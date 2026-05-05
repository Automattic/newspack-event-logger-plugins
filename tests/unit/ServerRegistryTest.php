<?php
/**
 * Tests for ServerRegistry.
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Aggregator\ServerRegistry;
use Newspack_Event_Logger\Config;

#[CoversClass( ServerRegistry::class )]
class ServerRegistryTest extends TestCase {

	private ServerRegistry $registry;

	protected function setUp(): void {
		$GLOBALS['_wp_test_options'] = [];
		// Reset Config static caches so each test gets fresh config.
		Config::reset();
		// Reset the singleton so each test starts fresh.
		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$this->registry = ServerRegistry::get_instance();
		$this->registry->reset_cache();
	}

	protected function tearDown(): void {
		// Restore test config env var (bootstrap sets it; config-file tests override it).
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		$GLOBALS['_wp_test_options'] = [];
		Config::reset();
		$ref = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	// ── Singleton ───────────────────────────────────────────────────────────

	public function test_get_instance_returns_same_instance(): void {
		$a = ServerRegistry::get_instance();
		$b = ServerRegistry::get_instance();
		$this->assertSame( $a, $b );
	}

	// ── get_all() ───────────────────────────────────────────────────────────

	public function test_get_all_returns_empty_when_no_config(): void {
		$servers = $this->registry->get_all();
		$this->assertIsArray( $servers );
	}

	public function test_get_all_returns_option_servers(): void {
		\update_option( 'event_logger_aggregator_servers', [
			'server1' => [
				'url'     => 'https://example.com',
				'enabled' => true,
			],
		] );
		$this->registry->reset_cache();

		$servers = $this->registry->get_all();
		$this->assertArrayHasKey( 'server1', $servers );
	}

	public function test_get_all_caches_result(): void {
		$first  = $this->registry->get_all();
		$second = $this->registry->get_all();
		$this->assertSame( $first, $second );
	}

	// ── get() ───────────────────────────────────────────────────────────────

	public function test_get_returns_null_for_unknown_server(): void {
		$this->assertNull( $this->registry->get( 'nonexistent' ) );
	}

	public function test_get_returns_server_config(): void {
		\update_option( 'event_logger_aggregator_servers', [
			'my-server' => [
				'url'     => 'https://example.com',
				'enabled' => true,
			],
		] );
		$this->registry->reset_cache();

		$server = $this->registry->get( 'my-server' );
		$this->assertNotNull( $server );
		$this->assertSame( 'https://example.com', $server['url'] );
	}

	// ── add() ───────────────────────────────────────────────────────────────

	public function test_add_server_succeeds(): void {
		$result = $this->registry->add( 'new-server', [
			'url'     => 'https://new.example.com',
			'enabled' => true,
		] );

		$this->assertTrue( $result );
		$server = $this->registry->get( 'new-server' );
		$this->assertNotNull( $server );
		$this->assertSame( 'https://new.example.com', $server['url'] );
	}

	public function test_add_fails_for_invalid_id(): void {
		$result = $this->registry->add( 'invalid id with spaces', [
			'url' => 'https://example.com',
		] );
		$this->assertFalse( $result );
	}

	public function test_add_fails_for_empty_id(): void {
		$result = $this->registry->add( '', [
			'url' => 'https://example.com',
		] );
		$this->assertFalse( $result );
	}

	public function test_add_fails_for_duplicate_id(): void {
		$this->registry->add( 'dup', [
			'url' => 'https://first.example.com',
		] );
		$result = $this->registry->add( 'dup', [
			'url' => 'https://second.example.com',
		] );
		$this->assertFalse( $result );
	}

	public function test_add_fails_without_url(): void {
		$result = $this->registry->add( 'no-url', [
			'enabled' => true,
		] );
		$this->assertFalse( $result );
	}

	public function test_add_fails_for_http_url(): void {
		$result = $this->registry->add( 'insecure', [
			'url' => 'http://insecure.example.com',
		] );
		$this->assertFalse( $result );
	}

	public function test_add_stores_auth_credentials(): void {
		$this->registry->add( 'authed', [
			'url'           => 'https://authed.example.com',
			'auth_username' => 'admin',
			'auth_password' => 'apppass123',
		] );

		$server = $this->registry->get( 'authed' );
		$this->assertSame( 'admin', $server['auth_username'] );
		$this->assertSame( 'apppass123', $server['auth_password'] );
	}

	public function test_add_defaults_enabled_to_true(): void {
		$this->registry->add( 'defaults', [
			'url' => 'https://defaults.example.com',
		] );

		$server = $this->registry->get( 'defaults' );
		$this->assertTrue( $server['enabled'] );
	}

	public function test_add_defaults_logs_to_firehose(): void {
		$this->registry->add( 'defaults', [
			'url' => 'https://defaults.example.com',
		] );

		$server = $this->registry->get( 'defaults' );
		$this->assertSame( [ 'firehose.log' ], $server['logs'] );
	}

	public function test_add_validates_log_names(): void {
		$this->registry->add( 'logtest', [
			'url'  => 'https://logtest.example.com',
			'logs' => [ 'firehose.log', 'invalid name.log', '../escape.log', 'jobs.log' ],
		] );

		$server = $this->registry->get( 'logtest' );
		$this->assertSame( [ 'firehose.log', 'jobs.log' ], $server['logs'] );
	}

	public function test_add_trims_trailing_slash_from_url(): void {
		$this->registry->add( 'slashed', [
			'url' => 'https://slashed.example.com/',
		] );

		$server = $this->registry->get( 'slashed' );
		$this->assertSame( 'https://slashed.example.com', $server['url'] );
	}

	// ── update() ────────────────────────────────────────────────────────────

	public function test_update_server_succeeds(): void {
		$this->registry->add( 'target', [
			'url'     => 'https://target.example.com',
			'enabled' => true,
		] );

		$result = $this->registry->update( 'target', [
			'enabled' => false,
		] );

		$this->assertTrue( $result );
		$server = $this->registry->get( 'target' );
		$this->assertFalse( $server['enabled'] );
		// URL should still be there from the merge.
		$this->assertSame( 'https://target.example.com', $server['url'] );
	}

	public function test_update_fails_for_nonexistent_server(): void {
		$result = $this->registry->update( 'ghost', [ 'enabled' => false ] );
		$this->assertFalse( $result );
	}

	// ── remove() ────────────────────────────────────────────────────────────

	public function test_remove_server_succeeds(): void {
		$this->registry->add( 'doomed', [
			'url' => 'https://doomed.example.com',
		] );

		$result = $this->registry->remove( 'doomed' );
		$this->assertTrue( $result );
		$this->assertNull( $this->registry->get( 'doomed' ) );
	}

	public function test_remove_fails_for_nonexistent_server(): void {
		$result = $this->registry->remove( 'ghost' );
		$this->assertFalse( $result );
	}

	/**
	 * Regression: update_option() returns false on no-op (when the new value
	 * equals the stored value), not just on failure. If the in-memory cached
	 * view of servers includes an entry that the WP option doesn't actually
	 * contain (e.g., concurrent edit, stale object cache), unset() leaves the
	 * option unchanged — the pre-fix code mistook update_option's `false`
	 * return for failure and reported "Failed to delete server" even though
	 * the desired state was already achieved.
	 */
	public function test_remove_succeeds_when_update_option_is_noop(): void {
		// Pre-seed the cached view with an entry, but leave the WP option
		// with that entry already absent — so unset() produces the same
		// array that's already stored, and update_option returns false.
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'keep' => [
				'url'           => 'https://keep.example.com',
				'auth_username' => '',
				'auth_password' => '',
				'enabled'       => true,
				'logs'          => [ 'firehose.log' ],
			],
		];

		$cached = new \ReflectionProperty( $this->registry, 'servers' );
		$cached->setAccessible( true );
		$cached->setValue( $this->registry, [
			'keep'  => $GLOBALS['_wp_test_options']['event_logger_aggregator_servers']['keep'],
			'ghost' => [
				'url'           => 'https://ghost.example.com',
				'auth_username' => '',
				'auth_password' => '',
				'enabled'       => true,
				'logs'          => [ 'firehose.log' ],
			],
		] );

		$result = $this->registry->remove( 'ghost' );

		$this->assertTrue( $result, 'remove() should succeed when desired state already holds' );
		$this->assertNull( $this->registry->get( 'ghost' ) );
	}

	/**
	 * Regression: update() must succeed when the user re-saves the same
	 * config (no actual change). Pre-fix, update_option returned false on
	 * the no-op and update() propagated that as "save failed".
	 */
	public function test_update_succeeds_when_config_unchanged(): void {
		$this->registry->add( 'stable', [
			'url'     => 'https://stable.example.com',
			'enabled' => true,
		] );

		// Re-save with identical config — update_option will return false.
		$result = $this->registry->update( 'stable', [
			'url'     => 'https://stable.example.com',
			'enabled' => true,
		] );

		$this->assertTrue( $result, 'update() should succeed when re-saving identical config' );
	}

	// ── get_enabled() ───────────────────────────────────────────────────────

	public function test_get_enabled_filters_disabled(): void {
		$this->registry->add( 'enabled-server', [
			'url'     => 'https://enabled.example.com',
			'enabled' => true,
		] );
		$this->registry->add( 'disabled-server', [
			'url'     => 'https://disabled.example.com',
			'enabled' => false,
		] );

		$enabled = $this->registry->get_enabled();
		$this->assertArrayHasKey( 'enabled-server', $enabled );
		$this->assertArrayNotHasKey( 'disabled-server', $enabled );
	}

	public function test_get_enabled_empty_when_all_disabled(): void {
		$this->registry->add( 'off', [
			'url'     => 'https://off.example.com',
			'enabled' => false,
		] );

		$enabled = $this->registry->get_enabled();
		$this->assertEmpty( $enabled );
	}

	// ── is_valid_id() ───────────────────────────────────────────────────────

	public function test_valid_id_alphanumeric(): void {
		$this->assertTrue( ServerRegistry::is_valid_id( 'server1' ) );
	}

	public function test_valid_id_with_hyphens_and_underscores(): void {
		$this->assertTrue( ServerRegistry::is_valid_id( 'my-server_01' ) );
	}

	public function test_invalid_id_empty(): void {
		$this->assertFalse( ServerRegistry::is_valid_id( '' ) );
	}

	public function test_invalid_id_with_spaces(): void {
		$this->assertFalse( ServerRegistry::is_valid_id( 'has spaces' ) );
	}

	public function test_invalid_id_with_special_chars(): void {
		$this->assertFalse( ServerRegistry::is_valid_id( 'has@special!' ) );
	}

	public function test_invalid_id_too_long(): void {
		$this->assertFalse( ServerRegistry::is_valid_id( str_repeat( 'a', 65 ) ) );
	}

	public function test_valid_id_max_length(): void {
		$this->assertTrue( ServerRegistry::is_valid_id( str_repeat( 'a', 64 ) ) );
	}

	// ── reset_cache() ───────────────────────────────────────────────────────

	public function test_reset_cache_forces_reload(): void {
		$this->registry->add( 'cached', [
			'url' => 'https://cached.example.com',
		] );

		// Directly modify the option to simulate external change.
		\update_option( 'event_logger_aggregator_servers', [] );
		$this->registry->reset_cache();

		$this->assertNull( $this->registry->get( 'cached' ) );
	}

	// ── Config-file server restrictions ─────────────────────────────────

	public function test_config_file_server_update_restricted_to_enabled_toggle(): void {
		// Load config that defines 'config-server' in aggregator_servers.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/server-registry.php' );
		Config::reset();

		// Re-create registry so it picks up the config-file server.
		$ref      = new \ReflectionClass( ServerRegistry::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		$registry = ServerRegistry::get_instance();

		// Verify the config-file server is present.
		$server = $registry->get( 'config-server' );
		$this->assertNotNull( $server, 'Config-file server should be visible via get()' );
		$this->assertSame( 'https://config.example.com', $server['url'] );

		// Attempt to update the URL — should be rejected.
		$result = $registry->update( 'config-server', [
			'url' => 'https://hacked.example.com',
		] );
		$this->assertFalse( $result, 'Updating URL of config-file server should return false' );

		// Attempt to update only enabled — should succeed.
		$result = $registry->update( 'config-server', [
			'enabled' => false,
		] );
		$this->assertTrue( $result, 'Toggling enabled on config-file server should succeed' );

		// Verify the toggle took effect.
		$registry->reset_cache();
		$server = $registry->get( 'config-server' );
		$this->assertFalse( $server['enabled'], 'Config-file server should now be disabled' );

		// Clean up env — restore to test config, not unset, to avoid polluting later tests.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
	}

	// ── get_all() normalization ─────────────────────────────────────────

	public function test_get_all_normalizes_missing_keys(): void {
		// Set a server entry missing the 'logs' key directly in the WP option.
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'bare-server' => [
				'url'     => 'https://bare.example.com',
				'enabled' => true,
			],
		];
		$this->registry->reset_cache();

		$servers = $this->registry->get_all();
		$this->assertArrayHasKey( 'bare-server', $servers );

		$server = $servers['bare-server'];
		$this->assertSame( [ 'firehose.log' ], $server['logs'], 'Missing logs key should default to firehose.log' );
		$this->assertArrayHasKey( 'auth_username', $server, 'Missing auth_username should be normalized' );
		$this->assertArrayHasKey( 'auth_password', $server, 'Missing auth_password should be normalized' );
	}

	// ── Password encryption at rest ─────────────────────────────────────

	public function test_auth_password_encrypted_at_rest(): void {
		// Add a server with plaintext password via the public API.
		$this->registry->add( 'secret-server', [
			'url'           => 'https://secret.example.com',
			'auth_username' => 'admin',
			'auth_password' => 'test_pass',
		] );

		// Read the raw WP option — password should be encrypted (prefixed with $enc$).
		$raw = $GLOBALS['_wp_test_options']['event_logger_aggregator_servers'];
		$this->assertArrayHasKey( 'secret-server', $raw );

		$stored_password = $raw['secret-server']['auth_password'];
		$this->assertStringStartsWith(
			'$enc$',
			$stored_password,
			'Stored password should be encrypted with $enc$ prefix'
		);
		$this->assertNotSame( 'test_pass', $stored_password, 'Stored password must not be plaintext' );

		// Read via get() — should be decrypted back to original.
		$this->registry->reset_cache();
		$server = $this->registry->get( 'secret-server' );
		$this->assertSame( 'test_pass', $server['auth_password'], 'get() should return decrypted password' );
	}

	// ── Legacy plaintext password migration ─────────────────────────────

	public function test_auth_password_legacy_plaintext_migration(): void {
		// Simulate pre-encryption data: plaintext password stored directly in the option.
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'legacy-server' => [
				'url'           => 'https://legacy.example.com',
				'auth_username' => 'oldadmin',
				'auth_password' => 'old_pass',
				'enabled'       => true,
				'logs'          => [ 'firehose.log' ],
			],
		];
		$this->registry->reset_cache();

		// get() should transparently return the plaintext password (no decryption error).
		$server = $this->registry->get( 'legacy-server' );
		$this->assertNotNull( $server, 'Legacy server should be accessible' );
		$this->assertSame( 'old_pass', $server['auth_password'], 'Legacy plaintext password should be returned as-is' );
		$this->assertSame( 'oldadmin', $server['auth_username'] );
	}
}
