<?php
/**
 * Performance Controller Base
 *
 * Base class for Performance REST controllers.
 *
 * @package Newspack_Performance_Dashboards
 */

namespace Newspack_Performance_Dashboards\REST;

use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Firehose;
use Newspack_Performance_Workers\StatsStore;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class providing shared functionality for Performance REST controllers.
 */
abstract class PerformanceControllerBase extends \WP_REST_Controller {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'event-logger/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'performance';

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	protected string $log_base = '/tmp/event-logger/logs';

	/**
	 * Number of partitions.
	 *
	 * @var int
	 */
	protected int $num_partitions = 1;

	/**
	 * Requests firehose instances by partition.
	 *
	 * @var array<int, Firehose>
	 */
	protected array $requests_logs = [];

	/**
	 * Flames firehose instances by partition.
	 *
	 * @var array<int, Firehose>
	 */
	protected array $flames_logs = [];

	/**
	 * Bounds constants for config validation.
	 */
	private const MIN_PARTITIONS    = 1;
	private const MAX_PARTITIONS    = 16;

	/**
	 * Maximum index entries to scan before stopping.
	 * Prevents O(partitions x index_size x 2) for non-existent rids.
	 */
	protected const MAX_INDEX_ENTRIES = 100000;

	/**
	 * Rate limit: requests per minute per user.
	 */
	protected const RATE_LIMIT_REQUESTS = 300;

	/**
	 * Rate limit window in seconds.
	 */
	protected const RATE_LIMIT_WINDOW = 60;

	/**
	 * Constructor - load config and initialize paths.
	 */
	public function __construct() {
		$config = Config::load_config( 'full' );

		$this->log_base = Config::get_logs_directory();

		// Validate config values with min/max bounds.
		$this->num_partitions = $this->validate_bounds(
			$config['num_partitions'] ?? 1,
			self::MIN_PARTITIONS,
			self::MAX_PARTITIONS,
			1
		);
		// Initialize stats store with partition count, memcache servers, and retention window.
		$memcache_servers = $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS;
		$max_lifespan     = (int) ( $config['max_lifespan'] ?? 86400 );
		StatsStore::init( $this->num_partitions, $memcache_servers, $max_lifespan );
	}

	/**
	 * Validate a numeric value is within bounds.
	 *
	 * @param mixed $value   Value to validate.
	 * @param int   $min     Minimum allowed value.
	 * @param int   $max     Maximum allowed value.
	 * @param int   $default Default value if out of bounds.
	 * @return int Validated value.
	 */
	private function validate_bounds( $value, int $min, int $max, int $default ): int {
		if ( ! \is_numeric( $value ) ) {
			return $default;
		}
		$int_value = (int) $value;
		if ( $int_value < $min || $int_value > $max ) {
			return $default;
		}
		return $int_value;
	}

	/**
	 * Validate partition is within bounds.
	 *
	 * @param int $partition Partition number to validate.
	 * @return void
	 * @throws \InvalidArgumentException If partition is out of bounds.
	 */
	private function validate_partition( int $partition ): void {
		if ( $partition < 0 || $partition >= $this->num_partitions ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Partition %d is out of bounds (valid range: 0-%d)',
					\absint( $partition ),
					\absint( $this->num_partitions - 1 )
				)
			);
		}
	}

	/**
	 * Get or create requests firehose for a partition.
	 *
	 * @param int $partition Partition number.
	 * @return Firehose Firehose instance.
	 * @throws \InvalidArgumentException If partition is out of bounds.
	 */
	protected function get_requests_log( int $partition ): Firehose {
		// Validate partition bounds.
		$this->validate_partition( $partition );

		if ( ! isset( $this->requests_logs[ $partition ] ) ) {
			$this->requests_logs[ $partition ] = new Firehose( "{$this->log_base}/requests.log", $partition );
		}
		return $this->requests_logs[ $partition ];
	}

	/**
	 * Get or create flames firehose for a partition.
	 *
	 * @param int $partition Partition number.
	 * @return Firehose Firehose instance.
	 * @throws \InvalidArgumentException If partition is out of bounds.
	 */
	protected function get_flames_log( int $partition ): Firehose {
		// Validate partition bounds.
		$this->validate_partition( $partition );

		if ( ! isset( $this->flames_logs[ $partition ] ) ) {
			$this->flames_logs[ $partition ] = new Firehose( "{$this->log_base}/flames.log", $partition );
		}
		return $this->flames_logs[ $partition ];
	}

	/**
	 * Check if the user has read permissions.
	 *
	 * Uses Admin::current_user_allowed() to check against allowed_users config.
	 *
	 * @return bool|\WP_Error True if allowed, \WP_Error otherwise.
	 */
	public function read_permissions_check() {
		if ( ! \Newspack_Event_Logger\Admin\Admin::current_user_allowed() ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to access this resource.', 'newspack-event-logger' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Return a not found error.
	 *
	 * @param string $message Error message.
	 * @return \WP_Error Error object.
	 */
	protected function not_found_error( string $message ): \WP_Error {
		return new \WP_Error(
			'rest_not_found',
			$message,
			[ 'status' => 404 ]
		);
	}

	/**
	 * Check rate limit for the current user.
	 *
	 * Uses fixed time windows to track request count per user. Each window
	 * (e.g., minute 0-59, 60-119) gets its own counter that naturally expires.
	 * This ensures "X requests per Y seconds" actually means requests within
	 * a Y-second period, not "X requests before Y seconds of inactivity".
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	protected function check_rate_limit() {
		$user_id = \get_current_user_id();
		if ( 0 === $user_id ) {
			// Anonymous users: use IP-based limiting.
			// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
			$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			$identifier  = 'ip_' . \wp_hash( $remote_addr );
		} else {
			$identifier = 'user_' . $user_id;
		}

		// Fixed time window: floor to window boundary (e.g., minute 0, 60, 120...).
		$now          = \time();
		$window_start = (int) \floor( $now / static::RATE_LIMIT_WINDOW ) * static::RATE_LIMIT_WINDOW;
		$transient_key = 'el_rate_' . $identifier . '_' . $window_start;

		$count = (int) \get_transient( $transient_key );

		if ( $count >= static::RATE_LIMIT_REQUESTS ) {
			$seconds_remaining = $window_start + static::RATE_LIMIT_WINDOW - $now;
			return new \WP_Error(
				'rate_limit_exceeded',
				\sprintf(
					/* translators: %d: number of seconds to wait */
					\__( 'Rate limit exceeded. Please wait %d seconds.', 'newspack-event-logger' ),
					\max( 1, $seconds_remaining )
				),
				[ 'status' => 429 ]
			);
		}

		// Expiry: end of window + buffer for cleanup.
		$expiry = $window_start + static::RATE_LIMIT_WINDOW + 10 - $now;
		\set_transient( $transient_key, $count + 1, \max( 1, $expiry ) );
		return true;
	}


	/**
	 * Scale category times by samples/count to get true average over ALL requests.
	 *
	 * Categories that appear in fewer requests get scaled down proportionally.
	 *
	 * @param array $data Profile/leaderboard data with 'count' and 'categories' keys.
	 * @return void
	 */
	protected function scale_categories_by_samples( array &$data ): void {
		if ( empty( $data ) || ( $data['count'] ?? 0 ) <= 0 ) {
			return;
		}
		$total_count = $data['count'];
		foreach ( ( $data['categories'] ?? [] ) as $cat => $cat_data ) {
			$samples = $cat_data['samples'] ?? $total_count;
			if ( $samples < $total_count ) {
				$data['categories'][ $cat ]['time']  = ( $cat_data['time'] ?? 0 ) * $samples / $total_count;
				$data['categories'][ $cat ]['count'] = ( $cat_data['count'] ?? 0 ) * $samples / $total_count;
			}
		}
	}

	/**
	 * Load URL stats from memcache.
	 *
	 * Merges stats from all partitions, computes percentiles, and sorts by count.
	 *
	 * @return array Array of URL stats entries.
	 */
	protected function load_index(): array {
		$result = StatsStore::get_merged_url_index();

		// Filter stale URLs and rename last_seen for API compatibility.
		$cutoff  = \time() - StatsStore::get_retention();
		$cleaned = [];
		foreach ( $result as $entry ) {
			$last_seen = $entry['last_seen'] ?? 0;
			if ( $last_seen < $cutoff ) {
				continue;
			}
			$entry['last_updated'] = $last_seen;
			unset( $entry['last_seen'] );
			$cleaned[] = $entry;
		}

		return $cleaned;
	}
}
