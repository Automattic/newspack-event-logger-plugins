<?php
/**
 * Workers Controller
 *
 * Worker status and management.
 *
 * @package Newspack_Event_Dashboards
 */

namespace Newspack_Event_Dashboards\REST;

use Newspack_Event_Logger\Admin\Admin as EventLoggerAdmin;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Cron\LogReader;
use Newspack_Event_Logger\Cron\Supervisor;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\Lock;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for worker status and management endpoints.
 */
class WorkersController extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'event-logger/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'performance';

	/**
	 * Number of partitions.
	 *
	 * @var int
	 */
	protected $num_partitions;

	/**
	 * Log base path.
	 *
	 * @var string
	 */
	protected $log_base;

	/**
	 * Locks base path.
	 *
	 * @var string
	 */
	protected $locks_base;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$config               = Config::load_config( 'full' );
		$this->num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$this->log_base       = Config::get_logs_directory();
		$this->locks_base     = Config::get_locks_directory();
	}

	/**
	 * Check read permissions.
	 *
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 */
	public function read_permissions_check() {
		if ( ! EventLoggerAdmin::current_user_allowed() ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to access this resource.', 'newspack-event-logger' ),
				[ 'status' => \rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Check rate limit for write operations.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	protected function check_rate_limit() {
		$transient_key = 'event_logger_rate_limit_' . \get_current_user_id();
		$last_request  = \get_transient( $transient_key );

		if ( false !== $last_request ) {
			$elapsed = \time() - (int) $last_request;
			if ( $elapsed < 2 ) {
				return new \WP_Error(
					'rate_limited',
					\__( 'Too many requests. Please wait a moment.', 'newspack-event-logger' ),
					[ 'status' => 429 ]
				);
			}
		}

		\set_transient( $transient_key, \time(), 10 );
		return true;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /performance/workers - Worker and segment status.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/workers',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_workers' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
				],
			]
		);

		// POST /performance/workers/restart - Request worker restart.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/workers/restart',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'restart_workers' ],
					'permission_callback' => [ $this, 'restart_permissions_check' ],
					'args'                => [
						'type'      => [
							'default'           => 'all',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => [ $this, 'validate_restart_type' ],
						],
						'partition' => [
							'default'           => 0, // Default to partition 0, not -1 (all), to prevent accidental bulk restarts.
							'sanitize_callback' => function ( $v ) {
								return (int) $v;
							},
						],
						'all_partitions' => [
							'default'           => false, // Must explicitly request all partitions.
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						'nonce'     => [
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);
	}

	/**
	 * Validate worker type.
	 *
	 * All workers are now LogReaders registered via newspack_event_logger_log_readers filter.
	 *
	 * @param string $type Worker type (reader name).
	 * @return bool True if valid.
	 */
	public function validate_worker_type( string $type ): bool {
		$readers = LogReader::get_registered_readers();
		return isset( $readers[ $type ] );
	}

	/**
	 * Validate restart type (includes 'all' option and standalone workers).
	 *
	 * @param string $type Worker type or 'all'.
	 * @return bool True if valid.
	 */
	public function validate_restart_type( string $type ): bool {
		if ( 'all' === $type ) {
			return true;
		}
		// Check LogReaders.
		if ( $this->validate_worker_type( $type ) ) {
			return true;
		}
		// Check standalone workers.
		$standalone = Supervisor::get_standalone_workers();
		return isset( $standalone[ $type ] ) || 'supervisor' === $type;
	}

	/**
	 * Check restart permissions.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 */
	public function restart_permissions_check( $request ) {
		// Check capability + allowed_users whitelist.
		if ( ! EventLoggerAdmin::current_user_allowed() ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to restart workers.', 'newspack-event-logger' ),
				[ 'status' => \rest_authorization_required_code() ]
			);
		}

		// Verify nonce.
		$nonce = $request->get_param( 'nonce' );
		if ( empty( $nonce ) || ! \wp_verify_nonce( $nonce, 'event_logger_restart_worker' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'Invalid or missing security nonce.', 'newspack-event-logger' ),
				[ 'status' => 403 ]
			);
		}

		// Check rate limit.
		$rate_check = $this->check_rate_limit();
		if ( \is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return true;
	}

	/**
	 * Request workers to restart.
	 *
	 * Handles both LogReaders and standalone workers.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Error|\WP_REST_Response Response with restart status.
	 */
	public function restart_workers( $request ) {
		$type           = $request->get_param( 'type' );
		$partition      = $request->get_param( 'partition' );
		$all_partitions = $request->get_param( 'all_partitions' );
		$results        = [];

		// Validate partition bounds if specific partition requested.
		if ( ! $all_partitions && ( $partition < 0 || $partition >= $this->num_partitions ) ) {
			return new \WP_Error( 'invalid_partition', 'Invalid partition number', [ 'status' => 400 ] );
		}

		// Require explicit all_partitions flag for bulk restarts (DoS mitigation).
		$partitions = $all_partitions ? \range( 0, $this->num_partitions - 1 ) : [ $partition ];

		// Get registered log readers.
		$readers = LogReader::get_registered_readers();

		// Get standalone workers.
		$standalone = Supervisor::get_standalone_workers();

		// Check if this is a standalone worker request.
		$is_standalone = isset( $standalone[ $type ] ) || 'supervisor' === $type;

		if ( $is_standalone && 'all' !== $type ) {
			// Handle standalone worker restart.
			if ( 'supervisor' === $type ) {
				// Supervisor is always single instance.
				$lock_dir  = "{$this->locks_base}/supervisor.lock.d";
				$success   = Lock::request_restart( $lock_dir );
				$results[] = [
					'type'      => 'supervisor',
					'partition' => null,
					'requested' => $success,
				];
			} else {
				$config      = $standalone[ $type ];
				$partitioned = ! empty( $config['partitions'] );

				if ( $partitioned ) {
					foreach ( $partitions as $p ) {
						$lock_dir  = "{$this->locks_base}/{$type}.p{$p}.lock.d";
						$success   = Lock::request_restart( $lock_dir );
						$results[] = [
							'type'      => $type,
							'partition' => $p,
							'requested' => $success,
						];
					}
				} else {
					// Single instance standalone worker.
					$lock_dir  = "{$this->locks_base}/{$type}.lock.d";
					$success   = Lock::request_restart( $lock_dir );
					$results[] = [
						'type'      => $type,
						'partition' => null,
						'requested' => $success,
					];
				}
			}
		} else {
			// Handle LogReader restarts.
			foreach ( $partitions as $p ) {
				foreach ( $readers as $reader_name => $config ) {
					if ( 'all' === $type || $reader_name === $type ) {
						$lock_dir  = "{$this->locks_base}/{$reader_name}.p{$p}.lock.d";
						$success   = Lock::request_restart( $lock_dir );
						$results[] = [
							'type'      => $reader_name,
							'partition' => $p,
							'requested' => $success,
						];
					}
				}
			}
		}

		return \rest_ensure_response(
			[
				'success' => true,
				'results' => $results,
			]
		);
	}

	/**
	 * Get worker and segment status.
	 *
	 * All workers are now LogReaders registered via newspack_event_logger_log_readers filter.
	 *
	 * @return \WP_REST_Response Response with worker status.
	 */
	public function get_workers() {
		$workers    = [];
		$standalone = [];
		$logs       = [];
		$now        = \time();

		$config       = Config::load_config( 'full' );
		$segment_size = $config['segment_size'] ?? ( 64 * 1024 * 1024 );
		$num_segments = $config['num_segments'] ?? 4;

		// Get registered log readers.
		$readers = LogReader::get_registered_readers();

		// Collect all input and output logs from registered handler configs.
		$input_logs  = [];
		$output_logs = [];
		foreach ( $readers as $group_config ) {
			foreach ( $group_config['handlers'] as $handler_config ) {
				foreach ( $handler_config['inputs'] as $input ) {
					$input_logs[ $input ] = true;
				}
				foreach ( $handler_config['outputs'] ?? [] as $output ) {
					$output_logs[ $output ] = true;
				}
			}
		}

		// Terminal logs are outputs that aren't inputs to any reader.
		$terminal_logs = \array_diff_key( $output_logs, $input_logs );

		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			// Expand groups into individual handlers for pipeline display.
			foreach ( $readers as $group_name => $group_config ) {
				foreach ( $group_config['handlers'] as $handler_name => $handler_config ) {
					$input_log  = $handler_config['inputs'][0];
					$output_log = ( $handler_config['outputs'] ?? [] )[0] ?? null;
					$firehose   = new Firehose( "{$this->log_base}/{$input_log}", $p );
					$worker     = $this->get_worker_status(
						$group_name,
						$p,
						$input_log,
						$output_log,
						$firehose,
						"{$this->locks_base}/{$group_name}.p{$p}.lock.d/heartbeat",
						$now,
						$group_config['stale_timeout'] ?? Lock::STALE_TIMEOUT
					);
					$worker['handler'] = $handler_name;
					$workers[]         = $worker;
				}
			}

			// Add terminal output logs (outputs not consumed by any reader).
			foreach ( $terminal_logs as $log_name => $_ ) {
				$log_key  = \str_replace( '.log', '', $log_name );
				$logs[]   = $this->get_log_segments( $log_key, $p, "{$this->log_base}/{$log_name}/p{$p}" );
			}
		}

		// Add standalone workers (supervisor, stream-merger, health-check, etc.).
		$standalone = $this->get_standalone_workers_status( $now );

		return \rest_ensure_response(
			[
				'workers'        => $workers,
				'standalone'     => $standalone,
				'logs'           => $logs,
				'num_partitions' => $this->num_partitions,
				'num_segments'   => $num_segments,
				'segment_size'   => $segment_size,
				'timestamp'      => $now,
			]
		);
	}

	/**
	 * Get status for standalone workers.
	 *
	 * @param int $now Current timestamp.
	 * @return array Standalone worker status.
	 */
	private function get_standalone_workers_status( int $now ): array {
		$result = [];

		// Supervisor (always single instance).
		$result[] = $this->get_standalone_status( 'supervisor', null, "{$this->locks_base}/supervisor.lock.d", $now );

		// Get registered standalone workers.
		$standalone_workers = Supervisor::get_standalone_workers();

		foreach ( $standalone_workers as $name => $config ) {
			$partitioned = ! empty( $config['partitions'] );

			if ( $partitioned ) {
				// One per partition.
				for ( $p = 0; $p < $this->num_partitions; $p++ ) {
					$lock_dir = "{$this->locks_base}/{$name}.p{$p}.lock.d";
					$result[] = $this->get_standalone_status( $name, $p, $lock_dir, $now );
				}
			} else {
				// Single instance.
				$lock_dir = "{$this->locks_base}/{$name}.lock.d";
				$result[] = $this->get_standalone_status( $name, null, $lock_dir, $now );
			}
		}

		return $result;
	}

	/**
	 * Get status for a single standalone worker.
	 *
	 * @param string   $name           Worker name.
	 * @param int|null $partition      Partition number (null for single instance).
	 * @param string   $lock_dir       Lock directory path.
	 * @param int      $now            Current timestamp.
	 * @param int      $stale_timeout  Lock stale timeout in seconds.
	 * @return array Worker status.
	 */
	private function get_standalone_status( string $name, ?int $partition, string $lock_dir, int $now, int $stale_timeout = Lock::STALE_TIMEOUT ): array {
		$heartbeat_file = "{$lock_dir}/heartbeat";

		// Check heartbeat for status.
		$status        = 'dead';
		$heartbeat_age = null;
		if ( \file_exists( $heartbeat_file ) ) {
			$mtime = @\filemtime( $heartbeat_file );
			if ( false !== $mtime ) {
				$heartbeat_age = $now - $mtime;
				if ( $heartbeat_age < $stale_timeout ) {
					$status = 'running';
				}
			}
		}

		// Get worker start time and restart pending status.
		$started_at      = Lock::get_started_time( $lock_dir );
		$restart_pending = Lock::is_restart_pending( $lock_dir );

		return [
			'type'            => $name,
			'partition'       => $partition,
			'status'          => $status,
			'started_at'      => $started_at,
			'heartbeat_age'   => $heartbeat_age,
			'restart_pending' => $restart_pending,
		];
	}

	/**
	 * Scan a directory for segment files and collect metadata.
	 *
	 * @param string $segment_dir Directory containing segment files.
	 * @return array Array with 'segments' and 'total_size' keys.
	 */
	private function scan_segments( string $segment_dir ): array {
		$segments   = [];
		$total_size = 0;

		if ( ! \is_dir( $segment_dir ) ) {
			return [
				'segments'   => [],
				'total_size' => 0,
			];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir
		$files = @\scandir( $segment_dir );
		if ( ! $files ) {
			return [
				'segments'   => [],
				'total_size' => 0,
			];
		}

		foreach ( $files as $file ) {
			if ( \preg_match( '/^(\d+)\.log$/', $file, $m ) ) {
				$seg_id   = (int) $m[1];
				$filepath = "{$segment_dir}/{$file}";
				// Skip symlinks to prevent filesystem probing attacks.
				if ( \is_link( $filepath ) ) {
					continue;
				}
				$size = @\filesize( $filepath );
				// Handle filesize failure explicitly - use 0 for missing/unreadable files.
				if ( false === $size ) {
					$size = 0;
				}
				$total_size += $size;
				$mtime = @\filemtime( $filepath );
				// Handle filemtime failure explicitly - use 0 for missing/unreadable files.
				if ( false === $mtime ) {
					$mtime = 0;
				}
				$segments[] = [
					'id'    => $seg_id,
					'size'  => $size,
					'mtime' => $mtime,
				];
			}
		}

		\usort( $segments, fn( $a, $b ) => $a['id'] <=> $b['id'] );

		return [
			'segments'   => $segments,
			'total_size' => $total_size,
		];
	}

	/**
	 * Get segments for a log without worker status.
	 *
	 * @param string $name        Log name.
	 * @param int    $partition   Partition number.
	 * @param string $segment_dir Directory containing segment files.
	 * @return array Log segment data.
	 */
	private function get_log_segments( string $name, int $partition, string $segment_dir ): array {
		$scan = $this->scan_segments( $segment_dir );

		return [
			'name'       => $name,
			'partition'  => $partition,
			'segments'   => $scan['segments'],
			'total_size' => $scan['total_size'],
		];
	}

	/**
	 * Get status for a single worker.
	 *
	 * @param string   $type           Worker group name (e.g., firehose-workers, request-workers).
	 * @param int      $partition      Partition number.
	 * @param string   $input_log      Input log name.
	 * @param string   $output_log     Output log name (or null if no output).
	 * @param Firehose $firehose       Firehose the worker reads from.
	 * @param string   $heartbeat_file Path to heartbeat file (inside lock dir).
	 * @param int      $now            Current timestamp.
	 * @param int      $stale_timeout  Lock stale timeout in seconds.
	 * @return array Worker status data.
	 */
	private function get_worker_status(
		string $type,
		int $partition,
		string $input_log,
		?string $output_log,
		Firehose $firehose,
		string $heartbeat_file,
		int $now,
		int $stale_timeout = 60
	): array {
		// Get lock directory from heartbeat file path.
		$lock_dir = \dirname( $heartbeat_file );

		// Get segments.
		$scan       = $this->scan_segments( $firehose->get_partition_dir() );
		$segments   = $scan['segments'];
		$total_size = $scan['total_size'];

		// Get cursor position from memcache (live) or offsetlog (fallback).
		$positions     = LogReader::get_live_positions( $type, $partition )
			?? LogReader::get_saved_positions( $type, $partition );
		$pos           = $positions[ $input_log ] ?? null;
		$cursor_seg    = $pos['seg'] ?? 0;
		$cursor_offset = $pos['off'] ?? 0;

		// Calculate bytes behind cursor efficiently using sorted segment property.
		// Segments are already sorted by ID, so we can start from cursor_seg.
		$behind        = 0;
		$found_current = false;
		foreach ( $segments as $seg ) {
			if ( $seg['id'] === $cursor_seg ) {
				$found_current = true;
				$remaining     = $seg['size'] - $cursor_offset;
				if ( $remaining > 0 ) {
					$behind += $remaining;
				}
			} elseif ( $found_current || $seg['id'] > $cursor_seg ) {
				// Once we find current or pass it, sum all remaining segment sizes.
				$behind += $seg['size'];
			}
		}

		// Check heartbeat for status.
		$status        = 'dead';
		$heartbeat_age = null;
		if ( \file_exists( $heartbeat_file ) ) {
			$mtime = @\filemtime( $heartbeat_file );
			// Handle filemtime failure explicitly - treat as dead if unreadable.
			if ( false !== $mtime ) {
				$heartbeat_age = $now - $mtime;
				if ( $heartbeat_age < $stale_timeout ) {
					$status = 'running';
				}
			}
		}

		// Get worker start time and restart pending status.
		$started_at      = Lock::get_started_time( $lock_dir );
		$restart_pending = Lock::is_restart_pending( $lock_dir );

		return [
			'type'            => $type,
			'partition'       => $partition,
			'input_log'       => $input_log,
			'output_log'      => $output_log,
			'status'          => $status,
			'started_at'      => $started_at,
			'heartbeat_age'   => $heartbeat_age,
			'restart_pending' => $restart_pending,
			'segments'        => $segments,
			'total_size'      => $total_size,
			'cursor_seg'      => $cursor_seg,
			'cursor_offset'   => $cursor_offset,
			'behind'          => $behind,
		];
	}
}
