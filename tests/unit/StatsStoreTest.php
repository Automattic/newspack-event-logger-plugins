<?php
/**
 * Tests for StatsStore (Memcache-based storage for performance stats).
 *
 * StatsStore wraps Memcached. Tests that require memcached are skipped if
 * memcached is not available.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;
use Newspack_Performance_Workers\StatsStore;

#[\PHPUnit\Framework\Attributes\CoversClass( StatsStore::class )]
class StatsStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_options'] = [];
		self::reset_stats_store_statics();
		self::reset_memcached_statics();
	}

	protected function tearDown(): void {
		// Remove any test salt so workers don't get orphaned keys.
		\delete_option( StatsStore::SALT_OPTION );
		self::reset_stats_store_statics();
		self::reset_memcached_statics();
		Config::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		parent::tearDown();
	}

	/**
	 * Reset StatsStore static properties via reflection.
	 */
	private static function reset_stats_store_statics(): void {
		$ref = new \ReflectionClass( StatsStore::class );

		$prefix = $ref->getProperty( 'prefix' );
		$prefix->setAccessible( true );
		$prefix->setValue( null, 'evlog' );

		$retention = $ref->getProperty( 'retention' );
		$retention->setAccessible( true );
		$retention->setValue( null, 86400 );

		$num_p = $ref->getProperty( 'num_partitions' );
		$num_p->setAccessible( true );
		$num_p->setValue( null, 1 );
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
	 * Initialize memcached and skip test if not available.
	 */
	private static function servers(): array {
		$config = Config::load_config();
		return $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS;
	}

	private function require_memcached(): void {
		if ( ! \class_exists( '\Memcached' ) && ! \class_exists( '\Memcache' ) ) {
			$this->markTestSkipped( 'Neither Memcached nor Memcache PHP extension is available' );
		}

		StatsStore::init( 1, self::servers(), 86400 );

		if ( ! Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached server not available' );
		}

		$test_key = 'statsstore_test_' . \uniqid();
		$set_ok   = Memcached::set( $test_key, 'ping', 5 );
		if ( ! $set_ok ) {
			$this->markTestSkipped( 'Memcached server not responding' );
		}
		$read = Memcached::get( $test_key );
		Memcached::delete( $test_key );
		if ( 'ping' !== $read ) {
			$this->markTestSkipped( 'Memcached server not responding correctly' );
		}
	}

	// ── init ──────────────────────────────────────────────────────────────

	public function test_init_sets_retention(): void {
		StatsStore::init( 2, [], 7200 );
		$this->assertSame( 7200, StatsStore::get_retention() );
	}

	public function test_init_floors_retention_at_one_hour(): void {
		StatsStore::init( 1, [], 100 );
		$this->assertSame( 3600, StatsStore::get_retention(), 'Retention should be floored at 3600' );
	}

	public function test_init_sets_partition_count(): void {
		StatsStore::init( 4, [], 86400 );
		$ref = new \ReflectionProperty( StatsStore::class, 'num_partitions' );
		$ref->setAccessible( true );
		$this->assertSame( 4, $ref->getValue() );
	}

	public function test_init_caps_partitions_at_max(): void {
		StatsStore::init( 100, [], 86400 );
		$ref = new \ReflectionProperty( StatsStore::class, 'num_partitions' );
		$ref->setAccessible( true );
		$this->assertSame( 16, $ref->getValue(), 'Partitions should be capped at MAX_PARTITIONS (16)' );
	}

	// ── get_retention ────────────────────────────────────────────────────

	public function test_get_retention_returns_configured_value(): void {
		StatsStore::init( 1, [], 43200 );
		$this->assertSame( 43200, StatsStore::get_retention() );
	}

	// ── ttl ──────────────────────────────────────────────────────────────

	public function test_ttl_matches_retention(): void {
		StatsStore::init( 1, [], 86400 );
		$this->assertSame( 86400, StatsStore::ttl() );
	}

	// ── ttl_url_stats ────────────────────────────────────────────────────

	public function test_ttl_url_stats_is_24th_of_retention(): void {
		StatsStore::init( 1, [], 86400 );
		$this->assertSame( 3600, StatsStore::ttl_url_stats() ); // 86400 / 24 = 3600.
	}

	public function test_ttl_url_stats_minimum_3600(): void {
		// With retention = 3600, 3600/24 = 150 which is below minimum.
		StatsStore::init( 1, [], 3600 );
		$this->assertSame( 3600, StatsStore::ttl_url_stats(), 'TTL should be at minimum 3600' );
	}

	public function test_ttl_url_stats_large_retention(): void {
		StatsStore::init( 1, [], 172800 ); // 48 hours.
		$this->assertSame( 7200, StatsStore::ttl_url_stats() ); // 172800 / 24 = 7200.
	}

	// ── key format ───────────────────────────────────────────────────────

	public function test_key_format(): void {
		$ref = new \ReflectionMethod( StatsStore::class, 'key' );
		$ref->setAccessible( true );

		// Reset prefix to known value.
		$prefix_prop = new \ReflectionProperty( StatsStore::class, 'prefix' );
		$prefix_prop->setAccessible( true );
		$prefix_prop->setValue( null, 'evlog' );

		$key = $ref->invoke( null, 'p0', 'hourly' );
		$this->assertSame( 'evlog:p0:hourly', $key );

		$key = $ref->invoke( null, 'p1', 'url', 'abc123' );
		$this->assertSame( 'evlog:p1:url:abc123', $key );
	}

	public function test_key_includes_salt_when_set(): void {
		$ref = new \ReflectionMethod( StatsStore::class, 'key' );
		$ref->setAccessible( true );

		$prefix_prop = new \ReflectionProperty( StatsStore::class, 'prefix' );
		$prefix_prop->setAccessible( true );
		$prefix_prop->setValue( null, 'evlog:mysalt' );

		$key = $ref->invoke( null, 'p0', 'hourly' );
		$this->assertSame( 'evlog:mysalt:p0:hourly', $key );
	}

	// ── get_retention_buckets ────────────────────────────────────────────

	public function test_get_retention_buckets_count(): void {
		StatsStore::init( 1, [], 3600 ); // 1 hour retention.

		$ref = new \ReflectionMethod( StatsStore::class, 'get_retention_buckets' );
		$ref->setAccessible( true );
		$buckets = $ref->invoke( null );

		// 3600 / 300 = 12 buckets, after array_unique may be less due to boundary.
		$this->assertGreaterThanOrEqual( 10, \count( $buckets ) );
		$this->assertLessThanOrEqual( 13, \count( $buckets ) );

		// All bucket keys should match Y-m-d-H-MM format.
		foreach ( $buckets as $key ) {
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}$/', $key );
		}
	}

	public function test_get_retention_buckets_large_retention(): void {
		StatsStore::init( 1, [], 86400 ); // 24 hours.

		$ref = new \ReflectionMethod( StatsStore::class, 'get_retention_buckets' );
		$ref->setAccessible( true );
		$buckets = $ref->invoke( null );

		// 86400 / 300 = 288 buckets, after unique may be slightly less.
		$this->assertGreaterThanOrEqual( 280, \count( $buckets ) );
		$this->assertLessThanOrEqual( 289, \count( $buckets ) );
	}

	// ── get_merged_url_index (without memcached) ─────────────────────────

	public function test_get_merged_url_index_empty(): void {
		StatsStore::init( 1, [], 3600 );
		$result = StatsStore::get_merged_url_index();
		$this->assertSame( [], $result );
	}

	// ── salt rotation ────────────────────────────────────────────────────

	public function test_init_uses_salt_from_option(): void {
		\update_option( StatsStore::SALT_OPTION, 'test_salt_abc' );

		StatsStore::init( 1, [], 86400 );

		$prefix_prop = new \ReflectionProperty( StatsStore::class, 'prefix' );
		$prefix_prop->setAccessible( true );
		$prefix = $prefix_prop->getValue();

		$this->assertSame( 'evlog:test_salt_abc', $prefix );
	}

	public function test_init_without_salt_uses_base_prefix(): void {
		// No salt set.
		\delete_option( StatsStore::SALT_OPTION );

		StatsStore::init( 1, [], 86400 );

		$prefix_prop = new \ReflectionProperty( StatsStore::class, 'prefix' );
		$prefix_prop->setAccessible( true );
		$prefix = $prefix_prop->getValue();

		$this->assertSame( 'evlog', $prefix );
	}

	// ── Memcached-dependent tests ────────────────────────────────────────

	public function test_set_get_hourly_roundtrip(): void {
		$this->require_memcached();

		$data = [
			'2024-01-15-10-05' => [ 'count' => 5, 'sum_ms' => 500.0, 'sum_peak_mb' => 50.0 ],
		];

		$this->assertTrue( StatsStore::set_hourly( 0, $data ) );
		$result = StatsStore::get_hourly( 0 );
		$this->assertSame( $data, $result );
	}

	public function test_set_get_url_stats_roundtrip(): void {
		$this->require_memcached();

		$data = [
			'flame' => [ 'name' => 'request', 'value' => 100, 'children' => [] ],
		];

		$url_hash = 'abc123def456';
		$this->assertTrue( StatsStore::set_url_stats( 0, $url_hash, $data ) );
		$result = StatsStore::get_url_stats( 0, $url_hash );
		$this->assertSame( $data, $result );
	}

	/**
	 * Rotate the stats salt to orphan existing keys, then re-init.
	 */
	private function rotate_and_reinit(): void {
		\update_option( StatsStore::SALT_OPTION, \uniqid( 'test_' ), true );
		self::reset_stats_store_statics();
		StatsStore::init( 1, self::servers(), 86400 );
	}

	public function test_get_hourly_returns_null_without_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();
		$result = StatsStore::get_hourly( 0 );
		$this->assertNull( $result );
	}

	public function test_get_url_stats_returns_null_without_data(): void {
		$this->require_memcached();
		$result = StatsStore::get_url_stats( 0, 'nonexistent_hash_' . \uniqid() );
		$this->assertNull( $result );
	}

	public function test_get_merged_hourly_empty(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();
		$result = StatsStore::get_merged_hourly();
		$this->assertSame( [], $result );
	}

	public function test_get_merged_leaderboard_null(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();
		$result = StatsStore::get_merged_leaderboard();
		$this->assertNull( $result );
	}

	public function test_dimensions_constant(): void {
		$this->assertContains( 'status', StatsStore::DIMENSIONS );
		$this->assertContains( 'method', StatsStore::DIMENSIONS );
		$this->assertContains( 'server', StatsStore::DIMENSIONS );
		$this->assertCount( 7, StatsStore::DIMENSIONS );
	}

	public function test_prefix_base_constant(): void {
		$this->assertSame( 'evlog', StatsStore::PREFIX_BASE );
	}

	public function test_salt_option_constant(): void {
		$this->assertSame( 'event_logger_stats_salt', StatsStore::SALT_OPTION );
	}

	// ── Memcached-dependent: merged hourly with data ────────────────────

	public function test_get_merged_hourly_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		// Set hourly data for partition 0.
		$data = [
			'2024-01-15-10-05' => [ 'count' => 5, 'sum_ms' => 500.0, 'sum_peak_mb' => 50.0 ],
			'2024-01-15-10-10' => [ 'count' => 3, 'sum_ms' => 300.0, 'sum_peak_mb' => 30.0 ],
		];
		StatsStore::set_hourly( 0, $data );

		$merged = StatsStore::get_merged_hourly();
		$this->assertArrayHasKey( '2024-01-15-10-05', $merged );
		$this->assertSame( 5, $merged['2024-01-15-10-05']['count'] );
		$this->assertSame( 3, $merged['2024-01-15-10-10']['count'] );
	}

	public function test_get_merged_hourly_multiple_partitions(): void {
		$this->require_memcached();

		// Init with 2 partitions.
		self::reset_stats_store_statics();
		self::reset_memcached_statics();
		$this->rotate_and_reinit();

		$ref = new \ReflectionProperty( StatsStore::class, 'num_partitions' );
		$ref->setAccessible( true );
		$ref->setValue( null, 2 );

		$data_p0 = [ '2024-01-15-10-05' => [ 'count' => 5, 'sum_ms' => 500.0, 'sum_peak_mb' => 50.0 ] ];
		$data_p1 = [ '2024-01-15-10-05' => [ 'count' => 3, 'sum_ms' => 300.0, 'sum_peak_mb' => 30.0 ] ];

		StatsStore::set_hourly( 0, $data_p0 );
		StatsStore::set_hourly( 1, $data_p1 );

		$merged = StatsStore::get_merged_hourly();
		$this->assertArrayHasKey( '2024-01-15-10-05', $merged );
		$this->assertSame( 8, $merged['2024-01-15-10-05']['count'] );
		$this->assertEqualsWithDelta( 800.0, $merged['2024-01-15-10-05']['sum_ms'], 0.01 );
	}

	// ── Memcached-dependent: leaderboard (bucketed, sums) ───────────────

	public function test_set_get_leaderboard_bucket_roundtrip(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$bucket = $this->current_bucket_key();
		$data   = [
			'count'        => 10,
			'sum_req_time' => 500.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 10,
					'sum_time'  => 500.0, // 50 avg * 10 requests
					'sum_count' => 30.0,  // 3 avg * 10 requests
					'entries'   => [],
				],
			],
		];

		$this->assertTrue( StatsStore::set_leaderboard_bucket( 0, $bucket, $data ) );
		$result = StatsStore::get_leaderboard_bucket( 0, $bucket );
		$this->assertSame( $data, $result );
	}

	public function test_get_merged_leaderboard_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$bucket = $this->current_bucket_key();
		$data   = [
			'count'        => 10,
			'sum_req_time' => 500.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 10,
					'sum_time'  => 500.0,
					'sum_count' => 30.0,
					'entries'   => [],
				],
			],
		];
		StatsStore::set_leaderboard_bucket( 0, $bucket, $data );

		$merged = StatsStore::get_merged_leaderboard();
		$this->assertNotNull( $merged );
		$this->assertSame( 10, $merged['count'] );
		// Display shape: total_time = sum_req_time / count = 500 / 10 = 50.
		$this->assertEqualsWithDelta( 50.0, $merged['total_time'], 0.01 );
		$this->assertArrayHasKey( 'wp_head', $merged['categories'] );
		// time = sum_time / count = 500 / 10 = 50.
		$this->assertEqualsWithDelta( 50.0, $merged['categories']['wp_head']['time'], 0.01 );
		// count = sum_count / count = 30 / 10 = 3.
		$this->assertEqualsWithDelta( 3.0, $merged['categories']['wp_head']['count'], 0.01 );
		$this->assertSame( 10, $merged['categories']['wp_head']['samples'] );
	}

	/**
	 * Generate the bucket key for the current 5-minute window (Y-m-d-H-NN UTC).
	 */
	private function current_bucket_key(): string {
		$now     = \time();
		$min     = (int) \gmdate( 'i', $now );
		$rounded = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', STR_PAD_LEFT );
		return \gmdate( 'Y-m-d-H', $now ) . '-' . $rounded;
	}

	// ── Memcached-dependent: URL stats ──────────────────────────────────

	public function test_get_url_stats_any_partition(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$hash = 'test_url_hash';
		$data = [ 'flame' => [ 'name' => 'request', 'value' => 100 ] ];

		StatsStore::set_url_stats( 0, $hash, $data );

		$result = StatsStore::get_url_stats_any_partition( $hash );
		$this->assertNotNull( $result );
		$this->assertSame( $data, $result );
	}

	public function test_get_url_stats_any_partition_not_found(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_url_stats_any_partition( 'nonexistent_' . \uniqid() );
		$this->assertNull( $result );
	}

	// ── Memcached-dependent: dimensional stats ──────────────────────────

	public function test_set_get_dimensional_roundtrip(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$data = [
			'2024-01-15-10-05' => [
				'2xx' => [ 'c' => 5, 's' => 500, 'm' => 50 ],
				'4xx' => [ 'c' => 2, 's' => 100, 'm' => 10 ],
			],
		];

		$this->assertTrue( StatsStore::set_dimensional( 0, 'status', $data ) );
		$result = StatsStore::get_dimensional( 0, 'status' );
		$this->assertSame( $data, $result );
	}

	public function test_get_merged_dimensional_empty(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_merged_dimensional( 'status' );
		$this->assertSame( [], $result );
	}

	public function test_set_get_dimensional_with_server(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$data = [ '2024-01-15-10-05' => [ 'GET' => [ 'c' => 3, 's' => 300, 'm' => 30 ] ] ];

		$this->assertTrue( StatsStore::set_dimensional( 0, 'method', $data, 'web1' ) );
		$result = StatsStore::get_dimensional( 0, 'method', 'web1' );
		$this->assertSame( $data, $result );
	}

	// ── Memcached-dependent: URL dimensional stats ──────────────────────

	public function test_set_get_url_dimensional(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$hash = 'url_dim_test';
		$data = [
			'status' => [
				'2024-01-15-10-05' => [ '2xx' => [ 'c' => 3, 's' => 300, 'm' => 0 ] ],
			],
		];

		$this->assertTrue( StatsStore::set_url_dimensional( 0, $hash, $data ) );
		$result = StatsStore::get_url_dimensional( 0, $hash );
		$this->assertSame( $data, $result );
	}

	public function test_get_merged_url_dimensional_empty(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_merged_url_dimensional( 'nonexistent', 'status' );
		$this->assertSame( [], $result );
	}

	public function test_get_merged_url_dimensional_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$hash = 'url_dim_merge';
		$data = [
			'method' => [
				'2024-01-15-10-05' => [
					'GET'  => [ 'c' => 5, 's' => 500, 'm' => 50 ],
					'POST' => [ 'c' => 2, 's' => 200, 'm' => 20 ],
				],
			],
		];
		StatsStore::set_url_dimensional( 0, $hash, $data );

		$result = StatsStore::get_merged_url_dimensional( $hash, 'method' );
		$this->assertArrayHasKey( '2024-01-15-10-05', $result );
		$this->assertSame( 5, $result['2024-01-15-10-05']['GET']['c'] );
		$this->assertSame( 2, $result['2024-01-15-10-05']['POST']['c'] );
	}

	public function test_get_merged_dimensional_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$data = [
			'2024-01-15-10-05' => [
				'GET'  => [ 'c' => 10, 's' => 1000, 'm' => 100 ],
				'POST' => [ 'c' => 3, 's' => 300, 'm' => 30 ],
			],
		];
		StatsStore::set_dimensional( 0, 'method', $data );

		$result = StatsStore::get_merged_dimensional( 'method' );
		$this->assertArrayHasKey( '2024-01-15-10-05', $result );
		$this->assertSame( 10, $result['2024-01-15-10-05']['GET']['c'] );
	}

	// ── Memcached-dependent: server leaderboard (bucketed, sums) ────────

	public function test_set_get_server_leaderboard_bucket(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$bucket = $this->current_bucket_key();
		$data   = [
			'count'        => 5,
			'sum_req_time' => 250.0,
			'categories'   => [],
		];

		$this->assertTrue( StatsStore::set_server_leaderboard_bucket( 0, 'web1', $bucket, $data ) );
		$result = StatsStore::get_server_leaderboard_bucket( 0, 'web1', $bucket );
		$this->assertSame( $data, $result );
	}

	public function test_get_merged_server_leaderboard_null(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_merged_server_leaderboard( 'nonexistent' );
		$this->assertNull( $result );
	}

	// ── URL index ───────────────────────────────────────────────────────

	public function test_set_get_url_index_hourly(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$data = [
			'hash1' => [ 'url' => '/test', 'count' => 3, 'sum_ms' => 300 ],
		];
		$bucket = '2024-01-15-10-05';

		$this->assertTrue( StatsStore::set_url_index_hourly( 0, $bucket, $data ) );
		$result = StatsStore::get_url_index_hourly( 0, $bucket );
		$this->assertSame( $data, $result );
	}

	// ── get_url_time_series ─────────────────────────────────────────────

	public function test_get_url_time_series_empty(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_url_time_series( 'nonexistent_hash' );
		$this->assertIsArray( $result );
		// Should have one entry per bucket in the retention window.
		$this->assertNotEmpty( $result );
		// Each bucket should have zeroed data.
		$first = \reset( $result );
		$this->assertSame( 0, $first['count'] );
	}

	// ── Memcached-dependent: get_merged_url_index with data ─────────────

	public function test_get_merged_url_index_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		// Generate a current bucket key to match retention window.
		$now     = \time();
		$min     = (int) \gmdate( 'i', $now );
		$rounded = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', STR_PAD_LEFT );
		$bucket  = \gmdate( 'Y-m-d-H', $now ) . '-' . $rounded;

		$data = [
			'hash_abc' => [
				'url'         => '/test-page',
				'count'       => 10,
				'timed_count' => 8,
				'sum_ms'      => 1000.0,
				'min_ms'      => 50.0,
				'max_ms'      => 200.0,
				'last_seen'   => $now,
				'durations'   => [ 50.0, 100.0, 150.0, 200.0 ],
				'count_2xx'   => 8,
				'count_3xx'   => 0,
				'count_4xx'   => 1,
				'count_5xx'   => 1,
				'sum_peak_mb' => 80.0,
				'max_peak_mb' => 15.0,
			],
		];
		StatsStore::set_url_index_hourly( 0, $bucket, $data );

		$result = StatsStore::get_merged_url_index();
		$this->assertNotEmpty( $result );

		// Result is array of values (not keyed by hash).
		$found = false;
		foreach ( $result as $entry ) {
			if ( '/test-page' === $entry['url'] ) {
				$found = true;
				$this->assertSame( 10, $entry['count'] );
				$this->assertSame( 8, $entry['timed_count'] );
				// avg_ms = 1000 / 8 = 125.
				$this->assertEqualsWithDelta( 125.0, $entry['avg_ms'], 0.01 );
				// Percentiles should be computed from durations.
				$this->assertArrayHasKey( 'p50_ms', $entry );
				$this->assertArrayHasKey( 'p95_ms', $entry );
				$this->assertArrayHasKey( 'p99_ms', $entry );
				// min_ms should be from source.
				$this->assertEqualsWithDelta( 50.0, $entry['min_ms'], 0.01 );
				// Durations should be stripped from output.
				$this->assertArrayNotHasKey( 'durations', $entry );
				break;
			}
		}
		$this->assertTrue( $found, 'Expected URL entry in merged index' );
	}

	public function test_get_merged_url_index_merges_across_buckets(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		// Use two adjacent bucket keys within the retention window.
		$now      = \time();
		$min      = (int) \gmdate( 'i', $now );
		$rounded  = (int) \floor( $min / 5 ) * 5;
		$bucket1  = \gmdate( 'Y-m-d-H', $now ) . '-' . \str_pad( (string) $rounded, 2, '0', STR_PAD_LEFT );

		$ts2     = $now - 300; // 5 minutes ago.
		$min2    = (int) \gmdate( 'i', $ts2 );
		$rounded2 = (int) \floor( $min2 / 5 ) * 5;
		$bucket2  = \gmdate( 'Y-m-d-H', $ts2 ) . '-' . \str_pad( (string) $rounded2, 2, '0', STR_PAD_LEFT );

		$data1 = [
			'hash_merge' => [
				'url'         => '/merge-test',
				'count'       => 5,
				'timed_count' => 5,
				'sum_ms'      => 500.0,
				'min_ms'      => 80.0,
				'max_ms'      => 120.0,
				'last_seen'   => $now,
				'durations'   => [ 80.0, 100.0, 120.0 ],
				'count_2xx'   => 5,
				'count_3xx'   => 0,
				'count_4xx'   => 0,
				'count_5xx'   => 0,
				'sum_peak_mb' => 50.0,
				'max_peak_mb' => 12.0,
			],
		];
		$data2 = [
			'hash_merge' => [
				'url'         => '/merge-test',
				'count'       => 3,
				'timed_count' => 3,
				'sum_ms'      => 450.0,
				'min_ms'      => 100.0,
				'max_ms'      => 200.0,
				'last_seen'   => $ts2,
				'durations'   => [ 100.0, 150.0, 200.0 ],
				'count_2xx'   => 2,
				'count_3xx'   => 1,
				'count_4xx'   => 0,
				'count_5xx'   => 0,
				'sum_peak_mb' => 30.0,
				'max_peak_mb' => 15.0,
			],
		];

		StatsStore::set_url_index_hourly( 0, $bucket1, $data1 );
		StatsStore::set_url_index_hourly( 0, $bucket2, $data2 );

		$result = StatsStore::get_merged_url_index();
		$found  = false;
		foreach ( $result as $entry ) {
			if ( '/merge-test' === $entry['url'] ) {
				$found = true;
				$this->assertSame( 8, $entry['count'] ); // 5 + 3.
				$this->assertSame( 8, $entry['timed_count'] ); // 5 + 3.
				$this->assertEqualsWithDelta( 950.0, $entry['sum_ms'], 0.01 ); // 500 + 450.
				$this->assertEqualsWithDelta( 80.0, $entry['min_ms'], 0.01 ); // min(80, 100).
				$this->assertEqualsWithDelta( 200.0, $entry['max_ms'], 0.01 ); // max(120, 200).
				$this->assertSame( 7, $entry['count_2xx'] ); // 5 + 2.
				$this->assertSame( 1, $entry['count_3xx'] );
				$this->assertEqualsWithDelta( 15.0, $entry['max_peak_mb'], 0.01 ); // max(12, 15).
				break;
			}
		}
		$this->assertTrue( $found, 'Expected merged URL entry' );
	}

	// ── Memcached-dependent: get_merged_server_leaderboard with data ────

	public function test_get_merged_server_leaderboard_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$bucket = $this->current_bucket_key();
		$data   = [
			'count'        => 10,
			'sum_req_time' => 500.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 10,
					'sum_time'  => 500.0, // 50 avg
					'sum_count' => 30.0,  // 3 avg
					'entries'   => [
						'do_action' => [ 25.0, 15.0, 5 ], // sum_time, sum_count, samples
					],
				],
			],
		];
		StatsStore::set_server_leaderboard_bucket( 0, 'web1', $bucket, $data );

		$result = StatsStore::get_merged_server_leaderboard( 'web1' );
		$this->assertNotNull( $result );
		$this->assertSame( 10, $result['count'] );
		$this->assertArrayHasKey( 'wp_head', $result['categories'] );
		$this->assertArrayHasKey( 'do_action', $result['categories']['wp_head']['entries'] );
		// Entry display: sum/samples = 25/5 = 5.
		$this->assertEqualsWithDelta( 5.0, $result['categories']['wp_head']['entries']['do_action'][0], 0.01 );
	}

	public function test_get_merged_server_leaderboard_multiple_partitions(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		// Init with 2 partitions.
		$ref = new \ReflectionProperty( StatsStore::class, 'num_partitions' );
		$ref->setAccessible( true );
		$ref->setValue( null, 2 );

		$bucket = $this->current_bucket_key();
		$data_p0 = [
			'count'        => 5,
			'sum_req_time' => 250.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 5,
					'sum_time'  => 200.0, // 40 avg * 5 requests
					'sum_count' => 10.0,  // 2 avg * 5 requests
					'entries'   => [],
				],
			],
		];
		$data_p1 = [
			'count'        => 8,
			'sum_req_time' => 400.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 8,
					'sum_time'  => 480.0, // 60 avg * 8 requests
					'sum_count' => 32.0,  // 4 avg * 8 requests
					'entries'   => [],
				],
			],
		];

		StatsStore::set_server_leaderboard_bucket( 0, 'web2', $bucket, $data_p0 );
		StatsStore::set_server_leaderboard_bucket( 1, 'web2', $bucket, $data_p1 );

		$result = StatsStore::get_merged_server_leaderboard( 'web2' );
		$this->assertNotNull( $result );
		// Count sums: 5 + 8 = 13.
		$this->assertSame( 13, $result['count'] );
		// Samples merged: 5 + 8 = 13.
		$this->assertSame( 13, $result['categories']['wp_head']['samples'] );
		// time = sum_time / count = (200 + 480) / 13 = 680/13 ~ 52.31.
		$this->assertEqualsWithDelta( 52.31, $result['categories']['wp_head']['time'], 0.1 );
	}

	// ── Memcached-dependent: get_merged_leaderboard multiple partitions ──

	public function test_get_merged_leaderboard_multiple_partitions(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$ref = new \ReflectionProperty( StatsStore::class, 'num_partitions' );
		$ref->setAccessible( true );
		$ref->setValue( null, 2 );

		$bucket  = $this->current_bucket_key();
		$data_p0 = [
			'count'        => 10,
			'sum_req_time' => 500.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 10,
					'sum_time'  => 500.0, // 50 avg * 10
					'sum_count' => 30.0,  // 3 avg * 10
					'entries'   => [
						// [sum_time, sum_count, samples] — 5 avg time, 2 avg count, 10 samples.
						'do_action' => [ 50.0, 20.0, 10 ],
					],
				],
			],
		];
		$data_p1 = [
			'count'        => 15,
			'sum_req_time' => 750.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 15,
					'sum_time'  => 1050.0, // 70 avg * 15
					'sum_count' => 75.0,   // 5 avg * 15
					'entries'   => [
						// 8 avg time * 15 samples = 120, 3 avg count * 15 = 45.
						'do_action'  => [ 120.0, 45.0, 15 ],
						// 10 avg time * 15 samples = 150, 1 avg count * 15 = 15.
						'wp_enqueue' => [ 150.0, 15.0, 15 ],
					],
				],
				'the_content' => [
					'samples'   => 15,
					'sum_time'  => 450.0, // 30 avg * 15
					'sum_count' => 15.0,  // 1 avg * 15
					'entries'   => [],
				],
			],
		];

		StatsStore::set_leaderboard_bucket( 0, $bucket, $data_p0 );
		StatsStore::set_leaderboard_bucket( 1, $bucket, $data_p1 );

		$result = StatsStore::get_merged_leaderboard();
		$this->assertNotNull( $result );
		// Count sums: 10 + 15 = 25.
		$this->assertSame( 25, $result['count'] );
		// wp_head merged samples = 10 + 15 = 25.
		$this->assertArrayHasKey( 'wp_head', $result['categories'] );
		$this->assertSame( 25, $result['categories']['wp_head']['samples'] );
		// time = (sum_time_p0 + sum_time_p1) / count = (500 + 1050) / 25 = 62.
		$this->assertEqualsWithDelta( 62.0, $result['categories']['wp_head']['time'], 0.01 );
		// the_content from p1 only.
		$this->assertArrayHasKey( 'the_content', $result['categories'] );
		$this->assertSame( 15, $result['categories']['the_content']['samples'] );
		// Entries should be merged.
		$this->assertArrayHasKey( 'do_action', $result['categories']['wp_head']['entries'] );
		$this->assertArrayHasKey( 'wp_enqueue', $result['categories']['wp_head']['entries'] );
		// do_action display: (sum_time_p0 + sum_time_p1) / total_samples = (50 + 120) / (10 + 15) = 170/25 = 6.8.
		$this->assertEqualsWithDelta( 6.8, $result['categories']['wp_head']['entries']['do_action'][0], 0.01 );
	}

	// ── Memcached-dependent: get_url_time_series with data ──────────────

	public function test_get_url_time_series_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		// Generate current bucket key.
		$now     = \time();
		$min     = (int) \gmdate( 'i', $now );
		$rounded = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', STR_PAD_LEFT );
		$bucket  = \gmdate( 'Y-m-d-H', $now ) . '-' . $rounded;

		$hash = 'ts_test_hash';
		$data = [
			$hash => [
				'url'         => '/ts-test',
				'count'       => 5,
				'sum_ms'      => 500.0,
				'count_2xx'   => 4,
				'count_3xx'   => 0,
				'count_4xx'   => 1,
				'count_5xx'   => 0,
				'sum_peak_mb' => 50.0,
			],
		];
		StatsStore::set_url_index_hourly( 0, $bucket, $data );

		$result = StatsStore::get_url_time_series( $hash );
		$this->assertArrayHasKey( $bucket, $result );
		$this->assertSame( 5, $result[ $bucket ]['count'] );
		$this->assertEqualsWithDelta( 500.0, $result[ $bucket ]['sum_ms'], 0.01 );
		$this->assertSame( 4, $result[ $bucket ]['count_2xx'] );
		$this->assertSame( 1, $result[ $bucket ]['count_4xx'] );
	}

	// ── Memcached-dependent: global category time series ────────────────

	public function test_set_get_categories_roundtrip(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$data = [
			'2024-01-15-10-05' => [
				'wp_head'     => [ 't' => 50.0, 'c' => 3, 'n' => 1 ],
				'the_content' => [ 't' => 80.0, 'c' => 1, 'n' => 1 ],
			],
		];

		$this->assertTrue( StatsStore::set_categories( 0, $data ) );
		$result = StatsStore::get_categories( 0 );
		$this->assertSame( $data, $result );
	}

	public function test_get_categories_returns_null_without_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_categories( 0 );
		$this->assertNull( $result );
	}

	public function test_get_merged_categories_empty(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_merged_categories();
		$this->assertSame( [], $result );
	}

	public function test_get_merged_categories_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$ref = new \ReflectionProperty( StatsStore::class, 'num_partitions' );
		$ref->setAccessible( true );
		$ref->setValue( null, 2 );

		$data_p0 = [
			'2024-01-15-10-05' => [
				'wp_head' => [ 't' => 50.0, 'c' => 3, 'n' => 1 ],
			],
		];
		$data_p1 = [
			'2024-01-15-10-05' => [
				'wp_head'     => [ 't' => 70.0, 'c' => 5, 'n' => 2 ],
				'the_content' => [ 't' => 30.0, 'c' => 1, 'n' => 1 ],
			],
		];

		StatsStore::set_categories( 0, $data_p0 );
		StatsStore::set_categories( 1, $data_p1 );

		$merged = StatsStore::get_merged_categories();
		$this->assertArrayHasKey( '2024-01-15-10-05', $merged );
		$bucket = $merged['2024-01-15-10-05'];
		// wp_head: t=50+70=120, c=3+5=8, n=1+2=3.
		$this->assertEqualsWithDelta( 120.0, $bucket['wp_head']['t'], 0.01 );
		$this->assertSame( 8, $bucket['wp_head']['c'] );
		$this->assertSame( 3, $bucket['wp_head']['n'] );
		// the_content: from p1 only.
		$this->assertEqualsWithDelta( 30.0, $bucket['the_content']['t'], 0.01 );
		$this->assertSame( 1, $bucket['the_content']['n'] );
	}

	// ── Memcached-dependent: per-server category time series ────────────

	public function test_set_get_server_categories_roundtrip(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$data = [
			'2024-01-15-10-05' => [
				'wp_head' => [ 't' => 40.0, 'c' => 2, 'n' => 1 ],
			],
		];

		$this->assertTrue( StatsStore::set_server_categories( 0, 'web1', $data ) );
		$result = StatsStore::get_server_categories( 0, 'web1' );
		$this->assertSame( $data, $result );
	}

	public function test_get_server_categories_returns_null_without_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_server_categories( 0, 'nonexistent' );
		$this->assertNull( $result );
	}

	public function test_get_merged_server_categories_empty(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_merged_server_categories( 'web1' );
		$this->assertSame( [], $result );
	}

	public function test_get_merged_server_categories_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$ref = new \ReflectionProperty( StatsStore::class, 'num_partitions' );
		$ref->setAccessible( true );
		$ref->setValue( null, 2 );

		$data_p0 = [
			'2024-01-15-10-05' => [
				'wp_head' => [ 't' => 40.0, 'c' => 2, 'n' => 1 ],
			],
		];
		$data_p1 = [
			'2024-01-15-10-05' => [
				'wp_head' => [ 't' => 60.0, 'c' => 4, 'n' => 2 ],
			],
			'2024-01-15-10-10' => [
				'query' => [ 't' => 10.0, 'c' => 5, 'n' => 3 ],
			],
		];

		StatsStore::set_server_categories( 0, 'web1', $data_p0 );
		StatsStore::set_server_categories( 1, 'web1', $data_p1 );

		$merged = StatsStore::get_merged_server_categories( 'web1' );
		// wp_head in bucket 10-05: t=40+60=100, c=2+4=6, n=1+2=3.
		$this->assertEqualsWithDelta( 100.0, $merged['2024-01-15-10-05']['wp_head']['t'], 0.01 );
		$this->assertSame( 6, $merged['2024-01-15-10-05']['wp_head']['c'] );
		// query in bucket 10-10: from p1 only.
		$this->assertArrayHasKey( '2024-01-15-10-10', $merged );
		$this->assertSame( 3, $merged['2024-01-15-10-10']['query']['n'] );
		// Should be sorted by bucket key.
		$keys = \array_keys( $merged );
		$this->assertSame( $keys, [ '2024-01-15-10-05', '2024-01-15-10-10' ] );
	}

	// ── Memcached-dependent: per-URL category time series ───────────────

	public function test_set_get_url_categories_roundtrip(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$hash = 'url_cat_test';
		$data = [
			'2024-01-15-10-05' => [
				'wp_head' => [ 't' => 30.0, 'c' => 1, 'n' => 1 ],
			],
		];

		$this->assertTrue( StatsStore::set_url_categories( 0, $hash, $data ) );
		$result = StatsStore::get_url_categories( 0, $hash );
		$this->assertSame( $data, $result );
	}

	public function test_get_url_categories_returns_null_without_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_url_categories( 0, 'nonexistent_' . \uniqid() );
		$this->assertNull( $result );
	}

	public function test_get_merged_url_categories_empty(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$result = StatsStore::get_merged_url_categories( 'nonexistent' );
		$this->assertSame( [], $result );
	}

	public function test_get_merged_url_categories_with_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$ref = new \ReflectionProperty( StatsStore::class, 'num_partitions' );
		$ref->setAccessible( true );
		$ref->setValue( null, 2 );

		$hash = 'url_cat_merge';
		$data_p0 = [
			'2024-01-15-10-05' => [
				'template' => [ 't' => 100.0, 'c' => 1, 'n' => 1 ],
			],
		];
		$data_p1 = [
			'2024-01-15-10-05' => [
				'template' => [ 't' => 80.0, 'c' => 1, 'n' => 2 ],
				'query'    => [ 't' => 20.0, 'c' => 3, 'n' => 2 ],
			],
		];

		StatsStore::set_url_categories( 0, $hash, $data_p0 );
		StatsStore::set_url_categories( 1, $hash, $data_p1 );

		$merged = StatsStore::get_merged_url_categories( $hash );
		$this->assertArrayHasKey( '2024-01-15-10-05', $merged );
		$bucket = $merged['2024-01-15-10-05'];
		// template: t=100+80=180, c=1+1=2, n=1+2=3.
		$this->assertEqualsWithDelta( 180.0, $bucket['template']['t'], 0.01 );
		$this->assertSame( 2, $bucket['template']['c'] );
		$this->assertSame( 3, $bucket['template']['n'] );
		// query: from p1 only.
		$this->assertSame( 2, $bucket['query']['n'] );
	}

	// ── Memcached-dependent: server leaderboard entry merging ───────────

	public function test_get_merged_server_leaderboard_merges_entries(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$ref = new \ReflectionProperty( StatsStore::class, 'num_partitions' );
		$ref->setAccessible( true );
		$ref->setValue( null, 2 );

		$bucket  = $this->current_bucket_key();
		$data_p0 = [
			'count'        => 5,
			'sum_req_time' => 250.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 5,
					'sum_time'  => 200.0, // 40 avg * 5
					'sum_count' => 10.0,  // 2 avg * 5
					'entries'   => [
						// 5 avg time * 5 samples = 25, 2 avg count * 5 = 10.
						'do_action' => [ 25.0, 10.0, 5 ],
					],
				],
			],
		];
		$data_p1 = [
			'count'        => 8,
			'sum_req_time' => 400.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 8,
					'sum_time'  => 480.0, // 60 avg * 8
					'sum_count' => 32.0,  // 4 avg * 8
					'entries'   => [
						// 8 avg * 8 samples = 64, 3 avg * 8 = 24.
						'do_action'  => [ 64.0, 24.0, 8 ],
						'wp_enqueue' => [ 80.0, 8.0, 8 ],
					],
				],
				'the_content' => [
					'samples'   => 8,
					'sum_time'  => 240.0, // 30 avg * 8
					'sum_count' => 8.0,   // 1 avg * 8
					'entries'   => [],
				],
			],
		];

		StatsStore::set_server_leaderboard_bucket( 0, 'web3', $bucket, $data_p0 );
		StatsStore::set_server_leaderboard_bucket( 1, 'web3', $bucket, $data_p1 );

		$result = StatsStore::get_merged_server_leaderboard( 'web3' );
		$this->assertNotNull( $result );
		$this->assertSame( 13, $result['count'] );
		// wp_head merged: samples=5+8=13.
		$this->assertSame( 13, $result['categories']['wp_head']['samples'] );
		// the_content from p1 only (new category path).
		$this->assertArrayHasKey( 'the_content', $result['categories'] );
		$this->assertSame( 8, $result['categories']['the_content']['samples'] );
		// do_action display: (sum_time_p0 + sum_time_p1) / total_samples = (25 + 64) / 13 = 89/13 ~ 6.85.
		$entries = $result['categories']['wp_head']['entries'];
		$this->assertArrayHasKey( 'do_action', $entries );
		$this->assertEqualsWithDelta( 6.85, $entries['do_action'][0], 0.1 );
		$this->assertSame( 13, $entries['do_action'][2] );
		// wp_enqueue from p1 only (new entry path).
		$this->assertArrayHasKey( 'wp_enqueue', $entries );
		$this->assertEqualsWithDelta( 10.0, $entries['wp_enqueue'][0], 0.01 );
	}

	// ── Memcached-dependent: URL index edge cases ──────────────────────

	public function test_get_merged_url_index_empty_durations(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$now     = \time();
		$min     = (int) \gmdate( 'i', $now );
		$rounded = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', STR_PAD_LEFT );
		$bucket  = \gmdate( 'Y-m-d-H', $now ) . '-' . $rounded;

		$data = [
			'hash_no_dur' => [
				'url'         => '/no-durations',
				'count'       => 3,
				'timed_count' => 0,
				'sum_ms'      => 0,
				'min_ms'      => PHP_INT_MAX,
				'max_ms'      => 0,
				'last_seen'   => $now,
				'durations'   => [],
				'count_2xx'   => 0,
				'count_3xx'   => 0,
				'count_4xx'   => 3,
				'count_5xx'   => 0,
				'sum_peak_mb' => 0,
				'max_peak_mb' => 0,
			],
		];
		StatsStore::set_url_index_hourly( 0, $bucket, $data );

		$result = StatsStore::get_merged_url_index();
		$found  = false;
		foreach ( $result as $entry ) {
			if ( '/no-durations' === $entry['url'] ) {
				$found = true;
				$this->assertSame( 3, $entry['count'] );
				// No durations — p50/p95/p99 should be 0.
				$this->assertEqualsWithDelta( 0, $entry['p50_ms'], 0.01 );
				$this->assertEqualsWithDelta( 0, $entry['p95_ms'], 0.01 );
				$this->assertEqualsWithDelta( 0, $entry['p99_ms'], 0.01 );
				break;
			}
		}
		$this->assertTrue( $found, 'Expected URL with empty durations' );
	}

	// ── flush_all rotates salt ─────────────────────────────────────────

	public function test_flush_all_rotates_salt(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		$prefix_prop = new \ReflectionProperty( StatsStore::class, 'prefix' );
		$prefix_prop->setAccessible( true );
		$old_prefix = $prefix_prop->getValue();

		$result = StatsStore::flush_all();
		$this->assertSame( 1, $result );

		$new_prefix = $prefix_prop->getValue();
		$this->assertNotSame( $old_prefix, $new_prefix, 'Prefix should change after flush_all' );
		$this->assertStringStartsWith( 'evlog:', $new_prefix );

		// Salt should be persisted in options.
		$salt = \get_option( StatsStore::SALT_OPTION );
		$this->assertNotEmpty( $salt );
		$this->assertSame( 'evlog:' . $salt, $new_prefix );
	}

	public function test_flush_all_orphans_old_data(): void {
		$this->require_memcached();
		$this->rotate_and_reinit();

		// Write data under current salt.
		$data = [ '2024-01-15-10-05' => [ 'count' => 5, 'sum_ms' => 500.0, 'sum_peak_mb' => 50.0 ] ];
		StatsStore::set_hourly( 0, $data );
		$this->assertNotNull( StatsStore::get_hourly( 0 ) );

		// Flush rotates salt — old data becomes unreachable.
		StatsStore::flush_all();
		StatsStore::init( 1, self::servers(), 86400 );

		$result = StatsStore::get_hourly( 0 );
		$this->assertNull( $result, 'Old data should be orphaned after flush_all' );
	}
}
