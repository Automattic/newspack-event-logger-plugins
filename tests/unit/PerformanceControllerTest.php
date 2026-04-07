<?php
/**
 * Tests for Newspack_Performance_Dashboards\REST\PerformanceController.
 *
 * @package Newspack_Performance_Dashboards
 */

use Newspack_Performance_Dashboards\REST\PerformanceController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerformanceController::class )]
class PerformanceControllerTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']            = [];
		$GLOBALS['_wp_test_registered_routes']  = [];
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']            = [];
		$GLOBALS['_wp_test_registered_routes']  = [];
		Config::reset();
		parent::tearDown();
	}

	public function test_register_routes_delegates_to_sub_controllers(): void {
		$controller = new PerformanceController();
		$controller->register_routes();

		// Should have registered routes from Overview, Urls, and Requests controllers.
		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertNotEmpty( $routes, 'Should register at least one route' );

		// Collect all route patterns.
		$patterns = \array_column( $routes, 'route' );

		// OverviewController registers /performance/overview.
		$this->assertTrue(
			\in_array( '/performance/overview', $patterns, true ),
			'Should register overview route'
		);

		// UrlsController registers /performance/urls.
		$this->assertTrue(
			\in_array( '/performance/urls', $patterns, true ),
			'Should register urls route'
		);

		// RequestsController registers requests search route.
		$has_requests_search = false;
		foreach ( $patterns as $pattern ) {
			if ( false !== \strpos( $pattern, '/performance/requests/search/' ) ) {
				$has_requests_search = true;
				break;
			}
		}
		$this->assertTrue( $has_requests_search, 'Should register requests search route' );
	}

	public function test_register_routes_uses_correct_namespace(): void {
		$controller = new PerformanceController();
		$controller->register_routes();

		$routes = $GLOBALS['_wp_test_registered_routes'];
		foreach ( $routes as $route ) {
			$this->assertSame(
				'event-logger/v1',
				$route['namespace'],
				'All routes should use event-logger/v1 namespace'
			);
		}
	}

	public function test_register_routes_can_be_called_twice(): void {
		$controller = new PerformanceController();
		$controller->register_routes();
		$count_first = \count( $GLOBALS['_wp_test_registered_routes'] );

		$controller->register_routes();
		$count_second = \count( $GLOBALS['_wp_test_registered_routes'] );

		// Second call doubles the routes (WordPress behavior).
		$this->assertSame( $count_first * 2, $count_second );
	}
}
