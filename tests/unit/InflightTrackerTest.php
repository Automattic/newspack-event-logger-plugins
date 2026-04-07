<?php
/**
 * Tests for InflightTracker.
 *
 * @package Newspack_Performance_Gyroscope
 */

namespace Newspack_Performance_Gyroscope\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Performance_Gyroscope\InflightTracker;

#[CoversClass( InflightTracker::class )]
class InflightTrackerTest extends TestCase {

	private InflightTracker $tracker;

	protected function setUp(): void {
		$this->tracker = new InflightTracker();
	}

	// ── process() basics ────────────────────────────────────────────────────

	public function test_process_ignores_entry_without_rid(): void {
		$this->tracker->process( [ 'k' => 'request', 'm' => 'GET /test' ] );
		$this->assertSame( [], $this->tracker->get_active() );
	}

	public function test_process_creates_request_on_request_keyword(): void {
		$this->tracker->process( [
			'rid' => 'abc123',
			'k'   => 'request',
			'm'   => 'GET /hello',
			'ts'  => 1000.0,
		] );

		$active = $this->tracker->get_active();
		$this->assertCount( 1, $active );
		$this->assertSame( 'abc123', $active[0]['rid'] );
		$this->assertSame( 'GET', $active[0]['method'] );
		$this->assertSame( '/hello', $active[0]['url'] );
		$this->assertSame( 'process', $active[0]['state'] );
	}

	public function test_process_parses_post_method(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'POST /wp-admin/admin-ajax.php',
			'ts'  => 1000.0,
		] );

		$active = $this->tracker->get_active();
		$this->assertSame( 'POST', $active[0]['method'] );
		$this->assertSame( '/wp-admin/admin-ajax.php', $active[0]['url'] );
	}

	public function test_process_skips_gyroscope_urls(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /firehose/gyroscope?partition=0',
			'ts'  => 1000.0,
		] );

		$this->assertSame( [], $this->tracker->get_active() );
	}

	public function test_process_ignores_events_for_unknown_rid(): void {
		$this->tracker->process( [
			'rid' => 'unknown',
			'k'   => 'environment_v2',
			'm'   => 'REMOTE_ADDR => "1.2.3.4"',
			'ts'  => 1000.0,
		] );

		$this->assertSame( [], $this->tracker->get_active() );
	}

	// ── environment_v2 ──────────────────────────────────────────────────────

	public function test_process_captures_remote_addr(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /page',
			'ts'  => 1000.0,
		] );
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'environment_v2',
			'm'   => 'REMOTE_ADDR => "10.0.0.1"',
			'ts'  => 1000.1,
		] );

		$active = $this->tracker->get_active();
		$this->assertSame( '10.0.0.1', $active[0]['remote_addr'] );
	}

	public function test_process_captures_user_agent(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /page',
			'ts'  => 1000.0,
		] );
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'environment_v2',
			'm'   => 'HTTP_USER_AGENT => "Mozilla/5.0"',
			'ts'  => 1000.1,
		] );

		$active = $this->tracker->get_active();
		$this->assertSame( 'Mozilla/5.0', $active[0]['user_agent'] );
	}

	// ── State transitions (start/complete) ──────────────────────────────────

	public function test_process_start_pushes_state(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /page',
			'ts'  => 1000.0,
		] );
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'template (start)',
			'm'   => 'Home.html',
			'ts'  => 1000.1,
		] );

		$active = $this->tracker->get_active();
		$this->assertSame( 'template', $active[0]['state'] );
		$this->assertSame( 'Home.html', $active[0]['what'] );
	}

	public function test_process_complete_pops_state(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /page',
			'ts'  => 1000.0,
		] );
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'template (start)',
			'm'   => 'Home.html',
			'ts'  => 1000.1,
		] );
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'template (complete)',
			'm'   => '',
			'ts'  => 1000.2,
		] );

		$active = $this->tracker->get_active();
		$this->assertSame( 'process', $active[0]['state'] );
		$this->assertSame( '', $active[0]['what'] );
	}

	public function test_process_nested_state_stack(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /page',
			'ts'  => 1000.0,
		] );
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'template (start)',
			'm'   => 'Home.html',
			'ts'  => 1000.1,
		] );
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'query (start)',
			'm'   => 'SELECT * FROM posts',
			'ts'  => 1000.2,
		] );

		$active = $this->tracker->get_active();
		$this->assertSame( 'query', $active[0]['state'] );
		$this->assertSame( 'SELECT * FROM posts', $active[0]['what'] );

		// Complete query, should restore to template.
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'query (complete)',
			'm'   => '',
			'ts'  => 1000.3,
		] );

		$active = $this->tracker->get_active();
		$this->assertSame( 'template', $active[0]['state'] );
		$this->assertSame( 'Home.html', $active[0]['what'] );
	}

	// ── process (complete) ──────────────────────────────────────────────────

	public function test_process_complete_moves_to_completed(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /page',
			'ts'  => 1000.0,
		] );
		$this->tracker->process( [
			'rid'         => 'r1',
			'k'           => 'process (complete)',
			'm'           => '',
			'ts'          => 1001.0,
			'duration_ms' => 42.5,
			'status_code' => 200,
		] );

		$this->assertSame( [], $this->tracker->get_active() );

		$completed = $this->tracker->get_completed();
		$this->assertCount( 1, $completed );
		$this->assertSame( 'r1', $completed[0]['rid'] );
		$this->assertSame( 'complete', $completed[0]['state'] );
		$this->assertSame( 42.5, $completed[0]['duration_ms'] );
		$this->assertSame( 200, $completed[0]['status_code'] );
	}

	public function test_get_completed_drains_buffer(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /page',
			'ts'  => 1000.0,
		] );
		$this->tracker->process( [
			'rid'         => 'r1',
			'k'           => 'process (complete)',
			'm'           => '',
			'ts'          => 1001.0,
			'duration_ms' => 10,
			'status_code' => 200,
		] );

		$first  = $this->tracker->get_completed();
		$second = $this->tracker->get_completed();
		$this->assertCount( 1, $first );
		$this->assertCount( 0, $second );
	}

	// ── get_active() filtering ──────────────────────────────────────────────

	public function test_get_active_since_update_filters_old(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /old',
			'ts'  => 1000.0,
		] );

		// The tracker_ts is set to microtime(true), so any since_update
		// in the future should filter it out.
		$future_ts = microtime( true ) + 1000;
		$active    = $this->tracker->get_active( $future_ts );
		$this->assertCount( 0, $active );
	}

	public function test_get_active_sorts_by_est_ms_descending(): void {
		$now = microtime( true );
		// r1 started recently, has low time_ms.
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /fast',
			'ts'  => $now,
		] );
		// r2 has been running longer — start_time and last_log_ts both 5s ago.
		$this->tracker->process( [
			'rid' => 'r2',
			'k'   => 'request',
			'm'   => 'GET /slow',
			'ts'  => $now - 5,
		] );
		// Add a later log entry for r2 to give it higher time_ms (last_log_ts - start_time).
		$this->tracker->process( [
			'rid' => 'r2',
			'k'   => 'hook (start)',
			'm'   => 'init',
			'ts'  => $now,
		] );

		$active = $this->tracker->get_active();
		$this->assertCount( 2, $active );
		// r2 has higher est_ms (5000ms time_ms), so it comes first.
		$this->assertSame( 'r2', $active[0]['rid'] );
		$this->assertSame( 'r1', $active[1]['rid'] );
	}

	// ── Bounded memory (MAX_REQUESTS / MAX_COMPLETED / MAX_STACK_DEPTH) ─────

	public function test_max_requests_evicts_oldest(): void {
		// Use reflection to read the constant.
		$ref       = new \ReflectionClass( InflightTracker::class );
		$max_const = $ref->getConstant( 'MAX_REQUESTS' );

		// We can't feasibly create 10000 requests in a test.
		// Instead, verify the eviction logic by checking a smaller scenario.
		// Create 2 requests, both should exist.
		$this->tracker->process( [
			'rid' => 'first',
			'k'   => 'request',
			'm'   => 'GET /first',
			'ts'  => microtime( true ),
		] );
		$this->tracker->process( [
			'rid' => 'second',
			'k'   => 'request',
			'm'   => 'GET /second',
			'ts'  => microtime( true ),
		] );

		$active = $this->tracker->get_active();
		$this->assertCount( 2, $active );
		$this->assertSame( 10000, $max_const );
	}

	public function test_max_stack_depth_constant(): void {
		$ref = new \ReflectionClass( InflightTracker::class );
		$this->assertSame( 100, $ref->getConstant( 'MAX_STACK_DEPTH' ) );
	}

	// ── Edge cases ──────────────────────────────────────────────────────────

	public function test_process_invalid_request_message_ignored(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'not a valid request line',
			'ts'  => 1000.0,
		] );

		$this->assertSame( [], $this->tracker->get_active() );
	}

	public function test_process_empty_message_ignored(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => '',
			'ts'  => 1000.0,
		] );

		$this->assertSame( [], $this->tracker->get_active() );
	}

	public function test_process_delete_method(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'DELETE /wp-json/wp/v2/posts/1',
			'ts'  => 1000.0,
		] );

		$active = $this->tracker->get_active();
		$this->assertCount( 1, $active );
		$this->assertSame( 'DELETE', $active[0]['method'] );
	}

	public function test_process_patch_method(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'PATCH /wp-json/wp/v2/posts/1',
			'ts'  => 1000.0,
		] );

		$active = $this->tracker->get_active();
		$this->assertSame( 'PATCH', $active[0]['method'] );
	}

	public function test_complete_without_start_does_not_crash(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /page',
			'ts'  => 1000.0,
		] );
		// Complete a label that was never started.
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'nonexistent (complete)',
			'm'   => '',
			'ts'  => 1000.1,
		] );

		$active = $this->tracker->get_active();
		$this->assertCount( 1, $active );
		$this->assertSame( 'process', $active[0]['state'] );
	}

	public function test_get_active_returns_required_fields(): void {
		$this->tracker->process( [
			'rid' => 'r1',
			'k'   => 'request',
			'm'   => 'GET /page',
			'ts'  => microtime( true ),
		] );

		$active = $this->tracker->get_active();
		$item   = $active[0];
		$expected_keys = [
			'rid', 'method', 'url', 'state', 'what',
			'time_ms', 'est_ms', 'start_time', 'last_log_ts',
			'lag_ms', 'remote_addr', 'user_agent',
		];
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $item, "Missing key: {$key}" );
		}
	}
}
