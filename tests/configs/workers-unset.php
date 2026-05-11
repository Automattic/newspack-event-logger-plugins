<?php
// Config that simulates "enable_workers not configured." The plugin's baseline
// newspack-event-logger-config.php sets enable_workers=true; load_config() merges it
// into every layered config, so simply omitting the key here would leave the
// baseline true value in place. Setting the key to null makes isset() return
// false in the merged result, exercising the "missing/unset" code path in
// SettingsSync's fail-closed gate.
return [
	'base_directory'   => '/tmp/event-logger-test-settings-sync',
	'num_partitions'   => 1,
	'num_segments'     => 2,
	'segment_size'     => 4096,
	'max_lifespan'     => 0,
	'enable_logging'   => true,
	'enable_workers'   => null, // exercise the !isset branch
	'memcache_servers' => [],
	'allowed_users'    => [],
	'skip_urls'        => [],
	'log_urls'         => [],
	'custom_colors'    => [],
	'custom_events'    => [],
	'log_events'       => [],
	'log_memory'       => false,
	'flush_every_line' => false,
];
