<?php
/**
 * Gyroscope Controller
 *
 * REST controller for real-time in-flight request streaming.
 * Extends SSEControllerBase from Event Logger core for SSE infrastructure.
 *
 * @package Newspack_Performance_Gyroscope
 */

namespace Newspack_Performance_Gyroscope\REST;

use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\FirehoseReader;
use Newspack_Performance_Gyroscope\InflightTracker;
use Newspack_Event_Logger\REST\SSEControllerBase;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gyroscope REST controller.
 *
 * Provides SSE endpoint for real-time in-flight request monitoring.
 */
class GyroscopeController extends SSEControllerBase {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'event-logger/v1';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'firehose';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// GET /firehose/gyroscope - Real-time in-flight request stream.
		\register_rest_route( $this->namespace, "/{$this->rest_base}/gyroscope", [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'stream' ],
			'permission_callback' => [ $this, 'stream_permissions_check' ],
			'args'                => [
				'interval' => [
					'default'           => 1000,
					'type'              => 'integer',
					'sanitize_callback' => function ( $v ) {
						return \max( 100, \min( 10000, \intval( $v ) ) );
					},
				],
			],
		] );
	}

	/**
	 * Stream in-flight requests via SSE.
	 *
	 * Multiplexes all partitions into a single SSE stream and tracks
	 * request state using InflightTracker.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|void WP_Error if rate limited.
	 */
	public function stream( $request ) {
		$result = $this->stream_run( $request );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}
		exit;
	}

	/**
	 * Run gyroscope stream setup, polling loop, and cleanup (without exit).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|void WP_Error if rate limited, void on normal completion.
	 */
	protected function stream_run( \WP_REST_Request $request ) {
		$digest_interval = $request->get_param( 'interval' );

		// Start SSE stream with slot management.
		$context = $this->start_sse_stream( [
			'num_partitions' => 0, // Will be set after config load.
			'interval'       => $digest_interval,
		] );

		if ( \is_wp_error( $context ) ) {
			return $context;
		}

		// Load config and initialize readers.
		$log_base       = $context['log_base'];
		$num_partitions = $context['num_partitions'];
		$tracker        = new InflightTracker();

		// Create readers for all partitions.
		$readers      = [];
		$file_handles = [];
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$firehose        = new Firehose( "{$log_base}/firehose.log", $p );
			$readers[ $p ]   = new FirehoseReader( $firehose );
			$readers[ $p ]->next_offset( 'end' ); // Tail mode.
			$file_handles[ $p ] = null;
		}

		// Send updated connected event with partition count.
		$this->send_sse_event( 'config', [
			'num_partitions' => $num_partitions,
			'interval'       => $digest_interval,
		] );

		$last_digest         = \microtime( true );
		$last_heartbeat      = \time();
		$digest_interval_sec = $digest_interval / 1000.0;

		while ( $this->should_continue_stream( $context ) ) {
			// Alternating reads across all partitions.
			$did_work      = false;
			$all_caught_up = true;

			foreach ( $readers as $p => $reader ) {
				$fh = $file_handles[ $p ];

				if ( $fh ) {
					$line = \fgets( $fh );
					$meta = \stream_get_meta_data( $fh );
					if ( ! empty( $meta['timed_out'] ) ) {
						continue;
					}
					if ( false !== $line ) {
						$reader->update_offset();
						$line  = \trim( $line );
						$entry = \json_decode( $line, true, 64 );
						if ( \is_array( $entry ) ) {
							$tracker->process( $entry );
							$did_work = true;
						}
					} else {
						$reader->mark_eof();
						$reader->update_offset();
						$file_handles[ $p ] = $reader->next_segment();
					}
				} else {
					$file_handles[ $p ] = $reader->open();
					if ( $file_handles[ $p ] ) {
						\stream_set_timeout( $file_handles[ $p ], 1 );
					}
				}

				// Track if any partition still has data.
				if ( $file_handles[ $p ] && ! $reader->is_caught_up() ) {
					$all_caught_up = false;
				}
			}

			// Send completed requests.
			$completed = $tracker->get_completed();
			if ( ! empty( $completed ) ) {
				$this->send_sse_event( 'complete_batch', $completed );
			}

			// Send in-flight digest at interval.
			$now = \microtime( true );
			if ( $now - $last_digest >= $digest_interval_sec ) {
				$active = $tracker->get_active();
				$this->send_sse_event( 'inflight', [
					'requests' => $active,
					'count'    => \count( $active ),
					'time'     => $now,
				] );
				$last_digest = $now;
			}

			// Sleep based on work done.
			if ( $all_caught_up && ! $did_work ) {
				$hb_now = \time();
				if ( $hb_now - $last_heartbeat >= self::HEARTBEAT_INTERVAL ) {
					$this->send_sse_event( 'heartbeat', [ 'ts' => $hb_now ] );
					$last_heartbeat = $hb_now;
				}
				$this->flush_if_needed();
				\usleep( 10000 ); // 10ms when caught up.
			} elseif ( ! $did_work ) {
				\usleep( 1000 ); // 1ms.
			}
		}

		// Cleanup.
		foreach ( $readers as $reader ) {
			$reader->close();
		}
		$this->end_sse_stream();
	}
}
