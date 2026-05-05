<?php
/**
 * Tests for StreamMerger (per-partition SSE multiplexer).
 *
 * Tests the private helper methods via reflection:
 * - restore_offsets / commit_all (offsetlog persistence)
 * - update_connection_status / update_heartbeat_status (memcache status)
 * - record_successful_heartbeat / update_sse_heartbeat / clear_heartbeat_status
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\Memcached;
use Newspack_Event_Logger\Cron\LogReader;
use Newspack_Event_Aggregator\Cron\StreamMerger;

#[\PHPUnit\Framework\Attributes\CoversClass( StreamMerger::class )]
class StreamMergerTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-stream-merger';

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		self::reset_memcached_statics();

		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
		@\mkdir( self::TEST_DIR . '/locks', 0755, true );
		@\mkdir( self::TEST_DIR . '/offsets', 0755, true );
		$GLOBALS['_wp_test_options'] = [];

		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/stream-merger.php' );
		Config::reset();
	}

	protected function tearDown(): void {
		self::rmdir_recursive( self::TEST_DIR );
		Config::reset();
		self::reset_memcached_statics();

		// Reset ServerRegistry singleton to avoid polluting other tests.
		$reg_ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$reg_ref->setAccessible( true );
		$reg_ref->setValue( null, null );
		$GLOBALS['_wp_test_options'] = [];

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

	/**
	 * Reset Memcached static properties via reflection.
	 */
	private static function reset_memcached_statics(): void {
		$ref = new \ReflectionClass( Memcached::class );

		$memd = $ref->getProperty( 'memd' );
		$memd->setAccessible( true );
		$memd->setValue( null, null );

		$ext = $ref->getProperty( 'extension' );
		$ext->setAccessible( true );
		$ext->setValue( null, null );

		$init = $ref->getProperty( 'init_attempted' );
		$init->setAccessible( true );
		$init->setValue( null, false );
	}

	/**
	 * Initialize Memcached for testing and return whether it's available.
	 */
	private function init_memcached(): bool {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );
		return Memcached::is_available();
	}

	/**
	 * Create a StreamMerger instance for testing.
	 *
	 * @param int $partition Partition number.
	 * @return StreamMerger
	 */
	private function create_merger( int $partition = 0 ): StreamMerger {
		return new StreamMerger( $partition );
	}

	/**
	 * Invoke a private method on an object via reflection.
	 *
	 * @param object $obj    Object to invoke on.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke_private( object $obj, string $method, array $args = [] ) {
		$ref = new \ReflectionMethod( $obj, $method );
		$ref->setAccessible( true );
		return $ref->invoke( $obj, ...$args );
	}

	/**
	 * Get a private property value via reflection.
	 *
	 * @param object $obj      Object.
	 * @param string $property Property name.
	 * @return mixed
	 */
	private function get_private( object $obj, string $property ) {
		$ref = new \ReflectionProperty( $obj, $property );
		$ref->setAccessible( true );
		return $ref->getValue( $obj );
	}

	/**
	 * Set a private property value via reflection.
	 *
	 * @param object $obj      Object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 */
	private function set_private( object $obj, string $property, $value ): void {
		$ref = new \ReflectionProperty( $obj, $property );
		$ref->setAccessible( true );
		$ref->setValue( $obj, $value );
	}

	// ── Constructor ──────────────────────────────────────────────────────

	public function test_constructor_sets_partition(): void {
		$merger = $this->create_merger( 0 );

		$partition = $this->get_private( $merger, 'partition' );
		$this->assertSame( 0, $partition );
	}

	public function test_constructor_sets_log_base(): void {
		$merger = $this->create_merger( 0 );

		$log_base = $this->get_private( $merger, 'log_base' );
		$this->assertStringContainsString( '/logs', $log_base );
	}

	// ── restore_offsets tests ────────────────────────────────────────────

	public function test_restore_offsets_empty_log(): void {
		$merger = $this->create_merger( 0 );

		// Initialize offsetlog.
		$this->invoke_private( $merger, 'init_offsetlog' );

		// restore_offsets with empty log should leave positions empty.
		$this->invoke_private( $merger, 'restore_offsets' );

		$positions = $this->get_private( $merger, 'positions' );
		$this->assertEmpty( $positions );
	}

	public function test_restore_offsets_with_data(): void {
		$merger = $this->create_merger( 0 );

		// Initialize offsetlog.
		$this->invoke_private( $merger, 'init_offsetlog' );
		$offsetlog = $this->get_private( $merger, 'offsetlog' );

		// Write a position entry.
		$entry = [
			'server1' => [ 'seg' => 1, 'off' => 500 ],
			'server2' => [ 'seg' => 2, 'off' => 1000 ],
			'_ts'     => \time(),
		];
		$offsetlog->write( \wp_json_encode( $entry ) );

		// Restore should populate positions.
		$this->invoke_private( $merger, 'restore_offsets' );

		$positions = $this->get_private( $merger, 'positions' );
		$this->assertArrayHasKey( 'server1', $positions );
		$this->assertArrayHasKey( 'server2', $positions );
		$this->assertSame( 1, $positions['server1']['segment_id'] );
		$this->assertSame( 500, $positions['server1']['offset'] );
		$this->assertSame( 2, $positions['server2']['segment_id'] );
		$this->assertSame( 1000, $positions['server2']['offset'] );
	}

	public function test_restore_offsets_uses_last_line(): void {
		$merger = $this->create_merger( 0 );

		// Initialize offsetlog.
		$this->invoke_private( $merger, 'init_offsetlog' );
		$offsetlog = $this->get_private( $merger, 'offsetlog' );

		// Write multiple entries -- only last should be used.
		$entry1 = [
			'server1' => [ 'seg' => 0, 'off' => 100 ],
			'_ts'     => \time() - 10,
		];
		$offsetlog->write( \wp_json_encode( $entry1 ) );

		$entry2 = [
			'server1' => [ 'seg' => 1, 'off' => 999 ],
			'_ts'     => \time(),
		];
		$offsetlog->write( \wp_json_encode( $entry2 ) );

		$this->invoke_private( $merger, 'restore_offsets' );

		$positions = $this->get_private( $merger, 'positions' );
		$this->assertSame( 1, $positions['server1']['segment_id'] );
		$this->assertSame( 999, $positions['server1']['offset'] );
	}

	public function test_restore_offsets_survives_rotation(): void {
		$merger = $this->create_merger( 0 );

		// Initialize offsetlog.
		$this->invoke_private( $merger, 'init_offsetlog' );
		$offsetlog = $this->get_private( $merger, 'offsetlog' );

		// Write enough data to fill the first segment and force rotation.
		$entry = [
			'server1' => [ 'seg' => 3, 'off' => 777 ],
			'_ts'     => \time(),
		];
		$json = \wp_json_encode( $entry );

		// Fill up a segment (65536 bytes for offsetlog) to trigger rotation.
		$segment_size = LogReader::OFFSETLOG_SEGMENT_SIZE;
		$written      = 0;
		while ( $written < $segment_size ) {
			$offsetlog->write( $json );
			$written += \strlen( $json ) + 1; // +1 for newline.
		}

		// After rotation, the latest data is in the newest segment.
		$this->set_private( $merger, 'positions', [] );
		$this->invoke_private( $merger, 'restore_offsets' );

		$positions = $this->get_private( $merger, 'positions' );
		$this->assertArrayHasKey( 'server1', $positions );
		$this->assertSame( 3, $positions['server1']['segment_id'] );
		$this->assertSame( 777, $positions['server1']['offset'] );
	}

	// ── commit_all tests ─────────────────────────────────────────────────

	public function test_commit_all_empty_positions(): void {
		$merger = $this->create_merger( 0 );

		// Initialize offsetlog.
		$this->invoke_private( $merger, 'init_offsetlog' );

		// With empty positions, nothing should be written.
		$this->invoke_private( $merger, 'commit_all' );

		$offsetlog = $this->get_private( $merger, 'offsetlog' );
		$segments  = $offsetlog->get_segments( true );

		// Either no segments or empty segment.
		if ( ! empty( $segments ) ) {
			$seg     = \end( $segments );
			$this->assertSame( 0, $seg['size'] );
		} else {
			$this->assertEmpty( $segments );
		}
	}

	public function test_commit_all_writes_positions(): void {
		$merger = $this->create_merger( 0 );

		// Initialize offsetlog.
		$this->invoke_private( $merger, 'init_offsetlog' );

		// Set positions for two servers.
		$this->set_private( $merger, 'positions', [
			'alpha' => [ 'segment_id' => 5, 'offset' => 1234 ],
			'beta'  => [ 'segment_id' => 2, 'offset' => 5678 ],
		] );

		// Commit.
		$this->invoke_private( $merger, 'commit_all' );

		// Read back from offsetlog.
		$offsetlog = $this->get_private( $merger, 'offsetlog' );
		$segments  = $offsetlog->get_segments( true );
		$this->assertNotEmpty( $segments );

		$seg     = \end( $segments );
		$content = $offsetlog->read_at( $seg['id'], 0, $seg['size'] );
		$lines   = \explode( "\n", \trim( $content ) );
		$last    = \json_decode( \end( $lines ), true );

		$this->assertArrayHasKey( 'alpha', $last );
		$this->assertArrayHasKey( 'beta', $last );
		$this->assertSame( 5, $last['alpha']['seg'] );
		$this->assertSame( 1234, $last['alpha']['off'] );
		$this->assertSame( 2, $last['beta']['seg'] );
		$this->assertSame( 5678, $last['beta']['off'] );
		$this->assertArrayHasKey( '_ts', $last );
	}

	// ── update_connection_status tests ───────────────────────────────────

	public function test_update_connection_status_stores_in_memcache(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		$this->invoke_private( $merger, 'update_connection_status', [
			'test-server',
			'connected',
			200,
			'',
			null,
		] );

		$key  = 'aggregator_status:test-server:p0';
		$data = Memcached::get( $key );

		$this->assertIsArray( $data );
		$this->assertSame( 'connected', $data['last_connection_status'] );
		$this->assertSame( 200, $data['last_connection_response'] );
		$this->assertSame( '', $data['last_connection_error'] );
		$this->assertArrayHasKey( 'last_connection_attempt', $data );
	}

	public function test_update_connection_status_preserves_existing_fields(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );
		$key    = 'aggregator_status:test-server:p0';

		// Pre-populate existing data.
		Memcached::set( $key, [
			'last_connection_response' => 200,
			'last_connection_error'    => '',
			'custom_field'             => 'preserved',
		], 300 );

		// Update status without providing http_code or error (null = omit).
		$this->invoke_private( $merger, 'update_connection_status', [
			'test-server',
			'connecting',
			null,  // http_code omitted.
			null,  // error omitted.
			null,  // backoff omitted.
		] );

		$data = Memcached::get( $key );
		$this->assertSame( 'connecting', $data['last_connection_status'] );
		// Previous fields should still be there (merged).
		$this->assertSame( 'preserved', $data['custom_field'] );
	}

	public function test_update_connection_status_includes_backoff(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		$this->invoke_private( $merger, 'update_connection_status', [
			'test-server',
			'backoff',
			null,
			'Waiting 8s before retry',
			8,
		] );

		$key  = 'aggregator_status:test-server:p0';
		$data = Memcached::get( $key );

		$this->assertSame( 'backoff', $data['last_connection_status'] );
		$this->assertSame( 8, $data['current_backoff'] );
		$this->assertSame( 'Waiting 8s before retry', $data['last_connection_error'] );
	}

	// ── update_heartbeat_status tests ────────────────────────────────────

	public function test_update_heartbeat_status_success(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		// Set heartbeat_sent timestamp.
		$ref = new \ReflectionProperty( StreamMerger::class, 'heartbeat_sent' );
		$ref->setAccessible( true );
		$ref->setValue( $merger, [ 'test-server' => \time() ] );

		// Mock a successful HTTP response.
		$response = [
			'response' => [ 'code' => 200 ],
			'body'     => \wp_json_encode( [ 'success' => true ] ),
		];

		$this->invoke_private( $merger, 'update_heartbeat_status', [
			'test-server',
			$response,
			12.5,
		] );

		$key  = 'aggregator_status:test-server:p0';
		$data = Memcached::get( $key );

		$this->assertSame( 'success', $data['last_heartbeat_response_status'] );
		$this->assertSame( 12.5, $data['last_heartbeat_rtt'] );
		$this->assertNull( $data['last_heartbeat_error'] );
	}

	public function test_update_heartbeat_status_wp_error(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		$ref = new \ReflectionProperty( StreamMerger::class, 'heartbeat_sent' );
		$ref->setAccessible( true );
		$ref->setValue( $merger, [ 'test-server' => \time() ] );

		$wp_error = new \WP_Error( 'http_request_failed', 'Connection timed out' );

		$this->invoke_private( $merger, 'update_heartbeat_status', [
			'test-server',
			$wp_error,
			5000.0,
		] );

		$key  = 'aggregator_status:test-server:p0';
		$data = Memcached::get( $key );

		$this->assertSame( 'error', $data['last_heartbeat_response_status'] );
		$this->assertSame( 'Connection timed out', $data['last_heartbeat_error'] );
	}

	public function test_update_heartbeat_status_http_error(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		$ref = new \ReflectionProperty( StreamMerger::class, 'heartbeat_sent' );
		$ref->setAccessible( true );
		$ref->setValue( $merger, [ 'test-server' => \time() ] );

		$response = [
			'response' => [ 'code' => 500 ],
			'body'     => 'Internal Server Error',
		];

		$this->invoke_private( $merger, 'update_heartbeat_status', [
			'test-server',
			$response,
			50.0,
		] );

		$key  = 'aggregator_status:test-server:p0';
		$data = Memcached::get( $key );

		$this->assertSame( 'error', $data['last_heartbeat_response_status'] );
		$this->assertSame( 'HTTP 500', $data['last_heartbeat_error'] );
	}

	public function test_update_heartbeat_status_slot_expired(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		$ref = new \ReflectionProperty( StreamMerger::class, 'heartbeat_sent' );
		$ref->setAccessible( true );
		$ref->setValue( $merger, [ 'test-server' => \time() ] );

		$response = [
			'response' => [ 'code' => 200 ],
			'body'     => \wp_json_encode( [ 'success' => false, 'error' => 'Slot not found' ] ),
		];

		$this->invoke_private( $merger, 'update_heartbeat_status', [
			'test-server',
			$response,
			20.0,
		] );

		$key  = 'aggregator_status:test-server:p0';
		$data = Memcached::get( $key );

		$this->assertSame( 'slot_expired', $data['last_heartbeat_response_status'] );
		$this->assertSame( 'Slot not found', $data['last_heartbeat_error'] );
	}

	// ── record_successful_heartbeat tests ────────────────────────────────

	public function test_record_successful_heartbeat_sets_fields(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		$this->invoke_private( $merger, 'record_successful_heartbeat', [ 'test-server' ] );

		$key  = 'aggregator_status:test-server:p0';
		$data = Memcached::get( $key );

		$this->assertIsArray( $data );
		$this->assertSame( 'success', $data['last_heartbeat_response_status'] );
		$this->assertSame( 0, $data['last_heartbeat_rtt'] );
		$this->assertNull( $data['last_heartbeat_error'] );
		$this->assertArrayHasKey( 'last_heartbeat_sent', $data );
		$this->assertArrayHasKey( 'last_heartbeat_response', $data );
	}

	// ── update_sse_heartbeat tests ───────────────────────────────────────

	public function test_update_sse_heartbeat_sets_timestamp(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );
		$ts     = \time();

		$this->invoke_private( $merger, 'update_sse_heartbeat', [ 'test-server', $ts ] );

		$key  = 'aggregator_status:test-server:p0';
		$data = Memcached::get( $key );

		$this->assertIsArray( $data );
		$this->assertSame( $ts, $data['last_sse_heartbeat'] );
	}

	// ── clear_heartbeat_status tests ─────────────────────────────────────

	public function test_clear_heartbeat_status_resets_fields(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );
		$key    = 'aggregator_status:test-server:p0';

		// Pre-populate with data.
		Memcached::set( $key, [
			'last_heartbeat_sent'            => \time(),
			'last_heartbeat_response'        => \time(),
			'last_heartbeat_rtt'             => 15.0,
			'last_heartbeat_response_status' => 'success',
			'last_heartbeat_error'           => null,
			'last_sse_heartbeat'             => \time(),
		], 300 );

		$this->invoke_private( $merger, 'clear_heartbeat_status', [ 'test-server' ] );

		$data = Memcached::get( $key );
		$this->assertNull( $data['last_heartbeat_sent'] );
		$this->assertNull( $data['last_heartbeat_response'] );
		$this->assertNull( $data['last_heartbeat_rtt'] );
		$this->assertSame( 'pending', $data['last_heartbeat_response_status'] );
		$this->assertNull( $data['last_heartbeat_error'] );
		$this->assertNull( $data['last_sse_heartbeat'] );
	}

	// ── init_output tests ────────────────────────────────────────────────

	public function test_init_output_creates_firehose(): void {
		$merger = $this->create_merger( 0 );

		$this->invoke_private( $merger, 'init_output' );

		$output = $this->get_private( $merger, 'output' );
		$this->assertInstanceOf( Firehose::class, $output );
	}

	// ── init_offsetlog tests ─────────────────────────────────────────────

	public function test_init_offsetlog_creates_firehose(): void {
		$merger = $this->create_merger( 0 );

		$this->invoke_private( $merger, 'init_offsetlog' );

		$offsetlog = $this->get_private( $merger, 'offsetlog' );
		$this->assertInstanceOf( Firehose::class, $offsetlog );
	}

	// ── init_multi_handle / close_multi_handle ───────────────────────────

	public function test_init_and_close_multi_handle(): void {
		$merger = $this->create_merger( 0 );

		$this->invoke_private( $merger, 'init_multi_handle' );
		$mh = $this->get_private( $merger, 'multi_handle' );
		$this->assertNotNull( $mh, 'multi_handle should be initialized' );

		$this->invoke_private( $merger, 'close_multi_handle' );
		$mh = $this->get_private( $merger, 'multi_handle' );
		$this->assertNull( $mh, 'multi_handle should be null after close' );
	}

	public function test_close_multi_handle_when_already_null(): void {
		$merger = $this->create_merger( 0 );
		$this->set_private( $merger, 'multi_handle', null );

		// Should not crash.
		$this->invoke_private( $merger, 'close_multi_handle' );
		$this->assertNull( $this->get_private( $merger, 'multi_handle' ) );
	}

	// ── close_clients ────────────────────────────────────────────────────

	public function test_close_clients_empties_arrays(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		// Create a real SSEClient (won't connect, but has close()).
		$client = new \Newspack_Event_Aggregator\SSEClient( 'test', 'http://localhost', '', '', 0, false, true );
		$this->set_private( $merger, 'clients', [ 'test' => $client ] );

		$this->invoke_private( $merger, 'close_clients' );

		$this->assertEmpty( $this->get_private( $merger, 'clients' ) );
		$this->assertEmpty( $this->get_private( $merger, 'handle_to_server' ) );

		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	// ── maybe_commit ─────────────────────────────────────────────────────

	public function test_maybe_commit_skips_when_recent(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_offsetlog' );

		$this->set_private( $merger, 'last_commit_time', \microtime( true ) );
		$this->set_private( $merger, 'positions', [ 'srv1' => [ 'segment_id' => 0, 'offset' => 100 ] ] );

		$this->invoke_private( $merger, 'maybe_commit' );

		// Offsetlog should NOT have been written (too soon).
		$offsetlog = $this->get_private( $merger, 'offsetlog' );
		$segments  = $offsetlog->get_segments( true );
		$this->assertEmpty( $segments, 'Should not commit when interval not reached' );
	}

	public function test_maybe_commit_writes_when_interval_passed(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_offsetlog' );

		$this->set_private( $merger, 'last_commit_time', \microtime( true ) - 10 );
		$this->set_private( $merger, 'positions', [ 'srv1' => [ 'segment_id' => 0, 'offset' => 100 ] ] );

		$this->invoke_private( $merger, 'maybe_commit' );

		$offsetlog = $this->get_private( $merger, 'offsetlog' );
		$segments  = $offsetlog->get_segments( true );
		$this->assertNotEmpty( $segments, 'Should commit when interval passed' );
	}

	// ── process_events ───────────────────────────────────────────────────

	/**
	 * Create an SSEClient with injected events and connected state.
	 *
	 * @param string $server_id Server identifier.
	 * @param array  $events    Events to queue.
	 * @return \Newspack_Event_Aggregator\SSEClient
	 */
	private function make_sse_client( string $server_id, array $events = [] ): \Newspack_Event_Aggregator\SSEClient {
		$client = new \Newspack_Event_Aggregator\SSEClient( $server_id, 'http://localhost', '', '', 0, false, true );

		// Inject events.
		$ref = new \ReflectionProperty( $client, 'event_queue' );
		$ref->setAccessible( true );
		$ref->setValue( $client, $events );

		// Set connected.
		$ref = new \ReflectionProperty( $client, 'connected' );
		$ref->setAccessible( true );
		$ref->setValue( $client, true );

		// Set slot.
		$ref = new \ReflectionProperty( $client, 'slot' );
		$ref->setAccessible( true );
		$ref->setValue( $client, 1 );

		return $client;
	}

	public function test_process_events_writes_valid_entry(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_output' );
		$this->init_memcached();

		$entry_data = [
			'k'   => 'hook (start)',
			'ts'  => \microtime( true ),
			'rid' => 'test123',
			'm'   => 'init',
		];
		$client = $this->make_sse_client( 'srv1', [
			[ 'type' => 'connected', 'data' => [ 'slot' => 1 ] ],
			[ 'type' => 'entry', 'data' => $entry_data ],
		] );

		// Set position on client so get_position works.
		$pos_ref = new \ReflectionProperty( $client, 'position' );
		$pos_ref->setAccessible( true );
		$pos_ref->setValue( $client, [ 'segment_id' => 0, 'offset' => 500 ] );

		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );
		$this->set_private( $merger, 'heartbeat_sent', [ 'srv1' => \time() ] );

		// Reset ServerRegistry to have our server.
		$reg_ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$reg_ref->setAccessible( true );
		$reg_ref->setValue( null, null );
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'srv1' => [ 'url' => 'http://localhost', 'enabled' => true ],
		];
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		$this->invoke_private( $merger, 'process_events' );

		// Verify position was advanced.
		$positions = $this->get_private( $merger, 'positions' );
		$this->assertArrayHasKey( 'srv1', $positions );
		$this->assertSame( 500, $positions['srv1']['offset'] );
	}

	public function test_process_events_skips_entry_without_ts(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_output' );
		$this->init_memcached();

		$client = $this->make_sse_client( 'srv1', [
			[ 'type' => 'entry', 'data' => [ 'k' => 'test', 'rid' => 'r1', 'm' => 'hello' ] ],
		] );
		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );
		$this->set_private( $merger, 'heartbeat_sent', [ 'srv1' => \time() ] );
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		$this->invoke_private( $merger, 'process_events' );

		// Position should NOT be advanced (invalid entry skipped).
		$positions = $this->get_private( $merger, 'positions' );
		$this->assertArrayNotHasKey( 'srv1', $positions );
	}

	public function test_process_events_skips_oversized_entry(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_output' );
		$this->init_memcached();

		$big_data = [
			'k'   => 'hook (start)',
			'ts'  => \microtime( true ),
			'rid' => 'r1',
			'm'   => \str_repeat( 'x', 4000 ),
		];
		$client = $this->make_sse_client( 'srv1', [
			[ 'type' => 'entry', 'data' => $big_data ],
		] );

		$pos_ref = new \ReflectionProperty( $client, 'position' );
		$pos_ref->setAccessible( true );
		$pos_ref->setValue( $client, [ 'segment_id' => 0, 'offset' => 100 ] );

		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );
		$this->set_private( $merger, 'heartbeat_sent', [ 'srv1' => \time() ] );
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		$this->invoke_private( $merger, 'process_events' );

		// Oversized entry should be skipped but position NOT advanced
		// because the continue happens before the position update.
		$positions = $this->get_private( $merger, 'positions' );
		$this->assertArrayNotHasKey( 'srv1', $positions );
	}

	public function test_process_events_handles_heartbeat_event(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_output' );

		$client = $this->make_sse_client( 'srv1', [
			[ 'type' => 'heartbeat', 'data' => [ 'ts' => 1700000000 ] ],
		] );
		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );
		$this->set_private( $merger, 'heartbeat_sent', [ 'srv1' => \time() ] );
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		$this->invoke_private( $merger, 'process_events' );

		// Check memcache for SSE heartbeat update.
		$key  = 'aggregator_status:srv1:p0';
		$data = Memcached::get( $key );
		$this->assertIsArray( $data );
		$this->assertSame( 1700000000, $data['last_sse_heartbeat'] ?? null );
	}

	public function test_process_events_skips_disconnected_client(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_output' );

		// Create a disconnected client with events — events should not be processed.
		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://localhost', '', '', 0, false, true );
		$ref    = new \ReflectionProperty( $client, 'event_queue' );
		$ref->setAccessible( true );
		$ref->setValue( $client, [ [ 'type' => 'entry', 'data' => [ 'k' => 'test', 'ts' => 1.0 ] ] ] );
		// connected stays false (default).

		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );

		$this->invoke_private( $merger, 'process_events' );

		// Position should NOT be set (client skipped).
		$this->assertEmpty( $this->get_private( $merger, 'positions' ) );
	}

	// ── init_clients ─────────────────────────────────────────────────────

	public function test_init_clients_creates_sse_clients(): void {
		$merger = $this->create_merger( 0 );

		$reg_ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$reg_ref->setAccessible( true );
		$reg_ref->setValue( null, null );
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'srv1' => [ 'url' => 'https://srv1.example.com', 'enabled' => true ],
			'srv2' => [ 'url' => 'https://srv2.example.com', 'enabled' => true ],
		];
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		$this->invoke_private( $merger, 'init_clients' );

		$clients = $this->get_private( $merger, 'clients' );
		$this->assertCount( 2, $clients );
		$this->assertArrayHasKey( 'srv1', $clients );
		$this->assertArrayHasKey( 'srv2', $clients );
		$this->assertInstanceOf( \Newspack_Event_Aggregator\SSEClient::class, $clients['srv1'] );
	}

	// ── poll_multi_handle ────────────────────────────────────────────────

	public function test_poll_multi_handle_null_handle(): void {
		$merger = $this->create_merger( 0 );
		$this->set_private( $merger, 'multi_handle', null );

		// Should return early without crashing.
		$this->invoke_private( $merger, 'poll_multi_handle' );
		$this->assertTrue( true );
	}

	public function test_poll_multi_handle_with_empty_handle(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		// With no curl handles added, should just do select + exec with no events.
		$this->invoke_private( $merger, 'poll_multi_handle' );
		$this->assertTrue( true );

		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	// ── Constants tests ──────────────────────────────────────────────────

	public function test_commit_interval_constant(): void {
		$ref = new \ReflectionClass( StreamMerger::class );
		$const = $ref->getConstant( 'COMMIT_INTERVAL_S' );
		$this->assertSame( 5, $const );
	}

	public function test_heartbeat_interval_constant(): void {
		$ref = new \ReflectionClass( StreamMerger::class );
		$const = $ref->getConstant( 'HEARTBEAT_INTERVAL' );
		$this->assertSame( 15, $const );
	}

	public function test_select_timeout_constant(): void {
		$ref = new \ReflectionClass( StreamMerger::class );
		$const = $ref->getConstant( 'SELECT_TIMEOUT_S' );
		$this->assertSame( 1.0, $const );
	}

	// ── add_to_multi / remove_from_multi ─────────────────────────────────

	public function test_add_to_multi_and_remove_from_multi(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );
		$client->connect(); // Creates a curl handle.

		$this->invoke_private( $merger, 'add_to_multi', [ 'srv1', $client ] );

		$map = $this->get_private( $merger, 'handle_to_server' );
		$this->assertNotEmpty( $map, 'handle_to_server should have an entry after add' );
		$this->assertContains( 'srv1', $map );

		$this->invoke_private( $merger, 'remove_from_multi', [ 'srv1', $client ] );

		$map = $this->get_private( $merger, 'handle_to_server' );
		$this->assertEmpty( $map, 'handle_to_server should be empty after remove' );

		// Clean up.
		$client->close();
		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	public function test_add_to_multi_null_handle_is_noop(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		// Client without connect() has null handle.
		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );
		$this->assertNull( $client->get_handle() );

		$this->invoke_private( $merger, 'add_to_multi', [ 'srv1', $client ] );

		$map = $this->get_private( $merger, 'handle_to_server' );
		$this->assertEmpty( $map, 'Should not add null handle to map' );

		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	public function test_add_to_multi_null_multi_handle_is_noop(): void {
		$merger = $this->create_merger( 0 );
		$this->set_private( $merger, 'multi_handle', null );

		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );
		$client->connect();

		$this->invoke_private( $merger, 'add_to_multi', [ 'srv1', $client ] );

		$map = $this->get_private( $merger, 'handle_to_server' );
		$this->assertEmpty( $map );

		$client->close();
	}

	public function test_remove_from_multi_null_handle_is_noop(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		// Client with no handle (not connected).
		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );

		// Should not crash.
		$this->invoke_private( $merger, 'remove_from_multi', [ 'srv1', $client ] );
		$this->assertTrue( true );

		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	// ── remove_handle_from_multi ─────────────────────────────────────────

	public function test_remove_handle_from_multi_removes_mapping(): void {
		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );
		$client->connect();

		// Add client to multi.
		$this->invoke_private( $merger, 'add_to_multi', [ 'srv1', $client ] );
		$map = $this->get_private( $merger, 'handle_to_server' );
		$this->assertNotEmpty( $map );

		// Save handle before removing (mimics stale connection pattern).
		$handle = $client->get_handle();
		$this->assertNotNull( $handle );

		// Remove via pre-saved handle.
		$this->invoke_private( $merger, 'remove_handle_from_multi', [ 'srv1', $handle ] );

		$map = $this->get_private( $merger, 'handle_to_server' );
		$this->assertEmpty( $map );

		$client->close();
		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	public function test_remove_handle_from_multi_null_multi_is_noop(): void {
		$merger = $this->create_merger( 0 );
		$this->set_private( $merger, 'multi_handle', null );

		$handle = \curl_init();

		// Should not crash.
		$this->invoke_private( $merger, 'remove_handle_from_multi', [ 'srv1', $handle ] );
		$this->assertTrue( true );

		\curl_close( $handle );
	}

	// ── ensure_connections tests ─────────────────────────────────────────

	public function test_ensure_connections_reconnects_disconnected_client(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		// Create a disconnected client (default state, never connected).
		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );
		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );

		$this->invoke_private( $merger, 'ensure_connections' );

		// After ensure_connections, the client should have attempted connect.
		// connect() calls curl_init which succeeds for any URL.
		$this->assertTrue( $client->is_connected(), 'Client should be connected after ensure_connections' );

		// The handle should be in the multi map.
		$map = $this->get_private( $merger, 'handle_to_server' );
		$this->assertNotEmpty( $map, 'handle_to_server should have an entry' );

		// was_connected reflects BEFORE-reconnect state (false).
		// It updates to true on the NEXT ensure_connections call.
		$was = $this->get_private( $merger, 'was_connected' );
		$this->assertFalse( $was['srv1'] ?? true, 'was_connected reflects pre-reconnect state' );

		// Clean up.
		$this->invoke_private( $merger, 'close_clients' );
		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	public function test_ensure_connections_detects_disconnection(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		// Create a client that WAS connected but is now disconnected.
		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );
		// Simulate: was connected, now disconnected.
		$this->set_private( $merger, 'was_connected', [ 'srv1' => true ] );
		// Client.connected is false (default), so it looks like it disconnected.

		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );

		$this->invoke_private( $merger, 'ensure_connections' );

		// The disconnection path should update connection status in memcache.
		$key  = 'aggregator_status:srv1:p0';
		$data = Memcached::get( $key );
		$this->assertIsArray( $data );
		$this->assertSame( 'connecting', $data['last_connection_status'], 'Should reconnect after detecting disconnection' );

		// Clean up.
		$this->invoke_private( $merger, 'close_clients' );
		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	public function test_ensure_connections_detects_stale_connection(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		// Create a connected client with stale last_event_time.
		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );
		$client->connect();

		// Add to multi so remove_handle_from_multi has something to work with.
		$this->invoke_private( $merger, 'add_to_multi', [ 'srv1', $client ] );

		// Set last_event_time far in the past so check_stale() returns true.
		// HEARTBEAT_TIMEOUT is 45s.
		$ref = new \ReflectionProperty( $client, 'last_event_time' );
		$ref->setAccessible( true );
		$ref->setValue( $client, \microtime( true ) - 100 );

		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );
		$this->set_private( $merger, 'was_connected', [ 'srv1' => true ] );

		$this->invoke_private( $merger, 'ensure_connections' );

		// The stale detection path should have disconnected. Because check_stale()
		// increases backoff, the subsequent reconnect attempt may fail the backoff
		// check, resulting in either 'disconnected' or 'backoff' as final status.
		$key  = 'aggregator_status:srv1:p0';
		$data = Memcached::get( $key );
		$this->assertIsArray( $data );
		$this->assertContains(
			$data['last_connection_status'],
			[ 'disconnected', 'backoff', 'connecting' ],
			'Should detect stale connection and update status'
		);

		// Clean up.
		$this->invoke_private( $merger, 'close_clients' );
		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	public function test_ensure_connections_backoff_path(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		// Create a disconnected client in backoff.
		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );

		// Set backoff: large current_backoff and recent last_connect_attempt.
		$ref = new \ReflectionProperty( $client, 'current_backoff' );
		$ref->setAccessible( true );
		$ref->setValue( $client, 30 ); // MAX_BACKOFF.

		$ref2 = new \ReflectionProperty( $client, 'last_connect_attempt' );
		$ref2->setAccessible( true );
		$ref2->setValue( $client, \microtime( true ) ); // Just attempted.

		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );

		$this->invoke_private( $merger, 'ensure_connections' );

		// Client should still be disconnected (in backoff).
		$this->assertFalse( $client->is_connected(), 'Client should remain disconnected during backoff' );

		// Should have written backoff status to memcache.
		$key  = 'aggregator_status:srv1:p0';
		$data = Memcached::get( $key );
		$this->assertIsArray( $data );
		$this->assertSame( 'backoff', $data['last_connection_status'] );
		$this->assertSame( 30, $data['current_backoff'] );

		$this->invoke_private( $merger, 'close_multi_handle' );
	}

	// ── maybe_send_heartbeat tests ───────────────────────────────────────

	public function test_maybe_send_heartbeat_sends_when_interval_passed(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		// Set up server registry.
		$reg_ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$reg_ref->setAccessible( true );
		$reg_ref->setValue( null, null );
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'srv1' => [
				'url'           => 'http://localhost',
				'enabled'       => true,
				'auth_username' => 'user',
				'auth_password' => 'pass',
			],
		];
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		// Create a connected client with a slot.
		$client = $this->make_sse_client( 'srv1', [] );
		$slot_ref = new \ReflectionProperty( $client, 'slot' );
		$slot_ref->setAccessible( true );
		$slot_ref->setValue( $client, 5 );

		// Set heartbeat_sent to past (so interval check passes).
		$this->set_private( $merger, 'heartbeat_sent', [ 'srv1' => \time() - 60 ] );

		$this->invoke_private( $merger, 'maybe_send_heartbeat', [ 'srv1', $client ] );

		// Verify memcache was updated with heartbeat status.
		$key  = 'aggregator_status:srv1:p0';
		$data = Memcached::get( $key );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'last_heartbeat_response_status', $data );
		// wp_remote_post stub returns 200 with no body, so json_decode yields null.
		// The status parser checks if 200 !== $code (false) and then checks $body['success'],
		// which is not set (null), so it falls through to 'success'.
		$this->assertSame( 'success', $data['last_heartbeat_response_status'] );
	}

	public function test_maybe_send_heartbeat_skips_when_recent(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		$reg_ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$reg_ref->setAccessible( true );
		$reg_ref->setValue( null, null );
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'srv1' => [ 'url' => 'http://localhost', 'enabled' => true ],
		];
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		$client = $this->make_sse_client( 'srv1', [] );
		$slot_ref = new \ReflectionProperty( $client, 'slot' );
		$slot_ref->setAccessible( true );
		$slot_ref->setValue( $client, 5 );

		// Set heartbeat_sent to NOW (too recent).
		$this->set_private( $merger, 'heartbeat_sent', [ 'srv1' => \time() ] );

		$this->invoke_private( $merger, 'maybe_send_heartbeat', [ 'srv1', $client ] );

		// Heartbeat should NOT have been updated (early return).
		// The heartbeat_sent map should be unchanged (still the original value).
		$sent = $this->get_private( $merger, 'heartbeat_sent' );
		$this->assertSame( $sent['srv1'], \time(), 'heartbeat_sent should not be updated' );
	}

	public function test_maybe_send_heartbeat_skips_when_no_slot(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		$reg_ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$reg_ref->setAccessible( true );
		$reg_ref->setValue( null, null );
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'srv1' => [ 'url' => 'http://localhost', 'enabled' => true ],
		];
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		$client = $this->make_sse_client( 'srv1', [] );
		// Set slot to null (no slot acquired).
		$slot_ref = new \ReflectionProperty( $client, 'slot' );
		$slot_ref->setAccessible( true );
		$slot_ref->setValue( $client, null );

		// Interval has passed.
		$this->set_private( $merger, 'heartbeat_sent', [ 'srv1' => \time() - 60 ] );

		$this->invoke_private( $merger, 'maybe_send_heartbeat', [ 'srv1', $client ] );

		// heartbeat_sent should be unchanged (early return before sending).
		$sent = $this->get_private( $merger, 'heartbeat_sent' );
		$this->assertLessThan( \time() - 30, $sent['srv1'], 'heartbeat_sent should not be updated' );
	}

	public function test_maybe_send_heartbeat_skips_when_negative_slot(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		$reg_ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$reg_ref->setAccessible( true );
		$reg_ref->setValue( null, null );
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'srv1' => [ 'url' => 'http://localhost', 'enabled' => true ],
		];
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		$client = $this->make_sse_client( 'srv1', [] );
		$slot_ref = new \ReflectionProperty( $client, 'slot' );
		$slot_ref->setAccessible( true );
		$slot_ref->setValue( $client, -1 );

		$this->set_private( $merger, 'heartbeat_sent', [ 'srv1' => \time() - 60 ] );

		$this->invoke_private( $merger, 'maybe_send_heartbeat', [ 'srv1', $client ] );

		$key  = 'aggregator_status:srv1:p0';
		$data = Memcached::get( $key );
		$sent = $this->get_private( $merger, 'heartbeat_sent' );
		$this->assertLessThan( \time() - 30, $sent['srv1'], 'heartbeat_sent should not be updated when slot negative' );
	}

	public function test_maybe_send_heartbeat_skips_when_server_not_in_registry(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );

		// Registry with no servers.
		$reg_ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$reg_ref->setAccessible( true );
		$reg_ref->setValue( null, null );
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [];
		$this->set_private( $merger, 'registry', \Newspack_Event_Aggregator\ServerRegistry::get_instance() );

		$client = $this->make_sse_client( 'srv1', [] );
		$slot_ref = new \ReflectionProperty( $client, 'slot' );
		$slot_ref->setAccessible( true );
		$slot_ref->setValue( $client, 5 );

		$this->set_private( $merger, 'heartbeat_sent', [ 'srv1' => \time() - 60 ] );

		// Clear any stale memcache data.
		$key = 'aggregator_status:srv1:p0';
		Memcached::delete( $key );

		$this->invoke_private( $merger, 'maybe_send_heartbeat', [ 'srv1', $client ] );

		// Should bail out when server not found in registry.
		// heartbeat_sent IS updated (line 567) but wp_remote_post NOT called,
		// so no heartbeat response in memcache.
		$data = Memcached::get( $key );
		$this->assertEmpty( $data, 'No memcache heartbeat data when server not in registry' );
	}

	// ── poll_multi_handle with failed connection ─────────────────────────

	public function test_poll_multi_handle_handles_connection_failure(): void {
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$merger = $this->create_merger( 0 );
		$this->invoke_private( $merger, 'init_multi_handle' );

		// Create a client connecting to a non-listening port.
		$client = new \Newspack_Event_Aggregator\SSEClient( 'srv1', 'http://127.0.0.1:1', '', '', 0, false, true );
		$client->connect();

		$this->invoke_private( $merger, 'add_to_multi', [ 'srv1', $client ] );
		$this->set_private( $merger, 'clients', [ 'srv1' => $client ] );

		// Poll until cURL detects the connection failure.
		// The connection to port 1 should fail quickly.
		$max_iterations = 50;
		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$this->invoke_private( $merger, 'poll_multi_handle' );

			// Check if the handle was removed (failure detected).
			$map = $this->get_private( $merger, 'handle_to_server' );
			if ( empty( $map ) ) {
				break;
			}
		}

		// The connection should have failed and been cleaned up.
		$map = $this->get_private( $merger, 'handle_to_server' );
		$this->assertEmpty( $map, 'Failed connection should be removed from handle_to_server' );

		// was_connected should be false.
		$was = $this->get_private( $merger, 'was_connected' );
		$this->assertFalse( $was['srv1'] ?? true, 'was_connected should be false after failure' );

		// Clean up.
		$this->invoke_private( $merger, 'close_multi_handle' );
	}
}
