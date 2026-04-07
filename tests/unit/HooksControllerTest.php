<?php
/**
 * Tests for Newspack_Performance_Logger\REST\HooksController.
 *
 * @package Newspack_Performance_Logger
 */

use Newspack_Performance_Logger\REST\HooksController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( HooksController::class )]
class HooksControllerTest extends \PHPUnit\Framework\TestCase {

	private HooksController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 1;
		$GLOBALS['_wp_test_registered_routes'] = [];
		$this->controller = new HooksController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		$GLOBALS['_wp_test_registered_routes'] = [];
		Config::reset();
		parent::tearDown();
	}

	private function make_request(): WP_REST_Request {
		return new WP_REST_Request( 'GET' );
	}

	public function test_register_routes(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertCount( 2, $routes );

		$route_paths = array_column( $routes, 'route' );
		$this->assertContains( '/performance/registered-hooks', $route_paths );
		$this->assertContains( '/performance/hook-categories', $route_paths );
	}

	public function test_read_permissions_check_allowed(): void {
		$result = $this->controller->read_permissions_check();
		$this->assertTrue( $result );
	}

	public function test_get_registered_hooks(): void {
		$response = $this->controller->get_registered_hooks( $this->make_request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'total_hooks', $data );
		$this->assertArrayHasKey( 'categories', $data );
		$this->assertArrayHasKey( 'hooks_by_category', $data );
		$this->assertIsInt( $data['total_hooks'] );
	}

	public function test_get_hook_categories(): void {
		$response = $this->controller->get_hook_categories( $this->make_request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'categories', $data );
		$this->assertArrayHasKey( 'config', $data );
	}

	public function test_rate_limit_enforcement(): void {
		// Fill up the rate limit.
		// HooksController::RATE_LIMIT_REQUESTS = 60 per 60s window.
		// We can't easily exhaust 60 requests, but we can set the transient directly.
		$now          = time();
		$window_start = (int) floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_hooks_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 60; // At the limit.

		$response = $this->controller->get_registered_hooks( $this->make_request() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'rate_limit_exceeded', $response->get_error_code() );
		$error_data = $response->get_error_data();
		$this->assertSame( 429, $error_data['status'] );
	}

	public function test_rate_limit_allows_below_limit(): void {
		$now          = time();
		$window_start = (int) floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_hooks_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 5; // Well below limit.

		$response = $this->controller->get_registered_hooks( $this->make_request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	public function test_rate_limit_increments_counter(): void {
		$now          = time();
		$window_start = (int) floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_hooks_rate_' . $identifier . '_' . $window_start;

		// Start at 0.
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 0;

		$this->controller->get_registered_hooks( $this->make_request() );

		// Counter should have been incremented.
		$this->assertSame( 1, $GLOBALS['_wp_test_transients'][ $transient_key ] );
	}
}
