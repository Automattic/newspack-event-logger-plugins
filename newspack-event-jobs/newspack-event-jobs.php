<?php
/**
 * Plugin Name: Newspack Event Jobs
 * Description: Async job queue processing for Event Logger. Allows plugins to queue large jobs for background processing.
 * Version: 2.4.10
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-event-jobs
 * Domain Path: /languages/
 * Requires Plugins: newspack-event-logger
 *
 * @package Newspack_Event_Jobs
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
		\esc_html_e( 'Newspack Event Jobs requires Newspack Event Logger plugin to be active.', 'newspack-event-jobs' );
		echo '</p></div>';
	} );
	return;
}

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_JOBS_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_JOBS_VERSION', '2.4.9' );
}

if ( ! \defined( 'EVENT_LOGGER_JOBS_DIR' ) ) {
	\define( 'EVENT_LOGGER_JOBS_DIR', \plugin_dir_path( __FILE__ ) );
}

if ( ! \defined( 'EVENT_LOGGER_JOBS_URL' ) ) {
	\define( 'EVENT_LOGGER_JOBS_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Register log count for storage calculation (jobs.log, jobintake.log).
\add_filter( 'newspack_event_logger_num_logs', fn( $n ) => $n + 2 );

// Register log readers for Jobs plugin (two-level group format).
\add_filter( 'newspack_event_logger_log_readers', function( $readers ) {
	// JobRouter inputs: always jobintake.log, plus firehose.log if Performance is active.
	$job_router_inputs = [ 'jobintake.log' ];
	if ( \class_exists( 'Newspack_Performance_Logger\\LogManager' ) ) {
		$job_router_inputs[] = 'firehose.log';
	}

	// JobRouter: routes job entries to jobs.log.
	// When Performance is active, shares firehose-workers group with RequestBuilder.
	// When Performance is inactive, runs in its own jobintake-workers group.
	$group = \class_exists( 'Newspack_Performance_Logger\\LogManager' ) ? 'firehose-workers' : 'jobintake-workers';
	$readers[ $group ]['job-router'] = [
		'class'  => Newspack_Event_Jobs\Cron\JobRouter::class,
		'inputs' => $job_router_inputs,
		'outputs' => [ 'jobs.log' ],
	];

	// JobWorker: jobs.log → dispatches to registered handlers.
	// Long stale timeout because job handlers (e.g. imports) can block for minutes.
	$readers['job-workers']['job-worker'] = [
		'class'         => Newspack_Event_Jobs\Cron\JobWorker::class,
		'inputs'        => [ 'jobs.log' ],
		'outputs'       => [ 'firehose.log' ],
		'stale_timeout' => 600,
	];
	return $readers;
} );

// Supervisor hooks for plugin activation/deactivation.
\register_activation_hook( __FILE__, [ Newspack_Event_Logger\Cron\Supervisor::class, 'request_restart' ] );
\register_deactivation_hook( __FILE__, function() {
	// Kill both possible group names (firehose-workers when Performance is active,
	// jobintake-workers when standalone) plus job-workers.
	Newspack_Event_Logger\Cron\Supervisor::kill_readers( [ 'firehose-workers', 'jobintake-workers', 'job-workers' ] );
} );
