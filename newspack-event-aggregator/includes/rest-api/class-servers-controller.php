<?php
/**
 * Servers Controller
 *
 * REST API for managing remote servers.
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator\REST;

use Newspack_Event_Aggregator\ServerRegistry;
use Newspack_Event_Logger\Admin\Admin as EventLoggerAdmin;
use Newspack_Event_Logger\Config;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Servers Controller class.
 */
class ServersController extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'event-aggregator/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'servers';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// List all servers.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $this->get_create_args(),
				],
			]
		);

		// Single server operations.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]{1,64})',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $this->get_update_args(),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				],
			]
		);

		// Test connection.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]{1,64})/test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'test_connection' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);
	}

	/**
	 * Check permissions.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function permissions_check() {
		if ( ! EventLoggerAdmin::current_user_allowed() ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to access this resource.', 'newspack-event-aggregator' ),
				[ 'status' => \rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Get all servers.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ): \WP_REST_Response {
		$registry = ServerRegistry::get_instance();
		$servers  = $registry->get_all();

		// Mask auth credentials in response.
		$response = [];
		foreach ( $servers as $id => $config ) {
			$response[ $id ] = [
				'id'              => $id,
				'url'             => $config['url'],
				'enabled'         => $config['enabled'],
				'logs'            => $config['logs'],
				'has_credentials' => ! empty( $config['auth_username'] ) && ! empty( $config['auth_password'] ),
			];
		}

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Get a single server.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$id       = $request->get_param( 'id' );
		$registry = ServerRegistry::get_instance();
		$server   = $registry->get( $id );

		if ( null === $server ) {
			return new \WP_Error(
				'not_found',
				\__( 'Server not found.', 'newspack-event-aggregator' ),
				[ 'status' => 404 ]
			);
		}

		return new \WP_REST_Response(
			[
				'id'              => $id,
				'url'             => $server['url'],
				'enabled'         => $server['enabled'],
				'logs'            => $server['logs'],
				'has_credentials' => ! empty( $server['auth_username'] ) && ! empty( $server['auth_password'] ),
			],
			200
		);
	}

	/**
	 * Create a new server.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$id     = $request->get_param( 'id' );
		$config = [
			'url'           => $request->get_param( 'url' ),
			'auth_username' => $request->get_param( 'auth_username' ),
			'auth_password' => $request->get_param( 'auth_password' ),
			'enabled'       => $request->get_param( 'enabled' ),
			'logs'          => $request->get_param( 'logs' ),
		];

		// Validate ID.
		if ( ! ServerRegistry::is_valid_id( $id ) ) {
			return new \WP_Error(
				'invalid_id',
				\__( 'Invalid server ID format.', 'newspack-event-aggregator' ),
				[ 'status' => 400 ]
			);
		}

		$registry = ServerRegistry::get_instance();

		// Check if exists.
		if ( null !== $registry->get( $id ) ) {
			return new \WP_Error(
				'already_exists',
				\__( 'Server with this ID already exists.', 'newspack-event-aggregator' ),
				[ 'status' => 409 ]
			);
		}

		// Add server.
		if ( ! $registry->add( $id, $config ) ) {
			return new \WP_Error(
				'create_failed',
				\__( 'Failed to create server. Check URL format (must be HTTPS).', 'newspack-event-aggregator' ),
				[ 'status' => 400 ]
			);
		}

		// Request supervisor restart to pick up new server.
		if ( \class_exists( 'Newspack_Event_Logger\Cron\Supervisor' ) ) {
			\Newspack_Event_Logger\Cron\Supervisor::request_restart();
		}

		return new \WP_REST_Response(
			[
				'id'      => $id,
				'message' => \__( 'Server created successfully.', 'newspack-event-aggregator' ),
			],
			201
		);
	}

	/**
	 * Update a server.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$id       = $request->get_param( 'id' );
		$registry = ServerRegistry::get_instance();

		if ( null === $registry->get( $id ) ) {
			return new \WP_Error(
				'not_found',
				\__( 'Server not found.', 'newspack-event-aggregator' ),
				[ 'status' => 404 ]
			);
		}

		$config = [];

		// Only update provided fields.
		if ( $request->has_param( 'url' ) ) {
			$config['url'] = $request->get_param( 'url' );
		}
		if ( $request->has_param( 'auth_username' ) ) {
			$config['auth_username'] = $request->get_param( 'auth_username' );
		}
		if ( $request->has_param( 'auth_password' ) ) {
			$config['auth_password'] = $request->get_param( 'auth_password' );
		}
		if ( $request->has_param( 'enabled' ) ) {
			$config['enabled'] = $request->get_param( 'enabled' );
		}
		if ( $request->has_param( 'logs' ) ) {
			$config['logs'] = $request->get_param( 'logs' );
		}

		if ( ! $registry->update( $id, $config ) ) {
			return new \WP_Error(
				'update_failed',
				\__( 'Failed to update server.', 'newspack-event-aggregator' ),
				[ 'status' => 400 ]
			);
		}

		// Request supervisor restart to pick up config changes.
		if ( \class_exists( 'Newspack_Event_Logger\Cron\Supervisor' ) ) {
			\Newspack_Event_Logger\Cron\Supervisor::request_restart();
		}

		return new \WP_REST_Response(
			[
				'id'      => $id,
				'message' => \__( 'Server updated successfully.', 'newspack-event-aggregator' ),
			],
			200
		);
	}

	/**
	 * Delete a server.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$id       = $request->get_param( 'id' );
		$registry = ServerRegistry::get_instance();

		if ( null === $registry->get( $id ) ) {
			return new \WP_Error(
				'not_found',
				\__( 'Server not found.', 'newspack-event-aggregator' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! $registry->remove( $id ) ) {
			return new \WP_Error(
				'delete_failed',
				\__( 'Failed to delete server.', 'newspack-event-aggregator' ),
				[ 'status' => 500 ]
			);
		}

		// Request supervisor restart.
		if ( \class_exists( 'Newspack_Event_Logger\Cron\Supervisor' ) ) {
			\Newspack_Event_Logger\Cron\Supervisor::request_restart();
		}

		return new \WP_REST_Response(
			[
				'id'      => $id,
				'message' => \__( 'Server deleted successfully.', 'newspack-event-aggregator' ),
			],
			200
		);
	}

	/**
	 * Test connection to a server.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_connection( $request ) {
		$id       = $request->get_param( 'id' );
		$registry = ServerRegistry::get_instance();
		$server   = $registry->get( $id );

		if ( null === $server ) {
			return new \WP_Error(
				'not_found',
				\__( 'Server not found.', 'newspack-event-aggregator' ),
				[ 'status' => 404 ]
			);
		}

		// Test by hitting the discovery endpoint.
		// Use wp_remote_get instead of wp_safe_remote_get to allow private/internal IPs.
		// Aggregators legitimately need to connect to internal servers.
		$url        = $server['url'] . '/wp-json/event-logger/v1/discovery';
		$config     = Config::load_config( 'full' );
		$verify_ssl = $config['aggregator_verify_ssl'] ?? true;

		// Build request args with WordPress Application Password auth (Basic Auth).
		$request_args = [
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Connection test needs reasonable timeout.
			'timeout'             => 5,
			'sslverify'           => $verify_ssl,
			'redirection'         => 0,
			'limit_response_size' => 1048576, // 1MB max.
		];

		// Add Basic Auth header if credentials provided.
		$username = $server['auth_username'] ?? '';
		$password = $server['auth_password'] ?? '';
		if ( ! empty( $username ) && ! empty( $password ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$request_args['headers']['Authorization'] = 'Basic ' . \base64_encode( $username . ':' . $password );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Intentional: must allow private IPs.
		$response = \wp_remote_get( $url, $request_args );

		if ( \is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf( '[EventLogger] Connection test failed for %s: %s', $id, $response->get_error_message() ) );
			return new \WP_Error(
				'connection_failed',
				\__( 'Could not connect to server.', 'newspack-event-aggregator' ),
				[ 'status' => 502 ]
			);
		}

		$code = \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'connection_failed',
				\sprintf( \__( 'HTTP %d response.', 'newspack-event-aggregator' ), $code ),
				[ 'status' => 502 ]
			);
		}

		$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 16 );

		if ( ! \is_array( $body ) ) {
			return new \WP_Error(
				'invalid_response',
				\__( 'Server returned non-JSON response.', 'newspack-event-aggregator' ),
				[ 'status' => 502 ]
			);
		}

		// Whitelist expected discovery fields -- don't proxy arbitrary remote JSON.
		$safe_response = [];
		if ( isset( $body['registered_hooks'] ) && \is_array( $body['registered_hooks'] ) ) {
			$safe_response['registered_hooks'] = \array_values( \array_map( 'sanitize_text_field', \array_filter( $body['registered_hooks'], 'is_string' ) ) );
		}
		if ( isset( $body['custom_events'] ) && \is_array( $body['custom_events'] ) ) {
			$safe_response['custom_events'] = \array_values( \array_map( 'sanitize_text_field', \array_filter( $body['custom_events'], 'is_string' ) ) );
		}
		if ( isset( $body['lag'] ) ) {
			$safe_response['lag'] = (int) $body['lag'];
		}

		return new \WP_REST_Response(
			[
				'id'       => $id,
				'status'   => 'connected',
				'response' => $safe_response,
			],
			200
		);
	}

	/**
	 * Validate URL argument (for required URL fields).
	 *
	 * @param string          $value   URL value.
	 * @param \WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return true|\WP_Error
	 */
	public function validate_url( $value, $request, $param ) {
		if ( empty( $value ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				\__( 'URL is required.', 'newspack-event-aggregator' ),
				[ 'status' => 400 ]
			);
		}

		return $this->validate_url_format( $value );
	}

	/**
	 * Validate URL argument (for optional URL fields).
	 *
	 * @param string          $value   URL value.
	 * @param \WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return true|\WP_Error
	 */
	public function validate_url_optional( $value, $request, $param ) {
		// Skip validation if empty (optional field).
		if ( empty( $value ) ) {
			return true;
		}

		return $this->validate_url_format( $value );
	}

	/**
	 * Validate URL format.
	 *
	 * @param string $value URL value.
	 * @return true|\WP_Error
	 */
	private function validate_url_format( $value ) {
		if ( ! \is_string( $value ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				\__( 'URL must be a string.', 'newspack-event-aggregator' ),
				[ 'status' => 400 ]
			);
		}

		// Check for HTTPS.
		if ( 0 !== \strpos( $value, 'https://' ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				\__( 'URL must use HTTPS.', 'newspack-event-aggregator' ),
				[ 'status' => 400 ]
			);
		}

		// Validate URL format.
		$sanitized = \esc_url_raw( $value );
		if ( empty( $sanitized ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				\__( 'Invalid URL format.', 'newspack-event-aggregator' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Get arguments for create endpoint.
	 *
	 * @return array
	 */
	private function get_create_args(): array {
		return [
			'id'            => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'url'           => [
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => [ $this, 'validate_url' ],
				'sanitize_callback' => 'esc_url_raw',
			],
			'auth_username' => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'auth_password' => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_password' ],
			],
			'enabled'       => [
				'required' => false,
				'type'     => 'boolean',
				'default'  => true,
			],
			'logs'          => [
				'required'          => false,
				'type'              => 'array',
				'default'           => [ 'firehose.log' ],
				'items'             => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				],
				'validate_callback' => [ $this, 'validate_logs' ],
			],
		];
	}

	/**
	 * Get arguments for update endpoint.
	 *
	 * @return array
	 */
	private function get_update_args(): array {
		return [
			'url'           => [
				'required'          => false,
				'type'              => 'string',
				'validate_callback' => [ $this, 'validate_url_optional' ],
				'sanitize_callback' => 'esc_url_raw',
			],
			'auth_username' => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'auth_password' => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_password' ],
			],
			'enabled'       => [
				'required' => false,
				'type'     => 'boolean',
			],
			'logs'          => [
				'required'          => false,
				'type'              => 'array',
				'items'             => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_file_name',
				],
				'validate_callback' => [ $this, 'validate_logs' ],
			],
		];
	}

	/**
	 * Sanitize password parameter.
	 *
	 * Strips control characters and enforces max length.
	 *
	 * @param string $value Raw password value.
	 * @return string Sanitized password.
	 */
	public function sanitize_password( $value ): string {
		if ( ! \is_string( $value ) ) {
			return '';
		}
		$value = \preg_replace( '/[\x00-\x1f\x7f]/', '', $value );
		if ( \strlen( $value ) > 256 ) {
			$value = \substr( $value, 0, 256 );
		}
		return $value;
	}

	/**
	 * Validate logs parameter.
	 *
	 * @param array $logs Array of log names.
	 * @return bool True if valid.
	 */
	public function validate_logs( $logs ): bool {
		if ( ! \is_array( $logs ) ) {
			return false;
		}
		foreach ( $logs as $log ) {
			if ( ! \is_string( $log ) || 1 !== \preg_match( '/^[a-zA-Z0-9_.-]+\.log$/', $log ) ) {
				return false;
			}
		}
		return true;
	}
}
