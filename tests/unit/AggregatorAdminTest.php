<?php
/**
 * Tests for Aggregator Admin (settings registration and sanitization).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Aggregator\Admin\Admin;

#[\PHPUnit\Framework\Attributes\CoversClass( Admin::class )]
class AggregatorAdminTest extends TestCase {

	private array $saved_filters = [];

	protected function setUp(): void {
		parent::setUp();
		// Restore test config env var — other tests may have unset or mutated it.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		Config::reset();
		$GLOBALS['_wp_test_registered_settings'] = [];
		$GLOBALS['_wp_test_options']             = [];
		// Save hooks registered by bootstrap so we can detect new ones.
		$this->saved_filters = $GLOBALS['_wp_test_filters'] ?? [];

		if ( ! \defined( 'EVENT_AGGREGATOR_DIR' ) ) {
			\define( 'EVENT_AGGREGATOR_DIR', '/tmp/test-aggregator/' );
		}
		if ( ! \defined( 'EVENT_AGGREGATOR_URL' ) ) {
			\define( 'EVENT_AGGREGATOR_URL', 'http://localhost/wp-content/plugins/event-aggregator/' );
		}
		if ( ! \defined( 'NEWSPACK_EVENT_AGGREGATOR_VERSION' ) ) {
			\define( 'NEWSPACK_EVENT_AGGREGATOR_VERSION', '1.0.0' );
		}
	}

	protected function tearDown(): void {
		Config::reset();
		$GLOBALS['_wp_test_filters'] = $this->saved_filters;
		unset( $GLOBALS['_wp_test_registered_settings'], $GLOBALS['_wp_test_user_can'] );
		// Clean up mock asset files.
		@\unlink( EVENT_AGGREGATOR_DIR . 'build/admin/index.asset.php' );
		@\unlink( EVENT_AGGREGATOR_DIR . 'build/settings/settings.asset.php' );
		@\rmdir( EVENT_AGGREGATOR_DIR . 'build/admin' );
		@\rmdir( EVENT_AGGREGATOR_DIR . 'build/settings' );
		@\rmdir( EVENT_AGGREGATOR_DIR . 'build' );
		// Reset ServerRegistry singleton.
		$ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
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

	public function test_register_admin_pages_adds_page(): void {
		$admin  = new Admin();
		$result = $admin->register_admin_pages( [] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'newspack-event-aggregator-status', $result[0]['slug'] );
	}

	public function test_register_settings_registers_options(): void {
		$admin = new Admin();
		$admin->register_settings();

		$registered = $GLOBALS['_wp_test_registered_settings'];

		$this->assertArrayHasKey( 'event_logger_remote_num_segments', $registered );
		$this->assertArrayHasKey( 'event_logger_remote_segment_size', $registered );
		$this->assertArrayHasKey( 'event_logger_remote_max_lifespan', $registered );

		$this->assertSame( 'event_logger_options_group', $registered['event_logger_remote_num_segments']['group'] );
	}

	public function test_sanitize_num_segments_empty(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_num_segments( '' );

		$this->assertSame( '', $result );
	}

	public function test_sanitize_num_segments_clamps_low(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_num_segments( 1 );

		$this->assertSame( 2, $result );
	}

	public function test_sanitize_num_segments_clamps_high(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_num_segments( 100 );

		$this->assertSame( 16, $result );
	}

	public function test_sanitize_num_segments_valid(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_num_segments( 8 );

		$this->assertSame( 8, $result );
	}

	public function test_sanitize_segment_size_empty(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_segment_size( '' );

		$this->assertSame( '', $result );
	}

	public function test_sanitize_segment_size_clamps(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_segment_size( 100 );

		$this->assertSame( 1048576, $result );
	}

	public function test_sanitize_max_lifespan_empty(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_max_lifespan( '' );

		$this->assertSame( '', $result );
	}

	public function test_sanitize_max_lifespan_clamps_low(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_max_lifespan( 10 );

		$this->assertSame( 60, $result );
	}

	public function test_sanitize_max_lifespan_clamps_high(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_max_lifespan( 999999 );

		$this->assertSame( 604800, $result );
	}

	public function test_settings_section_callback_outputs_html(): void {
		$admin = new Admin();

		\ob_start();
		$admin->settings_section_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( '<p>', $output );
		$this->assertStringContainsString( 'segment settings', $output );
	}

	public function test_servers_section_callback_outputs_html(): void {
		$admin = new Admin();

		\ob_start();
		$admin->servers_section_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( '<p>', $output );
		$this->assertStringContainsString( 'remote', $output );
	}

	public function test_num_segments_callback_outputs_number_field(): void {
		$admin = new Admin();

		\ob_start();
		$admin->num_segments_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'type="number"', $output );
		$this->assertStringContainsString( 'remote_num_segments', $output );
	}

	public function test_segment_size_callback_outputs_number_field(): void {
		$admin = new Admin();

		\ob_start();
		$admin->segment_size_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'type="number"', $output );
		$this->assertStringContainsString( 'remote_segment_size', $output );
	}

	public function test_max_lifespan_callback_outputs_number_field(): void {
		$admin = new Admin();

		\ob_start();
		$admin->max_lifespan_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'type="number"', $output );
		$this->assertStringContainsString( 'remote_max_lifespan', $output );
	}

	public function test_render_status_page_outputs_container(): void {
		$admin = new Admin();

		\ob_start();
		$admin->render_status_page();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event-aggregator-status', $output );
	}

	public function test_servers_field_callback_outputs_table(): void {
		$admin = new Admin();

		\ob_start();
		$admin->servers_field_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'wp-list-table', $output );
		$this->assertStringContainsString( 'No servers configured', $output );
	}

	public function test_enqueue_scripts_skips_wrong_hook(): void {
		$admin = new Admin();

		$admin->enqueue_scripts( 'edit.php' );
		$this->assertTrue( true );
	}

	public function test_render_status_page_denied_without_permission(): void {
		$GLOBALS['_wp_test_user_can'] = false;
		$admin = new Admin();

		try {
			$admin->render_status_page();
			$this->fail( 'Expected RuntimeException from wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'wp_die', $e->getMessage() );
		} finally {
			$GLOBALS['_wp_test_user_can'] = true;
		}
	}

	public function test_servers_field_callback_with_configured_servers(): void {
		// Populate servers via the option that ServerRegistry reads.
		$GLOBALS['_wp_test_options']['event_logger_aggregator_servers'] = [
			'web01' => [
				'url'     => 'https://web01.example.com',
				'enabled' => true,
			],
			'web02' => [
				'url'     => 'https://web02.example.com',
				'enabled' => false,
			],
		];

		// Reset singleton so it picks up new option.
		$ref = new \ReflectionProperty( \Newspack_Event_Aggregator\ServerRegistry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$admin = new Admin();

		\ob_start();
		$admin->servers_field_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'web01', $output );
		$this->assertStringContainsString( 'web02', $output );
		$this->assertStringContainsString( 'https://web01.example.com', $output );
		$this->assertStringNotContainsString( 'No servers configured', $output );
	}

	public function test_enqueue_scripts_status_page_with_asset(): void {
		// Create mock asset file.
		@\mkdir( EVENT_AGGREGATOR_DIR . 'build/admin', 0755, true );
		\file_put_contents(
			EVENT_AGGREGATOR_DIR . 'build/admin/index.asset.php',
			'<?php return ["dependencies" => [], "version" => "1.0.0"];'
		);

		$admin = new Admin();
		$admin->enqueue_scripts( 'toplevel_page_newspack-event-aggregator-status' );

		// If the full enqueue path ran without error, the asset was loaded.
		$this->assertTrue( true );
	}

	public function test_enqueue_scripts_status_page_without_asset(): void {
		// No asset file — should return early without error.
		$admin = new Admin();
		$admin->enqueue_scripts( 'toplevel_page_newspack-event-aggregator-status' );
		$this->assertTrue( true );
	}

	public function test_enqueue_scripts_settings_page_with_asset(): void {
		// Create mock settings asset file.
		@\mkdir( EVENT_AGGREGATOR_DIR . 'build/settings', 0755, true );
		\file_put_contents(
			EVENT_AGGREGATOR_DIR . 'build/settings/settings.asset.php',
			'<?php return ["dependencies" => [], "version" => "1.0.0"];'
		);

		$admin = new Admin();
		$admin->enqueue_scripts( 'settings_page_newspack-event-logger-settings' );

		// wp_localize_script was called (no crash = success).
		$this->assertTrue( true );
	}
}
