<?php
/**
 * Plugin Name: Newspack Performance Gyroscope
 * Description: Real-time in-flight request monitoring. Shows live requests as they execute by streaming firehose.log entries.
 * Version: 2.4.15
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-performance-gyroscope
 * Domain Path: /languages/
 * Requires Plugins: newspack-event-logger, newspack-performance-logger
 *
 * @package Newspack_Performance_Gyroscope
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
		\esc_html_e( 'Newspack Performance Gyroscope requires Newspack Event Logger plugin to be active.', 'newspack-performance-gyroscope' );
		echo '</p></div>';
	} );
	return;
}

// Check dependency: Performance Logger module must be active.
// Try to load Performance Logger's autoloader if class isn't available yet (handles plugin load order).
if ( ! \class_exists( 'Newspack_Performance_Logger\\LogManager' ) ) {
	$perf_autoloader = \WP_PLUGIN_DIR . '/newspack-performance-logger/vendor/autoload.php';
	if ( \file_exists( $perf_autoloader ) ) {
		require_once $perf_autoloader;
	}
}

if ( ! \class_exists( 'Newspack_Performance_Logger\\LogManager' ) ) {
	\add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>';
		\esc_html_e( 'Newspack Performance Gyroscope requires Newspack Performance Logger plugin to be active.', 'newspack-performance-gyroscope' );
		echo '</p></div>';
	} );
	return;
}

if ( ! \defined( 'PERFORMANCE_GYROSCOPE_VERSION' ) ) {
	\define( 'PERFORMANCE_GYROSCOPE_VERSION', '2.4.15' );
}

if ( ! \defined( 'PERFORMANCE_GYROSCOPE_FILE' ) ) {
	\define( 'PERFORMANCE_GYROSCOPE_FILE', __FILE__ );
}

if ( ! \defined( 'PERFORMANCE_GYROSCOPE_DIR' ) ) {
	\define( 'PERFORMANCE_GYROSCOPE_DIR', \plugin_dir_path( __FILE__ ) );
}

if ( ! \defined( 'PERFORMANCE_GYROSCOPE_URL' ) ) {
	\define( 'PERFORMANCE_GYROSCOPE_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Register admin page via Event Logger filter.
\add_filter( 'newspack_event_logger_admin_pages', function( $pages ) {
	$pages[] = [
		'slug'       => 'newspack-performance-gyroscope',
		'title'      => \__( 'Gyroscope - Live Requests', 'newspack-performance-gyroscope' ),
		'menu_title' => \__( 'Gyroscope', 'newspack-performance-gyroscope' ),
		'callback'   => function() {
			echo '<div id="event-logger-gyroscope" class="event-logger-gyroscope-page"></div>';
		},
		'position'   => 20,
	];
	return $pages;
} );

// Register REST controller via Event Logger filter.
\add_filter( 'newspack_event_logger_rest_controllers', function( $controllers ) {
	$controllers[] = Newspack_Performance_Gyroscope\REST\GyroscopeController::class;
	return $controllers;
} );

// Initialize admin.
if ( \is_admin() ) {
	new Newspack_Performance_Gyroscope\Admin\Admin();
}
