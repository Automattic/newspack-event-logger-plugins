<?php
/**
 * Job Intake
 *
 * Provides an interface for import processes to queue large jobs.
 * Jobs written here are routed to jobs.log by JobRouter.
 *
 * Uses internal locking by default to ensure single-writer semantics
 * (required for allow_large_writes mode).
 *
 * @package Newspack_Event_Jobs
 */

namespace Newspack_Event_Jobs;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\Lock;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Intake class.
 *
 * Use this class from import processes (WP-CLI, admin actions, cron)
 * to queue large jobs that exceed the 4KB firehose limit.
 *
 * Example usage:
 * ```php
 * // Instance-based with round-robin (default):
 * $intake = new JobIntake();
 * $intake->write_job('import_event', ['data' => $data]);
 * $intake->write_job('import_event', ['data' => $data2]);  // Different partition
 * $intake->close();
 *
 * // Key-based partitioning (same key = same partition):
 * $intake->write_job('import_event', ['data' => $data], 'event_123');
 *
 * // Pin to specific partition for all writes:
 * $intake = new JobIntake();
 * $intake->partition(2);  // All writes go to partition 2
 * $intake->write_job('import_event', ['data' => $data]);
 *
 * // Static helper (single job):
 * JobIntake::queue('import_event', ['data' => $data]);
 * JobIntake::queue('import_event', ['data' => $data], 'event_123');  // Keyed
 * ```
 */
class JobIntake {

	/**
	 * Valid handler name pattern (must match JobRouter and JobWorker).
	 */
	private const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

	/**
	 * Maximum job size in bytes.
	 */
	private const MAX_JOB_SIZE = 10485760;

	/**
	 * Round-robin counter for partition distribution.
	 *
	 * @var int
	 */
	private static int $round_robin = 0;

	/**
	 * Firehose instances for each partition.
	 *
	 * @var array<int, Firehose>
	 */
	private array $firehoses = [];

	/**
	 * Lock instance for single-writer guarantee.
	 *
	 * @var Lock|null
	 */
	private ?Lock $lock = null;

	/**
	 * Pinned partition (null = round-robin).
	 *
	 * @var int|null
	 */
	private ?int $pinned_partition = null;

	/**
	 * Number of partitions.
	 *
	 * @var int
	 */
	private int $num_partitions;

	/**
	 * Whether to use locking.
	 *
	 * @var bool
	 */
	private bool $use_lock;

	/**
	 * Last touch timestamp (to throttle heartbeat updates).
	 *
	 * @var int
	 */
	private int $last_touch = 0;

	/**
	 * Whether initialized.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Constructor.
	 *
	 * @param bool $use_lock Whether to acquire lock (default: true).
	 */
	public function __construct( bool $use_lock = true ) {
		$this->use_lock = $use_lock;
		$config = Config::load_config();
		$this->num_partitions = $config['num_partitions'] ?? 1;
	}

	/**
	 * Destructor - release lock if held.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Pin all writes to a specific partition.
	 *
	 * @param int $partition Partition index.
	 * @return self For chaining.
	 */
	public function partition( int $partition ): self {
		$this->pinned_partition = $partition;
		return $this;
	}

	/**
	 * Initialize firehoses and acquire lock (lazy).
	 *
	 * @return bool True if initialized successfully, false if lock unavailable.
	 */
	private function init(): bool {
		if ( $this->initialized ) {
			return true;
		}

		$log_base = Config::get_logs_directory();

		// Acquire lock first if enabled.
		if ( $this->use_lock ) {
			$this->lock = new Lock( Config::get_locks_directory() . '/job-intake.lock.d' );
			if ( ! $this->lock->acquire() ) {
				$this->lock = null;
				return false;
			}
		}

		// Open firehose for each partition.
		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			$this->firehoses[ $p ] = ( new Firehose( "{$log_base}/jobintake.log", $p ) )
				->allow_large_writes();
		}

		$this->initialized = true;
		return true;
	}

	/**
	 * Write a job to the job intake.
	 *
	 * Partition selection:
	 * - If pinned via partition(), always uses that partition
	 * - If key provided, hashes to consistent partition
	 * - Otherwise, round-robin across partitions
	 *
	 * @param string      $handler    Handler name (alphanumeric, underscores, hyphens, max 64 chars).
	 * @param array       $parameters Job parameters (can be large).
	 * @param string|null $key        Optional partition key for consistent routing.
	 * @return bool True on success, false on validation failure, lock unavailable, or write error.
	 */
	public function write_job( string $handler, array $parameters, ?string $key = null ): bool {
		// Validate handler name.
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			return false;
		}

		if ( ! $this->init() ) {
			return false;
		}

		// Select partition.
		if ( null !== $this->pinned_partition ) {
			$partition = $this->pinned_partition;
		} elseif ( null !== $key && '' !== $key ) {
			$partition = Firehose::hash_to_partition( $key, $this->num_partitions );
		} else {
			$partition         = self::$round_robin % $this->num_partitions;
			self::$round_robin = ( self::$round_robin + 1 ) % \PHP_INT_MAX;
		}

		// Clamp partition to valid range.
		$partition = \max( 0, \min( $partition, $this->num_partitions - 1 ) );

		$job = [
			'type'       => 'job',
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => \microtime( true ),
		];

		$job_json = \wp_json_encode( $job );
		if ( false !== $job_json && \strlen( $job_json ) > self::MAX_JOB_SIZE ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( '[EventLogger] JobIntake: Job exceeds size limit for handler: ' . $handler );
			return false;
		}

		$result = $this->firehoses[ $partition ]->write( $job_json );

		if ( false !== $result ) {
			$this->touch();
		}

		return false !== $result;
	}

	/**
	 * Close the intake and release the lock.
	 */
	public function close(): void {
		$this->firehoses   = [];
		$this->initialized = false;
		if ( $this->lock ) {
			$this->lock->release();
			$this->lock = null;
		}
	}

	/**
	 * Write multiple jobs in a batch.
	 *
	 * @param array       $jobs Array of ['handler' => string, 'parameters' => array].
	 * @param string|null $key  Optional partition key for all jobs.
	 * @return int Number of jobs successfully written (0 if lock unavailable).
	 */
	public function write_jobs( array $jobs, ?string $key = null ): int {
		if ( ! $this->init() ) {
			return 0;
		}

		$written = 0;

		foreach ( $jobs as $job ) {
			$handler    = $job['handler'] ?? '';
			$parameters = $job['parameters'] ?? [];

			if ( ! \is_string( $handler ) || ! \is_array( $parameters ) ) {
				continue;
			}

			if ( $this->write_job( $handler, $parameters, $key ) ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Get the pinned partition, or null if using round-robin.
	 *
	 * @return int|null Partition index or null.
	 */
	public function get_partition(): ?int {
		return $this->pinned_partition;
	}

	/**
	 * Check if the intake is open (lock acquired, firehoses ready).
	 *
	 * @return bool True if open and ready to write.
	 */
	public function is_open(): bool {
		return $this->initialized;
	}

	/**
	 * Check if lock is available without acquiring it.
	 *
	 * Useful to check before starting a long import process.
	 * Note: Lock may become unavailable between check and actual write.
	 *
	 * @return bool True if lock appears available (or locking disabled).
	 */
	public function is_lock_available(): bool {
		if ( ! $this->use_lock ) {
			return true;
		}
		if ( $this->is_open() ) {
			return true; // Already have lock.
		}

		$lock_dir = Config::get_locks_directory() . '/job-intake.lock.d';

		if ( ! \is_dir( $lock_dir ) ) {
			return true; // No lock held.
		}

		// Check heartbeat staleness.
		$heartbeat_file = $lock_dir . '/heartbeat';
		$mtime = @\filemtime( $heartbeat_file );
		if ( false === $mtime ) {
			return true; // No heartbeat = stale lock.
		}

		return ( \time() - $mtime ) >= Lock::STALE_TIMEOUT;
	}

	/**
	 * Touch heartbeat for long-running imports.
	 *
	 * Call this periodically during long imports to prevent
	 * the lock from being considered stale.
	 */
	public function touch(): void {
		if ( $this->lock ) {
			$now = \time();
			if ( $now > $this->last_touch ) {
				$this->lock->touch();
				$this->last_touch = $now;
			}
		}
	}

	/**
	 * Maximum time to wait for lock in seconds (5 minutes).
	 */
	private const QUEUE_TIMEOUT_S = 300;

	/**
	 * Sleep interval between lock retries in microseconds.
	 */
	private const QUEUE_RETRY_INTERVAL_US = 100000; // 100ms

	/**
	 * Static helper to write a single job.
	 *
	 * If a key is provided, jobs with the same key always go to the same partition.
	 * If no key is provided, jobs are distributed via round-robin.
	 *
	 * Blocks with retry if lock is held by another process (up to 5 min timeout).
	 *
	 * @param string      $handler    Handler name.
	 * @param array       $parameters Job parameters.
	 * @param string|null $key        Optional partition key (e.g., event ID).
	 * @return bool True on success, false on validation failure or timeout.
	 */
	public static function queue( string $handler, array $parameters, ?string $key = null ): bool {
		// Validate handler name before entering retry loop (fail fast).
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			return false;
		}

		// Retry with backoff until lock acquired or timeout.
		$deadline = \microtime( true ) + self::QUEUE_TIMEOUT_S;

		while ( true ) {
			$intake = new self();
			$result = $intake->write_job( $handler, $parameters, $key );
			$was_open = $intake->is_open();
			$intake->close();

			if ( $result ) {
				return true;
			}

			// If we acquired the lock (was_open) but write still failed,
			// it's a permanent error (disk full, size exceeded) — don't retry.
			if ( $was_open ) {
				return false;
			}

			// Check if we timed out.
			if ( \microtime( true ) >= $deadline ) {
				return false;
			}

			// Lock contention — wait before retry.
			\usleep( self::QUEUE_RETRY_INTERVAL_US );
		}
	}
}
