<?php
/**
 * Discovery Controller
 *
 * REST controller for discovering Event Logger configuration.
 * Returns registered hooks, custom events, and worker lag information.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\REST;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Cron\LogReader;
use Newspack_Event_Logger\Firehose;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovery controller class.
 */
class DiscoveryController extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'event-logger/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'discovery';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_discovery' ],
				'permission_callback' => [ $this, 'discovery_permissions_check' ],
			]
		);
	}

	/**
	 * Check if current user can access discovery information.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function discovery_permissions_check() {
		if ( ! \Newspack_Event_Logger\Admin\Admin::current_user_allowed() ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to access this resource.', 'newspack-event-logger' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Get discovery information.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function get_discovery( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// Get registered hooks and custom events from Config.
		$config = Config::load_config( 'full' );

		$log_events       = $config['log_events'] ?? [];
		$registered_hooks = [];
		if ( \is_array( $log_events ) ) {
			foreach ( $log_events as $key => $value ) {
				if ( \is_string( $key ) && '' !== $key && ! \is_numeric( $key ) ) {
					$registered_hooks[] = $key;
				} elseif ( \is_string( $value ) && '' !== $value ) {
					$registered_hooks[] = $value;
				}
			}
			$registered_hooks = \array_values( \array_unique( $registered_hooks ) );
		}

		// Build custom events list first so we can filter them from hooks.
		$custom_events_option = $config['custom_events'] ?? [];
		$custom_events        = [];
		if ( \is_array( $custom_events_option ) ) {
			foreach ( $custom_events_option as $key => $value ) {
				if ( \is_string( $key ) && '' !== $key && ! \is_numeric( $key ) ) {
					$custom_events[] = $key;
				} elseif ( \is_string( $value ) && '' !== $value ) {
					$custom_events[] = $value;
				}
			}
			$custom_events = \array_values( \array_unique( $custom_events ) );
		}

		// Filter custom event names out of registered_hooks (prevent cross-contamination).
		if ( ! empty( $custom_events ) ) {
			$custom_set       = \array_flip( $custom_events );
			$registered_hooks = \array_values( \array_filter( $registered_hooks, fn( $h ) => ! isset( $custom_set[ $h ] ) ) );
		}

		// Calculate lag (how far behind workers are).
		$lag = $this->calculate_lag();

		$response = [
			'registered_hooks' => $registered_hooks,
			'custom_events'    => $custom_events,
		];

		// Only include lag if we have data.
		if ( null !== $lag ) {
			$response['lag'] = $lag;
		}

		return \rest_ensure_response( $response );
	}

	/**
	 * Calculate how far behind workers are (lag).
	 *
	 * Returns the maximum lag across all readers in bytes.
	 * Lag is the difference between the firehose write position and the reader's position.
	 *
	 * @return int|null Lag in bytes, or null if unable to calculate.
	 */
	private function calculate_lag(): ?int {
		try {
			$config         = Config::load_config( 'full' );
			$log_base       = Config::get_logs_directory();
			$num_partitions = $config['num_partitions'] ?? 1;
			$segment_size   = $config['segment_size'] ?? ( 64 * 1024 * 1024 );

			$readers = LogReader::get_registered_readers();
			if ( empty( $readers ) ) {
				return null;
			}

			$max_lag = 0;

			// Check each reader's lag.
			foreach ( $readers as $name => $reader_config ) {
				$input_log = $reader_config['inputs'][0] ?? 'firehose.log';

				for ( $p = 0; $p < $num_partitions; $p++ ) {
					$firehose = new Firehose(
						"{$log_base}/{$input_log}",
						$p
					);

					// Get current write position.
					$write_pos = $firehose->get_current_position();
					if ( empty( $write_pos ) ) {
						continue;
					}

					// Get reader position from unified offsetlog.
					$positions  = LogReader::get_saved_positions( $name, $p );
					$pos        = $positions[ $input_log ] ?? null;
					$reader_pos = [
						'segment_id' => $pos['seg'] ?? 0,
						'offset'     => $pos['off'] ?? 0,
					];

					// Calculate lag in bytes.
					$lag = $this->calculate_position_difference( $write_pos, $reader_pos, $segment_size );
					if ( $lag > $max_lag ) {
						$max_lag = $lag;
					}
				}
			}

			return $max_lag;
		} catch ( \Throwable $e ) {
			// Unable to calculate lag - return null.
			return null;
		}
	}

	/**
	 * Calculate the byte difference between two positions.
	 *
	 * @param array $write_pos    Write position with segment_id and offset.
	 * @param array $reader_pos   Reader position with segment_id and offset.
	 * @param int   $segment_size Segment size in bytes.
	 * @return int Lag in bytes.
	 */
	private function calculate_position_difference( array $write_pos, array $reader_pos, int $segment_size ): int {
		$write_segment  = $write_pos['segment_id'] ?? 0;
		$write_offset   = $write_pos['offset'] ?? 0;
		$reader_segment = $reader_pos['segment_id'] ?? 0;
		$reader_offset  = $reader_pos['offset'] ?? 0;

		// Simple case: same segment.
		if ( $write_segment === $reader_segment ) {
			return \max( 0, $write_offset - $reader_offset );
		}

		// Different segments: calculate bytes remaining in reader's segment + bytes in between + bytes in write segment.
		$segment_diff = $write_segment - $reader_segment;
		if ( $segment_diff < 0 ) {
			// Reader is ahead of writer (shouldn't happen normally).
			return 0;
		}

		// Bytes remaining in reader's current segment.
		$remaining_in_reader_segment = $segment_size - $reader_offset;

		// Full segments between reader and writer.
		$full_segments = \max( 0, $segment_diff - 1 );

		// Bytes in write segment.
		$bytes_in_write_segment = $write_offset;

		return $remaining_in_reader_segment + ( $full_segments * $segment_size ) + $bytes_in_write_segment;
	}
}
