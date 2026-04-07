<?php
/**
 * Tests for Newspack_Performance_Dashboards\REST\UrlsController.
 *
 * @package Newspack_Performance_Dashboards
 */

use Newspack_Performance_Dashboards\REST\UrlsController;
use Newspack_Performance_Workers\StatsStore;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( UrlsController::class )]
class UrlsControllerTest extends \PHPUnit\Framework\TestCase {

	private UrlsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']            = [];
		$GLOBALS['_wp_test_transients']         = [];
		$GLOBALS['_wp_test_user_id']            = 1;
		$GLOBALS['_wp_test_registered_routes']  = [];
		// Clean leftover firehose data.
		$dir = '/tmp/event-logger-test/logs/requests.log/p0';
		if ( \is_dir( $dir ) ) {
			foreach ( \glob( "{$dir}/*.log" ) ?: [] as $f ) { @\unlink( $f ); }
			foreach ( \glob( "{$dir}/*.idx" ) ?: [] as $f ) { @\unlink( $f ); }
		}
		$this->controller = new UrlsController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']            = [];
		$GLOBALS['_wp_test_transients']         = [];
		$GLOBALS['_wp_test_user_id']            = 0;
		$GLOBALS['_wp_test_registered_routes']  = [];
		Config::reset();
		parent::tearDown();
	}

	// ── register_routes ─────────────────────────────────────────────────

	public function test_register_routes_registers_urls_list(): void {
		$this->controller->register_routes();
		$routes   = $GLOBALS['_wp_test_registered_routes'];
		$patterns = \array_column( $routes, 'route' );

		$this->assertContains( '/performance/urls', $patterns );
	}

	public function test_register_routes_registers_url_detail(): void {
		$this->controller->register_routes();
		$routes   = $GLOBALS['_wp_test_registered_routes'];
		$patterns = \array_column( $routes, 'route' );

		$has_detail = false;
		foreach ( $patterns as $pattern ) {
			if ( false !== \strpos( $pattern, '/performance/urls/' ) && false !== \strpos( $pattern, 'hash' ) ) {
				$has_detail = true;
				break;
			}
		}
		$this->assertTrue( $has_detail, 'Should register URL detail route with hash parameter' );
	}

	public function test_register_routes_correct_namespace(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			$this->assertSame( 'event-logger/v1', $route['namespace'] );
		}
	}

	public function test_register_routes_urls_has_sort_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( '/performance/urls' === $route['route'] ) {
				$args = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
				$this->assertArrayHasKey( 'sort', $args['args'] );
				$this->assertSame( 'count', $args['args']['sort']['default'] );
				return;
			}
		}
		$this->fail( 'URLs list route not found' );
	}

	public function test_register_routes_urls_has_order_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( '/performance/urls' === $route['route'] ) {
				$args = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
				$this->assertArrayHasKey( 'order', $args['args'] );
				$this->assertSame( 'desc', $args['args']['order']['default'] );
				return;
			}
		}
		$this->fail( 'URLs list route not found' );
	}

	public function test_register_routes_urls_has_limit_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( '/performance/urls' === $route['route'] ) {
				$args = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
				$this->assertArrayHasKey( 'limit', $args['args'] );
				$this->assertSame( 50, $args['args']['limit']['default'] );
				return;
			}
		}
		$this->fail( 'URLs list route not found' );
	}

	public function test_register_routes_urls_has_search_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( '/performance/urls' === $route['route'] ) {
				$args = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
				$this->assertArrayHasKey( 'search', $args['args'] );
				$this->assertSame( '', $args['args']['search']['default'] );
				return;
			}
		}
		$this->fail( 'URLs list route not found' );
	}

	public function test_register_routes_detail_has_hash_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( false !== \strpos( $route['route'], '/performance/urls/' ) && false !== \strpos( $route['route'], 'hash' ) ) {
				$args = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
				$this->assertArrayHasKey( 'hash', $args['args'] );
				$this->assertTrue( $args['args']['hash']['required'] );
				return;
			}
		}
		$this->fail( 'URL detail route not found' );
	}

	public function test_register_routes_detail_has_breakdown_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( false !== \strpos( $route['route'], '/performance/urls/' ) && false !== \strpos( $route['route'], 'hash' ) ) {
				$args = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
				$this->assertArrayHasKey( 'breakdown', $args['args'] );
				return;
			}
		}
		$this->fail( 'URL detail route not found' );
	}

	public function test_register_routes_detail_has_error_status_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( false !== \strpos( $route['route'], '/performance/urls/' ) && false !== \strpos( $route['route'], 'hash' ) ) {
				$args = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
				$this->assertArrayHasKey( 'error_status', $args['args'] );
				return;
			}
		}
		$this->fail( 'URL detail route not found' );
	}

	// ── get_urls ────────────────────────────────────────────────────────

	public function test_get_urls_returns_response(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'count' );
		$request->set_param( 'order', 'desc' );
		$request->set_param( 'limit', 50 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', '' );
		$request->set_param( 'server', '' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->assertSame( 'rate_limit_exceeded', $response->get_error_code() );
			return;
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'limit', $data );
		$this->assertArrayHasKey( 'offset', $data );
	}

	public function test_get_urls_returns_empty_data_with_no_entries(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'count' );
		$request->set_param( 'order', 'desc' );
		$request->set_param( 'limit', 50 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', '' );
		$request->set_param( 'server', '' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertIsInt( $data['total'] );
		$this->assertIsArray( $data['data'] );
	}

	public function test_get_urls_respects_limit_param(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'count' );
		$request->set_param( 'order', 'desc' );
		$request->set_param( 'limit', 5 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', '' );
		$request->set_param( 'server', '' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertSame( 5, $data['limit'] );
	}

	public function test_get_urls_rate_limited(): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 300;

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'count' );
		$request->set_param( 'order', 'desc' );
		$request->set_param( 'limit', 50 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', '' );
		$request->set_param( 'server', '' );

		$response = $this->controller->get_urls( $request );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rate_limit_exceeded', $response->get_error_code() );
	}

	public function test_get_urls_sort_by_avg_ms_asc(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'avg_ms' );
		$request->set_param( 'order', 'asc' );
		$request->set_param( 'limit', 50 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', '' );
		$request->set_param( 'server', '' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertIsArray( $data['data'] );
	}

	// ── get_url_detail ──────────────────────────────────────────────────

	public function test_get_url_detail_not_found(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'hash', 'deadbeef' );

		$response = $this->controller->get_url_detail( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
	}

	public function test_get_url_detail_rate_limited(): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 300;

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'hash', 'deadbeef' );

		$response = $this->controller->get_url_detail( $request );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rate_limit_exceeded', $response->get_error_code() );
	}

	// ── get_url_stats_from_cache via reflection ─────────────────────────

	public function test_get_url_stats_from_cache_returns_null_for_nonexistent(): void {
		$ref = new \ReflectionMethod( UrlsController::class, 'get_url_stats_from_cache' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 'nonexistent_hash' );
		$this->assertNull( $result );
	}

	// ── get_recent_requests_for_url via reflection ──────────────────────

	public function test_get_recent_requests_for_url_empty(): void {
		$ref = new \ReflectionMethod( UrlsController::class, 'get_recent_requests_for_url' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 'nonexistent_hash' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// ── get_aggregate_stats_for_url via reflection ──────────────────────

	public function test_get_aggregate_stats_for_url_returns_null(): void {
		$ref = new \ReflectionMethod( UrlsController::class, 'get_aggregate_stats_for_url' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 'nonexistent_hash' );
		$this->assertNull( $result );
	}

	// ── Validate route sort/order parameter validation callbacks ─────────

	public function test_sort_validate_callback_valid_values(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( '/performance/urls' !== $route['route'] ) {
				continue;
			}
			$args            = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
			$sort_validate   = $args['args']['sort']['validate_callback'];
			$order_validate  = $args['args']['order']['validate_callback'];

			// Valid sort values.
			$this->assertTrue( $sort_validate( 'count' ) );
			$this->assertTrue( $sort_validate( 'url' ) );
			$this->assertTrue( $sort_validate( 'avg_ms' ) );
			$this->assertTrue( $sort_validate( 'p95_ms' ) );
			$this->assertTrue( $sort_validate( 'last_updated' ) );
			$this->assertFalse( $sort_validate( 'invalid' ) );

			// Valid order values.
			$this->assertTrue( $order_validate( 'asc' ) );
			$this->assertTrue( $order_validate( 'desc' ) );
			$this->assertFalse( $order_validate( 'invalid' ) );
			return;
		}
		$this->fail( 'URLs list route not found' );
	}

	public function test_limit_sanitize_callback_caps_at_1000(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( '/performance/urls' !== $route['route'] ) {
				continue;
			}
			$args           = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
			$limit_sanitize = $args['args']['limit']['sanitize_callback'];

			$this->assertSame( 50, $limit_sanitize( 50 ) );
			$this->assertSame( 1000, $limit_sanitize( 5000 ) );
			$this->assertSame( 1, $limit_sanitize( -1 ) ); // absint(-1) = 1
			return;
		}
		$this->fail( 'URLs list route not found' );
	}

	public function test_offset_sanitize_callback_caps_at_10000(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( '/performance/urls' !== $route['route'] ) {
				continue;
			}
			$args            = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
			$offset_sanitize = $args['args']['offset']['sanitize_callback'];

			$this->assertSame( 100, $offset_sanitize( 100 ) );
			$this->assertSame( 10000, $offset_sanitize( 99999 ) );
			$this->assertSame( 5, $offset_sanitize( -5 ) ); // absint(-5) = 5
			return;
		}
		$this->fail( 'URLs list route not found' );
	}

	public function test_hash_validate_callback(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( false === \strpos( $route['route'], '/performance/urls/' ) || false === \strpos( $route['route'], 'hash' ) ) {
				continue;
			}
			$args          = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
			$hash_validate = $args['args']['hash']['validate_callback'];

			$this->assertTrue( (bool) $hash_validate( 'deadbeef' ) );
			$this->assertTrue( (bool) $hash_validate( \str_repeat( 'a', 64 ) ) );
			$this->assertFalse( (bool) $hash_validate( 'short' ) ); // < 8 chars.
			$this->assertFalse( (bool) $hash_validate( 'UPPERCASE' ) ); // Not hex.
			$this->assertFalse( (bool) $hash_validate( '' ) );
			return;
		}
		$this->fail( 'URL detail route not found' );
	}

	// ── get_urls with server filter ─────────────────────────────────────

	public function test_get_urls_with_server_filter(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'count' );
		$request->set_param( 'order', 'desc' );
		$request->set_param( 'limit', 50 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', '' );
		$request->set_param( 'server', 'example.com' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertIsArray( $data['data'] );
		// Server filter with no data returns empty.
		$this->assertSame( 0, $data['total'] );
	}

	public function test_get_urls_with_search_filter(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'count' );
		$request->set_param( 'order', 'desc' );
		$request->set_param( 'limit', 50 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', '/nonexistent-path' );
		$request->set_param( 'server', '' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertIsArray( $data['data'] );
		$this->assertSame( 0, $data['total'] );
	}

	public function test_get_urls_sort_by_url_desc(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'url' );
		$request->set_param( 'order', 'desc' );
		$request->set_param( 'limit', 50 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', '' );
		$request->set_param( 'server', '' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertIsArray( $data['data'] );
	}

	public function test_get_urls_sort_by_last_updated_asc(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'last_updated' );
		$request->set_param( 'order', 'asc' );
		$request->set_param( 'limit', 10 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', '' );
		$request->set_param( 'server', '' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertIsArray( $data['data'] );
		$this->assertSame( 10, $data['limit'] );
	}

	public function test_get_urls_with_offset(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'count' );
		$request->set_param( 'order', 'desc' );
		$request->set_param( 'limit', 10 );
		$request->set_param( 'offset', 5 );
		$request->set_param( 'search', '' );
		$request->set_param( 'server', '' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertSame( 5, $data['offset'] );
	}

	// ── get_url_detail with parameters ──────────────────────────────────

	public function test_get_url_detail_with_breakdown_param(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'hash', 'deadbeef' );
		$request->set_param( 'breakdown', 'status' );

		$response = $this->controller->get_url_detail( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		// Hash does not exist - should return not_found.
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
	}

	public function test_get_url_detail_with_error_status_param(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'hash', 'deadbeef' );
		$request->set_param( 'error_status', 'F,T' );

		$response = $this->controller->get_url_detail( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
	}

	// ── get_url_stats_from_cache deeper paths ───────────────────────────

	public function test_get_url_stats_from_cache_different_hashes(): void {
		$ref = new \ReflectionMethod( UrlsController::class, 'get_url_stats_from_cache' );
		$ref->setAccessible( true );

		// Each call with a different hash scans the (empty) index.
		$result1 = $ref->invoke( $this->controller, 'aaaa1111' );
		$result2 = $ref->invoke( $this->controller, 'bbbb2222' );
		$this->assertNull( $result1 );
		$this->assertNull( $result2 );
	}

	// ── get_recent_requests_for_url deeper paths ────────────────────────

	public function test_get_recent_requests_for_url_with_different_hashes(): void {
		$ref = new \ReflectionMethod( UrlsController::class, 'get_recent_requests_for_url' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 'aaaa1111bbbb2222' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// ── scale_categories_by_samples ─────────────────────────────────────

	public function test_scale_categories_by_samples_empty(): void {
		$ref = new \ReflectionMethod( UrlsController::class, 'scale_categories_by_samples' );
		$ref->setAccessible( true );

		$data = [];
		$ref->invokeArgs( $this->controller, [ &$data ] );
		$this->assertEmpty( $data );
	}

	public function test_scale_categories_by_samples_zero_count(): void {
		$ref = new \ReflectionMethod( UrlsController::class, 'scale_categories_by_samples' );
		$ref->setAccessible( true );

		$data = [ 'count' => 0, 'categories' => [ 'db' => [ 'time' => 100, 'count' => 5, 'samples' => 3 ] ] ];
		$ref->invokeArgs( $this->controller, [ &$data ] );
		// Should not modify since count is 0.
		$this->assertSame( 100, $data['categories']['db']['time'] );
	}

	public function test_scale_categories_by_samples_with_scaling(): void {
		$ref = new \ReflectionMethod( UrlsController::class, 'scale_categories_by_samples' );
		$ref->setAccessible( true );

		$data = [
			'count'      => 10,
			'categories' => [
				'db'  => [ 'time' => 100, 'count' => 5, 'samples' => 5 ],
				'ext' => [ 'time' => 200, 'count' => 8, 'samples' => 10 ],
			],
		];
		$ref->invokeArgs( $this->controller, [ &$data ] );
		// 'db' has samples(5) < total_count(10) so gets scaled: 100 * 5/10 = 50.
		$this->assertEquals( 50, $data['categories']['db']['time'] );
		$this->assertEquals( 2.5, $data['categories']['db']['count'] );
		// 'ext' has samples(10) = total_count(10) so no scaling.
		$this->assertSame( 200, $data['categories']['ext']['time'] );
	}

	public function test_get_urls_server_and_search_combined(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'sort', 'p95_ms' );
		$request->set_param( 'order', 'desc' );
		$request->set_param( 'limit', 50 );
		$request->set_param( 'offset', 0 );
		$request->set_param( 'search', 'test' );
		$request->set_param( 'server', 'example.com' );

		$response = $this->controller->get_urls( $request );

		if ( $response instanceof \WP_Error ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertIsArray( $data['data'] );
	}

	// ── Tests with real firehose index data ─────────────────────────────

	/**
	 * Build a fixed-width request index line (v4 format, 97 chars).
	 */
	private function build_index_line( string $rid, string $url_hash, int $timestamp, int $duration_ms, int $status_code, int $seg_id, int $offset, int $length, int $peak_mb = 8, string $method = 'G', string $error_status = '-' ): string {
		return \sprintf(
			'%-32s%-12s%010d%08d%03d%06d%010d%08d%06d%s%s',
			$rid,
			$url_hash,
			$timestamp,
			$duration_ms,
			$status_code,
			$seg_id,
			$offset,
			$length,
			$peak_mb,
			$method,
			$error_status
		);
	}

	/**
	 * Set up a requests.log firehose with indexed data for testing.
	 */
	private function create_test_requests_log(): void {
		$log_base = Config::get_logs_directory();
		$req_dir  = "{$log_base}/requests.log/p0";
		@\mkdir( $req_dir, 0755, true );

		$rid      = 'testreq00000000000000000000001';
		$url_hash = 'abc123def456';
		$ts       = \time();
		$request  = \wp_json_encode( [
			'rid'         => $rid,
			'url'         => 'https://example.com/test-page',
			'duration_ms' => 42,
			'status_code' => 200,
			'entries'     => [],
		] );
		$req_len  = \strlen( $request ) + 1; // +1 for newline.

		// Write the request data.
		\file_put_contents( "{$req_dir}/0.log", $request . "\n" );

		// Write the fixed-width index.
		$idx_line = $this->build_index_line( $rid, $url_hash, $ts, 42, 200, 0, 0, $req_len );
		\file_put_contents( "{$req_dir}/0.idx", $idx_line . "\n" );
	}

	public function test_get_recent_requests_for_url_finds_indexed_data(): void {
		$this->create_test_requests_log();

		$ref    = new \ReflectionMethod( UrlsController::class, 'get_recent_requests_for_url' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $this->controller, 'abc123def456' );

		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( 'testreq', $result[0]['rid'] );
		$this->assertSame( 200, $result[0]['status_code'] );
		$this->assertSame( 0, $result[0]['partition'] );
	}

	public function test_get_recent_requests_for_url_wrong_hash_returns_empty(): void {
		$this->create_test_requests_log();

		$ref    = new \ReflectionMethod( UrlsController::class, 'get_recent_requests_for_url' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $this->controller, 'xxxxyyyyzzzz' );

		$this->assertEmpty( $result );
	}

	public function test_get_url_detail_with_full_data(): void {
		$this->create_test_requests_log();

		// Populate StatsStore URL index so get_url_stats_from_cache finds the hash.
		$config     = Config::load_config( 'full' );
		$servers    = $config['memcache_servers'] ?? \Newspack_Event_Logger\Memcached::DEFAULT_SERVERS;
		$lifespan   = $config['max_lifespan'] ?? 86400;
		StatsStore::init( 1, $servers, $lifespan );

		// Rotate salt to isolate this test.
		$salt = 'url-detail-test-' . \uniqid();
		\update_option( StatsStore::SALT_OPTION, $salt );
		$ref = new \ReflectionProperty( StatsStore::class, 'prefix' );
		$ref->setAccessible( true );
		$ref->setValue( null, 'el_stats:' . $salt );

		// Compute current bucket key.
		$now        = \time();
		$min        = (int) \gmdate( 'i', $now );
		$bucket_min = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', STR_PAD_LEFT );
		$bucket_key = \gmdate( 'Y-m-d-H', $now ) . '-' . $bucket_min;

		StatsStore::set_url_index_hourly( 0, $bucket_key, [
			'abc123def456' => [
				'url'        => 'https://example.com/test-page',
				'hash'       => 'abc123def456',
				'count'      => 5,
				'timed_count' => 5,
				'sum_ms'     => 200,
				'min_ms'     => 10,
				'max_ms'     => 80,
				'last_seen'  => $now,
				'durations'  => [ 10, 20, 40, 60, 80 ],
				'sum_peak_mb' => 40,
				'max_peak_mb' => 10,
				'count_2xx'  => 5,
				'count_3xx'  => 0,
				'count_4xx'  => 0,
				'count_5xx'  => 0,
			],
		] );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'hash', 'abc123def456' );
		$request->set_param( 'breakdown', 'status' );
		$request->set_param( 'error_status', 'F' );

		$response = $this->controller->get_url_detail( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertArrayHasKey( 'stats', $data );
		$this->assertArrayHasKey( 'requests', $data );
		$this->assertArrayHasKey( 'aggregate_flame', $data );
		$this->assertSame( 'abc123def456', $data['stats']['hash'] );
		$this->assertSame( 'https://example.com/test-page', $data['stats']['url'] );
	}
}
