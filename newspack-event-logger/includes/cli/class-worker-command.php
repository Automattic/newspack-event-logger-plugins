<?php
/**
 * WP-CLI command for running Event Logger background workers.
 *
 * Spawned by Supervisor via REST API to run workers in separate processes.
 *
 * @package Newspack_Event_Logger
 */

namespace Newspack_Event_Logger\CLI;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Lock;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\Cron\LogReader;
use Newspack_Event_Logger\Cron\Supervisor;
use WP_CLI;
use WP_CLI_Command;

/**
 * Event Logger worker commands.
 */
class WorkerCommand extends WP_CLI_Command {

	/**
	 * List running workers and their status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eventlog worker list
	 *
	 * @when after_wp_load
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_cmd( array $args, array $assoc_args ): void {
		$readers    = LogReader::get_registered_readers();
		$standalone = Supervisor::get_standalone_workers();
		$this->list_workers( $readers, $standalone );
	}

	/**
	 * List available worker types.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eventlog worker types
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function types( array $args, array $assoc_args ): void {
		$readers    = LogReader::get_registered_readers();
		$standalone = Supervisor::get_standalone_workers();
		$this->list_types( $readers, $standalone );
	}

	/**
	 * Run a background worker.
	 *
	 * Workers process log data in the background. They run for up to ~1 hour
	 * before exiting, and are automatically respawned by the supervisor.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Worker type to run. Use 'wp eventlog worker types' to see available types.
	 *
	 * [--partition=<partition>]
	 * : Partition number (0-based).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--quiet]
	 * : Suppress output (for background execution).
	 *
	 * ## EXAMPLES
	 *
	 *     # Run firehose-workers group for partition 0
	 *     wp eventlog worker run firehose-workers
	 *
	 *     # Run stream-merger for partition 1
	 *     wp eventlog worker run stream-merger --partition=1
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$readers     = LogReader::get_registered_readers();
		$standalone  = Supervisor::get_standalone_workers();
		$valid_types = \array_merge( \array_keys( $readers ), \array_keys( $standalone ) );

		$type      = $args[0] ?? '';
		$partition = (int) ( $assoc_args['partition'] ?? 0 );
		$quiet     = isset( $assoc_args['quiet'] );

		// Validate type.
		if ( empty( $type ) ) {
			WP_CLI::error( 'Worker type required. Use: wp eventlog worker run <type>' );
		}

		if ( ! \in_array( $type, $valid_types, true ) ) {
			WP_CLI::error( 'Invalid worker type: ' . $type . '. Available types: ' . \implode( ', ', $valid_types ) );
		}

		// Validate partition.
		$config         = Config::load_config( 'full' );
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		if ( $partition < 0 || $partition >= $num_partitions ) {
			WP_CLI::error( \sprintf( 'Invalid partition. Must be 0-%d.', $num_partitions - 1 ) );
		}

		// Check if logging is enabled.
		if ( empty( $config['enable_logging'] ) ) {
			if ( ! $quiet ) {
				WP_CLI::warning( 'Logging is disabled. Exiting.' );
			}
			return;
		}

		if ( ! $quiet ) {
			WP_CLI::log( \sprintf( 'Starting %s worker for partition %d...', $type, $partition ) );
		}

		// Check if this is a standalone worker or a reader-based worker.
		if ( isset( $standalone[ $type ] ) ) {
			// Run standalone worker directly.
			$worker_class = $standalone[ $type ]['class'];
			$worker       = new $worker_class( $partition );
			$worker->run();
			if ( ! $quiet ) {
				WP_CLI::success( 'Standalone worker exited.' );
			}
		} else {
			// Run reader-based worker via LogReader.
			$result = LogReader::cron_callback( $type, $partition );
			if ( ! $quiet ) {
				$status = $result['status'] ?? 'unknown';
				WP_CLI::success( \sprintf( 'Worker exited with status: %s', $status ) );
			}
		}
	}

	/**
	 * Request worker restart.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Worker type to restart, or 'all' for all types.
	 *
	 * [--partition=<partition>]
	 * : Partition number (0-based).
	 *
	 * [--all-partitions]
	 * : Apply to all partitions.
	 *
	 * ## EXAMPLES
	 *
	 *     # Restart all workers on partition 0
	 *     wp eventlog worker restart all --partition=0
	 *
	 *     # Restart request-workers on all partitions
	 *     wp eventlog worker restart request-workers --all-partitions
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function restart( array $args, array $assoc_args ): void {
		$readers    = LogReader::get_registered_readers();
		$standalone = Supervisor::get_standalone_workers();

		$type           = $args[0] ?? '';
		$partition      = isset( $assoc_args['partition'] ) ? (int) $assoc_args['partition'] : -1;
		$all_partitions = isset( $assoc_args['all-partitions'] );

		$config         = Config::load_config( 'full' );
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$locks_base     = Config::get_locks_directory();

		// Validate type.
		if ( empty( $type ) ) {
			WP_CLI::error( 'Worker type required. Use: wp eventlog worker restart <type>' );
		}
		$all_types = \array_merge( \array_keys( $readers ), \array_keys( $standalone ) );
		if ( 'all' !== $type && ! \in_array( $type, $all_types, true ) ) {
			WP_CLI::error( 'Invalid worker type: ' . $type );
		}

		// Validate partition.
		if ( ! $all_partitions && ( $partition < 0 || $partition >= $num_partitions ) ) {
			WP_CLI::error( \sprintf( 'Invalid partition. Use --partition=<0-%d> or --all-partitions.', $num_partitions - 1 ) );
		}

		$partitions = $all_partitions ? \range( 0, $num_partitions - 1 ) : [ $partition ];
		$restarted  = 0;

		// Restart reader-based workers.
		foreach ( $partitions as $p ) {
			foreach ( $readers as $reader_name => $reader_config ) {
				if ( 'all' === $type || $reader_name === $type ) {
					$lock_dir = "{$locks_base}/{$reader_name}.p{$p}.lock.d";
					$success  = Lock::request_restart( $lock_dir );
					if ( $success ) {
						WP_CLI::log( "Restart requested: {$reader_name} partition {$p}" );
						++$restarted;
					} else {
						WP_CLI::warning( "Failed to request restart: {$reader_name} partition {$p}" );
					}
				}
			}
		}

		// Restart standalone workers.
		foreach ( $partitions as $p ) {
			foreach ( $standalone as $worker_name => $worker_config ) {
				if ( 'all' === $type || $worker_name === $type ) {
					// Check if this worker uses partitions.
					$uses_partitions = $worker_config['partitions'] ?? false;
					if ( ! $uses_partitions && $p > 0 ) {
						continue; // Single-instance worker, only restart on partition 0.
					}
					$lock_dir = "{$locks_base}/{$worker_name}.p{$p}.lock.d";
					$success  = Lock::request_restart( $lock_dir );
					if ( $success ) {
						WP_CLI::log( "Restart requested: {$worker_name} partition {$p}" );
						++$restarted;
					} else {
						WP_CLI::warning( "Failed to request restart: {$worker_name} partition {$p}" );
					}
				}
			}
		}

		WP_CLI::success( "Requested restart for {$restarted} worker(s)." );
	}

	/**
	 * List available worker types.
	 *
	 * @param array $readers    Registered readers.
	 * @param array $standalone Standalone workers.
	 */
	private function list_types( array $readers, array $standalone ): void {
		if ( empty( $readers ) && empty( $standalone ) ) {
			WP_CLI::warning( 'No worker types registered.' );
			return;
		}

		if ( ! empty( $readers ) ) {
			WP_CLI::log( 'Reader groups:' );
			foreach ( $readers as $name => $config ) {
				$inputs   = \implode( ', ', $config['inputs'] );
				$handlers = \implode( ', ', \array_keys( $config['handlers'] ) );
				WP_CLI::log( "  - {$name} (inputs: {$inputs}, handlers: {$handlers})" );
			}
		}

		if ( ! empty( $standalone ) ) {
			WP_CLI::log( 'Standalone workers:' );
			foreach ( $standalone as $name => $config ) {
				$partitioned = ( $config['partitions'] ?? false ) ? 'partitioned' : 'single';
				WP_CLI::log( "  - {$name} ({$partitioned})" );
			}
		}
	}

	/**
	 * List running workers and their status.
	 *
	 * @param array $readers    Registered readers.
	 * @param array $standalone Standalone workers.
	 */
	private function list_workers( array $readers, array $standalone ): void {
		$config         = Config::load_config( 'full' );
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$log_base       = Config::get_logs_directory();
		$locks_base     = Config::get_locks_directory();
		$now            = \time();

		$rows = [];

		// List reader group workers.
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			foreach ( $readers as $group_name => $group_config ) {
				$input_log      = $group_config['inputs'][0];
				$firehose       = new Firehose( "{$log_base}/{$input_log}", $p );
				$lock_dir       = "{$locks_base}/{$group_name}.p{$p}.lock.d";
				$heartbeat_file = "{$lock_dir}/heartbeat";

				// Check status using actual stale timeout from reader config.
				$stale_timeout = $group_config['stale_timeout'] ?? Lock::STALE_TIMEOUT;
				$status        = 'dead';
				if ( \file_exists( $heartbeat_file ) ) {
					$mtime = @\filemtime( $heartbeat_file );
					if ( false !== $mtime && ( $now - $mtime ) < $stale_timeout ) {
						$status = 'running';
					}
				}

				// Get cursor position from memcache.
				$positions     = LogReader::get_live_positions( $group_name, $p );
				$pos           = $positions[ $input_log ] ?? null;
				$cursor_seg    = $pos['seg'] ?? 0;
				$cursor_offset = $pos['off'] ?? 0;

				// Calculate behind.
				$partition_dir = $firehose->get_partition_dir();
				$behind        = $this->calculate_behind( $partition_dir, $cursor_seg, $cursor_offset );

				// Get restart pending status.
				$restart_pending = Lock::is_restart_pending( $lock_dir ) ? 'yes' : 'no';

				// Get uptime.
				$started_at = Lock::get_started_time( $lock_dir );
				$uptime     = $started_at ? $this->format_duration( $now - $started_at ) : '-';

				$rows[] = [
					'Type'      => $group_name,
					'Partition' => $p,
					'Status'    => $status,
					'Uptime'    => $uptime,
					'Behind'    => $this->format_bytes( $behind ),
					'Restart'   => $restart_pending,
				];
			}
		}

		// List standalone workers.
		foreach ( $standalone as $worker_name => $worker_config ) {
			$uses_partitions = $worker_config['partitions'] ?? false;
			$max_p           = $uses_partitions ? $num_partitions : 1;

			for ( $p = 0; $p < $max_p; $p++ ) {
				$lock_dir       = "{$locks_base}/{$worker_name}.p{$p}.lock.d";
				$heartbeat_file = "{$lock_dir}/heartbeat";

				// Check status using actual stale timeout from worker config.
				$stale_timeout = $worker_config['stale_timeout'] ?? Lock::STALE_TIMEOUT;
				$status        = 'dead';
				if ( \file_exists( $heartbeat_file ) ) {
					$mtime = @\filemtime( $heartbeat_file );
					if ( false !== $mtime && ( $now - $mtime ) < $stale_timeout ) {
						$status = 'running';
					}
				}

				// Get restart pending status.
				$restart_pending = Lock::is_restart_pending( $lock_dir ) ? 'yes' : 'no';

				// Get uptime.
				$started_at = Lock::get_started_time( $lock_dir );
				$uptime     = $started_at ? $this->format_duration( $now - $started_at ) : '-';

				$rows[] = [
					'Type'      => $worker_name,
					'Partition' => $uses_partitions ? $p : '-',
					'Status'    => $status,
					'Uptime'    => $uptime,
					'Behind'    => '-', // Standalone workers don't track cursor position.
					'Restart'   => $restart_pending,
				];
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No workers registered.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'Type', 'Partition', 'Status', 'Uptime', 'Behind', 'Restart' ] );
	}

	/**
	 * Calculate bytes behind cursor.
	 *
	 * @param string $partition_dir Partition directory.
	 * @param int    $cursor_seg    Current segment ID.
	 * @param int    $cursor_offset Current offset in segment.
	 * @return int Bytes behind.
	 */
	private function calculate_behind( string $partition_dir, int $cursor_seg, int $cursor_offset ): int {
		$behind = 0;

		if ( ! \is_dir( $partition_dir ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir
		$files = @\scandir( $partition_dir );
		if ( ! $files ) {
			return 0;
		}

		$segments = [];
		foreach ( $files as $file ) {
			if ( \preg_match( '/^(\d+)\.log$/', $file, $m ) ) {
				$seg_id = (int) $m[1];
				$size   = @\filesize( "{$partition_dir}/{$file}" );
				if ( false !== $size ) {
					$segments[ $seg_id ] = $size;
				}
			}
		}

		\ksort( $segments );

		$found_current = false;
		foreach ( $segments as $seg_id => $size ) {
			if ( $seg_id === $cursor_seg ) {
				$found_current = true;
				$remaining     = $size - $cursor_offset;
				if ( $remaining > 0 ) {
					$behind += $remaining;
				}
			} elseif ( $found_current || $seg_id > $cursor_seg ) {
				$behind += $size;
			}
		}

		return $behind;
	}

	/**
	 * Format bytes for display.
	 *
	 * @param int $bytes Byte count.
	 * @return string Formatted string.
	 */
	private function format_bytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . 'B';
		}
		if ( $bytes < 1024 * 1024 ) {
			return \round( $bytes / 1024, 1 ) . 'KB';
		}
		if ( $bytes < 1024 * 1024 * 1024 ) {
			return \round( $bytes / ( 1024 * 1024 ), 1 ) . 'MB';
		}
		return \round( $bytes / ( 1024 * 1024 * 1024 ), 1 ) . 'GB';
	}

	/**
	 * Format duration for display.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted string.
	 */
	private function format_duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		if ( $seconds < 3600 ) {
			return \floor( $seconds / 60 ) . 'm';
		}
		if ( $seconds < 86400 ) {
			return \floor( $seconds / 3600 ) . 'h';
		}
		return \floor( $seconds / 86400 ) . 'd';
	}
}
