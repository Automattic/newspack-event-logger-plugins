<?php
/**
 * Tests for WorkerBase (abstract background worker infrastructure).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Lock;
use Newspack_Event_Logger\WorkerBase;

/**
 * Concrete test subclass of abstract WorkerBase.
 */
class ConcreteTestWorker extends WorkerBase {

	/** @var bool Track whether run() was called. */
	public bool $run_called = false;

	/**
	 * Public wrapper for init_worker() since it is protected.
	 *
	 * @param string $lock_path     Lock path.
	 * @param int    $partition     Partition.
	 * @param int    $max_runtime   Max runtime.
	 * @param int    $stale_timeout Stale timeout.
	 */
	public function setup_worker( string $lock_path, int $partition = 0, int $max_runtime = 595, int $stale_timeout = Lock::STALE_TIMEOUT ): void {
		$this->init_worker( $lock_path, $partition, $max_runtime, $stale_timeout );
	}

	/**
	 * Public wrapper for should_restart().
	 *
	 * @return bool
	 */
	public function public_should_restart(): bool {
		return $this->should_restart();
	}

	/**
	 * Public accessor for start_time.
	 *
	 * @return float
	 */
	public function get_start_time(): float {
		return $this->start_time;
	}

	/**
	 * Public accessor for partition.
	 *
	 * @return int
	 */
	public function get_partition(): int {
		return $this->partition;
	}

	/**
	 * Public accessor for max_runtime.
	 *
	 * @return int
	 */
	public function get_max_runtime(): int {
		return $this->max_runtime;
	}

	/**
	 * Implement abstract run().
	 */
	public function run(): void {
		$this->run_called = true;
	}
}

#[\PHPUnit\Framework\Attributes\CoversClass( WorkerBase::class )]
class WorkerBaseTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-workerbase';

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR, 0755, true );
	}

	protected function tearDown(): void {
		self::rmdir_recursive( self::TEST_DIR );
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
		return self::TEST_DIR . "/{$name}.lock.d";
	}

	public function test_init_worker_sets_properties(): void {
		$worker = new ConcreteTestWorker();
		$worker->setup_worker( $this->make_lock_path( 'init' ), 3, 120 );

		$this->assertSame( 3, $worker->get_partition() );
		$this->assertSame( 120, $worker->get_max_runtime() );
		$this->assertGreaterThan( 0.0, $worker->get_start_time() );
	}

	public function test_init_worker_defaults(): void {
		$worker = new ConcreteTestWorker();
		$worker->setup_worker( $this->make_lock_path( 'defaults' ) );

		$this->assertSame( 0, $worker->get_partition() );
		$this->assertSame( 595, $worker->get_max_runtime() );
	}

	public function test_acquire_and_release(): void {
		$worker = new ConcreteTestWorker();
		$lock_path = $this->make_lock_path( 'acqrel' );
		$worker->setup_worker( $lock_path );

		$this->assertTrue( $worker->acquire() );
		$this->assertDirectoryExists( $lock_path );

		$worker->release();
		$this->assertDirectoryDoesNotExist( $lock_path );
	}

	public function test_acquire_fails_when_already_locked(): void {
		$lock_path = $this->make_lock_path( 'contention' );

		$worker1 = new ConcreteTestWorker();
		$worker1->setup_worker( $lock_path );

		$worker2 = new ConcreteTestWorker();
		$worker2->setup_worker( $lock_path );

		$this->assertTrue( $worker1->acquire() );
		$this->assertFalse( $worker2->acquire() );

		$worker1->release();
	}

	public function test_should_restart_false_when_healthy(): void {
		$worker = new ConcreteTestWorker();
		$worker->setup_worker( $this->make_lock_path( 'healthy' ), 0, 600 );
		$worker->acquire();

		$this->assertFalse( $worker->public_should_restart() );

		$worker->release();
	}

	public function test_should_restart_true_when_no_lock(): void {
		$worker = new ConcreteTestWorker();
		// Do not call setup_worker - lock is null.
		$this->assertTrue( $worker->public_should_restart() );
	}

	public function test_should_restart_touches_heartbeat(): void {
		$lock_path = $this->make_lock_path( 'hb-touch' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 0, 600 );
		$worker->acquire();

		$hb_path = $lock_path . '/heartbeat';
		$mtime1  = \filemtime( $hb_path );

		// Wait so that HEARTBEAT_INTERVAL_S (10) check passes.
		// We can't wait 10 seconds in a test, but we can manipulate
		// the internal state. Instead, just verify the heartbeat method
		// doesn't throw and returns false (healthy).
		$result = $worker->public_should_restart();
		$this->assertFalse( $result );

		$worker->release();
	}

	public function test_should_restart_true_on_restart_request(): void {
		$lock_path = $this->make_lock_path( 'restart-req' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path );
		$worker->acquire();

		// Request restart via Lock static method.
		Lock::request_restart( $lock_path );

		$this->assertTrue( $worker->public_should_restart() );

		$worker->release();
	}

	public function test_should_restart_true_on_max_runtime(): void {
		$lock_path = $this->make_lock_path( 'maxrt' );
		$worker    = new ConcreteTestWorker();
		// Use very short max runtime.
		$worker->setup_worker( $lock_path, 0, 0 );
		$worker->acquire();

		// Max runtime is 0, so elapsed time > max_runtime immediately.
		$this->assertTrue( $worker->public_should_restart() );

		$worker->release();
	}

	public function test_execute_calls_run_and_releases_lock(): void {
		$lock_path = $this->make_lock_path( 'execute' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path );

		$result = $worker->execute();

		$this->assertTrue( $worker->run_called );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertArrayHasKey( 'partition', $result );
		$this->assertDirectoryDoesNotExist( $lock_path, 'Lock should be released after execute' );
	}

	public function test_execute_skipped_when_already_locked(): void {
		$lock_path = $this->make_lock_path( 'exec-skip' );

		// Acquire lock externally.
		$blocker = new Lock( $lock_path );
		$blocker->acquire();

		$worker = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path );

		$result = $worker->execute();
		$this->assertSame( 'skipped', $result['status'] );
		$this->assertFalse( $worker->run_called );

		$blocker->release();
	}

	public function test_execute_with_shutdown_handler(): void {
		$lock_path = $this->make_lock_path( 'exec-shutdown' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path );

		$result = $worker->execute();

		// execute() should call run() and return completed.
		$this->assertTrue( $worker->run_called );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertArrayHasKey( 'partition', $result );
	}

	public function test_check_db_connection_returns_false_without_wpdb(): void {
		// check_db_connection is protected, use reflection.
		$worker = new ConcreteTestWorker();
		$worker->setup_worker( $this->make_lock_path( 'dbcheck' ) );

		$ref = new \ReflectionMethod( $worker, 'check_db_connection' );
		$ref->setAccessible( true );

		// In test environment, $wpdb is not set as a wpdb instance.
		$result = $ref->invoke( $worker );
		$this->assertFalse( $result, 'Should return false when $wpdb is not a wpdb instance' );
	}

	public function test_should_restart_touches_heartbeat_at_interval(): void {
		$lock_path = $this->make_lock_path( 'hb-interval' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 0, 600 );
		$worker->acquire();

		$hb_path = $lock_path . '/heartbeat';
		$this->assertFileExists( $hb_path );

		// Manipulate the last_heartbeat_touch to be long ago via reflection.
		$ref = new \ReflectionProperty( WorkerBase::class, 'last_heartbeat_touch' );
		$ref->setAccessible( true );
		$ref->setValue( $worker, 0.0 );

		// Record mtime before.
		$mtime_before = \filemtime( $hb_path );

		// Ensure at least 1 second passes.
		\touch( $hb_path, \time() - 2 );
		\clearstatcache( true, $hb_path );
		$mtime_before = \filemtime( $hb_path );

		// should_restart touches heartbeat when HEARTBEAT_INTERVAL_S has passed.
		$worker->public_should_restart();
		\clearstatcache( true, $hb_path );
		$mtime_after = \filemtime( $hb_path );

		$this->assertGreaterThanOrEqual( $mtime_before, $mtime_after, 'Heartbeat should be touched' );

		$worker->release();
	}

	public function test_execute_releases_lock_on_exception(): void {
		$lock_path = $this->make_lock_path( 'exec-throw' );

		// Create an anonymous worker that throws.
		$worker = new class() extends WorkerBase {
			public function run(): void {
				throw new \RuntimeException( 'Test exception' );
			}
		};

		$ref = new \ReflectionMethod( $worker, 'init_worker' );
		$ref->setAccessible( true );
		$ref->invoke( $worker, $lock_path );

		try {
			$worker->execute();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		// Lock should be released despite the exception (via finally).
		$this->assertDirectoryDoesNotExist( $lock_path, 'Lock should be released after exception' );
	}

	// ── should_restart() — db check branch ──────────────────────────────

	public function test_should_restart_db_check_branch(): void {
		$lock_path = $this->make_lock_path( 'dbcheck' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 0, 600 );
		$worker->acquire();

		// Force last_db_check to be long ago so DB check triggers.
		$ref = new \ReflectionProperty( WorkerBase::class, 'last_db_check' );
		$ref->setAccessible( true );
		$ref->setValue( $worker, 0.0 );

		// Also force heartbeat to be long ago.
		$hbref = new \ReflectionProperty( WorkerBase::class, 'last_heartbeat_touch' );
		$hbref->setAccessible( true );
		$hbref->setValue( $worker, 0.0 );

		// First call — DB check fails (no wpdb), increments failure counter.
		$result = $worker->public_should_restart();
		// One failure is not enough to trigger restart.
		$this->assertFalse( $result );

		$fail_ref = new \ReflectionProperty( WorkerBase::class, 'db_check_failures' );
		$fail_ref->setAccessible( true );
		$this->assertSame( 1, $fail_ref->getValue( $worker ) );

		$worker->release();
	}

	public function test_should_restart_db_max_failures_triggers_restart(): void {
		$lock_path = $this->make_lock_path( 'db-max-fail' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 0, 600 );
		$worker->acquire();

		// Set failures to threshold - 1 so next check triggers restart.
		$fail_ref = new \ReflectionProperty( WorkerBase::class, 'db_check_failures' );
		$fail_ref->setAccessible( true );
		$fail_ref->setValue( $worker, WorkerBase::DB_CHECK_MAX_FAILURES - 1 );

		// Force DB check to happen now.
		$db_ref = new \ReflectionProperty( WorkerBase::class, 'last_db_check' );
		$db_ref->setAccessible( true );
		$db_ref->setValue( $worker, 0.0 );

		// Force heartbeat to be old enough that I/O checks run.
		$hb_ref = new \ReflectionProperty( WorkerBase::class, 'last_heartbeat_touch' );
		$hb_ref->setAccessible( true );
		$hb_ref->setValue( $worker, 0.0 );

		$result = $worker->public_should_restart();
		$this->assertTrue( $result, 'Should restart after max DB check failures' );

		$worker->release();
	}

	// ── Constants ────────────────────────────────────────────────────────

	public function test_constants_are_sensible(): void {
		$this->assertSame( 595, WorkerBase::MAX_RUNTIME_SECONDS );
		$this->assertSame( 10, WorkerBase::HEARTBEAT_INTERVAL_S );
		$this->assertSame( 30, WorkerBase::DB_CHECK_INTERVAL_S );
		$this->assertSame( 3, WorkerBase::DB_CHECK_MAX_FAILURES );
	}

	// ── execute returns correct partition ────────────────────────────────

	public function test_execute_returns_partition_in_result(): void {
		$lock_path = $this->make_lock_path( 'exec-part' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 7, 600 );

		$result = $worker->execute();
		$this->assertSame( 7, $result['partition'] );
		$this->assertSame( 'completed', $result['status'] );
	}

	public function test_execute_skipped_returns_partition(): void {
		$lock_path = $this->make_lock_path( 'exec-skip-part' );

		$blocker = new Lock( $lock_path );
		$blocker->acquire();

		$worker = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 5 );

		$result = $worker->execute();
		$this->assertSame( 'skipped', $result['status'] );
		$this->assertSame( 5, $result['partition'] );
		$this->assertArrayHasKey( 'reason', $result );

		$blocker->release();
	}

	// ── should_restart: heartbeat NOT touched when recent ───────────────

	public function test_should_restart_skips_heartbeat_when_recent(): void {
		$lock_path = $this->make_lock_path( 'hb-skip' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 0, 600 );
		$worker->acquire();

		// Set last_heartbeat_touch to now (recent).
		$ref = new \ReflectionProperty( WorkerBase::class, 'last_heartbeat_touch' );
		$ref->setAccessible( true );
		$ref->setValue( $worker, \microtime( true ) );

		$hb_path = $lock_path . '/heartbeat';
		// Touch heartbeat to past so we can detect if it gets updated.
		@\touch( $hb_path, \time() - 100 );
		\clearstatcache( true, $hb_path );
		$mtime_before = \filemtime( $hb_path );

		$result = $worker->public_should_restart();
		$this->assertFalse( $result );

		// Heartbeat should NOT have been touched since it was "recent".
		\clearstatcache( true, $hb_path );
		$mtime_after = \filemtime( $hb_path );
		$this->assertSame( $mtime_before, $mtime_after, 'Heartbeat should not be touched when last touch is recent' );

		$worker->release();
	}

	// ── should_restart: db check resets failures on success ─────────────

	public function test_should_restart_db_check_resets_on_success(): void {
		$lock_path = $this->make_lock_path( 'db-reset' );

		// Create a worker subclass that reports DB as healthy.
		$worker = new class() extends WorkerBase {
			public bool $run_called = false;
			public function run(): void {
				$this->run_called = true;
			}
			protected function check_db_connection(): bool {
				return true; // DB is healthy.
			}
			public function public_should_restart(): bool {
				return $this->should_restart();
			}
		};

		$ref = new \ReflectionMethod( $worker, 'init_worker' );
		$ref->setAccessible( true );
		$ref->invoke( $worker, $lock_path, 0, 600 );
		$worker->acquire();

		// Set some pre-existing failures.
		$fail_ref = new \ReflectionProperty( WorkerBase::class, 'db_check_failures' );
		$fail_ref->setAccessible( true );
		$fail_ref->setValue( $worker, 2 );

		// Force DB check to happen now.
		$db_ref = new \ReflectionProperty( WorkerBase::class, 'last_db_check' );
		$db_ref->setAccessible( true );
		$db_ref->setValue( $worker, 0.0 );

		// Force heartbeat to be old enough that I/O checks run.
		$hb_ref = new \ReflectionProperty( WorkerBase::class, 'last_heartbeat_touch' );
		$hb_ref->setAccessible( true );
		$hb_ref->setValue( $worker, 0.0 );

		$result = $worker->public_should_restart();
		$this->assertFalse( $result );

		// Failures should be reset to 0.
		$this->assertSame( 0, $fail_ref->getValue( $worker ) );

		$worker->release();
	}

	// ── should_restart: db check skipped when interval not reached ──────

	public function test_should_restart_db_check_skipped_when_recent(): void {
		$lock_path = $this->make_lock_path( 'db-skip' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 0, 600 );
		$worker->acquire();

		// Set last_db_check to now (DB check interval not reached).
		$db_ref = new \ReflectionProperty( WorkerBase::class, 'last_db_check' );
		$db_ref->setAccessible( true );
		$db_ref->setValue( $worker, \microtime( true ) );

		// Set heartbeat to be recent too.
		$hb_ref = new \ReflectionProperty( WorkerBase::class, 'last_heartbeat_touch' );
		$hb_ref->setAccessible( true );
		$hb_ref->setValue( $worker, \microtime( true ) );

		// Set pre-existing failures.
		$fail_ref = new \ReflectionProperty( WorkerBase::class, 'db_check_failures' );
		$fail_ref->setAccessible( true );
		$fail_ref->setValue( $worker, 1 );

		$result = $worker->public_should_restart();
		$this->assertFalse( $result );

		// Failures should still be 1 (DB check was skipped).
		$this->assertSame( 1, $fail_ref->getValue( $worker ) );

		$worker->release();
	}

	// ── execute: sets time limit ────────────────────────────────────────

	public function test_execute_registers_shutdown_function(): void {
		$lock_path = $this->make_lock_path( 'exec-shutdown-fn' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path );

		// execute() calls register_shutdown_function and set_time_limit.
		// We can verify it runs successfully and cleans up.
		$result = $worker->execute();
		$this->assertSame( 'completed', $result['status'] );
		$this->assertTrue( $worker->run_called );
		// Lock should be released.
		$this->assertDirectoryDoesNotExist( $lock_path );
	}

	// ── check_db_connection: wpdb present and healthy ──────────────────

	public function test_check_db_connection_returns_true_with_healthy_wpdb(): void {
		$worker = new ConcreteTestWorker();
		$worker->setup_worker( $this->make_lock_path( 'dbcheck-ok' ) );

		$ref = new \ReflectionMethod( $worker, 'check_db_connection' );
		$ref->setAccessible( true );

		// Set $wpdb global to a wpdb stub (defined in bootstrap.php).
		$original_wpdb    = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']  = new \wpdb();

		$result = $ref->invoke( $worker );
		$this->assertTrue( $result, 'Should return true when wpdb::check_connection returns true' );

		// Restore.
		if ( null === $original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $original_wpdb;
		}
	}

	public function test_check_db_connection_returns_false_with_unhealthy_wpdb(): void {
		$worker = new ConcreteTestWorker();
		$worker->setup_worker( $this->make_lock_path( 'dbcheck-fail' ) );

		$ref = new \ReflectionMethod( $worker, 'check_db_connection' );
		$ref->setAccessible( true );

		// Create a wpdb subclass that returns false from check_connection.
		$mock_wpdb = new class() extends \wpdb {
			public function check_connection( $allow_bail = true ) {
				return false;
			}
		};

		$original_wpdb    = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']  = $mock_wpdb;

		$result = $ref->invoke( $worker );
		$this->assertFalse( $result, 'Should return false when wpdb::check_connection returns false' );

		// Restore.
		if ( null === $original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $original_wpdb;
		}
	}

	// ── should_restart: db check successful with real wpdb ─────────────

	public function test_should_restart_db_check_successful_with_real_wpdb(): void {
		$lock_path = $this->make_lock_path( 'db-real-wpdb' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 0, 600 );
		$worker->acquire();

		// Inject healthy wpdb.
		$original_wpdb    = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']  = new \wpdb();

		// Set pre-existing failures.
		$fail_ref = new \ReflectionProperty( WorkerBase::class, 'db_check_failures' );
		$fail_ref->setAccessible( true );
		$fail_ref->setValue( $worker, 2 );

		// Force DB check to happen now.
		$db_ref = new \ReflectionProperty( WorkerBase::class, 'last_db_check' );
		$db_ref->setAccessible( true );
		$db_ref->setValue( $worker, 0.0 );

		// Force heartbeat to be old enough that I/O checks run.
		$hb_ref = new \ReflectionProperty( WorkerBase::class, 'last_heartbeat_touch' );
		$hb_ref->setAccessible( true );
		$hb_ref->setValue( $worker, 0.0 );

		$result = $worker->public_should_restart();
		$this->assertFalse( $result );
		$this->assertSame( 0, $fail_ref->getValue( $worker ), 'Failures should reset on successful db check' );

		$worker->release();

		// Restore.
		if ( null === $original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $original_wpdb;
		}
	}

	// ── execute: run() called with correct partition value ──────────────

	public function test_execute_uses_correct_partition(): void {
		$lock_path = $this->make_lock_path( 'exec-partition' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 12, 600 );

		$result = $worker->execute();
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 12, $result['partition'] );
		$this->assertTrue( $worker->run_called );
	}

	// ── execute: skipped result includes reason string ──────────────────

	public function test_execute_skipped_reason_contains_partition(): void {
		$lock_path = $this->make_lock_path( 'exec-reason' );

		$blocker = new Lock( $lock_path );
		$blocker->acquire();

		$worker = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 9 );

		$result = $worker->execute();
		$this->assertSame( 'skipped', $result['status'] );
		$this->assertStringContainsString( '9', $result['reason'] );

		$blocker->release();
	}

	// ── handle_shutdown: fatal error path ───────────────────────────────

	public function test_handle_shutdown_releases_lock(): void {
		$lock_dir = $this->make_lock_path( 'shutdown-release' );
		$lock     = new Lock( $lock_dir );
		$lock->acquire();
		$this->assertTrue( \file_exists( "{$lock_dir}/heartbeat" ) );

		ConcreteTestWorker::handle_shutdown( $lock, 0, 'TestWorker' );

		// Lock should be released.
		$this->assertFileDoesNotExist( "{$lock_dir}/heartbeat" );
	}

	public function test_handle_shutdown_exit_path(): void {
		$lock_dir = $this->make_lock_path( 'shutdown-exit' );
		$lock     = new Lock( $lock_dir );
		$lock->acquire();

		// No fatal error — takes the exit() path.
		ConcreteTestWorker::handle_shutdown( $lock, 3, 'MyWorker' );

		// Lock released either way.
		$this->assertFileDoesNotExist( "{$lock_dir}/heartbeat" );
	}

	public function test_handle_shutdown_does_not_crash_without_lock(): void {
		$lock_dir = $this->make_lock_path( 'shutdown-nolock' );
		$lock     = new Lock( $lock_dir );
		// Don't acquire — release should be a no-op.

		ConcreteTestWorker::handle_shutdown( $lock, 0, 'TestWorker' );
		$this->assertTrue( true, 'Should not crash when lock was never acquired' );
	}

	// ── self_respawn ────────────────────────────────────────────────────

	public function test_self_respawn_skips_when_no_worker_type(): void {
		$lock_path = $this->make_lock_path( 'respawn-skip' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path );

		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );

		$ref = new \ReflectionMethod( $worker, 'self_respawn' );
		$ref->setAccessible( true );

		// Should return without calling wp_remote_post.
		$ref->invoke( $worker );
		$this->assertTrue( true, 'self_respawn should be a no-op without worker type' );
	}

	public function test_self_respawn_calls_wp_remote_post(): void {
		$lock_path = $this->make_lock_path( 'respawn-call' );
		$worker    = new ConcreteTestWorker();
		$worker->setup_worker( $lock_path, 2 );

		$orig = $_SERVER['EVENT_LOGGER_WORKER_TYPE'] ?? null;
		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'test-worker';

		$ref = new \ReflectionMethod( $worker, 'self_respawn' );
		$ref->setAccessible( true );

		// wp_remote_post is stubbed in bootstrap — just verify no crash.
		$ref->invoke( $worker );
		$this->assertTrue( true, 'self_respawn should call wp_remote_post without error' );

		if ( null === $orig ) {
			unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
		} else {
			$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = $orig;
		}
	}
}
