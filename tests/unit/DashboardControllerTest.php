<?php
/**
 * Tests for Newspack_Performance_Logger\REST\DashboardController.
 *
 * @package Newspack_Performance_Logger
 */

use Newspack_Performance_Logger\REST\DashboardController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DashboardController::class )]
class DashboardControllerTest extends \PHPUnit\Framework\TestCase {

	private DashboardController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 1;
		$GLOBALS['_wp_test_registered_routes'] = [];
		$GLOBALS['wp_actions'] = [];
		$GLOBALS['wp_filter']  = [];
		$this->controller = new DashboardController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		$GLOBALS['_wp_test_registered_routes'] = [];
		unset( $GLOBALS['wp_actions'], $GLOBALS['wp_filter'] );
		Config::reset();
		parent::tearDown();
	}

	private function make_request( array $params = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	public function test_register_routes(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];
		// Should register: hooks/available, hooks/configure, config (GET), config (POST).
		$this->assertGreaterThanOrEqual( 3, count( $routes ) );
	}

	public function test_sanitize_string_array_indexed(): void {
		$result = $this->controller->sanitize_string_array( [ 'init', 'shutdown', 'wp_loaded' ] );
		$this->assertSame( [ 'init', 'shutdown', 'wp_loaded' ], $result );
	}

	public function test_sanitize_string_array_associative(): void {
		$result = $this->controller->sanitize_string_array( [ 'custom_event' => true, 'another' => true ] );
		$this->assertSame( [ 'custom_event' => true, 'another' => true ], $result );
	}

	public function test_sanitize_string_array_non_array(): void {
		$result = $this->controller->sanitize_string_array( 'not_array' );
		$this->assertSame( [], $result );
	}

	public function test_sanitize_string_array_skips_non_string_values(): void {
		$result = $this->controller->sanitize_string_array( [ 123, null, 'valid' ] );
		$this->assertSame( [ 'valid' ], $result );
	}

	public function test_get_available_hooks_with_actions(): void {
		$GLOBALS['wp_actions'] = [
			'init'        => 1,
			'wp_loaded'   => 1,
			'admin_init'  => 3,
		];
		$GLOBALS['wp_filter'] = [];

		$request  = $this->make_request();
		$response = $this->controller->get_available_hooks( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'hooks', $data );
		$hooks = $data['hooks'];
		$this->assertCount( 3, $hooks );

		// Verify sorting (alphabetical).
		$names = array_column( $hooks, 'name' );
		$this->assertSame( [ 'admin_init', 'init', 'wp_loaded' ], $names );
	}

	public function test_get_available_hooks_categorizes_admin(): void {
		$GLOBALS['wp_actions'] = [ 'admin_menu' => 1 ];
		$GLOBALS['wp_filter']  = [];

		$request  = $this->make_request();
		$response = $this->controller->get_available_hooks( $request );
		$data     = $response->get_data();
		$hooks    = $data['hooks'];

		$this->assertSame( 'Admin', $hooks[0]['category'] );
	}

	public function test_get_available_hooks_merges_filters(): void {
		$GLOBALS['wp_actions'] = [ 'init' => 1 ];
		// Create a simple array as wp_filter — not a WP_Hook instance.
		$GLOBALS['wp_filter']  = [ 'the_content' => [] ];

		$request  = $this->make_request();
		$response = $this->controller->get_available_hooks( $request );
		$data     = $response->get_data();
		$hooks    = $data['hooks'];

		$names = array_column( $hooks, 'name' );
		$this->assertContains( 'init', $names );
		$this->assertContains( 'the_content', $names );
	}

	public function test_configure_hooks(): void {
		$request = $this->make_request( [
			'hooks'         => [ 'init', 'shutdown' ],
			'custom_events' => [ 'pyrobase_render', 'pyrobase_query' ],
		] );
		$response = $this->controller->configure_hooks( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 4, $data['hooks_configured'] );
		$this->assertSame( [ 'init', 'shutdown' ], get_option( 'event_logger_log_events' ) );
		$this->assertSame(
			[ 'pyrobase_render' => true, 'pyrobase_query' => true ],
			get_option( 'event_logger_custom_events' )
		);
	}

	public function test_configure_hooks_filters_empty_strings(): void {
		$request = $this->make_request( [
			'hooks' => [ '', 'init', '' ],
		] );
		$response = $this->controller->configure_hooks( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['hooks_configured'] );
		$this->assertSame( [ 'init' ], get_option( 'event_logger_log_events' ) );
	}

	public function test_get_config(): void {
		update_option( 'event_logger_log_events', [ 'init', 'shutdown' ] );
		update_option( 'event_logger_log_memory', true );

		// Reset config cache so it picks up the options.
		Config::reset();

		$request  = $this->make_request();
		$response = $this->controller->get_config( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'config', $data );
		$config = $data['config'];
		$this->assertArrayHasKey( 'log_events', $config );
		$this->assertArrayHasKey( 'log_memory', $config );
		$this->assertArrayHasKey( 'flush_every_line', $config );
		$this->assertArrayHasKey( 'auto_disable_threshold', $config );
	}

	public function test_update_config_updates_multiple_fields(): void {
		$request = $this->make_request( [
			'auto_disable_threshold' => 100,
			'log_memory'             => true,
			'flush_every_line'       => false,
		] );
		$response = $this->controller->update_config( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertContains( 'auto_disable_threshold', $data['updated'] );
		$this->assertContains( 'log_memory', $data['updated'] );
		$this->assertContains( 'flush_every_line', $data['updated'] );
	}

	public function test_update_config_skips_null_params(): void {
		$request  = $this->make_request( [] ); // No params set.
		$response = $this->controller->update_config( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertEmpty( $data['updated'] );
	}

	public function test_update_config_array_assoc_deduplication(): void {
		$request = $this->make_request( [
			'log_events' => [ 'init', 'shutdown', 'init' ], // Duplicate.
		] );
		$response = $this->controller->update_config( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$stored = get_option( 'event_logger_log_events' );
		$this->assertSame( [ 'init', 'shutdown' ], $stored );
	}

	public function test_update_config_array_bool_conversion(): void {
		$request = $this->make_request( [
			'custom_events' => [ 'event_a', 'event_b' ],
		] );
		$response = $this->controller->update_config( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$stored = get_option( 'event_logger_custom_events' );
		$this->assertSame( [ 'event_a' => true, 'event_b' => true ], $stored );
	}

	public function test_admin_permissions_check(): void {
		$result = $this->controller->admin_permissions_check();
		$this->assertTrue( $result );
	}
}
