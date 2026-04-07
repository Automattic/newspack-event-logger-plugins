<?php
/**
 * Overview Controller
 *
 * Dashboard summary stats.
 *
 * @package Newspack_Performance_Dashboards
 */

namespace Newspack_Performance_Dashboards\REST;

use Newspack_Performance_Workers\StatsStore;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for dashboard overview endpoint.
 */
class OverviewController extends PerformanceControllerBase {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /performance/overview - Dashboard summary.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/overview',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_overview' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
					'args'                => [
						'breakdown' => [
							'type'              => 'string',
							'enum'              => [ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ],
							'sanitize_callback' => 'sanitize_text_field',
						],
						'server'     => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'categories' => [
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
	 * Get dashboard overview.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response with overview data.
	 */
	public function get_overview( $request ) {
		// Rate limit: aggregates data across all partitions and 24 hours, potentially expensive.
		$rate_check = $this->check_rate_limit();
		if ( \is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$index = $this->load_index();

		$slowest = $index;
		// Use spaceship operator to avoid float truncation.
		\usort( $slowest, fn( $a, $b ) => ( $b['p95_ms'] ?? 0 ) <=> ( $a['p95_ms'] ?? 0 ) );

		// Get hourly time series and derive totals from it (last 24 hours).
		$time_series    = $this->build_aggregate_time_series();
		$total_requests    = 0;
		$total_sum_ms      = 0;
		$total_sum_peak_mb = 0;

		foreach ( $time_series as $hour_data ) {
			$total_requests    += $hour_data['count'] ?? 0;
			$total_sum_ms      += $hour_data['sum_ms'] ?? 0;
			$total_sum_peak_mb += $hour_data['sum_peak_mb'] ?? 0;
		}

		$server = $request->get_param( 'server' );

		$response = [
			'total_urls'            => \count( $index ),
			'total_requests'        => $total_requests,
			'global_avg_ms'         => $total_requests > 0 ? $total_sum_ms / $total_requests : 0,
			'global_avg_peak_mb'    => $total_requests > 0 ? $total_sum_peak_mb / $total_requests : 0,
			'slowest_urls'          => \array_slice( $slowest, 0, 10 ),
			'most_requested'        => \array_slice( $index, 0, 10 ),
			'aggregate_time_series' => $time_series,
			'global_leaderboard'    => $server
				? $this->get_server_leaderboard( $server )
				: $this->get_global_leaderboard(),
		];

		// Add dimensional breakdown data if requested.
		$breakdown = $request->get_param( 'breakdown' );
		if ( $breakdown && \in_array( $breakdown, StatsStore::DIMENSIONS, true ) ) {
			$response['breakdown_time_series'] = StatsStore::get_merged_dimensional( $breakdown, $server ?? '' );
		}

		// Add category time series if requested.
		if ( $request->get_param( 'categories' ) ) {
			$response['category_time_series'] = $server
				? StatsStore::get_merged_server_categories( $server )
				: StatsStore::get_merged_categories();
		}

		return \rest_ensure_response( $response );
	}

	/**
	 * Get merged per-server leaderboard from all partitions.
	 *
	 * @param string $server Server name.
	 * @return array|null Merged leaderboard data or null if none available.
	 */
	private function get_server_leaderboard( string $server ): ?array {
		$merged = StatsStore::get_merged_server_leaderboard( $server );
		if ( $merged ) {
			$this->scale_categories_by_samples( $merged );
		}
		return $merged;
	}

	/**
	 * Get merged global leaderboard from all partitions.
	 *
	 * @return array|null Merged leaderboard data or null if none available.
	 */
	private function get_global_leaderboard(): ?array {
		$merged = StatsStore::get_merged_leaderboard();

		// Scale category times by samples/count to get true average over ALL requests.
		if ( $merged ) {
			$this->scale_categories_by_samples( $merged );
		}

		return $merged;
	}

	/**
	 * Load aggregate time series from memcache (sum across partitions).
	 *
	 * @return array Aggregated time series data by hour.
	 */
	private function build_aggregate_time_series(): array {
		return StatsStore::get_merged_hourly();
	}
}
