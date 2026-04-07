<?php
/**
 * Dashboard Controller
 *
 * REST endpoints for the Performance Logger dashboard UI.
 * These endpoints allow the admin dashboard to discover and configure
 * which hooks and events to log.
 *
 * @package Newspack_Performance_Logger
 */

namespace Newspack_Performance_Logger\REST;

use Newspack_Event_Logger\Config;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for dashboard UI endpoints.
 */
class DashboardController extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'perf-logger/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /hooks/available - Return discovered hooks.
		\register_rest_route(
			$this->namespace,
			'/hooks/available',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_available_hooks' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
			]
		);

		// POST /hooks/configure - Configure which hooks to log.
		\register_rest_route(
			$this->namespace,
			'/hooks/configure',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'configure_hooks' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => [
						'hooks'         => [
							'type'              => 'array',
							'default'           => [],
							'sanitize_callback' => [ $this, 'sanitize_string_array' ],
						],
						'custom_events' => [
							'type'              => 'array',
							'default'           => [],
							'sanitize_callback' => [ $this, 'sanitize_string_array' ],
						],
					],
				],
			]
		);

		// GET /config - Get current configuration state.
		\register_rest_route(
			$this->namespace,
			'/config',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_config' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
			]
		);

		// POST /config - Update configuration.
		\register_rest_route(
			$this->namespace,
			'/config',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_config' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => [
						'log_events'                  => [
							'type'              => 'array',
							'sanitize_callback' => [ $this, 'sanitize_string_array' ],
						],
						'custom_events'               => [
							'type'              => 'array',
							'sanitize_callback' => [ $this, 'sanitize_string_array' ],
						],
						'log_urls'                    => [
							'type'              => 'array',
							'sanitize_callback' => [ $this, 'sanitize_string_array' ],
						],
						'skip_urls'                   => [
							'type'              => 'array',
							'sanitize_callback' => [ $this, 'sanitize_string_array' ],
						],
						'auto_disable_threshold'      => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'auto_protect_time_threshold' => [
							'type'              => 'number',
							'sanitize_callback' => 'floatval',
						],
						'significant_events'          => [
							'type'              => 'array',
							'sanitize_callback' => [ $this, 'sanitize_string_array' ],
						],
						'log_memory'                  => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						'flush_every_line'            => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				],
			]
		);
	}

	/**
	 * Check admin permissions.
	 *
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 */
	public function admin_permissions_check() {
		if ( ! \Newspack_Event_Logger\Admin\Admin::current_user_allowed() ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to access this resource.', 'newspack-performance-logger' ),
				[ 'status' => \rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Sanitize an array of strings.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return array Sanitized array of strings.
	 */
	public function sanitize_string_array( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$result = [];
		foreach ( $value as $k => $v ) {
			if ( \is_string( $v ) ) {
				$result[] = \sanitize_text_field( $v );
			} elseif ( \is_string( $k ) && \is_bool( $v ) ) {
				// Support associative array format: [ 'event_name' => true/false ].
				$result[ \sanitize_text_field( $k ) ] = $v;
			}
		}
		return $result;
	}

	/**
	 * Get available hooks that WordPress has executed.
	 *
	 * Uses the global $wp_actions and $wp_filter to discover hooks.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response with available hooks.
	 */
	public function get_available_hooks( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		global $wp_actions, $wp_filter;

		$hooks = [];

		// Get action hooks that have been fired.
		if ( \is_array( $wp_actions ) ) {
			foreach ( $wp_actions as $hook_name => $count ) {
				$hooks[ $hook_name ] = [
					'name'     => $hook_name,
					'category' => $this->categorize_hook( $hook_name ),
					'count'    => (int) $count,
				];
			}
		}

		// Also include filter hooks that have callbacks registered.
		if ( $wp_filter instanceof \WP_Hook || \is_array( $wp_filter ) ) {
			foreach ( $wp_filter as $hook_name => $callbacks ) {
				if ( ! isset( $hooks[ $hook_name ] ) ) {
					$hooks[ $hook_name ] = [
						'name'     => $hook_name,
						'category' => $this->categorize_hook( $hook_name ),
						'count'    => 0,
					];
				}
			}
		}

		// Filter out custom event names — they're handled by the custom events system.
		$config        = Config::load_config( 'full' );
		$custom_events = $config['custom_events'] ?? [];
		if ( \is_array( $custom_events ) ) {
			foreach ( $custom_events as $key => $value ) {
				$name = ( \is_string( $key ) && '' !== $key && ! \is_numeric( $key ) ) ? $key : $value;
				unset( $hooks[ $name ] );
			}
		}

		// Sort hooks alphabetically by name.
		\ksort( $hooks );

		return \rest_ensure_response( [
			'hooks' => \array_values( $hooks ),
		] );
	}

	/**
	 * Categorize a hook based on its name.
	 *
	 * @param string $hook_name Hook name.
	 * @return string Category name.
	 */
	private function categorize_hook( string $hook_name ): string {
		// Use HookCategorizer if available.
		if ( \class_exists( 'Newspack_Performance_Logger\\HookCategorizer' ) ) {
			return \Newspack_Performance_Logger\HookCategorizer::categorize( $hook_name );
		}

		// Fallback categorization.
		$prefixes = [
			'admin_'     => 'admin',
			'wp_ajax_'   => 'ajax',
			'rest_'      => 'rest',
			'the_'       => 'template',
			'template_'  => 'template',
			'loop_'      => 'loop',
			'save_post'  => 'post',
			'post_'      => 'post',
			'comment_'   => 'comment',
			'user_'      => 'user',
			'login_'     => 'auth',
			'auth_'      => 'auth',
			'wp_'        => 'core',
			'init'       => 'core',
			'shutdown'   => 'core',
			'plugins_'   => 'plugin',
			'plugin_'    => 'plugin',
			'theme_'     => 'theme',
			'widgets_'   => 'widget',
			'widget_'    => 'widget',
			'sidebar_'   => 'widget',
			'customize_' => 'customizer',
			'option_'    => 'option',
			'update_'    => 'update',
			'delete_'    => 'delete',
			'add_'       => 'add',
			'get_'       => 'get',
			'pre_'       => 'filter',
		];

		foreach ( $prefixes as $prefix => $category ) {
			if ( 0 === \strpos( $hook_name, $prefix ) ) {
				return $category;
			}
		}

		return 'other';
	}

	/**
	 * Configure which hooks to log.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response with configuration result.
	 */
	public function configure_hooks( $request ) {
		$hooks         = $request->get_param( 'hooks' );
		$custom_events = $request->get_param( 'custom_events' );
		$configured    = 0;

		// Update hooks (WordPress actions/filters to log).
		if ( ! empty( $hooks ) && \is_array( $hooks ) ) {
			$hooks_flat = [];
			foreach ( $hooks as $hook ) {
				if ( \is_string( $hook ) && ! empty( $hook ) ) {
					$hooks_flat[] = $hook;
				}
			}
			\update_option( 'event_logger_log_events', $hooks_flat, false );
			$configured += \count( $hooks_flat );
		}

		// Update custom events (e.g., Pyrobase events).
		if ( ! empty( $custom_events ) && \is_array( $custom_events ) ) {
			// Convert indexed array to associative array with true values.
			$events_assoc = [];
			foreach ( $custom_events as $event ) {
				if ( \is_string( $event ) && ! empty( $event ) ) {
					$events_assoc[ $event ] = true;
				}
			}
			\update_option( 'event_logger_custom_events', $events_assoc, false );
			$configured += \count( $events_assoc );
		}

		// Reset config cache so changes take effect.
		Config::reset();

		return \rest_ensure_response( [
			'success'          => true,
			'hooks_configured' => $configured,
		] );
	}

	/**
	 * Get current configuration state.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response with current configuration.
	 */
	public function get_config( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$config = Config::load_config( 'full' );

		// Extract the relevant configuration options.
		$response_config = [
			'log_events'                  => $config['log_events'] ?? [],
			'custom_events'               => $config['custom_events'] ?? [],
			'log_urls'                    => $config['log_urls'] ?? [],
			'skip_urls'                   => $config['skip_urls'] ?? [],
			'auto_disable_threshold'      => $config['auto_disable_threshold'] ?? 0,
			'auto_protect_time_threshold' => $config['auto_protect_time_threshold'] ?? 0.0,
			'significant_events'          => $config['significant_events'] ?? [],
			'log_memory'                  => ! empty( $config['log_memory'] ),
			'flush_every_line'            => ! empty( $config['flush_every_line'] ),
		];

		return \rest_ensure_response( [
			'config' => $response_config,
		] );
	}

	/**
	 * Update configuration.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response with updated fields.
	 */
	public function update_config( $request ) {
		$updated = [];

		// Map of param name to option name and type.
		$config_map = [
			'log_events'                  => [ 'option' => 'event_logger_log_events', 'type' => 'array_assoc' ],
			'custom_events'               => [ 'option' => 'event_logger_custom_events', 'type' => 'array_bool' ],
			'log_urls'                    => [ 'option' => 'event_logger_log_urls', 'type' => 'array_assoc' ],
			'skip_urls'                   => [ 'option' => 'event_logger_skip_urls', 'type' => 'array_assoc' ],
			'auto_disable_threshold'      => [ 'option' => 'event_logger_auto_disable_threshold', 'type' => 'int' ],
			'auto_protect_time_threshold' => [ 'option' => 'event_logger_auto_protect_time_threshold', 'type' => 'float' ],
			'significant_events'          => [ 'option' => 'event_logger_significant_events', 'type' => 'array_assoc' ],
			'log_memory'                  => [ 'option' => 'event_logger_log_memory', 'type' => 'bool' ],
			'flush_every_line'            => [ 'option' => 'event_logger_flush_every_line', 'type' => 'bool' ],
		];

		foreach ( $config_map as $param => $config ) {
			$value = $request->get_param( $param );
			if ( null === $value ) {
				continue;
			}

			$option_name = $config['option'];

			// Convert value based on type.
			switch ( $config['type'] ) {
				case 'array_assoc':
					// Flat indexed array of unique string values.
					if ( \is_array( $value ) ) {
						$flat = [];
						foreach ( $value as $k => $v ) {
							if ( \is_string( $v ) && '' !== $v ) {
								$flat[] = $v;
							} elseif ( \is_string( $k ) && '' !== $k ) {
								$flat[] = $k;
							}
						}
						$value = \array_values( \array_unique( $flat ) );
					}
					break;

				case 'array_bool':
					// Convert indexed array to associative array with true values.
					if ( \is_array( $value ) ) {
						$assoc = [];
						foreach ( $value as $k => $v ) {
							if ( \is_int( $k ) && \is_string( $v ) ) {
								$assoc[ $v ] = true;
							} elseif ( \is_string( $k ) ) {
								$assoc[ $k ] = (bool) $v;
							}
						}
						$value = $assoc;
					}
					break;

				case 'int':
					$value = (int) $value;
					break;

				case 'float':
					$value = (float) $value;
					break;

				case 'bool':
					$value = (bool) $value;
					break;
			}

			\update_option( $option_name, $value, false );
			$updated[] = $param;
		}

		// Reset config cache so changes take effect.
		if ( ! empty( $updated ) ) {
			Config::reset();
		}

		return \rest_ensure_response( [
			'success' => true,
			'updated' => $updated,
		] );
	}
}
