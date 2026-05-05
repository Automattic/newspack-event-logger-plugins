<?php
/**
 * Stream Merger
 *
 * Per-partition worker that multiplexes SSE connections from all configured remote servers.
 * Uses a single shared curl_multi handle for true multiplexing across all connections.
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator\Cron;

use Newspack_Event_Aggregator\ServerRegistry;
use Newspack_Event_Aggregator\SSEClient;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Event_Logger\Cron\LogReader;
use Newspack_Event_Logger\Memcached;
use Newspack_Event_Logger\WorkerBase;

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_init
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_exec
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_select
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_info_read
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_close
// Note: curl_multi is required for true SSE multiplexing.

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stream Merger class.
 */
class StreamMerger extends WorkerBase {

	/**
	 * Commit interval in seconds.
	 */
	private const COMMIT_INTERVAL_S = 5;

	/**
	 * Select timeout in seconds for curl_multi_select().
	 * Short timeout to allow checking for restarts and processing heartbeats.
	 */
	private const SELECT_TIMEOUT_S = 1.0;

	/**
	 * Heartbeat interval in seconds for verifying remote SSE slots.
	 */
	private const HEARTBEAT_INTERVAL = 15;

	/**
	 * SSE clients per server.
	 *
	 * @var array<string, SSEClient>
	 */
	private array $clients = [];

	/**
	 * Shared curl_multi handle for all SSE connections.
	 *
	 * @var \CurlMultiHandle|null
	 */
	private ?\CurlMultiHandle $multi_handle = null;

	/**
	 * Map of curl handles to server IDs (for identifying which client a handle belongs to).
	 *
	 * @var array<int, string>
	 */
	private array $handle_to_server = [];

	/**
	 * Single offsetlog for all servers.
	 *
	 * @var Firehose|null
	 */
	private ?Firehose $offsetlog = null;

	/**
	 * Local firehose to write aggregated events to.
	 *
	 * @var Firehose|null
	 */
	private ?Firehose $output = null;

	/**
	 * In-memory positions per server.
	 *
	 * @var array<string, array>
	 */
	private array $positions = [];

	/**
	 * Server registry.
	 *
	 * @var ServerRegistry|null
	 */
	private ?ServerRegistry $registry = null;

	/**
	 * Log base directory.
	 *
	 * @var string
	 */
	private string $log_base;

	/**
	 * Last commit timestamp.
	 *
	 * @var float
	 */
	private float $last_commit_time = 0.0;

	/**
	 * Last heartbeat sent timestamp per server.
	 *
	 * @var array<string, int>
	 */
	private array $heartbeat_sent = [];

	/**
	 * Constructor.
	 *
	 * @param int $partition Partition number.
	 */
	public function __construct( int $partition ) {
		$this->log_base = Config::get_logs_directory();

		$lock_dir = Config::get_locks_directory() . "/stream-merger.p{$partition}.lock.d";
		$this->init_worker( $lock_dir, $partition );
	}

	/**
	 * Run the worker.
	 */
	public function run(): void {
		$this->registry = ServerRegistry::get_instance();

		$this->init_multi_handle();
		$this->init_clients();
		$this->init_output();
		$this->init_offsetlog();
		$this->restore_offsets();

		$this->last_commit_time = \microtime( true );

		while ( ! $this->should_restart() ) {
			$this->ensure_connections();
			$this->poll_multi_handle();
			$this->process_events();
			$this->maybe_commit();
		}

		// Final commit before exit.
		$this->commit_all();
		$this->close_clients();
		$this->close_multi_handle();
	}

	/**
	 * Initialize the shared curl_multi handle.
	 */
	private function init_multi_handle(): void {
		$this->multi_handle = \curl_multi_init();
	}

	/**
	 * Close the shared curl_multi handle.
	 */
	private function close_multi_handle(): void {
		if ( null !== $this->multi_handle ) {
			\curl_multi_close( $this->multi_handle );
			$this->multi_handle = null;
		}
	}

	/**
	 * Initialize SSE clients for all enabled servers.
	 */
	private function init_clients(): void {
		$servers    = $this->registry->get_enabled();
		$config     = Config::load_config( 'full' );
		$verify_ssl = $config['aggregator_verify_ssl'] ?? true;
		$allow_http = $config['aggregator_allow_http'] ?? false;

		foreach ( $servers as $server_id => $server_config ) {
			$this->clients[ $server_id ] = new SSEClient(
				$server_id,
				$server_config['url'],
				$server_config['auth_username'] ?? '',
				$server_config['auth_password'] ?? '',
				$this->partition,
				$verify_ssl,
				$allow_http
			);
		}
	}

	/**
	 * Initialize local output firehose for writing.
	 */
	private function init_output(): void {
		$firehose_path = "{$this->log_base}/firehose.log";
		$this->output = new Firehose( $firehose_path, $this->partition );
	}

	/**
	 * Initialize single offsetlog per partition.
	 */
	private function init_offsetlog(): void {
		$offsetlog_path  = Config::get_offsets_directory() . "/remote_firehose.log.p{$this->partition}";
		$this->offsetlog = new Firehose(
			$offsetlog_path,
			0, // Single partition for offsetlog.
			LogReader::OFFSETLOG_SEGMENT_SIZE,
			LogReader::OFFSETLOG_NUM_SEGMENTS
		);
		$this->offsetlog->allow_large_writes();
	}

	/**
	 * Restore last committed positions for all servers.
	 */
	private function restore_offsets(): void {
		$segments = $this->offsetlog->get_segments( true );
		if ( empty( $segments ) ) {
			return;
		}

		// Read last segment to find latest positions.
		// Fall back to previous segment if newest is empty (just rotated).
		$last_seg = \end( $segments );
		$content  = $this->offsetlog->read_at( $last_seg['id'], 0, $last_seg['size'] );
		if ( empty( $content ) && \count( $segments ) > 1 ) {
			$prev_seg = $segments[ \count( $segments ) - 2 ];
			$content  = $this->offsetlog->read_at( $prev_seg['id'], 0, $prev_seg['size'] );
		}
		if ( empty( $content ) ) {
			return;
		}

		// Get last line (latest commit).
		$lines = \explode( "\n", \trim( $content ) );
		$last  = \json_decode( \end( $lines ), true, 64 );

		if ( ! \is_array( $last ) ) {
			return;
		}

		// Restore positions for each server.
		foreach ( $last as $server_id => $pos ) {
			if ( ! \is_array( $pos ) ) {
				continue;
			}
			$this->positions[ $server_id ] = [
				'segment_id' => $pos['seg'] ?? 0,
				'offset'     => $pos['off'] ?? 0,
			];
		}
	}

	/**
	 * Track which clients were connected last iteration.
	 *
	 * @var array<string, bool>
	 */
	private array $was_connected = [];

	/**
	 * Ensure all clients are connected and added to the shared multi handle.
	 */
	private function ensure_connections(): void {
		foreach ( $this->clients as $server_id => $client ) {
			$is_connected  = $client->is_connected();
			$was_connected = $this->was_connected[ $server_id ] ?? false;

			// Detect disconnection and remove stale handles.
			if ( $was_connected && ! $is_connected ) {
				$this->remove_from_multi( $server_id, $client );
				$http_code = $client->get_last_http_code();
				$error     = $client->get_last_error();
				$backoff   = $client->get_backoff();
				$this->update_connection_status( $server_id, 'disconnected', $http_code, $error, $backoff );
				// Clear stale heartbeat status from terminated connection.
				$this->clear_heartbeat_status( $server_id );
			}

			// Check for stale connections.
			// Save handle BEFORE check_stale() which internally calls close().
			// curl_multi_remove_handle must be called before curl_close.
			if ( $is_connected ) {
				$stale_handle = $client->get_handle();
				if ( $client->check_stale() ) {
					$this->remove_handle_from_multi( $server_id, $stale_handle );
					$http_code = $client->get_last_http_code();
					$error     = $client->get_last_error();
					$backoff   = $client->get_backoff();
					$this->update_connection_status( $server_id, 'disconnected', $http_code, $error, $backoff );
					// Clear stale heartbeat status from terminated connection.
					$this->clear_heartbeat_status( $server_id );
					$is_connected = false;
				}
			}

			$this->was_connected[ $server_id ] = $is_connected;

			// Reconnect if needed.
			if ( ! $is_connected ) {
				$position = $this->positions[ $server_id ] ?? null;
				if ( $client->connect( $position ) ) {
					$this->add_to_multi( $server_id, $client );
					$this->update_connection_status( $server_id, 'connecting' );
					// Clear stale heartbeat status from previous connection.
					$this->clear_heartbeat_status( $server_id );
				} else {
					// Still in backoff.
					$wait = $client->get_time_until_reconnect();
					if ( $wait > 0 ) {
						$backoff = $client->get_backoff();
						$error   = $client->get_last_error() ?? "Waiting {$backoff}s before retry";
						$this->update_connection_status( $server_id, 'backoff', null, $error, $backoff );
					}
				}
			}
		}
	}

	/**
	 * Add a client's curl handle to the shared multi handle.
	 *
	 * @param string    $server_id Server identifier.
	 * @param SSEClient $client    The SSE client.
	 */
	private function add_to_multi( string $server_id, SSEClient $client ): void {
		$handle = $client->get_handle();
		if ( null === $handle || null === $this->multi_handle ) {
			return;
		}

		$result = \curl_multi_add_handle( $this->multi_handle, $handle );
		if ( 0 !== $result ) {
			$client->close();
			return;
		}
		$this->handle_to_server[ (int) $handle ] = $server_id;
	}

	/**
	 * Remove a client's curl handle from the shared multi handle.
	 *
	 * @param string    $server_id Server identifier.
	 * @param SSEClient $client    The SSE client.
	 */
	private function remove_from_multi( string $server_id, SSEClient $client ): void {
		$handle = $client->get_handle();
		if ( null === $handle || null === $this->multi_handle ) {
			return;
		}

		\curl_multi_remove_handle( $this->multi_handle, $handle );
		unset( $this->handle_to_server[ (int) $handle ] );
	}

	/**
	 * Remove a pre-saved curl handle from the shared multi handle.
	 *
	 * Used when check_stale() has already closed the client's handle internally.
	 * The handle must be removed from multi BEFORE curl_close, but check_stale
	 * calls close() internally, so the caller must save the handle beforehand.
	 *
	 * @param string      $server_id Server identifier.
	 * @param \CurlHandle $handle    The cURL handle to remove.
	 */
	private function remove_handle_from_multi( string $server_id, \CurlHandle $handle ): void {
		if ( null === $this->multi_handle ) {
			return;
		}

		\curl_multi_remove_handle( $this->multi_handle, $handle );
		unset( $this->handle_to_server[ (int) $handle ] );
	}

	/**
	 * Poll the shared multi handle for activity on any connection.
	 *
	 * Uses curl_multi_select to efficiently wait for data on ALL connections at once.
	 */
	private function poll_multi_handle(): void {
		if ( null === $this->multi_handle ) {
			return;
		}

		// Wait for activity on any handle (with timeout).
		\curl_multi_select( $this->multi_handle, self::SELECT_TIMEOUT_S );

		// Process any pending data on all handles (loop until no more immediate work).
		$running = 0;
		do {
			$status = \curl_multi_exec( $this->multi_handle, $running );
		} while ( CURLM_CALL_MULTI_PERFORM === $status );

		// Check for completed/failed handles.
		while ( false !== ( $info = \curl_multi_info_read( $this->multi_handle ) ) ) {
			if ( CURLMSG_DONE !== $info['msg'] ) {
				continue;
			}

			$handle    = $info['handle'];
			$handle_id = (int) $handle;
			$server_id = $this->handle_to_server[ $handle_id ] ?? null;

			if ( null === $server_id || ! isset( $this->clients[ $server_id ] ) ) {
				continue;
			}

			$client = $this->clients[ $server_id ];
			$client->handle_completion( $info['result'] );
			$this->remove_from_multi( $server_id, $client );
			$client->close();
			$this->was_connected[ $server_id ] = false;

			$http_code = $client->get_last_http_code();
			$error     = $client->get_last_error();
			$backoff   = $client->get_backoff();
			$this->update_connection_status( $server_id, 'disconnected', $http_code, $error, $backoff );
			// Clear stale heartbeat status from terminated connection.
			$this->clear_heartbeat_status( $server_id );
		}
	}

	/**
	 * Process queued events from all connected clients.
	 */
	private function process_events(): void {
		foreach ( $this->clients as $server_id => $client ) {
			if ( ! $client->is_connected() ) {
				continue;
			}

			// Read all queued events first (to process "connected" and set heartbeat_sent).
			while ( null !== ( $event = $client->read_event() ) ) {
				if ( 'connected' === $event['type'] ) {
					$http_code = $client->get_last_http_code();
					$this->update_connection_status( $server_id, 'connected', $http_code, '' );
					// New connection counts as a successful heartbeat (slot just acquired).
					$this->record_successful_heartbeat( $server_id );
					$this->heartbeat_sent[ $server_id ] = \time();
				}

				if ( 'heartbeat' === $event['type'] && \is_array( $event['data'] ) ) {
					// Track server-side heartbeat timestamp from SSE stream.
					$ts = $event['data']['ts'] ?? null;
					$this->update_sse_heartbeat( $server_id, \is_numeric( $ts ) ? (int) $ts : \time() );
				}

				if ( 'entry' === $event['type'] && \is_array( $event['data'] )
					&& isset( $event['data']['k'] ) && \is_string( $event['data']['k'] ) ) {
					// Validate remote event data structure.
					if ( ! isset( $event['data']['ts'] ) || ! \is_numeric( $event['data']['ts'] ) ) {
						continue;
					}
					$event['data']['_source'] = $server_id;
					$line = \wp_json_encode( $event['data'] );
					// Enforce max payload size (PIPE_BUF - overhead).
					if ( false === $line || \strlen( $line ) > 3900 ) {
						continue;
					}

					/**
					 * Filter an aggregated log line before writing to the firehose.
					 *
					 * Return null to drop the line. Handlers should be fast (no I/O)
					 * since this runs in the Stream Merger hot path.
					 *
					 * @param string|null $line      JSON-encoded log entry, or null if already dropped.
					 * @param string      $server_id Source server identifier.
					 * @param int         $partition Target partition number.
					 */
					$line = \apply_filters( 'newspack_event_aggregator_ingest_line', $line, $server_id, $this->partition );

					if ( \is_string( $line ) && '' !== $line ) {
						if ( false === $this->output->write( $line ) ) {
							continue; // Write failed — don't advance position; retry next cycle.
						}
					}
					// Advance position after successful write or intentional drop (null/false from filter).
					$this->positions[ $server_id ] = $client->get_position();
				}
			}

			// Send HTTP heartbeat AFTER processing events (so "connected" sets heartbeat_sent first).
			$this->maybe_send_heartbeat( $server_id, $client );
		}
	}

	/**
	 * Commit positions periodically.
	 */
	private function maybe_commit(): void {
		$now = \microtime( true );
		if ( $now - $this->last_commit_time < self::COMMIT_INTERVAL_S ) {
			return;
		}

		$this->commit_all();
		$this->last_commit_time = $now;
	}

	/**
	 * Commit all positions atomically to offsetlog.
	 */
	private function commit_all(): void {
		if ( empty( $this->positions ) ) {
			return;
		}

		$entry = [];
		foreach ( $this->positions as $server_id => $pos ) {
			$entry[ $server_id ] = [
				'seg' => $pos['segment_id'] ?? 0,
				'off' => $pos['offset'] ?? 0,
			];
		}

		$entry['_ts'] = \time();
		$json = \wp_json_encode( $entry );
		if ( false !== $json ) {
			$this->offsetlog->write( $json );
		}
	}

	/**
	 * Close all SSE clients.
	 */
	private function close_clients(): void {
		foreach ( $this->clients as $server_id => $client ) {
			$this->remove_from_multi( $server_id, $client );
			$client->close();
		}
		$this->clients         = [];
		$this->handle_to_server = [];
	}

	/**
	 * Send HTTP heartbeat to verify remote SSE slot is still valid.
	 *
	 * @param string    $server_id Server identifier.
	 * @param SSEClient $client    SSE client for the server.
	 */
	private function maybe_send_heartbeat( string $server_id, SSEClient $client ): void {
		$now  = \time();
		$last = $this->heartbeat_sent[ $server_id ] ?? 0;

		if ( $now - $last < self::HEARTBEAT_INTERVAL ) {
			return;
		}

		$slot = $client->get_slot();
		if ( null === $slot || $slot < 0 ) {
			return;
		}

		$this->heartbeat_sent[ $server_id ] = $now;
		$servers                            = $this->registry->get_enabled();
		$server                             = $servers[ $server_id ] ?? null;
		if ( null === $server ) {
			return;
		}

		$config     = Config::load_config( 'full' );
		$verify_ssl = $config['aggregator_verify_ssl'] ?? true;

		// Build auth headers.
		$headers  = [];
		$username = $server['auth_username'] ?? '';
		$password = $server['auth_password'] ?? '';
		if ( '' !== $username && '' !== $password ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$headers['Authorization'] = 'Basic ' . \base64_encode( $username . ':' . $password );
		}

		$start    = \microtime( true );
		$response = \wp_remote_post(
			$server['url'] . '/wp-json/event-logger/v1/firehose/heartbeat',
			[
				'headers'             => $headers,
				'body'                => [
					'slot'       => $slot,
					'aggregator' => true,
					'partition'  => $this->partition,
				],
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Cross-server heartbeat needs longer timeout.
				'timeout'             => 10,
				'sslverify'           => $verify_ssl,
				'redirection'         => 0, // REST endpoints should not redirect.
				'limit_response_size' => 1048576, // 1MB max response.
			]
		);
		$rtt = ( \microtime( true ) - $start ) * 1000;

		$this->update_heartbeat_status( $server_id, $response, $rtt );
	}

	/**
	 * Record a new connection as a successful heartbeat.
	 *
	 * When a connection is established, the slot was just acquired/verified,
	 * so we treat this as a successful heartbeat to show consistent status.
	 *
	 * @param string $server_id Server identifier.
	 */
	private function record_successful_heartbeat( string $server_id ): void {
		$key = "aggregator_status:{$server_id}:p{$this->partition}";
		Memcached::init( Config::load_config()['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );
		$existing = Memcached::get( $key ) ?: [];

		$now  = \time();
		$data = [
			'last_heartbeat_sent'            => $now,
			'last_heartbeat_response'        => $now,
			'last_heartbeat_rtt'             => 0, // Instant - no HTTP round-trip needed.
			'last_heartbeat_response_status' => 'success',
			'last_heartbeat_error'           => null,
		];

		Memcached::set( $key, \array_merge( $existing, $data ), 300 );
	}

	/**
	 * Update SSE heartbeat timestamp from server.
	 *
	 * Tracks the last heartbeat event received over the SSE stream.
	 *
	 * @param string $server_id Server identifier.
	 * @param int    $timestamp Server timestamp from heartbeat event.
	 */
	private function update_sse_heartbeat( string $server_id, int $timestamp ): void {
		$key = "aggregator_status:{$server_id}:p{$this->partition}";
		Memcached::init( Config::load_config()['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );
		$existing = Memcached::get( $key ) ?: [];

		$data = [
			'last_sse_heartbeat' => $timestamp,
		];

		Memcached::set( $key, \array_merge( $existing, $data ), 300 );
	}

	/**
	 * Clear heartbeat status when starting a new connection.
	 *
	 * Prevents showing stale SUCCESS/ERROR from previous connection.
	 *
	 * @param string $server_id Server identifier.
	 */
	private function clear_heartbeat_status( string $server_id ): void {
		$key = "aggregator_status:{$server_id}:p{$this->partition}";
		Memcached::init( Config::load_config()['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );
		$existing = Memcached::get( $key ) ?: [];

		$data = [
			'last_heartbeat_sent'            => null,
			'last_heartbeat_response'        => null,
			'last_heartbeat_rtt'             => null,
			'last_heartbeat_response_status' => 'pending',
			'last_heartbeat_error'           => null,
			'last_sse_heartbeat'             => null,
		];

		Memcached::set( $key, \array_merge( $existing, $data ), 300 );
	}

	/**
	 * Update connection status in memcache.
	 *
	 * @param string      $server_id Server identifier.
	 * @param string      $status    Connection status.
	 * @param int|null    $http_code HTTP response code if available (omit to preserve existing).
	 * @param string|null $error     Error message if any (omit to preserve existing).
	 * @param int|null    $backoff   Current backoff in seconds.
	 */
	private function update_connection_status( string $server_id, string $status, ?int $http_code = null, ?string $error = null, ?int $backoff = null ): void {
		$key  = "aggregator_status:{$server_id}:p{$this->partition}";
		$data = [
			'last_connection_attempt' => \time(),
			'last_connection_status'  => $status,
		];
		// Only update response/error when explicitly provided (preserve from previous attempt).
		if ( null !== $http_code ) {
			$data['last_connection_response'] = $http_code;
		}
		if ( null !== $error ) {
			$data['last_connection_error'] = $error;
		}
		if ( null !== $backoff ) {
			$data['current_backoff'] = $backoff;
		}
		Memcached::init( Config::load_config()['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );
		$existing = Memcached::get( $key ) ?: [];
		Memcached::set( $key, \array_merge( $existing, $data ), 300 );
	}

	/**
	 * Update heartbeat status in memcache.
	 *
	 * @param string                $server_id Server identifier.
	 * @param array|\WP_Error       $response  HTTP response or WP_Error.
	 * @param float                 $rtt       Round-trip time in milliseconds.
	 */
	private function update_heartbeat_status( string $server_id, $response, float $rtt ): void {
		$key      = "aggregator_status:{$server_id}:p{$this->partition}";
		Memcached::init( Config::load_config()['memcache_servers'] ?? Memcached::DEFAULT_SERVERS );
		$existing = Memcached::get( $key ) ?: [];

		$status = 'success';
		$error  = null;

		// Parse response for status display (informational only).
		// Server will terminate connection if slot expires - client doesn't act on this.
		if ( \is_wp_error( $response ) ) {
			$status = 'error';
			$error  = $response->get_error_message();
		} else {
			$code = \wp_remote_retrieve_response_code( $response );
			$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 16 );

			if ( 200 !== $code ) {
				$status = 'error';
				$error  = "HTTP {$code}";
			} elseif ( isset( $body['success'] ) && ! $body['success'] ) {
				$status = 'slot_expired';
				$error  = $body['error'] ?? 'Slot no longer valid';
			}
		}

		$data = [
			'last_heartbeat_sent'            => $this->heartbeat_sent[ $server_id ],
			'last_heartbeat_response'        => \time(),
			'last_heartbeat_rtt'             => \round( $rtt, 2 ),
			'last_heartbeat_response_status' => $status,
			'last_heartbeat_error'           => $error,
		];

		Memcached::set( $key, \array_merge( $existing, $data ), 300 );
	}
}
