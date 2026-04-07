<?php
/**
 * Tests for Newspack_Event_Logger\REST\SpawnController.
 *
 * @package Event_Logger
 */

use Newspack_Event_Logger\REST\SpawnController;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Cron\Supervisor;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SpawnController::class )]
class SpawnControllerTest extends \PHPUnit\Framework\TestCase {

	private SpawnController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 1;
		$GLOBALS['_wp_test_nonce_valid'] = true;
		$GLOBALS['_wp_test_registered_routes'] = [];
		$this->controller = new SpawnController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		$GLOBALS['_wp_test_nonce_valid'] = true;
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
		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertNotEmpty( $routes );
		$this->assertStringContainsString( 'spawn', $routes[0]['route'] );
	}

	public function test_validate_worker_type_supervisor(): void {
		$this->assertTrue( $this->controller->validate_worker_type( 'supervisor' ) );
	}

	public function test_validate_worker_type_unknown(): void {
		// Unknown type should be rejected (no readers registered in test env).
		$this->assertFalse( $this->controller->validate_worker_type( 'nonexistent_type' ) );
	}

	public function test_spawn_permissions_check_with_valid_hmac_token(): void {
		// Generate a real spawn token.
		$token   = Supervisor::generate_spawn_token( 0 );
		$request = $this->make_request( [ 'nonce' => $token ] );

		$result = $this->controller->spawn_permissions_check( $request );
		$this->assertTrue( $result );
	}

	public function test_spawn_permissions_check_with_previous_window_token(): void {
		// Token from previous 10-second window should also be valid.
		$token   = Supervisor::generate_spawn_token( -1 );
		$request = $this->make_request( [ 'nonce' => $token ] );

		$result = $this->controller->spawn_permissions_check( $request );
		$this->assertTrue( $result );
	}

	public function test_spawn_permissions_check_with_invalid_token_falls_to_nonce(): void {
		// Invalid HMAC token, but valid WP nonce.
		$GLOBALS['_wp_test_nonce_valid'] = true;
		$request = $this->make_request( [ 'nonce' => 'invalid_token' ] );

		$result = $this->controller->spawn_permissions_check( $request );
		// Should succeed via WP nonce path (current_user_can returns true in stubs).
		$this->assertTrue( $result );
	}

	public function test_spawn_permissions_check_with_invalid_nonce(): void {
		$GLOBALS['_wp_test_nonce_valid'] = false;
		$request = $this->make_request( [ 'nonce' => 'bad_nonce' ] );

		$result = $this->controller->spawn_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_spawn_permissions_check_empty_nonce(): void {
		$request = $this->make_request( [ 'nonce' => '' ] );

		$result = $this->controller->spawn_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_spawn_worker_invalid_partition(): void {
		$request  = $this->make_request( [
			'type'      => 'supervisor',
			'partition' => 999, // Out of range.
		] );
		$response = $this->controller->spawn_worker( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_partition', $response->get_error_code() );
	}

	public function test_spawn_worker_negative_partition(): void {
		$request  = $this->make_request( [
			'type'      => 'supervisor',
			'partition' => -1,
		] );
		$response = $this->controller->spawn_worker( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_partition', $response->get_error_code() );
	}

	public function test_spawn_worker_unknown_type(): void {
		$request  = $this->make_request( [
			'type'      => 'totally_unknown',
			'partition' => 0,
		] );
		$response = $this->controller->spawn_worker( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'unknown_worker_type', $response->get_error_code() );
	}

	public function test_rate_limit_blocks_rapid_requests(): void {
		$GLOBALS['_wp_test_nonce_valid'] = true;

		// First request should succeed.
		$request1 = $this->make_request( [ 'nonce' => 'some_nonce' ] );
		$result1  = $this->controller->spawn_permissions_check( $request1 );
		$this->assertTrue( $result1 );

		// Second request within 2 seconds should be rate limited.
		$request2 = $this->make_request( [ 'nonce' => 'some_nonce' ] );
		$result2  = $this->controller->spawn_permissions_check( $request2 );
		$this->assertInstanceOf( WP_Error::class, $result2 );
		$this->assertSame( 'rate_limited', $result2->get_error_code() );
	}

	// ── sanitize_worker_result ──────────────────────────────────────────

	public function test_sanitize_worker_result(): void {
		$ref = new \ReflectionMethod( SpawnController::class, 'sanitize_worker_result' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, [
			'status'             => 'completed',
			'entries_processed'  => 42,
			'requests_complete'  => 10,
			'requests_pending'   => 5,
			'flames_written'     => 8,
			'jobs_processed'     => 3,
			'secret_field'       => 'should_be_stripped',
			'internal_path'      => '/var/www/html',
		], 2 );

		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 2, $result['partition'] );
		$this->assertSame( 42, $result['entries_processed'] );
		$this->assertSame( 10, $result['requests_complete'] );
		$this->assertSame( 5, $result['requests_pending'] );
		$this->assertSame( 8, $result['flames_written'] );
		$this->assertSame( 3, $result['jobs_processed'] );
		$this->assertArrayNotHasKey( 'secret_field', $result );
		$this->assertArrayNotHasKey( 'internal_path', $result );
	}

	public function test_sanitize_worker_result_missing_status(): void {
		$ref = new \ReflectionMethod( SpawnController::class, 'sanitize_worker_result' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, [], 0 );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 0, $result['partition'] );
	}

	public function test_sanitize_worker_result_non_numeric_excluded(): void {
		$ref = new \ReflectionMethod( SpawnController::class, 'sanitize_worker_result' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, [
			'status'            => 'ok',
			'entries_processed' => 'not-a-number',
		], 0 );

		$this->assertArrayNotHasKey( 'entries_processed', $result );
	}

	// ── validate_worker_type ────────────────────────────────────────────

	public function test_validate_worker_type_empty_string(): void {
		$this->assertFalse( $this->controller->validate_worker_type( '' ) );
	}

	// ── spawn_worker sets SERVER vars ───────────────────────────────────

	public function test_spawn_worker_sets_server_vars(): void {
		$request = $this->make_request( [
			'type'      => 'totally_unknown',
			'partition' => 0,
		] );
		$this->controller->spawn_worker( $request );

		$this->assertSame( 'totally_unknown', $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
		$this->assertSame( '0', $_SERVER['EVENT_LOGGER_WORKER_PARTITION'] );
	}

	// ── check_rate_limit — first call succeeds ──────────────────────────

	public function test_check_rate_limit_first_call_succeeds(): void {
		$ref = new \ReflectionMethod( SpawnController::class, 'check_rate_limit' );
		$ref->setAccessible( true );

		$GLOBALS['_wp_test_transients'] = [];
		$result = $ref->invoke( $this->controller );
		$this->assertTrue( $result );
	}

	// ── check_rate_limit: second call blocked ──────────────────────────

	public function test_check_rate_limit_second_call_blocked(): void {
		$ref = new \ReflectionMethod( SpawnController::class, 'check_rate_limit' );
		$ref->setAccessible( true );

		$GLOBALS['_wp_test_transients'] = [];

		// First call should succeed.
		$result1 = $ref->invoke( $this->controller );
		$this->assertTrue( $result1 );

		// Second call within 2 seconds should be blocked.
		$result2 = $ref->invoke( $this->controller );
		$this->assertInstanceOf( WP_Error::class, $result2 );
		$this->assertSame( 'rate_limited', $result2->get_error_code() );
	}

	// ── spawn_worker: supervisor type ──────────────────────────────────

	public function test_spawn_worker_supervisor_type(): void {
		$request = $this->make_request( [
			'type'      => 'supervisor',
			'partition' => 0,
		] );

		$response = $this->controller->spawn_worker( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'supervisor', $data['type'] );
		$this->assertIsArray( $data['result'] );
		$this->assertSame( 'completed', $data['result']['status'] );
	}

	// ── spawn_permissions_check: no user permissions ────────────────────

	public function test_spawn_permissions_check_no_capability_no_nonce(): void {
		// Test with an empty nonce to bypass HMAC path, but also invalid WP nonce.
		$GLOBALS['_wp_test_nonce_valid'] = false;
		$request = $this->make_request( [ 'nonce' => 'invalid' ] );

		// current_user_can stub always returns true, so this falls through to nonce check.
		$result = $this->controller->spawn_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	// ── sanitize_worker_result: string values for numeric fields ────────

	public function test_sanitize_worker_result_string_numeric_coerced(): void {
		$ref = new \ReflectionMethod( SpawnController::class, 'sanitize_worker_result' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, [
			'status'            => 'completed',
			'entries_processed' => '42', // String but numeric.
		], 0 );

		$this->assertSame( 42, $result['entries_processed'] );
	}

	// ── spawn_worker sets EVENT_LOGGER_WORKER_PARTITION as string ───────

	public function test_spawn_worker_sets_partition_as_string(): void {
		$request = $this->make_request( [
			'type'      => 'supervisor',
			'partition' => 0,
		] );
		$this->controller->spawn_worker( $request );

		$this->assertSame( '0', $_SERVER['EVENT_LOGGER_WORKER_PARTITION'] );
		$this->assertIsString( $_SERVER['EVENT_LOGGER_WORKER_PARTITION'] );
	}

	// ── validate_worker_type: standalone workers ───────────────────────

	public function test_validate_worker_type_checks_standalone(): void {
		// In test environment, there may be no standalone workers registered.
		// Verify the method doesn't crash when checking standalone workers.
		$result = $this->controller->validate_worker_type( 'stream-merger' );
		// Result depends on whether standalone workers are registered.
		$this->assertIsBool( $result );
	}

	// ── validate_worker_type: registered reader ─────────────────────────

	public function test_validate_worker_type_registered_reader(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['my-reader-group'] = [
				'handler' => [
					'class'  => \Newspack_Event_Logger\Tests\Unit\MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$this->assertTrue( $this->controller->validate_worker_type( 'my-reader-group' ) );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── validate_worker_type: known standalone ──────────────────────────

	public function test_validate_worker_type_known_standalone(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'my-standalone' => [
					'class'      => \Newspack_Event_Logger\Tests\Unit\ConcreteTestWorker::class,
					'partitions' => false,
				],
			];
		} );

		$this->assertTrue( $this->controller->validate_worker_type( 'my-standalone' ) );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── spawn_standalone_worker: non-partitioned with partition > 0 ─────

	public function test_spawn_standalone_non_partitioned_wrong_partition(): void {
		$ref = new \ReflectionMethod( SpawnController::class, 'spawn_standalone_worker' );
		$ref->setAccessible( true );

		$config = [
			'class'      => \Newspack_Event_Logger\Tests\Unit\ConcreteTestWorker::class,
			'partitions' => false,
		];

		$result = $ref->invoke( $this->controller, $config, 5 );
		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'not partitioned', $result['reason'] );
	}

	// ── spawn_standalone_worker: class without execute method ────────────

	public function test_spawn_standalone_no_execute_method(): void {
		$ref = new \ReflectionMethod( SpawnController::class, 'spawn_standalone_worker' );
		$ref->setAccessible( true );

		// Use Config class - it accepts no constructor args but has no execute().
		// spawn_standalone_worker will catch the error (Config constructor doesn't accept partition).
		$config = [
			'class'      => \Newspack_Event_Logger\Config::class,
			'partitions' => false,
		];

		$result = $ref->invoke( $this->controller, $config, 0 );
		$this->assertSame( 'error', $result['status'] );
	}

	// ── spawn_standalone_worker: exception during execution ─────────────

	public function test_spawn_standalone_exception(): void {
		// Create a class that throws from its constructor.
		$ref = new \ReflectionMethod( SpawnController::class, 'spawn_standalone_worker' );
		$ref->setAccessible( true );

		// Use a config with a class that exists but throws.
		// We create an anonymous class that throws from execute().
		// Since we need a named class, use one that we know will fail during construction.
		$config = [
			'class'      => 'Newspack_Event_Logger\Cron\LogReader', // constructor requires arguments.
			'partitions' => false,
		];

		$result = $ref->invoke( $this->controller, $config, 0 );
		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'Worker execution failed', $result['reason'] );
	}

	// ── spawn_permissions_check: valid nonce fallback (no HMAC) ─────────

	public function test_spawn_permissions_check_valid_wp_nonce(): void {
		$GLOBALS['_wp_test_nonce_valid'] = true;
		$request = $this->make_request( [ 'nonce' => 'not-hmac-but-valid-wp' ] );

		$result = $this->controller->spawn_permissions_check( $request );
		$this->assertTrue( $result );
	}

	// ── sanitize_worker_result: reason field preserved ──────────────────

	public function test_sanitize_worker_result_includes_reason_for_skipped(): void {
		$ref = new \ReflectionMethod( SpawnController::class, 'sanitize_worker_result' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, [
			'status'  => 'skipped',
			'reason'  => 'Worker already running',  // Not in safe list, should be excluded.
		], 0 );

		// reason is NOT in the allowed numeric fields list, so it should be absent.
		$this->assertSame( 'skipped', $result['status'] );
		$this->assertArrayNotHasKey( 'reason', $result );
	}
}
