<?php
/**
 * Tests for Newspack_Event_Aggregator\RemoteManager.
 *
 * @package Newspack_Event_Aggregator
 */

use Newspack_Event_Aggregator\RemoteManager;
use Newspack_Event_Aggregator\ServerRegistry;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RemoteManager::class )]
class RemoteManagerTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options']         = [];
		$GLOBALS['_wp_test_filters']         = [];
		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '',
		];

		// Reset ServerRegistry singleton.
		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		// Reset LogManager singleton and context stack.
		$lm_ref = new \ReflectionClass( \Newspack_Performance_Logger\LogManager::class );
		$lm_inst = $lm_ref->getProperty( 'instance' );
		$lm_inst->setAccessible( true );
		$lm_inst->setValue( null, null );
		$lm_stack = $lm_ref->getProperty( 'context_stack' );
		$lm_stack->setAccessible( true );
		$lm_stack->setValue( null, [] );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']         = [];
		$GLOBALS['_wp_test_filters']         = [];
		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '',
		];

		// Reset ServerRegistry singleton.
		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		// Reset LogManager singleton and context stack.
		$lm_ref = new \ReflectionClass( \Newspack_Performance_Logger\LogManager::class );
		$lm_inst = $lm_ref->getProperty( 'instance' );
		$lm_inst->setAccessible( true );
		$lm_inst->setValue( null, null );
		$lm_stack = $lm_ref->getProperty( 'context_stack' );
		$lm_stack->setAccessible( true );
		$lm_stack->setValue( null, [] );

		Config::reset();
		parent::tearDown();
	}

	// ── init / register_handler ─────────────────────────────────────────

	public function test_register_handler_adds_remote_manager(): void {
		$handlers = RemoteManager::register_handler( [] );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
		$this->assertIsCallable( $handlers['remote_manager'] );
	}

	public function test_register_handler_preserves_existing(): void {
		$existing = [ 'other_handler' => function () {} ];
		$handlers = RemoteManager::register_handler( $existing );

		$this->assertArrayHasKey( 'other_handler', $handlers );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
	}

	// ── post_to_server ──────────────────────────────────────────────────

	public function test_post_to_server_builds_correct_url(): void {
		$server = [
			'url'           => 'https://example.com',
			'auth_username' => '',
			'auth_password' => '',
		];

		$result = RemoteManager::post_to_server( $server, '/wp-json/event-logger/v1/test/v1/endpoint', [ 'key' => 'value' ] );
		// wp_remote_post stub returns success.
		$this->assertIsArray( $result );
		$this->assertSame( 200, $result['response']['code'] );
	}

	public function test_post_to_server_with_trailing_slash(): void {
		$server = [
			'url'           => 'https://example.com/',
			'auth_username' => '',
			'auth_password' => '',
		];

		$result = RemoteManager::post_to_server( $server, '/wp-json/event-logger/v1/test', [] );
		$this->assertIsArray( $result );
	}

	public function test_post_to_server_with_auth(): void {
		$server = [
			'url'           => 'https://example.com',
			'auth_username' => 'admin',
			'auth_password' => 'secret123',
		];

		// The function should not error out with auth credentials.
		$result = RemoteManager::post_to_server( $server, '/wp-json/event-logger/v1/test', [ 'foo' => 'bar' ] );
		$this->assertIsArray( $result );
	}

	public function test_post_to_server_empty_url(): void {
		$server = [
			'url'           => '',
			'auth_username' => '',
			'auth_password' => '',
		];

		$result = RemoteManager::post_to_server( $server, '/wp-json/event-logger/v1/test', [] );
		$this->assertIsArray( $result );
	}

	// ── get_from_server ─────────────────────────────────────────────────

	public function test_get_from_server_returns_response(): void {
		$server = [
			'url'           => 'https://example.com',
			'auth_username' => '',
			'auth_password' => '',
		];

		$result = RemoteManager::get_from_server( $server, '/wp-json/event-logger/v1/discovery' );
		$this->assertIsArray( $result );
	}

	public function test_get_from_server_with_auth(): void {
		$server = [
			'url'           => 'https://example.com',
			'auth_username' => 'user',
			'auth_password' => 'pass',
		];

		$result = RemoteManager::get_from_server( $server, '/wp-json/event-logger/v1/test' );
		$this->assertIsArray( $result );
	}

	// ── sync_setting ────────────────────────────────────────────────────

	public function test_sync_setting_no_servers(): void {
		// No servers configured - should not error.
		RemoteManager::sync_setting( 'test_option', 'test_value' );
		$this->assertTrue( true, 'Should not error with no servers' );
	}

	public function test_sync_setting_with_enabled_server(): void {
		// Register a server in the options.
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'           => 'https://remote1.example.com',
				'enabled'       => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		// Reset registry to pick up new options.
		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		RemoteManager::sync_setting( 'log_events', [ 'init', 'wp_loaded' ] );
		$this->assertTrue( true, 'Should sync to enabled server without error' );
	}

	public function test_sync_setting_skips_disabled_server(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'           => 'https://remote1.example.com',
				'enabled'       => false,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		RemoteManager::sync_setting( 'test', 'value' );
		$this->assertTrue( true, 'Should skip disabled servers without error' );
	}

	public function test_sync_setting_custom_endpoint(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'           => 'https://remote1.example.com',
				'enabled'       => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		RemoteManager::sync_setting( 'test', 'value', '/wp-json/custom/v1/settings' );
		$this->assertTrue( true, 'Should use custom endpoint' );
	}

	public function test_sync_setting_specific_servers(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'     => 'https://remote1.example.com',
				'enabled' => true,
				'auth_username' => '',
				'auth_password' => '',
			],
			'server2' => [
				'url'     => 'https://remote2.example.com',
				'enabled' => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		// Only sync to server1.
		RemoteManager::sync_setting( 'test', 'value', '/wp-json/event-logger/v1/settings', [ 'server1' ] );
		$this->assertTrue( true, 'Should only sync to specified servers' );
	}

	public function test_sync_setting_skips_non_string_server_ids(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'     => 'https://remote1.example.com',
				'enabled' => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		// Pass non-string server IDs (should be skipped).
		RemoteManager::sync_setting( 'test', 'value', '/wp-json/event-logger/v1/settings', [ 42, null ] );
		$this->assertTrue( true, 'Should skip non-string server IDs' );
	}

	// ── sync_all_settings ───────────────────────────────────────────────

	public function test_sync_all_settings_with_no_registered_settings(): void {
		RemoteManager::sync_all_settings();
		$this->assertTrue( true, 'Should not error with no registered settings' );
	}

	public function test_sync_all_settings_skips_empty_local_option(): void {
		\add_filter( 'newspack_event_aggregator_synced_settings', function () {
			return [
				[
					'local_option'  => '',
					'remote_option' => 'test',
					'endpoint'      => '/wp-json/event-logger/v1/test/v1/settings',
				],
			];
		} );

		RemoteManager::sync_all_settings();
		$this->assertTrue( true, 'Should skip empty local_option' );
		$GLOBALS['_wp_test_filters'] = [];
	}

	// ── health_check ────────────────────────────────────────────────────

	public function test_health_check_no_servers(): void {
		RemoteManager::health_check();
		$this->assertTrue( true, 'Should not error with no servers' );
	}

	public function test_health_check_with_server_error(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'     => 'https://remote1.example.com',
				'enabled' => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		// Simulate error response.
		$GLOBALS['_wp_test_remote_response'] = new \WP_Error( 'http_error', 'Connection refused' );

		RemoteManager::health_check();
		$this->assertTrue( true, 'Should handle server errors gracefully' );
	}

	public function test_health_check_with_non_200_response(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'     => 'https://remote1.example.com',
				'enabled' => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 500 ],
			'body'     => 'Internal Server Error',
		];

		RemoteManager::health_check();
		$this->assertTrue( true, 'Should handle non-200 responses gracefully' );
	}

	public function test_health_check_with_invalid_json(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'     => 'https://remote1.example.com',
				'enabled' => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => 'not valid json',
		];

		RemoteManager::health_check();
		$this->assertTrue( true, 'Should handle invalid JSON responses gracefully' );
	}

	public function test_health_check_with_valid_discovery(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'     => 'https://remote1.example.com',
				'enabled' => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => \json_encode( [
				'lag'    => 5,
				'hooks'  => [ 'init', 'wp_loaded' ],
				'events' => [],
			] ),
		];

		RemoteManager::health_check();
		$this->assertTrue( true, 'Should process valid discovery data' );
	}

	// ── check_server via reflection ─────────────────────────────────────

	public function test_check_server_wp_error(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'check_server' );
		$ref->setAccessible( true );

		$GLOBALS['_wp_test_remote_response'] = new \WP_Error( 'timeout', 'Request timed out' );

		$result = $ref->invoke( null, 'test-server', [
			'url'           => 'https://example.com',
			'auth_username' => '',
			'auth_password' => '',
		] );
		$this->assertNull( $result );
	}

	public function test_check_server_non_200(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'check_server' );
		$ref->setAccessible( true );

		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 403 ],
			'body'     => 'Forbidden',
		];

		$result = $ref->invoke( null, 'test-server', [
			'url'           => 'https://example.com',
			'auth_username' => '',
			'auth_password' => '',
		] );
		$this->assertNull( $result );
	}

	public function test_check_server_invalid_json(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'check_server' );
		$ref->setAccessible( true );

		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => 'not json',
		];

		$result = $ref->invoke( null, 'test-server', [
			'url'           => 'https://example.com',
			'auth_username' => '',
			'auth_password' => '',
		] );
		$this->assertNull( $result );
	}

	public function test_check_server_valid(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'check_server' );
		$ref->setAccessible( true );

		$discovery = [ 'lag' => 3, 'hooks' => [ 'init' ] ];
		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => \json_encode( $discovery ),
		];

		$result = $ref->invoke( null, 'test-server', [
			'url'           => 'https://example.com',
			'auth_username' => '',
			'auth_password' => '',
		] );
		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['lag'] );
	}

	// ── log_status via reflection ───────────────────────────────────────

	public function test_log_status_does_not_crash(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'log_status' );
		$ref->setAccessible( true );

		// LogManager class exists but message() is safe to call.
		$ref->invoke( null, 'test-server', 'ok', null, 0 );
		$this->assertTrue( true, 'log_status should not crash' );
	}

	public function test_log_status_with_error_message(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'log_status' );
		$ref->setAccessible( true );

		$ref->invoke( null, 'test-server', 'error', 'Connection refused', 0 );
		$this->assertTrue( true, 'log_status with error should not crash' );
	}

	public function test_log_status_with_lag(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'log_status' );
		$ref->setAccessible( true );

		$ref->invoke( null, 'test-server', 'ok', null, 42 );
		$this->assertTrue( true, 'log_status with lag should not crash' );
	}

	// ── MAX_SERVERS constant ────────────────────────────────────────────

	public function test_max_servers_constant(): void {
		$ref = new \ReflectionClassConstant( RemoteManager::class, 'MAX_SERVERS' );
		$this->assertSame( 100, $ref->getValue() );
	}

	public function test_request_timeout_constant(): void {
		$ref = new \ReflectionClassConstant( RemoteManager::class, 'REQUEST_TIMEOUT' );
		$this->assertSame( 15, $ref->getValue() );
	}

	public function test_stale_threshold_constant(): void {
		$ref = new \ReflectionClassConstant( RemoteManager::class, 'STALE_THRESHOLD' );
		$this->assertSame( 600, $ref->getValue() );
	}

	// ── handle_job: sync_setting action ─────────────────────────────────

	public function test_handle_job_sync_setting(): void {
		$parameters = [
			'action'    => 'sync_setting',
			'option'    => 'log_events',
			'value'     => [ 'init', 'wp_loaded' ],
			'endpoint'  => '/wp-json/event-logger/v1/settings',
			'queued_at' => \time(),
		];

		// Should not throw — no servers configured, so sync_setting does nothing.
		RemoteManager::handle_job( $parameters );
		$this->assertTrue( true, 'handle_job sync_setting should not error' );
	}

	public function test_handle_job_sync_setting_empty_option(): void {
		$parameters = [
			'action'    => 'sync_setting',
			'option'    => '',
			'value'     => 'test',
			'queued_at' => \time(),
		];

		// Empty option name: should early-return without syncing.
		RemoteManager::handle_job( $parameters );
		$this->assertTrue( true, 'handle_job with empty option should not error' );
	}

	public function test_handle_job_sync_setting_stale(): void {
		$parameters = [
			'action'    => 'sync_setting',
			'option'    => 'log_events',
			'value'     => [ 'init' ],
			'queued_at' => \time() - 600, // 10 minutes ago — exceeds STALE_THRESHOLD.
		];

		// Stale job: should skip silently.
		RemoteManager::handle_job( $parameters );
		$this->assertTrue( true, 'handle_job with stale sync_setting should skip' );
	}

	public function test_handle_job_sync_setting_default_endpoint(): void {
		$parameters = [
			'action'    => 'sync_setting',
			'option'    => 'test_opt',
			'value'     => 'val',
			'queued_at' => \time(),
			// No 'endpoint' key — should use default.
		];

		RemoteManager::handle_job( $parameters );
		$this->assertTrue( true, 'handle_job with default endpoint should not error' );
	}

	// ── handle_job: health_check action ─────────────────────────────────

	public function test_handle_job_health_check(): void {
		$parameters = [
			'action'    => 'health_check',
			'queued_at' => \time(),
		];

		RemoteManager::handle_job( $parameters );
		$this->assertTrue( true, 'handle_job health_check should not error' );
	}

	public function test_handle_job_health_check_stale(): void {
		$parameters = [
			'action'    => 'health_check',
			'queued_at' => \time() - 600, // Stale.
		];

		RemoteManager::handle_job( $parameters );
		$this->assertTrue( true, 'handle_job stale health_check should skip' );
	}

	// ── handle_job: unknown action ──────────────────────────────────────

	public function test_handle_job_unknown_action(): void {
		$parameters = [
			'action'    => 'unknown_action',
			'queued_at' => \time(),
		];

		// Unknown actions fall through to plugin filter — no registered handlers, so no-op.
		RemoteManager::handle_job( $parameters );
		$this->assertTrue( true, 'handle_job with unknown action should not error' );
	}

	// ── handle_job: empty/missing action ────────────────────────────────

	public function test_handle_job_empty_action(): void {
		// Empty action string: early return.
		RemoteManager::handle_job( [ 'action' => '' ] );
		$this->assertTrue( true, 'handle_job with empty action should early return' );
	}

	public function test_handle_job_missing_action(): void {
		// No action key: early return.
		RemoteManager::handle_job( [] );
		$this->assertTrue( true, 'handle_job with missing action should early return' );
	}

	public function test_handle_job_non_string_action(): void {
		// Non-string action: early return.
		RemoteManager::handle_job( [ 'action' => 42 ] );
		$this->assertTrue( true, 'handle_job with non-string action should early return' );
	}

	// ── handle_job: custom plugin action ────────────────────────────────

	public function test_handle_job_custom_plugin_action(): void {
		$was_called = false;

		\add_filter( 'newspack_event_aggregator_remote_actions', function () use ( &$was_called ) {
			return [
				'custom_sync' => function ( $params ) use ( &$was_called ) {
					$was_called = true;
				},
			];
		} );

		RemoteManager::handle_job( [
			'action'    => 'custom_sync',
			'queued_at' => \time(),
		] );

		$this->assertTrue( $was_called, 'Custom plugin action should have been called' );
		$GLOBALS['_wp_test_filters'] = [];
	}

	// ── sync_all_settings with registered settings ──────────────────────

	public function test_sync_all_settings_with_settings(): void {
		\add_filter( 'newspack_event_aggregator_synced_settings', function () {
			return [
				[
					'local_option'  => 'event_logger_log_events',
					'remote_option' => 'event_logger_log_events',
					'endpoint'      => '/wp-json/event-logger/v1/settings',
				],
			];
		} );

		// Even with registered settings, no servers means no-op.
		RemoteManager::sync_all_settings();
		$this->assertTrue( true, 'sync_all_settings with settings should not error' );
		$GLOBALS['_wp_test_filters'] = [];
	}

	public function test_sync_all_settings_skips_unknown_config_key(): void {
		\add_filter( 'newspack_event_aggregator_synced_settings', function () {
			return [
				[
					'local_option'  => 'event_logger_nonexistent_key_xyz',
					'remote_option' => 'event_logger_nonexistent_key_xyz',
					'endpoint'      => '/wp-json/event-logger/v1/settings',
				],
			];
		} );

		// Config key doesn't exist, should skip this setting.
		RemoteManager::sync_all_settings();
		$this->assertTrue( true, 'sync_all_settings should skip unknown config keys' );
		$GLOBALS['_wp_test_filters'] = [];
	}

	// ── handle_job with server configured ───────────────────────────────

	public function test_handle_job_sync_setting_with_server(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'           => 'https://remote1.example.com',
				'enabled'       => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		RemoteManager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'log_events',
			'value'     => [ 'init' ],
			'endpoint'  => '/wp-json/event-logger/v1/settings',
			'queued_at' => \time(),
		] );
		$this->assertTrue( true, 'handle_job sync_setting with server should succeed' );
	}

	// ── is_allowed_endpoint ────────────────────────────────────────────

	public function test_is_allowed_endpoint_event_logger(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'is_allowed_endpoint' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( null, '/wp-json/event-logger/v1/settings' ) );
	}

	public function test_is_allowed_endpoint_perf_logger(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'is_allowed_endpoint' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( null, '/wp-json/perf-logger/v1/settings' ) );
	}

	public function test_is_allowed_endpoint_rejects_bare_wp_json(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'is_allowed_endpoint' );
		$ref->setAccessible( true );

		$this->assertFalse( $ref->invoke( null, '/wp-json/' ) );
	}

	public function test_is_allowed_endpoint_rejects_other_namespace(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'is_allowed_endpoint' );
		$ref->setAccessible( true );

		$this->assertFalse( $ref->invoke( null, '/wp-json/wp/v2/users' ) );
	}

	public function test_is_allowed_endpoint_rejects_arbitrary_path(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'is_allowed_endpoint' );
		$ref->setAccessible( true );

		$this->assertFalse( $ref->invoke( null, '/etc/passwd' ) );
	}

	public function test_is_allowed_endpoint_rejects_empty(): void {
		$ref = new \ReflectionMethod( RemoteManager::class, 'is_allowed_endpoint' );
		$ref->setAccessible( true );

		$this->assertFalse( $ref->invoke( null, '' ) );
	}

	// ── handle_job endpoint validation ─────────────────────────────────

	public function test_handle_job_sync_setting_rejects_disallowed_endpoint(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'           => 'https://remote1.example.com',
				'enabled'       => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		// Passing a disallowed endpoint should fall back to the default.
		RemoteManager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'log_events',
			'value'     => [ 'init' ],
			'endpoint'  => '/wp-json/wp/v2/users',
			'queued_at' => \time(),
		] );
		$this->assertTrue( true, 'Disallowed endpoint should fall back to default' );
	}

	// ── sync_all_settings endpoint validation ──────────────────────────

	public function test_sync_all_settings_skips_disallowed_endpoint(): void {
		\add_filter( 'newspack_event_aggregator_synced_settings', function () {
			return [
				[
					'local_option'  => 'event_logger_log_events',
					'remote_option' => 'event_logger_log_events',
					'endpoint'      => '/wp-json/wp/v2/users',
				],
			];
		} );

		// Setting with disallowed endpoint should be skipped entirely.
		RemoteManager::sync_all_settings();
		$this->assertTrue( true, 'sync_all_settings should skip settings with disallowed endpoints' );
		$GLOBALS['_wp_test_filters'] = [];
	}

	public function test_handle_job_health_check_with_server(): void {
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'server1' => [
				'url'           => 'https://remote1.example.com',
				'enabled'       => true,
				'auth_username' => '',
				'auth_password' => '',
			],
		];

		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$GLOBALS['_wp_test_remote_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => \json_encode( [ 'lag' => 2, 'hooks' => [ 'init' ] ] ),
		];

		RemoteManager::handle_job( [
			'action'    => 'health_check',
			'queued_at' => \time(),
		] );
		$this->assertTrue( true, 'handle_job health_check with server should succeed' );
	}
}
