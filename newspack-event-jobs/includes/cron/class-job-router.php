<?php
/**
 * Job Router Handler
 *
 * Routes job entries from firehose.log and jobintake.log to jobs.log.
 * Handler class for LogReader infrastructure.
 *
 * Job sources:
 * - firehose.log: Extracts entries with k='job' from request lifecycle
 * - jobintake.log: Direct job entries from JobIntake API
 *
 * SECURITY NOTES:
 * - Handler names validated against strict pattern
 * - Parameters size limited to prevent DoS
 * - All validation happens before writing to jobs.log
 *
 * @package Newspack_Event_Jobs
 */

namespace Newspack_Event_Jobs\Cron;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Router handler class.
 */
class JobRouter {

	/**
	 * Valid handler name pattern (must match JobWorker).
	 */
	private const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

	/**
	 * Maximum parameters size in bytes.
	 */
	private const MAX_JOB_SIZE = 10485760;

	/**
	 * Maximum JSON decode depth.
	 */
	private const MAX_JSON_DEPTH = 64;

	/**
	 * Initialize handler context.
	 *
	 * @param array      $context     Context array (passed by reference).
	 * @param array|null $saved_state Saved state from previous run, or null.
	 */
	public static function init( array &$context, ?array $saved_state ): void {
		$log_base     = Config::get_logs_directory();
		$partition    = $context['partition'];

		// Create jobs output firehose.
		$context['jobs_log'] = ( new Firehose( "{$log_base}/jobs.log", $partition ) )
			->allow_large_writes();

		// Queue for batching writes.
		$context['queue'] = [];
	}

	/**
	 * Process a single log line.
	 *
	 * @param string $line      Raw log line.
	 * @param string $input     Input log name ('firehose.log' or 'jobintake.log').
	 * @param array  $context   Handler context (passed by reference).
	 */
	public static function process( string $line, string $input, array &$context ): void {
		if ( 'firehose.log' === $input ) {
			self::process_firehose_entry( $line, $context );
		} elseif ( 'jobintake.log' === $input ) {
			self::process_jobintake_entry( $line, $context );
		}
	}

	/**
	 * Process firehose entry - extract job entries.
	 *
	 * Uses strpos pre-filter to skip json_decode on non-job lines (>99% of firehose traffic).
	 *
	 * @param string $line    Raw log line.
	 * @param array  $context Handler context.
	 */
	private static function process_firehose_entry( string $line, array &$context ): void {
		// Fast reject: skip lines that don't contain a job keyword.
		if ( false === \strpos( $line, '"k":"job"' ) && false === \strpos( $line, '"k":"remote_job"' ) ) {
			return;
		}

		$entry = \json_decode( $line, true, self::MAX_JSON_DEPTH );
		if ( ! \is_array( $entry ) || \json_last_error() !== JSON_ERROR_NONE ) {
			return;
		}

		// Confirm keyword after decode (strpos is a heuristic — value could match in a message field).
		if ( 'job' !== ( $entry['k'] ?? '' ) && 'remote_job' !== ( $entry['k'] ?? '' ) ) {
			return;
		}

		// The message field contains the handler name and parameters.
		$message = $entry['m'] ?? [];
		if ( ! \is_array( $message ) ) {
			return;
		}

		$validated = self::validate_job( $entry['k'], $message );
		if ( null !== $validated ) {
			$context['queue'][] = $validated;
		}
	}

	/**
	 * Process jobintake entry - validate and pass through.
	 *
	 * JobIntake entries are already well-formed JSON written by the JobIntake API.
	 * Validate handler name against HANDLER_NAME_PATTERN before writing to jobs.log.
	 *
	 * @param string $line    Raw log line.
	 * @param array  $context Handler context.
	 */
	private static function process_jobintake_entry( string $line, array &$context ): void {
		// Size guard — reject oversized payloads.
		if ( \strlen( $line ) > self::MAX_JOB_SIZE ) {
			return;
		}

		// Validate handler name before writing to jobs.log.
		$entry = \json_decode( $line, true, self::MAX_JSON_DEPTH );
		if ( ! \is_array( $entry ) || \json_last_error() !== JSON_ERROR_NONE ) {
			return;
		}

		$handler = $entry['handler'] ?? '';
		if ( ! \is_string( $handler ) || ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			return;
		}

		$context['jobs_log']->write( \rtrim( $line, "\n" ) );
	}

	/**
	 * Validate job entry.
	 *
	 * @param string $type Job type - "job" or "remote_job".
	 * @param array  $job  Job entry with handler and parameters.
	 * @return array|null Validated job entry, or null if invalid.
	 */
	private static function validate_job( string $type, array $job ): ?array {
		// Validate handler name exists and matches safe pattern.
		$handler = $job['handler'] ?? '';
		if ( ! \is_string( $handler ) || ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			return null;
		}

		// Validate parameters is array.
		$parameters = $job['parameters'] ?? [];
		if ( ! \is_array( $parameters ) ) {
			return null;
		}

		return [
			'type'       => $type,
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => $job['ts'] ?? \microtime( true ),
		];
	}

	/**
	 * Save handler state.
	 *
	 * @param array $context Handler context.
	 * @return array State array for persistence.
	 */
	public static function save_state( array &$context ): array {
		// No persistent state needed - jobs are written immediately.
		return [];
	}

	/**
	 * Flush queued jobs to output.
	 *
	 * @param array $context Handler context.
	 */
	public static function flush( array &$context ): void {
		$jobs_log = $context['jobs_log'] ?? null;
		if ( ! $jobs_log ) {
			return;
		}

		foreach ( $context['queue'] as $job ) {
			$jobs_log->write( \wp_json_encode( $job ) );
		}
		$context['queue'] = [];
	}

	/**
	 * Cleanup handler resources.
	 *
	 * @param array $context Handler context.
	 */
	public static function cleanup( array &$context ): void {
		self::flush( $context );
		$context['jobs_log'] = null;
	}
}
