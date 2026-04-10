<?php
/**
 * Tests for Jobs Admin (settings registration).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Jobs\Admin\Admin;

#[\PHPUnit\Framework\Attributes\CoversClass( Admin::class )]
class JobsAdminTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$GLOBALS['_wp_test_registered_settings'] = [];
		$GLOBALS['_wp_test_options']             = [];
	}

	protected function tearDown(): void {
		Config::reset();
		unset( $GLOBALS['_wp_test_registered_settings'] );
		parent::tearDown();
	}

	public function test_constructor_registers_admin_init_action(): void {
		$admin = new Admin();

		// add_action stores callbacks in the test filters global.
		$this->assertNotEmpty(
			$GLOBALS['_wp_test_filters']['admin_init'] ?? [],
			'Constructor should register admin_init action'
		);
	}

	public function test_register_settings_registers_option(): void {
		$admin = new Admin();
		$admin->register_settings();

		$this->assertArrayHasKey(
			'event_logger_enable_jobs',
			$GLOBALS['_wp_test_registered_settings'],
			'register_settings should register the enable_jobs option'
		);

		$entry = $GLOBALS['_wp_test_registered_settings']['event_logger_enable_jobs'];
		$this->assertSame( 'event_logger_options_group', $entry['group'] );
	}

	public function test_section_callback_outputs_html(): void {
		$admin = new Admin();

		\ob_start();
		$admin->section_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( '<p>', $output );
		$this->assertStringContainsString( 'Background job processing', $output );
	}

	public function test_enable_jobs_callback_outputs_checkbox(): void {
		$admin = new Admin();

		\ob_start();
		$admin->enable_jobs_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'name="event_logger_enable_jobs"', $output );
	}

	public function test_enable_jobs_callback_checked_when_enabled(): void {
		$GLOBALS['_wp_test_options']['event_logger_enable_jobs'] = 1;

		$admin = new Admin();

		\ob_start();
		$admin->enable_jobs_callback();
		$output = \ob_get_clean();

		$this->assertStringContainsString( "checked='checked'", $output );
	}

	public function test_enable_jobs_callback_unchecked_when_disabled(): void {
		$GLOBALS['_wp_test_options']['event_logger_enable_jobs'] = 0;

		$admin = new Admin();

		\ob_start();
		$admin->enable_jobs_callback();
		$output = \ob_get_clean();

		$this->assertStringNotContainsString( "checked='checked'", $output );
	}
}
