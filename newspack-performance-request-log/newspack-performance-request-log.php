<?php
/**
 * Plugin Name: Newspack Performance Request Log
 * Description: Completed requests stream viewer. Shows real-time request completion log by streaming requests.log entries.
 * Version: 2.4.29
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-performance-request-log
 * Domain Path: /languages/
 * Requires Plugins: newspack-event-logger, newspack-performance-workers
 *
 * @package Newspack_Performance_Request_Log
 */

\defined( 'ABSPATH' ) || exit;

// Check dependency: Event Logger Core must be active.
if ( ! \class_exists( 'Newspack_Event_Logger\\Firehose' ) ) {
	$core_autoloader = \WP_PLUGIN_DIR . '/newspack-event-logger/vendor/autoload.php';
	if ( \file_exists( $core_autoloader ) ) {
		require_once $core_autoloader;
	}
}

if ( ! \class_exists( 'Newspack_Event_Logger\\Firehose' ) ) {
	\add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>';
		\esc_html_e( 'Newspack Performance Request Log requires Newspack Event Logger plugin to be active.', 'newspack-performance-request-log' );
		echo '</p></div>';
	} );
	return;
}

// Check dependency: Performance Workers must be active (produces requests.log via RequestBuilder).
// Try to load Workers autoloader if class isn't available yet (handles plugin load order).
if ( ! \class_exists( 'Newspack_Performance_Workers\\Cron\\RequestBuilder' ) ) {
	$workers_autoloader = \WP_PLUGIN_DIR . '/newspack-performance-workers/vendor/autoload.php';
	if ( \file_exists( $workers_autoloader ) ) {
		require_once $workers_autoloader;
	}
}

if ( ! \class_exists( 'Newspack_Performance_Workers\\Cron\\RequestBuilder' ) ) {
	\add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>';
		\esc_html_e( 'Newspack Performance Request Log requires Newspack Performance Workers plugin to be active (provides RequestBuilder which produces requests.log).', 'newspack-performance-request-log' );
		echo '</p></div>';
	} );
	return;
}

if ( ! \defined( 'PERFORMANCE_REQUEST_LOG_VERSION' ) ) {
	\define( 'PERFORMANCE_REQUEST_LOG_VERSION', '2.4.29' );
}

if ( ! \defined( 'PERFORMANCE_REQUEST_LOG_DIR' ) ) {
	\define( 'PERFORMANCE_REQUEST_LOG_DIR', \plugin_dir_path( __FILE__ ) );
}

if ( ! \defined( 'PERFORMANCE_REQUEST_LOG_URL' ) ) {
	\define( 'PERFORMANCE_REQUEST_LOG_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Register admin page via Event Logger filter.
\add_filter( 'newspack_event_logger_admin_pages', function( $pages ) {
	$pages[] = [
		'slug'       => 'newspack-event-logger-stream',
		'title'      => \__( 'Request Log', 'newspack-performance-request-log' ),
		'menu_title' => \__( 'Request Log', 'newspack-performance-request-log' ),
		'callback'   => function() {
			echo '<div id="event-logger-stream" class="event-logger-stream-page"></div>';
		},
		'position'   => 30,
	];
	return $pages;
} );

// Register REST controller via Event Logger filter.
\add_filter( 'newspack_event_logger_rest_controllers', function( $controllers ) {
	$controllers[] = Newspack_Performance_Request_Log\REST\RequestsController::class;
	return $controllers;
} );

// Initialize admin.
if ( \is_admin() ) {
	new Newspack_Performance_Request_Log\Admin\Admin();
}
