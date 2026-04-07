<?php
/**
 * Tests for JobWorker (job dispatch and execution).
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger\Config;
use Newspack_Event_Jobs\Cron\JobWorker;

#[\PHPUnit\Framework\Attributes\CoversClass( JobWorker::class )]
class JobWorkerTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-test-jobworker';

	/** @var array Original $_SERVER backup. */
	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$this->orig_server = $_SERVER;
		$_SERVER['REQUEST_URI']    = '/test';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SERVER_NAME']    = 'localhost';
		$_SERVER['HTTP_HOST']      = 'localhost';
		unset( $_SERVER['UNIQUE_ID'], $_SERVER['HTTP_X_A8C_REQUEST_ID'] );

		self::rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
	}

	protected function tearDown(): void {
		$_SERVER = $this->orig_server;
		self::rmdir_recursive( self::TEST_DIR );
		Config::reset();
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

	public function test_get_registered_handlers_returns_array(): void {
		// With stubs, apply_filters returns the default (empty array).
		$handlers = JobWorker::get_registered_handlers();
		$this->assertIsArray( $handlers );
		$this->assertEmpty( $handlers );
	}

	public function test_get_registered_remote_handlers_returns_array(): void {
		$handlers = JobWorker::get_registered_remote_handlers();
		$this->assertIsArray( $handlers );
		$this->assertEmpty( $handlers );
	}

	public function test_init_sets_context(): void {
		$context = [];
		JobWorker::init( $context, null );

		$this->assertArrayHasKey( 'handlers', $context );
		$this->assertArrayHasKey( 'remote_handlers', $context );
		$this->assertArrayHasKey( 'jobs_since_cache_flush', $context );
		$this->assertSame( 0, $context['jobs_since_cache_flush'] );
	}

	public function test_init_with_saved_state(): void {
		$context = [];
		JobWorker::init( $context, [ 'some' => 'state' ] );

		// init doesn't restore state (stateless worker), just sets handlers.
		$this->assertArrayHasKey( 'handlers', $context );
	}

	public function test_process_valid_json(): void {
		$context = [
			'handlers'               => [
				'test_handler' => function ( array $params ) {
					// Track that we were called.
					$GLOBALS['_test_handler_called'] = $params;
				},
			],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		$job_line = \wp_json_encode( [
			'handler'    => 'test_handler',
			'parameters' => [ 'key' => 'value' ],
		] );

		$GLOBALS['_test_handler_called'] = null;
		JobWorker::process( $job_line, 'jobs.log', $context );

		$this->assertNotNull( $GLOBALS['_test_handler_called'] );
		$this->assertSame( 'value', $GLOBALS['_test_handler_called']['key'] );
		$this->assertSame( 1, $context['jobs_since_cache_flush'] );

		unset( $GLOBALS['_test_handler_called'] );
	}

	public function test_process_invalid_json_silently_skipped(): void {
		$context = [
			'handlers'               => [],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		// Invalid JSON.
		JobWorker::process( 'not-json{{{', 'jobs.log', $context );
		$this->assertSame( 0, $context['jobs_since_cache_flush'] );
	}

	public function test_process_empty_handler_silently_skipped(): void {
		$context = [
			'handlers'               => [],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		$job_line = \wp_json_encode( [
			'handler'    => '',
			'parameters' => [],
		] );
		JobWorker::process( $job_line, 'jobs.log', $context );
		$this->assertSame( 1, $context['jobs_since_cache_flush'] );
	}

	public function test_process_unregistered_handler_silently_skipped(): void {
		$called  = false;
		$context = [
			'handlers'               => [
				'known_handler' => function () use ( &$called ) {
					$called = true;
				},
			],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		$job_line = \wp_json_encode( [
			'handler'    => 'unknown_handler',
			'parameters' => [],
		] );
		JobWorker::process( $job_line, 'jobs.log', $context );
		$this->assertFalse( $called );
	}

	public function test_handler_name_validation_rejects_special_chars(): void {
		$called  = false;
		$context = [
			'handlers'               => [
				// Even if somehow registered with bad name, validation should block.
				'../hack' => function () use ( &$called ) {
					$called = true;
				},
			],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		$job_line = \wp_json_encode( [
			'handler'    => '../hack',
			'parameters' => [],
		] );
		JobWorker::process( $job_line, 'jobs.log', $context );
		$this->assertFalse( $called, 'Handler with special chars should be rejected' );
	}

	public function test_handler_name_validation_accepts_valid_names(): void {
		$called  = false;
		$context = [
			'handlers'               => [
				'my_handler-v2' => function () use ( &$called ) {
					$called = true;
				},
			],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		$job_line = \wp_json_encode( [
			'handler'    => 'my_handler-v2',
			'parameters' => [],
		] );
		JobWorker::process( $job_line, 'jobs.log', $context );
		$this->assertTrue( $called, 'Valid handler name should be accepted' );
	}

	public function test_handler_name_must_start_with_letter(): void {
		$called  = false;
		$context = [
			'handlers'               => [
				'1invalid' => function () use ( &$called ) {
					$called = true;
				},
			],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		$job_line = \wp_json_encode( [
			'handler'    => '1invalid',
			'parameters' => [],
		] );
		JobWorker::process( $job_line, 'jobs.log', $context );
		$this->assertFalse( $called, 'Handler name starting with digit should be rejected' );
	}

	public function test_process_oversized_job_silently_dropped(): void {
		$context = [
			'handlers'               => [],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		// Create line > MAX_JOB_SIZE (10MB).
		$big_line = \str_repeat( 'x', 10485761 );
		JobWorker::process( $big_line, 'jobs.log', $context );
		// Should not increment counter because it was dropped before dispatch.
		$this->assertSame( 0, $context['jobs_since_cache_flush'] );
	}

	public function test_process_handler_exception_is_caught(): void {
		$context = [
			'handlers'               => [
				'failing_handler' => function () {
					throw new \RuntimeException( 'Handler failed' );
				},
			],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		$job_line = \wp_json_encode( [
			'handler'    => 'failing_handler',
			'parameters' => [],
		] );

		// Should not throw - exception is caught internally.
		JobWorker::process( $job_line, 'jobs.log', $context );
		$this->assertSame( 1, $context['jobs_since_cache_flush'] );
	}

	public function test_process_remote_job_type(): void {
		$called  = false;
		$context = [
			'handlers'               => [],
			'remote_handlers'        => [
				'remote_action' => function ( array $params ) use ( &$called ) {
					$called = true;
				},
			],
			'jobs_since_cache_flush' => 0,
		];

		$job_line = \wp_json_encode( [
			'type'       => 'remote_job',
			'handler'    => 'remote_action',
			'parameters' => [],
		] );
		JobWorker::process( $job_line, 'jobs.log', $context );
		$this->assertTrue( $called, 'Remote job should dispatch to remote_handlers' );
	}

	public function test_save_state_returns_empty_array(): void {
		$context = [];
		$state   = JobWorker::save_state( $context );
		$this->assertIsArray( $state );
		$this->assertEmpty( $state );
	}

	public function test_begin_job_context_sets_server_vars(): void {
		$orig = JobWorker::begin_job_context( 'test_job' );

		$this->assertSame( '/jobs/test_job', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'POST', $_SERVER['REQUEST_METHOD'] );
		$this->assertSame( '/test_job', $_SERVER['PATH_INFO'] );
		$this->assertNotEmpty( $_SERVER['UNIQUE_ID'] );

		// Restore.
		JobWorker::end_job_context( $orig );
	}

	public function test_end_job_context_restores_server(): void {
		$original_uri = $_SERVER['REQUEST_URI'];
		$orig         = JobWorker::begin_job_context( 'restore_test' );

		$this->assertNotSame( $original_uri, $_SERVER['REQUEST_URI'] );

		JobWorker::end_job_context( $orig );
		$this->assertSame( $original_uri, $_SERVER['REQUEST_URI'] );
	}

	public function test_begin_job_context_strips_leading_slash(): void {
		$orig = JobWorker::begin_job_context( '/leading-slash-job' );

		// Path should not have double slashes.
		$this->assertSame( '/leading-slash-job', $_SERVER['PATH_INFO'] );
		$this->assertSame( '/jobs/leading-slash-job', $_SERVER['REQUEST_URI'] );

		JobWorker::end_job_context( $orig );
	}

	public function test_nested_job_contexts(): void {
		$original_uri = $_SERVER['REQUEST_URI'];

		$outer = JobWorker::begin_job_context( 'outer_job' );
		$this->assertSame( '/jobs/outer_job', $_SERVER['REQUEST_URI'] );

		$inner = JobWorker::begin_job_context( 'inner_job' );
		$this->assertSame( '/jobs/inner_job', $_SERVER['REQUEST_URI'] );

		// End inner context — should restore outer.
		JobWorker::end_job_context( $inner );
		$this->assertSame( '/jobs/outer_job', $_SERVER['REQUEST_URI'] );

		// End outer context — should restore original.
		JobWorker::end_job_context( $outer );
		$this->assertSame( $original_uri, $_SERVER['REQUEST_URI'] );
	}

	public function test_cache_flush_interval(): void {
		$flush_count = 0;
		$context     = [
			'handlers'               => [
				'noop' => function () {},
			],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 49, // One away from flush.
		];

		$job_line = \wp_json_encode( [
			'handler'    => 'noop',
			'parameters' => [],
		] );

		JobWorker::process( $job_line, 'jobs.log', $context );

		// After 50th job, counter should reset to 0.
		$this->assertSame( 0, $context['jobs_since_cache_flush'] );
	}

	public function test_process_non_array_parameters_rejected(): void {
		$called  = false;
		$context = [
			'handlers'               => [
				'handler_x' => function () use ( &$called ) {
					$called = true;
				},
			],
			'remote_handlers'        => [],
			'jobs_since_cache_flush' => 0,
		];

		$job_line = \wp_json_encode( [
			'handler'    => 'handler_x',
			'parameters' => 'not-an-array',
		] );
		JobWorker::process( $job_line, 'jobs.log', $context );
		$this->assertFalse( $called, 'Non-array parameters should be rejected' );
	}
}
