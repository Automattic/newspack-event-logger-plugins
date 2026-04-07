<?php
/**
 * Status Controller
 *
 * REST API for getting aggregator connection status.
 *
 * @package Newspack_Event_Aggregator
 */

namespace Newspack_Event_Aggregator\REST;

use Newspack_Event_Aggregator\ServerRegistry;
use Newspack_Event_Logger\Config;
use Newspack_Event_Logger\Memcached;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status Controller class.
 */
class StatusController extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'event-aggregator/v1';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);
	}

	/**
	 * Check permissions.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function permissions_check() {
		if ( ! \Newspack_Event_Logger\Admin\Admin::current_user_allowed() ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You do not have permission to access this resource.', 'newspack-event-aggregator' ),
				[ 'status' => \rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Get aggregator status for all servers.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Status for all configured servers.
	 */
	public function get_status( $request ): \WP_REST_Response {
		$registry = ServerRegistry::get_instance();
		$servers  = $registry->get_all();

		$config         = Config::load_config( 'full' );
		$num_partitions = \min( 16, \max( 1, (int) ( $config['num_partitions'] ?? 1 ) ) );
		$memcache_servers = $config['memcache_servers'] ?? Memcached::DEFAULT_SERVERS;
		if ( ! \is_array( $memcache_servers ) ) {
			$memcache_servers = Memcached::DEFAULT_SERVERS;
		}
		Memcached::init( $memcache_servers );

		$result = [];
		foreach ( $servers as $id => $server ) {
			// Fetch status for each partition.
			$partitions = [];
			for ( $p = 0; $p < $num_partitions; $p++ ) {
				$partitions[ $p ] = Memcached::get( "aggregator_status:{$id}:p{$p}" ) ?: [];
			}

			$result[ $id ] = [
				'id'         => $id,
				'url'        => \esc_url_raw( $server['url'] ),
				'enabled'    => $server['enabled'] ?? true,
				'partitions' => $partitions,
			];
		}

		return \rest_ensure_response( $result );
	}
}
