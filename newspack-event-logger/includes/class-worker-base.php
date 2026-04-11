<?php
/**
 * Worker Base
 *
 * Abstract base class for background workers.
 * Provides common infrastructure:
 * - Lock management (atomic mkdir + heartbeat)
 * - Runtime tracking
 * - Graceful restart handling
 *
 * Subclasses implement their own constructor and processing logic,
 * using the helpers provided here.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worker base class.
 */
abstract class WorkerBase {

	const MAX_RUNTIME_SECONDS      = 595;
	const LOCK_CHECK_INTERVAL_S    = 0.25;
	const LOCK_CHECK_GRACE_S       = 0.25;
	const HEARTBEAT_INTERVAL_S     = 10;
	const DB_CHECK_INTERVAL_S      = 30;
	const DB_CHECK_MAX_FAILURES    = 3;

	/**
	 * Run the worker. Implemented by subclasses.
	 *
	 * @return array Results.
	 */
	abstract public function run(): void;

	/**
	 * Execute the worker with locking.
	 *
	 * @return array Results with status and partition.
	 */
	public function execute(): array {
		if ( ! $this->acquire() ) {
			return [
				'status'    => 'skipped',
				'partition' => $this->partition,
				'reason'    => 'Worker already running for partition ' . $this->partition,
			];
		}

		// Grace period: let the previous worker notice it lost the lock and exit
		// before we load state from the offsetlog. Without this, both workers
		// could process the same data.
		\usleep( (int) ( self::LOCK_CHECK_GRACE_S * 1e6 ) );

		// Disable execution timeout for long-running workers.
		@\set_time_limit( 0 );

		// Track whether the try/finally block completed normally.
		// PHP's exit() bypasses finally blocks, so if the shutdown handler
		// fires without this flag set, we know exit() killed the worker.
		$lock              = $this->lock;
		$partition         = $this->partition;
		$worker_class      = static::class;
		$shutdown_handled  = false;

		\register_shutdown_function( function () use ( $lock, $partition, $worker_class, &$shutdown_handled ) {
			if ( ! $shutdown_handled ) {
				static::handle_shutdown( $lock, $partition, $worker_class );
			}
		} );

		try {
			$this->run();
			return [
				'status'    => 'completed',
				'partition' => $this->partition,
			];
		} finally {
			$shutdown_handled = true;
			$this->release();
			$this->self_respawn();
		}
	}

	/**
	 * Handle abnormal worker termination (fatal error or exit()).
	 *
	 * Logs the cause and releases the lock so supervisor can respawn immediately.
	 *
	 * @param Lock   $lock         The worker's lock.
	 * @param int    $partition    Partition number.
	 * @param string $worker_class Worker class name.
	 */
	public static function handle_shutdown( Lock $lock, int $partition, string $worker_class ): void {
		$error = \error_get_last();
		if ( $error && \in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf(
				'[EventLogger] FATAL: %s worker p%d died: %s in %s:%d',
				$worker_class,
				$partition,
				\substr( $error['message'] ?? '', 0, 500 ),
				$error['file'] ?? '',
				$error['line'] ?? 0
			) );
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf(
				'[EventLogger] EXIT: %s worker p%d terminated by exit() or die()',
				$worker_class,
				$partition
			) );
		}

		$lock->release();
	}

	/** @var Lock|null */
	protected ?Lock $lock = null;

	/** @var int */
	protected int $partition = 0;

	/** @var int */
	protected int $max_runtime;

	/** @var float */
	protected float $start_time = 0.0;

	/** @var float */
	protected float $last_lock_check = 0.0;

	/** @var float */
	protected float $last_heartbeat_touch = 0.0;

	/** @var float */
	protected float $last_db_check = 0.0;

	/** @var int */
	protected int $db_check_failures = 0;

	/**
	 * Initialize base worker properties.
	 *
	 * Call from subclass constructor.
	 *
	 * @param string $lock_path     Path for the lock file (without .d suffix).
	 * @param int    $partition     Partition index.
	 * @param int    $max_runtime   Maximum runtime in seconds.
	 * @param int    $stale_timeout Lock stale timeout in seconds.
	 */
	protected function init_worker( string $lock_path, int $partition = 0, int $max_runtime = self::MAX_RUNTIME_SECONDS, int $stale_timeout = Lock::STALE_TIMEOUT ): void {
		$this->partition   = $partition;
		$this->max_runtime = $max_runtime;
		$this->start_time  = \microtime( true );
		$this->lock        = new Lock( $lock_path, $stale_timeout );
	}

	/**
	 * Acquire lock.
	 *
	 * @return bool True if lock acquired.
	 */
	public function acquire(): bool {
		return $this->lock->acquire();
	}

	/**
	 * Release lock.
	 */
	public function release(): void {
		$this->lock->release();
	}

	/**
	 * Combined housekeeping check: touch heartbeat, check restart/runtime.
	 *
	 * Call this periodically from processing loop.
	 *
	 * @return bool True if worker should exit.
	 */
	protected function should_restart(): bool {
		if ( ! $this->lock ) {
			return true;
		}

		$now = \microtime( true );

		// Exit if max runtime exceeded (cheap float comparison — always check).
		if ( $now - $this->start_time >= $this->max_runtime ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf(
				'[EventLogger] %s worker p%d: max runtime reached (%.0fs), exiting gracefully',
				static::class,
				$this->partition,
				$now - $this->start_time
			) );
			return true;
		}

		// Fast check: lock still ours / restart requested? Every 250ms.
		if ( $now - $this->last_lock_check >= self::LOCK_CHECK_INTERVAL_S ) {
			$this->last_lock_check = $now;
			if ( $this->lock->should_restart() ) {
				return true;
			}
		}

		// Slow checks: heartbeat touch + db. Every 10s.
		if ( $now - $this->last_heartbeat_touch >= self::HEARTBEAT_INTERVAL_S ) {
			$this->last_heartbeat_touch = $now;
			$this->lock->touch();

			// Periodic database connection check.
			if ( $now - $this->last_db_check >= self::DB_CHECK_INTERVAL_S ) {
				$this->last_db_check = $now;
				if ( ! $this->check_db_connection() ) {
					++$this->db_check_failures;
					if ( $this->db_check_failures >= self::DB_CHECK_MAX_FAILURES ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						\error_log( \sprintf(
							'[EventLogger] %s worker p%d: database connection lost after %d checks, restarting',
							static::class,
							$this->partition,
							$this->db_check_failures
						) );
						return true;
					}
				} else {
					$this->db_check_failures = 0;
				}
			}
		}

		return false;
	}

	/**
	 * Spawn the next instance of this worker via the spawn endpoint.
	 */
	protected function self_respawn(): void {
		$type = \sanitize_text_field( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] ?? '' );
		if ( '' === $type ) {
			return;
		}

		\wp_remote_post( \rest_url( 'event-logger/v1/workers/spawn' ), [
			'blocking'  => false,
			'timeout'   => 0.01,
			'sslverify' => false,
			'body'      => [
				'type'      => $type,
				'partition' => $this->partition,
				'nonce'     => \Newspack_Event_Logger\Cron\Supervisor::generate_spawn_token(),
			],
		] );
	}

	/**
	 * Check if the database connection is still alive.
	 *
	 * Uses $wpdb->check_connection() which attempts a ping and reconnect.
	 *
	 * @return bool True if connection is healthy.
	 */
	protected function check_db_connection(): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		return $wpdb->check_connection( false );
	}

}
