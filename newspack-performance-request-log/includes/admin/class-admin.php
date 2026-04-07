<?php
/**
 * Request Log Admin
 *
 * Admin script enqueuing for Request Log module.
 *
 * @package Newspack_Performance_Request_Log
 */

namespace Newspack_Performance_Request_Log\Admin;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class for Request Log module.
 */
class Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		// Only load on Request Log page.
		if ( ! \str_contains( $hook_suffix, 'newspack-event-logger-stream' ) ) {
			return;
		}

		$asset_file = PERFORMANCE_REQUEST_LOG_DIR . 'build/admin/index.asset.php';
		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		\wp_enqueue_script(
			'performance-request-log-admin',
			PERFORMANCE_REQUEST_LOG_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		\wp_localize_script(
			'performance-request-log-admin',
			'eventLoggerDashboards',
			[
				'restUrl' => \esc_url_raw( \rest_url() ),
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
			]
		);

		\wp_enqueue_style(
			'performance-request-log-admin',
			PERFORMANCE_REQUEST_LOG_URL . 'build/admin/index.css',
			[],
			$asset['version']
		);
	}
}
