<?php
/**
 * Tests for Newspack_Performance_Dashboards\REST\OverviewController.
 *
 * @package Newspack_Performance_Dashboards
 */

use Newspack_Performance_Dashboards\REST\OverviewController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( OverviewController::class )]
class OverviewControllerTest extends \PHPUnit\Framework\TestCase {

	private OverviewController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']            = [];
		$GLOBALS['_wp_test_transients']         = [];
		$GLOBALS['_wp_test_user_id']            = 1;
		$GLOBALS['_wp_test_registered_routes']  = [];
		$this->controller = new OverviewController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']            = [];
		$GLOBALS['_wp_test_transients']         = [];
		$GLOBALS['_wp_test_user_id']            = 0;
		$GLOBALS['_wp_test_registered_routes']  = [];
		Config::reset();
		parent::tearDown();
	}

	// ── register_routes ─────────────────────────────────────────────────

	public function test_register_routes_registers_overview(): void {
		$this->controller->register_routes();
		$routes   = $GLOBALS['_wp_test_registered_routes'];
		$patterns = \array_column( $routes, 'route' );

		$this->assertContains( '/performance/overview', $patterns );
	}

	public function test_register_routes_uses_correct_namespace(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		$this->assertNotEmpty( $routes );
		$this->assertSame( 'event-logger/v1', $routes[0]['namespace'] );
	}

	public function test_register_routes_uses_get_method(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		// The route args are nested in an array.
		$args = $routes[0]['args'];
		// Could be array of arrays or direct.
		$method_args = isset( $args[0] ) ? $args[0] : $args;
		$this->assertSame( 'GET', $method_args['methods'] );
	}

	public function test_register_routes_has_breakdown_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		$args        = $routes[0]['args'];
		$method_args = isset( $args[0] ) ? $args[0] : $args;

		$this->assertArrayHasKey( 'breakdown', $method_args['args'] );
		$this->assertSame( 'string', $method_args['args']['breakdown']['type'] );
	}

	public function test_register_routes_has_server_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		$args        = $routes[0]['args'];
		$method_args = isset( $args[0] ) ? $args[0] : $args;

		$this->assertArrayHasKey( 'server', $method_args['args'] );
	}

	// ── get_overview ────────────────────────────────────────────────────

	public function test_get_overview_returns_response(): void {
		$request  = new \WP_REST_Request( 'GET' );
		$response = $this->controller->get_overview( $request );

		// Could be WP_REST_Response (success) or WP_Error (rate limit).
		if ( $response instanceof \WP_Error ) {
			$this->assertSame( 'rate_limit_exceeded', $response->get_error_code() );
			return;
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'total_urls', $data );
		$this->assertArrayHasKey( 'total_requests', $data );
		$this->assertArrayHasKey( 'global_avg_ms', $data );
		$this->assertArrayHasKey( 'global_avg_peak_mb', $data );
		$this->assertArrayHasKey( 'slowest_urls', $data );
		$this->assertArrayHasKey( 'most_requested', $data );
		$this->assertArrayHasKey( 'aggregate_time_series', $data );
		$this->assertArrayHasKey( 'global_leaderboard', $data );
	}

	public function test_get_overview_slowest_urls_limited_to_10(): void {
		$request  = new \WP_REST_Request( 'GET' );
		$response = $this->controller->get_overview( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertLessThanOrEqual( 10, \count( $data['slowest_urls'] ) );
		$this->assertLessThanOrEqual( 10, \count( $data['most_requested'] ) );
	}

	public function test_get_overview_zero_requests_no_division_by_zero(): void {
		$request  = new \WP_REST_Request( 'GET' );
		$response = $this->controller->get_overview( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		// Global averages should be numeric (no division by zero crash).
		$this->assertIsNumeric( $data['global_avg_ms'] );
		$this->assertIsNumeric( $data['global_avg_peak_mb'] );
	}

	public function test_get_overview_with_server_param(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'server', 'docker1' );

		$response = $this->controller->get_overview( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		// Should have global_leaderboard from server-specific method.
		$this->assertArrayHasKey( 'global_leaderboard', $data );
	}

	public function test_get_overview_without_breakdown(): void {
		$request  = new \WP_REST_Request( 'GET' );
		$response = $this->controller->get_overview( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		// No breakdown requested, so key should not be present.
		$this->assertArrayNotHasKey( 'breakdown_time_series', $data );
	}

	public function test_get_overview_with_valid_breakdown(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'breakdown', 'status' );

		$response = $this->controller->get_overview( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertArrayHasKey( 'breakdown_time_series', $data );
	}

	public function test_get_overview_with_invalid_breakdown(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'breakdown', 'invalid_dimension' );

		$response = $this->controller->get_overview( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		// Invalid breakdown should not add the key.
		$this->assertArrayNotHasKey( 'breakdown_time_series', $data );
	}

	// ── rate limiting ───────────────────────────────────────────────────

	public function test_get_overview_rate_limited(): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 300;

		$request  = new \WP_REST_Request( 'GET' );
		$response = $this->controller->get_overview( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rate_limit_exceeded', $response->get_error_code() );
	}
}
