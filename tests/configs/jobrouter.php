<?php
return [
	'base_directory'   => '/tmp/event-logger-test-jobrouter',
	'num_partitions'   => 1,
	'num_segments'     => 2,
	'segment_size'     => 4096,
	'max_lifespan'     => 0,
	'enable_logging'   => false,
	'enable_workers'   => false,
	'memcache_servers' => [],
	'allowed_users'    => [],
	'skip_urls'        => [],
	'log_urls'         => [],
];
