<?php
/**
 * Inflight Tracker
 *
 * Tracks in-flight requests for real-time UI display.
 * Simplified state machine for SSE streams. Maintains request state/what for
 * Gyroscope dashboard. Does NOT build profiles - that's RequestBuilder's job.
 *
 * Named to distinguish from InstrumentalityGrail.pm which does full request
 * reconstruction with profiling. RequestBuilder is the PHP equivalent of that.
 *
 * @package Newspack_Performance_Gyroscope
 */

namespace Newspack_Performance_Gyroscope;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inflight tracker class.
 */
class InflightTracker {

	/**
	 * Security: Unbounded memory growth protection (Pattern 40).
	 */
	private const MAX_REQUESTS    = 10000;
	private const MAX_COMPLETED   = 5000;
	private const MAX_STACK_DEPTH = 100;
	private const STALE_TIMEOUT   = 300;

	private $requests  = [];
	private $completed = [];
	private $skip_urls = [ '/firehose/gyroscope' ];

	/**
	 * Pre-compiled regex patterns (Efficiency Principle 7: Pre-compute at Setup).
	 */
	private const REGEX_REQUEST     = '/^(GET|POST|PUT|DELETE|PATCH)\s+(.+)$/';
	private const REGEX_REMOTE_ADDR = '/^REMOTE_ADDR => "(.+)"$/';
	private const REGEX_USER_AGENT  = '/^HTTP_USER_AGENT => "(.+)"$/';
	private const REGEX_START       = '/^(.+?) \(start\)$/';
	private const REGEX_COMPLETE    = '/^(.+?) \(complete\)$/';

	public function process( array $entry ): void {
		$rid = $entry['rid'] ?? null;
		if ( ! $rid ) {
			return;
		}

		$keyword = $entry['k'] ?? '';
		$message = $entry['m'] ?? '';
		$ts      = $entry['ts'] ?? 0;

		if ( 'request' === $keyword && $message && \preg_match( self::REGEX_REQUEST, $message, $m ) ) {
			$url = $m[2];
			foreach ( $this->skip_urls as $skip ) {
				if ( false !== \strpos( $url, $skip ) ) {
					return;
				}
			}
			// Security: Limit request tracking to prevent unbounded memory growth (Pattern 40).
			if ( \count( $this->requests ) >= self::MAX_REQUESTS ) {
				\array_shift( $this->requests );
			}
			$this->requests[ $rid ] = [
				'rid'         => $rid,
				'method'      => $m[1],
				'url'         => $url,
				'start_time'  => $ts,
				'last_log_ts' => $ts,
				'tracker_ts'  => \microtime( true ),
				'state'       => 'process',
				'what'        => '',
				'stack'       => [ [ 'process', '' ] ],
				'remote_addr' => '',
				'user_agent'  => '',
			];
			return;
		}

		if ( ! isset( $this->requests[ $rid ] ) ) {
			return;
		}

		$request                = &$this->requests[ $rid ];
		$request['last_log_ts'] = $ts;
		$request['tracker_ts']  = \microtime( true );

		if ( 'environment_v2' === $keyword && $message ) {
			if ( \preg_match( self::REGEX_REMOTE_ADDR, $message, $m ) ) {
				$request['remote_addr'] = $m[1];
			} elseif ( \preg_match( self::REGEX_USER_AGENT, $message, $m ) ) {
				$request['user_agent'] = $m[1];
			}
			return;
		}

		if ( 'process (complete)' === $keyword ) {
			$request['state']       = 'complete';
			$request['duration_ms'] = $entry['duration_ms'] ?? 0;
			$request['status_code'] = $entry['status_code'] ?? 0;
			$request['end_time']    = $ts;
			// Security: Limit completed request buffer to prevent unbounded memory growth (Pattern 40).
			if ( \count( $this->completed ) >= self::MAX_COMPLETED ) {
				\array_shift( $this->completed );
			}
			$this->completed[]      = $request;
			unset( $this->requests[ $rid ] );
			return;
		}

		$label  = $keyword;
		$action = null;
		if ( \preg_match( self::REGEX_START, $keyword, $m ) ) {
			$label  = $m[1];
			$action = 'start';
		} elseif ( \preg_match( self::REGEX_COMPLETE, $keyword, $m ) ) {
			$label  = $m[1];
			$action = 'complete';
		}

		if ( 'start' === $action ) {
			// Security: Limit stack depth to prevent unbounded memory growth (Pattern 40).
			if ( \count( $request['stack'] ) < self::MAX_STACK_DEPTH ) {
				$request['stack'][] = [ $label, $message ];
			}
			$request['state']        = $label;
			$request['what']         = $message;
		}

		if ( 'complete' === $action ) {
			for ( $i = \count( $request['stack'] ) - 1; $i >= 0; $i-- ) {
				if ( $request['stack'][ $i ][0] === $label ) {
					\array_splice( $request['stack'], $i );
					break;
				}
			}
			$top              = \end( $request['stack'] ) ?: [ 'process', '' ];
			$request['state'] = $top[0];
			$request['what']  = $top[1];
		}
	}

	public function get_active( float $since_update = 0 ): array {
		$now     = \microtime( true );
		$timeout = self::STALE_TIMEOUT;
		$result  = [];

		foreach ( $this->requests as $rid => $req ) {
			if ( $now - $req['tracker_ts'] > $timeout ) {
				unset( $this->requests[ $rid ] );
				continue;
			}
			if ( $since_update > 0 && $req['tracker_ts'] < $since_update ) {
				continue;
			}

			$time_ms = ( $req['last_log_ts'] - $req['start_time'] ) * 1000;
			$age_ms  = ( $now - $req['tracker_ts'] ) * 1000;

			$result[] = [
				'rid'         => $req['rid'],
				'method'      => $req['method'],
				'url'         => $req['url'],
				'state'       => $req['state'],
				'what'        => $req['what'],
				'time_ms'     => \round( $time_ms, 1 ),
				'est_ms'      => \round( $time_ms + $age_ms, 1 ),
				'start_time'  => $req['start_time'],
				'last_log_ts' => $req['last_log_ts'],
				'lag_ms'      => \round( ( $req['tracker_ts'] - $req['last_log_ts'] ) * 1000, 1 ),
				'remote_addr' => $req['remote_addr'],
				'user_agent'  => $req['user_agent'],
			];
		}

		\usort( $result, fn( $a, $b ) => $b['est_ms'] <=> $a['est_ms'] );
		return $result;
	}

	public function get_completed(): array {
		$result          = $this->completed;
		$this->completed = [];
		return $result;
	}

}
