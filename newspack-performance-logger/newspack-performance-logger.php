<?php
/**
 * Plugin Name: Newspack Performance Logger
 * Description: Lightweight performance instrumentation and logging. Provides hook timing, request lifecycle logging, and remote management API for multi-server aggregation.
 * Version: 2.4.10
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-performance-logger
 * Domain Path: /languages/
 * Requires Plugins: newspack-event-logger
 *
 * @package Newspack_Performance_Logger
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

// Final check - if Core still not available, show error.
if ( ! \class_exists( 'Newspack_Event_Logger\\Firehose' ) ) {
	\add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			\esc_html_e( 'Newspack Performance Logger requires Newspack Event Logger plugin to be active.', 'newspack-performance-logger' );
			echo '</p></div>';
		}
	);
	return;
}

if ( ! \defined( 'NEWSPACK_PERFORMANCE_LOGGER_VERSION' ) ) {
	\define( 'NEWSPACK_PERFORMANCE_LOGGER_VERSION', '2.4.9' );
}

if ( ! \defined( 'NEWSPACK_PERFORMANCE_LOGGER_FILE' ) ) {
	\define( 'NEWSPACK_PERFORMANCE_LOGGER_FILE', __FILE__ );
}

if ( ! \defined( 'PERFORMANCE_LOGGER_DIR' ) ) {
	\define( 'PERFORMANCE_LOGGER_DIR', \plugin_dir_path( __FILE__ ) );
}

if ( ! \defined( 'PERFORMANCE_LOGGER_URL' ) ) {
	\define( 'PERFORMANCE_LOGGER_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Register config schema options BEFORE Core() initializes (it may call load_config).
\add_filter(
	'newspack_event_logger_option_schema_core',
	function ( $schema ) {
		return \array_merge(
			$schema,
			[
				'log_urls'         => 'array_strings',
				'skip_urls'        => 'array_strings',
				'log_events'       => 'array_strings',
				'custom_events'    => 'array_strings',
				'log_memory'          => 'bool',
				'flush_every_line'    => 'bool',
				'significant_events' => 'array_strings',
			]
		);
	}
);

// Register log count for storage calculation (firehose.log).
\add_filter( 'newspack_event_logger_num_logs', fn( $n ) => $n + 1 );

// Initialize Core (WP hook instrumentation) - runs early, before init.
// This must happen before any other plugins to capture accurate timing.
new Newspack_Performance_Logger\Core();

// Initialize LogManager for request lifecycle logging.
// This is the singleton that Pyrobase and other plugins use.
Newspack_Performance_Logger\LogManager::instance();

// Register WP-CLI commands.
if ( \defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'eventlog reqgrep', Newspack_Performance_Logger\CLI\ReqgrepCommand::class );
}

// Register REST controllers via Event Logger filter.
\add_filter( 'newspack_event_logger_rest_controllers', function( $controllers ) {
	$controllers[] = Newspack_Performance_Logger\REST\DashboardController::class;
	$controllers[] = Newspack_Performance_Logger\REST\HooksController::class;
	$controllers[] = Newspack_Performance_Logger\REST\SettingsController::class;
	return $controllers;
} );

// Initialize admin settings UI.
if ( \is_admin() ) {
	new Newspack_Performance_Logger\Admin\Admin();
}

// Supervisor hooks for plugin activation/deactivation.
\register_activation_hook( __FILE__, [ Newspack_Event_Logger\Cron\Supervisor::class, 'request_restart' ] );
\register_deactivation_hook( __FILE__, [ Newspack_Event_Logger\Cron\Supervisor::class, 'request_restart' ] );
