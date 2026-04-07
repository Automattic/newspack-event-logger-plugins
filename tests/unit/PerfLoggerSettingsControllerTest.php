<?php
/**
 * Tests for Newspack_Performance_Logger\REST\SettingsController.
 *
 * @package Newspack_Performance_Logger
 */

use Newspack_Performance_Logger\REST\SettingsController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SettingsController::class )]
class PerfLoggerSettingsControllerTest extends \PHPUnit\Framework\TestCase {

	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 1;
		$GLOBALS['_wp_test_registered_routes'] = [];
		$this->controller = new SettingsController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		$GLOBALS['_wp_test_registered_routes'] = [];
		Config::reset();
		parent::tearDown();
	}

	private function make_request( array $params = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	public function test_register_routes(): void {
		$this->controller->register_routes();
		$this->assertNotEmpty( $GLOBALS['_wp_test_registered_routes'] );
		$route = $GLOBALS['_wp_test_registered_routes'][0];
		$this->assertSame( 'perf-logger/v1', $route['namespace'] );
		$this->assertSame( '/settings', $route['route'] );
	}

	public function test_validate_option_name_accepts_all_allowed(): void {
		$allowed = [
			'event_logger_log_urls',
			'event_logger_skip_urls',
			'event_logger_log_events',
			'event_logger_custom_events',
			'event_logger_auto_disable_threshold',
			'event_logger_auto_protect_time_threshold',
			'event_logger_significant_events',
			'event_logger_log_memory',
			'event_logger_flush_every_line',
		];
		foreach ( $allowed as $option ) {
			$this->assertTrue(
				$this->controller->validate_option_name( $option ),
				"Expected '$option' to be allowed"
			);
		}
	}

	public function test_validate_option_name_rejects_core_options(): void {
		$this->assertFalse( $this->controller->validate_option_name( 'event_logger_num_partitions' ) );
		$this->assertFalse( $this->controller->validate_option_name( 'event_logger_segment_size' ) );
	}

	public function test_update_setting_int_type(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_auto_disable_threshold',
			'value'  => 500,
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'event_logger_auto_disable_threshold', $data['option'] );
		$this->assertTrue( $data['updated'] );
		$this->assertSame( 500, get_option( 'event_logger_auto_disable_threshold' ) );
	}

	public function test_update_setting_float_type(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_auto_protect_time_threshold',
			'value'  => 2.5,
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
		$this->assertSame( 2.5, get_option( 'event_logger_auto_protect_time_threshold' ) );
	}

	public function test_update_setting_float_rejects_non_numeric(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_auto_protect_time_threshold',
			'value'  => 'abc',
		] );
		$response = $this->controller->update_setting( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_value', $response->get_error_code() );
	}

	public function test_update_setting_float_rejects_over_max(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_auto_protect_time_threshold',
			'value'  => 100000, // Over 86400 max.
		] );
		$response = $this->controller->update_setting( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
	}

	public function test_update_setting_bool_type(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_log_memory',
			'value'  => true,
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
		$this->assertTrue( get_option( 'event_logger_log_memory' ) );
	}

	public function test_update_setting_bool_false(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_flush_every_line',
			'value'  => false,
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertFalse( get_option( 'event_logger_flush_every_line' ) );
	}

	public function test_update_setting_array_type(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_log_urls',
			'value'  => [ '/test', '/api/v1' ],
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
		$this->assertSame( [ '/test', '/api/v1' ], get_option( 'event_logger_log_urls' ) );
	}

	public function test_update_setting_array_rejects_non_array(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_log_urls',
			'value'  => 'not_array',
		] );
		$response = $this->controller->update_setting( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_value', $response->get_error_code() );
	}

	public function test_update_setting_array_rejects_too_deep(): void {
		// Build a 7-level deep nested array (exceeds max depth of 5).
		$value = 'leaf';
		for ( $i = 0; $i < 7; $i++ ) {
			$value = [ 'nested' => $value ];
		}
		$request  = $this->make_request( [
			'option' => 'event_logger_log_events',
			'value'  => $value,
		] );
		$response = $this->controller->update_setting( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
	}

	public function test_update_setting_array_rejects_oversized(): void {
		// Build array with 10001 items (exceeds MAX_EVENTS of 10000).
		$value = array_fill( 0, 10001, 'item' );
		$request  = $this->make_request( [
			'option' => 'event_logger_log_events',
			'value'  => $value,
		] );
		$response = $this->controller->update_setting( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
	}

	public function test_permissions_check_returns_true_when_allowed(): void {
		$result = $this->controller->update_permissions_check();
		$this->assertTrue( $result );
	}
}
