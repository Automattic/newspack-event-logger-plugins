<?php
/**
 * Memcached
 *
 * Direct Memcached/Memcache access for Event Logger.
 * Supports both PHP extensions with consistent API.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memcache client with direct access to Memcached/Memcache.
 */
class Memcached {

	const DEFAULT_SERVERS = [ '127.0.0.1:11211' ];

	/**
	 * Memcached or Memcache connection instance.
	 *
	 * @var \Memcached|\Memcache|null
	 */
	private static $memd = null;

	/**
	 * Which extension is in use: 'memcached', 'memcache', or null.
	 *
	 * @var string|null
	 */
	private static ?string $extension = null;

	/**
	 * Whether initialization has been attempted.
	 *
	 * @var bool
	 */
	private static bool $init_attempted = false;

	/**
	 * Initialize the memcache connection.
	 *
	 * @param array $servers Array of memcache servers (host:port strings). Default: ['127.0.0.1:11211'].
	 */
	public static function init( array $servers = self::DEFAULT_SERVERS ): void {
		if ( self::$init_attempted ) {
			return;
		}
		self::$init_attempted = true;

		// Parse servers into host/port pairs.
		$parsed = [];
		foreach ( $servers as $server ) {
			$parts    = \explode( ':', $server );
			$parsed[] = [
				'host' => $parts[0] ?? '127.0.0.1',
				'port' => (int) ( $parts[1] ?? 11211 ),
			];
		}

		if ( empty( $parsed ) ) {
			return;
		}

		// Try Memcached extension first, then fall back to Memcache.
		if ( \class_exists( '\Memcached' ) ) {
			self::connect_memcached( $parsed );
		} elseif ( \class_exists( '\Memcache' ) ) {
			self::connect_memcache( $parsed );
		}
	}

	/**
	 * Connect using Memcached extension.
	 *
	 * @param array $servers Array of ['host' => string, 'port' => int].
	 */
	private static function connect_memcached( array $servers ): void {
		try {
			$memd = new \Memcached();
			foreach ( $servers as $server ) {
				$memd->addServer( $server['host'], $server['port'] );
			}

			if ( empty( $memd->getServerList() ) ) {
				self::$memd = null;
				return;
			}

			self::$memd      = $memd;
			self::$extension = 'memcached';
		} catch ( \Exception $e ) {
			self::$memd = null;
		}
	}

	/**
	 * Connect using Memcache extension (fallback).
	 *
	 * @param array $servers Array of ['host' => string, 'port' => int].
	 */
	private static function connect_memcache( array $servers ): void {
		try {
			$memd = new \Memcache();
			foreach ( $servers as $server ) {
				$memd->addServer( $server['host'], $server['port'] );
			}

			self::$memd      = $memd;
			self::$extension = 'memcache';
		} catch ( \Exception $e ) {
			self::$memd = null;
		}
	}

	/**
	 * Check if memcache is available.
	 *
	 * @return bool True if connected.
	 */
	public static function is_available(): bool {
		return null !== self::$memd;
	}

	/**
	 * Get value from memcache.
	 *
	 * @param string $key Cache key.
	 * @return mixed|null Cached value or null if not found.
	 */
	public static function get( string $key ) {
		if ( null === self::$memd ) {
			return null;
		}
		$result = self::$memd->get( $key );
		return false === $result ? null : $result;
	}

	/**
	 * Set value in memcache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time to live in seconds.
	 * @return bool Success.
	 */
	public static function set( string $key, $value, int $ttl ): bool {
		if ( null === self::$memd ) {
			return false;
		}
		// Memcached: set(key, value, ttl)
		// Memcache: set(key, value, flags, ttl)
		if ( 'memcached' === self::$extension ) {
			return self::$memd->set( $key, $value, $ttl );
		}
		return self::$memd->set( $key, $value, 0, $ttl );
	}

	/**
	 * Add value to memcache (only if key doesn't exist).
	 *
	 * This is atomic - returns false if key already exists.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time to live in seconds.
	 * @return bool True if added, false if key exists or error.
	 */
	public static function add( string $key, $value, int $ttl ): bool {
		if ( null === self::$memd ) {
			return false;
		}
		// Memcached: add(key, value, ttl)
		// Memcache: add(key, value, flags, ttl)
		if ( 'memcached' === self::$extension ) {
			return self::$memd->add( $key, $value, $ttl );
		}
		return self::$memd->add( $key, $value, 0, $ttl );
	}

	/**
	 * Delete a key from memcache.
	 *
	 * @param string $key Cache key.
	 * @return bool True if deleted, false otherwise.
	 */
	public static function delete( string $key ): bool {
		if ( null === self::$memd ) {
			return false;
		}
		return self::$memd->delete( $key );
	}

	/**
	 * Get multiple values from memcache in a single round-trip.
	 *
	 * @param array $keys Array of cache keys.
	 * @return array Associative array of key => value for found keys.
	 */
	public static function get_multi( array $keys ): array {
		if ( null === self::$memd || empty( $keys ) ) {
			return [];
		}
		if ( 'memcached' === self::$extension ) {
			$result = self::$memd->getMulti( $keys );
			return \is_array( $result ) ? $result : [];
		}
		// Memcache extension: no native multi-get, fall back to serial.
		$result = [];
		foreach ( $keys as $key ) {
			$val = self::$memd->get( $key );
			if ( false !== $val ) {
				$result[ $key ] = $val;
			}
		}
		return $result;
	}

	// -------------------------------------------------------------------------
	// SSE Connection Slots
	// -------------------------------------------------------------------------

	/**
	 * SSE slot TTL in seconds.
	 */
	private const SSE_SLOT_TTL = 10;

	/**
	 * Build SSE slot key.
	 *
	 * Aggregator connections pass a partition >= 0 so each partition gets
	 * its own slot pool — one stream-merger per partition shouldn't be able
	 * to crowd browser tabs (or other partitions) out of the global 10-slot
	 * pool.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP address.
	 * @param int    $slot      Slot number.
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return string Cache key.
	 */
	private static function sse_slot_key( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): string {
		if ( $partition >= 0 ) {
			return "evlog:sse:{$user_id}:{$ip_hash}:p{$partition}:{$slot}";
		}
		return "evlog:sse:{$user_id}:{$ip_hash}:{$slot}";
	}

	/**
	 * Acquire an SSE connection slot.
	 *
	 * Uses atomic add() to claim an available slot.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP (use substr(md5($ip), 0, 8)).
	 * @param int    $max_slots Maximum number of slots.
	 * @param int    $ttl       Slot TTL in seconds (10 for browsers, 30 for aggregators).
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return int|false Slot number on success, false if all slots taken or no memcache.
	 */
	public static function acquire_sse_slot( int $user_id, string $ip_hash, int $max_slots, int $ttl = self::SSE_SLOT_TTL, int $partition = -1 ): int|false {
		if ( null === self::$memd ) {
			// No memcache - deny connection (fail closed).
			return false;
		}

		$connection_id = \wp_generate_uuid4();

		for ( $slot = 0; $slot < $max_slots; $slot++ ) {
			$key = self::sse_slot_key( $user_id, $ip_hash, $slot, $partition );
			// add() is atomic - only succeeds if key doesn't exist.
			if ( self::add( $key, $connection_id, $ttl ) ) {
				return $slot;
			}
		}

		return false;
	}

	/**
	 * Check if an SSE slot is still alive (without refreshing TTL).
	 *
	 * Called by SSE loop to check if browser is still sending heartbeats.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP.
	 * @param int    $slot      Slot number.
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return bool True if slot exists.
	 */
	public static function check_sse_slot( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): bool {
		if ( null === self::$memd ) {
			return false;  // No memcache - deny (fail closed).
		}

		$key = self::sse_slot_key( $user_id, $ip_hash, $slot, $partition );
		return null !== self::get( $key );
	}

	/**
	 * Touch an SSE slot to keep it alive.
	 *
	 * Called by heartbeat endpoint.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP.
	 * @param int    $slot      Slot number.
	 * @param int    $ttl       Slot TTL in seconds (10 for browsers, 30 for aggregators).
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return bool Success.
	 */
	public static function touch_sse_slot( int $user_id, string $ip_hash, int $slot, int $ttl = self::SSE_SLOT_TTL, int $partition = -1 ): bool {
		if ( null === self::$memd ) {
			return true;
		}

		$key = self::sse_slot_key( $user_id, $ip_hash, $slot, $partition );

		// Memcached extension has native atomic touch().
		if ( 'memcached' === self::$extension && \method_exists( self::$memd, 'touch' ) ) {
			return self::$memd->touch( $key, $ttl );
		}

		// Fallback for Memcache extension: non-atomic get-then-set.
		$value = self::get( $key );
		if ( null === $value ) {
			// Slot expired - connection is stale, should exit.
			return false;
		}
		return self::set( $key, $value, $ttl );
	}

	/**
	 * Release an SSE slot.
	 *
	 * Optional - slots auto-expire via TTL.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP.
	 * @param int    $slot      Slot number.
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return bool Success.
	 */
	public static function release_sse_slot( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): bool {
		if ( null === self::$memd ) {
			return true;
		}

		$key = self::sse_slot_key( $user_id, $ip_hash, $slot, $partition );
		return self::delete( $key );
	}

}
