<?php
/**
 * Firehose Stream Controller
 *
 * REST controller for streaming raw firehose.log entries via SSE.
 * Provides real-time access to the raw event stream.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\REST;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\FirehoseReader;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Firehose stream controller class.
 */
class FirehoseStreamController extends SSEControllerBase {

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
	protected $rest_base = 'firehose/stream';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'stream' ],
				'permission_callback' => [ $this, 'stream_permissions_check' ],
				'args'                => [
					'partition'  => [
						'default'           => 0,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $v, $request, $key ) {
							$config         = Config::load_config();
							$num_partitions = $config['num_partitions'] ?? 1;
							return $v >= 0 && $v < $num_partitions;
						},
					],
					'segment_id' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'offset'     => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'aggregator' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);
	}

	/**
	 * Stream firehose entries via SSE.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|void WP_Error on failure, exits on success.
	 */
	public function stream( \WP_REST_Request $request ) {
		$partition   = $request->get_param( 'partition' );
		$segment_id  = $request->get_param( 'segment_id' );
		$offset      = $request->get_param( 'offset' );
		$aggregator  = $request->get_param( 'aggregator' );

		// Start SSE stream.
		$hostname = \gethostname() ?: 'unknown';
		$context  = $this->start_sse_stream(
			[
				'partition' => $partition,
				'log'       => 'firehose.log',
			],
			[ 'X-Server-Id' => $hostname ],
			$aggregator
		);

		if ( \is_wp_error( $context ) ) {
			return $context;
		}

		// Create firehose reader.
		$log_base      = $context['log_base'];
		$firehose_path = "{$log_base}/firehose.log";

		$firehose = new Firehose( $firehose_path, $partition );

		// Set starting position.
		if ( null !== $segment_id && null !== $offset ) {
			// Resume from explicit position (segment_id + offset).
			$reader = new FirehoseReader( $firehose, 'end' );
			$reader->next_offset( [
				'segment_id' => $segment_id,
				'offset'     => $offset,
			] );
		} elseif ( null !== $offset ) {
			// Legacy: offset in current segment.
			$reader = new FirehoseReader( $firehose, 'end' );
			$reader->next_offset( [
				'segment_id' => $reader->get_segment_id(),
				'offset'     => $offset,
			] );
		} else {
			// Start from end (tail mode).
			$reader = new FirehoseReader( $firehose, 'end' );
		}

		$last_heartbeat = \time();

		// Main streaming loop.
		while ( $this->should_continue_stream( $context ) ) {
			$fh = $reader->open();
			if ( ! $fh ) {
				// No segments yet - wait and retry.
				$now = \time();
				if ( $now - $last_heartbeat >= self::HEARTBEAT_INTERVAL ) {
					$this->send_sse_event( 'heartbeat', [
						'ts'        => $now,
						'partition' => $partition,
						'position'  => $reader->get_position(),
					] );
					$last_heartbeat = $now;
				}
				$this->flush_if_needed();
				\usleep( 100000 ); // 100ms.
				continue;
			}

			// Read available lines.
			$lines_read = 0;
			// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( ( $line = $reader->read_line() ) !== null ) {
				$trimmed = \trim( $line );
				if ( '' === $trimmed ) {
					continue;
				}

				// Decode to validate JSON and allow filtering.
				$entry = \json_decode( $trimmed, true, 64 );
				if ( ! \is_array( $entry ) ) {
					// Not valid JSON - skip.
					continue;
				}

				// Include position for accurate resume after reconnect.
				$entry['position'] = $reader->get_position();

				$this->send_sse_event( 'entry', $entry );
				++$lines_read;

				// Check connection periodically during high-volume reads.
				if ( 0 === $lines_read % 100 && connection_aborted() ) {
					break 2;
				}
			}

			// Check for next segment if caught up.
			if ( $reader->is_caught_up() ) {
				$now = \time();
				if ( $now - $last_heartbeat >= self::HEARTBEAT_INTERVAL ) {
					$this->send_sse_event( 'heartbeat', [
						'ts'        => $now,
						'partition' => $partition,
						'position'  => $reader->get_position(),
					] );
					$last_heartbeat = $now;
				}
				// Flush any buffered data before sleeping.
				$this->flush_if_needed();
				// Sleep briefly when caught up to avoid busy-waiting.
				\usleep( 100000 ); // 100ms.
			} else {
				// Move to next segment.
				$reader->next_segment();
			}
		}

		// Cleanup.
		$reader->close();
		$this->end_sse_stream();
		exit;
	}
}
