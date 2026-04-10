<?php
/**
 * Request Builder
 *
 * Handler that builds request profiles from firehose entries.
 * Registered via newspack_event_logger_log_readers filter, called by LogReader.
 *
 * @package Newspack_Performance_Workers
 */

namespace Newspack_Performance_Workers\Cron;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\LruCache;
use Newspack_Performance_Workers\StatsStore;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request builder handler class.
 */
class RequestBuilder {

	/**
	 * Maximum stack depth before request is considered runaway and evicted.
	 * Matches json_decode depth limit (64) with headroom.
	 */
	private const MAX_STACK_DEPTH = 50;

	/**
	 * Maximum entries stored per request (for the detail view Log Entries table).
	 */
	private const MAX_ENTRIES_PER_REQUEST = 50000;

	/**
	 * Max stored message length per entry. Truncate long values (filter args,
	 * callback lists) to keep in-flight request memory bounded.
	 */
	private const MAX_ENTRY_MESSAGE_LENGTH = 1024;

	/**
	 * Max raw payload length for URL/process-start extraction.
	 */
	private const MAX_PAYLOAD_SCAN_LENGTH = 8192;

	/**
	 * Bucket rotation interval in seconds.
	 * 3 buckets x 200s = 600s (10 min) before oldest bucket is evicted.
	 */
	private const BUCKET_ROTATION_S = 200;

	/**
	 * Initialize handler context.
	 *
	 * @param array      $context     Context array (passed by reference).
	 * @param array|null $saved_state Restored state from previous run.
	 */
	public static function init( array &$context, ?array $saved_state ): void {
		$partition = (int) $context['partition'];
		$log_base  = $context['log_base'];

		// Validate path components from context.
		if ( false !== \strpos( $log_base, '..' ) ) {
			return;
		}

		// Initialize LRU cache for in-flight requests (100 per bucket, 3 buckets).
		// Time-based rotation every 200s — oldest bucket evicted after ~600s (10 min).
		$context['request_cache'] = ( new LruCache( 100, 3 ) )
			->with_timed_rotation(
				self::BUCKET_ROTATION_S,
				function ( string $rid, $request ) use ( &$context ): void {
					self::evict_request( $context, $rid, $request );
				}
			);

		// Restore in-flight requests from saved state.
		if ( \is_array( $saved_state ) && isset( $saved_state['request_cache'] ) ) {
			$context['request_cache']->restore_state( $saved_state['request_cache'] );
		}

		// Create requests firehose for output with companion index.
		$context['requests_log'] = ( new Firehose( "{$log_base}/requests.log", $partition ) )
			->allow_large_writes()
			->with_index( [ self::class, 'format_index_entry' ] );

		// Create errors firehose — errors and warnings forwarded here for quick browsing.
		$context['errors_log'] = new Firehose( "{$log_base}/errors.log", $partition );

		// Set up state callbacks.
		self::set_state_callbacks( $context );
	}

	/**
	 * Set up state callbacks for different entry keywords.
	 *
	 * @param array $context Context array.
	 */
	private static function set_state_callbacks( array &$context ): void {
		$s = [];

		$s['process (start)'] = function ( array &$request, array $entry ): void {
			$payload = $entry['m'] ?? '';
			if ( \is_array( $payload ) ) {
				$payload = $payload['m'] ?? '';
			}
			if ( \is_string( $payload ) && strlen( $payload ) < self::MAX_ENTRY_MESSAGE_LENGTH && \preg_match( '/^(\d+) on (\S+)/', $payload, $m ) ) {
				$request['process_id'] = $m[1];
				$request['host']       = $m[2];
			}
			$request['timestamp']   = $entry['ts'] ?? \microtime( true );
			$request['stack']       = [ 'process' ];
			$request['what_stack']  = [ '' ];
			$request['profiles']    = [];
			$request['entries']     = [];
			$request['state']       = 'process';
			$request['initialized'] = true;
		};

		$s['process (complete)'] = function ( array &$request, array $entry ): void {
			$request['duration_ms'] = $entry['duration_ms'] ?? 0;
			$request['status_code'] = $entry['status_code'] ?? 0;
			$error_status           = $entry['error_status'] ?? '-';
			if ( ! \is_string( $error_status ) || 1 !== \strlen( $error_status ) || ! \in_array( $error_status, [ '-', 'F', 'T' ], true ) ) {
				$error_status = '-';
			}
			$request['error_status'] = $error_status;
			$request['state']        = 'complete';
		};

		$s['request'] = function ( array &$request, array $entry ): void {
			$message = $entry['m'] ?? '';
			if ( \strlen( $message ) < self::MAX_PAYLOAD_SCAN_LENGTH && \preg_match( '/^(?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS|CLI)\s+(.+)$/', $message, $m ) ) {
				// Strip query string — URL hash already ignores it for merging,
				// and keeping it wastes memory and makes the URL table noisy.
				$request['url'] = \explode( '?', $m[1], 2 )[0];
			}
			$parts                     = \explode( ' ', $message, 2 );
			$request['request_method'] = $parts[0] ?? '';
		};

		$s['environment_v2'] = function ( array &$request, array $entry ): void {
			$message = $entry['m'] ?? '';
			if ( \strlen( $message ) > 8192 ) {
				return;
			}
			if ( \preg_match( '/^REMOTE_ADDR => "(.+)"$/', $message, $m ) ) {
				$ip = \trim( $m[1] );
				$request['remote_addr'] = \filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
			} elseif ( \preg_match( '/^HTTP_USER_AGENT => "(.+)"$/', $message, $m ) ) {
				$request['user_agent'] = $m[1];
			} elseif ( \preg_match( '/^HTTP_X_FORWARDED_FOR => "(.+)"$/', $message, $m ) ) {
				if ( empty( $request['remote_addr'] ) ) {
					$parts = \explode( ',', $m[1], 2 );
					$ip    = \trim( $parts[0] );
					if ( \filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						$request['remote_addr'] = $ip;
					}
				}
			} elseif ( \preg_match( '/^SERVER_NAME => "(.+)"$/', $message, $m ) ) {
				$request['server_name'] = $m[1];
			} elseif ( \preg_match( '/^GEOIP_COUNTRY_CODE => "(.+)"$/', $message, $m ) ) {
				$request['country_code'] = $m[1];
			} elseif ( \preg_match( '/^HTTP_FROM => "(.+)"$/', $message, $m ) ) {
				$request['http_from'] = $m[1];
			} elseif ( \preg_match( '/^HTTP_X_JA4_HASH => "(.+)"$/', $message, $m ) ) {
				$request['ja4_hash'] = $m[1];
			} elseif ( \preg_match( '/^EVENT_LOGGER_WORKER_TYPE => ".+"$/', $message ) ) {
				$request['is_worker'] = true;
			}
		};

		$s['worker_type'] = function ( array &$request, array $entry ): void {
			$request['is_worker'] = true;
		};

		$s['memory'] = function ( array &$request, array $entry ): void {
			$m = $entry['m'] ?? [];
			if ( \is_array( $m ) && isset( $m['peak'] ) ) {
				$request['peak_mb'] = (float) $m['peak'];
			}
		};

		$context['state_callbacks'] = $s;
	}

	/**
	 * Process a single line from firehose.log.
	 *
	 * @param string $line      Raw JSONL line.
	 * @param string $input     Input log name (firehose.log).
	 * @param array  $context   Context array (passed by reference).
	 */
	public static function process( string $line, string $input, array &$context ): void {
		$context['request_cache']->rotate_if_due();

		$entry = \json_decode( $line, true, 64 );
		if ( ! \is_array( $entry ) || \json_last_error() !== JSON_ERROR_NONE ) {
			return;
		}

		$rid = $entry['rid'] ?? null;
		if ( ! \is_string( $rid ) || '' === $rid ) {
			return;
		}

		// Intern keyword strings — json_decode allocates a new string per entry,
		// but most entries share the same ~200 unique keywords. Interning makes
		// all identical strings share one zval, saving ~80 bytes per entry.
		static $intern = [];
		$keyword       = $entry['k'] ?? '';
		if ( ! \is_string( $keyword ) ) {
			return;
		}
		if ( \strlen( $keyword ) <= 256 && \count( $intern ) < 50000 ) {
			$keyword = $intern[ $keyword ] ??= $keyword;
		}
		$n = $entry['n'] ?? 0;

		$request = $context['request_cache']->get( $rid ) ?? [];
		$request['rid'] = $rid;

		if ( empty( $request['initialized'] ) ) {
			if ( 'process (start)' !== $keyword ) {
				return;
			}
		}

		// Forward errors and warnings to errors.log.
		if ( 'error' === $keyword || 'warning' === $keyword
			|| \str_ends_with( $keyword, '(error)' )
			|| \str_ends_with( $keyword, '(warning)' )
		) {
			$context['errors_log']->write( $line );
		}

		if ( isset( $context['state_callbacks'][ $keyword ] ) ) {
			$context['state_callbacks'][ $keyword ]( $request, $entry );
		} elseif ( \str_ends_with( $keyword, ' (start)' ) ) {
			$what = $entry['m'] ?? '';
			self::push_stack( $request, \substr( $keyword, 0, -8 ), \is_string( $what ) ? $what : '' );
		} elseif ( \str_ends_with( $keyword, ' (complete)' ) ) {
			self::pop_stack( $request, \substr( $keyword, 0, -11 ), $entry['duration_ms'] ?? 0, $entry['ts'] ?? 0 );
		}

		// Evict runaway requests immediately.
		if ( $request['is_runaway'] ?? false ) {
			$context['request_cache']->delete( $rid );
			return;
		}

		if ( isset( $request['entries'] ) && \count( $request['entries'] ) < self::MAX_ENTRIES_PER_REQUEST ) {
			$stored = [
				'n'  => $n,
				'ts' => $entry['ts'] ?? 0,
				'k'  => $keyword,
			];

			// Truncate 'm' to bound per-entry memory.
			$m = $entry['m'] ?? '';
			if ( \is_string( $m ) && \strlen( $m ) > self::MAX_ENTRY_MESSAGE_LENGTH ) {
				$m = \substr( $m, 0, self::MAX_ENTRY_MESSAGE_LENGTH );
			} elseif ( \is_array( $m ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode overhead unnecessary for throwaway length check.
				$encoded_check = \json_encode( $m );
				if ( false !== $encoded_check && \strlen( $encoded_check ) > self::MAX_ENTRY_MESSAGE_LENGTH ) {
					$m = '';
				}
			}
			$stored['m'] = $m;

			if ( isset( $entry['l'] ) ) {
				$stored['l'] = $entry['l'];
			}
			if ( isset( $entry['duration_ms'] ) ) {
				$stored['duration_ms'] = $entry['duration_ms'];
			}
			if ( isset( $entry['peak_mb'] ) ) {
				$stored['peak_mb'] = $entry['peak_mb'];
			}

			$request['entries'][] = $stored;
		} elseif ( isset( $request['entries'] ) && empty( $request['truncated'] ) ) {
			$request['truncated'] = true;
		}

		if ( 'complete' === ( $request['state'] ?? '' ) ) {
			// Write immediately to get state out of RAM.
			if ( ! empty( $request['url'] ) ) {
				$context['requests_log']->write( \wp_json_encode( $request ), $request );
			}
			$context['request_cache']->delete( $rid );
		} else {
			$context['request_cache']->set( $rid, $request );
		}
	}

	/**
	 * Push state onto request stack.
	 *
	 * @param array  $request Request data.
	 * @param string $state   State name.
	 * @param string $what    What identifier.
	 */
	private static function push_stack( array &$request, string $state, string $what ): void {
		if ( ! isset( $request['stack'] ) ) {
			$request['stack']      = [ 'process' ];
			$request['what_stack'] = [ '' ];
			$request['profiles']   = [];
		}

		if ( ! isset( $request['profiles'][ $state ] ) ) {
			$request['profiles'][ $state ] = [
				'entries' => [],
				'count'   => 0,
				'time'    => 0,
				'ts'      => 0,
			];
		}

		$request['stack'][]      = $state;
		$request['what_stack'][] = $what;

		$profile = &$request['profiles'][ $state ];
		if ( \count( $profile['entries'] ) < 1000 && ! isset( $profile['entries'][ $what ] ) ) {
			$profile['entries'][ $what ] = [ 0, 0 ];
		}

		if ( \count( $request['stack'] ) > self::MAX_STACK_DEPTH ) {
			$request['is_runaway'] = true;
		}
	}

	/**
	 * Pop state from request stack.
	 *
	 * @param array  $request Request data.
	 * @param string $label   State label.
	 * @param float  $time    Duration in ms.
	 * @param float  $ts      Timestamp.
	 */
	private static function pop_stack( array &$request, string $label, float $time, float $ts = 0 ): void {
		if ( $request['is_runaway'] ?? false ) {
			return;
		}

		if ( empty( $request['stack'] ) ) {
			return;
		}

		$found_idx = false;
		for ( $i = \count( $request['stack'] ) - 1; $i >= 0; $i-- ) {
			if ( $request['stack'][ $i ] === $label ) {
				$found_idx = $i;
				break;
			}
		}
		if ( false === $found_idx ) {
			return;
		}

		$state                 = $request['stack'][ $found_idx ];
		$what                  = $request['what_stack'][ $found_idx ] ?? '';
		$request['stack']      = \array_slice( $request['stack'], 0, $found_idx );
		$request['what_stack'] = \array_slice( $request['what_stack'], 0, $found_idx );

		if ( isset( $request['profiles'][ $state ] ) ) {
			$profile          = &$request['profiles'][ $state ];
			$profile['time'] += $time;
			++$profile['count'];
			$profile['ts'] = \max( $profile['ts'], $ts );

			if ( $what && isset( $profile['entries'][ $what ] ) ) {
				$profile['entries'][ $what ][0] += $time;
				++$profile['entries'][ $what ][1];
			}
		}

		// Subtract child time from ancestors to avoid double-counting.
		// Callbacks (contain " @N") are breakdowns of their parent hook's time,
		// so callback completion does NOT subtract from the hook.
		// Non-callback children subtract from BOTH the callback (if inside one)
		// AND the callback's parent hook.
		if ( ! empty( $request['stack'] ) && ! self::is_callback_state( $state ) ) {
			for ( $j = \count( $request['stack'] ) - 1; $j >= 0; $j-- ) {
				$ancestor = $request['stack'][ $j ];
				if ( 'process' === $ancestor ) {
					break;
				}
				if ( isset( $request['profiles'][ $ancestor ] ) ) {
					$request['profiles'][ $ancestor ]['time'] -= $time;

					$ancestor_what = $request['what_stack'][ $j ] ?? '';
					if ( $ancestor_what && isset( $request['profiles'][ $ancestor ]['entries'][ $ancestor_what ] ) ) {
						$request['profiles'][ $ancestor ]['entries'][ $ancestor_what ][0] -= $time;
					}
					// If we just subtracted from a callback, continue to also
					// subtract from its parent hook. Stop after the first
					// non-callback ancestor.
					if ( ! self::is_callback_state( $ancestor ) ) {
						break;
					}
				}
			}
		}
	}

	/**
	 * Check if a state label is a callback (ends with " @N").
	 *
	 * @param string $state State label.
	 * @return bool True if callback state.
	 */
	private static function is_callback_state( string $state ): bool {
		$at_pos = \strrpos( $state, ' @' );
		return false !== $at_pos && \ctype_digit( \substr( $state, $at_pos + 2 ) );
	}

	/**
	 * Handle a single evicted request from LRU bucket rotation.
	 *
	 * Incomplete requests get written with error_status=T.
	 * Called by the LruCache eviction callback.
	 *
	 * @param array  $context Handler context.
	 * @param string $rid     Request ID.
	 * @param mixed  $request Request data.
	 */
	private static function evict_request( array &$context, string $rid, $request ): void {
		if ( ! \is_array( $request ) || empty( $request['url'] ) ) {
			return;
		}
		if ( 'complete' === ( $request['state'] ?? '' ) ) {
			return;
		}
		$now                     = \time();
		$start_ts                = (int) ( $request['timestamp'] ?? $now );
		$request['error_status'] = 'T';
		$request['duration_ms']  = ( $now - $start_ts ) * 1000;
		$request['status_code']  = $request['status_code'] ?? 0;
		$request['state']        = 'complete';
		$context['requests_log']->write( \wp_json_encode( $request ), $request );
	}

	/**
	 * Flush handler (no-op — completed requests are written immediately in process()).
	 *
	 * @param array $context Context array.
	 */
	public static function flush( array &$context ): void {
	}

	/**
	 * Save state for persistence.
	 *
	 * Persists the full request cache (including entries and profiles)
	 * so in-flight requests retain trace data across worker restarts.
	 * Orphan eviction is handled by LRU bucket rotation.
	 *
	 * @param array $context Context array.
	 * @return array State to persist.
	 */
	public static function save_state( array &$context ): array {
		if ( ! isset( $context['request_cache'] ) ) {
			return [];
		}
		return [
			'request_cache' => $context['request_cache']->get_state(),
		];
	}

	/**
	 * Format index entry callback for Firehose::with_index().
	 *
	 * @param string     $line     The JSON line written.
	 * @param array      $position Position array.
	 * @param array|null $data     Pre-decoded data (avoids re-parsing $line).
	 * @return string|null Index entry or null.
	 */
	public static function format_index_entry( string $line, array $position, ?array &$data = null ): ?string {
		$request = $data ?? \json_decode( $line, true, 64 );
		if ( ! \is_array( $request ) || empty( $request['url'] ) ) {
			return null;
		}

		$rid          = $request['rid'] ?? '';
		$url_hash     = self::url_hash( $request['url'] );
		$timestamp    = (int) ( $request['timestamp'] ?? \time() );
		$duration_ms  = (int) ( $request['duration_ms'] ?? 0 );
		$status_code  = (int) ( $request['status_code'] ?? 0 );
		$peak_mb      = (float) ( $request['peak_mb'] ?? 0 );
		$segment_id   = $position['segment_id'];
		$offset       = $position['offset'];
		$length       = $position['length'];
		$error_status = $request['error_status'] ?? '-';

		if ( $offset > 9999999999 || $length > 99999999 || $segment_id > 999999 ) {
			return '';
		}

		// peak_mb: 6 chars, integer MB zero-padded (max 999999 MB).
		$peak_mb_int = \min( (int) \round( $peak_mb ), 999999 );

		// method: 1 char code for HTTP method.
		static $method_codes = [
			'GET'     => 'G',
			'POST'    => 'P',
			'HEAD'    => 'H',
			'DELETE'  => 'D',
			'PUT'     => 'U',
			'PATCH'   => 'A',
			'OPTIONS' => 'O',
			'CLI'     => 'C',
		];
		$method = $method_codes[ $request['request_method'] ?? 'GET' ] ?? 'G';

		return \str_pad( \substr( $rid, 0, 32 ), 32 )
			. \str_pad( \substr( $url_hash, 0, 12 ), 12 )
			. \str_pad( (string) $timestamp, 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) \min( $duration_ms, 99999999 ), 8, '0', STR_PAD_LEFT )
			. \str_pad( (string) \min( $status_code, 999 ), 3, '0', STR_PAD_LEFT )
			. \str_pad( (string) $segment_id, 6, '0', STR_PAD_LEFT )
			. \str_pad( (string) $offset, 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) $length, 8, '0', STR_PAD_LEFT )
			. \str_pad( (string) $peak_mb_int, 6, '0', STR_PAD_LEFT )
			. $method
			. $error_status;
	}

	/**
	 * FNV-1a 32-bit hash.
	 *
	 * @param string $str  Input string.
	 * @param int    $seed Offset basis.
	 * @return int 32-bit hash.
	 */
	private static function fnv1a32( string $str, int $seed = 2166136261 ): int {
		$hash = $seed;
		$len  = \strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$hash ^= \ord( $str[ $i ] );
			$hash  = ( $hash * 16777619 ) & 0xFFFFFFFF;
		}
		return $hash;
	}

	/**
	 * URL hash - 12-char FNV-1a hash.
	 *
	 * @param string $url URL to hash.
	 * @return string 12-character hex hash.
	 */
	public static function url_hash( string $url ): string {
		$str   = \explode( '?', $url, 2 )[0] ?: $url;
		$hash1 = self::fnv1a32( $str );
		$hash2 = self::fnv1a32( $str, $hash1 ^ 0x811c9dc5 );
		return \sprintf( '%08x%04x', $hash1, $hash2 & 0xFFFF );
	}

	/**
	 * Parse request index entry.
	 *
	 * @param string $line Index line.
	 * @return array|null Parsed entry or null.
	 */
	public static function parse_request_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		$len  = \strlen( $line );

		if ( $len >= 89 ) {
			$entry = [
				'rid'         => \trim( \substr( $line, 0, 32 ) ),
				'url_hash'    => \trim( \substr( $line, 32, 12 ) ),
				'timestamp'   => (int) \substr( $line, 44, 10 ),
				'duration_ms' => (int) \substr( $line, 54, 8 ),
				'status_code' => (int) \substr( $line, 62, 3 ),
				'segment_id'  => (int) \substr( $line, 65, 6 ),
				'offset'      => (int) \substr( $line, 71, 10 ),
				'length'      => (int) \substr( $line, 81, 8 ),
			];

			// peak_mb field appended in v2 format (position 89, 6 chars).
			if ( $len >= 95 ) {
				$entry['peak_mb'] = (int) \substr( $line, 89, 6 );
			}

			// method field appended in v3 format (position 95, 1 char).
			if ( $len >= 96 ) {
				static $methods = [
					'G' => 'GET',
					'P' => 'POST',
					'H' => 'HEAD',
					'D' => 'DELETE',
					'U' => 'PUT',
					'A' => 'PATCH',
					'O' => 'OPTIONS',
					'C' => 'CLI',
				];
				$entry['method'] = $methods[ \substr( $line, 95, 1 ) ] ?? \substr( $line, 95, 1 );
			}

			// error_status field appended in v4 format (position 96, 1 char).
			if ( $len >= 97 ) {
				$c = \substr( $line, 96, 1 );
				if ( 'F' === $c || 'T' === $c ) {
					$entry['error_status'] = $c;
				}
			}

			return $entry;
		}

		return null;
	}
}
