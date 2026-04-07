<?php
/**
 * Stats Store
 *
 * Memcache-based storage for performance stats.
 * Uses Memcached for connection management.
 *
 * @package Newspack_Performance_Workers
 */

namespace Newspack_Performance_Workers;

use Newspack_Event_Logger\Memcached;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stats storage using Memcached.
 *
 * Key naming scheme:
 * - evlog:p{n}:hourly                  -> hourly time series
 * - evlog:p{n}:leaderboard             -> global leaderboard
 * - evlog:p{n}:leaderboard:{server}    -> per-server leaderboard
 * - evlog:p{n}:urls                    -> URL index with stats
 * - evlog:p{n}:url:{hash}              -> per-URL flame + profiles
 */
class StatsStore {

	/**
	 * Key prefix base for all stats.
	 */
	const PREFIX_BASE = 'evlog';

	/**
	 * WP option name for the stats salt.
	 */
	const SALT_OPTION = 'event_logger_stats_salt';

	/**
	 * Computed prefix (base + salt). Set in init().
	 *
	 * @var string
	 */
	private static string $prefix = 'evlog';

	/**
	 * Bucket interval in seconds (5 minutes).
	 */
	private const BUCKET_INTERVAL = 300;

	/**
	 * Maximum number of partitions allowed.
	 */
	private const MAX_PARTITIONS = 16;

	/**
	 * Maximum unique URLs to accumulate in merged index.
	 */
	private const MAX_UNIQUE_URLS = 10000;

	/**
	 * Maximum duration samples per URL per bucket.
	 * Kept small to prevent memcache value overflow (~800 bytes per URL at 100 samples).
	 */
	const MAX_DURATIONS_PER_BUCKET = 100;

	/**
	 * Retention window in seconds. Drives TTLs and bucket count.
	 * Set via init(), defaults to 86400 (24h).
	 *
	 * @var int
	 */
	private static int $retention = 86400;

	/**
	 * Number of partitions.
	 *
	 * @var int
	 */
	private static int $num_partitions = 1;

	/**
	 * Get the retention window in seconds.
	 *
	 * @return int Retention in seconds.
	 */
	public static function get_retention(): int {
		return self::$retention;
	}

	/**
	 * Get the TTL for stats that must survive the full retention window.
	 *
	 * @return int TTL in seconds.
	 */
	public static function ttl(): int {
		return self::$retention;
	}

	/**
	 * Get the TTL for per-URL stats (1/24th of retention, minimum 1 hour).
	 *
	 * @return int TTL in seconds.
	 */
	public static function ttl_url_stats(): int {
		return \max( 3600, (int) ( self::$retention / 24 ) );
	}

	/**
	 * Initialize the stats store.
	 *
	 * @param int   $num_partitions   Number of partitions.
	 * @param array $memcache_servers Array of memcache servers (host:port strings). Default: ['127.0.0.1:11211'].
	 * @param int   $max_lifespan     Retention window in seconds. Default: 86400 (24h).
	 */
	public static function init( int $num_partitions, array $memcache_servers = Memcached::DEFAULT_SERVERS, int $max_lifespan = 86400 ): void {
		self::$num_partitions = \min( $num_partitions, self::MAX_PARTITIONS );
		self::$retention      = \max( 3600, $max_lifespan ); // Floor at 1 hour.
		Memcached::init( $memcache_servers );

		// Build prefix with salt — rotating the salt orphans all existing keys.
		$salt          = \get_option( self::SALT_OPTION, '' );
		self::$prefix  = self::PREFIX_BASE;
		if ( '' !== $salt ) {
			self::$prefix .= ':' . $salt;
		}
	}

	// -------------------------------------------------------------------------
	// Hourly Stats
	// -------------------------------------------------------------------------

	/**
	 * Get hourly stats for a partition.
	 *
	 * @param int $partition Partition number.
	 * @return array|null Hourly stats or null if not found.
	 */
	public static function get_hourly( int $partition ): ?array {
		$val = Memcached::get( self::key( "p{$partition}", 'hourly' ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set hourly stats for a partition.
	 *
	 * @param int   $partition Partition number.
	 * @param array $data      Hourly stats data.
	 * @return bool Success.
	 */
	public static function set_hourly( int $partition, array $data ): bool {
		return Memcached::set( self::key( "p{$partition}", 'hourly' ), $data, self::ttl() );
	}

	/**
	 * Get merged hourly stats from all partitions.
	 *
	 * @return array Merged hourly stats.
	 */
	public static function get_merged_hourly(): array {
		$merged = [];

		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			$hourly = self::get_hourly( $p );
			if ( ! $hourly ) {
				continue;
			}

			foreach ( $hourly as $hour => $stats ) {
				if ( ! isset( $merged[ $hour ] ) ) {
					$merged[ $hour ] = [
						'count'       => 0,
						'sum_ms'      => 0,
						'sum_peak_mb' => 0,
					];
				}
				$m = &$merged[ $hour ];
				$m['count']       += $stats['count'] ?? 0;
				$m['sum_ms']      += $stats['sum_ms'] ?? 0;
				$m['sum_peak_mb'] += $stats['sum_peak_mb'] ?? 0;
			}
		}

		\ksort( $merged );
		return $merged;
	}

	// -------------------------------------------------------------------------
	// Leaderboard
	// -------------------------------------------------------------------------

	/**
	 * Get leaderboard for a partition.
	 *
	 * @param int $partition Partition number.
	 * @return array|null Leaderboard data or null if not found.
	 */
	public static function get_leaderboard( int $partition ): ?array {
		$val = Memcached::get( self::key( "p{$partition}", 'leaderboard' ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set leaderboard for a partition.
	 *
	 * @param int   $partition Partition number.
	 * @param array $data      Leaderboard data.
	 * @return bool Success.
	 */
	public static function set_leaderboard( int $partition, array $data ): bool {
		return Memcached::set( self::key( "p{$partition}", 'leaderboard' ), $data, self::ttl() );
	}

	/**
	 * Get merged leaderboard from all partitions.
	 *
	 * @return array|null Merged leaderboard data or null if none.
	 */
	public static function get_merged_leaderboard(): ?array {
		$merged = null;

		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			$lb = self::get_leaderboard( $p );
			if ( ! $lb ) {
				continue;
			}

			if ( null === $merged ) {
				$merged = $lb;
				continue;
			}

			// Merge category data.
			foreach ( $lb['categories'] ?? [] as $cat => $data ) {
				if ( ! isset( $merged['categories'][ $cat ] ) ) {
					$merged['categories'][ $cat ] = $data;
					continue;
				}

				$m_cat = &$merged['categories'][ $cat ];

				// Merge samples (sum).
				$old_samples       = $m_cat['samples'] ?? 0;
				$new_samples       = $data['samples'] ?? 0;
				$total_samples     = $old_samples + $new_samples;
				$m_cat['samples']  = $total_samples;

				// Weighted average for time and count.
				if ( $total_samples > 0 ) {
					$m_cat['time']  = ( ( $m_cat['time'] ?? 0 ) * $old_samples + ( $data['time'] ?? 0 ) * $new_samples ) / $total_samples;
					$m_cat['count'] = ( ( $m_cat['count'] ?? 0 ) * $old_samples + ( $data['count'] ?? 0 ) * $new_samples ) / $total_samples;
				}

				// Merge entries.
				foreach ( $data['entries'] ?? [] as $name => $entry ) {
					if ( ! isset( $m_cat['entries'][ $name ] ) ) {
						$m_cat['entries'][ $name ] = $entry;
						continue;
					}

					$e_old       = $m_cat['entries'][ $name ];
					$e_old_s     = $e_old[2] ?? 0;
					$e_new_s     = $entry[2] ?? 0;
					$e_total_s   = $e_old_s + $e_new_s;

					if ( $e_total_s > 0 ) {
						$m_cat['entries'][ $name ] = [
							( ( $e_old[0] ?? 0 ) * $e_old_s + ( $entry[0] ?? 0 ) * $e_new_s ) / $e_total_s,
							( ( $e_old[1] ?? 0 ) * $e_old_s + ( $entry[1] ?? 0 ) * $e_new_s ) / $e_total_s,
							$e_total_s,
						];
					}
				}
			}

			// Sum total count.
			$merged['count'] = ( $merged['count'] ?? 0 ) + ( $lb['count'] ?? 0 );

			// Weighted average for total_time.
			$old_count = $merged['count'] - ( $lb['count'] ?? 0 );
			$new_count = $lb['count'] ?? 0;
			$total     = $old_count + $new_count;
			if ( $total > 0 ) {
				$merged['total_time'] = ( ( $merged['total_time'] ?? 0 ) * $old_count + ( $lb['total_time'] ?? 0 ) * $new_count ) / $total;
			}
		}

		return $merged;
	}

	// -------------------------------------------------------------------------
	// Per-Server Leaderboard
	// -------------------------------------------------------------------------

	/**
	 * Get per-server leaderboard for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $server   Server name.
	 * @return array|null Leaderboard data or null if not found.
	 */
	public static function get_server_leaderboard( int $partition, string $server ): ?array {
		$val = Memcached::get( self::key( "p{$partition}", 'leaderboard', $server ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set per-server leaderboard for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $server   Server name.
	 * @param array  $data     Leaderboard data.
	 * @return bool Success.
	 */
	public static function set_server_leaderboard( int $partition, string $server, array $data ): bool {
		return Memcached::set( self::key( "p{$partition}", 'leaderboard', $server ), $data, self::ttl() );
	}

	/**
	 * Get merged per-server leaderboard across all partitions.
	 *
	 * @param string $server Server name.
	 * @return array|null Merged leaderboard data or null if none.
	 */
	public static function get_merged_server_leaderboard( string $server ): ?array {
		$merged = null;

		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			$lb = self::get_server_leaderboard( $p, $server );
			if ( ! $lb ) {
				continue;
			}

			if ( null === $merged ) {
				$merged = $lb;
				continue;
			}

			// Merge category data (same logic as get_merged_leaderboard).
			foreach ( $lb['categories'] ?? [] as $cat => $data ) {
				if ( ! isset( $merged['categories'][ $cat ] ) ) {
					$merged['categories'][ $cat ] = $data;
					continue;
				}

				$m_cat = &$merged['categories'][ $cat ];

				$old_samples      = $m_cat['samples'] ?? 0;
				$new_samples      = $data['samples'] ?? 0;
				$total_samples    = $old_samples + $new_samples;
				$m_cat['samples'] = $total_samples;

				if ( $total_samples > 0 ) {
					$m_cat['time']  = ( ( $m_cat['time'] ?? 0 ) * $old_samples + ( $data['time'] ?? 0 ) * $new_samples ) / $total_samples;
					$m_cat['count'] = ( ( $m_cat['count'] ?? 0 ) * $old_samples + ( $data['count'] ?? 0 ) * $new_samples ) / $total_samples;
				}

				foreach ( $data['entries'] ?? [] as $name => $entry ) {
					if ( ! isset( $m_cat['entries'][ $name ] ) ) {
						$m_cat['entries'][ $name ] = $entry;
						continue;
					}

					$e_old     = $m_cat['entries'][ $name ];
					$e_old_s   = $e_old[2] ?? 0;
					$e_new_s   = $entry[2] ?? 0;
					$e_total_s = $e_old_s + $e_new_s;

					if ( $e_total_s > 0 ) {
						$m_cat['entries'][ $name ] = [
							( ( $e_old[0] ?? 0 ) * $e_old_s + ( $entry[0] ?? 0 ) * $e_new_s ) / $e_total_s,
							( ( $e_old[1] ?? 0 ) * $e_old_s + ( $entry[1] ?? 0 ) * $e_new_s ) / $e_total_s,
							$e_total_s,
						];
					}
				}
			}

			// Sum total count.
			$merged['count'] = ( $merged['count'] ?? 0 ) + ( $lb['count'] ?? 0 );

			// Weighted average for total_time.
			$old_count = $merged['count'] - ( $lb['count'] ?? 0 );
			$new_count = $lb['count'] ?? 0;
			$total     = $old_count + $new_count;
			if ( $total > 0 ) {
				$merged['total_time'] = ( ( $merged['total_time'] ?? 0 ) * $old_count + ( $lb['total_time'] ?? 0 ) * $new_count ) / $total;
			}
		}

		return $merged;
	}

	// -------------------------------------------------------------------------
	// URL Index (5-minute buckets)
	// -------------------------------------------------------------------------

	/**
	 * Get URL index for a partition and bucket.
	 *
	 * @param int    $partition  Partition number.
	 * @param string $bucket_key Bucket key (Y-m-d-H-i format, minutes rounded to 5-min boundary).
	 * @return array|null URL index or null if not found.
	 */
	public static function get_url_index_hourly( int $partition, string $bucket_key ): ?array {
		$val = Memcached::get( self::key( "p{$partition}", 'urls', $bucket_key ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set URL index for a partition and bucket.
	 *
	 * @param int    $partition  Partition number.
	 * @param string $bucket_key Bucket key (Y-m-d-H-i format, minutes rounded to 5-min boundary).
	 * @param array  $data       URL index data.
	 * @return bool Success.
	 */
	public static function set_url_index_hourly( int $partition, string $bucket_key, array $data ): bool {
		return Memcached::set( self::key( "p{$partition}", 'urls', $bucket_key ), $data, self::ttl() );
	}

	/**
	 * Get bucket keys for the retention window (5-minute intervals).
	 *
	 * @return array Array of bucket keys (Y-m-d-H-i format).
	 */
	private static function get_retention_buckets(): array {
		$buckets    = [];
		$now        = \time();
		$num_buckets = (int) \ceil( self::$retention / self::BUCKET_INTERVAL );
		for ( $i = 0; $i < $num_buckets; $i++ ) {
			$ts      = $now - ( $i * self::BUCKET_INTERVAL );
			$min     = (int) \gmdate( 'i', $ts );
			$rounded = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', STR_PAD_LEFT );
			$buckets[] = \gmdate( 'Y-m-d-H', $ts ) . '-' . $rounded;
		}
		return \array_unique( $buckets );
	}

	/**
	 * Get merged URL index from all partitions over the retention window.
	 *
	 * @return array Merged URL index, sorted by count descending.
	 */
	public static function get_merged_url_index(): array {
		$merged  = [];
		$buckets = self::get_retention_buckets();

		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			// Batch all bucket keys for this partition into a single multi-get.
			$keys = [];
			foreach ( $buckets as $bucket_key ) {
				$keys[] = self::key( "p{$p}", 'urls', $bucket_key );
			}
			$results = Memcached::get_multi( $keys );

			foreach ( $results as $cache_key => $index ) {
				if ( ! \is_array( $index ) ) {
					continue;
				}

				foreach ( $index as $hash => $entry ) {
					if ( ! isset( $merged[ $hash ] ) ) {
						// Skip new URLs when at capacity, but keep accumulating existing ones.
						if ( \count( $merged ) >= self::MAX_UNIQUE_URLS ) {
							continue;
						}
						$merged[ $hash ] = [
							'hash'        => $hash,
							'url'         => $entry['url'] ?? '',
							'count'       => 0,
							'timed_count' => 0,
							'sum_ms'      => 0,
							'min_ms'      => 0,
							'max_ms'      => 0,
							'last_seen'   => 0,
							'durations'   => [],
							'count_2xx'   => 0,
							'count_3xx'   => 0,
							'count_4xx'   => 0,
							'count_5xx'   => 0,
							'sum_peak_mb' => 0,
							'max_peak_mb' => 0,
						];
					}

					$m = &$merged[ $hash ];
					$m['count']       += $entry['count'] ?? 0;
					$m['timed_count'] += $entry['timed_count'] ?? $entry['count'] ?? 0;
					$m['sum_ms']      += $entry['sum_ms'] ?? 0;
					$m['min_ms']     = ( 0 === $m['min_ms'] ) ? ( $entry['min_ms'] ?? 0 ) : \min( $m['min_ms'], $entry['min_ms'] ?? $m['min_ms'] );
					$m['max_ms']     = \max( $m['max_ms'], $entry['max_ms'] ?? 0 );
					$m['last_seen']  = \max( $m['last_seen'], $entry['last_seen'] ?? 0 );
					$m['count_2xx'] += $entry['count_2xx'] ?? 0;
					$m['count_3xx'] += $entry['count_3xx'] ?? 0;
					$m['count_4xx'] += $entry['count_4xx'] ?? 0;
					$m['count_5xx'] += $entry['count_5xx'] ?? 0;
					$m['sum_peak_mb'] += $entry['sum_peak_mb'] ?? 0;
					$m['max_peak_mb']  = \max( $m['max_peak_mb'], $entry['max_peak_mb'] ?? 0 );

					if ( empty( $m['url'] ) && ! empty( $entry['url'] ) ) {
						$m['url'] = $entry['url'];
					}

					if ( ! empty( $entry['durations'] ) ) {
						$m['durations'] = \array_merge( $m['durations'], $entry['durations'] );
						$max_merged     = self::MAX_DURATIONS_PER_BUCKET * 3; // Allow headroom for cross-partition merge.
						if ( \count( $m['durations'] ) > $max_merged ) {
							\shuffle( $m['durations'] );
							$m['durations'] = \array_slice( $m['durations'], 0, $max_merged );
						}
					}
				}
			}
		}

		// Calculate percentiles and avg.
		foreach ( $merged as $hash => &$entry ) {
			$tc = $entry['timed_count'] ?? $entry['count'];
			$entry['avg_ms']      = $tc > 0 ? $entry['sum_ms'] / $tc : 0;
			$entry['avg_peak_mb'] = $tc > 0 ? $entry['sum_peak_mb'] / $tc : 0;

			if ( ! empty( $entry['durations'] ) ) {
				\sort( $entry['durations'] );
				$cnt            = \count( $entry['durations'] );
				$entry['p50_ms'] = $entry['durations'][ (int) ( $cnt * 0.50 ) ] ?? 0;
				$entry['p95_ms'] = $entry['durations'][ (int) ( $cnt * 0.95 ) ] ?? 0;
				$entry['p99_ms'] = $entry['durations'][ (int) ( $cnt * 0.99 ) ] ?? 0;
			} else {
				$entry['p50_ms'] = 0;
				$entry['p95_ms'] = 0;
				$entry['p99_ms'] = 0;
			}

			// Clean up durations for output.
			unset( $entry['durations'] );
		}
		unset( $entry );

		// Sort by count descending. Use spaceship operator to avoid integer overflow.
		\uasort( $merged, fn( $a, $b ) => ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 ) );

		return \array_values( $merged );
	}

	/**
	 * Get time series data for a specific URL over the retention window.
	 *
	 * @param string $url_hash URL hash.
	 * @return array Time series keyed by bucket (Y-m-d-H-i format) with count, sum_ms, and status counts.
	 */
	public static function get_url_time_series( string $url_hash ): array {
		$series  = [];
		$buckets = self::get_retention_buckets();

		// Batch all partition keys per bucket into a single multi-get per bucket.
		foreach ( $buckets as $bucket_key ) {
			$bucket_data = [
				'count'       => 0,
				'sum_ms'      => 0,
				'count_2xx'   => 0,
				'count_3xx'   => 0,
				'count_4xx'   => 0,
				'count_5xx'   => 0,
				'sum_peak_mb' => 0,
			];

			// Build keys for all partitions in this bucket.
			$keys    = [];
			for ( $p = 0; $p < self::$num_partitions; $p++ ) {
				$keys[] = self::key( "p{$p}", 'urls', $bucket_key );
			}

			// Single round-trip for all partitions.
			$results = Memcached::get_multi( $keys );

			foreach ( $results as $index ) {
				if ( \is_array( $index ) && isset( $index[ $url_hash ] ) ) {
					$entry                      = $index[ $url_hash ];
					$bucket_data['count']       += $entry['count'] ?? 0;
					$bucket_data['sum_ms']      += $entry['sum_ms'] ?? 0;
					$bucket_data['count_2xx']   += $entry['count_2xx'] ?? 0;
					$bucket_data['count_3xx']   += $entry['count_3xx'] ?? 0;
					$bucket_data['count_4xx']   += $entry['count_4xx'] ?? 0;
					$bucket_data['count_5xx']   += $entry['count_5xx'] ?? 0;
					$bucket_data['sum_peak_mb'] += $entry['sum_peak_mb'] ?? 0;
				}
			}

			$series[ $bucket_key ] = $bucket_data;
		}

		\ksort( $series );
		return $series;
	}

	// -------------------------------------------------------------------------
	// Per-URL Stats (flame + profiles)
	// -------------------------------------------------------------------------

	/**
	 * Get per-URL stats for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $url_hash  URL hash.
	 * @return array|null URL stats or null if not found.
	 */
	public static function get_url_stats( int $partition, string $url_hash ): ?array {
		$val = Memcached::get( self::key( "p{$partition}", 'url', $url_hash ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set per-URL stats for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $url_hash  URL hash.
	 * @param array  $data      URL stats (flame, profiles, etc).
	 * @return bool Success.
	 */
	public static function set_url_stats( int $partition, string $url_hash, array $data ): bool {
		return Memcached::set( self::key( "p{$partition}", 'url', $url_hash ), $data, self::ttl_url_stats() );
	}

	/**
	 * Get per-URL stats, searching all partitions.
	 *
	 * URLs hash to a specific partition, so only one should have data.
	 *
	 * @param string $url_hash URL hash.
	 * @return array|null URL stats or null if not found.
	 */
	public static function get_url_stats_any_partition( string $url_hash ): ?array {
		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			$stats = self::get_url_stats( $p, $url_hash );
			if ( $stats ) {
				return $stats;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Dimensional Stats
	// -------------------------------------------------------------------------

	/**
	 * Available breakdown dimensions.
	 */
	const DIMENSIONS = [ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ];

	/**
	 * Maximum dimension values per bucket (prevents unbounded growth).
	 */
	const MAX_DIM_VALUES = 20;

	/**
	 * Maximum dimension values per bucket for per-URL breakdowns.
	 */
	const MAX_URL_DIM_VALUES = 10;

	/**
	 * Maximum category values per bucket (prevents unbounded growth / memcache 1MB limit).
	 */
	const MAX_CAT_VALUES = 50;

	/**
	 * Get dimensional stats for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $dimension Dimension name (one of DIMENSIONS).
	 * @param string $server    Optional server name for per-server data.
	 * @return array|null Dimensional data keyed by bucket, or null if not found.
	 */
	public static function get_dimensional( int $partition, string $dimension, string $server = '' ): ?array {
		$key_parts = [ "p{$partition}", 'dim', $dimension ];
		if ( '' !== $server ) {
			$key_parts[] = $server;
		}
		$val = Memcached::get( self::key( ...$key_parts ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set dimensional stats for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $dimension Dimension name.
	 * @param array  $data      Dimensional data.
	 * @param string $server    Optional server name for per-server data.
	 * @return bool Success.
	 */
	public static function set_dimensional( int $partition, string $dimension, array $data, string $server = '' ): bool {
		$key_parts = [ "p{$partition}", 'dim', $dimension ];
		if ( '' !== $server ) {
			$key_parts[] = $server;
		}
		return Memcached::set( self::key( ...$key_parts ), $data, self::ttl() );
	}

	/**
	 * Get merged dimensional stats from all partitions.
	 *
	 * For each bucket, sums c and s per dimension value across partitions.
	 *
	 * @param string $dimension Dimension name.
	 * @param string $server    Optional server name for per-server data.
	 * @return array Merged dimensional data keyed by bucket.
	 */
	public static function get_merged_dimensional( string $dimension, string $server = '' ): array {
		$merged = [];

		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			$dim_data = self::get_dimensional( $p, $dimension, $server );
			if ( ! $dim_data ) {
				continue;
			}

			foreach ( $dim_data as $bucket => $values ) {
				if ( ! isset( $merged[ $bucket ] ) ) {
					$merged[ $bucket ] = [];
				}
				foreach ( $values as $val => $stats ) {
					if ( ! isset( $merged[ $bucket ][ $val ] ) ) {
						$merged[ $bucket ][ $val ] = [ 'c' => 0, 's' => 0, 'm' => 0 ];
					}
					$merged[ $bucket ][ $val ]['c'] += $stats['c'] ?? 0;
					$merged[ $bucket ][ $val ]['s'] += $stats['s'] ?? 0;
					$merged[ $bucket ][ $val ]['m'] += $stats['m'] ?? 0;
				}
			}
		}

		\ksort( $merged );
		return $merged;
	}

	// -------------------------------------------------------------------------
	// Per-URL Dimensional Stats
	// -------------------------------------------------------------------------

	/**
	 * Get per-URL dimensional stats for a partition.
	 *
	 * Single key stores all 7 dimensions for one URL.
	 * Structure: { dimension: { bucket_key: { value: { c, s } } } }
	 *
	 * @param int    $partition Partition number.
	 * @param string $url_hash  URL hash.
	 * @return array|null Per-URL dimensional data or null if not found.
	 */
	public static function get_url_dimensional( int $partition, string $url_hash ): ?array {
		$val = Memcached::get( self::key( "p{$partition}", 'url_dim', $url_hash ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set per-URL dimensional stats for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $url_hash  URL hash.
	 * @param array  $data      Per-URL dimensional data (all dimensions).
	 * @return bool Success.
	 */
	public static function set_url_dimensional( int $partition, string $url_hash, array $data ): bool {
		return Memcached::set( self::key( "p{$partition}", 'url_dim', $url_hash ), $data, self::ttl() );
	}

	/**
	 * Get merged per-URL dimensional stats from all partitions for a single dimension.
	 *
	 * Extracts one dimension from the combined per-URL key and merges across partitions.
	 *
	 * @param string $url_hash  URL hash.
	 * @param string $dimension Dimension name (one of DIMENSIONS).
	 * @return array Merged dimensional data keyed by bucket.
	 */
	public static function get_merged_url_dimensional( string $url_hash, string $dimension ): array {
		$merged = [];

		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			$all_dims = self::get_url_dimensional( $p, $url_hash );
			if ( ! $all_dims || ! isset( $all_dims[ $dimension ] ) ) {
				continue;
			}

			foreach ( $all_dims[ $dimension ] as $bucket => $values ) {
				if ( ! isset( $merged[ $bucket ] ) ) {
					$merged[ $bucket ] = [];
				}
				foreach ( $values as $val => $stats ) {
					if ( ! isset( $merged[ $bucket ][ $val ] ) ) {
						$merged[ $bucket ][ $val ] = [ 'c' => 0, 's' => 0, 'm' => 0 ];
					}
					$merged[ $bucket ][ $val ]['c'] += $stats['c'] ?? 0;
					$merged[ $bucket ][ $val ]['s'] += $stats['s'] ?? 0;
					$merged[ $bucket ][ $val ]['m'] += $stats['m'] ?? 0;
				}
			}
		}

		\ksort( $merged );
		return $merged;
	}

	// -------------------------------------------------------------------------
	// Category Time Series (profile breakdowns over time)
	// -------------------------------------------------------------------------

	/**
	 * Get global category time series for a partition.
	 *
	 * Structure: { bucket_key: { category: { t, c, n } } }
	 *
	 * @param int $partition Partition number.
	 * @return array|null Category data keyed by bucket, or null if not found.
	 */
	public static function get_categories( int $partition ): ?array {
		$val = Memcached::get( self::key( "p{$partition}", 'categories' ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set global category time series for a partition.
	 *
	 * @param int   $partition Partition number.
	 * @param array $data      Category data keyed by bucket.
	 * @return bool Success.
	 */
	public static function set_categories( int $partition, array $data ): bool {
		return Memcached::set( self::key( "p{$partition}", 'categories' ), $data, self::ttl() );
	}

	/**
	 * Get merged global category time series from all partitions.
	 *
	 * For each bucket, sums t, c, and n per category across partitions.
	 *
	 * @return array Merged category data keyed by bucket.
	 */
	public static function get_merged_categories(): array {
		$merged = [];

		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			$cat_data = self::get_categories( $p );
			if ( ! $cat_data ) {
				continue;
			}

			foreach ( $cat_data as $bucket => $categories ) {
				if ( ! isset( $merged[ $bucket ] ) ) {
					$merged[ $bucket ] = [];
				}
				foreach ( $categories as $cat => $stats ) {
					if ( ! isset( $merged[ $bucket ][ $cat ] ) ) {
						$merged[ $bucket ][ $cat ] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
					}
					$merged[ $bucket ][ $cat ]['t'] += $stats['t'] ?? 0;
					$merged[ $bucket ][ $cat ]['c'] += $stats['c'] ?? 0;
					$merged[ $bucket ][ $cat ]['n'] += $stats['n'] ?? 0;
				}
			}
		}

		\ksort( $merged );
		return $merged;
	}

	/**
	 * Get per-server category time series for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $server    Server name.
	 * @return array|null Category data keyed by bucket, or null.
	 */
	public static function get_server_categories( int $partition, string $server ): ?array {
		$val = Memcached::get( self::key( "p{$partition}", 'categories', $server ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set per-server category time series for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $server    Server name.
	 * @param array  $data      Category data keyed by bucket.
	 * @return bool Success.
	 */
	public static function set_server_categories( int $partition, string $server, array $data ): bool {
		return Memcached::set( self::key( "p{$partition}", 'categories', $server ), $data, self::ttl() );
	}

	/**
	 * Get merged per-server category time series from all partitions.
	 *
	 * @param string $server Server name.
	 * @return array Merged category data keyed by bucket.
	 */
	public static function get_merged_server_categories( string $server ): array {
		$merged = [];

		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			$cat_data = self::get_server_categories( $p, $server );
			if ( ! $cat_data ) {
				continue;
			}

			foreach ( $cat_data as $bucket => $categories ) {
				if ( ! isset( $merged[ $bucket ] ) ) {
					$merged[ $bucket ] = [];
				}
				foreach ( $categories as $cat => $stats ) {
					if ( ! isset( $merged[ $bucket ][ $cat ] ) ) {
						$merged[ $bucket ][ $cat ] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
					}
					$merged[ $bucket ][ $cat ]['t'] += $stats['t'] ?? 0;
					$merged[ $bucket ][ $cat ]['c'] += $stats['c'] ?? 0;
					$merged[ $bucket ][ $cat ]['n'] += $stats['n'] ?? 0;
				}
			}
		}

		\ksort( $merged );
		return $merged;
	}

	// -------------------------------------------------------------------------
	// Per-URL Category Time Series
	// -------------------------------------------------------------------------

	/**
	 * Get per-URL category time series for a partition.
	 *
	 * Structure: { bucket_key: { category: { t, c, n } } }
	 *
	 * @param int    $partition Partition number.
	 * @param string $url_hash  URL hash.
	 * @return array|null Per-URL category data keyed by bucket, or null if not found.
	 */
	public static function get_url_categories( int $partition, string $url_hash ): ?array {
		$val = Memcached::get( self::key( "p{$partition}", 'url_cat', $url_hash ) );
		return \is_array( $val ) ? $val : null;
	}

	/**
	 * Set per-URL category time series for a partition.
	 *
	 * @param int    $partition Partition number.
	 * @param string $url_hash  URL hash.
	 * @param array  $data      Per-URL category data keyed by bucket.
	 * @return bool Success.
	 */
	public static function set_url_categories( int $partition, string $url_hash, array $data ): bool {
		return Memcached::set( self::key( "p{$partition}", 'url_cat', $url_hash ), $data, self::ttl() );
	}

	/**
	 * Get merged per-URL category time series from all partitions.
	 *
	 * For each bucket, sums t, c, and n per category across partitions.
	 *
	 * @param string $url_hash URL hash.
	 * @return array Merged per-URL category data keyed by bucket.
	 */
	public static function get_merged_url_categories( string $url_hash ): array {
		$merged = [];

		for ( $p = 0; $p < self::$num_partitions; $p++ ) {
			$cat_data = self::get_url_categories( $p, $url_hash );
			if ( ! $cat_data ) {
				continue;
			}

			foreach ( $cat_data as $bucket => $categories ) {
				if ( ! isset( $merged[ $bucket ] ) ) {
					$merged[ $bucket ] = [];
				}
				foreach ( $categories as $cat => $stats ) {
					if ( ! isset( $merged[ $bucket ][ $cat ] ) ) {
						$merged[ $bucket ][ $cat ] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
					}
					$merged[ $bucket ][ $cat ]['t'] += $stats['t'] ?? 0;
					$merged[ $bucket ][ $cat ]['c'] += $stats['c'] ?? 0;
					$merged[ $bucket ][ $cat ]['n'] += $stats['n'] ?? 0;
				}
			}
		}

		\ksort( $merged );
		return $merged;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a cache key from parts.
	 *
	 * @param string ...$parts Key parts.
	 * @return string Cache key.
	 */
	private static function key( string ...$parts ): string {
		return self::$prefix . ':' . \implode( ':', $parts );
	}

	/**
	 * Flush all stats by rotating the cache salt.
	 *
	 * Generates a new random salt so all existing keys become orphaned.
	 * Old entries expire naturally via memcached TTL/eviction.
	 * This is instantaneous regardless of how many keys exist.
	 *
	 * @return int Always returns 1 (salt rotated).
	 */
	public static function flush_all(): int {
		$salt = \wp_generate_password( 8, false );
		\update_option( self::SALT_OPTION, $salt, true );
		self::$prefix = self::PREFIX_BASE . ':' . $salt;

		// Restart workers so they pick up the new salt. Without this,
		// workers keep writing to the old prefix indefinitely.
		\Newspack_Event_Logger\Cron\Supervisor::request_restart();

		return 1;
	}
}
