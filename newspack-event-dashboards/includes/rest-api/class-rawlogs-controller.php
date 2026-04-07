<?php
/**
 * Raw Logs Controller
 *
 * REST controller for generic raw log streaming.
 * Extends SSEControllerBase from Event Logger core for SSE infrastructure.
 *
 * @package Newspack_Event_Dashboards
 */

namespace Newspack_Event_Dashboards\REST;

use Newspack_Event_Logger\REST\FirehoseController;
use Newspack_Event_Logger\REST\SSEControllerBase;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raw Logs REST controller.
 *
 * Provides SSE endpoint for streaming any firehose log file.
 * Uses Core's FirehoseController for log validation (available via /firehose/logs).
 */
class RawlogsController extends SSEControllerBase {

	/** @var string */
	protected $namespace = 'event-logger/v1';

	/** @var string */
	protected $rest_base = 'firehose';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		\register_rest_route( $this->namespace, "/{$this->rest_base}/rawlogs", [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'stream' ],
			'permission_callback' => [ $this, 'stream_permissions_check' ],
			'args'                => [
				'log'       => [
					'default'           => '',
					'type'              => 'string',
					'sanitize_callback' => [ $this, 'sanitize_log_param' ],
				],
				'interval'  => [
					'default'           => 100,
					'type'              => 'integer',
					'sanitize_callback' => function ( $v ) {
						return \max( 100, \min( 10000, \intval( $v ) ) );
					},
				],
				'positions' => [
					'default'           => '',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/**
	 * Sanitize log parameter.
	 *
	 * @param string $v Log parameter value.
	 * @return string Sanitized log filename, or empty string if no logs available.
	 */
	public function sanitize_log_param( $v ): string {
		$allowed = FirehoseController::get_available_logs();

		if ( empty( $v ) || empty( $allowed ) ) {
			return FirehoseController::get_default_log();
		}

		$key = \str_replace( '.log', '', $v );

		return $allowed[ $key ] ?? FirehoseController::get_default_log();
	}

	/**
	 * Stream raw log entries via SSE.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|void
	 */
	public function stream( $request ) {
		$log_file = $request->get_param( 'log' );

		return $this->stream_log(
			$request,
			[
				'log_file'        => $log_file,
				'event_name'      => 'lines',
				'tail_bytes'      => 1048576,
				'batch_threshold' => 10,
				'config_extras'   => [ 'log' => $log_file ],
			],
			function ( string $line, int $p ): ?array {
				if ( '' === $line ) {
					return null;
				}
				if ( \strlen( $line ) > 1000 ) {
					$line = \substr( $line, 0, 1000 ) . '...';
				}
				return [ 'p' => $p, 'line' => $line ];
			}
		);
	}
}
