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
		$dependents = self::collect_job_dependents();
		if ( ! empty( $dependents ) ) {
			?>
			<p class="description" style="color: #b32d2e;">
				<strong><?php \esc_html_e( 'Required by features active on this site:', 'newspack-event-jobs' ); ?></strong>
				<?php echo \esc_html( \implode( ', ', $dependents ) ); ?>.
				<?php \esc_html_e( 'Disabling jobs will silently break these flows — settings will not propagate, FlameBuilder auto-tuning will not apply, and queued jobs will accumulate in firehose unprocessed.', 'newspack-event-jobs' ); ?>
			</p>
			<?php
		}
	}

	/**
	 * Collect human-readable names of features that depend on the job pipeline.
	 *
	 * @return string[] Active dependents, empty if none configured.
	 */
	private static function collect_job_dependents(): array {
		$dependents = [];

		// Aggregator settings sync runs through JobIntake / health_check jobs.
		if ( \class_exists( '\\Newspack_Event_Aggregator\\ServerRegistry' ) ) {
			$servers = \Newspack_Event_Aggregator\ServerRegistry::get_instance()->get_all();
			if ( ! empty( $servers ) ) {
				$dependents[] = \__( 'Aggregator settings sync', 'newspack-event-jobs' );
			}
		}

		// Performance Aggregator hub-mode handlers.
		if ( \class_exists( '\\Newspack_Performance_Aggregator\\SettingsSync' ) ) {
			$dependents[] = \__( 'Performance Aggregator fan-out', 'newspack-event-jobs' );
		}

		// Performance Workers auto-tune writes go through JobIntake on hubs.
		if ( \class_exists( '\\Newspack_Performance_Workers\\Cron\\FlameBuilder' ) ) {
			$dependents[] = \__( 'FlameBuilder auto-tune', 'newspack-event-jobs' );
		}

		return $dependents;
	}
}
