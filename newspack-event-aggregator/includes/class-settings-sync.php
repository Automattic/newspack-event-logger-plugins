<?php
/**
 * Settings Sync
 *
 * Hooks into update_option to fan-out core Event Logger setting changes to remote servers.
 * When a synced option changes, a job is queued to RemoteManager for fan-out.
 *
 * Synced options:
 * - event_logger_num_partitions (synced as-is)
 * - event_logger_remote_num_segments (synced as event_logger_num_segments)
 * - event_logger_remote_segment_size (synced as event_logger_segment_size)
 * - event_logger_remote_max_lifespan (synced as event_logger_max_lifespan)
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Sync class.
 */
class SettingsSync {

	/**
	 * Options that sync to remote servers.
	 * Maps local option name to remote option name.
	 *
	 * @var array<string, string>
	 */
	private const SYNCED_OPTIONS = [
		'event_logger_num_partitions'       => 'event_logger_num_partitions',
		'event_logger_remote_num_segments'  => 'event_logger_num_segments',
		'event_logger_remote_segment_size'  => 'event_logger_segment_size',
		'event_logger_remote_max_lifespan'  => 'event_logger_max_lifespan',
	];

	/**
	 * REST endpoint for syncing core options.
	 *
	 * @var string
	 */
	private const ENDPOINT = '/wp-json/event-logger/v1/settings';

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
		foreach ( self::SYNCED_OPTIONS as $local => $remote ) {
			$settings[] = [
				'local_option'  => $local,
				'remote_option' => $remote,
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
	 * because settings payloads can exceed the 4KB PIPE_BUF atomic write limit.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 */
	private static function maybe_queue_sync( string $option, $value ): void {
		if ( ! isset( self::SYNCED_OPTIONS[ $option ] ) ) {
			return;
		}

		// Only hub nodes (enable_workers=true) should sync settings.
		// Remote nodes receive settings but don't fan-out to prevent loops.
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

		// Map local option name to remote option name.
		$remote_option = self::SYNCED_OPTIONS[ $option ];

		// Resolve empty/false to config file defaults. Safe to call Config here —
		// JobIntake (checked above) depends on Config, so it is always loaded.
		if ( '' === $value || false === $value ) {
			$config_key = \str_replace( 'event_logger_', '', $option );
			$defaults   = \Newspack_Event_Logger\Config::load_config_defaults();
			$value      = $defaults[ $config_key ] ?? $value;
		}

		\Newspack_Event_Jobs\JobIntake::queue( 'remote_manager', [
			'action'    => 'sync_setting',
			'option'    => $remote_option,
			'value'     => $value,
			'endpoint'  => self::ENDPOINT,
			'queued_at' => \time(),
		] );
	}

	/**
	 * Get list of synced options.
	 *
	 * @return array<string, string>
	 */
	public static function get_synced_options(): array {
		return self::SYNCED_OPTIONS;
	}
}
