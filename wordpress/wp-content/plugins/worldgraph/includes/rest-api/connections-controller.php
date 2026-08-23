<?php
/**
 * Provider Connections REST API Controller for World Graph Studio.
 *
 * Exposes the worldgraph_conn control-plane resource:
 *
 *   GET    /worldgraph/v1/connections            List connections
 *   POST   /worldgraph/v1/connections            Create a connection
 *   GET    /worldgraph/v1/connections/{id}       Get a connection
 *   PUT    /worldgraph/v1/connections/{id}       Update a connection
 *   DELETE /worldgraph/v1/connections/{id}       Delete a connection
 *   GET    /worldgraph/v1/connections/{id}/resolve  Resolve non-secret config
 *   POST   /worldgraph/v1/connections/{id}/test   Run a health check
 *   POST   /worldgraph/v1/connections/sync       Sync provider capabilities
 *   GET    /worldgraph/v1/connections/{id}/catalog                     Get catalog snapshot
 *   POST   /worldgraph/v1/connections/{id}/catalog/sync                Sync provider catalog
 *   POST   /worldgraph/v1/connections/{id}/catalog/prepare             Sync, enable, and materialize mappable entries
 *   POST   /worldgraph/v1/connections/{id}/catalog/entries/{entry}/enable
 *   POST   /worldgraph/v1/connections/{id}/catalog/entries/{entry}/disable
 *   POST   /worldgraph/v1/connections/{id}/catalog/entries/{entry}/materialize
 *   POST   /worldgraph/v1/connections/{id}/catalog/entries/{entry}/download
 *
 * @package WorldGraph
 */

namespace WorldGraph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WorldGraph\CPT\Connection as Connection_CPT;
use WorldGraph\Utils\Capability_Sync;
use WorldGraph\Utils\Connection_Repository;
use WorldGraph\Utils\Connection_Tester;

/**
 * Connections Controller class.
 */
class Connections_Controller extends Base_Controller {

	/**
	 * CPT slug.
	 *
	 * @var string
	 */
	protected $cpt = 'worldgraph_conn';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'connections';

	/**
	 * Initialize the controller.
	 */
	public static function init(): void {
		$instance = new self();
		add_action( 'rest_api_init', [ $instance, 'register_routes' ] );
	}

	/**
	 * Prepare a Connection without ever serializing its credential values.
	 *
	 * @param \WP_Post $post   Connection post.
	 * @param array    $params Request parameters.
	 * @return array<string, mixed>
	 */
	protected function get_item_data( \WP_Post $post, array $params = [] ): array {
		$data = parent::get_item_data( $post, $params );
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$data['meta'] = Connection_Repository::redact_credentials( $data['meta'] );
		}
		return $data;
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( 'worldgraph/v1', '/connections', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'check_read_permission' ],
				'args'                => [
					'page'          => [ 'default' => 1 ],
					'per_page'      => [ 'default' => 10, 'maximum' => 100 ],
					'provider_type' => [ 'type' => 'string', 'default' => '' ],
					'environment'   => [ 'type' => 'string', 'default' => '' ],
					'status'        => [ 'type' => 'string', 'default' => '' ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'check_create_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/sync', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'sync_capabilities' ],
				'permission_callback' => [ $this, 'check_create_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)', [
			'args' => [ 'id' => [ 'type' => 'integer' ] ],
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'check_read_permission' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => [ $this, 'check_update_permission' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => [ $this, 'check_delete_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)/resolve', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'resolve_connection' ],
				'permission_callback' => [ $this, 'check_read_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)/test', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test_connection' ],
				'permission_callback' => [ $this, 'check_update_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)/catalog', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_catalog' ],
				'permission_callback' => [ $this, 'check_update_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)/catalog/sync', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'sync_catalog' ],
				'permission_callback' => [ $this, 'check_update_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)/catalog/prepare', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'prepare_catalog' ],
				'permission_callback' => [ $this, 'check_update_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)/catalog/entries/(?P<entry_id>[^/]+)/enable', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'enable_catalog_entry' ],
				'permission_callback' => [ $this, 'check_update_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)/catalog/entries/(?P<entry_id>[^/]+)/disable', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'disable_catalog_entry' ],
				'permission_callback' => [ $this, 'check_update_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)/catalog/entries/(?P<entry_id>[^/]+)/materialize', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'materialize_catalog_entry' ],
				'permission_callback' => [ $this, 'check_update_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/connections/(?P<id>\d+)/catalog/entries/(?P<entry_id>[^/]+)/download', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'download_catalog_entry' ],
				'permission_callback' => [ $this, 'check_update_permission' ],
			],
		] );
	}

	/** Only administrators may read Connection control-plane records. */
	public function check_read_permission( \WP_REST_Request $request ) {
		return current_user_can( 'manage_options' )
			? true
			: new \WP_Error( 'rest_forbidden', 'You do not have permission to manage World Graph Studio Connections.', [ 'status' => is_user_logged_in() ? 403 : 401 ] );
	}

	/** Only administrators may create Connection records. */
	public function check_create_permission( \WP_REST_Request $request ) {
		return $this->check_read_permission( $request );
	}

	/** Only administrators with access to the object may update it. */
	public function check_update_permission( \WP_REST_Request $request ) {
		$permission = $this->check_read_permission( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$post_id = absint( $request->get_param( 'id' ) );
		return ! $post_id || current_user_can( 'edit_post', $post_id )
			? true
			: new \WP_Error( 'rest_forbidden', 'You cannot edit this World Graph Studio Connection.', [ 'status' => 403 ] );
	}

	/** Only administrators with access to the object may delete it. */
	public function check_delete_permission( \WP_REST_Request $request ) {
		$permission = $this->check_read_permission( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$post_id = absint( $request->get_param( 'id' ) );
		return $post_id && current_user_can( 'delete_post', $post_id )
			? true
			: new \WP_Error( 'rest_forbidden', 'You cannot delete this World Graph Studio Connection.', [ 'status' => 403 ] );
	}

	/**
	 * List connections, filtered by provider type, environment, or status.
	 *
	 * Overrides the base implementation because connection status is a meta
	 * field (not the worldgraph_status taxonomy) and the repository provides
	 * meta-based filtering.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$permission = $this->check_read_permission( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$filters = [];
		foreach ( [ 'provider_type', 'environment', 'status' ] as $key ) {
			$value = $request->get_param( $key );
			if ( ! empty( $value ) ) {
				$filters[ $key ] = sanitize_key( $value );
			}
		}

		$connections = Connection_Repository::get_all( $filters );

		$items = [];
		foreach ( $connections as $connection ) {
			$post = get_post( $connection['id'] );
			if ( $post ) {
				$items[] = $this->prepare_item( $post, $request->get_params() );
			}
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', count( $items ) );
		$response->header( 'X-WP-TotalPages', 1 );

		return $response;
	}

	/**
	 * Resolve the non-secret configuration for a connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resolve_connection( \WP_REST_Request $request ) {
		$connection_id = absint( $request->get_param( 'id' ) );
		$config        = Connection_Repository::resolve( $connection_id );

		if ( null === $config ) {
			return new \WP_Error( 'rest_connection_not_found', 'Connection not found.', [ 'status' => 404 ] );
		}

		return rest_ensure_response( Connection_Repository::redact_credentials( $config ) );
	}

	/**
	 * Validate a Comfy Cloud MCP connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function test_connection( \WP_REST_Request $request ) {
		$connection_id = absint( $request->get_param( 'id' ) );
		$result        = Connection_Tester::test( $connection_id );

		$response = rest_ensure_response( $result );
		$response->set_status( $result['success'] ? 200 : 422 );

		return $response;
	}

	/**
	 * Refresh the Comfy Cloud MCP provider capabilities.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function sync_capabilities( \WP_REST_Request $request ) {
		$result = Capability_Sync::sync();

		if ( ! $result['success'] ) {
			return new \WP_Error( 'rest_capability_sync_failed', $result['message'], [ 'status' => 502 ] );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Return the saved Comfy catalog snapshot for one Connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_catalog( \WP_REST_Request $request ) {
		$connection_id = absint( $request->get_param( 'id' ) );
		$connection    = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) ) {
			return new \WP_Error( 'rest_connection_not_found', 'Connection not found.', [ 'status' => 404 ] );
		}

		if ( 'comfyui' !== (string) ( $connection['provider_type'] ?? '' ) ) {
			return new \WP_Error( 'rest_connection_not_comfy', 'Catalog operations only apply to ComfyUI connections.', [ 'status' => 400 ] );
		}

		return rest_ensure_response( [
			'connection_id' => $connection_id,
			'snapshot'      => \WorldGraph\Utils\Comfy_Catalog::get( $connection_id ),
		] );
	}

	/**
	 * Sync catalog for one ComfyUI Connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync_catalog( \WP_REST_Request $request ) {
		$result = Connection_CPT::catalog_sync( absint( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Guided prepare flow for headless clients: sync, enable, and materialize.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function prepare_catalog( \WP_REST_Request $request ) {
		$result = Connection_CPT::catalog_prepare_mappable( absint( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Enable one catalog entry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enable_catalog_entry( \WP_REST_Request $request ) {
		$entry_id = sanitize_text_field( rawurldecode( (string) $request->get_param( 'entry_id' ) ) );
		if ( '' === $entry_id ) {
			return new \WP_Error( 'rest_catalog_entry_missing', 'Select a catalog entry first.', [ 'status' => 400 ] );
		}

		$result = Connection_CPT::catalog_enable_entry( absint( $request->get_param( 'id' ) ), $entry_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Disable one catalog entry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function disable_catalog_entry( \WP_REST_Request $request ) {
		$entry_id = sanitize_text_field( rawurldecode( (string) $request->get_param( 'entry_id' ) ) );
		if ( '' === $entry_id ) {
			return new \WP_Error( 'rest_catalog_entry_missing', 'Select a catalog entry first.', [ 'status' => 400 ] );
		}

		$result = Connection_CPT::catalog_disable_entry( absint( $request->get_param( 'id' ) ), $entry_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Materialize one catalog entry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function materialize_catalog_entry( \WP_REST_Request $request ) {
		$entry_id = sanitize_text_field( rawurldecode( (string) $request->get_param( 'entry_id' ) ) );
		if ( '' === $entry_id ) {
			return new \WP_Error( 'rest_catalog_entry_missing', 'Select a catalog entry first.', [ 'status' => 400 ] );
		}

		$result = Connection_CPT::catalog_materialize_entry( absint( $request->get_param( 'id' ) ), $entry_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Request provider-side download for one catalog entry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function download_catalog_entry( \WP_REST_Request $request ) {
		$entry_id = sanitize_text_field( rawurldecode( (string) $request->get_param( 'entry_id' ) ) );
		if ( '' === $entry_id ) {
			return new \WP_Error( 'rest_catalog_entry_missing', 'Select a catalog entry first.', [ 'status' => 400 ] );
		}

		$result = Connection_CPT::catalog_download_entry( absint( $request->get_param( 'id' ) ), $entry_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
