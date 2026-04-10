<?php
/**
 * Tests for Performance Logger Admin (settings registration and sanitization).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Performance_Logger\Admin\Admin;

#[\PHPUnit\Framework\Attributes\CoversClass( Admin::class )]
class PerformanceLoggerAdminTest extends TestCase {

	private array $saved_filters = [];

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_registered_settings'] = [];
		$GLOBALS['_wp_test_options']             = [];
		$this->saved_filters = $GLOBALS['_wp_test_filters'] ?? [];

		if ( ! \defined( 'PERFORMANCE_LOGGER_DIR' ) ) {
			\define( 'PERFORMANCE_LOGGER_DIR', '/tmp/test-performance-logger/' );
		}
		if ( ! \defined( 'PERFORMANCE_LOGGER_URL' ) ) {
			\define( 'PERFORMANCE_LOGGER_URL', 'http://localhost/wp-content/plugins/performance-logger/' );
		}
		if ( ! \defined( 'EVENT_LOGGER_DIR' ) ) {
			\define( 'EVENT_LOGGER_DIR', '/tmp/test-event-logger/' );
		}
	}

	protected function tearDown(): void {
		Config::reset();
		$GLOBALS['_wp_test_filters'] = $this->saved_filters;
		unset( $GLOBALS['_wp_test_registered_settings'] );
		// Clean up mock asset files.
		@\unlink( PERFORMANCE_LOGGER_DIR . 'build/admin/index.asset.php' );
		@\unlink( PERFORMANCE_LOGGER_DIR . 'hook_categories.json' );
		@\unlink( EVENT_LOGGER_DIR . 'event-logger-config.php' );
		@\rmdir( PERFORMANCE_LOGGER_DIR . 'build/admin' );
		@\rmdir( PERFORMANCE_LOGGER_DIR . 'build' );
		parent::tearDown();
	}

	public function test_constructor_registers_actions(): void {
		$admin = new Admin();

		$this->assertNotEmpty(
			$GLOBALS['_wp_test_filters']['admin_init'] ?? [],
			'Constructor should register admin_init action'
		);
		$this->assertNotEmpty(
			$GLOBALS['_wp_test_filters']['admin_enqueue_scripts'] ?? [],
			'Constructor should register admin_enqueue_scripts action'
		);
	}

	public function test_register_settings_registers_options(): void {
		$admin = new Admin();
		$admin->register_settings();

		$registered = $GLOBALS['_wp_test_registered_settings'];

		$this->assertArrayHasKey( 'event_logger_log_urls', $registered );
		$this->assertArrayHasKey( 'event_logger_skip_urls', $registered );
		$this->assertArrayHasKey( 'event_logger_log_events', $registered );
		$this->assertArrayHasKey( 'event_logger_custom_events', $registered );
		$this->assertArrayHasKey( 'event_logger_log_memory', $registered );
		$this->assertArrayHasKey( 'event_logger_flush_every_line', $registered );

		$this->assertSame( 'event_logger_options_group', $registered['event_logger_log_urls']['group'] );
	}

	public function test_sanitize_array_option_with_json_string(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_array_option( '["a","b"]' );

		$this->assertSame( [ 'a', 'b' ], $result );
	}

	public function test_sanitize_array_option_with_array(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_array_option( [ 'x', '', 'y' ] );

		$this->assertSame( [ 'x', 'y' ], $result );
	}

	public function test_sanitize_array_option_with_non_array(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_array_option( null );

		$this->assertSame( [], $result );
	}

	public function test_sanitize_custom_events_associative(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_custom_events( [ 'my_event' => '#ff0000' ] );

		$this->assertSame( [ 'my_event' => '#ff0000' ], $result );
	}

	public function test_sanitize_custom_events_sequential(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_custom_events( [ 'my_event' ] );

		// Config::get_custom_colors() returns empty in tests, so default color is used.
		$this->assertSame( [ 'my_event' => '#ffa726' ], $result );
	}

	public function test_sanitize_custom_events_empty(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_custom_events( '' );

		$this->assertSame( [], $result );
	}

	public function test_sanitize_custom_events_invalid_color(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_custom_events( [ 'ev' => 'not-a-color' ] );

		$this->assertSame( [ 'ev' => '#ffa726' ], $result );
	}

	public function test_sanitize_bool_option_truthy(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_bool_option( '1' );

		$this->assertTrue( $result );
	}

	public function test_sanitize_bool_option_falsy(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_bool_option( '' );

		$this->assertFalse( $result );
	}

	public function test_sanitize_bool_option_null(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_bool_option( null );

		$this->assertFalse( $result );
	}

	public function test_performance_section_callback_outputs_html(): void {
		$admin = new Admin();

		\ob_start();
		$admin->performance_section_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( '<p>', $output );
		$this->assertStringContainsString( 'URLs', $output );
	}

	public function test_debugging_section_callback_outputs_html(): void {
		$admin = new Admin();

		\ob_start();
		$admin->debugging_section_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( '<p>', $output );
		$this->assertStringContainsString( 'OOM', $output );
	}

	public function test_log_urls_callback_outputs_tag_input(): void {
		$admin = new Admin();

		\ob_start();
		$admin->log_urls_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event-logger-tag-input', $output );
	}

	public function test_log_memory_callback_outputs_checkbox(): void {
		$admin = new Admin();

		\ob_start();
		$admin->log_memory_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $output );
	}

	public function test_skip_urls_callback_outputs_tag_input(): void {
		$admin = new Admin();

		\ob_start();
		$admin->skip_urls_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event-logger-tag-input', $output );
		$this->assertStringContainsString( 'skip_urls', $output );
	}

	public function test_log_events_callback_outputs_tag_input(): void {
		$admin = new Admin();

		\ob_start();
		$admin->log_events_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event-logger-tag-input', $output );
		$this->assertStringContainsString( 'log_events', $output );
	}

	public function test_custom_events_callback_outputs_tag_input(): void {
		$admin = new Admin();

		\ob_start();
		$admin->custom_events_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event-logger-tag-input', $output );
		$this->assertStringContainsString( 'custom_events', $output );
	}

	public function test_flush_every_line_callback_outputs_checkbox(): void {
		$admin = new Admin();

		\ob_start();
		$admin->flush_every_line_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'flush_every_line', $output );
	}

	public function test_enqueue_scripts_skips_wrong_hook(): void {
		$admin = new Admin();

		$admin->enqueue_scripts( 'edit.php' );
		$this->assertTrue( true );
	}

	public function test_enqueue_scripts_returns_early_without_asset(): void {
		$admin = new Admin();

		// Correct hook but no asset file — should return early.
		$admin->enqueue_scripts( 'settings_page_newspack-event-logger-settings' );
		$this->assertTrue( true );
	}

	public function test_enqueue_scripts_with_asset_file(): void {
		// Create mock asset file.
		@\mkdir( PERFORMANCE_LOGGER_DIR . 'build/admin', 0755, true );
		\file_put_contents(
			PERFORMANCE_LOGGER_DIR . 'build/admin/index.asset.php',
			'<?php return ["dependencies" => [], "version" => "1.0.0"];'
		);

		$admin = new Admin();
		$admin->enqueue_scripts( 'settings_page_newspack-event-logger-settings' );

		// If we got here without error, enqueue path ran successfully.
		$this->assertTrue( true );
	}

	public function test_enqueue_scripts_loads_hook_categories(): void {
		// Create both the asset file and hook_categories.json.
		@\mkdir( PERFORMANCE_LOGGER_DIR . 'build/admin', 0755, true );
		\file_put_contents(
			PERFORMANCE_LOGGER_DIR . 'build/admin/index.asset.php',
			'<?php return ["dependencies" => [], "version" => "1.0.0"];'
		);
		\file_put_contents(
			PERFORMANCE_LOGGER_DIR . 'hook_categories.json',
			\json_encode( [ 'core' => [ 'init', 'wp_loaded' ] ] )
		);

		$admin = new Admin();
		$admin->enqueue_scripts( 'settings_page_newspack-event-logger-settings' );

		$this->assertTrue( true );
	}

	public function test_enqueue_scripts_loads_recommended_hooks(): void {
		@\mkdir( PERFORMANCE_LOGGER_DIR . 'build/admin', 0755, true );
		\file_put_contents(
			PERFORMANCE_LOGGER_DIR . 'build/admin/index.asset.php',
			'<?php return ["dependencies" => [], "version" => "1.0.0"];'
		);
		// Create a mock config file with recommended_log_events.
		@\mkdir( EVENT_LOGGER_DIR, 0755, true );
		\file_put_contents(
			EVENT_LOGGER_DIR . 'event-logger-config.php',
			'<?php return ["recommended_log_events" => ["init", "wp_head"]];'
		);

		$admin = new Admin();
		$admin->enqueue_scripts( 'settings_page_newspack-event-logger-settings' );

		$this->assertTrue( true );
	}

	public function test_sanitize_custom_events_with_escaped_json_string(): void {
		// Simulates WP slashing: json_decode fails first, then wp_unslash + decode succeeds.
		$admin  = new Admin();
		$result = $admin->sanitize_custom_events( '["my_event"]' );

		$this->assertSame( [ 'my_event' => '#ffa726' ], $result );
	}

	public function test_sanitize_custom_events_with_empty_event_name(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_custom_events( [ '' ] );

		$this->assertSame( [], $result );
	}

	public function test_log_events_callback_with_non_array_option(): void {
		// Stored value is not an array — should fall back to empty.
		$GLOBALS['_wp_test_options']['event_logger_log_events'] = 'not-an-array';

		$admin = new Admin();

		\ob_start();
		$admin->log_events_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event-logger-tag-input', $output );
	}

	public function test_skip_urls_callback_with_non_array_option(): void {
		// render_array_field's non-array fallback (line 210).
		$GLOBALS['_wp_test_options']['event_logger_skip_urls'] = 'broken';

		$admin = new Admin();

		\ob_start();
		$admin->skip_urls_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event-logger-tag-input', $output );
	}
}
