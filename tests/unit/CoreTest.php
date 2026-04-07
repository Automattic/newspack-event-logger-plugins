<?php
/**
 * Tests for Newspack_Performance_Logger\Core.
 *
 * @package Newspack_Performance_Logger
 */

use Newspack_Performance_Logger\Core;
use Newspack_Performance_Logger\LogManager;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Core::class )]
class CoreTest extends \PHPUnit\Framework\TestCase {

	/** @var string[] Temp config files to clean up. */
	private array $temp_files = [];

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		LogManager::reset();
		$GLOBALS['_wp_test_options']        = [];
		$GLOBALS['_wp_test_current_filter'] = '';
		$GLOBALS['wp_filter']               = [];
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']        = [];
		$GLOBALS['_wp_test_current_filter'] = '';
		$GLOBALS['wp_filter']               = [];
		foreach ( $this->temp_files as $file ) {
			@\unlink( $file );
		}
		$this->temp_files = [];
		// Restore test config.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		Config::reset();
		LogManager::reset();
		parent::tearDown();
	}

	/**
	 * Set config via WP option stubs (overrides file config).
	 *
	 * @param array $config Config key/value pairs.
	 */
	private function use_config( array $config ): void {
		foreach ( $config as $key => $value ) {
			// WP option false === "not set", use '0' for boolean false.
			if ( false === $value ) {
				$value = '0';
			}
			$GLOBALS['_wp_test_options'][ "event_logger_{$key}" ] = $value;
		}
		Config::reset();
		LogManager::reset();
	}

	// ── short_name tests via reflection ─────────────────────────────────

	public function test_short_name_string_function(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame( 'do_blocks', $ref->invoke( null, 'do_blocks' ) );
	}

	public function test_short_name_namespaced_string(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame( 'do_stuff', $ref->invoke( null, 'Some\\Namespace\\do_stuff' ) );
	}

	public function test_short_name_array_class_method_string(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame(
			'Image_CDN::filter_the_content',
			$ref->invoke( null, [ 'My\\Namespace\\Image_CDN', 'filter_the_content' ] )
		);
	}

	public function test_short_name_array_object_method(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame(
			'stdClass::some_method',
			$ref->invoke( null, [ new \stdClass(), 'some_method' ] )
		);
	}

	public function test_short_name_closure(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$closure = function () { return 42; };
		$result  = $ref->invoke( null, $closure );
		$this->assertStringContainsString( '{closure}', $result );
		$this->assertStringContainsString( 'CoreTest.php', $result );
	}

	public function test_short_name_invokable_object(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$invokable = new class() {
			public function __invoke() {}
		};
		$this->assertStringContainsString( '::__invoke', $ref->invoke( null, $invokable ) );
	}

	public function test_short_name_unknown_type(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame( '{unknown}', $ref->invoke( null, 42 ) );
	}

	public function test_short_name_single_element_array(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		// Array with 1 element is not a valid [class, method] callback.
		$this->assertSame( '{unknown}', $ref->invoke( null, [ 'only_one' ] ) );
	}

	// ── Constructor tests ───────────────────────────────────────────────

	public function test_constructor_disabled_logging(): void {
		$core = new Core();
		$this->assertInstanceOf( Core::class, $core );
	}

	public function test_constructor_registers_hook_filters(): void {
		$this->use_config( [
			'enable_logging'       => true,
			'log_events'           => [ 'the_content', 'wp_head' ],
			'significant_events'   => [ 'the_content hook' ],
			'hook_start_priority'  => 1,
		] );

		$core    = new Core();
		$filters = $GLOBALS['_wp_test_filters'];

		$this->assertArrayHasKey( 'the_content', $filters );
		$this->assertArrayHasKey( 'wp_head', $filters );
		// Two priorities registered: start (from config) and complete (PHP_INT_MAX-1).
		$priorities = \array_keys( $filters['the_content'] );
		$this->assertCount( 2, $priorities );
		$this->assertContains( PHP_INT_MAX - 1, $priorities );
	}

	public function test_constructor_skips_plugin_loaded(): void {
		$this->use_config( [
			'enable_logging' => true,
			'log_events'     => [ 'plugin_loaded', 'init' ],
		] );

		new Core();
		$filters = $GLOBALS['_wp_test_filters'];

		$this->assertArrayNotHasKey( 'plugin_loaded', $filters );
		$this->assertArrayHasKey( 'init', $filters );
	}

	public function test_constructor_skips_empty_hook_names(): void {
		$this->use_config( [
			'enable_logging' => true,
			'log_events'     => [ '', 'init', 42 ],
		] );

		new Core();
		$filters = $GLOBALS['_wp_test_filters'];

		$this->assertArrayNotHasKey( '', $filters );
		$this->assertArrayHasKey( 'init', $filters );
	}

	// ── hook_start / hook_complete tests ────────────────────────────────

	public function test_hook_start_passes_through_value(): void {
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$this->assertSame( 'hello world', $core->hook_start( 'hello world' ) );
	}

	public function test_hook_start_passes_through_null(): void {
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'wp_head';

		$this->assertNull( $core->hook_start() );
	}

	public function test_hook_complete_passes_through_value(): void {
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$this->assertSame( 'test output', $core->hook_complete( 'test output' ) );
	}

	public function test_hook_complete_passes_through_null(): void {
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'wp_head';

		$this->assertNull( $core->hook_complete() );
	}

	public function test_hook_start_with_integer(): void {
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'test_hook';

		$this->assertSame( 42, $core->hook_start( 42 ) );
	}

	public function test_hook_start_with_array(): void {
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'test_hook';

		$input = [ 'key' => 'value' ];
		$this->assertSame( $input, $core->hook_start( $input ) );
	}

	public function test_hook_start_with_long_string(): void {
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$long_string = \str_repeat( 'x', 2000 );
		$this->assertSame( $long_string, $core->hook_start( $long_string ) );
	}

	public function test_hook_start_with_boolean(): void {
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'test_hook';

		$this->assertTrue( $core->hook_start( true ) );
	}

	// ── significant_events parsing ──────────────────────────────────────

	public function test_constructor_parses_significant_events(): void {
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook', 'wp_head' ],
		] );

		$core = new Core();

		$ref = new \ReflectionProperty( Core::class, 'significant' );
		$ref->setAccessible( true );
		$sig = $ref->getValue( $core );

		$this->assertArrayHasKey( 'the_content', $sig );
		$this->assertArrayHasKey( 'wp_head', $sig );
	}

	// ── wrap_callbacks tests ────────────────────────────────────────────

	public function test_wrap_callbacks_skips_when_no_wp_filter(): void {
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$this->assertSame( 'test', $core->hook_start( 'test' ) );
	}

	public function test_wrap_callbacks_wraps_eligible_callbacks(): void {
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v . ' modified'; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'test_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$wrapped = $wp_filter['the_content']->callbacks[10]['test_cb']['function'];
		$this->assertNotSame( $original, $wrapped, 'Callback should be wrapped' );
		$this->assertSame( 99, $wp_filter['the_content']->callbacks[10]['test_cb']['accepted_args'] );
	}

	public function test_wrap_callbacks_skips_start_priority(): void {
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();

		// Get the actual start priority from the instance.
		$ref      = new \ReflectionProperty( Core::class, 'start_priority' );
		$ref->setAccessible( true );
		$priority = $ref->getValue( $core );

		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			$priority => [
				'our_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		// Callbacks at start_priority should NOT be wrapped.
		$this->assertSame( $original, $wp_filter['the_content']->callbacks[ $priority ]['our_cb']['function'] );
	}

	public function test_wrap_callbacks_prevents_double_wrap(): void {
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'test_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );
		$first_wrap = $wp_filter['the_content']->callbacks[10]['test_cb']['function'];

		$core->hook_start( 'test' );
		$second_wrap = $wp_filter['the_content']->callbacks[10]['test_cb']['function'];

		$this->assertSame( $first_wrap, $second_wrap, 'Should not double-wrap' );
	}

	public function test_wrap_callbacks_skips_max_int_priority(): void {
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			PHP_INT_MAX - 1 => [
				'our_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		// Priority PHP_INT_MAX - 1 should NOT be wrapped (it's our own hook_complete).
		$this->assertSame( $original, $wp_filter['the_content']->callbacks[ PHP_INT_MAX - 1 ]['our_cb']['function'] );
	}

	// ── custom start_priority ───────────────────────────────────────────

	public function test_constructor_uses_config_start_priority(): void {
		$this->use_config( [
			'enable_logging' => true,
			'log_events'     => [ 'init' ],
		] );

		$core = new Core();
		$ref  = new \ReflectionProperty( Core::class, 'start_priority' );
		$ref->setAccessible( true );
		$priority = $ref->getValue( $core );

		// Config file sets hook_start_priority — verify it was read.
		$filters = $GLOBALS['_wp_test_filters'];
		$this->assertArrayHasKey( 'init', $filters );
		$this->assertArrayHasKey( $priority, $filters['init'] );
	}

	// ── hook_start with enabled logging ─────────────────────────────────

	public function test_hook_start_logs_with_enabled_logging(): void {
		$this->use_config( [
			'enable_logging' => true,
			'log_events'     => [ 'the_content' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$result = $core->hook_start( '<p>Hello</p>' );
		$this->assertSame( '<p>Hello</p>', $result );
	}

	public function test_wrapped_callback_executes_and_returns_value(): void {
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v . ' MODIFIED'; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'test_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		// Now invoke the wrapped callback and verify it returns the correct value.
		$wrapped = $wp_filter['the_content']->callbacks[10]['test_cb']['function'];
		$result  = \call_user_func( $wrapped, 'hello' );
		$this->assertSame( 'hello MODIFIED', $result, 'Wrapped callback should return original callback result' );
	}

	public function test_accepted_args_zero_callback_works(): void {
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function () { return 'no-args-result'; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'zero_cb' => [
					'function'      => $original,
					'accepted_args' => 0,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		// accepted_args should be 99 (the wrapper value).
		$this->assertSame( 99, $wp_filter['the_content']->callbacks[10]['zero_cb']['accepted_args'] );

		// Invoke with zero args — should work without error.
		$wrapped = $wp_filter['the_content']->callbacks[10]['zero_cb']['function'];
		$result  = \call_user_func( $wrapped );
		$this->assertSame( 'no-args-result', $result, 'Wrapper should pass through zero-arg callback result' );
	}

	public function test_hook_start_non_significant_skips_wrap(): void {
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'init' ],
			'significant_events' => [],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'init';

		// No wp_filter to check, but hook_start should still work without wrapping.
		$result = $core->hook_start( null );
		$this->assertNull( $result );
	}
}
