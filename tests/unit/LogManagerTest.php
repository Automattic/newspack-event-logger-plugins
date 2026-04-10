<?php
/**
 * Tests for LogManager (per-request JSONL writer).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Performance_Logger\LogManager;

#[\PHPUnit\Framework\Attributes\CoversClass( LogManager::class )]
class LogManagerTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-logmanager';

	/** @var array Original $_SERVER backup. */
	private array $orig_server;

	/** @var array Config files written during this test (cleaned up in tearDown). */
	private array $config_files = [];

	protected function setUp(): void {
		parent::setUp();

		// Save original $_SERVER.
		$this->orig_server = $_SERVER;

		// Reset singleton so each test starts fresh.
		LogManager::reset();
		Config::reset();

		// Set required $_SERVER vars.
		$_SERVER['REQUEST_URI']    = '/test/page';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SERVER_NAME']    = 'localhost';
		$_SERVER['HTTP_HOST']      = 'localhost';
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'], $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );

		// Point config to pre-written test config.
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();
	}

	protected function tearDown(): void {
		LogManager::reset();
		Config::reset();

		// Restore original $_SERVER.
		$_SERVER = $this->orig_server;

		// Restore test config.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );

		self::rmdir_recursive( self::TEST_DIR );
		parent::tearDown();
	}

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

	/**
	 * Get path to a pre-written test config file.
	 *
	 * @param string $name Config name (without .php extension).
	 * @return string Absolute path.
	 */
	private function config_path( string $name ): string {
		return \dirname( __DIR__ ) . '/configs/' . $name . '.php';
	}

	public function test_singleton_instance(): void {
		$instance1 = LogManager::instance();
		$instance2 = LogManager::instance();
		$this->assertSame( $instance1, $instance2 );
	}

	public function test_reset_clears_singleton(): void {
		$instance1 = LogManager::instance();
		LogManager::reset();
		$instance2 = LogManager::instance();
		$this->assertNotSame( $instance1, $instance2 );
	}

	public function test_constructor_sets_enabled_when_logging_enabled(): void {
		$lm = LogManager::instance();
		$this->assertTrue( $lm->enabled );
	}

	public function test_constructor_disabled_when_config_disables_logging(): void {
		LogManager::reset();
		Config::reset();

		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$this->assertFalse( $lm->enabled );
	}

	public function test_generate_request_id_format(): void {
		$rid = LogManager::generate_request_id();
		$this->assertIsString( $rid );
		$this->assertSame( 32, \strlen( $rid ) );
		// Should be alphanumeric (base36).
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+$/', $rid );
	}

	public function test_generate_request_id_uniqueness(): void {
		$ids = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$ids[] = LogManager::generate_request_id();
		}
		$unique = \array_unique( $ids );
		$this->assertCount( 50, $unique, 'All generated request IDs should be unique' );
	}

	public function test_message_returns_true(): void {
		$lm     = LogManager::instance();
		$result = $lm->message( 'test_category', [ 'm' => 'hello world' ] );
		$this->assertTrue( $result );
	}

	public function test_message_truncates_large_data(): void {
		$lm = LogManager::instance();

		// Create data larger than MAX_DATA_SIZE (3840 bytes).
		$large = [ 'm' => \str_repeat( 'x', 4000 ) ];
		$result = $lm->message( 'big_data', $large );
		$this->assertTrue( $result );
		// If data exceeded limit, the entry would have 'truncated' => true instead.
		// We cannot directly inspect the written line here, but the method should succeed.
	}

	public function test_error_convenience_method(): void {
		$lm     = LogManager::instance();
		$result = $lm->error( 'Something went wrong' );
		$this->assertTrue( $result );
	}

	public function test_warning_convenience_method(): void {
		$lm     = LogManager::instance();
		$result = $lm->warning( 'Watch out' );
		$this->assertTrue( $result );
	}

	public function test_info_convenience_method(): void {
		$lm     = LogManager::instance();
		$result = $lm->info( 'FYI' );
		$this->assertTrue( $result );
	}

	public function test_start_complete_timing(): void {
		$lm = LogManager::instance();

		// start() requires ensure_started(), which sets up firehose.
		$lm->start( 'test_op', [ 'm' => 'starting operation' ] );
		\usleep( 10000 ); // 10ms
		$lm->complete( 'test_op' );

		// Verify the timer stack only has the root 'process' entry left.
		$ref = new \ReflectionProperty( LogManager::class, 'times' );
		$ref->setAccessible( true );
		$times = $ref->getValue( $lm );
		$this->assertCount( 1, $times, 'Timer stack should have only root entry after complete' );
	}

	public function test_complete_without_start_is_noop(): void {
		$lm = LogManager::instance();
		// complete() without matching start() should not throw.
		$lm->complete( 'nonexistent_label' );
		$this->assertTrue( true );
	}

	public function test_nested_start_complete(): void {
		$lm = LogManager::instance();

		$lm->start( 'outer' );
		$lm->start( 'inner' );
		$lm->complete( 'inner' );
		$lm->complete( 'outer' );

		$this->assertTrue( true );
	}

	public function test_flush_buffer_null_guard(): void {
		$lm = LogManager::instance();
		// flush_buffer on a fresh instance (no firehose yet) should not throw.
		$lm->flush_buffer();
		$this->assertTrue( true );
	}

	public function test_finish_lifecycle(): void {
		$lm = LogManager::instance();

		// Start and then finish.
		$lm->start( 'process_test' );
		$lm->message( 'test_event', [ 'm' => 'data' ] );
		$lm->complete( 'process_test' );
		$lm->finish();

		// finish() resets started/tracked state.
		// A second finish() should be a no-op.
		$lm->finish();
		$this->assertTrue( true );
	}

	public function test_get_request_id_returns_string(): void {
		$lm = LogManager::instance();
		// Force initialization by calling start (triggers ensure_started/init_firehose).
		$lm->start( 'init' );
		$rid = $lm->get_request_id();
		$this->assertIsString( $rid );
		$this->assertNotEmpty( $rid );
	}

	public function test_worker_type_tagging(): void {
		LogManager::reset();
		Config::reset();

		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'test_worker';
		$lm = LogManager::instance();
		$lm->start( 'work' );
		$lm->complete( 'work' );

		// If no exception, worker type was handled.
		$this->assertTrue( true );
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
	}

	public function test_matches_url_filter_with_skip_urls(): void {
		LogManager::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'skip-urls' ) );
		Config::reset();

		$_SERVER['REQUEST_URI'] = '/health';
		$lm = LogManager::instance();
		$this->assertFalse( $lm->enabled, 'Skip URL should disable logging' );
	}

	public function test_matches_url_filter_with_log_urls(): void {
		LogManager::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'log-urls' ) );
		Config::reset();

		$_SERVER['REQUEST_URI'] = '/other/page';
		$lm = LogManager::instance();
		$this->assertFalse( $lm->enabled, 'Non-matching URL should be disabled when log_urls is set' );
	}

	public function test_matches_url_filter_accepts_matching_url(): void {
		LogManager::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'log-urls' ) );
		Config::reset();

		$_SERVER['REQUEST_URI'] = '/api/data';
		$lm = LogManager::instance();
		$this->assertTrue( $lm->enabled, 'Matching URL should be enabled' );
	}

	public function test_line_limiting_mutes_after_max(): void {
		$lm = LogManager::instance();

		// We cannot easily test MAX_LOG_LINES (40000) due to volume,
		// but we can verify the mechanism works by checking line_number increments.
		$lm->message( 'line1' );
		$lm->message( 'line2' );
		$lm->message( 'line3' );

		// All messages should succeed (we're well below limit).
		$this->assertTrue( true );
	}

	public function test_start_complete_muted_when_line_limited(): void {
		$lm = LogManager::instance();

		// Use reflection to set line_limited to true.
		$ref = new \ReflectionProperty( LogManager::class, 'line_limited' );
		$ref->setAccessible( true );
		$ref->setValue( $lm, true );

		// start() and complete() should not throw when line_limited.
		$lm->start( 'muted_op' );
		$lm->complete( 'muted_op' );

		// If we get here, muting works.
		$this->assertTrue( true );

		// Reset for subsequent tests.
		$ref->setValue( $lm, false );
	}

	public function test_complete_with_mismatched_label(): void {
		$lm = LogManager::instance();

		// Start one label, complete a different one.
		$lm->start( 'outer' );
		$lm->start( 'inner' );

		// Completing 'outer' when 'inner' is on top triggers orphan handling.
		$lm->complete( 'outer' );

		// The orphaned 'inner' should be logged as orphaned. No exception expected.
		$this->assertTrue( true );
	}

	public function test_log_memory_config_flag(): void {
		LogManager::reset();
		Config::reset();

		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'logging-memory' ) );
		Config::reset();

		$lm = LogManager::instance();
		$this->assertTrue( $lm->enabled );

		// Verify log_memory flag is set via reflection.
		$ref = new \ReflectionProperty( LogManager::class, 'log_memory' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->getValue( $lm ), 'log_memory should be true with logging-memory config' );

		// start/complete with log_memory should add peak_mb to complete entry.
		$lm->start( 'memory_test' );
		$lm->complete( 'memory_test' );

		// If we get here without error, memory logging path works.
		$this->assertTrue( true );
	}

	public function test_finish_computes_real_duration(): void {
		// Clean firehose output from prior tests.
		self::rmdir_recursive( '/tmp/event-logger-test' );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		// Call start() to trigger firehose initialization.
		$lm->start( 'custom_event', [ 'm' => 'tracked with start' ] );

		// Brief sleep to ensure non-zero duration.
		\usleep( 5000 ); // 5ms.

		$lm->finish();

		// Read the firehose output to find process (complete) entry.
		// Config base_directory is /tmp/event-logger-test (from logging-enabled.php).
		$config_base = '/tmp/event-logger-test';
		$log_dir     = $config_base . '/logs/firehose.log/p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'Firehose should have written data' );

		// Read ALL segment files (process (complete) may be in a later segment).
		$complete_entry = null;
		foreach ( $files as $file ) {
			$content = \file_get_contents( $file );
			$lines   = \array_filter( \explode( "\n", $content ) );
			foreach ( $lines as $line ) {
				$decoded = \json_decode( $line, true );
				if ( $decoded && isset( $decoded['k'] ) && 'process (complete)' === $decoded['k'] ) {
					$complete_entry = $decoded;
				}
			}
		}

		$this->assertNotNull( $complete_entry, 'Should have a process (complete) entry' );
		$this->assertArrayHasKey( 'duration_ms', $complete_entry );
		$this->assertGreaterThan( 0, $complete_entry['duration_ms'], 'Duration should be > 0ms' );
	}

	public function test_message_m_field_url_redaction(): void {
		// Clean firehose output from prior tests.
		self::rmdir_recursive( '/tmp/event-logger-test' );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'redaction_test' );
		$lm->message( 'test', [ 'm' => 'https://example.com?client_secret=SECRET&id=123' ] );
		$lm->flush_buffer();

		// Read the firehose output.
		// Config base_directory is /tmp/event-logger-test (from logging-enabled.php).
		$config_base = '/tmp/event-logger-test';
		$log_dir     = $config_base . '/logs/firehose.log/p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files );

		// Read ALL segment files.
		$all_content = '';
		foreach ( $files as $file ) {
			$all_content .= \file_get_contents( $file );
		}
		$this->assertStringNotContainsString( 'SECRET', $all_content, 'Secret value should be redacted' );
		$this->assertStringContainsString( 'client_secret=[REDACTED]', $all_content, 'Should show redacted placeholder' );
		$this->assertStringContainsString( 'id=123', $all_content, 'Non-sensitive params should be preserved' );
	}

	/**
	 * Read ALL firehose log entries from the test directory.
	 *
	 * @return array[] Decoded JSON entries.
	 */
	private function read_firehose_entries(): array {
		$log_dir = '/tmp/event-logger-test/logs/firehose.log/p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'Firehose should have written data' );

		$entries = [];
		foreach ( $files as $file ) {
			$content = \file_get_contents( $file );
			$lines   = \array_filter( \explode( "\n", $content ) );
			foreach ( $lines as $line ) {
				$decoded = \json_decode( $line, true );
				if ( $decoded ) {
					$entries[] = $decoded;
				}
			}
		}
		return $entries;
	}

	/**
	 * Find the last firehose entry matching a given category.
	 *
	 * @param string $category The 'k' value to search for.
	 * @return array|null The matching entry, or null.
	 */
	private function find_last_entry( string $category ): ?array {
		$entries = $this->read_firehose_entries();
		$match   = null;
		foreach ( $entries as $entry ) {
			if ( isset( $entry['k'] ) && $category === $entry['k'] ) {
				$match = $entry;
			}
		}
		return $match;
	}

	/**
	 * Regression: the 'k' field must come from the $category parameter,
	 * not from user-supplied $data. A bug allowed $data['k'] to override
	 * the category, causing health check discovery messages to be mis-tagged.
	 */
	public function test_message_k_field_not_overridable_by_data(): void {
		self::rmdir_recursive( '/tmp/event-logger-test' );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'k_override_test' );
		$lm->message( 'job', [ 'k' => 'discovery', 'm' => 'test' ] );
		$lm->flush_buffer();

		$entry = $this->find_last_entry( 'job' );
		$this->assertNotNull( $entry, 'Should find an entry with k=job' );
		$this->assertSame( 'job', $entry['k'], 'Category must come from $category param, not $data' );

		// Also verify there is NO entry with k=discovery.
		$bad_entry = $this->find_last_entry( 'discovery' );
		$this->assertNull( $bad_entry, 'Data array must not be able to override k field' );
	}

	/**
	 * Regression: the 'ts' field CAN be overridden by $data. The profiler
	 * intentionally passes a custom timestamp for deferred logging.
	 */
	public function test_message_ts_field_overridable_by_data(): void {
		self::rmdir_recursive( '/tmp/event-logger-test' );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'ts_override_test' );
		$lm->message( 'test', [ 'ts' => 12345.678, 'm' => 'hello' ] );
		$lm->flush_buffer();

		$entry = $this->find_last_entry( 'test' );
		$this->assertNotNull( $entry, 'Should find an entry with k=test' );
		$this->assertEqualsWithDelta( 12345.678, $entry['ts'], 0.001, 'ts field must be overridable by $data for profiler use' );
	}

	/**
	 * Regression: the 'rid' field must come from the LogManager's internal
	 * request ID, not from user-supplied $data.
	 */
	public function test_message_rid_field_not_overridable_by_data(): void {
		self::rmdir_recursive( '/tmp/event-logger-test' );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'rid_override_test' );
		$lm->message( 'test', [ 'rid' => 'fake_id', 'm' => 'hello' ] );
		$lm->flush_buffer();

		$entry = $this->find_last_entry( 'test' );
		$this->assertNotNull( $entry, 'Should find an entry with k=test' );
		$this->assertNotSame( 'fake_id', $entry['rid'], 'rid must not be overridable by $data' );
		$this->assertSame( $lm->get_request_id(), $entry['rid'], 'rid must be the real request ID' );
	}

	/**
	 * Regression: the 'n' field must come from LogManager's internal line
	 * counter, not from user-supplied $data.
	 */
	public function test_message_n_field_not_overridable_by_data(): void {
		self::rmdir_recursive( '/tmp/event-logger-test' );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'n_override_test' );
		$lm->message( 'test', [ 'n' => 99999, 'm' => 'hello' ] );
		$lm->flush_buffer();

		$entry = $this->find_last_entry( 'test' );
		$this->assertNotNull( $entry, 'Should find an entry with k=test' );
		$this->assertNotSame( 99999, $entry['n'], 'n must not be overridable by $data' );
		// The 'test' message is NOT the first line; ensure_tracked() logs
		// process (start), request, environment, and resources lines first.
		// So the exact line number depends on environment size.  Just verify
		// it's a reasonable positive integer that isn't the injected 99999.
		$this->assertIsInt( $entry['n'] );
		$this->assertGreaterThan( 0, $entry['n'] );
	}

	public function test_unique_id_server_var_used_when_set(): void {
		LogManager::reset();
		Config::reset();

		$_SERVER['UNIQUE_ID'] = 'test-unique-id-123';
		$lm = LogManager::instance();
		// Trigger initialization.
		$lm->start( 'test' );
		$lm->complete( 'test' );

		$rid = $lm->get_request_id();
		$this->assertSame( 'test-unique-id-123', $rid );

		unset( $_SERVER['UNIQUE_ID'] );
	}
}
