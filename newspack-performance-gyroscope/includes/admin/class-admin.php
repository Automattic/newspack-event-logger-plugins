<?php
/**
 * Gyroscope Admin
 *
 * Admin script enqueuing for Gyroscope module.
 *
 * @package Newspack_Performance_Gyroscope
 */

namespace Newspack_Performance_Gyroscope\Admin;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class for Gyroscope module.
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
		// Only load on Gyroscope page.
		if ( ! \str_contains( $hook_suffix, 'newspack-performance-gyroscope' ) ) {
			return;
		}

		$asset_file = PERFORMANCE_GYROSCOPE_DIR . 'build/admin/index.asset.php';
		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		\wp_enqueue_script(
			'performance-gyroscope-admin',
			PERFORMANCE_GYROSCOPE_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		\wp_localize_script(
			'performance-gyroscope-admin',
			'eventLoggerDashboards',
			[
				'restUrl' => \esc_url_raw( \rest_url() ),
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
			]
		);

		// Pass hook category colors to JavaScript.
		// HookCategorizer is available via newspack-performance-logger dependency.
		\wp_localize_script(
			'performance-gyroscope-admin',
			'eventLoggerHookCategories',
			\Newspack_Performance_Logger\HookCategorizer::get_base_config()
		);

		\wp_enqueue_style(
			'performance-gyroscope-admin',
			PERFORMANCE_GYROSCOPE_URL . 'build/admin/index.css',
			[],
			$asset['version']
		);
	}
}
