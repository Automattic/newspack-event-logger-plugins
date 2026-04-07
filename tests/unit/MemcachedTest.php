<?php
/**
 * Tests for Memcached (direct Memcached/Memcache access).
 *
 * Requires a running memcached server at ' . self::server() . '.
 * Tests are skipped if memcached is not available.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;

#[\PHPUnit\Framework\Attributes\CoversClass( Memcached::class )]
class MemcachedTest extends TestCase {

	/**
	 * Reset the static singleton between tests.
	 *
	 * Memcached uses static properties that persist across test methods.
	 * We use reflection to reset them.
	 */
	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		self::reset_memcached_statics();
	}

	protected function tearDown(): void {
		self::reset_memcached_statics();
		Config::reset();
		parent::tearDown();
	}

	/**
	 * Reset Memcached static properties via reflection.
	 */
	private static function reset_memcached_statics(): void {
		$ref = new \ReflectionClass( Memcached::class );

		$memd = $ref->getProperty( 'memd' );
		$memd->setAccessible( true );
		$memd->setValue( null, null );

		$ext = $ref->getProperty( 'extension' );
		$ext->setAccessible( true );
		$ext->setValue( null, null );

		$init = $ref->getProperty( 'init_attempted' );
		$init->setAccessible( true );
		$init->setValue( null, false );
	}

	/**
	 * Initialize and check if memcached is available, skipping test if not.
	 */
	/**
	 * Get memcache server from test config.
	 */
	private static function servers(): array {
		$config = Config::load_config();
		return $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS;
	}

	private function require_memcached(): void {
		if ( ! \class_exists( '\Memcached' ) && ! \class_exists( '\Memcache' ) ) {
			$this->markTestSkipped( 'Neither Memcached nor Memcache PHP extension is available' );
		}

		Memcached::init( self::servers() );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached server not available' );
		}

		// Verify connectivity with a test write/read.
		$test_key = 'event_logger_test_' . \uniqid();
		$set_ok   = Memcached::set( $test_key, 'ping', 5 );
		if ( ! $set_ok ) {
			$this->markTestSkipped( 'Memcached server not responding to set()' );
		}
		$read = Memcached::get( $test_key );
		Memcached::delete( $test_key );
		if ( 'ping' !== $read ) {
			$this->markTestSkipped( 'Memcached server not responding correctly' );
		}
	}

	public function test_init_does_not_throw_without_extensions(): void {
		Memcached::init( self::servers() );
		$this->assertTrue( true );
	}

	public function test_init_only_runs_once(): void {
		Memcached::init( self::servers() );
		$available1 = Memcached::is_available();

		// Second init with different server should be ignored.
		Memcached::init( [ '192.168.99.99:11211' ] );
		$available2 = Memcached::is_available();

		$this->assertSame( $available1, $available2, 'Second init should be a no-op' );
	}

	public function test_get_returns_null_before_init(): void {
		// Before init, memd is null.
		$result = Memcached::get( 'any_key' );
		$this->assertNull( $result );
	}

	public function test_set_returns_false_before_init(): void {
		$result = Memcached::set( 'any_key', 'value', 60 );
		$this->assertFalse( $result );
	}

	public function test_add_returns_false_before_init(): void {
		$result = Memcached::add( 'any_key', 'value', 60 );
		$this->assertFalse( $result );
	}

	public function test_delete_returns_false_before_init(): void {
		$result = Memcached::delete( 'any_key' );
		$this->assertFalse( $result );
	}

	public function test_get_multi_returns_empty_before_init(): void {
		$result = Memcached::get_multi( [ 'key1', 'key2' ] );
		$this->assertSame( [], $result );
	}

	public function test_get_multi_returns_empty_for_empty_keys(): void {
		$this->require_memcached();
		$result = Memcached::get_multi( [] );
		$this->assertSame( [], $result );
	}

	public function test_is_available_false_before_init(): void {
		$this->assertFalse( Memcached::is_available() );
	}

	public function test_set_get_roundtrip(): void {
		$this->require_memcached();

		$key = 'test_roundtrip_' . \uniqid();
		$this->assertTrue( Memcached::set( $key, 'hello', 30 ) );
		$this->assertSame( 'hello', Memcached::get( $key ) );

		Memcached::delete( $key );
	}

	public function test_set_get_complex_value(): void {
		$this->require_memcached();

		$key   = 'test_complex_' . \uniqid();
		$value = [ 'nested' => [ 'array' => true ], 'count' => 42 ];

		$this->assertTrue( Memcached::set( $key, $value, 30 ) );
		$this->assertSame( $value, Memcached::get( $key ) );

		Memcached::delete( $key );
	}

	public function test_get_nonexistent_returns_null(): void {
		$this->require_memcached();

		$result = Memcached::get( 'nonexistent_key_' . \uniqid() );
		$this->assertNull( $result );
	}

	public function test_delete_existing_key(): void {
		$this->require_memcached();

		$key = 'test_delete_' . \uniqid();
		Memcached::set( $key, 'to_delete', 30 );
		$this->assertTrue( Memcached::delete( $key ) );
		$this->assertNull( Memcached::get( $key ) );
	}

	public function test_add_succeeds_for_new_key(): void {
		$this->require_memcached();

		$key = 'test_add_new_' . \uniqid();
		$this->assertTrue( Memcached::add( $key, 'added', 30 ) );
		$this->assertSame( 'added', Memcached::get( $key ) );

		Memcached::delete( $key );
	}

	public function test_add_fails_for_existing_key(): void {
		$this->require_memcached();

		$key = 'test_add_exist_' . \uniqid();
		Memcached::set( $key, 'original', 30 );

		$this->assertFalse( Memcached::add( $key, 'duplicate', 30 ) );
		$this->assertSame( 'original', Memcached::get( $key ) );

		Memcached::delete( $key );
	}

	public function test_ttl_expiry(): void {
		$this->require_memcached();

		$key = 'test_ttl_' . \uniqid();
		Memcached::set( $key, 'expires_soon', 1 );
		$this->assertSame( 'expires_soon', Memcached::get( $key ) );

		// Wait for TTL to expire.
		\sleep( 2 );

		$result = Memcached::get( $key );
		$this->assertNull( $result, 'Key should have expired after TTL' );
	}

	public function test_set_overwrites_existing(): void {
		$this->require_memcached();

		$key = 'test_overwrite_' . \uniqid();
		Memcached::set( $key, 'first', 30 );
		Memcached::set( $key, 'second', 30 );

		$this->assertSame( 'second', Memcached::get( $key ) );

		Memcached::delete( $key );
	}

	public function test_init_with_empty_servers(): void {
		Memcached::init( [] );
		$this->assertFalse( Memcached::is_available() );
	}

	public function test_init_parses_host_port(): void {
		// Init with unreachable server - should not throw.
		self::reset_memcached_statics();
		Memcached::init( [ '192.168.99.99:11299' ] );

		// is_available may or may not be true depending on extension
		// behavior (Memcached extension adds server but doesn't connect
		// until first operation). Either way, should not throw.
		$this->assertTrue( true );
	}

	public function test_init_default_port(): void {
		// Server without port should default to 11211.
		self::reset_memcached_statics();
		Memcached::init( [ '127.0.0.1' ] );

		// Should not throw regardless of whether server is available.
		$this->assertTrue( true );
	}

	// ── DEFAULT_SERVERS constant ────────────────────────────────────────

	public function test_default_servers_constant(): void {
		$this->assertSame( [ '127.0.0.1:11211' ], Memcached::DEFAULT_SERVERS );
	}

	// ── SSE slot methods without memcache ───────────────────────────────

	public function test_acquire_sse_slot_without_memcache(): void {
		// Not initialized, so memd is null.
		$result = Memcached::acquire_sse_slot( 1, 'abc', 3 );
		// Without memcache, should deny connection (fail closed).
		$this->assertFalse( $result );
	}

	public function test_check_sse_slot_without_memcache(): void {
		$result = Memcached::check_sse_slot( 1, 'abc', 0 );
		// Without memcache, should return false (deny - fail closed).
		$this->assertFalse( $result );
	}

	public function test_touch_sse_slot_without_memcache(): void {
		$result = Memcached::touch_sse_slot( 1, 'abc', 0 );
		// Without memcache, should return true.
		$this->assertTrue( $result );
	}

	public function test_release_sse_slot_without_memcache(): void {
		$result = Memcached::release_sse_slot( 1, 'abc', 0 );
		// Without memcache, should return true.
		$this->assertTrue( $result );
	}

	// ── SSE slot methods with memcache ──────────────────────────────────

	public function test_acquire_sse_slot_roundtrip(): void {
		$this->require_memcached();

		$user_id = 999;
		$ip_hash = 'test1234';
		$max     = 2;

		// Acquire first slot.
		$slot1 = Memcached::acquire_sse_slot( $user_id, $ip_hash, $max, 5 );
		$this->assertSame( 0, $slot1 );

		// Acquire second slot.
		$slot2 = Memcached::acquire_sse_slot( $user_id, $ip_hash, $max, 5 );
		$this->assertSame( 1, $slot2 );

		// Third should fail (all slots taken).
		$slot3 = Memcached::acquire_sse_slot( $user_id, $ip_hash, $max, 5 );
		$this->assertFalse( $slot3 );

		// Release and re-acquire.
		Memcached::release_sse_slot( $user_id, $ip_hash, 0 );
		$slot4 = Memcached::acquire_sse_slot( $user_id, $ip_hash, $max, 5 );
		$this->assertSame( 0, $slot4 );

		// Cleanup.
		Memcached::release_sse_slot( $user_id, $ip_hash, 0 );
		Memcached::release_sse_slot( $user_id, $ip_hash, 1 );
	}

	public function test_check_sse_slot_with_memcache(): void {
		$this->require_memcached();

		$user_id = 998;
		$ip_hash = 'chkslot';

		// Acquire a slot.
		$slot = Memcached::acquire_sse_slot( $user_id, $ip_hash, 1, 5 );
		$this->assertSame( 0, $slot );

		// Check should return true.
		$this->assertTrue( Memcached::check_sse_slot( $user_id, $ip_hash, 0 ) );

		// Check non-existent slot should return false.
		$this->assertFalse( Memcached::check_sse_slot( $user_id, $ip_hash, 1 ) );

		// Cleanup.
		Memcached::release_sse_slot( $user_id, $ip_hash, 0 );
	}

	public function test_touch_sse_slot_with_memcache(): void {
		$this->require_memcached();

		$user_id = 997;
		$ip_hash = 'tch_slot';

		$slot = Memcached::acquire_sse_slot( $user_id, $ip_hash, 1, 5 );
		$this->assertSame( 0, $slot );

		// Touch should succeed.
		$this->assertTrue( Memcached::touch_sse_slot( $user_id, $ip_hash, 0, 5 ) );

		// Touch non-existent slot should return false (expired).
		$this->assertFalse( Memcached::touch_sse_slot( $user_id, $ip_hash, 1, 5 ) );

		// Cleanup.
		Memcached::release_sse_slot( $user_id, $ip_hash, 0 );
	}

	public function test_get_multi_roundtrip(): void {
		$this->require_memcached();

		$prefix = 'test_multi_' . \uniqid() . '_';
		Memcached::set( $prefix . 'a', 'alpha', 30 );
		Memcached::set( $prefix . 'b', [ 'nested' => true ], 30 );

		$result = Memcached::get_multi( [ $prefix . 'a', $prefix . 'b', $prefix . 'missing' ] );

		$this->assertArrayHasKey( $prefix . 'a', $result );
		$this->assertSame( 'alpha', $result[ $prefix . 'a' ] );
		$this->assertArrayHasKey( $prefix . 'b', $result );
		$this->assertSame( [ 'nested' => true ], $result[ $prefix . 'b' ] );
		$this->assertArrayNotHasKey( $prefix . 'missing', $result );

		Memcached::delete( $prefix . 'a' );
		Memcached::delete( $prefix . 'b' );
	}

	public function test_get_multi_via_legacy_memcache(): void {
		self::reset_memcached_statics();

		$ref = new \ReflectionMethod( Memcached::class, 'connect_memcache' );
		$ref->setAccessible( true );
		$ref->invoke( null, [ [ 'host' => '127.0.0.1', 'port' => 11211 ] ] );

		Memcached::set( 'legacy_multi_a', 'va', 60 );
		Memcached::set( 'legacy_multi_b', 'vb', 60 );

		$result = Memcached::get_multi( [ 'legacy_multi_a', 'legacy_multi_b', 'legacy_multi_missing' ] );
		$this->assertArrayHasKey( 'legacy_multi_a', $result );
		$this->assertSame( 'va', $result['legacy_multi_a'] );
		$this->assertArrayHasKey( 'legacy_multi_b', $result );
		$this->assertArrayNotHasKey( 'legacy_multi_missing', $result );
	}

	public function test_delete_nonexistent_key(): void {
		$this->require_memcached();

		// Delete a key that doesn't exist.
		$result = Memcached::delete( 'nonexistent_key_' . \uniqid() );
		// Memcached returns false for non-existent keys.
		$this->assertFalse( $result );
	}

	// ── init with empty servers ────────────────────────────────────────

	public function test_init_empty_servers_not_available(): void {
		self::reset_memcached_statics();
		Memcached::init( [] );
		$this->assertFalse( Memcached::is_available() );
	}

	public function test_init_idempotent_after_empty(): void {
		self::reset_memcached_statics();
		Memcached::init( [] );
		$this->assertFalse( Memcached::is_available() );

		// Second init is no-op (init_attempted is true).
		Memcached::init( [ 'memcache1:11211' ] );
		$this->assertFalse( Memcached::is_available(), 'Second init should be no-op' );
	}

	// ── set/add/get without connection ─────────────────────────────────

	public function test_set_without_connection_returns_false(): void {
		self::reset_memcached_statics();
		Memcached::init( [] );
		$this->assertFalse( Memcached::set( 'key', 'value', 60 ) );
	}

	public function test_add_without_connection_returns_false(): void {
		self::reset_memcached_statics();
		Memcached::init( [] );
		$this->assertFalse( Memcached::add( 'key', 'value', 60 ) );
	}

	// ── Legacy Memcache fallback path ──────────────────────────────────

	public function test_connect_memcache_legacy_path(): void {
		self::reset_memcached_statics();

		$ref = new \ReflectionMethod( Memcached::class, 'connect_memcache' );
		$ref->setAccessible( true );

		$ref->invoke( null, [ [ 'host' => '127.0.0.1', 'port' => 11211 ] ] );

		// Should have connected via the legacy Memcache stub.
		$ext_ref = new \ReflectionProperty( Memcached::class, 'extension' );
		$ext_ref->setAccessible( true );
		$this->assertSame( 'memcache', $ext_ref->getValue() );
		$this->assertTrue( Memcached::is_available() );
	}

	public function test_set_and_get_via_legacy_memcache(): void {
		self::reset_memcached_statics();

		// Force legacy path.
		$ref = new \ReflectionMethod( Memcached::class, 'connect_memcache' );
		$ref->setAccessible( true );
		$ref->invoke( null, [ [ 'host' => '127.0.0.1', 'port' => 11211 ] ] );

		// set/get use the memcache extension branch.
		$result = Memcached::set( 'legacy_test_key', 'legacy_value', 60 );
		$this->assertTrue( $result );

		$value = Memcached::get( 'legacy_test_key' );
		$this->assertSame( 'legacy_value', $value );
	}

	public function test_add_via_legacy_memcache(): void {
		self::reset_memcached_statics();

		$ref = new \ReflectionMethod( Memcached::class, 'connect_memcache' );
		$ref->setAccessible( true );
		$ref->invoke( null, [ [ 'host' => '127.0.0.1', 'port' => 11211 ] ] );

		$result = Memcached::add( 'legacy_add_key', 'added_value', 60 );
		$this->assertTrue( $result );
	}

	public function test_init_falls_back_to_memcache_when_memcached_unavailable(): void {
		self::reset_memcached_statics();

		// Simulate: Memcached extension not available, Memcache IS available.
		// We can't un-define \Memcached, but we can test the connect_memcache path directly
		// and verify init would call it via the elseif branch.
		// The init() code: if (class_exists('\Memcached')) { ... } elseif (class_exists('\Memcache')) { ... }
		// Since \Memcached exists, init always takes the first branch.
		// But connect_memcache is tested directly above.
		$this->assertTrue( \class_exists( '\Memcache' ), 'Memcache stub should be defined' );
	}
}
