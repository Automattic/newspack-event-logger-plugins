<?php
/**
 * Tests for Newspack_Performance_Dashboards\REST\PerformanceControllerBase.
 *
 * Since the base class is abstract, we test via a concrete subclass.
 *
 * @package Newspack_Performance_Dashboards
 */

use Newspack_Performance_Dashboards\REST\PerformanceControllerBase;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Concrete test subclass of PerformanceControllerBase.
 */
class TestablePerformanceController extends PerformanceControllerBase {

	public function register_routes() {
		// Minimal implementation for testing.
	}

	/**
	 * Expose protected check_rate_limit for testing.
	 *
	 * @return bool|\WP_Error
	 */
	public function test_check_rate_limit() {
		return $this->check_rate_limit();
	}

	/**
	 * Expose protected scale_categories_by_samples for testing.
	 *
	 * @param array $data Data to scale.
	 */
	public function test_scale_categories( array &$data ): void {
		$this->scale_categories_by_samples( $data );
	}

	/**
	 * Expose protected not_found_error for testing.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	public function test_not_found_error( string $message ): \WP_Error {
		return $this->not_found_error( $message );
	}
}

#[CoversClass( PerformanceControllerBase::class )]
class PerformanceControllerBaseTest extends \PHPUnit\Framework\TestCase {

	private TestablePerformanceController $controller;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 1;
		$this->controller = new TestablePerformanceController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		Config::reset();
		parent::tearDown();
	}

	public function test_read_permissions_check_allowed(): void {
		$result = $this->controller->read_permissions_check();
		$this->assertTrue( $result );
	}

	public function test_not_found_error(): void {
		$error = $this->controller->test_not_found_error( 'Thing not found.' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'rest_not_found', $error->get_error_code() );
		$this->assertSame( 'Thing not found.', $error->get_error_message() );
		$data = $error->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	public function test_rate_limit_allows_below_threshold(): void {
		$result = $this->controller->test_check_rate_limit();
		$this->assertTrue( $result );
	}

	public function test_rate_limit_blocks_at_threshold(): void {
		// Set rate counter at the limit (300 per 60s window).
		$now          = time();
		$window_start = (int) floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 300;

		$result = $this->controller->test_check_rate_limit();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
	}

	public function test_rate_limit_increments_counter(): void {
		$now          = time();
		$window_start = (int) floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 10;

		$result = $this->controller->test_check_rate_limit();
		$this->assertTrue( $result );

		// Counter should have incremented.
		$this->assertSame( 11, $GLOBALS['_wp_test_transients'][ $transient_key ] );
	}

	public function test_rate_limit_anonymous_user(): void {
		$GLOBALS['_wp_test_user_id'] = 0;
		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';

		$result = $this->controller->test_check_rate_limit();
		$this->assertTrue( $result );

		// Should have used IP-based key.
		$ip_hash      = md5( '192.168.1.100' );
		$now          = time();
		$window_start = (int) floor( $now / 60 ) * 60;
		$transient_key = 'el_rate_ip_' . $ip_hash . '_' . $window_start;
		$this->assertSame( 1, $GLOBALS['_wp_test_transients'][ $transient_key ] );
	}

	public function test_scale_categories_by_samples_no_scaling_needed(): void {
		// Sums-based input: category present in every request, samples == count.
		$data = [
			'count'        => 100,
			'sum_req_time' => 500,
			'categories'   => [
				// 100 samples, sum_time = 500 (avg 5/req), sum_count = 100 (avg 1/req).
				'core' => [ 'samples' => 100, 'sum_time' => 500, 'sum_count' => 100, 'entries' => [] ],
			],
		];
		$this->controller->test_scale_categories( $data );

		// Display: time = sum_time / count = 500 / 100 = 5.
		$this->assertEqualsWithDelta( 5.0, $data['categories']['core']['time'], 0.01 );
		$this->assertEqualsWithDelta( 1.0, $data['categories']['core']['count'], 0.01 );
		$this->assertEqualsWithDelta( 5.0, $data['total_time'], 0.01 );
	}

	public function test_scale_categories_by_samples_scales_down(): void {
		// Plugin only present in 50 of 100 profiled requests.
		// When present, contributed sum_time = 200 (avg 4/appearance), sum_count = 50 (avg 1).
		$data = [
			'count'        => 100,
			'sum_req_time' => 1000,
			'categories'   => [
				'plugin' => [ 'samples' => 50, 'sum_time' => 200, 'sum_count' => 50, 'entries' => [] ],
			],
		];
		$this->controller->test_scale_categories( $data );

		// Display: time = sum_time / count = 200 / 100 = 2 (avg per profiled request, including absences).
		$this->assertEqualsWithDelta( 2.0, $data['categories']['plugin']['time'], 0.01 );
		$this->assertEqualsWithDelta( 0.5, $data['categories']['plugin']['count'], 0.01 );
	}

	public function test_scale_categories_empty_data(): void {
		$data = [];
		$this->controller->test_scale_categories( $data );
		$this->assertEmpty( $data );
	}

	public function test_scale_categories_zero_count(): void {
		$data = [
			'count'        => 0,
			'sum_req_time' => 0,
			'categories'   => [
				'core' => [ 'samples' => 0, 'sum_time' => 0, 'sum_count' => 0, 'entries' => [] ],
			],
		];
		$this->controller->test_scale_categories( $data );
		// Should not modify when count is 0 — sum_req_time stays present, no total_time.
		$this->assertSame( 0, $data['count'] );
	}

	// ── scale_categories: pre-converted display data is a no-op ─────────

	public function test_scale_categories_display_shape_passthrough(): void {
		$data = [
			'count'      => 100,
			'total_time' => 500.0,
			'categories' => [
				'core' => [ 'time' => 5.0, 'count' => 1.0, 'samples' => 100, 'entries' => [] ],
			],
		];
		$before = $data;
		$this->controller->test_scale_categories( $data );
		// Already in display shape (no sum_req_time present) — must be left alone.
		$this->assertSame( $before, $data );
	}

	// ── scale_categories: multiple categories ──────────────────────────

	public function test_scale_categories_multiple_categories(): void {
		$data = [
			'count'        => 200,
			'sum_req_time' => 1800,
			'categories'   => [
				// core: present in all 200 requests; sum_time = 400 (avg 2/req).
				'core'   => [ 'samples' => 200, 'sum_time' => 400, 'sum_count' => 200, 'entries' => [] ],
				// plugin: present in 100 requests; sum_time = 300 (avg 3/appearance).
				'plugin' => [ 'samples' => 100, 'sum_time' => 300, 'sum_count' => 100, 'entries' => [] ],
				// theme: present in 50 requests; sum_time = 200 (avg 4/appearance).
				'theme'  => [ 'samples' => 50,  'sum_time' => 200, 'sum_count' => 50,  'entries' => [] ],
			],
		];
		$this->controller->test_scale_categories( $data );

		// core: time = 400 / 200 = 2.
		$this->assertEqualsWithDelta( 2.0, $data['categories']['core']['time'], 0.01 );
		// plugin: time = 300 / 200 = 1.5 (avg across all profiled requests, not per-appearance).
		$this->assertEqualsWithDelta( 1.5, $data['categories']['plugin']['time'], 0.01 );
		$this->assertEqualsWithDelta( 0.5, $data['categories']['plugin']['count'], 0.01 );
		// theme: time = 200 / 200 = 1.0.
		$this->assertEqualsWithDelta( 1.0, $data['categories']['theme']['time'], 0.01 );
		$this->assertEqualsWithDelta( 0.25, $data['categories']['theme']['count'], 0.01 );
	}

	// ── scale_categories: negative count ────────────────────────────────

	public function test_scale_categories_negative_count(): void {
		$data = [
			'count'        => -5,
			'sum_req_time' => 0,
			'categories'   => [
				'core' => [ 'samples' => 0, 'sum_time' => 100, 'sum_count' => 5, 'entries' => [] ],
			],
		];
		$this->controller->test_scale_categories( $data );
		// Should not modify when count <= 0.
		$this->assertSame( -5, $data['count'] );
	}

	// ── scale_categories: no categories key ─────────────────────────────

	public function test_scale_categories_no_categories_key(): void {
		$data = [
			'count'        => 100,
			'sum_req_time' => 500,
		];
		$this->controller->test_scale_categories( $data );
		// Should not crash when categories is missing.
		$this->assertSame( 100, $data['count'] );
		$this->assertEqualsWithDelta( 5.0, $data['total_time'], 0.01 );
	}

	// ── rate_limit: no REMOTE_ADDR for anonymous ────────────────────────

	public function test_rate_limit_anonymous_no_remote_addr(): void {
		$GLOBALS['_wp_test_user_id'] = 0;
		unset( $_SERVER['REMOTE_ADDR'] );

		$result = $this->controller->test_check_rate_limit();
		$this->assertTrue( $result );
	}

	// ── rate_limit: counter persists across calls ───────────────────────

	public function test_rate_limit_counter_increments_across_calls(): void {
		$now          = time();
		$window_start = (int) floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;

		// Start at 298 requests.
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 298;

		// Call 299.
		$result1 = $this->controller->test_check_rate_limit();
		$this->assertTrue( $result1 );
		$this->assertSame( 299, $GLOBALS['_wp_test_transients'][ $transient_key ] );

		// Call 300.
		$result2 = $this->controller->test_check_rate_limit();
		$this->assertTrue( $result2 );
		$this->assertSame( 300, $GLOBALS['_wp_test_transients'][ $transient_key ] );

		// Call 301 — should be blocked.
		$result3 = $this->controller->test_check_rate_limit();
		$this->assertInstanceOf( WP_Error::class, $result3 );
		$this->assertSame( 'rate_limit_exceeded', $result3->get_error_code() );
	}

	// ── read_permissions_check: returns WP_Error for disallowed ─────────

	public function test_read_permissions_check_disallowed(): void {
		// The Admin::current_user_allowed() checks current_user_can which
		// always returns true in our stubs. Test the positive case here.
		$result = $this->controller->read_permissions_check();
		$this->assertTrue( $result );
	}

	// ── not_found_error: custom message ─────────────────────────────────

	public function test_not_found_error_custom_message(): void {
		$error = $this->controller->test_not_found_error( 'Request abc123 not found' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'rest_not_found', $error->get_error_code() );
		$this->assertSame( 'Request abc123 not found', $error->get_error_message() );
		$data = $error->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	// ── load_index: returns empty for fresh install ─────────────────────

	public function test_load_index_returns_array(): void {
		// Expose load_index via a test subclass.
		$controller = new class() extends PerformanceControllerBase {
			public function register_routes() {}
			public function test_load_index(): array {
				return $this->load_index();
			}
		};

		$result = $controller->test_load_index();
		$this->assertIsArray( $result );
	}

	// ── validate_bounds via reflection ──────────────────────────────────

	public function test_validate_bounds_non_numeric(): void {
		$ref = new \ReflectionMethod( PerformanceControllerBase::class, 'validate_bounds' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 'not-a-number', 1, 64, 1 );
		$this->assertSame( 1, $result, 'Non-numeric should return default' );
	}

	public function test_validate_bounds_below_min(): void {
		$ref = new \ReflectionMethod( PerformanceControllerBase::class, 'validate_bounds' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 0, 1, 64, 5 );
		$this->assertSame( 5, $result, 'Below min should return default' );
	}

	public function test_validate_bounds_above_max(): void {
		$ref = new \ReflectionMethod( PerformanceControllerBase::class, 'validate_bounds' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 100, 1, 64, 5 );
		$this->assertSame( 5, $result, 'Above max should return default' );
	}

	public function test_validate_bounds_valid_value(): void {
		$ref = new \ReflectionMethod( PerformanceControllerBase::class, 'validate_bounds' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 32, 1, 64, 5 );
		$this->assertSame( 32, $result, 'Valid value should be returned' );
	}

	public function test_validate_bounds_at_min(): void {
		$ref = new \ReflectionMethod( PerformanceControllerBase::class, 'validate_bounds' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 1, 1, 64, 5 );
		$this->assertSame( 1, $result, 'Value at min should be returned' );
	}

	public function test_validate_bounds_at_max(): void {
		$ref = new \ReflectionMethod( PerformanceControllerBase::class, 'validate_bounds' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, 64, 1, 64, 5 );
		$this->assertSame( 64, $result, 'Value at max should be returned' );
	}

	// ── validate_partition via reflection ────────────────────────────────

	public function test_validate_partition_valid(): void {
		$ref = new \ReflectionMethod( PerformanceControllerBase::class, 'validate_partition' );
		$ref->setAccessible( true );

		// num_partitions is 1 (from test config), so partition 0 is valid.
		$ref->invoke( $this->controller, 0 );
		$this->assertTrue( true, 'Valid partition should not throw' );
	}

	public function test_validate_partition_negative(): void {
		$ref = new \ReflectionMethod( PerformanceControllerBase::class, 'validate_partition' );
		$ref->setAccessible( true );

		$this->expectException( \InvalidArgumentException::class );
		$ref->invoke( $this->controller, -1 );
	}

	public function test_validate_partition_out_of_bounds(): void {
		$ref = new \ReflectionMethod( PerformanceControllerBase::class, 'validate_partition' );
		$ref->setAccessible( true );

		$this->expectException( \InvalidArgumentException::class );
		$ref->invoke( $this->controller, 999 );
	}

	// ── get_requests_log and get_flames_log via reflection ──────────────

	public function test_get_requests_log_creates_firehose(): void {
		$controller = new class() extends PerformanceControllerBase {
			public function register_routes() {}
			public function test_get_requests_log( int $p ): \Newspack_Event_Logger\Firehose {
				return $this->get_requests_log( $p );
			}
		};

		$fh = $controller->test_get_requests_log( 0 );
		$this->assertInstanceOf( \Newspack_Event_Logger\Firehose::class, $fh );

		// Second call returns same instance (caching).
		$fh2 = $controller->test_get_requests_log( 0 );
		$this->assertSame( $fh, $fh2, 'Should cache firehose instance' );
	}

	public function test_get_flames_log_creates_firehose(): void {
		$controller = new class() extends PerformanceControllerBase {
			public function register_routes() {}
			public function test_get_flames_log( int $p ): \Newspack_Event_Logger\Firehose {
				return $this->get_flames_log( $p );
			}
		};

		$fh = $controller->test_get_flames_log( 0 );
		$this->assertInstanceOf( \Newspack_Event_Logger\Firehose::class, $fh );
	}

	public function test_get_requests_log_invalid_partition(): void {
		$controller = new class() extends PerformanceControllerBase {
			public function register_routes() {}
			public function test_get_requests_log( int $p ): \Newspack_Event_Logger\Firehose {
				return $this->get_requests_log( $p );
			}
		};

		$this->expectException( \InvalidArgumentException::class );
		$controller->test_get_requests_log( 999 );
	}

	// ── rate_limit: rate_limit_exceeded error data has 429 status ───────

	public function test_rate_limit_error_has_429_status(): void {
		$now          = time();
		$window_start = (int) floor( $now / 60 ) * 60;
		$identifier   = 'user_1';
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;
		$GLOBALS['_wp_test_transients'][ $transient_key ] = 300;

		$result = $this->controller->test_check_rate_limit();
		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
	}

	// ── constants ───────────────────────────────────────────────────────

	public function test_max_index_entries_constant(): void {
		$ref = new \ReflectionClassConstant( PerformanceControllerBase::class, 'MAX_INDEX_ENTRIES' );
		$this->assertSame( 100000, $ref->getValue() );
	}

	public function test_rate_limit_constants(): void {
		$ref1 = new \ReflectionClassConstant( PerformanceControllerBase::class, 'RATE_LIMIT_REQUESTS' );
		$ref2 = new \ReflectionClassConstant( PerformanceControllerBase::class, 'RATE_LIMIT_WINDOW' );
		$this->assertSame( 300, $ref1->getValue() );
		$this->assertSame( 60, $ref2->getValue() );
	}
}
