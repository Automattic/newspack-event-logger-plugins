<?php
/**
 * PHPUnit Bootstrap for Event Logger Tests
 *
 * Sets up autoloading and WordPress function stubs for running
 * Event Logger plugin code within PHPUnit without WordPress.
 *
 * @package Event_Logger
 */

// Define WordPress constants.
define( 'ABSPATH', '/' );
// WP_PLUGIN_DIR: monorepo root in source tree, /usr/src in container.
// Detect by checking if sibling plugin dirs exist at dirname(__DIR__).
$_monorepo_root = dirname( __DIR__ );
if ( is_dir( $_monorepo_root . '/newspack-event-logger' ) ) {
	define( 'WP_PLUGIN_DIR', $_monorepo_root );
} else {
	// Deployed layout: plugins at /usr/src/newspack-*/ (siblings, not children).
	define( 'WP_PLUGIN_DIR', dirname( $_monorepo_root ) );
}
unset( $_monorepo_root );

// Fake server variables for code that checks them.
$_SERVER['REQUEST_URI']    = '/test';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';

// Point config to test config file.
putenv( 'LOCAL_EVENT_LOGGER_CONF=' . __DIR__ . '/event-logger-test-config.php' );

// Tests write temp config files under /tmp; production Config only allows /usr/src.
// Redirect PHP's error_log() to /dev/null so negative-path tests don't spew into test output.
ini_set( 'error_log', '/dev/null' );

// Load Composer autoloaders from each plugin.
$autoloaders = [
	WP_PLUGIN_DIR . '/newspack-event-logger/vendor/autoload.php',
	WP_PLUGIN_DIR . '/newspack-event-jobs/vendor/autoload.php',
	WP_PLUGIN_DIR . '/newspack-performance-logger/vendor/autoload.php',
	WP_PLUGIN_DIR . '/newspack-performance-workers/vendor/autoload.php',
];

foreach ( $autoloaders as $autoloader ) {
	if ( file_exists( $autoloader ) ) {
		require_once $autoloader;
	}
}

// Plugins without Composer autoloaders — require class files directly.
$class_files = [
	WP_PLUGIN_DIR . '/newspack-performance-gyroscope/includes/class-inflight-tracker.php',
	WP_PLUGIN_DIR . '/newspack-event-aggregator/includes/class-sse-client.php',
	WP_PLUGIN_DIR . '/newspack-event-aggregator/includes/class-server-registry.php',
	WP_PLUGIN_DIR . '/newspack-event-aggregator/includes/class-settings-sync.php',
];

foreach ( $class_files as $class_file ) {
	if ( file_exists( $class_file ) ) {
		require_once $class_file;
	}
}

// Allow tests to point LOCAL_EVENT_LOGGER_CONF at temp files under /tmp.
// Production Config::$allowed_config_dirs is locked to /usr/src as a security measure;
// widen it here (reflection, test-only) so WorkerCommandTest et al. can validate temp configs.
$_allowed_ref = new ReflectionProperty( \Newspack_Event_Logger\Config::class, 'allowed_config_dirs' );
$_allowed_ref->setAccessible( true );
$_current_dirs = $_allowed_ref->getValue();
if ( ! in_array( '/tmp', $_current_dirs, true ) ) {
	$_allowed_ref->setValue( null, array_merge( $_current_dirs, [ '/tmp' ] ) );
}
unset( $_allowed_ref, $_current_dirs );

// ── Minimal WordPress Function Stubs ──────────────────────────────────────────
// Only stub what the tested classes actually call.
// Uses static arrays for option storage to avoid needing a database.

/** @var array In-memory filter store for add_filter/apply_filters stubs. */
$GLOBALS['_wp_test_filters'] = [];

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Stub: add_filter — registers callback.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @return true
	 */
	function add_filter( $hook = '', $callback = null, $priority = 10 ) {
		if ( '' !== $hook && is_callable( $callback ) ) {
			$GLOBALS['_wp_test_filters'][ $hook ][ $priority ][] = $callback;
		}
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub: apply_filters — runs registered callbacks.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value to filter.
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$args ) {
		if ( ! empty( $GLOBALS['_wp_test_filters'][ $hook ] ) ) {
			ksort( $GLOBALS['_wp_test_filters'][ $hook ] );
			foreach ( $GLOBALS['_wp_test_filters'][ $hook ] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$value = call_user_func( $callback, $value, ...$args );
				}
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub: add_action — routes through add_filter.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @return true
	 */
	function add_action( $hook = '', $callback = null, $priority = 10 ) {
		return add_filter( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Stub: do_action — dispatches registered action callbacks.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Arguments to pass to callbacks.
	 */
	function do_action( $hook = '', ...$args ) {
		if ( ! empty( $GLOBALS['_wp_test_filters'][ $hook ] ) ) {
			ksort( $GLOBALS['_wp_test_filters'][ $hook ] );
			foreach ( $GLOBALS['_wp_test_filters'][ $hook ] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					call_user_func_array( $callback, $args );
				}
			}
		}
	}
}

/** @var array In-memory option store for get_option/update_option/delete_option stubs. */
$GLOBALS['_wp_test_options'] = [];

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub: get_option — reads from in-memory store.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['_wp_test_options'] )
			? $GLOBALS['_wp_test_options'][ $option ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub: update_option — writes to in-memory store.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return bool
	 */
	function update_option( $option, $value ) {
		$GLOBALS['_wp_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Stub: delete_option — removes from in-memory store.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( $option ) {
		unset( $GLOBALS['_wp_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub: wp_json_encode — delegates to json_encode.
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options JSON options.
	 * @param int   $depth   Max depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub: sanitize_text_field — basic sanitization.
	 *
	 * @param string $str String to sanitize.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub: wp_unslash — removes slashes.
	 *
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub: esc_html — HTML entity encoding.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Stub: esc_html__ — returns escaped string.
	 *
	 * @param string $text   Text to escape.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stub: esc_url_raw — basic URL sanitization.
	 *
	 * @param string $url URL to sanitize.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub: __ — returns untranslated string.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Stub: wp_rand — delegates to random_int.
	 *
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 * @return int
	 */
	function wp_rand( $min = 0, $max = 0 ) {
		if ( 0 === $max ) {
			$max = mt_getrandmax();
		}
		return random_int( $min, $max );
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * Stub: wp_timezone — returns UTC.
	 *
	 * @return DateTimeZone
	 */
	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub: current_user_can — always returns true in tests.
	 *
	 * @param string $capability Capability to check.
	 * @return bool
	 */
	function current_user_can( $capability ) {
		return $GLOBALS['_wp_test_user_can'] ?? true;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub: is_wp_error — checks instanceof WP_Error.
	 *
	 * @param mixed $thing Thing to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	/**
	 * Stub: wp_cache_flush — no-op.
	 *
	 * @return true
	 */
	function wp_cache_flush() {
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Stub: wp_cache_delete — no-op.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @return true
	 */
	function wp_cache_delete( $key = '', $group = '' ) {
		return true;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Stub: wp_mkdir_p — recursive mkdir.
	 *
	 * @param string $target Directory path.
	 * @return bool
	 */
	function wp_mkdir_p( $target ) {
		if ( is_dir( $target ) ) {
			return true;
		}
		return @mkdir( $target, 0755, true );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Stub: trailingslashit — appends trailing slash.
	 *
	 * @param string $value Value to trail.
	 * @return string
	 */
	function trailingslashit( $value ) {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * Stub: wp_generate_uuid4 — generates a UUID v4 string.
	 *
	 * @return string
	 */
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ), random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
		);
	}
}

// ── Stubs for Supervisor tests ────────────────────────────────────────────────

if ( ! defined( 'NONCE_SALT' ) ) {
	define( 'NONCE_SALT', 'test-nonce-salt-for-unit-tests' );
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Stub: wp_next_scheduled — returns false (nothing scheduled).
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Arguments.
	 * @return false
	 */
	function wp_next_scheduled( $hook, $args = [] ) {
		return $GLOBALS['_wp_test_next_scheduled'] ?? false;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	/**
	 * Stub: wp_unschedule_event — no-op.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $hook      Hook name.
	 * @param array  $args      Arguments.
	 * @return true
	 */
	function wp_unschedule_event( $timestamp, $hook, $args = [] ) {
		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Stub: rest_url — returns fake REST URL.
	 *
	 * @param string $path REST path.
	 * @return string
	 */
	function rest_url( $path = '' ) {
		return 'http://localhost/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Stub: wp_remote_post — returns success response.
	 *
	 * @param string $url  URL.
	 * @param array  $args Arguments.
	 * @return array
	 */
	function wp_remote_post( $url, $args = [] ) {
		return [ 'response' => [ 'code' => 200 ] ];
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * Stub: wp_generate_password — generates a random password.
	 *
	 * @param int  $length        Password length.
	 * @param bool $special_chars Include special chars.
	 * @param bool $extra_special Include extra special chars.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$password = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$password .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $password;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Stub: wp_parse_args — merges user args with defaults.
	 *
	 * @param array|string $args     Value to merge with defaults.
	 * @param array        $defaults Default values.
	 * @return array Merged arguments.
	 */
	function wp_parse_args( $args, $defaults = [] ) {
		if ( is_string( $args ) ) {
			parse_str( $args, $args );
		}
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Stub: add_query_arg — appends query parameters to a URL.
	 *
	 * @param array  $args URL query args.
	 * @param string $url  Base URL.
	 * @return string URL with query args appended.
	 */
	function add_query_arg( $args, $url = '' ) {
		$parsed = parse_url( $url );
		$query  = [];
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query );
		}
		$query = array_merge( $query, $args );
		$base  = ( ! empty( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '' )
			. ( $parsed['host'] ?? '' )
			. ( ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '' )
			. ( $parsed['path'] ?? '' );
		return $base . '?' . http_build_query( $query );
	}
}

// ── WordPress REST API Stubs ────────────────────────────────────────────────────
// Stub WP_REST_Server constants and REST infrastructure classes.

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * Stub: WP_REST_Server — constants only.
	 */
	class WP_REST_Server {
		const READABLE   = 'GET';
		const CREATABLE  = 'POST';
		const EDITABLE   = 'PUT, PATCH';
		const DELETABLE  = 'DELETE';
		const ALLMETHODS  = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! class_exists( 'WP_HTTP_Response' ) ) {
	/**
	 * Stub: WP_HTTP_Response.
	 */
	class WP_HTTP_Response {

		/** @var mixed */
		public $data;

		/** @var int */
		public $status;

		/** @var array */
		public $headers = [];

		/**
		 * Constructor.
		 *
		 * @param mixed $data    Response data.
		 * @param int   $status  HTTP status code.
		 * @param array $headers Response headers.
		 */
		public function __construct( $data = null, $status = 200, $headers = [] ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_headers() {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Stub: WP_REST_Response.
	 */
	class WP_REST_Response extends WP_HTTP_Response {

		public function set_status( $code ) {
			$this->status = $code;
		}

		public function set_data( $data ) {
			$this->data = $data;
		}

		public function header( $key, $value, $replace = true ) {
			$this->headers[ $key ] = $value;
		}

		public function set_headers( $headers ) {
			$this->headers = $headers;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Stub: WP_REST_Request.
	 */
	class WP_REST_Request {

		/** @var array */
		private $params = [];

		/** @var array */
		private $json_params = [];

		/** @var string */
		private $method = 'GET';

		/** @var array */
		private $headers = [];

		/**
		 * Constructor.
		 *
		 * @param string $method HTTP method.
		 * @param string $route  Route.
		 */
		public function __construct( $method = 'GET', $route = '' ) {
			$this->method = $method;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function has_param( $key ) {
			return array_key_exists( $key, $this->params );
		}

		public function get_params() {
			return $this->params;
		}

		public function get_json_params() {
			return $this->json_params;
		}

		public function get_method() {
			return $this->method;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function get_header( $key ) {
			$key = strtolower( $key );
			return $this->headers[ $key ] ?? null;
		}

		public function set_header( $key, $value ) {
			$this->headers[ strtolower( $key ) ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	/**
	 * Stub: WP_REST_Controller — base class.
	 */
	class WP_REST_Controller {

		protected $namespace = '';
		protected $rest_base = '';

		public function register_routes() {}
		public function get_items( $request ) { return null; }
		public function get_item( $request ) { return null; }
		public function create_item( $request ) { return null; }
		public function update_item( $request ) { return null; }
		public function delete_item( $request ) { return null; }
		public function get_items_permissions_check( $request ) { return null; }
		public function prepare_item_for_response( $item, $request ) { return null; }
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stub: WP_Error.
	 */
	class WP_Error {

		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
		private $error_data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code       = $code;
			$this->message    = $message;
			$this->error_data = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->error_data;
		}
	}
}

// Store registered routes for test assertions.
$GLOBALS['_wp_test_registered_routes'] = [];

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Stub: register_rest_route — stores routes for verification.
	 *
	 * @param string $namespace Route namespace.
	 * @param string $route     Route pattern.
	 * @param array  $args      Route arguments.
	 * @return bool
	 */
	function register_rest_route( $namespace, $route, $args = [] ) {
		$GLOBALS['_wp_test_registered_routes'][] = [
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		];
		return true;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/**
	 * Stub: rest_ensure_response — wraps non-response values.
	 *
	 * @param mixed $response Response data.
	 * @return WP_REST_Response
	 */
	function rest_ensure_response( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			return $response;
		}
		return new WP_REST_Response( $response, 200 );
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	/**
	 * Stub: rest_authorization_required_code.
	 *
	 * @return int
	 */
	function rest_authorization_required_code() {
		return 401;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Stub: get_current_user_id — returns from test global.
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return $GLOBALS['_wp_test_user_id'] ?? 0;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub: absint.
	 *
	 * @param mixed $val Value.
	 * @return int
	 */
	function absint( $val ) {
		return abs( intval( $val ) );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Stub: wp_verify_nonce — returns from test global.
	 *
	 * @param string $nonce  Nonce value.
	 * @param string $action Action name.
	 * @return bool
	 */
	function wp_verify_nonce( $nonce, $action = '' ) {
		return $GLOBALS['_wp_test_nonce_valid'] ?? true;
	}
}

if ( ! function_exists( 'wp_hash' ) ) {
	/**
	 * Stub: wp_hash.
	 *
	 * @param string $data Data to hash.
	 * @return string
	 */
	function wp_hash( $data ) {
		return md5( $data );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Stub: wp_salt.
	 *
	 * @param string $scheme Salt scheme.
	 * @return string Deterministic test salt.
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}

// Transient storage for tests.
$GLOBALS['_wp_test_transients'] = [];

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Stub: get_transient.
	 *
	 * @param string $key Transient key.
	 * @return mixed
	 */
	function get_transient( $key ) {
		return $GLOBALS['_wp_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stub: set_transient.
	 *
	 * @param string $key        Transient key.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Expiration in seconds.
	 * @return bool
	 */
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['_wp_test_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stub: delete_transient.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	function delete_transient( $key ) {
		unset( $GLOBALS['_wp_test_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	/**
	 * Stub: rest_sanitize_boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	function rest_sanitize_boolean( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( $value );
			if ( in_array( $value, [ 'false', '0', 'no', 'off', '' ], true ) ) {
				return false;
			}
			return true;
		}
		return (bool) $value;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Stub: wp_remote_get — returns from test global.
	 *
	 * @param string $url  URL.
	 * @param array  $args Arguments.
	 * @return array|WP_Error
	 */
	function wp_remote_get( $url, $args = [] ) {
		return $GLOBALS['_wp_test_remote_response'] ?? [
			'response' => [ 'code' => 200 ],
			'body'     => '',
		];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Stub: wp_remote_retrieve_response_code.
	 *
	 * @param array $response Response array.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 200;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Stub: wp_remote_retrieve_body.
	 *
	 * @param array $response Response array.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * Stub: wp_get_current_user — returns a minimal user object.
	 *
	 * @return object
	 */
	function wp_get_current_user() {
		$login = $GLOBALS['_wp_test_current_user_login'] ?? '';
		return (object) [
			'ID'         => $GLOBALS['_wp_test_user_id'] ?? 0,
			'user_login' => $login,
		];
	}
}

// ── wpdb stub (for WorkerBase::check_db_connection tests) ────────────────────────

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Stub: wpdb — minimal for instanceof checks.
	 */
	class wpdb {

		/**
		 * Check if the database connection is alive.
		 *
		 * @param bool $allow_bail Whether to allow bail on failure.
		 * @return bool
		 */
		public function check_connection( $allow_bail = true ) {
			return true;
		}
	}
}

if ( ! function_exists( 'current_filter' ) ) {
	/**
	 * Stub: current_filter — returns from test global.
	 *
	 * @return string Current filter name.
	 */
	function current_filter() {
		return $GLOBALS['_wp_test_current_filter'] ?? '';
	}
}

// ── WP_Hook stub (for $wp_filter inspection) ────────────────────────────────────

if ( ! class_exists( 'WP_Hook' ) ) {
	/**
	 * Stub: WP_Hook — minimal implementation for iteration.
	 */
	class WP_Hook implements \IteratorAggregate {

		/** @var array */
		public $callbacks = [];

		public function getIterator(): \ArrayIterator {
			return new \ArrayIterator( $this->callbacks );
		}
	}
}

// ── Additional autoloaders for plugins not in bootstrap ──────────────────────────
// Load autoloaders for plugins whose controllers we test.

$extra_autoloaders = [
	WP_PLUGIN_DIR . '/newspack-event-aggregator/vendor/autoload.php',
	WP_PLUGIN_DIR . '/newspack-event-dashboards/vendor/autoload.php',
	WP_PLUGIN_DIR . '/newspack-performance-dashboards/vendor/autoload.php',
	WP_PLUGIN_DIR . '/newspack-performance-aggregator/vendor/autoload.php',
	WP_PLUGIN_DIR . '/newspack-performance-gyroscope/vendor/autoload.php',
	WP_PLUGIN_DIR . '/newspack-performance-request-log/vendor/autoload.php',
];

foreach ( $extra_autoloaders as $autoloader ) {
	if ( file_exists( $autoloader ) ) {
		require_once $autoloader;
	}
}

// ── Legacy Memcache Stub ────────────────────────────────────────────────────────
// Stub for the legacy Memcache extension (not Memcached).
// Allows testing the fallback path in class-memcached.php.
if ( ! class_exists( '\Memcache' ) ) {
	class Memcache {
		private array $data = [];

		public function addServer( string $host, int $port ): bool {
			return true;
		}

		public function get( string $key ) {
			return $this->data[ $key ] ?? false;
		}

		public function set( string $key, $value, int $flags = 0, int $ttl = 0 ): bool {
			$this->data[ $key ] = $value;
			return true;
		}

		public function add( string $key, $value, int $flags = 0, int $ttl = 0 ): bool {
			if ( isset( $this->data[ $key ] ) ) {
				return false;
			}
			$this->data[ $key ] = $value;
			return true;
		}

		public function delete( string $key ): bool {
			unset( $this->data[ $key ] );
			return true;
		}
	}
}

// ── Schema Filter Registration ───────────────────────────────────────────────────
// ── Settings API stubs ──────────────────────────────────────────────

$GLOBALS['_wp_test_registered_settings'] = [];

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $option_group, $option_name, $args = [] ) {
		$GLOBALS['_wp_test_registered_settings'][ $option_name ] = [
			'group' => $option_group,
			'args'  => $args,
		];
	}
}

if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( $id, $title, $callback, $page, $args = [] ) {
	}
}

if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = [] ) {
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $echo = true ) {
		$result = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ) {
		echo esc_attr( $text );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return addslashes( (string) $text );
	}
}

// ── Admin page / menu stubs ────────────────────────────────────────

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'http://localhost/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
		$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce" />';
		if ( $echo ) {
			echo $html;
		}
		return $html;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['_wp_test_redirect'] = [ 'location' => $location, 'status' => $status ];
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = [] ) {
		throw new \RuntimeException( 'wp_die: ' . $message );
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title = '', $menu_title = '', $capability = '', $menu_slug = '', $callback = '', $icon_url = '', $position = null ) {
		return $menu_slug;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
		return $menu_slug;
	}
}

if ( ! function_exists( 'add_options_page' ) ) {
	function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
		return $menu_slug;
	}
}

if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( $option_group ) {
	}
}

if ( ! function_exists( 'do_settings_sections' ) ) {
	function do_settings_sections( $page ) {
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = '' ) {
		echo '<input type="submit" name="' . esc_attr( $name ) . '" value="' . esc_attr( $text ?? 'Save Changes' ) . '" />';
	}
}

// ── Asset enqueue stubs ─────────────────────────────────────────────

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) {
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		return true;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'test-nonce';
	}
}

if ( ! function_exists( 'printf' ) ) {
	// printf is a PHP built-in, but define esc_html-like version if needed.
}

// ── WP-CLI stubs ────────────────────────────────────────────────────

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static array $log = [];

		public static function log( $message ) {
			self::$log[] = [ 'level' => 'log', 'message' => $message ];
		}

		public static function success( $message ) {
			self::$log[] = [ 'level' => 'success', 'message' => $message ];
		}

		public static function warning( $message ) {
			self::$log[] = [ 'level' => 'warning', 'message' => $message ];
		}

		public static function error( $message ) {
			self::$log[] = [ 'level' => 'error', 'message' => $message ];
			throw new \RuntimeException( 'WP_CLI::error: ' . $message );
		}

		public static function reset() {
			self::$log = [];
		}
	}
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {
	}
}


require_once __DIR__ . '/stubs/wp-cli-utils.php';

// Register option schema extensions that would normally come from plugin init files.
// Must be after add_filter stub is defined (above) and after autoloaders.
\add_filter(
	'newspack_event_logger_option_schema_core',
	function ( $schema ) {
		return \array_merge(
			$schema,
			[
				'log_urls'           => 'array_strings',
				'skip_urls'          => 'array_strings',
				'log_events'         => 'array_strings',
				'custom_events'      => 'array_strings',
				'log_memory'         => 'bool',
				'flush_every_line'   => 'bool',
				'significant_events' => 'array_strings',
			]
		);
	}
);
