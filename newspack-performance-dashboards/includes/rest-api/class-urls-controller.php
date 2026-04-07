<?php
/**
 * URLs Controller
 *
 * URL list and detail endpoints.
 *
 * @package Newspack_Performance_Dashboards
 */

namespace Newspack_Performance_Dashboards\REST;

use Newspack_Performance_Workers\StatsStore;
use Newspack_Performance_Workers\Cron\RequestBuilder;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for URL-related endpoints.
 */
class UrlsController extends PerformanceControllerBase {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /performance/urls - List URLs with stats.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/urls',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_urls' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
					'args'                => [
						'sort'   => [
							'default'           => 'count',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $v ) {
								return \in_array( $v, [ 'count', 'url', 'avg_ms', 'min_ms', 'max_ms', 'p95_ms', 'avg_peak_mb', 'last_updated' ], true );
							},
						],
						'order'  => [
							'default'           => 'desc',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $v ) {
								return \in_array( $v, [ 'asc', 'desc' ], true );
							},
						],
						'limit'  => [
							'default'           => 50,
							'sanitize_callback' => function ( $v ) {
								return \min( 1000, \absint( $v ) );
							},
						],
						'offset' => [
							'default'           => 0,
							'sanitize_callback' => function ( $v ) {
								return \min( 10000, \max( 0, \absint( $v ) ) );
							},
						],
						'search' => [
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'server' => [
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// GET /performance/urls/{hash} - Get URL detail + flame data.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/urls/(?P<hash>[a-f0-9]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_url_detail' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
					'args'                => [
						'hash'         => [
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							// Validate hash is 8-64 hex chars to prevent DoS from megabyte-length inputs.
							'validate_callback' => function ( $v ) {
								return \preg_match( '/^[a-f0-9]{8,64}$/', $v );
							},
						],
						'breakdown'    => [
							'type'              => 'string',
							'enum'              => [ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ],
							'sanitize_callback' => 'sanitize_text_field',
						],
						'error_status' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'categories'   => [
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				],
			]
		);
	}

	/**
	 * Get URLs list with stats.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response with URL list or rate limit error.
	 */
	public function get_urls( $request ) {
		// Rate limiting.
		$rate_check = $this->check_rate_limit();
		if ( true !== $rate_check ) {
			return $rate_check;
		}

		$sort   = $request->get_param( 'sort' );
		$order  = $request->get_param( 'order' );
		$limit  = $request->get_param( 'limit' );
		$offset = $request->get_param( 'offset' );
		$search = $request->get_param( 'search' );
		$server = $request->get_param( 'server' );
		$index  = $this->load_index();

		// Filter by server name (substring match on URL).
		if ( '' !== $server ) {
			$srv   = \strtolower( $server );
			$index = \array_values( \array_filter(
				$index,
				fn( $entry ) => false !== \strpos( \strtolower( $entry['url'] ?? '' ), $srv )
			) );
		}

		// Filter by search term before sorting/limiting.
		if ( '' !== $search ) {
			$term  = \strtolower( $search );
			$index = \array_values( \array_filter(
				$index,
				fn( $entry ) => false !== \strpos( \strtolower( $entry['url'] ?? '' ), $term )
			) );
		}

		$total = \count( $index );

		if ( 'count' !== $sort || 'asc' === $order ) {
			// Use spaceship operator to avoid float truncation and integer overflow.
			\usort(
				$index,
				fn( $a, $b ) => 'asc' === $order
					? ( $a[ $sort ] ?? 0 ) <=> ( $b[ $sort ] ?? 0 )
					: ( $b[ $sort ] ?? 0 ) <=> ( $a[ $sort ] ?? 0 )
			);
		}

		return \rest_ensure_response( [
			'data'   => \array_slice( $index, $offset, $limit ),
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
		] );
	}

	/**
	 * Get URL detail with stats and recent requests (merged from all partitions).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response with URL detail or error.
	 */
	public function get_url_detail( $request ) {
		// Rate limiting.
		$rate_check = $this->check_rate_limit();
		if ( true !== $rate_check ) {
			return $rate_check;
		}

		$hash         = $request->get_param( 'hash' );
		$merged_stats = $this->get_url_stats_from_cache( $hash );

		if ( ! $merged_stats ) {
			return $this->not_found_error( 'URL not found.' );
		}

		$requests = $this->get_recent_requests_for_url( $hash );

		// Filter by error_status if requested (e.g. ?error_status=F,T).
		$error_filter = $request->get_param( 'error_status' );
		if ( $error_filter ) {
			$allowed  = \array_map( 'trim', \explode( ',', $error_filter ) );
			$requests = \array_filter( $requests, fn( $r ) => \in_array( $r['error_status'] ?? '', $allowed, true ) );
			$requests = \array_values( $requests );
		}

		$merged_stats['time_series'] = StatsStore::get_url_time_series( $hash );
		$aggregate_stats            = $this->get_aggregate_stats_for_url( $hash );
		$aggregate_flame            = $aggregate_stats['flame']
			?? [ 'name' => 'aggregate', 'value' => 0, 'children' => [] ];
		$aggregate_profiles         = $aggregate_stats['profiles'] ?? null;

		// Scale category times by samples/count to get true average over ALL requests.
		if ( $aggregate_profiles ) {
			$this->scale_categories_by_samples( $aggregate_profiles );
		}

		// Fetch per-URL dimensional breakdown if requested.
		$breakdown             = $request->get_param( 'breakdown' );
		$breakdown_time_series = null;
		if ( $breakdown && \in_array( $breakdown, StatsStore::DIMENSIONS, true ) ) {
			$breakdown_time_series = StatsStore::get_merged_url_dimensional( $hash, $breakdown );
		}

		// Add per-URL category time series if requested.
		$category_time_series = null;
		if ( $request->get_param( 'categories' ) ) {
			$category_time_series = StatsStore::get_merged_url_categories( $hash );
		}

		return \rest_ensure_response(
			[
				'stats'                 => $merged_stats,
				'requests'              => $requests,
				'aggregate_flame'       => $aggregate_flame,
				'aggregate_profiles'    => $aggregate_profiles,
				'last_modified'         => $aggregate_stats['last_modified'] ?? 0,
				'breakdown_time_series' => $breakdown_time_series,
				'category_time_series'  => $category_time_series,
			]
		);
	}

	/**
	 * Get URL stats from memcache (aggregated from hourly buckets).
	 *
	 * Uses early termination - returns immediately when hash is found,
	 * and limits scanning to MAX_INDEX_ENTRIES to prevent full scan when hash
	 * is not found (e.g., for non-existent URL lookups).
	 *
	 * @param string $hash URL hash.
	 * @return array|null URL stats or null if not found.
	 */
	private function get_url_stats_from_cache( string $hash ): ?array {
		// Use the merged URL index which aggregates hourly buckets across partitions.
		$index = StatsStore::get_merged_url_index();

		// Track entries scanned to prevent unbounded iteration.
		$entries_scanned = 0;

		// Find the entry with early termination (return on first match).
		foreach ( $index as $entry ) {
			++$entries_scanned;

			// Stop scanning if we've exceeded MAX_INDEX_ENTRIES limit.
			if ( $entries_scanned > static::MAX_INDEX_ENTRIES ) {
				break;
			}

			if ( ( $entry['hash'] ?? '' ) === $hash ) {
				// Early termination: return immediately when hash is found.
				return [
					'hash'         => $hash,
					'url'          => $entry['url'] ?? '',
					'count'        => $entry['count'] ?? 0,
					'avg_ms'       => $entry['avg_ms'] ?? 0,
					'min_ms'       => $entry['min_ms'] ?? 0,
					'max_ms'       => $entry['max_ms'] ?? 0,
					'p50_ms'       => $entry['p50_ms'] ?? 0,
					'p95_ms'       => $entry['p95_ms'] ?? 0,
					'p99_ms'       => $entry['p99_ms'] ?? 0,
					'avg_peak_mb'  => $entry['avg_peak_mb'] ?? 0,
					'max_peak_mb'  => $entry['max_peak_mb'] ?? 0,
					'last_updated' => $entry['last_seen'] ?? 0,
					'time_series'  => [],
				];
			}
		}

		return null;
	}

	/**
	 * Get recent requests for a URL hash from requests index.
	 *
	 * Add MAX_INDEX_ENTRIES limit to prevent unbounded segment scanning.
	 *
	 * @param string $url_hash URL hash.
	 * @return array Recent requests.
	 */
	private function get_recent_requests_for_url( string $url_hash ): array {
		$requests      = [];
		$entries_count = 0;

		for ( $partition = 0; $partition < $this->num_partitions; $partition++ ) {
			$this->get_requests_log( $partition )->scan_index(
				function ( string $line ) use ( &$requests, &$entries_count, $url_hash, $partition ) {
					++$entries_count;
					// Stop scanning if index entries limit reached.
					if ( $entries_count > static::MAX_INDEX_ENTRIES ) {
						return false; // Stop scanning, limit reached.
					}

					$entry = RequestBuilder::parse_request_index( $line );
					if ( ! $entry || \trim( $entry['url_hash'] ) !== $url_hash ) {
						return null; // Continue scanning.
					}

					$requests[] = [
						'rid'          => \trim( $entry['rid'] ),
						'timestamp'    => $entry['timestamp'],
						'duration_ms'  => $entry['duration_ms'] ?? 0,
						'status_code'  => $entry['status_code'] ?? 0,
						'peak_mb'      => $entry['peak_mb'] ?? 0,
						'method'       => $entry['method'] ?? '',
						'error_status' => $entry['error_status'] ?? null,
						'segment_id'   => $entry['segment_id'],
						'offset'       => $entry['offset'],
						'length'       => $entry['length'],
						'partition'    => $partition,
					];

					if ( \count( $requests ) >= 500 ) {
						return false; // Stop scanning.
					}
					return null; // Continue scanning.
				},
				true // Newest first.
			);

			// Stop if we've hit the request limit or index entries limit.
			if ( \count( $requests ) >= 500 || $entries_count > static::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		// Deduplicate by rid (keep newest — first occurrence after sort).
		\usort( $requests, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		$seen = [];
		$unique = [];
		foreach ( $requests as $req ) {
			if ( ! isset( $seen[ $req['rid'] ] ) ) {
				$seen[ $req['rid'] ] = true;
				$unique[]            = $req;
				if ( \count( $unique ) >= 500 ) {
					break;
				}
			}
		}
		return $unique;
	}

	/**
	 * Get aggregate stats (flame + profiles) for a URL hash from memcache.
	 *
	 * @param string $url_hash URL hash.
	 * @return array|null Aggregate stats or null if not found.
	 */
	private function get_aggregate_stats_for_url( string $url_hash ): ?array {
		return StatsStore::get_url_stats_any_partition( $url_hash );
	}

}
