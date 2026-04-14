<?php
/**
 * Tests for Newspack_Event_Dashboards\REST\WorkersController.
 *
 * @package Newspack_Event_Dashboards
 */

use Newspack_Event_Dashboards\REST\WorkersController;
use Newspack_Event_Logger\Config;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( WorkersController::class )]
class WorkersControllerTest extends \PHPUnit\Framework\TestCase {

	private WorkersController $controller;

	/** @var string */
	private string $base_dir;

	protected function setUp(): void {
		parent::setUp();
		// Restore test config env var — other tests may have unset or mutated it.
		\putenv( 'LOCAL_EVENT_LOGGER_CONF=' . \dirname( __DIR__ ) . '/event-logger-test-config.php' );
		Config::reset();

		// Use the test config's base_directory (/tmp/event-logger-test).
		$config         = Config::load_config();
		$this->base_dir = $config['base_directory'];
		@mkdir( $this->base_dir . '/logs', 0755, true );
		@mkdir( $this->base_dir . '/locks', 0755, true );
		@mkdir( $this->base_dir . '/offsets', 0755, true );

		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 1;
		$GLOBALS['_wp_test_nonce_valid'] = true;
		$GLOBALS['_wp_test_registered_routes'] = [];

		Config::reset();
		$this->controller = new WorkersController();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_options']    = [];
		$GLOBALS['_wp_test_transients'] = [];
		$GLOBALS['_wp_test_user_id']    = 0;
		$GLOBALS['_wp_test_nonce_valid'] = true;
		$GLOBALS['_wp_test_registered_routes'] = [];
		$this->rmdir_recursive( $this->base_dir );
		Config::reset();
		parent::tearDown();
	}

	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rmdir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	public function test_register_routes(): void {
		$this->controller->register_routes();
		$routes = $GLOBALS['_wp_test_registered_routes'];
		$this->assertGreaterThanOrEqual( 2, count( $routes ) );
	}

	public function test_read_permissions_check_allowed(): void {
		$result = $this->controller->read_permissions_check();
		$this->assertTrue( $result );
	}

	public function test_validate_restart_type_all(): void {
		$this->assertTrue( $this->controller->validate_restart_type( 'all' ) );
	}

	public function test_validate_restart_type_supervisor(): void {
		$this->assertTrue( $this->controller->validate_restart_type( 'supervisor' ) );
	}

	public function test_validate_restart_type_unknown(): void {
		$this->assertFalse( $this->controller->validate_restart_type( 'totally_unknown' ) );
	}

	public function test_restart_permissions_check_valid_nonce(): void {
		$GLOBALS['_wp_test_nonce_valid'] = true;
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'nonce', 'valid_nonce' );

		$result = $this->controller->restart_permissions_check( $request );
		$this->assertTrue( $result );
	}

	public function test_restart_permissions_check_invalid_nonce(): void {
		$GLOBALS['_wp_test_nonce_valid'] = false;
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'nonce', 'bad_nonce' );

		$result = $this->controller->restart_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_restart_permissions_check_empty_nonce(): void {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'nonce', '' );

		$result = $this->controller->restart_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_rate_limit_blocks_rapid_restarts(): void {
		$GLOBALS['_wp_test_nonce_valid'] = true;

		// First restart request should succeed.
		$request1 = new WP_REST_Request( 'POST' );
		$request1->set_param( 'nonce', 'nonce1' );
		$result1 = $this->controller->restart_permissions_check( $request1 );
		$this->assertTrue( $result1 );

		// Second should be rate limited.
		$request2 = new WP_REST_Request( 'POST' );
		$request2->set_param( 'nonce', 'nonce2' );
		$result2 = $this->controller->restart_permissions_check( $request2 );
		$this->assertInstanceOf( WP_Error::class, $result2 );
		$this->assertSame( 'rate_limited', $result2->get_error_code() );
	}

	public function test_restart_workers_invalid_partition(): void {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'type', 'all' );
		$request->set_param( 'partition', 999 );
		$request->set_param( 'all_partitions', false );

		$response = $this->controller->restart_workers( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_partition', $response->get_error_code() );
	}

	public function test_get_workers_returns_structure(): void {
		$response = $this->controller->get_workers();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'workers', $data );
		$this->assertArrayHasKey( 'standalone', $data );
		$this->assertArrayHasKey( 'logs', $data );
		$this->assertArrayHasKey( 'num_partitions', $data );
		$this->assertArrayHasKey( 'num_segments', $data );
		$this->assertArrayHasKey( 'segment_size', $data );
		$this->assertArrayHasKey( 'timestamp', $data );
		$this->assertIsInt( $data['timestamp'] );
	}

	// ── scan_segments via reflection ────────────────────────────────────

	public function test_scan_segments_empty_dir(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'scan_segments' );
		$ref->setAccessible( true );

		$empty_dir = $this->base_dir . '/logs/empty-test';
		@mkdir( $empty_dir, 0755, true );

		$result = $ref->invoke( $this->controller, $empty_dir );
		$this->assertSame( [], $result['segments'] );
		$this->assertSame( 0, $result['total_size'] );
	}

	public function test_scan_segments_nonexistent_dir(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'scan_segments' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, '/nonexistent/path/to/dir' );
		$this->assertSame( [], $result['segments'] );
		$this->assertSame( 0, $result['total_size'] );
	}

	public function test_scan_segments_with_files(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'scan_segments' );
		$ref->setAccessible( true );

		$seg_dir = $this->base_dir . '/logs/scan-test';
		@mkdir( $seg_dir, 0755, true );

		// Create segment files.
		file_put_contents( $seg_dir . '/0.log', str_repeat( 'a', 100 ) );
		file_put_contents( $seg_dir . '/1.log', str_repeat( 'b', 200 ) );
		file_put_contents( $seg_dir . '/2.log', str_repeat( 'c', 50 ) );
		// Non-matching file should be ignored.
		file_put_contents( $seg_dir . '/metadata.json', '{}' );

		$result = $ref->invoke( $this->controller, $seg_dir );
		$this->assertCount( 3, $result['segments'] );
		$this->assertSame( 350, $result['total_size'] );

		// Should be sorted by ID.
		$this->assertSame( 0, $result['segments'][0]['id'] );
		$this->assertSame( 1, $result['segments'][1]['id'] );
		$this->assertSame( 2, $result['segments'][2]['id'] );

		// Size should be correct.
		$this->assertSame( 100, $result['segments'][0]['size'] );
		$this->assertSame( 200, $result['segments'][1]['size'] );
	}

	public function test_scan_segments_skips_symlinks(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'scan_segments' );
		$ref->setAccessible( true );

		$seg_dir = $this->base_dir . '/logs/symlink-test';
		@mkdir( $seg_dir, 0755, true );

		// Create a real segment.
		file_put_contents( $seg_dir . '/0.log', 'real data' );

		// Create a symlink that looks like a segment.
		$target = $this->base_dir . '/logs/target.log';
		file_put_contents( $target, 'symlink target' );
		@symlink( $target, $seg_dir . '/1.log' );

		$result = $ref->invoke( $this->controller, $seg_dir );
		// Should only include the real file, not the symlink.
		$this->assertCount( 1, $result['segments'] );
		$this->assertSame( 0, $result['segments'][0]['id'] );
	}

	// ── get_worker_status via reflection ────────────────────────────────

	public function test_get_worker_status_dead_worker(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'get_worker_status' );
		$ref->setAccessible( true );

		// Create a segment directory with a file.
		$log_dir = $this->base_dir . '/logs/firehose.log/p0';
		@mkdir( $log_dir, 0755, true );
		file_put_contents( $log_dir . '/0.log', str_repeat( 'x', 100 ) );

		$firehose = new \Newspack_Event_Logger\Firehose( $this->base_dir . '/logs/firehose.log', 0 );

		// No heartbeat file = dead worker.
		$heartbeat_path = $this->base_dir . '/locks/test-worker.p0.lock.d/heartbeat';

		$result = $ref->invoke(
			$this->controller,
			'test-worker',
			0,
			'firehose.log',
			'requests.log',
			$firehose,
			$heartbeat_path,
			time(),
			60
		);

		$this->assertSame( 'dead', $result['status'] );
		$this->assertSame( 'test-worker', $result['type'] );
		$this->assertSame( 0, $result['partition'] );
		$this->assertSame( 'firehose.log', $result['input_log'] );
		$this->assertSame( 'requests.log', $result['output_log'] );
		$this->assertNull( $result['heartbeat_age'] );
	}

	public function test_get_worker_status_running_worker(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'get_worker_status' );
		$ref->setAccessible( true );

		$log_dir = $this->base_dir . '/logs/firehose.log/p0';
		@mkdir( $log_dir, 0755, true );
		file_put_contents( $log_dir . '/0.log', str_repeat( 'x', 500 ) );

		$firehose = new \Newspack_Event_Logger\Firehose( $this->base_dir . '/logs/firehose.log', 0 );

		// Create heartbeat file (recently touched).
		$lock_dir = $this->base_dir . '/locks/test-worker.p0.lock.d';
		@mkdir( $lock_dir, 0755, true );
		$heartbeat_path = $lock_dir . '/heartbeat';
		touch( $heartbeat_path ); // mtime = now.

		$result = $ref->invoke(
			$this->controller,
			'test-worker',
			0,
			'firehose.log',
			null,
			$firehose,
			$heartbeat_path,
			time(),
			60
		);

		$this->assertSame( 'running', $result['status'] );
		$this->assertNotNull( $result['heartbeat_age'] );
		$this->assertLessThanOrEqual( 2, $result['heartbeat_age'] );
		$this->assertNull( $result['output_log'] );
	}

	// ── restart_workers: all type ───────────────────────────────────────

	public function test_restart_workers_all_type(): void {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'type', 'all' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'all_partitions', false );

		$response = $this->controller->restart_workers( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['results'] );
	}

	// ── restart_workers: supervisor type ─────────────────────────────────

	public function test_restart_workers_supervisor(): void {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'type', 'supervisor' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'all_partitions', false );

		$response = $this->controller->restart_workers( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		// Should have one result for supervisor.
		$this->assertGreaterThanOrEqual( 1, count( $data['results'] ) );
		$found_supervisor = false;
		foreach ( $data['results'] as $r ) {
			if ( 'supervisor' === $r['type'] ) {
				$found_supervisor = true;
				$this->assertNull( $r['partition'] );
			}
		}
		$this->assertTrue( $found_supervisor, 'Should have supervisor in results' );
	}

	// ── restart_workers: all_partitions flag ─────────────────────────────

	public function test_restart_workers_all_partitions(): void {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'type', 'all' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'all_partitions', true );

		$response = $this->controller->restart_workers( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	// ── validate_worker_type: unknown ───────────────────────────────────

	public function test_validate_worker_type_unknown(): void {
		$this->assertFalse( $this->controller->validate_worker_type( 'not_a_real_worker' ) );
	}

	// ── check_rate_limit via reflection ─────────────────────────────────

	public function test_check_rate_limit_first_call(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'check_rate_limit' );
		$ref->setAccessible( true );

		$GLOBALS['_wp_test_transients'] = [];
		$result = $ref->invoke( $this->controller );
		$this->assertTrue( $result );
	}

	public function test_check_rate_limit_rapid_calls(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'check_rate_limit' );
		$ref->setAccessible( true );

		$GLOBALS['_wp_test_transients'] = [];

		// First call succeeds.
		$result1 = $ref->invoke( $this->controller );
		$this->assertTrue( $result1 );

		// Second call within 2 seconds blocked.
		$result2 = $ref->invoke( $this->controller );
		$this->assertInstanceOf( WP_Error::class, $result2 );
		$this->assertSame( 'rate_limited', $result2->get_error_code() );
	}

	// ── get_workers: standalone workers in response ─────────────────────

	public function test_get_workers_includes_standalone(): void {
		$response = $this->controller->get_workers();
		$data     = $response->get_data();

		$this->assertIsArray( $data['standalone'] );
		// Should always include supervisor.
		$types = array_column( $data['standalone'], 'type' );
		$this->assertContains( 'supervisor', $types );
	}

	// ── get_standalone_status via reflection ─────────────────────────────

	public function test_get_standalone_status_running(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'get_standalone_status' );
		$ref->setAccessible( true );

		$lock_dir = $this->base_dir . '/locks/test-standalone.lock.d';
		@mkdir( $lock_dir, 0755, true );
		touch( $lock_dir . '/heartbeat' );

		$result = $ref->invoke( $this->controller, 'test-standalone', null, $lock_dir, time() );

		$this->assertSame( 'running', $result['status'] );
		$this->assertSame( 'test-standalone', $result['type'] );
		$this->assertNull( $result['partition'] );
	}

	public function test_get_standalone_status_dead(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'get_standalone_status' );
		$ref->setAccessible( true );

		$lock_dir = $this->base_dir . '/locks/dead-standalone.lock.d';
		// No lock dir = dead.

		$result = $ref->invoke( $this->controller, 'dead-standalone', 0, $lock_dir, time() );

		$this->assertSame( 'dead', $result['status'] );
		$this->assertSame( 0, $result['partition'] );
		$this->assertNull( $result['heartbeat_age'] );
	}

	// ── restart_workers: negative partition ──────────────────────────────

	public function test_restart_workers_negative_partition(): void {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'type', 'all' );
		$request->set_param( 'partition', -1 );
		$request->set_param( 'all_partitions', false );

		$response = $this->controller->restart_workers( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_partition', $response->get_error_code() );
	}

	// ── restart_workers: standalone partitioned worker ──────────────────

	public function test_restart_workers_standalone_partitioned(): void {
		// Register a standalone partitioned worker via filter.
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'test-standalone' => [
					'class'      => \Newspack_Event_Logger\Tests\Unit\ConcreteTestWorker::class,
					'partitions' => true,
				],
			];
		} );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'type', 'test-standalone' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'all_partitions', false );

		$response = $this->controller->restart_workers( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertGreaterThanOrEqual( 1, count( $data['results'] ) );
		$this->assertSame( 'test-standalone', $data['results'][0]['type'] );
		$this->assertSame( 0, $data['results'][0]['partition'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── restart_workers: standalone single-instance worker ──────────────

	public function test_restart_workers_standalone_single_instance(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'single-standalone' => [
					'class'      => \Newspack_Event_Logger\Tests\Unit\ConcreteTestWorker::class,
					'partitions' => false,
				],
			];
		} );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'type', 'single-standalone' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'all_partitions', false );

		$response = $this->controller->restart_workers( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$found = false;
		foreach ( $data['results'] as $r ) {
			if ( 'single-standalone' === $r['type'] ) {
				$found = true;
				$this->assertNull( $r['partition'] );
			}
		}
		$this->assertTrue( $found );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── restart_workers: all_partitions with all type ────────────────────

	public function test_restart_workers_all_partitions_generates_results(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['test-reader-group'] = [
				'test-handler' => [
					'class'  => \Newspack_Event_Logger\Tests\Unit\MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'type', 'all' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'all_partitions', true );

		$response = $this->controller->restart_workers( $request );
		$data     = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['results'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── restart_workers: specific reader type ───────────────────────────

	public function test_restart_workers_specific_reader_type(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['specific-reader'] = [
				'handler' => [
					'class'  => \Newspack_Event_Logger\Tests\Unit\MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'type', 'specific-reader' );
		$request->set_param( 'partition', 0 );
		$request->set_param( 'all_partitions', false );

		$response = $this->controller->restart_workers( $request );
		$data     = $response->get_data();
		$this->assertTrue( $data['success'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── validate_restart_type: known standalone worker ──────────────────

	public function test_validate_restart_type_known_standalone(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'known-standalone' => [
					'class'      => \Newspack_Event_Logger\Tests\Unit\ConcreteTestWorker::class,
					'partitions' => false,
				],
			];
		} );

		$this->assertTrue( $this->controller->validate_restart_type( 'known-standalone' ) );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── validate_worker_type: known reader ──────────────────────────────

	public function test_validate_worker_type_known_reader(): void {
		\add_filter( 'newspack_event_logger_log_readers', function ( $readers ) {
			$readers['known-reader'] = [
				'handler' => [
					'class'  => \Newspack_Event_Logger\Tests\Unit\MockLogHandler::class,
					'inputs' => [ 'firehose.log' ],
				],
			];
			return $readers;
		} );

		$this->assertTrue( $this->controller->validate_worker_type( 'known-reader' ) );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── get_worker_status: stale heartbeat ──────────────────────────────

	public function test_get_worker_status_stale_heartbeat(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'get_worker_status' );
		$ref->setAccessible( true );

		$log_dir = $this->base_dir . '/logs/firehose.log/p0';
		@mkdir( $log_dir, 0755, true );
		file_put_contents( $log_dir . '/0.log', str_repeat( 'x', 100 ) );

		$firehose = new \Newspack_Event_Logger\Firehose( $this->base_dir . '/logs/firehose.log', 0 );

		// Create heartbeat file with old timestamp.
		$lock_dir = $this->base_dir . '/locks/stale-worker.p0.lock.d';
		@mkdir( $lock_dir, 0755, true );
		$heartbeat_path = $lock_dir . '/heartbeat';
		touch( $heartbeat_path, time() - 120 ); // 120 seconds ago.
		clearstatcache( true, $heartbeat_path );

		$result = $ref->invoke(
			$this->controller,
			'stale-worker',
			0,
			'firehose.log',
			null,
			$firehose,
			$heartbeat_path,
			time(),
			60 // 60 second stale timeout, heartbeat is 120s old.
		);

		$this->assertSame( 'dead', $result['status'] );
		$this->assertGreaterThan( 60, $result['heartbeat_age'] );
	}

	// ── get_log_segments via reflection ─────────────────────────────────

	public function test_get_log_segments(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'get_log_segments' );
		$ref->setAccessible( true );

		$seg_dir = $this->base_dir . '/logs/test-log/p0';
		@mkdir( $seg_dir, 0755, true );
		file_put_contents( $seg_dir . '/0.log', str_repeat( 'a', 100 ) );
		file_put_contents( $seg_dir . '/1.log', str_repeat( 'b', 200 ) );

		$result = $ref->invoke( $this->controller, 'test-log', 0, $seg_dir );
		$this->assertSame( 'test-log', $result['name'] );
		$this->assertSame( 0, $result['partition'] );
		$this->assertSame( 300, $result['total_size'] );
		$this->assertCount( 2, $result['segments'] );
	}

	// ── rate limit: elapsed >= 2 allows through ─────────────────────────

	public function test_check_rate_limit_elapsed_allows(): void {
		$ref = new \ReflectionMethod( WorkersController::class, 'check_rate_limit' );
		$ref->setAccessible( true );

		$GLOBALS['_wp_test_transients'] = [];

		// Set transient to 5 seconds ago (elapsed >= 2).
		$transient_key = 'event_logger_rate_limit_1';
		$GLOBALS['_wp_test_transients'][ $transient_key ] = time() - 5;

		$result = $ref->invoke( $this->controller );
		$this->assertTrue( $result );
	}

	// ── get_standalone_workers_status via reflection ─────────────────────

	public function test_get_standalone_workers_status_with_standalone(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'test-merger' => [
					'class'      => \Newspack_Event_Logger\Tests\Unit\ConcreteTestWorker::class,
					'partitions' => false,
				],
			];
		} );

		$ref = new \ReflectionMethod( WorkersController::class, 'get_standalone_workers_status' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, time() );
		$this->assertIsArray( $result );

		// Should include supervisor + the test-merger.
		$types = array_column( $result, 'type' );
		$this->assertContains( 'supervisor', $types );
		$this->assertContains( 'test-merger', $types );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}

	// ── get_standalone_workers_status: partitioned standalone ────────────

	public function test_get_standalone_workers_status_partitioned(): void {
		\add_filter( 'newspack_event_logger_standalone_workers', function () {
			return [
				'partitioned-worker' => [
					'class'      => \Newspack_Event_Logger\Tests\Unit\ConcreteTestWorker::class,
					'partitions' => true,
				],
			];
		} );

		$ref = new \ReflectionMethod( WorkersController::class, 'get_standalone_workers_status' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $this->controller, time() );

		// Find entries for the partitioned worker.
		$partitioned = array_filter( $result, fn( $r ) => 'partitioned-worker' === $r['type'] );
		$this->assertGreaterThanOrEqual( 1, count( $partitioned ) );
		// First one should have partition 0.
		$first = array_values( $partitioned )[0];
		$this->assertSame( 0, $first['partition'] );

		unset( $GLOBALS['_wp_test_filters']['newspack_event_logger_standalone_workers'], $GLOBALS['_wp_test_filters']['newspack_event_logger_log_readers'] );
	}
}
