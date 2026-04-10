<?php
/**
 * Tests for RequestsController (SSE request log streaming).
 *
 * Named SSERequestsControllerTest to avoid collision with the existing
 * RequestsControllerTest that tests the REST (non-SSE) requests controller.
 *
 * @package Newspack_Performance_Request_Log
 */

namespace Newspack_Performance_Request_Log\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Performance_Request_Log\REST\RequestsController;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;

/**
 * Testable subclass: overrides SSE lifecycle methods that cannot run in CLI.
 */
class TestableSSERequestsController extends RequestsController {

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

#[\PHPUnit\Framework\Attributes\CoversClass( RequestsController::class )]
class SSERequestsControllerTest extends TestCase {

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

	public function test_register_routes_registers_requests_endpoint(): void {
		$controller = new RequestsController();
		$controller->register_routes();

		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertNotEmpty( $routes );

		$found = false;
		foreach ( $routes as $route ) {
			if ( false !== \strpos( $route['route'], 'requests' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected requests route to be registered' );
	}

	// ── transform_line static tests ─────────────────────────────────────

	public function test_transform_line_invalid_json_returns_null(): void {
		$this->assertNull( RequestsController::transform_line( 'not json', 0 ) );
	}

	public function test_transform_line_missing_url_returns_null(): void {
		$line = \json_encode( [ 'rid' => 'abc', 'timestamp' => 1000 ] );
		$this->assertNull( RequestsController::transform_line( $line, 0 ) );
	}

	public function test_transform_line_empty_url_returns_null(): void {
		$line = \json_encode( [ 'url' => '' ] );
		$this->assertNull( RequestsController::transform_line( $line, 0 ) );
	}

	public function test_transform_line_valid_entry(): void {
		$line = \json_encode( [
			'rid'            => 'req-001',
			'request_method' => 'POST',
			'url'            => '/wp-admin/admin-ajax.php',
			'timestamp'      => 1700000000,
			'duration_ms'    => 250,
			'status_code'    => 200,
			'error_status'   => '-',
			'remote_addr'    => '10.0.0.1',
			'user_agent'     => 'Mozilla/5.0',
		] );

		$result = RequestsController::transform_line( $line, 1 );

		$this->assertNotNull( $result );
		$this->assertSame( 'req-001', $result['rid'] );
		$this->assertSame( 'POST', $result['method'] );
		$this->assertSame( '/wp-admin/admin-ajax.php', $result['url'] );
		$this->assertSame( 1700000000, $result['start_time'] );
		$this->assertSame( 250, $result['duration_ms'] );
		$this->assertSame( 200, $result['status_code'] );
		$this->assertSame( 'complete', $result['state'] );
		$this->assertSame( '-', $result['error_status'] );
		$this->assertSame( '10.0.0.1', $result['remote_addr'] );
		$this->assertSame( 'Mozilla/5.0', $result['user_agent'] );
	}

	public function test_transform_line_end_time_computed_correctly(): void {
		$line = \json_encode( [
			'url'         => '/page',
			'timestamp'   => 1000,
			'duration_ms' => 500,
		] );

		$result = RequestsController::transform_line( $line, 0 );

		$this->assertNotNull( $result );
		// end_time = timestamp + (duration_ms / 1000) = 1000 + 0.5 = 1000.5
		$this->assertEqualsWithDelta( 1000.5, $result['end_time'], 0.001 );
	}

	public function test_transform_line_defaults_for_missing_fields(): void {
		$line = \json_encode( [ 'url' => '/test' ] );

		$result = RequestsController::transform_line( $line, 0 );

		$this->assertNotNull( $result );
		$this->assertSame( '', $result['rid'] );
		$this->assertSame( 'GET', $result['method'] );
		$this->assertSame( 0, $result['start_time'] );
		$this->assertSame( 0, $result['duration_ms'] );
		$this->assertSame( 0, $result['status_code'] );
		$this->assertSame( '-', $result['error_status'] );
		$this->assertSame( '', $result['remote_addr'] );
		$this->assertSame( '', $result['user_agent'] );
	}

	public function test_transform_line_long_url_truncated(): void {
		$long_url = '/' . \str_repeat( 'a', 2500 );
		$line = \json_encode( [ 'url' => $long_url ] );

		$result = RequestsController::transform_line( $line, 0 );

		$this->assertNotNull( $result );
		$this->assertSame( 2003, \strlen( $result['url'] ) ); // 2000 + '...'
		$this->assertStringEndsWith( '...', $result['url'] );
	}

	public function test_transform_line_url_exactly_2000_not_truncated(): void {
		$exact_url = '/' . \str_repeat( 'b', 1999 );
		$line = \json_encode( [ 'url' => $exact_url ] );

		$result = RequestsController::transform_line( $line, 0 );

		$this->assertNotNull( $result );
		$this->assertSame( 2000, \strlen( $result['url'] ) );
	}

	public function test_transform_line_long_user_agent_truncated(): void {
		$long_ua = \str_repeat( 'z', 600 );
		$line = \json_encode( [ 'url' => '/page', 'user_agent' => $long_ua ] );

		$result = RequestsController::transform_line( $line, 0 );

		$this->assertNotNull( $result );
		$this->assertSame( 503, \strlen( $result['user_agent'] ) ); // 500 + '...'
		$this->assertStringEndsWith( '...', $result['user_agent'] );
	}

	public function test_transform_line_user_agent_exactly_500_not_truncated(): void {
		$exact_ua = \str_repeat( 'w', 500 );
		$line = \json_encode( [ 'url' => '/page', 'user_agent' => $exact_ua ] );

		$result = RequestsController::transform_line( $line, 0 );

		$this->assertNotNull( $result );
		$this->assertSame( 500, \strlen( $result['user_agent'] ) );
	}

	public function test_transform_line_empty_string_returns_null(): void {
		$this->assertNull( RequestsController::transform_line( '', 0 ) );
	}

	// ── stream() end-to-end via testable subclass ───────────────────────

	public function test_stream_returns_wp_error_when_rate_limited(): void {
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

		$controller = new TestableSSERequestsController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'interval', 1000 );
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
		$log_dir  = "{$log_base}/requests.log/p0";
		@\mkdir( $log_dir, 0755, true );
		\file_put_contents( "{$log_dir}/0.log", '' );

		$controller = new TestableSSERequestsController();
		$request    = new \WP_REST_Request( 'GET' );
		$request->set_param( 'interval', 1000 );
		$request->set_param( 'positions', '' );

		\ob_start();
		$result = $controller->stream( $request );
		$output = \ob_get_clean();

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'event: connected', $output );
		$this->assertStringContainsString( 'event: config', $output );

		// Clean up.
		@\unlink( "{$log_dir}/0.log" );
		@\rmdir( $log_dir );
		@\rmdir( "{$log_base}/requests.log" );
	}
}
