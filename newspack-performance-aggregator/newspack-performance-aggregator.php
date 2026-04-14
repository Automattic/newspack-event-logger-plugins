<?php
/**
 * Plugin Name: Newspack Performance Aggregator
 * Description: Performance-specific hub logic for multi-server aggregation - settings sync, health-check, auto-tuning coordination.
 * Version: 2.4.22
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-performance-aggregator
 * Domain Path: /languages/
 * Requires Plugins: newspack-event-logger, newspack-event-jobs, newspack-event-aggregator, newspack-performance-workers
 *
 * @package Newspack_Performance_Aggregator
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_PERFORMANCE_AGGREGATOR_VERSION' ) ) {
	\define( 'NEWSPACK_PERFORMANCE_AGGREGATOR_VERSION', '2.4.22' );
}

// Define the plugin directory and URL.
if ( ! \defined( 'PERFORMANCE_AGGREGATOR_DIR' ) ) {
	\define( 'PERFORMANCE_AGGREGATOR_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'PERFORMANCE_AGGREGATOR_URL' ) ) {
	\define( 'PERFORMANCE_AGGREGATOR_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Initialize settings sync.
Newspack_Performance_Aggregator\SettingsSync::init();

// Initialize health check extensions (hook/event merging).
Newspack_Performance_Aggregator\HealthCheckExtensions::init();

// Rewrite job entries during aggregation so they dispatch to remote handlers on the hub.
// Jobs are routed to jobs.log by JobRouter on each spoke;
// rewriting k:"job" → k:"remote_job" causes JobWorker to use newspack_event_logger_remote_job_handlers.
\add_filter(
	'newspack_event_aggregator_ingest_line',
	function ( $line ) {
		if ( ! \is_string( $line ) ) {
			return $line;
		}
		// Fast strpos pre-filter: >99% of firehose traffic is non-job entries.
		if ( false === \strpos( $line, '"k":"job"' ) ) {
			return $line;
		}
		// Decode and verify the actual k field to avoid false positives in payload values.
		$entry = \json_decode( $line, true );
		if ( ! \is_array( $entry ) || 'job' !== ( $entry['k'] ?? '' ) ) {
			return $line;
		}
		$entry['k'] = 'remote_job';
		$encoded    = \wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
		return \is_string( $encoded ) ? $encoded : $line;
	}
);

// Request supervisor restart on activation/deactivation so it picks up changed workers.
\register_activation_hook( __FILE__, [ Newspack_Event_Logger\Cron\Supervisor::class, 'request_restart' ] );
\register_deactivation_hook( __FILE__, [ Newspack_Event_Logger\Cron\Supervisor::class, 'request_restart' ] );
