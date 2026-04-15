<?php
/**
 * Plugin Name: Newspack Event Logger
 * Description: Logs WordPress request lifecycle, plugin load times, and query performance in JSONL format.
 * Version: 2.4.26
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-event-logger
 * Domain Path: /languages/
 *
 * @package Newspack_Event_Logger
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_VERSION', '2.4.26' );
}

// Define NEWSPACK_EVENT_LOGGER_FILE.
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_FILE' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_FILE', __FILE__ );
}

// Define the plugin directory and URL.
if ( ! \defined( 'EVENT_LOGGER_DIR' ) ) {
	\define( 'EVENT_LOGGER_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'EVENT_LOGGER_URL' ) ) {
	\define( 'EVENT_LOGGER_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Register WP-CLI commands.
if ( \defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'eventlog worker', Newspack_Event_Logger\CLI\WorkerCommand::class );
}

// Initialize admin interface.
if ( \is_admin() ) {
	new Newspack_Event_Logger\Admin\Admin();
}

// Register REST API routes.
\add_action(
	'rest_api_init',
	function () {
		// Core controllers.
		( new Newspack_Event_Logger\REST\FirehoseController() )->register_routes();
		( new Newspack_Event_Logger\REST\SpawnController() )->register_routes();
		( new Newspack_Event_Logger\REST\FirehoseStreamController() )->register_routes();
		( new Newspack_Event_Logger\REST\SettingsController() )->register_routes();
		( new Newspack_Event_Logger\REST\DiscoveryController() )->register_routes();

		// Allow external plugins to register REST controllers via filter.
		$external_controllers = \apply_filters( 'newspack_event_logger_rest_controllers', [] );
		foreach ( $external_controllers as $controller_class ) {
			if ( \class_exists( $controller_class ) && \is_subclass_of( $controller_class, \WP_REST_Controller::class ) ) {
				$controller = new $controller_class();
				$controller->register_routes();
			}
		}
	}
);

// Register and schedule the log aggregator cron.
// phpcs:disable WordPress.WP.CronInterval.CronSchedulesInterval -- Intentional for real-time log aggregation
\add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['every_minute'] = [
			'interval' => 60,
			'display'  => \__( 'Every Minute', 'newspack-event-logger' ),
		];
		return $schedules;
	}
);
// phpcs:enable WordPress.WP.CronInterval.CronSchedulesInterval

\add_action(
	'init',
	function () {
		// Schedule master supervisor cron (runs every minute as fallback).
		// Note: Schedule is in shared database, so don't conditionally unschedule
		// based on per-node config - that would cause thrashing between nodes.
		if ( ! \wp_next_scheduled( 'newspack_event_logger_supervisor' ) ) {
			\wp_schedule_event( \time() + 5, 'every_minute', 'newspack_event_logger_supervisor' );
		}

		// Master supervisor - long-running process that monitors and spawns workers.
		// Check for registered readers at runtime so each node respects its own config.
		\add_action(
			'newspack_event_logger_supervisor',
			function () {
				$readers = Newspack_Event_Logger\Cron\LogReader::get_registered_readers();
				if ( empty( $readers ) ) {
					return; // No workers registered on this node.
				}
				( new Newspack_Event_Logger\Cron\Supervisor() )->run();
			}
		);

		// Clean up old spawn_workers cron if present.
		$spawn_timestamp = \wp_next_scheduled( 'newspack_event_logger_spawn_workers' );
		if ( $spawn_timestamp ) {
			\wp_unschedule_event( $spawn_timestamp, 'newspack_event_logger_spawn_workers' );
		}
	}
);

// Plugin deactivation hook - clean up everything.
register_deactivation_hook( __FILE__, [ Newspack_Event_Logger\Cron\Supervisor::class, 'deactivate' ] );
