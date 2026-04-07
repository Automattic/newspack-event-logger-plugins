<?php
/**
 * Lock
 *
 * mkdir+heartbeat based locking utility.
 * Works on macOS Docker volumes where flock fails.
 * Uses atomic mkdir for lock acquisition and heartbeat file for stale detection.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lock class.
 */
class Lock {

	/**
	 * Default heartbeat staleness threshold in seconds.
	 * If a lock holder hasn't touched their heartbeat in this long, consider them dead.
	 */
	const STALE_TIMEOUT = 60;

	private string $lock_dir;
	private string $heartbeat_file;
	private string $started_file;
	private string $restart_file;
	private bool $acquired = false;
	private int $stale_timeout;

	/**
	 * Constructor.
	 *
	 * @param string $lock_path     Full path to the lock directory (should end in .lock.d).
	 * @param int    $stale_timeout Heartbeat staleness threshold in seconds (default: 60).
	 *
	 * @throws \RuntimeException If parent directory contains symlinks or is not canonical.
	 */
	public function __construct( string $lock_path, int $stale_timeout = self::STALE_TIMEOUT ) {
		Config::ensure_path( \dirname( $lock_path ) );
		$this->lock_dir       = $lock_path;
		$this->heartbeat_file = $this->lock_dir . '/heartbeat';
		$this->started_file   = $this->lock_dir . '/started';
		$this->restart_file   = $this->lock_dir . '/restart';
		$this->stale_timeout  = $stale_timeout;
	}

	/**
	 * Acquire lock using mkdir + heartbeat.
	 *
	 * @return bool True if lock acquired.
	 */
	public function acquire(): bool {
		// Try to create lock directory (atomic operation).
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
		if ( ! @\mkdir( $this->lock_dir, 0755 ) ) {
			// Lock exists - check if heartbeat is stale.
			\clearstatcache( true, $this->heartbeat_file );

			// If heartbeat file doesn't exist, lock dir is orphaned (crash during creation).
			if ( ! \file_exists( $this->heartbeat_file ) ) {
				// Give holder a moment to write heartbeat in case of race condition.
				\sleep( 1 );
				\clearstatcache( true, $this->heartbeat_file );
				if ( ! \file_exists( $this->heartbeat_file ) ) {
					// Still no heartbeat - treat as stale and take over.
					self::force_release( $this->lock_dir );
					// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
					if ( ! @\mkdir( $this->lock_dir, 0755 ) ) {
						return false;
					}
					// Fall through to write heartbeat below.
				} else {
					// Heartbeat appeared - another process has it, back off.
					return false;
				}
			} else {
				// Heartbeat file exists - check if stale.
				$mtime = @\filemtime( $this->heartbeat_file );
				if ( false === $mtime ) {
					// Can't read mtime (permissions?) - don't steal.
					return false;
				}
				$heartbeat_age = \time() - $mtime;
				if ( $heartbeat_age < $this->stale_timeout ) {
					return false;
				}
				// Heartbeat is stale - remove lock and retry.
				self::force_release( $this->lock_dir );
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
				if ( ! @\mkdir( $this->lock_dir, 0755 ) ) {
					return false;
				}
			}
		}

		// Write initial heartbeat with our PID.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		$heartbeat_result = @\file_put_contents( $this->heartbeat_file, (string) \getmypid() );

		// Write started timestamp and clear any pending restart.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		$started_result = @\file_put_contents( $this->started_file, (string) \time() );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		@\unlink( $this->restart_file );

		// Check for write failures - release lock if we couldn't write required files.
		if ( false === $heartbeat_result || false === $started_result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( "Lock::acquire() failed to write lock files in {$this->lock_dir}" );
			// Release the lock dir since we couldn't complete acquisition.
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
			@\rmdir( $this->lock_dir );
			return false;
		}

		$this->acquired = true;
		return true;
	}

	/**
	 * Update heartbeat to signal we're still alive.
	 */
	public function touch(): void {
		if ( ! $this->acquired ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_touch
		@\touch( $this->heartbeat_file );
	}

	/**
	 * Release the lock.
	 */
	public function release(): void {
		if ( $this->acquired ) {
			self::force_release( $this->lock_dir );
			$this->acquired = false;
		}
	}

	/**
	 * Check if restart has been requested or lock was taken/deleted by another process.
	 *
	 * @return bool True if should exit.
	 */
	public function should_restart(): bool {
		// Exit if restart requested.
		// Clear stat cache for long-running processes to see newly created files.
		\clearstatcache( true, $this->restart_file );
		if ( \file_exists( $this->restart_file ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf(
				'[EventLogger] Lock::should_restart: restart requested for %s (pid=%d)',
				$this->lock_dir,
				\getmypid()
			) );
			return true;
		}
		// Exit if lock was deleted or taken by another process.
		if ( $this->acquired ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$content = @\file_get_contents( $this->heartbeat_file );
			// Exit if heartbeat file is gone or contains different PID.
			if ( false === $content ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( \sprintf(
					'[EventLogger] Lock::should_restart: heartbeat file gone for %s (pid=%d)',
					$this->lock_dir,
					\getmypid()
				) );
				return true;
			}
			if ( (int) $content !== \getmypid() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( \sprintf(
					'[EventLogger] Lock::should_restart: lock stolen for %s (our_pid=%d, file_pid=%s)',
					$this->lock_dir,
					\getmypid(),
					\substr( $content, 0, 20 )
				) );
				return true;
			}
		}
		return false;
	}

	/**
	 * Request restart by creating restart file.
	 * Can be called without holding the lock.
	 *
	 * @param string $lock_dir The lock directory path.
	 * @return bool True if restart file was created.
	 */
	public static function request_restart( string $lock_dir ): bool {
		$restart_file = $lock_dir . '/restart';
		if ( ! \is_dir( $lock_dir ) ) {
			return false;
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		return @\file_put_contents( $restart_file, (string) \time() ) !== false;
	}

	/**
	 * Get worker start timestamp from lock directory.
	 * Can be called without holding the lock.
	 *
	 * @param string $lock_dir The lock directory path.
	 * @return int|null Start timestamp or null if not available.
	 */
	public static function get_started_time( string $lock_dir ): ?int {
		$started_file = $lock_dir . '/started';
		if ( ! \file_exists( $started_file ) ) {
			return null;
		}
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		$content = @\file_get_contents( $started_file );
		return $content !== false ? (int) $content : null;
	}

	/**
	 * Check if restart is pending for a lock directory.
	 * Can be called without holding the lock.
	 *
	 * @param string $lock_dir The lock directory path.
	 * @return bool True if restart is pending.
	 */
	public static function is_restart_pending( string $lock_dir ): bool {
		return \file_exists( $lock_dir . '/restart' );
	}

	/**
	 * Force release a lock directory.
	 * Can be called without holding the lock.
	 *
	 * @param string $lock_dir The lock directory path.
	 */
	public static function force_release( string $lock_dir ): void {
		try {
			Config::ensure_path( \dirname( $lock_dir ) );
		} catch ( \RuntimeException $e ) {
			return;
		}

		if ( ! \is_dir( $lock_dir ) ) {
			return;
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		@\unlink( "{$lock_dir}/heartbeat" );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		@\unlink( "{$lock_dir}/started" );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		@\unlink( "{$lock_dir}/restart" );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		@\rmdir( $lock_dir );
	}

}
