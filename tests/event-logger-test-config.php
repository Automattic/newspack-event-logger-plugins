<?php
/**
 * Event Logger test configuration.
 *
 * Overrides default config for test environment.
 * Loaded via LOCAL_EVENT_LOGGER_CONF environment variable.
 *
 * @package Event_Logger
 */

return [
	'base_directory'    => '/tmp/event-logger-test',
	'num_partitions'    => 1,
	'num_segments'      => 2,
	'segment_size'      => 1024, // Small for testing.
	'max_lifespan'      => 0,    // Disable time-based retention.
	'memcache_servers'  => [ getenv( 'MEMCACHE_HOST' ) ?: 'memcache1:11211' ],
	'enable_logging'    => false,
	'enable_workers'    => false,
	'allowed_users'     => [],
	'log_urls'          => [],
	'skip_urls'         => [],
	'custom_colors'     => [],
	'custom_events'     => [],
	'log_events'        => [],
	'log_memory'        => false,
	'flush_every_line'  => false,
];
