<?php
/**
 * Tests for Performance Logger LogManager — private utility methods.
 *
 * Covers: redact_url, is_sensitive_key, extract_plugin_slug,
 * suspend/resume context stack, and safety constants.
 * These are NOT covered by the existing LogManagerTest which focuses on
 * the public logging lifecycle (message, start/complete, flush, finish).
 *
 * @package Newspack_Performance_Logger
 */

namespace Newspack_Performance_Logger\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Performance_Logger\LogManager;
use Newspack_Event_Logger\Config;

#[CoversClass( LogManager::class )]
class PerfLogManagerTest extends TestCase {

	protected function setUp(): void {
		Config::reset();
		LogManager::reset();
		// Clear context stack from previous tests.
		$ref   = new \ReflectionClass( LogManager::class );
		$stack = $ref->getProperty( 'context_stack' );
		$stack->setAccessible( true );
		$stack->setValue( null, [] );
	}

	protected function tearDown(): void {
		// Clear context stack before reset to avoid cascading finish() calls.
		$ref   = new \ReflectionClass( LogManager::class );
		$stack = $ref->getProperty( 'context_stack' );
		$stack->setAccessible( true );
		$stack->setValue( null, [] );
		LogManager::reset();
		Config::reset();
	}

	// ── redact_url() ────────────────────────────────────────────────────────

	public function test_redact_url_redacts_api_key(): void {
		$method = self::redact_url_method();

		$url      = '/api?api_key=secret123&foo=bar';
		$redacted = $method->invoke( null, $url );
		$this->assertStringNotContainsString( 'secret123', $redacted );
		$this->assertStringContainsString( 'api_key=[REDACTED]', $redacted );
		$this->assertStringContainsString( 'foo=bar', $redacted );
	}

	public function test_redact_url_redacts_token(): void {
		$method = self::redact_url_method();

		$redacted = $method->invoke( null, '/api?token=abc&other=val' );
		$this->assertStringContainsString( 'token=[REDACTED]', $redacted );
		$this->assertStringContainsString( 'other=val', $redacted );
	}

	public function test_redact_url_redacts_password(): void {
		$method = self::redact_url_method();

		$redacted = $method->invoke( null, '/login?password=hunter2' );
		$this->assertStringContainsString( 'password=[REDACTED]', $redacted );
		$this->assertStringNotContainsString( 'hunter2', $redacted );
	}

	public function test_redact_url_redacts_access_token(): void {
		$method = self::redact_url_method();

		$redacted = $method->invoke( null, '/oauth?access_token=xyz789' );
		$this->assertStringContainsString( 'access_token=[REDACTED]', $redacted );
	}

	public function test_redact_url_redacts_refresh_token(): void {
		$method = self::redact_url_method();

		$redacted = $method->invoke( null, '/oauth?refresh_token=rt_123' );
		$this->assertStringContainsString( 'refresh_token=[REDACTED]', $redacted );
	}

	public function test_redact_url_redacts_client_secret(): void {
		$method = self::redact_url_method();

		$redacted = $method->invoke( null, '/oauth?client_secret=cs_abc' );
		$this->assertStringContainsString( 'client_secret=[REDACTED]', $redacted );
	}

	public function test_redact_url_redacts_session_param(): void {
		$method = self::redact_url_method();

		$redacted = $method->invoke( null, '/page?session=sess_abc123' );
		$this->assertStringContainsString( 'session=[REDACTED]', $redacted );
	}

	public function test_redact_url_case_insensitive(): void {
		$method = self::redact_url_method();

		$redacted = $method->invoke( null, '/api?API_KEY=abc&Token=xyz' );
		$this->assertStringNotContainsString( 'abc', $redacted );
		$this->assertStringNotContainsString( 'xyz', $redacted );
	}

	public function test_redact_url_preserves_non_sensitive_params(): void {
		$method = self::redact_url_method();

		$url      = '/page?id=123&name=test';
		$redacted = $method->invoke( null, $url );
		$this->assertSame( $url, $redacted );
	}

	public function test_redact_url_handles_no_query_string(): void {
		$method = self::redact_url_method();

		$url = '/page/without/params';
		$this->assertSame( $url, $method->invoke( null, $url ) );
	}

	public function test_redact_url_handles_multiple_sensitive_params(): void {
		$method = self::redact_url_method();

		$redacted = $method->invoke( null, '/api?key=a&password=b&id=123' );
		$this->assertStringNotContainsString( '=a', $redacted );
		$this->assertStringNotContainsString( '=b', $redacted );
		$this->assertStringContainsString( 'id=123', $redacted );
	}

	// ── is_sensitive_key() ──────────────────────────────────────────────────

	public function test_is_sensitive_key_exact_matches(): void {
		$method = self::is_sensitive_key_method();

		$sensitive = [
			'AUTH_KEY', 'AUTH_SALT', 'DB_PASSWORD', 'DB_USER',
			'HTTP_AUTHORIZATION', 'HTTP_COOKIE', 'NONCE_SALT',
			'NONCE_KEY', 'LOGGED_IN_KEY', 'LOGGED_IN_SALT',
			'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT', 'TERMCAP',
		];
		foreach ( $sensitive as $key ) {
			$this->assertTrue(
				$method->invoke( null, $key ),
				"Expected '{$key}' to be sensitive"
			);
		}
	}

	public function test_is_sensitive_key_substring_matches(): void {
		$method = self::is_sensitive_key_method();

		$this->assertTrue( $method->invoke( null, 'MY_CUSTOM_PASSWORD_FIELD' ) );
		$this->assertTrue( $method->invoke( null, 'MY_API_TOKEN_VALUE' ) );
		$this->assertTrue( $method->invoke( null, 'APP_SECRET_KEY' ) );
		$this->assertTrue( $method->invoke( null, 'BEARER_INFO' ) );
		$this->assertTrue( $method->invoke( null, 'CREDENTIAL_STORE' ) );
		$this->assertTrue( $method->invoke( null, 'PRIVATE_DATA' ) );
	}

	public function test_is_sensitive_key_non_sensitive(): void {
		$method = self::is_sensitive_key_method();

		$this->assertFalse( $method->invoke( null, 'SERVER_NAME' ) );
		$this->assertFalse( $method->invoke( null, 'REQUEST_URI' ) );
		$this->assertFalse( $method->invoke( null, 'HTTP_HOST' ) );
		$this->assertFalse( $method->invoke( null, 'REMOTE_ADDR' ) );
		$this->assertFalse( $method->invoke( null, 'SERVER_PORT' ) );
		$this->assertFalse( $method->invoke( null, 'CONTENT_TYPE' ) );
	}

	public function test_is_sensitive_key_case_sensitive_exact(): void {
		$method = self::is_sensitive_key_method();

		// Exact matches are case-sensitive, but substring check is case-insensitive.
		// 'auth_key' misses exact match but hits substring 'AUTH' via strtoupper.
		$this->assertTrue( $method->invoke( null, 'auth_key' ) );
		$this->assertTrue( $method->invoke( null, 'AUTH_KEY' ) );
		// A key that doesn't match exact or substring.
		$this->assertFalse( $method->invoke( null, 'SERVER_PORT' ) );
	}

	public function test_is_sensitive_key_substring_case_insensitive(): void {
		$method = self::is_sensitive_key_method();

		// Substring checks use strtoupper, so mixed-case keys with substrings match.
		$this->assertTrue( $method->invoke( null, 'my_password_field' ) );
		$this->assertTrue( $method->invoke( null, 'Api_Token' ) );
	}

	// ── extract_plugin_slug() ───────────────────────────────────────────────

	public function test_extract_plugin_slug_from_plugin_path(): void {
		$method = self::extract_plugin_slug_method();

		$slug = $method->invoke( null, WP_PLUGIN_DIR . '/my-plugin/includes/class-foo.php' );
		$this->assertSame( 'my-plugin', $slug );
	}

	public function test_extract_plugin_slug_single_file_plugin(): void {
		$method = self::extract_plugin_slug_method();

		$slug = $method->invoke( null, WP_PLUGIN_DIR . '/hello.php' );
		$this->assertSame( 'hello', $slug );
	}

	public function test_extract_plugin_slug_non_plugin_path(): void {
		$method = self::extract_plugin_slug_method();

		$this->assertNull( $method->invoke( null, '/some/other/path/file.php' ) );
	}

	public function test_extract_plugin_slug_deeply_nested(): void {
		$method = self::extract_plugin_slug_method();

		$slug = $method->invoke( null, WP_PLUGIN_DIR . '/woocommerce/src/Internal/Admin/foo.php' );
		$this->assertSame( 'woocommerce', $slug );
	}

	// ── suspend() / resume() ────────────────────────────────────────────────

	public function test_suspend_and_resume_context(): void {
		$parent    = LogManager::instance();
		$parent_id = \spl_object_id( $parent );

		LogManager::suspend();
		$child = LogManager::instance();
		$this->assertNotSame( $parent_id, \spl_object_id( $child ) );

		LogManager::resume();
		$restored = LogManager::instance();
		$this->assertSame( $parent_id, \spl_object_id( $restored ) );
	}

	public function test_nested_suspend_resume(): void {
		$first  = LogManager::instance();
		$first_id = \spl_object_id( $first );

		LogManager::suspend();
		$second = LogManager::instance();
		$second_id = \spl_object_id( $second );

		LogManager::suspend();
		$third = LogManager::instance();
		$this->assertNotSame( $second_id, \spl_object_id( $third ) );

		LogManager::resume();
		$restored_second = LogManager::instance();
		$this->assertSame( $second_id, \spl_object_id( $restored_second ) );

		LogManager::resume();
		$restored_first = LogManager::instance();
		$this->assertSame( $first_id, \spl_object_id( $restored_first ) );
	}

	public function test_resume_without_suspend_clears_instance(): void {
		LogManager::instance();
		LogManager::resume();

		$ref  = new \ReflectionClass( LogManager::class );
		$inst = $ref->getProperty( 'instance' );
		$inst->setAccessible( true );
		$this->assertNull( $inst->getValue() );
	}

	public function test_suspend_without_instance_is_noop(): void {
		LogManager::reset();
		LogManager::suspend();
		// Should not throw. Stack should be empty.
		$ref   = new \ReflectionClass( LogManager::class );
		$stack = $ref->getProperty( 'context_stack' );
		$stack->setAccessible( true );
		$this->assertEmpty( $stack->getValue() );
	}

	// ── Constants ───────────────────────────────────────────────────────────

	public function test_max_buffer_size_under_pipe_buf(): void {
		$ref = new \ReflectionClass( LogManager::class );
		$this->assertSame( 4096, $ref->getConstant( 'MAX_BUFFER_SIZE' ) );
	}

	public function test_max_timer_depth_prevents_unbounded_growth(): void {
		$ref = new \ReflectionClass( LogManager::class );
		$this->assertSame( 100, $ref->getConstant( 'MAX_TIMER_DEPTH' ) );
	}

	public function test_max_data_size_under_buffer_limit(): void {
		$ref = new \ReflectionClass( LogManager::class );
		$max_data   = $ref->getConstant( 'MAX_DATA_SIZE' );
		$max_buffer = $ref->getConstant( 'MAX_BUFFER_SIZE' );
		$this->assertLessThan( $max_buffer, $max_data );
	}

	public function test_fatal_types_set(): void {
		$this->assertContains( E_ERROR, LogManager::FATAL_TYPES );
		$this->assertContains( E_PARSE, LogManager::FATAL_TYPES );
		$this->assertContains( E_COMPILE_ERROR, LogManager::FATAL_TYPES );
		$this->assertContains( E_USER_ERROR, LogManager::FATAL_TYPES );
		$this->assertCount( 4, LogManager::FATAL_TYPES );
	}

	// ── Boot time consume-and-unset ─────────────────────────────────────

	public function test_boot_time_consume_and_unset(): void {
		// Enable logging so the constructor reaches the profiler consumption code.
		$GLOBALS['_wp_test_options']['event_logger_enable_logging'] = '1';
		Config::reset();

		// Set the global profiler with a known hrtime value.
		$known_time = \hrtime( true ) - 500000000; // 500ms ago.
		$GLOBALS['newspack_profiler'] = [ 'request_time' => $known_time ];

		LogManager::reset();
		$lm = LogManager::instance();

		// Verify it consumed the request_time via reflection.
		$ref = new \ReflectionProperty( LogManager::class, 'request_time' );
		$ref->setAccessible( true );
		$this->assertSame( $known_time, $ref->getValue( $lm ), 'request_time should be consumed from global' );

		// Verify the global was unset.
		$this->assertArrayNotHasKey( 'request_time', $GLOBALS['newspack_profiler'] );

		// Reset and create a second instance — should NOT have request_time.
		unset( $GLOBALS['newspack_profiler'] );
		LogManager::reset();
		$lm2 = LogManager::instance();
		$this->assertNull( $ref->getValue( $lm2 ), 'Second instance should have null request_time' );

		// Cleanup.
		unset( $GLOBALS['_wp_test_options']['event_logger_enable_logging'] );
		Config::reset();
	}

	// ── Reflection helpers ──────────────────────────────────────────────────

	private static function redact_url_method(): \ReflectionMethod {
		$method = new \ReflectionMethod( LogManager::class, 'redact_url' );
		$method->setAccessible( true );
		return $method;
	}

	private static function is_sensitive_key_method(): \ReflectionMethod {
		$method = new \ReflectionMethod( LogManager::class, 'is_sensitive_key' );
		$method->setAccessible( true );
		return $method;
	}

	private static function extract_plugin_slug_method(): \ReflectionMethod {
		$method = new \ReflectionMethod( LogManager::class, 'extract_plugin_slug' );
		$method->setAccessible( true );
		return $method;
	}
}
