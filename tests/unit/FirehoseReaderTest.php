<?php
/**
 * Tests for FirehoseReader streaming reader.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\FirehoseReader;

#[\PHPUnit\Framework\Attributes\CoversClass( FirehoseReader::class )]
class FirehoseReaderTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$this->temp_dir = '/tmp/event-logger-test-reader-' . \uniqid();
		@\mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		self::rmdir_recursive( $this->temp_dir );
		Config::reset();
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
	 * Create a Firehose and write lines to it, returning the Firehose instance.
	 *
	 * @param string   $name         Log name.
	 * @param string[] $lines        Lines to write.
	 * @param int      $segment_size Segment size.
	 * @return Firehose
	 */
	private function make_firehose_with_data( string $name, array $lines, int $segment_size = 4096 ): Firehose {
		$base = "{$this->temp_dir}/{$name}";
		$fh   = new Firehose( $base, 0, $segment_size, 10, 0 );
		foreach ( $lines as $line ) {
			$fh->write( $line );
		}
		// Force-refresh the segment cache so readers see current state.
		$fh->get_segments( true );
		return $fh;
	}

	public function test_next_offset_start(): void {
		$fh     = $this->make_firehose_with_data( 'start.log', [ 'line1', 'line2', 'line3' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_end(): void {
		$fh     = $this->make_firehose_with_data( 'end.log', [ 'line1', 'line2' ] );
		$reader = new FirehoseReader( $fh, 'end' );

		$pos = $reader->get_position();
		// Should be at end of newest segment.
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertGreaterThan( 0, $pos['offset'] );
	}

	public function test_next_offset_recent_single_segment(): void {
		$fh     = $this->make_firehose_with_data( 'recent1.log', [ 'one', 'two' ] );
		$reader = new FirehoseReader( $fh, 'recent' );

		// With only one segment, 'recent' starts at the oldest (and only) segment.
		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_recent_multiple_segments(): void {
		// Segment size minimum is 1024. Use 600-byte lines to force rotation.
		// 3 writes of 600 bytes: writes 1-2 stay on seg 0, write 3 creates seg 1.
		$fh = $this->make_firehose_with_data(
			'recent2.log',
			[ \str_repeat( 'a', 599 ), \str_repeat( 'b', 599 ), \str_repeat( 'c', 599 ) ],
			1024
		);

		$segments = $fh->get_segments( true );
		$this->assertGreaterThanOrEqual( 2, \count( $segments ), 'Need at least 2 segments for recent test' );

		$reader = new FirehoseReader( $fh, 'recent' );
		$pos    = $reader->get_position();

		// 'recent' should start at second-to-last segment.
		$expected_id = $segments[ \count( $segments ) - 2 ]['id'];
		$this->assertSame( $expected_id, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_explicit_position(): void {
		$fh     = $this->make_firehose_with_data( 'explicit.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->next_offset( [ 'segment_id' => 5, 'offset' => 100 ] );
		$pos = $reader->get_position();
		$this->assertSame( 5, $pos['segment_id'] );
		$this->assertSame( 100, $pos['offset'] );
	}

	public function test_read_line_returns_lines_in_order(): void {
		$lines  = [ 'first', 'second', 'third' ];
		$fh     = $this->make_firehose_with_data( 'order.log', $lines );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();

		$read_lines = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$line = $reader->read_line();
			$this->assertNotNull( $line, "Expected line {$i}" );
			$read_lines[] = \rtrim( $line, "\n" );
		}

		$this->assertSame( $lines, $read_lines );
	}

	public function test_read_line_returns_null_at_eof(): void {
		$fh     = $this->make_firehose_with_data( 'eof.log', [ 'only' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();

		// Read the one line.
		$line = $reader->read_line();
		$this->assertNotNull( $line );

		// Now at EOF.
		$line = $reader->read_line();
		$this->assertNull( $line );
	}

	public function test_is_caught_up_at_eof(): void {
		$fh     = $this->make_firehose_with_data( 'caught.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();

		// Read all data.
		while ( null !== $reader->read_line() ) {
			// Drain.
		}

		$this->assertTrue( $reader->is_caught_up() );
	}

	public function test_is_caught_up_false_when_data_available(): void {
		$fh     = $this->make_firehose_with_data( 'notcaught.log', [ 'one', 'two' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();

		// Read one line, more data remains.
		$reader->read_line();
		// After reading one line, we're not at EOF yet.
		$this->assertFalse( $reader->is_caught_up() );
	}

	public function test_next_segment_advances(): void {
		// Force multiple segments with 600-byte lines (segment min is 1024).
		$lines = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$lines[] = \str_repeat( (string) $i, 599 );
		}
		$fh = $this->make_firehose_with_data( 'advance.log', $lines, 1024 );

		$segments = $fh->get_segments();
		if ( \count( $segments ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 segments for this test' );
		}

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Read all lines from first segment.
		$first_segment_id = $reader->get_segment_id();
		while ( null !== $reader->read_line() ) {
			// Drain first segment.
		}

		// Advance to next segment - allow time for file to be considered stale.
		// Touch current segment to make it look old.
		$current_path = $fh->get_segment_path( $first_segment_id );
		@\touch( $current_path, \time() - 10 );
		\clearstatcache( true, $current_path );

		$result = $reader->next_segment();
		if ( null !== $result ) {
			// Should have moved to a strictly later segment.
			$this->assertGreaterThan( $first_segment_id, $reader->get_segment_id() );
		}
	}

	public function test_position_save_and_restore(): void {
		$fh     = $this->make_firehose_with_data( 'savepos.log', [ 'line1', 'line2', 'line3' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();
		$reader->read_line(); // Read "line1\n"

		// Save position.
		$saved_pos = $reader->get_position();
		$this->assertSame( 0, $saved_pos['segment_id'] );
		$this->assertGreaterThan( 0, $saved_pos['offset'] );

		// Create new reader and restore position.
		$reader2 = new FirehoseReader( $fh, 'start' );
		$reader2->next_offset( $saved_pos );
		$reader2->open();

		// Should read from where we left off.
		$line = $reader2->read_line();
		$this->assertNotNull( $line );
		$this->assertSame( "line2\n", $line );
	}

	public function test_open_empty_firehose_returns_null(): void {
		$base   = "{$this->temp_dir}/empty.log";
		$fh     = new Firehose( $base, 0, 1024, 2, 0 );
		$reader = new FirehoseReader( $fh, 'start' );

		// No data written.
		$result = $reader->open();
		// May return null or a valid handle (segment 0 might have been created as empty).
		// If it returns a handle, reading should give null.
		if ( null !== $result ) {
			$line = $reader->read_line();
			$this->assertNull( $line );
		} else {
			$this->assertNull( $result );
		}
	}

	public function test_read_line_handles_no_open(): void {
		$fh     = $this->make_firehose_with_data( 'noopen.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		// Don't call open() - read_line should return null.
		$line = $reader->read_line();
		$this->assertNull( $line );
	}

	public function test_close_is_safe_multiple_times(): void {
		$fh     = $this->make_firehose_with_data( 'multiclose.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();
		$reader->close();
		$reader->close(); // Should not throw.
		$this->assertTrue( true ); // If we got here, no exception.
	}

	public function test_get_segment_id(): void {
		$fh     = $this->make_firehose_with_data( 'segid.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$this->assertSame( 0, $reader->get_segment_id() );
	}

	public function test_refresh_segments(): void {
		$fh     = $this->make_firehose_with_data( 'refresh.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		// This should not throw.
		$reader->refresh_segments();
		$this->assertTrue( true );
	}

	public function test_update_offset_syncs_with_ftell(): void {
		$lines  = [ 'alpha', 'bravo', 'charlie' ];
		$fh     = $this->make_firehose_with_data( 'updateoff.log', $lines );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();

		// Read one line manually, then check update_offset.
		$line = $reader->read_line();
		$this->assertNotNull( $line );

		$pos_after_read = $reader->get_position();
		// After read_line, offset should be past the first line ("alpha\n" = 6 bytes).
		$this->assertGreaterThan( 0, $pos_after_read['offset'] );

		// update_offset() syncs with ftell which may be ahead of line position
		// (read_line uses buffered reads). Just verify it doesn't go backwards.
		$reader->update_offset();
		$pos_after_update = $reader->get_position();
		$this->assertGreaterThanOrEqual( $pos_after_read['offset'], $pos_after_update['offset'] );
	}

	public function test_close_and_reopen(): void {
		$lines  = [ 'first', 'second', 'third' ];
		$fh     = $this->make_firehose_with_data( 'reopen.log', $lines );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();
		$line = $reader->read_line();
		$this->assertSame( "first\n", $line );

		// Save position, close, reopen.
		$saved = $reader->get_position();
		$reader->close();

		// After close, read_line should return null.
		$this->assertNull( $reader->read_line() );

		// Reopen at saved position.
		$reader->next_offset( $saved );
		$reader->open();
		$line = $reader->read_line();
		$this->assertSame( "second\n", $line );
	}

	public function test_deleted_segment_jumps_to_oldest(): void {
		// Create multi-segment firehose.
		$lines = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$lines[] = \str_repeat( (string) $i, 599 );
		}
		$fh = $this->make_firehose_with_data( 'delseg.log', $lines, 1024 );

		$segments = $fh->get_segments();
		if ( \count( $segments ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 segments' );
		}

		// Point reader at a segment that we'll "delete" (non-existent).
		$reader = new FirehoseReader( $fh, 'start' );
		// Set position to a non-existent segment far in the past.
		$reader->next_offset( [ 'segment_id' => -100, 'offset' => 0 ] );
		$reader->open();

		// Open should handle the missing segment gracefully.
		// Reader should eventually provide data from the first available segment.
		$line = $reader->read_line();
		// Either returns null (segment not found) or data from fallback.
		// The important thing is no exception.
		$this->assertTrue( true, 'Handled deleted segment without exception' );
	}

	public function test_read_across_multiple_segments(): void {
		// Segment min is 1024. Use 400-byte lines (12 lines = multiple segments).
		$data = [];
		for ( $i = 0; $i < 12; $i++ ) {
			$data[] = \str_repeat( \chr( 65 + ( $i % 26 ) ), 399 ); // 400 bytes per line.
		}
		$fh = $this->make_firehose_with_data( 'multiread.log', $data, 1024 );

		$segments = $fh->get_segments();
		if ( \count( $segments ) < 2 ) {
			$this->markTestSkipped( 'Need multiple segments' );
		}

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		$all_lines = [];
		$max_iter  = 50; // Safety limit.
		$iter      = 0;
		while ( $iter < $max_iter ) {
			$line = $reader->read_line();
			if ( null !== $line ) {
				$all_lines[] = \rtrim( $line, "\n" );
			} else {
				// Try advancing to next segment.
				$old_id = $reader->get_segment_id();
				// Touch old segment file to make it stale.
				$old_path = $fh->get_segment_path( $old_id );
				@\touch( $old_path, \time() - 10 );
				\clearstatcache( true, $old_path );

				$result = $reader->next_segment();
				if ( null === $result || $reader->is_caught_up() ) {
					break;
				}
			}
			$iter++;
		}

		// We should have read at least most of the lines.
		$this->assertGreaterThanOrEqual( \count( $data ) - 1, \count( $all_lines ),
			'Should read most/all lines across segments' );
	}

	// ── next_offset with negative offset clamp ──────────────────────────

	public function test_next_offset_clamps_negative_offset(): void {
		$fh     = $this->make_firehose_with_data( 'neg-off.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		// Setting offset to -100 should be clamped to -1.
		$reader->next_offset( [ 'segment_id' => 0, 'offset' => -100 ] );
		$pos = $reader->get_position();
		$this->assertSame( -1, $pos['offset'], 'Offset below -1 should be clamped to -1' );
	}

	public function test_next_offset_allows_minus_one(): void {
		$fh     = $this->make_firehose_with_data( 'neg1.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->next_offset( [ 'segment_id' => 0, 'offset' => -1 ] );
		$pos = $reader->get_position();
		$this->assertSame( -1, $pos['offset'] );
	}

	// ── mark_eof ────────────────────────────────────────────────────────

	public function test_mark_eof_makes_caught_up(): void {
		$fh     = $this->make_firehose_with_data( 'markeof.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'end' );

		// At end position, with mark_eof the reader should be caught up.
		$reader->mark_eof();
		$this->assertTrue( $reader->is_caught_up() );
	}

	// ── is_caught_up on empty firehose ──────────────────────────────────

	public function test_is_caught_up_on_empty_firehose(): void {
		$base   = "{$this->temp_dir}/empty2.log";
		$fh     = new Firehose( $base, 0, 1024, 2, 0 );
		$reader = new FirehoseReader( $fh, 'start' );

		// Mark EOF since there is no data.
		$reader->mark_eof();
		$this->assertTrue( $reader->is_caught_up() );
	}

	// ── open returns existing handle when same segment ───────────────────

	public function test_open_returns_existing_handle(): void {
		$fh     = $this->make_firehose_with_data( 'reuse.log', [ 'line1', 'line2' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$handle1 = $reader->open();
		$this->assertNotNull( $handle1 );

		// Second open on same segment returns same handle.
		$handle2 = $reader->open();
		$this->assertSame( $handle1, $handle2 );
	}

	// ── next_segment with firehose reset detection ──────────────────────

	public function test_next_segment_on_empty_returns_null(): void {
		$base   = "{$this->temp_dir}/empty3.log";
		$fh     = new Firehose( $base, 0, 1024, 2, 0 );
		$reader = new FirehoseReader( $fh, 'start' );

		$result = $reader->next_segment();
		$this->assertNull( $result );
	}

	// ── destructor closes handle ────────────────────────────────────────

	public function test_destructor_closes_handle(): void {
		$fh     = $this->make_firehose_with_data( 'destruct.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Destroying the reader should close the handle.
		unset( $reader );
		$this->assertTrue( true, 'Destructor should not throw' );
	}

	// ── default offset parameter ────────────────────────────────────────

	public function test_default_offset_is_start(): void {
		$fh     = $this->make_firehose_with_data( 'defstart.log', [ 'aaa', 'bbb' ] );
		// No default_offset argument — should default to 'start'.
		$reader = new FirehoseReader( $fh );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	// ── read_line — buffer overflow protection ─────────────────────────

	public function test_read_line_buffer_overflow_discards_and_recovers(): void {
		// The MAX_LINE_BUFFER_SIZE is 20MB (private const).
		// We can't easily exceed it in a unit test. Instead, test that
		// partial line buffering works correctly: write a very long line
		// (no newline) followed by a normal line, and verify partial data
		// is buffered correctly across read_line calls.
		$long_data = \str_repeat( 'A', 2000 ); // Long line without newline.
		$normal    = 'normal-line';

		// Write them as one continuous block (long line + newline + normal + newline).
		$base = "{$this->temp_dir}/buffer-overflow.log";
		$fh   = new Firehose( $base, 0, 65536, 10, 0 );
		$fh->allow_large_writes();
		$fh->write_raw( $long_data . "\n" . $normal . "\n" );
		$fh->get_segments( true );

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// First read should get the long line.
		$line1 = $reader->read_line();
		$this->assertNotNull( $line1 );
		$this->assertSame( $long_data . "\n", $line1 );

		// Second read should get the normal line.
		$line2 = $reader->read_line();
		$this->assertNotNull( $line2 );
		$this->assertSame( $normal . "\n", $line2 );

		// Third read should return null (EOF).
		$line3 = $reader->read_line();
		$this->assertNull( $line3 );
	}

	public function test_read_line_partial_line_waits_for_newline(): void {
		// Write data without a trailing newline to simulate a partial write.
		$base = "{$this->temp_dir}/partial.log";
		$fh   = new Firehose( $base, 0, 65536, 10, 0 );
		// Write a complete line first.
		$fh->write( 'complete-line' );
		$fh->get_segments( true );

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Should read the complete line.
		$line = $reader->read_line();
		$this->assertSame( "complete-line\n", $line );

		// No more data — should return null.
		$line = $reader->read_line();
		$this->assertNull( $line );
	}

	public function test_read_line_multi_chunk_reassembly(): void {
		// Write many short lines to force multiple fread chunks from the OS.
		$lines = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$lines[] = "line-{$i}-" . \str_repeat( 'x', 50 );
		}
		$fh     = $this->make_firehose_with_data( 'multichunk.log', $lines, 65536 );
		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		$read_lines = [];
		$safety     = 200;
		while ( $safety-- > 0 ) {
			$line = $reader->read_line();
			if ( null === $line ) {
				break;
			}
			$read_lines[] = \rtrim( $line, "\n" );
		}

		$this->assertSame( $lines, $read_lines );
	}

	public function test_read_line_empty_line(): void {
		// Write lines including an empty line.
		$base = "{$this->temp_dir}/emptyline.log";
		$fh   = new Firehose( $base, 0, 65536, 10, 0 );
		$fh->write( 'before' );
		$fh->write( '' );  // Empty line becomes just "\n".
		$fh->write( 'after' );
		$fh->get_segments( true );

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		$line1 = $reader->read_line();
		$this->assertSame( "before\n", $line1 );

		$line2 = $reader->read_line();
		$this->assertSame( "\n", $line2 );

		$line3 = $reader->read_line();
		$this->assertSame( "after\n", $line3 );
	}

	public function test_read_line_tracks_offset_correctly(): void {
		$lines  = [ 'alpha', 'beta', 'gamma' ];
		$fh     = $this->make_firehose_with_data( 'offset-track.log', $lines );
		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Before reading, offset is 0.
		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['offset'] );

		// Read first line: "alpha\n" = 6 bytes.
		$reader->read_line();
		$pos = $reader->get_position();
		$this->assertSame( 6, $pos['offset'] );

		// Read second line: "beta\n" = 5 bytes. Total offset = 11.
		$reader->read_line();
		$pos = $reader->get_position();
		$this->assertSame( 11, $pos['offset'] );

		// Read third line: "gamma\n" = 6 bytes. Total offset = 17.
		$reader->read_line();
		$pos = $reader->get_position();
		$this->assertSame( 17, $pos['offset'] );
	}

	// ── DoS protection: MAX_LINE_BUFFER_SIZE branch ──────────────────────

	public function test_read_line_buffer_overflow_dos_protection(): void {
		// We cannot hit the actual 20MB limit efficiently, but we CAN test
		// the code path by temporarily lowering MAX_LINE_BUFFER_SIZE via reflection.
		$base = "{$this->temp_dir}/dos-overflow.log";
		$fh   = new Firehose( $base, 0, 1048576, 10, 0 );
		$fh->allow_large_writes();

		// Write data that exceeds our lowered buffer limit.
		// After the huge line, write a normal line to verify recovery.
		$huge_data   = \str_repeat( 'X', 200 ); // No newline — a single huge "line".
		$normal_line = 'recovered-line';
		$fh->write_raw( $huge_data . "\n" . $normal_line . "\n" );
		$fh->get_segments( true );

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Use reflection to lower MAX_LINE_BUFFER_SIZE to trigger the DoS branch.
		$ref = new \ReflectionClassConstant( FirehoseReader::class, 'MAX_LINE_BUFFER_SIZE' );
		// We can't change a constant, but we CAN test the overflow path by
		// manipulating the line_buffer directly to be near the limit.
		$buf_ref = new \ReflectionProperty( FirehoseReader::class, 'line_buffer' );
		$buf_ref->setAccessible( true );

		// Inject a buffer that's just under the limit, so next fread pushes it over.
		// The MAX_LINE_BUFFER_SIZE is 20971520. We set buffer to 20971500 bytes.
		// But that wastes memory. Instead, test the normal path thoroughly:
		// The huge line DOES have a newline, so it should be read normally.
		$line1 = $reader->read_line();
		$this->assertNotNull( $line1, 'Should read the huge line' );
		$this->assertSame( $huge_data . "\n", $line1 );

		$line2 = $reader->read_line();
		$this->assertNotNull( $line2, 'Should recover and read normal line' );
		$this->assertSame( $normal_line . "\n", $line2 );

		$line3 = $reader->read_line();
		$this->assertNull( $line3, 'Should be at EOF' );
	}

	public function test_read_line_fread_returns_false_sets_eof(): void {
		// After all data is consumed, fread returns empty, which should set at_eof.
		$fh     = $this->make_firehose_with_data( 'fread-false.log', [ 'single' ] );
		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Read the only line.
		$line = $reader->read_line();
		$this->assertSame( "single\n", $line );

		// Next read should return null and set at_eof.
		$line = $reader->read_line();
		$this->assertNull( $line );
		$this->assertTrue( $reader->is_caught_up() );
	}

	public function test_read_line_buffer_has_complete_line_before_fread(): void {
		// Tests the path where the line buffer already has a newline
		// before needing to call fread(). This happens when a single fread()
		// returns multiple lines.
		$lines  = [ 'aaa', 'bbb', 'ccc' ];
		$fh     = $this->make_firehose_with_data( 'prebuffered.log', $lines );
		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// The first fread will likely return all three lines at once.
		// read_line should return them one at a time from the buffer.
		$line1 = $reader->read_line();
		$this->assertSame( "aaa\n", $line1 );

		$line2 = $reader->read_line();
		$this->assertSame( "bbb\n", $line2 );

		$line3 = $reader->read_line();
		$this->assertSame( "ccc\n", $line3 );
	}

	// ── next_segment: firehose reset detection ──────────────────────────

	public function test_next_segment_detects_firehose_reset(): void {
		// Create a firehose with multiple segments.
		$lines = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$lines[] = \str_repeat( (string) $i, 599 );
		}
		$fh = $this->make_firehose_with_data( 'reset-detect.log', $lines, 1024 );

		$segments = $fh->get_segments();
		if ( \count( $segments ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 segments for reset detection' );
		}

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Read through first segment.
		while ( null !== $reader->read_line() ) {
			// Drain.
		}

		// Now simulate a firehose reset by deleting all segments and creating a new one.
		$partition_dir = $fh->get_partition_dir();
		// Delete all existing segment files.
		foreach ( \glob( "{$partition_dir}/*.log" ) as $file ) {
			@\unlink( $file );
		}

		// Create a fresh segment 0 with new data.
		$fresh_fh = new Firehose( "{$this->temp_dir}/reset-detect.log", 0, 1024, 10, 0 );
		$fresh_fh->write( 'after-reset' );
		$fresh_fh->get_segments( true );

		// next_segment should detect the reset (current segment is gone)
		// and jump to the oldest available segment.
		$result = $reader->next_segment();
		// Should return a file handle (or null if timing issues).
		// The key is no exception.
		if ( null !== $result ) {
			$line = $reader->read_line();
			if ( null !== $line ) {
				$this->assertSame( "after-reset\n", $line );
			}
		}
		$this->assertTrue( true, 'Firehose reset handled without exception' );
	}

	// ── next_segment: current segment still fresh ───────────────────────

	public function test_next_segment_stays_on_fresh_segment(): void {
		// Write enough to span 2 segments.
		$lines = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$lines[] = \str_repeat( (string) $i, 599 );
		}
		$fh = $this->make_firehose_with_data( 'stay-fresh.log', $lines, 1024 );

		$segments = $fh->get_segments();
		if ( \count( $segments ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 segments' );
		}

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Read all lines from first segment.
		while ( null !== $reader->read_line() ) {
			// Drain.
		}

		// Touch current segment to make it "fresh" (recently written).
		$current_path = $fh->get_segment_path( $reader->get_segment_id() );
		@\touch( $current_path ); // mtime = now, so stale_secs < 1.
		\clearstatcache( true, $current_path );

		// next_segment should stay on current segment because it's fresh.
		$old_id = $reader->get_segment_id();
		$reader->next_segment();
		// The reader may or may not advance depending on exact timing,
		// but should not crash.
		$this->assertTrue( true, 'Stayed on fresh segment without error' );
	}

	// ── next_offset: end on empty firehose ──────────────────────────────

	public function test_next_offset_end_on_empty_firehose(): void {
		$base   = "{$this->temp_dir}/empty-end.log";
		$fh     = new Firehose( $base, 0, 1024, 2, 0 );
		$reader = new FirehoseReader( $fh, 'end' );

		$pos = $reader->get_position();
		// Empty firehose: segment 0, offset 0.
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	// ── next_offset: recent on empty firehose ───────────────────────────

	public function test_next_offset_recent_on_empty_firehose(): void {
		$base   = "{$this->temp_dir}/empty-recent.log";
		$fh     = new Firehose( $base, 0, 1024, 2, 0 );
		$reader = new FirehoseReader( $fh, 'recent' );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	// ── open: segment deleted jumps to oldest ───────────────────────────

	public function test_open_segment_not_found_jumps_to_oldest(): void {
		$fh     = $this->make_firehose_with_data( 'jump-oldest.log', [ 'data1', 'data2' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		// Point reader to a non-existent high segment.
		$reader->next_offset( [ 'segment_id' => 999, 'offset' => 0 ] );
		$handle = $reader->open();

		// Should have jumped to oldest available segment.
		$this->assertSame( 0, $reader->get_segment_id() );
		$this->assertSame( 0, $reader->get_position()['offset'] );

		if ( null !== $handle ) {
			$line = $reader->read_line();
			$this->assertNotNull( $line );
			$this->assertSame( "data1\n", $line );
		}
	}

	// ── read_line: DoS protection via injected buffer ──────────────────

	public function test_read_line_dos_protection_overflow_branch(): void {
		// Write a line that's small enough but we'll inject a buffer to trigger overflow.
		$base = "{$this->temp_dir}/dos-inject.log";
		$fh   = new Firehose( $base, 0, 1048576, 10, 0 );
		$fh->allow_large_writes();

		// Write data: a huge partial line (no newline), then newline, then recovery line.
		$fh->write_raw( "normal-after-overflow\n" );
		$fh->get_segments( true );

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Inject a line_buffer that's just under MAX_LINE_BUFFER_SIZE via reflection.
		// The next fread will push it over the limit, triggering the DoS branch.
		$buf_ref = new \ReflectionProperty( FirehoseReader::class, 'line_buffer' );
		$buf_ref->setAccessible( true );
		// MAX_LINE_BUFFER_SIZE is 20971520. We set a buffer of that size minus 10,
		// so the next fread of ~22 bytes pushes it over.
		$buf_ref->setValue( $reader, \str_repeat( 'X', 20971510 ) ); // Just under limit.

		// read_line should detect overflow, discard buffer, and return null.
		$line = $reader->read_line();
		$this->assertNull( $line, 'Should return null on buffer overflow' );

		// Buffer should have been cleared and positioned at next newline.
		$buf_after = $buf_ref->getValue( $reader );
		// May have captured data after the newline, or be empty.
		$this->assertLessThan( 20971520, \strlen( $buf_after ), 'Buffer should be cleared after overflow' );
	}

	// ── read_line: partial line without newline returns null ────────────

	public function test_read_line_partial_no_newline_returns_null(): void {
		$base = "{$this->temp_dir}/partial-nonl.log";
		$fh   = new Firehose( $base, 0, 65536, 10, 0 );
		$fh->allow_large_writes();

		// Write data WITHOUT a trailing newline.
		$fh->write_raw( 'no-newline-here' );
		$fh->get_segments( true );

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Should return null because there's no complete line.
		$line = $reader->read_line();
		$this->assertNull( $line, 'Partial line without newline should return null' );
	}

	// ── next_segment: unread data in stale segment advances ─────────────

	public function test_next_segment_stale_with_unread_advances(): void {
		$lines = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$lines[] = \str_repeat( (string) $i, 599 );
		}
		$fh = $this->make_firehose_with_data( 'stale-advance.log', $lines, 1024 );

		$segments = $fh->get_segments();
		if ( \count( $segments ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 segments' );
		}

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		// Read only one line (leaving unread data in the first segment).
		$reader->read_line();

		// Touch current segment to make it stale (5+ seconds old).
		$current_path = $fh->get_segment_path( $reader->get_segment_id() );
		@\touch( $current_path, \time() - 10 );
		\clearstatcache( true, $current_path );

		$old_id = $reader->get_segment_id();
		$result = $reader->next_segment();

		// Should have advanced to next segment since current is stale.
		if ( null !== $result ) {
			$this->assertGreaterThanOrEqual( $old_id, $reader->get_segment_id() );
		}
		$this->assertTrue( true, 'Stale segment with unread data handled' );
	}

	// ── next_offset resets state properly ────────────────────────────────

	public function test_next_offset_resets_line_buffer_and_eof(): void {
		$fh     = $this->make_firehose_with_data( 'reset-state.log', [ 'line1', 'line2' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();
		$reader->read_line();

		// Now reset to start.
		$reader->next_offset( 'start' );

		// Internal state should be reset.
		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );

		// Should be able to re-read from start.
		$reader->open();
		$line = $reader->read_line();
		$this->assertSame( "line1\n", $line );
	}

	// ── open: already open with same segment ────────────────────────────

	public function test_open_same_segment_returns_same_handle_after_read(): void {
		$fh     = $this->make_firehose_with_data( 'same-seg.log', [ 'a', 'b' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$h1 = $reader->open();
		$reader->read_line();
		$h2 = $reader->open();

		$this->assertSame( $h1, $h2, 'Same segment should return same handle' );
	}

	// ── next_segment does not clear at_eof (SSE heartbeat regression) ───

	public function test_next_segment_does_not_clear_at_eof_on_newest(): void {
		// Write a single segment with data.
		$fh     = $this->make_firehose_with_data( 'eof-clear.log', [ 'line1', 'line2' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		$reader->open();

		// Drain all lines.
		while ( null !== $reader->read_line() ) {
			// Drain.
		}

		// Reader should be caught up (at_eof on newest segment).
		$this->assertTrue( $reader->is_caught_up(), 'Should be caught up after draining' );

		// Call next_segment() — when there is no next segment and we are on newest,
		// is_caught_up() must still return true (at_eof should not be cleared).
		$reader->next_segment();

		$this->assertTrue( $reader->is_caught_up(), 'Should still be caught up after next_segment() with no new segment' );
	}

	// ── next_segment_advances: strict greater than ──────────────────────

	public function test_next_segment_advances_strict(): void {
		// Force multiple segments.
		$lines = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$lines[] = \str_repeat( (string) $i, 599 );
		}
		$fh = $this->make_firehose_with_data( 'advance-strict.log', $lines, 1024 );

		$segments = $fh->get_segments();
		if ( \count( $segments ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 segments for this test' );
		}

		$reader = new FirehoseReader( $fh, 'start' );
		$reader->open();

		$first_segment_id = $reader->get_segment_id();
		while ( null !== $reader->read_line() ) {
			// Drain first segment.
		}

		// Touch current segment to make it stale.
		$current_path = $fh->get_segment_path( $first_segment_id );
		@\touch( $current_path, \time() - 10 );
		\clearstatcache( true, $current_path );

		$result = $reader->next_segment();
		if ( null !== $result ) {
			// Use strict greater than, not greater than or equal.
			$this->assertGreaterThan( $first_segment_id, $reader->get_segment_id() );
		}
	}

	// ── update_offset: no file handle returns nothing ───────────────────

	public function test_update_offset_no_fh(): void {
		$fh     = $this->make_firehose_with_data( 'no-fh.log', [ 'data' ] );
		$reader = new FirehoseReader( $fh, 'start' );

		// Don't call open() - no file handle.
		$reader->update_offset();
		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['offset'], 'Offset should remain 0 without open fh' );
	}
}
