<?php
/**
 * Flame Builder Handler
 *
 * Handler for LogReader that builds flame_data from requests.log,
 * writes to flames.log, and accumulates per-URL aggregate stats.
 *
 * @package Newspack_Performance_Workers
 */

namespace Newspack_Performance_Workers\Cron;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\LruCache;
use Newspack_Event_Logger\Memcached;
use Newspack_Performance_Workers\StatsStore;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flame builder handler class.
 *
 * Implements the handler interface for LogReader:
 * - init()       - Initialize context (flames_log, accumulators)
 * - process()    - Process one line from requests.log
 * - flush()      - Flush accumulators to memcache
 * - save_state() - Return state for persistence
 * - cleanup()    - Clean up resources
 */
class FlameBuilder {

	const EMA_SAMPLE_LIMIT   = 1000;
	const FLUSH_INTERVAL_SEC = 5;

	/** Security limits for recursion and unbounded growth. */
	private const MAX_RECURSION_DEPTH = 50;
	private const MAX_STACK_DEPTH     = 50;

	/** Pre-compiled regex patterns for flame data parsing. */
	const PATTERN_START    = '/^(.+?) \(start\)$/';
	const PATTERN_COMPLETE = '/^(.+?) \(complete\)$/';

	/** Entry limits with hysteresis: only trim when upper limit hit, trim to lower limit. */
	const ENTRY_LIMIT_URL_UPPER    = 40;
	const ENTRY_LIMIT_URL_LOWER    = 20;
	const ENTRY_LIMIT_GLOBAL_UPPER = 100;
	const ENTRY_LIMIT_GLOBAL_LOWER = 50;

	/** Dimension field mapping: dim key => request field name. */
	const DIM_FIELDS = [
		'status'  => 'status_category',
		'method'  => 'request_method',
		'server'  => 'server_name',
		'country' => 'country_code',
		'from'    => 'http_from',
		'ua'      => 'user_agent',
		'ja4'     => 'ja4_hash',
	];

	/** Minutes per time-series bucket. */
	private const BUCKET_MINUTES = 5;

	/** LRU cache for per-URL stats accumulators. */
	private const STATS_CACHE_BUCKET_SIZE = 1000;
	private const STATS_CACHE_NUM_BUCKETS = 5;

	/**
	 * Initialize handler context.
	 *
	 * @param array      $context     Context array (modified by reference).
	 * @param array|null $saved_state Previously saved state (or null on first run).
	 */
	public static function init( array &$context, ?array $saved_state ): void {
		$context['stats_cache']                 = new LruCache( self::STATS_CACHE_BUCKET_SIZE, self::STATS_CACHE_NUM_BUCKETS );
		$context['global_leaderboard']          = null;
		$context['leaderboard_by_server']       = [];
		$context['url_stats']                   = [];
		$context['hourly_stats']                = [];
		$context['last_flush_time']             = \microtime( true );
		$context['auto_disable_threshold']      = 0;
		$context['auto_protect_time_threshold'] = 0.0;
		$context['hooks_to_disable']            = [];
		$context['custom_events_to_disable']    = [];
		$context['significant_events']          = [];
		$context['new_significant_events']      = [];
		$context['last_significant_refresh']    = 0.0;
		$context['custom_event_names']          = [];
		$context['dim_stats']                   = [];
		$context['dim_stats_by_server']         = [];
		$context['url_dim_stats']               = [];
		$context['cat_stats']                   = [];
		$context['cat_stats_by_server']         = [];
		$context['url_cat_stats']               = [];
		$context['is_hub']                      = false;

		// Pending stats for the current (incomplete) 5-minute bucket.
		// All bucketed data accumulates here first; promoted to the flush
		// arrays when the bucket key rotates (i.e., the bucket is complete).
		// Persisted via save_state/offsetlog across worker restarts.
		$context['pending_bucket']              = '';
		$context['pending'] = [
			'hourly'        => [],
			'dim'           => [],
			'dim_by_server' => [],
			'url_dim'       => [],
			'url_stats'     => [],
			'cat'           => [],
			'cat_by_server' => [],
			'cat_by_url'    => [],
		];

		// Restore pending state from previous worker run.
		if ( \is_array( $saved_state ) && isset( $saved_state['pending_bucket'] ) ) {
			$context['pending_bucket'] = $saved_state['pending_bucket'];
			$context['pending']        = \array_merge( $context['pending'], $saved_state['pending'] ?? [] );
		}

		// Load config.
		$config    = Config::load_config( 'full' );
		$log_base  = Config::get_logs_directory();
		$partition = $context['partition'];

		// Initialize stats store with partition count, memcache servers, and retention window.
		$num_partitions   = $config['num_partitions'];
		$memcache_servers = $config['memcache_servers'];
		$max_lifespan     = (int) ( $config['max_lifespan'] ?? 86400 );
		StatsStore::init( $num_partitions, $memcache_servers, $max_lifespan );

		// Hub mode: track per-server dimensional data only when remote servers are configured.
		$context['is_hub'] = ! empty( $config['aggregator_servers'] );

		// Flames log for writing flame data.
		$context['flames_log'] = ( new Firehose( "{$log_base}/flames.log", $partition ) )
			->allow_large_writes()
			->with_index( [ self::class, 'format_index_entry' ] );

		// Update state with config values.
		$context['auto_disable_threshold']      = (int)   ( $config['auto_disable_threshold']      ?? 0 );
		$context['auto_protect_time_threshold'] = (float) ( $config['auto_protect_time_threshold'] ?? 0 );

		// Load custom event names for matching.
		$custom_colors = Config::get_custom_colors();
		foreach ( \array_keys( $custom_colors ) as $event ) {
			$context['custom_event_names'][ $event ] = true;
		}

		// Load persisted significant events (protected from auto-disable).
		$significant = $config['significant_events'] ?? [];
		if ( \is_array( $significant ) ) {
			foreach ( $significant as $event ) {
				$context['significant_events'][ $event ] = true;
			}
		}
	}

	/**
	 * Process a single line from requests.log.
	 *
	 * @param string $line      The line to process.
	 * @param string $input     Input log name (e.g., 'requests.log').
	 * @param array  $context   Context array (modified by reference).
	 */
	public static function process( string $line, string $input, array &$context ): void {
		$request = \json_decode( $line, true, 64 );
		if ( ! \is_array( $request ) ) {
			return; // Skip invalid JSON lines.
		}

		$rid      = $request['rid'] ?? '';
		$url_hash = RequestBuilder::url_hash( $request['url'] ?? '' );
		$entries  = $request['entries'] ?? [];
		if ( ! \is_array( $entries ) ) {
			$entries = [];
		}

		$flame_data          = self::build_flame_data( $entries, $context );
		$flame_data['value'] = (float) ( $request['duration_ms'] ?? 0 );

		$profiles = $request['profiles'] ?? [];
		if ( ! \is_array( $profiles ) ) {
			$profiles = [];
		}

		if ( self::store_flame( $rid, $url_hash, $flame_data, $context ) ) {
			self::accumulate_all_stats( $url_hash, $flame_data, $profiles, $request, $context );
		}

		// Periodic flush.
		$now_f = \microtime( true );
		if ( $now_f - $context['last_flush_time'] >= self::FLUSH_INTERVAL_SEC ) {
			self::flush( $context );
			$context['last_flush_time'] = $now_f;
		}
	}

	/**
	 * Flush accumulators to memcache.
	 *
	 * @param array $context Context array (modified by reference).
	 */
	public static function flush( array &$context ): void {
		$partition = $context['partition'];

		// Promote pending bucket into flush arrays on every flush cycle so
		// dashboards see data within 30s, not after the 5-minute bucket rotation.
		// The merge in persist_aggregate_stats is additive, so flushing partial
		// 30s chunks produces the same result as one batch at rotation time.
		if ( '' !== $context['pending_bucket'] ) {
			self::promote_pending_bucket( $context );
		}

		// Flush per-URL stats accumulators (combined flame + profiles) to memcache.
		$now = \time();
		foreach ( $context['stats_cache']->iterate() as $url_hash => $aggregate ) {
			// Create finalized flame for display (scale values, strip suffixes, normalize).
			// Keep raw flame_raw for future merging (unscaled, with seen_count).
			$total_count            = $aggregate['flame']['count'] ?? 0;
			$aggregate['flame_raw'] = $aggregate['flame'];
			self::finalize_flame_node( $aggregate['flame'], $total_count );
			$aggregate['last_modified'] = $now;
			StatsStore::set_url_stats( $partition, $url_hash, $aggregate );
		}

		// Flush combined hourly, leaderboard, and URL stats to memcache.
		self::persist_aggregate_stats( $context );

		// Apply auto-disable.
		self::apply_auto_tune( $context );

		// Refresh significant events.
		self::refresh_significant_events( $context, false );

		$context['stats_cache']->flush();
		$context['global_leaderboard']    = null;
		$context['leaderboard_by_server'] = [];
		$context['url_stats']             = [];
		$context['hourly_stats']          = [];
		$context['dim_stats']             = [];
		$context['dim_stats_by_server']   = [];
		$context['url_dim_stats']         = [];
		$context['cat_stats']             = [];
		$context['cat_stats_by_server']   = [];
		$context['url_cat_stats']         = [];
	}

	/**
	 * Save state for persistence.
	 *
	 * @param array $context Context array.
	 * @return array State to persist.
	 */
	public static function save_state( array &$context ): array {
		// Persist pending (incomplete) bucket data across worker restarts.
		return [
			'pending_bucket' => $context['pending_bucket'],
			'pending'        => $context['pending'],
		];
	}

	/**
	 * Clean up resources.
	 *
	 * @param array $context Context array.
	 */
	public static function cleanup( array &$context ): void {
		// Promote any pending bucket before final flush (even if incomplete —
		// better to have partial data than lose it on shutdown).
		if ( '' !== $context['pending_bucket'] ) {
			self::promote_pending_bucket( $context );
		}

		// Final flush.
		self::flush( $context );

		// Close flames log.
		$context['flames_log'] = null;
	}

	/**
	 * Store flame data to flames log.
	 *
	 * Index is written automatically via the with_index() callback.
	 *
	 * @param string $rid        Request ID.
	 * @param string $url_hash   URL hash.
	 * @param array  $flame_data Flame graph data.
	 * @param array  $context    Context array.
	 * @return bool True on success.
	 */
	private static function store_flame( string $rid, string $url_hash, array $flame_data, array &$context ): bool {
		// Strip duplicate sibling suffixes before storage (they're only needed for merging).
		self::strip_name_suffixes( $flame_data );

		// Add rid and url_hash to flame data so index callback can extract them.
		$flame_data['rid']      = $rid;
		$flame_data['url_hash'] = $url_hash;
		$json                   = \wp_json_encode( $flame_data );

		return false !== $context['flames_log']->write( $json, $flame_data );
	}

	/**
	 * Strip hidden sequence suffixes (\x00N) from flame node names recursively.
	 *
	 * @param array $node  Flame node (modified in place).
	 * @param int   $depth Current recursion depth.
	 */
	private static function strip_name_suffixes( array &$node, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}
		$name     = $node['name'] ?? '';
		$null_pos = \strpos( $name, "\x00" );
		if ( false !== $null_pos ) {
			$node['name'] = \substr( $name, 0, $null_pos );
		}
		if ( ! empty( $node['children'] ) ) {
			foreach ( $node['children'] as &$child ) {
				self::strip_name_suffixes( $child, $depth + 1 );
			}
			unset( $child );
		}
	}

	/**
	 * Format index entry callback for Firehose::with_index().
	 *
	 * @param string     $line     The JSON line written to the log.
	 * @param array      $position Position array with segment_id, offset, length.
	 * @param array|null $data     Pre-decoded data (avoids re-parsing $line).
	 * @return string|null Index entry or null to skip.
	 */
	public static function format_index_entry( string $line, array $position, ?array &$data = null ): ?string {
		$data = $data ?? \json_decode( $line, true, 64 );
		if ( ! \is_array( $data ) || empty( $data['rid'] ) ) {
			return null;
		}

		return \str_pad( \substr( $data['rid'], 0, 32 ), 32 )
			. \str_pad( \substr( $data['url_hash'], 0, 12 ), 12 )
			. \str_pad( (string) $position['segment_id'], 6, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['offset'], 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['length'], 8, '0', STR_PAD_LEFT );
	}

	/**
	 * Parse flame index entry.
	 *
	 * @param string $line Index line.
	 * @return array{rid: string, url_hash: string, segment_id: int, offset: int, length: int}|null
	 */
	public static function parse_flame_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		if ( \strlen( $line ) < 68 ) {
			return null;
		}
		return [
			'rid'        => \trim( \substr( $line, 0, 32 ) ),
			'url_hash'   => \trim( \substr( $line, 32, 12 ) ),
			'segment_id' => (int) \substr( $line, 44, 6 ),
			'offset'     => (int) \substr( $line, 50, 10 ),
			'length'     => (int) \substr( $line, 60, 8 ),
		];
	}

	/**
	 * Build flame graph data using stack-based LIFO matching.
	 *
	 * This handles improperly nested events (e.g., when a child span outlives
	 * its parent) by using LIFO matching like the log-manager does.
	 *
	 * @param array $entries Log entries.
	 * @param array $context Context array.
	 * @return array Flame graph data.
	 */
	private static function build_flame_data( array $entries, array &$context ): array {
		// Root node.
		$root = [
			'name'     => 'request',
			'value'    => 0,
			'children' => [],
		];

		// Stack of open nodes. Each entry: [ 'node' => &node, 'name' => base_name ].
		$stack   = [];
		$stack[] = [
			'node' => &$root,
			'name' => 'request',
		];

		foreach ( $entries as $entry ) {
			$keyword = $entry['k'] ?? '';

			if ( \preg_match( self::PATTERN_START, $keyword, $m ) ) {
				$base_name = $m[1];
				// 'l' is the stable label for aggregation/deduplication.
				// 'm' is the message with volatile details (SQL, paths) for display.
				$label  = \is_string( $entry['l'] ?? '' ) ? ( $entry['l'] ?? '' ) : '';
				$detail = \is_string( $entry['m'] ?? '' ) ? ( $entry['m'] ?? '' ) : '';
				$new_node = [
					'name'     => $label ? "{$base_name}: {$label}" : $base_name,
					'value'    => 0,
					'children' => [],
				];
				if ( $detail && $detail !== $label ) {
					$new_node['detail'] = "{$base_name}: {$detail}";
				}

				// Add as child of current top of stack.
				$top_idx                                 = \count( $stack ) - 1;
				$stack[ $top_idx ]['node']['children'][] = &$new_node;

				// Push onto stack (with depth limit to prevent DoS).
				if ( \count( $stack ) < self::MAX_STACK_DEPTH ) {
					$stack[] = [
						'node' => &$new_node,
						'name' => $base_name,
					];
				}
				unset( $new_node ); // Break reference for next iteration.

			} elseif ( \preg_match( self::PATTERN_COMPLETE, $keyword, $m ) ) {
				$base_name   = $m[1];
				$duration_ms = $entry['duration_ms'] ?? 0;

				// Search stack from top (LIFO) for matching name.
				$found_idx = -1;
				for ( $i = \count( $stack ) - 1; $i >= 1; $i-- ) { // Don't pop root.
					if ( $stack[ $i ]['name'] === $base_name ) {
						$found_idx = $i;
						break;
					}
				}

				if ( $found_idx >= 1 ) {
					// Set duration and timestamp on matched node.
					$stack[ $found_idx ]['node']['value'] = $duration_ms;
					$stack[ $found_idx ]['node']['ts']    = (int) ( $entry['ts'] ?? \time() );

					// Pop all nodes from found_idx to top.
					// Children that outlive their parent become orphaned (value=0).
					\array_splice( $stack, $found_idx );
				}
				// If not found, this is an orphaned complete - ignore it.
			}
		}

		// Number duplicate sibling names to prevent collapse during aggregation.
		self::number_duplicate_siblings( $root );

		return $root;
	}

	/**
	 * Recursively number duplicate sibling names with hidden suffix.
	 *
	 * Appends \x00{N} to duplicate names so they stay separate during merge,
	 * but the suffix is stripped before display.
	 *
	 * @param array $node  Flame node (modified by reference).
	 * @param int   $depth Current recursion depth.
	 */
	private static function number_duplicate_siblings( array &$node, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}
		if ( empty( $node['children'] ) ) {
			return;
		}

		// Count occurrences of each name among siblings.
		$name_counts = [];
		foreach ( $node['children'] as $child ) {
			$name                 = $child['name'] ?? 'unknown';
			$name_counts[ $name ] = ( $name_counts[ $name ] ?? 0 ) + 1;
		}

		// Add sequence numbers to duplicates.
		$name_seq = [];
		foreach ( $node['children'] as &$child ) {
			$name = $child['name'] ?? 'unknown';
			if ( $name_counts[ $name ] > 1 ) {
				$seq               = ( $name_seq[ $name ] ?? 0 ) + 1;
				$name_seq[ $name ] = $seq;
				$child['name']     = $name . "\x00" . $seq;
			}
			// Recurse into children.
			self::number_duplicate_siblings( $child, $depth + 1 );
		}
	}

	/**
	 * Accumulate all stats from a request: flame, profiles (per-URL + global), URL stats, hourly stats.
	 * Single loop over profiles with significance detection.
	 *
	 * @param string $url_hash   URL hash.
	 * @param array  $flame_data Flame graph data.
	 * @param array  $profiles   Profiles data from request.
	 * @param array  $request    Full request data.
	 * @param array  $context    Context array (modified by reference).
	 */
	private static function accumulate_all_stats( string $url_hash, array $flame_data, array $profiles, array $request, array &$context ): void {
		$duration_ms    = $flame_data['value'] ?? 0;
		$error_status   = $request['error_status'] ?? '-';
		$is_timed_out   = 'T' === $error_status;
		$is_worker      = ! empty( $request['is_worker'] );
		// Exclude from timing stats: timed-out (synthetic duration) and workers (skew averages).
		$has_timing     = $duration_ms > 0 && ! $is_timed_out && ! $is_worker;
		$now            = \time();
		$partition      = $context['partition'];

		// --- 1. Flame data (per-URL aggregate) with LRU caching ---
		$aggregate = $context['stats_cache']->get( $url_hash );
		if ( null === $aggregate ) {
			$aggregate = StatsStore::get_url_stats( $partition, $url_hash ) ?? [
				'flame'    => [
					'name'     => 'aggregate',
					'value'    => 0,
					'count'    => 0,
					'children' => [],
				],
				'profiles' => [
					'count'      => 0,
					'categories' => [],
				],
			];
			if ( isset( $aggregate['flame_raw'] ) ) {
				$aggregate['flame'] = $aggregate['flame_raw'];
				unset( $aggregate['flame_raw'] );
			}
		}
		$flame          = &$aggregate['flame'];
		$flame['count'] = \min( ( $flame['count'] ?? 0 ) + 1, self::EMA_SAMPLE_LIMIT );
		if ( $has_timing ) {
			$flame['value']    = ( $flame['value'] ?? 0 ) + ( $duration_ms - ( $flame['value'] ?? 0 ) ) / $flame['count'];
			$flame['children'] = self::merge_flame_children_incremental( $flame['children'] ?? [], $flame_data['children'] ?? [] );
		}

		// --- 2. Bucket key and rotation ---
		// All bucketed data accumulates in $context['pending'] for the current
		// 5-minute bucket. When the bucket key rotates, completed data is promoted
		// to the flush arrays (capped where needed) for memcache write.
		$timestamp  = $request['timestamp'] ?? \microtime( true );
		$bucket_key = self::bucket_key( (int) $timestamp );

		// Bucket rotation: promote completed bucket before starting a new one.
		if ( $bucket_key !== $context['pending_bucket'] ) {
			if ( '' !== $context['pending_bucket'] ) {
				self::promote_pending_bucket( $context );
			}
			$context['pending_bucket'] = $bucket_key;
		}

		// --- 2b. URL stats (pending bucket) ---
		$url = $request['url'] ?? '';
		if ( ! empty( $url ) ) {
			$pu = &$context['pending']['url_stats'];
			if ( ! isset( $pu[ $url_hash ] ) ) {
				$pu[ $url_hash ] = [
					'url'         => $url,
					'count'       => 0,
					'timed_count' => 0,
					'sum_ms'      => 0,
					'min_ms'      => PHP_INT_MAX,
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
			$ustats = &$pu[ $url_hash ];
			++$ustats['count'];
			if ( $has_timing ) {
				++$ustats['timed_count'];
				$ustats['sum_ms'] += $duration_ms;
				$ustats['max_ms']  = \max( $ustats['max_ms'], $duration_ms );
			}
			$ustats['last_seen'] = \max( $ustats['last_seen'], $timestamp );
			$status_category     = (int) \floor( ( $request['status_code'] ?? 0 ) / 100 );
			if ( $status_category >= 2 && $status_category <= 5 ) {
				++$ustats[ "count_{$status_category}xx" ];
			}
			if ( $has_timing ) {
				$max_dur = StatsStore::MAX_DURATIONS_PER_BUCKET;
				$ustats['min_ms'] = \min( $ustats['min_ms'], $duration_ms );
				if ( \count( $ustats['durations'] ) < $max_dur ) {
					$ustats['durations'][] = $duration_ms;
				} else {
					$idx = \wp_rand( 0, $ustats['timed_count'] - 1 );
					if ( $idx < $max_dur ) {
						$ustats['durations'][ $idx ] = $duration_ms;
					}
				}
			}
			$peak_mb = $request['peak_mb'] ?? 0;
			if ( $peak_mb > 0 ) {
				$ustats['sum_peak_mb'] += $peak_mb;
				$ustats['max_peak_mb']  = \max( $ustats['max_peak_mb'], $peak_mb );
			}
			unset( $ustats, $pu );
		}

		// --- 3. Time-series stats (pending bucket) ---
		$p = &$context['pending'];

		if ( empty( $p['hourly'] ) ) {
			$p['hourly'] = [ 'count' => 0, 'sum_ms' => 0, 'sum_peak_mb' => 0 ];
		}
		if ( $has_timing ) {
			++$p['hourly']['count'];
			$p['hourly']['sum_ms'] += $duration_ms;
		}
		$p['hourly']['sum_peak_mb'] += $request['peak_mb'] ?? 0;
		$status_cat = (int) \floor( ( $request['status_code'] ?? 0 ) / 100 );
		if ( $status_cat >= 2 && $status_cat <= 5 ) {
			$request['status_category'] = "{$status_cat}xx";
		}

		// --- 3b. Dimensional stats (global + per-server) ---
		// Intern dimension values — status codes, HTTP methods, country codes,
		// server names repeat across thousands of requests.
		static $intern      = [];
		static $intern_full = false;
		$server_name   = $request['server_name'] ?? '';
		$dim_peak_mb   = $request['peak_mb'] ?? 0;
		$dim_duration  = $has_timing ? $duration_ms : 0;
		foreach ( self::DIM_FIELDS as $dim => $field ) {
			$val = $request[ $field ] ?? '';
			if ( '' === $val ) {
				continue;
			}
			if ( ! $intern_full ) {
				$val = $intern[ $val ] ??= $val;
				if ( \count( $intern ) >= 50000 ) {
					$intern_full = true;
				}
			}
			// Global.
			if ( ! isset( $p['dim'][ $dim ][ $val ] ) ) {
				$p['dim'][ $dim ][ $val ] = [ 'c' => 0, 's' => 0, 'm' => 0 ];
			}
			++$p['dim'][ $dim ][ $val ]['c'];
			$p['dim'][ $dim ][ $val ]['s'] += $dim_duration;
			$p['dim'][ $dim ][ $val ]['m'] += $dim_peak_mb;

			// Per-server (hub mode only; skip 'server' dimension — redundant with the filter itself).
			if ( $context['is_hub'] && '' !== $server_name && 'server' !== $dim ) {
				if ( ! isset( $p['dim_by_server'][ $server_name ][ $dim ][ $val ] ) ) {
					$p['dim_by_server'][ $server_name ][ $dim ][ $val ] = [ 'c' => 0, 's' => 0, 'm' => 0 ];
				}
				++$p['dim_by_server'][ $server_name ][ $dim ][ $val ]['c'];
				$p['dim_by_server'][ $server_name ][ $dim ][ $val ]['s'] += $dim_duration;
				$p['dim_by_server'][ $server_name ][ $dim ][ $val ]['m'] += $dim_peak_mb;
			}

			// Per-URL dimensional stats.
			if ( ! isset( $p['url_dim'][ $url_hash ][ $dim ][ $val ] ) ) {
				$p['url_dim'][ $url_hash ][ $dim ][ $val ] = [ 'c' => 0, 's' => 0, 'm' => 0 ];
			}
			++$p['url_dim'][ $url_hash ][ $dim ][ $val ]['c'];
			$p['url_dim'][ $url_hash ][ $dim ][ $val ]['s'] += $dim_duration;
			$p['url_dim'][ $url_hash ][ $dim ][ $val ]['m'] += $dim_peak_mb;
		}
		unset( $p );

		// --- 4. Profile data (per-URL + global leaderboard) - SINGLE LOOP ---
		// Skip timed-out requests — their profiles are incomplete.
		if ( ! empty( $profiles ) && $has_timing ) {
			// Initialize global leaderboard buffer.
			if ( null === $context['global_leaderboard'] ) {
				$context['global_leaderboard'] = [
					'count'      => 0,
					'total_time' => 0,
					'categories' => [],
				];
			}

			$prof          = &$aggregate['profiles'];
			$lb            = &$context['global_leaderboard'];
			$prof['count'] = \min( ( $prof['count'] ?? 0 ) + 1, self::EMA_SAMPLE_LIMIT );
			$lb['count']   = \min( ( $lb['count'] ?? 0 ) + 1, self::EMA_SAMPLE_LIMIT );
			$prof_count    = $prof['count'];
			$lb_count      = $lb['count'];

			// Calculate total profiled time inline.
			$req_time = 0.0;

			// Threshold for noisy event detection.
			$count_threshold = $context['auto_disable_threshold'];

			$cp = &$context['pending'];

			// Initialize "total" category for this request (c accumulates in loop below).
			if ( ! isset( $cp['cat']['total'] ) ) {
				$cp['cat']['total'] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
			}
			$cp['cat']['total']['t'] += $duration_ms;
			$cp['cat']['total']['n']++;

			if ( $context['is_hub'] && '' !== $server_name ) {
				if ( ! isset( $cp['cat_by_server'][ $server_name ]['total'] ) ) {
					$cp['cat_by_server'][ $server_name ]['total'] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
				}
				$cp['cat_by_server'][ $server_name ]['total']['t'] += $duration_ms;
				$cp['cat_by_server'][ $server_name ]['total']['n']++;
			}

			if ( ! isset( $cp['cat_by_url'][ $url_hash ]['total'] ) ) {
				$cp['cat_by_url'][ $url_hash ]['total'] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
			}
			$cp['cat_by_url'][ $url_hash ]['total']['t'] += $duration_ms;
			$cp['cat_by_url'][ $url_hash ]['total']['n']++;

			// Single loop over all profile categories.
			// Intern category names — same hooks repeat across thousands of requests.
			foreach ( $profiles as $category => $data ) {
				if ( ! $intern_full ) {
					$category = $intern[ $category ] ??= $category;
					if ( \count( $intern ) >= 50000 ) {
						$intern_full = true;
					}
				}
				// Per-callback profiling categories (e.g. "the_content @10 do_blocks")
				// are breakdowns of their parent hook. Include them in profiles for
				// display, but exclude from $req_time, auto-disable, and significant
				// event detection.
				$is_callback = (bool) \preg_match( '/ @-?\d+$/', $category );
				$is_plugin   = (bool) \preg_match( '/ plugin$/', $category );

				if ( ! $is_callback ) {
					$req_time += $data['time'] ?? 0;
				}

				$cat_time  = (float) ( $data['time'] ?? 0 );
				$cat_count = (int) ( $data['count'] ?? 0 );
				$cat_ts    = (int) ( $data['ts'] ?? 0 );

				// --- Per-URL category ---
				if ( ! isset( $prof['categories'][ $category ] ) ) {
					$prof['categories'][ $category ] = [
						'time'    => 0,
						'count'   => 0,
						'samples' => 0,
						'ts'      => $cat_ts,
						'entries' => [],
					];
				}
				$pcat            = &$prof['categories'][ $category ];
				$pcat['samples'] = \min( ( $pcat['samples'] ?? 0 ) + 1, self::EMA_SAMPLE_LIMIT );
				$pcat['ts']      = \max( $pcat['ts'] ?? 0, $cat_ts );
				$pcat['time']    = $pcat['time'] + ( $cat_time - $pcat['time'] ) / $pcat['samples'];
				$pcat['count']   = $pcat['count'] + ( $cat_count - $pcat['count'] ) / $pcat['samples'];

				// --- Global leaderboard category ---
				if ( ! isset( $lb['categories'][ $category ] ) ) {
					$lb['categories'][ $category ] = [
						'time'    => 0,
						'count'   => 0,
						'samples' => 0,
						'entries' => [],
					];
				}
				$lcat            = &$lb['categories'][ $category ];
				$lcat['samples'] = \min( ( $lcat['samples'] ?? 0 ) + 1, self::EMA_SAMPLE_LIMIT );
				$lcat['time']    = $lcat['time'] + ( $cat_time - $lcat['time'] ) / $lcat['samples'];
				$lcat['count']   = $lcat['count'] + ( $cat_count - $lcat['count'] ) / $lcat['samples'];

				// --- Per-server leaderboard category (hub mode only) ---
				if ( $context['is_hub'] && '' !== $server_name ) {
					if ( ! isset( $context['leaderboard_by_server'][ $server_name ] ) ) {
						$context['leaderboard_by_server'][ $server_name ] = [
							'count'      => 0,
							'total_time' => 0,
							'categories' => [],
						];
					}
					$slb = &$context['leaderboard_by_server'][ $server_name ];

					if ( ! isset( $slb['categories'][ $category ] ) ) {
						$slb['categories'][ $category ] = [
							'time'    => 0,
							'count'   => 0,
							'samples' => 0,
							'entries' => [],
						];
					}
					$scat            = &$slb['categories'][ $category ];
					$scat['samples'] = \min( ( $scat['samples'] ?? 0 ) + 1, self::EMA_SAMPLE_LIMIT );
					$scat['time']    = $scat['time'] + ( $cat_time - $scat['time'] ) / $scat['samples'];
					$scat['count']   = $scat['count'] + ( $cat_count - $scat['count'] ) / $scat['samples'];

					// Per-server entries.
					if ( ! empty( $data['entries'] ) ) {
						foreach ( $data['entries'] as $s_name => $s_entry_data ) {
							$s_name  = $intern[ $s_name ] ??= $s_name;
							$s_time  = $s_entry_data[0] ?? 0;
							$s_count = $s_entry_data[1] ?? 0;
							if ( ! isset( $scat['entries'][ $s_name ] ) ) {
								$scat['entries'][ $s_name ] = [ $s_time, $s_count, 1 ];
							} else {
								$ses                            = ( $scat['entries'][ $s_name ][2] ?? 1 ) + 1;
								$scat['entries'][ $s_name ][2]  = \min( $ses, self::EMA_SAMPLE_LIMIT );
								$scat['entries'][ $s_name ][0] += ( $s_time - $scat['entries'][ $s_name ][0] ) / $scat['entries'][ $s_name ][2];
								$scat['entries'][ $s_name ][1] += ( $s_count - $scat['entries'][ $s_name ][1] ) / $scat['entries'][ $s_name ][2];
							}
						}
						if ( \count( $scat['entries'] ) > self::ENTRY_LIMIT_GLOBAL_UPPER ) {
							\uasort( $scat['entries'], fn( $a, $b ) => $b[0] <=> $a[0] );
							$scat['entries'] = \array_slice( $scat['entries'], 0, self::ENTRY_LIMIT_GLOBAL_LOWER, true );
						}
					}
					unset( $scat );
					unset( $slb );
				}

				// --- Category time series (pending bucket) ---
				if ( ! isset( $cp['cat'][ $category ] ) ) {
					$cp['cat'][ $category ] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
				}
				$cp['cat'][ $category ]['t'] += $cat_time;
				$cp['cat'][ $category ]['c'] += $cat_count;
				$cp['cat'][ $category ]['n']++;
				$cp['cat']['total']['c'] += $cat_count;

				if ( $context['is_hub'] && '' !== $server_name ) {
					if ( ! isset( $cp['cat_by_server'][ $server_name ][ $category ] ) ) {
						$cp['cat_by_server'][ $server_name ][ $category ] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
					}
					$cp['cat_by_server'][ $server_name ][ $category ]['t'] += $cat_time;
					$cp['cat_by_server'][ $server_name ][ $category ]['c'] += $cat_count;
					$cp['cat_by_server'][ $server_name ][ $category ]['n']++;
					$cp['cat_by_server'][ $server_name ]['total']['c'] += $cat_count;
				}

				if ( ! isset( $cp['cat_by_url'][ $url_hash ][ $category ] ) ) {
					$cp['cat_by_url'][ $url_hash ][ $category ] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
				}
				$cp['cat_by_url'][ $url_hash ][ $category ]['t'] += $cat_time;
				$cp['cat_by_url'][ $url_hash ][ $category ]['c'] += $cat_count;
				$cp['cat_by_url'][ $url_hash ][ $category ]['n']++;
				$cp['cat_by_url'][ $url_hash ]['total']['c'] += $cat_count;

				// --- Significant event detection (average time per call exceeds threshold) ---
				// Skip for callback categories — they're breakdowns, not independent events.
				$time_threshold = $context['auto_protect_time_threshold'];
				if ( ! $is_callback && ! $is_plugin && $time_threshold > 0 && $lcat['count'] > 0 ) {
					$avg_per_call = $lcat['time'] / $lcat['count'];
					if ( $avg_per_call >= $time_threshold ) {
						$base_name = \explode( ' ', $category, 2 )[0];
						if ( ! isset( $context['significant_events'][ $base_name ] ) ) {
							$context['significant_events'][ $base_name ]     = true;
							$context['new_significant_events'][ $base_name ] = true;
						}
					}
				}

				// --- Entry loop (both per-URL and global, plus max tracking) ---
				if ( ! empty( $data['entries'] ) ) {
					foreach ( $data['entries'] as $name => $entry_data ) {
						$name        = $intern[ $name ] ??= $name;
						$entry_time  = $entry_data[0] ?? 0;
						$entry_count = $entry_data[1] ?? 0;

						// Per-URL entries (top 20).
						if ( ! isset( $pcat['entries'][ $name ] ) ) {
							$pcat['entries'][ $name ] = [ $entry_time, $entry_count, 1 ];
						} else {
							$es                           = ( $pcat['entries'][ $name ][2] ?? 1 ) + 1;
							$pcat['entries'][ $name ][2]  = \min( $es, self::EMA_SAMPLE_LIMIT );
							$pcat['entries'][ $name ][0] += ( $entry_time - $pcat['entries'][ $name ][0] ) / $pcat['entries'][ $name ][2];
							$pcat['entries'][ $name ][1] += ( $entry_count - $pcat['entries'][ $name ][1] ) / $pcat['entries'][ $name ][2];
						}

						// Global entries (top 50).
						if ( ! isset( $lcat['entries'][ $name ] ) ) {
							$lcat['entries'][ $name ] = [ $entry_time, $entry_count, 1 ];
						} else {
							$es                           = ( $lcat['entries'][ $name ][2] ?? 1 ) + 1;
							$lcat['entries'][ $name ][2]  = \min( $es, self::EMA_SAMPLE_LIMIT );
							$lcat['entries'][ $name ][0] += ( $entry_time - $lcat['entries'][ $name ][0] ) / $lcat['entries'][ $name ][2];
							$lcat['entries'][ $name ][1] += ( $entry_count - $lcat['entries'][ $name ][1] ) / $lcat['entries'][ $name ][2];
						}
					}

					// Trim entries with hysteresis: only sort when upper limit hit, trim to lower.
					if ( \count( $pcat['entries'] ) > self::ENTRY_LIMIT_URL_UPPER ) {
						\uasort( $pcat['entries'], fn( $a, $b ) => $b[0] <=> $a[0] );
						$pcat['entries'] = \array_slice( $pcat['entries'], 0, self::ENTRY_LIMIT_URL_LOWER, true );
					}
					if ( \count( $lcat['entries'] ) > self::ENTRY_LIMIT_GLOBAL_UPPER ) {
						\uasort( $lcat['entries'], fn( $a, $b ) => $b[0] <=> $a[0] );
						$lcat['entries'] = \array_slice( $lcat['entries'], 0, self::ENTRY_LIMIT_GLOBAL_LOWER, true );
					}
				}

				// --- Noisy detection (apply_auto_tune filters out significant) ---
				if ( ! $is_callback && ! $is_plugin && $count_threshold > 0 && $cat_count > $count_threshold ) {
					$base_name = \explode( ' ', $category, 2 )[0];
					if ( isset( $context['custom_event_names'][ $base_name ] ) ) {
						$context['custom_events_to_disable'][ $base_name ] = true;
					} else {
						$context['hooks_to_disable'][ $base_name ] = true;
					}
				}
			}
			$prof['total_time'] = ( $prof['total_time'] ?? 0 ) + ( $req_time - ( $prof['total_time'] ?? 0 ) ) / $prof_count;
			$lb['total_time']   = ( $lb['total_time'] ?? 0 ) + ( $req_time - ( $lb['total_time'] ?? 0 ) ) / $lb_count;

			// Per-server total_time + count (hub mode only).
			if ( $context['is_hub'] && '' !== $server_name && isset( $context['leaderboard_by_server'][ $server_name ] ) ) {
				$slb = &$context['leaderboard_by_server'][ $server_name ];
				$slb['count']      = \min( ( $slb['count'] ?? 0 ) + 1, self::EMA_SAMPLE_LIMIT );
				$slb['total_time'] = $slb['total_time'] + ( $req_time - $slb['total_time'] ) / $slb['count'];
				unset( $slb );
			}

			// Expire old per-URL categories.
			$cutoff = $now - 3600;
			foreach ( $prof['categories'] as $cat => $cd ) {
				if ( ( $cd['ts'] ?? 0 ) < $cutoff ) {
					unset( $prof['categories'][ $cat ] );
				}
			}
			unset( $cp );
		}

		// Store updated aggregate in LRU cache.
		$context['stats_cache']->set( $url_hash, $aggregate );
	}

	/**
	 * Persist combined aggregate stats (hourly, leaderboard, urls) to memcache.
	 *
	 * Merges with existing stats, computes percentiles for URLs, expires old hourly data.
	 *
	 * @param array $context Context array (modified by reference).
	 */
	private static function persist_aggregate_stats( array &$context ): void {
		if ( empty( $context['hourly_stats'] ) && null === $context['global_leaderboard'] && empty( $context['leaderboard_by_server'] ) && empty( $context['url_stats'] ) && empty( $context['dim_stats'] ) && empty( $context['dim_stats_by_server'] ) && empty( $context['url_dim_stats'] ) && empty( $context['cat_stats'] ) && empty( $context['cat_stats_by_server'] ) && empty( $context['url_cat_stats'] ) ) {
			return;
		}

		$p = $context['partition'];

		// --- Hourly stats ---
		if ( ! empty( $context['hourly_stats'] ) ) {
			$existing_hourly = StatsStore::get_hourly( $p ) ?? [];

			// Merge hourly stats.
			foreach ( $context['hourly_stats'] as $bucket_key => $stats ) {
				if ( ! isset( $existing_hourly[ $bucket_key ] ) ) {
					$existing_hourly[ $bucket_key ] = [
						'count'       => 0,
						'sum_ms'      => 0,
						'sum_peak_mb' => 0,
					];
				}
				$existing_hourly[ $bucket_key ]['count']       += $stats['count'];
				$existing_hourly[ $bucket_key ]['sum_ms']      += $stats['sum_ms'];
				$existing_hourly[ $bucket_key ]['sum_peak_mb'] += $stats['sum_peak_mb'] ?? 0;
			}

			// Expire bucket data older than the retention window.
			$cutoff = self::bucket_key( \time() - StatsStore::get_retention() );
			foreach ( \array_keys( $existing_hourly ) as $bucket_key ) {
				if ( $bucket_key < $cutoff ) {
					unset( $existing_hourly[ $bucket_key ] );
				}
			}
			\ksort( $existing_hourly );

			StatsStore::set_hourly( $p, $existing_hourly );
		}

		// --- Leaderboard ---
		if ( null !== $context['global_leaderboard'] ) {
			$lb    = $context['global_leaderboard'];
			$ex_lb = StatsStore::get_leaderboard( $p ) ?? [
				'count'      => 0,
				'total_time' => 0,
				'categories' => [],
			];

			// Weighted average for total_time based on counts.
			$old_count = $ex_lb['count'] ?? 0;
			$new_count = $lb['count'] ?? 0;
			$total     = $old_count + $new_count;
			if ( $total > 0 ) {
				$ex_lb['total_time'] = (
					( $ex_lb['total_time'] ?? 0 ) * $old_count +
					( $lb['total_time'] ?? 0 ) * $new_count
				) / $total;
			}
			$ex_lb['count'] = $total;

			foreach ( ( $lb['categories'] ?? [] ) as $category => $data ) {
				if ( ! isset( $ex_lb['categories'][ $category ] ) ) {
					$ex_lb['categories'][ $category ] = $data;
				} else {
					$ex_cat = &$ex_lb['categories'][ $category ];

					// Weighted average based on samples.
					$old_samples   = $ex_cat['samples'] ?? 0;
					$new_samples   = $data['samples'] ?? 0;
					$total_samples = $old_samples + $new_samples;
					if ( $total_samples > 0 ) {
						$ex_cat['time']  = (
							( $ex_cat['time'] ?? 0 ) * $old_samples +
							( $data['time'] ?? 0 ) * $new_samples
						) / $total_samples;
						$ex_cat['count'] = (
							( $ex_cat['count'] ?? 0 ) * $old_samples +
							( $data['count'] ?? 0 ) * $new_samples
						) / $total_samples;
					}
					$ex_cat['samples'] = $total_samples;

					// Merge entries with hysteresis: only sort when upper limit hit.
					foreach ( ( $data['entries'] ?? [] ) as $name => $entry ) {
						if ( ! isset( $ex_cat['entries'][ $name ] ) ) {
							$ex_cat['entries'][ $name ] = $entry;
						} else {
							$ex_cat['entries'][ $name ][0] = \max( $ex_cat['entries'][ $name ][0], $entry[0] );
							$ex_cat['entries'][ $name ][1] = \max( $ex_cat['entries'][ $name ][1], $entry[1] );
						}
					}
					if ( \count( $ex_cat['entries'] ) > self::ENTRY_LIMIT_GLOBAL_UPPER ) {
						\uasort( $ex_cat['entries'], fn( $a, $b ) => $b[0] <=> $a[0] );
						$ex_cat['entries'] = \array_slice( $ex_cat['entries'], 0, self::ENTRY_LIMIT_GLOBAL_LOWER, true );
					}
				}
			}

			StatsStore::set_leaderboard( $p, $ex_lb );
		}

		// --- Per-server leaderboards (hub mode) ---
		foreach ( ( $context['leaderboard_by_server'] ?? [] ) as $server => $lb ) {
			$ex_lb = StatsStore::get_server_leaderboard( $p, $server ) ?? [
				'count'      => 0,
				'total_time' => 0,
				'categories' => [],
			];

			// Weighted average for total_time based on counts.
			$old_count = $ex_lb['count'] ?? 0;
			$new_count = $lb['count'] ?? 0;
			$total     = $old_count + $new_count;
			if ( $total > 0 ) {
				$ex_lb['total_time'] = (
					( $ex_lb['total_time'] ?? 0 ) * $old_count +
					( $lb['total_time'] ?? 0 ) * $new_count
				) / $total;
			}
			$ex_lb['count'] = $total;

			foreach ( ( $lb['categories'] ?? [] ) as $category => $data ) {
				if ( ! isset( $ex_lb['categories'][ $category ] ) ) {
					$ex_lb['categories'][ $category ] = $data;
				} else {
					$ex_cat = &$ex_lb['categories'][ $category ];

					$old_samples   = $ex_cat['samples'] ?? 0;
					$new_samples   = $data['samples'] ?? 0;
					$total_samples = $old_samples + $new_samples;
					if ( $total_samples > 0 ) {
						$ex_cat['time']  = (
							( $ex_cat['time'] ?? 0 ) * $old_samples +
							( $data['time'] ?? 0 ) * $new_samples
						) / $total_samples;
						$ex_cat['count'] = (
							( $ex_cat['count'] ?? 0 ) * $old_samples +
							( $data['count'] ?? 0 ) * $new_samples
						) / $total_samples;
					}
					$ex_cat['samples'] = $total_samples;

					foreach ( ( $data['entries'] ?? [] ) as $name => $entry ) {
						if ( ! isset( $ex_cat['entries'][ $name ] ) ) {
							$ex_cat['entries'][ $name ] = $entry;
						} else {
							$ex_cat['entries'][ $name ][0] = \max( $ex_cat['entries'][ $name ][0], $entry[0] );
							$ex_cat['entries'][ $name ][1] = \max( $ex_cat['entries'][ $name ][1], $entry[1] );
						}
					}
					if ( \count( $ex_cat['entries'] ) > self::ENTRY_LIMIT_GLOBAL_UPPER ) {
						\uasort( $ex_cat['entries'], fn( $a, $b ) => $b[0] <=> $a[0] );
						$ex_cat['entries'] = \array_slice( $ex_cat['entries'], 0, self::ENTRY_LIMIT_GLOBAL_LOWER, true );
					}
					unset( $ex_cat );
				}
			}

			StatsStore::set_server_leaderboard( $p, $server, $ex_lb );
		}

		// --- URL index (hourly buckets) ---
		if ( ! empty( $context['url_stats'] ) ) {
			foreach ( $context['url_stats'] as $bucket_key => $hour_data ) {
				$existing_urls = StatsStore::get_url_index_hourly( $p, $bucket_key ) ?? [];

				// Merge URL stats for this hour.
				foreach ( $hour_data as $hash => $stats ) {
					if ( ! isset( $existing_urls[ $hash ] ) ) {
						$existing_urls[ $hash ] = [
							'url'         => $stats['url'],
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

					$e                = &$existing_urls[ $hash ];
					$e['count']      += $stats['count'];
					$e['timed_count'] += $stats['timed_count'] ?? 0;
					$e['sum_ms']     += $stats['sum_ms'];
					$e['min_ms']     = ( 0 === $e['min_ms'] ) ? $stats['min_ms'] : \min( $e['min_ms'], $stats['min_ms'] );
					$e['max_ms']     = \max( $e['max_ms'], $stats['max_ms'] );
					$e['last_seen']  = \max( $e['last_seen'], $stats['last_seen'] );
					$e['count_2xx'] += $stats['count_2xx'] ?? 0;
					$e['count_3xx'] += $stats['count_3xx'] ?? 0;
					$e['count_4xx'] += $stats['count_4xx'] ?? 0;
					$e['count_5xx'] += $stats['count_5xx'] ?? 0;
					$e['sum_peak_mb'] += $stats['sum_peak_mb'] ?? 0;
					$e['max_peak_mb']  = \max( $e['max_peak_mb'], $stats['max_peak_mb'] ?? 0 );

					// Merge durations with reservoir sampling to avoid bias toward old data.
					$max_dur        = StatsStore::MAX_DURATIONS_PER_BUCKET;
					$merged         = \array_merge( $e['durations'], $stats['durations'] );
					if ( \count( $merged ) > $max_dur ) {
						\shuffle( $merged );
						$merged = \array_slice( $merged, 0, $max_dur );
					}
					$e['durations'] = $merged;
				}

				// Compute percentiles for all URLs in this hour.
				foreach ( $existing_urls as &$url_stat ) {
					if ( ! empty( $url_stat['durations'] ) ) {
						$sorted = $url_stat['durations'];
						\sort( $sorted );
						$n = \count( $sorted );

						$url_stat['p50_ms'] = $sorted[ (int) ( $n * 0.50 ) ] ?? 0;
						$url_stat['p95_ms'] = $sorted[ (int) ( $n * 0.95 ) ] ?? 0;
						$url_stat['p99_ms'] = $sorted[ (int) ( $n * 0.99 ) ] ?? 0;
						$tc = $url_stat['timed_count'] ?? $url_stat['count'];
						$url_stat['avg_ms'] = $tc > 0 ? $url_stat['sum_ms'] / $tc : 0;
					}
				}
				unset( $url_stat );

				// Cap URLs per bucket to stay within memcache 1MB value limit.
				// At ~1KB per URL (100 durations + metadata), 500 URLs ≈ 500KB.
				if ( \count( $existing_urls ) > 500 ) {
					\uasort( $existing_urls, fn( $a, $b ) => $b['count'] <=> $a['count'] );
					$existing_urls = \array_slice( $existing_urls, 0, 500, true );
				}

				StatsStore::set_url_index_hourly( $p, $bucket_key, $existing_urls );
			}
		}

		// --- Dimensional stats (global) ---
		$cutoff = self::bucket_key( \time() - StatsStore::get_retention() );
		foreach ( ( $context['dim_stats'] ?? [] ) as $dim => $buckets ) {
			$existing = StatsStore::get_dimensional( $p, $dim ) ?? [];
			self::merge_and_cap_dimensional( $existing, $buckets, $cutoff );
			StatsStore::set_dimensional( $p, $dim, $existing );
		}

		// --- Dimensional stats (per-server) ---
		foreach ( ( $context['dim_stats_by_server'] ?? [] ) as $server => $dims ) {
			foreach ( $dims as $dim => $buckets ) {
				$existing = StatsStore::get_dimensional( $p, $dim, $server ) ?? [];
				self::merge_and_cap_dimensional( $existing, $buckets, $cutoff );
				StatsStore::set_dimensional( $p, $dim, $existing, $server );
			}
		}

		// --- Per-URL dimensional stats ---
		foreach ( ( $context['url_dim_stats'] ?? [] ) as $url_hash => $dims ) {
			$existing = StatsStore::get_url_dimensional( $p, $url_hash ) ?? [];
			foreach ( $dims as $dim => $buckets ) {
				if ( ! isset( $existing[ $dim ] ) ) {
					$existing[ $dim ] = [];
				}
				self::merge_and_cap_dimensional( $existing[ $dim ], $buckets, $cutoff, StatsStore::MAX_URL_DIM_VALUES );
			}
			StatsStore::set_url_dimensional( $p, $url_hash, $existing );
		}

		// --- Category time series (global) ---
		if ( ! empty( $context['cat_stats'] ) ) {
			$existing_cats = StatsStore::get_categories( $p ) ?? [];
			self::merge_and_cap_categories( $existing_cats, $context['cat_stats'], $cutoff );
			StatsStore::set_categories( $p, $existing_cats );
		}

		// --- Category time series (per-server) ---
		foreach ( ( $context['cat_stats_by_server'] ?? [] ) as $server => $buckets ) {
			$existing = StatsStore::get_server_categories( $p, $server ) ?? [];
			self::merge_and_cap_categories( $existing, $buckets, $cutoff );
			StatsStore::set_server_categories( $p, $server, $existing );
		}

		// --- Category time series (per-URL) ---
		foreach ( ( $context['url_cat_stats'] ?? [] ) as $url_hash => $buckets ) {
			$existing_url_cats = StatsStore::get_url_categories( $p, $url_hash ) ?? [];
			self::merge_and_cap_categories( $existing_url_cats, $buckets, $cutoff );
			StatsStore::set_url_categories( $p, $url_hash, $existing_url_cats );
		}
	}

	/**
	 * Merge incoming dimensional buckets into existing, expire old, and cap.
	 *
	 * @param array  $existing   Existing dimensional data (modified by reference).
	 * @param array  $buckets    Incoming buckets to merge.
	 * @param string $cutoff     Bucket key cutoff for expiry.
	 * @param int    $max_values Maximum values per bucket (0 = use StatsStore::MAX_DIM_VALUES).
	 */
	private static function merge_and_cap_dimensional( array &$existing, array $buckets, string $cutoff, int $max_values = 0 ): void {
		if ( 0 === $max_values ) {
			$max_values = StatsStore::MAX_DIM_VALUES;
		}
		foreach ( $buckets as $bk => $values ) {
			foreach ( $values as $val => $stats ) {
				$existing[ $bk ][ $val ]['c'] = ( $existing[ $bk ][ $val ]['c'] ?? 0 ) + $stats['c'];
				$existing[ $bk ][ $val ]['s'] = ( $existing[ $bk ][ $val ]['s'] ?? 0 ) + $stats['s'];
				$existing[ $bk ][ $val ]['m'] = ( $existing[ $bk ][ $val ]['m'] ?? 0 ) + ( $stats['m'] ?? 0 );
			}
		}
		// Expire old buckets.
		foreach ( \array_keys( $existing ) as $bk ) {
			if ( $bk < $cutoff ) {
				unset( $existing[ $bk ] );
			}
		}
		// Cap each bucket to top $max_values by count.
		foreach ( $existing as $bk => &$bk_values ) {
			if ( \count( $bk_values ) > $max_values ) {
				\uasort( $bk_values, fn( $a, $b ) => $b['c'] <=> $a['c'] );
				$top    = \array_slice( $bk_values, 0, $max_values - 1, true );
				$rest_c = $rest_s = $rest_m = 0;
				foreach ( \array_slice( $bk_values, $max_values - 1 ) as $v ) {
					$rest_c += $v['c'];
					$rest_s += $v['s'];
					$rest_m += $v['m'] ?? 0;
				}
				$top['Other'] = [ 'c' => $rest_c, 's' => $rest_s, 'm' => $rest_m ];
				$bk_values    = $top;
			}
		}
		unset( $bk_values );
		\ksort( $existing );
	}

	/**
	 * Merge incoming category buckets into existing, expire old, and cap.
	 *
	 * Prevents unbounded growth that would exceed memcache's 1MB value limit.
	 * The 'total' pseudo-category is always preserved; overflow rolls into 'Other'.
	 *
	 * @param array  $existing   Existing category data (modified by reference).
	 * @param array  $buckets    Incoming buckets to merge.
	 * @param string $cutoff     Bucket key cutoff for expiry.
	 * @param int    $max_values Maximum categories per bucket (0 = use StatsStore::MAX_CAT_VALUES).
	 */
	private static function merge_and_cap_categories( array &$existing, array $buckets, string $cutoff, int $max_values = 0 ): void {
		if ( 0 === $max_values ) {
			$max_values = StatsStore::MAX_CAT_VALUES;
		}
		foreach ( $buckets as $bk => $categories ) {
			foreach ( $categories as $cat => $stats ) {
				$existing[ $bk ][ $cat ]['t'] = ( $existing[ $bk ][ $cat ]['t'] ?? 0 ) + $stats['t'];
				$existing[ $bk ][ $cat ]['c'] = ( $existing[ $bk ][ $cat ]['c'] ?? 0 ) + $stats['c'];
				$existing[ $bk ][ $cat ]['n'] = ( $existing[ $bk ][ $cat ]['n'] ?? 0 ) + $stats['n'];
			}
		}
		// Expire old buckets.
		foreach ( \array_keys( $existing ) as $bk ) {
			if ( $bk < $cutoff ) {
				unset( $existing[ $bk ] );
			}
		}
		// Cap each bucket to top $max_values by total time (t), preserving 'total'.
		foreach ( $existing as $bk => &$bk_cats ) {
			if ( \count( $bk_cats ) > $max_values ) {
				// Preserve and remove 'total' before sorting.
				$total = $bk_cats['total'] ?? null;
				unset( $bk_cats['total'] );
				\uasort( $bk_cats, fn( $a, $b ) => ( $b['t'] ?? 0 ) <=> ( $a['t'] ?? 0 ) );
				$top      = \array_slice( $bk_cats, 0, $max_values - 2, true ); // -2: room for total + Other
				$rest_t   = $rest_c = $rest_n = 0;
				foreach ( \array_slice( $bk_cats, $max_values - 2 ) as $v ) {
					$rest_t += $v['t'] ?? 0;
					$rest_c += $v['c'] ?? 0;
					$rest_n += $v['n'] ?? 0;
				}
				if ( $rest_t > 0 || $rest_c > 0 ) {
					$top['Other'] = [ 't' => $rest_t, 'c' => $rest_c, 'n' => $rest_n ];
				}
				if ( $total ) {
					$top['total'] = $total;
				}
				$bk_cats = $top;
			}
		}
		unset( $bk_cats );
		\ksort( $existing );
	}

	/**
	 * Promote completed pending bucket to the cat_stats accumulators, capped.
	 *
	 * Moves all pending category data for the completed bucket into the
	 * cat_stats / cat_stats_by_server / url_cat_stats arrays, applying
	 * the MAX_CAT_VALUES cap now that the bucket has complete data.
	 *
	 * @param array $context Context array (modified by reference).
	 */
	private static function promote_pending_bucket( array &$context ): void {
		$bk         = $context['pending_bucket'];
		$p          = &$context['pending'];
		$max_cats   = StatsStore::MAX_CAT_VALUES;

		// --- Hourly ---
		if ( ! empty( $p['hourly'] ) ) {
			$context['hourly_stats'][ $bk ] = $p['hourly'];
		}

		// --- URL stats ---
		if ( ! empty( $p['url_stats'] ) ) {
			$context['url_stats'][ $bk ] = $p['url_stats'];
		}

		// --- Dimensional (global) ---
		foreach ( $p['dim'] as $dim => $values ) {
			$context['dim_stats'][ $dim ][ $bk ] = $values;
		}

		// --- Dimensional (per-server) ---
		foreach ( $p['dim_by_server'] as $server => $dims ) {
			foreach ( $dims as $dim => $values ) {
				$context['dim_stats_by_server'][ $server ][ $dim ][ $bk ] = $values;
			}
		}

		// --- Dimensional (per-URL) ---
		foreach ( $p['url_dim'] as $url_hash => $dims ) {
			foreach ( $dims as $dim => $values ) {
				$context['url_dim_stats'][ $url_hash ][ $dim ][ $bk ] = $values;
			}
		}

		// --- Category (global, capped) ---
		if ( ! empty( $p['cat'] ) ) {
			$context['cat_stats'][ $bk ] = self::cap_single_bucket( $p['cat'], $max_cats );
		}

		// --- Category (per-server, capped) ---
		foreach ( $p['cat_by_server'] as $server => $cats ) {
			$context['cat_stats_by_server'][ $server ][ $bk ] = self::cap_single_bucket( $cats, $max_cats );
		}

		// --- Category (per-URL, capped) ---
		foreach ( $p['cat_by_url'] as $url_hash => $cats ) {
			$context['url_cat_stats'][ $url_hash ][ $bk ] = self::cap_single_bucket( $cats, $max_cats );
		}

		// Reset pending for the new bucket.
		$context['pending'] = [
			'hourly'        => [],
			'dim'           => [],
			'dim_by_server' => [],
			'url_dim'       => [],
			'url_stats'     => [],
			'cat'           => [],
			'cat_by_server' => [],
			'cat_by_url'    => [],
		];
	}

	/**
	 * Cap a single bucket's categories to top N by time, preserving 'total'.
	 *
	 * @param array $cats       Category data { cat => {t,c,n} }.
	 * @param int   $max_values Maximum categories to keep.
	 * @return array Capped category data.
	 */
	private static function cap_single_bucket( array $cats, int $max_values ): array {
		if ( \count( $cats ) <= $max_values ) {
			return $cats;
		}
		$total = $cats['total'] ?? null;
		unset( $cats['total'] );
		\uasort( $cats, fn( $a, $b ) => ( $b['t'] ?? 0 ) <=> ( $a['t'] ?? 0 ) );
		$top    = \array_slice( $cats, 0, $max_values - 2, true );
		$rest_t = $rest_c = $rest_n = 0;
		foreach ( \array_slice( $cats, $max_values - 2 ) as $v ) {
			$rest_t += $v['t'] ?? 0;
			$rest_c += $v['c'] ?? 0;
			$rest_n += $v['n'] ?? 0;
		}
		if ( $rest_t > 0 || $rest_c > 0 ) {
			$top['Other'] = [ 't' => $rest_t, 'c' => $rest_c, 'n' => $rest_n ];
		}
		if ( $total ) {
			$top['total'] = $total;
		}
		return $top;
	}

	/**
	 * Recursively merge flame children using incremental averaging.
	 *
	 * @param array $existing Existing children array.
	 * @param array $incoming Incoming children to merge.
	 * @param int   $depth    Current recursion depth.
	 * @return array Merged children array.
	 */
	private static function merge_flame_children_incremental( array $existing, array $incoming, int $depth = 0 ): array {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return $existing;
		}

		$indexed = [];
		foreach ( $existing as $child ) {
			$indexed[ $child['name'] ] = $child;
		}

		foreach ( $incoming as $child ) {
			$name     = $child['name'] ?? 'unknown';
			$child_ts = (int) ( $child['ts'] ?? \time() );
			if ( ! isset( $indexed[ $name ] ) ) {
				// New node: store raw value (scaling by seen_count/count happens at display time).
				$indexed[ $name ] = [
					'name'       => $name,
					'value'      => $child['value'] ?? 0,
					'seen_count' => 1,
					'ts'         => $child_ts,
					'children'   => [],
				];
			} else {
				++$indexed[ $name ]['seen_count'];
				$indexed[ $name ]['ts'] = $child_ts;
				$old_value              = $indexed[ $name ]['value'];
				$new_value              = $child['value'] ?? 0;
				// EMA over times this node was seen (scaling by seen_count/count happens at display time).
				$indexed[ $name ]['value'] = $old_value + ( $new_value - $old_value ) / $indexed[ $name ]['seen_count'];
			}

			if ( ! empty( $child['children'] ) ) {
				$indexed[ $name ]['children'] = self::merge_flame_children_incremental(
					$indexed[ $name ]['children'] ?? [],
					$child['children'],
					$depth + 1
				);
			}
		}

		// Expire entries not seen in over 1 hour.
		$cutoff = \time() - 3600;
		foreach ( $indexed as $name => $child ) {
			if ( ( $child['ts'] ?? 0 ) < $cutoff ) {
				unset( $indexed[ $name ] );
			}
		}

		return \array_values( $indexed );
	}

	/**
	 * Finalize flame node for storage: scale values, strip suffixes, normalize.
	 *
	 * @param array $node        Flame node (modified in place).
	 * @param int   $total_count Total request count for scaling.
	 * @param int   $depth       Current recursion depth.
	 */
	private static function finalize_flame_node( array &$node, int $total_count, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}

		// Strip hidden sequence suffix (\x00N) used for duplicate sibling tracking.
		$name     = $node['name'] ?? 'unknown';
		$null_pos = \strpos( $name, "\x00" );
		if ( false !== $null_pos ) {
			$node['name'] = \substr( $name, 0, $null_pos );
		}

		// Scale value by seen_count/total_count for nodes not seen in every request.
		if ( $total_count > 0 && isset( $node['seen_count'] ) ) {
			$seen = (int) $node['seen_count'];
			if ( $seen > 0 && $seen < $total_count ) {
				$node['value'] = ( $node['value'] ?? 0 ) * $seen / $total_count;
			}
		}

		// Process children recursively (must happen before normalization).
		if ( ! empty( $node['children'] ) ) {
			foreach ( $node['children'] as &$child ) {
				self::finalize_flame_node( $child, $total_count, $depth + 1 );
			}
			unset( $child );

			// Normalize: ensure parent value >= sum of children (scaling can violate this).
			$children_sum = 0;
			foreach ( $node['children'] as $child ) {
				$children_sum += $child['value'] ?? 0;
			}
			if ( $children_sum > ( $node['value'] ?? 0 ) ) {
				$node['value'] = $children_sum;
			}
		}

		// Remove internal tracking fields not needed by client.
		unset( $node['ts'] );
	}

	/**
	 * Refresh significant_events from database.
	 *
	 * Called during flush to pick up significant events discovered by other workers.
	 *
	 * @param array $context      Context array (modified by reference).
	 * @param bool  $is_caught_up True if worker is caught up (idle).
	 */
	private static function refresh_significant_events( array &$context, bool $is_caught_up ): void {
		$now      = \microtime( true );
		$interval = $is_caught_up ? 1.0 : 15.0;

		if ( ( $now - $context['last_significant_refresh'] ) < $interval ) {
			return;
		}

		$context['last_significant_refresh'] = $now;

		// Reset Config cache to get fresh values from DB.
		Config::reset();
		$config = Config::load_config( 'full' );

		$significant                   = $config['significant_events'] ?? [];
		$context['significant_events'] = [];
		if ( \is_array( $significant ) ) {
			foreach ( $significant as $event ) {
				$context['significant_events'][ $event ] = true;
			}
		}
	}

	/**
	 * Apply auto-disable decisions: persist hooks/events to disable and newly discovered significant events.
	 *
	 * Called during flush. Uses WordPress actions to allow hub mode to override behavior.
	 * Uses transient-based locking to prevent race conditions between FlameBuilder workers.
	 *
	 * @param array $context Context array (modified by reference).
	 */
	private static function apply_auto_tune( array &$context ): void {
		// Skip if nothing to persist.
		if ( empty( $context['hooks_to_disable'] ) && empty( $context['custom_events_to_disable'] ) && empty( $context['new_significant_events'] ) ) {
			return;
		}

		// Acquire lock to prevent race condition between multiple FlameBuilder workers.
		// Uses Memcached::add() with short TTL (5 seconds) as a simple distributed lock.
		$lock_key     = 'evlog:auto_disable_lock';
		$lock_timeout = 5;
		$lock_value   = \wp_generate_uuid4();

		// Try to acquire lock (add returns false if key already exists).
		if ( ! Memcached::add( $lock_key, $lock_value, $lock_timeout ) ) {
			// Lock held by another worker - skip this round, will retry on next flush.
			return;
		}

		try {
			// Clear option cache to get fresh values (FlameBuilder is long-running).
			\wp_cache_delete( 'alloptions', 'options' );

			// Fire actions for auto-disable (allows hub mode to override).
			// Standalone handlers are registered in dashboards.php.
			\do_action( 'newspack_performance_workers_disable_hooks', \array_keys( $context['hooks_to_disable'] ), $context );
			\do_action( 'newspack_performance_workers_disable_custom_events', \array_keys( $context['custom_events_to_disable'] ), $context );
			\do_action( 'newspack_performance_workers_add_significant_events', \array_keys( $context['new_significant_events'] ), $context );

			// Clear pending lists after actions have been fired.
			$context['hooks_to_disable']         = [];
			$context['custom_events_to_disable'] = [];
			$context['new_significant_events']   = [];
		} finally {
			// Release lock only if we still own it (verify before delete).
			$current = Memcached::get( $lock_key );
			if ( $current === $lock_value ) {
				Memcached::delete( $lock_key );
			}
		}
	}

	/**
	 * Generate a 5-minute bucket key from a Unix timestamp.
	 *
	 * Minutes are rounded down to the nearest BUCKET_MINUTES boundary
	 * (00, 05, 10, ..., 55). Format: Y-m-d-H-i (UTC).
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Bucket key.
	 */
	private static function bucket_key( int $timestamp ): string {
		$min        = (int) \gmdate( 'i', $timestamp );
		$bucket_min = \str_pad( (string) ( (int) \floor( $min / self::BUCKET_MINUTES ) * self::BUCKET_MINUTES ), 2, '0', STR_PAD_LEFT );
		return \gmdate( 'Y-m-d-H', $timestamp ) . '-' . $bucket_min;
	}
}
