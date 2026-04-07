<?php
/**
 * Tests for Lock (mkdir + heartbeat advisory locking).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Lock;

#[\PHPUnit\Framework\Attributes\CoversClass( Lock::class )]
class LockTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$this->temp_dir = '/tmp/event-logger-test/locks-' . \uniqid();
		@\mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		self::rmdir_recursive( $this->temp_dir );
		Config::reset();
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

	private function make_lock_path( string $name = 'test' ): string {
		return "{$this->temp_dir}/{$name}.lock.d";
	}

	public function test_acquire_creates_lock_directory(): void {
		$lock_path = $this->make_lock_path( 'create' );
		$lock      = new Lock( $lock_path );

		$this->assertTrue( $lock->acquire() );
		$this->assertDirectoryExists( $lock_path );

		// Heartbeat file should exist.
		$this->assertFileExists( $lock_path . '/heartbeat' );

		// Started file should exist.
		$this->assertFileExists( $lock_path . '/started' );

		// Heartbeat should contain our PID.
		$pid = \file_get_contents( $lock_path . '/heartbeat' );
		$this->assertSame( (string) \getmypid(), $pid );

		$lock->release();
	}

	public function test_acquire_returns_false_when_already_locked(): void {
		$lock_path = $this->make_lock_path( 'contention' );
		$lock1     = new Lock( $lock_path );
		$lock2     = new Lock( $lock_path );

		$this->assertTrue( $lock1->acquire() );
		$this->assertFalse( $lock2->acquire(), 'Second acquire on same lock should fail' );

		$lock1->release();
	}

	public function test_release_removes_lock_directory(): void {
		$lock_path = $this->make_lock_path( 'release' );
		$lock      = new Lock( $lock_path );

		$lock->acquire();
		$this->assertDirectoryExists( $lock_path );

		$lock->release();
		$this->assertDirectoryDoesNotExist( $lock_path );
	}

	public function test_release_without_acquire_is_noop(): void {
		$lock_path = $this->make_lock_path( 'norel' );
		$lock      = new Lock( $lock_path );

		// Should not throw.
		$lock->release();
		$this->assertDirectoryDoesNotExist( $lock_path );
	}

	public function test_touch_updates_heartbeat(): void {
		$lock_path = $this->make_lock_path( 'touch' );
		$lock      = new Lock( $lock_path );

		$lock->acquire();

		$hb_path = $lock_path . '/heartbeat';
		$mtime1  = \filemtime( $hb_path );

		// Wait a moment and touch.
		\sleep( 1 );
		$lock->touch();

		\clearstatcache( true, $hb_path );
		$mtime2 = \filemtime( $hb_path );

		$this->assertGreaterThanOrEqual( $mtime1, $mtime2, 'touch() should update mtime' );

		$lock->release();
	}

	public function test_touch_without_acquire_is_noop(): void {
		$lock_path = $this->make_lock_path( 'notouch' );
		$lock      = new Lock( $lock_path );

		// Should not throw.
		$lock->touch();
		$this->assertDirectoryDoesNotExist( $lock_path );
	}

	public function test_should_restart_returns_false_for_own_pid(): void {
		$lock_path = $this->make_lock_path( 'restart-own' );
		$lock      = new Lock( $lock_path );

		$lock->acquire();

		// Our PID matches, no restart file - should be false.
		$this->assertFalse( $lock->should_restart() );

		$lock->release();
	}

	public function test_should_restart_returns_true_for_restart_file(): void {
		$lock_path = $this->make_lock_path( 'restart-req' );
		$lock      = new Lock( $lock_path );

		$lock->acquire();

		// Create restart file.
		Lock::request_restart( $lock_path );
		$this->assertTrue( $lock->should_restart(), 'should_restart() should detect restart file' );

		$lock->release();
	}

	public function test_should_restart_returns_true_for_different_pid(): void {
		$lock_path = $this->make_lock_path( 'restart-pid' );
		$lock      = new Lock( $lock_path );

		$lock->acquire();

		// Overwrite heartbeat with a different PID.
		\file_put_contents( $lock_path . '/heartbeat', '99999999' );

		$this->assertTrue( $lock->should_restart(), 'should_restart() should detect PID mismatch' );

		$lock->release();
	}

	public function test_should_restart_returns_true_when_heartbeat_deleted(): void {
		$lock_path = $this->make_lock_path( 'restart-gone' );
		$lock      = new Lock( $lock_path );

		$lock->acquire();

		// Delete heartbeat file.
		@\unlink( $lock_path . '/heartbeat' );
		\clearstatcache( true, $lock_path . '/heartbeat' );

		$this->assertTrue( $lock->should_restart(), 'should_restart() should detect missing heartbeat' );

		$lock->release();
	}

	public function test_force_release_cleans_up(): void {
		$lock_path = $this->make_lock_path( 'force' );
		$lock      = new Lock( $lock_path );

		$lock->acquire();
		$this->assertDirectoryExists( $lock_path );

		// Force release from outside.
		Lock::force_release( $lock_path );
		$this->assertDirectoryDoesNotExist( $lock_path );
	}

	public function test_force_release_on_nonexistent_dir(): void {
		// Should not throw.
		Lock::force_release( $this->temp_dir . '/nonexistent.lock.d' );
		$this->assertTrue( true );
	}

	public function test_stale_lock_detection(): void {
		$lock_path = $this->make_lock_path( 'stale' );

		// Manually create a lock with an old heartbeat.
		@\mkdir( $lock_path, 0755 );
		\file_put_contents( $lock_path . '/heartbeat', '12345' );
		// Set heartbeat mtime to be very old (well past the default 60s timeout).
		\touch( $lock_path . '/heartbeat', \time() - 120 );
		\file_put_contents( $lock_path . '/started', (string) \time() );

		// New lock should be able to steal it.
		$lock = new Lock( $lock_path, 60 );
		$this->assertTrue( $lock->acquire(), 'Should acquire stale lock' );
		$this->assertDirectoryExists( $lock_path );

		// New heartbeat should have our PID.
		$pid = \file_get_contents( $lock_path . '/heartbeat' );
		$this->assertSame( (string) \getmypid(), $pid );

		$lock->release();
	}

	public function test_fresh_lock_cannot_be_stolen(): void {
		$lock_path = $this->make_lock_path( 'fresh' );

		// Manually create a lock with a fresh heartbeat (different PID).
		@\mkdir( $lock_path, 0755 );
		\file_put_contents( $lock_path . '/heartbeat', '12345' );
		\touch( $lock_path . '/heartbeat' ); // Current timestamp.
		\file_put_contents( $lock_path . '/started', (string) \time() );

		// New lock should NOT be able to steal it.
		$lock = new Lock( $lock_path, 60 );
		$this->assertFalse( $lock->acquire(), 'Should not steal fresh lock' );
	}

	public function test_request_restart_creates_file(): void {
		$lock_path = $this->make_lock_path( 'reqrestart' );
		$lock      = new Lock( $lock_path );

		$lock->acquire();

		$result = Lock::request_restart( $lock_path );
		$this->assertTrue( $result );
		$this->assertFileExists( $lock_path . '/restart' );

		$lock->release();
	}

	public function test_request_restart_on_nonexistent_dir(): void {
		$result = Lock::request_restart( $this->temp_dir . '/missing.lock.d' );
		$this->assertFalse( $result );
	}

	public function test_is_restart_pending(): void {
		$lock_path = $this->make_lock_path( 'pending' );
		$lock      = new Lock( $lock_path );

		$lock->acquire();

		$this->assertFalse( Lock::is_restart_pending( $lock_path ) );

		Lock::request_restart( $lock_path );
		$this->assertTrue( Lock::is_restart_pending( $lock_path ) );

		$lock->release();
	}

	public function test_get_started_time(): void {
		$lock_path = $this->make_lock_path( 'started' );
		$lock      = new Lock( $lock_path );

		$before = \time();
		$lock->acquire();
		$after = \time();

		$started = Lock::get_started_time( $lock_path );
		$this->assertNotNull( $started );
		$this->assertGreaterThanOrEqual( $before, $started );
		$this->assertLessThanOrEqual( $after, $started );

		$lock->release();
	}

	public function test_get_started_time_nonexistent(): void {
		$started = Lock::get_started_time( $this->temp_dir . '/missing.lock.d' );
		$this->assertNull( $started );
	}

	public function test_force_release_rejects_path_with_null_byte(): void {
		$bad_path = $this->temp_dir . "/evil\0.lock.d";

		// Should silently return without throwing.
		Lock::force_release( $bad_path );
		$this->assertTrue( true );
	}

	public function test_force_release_rejects_symlinked_parent(): void {
		// Create a real directory and a symlink to it.
		$real_dir = $this->temp_dir . '/real';
		$link_dir = $this->temp_dir . '/link';
		@\mkdir( $real_dir, 0755, true );
		@\symlink( $real_dir, $link_dir );

		// Create a lock dir under the symlink.
		$lock_path = $link_dir . '/test.lock.d';
		@\mkdir( $lock_path, 0755 );
		\file_put_contents( $lock_path . '/heartbeat', '12345' );

		// force_release should reject because parent resolves via symlink.
		Lock::force_release( $lock_path );

		// Lock dir should still exist (force_release bailed out).
		$this->assertDirectoryExists( $lock_path );

		// Clean up.
		@\unlink( $lock_path . '/heartbeat' );
		@\rmdir( $lock_path );
		@\unlink( $link_dir );
	}

	public function test_acquire_after_release(): void {
		$lock_path = $this->make_lock_path( 'reacquire' );
		$lock      = new Lock( $lock_path );

		$this->assertTrue( $lock->acquire() );
		$lock->release();
		$this->assertTrue( $lock->acquire(), 'Should be able to re-acquire after release' );

		$lock->release();
	}
}
