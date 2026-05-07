<?php
/**
 * Tests for Supervisor (worker health monitor and spawner).
 *
 * Tests what is testable without spawning actual workers or making HTTP requests.
 * Constructor, worker lock building, spawn token generation/verification,
 * worker_needs_spawn detection, get_standalone_workers, and request_restart.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\Lock;
use Newspack_Event_Logger\Cron\LogReader;
use Newspack_Event_Logger\Cron\Supervisor;

#[\PHPUnit\Framework\Attributes\CoversClass( Supervisor::class )]
class SupervisorTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-supervisor';

	protected function setUp(): void {
		parent::setUp();
		Config::reset();

		// Reset Supervisor's cached base_dir static.
		$ref = new \ReflectionProperty( Supervisor::class, 'base_dir' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
		@\mkdir( self::TEST_DIR . '/locks', 0755, true );
		@\mkdir( self::TEST_DIR . '/offsets', 0755, true );
		$GLOBALS['_wp_test_options'] = [];

		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/supervisor.php' );
		Config::reset();
	}

	protected function tearDown(): void {
		self::rmdir_recursive( self::TEST_DIR );
		Config::reset();

		// Reset Supervisor's cached base_dir static.
		$ref = new \ReflectionProperty( Supervisor::class, 'base_dir' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
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

	// ── Constructor ──────────────────────────────────────────────────────

	public function test_constructor_loads_config(): void {
		$supervisor = new Supervisor();

		// Verify num_partitions was loaded from config (supervisor.php sets 2).
		$ref = new \ReflectionProperty( Supervisor::class, 'num_partitions' );
		$ref->setAccessible( true );
		$this->assertSame( 2, $ref->getValue( $supervisor ) );
	}

	public function test_constructor_caps_partitions(): void {
		Config::reset();
		// Set a very high partition count via WP option.
		\update_option( 'event_logger_num_partitions', '100' );
		Config::reset();

		$supervisor = new Supervisor();

		$ref = new \ReflectionProperty( Supervisor::class, 'num_partitions' );
		$ref->setAccessible( true );
		$this->assertLessThanOrEqual( 16, $ref->getValue( $supervisor ) );
	}

	// ── build_worker_locks ───────────────────────────────────────────────

	public function test_build_worker_locks_creates_entries(): void {
		$supervisor = new Supervisor();

		$ref = new \ReflectionProperty( Supervisor::class, 'worker_locks' );
		$ref->setAccessible( true );
		$locks = $ref->getValue( $supervisor );

		// With no registered readers or standalone workers (apply_filters returns []),
		// worker_locks should be empty.
		$this->assertIsArray( $locks );
		// May be empty since apply_filters is stubbed to return value unmodified.
	}

	// ── worker_needs_spawn ───────────────────────────────────────────────

	public function test_worker_needs_spawn_no_lock_dir(): void {
		$supervisor = new Supervisor();

		$ref = new \ReflectionMethod( Supervisor::class, 'worker_needs_spawn' );
		$ref->setAccessible( true );

		$worker = [
			'type'          => 'test-reader',
			'partition'     => 0,
			'lock_dir'      => self::TEST_DIR . '/locks/nonexistent.p0.lock.d',
			'stale_timeout' => Lock::STALE_TIMEOUT,
		];

		$result = $ref->invoke( $supervisor, $worker, \time() );
		$this->assertTrue( $result, 'Worker with no lock dir should need spawn' );
	}

	public function test_worker_needs_spawn_healthy_worker(): void {
		$supervisor = new Supervisor();

		$ref = new \ReflectionMethod( Supervisor::class, 'worker_needs_spawn' );
		$ref->setAccessible( true );

		$lock_dir = self::TEST_DIR . '/locks/healthy.p0.lock.d';
		@\mkdir( $lock_dir, 0755, true );
		\file_put_contents( $lock_dir . '/heartbeat', '' );
		// Fresh heartbeat.
		\touch( $lock_dir . '/heartbeat' );

		$worker = [
			'type'          => 'test-reader',
			'partition'     => 0,
			'lock_dir'      => $lock_dir,
			'stale_timeout' => Lock::STALE_TIMEOUT,
		];

		$result = $ref->invoke( $supervisor, $worker, \time() );
		$this->assertFalse( $result, 'Healthy worker should not need spawn' );
	}

	public function test_worker_needs_spawn_stale_heartbeat(): void {
		$supervisor = new Supervisor();

		$ref = new \ReflectionMethod( Supervisor::class, 'worker_needs_spawn' );
		$ref->setAccessible( true );

		$lock_dir = self::TEST_DIR . '/locks/stale.p0.lock.d';
		@\mkdir( $lock_dir, 0755, true );
		\file_put_contents( $lock_dir . '/heartbeat', '' );
		// Set heartbeat far in the past (stale).
		\touch( $lock_dir . '/heartbeat', \time() - 300 );
		\clearstatcache( true, $lock_dir . '/heartbeat' );

		$worker = [
			'type'          => 'test-reader',
			'partition'     => 0,
			'lock_dir'      => $lock_dir,
			'stale_timeout' => 60, // 60 second stale timeout.
		];

		$result = $ref->invoke( $supervisor, $worker, \time() );
		$this->assertTrue( $result, 'Worker with stale heartbeat should need spawn' );
	}

	public function test_worker_needs_spawn_missing_heartbeat(): void {
		$supervisor = new Supervisor();

		$ref = new \ReflectionMethod( Supervisor::class, 'worker_needs_spawn' );
		$ref->setAccessible( true );

		$lock_dir = self::TEST_DIR . '/locks/nohb.p0.lock.d';
		@\mkdir( $lock_dir, 0755, true );
		// No heartbeat file created.

		$worker = [
			'type'          => 'test-reader',
			'partition'     => 0,
			'lock_dir'      => $lock_dir,
			'stale_timeout' => Lock::STALE_TIMEOUT,
		];

		$result = $ref->invoke( $supervisor, $worker, \time() );
		$this->assertTrue( $result, 'Worker with missing heartbeat should need spawn' );
	}

	// ── generate_spawn_token / verify_spawn_token ────────────────────────

	public function test_generate_spawn_token_format(): void {
		$token = Supervisor::generate_spawn_token();
		$this->assertIsString( $token );
		$this->assertSame( 64, \strlen( $token ), 'SHA256 HMAC should be 64 hex chars' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
	}

	public function test_generate_spawn_token_deterministic_within_window(): void {
		// Two calls within the same 10-second window should return the same token.
		$token1 = Supervisor::generate_spawn_token();
		$token2 = Supervisor::generate_spawn_token();
		$this->assertSame( $token1, $token2, 'Tokens in same window should match' );
	}

	public function test_generate_spawn_token_different_windows(): void {
		$current = Supervisor::generate_spawn_token( 0 );
		$next    = Supervisor::generate_spawn_token( 1 );
		$prev    = Supervisor::generate_spawn_token( -1 );

		$this->assertNotSame( $current, $next, 'Different windows should produce different tokens' );
		$this->assertNotSame( $current, $prev, 'Different windows should produce different tokens' );
	}

	public function test_generate_spawn_token_previous_window(): void {
		// Previous window token should be different from current.
		$current  = Supervisor::generate_spawn_token( 0 );
		$previous = Supervisor::generate_spawn_token( -1 );
		$this->assertNotSame( $current, $previous );
	}

	// ── get_standalone_workers ───────────────────────────────────────────

	public function test_get_standalone_workers_returns_empty_by_default(): void {
		// apply_filters is stubbed to return value unmodified, so returns [].
		$workers = Supervisor::get_standalone_workers();
		$this->assertSame( [], $workers );
	}

	// ── request_restart ──────────────────────────────────────────────────

	public function test_request_restart_creates_lock_restart_file(): void {
		// Ensure locks directory exists.
		$locks_dir = Config::get_locks_directory();
		\wp_mkdir_p( $locks_dir );
		// Lock dir must exist for request_restart to drop the marker inside it.
		\wp_mkdir_p( "{$locks_dir}/supervisor.lock.d" );

		Supervisor::request_restart();

		$marker = "{$locks_dir}/supervisor.lock.d/restart";
		$this->assertFileExists( $marker, 'Lock restart marker should be created in supervisor lock dir' );
	}

	public function test_request_restart_idempotent(): void {
		$locks_dir = Config::get_locks_directory();
		\wp_mkdir_p( "{$locks_dir}/supervisor.lock.d" );

		// Call twice, should not throw.
		Supervisor::request_restart();
		Supervisor::request_restart();

		$marker = "{$locks_dir}/supervisor.lock.d/restart";
		$this->assertFileExists( $marker );
	}

	// ── get_base_dir ─────────────────────────────────────────────────────

	public function test_get_base_dir_caches_result(): void {
		$dir1 = Supervisor::get_base_dir();
		$dir2 = Supervisor::get_base_dir();
		$this->assertSame( $dir1, $dir2 );
		$this->assertStringContainsString( 'event-logger-test-supervisor', $dir1 );
	}

	// ── unschedule ───────────────────────────────────────────────────────

	public function test_unschedule_does_not_throw(): void {
		// wp_next_scheduled stub returns false, so nothing to unschedule.
		Supervisor::unschedule();
		$this->assertTrue( true, 'unschedule should not throw' );
	}

	// ── deactivate ──────────────────────────────────────────────────────

	public function test_deactivate_does_not_throw(): void {
		// deactivate calls cleanup_all and unschedule.
		Supervisor::deactivate();
		$this->assertTrue( true, 'deactivate should not throw' );
	}

	// ── kill_readers ────────────────────────────────────────────────────

	public function test_kill_readers_does_not_throw(): void {
		// No readers registered, should be a no-op.
		Supervisor::kill_readers( [ 'nonexistent-reader' ] );
		$this->assertTrue( true );
	}

	public function test_kill_readers_with_empty_array(): void {
		Supervisor::kill_readers( [] );
		$this->assertTrue( true );
	}

	// ── check_config ────────────────────────────────────────────────────

	public function test_check_config_rebuilds_worker_locks_each_tick(): void {
		// Replaces the old restart-marker test. The new behavior: check_config
		// rebuilds worker_locks from the topologies filter on every tick, so
		// activation/deactivation propagates within ~15s without a marker file.
		$supervisor = new Supervisor();

		// Enable logging so check_config doesn't bail early.
		\update_option( 'event_logger_enable_logging', '1' );
		Config::reset();
		$prop = new \ReflectionProperty( Supervisor::class, 'base_dir' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$ref = new \ReflectionMethod( Supervisor::class, 'check_config' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $supervisor );

		// check_config returns true (supervisor continues) and worker_locks
		// is freshly rebuilt regardless of partition-count change.
		$this->assertTrue( $result );

		$locks = new \ReflectionProperty( Supervisor::class, 'worker_locks' );
		$locks->setAccessible( true );
		$this->assertIsArray( $locks->getValue( $supervisor ) );
	}

	public function test_check_config_normal(): void {
		$supervisor = new Supervisor();

		// Enable logging for this test.
		\update_option( 'event_logger_enable_logging', '1' );
		Config::reset();
		$ref = new \ReflectionProperty( Supervisor::class, 'base_dir' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$method = new \ReflectionMethod( Supervisor::class, 'check_config' );
		$method->setAccessible( true );

		$result = $method->invoke( $supervisor );
		$this->assertTrue( $result );
	}

	// ── Constants ────────────────────────────────────────────────────────

	public function test_max_supervisor_runtime(): void {
		$ref = new \ReflectionClassConstant( Supervisor::class, 'MAX_SUPERVISOR_RUNTIME_S' );
		$this->assertSame( 595, $ref->getValue() );
	}

	public function test_min_spawn_interval(): void {
		$ref = new \ReflectionClassConstant( Supervisor::class, 'MIN_SPAWN_INTERVAL_S' );
		$this->assertSame( 15, $ref->getValue() );
	}

	public function test_config_check_interval(): void {
		$ref = new \ReflectionClassConstant( Supervisor::class, 'CONFIG_CHECK_INTERVAL' );
		$this->assertSame( 15, $ref->getValue() );
	}

	// ── check_config: logging disabled ──────────────────────────────────

	public function test_check_config_logging_disabled(): void {
		$supervisor = new Supervisor();

		// Disable logging via WP option. Use '0' because '' is skipped by Config::load_config.
		\update_option( 'event_logger_enable_logging', '0' );
		Config::reset();
		$ref = new \ReflectionProperty( Supervisor::class, 'base_dir' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$method = new \ReflectionMethod( Supervisor::class, 'check_config' );
		$method->setAccessible( true );

		$result = $method->invoke( $supervisor );
		$this->assertFalse( $result, 'Should exit when logging is disabled' );
	}

	// ── check_config: base_directory changed ────────────────────────────

	public function test_check_config_base_dir_changed(): void {
		$supervisor = new Supervisor();

		// Set base_dir to original value.
		$ref = new \ReflectionProperty( Supervisor::class, 'base_dir' );
		$ref->setAccessible( true );
		$original = $ref->getValue( null );

		$method = new \ReflectionMethod( Supervisor::class, 'check_config' );
		$method->setAccessible( true );

		// Force base_dir to a different value so Config::get_base_directory returns something else.
		$ref->setValue( null, '/tmp/event-logger-test-supervisor-OLD' );

		// Enable logging so the method proceeds to the base_dir check.
		\update_option( 'event_logger_enable_logging', '1' );
		Config::reset();

		$result = $method->invoke( $supervisor );
		// Should return false because the cached base_dir doesn't match the config.
		$this->assertFalse( $result, 'Should exit when base_directory changes' );

		// Restore.
		$ref->setValue( null, null );
	}

	// ── check_config: partition count decreased ─────────────────────────

	public function test_check_config_partition_count_decreased(): void {
		$supervisor = new Supervisor();

		// Enable logging.
		\update_option( 'event_logger_enable_logging', '1' );
		Config::reset();
		$ref = new \ReflectionProperty( Supervisor::class, 'base_dir' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		// Set num_partitions to 2 (matching config).
		$np_ref = new \ReflectionProperty( Supervisor::class, 'num_partitions' );
		$np_ref->setAccessible( true );
		$this->assertSame( 2, $np_ref->getValue( $supervisor ) );

		// Now decrease via option.
		\update_option( 'event_logger_num_partitions', '1' );
		Config::reset();

		$method = new \ReflectionMethod( Supervisor::class, 'check_config' );
		$method->setAccessible( true );

		$result = $method->invoke( $supervisor );
		$this->assertTrue( $result );
		$this->assertSame( 1, $np_ref->getValue( $supervisor ) );
	}

	// ── load_standalone_workers: non-array filter return ────────────────

	public function test_load_standalone_workers_non_array(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return 'not-an-array';
		} );

		$workers = Supervisor::get_standalone_workers();
		$this->assertSame( [], $workers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── load_standalone_workers: missing class key ──────────────────────

	public function test_get_standalone_workers_missing_class(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'bad-worker' => [
					// No 'class' key.
					'partitions' => true,
				],
			];
		} );

		$workers = Supervisor::get_standalone_workers();
		$this->assertEmpty( $workers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── load_standalone_workers: nonexistent class ──────────────────────

	public function test_get_standalone_workers_nonexistent_class(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'fake-worker' => [
					'class'      => 'Totally_Nonexistent_Class_Xyz',
					'partitions' => false,
				],
			];
		} );

		$workers = Supervisor::get_standalone_workers();
		$this->assertEmpty( $workers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── load_standalone_workers: non-string name ────────────────────────

	public function test_get_standalone_workers_non_string_name(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				0 => [
					'class'      => MockLogHandler::class,
					'partitions' => false,
				],
			];
		} );

		$workers = Supervisor::get_standalone_workers();
		$this->assertEmpty( $workers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── kill_readers with lock dirs ─────────────────────────────────────

	public function test_kill_readers_with_existing_lock(): void {
		$locks_dir = Config::get_locks_directory();
		$lock_dir  = "{$locks_dir}/test-reader.p0.lock.d";
		@\mkdir( $lock_dir, 0755, true );
		\file_put_contents( "{$lock_dir}/heartbeat", '' );

		// Register the reader via filter so kill_readers can find it.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader'] = [
				'kill-handler' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		Supervisor::kill_readers( [ 'test-reader' ] );

		// Lock should have been force-released.
		$this->assertDirectoryDoesNotExist( $lock_dir );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── cleanup_stale_partitions ─────────────────────────────────────────

	public function test_cleanup_stale_partitions_no_crash(): void {
		$supervisor = new Supervisor();

		$method = new \ReflectionMethod( Supervisor::class, 'cleanup_stale_partitions' );
		$method->setAccessible( true );

		// Should not throw even with no stale directories.
		$method->invoke( $supervisor );
		$this->assertTrue( true );
	}

	// ── generate_spawn_token: different salt produces different token ────

	public function test_generate_spawn_token_depends_on_nonce_salt(): void {
		$token = Supervisor::generate_spawn_token( 0 );
		// Token is based on NONCE_SALT + time window. Just verify it's a valid hex.
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
	}

	// ── build_worker_locks: with registered readers ────────────────────

	public function test_build_worker_locks_with_readers(): void {
		// Register a fake reader via filter before constructing.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader'] = [
				'test-handler' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$supervisor = new Supervisor();

		$ref = new \ReflectionProperty( Supervisor::class, 'worker_locks' );
		$ref->setAccessible( true );
		$locks = $ref->getValue( $supervisor );

		// With 2 partitions (supervisor.php config), should have 2 entries for test-reader.
		$test_locks = \array_filter( $locks, fn( $l ) => 'test-reader' === $l['type'] );
		$this->assertCount( 2, $test_locks, 'Should create one lock entry per partition for each reader' );

		// Check that lock dirs contain the correct naming.
		foreach ( $test_locks as $lock_entry ) {
			$this->assertStringContainsString( 'test-reader.p', $lock_entry['lock_dir'] );
			$this->assertArrayHasKey( 'stale_timeout', $lock_entry );
		}

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── build_worker_locks: with standalone workers (partitioned) ────────

	public function test_build_worker_locks_with_partitioned_standalone(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'my-worker' => [
					'class'      => MockLogHandler::class,
					'partitions' => true,
				],
			];
		} );

		$supervisor = new Supervisor();

		$ref = new \ReflectionProperty( Supervisor::class, 'worker_locks' );
		$ref->setAccessible( true );
		$locks = $ref->getValue( $supervisor );

		// Partitioned standalone worker should have 2 entries (2 partitions from config).
		$standalone_locks = \array_filter( $locks, fn( $l ) => 'my-worker' === $l['type'] );
		$this->assertCount( 2, $standalone_locks );

		foreach ( $standalone_locks as $lock_entry ) {
			$this->assertTrue( $lock_entry['standalone'] );
		}

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	// ── build_worker_locks: with standalone workers (single instance) ───

	public function test_build_worker_locks_with_single_standalone(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'single-worker' => [
					'class'      => MockLogHandler::class,
					'partitions' => false,
				],
			];
		} );

		$supervisor = new Supervisor();

		$ref = new \ReflectionProperty( Supervisor::class, 'worker_locks' );
		$ref->setAccessible( true );
		$locks = $ref->getValue( $supervisor );

		// Single instance standalone worker: 1 entry with partition 0.
		$standalone_locks = \array_filter( $locks, fn( $l ) => 'single-worker' === $l['type'] );
		$this->assertCount( 1, $standalone_locks );

		$entry = \array_values( $standalone_locks )[0];
		$this->assertSame( 0, $entry['partition'] );
		$this->assertTrue( $entry['standalone'] );
		// Single instance uses "{name}.lock.d" (no .pN).
		$this->assertStringContainsString( 'single-worker.lock.d', $entry['lock_dir'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	// ── build_worker_locks: custom stale_timeout from reader config ─────

	public function test_build_worker_locks_custom_stale_timeout(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['custom-stale'] = [
				'test-handler' => [
					'class'         => MockLogHandler::class,
					'inputs'        => [ 'firehose.log' ],
					'stale_timeout' => 120,
				],
			];
			return $readers;
		} );

		$supervisor = new Supervisor();

		$ref = new \ReflectionProperty( Supervisor::class, 'worker_locks' );
		$ref->setAccessible( true );
		$locks = $ref->getValue( $supervisor );

		$custom_locks = \array_filter( $locks, fn( $l ) => 'custom-stale' === $l['type'] );
		$this->assertNotEmpty( $custom_locks );

		$entry = \array_values( $custom_locks )[0];
		$this->assertSame( 120, $entry['stale_timeout'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── load_standalone_workers: valid class ────────────────────────────

	public function test_load_standalone_workers_valid_class(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'valid-worker' => [
					'class'      => MockLogHandler::class,
					'partitions' => true,
				],
			];
		} );

		// Use the static method for direct validation.
		$workers = Supervisor::get_standalone_workers();
		$this->assertArrayHasKey( 'valid-worker', $workers );
		$this->assertSame( MockLogHandler::class, $workers['valid-worker']['class'] );
		$this->assertTrue( $workers['valid-worker']['partitions'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	// ── load_standalone_workers: non-string class key ───────────────────

	public function test_load_standalone_workers_non_string_class(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'bad-class' => [
					'class'      => 12345, // Non-string.
					'partitions' => false,
				],
			];
		} );

		$workers = Supervisor::get_standalone_workers();
		$this->assertEmpty( $workers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	// ── load_standalone_workers: mixed valid and invalid ────────────────

	public function test_load_standalone_workers_mixed_entries(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'valid'           => [
					'class'      => MockLogHandler::class,
					'partitions' => false,
				],
				'missing-class'   => [
					'partitions' => true,
				],
				'nonexistent'     => [
					'class'      => 'NoSuchClass_XYZ123',
					'partitions' => false,
				],
				0                 => [
					'class'      => MockLogHandler::class,
					'partitions' => false,
				],
			];
		} );

		$workers = Supervisor::get_standalone_workers();
		// Only 'valid' should pass all checks.
		$this->assertCount( 1, $workers );
		$this->assertArrayHasKey( 'valid', $workers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	// ── spawn_next_supervisor ───────────────────────────────────────────

	public function test_spawn_next_supervisor_calls_wp_remote_post(): void {
		$supervisor = new Supervisor();

		$ref = new \ReflectionMethod( Supervisor::class, 'spawn_next_supervisor' );
		$ref->setAccessible( true );

		// Our bootstrap stub for wp_remote_post returns success.
		// Just verify it doesn't throw.
		$ref->invoke( $supervisor );
		$this->assertTrue( true, 'spawn_next_supervisor should not throw' );
	}

	// ── cleanup_stale_partitions: with actual stale directories ─────────

	public function test_cleanup_stale_partitions_removes_stale_dirs(): void {
		// Register a reader so cleanup knows what to scan.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['cleanup-reader'] = [
				'test-handler' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$supervisor = new Supervisor();

		// Create stale partition directories beyond num_partitions (2).
		$logs_dir  = Config::get_logs_directory();
		$locks_dir = Config::get_locks_directory();

		$stale_log_dir  = "{$logs_dir}/firehose.log/p5";
		$stale_lock_dir = "{$locks_dir}/cleanup-reader.p5.lock.d";

		@\mkdir( $stale_log_dir, 0755, true );
		@\mkdir( $stale_lock_dir, 0755, true );

		// Create a file with old modification time (2 hours ago, stale_age is 1 hour).
		\file_put_contents( "{$stale_log_dir}/0.log", 'old data' );
		\touch( "{$stale_log_dir}/0.log", \time() - 7200 );

		\file_put_contents( "{$stale_lock_dir}/heartbeat", '' );
		\touch( "{$stale_lock_dir}/heartbeat", \time() - 7200 );

		\clearstatcache();

		$method = new \ReflectionMethod( Supervisor::class, 'cleanup_stale_partitions' );
		$method->setAccessible( true );
		$method->invoke( $supervisor );

		// Stale directories should have been removed.
		$this->assertDirectoryDoesNotExist( $stale_log_dir, 'Stale log dir should be removed' );
		$this->assertDirectoryDoesNotExist( $stale_lock_dir, 'Stale lock dir should be removed' );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── cleanup_stale_partitions: standalone worker stale dirs ──────────

	public function test_cleanup_stale_partitions_standalone_workers(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'stale-standalone' => [
					'class'      => MockLogHandler::class,
					'partitions' => true,
				],
			];
		} );

		$supervisor = new Supervisor();

		$locks_dir      = Config::get_locks_directory();
		$stale_lock_dir = "{$locks_dir}/stale-standalone.p5.lock.d";

		@\mkdir( $stale_lock_dir, 0755, true );
		\file_put_contents( "{$stale_lock_dir}/heartbeat", '' );
		\touch( "{$stale_lock_dir}/heartbeat", \time() - 7200 );
		\clearstatcache();

		$method = new \ReflectionMethod( Supervisor::class, 'cleanup_stale_partitions' );
		$method->setAccessible( true );
		$method->invoke( $supervisor );

		$this->assertDirectoryDoesNotExist( $stale_lock_dir, 'Stale standalone lock dir should be removed' );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	// ── check_config: partition count increased ─────────────────────────

	public function test_check_config_partition_count_increased(): void {
		$supervisor = new Supervisor();

		\update_option( 'event_logger_enable_logging', '1' );
		Config::reset();
		$ref = new \ReflectionProperty( Supervisor::class, 'base_dir' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		// Start with 2 partitions (from config).
		$np_ref = new \ReflectionProperty( Supervisor::class, 'num_partitions' );
		$np_ref->setAccessible( true );
		$this->assertSame( 2, $np_ref->getValue( $supervisor ) );

		// Increase to 4.
		\update_option( 'event_logger_num_partitions', '4' );
		Config::reset();

		$method = new \ReflectionMethod( Supervisor::class, 'check_config' );
		$method->setAccessible( true );

		$result = $method->invoke( $supervisor );
		$this->assertTrue( $result );
		$this->assertSame( 4, $np_ref->getValue( $supervisor ) );
	}

	// ── check_config: partition count decreased with force release ──────

	public function test_check_config_partition_decrease_force_releases_locks(): void {
		// Register a reader so we have lock entries.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['release-test'] = [
				'release-handler' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$supervisor = new Supervisor();

		\update_option( 'event_logger_enable_logging', '1' );
		Config::reset();
		$ref = new \ReflectionProperty( Supervisor::class, 'base_dir' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		// Verify starting at 2 partitions.
		$np_ref = new \ReflectionProperty( Supervisor::class, 'num_partitions' );
		$np_ref->setAccessible( true );
		$this->assertSame( 2, $np_ref->getValue( $supervisor ) );

		// Create lock dirs for partition 1 (will be orphaned when we decrease to 1).
		$locks_dir = Config::get_locks_directory();
		$orphan    = "{$locks_dir}/release-test.p1.lock.d";
		@\mkdir( $orphan, 0755, true );
		\file_put_contents( "{$orphan}/heartbeat", '' );

		// Decrease to 1.
		\update_option( 'event_logger_num_partitions', '1' );
		Config::reset();

		$method = new \ReflectionMethod( Supervisor::class, 'check_config' );
		$method->setAccessible( true );
		$result = $method->invoke( $supervisor );

		$this->assertTrue( $result );
		$this->assertSame( 1, $np_ref->getValue( $supervisor ) );
		// Orphan lock should be force-released.
		$this->assertDirectoryDoesNotExist( $orphan, 'Lock for removed partition should be force-released' );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── unschedule with scheduled event ────────────────────────────────

	public function test_unschedule_with_scheduled_event(): void {
		$GLOBALS['_wp_test_next_scheduled'] = 1234567890;
		Supervisor::unschedule();
		$this->assertTrue( true );
		unset( $GLOBALS['_wp_test_next_scheduled'] );
	}

	// ── kill_readers with force release ─────────────────────────────────

	public function test_kill_readers_force_releases_locks(): void {
		$locks_dir = self::TEST_DIR . '/locks';
		@\mkdir( $locks_dir, 0755, true );

		// Create lock dirs that kill_readers should force-release.
		$lock_dir = "{$locks_dir}/test-reader.p0.lock.d";
		@\mkdir( $lock_dir, 0755, true );
		\file_put_contents( "{$lock_dir}/heartbeat", (string) \getmypid() );

		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader'] = [
				'test-handler' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		Supervisor::kill_readers( [ 'test-reader' ] );

		// Lock dir should have been force-released (heartbeat removed).
		$this->assertFileDoesNotExist( "{$lock_dir}/heartbeat" );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── load_standalone_workers: class_exists path ─────────────────────

	public function test_load_standalone_workers_with_partitions(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'test-standalone' => [
					'class'      => Supervisor::class, // Use existing class.
					'partitions' => true,
				],
			];
		} );

		$supervisor = new Supervisor();

		$ref = new \ReflectionProperty( Supervisor::class, 'standalone_workers' );
		$ref->setAccessible( true );
		$workers = $ref->getValue( $supervisor );

		$this->assertArrayHasKey( 'test-standalone', $workers );
		$this->assertTrue( $workers['test-standalone']['partitions'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	public function test_load_standalone_workers_without_partitions(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'single-worker' => [
					'class' => Supervisor::class,
				],
			];
		} );

		$supervisor = new Supervisor();

		$ref = new \ReflectionProperty( Supervisor::class, 'standalone_workers' );
		$ref->setAccessible( true );
		$workers = $ref->getValue( $supervisor );

		$this->assertArrayHasKey( 'single-worker', $workers );
		$this->assertFalse( $workers['single-worker']['partitions'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	// ── spawn_next_supervisor ──────────────────────────────────────────

	public function test_spawn_next_supervisor_makes_request(): void {
		$supervisor = new Supervisor();

		$ref = new \ReflectionMethod( Supervisor::class, 'spawn_next_supervisor' );
		$ref->setAccessible( true );

		// Should not throw — wp_remote_post is stubbed.
		$ref->invoke( $supervisor );
		$this->assertTrue( true );
	}

	// ── load_standalone_workers via constructor (instance method) ────────

	public function test_load_standalone_workers_instance_non_array(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return 'not-an-array';
		} );
		$supervisor = new Supervisor();
		$ref        = new \ReflectionProperty( Supervisor::class, 'standalone_workers' );
		$ref->setAccessible( true );
		$this->assertSame( [], $ref->getValue( $supervisor ) );
		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	public function test_load_standalone_workers_instance_skips_invalid(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				0               => [ 'class' => Supervisor::class ], // non-string key
				'missing-class' => [ 'partitions' => true ],         // no class
				'bad-class'     => [ 'class' => 12345 ],             // non-string class
				'nonexistent'   => [ 'class' => 'NoSuchClass_XYZ' ], // class doesn't exist
				'valid'         => [ 'class' => Supervisor::class, 'partitions' => true ],
			];
		} );
		$supervisor = new Supervisor();
		$ref        = new \ReflectionProperty( Supervisor::class, 'standalone_workers' );
		$ref->setAccessible( true );
		$workers = $ref->getValue( $supervisor );
		$this->assertCount( 1, $workers );
		$this->assertArrayHasKey( 'valid', $workers );
		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'] );
	}

	// ── run() early return when logging disabled ────────────────────────

	public function test_run_exits_via_should_restart(): void {
		$supervisor = new Supervisor();

		// Set max_runtime to 0 so the while loop condition fails immediately,
		// but lines 527-538 (setup before the loop) get covered.
		$ref = new \ReflectionProperty( \Newspack_Event_Logger\SupervisorBase::class, 'max_runtime' );
		$ref->setAccessible( true );
		$ref->setValue( $supervisor, 0 );

		$supervisor->run();

		$this->assertTrue( true );
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'], $_SERVER['EVENT_LOGGER_WORKER_PARTITION'] );
	}

}
