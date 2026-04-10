<?php
/**
 * Tests for ErrorsController (SSE error log streaming).
 *
 * @package Newspack_Performance_Dashboards
 */

namespace Newspack_Performance_Dashboards\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Performance_Dashboards\REST\ErrorsController;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;

/**
 * Testable subclass: overrides SSE lifecycle methods that cannot run in CLI.
 */
class TestableErrorsController extends ErrorsController {

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

#[\PHPUnit\Framework\Attributes\CoversClass( ErrorsController::class )]
class ErrorsControllerTest extends TestCase {

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

	public function test_register_routes_registers_errors_endpoint(): void {
		$controller = new ErrorsController();
		$controller->register_routes();

		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertNotEmpty( $routes );

		$found = false;
		foreach ( $routes as $route ) {
			if ( false !== \strpos( $route['route'], 'errors' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected errors route to be registered' );
	}

	// ── transform_line static tests ─────────────────────────────────────

	public function test_transform_line_invalid_json_returns_null(): void {
		$this->assertNull( ErrorsController::transform_line( 'not json', 0 ) );
	}

	public function test_transform_line_missing_rid_returns_null(): void {
		$line = \json_encode( [ 'ts' => 1000, 'k' => 'error', 'm' => 'oops' ] );
		$this->assertNull( ErrorsController::transform_line( $line, 0 ) );
	}

	public function test_transform_line_empty_rid_returns_null(): void {
		$line = \json_encode( [ 'rid' => '', 'ts' => 1000 ] );
		$this->assertNull( ErrorsController::transform_line( $line, 0 ) );
	}

	public function test_transform_line_valid_entry(): void {
		$line = \json_encode( [
			'rid' => 'abc123',
			'ts'  => 1700000000,
			'k'   => 'warning',
			'm'   => 'Something happened',
			'n'   => 5,
		] );

		$result = ErrorsController::transform_line( $line, 2 );

		$this->assertNotNull( $result );
		$this->assertSame( 'abc123', $result['rid'] );
		$this->assertSame( 1700000000, $result['ts'] );
		$this->assertSame( 'warning', $result['k'] );
		$this->assertSame( 'Something happened', $result['m'] );
		$this->assertSame( 5, $result['n'] );
	}

	public function test_transform_line_defaults_for_missing_fields(): void {
		$line = \json_encode( [ 'rid' => 'def456' ] );

		$result = ErrorsController::transform_line( $line, 0 );

		$this->assertNotNull( $result );
		$this->assertSame( 'def456', $result['rid'] );
		$this->assertSame( 0, $result['ts'] );
		$this->assertSame( '', $result['k'] );
		$this->assertSame( '', $result['m'] );
		$this->assertSame( 0, $result['n'] );
	}

	public function test_transform_line_long_message_truncated(): void {
		$long_m = \str_repeat( 'x', 1500 );
		$line = \json_encode( [
			'rid' => 'ghi789',
			'm'   => $long_m,
		] );

		$result = ErrorsController::transform_line( $line, 0 );

		$this->assertNotNull( $result );
		$this->assertSame( 1003, \strlen( $result['m'] ) ); // 1000 + '...'
		$this->assertStringEndsWith( '...', $result['m'] );
	}

	public function test_transform_line_message_exactly_1000_not_truncated(): void {
		$exact_m = \str_repeat( 'y', 1000 );
		$line = \json_encode( [
			'rid' => 'jkl012',
			'm'   => $exact_m,
		] );

		$result = ErrorsController::transform_line( $line, 0 );

		$this->assertNotNull( $result );
		$this->assertSame( 1000, \strlen( $result['m'] ) );
	}

	public function test_transform_line_empty_string_returns_null(): void {
		$this->assertNull( ErrorsController::transform_line( '', 0 ) );
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

		$controller = new TestableErrorsController();
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
		$log_dir  = "{$log_base}/errors.log/p0";
		@\mkdir( $log_dir, 0755, true );
		\file_put_contents( "{$log_dir}/0.log", '' );

		$controller = new TestableErrorsController();
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
		@\rmdir( "{$log_base}/errors.log" );
	}
}
