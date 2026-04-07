<?php
/**
 * Tests for LruCache (bucket-based LRU cache).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\LruCache;

#[\PHPUnit\Framework\Attributes\CoversClass( LruCache::class )]
class LruCacheTest extends TestCase {

	public function test_get_set_basic(): void {
		$cache = new LruCache( 10, 3 );

		$cache->set( 'key1', 'value1' );
		$this->assertSame( 'value1', $cache->get( 'key1' ) );
	}

	public function test_get_nonexistent_returns_null(): void {
		$cache = new LruCache( 10, 3 );
		$this->assertNull( $cache->get( 'missing' ) );
	}

	public function test_set_overwrites_existing(): void {
		$cache = new LruCache( 10, 3 );

		$cache->set( 'key', 'old' );
		$cache->set( 'key', 'new' );
		$this->assertSame( 'new', $cache->get( 'key' ) );
	}

	public function test_set_different_value_types(): void {
		$cache = new LruCache( 10, 3 );

		$cache->set( 'int', 42 );
		$cache->set( 'float', 3.14 );
		$cache->set( 'bool', true );
		$cache->set( 'array', [ 1, 2, 3 ] );
		$cache->set( 'null', null );

		$this->assertSame( 42, $cache->get( 'int' ) );
		$this->assertSame( 3.14, $cache->get( 'float' ) );
		$this->assertTrue( $cache->get( 'bool' ) );
		$this->assertSame( [ 1, 2, 3 ], $cache->get( 'array' ) );
		$this->assertNull( $cache->get( 'null' ) );
	}

	public function test_delete_removes_entry(): void {
		$cache = new LruCache( 10, 3 );

		$cache->set( 'key', 'value' );
		$cache->delete( 'key' );
		$this->assertNull( $cache->get( 'key' ) );
	}

	public function test_delete_nonexistent_is_safe(): void {
		$cache = new LruCache( 10, 3 );
		// Should not throw.
		$cache->delete( 'nope' );
		$this->assertTrue( true );
	}

	public function test_lru_eviction(): void {
		// bucket_size=3, num_buckets=2 → max ~6 items before eviction.
		$cache = new LruCache( 3, 2 );

		// Fill first bucket (3 items).
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 );

		// This triggers rotation to bucket 1.
		$cache->set( 'd', 4 );
		$cache->set( 'e', 5 );
		$cache->set( 'f', 6 );

		// This triggers rotation to bucket 2, which evicts bucket 0.
		$cache->set( 'g', 7 );

		// Items a, b, c should have been evicted (oldest bucket).
		$this->assertNull( $cache->get( 'a' ), 'Evicted item should be null' );
		$this->assertNull( $cache->get( 'b' ), 'Evicted item should be null' );
		$this->assertNull( $cache->get( 'c' ), 'Evicted item should be null' );

		// Newer items should still be accessible.
		$this->assertSame( 4, $cache->get( 'd' ) );
		$this->assertSame( 7, $cache->get( 'g' ) );
	}

	public function test_get_promotes_to_current_bucket(): void {
		$cache = new LruCache( 3, 3 );

		// Fill bucket 0 with a, b, c.
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 );

		// Trigger rotation (now on bucket 1).
		$cache->set( 'd', 4 );

		// Access 'a' which is in bucket 0 — should promote to bucket 1.
		$this->assertSame( 1, $cache->get( 'a' ) );

		// Fill more items to trigger more rotations and evict bucket 0.
		$cache->set( 'e', 5 );
		$cache->set( 'f', 6 );
		// Bucket 1 now has d, a, e, f (> 3, so triggers rotation to bucket 2).
		$cache->set( 'g', 7 );
		$cache->set( 'h', 8 );
		$cache->set( 'i', 9 );

		// 'a' was promoted to bucket 1, so it should survive longer than 'b' and 'c'.
		// 'b' and 'c' stayed in bucket 0 which was evicted.
		$this->assertNull( $cache->get( 'b' ), 'Non-promoted item should be evicted' );
		$this->assertNull( $cache->get( 'c' ), 'Non-promoted item should be evicted' );
	}

	public function test_iterate_returns_all_entries(): void {
		$cache = new LruCache( 10, 3 );

		$cache->set( 'x', 1 );
		$cache->set( 'y', 2 );
		$cache->set( 'z', 3 );

		$items = [];
		foreach ( $cache->iterate() as $key => $value ) {
			$items[ $key ] = $value;
		}

		$this->assertCount( 3, $items );
		$this->assertSame( 1, $items['x'] );
		$this->assertSame( 2, $items['y'] );
		$this->assertSame( 3, $items['z'] );
	}

	public function test_iterate_empty_cache(): void {
		$cache = new LruCache( 10, 3 );

		$items = [];
		foreach ( $cache->iterate() as $key => $value ) {
			$items[ $key ] = $value;
		}

		$this->assertEmpty( $items );
	}

	public function test_iterate_across_buckets(): void {
		$cache = new LruCache( 2, 3 );

		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		// Triggers rotation.
		$cache->set( 'c', 3 );
		$cache->set( 'd', 4 );

		$items = [];
		foreach ( $cache->iterate() as $key => $value ) {
			$items[ $key ] = $value;
		}

		$this->assertCount( 4, $items );
	}

	public function test_get_state_and_restore_state(): void {
		$cache = new LruCache( 5, 3 );

		$cache->set( 'k1', 'v1' );
		$cache->set( 'k2', 'v2' );
		$cache->set( 'k3', 'v3' );

		$state = $cache->get_state();
		$this->assertArrayHasKey( 'buckets', $state );
		$this->assertArrayHasKey( 'current', $state );

		// Create new cache and restore.
		$cache2 = new LruCache( 5, 3 );
		$cache2->restore_state( $state );

		$this->assertSame( 'v1', $cache2->get( 'k1' ) );
		$this->assertSame( 'v2', $cache2->get( 'k2' ) );
		$this->assertSame( 'v3', $cache2->get( 'k3' ) );
	}

	public function test_restore_state_with_empty_state(): void {
		$cache = new LruCache( 5, 3 );
		$cache->set( 'existing', 'data' );

		$cache->restore_state( [] );
		// After restoring empty state, cache should be empty.
		$this->assertNull( $cache->get( 'existing' ) );
	}

	public function test_flush_clears_all(): void {
		$cache = new LruCache( 10, 3 );

		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );

		$cache->flush();

		$this->assertNull( $cache->get( 'a' ) );
		$this->assertNull( $cache->get( 'b' ) );
		$this->assertTrue( $cache->is_empty() );
	}

	public function test_is_empty(): void {
		$cache = new LruCache( 10, 3 );
		$this->assertTrue( $cache->is_empty() );

		$cache->set( 'key', 'val' );
		$this->assertFalse( $cache->is_empty() );

		$cache->flush();
		$this->assertTrue( $cache->is_empty() );
	}

	public function test_bucket_rotation_with_single_bucket(): void {
		// num_buckets=1 means only one bucket — rotation immediately evicts.
		$cache = new LruCache( 2, 1 );

		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		// Bucket full, rotation evicts the only bucket.
		$cache->set( 'c', 3 );

		// 'a' and 'b' should be gone.
		$this->assertNull( $cache->get( 'a' ) );
		$this->assertNull( $cache->get( 'b' ) );
		$this->assertSame( 3, $cache->get( 'c' ) );
	}

	public function test_large_number_of_items(): void {
		$cache = new LruCache( 100, 5 );

		for ( $i = 0; $i < 600; $i++ ) {
			$cache->set( "key{$i}", $i );
		}

		// Recent items should be available.
		$this->assertSame( 599, $cache->get( 'key599' ) );
		$this->assertSame( 598, $cache->get( 'key598' ) );

		// Old items should be evicted (max ~500 items with bucket_size=100, num_buckets=5).
		$this->assertNull( $cache->get( 'key0' ) );
		$this->assertNull( $cache->get( 'key1' ) );
	}

	public function test_get_state_preserves_bucket_structure(): void {
		$cache = new LruCache( 3, 3 );

		// Fill across multiple buckets.
		for ( $i = 0; $i < 9; $i++ ) {
			$cache->set( "k{$i}", $i );
		}

		$state = $cache->get_state();

		// Should have multiple buckets.
		$this->assertGreaterThan( 1, \count( $state['buckets'] ) );
		$this->assertGreaterThan( 0, $state['current'] );
	}

	// ── Timed rotation ─────────────────────────────────────────────────

	public function test_with_timed_rotation_returns_self(): void {
		$cache = new LruCache( 10, 3 );
		$result = $cache->with_timed_rotation( 1.0, function () {} );
		$this->assertSame( $cache, $result );
	}

	public function test_rotate_if_due_noop_without_timed_rotation(): void {
		$cache = new LruCache( 10, 3 );
		$cache->set( 'a', 1 );
		$cache->rotate_if_due();
		$this->assertSame( 1, $cache->get( 'a' ) );
	}

	public function test_rotate_if_due_rotates_after_interval(): void {
		$cache = new LruCache( 100, 2 );
		$evicted = [];
		$cache->with_timed_rotation( 0.001, function ( $k, $v ) use ( &$evicted ) {
			$evicted[ $k ] = $v;
		} );

		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );

		// Wait for interval then trigger rotation twice to evict first bucket.
		\usleep( 2000 );
		$cache->rotate_if_due(); // Bucket 0 -> bucket 1.
		\usleep( 2000 );
		$cache->rotate_if_due(); // Bucket 1 -> bucket 2, evicts bucket 0.

		$this->assertArrayHasKey( 'a', $evicted );
		$this->assertSame( 1, $evicted['a'] );
		$this->assertArrayHasKey( 'b', $evicted );
	}

	public function test_active_items_survive_timed_rotation(): void {
		$cache = new LruCache( 100, 3 );
		$evicted = [];
		$cache->with_timed_rotation( 0.001, function ( $k, $v ) use ( &$evicted ) {
			$evicted[] = $k;
		} );

		$cache->set( 'active', 'val' );

		// Rotate and promote — active item should survive.
		\usleep( 2000 );
		$cache->rotate_if_due();
		$cache->get( 'active' ); // Promote to current bucket.
		\usleep( 2000 );
		$cache->rotate_if_due();

		$this->assertSame( 'val', $cache->get( 'active' ) );
		$this->assertNotContains( 'active', $evicted );
	}

	public function test_evict_nonexistent_bucket_is_noop(): void {
		$cache = new LruCache( 100, 3 );
		$evicted = [];
		$cache->with_timed_rotation( 0.001, function ( $k, $v ) use ( &$evicted ) {
			$evicted[] = $k;
		} );

		// Force multiple rotations on an empty cache — evict_bucket on nonexistent index.
		\usleep( 2000 );
		$cache->rotate_if_due();
		\usleep( 2000 );
		$cache->rotate_if_due();
		\usleep( 2000 );
		$cache->rotate_if_due();

		$this->assertEmpty( $evicted );
	}

	public function test_evict_bucket_without_callback(): void {
		// Capacity-based rotation without on_evict should not throw.
		$cache = new LruCache( 2, 2 );
		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->set( 'c', 3 ); // Triggers rotation.
		$cache->set( 'd', 4 );
		$cache->set( 'e', 5 ); // Triggers eviction of oldest bucket.

		// 'a' and 'b' should be gone.
		$this->assertNull( $cache->get( 'a' ) );
		$this->assertNull( $cache->get( 'b' ) );
		$this->assertSame( 5, $cache->get( 'e' ) );
	}
}
