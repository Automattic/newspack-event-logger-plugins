<?php
/**
 * Plugin Name: Newspack Event Aggregator
 * Description: Aggregates logs from remote Event Logger servers via SSE.
 * Version: 2.4.41
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-event-aggregator
 * Domain Path: /languages/
 * Requires Plugins: newspack-event-logger, newspack-event-jobs
 *
 * @package Newspack_Event_Aggregator
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
		\esc_html_e( 'Newspack Event Aggregator requires Newspack Event Logger plugin to be active.', 'newspack-event-aggregator' );
		echo '</p></div>';
	} );
	return;
}

if ( ! \defined( 'NEWSPACK_EVENT_AGGREGATOR_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_AGGREGATOR_VERSION', '2.4.41' );
}

// Define the plugin directory and URL.
if ( ! \defined( 'EVENT_AGGREGATOR_DIR' ) ) {
	\define( 'EVENT_AGGREGATOR_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'EVENT_AGGREGATOR_URL' ) ) {
	\define( 'EVENT_AGGREGATOR_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Initialize remote manager (settings sync, health check job handler).
Newspack_Event_Aggregator\RemoteManager::init();

// Initialize settings sync for core options (partitions, segments, segment_size).
Newspack_Event_Aggregator\SettingsSync::init();

// Initialize admin interface.
if ( \is_admin() ) {
	new Newspack_Event_Aggregator\Admin\Admin();
}

// Register REST API routes.
\add_action(
	'rest_api_init',
	function () {
		( new Newspack_Event_Aggregator\REST\ServersController() )->register_routes();
		( new Newspack_Event_Aggregator\REST\StatusController() )->register_routes();
	}
);

// Register standalone workers via filter.
// Only registers stream-merger when remote servers are configured.
// Health check runs inside the Supervisor via the periodic hook below.
\add_filter(
	'newspack_event_logger_standalone_workers',
	function ( $workers ) {
		$config = \Newspack_Event_Logger\Config::load_config();
		if ( isset( $config['enable_workers'] ) && false === $config['enable_workers'] ) {
			return $workers; // Workers disabled on this node.
		}

		// Only start stream-merger if there are enabled remote servers.
		$servers = Newspack_Event_Aggregator\ServerRegistry::get_instance()->get_enabled();
		if ( ! empty( $servers ) ) {
			$workers['stream-merger'] = [
				'class'      => Newspack_Event_Aggregator\Cron\StreamMerger::class,
				'partitions' => true,  // One per partition.
			];
		}

		return $workers;
	}
);

// Run health check inside the Supervisor process (every 300 seconds).
// Avoids dedicating a whole PHP-FPM worker to a sub-millisecond task.
\add_action(
	'newspack_event_logger_supervisor_periodic',
	function () {
		static $last_check = 0;

		$now = \time();
		if ( $now - $last_check < 300 ) {
			return;
		}
		$last_check = $now;

		// Only check if we have enabled servers.
		$servers = Newspack_Event_Aggregator\ServerRegistry::get_instance()->get_enabled();
		if ( empty( $servers ) ) {
			return;
		}

		// Aggregator depends on the job pipeline (firehose → JobRouter → JobWorker
		// → RemoteManager). If enable_jobs is off, queueing the health_check is
		// pointless: jobs.log isn't being routed and STALE_THRESHOLD will drop
		// every entry. Surface this loudly — it's silently destructive otherwise.
		$config = \Newspack_Event_Logger\Config::load_config();
		if ( false === ( $config['enable_jobs'] ?? true ) ) {
			static $last_warn = 0;
			if ( $now - $last_warn >= 3600 ) {
				$last_warn = $now;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log(
					\sprintf(
						'[EventAggregator] enable_jobs is OFF but %d aggregator server(s) are configured. Settings sync, health checks, and remote fan-out are offline. Re-enable Jobs in Event Logger Settings to restore.',
						\count( $servers )
					)
				);
			}
			return;
		}

		// Queue health check job via LogManager.
		if ( ! \class_exists( 'Newspack_Performance_Logger\LogManager' ) ) {
			return;
		}
		$log_manager = \Newspack_Performance_Logger\LogManager::instance();
		$log_manager->message(
			'job',
			[
				'm' => [
					'handler'    => 'remote_manager',
					'parameters' => [
						'action'    => 'health_check',
						'queued_at' => $now,
					],
				],
			]
		);
		$log_manager->flush_buffer();
	}
);

// Register config option schema (for config file defaults to load).
\add_filter(
	'newspack_event_logger_option_schema_extended',
	function ( $schema ) {
		$schema['aggregator_servers']    = 'aggregator_servers';
		$schema['remote_num_segments']  = 'int';
		$schema['remote_segment_size']  = 'int';
		$schema['remote_max_lifespan']  = 'int';
		return $schema;
	}
);

// Request supervisor restart on activation so it picks up the new worker.
\register_activation_hook( __FILE__, [ Newspack_Event_Logger\Cron\Supervisor::class, 'request_restart' ] );

// Kill stream-merger workers and request supervisor restart on deactivation.
\register_deactivation_hook(
	__FILE__,
	function () {
		if ( \class_exists( 'Newspack_Event_Logger\Cron\Supervisor' ) ) {
			\Newspack_Event_Logger\Cron\Supervisor::kill_readers( [ 'stream-merger' ] );
		}
	}
);
