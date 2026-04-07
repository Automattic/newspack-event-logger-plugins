<?php
/**
 * Settings Controller
 *
 * REST controller for updating Event Logger settings remotely.
 * Used by aggregator plugins to sync settings to remote servers.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\REST;

use Newspack_Event_Logger\Config;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings controller class.
 */
class SettingsController extends \WP_REST_Controller {

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
	protected $rest_base = 'settings';

	/**
	 * Allowed options for remote updates (whitelist).
	 *
	 * Only these core Event Logger options can be updated via this endpoint.
	 * Excludes sensitive options like base_directory which could be security risks.
	 * Performance tuning options are handled by newspack-performance-logger.
	 *
	 * @var array<string, string> Option name => type for sanitization.
	 */
	private const ALLOWED_OPTIONS = [
		'event_logger_num_partitions' => 'int',
		'event_logger_num_segments'   => 'int',
		'event_logger_segment_size'   => 'int',
		'event_logger_max_lifespan'   => 'int',
	];

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'update_setting' ],
				'permission_callback' => [ $this, 'update_permissions_check' ],
				'args'                => [
					'option' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [ $this, 'validate_option_name' ],
					],
					'value'  => [
						'required' => true,
						// Value type depends on option, validated in callback.
					],
				],
			]
		);
	}

	/**
	 * Check if current user can update settings.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function update_permissions_check() {
		if ( ! \Newspack_Event_Logger\Admin\Admin::current_user_allowed() ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to update settings.', 'newspack-event-logger' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Validate that the option name is in the whitelist.
	 *
	 * @param string $option Option name.
	 * @return bool True if valid.
	 */
	public function validate_option_name( string $option ): bool {
		return isset( self::ALLOWED_OPTIONS[ $option ] );
	}

	/**
	 * Update a setting.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function update_setting( \WP_REST_Request $request ) {
		$option = $request->get_param( 'option' );
		$value  = $request->get_param( 'value' );
		$type   = self::ALLOWED_OPTIONS[ $option ];

		// Sanitize value based on option type.
		$sanitized = $this->sanitize_value( $value, $type, $option );
		if ( null === $sanitized ) {
			return new \WP_Error(
				'invalid_value',
				\__( 'Invalid value for this option type.', 'newspack-event-logger' ),
				[ 'status' => 400 ]
			);
		}

		// Update the option.
		$updated = \update_option( $option, $sanitized, false );

		// Reset config cache so changes take effect.
		Config::reset();

		return \rest_ensure_response( [
			'option'  => $option,
			'updated' => $updated,
		] );
	}

	/**
	 * Sanitize a value based on its type.
	 *
	 * @param mixed  $value The value to sanitize.
	 * @param string $type  The type of sanitization.
	 * @return mixed|null Sanitized value or null if invalid.
	 */
	private function sanitize_value( $value, string $type, string $option = '' ) {
		switch ( $type ) {
			case 'int':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$int_value = (int) $value;
				// max_lifespan=0 is valid (disables time-based retention).
				$min = 'event_logger_max_lifespan' === $option ? 0 : 1;
				if ( $int_value < $min || $int_value > 1073741824 ) { // Max 1GB.
					return null;
				}
				return $int_value;

			case 'float':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$float_value = (float) $value;
				// Sanity check for reasonable values.
				if ( $float_value < 0 || $float_value > 86400 ) { // Max 24 hours in seconds.
					return null;
				}
				return $float_value;

			case 'array':
				if ( ! \is_array( $value ) ) {
					return null;
				}
				// Recursively sanitize array values.
				return $this->sanitize_array( $value );

			default:
				return null;
		}
	}

	/**
	 * Recursively sanitize an array.
	 *
	 * @param array $arr   The array to sanitize.
	 * @param int   $depth Current recursion depth.
	 * @return array|null Sanitized array or null if too deep.
	 */
	private function sanitize_array( array $arr, int $depth = 0 ): ?array {
		// Prevent excessive recursion.
		if ( $depth > 5 ) {
			return null;
		}

		// Limit array size.
		if ( \count( $arr ) > 1000 ) {
			return null;
		}

		$result = [];
		foreach ( $arr as $key => $value ) {
			// Sanitize key.
			$safe_key = \is_int( $key ) ? $key : \sanitize_text_field( (string) $key );

			// Sanitize value based on type.
			if ( \is_string( $value ) ) {
				$result[ $safe_key ] = \sanitize_text_field( $value );
			} elseif ( \is_bool( $value ) ) {
				$result[ $safe_key ] = $value;
			} elseif ( \is_int( $value ) || \is_float( $value ) ) {
				$result[ $safe_key ] = $value;
			} elseif ( \is_array( $value ) ) {
				$nested = $this->sanitize_array( $value, $depth + 1 );
				if ( null === $nested ) {
					return null;
				}
				$result[ $safe_key ] = $nested;
			}
			// Skip null and other types.
		}

		return $result;
	}
}
