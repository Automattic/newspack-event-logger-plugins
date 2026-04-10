<?php
/**
 * Tests for JobRouter (routes job entries to jobs.log).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Jobs\Cron\JobRouter;

#[\PHPUnit\Framework\Attributes\CoversClass( JobRouter::class )]
class JobRouterTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-jobrouter';

	/** @var string|null Config file written during setUp (cleaned up in tearDown). */
	private ?string $config_file = null;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );

		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/jobrouter.php' );
		Config::reset();
	}

	protected function tearDown(): void {
		self::rmdir_recursive( self::TEST_DIR );
		Config::reset();
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
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

	private function make_context( int $partition = 0 ): array {
		$context = [
			'partition' => $partition,
			'log_base'  => self::TEST_DIR . '/logs',
		];
		JobRouter::init( $context, null );
		return $context;
	}

	public function test_init_creates_jobs_log(): void {
		$context = $this->make_context();

		$this->assertArrayHasKey( 'jobs_log', $context );
		$this->assertInstanceOf( Firehose::class, $context['jobs_log'] );
	}

	public function test_process_firehose_job_entry(): void {
		$context = $this->make_context();

		$entry = \wp_json_encode( [
			'ts'  => \microtime( true ),
			'n'   => 1,
			'rid' => 'abc123',
			'k'   => 'job',
			'm'   => [
				'handler'    => 'my_handler',
				'parameters' => [ 'key' => 'value' ],
			],
		] );

		JobRouter::process( $entry, 'firehose.log', $context );
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertDirectoryExists( $jobs_dir );
		$files = \glob( $jobs_dir . '/*.log' );
		$this->assertNotEmpty( $files );
		$content = \file_get_contents( $files[0] );
		$decoded = \json_decode( \trim( $content ), true );
		$this->assertSame( 'my_handler', $decoded['handler'] );
		$this->assertSame( 'job', $decoded['type'] );
	}

	public function test_process_firehose_remote_job_entry(): void {
		$context = $this->make_context();

		$entry = \wp_json_encode( [
			'ts'  => \microtime( true ),
			'n'   => 1,
			'rid' => 'abc456',
			'k'   => 'remote_job',
			'm'   => [
				'handler'    => 'remote_handler',
				'parameters' => [],
			],
		] );

		JobRouter::process( $entry, 'firehose.log', $context );
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$files = \glob( $jobs_dir . '/*.log' );
		$this->assertNotEmpty( $files );
		$content = \file_get_contents( $files[0] );
		$decoded = \json_decode( \trim( $content ), true );
		$this->assertSame( 'remote_job', $decoded['type'] );
	}

	public function test_process_firehose_skips_non_job_entries(): void {
		$context = $this->make_context();

		// Entry with k='hook' should be skipped.
		$entry = \wp_json_encode( [
			'ts'  => \microtime( true ),
			'n'   => 1,
			'rid' => 'abc789',
			'k'   => 'hook (start)',
			'm'   => 'init',
		] );

		JobRouter::process( $entry, 'firehose.log', $context );
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertFalse( \is_dir( $jobs_dir ), 'Non-job entry should not create output' );
	}

	public function test_process_firehose_skips_invalid_json(): void {
		$context = $this->make_context();

		JobRouter::process( 'not-json{{{', 'firehose.log', $context );
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertFalse( \is_dir( $jobs_dir ), 'Invalid JSON should not create output' );
	}

	public function test_process_firehose_rejects_invalid_handler_name(): void {
		$context = $this->make_context();

		$entry = \wp_json_encode( [
			'ts'  => \microtime( true ),
			'n'   => 1,
			'rid' => 'def123',
			'k'   => 'job',
			'm'   => [
				'handler'    => '../evil',
				'parameters' => [],
			],
		] );

		JobRouter::process( $entry, 'firehose.log', $context );
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertFalse( \is_dir( $jobs_dir ), 'Invalid handler name should be rejected' );
	}

	public function test_process_firehose_rejects_non_array_message(): void {
		$context = $this->make_context();

		$entry = \wp_json_encode( [
			'ts'  => \microtime( true ),
			'n'   => 1,
			'rid' => 'ghi123',
			'k'   => 'job',
			'm'   => 'not-an-array',
		] );

		JobRouter::process( $entry, 'firehose.log', $context );
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertFalse( \is_dir( $jobs_dir ), 'Non-array message should be rejected' );
	}

	public function test_process_jobintake_writes_directly(): void {
		$context = $this->make_context();

		$job_line = \wp_json_encode( [
			'handler'    => 'intake_handler',
			'parameters' => [ 'data' => 'payload' ],
		] );

		JobRouter::process( $job_line, 'jobintake.log', $context );

		// Verify it was written to the jobs log.
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertDirectoryExists( $jobs_dir );
		$files = \glob( $jobs_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'Job should be written to jobs.log' );

		$content = \file_get_contents( $files[0] );
		$this->assertStringContainsString( 'intake_handler', $content );
	}

	public function test_process_jobintake_drops_oversized(): void {
		$context = $this->make_context();

		// Create a line that exceeds MAX_JOB_SIZE (10MB).
		$big_line = \str_repeat( 'x', 10485761 );
		JobRouter::process( $big_line, 'jobintake.log', $context );

		// Verify no output was written to jobs.log.
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		if ( \is_dir( $jobs_dir ) ) {
			$files = \glob( $jobs_dir . '/*.log' );
			if ( ! empty( $files ) ) {
				$content = \file_get_contents( $files[0] );
				$this->assertEmpty( \trim( $content ), 'Oversized job should not produce any output' );
			} else {
				// No files = no output written. Correct.
				$this->assertEmpty( $files, 'No job files should exist for oversized input' );
			}
		} else {
			// Directory not created = no output written. Correct.
			$this->assertDirectoryDoesNotExist( $jobs_dir );
		}
	}

	public function test_process_writes_directly_to_jobs_log(): void {
		$context = $this->make_context();

		$entry = \wp_json_encode( [
			'ts'  => \microtime( true ),
			'n'   => 1,
			'rid' => 'flush123',
			'k'   => 'job',
			'm'   => [
				'handler'    => 'flush_handler',
				'parameters' => [ 'a' => 'b' ],
			],
		] );
		JobRouter::process( $entry, 'firehose.log', $context );

		// Verify output written immediately (no flush required).
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$files    = \glob( $jobs_dir . '/*.log' );
		$this->assertNotEmpty( $files );
		$content = \file_get_contents( $files[0] );
		$this->assertStringContainsString( 'flush_handler', $content );
	}

	public function test_save_state_returns_empty(): void {
		$context = $this->make_context();
		$state   = JobRouter::save_state( $context );
		$this->assertIsArray( $state );
		$this->assertEmpty( $state );
	}

	public function test_cleanup_nulls_jobs_log(): void {
		$context = $this->make_context();
		JobRouter::cleanup( $context );
		$this->assertNull( $context['jobs_log'] );
	}

	public function test_process_preserves_timestamp(): void {
		$context = $this->make_context();

		$ts    = 1700000000.123;
		$entry = \wp_json_encode( [
			'ts'  => $ts,
			'n'   => 1,
			'rid' => 'ts123',
			'k'   => 'job',
			'm'   => [
				'handler'    => 'ts_handler',
				'parameters' => [],
				'ts'         => $ts,
			],
		] );

		JobRouter::process( $entry, 'firehose.log', $context );

		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$files    = \glob( $jobs_dir . '/*.log' );
		$this->assertNotEmpty( $files );
		$content = \file_get_contents( $files[0] );
		$decoded = \json_decode( \trim( $content ), true );
		$this->assertSame( $ts, $decoded['ts'] );
	}

	public function test_process_jobintake_rejects_invalid_handler_name(): void {
		$context = $this->make_context();

		$job_line = \wp_json_encode( [
			'handler'    => '../evil',
			'parameters' => [ 'data' => 'payload' ],
		] );

		JobRouter::process( $job_line, 'jobintake.log', $context );

		// jobs.log directory should not exist — entry was rejected before writing.
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertFalse( \is_dir( $jobs_dir ), 'Invalid handler name should be rejected' );
	}

	public function test_process_jobintake_rejects_missing_handler(): void {
		$context = $this->make_context();

		$job_line = \wp_json_encode( [
			'parameters' => [ 'data' => 'payload' ],
		] );

		JobRouter::process( $job_line, 'jobintake.log', $context );

		// jobs.log directory should not exist — entry was rejected before writing.
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertFalse( \is_dir( $jobs_dir ), 'Missing handler should be rejected' );
	}

	public function test_process_jobintake_rejects_invalid_json(): void {
		$context = $this->make_context();

		JobRouter::process( 'not-valid-json{{{', 'jobintake.log', $context );

		// jobs.log directory should not exist — entry was rejected before writing.
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertFalse( \is_dir( $jobs_dir ), 'Invalid JSON should be rejected' );
	}

	public function test_process_jobintake_accepts_valid_handler_names(): void {
		$context = $this->make_context();

		// Test various valid handler name patterns.
		$valid_names = [ 'myHandler', 'my_handler', 'my-handler', 'A', 'handler123' ];

		foreach ( $valid_names as $name ) {
			$job_line = \wp_json_encode( [
				'handler'    => $name,
				'parameters' => [],
			] );
			JobRouter::process( $job_line, 'jobintake.log', $context );
		}

		// Verify entries were written to jobs.log.
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertDirectoryExists( $jobs_dir );
		$files = \glob( $jobs_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'Valid handler names should be accepted' );

		$content = \file_get_contents( $files[0] );
		foreach ( $valid_names as $name ) {
			$this->assertStringContainsString( $name, $content, "Handler name '{$name}' should be accepted" );
		}
	}

	public function test_process_firehose_false_positive_strpos(): void {
		$context = $this->make_context();

		// Line contains "k":"job" but in a message string, not as the actual keyword.
		$entry = \wp_json_encode( [
			'ts'  => \microtime( true ),
			'n'   => 1,
			'rid' => 'fp123',
			'k'   => 'info',
			'm'   => 'Received "k":"job" in payload',
		] );

		JobRouter::process( $entry, 'firehose.log', $context );
		// After full JSON decode, k is 'info' not 'job', so should be skipped.
		$jobs_dir = self::TEST_DIR . '/logs/jobs.log/p0';
		$this->assertFalse( \is_dir( $jobs_dir ), 'False positive strpos should not create output' );
	}
}
