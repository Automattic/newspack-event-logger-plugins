<?php
/**
 * Spawn Controller
 *
 * REST endpoint for spawning workers (used by Supervisor).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\REST;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Cron\LogReader;
use Newspack_Event_Logger\Cron\Supervisor;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for worker spawn endpoint.
 *
 * This minimal controller handles only the spawn endpoint used by Supervisor.
 * Dashboard functionality (status, restart) is in Dashboards plugin.
 */
class SpawnController extends \WP_REST_Controller {

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
	protected $rest_base = 'workers';

	/**
	 * Number of partitions.
	 *
	 * @var int
	 */
	protected $num_partitions;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$config               = Config::load_config();
		$this->num_partitions = (int) ( $config['num_partitions'] ?? 1 );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// POST /workers/spawn - Spawn a worker directly (internal use).
		\register_rest_route( $this->namespace, "/{$this->rest_base}/spawn", [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'spawn_worker' ],
			'permission_callback' => [ $this, 'spawn_permissions_check' ],
			'args'                => [
				'type'      => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => [ $this, 'validate_worker_type' ],
				],
				'partition' => [
					'required'          => true,
					'sanitize_callback' => function ( $v ) {
						return (int) $v;
					},
				],
				'nonce'     => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/**
	 * Validate worker type.
	 *
	 * @param string $type Worker type (reader name or standalone worker name).
	 * @return bool True if valid.
	 */
	public function validate_worker_type( string $type ): bool {
		if ( 'supervisor' === $type ) {
			return true;
		}

		// Check LogReader handlers first.
		$readers = LogReader::get_registered_readers();
		if ( isset( $readers[ $type ] ) ) {
			return true;
		}

		// Check standalone workers.
		$standalone = Supervisor::get_standalone_workers();
		return isset( $standalone[ $type ] );
	}

	/**
	 * Verify an internal spawn token.
	 *
	 * @param string $token Token to verify.
	 * @return bool True if valid.
	 */
	private function verify_spawn_token( string $token ): bool {
		// Check current and previous 10-second window (handles edge cases).
		return \hash_equals( Supervisor::generate_spawn_token( 0 ), $token ) ||
			\hash_equals( Supervisor::generate_spawn_token( -1 ), $token );
	}

	/**
	 * Check rate limit for write operations.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	protected function check_rate_limit() {
		$transient_key = 'event_logger_rate_limit_' . \get_current_user_id();
		$last_request  = \get_transient( $transient_key );

		if ( false !== $last_request ) {
			$elapsed = \time() - (int) $last_request;
			if ( $elapsed < 2 ) {
				return new \WP_Error(
					'rate_limited',
					\__( 'Too many requests. Please wait a moment.', 'newspack-event-logger' ),
					[ 'status' => 429 ]
				);
			}
		}

		\set_transient( $transient_key, \time(), 10 );
		return true;
	}

	/**
	 * Check spawn permissions via capability and nonce.
	 *
	 * For internal requests (valid HMAC token), allows spawning without user
	 * capability check. For external requests, requires manage_options + WP nonce.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 */
	public function spawn_permissions_check( $request ) {
		$nonce = $request->get_param( 'nonce' );

		// Internal spawn: valid HMAC token (10-second window).
		// HMAC token is the security mechanism - short-lived and cryptographically signed.
		// No localhost check: managed hosting may proxy internal requests through load balancers.
		// No rate limit: supervisor manages its own spawn rate limiting per worker.
		if ( ! empty( $nonce ) && $this->verify_spawn_token( $nonce ) ) {
			return true;
		}

		// External spawn: require capability + WordPress nonce.
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to spawn workers.', 'newspack-event-logger' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		if ( empty( $nonce ) || ! \wp_verify_nonce( $nonce, 'event_logger_spawn_worker' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'Invalid or missing security nonce.', 'newspack-event-logger' ),
				[ 'status' => 403 ]
			);
		}

		$rate_check = $this->check_rate_limit();
		if ( \is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return true;
	}

	/**
	 * Spawn a worker directly.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|\WP_REST_Response Response with spawn result.
	 */
	public function spawn_worker( $request ) {
		$type      = $request->get_param( 'type' );
		$partition = $request->get_param( 'partition' );

		if ( $partition < 0 || $partition >= $this->num_partitions ) {
			return new \WP_Error( 'invalid_partition', 'Invalid partition number', [ 'status' => 400 ] );
		}

		// Expose worker identity in $_SERVER for environment logging.
		$_SERVER['EVENT_LOGGER_WORKER_TYPE']      = $type;
		$_SERVER['EVENT_LOGGER_WORKER_PARTITION']  = (string) $partition;

		// Tag this request as a worker so RequestBuilder can exclude it from timing stats.
		// Must be logged explicitly because ensure_started() runs before this point.
		if ( \class_exists( \Newspack_Performance_Logger\LogManager::class ) ) {
			\Newspack_Performance_Logger\LogManager::instance()->message( 'worker_type', [ 'm' => $type ] );
		}

		// Supervisor self-respawn.
		if ( 'supervisor' === $type ) {
			( new Supervisor() )->run();
			return \rest_ensure_response( [
				'type'   => 'supervisor',
				'result' => [ 'status' => 'completed' ],
			] );
		}

		// Check LogReader handlers first.
		$readers = LogReader::get_registered_readers();
		if ( isset( $readers[ $type ] ) ) {
			$result = LogReader::cron_callback( $type, $partition );

			return \rest_ensure_response(
				[
					'type'      => $type,
					'partition' => $partition,
					'result'    => $this->sanitize_worker_result( $result, $partition ),
				]
			);
		}

		// Check standalone workers.
		$standalone = Supervisor::get_standalone_workers();
		if ( isset( $standalone[ $type ] ) ) {
			$result = $this->spawn_standalone_worker( $standalone[ $type ], $partition );

			return \rest_ensure_response(
				[
					'type'      => $type,
					'partition' => $partition,
					'result'    => $this->sanitize_worker_result( $result, $partition ),
				]
			);
		}

		return new \WP_Error( 'unknown_worker_type', 'Unknown worker type', [ 'status' => 400 ] );
	}

	/**
	 * Spawn a standalone worker.
	 *
	 * @param array $config    Worker config with 'class' and 'partitions' keys.
	 * @param int   $partition Partition number.
	 * @return array Result array with 'status' key.
	 */
	private function spawn_standalone_worker( array $config, int $partition ): array {
		$worker_class = $config['class'];

		// Validate partition for non-partitioned workers.
		if ( empty( $config['partitions'] ) && 0 !== $partition ) {
			return [
				'status' => 'error',
				'reason' => 'Worker is not partitioned, partition must be 0',
			];
		}

		try {
			// Instantiate and run the worker.
			// Standalone workers must accept partition as constructor argument.
			$worker = new $worker_class( $partition );

			if ( ! \method_exists( $worker, 'execute' ) ) {
				return [
					'status' => 'error',
					'reason' => 'Worker does not implement execute() method',
				];
			}

			return $worker->execute();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf(
				'[EventLogger] SpawnController: Standalone worker %s error: %s',
				$worker_class,
				\substr( \preg_replace( '/[\x00-\x1F\x7F]/', '', $e->getMessage() ), 0, 200 )
			) );

			return [
				'status' => 'error',
				'reason' => 'Worker execution failed',
			];
		}
	}

	/**
	 * Sanitize worker result to prevent leaking internal paths/stack traces.
	 *
	 * @param array $result    Worker result array.
	 * @param int   $partition Partition number.
	 * @return array Sanitized result.
	 */
	private function sanitize_worker_result( $result, int $partition ): array {
		$safe_result = [
			'status'    => $result['status'] ?? 'unknown',
			'partition' => $partition,
		];
		// Only include safe numeric fields.
		foreach ( [ 'entries_processed', 'requests_complete', 'requests_pending', 'flames_written', 'jobs_processed' ] as $key ) {
			if ( isset( $result[ $key ] ) && \is_numeric( $result[ $key ] ) ) {
				$safe_result[ $key ] = (int) $result[ $key ];
			}
		}
		return $safe_result;
	}
}
