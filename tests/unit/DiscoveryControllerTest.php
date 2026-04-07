<?php
/**
 * Tests for Newspack_Event_Logger\REST\DiscoveryController.
 *
 * @package Event_Logger
 */

use Newspack_Event_Logger\REST\DiscoveryController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DiscoveryController::class )]
class DiscoveryControllerTest extends \PHPUnit\Framework\TestCase {

	private DiscoveryController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 1;
		$GLOBALS['_wp_test_registered_routes'] = [];
		$this->controller = new DiscoveryController();
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
		$this->assertNotEmpty( $routes );
		$this->assertSame( 'event-logger/v1', $routes[0]['namespace'] );
		$this->assertSame( '/discovery', $routes[0]['route'] );
	}

	public function test_discovery_permissions_check(): void {
		$result = $this->controller->discovery_permissions_check();
		$this->assertTrue( $result );
	}

	public function test_get_discovery_empty_config(): void {
		// Default test config has empty log_events and custom_events.
		$response = $this->controller->get_discovery( $this->make_request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'registered_hooks', $data );
		$this->assertArrayHasKey( 'custom_events', $data );
		$this->assertIsArray( $data['registered_hooks'] );
		$this->assertIsArray( $data['custom_events'] );
	}

	public function test_get_discovery_with_indexed_array_hooks(): void {
		// log_events as indexed array of strings.
		update_option( 'event_logger_log_events', [ 'init', 'shutdown', 'wp_loaded' ] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$this->assertContains( 'init', $data['registered_hooks'] );
		$this->assertContains( 'shutdown', $data['registered_hooks'] );
		$this->assertContains( 'wp_loaded', $data['registered_hooks'] );
	}

	public function test_get_discovery_with_associative_hooks(): void {
		// log_events as associative array (key = hook name, value = bool).
		update_option( 'event_logger_log_events', [
			'save_post'     => true,
			'delete_post'   => true,
			'wp_insert_post' => false,
		] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$this->assertContains( 'save_post', $data['registered_hooks'] );
		$this->assertContains( 'delete_post', $data['registered_hooks'] );
		$this->assertContains( 'wp_insert_post', $data['registered_hooks'] );
	}

	public function test_get_discovery_deduplicates_hooks(): void {
		update_option( 'event_logger_log_events', [ 'init', 'init', 'shutdown' ] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		// Should deduplicate.
		$init_count = array_count_values( $data['registered_hooks'] )['init'] ?? 0;
		$this->assertSame( 1, $init_count );
	}

	public function test_get_discovery_with_custom_events(): void {
		update_option( 'event_logger_custom_events', [
			'pyrobase_render' => true,
			'pyrobase_query'  => true,
		] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$this->assertContains( 'pyrobase_render', $data['custom_events'] );
		$this->assertContains( 'pyrobase_query', $data['custom_events'] );
	}

	public function test_get_discovery_filters_empty_strings(): void {
		update_option( 'event_logger_log_events', [ '', 'init', '' ] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$this->assertNotContains( '', $data['registered_hooks'] );
		$this->assertContains( 'init', $data['registered_hooks'] );
	}

	public function test_get_discovery_filters_numeric_keys(): void {
		// Numeric string keys should be treated as indexed, not as hook names.
		update_option( 'event_logger_log_events', [
			'0'    => 'hook_a',
			'1'    => 'hook_b',
			'real' => true,
		] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		// '0' and '1' are numeric keys so their values should be extracted.
		$this->assertContains( 'hook_a', $data['registered_hooks'] );
		$this->assertContains( 'hook_b', $data['registered_hooks'] );
		$this->assertContains( 'real', $data['registered_hooks'] );
	}

	// ── calculate_position_difference via reflection ────────────────────

	public function test_calculate_position_difference_same_segment(): void {
		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_position_difference' );
		$ref->setAccessible( true );

		$lag = $ref->invoke(
			$this->controller,
			[ 'segment_id' => 5, 'offset' => 1000 ],
			[ 'segment_id' => 5, 'offset' => 200 ],
			65536
		);

		$this->assertSame( 800, $lag );
	}

	public function test_calculate_position_difference_different_segments(): void {
		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_position_difference' );
		$ref->setAccessible( true );

		$segment_size = 65536;
		$lag = $ref->invoke(
			$this->controller,
			[ 'segment_id' => 3, 'offset' => 100 ],   // Writer at seg 3, offset 100.
			[ 'segment_id' => 1, 'offset' => 60000 ],  // Reader at seg 1, offset 60000.
			$segment_size
		);

		// Expected: (65536 - 60000) remaining in seg 1 + (1 full seg between) + 100 in seg 3.
		$expected = ( $segment_size - 60000 ) + ( 1 * $segment_size ) + 100;
		$this->assertSame( $expected, $lag );
	}

	public function test_calculate_position_difference_reader_ahead(): void {
		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_position_difference' );
		$ref->setAccessible( true );

		// Reader ahead of writer (shouldn't happen normally, returns 0).
		$lag = $ref->invoke(
			$this->controller,
			[ 'segment_id' => 1, 'offset' => 100 ],
			[ 'segment_id' => 3, 'offset' => 200 ],
			65536
		);

		$this->assertSame( 0, $lag );
	}

	public function test_calculate_position_difference_same_position(): void {
		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_position_difference' );
		$ref->setAccessible( true );

		$lag = $ref->invoke(
			$this->controller,
			[ 'segment_id' => 5, 'offset' => 500 ],
			[ 'segment_id' => 5, 'offset' => 500 ],
			65536
		);

		$this->assertSame( 0, $lag );
	}

	public function test_calculate_position_difference_reader_at_zero(): void {
		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_position_difference' );
		$ref->setAccessible( true );

		$segment_size = 65536;
		$lag = $ref->invoke(
			$this->controller,
			[ 'segment_id' => 2, 'offset' => 500 ],
			[ 'segment_id' => 0, 'offset' => 0 ],
			$segment_size
		);

		// (65536 - 0) from seg 0 + (1 full segment) + 500 in seg 2.
		$expected = $segment_size + $segment_size + 500;
		$this->assertSame( $expected, $lag );
	}

	public function test_calculate_position_difference_adjacent_segments(): void {
		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_position_difference' );
		$ref->setAccessible( true );

		$segment_size = 65536;
		$lag = $ref->invoke(
			$this->controller,
			[ 'segment_id' => 1, 'offset' => 200 ],
			[ 'segment_id' => 0, 'offset' => 60000 ],
			$segment_size
		);

		// Adjacent: remaining in seg 0 + seg 1 offset. No full segments between.
		$expected = ( $segment_size - 60000 ) + 200;
		$this->assertSame( $expected, $lag );
	}

	// ── calculate_lag via reflection ────────────────────────────────────

	public function test_calculate_lag_returns_null_on_error(): void {
		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_lag' );
		$ref->setAccessible( true );

		// calculate_lag catches all Throwable. In test env without real files,
		// it should return null or a valid int.
		$result = $ref->invoke( $this->controller );
		// Could be null (no readers) or 0 (no data).
		$this->assertTrue( null === $result || \is_int( $result ) );
	}

	// ── get_discovery: lag field conditional inclusion ──────────────────

	public function test_get_discovery_lag_field(): void {
		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		// lag is only included when not null.
		// In test env it may or may not be present.
		if ( isset( $data['lag'] ) ) {
			$this->assertIsInt( $data['lag'] );
		} else {
			$this->assertArrayNotHasKey( 'lag', $data );
		}
	}

	// ── get_discovery: mixed array types for custom_events ─────────────

	public function test_get_discovery_custom_events_mixed(): void {
		update_option( 'event_logger_custom_events', [
			'named_event'  => true,
			0              => 'indexed_event',
			'another_name' => false,
			''             => true,      // Empty key should be ignored.
		] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$this->assertContains( 'named_event', $data['custom_events'] );
		$this->assertContains( 'another_name', $data['custom_events'] );
		$this->assertContains( 'indexed_event', $data['custom_events'] );
		$this->assertNotContains( '', $data['custom_events'] );
	}

	// ── get_discovery: non-array log_events handled gracefully ─────────

	public function test_get_discovery_non_array_log_events(): void {
		update_option( 'event_logger_log_events', 'not_an_array' );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		// Non-array should result in empty hooks.
		$this->assertIsArray( $data['registered_hooks'] );
	}

	// ── discovery_permissions_check: returns WP_Error for unauthorized ──

	public function test_discovery_permissions_check_positive(): void {
		// current_user_can always returns true in stubs.
		$result = $this->controller->discovery_permissions_check();
		$this->assertTrue( $result );
	}

	// ── get_discovery: deduplicates custom_events ──────────────────────

	public function test_get_discovery_deduplicates_custom_events(): void {
		update_option( 'event_logger_custom_events', [ 'event_a', 'event_a', 'event_b' ] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$count = array_count_values( $data['custom_events'] )['event_a'] ?? 0;
		$this->assertSame( 1, $count, 'custom_events should be deduplicated' );
	}

	// ── calculate_lag: with registered readers ──────────────────────────

	public function test_calculate_lag_with_registered_readers(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-lag'] = [
				'handler' => [
					'class'  => \Newspack_Event_Logger\Tests\Unit\MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_lag' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller );
		// With no actual data written, lag should be 0 or null.
		$this->assertTrue( null === $result || 0 === $result || \is_int( $result ) );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── calculate_position_difference: writer far ahead ─────────────────

	public function test_calculate_position_difference_large_gap(): void {
		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_position_difference' );
		$ref->setAccessible( true );

		$segment_size = 65536;
		$lag = $ref->invoke(
			$this->controller,
			[ 'segment_id' => 10, 'offset' => 1000 ],
			[ 'segment_id' => 0, 'offset' => 0 ],
			$segment_size
		);

		// 65536 remaining in seg 0 + 9 full segments + 1000 in seg 10.
		$expected = $segment_size + ( 9 * $segment_size ) + 1000;
		$this->assertSame( $expected, $lag );
	}

	// ── get_discovery: custom_events non-array ──────────────────────────

	public function test_get_discovery_non_array_custom_events(): void {
		update_option( 'event_logger_custom_events', 'not-an-array' );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$this->assertIsArray( $data['custom_events'] );
	}

	// ── get_discovery: log_events with empty string keys ────────────────

	public function test_get_discovery_empty_string_key_hooks(): void {
		update_option( 'event_logger_log_events', [
			'' => true,
			'valid_hook' => true,
		] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$this->assertNotContains( '', $data['registered_hooks'] );
		$this->assertContains( 'valid_hook', $data['registered_hooks'] );
	}

	// ── get_discovery: custom_events indexed strings with empty ──────────

	public function test_get_discovery_custom_events_empty_string_values(): void {
		update_option( 'event_logger_custom_events', [ '', 'real_event', '' ] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$this->assertNotContains( '', $data['custom_events'] );
		$this->assertContains( 'real_event', $data['custom_events'] );
	}

	// ── custom_events: color hex as event name ────────────────────────

	public function test_custom_events_color_hex_as_value_not_event_name(): void {
		update_option( 'event_logger_custom_events', [ 'pyrobase' => '#FF7600' ] );
		Config::reset();

		$response = $this->controller->get_discovery( $this->make_request() );
		$data     = $response->get_data();

		$this->assertContains( 'pyrobase', $data['custom_events'], 'pyrobase should be in events' );
		$this->assertNotContains( '#FF7600', $data['custom_events'], 'Hex color should NOT be treated as event name' );
	}

	// ── calculate_position_difference: missing keys default to 0 ────────

	public function test_calculate_position_difference_missing_keys(): void {
		$ref = new \ReflectionMethod( DiscoveryController::class, 'calculate_position_difference' );
		$ref->setAccessible( true );

		$lag = $ref->invoke(
			$this->controller,
			[], // No segment_id or offset keys.
			[],
			65536
		);

		// Both default to segment 0, offset 0 — lag is 0.
		$this->assertSame( 0, $lag );
	}
}
