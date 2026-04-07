<?php
/**
 * Dashboards Admin
 *
 * Admin script enqueuing for Dashboards module.
 *
 * @package Newspack_Event_Dashboards
 */

namespace Newspack_Event_Dashboards\Admin;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class for Dashboards module.
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
		// Load on Workers or Raw Logs pages.
		$is_workers  = \str_contains( $hook_suffix, 'newspack-event-logger-workers' );
		$is_rawlogs  = \str_contains( $hook_suffix, 'newspack-event-logger-rawlogs' );

		if ( ! $is_workers && ! $is_rawlogs ) {
			return;
		}

		$asset_file = EVENT_LOGGER_DASHBOARDS_DIR . 'build/admin/index.asset.php';
		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		\wp_enqueue_script(
			'event-logger-dashboards-admin',
			EVENT_LOGGER_DASHBOARDS_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		\wp_localize_script(
			'event-logger-dashboards-admin',
			'eventLoggerDashboards',
			[
				'restUrl'      => \esc_url_raw( \rest_url() ),
				'nonce'        => \wp_create_nonce( 'wp_rest' ),
				'restartNonce' => \wp_create_nonce( 'event_logger_restart_worker' ),
			]
		);

		\wp_enqueue_style(
			'event-logger-dashboards-admin',
			EVENT_LOGGER_DASHBOARDS_URL . 'build/admin/index.css',
			[],
			$asset['version']
		);
	}
}
