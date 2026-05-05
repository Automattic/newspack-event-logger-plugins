<?php
/**
 * Remote Manager
 *
 * Job handler that fans out actions to remote servers.
 * Registered via the newspack_event_logger_job_handlers filter.
 *
 * Core actions:
 * - sync_setting: POST to remote /event-logger/v1/settings
 * - health_check: GET from remote /event-logger/v1/discovery
 *
 * Plugins can register additional actions via the 'newspack_event_aggregator_remote_actions' filter.
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator;

use Newspack_Event_Logger\Config;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remote Manager class.
 */
class RemoteManager {

	/**
	 * Maximum number of servers to process in a single job.
	 * Prevents unbounded loops.
	 *
	 * @var int
	 */
	private const MAX_SERVERS = 100;

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	private const REQUEST_TIMEOUT = 15;

	/**
	 * Job staleness threshold in seconds.
	 * Must exceed health check interval (300s) to avoid race-to-drop under cron latency.
	 *
	 * @var int
	 */
	private const STALE_THRESHOLD = 600;

	/**
	 * Maximum number of settings to sync in a single pass.
	 *
	 * @var int
	 */
	private const MAX_SETTINGS = 50;

	/**
	 * Allowed endpoint prefixes for outbound requests.
	 * Only endpoints starting with one of these prefixes are permitted.
	 *
	 * @var string[]
	 */
	private const ALLOWED_ENDPOINT_PREFIXES = [
		'/wp-json/event-logger/',
		'/wp-json/perf-logger/',
	];

	/**
	 * Initialize remote manager.
	 */
	public static function init(): void {
		\add_filter( 'newspack_event_logger_job_handlers', [ self::class, 'register_handler' ] );
	}

	/**
	 * Register the job handler.
	 *
	 * @param array $handlers Existing handlers.
	 * @return array Modified handlers.
	 */
	public static function register_handler( array $handlers ): array {
		$handlers['remote_manager'] = [ self::class, 'handle_job' ];
		return $handlers;
	}

	/**
	 * Handle a job.
	 *
	 * @param array $parameters Job parameters.
	 */
	public static function handle_job( array $parameters ): void {
		$action = $parameters['action'] ?? '';
		if ( ! \is_string( $action ) || '' === $action ) {
			return;
		}

		// Sanitize action for use in $_SERVER superglobals via begin_job_context().
		$safe_action = \preg_replace( '/[^a-zA-Z0-9_-]/', '', \substr( $action, 0, 128 ) );

		$orig_server = \Newspack_Event_Jobs\Cron\JobWorker::begin_job_context( 'remote_manager/' . $safe_action );

		try {
			// Core actions.
			switch ( $action ) {
				case 'sync_setting':
					$option   = $parameters['option'] ?? '';
					$value    = $parameters['value'] ?? null;
					$endpoint = $parameters['endpoint'] ?? '/wp-json/event-logger/v1/settings';
					if ( ! self::is_allowed_endpoint( $endpoint ) ) {
						$endpoint = '/wp-json/event-logger/v1/settings';
					}

					// Skip stale jobs (older than sync interval).
					$queued_at = $parameters['queued_at'] ?? 0;
					if ( $queued_at > 0 && ( \time() - $queued_at ) > self::STALE_THRESHOLD ) {
						static $last_stale_sync_log = 0;
						if ( \time() - $last_stale_sync_log >= 60 ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							\error_log( \sprintf( '[EventLogger] Stale %s job dropped (age=%ds)', $action, \time() - $queued_at ) );
							$last_stale_sync_log = \time();
						}
						return;
					}

					if ( \is_string( $option ) && '' !== $option ) {
						self::sync_setting( $option, $value, $endpoint );
					}
					return;

				case 'health_check':
					// Skip stale health check jobs.
					$queued_at = $parameters['queued_at'] ?? 0;
					if ( $queued_at > 0 && ( \time() - $queued_at ) > self::STALE_THRESHOLD ) {
						static $last_stale_health_log = 0;
						if ( \time() - $last_stale_health_log >= 60 ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							\error_log( \sprintf( '[EventLogger] Stale %s job dropped (age=%ds)', $action, \time() - $queued_at ) );
							$last_stale_health_log = \time();
						}
						return;
					}

					self::health_check();
					return;

				default:
					// Only dispatch to filter-registered handlers.
					// Core actions (sync_setting, health_check) are handled by switch cases above.
					$handlers = \apply_filters( 'newspack_event_aggregator_remote_actions', [] );
					if ( isset( $handlers[ $action ] ) && \is_callable( $handlers[ $action ] ) ) {
						\call_user_func( $handlers[ $action ], self::sanitize_handler_parameters( $parameters ) );
					} else {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						\error_log( \sprintf( '[EventLogger] Unknown remote action: %s', \sanitize_text_field( $action ) ) );
					}
					return;
			}
		} finally {
			\Newspack_Event_Jobs\Cron\JobWorker::end_job_context( $orig_server );
		}
	}

	/**
	 * Sync a setting to all remote servers.
	 *
	 * @param string     $option   Option name (already mapped by SettingsSync).
	 * @param mixed      $value    Option value.
	 * @param string     $endpoint REST endpoint to POST to.
	 * @param array|null $servers  Optional list of server IDs (null = all).
	 */
	public static function sync_setting( string $option, $value, string $endpoint = '/wp-json/event-logger/v1/settings', ?array $servers = null ): void {
		$registry    = ServerRegistry::get_instance();
		$all_servers = $registry->get_enabled();
		$server_ids  = $servers ?? \array_keys( $all_servers );

		$count = 0;
		foreach ( $server_ids as $server_id ) {
			if ( $count >= self::MAX_SERVERS ) {
				break;
			}

			if ( ! \is_string( $server_id ) ) {
				continue;
			}

			$server = $registry->get( $server_id );
			if ( null === $server || empty( $server['enabled'] ) ) {
				continue;
			}

			$response = self::post_to_server(
				$server,
				$endpoint,
				[
					'option' => $option,
					'value'  => $value,
				]
			);

			// Log sync failures for operational visibility.
			if ( \is_wp_error( $response ) ) {
				self::log_status( $server_id, 'sync_error', $response->get_error_message() );
			} else {
				$code = \wp_remote_retrieve_response_code( $response );
				if ( 200 !== $code ) {
					self::log_status( $server_id, 'sync_error', "HTTP {$code} syncing {$option}" );
				}
			}
			++$count;
		}
	}

	/**
	 * Run health check on all servers, collecting discovery data.
	 *
	 * Checks each server serially, collects discovery data, logs status,
	 * fires action with aggregated discovery data, then syncs all settings
	 * to ensure servers are up to date.
	 */
	public static function health_check(): void {
		$registry = ServerRegistry::get_instance();
		// Long-running JobWorker processes keep this singleton alive across
		// many job dispatches; reset so the post-add sync (and periodic ticks)
		// see freshly-added or re-enabled spokes without waiting for the
		// worker to hit max_runtime and respawn.
		$registry->reset_cache();
		$servers  = $registry->get_enabled();

		// Collect discovery data from all servers.
		$all_discovery = [];
		$count         = 0;

		foreach ( $servers as $server_id => $server ) {
			if ( $count >= self::MAX_SERVERS ) {
				break;
			}

			$data = self::check_server( $server_id, $server );
			if ( null !== $data ) {
				$all_discovery[ $server_id ] = $data;
			}

			++$count;
		}

		/**
		 * Action fired with aggregated discovery data from all servers.
		 *
		 * Plugins (like performance-aggregator) hook into this to merge
		 * discovered hooks/events into local settings.
		 *
		 * @param array $all_discovery Map of server_id => discovery data.
		 */
		\do_action( 'newspack_event_aggregator_health_check_discovery', $all_discovery );

		// Sync all settings to ensure servers are up to date.
		// This handles: staleness drops, new servers, general consistency.
		self::sync_all_settings();
	}

	/**
	 * Sync all registered settings to all servers (or a targeted subset).
	 *
	 * Uses the 'newspack_event_aggregator_synced_settings' filter to collect
	 * settings from all SettingsSync classes (event-aggregator and
	 * performance-aggregator).
	 *
	 * @param array|null $server_ids Optional list of server IDs to sync to (null = all enabled).
	 */
	public static function sync_all_settings( ?array $server_ids = null ): void {
		// Long-running workers (JobWorker, supervisor) cache the registry singleton
		// across many job dispatches; reset so newly-added or re-enabled spokes are
		// visible without waiting for max_runtime respawn.
		ServerRegistry::get_instance()->reset_cache();
		/**
		 * Filter to collect all settings that should be synced.
		 *
		 * Each entry should be:
		 * [
		 *     'local_option'  => 'event_logger_...',
		 *     'remote_option' => 'event_logger_...',
		 *     'endpoint'      => '/wp-json/.../settings',
		 * ]
		 *
		 * @param array $settings Array of setting definitions.
		 */
		$settings = \apply_filters( 'newspack_event_aggregator_synced_settings', [] );

		// Cap settings to prevent unbounded iteration from filter abuse.
		if ( \count( $settings ) > self::MAX_SETTINGS ) {
			$settings = \array_slice( $settings, 0, self::MAX_SETTINGS );
		}

		// Load full config once - always use Config, never get_option() directly.
		$config = Config::load_config( 'full' );

		foreach ( $settings as $setting ) {
			$local_option  = $setting['local_option'] ?? '';
			$remote_option = $setting['remote_option'] ?? $local_option;
			$endpoint      = $setting['endpoint'] ?? '/wp-json/event-logger/v1/settings';

			if ( '' === $local_option ) {
				continue;
			}

			// Validate endpoint from filter against allowed prefixes.
			if ( ! self::is_allowed_endpoint( $endpoint ) ) {
				continue;
			}

			// Convert option name to config key (strip event_logger_ prefix).
			$config_key = \str_replace( 'event_logger_', '', $local_option );
			if ( ! isset( $config[ $config_key ] ) ) {
				continue; // Option not in config.
			}

			self::sync_setting( $remote_option, $config[ $config_key ], $endpoint, $server_ids );
		}
	}

	/**
	 * Check a single server's health and discovery endpoint.
	 *
	 * @param string $server_id Server ID.
	 * @param array  $server    Server config.
	 * @return array|null Discovery data or null on error.
	 */
	private static function check_server( string $server_id, array $server ): ?array {
		$response = self::get_from_server( $server, '/wp-json/event-logger/v1/discovery' );

		if ( \is_wp_error( $response ) ) {
			self::log_status( $server_id, 'error', $response->get_error_message() );
			return null;
		}

		$code = \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			self::log_status( $server_id, 'error', "HTTP {$code}" );
			return null;
		}

		$body = \wp_remote_retrieve_body( $response );
		$data = \json_decode( $body, true, 16 );

		if ( ! \is_array( $data ) ) {
			self::log_status( $server_id, 'error', 'Invalid JSON response' );
			return null;
		}

		$lag = $data['lag'] ?? 0;
		self::log_status( $server_id, 'ok', null, (int) $lag );

		// Return only expected discovery fields -- don't pass raw remote JSON to action hooks.
		$validated = [];
		if ( isset( $data['registered_hooks'] ) && \is_array( $data['registered_hooks'] ) ) {
			$validated['registered_hooks'] = \array_slice( $data['registered_hooks'], 0, 500 );
		}
		if ( isset( $data['custom_events'] ) && \is_array( $data['custom_events'] ) ) {
			$validated['custom_events'] = \array_slice( $data['custom_events'], 0, 500 );
		}
		if ( isset( $data['lag'] ) ) {
			$validated['lag'] = (int) $data['lag'];
		}

		return $validated;
	}

	/**
	 * POST to a remote server.
	 *
	 * @param array  $server   Server config.
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body.
	 * @return array|\WP_Error Response or error.
	 */
	public static function post_to_server( array $server, string $endpoint, array $body ) {
		if ( ! self::is_allowed_endpoint( $endpoint ) ) {
			return new \WP_Error( 'disallowed_endpoint', 'Endpoint not in allowed prefixes: ' . \sanitize_text_field( $endpoint ) );
		}

		$url    = \rtrim( $server['url'] ?? '', '/' ) . $endpoint;
		$config = Config::load_config( 'full' );

		$request_args = [
			'headers'             => [ 'Content-Type' => 'application/json' ],
			'body'                => \wp_json_encode( $body ),
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Remote server sync needs reasonable timeout.
			'timeout'             => self::REQUEST_TIMEOUT,
			'sslverify'           => $config['aggregator_verify_ssl'] ?? true,
			'redirection'         => 0, // SSE/REST endpoints should not redirect.
			'limit_response_size' => 1048576, // 1MB max response.
		];

		// Add Basic Auth header for WordPress Application Passwords.
		$username = $server['auth_username'] ?? '';
		$password = $server['auth_password'] ?? '';
		if ( '' !== $username && '' !== $password ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$request_args['headers']['Authorization'] = 'Basic ' . \base64_encode( $username . ':' . $password );
		}

		// Use wp_remote_post to allow private/internal IPs.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Intentional: must allow private IPs.
		return \wp_remote_post( $url, $request_args );
	}

	/**
	 * GET from a remote server.
	 *
	 * @param array  $server   Server config.
	 * @param string $endpoint API endpoint.
	 * @return array|\WP_Error Response or error.
	 */
	public static function get_from_server( array $server, string $endpoint ) {
		if ( ! self::is_allowed_endpoint( $endpoint ) ) {
			return new \WP_Error( 'disallowed_endpoint', 'Endpoint not in allowed prefixes: ' . \sanitize_text_field( $endpoint ) );
		}

		$url    = \rtrim( $server['url'] ?? '', '/' ) . $endpoint;
		$config = Config::load_config( 'full' );

		$request_args = [
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Remote server sync needs reasonable timeout.
			'timeout'             => self::REQUEST_TIMEOUT,
			'sslverify'           => $config['aggregator_verify_ssl'] ?? true,
			'redirection'         => 0, // REST endpoints should not redirect.
			'limit_response_size' => 1048576, // 1MB max response.
		];

		// Add Basic Auth header for WordPress Application Passwords.
		$username = $server['auth_username'] ?? '';
		$password = $server['auth_password'] ?? '';
		if ( '' !== $username && '' !== $password ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$request_args['headers']['Authorization'] = 'Basic ' . \base64_encode( $username . ':' . $password );
		}

		// Use wp_remote_get to allow private/internal IPs.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Intentional: must allow private IPs.
		return \wp_remote_get( $url, $request_args );
	}

	/**
	 * Check if an endpoint is allowed based on ALLOWED_ENDPOINT_PREFIXES.
	 *
	 * @param string $endpoint Endpoint path to validate.
	 * @return bool True if the endpoint starts with an allowed prefix.
	 */
	private static function is_allowed_endpoint( string $endpoint ): bool {
		foreach ( self::ALLOWED_ENDPOINT_PREFIXES as $prefix ) {
			if ( 0 === \strpos( $endpoint, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitize parameters before passing to filter-registered handlers.
	 *
	 * Strips keys that could be used to bypass endpoint validation
	 * if handlers naively pass parameters to post_to_server/get_from_server.
	 *
	 * @param array $parameters Raw job parameters.
	 * @return array Sanitized parameters with only safe keys.
	 */
	private static function sanitize_handler_parameters( array $parameters ): array {
		$safe = [];
		foreach ( $parameters as $key => $value ) {
			if ( 'endpoint' === $key ) {
				// Only allow validated endpoints through.
				if ( \is_string( $value ) && self::is_allowed_endpoint( $value ) ) {
					$safe[ $key ] = $value;
				}
				continue;
			}
			$safe[ $key ] = $value;
		}
		return $safe;
	}

	/**
	 * Log server status.
	 *
	 * @param string      $server_id Server ID.
	 * @param string      $status    Status ('ok' or 'error').
	 * @param string|null $message   Error message.
	 * @param int         $lag       Lag in seconds.
	 */
	private static function log_status( string $server_id, string $status, ?string $message, int $lag = 0 ): void {
		// Use LogManager if performance-logger is active.
		if ( \class_exists( 'Newspack_Performance_Logger\LogManager' ) ) {
			$log_manager = \Newspack_Performance_Logger\LogManager::instance();
			$data        = [
				'm' => [
					'server' => $server_id,
					'status' => $status,
				],
			];

			if ( null !== $message ) {
				$data['m']['message'] = $message;
			}

			if ( $lag > 0 ) {
				$data['m']['lag'] = $lag;
			}

			$log_manager->message( 'remote_health', $data );
		}
	}
}
