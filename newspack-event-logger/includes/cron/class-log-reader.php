<?php
/**
 * Log Reader
 *
 * Generic log reader worker that reads from one or more input logs
 * and dispatches each line to registered handlers.
 *
 * Handlers are registered via the newspack_event_logger_log_readers filter using
 * a two-level group format:
 *
 *   $readers['group-name']['handler-name'] = [
 *       'class'  => HandlerClass::class,
 *       'inputs' => ['firehose.log'],
 *   ];
 *
 * Multiple plugins can independently add handlers to the same group.
 * Each group runs as one process, reading the union of all handler inputs.
 * Lines are dispatched only to handlers that registered for that input.
 *
 * Handler Interface:
 * - process($line, $input, &$context) - Required. Called for each line.
 * - init(&$context, $saved_state) - Optional. Called on startup with restored state.
 * - save_state(&$context) - Optional. Returns state array to persist.
 * - flush(&$context) - Optional. Called during housekeeping.
 * - cleanup(&$context) - Optional. Called on shutdown.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Cron;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\FirehoseReader;
use Newspack_Event_Logger\Lock;
use Newspack_Event_Logger\Memcached;
use Newspack_Event_Logger\WorkerBase;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log reader class.
 */
class LogReader extends WorkerBase {

	/**
	 * Offsetlog segment size (64KB - small for fast last-entry lookup).
	 */
	const OFFSETLOG_SEGMENT_SIZE = 65536;

	/**
	 * Number of offsetlog segments to retain.
	 */
	const OFFSETLOG_NUM_SEGMENTS = 2;

	/**
	 * Housekeeping (state save) interval in seconds.
	 */
	const HOUSEKEEPING_INTERVAL = 30.0;

	/**
	 * Reader group name (used for lock file and offset tracking).
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Input log names -- union of all handler inputs.
	 *
	 * @var string[]
	 */
	private array $inputs;

	/**
	 * Handler configurations keyed by handler name.
	 * Each entry has 'class' and 'inputs' keys.
	 *
	 * @var array<string, array>
	 */
	private array $handlers;

	/**
	 * Per-handler contexts keyed by handler name.
	 * Each handler gets its own context array passed by reference.
	 *
	 * @var array<string, array>
	 */
	private array $handler_contexts = [];

	/**
	 * Dispatch map: input log name => list of handler names that read it.
	 *
	 * @var array<string, string[]>
	 */
	private array $dispatch_map = [];

	/**
	 * FirehoseReader instances for each input log.
	 *
	 * @var FirehoseReader[]
	 */
	private array $readers = [];

	/**
	 * Unified offsetlog for position + state persistence.
	 *
	 * @var Firehose|null
	 */
	private ?Firehose $offsetlog = null;

	/**
	 * File handles for each input log (indexed by input name).
	 *
	 * @var array<string, resource|null>
	 */
	private array $file_handles = [];

	/**
	 * Logs directory (for data firehoses).
	 *
	 * @var string
	 */
	private string $logs_dir;

	/**
	 * Timestamp of last housekeeping.
	 *
	 * @var int
	 */
	private float $last_housekeeping = 0;

	/**
	 * Did work since last housekeeping
	 *
	 * @var int
	 */
	private bool $house_is_dirty = false;

	/**
	 * Last time positions were published (tracks heartbeat cadence).
	 *
	 * @var float
	 */
	private float $last_heartbeat_publish = 0;

	/**
	 * Lock stale timeout (used as memcache TTL for published positions).
	 *
	 * @var int
	 */
	private int $stale_timeout;

	/**
	 * Constructor.
	 *
	 * @param string $name          Reader group name (e.g., 'firehose-workers').
	 * @param array  $inputs        Input log names -- union of handler inputs.
	 * @param array  $handlers      Handler configs keyed by handler name.
	 * @param int    $partition     Partition index.
	 * @param int    $max_runtime   Maximum runtime in seconds.
	 * @param int    $stale_timeout Lock stale timeout in seconds.
	 */
	public function __construct(
		string $name,
		array $inputs,
		array $handlers,
		int $partition = 0,
		int $max_runtime = self::MAX_RUNTIME_SECONDS,
		int $stale_timeout = Lock::STALE_TIMEOUT
	) {
		$this->name          = $name;
		$this->inputs        = $inputs;
		$this->handlers      = $handlers;
		$this->stale_timeout = $stale_timeout;

		$this->logs_dir = Config::get_logs_directory();

		// Lock directory in centralized locks directory.
		$lock_path = Config::get_locks_directory() . "/{$name}.p{$partition}.lock.d";

		$this->init_worker( $lock_path, $partition, $max_runtime, $stale_timeout );
		$this->init_readers();
	}

	/**
	 * Initialize FirehoseReaders and handler contexts.
	 */
	private function init_readers(): void {
		$config       = Config::load_config( 'full' );
		$num_segments = $config['num_segments'] ?? 4;
		$segment_size = $config['segment_size'] ?? ( 64 * 1024 * 1024 );

		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		// Create unified offsetlog for this reader group.
		$offsets_dir     = Config::get_offsets_directory();
		$this->offsetlog = new Firehose(
			"{$offsets_dir}/{$this->name}.p{$this->partition}",
			0, // Single partition for offsetlog.
			self::OFFSETLOG_SEGMENT_SIZE,
			self::OFFSETLOG_NUM_SEGMENTS,
			0  // Pure count-based retention (no max_lifespan).
		);
		$this->offsetlog->allow_large_writes();

		// Read saved positions and state from unified offsetlog.
		$saved           = $this->read_last_offsetlog_line();
		$saved_positions = $saved['positions'] ?? [];
		$saved_states    = $saved['state'] ?? [];

		// Create input readers without per-reader offsetlogs.
		foreach ( $this->inputs as $input ) {
			$firehose = new Firehose( "{$this->logs_dir}/{$input}", $this->partition );
			$reader   = new FirehoseReader( $firehose );

			// Position reader from saved positions.
			if ( isset( $saved_positions[ $input ] ) ) {
				$pos = $saved_positions[ $input ];
				$reader->next_offset( [
					'segment_id' => $pos['seg'] ?? 0,
					'offset'     => $pos['off'] ?? 0,
				] );
			}

			$this->readers[ $input ]      = $reader;
			$this->file_handles[ $input ] = null;
		}

		// Build base context shared by all handlers.
		$base_context = [
			'partition'      => $this->partition,
			'log_base'       => $this->logs_dir,
			'segment_size'   => $segment_size,
			'num_segments'   => $num_segments,
			'num_partitions' => (int) ( $config['num_partitions'] ?? 1 ),
			'config'         => $config,
		];

		// Build dispatch map and initialize per-handler contexts.
		foreach ( $this->handlers as $handler_name => $handler_config ) {
			// Dispatch map: input → handler names that read it.
			foreach ( $handler_config['inputs'] as $input ) {
				$this->dispatch_map[ $input ][] = $handler_name;
			}

			// Per-handler context.
			$this->handler_contexts[ $handler_name ] = \array_merge( $base_context, [
				'name' => $handler_name,
			] );

			// Call handler init with saved state.
			$handler_class = $handler_config['class'];
			$saved_state   = $saved_states[ $handler_name ] ?? null;
			if ( \method_exists( $handler_class, 'init' ) ) {
				try {
					$handler_class::init( $this->handler_contexts[ $handler_name ], $saved_state );
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					\error_log( \sprintf(
						'[EventLogger] LogReader %s: Handler %s::init error: %s',
						$this->name,
						$handler_name,
						\substr( \preg_replace( '/[\x00-\x1F\x7F]/', '', $e->getMessage() ), 0, 200 )
					) );
					throw $e;
				}
			}
		}
	}

	/**
	 * Read the last JSONL line from the unified offsetlog.
	 *
	 * @return array|null Decoded offsetlog entry or null if no entries.
	 */
	private function read_last_offsetlog_line(): ?array {
		$reader = new FirehoseReader( $this->offsetlog, 'recent' );
		$fh     = $reader->open();

		$last_line = null;
		while ( $fh ) {
			// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( null !== ( $line = $reader->read_line() ) ) {
				$last_line = $line;
			}
			if ( $reader->is_caught_up() ) {
				break;
			}
			$fh = $reader->next_segment();
		}
		$reader->close();

		if ( null === $last_line ) {
			return null;
		}

		$decoded = \json_decode( \trim( $last_line ), true, 64 );
		if ( ! \is_array( $decoded ) || ! isset( $decoded['positions'] ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Run the worker.
	 */
	public function run(): void {
		while ( true ) {
			$did_work = false;

			// Read from each input log (round-robin for fairness).
			foreach ( $this->inputs as $input ) {
				$reader = $this->readers[ $input ];
				$fh     = $this->file_handles[ $input ];

				if ( $fh ) {
					$line = $reader->read_line();
					if ( null !== $line ) {
						// Dispatch to all handlers registered for this input.
						$handler_names = $this->dispatch_map[ $input ] ?? [];
						foreach ( $handler_names as $handler_name ) {
							$handler_class = $this->handlers[ $handler_name ]['class'];
							try {
								$handler_class::process( $line, $input, $this->handler_contexts[ $handler_name ] );
							} catch ( \Throwable $e ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								\error_log( \sprintf(
									'[EventLogger] LogReader %s: Handler %s::process error: %s',
									$this->name,
									$handler_name,
									\substr( \preg_replace( '/[\x00-\x1F\x7F]/', '', $e->getMessage() ), 0, 200 )
								) );
							}
						}
						$this->house_is_dirty = true;
						$did_work = true;
					} else {
						// End of data in current segment - try next.
						$this->file_handles[ $input ] = $reader->next_segment();
					}
				} else {
					// Try to open.
					$this->file_handles[ $input ] = $reader->open();
				}
			}

			// Check if all readers are caught up.
			$all_caught_up = true;
			foreach ( $this->inputs as $input ) {
				$fh = $this->file_handles[ $input ];
				if ( $fh && ! $this->readers[ $input ]->is_caught_up() ) {
					$all_caught_up = false;
					break;
				}
			}

			if ( $all_caught_up ) {
				\usleep( 10000 ); // 10ms when caught up.
			} elseif ( ! $did_work ) {
				\usleep( 1000 ); // 1ms.
			}

			// Heartbeat + runtime/restart checks (internally rate-limited to 10s).
			if ( $this->should_restart() ) {
				break;
			}
			// Publish positions every ~2s for dashboard read-rate display.
			$now_pos = \microtime( true );
			if ( $now_pos - $this->last_heartbeat_publish >= 1.0 ) {
				$this->publish_positions();
				$this->last_heartbeat_publish = $now_pos;
			}

			// State save every 30s.
			$now = \microtime( true );
			if ( $this->house_is_dirty && self::HOUSEKEEPING_INTERVAL <= $now - $this->last_housekeeping ) {
				$this->do_housekeeping();
			}
		}

		// Final state save before shutdown.
		$this->house_is_dirty = true;
		$this->do_housekeeping();

		// Call handler cleanup for all handlers.
		foreach ( $this->handlers as $handler_name => $handler_config ) {
			$handler_class = $handler_config['class'];
			if ( \method_exists( $handler_class, 'cleanup' ) ) {
				try {
					$handler_class::cleanup( $this->handler_contexts[ $handler_name ] );
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					\error_log( \sprintf(
						'[EventLogger] LogReader %s: Handler %s::cleanup error: %s',
						$this->name,
						$handler_name,
						\substr( \preg_replace( '/[\x00-\x1F\x7F]/', '', $e->getMessage() ), 0, 200 )
					) );
				}
			}
		}
	}

	/**
	 * Perform housekeeping tasks: flush handlers and save state.
	 */
	private function do_housekeeping(): void {
		// Call flush on all handlers.
		foreach ( $this->handlers as $handler_name => $handler_config ) {
			$handler_class = $handler_config['class'];
			if ( \method_exists( $handler_class, 'flush' ) ) {
				try {
					$handler_class::flush( $this->handler_contexts[ $handler_name ] );
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					\error_log( \sprintf(
						'[EventLogger] LogReader %s: Handler %s::flush error: %s',
						$this->name,
						$handler_name,
						\substr( \preg_replace( '/[\x00-\x1F\x7F]/', '', $e->getMessage() ), 0, 200 )
					) );
				}
			}
		}

		// Commit unified offsetlog with all positions + all handler states.
		if ( $this->house_is_dirty ) {
			// Collect positions from all input readers.
			$positions = [];
			foreach ( $this->readers as $input => $reader ) {
				$pos                 = $reader->get_position();
				$positions[ $input ] = [
					'seg' => $pos['segment_id'],
					'off' => $pos['offset'],
				];
			}

			// Collect state from all handlers.
			$states = [];
			foreach ( $this->handlers as $handler_name => $handler_config ) {
				$handler_class = $handler_config['class'];
				if ( \method_exists( $handler_class, 'save_state' ) ) {
					try {
						$state = $handler_class::save_state( $this->handler_contexts[ $handler_name ] );
						if ( null !== $state ) {
							$states[ $handler_name ] = $state;
						}
					} catch ( \Throwable $e ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						\error_log( \sprintf(
							'[EventLogger] LogReader %s: Handler %s::save_state error: %s',
							$this->name,
							$handler_name,
							\substr( \preg_replace( '/[\x00-\x1F\x7F]/', '', $e->getMessage() ), 0, 200 )
						) );
					}
				}
			}

			$entry = [
				'positions' => $positions,
				'ts'        => \time(),
			];
			if ( ! empty( $states ) ) {
				$entry['state'] = $states;
			}

			$this->offsetlog->write( \wp_json_encode( $entry ) );
			$this->house_is_dirty = false;
		}

		$this->last_housekeeping = \microtime( true );
	}

	/**
	 * Get registered log reader groups from filter.
	 *
	 * Filter format is two-level: group name → handler name → config.
	 * Each handler config must have 'class' (string) and 'inputs' (string[]).
	 * Optional keys: 'output' (string), 'stale_timeout' (int).
	 *
	/**
	 * Publish current reader positions to memcache (called on heartbeat cadence).
	 *
	 * Keyed by reader name and partition so the dashboard can read
	 * fresh positions without parsing the offsetlog.
	 */
	private function publish_positions(): void {
		$positions = [];
		foreach ( $this->readers as $input => $reader ) {
			$pos                 = $reader->get_position();
			$positions[ $input ] = [
				'seg' => $pos['segment_id'],
				'off' => $pos['offset'],
			];
		}
		$host = \gethostname();
		Memcached::set(
			"evlog:pos:{$host}:{$this->name}:p{$this->partition}",
			$positions,
			$this->stale_timeout
		);
	}

	/**
	 * Read live positions from memcache (for dashboard).
	 *
	 * @param string $name      Reader group name.
	 * @param int    $partition Partition number.
	 * @return array|null Positions keyed by input name, or null if not available.
	 */
	public static function get_live_positions( string $name, int $partition ): ?array {
		$host = \gethostname();
		$val  = Memcached::get( "evlog:pos:{$host}:{$name}:p{$partition}" );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Get registered log reader groups from filter.
	 *
	 * Returns normalized group configs with union of inputs across handlers.
	 *
	 * @return array<string, array> Group configurations keyed by group name.
	 */
	public static function get_registered_readers(): array {
		/**
		 * Filter to register log reader handler groups.
		 *
		 * Two-level format: $readers['group-name']['handler-name'] = config.
		 *
		 * Each handler config must have:
		 * - 'class'  => string: Handler class with static process() method
		 * - 'inputs' => string[]: Input log names this handler reads
		 *
		 * Optional:
		 * - 'outputs'       => string[]: Output log names
		 * - 'stale_timeout' => int: Lock stale timeout in seconds
		 *
		 * Multiple plugins can add handlers to the same group.
		 * The group's inputs are the union of all handler inputs.
		 *
		 * @param array $readers Array of reader group configurations.
		 */
		$raw = \apply_filters( 'newspack_event_logger_log_readers', [] );

		if ( ! \is_array( $raw ) ) {
			return [];
		}

		$valid = [];
		foreach ( $raw as $group_name => $group ) {
			if ( ! \is_string( $group_name ) || ! \is_array( $group ) ) {
				continue;
			}

			$handlers      = [];
			$group_inputs  = [];
			$group_outputs = [];
			$stale_timeout = Lock::STALE_TIMEOUT;

			foreach ( $group as $handler_name => $handler_config ) {
				if ( ! \is_string( $handler_name ) || ! \is_array( $handler_config ) ) {
					continue;
				}
				if ( ! isset( $handler_config['class'] ) || ! \is_string( $handler_config['class'] ) ) {
					continue;
				}
				if ( ! isset( $handler_config['inputs'] ) || ! \is_array( $handler_config['inputs'] ) ) {
					continue;
				}

				$handler_class = $handler_config['class'];
				if ( ! \class_exists( $handler_class ) || ! \method_exists( $handler_class, 'process' ) ) {
					continue;
				}

				// Validate inputs are strings.
				$inputs_valid = true;
				foreach ( $handler_config['inputs'] as $input ) {
					if ( ! \is_string( $input ) ) {
						$inputs_valid = false;
						break;
					}
				}
				if ( ! $inputs_valid ) {
					continue;
				}

				$handler = [
					'class'  => $handler_class,
					'inputs' => $handler_config['inputs'],
				];

				if ( ! empty( $handler_config['outputs'] ) && \is_array( $handler_config['outputs'] ) ) {
					$handler['outputs'] = $handler_config['outputs'];
					$group_outputs      = \array_merge( $group_outputs, $handler_config['outputs'] );
				}

				$handlers[ $handler_name ] = $handler;
				$group_inputs              = \array_unique( \array_merge( $group_inputs, $handler_config['inputs'] ) );

				if ( isset( $handler_config['stale_timeout'] ) ) {
					$stale_timeout = \max( $stale_timeout, (int) $handler_config['stale_timeout'] );
				}
			}

			// Skip empty groups.
			if ( empty( $handlers ) ) {
				continue;
			}

			$valid[ $group_name ] = [
				'inputs'        => \array_values( $group_inputs ),
				'handlers'      => $handlers,
				'outputs'       => $group_outputs,
				'stale_timeout' => $stale_timeout,
			];
		}

		return $valid;
	}

	/**
	 * Get saved positions for a reader group from the unified offsetlog.
	 *
	 * Used by Supervisor and dashboard to check cursor positions without
	 * running a full LogReader worker.
	 *
	 * @param string $name      Reader group name.
	 * @param int    $partition Partition index.
	 * @return array<string, array{seg: int, off: int}> Positions keyed by input name.
	 */
	public static function get_saved_positions( string $name, int $partition ): array {
		$offsets_dir    = Config::get_offsets_directory();
		$offsetlog_path = "{$offsets_dir}/{$name}.p{$partition}";

		if ( ! \is_dir( "{$offsetlog_path}/p0" ) ) {
			return [];
		}

		$offsetlog = new Firehose(
			$offsetlog_path,
			0,
			self::OFFSETLOG_SEGMENT_SIZE,
			self::OFFSETLOG_NUM_SEGMENTS,
			0
		);

		// Read last line from offsetlog.
		$reader = new FirehoseReader( $offsetlog, 'recent' );
		$fh     = $reader->open();

		$last_line = null;
		while ( $fh ) {
			// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( null !== ( $line = $reader->read_line() ) ) {
				$last_line = $line;
			}
			if ( $reader->is_caught_up() ) {
				break;
			}
			$fh = $reader->next_segment();
		}
		$reader->close();

		if ( null === $last_line ) {
			return [];
		}

		$decoded = \json_decode( \trim( $last_line ), true, 64 );
		if ( ! \is_array( $decoded ) || ! isset( $decoded['positions'] ) ) {
			return [];
		}

		return $decoded['positions'];
	}

	/**
	 * Cron callback for a specific reader group and partition.
	 *
	 * @param string $name      Reader group name.
	 * @param int    $partition Partition index.
	 * @return array Results.
	 */
	public static function cron_callback( string $name, int $partition = 0 ): array {
		$readers = self::get_registered_readers();

		if ( ! isset( $readers[ $name ] ) ) {
			return [
				'status' => 'error',
				'reason' => "Unknown reader group: {$name}",
			];
		}

		$config = $readers[ $name ];

		return ( new self(
			$name,
			$config['inputs'],
			$config['handlers'],
			$partition,
			self::MAX_RUNTIME_SECONDS,
			$config['stale_timeout'] ?? Lock::STALE_TIMEOUT
		) )->execute();
	}
}
