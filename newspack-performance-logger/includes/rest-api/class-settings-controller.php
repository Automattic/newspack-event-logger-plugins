<?php
/**
 * Settings Controller
 *
 * REST controller for updating Performance Logger settings remotely.
 * Used by aggregator plugins to sync performance tuning settings to remote servers.
 *
 * @package Newspack_Performance_Logger
 */

namespace Newspack_Performance_Logger\REST;

use Newspack_Event_Logger\Config;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings controller class.
 */
class SettingsController extends \WP_REST_Controller {

	/**
	 * Maximum discovered events to merge.
	 */
	private const MAX_EVENTS = 10000;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'perf-logger/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Allowed options for remote updates (whitelist).
	 *
	 * Only these performance tuning options can be updated via this endpoint.
	 * Core Event Logger options are handled by event-logger/v1/settings.
	 *
	 * @var array<string, string> Option name => type for sanitization.
	 */
	private const ALLOWED_OPTIONS = [
		'event_logger_log_urls'                    => 'array',
		'event_logger_skip_urls'                   => 'array',
		'event_logger_log_events'                  => 'array',
		'event_logger_custom_events'               => 'array',
		'event_logger_auto_disable_threshold'      => 'int',
		'event_logger_auto_protect_time_threshold' => 'float',
		'event_logger_significant_events'          => 'array',
		'event_logger_log_memory'                  => 'bool',
		'event_logger_flush_every_line'            => 'bool',
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
						'required'          => true,
						'sanitize_callback' => [ $this, 'sanitize_setting_value' ],
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
				\__( 'You do not have permission to update settings.', 'newspack-performance-logger' ),
				[ 'status' => \rest_authorization_required_code() ]
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
	 * Sanitize setting value at REST registration.
	 *
	 * Basic sanitization — full validation happens downstream per option type.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed Sanitized value.
	 */
	public function sanitize_setting_value( $value ) {
		if ( \is_string( $value ) ) {
			return \sanitize_text_field( $value );
		}
		if ( \is_array( $value ) ) {
			return \array_slice( $value, 0, 10000 );
		}
		return $value;
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
		$sanitized = $this->sanitize_value( $value, $type );
		if ( null === $sanitized ) {
			return new \WP_Error(
				'invalid_value',
				\__( 'Invalid value for this option type.', 'newspack-performance-logger' ),
				[ 'status' => 400 ]
			);
		}

		// Suppress SettingsSync fan-out to prevent sync loops when applying
		// a remotely-synced setting (spoke receiving from hub).
		$has_sync = \class_exists( 'Newspack_Performance_Aggregator\SettingsSync' );
		if ( $has_sync ) {
			\Newspack_Performance_Aggregator\SettingsSync::suppress_sync();
		}

		try {
			// Update the option.
			$updated = \update_option( $option, $sanitized, false );
		} finally {
			if ( $has_sync ) {
				\Newspack_Performance_Aggregator\SettingsSync::suppress_sync( false );
			}
		}

		// Reset config cache so changes take effect.
		Config::reset();

		return \rest_ensure_response(
			[
				'option'  => $option,
				'updated' => $updated,
			]
		);
	}

	/**
	 * Sanitize a value based on its type.
	 *
	 * @param mixed  $value The value to sanitize.
	 * @param string $type  The type of sanitization.
	 * @return mixed|null Sanitized value or null if invalid.
	 */
	private function sanitize_value( $value, string $type ) {
		switch ( $type ) {
			case 'int':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$int_value = (int) $value;
				// 0 is valid (e.g., auto_disable_threshold=0 means "disabled").
				if ( $int_value < 0 || $int_value > 1073741824 ) { // Max 1GB.
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

			case 'bool':
				return (bool) $value;

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

		// Limit array size (log_events can have 1000+ hooks from discovery).
		if ( \count( $arr ) > self::MAX_EVENTS ) {
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
