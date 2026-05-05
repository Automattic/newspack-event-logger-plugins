<?php
/**
 * Job Worker Handler
 *
 * Consumes jobs.log and dispatches to registered handlers.
 * Handler class for LogReader infrastructure.
 *
 * Handlers are registered via the newspack_event_logger_job_handlers filter.
 * Each job specifies a handler name and parameters.
 *
 * SECURITY NOTES:
 * - Handler names must match [a-zA-Z0-9_-]+ pattern (validated here)
 * - Parameters are validated for type/size but handlers MUST validate content
 * - Only handlers registered via newspack_event_logger_job_handlers filter are callable
 *
 * @package Newspack_Event_Jobs
 */

namespace Newspack_Event_Jobs\Cron;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Worker handler class.
 */
class JobWorker {

	/**
	 * Maximum JSON decode depth to prevent stack exhaustion.
	 */
	private const MAX_JSON_DEPTH = 64;

	/**
	 * Maximum job size in bytes.
	 */
	private const MAX_JOB_SIZE = 10485760;

	/**
	 * Valid handler name pattern (alphanumeric, underscore, hyphen).
	 */
	private const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

	/**
	 * Flush object cache every N jobs to prevent memory growth.
	 */
	private const CACHE_FLUSH_INTERVAL = 50;

	/**
	 * Set up a fresh request context for a job execution.
	 *
	 * Resets the current LogManager session, configures $_SERVER with
	 * a unique request ID and job-specific paths, so the next
	 * LogManager::instance() picks up meaningful context.
	 *
	 * @param string $name Job name used for PATH_INFO and REQUEST_URI.
	 * @return array Original $_SERVER for restoration via end_job_context().
	 */
	public static function begin_job_context( string $name ): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserving original value for restoration.
		$orig_server = $_SERVER;

		// Suspend parent LogManager (preserves its state on the context stack).
		\Newspack_Performance_Logger\LogManager::suspend();

		// Set up environment for this job execution.
		$name        = \ltrim( $name, '/' );
		$path_info   = '/' . $name;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used for internal context only
		$server_name = $_SERVER['SERVER_NAME'] ?? '';

		$_SERVER['UNIQUE_ID']       = \Newspack_Performance_Logger\LogManager::generate_request_id();
		$_SERVER['REQUEST_URI']     = '/jobs/' . $name;
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_SERVER['PATH_INFO']       = $path_info;
		$_SERVER['SCRIPT_NAME']     = $path_info;
		$_SERVER['SCRIPT_URL']      = $path_info;
		$_SERVER['SCRIPT_URI']      = 'https://' . $server_name . $path_info;
		$_SERVER['SCRIPT_FILENAME'] = ( \defined( 'NEWSPACK_FOUNDATION_BASE' ) ? NEWSPACK_FOUNDATION_BASE : '' ) . '/template';
		$_SERVER['QUERY_STRING']    = '';
		unset( $_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH'] );
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'] );

		return $orig_server;
	}

	/**
	 * Restore request context after a job execution.
	 *
	 * @param array $orig_server Original $_SERVER from begin_job_context().
	 */
	public static function end_job_context( array $orig_server ): void {
		// Finish job LogManager and restore parent from context stack.
		\Newspack_Performance_Logger\LogManager::resume();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Restoring previously saved value.
		$_SERVER = $orig_server;
	}

	/**
	 * Get registered job handlers.
	 *
	 * @return array<string, callable> Handler name => callable.
	 */
	public static function get_registered_handlers(): array {
		$handlers = \apply_filters( 'newspack_event_logger_job_handlers', [] );
		if ( ! \is_array( $handlers ) ) {
			return [];
		}
		$valid = [];
		foreach ( $handlers as $name => $callable ) {
			if ( \is_string( $name ) && \is_callable( $callable ) ) {
				$valid[ $name ] = $callable;
			}
		}
		return $valid;
	}

	/**
	 * Get registered remote job handlers.
	 *
	 * @return array<string, callable> Handler name => callable.
	 */
	public static function get_registered_remote_handlers(): array {
		$handlers = \apply_filters( 'newspack_event_logger_remote_job_handlers', [] );
		if ( ! \is_array( $handlers ) ) {
			return [];
		}
		$valid = [];
		foreach ( $handlers as $name => $callable ) {
			if ( \is_string( $name ) && \is_callable( $callable ) ) {
				$valid[ $name ] = $callable;
			}
		}
		return $valid;
	}

	/**
	 * Initialize handler context.
	 *
	 * @param array      $context     Context array (passed by reference).
	 * @param array|null $saved_state Saved state from previous run, or null.
	 */
	public static function init( array &$context, ?array $saved_state ): void {
		// Cache registered handlers.
		$context['handlers']        = self::get_registered_handlers();
		$context['remote_handlers'] = self::get_registered_remote_handlers();

		// Track jobs processed for cache flushing.
		$context['jobs_since_cache_flush'] = 0;
	}

	/**
	 * Process a single log line (job entry).
	 *
	 * @param string $line      Raw log line.
	 * @param string $input     Input log name ('jobs.log').
	 * @param array  $context   Handler context (passed by reference).
	 */
	public static function process( string $line, string $input, array &$context ): void {
		if ( \strlen( $line ) > self::MAX_JOB_SIZE ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( '[EventLogger] JobWorker: job exceeds size limit' );
			return;
		}

		// Decode with depth limit to prevent stack exhaustion.
		$job = \json_decode( $line, true, self::MAX_JSON_DEPTH );
		if ( ! \is_array( $job ) || \json_last_error() !== JSON_ERROR_NONE ) {
			return;
		}

		self::dispatch_job( $job, $context );

		// Force a GC cycle after each job. PHP's reference-counted GC can't
		// break cycles immediately, and image handlers (wp_generate_attachment_metadata
		// → WP_Image_Editor_GD) leave circular refs behind that otherwise
		// accumulate until WorkerBase's memory watermark forces a respawn.
		// Running gc_collect_cycles() here delays that trigger so each
		// process churns through more jobs before being recycled.
		\gc_collect_cycles();

		++$context['jobs_since_cache_flush'];

		// Flush object cache periodically to prevent memory growth from handler queries.
		if ( $context['jobs_since_cache_flush'] >= self::CACHE_FLUSH_INTERVAL ) {
			\wp_cache_flush();
			$context['jobs_since_cache_flush'] = 0;
		}
	}

	/**
	 * Dispatch a job to its handler.
	 *
	 * @param array $job     Job data with 'handler' and 'parameters'.
	 * @param array $context Handler context.
	 * @return bool True on success, false on failure.
	 */
	private static function dispatch_job( array $job, array &$context ): bool {
		$type         = $job['type'] ?? '';
		$handler_name = $job['handler'] ?? '';
		$parameters   = $job['parameters'] ?? [];

		// Validate handler name exists and matches safe pattern.
		if ( empty( $handler_name ) || ! \is_string( $handler_name ) ) {
			return false;
		}

		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler_name ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( '[EventLogger] JobWorker: Invalid handler name format: ' .
				\substr( \preg_replace( '/[\x00-\x1F\x7F]/', '', $handler_name ), 0, 64 ) );
			return false;
		}

		// Validate parameters is array and within size limit.
		if ( ! \is_array( $parameters ) ) {
			return false;
		}

		if ( 'remote_job' === $type ) {
			$handlers = $context['remote_handlers'] ?? [];
		} else {
			$handlers = $context['handlers'] ?? [];
		}

		if ( ! isset( $handlers[ $handler_name ] ) ) {
			return false;
		}

		$callable = $handlers[ $handler_name ];

		try {
			\call_user_func( $callable, $parameters );
			return true;
		} catch ( \Throwable $e ) {
			// Log sanitized exception for debugging (truncate message to prevent log injection).
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf(
				'[EventLogger] JobWorker: Handler %s failed: %s',
				$handler_name,
				\substr( \preg_replace( '/[\x00-\x1F\x7F]/', '', $e->getMessage() ), 0, 200 )
			) );
			return false;
		}
	}

	/**
	 * Save handler state.
	 *
	 * @param array $context Handler context.
	 * @return array State array for persistence.
	 */
	public static function save_state( array &$context ): array {
		// No persistent state needed.
		return [];
	}

	/**
	 * Flush any pending output.
	 *
	 * @param array $context Handler context.
	 */
	public static function flush( array &$context ): void {
		// JobWorker doesn't produce output.
	}

	/**
	 * Cleanup handler resources.
	 *
	 * @param array $context Handler context.
	 */
	public static function cleanup( array &$context ): void {
		// Clear handlers cache.
		$context['handlers'] = null;
	}
}
