<?php
/**
 * Tests for FirehoseStreamController (SSE firehose streaming).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;
use Newspack_Event_Logger\REST\FirehoseStreamController;

/**
 * Testable subclass: overrides SSE lifecycle methods that cannot run in CLI.
 */
class TestableFirehoseStreamController extends FirehoseStreamController {

	private int $loop_count    = 0;
	private int $max_loops     = 0;

	/** Override heartbeat interval to 0 so tests hit the heartbeat path immediately. */
	protected const HEARTBEAT_INTERVAL = 0;

	protected function init_sse_headers(): void {}

	/**
	 * Allow N loop iterations then exit.
	 */
	protected function should_continue_stream( array &$context ): bool {
		return ++$this->loop_count <= $this->max_loops;
	}

	public function set_max_loops( int $n ): void {
		$this->max_loops = $n;
		$this->loop_count = 0;
	}

	public function public_stream_run( \WP_REST_Request $request ) {
		return $this->stream_run( $request );
	}
}

#[\PHPUnit\Framework\Attributes\CoversClass( FirehoseStreamController::class )]
class FirehoseStreamControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		self::reset_memcached();
		$GLOBALS['_wp_test_user_can']          = true;
		$GLOBALS['_wp_test_user_id']           = 42;
		$GLOBALS['_wp_test_registered_routes'] = [];
		$_SERVER['REMOTE_ADDR']                = '127.0.0.1';
	}

	protected function tearDown(): void {
		self::reset_memcached();
		Config::reset();
		unset( $GLOBALS['_wp_test_user_can'] );
		unset( $GLOBALS['_wp_test_user_id'] );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	private static function reset_memcached(): void {
		$ref = new \ReflectionClass( Memcached::class );
		foreach ( [ 'memd', 'extension' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}
		$init = $ref->getProperty( 'init_attempted' );
		$init->setAccessible( true );
		$init->setValue( null, false );
	}

	/**
	 * Create a firehose partition directory with an empty segment file.
	 *
	 * @return string Log base directory path.
	 */
	private function create_firehose_dir(): string {
		$log_base = Config::get_logs_directory();
		$log_dir  = "{$log_base}/firehose.log/p0";
		@\mkdir( $log_dir, 0755, true );
		\file_put_contents( "{$log_dir}/0.log", '' );
		return $log_base;
	}

	/**
	 * Clean up firehose directory.
	 *
	 * @param string $log_base Log base directory.
	 */
	private function cleanup_firehose_dir( string $log_base ): void {
		@\unlink( "{$log_base}/firehose.log/p0/0.log" );
		@\rmdir( "{$log_base}/firehose.log/p0" );
		@\rmdir( "{$log_base}/firehose.log" );
	}

	// ── register_routes ─────────────────────────────────────────────────

	public function test_register_routes_registers_firehose_stream_endpoint(): void {
		$controller = new FirehoseStreamController();
		$controller->register_routes();

		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertNotEmpty( $routes );

		$found = false;
		foreach ( $routes as $route ) {
			if ( 'event-logger/v1' === $route['namespace'] && false !== \strpos( $route['route'], 'stream' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected firehose/stream route to be registered' );
	}

	public function test_register_routes_has_partition_argument(): void {
		$controller = new FirehoseStreamController();
		$controller->register_routes();

		$routes = $GLOBALS['_wp_test_registered_routes'];
		$route  = $routes[0] ?? [];
		$args   = $route['args']['args'] ?? [];

		$this->assertArrayHasKey( 'partition', $args );
		$this->assertArrayHasKey( 'segment_id', $args );
		$this->assertArrayHasKey( 'offset', $args );
		$this->assertArrayHasKey( 'aggregator', $args );
	}

	// ── stream_run returns WP_Error when rate limited ───────────────────

	public function test_stream_run_returns_wp_error_when_rate_limited(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		// Fill all slots.
		$user_id = \get_current_user_id();
		$ip_hash = \substr( \md5( '127.0.0.1' ), 0, 8 );
		for ( $i = 0; $i < 10; $i++ ) {
			$key = "evlog:sse:{$user_id}:{$ip_hash}:{$i}";
			Memcached::set( $key, 'occupied', 60 );
		}

		$controller = new TestableFirehoseStreamController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'aggregator', false );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		\ob_get_clean();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_connections', $result->get_error_code() );

		// Clean up slots.
		for ( $i = 0; $i < 10; $i++ ) {
			$key = "evlog:sse:{$user_id}:{$ip_hash}:{$i}";
			Memcached::delete( $key );
		}
	}

	// ── stream_run with explicit segment_id + offset (resume) ───────────

	public function test_stream_run_resume_with_segment_id_and_offset(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = $this->create_firehose_dir();

		$controller = new TestableFirehoseStreamController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'segment_id', 0 );
		$request->set_param( 'offset', 100 );
		$request->set_param( 'aggregator', false );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: connected', $output );

		$this->cleanup_firehose_dir( $log_base );
	}

	// ── stream_run with offset only (legacy resume) ─────────────────────

	public function test_stream_run_resume_with_offset_only(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = $this->create_firehose_dir();

		$controller = new TestableFirehoseStreamController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'offset', 50 );
		// segment_id not set — legacy mode.
		$request->set_param( 'aggregator', false );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: connected', $output );

		$this->cleanup_firehose_dir( $log_base );
	}

	// ── stream_run tail mode (no position params) ───────────────────────

	public function test_stream_run_tail_mode(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = $this->create_firehose_dir();

		$controller = new TestableFirehoseStreamController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'partition', 0 );
		// No segment_id, no offset — tail mode.
		$request->set_param( 'aggregator', false );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: connected', $output );

		$this->cleanup_firehose_dir( $log_base );
	}

	// ── stream_run as aggregator ────────────────────────────────────────

	public function test_stream_run_aggregator_mode(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = $this->create_firehose_dir();

		$controller = new TestableFirehoseStreamController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'aggregator', true );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: connected', $output );

		$this->cleanup_firehose_dir( $log_base );
	}

	// ── stream_run with data processes entries ──────────────────────────

	public function test_stream_run_reads_json_entries(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = Config::get_logs_directory();
		$log_dir  = "{$log_base}/firehose.log/p0";
		@\mkdir( $log_dir, 0755, true );

		// Write two JSON entries to the segment.
		$entry1 = \json_encode( [ 'k' => 'start', 'rid' => 'r1', 'ts' => 1000 ] );
		$entry2 = \json_encode( [ 'k' => 'end', 'rid' => 'r1', 'ts' => 1001 ] );
		\file_put_contents( "{$log_dir}/0.log", "{$entry1}\n{$entry2}\n" );

		// Use a subclass that runs the loop a few times then stops.
		$controller = new class extends FirehoseStreamController {
			protected function init_sse_headers(): void {}
			private int $loop_count = 0;
			protected function should_continue_stream( array &$context ): bool {
				return $this->loop_count++ < 5;
			}
			public function public_stream_run( \WP_REST_Request $request ) {
				return $this->stream_run( $request );
			}
		};

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'segment_id', 0 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'aggregator', false );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: entry', $output );
		$this->assertStringContainsString( '"rid":"r1"', $output );

		// Clean up.
		@\unlink( "{$log_dir}/0.log" );
		@\rmdir( $log_dir );
		@\rmdir( "{$log_base}/firehose.log" );
	}

	// ── stream_run skips invalid JSON ───────────────────────────────────

	public function test_stream_run_skips_invalid_json(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = Config::get_logs_directory();
		$log_dir  = "{$log_base}/firehose.log/p0";
		@\mkdir( $log_dir, 0755, true );

		// Write a mix of valid and invalid lines.
		$valid   = \json_encode( [ 'k' => 'test', 'rid' => 'r2' ] );
		$content = "not json\n\n{$valid}\n";
		\file_put_contents( "{$log_dir}/0.log", $content );

		$controller = new class extends FirehoseStreamController {
			protected function init_sse_headers(): void {}
			private int $loop_count = 0;
			protected function should_continue_stream( array &$context ): bool {
				return $this->loop_count++ < 5;
			}
			public function public_stream_run( \WP_REST_Request $request ) {
				return $this->stream_run( $request );
			}
		};

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'segment_id', 0 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'aggregator', false );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		// Only the valid entry should produce an 'entry' event.
		$this->assertStringContainsString( '"rid":"r2"', $output );
		// Invalid JSON and blank lines should be skipped.
		$this->assertStringNotContainsString( 'not json', $output );

		// Clean up.
		@\unlink( "{$log_dir}/0.log" );
		@\rmdir( $log_dir );
		@\rmdir( "{$log_base}/firehose.log" );
	}

	// ── stream_run: no-segments path (fh null) ──────────────────────────

	public function test_stream_run_no_segments_path(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = Config::get_logs_directory();
		$log_dir  = "{$log_base}/firehose.log/p0";
		@\mkdir( $log_dir, 0755, true );
		// Empty partition directory — no segment files.

		$controller = new TestableFirehoseStreamController();
		$controller->set_max_loops( 2 );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'segment_id', null );
		$request->set_param( 'offset', null );
		$request->set_param( 'aggregator', false );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: connected', $output );

		@\rmdir( $log_dir );
		@\rmdir( "{$log_base}/firehose.log" );
	}

	// ── stream_run: caught-up path ──────────────────────────────────────

	public function test_stream_run_caught_up_path(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = Config::get_logs_directory();
		$log_dir  = "{$log_base}/firehose.log/p0";
		@\mkdir( $log_dir, 0755, true );
		$entry = \json_encode( [ 'k' => 'test', 'rid' => 'r1', 'ts' => 1000 ] );
		\file_put_contents( "{$log_dir}/0.log", "{$entry}\n" );

		$controller = new TestableFirehoseStreamController();
		$controller->set_max_loops( 3 );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'segment_id', 0 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'aggregator', false );

		\ob_start();
		$controller->public_stream_run( $request );
		$output = \ob_get_clean();

		// Should read the entry and then be caught up.
		$this->assertStringContainsString( 'event: entry', $output );

		@\unlink( "{$log_dir}/0.log" );
		@\rmdir( $log_dir );
		@\rmdir( "{$log_base}/firehose.log" );
	}
}
