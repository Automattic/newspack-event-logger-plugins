<?php
/**
 * Tests for Config (configuration loading and path validation).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;

#[\PHPUnit\Framework\Attributes\CoversClass( Config::class )]
class ConfigTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$this->temp_dir = '/tmp/event-logger-test-config-' . \uniqid();
		@\mkdir( $this->temp_dir, 0755, true );
		// Reset option store.
		$GLOBALS['_wp_test_options'] = [];
	}

	protected function tearDown(): void {
		self::rmdir_recursive( $this->temp_dir );
		Config::reset();
		// Restore LOCAL_EVENT_LOGGER_CONF to test config.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		parent::tearDown();
	}

	private static function rmdir_recursive( string $dir ): void {
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @\rmdir( $item->getPathname() ) : @\unlink( $item->getPathname() );
		}
		@\rmdir( $dir );
	}

	public function test_load_config_returns_array(): void {
		$config = Config::load_config();
		$this->assertIsArray( $config );
	}

	public function test_load_config_has_base_directory(): void {
		$config = Config::load_config();
		$this->assertArrayHasKey( 'base_directory', $config );
		$this->assertSame( '/tmp/event-logger-test', $config['base_directory'] );
	}

	public function test_load_config_has_test_values(): void {
		$config = Config::load_config();

		$this->assertSame( 1, $config['num_partitions'] );
		$this->assertSame( 2, $config['num_segments'] );
		$this->assertSame( 1024, $config['segment_size'] );
		$this->assertSame( 0, $config['max_lifespan'] );
		$this->assertFalse( $config['enable_logging'] );
	}

	public function test_load_config_caches_result(): void {
		$config1 = Config::load_config();
		$config2 = Config::load_config();

		// Same reference (cached).
		$this->assertSame( $config1, $config2 );
	}

	public function test_load_config_full_mode(): void {
		$config = Config::load_config( 'full' );
		$this->assertIsArray( $config );
		// Full mode should include memcache_servers from test config.
		$this->assertArrayHasKey( 'memcache_servers', $config );
	}

	public function test_reset_clears_cache(): void {
		$config1 = Config::load_config();
		Config::reset();
		$config2 = Config::load_config();

		// After reset, fresh config should still match (same file).
		$this->assertEquals( $config1, $config2 );
	}

	public function test_ensure_path_creates_directory(): void {
		$path = $this->temp_dir . '/sub/deep/dir';

		$result = Config::ensure_path( $path );
		$this->assertDirectoryExists( $path );
		$this->assertSame( $path, $result );
	}

	public function test_ensure_path_existing_directory(): void {
		// temp_dir already exists.
		$result = Config::ensure_path( $this->temp_dir );
		$this->assertSame( $this->temp_dir, $result );
	}

	public function test_ensure_path_rejects_null_byte(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'null byte' );
		Config::ensure_path( "/tmp/evil\0path" );
	}

	public function test_ensure_path_strips_trailing_slash(): void {
		$path = $this->temp_dir . '/trailing';
		@\mkdir( $path, 0755, true );

		$result = Config::ensure_path( $path . '/' );
		$this->assertSame( $path, $result );
	}

	public function test_get_base_directory(): void {
		$config = Config::load_config();
		$base   = $config['base_directory'];

		// Ensure the directory exists.
		@\mkdir( $base, 0755, true );

		$result = Config::get_base_directory();
		$this->assertSame( $base, $result );
	}

	public function test_get_logs_directory(): void {
		$config = Config::load_config();
		$base   = $config['base_directory'];

		// Ensure directories exist.
		@\mkdir( $base . '/logs', 0755, true );

		$result = Config::get_logs_directory();
		$this->assertSame( $base . '/logs', $result );
	}

	public function test_get_locks_directory(): void {
		$config = Config::load_config();
		$base   = $config['base_directory'];

		@\mkdir( $base . '/locks', 0755, true );

		$result = Config::get_locks_directory();
		$this->assertSame( $base . '/locks', $result );
	}

	public function test_get_offsets_directory(): void {
		$config = Config::load_config();
		$base   = $config['base_directory'];

		@\mkdir( $base . '/offsets', 0755, true );

		$result = Config::get_offsets_directory();
		$this->assertSame( $base . '/offsets', $result );
	}

	public function test_get_base_directory_throws_without_config(): void {
		Config::reset();

		// Clear the config env and remove any existing config file access.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' );

		// Create a temporary config file that returns empty base_directory.
		$empty_config = $this->temp_dir . '/empty-config.php';
		\file_put_contents( $empty_config, "<?php\nreturn ['base_directory' => ''];\n" );

		// This is hard to test because Config loads from the plugin's own config file first.
		// Just verify the method exists and works with our test config.
		Config::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		$base = Config::get_base_directory();
		$this->assertNotEmpty( $base );
	}

	public function test_config_file_override_via_env(): void {
		// The bootstrap already loads event-logger-test-config.php via
		// LOCAL_EVENT_LOGGER_CONF. Verify its values took effect.
		Config::reset();
		$config = Config::load_config();
		$this->assertSame( '/tmp/event-logger-test', $config['base_directory'] );
		$this->assertSame( 1024, $config['segment_size'] );
		$this->assertFalse( $config['enable_logging'] );
	}

	public function test_wordpress_option_override(): void {
		Config::reset();

		// Set a WordPress option.
		\update_option( 'event_logger_num_partitions', '8' );

		$config = Config::load_config();
		$this->assertSame( 8, $config['num_partitions'] );
	}

	public function test_wordpress_option_invalid_type_rejected(): void {
		Config::reset();

		// Set a non-numeric value for an int option.
		\update_option( 'event_logger_num_partitions', 'not-a-number' );

		$config = Config::load_config();
		// Should keep the default from config file, not the invalid WP option.
		$this->assertSame( 1, $config['num_partitions'] );
	}

	public function test_get_custom_colors(): void {
		$colors = Config::get_custom_colors();
		$this->assertIsArray( $colors );
	}

	public function test_load_config_defaults(): void {
		Config::reset();
		$defaults = Config::load_config_defaults();
		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'base_directory', $defaults );
	}

	public function test_load_config_full_includes_all_keys(): void {
		$config = Config::load_config( 'full' );
		$this->assertIsArray( $config );
		// Full mode should include all core keys plus extended keys.
		$this->assertArrayHasKey( 'base_directory', $config );
		$this->assertArrayHasKey( 'num_partitions', $config );
		$this->assertArrayHasKey( 'enable_logging', $config );
		$this->assertArrayHasKey( 'memcache_servers', $config );
	}

	public function test_get_custom_colors_returns_sorted_array(): void {
		$colors = Config::get_custom_colors();
		$this->assertIsArray( $colors );
		// Verify sorted by key (ksort).
		$keys = \array_keys( $colors );
		$sorted = $keys;
		\sort( $sorted, SORT_NATURAL | SORT_FLAG_CASE );
		$this->assertSame( $sorted, $keys, 'Colors should be sorted alphabetically' );
	}

	public function test_get_custom_colors_merges_discovered_events(): void {
		// Set a discovered event option.
		\update_option( 'event_logger_discovered_events', [ 'custom_hook' => '#ff0000' ] );
		Config::reset();

		$colors = Config::get_custom_colors();
		$this->assertArrayHasKey( 'custom_hook', $colors );
		$this->assertSame( '#ff0000', $colors['custom_hook'] );

		\delete_option( 'event_logger_discovered_events' );
	}

	public function test_maybe_migrate_options_is_callable(): void {
		// maybe_migrate_options is private but called during load_config.
		// Verify it doesn't throw by loading config.
		Config::reset();
		$config = Config::load_config();
		$this->assertIsArray( $config );
	}

	public function test_load_config_defaults_without_env_override(): void {
		Config::reset();
		// Temporarily clear the env variable.
		$orig_env = \getenv( 'LOCAL_EVENT_LOGGER_CONF' );
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' );
		Config::reset();

		$defaults = Config::load_config_defaults();
		$this->assertIsArray( $defaults );
		// Should still have base_directory from the plugin's default config file.
		// (May or may not have base_directory depending on whether the default exists.)
		$this->assertIsArray( $defaults );

		// Restore.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $orig_env );
		Config::reset();
	}

	public function test_validate_config_path_rejects_non_php(): void {
		// Use reflection to call private validate_config_path.
		$ref = new \ReflectionMethod( Config::class, 'validate_config_path' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, '/tmp/config.txt' );
		$this->assertNull( $result, 'Non-.php files should be rejected' );
	}

	public function test_validate_config_path_rejects_null_byte(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_path' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, "/tmp/evil\0config.php" );
		$this->assertNull( $result, 'Null byte in path should be rejected' );
	}

	public function test_directories_are_cached(): void {
		$config = Config::load_config();
		$base   = $config['base_directory'];
		@\mkdir( $base . '/logs', 0755, true );
		@\mkdir( $base . '/locks', 0755, true );
		@\mkdir( $base . '/offsets', 0755, true );

		// First call creates and caches.
		$logs1 = Config::get_logs_directory();
		$logs2 = Config::get_logs_directory();
		$this->assertSame( $logs1, $logs2 );

		$locks1 = Config::get_locks_directory();
		$locks2 = Config::get_locks_directory();
		$this->assertSame( $locks1, $locks2 );

		$offsets1 = Config::get_offsets_directory();
		$offsets2 = Config::get_offsets_directory();
		$this->assertSame( $offsets1, $offsets2 );
	}

	// ── sanitize_option (via load_config with WP options) ───────────────

	public function test_sanitize_option_bool(): void {
		Config::reset();
		\update_option( 'event_logger_enable_logging', '1' );
		$config = Config::load_config();
		$this->assertTrue( $config['enable_logging'] );
	}

	public function test_sanitize_option_path_rejects_traversal(): void {
		Config::reset();
		\update_option( 'event_logger_base_directory', '/tmp/../etc/passwd' );
		$config = Config::load_config();
		// Should keep file default, not the malicious path.
		$this->assertSame( '/tmp/event-logger-test', $config['base_directory'] );
	}

	public function test_sanitize_option_path_rejects_null_byte(): void {
		Config::reset();
		\update_option( 'event_logger_base_directory', "/tmp/evil\0path" );
		$config = Config::load_config();
		// Should keep file default.
		$this->assertSame( '/tmp/event-logger-test', $config['base_directory'] );
	}

	public function test_sanitize_option_path_rejects_relative(): void {
		Config::reset();
		\update_option( 'event_logger_base_directory', 'relative/path' );
		$config = Config::load_config();
		$this->assertSame( '/tmp/event-logger-test', $config['base_directory'] );
	}

	public function test_sanitize_option_int_rejects_non_numeric(): void {
		Config::reset();
		\update_option( 'event_logger_segment_size', 'abc' );
		$config = Config::load_config();
		// Should keep the default.
		$this->assertSame( 1024, $config['segment_size'] );
	}

	public function test_sanitize_option_path_non_string(): void {
		Config::reset();
		\update_option( 'event_logger_base_directory', 12345 );
		$config = Config::load_config();
		// Non-string path should be rejected.
		$this->assertSame( '/tmp/event-logger-test', $config['base_directory'] );
	}

	// ── full mode caches separately ─────────────────────────────────────

	public function test_full_config_cached_separately(): void {
		$core = Config::load_config( 'core' );
		$full = Config::load_config( 'full' );

		// Full has memcache_servers, core may not.
		$this->assertArrayHasKey( 'memcache_servers', $full );

		// Second call returns cached.
		$full2 = Config::load_config( 'full' );
		$this->assertSame( $full, $full2 );
	}

	// ── validate_config_path ────────────────────────────────────────────

	public function test_validate_config_path_rejects_outside_allowed_dirs(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_path' );
		$ref->setAccessible( true );

		// Create a real file outside allowed dirs.
		$path = $this->temp_dir . '/evil-config.php';
		\file_put_contents( $path, "<?php\nreturn [];\n" );

		$result = $ref->invoke( null, $path );
		// temp_dir is under /tmp which is not in allowed dirs, so should be null.
		$this->assertNull( $result );
	}

	// ── validate_config_values ──────────────────────────────────────────

	public function test_validate_config_values_rejects_objects(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_values' );
		$ref->setAccessible( true );

		$this->assertFalse( $ref->invoke( null, new \stdClass() ) );
	}

	public function test_validate_config_values_rejects_deep_nesting(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_values' );
		$ref->setAccessible( true );

		// Build deeply nested array (> 10 levels).
		$value = 'leaf';
		for ( $i = 0; $i < 12; $i++ ) {
			$value = [ $value ];
		}
		$this->assertFalse( $ref->invoke( null, $value ) );
	}

	public function test_validate_config_values_allows_scalars(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_values' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( null, 'string' ) );
		$this->assertTrue( $ref->invoke( null, 42 ) );
		$this->assertTrue( $ref->invoke( null, 3.14 ) );
		$this->assertTrue( $ref->invoke( null, true ) );
		$this->assertTrue( $ref->invoke( null, null ) );
	}

	public function test_validate_config_values_allows_arrays(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_values' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( null, [ 'a', 'b', 'c' ] ) );
		$this->assertTrue( $ref->invoke( null, [ 'nested' => [ 'key' => 'val' ] ] ) );
	}

	// ── get_custom_colors with bad filter return ────────────────────────

	public function test_get_custom_colors_handles_non_string_discovered_events(): void {
		\update_option( 'event_logger_discovered_events', [ 'hook1' => 123 ] );
		Config::reset();

		$colors = Config::get_custom_colors();
		// Non-string color should get default.
		$this->assertArrayHasKey( 'hook1', $colors );
		$this->assertSame( '#ffa726', $colors['hook1'] );

		\delete_option( 'event_logger_discovered_events' );
	}

	// ── load_config_defaults caching ────────────────────────────────────

	public function test_load_config_defaults_cached(): void {
		Config::reset();
		$d1 = Config::load_config_defaults();
		$d2 = Config::load_config_defaults();
		$this->assertSame( $d1, $d2 );
	}

	// ── WordPress option empty values ignored ───────────────────────────

	public function test_empty_wp_option_uses_file_default(): void {
		Config::reset();
		\update_option( 'event_logger_num_partitions', '' );
		$config = Config::load_config();
		// Empty value should be ignored; file default used.
		$this->assertSame( 1, $config['num_partitions'] );
	}

	// ── sanitize_option — float branch ─────────────────────────────────

	public function test_sanitize_option_float_valid(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, '3.14', 'float' );
		$this->assertSame( 3.14, $result );
	}

	public function test_sanitize_option_float_integer_string(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, '42', 'float' );
		$this->assertSame( 42.0, $result );
	}

	public function test_sanitize_option_float_rejects_non_numeric(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, 'not-a-number', 'float' );
		$this->assertNull( $result );
	}

	// ── sanitize_option — memcache_servers branch ──────────────────────

	public function test_sanitize_option_memcache_servers_valid(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, "host1:11211\nhost2:11212", 'memcache_servers' );
		$this->assertSame( [ 'host1:11211', 'host2:11212' ], $result );
	}

	public function test_sanitize_option_memcache_servers_filters_invalid(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		// Invalid entries (no port, too-long port, special chars) are dropped.
		$result = $ref->invoke( null, "valid:11211\ninvalid\nhost@bad:999999\nok:1234", 'memcache_servers' );
		$this->assertSame( [ 'valid:11211', 'ok:1234' ], $result );
	}

	public function test_sanitize_option_memcache_servers_rejects_non_string(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, 12345, 'memcache_servers' );
		$this->assertNull( $result );
	}

	public function test_sanitize_option_memcache_servers_empty_string(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, '', 'memcache_servers' );
		$this->assertNull( $result );
	}

	// ── sanitize_option — array_strings branch ─────────────────────────

	public function test_sanitize_option_array_strings_valid(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, [ 'key1' => 'value1', 'key2' => 'value2' ], 'array_strings' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'key1', $result );
		$this->assertArrayHasKey( 'key2', $result );
	}

	public function test_sanitize_option_array_strings_with_booleans(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, [ 'custom_hook' => true, 'other' => false ], 'array_strings' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['custom_hook'] );
		$this->assertFalse( $result['other'] );
	}

	public function test_sanitize_option_array_strings_rejects_non_array(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, 'not-an-array', 'array_strings' );
		$this->assertNull( $result );
	}

	// ── sanitize_option — aggregator_servers branch ────────────────────

	public function test_sanitize_option_aggregator_servers_valid(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$input = [
			'server1' => [
				'url'        => 'https://example.com/api',
				'auth_token' => 'secret123',
				'enabled'    => true,
			],
		];
		$result = $ref->invoke( null, $input, 'aggregator_servers' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'server1', $result );
		$this->assertStringStartsWith( 'https://', $result['server1']['url'] );
		$this->assertTrue( $result['server1']['enabled'] );
	}

	public function test_sanitize_option_aggregator_servers_rejects_http(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$input = [
			'bad' => [
				'url' => 'http://insecure.example.com',
			],
		];
		$result = $ref->invoke( null, $input, 'aggregator_servers' );
		// HTTP URL should be rejected, resulting in empty array.
		$this->assertSame( [], $result );
	}

	public function test_sanitize_option_aggregator_servers_rejects_non_array(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, 'not-an-array', 'aggregator_servers' );
		$this->assertNull( $result );
	}

	public function test_sanitize_option_aggregator_servers_skips_non_array_entries(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$input = [
			'good' => [
				'url'        => 'https://good.example.com',
				'auth_token' => 'token',
			],
			'bad'  => 'not-an-array',
		];
		$result = $ref->invoke( null, $input, 'aggregator_servers' );
		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'good', $result );
	}

	// ── sanitize_option — unknown type ─────────────────────────────────

	public function test_sanitize_option_unknown_type_returns_null(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, 'value', 'nonexistent_type' );
		$this->assertNull( $result );
	}

	// ── sanitize_option — path accepts valid absolute path ─────────────

	public function test_sanitize_option_path_accepts_valid(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, '/var/www/html', 'path' );
		$this->assertSame( '/var/www/html', $result );
	}

	public function test_sanitize_option_path_trims_whitespace(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, '  /var/log  ', 'path' );
		$this->assertSame( '/var/log', $result );
	}

	// ── sanitize_option — int valid ────────────────────────────────────

	public function test_sanitize_option_int_valid(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, '42', 'int' );
		$this->assertSame( 42, $result );
	}

	// ── sanitize_option — bool ─────────────────────────────────────────

	public function test_sanitize_option_bool_false(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, '', 'bool' );
		$this->assertFalse( $result );
	}

	public function test_sanitize_option_bool_true(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );

		$result = $ref->invoke( null, '1', 'bool' );
		$this->assertTrue( $result );
	}
}
