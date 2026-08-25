<?php
/**
 * Scenes REST API Controller for World Graph Studio.
 *
 * @package WorldGraph
 */

namespace WorldGraph\REST;

/**
 * Scenes Controller class.
 */
class Scenes_Controller extends Base_Controller {

	/**
	 * CPT slug.
	 *
	 * @var string
	 */
	protected $cpt = 'worldgraph_scene';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'scenes';

	/**
	 * Initialize the controller.
	 */
	public static function init(): void {
		$instance = new self();
		add_action( 'rest_api_init', [ $instance, 'register_routes' ] );
	}

	/** Validate Scene ownership before a REST create can mutate state. */
	public function check_create_permission( \WP_REST_Request $request ) {
		$permission = parent::check_create_permission( $request );
		return is_wp_error( $permission ) ? $permission : $this->validate_parent_request( $request );
	}

	/** Validate Scene ownership before a REST update can mutate state. */
	public function check_update_permission( \WP_REST_Request $request ) {
		$permission = parent::check_update_permission( $request );
		return is_wp_error( $permission ) ? $permission : $this->validate_parent_request( $request );
	}

	/** Resolve submitted/stored parent fields and reject a cross-Project pair. */
	private function validate_parent_request( \WP_REST_Request $request ) {
		$meta       = $request->get_param( 'meta' );
		$meta       = is_array( $meta ) ? $meta : [];
		$post_id    = absint( $request->get_param( 'id' ) );
		$episode_id = array_key_exists( 'episode', $meta )
			? self::relationship_request_id( $meta['episode'] )
			: ( $post_id ? self::relationship_request_id( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'episode' ) ) : 0 );
		$project_id = array_key_exists( 'project', $meta )
			? self::relationship_request_id( $meta['project'] )
			: ( $post_id ? self::relationship_request_id( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'project' ) ) : 0 );

		return \WorldGraph\CPT\Scene::validate_parent_pair( $episode_id, $project_id );
	}

	/** Normalize a REST relationship scalar/list to one post ID. */
	private static function relationship_request_id( $value ): int {
		return absint( is_array( $value ) ? reset( $value ) : $value );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( 'worldgraph/v1', '/scenes', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'check_read_permission' ],
				'args'                => [
					'page'     => [ 'default' => 1 ],
					'per_page' => [ 'default' => 10, 'maximum' => 100 ],
					'project'  => [ 'type' => 'integer' ],
					'episode'  => [ 'type' => 'integer' ],
					'location' => [ 'type' => 'integer' ],
					'sequence' => [
						'description' => 'Filter by sequence slug (or comma-separated slugs).',
						'type'        => 'string',
					],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'check_create_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/scenes/(?P<id>\d+)', [
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

		register_rest_route( 'worldgraph/v1', '/scenes/(?P<id>\d+)/graph', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_graph' ],
			'permission_callback' => [ $this, 'check_read_permission' ],
		] );

		// Reorder the complete Scene membership of one Sequence.
		register_rest_route( 'worldgraph/v1', '/scenes/reorder', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'reorder_items' ],
			'permission_callback' => [ $this, 'check_reorder_permission' ],
			'args'                => [
				'ordered_ids' => [
					'description' => 'Every Scene post ID assigned to the Sequence, in the new order.',
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'required'    => true,
				],
				'sequence_id' => [
					'description' => 'Sequence term whose complete Scene membership is being reordered.',
					'type'        => 'integer',
					'minimum'     => 1,
					'required'    => true,
				],
			],
		] );
	}

	/**
	 * Check whether the current request may reorder every Scene in a Sequence.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	public function check_reorder_permission( \WP_REST_Request $request ) {
		$validated = self::validate_reorder_request( $request, true );

		return is_wp_error( $validated ) ? $validated : true;
	}

	/**
	 * Reorder the complete Scene membership of one Sequence.
	 *
	 * Only `sequence_order` is changed. Original metadata is restored in full if
	 * any write cannot be verified.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function reorder_items( \WP_REST_Request $request ) {
		$validated = self::validate_reorder_request( $request, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$sequence_id = $validated['sequence_id'];
		$lock_token  = self::acquire_sequence_reorder_lock( $sequence_id );
		if ( false === $lock_token ) {
			return new \WP_Error( 'rest_scene_reorder_locked', 'Another Scene order is being saved for this Sequence. Try again.', [ 'status' => 409 ] );
		}

		try {
			// Membership and capabilities may have changed after permission dispatch.
			$validated = self::validate_reorder_request( $request, true );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$ordered_ids   = $validated['ordered_ids'];
			$original_meta = [];
			foreach ( $ordered_ids as $scene_id ) {
				$original_meta[ $scene_id ] = self::get_raw_order_meta( 'post', $scene_id, 'sequence_order' );
			}

			foreach ( $ordered_ids as $index => $scene_id ) {
				update_post_meta( $scene_id, 'sequence_order', $index + 1 );
				if ( ! self::has_verified_order_meta( 'post', $scene_id, 'sequence_order', $index + 1 ) ) {
					$rolled_back = self::rollback_order_meta( 'post', $original_meta, 'sequence_order' );
					$message     = $rolled_back
						? 'A Scene order could not be saved; the original order was restored.'
						: 'A Scene order could not be saved, and restoration could not be fully verified.';
					return new \WP_Error( 'rest_scene_reorder_failed', $message, [ 'status' => 500 ] );
				}
			}

			return rest_ensure_response( [ 'updated' => $ordered_ids ] );
		} catch ( \Throwable $error ) {
			$rolled_back = true;
			if ( isset( $original_meta ) ) {
				$rolled_back = self::rollback_order_meta( 'post', $original_meta, 'sequence_order' );
			}

			$message = $rolled_back
				? 'The Scene order could not be saved; the original order was restored.'
				: 'The Scene order could not be saved, and restoration could not be fully verified.';
			return new \WP_Error( 'rest_scene_reorder_failed', $message, [ 'status' => 500 ] );
		} finally {
			self::release_sequence_reorder_lock( $sequence_id, $lock_token );
		}
	}

	/**
	 * Validate one complete, authorized Sequence-scoped Scene order.
	 *
	 * @param \WP_REST_Request $request           REST request.
	 * @param bool             $check_permissions Whether to enforce assignment and edit capabilities.
	 * @return array{sequence_id:int,ordered_ids:array<int,int>}|\WP_Error
	 */
	private static function validate_reorder_request( \WP_REST_Request $request, bool $check_permissions ) {
		$raw_sequence_id = $request->get_param( 'sequence_id' );
		$is_integer      = is_int( $raw_sequence_id ) || ( is_string( $raw_sequence_id ) && ctype_digit( $raw_sequence_id ) );
		$sequence_id     = $is_integer ? (int) $raw_sequence_id : 0;
		if ( $sequence_id < 1 ) {
			return new \WP_Error( 'rest_invalid_sequence', 'A valid sequence_id is required.', [ 'status' => 400 ] );
		}

		$sequence = get_term( $sequence_id, \WorldGraph\Taxonomies\Sequence::TAXONOMY );
		if ( ! $sequence || is_wp_error( $sequence ) ) {
			return new \WP_Error( 'rest_invalid_sequence', 'Sequence term not found.', [ 'status' => 404 ] );
		}

		$taxonomy = get_taxonomy( \WorldGraph\Taxonomies\Sequence::TAXONOMY );
		if ( ! $taxonomy ) {
			return new \WP_Error( 'rest_sequence_taxonomy_unavailable', 'Sequence taxonomy is unavailable.', [ 'status' => 500 ] );
		}
		if ( $check_permissions && ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			return new \WP_Error( 'rest_forbidden', 'You cannot assign this Sequence taxonomy.', [ 'status' => is_user_logged_in() ? 403 : 401 ] );
		}

		$submitted = $request->get_param( 'ordered_ids' );
		if ( ! is_array( $submitted ) || empty( $submitted ) ) {
			return new \WP_Error( 'rest_invalid_ordered_ids', 'ordered_ids cannot be empty.', [ 'status' => 400 ] );
		}

		$ordered_ids = [];
		foreach ( $submitted as $submitted_id ) {
			$is_integer = is_int( $submitted_id ) || ( is_string( $submitted_id ) && ctype_digit( $submitted_id ) );
			$scene_id   = $is_integer ? (int) $submitted_id : 0;
			if ( $scene_id < 1 || 'worldgraph_scene' !== get_post_type( $scene_id ) ) {
				return new \WP_Error( 'rest_invalid_ordered_ids', 'ordered_ids must contain only valid Scene post IDs.', [ 'status' => 400 ] );
			}
			$ordered_ids[] = $scene_id;
		}

		if ( count( $ordered_ids ) !== count( array_unique( $ordered_ids ) ) ) {
			return new \WP_Error( 'rest_invalid_ordered_ids', 'ordered_ids cannot contain duplicate Scene IDs.', [ 'status' => 400 ] );
		}

		$object_ids = get_objects_in_term( $sequence_id, \WorldGraph\Taxonomies\Sequence::TAXONOMY );
		if ( is_wp_error( $object_ids ) ) {
			return new \WP_Error( 'rest_sequence_membership_unavailable', 'Sequence membership could not be read.', [ 'status' => 500 ] );
		}
		$expected_ids = array_values(
			array_filter(
				array_unique( array_map( 'absint', (array) $object_ids ) ),
				static fn( int $post_id ): bool => 'worldgraph_scene' === get_post_type( $post_id )
			)
		);
		$submitted_set = $ordered_ids;
		$expected_set  = $expected_ids;
		sort( $submitted_set, SORT_NUMERIC );
		sort( $expected_set, SORT_NUMERIC );
		if ( $submitted_set !== $expected_set ) {
			return new \WP_Error( 'rest_scene_reorder_membership', 'Submit every Scene assigned to this Sequence exactly once.', [ 'status' => 400 ] );
		}

		if ( $check_permissions ) {
			foreach ( $ordered_ids as $scene_id ) {
				if ( ! current_user_can( 'edit_post', $scene_id ) ) {
					return new \WP_Error( 'rest_forbidden', 'You cannot edit one or more Scenes in this Sequence.', [ 'status' => 403 ] );
				}
			}
		}

		return [
			'sequence_id' => $sequence_id,
			'ordered_ids' => $ordered_ids,
		];
	}

	/** Acquire a short, Sequence-scoped lock for Scene order writes. */
	private static function acquire_sequence_reorder_lock( int $sequence_id ): string|false {
		global $wpdb;

		$key      = 'worldgraph_scene_reorder_lock_' . $sequence_id;
		$now      = time();
		$token    = $now . ':' . wp_generate_uuid4();
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic mutex row; cache is explicitly invalidated.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				$token,
				'no'
			)
		);
		if ( 1 === $inserted ) {
			self::clear_reorder_lock_cache( $key );
			return $token;
		}

		$current_token = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock ownership must bypass the option cache.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key )
		);
		$locked_at     = absint( strtok( $current_token, ':' ) );
		if ( $locked_at && $now - $locked_at > 300 ) {
			$claimed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-swap lock ownership.
				$wpdb->options,
				[ 'option_value' => $token ],
				[ 'option_name' => $key, 'option_value' => $current_token ],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			if ( 1 === $claimed ) {
				self::clear_reorder_lock_cache( $key );
				return $token;
			}
		}

		return false;
	}

	/** Release a Scene ordering lock only while this request still owns it. */
	private static function release_sequence_reorder_lock( int $sequence_id, string $token ): void {
		global $wpdb;

		if ( '' === $token ) {
			return;
		}

		$key     = 'worldgraph_scene_reorder_lock_' . $sequence_id;
		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Token-qualified delete cannot release another request's lock.
			$wpdb->options,
			[ 'option_name' => $key, 'option_value' => $token ],
			[ '%s', '%s' ]
		);
		if ( $deleted ) {
			self::clear_reorder_lock_cache( $key );
		}
	}

	/** Clear all core option-cache locations touched by the direct lock row. */
	private static function clear_reorder_lock_cache( string $key ): void {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Read every current value for one order key, preserving absence and duplicates. */
	private static function get_raw_order_meta( string $meta_type, int $object_id, string $meta_key ): array {
		$values = get_metadata_raw( $meta_type, $object_id, $meta_key, false );

		return is_array( $values ) ? $values : [];
	}

	/** Verify that an order write produced exactly one value with the requested position. */
	private static function has_verified_order_meta( string $meta_type, int $object_id, string $meta_key, int $order ): bool {
		$values = self::get_raw_order_meta( $meta_type, $object_id, $meta_key );

		return 1 === count( $values ) && (string) $order === (string) reset( $values );
	}

	/** Restore every original raw order-meta value after a failed batch. */
	private static function rollback_order_meta( string $meta_type, array $original_meta, string $meta_key ): bool {
		$restored = true;
		foreach ( $original_meta as $object_id => $values ) {
			delete_metadata( $meta_type, $object_id, $meta_key );
			foreach ( $values as $value ) {
				if ( false === add_metadata( $meta_type, $object_id, $meta_key, wp_slash( $value ), false ) ) {
					$restored = false;
				}
			}
			if ( ! self::raw_order_meta_matches( $values, self::get_raw_order_meta( $meta_type, $object_id, $meta_key ) ) ) {
				$restored = false;
			}
		}

		return $restored;
	}

	/** Compare metadata by the exact values WordPress serializes to storage. */
	private static function raw_order_meta_matches( array $expected, array $actual ): bool {
		$serialize = static fn( $value ): string => (string) maybe_serialize( $value );

		return array_map( $serialize, $expected ) === array_map( $serialize, $actual );
	}

	/**
	 * Get graph connections.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_graph( \WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$entities = \WorldGraph\Utils\get_graph_entities( $post_id, 'worldgraph_scene' );
		return rest_ensure_response( $entities );
	}

	/**
	 * Override to add scene-specific fields.
	 *
	 * @param \WP_Post $post
	 * @param array    $params
	 * @return array
	 */
	protected function get_item_data( \WP_Post $post, array $params = [] ): array {
		$data = parent::get_item_data( $post, $params );

		// Get scene tags.
		$tags = get_the_terms( $post->ID, 'worldgraph_scene_tag' );
		if ( $tags && ! is_wp_error( $tags ) ) {
			$data['meta']['scene_tags'] = array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $tags );
		}

		// Get sequence terms.
		$sequences = get_the_terms( $post->ID, 'worldgraph_sequence' );
		if ( $sequences && ! is_wp_error( $sequences ) ) {
			$data['meta']['sequences'] = array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $sequences );
		}

		// Position of the scene within its sequence / the project cut.
		$data['meta']['sequence_order'] = get_post_meta( $post->ID, 'sequence_order', true );
		$data['meta']['menu_order']     = (int) $post->menu_order;

		// Count related shots.
		$data['meta']['shot_count'] = self::count_related( $post->ID, 'worldgraph_shot', 'worldgraph_scene' );

		return $data;
	}

	/**
	 * Count related items.
	 *
	 * @param int    $post_id
	 * @param string $related_cpt
	 * @param string $from_cpt
	 * @return int
	 */
	private static function count_related( int $post_id, string $related_cpt, string $from_cpt ): int {
		$related_ids = [];

		// Support legacy parent-owned edges as well as the canonical child-owned
		// Shot.scene relationship without double-counting reciprocal records.
		foreach ( \WorldGraph\Utils\get_relationships( $post_id, $from_cpt, 'outgoing' ) as $relationship ) {
			if ( $related_cpt === (string) ( $relationship['to_type'] ?? '' ) ) {
				$related_ids[ (int) ( $relationship['to_id'] ?? 0 ) ] = true;
			}
		}

		foreach ( \WorldGraph\Utils\get_relationships( $post_id, $from_cpt, 'incoming' ) as $relationship ) {
			if ( $related_cpt === (string) ( $relationship['from_type'] ?? '' ) ) {
				$related_ids[ (int) ( $relationship['from_id'] ?? 0 ) ] = true;
			}
		}

		unset( $related_ids[0] );
		return count( $related_ids );
	}
}
