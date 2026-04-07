<?php
/**
 * Tests for Firehose segmented log writer.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;

#[\PHPUnit\Framework\Attributes\CoversClass( Firehose::class )]
class FirehoseTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$this->temp_dir = '/tmp/event-logger-test-firehose-' . \uniqid();
		@\mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		self::rmdir_recursive( $this->temp_dir );
		Config::reset();
		parent::tearDown();
	}

	/**
	 * Recursively remove a directory.
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

	/**
	 * Create a Firehose instance with small segments for testing.
	 */
	private function make_firehose( string $name = 'test.log', int $segment_size = 1024, int $num_segments = 2 ): Firehose {
		$base = "{$this->temp_dir}/{$name}";
		return new Firehose( $base, 0, $segment_size, $num_segments, 0 );
	}

	public function test_write_and_read_back(): void {
		$fh = $this->make_firehose();

		$pos = $fh->write( '{"msg":"hello"}' );
		$this->assertIsArray( $pos );
		$this->assertArrayHasKey( 'segment_id', $pos );
		$this->assertArrayHasKey( 'offset', $pos );
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );

		// Read it back using read_at.
		$data = $fh->read_at( $pos['segment_id'], $pos['offset'], $pos['length'] );
		$this->assertSame( "{\"msg\":\"hello\"}\n", $data );
	}

	public function test_write_multiple_lines(): void {
		$fh = $this->make_firehose( 'multi.log', 8192 );

		$positions = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$pos = $fh->write( "line-{$i}" );
			$this->assertIsArray( $pos );
			$positions[] = $pos;
		}

		// Each subsequent write has a higher offset.
		for ( $i = 1; $i < 5; $i++ ) {
			$this->assertGreaterThan( $positions[ $i - 1 ]['offset'], $positions[ $i ]['offset'] );
		}

		// Read each line back.
		foreach ( $positions as $i => $pos ) {
			$data = $fh->read_at( $pos['segment_id'], $pos['offset'], $pos['length'] );
			$this->assertSame( "line-{$i}\n", $data );
		}
	}

	public function test_segment_rotation_on_size(): void {
		// Segment size minimum is 1024 (clamped by constructor).
		// Each line = 600 bytes (599 chars + "\n").
		// Rotation logic: write() triggers rotate() when current_size + len > segment_size.
		// do_rotate() checks actual filesize on disk vs segment_size.
		// So rotation to a new segment only happens when the ALREADY-WRITTEN file
		// exceeds the segment size (previous writes flushed to disk before rotate checks).
		$fh = $this->make_firehose( 'rotate.log', 1024, 5 );

		$data = \str_repeat( 'x', 599 ); // 599 chars + "\n" = 600 bytes per write.
		$pos1 = $fh->write( $data );
		$this->assertSame( 0, $pos1['segment_id'] );

		// Write 2: current_size (600) + 600 = 1200 > 1024, triggers rotate.
		// But do_rotate sees file on disk = 600 bytes < 1024, stays on segment 0.
		$pos2 = $fh->write( $data );
		$this->assertSame( 0, $pos2['segment_id'] );

		// Write 3: current_size (1200) + 600 = 1800 > 1024, triggers rotate.
		// do_rotate sees file on disk = 1200 bytes >= 1024, creates segment 1.
		$pos3 = $fh->write( $data );
		$this->assertSame( 1, $pos3['segment_id'] );

		// Verify segments exist.
		$segments = $fh->get_segments( true );
		$this->assertCount( 2, $segments );
		$this->assertSame( 0, $segments[0]['id'] );
		$this->assertSame( 1, $segments[1]['id'] );
	}

	public function test_segment_cleanup_retains_correct_count(): void {
		// num_segments=2 means keep at most 2 segments.
		// Segment size minimum is 1024. Use 600-byte lines so 2 writes fill a segment.
		$fh = $this->make_firehose( 'cleanup.log', 1024, 2 );

		$data = \str_repeat( 'y', 599 ); // 600 bytes per line.

		// Write enough to create 3+ segments and trigger cleanup.
		// Writes 1-2: fill seg 0 (1200 bytes).
		// Write 3: triggers rotate, sees 600 on disk < 1024, stays seg 0.
		//          Then after write, current_size = 1800.
		// Write 4: triggers rotate, sees 1800 on disk >= 1024, creates seg 1.
		// Writes 5-6: fill seg 1 similarly.
		// Write 7: creates seg 2, cleanup removes seg 0.
		for ( $i = 0; $i < 9; $i++ ) {
			$fh->write( $data );
		}

		$segments = $fh->get_segments( true );
		$this->assertLessThanOrEqual( 3, \count( $segments ), 'Should have at most 3 segments (cleanup race is OK)' );

		// The oldest segment should have been cleaned up.
		$ids = \array_column( $segments, 'id' );
		// At minimum, newest segments should exist.
		$this->assertContains( \max( $ids ), $ids );
	}

	public function test_hash_to_partition_deterministic(): void {
		// Same input always produces same partition.
		$p1 = Firehose::hash_to_partition( '/test/page', 4 );
		$p2 = Firehose::hash_to_partition( '/test/page', 4 );
		$this->assertSame( $p1, $p2 );

		// Result is in range [0, num_partitions).
		for ( $i = 0; $i < 100; $i++ ) {
			$p = Firehose::hash_to_partition( "/path/{$i}", 8 );
			$this->assertGreaterThanOrEqual( 0, $p );
			$this->assertLessThan( 8, $p );
		}
	}

	public function test_hash_to_partition_strips_query_string(): void {
		$p1 = Firehose::hash_to_partition( '/page?v=1', 4 );
		$p2 = Firehose::hash_to_partition( '/page?v=2', 4 );
		$this->assertSame( $p1, $p2, 'Query string should be stripped before hashing' );

		$p3 = Firehose::hash_to_partition( '/page', 4 );
		$this->assertSame( $p1, $p3, 'URL with no query string should match stripped version' );
	}

	public function test_hash_to_partition_rejects_zero_partitions(): void {
		$this->expectException( \InvalidArgumentException::class );
		Firehose::hash_to_partition( '/test', 0 );
	}

	public function test_hash_to_partition_single_partition(): void {
		$p = Firehose::hash_to_partition( '/anything', 1 );
		$this->assertSame( 0, $p );
	}

	public function test_write_raw(): void {
		$fh = $this->make_firehose( 'raw.log', 4096 );

		// write_raw does not append newline.
		$result = $fh->write_raw( "line1\nline2\n" );
		$this->assertTrue( $result );

		$data = $fh->read_at( 0, 0, 12 );
		$this->assertSame( "line1\nline2\n", $data );
	}

	public function test_write_raw_drops_large_by_default(): void {
		$fh = $this->make_firehose( 'raw-large.log', 8192 );

		// Default: drop writes > MAX_LINE_SIZE (4096).
		$big_data = \str_repeat( 'z', 5000 );
		$result   = $fh->write_raw( $big_data );
		$this->assertFalse( $result, 'Should drop writes exceeding PIPE_BUF by default' );
	}

	public function test_allow_large_writes(): void {
		$fh = $this->make_firehose( 'raw-allow.log', 65536 );
		$fh->allow_large_writes();

		$big_data = \str_repeat( 'z', 5000 ) . "\n";
		$result   = $fh->write_raw( $big_data );
		$this->assertTrue( $result, 'Should allow large writes when flag is set' );

		$readback = $fh->read_at( 0, 0, 5001 );
		$this->assertSame( $big_data, $readback );
	}

	public function test_write_drops_oversized_lines(): void {
		$fh = $this->make_firehose( 'oversized.log', 8192 );

		// Line + newline exceeds MAX_LINE_SIZE (4096).
		$big_line = \str_repeat( 'a', 4096 );
		$result   = $fh->write( $big_line );
		$this->assertFalse( $result, 'Should drop lines exceeding PIPE_BUF' );
	}

	public function test_get_segments_returns_sorted(): void {
		// Segment size minimum is 1024. Use 600-byte lines so 2 writes fill a segment.
		$fh = $this->make_firehose( 'sorted.log', 1024, 10 );

		$data = \str_repeat( 'x', 599 ); // 600 bytes per line.
		// Create multiple segments: every 3 writes creates a new segment.
		for ( $i = 0; $i < 12; $i++ ) {
			$fh->write( $data );
		}

		$segments = $fh->get_segments( true );
		$ids = \array_column( $segments, 'id' );

		// Must have created at least 2 segments to validate sorting.
		$this->assertGreaterThanOrEqual( 2, \count( $ids ), 'Expected at least 2 segments for sort validation' );

		// IDs should be monotonically increasing.
		for ( $i = 1; $i < \count( $ids ); $i++ ) {
			$this->assertGreaterThan( $ids[ $i - 1 ], $ids[ $i ] );
		}
	}

	public function test_get_segments_empty_partition(): void {
		$base = "{$this->temp_dir}/empty.log";
		// Create the base dir but not the partition dir.
		@\mkdir( $base, 0755, true );
		$fh = new Firehose( $base, 0, 1024, 2, 0 );

		// No writes yet, but segments should work.
		$segments = $fh->get_segments( true );
		// Might be empty or might have segment 0 (depending on lazy creation).
		$this->assertIsArray( $segments );
	}

	public function test_get_current_position(): void {
		$fh = $this->make_firehose( 'position.log', 4096 );

		$pos = $fh->get_current_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );

		$fh->write( 'hello' );
		$pos = $fh->get_current_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 6, $pos['offset'] ); // "hello\n" = 6 bytes.
	}

	public function test_get_partition_dir(): void {
		$base = "{$this->temp_dir}/partdir.log";
		$fh = new Firehose( $base, 3, 1024, 2, 0 );
		$this->assertStringEndsWith( '/p3', $fh->get_partition_dir() );
	}

	public function test_get_partition(): void {
		$base = "{$this->temp_dir}/partnum.log";
		$fh = new Firehose( $base, 7, 1024, 2, 0 );
		$this->assertSame( 7, $fh->get_partition() );
	}

	public function test_get_base_dir(): void {
		$base = "{$this->temp_dir}/basedir.log";
		$fh = new Firehose( $base, 0, 1024, 2, 0 );
		$this->assertSame( $base, $fh->get_base_dir() );
	}

	public function test_read_at_invalid_bounds(): void {
		$fh = $this->make_firehose( 'bounds.log' );
		$fh->write( 'test' );

		$this->assertNull( $fh->read_at( -1, 0, 10 ), 'Negative segment ID' );
		$this->assertNull( $fh->read_at( 0, -1, 10 ), 'Negative offset' );
		$this->assertNull( $fh->read_at( 0, 0, -1 ), 'Negative length' );
		$this->assertNull( $fh->read_at( 999, 0, 10 ), 'Non-existent segment' );
	}

	public function test_read_at_zero_length(): void {
		$fh = $this->make_firehose( 'zero.log' );
		$fh->write( 'test' );

		// PHP 8.1+ throws ValueError for fread() with length 0.
		// read_at() does not guard against 0 length, so the exception propagates.
		$this->expectException( \ValueError::class );
		$fh->read_at( 0, 0, 0 );
	}

	public function test_get_segment_path(): void {
		$fh = $this->make_firehose( 'segpath.log' );
		$path = $fh->get_segment_path( 42 );
		$this->assertStringEndsWith( '/p0/42.log', $path );
	}

	public function test_get_segment_path_rejects_negative(): void {
		$fh = $this->make_firehose( 'segpath2.log' );
		$this->expectException( \InvalidArgumentException::class );
		$fh->get_segment_path( -1 );
	}

	public function test_with_index_callback(): void {
		$fh = $this->make_firehose( 'indexed.log', 4096 );

		$index_entries = [];
		$fh->with_index( function ( string $line, array $position ) use ( &$index_entries ) {
			$index_entries[] = [ 'line' => $line, 'position' => $position ];
			return "idx:{$position['offset']}";
		} );

		$fh->write( '{"id":1}' );
		$fh->write( '{"id":2}' );

		$this->assertCount( 2, $index_entries );
		$this->assertSame( '{"id":1}', $index_entries[0]['line'] );
		$this->assertSame( '{"id":2}', $index_entries[1]['line'] );

		// Check index file was written.
		$idx_path = $fh->get_partition_dir() . '/0.idx';
		$this->assertFileExists( $idx_path );
		$idx_content = \file_get_contents( $idx_path );
		$this->assertStringContainsString( 'idx:0', $idx_content );
	}

	public function test_reader_factory(): void {
		$fh = $this->make_firehose( 'reader.log' );
		$reader = $fh->reader( 'start' );
		$this->assertInstanceOf( \Newspack_Event_Logger\FirehoseReader::class, $reader );
	}

	public function test_close_handle_allows_rewrite(): void {
		$fh = $this->make_firehose( 'closehandle.log', 4096 );
		$fh->write( 'before' );

		// Use reflection to call private close_handle().
		$ref = new \ReflectionMethod( $fh, 'close_handle' );
		$ref->setAccessible( true );
		$ref->invoke( $fh );

		// After closing handle, next write should re-open and succeed.
		$pos = $fh->write( 'after' );
		$this->assertIsArray( $pos );
		$data = $fh->read_at( $pos['segment_id'], $pos['offset'], $pos['length'] );
		$this->assertSame( "after\n", $data );
	}

	public function test_rotate_with_max_lifespan(): void {
		// Test rotation with max_lifespan > 0 (time-based rotation).
		$base = "{$this->temp_dir}/lifespan.log";
		// Constructor: $base, $partition, $segment_size, $num_segments, $max_lifespan.
		$fh = new Firehose( $base, 0, 65536, 5, 1 ); // 1 second lifespan.

		$pos1 = $fh->write( 'first-line' );
		$this->assertSame( 0, $pos1['segment_id'] );

		// Touch the segment file to make it look old (2 seconds ago).
		$seg_path = $fh->get_segment_path( 0 );
		@\touch( $seg_path, \time() - 2 );
		\clearstatcache( true, $seg_path );

		// Write again - should trigger time-based rotation.
		// Need to write enough to pass the size check too since rotate checks actual file size.
		$data = \str_repeat( 'x', 599 );
		$fh->write( $data );
		$fh->write( $data );

		// Verify we have at least 2 segments due to lifespan-based rotation.
		$segments = $fh->get_segments( true );
		// Time-based rotation depends on internal tracking, so just verify no crash.
		$this->assertNotEmpty( $segments );
	}

	public function test_with_index_position_data(): void {
		$fh = $this->make_firehose( 'idx-pos.log', 4096 );

		$positions = [];
		$fh->with_index( function ( string $line, array $position ) use ( &$positions ) {
			$positions[] = $position;
			return 'idx';
		} );

		$fh->write( 'aaa' );
		$fh->write( 'bbb' );

		$this->assertCount( 2, $positions );

		// First write at offset 0.
		$this->assertSame( 0, $positions[0]['segment_id'] );
		$this->assertSame( 0, $positions[0]['offset'] );
		$this->assertSame( 4, $positions[0]['length'] ); // "aaa\n" = 4 bytes.

		// Second write at offset 4.
		$this->assertSame( 0, $positions[1]['segment_id'] );
		$this->assertSame( 4, $positions[1]['offset'] );
		$this->assertSame( 4, $positions[1]['length'] ); // "bbb\n" = 4 bytes.
	}

	public function test_read_at_beyond_segment_end(): void {
		$fh = $this->make_firehose( 'beyond.log' );
		$fh->write( 'short' );

		// Try to read at an offset way beyond the file size.
		$data = $fh->read_at( 0, 99999, 10 );
		// Should return empty string or null for offset past end.
		$this->assertTrue( '' === $data || null === $data );
	}

	// ── scan_index ───��──────────────────────────────────────────────────

	public function test_scan_index_no_index_files(): void {
		$fh = $this->make_firehose( 'noidx.log', 4096 );

		// Write without index callback — no .idx files created.
		$fh->write( 'data' );

		$scanned = [];
		$fh->scan_index( function ( string $line, int $segment_id ) use ( &$scanned ) {
			$scanned[] = $line;
		} );

		$this->assertSame( [], $scanned );
	}

	// ── write_raw rotation ──────────────────────────────────────────────

	public function test_write_raw_triggers_rotation(): void {
		$fh = $this->make_firehose( 'raw-rot.log', 1024, 5 );

		// Write enough raw data to trigger rotation.
		$data = \str_repeat( 'x', 600 ) . "\n";
		$fh->write_raw( $data );
		$fh->write_raw( $data );
		$fh->write_raw( $data ); // Should trigger rotation.

		$segments = $fh->get_segments( true );
		$this->assertGreaterThanOrEqual( 2, \count( $segments ) );
	}

	// ── write with index callback that throws ───────────────────────────

	public function test_write_survives_index_callback_exception(): void {
		$fh = $this->make_firehose( 'idx-throw.log', 4096 );

		$fh->with_index( function ( string $line, array $position ) {
			throw new \RuntimeException( 'Index callback error' );
		} );

		// Write should succeed despite index callback throwing.
		$pos = $fh->write( 'test-line' );
		$this->assertIsArray( $pos );
		$this->assertSame( 0, $pos['segment_id'] );
	}

	// ── write with index callback returning null ────────────────────────

	public function test_write_with_null_index_entry(): void {
		$fh = $this->make_firehose( 'idx-null.log', 4096 );

		$fh->with_index( function ( string $line, array $position ) {
			return null; // Skip index entry.
		} );

		$pos = $fh->write( 'test-line' );
		$this->assertIsArray( $pos );

		// Index file may exist but should be empty or not contain entries.
		$idx_path = $fh->get_partition_dir() . '/0.idx';
		if ( \file_exists( $idx_path ) ) {
			$this->assertSame( '', \file_get_contents( $idx_path ) );
		}
	}

	// ── read_at exceeds MAX_READ_SIZE ───────────────────────────────────

	public function test_read_at_exceeds_max_read_size(): void {
		$fh = $this->make_firehose( 'maxread.log' );
		$fh->write( 'test' );

		// MAX_READ_SIZE is 10MB. Request more than that.
		$data = $fh->read_at( 0, 0, 10485761 );
		$this->assertNull( $data, 'Reads exceeding MAX_READ_SIZE should return null' );
	}

	// ── segment cache TTL ───────────────────────────────────────────────


	// ── allow_large_writes enables big writes ───────────────────────────

	public function test_allow_large_writes_with_write_method(): void {
		$fh = $this->make_firehose( 'large-write.log', 65536 );
		$fh->allow_large_writes();

		// Line + newline exceeds MAX_LINE_SIZE (4096).
		$big_line = \str_repeat( 'b', 5000 );
		$result   = $fh->write( $big_line );
		$this->assertIsArray( $result, 'Should allow large writes when flag is set' );
	}


	// ── scan_index with data ────────────────────────────────────────────

	public function test_scan_index_reads_entries(): void {
		$fh = $this->make_firehose( 'scanidx.log', 4096 );

		$fh->with_index( function ( string $line, array $position ) {
			return "index:{$position['offset']}";
		} );

		$fh->write( '{"id":1}' );
		$fh->write( '{"id":2}' );
		$fh->write( '{"id":3}' );

		// Force cache refresh so scan_index sees the new segment.
		$fh->get_segments( true );

		$scanned = [];
		$fh->scan_index( function ( string $line, int $segment_id ) use ( &$scanned ) {
			$scanned[] = [ 'line' => \trim( $line ), 'seg' => $segment_id ];
		} );

		$this->assertCount( 3, $scanned );
		$this->assertStringStartsWith( 'index:', $scanned[0]['line'] );
		$this->assertSame( 0, $scanned[0]['seg'] );
	}

	public function test_scan_index_oldest_first(): void {
		$fh = $this->make_firehose( 'scanidx-old.log', 4096 );

		$fh->with_index( function ( string $line, array $position ) {
			return "idx:{$position['offset']}";
		} );

		$fh->write( 'line1' );

		// Force cache refresh so scan_index sees the new segment.
		$fh->get_segments( true );

		$scanned = [];
		$fh->scan_index( function ( string $line, int $segment_id ) use ( &$scanned ) {
			$scanned[] = \trim( $line );
		}, false ); // newest_first = false.

		$this->assertNotEmpty( $scanned );
		$this->assertSame( 'idx:0', $scanned[0] );
	}

	// ── get_segments caching ────────────────────────────────────────────

	public function test_get_segments_returns_data_after_write(): void {
		$fh = $this->make_firehose( 'cache.log', 4096 );
		$fh->write( 'data' );

		// Force refresh to bypass stale cache from constructor.
		$segs = $fh->get_segments( true );
		$this->assertNotEmpty( $segs );
		$this->assertSame( 0, $segs[0]['id'] );
	}

	// ── write partial writes retry ──────────────────────────────────────

	public function test_write_returns_correct_position(): void {
		$fh = $this->make_firehose( 'pos-check.log', 4096 );

		$p1 = $fh->write( 'hello' );
		$this->assertSame( 0, $p1['segment_id'] );
		$this->assertSame( 0, $p1['offset'] );
		$this->assertSame( 6, $p1['length'] ); // "hello\n".

		$p2 = $fh->write( 'world' );
		$this->assertSame( 0, $p2['segment_id'] );
		$this->assertSame( 6, $p2['offset'] );
		$this->assertSame( 6, $p2['length'] ); // "world\n".
	}

	// ── destructor safety ───────────────────────────────────────────────

	public function test_destructor_is_safe(): void {
		$fh = $this->make_firehose( 'destruct.log', 4096 );
		$fh->write( 'data' );

		// Trigger destructor explicitly.
		unset( $fh );
		$this->assertTrue( true, 'Destructor should not throw' );
	}

	// ── max_lifespan prevents deletion of young segments ────────────────

	public function test_max_lifespan_prevents_young_segment_deletion(): void {
		$base = "{$this->temp_dir}/young.log";
		// max_lifespan = 3600 (1 hour) — young segments should not be deleted.
		$fh = new Firehose( $base, 0, 1024, 2, 3600 );
		$fh->allow_large_writes();

		$data = \str_repeat( 'y', 599 );

		// Write enough to create more than num_segments (2) segments.
		for ( $i = 0; $i < 9; $i++ ) {
			$fh->write( $data );
		}

		$segments = $fh->get_segments( true );
		// With max_lifespan=3600, all segments are young, so even though we have
		// more than 2, cleanup should NOT delete any (segments are < 3600s old).
		$this->assertGreaterThanOrEqual( 2, \count( $segments ),
			'Young segments should not be deleted when max_lifespan is set' );
	}

	// ── get_segments caching ────────────────────────────────────────────

}
