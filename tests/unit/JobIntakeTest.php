<?php
/**
 * Tests for JobIntake (locked write path for large jobs).
 *
 * Tests write_job validation, partition selection,
 * lock management, payload size limits, and close behavior.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\FirehoseReader;
use Newspack_Event_Jobs\JobIntake;

#[\PHPUnit\Framework\Attributes\CoversClass( JobIntake::class )]
class JobIntakeTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-jobintake';

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
		@\mkdir( self::TEST_DIR . '/locks', 0755, true );
		@\mkdir( self::TEST_DIR . '/offsets', 0755, true );

		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/jobintake.php' );
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

	/**
	 * Read all lines from jobintake.log for a given partition.
	 *
	 * @param int $partition Partition index.
	 * @return string[] Lines.
	 */
	private function read_intake_lines( int $partition ): array {
		$firehose = new Firehose( self::TEST_DIR . '/logs/jobintake.log', $partition );
		$reader   = new FirehoseReader( $firehose );
		$fh       = $reader->open();
		$lines    = [];

		while ( $fh ) {
			while ( null !== ( $line = $reader->read_line() ) ) {
				$lines[] = \trim( $line );
			}
			if ( $reader->is_caught_up() ) {
				break;
			}
			$fh = $reader->next_segment();
		}
		$reader->close();

		return $lines;
	}

	// ── write_job ─────────────────────────────────────────────────────────

	public function test_write_job_writes_to_jobintake_log(): void {
		$intake = new JobIntake();
		$result = $intake->write_job( 'test_handler', [ 'key' => 'value' ] );
		$intake->close();

		$this->assertTrue( $result );

		// Read from both partitions (round-robin counter is static and unpredictable).
		$p0_lines = $this->read_intake_lines( 0 );
		$p1_lines = $this->read_intake_lines( 1 );
		$total    = \count( $p0_lines ) + \count( $p1_lines );
		$this->assertSame( 1, $total );

		$line    = \count( $p0_lines ) > 0 ? $p0_lines[0] : $p1_lines[0];
		$decoded = \json_decode( $line, true );
		$this->assertSame( 'job', $decoded['type'] );
		$this->assertSame( 'test_handler', $decoded['handler'] );
		$this->assertSame( [ 'key' => 'value' ], $decoded['parameters'] );
		$this->assertArrayHasKey( 'ts', $decoded );
	}

	public function test_write_job_rejects_invalid_handler_names(): void {
		$intake = new JobIntake();

		$this->assertFalse( $intake->write_job( '', [ 'a' => 1 ] ), 'Empty handler name should be rejected' );
		$this->assertFalse( $intake->write_job( '123start', [ 'a' => 1 ] ), 'Handler starting with digit should be rejected' );
		$this->assertFalse( $intake->write_job( 'has spaces', [ 'a' => 1 ] ), 'Handler with spaces should be rejected' );
		$this->assertFalse( $intake->write_job( 'has.dot', [ 'a' => 1 ] ), 'Handler with dots should be rejected' );

		$intake->close();
	}

	public function test_write_job_accepts_valid_handler_names(): void {
		$intake = new JobIntake();

		$this->assertTrue( $intake->write_job( 'simple', [] ) );
		$this->assertTrue( $intake->write_job( 'with_underscore', [] ) );
		$this->assertTrue( $intake->write_job( 'with-dash', [] ) );
		$this->assertTrue( $intake->write_job( 'CamelCase', [] ) );

		$intake->close();
	}

	// ── Handler name length limit (64 chars) ──────────────────────────────

	public function test_write_job_rejects_too_long_handler(): void {
		$intake = new JobIntake();
		$long   = 'a' . \str_repeat( 'b', 64 ); // 65 chars.
		$this->assertFalse( $intake->write_job( $long, [] ), 'Handler over 64 chars should be rejected' );
		$intake->close();
	}

	public function test_write_job_accepts_max_length_handler(): void {
		$intake = new JobIntake();
		$exact  = 'a' . \str_repeat( 'b', 63 ); // 64 chars.
		$this->assertTrue( $intake->write_job( $exact, [] ), 'Handler at exactly 64 chars should be accepted' );
		$intake->close();
	}

	// ── Partition selection ───────────────────────────────────────────────

	public function test_pinned_partition_sends_all_to_same(): void {
		$intake = new JobIntake();
		$intake->partition( 1 );
		$intake->write_job( 'handler_a', [ 'n' => 1 ] );
		$intake->write_job( 'handler_a', [ 'n' => 2 ] );
		$intake->close();

		$p0_lines = $this->read_intake_lines( 0 );
		$p1_lines = $this->read_intake_lines( 1 );

		$this->assertCount( 0, $p0_lines, 'Partition 0 should have no entries' );
		$this->assertCount( 2, $p1_lines, 'Partition 1 should have both entries' );
	}

	public function test_key_based_partition_consistent(): void {
		$intake = new JobIntake();
		$intake->write_job( 'handler_a', [ 'n' => 1 ], 'event_123' );
		$intake->write_job( 'handler_a', [ 'n' => 2 ], 'event_123' );
		$intake->close();

		// Both should land on the same partition (key-based hashing).
		$p0_lines = $this->read_intake_lines( 0 );
		$p1_lines = $this->read_intake_lines( 1 );

		// One partition has 2, the other has 0.
		$total = \count( $p0_lines ) + \count( $p1_lines );
		$this->assertSame( 2, $total );
		$this->assertTrue(
			2 === \count( $p0_lines ) || 2 === \count( $p1_lines ),
			'Both jobs with same key should go to same partition'
		);
	}

	public function test_get_partition_returns_null_by_default(): void {
		$intake = new JobIntake();
		$this->assertNull( $intake->get_partition() );
		$intake->close();
	}

	public function test_get_partition_returns_pinned_value(): void {
		$intake = new JobIntake();
		$intake->partition( 1 );
		$this->assertSame( 1, $intake->get_partition() );
		$intake->close();
	}

	// ── Lock management ───────────────────────────────────────────────────

	public function test_close_releases_lock(): void {
		$intake = new JobIntake();
		$intake->write_job( 'handler_a', [] ); // Triggers init.
		$this->assertTrue( $intake->is_open() );

		$intake->close();
		$this->assertFalse( $intake->is_open() );
	}

	public function test_second_intake_blocked_by_first(): void {
		$intake1 = new JobIntake();
		$intake1->write_job( 'handler_a', [] ); // Triggers init, acquires lock.

		// Second intake should fail to acquire lock.
		$intake2 = new JobIntake();
		$result  = $intake2->write_job( 'handler_b', [] );
		$this->assertFalse( $result, 'Second intake should fail while first holds lock' );

		$intake1->close();
		$intake2->close();
	}

	public function test_intake_reusable_after_close(): void {
		$intake = new JobIntake();
		$intake->write_job( 'handler_a', [] );
		$intake->close();
		$this->assertFalse( $intake->is_open() );

		// Create new intake after close -- should work.
		$intake2 = new JobIntake();
		$result  = $intake2->write_job( 'handler_b', [] );
		$this->assertTrue( $result );
		$intake2->close();
	}

	public function test_no_lock_mode(): void {
		$intake = new JobIntake( false ); // Disable locking.

		$result = $intake->write_job( 'handler_a', [ 'data' => 1 ] );
		$this->assertTrue( $result );

		$intake->close();
	}

	public function test_is_lock_available_when_no_lock_held(): void {
		$intake = new JobIntake();
		$this->assertTrue( $intake->is_lock_available() );
		$intake->close();
	}

	// ── write_jobs (batch) ────────────────────────────────────────────────

	public function test_write_jobs_batch(): void {
		$intake = new JobIntake();
		$jobs   = [
			[ 'handler' => 'batch_handler', 'parameters' => [ 'n' => 1 ] ],
			[ 'handler' => 'batch_handler', 'parameters' => [ 'n' => 2 ] ],
			[ 'handler' => 'batch_handler', 'parameters' => [ 'n' => 3 ] ],
		];
		$written = $intake->write_jobs( $jobs );
		$intake->close();

		$this->assertSame( 3, $written );
	}

	public function test_write_jobs_skips_invalid_entries(): void {
		$intake = new JobIntake();
		$jobs   = [
			[ 'handler' => 'good_handler', 'parameters' => [ 'n' => 1 ] ],
			[ 'handler' => '!invalid!', 'parameters' => [] ], // Invalid handler name.
			[ 'handler' => 'good_handler', 'parameters' => [ 'n' => 2 ] ],
		];
		$written = $intake->write_jobs( $jobs );
		$intake->close();

		$this->assertSame( 2, $written );
	}

	// ── Destructor releases lock ──────────────────────────────────────────

	public function test_destructor_releases_lock(): void {
		$lock_dir = self::TEST_DIR . '/locks/job-intake.lock.d';

		$intake = new JobIntake();
		$intake->write_job( 'handler_a', [] );
		// Lock dir should exist while open.
		$this->assertDirectoryExists( $lock_dir );

		// Destroy the object.
		unset( $intake );

		// Lock should be released -- new intake should succeed.
		$intake2 = new JobIntake();
		$result  = $intake2->write_job( 'handler_b', [] );
		$this->assertTrue( $result, 'New intake should succeed after destructor released lock' );
		$intake2->close();
	}

	// ── queue() static helper ─────────────────────────────────────────────

	public function test_queue_static_writes_job(): void {
		$result = JobIntake::queue( 'static_handler', [ 'data' => 'test' ] );
		$this->assertTrue( $result );

		// Verify the job was written.
		$p0_lines = $this->read_intake_lines( 0 );
		$p1_lines = $this->read_intake_lines( 1 );
		$total    = \count( $p0_lines ) + \count( $p1_lines );
		$this->assertSame( 1, $total );

		$line    = \count( $p0_lines ) > 0 ? $p0_lines[0] : $p1_lines[0];
		$decoded = \json_decode( $line, true );
		$this->assertSame( 'static_handler', $decoded['handler'] );
	}

	public function test_queue_static_rejects_invalid_handler(): void {
		$result = JobIntake::queue( '', [] );
		$this->assertFalse( $result );
	}

	public function test_large_payload_over_4kb_write_and_read(): void {
		$intake = new JobIntake();
		// Create a payload larger than PIPE_BUF (4096 bytes).
		$large_data = \str_repeat( 'A', 5000 );
		$result     = $intake->write_job( 'large_handler', [ 'payload' => $large_data ] );
		$intake->close();

		$this->assertTrue( $result, 'Large payload write should succeed via JobIntake' );

		// Read it back and verify it's intact.
		$p0_lines = $this->read_intake_lines( 0 );
		$p1_lines = $this->read_intake_lines( 1 );
		$all      = \array_merge( $p0_lines, $p1_lines );
		$this->assertCount( 1, $all );

		$decoded = \json_decode( $all[0], true );
		$this->assertSame( 'large_handler', $decoded['handler'] );
		$this->assertSame( $large_data, $decoded['parameters']['payload'] );
	}

	public function test_queue_static_with_key(): void {
		$result = JobIntake::queue( 'keyed_handler', [ 'data' => 1 ], 'my_key' );
		$this->assertTrue( $result );
	}
}
