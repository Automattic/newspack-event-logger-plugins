<?php
/**
 * Tests for FlameBuilder (builds flame graphs and accumulates per-URL stats).
 *
 * FlameBuilder is the largest class in the codebase (690 lines).
 * Focus: accumulate_all_stats() — the core stats accumulation logic,
 * build_flame_data() — stack-based flame graph construction,
 * bucket_key() — time-series bucketing, and index formatting.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\LruCache;
use Newspack_Performance_Workers\Cron\FlameBuilder;
use Newspack_Performance_Workers\Cron\RequestBuilder;
use Newspack_Performance_Workers\StatsStore;

#[\PHPUnit\Framework\Attributes\CoversClass( FlameBuilder::class )]
class FlameBuilderTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-flamebuilder';

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
		@\mkdir( self::TEST_DIR . '/locks', 0755, true );
		@\mkdir( self::TEST_DIR . '/offsets', 0755, true );

		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/flamebuilder.php' );
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
	 * Make a minimal FlameBuilder context with init().
	 *
	 * @param int $partition Partition index.
	 * @return array Context array.
	 */
	private function make_context( int $partition = 0 ): array {
		$context = [ 'partition' => $partition ];
		FlameBuilder::init( $context, null );
		return $context;
	}

	/**
	 * Call private static method via Reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed Return value.
	 */
	private static function call_private( string $method, array $args ) {
		$ref = new \ReflectionMethod( FlameBuilder::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	/**
	 * Build a mock request array.
	 *
	 * @param array $overrides Fields to override.
	 * @return array Request data.
	 */
	private function make_request( array $overrides = [] ): array {
		return \array_merge( [
			'rid'            => \wp_generate_uuid4(),
			'url'            => '/test-page',
			'duration_ms'    => 150.0,
			'timestamp'      => \time(),
			'status_code'    => 200,
			'error_status'   => '-',
			'is_worker'      => false,
			'peak_mb'        => 12.5,
			'request_method' => 'GET',
			'server_name'    => 'localhost',
			'entries'        => [],
			'profiles'       => [],
		], $overrides );
	}

	/**
	 * Build flame_data from entries using Reflection.
	 *
	 * @param array $entries Log entries.
	 * @param array $context Context.
	 * @return array Flame data.
	 */
	private function build_flame( array $entries, array &$context ): array {
		return self::call_private( 'build_flame_data', [ $entries, &$context ] );
	}

	/**
	 * Call accumulate_all_stats via Reflection.
	 *
	 * @param string $url_hash   URL hash.
	 * @param array  $flame_data Flame data.
	 * @param array  $profiles   Profiles.
	 * @param array  $request    Request.
	 * @param array  $context    Context (by reference).
	 */
	private function accumulate( string $url_hash, array $flame_data, array $profiles, array $request, array &$context ): void {
		self::call_private( 'accumulate_all_stats', [ $url_hash, $flame_data, $profiles, $request, &$context ] );
	}

	/**
	 * Promote pending bucket data to top-level context arrays via Reflection.
	 *
	 * Data accumulates in $context['pending'] and only moves to top-level
	 * arrays (url_stats, hourly_stats, dim_stats, etc.) on bucket rotation.
	 * Tests that check top-level arrays must call this after accumulation.
	 *
	 * @param array $context Context (by reference).
	 */
	private function promote_pending( array &$context ): void {
		self::call_private( 'promote_pending_bucket', [ &$context ] );
	}

	// ── init ──────────────────────────────────────────────────────────────

	public function test_init_creates_required_context_keys(): void {
		$context = $this->make_context();
		$this->assertInstanceOf( LruCache::class, $context['stats_cache'] );
		$this->assertSame( [], $context['leaderboard_stats'] );
		$this->assertSame( [], $context['leaderboard_by_server_stats'] );
		$this->assertSame( [], $context['url_stats'] );
		$this->assertSame( [], $context['hourly_stats'] );
		$this->assertSame( [], $context['dim_stats'] );
		$this->assertSame( [], $context['url_dim_stats'] );
		$this->assertSame( false, $context['is_hub'] );
		// Pending leaderboard is initialized with empty sums.
		$this->assertSame( 0, $context['pending']['leaderboard']['count'] );
		$this->assertSame( [], $context['pending']['leaderboard']['categories'] );
		$this->assertSame( [], $context['pending']['leaderboard_by_server'] );
	}

	public function test_init_recognizes_hub_mode(): void {
		// Reconfigure with aggregator servers using pre-written hub config.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/flamebuilder-hub.php' );
		Config::reset();

		$context = $this->make_context();
		$this->assertTrue( $context['is_hub'] );
	}

	// ── bucket_key ────────────────────────────────────────────────────────

	public function test_bucket_key_rounds_down_to_5min_boundary(): void {
		// 2024-01-15 10:07:33 UTC should round to 10:05.
		$ts = \gmmktime( 10, 7, 33, 1, 15, 2024 );
		$key = self::call_private( 'bucket_key', [ $ts ] );
		$this->assertSame( '2024-01-15-10-05', $key );
	}

	public function test_bucket_key_on_exact_boundary(): void {
		$ts = \gmmktime( 14, 30, 0, 6, 1, 2025 );
		$key = self::call_private( 'bucket_key', [ $ts ] );
		$this->assertSame( '2025-06-01-14-30', $key );
	}

	public function test_bucket_key_at_hour_start(): void {
		$ts = \gmmktime( 0, 0, 0, 12, 31, 2025 );
		$key = self::call_private( 'bucket_key', [ $ts ] );
		$this->assertSame( '2025-12-31-00-00', $key );
	}

	public function test_bucket_key_minute_59(): void {
		$ts = \gmmktime( 23, 59, 59, 3, 15, 2026 );
		$key = self::call_private( 'bucket_key', [ $ts ] );
		$this->assertSame( '2026-03-15-23-55', $key );
	}

	// ── build_flame_data ──────────────────────────────────────────────────

	public function test_build_flame_empty_entries(): void {
		$context = $this->make_context();
		$flame   = $this->build_flame( [], $context );
		$this->assertSame( 'request', $flame['name'] );
		$this->assertSame( 0, $flame['value'] );
		$this->assertSame( [], $flame['children'] );
	}

	public function test_build_flame_simple_span(): void {
		$context = $this->make_context();
		$entries = [
			[ 'k' => 'wp_head (start)', 'l' => '' ],
			[ 'k' => 'wp_head (complete)', 'duration_ms' => 50, 'ts' => \time() ],
		];
		$flame = $this->build_flame( $entries, $context );
		$this->assertCount( 1, $flame['children'] );
		$this->assertSame( 'wp_head', $flame['children'][0]['name'] );
		$this->assertSame( 50, $flame['children'][0]['value'] );
	}

	public function test_build_flame_nested_spans(): void {
		$context = $this->make_context();
		$entries = [
			[ 'k' => 'wp_head (start)', 'l' => '' ],
			[ 'k' => 'wp_enqueue_scripts (start)', 'l' => '' ],
			[ 'k' => 'wp_enqueue_scripts (complete)', 'duration_ms' => 20, 'ts' => \time() ],
			[ 'k' => 'wp_head (complete)', 'duration_ms' => 80, 'ts' => \time() ],
		];
		$flame = $this->build_flame( $entries, $context );

		$this->assertCount( 1, $flame['children'] );
		$wp_head = $flame['children'][0];
		$this->assertSame( 'wp_head', $wp_head['name'] );
		$this->assertSame( 80, $wp_head['value'] );
		$this->assertCount( 1, $wp_head['children'] );
		$this->assertSame( 'wp_enqueue_scripts', $wp_head['children'][0]['name'] );
		$this->assertSame( 20, $wp_head['children'][0]['value'] );
	}

	public function test_build_flame_with_label(): void {
		$context = $this->make_context();
		$entries = [
			[ 'k' => 'template (start)', 'l' => 'single.php' ],
			[ 'k' => 'template (complete)', 'duration_ms' => 100, 'ts' => \time() ],
		];
		$flame = $this->build_flame( $entries, $context );
		$this->assertSame( 'template: single.php', $flame['children'][0]['name'] );
	}

	public function test_build_flame_duplicate_siblings_get_numbered(): void {
		$context = $this->make_context();
		$entries = [
			[ 'k' => 'query (start)', 'l' => '' ],
			[ 'k' => 'query (complete)', 'duration_ms' => 10, 'ts' => \time() ],
			[ 'k' => 'query (start)', 'l' => '' ],
			[ 'k' => 'query (complete)', 'duration_ms' => 15, 'ts' => \time() ],
		];
		$flame = $this->build_flame( $entries, $context );

		$this->assertCount( 2, $flame['children'] );
		// Duplicate siblings should have hidden suffix \x00N.
		$name0 = $flame['children'][0]['name'];
		$name1 = $flame['children'][1]['name'];
		$this->assertStringStartsWith( 'query', $name0 );
		$this->assertStringStartsWith( 'query', $name1 );
		// They should be different (numbered).
		$this->assertNotSame( $name0, $name1 );
	}

	public function test_build_flame_orphaned_complete_ignored(): void {
		$context = $this->make_context();
		$entries = [
			// Complete without a start -- should be ignored.
			[ 'k' => 'mystery (complete)', 'duration_ms' => 999, 'ts' => \time() ],
		];
		$flame = $this->build_flame( $entries, $context );
		$this->assertSame( [], $flame['children'] );
	}

	// ── accumulate_all_stats — normal requests ────────────────────────────

	public function test_accumulate_normal_request_increments_url_stats(): void {
		$context  = $this->make_context();
		$request  = $this->make_request( [ 'duration_ms' => 200.0, 'status_code' => 200 ] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 200.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		// URL stats should have one entry.
		$this->assertNotEmpty( $context['url_stats'] );
		$hour_data = \reset( $context['url_stats'] );
		$this->assertArrayHasKey( $url_hash, $hour_data );
		$stats = $hour_data[ $url_hash ];
		$this->assertSame( 1, $stats['count'] );
		$this->assertSame( 1, $stats['timed_count'] );
		$this->assertEqualsWithDelta( 200.0, $stats['sum_ms'], 0.01 );
		$this->assertEqualsWithDelta( 200.0, $stats['max_ms'], 0.01 );
		$this->assertEqualsWithDelta( 200.0, $stats['min_ms'], 0.01 );
		$this->assertSame( 1, $stats['count_2xx'] );
		$this->assertSame( 0, $stats['count_4xx'] );
		$this->assertCount( 1, $stats['durations'] );
	}

	public function test_accumulate_multiple_requests_sums_correctly(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/multi' );

		$request1 = $this->make_request( [ 'url' => '/multi', 'duration_ms' => 100.0, 'status_code' => 200 ] );
		$request2 = $this->make_request( [ 'url' => '/multi', 'duration_ms' => 300.0, 'status_code' => 200 ] );
		$flame1   = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
		$flame2   = [ 'name' => 'request', 'value' => 300.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame1, [], $request1, $context );
		$this->accumulate( $url_hash, $flame2, [], $request2, $context );
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		$this->assertSame( 2, $stats['count'] );
		$this->assertSame( 2, $stats['timed_count'] );
		$this->assertEqualsWithDelta( 400.0, $stats['sum_ms'], 0.01 );
		$this->assertEqualsWithDelta( 300.0, $stats['max_ms'], 0.01 );
		$this->assertEqualsWithDelta( 100.0, $stats['min_ms'], 0.01 );
		$this->assertCount( 2, $stats['durations'] );
	}

	// ── accumulate_all_stats — timed-out requests ─────────────────────────

	public function test_timed_out_requests_excluded_from_timing(): void {
		$context  = $this->make_context();
		$request  = $this->make_request( [
			'duration_ms'  => 30000.0,
			'error_status' => 'T', // Timed out.
			'status_code'  => 503,
		] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 30000.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		// Count is incremented (request happened), but timing is excluded.
		$this->assertSame( 1, $stats['count'] );
		$this->assertSame( 0, $stats['timed_count'] );
		$this->assertEqualsWithDelta( 0.0, $stats['sum_ms'], 0.01 );
		$this->assertSame( PHP_INT_MAX, $stats['min_ms'], 'min_ms should remain PHP_INT_MAX when no timed requests' );
		$this->assertSame( [], $stats['durations'] );
	}

	// ── accumulate_all_stats — worker requests ────────────────────────────

	public function test_worker_requests_excluded_from_timing(): void {
		$context  = $this->make_context();
		$request  = $this->make_request( [
			'duration_ms' => 5000.0,
			'is_worker'   => true,
			'status_code' => 200,
		] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 5000.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		$this->assertSame( 1, $stats['count'] );
		$this->assertSame( 0, $stats['timed_count'] );
		$this->assertEqualsWithDelta( 0.0, $stats['sum_ms'], 0.01 );
	}

	// ── accumulate_all_stats — hourly stats ───────────────────────────────

	public function test_hourly_stats_bucketed_correctly(): void {
		$context  = $this->make_context();
		$ts       = \gmmktime( 10, 7, 0, 1, 15, 2024 ); // Should bucket to 10:05.
		$request  = $this->make_request( [
			'duration_ms' => 150.0,
			'timestamp'   => $ts,
			'peak_mb'     => 20.0,
		] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 150.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		$expected_key = '2024-01-15-10-05';
		$this->assertArrayHasKey( $expected_key, $context['hourly_stats'] );
		$hstats = $context['hourly_stats'][ $expected_key ];
		$this->assertSame( 1, $hstats['count'] );
		$this->assertEqualsWithDelta( 150.0, $hstats['sum_ms'], 0.01 );
		$this->assertEqualsWithDelta( 20.0, $hstats['sum_peak_mb'], 0.01 );
	}

	// ── accumulate_all_stats — status code bucketing ──────────────────────

	public function test_status_code_bucketed_by_category(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/status-test' );

		$codes = [ 200, 301, 404, 500 ];
		$expected_fields = [ 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx' ];

		foreach ( $codes as $code ) {
			$request = $this->make_request( [
				'url'         => '/status-test',
				'duration_ms' => 100.0,
				'status_code' => $code,
			] );
			$flame = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
			$this->accumulate( $url_hash, $flame, [], $request, $context );
		}
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		$this->assertSame( 4, $stats['count'] );
		$this->assertSame( 1, $stats['count_2xx'] );
		$this->assertSame( 1, $stats['count_3xx'] );
		$this->assertSame( 1, $stats['count_4xx'] );
		$this->assertSame( 1, $stats['count_5xx'] );
	}

	// ── accumulate_all_stats — flame EMA merge ────────────────────────────

	public function test_flame_ema_value_averages_over_requests(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/ema-test' );

		// First request: 100ms.
		$request1 = $this->make_request( [ 'url' => '/ema-test', 'duration_ms' => 100.0 ] );
		$flame1   = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
		$this->accumulate( $url_hash, $flame1, [], $request1, $context );

		$agg1 = $context['stats_cache']->get( $url_hash );
		$this->assertSame( 1, $agg1['flame']['count'] );
		$this->assertEqualsWithDelta( 100.0, $agg1['flame']['value'], 0.01 );

		// Second request: 200ms. EMA: 100 + (200 - 100) / 2 = 150.
		$request2 = $this->make_request( [ 'url' => '/ema-test', 'duration_ms' => 200.0 ] );
		$flame2   = [ 'name' => 'request', 'value' => 200.0, 'children' => [] ];
		$this->accumulate( $url_hash, $flame2, [], $request2, $context );

		$agg2 = $context['stats_cache']->get( $url_hash );
		$this->assertSame( 2, $agg2['flame']['count'] );
		$this->assertEqualsWithDelta( 150.0, $agg2['flame']['value'], 0.01 );
	}

	// ── accumulate_all_stats — profile accumulation ───────────────────────

	public function test_profile_accumulation_creates_categories(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/profile-test' );
		$request  = $this->make_request( [
			'url'         => '/profile-test',
			'duration_ms' => 200.0,
		] );
		$flame = [ 'name' => 'request', 'value' => 200.0, 'children' => [] ];
		$profiles = [
			'wp_head' => [ 'time' => 50.0, 'count' => 3, 'ts' => \time(), 'entries' => [] ],
			'the_content' => [ 'time' => 80.0, 'count' => 1, 'ts' => \time(), 'entries' => [] ],
		];

		$this->accumulate( $url_hash, $flame, $profiles, $request, $context );

		// Per-URL profiles (sums-based).
		$agg = $context['stats_cache']->get( $url_hash );
		$this->assertArrayHasKey( 'profiles', $agg );
		$this->assertSame( 1, $agg['profiles']['count'] );
		$this->assertEqualsWithDelta( 130.0, $agg['profiles']['sum_req_time'], 0.01 );
		$this->assertArrayHasKey( 'wp_head', $agg['profiles']['categories'] );
		$this->assertArrayHasKey( 'the_content', $agg['profiles']['categories'] );
		// One request: sum_time equals the request's contribution.
		$this->assertEqualsWithDelta( 50.0, $agg['profiles']['categories']['wp_head']['sum_time'], 0.01 );
		$this->assertSame( 1, $agg['profiles']['categories']['wp_head']['samples'] );

		// Pending leaderboard (sums-based).
		$lb = $context['pending']['leaderboard'];
		$this->assertSame( 1, $lb['count'] );
		$this->assertEqualsWithDelta( 130.0, $lb['sum_req_time'], 0.01, 'sum_req_time = 50 + 80' );
		$this->assertArrayHasKey( 'wp_head', $lb['categories'] );
		$this->assertEqualsWithDelta( 50.0, $lb['categories']['wp_head']['sum_time'], 0.01 );
		$this->assertSame( 1, $lb['categories']['wp_head']['samples'] );
	}

	public function test_timed_out_requests_exclude_profiles(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/timeout-profile' );
		$request  = $this->make_request( [
			'url'          => '/timeout-profile',
			'duration_ms'  => 30000.0,
			'error_status' => 'T',
		] );
		$flame    = [ 'name' => 'request', 'value' => 30000.0, 'children' => [] ];
		$profiles = [
			'wp_head' => [ 'time' => 100.0, 'count' => 1, 'ts' => \time(), 'entries' => [] ],
		];

		$this->accumulate( $url_hash, $flame, $profiles, $request, $context );

		// Pending leaderboard should remain empty (profiles skipped for timed-out requests).
		$this->assertSame( 0, $context['pending']['leaderboard']['count'] );
		$this->assertSame( [], $context['pending']['leaderboard']['categories'] );
	}

	// ── accumulate_all_stats — dimensional stats ──────────────────────────

	public function test_dimensional_stats_accumulated(): void {
		$context  = $this->make_context();
		$request  = $this->make_request( [
			'duration_ms'    => 150.0,
			'status_code'    => 200,
			'request_method' => 'POST',
			'server_name'    => 'web1.example.com',
			'peak_mb'        => 10.0,
		] );
		$request['status_category'] = '2xx';
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 150.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		// Check method dimension.
		$this->assertNotEmpty( $context['dim_stats']['method'] );
		$hour_data = \reset( $context['dim_stats']['method'] );
		$this->assertArrayHasKey( 'POST', $hour_data );
		$this->assertSame( 1, $hour_data['POST']['c'] );
		$this->assertEqualsWithDelta( 150.0, $hour_data['POST']['s'], 0.01 );
		$this->assertEqualsWithDelta( 10.0, $hour_data['POST']['m'], 0.01 );

		// Check server dimension.
		$this->assertNotEmpty( $context['dim_stats']['server'] );
		$hour_data = \reset( $context['dim_stats']['server'] );
		$this->assertArrayHasKey( 'web1.example.com', $hour_data );
	}

	// ── accumulate_all_stats — peak_mb tracking ───────────────────────────

	public function test_peak_mb_accumulated_in_url_stats(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/mem-test' );
		$request  = $this->make_request( [
			'url'         => '/mem-test',
			'duration_ms' => 100.0,
			'peak_mb'     => 25.0,
		] );
		$flame = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		$this->assertEqualsWithDelta( 25.0, $stats['sum_peak_mb'], 0.01 );
		$this->assertEqualsWithDelta( 25.0, $stats['max_peak_mb'], 0.01 );
	}

	// ── format_index_entry / parse_flame_index ────────────────────────────

	public function test_format_and_parse_index_roundtrip(): void {
		$rid      = 'abc12345def67890abc12345def67890';
		$url_hash = 'a1b2c3d4e5f6';
		$line     = \wp_json_encode( [
			'rid'      => $rid,
			'url_hash' => $url_hash,
			'name'     => 'request',
			'value'    => 100,
			'children' => [],
		] );
		$position = [
			'segment_id' => 3,
			'offset'     => 12345,
			'length'     => 678,
		];

		$index_entry = FlameBuilder::format_index_entry( $line, $position );
		$this->assertNotNull( $index_entry );

		$parsed = FlameBuilder::parse_flame_index( $index_entry . "\n" );
		$this->assertNotNull( $parsed );
		$this->assertSame( $rid, $parsed['rid'] );
		$this->assertSame( $url_hash, $parsed['url_hash'] );
		$this->assertSame( 3, $parsed['segment_id'] );
		$this->assertSame( 12345, $parsed['offset'] );
		$this->assertSame( 678, $parsed['length'] );
	}

	public function test_format_index_entry_returns_null_for_invalid_json(): void {
		$index = FlameBuilder::format_index_entry( 'not json', [ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ] );
		$this->assertNull( $index );
	}

	public function test_format_index_entry_returns_null_for_missing_rid(): void {
		$line  = \wp_json_encode( [ 'url_hash' => 'abc', 'value' => 1 ] );
		$index = FlameBuilder::format_index_entry( $line, [ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ] );
		$this->assertNull( $index );
	}

	public function test_parse_flame_index_returns_null_for_short_line(): void {
		$parsed = FlameBuilder::parse_flame_index( 'too short' );
		$this->assertNull( $parsed );
	}

	// ── strip_name_suffixes ───────────────────────────────────────────────

	public function test_strip_name_suffixes_removes_null_sequences(): void {
		$node = [
			'name'     => "wp_head\x001",
			'value'    => 50,
			'children' => [
				[
					'name'     => "query\x002",
					'value'    => 10,
					'children' => [],
				],
			],
		];
		self::call_private( 'strip_name_suffixes', [ &$node ] );
		$this->assertSame( 'wp_head', $node['name'] );
		$this->assertSame( 'query', $node['children'][0]['name'] );
	}

	// ── merge_flame_children_incremental ──────────────────────────────────

	public function test_merge_flame_children_new_nodes_added(): void {
		$existing = [
			[ 'name' => 'a', 'value' => 10, 'seen_count' => 1, 'ts' => \time(), 'children' => [] ],
		];
		$incoming = [
			[ 'name' => 'b', 'value' => 20, 'ts' => \time(), 'children' => [] ],
		];
		$merged = self::call_private( 'merge_flame_children_incremental', [ $existing, $incoming ] );

		$this->assertCount( 2, $merged );
		$names = \array_column( $merged, 'name' );
		$this->assertContains( 'a', $names );
		$this->assertContains( 'b', $names );
	}

	public function test_merge_flame_children_existing_node_ema_updated(): void {
		$now = \time();
		$existing = [
			[ 'name' => 'query', 'value' => 100, 'seen_count' => 1, 'ts' => $now, 'children' => [] ],
		];
		$incoming = [
			[ 'name' => 'query', 'value' => 200, 'ts' => $now, 'children' => [] ],
		];
		$merged = self::call_private( 'merge_flame_children_incremental', [ $existing, $incoming ] );

		$this->assertCount( 1, $merged );
		$this->assertSame( 2, $merged[0]['seen_count'] );
		// EMA: 100 + (200 - 100) / 2 = 150.
		$this->assertEqualsWithDelta( 150.0, $merged[0]['value'], 0.01 );
	}

	// ── finalize_flame_node ───────────────────────────────────────────────

	public function test_finalize_scales_by_seen_count(): void {
		$node = [
			'name'       => 'query',
			'value'      => 100,
			'seen_count' => 5,
			'ts'         => \time(),
			'children'   => [],
		];
		self::call_private( 'finalize_flame_node', [ &$node, 10 ] );
		// Seen 5 out of 10: value should be scaled to 50.
		$this->assertEqualsWithDelta( 50.0, $node['value'], 0.01 );
		// 'ts' should be removed.
		$this->assertArrayNotHasKey( 'ts', $node );
	}

	public function test_finalize_ensures_parent_ge_children_sum(): void {
		$node = [
			'name'       => 'parent',
			'value'      => 10, // Will be 5 after scaling (5/10).
			'seen_count' => 5,
			'ts'         => \time(),
			'children'   => [
				[
					'name'       => 'child',
					'value'      => 100,
					'seen_count' => 10,
					'ts'         => \time(),
					'children'   => [],
				],
			],
		];
		self::call_private( 'finalize_flame_node', [ &$node, 10 ] );
		// Child seen 10/10: value stays 100.
		// Parent seen 5/10: value scaled to 5, but children sum is 100, so parent bumped.
		$this->assertGreaterThanOrEqual( $node['children'][0]['value'], $node['value'] );
	}

	// ── save_state / cleanup ──────────────────────────────────────────────

	public function test_save_state_returns_pending_structure(): void {
		$context = $this->make_context();
		$state   = FlameBuilder::save_state( $context );
		$this->assertSame( '', $state['pending_bucket'] );
		$this->assertSame( [], $state['pending']['hourly'] );
		$this->assertSame( [], $state['pending']['dim'] );
		$this->assertSame( [], $state['pending']['url_stats'] );
	}

	// ── process — integration-level test ──────────────────────────────────

	public function test_process_valid_request_line(): void {
		$context = $this->make_context();
		$request = $this->make_request( [
			'duration_ms' => 120.0,
			'status_code' => 200,
			'entries'     => [
				[ 'k' => 'the_content (start)', 'l' => '' ],
				[ 'k' => 'the_content (complete)', 'duration_ms' => 40, 'ts' => \time() ],
			],
		] );

		$line = \wp_json_encode( $request );
		FlameBuilder::process( $line, 'requests.log', $context );
		$this->promote_pending( $context );

		// Should have accumulated URL stats.
		$this->assertNotEmpty( $context['url_stats'] );
		// Should have accumulated hourly stats.
		$this->assertNotEmpty( $context['hourly_stats'] );
	}

	public function test_process_skips_invalid_json(): void {
		$context = $this->make_context();
		FlameBuilder::process( 'not valid json {{{', 'requests.log', $context );

		$this->assertEmpty( $context['url_stats'] );
		$this->assertEmpty( $context['hourly_stats'] );
	}

	// ── flush and cleanup ─────────────────────────────────────────────────

	public function test_flush_clears_accumulators(): void {
		$context = $this->make_context();

		// Accumulate some data.
		$request  = $this->make_request( [ 'duration_ms' => 100.0 ] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		$this->assertNotEmpty( $context['url_stats'] );

		// Flush.
		FlameBuilder::flush( $context );

		// Accumulators should be cleared.
		$this->assertSame( [], $context['leaderboard_stats'] );
		$this->assertSame( [], $context['leaderboard_by_server_stats'] );
		$this->assertSame( [], $context['url_stats'] );
		$this->assertSame( [], $context['hourly_stats'] );
		$this->assertSame( [], $context['dim_stats'] );
	}

	public function test_cleanup_calls_flush_and_clears_flames_log(): void {
		$context = $this->make_context();
		$this->assertNotNull( $context['flames_log'] );

		FlameBuilder::cleanup( $context );

		$this->assertNull( $context['flames_log'] );
	}

	// ── EMA_SAMPLE_LIMIT ─────────────────────────────────────────────────

	public function test_flame_count_capped_at_ema_limit(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/cap-test' );

		// Accumulate many requests - count should be capped at EMA_SAMPLE_LIMIT.
		for ( $i = 0; $i < 1005; $i++ ) {
			$request = $this->make_request( [ 'url' => '/cap-test', 'duration_ms' => 100.0 ] );
			$flame   = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
			$this->accumulate( $url_hash, $flame, [], $request, $context );
		}

		$agg = $context['stats_cache']->get( $url_hash );
		$this->assertSame( FlameBuilder::EMA_SAMPLE_LIMIT, $agg['flame']['count'] );
	}

	// ── accumulate_all_stats — min_ms tracking ───────────────────────────

	public function test_min_ms_tracks_minimum_duration(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/min-test' );

		$request1 = $this->make_request( [ 'url' => '/min-test', 'duration_ms' => 300.0 ] );
		$request2 = $this->make_request( [ 'url' => '/min-test', 'duration_ms' => 100.0 ] );
		$request3 = $this->make_request( [ 'url' => '/min-test', 'duration_ms' => 200.0 ] );
		$flame    = [ 'name' => 'request', 'value' => 0.0, 'children' => [] ];

		$flame['value'] = 300.0;
		$this->accumulate( $url_hash, $flame, [], $request1, $context );
		$flame['value'] = 100.0;
		$this->accumulate( $url_hash, $flame, [], $request2, $context );
		$flame['value'] = 200.0;
		$this->accumulate( $url_hash, $flame, [], $request3, $context );
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		$this->assertEqualsWithDelta( 100.0, $stats['min_ms'], 0.01, 'min_ms should track minimum' );
		$this->assertEqualsWithDelta( 300.0, $stats['max_ms'], 0.01, 'max_ms should track maximum' );
	}

	public function test_max_peak_mb_tracks_maximum(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/peak-test' );

		$request1 = $this->make_request( [ 'url' => '/peak-test', 'duration_ms' => 100.0, 'peak_mb' => 10.0 ] );
		$request2 = $this->make_request( [ 'url' => '/peak-test', 'duration_ms' => 100.0, 'peak_mb' => 30.0 ] );
		$request3 = $this->make_request( [ 'url' => '/peak-test', 'duration_ms' => 100.0, 'peak_mb' => 20.0 ] );
		$flame    = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request1, $context );
		$this->accumulate( $url_hash, $flame, [], $request2, $context );
		$this->accumulate( $url_hash, $flame, [], $request3, $context );
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		$this->assertEqualsWithDelta( 30.0, $stats['max_peak_mb'], 0.01, 'max_peak_mb should track maximum' );
		$this->assertEqualsWithDelta( 60.0, $stats['sum_peak_mb'], 0.01, 'sum_peak_mb should be total' );
	}

	public function test_status_code_Nxx_counts(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/nxx-test' );

		// Accumulate several requests with same status to verify counting.
		for ( $i = 0; $i < 3; $i++ ) {
			$request = $this->make_request( [ 'url' => '/nxx-test', 'duration_ms' => 100.0, 'status_code' => 404 ] );
			$flame   = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
			$this->accumulate( $url_hash, $flame, [], $request, $context );
		}
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		$this->assertSame( 3, $stats['count_4xx'] );
		$this->assertSame( 0, $stats['count_2xx'] );
		$this->assertSame( 0, $stats['count_5xx'] );
	}

	// ── process — full lifecycle test ────────────────────────────────────

	public function test_process_full_lifecycle(): void {
		$context = $this->make_context();
		// Use a unique URL so memcache state from other tests doesn't carry over.
		$request = $this->make_request( [
			'url'         => '/test-process-full-lifecycle',
			'duration_ms' => 250.0,
			'status_code' => 200,
			'peak_mb'     => 15.0,
			'entries'     => [
				[ 'k' => 'template (start)', 'l' => 'page.php' ],
				[ 'k' => 'query (start)', 'l' => '' ],
				[ 'k' => 'query (complete)', 'duration_ms' => 30, 'ts' => \time() ],
				[ 'k' => 'template (complete)', 'duration_ms' => 200, 'ts' => \time() ],
			],
			'profiles' => [
				'template' => [ 'time' => 200.0, 'count' => 1, 'ts' => \time(), 'entries' => [] ],
				'query'    => [ 'time' => 30.0, 'count' => 1, 'ts' => \time(), 'entries' => [] ],
			],
		] );

		$line = \wp_json_encode( $request );
		FlameBuilder::process( $line, 'requests.log', $context );
		$this->promote_pending( $context );

		// Verify all accumulator paths were exercised.
		$this->assertNotEmpty( $context['url_stats'], 'url_stats should be populated' );
		$this->assertNotEmpty( $context['hourly_stats'], 'hourly_stats should be populated' );
		// Leaderboard stats should hold one bucket after promote_pending.
		$this->assertNotEmpty( $context['leaderboard_stats'], 'leaderboard should be populated' );
		$bucket = \reset( $context['leaderboard_stats'] );
		$this->assertSame( 1, $bucket['count'] );

		// Verify flame was cached.
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$agg      = $context['stats_cache']->get( $url_hash );
		$this->assertNotNull( $agg );
		$this->assertSame( 1, $agg['flame']['count'] );
		$this->assertArrayHasKey( 'profiles', $agg );
	}

	// ── zero duration excluded from timing ────────────────────────────────

	public function test_zero_duration_excluded_from_timing(): void {
		$context  = $this->make_context();
		$request  = $this->make_request( [ 'duration_ms' => 0.0, 'status_code' => 200 ] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 0.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		$this->assertSame( 1, $stats['count'] );
		$this->assertSame( 0, $stats['timed_count'], 'Zero duration should not be counted as timed' );
	}

	public function test_cleanup_calls_flush_and_nulls_log(): void {
		$context = $this->make_context();
		FlameBuilder::cleanup( $context );
		$this->assertNull( $context['flames_log'] );
	}

	// ── flush ───────────────────────────────────────────────────────────

	public function test_flush_resets_accumulators(): void {
		$context = $this->make_context();

		// Add some data.
		$request  = $this->make_request( [ 'duration_ms' => 100.0 ] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
		$profiles = [ 'wp_head' => [ 'time' => 50.0, 'count' => 1, 'ts' => \time(), 'entries' => [] ] ];

		$this->accumulate( $url_hash, $flame, $profiles, $request, $context );
		$this->promote_pending( $context );

		// Verify data accumulated.
		$this->assertNotEmpty( $context['hourly_stats'] );
		$this->assertNotEmpty( $context['leaderboard_stats'] );

		// Flush should reset.
		FlameBuilder::flush( $context );

		$this->assertSame( [], $context['leaderboard_stats'] );
		$this->assertSame( [], $context['leaderboard_by_server_stats'] );
		$this->assertSame( [], $context['url_stats'] );
		$this->assertSame( [], $context['hourly_stats'] );
	}

	// ── build_flame — max stack depth ───────────────────────────────────

	public function test_build_flame_max_stack_depth(): void {
		$context = $this->make_context();

		// Build entries that exceed MAX_STACK_DEPTH (50).
		$entries = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$entries[] = [ 'k' => "hook_{$i} (start)", 'l' => '' ];
		}
		// Complete them all in reverse.
		for ( $i = 59; $i >= 0; $i-- ) {
			$entries[] = [ 'k' => "hook_{$i} (complete)", 'duration_ms' => 1, 'ts' => \time() ];
		}

		$flame = $this->build_flame( $entries, $context );
		// Should not crash. Root should have children but not exceed 50 depth.
		$this->assertSame( 'request', $flame['name'] );
		$this->assertNotEmpty( $flame['children'] );
	}

	// ── build_flame — entry with label field 'm' ────────────────────────

	public function test_build_flame_uses_l_field_as_name_label(): void {
		$context = $this->make_context();
		$entries = [
			[ 'k' => 'custom (start)', 'l' => 'my-label' ],
			[ 'k' => 'custom (complete)', 'duration_ms' => 25, 'ts' => \time() ],
		];
		$flame = $this->build_flame( $entries, $context );
		$this->assertSame( 'custom: my-label', $flame['children'][0]['name'] );
	}

	public function test_build_flame_uses_m_field_as_detail(): void {
		$context = $this->make_context();
		$entries = [
			[ 'k' => 'custom (start)', 'm' => 'my-detail' ],
			[ 'k' => 'custom (complete)', 'duration_ms' => 25, 'ts' => \time() ],
		];
		$flame = $this->build_flame( $entries, $context );
		// 'm' field goes to detail, not name. Name stays as base_name when 'l' is empty.
		$this->assertSame( 'custom', $flame['children'][0]['name'] );
		$this->assertSame( 'custom: my-detail', $flame['children'][0]['detail'] );
	}

	// ── number_duplicate_siblings ────────────────────────────────────────

	public function test_number_duplicate_siblings_no_duplicates(): void {
		$root = [
			'name'     => 'request',
			'value'    => 0,
			'children' => [
				[ 'name' => 'a', 'value' => 1, 'children' => [] ],
				[ 'name' => 'b', 'value' => 2, 'children' => [] ],
			],
		];
		self::call_private( 'number_duplicate_siblings', [ &$root ] );
		// No duplicates, names unchanged.
		$this->assertSame( 'a', $root['children'][0]['name'] );
		$this->assertSame( 'b', $root['children'][1]['name'] );
	}

	// ── accumulate — per-URL dimensional stats ──────────────────────────

	public function test_url_dimensional_stats_accumulated(): void {
		$context  = $this->make_context();
		$request  = $this->make_request( [
			'duration_ms'    => 100.0,
			'status_code'    => 200,
			'request_method' => 'GET',
		] );
		$request['status_category'] = '2xx';
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		// Per-URL dim stats should be populated.
		$this->assertArrayHasKey( $url_hash, $context['url_dim_stats'] );
		$this->assertArrayHasKey( 'method', $context['url_dim_stats'][ $url_hash ] );
	}

	// ── accumulate — hub mode per-server dimensional stats ──────────────

	public function test_hub_mode_per_server_dims_accumulated(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/flamebuilder-hub.php' );
		Config::reset();

		$context  = $this->make_context();
		$request  = $this->make_request( [
			'duration_ms'    => 200.0,
			'status_code'    => 200,
			'server_name'    => 'web2.example.com',
			'request_method' => 'POST',
		] );
		$request['status_category'] = '2xx';
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 200.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		// In hub mode, per-server dimensional stats should be populated.
		$this->assertNotEmpty( $context['dim_stats_by_server'] );
		$this->assertArrayHasKey( 'web2.example.com', $context['dim_stats_by_server'] );
	}

	// ── accumulate — hub mode per-server leaderboard ────────────────────

	public function test_hub_mode_per_server_leaderboard(): void {
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/flamebuilder-hub.php' );
		Config::reset();

		$context  = $this->make_context();
		$request  = $this->make_request( [
			'duration_ms' => 150.0,
			'server_name' => 'web3.example.com',
		] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 150.0, 'children' => [] ];
		$profiles = [ 'wp_head' => [ 'time' => 50.0, 'count' => 1, 'ts' => \time(), 'entries' => [] ] ];

		$this->accumulate( $url_hash, $flame, $profiles, $request, $context );

		// Per-server leaderboard now lives in pending until promote.
		$this->assertArrayHasKey( 'web3.example.com', $context['pending']['leaderboard_by_server'] );
		$slb = $context['pending']['leaderboard_by_server']['web3.example.com'];
		$this->assertSame( 1, $slb['count'] );
		$this->assertArrayHasKey( 'wp_head', $slb['categories'] );
	}

	// ── Constants ────────────────────────────────────────────────────────

	public function test_ema_sample_limit(): void {
		$this->assertSame( 1000, FlameBuilder::EMA_SAMPLE_LIMIT );
	}

	public function test_flush_interval(): void {
		$this->assertSame( 5, FlameBuilder::FLUSH_INTERVAL_SEC );
	}

	public function test_pattern_constants(): void {
		$this->assertSame( '/^(.+?) \(start\)$/', FlameBuilder::PATTERN_START );
		$this->assertSame( '/^(.+?) \(complete\)$/', FlameBuilder::PATTERN_COMPLETE );
	}

	public function test_entry_limit_constants(): void {
		$this->assertSame( 40, FlameBuilder::ENTRY_LIMIT_URL_UPPER );
		$this->assertSame( 20, FlameBuilder::ENTRY_LIMIT_URL_LOWER );
		$this->assertSame( 100, FlameBuilder::ENTRY_LIMIT_GLOBAL_UPPER );
		$this->assertSame( 50, FlameBuilder::ENTRY_LIMIT_GLOBAL_LOWER );
	}

	public function test_duration_sample_size_matches_constant(): void {
		// Regression: MAX_DURATIONS_PER_BUCKET was hardcoded to 1000 in earlier versions.
		// It should be 100 to keep memcache values under 1MB (~800 bytes per URL at 100 samples).
		$this->assertSame( 100, StatsStore::MAX_DURATIONS_PER_BUCKET );
	}

	public function test_url_cap_per_bucket(): void {
		// Regression: persist_aggregate_stats caps URLs per bucket at 500.
		// Verify that writing >500 URLs results in at most 500 in stored bucket.
		$this->require_memcached();

		$context = $this->make_context();

		// Accumulate 600 unique URLs into the same bucket.
		$ts = \time();
		for ( $i = 0; $i < 600; $i++ ) {
			$url      = "/url-cap-test/{$i}";
			$url_hash = RequestBuilder::url_hash( $url );
			$request  = $this->make_request( [
				'url'         => $url,
				'duration_ms' => 100.0,
				'timestamp'   => (float) $ts,
				'status_code' => 200,
			] );
			$flame = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
			$this->accumulate( $url_hash, $flame, [], $request, $context );
		}
		$this->promote_pending( $context );

		// Persist to memcache.
		self::call_private( 'persist_aggregate_stats', [ &$context ] );

		// Read back the bucket and verify cap.
		$bucket_key = self::call_private( 'bucket_key', [ $ts ] );
		$url_idx    = StatsStore::get_url_index_hourly( 0, $bucket_key );
		$this->assertNotNull( $url_idx, 'URL index should be written to memcache' );
		$this->assertLessThanOrEqual( 500, \count( $url_idx ), 'URL cap per bucket should be 500' );
	}

	// ── persist_aggregate_stats — hourly stats merge ────────────────────

	public function test_persist_aggregate_stats_merges_hourly(): void {
		$context = $this->make_context();
		// Reinit memcached AFTER make_context (which re-inits StatsStore with empty servers).
		$this->require_memcached();

		// Use current time so bucket key matches regardless of timezone.
		$ts      = \time();
		$request = $this->make_request( [
			'duration_ms' => 100.0,
			'timestamp'   => (float) $ts,
			'peak_mb'     => 10.0,
		] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		self::call_private( 'persist_aggregate_stats', [ &$context ] );

		$hourly = StatsStore::get_hourly( 0 );
		$this->assertNotNull( $hourly, 'Hourly stats should be written to memcache' );
		$this->assertNotEmpty( $hourly, 'Should have at least one bucket' );
		$bucket = \array_values( $hourly )[0];
		$this->assertSame( 1, $bucket['count'] );
	}


	public function test_persist_aggregate_stats_writes_leaderboard(): void {
		$this->require_memcached();

		$context  = $this->make_context();
		$ts       = \time();
		$request  = $this->make_request( [ 'duration_ms' => 200.0, 'timestamp' => (float) $ts ] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 200.0, 'children' => [] ];
		$profiles = [
			'wp_head' => [ 'time' => 50.0, 'count' => 3, 'ts' => \time(), 'entries' => [] ],
		];
		$this->accumulate( $url_hash, $flame, $profiles, $request, $context );
		$this->promote_pending( $context );

		self::call_private( 'persist_aggregate_stats', [ &$context ] );

		$bk = self::call_private( 'bucket_key', [ $ts ] );
		$lb = StatsStore::get_leaderboard_bucket( 0, $bk );
		$this->assertNotNull( $lb );
		$this->assertSame( 1, $lb['count'] );
		$this->assertArrayHasKey( 'wp_head', $lb['categories'] );
		// Stored as sums: sum_time = 50 (one request at 50).
		$this->assertEqualsWithDelta( 50.0, $lb['categories']['wp_head']['sum_time'], 0.01 );
		$this->assertSame( 1, $lb['categories']['wp_head']['samples'] );
	}

	public function test_persist_aggregate_stats_merges_leaderboard_categories(): void {
		$this->require_memcached();

		$context = $this->make_context();
		$ts      = \time();
		$bk      = self::call_private( 'bucket_key', [ $ts ] );

		// Pre-populate leaderboard bucket in memcache (sums-based).
		$existing_lb = [
			'count'        => 5,
			'sum_req_time' => 200.0,
			'categories'   => [
				'wp_head' => [
					'samples'   => 5,
					'sum_time'  => 200.0, // 40 avg * 5 requests
					'sum_count' => 10.0,  // 2 avg * 5 requests
					'entries'   => [],
				],
			],
		];
		StatsStore::set_leaderboard_bucket( 0, $bk, $existing_lb );

		// Accumulate data with a wp_head profile to merge.
		$request  = $this->make_request( [ 'duration_ms' => 150.0, 'timestamp' => (float) $ts ] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 150.0, 'children' => [] ];
		$profiles = [
			'wp_head' => [ 'time' => 80.0, 'count' => 4, 'ts' => \time(), 'entries' => [] ],
		];
		$this->accumulate( $url_hash, $flame, $profiles, $request, $context );
		$this->promote_pending( $context );

		self::call_private( 'persist_aggregate_stats', [ &$context ] );

		$lb = StatsStore::get_leaderboard_bucket( 0, $bk );
		$this->assertNotNull( $lb );
		// Existing count=5 + new count=1 = 6 total.
		$this->assertSame( 6, $lb['count'] );
		// wp_head samples = 5 + 1 = 6.
		$this->assertSame( 6, $lb['categories']['wp_head']['samples'] );
		// wp_head sum_time = 200 + 80 = 280.
		$this->assertEqualsWithDelta( 280.0, $lb['categories']['wp_head']['sum_time'], 0.01 );
	}

	public function test_persist_aggregate_stats_writes_url_index(): void {
		$this->require_memcached();

		$context = $this->make_context();

		$request  = $this->make_request( [
			'url'         => '/persist-url-test',
			'duration_ms' => 300.0,
			'status_code' => 200,
		] );
		$url_hash = RequestBuilder::url_hash( '/persist-url-test' );
		$flame    = [ 'name' => 'request', 'value' => 300.0, 'children' => [] ];
		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		self::call_private( 'persist_aggregate_stats', [ &$context ] );

		// Verify URL stats were written. Check the bucket from the request timestamp.
		$ts       = $request['timestamp'];
		$hour_key = self::call_private( 'bucket_key', [ $ts ] );
		$url_idx  = StatsStore::get_url_index_hourly( 0, $hour_key );
		$this->assertNotNull( $url_idx );
		$this->assertArrayHasKey( $url_hash, $url_idx );
		$this->assertSame( 1, $url_idx[ $url_hash ]['count'] );
	}

	public function test_persist_aggregate_stats_skips_when_empty(): void {
		$this->require_memcached();

		$context = $this->make_context();

		// No data accumulated — persist should be a no-op.
		self::call_private( 'persist_aggregate_stats', [ &$context ] );

		// Verify nothing was written.
		$hourly = StatsStore::get_hourly( 0 );
		// Should be null or whatever was there before (nothing).
		$this->assertNull( $hourly );
	}

	public function test_persist_aggregate_stats_writes_dimensional(): void {
		$this->require_memcached();

		$context  = $this->make_context();
		$request  = $this->make_request( [
			'duration_ms'    => 100.0,
			'status_code'    => 200,
			'request_method' => 'POST',
		] );
		$request['status_category'] = '2xx';
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		self::call_private( 'persist_aggregate_stats', [ &$context ] );

		// Verify dimensional stats for 'method' dimension.
		$dim_data = StatsStore::get_dimensional( 0, 'method' );
		$this->assertNotNull( $dim_data );
		$bucket = \reset( $dim_data );
		$this->assertArrayHasKey( 'POST', $bucket );
		$this->assertSame( 1, $bucket['POST']['c'] );
	}

	public function test_persist_aggregate_stats_writes_url_dimensional(): void {
		$this->require_memcached();

		$context  = $this->make_context();
		$request  = $this->make_request( [
			'duration_ms'    => 100.0,
			'status_code'    => 200,
			'request_method' => 'GET',
		] );
		$request['status_category'] = '2xx';
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		self::call_private( 'persist_aggregate_stats', [ &$context ] );

		// Verify per-URL dimensional stats.
		$url_dim = StatsStore::get_url_dimensional( 0, $url_hash );
		$this->assertNotNull( $url_dim );
		$this->assertArrayHasKey( 'method', $url_dim );
	}

	// ── persist_aggregate_stats — hub mode server leaderboard ───────────

	public function test_persist_aggregate_stats_hub_mode_server_leaderboard(): void {
		// Set hub config BEFORE make_context so FlameBuilder sees aggregator_servers.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/configs/flamebuilder-hub.php' );
		Config::reset();

		$context  = $this->make_context();

		// Require memcached AFTER make_context so StatsStore gets valid servers
		// (FlameBuilder::init re-inits StatsStore with hub config's empty servers).
		$this->require_memcached();

		$ts       = \time();
		$request  = $this->make_request( [
			'duration_ms' => 200.0,
			'server_name' => 'web1.example.com',
			'timestamp'   => (float) $ts,
		] );
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 200.0, 'children' => [] ];
		$profiles = [
			'wp_head' => [ 'time' => 80.0, 'count' => 2, 'ts' => \time(), 'entries' => [] ],
		];
		$this->accumulate( $url_hash, $flame, $profiles, $request, $context );
		$this->promote_pending( $context );

		self::call_private( 'persist_aggregate_stats', [ &$context ] );

		$bk  = self::call_private( 'bucket_key', [ $ts ] );
		$slb = StatsStore::get_server_leaderboard_bucket( 0, 'web1.example.com', $bk );
		$this->assertNotNull( $slb );
		$this->assertArrayHasKey( 'wp_head', $slb['categories'] );
		$this->assertSame( 1, $slb['count'] );
	}

	// ── accumulate — profile entries accumulated as sums ────────────────

	public function test_profile_entries_accumulated_as_sums(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/entry-test' );

		$profiles1 = [
			'the_content' => [
				'time'    => 100.0,
				'count'   => 1,
				'ts'      => \time(),
				'entries' => [
					'do_blocks' => [ 20.0, 1 ],
				],
			],
		];
		$profiles2 = [
			'the_content' => [
				'time'    => 80.0,
				'count'   => 1,
				'ts'      => \time(),
				'entries' => [
					'do_blocks' => [ 40.0, 1 ],
				],
			],
		];

		$request1 = $this->make_request( [ 'url' => '/entry-test', 'duration_ms' => 100.0 ] );
		$flame1   = [ 'name' => 'request', 'value' => 100.0, 'children' => [] ];
		$this->accumulate( $url_hash, $flame1, $profiles1, $request1, $context );

		$request2 = $this->make_request( [ 'url' => '/entry-test', 'duration_ms' => 80.0 ] );
		$flame2   = [ 'name' => 'request', 'value' => 80.0, 'children' => [] ];
		$this->accumulate( $url_hash, $flame2, $profiles2, $request2, $context );

		// Per-URL profile entries store sums, not running means.
		$agg = $context['stats_cache']->get( $url_hash );
		$this->assertArrayHasKey( 'do_blocks', $agg['profiles']['categories']['the_content']['entries'] );
		$entry = $agg['profiles']['categories']['the_content']['entries']['do_blocks'];
		$this->assertSame( 2, $entry[2] ); // samples = 2.
		// Sum: 20 + 40 = 60. (Display-time conversion divides by samples to get avg = 30.)
		$this->assertEqualsWithDelta( 60.0, $entry[0], 0.01 );

		// Pending leaderboard entries (also sums).
		$lb = $context['pending']['leaderboard'];
		$this->assertArrayHasKey( 'do_blocks', $lb['categories']['the_content']['entries'] );
		$lentry = $lb['categories']['the_content']['entries']['do_blocks'];
		$this->assertSame( 2, $lentry[2] );
		$this->assertEqualsWithDelta( 60.0, $lentry[0], 0.01 );
	}

	// ── accumulate — reservoir sampling of durations ─────────────────────

	public function test_durations_reservoir_sampling(): void {
		$context  = $this->make_context();
		$url_hash = RequestBuilder::url_hash( '/reservoir-test' );

		// Accumulate more than 1000 requests to trigger reservoir sampling.
		for ( $i = 0; $i < 1005; $i++ ) {
			$request = $this->make_request( [
				'url'         => '/reservoir-test',
				'duration_ms' => (float) ( $i + 1 ),
			] );
			$flame = [ 'name' => 'request', 'value' => (float) ( $i + 1 ), 'children' => [] ];
			$this->accumulate( $url_hash, $flame, [], $request, $context );
		}
		$this->promote_pending( $context );

		$hour_data = \reset( $context['url_stats'] );
		$stats     = $hour_data[ $url_hash ];
		// Durations should be capped at 1000.
		$this->assertLessThanOrEqual( 1000, \count( $stats['durations'] ) );
		$this->assertSame( 1005, $stats['count'] );
	}

	// ── worker exclusion from hourly count AND dimensional stats ────────

	public function test_worker_requests_excluded_from_hourly_count_and_dim_sum(): void {
		$context  = $this->make_context();
		$request  = $this->make_request( [
			'duration_ms'    => 5000.0,
			'is_worker'      => true,
			'status_code'    => 200,
			'request_method' => 'POST',
			'server_name'    => 'worker-host',
		] );
		$request['status_category'] = '2xx';
		$url_hash = RequestBuilder::url_hash( $request['url'] );
		$flame    = [ 'name' => 'request', 'value' => 5000.0, 'children' => [] ];

		$this->accumulate( $url_hash, $flame, [], $request, $context );
		$this->promote_pending( $context );

		// Hourly stats: count should be 0 (workers excluded from timing).
		$hour_data = \reset( $context['hourly_stats'] );
		$this->assertSame( 0, $hour_data['count'], 'Worker requests should be excluded from hourly count' );

		// Dimensional stats: entries exist (count incremented) but sum (s) should be 0.
		if ( ! empty( $context['dim_stats']['method'] ) ) {
			$method_data = \reset( $context['dim_stats']['method'] );
			$this->assertArrayHasKey( 'POST', $method_data );
			$this->assertEqualsWithDelta( 0.0, $method_data['POST']['s'], 0.01, 'Worker dim_stats sum should be 0' );
		}
	}

	/**
	 * Helper: require memcached for persist tests.
	 */
	private function require_memcached(): void {
		if ( ! \class_exists( '\Memcached' ) && ! \class_exists( '\Memcache' ) ) {
			$this->markTestSkipped( 'Neither Memcached nor Memcache PHP extension is available' );
		}

		// Use the base test config for memcache servers (flamebuilder config has empty servers).
		$orig_env = \getenv( 'LOCAL_EVENT_LOGGER_CONF' );
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		Config::reset();
		$config  = Config::load_config( 'full' );
		$servers = ! empty( $config['memcache_servers'] ) ? $config['memcache_servers'] : \Newspack_Event_Logger\Memcached::DEFAULT_SERVERS;

		// Restore original config for flamebuilder tests.
		if ( $orig_env ) {
			\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . $orig_env );
		}
		Config::reset();

		// Reset Memcached statics before init.
		$memd_ref = new \ReflectionClass( \Newspack_Event_Logger\Memcached::class );
		$memd_prop = $memd_ref->getProperty( 'memd' );
		$memd_prop->setAccessible( true );
		$memd_prop->setValue( null, null );
		$init_prop = $memd_ref->getProperty( 'init_attempted' );
		$init_prop->setAccessible( true );
		$init_prop->setValue( null, false );

		StatsStore::init( 1, $servers, 86400 );

		if ( ! \Newspack_Event_Logger\Memcached::is_available() ) {
			$this->markTestSkipped( 'Memcached server not available' );
		}

		// Verify memcached is responding.
		$test_key = 'flame_test_ping_' . \uniqid();
		$set_ok   = \Newspack_Event_Logger\Memcached::set( $test_key, 'pong', 5 );
		if ( ! $set_ok ) {
			$this->markTestSkipped( 'Memcached server not responding' );
		}
		\Newspack_Event_Logger\Memcached::delete( $test_key );

		// Rotate salt to isolate test data.
		\update_option( StatsStore::SALT_OPTION, \uniqid( 'flame_test_' ), true );
		$ref = new \ReflectionClass( StatsStore::class );
		$prefix = $ref->getProperty( 'prefix' );
		$prefix->setAccessible( true );
		$prefix->setValue( null, 'evlog' );
		StatsStore::init( 1, $servers, 86400 );
	}
}
