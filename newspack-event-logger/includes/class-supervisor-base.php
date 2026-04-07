<?php
/**
 * Supervisor Base
 *
 * Base class for event loop-based workers.
 * Unlike Worker_Base (for cron-style batch processing), this is for
 * long-running event loop processes that use EventFramework::drain().
 *
 * Provides:
 * - Lock management (via Lock)
 * - Runtime tracking
 * - Heartbeat touching
 * - Standard "should continue" check for drain callbacks
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supervisor base class.
 */
class SupervisorBase {

	const MAX_RUNTIME_SECONDS  = 3600;
	const HEARTBEAT_INTERVAL_S = 10;

	/**
	 * Maximum recursion depth for directory operations.
	 */
	private const MAX_DEPTH = 20;

	/** @var Lock|null */
	protected ?Lock $lock = null;

	/** @var float */
	protected float $start_time = 0.0;

	/** @var int */
	protected int $max_runtime;

	/** @var float */
	protected float $last_heartbeat_touch = 0.0;

	/** @var int */
	protected int $stale_timeout;

	/**
	 * Constructor.
	 *
	 * @param string|null $lock_path     Path for lock (without .d suffix). Null for lazy init.
	 * @param int         $max_runtime   Maximum runtime in seconds.
	 * @param int         $stale_timeout Lock stale timeout in seconds (default: 60).
	 */
	public function __construct( ?string $lock_path = null, int $max_runtime = self::MAX_RUNTIME_SECONDS, int $stale_timeout = Lock::STALE_TIMEOUT ) {
		$this->max_runtime   = $max_runtime;
		$this->stale_timeout = $stale_timeout;
		$this->start_time    = \microtime( true );
		if ( null !== $lock_path ) {
			$this->lock = new Lock( $lock_path, $stale_timeout );
		}
	}

	/**
	 * Initialize or reinitialize the lock.
	 *
	 * @param string $lock_path     Path for lock (without .d suffix).
	 * @param int    $stale_timeout Lock stale timeout in seconds (default: uses instance value).
	 */
	protected function init_lock( string $lock_path, ?int $stale_timeout = null ): void {
		$this->lock = new Lock( $lock_path, $stale_timeout ?? $this->stale_timeout );
	}

	/**
	 * Acquire lock.
	 *
	 * @return bool True if lock acquired.
	 */
	public function acquire(): bool {
		return $this->lock ? $this->lock->acquire() : false;
	}

	/**
	 * Release lock.
	 */
	public function release(): void {
		if ( $this->lock ) {
			$this->lock->release();
		}
	}

	/**
	 * Check if should restart
	 *
	 * Call this from your EventFramework::drain() callback.
	 * Handles heartbeat touching automatically.
	 *
	 * @return bool True if should restart
	 */
	public function should_restart(): bool {
		if ( ! $this->lock ) {
			return true;
		}

		$now = \microtime( true );

		// Touch heartbeat periodically.
		if ( $now - $this->last_heartbeat_touch >= self::HEARTBEAT_INTERVAL_S ) {
			$this->lock->touch();
			$this->last_heartbeat_touch = $now;
		}

		// Exit if lock lost or restart requested.
		if ( $this->lock->should_restart() ) {
			return true;
		}

		// Exit if max runtime exceeded.
		if ( $now - $this->start_time >= $this->max_runtime ) {
			return true;
		}

		return false;
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $dir   Directory path.
	 * @param int    $depth Current recursion depth (internal use).
	 */
	public static function delete_directory_recursive( string $dir, int $depth = 0 ): void {
		// Prevent unbounded recursion.
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		if ( \is_link( $dir ) ) {
			return;
		}
		if ( ! \is_dir( $dir ) ) {
			return;
		}

		// Path containment check: only allow deletion within the configured base directory.
		if ( 0 === $depth ) {
			$base_directory = Config::get_base_directory();
			$real_dir       = \realpath( $dir );
			if ( false === $real_dir || ( $real_dir !== $base_directory && 0 !== \strpos( $real_dir, $base_directory . '/' ) ) ) {
				return;
			}
		}
		$items = @\scandir( $dir ) ?: [];
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( \is_dir( $path ) && ! \is_link( $path ) ) {
				self::delete_directory_recursive( $path, $depth + 1 );
			} else {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
				@\unlink( $path );
			}
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		@\rmdir( $dir );
	}

	/**
	 * Remove a directory if no files have been modified within stale_age seconds.
	 *
	 * @param string $dir       Directory path.
	 * @param int    $stale_age Age in seconds after which directory is considered stale.
	 */
	public static function remove_stale_directory( string $dir, int $stale_age ): void {
		// Skip symlinks to prevent escaping intended directory.
		if ( \is_link( $dir ) ) {
			return;
		}

		if ( ! \is_dir( $dir ) ) {
			return;
		}

		// Find newest file modification time.
		$newest_mtime = 0;
		$files        = @\scandir( $dir ) ?: [];
		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}
			$path = $dir . '/' . $file;
			// Skip symlinks when checking file times.
			if ( \is_link( $path ) ) {
				continue;
			}
			$mtime = @\filemtime( $path );
			// Skip files that can't be read.
			if ( false !== $mtime && $mtime > $newest_mtime ) {
				$newest_mtime = $mtime;
			}
		}

		// If newest file is older than stale_age, remove the directory.
		if ( $newest_mtime > 0 && ( \time() - $newest_mtime ) > $stale_age ) {
			self::delete_directory_recursive( $dir );
		}
	}
}
