<?php
/**
 * Plugin Name: Newspack Event Dashboards
 * Description: Workers and Raw Logs dashboards for Event Logger.
 * Version: 2.4.23
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-event-dashboards
 * Domain Path: /languages/
 * Requires Plugins: newspack-event-logger
 *
 * @package Newspack_Event_Dashboards
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
		\esc_html_e( 'Newspack Event Dashboards requires Newspack Event Logger plugin to be active.', 'newspack-event-dashboards' );
		echo '</p></div>';
	} );
	return;
}

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_DASHBOARDS_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_DASHBOARDS_VERSION', '2.4.23' );
}

if ( ! \defined( 'EVENT_LOGGER_DASHBOARDS_DIR' ) ) {
	\define( 'EVENT_LOGGER_DASHBOARDS_DIR', \plugin_dir_path( __FILE__ ) );
}

if ( ! \defined( 'EVENT_LOGGER_DASHBOARDS_URL' ) ) {
	\define( 'EVENT_LOGGER_DASHBOARDS_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Register admin pages via Event Logger filter.
\add_filter( 'newspack_event_logger_admin_pages', function( $pages ) {
	$pages[] = [
		'slug'       => 'newspack-event-logger-workers',
		'title'      => \__( 'Workers', 'newspack-event-dashboards' ),
		'menu_title' => \__( 'Workers', 'newspack-event-dashboards' ),
		'callback'   => function() {
			echo '<div id="event-logger-workers" class="event-logger-workers-page"></div>';
		},
	];

	$pages[] = [
		'slug'       => 'newspack-event-logger-rawlogs',
		'title'      => \__( 'Raw Logs', 'newspack-event-dashboards' ),
		'menu_title' => \__( 'Raw Logs', 'newspack-event-dashboards' ),
		'callback'   => function() {
			echo '<div id="event-logger-rawlogs" class="event-logger-rawlogs-page"></div>';
		},
	];

	return $pages;
} );

// Register REST controllers via Event Logger filter.
\add_filter( 'newspack_event_logger_rest_controllers', function( $controllers ) {
	$controllers[] = Newspack_Event_Dashboards\REST\WorkersController::class;
	$controllers[] = Newspack_Event_Dashboards\REST\RawlogsController::class;
	return $controllers;
} );

// Initialize admin.
if ( \is_admin() ) {
	new Newspack_Event_Dashboards\Admin\Admin();
}
