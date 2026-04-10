<?php
/**
 * Tests for WorkerCommand (CLI worker management).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\CLI\WorkerCommand;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Lock;

/**
 * Mock standalone worker for testing WorkerCommand::run() standalone path.
 */
class MockStandaloneWorker {

	/** @var bool Whether run() was called. */
	public static bool $ran = false;

	/** @var int Partition passed to constructor. */
	public static int $partition = -1;

	/**
	 * Constructor.
	 *
	 * @param int $partition Partition number.
	 */
	public function __construct( int $partition ) {
		self::$partition = $partition;
	}

	/**
	 * Run the worker.
	 */
	public function run(): void {
		self::$ran = true;
	}

	/**
	 * Reset tracking state.
	 */
	public static function reset(): void {
		self::$ran       = false;
		self::$partition = -1;
	}
}

#[\PHPUnit\Framework\Attributes\CoversClass( WorkerCommand::class )]
class WorkerCommandTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-worker-cmd';

	/** @var WorkerCommand */
	private WorkerCommand $cmd;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $format_bytes;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $format_duration;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $calculate_behind;

	protected function setUp(): void {
		parent::setUp();
		$this->cmd = new WorkerCommand();

		$this->format_bytes = new \ReflectionMethod( $this->cmd, 'format_bytes' );
		$this->format_bytes->setAccessible( true );

		$this->format_duration = new \ReflectionMethod( $this->cmd, 'format_duration' );
		$this->format_duration->setAccessible( true );

		$this->calculate_behind = new \ReflectionMethod( $this->cmd, 'calculate_behind' );
		$this->calculate_behind->setAccessible( true );

		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR, 0755, true );
	}

	protected function tearDown(): void {
		self::rmdir_recursive( self::TEST_DIR );
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

	// ── format_bytes ────────────────────────────────────────────────────

	public function test_format_bytes_zero(): void {
		$this->assertSame( '0B', $this->format_bytes->invoke( $this->cmd, 0 ) );
	}

	public function test_format_bytes_bytes(): void {
		$this->assertSame( '512B', $this->format_bytes->invoke( $this->cmd, 512 ) );
	}

	public function test_format_bytes_kilobytes(): void {
		$this->assertSame( '1.5KB', $this->format_bytes->invoke( $this->cmd, 1536 ) );
	}

	public function test_format_bytes_megabytes(): void {
		$this->assertSame( '10MB', $this->format_bytes->invoke( $this->cmd, 10 * 1024 * 1024 ) );
	}

	public function test_format_bytes_gigabytes(): void {
		$this->assertSame( '2.5GB', $this->format_bytes->invoke( $this->cmd, (int) ( 2.5 * 1024 * 1024 * 1024 ) ) );
	}

	public function test_format_bytes_boundary_kb(): void {
		$this->assertSame( '1KB', $this->format_bytes->invoke( $this->cmd, 1024 ) );
	}

	public function test_format_bytes_boundary_mb(): void {
		$this->assertSame( '1MB', $this->format_bytes->invoke( $this->cmd, 1024 * 1024 ) );
	}

	public function test_format_bytes_just_under_kb(): void {
		$this->assertSame( '1023B', $this->format_bytes->invoke( $this->cmd, 1023 ) );
	}

	// ── format_duration ─────────────────────────────────────────────────

	public function test_format_duration_seconds(): void {
		$this->assertSame( '45s', $this->format_duration->invoke( $this->cmd, 45 ) );
	}

	public function test_format_duration_minutes(): void {
		$this->assertSame( '5m', $this->format_duration->invoke( $this->cmd, 300 ) );
	}

	public function test_format_duration_hours(): void {
		$this->assertSame( '2h', $this->format_duration->invoke( $this->cmd, 7200 ) );
	}

	public function test_format_duration_days(): void {
		$this->assertSame( '3d', $this->format_duration->invoke( $this->cmd, 259200 ) );
	}

	public function test_format_duration_zero(): void {
		$this->assertSame( '0s', $this->format_duration->invoke( $this->cmd, 0 ) );
	}

	public function test_format_duration_boundary_minute(): void {
		$this->assertSame( '59s', $this->format_duration->invoke( $this->cmd, 59 ) );
		$this->assertSame( '1m', $this->format_duration->invoke( $this->cmd, 60 ) );
	}

	public function test_format_duration_boundary_hour(): void {
		$this->assertSame( '59m', $this->format_duration->invoke( $this->cmd, 3599 ) );
		$this->assertSame( '1h', $this->format_duration->invoke( $this->cmd, 3600 ) );
	}

	public function test_format_duration_boundary_day(): void {
		$this->assertSame( '23h', $this->format_duration->invoke( $this->cmd, 86399 ) );
		$this->assertSame( '1d', $this->format_duration->invoke( $this->cmd, 86400 ) );
	}

	// ── calculate_behind ────────────────────────────────────────────────

	public function test_calculate_behind_no_dir(): void {
		$result = $this->calculate_behind->invoke( $this->cmd, '/nonexistent', 0, 0 );
		$this->assertSame( 0, $result );
	}

	public function test_calculate_behind_empty_dir(): void {
		$dir = self::TEST_DIR . '/empty';
		@\mkdir( $dir, 0755, true );

		$result = $this->calculate_behind->invoke( $this->cmd, $dir, 0, 0 );
		$this->assertSame( 0, $result );
	}

	public function test_calculate_behind_single_segment_at_start(): void {
		$dir = self::TEST_DIR . '/single';
		@\mkdir( $dir, 0755, true );
		\file_put_contents( "{$dir}/0.log", \str_repeat( 'x', 1000 ) );

		$result = $this->calculate_behind->invoke( $this->cmd, $dir, 0, 0 );
		$this->assertSame( 1000, $result );
	}

	public function test_calculate_behind_single_segment_partial(): void {
		$dir = self::TEST_DIR . '/partial';
		@\mkdir( $dir, 0755, true );
		\file_put_contents( "{$dir}/0.log", \str_repeat( 'x', 1000 ) );

		$result = $this->calculate_behind->invoke( $this->cmd, $dir, 0, 400 );
		$this->assertSame( 600, $result );
	}

	public function test_calculate_behind_single_segment_caught_up(): void {
		$dir = self::TEST_DIR . '/caught-up';
		@\mkdir( $dir, 0755, true );
		\file_put_contents( "{$dir}/0.log", \str_repeat( 'x', 1000 ) );

		$result = $this->calculate_behind->invoke( $this->cmd, $dir, 0, 1000 );
		$this->assertSame( 0, $result );
	}

	public function test_calculate_behind_multiple_segments(): void {
		$dir = self::TEST_DIR . '/multi';
		@\mkdir( $dir, 0755, true );
		\file_put_contents( "{$dir}/0.log", \str_repeat( 'x', 500 ) );
		\file_put_contents( "{$dir}/1.log", \str_repeat( 'x', 800 ) );
		\file_put_contents( "{$dir}/2.log", \str_repeat( 'x', 300 ) );

		// Cursor at segment 0, offset 200 — behind = (500-200) + 800 + 300 = 1400.
		$result = $this->calculate_behind->invoke( $this->cmd, $dir, 0, 200 );
		$this->assertSame( 1400, $result );
	}

	public function test_calculate_behind_cursor_in_middle_segment(): void {
		$dir = self::TEST_DIR . '/middle';
		@\mkdir( $dir, 0755, true );
		\file_put_contents( "{$dir}/0.log", \str_repeat( 'x', 500 ) );
		\file_put_contents( "{$dir}/1.log", \str_repeat( 'x', 800 ) );
		\file_put_contents( "{$dir}/2.log", \str_repeat( 'x', 300 ) );

		// Cursor at segment 1, offset 100 — behind = (800-100) + 300 = 1000.
		$result = $this->calculate_behind->invoke( $this->cmd, $dir, 1, 100 );
		$this->assertSame( 1000, $result );
	}

	public function test_calculate_behind_ignores_non_log_files(): void {
		$dir = self::TEST_DIR . '/mixed';
		@\mkdir( $dir, 0755, true );
		\file_put_contents( "{$dir}/0.log", \str_repeat( 'x', 500 ) );
		\file_put_contents( "{$dir}/0.idx", \str_repeat( 'y', 200 ) );
		\file_put_contents( "{$dir}/notes.txt", 'hello' );

		$result = $this->calculate_behind->invoke( $this->cmd, $dir, 0, 0 );
		$this->assertSame( 500, $result );
	}

	public function test_calculate_behind_cursor_past_end(): void {
		$dir = self::TEST_DIR . '/past-end';
		@\mkdir( $dir, 0755, true );
		\file_put_contents( "{$dir}/0.log", \str_repeat( 'x', 500 ) );

		// Cursor past end of segment — 0 behind for this segment.
		$result = $this->calculate_behind->invoke( $this->cmd, $dir, 0, 600 );
		$this->assertSame( 0, $result );
	}

	// ── list_types ──────────────────────────────────────────────────────

	public function test_list_types_with_readers(): void {
		$list_types = new \ReflectionMethod( $this->cmd, 'list_types' );
		$list_types->setAccessible( true );

		\WP_CLI::reset();
		$list_types->invoke( $this->cmd, [
			'firehose-workers' => [
				'inputs'   => [ 'firehose.log' ],
				'handlers' => [ 'RequestBuilder' => [], 'JobRouter' => [] ],
			],
		], [] );

		$found = false;
		foreach ( \WP_CLI::$log as $entry ) {
			if ( \str_contains( $entry['message'], 'firehose-workers' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should log reader group name' );
	}

	public function test_list_types_with_standalone(): void {
		$list_types = new \ReflectionMethod( $this->cmd, 'list_types' );
		$list_types->setAccessible( true );

		\WP_CLI::reset();
		$list_types->invoke( $this->cmd, [], [
			'stream-merger' => [ 'partitions' => true ],
		] );

		$found = false;
		foreach ( \WP_CLI::$log as $entry ) {
			if ( \str_contains( $entry['message'], 'stream-merger' ) && \str_contains( $entry['message'], 'partitioned' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should log standalone worker name with partition type' );
	}

	public function test_list_types_single_standalone(): void {
		$list_types = new \ReflectionMethod( $this->cmd, 'list_types' );
		$list_types->setAccessible( true );

		\WP_CLI::reset();
		$list_types->invoke( $this->cmd, [], [
			'my-worker' => [ 'partitions' => false ],
		] );

		$found = false;
		foreach ( \WP_CLI::$log as $entry ) {
			if ( \str_contains( $entry['message'], 'single' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Non-partitioned worker should show as single' );
	}

	public function test_list_types_empty(): void {
		$list_types = new \ReflectionMethod( $this->cmd, 'list_types' );
		$list_types->setAccessible( true );

		\WP_CLI::reset();
		$list_types->invoke( $this->cmd, [], [] );

		$warnings = \array_filter( \WP_CLI::$log, fn( $e ) => 'warning' === $e['level'] );
		$this->assertNotEmpty( $warnings, 'Should warn when no types registered' );
	}

	// ── run: validation ─────────────────────────────────────────────────

	public function test_run_rejects_empty_type(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'type required' );
		$this->cmd->run( [], [] );
	}

	public function test_run_rejects_invalid_type(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid worker type' );
		$this->cmd->run( [ 'nonexistent-worker' ], [] );
	}

	// ── restart: validation ─────────────────────────────────────────────

	public function test_restart_rejects_empty_type(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'type required' );
		$this->cmd->restart( [], [] );
	}

	public function test_restart_rejects_invalid_type(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid worker type' );
		$this->cmd->restart( [ 'nonexistent-worker' ], [] );
	}

	// ── list_workers ────────────────────────────────────────────────────

	public function test_list_workers_empty(): void {
		$list_workers = new \ReflectionMethod( $this->cmd, 'list_workers' );
		$list_workers->setAccessible( true );

		\WP_CLI::reset();
		$list_workers->invoke( $this->cmd, [], [] );

		$warnings = \array_filter( \WP_CLI::$log, fn( $e ) => 'warning' === $e['level'] );
		$this->assertNotEmpty( $warnings, 'Should warn when no workers registered' );
	}

	public function test_list_workers_with_reader(): void {
		// Set up filesystem fixtures.
		$log_base   = self::TEST_DIR . '/logs';
		$locks_base = self::TEST_DIR . '/locks';
		@\mkdir( "{$log_base}/firehose.log/p0", 0755, true );
		@\mkdir( $locks_base, 0755, true );
		\file_put_contents( "{$log_base}/firehose.log/p0/0.log", \str_repeat( 'x', 500 ) );

		// Point Config to our test dir.
		$config_file = self::TEST_DIR . '/config.php';
		\file_put_contents( $config_file, '<?php return [
			"base_directory" => "' . self::TEST_DIR . '",
			"num_partitions" => 1,
			"enable_logging" => true,
		];' );
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $config_file );
		\Newspack_Event_Logger\Config::reset();

		$list_workers = new \ReflectionMethod( $this->cmd, 'list_workers' );
		$list_workers->setAccessible( true );

		\WP_CLI::reset();
		$list_workers->invoke( $this->cmd, [
			'firehose-workers' => [
				'inputs'   => [ 'firehose.log' ],
				'handlers' => [ 'RequestBuilder' => [] ],
			],
		], [] );

		// format_items was called with the rows.
		$this->assertNotEmpty( $GLOBALS['_wp_test_cli_format_items'] ?? [] );
		$items = $GLOBALS['_wp_test_cli_format_items']['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'firehose-workers', $items[0]['Type'] );
		$this->assertSame( 'dead', $items[0]['Status'] );

		// Restore.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		\Newspack_Event_Logger\Config::reset();
	}

	public function test_list_workers_with_reader_shows_status(): void {
		$list_workers = new \ReflectionMethod( $this->cmd, 'list_workers' );
		$list_workers->setAccessible( true );

		\WP_CLI::reset();
		$list_workers->invoke( $this->cmd, [
			'test-reader' => [
				'inputs'   => [ 'firehose.log' ],
				'handlers' => [ 'Handler' => [] ],
			],
		], [] );

		// format_items was called.
		$this->assertNotEmpty( $GLOBALS['_wp_test_cli_format_items'] ?? [] );
		$items = $GLOBALS['_wp_test_cli_format_items']['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'test-reader', $items[0]['Type'] );
		$this->assertSame( 0, $items[0]['Partition'] );
		$this->assertArrayHasKey( 'Status', $items[0] );
		$this->assertArrayHasKey( 'Behind', $items[0] );
	}

	public function test_list_workers_with_standalone(): void {
		$list_workers = new \ReflectionMethod( $this->cmd, 'list_workers' );
		$list_workers->setAccessible( true );

		\WP_CLI::reset();
		$list_workers->invoke( $this->cmd, [], [
			'my-worker' => [
				'partitions'    => false,
				'stale_timeout' => 60,
			],
		] );

		$items = $GLOBALS['_wp_test_cli_format_items']['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'my-worker', $items[0]['Type'] );
		$this->assertSame( '-', $items[0]['Partition'] );
		$this->assertSame( '-', $items[0]['Behind'] );
	}

	// ── run: more validation paths ──────────────────────────────────────

	public function test_run_warns_when_logging_disabled(): void {
		// Disable logging via WP option (same pattern as other tests).
		$GLOBALS['_wp_test_options']['event_logger_enable_logging'] = '0';
		\Newspack_Event_Logger\Config::reset();

		// Register a reader so the type is valid.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader'] = [
				'test' => [
					'class'  => \Newspack_Event_Logger\Tests\Unit\MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		\WP_CLI::reset();
		$this->cmd->run( [ 'test-reader' ], [] );

		$warnings = \array_filter( \WP_CLI::$log, fn( $e ) => 'warning' === $e['level'] );
		$this->assertNotEmpty( $warnings, 'Should warn when logging disabled' );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'], $GLOBALS['_wp_test_options']['event_logger_enable_logging'] );
		\Newspack_Event_Logger\Config::reset();
	}

	public function test_run_quiet_mode_suppresses_output(): void {
		// Disable logging so run() returns early without actually running a worker.
		$GLOBALS['_wp_test_options']['event_logger_enable_logging'] = '0';
		Config::reset();

		// Register a reader so the type is valid.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader'] = [
				'test' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		\WP_CLI::reset();
		$this->cmd->run( [ 'test-reader' ], [ 'quiet' => true ] );

		// Quiet mode should produce no warnings.
		$this->assertEmpty( \WP_CLI::$log, 'Quiet mode should suppress all output' );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'], $GLOBALS['_wp_test_options']['event_logger_enable_logging'] );
		Config::reset();
	}

	// ── list_cmd / types via filters ────────────────────────────────────

	public function test_list_cmd_uses_filters(): void {
		// Register mock readers and standalone workers via filters.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-group'] = [
				'handler1' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		\add_filter( 'newspack_event_logger_standalone_workers', function ( $workers ) {
			$workers['test-standalone'] = [
				'class'      => MockStandaloneWorker::class,
				'partitions' => false,
			];
			return $workers;
		} );

		\WP_CLI::reset();
		$this->cmd->list_cmd( [], [] );

		// format_items should have been called with rows.
		$this->assertNotEmpty( $GLOBALS['_wp_test_cli_format_items'] ?? [], 'list_cmd should call format_items' );

		unset(
			$GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'],
			$GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers']
		);
	}

	public function test_types_uses_filters(): void {
		// Register mock readers and standalone workers via filters.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['typed-group'] = [
				'handler1' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		\add_filter( 'newspack_event_logger_standalone_workers', function ( $workers ) {
			$workers['typed-standalone'] = [
				'class'      => MockStandaloneWorker::class,
				'partitions' => false,
			];
			return $workers;
		} );

		\WP_CLI::reset();
		$this->cmd->types( [], [] );

		$found_group      = false;
		$found_standalone = false;
		foreach ( \WP_CLI::$log as $entry ) {
			if ( \str_contains( $entry['message'], 'typed-group' ) ) {
				$found_group = true;
			}
			if ( \str_contains( $entry['message'], 'typed-standalone' ) ) {
				$found_standalone = true;
			}
		}
		$this->assertTrue( $found_group, 'types() should show reader groups from filter' );
		$this->assertTrue( $found_standalone, 'types() should show standalone workers from filter' );

		unset(
			$GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'],
			$GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers']
		);
	}

	// ── run: invalid partition ──────────────────────────────────────────

	public function test_run_rejects_invalid_partition(): void {
		// Register a reader so the type is valid.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader'] = [
				'test' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid partition' );

		try {
			$this->cmd->run( [ 'test-reader' ], [ 'partition' => '5' ] );
		} finally {
			unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
		}
	}

	// ── run: standalone success path ────────────────────────────────────

	public function test_run_standalone_worker(): void {
		// Enable logging so run() proceeds past the check.
		$GLOBALS['_wp_test_options']['event_logger_enable_logging'] = '1';
		Config::reset();

		MockStandaloneWorker::reset();

		\add_filter( 'newspack_event_logger_standalone_workers', function ( $workers ) {
			$workers['mock-worker'] = [
				'class'      => MockStandaloneWorker::class,
				'partitions' => false,
			];
			return $workers;
		} );

		\WP_CLI::reset();
		$this->cmd->run( [ 'mock-worker' ], [] );

		$this->assertTrue( MockStandaloneWorker::$ran, 'Standalone worker run() should be called' );
		$this->assertSame( 0, MockStandaloneWorker::$partition, 'Default partition should be 0' );

		// Check success message.
		$successes = \array_filter( \WP_CLI::$log, fn( $e ) => 'success' === $e['level'] );
		$this->assertNotEmpty( $successes, 'Should log success message' );

		unset(
			$GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'],
			$GLOBALS['_wp_test_options']['event_logger_enable_logging']
		);
		Config::reset();
	}

	public function test_run_standalone_worker_quiet(): void {
		$GLOBALS['_wp_test_options']['event_logger_enable_logging'] = '1';
		Config::reset();

		MockStandaloneWorker::reset();

		\add_filter( 'newspack_event_logger_standalone_workers', function ( $workers ) {
			$workers['mock-worker'] = [
				'class'      => MockStandaloneWorker::class,
				'partitions' => false,
			];
			return $workers;
		} );

		\WP_CLI::reset();
		$this->cmd->run( [ 'mock-worker' ], [ 'quiet' => true ] );

		$this->assertTrue( MockStandaloneWorker::$ran, 'Worker should still run in quiet mode' );
		$this->assertEmpty( \WP_CLI::$log, 'Quiet mode should suppress all output' );

		unset(
			$GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'],
			$GLOBALS['_wp_test_options']['event_logger_enable_logging']
		);
		Config::reset();
	}

	// ── restart: full flow ──────────────────────────────────────────────

	public function test_restart_all_workers_all_partitions(): void {
		$locks_base = Config::get_locks_directory();

		// Create lock directories for the workers.
		$reader_lock = "{$locks_base}/test-reader.p0.lock.d";
		$standalone_lock = "{$locks_base}/mock-standalone.p0.lock.d";
		@\mkdir( $reader_lock, 0755, true );
		@\mkdir( $standalone_lock, 0755, true );

		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader'] = [
				'handler1' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		\add_filter( 'newspack_event_logger_standalone_workers', function ( $workers ) {
			$workers['mock-standalone'] = [
				'class'      => MockStandaloneWorker::class,
				'partitions' => false,
			];
			return $workers;
		} );

		\WP_CLI::reset();
		$this->cmd->restart( [ 'all' ], [ 'all-partitions' => true ] );

		// Both should have restart files.
		$this->assertFileExists( "{$reader_lock}/restart", 'Reader lock dir should have restart file' );
		$this->assertFileExists( "{$standalone_lock}/restart", 'Standalone lock dir should have restart file' );

		// Check success message.
		$successes = \array_filter( \WP_CLI::$log, fn( $e ) => 'success' === $e['level'] );
		$this->assertNotEmpty( $successes, 'Should log success message' );

		// Clean up.
		unset(
			$GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'],
			$GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers']
		);
	}

	public function test_restart_specific_reader_with_partition(): void {
		$locks_base = Config::get_locks_directory();

		$lock_dir = "{$locks_base}/test-reader.p0.lock.d";
		@\mkdir( $lock_dir, 0755, true );

		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader'] = [
				'handler1' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		\WP_CLI::reset();
		$this->cmd->restart( [ 'test-reader' ], [ 'partition' => '0' ] );

		$this->assertFileExists( "{$lock_dir}/restart" );

		$successes = \array_filter( \WP_CLI::$log, fn( $e ) => 'success' === $e['level'] );
		$this->assertNotEmpty( $successes );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── restart: invalid partition ──────────────────────────────────────

	public function test_restart_rejects_missing_partition(): void {
		// Register a reader so the type is valid.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader'] = [
				'handler1' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid partition' );

		try {
			// No --partition and no --all-partitions => partition defaults to -1 which is invalid.
			$this->cmd->restart( [ 'test-reader' ], [] );
		} finally {
			unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
		}
	}

	public function test_restart_warns_on_missing_lock_dir(): void {
		// Use a unique reader name that won't have a lock dir from other tests.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['nonexistent-unique-reader-xyz'] = [
				'handler1' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		// Ensure the lock dir does NOT exist.
		$locks_dir = Config::get_locks_directory();
		$lock_dir  = "{$locks_dir}/nonexistent-unique-reader-xyz.p0.lock.d";
		if ( \is_dir( $lock_dir ) ) {
			@\unlink( "{$lock_dir}/heartbeat" );
			@\unlink( "{$lock_dir}/restart" );
			@\rmdir( $lock_dir );
		}

		\WP_CLI::reset();
		$this->cmd->restart( [ 'nonexistent-unique-reader-xyz' ], [ 'partition' => '0' ] );

		$warnings = \array_filter( \WP_CLI::$log, fn( $e ) => 'warning' === $e['level'] );
		$this->assertNotEmpty( $warnings, 'Should warn when lock dir does not exist' );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_restart_standalone_skips_partition_gt0_for_single_instance(): void {
		// Use 2-partition config via WP option.
		$GLOBALS['_wp_test_options']['event_logger_num_partitions'] = '2';
		Config::reset();

		$locks_base = Config::get_locks_directory();

		// Only create p0 lock dir — the non-partitioned worker should skip p1+.
		$lock_dir = "{$locks_base}/single-worker.p0.lock.d";
		@\mkdir( $lock_dir, 0755, true );

		\add_filter( 'newspack_event_logger_standalone_workers', function ( $workers ) {
			$workers['single-worker'] = [
				'class'      => MockStandaloneWorker::class,
				'partitions' => false,
			];
			return $workers;
		} );

		\WP_CLI::reset();
		$this->cmd->restart( [ 'single-worker' ], [ 'all-partitions' => true ] );

		// Only partition 0 should have a restart file — p1 should be skipped.
		$this->assertFileExists( "{$lock_dir}/restart" );

		$successes = \array_filter( \WP_CLI::$log, fn( $e ) => 'success' === $e['level'] );
		$success_msg = $successes[ \array_key_first( $successes ) ]['message'] ?? '';
		$this->assertStringContainsString( '1 worker', $success_msg, 'Only 1 worker should be restarted for single-instance standalone' );

		// Restore.
		unset(
			$GLOBALS['_wp_test_options']['event_logger_num_partitions'],
			$GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers']
		);
		Config::reset();
	}
}
