<?php
/**
 * Tests for SSEClient.
 *
 * Tests parsing, state management, and reconnection logic.
 * Does NOT test actual cURL connections - those require network.
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Aggregator\SSEClient;

#[CoversClass( SSEClient::class )]
class SSEClientTest extends TestCase {

	private SSEClient $client;

	protected function setUp(): void {
		$this->client = new SSEClient(
			'test-server',
			'https://example.com',
			'user',
			'pass',
			0,
			false
		);
	}

	protected function tearDown(): void {
		$this->client->close();
	}

	// ── Constructor / Initial State ─────────────────────────────────────────

	public function test_initial_state_not_connected(): void {
		$this->assertFalse( $this->client->is_connected() );
	}

	public function test_initial_position_is_zero(): void {
		$pos = $this->client->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_initial_slot_is_null(): void {
		$this->assertNull( $this->client->get_slot() );
	}

	public function test_initial_backoff_is_one(): void {
		$this->assertSame( 1, $this->client->get_backoff() );
	}

	public function test_initial_last_error_is_null(): void {
		$this->assertNull( $this->client->get_last_error() );
	}

	public function test_initial_last_http_code_is_null(): void {
		$this->assertNull( $this->client->get_last_http_code() );
	}

	public function test_read_event_returns_null_when_empty(): void {
		$this->assertNull( $this->client->read_event() );
	}

	// ── SSE Line Parsing (via handle_data callback) ─────────────────────────

	/**
	 * Helper: Feed raw SSE data into the client's handle_data method.
	 * Requires a connected client with a real cURL handle (or we use reflection).
	 */
	private function feed_sse_data( string $data ): void {
		// Use reflection to call parse_sse_line directly since handle_data
		// requires a CurlHandle. We'll feed data via the buffer + parse approach.
		$ref = new \ReflectionClass( $this->client );

		// Set buffer.
		$buffer_prop = $ref->getProperty( 'buffer' );
		$buffer_prop->setAccessible( true );
		$buffer_prop->setValue( $this->client, $buffer_prop->getValue( $this->client ) . $data );

		// Parse lines from buffer (same logic as handle_data).
		$buffer = $buffer_prop->getValue( $this->client );
		$parse  = $ref->getMethod( 'parse_sse_line' );
		$parse->setAccessible( true );

		while ( false !== ( $pos = strpos( $buffer, "\n" ) ) ) {
			$line   = substr( $buffer, 0, $pos );
			$buffer = substr( $buffer, $pos + 1 );
			$line   = rtrim( $line, "\r" );
			$parse->invoke( $this->client, $line );
		}

		$buffer_prop->setValue( $this->client, $buffer );
	}

	/**
	 * Helper: Set the connected flag + last_event_time so dispatch works.
	 */
	private function set_connected(): void {
		$ref = new \ReflectionClass( $this->client );

		$connected = $ref->getProperty( 'connected' );
		$connected->setAccessible( true );
		$connected->setValue( $this->client, true );

		$let = $ref->getProperty( 'last_event_time' );
		$let->setAccessible( true );
		$let->setValue( $this->client, microtime( true ) );
	}

	public function test_parse_entry_event(): void {
		$this->set_connected();
		$this->feed_sse_data( "event: entry\ndata: {\"rid\":\"abc\",\"k\":\"test\"}\n\n" );

		$event = $this->client->read_event();
		$this->assertNotNull( $event );
		$this->assertSame( 'entry', $event['type'] );
		$this->assertSame( 'abc', $event['data']['rid'] );
	}

	public function test_parse_heartbeat_updates_position(): void {
		$this->set_connected();
		$this->feed_sse_data( "event: heartbeat\ndata: {\"position\":{\"segment_id\":5,\"offset\":1024}}\n\n" );

		$event = $this->client->read_event();
		$this->assertNotNull( $event );
		$this->assertSame( 'heartbeat', $event['type'] );

		$pos = $this->client->get_position();
		$this->assertSame( 5, $pos['segment_id'] );
		$this->assertSame( 1024, $pos['offset'] );
	}

	public function test_parse_connected_event_captures_slot(): void {
		$this->set_connected();
		$this->feed_sse_data( "event: connected\ndata: {\"slot\":3}\n\n" );

		$event = $this->client->read_event();
		$this->assertNotNull( $event );
		$this->assertSame( 'connected', $event['type'] );
		$this->assertSame( 3, $this->client->get_slot() );
	}

	public function test_parse_entry_with_position_updates_position(): void {
		$this->set_connected();
		$this->feed_sse_data( "event: entry\ndata: {\"rid\":\"x\",\"position\":{\"segment_id\":10,\"offset\":2048}}\n\n" );

		$event = $this->client->read_event();
		$this->assertNotNull( $event );

		// Position should be updated but stripped from data.
		$this->assertArrayNotHasKey( 'position', $event['data'] );

		$pos = $this->client->get_position();
		$this->assertSame( 10, $pos['segment_id'] );
		$this->assertSame( 2048, $pos['offset'] );
	}

	public function test_parse_ignores_empty_event_type(): void {
		$this->set_connected();
		// data without event: line should be ignored (event type is '').
		$this->feed_sse_data( "data: {\"foo\":\"bar\"}\n\n" );

		$event = $this->client->read_event();
		$this->assertNull( $event );
	}

	public function test_parse_ignores_comment_lines(): void {
		$this->set_connected();
		// Lines without a colon are ignored per SSE spec.
		$this->feed_sse_data( "no-colon-here\nevent: test\ndata: {}\n\n" );

		$event = $this->client->read_event();
		$this->assertNotNull( $event );
		$this->assertSame( 'test', $event['type'] );
	}

	public function test_parse_strips_leading_space_after_colon(): void {
		$this->set_connected();
		$this->feed_sse_data( "event: test\ndata: {\"key\":\"value\"}\n\n" );

		$event = $this->client->read_event();
		$this->assertSame( 'value', $event['data']['key'] );
	}

	public function test_multiple_events_queued(): void {
		$this->set_connected();
		$this->feed_sse_data(
			"event: entry\ndata: {\"n\":1}\n\n"
			. "event: entry\ndata: {\"n\":2}\n\n"
		);

		$e1 = $this->client->read_event();
		$e2 = $this->client->read_event();
		$e3 = $this->client->read_event();

		$this->assertNotNull( $e1 );
		$this->assertNotNull( $e2 );
		$this->assertNull( $e3 );
		$this->assertSame( 1, $e1['data']['n'] );
		$this->assertSame( 2, $e2['data']['n'] );
	}

	// ── Backoff Logic ───────────────────────────────────────────────────────

	public function test_backoff_increases_on_handle_completion_error(): void {
		// Simulate a cURL completion with error using reflection.
		$ref = new \ReflectionClass( $this->client );

		$increase = $ref->getMethod( 'increase_backoff' );
		$increase->setAccessible( true );

		$this->assertSame( 1, $this->client->get_backoff() );
		$increase->invoke( $this->client );
		$this->assertSame( 2, $this->client->get_backoff() );
		$increase->invoke( $this->client );
		$this->assertSame( 4, $this->client->get_backoff() );
		$increase->invoke( $this->client );
		$this->assertSame( 8, $this->client->get_backoff() );
		$increase->invoke( $this->client );
		$this->assertSame( 16, $this->client->get_backoff() );
		$increase->invoke( $this->client );
		$this->assertSame( 30, $this->client->get_backoff() ); // Capped at MAX_BACKOFF.
		$increase->invoke( $this->client );
		$this->assertSame( 30, $this->client->get_backoff() ); // Stays at cap.
	}

	public function test_backoff_resets_on_successful_event(): void {
		$ref = new \ReflectionClass( $this->client );

		// Increase backoff first.
		$increase = $ref->getMethod( 'increase_backoff' );
		$increase->setAccessible( true );
		$increase->invoke( $this->client );
		$increase->invoke( $this->client );
		$this->assertSame( 4, $this->client->get_backoff() );

		// Receiving an event resets backoff (dispatch_event sets it to INITIAL_BACKOFF).
		$this->set_connected();
		$this->feed_sse_data( "event: heartbeat\ndata: {\"position\":{\"segment_id\":0,\"offset\":0}}\n\n" );
		$this->client->read_event();

		$this->assertSame( 1, $this->client->get_backoff() );
	}

	// ── check_stale() ───────────────────────────────────────────────────────

	public function test_check_stale_returns_false_when_not_connected(): void {
		$this->assertFalse( $this->client->check_stale() );
	}

	public function test_check_stale_returns_false_when_recent(): void {
		$this->set_connected();
		$this->assertFalse( $this->client->check_stale() );
	}

	public function test_check_stale_returns_true_when_old(): void {
		$ref = new \ReflectionClass( $this->client );

		$connected = $ref->getProperty( 'connected' );
		$connected->setAccessible( true );
		$connected->setValue( $this->client, true );

		// Set last_event_time far in the past.
		$let = $ref->getProperty( 'last_event_time' );
		$let->setAccessible( true );
		$let->setValue( $this->client, microtime( true ) - 60 ); // 60s ago, timeout is 45s.

		$this->assertTrue( $this->client->check_stale() );
		$this->assertFalse( $this->client->is_connected() );
		$this->assertNotNull( $this->client->get_last_error() );
		$this->assertStringContainsString( 'Stale connection', $this->client->get_last_error() );
	}

	// ── close() ─────────────────────────────────────────────────────────────

	public function test_close_resets_connected_and_slot(): void {
		$this->set_connected();
		$ref  = new \ReflectionClass( $this->client );
		$slot = $ref->getProperty( 'slot' );
		$slot->setAccessible( true );
		$slot->setValue( $this->client, 5 );

		$this->client->close();

		$this->assertFalse( $this->client->is_connected() );
		$this->assertNull( $this->client->get_slot() );
	}

	// ── get_time_until_reconnect() ──────────────────────────────────────────

	public function test_time_until_reconnect_is_zero_initially(): void {
		// No connect attempt made yet, last_connect_attempt is 0.
		$this->assertSame( 0.0, $this->client->get_time_until_reconnect() );
	}

	// ── Constants ───────────────────────────────────────────────────────────

	public function test_max_backoff_constant(): void {
		$ref = new \ReflectionClass( SSEClient::class );
		$this->assertSame( 30, $ref->getConstant( 'MAX_BACKOFF' ) );
	}

	public function test_heartbeat_timeout_constant(): void {
		$ref = new \ReflectionClass( SSEClient::class );
		$this->assertSame( 45, $ref->getConstant( 'HEARTBEAT_TIMEOUT' ) );
	}

	public function test_connect_timeout_constant(): void {
		$ref = new \ReflectionClass( SSEClient::class );
		$this->assertSame( 5, $ref->getConstant( 'CONNECT_TIMEOUT' ) );
	}

	// ── CRLF handling ───────────────────────────────────────────────────────

	public function test_parse_handles_crlf_line_endings(): void {
		$this->set_connected();
		$this->feed_sse_data( "event: entry\r\ndata: {\"k\":\"v\"}\r\n\r\n" );

		$event = $this->client->read_event();
		$this->assertNotNull( $event );
		$this->assertSame( 'entry', $event['type'] );
		$this->assertSame( 'v', $event['data']['k'] );
	}

	// ── Heartbeat position strips from data ─────────────────────────────────

	public function test_heartbeat_strips_position_from_event_data(): void {
		$this->set_connected();
		$this->feed_sse_data( "event: heartbeat\ndata: {\"position\":{\"segment_id\":1,\"offset\":100},\"extra\":true}\n\n" );

		$event = $this->client->read_event();
		$this->assertArrayNotHasKey( 'position', $event['data'] );
		$this->assertTrue( $event['data']['extra'] );
	}

	// ── connect() backoff rate limiting ─────────────────────────────────

	public function test_connect_respects_backoff(): void {
		// Increase backoff.
		$ref      = new \ReflectionClass( $this->client );
		$increase = $ref->getMethod( 'increase_backoff' );
		$increase->setAccessible( true );
		$increase->invoke( $this->client ); // backoff = 2

		// Set last_connect_attempt to now.
		$lca = $ref->getProperty( 'last_connect_attempt' );
		$lca->setAccessible( true );
		$lca->setValue( $this->client, microtime( true ) );

		// Connect should fail due to backoff.
		$result = $this->client->connect();
		$this->assertFalse( $result );
	}

	public function test_connect_succeeds_after_backoff_expires(): void {
		$ref = new \ReflectionClass( $this->client );

		// Set last_connect_attempt far in the past.
		$lca = $ref->getProperty( 'last_connect_attempt' );
		$lca->setAccessible( true );
		$lca->setValue( $this->client, microtime( true ) - 100 );

		// Connect should succeed (creates cURL handle).
		$result = $this->client->connect();
		$this->assertTrue( $result );
		$this->assertTrue( $this->client->is_connected() );
		$this->assertNotNull( $this->client->get_handle() );
	}

	// ── connect() with position ─────────────────────────────────────────

	public function test_connect_with_position(): void {
		$ref = new \ReflectionClass( $this->client );
		$lca = $ref->getProperty( 'last_connect_attempt' );
		$lca->setAccessible( true );
		$lca->setValue( $this->client, 0 );

		$result = $this->client->connect( [ 'segment_id' => 5, 'offset' => 1024 ] );
		$this->assertTrue( $result );
	}

	// ── handle_completion sets error ────────────────────────────────────

	public function test_handle_completion_without_handle(): void {
		// handle_completion with no cURL handle should be a no-op.
		$this->client->handle_completion( CURLE_OK );
		$this->assertNull( $this->client->get_last_error() );
	}

	// ── check_stale increases backoff ───────────────────────────────────

	public function test_check_stale_increases_backoff(): void {
		$ref = new \ReflectionClass( $this->client );

		$connected = $ref->getProperty( 'connected' );
		$connected->setAccessible( true );
		$connected->setValue( $this->client, true );

		$let = $ref->getProperty( 'last_event_time' );
		$let->setAccessible( true );
		$let->setValue( $this->client, microtime( true ) - 60 );

		$this->assertSame( 1, $this->client->get_backoff() );
		$this->client->check_stale();
		$this->assertSame( 2, $this->client->get_backoff() );
	}

	// ── get_time_until_reconnect after connect attempt ──────────────────

	public function test_time_until_reconnect_after_attempt(): void {
		$ref = new \ReflectionClass( $this->client );

		$lca = $ref->getProperty( 'last_connect_attempt' );
		$lca->setAccessible( true );
		$lca->setValue( $this->client, microtime( true ) );

		// With default backoff of 1s, time until reconnect should be ~1s.
		$remaining = $this->client->get_time_until_reconnect();
		$this->assertGreaterThan( 0.0, $remaining );
		$this->assertLessThanOrEqual( 1.0, $remaining );
	}

	// ── close() is idempotent ───────────────────────────────────────────

	public function test_close_is_idempotent(): void {
		$this->client->close();
		$this->client->close();
		$this->assertFalse( $this->client->is_connected() );
	}

	// ── get_handle returns null when not connected ──────────────────────

	public function test_get_handle_returns_null_when_not_connected(): void {
		$this->assertNull( $this->client->get_handle() );
	}

	// ── parse partial data (no newline yet) ─────────────────────────────

	public function test_parse_incomplete_line_buffered(): void {
		$this->set_connected();
		// Feed data without trailing newline — should be buffered.
		$this->feed_sse_data( "event: entry" );

		$event = $this->client->read_event();
		$this->assertNull( $event, 'Incomplete line should not produce event' );

		// Now send the rest.
		$this->feed_sse_data( "\ndata: {\"n\":1}\n\n" );
		$event = $this->client->read_event();
		$this->assertNotNull( $event );
		$this->assertSame( 'entry', $event['type'] );
	}

	// ── Regression: Event data overflow disconnects ────────────────────

	public function test_event_data_overflow_disconnects(): void {
		$this->set_connected();

		// Feed many data: lines (~4000 bytes each) without a blank line to dispatch.
		// MAX_EVENT_SIZE is 10MB; we need to exceed it.
		// Each line: "data: " (6 bytes) + 4000 'A' chars + "\n" (1 byte).
		// The data: value accumulated is 4000 bytes per line (+ newlines between).
		// 10MB / 4000 = 2622 lines needed. Use 2700 to be safe.
		$chunk = 'data: ' . str_repeat( 'A', 4000 ) . "\n";
		$batch = str_repeat( $chunk, 100 );

		// Feed in batches to avoid building one giant string.
		for ( $i = 0; $i < 27; $i++ ) {
			$this->feed_sse_data( $batch );
		}

		// Client should have detected overflow and disconnected.
		$this->assertFalse( $this->client->is_connected() );
		$this->assertNotNull( $this->client->get_last_error() );
		$this->assertStringContainsString( 'overflow', $this->client->get_last_error() );
	}

	// ── Regression: Position values cast to int ─────────────────────────

	public function test_position_values_cast_to_int(): void {
		$this->set_connected();
		$this->feed_sse_data( "event: heartbeat\ndata: {\"position\":{\"segment_id\":\"5\",\"offset\":\"1024\"}}\n\n" );

		$this->client->read_event();
		$pos = $this->client->get_position();

		$this->assertSame( 5, $pos['segment_id'], 'segment_id should be int, not string' );
		$this->assertSame( 1024, $pos['offset'], 'offset should be int, not string' );
	}

	// ── Regression: Negative position values clamped to zero ────────────

	public function test_position_negative_values_clamped_to_zero(): void {
		$this->set_connected();
		$this->feed_sse_data( "event: heartbeat\ndata: {\"position\":{\"segment_id\":-1,\"offset\":-100}}\n\n" );

		$this->client->read_event();
		$pos = $this->client->get_position();

		$this->assertSame( 0, $pos['segment_id'], 'Negative segment_id should be clamped to 0' );
		$this->assertSame( 0, $pos['offset'], 'Negative offset should be clamped to 0' );
	}

	// ── Regression: Heartbeat and entry positions use same code path ────

	public function test_heartbeat_and_entry_positions_deduplicated(): void {
		$this->set_connected();

		// Feed an entry event with position — should update position.
		$this->feed_sse_data( "event: entry\ndata: {\"rid\":\"r1\",\"position\":{\"segment_id\":3,\"offset\":500}}\n\n" );
		$this->client->read_event();

		$pos = $this->client->get_position();
		$this->assertSame( 3, $pos['segment_id'], 'Entry event should update segment_id' );
		$this->assertSame( 500, $pos['offset'], 'Entry event should update offset' );

		// Feed a heartbeat with a different position — should also update.
		$this->feed_sse_data( "event: heartbeat\ndata: {\"position\":{\"segment_id\":7,\"offset\":2048}}\n\n" );
		$this->client->read_event();

		$pos = $this->client->get_position();
		$this->assertSame( 7, $pos['segment_id'], 'Heartbeat should update segment_id' );
		$this->assertSame( 2048, $pos['offset'], 'Heartbeat should update offset' );
	}

	// ── Constructor ─────────────────────────────────────────────────────

	public function test_constructor_stores_verify_ssl(): void {
		$client = new SSEClient( 'id', 'https://example.com', '', '', 0, true );
		$ref    = new \ReflectionProperty( SSEClient::class, 'verify_ssl' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->getValue( $client ) );
		$client->close();

		$client2 = new SSEClient( 'id', 'https://example.com', '', '', 0, false );
		$this->assertFalse( $ref->getValue( $client2 ) );
		$client2->close();
	}
}
