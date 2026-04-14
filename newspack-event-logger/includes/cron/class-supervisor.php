<?php
/**
 * Supervisor
 *
 * Monitors and spawns workers.
 * Long-running process that monitors worker health and spawns new workers as needed.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Cron;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\Lock;
use Newspack_Event_Logger\SupervisorBase;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supervisor class.
 */
class Supervisor extends SupervisorBase {

	/**
	 * Config check interval in seconds.
	 */
	private const CONFIG_CHECK_INTERVAL = 15;

	/**
	 * Maximum partitions to check when killing workers.
	 */
	private const MAX_PARTITIONS = 16;

	/**
	 * Minimum interval between spawning the same worker (rate limiting).
	 */
	private const MIN_SPAWN_INTERVAL_S = 15;

	/**
	 * Grace period (seconds) before removing stale partition directories.
	 */
	private const STALE_PARTITION_AGE_S = 3600;

	/**
	 * Cached base directory path.
	 *
	 * @var string|null
	 */
	private static ?string $base_dir = null;

	/**
	 * Number of partitions.
	 *
	 * @var int
	 */
	private int $num_partitions;

	/**
	 * Worker locks to monitor.
	 *
	 * @var array
	 */
	private array $worker_locks = [];

	/**
	 * Last spawn time per worker (keyed by type|partition).
	 *
	 * @var array<string, int>
	 */
	private array $last_spawn_time = [];

	/**
	 * Registered log readers from filter.
	 *
	 * @var array<string, array>
	 */
	private array $log_readers = [];

	/**
	 * Registered standalone workers from filter.
	 *
	 * @var array<string, array>
	 */
	private array $standalone_workers = [];

	/**
	 * Maximum supervisor runtime in seconds (10 minutes minus 5 seconds).
	 */
	private const MAX_SUPERVISOR_RUNTIME_S = 595;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( null, self::MAX_SUPERVISOR_RUNTIME_S );
		$config               = Config::load_config( 'full' );
		$this->num_partitions = \min( self::MAX_PARTITIONS, \max( 1, (int) ( $config['num_partitions'] ?? 1 ) ) );
		$this->build_worker_locks();
	}

	/**
	 * Get the cached, validated base directory path.
	 *
	 * @return string Base directory path.
	 */
	public static function get_base_dir(): string {
		if ( null === self::$base_dir ) {
			self::$base_dir = Config::get_base_directory();
		}
		return self::$base_dir;
	}

	/**
	 * Clean up all directories (logs, locks, offsets).
	 */
	private static function cleanup_all(): void {
		parent::delete_directory_recursive( self::get_base_dir() );
	}

	/**
	 * Unschedule the supervisor cron job.
	 */
	public static function unschedule(): void {
		$timestamp = \wp_next_scheduled( 'newspack_event_logger_supervisor' );
		if ( $timestamp ) {
			\wp_unschedule_event( $timestamp, 'newspack_event_logger_supervisor' );
		}
	}

	/**
	 * Handle plugin deactivation.
	 */
	public static function deactivate(): void {
		self::cleanup_all();
		self::unschedule();
	}

	/**
	 * Build the worker locks array for current partition count.
	 *
	 * Includes both LogReader handlers and standalone workers.
	 */
	private function build_worker_locks(): void {
		$this->worker_locks = [];
		$locks_dir = Config::get_locks_directory();

		// Load registered log readers.
		$this->load_log_readers();

		// Create worker locks for each registered reader.
		foreach ( $this->log_readers as $name => $reader_config ) {
			for ( $p = 0; $p < $this->num_partitions; $p++ ) {
				$this->worker_locks[] = [
					'type'          => $name,
					'partition'     => $p,
					'lock_dir'      => "{$locks_dir}/{$name}.p{$p}.lock.d",
					'stale_timeout' => $reader_config['stale_timeout'] ?? Lock::STALE_TIMEOUT,
				];
			}
		}

		// Load and add standalone workers.
		$this->load_standalone_workers();

		foreach ( $this->standalone_workers as $name => $worker_config ) {
			$partitions = ! empty( $worker_config['partitions'] );
			if ( $partitions ) {
				// One worker per partition.
				for ( $p = 0; $p < $this->num_partitions; $p++ ) {
					$this->worker_locks[] = [
						'type'       => $name,
						'partition'  => $p,
						'lock_dir'   => "{$locks_dir}/{$name}.p{$p}.lock.d",
						'standalone' => true,
					];
				}
			} else {
				// Single instance (partition 0).
				$this->worker_locks[] = [
					'type'       => $name,
					'partition'  => 0,
					'lock_dir'   => "{$locks_dir}/{$name}.lock.d",
					'standalone' => true,
				];
			}
		}
	}

	/**
	 * Load registered log reader groups from filter.
	 *
	 * Plugins register handlers in groups via 'newspack_event_logger_log_readers':
	 *
	 * add_filter('newspack_event_logger_log_readers', function($readers) {
	 *     $readers['firehose-workers']['request-builder'] = [
	 *         'class'  => RequestBuilder::class,
	 *         'inputs' => ['firehose.log'],
	 *     ];
	 *     return $readers;
	 * });
	 */
	private function load_log_readers(): void {
		$this->log_readers = LogReader::get_registered_readers();
	}

	/**
	 * Load registered standalone workers from filter.
	 *
	 * Plugins register standalone workers via the 'newspack_event_logger_standalone_workers' filter:
	 *
	 * add_filter('newspack_event_logger_standalone_workers', function($workers) {
	 *     $workers['stream-merger'] = [
	 *         'class'      => StreamMerger::class,
	 *         'partitions' => true,  // One per partition, or false for single instance.
	 *     ];
	 *     return $workers;
	 * });
	 *
	 * Worker class must extend WorkerBase and implement run().
	 */
	private function load_standalone_workers(): void {
		$workers = \apply_filters( 'newspack_event_logger_standalone_workers', [] );

		if ( ! \is_array( $workers ) ) {
			$this->standalone_workers = [];
			return;
		}

		// Validate worker configurations.
		$valid = [];
		foreach ( $workers as $name => $config ) {
			if ( ! \is_string( $name ) ) {
				continue;
			}
			if ( ! isset( $config['class'] ) || ! \is_string( $config['class'] ) ) {
				continue;
			}

			// Validate worker class exists.
			$worker_class = $config['class'];
			if ( ! \class_exists( $worker_class ) ) {
				continue;
			}

			$valid[ $name ] = [
				'class'      => $worker_class,
				'partitions' => ! empty( $config['partitions'] ),
			];
		}

		$this->standalone_workers = $valid;
	}

	/**
	 * Get registered standalone workers.
	 *
	 * @return array<string, array> Worker configurations keyed by name.
	 */
	public static function get_standalone_workers(): array {
		$workers = \apply_filters( 'newspack_event_logger_standalone_workers', [] );

		if ( ! \is_array( $workers ) ) {
			return [];
		}

		// Validate worker configurations.
		$valid = [];
		foreach ( $workers as $name => $config ) {
			if ( ! \is_string( $name ) ) {
				continue;
			}
			if ( ! isset( $config['class'] ) || ! \is_string( $config['class'] ) ) {
				continue;
			}

			// Validate worker class exists.
			$worker_class = $config['class'];
			if ( ! \class_exists( $worker_class ) ) {
				continue;
			}

			$valid[ $name ] = [
				'class'      => $worker_class,
				'partitions' => ! empty( $config['partitions'] ),
			];
		}

		return $valid;
	}

	/**
	 * Check config and handle changes.
	 *
	 * @return bool False if supervisor should exit.
	 */
	private function check_config(): bool {
		$current_base_dir = self::get_base_dir();

		// Check restart marker (plugins touch this on activation/deactivation).
		if ( \file_exists( "{$current_base_dir}/restart_supervisor" ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Base directory is configurable and validated in Config class.
			@\unlink( "{$current_base_dir}/restart_supervisor" );
			return false;
		}

		// Clear caches for long-running process.
		// Must clear alloptions because WordPress caches all autoloaded options together.
		\wp_cache_delete( 'alloptions', 'options' );
		Config::reset();
		self::$base_dir = null;

		$config             = Config::load_config( 'full' );
		$new_num_partitions = \min( self::MAX_PARTITIONS, \max( 1, (int) ( $config['num_partitions'] ?? 1 ) ) );
		$logging_enabled    = ! empty( $config['enable_logging'] );
		$new_base_dir       = self::get_base_dir();

		if ( ! $logging_enabled ) {
			// Logging disabled via settings: don't spawn new workers and
			// unschedule so the supervisor goes quiet. Intentionally do NOT
			// wipe the base directory here — disabling logging should stop
			// new writes, not destroy historical firehose data the user may
			// be inspecting (or that was copied in from another environment
			// for debugging). Full cleanup only happens on plugin
			// deactivation via Supervisor::deactivate().
			self::unschedule();
			return false;
		}

		if ( $new_base_dir !== $current_base_dir ) {
			// Base directory moved. Leave the old directory in place —
			// the user can remove it manually. Silently recursive-deleting
			// whatever was at the previous path is too dangerous.
			return false;
		}

		// Handle partition count changes — release locks for retired partitions.
		if ( $new_num_partitions < $this->num_partitions ) {
			$locks_dir = Config::get_locks_directory();
			foreach ( $this->log_readers as $name => $reader_config ) {
				for ( $p = $new_num_partitions; $p < $this->num_partitions; $p++ ) {
					Lock::force_release( "{$locks_dir}/{$name}.p{$p}.lock.d" );
					unset( $this->last_spawn_time[ "{$name}|{$p}" ] );
				}
			}
			// Also release standalone worker locks for retired partitions.
			foreach ( $this->standalone_workers as $name => $worker_config ) {
				for ( $p = $new_num_partitions; $p < $this->num_partitions; $p++ ) {
					Lock::force_release( "{$locks_dir}/{$name}.p{$p}.lock.d" );
					unset( $this->last_spawn_time[ "{$name}|{$p}" ] );
				}
			}
		}

		if ( $new_num_partitions !== $this->num_partitions ) {
			$this->num_partitions = $new_num_partitions;
			$this->build_worker_locks();
		}

		$this->cleanup_stale_partitions();

		return true;
	}

	/**
	 * Clean up stale partition directories beyond num_partitions.
	 *
	 * Removes partition directories that haven't been modified in over 1 hour,
	 * allowing workers to finish gracefully before cleanup.
	 */
	private function cleanup_stale_partitions(): void {
		$logs_dir  = Config::get_logs_directory();
		$locks_dir = Config::get_locks_directory();
		$stale_age = self::STALE_PARTITION_AGE_S;

		// Check partition directories beyond current num_partitions (up to max 16).
		foreach ( $this->log_readers as $name => $reader_config ) {
			$input_log = $reader_config['inputs'][0];
			for ( $p = $this->num_partitions; $p < self::MAX_PARTITIONS; $p++ ) {
				parent::remove_stale_directory( "{$logs_dir}/{$input_log}/p{$p}", $stale_age );
				parent::remove_stale_directory( "{$locks_dir}/{$name}.p{$p}.lock.d", $stale_age );
			}
		}

		// Also clean up stale standalone worker lock directories.
		foreach ( $this->standalone_workers as $name => $worker_config ) {
			if ( ! empty( $worker_config['partitions'] ) ) {
				for ( $p = $this->num_partitions; $p < self::MAX_PARTITIONS; $p++ ) {
					parent::remove_stale_directory( "{$locks_dir}/{$name}.p{$p}.lock.d", $stale_age );
				}
			}
		}
	}

	/**
	 * Check if a worker needs to be spawned.
	 *
	 * @param array $worker Worker info array.
	 * @param int   $now    Current timestamp.
	 * @return bool True if worker needs spawning.
	 */
	private function worker_needs_spawn( array $worker, int $now ): bool {
		if ( ! \is_dir( $worker['lock_dir'] ) ) {
			return true;
		}

		// Check heartbeat for crash detection.
		$heartbeat_file = $worker['lock_dir'] . '/heartbeat';
		$mtime = @\filemtime( $heartbeat_file );
		// Handle filemtime failure explicitly - don't trigger respawn on error.
		// Prevents premature respawn when heartbeat file is missing or unreadable.
		if ( false === $mtime ) {
			Lock::force_release( $worker['lock_dir'] );
			return true;
		}
		$heartbeat_age = $now - $mtime;
		$stale_timeout = $worker['stale_timeout'] ?? Lock::STALE_TIMEOUT;
		if ( $heartbeat_age > $stale_timeout ) {
			// Stale heartbeat - worker crashed, clean up lock.
			Lock::force_release( $worker['lock_dir'] );
			return true;
		}

		return false;
	}

	/**
	 * Run the supervisor loop.
	 */
	public function run(): void {
		// Tag this process as a supervisor worker for stats exclusion.
		$_SERVER['EVENT_LOGGER_WORKER_TYPE']     = 'supervisor';
		$_SERVER['EVENT_LOGGER_WORKER_PARTITION'] = '0';
		if ( \class_exists( \Newspack_Performance_Logger\LogManager::class ) ) {
			\Newspack_Performance_Logger\LogManager::instance()->message( 'worker_type', [ 'm' => 'supervisor' ] );
		}

		// Check config first - exit if logging disabled or base_directory changed.
		// Must happen before any setup so old directory can be cleaned safely.
		if ( ! $this->check_config() ) {
			return;
		}

		// Acquire supervisor lock.
		$locks_dir = Config::get_locks_directory();
		$this->init_lock( "{$locks_dir}/supervisor.lock.d" );
		if ( ! $this->acquire() ) {
			return; // Another supervisor is running.
		}

		// Disable timeout after lock acquired.
		@\set_time_limit( 0 );

		$start_time        = \time();
		$last_config_check = 0;
		$last_token_window = 0;
		$spawn_url         = \rest_url( 'event-logger/v1/workers/spawn' );
		$spawn_args        = [
			'blocking'  => false,
			'timeout'   => 0.01,
			'sslverify' => false,
			'body'      => [],
		];
		$token             = '';

		while ( \time() - $start_time < $this->max_runtime ) {
			$now            = \time();
			$current_window = (int) \floor( $now / 10 );

			// Refresh HMAC token every 10 seconds.
			if ( $current_window !== $last_token_window ) {
				$token             = self::generate_spawn_token();
				$last_token_window = $current_window;
			}

			// Re-check config periodically.
			if ( $now - $last_config_check >= self::CONFIG_CHECK_INTERVAL ) {
				$last_config_check = $now;
				if ( ! $this->check_config() ) {
					break; // Logging disabled.
				}

				// Let plugins run lightweight periodic tasks inside the supervisor.
				\do_action( 'newspack_event_logger_supervisor_periodic' );
			}

			// Check each worker and spawn if needed.
			foreach ( $this->worker_locks as $worker ) {
				if ( $this->worker_needs_spawn( $worker, $now ) ) {
					// Rate limit: skip if spawned too recently.
					// Use pipe delimiter to avoid collision if type ever contains colon.
					$worker_key = $worker['type'] . '|' . $worker['partition'];
					$last_spawn = $this->last_spawn_time[ $worker_key ] ?? 0;
					if ( $now - $last_spawn < self::MIN_SPAWN_INTERVAL_S ) {
						continue;
					}

					// Spawn worker via REST API.
					$spawn_args['body'] = [
						'type'      => $worker['type'],
						'partition' => $worker['partition'],
						'nonce'     => $token,
					];
					$response = \wp_remote_post( $spawn_url, $spawn_args );
					// Log persistent spawn failures for debugging.
					if ( \is_wp_error( $response ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						\error_log( 'Supervisor: spawn failed for ' . $worker_key . ': ' . $response->get_error_message() );
					}
					$this->last_spawn_time[ $worker_key ] = $now;
				}
			}

			// Heartbeat + runtime/restart checks (heartbeat touched internally every 10s).
			if ( $this->should_restart() ) {
				break;
			}

			\sleep( 1 );
		}

		$this->release();
		$this->spawn_next_supervisor();
	}

	/**
	 * Spawn the next supervisor instance via the worker spawn endpoint.
	 */
	private function spawn_next_supervisor(): void {
		\wp_remote_post( \rest_url( 'event-logger/v1/workers/spawn' ), [
			'blocking'  => false,
			'timeout'   => 0.01,
			'sslverify' => false,
			'body'      => [
				'type'      => 'supervisor',
				'partition' => 0,
				'nonce'     => self::generate_spawn_token(),
			],
		] );
	}

	/**
	 * Generate an internal spawn token (HMAC-based, valid for ~10 seconds).
	 *
	 * @param int $time_offset Window offset (0 for current, -1 for previous).
	 * @return string Token.
	 */
	public static function generate_spawn_token( int $time_offset = 0 ): string {
		$window = (int) \floor( \time() / 10 ) + $time_offset;
		return \hash_hmac( 'sha256', 'event_logger_spawn:' . $window, NONCE_SALT );
	}

	/**
	 * Request supervisor restart.
	 *
	 * Plugins call this on activation so supervisor picks up new log readers.
	 */
	public static function request_restart(): void {
		try {
			$marker = self::get_base_dir() . '/restart_supervisor';
			@\touch( $marker ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_touch
		} catch ( \Throwable $e ) {
			// Ignore - supervisor restarts hourly anyway.
		}
	}

	/**
	 * Kill workers for specific readers.
	 *
	 * Plugins call this on deactivation to stop their workers immediately.
	 *
	 * @param string[] $reader_names Group names to kill (e.g., ['firehose-workers', 'request-workers']).
	 */
	public static function kill_readers( array $reader_names ): void {
		try {
			$locks_dir  = Config::get_locks_directory();
			$config     = Config::load_config( 'full' );
			$partitions = \min( self::MAX_PARTITIONS, \max( 1, (int) ( $config['num_partitions'] ?? 1 ) ) );
			$readers    = LogReader::get_registered_readers();

			foreach ( $reader_names as $name ) {
				$reader_config = $readers[ $name ] ?? null;
				if ( ! $reader_config ) {
					continue;
				}
				for ( $p = 0; $p < $partitions; $p++ ) {
					Lock::force_release( "{$locks_dir}/{$name}.p{$p}.lock.d" );
				}
			}

			// Also request supervisor restart to update its worker list.
			self::request_restart();
		} catch ( \Throwable $e ) {
			// Ignore.
		}
	}
}
