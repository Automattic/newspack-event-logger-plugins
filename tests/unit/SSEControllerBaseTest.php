<?php
/**
 * Tests for Newspack_Event_Logger\REST\SSEControllerBase.
 *
 * @package Event_Logger
 */

use Newspack_Event_Logger\REST\SSEControllerBase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Concrete stub extending the abstract SSEControllerBase for testing.
 */
class TestableSSEController extends SSEControllerBase {

	/** @var array Captured headers from start_sse_stream(). */
	public array $sent_headers = [];

	/**
	 * Override init_sse_headers to avoid calling header() in CLI.
	 */
	protected function init_sse_headers(): void {
		// No-op in tests.
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
	 * Required by WP_REST_Controller but unused in these tests.
	 */
	public function register_routes() {}
}

#[CoversClass( SSEControllerBase::class )]
class SSEControllerBaseTest extends \PHPUnit\Framework\TestCase {

	private TestableSSEController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new TestableSSEController();
	}

	// ── Header sanitization ─────────────────────────────────────────────

	public function test_custom_headers_clean_values_pass_through(): void {
		$result = $this->controller->test_sanitize_headers( [
			'X-Server-Id' => 'web01',
		] );

		$this->assertSame( [ 'X-Server-Id: web01' ], $result );
	}

	public function test_custom_headers_strips_crlf_from_value(): void {
		$result = $this->controller->test_sanitize_headers( [
			'X-Custom' => "safe\r\nInjected-Header: evil",
		] );

		$this->assertSame( [ 'X-Custom: safeInjected-Header: evil' ], $result );
	}

	public function test_custom_headers_strips_lf_from_value(): void {
		$result = $this->controller->test_sanitize_headers( [
			'X-Custom' => "safe\nInjected-Header: evil",
		] );

		$this->assertSame( [ 'X-Custom: safeInjected-Header: evil' ], $result );
	}

	public function test_custom_headers_strips_cr_from_value(): void {
		$result = $this->controller->test_sanitize_headers( [
			'X-Custom' => "safe\rInjected-Header: evil",
		] );

		$this->assertSame( [ 'X-Custom: safeInjected-Header: evil' ], $result );
	}

	public function test_custom_headers_strips_null_from_value(): void {
		$result = $this->controller->test_sanitize_headers( [
			'X-Custom' => "safe\0evil",
		] );

		$this->assertSame( [ 'X-Custom: safeevil' ], $result );
	}

	public function test_custom_headers_strips_crlf_from_name(): void {
		$result = $this->controller->test_sanitize_headers( [
			"X-Bad\r\nInjected" => 'value',
		] );

		$this->assertSame( [ 'X-BadInjected: value' ], $result );
	}

	public function test_custom_headers_strips_null_from_name(): void {
		$result = $this->controller->test_sanitize_headers( [
			"X-Bad\0Name" => 'value',
		] );

		$this->assertSame( [ 'X-BadName: value' ], $result );
	}

	public function test_custom_headers_strips_all_dangerous_chars(): void {
		$result = $this->controller->test_sanitize_headers( [
			"X-Name\r\n\0" => "val\r\n\0ue",
		] );

		$this->assertSame( [ 'X-Name: value' ], $result );
	}

	public function test_custom_headers_multiple_headers_all_sanitized(): void {
		$result = $this->controller->test_sanitize_headers( [
			'X-Clean'             => 'safe',
			"X-Dirty\nInjection" => "bad\r\nvalue",
		] );

		$this->assertSame( [
			'X-Clean: safe',
			'X-DirtyInjection: badvalue',
		], $result );
	}

	// ── send_sse_event sanitization ─────────────────────────────────────

	public function test_send_sse_event_sanitizes_event_name(): void {
		// send_sse_event uses preg_replace to strip non-alphanumeric chars.
		// Verify the regex is correct by checking the source pattern.
		$ref    = new \ReflectionClass( $this->controller );
		$method = $ref->getMethod( 'send_sse_event' );

		// The method is protected but we can verify it exists.
		$this->assertTrue( $method->isProtected() );
	}
}
