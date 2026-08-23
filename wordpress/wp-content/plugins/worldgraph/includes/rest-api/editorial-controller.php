<?php
/**
 * Editorial REST API Controller for World Graph Studio.
 *
 * Handles editorial workflows, exports, and review processes.
 *
 * @package WorldGraph
 */

namespace WorldGraph\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Editorial Controller class.
 */
class Editorial_Controller extends Base_Controller {

	/**
	 * CPT slug (not used).
	 *
	 * @var string
	 */
	protected $cpt = '';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'editorial';

	/**
	 * Initialize the controller.
	 */
	public static function init(): void {
		$instance = new self();
		add_action( 'rest_api_init', [ $instance, 'register_routes' ] );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Get editorial overview.
		register_rest_route( 'worldgraph/v1', '/editorial/(?P<project_id>\d+)/overview', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_overview' ],
			'permission_callback' => [ $this, 'check_project_read_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
			],
		] );

		// Get editorial artifacts.
		register_rest_route( 'worldgraph/v1', '/editorial/(?P<project_id>\d+)/artifacts', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_artifacts' ],
			'permission_callback' => [ $this, 'check_project_read_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'type'       => [
					'description' => 'Filter by artifact type.',
					'type'        => 'string',
				],
				'page'       => [ 'default' => 1 ],
				'per_page'   => [ 'default' => 20, 'maximum' => 100 ],
			],
		] );

		// Create editorial artifact.
		register_rest_route( 'worldgraph/v1', '/editorial/(?P<project_id>\d+)/artifacts', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_artifact' ],
			'permission_callback' => [ $this, 'check_project_edit_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'type'       => [
					'description' => 'Artifact type.',
					'type'        => 'string',
					'required'    => true,
				],
				'format'     => [
					'description' => 'Export format.',
					'type'        => 'string',
				],
			],
		] );

		// Export project.
		register_rest_route( 'worldgraph/v1', '/editorial/(?P<project_id>\d+)/export', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'export_project' ],
			'permission_callback' => [ $this, 'check_project_edit_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'format'     => [
					'description' => 'Export format (pdf, json, xml).',
					'type'        => 'string',
					'default'     => 'json',
				],
				'scope'      => [
					'description' => 'Export scope (full, scenes, shots).',
					'type'        => 'string',
					'default'     => 'full',
				],
			],
		] );

		// Get review notes.
		register_rest_route( 'worldgraph/v1', '/editorial/(?P<project_id>\d+)/reviews', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_reviews' ],
			'permission_callback' => [ $this, 'check_project_read_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'page'       => [ 'default' => 1 ],
				'per_page'   => [ 'default' => 20, 'maximum' => 100 ],
			],
		] );

		// Add review note.
		register_rest_route( 'worldgraph/v1', '/editorial/(?P<project_id>\d+)/reviews', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'add_review' ],
			'permission_callback' => [ $this, 'check_project_edit_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'content'    => [
					'description' => 'Review content.',
					'type'        => 'string',
					'required'    => true,
				],
				'entity_id'  => [
					'description' => 'Associated entity ID.',
					'type'        => 'integer',
				],
				'entity_type' => [
					'description' => 'Associated entity type.',
					'type'        => 'string',
				],
			],
		] );
	}

	/**
	 * Authorize a read against the Project named by the route.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function check_project_read_permission( WP_REST_Request $request ) {
		return $this->check_project_permission( $request, 'read_post' );
	}

	/**
	 * Authorize a mutation or export against the Project named by the route.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function check_project_edit_permission( WP_REST_Request $request ) {
		return $this->check_project_permission( $request, 'edit_post' );
	}

	/**
	 * Check an object capability on the route's Project.
	 *
	 * @param WP_REST_Request $request    REST request.
	 * @param string          $capability Object capability to check.
	 * @return true|WP_Error
	 */
	private function check_project_permission( WP_REST_Request $request, string $capability ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'You must be logged in to access this resource.', [ 'status' => 401 ] );
		}

		$project_id = absint( $request->get_param( 'project_id' ) );
		$project    = get_post( $project_id );
		if ( ! $project || 'worldgraph_project' !== $project->post_type ) {
			return new WP_Error( 'rest_project_not_found', 'Project not found.', [ 'status' => 404 ] );
		}

		if ( ! current_user_can( $capability, $project_id ) ) {
			$message = 'read_post' === $capability ? 'You cannot read this Project.' : 'You cannot edit this Project.';
			return new WP_Error( 'rest_forbidden', $message, [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Get editorial overview.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_overview( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );

		// Get project details.
		$project = get_post( $project_id );
		if ( ! $project || $project->post_type !== 'worldgraph_project' ) {
			return new WP_Error( 'project_not_found', 'Project not found.', [ 'status' => 404 ] );
		}

		// Get counts.
		$artifact_count = count_user_posts( $project_id, 'worldgraph_editorial' );
		$review_count = count_user_posts( $project_id, 'worldgraph_review' );

		// Get export history.
		$exports = get_post_meta( $project_id, '_worldgraph_export_history', true ) ?: [];

		return rest_ensure_response( [
			'project'       => [
				'id'    => $project_id,
				'title' => $project->post_title,
			],
			'counts'        => [
				'artifacts' => $artifact_count,
				'reviews'   => $review_count,
			],
			'export_count'  => count( $exports ),
			'last_export'   => ! empty( $exports ) ? end( $exports )['date'] : null,
		] );
	}

	/**
	 * Get editorial artifacts.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_artifacts( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );
		$type = $request->get_param( 'type' );
		$page = absint( $request->get_param( 'page' ) ) ?: 1;
		$per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;

		$args = [
			'post_type'      => 'worldgraph_editorial',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => [
				[
					'key'   => 'project',
					'value' => $project_id,
				],
			],
		];

		if ( $type ) {
			$args['meta_query'][] = [
				'key'   => 'artifact_type',
				'value' => $type,
			];
		}

		$query = new \WP_Query( $args );
		$artifacts = [];

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$artifacts[] = [
					'id'           => $post->ID,
					'type'         => \WorldGraph\Utils\worldgraph_get_field_value( $post->ID, 'artifact_type' ),
					'format'       => \WorldGraph\Utils\worldgraph_get_field_value( $post->ID, 'export_format' ),
					'created_date' => \WorldGraph\Utils\worldgraph_get_field_value( $post->ID, 'generated_date' ),
					'title'        => $post->post_title,
				];
			}
			wp_reset_postdata();
		}

		$response = rest_ensure_response( $artifacts );
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	/**
	 * Create an editorial artifact.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_artifact( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );
		$type       = sanitize_key( (string) $request->get_param( 'type' ) );
		$format     = sanitize_key( (string) ( $request->get_param( 'format' ) ?: 'json' ) );

		// Validate project exists.
		$project = get_post( $project_id );
		if ( ! $project || $project->post_type !== 'worldgraph_project' ) {
			return new WP_Error( 'project_not_found', 'Project not found.', [ 'status' => 404 ] );
		}

		$post_id = wp_insert_post( [
			'post_type'   => 'worldgraph_editorial',
			'post_title'  => "Artifact: {$type} - " . current_time( 'mysql' ),
			'post_status' => 'draft',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save artifact metadata.
		\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'artifact_type', $type );
		\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'export_format', $format );
		\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'generated_date', current_time( 'Y-m-d' ) );
		\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'project', $project_id );

		return rest_ensure_response( [
			'id'         => $post_id,
			'type'       => $type,
			'format'     => $format,
			'created_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Export a project.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function export_project( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );
		$format = $request->get_param( 'format' ) ?: 'json';
		$scope = $request->get_param( 'scope' ) ?: 'full';

		// Validate project exists.
		$project = get_post( $project_id );
		if ( ! $project || $project->post_type !== 'worldgraph_project' ) {
			return new WP_Error( 'project_not_found', 'Project not found.', [ 'status' => 404 ] );
		}

		// Build export data.
		$data = self::build_export_data( $project_id, $scope );

		// Format export.
		$exported = self::format_export( $data, $format );

		// Log export.
		$exports = get_post_meta( $project_id, '_worldgraph_export_history', true ) ?: [];
		$exports[] = [
			'date'  => current_time( 'mysql' ),
			'format' => $format,
			'scope' => $scope,
		];
		update_post_meta( $project_id, '_worldgraph_export_history', $exports );

		return rest_ensure_response( [
			'message'  => 'Export completed.',
			'format'   => $format,
			'scope'    => $scope,
			'data'     => $exported,
		] );
	}

	/**
	 * Build export data.
	 *
	 * @param int    $project_id
	 * @param string $scope
	 * @return array
	 */
	private static function build_export_data( int $project_id, string $scope ): array {
		$data = [
			'project' => get_post( $project_id ),
		];
		$scene_ids = self::project_scene_ids( $project_id );

		if ( in_array( $scope, [ 'full', 'scenes' ], true ) ) {
			$data['scenes'] = new \WP_Query( [
				'post_type'      => 'worldgraph_scene',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'post__in'       => $scene_ids ?: [ 0 ],
				'orderby'        => 'post__in',
			] );
		}

		if ( in_array( $scope, [ 'full', 'shots' ], true ) ) {
			$shot_ids = self::project_shot_ids( $scene_ids );
			$data['shots'] = new \WP_Query( [
				'post_type'      => 'worldgraph_shot',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'post__in'       => $shot_ids ?: [ 0 ],
				'orderby'        => 'post__in',
			] );
		}

		return $data;
	}

	/**
	 * Resolve the Scenes that belong to one Project through canonical or legacy edges.
	 *
	 * @param int $project_id Project post ID.
	 * @return array<int, int>
	 */
	private static function project_scene_ids( int $project_id ): array {
		$scene_ids   = [];
		$episode_ids = [];

		foreach ( self::relationship_targets( $project_id, 'worldgraph_project' ) as $target ) {
			if ( 'worldgraph_scene' === $target['type'] ) {
				$scene_ids[] = $target['id'];
			} elseif ( 'worldgraph_episode' === $target['type'] ) {
				$episode_ids[] = $target['id'];
			}
		}

		$episodes = get_posts( [
			'post_type'      => 'worldgraph_episode',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		] );
		foreach ( $episodes as $episode ) {
			$episode_id = is_object( $episode ) ? absint( $episode->ID ?? 0 ) : absint( $episode );
			$owner_id   = absint( \WorldGraph\Utils\worldgraph_get_field_value( $episode_id, 'project' ) );
			if ( $project_id === $owner_id || self::has_relationship_target( $episode_id, 'worldgraph_episode', $project_id, 'worldgraph_project' ) ) {
				$episode_ids[] = $episode_id;
			}
		}
		$episode_ids = array_values( array_unique( array_filter( array_map( 'absint', $episode_ids ) ) ) );

		foreach ( $episode_ids as $episode_id ) {
			foreach ( self::relationship_targets( $episode_id, 'worldgraph_episode' ) as $target ) {
				if ( 'worldgraph_scene' === $target['type'] ) {
					$scene_ids[] = $target['id'];
				}
			}
		}

		$scenes = get_posts( [
			'post_type'      => 'worldgraph_scene',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		] );
		foreach ( $scenes as $scene ) {
			$scene_id = is_object( $scene ) ? absint( $scene->ID ?? 0 ) : absint( $scene );
			if (
				$project_id === absint( \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'project' ) )
				|| self::has_relationship_target( $scene_id, 'worldgraph_scene', $project_id, 'worldgraph_project' )
			) {
				$scene_ids[] = $scene_id;
				continue;
			}

			$episode_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'episode' ) );
			if ( in_array( $episode_id, $episode_ids, true ) ) {
				$scene_ids[] = $scene_id;
				continue;
			}

			foreach ( self::relationship_targets( $scene_id, 'worldgraph_scene' ) as $target ) {
				if ( 'worldgraph_episode' === $target['type'] && in_array( $target['id'], $episode_ids, true ) ) {
					$scene_ids[] = $scene_id;
					break;
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $scene_ids ) ) ) );
	}

	/**
	 * Resolve only the Shots that belong to the supplied Project Scenes.
	 *
	 * @param array<int, int> $scene_ids Project Scene IDs.
	 * @return array<int, int>
	 */
	private static function project_shot_ids( array $scene_ids ): array {
		$scene_ids = array_values( array_unique( array_filter( array_map( 'absint', $scene_ids ) ) ) );
		if ( empty( $scene_ids ) ) {
			return [];
		}

		$scene_lookup = array_fill_keys( $scene_ids, true );
		$shot_ids     = [];
		foreach ( $scene_ids as $scene_id ) {
			foreach ( self::relationship_targets( $scene_id, 'worldgraph_scene' ) as $target ) {
				if ( 'worldgraph_shot' === $target['type'] ) {
					$shot_ids[] = $target['id'];
				}
			}
		}

		$shots = get_posts( [
			'post_type'      => 'worldgraph_shot',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		] );
		foreach ( $shots as $shot ) {
			$shot_id  = is_object( $shot ) ? absint( $shot->ID ?? 0 ) : absint( $shot );
			$scene_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $shot_id, 'scene' ) );
			if ( isset( $scene_lookup[ $scene_id ] ) ) {
				$shot_ids[] = $shot_id;
				continue;
			}

			foreach ( self::relationship_targets( $shot_id, 'worldgraph_shot' ) as $target ) {
				if ( 'worldgraph_scene' === $target['type'] && isset( $scene_lookup[ $target['id'] ] ) ) {
					$shot_ids[] = $shot_id;
					break;
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $shot_ids ) ) ) );
	}

	/**
	 * Return normalized outgoing Story Graph targets for an entity.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $post_type Source post type.
	 * @return array<int, array{id:int,type:string}>
	 */
	private static function relationship_targets( int $post_id, string $post_type ): array {
		$targets = [];
		foreach ( \WorldGraph\Utils\get_relationships( $post_id, $post_type, 'outgoing' ) as $relationship ) {
			$target_id   = absint( $relationship['to_id'] ?? 0 );
			$target_type = (string) ( $relationship['to_type'] ?? '' );
			if ( $target_id && '' !== $target_type ) {
				$targets[] = [
					'id'   => $target_id,
					'type' => $target_type,
				];
			}
		}

		return $targets;
	}

	/**
	 * Whether one outgoing Story Graph edge targets the expected object.
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $post_type   Source post type.
	 * @param int    $target_id   Expected target ID.
	 * @param string $target_type Expected target type.
	 * @return bool
	 */
	private static function has_relationship_target( int $post_id, string $post_type, int $target_id, string $target_type ): bool {
		foreach ( self::relationship_targets( $post_id, $post_type ) as $target ) {
			if ( $target_id === $target['id'] && $target_type === $target['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Format export data.
	 *
	 * @param array  $data
	 * @param string $format
	 * @return string|array
	 */
	private static function format_export( array $data, string $format ) {
		switch ( $format ) {
			case 'json':
				return wp_json_encode( $data, JSON_PRETTY_PRINT );
			case 'xml':
				// Simplified XML export.
				return '<?xml version="1.0"?>' . "\n" . '<export>' . count( $data ) . ' items</export>';
			default:
				return $data;
		}
	}

	/**
	 * Get review notes.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_reviews( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );
		$page = absint( $request->get_param( 'page' ) ) ?: 1;
		$per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;

		// Get reviews from post meta.
		$reviews = get_post_meta( $project_id, '_worldgraph_reviews', true ) ?: [];

		$total = count( $reviews );
		$reviews = array_slice( $reviews, ( $page - 1 ) * $per_page, $per_page );

		$response = rest_ensure_response( $reviews );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Add a review note.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_review( WP_REST_Request $request ) {
		$project_id  = absint( $request->get_param( 'project_id' ) );
		$content     = sanitize_textarea_field( (string) $request->get_param( 'content' ) );
		$entity_id   = $request->get_param( 'entity_id' ) ? absint( $request->get_param( 'entity_id' ) ) : null;
		$entity_type = sanitize_key( (string) $request->get_param( 'entity_type' ) );

		$review = [
			'id'          => wp_generate_uuid4(),
			'content'     => $content,
			'entity_id'   => $entity_id,
			'entity_type' => $entity_type,
			'author'      => get_current_user_id(),
			'created_at'  => current_time( 'mysql' ),
		];

		$reviews = get_post_meta( $project_id, '_worldgraph_reviews', true ) ?: [];
		$reviews[] = $review;
		update_post_meta( $project_id, '_worldgraph_reviews', $reviews );

		return rest_ensure_response( $review );
	}
}
