<?php
/**
 * Firehose
 *
 * Single-Partition Segmented Log Writer/Reader.
 * Append-only segmented log for high-throughput logging.
 * Each instance handles one partition. Use hash_to_partition() to route writes.
 *
 * Segments are named by monotonically increasing ID (0.log, 1.log, 2.log, ...).
 * Positions are segment_id:offset format for unambiguous positioning.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Firehose class.
 */
class Firehose {
	const MAX_LINE_SIZE = 4096;
	const MAX_READ_SIZE = 10485760; // 10MB limit for read operations.
	const SEGMENT_PATTERN = '/^(\d+)\.log$/';
	const SEGMENT_CACHE_TTL = 0.25;

	private string $base_dir;
	private int $partition;
	private string $partition_dir;
	private int $segment_size;
	private int $num_segments;
	private int $max_lifespan;
	private int $current_segment_id   = 0;
	private int $current_size         = 0;
	private string $current_log_path  = '';
	private string $current_idx_path  = '';
	private bool $drop_large_writes   = true;
	private bool $skip_rotation_lock  = false;
	private float $last_segment_check = 0.0;

	/** @var array|null Cached segments list. */
	private ?array $segments_cache = null;

	/** @var float Last time segments were scanned. */
	private float $segments_cache_time = 0.0;

	/** @var callable|null Index callback: fn(string $line, array $position) => string|null */
	private $index_callback = null;

	/** @var resource|null Persistent file handle for current segment. */
	private $fh = null;

	/** @var int Segment ID that $fh is open for (-1 if none). */
	private int $fh_segment_id = -1;

	/** @var resource|null Persistent file handle for current index (opened with $fh). */
	private $idx_fh = null;

	/**
	 * Constructor.
	 *
	 * @param string   $base_dir      Base directory for this firehose (must be canonical path).
	 * @param int      $partition     Partition number for this instance.
	 * @param int|null $segment_size  Maximum segment size in bytes (default: from config, ~64MB).
	 * @param int|null $num_segments  Number of segments to retain (default: from config, ~4).
	 * @param int|null $max_lifespan  Minimum retention in seconds (default: from config, 86400).
	 *                                Segments are only deleted when BOTH conditions pass:
	 *                                1) more than num_segments exist, AND
	 *                                2) the segment is older than max_lifespan.
	 *                                Set to 0 to disable time-based retention (pure count-based).
	 *
	 * @throws \RuntimeException If base_dir contains symlinks or is not canonical.
	 */
	public function __construct(
		string $base_dir,
		int $partition,
		?int $segment_size = null,
		?int $num_segments = null,
		?int $max_lifespan = null
	) {
		$this->base_dir      = Config::ensure_path( $base_dir );
		$this->partition     = $partition;
		$this->partition_dir = "{$this->base_dir}/p{$partition}";

		if ( null === $segment_size || null === $num_segments || null === $max_lifespan ) {
			$config = Config::load_config( 'full' );
		}
		$this->segment_size  = \max( 1024, $segment_size ?? (int) ( $config['segment_size'] ?? 64 * 1024 * 1024 ) );
		$this->num_segments  = \max( 2, $num_segments ?? (int) ( $config['num_segments'] ?? 4 ) );
		$this->max_lifespan  = \max( 0, $max_lifespan ?? (int) ( $config['max_lifespan'] ?? 86400 ) );

		$this->init_current_segment();
	}

	/**
	 * Get the path to a segment file.
	 *
	 * @param int $segment_id Segment ID.
	 * @return string Full path to segment file.
	 * @throws \InvalidArgumentException If segment_id is negative.
	 */
	public function get_segment_path( int $segment_id ): string {
		if ( $segment_id < 0 ) {
			throw new \InvalidArgumentException( 'Segment ID must be non-negative' );
		}
		return "{$this->partition_dir}/{$segment_id}.log";
	}

	public function __destruct() {
		$this->close_handle();
	}

	/**
	 * Close the persistent file handles (log and index).
	 */
	private function close_handle(): void {
		if ( \is_resource( $this->fh ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			@\fclose( $this->fh );
			$this->fh            = null;
			$this->fh_segment_id = -1;
		}
		if ( \is_resource( $this->idx_fh ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			@\fclose( $this->idx_fh );
			$this->idx_fh = null;
		}
	}

	/**
	 * Get or open the file handle for the current segment.
	 * Also opens the index handle if an index callback is configured.
	 *
	 * @return resource|null File handle or null on failure.
	 */
	private function get_handle() {
		// If handle is for wrong segment, close it (closes both log and index).
		if ( null !== $this->fh && $this->fh_segment_id !== $this->current_segment_id ) {
			$this->close_handle();
		}

		// If no handle, open one (append mode creates file if needed).
		if ( null === $this->fh ) {
			// TOCTOU guard: ensure directory exists, re-init if file missing.
			// Handles recovery after rm -rf of logs directory.
			if ( ! \is_dir( $this->partition_dir ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
				@\mkdir( $this->partition_dir, 0755, true );
				$this->init_current_segment();
			} elseif ( ! \file_exists( $this->current_log_path ) ) {
				$this->init_current_segment();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$this->fh = @\fopen( $this->current_log_path, 'a' );
			if ( $this->fh ) {
				$this->fh_segment_id = $this->current_segment_id;
				// Single-writer firehoses (jobs.log, requests.log) keep handles open
				// for the process lifetime. Disable write buffering so downstream
				// readers see new entries immediately instead of waiting for the
				// 8KB PHP stream buffer to fill.
				if ( $this->skip_rotation_lock ) {
					\stream_set_write_buffer( $this->fh, 0 );
				}
				// Open index handle alongside log handle.
				if ( null !== $this->index_callback ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
					$this->idx_fh = @\fopen( $this->current_idx_path, 'a' );
				}
			}
		}

		return $this->fh;
	}

	/**
	 * Hash a string to a partition number.
	 *
	 * @param string $str            String to hash (query string stripped for URLs).
	 * @param int    $num_partitions Total number of partitions.
	 * @return int Partition number (0 to num_partitions-1).
	 */
	public static function hash_to_partition( string $str, int $num_partitions ): int {
		if ( $num_partitions < 1 ) {
			throw new \InvalidArgumentException( 'Partition count must be at least 1' );
		}
		// Strip query string using explode (strtok has global state pollution issues).
		$base = \explode( '?', $str, 2 )[0];
		// Mask to 31 bits to ensure positive result on 32-bit PHP.
		return ( \crc32( $base ?: $str ) & 0x7FFFFFFF ) % $num_partitions;
	}

	/**
	 * Enable companion index files with a custom formatter callback.
	 *
	 * @param callable $callback fn(string $line, array $position, ?array &$data) => string|null
	 * @return self
	 */
	public function with_index( callable $callback ): self {
		$this->index_callback = $callback;
		return $this;
	}

	/** Disable PIPE_BUF limit for single-writer scenarios. */
	public function allow_large_writes(): self {
		$this->drop_large_writes = false;
		$this->skip_rotation_lock = true;
		return $this;
	}

	/**
	 * Get the directory path for this partition.
	 *
	 * @return string Partition directory path.
	 */
	public function get_partition_dir(): string {
		return $this->partition_dir;
	}

	/**
	 * Get the partition number.
	 *
	 * @return int Partition number.
	 */
	public function get_partition(): int {
		return $this->partition;
	}

	/**
	 * Get the base directory for all partitions.
	 *
	 * @return string Base directory path.
	 */
	public function get_base_dir(): string {
		return $this->base_dir;
	}

	/**
	 * Initialize current segment state from existing segments.
	 * Does NOT create files - files are created lazily on first write.
	 */
	public function init_current_segment(): void {
		$this->close_handle();
		$segments = $this->get_segments( true );
		if ( empty( $segments ) ) {
			// No segments yet - set state for segment 0, file created on first write.
			$this->current_segment_id = 0;
			$this->current_size       = 0;
			$this->current_log_path   = "{$this->partition_dir}/0.log";
			$this->current_idx_path   = "{$this->partition_dir}/0.idx";
			return;
		}
		$newest                   = \end( $segments );
		$this->current_segment_id = $newest['id'];
		$this->current_size       = $newest['size'];
		$this->current_log_path   = "{$this->partition_dir}/{$this->current_segment_id}.log";
		$this->current_idx_path   = "{$this->partition_dir}/{$this->current_segment_id}.idx";
	}

	/**
	 * Get list of segments sorted by ID (cached with TTL).
	 *
	 * @param bool $force_refresh Bypass cache and rescan filesystem.
	 * @return array Array of ['id' => int, 'size' => int].
	 */
	public function get_segments( bool $force_refresh = false ): array {
		$now = \microtime( true );
		if ( ! $force_refresh && null !== $this->segments_cache && ( $now - $this->segments_cache_time ) < self::SEGMENT_CACHE_TTL ) {
			return $this->segments_cache;
		}

		$segments = [];
		if ( ! \is_dir( $this->partition_dir ) ) {
			$this->segments_cache      = [];
			$this->segments_cache_time = $now;
			return [];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir
		$files = @\scandir( $this->partition_dir );
		if ( ! $files ) {
			$this->segments_cache      = [];
			$this->segments_cache_time = $now;
			return [];
		}

		foreach ( $files as $file ) {
			if ( \preg_match( self::SEGMENT_PATTERN, $file, $m ) ) {
				$segments[] = [ 'id' => (int) $m[1], 'size' => @\filesize( "{$this->partition_dir}/{$file}" ) ?: 0 ];
			}
		}

		\usort( $segments, fn( $a, $b ) => $a['id'] <=> $b['id'] );
		$this->segments_cache      = $segments;
		$this->segments_cache_time = $now;
		return $segments;
	}

	/**
	 * Set up state for a new segment.
	 * Does NOT create the file - it will be created lazily by fopen('a').
	 *
	 * @param int $segment_id Segment ID.
	 */
	private function set_segment( int $segment_id ): void {
		$this->close_handle();
		$this->current_segment_id = $segment_id;
		$this->current_size       = 0;
		$this->current_log_path   = "{$this->partition_dir}/{$segment_id}.log";
		$this->current_idx_path   = "{$this->partition_dir}/{$segment_id}.idx";
		$this->segments_cache     = null;
	}

	private function cleanup_segments(): void {
		$segments = $this->get_segments( true );
		$now      = \time();

		while ( \count( $segments ) > $this->num_segments ) {
			$oldest = $segments[0];

			// max_lifespan check: don't delete segments younger than the threshold.
			// Always keep at least num_segments regardless of age.
			if ( $this->max_lifespan > 0 ) {
				$path  = "{$this->partition_dir}/{$oldest['id']}.log";
				$mtime = @\filemtime( $path );
				if ( false !== $mtime && ( $now - $mtime ) < $this->max_lifespan ) {
					break; // Oldest segment is still within lifespan — keep everything.
				}
			}

			\array_shift( $segments );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			@\unlink( "{$this->partition_dir}/{$oldest['id']}.log" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			@\unlink( "{$this->partition_dir}/{$oldest['id']}.idx" );
		}
		// Update cache in-place instead of invalidating (avoids scandir on next call).
		$this->segments_cache      = $segments;
		$this->segments_cache_time = \microtime( true );
	}

	/**
	 * Write raw data to the log (no newline appended).
	 *
	 * Used for batched writes where caller has already formatted lines with newlines.
	 * Each call should be ≤4KB to maintain atomicity.
	 *
	 * @param string $data Pre-formatted data to write.
	 * @return bool True on success.
	 */
	public function write_raw( string $data ): bool {
		$len = \strlen( $data );

		if ( $this->drop_large_writes && $len > self::MAX_LINE_SIZE ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( "Firehose: Raw write exceeds PIPE_BUF ({$len} > " . self::MAX_LINE_SIZE . "), dropping to preserve atomicity. Log: {$this->partition_dir}" );
			return false;
		}

		if ( $this->current_size + $len > $this->segment_size ) {
			$this->rotate();
		}

		$fh = $this->get_handle();
		if ( ! $fh ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( "Firehose: write_raw failed to open handle for {$this->partition_dir}" );
			return false;
		}

		// Write all bytes, handling partial writes (parity with write()).
		$remaining = $data;
		while ( '' !== $remaining ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
			$written = @\fwrite( $fh, $remaining );
			if ( false === $written || 0 === $written ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( "Firehose: write_raw fwrite failed for {$this->partition_dir} (wrote " . ( \strlen( $data ) - \strlen( $remaining ) ) . "/{$len} bytes)" );
				return false;
			}
			$this->current_size += $written;
			$remaining = \substr( $remaining, $written );
		}
		return true;
	}

	/**
	 * Write a line to the log.
	 *
	 * @param string $line The line to write (newline will be appended).
	 * @return array|false Position array on success, false on failure.
	 */
	public function write( string $line, ?array &$data = null ) {
		$raw = $line . "\n";
		$len = \strlen( $raw );

		if ( $this->drop_large_writes && $len > self::MAX_LINE_SIZE ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( "Firehose: Line exceeds PIPE_BUF ({$len} > " . self::MAX_LINE_SIZE . "), dropping to preserve atomicity. Log: {$this->partition_dir}" );
			return false;
		}

		// Refresh segment state periodically (check at most once per second).
		$now = \microtime( true );
		if ( $now - $this->last_segment_check > 1.0 ) {
			$this->last_segment_check = $now;
			$segments                 = $this->get_segments();
			if ( ! empty( $segments ) ) {
				$newest = \end( $segments );
				// Sync to newest segment (handles both forward progress and firehose reset).
				if ( $newest['id'] !== $this->current_segment_id ) {
					$this->close_handle();
					$this->current_segment_id = $newest['id'];
					$this->current_size       = $newest['size'];
					$this->current_log_path   = "{$this->partition_dir}/{$this->current_segment_id}.log";
					$this->current_idx_path   = "{$this->partition_dir}/{$this->current_segment_id}.idx";
				}
			}
		}

		if ( $this->current_size + $len > $this->segment_size ) {
			$this->rotate();
		}

		$offset = $this->current_size;

		$fh = $this->get_handle();
		if ( ! $fh ) {
			return false;
		}

		// Write all bytes, handling partial writes.
		$remaining = $raw;
		while ( '' !== $remaining ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
			$written = @\fwrite( $fh, $remaining );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$this->current_size += $written;
			$remaining = \substr( $remaining, $written );
		}
		$position = [ 'segment_id' => $this->current_segment_id, 'offset' => $offset, 'length' => $len ];

		// Write to companion index if callback is set (handle opened with log in get_handle).
		if ( null !== $this->index_callback && null !== $this->idx_fh ) {
			try {
				$index_entry = ( $this->index_callback )( $line, $position, $data );
				if ( null !== $index_entry ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
					@\fwrite( $this->idx_fh, $index_entry . "\n" );
				}
			} catch ( \Throwable $e ) {
				// Log but don't fail the write - index is secondary to log integrity.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( 'Firehose: Index callback threw: ' . $e->getMessage() );
			}
		}

		return $position;
	}

	private function rotate(): void {
		$this->close_handle();

		// Single-writer mode: skip locking overhead.
		if ( $this->skip_rotation_lock ) {
			$this->do_rotate();
			return;
		}

		// Multi-writer mode: acquire rotation lock.
		$log_name  = \basename( $this->base_dir );
		$log_base  = \dirname( $this->base_dir );
		$locks_dir = "{$log_base}/locks";
		$lock_dir  = "{$locks_dir}/{$log_name}.p{$this->partition}.rotate.lock.d";

		// Ensure locks directory exists.
		if ( ! \is_dir( $locks_dir ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
			@\mkdir( $locks_dir, 0755, true );
		}

		// Acquire lock via mkdir (atomic).
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
		if ( ! @\mkdir( $lock_dir, 0755 ) ) {
			\clearstatcache( true, $lock_dir );
			// Handle filemtime() failure explicitly to prevent lock theft
			$mtime = @\filemtime( $lock_dir );
			if ( false === $mtime ) {
				// File doesn't exist or error - don't assume stale lock.
				\usleep( 50000 );
				$this->init_current_segment();
				return;
			}
			$lock_age = \time() - $mtime;
			if ( $lock_age < 5 ) {
				\usleep( 50000 );
				$this->init_current_segment();
				return;
			}
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
			@\rmdir( $lock_dir );
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
			if ( ! @\mkdir( $lock_dir, 0755 ) ) {
				$this->init_current_segment();
				return;
			}
		}

		try {
			$this->do_rotate();
		} finally {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
			@\rmdir( $lock_dir );
		}
	}

	/**
	 * Perform segment rotation (called with or without lock held).
	 *
	 * @return void
	 */
	private function do_rotate(): void {
		// Scan segments and check if newest still has room (force refresh for accuracy).
		$segments = $this->get_segments( true );

		if ( ! empty( $segments ) ) {
			$newest = \end( $segments );
			// If newest segment has room, just use it (another process already rotated).
			if ( $newest['size'] < $this->segment_size ) {
				$this->current_segment_id = $newest['id'];
				$this->current_size       = $newest['size'];
				$this->current_log_path   = "{$this->partition_dir}/{$this->current_segment_id}.log";
				$this->current_idx_path   = "{$this->partition_dir}/{$this->current_segment_id}.idx";
				return;
			}
		}

		// Newest is full (or no segments) - set up for new one.
		$next_id = empty( $segments ) ? 0 : \end( $segments )['id'] + 1;
		$this->set_segment( $next_id );
		// Create empty file to prevent get_handle() TOCTOU guard from re-initializing.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_touch
		if ( ! @\touch( $this->current_log_path ) ) {
			// touch() failed - log error but don't fail rotation
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( "Firehose: touch() failed for {$this->current_log_path}" );
		}
		$this->cleanup_segments();
	}

	/**
	 * Get the current write position.
	 *
	 * @return array{segment_id: int, offset: int}
	 */
	public function get_current_position(): array {
		return [
			'segment_id' => $this->current_segment_id,
			'offset'     => $this->current_size,
		];
	}

	/**
	 * Read data from a specific segment at a specific offset.
	 *
	 * @param int $segment_id Segment ID.
	 * @param int $offset     Offset within segment.
	 * @param int $length     Number of bytes to read.
	 * @return string|null Data or null on error.
	 */
	public function read_at( int $segment_id, int $offset, int $length ): ?string {
		// Validate bounds to prevent memory exhaustion and negative seeks.
		if ( $segment_id < 0 || $offset < 0 || $length < 0 || $length > self::MAX_READ_SIZE ) {
			return null;
		}
		$path = "{$this->partition_dir}/{$segment_id}.log";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = @\fopen( $path, 'r' );
		if ( ! $fh ) {
			return null;
		}
		\fseek( $fh, $offset );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$data = \fread( $fh, $length );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		\fclose( $fh );
		return ( false === $data ) ? null : $data;
	}

	/**
	 * Create a streaming reader for this firehose.
	 *
	 * @param string $default_offset Where to start: 'start', 'recent', 'end'.
	 * @return FirehoseReader
	 */
	public function reader( string $default_offset = 'start' ): FirehoseReader {
		return new FirehoseReader( $this, $default_offset );
	}

	/**
	 * Scan companion index files.
	 *
	 * @param callable $callback     fn(string $line, int $segment_id) => bool|null
	 * @param bool     $newest_first Whether to scan newest segments first.
	 */
	public function scan_index( callable $callback, bool $newest_first = true ): void {
		$segments = $this->get_segments();
		if ( $newest_first ) {
			$segments = \array_reverse( $segments );
		}

		foreach ( $segments as $seg ) {
			$idx_path = "{$this->partition_dir}/{$seg['id']}.idx";
			if ( ! \file_exists( $idx_path ) ) {
				continue;
			}

			// Guard against memory exhaustion from large index files.
			// Matches the 10MB limit in read_at() for consistency.
			$idx_size = @\filesize( $idx_path );
			if ( false === $idx_size || $idx_size > self::MAX_READ_SIZE ) {
				continue;
			}

			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$content = @\file_get_contents( $idx_path );
			if ( false === $content ) {
				continue;
			}

			$lines = \explode( "\n", \rtrim( $content, "\n" ) );
			if ( $newest_first ) {
				$lines = \array_reverse( $lines );
			}

			foreach ( $lines as $line ) {
				if ( '' === $line ) {
					continue;
				}
				$result = $callback( $line, $seg['id'] );
				if ( false === $result ) {
					return;
				}
			}
		}
	}

}
