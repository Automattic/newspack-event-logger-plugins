<?php
/**
 * Firehose Controller
 *
 * REST controller for firehose infrastructure (status, heartbeat, available logs).
 * Streaming endpoints are in extension plugins (Gyroscope, Request Log, Raw Logs).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\REST;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Cron\LogReader;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\Memcached;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Firehose controller class.
 */
class FirehoseController extends \WP_REST_Controller {
	protected $namespace = 'event-logger/v1';
	protected $rest_base = 'firehose';

	/**
	 * Maximum concurrent SSE connections per user:IP.
	 *
	 * @var int
	 */
	private const MAX_SSE_SLOTS = 10;

	public function register_routes() {
		// GET /firehose/logs - Get available log files.
		\register_rest_route( $this->namespace, "/{$this->rest_base}/logs", [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_logs' ],
			'permission_callback' => [ $this, 'stream_permissions_check' ],
		] );

		// GET /firehose/status - Get status for a specific log.
		\register_rest_route( $this->namespace, "/{$this->rest_base}/status", [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_status' ],
			'permission_callback' => [ $this, 'stream_permissions_check' ],
			'args'                => [
				'log' => [
					'default'           => '',
					'type'              => 'string',
					'sanitize_callback' => [ $this, 'sanitize_log_param' ],
				],
			],
		] );

		// POST /firehose/heartbeat - Keep SSE slot alive.
		\register_rest_route( $this->namespace, "/{$this->rest_base}/heartbeat", [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'heartbeat' ],
			'permission_callback' => [ $this, 'stream_permissions_check' ],
			'args'                => [
				'slot' => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
				'aggregator' => [
					'required'          => false,
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				],
				'partition'  => [
					'required'          => false,
					'type'              => 'integer',
					'default'           => -1,
					'sanitize_callback' => function ( $value ) {
						return null === $value ? -1 : (int) $value;
					},
				],
			],
		] );
	}

	public function stream_permissions_check() {
		if ( ! \Newspack_Event_Logger\Admin\Admin::current_user_allowed() ) {
			return new \WP_Error( 'rest_forbidden', \__( 'You do not have permission to access this resource.', 'newspack-event-logger' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	/**
	 * Get available log files based on registered readers.
	 *
	 * Collects all unique input and output log names from registered readers.
	 *
	 * @return array<string, string> Map of log key => log filename.
	 */
	public static function get_available_logs(): array {
		$logs = [];

		// Get all registered readers.
		$readers = LogReader::get_registered_readers();

		// Collect all unique inputs and outputs.
		foreach ( $readers as $config ) {
			// Add inputs.
			if ( ! empty( $config['inputs'] ) && \is_array( $config['inputs'] ) ) {
				foreach ( $config['inputs'] as $input ) {
					if ( \is_string( $input ) && ! empty( $input ) ) {
						$key          = \str_replace( '.log', '', $input );
						$logs[ $key ] = $input;
					}
				}
			}

			// Add outputs.
			if ( ! empty( $config['outputs'] ) && \is_array( $config['outputs'] ) ) {
				foreach ( $config['outputs'] as $output ) {
					if ( \is_string( $output ) && ! empty( $output ) ) {
						$key          = \str_replace( '.log', '', $output );
						$logs[ $key ] = $output;
					}
				}
			}
		}

		// Sort by key for consistent ordering.
		\ksort( $logs );

		return $logs;
	}

	/**
	 * Get the first available log (used as default).
	 *
	 * @return string First available log filename, or empty string if none.
	 */
	public static function get_default_log(): string {
		$logs = self::get_available_logs();
		return \reset( $logs ) ?: '';
	}

	/**
	 * Validate log name parameter.
	 *
	 * @param string $log Log name (key without .log suffix).
	 * @return bool True if valid.
	 */
	public static function validate_log_name( $log ): bool {
		$allowed = self::get_available_logs();
		return isset( $allowed[ $log ] );
	}

	/**
	 * Sanitize log parameter.
	 *
	 * @param string $v Log parameter value.
	 * @return string Sanitized log filename, or empty string if no logs available.
	 */
	public function sanitize_log_param( $v ): string {
		$allowed = self::get_available_logs();

		// Empty input or no logs available - use first available.
		if ( empty( $v ) || empty( $allowed ) ) {
			return self::get_default_log();
		}

		// Check with and without .log suffix.
		$key = \str_replace( '.log', '', $v );

		return $allowed[ $key ] ?? self::get_default_log();
	}

	/**
	 * Get hashed IP for slot keys.
	 *
	 * @return string 8-char hash of client IP.
	 */
	public static function get_ip_hash(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- IP used only for cache key hashing, not displayed or stored.
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		return \substr( \md5( $ip ), 0, 8 );
	}

	/**
	 * Get available logs endpoint.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response with available logs.
	 */
	public function get_logs( $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$logs = self::get_available_logs();

		// Format for frontend: array of { key, label }.
		$result = [];
		foreach ( $logs as $key => $filename ) {
			$result[] = [
				'key'   => $key,
				'label' => $filename,
			];
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Get log status.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response with log status.
	 */
	public function get_status( $request ) {
		$log_file = $request->get_param( 'log' );

		if ( empty( $log_file ) ) {
			return new \WP_Error( 'no_logs', 'No logs available', [ 'status' => 404 ] );
		}

		$config         = Config::load_config();
		$log_base       = Config::get_logs_directory();
		$num_partitions = $config['num_partitions'] ?? 1;
		$partitions     = [];
		$total_size     = 0;
		$total_segments = 0;

		$log_key = \str_replace( '.log', '', $log_file );

		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$firehose       = new Firehose( "{$log_base}/{$log_file}", $p );
			$segments       = $firehose->get_segments();
			$partition_size = \array_sum( \array_column( $segments, 'size' ) );
			$current_pos    = $firehose->get_current_position();
			$partitions[ $p ] = [
				'segments'      => $segments,
				'segment_count' => \count( $segments ),
				'size'          => $partition_size,
				'current_pos'   => $current_pos,
			];
			$total_size     += $partition_size;
			$total_segments += \count( $segments );
		}

		return \rest_ensure_response( [
			'log_id'         => $log_key,
			'log_file'       => $log_file,
			'num_partitions' => $num_partitions,
			'partitions'     => $partitions,
			'total_segments' => $total_segments,
			'total_size'     => $total_size,
		] );
	}

	/**
	 * Handle heartbeat from browser or aggregator to keep SSE slot alive.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response with success/error status.
	 */
	public function heartbeat( $request ) {
		// Initialize memcached for slot management.
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );

		$user_id    = \get_current_user_id();
		$ip_hash    = self::get_ip_hash();
		$slot       = $request->get_param( 'slot' );
		$aggregator = $request->get_param( 'aggregator' );
		$partition  = (int) $request->get_param( 'partition' );
		$ttl        = $aggregator ? SSEControllerBase::SLOT_TTL_AGGREGATOR : SSEControllerBase::SLOT_TTL_BROWSER;

		// Browser heartbeats omit partition (default -1 = shared pool); aggregator
		// heartbeats include the partition to refresh the right per-partition slot.
		$success = Memcached::touch_sse_slot( $user_id, $ip_hash, $slot, $ttl, $partition );

		return \rest_ensure_response( [
			'success'   => $success,
			'slot'      => $slot,
			'error'     => $success ? null : 'slot_expired',
			'timestamp' => \time(),
		] );
	}
}
