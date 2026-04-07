<?php
/**
 * Tests for Newspack_Event_Aggregator\REST\StatusController.
 *
 * @package Newspack_Event_Aggregator
 */

use Newspack_Event_Aggregator\REST\StatusController;
use Newspack_Event_Aggregator\ServerRegistry;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( StatusController::class )]
class StatusControllerTest extends \PHPUnit\Framework\TestCase {

	private StatusController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 1;
		$GLOBALS['_wp_test_registered_routes'] = [];
		ServerRegistry::get_instance()->reset_cache();
		$this->controller = new StatusController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		$GLOBALS['_wp_test_registered_routes'] = [];
		ServerRegistry::get_instance()->reset_cache();
		Config::reset();
		parent::tearDown();
	}

	public function test_register_routes(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertNotEmpty( $routes );
		$this->assertSame( 'event-aggregator/v1', $routes[0]['namespace'] );
		$this->assertSame( '/status', $routes[0]['route'] );
	}

	public function test_permissions_check_allowed(): void {
		$result = $this->controller->permissions_check();
		$this->assertTrue( $result );
	}

	public function test_get_status_empty_servers(): void {
		$request  = new WP_REST_Request( 'GET' );
		$response = $this->controller->get_status( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertEmpty( $data );
	}

	public function test_get_status_with_servers(): void {
		// Register a server via options.
		$servers = [
			'spoke1' => [
				'url'           => 'https://spoke1.example.com',
				'auth_username' => '',
				'auth_password' => '',
				'enabled'       => true,
				'logs'          => [ 'firehose.log' ],
			],
		];
		update_option( 'event_logger_aggregator_servers', $servers );
		ServerRegistry::get_instance()->reset_cache();

		$request  = new WP_REST_Request( 'GET' );
		$response = $this->controller->get_status( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'spoke1', $data );
		$this->assertSame( 'spoke1', $data['spoke1']['id'] );
		$this->assertSame( 'https://spoke1.example.com', $data['spoke1']['url'] );
		$this->assertTrue( $data['spoke1']['enabled'] );
		$this->assertArrayHasKey( 'partitions', $data['spoke1'] );
	}
}
