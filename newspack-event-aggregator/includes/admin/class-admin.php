<?php
/**
 * Event Aggregator Admin
 *
 * Admin pages and settings for managing remote servers.
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator\Admin;

use Newspack_Event_Aggregator\ServerRegistry;
use Newspack_Event_Logger\Admin\Admin as EventLoggerAdmin;
use Newspack_Event_Logger\Config;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 */
class Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		// Register page via Event Logger filter (not add_submenu_page).
		\add_filter( 'newspack_event_logger_admin_pages', [ $this, 'register_admin_pages' ] );
	}

	/**
	 * Register admin pages via Event Logger filter.
	 *
	 * @param array $pages Registered pages.
	 * @return array Modified pages.
	 */
	public function register_admin_pages( array $pages ): array {
		$pages[] = [
			'slug'       => 'newspack-event-aggregator-status',
			'title'      => \__( 'Aggregator Status', 'newspack-event-aggregator' ),
			'menu_title' => \__( 'Aggregator', 'newspack-event-aggregator' ),
			'callback'   => [ $this, 'render_status_page' ],
			'position'   => 50, // After Workers, Raw Logs.
		];
		return $pages;
	}

	/**
	 * Render the aggregator status page.
	 */
	public function render_status_page(): void {
		// Check permissions.
		if ( ! EventLoggerAdmin::current_user_allowed() ) {
			\wp_die( \esc_html__( 'You do not have sufficient permissions to access this page.', 'newspack-event-aggregator' ) );
		}

		echo '<div id="event-aggregator-status"></div>';
	}

	/**
	 * Register settings.
	 */
	public function register_settings(): void {
		$config = Config::load_config( 'full' );

		// Remote segment count (type=string because empty string means "use default").
		\register_setting(
			'event_logger_options_group',
			'event_logger_remote_num_segments',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_num_segments' ],
				'default'           => $config['remote_num_segments'] ?? 4,
			]
		);

		// Remote segment size (type=string because empty string means "use default").
		\register_setting(
			'event_logger_options_group',
			'event_logger_remote_segment_size',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_segment_size' ],
				'default'           => $config['remote_segment_size'] ?? 10485760,
			]
		);

		// Add section to Event Logger settings page.
		\add_settings_section(
			'event_logger_aggregator_servers_section',
			\__( 'Remote Servers', 'newspack-event-aggregator' ),
			[ $this, 'servers_section_callback' ],
			'event_logger'
		);

		// Add server management field.
		\add_settings_field(
			'event_logger_aggregator_servers',
			\__( 'Configured Servers', 'newspack-event-aggregator' ),
			[ $this, 'servers_field_callback' ],
			'event_logger',
			'event_logger_aggregator_servers_section'
		);

		// Remote segment settings section.
		\add_settings_section(
			'event_logger_aggregator_settings_section',
			\__( 'Remote Server Settings', 'newspack-event-aggregator' ),
			[ $this, 'settings_section_callback' ],
			'event_logger'
		);

		\add_settings_field(
			'remote_num_segments',
			\__( 'Remote Segment Count', 'newspack-event-aggregator' ),
			[ $this, 'num_segments_callback' ],
			'event_logger',
			'event_logger_aggregator_settings_section'
		);

		\add_settings_field(
			'remote_segment_size',
			\__( 'Remote Segment Size', 'newspack-event-aggregator' ),
			[ $this, 'segment_size_callback' ],
			'event_logger',
			'event_logger_aggregator_settings_section'
		);

		\register_setting(
			'event_logger_options_group',
			'event_logger_remote_max_lifespan',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_max_lifespan' ],
				'default'           => $config['remote_max_lifespan'] ?? 3600,
			]
		);

		\add_settings_field(
			'remote_max_lifespan',
			\__( 'Remote Min Retention', 'newspack-event-aggregator' ),
			[ $this, 'max_lifespan_callback' ],
			'event_logger',
			'event_logger_aggregator_settings_section'
		);
	}

	/**
	 * Sanitize number of segments.
	 *
	 * @param mixed $value Input value.
	 * @return int Sanitized value (2-16).
	 */
	public function sanitize_num_segments( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$value = \absint( $value );
		return \max( 2, \min( 16, $value ) );
	}

	/**
	 * Sanitize segment size.
	 *
	 * @param mixed $value Input value.
	 * @return int|string Sanitized value (1MB - 256MB) or empty string for default.
	 */
	public function sanitize_segment_size( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$value = \absint( $value );
		$min   = 1024 * 1024;        // 1MB.
		$max   = 256 * 1024 * 1024;  // 256MB.
		return \max( $min, \min( $max, $value ) );
	}

	/**
	 * Sanitize max lifespan.
	 *
	 * @param mixed $value Input value.
	 * @return int|string Sanitized value (60-604800) or empty string for default.
	 */
	public function sanitize_max_lifespan( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$value = \absint( $value );
		return \max( 60, \min( 604800, $value ) );
	}

	/**
	 * Settings section description.
	 */
	public function settings_section_callback(): void {
		echo '<p>' . \esc_html__(
			'Configure segment settings sent to remote servers (may differ from hub settings).',
			'newspack-event-aggregator'
		) . '</p>';
	}

	/**
	 * Number of segments field callback.
	 */
	public function num_segments_callback(): void {
		$this->render_number_field( 'remote_num_segments', Config::load_config_defaults()['remote_num_segments'] ?? 2, 2, 16, \__( 'Number of log segments on remote servers (2-16).', 'newspack-event-aggregator' ) );
	}

	/**
	 * Render a number field with reset button matching core admin style.
	 *
	 * @param string $field       Option name (without event_logger_ prefix).
	 * @param int    $default     Default value.
	 * @param int    $min         Minimum value.
	 * @param int    $max         Maximum value.
	 * @param string $description Field description.
	 */
	private function render_number_field( string $field, int $default, int $min, int $max, string $description ): void {
		$value         = \get_option( 'event_logger_' . $field, '' );
		$display_value = ( '' === $value || (int) $value === $default ) ? '' : $value;
		$input_class   = $max > 999 ? 'regular-text' : 'small-text';
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<input type="number" id="<?php echo \esc_attr( $field ); ?>" name="event_logger_<?php echo \esc_attr( $field ); ?>" value="<?php echo \esc_attr( $display_value ); ?>" min="<?php echo \esc_attr( $min ); ?>" max="<?php echo \esc_attr( $max ); ?>" class="<?php echo \esc_attr( $input_class ); ?>" placeholder="<?php echo \esc_attr( $default ); ?>" />
				<p class="description"><?php echo \esc_html( $description ); ?></p>
			</div>
			<button type="button" class="button button-secondary event-logger-reset-number" data-field="<?php echo \esc_attr( $field ); ?>" title="<?php \esc_attr_e( 'Clear (use default)', 'newspack-event-aggregator' ); ?>">↺</button>
		</div>
		<?php
	}

	/**
	 * Segment size field callback.
	 */
	public function segment_size_callback(): void {
		$this->render_number_field( 'remote_segment_size', Config::load_config_defaults()['remote_segment_size'] ?? 10485760, 1048576, 268435456, \__( 'Segment size on remote servers in bytes.', 'newspack-event-aggregator' ) );
	}

	/**
	 * Remote max lifespan callback.
	 */
	public function max_lifespan_callback(): void {
		$this->render_number_field( 'remote_max_lifespan', Config::load_config_defaults()['remote_max_lifespan'] ?? 3600, 60, 604800, \__( 'Minimum retention on remote servers in seconds. Spokes keep data at least this long for aggregator to pull.', 'newspack-event-aggregator' ) );
	}

	/**
	 * Servers section description.
	 */
	public function servers_section_callback(): void {
		echo '<p>' . \esc_html__( 'Configure remote Event Logger servers to aggregate logs from.', 'newspack-event-aggregator' ) . '</p>';
	}

	/**
	 * Servers field - displays current servers and form to add/edit.
	 */
	public function servers_field_callback(): void {
		$registry = ServerRegistry::get_instance();
		$servers  = $registry->get_all();
		?>
		<div id="event-aggregator-servers">
			<table class="wp-list-table widefat fixed striped" style="max-width: 800px;">
				<thead>
					<tr>
						<th><?php \esc_html_e( 'ID', 'newspack-event-aggregator' ); ?></th>
						<th><?php \esc_html_e( 'URL', 'newspack-event-aggregator' ); ?></th>
						<th><?php \esc_html_e( 'Status', 'newspack-event-aggregator' ); ?></th>
						<th><?php \esc_html_e( 'Actions', 'newspack-event-aggregator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $servers ) ) : ?>
						<tr>
							<td colspan="4"><?php \esc_html_e( 'No servers configured.', 'newspack-event-aggregator' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $servers as $id => $config ) : ?>
							<tr data-server-id="<?php echo \esc_attr( $id ); ?>">
								<td><code><?php echo \esc_html( $id ); ?></code></td>
								<td><?php echo \esc_html( $config['url'] ); ?></td>
								<td>
									<?php if ( $config['enabled'] ) : ?>
										<span class="dashicons dashicons-yes-alt" style="color: green;" title="<?php \esc_attr_e( 'Enabled', 'newspack-event-aggregator' ); ?>"></span>
									<?php else : ?>
										<span class="dashicons dashicons-no" style="color: gray;" title="<?php \esc_attr_e( 'Disabled', 'newspack-event-aggregator' ); ?>"></span>
									<?php endif; ?>
									<span class="test-status"></span>
								</td>
								<td>
									<button type="button" class="button button-small event-aggregator-test" data-server-id="<?php echo \esc_attr( $id ); ?>">
										<?php \esc_html_e( 'Test', 'newspack-event-aggregator' ); ?>
									</button>
									<button type="button" class="button button-small event-aggregator-toggle" data-server-id="<?php echo \esc_attr( $id ); ?>" data-enabled="<?php echo $config['enabled'] ? '1' : '0'; ?>">
										<?php echo $config['enabled'] ? \esc_html__( 'Disable', 'newspack-event-aggregator' ) : \esc_html__( 'Enable', 'newspack-event-aggregator' ); ?>
									</button>
									<button type="button" class="button button-small button-link-delete event-aggregator-remove" data-server-id="<?php echo \esc_attr( $id ); ?>">
										<?php \esc_html_e( 'Remove', 'newspack-event-aggregator' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h4><?php \esc_html_e( 'Add New Server', 'newspack-event-aggregator' ); ?></h4>
			<table class="form-table" style="max-width: 600px;">
				<tr>
					<th><label for="new-server-id"><?php \esc_html_e( 'Server ID', 'newspack-event-aggregator' ); ?></label></th>
					<td>
						<input type="text" id="new-server-id" class="regular-text" placeholder="prod-web-01" pattern="[a-zA-Z0-9_-]+" />
						<p class="description"><?php \esc_html_e( 'Unique identifier (alphanumeric, hyphen, underscore).', 'newspack-event-aggregator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="new-server-url"><?php \esc_html_e( 'Server URL', 'newspack-event-aggregator' ); ?></label></th>
					<td>
						<input type="url" id="new-server-url" class="regular-text" placeholder="https://example.com" />
						<p class="description"><?php \esc_html_e( 'HTTPS URL of the WordPress site.', 'newspack-event-aggregator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="new-server-username"><?php \esc_html_e( 'Username', 'newspack-event-aggregator' ); ?></label></th>
					<td>
						<input type="text" id="new-server-username" class="regular-text" />
						<p class="description"><?php \esc_html_e( 'WordPress username on the remote site.', 'newspack-event-aggregator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="new-server-password"><?php \esc_html_e( 'Application Password', 'newspack-event-aggregator' ); ?></label></th>
					<td>
						<input type="password" id="new-server-password" class="regular-text" />
						<p class="description"><?php \esc_html_e( 'WordPress Application Password (Users → Profile → Application Passwords).', 'newspack-event-aggregator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button type="button" class="button button-primary" id="event-aggregator-add-server">
							<?php \esc_html_e( 'Add Server', 'newspack-event-aggregator' ); ?>
						</button>
						<span id="add-server-status"></span>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		// Aggregator status dashboard page.
		// Hook suffix varies based on which plugin registers first page, so check for page slug.
		if ( \str_contains( $hook_suffix, 'newspack-event-aggregator-status' ) ) {
			$asset_file = EVENT_AGGREGATOR_DIR . 'build/admin/index.asset.php';
			if ( ! \file_exists( $asset_file ) ) {
				return;
			}
			$asset = require $asset_file;

			\wp_enqueue_script(
				'event-aggregator-status',
				EVENT_AGGREGATOR_URL . 'build/admin/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			\wp_enqueue_style(
				'event-aggregator-status',
				EVENT_AGGREGATOR_URL . 'build/admin/index.css',
				[],
				$asset['version']
			);

			// Remove admin footer for full-page app.
			\add_filter( 'admin_footer_text', '__return_empty_string' );
			\add_filter( 'update_footer', '__return_empty_string', 11 );

			return;
		}

		// Only load on Event Logger settings page.
		if ( 'settings_page_newspack-event-logger-settings' !== $hook_suffix ) {
			return;
		}

		// Enqueue settings styles.
		$settings_asset_file = EVENT_AGGREGATOR_DIR . 'build/settings/settings.asset.php';
		if ( ! \file_exists( $settings_asset_file ) ) {
			return;
		}
		$settings_asset = require $settings_asset_file;

		\wp_enqueue_style(
			'event-aggregator-settings',
			EVENT_AGGREGATOR_URL . 'build/settings/settings.css',
			[],
			$settings_asset['version']
		);

		\wp_enqueue_script(
			'event-aggregator-admin',
			EVENT_AGGREGATOR_URL . 'assets/admin.js',
			[ 'jquery' ],
			NEWSPACK_EVENT_AGGREGATOR_VERSION,
			true
		);

		\wp_localize_script(
			'event-aggregator-admin',
			'eventAggregatorAdmin',
			[
				'restUrl' => \esc_url_raw( \rest_url( 'event-aggregator/v1/' ) ),
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
				'i18n'    => [
					'confirmRemove' => \__( 'Are you sure you want to remove this server?', 'newspack-event-aggregator' ),
					'testing'       => \__( 'Testing...', 'newspack-event-aggregator' ),
					'success'       => \__( 'Connected!', 'newspack-event-aggregator' ),
					'failed'        => \__( 'Failed', 'newspack-event-aggregator' ),
					'adding'        => \__( 'Adding...', 'newspack-event-aggregator' ),
					'added'         => \__( 'Server added! Reloading...', 'newspack-event-aggregator' ),
					'error'         => \__( 'Error', 'newspack-event-aggregator' ),
				],
			]
		);
	}
}
