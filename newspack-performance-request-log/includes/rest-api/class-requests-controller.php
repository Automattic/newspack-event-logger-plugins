<?php
/**
 * Requests Controller
 *
 * SSE endpoint for streaming completed requests from requests.log.
 *
 * @package Newspack_Performance_Request_Log
 */

namespace Newspack_Performance_Request_Log\REST;

use Newspack_Event_Logger\REST\SSEControllerBase;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests REST controller.
 */
class RequestsController extends SSEControllerBase {

	/** @var string */
	protected $namespace = 'event-logger/v1';

	/** @var string */
	protected $rest_base = 'firehose';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		\register_rest_route( $this->namespace, "/{$this->rest_base}/requests", [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'stream' ],
			'permission_callback' => [ $this, 'stream_permissions_check' ],
			'args'                => [
				'interval'  => [
					'default'           => 1000,
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
	 * Transform a single requests.log line into a batch entry.
	 *
	 * @param string $line Trimmed log line.
	 * @param int    $p    Partition number.
	 * @return array|null Batch entry or null to skip.
	 */
	public static function transform_line( string $line, int $p ): ?array {
		$req = \json_decode( $line, true, 64 );
		if ( ! \is_array( $req ) || empty( $req['url'] ) ) {
			return null;
		}
		$url = $req['url'] ?? '';
		$ua  = $req['user_agent'] ?? '';
		return [
			'rid'          => $req['rid'] ?? '',
			'method'       => $req['request_method'] ?? 'GET',
			'url'          => \strlen( $url ) > 2000 ? \substr( $url, 0, 2000 ) . '...' : $url,
			'start_time'   => $req['timestamp'] ?? 0,
			'end_time'     => ( $req['timestamp'] ?? 0 ) + ( ( $req['duration_ms'] ?? 0 ) / 1000 ),
			'duration_ms'  => $req['duration_ms'] ?? 0,
			'status_code'  => $req['status_code'] ?? 0,
			'state'        => 'complete',
			'error_status' => $req['error_status'] ?? '-',
			'remote_addr'  => $req['remote_addr'] ?? '',
			'user_agent'   => \strlen( $ua ) > 500 ? \substr( $ua, 0, 500 ) . '...' : $ua,
		];
	}

	/**
	 * Stream completed requests via SSE.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|void
	 */
	public function stream( $request ) {
		return $this->stream_log(
			$request,
			[
				'log_file'        => 'requests.log',
				'event_name'      => 'complete_batch',
				'tail_bytes'      => 1048576,
				'batch_threshold' => 50,
			],
			[ static::class, 'transform_line' ]
		);
	}
}
