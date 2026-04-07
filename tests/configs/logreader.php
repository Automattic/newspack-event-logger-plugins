<?php
return [
	'base_directory'   => '/tmp/event-logger-test-logreader',
	'num_partitions'   => 1,
	'num_segments'     => 2,
	'segment_size'     => 65536,
	'max_lifespan'     => 0,
	'enable_logging'   => false,
	'enable_workers'   => false,
	'memcache_servers' => [],
	'allowed_users'    => [],
	'log_urls'         => [],
	'skip_urls'        => [],
	'custom_colors'    => [],
	'custom_events'    => [],
	'log_events'       => [],
	'log_memory'       => false,
	'flush_every_line' => false,
];
