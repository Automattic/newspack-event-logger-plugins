<?php
/**
 * Tests for SupervisorBase (abstract base for event-loop workers).
 *
 * Tests delete_directory_recursive(), remove_stale_directory(),
 * constructor defaults, should_restart() logic, and lock management.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Lock;
use Newspack_Event_Logger\SupervisorBase;

/**
 * Concrete test subclass of abstract SupervisorBase.
 */
class ConcreteSupervisor extends SupervisorBase {

	/**
	 * Public accessor for max_runtime.
	 *
	 * @return int
	 */
	public function get_max_runtime(): int {
		return $this->max_runtime;
	}

	/**
	 * Public accessor for stale_timeout.
	 *
	 * @return int
	 */
	public function get_stale_timeout(): int {
		return $this->stale_timeout;
	}

	/**
	 * Public accessor for start_time.
	 *
	 * @return float
	 */
	public function get_start_time(): float {
		return $this->start_time;
	}
}

#[\PHPUnit\Framework\Attributes\CoversClass( SupervisorBase::class )]
class SupervisorBaseTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$this->temp_dir = '/tmp/event-logger-test/supervisorbase-' . \uniqid();
		@\mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		Config::reset();
		self::rmdir_recursive( $this->temp_dir );
		parent::tearDown();
	}

	private static function rmdir_recursive( string $dir ): void {
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @\rmdir( $item->getPathname() ) : @\unlink( $item->getPathname() );
		}
		@\rmdir( $dir );
	}

	// ── Constructor ───────────────────────────────────────────────────────

	public function test_constructor_sets_max_runtime(): void {
		$supervisor = new ConcreteSupervisor( null, 120 );
		$this->assertSame( 120, $supervisor->get_max_runtime() );
	}

	public function test_constructor_default_max_runtime(): void {
		$supervisor = new ConcreteSupervisor();
		$this->assertSame( SupervisorBase::MAX_RUNTIME_SECONDS, $supervisor->get_max_runtime() );
	}

	public function test_constructor_sets_start_time(): void {
		$before     = \microtime( true );
		$supervisor = new ConcreteSupervisor();
		$after      = \microtime( true );
		$this->assertGreaterThanOrEqual( $before, $supervisor->get_start_time() );
		$this->assertLessThanOrEqual( $after, $supervisor->get_start_time() );
	}

	public function test_constructor_sets_stale_timeout(): void {
		$supervisor = new ConcreteSupervisor( null, 3600, 120 );
		$this->assertSame( 120, $supervisor->get_stale_timeout() );
	}

	public function test_constructor_with_lock_path(): void {
		$lock_path  = "{$this->temp_dir}/test-supervisor.lock.d";
		$supervisor = new ConcreteSupervisor( $lock_path );
		// Lock exists but is not acquired yet.
		$this->assertTrue( $supervisor->acquire() );
		$supervisor->release();
	}

	// ── acquire / release ─────────────────────────────────────────────────

	public function test_acquire_returns_false_without_lock(): void {
		$supervisor = new ConcreteSupervisor(); // No lock path.
		$this->assertFalse( $supervisor->acquire() );
	}

	public function test_acquire_and_release(): void {
		$lock_path  = "{$this->temp_dir}/acq-rel.lock.d";
		$supervisor = new ConcreteSupervisor( $lock_path );
		$this->assertTrue( $supervisor->acquire() );
		$this->assertDirectoryExists( $lock_path );

		$supervisor->release();
		$this->assertDirectoryDoesNotExist( $lock_path );
	}

	// ── should_restart ────────────────────────────────────────────────────

	public function test_should_restart_returns_true_without_lock(): void {
		$supervisor = new ConcreteSupervisor();
		$this->assertTrue( $supervisor->should_restart(), 'No lock means should always restart' );
	}

	public function test_should_restart_returns_false_when_healthy(): void {
		$lock_path  = "{$this->temp_dir}/healthy.lock.d";
		$supervisor = new ConcreteSupervisor( $lock_path, 3600 ); // Long max_runtime.
		$supervisor->acquire();

		$this->assertFalse( $supervisor->should_restart() );

		$supervisor->release();
	}

	// ── delete_directory_recursive ────────────────────────────────────────

	public function test_delete_directory_recursive_removes_files_and_dirs(): void {
		$target = "{$this->temp_dir}/to-delete";
		@\mkdir( "{$target}/sub1/sub2", 0755, true );
		\file_put_contents( "{$target}/file1.txt", 'hello' );
		\file_put_contents( "{$target}/sub1/file2.txt", 'world' );
		\file_put_contents( "{$target}/sub1/sub2/file3.txt", 'deep' );

		$this->assertDirectoryExists( $target );

		SupervisorBase::delete_directory_recursive( $target );

		$this->assertDirectoryDoesNotExist( $target );
	}

	public function test_delete_directory_recursive_handles_nonexistent(): void {
		// Should not throw on nonexistent directory.
		SupervisorBase::delete_directory_recursive( "{$this->temp_dir}/does-not-exist" );
		$this->assertTrue( true, 'No exception for nonexistent directory' );
	}

	public function test_delete_directory_recursive_ignores_symlinks(): void {
		$real_dir = "{$this->temp_dir}/real";
		$link_dir = "{$this->temp_dir}/link";
		@\mkdir( $real_dir, 0755, true );
		\file_put_contents( "{$real_dir}/important.txt", 'keep me' );
		\symlink( $real_dir, $link_dir );

		// Deleting the symlink should NOT follow it.
		SupervisorBase::delete_directory_recursive( $link_dir );

		// The real directory and its contents should still exist.
		$this->assertFileExists( "{$real_dir}/important.txt" );
	}

	public function test_delete_directory_recursive_depth_limit(): void {
		// Create a directory tree deeper than MAX_DEPTH (20).
		$path = "{$this->temp_dir}/deep";
		$current = $path;
		for ( $i = 0; $i < 25; $i++ ) {
			$current .= "/level{$i}";
		}
		@\mkdir( $current, 0755, true );
		\file_put_contents( "{$current}/file.txt", 'deep file' );

		// delete_directory_recursive should not crash even with deep nesting.
		// It stops at MAX_DEPTH=20 so some deep files may survive, but it
		// should not throw or infinite recurse.
		SupervisorBase::delete_directory_recursive( $path );

		// Top-level directory may or may not be fully removed depending on
		// MAX_DEPTH. The important thing is no crash.
		$this->assertTrue( true, 'No crash with deep directory' );
	}

	public function test_delete_directory_recursive_rejects_outside_base(): void {
		// Create a directory outside the base_directory.
		$outside = '/tmp/event-logger-test-outside-' . \uniqid();
		@\mkdir( $outside, 0755, true );
		\file_put_contents( "{$outside}/secret.txt", 'do not delete' );

		SupervisorBase::delete_directory_recursive( $outside );

		// Directory and file should still exist — not deleted.
		$this->assertFileExists( "{$outside}/secret.txt" );

		// Clean up.
		@\unlink( "{$outside}/secret.txt" );
		@\rmdir( $outside );
	}

	public function test_delete_directory_recursive_allows_base_directory_itself(): void {
		$target = "{$this->temp_dir}/subdir";
		@\mkdir( $target, 0755, true );
		\file_put_contents( "{$target}/file.txt", 'data' );

		// Deleting the base directory itself should be allowed.
		SupervisorBase::delete_directory_recursive( $this->temp_dir );

		$this->assertDirectoryDoesNotExist( "{$target}" );
	}

	// ── remove_stale_directory ────────────────────────────────────────────

	public function test_remove_stale_directory_removes_old(): void {
		$target = "{$this->temp_dir}/stale";
		@\mkdir( $target, 0755, true );
		$file = "{$target}/heartbeat";
		\file_put_contents( $file, 'old' );

		// Set file mtime to 2 hours ago.
		\touch( $file, \time() - 7200 );

		// Stale age of 1 hour — should remove.
		SupervisorBase::remove_stale_directory( $target, 3600 );

		$this->assertDirectoryDoesNotExist( $target );
	}

	public function test_remove_stale_directory_keeps_fresh(): void {
		$target = "{$this->temp_dir}/fresh";
		@\mkdir( $target, 0755, true );
		\file_put_contents( "{$target}/heartbeat", 'recent' );
		// File mtime is now — fresh.

		// Stale age of 1 hour — should NOT remove.
		SupervisorBase::remove_stale_directory( $target, 3600 );

		$this->assertDirectoryExists( $target );
	}

	public function test_remove_stale_directory_handles_nonexistent(): void {
		SupervisorBase::remove_stale_directory( "{$this->temp_dir}/nope", 3600 );
		$this->assertTrue( true, 'No exception for nonexistent directory' );
	}

	public function test_remove_stale_directory_ignores_symlinks(): void {
		$real = "{$this->temp_dir}/real-stale";
		$link = "{$this->temp_dir}/link-stale";
		@\mkdir( $real, 0755, true );
		\file_put_contents( "{$real}/file.txt", 'data' );
		\touch( "{$real}/file.txt", \time() - 7200 );
		\symlink( $real, $link );

		// Should not remove symlink target.
		SupervisorBase::remove_stale_directory( $link, 3600 );

		$this->assertFileExists( "{$real}/file.txt" );
	}

	// ── Constants ─────────────────────────────────────────────────────────

	public function test_max_runtime_constant(): void {
		$this->assertSame( 3600, SupervisorBase::MAX_RUNTIME_SECONDS );
	}

	public function test_heartbeat_interval_constant(): void {
		$this->assertSame( 10, SupervisorBase::HEARTBEAT_INTERVAL_S );
	}
}
