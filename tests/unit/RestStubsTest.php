<?php
/**
 * Tests for WordPress REST API stubs in bootstrap.php.
 *
 * Verifies the test infrastructure works correctly.
 *
 * @package Event_Logger
 */

class RestStubsTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		$GLOBALS['_wp_test_nonce_valid'] = true;
		$GLOBALS['_wp_test_registered_routes'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		$GLOBALS['_wp_test_nonce_valid'] = true;
		$GLOBALS['_wp_test_registered_routes'] = [];
		unset( $GLOBALS['_wp_test_remote_response'] );
		unset( $GLOBALS['_wp_test_current_user_login'] );
		parent::tearDown();
	}

	// ── WP_REST_Server constants ──────────────────────────────

	public function test_wp_rest_server_constants(): void {
		$this->assertSame( 'GET', WP_REST_Server::READABLE );
		$this->assertSame( 'POST', WP_REST_Server::CREATABLE );
		$this->assertSame( 'PUT, PATCH', WP_REST_Server::EDITABLE );
		$this->assertSame( 'DELETE', WP_REST_Server::DELETABLE );
	}

	// ── WP_REST_Request ──────────────────────────────────────

	public function test_wp_rest_request_params(): void {
		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_param( 'key', 'value' );

		$this->assertSame( 'value', $request->get_param( 'key' ) );
		$this->assertNull( $request->get_param( 'missing' ) );
		$this->assertTrue( $request->has_param( 'key' ) );
		$this->assertFalse( $request->has_param( 'missing' ) );
		$this->assertSame( [ 'key' => 'value' ], $request->get_params() );
	}

	public function test_wp_rest_request_method(): void {
		$request = new WP_REST_Request( 'DELETE' );
		$this->assertSame( 'DELETE', $request->get_method() );
	}

	public function test_wp_rest_request_headers(): void {
		$request = new WP_REST_Request();
		$request->set_header( 'Content-Type', 'application/json' );
		$this->assertSame( 'application/json', $request->get_header( 'content-type' ) );
	}

	// ── WP_REST_Response ─────────────────────────────────────

	public function test_wp_rest_response_constructor(): void {
		$response = new WP_REST_Response( [ 'ok' => true ], 201 );
		$this->assertSame( [ 'ok' => true ], $response->get_data() );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_wp_rest_response_setters(): void {
		$response = new WP_REST_Response();
		$response->set_data( [ 'test' => 1 ] );
		$response->set_status( 404 );
		$response->header( 'X-Custom', 'value' );

		$this->assertSame( [ 'test' => 1 ], $response->get_data() );
		$this->assertSame( 404, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertSame( 'value', $headers['X-Custom'] );
	}

	// ── WP_Error ─────────────────────────────────────────────

	public function test_wp_error(): void {
		$error = new WP_Error( 'test_code', 'Test message', [ 'status' => 400 ] );
		$this->assertSame( 'test_code', $error->get_error_code() );
		$this->assertSame( 'Test message', $error->get_error_message() );
		$this->assertSame( [ 'status' => 400 ], $error->get_error_data() );
	}

	public function test_is_wp_error(): void {
		$error = new WP_Error( 'code', 'msg' );
		$this->assertTrue( is_wp_error( $error ) );
		$this->assertFalse( is_wp_error( 'string' ) );
		$this->assertFalse( is_wp_error( null ) );
		$this->assertFalse( is_wp_error( new WP_REST_Response() ) );
	}

	// ── WP_REST_Controller ───────────────────────────────────

	public function test_wp_rest_controller_base(): void {
		$controller = new WP_REST_Controller();
		$this->assertNull( $controller->get_items( new WP_REST_Request() ) );
	}

	// ── REST Functions ───────────────────────────────────────

	public function test_register_rest_route(): void {
		register_rest_route( 'test/v1', '/endpoint', [ 'methods' => 'GET' ] );
		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertCount( 1, $routes );
		$this->assertSame( 'test/v1', $routes[0]['namespace'] );
		$this->assertSame( '/endpoint', $routes[0]['route'] );
	}

	public function test_rest_ensure_response_wraps_array(): void {
		$response = rest_ensure_response( [ 'data' => true ] );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( [ 'data' => true ], $response->get_data() );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_rest_ensure_response_passes_through(): void {
		$original = new WP_REST_Response( 'test', 201 );
		$result   = rest_ensure_response( $original );
		$this->assertSame( $original, $result );
	}

	public function test_rest_authorization_required_code(): void {
		$this->assertSame( 401, rest_authorization_required_code() );
	}

	public function test_rest_sanitize_boolean(): void {
		$this->assertTrue( rest_sanitize_boolean( true ) );
		$this->assertTrue( rest_sanitize_boolean( 'yes' ) );
		$this->assertTrue( rest_sanitize_boolean( '1' ) );
		$this->assertTrue( rest_sanitize_boolean( 'true' ) );
		$this->assertFalse( rest_sanitize_boolean( false ) );
		$this->assertFalse( rest_sanitize_boolean( 'false' ) );
		$this->assertFalse( rest_sanitize_boolean( '0' ) );
		$this->assertFalse( rest_sanitize_boolean( '' ) );
	}

	// ── User & Auth Functions ────────────────────────────────

	public function test_get_current_user_id(): void {
		$GLOBALS['_wp_test_user_id'] = 42;
		$this->assertSame( 42, get_current_user_id() );
	}

	public function test_get_current_user_id_default(): void {
		unset( $GLOBALS['_wp_test_user_id'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_wp_verify_nonce_controllable(): void {
		$GLOBALS['_wp_test_nonce_valid'] = true;
		$this->assertTrue( wp_verify_nonce( 'any', 'action' ) );

		$GLOBALS['_wp_test_nonce_valid'] = false;
		$this->assertFalse( wp_verify_nonce( 'any', 'action' ) );
	}

	public function test_wp_get_current_user(): void {
		$GLOBALS['_wp_test_user_id']            = 5;
		$GLOBALS['_wp_test_current_user_login'] = 'testadmin';
		$user = wp_get_current_user();
		$this->assertSame( 5, $user->ID );
		$this->assertSame( 'testadmin', $user->user_login );
	}

	// ── Utility Functions ────────────────────────────────────

	public function test_absint(): void {
		$this->assertSame( 5, absint( -5 ) );
		$this->assertSame( 0, absint( 0 ) );
		$this->assertSame( 10, absint( '10' ) );
		$this->assertSame( 3, absint( 3.7 ) );
	}

	public function test_wp_hash(): void {
		$this->assertSame( md5( 'test' ), wp_hash( 'test' ) );
	}

	// ── Transient Functions ──────────────────────────────────

	public function test_transient_set_get_delete(): void {
		$this->assertFalse( get_transient( 'test_key' ) );

		set_transient( 'test_key', 'test_value', 300 );
		$this->assertSame( 'test_value', get_transient( 'test_key' ) );

		delete_transient( 'test_key' );
		$this->assertFalse( get_transient( 'test_key' ) );
	}

	// ── Remote Request Functions ─────────────────────────────

	public function test_wp_remote_get_default(): void {
		$response = wp_remote_get( 'https://example.com' );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( '', wp_remote_retrieve_body( $response ) );
	}

	public function test_wp_remote_get_custom(): void {
		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 404 ],
			'body'     => 'Not found',
		];
		$response = wp_remote_get( 'https://example.com' );
		$this->assertSame( 404, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( 'Not found', wp_remote_retrieve_body( $response ) );
	}
}
