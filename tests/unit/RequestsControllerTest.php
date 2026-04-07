<?php
/**
 * Tests for Newspack_Performance_Dashboards\REST\RequestsController.
 *
 * @package Newspack_Performance_Dashboards
 */

use Newspack_Performance_Dashboards\REST\RequestsController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RequestsController::class )]
class RequestsControllerTest extends \PHPUnit\Framework\TestCase {

	private RequestsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']            = [];
		$GLOBALS['_wp_test_transients']         = [];
		$GLOBALS['_wp_test_user_id']            = 1;
		$GLOBALS['_wp_test_registered_routes']  = [];
		// Clean any leftover firehose data from previous tests.
		$log_base = '/tmp/event-logger-test/logs';
		foreach ( [ 'requests.log', 'flames.log' ] as $log ) {
			$dir = "{$log_base}/{$log}/p0";
			if ( \is_dir( $dir ) ) {
				foreach ( \glob( "{$dir}/*.log" ) ?: [] as $f ) { @\unlink( $f ); }
				foreach ( \glob( "{$dir}/*.idx" ) ?: [] as $f ) { @\unlink( $f ); }
			}
		}
		$this->controller = new RequestsController();
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

	public function test_register_routes_registers_search(): void {
		$this->controller->register_routes();
		$routes   = $GLOBALS['_wp_test_registered_routes'];
		$patterns = \array_column( $routes, 'route' );

		$has_search = false;
		foreach ( $patterns as $pattern ) {
			if ( false !== \strpos( $pattern, '/performance/requests/search/' ) ) {
				$has_search = true;
				break;
			}
		}
		$this->assertTrue( $has_search, 'Should register requests search route' );
	}

	public function test_register_routes_registers_get_request(): void {
		$this->controller->register_routes();
		$routes   = $GLOBALS['_wp_test_registered_routes'];
		$patterns = \array_column( $routes, 'route' );

		// Should have a route matching /performance/requests/{rid} (without /search/).
		$has_get = false;
		foreach ( $patterns as $pattern ) {
			if ( false !== \strpos( $pattern, '/performance/requests/' )
				&& false === \strpos( $pattern, '/search/' ) ) {
				$has_get = true;
				break;
			}
		}
		$this->assertTrue( $has_get, 'Should register single request route' );
	}

	public function test_register_routes_correct_namespace(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			$this->assertSame( 'event-logger/v1', $route['namespace'] );
		}
	}

	public function test_register_routes_search_has_rid_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( false !== \strpos( $route['route'], '/search/' ) ) {
				$args = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
				$this->assertArrayHasKey( 'rid', $args['args'] );
				$this->assertTrue( $args['args']['rid']['required'] );
				return;
			}
		}
		$this->fail( 'Search route not found' );
	}

	public function test_register_routes_get_has_partition_arg(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];

		foreach ( $routes as $route ) {
			if ( false !== \strpos( $route['route'], '/performance/requests/' )
				&& false === \strpos( $route['route'], '/search/' ) ) {
				$args = isset( $route['args'][0] ) ? $route['args'][0] : $route['args'];
				$this->assertArrayHasKey( 'partition', $args['args'] );
				$this->assertTrue( $args['args']['partition']['required'] );
				return;
			}
		}
		$this->fail( 'Get request route not found' );
	}

	// ── search_request ──────────────────────────────────────────────────

	public function test_search_request_not_found(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', 'nonexistent-rid-12345' );

		$response = $this->controller->search_request( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
	}

	public function test_search_request_rate_limited(): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 300;

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', 'test-rid' );

		$response = $this->controller->search_request( $request );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rate_limit_exceeded', $response->get_error_code() );
	}

	// ── get_request ─────────────────────────────────────────────────────

	public function test_get_request_invalid_partition(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', 'test-rid' );
		$request->set_param( 'partition', 999 );

		$response = $this->controller->get_request( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
		$this->assertSame( 'Invalid partition.', $response->get_error_message() );
	}

	public function test_get_request_not_found_in_valid_partition(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', 'nonexistent-rid' );
		$request->set_param( 'partition', 0 );

		$response = $this->controller->get_request( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
		$this->assertSame( 'Request not found.', $response->get_error_message() );
	}

	public function test_get_request_rate_limited(): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 300;

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', 'test-rid' );
		$request->set_param( 'partition', 0 );

		$response = $this->controller->get_request( $request );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rate_limit_exceeded', $response->get_error_code() );
	}

	// ── find_request_index_entry via reflection ─────────────────────────

	public function test_find_request_index_entry_returns_null_for_empty(): void {
		$ref = new \ReflectionMethod( RequestsController::class, 'find_request_index_entry' );
		$ref->setAccessible( true );

		$entries_count = 0;
		$args   = [ 0, 'nonexistent', &$entries_count ];
		$result = $ref->invokeArgs( $this->controller, $args );
		$this->assertNull( $result );
	}

	// ── find_flame_for_rid via reflection ────────────────────────────────

	public function test_find_flame_for_rid_returns_null_for_nonexistent(): void {
		$ref = new \ReflectionMethod( RequestsController::class, 'find_flame_for_rid' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 'nonexistent-rid' );
		$this->assertNull( $result );
	}

	// ── find_request_in_partition via reflection ─────────────────────────

	public function test_find_request_in_partition_returns_null_for_empty(): void {
		$ref = new \ReflectionMethod( RequestsController::class, 'find_request_in_partition' );
		$ref->setAccessible( true );

		$entries_count = 0;
		$args   = [ 0, 'nonexistent', &$entries_count ];
		$result = $ref->invokeArgs( $this->controller, $args );
		$this->assertNull( $result );
	}

	// ── search_request scans all partitions ─────────────────────────────

	public function test_search_request_scans_partitions_and_not_found(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', 'missing-rid-abc123' );

		$response = $this->controller->search_request( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
		$this->assertSame( 'Request not found.', $response->get_error_message() );
	}

	// ── get_request with partition=0 not found ──────────────────────────

	public function test_get_request_partition_zero_rid_missing(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', 'completely-missing-rid' );
		$request->set_param( 'partition', 0 );

		$response = $this->controller->get_request( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
		$this->assertSame( 'Request not found.', $response->get_error_message() );
	}

	// ── find_request_index_entry with varied rids ───────────────────────

	public function test_find_request_index_entry_different_rids(): void {
		$ref = new \ReflectionMethod( RequestsController::class, 'find_request_index_entry' );
		$ref->setAccessible( true );

		$entries_count = 0;
		$args1  = [ 0, 'rid-alpha-001', &$entries_count ];
		$result = $ref->invokeArgs( $this->controller, $args1 );
		$this->assertNull( $result );
		$this->assertSame( 0, $entries_count );

		$entries_count = 0;
		$args2  = [ 0, 'rid-beta-002', &$entries_count ];
		$result = $ref->invokeArgs( $this->controller, $args2 );
		$this->assertNull( $result );
	}

	// ── find_flame_for_rid with varied rids ─────────────────────────────

	public function test_find_flame_for_rid_different_rids(): void {
		$ref = new \ReflectionMethod( RequestsController::class, 'find_flame_for_rid' );
		$ref->setAccessible( true );

		$result1 = $ref->invoke( $this->controller, 'rid-alpha-001' );
		$result2 = $ref->invoke( $this->controller, 'rid-beta-002' );
		$this->assertNull( $result1 );
		$this->assertNull( $result2 );
	}

	// ── find_request_in_partition with varied rids ──────────────────────

	public function test_find_request_in_partition_different_rids(): void {
		$ref = new \ReflectionMethod( RequestsController::class, 'find_request_in_partition' );
		$ref->setAccessible( true );

		$entries_count = 0;
		$args   = [ 0, 'rid-gamma-003', &$entries_count ];
		$result = $ref->invokeArgs( $this->controller, $args );
		$this->assertNull( $result );
	}

	// ── get_request with out-of-range partition ─────────────────────────

	public function test_get_request_partition_out_of_range(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', 'test-rid' );
		$request->set_param( 'partition', 50 );

		$response = $this->controller->get_request( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
		$this->assertSame( 'Invalid partition.', $response->get_error_message() );
	}

	// ── search_request with short rid ───────────────────────────────────

	public function test_search_request_with_short_rid(): void {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', 'x' );

		$response = $this->controller->search_request( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_not_found', $response->get_error_code() );
	}

	// ── Tests with real firehose index data ─────────────────────────────

	private function build_request_index_line( string $rid, string $url_hash, int $seg_id, int $offset, int $length ): string {
		return \sprintf(
			'%-32s%-12s%010d%08d%03d%06d%010d%08d%06dG-',
			$rid, $url_hash, \time(), 42, 200, $seg_id, $offset, $length, 8
		);
	}

	private function build_flame_index_line( string $rid, string $url_hash, int $seg_id, int $offset, int $length ): string {
		return \sprintf(
			'%-32s%-12s%06d%010d%08d',
			$rid, $url_hash, $seg_id, $offset, $length
		);
	}

	private function create_test_firehose_data(): string {
		$log_base = Config::get_logs_directory();
		$req_dir  = "{$log_base}/requests.log/p0";
		$flame_dir = "{$log_base}/flames.log/p0";
		@\mkdir( $req_dir, 0755, true );
		@\mkdir( $flame_dir, 0755, true );

		$rid      = 'findme00000000000000000000001';
		$url_hash = 'aabb11223344';

		// Write request data.
		$request_json = \wp_json_encode( [
			'rid'         => $rid,
			'url'         => 'https://example.com/found',
			'duration_ms' => 55,
			'status_code' => 200,
			'entries'     => [ [ 'k' => 'process (start)', 'ts' => \microtime( true ) ] ],
		] );
		$req_len = \strlen( $request_json ) + 1;
		\file_put_contents( "{$req_dir}/0.log", $request_json . "\n" );
		\file_put_contents( "{$req_dir}/0.idx", $this->build_request_index_line( $rid, $url_hash, 0, 0, $req_len ) . "\n" );

		// Write flame data.
		$flame_json = \wp_json_encode( [
			'name'     => 'request',
			'value'    => 55,
			'children' => [],
		] );
		$flame_len = \strlen( $flame_json ) + 1;
		\file_put_contents( "{$flame_dir}/0.log", $flame_json . "\n" );
		\file_put_contents( "{$flame_dir}/0.idx", $this->build_flame_index_line( $rid, $url_hash, 0, 0, $flame_len ) . "\n" );

		return $rid;
	}

	public function test_find_request_index_entry_finds_match(): void {
		$rid = $this->create_test_firehose_data();

		$ref = new \ReflectionMethod( RequestsController::class, 'find_request_index_entry' );
		$ref->setAccessible( true );

		$entries_count = 0;
		$args   = [ 0, $rid, &$entries_count ];
		$result = $ref->invokeArgs( $this->controller, $args );

		$this->assertNotNull( $result );
		$this->assertSame( $rid, $result['rid'] );
		$this->assertSame( 0, $result['partition'] );
		$this->assertSame( 'aabb11223344', $result['url_hash'] );
	}

	public function test_find_request_in_partition_finds_and_reads_data(): void {
		$rid = $this->create_test_firehose_data();

		$ref = new \ReflectionMethod( RequestsController::class, 'find_request_in_partition' );
		$ref->setAccessible( true );

		$entries_count = 0;
		$args   = [ 0, $rid, &$entries_count ];
		$result = $ref->invokeArgs( $this->controller, $args );

		$this->assertNotNull( $result );
		$this->assertSame( $rid, $result['rid'] );
		$this->assertSame( 'https://example.com/found', $result['url'] );
		$this->assertArrayHasKey( 'flame_data', $result );
		$this->assertSame( 'request', $result['flame_data']['name'] );
	}

	public function test_find_flame_for_rid_finds_match(): void {
		$rid = $this->create_test_firehose_data();

		$ref    = new \ReflectionMethod( RequestsController::class, 'find_flame_for_rid' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $this->controller, $rid );

		$this->assertNotNull( $result );
		$this->assertSame( 'request', $result['name'] );
		$this->assertSame( 55, $result['value'] );
	}

	public function test_search_request_finds_indexed_request(): void {
		$rid = $this->create_test_firehose_data();

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', $rid );

		$response = $this->controller->search_request( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertSame( $rid, $data['rid'] );
		$this->assertSame( 0, $data['partition'] );
	}

	public function test_get_request_finds_indexed_request(): void {
		$rid = $this->create_test_firehose_data();

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'rid', $rid );
		$request->set_param( 'partition', 0 );

		$response = $this->controller->get_request( $request );

		if ( $response instanceof \WP_Error && 'rate_limit_exceeded' === $response->get_error_code() ) {
			$this->markTestSkipped( 'Rate limited' );
		}

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$this->assertSame( $rid, $data['rid'] );
		$this->assertSame( 'https://example.com/found', $data['url'] );
		$this->assertArrayHasKey( 'flame_data', $data );
	}
}
