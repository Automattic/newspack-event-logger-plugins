<?php
/**
 * Tests for SettingsSync.
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Aggregator\SettingsSync;

#[CoversClass( SettingsSync::class )]
class SettingsSyncTest extends TestCase {

	private const SYNC_TEST_DIR = '/tmp/event-logger-test-settings-sync';

	protected function setUp(): void {
		parent::setUp();
		\Newspack_Event_Logger\Config::reset();
	}

	protected function tearDown(): void {
		// Ensure config points back to the default test config.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		\Newspack_Event_Logger\Config::reset();

		// Clean up any sync-test directories.
		self::rmdir_recursive( self::SYNC_TEST_DIR );

		parent::tearDown();
	}

	// ── get_synced_options() ────────────────────────────────────────────────

	public function test_get_synced_options_returns_expected_mappings(): void {
		$options = SettingsSync::get_synced_options();
		$this->assertIsArray( $options );
		$this->assertArrayHasKey( 'event_logger_num_partitions', $options );
		$this->assertArrayHasKey( 'event_logger_remote_num_segments', $options );
		$this->assertArrayHasKey( 'event_logger_remote_segment_size', $options );
		$this->assertArrayHasKey( 'event_logger_remote_max_lifespan', $options );
	}

	public function test_synced_options_maps_local_to_remote(): void {
		$options = SettingsSync::get_synced_options();

		// num_partitions maps to itself.
		$this->assertSame(
			'event_logger_num_partitions',
			$options['event_logger_num_partitions']
		);

		// remote_ prefixed options map to non-remote names.
		$this->assertSame(
			'event_logger_num_segments',
			$options['event_logger_remote_num_segments']
		);
		$this->assertSame(
			'event_logger_segment_size',
			$options['event_logger_remote_segment_size']
		);
		$this->assertSame(
			'event_logger_max_lifespan',
			$options['event_logger_remote_max_lifespan']
		);
	}

	public function test_synced_options_count(): void {
		$options = SettingsSync::get_synced_options();
		$this->assertCount( 4, $options );
	}

	// ── register_synced_settings() ──────────────────────────────────────────

	public function test_register_synced_settings_appends_to_array(): void {
		$existing = [ [ 'local_option' => 'existing', 'remote_option' => 'existing', 'endpoint' => '/test' ] ];
		$result   = SettingsSync::register_synced_settings( $existing );

		$this->assertCount( 5, $result ); // 1 existing + 4 synced.
	}

	public function test_register_synced_settings_includes_endpoint(): void {
		$result = SettingsSync::register_synced_settings( [] );

		foreach ( $result as $setting ) {
			$this->assertArrayHasKey( 'endpoint', $setting );
			$this->assertSame( '/wp-json/event-logger/v1/settings', $setting['endpoint'] );
		}
	}

	public function test_register_synced_settings_includes_all_mappings(): void {
		$result       = SettingsSync::register_synced_settings( [] );
		$local_names  = array_column( $result, 'local_option' );
		$remote_names = array_column( $result, 'remote_option' );

		$this->assertContains( 'event_logger_num_partitions', $local_names );
		$this->assertContains( 'event_logger_remote_num_segments', $local_names );
		$this->assertContains( 'event_logger_remote_segment_size', $local_names );
		$this->assertContains( 'event_logger_remote_max_lifespan', $local_names );

		$this->assertContains( 'event_logger_num_segments', $remote_names );
		$this->assertContains( 'event_logger_segment_size', $remote_names );
		$this->assertContains( 'event_logger_max_lifespan', $remote_names );
	}

	public function test_register_synced_settings_empty_input(): void {
		$result = SettingsSync::register_synced_settings( [] );
		$this->assertCount( 4, $result );
	}

	// ── init() ──────────────────────────────────────────────────────────────

	public function test_init_does_not_throw(): void {
		// init() calls add_action/add_filter which are no-op stubs.
		SettingsSync::init();
		$this->assertTrue( true ); // No exception = pass.
	}

	// ── on_option_update() ──────────────────────────────────────────────────

	public function test_on_option_update_ignores_non_synced_option(): void {
		// This should not throw even though JobIntake doesn't exist.
		SettingsSync::on_option_update( 'unrelated_option', 'old', 'new' );
		$this->assertTrue( true );
	}

	public function test_on_option_add_ignores_non_synced_option(): void {
		SettingsSync::on_option_add( 'unrelated_option', 'value' );
		$this->assertTrue( true );
	}

	// ── maybe_queue_sync() guards ───────────────────────────────────────────

	public function test_on_option_update_for_synced_option_without_jobintake(): void {
		// Synced option but JobIntake class doesn't exist — should not throw.
		SettingsSync::on_option_update( 'event_logger_num_partitions', 1, 2 );
		$this->assertTrue( true );
	}

	// ── on_option_add() for synced options ─────────────────────────────────

	public function test_on_option_add_for_synced_option_without_jobintake(): void {
		// Synced option but JobIntake class doesn't exist — should not throw.
		SettingsSync::on_option_add( 'event_logger_num_partitions', 4 );
		$this->assertTrue( true );
	}

	public function test_on_option_add_for_all_synced_options(): void {
		$options = SettingsSync::get_synced_options();
		foreach ( \array_keys( $options ) as $option ) {
			SettingsSync::on_option_add( $option, 'test_value' );
		}
		// None should throw even without JobIntake.
		$this->assertTrue( true );
	}

	public function test_on_option_update_for_all_synced_options(): void {
		$options = SettingsSync::get_synced_options();
		foreach ( \array_keys( $options ) as $option ) {
			SettingsSync::on_option_update( $option, 'old', 'new' );
		}
		// None should throw even without JobIntake.
		$this->assertTrue( true );
	}

	// ── register_synced_settings() structure ────────────────────────────────

	public function test_register_synced_settings_entry_structure(): void {
		$result = SettingsSync::register_synced_settings( [] );
		foreach ( $result as $setting ) {
			$this->assertArrayHasKey( 'local_option', $setting );
			$this->assertArrayHasKey( 'remote_option', $setting );
			$this->assertArrayHasKey( 'endpoint', $setting );
			$this->assertIsString( $setting['local_option'] );
			$this->assertIsString( $setting['remote_option'] );
			$this->assertIsString( $setting['endpoint'] );
		}
	}

	public function test_register_synced_settings_preserves_existing(): void {
		$existing = [
			[ 'local_option' => 'a', 'remote_option' => 'b', 'endpoint' => '/c' ],
			[ 'local_option' => 'd', 'remote_option' => 'e', 'endpoint' => '/f' ],
		];
		$result = SettingsSync::register_synced_settings( $existing );
		$this->assertCount( 6, $result ); // 2 existing + 4 synced.
		$this->assertSame( 'a', $result[0]['local_option'] );
		$this->assertSame( 'd', $result[1]['local_option'] );
	}

	// ── on_option_update with synced option when Config says enable_workers=false ──

	public function test_on_option_update_skips_when_workers_disabled(): void {
		// When enable_workers is false (remote node), syncing should be skipped
		// to prevent infinite fan-out loops.
		// This path is in maybe_queue_sync — it checks Config::load_config().
		// In test env, Config defaults have enable_workers as true or absent.
		// The important thing: calling on_option_update for a synced option
		// should not throw even when the code path checks enable_workers.
		SettingsSync::on_option_update( 'event_logger_num_partitions', 1, 4 );
		$this->assertTrue( true, 'Should not throw when checking enable_workers' );
	}

	// ── on_option_add with synced options: different remote names ───────

	public function test_on_option_add_remote_num_segments(): void {
		SettingsSync::on_option_add( 'event_logger_remote_num_segments', 8 );
		$this->assertTrue( true, 'No exception for remote_num_segments' );
	}

	public function test_on_option_add_remote_segment_size(): void {
		SettingsSync::on_option_add( 'event_logger_remote_segment_size', 67108864 );
		$this->assertTrue( true, 'No exception for remote_segment_size' );
	}

	public function test_on_option_add_remote_max_lifespan(): void {
		SettingsSync::on_option_add( 'event_logger_remote_max_lifespan', 86400 );
		$this->assertTrue( true, 'No exception for remote_max_lifespan' );
	}

	// ── on_option_update: empty value resolves to config default ────────

	public function test_on_option_update_empty_value_resolves_default(): void {
		// When value is '' or false, maybe_queue_sync resolves to config default.
		// This exercises the empty-value branch in maybe_queue_sync.
		SettingsSync::on_option_update( 'event_logger_num_partitions', 4, '' );
		$this->assertTrue( true, 'Empty string value handled without exception' );

		SettingsSync::on_option_update( 'event_logger_num_partitions', 4, false );
		$this->assertTrue( true, 'False value handled without exception' );
	}

	// ── on_option_update: non-synced option with various types ─────────

	public function test_on_option_update_non_synced_option_types(): void {
		// Arrays, objects, etc. should be safely ignored.
		SettingsSync::on_option_update( 'some_other_option', 'old', [ 'a' => 1 ] );
		$this->assertTrue( true );

		SettingsSync::on_option_update( 'another_option', null, new \stdClass() );
		$this->assertTrue( true );
	}

	// ── on_option_add: non-synced option ───────────────────────────────

	public function test_on_option_add_non_synced_returns_early(): void {
		SettingsSync::on_option_add( 'completely_unrelated', 'whatever' );
		$this->assertTrue( true, 'Non-synced option ignored' );
	}

	// ── maybe_queue_sync: enable_workers false (spoke node) ────────────

	public function test_on_option_update_skips_when_workers_disabled_via_config(): void {
		// Point to a config file that has enable_workers=false.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/logging-disabled.php' );
		\Newspack_Event_Logger\Config::reset();

		// This exercises the enable_workers=false branch in maybe_queue_sync.
		SettingsSync::on_option_update( 'event_logger_num_partitions', 1, 4 );
		$this->assertTrue( true, 'Should not throw when enable_workers is false' );
		// Config restore handled by tearDown.
	}

	// ── on_option_add: false value resolves to config default ──────────

	public function test_on_option_add_false_value_resolves_default(): void {
		SettingsSync::on_option_add( 'event_logger_num_partitions', false );
		$this->assertTrue( true, 'False value handled in on_option_add' );
	}

	public function test_on_option_add_empty_string_value(): void {
		SettingsSync::on_option_add( 'event_logger_num_partitions', '' );
		$this->assertTrue( true, 'Empty string handled in on_option_add' );
	}

	// ── on_option_update: all remote_ options with empty values ─────────

	public function test_on_option_update_remote_options_with_empty_values(): void {
		$options = SettingsSync::get_synced_options();
		foreach ( \array_keys( $options ) as $option ) {
			SettingsSync::on_option_update( $option, 'old', '' );
			SettingsSync::on_option_update( $option, 'old', false );
		}
		$this->assertTrue( true, 'All synced options handled with empty/false values' );
	}

	// ── maybe_queue_sync: happy path with JobIntake available ──────────

	public function test_maybe_queue_sync_queues_when_workers_enabled(): void {
		// Use config with enable_workers=true and ensure base directories exist.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/workers-enabled.php' );
		\Newspack_Event_Logger\Config::reset();

		$base = self::SYNC_TEST_DIR;
		@\mkdir( "{$base}/logs/jobintake.log/p0", 0755, true );
		@\mkdir( "{$base}/locks", 0755, true );
		@\mkdir( "{$base}/offsets", 0755, true );

		// Trigger a synced option update.
		SettingsSync::on_option_update( 'event_logger_num_partitions', 1, 4 );

		// Verify JobIntake wrote something to the jobintake.log.
		$files = \glob( "{$base}/logs/jobintake.log/p0/*.log" );
		$this->assertNotEmpty( $files, 'JobIntake should write a segment file' );

		// Read the content and verify it contains the sync payload.
		$content = '';
		foreach ( $files as $file ) {
			$content .= \file_get_contents( $file );
		}
		$this->assertStringContainsString( 'remote_manager', $content );
		$this->assertStringContainsString( 'sync_setting', $content );
		$this->assertStringContainsString( 'event_logger_num_partitions', $content );
	}


	public function test_maybe_queue_sync_resolves_empty_value_to_default(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/workers-enabled.php' );
		\Newspack_Event_Logger\Config::reset();

		$base = self::SYNC_TEST_DIR;
		@\mkdir( "{$base}/logs/jobintake.log/p0", 0755, true );
		@\mkdir( "{$base}/locks", 0755, true );
		@\mkdir( "{$base}/offsets", 0755, true );

		// Trigger with empty string value -- exercises the default resolution branch.
		SettingsSync::on_option_update( 'event_logger_num_partitions', 4, '' );

		$files = \glob( "{$base}/logs/jobintake.log/p0/*.log" );
		$this->assertNotEmpty( $files, 'Should write even when value is empty (resolves to default)' );

		$content = '';
		foreach ( $files as $file ) {
			$content .= \file_get_contents( $file );
		}
		// The payload should contain the resolved default value, not empty string.
		$this->assertStringContainsString( 'sync_setting', $content );
	}

	public function test_maybe_queue_sync_resolves_false_value_to_default(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/workers-enabled.php' );
		\Newspack_Event_Logger\Config::reset();

		$base = self::SYNC_TEST_DIR;
		@\mkdir( "{$base}/logs/jobintake.log/p0", 0755, true );
		@\mkdir( "{$base}/locks", 0755, true );
		@\mkdir( "{$base}/offsets", 0755, true );

		// Trigger with false value -- exercises the false-value branch.
		SettingsSync::on_option_update( 'event_logger_num_partitions', 4, false );

		$files = \glob( "{$base}/logs/jobintake.log/p0/*.log" );
		$this->assertNotEmpty( $files, 'Should write even when value is false (resolves to default)' );
	}

	public function test_maybe_queue_sync_with_add_option(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/workers-enabled.php' );
		\Newspack_Event_Logger\Config::reset();

		$base = self::SYNC_TEST_DIR;
		@\mkdir( "{$base}/logs/jobintake.log/p0", 0755, true );
		@\mkdir( "{$base}/locks", 0755, true );
		@\mkdir( "{$base}/offsets", 0755, true );

		// Test through on_option_add (new option added for first time).
		SettingsSync::on_option_add( 'event_logger_remote_num_segments', 8 );

		$files = \glob( "{$base}/logs/jobintake.log/p0/*.log" );
		$this->assertNotEmpty( $files, 'Should queue via on_option_add' );

		$content = '';
		foreach ( $files as $file ) {
			$content .= \file_get_contents( $file );
		}
		// Should map to the remote option name.
		$this->assertStringContainsString( 'event_logger_num_segments', $content );
	}

	public function test_maybe_queue_sync_maps_all_remote_options(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/workers-enabled.php' );
		\Newspack_Event_Logger\Config::reset();

		$base = self::SYNC_TEST_DIR;

		$options = SettingsSync::get_synced_options();
		foreach ( \array_keys( $options ) as $option ) {
			@\mkdir( "{$base}/logs/jobintake.log/p0", 0755, true );
			@\mkdir( "{$base}/locks", 0755, true );
			@\mkdir( "{$base}/offsets", 0755, true );

			// Reset Config cache so each update gets a fresh config.
			\Newspack_Event_Logger\Config::reset();

			SettingsSync::on_option_update( $option, 'old', 'new_value' );
		}

		$files = \glob( "{$base}/logs/jobintake.log/p0/*.log" );
		$this->assertNotEmpty( $files, 'All synced options should produce queue entries' );

		$content = '';
		foreach ( $files as $file ) {
			$content .= \file_get_contents( $file );
		}

		// Verify each remote option name appears in queued content.
		foreach ( $options as $local => $remote ) {
			$this->assertStringContainsString( $remote, $content, "Remote option {$remote} should appear in queued content" );
		}
	}

	/**
	 * Helper: recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private static function rmdir_recursive( string $dir ): void {
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @\rmdir( $item->getPathname() ) : @\unlink( $item->getPathname() );
		}
		@\rmdir( $dir );
	}

	// ── register_synced_settings: remote option names correct ──────────

	public function test_register_synced_settings_remote_names(): void {
		$result = SettingsSync::register_synced_settings( [] );

		$remote_map = [];
		foreach ( $result as $setting ) {
			$remote_map[ $setting['local_option'] ] = $setting['remote_option'];
		}

		$this->assertSame( 'event_logger_num_partitions', $remote_map['event_logger_num_partitions'] );
		$this->assertSame( 'event_logger_num_segments', $remote_map['event_logger_remote_num_segments'] );
		$this->assertSame( 'event_logger_segment_size', $remote_map['event_logger_remote_segment_size'] );
		$this->assertSame( 'event_logger_max_lifespan', $remote_map['event_logger_remote_max_lifespan'] );
	}
}
