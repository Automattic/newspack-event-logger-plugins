<?php
return [
	'base_directory'     => '/tmp/event-logger-test-server-registry',
	'enable_logging'     => false,
	'enable_workers'     => false,
	'aggregator_servers' => [
		'config-server' => [
			'url'     => 'https://config.example.com',
			'enabled' => true,
		],
	],
];
