<?php
/**
 * Plugin Name: Newspack Performance Workers
 * Description: Background workers for performance data aggregation and auto-tuning. Processes firehose data into flame graphs and URL stats.
 * Version: 2.4.41
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-performance-workers
 * Domain Path: /languages/
 * Requires Plugins: newspack-event-logger, newspack-performance-logger
 *
 * @package Newspack_Performance_Workers
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
		\esc_html_e( 'Newspack Performance Workers requires Newspack Event Logger plugin to be active.', 'newspack-performance-workers' );
		echo '</p></div>';
	} );
	return;
}

// Check dependency: Performance Logger must be active.
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
		\esc_html_e( 'Newspack Performance Workers requires Newspack Performance Logger plugin to be active.', 'newspack-performance-workers' );
		echo '</p></div>';
	} );
	return;
}

if ( ! \defined( 'NEWSPACK_PERFORMANCE_WORKERS_VERSION' ) ) {
	\define( 'NEWSPACK_PERFORMANCE_WORKERS_VERSION', '2.4.41' );
}

if ( ! \defined( 'PERFORMANCE_WORKERS_DIR' ) ) {
	\define( 'PERFORMANCE_WORKERS_DIR', \plugin_dir_path( __FILE__ ) );
}

if ( ! \defined( 'PERFORMANCE_WORKERS_URL' ) ) {
	\define( 'PERFORMANCE_WORKERS_URL', \plugin_dir_url( __FILE__ ) );
}

// Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Register config schema options (all extended - only needed for workers/admin).
\add_filter(
	'newspack_event_logger_option_schema_core',
	function ( $schema ) {
		$schema['enable_workers'] = 'bool';
		return $schema;
	}
);
\add_filter(
	'newspack_event_logger_option_schema_extended',
	function ( $schema ) {
		return \array_merge(
			$schema,
			[
				'auto_disable_threshold'       => 'int',
				'auto_protect_time_threshold'  => 'float',
				'significant_events'           => 'array_strings',
			]
		);
	}
);

// Register log count for storage calculation (requests.log, flames.log).
// Only count if workers are enabled on this node.
\add_filter( 'newspack_event_logger_num_logs', function( $n ) {
	$config = Newspack_Event_Logger\Config::load_config();
	if ( isset( $config['enable_workers'] ) && false === $config['enable_workers'] ) {
		return $n; // Workers disabled, don't count their logs.
	}
	return $n + 2;
} );

// Register log readers via Event Logger filter (two-level group format).
// These readers process firehose data into aggregated request and flame data.
// Can be disabled via `enable_workers => false` in config (for non-hub nodes).
\add_filter( 'newspack_event_logger_log_readers', function( $readers ) {
	$config = Newspack_Event_Logger\Config::load_config();
	if ( isset( $config['enable_workers'] ) && false === $config['enable_workers'] ) {
		return $readers; // Workers disabled on this node.
	}

	// Request builder: processes firehose.log into requests.log.
	// Shares firehose-workers group with JobRouter (from event-jobs plugin).
	$readers['firehose-workers']['request-builder'] = [
		'class'   => Newspack_Performance_Workers\Cron\RequestBuilder::class,
		'inputs'  => [ 'firehose.log' ],
		'outputs' => [ 'requests.log', 'errors.log' ],
	];

	// Flame builder: processes requests.log into flames.log.
	$readers['request-workers']['flame-builder'] = [
		'class'  => Newspack_Performance_Workers\Cron\FlameBuilder::class,
		'inputs' => [ 'requests.log' ],
		'outputs' => [ 'flames.log' ],
	];

	return $readers;
} );

/*
 * Auto-tuning handlers for standalone mode (direct option updates).
 *
 * These handlers perform local WordPress option updates when FlameBuilder
 * detects noisy events or discovers significant events. In standalone mode,
 * changes are applied directly to this WordPress instance.
 *
 * Hub mode handler example (for future Aggregator plugin):
 *
 * \add_action( 'newspack_performance_workers_disable_hooks', function( $hooks, $context ) {
 *     // Queue job to fan-out to remote servers instead of local option update
 *     Newspack_Event_Logger\LogManager::log([
 *         'k' => 'job',
 *         'handler' => 'remote_manager',
 *         'parameters' => [
 *             'action' => 'disable',
 *             'hooks'  => $hooks
 *         ]
 *     ]);
 * }, 5, 2 ); // Priority 5 to run before standalone handler
 */

\add_action( 'newspack_performance_workers_disable_hooks', function( $hooks, $context ) {
	if ( ! isset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] ) && ! \current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $hooks ) ) {
		return;
	}
	$significant = $context['significant_events'] ?? [];
	$existing    = \get_option( 'event_logger_log_events', [] );
	if ( ! \is_array( $existing ) ) {
		$existing = [];
	}
	$to_remove = [];
	foreach ( $hooks as $hook ) {
		if ( ! isset( $significant[ $hook ] ) ) {
			$to_remove[ $hook ] = true;
		}
	}
	$existing = \array_values( \array_filter( $existing, fn( $v ) => ! isset( $to_remove[ $v ] ) ) );
	\update_option( 'event_logger_log_events', $existing, false );
}, 10, 2 );

\add_action( 'newspack_performance_workers_disable_custom_events', function( $events, $context ) {
	if ( ! isset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] ) && ! \current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $events ) ) {
		return;
	}
	$significant = $context['significant_events'] ?? [];
	$existing    = \get_option( 'event_logger_custom_events', [] );
	if ( ! \is_array( $existing ) ) {
		$existing = [];
	}
	foreach ( $events as $event ) {
		if ( isset( $significant[ $event ] ) ) {
			continue; // Skip significant events.
		}
		unset( $existing[ $event ] );
	}
	\update_option( 'event_logger_custom_events', $existing, false );
}, 10, 2 );

\add_action( 'newspack_performance_workers_add_significant_events', function( $events, $context ) {
	if ( ! isset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] ) && ! \current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $events ) ) {
		return;
	}
	$existing = \get_option( 'event_logger_significant_events', [] );
	if ( ! \is_array( $existing ) ) {
		$existing = [];
	}
	$merged = \array_unique( \array_merge( $existing, $events ) );
	\update_option( 'event_logger_significant_events', $merged, false );
}, 10, 2 );

// Supervisor hooks for plugin activation/deactivation.
\register_activation_hook( __FILE__, [ Newspack_Event_Logger\Cron\Supervisor::class, 'request_restart' ] );
\register_deactivation_hook( __FILE__, function() {
	Newspack_Event_Logger\Cron\Supervisor::kill_readers( [ 'firehose-workers', 'request-workers' ] );
} );

// Initialize admin.
if ( \is_admin() ) {
	new Newspack_Performance_Workers\Admin\Admin();
}
