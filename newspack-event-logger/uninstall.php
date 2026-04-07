<?php
/**
 * Event Logger Uninstall
 *
 * Fired when the plugin is uninstalled.
 *
 * @package Event_Logger
 */


if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

// If uninstall not called from WordPress, exit.
if ( ! \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Recursively delete a directory and its contents.
 *
 * @param string $directory Directory path to delete.
 */
function event_logger_delete_directory( $directory ) {
	// Remove symlinks without following to prevent directory escape attacks.
	if ( \is_link( $directory ) ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		@\unlink( $directory );
		return;
	}
	if ( ! \is_dir( $directory ) ) {
		return;
	}

	// Recursively delete directory contents.
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		// Skip symlinks to prevent directory escape attacks.
		if ( $file->isLink() ) {
			// Remove the symlink itself, but don't follow it.
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			@\unlink( $file->getPathname() );
			continue;
		}
		if ( $file->isDir() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
			@\rmdir( $file->getRealPath() );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			@\unlink( $file->getRealPath() );
		}
	}
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
	@\rmdir( $directory );
}

// Unschedule cron jobs.
for ( $p = 0; $p < 16; $p++ ) {
	\wp_clear_scheduled_hook( "newspack_event_logger_aggregate_logs_p{$p}" );
	\wp_clear_scheduled_hook( "newspack_event_logger_build_flames_p{$p}" );
}

// Get configured base directory BEFORE deleting options.
// Validate against allowed parent paths to prevent arbitrary directory deletion
// if the option value has been tampered with.
$base_directory      = \get_option( 'event_logger_base_directory', '/tmp/event-logger' );
$real_base           = \is_string( $base_directory ) ? \realpath( $base_directory ) : false;
$allowed_parents     = [ '/tmp/', '/volumes/' ];
$upload_dir          = \wp_upload_dir();
if ( ! empty( $upload_dir['basedir'] ) ) {
	$allowed_parents[] = \trailingslashit( $upload_dir['basedir'] );
}
$base_directory_safe = false;
if ( false !== $real_base ) {
	$real_base_slash = \trailingslashit( $real_base );
	foreach ( $allowed_parents as $parent ) {
		if ( 0 === \strpos( $real_base_slash, $parent ) ) {
			$base_directory_safe = $real_base;
			break;
		}
	}
}

// Delete all plugin options.
$options = [
	'event_logger_enable_logging',
	'event_logger_base_directory',
	'event_logger_memcache_servers',
	'event_logger_num_partitions',
	'event_logger_num_segments',
	'event_logger_segment_size',
	'event_logger_log_urls',
	'event_logger_skip_urls',
	'event_logger_log_events',
	'event_logger_custom_events',
	'event_logger_auto_disable_threshold',
	'event_logger_auto_protect_time_threshold',
	'event_logger_significant_events',
	'event_logger_hook_customizations',
	'event_logger_aggregator_servers',
	'event_logger_remote_num_segments',
	'event_logger_remote_segment_size',
	'event_logger_max_lifespan',
	'event_logger_remote_max_lifespan',
];
foreach ( $options as $option ) {
	\delete_option( $option );
}

// Remove entire base directory (logs, locks, offsets).
if ( false !== $base_directory_safe ) {
	event_logger_delete_directory( $base_directory_safe );
}
