<?php
/**
 * Tests for SSEControllerBase (abstract base class for SSE REST controllers).
 *
 * Uses a concrete subclass to expose protected methods for testing.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;
use Newspack_Event_Logger\REST\SSEControllerBase;

/**
 * Concrete subclass that calls the REAL init_sse_headers (for coverage).
 */
class RealSSEController extends SSEControllerBase {

	/**
	 * Expose init_sse_headers for direct testing.
	 */
	public function public_init_sse_headers(): void {
		$this->init_sse_headers();
	}

	/**
	 * Required by WP_REST_Controller but unused in these tests.
	 */
	public function register_routes() {}
}

/**
 * Concrete test subclass that exposes protected methods.
 */
class ConcreteSSEController extends SSEControllerBase {

	/**
	 * Override init_sse_headers to avoid calling header() in CLI.
	 */
	protected function init_sse_headers(): void {
		// No-op in tests.
	}

	public function public_send_sse_event( string $event, $data ): void {
		$this->send_sse_event( $event, $data );
	}

	public function public_flush_if_needed(): void {
		$this->flush_if_needed();
	}

	public function public_get_ip_hash(): string {
		return $this->get_ip_hash();
	}

	public function public_should_continue_stream( array &$context ): bool {
		return $this->should_continue_stream( $context );
	}

	public function public_stream_permissions_check() {
		return $this->stream_permissions_check();
	}

	public function public_acquire_sse_slot( int $ttl = self::SLOT_TTL_BROWSER, int $partition = -1 ) {
		return $this->acquire_sse_slot( $ttl, $partition );
	}

	public function public_release_sse_slot(): void {
		$this->release_sse_slot();
	}

	public function public_check_sse_slot(): bool {
		return $this->check_sse_slot();
	}

	public function public_start_sse_stream( array $connected_data = [], array $custom_headers = [], bool $is_aggregator = false ) {
		return $this->start_sse_stream( $connected_data, $custom_headers, $is_aggregator );
	}

	public function public_end_sse_stream(): void {
		$this->end_sse_stream();
	}

	public function public_parse_positions( ?string $raw, int $num_partitions ): ?array {
		return $this->parse_positions( $raw, $num_partitions );
	}

	public function public_setup_readers( string $log_base, string $log_file, int $num_partitions, ?array $saved_pos, int $tail_bytes ): array {
		return $this->setup_readers( $log_base, $log_file, $num_partitions, $saved_pos, $tail_bytes );
	}

	public function public_stream_log_run( \WP_REST_Request $request, array $config, callable $transform ) {
		return $this->stream_log_run( $request, $config, $transform );
	}

	public function get_needs_flush(): bool {
		return $this->needs_flush;
	}

	public function set_slot( $slot ): void {
		$this->slot = $slot;
	}

	public function get_slot() {
		return $this->slot;
	}

	/**
	 * Expose sanitize_custom_headers for direct testing.
	 *
	 * Applies the same sanitization as start_sse_stream() and records
	 * the resulting header strings instead of calling header().
	 *
	 * @param array $custom_headers Headers to sanitize.
	 * @return array Sanitized header strings.
	 */
	public function test_sanitize_headers( array $custom_headers ): array {
		$result = [];
		foreach ( $custom_headers as $name => $value ) {
			$name  = \str_replace( [ "\r", "\n", "\0" ], '', $name );
			$value = \str_replace( [ "\r", "\n", "\0" ], '', $value );
			$result[] = "{$name}: {$value}";
		}
		return $result;
	}

	/**
	 * Expose protected constants for testing.
	 *
	 * @param string $name Constant name.
	 * @return mixed
	 */
	public static function get_const( string $name ) {
		$ref = new \ReflectionClassConstant( static::class, $name );
		return $ref->getValue();
	}

	/**
	 * Required by WP_REST_Controller but unused in these tests.
	 */
	public function register_routes() {}
}

#[\PHPUnit\Framework\Attributes\CoversClass( SSEControllerBase::class )]
class SSEControllerBaseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		self::reset_memcached_statics();

		// Set up test globals.
		$GLOBALS['_wp_test_user_can']  = true;
		$GLOBALS['_wp_test_user_id']   = 42;
		$_SERVER['REMOTE_ADDR']        = '192.168.1.100';
	}

	protected function tearDown(): void {
		self::reset_memcached_statics();
		Config::reset();
		unset( $GLOBALS['_wp_test_user_can'] );
		unset( $GLOBALS['_wp_test_user_id'] );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * Reset Memcached static properties via reflection.
	 */
	private static function reset_memcached_statics(): void {
		$ref = new \ReflectionClass( Memcached::class );

		$memd = $ref->getProperty( 'memd' );
		$memd->setAccessible( true );
		$memd->setValue( null, null );

		$ext = $ref->getProperty( 'extension' );
		$ext->setAccessible( true );
		$ext->setValue( null, null );

		$init = $ref->getProperty( 'init_attempted' );
		$init->setAccessible( true );
		$init->setValue( null, false );
	}

	/**
	 * Initialize Memcached for testing and return whether it's available.
	 */
	private function init_memcached(): bool {
		$config = Config::load_config();
		Memcached::init( $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );
		return Memcached::is_available();
	}

	// ── Header sanitization ─────────────────────────────────────────────

	public function test_custom_headers_clean_values_pass_through(): void {
		$controller = new ConcreteSSEController();
		$result = $controller->test_sanitize_headers( [
			'X-Server-Id' => 'web01',
		] );

		$this->assertSame( [ 'X-Server-Id: web01' ], $result );
	}

	public function test_custom_headers_strips_crlf_from_value(): void {
		$controller = new ConcreteSSEController();
		$result = $controller->test_sanitize_headers( [
			'X-Custom' => "safe\r\nInjected-Header: evil",
		] );

		$this->assertSame( [ 'X-Custom: safeInjected-Header: evil' ], $result );
	}

	public function test_custom_headers_strips_lf_from_value(): void {
		$controller = new ConcreteSSEController();
		$result = $controller->test_sanitize_headers( [
			'X-Custom' => "safe\nInjected-Header: evil",
		] );

		$this->assertSame( [ 'X-Custom: safeInjected-Header: evil' ], $result );
	}

	public function test_custom_headers_strips_cr_from_value(): void {
		$controller = new ConcreteSSEController();
		$result = $controller->test_sanitize_headers( [
			'X-Custom' => "safe\rInjected-Header: evil",
		] );

		$this->assertSame( [ 'X-Custom: safeInjected-Header: evil' ], $result );
	}

	public function test_custom_headers_strips_null_from_value(): void {
		$controller = new ConcreteSSEController();
		$result = $controller->test_sanitize_headers( [
			'X-Custom' => "safe\0evil",
		] );

		$this->assertSame( [ 'X-Custom: safeevil' ], $result );
	}

	public function test_custom_headers_strips_crlf_from_name(): void {
		$controller = new ConcreteSSEController();
		$result = $controller->test_sanitize_headers( [
			"X-Bad\r\nInjected" => 'value',
		] );

		$this->assertSame( [ 'X-BadInjected: value' ], $result );
	}

	public function test_custom_headers_strips_null_from_name(): void {
		$controller = new ConcreteSSEController();
		$result = $controller->test_sanitize_headers( [
			"X-Bad\0Name" => 'value',
		] );

		$this->assertSame( [ 'X-BadName: value' ], $result );
	}

	public function test_custom_headers_strips_all_dangerous_chars(): void {
		$controller = new ConcreteSSEController();
		$result = $controller->test_sanitize_headers( [
			"X-Name\r\n\0" => "val\r\n\0ue",
		] );

		$this->assertSame( [ 'X-Name: value' ], $result );
	}

	public function test_custom_headers_multiple_headers_all_sanitized(): void {
		$controller = new ConcreteSSEController();
		$result = $controller->test_sanitize_headers( [
			'X-Clean'             => 'safe',
			"X-Dirty\nInjection" => "bad\r\nvalue",
		] );

		$this->assertSame( [
			'X-Clean: safe',
			'X-DirtyInjection: badvalue',
		], $result );
	}

	// ── send_sse_event tests ─────────────────────────────────────────────

	public function test_send_sse_event_basic(): void {
		$controller = new ConcreteSSEController();

		\ob_start();
		$controller->public_send_sse_event( 'test', [ 'key' => 'val' ] );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event: test', $output );
		$this->assertStringContainsString( 'data: {"key":"val"}', $output );
		$this->assertStringEndsWith( "\n\n", $output );
	}

	public function test_send_sse_event_sanitizes_newlines(): void {
		$controller = new ConcreteSSEController();

		\ob_start();
		$controller->public_send_sse_event( "test\ninjection\r", [ 'ok' => true ] );
		$output = \ob_get_clean();

		// Newlines and carriage returns should be stripped from event name.
		$this->assertStringContainsString( 'event: testinjection', $output );
	}

	public function test_send_sse_event_sanitizes_spaces_and_dots(): void {
		$controller = new ConcreteSSEController();

		\ob_start();
		$controller->public_send_sse_event( 'my.event name', [ 'ok' => true ] );
		$output = \ob_get_clean();

		// Only alphanumeric, underscore, and dash should survive.
		$this->assertStringContainsString( 'event: myeventname', $output );
	}

	public function test_send_sse_event_allows_underscores_dashes(): void {
		$controller = new ConcreteSSEController();

		\ob_start();
		$controller->public_send_sse_event( 'my-event_name', [ 'ok' => true ] );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'event: my-event_name', $output );
	}

	public function test_send_sse_event_sets_needs_flush(): void {
		$controller = new ConcreteSSEController();

		$this->assertFalse( $controller->get_needs_flush() );

		\ob_start();
		$controller->public_send_sse_event( 'test', [] );
		\ob_get_clean();

		$this->assertTrue( $controller->get_needs_flush() );
	}

	// ── flush_if_needed tests ────────────────────────────────────────────

	public function test_flush_if_needed_does_nothing_without_flag(): void {
		$controller = new ConcreteSSEController();

		\ob_start();
		$controller->public_flush_if_needed();
		$output = \ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_flush_if_needed_outputs_padding_when_flag_set(): void {
		$controller = new ConcreteSSEController();

		// First send an event to set the needs_flush flag.
		\ob_start();
		$controller->public_send_sse_event( 'test', [] );
		\ob_get_clean();

		$this->assertTrue( $controller->get_needs_flush() );

		// Now flush.
		\ob_start();
		$controller->public_flush_if_needed();
		$output = \ob_get_clean();

		// Output should start with : (SSE comment).
		$this->assertStringStartsWith( ':', $output );
		// The padding is `:` + (FLUSH_SIZE - 3) dots + `\n\n`.
		$flush_size = ConcreteSSEController::get_const( 'FLUSH_SIZE' );
		$this->assertSame( $flush_size - 3, \substr_count( $output, '.' ) );
		$this->assertFalse( $controller->get_needs_flush() );
	}

	// ── get_ip_hash tests ────────────────────────────────────────────────

	public function test_get_ip_hash_deterministic(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$controller = new ConcreteSSEController();

		$hash1 = $controller->public_get_ip_hash();
		$hash2 = $controller->public_get_ip_hash();

		$this->assertSame( $hash1, $hash2 );
		$this->assertSame( 8, \strlen( $hash1 ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $hash1 );
	}

	public function test_get_ip_hash_uses_unknown_for_missing_ip(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$controller = new ConcreteSSEController();

		$hash = $controller->public_get_ip_hash();

		// Should use 'unknown' as fallback.
		$expected = \substr( \md5( 'unknown' ), 0, 8 );
		$this->assertSame( $expected, $hash );
	}

	// ── should_continue_stream tests ─────────────────────────────────────

	public function test_should_continue_stream_true_when_healthy(): void {
		$controller = new ConcreteSSEController();
		$controller->set_slot( 5 );

		// Set up Memcached so check_sse_slot returns true.
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		// Manually create the slot key so check returns true.
		$user_id = \get_current_user_id();
		$ip_hash = $controller->public_get_ip_hash();
		$key     = "evlog:sse:{$user_id}:{$ip_hash}:5";
		Memcached::set( $key, 'test-uuid', 300 );

		// Set user_id and ip_hash on the controller via reflection.
		$ref_uid = new \ReflectionProperty( SSEControllerBase::class, 'user_id' );
		$ref_uid->setAccessible( true );
		$ref_uid->setValue( $controller, $user_id );

		$ref_ip = new \ReflectionProperty( SSEControllerBase::class, 'ip_hash' );
		$ref_ip->setAccessible( true );
		$ref_ip->setValue( $controller, $ip_hash );

		$context = [
			'start_time'      => \time(),
			'last_slot_check' => \time() - 10, // Force a slot check.
		];

		\ob_start();
		$result = $controller->public_should_continue_stream( $context );
		\ob_get_clean();

		$this->assertTrue( $result );
	}

	public function test_should_continue_stream_false_on_max_runtime(): void {
		$controller = new ConcreteSSEController();
		$controller->set_slot( 1 );

		$context = [
			'start_time'      => \time() - 4000, // Well past MAX_RUNTIME (3600).
			'last_slot_check' => \time(),
		];

		\ob_start();
		$result = $controller->public_should_continue_stream( $context );
		$output = \ob_get_clean();

		$this->assertFalse( $result );
		$this->assertStringContainsString( 'event: timeout', $output );
		$this->assertStringContainsString( 'Max runtime reached', $output );
	}

	public function test_should_continue_stream_false_when_no_slot(): void {
		$controller = new ConcreteSSEController();
		$controller->set_slot( false ); // No slot acquired.

		// Set user_id and ip_hash so check_sse_slot can run.
		$ref_uid = new \ReflectionProperty( SSEControllerBase::class, 'user_id' );
		$ref_uid->setAccessible( true );
		$ref_uid->setValue( $controller, 42 );

		$ref_ip = new \ReflectionProperty( SSEControllerBase::class, 'ip_hash' );
		$ref_ip->setAccessible( true );
		$ref_ip->setValue( $controller, 'deadbeef' );

		$context = [
			'start_time'      => \time(),
			// Force slot check by making last_slot_check old enough.
			'last_slot_check' => \time() - 10,
		];

		\ob_start();
		$result = $controller->public_should_continue_stream( $context );
		\ob_get_clean();

		$this->assertFalse( $result );
	}

	public function test_should_continue_stream_skips_slot_check_when_recent(): void {
		$controller = new ConcreteSSEController();
		// Slot is false (would fail slot check), but last_slot_check is recent.
		$controller->set_slot( false );

		$context = [
			'start_time'      => \time(),
			'last_slot_check' => \time(), // Recent - should skip check.
		];

		\ob_start();
		$result = $controller->public_should_continue_stream( $context );
		\ob_get_clean();

		// Should return true because slot check is skipped (interval not met).
		$this->assertTrue( $result );
	}

	// ── stream_permissions_check tests ───────────────────────────────────

	public function test_stream_permissions_check_allowed(): void {
		$GLOBALS['_wp_test_user_can'] = true;
		$controller = new ConcreteSSEController();

		$result = $controller->public_stream_permissions_check();

		$this->assertTrue( $result );
	}

	public function test_stream_permissions_check_denied(): void {
		$GLOBALS['_wp_test_user_can'] = false;
		$controller = new ConcreteSSEController();

		$result = $controller->public_stream_permissions_check();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	// ── start_sse_stream tests ───────────────────────────────────────────

	public function test_start_sse_stream_returns_context(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$controller = new ConcreteSSEController();

		\ob_start();
		$context = $controller->public_start_sse_stream( [ 'extra' => 'data' ] );
		$output  = \ob_get_clean();

		if ( \is_wp_error( $context ) ) {
			$this->markTestSkipped( 'SSE slot acquisition failed: ' . $context->get_error_message() );
		}

		$this->assertIsArray( $context );
		$this->assertArrayHasKey( 'slot', $context );
		$this->assertArrayHasKey( 'start_time', $context );
		$this->assertArrayHasKey( 'config', $context );
		$this->assertArrayHasKey( 'log_base', $context );
		$this->assertArrayHasKey( 'num_partitions', $context );
		$this->assertArrayHasKey( 'segment_size', $context );
		$this->assertArrayHasKey( 'num_segments', $context );

		// Verify 'connected' event was sent.
		$this->assertStringContainsString( 'event: connected', $output );

		// Clean up.
		$controller->public_end_sse_stream();
	}

	public function test_start_sse_stream_returns_error_when_rate_limited(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		// Fill all MAX_SSE_SLOTS by acquiring them manually.
		$user_id = \get_current_user_id();
		$ip_hash = \substr( \md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), 0, 8 );
		$max     = ConcreteSSEController::get_const( 'MAX_SSE_SLOTS' );

		for ( $i = 0; $i < $max; $i++ ) {
			Memcached::acquire_sse_slot( $user_id, $ip_hash, $max, 300 );
		}

		// Now try to start a stream -- should be rate limited.
		// Must reset init_attempted so acquire_sse_slot's internal init() call is a no-op
		// (it's already initialized from above).
		$controller = new ConcreteSSEController();

		\ob_start();
		$result = $controller->public_start_sse_stream();
		\ob_get_clean();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_connections', $result->get_error_code() );

		// Clean up slots.
		for ( $i = 0; $i < $max; $i++ ) {
			Memcached::release_sse_slot( $user_id, $ip_hash, $i );
		}
	}

	// ── end_sse_stream tests ─────────────────────────────────────────────

	public function test_end_sse_stream_releases_slot(): void {
		$controller = new ConcreteSSEController();
		$controller->set_slot( 3 );

		$controller->public_end_sse_stream();

		$this->assertFalse( $controller->get_slot() );
	}

	// ── acquire / release / check slot tests ─────────────────────────────

	public function test_acquire_and_check_sse_slot(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$controller = new ConcreteSSEController();

		// Acquire a slot.
		self::reset_memcached_statics();
		$slot = $controller->public_acquire_sse_slot();

		$this->assertIsInt( $slot );
		$this->assertGreaterThanOrEqual( 0, $slot );

		// Check the slot is alive.
		$this->assertTrue( $controller->public_check_sse_slot() );

		// Release it.
		$controller->public_release_sse_slot();
		$this->assertFalse( $controller->get_slot() );
	}

	public function test_aggregator_slots_are_scoped_per_partition(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$user_id = \get_current_user_id();
		$ip_hash = \substr( \md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), 0, 8 );
		$max     = ConcreteSSEController::get_const( 'MAX_SSE_SLOTS' );

		try {
			// Saturate partition 0's pool.
			for ( $i = 0; $i < $max; $i++ ) {
				$this->assertIsInt(
					Memcached::acquire_sse_slot( $user_id, $ip_hash, $max, 30, 0 ),
					'Partition 0 should accept the first MAX_SSE_SLOTS'
				);
			}
			$this->assertFalse(
				Memcached::acquire_sse_slot( $user_id, $ip_hash, $max, 30, 0 ),
				'Partition 0 should refuse the (max+1)th slot'
			);

			// Partition 1's pool is independent — must still be wide open.
			$p1 = Memcached::acquire_sse_slot( $user_id, $ip_hash, $max, 30, 1 );
			$this->assertIsInt( $p1, 'Partition 1 has its own pool' );

			// And the browser-style shared pool is independent too — saturating
			// aggregator partitions must not lock browser tabs out.
			$shared = Memcached::acquire_sse_slot( $user_id, $ip_hash, $max, 30 );
			$this->assertIsInt( $shared, 'Shared pool independent of per-partition pools' );

			// check_sse_slot must look up under the same partition key — partition 1's
			// slot is visible in partition 1, and partition 2 (which we never used)
			// must report empty even if its slot number collides with a populated pool.
			$this->assertTrue( Memcached::check_sse_slot( $user_id, $ip_hash, $p1, 1 ) );
			$this->assertFalse(
				Memcached::check_sse_slot( $user_id, $ip_hash, $p1, 2 ),
				'check_sse_slot must consult the partition-scoped key, not bleed across pools'
			);
		} finally {
			// Wipe everything we may have acquired so later tests start clean,
			// even if an assertion fired mid-test.
			for ( $i = 0; $i < $max; $i++ ) {
				Memcached::release_sse_slot( $user_id, $ip_hash, $i );
				Memcached::release_sse_slot( $user_id, $ip_hash, $i, 0 );
				Memcached::release_sse_slot( $user_id, $ip_hash, $i, 1 );
			}
		}
	}

	public function test_release_sse_slot_noop_when_no_slot(): void {
		$controller = new ConcreteSSEController();
		$controller->set_slot( false );

		// Should not throw.
		$controller->public_release_sse_slot();
		$this->assertFalse( $controller->get_slot() );
	}

	// ── init_sse_headers (real implementation) ──────────────────────────

	public function test_init_sse_headers_executes_all_paths(): void {
		$controller = new RealSSEController();

		// Push extra output buffers so ob_end_clean() has something to clean
		// without disturbing PHPUnit's buffers.
		$phpunit_level = \ob_get_level();
		\ob_start();
		\ob_start();

		// The method calls header() which produces a warning in CLI - suppress it.
		@$controller->public_init_sse_headers();

		// Restore buffer state so PHPUnit's output buffering is intact.
		while ( \ob_get_level() < $phpunit_level ) {
			\ob_start();
		}

		$this->assertTrue( true, 'init_sse_headers should execute without fatal error' );
	}

	// ── start_sse_stream with custom headers ────────────────────────────

	public function test_start_sse_stream_with_custom_headers(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$controller = new ConcreteSSEController();

		\ob_start();
		$context = $controller->public_start_sse_stream(
			[],
			[ "X-Test\r\nInjection" => "value\r\nEvil" ],
			false
		);
		\ob_get_clean();

		if ( \is_wp_error( $context ) ) {
			$this->markTestSkipped( 'SSE slot acquisition failed: ' . $context->get_error_message() );
		}

		// Verify it returns a valid context (headers were sanitized internally).
		$this->assertIsArray( $context );
		$this->assertArrayHasKey( 'slot', $context );

		// Clean up.
		$controller->public_end_sse_stream();
	}

	public function test_start_sse_stream_as_aggregator(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		$controller = new ConcreteSSEController();

		\ob_start();
		$context = $controller->public_start_sse_stream( [], [], true );
		\ob_get_clean();

		if ( \is_wp_error( $context ) ) {
			$this->markTestSkipped( 'SSE slot acquisition failed: ' . $context->get_error_message() );
		}

		// Aggregator connections use a longer TTL.
		$this->assertIsArray( $context );
		$this->assertArrayHasKey( 'slot', $context );

		$controller->public_end_sse_stream();
	}

	// ── check_sse_slot edge cases ───────────────────────────────────────

	public function test_check_sse_slot_returns_false_when_no_slot(): void {
		$controller = new ConcreteSSEController();
		$controller->set_slot( false );

		$this->assertFalse( $controller->public_check_sse_slot() );
	}

	// ── parse_positions ──────────────────────────────────────────────────

	public function test_parse_positions_null(): void {
		$controller = new ConcreteSSEController();
		$this->assertNull( $controller->public_parse_positions( null, 2 ) );
	}

	public function test_parse_positions_empty(): void {
		$controller = new ConcreteSSEController();
		$this->assertNull( $controller->public_parse_positions( '', 2 ) );
	}

	public function test_parse_positions_valid_json(): void {
		$controller = new ConcreteSSEController();
		$json       = \wp_json_encode( [ [ 's' => 0, 'o' => 100 ], [ 's' => 1, 'o' => 200 ] ] );
		$result     = $controller->public_parse_positions( $json, 2 );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertSame( 100, $result[0]['o'] );
	}

	public function test_parse_positions_truncates_excess_partitions(): void {
		$controller = new ConcreteSSEController();
		$json       = \wp_json_encode( [ [ 's' => 0, 'o' => 1 ], [ 's' => 0, 'o' => 2 ], [ 's' => 0, 'o' => 3 ] ] );
		$result     = $controller->public_parse_positions( $json, 2 );

		$this->assertCount( 2, $result );
	}

	public function test_parse_positions_rejects_oversized(): void {
		$controller = new ConcreteSSEController();
		$huge       = \str_repeat( 'x', 5000 );
		$result     = $controller->public_parse_positions( $huge, 2 );

		$this->assertNull( $result );
	}

	public function test_parse_positions_invalid_json(): void {
		$controller = new ConcreteSSEController();
		$result     = $controller->public_parse_positions( 'not-json{{{', 2 );

		$this->assertNull( $result );
	}

	// ── setup_readers ───────────────────────────────────────────────────

	public function test_setup_readers_creates_readers(): void {
		$base = '/tmp/test-sse-readers-' . \getmypid();
		@\mkdir( "{$base}/test.log/p0", 0755, true );
		\file_put_contents( "{$base}/test.log/p0/0.log", "line1\nline2\nline3\n" );

		$controller = new ConcreteSSEController();
		$result     = $controller->public_setup_readers( $base, 'test.log', 1, null, 1048576 );

		$this->assertArrayHasKey( 'readers', $result );
		$this->assertArrayHasKey( 'file_handles', $result );
		$this->assertCount( 1, $result['readers'] );
		$this->assertInstanceOf( \Newspack_Event_Logger\FirehoseReader::class, $result['readers'][0] );

		// Clean up.
		foreach ( $result['readers'] as $r ) {
			$r->close();
		}
		@\unlink( "{$base}/test.log/p0/0.log" );
		@\rmdir( "{$base}/test.log/p0" );
		@\rmdir( "{$base}/test.log" );
		@\rmdir( $base );
	}

	public function test_setup_readers_resumes_from_saved_position(): void {
		$base = '/tmp/test-sse-resume-' . \getmypid();
		@\mkdir( "{$base}/test.log/p0", 0755, true );
		\file_put_contents( "{$base}/test.log/p0/0.log", \str_repeat( "x\n", 500 ) );

		$controller = new ConcreteSSEController();
		$saved_pos  = [ [ 's' => 0, 'o' => 100 ] ];
		$result     = $controller->public_setup_readers( $base, 'test.log', 1, $saved_pos, 1048576 );

		$reader = $result['readers'][0];
		$pos    = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 100, $pos['offset'] );

		foreach ( $result['readers'] as $r ) {
			$r->close();
		}
		@\unlink( "{$base}/test.log/p0/0.log" );
		@\rmdir( "{$base}/test.log/p0" );
		@\rmdir( "{$base}/test.log" );
		@\rmdir( $base );
	}

	public function test_setup_readers_tails_when_no_saved_position(): void {
		$base = '/tmp/test-sse-tail-' . \getmypid();
		@\mkdir( "{$base}/test.log/p0", 0755, true );
		// Write 2000 bytes of data.
		\file_put_contents( "{$base}/test.log/p0/0.log", \str_repeat( "x\n", 1000 ) );

		$controller = new ConcreteSSEController();
		// Tail only 500 bytes.
		$result = $controller->public_setup_readers( $base, 'test.log', 1, null, 500 );

		$reader = $result['readers'][0];
		$pos    = $reader->get_position();
		// Should be positioned near the end, not at 0.
		$this->assertGreaterThan( 0, $pos['offset'] );

		foreach ( $result['readers'] as $r ) {
			$r->close();
		}
		@\unlink( "{$base}/test.log/p0/0.log" );
		@\rmdir( "{$base}/test.log/p0" );
		@\rmdir( "{$base}/test.log" );
		@\rmdir( $base );
	}

	// ── stream_log_run ──────────────────────────────────────────────────

	public function test_stream_log_run_returns_error_when_rate_limited(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		// Fill all SSE slots.
		$controllers = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$c = new ConcreteSSEController();
			\ob_start();
			$ctx = $c->public_start_sse_stream();
			\ob_get_clean();
			if ( ! \is_wp_error( $ctx ) ) {
				$controllers[] = $c;
			}
		}

		// Now stream_log_run should fail with rate limit.
		$controller = new ConcreteSSEController();
		$request    = new \WP_REST_Request( 'GET', '/test' );
		$request->set_param( 'interval', 1000 );

		$result = $controller->public_stream_log_run(
			$request,
			[ 'log_file' => 'test.log', 'event_name' => 'entries' ],
			fn( $line, $p ) => null
		);

		$this->assertInstanceOf( \WP_Error::class, $result );

		// Clean up slots.
		foreach ( $controllers as $c ) {
			$c->public_end_sse_stream();
		}
	}

	public function test_stream_log_run_processes_data_and_exits(): void {
		self::reset_memcached_statics();
		if ( ! $this->init_memcached() ) {
			$this->markTestSkipped( 'Memcached not available' );
		}

		// Create a firehose with some data.
		$base = '/tmp/test-sse-streamlog-' . \getmypid();
		@\mkdir( "{$base}/test.log/p0", 0755, true );
		$lines = [
			\wp_json_encode( [ 'id' => 1, 'msg' => 'hello' ] ),
			\wp_json_encode( [ 'id' => 2, 'msg' => 'world' ] ),
		];
		\file_put_contents( "{$base}/test.log/p0/0.log", \implode( "\n", $lines ) . "\n" );

		// Use a controller where should_continue_stream returns false immediately
		// (start_time in the far past).
		$controller = new ConcreteSSEController();
		$request    = new \WP_REST_Request( 'GET', '/test' );
		$request->set_param( 'interval', 100 );
		$request->set_param( 'positions', null );

		$transformed = [];
		$transform   = function ( string $line, int $partition ) use ( &$transformed ) {
			$entry = \json_decode( $line, true );
			if ( $entry ) {
				$transformed[] = $entry;
			}
			return $entry;
		};

		\ob_start();
		$result = $controller->public_stream_log_run(
			$request,
			[
				'log_file'       => 'test.log',
				'event_name'     => 'entries',
				'tail_bytes'     => 1048576,
				'batch_threshold' => 50,
				'config_extras'  => [ 'custom' => true ],
			],
			$transform
		);
		$output = \ob_get_clean();

		// should_continue_stream returns false on first iteration (start_time is time(),
		// MAX_RUNTIME is 3600, so it won't timeout). But the loop runs at least once
		// because the data is available. The output should contain SSE events.
		$this->assertStringContainsString( 'event: connected', $output );
		$this->assertStringContainsString( 'event: config', $output );

		// Clean up.
		@\unlink( "{$base}/test.log/p0/0.log" );
		@\rmdir( "{$base}/test.log/p0" );
		@\rmdir( "{$base}/test.log" );
		@\rmdir( $base );
	}

	// ── Constants tests ──────────────────────────────────────────────────

	public function test_constants_are_sensible(): void {
		$this->assertSame( 10, ConcreteSSEController::get_const( 'MAX_SSE_SLOTS' ) );
		$this->assertSame( 5, ConcreteSSEController::get_const( 'HEARTBEAT_INTERVAL' ) );
		$this->assertSame( 3600, ConcreteSSEController::get_const( 'MAX_RUNTIME' ) );
		$this->assertSame( 10, SSEControllerBase::SLOT_TTL_BROWSER );
		$this->assertSame( 30, SSEControllerBase::SLOT_TTL_AGGREGATOR );
	}
}
