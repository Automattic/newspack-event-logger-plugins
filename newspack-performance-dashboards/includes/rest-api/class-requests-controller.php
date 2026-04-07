<?php
/**
 * Requests Controller
 *
 * Single request trace endpoint.
 *
 * @package Newspack_Performance_Dashboards
 */

namespace Newspack_Performance_Dashboards\REST;

use Newspack_Performance_Workers\Cron\RequestBuilder;
use Newspack_Performance_Workers\Cron\FlameBuilder;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for single request trace endpoint.
 */
class RequestsController extends PerformanceControllerBase {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /performance/requests/search/{rid} - Search for request across all partitions.
		\register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/requests/search/(?P<rid>[a-zA-Z0-9_-]{1,128})",
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'search_request' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
					'args'                => [
						'rid' => [
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// GET /performance/requests/{rid}?partition=N - Get single request trace.
		\register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/requests/(?P<rid>[a-zA-Z0-9_-]{1,128})",
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_request' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
					'args'                => [
						'rid' => [
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'partition' => [
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	/**
	 * Search for a request across all partitions.
	 * Returns minimal info: rid, partition, url_hash.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function search_request( $request ) {
		$rate_check = $this->check_rate_limit();
		if ( \is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$rid           = $request->get_param( 'rid' );
		$entries_count = 0;

		for ( $partition = 0; $partition < $this->num_partitions; $partition++ ) {
			$result = $this->find_request_index_entry( $partition, $rid, $entries_count );
			if ( $result ) {
				return \rest_ensure_response( $result );
			}
			if ( $entries_count > static::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		return $this->not_found_error( 'Request not found.' );
	}

	/**
	 * Find request index entry (minimal data for search).
	 *
	 * @param int $partition     Partition number.
	 * @param string $rid        Request ID.
	 * @param int $entries_count Global entry counter (by reference).
	 * @return array|null Minimal request info or null.
	 */
	private function find_request_index_entry( int $partition, string $rid, int &$entries_count ): ?array {
		$result       = null;
		$requests_log = $this->get_requests_log( $partition );

		$requests_log->scan_index(
			function ( string $line ) use ( &$result, &$entries_count, $partition, $rid ) {
				++$entries_count;
				if ( $entries_count > static::MAX_INDEX_ENTRIES ) {
					return false;
				}

				$entry = RequestBuilder::parse_request_index( $line );
				if ( ! $entry || \trim( $entry['rid'] ) !== $rid ) {
					return null;
				}

				$result = [
					'rid'       => $rid,
					'partition' => $partition,
					'url_hash'  => \trim( $entry['url_hash'] ),
				];
				return false;
			},
			true
		);

		return $result;
	}

	/**
	 * Get single request trace (searches requests index by rid).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function get_request( $request ) {
		$rate_check = $this->check_rate_limit();
		if ( \is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$rid           = $request->get_param( 'rid' );
		$partition     = $request->get_param( 'partition' );
		$entries_count = 0;

		if ( $partition >= $this->num_partitions ) {
			return $this->not_found_error( 'Invalid partition.' );
		}

		$result = $this->find_request_in_partition( $partition, $rid, $entries_count );
		if ( $result ) {
			return \rest_ensure_response( $result );
		}

		return $this->not_found_error( 'Request not found.' );
	}

	/**
	 * Find a request by rid in a partition.
	 *
	 * @param int $partition     Partition number.
	 * @param string $rid        Request ID.
	 * @param int $entries_count Global entry counter (by reference).
	 * @return array|null Request data or null if not found.
	 */
	private function find_request_in_partition( int $partition, string $rid, int &$entries_count ): ?array {
		$result       = null;
		$requests_log = $this->get_requests_log( $partition );

		$requests_log->scan_index(
			function ( string $line ) use ( &$result, &$entries_count, $requests_log, $rid ) {
				++$entries_count;
				if ( $entries_count > static::MAX_INDEX_ENTRIES ) {
					return false; // Stop scanning, limit reached.
				}

				$entry = RequestBuilder::parse_request_index( $line );
				if ( ! $entry || \trim( $entry['rid'] ) !== $rid ) {
					return null; // Continue scanning.
				}

				$data = $requests_log->read_at( $entry['segment_id'], $entry['offset'], $entry['length'] );
				if ( ! $data ) {
					return false; // Stop scanning, entry found but data missing.
				}

				$request_data = \json_decode( \trim( $data ), true, 64 );
				if ( ! \is_array( $request_data ) ) {
					return false; // Stop scanning.
				}

				$request_data['url_hash'] = \trim( $entry['url_hash'] );
				$flame                    = $this->find_flame_for_rid( $rid );
				if ( $flame ) {
					$request_data['flame_data'] = $flame;
				}

				$result = $request_data;
				return false; // Stop scanning.
			},
			true // Newest first.
		);

		return $result;
	}

	/**
	 * Find flame data for a request ID.
	 *
	 * @param string $rid Request ID.
	 * @return array|null Flame data or null if not found.
	 */
	private function find_flame_for_rid( string $rid ): ?array {
		$result        = null;
		$entries_count = 0;

		for ( $partition = 0; $partition < $this->num_partitions; $partition++ ) {
			$flames_log = $this->get_flames_log( $partition );

			// Add max entries limit to prevent unbounded scanning.
			$flames_log->scan_index(
				function ( string $line ) use ( &$result, &$entries_count, $flames_log, $rid ) {
					++$entries_count;
					if ( $entries_count > static::MAX_INDEX_ENTRIES ) {
						return false; // Stop scanning, limit reached.
					}

					$entry = FlameBuilder::parse_flame_index( $line );
					if ( ! $entry || \trim( $entry['rid'] ) !== $rid ) {
						return null; // Continue scanning.
					}

					$data = $flames_log->read_at( $entry['segment_id'], $entry['offset'], $entry['length'] );
					if ( ! $data ) {
						return false; // Stop scanning.
					}

					$flame = \json_decode( \trim( $data ), true, 64 );
					if ( \is_array( $flame ) ) {
						$result = $flame;
					}
					return false; // Stop scanning.
				},
				true // Newest first.
			);

			if ( null !== $result ) {
				break;
			}

			// Stop if we've hit the limit across partitions.
			if ( $entries_count > static::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		return $result;
	}
}
