<?php
/**
 * Hooks Controller
 *
 * Registered hooks with auto-categorization.
 * Uses on-demand $wp_filter inspection instead of runtime discovery.
 *
 * @package Newspack_Performance_Logger
 */

namespace Newspack_Performance_Logger\REST;

use Newspack_Performance_Logger\HookCategorizer;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for hook endpoints.
 */
class HooksController extends \WP_REST_Controller {

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
	protected $rest_base = 'performance';

	/**
	 * Rate limit: requests per minute per user.
	 */
	private const RATE_LIMIT_REQUESTS = 60;

	/**
	 * Rate limit window in seconds.
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /performance/registered-hooks - Get all registered hooks grouped by category.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/registered-hooks',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_registered_hooks' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
				],
			]
		);

		// GET /performance/hook-categories - Get all categories with colors.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/hook-categories',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_hook_categories' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
				],
			]
		);
	}

	/**
	 * Check if current user can read hooks.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function read_permissions_check() {
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
	 * Check rate limit for the current user.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	private function check_rate_limit() {
		$user_id = \get_current_user_id();
		if ( 0 === $user_id ) {
			// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
			$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			$identifier  = 'ip_' . \wp_hash( $remote_addr );
		} else {
			$identifier = 'user_' . $user_id;
		}

		$now           = \time();
		$window_start  = (int) \floor( $now / self::RATE_LIMIT_WINDOW ) * self::RATE_LIMIT_WINDOW;
		$transient_key = 'el_hooks_rate_' . $identifier . '_' . $window_start;

		$count = (int) \get_transient( $transient_key );

		if ( $count >= self::RATE_LIMIT_REQUESTS ) {
			$seconds_remaining = $window_start + self::RATE_LIMIT_WINDOW - $now;
			return new \WP_Error(
				'rate_limit_exceeded',
				\sprintf(
					/* translators: %d: number of seconds to wait */
					\__( 'Rate limit exceeded. Please wait %d seconds.', 'newspack-performance-logger' ),
					\max( 1, $seconds_remaining )
				),
				[ 'status' => 429 ]
			);
		}

		$expiry = $window_start + self::RATE_LIMIT_WINDOW + 10 - $now;
		\set_transient( $transient_key, $count + 1, \max( 1, $expiry ) );
		return true;
	}

	/**
	 * Get all registered hooks grouped by category.
	 *
	 * Inspects $wp_filter on-demand rather than tracking at runtime.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response with categorized hooks.
	 */
	public function get_registered_hooks( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$rate_check = $this->check_rate_limit();
		if ( \is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$hooks_by_category = HookCategorizer::get_registered_hooks_by_category();
		$categories        = HookCategorizer::get_categories();

		// Count total hooks.
		$total = 0;
		foreach ( $hooks_by_category as $hooks ) {
			$total += \count( $hooks );
		}

		return \rest_ensure_response(
			[
				'total_hooks'       => $total,
				'categories'        => $categories,
				'hooks_by_category' => $hooks_by_category,
			]
		);
	}

	/**
	 * Get all hook categories with their colors and patterns.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response with categories.
	 */
	public function get_hook_categories( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$rate_check = $this->check_rate_limit();
		if ( \is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return \rest_ensure_response(
			[
				'categories' => HookCategorizer::get_categories(),
				'config'     => HookCategorizer::get_merged_config(),
			]
		);
	}
}
