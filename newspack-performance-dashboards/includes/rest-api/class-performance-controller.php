<?php
/**
 * Performance Controller
 *
 * Facade controller that coordinates all performance sub-controllers.
 *
 * @package Newspack_Performance_Dashboards
 */

namespace Newspack_Performance_Dashboards\REST;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facade controller that instantiates and registers all performance REST controllers.
 *
 * This class coordinates the following specialized controllers:
 * - Overview: Dashboard summary and aggregate stats
 * - URLs: URL listing and detail endpoints
 * - Requests: Individual request trace endpoints
 */
class PerformanceController {

	/**
	 * Register routes for all sub-controllers.
	 *
	 * @return void
	 */
	public function register_routes() {
		foreach ( [
			new OverviewController(),
			new UrlsController(),
			new RequestsController(),
		] as $controller ) {
			$controller->register_routes();
		}
	}
}
