<?php
/**
 * Workers Admin
 *
 * Admin settings for auto-tuning (FlameBuilder worker).
 *
 * @package Newspack_Performance_Workers
 */

namespace Newspack_Performance_Workers\Admin;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class for Workers module.
 */
class Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register Worker-specific settings.
	 */
	public function register_settings() {
		// Register options owned by this plugin.
		\register_setting( 'event_logger_options_group', 'event_logger_enable_workers', [ 'sanitize_callback' => 'absint' ] );
		\register_setting( 'event_logger_options_group', 'event_logger_auto_disable_threshold', [ 'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ], 'autoload' => false ] );
		\register_setting( 'event_logger_options_group', 'event_logger_auto_protect_time_threshold', [ 'sanitize_callback' => [ $this, 'sanitize_float_or_empty' ], 'autoload' => false ] );
		\register_setting( 'event_logger_options_group', 'event_logger_significant_events', [ 'sanitize_callback' => [ $this, 'sanitize_array_option' ], 'autoload' => false ] );

		// Workers section.
		\add_settings_section( 'event_logger_workers_section', \__( 'Performance Workers', 'newspack-performance-workers' ), [ $this, 'workers_section_callback' ], 'event_logger' );

		\add_settings_field( 'enable_workers', \__( 'Enable Workers', 'newspack-performance-workers' ), [ $this, 'enable_workers_callback' ], 'event_logger', 'event_logger_workers_section' );
		\add_settings_field( 'auto_tune', \__( 'Auto-Tune', 'newspack-performance-workers' ), [ $this, 'auto_tune_callback' ], 'event_logger', 'event_logger_workers_section' );
		\add_settings_field( 'significant_events', \__( 'Significant Events', 'newspack-performance-workers' ), [ $this, 'significant_events_callback' ], 'event_logger', 'event_logger_workers_section' );
	}

	public function enable_workers_callback() {
		$config  = \Newspack_Event_Logger\Config::load_config();
		$enabled = \get_option( 'event_logger_enable_workers', $config['enable_workers'] ?? 1 );
		?>
		<input type="hidden" name="event_logger_enable_workers" value="0" />
		<input type="checkbox" id="enable_workers" name="event_logger_enable_workers" value="1" <?php checked( 1, $enabled ); ?> />
		<label for="enable_workers"><?php \esc_html_e( 'Enable RequestBuilder and FlameBuilder', 'newspack-performance-workers' ); ?></label>
		<?php
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
			if ( null === $decoded && '' !== $input ) {
				$decoded = \json_decode( \wp_unslash( $input ), true, 64 );
			}
			$input = $decoded;
		}
		if ( ! \is_array( $input ) ) {
			return [];
		}
		return \array_values( \array_filter( \array_map( 'trim', $input ) ) );
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
	 * Sanitize float option, preserving empty string for "use default".
	 *
	 * @param mixed $input Input value.
	 * @return string|float Empty string or sanitized float.
	 */
	public function sanitize_float_or_empty( $input ) {
		if ( '' === $input || null === $input ) {
			return '';
		}
		return (float) $input;
	}

	/**
	 * Workers section description.
	 */
	public function workers_section_callback() {
		echo '<p>' . \esc_html__( 'Automatically disable noisy events and protect slow ones.', 'newspack-performance-workers' ) . '</p>';
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
			<button type="button" class="button button-secondary event-logger-reset-field" data-field="<?php echo \esc_attr( $field ); ?>" data-default="<?php echo \esc_attr( $default_json ); ?>" title="<?php \esc_attr_e( 'Reset to default', 'newspack-performance-workers' ); ?>">&#x21BA;</button>
		</div>
		<p class="description"><?php echo \esc_html( $description ); ?></p>
		<?php
	}

	/**
	 * Auto-tune field callback.
	 */
	public function auto_tune_callback() {
		$count_value   = \get_option( 'event_logger_auto_disable_threshold', '' );
		$time_value    = \get_option( 'event_logger_auto_protect_time_threshold', '' );
		$count_display = ( '' === $count_value || 0 === (int) $count_value ) ? '' : $count_value;
		$time_display  = ( '' === $time_value || 0.0 === (float) $time_value ) ? '' : $time_value;
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<div class="event-logger-auto-disable-row">
					<label class="event-logger-auto-disable-label">
						<?php \esc_html_e( 'Disable if count >', 'newspack-performance-workers' ); ?>
						<input type="number" id="auto_disable_threshold" name="event_logger_auto_disable_threshold" value="<?php echo \esc_attr( $count_display ); ?>" min="0" max="10000" class="small-text" placeholder="0" />
					</label>
					<label class="event-logger-auto-disable-label">
						<?php \esc_html_e( 'Protect if avg >=', 'newspack-performance-workers' ); ?>
						<input type="number" id="auto_protect_time_threshold" name="event_logger_auto_protect_time_threshold" value="<?php echo \esc_attr( $time_display ); ?>" min="0" max="1000" step="0.1" class="small-text" placeholder="0" />
						<?php \esc_html_e( 'ms', 'newspack-performance-workers' ); ?>
					</label>
				</div>
				<p class="description"><?php \esc_html_e( 'Noisy events (count > N) get disabled. Significant events (avg >= M ms) are protected. Set to 0 to disable.', 'newspack-performance-workers' ); ?></p>
			</div>
			<button type="button" class="button button-secondary event-logger-reset-btn" onclick="document.getElementById('auto_disable_threshold').value=''; document.getElementById('auto_protect_time_threshold').value='';" title="<?php \esc_attr_e( 'Clear (use default)', 'newspack-performance-workers' ); ?>">&#x21BA;</button>
		</div>
		<?php
	}

	/**
	 * Significant events field callback.
	 */
	public function significant_events_callback() {
		$stored = \get_option( 'event_logger_significant_events', [] );
		$values = \is_array( $stored ) ? \array_values( $stored ) : [];
		\sort( $values, SORT_NATURAL | SORT_FLAG_CASE );
		$this->render_array_field_custom( 'significant_events', $values, [], \__( 'Events/hooks that exceeded the time threshold at least once. Protected from auto-disable. Remove to allow auto-disabling.', 'newspack-performance-workers' ) );
	}
}
