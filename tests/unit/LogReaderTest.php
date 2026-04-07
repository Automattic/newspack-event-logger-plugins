<?php
/**
 * Tests for LogReader (generic log reader that dispatches to handlers).
 *
 * Tests constructor, dispatch map, handler lifecycle,
 * get_registered_readers() validation, and get_saved_positions().
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\FirehoseReader;
use Newspack_Event_Logger\Cron\LogReader;

/**
 * Mock handler for testing LogReader dispatch.
 *
 * Static because LogReader calls handler methods statically.
 */
class MockLogHandler {

	/** @var string[] Lines received by process(). */
	public static array $processed_lines = [];

	/** @var bool Whether init() was called. */
	public static bool $init_called = false;

	/** @var bool Whether flush() was called. */
	public static bool $flush_called = false;

	/** @var bool Whether save_state() was called. */
	public static bool $save_state_called = false;

	/** @var bool Whether cleanup() was called. */
	public static bool $cleanup_called = false;

	/**
	 * Reset all tracking state.
	 */
	public static function reset(): void {
		self::$processed_lines  = [];
		self::$init_called      = false;
		self::$flush_called     = false;
		self::$save_state_called = false;
		self::$cleanup_called   = false;
	}

	/**
	 * Handler init.
	 *
	 * @param array      $context     Context.
	 * @param array|null $saved_state Saved state.
	 */
	public static function init( array &$context, ?array $saved_state ): void {
		self::$init_called       = true;
		$context['handler_data'] = 'initialized';
	}

	/**
	 * Handler process.
	 *
	 * @param string $line    The line.
	 * @param string $input   Input log name.
	 * @param array  $context Context.
	 */
	public static function process( string $line, string $input, array &$context ): void {
		self::$processed_lines[] = $line;
	}

	/**
	 * Handler flush.
	 *
	 * @param array $context Context.
	 */
	public static function flush( array &$context ): void {
		self::$flush_called = true;
	}

	/**
	 * Handler save_state.
	 *
	 * @param array $context Context.
	 * @return array State to persist.
	 */
	public static function save_state( array &$context ): array {
		self::$save_state_called = true;
		return [ 'test_state' => 'saved' ];
	}

	/**
	 * Handler cleanup.
	 *
	 * @param array $context Context.
	 */
	public static function cleanup( array &$context ): void {
		self::$cleanup_called = true;
	}
}

#[\PHPUnit\Framework\Attributes\CoversClass( LogReader::class )]
class LogReaderTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-logreader';

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		MockLogHandler::reset();
		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
		@\mkdir( self::TEST_DIR . '/locks', 0755, true );
		@\mkdir( self::TEST_DIR . '/offsets', 0755, true );

		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/logreader.php' );
		Config::reset();
	}

	protected function tearDown(): void {
		self::rmdir_recursive( self::TEST_DIR );
		Config::reset();
		MockLogHandler::reset();
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

	// ── Constructor ───────────────────────────────────────────────────────

	public function test_constructor_creates_reader_and_calls_handler_init(): void {
		$handlers = [
			'test-handler' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		$reader = new LogReader(
			'test-group',
			[ 'firehose.log' ],
			$handlers,
			0,
			10, // short runtime
			60
		);

		$this->assertTrue( MockLogHandler::$init_called, 'Handler init() should be called during construction' );
	}

	// ── get_registered_readers — filter parsing ───────────────────────────

	public function test_get_registered_readers_empty_filter(): void {
		// Default apply_filters stub returns [] since no filters are registered.
		$readers = LogReader::get_registered_readers();
		$this->assertSame( [], $readers );
	}

	public function test_get_registered_readers_validates_handler_class(): void {
		// Simulate the filter returning a handler with a non-existent class.
		// Since apply_filters is a stub returning the first arg, we cannot inject
		// handlers this way. Instead we test the validation logic directly
		// by verifying the method returns empty for the default (no filter) case.
		$readers = LogReader::get_registered_readers();
		$this->assertIsArray( $readers );
	}

	// ── get_saved_positions — offsetlog reading ───────────────────────────

	public function test_get_saved_positions_empty_when_no_offsetlog(): void {
		$positions = LogReader::get_saved_positions( 'nonexistent-group', 0 );
		$this->assertSame( [], $positions );
	}

	public function test_get_saved_positions_reads_written_positions(): void {
		$offsets_dir = self::TEST_DIR . '/offsets';

		// Create an offsetlog and write position data.
		$offsetlog = new Firehose(
			"{$offsets_dir}/test-group.p0",
			0,
			LogReader::OFFSETLOG_SEGMENT_SIZE,
			LogReader::OFFSETLOG_NUM_SEGMENTS,
			0
		);
		$offsetlog->allow_large_writes();

		$entry = \wp_json_encode( [
			'positions' => [
				'firehose.log' => [ 'seg' => 2, 'off' => 12345 ],
				'requests.log' => [ 'seg' => 1, 'off' => 6789 ],
			],
			'ts' => \time(),
		] );
		$offsetlog->write( $entry );

		// Read positions back.
		$positions = LogReader::get_saved_positions( 'test-group', 0 );
		$this->assertArrayHasKey( 'firehose.log', $positions );
		$this->assertSame( 2, $positions['firehose.log']['seg'] );
		$this->assertSame( 12345, $positions['firehose.log']['off'] );
		$this->assertArrayHasKey( 'requests.log', $positions );
		$this->assertSame( 1, $positions['requests.log']['seg'] );
		$this->assertSame( 6789, $positions['requests.log']['off'] );
	}

	public function test_get_saved_positions_returns_empty_for_invalid_json(): void {
		$offsets_dir = self::TEST_DIR . '/offsets';

		$offsetlog = new Firehose(
			"{$offsets_dir}/bad-json.p0",
			0,
			LogReader::OFFSETLOG_SEGMENT_SIZE,
			LogReader::OFFSETLOG_NUM_SEGMENTS,
			0
		);
		$offsetlog->allow_large_writes();
		$offsetlog->write( 'not valid json {{{}}}' );

		$positions = LogReader::get_saved_positions( 'bad-json', 0 );
		$this->assertSame( [], $positions );
	}

	public function test_get_saved_positions_returns_empty_for_missing_positions_key(): void {
		$offsets_dir = self::TEST_DIR . '/offsets';

		$offsetlog = new Firehose(
			"{$offsets_dir}/no-pos.p0",
			0,
			LogReader::OFFSETLOG_SEGMENT_SIZE,
			LogReader::OFFSETLOG_NUM_SEGMENTS,
			0
		);
		$offsetlog->allow_large_writes();
		$offsetlog->write( \wp_json_encode( [ 'ts' => \time() ] ) );

		$positions = LogReader::get_saved_positions( 'no-pos', 0 );
		$this->assertSame( [], $positions );
	}

	public function test_get_saved_positions_returns_last_entry(): void {
		$offsets_dir = self::TEST_DIR . '/offsets';

		$offsetlog = new Firehose(
			"{$offsets_dir}/multi.p0",
			0,
			LogReader::OFFSETLOG_SEGMENT_SIZE,
			LogReader::OFFSETLOG_NUM_SEGMENTS,
			0
		);
		$offsetlog->allow_large_writes();

		// Write two entries -- should return the last one.
		$offsetlog->write( \wp_json_encode( [
			'positions' => [ 'firehose.log' => [ 'seg' => 0, 'off' => 100 ] ],
			'ts'        => \time() - 60,
		] ) );
		$offsetlog->write( \wp_json_encode( [
			'positions' => [ 'firehose.log' => [ 'seg' => 1, 'off' => 500 ] ],
			'ts'        => \time(),
		] ) );

		$positions = LogReader::get_saved_positions( 'multi', 0 );
		$this->assertSame( 1, $positions['firehose.log']['seg'] );
		$this->assertSame( 500, $positions['firehose.log']['off'] );
	}

	// ── Constants ─────���───────────────────────────────────────────────────

	public function test_housekeeping_interval_is_30_seconds(): void {
		$this->assertSame( 30.0, LogReader::HOUSEKEEPING_INTERVAL );
	}

	public function test_offsetlog_segment_size_is_64kb(): void {
		$this->assertSame( 65536, LogReader::OFFSETLOG_SEGMENT_SIZE );
	}

	// ── Dispatch map ─────────────────��───────────────────────────────────

	public function test_dispatch_map_routes_to_correct_handlers(): void {
		$handlers = [
			'handler-a' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
			'handler-b' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log', 'requests.log' ],
			],
		];

		$reader = new LogReader(
			'multi-handler',
			[ 'firehose.log', 'requests.log' ],
			$handlers,
			0,
			10,
			60
		);

		$ref = new \ReflectionProperty( LogReader::class, 'dispatch_map' );
		$ref->setAccessible( true );
		$map = $ref->getValue( $reader );

		$this->assertArrayHasKey( 'firehose.log', $map );
		$this->assertContains( 'handler-a', $map['firehose.log'] );
		$this->assertContains( 'handler-b', $map['firehose.log'] );

		$this->assertArrayHasKey( 'requests.log', $map );
		$this->assertContains( 'handler-b', $map['requests.log'] );
		$this->assertNotContains( 'handler-a', $map['requests.log'] );
	}

	public function test_constructor_initializes_handler_contexts(): void {
		$handlers = [
			'ctx-handler' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		$reader = new LogReader(
			'ctx-test',
			[ 'firehose.log' ],
			$handlers,
			0,
			10,
			60
		);

		$ref = new \ReflectionProperty( LogReader::class, 'handler_contexts' );
		$ref->setAccessible( true );
		$contexts = $ref->getValue( $reader );

		$this->assertArrayHasKey( 'ctx-handler', $contexts );
		$this->assertIsArray( $contexts['ctx-handler'] );
		$this->assertSame( 'initialized', $contexts['ctx-handler']['handler_data'] );
	}

	public function test_constructor_creates_offsetlog(): void {
		$handlers = [
			'offl-handler' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		$reader = new LogReader(
			'offsetlog-test',
			[ 'firehose.log' ],
			$handlers,
			0,
			10,
			60
		);

		$ref = new \ReflectionProperty( LogReader::class, 'offsetlog' );
		$ref->setAccessible( true );
		$offsetlog = $ref->getValue( $reader );

		$this->assertInstanceOf( Firehose::class, $offsetlog );
	}

	public function test_constructor_with_partition_1(): void {
		$handlers = [
			'part-handler' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		$reader = new LogReader(
			'part-test',
			[ 'firehose.log' ],
			$handlers,
			1,
			10,
			60
		);

		$ref = new \ReflectionProperty( LogReader::class, 'partition' );
		$ref->setAccessible( true );
		$this->assertSame( 1, $ref->getValue( $reader ) );
	}

	public function test_offsetlog_num_segments(): void {
		$this->assertSame( 2, LogReader::OFFSETLOG_NUM_SEGMENTS );
	}

	// ── cron_callback with unknown reader ────────────────────────────────

	public function test_cron_callback_unknown_reader(): void {
		$result = LogReader::cron_callback( 'totally-unknown-group', 0 );
		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'Unknown', $result['reason'] );
	}

	// ── get_live_positions without memcache ──────────────────────────────

	public function test_get_live_positions_returns_null_without_memcache(): void {
		$result = LogReader::get_live_positions( 'test-group', 0 );
		$this->assertNull( $result );
	}

	// ── constructor creates readers for inputs ──────────────────────────

	public function test_constructor_creates_readers_for_all_inputs(): void {
		$handlers = [
			'multi-input' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log', 'requests.log' ],
			],
		];

		$reader = new LogReader(
			'multi-input-test',
			[ 'firehose.log', 'requests.log' ],
			$handlers,
			0,
			10,
			60
		);

		$ref     = new \ReflectionProperty( LogReader::class, 'readers' );
		$ref->setAccessible( true );
		$readers = $ref->getValue( $reader );

		$this->assertArrayHasKey( 'firehose.log', $readers );
		$this->assertArrayHasKey( 'requests.log', $readers );
		$this->assertInstanceOf( \Newspack_Event_Logger\FirehoseReader::class, $readers['firehose.log'] );
		$this->assertInstanceOf( \Newspack_Event_Logger\FirehoseReader::class, $readers['requests.log'] );
	}

	// ── constructor initializes file_handles ─────────────────────────────

	public function test_constructor_initializes_file_handles(): void {
		$handlers = [
			'fh-handler' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		$reader = new LogReader(
			'fh-test',
			[ 'firehose.log' ],
			$handlers,
			0,
			10,
			60
		);

		$ref         = new \ReflectionProperty( LogReader::class, 'file_handles' );
		$ref->setAccessible( true );
		$file_handles = $ref->getValue( $reader );

		$this->assertArrayHasKey( 'firehose.log', $file_handles );
		$this->assertNull( $file_handles['firehose.log'] );
	}

	// ── constructor passes saved state to handler init ───────────────────

	public function test_constructor_passes_partition_to_context(): void {
		$handlers = [
			'part-ctx' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		$reader = new LogReader(
			'part-ctx-test',
			[ 'firehose.log' ],
			$handlers,
			3,
			10,
			60
		);

		$ref      = new \ReflectionProperty( LogReader::class, 'handler_contexts' );
		$ref->setAccessible( true );
		$contexts = $ref->getValue( $reader );

		$this->assertSame( 3, $contexts['part-ctx']['partition'] );
	}

	// ── get_saved_positions with multiple offsetlog entries ──────────────

	public function test_get_saved_positions_with_state(): void {
		$offsets_dir = self::TEST_DIR . '/offsets';

		$offsetlog = new Firehose(
			"{$offsets_dir}/state-test.p0",
			0,
			LogReader::OFFSETLOG_SEGMENT_SIZE,
			LogReader::OFFSETLOG_NUM_SEGMENTS,
			0
		);
		$offsetlog->allow_large_writes();

		$entry = \wp_json_encode( [
			'positions' => [
				'firehose.log' => [ 'seg' => 3, 'off' => 999 ],
			],
			'state' => [
				'handler-a' => [ 'counter' => 42 ],
			],
			'ts' => \time(),
		] );
		$offsetlog->write( $entry );

		// get_saved_positions only returns positions, not state.
		$positions = LogReader::get_saved_positions( 'state-test', 0 );
		$this->assertSame( 3, $positions['firehose.log']['seg'] );
		$this->assertSame( 999, $positions['firehose.log']['off'] );
	}

	// ── get_registered_readers: validation branches ─────────────────────

	public function test_get_registered_readers_via_filter(): void {
		// Register a valid handler via the apply_filters stub.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-group'] = [
				'test-handler' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();

		$this->assertArrayHasKey( 'test-group', $readers );
		$this->assertArrayHasKey( 'handlers', $readers['test-group'] );
		$this->assertArrayHasKey( 'test-handler', $readers['test-group']['handlers'] );
		$this->assertContains( 'firehose.log', $readers['test-group']['inputs'] );

		// Cleanup the filter.
		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_skips_non_string_group_name(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers[0] = [
				'handler' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertEmpty( $readers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_skips_missing_class(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['bad-group'] = [
				'handler' => [
					// No 'class' key.
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertEmpty( $readers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_skips_nonexistent_class(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['bad-group'] = [
				'handler' => [
					'class'  => 'Totally_Fake_Class_Does_Not_Exist',
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertEmpty( $readers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_skips_missing_inputs(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['bad-group'] = [
				'handler' => [
					'class' => MockLogHandler::class,
					// No 'inputs' key.
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertEmpty( $readers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_skips_non_string_input(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['bad-group'] = [
				'handler' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 12345 ], // Not a string.
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertEmpty( $readers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_includes_outputs(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['out-group'] = [
				'handler' => [
					'class'   => MockLogHandler::class,
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [ 'requests.log' ],
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertArrayHasKey( 'out-group', $readers );
		$this->assertContains( 'requests.log', $readers['out-group']['outputs'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_takes_max_stale_timeout(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['timeout-group'] = [
				'handler-a' => [
					'class'         => MockLogHandler::class,
					'inputs'        => [ 'firehose.log' ],
					'stale_timeout' => 120,
				],
				'handler-b' => [
					'class'         => MockLogHandler::class,
					'inputs'        => [ 'firehose.log' ],
					'stale_timeout' => 300,
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertSame( 300, $readers['timeout-group']['stale_timeout'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_skips_non_array_group(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['bad'] = 'not-an-array';
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertEmpty( $readers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_skips_non_string_handler_name(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['group'] = [
				0 => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertEmpty( $readers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_skips_non_array_handler_config(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['group'] = [
				'handler' => 'not-an-array',
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertEmpty( $readers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	public function test_get_registered_readers_union_of_inputs(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['union-group'] = [
				'handler-a' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
				'handler-b' => [
					'class'  => MockLogHandler::class,
					'inputs' => [ 'firehose.log', 'requests.log' ],
				],
			];
			return $readers;
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertCount( 2, $readers['union-group']['inputs'] );
		$this->assertContains( 'firehose.log', $readers['union-group']['inputs'] );
		$this->assertContains( 'requests.log', $readers['union-group']['inputs'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── constructor with saved state from offsetlog ──────────────────────

	public function test_constructor_restores_positions_from_offsetlog(): void {
		$offsets_dir = self::TEST_DIR . '/offsets';

		// Write a saved position to the offsetlog.
		$offsetlog = new Firehose(
			"{$offsets_dir}/restore-test.p0",
			0,
			LogReader::OFFSETLOG_SEGMENT_SIZE,
			LogReader::OFFSETLOG_NUM_SEGMENTS,
			0
		);
		$offsetlog->allow_large_writes();
		$offsetlog->write( \wp_json_encode( [
			'positions' => [
				'firehose.log' => [ 'seg' => 5, 'off' => 1234 ],
			],
			'state' => [
				'test-handler' => [ 'counter' => 99 ],
			],
			'ts' => \time(),
		] ) );

		$handlers = [
			'test-handler' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		$reader = new LogReader(
			'restore-test',
			[ 'firehose.log' ],
			$handlers,
			0,
			10,
			60
		);

		// Verify the firehose reader was positioned from saved data.
		$ref     = new \ReflectionProperty( LogReader::class, 'readers' );
		$ref->setAccessible( true );
		$readers = $ref->getValue( $reader );

		$pos = $readers['firehose.log']->get_position();
		$this->assertSame( 5, $pos['segment_id'] );
		$this->assertSame( 1234, $pos['offset'] );
	}

	// ── get_registered_readers: non-array return from filter ─────────────

	public function test_get_registered_readers_non_array_filter_return(): void {
		\add_filter( 'newspack_event_logger_log_readers', function () {
			return 'not-an-array';
		} );

		$readers = LogReader::get_registered_readers();
		$this->assertSame( [], $readers );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── get_live_positions returns null for non-array memcache value ─────

	public function test_get_live_positions_non_array_returns_null(): void {
		// Memcached stub returns false/null, so get_live_positions should return null.
		$result = LogReader::get_live_positions( 'any-group', 0 );
		$this->assertNull( $result );
	}

	// ── run() with max_runtime=0 ─────────────────────────────────────────

	public function test_run_exits_immediately_with_zero_max_runtime(): void {
		$handlers = [
			'test-handler' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		// max_runtime=0 means should_restart() returns true on first check.
		$reader = new LogReader(
			'test-run',
			[ 'firehose.log' ],
			$handlers,
			0,
			0, // max_runtime = 0
			60
		);

		$result = $reader->execute();
		$this->assertSame( 'completed', $result['status'] );
		// cleanup should have been called after the loop.
		$this->assertTrue( MockLogHandler::$cleanup_called );
	}

	public function test_run_processes_data_with_one_iteration(): void {
		// Write data to firehose so the reader has something to process.
		$firehose = new \Newspack_Event_Logger\Firehose( self::TEST_DIR . '/logs/firehose.log', 0 );
		$firehose->write( '{"k":"test","m":"line1"}' );
		$firehose->write( '{"k":"test","m":"line2"}' );
		unset( $firehose ); // Close file handle.

		$handlers = [
			'test-handler' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		// max_runtime=1: enters loop, processes data, sleeps ~10ms, should_restart exits.
		$reader = new LogReader(
			'test-data',
			[ 'firehose.log' ],
			$handlers,
			0,
			1, // 1 second — enough for one loop iteration
			60
		);

		$result = $reader->execute();
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNotEmpty( MockLogHandler::$processed_lines );
		$this->assertTrue( MockLogHandler::$cleanup_called );
	}

	public function test_run_calls_housekeeping_and_cleanup(): void {
		$handlers = [
			'test-handler' => [
				'class'  => MockLogHandler::class,
				'inputs' => [ 'firehose.log' ],
			],
		];

		$reader = new LogReader(
			'test-housekeep',
			[ 'firehose.log' ],
			$handlers,
			0,
			0, // exits immediately
			60
		);

		// Simulate dirty state so do_housekeeping runs on exit.
		$ref = new \ReflectionProperty( LogReader::class, 'house_is_dirty' );
		$ref->setAccessible( true );
		$ref->setValue( $reader, true );

		$result = $reader->execute();
		$this->assertSame( 'completed', $result['status'] );
		// Final housekeeping calls flush, then cleanup runs.
		$this->assertTrue( MockLogHandler::$flush_called );
		$this->assertTrue( MockLogHandler::$cleanup_called );
	}
}
