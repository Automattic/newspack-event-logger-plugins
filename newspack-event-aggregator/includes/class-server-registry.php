<?php
/**
 * Server Registry
 *
 * Singleton that manages remote server configurations stored in WordPress options.
 * Servers are stored in the 'event_logger_aggregator_servers' option as an associative array.
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator;

use Newspack_Event_Logger\Config;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server Registry class.
 */
class ServerRegistry {

	/**
	 * Option name for storing server configurations.
	 */
	private const OPTION_NAME = 'event_logger_aggregator_servers';

	/**
	 * Maximum number of servers. Matches RemoteManager::MAX_SERVERS.
	 */
	private const MAX_SERVERS = 100;

	/**
	 * Prefix for encrypted values (distinguishes from plaintext during migration).
	 */
	private const ENCRYPTED_PREFIX = '$enc$';

	/**
	 * Singleton instance.
	 *
	 * @var ServerRegistry|null
	 */
	private static ?ServerRegistry $instance = null;

	/**
	 * Cached servers.
	 *
	 * @var array|null
	 */
	private ?array $servers = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return ServerRegistry
	 */
	public static function get_instance(): ServerRegistry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton.
	 */
	private function __construct() {
		// Singleton.
	}

	/**
	 * Get all servers.
	 *
	 * Merges config file defaults with WordPress option values.
	 * WordPress option values override config file defaults.
	 *
	 * @return array Associative array of server_id => config.
	 */
	public function get_all(): array {
		if ( null === $this->servers ) {
			// Get config file defaults.
			$config          = Config::load_config( 'full' );
			$config_defaults = $config['aggregator_servers'] ?? [];
			if ( ! \is_array( $config_defaults ) ) {
				$config_defaults = [];
			}

			// Get WordPress option (may override config defaults).
			$option = \get_option( self::OPTION_NAME, null );

			if ( null === $option ) {
				// No option set - use config defaults.
				$this->servers = $config_defaults;
			} elseif ( \is_array( $option ) ) {
				// Merge: WordPress option takes precedence.
				$this->servers = \array_merge( $config_defaults, $option );
			} else {
				$this->servers = $config_defaults;
			}

			// Normalize: ensure all entries have required keys (config-file entries
			// bypass validate_config and may be missing 'logs', 'enabled', etc.).
			foreach ( $this->servers as $id => &$server ) {
				if ( ! \is_array( $server ) ) {
					unset( $this->servers[ $id ] );
					continue;
				}
				$server += [
					'url'           => '',
					'auth_username' => '',
					'auth_password' => '',
					'enabled'       => true,
					'logs'          => [ 'firehose.log' ],
				];
				// Decrypt credentials (handles both encrypted and legacy plaintext).
				if ( '' !== $server['auth_password'] ) {
					$server['auth_password'] = self::decrypt( $server['auth_password'] );
				}
			}
			unset( $server );
		}
		return $this->servers;
	}

	/**
	 * Get only WP-option-managed servers (excludes config-file defaults).
	 *
	 * Used by write operations to avoid contaminating the WP option
	 * with config-file entries.
	 *
	 * @return array Associative array of server_id => config.
	 */
	private function get_wp_servers(): array {
		$option = \get_option( self::OPTION_NAME, [] );
		return \is_array( $option ) ? $option : [];
	}

	/**
	 * Check if a server ID originates from the config file.
	 *
	 * @param string $id Server ID.
	 * @return bool True if the server is defined in the config file.
	 */
	public function is_config_server( string $id ): bool {
		$config  = Config::load_config( 'full' );
		$defaults = $config['aggregator_servers'] ?? [];
		return \is_array( $defaults ) && isset( $defaults[ $id ] );
	}

	/**
	 * Get a specific server by ID.
	 *
	 * @param string $id Server ID.
	 * @return array|null Server config or null if not found.
	 */
	public function get( string $id ): ?array {
		$servers = $this->get_all();
		return $servers[ $id ] ?? null;
	}

	/**
	 * Add a new server.
	 *
	 * @param string $id     Server ID (alphanumeric, hyphen, underscore).
	 * @param array  $config Server configuration.
	 * @return bool True on success.
	 */
	public function add( string $id, array $config ): bool {
		// Validate ID format.
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}

		// Check if already exists (in merged view) or at capacity.
		$all = $this->get_all();
		if ( isset( $all[ $id ] ) ) {
			return false;
		}
		if ( \count( $all ) >= self::MAX_SERVERS ) {
			return false;
		}

		// Validate and sanitize config.
		$validated = $this->validate_config( $config );
		if ( null === $validated ) {
			return false;
		}

		// Write only WP-managed servers to the option (not config-file entries).
		$wp_servers        = $this->get_wp_servers();
		$wp_servers[ $id ] = $validated;

		$result = \update_option( self::OPTION_NAME, $wp_servers, false );
		$this->servers = null; // Force reload to re-merge.

		if ( $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf( '[EventLogger] Server %s: %s (user=%d)', 'added', $id, \get_current_user_id() ) );
		}

		return $result;
	}

	/**
	 * Update an existing server.
	 *
	 * @param string $id     Server ID.
	 * @param array  $config Server configuration (partial update allowed).
	 * @return bool True on success.
	 */
	public function update( string $id, array $config ): bool {
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}

		$all = $this->get_all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}

		// Config-file servers can only toggle enabled — URL/credentials are immutable.
		if ( $this->is_config_server( $id ) ) {
			if ( ! isset( $config['enabled'] ) ) {
				return false;
			}
			$config = [ 'enabled' => $config['enabled'] ];
		}

		// Whitelist config keys before merging.
		$config = \array_intersect_key( $config, \array_flip( [ 'url', 'auth_username', 'auth_password', 'enabled', 'logs' ] ) );

		// Merge with existing config.
		$merged    = \array_merge( $all[ $id ], $config );
		$validated = $this->validate_config( $merged );
		if ( null === $validated ) {
			return false;
		}

		// Write only WP-managed servers to the option.
		$wp_servers        = $this->get_wp_servers();
		$wp_servers[ $id ] = $validated;

		$result = \update_option( self::OPTION_NAME, $wp_servers, false );
		$this->servers = null; // Force reload to re-merge.

		if ( $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf( '[EventLogger] Server %s: %s (user=%d)', 'updated', $id, \get_current_user_id() ) );
		}

		return $result;
	}

	/**
	 * Remove a server.
	 *
	 * Config-file servers cannot be removed via the API -- they reappear on reload.
	 * Returns false for config-file servers.
	 *
	 * @param string $id Server ID.
	 * @return bool True on success.
	 */
	public function remove( string $id ): bool {
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}

		$all = $this->get_all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}

		// Config-file servers cannot be removed via the API.
		if ( $this->is_config_server( $id ) ) {
			return false;
		}

		$wp_servers = $this->get_wp_servers();
		unset( $wp_servers[ $id ] );

		$result = \update_option( self::OPTION_NAME, $wp_servers, false );
		$this->servers = null; // Force reload to re-merge.

		if ( $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf( '[EventLogger] Server %s: %s (user=%d)', 'removed', $id, \get_current_user_id() ) );
		}

		return $result;
	}

	/**
	 * Get all enabled servers.
	 *
	 * @return array Associative array of server_id => config for enabled servers.
	 */
	public function get_enabled(): array {
		$servers = $this->get_all();
		return \array_filter(
			$servers,
			function ( $config ) {
				return ! empty( $config['enabled'] );
			}
		);
	}

	/**
	 * Reset the cache (for testing or after external option updates).
	 */
	public function reset_cache(): void {
		$this->servers = null;
	}

	/**
	 * Validate server ID format.
	 *
	 * @param string $id Server ID to validate.
	 * @return bool True if valid.
	 */
	public static function is_valid_id( string $id ): bool {
		// Alphanumeric, hyphen, underscore only. Length 1-64.
		return 1 === \preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $id );
	}

	/**
	 * Validate and sanitize server configuration.
	 *
	 * @param array $config Raw configuration.
	 * @return array|null Validated configuration or null if invalid.
	 */
	private function validate_config( array $config ): ?array {
		// URL is required and must be valid.
		if ( empty( $config['url'] ) || ! \is_string( $config['url'] ) ) {
			return null;
		}

		$url = \esc_url_raw( $config['url'] );
		if ( empty( $url ) ) {
			return null;
		}

		// Must be HTTPS.
		if ( 0 !== \strpos( $url, 'https://' ) ) {
			return null;
		}

		// Build validated config.
		$validated = [
			'url'           => \rtrim( $url, '/' ),
			'auth_username' => '',
			'auth_password' => '',
			'enabled'       => true,
			'logs'          => [ 'firehose.log' ],
		];

		// WordPress Application Password auth (username + app password).
		if ( ! empty( $config['auth_username'] ) && \is_string( $config['auth_username'] ) ) {
			$validated['auth_username'] = \sanitize_text_field( $config['auth_username'] );
		}
		if ( ! empty( $config['auth_password'] ) && \is_string( $config['auth_password'] ) ) {
			$password = $config['auth_password'];
			// Don't re-sanitize/re-encrypt already-encrypted values.
			if ( 0 !== \strpos( $password, self::ENCRYPTED_PREFIX ) ) {
				// New plaintext password - sanitize and encrypt.
				$password = \preg_replace( '/[\x00-\x1f\x7f]/', '', $password );
				if ( \strlen( $password ) > 256 ) {
					$password = \substr( $password, 0, 256 );
				}
				$password = self::encrypt( $password );
			} else {
				// Already encrypted - verify it actually decrypts.
				$decrypted = self::decrypt( $password );
				if ( '' === $decrypted ) {
					// Invalid encrypted value - reject by clearing the field.
					$password = '';
				}
			}
			$validated['auth_password'] = $password;
		}

		// Enabled flag.
		if ( isset( $config['enabled'] ) ) {
			$validated['enabled'] = (bool) $config['enabled'];
		}

		// Logs to aggregate.
		if ( ! empty( $config['logs'] ) && \is_array( $config['logs'] ) ) {
			$logs = [];
			foreach ( $config['logs'] as $log ) {
				if ( \is_string( $log ) && 1 === \preg_match( '/^[a-zA-Z0-9_.-]+\.log$/', $log ) ) {
					$logs[] = $log;
				}
			}
			if ( ! empty( $logs ) ) {
				$validated['logs'] = $logs;
			}
		}

		return $validated;
	}

	/**
	 * Derive a 32-byte encryption key from wp_salt('auth').
	 *
	 * @return string 32-byte key for sodium_crypto_secretbox.
	 */
	private static function encryption_key(): string {
		return \sodium_crypto_generichash( \wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a string for storage.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Encrypted value with prefix, or empty string on failure.
	 */
	private static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! \function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		$nonce      = \random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = \sodium_crypto_secretbox( $plaintext, $nonce, self::encryption_key() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for binary-safe storage.
		return self::ENCRYPTED_PREFIX . \base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * Handles both encrypted (prefixed) and legacy plaintext values.
	 *
	 * @param string $stored Stored value (may be encrypted or plaintext).
	 * @return string Decrypted plaintext, or original value if not encrypted or on failure.
	 */
	private static function decrypt( string $stored ): string {
		if ( 0 !== \strpos( $stored, self::ENCRYPTED_PREFIX ) ) {
			return $stored; // Legacy plaintext — return as-is.
		}
		if ( ! \function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return ''; // Can't decrypt without sodium.
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for binary-safe storage.
		$decoded = \base64_decode( \substr( $stored, \strlen( self::ENCRYPTED_PREFIX ) ), true );
		if ( false === $decoded || \strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce      = \substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = \substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = \sodium_crypto_secretbox_open( $ciphertext, $nonce, self::encryption_key() );
		return false === $plaintext ? '' : $plaintext;
	}
}
