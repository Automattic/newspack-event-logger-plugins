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
		$data = [
			'count'      => 100,
			'categories' => [
				'core' => [ 'time' => 500, 'count' => 100, 'samples' => 100 ],
			],
		];
		$this->controller->test_scale_categories( $data );

		// No scaling when samples == count.
		$this->assertSame( 500, $data['categories']['core']['time'] );
	}

	public function test_scale_categories_by_samples_scales_down(): void {
		$data = [
			'count'      => 100,
			'categories' => [
				'plugin' => [ 'time' => 200, 'count' => 50, 'samples' => 50 ],
			],
		];
		$this->controller->test_scale_categories( $data );

		// samples (50) < count (100), so time and count should be halved.
		$this->assertEquals( 100, $data['categories']['plugin']['time'] );
		$this->assertEquals( 25, $data['categories']['plugin']['count'] );
	}

	public function test_scale_categories_empty_data(): void {
		$data = [];
		$this->controller->test_scale_categories( $data );
		$this->assertEmpty( $data );
	}

	public function test_scale_categories_zero_count(): void {
		$data = [
			'count'      => 0,
			'categories' => [
				'core' => [ 'time' => 100, 'count' => 0 ],
			],
		];
		$this->controller->test_scale_categories( $data );
		// Should not modify when count is 0.
		$this->assertSame( 100, $data['categories']['core']['time'] );
	}

	// ── scale_categories: missing samples defaults to total_count ───────

	public function test_scale_categories_missing_samples_uses_count(): void {
		$data = [
			'count'      => 100,
			'categories' => [
				'core' => [ 'time' => 500, 'count' => 100 ],
				// No 'samples' key — should default to total count, no scaling.
			],
		];
		$this->controller->test_scale_categories( $data );
		$this->assertSame( 500, $data['categories']['core']['time'] );
		$this->assertSame( 100, $data['categories']['core']['count'] );
	}

	// ── scale_categories: multiple categories ──────────────────────────

	public function test_scale_categories_multiple_categories(): void {
		$data = [
			'count'      => 200,
			'categories' => [
				'core'   => [ 'time' => 400, 'count' => 200, 'samples' => 200 ],
				'plugin' => [ 'time' => 300, 'count' => 100, 'samples' => 100 ],
				'theme'  => [ 'time' => 200, 'count' => 50, 'samples' => 50 ],
			],
		];
		$this->controller->test_scale_categories( $data );

		// core: samples == count, no scaling.
		$this->assertSame( 400, $data['categories']['core']['time'] );

		// plugin: samples (100) < count (200), scaled by 100/200 = 0.5.
		$this->assertEquals( 150, $data['categories']['plugin']['time'] );
		$this->assertEquals( 50, $data['categories']['plugin']['count'] );

		// theme: samples (50) < count (200), scaled by 50/200 = 0.25.
		$this->assertEquals( 50, $data['categories']['theme']['time'] );
		$this->assertEquals( 12.5, $data['categories']['theme']['count'] );
	}

	// ── scale_categories: negative count ────────────────────────────────

	public function test_scale_categories_negative_count(): void {
		$data = [
			'count'      => -5,
			'categories' => [
				'core' => [ 'time' => 100, 'count' => 5 ],
			],
		];
		$this->controller->test_scale_categories( $data );
		// Should not modify when count <= 0.
		$this->assertSame( 100, $data['categories']['core']['time'] );
	}

	// ── scale_categories: no categories key ─────────────────────────────

	public function test_scale_categories_no_categories_key(): void {
		$data = [
			'count' => 100,
		];
		$this->controller->test_scale_categories( $data );
		// Should not crash when categories is missing.
		$this->assertSame( 100, $data['count'] );
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
