<?php
/**
 * Event Logger configuration.
 *
 * @package Event_Logger
 */

// Prevent direct access.
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

// Log SSE rate-limit events.
add_action( 'newspack_event_logger_sse_rate_limited', function( $user_id, $class ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( "[EventLogger] SSE 429: user={$user_id} controller={$class}" );
}, 10, 2 );

return [
	// Access control - restrict admin UI and REST API to these usernames.
	// Empty array = allow all users with manage_options capability.
	//
	// SECURITY LIMITATIONS of username-based authorization:
	// - Usernames are case-sensitive (WordPress stores lowercase by default).
	// - Deleted/recreated users with the same username regain access.
	// - Renamed users lose access (update this list after username changes).
	// - For stronger authorization, consider switching to user IDs in future.
	'allowed_users'          => [ 'admin', 'adminnewspack', 'dispatch' ],

	// Enable/disable logging.
	'enable_logging'         => true,

	// Directories.
	'base_directory'         => '/tmp/event-logger',

	// Memcache servers (array of host:port strings)
	'memcache_servers'       => [
		'127.0.0.1:11211',
	],

	// Partitioning and segmentation.
	'num_partitions'         => 1,  // Number of partitions (for parallel processing).
	'num_segments'           => 2,  // Number of segments to retain per partition.
	'segment_size'           => 64 * 1024 * 1024,  // Max segment size before rotation (64MB).
	'max_lifespan'           => 86400,  // Minimum retention in seconds (24h). Segments only deleted
	                                    // when BOTH over num_segments AND older than max_lifespan.
	                                    // Ensures high-traffic sites keep at least this much data.
	                                    // Set to 0 to disable (pure count-based retention).

	// Remote aggregation (newspack-event-aggregator, newspack-performance-aggregator).
	// Servers to aggregate logs from (empty = hub mode disabled).
	// Uses WordPress Application Passwords for auth (Users → Profile → Application Passwords).
	'aggregator_servers'     => [],  // Server config keys: url, auth_username, auth_password, enabled, logs.
	'remote_num_segments'    => 2,   // Number of segments for remote servers.
	'remote_segment_size'    => 10 * 1024 * 1024,  // Segment size for remotes (10MB).
	'remote_max_lifespan'    => 3600,  // Min retention for remotes (1h). Spokes only need to keep
	                                   // data long enough for the aggregator to pull it.
	'aggregator_verify_ssl'  => true,  // Verify SSL certificates (set false for self-signed certs).
	'aggregator_allow_http'  => false, // Allow plain HTTP connections (default: false, HTTPS only).
	'enable_workers'         => true,

	// URL filtering (substring matching, not regex).
	// Priority: skip_urls is checked FIRST and always wins.
	'log_urls'               => [],  //  Only log URLs containing these substrings (empty = log all).
	'skip_urls'              => [
		// Never log URLs containing these substrings.
		'/wp-json/event-logger/v1/firehose',
	],

	// Custom event colors for flame graphs/profiles.
	// Plugins register their events via the 'newspack_event_logger_custom_colors' filter.
	// Example: add_filter('newspack_event_logger_custom_colors', fn($c) => array_merge($c, ['my_event' => '#9C27B0']));
	'custom_colors'          => [],

	// Custom events to log (associative array: event_name => color or true).
	// is_enabled() checks isset($custom_events[$keyword]), so names must be KEYS.
	// Default is empty - use admin UI to populate from custom_colors.
	'custom_events'          => [],

	// WordPress hooks to time (bind at priority 1 and PHP_INT_MAX-1).
	// Default is empty - opt-in to specific hooks as needed.
	// Use "Select Recommended" in admin to populate from recommended_log_events.
	'log_events'             => [],

	// Debugging options (off by default — use when tracing OOMs or mysterious slowness).
	// log_memory: append peak_mb to every complete() log entry.
	// flush_every_line: flush write buffer after every log line (survives OOM/crash).
	'log_memory'             => false,
	'flush_every_line'       => false,

	// Priority for hook_start registration. Default 1. Use a negative value
	// (e.g. -10000) to capture callbacks at priority 1 in checkpoint profiling.
	'hook_start_priority'    => -10000,

	// Recommended hooks for general profiling (used by "Select Recommended" button).
	// Colors are defined by category in hook_categories.json "_colors".
	'recommended_log_events' => [
		// Lifecycle.
		'after_setup_theme',
		'init',
		'parse_query',
		'parse_request',
		'plugins_loaded',
		'pre_get_posts',
		'send_headers',
		'setup_theme',
		'shutdown',
		'template_include',
		'template_redirect',
		'widgets_init',
		'wp',
		'wp_footer',
		'wp_head',
		'wp_loaded',

		// Scripts & Styles.
		'wp_enqueue_scripts',

		// Content Rendering.
		'body_class',
		'document_title',
		'document_title_parts',
		'document_title_separator',
		'post_class',
		'the_content',
		'the_permalink',
		'the_posts',

		// Query & Posts.
		'found_posts',
		'found_posts_query',
		'posts_clauses',
		'posts_clauses_request',
		'posts_distinct',
		'posts_distinct_request',
		'posts_fields',
		'posts_fields_request',
		'posts_groupby',
		'posts_groupby_request',
		'posts_join',
		'posts_join_paged',
		'posts_join_request',
		'posts_orderby',
		'posts_orderby_request',
		'posts_pre_query',
		'posts_request',
		'posts_request_ids',
		'posts_results',
		'posts_search',
		'posts_selection',
		'posts_where',
		'posts_where_paged',
		'posts_where_request',
		'query',

		// Taxonomies & Terms.
		'get_terms',

		// REST API.
		'rest_api_init',
		'rest_post_dispatch',
		'rest_pre_dispatch',

		// Other
		'activated_plugin',
		'admin_enqueue_scripts',
		'admin_footer',
		'admin_init',
		'admin_menu',
		'admin_notices',
		'admin_print_footer_scripts',
		'after_password_reset',
		'authenticate',
		'cron_schedules',
		'deactivate_jetpack-boost/jetpack-boost.php',
		'deactivate_pwa/pwa.php',
		'deactivate_woocommerce-memberships/woocommerce-memberships.php',
		'deactivate_woocommerce/woocommerce.php',
		'deactivate_wordpress-seo/wp-seo.php',
		'deactivated_plugin',
		'enqueue_block_editor_assets',
		'googlesitekit_deactivation',
		'load-plugins.php',
		'load-themes.php',
		'newspack_my_account_version',
		'pre_set_site_transient_update_plugins',
		'updated_option',
		'wp_ajax_woocommerce_load_status_widget',
		'wp_authenticate_user',
		'wp_maybe_auto_update',
		'wp_robots',
		'wp_update_plugins',
		'wp_version_check',
		'wpseo_deactivate',
		'wpseo_indexables_unindexed_calculated',
		'wpseo_saved_indexable',
	],
];
