<?php
/**
 * Tests for ReqgrepCommand (firehose log grep with request reconstruction).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\LruCache;
use Newspack_Performance_Logger\CLI\ReqgrepCommand;

#[\PHPUnit\Framework\Attributes\CoversClass( ReqgrepCommand::class )]
class ReqgrepCommandTest extends TestCase {

	private ReqgrepCommand $cmd;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $process_line;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $format_entry;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $output_request;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $output_remaining;

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset();
	}

	/**
	 * Create a fresh command with accessible private methods.
	 *
	 * @param string $pattern Search pattern.
	 * @param bool   $raw     Raw output mode.
	 * @param bool   $incomplete Show incomplete requests.
	 * @param int    $bucket_size History bucket size.
	 * @param int    $num_buckets Number of history buckets.
	 * @return ReqgrepCommand
	 */
	private function make_cmd(
		string $pattern = '.',
		bool $raw = false,
		bool $incomplete = false,
		int $bucket_size = 250,
		int $num_buckets = 10
	): ReqgrepCommand {
		$cmd = new ReqgrepCommand();

		// Set private properties via reflection.
		$set = function ( string $prop, $value ) use ( $cmd ) {
			$ref = new \ReflectionProperty( $cmd, $prop );
			$ref->setAccessible( true );
			$ref->setValue( $cmd, $value );
		};

		$set( 'pattern', $pattern );
		$set( 'pattern_regex', '/' . \preg_quote( $pattern, '/' ) . '/i' );
		$set( 'raw', $raw );
		$set( 'incomplete', $incomplete );
		$set( 'bucket_size', $bucket_size );
		$set( 'num_buckets', $num_buckets );

		// Initialize in-flight LruCache with eviction → print [incomplete],
		// mirroring what __invoke() sets up.
		$inflight = ( new LruCache( 100, 3 ) )->with_timed_rotation(
			60.0,
			function ( string $rid, \stdClass $state ) use ( $cmd ) {
				$output = new \ReflectionMethod( $cmd, 'output_request' );
				$output->setAccessible( true );
				$output->invoke( $cmd, $state->lines );
				echo "[incomplete]\n\n";
			}
		);
		$set( 'inflight', $inflight );

		$this->process_line = new \ReflectionMethod( $cmd, 'process_line' );
		$this->process_line->setAccessible( true );

		$this->format_entry = new \ReflectionMethod( $cmd, 'format_entry' );
		$this->format_entry->setAccessible( true );

		$this->output_request = new \ReflectionMethod( $cmd, 'output_request' );
		$this->output_request->setAccessible( true );

		$this->output_remaining = new \ReflectionMethod( $cmd, 'output_remaining' );
		$this->output_remaining->setAccessible( true );

		$this->cmd = $cmd;
		return $cmd;
	}

	private function get_prop( string $prop ) {
		$ref = new \ReflectionProperty( $this->cmd, $prop );
		$ref->setAccessible( true );
		return $ref->getValue( $this->cmd );
	}

	/**
	 * Read the inflight LruCache and return an associative array of
	 * [rid => [line, line, ...]], matching the shape tests expect from
	 * the old $this->requests array.
	 */
	private function get_requests(): array {
		$inflight = $this->get_prop( 'inflight' );
		if ( null === $inflight ) {
			return [];
		}
		$out = [];
		foreach ( $inflight->iterate() as $rid => $state ) {
			$out[ $rid ] = $state->lines;
		}
		return $out;
	}

	/**
	 * Directly put a rid into the inflight cache (for tests that previously
	 * poked $this->requests[$rid] = [...]).
	 */
	private function set_request_lines( string $rid, array $lines ): void {
		$inflight = $this->get_prop( 'inflight' );
		$state        = new \stdClass();
		$state->lines = $lines;
		$state->bytes = 0;
		foreach ( $lines as $l ) {
			$state->bytes += \strlen( $l );
		}
		$inflight->set( $rid, $state );
	}

	private function set_prop( string $prop, $value ): void {
		$ref = new \ReflectionProperty( $this->cmd, $prop );
		$ref->setAccessible( true );
		$ref->setValue( $this->cmd, $value );
	}

	private function line( string $rid, string $key, $message = '', int $n = 1, float $ts = 0, array $extra = [] ): string {
		$data = [ 'n' => $n, 'rid' => $rid, 'k' => $key, 'ts' => $ts ?: \microtime( true ) ];
		if ( '' !== $message ) {
			$data['m'] = $message;
		}
		return \wp_json_encode( \array_merge( $data, $extra ) );
	}

	// ── process_line: basic request tracking ─────────────────────────────

	public function test_process_line_skips_empty(): void {
		$this->make_cmd();
		$this->process_line->invoke( $this->cmd, '' );
		$this->assertEmpty( $this->get_requests() );
	}

	public function test_process_line_skips_invalid_json(): void {
		$this->make_cmd();
		$this->process_line->invoke( $this->cmd, 'not-json{{{' );
		$this->assertEmpty( $this->get_requests() );
	}

	public function test_process_line_skips_entry_without_rid(): void {
		$this->make_cmd();
		$this->process_line->invoke( $this->cmd, '{"k":"test","n":1}' );
		$this->assertEmpty( $this->get_requests() );
	}

	public function test_process_line_tracks_matching_request(): void {
		$this->make_cmd( '/test' );

		$line = $this->line( 'r1', 'request', 'GET /test/page', 1 );
		$this->process_line->invoke( $this->cmd, $line );

		$requests = $this->get_requests();
		$this->assertArrayHasKey( 'r1', $requests );
		$this->assertCount( 1, $requests['r1'] );
	}

	public function test_process_line_accumulates_entries_for_tracked_request(): void {
		$this->make_cmd( '/test' );

		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'request', 'GET /test/page', 1 ) );
		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'hook (start)', 'init', 2 ) );
		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'hook (complete)', '', 3 ) );

		$requests = $this->get_requests();
		$this->assertCount( 3, $requests['r1'] );
	}

	public function test_process_line_outputs_on_complete(): void {
		$this->make_cmd( '/test' );

		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'request', 'GET /test/page', 1 ) );

		\ob_start();
		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'process (complete)', '', 2, 0, [ 'duration_ms' => 42 ] ) );
		$output = \ob_get_clean();

		// Request should be removed from tracking after output.
		$this->assertEmpty( $this->get_requests() );
		$this->assertNotEmpty( $output );
	}

	public function test_process_line_does_not_track_non_matching(): void {
		$this->make_cmd( '/calendar' );

		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'request', 'GET /about', 1 ) );

		$requests = $this->get_requests();
		$this->assertArrayNotHasKey( 'r1', $requests );
	}

	public function test_process_line_stores_non_matching_in_history(): void {
		$this->make_cmd( '/calendar' );

		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'request', 'GET /about', 1 ) );

		$history = $this->get_prop( 'history' );
		$recent  = \end( $history );
		$this->assertArrayHasKey( 'r1', $recent );
	}

	// ── process_line: history bucket rotation ────────────────────────────

	public function test_history_rotates_when_bucket_full(): void {
		$this->make_cmd( '/match-nothing-ever', false, false, 5, 3 );

		// Fill a bucket past the threshold.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->process_line->invoke( $this->cmd, $this->line( "r{$i}", 'request', "GET /page/{$i}", 1 ) );
		}

		$history = $this->get_prop( 'history' );
		$this->assertGreaterThan( 1, \count( $history ), 'History should have rotated' );
	}

	public function test_history_evicts_oldest_bucket(): void {
		$this->make_cmd( '/match-nothing-ever', false, false, 3, 2 );

		// Fill enough to trigger multiple rotations.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->process_line->invoke( $this->cmd, $this->line( "r{$i}", 'request', "GET /page/{$i}", 1 ) );
		}

		$history = $this->get_prop( 'history' );
		$this->assertLessThanOrEqual( 2, \count( $history ), 'Should not exceed num_buckets' );
	}

	// ── process_line: history recall ─────────────────────────────────────

	public function test_process_line_recovers_start_from_history(): void {
		$this->make_cmd( '/target' );

		// First line goes to history (doesn't match pattern).
		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'process (start)', '1234 on host', 1 ) );

		// Second line matches pattern — should pull r1's start from history.
		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'request', 'GET /target/page', 2 ) );

		$requests = $this->get_requests();
		$this->assertArrayHasKey( 'r1', $requests );
		$this->assertCount( 2, $requests['r1'], 'Should include the start line from history' );
	}

	// ── process_line: bounds checking ────────────────────────────────────

	public function test_process_line_caps_bytes_per_request(): void {
		$this->make_cmd( '.' );

		// Track a request.
		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'request', 'GET /', 1 ) );

		// Overflow the per-rid byte cap by pre-filling state->bytes near the max.
		$inflight    = $this->get_prop( 'inflight' );
		$state       = $inflight->get( 'r1' );
		$state->bytes = 1024 * 1024; // 1MB = MAX_BYTES_PER_REQUEST.

		// Next line should not be added because byte cap is hit.
		$line_count_before = \count( $state->lines );
		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'hook (start)', 'extra', 2 ) );

		$this->assertSame( $line_count_before, \count( $state->lines ), 'Line should be dropped once byte cap is hit' );
	}

	public function test_process_line_evicts_oldest_when_too_many_requests(): void {
		$this->make_cmd( '.' );

		// LruCache is 100 × 3 = 300 slots. Track 301 distinct requests —
		// the 301st should trigger a bucket rotation and evict the oldest
		// via the on_evict callback (which prints as [incomplete]).
		\ob_start();
		for ( $i = 0; $i < 301; $i++ ) {
			$this->process_line->invoke( $this->cmd, $this->line( "r{$i}", 'request', 'GET /', 1 ) );
		}
		$output = \ob_get_clean();

		$requests = $this->get_requests();
		// r0 should have been evicted from the oldest bucket.
		$this->assertArrayNotHasKey( 'r0', $requests, 'Oldest request should be evicted' );
		// At least one incomplete marker printed during rotation.
		$this->assertStringContainsString( '[incomplete]', $output );
	}

	// ── process_line: exact rid match ────────────────────────────────────

	public function test_process_line_matches_exact_rid(): void {
		$this->make_cmd( 'abc123' );

		$this->process_line->invoke( $this->cmd, $this->line( 'abc123', 'request', 'GET /unrelated', 1 ) );

		$requests = $this->get_requests();
		$this->assertArrayHasKey( 'abc123', $requests );
	}

	// ── process_line: incomplete mode ────────────────────────────────────

	public function test_process_line_incomplete_mode_does_not_output_on_complete(): void {
		$this->make_cmd( '.', false, true );

		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'request', 'GET /', 1 ) );

		\ob_start();
		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'process (complete)', '', 2 ) );
		$output = \ob_get_clean();

		// In incomplete mode, complete requests are not output inline.
		$this->assertEmpty( $output );
		// But the request should still be removed.
		$this->assertEmpty( $this->get_requests() );
	}

	// ── output_request: raw mode ─────────────────────────────────────────

	public function test_output_request_raw(): void {
		$this->make_cmd( '.', true );

		$lines = [
			'{"n":1,"rid":"r1","k":"request","m":"GET /"}',
			'{"n":2,"rid":"r1","k":"process (complete)"}',
		];

		\ob_start();
		$this->output_request->invoke( $this->cmd, $lines );
		$output = \ob_get_clean();

		$this->assertStringContainsString( $lines[0], $output );
		$this->assertStringContainsString( $lines[1], $output );
	}

	// ── output_remaining ─────────────────────────────────────────────────

	public function test_output_remaining_flushes_incomplete(): void {
		$this->make_cmd( '.' );

		$this->process_line->invoke( $this->cmd, $this->line( 'r1', 'request', 'GET /', 1 ) );

		\ob_start();
		$this->output_remaining->invoke( $this->cmd );
		$output = \ob_get_clean();

		$this->assertStringContainsString( '[incomplete]', $output );
	}

	// ── format_entry ─────────────────────────────────────────────────────

	public function test_format_entry_basic(): void {
		$this->make_cmd();

		$entry  = [ 'n' => 5, 'ts' => 1700000000.5, 'k' => 'hook (start)', 'm' => 'init', 'rid' => 'r1' ];
		$result = $this->format_entry->invoke( $this->cmd, $entry );

		$this->assertStringContainsString( 'hook (start)', $result );
		$this->assertStringContainsString( 'init', $result );
	}

	public function test_format_entry_indentation_increases_on_start(): void {
		$this->make_cmd();

		$this->format_entry->invoke( $this->cmd, [ 'n' => 1, 'ts' => 1700000000.0, 'k' => 'process (start)', 'm' => '', 'rid' => 'r1' ] );
		$indent_after = $this->get_prop( 'fmt_indent' );

		$this->assertSame( 4, $indent_after );
	}

	public function test_format_entry_indentation_decreases_on_complete(): void {
		$this->make_cmd();

		// Start increases indent.
		$this->format_entry->invoke( $this->cmd, [ 'n' => 1, 'ts' => 1700000000.0, 'k' => 'hook (start)', 'm' => 'init', 'rid' => 'r1' ] );
		$this->assertSame( 4, $this->get_prop( 'fmt_indent' ) );

		// Complete decreases indent.
		$this->format_entry->invoke( $this->cmd, [ 'n' => 2, 'ts' => 1700000000.1, 'k' => 'hook (complete)', 'm' => '', 'rid' => 'r1' ] );
		$this->assertSame( 0, $this->get_prop( 'fmt_indent' ) );
	}

	public function test_format_entry_indent_never_goes_negative(): void {
		$this->make_cmd();

		// Complete without matching start.
		$this->format_entry->invoke( $this->cmd, [ 'n' => 1, 'ts' => 1700000000.0, 'k' => 'hook (complete)', 'm' => '', 'rid' => 'r1' ] );
		$this->assertSame( 0, $this->get_prop( 'fmt_indent' ) );
	}

	public function test_format_entry_duration_suffix(): void {
		$this->make_cmd();

		$entry  = [ 'n' => 1, 'ts' => 1700000000.0, 'k' => 'hook (complete)', 'rid' => 'r1', 'duration_ms' => 42.5 ];
		$result = $this->format_entry->invoke( $this->cmd, $entry );

		$this->assertStringContainsString( '42.50ms', $result );
	}

	public function test_format_entry_peak_mb_suffix(): void {
		$this->make_cmd();

		$entry  = [ 'n' => 1, 'ts' => 1700000000.0, 'k' => 'memory', 'rid' => 'r1', 'm' => '', 'peak_mb' => 64 ];
		$result = $this->format_entry->invoke( $this->cmd, $entry );

		$this->assertStringContainsString( '[64MB]', $result );
	}

	public function test_output_request_emits_request_id_header(): void {
		$this->make_cmd();

		// Header is printed before any entries — independent of which entry
		// happens to be at n=1, so wp-admin requests (where process (start)
		// can land at n=28+) still surface the rid for cross-referencing.
		$lines = [
			\json_encode( [ 'n' => 1,  'ts' => 1700000000.0, 'k' => 'option_get', 'rid' => 'abc123' ] ),
			\json_encode( [ 'n' => 29, 'ts' => 1700000001.0, 'k' => 'process (start)', 'm' => '1234 on host', 'rid' => 'abc123' ] ),
		];

		\ob_start();
		$this->output_request->invoke( $this->cmd, $lines );
		$out = \ob_get_clean();

		$this->assertStringContainsString( 'request_id:abc123', $out );
		// Header should land *before* the first formatted entry.
		$rid_pos   = \strpos( $out, 'request_id:abc123' );
		$first_pos = \strpos( $out, 'option_get' );
		$this->assertNotFalse( $rid_pos );
		$this->assertNotFalse( $first_pos );
		$this->assertLessThan( $first_pos, $rid_pos, 'request_id header must precede the first entry' );
	}

	public function test_format_entry_timestamp_shown_when_changes(): void {
		$this->make_cmd();

		$result1 = $this->format_entry->invoke( $this->cmd, [ 'n' => 1, 'ts' => 1700000000.0, 'k' => 'a', 'rid' => 'r1' ] );
		$result2 = $this->format_entry->invoke( $this->cmd, [ 'n' => 2, 'ts' => 1700000000.0, 'k' => 'b', 'rid' => 'r1' ] );
		$result3 = $this->format_entry->invoke( $this->cmd, [ 'n' => 3, 'ts' => 1700000000.2, 'k' => 'c', 'rid' => 'r1' ] );

		// First line should have timestamp.
		$this->assertStringContainsString( '2023-11-14', $result1 );
		// Same 0.1s bucket — no timestamp.
		$this->assertStringNotContainsString( '2023-11-14', $result2 );
		// Different 0.1s bucket — timestamp shown again.
		$this->assertStringContainsString( '2023-11-14', $result3 );
	}

	public function test_format_entry_multiline_message_aligned(): void {
		$this->make_cmd();

		$entry  = [ 'n' => 1, 'ts' => 1700000000.0, 'k' => 'test', 'm' => "line1\nline2\nline3", 'rid' => 'r1' ];
		$result = $this->format_entry->invoke( $this->cmd, $entry );

		$lines = \explode( "\n", $result );
		// Should have multiple lines (the message + request_id since n=1).
		$this->assertGreaterThan( 1, \count( $lines ) );
	}

	public function test_format_entry_number_reset_inserts_separator(): void {
		$this->make_cmd();

		$this->format_entry->invoke( $this->cmd, [ 'n' => 10, 'ts' => 1700000000.0, 'k' => 'a', 'rid' => 'r1' ] );
		$result = $this->format_entry->invoke( $this->cmd, [ 'n' => 1, 'ts' => 1700000001.0, 'k' => 'b', 'rid' => 'r2' ] );

		$this->assertStringContainsString( '####', $result );
	}

	public function test_format_entry_array_message(): void {
		$this->make_cmd();

		$entry  = [ 'n' => 1, 'ts' => 1700000000.0, 'k' => 'memory', 'rid' => 'r1', 'm' => [ 'peak' => 64 ] ];
		$result = $this->format_entry->invoke( $this->cmd, $entry );

		$this->assertStringContainsString( '"peak"', $result );
	}

	public function test_format_entry_elapsed_dots(): void {
		$this->make_cmd();

		// First entry at t=100.
		$this->format_entry->invoke( $this->cmd, [ 'n' => 1, 'ts' => 1700000000.0, 'k' => 'a', 'rid' => 'r1' ] );

		// Next entry 3 seconds later — should have 2 dot lines for the gap.
		$result = $this->format_entry->invoke( $this->cmd, [ 'n' => 2, 'ts' => 1700000003.0, 'k' => 'b', 'rid' => 'r1' ] );

		$dot_count = \substr_count( $result, '.' );
		// The dots themselves plus any timestamp dots — at minimum we should see the elapsed dots.
		$this->assertGreaterThan( 0, $dot_count );
	}

	// ── get_firehose: caching ───────────────────────────────────────────

	public function test_get_firehose_returns_firehose_instance(): void {
		$this->make_cmd();

		// Create a temp firehose directory.
		$base = '/tmp/test-reqgrep-firehose-' . \getmypid();
		@\mkdir( "{$base}/p0", 0755, true );

		$this->set_prop( 'base_dir', $base );

		$get_firehose = new \ReflectionMethod( $this->cmd, 'get_firehose' );
		$get_firehose->setAccessible( true );

		$firehose = $get_firehose->invoke( $this->cmd, 0 );
		$this->assertInstanceOf( \Newspack_Event_Logger\Firehose::class, $firehose );

		// Second call should return cached instance.
		$firehose2 = $get_firehose->invoke( $this->cmd, 0 );
		$this->assertSame( $firehose, $firehose2, 'get_firehose should cache instances' );

		// Clean up.
		@\rmdir( "{$base}/p0" );
		@\rmdir( $base );
	}

	// ── cat_mode: full read through firehose segments ───────────────────

	public function test_cat_mode_reads_all_segments(): void {
		$this->make_cmd( '/test' );

		$base = '/tmp/test-reqgrep-cat-' . \getmypid();
		@\mkdir( "{$base}/p0", 0755, true );

		$ts = 1700000000.0;
		$lines = [
			\wp_json_encode( [ 'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '1 on host', 'ts' => $ts ] ),
			\wp_json_encode( [ 'n' => 2, 'rid' => 'r1', 'k' => 'request', 'm' => 'GET /test/page', 'ts' => $ts + 0.01 ] ),
			\wp_json_encode( [ 'n' => 3, 'rid' => 'r1', 'k' => 'process (complete)', 'ts' => $ts + 0.02, 'duration_ms' => 42 ] ),
		];
		\file_put_contents( "{$base}/p0/0.log", \implode( "\n", $lines ) . "\n" );

		$this->set_prop( 'base_dir', $base );
		$this->set_prop( 'num_partitions', 1 );

		$cat_mode = new \ReflectionMethod( $this->cmd, 'cat_mode' );
		$cat_mode->setAccessible( true );

		\ob_start();
		$cat_mode->invoke( $this->cmd );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'process (start)', $output );
		$this->assertStringContainsString( 'GET /test/page', $output );
		$this->assertStringContainsString( '42.00ms', $output );

		// Clean up.
		@\unlink( "{$base}/p0/0.log" );
		@\rmdir( "{$base}/p0" );
		@\rmdir( $base );
	}

	public function test_cat_mode_outputs_incomplete_at_end(): void {
		$this->make_cmd( '/test' );

		$base = '/tmp/test-reqgrep-cat-inc-' . \getmypid();
		@\mkdir( "{$base}/p0", 0755, true );

		$ts = 1700000000.0;
		// Request without process (complete) — should be flushed as incomplete at end.
		$lines = [
			\wp_json_encode( [ 'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '1 on host', 'ts' => $ts ] ),
			\wp_json_encode( [ 'n' => 2, 'rid' => 'r1', 'k' => 'request', 'm' => 'GET /test/page', 'ts' => $ts + 0.01 ] ),
		];
		\file_put_contents( "{$base}/p0/0.log", \implode( "\n", $lines ) . "\n" );

		$this->set_prop( 'base_dir', $base );
		$this->set_prop( 'num_partitions', 1 );

		$cat_mode = new \ReflectionMethod( $this->cmd, 'cat_mode' );
		$cat_mode->setAccessible( true );

		\ob_start();
		$cat_mode->invoke( $this->cmd );
		$output = \ob_get_clean();

		$this->assertStringContainsString( '[incomplete]', $output );

		// Clean up.
		@\unlink( "{$base}/p0/0.log" );
		@\rmdir( "{$base}/p0" );
		@\rmdir( $base );
	}

	// ── __invoke: path validation ───────────────────────────────────────

	public function test_invoke_rejects_invalid_path(): void {
		$cmd = new ReqgrepCommand();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid path' );

		// __invoke drains all output buffers (intentional, see class doc).
		// Restore PHPUnit's buffer level after the exception propagates.
		$buffer_level = \ob_get_level();
		try {
			$cmd( [ 'test' ], [ 'path' => '/nonexistent/path/that/does/not/exist' ] );
		} finally {
			while ( \ob_get_level() < $buffer_level ) {
				\ob_start();
			}
		}
	}

	// ── process_line: stale request cleanup ─────────────────────────────

	public function test_process_line_cleans_stale_requests(): void {
		$this->make_cmd( '.' );

		// Track a request.
		$this->process_line->invoke( $this->cmd, $this->line( 'stale-r1', 'request', 'GET /', 1 ) );

		// Force the LruCache's last_rotation time into the past so the next
		// rotate_if_due() call (at the end of process_line) fires a rotation.
		// After NUM_BUCKETS rotations the stale-r1 bucket rolls out and the
		// on_evict callback prints it as [incomplete].
		$inflight = $this->get_prop( 'inflight' );
		$ref      = new \ReflectionProperty( $inflight, 'last_rotation' );
		$ref->setAccessible( true );

		\ob_start();
		// Each call advances one "rotation window" back; need INFLIGHT_NUM_BUCKETS
		// rotations (3) to push the bucket holding stale-r1 out.
		for ( $i = 0; $i < 4; $i++ ) {
			$ref->setValue( $inflight, \microtime( true ) - 120.0 ); // >60s ago.
			$this->process_line->invoke( $this->cmd, $this->line( "fill-{$i}", 'request', 'GET /', 1 ) );
		}
		$output = \ob_get_clean();

		// Stale request should have been flushed via time-based eviction.
		$this->assertStringContainsString( '[incomplete]', $output );
		$requests = $this->get_requests();
		$this->assertArrayNotHasKey( 'stale-r1', $requests, 'Stale request should be removed' );
	}

	// ── output_request: non-JSON line fallback ──────────────────────────

	public function test_output_request_non_json_line_echoed_as_is(): void {
		$this->make_cmd();

		$lines = [
			'this is not JSON at all',
			\wp_json_encode( [ 'n' => 2, 'rid' => 'r1', 'k' => 'request', 'm' => 'GET /', 'ts' => 1700000000.0 ] ),
		];

		\ob_start();
		$this->output_request->invoke( $this->cmd, $lines );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'this is not JSON at all', $output );
		$this->assertStringContainsString( 'request', $output );
	}

	// ── process_line: history warning when start not found ──────────────

	public function test_history_warning_when_start_not_in_history(): void {
		// Use small buckets that will be evicted quickly.
		$this->make_cmd( '/target', false, false, 2, 2 );

		// Fill history buckets with unrelated data to push out old entries.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->process_line->invoke( $this->cmd, $this->line( "fill-{$i}", 'request', "GET /unrelated/{$i}", 1 ) );
		}

		// Now match a line with n > 1 (not the start). History has been rotated,
		// so the original start should be gone.
		\WP_CLI::reset();
		$this->process_line->invoke( $this->cmd, $this->line( 'late-r1', 'request', 'GET /target/page', 5, 0, [] ) );

		$warnings = \array_filter( \WP_CLI::$log, fn( $e ) => 'warning' === $e['level'] );
		$this->assertNotEmpty( $warnings, 'Should warn when request start not found in history' );
	}

	// ── process_line: new matching request complete on same line ─────────

	public function test_new_matching_request_outputs_on_complete_line(): void {
		$this->make_cmd( '/test' );

		// A single line that both matches AND is process (complete).
		$line = $this->line( 'r1', 'process (complete)', 'GET /test', 1, 0, [ 'duration_ms' => 10 ] );

		\ob_start();
		$this->process_line->invoke( $this->cmd, $line );
		$output = \ob_get_clean();

		// Should output the request immediately.
		$this->assertNotEmpty( $output, 'Complete line that matches should output immediately' );
		$this->assertEmpty( $this->get_requests(), 'Request should be removed after output' );
	}

	// ── process_line: history MAX_LINES_PER_REQUEST_IN_HISTORY ──────────

	public function test_history_caps_lines_per_request(): void {
		$this->make_cmd( '/match-nothing', false, false, 100000, 2 );

		// Set up a request in the current history bucket that's at the cap.
		$history = $this->get_prop( 'history' );
		$recent_idx = \count( $history ) - 1;
		$history[ $recent_idx ]['capped-r1'] = \array_fill( 0, 10000, '{}' );
		$this->set_prop( 'history', $history );

		// This line for the same request should not be added (at cap).
		$this->process_line->invoke( $this->cmd, $this->line( 'capped-r1', 'extra', 'more data', 10001 ) );

		$history = $this->get_prop( 'history' );
		$recent_idx = \count( $history ) - 1;
		$this->assertCount( 10000, $history[ $recent_idx ]['capped-r1'], 'History should cap at MAX_LINES_PER_REQUEST_IN_HISTORY' );
	}

	// ── output_request: raw mode with multiple lines ────────────────────

	public function test_output_request_formatted_mode(): void {
		$this->make_cmd( '.', false );

		$lines = [
			\wp_json_encode( [ 'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '1 on host', 'ts' => 1700000000.0 ] ),
			\wp_json_encode( [ 'n' => 2, 'rid' => 'r1', 'k' => 'request', 'm' => 'GET /', 'ts' => 1700000000.1 ] ),
			\wp_json_encode( [ 'n' => 3, 'rid' => 'r1', 'k' => 'process (complete)', 'ts' => 1700000000.2, 'duration_ms' => 42 ] ),
		];

		\ob_start();
		$this->output_request->invoke( $this->cmd, $lines );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'process (start)', $output );
		$this->assertStringContainsString( 'request_id:r1', $output );
		$this->assertStringContainsString( '42.00ms', $output );
	}

	// ── cat_mode: no partitions — no output ─────────────────────────────

	public function test_cat_mode_with_empty_partition(): void {
		$this->make_cmd( '/test' );

		$base = '/tmp/test-reqgrep-empty-' . \getmypid();
		@\mkdir( "{$base}/p0", 0755, true );

		// Empty segment file.
		\file_put_contents( "{$base}/p0/0.log", '' );

		$this->set_prop( 'base_dir', $base );
		$this->set_prop( 'num_partitions', 1 );

		$cat_mode = new \ReflectionMethod( $this->cmd, 'cat_mode' );
		$cat_mode->setAccessible( true );

		\ob_start();
		$cat_mode->invoke( $this->cmd );
		$output = \ob_get_clean();

		// No data, so no output.
		$this->assertEmpty( \trim( $output ) );

		// Clean up.
		@\unlink( "{$base}/p0/0.log" );
		@\rmdir( "{$base}/p0" );
		@\rmdir( $base );
	}

	// ── __invoke: path validation ───────────────────────────────────────

	public function test_invoke_path_must_be_within_logs_dir(): void {
		$cmd = new ReqgrepCommand();

		// Create a temp dir OUTSIDE the logs directory.
		$outside_dir = '/tmp/test-reqgrep-outside-' . \getmypid();
		@\mkdir( $outside_dir, 0755, true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Path must be within the logs directory' );

		// __invoke drains all output buffers (intentional, see class doc).
		// Restore PHPUnit's buffer level after the exception propagates.
		$buffer_level = \ob_get_level();
		try {
			$cmd( [ 'test' ], [ 'path' => $outside_dir ] );
		} finally {
			@\rmdir( $outside_dir );
			while ( \ob_get_level() < $buffer_level ) {
				\ob_start();
			}
		}
	}
}
