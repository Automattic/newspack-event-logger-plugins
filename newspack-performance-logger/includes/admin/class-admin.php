<?php
/**
 * Performance Logger Admin
 *
 * Admin settings for Performance Logger module.
 *
 * @package Newspack_Performance_Logger
 */

namespace Newspack_Performance_Logger\Admin;

use Newspack_Event_Logger\Config;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class for Performance Logger module.
 */
class Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Enqueue admin scripts and styles for settings page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		// Only load on Event Logger settings page.
		if ( ! \str_contains( $hook_suffix, 'newspack-event-logger-settings' ) ) {
			return;
		}

		$asset_file = PERFORMANCE_LOGGER_DIR . 'build/admin/index.asset.php';
		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		\wp_enqueue_script(
			'performance-logger-admin',
			PERFORMANCE_LOGGER_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		\wp_enqueue_style(
			'performance-logger-admin',
			PERFORMANCE_LOGGER_URL . 'build/admin/index.css',
			[ 'wp-components' ],
			$asset['version']
		);

		// Load hook categories for hook selector modal.
		$hook_categories_file = PERFORMANCE_LOGGER_DIR . 'hook_categories.json';
		if ( \file_exists( $hook_categories_file ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local file read.
			$hook_categories = \json_decode( \file_get_contents( $hook_categories_file ), true, 64 );
			\wp_localize_script( 'performance-logger-admin', 'eventLoggerHookCategories', $hook_categories ?: [] );
		}

		// Load recommended hooks for settings page.
		$config_file = \defined( 'EVENT_LOGGER_DIR' ) ? EVENT_LOGGER_DIR . 'newspack-event-logger-config.php' : '';
		if ( $config_file && \file_exists( $config_file ) ) {
			$file_config = require $config_file;
			\wp_localize_script( 'performance-logger-admin', 'eventLoggerRecommendedHooks', $file_config['recommended_log_events'] ?? [] );
		}

		// Load custom event colors.
		\wp_localize_script( 'performance-logger-admin', 'eventLoggerCustomColors', Config::get_custom_colors() );
	}

	/**
	 * Register Performance Logger settings.
	 */
	public function register_settings() {
		// Register options owned by this plugin.
		\register_setting( 'event_logger_options_group', 'event_logger_log_urls', [ 'sanitize_callback' => [ $this, 'sanitize_array_option' ] ] );
		\register_setting( 'event_logger_options_group', 'event_logger_skip_urls', [ 'sanitize_callback' => [ $this, 'sanitize_array_option' ] ] );
		\register_setting( 'event_logger_options_group', 'event_logger_log_events', [ 'sanitize_callback' => [ $this, 'sanitize_array_option' ] ] );
		\register_setting( 'event_logger_options_group', 'event_logger_custom_events', [ 'sanitize_callback' => [ $this, 'sanitize_custom_events' ] ] );
		\register_setting( 'event_logger_options_group', 'event_logger_log_memory', [ 'sanitize_callback' => [ $this, 'sanitize_bool_option' ] ] );
		\register_setting( 'event_logger_options_group', 'event_logger_flush_every_line', [ 'sanitize_callback' => [ $this, 'sanitize_bool_option' ] ] );

		// Performance section - add to event_logger settings page.
		\add_settings_section( 'event_logger_performance_section', \__( 'Instrumentation', 'newspack-performance-logger' ), [ $this, 'performance_section_callback' ], 'event_logger' );
		\add_settings_field( 'log_urls', \__( 'Log URLs', 'newspack-performance-logger' ), [ $this, 'log_urls_callback' ], 'event_logger', 'event_logger_performance_section' );
		\add_settings_field( 'skip_urls', \__( 'Skip URLs', 'newspack-performance-logger' ), [ $this, 'skip_urls_callback' ], 'event_logger', 'event_logger_performance_section' );
		\add_settings_field( 'log_events', \__( 'Log Events', 'newspack-performance-logger' ), [ $this, 'log_events_callback' ], 'event_logger', 'event_logger_performance_section' );

		// Only show custom events field if any custom events are registered.
		if ( ! empty( Config::get_custom_colors() ) ) {
			\add_settings_field( 'custom_events', \__( 'Custom Events', 'newspack-performance-logger' ), [ $this, 'custom_events_callback' ], 'event_logger', 'event_logger_performance_section' );
		}

		// Debugging section.
		\add_settings_section( 'event_logger_debugging_section', \__( 'Debugging', 'newspack-performance-logger' ), [ $this, 'debugging_section_callback' ], 'event_logger' );
		\add_settings_field( 'log_memory', \__( 'Log Memory', 'newspack-performance-logger' ), [ $this, 'log_memory_callback' ], 'event_logger', 'event_logger_debugging_section' );
		\add_settings_field( 'flush_every_line', \__( 'Flush Every Line', 'newspack-performance-logger' ), [ $this, 'flush_every_line_callback' ], 'event_logger', 'event_logger_debugging_section' );
	}

	/**
	 * Sanitize array option.
	 *
	 * @param mixed $input Input value.
	 * @return array Sanitized array.
	 */
	public function sanitize_array_option( $input ) {
		if ( \is_string( $input ) ) {
			$decoded = \json_decode( $input, true, 64 );
			if ( \is_array( $decoded ) ) {
				$input = $decoded;
			}
		}
		return \is_array( $input ) ? \array_values( \array_filter( \array_map( 'sanitize_text_field', $input ) ) ) : [];
	}

	/**
	 * Sanitize custom events - convert array of names to associative array with colors.
	 *
	 * @param mixed $input Input value.
	 * @return array Associative array: event_name => color.
	 */
	public function sanitize_custom_events( $input ) {
		if ( \is_string( $input ) ) {
			$decoded = \json_decode( $input, true, 64 );
			if ( null === $decoded && '' !== $input ) {
				$decoded = \json_decode( \wp_unslash( $input ), true, 64 );
			}
			$input = $decoded;
		}
		if ( ! \is_array( $input ) || empty( $input ) ) {
			return [];
		}

		$first_key      = \array_key_first( $input );
		$is_associative = \is_string( $first_key ) && ! \is_numeric( $first_key );

		if ( $is_associative ) {
			$selected        = \array_map( 'sanitize_text_field', \array_keys( $input ) );
			$existing_colors = $input;
		} else {
			$selected        = \array_values( \array_filter( \array_map( 'sanitize_text_field', $input ) ) );
			$existing_colors = [];
		}

		$all_events = Config::get_custom_colors();
		$result     = [];
		foreach ( $selected as $event ) {
			if ( '' === $event ) {
				continue;
			}
			$color = $existing_colors[ $event ] ?? $all_events[ $event ] ?? '#ffa726';
			$result[ $event ] = \preg_match( '/^#[0-9a-fA-F]{3,6}$/', $color ) ? $color : '#ffa726';
		}
		return $result;
	}

	/**
	 * Performance section description.
	 */
	public function performance_section_callback() {
		echo '<p>' . \esc_html__( 'Configure which URLs and events to capture.', 'newspack-performance-logger' ) . '</p>';
	}

	/**
	 * Render a tag-input field with custom values/defaults.
	 *
	 * @param string $field       Field name.
	 * @param array  $values      Current values.
	 * @param array  $default     Default values.
	 * @param string $description Field description.
	 */
	private function render_array_field_custom( string $field, array $values, array $default, string $description ): void {
		$values_json  = \wp_json_encode( $values );
		$default_json = \wp_json_encode( $default );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<input type="hidden" id="<?php echo \esc_attr( $field ); ?>_json" name="event_logger_<?php echo \esc_attr( $field ); ?>" value="<?php echo \esc_attr( $values_json ); ?>" />
				<div id="event-logger-<?php echo \esc_attr( $field ); ?>" data-field="<?php echo \esc_attr( $field ); ?>" data-values="<?php echo \esc_attr( $values_json ); ?>" data-default="<?php echo \esc_attr( $default_json ); ?>" class="event-logger-tag-input"></div>
			</div>
			<button type="button" class="button button-secondary event-logger-reset-field" data-field="<?php echo \esc_attr( $field ); ?>" data-default="<?php echo \esc_attr( $default_json ); ?>" title="<?php \esc_attr_e( 'Reset to default', 'newspack-performance-logger' ); ?>">&#x21BA;</button>
		</div>
		<p class="description"><?php echo \esc_html( $description ); ?></p>
		<?php
	}

	/**
	 * Render a tag-input field for array settings.
	 *
	 * @param string $field       Field name.
	 * @param string $description Field description.
	 * @param string $examples    Example values.
	 * @param array  $default     Default values.
	 */
	private function render_array_field( string $field, string $description, string $examples, array $default = [] ): void {
		$values = \get_option( 'event_logger_' . $field, $default );
		if ( ! \is_array( $values ) ) {
			$values = $default;
		}
		$values_json  = \wp_json_encode( $values );
		$default_json = \wp_json_encode( $default );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<input type="hidden" id="<?php echo \esc_attr( $field ); ?>_json" name="event_logger_<?php echo \esc_attr( $field ); ?>" value="<?php echo \esc_attr( $values_json ); ?>" />
				<div id="event-logger-<?php echo \esc_attr( $field ); ?>" data-field="<?php echo \esc_attr( $field ); ?>" data-values="<?php echo \esc_attr( $values_json ); ?>" data-default="<?php echo \esc_attr( $default_json ); ?>" class="event-logger-tag-input"></div>
			</div>
			<button type="button" class="button button-secondary event-logger-reset-field" data-field="<?php echo \esc_attr( $field ); ?>" data-default="<?php echo \esc_attr( $default_json ); ?>" title="<?php \esc_attr_e( 'Reset to default', 'newspack-performance-logger' ); ?>">&#x21BA;</button>
		</div>
		<p class="description"><?php echo \esc_html( $description ); ?></p>
		<?php if ( $examples ) : ?>
		<p class="description"><?php \esc_html_e( 'Examples:', 'newspack-performance-logger' ); ?> <?php echo \esc_html( $examples ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Log URLs field callback.
	 */
	public function log_urls_callback() {
		$this->render_array_field(
			'log_urls',
			\__( 'Only log URLs containing these substrings. Leave empty to log all requests.', 'newspack-performance-logger' ),
			'/calendar, /events/, article.fcgi'
		);
	}

	/**
	 * Skip URLs field callback.
	 */
	public function skip_urls_callback() {
		$defaults = Config::load_config_defaults();
		$default  = $defaults['skip_urls'] ?? [ '/wp-cron.php' ];
		$this->render_array_field(
			'skip_urls',
			\__( 'Never log URLs containing these substrings. (Checked first - always wins over Log URLs)', 'newspack-performance-logger' ),
			'/wp-cron.php, /wp-admin/admin-ajax.php',
			$default
		);
	}

	/**
	 * Log events field callback.
	 */
	public function log_events_callback() {
		$stored = \get_option( 'event_logger_log_events', [] );
		if ( ! \is_array( $stored ) ) {
			$stored = [];
		}
		$values = \array_values( \array_filter( $stored, 'is_string' ) );
		$this->render_array_field_custom( 'log_events', $values, [], \__( 'Hooks to time. Use Browse Hooks to select from categories.', 'newspack-performance-logger' ) );
	}

	/**
	 * Custom events field callback.
	 */
	public function custom_events_callback() {
		$stored = \get_option( 'event_logger_custom_events', [] );
		$values = \is_array( $stored ) ? \array_keys( $stored ) : [];
		$this->render_array_field_custom( 'custom_events', $values, [], \__( 'Custom events to time. Use Browse Events to select from available events.', 'newspack-performance-logger' ) );
	}

	/**
	 * Debugging section description.
	 */
	public function debugging_section_callback() {
		echo '<p>' . \esc_html__( 'Options for tracing OOMs and mysterious slowness. Both add overhead — disable when not needed.', 'newspack-performance-logger' ) . '</p>';
	}

	/**
	 * Log memory checkbox callback.
	 */
	public function log_memory_callback() {
		$this->render_checkbox_field(
			'log_memory',
			\__( 'Append peak_mb to every complete() log entry so memory growth is visible across the request timeline.', 'newspack-performance-logger' )
		);
	}

	/**
	 * Flush every line checkbox callback.
	 */
	public function flush_every_line_callback() {
		$this->render_checkbox_field(
			'flush_every_line',
			\__( 'Flush write buffer after every log line. Survives OOM kills — last line before crash is preserved on disk. Trades throughput for crash survivability.', 'newspack-performance-logger' )
		);
	}

	/**
	 * Render a checkbox field for boolean settings.
	 *
	 * @param string $field       Field name (without event_logger_ prefix).
	 * @param string $description Field description.
	 */
	private function render_checkbox_field( string $field, string $description ): void {
		$option  = 'event_logger_' . $field;
		$checked = ! empty( \get_option( $option, false ) );
		?>
		<label>
			<input type="checkbox" name="<?php echo \esc_attr( $option ); ?>" value="1" <?php \checked( $checked ); ?> />
			<?php echo \esc_html( $description ); ?>
		</label>
		<?php
	}

	/**
	 * Sanitize boolean option from checkbox.
	 *
	 * @param mixed $input Input value.
	 * @return bool Sanitized boolean.
	 */
	public function sanitize_bool_option( $input ) {
		return ! empty( $input );
	}
}
