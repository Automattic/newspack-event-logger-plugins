<?php
/**
 * Event Logger Admin
 *
 * Admin pages and settings.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Admin;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Cron\LogReader;
use Newspack_Event_Logger\Lock;
use Newspack_Event_Logger\Memcached;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 */
class Admin {

	/**
	 * Check if current user is allowed to access Event Logger.
	 *
	 * Checks against allowed_users in config. Empty array = allow all admins.
	 *
	 * @return bool True if user is allowed.
	 */
	public static function current_user_allowed(): bool {
		// Always require manage_options as baseline (prevents demoted users in allowed_users from retaining access).
		if ( ! \current_user_can( 'manage_options' ) ) {
			return false;
		}

		$config        = Config::load_config( 'full' );
		$allowed_users = $config['allowed_users'] ?? [];

		// Empty array = allow all users with manage_options.
		if ( empty( $allowed_users ) ) {
			return true;
		}

		$current_user = \wp_get_current_user();
		return $current_user && \in_array( $current_user->user_login, $allowed_users, true );
	}

	/**
	 * Core plugin options (for reset).
	 *
	 * @var array
	 */
	private static array $option_names = [
		'event_logger_enable_logging',
		'event_logger_base_directory',
		'event_logger_num_partitions',
		'event_logger_num_segments',
		'event_logger_segment_size',
		'event_logger_max_lifespan',
		'event_logger_memcache_servers',
	];

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
		\add_action( 'admin_post_event_logger_reset_settings', [ $this, 'handle_reset_settings' ] );

		// Request worker restart when any event_logger option changes.
		\add_action( 'updated_option', [ $this, 'maybe_request_worker_restart' ], 10, 1 );
		\add_action( 'added_option', [ $this, 'maybe_request_worker_restart' ], 10, 1 );
	}

	/**
	 * Request worker restart when an event_logger option is updated.
	 *
	 * Workers will restart at their next graceful restart point (end of segment).
	 * Only restarts workers that are affected by the changed option.
	 *
	 * @param string $option Option name.
	 */
	public function maybe_request_worker_restart( string $option ): void {
		if ( ! \str_starts_with( $option, 'event_logger_' ) ) {
			return;
		}

		// Reset local config cache so current process sees new values.
		Config::reset();

		// Options that don't affect workers (runtime-only, checked per-request).
		$no_impact_options = [
			'event_logger_log_urls',
			'event_logger_skip_urls',
		];
		if ( \in_array( $option, $no_impact_options, true ) ) {
			return;
		}

		// Options handled by supervisor (it refreshes config each loop).
		$supervisor_only_options = [
			'event_logger_enable_logging',
			'event_logger_num_partitions',
		];
		if ( \in_array( $option, $supervisor_only_options, true ) ) {
			return;
		}

		// Options that require restarting all workers.
		$all_workers_options = [
			'event_logger_base_directory',
			'event_logger_num_segments',
			'event_logger_segment_size',
			'event_logger_max_lifespan',
		];

		// Options that only require restarting specific workers.
		// request-workers: memcache and auto-disable thresholds.
		// Note: significant_events is refreshed periodically by FlameBuilder, no restart needed.
		$request_workers_options = [
			'event_logger_memcache_servers',
			'event_logger_auto_disable_threshold',
			'event_logger_auto_protect_time_threshold',
			'event_logger_stats_salt',
		];

		// job-workers: log events and custom events affect job handler registration.
		$job_workers_options = [
			'event_logger_log_events',
			'event_logger_custom_events',
			'event_logger_significant_events',
			'event_logger_log_memory',
			'event_logger_flush_every_line',
		];

		// Build list of workers to restart.
		$readers            = LogReader::get_registered_readers();
		$workers_to_restart = [];

		if ( \in_array( $option, $all_workers_options, true ) ) {
			$workers_to_restart = \array_keys( $readers );
		} elseif ( \in_array( $option, $request_workers_options, true ) ) {
			$workers_to_restart = [ 'request-workers' ];
		} elseif ( \in_array( $option, $job_workers_options, true ) ) {
			$workers_to_restart = [ 'job-workers' ];
		}

		// If no workers to restart, skip.
		if ( empty( $workers_to_restart ) ) {
			return;
		}

		$config         = Config::load_config( 'full' );
		$locks_dir      = Config::get_locks_directory();
		$num_partitions = $config['num_partitions'] ?? 1;

		// Request restart for affected workers across all partitions.
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			foreach ( $workers_to_restart as $worker ) {
				$lock_dir = "{$locks_dir}/{$worker}.p{$p}.lock.d";
				Lock::request_restart( $lock_dir );
			}
		}
	}

	public function add_admin_menu() {
		// Only show Event Logger to the allowed user.
		if ( ! self::current_user_allowed() ) {
			return;
		}

		// Collect pages to show from external plugins.
		// The Performance Dashboards plugin registers the main dashboard page.
		$pages = [];

		// Allow external plugins to register admin pages.
		$external_pages = \apply_filters( 'newspack_event_logger_admin_pages', [] );
		foreach ( $external_pages as $page ) {
			if ( empty( $page['slug'] ) || empty( $page['title'] ) || empty( $page['callback'] ) ) {
				continue;
			}
			$pages[] = $page;
		}

		// Sort pages by position (default 100 for unpositioned pages).
		\usort( $pages, fn( $a, $b ) => ( $a['position'] ?? 100 ) <=> ( $b['position'] ?? 100 ) );

		// Only create menu if there are pages to show.
		if ( ! empty( $pages ) ) {
			// First page becomes the menu landing page.
			$first_page = $pages[0];
			\add_menu_page(
				\__( 'Event Logger', 'newspack-event-logger' ),
				\__( 'Event Logger', 'newspack-event-logger' ),
				'manage_options',
				$first_page['slug'],
				$first_page['callback'],
				'dashicons-chart-line',
				80
			);

			// Add all pages as submenus.
			foreach ( $pages as $page ) {
				\add_submenu_page(
					$first_page['slug'],
					$page['title'],
					$page['menu_title'] ?? $page['title'],
					'manage_options',
					$page['slug'],
					$page['callback']
				);
			}
		}

		// Settings page always available (in Settings menu).
		\add_options_page( \__( 'Event Logger Settings', 'newspack-event-logger' ), \__( 'Event Logger', 'newspack-event-logger' ), 'manage_options', 'newspack-event-logger-settings', [ $this, 'render_settings_page' ] );
	}

	public function render_settings_page() {
		?>
		<div class="wrap event-logger-settings-wrap">
			<h1><?php \esc_html_e( 'Event Logger Settings', 'newspack-event-logger' ); ?></h1>
			<form method="post" action="options.php">
				<?php \settings_fields( 'event_logger_options_group' ); \do_settings_sections( 'event_logger' ); ?>
				<p class="submit">
					<?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
					<span style="display:inline-block; margin-left: 10px;">
						<input type="button" class="button button-secondary" value="<?php \esc_attr_e( 'Reset to Defaults', 'newspack-event-logger' ); ?>"
						onclick="if(confirm('<?php echo \esc_js( \__( 'Are you sure you want to reset all settings to defaults? This cannot be undone.', 'newspack-event-logger' ) ); ?>')) { document.getElementById('reset-form').submit(); }" />
					</span>
				</p>
			</form>
			<form id="reset-form" method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
				<input type="hidden" name="action" value="event_logger_reset_settings">
				<?php \wp_nonce_field( 'event_logger_reset_settings', 'event_logger_reset_nonce' ); ?>
			</form>
			<?php
			// Allow Performance module to add its maintenance section.
			\do_action( 'newspack_event_logger_settings_after_form' );
			?>
		</div>
		<?php
	}

	public function register_settings() {
		// Register core options.
		\register_setting( 'event_logger_options_group', 'event_logger_enable_logging', [ 'sanitize_callback' => 'absint' ] );
		\register_setting( 'event_logger_options_group', 'event_logger_base_directory', [ 'sanitize_callback' => function( $value ) {
			$value = \sanitize_text_field( $value );
			if ( \str_contains( $value, "\0" ) || \str_contains( $value, '..' ) ) {
				return '';
			}
			if ( empty( $value ) || '/' !== $value[0] ) {
				return '';
			}
			return \rtrim( $value, '/' );
		} ] );
		\register_setting( 'event_logger_options_group', 'event_logger_num_partitions', [ 'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ] ] );
		\register_setting( 'event_logger_options_group', 'event_logger_num_segments', [ 'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ] ] );
		\register_setting( 'event_logger_options_group', 'event_logger_segment_size', [ 'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ] ] );
		\register_setting( 'event_logger_options_group', 'event_logger_max_lifespan', [ 'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ] ] );
		\register_setting( 'event_logger_options_group', 'event_logger_memcache_servers', [ 'sanitize_callback' => [ $this, 'sanitize_memcache_servers' ], 'autoload' => false ] );

		// General section.
		\add_settings_section( 'event_logger_general_section', \__( 'General', 'newspack-event-logger' ), [ $this, 'general_section_callback' ], 'event_logger' );
		\add_settings_field( 'enable_logging', \__( 'Enable Logging', 'newspack-event-logger' ), [ $this, 'enable_logging_callback' ], 'event_logger', 'event_logger_general_section' );

		// Storage section.
		\add_settings_section( 'event_logger_storage_section', \__( 'Storage Settings', 'newspack-event-logger' ), [ $this, 'storage_section_callback' ], 'event_logger' );
		\add_settings_field( 'num_partitions', \__( 'Num Partitions', 'newspack-event-logger' ), [ $this, 'num_partitions_callback' ], 'event_logger', 'event_logger_storage_section' );
		\add_settings_field( 'num_segments', \__( 'Num Segments', 'newspack-event-logger' ), [ $this, 'num_segments_callback' ], 'event_logger', 'event_logger_storage_section' );
		\add_settings_field( 'segment_size', \__( 'Segment Size', 'newspack-event-logger' ), [ $this, 'segment_size_callback' ], 'event_logger', 'event_logger_storage_section' );
		\add_settings_field( 'max_lifespan', \__( 'Minimum Retention', 'newspack-event-logger' ), [ $this, 'max_lifespan_callback' ], 'event_logger', 'event_logger_storage_section' );
		\add_settings_field( 'total_storage', \__( 'Total Log Storage', 'newspack-event-logger' ), [ $this, 'total_storage_callback' ], 'event_logger', 'event_logger_storage_section' );
		\add_settings_field( 'base_directory', \__( 'Base Directory', 'newspack-event-logger' ), [ $this, 'base_directory_callback' ], 'event_logger', 'event_logger_storage_section' );
		\add_settings_field( 'memcache_servers', \__( 'Memcache Servers', 'newspack-event-logger' ), [ $this, 'memcache_servers_callback' ], 'event_logger', 'event_logger_storage_section' );
	}

	/**
	 * Sanitize integer option, preserving empty string for "use default".
	 *
	 * @param mixed $input Input value.
	 * @return string|int Empty string or sanitized integer.
	 */
	public function sanitize_int_or_empty( $input ) {
		if ( '' === $input || null === $input ) {
			return '';
		}
		return \absint( $input );
	}

	/**
	 * Sanitize memcache servers option (newline-separated host:port list).
	 *
	 * @param string $value Newline-separated server list.
	 * @return string Sanitized servers (one per line) or empty string if all invalid.
	 */
	public function sanitize_memcache_servers( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}

		$lines           = \explode( "\n", (string) $value );
		$sanitized_lines = [];

		foreach ( $lines as $line ) {
			$line = \trim( $line );
			if ( '' === $line ) {
				continue;
			}

			// Validate host:port format (allows underscores for Docker container names).
			if ( \preg_match( '/^[a-zA-Z0-9._\-]+:\d{1,5}$/', $line ) ) {
				$sanitized_lines[] = $line;
			}
		}

		return \implode( "\n", $sanitized_lines );
	}

	/**
	 * Memcache servers field callback.
	 */
	public function memcache_servers_callback() {
		$defaults        = Config::load_config_defaults();
		$default_servers = $defaults['memcache_servers'] ?? Memcached::DEFAULT_SERVERS;
		$default_text    = \implode( "\n", $default_servers );
		$value           = \get_option( 'event_logger_memcache_servers', '' );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<textarea id="memcache_servers" name="event_logger_memcache_servers" rows="3" class="regular-text code" placeholder="<?php echo \esc_attr( $default_text ); ?>"><?php echo \esc_textarea( $value ); ?></textarea>
				<p class="description"><?php \esc_html_e( 'Memcache servers (one per line, format: host:port). Used for stats aggregation and SSE.', 'newspack-event-logger' ); ?>
				<br><?php \esc_html_e( 'Default:', 'newspack-event-logger' ); ?> <?php echo \esc_html( \implode( ', ', $default_servers ) ); ?></p>
			</div>
			<button type="button" class="button button-secondary event-logger-reset-text" data-field="memcache_servers" data-default="" title="<?php \esc_attr_e( 'Reset to default', 'newspack-event-logger' ); ?>">↺</button>
		</div>
		<?php
	}

	public function general_section_callback() {
		echo '<p>' . \esc_html__( 'Enable or disable event logging.', 'newspack-event-logger' ) . '</p>';
	}

	public function storage_section_callback() {
		echo '<p>' . \esc_html__( 'Configure log storage and infrastructure settings.', 'newspack-event-logger' ) . '</p>';
	}

	public function enable_logging_callback() {
		$enabled = \get_option( 'event_logger_enable_logging', 1 );
		?>
		<input type="hidden" name="event_logger_enable_logging" value="0" />
		<input type="checkbox" id="enable_logging" name="event_logger_enable_logging" value="1" <?php checked( 1, $enabled ); ?> />
		<label for="enable_logging"><?php \esc_html_e( 'Enable event logging', 'newspack-event-logger' ); ?></label>
		<?php
	}

	private function render_directory_field( string $field, string $default, string $description ): void {
		$value = \get_option( 'event_logger_' . $field, '' );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<input type="text" id="<?php echo \esc_attr( $field ); ?>" name="event_logger_<?php echo \esc_attr( $field ); ?>" value="<?php echo \esc_attr( $value ); ?>" class="regular-text code" placeholder="<?php echo \esc_attr( $default ); ?>" />
				<p class="description"><?php echo \esc_html( $description ); ?> (default: <?php echo \esc_html( $default ); ?>)</p>
			</div>
			<button type="button" class="button button-secondary event-logger-reset-text" data-field="<?php echo \esc_attr( $field ); ?>" data-default="" title="<?php \esc_attr_e( 'Reset to default', 'newspack-event-logger' ); ?>">↺</button>
		</div>
		<?php
	}

	public function base_directory_callback() {
		$defaults = Config::load_config_defaults();
		$this->render_directory_field( 'base_directory', $defaults['base_directory'] ?? '/tmp/event-logger', \__( 'Base directory for logs, locks, and offsets.', 'newspack-event-logger' ) );
	}

	private function render_number_field( string $field, int $default, int $min, int $max, string $description ): void {
		$value = \get_option( 'event_logger_' . $field, '' );
		// Show empty (with placeholder) if not set or equals default.
		$display_value = ( '' === $value || (int) $value === $default ) ? '' : $value;
		$input_class   = $max > 999 ? 'regular-text' : 'small-text';
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<input type="number" id="<?php echo \esc_attr( $field ); ?>" name="event_logger_<?php echo \esc_attr( $field ); ?>" value="<?php echo \esc_attr( $display_value ); ?>" min="<?php echo \esc_attr( $min ); ?>" max="<?php echo \esc_attr( $max ); ?>" class="<?php echo \esc_attr( $input_class ); ?>" placeholder="<?php echo \esc_attr( $default ); ?>" />
				<p class="description"><?php echo \esc_html( $description ); ?></p>
			</div>
			<button type="button" class="button button-secondary event-logger-reset-number" data-field="<?php echo \esc_attr( $field ); ?>" title="<?php \esc_attr_e( 'Clear (use default)', 'newspack-event-logger' ); ?>">↺</button>
		</div>
		<?php
	}

	public function num_partitions_callback() {
		$defaults = Config::load_config_defaults();
		$this->render_number_field( 'num_partitions', $defaults['num_partitions'] ?? 1, 1, 16, \__( 'Number of log partitions for parallel processing.', 'newspack-event-logger' ) );
	}

	public function num_segments_callback() {
		$defaults = Config::load_config_defaults();
		$this->render_number_field( 'num_segments', $defaults['num_segments'] ?? 4, 2, 32, \__( 'Number of segments to retain per partition.', 'newspack-event-logger' ) );
	}

	public function max_lifespan_callback() {
		$defaults = Config::load_config_defaults();
		$this->render_number_field( 'max_lifespan', $defaults['max_lifespan'] ?? 86400, 0, 604800, \__( 'Minimum retention in seconds. 0 = disabled (pure count-based).', 'newspack-event-logger' ) );
	}

	public function segment_size_callback() {
		$defaults = Config::load_config_defaults();
		$this->render_number_field( 'segment_size', $defaults['segment_size'] ?? ( 64 * 1024 * 1024 ), 1048576, 536870912, \__( 'Maximum segment size in bytes.', 'newspack-event-logger' ) );
	}

	public function total_storage_callback() {
		$defaults       = Config::load_config_defaults();
		$segment_size   = \get_option( 'event_logger_segment_size', '' );
		$num_segments   = \get_option( 'event_logger_num_segments', '' );
		$num_partitions = \get_option( 'event_logger_num_partitions', '' );

		// Use config defaults for empty values.
		$segment_size   = '' === $segment_size ? ( $defaults['segment_size'] ?? ( 64 * 1024 * 1024 ) ) : (int) $segment_size;
		$num_segments   = '' === $num_segments ? ( $defaults['num_segments'] ?? 4 ) : (int) $num_segments;
		$num_partitions = '' === $num_partitions ? ( $defaults['num_partitions'] ?? 1 ) : (int) $num_partitions;

		// Plugins register their log count via filter.
		$num_logs    = \apply_filters( 'newspack_event_logger_num_logs', 0 );
		$total_bytes = $segment_size * $num_segments * $num_partitions * $num_logs;
		$total_mb    = \round( $total_bytes / ( 1024 * 1024 ) );
		$total_gb    = \round( $total_bytes / ( 1024 * 1024 * 1024 ), 2 );

		$segment_mb = \round( $segment_size / ( 1024 * 1024 ) );

		if ( $total_gb >= 1 ) {
			$display = \sprintf( '%s MB (%s GB)', \number_format( $total_mb ), \number_format( $total_gb, 2 ) );
		} else {
			$display = \sprintf( '%s MB', \number_format( $total_mb ) );
		}
		?>
		<div id="total_storage_display" style="font-weight: 500; font-size: 14px; padding: 8px 0;">
			<?php echo \esc_html( $display ); ?>
		</div>
		<p class="description">
		<?php
		\printf(
			/* translators: 1: segment size in MB, 2: number of segments, 3: number of partitions, 4: number of logs */
			\esc_html__( 'Calculated as: %1$s MB segment × %2$s segments × %3$s partitions × %4$s logs', 'newspack-event-logger' ),
			\esc_html( $segment_mb ),
			\esc_html( $num_segments ),
			\esc_html( $num_partitions ),
			\esc_html( $num_logs )
		);
		?>
		</p>
		<?php
	}

	public function handle_reset_settings() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['event_logger_reset_nonce'] ) || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['event_logger_reset_nonce'] ) ), 'event_logger_reset_settings' ) ) {
			\wp_die( 'Security check failed' );
		}
		if ( ! self::current_user_allowed() ) {
			\wp_die( 'Unauthorized' );
		}
		foreach ( self::$option_names as $option ) {
			\delete_option( $option );
		}
		\wp_safe_redirect( add_query_arg( [ 'page' => 'newspack-event-logger-settings', 'reset' => '1' ], \admin_url( 'options-general.php' ) ) );
		exit;
	}

}
