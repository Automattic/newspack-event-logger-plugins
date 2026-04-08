<?php
/**
 * Jobs Admin
 *
 * Admin settings for the Jobs plugin.
 *
 * @package Newspack_Event_Jobs
 */

namespace Newspack_Event_Jobs\Admin;

use Newspack_Event_Logger\Config;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class for Jobs module.
 */
class Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register Jobs-specific settings.
	 */
	public function register_settings() {
		\register_setting( 'event_logger_options_group', 'event_logger_enable_jobs', [ 'sanitize_callback' => 'absint' ] );

		\add_settings_section( 'event_logger_jobs_section', \__( 'Jobs', 'newspack-event-jobs' ), [ $this, 'section_callback' ], 'event_logger' );
		\add_settings_field( 'enable_jobs', \__( 'Enable Jobs', 'newspack-event-jobs' ), [ $this, 'enable_jobs_callback' ], 'event_logger', 'event_logger_jobs_section' );
	}

	public function section_callback() {
		echo '<p>' . \esc_html__( 'Background job processing (JobIntake and JobWorker).', 'newspack-event-jobs' ) . '</p>';
	}

	public function enable_jobs_callback() {
		$config  = Config::load_config();
		$enabled = \get_option( 'event_logger_enable_jobs', $config['enable_jobs'] ?? 1 );
		?>
		<input type="hidden" name="event_logger_enable_jobs" value="0" />
		<input type="checkbox" id="enable_jobs" name="event_logger_enable_jobs" value="1" <?php checked( 1, $enabled ); ?> />
		<label for="enable_jobs"><?php \esc_html_e( 'Enable JobIntake and JobWorker', 'newspack-event-jobs' ); ?></label>
		<?php
	}
}
