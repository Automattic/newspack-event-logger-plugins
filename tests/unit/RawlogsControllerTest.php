<?php
/**
 * Tests for RawlogsController (SSE raw log streaming).
 *
 * @package Newspack_Event_Dashboards
 */

namespace Newspack_Event_Dashboards\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Dashboards\REST\RawlogsController;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;
use Newspack_Event_Logger\REST\FirehoseController;

/**
 * Testable subclass: overrides SSE lifecycle methods that cannot run in CLI.
 */
class TestableRawlogsController extends RawlogsController {

	/**
	 * Skip SSE headers in CLI.
	 */
	protected function init_sse_headers(): void {}

	/**
	 * Delegate to stream_log_run (skip exit).
	 *
	 * @param \WP_REST_Request $request   REST request.
	 * @param array            $config    Stream config.
	 * @param callable         $transform Line transformer.
	 * @return \WP_Error|void
	 */
	protected function stream_log( \WP_REST_Request $request, array $config, callable $transform ) {
		return $this->stream_log_run( $request, $config, $transform );
	}

	/**
	 * Exit loop immediately.
	 *
	 * @param array $context Stream context.
	 * @return bool Always false.
	 */
	protected function should_continue_stream( array &$context ): bool {
		return false;
	}
}

#[\PHPUnit\Framework\Attributes\CoversClass( RawlogsController::class )]
class RawlogsControllerTest extends TestCase {

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

	// ── register_routes ─────────────────────────────────────────────────

	public function test_register_routes_registers_rawlogs_endpoint(): void {
		$controller = new RawlogsController();
		$controller->register_routes();

		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertNotEmpty( $routes );

		$found = false;
		foreach ( $routes as $route ) {
			if ( false !== \strpos( $route['route'], 'rawlogs' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected rawlogs route to be registered' );
	}

	// ── transform_line static tests ─────────────────────────────────────

	public function test_transform_line_empty_returns_null(): void {
		$this->assertNull( RawlogsController::transform_line( '', 0 ) );
	}

	public function test_transform_line_normal_returns_entry(): void {
		$result = RawlogsController::transform_line( 'hello world', 3 );
		$this->assertSame( [ 'p' => 3, 'line' => 'hello world' ], $result );
	}

	public function test_transform_line_long_line_truncated(): void {
		$long = \str_repeat( 'a', 1500 );
		$result = RawlogsController::transform_line( $long, 0 );

		$this->assertNotNull( $result );
		$this->assertSame( 0, $result['p'] );
		$this->assertSame( 1003, \strlen( $result['line'] ) ); // 1000 + '...'
		$this->assertStringEndsWith( '...', $result['line'] );
	}

	public function test_transform_line_exactly_1000_chars_not_truncated(): void {
		$exact = \str_repeat( 'b', 1000 );
		$result = RawlogsController::transform_line( $exact, 1 );

		$this->assertNotNull( $result );
		$this->assertSame( 1000, \strlen( $result['line'] ) );
		$this->assertSame( $exact, $result['line'] );
	}

	public function test_transform_line_1001_chars_truncated(): void {
		$over = \str_repeat( 'c', 1001 );
		$result = RawlogsController::transform_line( $over, 0 );

		$this->assertNotNull( $result );
		$this->assertStringEndsWith( '...', $result['line'] );
	}

	// ── sanitize_log_param ──────────────────────────────────────────────

	public function test_sanitize_log_param_empty_returns_default(): void {
		$controller = new RawlogsController();
		$result = $controller->sanitize_log_param( '' );

		// With no readers registered, default is also empty.
		$default = FirehoseController::get_default_log();
		$this->assertSame( $default, $result );
	}

	public function test_sanitize_log_param_invalid_key_returns_default(): void {
		$controller = new RawlogsController();
		$result = $controller->sanitize_log_param( 'nonexistent.log' );

		// No matching log — returns default.
		$default = FirehoseController::get_default_log();
		$this->assertSame( $default, $result );
	}

	public function test_sanitize_log_param_strips_dot_log_suffix(): void {
		$controller = new RawlogsController();

		// Both 'firehose' and 'firehose.log' go through the same key lookup.
		$result1 = $controller->sanitize_log_param( 'firehose' );
		$result2 = $controller->sanitize_log_param( 'firehose.log' );

		// With no registered readers, both return default.
		$this->assertSame( $result1, $result2 );
	}

	// ── stream() end-to-end via testable subclass ───────────────────────

	public function test_stream_returns_wp_error_when_rate_limited(): void {
		// Fill all SSE slots to trigger rate limiting.
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

		$controller = new TestableRawlogsController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'log', 'firehose.log' );
		$request->set_param( 'interval', 100 );
		$request->set_param( 'positions', '' );

		\ob_start();
		$result = $controller->stream( $request );
		\ob_get_clean();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_connections', $result->get_error_code() );

		// Clean up slots.
		for ( $i = 0; $i < 10; $i++ ) {
			$key = "evlog:sse:{$user_id}:{$ip_hash}:{$i}";
			Memcached::delete( $key );
		}
	}

	public function test_stream_completes_with_empty_firehose(): void {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		// Create temp firehose directory structure.
		$log_base = Config::get_logs_directory();
		$log_dir  = "{$log_base}/firehose.log/p0";
		@\mkdir( $log_dir, 0755, true );

		// Create an empty segment file.
		\file_put_contents( "{$log_dir}/0.log", '' );

		$controller = new TestableRawlogsController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'log', 'firehose.log' );
		$request->set_param( 'interval', 100 );
		$request->set_param( 'positions', '' );

		\ob_start();
		$result = $controller->stream( $request );
		$output = \ob_get_clean();

		// Should not be a WP_Error (successful stream that exited immediately).
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		// Should contain connected and config SSE events.
		$this->assertStringContainsString( 'event: connected', $output );
		$this->assertStringContainsString( 'event: config', $output );

		// Clean up.
		@\unlink( "{$log_dir}/0.log" );
		@\rmdir( $log_dir );
		@\rmdir( "{$log_base}/firehose.log" );
	}
}
