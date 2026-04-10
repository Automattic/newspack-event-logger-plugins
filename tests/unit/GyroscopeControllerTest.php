<?php
/**
 * Tests for GyroscopeController (SSE in-flight request streaming).
 *
 * @package Newspack_Performance_Gyroscope
 */

namespace Newspack_Performance_Gyroscope\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;
use Newspack_Performance_Gyroscope\REST\GyroscopeController;

/**
 * Testable subclass: overrides SSE lifecycle methods that cannot run in CLI.
 */
class TestableGyroscopeController extends GyroscopeController {

	/**
	 * Skip SSE headers in CLI.
	 */
	protected function init_sse_headers(): void {}

	/**
	 * Exit loop immediately.
	 *
	 * @param array $context Stream context.
	 * @return bool Always false.
	 */
	protected function should_continue_stream( array &$context ): bool {
		return false;
	}

	/**
	 * Expose stream_run for testing (skip exit in stream()).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|void
	 */
	public function public_stream_run( \WP_REST_Request $request ) {
		return $this->stream_run( $request );
	}
}

#[\PHPUnit\Framework\Attributes\CoversClass( GyroscopeController::class )]
class GyroscopeControllerTest extends TestCase {

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

	public function test_register_routes_registers_gyroscope_endpoint(): void {
		$controller = new GyroscopeController();
		$controller->register_routes();

		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertNotEmpty( $routes );

		$found = false;
		foreach ( $routes as $route ) {
			if ( false !== \strpos( $route['route'], 'gyroscope' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected gyroscope route to be registered' );
	}

	public function test_register_routes_has_interval_argument(): void {
		$controller = new GyroscopeController();
		$controller->register_routes();

		$routes = $GLOBALS['_wp_test_registered_routes'];
		$route  = $routes[0] ?? [];
		$args   = $route['args']['args'] ?? [];

		$this->assertArrayHasKey( 'interval', $args );
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

		$controller = new TestableGyroscopeController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'interval', 1000 );

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

	// ── stream_run creates InflightTracker and completes ────────────────

	public function test_stream_run_completes_with_empty_firehose(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = $this->create_firehose_dir();

		$controller = new TestableGyroscopeController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'interval', 1000 );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: connected', $output );
		$this->assertStringContainsString( 'event: config', $output );

		// Config event should include num_partitions and interval.
		$this->assertStringContainsString( '"num_partitions"', $output );
		$this->assertStringContainsString( '"interval"', $output );

		$this->cleanup_firehose_dir( $log_base );
	}

	// ── stream_run processes entries through InflightTracker ─────────────

	public function test_stream_run_processes_entries(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = Config::get_logs_directory();
		$log_dir  = "{$log_base}/firehose.log/p0";
		@\mkdir( $log_dir, 0755, true );

		// Write start and end events for a request.
		$start = \json_encode( [ 'k' => 'start', 'rid' => 'gyro-r1', 'ts' => \microtime( true ), 'url' => '/test' ] );
		$end   = \json_encode( [ 'k' => 'end', 'rid' => 'gyro-r1', 'ts' => \microtime( true ) + 0.1, 'duration_ms' => 100 ] );
		\file_put_contents( "{$log_dir}/0.log", "{$start}\n{$end}\n" );

		// Run enough loop iterations to process both entries and emit digest.
		$controller = new class extends GyroscopeController {
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
		$request->set_param( 'interval', 1 ); // 1ms interval for fast digest.

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: connected', $output );
		$this->assertStringContainsString( 'event: config', $output );

		// Should have processed entries — either inflight or complete_batch events.
		// The complete_batch is sent when get_completed() returns non-empty.
		$has_batch_or_inflight = (
			\str_contains( $output, 'event: complete_batch' ) ||
			\str_contains( $output, 'event: inflight' )
		);
		$this->assertTrue( $has_batch_or_inflight, 'Expected complete_batch or inflight event' );

		// Clean up.
		@\unlink( "{$log_dir}/0.log" );
		@\rmdir( $log_dir );
		@\rmdir( "{$log_base}/firehose.log" );
	}

	// ── stream_run loop runs cleanly with empty partition ────────────────

	public function test_stream_run_single_loop_iteration(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$log_base = $this->create_firehose_dir();

		// Run loop exactly once.
		$controller = new class extends GyroscopeController {
			protected function init_sse_headers(): void {}
			private int $loop_count = 0;
			protected function should_continue_stream( array &$context ): bool {
				return $this->loop_count++ < 1;
			}
			public function public_stream_run( \WP_REST_Request $request ) {
				return $this->stream_run( $request );
			}
		};

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'interval', 1000 );

		\ob_start();
		$result = $controller->public_stream_run( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: connected', $output );

		$this->cleanup_firehose_dir( $log_base );
	}
}
