<?php
/**
 * Production REST API Controller for World Graph Studio.
 *
 * Handles production workflow and pipeline management.
 *
 * @package WorldGraph
 */

namespace WorldGraph\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Production Controller class.
 */
class Production_Controller extends Base_Controller {

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
	protected $rest_base = 'production';

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
		// Get production overview for a project.
		register_rest_route( 'worldgraph/v1', '/production/(?P<project_id>\d+)/overview', [
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

		// Get production pipeline status.
		register_rest_route( 'worldgraph/v1', '/production/(?P<project_id>\d+)/pipeline', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_pipeline' ],
			'permission_callback' => [ $this, 'check_project_read_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
			],
		] );

		// Update production stage.
		register_rest_route( 'worldgraph/v1', '/production/(?P<project_id>\d+)/stage', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_stage' ],
			'permission_callback' => [ $this, 'check_project_edit_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'stage'      => [
					'description' => 'New production stage.',
					'type'        => 'string',
					'required'    => true,
				],
			],
		] );

		// Get production tasks.
		register_rest_route( 'worldgraph/v1', '/production/(?P<project_id>\d+)/tasks', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_tasks' ],
			'permission_callback' => [ $this, 'check_project_read_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'status'     => [
					'description' => 'Filter by task status.',
					'type'        => 'string',
				],
				'page'       => [ 'default' => 1 ],
				'per_page'   => [ 'default' => 20, 'maximum' => 100 ],
			],
		] );

		// Create production task.
		register_rest_route( 'worldgraph/v1', '/production/(?P<project_id>\d+)/tasks', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_task' ],
			'permission_callback' => [ $this, 'check_project_edit_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'title'      => [
					'description' => 'Task title.',
					'type'        => 'string',
					'required'    => true,
				],
				'description' => [
					'description' => 'Task description.',
					'type'        => 'string',
				],
				'status'     => [
					'description' => 'Task status.',
					'type'        => 'string',
					'default'     => 'pending',
				],
			],
		] );

		// Update task status.
		register_rest_route( 'worldgraph/v1', '/production/tasks/(?P<task_id>[A-Za-z0-9-]+)/status', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_task_status' ],
			'permission_callback' => [ $this, 'check_task_edit_permission' ],
			'args'                => [
				'task_id'  => [
					'description' => 'Task ID.',
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9-]+$',
					'required'    => true,
				],
				'status'   => [
					'description' => 'New task status.',
					'type'        => 'string',
					'required'    => true,
				],
			],
		] );

		// Get production timeline.
		register_rest_route( 'worldgraph/v1', '/production/(?P<project_id>\d+)/timeline', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_timeline' ],
			'permission_callback' => [ $this, 'check_project_read_permission' ],
			'args'                => [
				'project_id' => [
					'description' => 'Project ID.',
					'type'        => 'integer',
					'required'    => true,
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
	 * Authorize a mutation against the Project named by the route.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function check_project_edit_permission( WP_REST_Request $request ) {
		return $this->check_project_permission( $request, 'edit_post' );
	}

	/**
	 * Authorize a task mutation against the Project that owns that task.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function check_task_edit_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'You must be logged in to access this resource.', [ 'status' => 401 ] );
		}

		$record = self::find_task( sanitize_text_field( (string) $request->get_param( 'task_id' ) ) );
		if ( null === $record ) {
			return new WP_Error( 'task_not_found', 'Task not found.', [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $record['project_id'] ) ) {
			return new WP_Error( 'rest_forbidden', 'You cannot edit the Project that owns this task.', [ 'status' => 403 ] );
		}

		return true;
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
	 * Get production overview.
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
		$scene_count = count_user_posts( $project_id, 'worldgraph_scene' );
		$shot_count = count_user_posts( $project_id, 'worldgraph_shot' );
		$asset_count = count_user_posts( $project_id, 'worldgraph_asset' );
		$episode_count = count_user_posts( $project_id, 'worldgraph_episode' );

		// Get production stage.
		$stage = \WorldGraph\Utils\worldgraph_get_field_value( $project_id, 'production_stage' ) ?: 'draft';

		return rest_ensure_response( [
			'project'       => [
				'id'   => $project_id,
				'title' => $project->post_title,
				'stage' => $stage,
			],
			'counts'        => [
				'scenes'      => $scene_count,
				'shots'       => $shot_count,
				'assets'      => $asset_count,
				'episodes'    => $episode_count,
			],
			'production_stage' => $stage,
		] );
	}

	/**
	 * Get production pipeline.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_pipeline( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );

		// Define pipeline stages.
		$pipeline = [
			'pre_production' => [
				'label' => 'Pre-Production',
				'status' => 'completed',
				'items' => [
					'type' => 'scenes',
					'count' => count_user_posts( $project_id, 'worldgraph_scene' ),
				],
			],
			'production' => [
				'label' => 'Production',
				'status' => 'in_progress',
				'items' => [
					'type' => 'shots',
					'count' => count_user_posts( $project_id, 'worldgraph_shot' ),
				],
			],
			'post_production' => [
				'label' => 'Post-Production',
				'status' => 'pending',
				'items' => [
					'type' => 'assets',
					'count' => count_user_posts( $project_id, 'worldgraph_asset' ),
				],
			],
			'review' => [
				'label' => 'Review',
				'status' => 'pending',
			],
			'final' => [
				'label' => 'Final',
				'status' => 'pending',
			],
		];

		return rest_ensure_response( $pipeline );
	}

	/**
	 * Update production stage.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_stage( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );
		$stage      = sanitize_key( (string) $request->get_param( 'stage' ) );
		if ( 'worldgraph_project' !== get_post_type( $project_id ) ) {
			return new WP_Error( 'project_not_found', 'Project not found.', [ 'status' => 404 ] );
		}

		$fields       = \WorldGraph\Utils\worldgraph_get_fields( 'worldgraph_project' );
		$valid_stages = array_keys( (array) ( $fields['production_stage']['options'] ?? [] ) );
		if ( ! in_array( $stage, $valid_stages, true ) ) {
			return new WP_Error( 'invalid_stage', 'Invalid production stage.', [ 'status' => 400 ] );
		}

		\WorldGraph\Utils\worldgraph_update_field_value( $project_id, 'production_stage', $stage );

		return rest_ensure_response( [
			'message' => 'Production stage updated.',
			'stage'   => $stage,
		] );
	}

	/**
	 * Get production tasks.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_tasks( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );
		$status = $request->get_param( 'status' );
		$page = absint( $request->get_param( 'page' ) ) ?: 1;
		$per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;

		// Get tasks from post meta.
		$tasks = get_post_meta( $project_id, '_worldgraph_production_tasks', true ) ?: [];

		// Filter by status if specified.
		if ( $status ) {
			$tasks = array_filter( $tasks, fn( $t ) => $t['status'] === $status );
		}

		$total = count( $tasks );
		$tasks = array_slice( $tasks, ( $page - 1 ) * $per_page, $per_page );

		$response = rest_ensure_response( $tasks );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Create a production task.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_task( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );
		$title       = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$description = sanitize_textarea_field( (string) ( $request->get_param( 'description' ) ?: '' ) );
		$status      = sanitize_key( (string) ( $request->get_param( 'status' ) ?: 'pending' ) );
		if ( '' === $title ) {
			return new WP_Error( 'task_title_required', 'Task title is required.', [ 'status' => 400 ] );
		}

		$task = [
			'id'          => wp_generate_uuid4(),
			'title'       => $title,
			'description' => $description,
			'status'      => $status,
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		];

		$tasks = get_post_meta( $project_id, '_worldgraph_production_tasks', true ) ?: [];
		$tasks[] = $task;
		update_post_meta( $project_id, '_worldgraph_production_tasks', $tasks );

		return rest_ensure_response( $task );
	}

	/**
	 * Update task status.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_task_status( WP_REST_Request $request ) {
		$task_id = sanitize_text_field( (string) $request->get_param( 'task_id' ) );
		$status  = sanitize_key( (string) $request->get_param( 'status' ) );
		$record  = self::find_task( $task_id );

		if ( null === $record ) {
			return new WP_Error( 'task_not_found', 'Task not found.', [ 'status' => 404 ] );
		}

		$tasks = $record['tasks'];
		$tasks[ $record['index'] ]['status']     = $status;
		$tasks[ $record['index'] ]['updated_at'] = current_time( 'mysql' );
		update_post_meta( $record['project_id'], '_worldgraph_production_tasks', $tasks );

		return rest_ensure_response( [
			'message' => 'Task status updated.',
			'task'    => [ 'id' => $task_id, 'status' => $status ],
		] );
	}

	/**
	 * Resolve a task to its owning Project and stored array position.
	 *
	 * @param string $task_id Task identifier.
	 * @return array{project_id:int,index:int,tasks:array<int, array<string, mixed>>}|null
	 */
	private static function find_task( string $task_id ): ?array {
		if ( '' === $task_id ) {
			return null;
		}

		$projects = get_posts( [
			'post_type'      => 'worldgraph_project',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_worldgraph_production_tasks',
		] );

		foreach ( $projects as $project ) {
			$project_id = is_object( $project ) ? absint( $project->ID ?? 0 ) : absint( $project );
			$tasks      = get_post_meta( $project_id, '_worldgraph_production_tasks', true );
			if ( ! is_array( $tasks ) ) {
				continue;
			}

			foreach ( $tasks as $index => $task ) {
				if ( is_array( $task ) && $task_id === (string) ( $task['id'] ?? '' ) ) {
					return [
						'project_id' => $project_id,
						'index'      => (int) $index,
						'tasks'      => $tasks,
					];
				}
			}
		}

		return null;
	}

	/**
	 * Get production timeline.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_timeline( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );

		// Get timeline events from post meta.
		$timeline = get_post_meta( $project_id, '_worldgraph_production_timeline', true ) ?: [];

		return rest_ensure_response( $timeline );
	}
}
