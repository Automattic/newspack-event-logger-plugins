<?php
/**
 * SSE Controller Base
 *
 * Base class for Server-Sent Events REST controllers.
 * Provides common SSE infrastructure for external plugins.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\REST;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\FirehoseReader;
use Newspack_Event_Logger\Memcached;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSE Controller Base class.
 *
 * External plugins can extend this class to create their own SSE endpoints
 * with built-in slot management and connection handling.
 */
abstract class SSEControllerBase extends \WP_REST_Controller {

	/**
	 * Maximum concurrent SSE connections per user:IP.
	 * Each browser tab typically opens one connection per partition.
	 *
	 * @var int
	 */
	protected const MAX_SSE_SLOTS = 10;

	/**
	 * Size of padding used to push recent SSE events through buffers.
	 *
	 * @var int
	 */
	protected const FLUSH_SIZE = 4096;

	/**
	 * How often SSE loop checks its slot.
	 *
	 * @var int
	 */
	protected const SLOT_CHECK_INTERVAL = 5;

	/**
	 * Heartbeat interval in seconds (sent to clients when caught up).
	 */
	protected const HEARTBEAT_INTERVAL = 5;

	/**
	 * Maximum SSE stream runtime in seconds (1 hour default).
	 *
	 * @var int
	 */
	protected const MAX_RUNTIME = 3600;

	/**
	 * Slot TTL for browser connections (seconds).
	 *
	 * Browsers heartbeat every 5s (useFirehoseConnection.js), so 10s gives
	 * 2x headroom — a slot frees within ~5s of a tab closing.
	 */
	public const SLOT_TTL_BROWSER = 10;

	/**
	 * Slot TTL for aggregator connections (seconds).
	 *
	 * StreamMerger heartbeats every 15s, so 30s gives the same 2x headroom
	 * the browser side uses. Larger values create a long stale-slot window
	 * after worker exits / restart cycles, which exhausts the per-user
	 * slot pool and trips MAX_SSE_SLOTS rate limiting.
	 */
	public const SLOT_TTL_AGGREGATOR = 30;

	/**
	 * Current user ID for slot management.
	 *
	 * @var int
	 */
	protected int $user_id = 0;

	/**
	 * Hashed IP for slot management.
	 *
	 * @var string
	 */
	protected string $ip_hash = '';

	/**
	 * Acquired SSE slot number.
	 *
	 * @var int|false
	 */
	protected $slot = false;

	/**
	 * Partition the acquired slot is scoped to (-1 for shared / browser pool).
	 *
	 * @var int
	 */
	protected int $slot_partition = -1;

	/**
	 * Whether unflushed data may be sitting in buffers.
	 *
	 * @var bool
	 */
	protected bool $needs_flush = false;


	/**
	 * Check if current user is allowed to access SSE streams.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function stream_permissions_check() {
		if ( ! \Newspack_Event_Logger\Admin\Admin::current_user_allowed() ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to access this resource.', 'newspack-event-logger' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Get hashed IP for slot keys.
	 *
	 * @return string 8-char hash of client IP.
	 */
	protected function get_ip_hash(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for cache key hashing, not displayed or stored.
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		return \substr( \md5( $ip ), 0, 8 );
	}

	/**
	 * Acquire an SSE slot for the current user.
	 *
	 * @param int $ttl       Slot TTL in seconds.
	 * @param int $partition Partition number (>= 0 scopes the slot to a per-partition pool;
	 *                       -1 keeps the browser-style shared pool).
	 * @return int|false Slot number or false if rate limited.
	 */
	protected function acquire_sse_slot( int $ttl = self::SLOT_TTL_BROWSER, int $partition = -1 ) {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		$this->user_id        = \get_current_user_id();
		$this->ip_hash        = $this->get_ip_hash();
		$this->slot_partition = $partition;

		$this->slot = Memcached::acquire_sse_slot( $this->user_id, $this->ip_hash, static::MAX_SSE_SLOTS, $ttl, $partition );
		return $this->slot;
	}

	/**
	 * Release the current SSE slot.
	 */
	protected function release_sse_slot(): void {
		if ( false !== $this->slot ) {
			Memcached::release_sse_slot( $this->user_id, $this->ip_hash, $this->slot, $this->slot_partition );
			$this->slot           = false;
			$this->slot_partition = -1;
		}
	}

	/**
	 * Check if the SSE slot is still alive.
	 *
	 * @return bool True if slot is alive, false if expired.
	 */
	protected function check_sse_slot(): bool {
		if ( false === $this->slot ) {
			return false;
		}
		$continue = Memcached::check_sse_slot( $this->user_id, $this->ip_hash, $this->slot, $this->slot_partition );
		return $continue;
	}

	/**
	 * Initialize SSE headers and output buffering.
	 */
	protected function init_sse_headers(): void {
		// phpcs:disable WordPress.PHP.IniSet.Risky
		@\ini_set( 'output_buffering', 'off' );
		@\ini_set( 'zlib.output_compression', false );
		@\ini_set( 'implicit_flush', true );
		// phpcs:enable

		// Clear all output buffers.
		while ( \ob_get_level() > 0 ) {
			\ob_end_clean();
		}

		// Disable Apache mod_deflate compression.
		if ( \function_exists( 'apache_setenv' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv -- Required to disable gzip for SSE streaming.
			@\apache_setenv( 'no-gzip', '1' );
		}

		\header( 'Content-Type: text/event-stream' );
		\header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		\header( 'Connection: keep-alive' );
		\header( 'X-Accel-Buffering: no' ); // Nginx.
		\header( 'Content-Encoding: none' ); // Prevent gzip.
	}

	/**
	 * Send an SSE event.
	 *
	 * @param string $event Event name (sanitized to alphanumeric + _ -).
	 * @param mixed  $data  Data to JSON encode and send.
	 */
	protected function send_sse_event( string $event, $data ): void {
		// Sanitize event name to prevent SSE injection via newlines/special chars.
		// Hot-path fast path: internal literal event names skip preg_replace entirely.
		static $safe_events = [
			'entry'          => 1,
			'entries'        => 1,
			'lines'          => 1,
			'positions'      => 1,
			'heartbeat'      => 1,
			'config'         => 1,
			'connected'      => 1,
			'timeout'        => 1,
			'complete_batch' => 1,
			'inflight'       => 1,
			'errors'         => 1,
		];
		if ( ! isset( $safe_events[ $event ] ) ) {
			$event = \preg_replace( '/[^a-zA-Z0-9_-]/', '', $event );
		}
		$payload = "event: {$event}\ndata: " . \wp_json_encode( $data ) . "\n\n";

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $payload;
		@\flush();

		$this->needs_flush = true;
	}

	/**
	 * Flush buffers if data may be sitting in proxy/TLS buffers.
	 *
	 * Call this before sleeping to ensure data reaches the client.
	 * Sends 4KB of SSE comments to push any buffered data through.
	 */
	protected function flush_if_needed(): void {
		if ( ! $this->needs_flush ) {
			return;
		}

		// SSE comment (ignored by parsers) with enough bulk to flush buffers.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ':' . \str_repeat( '.', static::FLUSH_SIZE - 3 ) . "\n\n";
		@\flush();

		$this->needs_flush = false;
	}

	/**
	 * Start an SSE stream with connection management.
	 *
	 * Sets up the SSE connection with slot management and returns
	 * a configured stream context. Returns WP_Error if rate limited.
	 *
	 * @param array $connected_data Optional data to include in 'connected' event.
	 * @param array $custom_headers Optional custom headers to send before output.
	 * @param bool  $is_aggregator  Whether this is an aggregator connection (uses longer slot TTL).
	 * @return array|\WP_Error Stream context array or WP_Error if rate limited.
	 */
	protected function start_sse_stream( array $connected_data = [], array $custom_headers = [], bool $is_aggregator = false ) {
		$ttl       = $is_aggregator ? static::SLOT_TTL_AGGREGATOR : static::SLOT_TTL_BROWSER;
		// Aggregator connections get a per-partition slot pool — one
		// stream-merger per partition shouldn't compete with browser tabs
		// or other partitions for the global MAX_SSE_SLOTS pool.
		$partition = ( $is_aggregator && isset( $connected_data['partition'] ) ) ? (int) $connected_data['partition'] : -1;
		$slot      = $this->acquire_sse_slot( $ttl, $partition );
		if ( false === $slot ) {
			/** Fires when an SSE connection is rate-limited (429). */
			\do_action( 'newspack_event_logger_sse_rate_limited', \get_current_user_id(), static::class );
			return new \WP_Error(
				'too_many_connections',
				\__( 'Maximum concurrent SSE streams reached. Close other tabs or wait.', 'newspack-event-logger' ),
				[ 'status' => 429 ]
			);
		}

		$this->init_sse_headers();

		// Add custom headers before any output.
		// Sanitize header names and values to prevent HTTP response splitting.
		foreach ( $custom_headers as $name => $value ) {
			$name  = \str_replace( [ "\r", "\n", "\0" ], '', $name );
			$value = \str_replace( [ "\r", "\n", "\0" ], '', $value );
			\header( "{$name}: {$value}" );
		}

		@\set_time_limit( 0 );

		$config = Config::load_config();

		// Send connected event with slot info.
		$connected_data['slot'] = $slot;
		$this->send_sse_event( 'connected', $connected_data );

		/**
		 * Fires when an SSE stream is opened.
		 *
		 * @param int    $slot    Slot number assigned.
		 * @param int    $user_id Current user ID.
		 * @param string $route   REST route being streamed.
		 */
		\do_action( 'newspack_event_logger_sse_connected', $slot, \get_current_user_id(), static::class );

		return [
			'slot'            => $slot,
			'start_time'      => \time(),
			'last_slot_check' => \time(),
			'config'          => $config,
			'log_base'        => Config::get_logs_directory(),
			'num_partitions'  => $config['num_partitions'] ?? 1,
			'segment_size'    => $config['segment_size'] ?? 64 * 1024 * 1024,
			'num_segments'    => $config['num_segments'] ?? 4,
		];
	}

	/**
	 * Check if the SSE stream should continue.
	 *
	 * Call this at the start of each loop iteration. Returns false if:
	 * - Connection aborted by client
	 * - Max runtime exceeded
	 * - SSE slot expired
	 *
	 * @param array $context Stream context from start_sse_stream().
	 * @return bool True to continue, false to exit.
	 */
	protected function should_continue_stream( array &$context ): bool {
		if ( connection_aborted() ) {
			return false;
		}

		$now = \time();

		if ( $now - $context['start_time'] > static::MAX_RUNTIME ) {
			$this->send_sse_event( 'timeout', [ 'message' => 'Max runtime reached, reconnect to continue' ] );
			return false;
		}

		// Check if slot is still alive periodically.
		if ( $now - $context['last_slot_check'] >= static::SLOT_CHECK_INTERVAL ) {
			if ( ! $this->check_sse_slot() ) {
				return false;
			}
			$context['last_slot_check'] = $now;
		}

		return true;
	}

	/**
	 * Clean up SSE stream resources.
	 *
	 * Call this when exiting the stream loop.
	 */
	protected function end_sse_stream(): void {
		/** Fires when an SSE stream is closed. */
		\do_action( 'newspack_event_logger_sse_disconnected', \get_current_user_id(), static::class );
		$this->release_sse_slot();
	}

	/**
	 * Stream a log file via SSE with resume, batching, heartbeats.
	 *
	 * Extracts the common SSE polling loop shared by rawlogs, errors, and requests controllers.
	 * Subclasses provide a line transformer callback and configuration.
	 *
	 * @param \WP_REST_Request $request    REST request.
	 * @param array            $config     Stream config:
	 *   - string   'log_file'        Log directory name (e.g., 'errors.log').
	 *   - string   'event_name'      SSE event name for data batches.
	 *   - int      'tail_bytes'      Bytes to tail on first connect (default 1MB).
	 *   - int      'batch_threshold' Max batch size before forced send (default 50).
	 *   - array    'config_extras'   Extra fields for the config SSE event (default []).
	 * @param callable         $transform  fn(string $line, int $partition): ?array — returns batch entry or null to skip.
	 * @return \WP_Error|void
	 */
	protected function stream_log( \WP_REST_Request $request, array $config, callable $transform ) {
		$result = $this->stream_log_run( $request, $config, $transform );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}
		exit;
	}

	/**
	 * Parse and validate saved positions from request parameter.
	 *
	 * @param string|null $raw            Raw positions parameter.
	 * @param int         $num_partitions Number of partitions.
	 * @return array|null Validated positions array or null.
	 */
	protected function parse_positions( ?string $raw, int $num_partitions ): ?array {
		if ( \is_string( $raw ) && \strlen( $raw ) > 4096 ) {
			$raw = null;
		}
		$saved_pos = ! empty( $raw ) ? \json_decode( $raw, true ) : null;
		if ( \is_array( $saved_pos ) && \count( $saved_pos ) > $num_partitions ) {
			$saved_pos = \array_slice( $saved_pos, 0, $num_partitions );
		}
		return $saved_pos;
	}

	/**
	 * Set up firehose readers with resume or tail-seek positioning.
	 *
	 * @param string     $log_base   Log base directory.
	 * @param string     $log_file   Log file directory name.
	 * @param int        $num_partitions Number of partitions.
	 * @param array|null $saved_pos  Saved positions from client.
	 * @param int        $tail_bytes Bytes to tail on first connect.
	 * @return array{readers: FirehoseReader[], file_handles: array, needs_skip: array}
	 */
	protected function setup_readers( string $log_base, string $log_file, int $num_partitions, ?array $saved_pos, int $tail_bytes ): array {
		$readers      = [];
		$file_handles = [];
		$needs_skip   = [];

		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$firehose = new Firehose( "{$log_base}/{$log_file}", $p );
			$reader   = new FirehoseReader( $firehose );
			$reader->next_offset( 'end' );
			$end_pos = $reader->get_position();

			$resumed = false;
			if ( \is_array( $saved_pos ) && isset( $saved_pos[ $p ] ) ) {
				$sp = $saved_pos[ $p ];
				if ( isset( $sp['s'], $sp['o'] )
					&& (int) $sp['s'] === $end_pos['segment_id']
					&& $end_pos['offset'] - (int) $sp['o'] <= $tail_bytes
				) {
					$reader->next_offset( [
						'segment_id' => (int) $sp['s'],
						'offset'     => (int) $sp['o'],
					] );
					$resumed = true;
				}
			}

			if ( ! $resumed ) {
				$reader->next_offset( [
					'segment_id' => $end_pos['segment_id'],
					'offset'     => \max( 0, $end_pos['offset'] - $tail_bytes ),
				] );
				$needs_skip[ $p ] = true;
			}

			$readers[ $p ]      = $reader;
			$file_handles[ $p ] = null;
		}

		// Skip partial first line from mid-file seek.
		foreach ( $needs_skip as $p => $_ ) {
			$pos = $readers[ $p ]->get_position();
			if ( $pos['offset'] > 0 ) {
				$fh = $readers[ $p ]->open();
				if ( $fh ) {
					\stream_set_timeout( $fh, 1 );
					\fgets( $fh );
					$readers[ $p ]->update_offset();
					$file_handles[ $p ] = $fh;
				}
			}
		}

		return [
			'readers'      => $readers,
			'file_handles' => $file_handles,
		];
	}

	/**
	 * Run the stream log setup, polling loop, and cleanup (without exit).
	 *
	 * @param \WP_REST_Request $request    REST request.
	 * @param array            $config     Stream config (see stream_log).
	 * @param callable         $transform  Line transformer callback.
	 * @return \WP_Error|void WP_Error if rate limited, void on normal completion.
	 */
	protected function stream_log_run( \WP_REST_Request $request, array $config, callable $transform ) {
		$digest_interval = $request->get_param( 'interval' );
		$log_file        = $config['log_file'];
		$event_name      = $config['event_name'];
		$tail_bytes      = $config['tail_bytes'] ?? 1048576;
		$batch_threshold = $config['batch_threshold'] ?? 50;
		$config_extras   = $config['config_extras'] ?? [];

		$context = $this->start_sse_stream( [
			'num_partitions' => 0,
			'interval'       => $digest_interval,
		] );

		if ( \is_wp_error( $context ) ) {
			return $context;
		}

		$log_base       = $context['log_base'];
		$num_partitions = $context['num_partitions'];
		$saved_pos      = $this->parse_positions( $request->get_param( 'positions' ), $num_partitions );

		$setup        = $this->setup_readers( $log_base, $log_file, $num_partitions, $saved_pos, $tail_bytes );
		$readers      = $setup['readers'];
		$file_handles = $setup['file_handles'];

		$this->send_sse_event( 'config', \array_merge( [
			'num_partitions' => $num_partitions,
			'interval'       => $digest_interval,
		], $config_extras ) );

		$last_batch         = \microtime( true );
		$last_heartbeat     = \time();
		$batch_interval_sec = $digest_interval / 1000.0;
		$batch              = [];

		try {
			while ( $this->should_continue_stream( $context ) ) {
				$did_work      = false;
				$all_caught_up = true;

				foreach ( $readers as $p => $reader ) {
					$fh = $file_handles[ $p ];

					if ( $fh ) {
						$line = \fgets( $fh );
						if ( false !== $line ) {
							$reader->update_offset();
							$entry = $transform( \trim( $line ), $p );
							if ( null !== $entry ) {
								$batch[]  = $entry;
								$did_work = true;
							}
						} else {
							// fgets returns false on both timeout and EOF; disambiguate via stream metadata.
							$meta = \stream_get_meta_data( $fh );
							if ( ! empty( $meta['timed_out'] ) ) {
								continue;
							}
							$reader->mark_eof();
							$reader->update_offset();
							$file_handles[ $p ] = $reader->next_segment();
						}
					} else {
						$file_handles[ $p ] = $reader->open();
						if ( $file_handles[ $p ] ) {
							\stream_set_timeout( $file_handles[ $p ], 1 );
						}
					}

					if ( $file_handles[ $p ] && ! $reader->is_caught_up() ) {
						$all_caught_up = false;
					}
				}

				$now = \microtime( true );
				if ( ! empty( $batch ) && ( $now - $last_batch >= $batch_interval_sec || \count( $batch ) >= $batch_threshold ) ) {
					$this->send_sse_event( $event_name, $batch );
					$positions = [];
					foreach ( $readers as $rp => $r ) {
						$rpos              = $r->get_position();
						$positions[ $rp ] = [ 's' => $rpos['segment_id'], 'o' => $rpos['offset'] ];
					}
					$this->send_sse_event( 'positions', $positions );
					$batch      = [];
					$last_batch = $now;
				}

				if ( $all_caught_up && ! $did_work ) {
					$hb_now = \time();
					if ( $hb_now - $last_heartbeat >= self::HEARTBEAT_INTERVAL ) {
						$this->send_sse_event( 'heartbeat', [ 'ts' => $hb_now ] );
						$last_heartbeat = $hb_now;
					}
					$this->flush_if_needed();
					\usleep( 10000 );
				} elseif ( ! $did_work ) {
					\usleep( 1000 );
				}
			}
		} finally {
			// Always release reader handles and the SSE slot, even if the loop body throws.
			foreach ( $readers as $reader ) {
				$reader->close();
			}
			$this->end_sse_stream();
		}
	}
}
