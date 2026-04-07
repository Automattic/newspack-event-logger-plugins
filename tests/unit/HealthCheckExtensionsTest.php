<?php
/**
 * Tests for HealthCheckExtensions.
 *
 * Covers the merge_hooks and merge_events cap logic that prevents
 * unbounded option growth from remote server discovery.
 *
 * @package Newspack_Performance_Aggregator
 */

namespace Newspack_Performance_Aggregator\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Performance_Aggregator\HealthCheckExtensions;

#[CoversClass( HealthCheckExtensions::class )]
class HealthCheckExtensionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options'] = [];
		parent::tearDown();
	}

	// ── merge_hooks cap ─────────────────────────────────────────────────

	public function test_merge_hooks_caps_at_max_events(): void {
		// Pre-populate event_logger_log_events with 9999 entries.
		$existing = [];
		for ( $i = 0; $i < 9999; $i++ ) {
			$existing[] = "existing_hook_{$i}";
		}
		\update_option( 'event_logger_log_events', $existing );

		// Provide 100 new hooks via discovery.
		$new_hooks = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$new_hooks[] = "new_hook_{$i}";
		}

		$discovery = [
			'server_1' => [
				'registered_hooks' => $new_hooks,
				'custom_events'    => [],
			],
		];

		HealthCheckExtensions::process_discovery( $discovery );

		$result = \get_option( 'event_logger_log_events', [] );
		$this->assertIsArray( $result );
		// 9999 existing + 100 new = 10099 would exceed cap.
		// Cap is 10000, so at most 10000 entries.
		$this->assertLessThanOrEqual( 10000, \count( $result ), 'Merged hooks should be capped at MAX_EVENTS (10000)' );
		// Should have gained at least 1 new hook (9999 < 10000).
		$this->assertGreaterThan( 9999, \count( $result ), 'Should have merged at least 1 new hook' );
	}

	// ── merge_events cap ────────────────────────────────────────────────

	public function test_merge_events_caps_at_max_events(): void {
		// Pre-populate event_logger_discovered_events with 9999 entries.
		$existing = [];
		for ( $i = 0; $i < 9999; $i++ ) {
			$existing[ "existing_event_{$i}" ] = true;
		}
		\update_option( 'event_logger_discovered_events', $existing );

		// Provide 100 new events via discovery.
		$new_events = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$new_events[] = "new_event_{$i}";
		}

		$discovery = [
			'server_1' => [
				'registered_hooks' => [],
				'custom_events'    => $new_events,
			],
		];

		HealthCheckExtensions::process_discovery( $discovery );

		$result = \get_option( 'event_logger_discovered_events', [] );
		$this->assertIsArray( $result );
		// 9999 existing + 100 new = 10099 would exceed cap.
		// Cap is 10000, so at most 10000 entries.
		$this->assertLessThanOrEqual( 10000, \count( $result ), 'Merged events should be capped at MAX_EVENTS (10000)' );
		// Should have gained at least 1 new event (9999 < 10000).
		$this->assertGreaterThan( 9999, \count( $result ), 'Should have merged at least 1 new event' );
	}
}
