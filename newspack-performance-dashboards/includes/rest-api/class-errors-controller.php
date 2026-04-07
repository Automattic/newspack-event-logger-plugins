<?php
/**
 * Errors Controller
 *
 * SSE endpoint for streaming errors.log entries.
 *
 * @package Newspack_Performance_Dashboards
 */

namespace Newspack_Performance_Dashboards\REST;

use Newspack_Event_Logger\REST\SSEControllerBase;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Errors REST controller.
 */
class ErrorsController extends SSEControllerBase {

	/** @var string */
	protected $namespace = 'event-logger/v1';

	/** @var string */
	protected $rest_base = 'firehose';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		\register_rest_route( $this->namespace, "/{$this->rest_base}/errors", [
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
	 * Stream error/warning entries via SSE.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|void
	 */
	public function stream( $request ) {
		return $this->stream_log(
			$request,
			[
				'log_file'        => 'errors.log',
				'event_name'      => 'errors',
				'tail_bytes'      => 50 * 1024,
				'batch_threshold' => 50,
			],
			function ( string $line, int $p ): ?array {
				$entry = \json_decode( $line, true, 64 );
				if ( ! \is_array( $entry ) || empty( $entry['rid'] ) ) {
					return null;
				}
				$m = $entry['m'] ?? '';
				if ( \is_string( $m ) && \strlen( $m ) > 1000 ) {
					$m = \substr( $m, 0, 1000 ) . '...';
				}
				return [
					'rid' => $entry['rid'],
					'ts'  => $entry['ts'] ?? 0,
					'k'   => $entry['k'] ?? '',
					'm'   => $m,
					'n'   => $entry['n'] ?? 0,
				];
			}
		);
	}
}
