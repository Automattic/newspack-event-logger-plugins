<?php
/**
 * WP-CLI command for filtering Performance Logger firehose logs.
 *
 * @package Newspack_Performance_Logger
 * @phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not web
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Intentional log file access
 */

namespace Newspack_Performance_Logger\CLI;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\FirehoseReader;
use WP_CLI;
use WP_CLI_Command;

/**
 * Filter Performance Logger firehose logs by request ID or pattern.
 *
 * Collects all log entries for requests matching the given pattern.
 * Works with JSONL format from Performance Logger firehose.
 *
 * ## EXAMPLES
 *
 *     # Search all partitions for URL pattern
 *     wp eventlog reqgrep /calendar
 *
 *     # Follow mode - tail all partitions continuously
 *     wp eventlog reqgrep --follow
 *
 *     # Follow mode with pattern filter
 *     wp eventlog reqgrep pyrobase --follow
 *
 *     # Match specific request ID
 *     wp eventlog reqgrep abc123def
 *
 *     # Output raw JSON instead of formatted
 *     wp eventlog reqgrep /calendar --raw
 *
 * @package Newspack_Performance_Logger
 */
class ReqgrepCommand extends WP_CLI_Command {

	/**
	 * Maximum lines per in-progress request to prevent unbounded memory growth.
	 *
	 * @var int
	 */
	private const MAX_LINES_PER_REQUEST = 20000;

	/**
	 * Maximum bytes per in-progress request. Caps memory held per rid independent
	 * of line count — some writers (gyrobase, workers) emit lines larger than
	 * PIPE_BUF, so a line-only cap can silently hold hundreds of MB per rid.
	 *
	 * @var int
	 */
	private const MAX_BYTES_PER_REQUEST = 8 * 1024 * 1024;

	/**
	 * Maximum lines per request in history buckets to prevent unbounded memory growth.
	 *
	 * @var int
	 */
	private const MAX_LINES_PER_REQUEST_IN_HISTORY = 10000;

	/**
	 * Formatting state - indentation level.
	 *
	 * @var int
	 */
	private int $fmt_indent = 0;

	/**
	 * Formatting state - last message number.
	 *
	 * @var int
	 */
	private int $fmt_last_number = 0;

	/**
	 * Formatting state - last timestamp.
	 *
	 * @var float
	 */
	private float $fmt_last_timestamp = 0;

	/**
	 * In-progress requests by request ID.
	 *
	 * @var array<string, array>
	 */
	private array $requests = [];

	/**
	 * Cumulative bytes stored per rid in $this->requests (for MAX_BYTES_PER_REQUEST).
	 *
	 * @var array<string, int>
	 */
	private array $request_bytes = [];

	/**
	 * Timestamps for request tracking (for stale cleanup).
	 *
	 * @var array<string, int>
	 */
	private array $timestamps = [];

	/**
	 * History buckets for catching request starts.
	 *
	 * @var array
	 */
	private array $history = [ [] ];

	/**
	 * Current time for cleanup checks.
	 *
	 * @var int
	 */
	private int $time;

	/**
	 * Line counter for periodic cleanup.
	 *
	 * @var int
	 */
	private int $line_count = 0;

	/**
	 * Current search pattern.
	 *
	 * @var string
	 */
	private string $pattern = '.';

	/**
	 * Pre-compiled regex pattern for matching.
	 *
	 * @var string
	 */
	private string $pattern_regex = '/./i';

	/**
	 * Whether to show incomplete requests.
	 *
	 * @var bool
	 */
	private bool $incomplete = false;

	/**
	 * Whether to output raw JSON.
	 *
	 * @var bool
	 */
	private bool $raw = false;

	/**
	 * Bucket size for history.
	 *
	 * @var int
	 */
	private int $bucket_size = 250;

	/**
	 * Number of history buckets.
	 *
	 * @var int
	 */
	private int $num_buckets = 10;

	/**
	 * Firehose base directory.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Number of partitions.
	 *
	 * @var int
	 */
	private int $num_partitions;

	/**
	 * Config array.
	 *
	 * @var array
	 */
	private array $config;

	/**
	 * Cached Firehose instances.
	 *
	 * @var array<int, Firehose>
	 */
	private array $firehose_cache = [];

	/**
	 * Filter firehose logs by request ID or pattern.
	 *
	 * ## OPTIONS
	 *
	 * [<pattern>]
	 * : Search pattern (request ID, URL, or any text). Matches everything if omitted.
	 *
	 * [--follow]
	 * : Follow mode - tail all partitions continuously (like tail -f).
	 *
	 * [--raw]
	 * : Output raw JSON instead of formatted.
	 *
	 * [--incomplete]
	 * : Show incomplete requests (those without process complete).
	 *
	 * [--bucket-size=<size>]
	 * : Lines per bucket for history buffer.
	 * ---
	 * default: 250
	 * ---
	 *
	 * [--num-buckets=<count>]
	 * : Number of history buckets to keep.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--path=<path>]
	 * : Firehose base directory. Auto-detected from config if not specified.
	 *
	 * ## EXAMPLES
	 *
	 *     # Search all partitions for URL pattern
	 *     wp eventlog reqgrep /calendar
	 *
	 *     # Follow mode
	 *     wp eventlog reqgrep --follow
	 *
	 *     # Follow with filter
	 *     wp eventlog reqgrep pyrobase --follow
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// Parse arguments.
		$this->pattern       = $args[0] ?? '.';
		$this->pattern_regex = '/' . \preg_quote( $this->pattern, '/' ) . '/i';
		$this->incomplete    = isset( $assoc_args['incomplete'] );
		$this->raw           = isset( $assoc_args['raw'] );
		$this->bucket_size = \max( 1, \min( 10000, (int) ( $assoc_args['bucket-size'] ?? 250 ) ) );
		$this->num_buckets = \max( 1, \min( 100, (int) ( $assoc_args['num-buckets'] ?? 10 ) ) );
		$follow            = isset( $assoc_args['follow'] );

		// Load config.
		$this->config         = Config::load_config();
		$this->base_dir       = $assoc_args['path'] ?? Config::get_logs_directory() . '/firehose.log';
		$this->num_partitions = $this->config['num_partitions'] ?? 1;
		$this->time           = \time();

		// Validate path if provided explicitly.
		if ( isset( $assoc_args['path'] ) ) {
			$real_path = \realpath( $assoc_args['path'] );
			if ( false === $real_path ) {
				WP_CLI::error( 'Invalid path: ' . $assoc_args['path'] );
			}
			$logs_dir = Config::get_logs_directory();
			if ( 0 !== \strpos( $real_path, $logs_dir ) ) {
				WP_CLI::error( 'Path must be within the logs directory.' );
			}
			$this->base_dir = $real_path;
		}

		// Check if stdin has data (pipe mode).
		$use_stdin = ! posix_isatty( STDIN );

		if ( $use_stdin ) {
			$this->process_stdin();
		} elseif ( $follow ) {
			$this->follow_mode();
		} else {
			$this->cat_mode();
		}
	}

	/**
	 * Process stdin (pipe mode).
	 */
	private function process_stdin(): void {
		while ( ( $line = \fgets( STDIN ) ) !== false ) {
			$this->process_line( $line );
		}
		$this->output_remaining();
	}

	/**
	 * Cat mode - stream through all partitions/segments using FirehoseReader.
	 */
	private function cat_mode(): void {
		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			$firehose = $this->get_firehose( $p );
			$reader   = $firehose->reader( 'start' );

			while ( $reader->open() ) {
				// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				while ( ( $line = $reader->read_line() ) !== null ) {
					$this->process_line( $line );
				}
				if ( $reader->is_caught_up() || ! $reader->next_segment() ) {
					break;
				}
			}
			$reader->close();
		}
		$this->output_remaining();
	}

	/**
	 * Follow mode - tail all partitions using FirehoseReader.
	 */
	private function follow_mode(): void {
		/** @var FirehoseReader[] $readers */
		$readers = [];

		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			$firehose      = $this->get_firehose( $p );
			$reader        = $firehose->reader( 'end' );
			$readers[ $p ] = $reader;
			$reader->open();
		}

		if ( empty( $readers ) ) {
			WP_CLI::error( 'No firehose partitions found.' );
		}

		WP_CLI::log( 'Base dir: ' . $this->base_dir );
		WP_CLI::log( 'Following ' . \count( $readers ) . ' partition(s)... (Ctrl+C to stop)' );

		// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( true ) {
			$had_data = false;

			foreach ( $readers as $p => $reader ) {
				// Read all available lines from current segment.
				// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				while ( ( $line = $reader->read_line() ) !== null ) {
					$this->process_line( $line );
					$had_data = true;
				}

				// Check for segment rotation.
				if ( ! $reader->is_caught_up() ) {
					$reader->next_segment();
				}
			}

			if ( ! $had_data ) {
				\usleep( 100000 ); // 100ms.
			}
		}
	}

	/**
	 * Get Firehose instance for a partition (cached).
	 *
	 * @param int $partition Partition number.
	 * @return Firehose
	 */
	private function get_firehose( int $partition ): Firehose {
		if ( ! isset( $this->firehose_cache[ $partition ] ) ) {
			$this->firehose_cache[ $partition ] = new Firehose( $this->base_dir, $partition );
		}
		return $this->firehose_cache[ $partition ];
	}

	/**
	 * Process a single log line.
	 *
	 * @param string $line Raw log line.
	 */
	private function process_line( string $line ): void {
		$line = \trim( $line );
		if ( empty( $line ) ) {
			return;
		}

		$entry = \json_decode( $line, true, 64 );
		if ( ! \is_array( $entry ) || ! isset( $entry['rid'] ) ) {
			return;
		}

		$rid = $entry['rid'];
		$key = $entry['k'] ?? '';

		if ( isset( $this->requests[ $rid ] ) ) {
			// Already tracking this request (with line + byte bounds check).
			$this->append_tracked_line( $rid, $line );
			if ( 'process (complete)' === $key ) {
				if ( ! $this->incomplete ) {
					$this->output_request( $this->requests[ $rid ] );
				}
				unset( $this->requests[ $rid ], $this->timestamps[ $rid ], $this->request_bytes[ $rid ] );
			}
		} elseif ( $rid === $this->pattern || \preg_match( $this->pattern_regex, $line ) ) {
			// New matching request.
			if ( \count( $this->requests ) >= 10000 ) {
				// Evict oldest to prevent unbounded memory growth.
				$evict_rid = \array_key_first( $this->requests );
				unset( $this->requests[ $evict_rid ], $this->timestamps[ $evict_rid ], $this->request_bytes[ $evict_rid ] );
			}
			$this->timestamps[ $rid ]    = $this->time;
			$this->request_bytes[ $rid ] = 0;

			// Check history for earlier entries.
			foreach ( $this->history as $recent ) {
				if ( isset( $recent[ $rid ] ) ) {
					if ( ! isset( $this->requests[ $rid ] ) ) {
						$this->requests[ $rid ] = [];
					}
					foreach ( $recent[ $rid ] as $hist_line ) {
						if ( ! $this->append_tracked_line( $rid, $hist_line ) ) {
							break 2; // Cap hit — stop merging history.
						}
					}
				}
			}

			$n = $entry['n'] ?? 0;
			if ( ! isset( $this->requests[ $rid ] ) && $n > 1 && \count( $this->history ) >= $this->num_buckets ) {
				WP_CLI::warning( "Couldn't find request start in history - try increasing --bucket-size or --num-buckets" );
			}

			if ( ! isset( $this->requests[ $rid ] ) ) {
				$this->requests[ $rid ] = [];
			}
			$this->append_tracked_line( $rid, $line );

			if ( 'process (complete)' === $key && ! $this->incomplete ) {
				$this->output_request( $this->requests[ $rid ] );
				unset( $this->requests[ $rid ], $this->timestamps[ $rid ], $this->request_bytes[ $rid ] );
			}
		} else {
			// Not matching - store in history (with bounds check).
			$recent_idx = \count( $this->history ) - 1;
			if ( ! isset( $this->history[ $recent_idx ][ $rid ] ) ) {
				$this->history[ $recent_idx ][ $rid ] = [];
			}
			if ( \count( $this->history[ $recent_idx ][ $rid ] ) < self::MAX_LINES_PER_REQUEST_IN_HISTORY ) {
				$this->history[ $recent_idx ][ $rid ][] = $line;
			}

			// Rotate history buckets.
			if ( \count( $this->history[ $recent_idx ], COUNT_RECURSIVE ) > $this->bucket_size ) {
				$this->history[] = [];
			}
			if ( \count( $this->history ) > $this->num_buckets ) {
				\array_shift( $this->history );
			}
		}

		// Periodic cleanup of stale requests (>15 min old).
		if ( ++$this->line_count > 10000 ) {
			$this->time = \time();
			foreach ( $this->timestamps as $rid => $ts ) {
				if ( $this->time - $ts >= 900 ) {
					$this->output_request( $this->requests[ $rid ] );
					echo "[incomplete]\n\n";
					unset( $this->timestamps[ $rid ], $this->requests[ $rid ], $this->request_bytes[ $rid ] );
				}
			}
			$this->line_count = 0;
		}
	}

	/**
	 * Append a line to $this->requests[$rid], respecting line and byte caps.
	 * Returns true if appended, false if cap hit (caller may want to stop merging).
	 *
	 * @param string $rid  Request ID.
	 * @param string $line Raw JSON line.
	 * @return bool True if stored, false if dropped due to cap.
	 */
	private function append_tracked_line( string $rid, string $line ): bool {
		$line_bytes = \strlen( $line );
		$cur_bytes  = $this->request_bytes[ $rid ] ?? 0;
		if ( $cur_bytes + $line_bytes > self::MAX_BYTES_PER_REQUEST ) {
			return false;
		}
		if ( isset( $this->requests[ $rid ] ) && \count( $this->requests[ $rid ] ) >= self::MAX_LINES_PER_REQUEST ) {
			return false;
		}
		$this->requests[ $rid ][]      = $line;
		$this->request_bytes[ $rid ]   = $cur_bytes + $line_bytes;
		return true;
	}

	/**
	 * Output remaining incomplete requests.
	 */
	private function output_remaining(): void {
		foreach ( $this->requests as $rid => $lines ) {
			$this->output_request( $lines );
			echo "[incomplete]\n\n";
		}
	}

	/**
	 * Output a completed request.
	 *
	 * @param array $lines Raw JSON lines.
	 */
	private function output_request( array $lines ): void {
		if ( $this->raw ) {
			// Stream each line directly — never implode into a single giant
			// string, since a matched long-running request can hold many MB
			// of lines and implode would allocate a contiguous copy.
			foreach ( $lines as $line ) {
				echo $line . "\n";
			}
			echo "\n";
			return;
		}

		// Reset formatting state.
		$this->fmt_indent         = 0;
		$this->fmt_last_number    = 0;
		$this->fmt_last_timestamp = 0;

		foreach ( $lines as $line ) {
			$entry = \json_decode( $line, true, 64 );
			if ( ! \is_array( $entry ) ) {
				echo $line . "\n";
				continue;
			}
			echo $this->format_entry( $entry ) . "\n";
		}
		echo "\n";
	}

	/**
	 * Format a log entry for display with indentation.
	 *
	 * @param array $entry Decoded JSON entry.
	 * @return string Formatted output line.
	 */
	private function format_entry( array $entry ): string {
		$number = $entry['n'] ?? 0;
		$ts     = $entry['ts'] ?? 0;
		$key    = $entry['k'] ?? '';

		// Decrease indent on (complete).
		if ( \strpos( $key, '(complete)' ) !== false ) {
			$this->fmt_indent -= 4;
		}
		if ( $this->fmt_indent < 0 ) {
			$this->fmt_indent = 0;
		}

		$output = '';

		// New request separator if message number reset.
		if ( $number < $this->fmt_last_number ) {
			$this->fmt_indent         = 0;
			$this->fmt_last_timestamp = 0;
			$output .= "\n    " . \str_repeat( '#', 60 ) . "\n\n";
		}

		// Timestamp - only show when changes (to 0.1s resolution).
		$time_str = '';
		if ( (int) ( $ts * 10 ) > (int) ( $this->fmt_last_timestamp * 10 ) ) {
			$tenth    = (int) ( ( $ts - \floor( $ts ) ) * 10 );
			$time_str = \gmdate( 'Y-m-d H:i:s', (int) $ts ) . ".{$tenth}";

			// Print dots for elapsed seconds using escalating intervals so a
			// multi-hour or multi-day gap doesn't blow up memory. First 10 rows
			// at 1s, next 10 at 10s, next 10 at 100s, etc. — O(log gap) rows.
			// Mirrors the JS placeholder logic in logEntryUtils.js.
			if ( $this->fmt_last_timestamp ) {
				$last_sec = (int) $this->fmt_last_timestamp;
				$curr_sec = (int) $ts;
				if ( $curr_sec > $last_sec + 1 ) {
					$interval    = 1;
					$rows_at_iv  = 0;
					$s           = $last_sec + 1;
					while ( $s < $curr_sec ) {
						$dot_time = \gmdate( 'Y-m-d H:i:s', $s ) . ".{$tenth}";
						$output  .= \sprintf( "%4d: %22s %s.\n", $number, $dot_time, \str_repeat( ' ', $this->fmt_indent ) );
						++$rows_at_iv;
						if ( $rows_at_iv >= 10 ) {
							$interval  *= 10;
							$rows_at_iv = 0;
							// Jump to next interval-aligned boundary.
							$s = ( \intdiv( $s, $interval ) + 1 ) * $interval;
						} else {
							$s += $interval;
						}
					}
				}
			}
		}

		// Build message: m value + duration + peak_mb if present.
		$msg = isset( $entry['m'] ) ? ( \is_array( $entry['m'] ) ? \wp_json_encode( $entry['m'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $entry['m'] ) : '';
		$suffix = '';
		if ( isset( $entry['duration_ms'] ) ) {
			$suffix .= ' (' . \number_format( $entry['duration_ms'], 2 ) . 'ms)';
		}
		if ( isset( $entry['peak_mb'] ) ) {
			$suffix .= ' [' . $entry['peak_mb'] . 'MB]';
		}

		// Format: MSGNUM: TIMESTAMP INDENT KEY:MSG
		// For multiline messages, pad continuation lines to align with the
		// start of the message content (after the key: prefix).
		$prefix = \sprintf(
			"%4d: %22s %s%s:",
			$number,
			$time_str,
			\str_repeat( ' ', $this->fmt_indent ),
			$key
		);

		if ( \str_contains( $msg, "\n" ) ) {
			$pad   = \str_repeat( ' ', \strlen( $prefix ) );
			$lines = \explode( "\n", $msg );
			$msg   = $lines[0];
			for ( $i = 1, $c = \count( $lines ); $i < $c; $i++ ) {
				$msg .= "\n" . $pad . $lines[ $i ];
			}
		}

		$output .= $prefix . $msg . $suffix;

		// Increase indent on (start).
		if ( \strpos( $key, '(start)' ) !== false ) {
			$this->fmt_indent += 4;
		}

		// Synthesize request_id line after process (start).
		if ( 1 === $number ) {
			$output .= "\n" . \sprintf( "%4d: %22s %srequest_id:%s", 1, '', \str_repeat( ' ', $this->fmt_indent ), $entry['rid'] ?? '' );
		}

		$this->fmt_last_number    = $number;
		$this->fmt_last_timestamp = $ts;

		return \rtrim( $output, "\n" );
	}
}
