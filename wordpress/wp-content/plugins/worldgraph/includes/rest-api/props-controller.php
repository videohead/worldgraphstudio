<?php
/**
 * Props REST API Controller for World Graph Studio.
 *
 * @package WorldGraph
 */

namespace WorldGraph\REST;

/**
 * Props Controller class.
 */
class Props_Controller extends Base_Controller {

	/**
	 * CPT slug.
	 *
	 * @var string
	 */
	protected $cpt = 'worldgraph_prop';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'props';

	/**
	 * Initialize the controller.
	 */
	public static function init(): void {
		$instance = new self();
		add_action( 'rest_api_init', [ $instance, 'register_routes' ] );
	}

	/** Validate Prop ownership before a REST create can mutate state. */
	public function check_create_permission( \WP_REST_Request $request ) {
		$permission = parent::check_create_permission( $request );
		return is_wp_error( $permission ) ? $permission : $this->validate_parent_request( $request );
	}

	/** Validate Prop ownership before a REST update can mutate state. */
	public function check_update_permission( \WP_REST_Request $request ) {
		$permission = parent::check_update_permission( $request );
		return is_wp_error( $permission ) ? $permission : $this->validate_parent_request( $request );
	}

	/** Resolve submitted/stored parent fields and reject a cross-World pair. */
	private function validate_parent_request( \WP_REST_Request $request ) {
		$meta     = $request->get_param( 'meta' );
		$meta     = is_array( $meta ) ? $meta : [];
		$post_id  = absint( $request->get_param( 'id' ) );
		$owner_id = array_key_exists( 'owner_character', $meta )
			? self::relationship_request_id( $meta['owner_character'] )
			: ( $post_id ? self::relationship_request_id( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'owner_character' ) ) : 0 );
		$world_id = array_key_exists( 'story_world', $meta )
			? self::relationship_request_id( $meta['story_world'] )
			: ( $post_id ? self::relationship_request_id( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'story_world' ) ) : 0 );

		return \WorldGraph\CPT\Prop::validate_parent_pair( $owner_id, $world_id );
	}

	/** Normalize a REST relationship scalar/list to one post ID. */
	private static function relationship_request_id( $value ): int {
		return absint( is_array( $value ) ? reset( $value ) : $value );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( 'worldgraph/v1', '/props', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'check_read_permission' ],
				'args'                => [
					'page'     => [ 'default' => 1 ],
					'per_page' => [ 'default' => 10, 'maximum' => 100 ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'check_create_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/props/(?P<id>\d+)', [
			'args'   => [ 'id' => [ 'type' => 'integer' ] ],
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

		register_rest_route( 'worldgraph/v1', '/props/(?P<id>\d+)/graph', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_graph' ],
			'permission_callback' => [ $this, 'check_read_permission' ],
		] );
	}

	/**
	 * Get graph connections.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_graph( \WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$entities = \WorldGraph\Utils\get_graph_entities( $post_id, 'worldgraph_prop' );
		return rest_ensure_response( $entities );
	}
}
