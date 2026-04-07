<?php
/**
 * Tests for Newspack_Event_Logger\REST\FirehoseController.
 *
 * @package Event_Logger
 */

use Newspack_Event_Logger\REST\FirehoseController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( FirehoseController::class )]
class FirehoseControllerTest extends \PHPUnit\Framework\TestCase {

	private FirehoseController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']           = [];
		$GLOBALS['_wp_test_registered_routes'] = [];
		$GLOBALS['_wp_test_filters']           = [];
		$this->controller = new FirehoseController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']           = [];
		$GLOBALS['_wp_test_registered_routes'] = [];
		$GLOBALS['_wp_test_filters']           = [];

		// Reset Memcached singleton so heartbeat tests don't affect other tests.
		$ref = new \ReflectionClass( \Newspack_Event_Logger\Memcached::class );
		$memd = $ref->getProperty( 'memd' );
		$memd->setAccessible( true );
		$memd->setValue( null, null );
		$ext = $ref->getProperty( 'extension' );
		$ext->setAccessible( true );
		$ext->setValue( null, null );
		$init = $ref->getProperty( 'init_attempted' );
		$init->setAccessible( true );
		$init->setValue( null, false );

		Config::reset();
		parent::tearDown();
	}

	// ── register_routes ─────────────────────────────────────────────────

	public function test_register_routes_registers_logs_endpoint(): void {
		$this->controller->register_routes();
		$routes   = $GLOBALS['_wp_test_registered_routes'];
		$patterns = \array_column( $routes, 'route' );

		$this->assertContains( '/firehose/logs', $patterns );
	}

	public function test_register_routes_registers_status_endpoint(): void {
		$this->controller->register_routes();
		$routes   = $GLOBALS['_wp_test_registered_routes'];
		$patterns = \array_column( $routes, 'route' );

		$this->assertContains( '/firehose/status', $patterns );
	}

	public function test_register_routes_registers_heartbeat_endpoint(): void {
		$this->controller->register_routes();
		$routes   = $GLOBALS['_wp_test_registered_routes'];
		$patterns = \array_column( $routes, 'route' );

		$this->assertContains( '/firehose/heartbeat', $patterns );
	}

	public function test_register_routes_uses_correct_namespace(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			$this->assertSame( 'event-logger/v1', $route['namespace'] );
		}
	}

	public function test_register_routes_heartbeat_uses_post_method(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( '/firehose/heartbeat' === $route['route'] ) {
				$this->assertSame( 'POST', $route['args']['methods'] );
				return;
			}
		}
		$this->fail( 'Heartbeat route not found' );
	}

	// ── stream_permissions_check ────────────────────────────────────────

	public function test_stream_permissions_check_allowed(): void {
		// current_user_can always returns true in stubs, allowed_users empty = all admins allowed.
		$result = $this->controller->stream_permissions_check();
		$this->assertTrue( $result );
	}

	// ── get_available_logs ──────────────────────────────────────────────

	public function test_get_available_logs_returns_array(): void {
		$result = FirehoseController::get_available_logs();
		$this->assertIsArray( $result );
	}

	public function test_get_available_logs_with_registered_readers(): void {
		// Register readers via the filter.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-group'] = [
				'handler1' => [
					'class'   => 'TestHandler',
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [ 'requests.log', 'flames.log' ],
				],
			];
			return $readers;
		} );

		$result = FirehoseController::get_available_logs();
		$this->assertIsArray( $result );

		// Clean up filter.
		$GLOBALS['_wp_test_filters'] = [];
	}

	// ── get_default_log ─────────────────────────────────────────────────

	public function test_get_default_log_returns_string(): void {
		$result = FirehoseController::get_default_log();
		$this->assertIsString( $result );
	}

	// ── validate_log_name ───────────────────────────────────────────────

	public function test_validate_log_name_returns_bool(): void {
		$result = FirehoseController::validate_log_name( 'nonexistent' );
		$this->assertIsBool( $result );
	}

	// ── sanitize_log_param ──────────────────────────────────────────────

	public function test_sanitize_log_param_empty_input(): void {
		$result = $this->controller->sanitize_log_param( '' );
		$this->assertIsString( $result );
	}

	public function test_sanitize_log_param_strips_log_suffix(): void {
		// The method strips .log suffix and looks up by key.
		$result = $this->controller->sanitize_log_param( 'nonexistent.log' );
		// Falls back to default log.
		$this->assertIsString( $result );
	}

	// ── get_ip_hash ─────────────────────────────────────────────────────

	public function test_get_ip_hash_returns_8_char_string(): void {
		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
		$hash = FirehoseController::get_ip_hash();
		$this->assertSame( 8, \strlen( $hash ) );
	}

	public function test_get_ip_hash_is_consistent(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$hash1 = FirehoseController::get_ip_hash();
		$hash2 = FirehoseController::get_ip_hash();
		$this->assertSame( $hash1, $hash2 );
	}

	public function test_get_ip_hash_differs_by_ip(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$hash1 = FirehoseController::get_ip_hash();

		$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
		$hash2 = FirehoseController::get_ip_hash();

		$this->assertNotSame( $hash1, $hash2 );
	}

	public function test_get_ip_hash_handles_missing_remote_addr(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$hash = FirehoseController::get_ip_hash();
		$this->assertSame( 8, \strlen( $hash ) );
	}

	// ── get_logs endpoint ───────────────────────────────────────────────

	public function test_get_logs_returns_response(): void {
		$request  = new \WP_REST_Request( 'GET' );
		$response = $this->controller->get_logs( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	public function test_get_logs_returns_key_label_format(): void {
		// Register a reader so there's at least one log.
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-group'] = [
				'handler1' => [
					'class'   => 'TestHandler',
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [ 'requests.log' ],
				],
			];
			return $readers;
		} );

		$request  = new \WP_REST_Request( 'GET' );
		$response = $this->controller->get_logs( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		if ( ! empty( $data ) ) {
			$first = $data[0];
			$this->assertArrayHasKey( 'key', $first );
			$this->assertArrayHasKey( 'label', $first );
		}

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── get_status endpoint ─────────────────────────────────────────────

	public function test_get_status_empty_log_returns_error(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'log', '' );

		$result = $this->controller->get_status( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_logs', $result->get_error_code() );
	}

	public function test_get_status_with_valid_log(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'log', 'firehose.log' );

		$result = $this->controller->get_status( $request );
		// Should return a response (even if log dir doesn't exist, it won't crash).
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$data = $result instanceof \WP_REST_Response ? $result->get_data() : $result;
		$this->assertArrayHasKey( 'log_id', $data );
		$this->assertArrayHasKey( 'log_file', $data );
		$this->assertArrayHasKey( 'num_partitions', $data );
		$this->assertArrayHasKey( 'partitions', $data );
		$this->assertArrayHasKey( 'total_segments', $data );
		$this->assertArrayHasKey( 'total_size', $data );
	}

	public function test_get_status_log_id_strips_suffix(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'log', 'requests.log' );

		$result = $this->controller->get_status( $request );
		$data   = $result instanceof \WP_REST_Response ? $result->get_data() : $result;

		$this->assertSame( 'requests', $data['log_id'] );
		$this->assertSame( 'requests.log', $data['log_file'] );
	}

	// ── heartbeat endpoint ─────────────────────────────────────────────

	public function test_heartbeat_returns_response(): void {
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'slot', 1 );
		$request->set_param( 'aggregator', false );

		$response = $this->controller->heartbeat( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'slot', $data );
		$this->assertArrayHasKey( 'timestamp', $data );
		$this->assertSame( 1, $data['slot'] );
	}

	public function test_heartbeat_with_aggregator_flag(): void {
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'slot', 3 );
		$request->set_param( 'aggregator', true );

		$response = $this->controller->heartbeat( $request );

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertArrayHasKey( 'success', $data );
		$this->assertSame( 3, $data['slot'] );
	}

	public function test_heartbeat_timestamp_is_current(): void {
		$before = \time();
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'slot', 0 );
		$request->set_param( 'aggregator', false );

		$response = $this->controller->heartbeat( $request );
		$after    = \time();

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertGreaterThanOrEqual( $before, $data['timestamp'] );
		$this->assertLessThanOrEqual( $after, $data['timestamp'] );
	}

	// ── get_available_logs deeper paths ─────────────────────────────────

	public function test_get_available_logs_with_multiple_readers(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['group-a'] = [
				'handler_a' => [
					'class'   => \Newspack_Performance_Workers\Cron\RequestBuilder::class,
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [ 'requests.log', 'errors.log' ],
				],
			];
			$readers['group-b'] = [
				'handler_b' => [
					'class'   => \Newspack_Performance_Workers\Cron\FlameBuilder::class,
					'inputs'  => [ 'requests.log' ],
					'outputs' => [ 'flames.log' ],
				],
			];
			return $readers;
		} );

		$result = FirehoseController::get_available_logs();
		$this->assertIsArray( $result );

		// Should have deduped keys from all inputs/outputs.
		$this->assertArrayHasKey( 'firehose', $result );
		$this->assertArrayHasKey( 'requests', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'flames', $result );
		$this->assertSame( 'firehose.log', $result['firehose'] );
		$this->assertSame( 'requests.log', $result['requests'] );

		// Verify sorted by key.
		$keys = \array_keys( $result );
		$sorted = $keys;
		\sort( $sorted );
		$this->assertSame( $sorted, $keys );

		$GLOBALS['_wp_test_filters'] = [];
	}

	public function test_get_available_logs_skips_non_string_outputs(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['group'] = [
				'handler' => [
					'class'   => \Newspack_Performance_Workers\Cron\RequestBuilder::class,
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [ '', 'requests.log', 123, null ],
				],
			];
			return $readers;
		} );

		$result = FirehoseController::get_available_logs();
		// firehose.log (input) + requests.log (valid output) should pass through.
		// Empty string, 123, null in outputs should be filtered out by get_available_logs.
		$this->assertArrayHasKey( 'firehose', $result );
		$this->assertArrayHasKey( 'requests', $result );
		$this->assertCount( 2, $result );

		$GLOBALS['_wp_test_filters'] = [];
	}

	// ── get_logs formats correctly ──────────────────────────────────────

	public function test_get_logs_with_multiple_readers_formats_correctly(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['group'] = [
				'handler' => [
					'class'   => \Newspack_Performance_Workers\Cron\RequestBuilder::class,
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [ 'requests.log', 'flames.log' ],
				],
			];
			return $readers;
		} );

		$request  = new \WP_REST_Request( 'GET' );
		$response = $this->controller->get_logs( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertGreaterThanOrEqual( 1, \count( $data ) );

		foreach ( $data as $item ) {
			$this->assertArrayHasKey( 'key', $item );
			$this->assertArrayHasKey( 'label', $item );
			$this->assertIsString( $item['key'] );
			$this->assertIsString( $item['label'] );
		}

		$GLOBALS['_wp_test_filters'] = [];
	}

	// ── sanitize_log_param deeper paths ─────────────────────────────────

	public function test_sanitize_log_param_with_valid_key(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['group'] = [
				'handler' => [
					'class'   => \Newspack_Performance_Workers\Cron\RequestBuilder::class,
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [],
				],
			];
			return $readers;
		} );

		// Test with .log suffix — should strip and lookup by key.
		$result = $this->controller->sanitize_log_param( 'firehose.log' );
		$this->assertSame( 'firehose.log', $result );

		// Test with just the key name — should also find it.
		$result = $this->controller->sanitize_log_param( 'firehose' );
		$this->assertSame( 'firehose.log', $result );

		$GLOBALS['_wp_test_filters'] = [];
	}

	public function test_sanitize_log_param_nonexistent_falls_back(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['group'] = [
				'handler' => [
					'class'   => \Newspack_Performance_Workers\Cron\RequestBuilder::class,
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [],
				],
			];
			return $readers;
		} );

		$result = $this->controller->sanitize_log_param( 'nonexistent.log' );
		// Falls back to get_default_log which is the first available.
		$this->assertSame( 'firehose.log', $result );

		$GLOBALS['_wp_test_filters'] = [];
	}

	// ── validate_log_name ───────────────────────────────────────────────

	public function test_validate_log_name_with_registered_log(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['group'] = [
				'handler' => [
					'class'   => \Newspack_Performance_Workers\Cron\RequestBuilder::class,
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [],
				],
			];
			return $readers;
		} );

		$this->assertTrue( FirehoseController::validate_log_name( 'firehose' ) );
		$this->assertFalse( FirehoseController::validate_log_name( 'nonexistent' ) );

		$GLOBALS['_wp_test_filters'] = [];
	}

	// ── get_default_log ─────────────────────────────────────────────────

	public function test_get_default_log_with_registered_readers(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['group'] = [
				'handler' => [
					'class'   => \Newspack_Performance_Workers\Cron\RequestBuilder::class,
					'inputs'  => [ 'firehose.log' ],
					'outputs' => [ 'requests.log' ],
				],
			];
			return $readers;
		} );

		$result = FirehoseController::get_default_log();
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );

		$GLOBALS['_wp_test_filters'] = [];
	}
}
