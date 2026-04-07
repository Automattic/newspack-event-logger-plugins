<?php
/**
 * Firehose Reader
 *
 * Streaming reader for Firehose segmented logs.
 * Pure reader — no persistence. Position tracking (offsetlogs) is
 * managed by callers like LogReader and StreamMerger.
 *
 * @package Event_Logger
 */

namespace Newspack_Event_Logger;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Firehose reader class.
 */
class FirehoseReader {

	/**
	 * Maximum line buffer size (10MB).
	 *
	 * Protects against DoS from malformed files without newlines.
	 * Firehose lines are JSON event records, typically < 4KB.
	 */
	private const MAX_LINE_BUFFER_SIZE = 20971520; // 20MB.

	private Firehose $firehose;
	private int $segment_id       = 0;
	private int $offset           = 0;
	private ?array $current_segment = null;
	private array $segments         = [];
	/** @var resource|null */
	private $fh = null;
	private string $line_buffer = '';
	private bool $at_eof        = true;

	/**
	 * Constructor.
	 *
	 * @param Firehose $firehose       The firehose to read from.
	 * @param string   $default_offset Where to start: 'start', 'recent', or 'end'.
	 */
	public function __construct( Firehose $firehose, string $default_offset = 'start' ) {
		$this->firehose = $firehose;
		$this->next_offset( $default_offset );
	}

	public function __destruct() {
		$this->close();
	}

	/**
	 * Set next read position.
	 *
	 * Like Consumer.pm's next_offset - accepts magic values or explicit position.
	 *
	 * @param string|array $position 'start', 'recent', 'end', or ['segment_id' => int, 'offset' => int].
	 */
	public function next_offset( $position ): void {
		// Close any open file and reset state.
		$this->close();
		$this->current_segment = null;
		$this->line_buffer     = '';
		$this->at_eof          = false;

		if ( \is_array( $position ) ) {
			// Explicit position.
			$this->segment_id = $position['segment_id'] ?? 0;
			$this->offset     = $position['offset'] ?? 0;
			// Validate offset is not negative (reject values < -1).
			if ( $this->offset < -1 ) {
				$this->offset = -1;
			}
			return;
		}

		// Magic values - need current segment list.
		$this->refresh_segments();

		switch ( $position ) {
			case 'end':
				// End of newest segment.
				if ( ! empty( $this->segments ) ) {
					$newest           = \end( $this->segments );
					$this->segment_id = $newest['id'];
					$this->offset     = $newest['size'];
				}
				break;

			case 'recent':
				// Start of second-to-last segment (or oldest if only one).
				// This ensures we don't miss commits if newest segment just rotated.
				if ( ! empty( $this->segments ) ) {
					$count = \count( $this->segments );
					if ( $count >= 2 ) {
						$second_last      = $this->segments[ $count - 2 ];
						$this->segment_id = $second_last['id'];
					} else {
						$this->segment_id = $this->segments[0]['id'];
					}
					$this->offset = 0;
				}
				break;

			case 'start':
			default:
				// Beginning (segment 0, offset 0).
				$this->segment_id = 0;
				$this->offset     = 0;
				break;
		}
	}

	/**
	 * Update internal offset from file handle position.
	 *
	 * Call after fgets() when not using read_line().
	 */
	public function update_offset(): void {
		if ( $this->fh ) {
			$this->offset = \ftell( $this->fh );
		}
	}

	public function get_segment_id(): int {
		return $this->segment_id;
	}

	/**
	 * Get current position as array.
	 *
	 * @return array{segment_id: int, offset: int}
	 */
	public function get_position(): array {
		return [
			'segment_id' => $this->segment_id,
			'offset'     => $this->offset,
		];
	}

	/**
	 * Refresh the list of available segments from disk.
	 */
	public function refresh_segments(): void {
		$this->segments = $this->firehose->get_segments();
	}

	/**
	 * Find segment by ID in segments array.
	 *
	 * @param int $segment_id Segment ID to find.
	 * @return array|null Segment array or null if not found.
	 */
	private function find_segment_by_id( int $segment_id ): ?array {
		foreach ( $this->segments as $seg ) {
			if ( $seg['id'] === $segment_id ) {
				return $seg;
			}
		}
		return null;
	}

	/**
	 * Safely open segment file.
	 *
	 * Opens segment file using path from Firehose::get_segment_path().
	 * Base directory is validated canonical at Firehose construction,
	 * and segment paths are safe (int ID + fixed format).
	 *
	 * @param int $segment_id Segment ID to open.
	 * @return resource|null File handle if opened, null on error.
	 */
	private function open_segment( int $segment_id ) {
		$segment_path = $this->firehose->get_segment_path( $segment_id );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		return @\fopen( $segment_path, 'r' ) ?: null;
	}

	/**
	 * Open a filehandle for reading from current position.
	 * Returns null when no data available.
	 *
	 * @return resource|null
	 */
	public function open() {
		$this->refresh_segments();
		if ( empty( $this->segments ) ) {
			return null;
		}

		$segment = $this->find_segment_by_id( $this->segment_id );

		// If segment doesn't exist (deleted), jump to oldest available.
		if ( null === $segment ) {
			$segment          = $this->segments[0];
			$this->segment_id = $segment['id'];
			$this->offset     = 0;
		}

		// Already have this segment open.
		if ( null !== $this->current_segment && $this->current_segment['id'] === $segment['id'] ) {
			return $this->fh;
		}

		$this->close();
		$this->fh = $this->open_segment( $segment['id'] );
		if ( $this->fh ) {
			$this->current_segment = $segment;
			if ( $this->offset > 0 ) {
				\fseek( $this->fh, $this->offset );
			}
		}
		return $this->fh;
	}

	/**
	 * Mark reader as at EOF.
	 *
	 * For callers using fgets() directly instead of read_line().
	 * Call when fgets() returns false and feof() confirms EOF.
	 */
	public function mark_eof(): void {
		$this->at_eof = true;
	}

	/**
	 * Check if reader is caught up (at EOF on newest segment).
	 *
	 * Relies on at_eof being set by read_line() or mark_eof().
	 *
	 * @return bool True if at EOF on newest segment.
	 */
	public function is_caught_up(): bool {
		if ( ! $this->at_eof ) {
			return false;
		}
		// At EOF - check if we're on the newest segment.
		$this->refresh_segments();
		if ( empty( $this->segments ) ) {
			return true;
		}
		$newest = \end( $this->segments );
		return $this->segment_id >= $newest['id'];
	}

	/**
	 * Move to next segment.
	 *
	 * Returns filehandle or null on error. Use is_caught_up() to check
	 * if at end of stream.
	 *
	 * @return resource|null
	 */
	public function next_segment() {
		$this->refresh_segments();
		if ( empty( $this->segments ) ) {
			return null;
		}

		// Find next segment ID.
		$next_id = $this->segment_id + 1;
		$segment = $this->find_segment_by_id( $next_id );

		// Detect firehose reset: next segment doesn't exist AND current segment is gone.
		// This happens after rm -rf when new writers create fresh segments starting at 0.
		if ( null === $segment && null === $this->find_segment_by_id( $this->segment_id ) ) {
			$this->close();
			$oldest                = $this->segments[0];
			$this->current_segment = $oldest;
			$this->segment_id      = $oldest['id'];
			$this->offset          = 0;
			$this->line_buffer     = '';
			$this->at_eof          = false;
			$this->fh              = $this->open_segment( $oldest['id'] );
			return $this->fh;
		}

		// Don't move to next segment if current is still being written or has unread data.
		if ( null !== $segment && null !== $this->current_segment && $this->fh ) {
			$current_path = $this->firehose->get_segment_path( $this->current_segment['id'] );
			\clearstatcache( true, $current_path );

			$file_size  = @\filesize( $current_path );
			$read_pos   = \ftell( $this->fh );
			$mtime      = @\filemtime( $current_path );
			$stale_secs = $mtime ? ( \time() - $mtime ) : PHP_INT_MAX;

			// If current segment was recently written, wait for it to finish.
			if ( $stale_secs < 1 ) {
				$segment = null;
			} elseif ( false !== $file_size && $read_pos < $file_size ) {
				// There's unread data in current segment.
				// If segment is stale (not modified in 5+ seconds) AND next segment exists,
				// the unread bytes are likely incomplete/corrupt - skip them and move on.
				// Otherwise, stay on current segment to read the remaining data.
				if ( $stale_secs < 5 ) {
					$segment = null; // Still fresh, more data may be coming.
				}
				// else: stale segment with unread data - allow moving to next segment.
			}
		}

		if ( null === $segment ) {
			// No next segment yet, or current still has data.
			// Refresh position in current file (may have new data).
			if ( $this->fh ) {
				\fseek( $this->fh, 0, SEEK_CUR ); // Clears PHP EOF flag for next fgets/fread.
			}
			return $this->fh;
		}

		// Move to next segment.
		$this->close();
		$this->current_segment = $segment;
		$this->segment_id      = $next_id;
		$this->offset          = 0;
		$this->line_buffer     = '';
		$this->at_eof          = false;
		$this->fh              = $this->open_segment( $segment['id'] );
		return $this->fh;
	}

	/**
	 * Read a complete line from the firehose.
	 *
	 * Handles partial lines correctly by buffering until newline.
	 * Returns null when no complete line is available.
	 *
	 * @return string|null Complete line (with newline) or null.
	 */
	public function read_line(): ?string {
		if ( ! $this->fh ) {
			return null;
		}

		// Check buffer first for complete line.
		$nl = \strpos( $this->line_buffer, "\n" );
		if ( false !== $nl ) {
			$line              = \substr( $this->line_buffer, 0, $nl + 1 );
			$this->line_buffer = \substr( $this->line_buffer, $nl + 1 );
			$this->offset      = \ftell( $this->fh ) - \strlen( $this->line_buffer );
			return $line;
		}

		// Refresh PHP's buffer and read more data.
		\fseek( $this->fh, 0, SEEK_CUR );
		$data = \fread( $this->fh, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

		if ( false === $data || '' === $data ) {
			$this->at_eof = true;
			return null;
		}
		$this->at_eof = false;

		// Check if appending would exceed buffer limit (DoS protection).
		if ( \strlen( $this->line_buffer ) + \strlen( $data ) > self::MAX_LINE_BUFFER_SIZE ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				\sprintf(
					'FirehoseReader: Line buffer limit exceeded (%d bytes) at segment %d offset %d - discarding buffer',
					self::MAX_LINE_BUFFER_SIZE,
					$this->segment_id,
					$this->offset
				)
			);
			// Discard buffered data and skip forward to next newline boundary
			// to avoid returning corrupt partial lines on subsequent reads.
			$this->offset += \strlen( $this->line_buffer );
			$this->line_buffer = '';

			// Search for newline in current chunk first.
			$nl = \strpos( $data, "\n" );
			if ( false !== $nl ) {
				$this->offset     += $nl + 1;
				$this->line_buffer = \substr( $data, $nl + 1 );
				return null;
			}

			// No newline in current chunk - keep reading until we find one.
			$this->offset += \strlen( $data );
			while ( ! \feof( $this->fh ) ) {
				\fseek( $this->fh, 0, SEEK_CUR );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$skip_data = \fread( $this->fh, 65536 );
				if ( false === $skip_data || '' === $skip_data ) {
					break;
				}
				$nl = \strpos( $skip_data, "\n" );
				if ( false !== $nl ) {
					$this->offset     += $nl + 1;
					$this->line_buffer = \substr( $skip_data, $nl + 1 );
					break;
				}
				$this->offset += \strlen( $skip_data );
			}
			return null;
		}

		$this->line_buffer .= $data;

		// Check again for complete line.
		$nl = \strpos( $this->line_buffer, "\n" );
		if ( false !== $nl ) {
			$line              = \substr( $this->line_buffer, 0, $nl + 1 );
			$this->line_buffer = \substr( $this->line_buffer, $nl + 1 );
			$this->offset      = \ftell( $this->fh ) - \strlen( $this->line_buffer );
			return $line;
		}

		// Have data but no complete line yet - not at EOF.
		return null;
	}

	/**
	 * Close the current file handle.
	 */
	public function close(): void {
		if ( $this->fh ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			\fclose( $this->fh );
			$this->fh = null;
		}
	}
}
