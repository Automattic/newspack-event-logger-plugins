<?php
/**
 * SSE Client
 *
 * Non-blocking SSE client using cURL.
 * Connects to a remote Event Logger SSE endpoint and reads events.
 *
 * Features:
 * - Reconnect with exponential backoff (1s, 2s, 4s, max 30s)
 * - Resume from segment:offset position via URL params
 * - Heartbeat detection (stale if no event in 30s)
 * - Parse SSE event format (event:, data:, blank line = end)
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_close
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_errno
// Note: cURL is required for SSE streaming - wp_remote_get() doesn't support it.
// Multi-handle management is done by StreamMerger, not this class.

/**
 * SSE Client class.
 *
 * This client does NOT manage its own curl_multi handle.
 * The caller (StreamMerger) should manage a shared multi handle for all clients.
 */
class SSEClient {

	/**
	 * Maximum reconnect backoff in seconds.
	 */
	private const MAX_BACKOFF = 30;

	/**
	 * Initial backoff in seconds.
	 */
	private const INITIAL_BACKOFF = 1;

	/**
	 * Connection timeout in seconds.
	 * Reduced from default 10s to fail faster and allow more reconnect attempts.
	 */
	private const CONNECT_TIMEOUT = 5;

	/**
	 * Heartbeat timeout in seconds. Consider stale if no event in this time.
	 * Should be longer than server's SSE heartbeat interval (15s for aggregators).
	 */
	private const HEARTBEAT_TIMEOUT = 45;

	/**
	 * Maximum buffer size in bytes. Disconnect if a line exceeds this
	 * (no newline received). Prevents memory exhaustion from malicious servers.
	 */
	private const MAX_BUFFER_SIZE = 10485760; // 10MB.

	/**
	 * Maximum accumulated event data size in bytes. Disconnect if a single
	 * SSE event's data exceeds this (many data: lines without a blank line).
	 * Prevents memory exhaustion from malicious servers sending unlimited short data: lines.
	 */
	private const MAX_EVENT_SIZE = 10485760; // 10MB.

	/**
	 * Maximum number of events in the queue. Disconnect if the consumer
	 * falls behind and the queue grows beyond this. Prevents memory
	 * exhaustion from a fast producer / slow consumer.
	 */
	private const MAX_QUEUE_SIZE = 10000;

	/**
	 * Server ID for logging.
	 *
	 * @var string
	 */
	private string $server_id;

	/**
	 * Base URL for the SSE endpoint.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Auth username for Basic authentication.
	 *
	 * @var string
	 */
	private string $auth_username;

	/**
	 * Auth password for Basic authentication.
	 *
	 * @var string
	 */
	private string $auth_password;

	/**
	 * Partition number.
	 *
	 * @var int
	 */
	private int $partition;

	/**
	 * Whether to verify SSL certificates.
	 *
	 * @var bool
	 */
	private bool $verify_ssl;

	/**
	 * Whether to allow plain HTTP connections.
	 *
	 * @var bool
	 */
	private bool $allow_http;

	/**
	 * cURL handle.
	 *
	 * @var \CurlHandle|null
	 */
	private ?\CurlHandle $ch = null;

	/**
	 * Is the connection currently established?
	 *
	 * @var bool
	 */
	private bool $connected = false;

	/**
	 * Buffer for incomplete SSE data.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Current event being parsed.
	 *
	 * @var array
	 */
	private array $current_event = [
		'event' => '',
		'data'  => '',
	];

	/**
	 * Queue of complete events ready to be read.
	 *
	 * @var array
	 */
	private array $event_queue = [];

	/**
	 * Last position received from heartbeat.
	 *
	 * @var array
	 */
	private array $position = [
		'segment_id' => 0,
		'offset'     => 0,
	];

	/**
	 * SSE slot assigned by the remote server.
	 *
	 * @var int|null
	 */
	private ?int $slot = null;

	/**
	 * Time of last event received.
	 *
	 * @var float
	 */
	private float $last_event_time = 0.0;

	/**
	 * Current backoff for reconnection.
	 *
	 * @var int
	 */
	private int $current_backoff = self::INITIAL_BACKOFF;

	/**
	 * Time of last connection attempt.
	 *
	 * @var float
	 */
	private float $last_connect_attempt = 0.0;

	/**
	 * Last error message.
	 *
	 * @var string|null
	 */
	private ?string $last_error = null;

	/**
	 * Last HTTP response code.
	 *
	 * @var int|null
	 */
	private ?int $last_http_code = null;

	/**
	 * Constructor.
	 *
	 * @param string $server_id     Server identifier for logging.
	 * @param string $url           Base URL for the SSE endpoint.
	 * @param string $auth_username Auth username for Basic authentication.
	 * @param string $auth_password Auth password for Basic authentication.
	 * @param int    $partition     Partition number.
	 * @param bool   $verify_ssl    Whether to verify SSL certificates.
	 * @param bool   $allow_http    Whether to allow plain HTTP (default: false, HTTPS only).
	 */
	public function __construct( string $server_id, string $url, string $auth_username, string $auth_password, int $partition, bool $verify_ssl = true, bool $allow_http = false ) {
		$this->server_id     = $server_id;
		$this->url           = \rtrim( $url, '/' );
		$this->auth_username = $auth_username;
		$this->auth_password = $auth_password;
		$this->partition     = $partition;
		$this->verify_ssl    = $verify_ssl;
		$this->allow_http    = $allow_http;
	}

	/**
	 * Destructor - clean up cURL handle.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Connect to the SSE endpoint.
	 *
	 * @param array|null $position Optional position to resume from ['segment_id' => N, 'offset' => M].
	 * @return bool True if connection was initiated.
	 */
	public function connect( ?array $position = null ): bool {
		// Rate limit reconnection attempts using exponential backoff.
		$now = \microtime( true );
		if ( $now - $this->last_connect_attempt < $this->current_backoff ) {
			return false;
		}
		$this->last_connect_attempt = $now;

		$this->close();

		// Build URL with parameters.
		$endpoint = $this->url . '/wp-json/event-logger/v1/firehose/stream';
		$params   = [
			'partition'  => $this->partition,
			'aggregator' => 1, // Skip SSE slot management on remote.
		];

		if ( null !== $position ) {
			if ( isset( $position['segment_id'] ) ) {
				$params['segment_id'] = (int) $position['segment_id'];
			}
			if ( isset( $position['offset'] ) ) {
				$params['offset'] = (int) $position['offset'];
			}
		}

		$url = \add_query_arg( $params, $endpoint );

		// Initialize cURL. Assign to local first to avoid TypeError on ?\CurlHandle property.
		$ch = \curl_init();
		if ( ! $ch ) {
			return false;
		}
		$this->ch = $ch;

		// Build HTTP headers.
		$headers = [
			'Accept: text/event-stream',
			'Cache-Control: no-cache',
		];

		// Add Basic Auth header for WordPress Application Passwords.
		if ( '' !== $this->auth_username && '' !== $this->auth_password ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$headers[] = 'Authorization: Basic ' . \base64_encode( $this->auth_username . ':' . $this->auth_password );
		}

		// Configure cURL for SSE streaming.
		// FOLLOWLOCATION disabled: SSE endpoints should not redirect.
		// CURLOPT_PROTOCOLS restricted to HTTPS to prevent protocol downgrade.
		\curl_setopt_array(
			$this->ch,
			[
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 0, // No timeout - long-running connection.
				CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
				CURLOPT_WRITEFUNCTION  => [ $this, 'handle_data' ],
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
				CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
				CURLOPT_PROTOCOLS      => $this->allow_http ? ( CURLPROTO_HTTPS | CURLPROTO_HTTP ) : CURLPROTO_HTTPS,
			]
		);

		$this->buffer          = '';
		$this->current_event   = [ 'event' => '', 'data' => '' ];
		$this->event_queue     = [];
		$this->last_event_time = $now;
		$this->connected       = true;
		$this->last_error      = null;
		$this->last_http_code  = null;

		return true;
	}

	/**
	 * cURL write callback - receives data chunks.
	 *
	 * @param \CurlHandle $ch   cURL handle.
	 * @param string      $data Data chunk.
	 * @return int Number of bytes handled.
	 */
	public function handle_data( \CurlHandle $ch, string $data ): int {
		$len           = \strlen( $data );
		$this->buffer .= $data;

		// Prevent unbounded buffer growth from malicious/broken servers.
		if ( \strlen( $this->buffer ) > self::MAX_BUFFER_SIZE ) {
			$this->last_error = 'Buffer overflow (no newline in ' . self::MAX_BUFFER_SIZE . ' bytes)';
			$this->buffer     = '';
			$this->connected  = false;
			return 0; // Signal cURL to abort the transfer.
		}

		// Capture HTTP code on first data received.
		if ( null === $this->last_http_code ) {
			$this->last_http_code = \curl_getinfo( $ch, CURLINFO_HTTP_CODE ) ?: null;
		}

		// Parse complete lines. Check $this->connected each iteration so that
		// overflow detected in parse_sse_line() or dispatch_event() aborts promptly.
		while ( $this->connected && false !== ( $newline_pos = \strpos( $this->buffer, "\n" ) ) ) {
			$line         = \substr( $this->buffer, 0, $newline_pos );
			$this->buffer = \substr( $this->buffer, $newline_pos + 1 );

			// Remove carriage return if present.
			$line = \rtrim( $line, "\r" );

			$this->parse_sse_line( $line );
		}

		// If a handler set connected=false (queue overflow, event overflow),
		// signal cURL to abort the transfer.
		if ( ! $this->connected ) {
			return 0;
		}

		return $len;
	}

	/**
	 * Parse a single SSE line.
	 *
	 * @param string $line Line to parse.
	 */
	private function parse_sse_line( string $line ): void {
		// Per SSE spec: blank line dispatches the accumulated event.
		if ( '' === $line ) {
			$this->dispatch_event();
			$this->current_event = [ 'event' => '', 'data' => '' ];
			return;
		}

		// Check for field: value format.
		$colon_pos = \strpos( $line, ':' );
		if ( false === $colon_pos ) {
			return;
		}

		$field = \substr( $line, 0, $colon_pos );
		$value = \substr( $line, $colon_pos + 1 );
		// Remove leading space after colon if present.
		if ( isset( $value[0] ) && ' ' === $value[0] ) {
			$value = \substr( $value, 1 );
		}

		switch ( $field ) {
			case 'event':
				$this->current_event['event'] = $value;
				break;
			case 'data':
				// Per SSE spec: multiple data fields are concatenated with newlines.
				if ( '' !== $this->current_event['data'] ) {
					$this->current_event['data'] .= "\n";
				}
				$this->current_event['data'] .= $value;
				// Prevent unbounded event data growth from malicious servers
				// sending unlimited short data: lines without a blank line to dispatch.
				if ( \strlen( $this->current_event['data'] ) > self::MAX_EVENT_SIZE ) {
					$this->last_error     = 'Event data overflow (' . self::MAX_EVENT_SIZE . ' bytes)';
					$this->current_event  = [ 'event' => '', 'data' => '' ];
					$this->connected      = false;
				}
				break;
			// Ignore other fields (id, retry, comments).
		}
	}

	/**
	 * Dispatch a complete event to the queue.
	 */
	private function dispatch_event(): void {
		$event_type = $this->current_event['event'];
		if ( '' === $event_type ) {
			return;
		}

		// Backpressure: if the consumer isn't draining fast enough, disconnect
		// so StreamMerger can reconnect after the queue is processed.
		if ( \count( $this->event_queue ) >= self::MAX_QUEUE_SIZE ) {
			$this->last_error = 'Event queue overflow (' . self::MAX_QUEUE_SIZE . ' events)';
			$this->connected  = false;
			return;
		}

		// Decode JSON data.
		$decoded = \json_decode( $this->current_event['data'], true, 16 );

		// Entry events must have valid JSON data. Drop malformed entries
		// rather than queuing nulls that downstream consumers can't process.
		if ( 'entry' === $event_type && null === $decoded ) {
			return;
		}

		// Capture slot from connected event.
		if ( 'connected' === $event_type && \is_array( $decoded ) && isset( $decoded['slot'] ) ) {
			$this->slot = (int) $decoded['slot'];
		}

		// Update position from heartbeat or entry events.
		if ( ( 'heartbeat' === $event_type || 'entry' === $event_type )
			&& \is_array( $decoded ) && isset( $decoded['position'] ) ) {
			$this->position = [
				'segment_id' => \max( 0, (int) ( $decoded['position']['segment_id'] ?? $this->position['segment_id'] ) ),
				'offset'     => \max( 0, (int) ( $decoded['position']['offset'] ?? $this->position['offset'] ) ),
			];
			unset( $decoded['position'] );
		}

		$this->event_queue[] = [
			'type' => $event_type,
			'data' => $decoded,
		];

		$this->last_event_time = \microtime( true );

		// Reset backoff on successful event receipt.
		$this->current_backoff = self::INITIAL_BACKOFF;
	}

	/**
	 * Read a single event from the queue (non-blocking).
	 *
	 * Events are queued by handle_data() when cURL receives data.
	 * The caller must drive cURL via a shared multi handle.
	 *
	 * @return array|null Event array or null if none available.
	 */
	public function read_event(): ?array {
		if ( ! empty( $this->event_queue ) ) {
			return \array_shift( $this->event_queue );
		}
		return null;
	}

	/**
	 * Get the cURL handle for adding to a shared multi handle.
	 *
	 * @return \CurlHandle|null The handle, or null if not connected.
	 */
	public function get_handle(): ?\CurlHandle {
		return $this->ch;
	}

	/**
	 * Check if the connection is stale (no events received recently).
	 *
	 * @return bool True if stale and connection was closed.
	 */
	public function check_stale(): bool {
		if ( ! $this->connected ) {
			return false;
		}

		$now = \microtime( true );
		if ( $now - $this->last_event_time > self::HEARTBEAT_TIMEOUT ) {
			$stale_seconds    = (int) ( $now - $this->last_event_time );
			$this->last_error = "Stale connection (no events for {$stale_seconds}s)";
			$this->close();
			$this->increase_backoff();
			return true;
		}
		return false;
	}

	/**
	 * Handle cURL handle completion (called by StreamMerger when multi_info_read reports done).
	 *
	 * @param int $result The CURLE_* result code.
	 */
	public function handle_completion( int $result ): void {
		if ( null === $this->ch ) {
			return;
		}

		$http_code            = \curl_getinfo( $this->ch, CURLINFO_HTTP_CODE );
		$curl_error           = \curl_error( $this->ch );
		$this->last_http_code = $http_code ?: null;

		// Close handle but don't call close() yet - let StreamMerger remove from multi first.
		if ( CURLE_OK !== $result ) {
			$this->last_error = "cURL error {$result}: {$curl_error}";
		} elseif ( 200 !== $http_code && 0 !== $http_code ) {
			$this->last_error = "HTTP {$http_code}";
		} else {
			$this->last_error = 'Connection closed by server';
		}

		$this->increase_backoff();
		$this->connected = false;
	}

	/**
	 * Get the current position.
	 *
	 * @return array Position array ['segment_id' => N, 'offset' => M].
	 */
	public function get_position(): array {
		return $this->position;
	}

	/**
	 * Check if currently connected.
	 *
	 * @return bool True if connected.
	 */
	public function is_connected(): bool {
		return $this->connected;
	}

	/**
	 * Close the connection.
	 *
	 * Note: The caller must remove this handle from the shared multi handle
	 * BEFORE calling close().
	 */
	public function close(): void {
		if ( null !== $this->ch ) {
			\curl_close( $this->ch );
			$this->ch = null;
		}
		$this->connected = false;
		$this->slot      = null; // Reset slot - will be set by next connected event.
	}

	/**
	 * Increase backoff for next reconnection attempt.
	 */
	private function increase_backoff(): void {
		$this->current_backoff = \min( self::MAX_BACKOFF, $this->current_backoff * 2 );
	}

	/**
	 * Get the SSE slot assigned by the remote server.
	 *
	 * @return int|null Slot number or null if not connected.
	 */
	public function get_slot(): ?int {
		return $this->slot;
	}

	/**
	 * Get the last error message.
	 *
	 * @return string|null Error message or null if no error.
	 */
	public function get_last_error(): ?string {
		return $this->last_error;
	}

	/**
	 * Get the last HTTP response code.
	 *
	 * @return int|null HTTP code or null if not yet received.
	 */
	public function get_last_http_code(): ?int {
		return $this->last_http_code;
	}

	/**
	 * Get the current backoff in seconds.
	 *
	 * @return int Current backoff.
	 */
	public function get_backoff(): int {
		return $this->current_backoff;
	}

	/**
	 * Get seconds until next reconnect attempt is allowed.
	 *
	 * @return float Seconds remaining, or 0 if reconnect is allowed now.
	 */
	public function get_time_until_reconnect(): float {
		$elapsed = \microtime( true ) - $this->last_connect_attempt;
		$remaining = $this->current_backoff - $elapsed;
		return \max( 0.0, $remaining );
	}
}
