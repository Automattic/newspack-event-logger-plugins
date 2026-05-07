<?php
/**
 * Tests for Newspack_Performance_Aggregator\SettingsSync.
 *
 * Distinct from the existing SettingsSyncTest, which tests
 * Newspack_Event_Aggregator\SettingsSync (a separate class that syncs
 * different options with separate hub-gating logic).
 *
 * @package Newspack_Performance_Aggregator
 */

namespace Newspack_Performance_Aggregator\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Performance_Aggregator\SettingsSync;

#[CoversClass( SettingsSync::class )]
class PerfAggregatorSettingsSyncTest extends TestCase {

	private const SYNC_TEST_DIR = '/tmp/event-logger-test-settings-sync';

	protected function setUp(): void {
		parent::setUp();
		\Newspack_Event_Logger\Config::reset();
	}

	protected function tearDown(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		\Newspack_Event_Logger\Config::reset();
		self::rmdir_recursive( self::SYNC_TEST_DIR );
	}

	private static function rmdir_recursive( string $dir ): void {
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		$items = \scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = "{$dir}/{$item}";
			if ( \is_dir( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				@\unlink( $path );
			}
		}
		@\rmdir( $dir );
	}

	private function setup_sync_dirs(): string {
		$base = self::SYNC_TEST_DIR;
		@\mkdir( "{$base}/logs/jobintake.log/p0", 0755, true );
		@\mkdir( "{$base}/locks", 0755, true );
		@\mkdir( "{$base}/offsets", 0755, true );
		return $base;
	}

	private function jobintake_grew_during( callable $action ): bool {
		$base   = $this->setup_sync_dirs();
		$before = \glob( "{$base}/logs/jobintake.log/p0/*.log" ) ?: [];
		$sizes  = [];
		foreach ( $before as $f ) {
			$sizes[ $f ] = \filesize( $f );
		}

		$action();

		$after = \glob( "{$base}/logs/jobintake.log/p0/*.log" ) ?: [];
		foreach ( $after as $f ) {
			if ( ! isset( $sizes[ $f ] ) || \filesize( $f ) > $sizes[ $f ] ) {
				return true;
			}
		}
		return false;
	}

	// ── happy path: enable_workers=true syncs ──────────────────────────

	public function test_maybe_queue_sync_queues_when_workers_enabled(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/workers-enabled.php' );
		\Newspack_Event_Logger\Config::reset();

		$grew = $this->jobintake_grew_during( function () {
			SettingsSync::on_option_update( 'event_logger_log_urls', 'old', 'new' );
		} );
		$this->assertTrue( $grew, 'Hub (enable_workers=true) should write a sync job to jobintake.log' );
	}

	// ── fail-closed: enable_workers=false skips ─────────────────────────

	public function test_maybe_queue_sync_skips_when_workers_disabled(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/logging-disabled.php' );
		\Newspack_Event_Logger\Config::reset();

		$grew = $this->jobintake_grew_during( function () {
			SettingsSync::on_option_update( 'event_logger_log_urls', 'old', 'new' );
		} );
		$this->assertFalse( $grew, 'Spoke (enable_workers=false) should skip the sync' );
	}

	// ── fail-closed: enable_workers unset skips (regression test for the
	//    polarity-flip fix) ──────────────────────────────────────────────
	//
	// Pre-fix logic was `isset && false === $config['enable_workers']`, which
	// let missing/null/empty enable_workers count as "yes hub-mode" and silently
	// fanned tuning settings out to remote spokes — contradicting the file's own
	// comment "Only hub nodes (enable_workers=true) should sync settings."
	// Post-fix matches event-aggregator's fail-closed polarity: missing
	// enable_workers means we are NOT a hub.

	public function test_maybe_queue_sync_skips_when_workers_unset(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/workers-unset.php' );
		\Newspack_Event_Logger\Config::reset();

		$grew = $this->jobintake_grew_during( function () {
			SettingsSync::on_option_update( 'event_logger_log_urls', 'old', 'new' );
		} );
		$this->assertFalse( $grew, 'Sync should skip when enable_workers is unset (fail-closed)' );
	}

	// ── re-entrancy guard: $syncing flag suppresses sync ──────────────

	public function test_maybe_queue_sync_skips_when_syncing_flag_set(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/workers-enabled.php' );
		\Newspack_Event_Logger\Config::reset();

		$grew = $this->jobintake_grew_during( function () {
			SettingsSync::suppress_sync( true );
			try {
				SettingsSync::on_option_update( 'event_logger_log_urls', 'old', 'new' );
			} finally {
				SettingsSync::suppress_sync( false );
			}
		} );
		$this->assertFalse( $grew, 'Sync should skip while $syncing flag is set' );
	}

	// ── unsynced option is ignored ─────────────────────────────────────

	public function test_maybe_queue_sync_ignores_unsynced_option(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/workers-enabled.php' );
		\Newspack_Event_Logger\Config::reset();

		$grew = $this->jobintake_grew_during( function () {
			SettingsSync::on_option_update( 'event_logger_unrelated_option', 'old', 'new' );
		} );
		$this->assertFalse( $grew, 'Non-synced option should not trigger a write' );
	}
}
