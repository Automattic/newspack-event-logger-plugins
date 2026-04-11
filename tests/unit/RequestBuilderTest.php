<?php
/**
 * Tests for RequestBuilder (reconstructs requests from firehose entries).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\LruCache;
use Newspack_Performance_Workers\Cron\RequestBuilder;

#[\PHPUnit\Framework\Attributes\CoversClass( RequestBuilder::class )]
class RequestBuilderTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-requestbuilder';

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );

		$config_path = self::TEST_DIR . '/config.php';
		\file_put_contents( $config_path, '<?php return ' . \var_export( [
			'base_directory'   => self::TEST_DIR,
			'num_partitions'   => 1,
			'num_segments'     => 4,
			'segment_size'     => 65536,
			'max_lifespan'     => 0,
			'enable_logging'   => false,
			'enable_workers'   => false,
			'memcache_servers' => [],
			'allowed_users'    => [],
			'log_urls'         => [],
			'skip_urls'        => [],
			'custom_colors'    => [],
			'custom_events'    => [],
			'log_events'       => [],
			'log_memory'       => false,
			'flush_every_line' => false,
		], true ) . ';' );
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $config_path );
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
	 * Create a context with init() called.
	 */
	private function make_context( int $partition = 0 ): array {
		$context = [
			'partition' => $partition,
			'log_base'  => self::TEST_DIR . '/logs',
		];
		RequestBuilder::init( $context, null );
		return $context;
	}

	/**
	 * Build a firehose entry JSON line.
	 */
	private function entry( string $rid, string $keyword, $message = '', array $extra = [] ): string {
		$data = [
			'ts'  => \microtime( true ),
			'n'   => 1,
			'rid' => $rid,
			'k'   => $keyword,
		];
		if ( '' !== $message ) {
			$data['m'] = $message;
		}
		return \wp_json_encode( \array_merge( $data, $extra ) );
	}

	public function test_init_creates_request_cache(): void {
		$context = $this->make_context();

		$this->assertArrayHasKey( 'request_cache', $context );
		$this->assertInstanceOf( LruCache::class, $context['request_cache'] );
		$this->assertArrayHasKey( 'requests_log', $context );
		$this->assertInstanceOf( Firehose::class, $context['requests_log'] );
		$this->assertArrayHasKey( 'state_callbacks', $context );
	}

	public function test_init_restores_saved_state(): void {
		// Create a cache with some data (arrays, as they'd be serialized).
		$cache = new LruCache( 10, 2 );
		$cache->set( 'rid1', [ 'rid' => 'rid1', 'url' => '/test', 'initialized' => true ] );
		$state = $cache->get_state();

		$context = [
			'partition' => 0,
			'log_base'  => self::TEST_DIR . '/logs',
		];
		RequestBuilder::init( $context, [ 'request_cache' => $state ] );

		$restored = $context['request_cache']->get( 'rid1' );
		$this->assertNotNull( $restored );
		// Restored from serialized arrays — init converts to stdClass.
		$this->assertInstanceOf( \stdClass::class, $restored );
		$this->assertSame( 'rid1', $restored->rid );
	}

	public function test_process_start_initializes_request(): void {
		$context = $this->make_context();

		RequestBuilder::process( $this->entry( 'r1', 'process (start)' ), 'firehose.log', $context );

		$request = $context['request_cache']->get( 'r1' );
		$this->assertInstanceOf( \stdClass::class, $request );
		$this->assertTrue( $request->initialized );
		$this->assertSame( 'process', $request->state );
		$this->assertSame( [ [ 'process', '' ] ], $request->stack );
	}

	public function test_process_skips_entry_without_start(): void {
		$context = $this->make_context();

		// Send a hook entry without process (start) first.
		RequestBuilder::process( $this->entry( 'r2', 'hook (start)', 'init' ), 'firehose.log', $context );

		$request = $context['request_cache']->get( 'r2' );
		$this->assertNull( $request, 'Entry without process (start) should be ignored' );
	}

	public function test_process_complete_request(): void {
		$context = $this->make_context();
		$rid     = 'r3';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'process', '123 on host1' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'request', 'GET https://example.com/page' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'process (complete)', '', [ 'duration_ms' => 42.5, 'status_code' => 200 ] ), 'firehose.log', $context );

		// After process (complete), the request should be evicted from cache (written to log).
		$request = $context['request_cache']->get( $rid );
		$this->assertNull( $request, 'Completed request should be flushed from cache' );

		// Verify it was written to requests.log.
		$req_dir = self::TEST_DIR . '/logs/requests.log/p0';
		$this->assertDirectoryExists( $req_dir );
		$files = \glob( $req_dir . '/*.log' );
		$this->assertNotEmpty( $files );
		$content = \file_get_contents( $files[0] );
		$this->assertStringContainsString( $rid, $content );
	}

	public function test_process_extracts_url(): void {
		$context = $this->make_context();
		$rid     = 'r4';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'request', 'GET https://example.com/page?q=1' ), 'firehose.log', $context );

		$request = $context['request_cache']->get( $rid );
		// URL should be stripped of query string.
		$this->assertSame( 'https://example.com/page', $request->url );
		$this->assertSame( 'GET', $request->request_method );
	}

	public function test_process_extracts_environment(): void {
		$context = $this->make_context();
		$rid     = 'r5';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'environment_v2', 'REMOTE_ADDR => "10.0.0.1"' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'environment_v2', 'HTTP_USER_AGENT => "TestBot/1.0"' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'environment_v2', 'SERVER_NAME => "example.com"' ), 'firehose.log', $context );

		$request = $context['request_cache']->get( $rid );
		$this->assertSame( '10.0.0.1', $request->remote_addr );
		$this->assertSame( 'TestBot/1.0', $request->user_agent );
		$this->assertSame( 'example.com', $request->server_name );
	}

	public function test_process_extracts_process_id_and_host(): void {
		$context = $this->make_context();
		$rid     = 'r6';

		RequestBuilder::process( $this->entry( $rid, 'process (start)', '12345 on web-host-01' ), 'firehose.log', $context );

		$request = $context['request_cache']->get( $rid );
		$this->assertSame( '12345', $request->process_id );
		$this->assertSame( 'web-host-01', $request->host );
	}

	public function test_process_worker_type_flag(): void {
		$context = $this->make_context();
		$rid     = 'r7';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'worker_type', 'supervisor' ), 'firehose.log', $context );

		$request = $context['request_cache']->get( $rid );
		$this->assertTrue( $request->is_worker );
	}

	public function test_push_stack_and_pop_stack(): void {
		$context = $this->make_context();
		$rid     = 'r8';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'hook (start)', 'init' ), 'firehose.log', $context );

		$request = $context['request_cache']->get( $rid );
		$this->assertSame( [ [ 'process', '' ], [ 'hook', '' ] ], $request->stack );

		RequestBuilder::process(
			\wp_json_encode( [
				'ts'          => \microtime( true ),
				'n'           => 3,
				'rid'         => $rid,
				'k'           => 'hook (complete)',
				'duration_ms' => 5.0,
			] ),
			'firehose.log',
			$context
		);

		$request = $context['request_cache']->get( $rid );
		$this->assertSame( [ [ 'process', '' ] ], $request->stack );
		$this->assertArrayHasKey( 'hook', $request->profiles );
		$this->assertSame( 1, $request->profiles['hook']['count'] );
	}

	public function test_runaway_request_evicted(): void {
		$context = $this->make_context();
		$rid     = 'r9';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );

		// Push more than MAX_STACK_DEPTH (50) entries.
		for ( $i = 0; $i < 52; $i++ ) {
			RequestBuilder::process( $this->entry( $rid, "hook{$i} (start)", "cb{$i}" ), 'firehose.log', $context );
		}

		// After exceeding stack depth, the request should be flagged as runaway and evicted.
		$request = $context['request_cache']->get( $rid );
		$this->assertNull( $request, 'Runaway request should be evicted from cache' );
	}

	public function test_lru_eviction_writes_timed_out_request(): void {
		$context = $this->make_context();

		// Use a tiny LRU (2 per bucket, 2 buckets) with instant rotation to trigger eviction.
		$context['request_cache'] = ( new \Newspack_Event_Logger\LruCache( 2, 2 ) )
			->with_timed_rotation(
				0.001, // Rotate almost immediately.
				function ( string $rid, $request ) use ( &$context ): void {
					// Mirror the real eviction logic inline.
					if ( ! ( $request instanceof \stdClass ) || empty( $request->url ) || 'complete' === ( $request->state ?? '' ) ) {
						return;
					}
					$now                    = \time();
					$request->error_status  = 'T';
					$request->duration_ms   = ( $now - (int) ( $request->timestamp ?? $now ) ) * 1000;
					$request->status_code   = $request->status_code ?? 0;
					$request->state         = 'complete';
					$context['requests_log']->write( \wp_json_encode( $request ) );
				}
			);

		// Insert a request object with a URL (evictable).
		$r10 = new \stdClass();
		$r10->rid         = 'r10';
		$r10->url         = '/old-page';
		$r10->timestamp   = \time() - 600;
		$r10->initialized = true;
		$r10->state       = 'process';
		$r10->entries     = [];
		$context['request_cache']->set( 'r10', $r10 );

		// Fill bucket to trigger capacity rotation, then wait for time rotation.
		$context['request_cache']->set( 'filler1', (object) [ 'rid' => 'filler1' ] );
		$context['request_cache']->set( 'filler2', (object) [ 'rid' => 'filler2' ] );
		\usleep( 2000 ); // 2ms > 0.001s rotation interval.
		$context['request_cache']->set( 'filler3', (object) [ 'rid' => 'filler3' ] );
		$context['request_cache']->set( 'filler4', (object) [ 'rid' => 'filler4' ] );
		\usleep( 2000 );
		$context['request_cache']->rotate_if_due();

		// Verify evicted request was written with error_status=T.
		$req_dir = self::TEST_DIR . '/logs/requests.log/p0';
		$files   = \glob( $req_dir . '/*.log' );
		$this->assertNotEmpty( $files );
		$all_content = '';
		foreach ( $files as $f ) {
			$all_content .= \file_get_contents( $f );
		}
		$this->assertStringContainsString( '"error_status":"T"', $all_content );
	}

	public function test_active_requests_survive_rotation(): void {
		$context = $this->make_context();

		// Use timed rotation with a very short interval.
		$evicted_rids = [];
		$context['request_cache'] = ( new \Newspack_Event_Logger\LruCache( 100, 3 ) )
			->with_timed_rotation(
				0.001,
				function ( string $rid, $request ) use ( &$evicted_rids ): void {
					$evicted_rids[] = $rid;
				}
			);

		$r11 = new \stdClass();
		$r11->rid         = 'r11';
		$r11->url         = '/active-page';
		$r11->timestamp   = \time();
		$r11->initialized = true;
		$r11->state       = 'process';
		$context['request_cache']->set( 'r11', $r11 );

		// Rotate twice — r11 should be promoted on get() and survive.
		\usleep( 2000 );
		$context['request_cache']->rotate_if_due();
		$context['request_cache']->get( 'r11' ); // Promote to current bucket.
		\usleep( 2000 );
		$context['request_cache']->rotate_if_due();

		$request = $context['request_cache']->get( 'r11' );
		$this->assertNotNull( $request, 'Active request should survive rotation' );
		$this->assertNotContains( 'r11', $evicted_rids, 'Active request should not be evicted' );
	}

	public function test_save_state_preserves_entries(): void {
		$context = $this->make_context();
		$rid     = 'r12';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'request', 'GET /test-save' ), 'firehose.log', $context );

		$state = RequestBuilder::save_state( $context );
		$this->assertArrayHasKey( 'request_cache', $state );
		$this->assertIsArray( $state['request_cache'] );
	}

	public function test_format_index_entry_produces_fixed_width(): void {
		$line = \wp_json_encode( [
			'rid'            => 'abcdef1234567890abcdef1234567890',
			'url'            => 'https://example.com/page',
			'timestamp'      => 1700000000,
			'duration_ms'    => 42,
			'status_code'    => 200,
			'request_method' => 'GET',
			'peak_mb'        => 64.5,
			'error_status'   => '-',
		] );

		$position = [
			'segment_id' => 0,
			'offset'     => 0,
			'length'     => \strlen( $line ) + 1,
		];

		$result = RequestBuilder::format_index_entry( $line, $position );
		$this->assertIsString( $result );
		// Total width: 32 (rid) + 12 (url_hash) + 10 (ts) + 8 (dur) + 3 (status) + 6 (seg) + 10 (offset) + 8 (length) + 6 (peak) + 1 (method) + 1 (error) = 97.
		$this->assertSame( 97, \strlen( $result ) );
	}

	public function test_format_index_entry_returns_null_for_no_url(): void {
		$line = \wp_json_encode( [
			'rid' => 'abc123',
		] );

		$result = RequestBuilder::format_index_entry( $line, [ 'segment_id' => 0, 'offset' => 0, 'length' => 10 ] );
		$this->assertNull( $result );
	}

	public function test_parse_request_index_roundtrip(): void {
		$line = \wp_json_encode( [
			'rid'            => 'abcdef1234567890abcdef1234567890',
			'url'            => 'https://example.com/page',
			'timestamp'      => 1700000000,
			'duration_ms'    => 42,
			'status_code'    => 200,
			'request_method' => 'GET',
			'peak_mb'        => 64.0,
			'error_status'   => 'F',
		] );

		$position = [
			'segment_id' => 5,
			'offset'     => 12345,
			'length'     => 678,
		];

		$index_line = RequestBuilder::format_index_entry( $line, $position );
		$this->assertNotNull( $index_line );

		$parsed = RequestBuilder::parse_request_index( $index_line );
		$this->assertNotNull( $parsed );
		$this->assertSame( 'abcdef1234567890abcdef1234567890', $parsed['rid'] );
		$this->assertSame( 1700000000, $parsed['timestamp'] );
		$this->assertSame( 42, $parsed['duration_ms'] );
		$this->assertSame( 200, $parsed['status_code'] );
		$this->assertSame( 5, $parsed['segment_id'] );
		$this->assertSame( 12345, $parsed['offset'] );
		$this->assertSame( 678, $parsed['length'] );
		$this->assertSame( 64, $parsed['peak_mb'] );
		$this->assertSame( 'GET', $parsed['method'] );
		$this->assertSame( 'F', $parsed['error_status'] );
	}

	public function test_parse_request_index_short_line(): void {
		$result = RequestBuilder::parse_request_index( 'too-short' );
		$this->assertNull( $result );
	}

	public function test_parse_request_index_v1_format(): void {
		// V1 format: 89 chars (no peak_mb, no method, no error_status).
		$line = \str_pad( 'rid12345678901234567890123456789', 32 )
			. \str_pad( 'urlhash12345', 12 )
			. '1700000000'   // timestamp (10)
			. '00000042'     // duration_ms (8)
			. '200'          // status_code (3)
			. '000005'       // segment_id (6)
			. '0000012345'   // offset (10)
			. '00000678';    // length (8)

		$this->assertSame( 89, \strlen( $line ) );
		$parsed = RequestBuilder::parse_request_index( $line );
		$this->assertNotNull( $parsed );
		$this->assertSame( 42, $parsed['duration_ms'] );
		$this->assertArrayNotHasKey( 'peak_mb', $parsed );
		$this->assertArrayNotHasKey( 'method', $parsed );
	}

	public function test_url_hash_consistency(): void {
		$hash1 = RequestBuilder::url_hash( '/test/page' );
		$hash2 = RequestBuilder::url_hash( '/test/page' );
		$this->assertSame( $hash1, $hash2, 'Same URL should produce same hash' );
	}

	public function test_url_hash_strips_query_string(): void {
		$hash1 = RequestBuilder::url_hash( '/test/page?q=1' );
		$hash2 = RequestBuilder::url_hash( '/test/page?q=2' );
		$this->assertSame( $hash1, $hash2, 'Query string should be stripped before hashing' );
	}

	public function test_url_hash_is_12_chars(): void {
		$hash = RequestBuilder::url_hash( '/any/url' );
		$this->assertSame( 12, \strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $hash );
	}

	public function test_url_hash_differs_for_different_urls(): void {
		$hash1 = RequestBuilder::url_hash( '/page-a' );
		$hash2 = RequestBuilder::url_hash( '/page-b' );
		$this->assertNotSame( $hash1, $hash2, 'Different URLs should produce different hashes (with high probability)' );
	}

	public function test_environment_v2_worker_type_detection(): void {
		$context = $this->make_context();
		$rid     = 'r_env_v2';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process(
			$this->entry( $rid, 'environment_v2', 'EVENT_LOGGER_WORKER_TYPE => "supervisor"' ),
			'firehose.log',
			$context
		);

		$request = $context['request_cache']->get( $rid );
		$this->assertTrue( $request->is_worker, 'environment_v2 with EVENT_LOGGER_WORKER_TYPE should set is_worker' );
	}

	public function test_process_memory_peak(): void {
		$context = $this->make_context();
		$rid     = 'r13';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process(
			\wp_json_encode( [
				'ts'  => \microtime( true ),
				'n'   => 2,
				'rid' => $rid,
				'k'   => 'memory',
				'm'   => [ 'peak' => '128.5MB' ],
			] ),
			'firehose.log',
			$context
		);

		$request = $context['request_cache']->get( $rid );
		$this->assertSame( 128.5, $request->peak_mb );
	}

	public function test_process_invalid_json_skipped(): void {
		$context = $this->make_context();
		RequestBuilder::process( 'invalid{json', 'firehose.log', $context );
		// Should not throw.
		$this->assertTrue( true );
	}

	public function test_process_entry_without_rid_skipped(): void {
		$context = $this->make_context();
		$line    = \wp_json_encode( [ 'ts' => \microtime( true ), 'k' => 'process (start)' ] );
		RequestBuilder::process( $line, 'firehose.log', $context );
		$this->assertTrue( true );
	}

	public function test_process_errors_forwarded_to_errors_log(): void {
		$context = $this->make_context();
		$rid     = 'r14';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'error', 'Something broke' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'warning', 'Watch out' ), 'firehose.log', $context );

		// Verify errors were written to errors.log.
		$err_dir = self::TEST_DIR . '/logs/errors.log/p0';
		$this->assertDirectoryExists( $err_dir );
		$files = \glob( $err_dir . '/*.log' );
		$this->assertNotEmpty( $files );
		$content = \file_get_contents( $files[0] );
		$this->assertStringContainsString( 'error', $content );
		$this->assertStringContainsString( 'warning', $content );
	}

	public function test_entries_stored_on_request(): void {
		$context = $this->make_context();
		$rid     = 'r15';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'request', 'GET /test' ), 'firehose.log', $context );
		RequestBuilder::process( $this->entry( $rid, 'hook (start)', 'init' ), 'firehose.log', $context );

		$request = $context['request_cache']->get( $rid );
		$this->assertIsArray( $request->entries );
		$this->assertGreaterThanOrEqual( 3, \count( $request->entries ) );
	}

	public function test_entry_message_truncation(): void {
		$context = $this->make_context();
		$rid     = 'r16';

		RequestBuilder::process( $this->entry( $rid, 'process (start)' ), 'firehose.log', $context );
		// Message longer than MAX_ENTRY_MESSAGE_LENGTH (1024).
		$long_msg = \str_repeat( 'x', 2000 );
		RequestBuilder::process( $this->entry( $rid, 'info', $long_msg ), 'firehose.log', $context );

		$request = $context['request_cache']->get( $rid );
		$last    = \end( $request->entries );
		$this->assertLessThanOrEqual( 1024, \strlen( $last['m'] ) );
	}
}
