<?php
/**
 * Tests for Workers Admin (settings registration and sanitization).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Performance_Workers\Admin\Admin;

#[\PHPUnit\Framework\Attributes\CoversClass( Admin::class )]
class WorkersAdminTest extends TestCase {

	private array $saved_filters = [];

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_registered_settings'] = [];
		$GLOBALS['_wp_test_options']             = [];
		$GLOBALS['_wp_test_filters']             = [];
	}

	protected function tearDown(): void {
		Config::reset();
		$GLOBALS['_wp_test_filters'] = $this->saved_filters;
		unset( $GLOBALS['_wp_test_registered_settings'] );
		parent::tearDown();
	}

	public function test_constructor_registers_admin_init_action(): void {
		$admin = new Admin();

		$this->assertNotEmpty(
			$GLOBALS['_wp_test_filters']['admin_init'] ?? [],
			'Constructor should register admin_init action'
		);
	}

	public function test_register_settings_registers_options(): void {
		$admin = new Admin();
		$admin->register_settings();

		$registered = $GLOBALS['_wp_test_registered_settings'];

		$this->assertArrayHasKey( 'event_logger_enable_workers', $registered );
		$this->assertArrayHasKey( 'event_logger_auto_disable_threshold', $registered );
		$this->assertArrayHasKey( 'event_logger_auto_protect_time_threshold', $registered );
		$this->assertArrayHasKey( 'event_logger_significant_events', $registered );

		$this->assertSame( 'event_logger_options_group', $registered['event_logger_enable_workers']['group'] );
	}

	public function test_sanitize_array_option_with_json_string(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_array_option( '["a","b","c"]' );

		$this->assertSame( [ 'a', 'b', 'c' ], $result );
	}

	public function test_sanitize_array_option_with_array(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_array_option( [ 'x', '', 'y' ] );

		$this->assertSame( [ 'x', 'y' ], $result );
	}

	public function test_sanitize_array_option_with_non_array(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_array_option( 42 );

		$this->assertSame( [], $result );
	}

	public function test_sanitize_int_or_empty_with_empty(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_int_or_empty( '' );

		$this->assertSame( '', $result );
	}

	public function test_sanitize_int_or_empty_with_number(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_int_or_empty( '42' );

		$this->assertSame( 42, $result );
	}

	public function test_sanitize_int_or_empty_with_null(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_int_or_empty( null );

		$this->assertSame( '', $result );
	}

	public function test_sanitize_float_or_empty_with_empty(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_float_or_empty( '' );

		$this->assertSame( '', $result );
	}

	public function test_sanitize_float_or_empty_with_number(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_float_or_empty( '3.14' );

		$this->assertSame( 3.14, $result );
	}

	public function test_workers_section_callback_outputs_html(): void {
		$admin = new Admin();

		\ob_start();
		$admin->workers_section_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( '<p>', $output );
		$this->assertStringContainsString( 'disable noisy events', $output );
	}

	public function test_enable_workers_callback_outputs_checkbox(): void {
		$admin = new Admin();

		\ob_start();
		$admin->enable_workers_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'name="event_logger_enable_workers"', $output );
	}

	public function test_auto_tune_callback_outputs_fields(): void {
		$admin = new Admin();

		\ob_start();
		$admin->auto_tune_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'type="number"', $output );
		$this->assertStringContainsString( 'name="event_logger_auto_disable_threshold"', $output );
		$this->assertStringContainsString( 'name="event_logger_auto_protect_time_threshold"', $output );
	}

	public function test_significant_events_callback_outputs_tag_input(): void {
		$admin = new Admin();

		\ob_start();
		$admin->significant_events_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event-logger-tag-input', $output );
	}
}
