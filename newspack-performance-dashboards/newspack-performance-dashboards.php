<?php
/**
 * Plugin Name: Newspack Performance Dashboards
 * Description: Dashboard visualization for performance data. Provides flame graphs, URL stats, leaderboards, and real-time views.
 * Version: 2.4.24
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-performance-dashboards
 * Domain Path: /languages/
 * Requires Plugins: newspack-event-logger, newspack-performance-workers
 *
 * @package Newspack_Performance_Dashboards
 */

\defined( 'ABSPATH' ) || exit;

// Check dependency: Event Logger Core must be active.
// Try to load Core's autoloader if class isn't available yet (handles plugin load order).
if ( ! \class_exists( 'Newspack_Event_Logger\\Firehose' ) ) {
	$core_autoloader = \WP_PLUGIN_DIR . '/newspack-event-logger/vendor/autoload.php';
	if ( \file_exists( $core_autoloader ) ) {
		require_once $core_autoloader;
	}
}

if ( ! \class_exists( 'Newspack_Event_Logger\\Firehose' ) ) {
	\add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>';
		\esc_html_e( 'Newspack Performance Dashboards requires Newspack Event Logger plugin to be active.', 'newspack-performance-dashboards' );
		echo '</p></div>';
	} );
	return;
}

// Check dependency: Performance Workers must be active.
// Try to load Performance Workers autoloader if class isn't available yet (handles plugin load order).
if ( ! \class_exists( 'Newspack_Performance_Workers\\StatsStore' ) ) {
	$workers_autoloader = \WP_PLUGIN_DIR . '/newspack-performance-workers/vendor/autoload.php';
	if ( \file_exists( $workers_autoloader ) ) {
		require_once $workers_autoloader;
	}
}

if ( ! \class_exists( 'Newspack_Performance_Workers\\StatsStore' ) ) {
	\add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>';
		\esc_html_e( 'Newspack Performance Dashboards requires Newspack Performance Workers plugin to be active.', 'newspack-performance-dashboards' );
		echo '</p></div>';
	} );
	return;
}

if ( ! \defined( 'NEWSPACK_PERFORMANCE_DASHBOARDS_VERSION' ) ) {
	\define( 'NEWSPACK_PERFORMANCE_DASHBOARDS_VERSION', '2.4.24' );
}

if ( ! \defined( 'PERFORMANCE_DASHBOARDS_DIR' ) ) {
	\define( 'PERFORMANCE_DASHBOARDS_DIR', \plugin_dir_path( __FILE__ ) );
}

if ( ! \defined( 'PERFORMANCE_DASHBOARDS_URL' ) ) {
	\define( 'PERFORMANCE_DASHBOARDS_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Register admin pages via Event Logger filter.
// Performance Dashboard is registered as the main page.
\add_filter( 'newspack_event_logger_admin_pages', function( $pages ) {
	// Performance Dashboard - main page.
	$pages[] = [
		'slug'       => 'newspack-event-logger',
		'title'      => \__( 'Performance Dashboard', 'newspack-performance-dashboards' ),
		'menu_title' => \__( 'Performance', 'newspack-performance-dashboards' ),
		'callback'   => function() {
			echo '<div id="event-logger-admin" class="event-logger-admin-page"></div>';
		},
		'position'   => 10,
	];

	// Error Log page.
	$pages[] = [
		'slug'       => 'newspack-event-logger-errors',
		'title'      => \__( 'Error Log', 'newspack-performance-dashboards' ),
		'menu_title' => \__( 'Error Log', 'newspack-performance-dashboards' ),
		'callback'   => function() {
			echo '<div id="event-logger-errors" class="event-logger-admin-page"></div>';
		},
		'position'   => 15,
	];

	return $pages;
} );

// Register REST controllers via Event Logger filter.
\add_filter( 'newspack_event_logger_rest_controllers', function( $controllers ) {
	$controllers[] = Newspack_Performance_Dashboards\REST\PerformanceController::class;
	$controllers[] = Newspack_Performance_Dashboards\REST\OverviewController::class;
	$controllers[] = Newspack_Performance_Dashboards\REST\RequestsController::class;
	$controllers[] = Newspack_Performance_Dashboards\REST\UrlsController::class;
	$controllers[] = Newspack_Performance_Dashboards\REST\ErrorsController::class;
	return $controllers;
} );

// Initialize admin.
if ( \is_admin() ) {
	new Newspack_Performance_Dashboards\Admin\Admin();
}
