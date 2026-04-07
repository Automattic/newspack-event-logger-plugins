<?php
/**
 * Dashboards Admin
 *
 * Admin script enqueuing and maintenance for Dashboards module.
 *
 * @package Newspack_Performance_Dashboards
 */

namespace Newspack_Performance_Dashboards\Admin;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;
use Newspack_Performance_Workers\StatsStore;

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
		\add_action( 'admin_post_event_logger_clear_stats', [ $this, 'handle_clear_stats' ] );
		\add_action( 'newspack_event_logger_settings_after_form', [ $this, 'render_maintenance_section' ] );
	}

	/**
	 * Render maintenance section (Clear Memcache Stats).
	 */
	public function render_maintenance_section() {
		?>
		<hr style="margin: 30px 0;">
		<h2><?php \esc_html_e( 'Maintenance', 'newspack-performance-dashboards' ); ?></h2>
		<p>
			<input type="button" class="button button-secondary" value="<?php \esc_attr_e( 'Clear Memcache Stats', 'newspack-performance-dashboards' ); ?>"
			onclick="if(confirm('<?php echo \esc_js( \__( 'Clear all performance stats from memcache? Hourly stats, leaderboards, and URL data will be reset. This cannot be undone.', 'newspack-performance-dashboards' ) ); ?>')) { document.getElementById('clear-stats-form').submit(); }" />
			<span class="description" style="margin-left: 10px;"><?php \esc_html_e( 'Clears hourly stats, leaderboards, and URL index. Per-URL flame data expires via TTL.', 'newspack-performance-dashboards' ); ?></span>
		</p>
		<form id="clear-stats-form" method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
			<input type="hidden" name="action" value="event_logger_clear_stats">
			<?php \wp_nonce_field( 'event_logger_clear_stats', 'event_logger_clear_stats_nonce' ); ?>
		</form>
		<?php
	}

	/**
	 * Handle clearing memcache stats.
	 */
	public function handle_clear_stats() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['event_logger_clear_stats_nonce'] ) || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['event_logger_clear_stats_nonce'] ) ), 'event_logger_clear_stats' ) ) {
			\wp_die( 'Security check failed' );
		}
		if ( ! \Newspack_Event_Logger\Admin\Admin::current_user_allowed() ) {
			\wp_die( 'Unauthorized' );
		}

		$config           = Config::load_config( 'full' );
		$num_partitions   = (int) ( $config['num_partitions'] ?? 1 );
		$memcache_servers = $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS;
		$max_lifespan     = (int) ( $config['max_lifespan'] ?? 86400 );
		StatsStore::init( $num_partitions, $memcache_servers, $max_lifespan );

		$deleted = StatsStore::flush_all();

		\wp_safe_redirect( \add_query_arg( [ 'page' => 'newspack-event-logger-settings', 'stats_cleared' => $deleted ], \admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		// Only load on Performance Dashboard pages (main dashboard + error log).
		// Must not match other Event Logger pages (stream, workers, rawlogs, gyroscope, aggregator).
		$is_dashboard = \str_ends_with( $hook_suffix, 'newspack-event-logger' )
			|| \str_contains( $hook_suffix, 'newspack-event-logger-errors' );
		if ( ! $is_dashboard ) {
			return;
		}

		$asset_file = PERFORMANCE_DASHBOARDS_DIR . 'build/admin/index.asset.php';
		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		\wp_enqueue_script(
			'performance-dashboards-admin',
			PERFORMANCE_DASHBOARDS_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Localize dashboard config.
		$config           = Config::load_config( 'full' );
		$retention        = (int) ( $config['max_lifespan'] ?? 86400 );
		\wp_localize_script(
			'performance-dashboards-admin',
			'eventLoggerDashboards',
			[
				'restUrl'          => \esc_url_raw( \rest_url() ),
				'nonce'            => \wp_create_nonce( 'wp_rest' ),
				'restartNonce'     => \wp_create_nonce( 'event_logger_restart_worker' ),
				'retentionSeconds' => $retention,
			]
		);

		// Load hook categories (for flame graph colors).
		$hook_categories_file = \defined( 'PERFORMANCE_LOGGER_DIR' ) ? PERFORMANCE_LOGGER_DIR . 'hook_categories.json' : '';
		if ( ! $hook_categories_file || ! \file_exists( $hook_categories_file ) ) {
			$hook_categories_file = '';
		}
		if ( $hook_categories_file && \file_exists( $hook_categories_file ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local file read.
			$hook_categories = \json_decode( \file_get_contents( $hook_categories_file ), true, 64 );
			\wp_localize_script( 'performance-dashboards-admin', 'eventLoggerHookCategories', $hook_categories ?: [] );
		}

		// Load custom event colors (for flame graph).
		\wp_localize_script( 'performance-dashboards-admin', 'eventLoggerCustomColors', Config::get_custom_colors() );

		\wp_enqueue_style(
			'performance-dashboards-admin',
			PERFORMANCE_DASHBOARDS_URL . 'build/admin/index.css',
			[ 'wp-components' ],
			$asset['version']
		);
	}
}
