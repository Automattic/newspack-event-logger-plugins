<?php
/**
 * Tests for Newspack_Event_Aggregator\REST\ServersController.
 *
 * @package Newspack_Event_Aggregator
 */

use Newspack_Event_Aggregator\REST\ServersController;
use Newspack_Event_Aggregator\ServerRegistry;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ServersController::class )]
class ServersControllerTest extends \PHPUnit\Framework\TestCase {

	private ServersController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 1;
		$GLOBALS['_wp_test_registered_routes'] = [];
		$GLOBALS['_wp_test_remote_response']   = null;
		// Reset ServerRegistry singleton cache.
		ServerRegistry::get_instance()->reset_cache();
		$this->controller = new ServersController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		$GLOBALS['_wp_test_registered_routes'] = [];
		unset( $GLOBALS['_wp_test_remote_response'] );
		ServerRegistry::get_instance()->reset_cache();
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
		// Should register list+create, single server CRUD, and test connection.
		$this->assertGreaterThanOrEqual( 3, count( $routes ) );
	}

	public function test_permissions_check_allowed(): void {
		$result = $this->controller->permissions_check();
		$this->assertTrue( $result );
	}

	public function test_get_items_empty(): void {
		$request  = $this->make_request();
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( $response->get_data() );
	}

	public function test_create_item_success(): void {
		$request = $this->make_request( [
			'id'            => 'test-server',
			'url'           => 'https://example.com',
			'auth_username' => 'admin',
			'auth_password' => 'secret123',
			'enabled'       => true,
			'logs'          => [ 'firehose.log' ],
		] );

		$response = $this->controller->create_item( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'test-server', $data['id'] );
	}

	public function test_create_item_invalid_id(): void {
		$request = $this->make_request( [
			'id'  => 'invalid id with spaces!!',
			'url' => 'https://example.com',
		] );

		$response = $this->controller->create_item( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_id', $response->get_error_code() );
	}

	public function test_create_item_duplicate_id(): void {
		// Create first server.
		$request1 = $this->make_request( [
			'id'  => 'server1',
			'url' => 'https://example.com',
		] );
		$this->controller->create_item( $request1 );

		// Reset cache so get_all re-reads.
		ServerRegistry::get_instance()->reset_cache();

		// Try duplicate.
		$request2 = $this->make_request( [
			'id'  => 'server1',
			'url' => 'https://other.com',
		] );
		$response = $this->controller->create_item( $request2 );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'already_exists', $response->get_error_code() );
	}

	public function test_get_item_success(): void {
		// Create a server first.
		$create_request = $this->make_request( [
			'id'  => 'myserver',
			'url' => 'https://myserver.example.com',
		] );
		$this->controller->create_item( $create_request );
		ServerRegistry::get_instance()->reset_cache();

		$get_request = $this->make_request( [ 'id' => 'myserver' ] );
		$response    = $this->controller->get_item( $get_request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'myserver', $data['id'] );
		$this->assertStringContainsString( 'myserver.example.com', $data['url'] );
		$this->assertArrayHasKey( 'has_credentials', $data );
	}

	public function test_get_item_not_found(): void {
		$request  = $this->make_request( [ 'id' => 'nonexistent' ] );
		$response = $this->controller->get_item( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'not_found', $response->get_error_code() );
	}

	public function test_update_item_success(): void {
		// Create a server.
		$create_req = $this->make_request( [
			'id'  => 'updatable',
			'url' => 'https://old.example.com',
		] );
		$this->controller->create_item( $create_req );
		ServerRegistry::get_instance()->reset_cache();

		// Update it.
		$update_req = new WP_REST_Request( 'PUT' );
		$update_req->set_param( 'id', 'updatable' );
		$update_req->set_param( 'url', 'https://new.example.com' );
		$update_req->set_param( 'enabled', false );

		$response = $this->controller->update_item( $update_req );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_update_item_not_found(): void {
		$request = new WP_REST_Request( 'PUT' );
		$request->set_param( 'id', 'ghost' );
		$request->set_param( 'url', 'https://ghost.example.com' );

		$response = $this->controller->update_item( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'not_found', $response->get_error_code() );
	}

	public function test_delete_item_success(): void {
		// Create a server.
		$create_req = $this->make_request( [
			'id'  => 'deleteme',
			'url' => 'https://deleteme.example.com',
		] );
		$this->controller->create_item( $create_req );
		ServerRegistry::get_instance()->reset_cache();

		// Delete it.
		$delete_req = new WP_REST_Request( 'DELETE' );
		$delete_req->set_param( 'id', 'deleteme' );

		$response = $this->controller->delete_item( $delete_req );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		// Verify it's gone.
		ServerRegistry::get_instance()->reset_cache();
		$get_req  = $this->make_request( [ 'id' => 'deleteme' ] );
		$get_resp = $this->controller->get_item( $get_req );
		$this->assertInstanceOf( WP_Error::class, $get_resp );
	}

	public function test_delete_item_not_found(): void {
		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', 'nonexistent' );

		$response = $this->controller->delete_item( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'not_found', $response->get_error_code() );
	}

	public function test_get_items_masks_credentials(): void {
		$create_req = $this->make_request( [
			'id'            => 'secure',
			'url'           => 'https://secure.example.com',
			'auth_username' => 'admin',
			'auth_password' => 'super_secret',
		] );
		$this->controller->create_item( $create_req );
		ServerRegistry::get_instance()->reset_cache();

		$list_req  = $this->make_request();
		$response  = $this->controller->get_items( $list_req );
		$data      = $response->get_data();

		$this->assertNotEmpty( $data );
		$server = reset( $data );
		$this->assertTrue( $server['has_credentials'] );
		// Must NOT contain the actual password.
		$this->assertArrayNotHasKey( 'auth_password', $server );
		$this->assertArrayNotHasKey( 'auth_username', $server );
	}

	public function test_test_connection_success(): void {
		// Create a server.
		$create_req = $this->make_request( [
			'id'  => 'testable',
			'url' => 'https://testable.example.com',
		] );
		$this->controller->create_item( $create_req );
		ServerRegistry::get_instance()->reset_cache();

		// Set up mock remote response.
		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [ 'registered_hooks' => [], 'custom_events' => [] ] ),
		];

		$test_req = $this->make_request( [ 'id' => 'testable' ] );
		$response = $this->controller->test_connection( $test_req );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'connected', $data['status'] );
	}

	public function test_test_connection_not_found(): void {
		$request  = $this->make_request( [ 'id' => 'noserver' ] );
		$response = $this->controller->test_connection( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'not_found', $response->get_error_code() );
	}

	public function test_test_connection_http_error(): void {
		$create_req = $this->make_request( [
			'id'  => 'failing',
			'url' => 'https://failing.example.com',
		] );
		$this->controller->create_item( $create_req );
		ServerRegistry::get_instance()->reset_cache();

		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 500 ],
			'body'     => '',
		];

		$test_req = $this->make_request( [ 'id' => 'failing' ] );
		$response = $this->controller->test_connection( $test_req );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'connection_failed', $response->get_error_code() );
	}

	public function test_validate_url_rejects_http(): void {
		$request = new WP_REST_Request( 'POST' );
		$result  = $this->controller->validate_url( 'http://insecure.com', $request, 'url' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_validate_url_rejects_empty(): void {
		$request = new WP_REST_Request( 'POST' );
		$result  = $this->controller->validate_url( '', $request, 'url' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_validate_url_accepts_https(): void {
		$request = new WP_REST_Request( 'POST' );
		$result  = $this->controller->validate_url( 'https://valid.example.com', $request, 'url' );
		$this->assertTrue( $result );
	}

	public function test_validate_url_optional_accepts_empty(): void {
		$request = new WP_REST_Request( 'POST' );
		$result  = $this->controller->validate_url_optional( '', $request, 'url' );
		$this->assertTrue( $result );
	}

	public function test_validate_url_optional_validates_non_empty(): void {
		$request = new WP_REST_Request( 'POST' );
		$result  = $this->controller->validate_url_optional( 'http://not-https.com', $request, 'url' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
