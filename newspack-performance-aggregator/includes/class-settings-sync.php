<?php
/**
 * Settings Sync
 *
 * Hooks into update_option to fan-out performance tuning setting changes to remote servers.
 * When a synced option changes, a job is queued to RemoteManager for fan-out.
 *
 * Synced options (performance tuning):
 * - event_logger_log_urls
 * - event_logger_skip_urls
 * - event_logger_log_events
 * - event_logger_custom_events
 * - event_logger_auto_disable_threshold
 * - event_logger_auto_protect_time_threshold
 * - event_logger_significant_events
 * - event_logger_log_memory
 * - event_logger_flush_every_line
 *
 * Core options (num_partitions, num_segments, segment_size) are synced by
 * newspack-event-aggregator's SettingsSync.
 *
 * @package Newspack_Performance_Aggregator
 */

namespace Newspack_Performance_Aggregator;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Sync class.
 */
class SettingsSync {

	/**
	 * Options that sync to remote servers.
	 *
	 * @var array<string>
	 */
	private const SYNCED_OPTIONS = [
		'event_logger_log_urls',
		'event_logger_skip_urls',
		'event_logger_log_events',
		'event_logger_custom_events',
		'event_logger_auto_disable_threshold',
		'event_logger_auto_protect_time_threshold',
		'event_logger_significant_events',
		'event_logger_log_memory',
		'event_logger_flush_every_line',
	];

	/**
	 * REST endpoint for syncing performance options.
	 *
	 * @var string
	 */
	private const ENDPOINT = '/wp-json/perf-logger/v1/settings';

	/**
	 * Re-entrancy guard to prevent sync loops.
	 *
	 * These options sync with the same name on hub and spoke (no name-remapping).
	 * When the hub's own settings endpoint receives a sync (e.g., during
	 * sync_all_settings or health check round-trip), the update_option call
	 * would re-queue the sync without this guard. The spoke is also protected
	 * by the enable_workers=false check, but this guard provides defense-in-depth.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Initialize settings sync.
	 */
	public static function init(): void {
		\add_action( 'update_option', [ self::class, 'on_option_update' ], 10, 3 );
		\add_action( 'add_option', [ self::class, 'on_option_add' ], 10, 2 );
		\add_filter( 'newspack_event_aggregator_synced_settings', [ self::class, 'register_synced_settings' ] );
	}

	/**
	 * Register synced settings for health check full sync.
	 *
	 * @param array $settings Existing settings.
	 * @return array Modified settings.
	 */
	public static function register_synced_settings( array $settings ): array {
		foreach ( self::SYNCED_OPTIONS as $option ) {
			$settings[] = [
				'local_option'  => $option,
				'remote_option' => $option,
				'endpoint'      => self::ENDPOINT,
			];
		}
		return $settings;
	}

	/**
	 * Handle option update.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value (unused).
	 * @param mixed  $new_value New value.
	 */
	public static function on_option_update( string $option, $old_value, $new_value ): void {
		self::maybe_queue_sync( $option, $new_value );
	}

	/**
	 * Handle option add (first time set).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 */
	public static function on_option_add( string $option, $value ): void {
		self::maybe_queue_sync( $option, $value );
	}

	/**
	 * Queue sync job if option is in sync list.
	 *
	 * Routes through JobIntake (jobintake.log) instead of LogManager (firehose.log)
	 * because settings like log_events can exceed the 4KB PIPE_BUF atomic write limit.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 */
	private static function maybe_queue_sync( string $option, $value ): void {
		if ( ! \in_array( $option, self::SYNCED_OPTIONS, true ) ) {
			return;
		}

		// Re-entrancy guard: if we are currently processing an inbound sync
		// (e.g., a spoke receiving a setting via the REST endpoint), don't
		// re-queue the same setting back. This prevents fan-out loops since
		// these options use the same name on hub and spoke (no name remapping).
		if ( self::$syncing ) {
			return;
		}

		// Only hub nodes (enable_workers=true) should sync settings.
		// Remote nodes receive settings but don't fan-out to prevent loops.
		// Fail-closed: missing or non-true enable_workers means we are NOT a hub
		// (matches event-aggregator/SettingsSync at class-settings-sync.php:113).
		if ( \class_exists( 'Newspack_Event_Logger\Config' ) ) {
			$config = \Newspack_Event_Logger\Config::load_config();
			if ( ! isset( $config['enable_workers'] ) || true !== $config['enable_workers'] ) {
				return;
			}
		} else {
			return; // Fail-closed: don't sync if Config unavailable.
		}

		if ( ! \class_exists( 'Newspack_Event_Jobs\JobIntake' ) ) {
			return;
		}

		// Resolve empty/false to config values so spokes get the intended
		// value instead of a type that fails remote sanitization.
		// Use load_config('full') which includes WP option values -- load_config_defaults()
		// only has file-based defaults and misses options like auto_disable_threshold.
		// Resolve empty/false to config values. Safe to call Config here —
		// JobIntake (checked above) depends on Config, so it is always loaded.
		if ( '' === $value || false === $value ) {
			$config_key = \str_replace( 'event_logger_', '', $option );
			$full       = \Newspack_Event_Logger\Config::load_config( 'full' );
			$value      = $full[ $config_key ] ?? $value;
		}

		\Newspack_Event_Jobs\JobIntake::queue( 'remote_manager', [
			'action'    => 'sync_setting',
			'option'    => $option,
			'value'     => $value,
			'endpoint'  => self::ENDPOINT,
			'queued_at' => \time(),
		] );
	}

	/**
	 * Suppress sync fan-out during inbound setting updates.
	 *
	 * Call this before update_option() when applying a remotely-synced setting
	 * to prevent the update_option hook from re-queuing the sync.
	 *
	 * @param bool $suppress True to suppress, false to re-enable.
	 */
	public static function suppress_sync( bool $suppress = true ): void {
		self::$syncing = $suppress;
	}

	/**
	 * Get list of synced options.
	 *
	 * @return array<string>
	 */
	public static function get_synced_options(): array {
		return self::SYNCED_OPTIONS;
	}
}
