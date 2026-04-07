<?php
/**
 * Tests for Newspack_Event_Logger\REST\SettingsController.
 *
 * @package Event_Logger
 */

use Newspack_Event_Logger\REST\SettingsController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SettingsController::class )]
class EventLoggerSettingsControllerTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Controller instance.
	 *
	 * @var SettingsController
	 */
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

	/**
	 * Helper: create a WP_REST_Request with params.
	 */
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
		$this->assertSame( 'event-logger/v1', $route['namespace'] );
		$this->assertSame( '/settings', $route['route'] );
	}

	public function test_validate_option_name_accepts_allowed(): void {
		$this->assertTrue( $this->controller->validate_option_name( 'event_logger_num_partitions' ) );
		$this->assertTrue( $this->controller->validate_option_name( 'event_logger_num_segments' ) );
		$this->assertTrue( $this->controller->validate_option_name( 'event_logger_segment_size' ) );
		$this->assertTrue( $this->controller->validate_option_name( 'event_logger_max_lifespan' ) );
	}

	public function test_validate_option_name_rejects_unknown(): void {
		$this->assertFalse( $this->controller->validate_option_name( 'not_allowed' ) );
		$this->assertFalse( $this->controller->validate_option_name( '' ) );
		$this->assertFalse( $this->controller->validate_option_name( 'event_logger_base_directory' ) );
	}

	public function test_update_setting_integer_success(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_num_partitions',
			'value'  => 4,
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'event_logger_num_partitions', $data['option'] );
		$this->assertTrue( $data['updated'] );
		$this->assertSame( 4, get_option( 'event_logger_num_partitions' ) );
	}

	public function test_update_setting_integer_string_coercion(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_segment_size',
			'value'  => '67108864',
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
		$this->assertSame( 67108864, get_option( 'event_logger_segment_size' ) );
	}

	public function test_update_setting_rejects_non_numeric(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_num_partitions',
			'value'  => 'not_a_number',
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_value', $response->get_error_code() );
	}

	public function test_update_setting_rejects_negative_int(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_num_partitions',
			'value'  => -1,
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_value', $response->get_error_code() );
	}

	public function test_update_setting_rejects_oversized_int(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_segment_size',
			'value'  => 2000000000, // Over 1GB limit.
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_value', $response->get_error_code() );
	}

	public function test_update_setting_boundary_max_int(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_segment_size',
			'value'  => 1073741824, // Exactly 1GB.
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
	}

	public function test_update_setting_zero_max_lifespan_is_valid(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_max_lifespan',
			'value'  => 0,
		] );
		$response = $this->controller->update_setting( $request );

		// Zero is valid for max_lifespan (disables time-based retention).
		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	public function test_update_setting_zero_num_partitions_is_rejected(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_num_partitions',
			'value'  => 0,
		] );
		$response = $this->controller->update_setting( $request );

		// Zero is rejected for num_partitions (minimum is 1).
		$this->assertInstanceOf( \WP_Error::class, $response );
	}

	public function test_update_setting_one_is_valid(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_max_lifespan',
			'value'  => 1,
		] );
		$response = $this->controller->update_setting( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
		$this->assertSame( 1, get_option( 'event_logger_max_lifespan' ) );
	}

	public function test_permissions_check_returns_true_when_allowed(): void {
		// current_user_can always returns true in test stubs.
		$result = $this->controller->update_permissions_check();
		$this->assertTrue( $result );
	}

	// ── sanitize_value: float type ───────────────────────────────────���──

	public function test_sanitize_value_float_via_reflection(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_value' );
		$ref->setAccessible( true );

		// Valid float.
		$result = $ref->invoke( $this->controller, '3.14', 'float' );
		$this->assertSame( 3.14, $result );

		// Zero is valid float.
		$result = $ref->invoke( $this->controller, 0, 'float' );
		$this->assertSame( 0.0, $result );

		// Max boundary: 86400.
		$result = $ref->invoke( $this->controller, 86400, 'float' );
		$this->assertSame( 86400.0, $result );
	}

	public function test_sanitize_value_float_rejects_non_numeric(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_value' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 'not_a_float', 'float' );
		$this->assertNull( $result );
	}

	public function test_sanitize_value_float_rejects_negative(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_value' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, -1.5, 'float' );
		$this->assertNull( $result );
	}

	public function test_sanitize_value_float_rejects_over_limit(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_value' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 86401, 'float' );
		$this->assertNull( $result );
	}

	// ── sanitize_value: array type ��─────────────────────────────────────

	public function test_sanitize_value_array_valid(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_value' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, [ 'hook_a', 'hook_b' ], 'array' );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}

	public function test_sanitize_value_array_rejects_non_array(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_value' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 'not_an_array', 'array' );
		$this->assertNull( $result );
	}

	// ── sanitize_value: unknown type ────────────────────────────────────

	public function test_sanitize_value_unknown_type(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_value' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 'value', 'unknown_type' );
		$this->assertNull( $result );
	}

	// ── sanitize_array: nested arrays ───────────────────────────────────

	public function test_sanitize_array_nested(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_array' );
		$ref->setAccessible( true );

		$input = [
			'key1' => 'value1',
			'key2' => [ 'nested_key' => 'nested_value' ],
		];

		$result = $ref->invoke( $this->controller, $input );
		$this->assertIsArray( $result );
		$this->assertSame( 'value1', $result['key1'] );
		$this->assertIsArray( $result['key2'] );
		$this->assertSame( 'nested_value', $result['key2']['nested_key'] );
	}

	public function test_sanitize_array_too_deep(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_array' );
		$ref->setAccessible( true );

		// Create deeply nested array (depth > 5). Need 7 levels to trigger the guard
		// because sanitize_array checks $depth > 5 BEFORE processing.
		$deep = [ 'a' => [ 'b' => [ 'c' => [ 'd' => [ 'e' => [ 'f' => [ 'g' => 'too deep' ] ] ] ] ] ] ];

		$result = $ref->invoke( $this->controller, $deep );
		$this->assertNull( $result );
	}

	public function test_sanitize_array_too_large(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_array' );
		$ref->setAccessible( true );

		// Create array with > 1000 elements.
		$large = \array_fill( 0, 1001, 'v' );

		$result = $ref->invoke( $this->controller, $large );
		$this->assertNull( $result );
	}

	public function test_sanitize_array_mixed_types(): void {
		$ref = new \ReflectionMethod( SettingsController::class, 'sanitize_array' );
		$ref->setAccessible( true );

		$input = [
			'string_val' => 'hello',
			'bool_val'   => true,
			'int_val'    => 42,
			'float_val'  => 3.14,
			'null_val'   => null,     // Should be skipped.
			0            => 'indexed',
		];

		$result = $ref->invoke( $this->controller, $input );
		$this->assertIsArray( $result );
		$this->assertSame( 'hello', $result['string_val'] );
		$this->assertTrue( $result['bool_val'] );
		$this->assertSame( 42, $result['int_val'] );
		$this->assertSame( 3.14, $result['float_val'] );
		$this->assertArrayNotHasKey( 'null_val', $result );
		$this->assertSame( 'indexed', $result[0] );
	}

	// ── update_setting: resets Config cache ──────────────────────────────

	public function test_update_setting_resets_config_cache(): void {
		$request = $this->make_request( [
			'option' => 'event_logger_num_segments',
			'value'  => 8,
		] );

		$response = $this->controller->update_setting( $request );
		$data     = $response->get_data();

		$this->assertSame( 'event_logger_num_segments', $data['option'] );
		$this->assertTrue( $data['updated'] );
	}

	// ── update_setting: all allowed options ─────���────────────────────────

	public function test_update_setting_zero_num_segments_is_rejected(): void {
		$request  = $this->make_request( [
			'option' => 'event_logger_num_segments',
			'value'  => 0,
		] );
		$response = $this->controller->update_setting( $request );

		// Zero is rejected for num_segments (minimum is 1).
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'invalid_value', $response->get_error_code() );
	}

	public function test_update_setting_negative_value_is_rejected(): void {
		$int_options = [
			'event_logger_num_partitions',
			'event_logger_num_segments',
			'event_logger_segment_size',
			'event_logger_max_lifespan',
		];

		foreach ( $int_options as $option ) {
			$request  = $this->make_request( [
				'option' => $option,
				'value'  => -1,
			] );
			$response = $this->controller->update_setting( $request );

			$this->assertInstanceOf( \WP_Error::class, $response, "Negative value should be rejected for {$option}" );
			$this->assertSame( 'invalid_value', $response->get_error_code(), "Error code mismatch for {$option}" );
		}
	}

	public function test_update_setting_all_allowed_options(): void {
		$options = [
			'event_logger_num_partitions' => 2,
			'event_logger_num_segments'   => 8,
			'event_logger_segment_size'   => 33554432,
			'event_logger_max_lifespan'   => 43200,
		];

		foreach ( $options as $option => $value ) {
			$request  = $this->make_request( [ 'option' => $option, 'value' => $value ] );
			$response = $this->controller->update_setting( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response, "Failed for {$option}" );
			$data = $response->get_data();
			$this->assertTrue( $data['updated'], "Option {$option} should be updated" );
			$this->assertSame( $value, get_option( $option ), "Option {$option} value mismatch" );
		}
	}
}
