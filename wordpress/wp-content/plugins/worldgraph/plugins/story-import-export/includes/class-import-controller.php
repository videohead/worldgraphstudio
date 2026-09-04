<?php
/**
 * Import REST API controller owned by the Story Import & Export feature plugin.
 *
 * Handles canonical JSON import, story decomposition previews, and project
 * export endpoints for the Story Import & Export feature plugin.
 *
 * @package WorldGraph
 */

namespace WorldGraph\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

require_once __DIR__ . '/class-decomposition-job.php';

/**
 * Import Controller class.
 */
class Import_Controller extends Base_Controller {

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'import';

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
		register_rest_route( 'worldgraph/v1', '/import', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'import_json' ],
				'permission_callback' => [ $this, 'check_import_permission' ],
				'args'                => [
					'json'      => [
						'description' => 'The World Graph Studio JSON document to import.',
						'type'        => 'string',
						'required'    => true,
					],
					'overwrite' => [
						'description' => 'Overwrite existing entities with the same external ID.',
						'type'        => 'boolean',
						'default'     => false,
					],
				],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/import/validate', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'validate_json' ],
				'permission_callback' => [ $this, 'check_import_permission' ],
				'args'                => [
					'json' => [
						'description' => 'The World Graph Studio JSON document to validate.',
						'type'        => 'string',
						'required'    => true,
					],
				],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/import/decompose', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'decompose_story' ],
				'permission_callback' => [ $this, 'check_decomposition_permission' ],
				'args'                => [
					'attachment_id' => [
						'description' => 'A persisted story-source attachment to preview as canonical World Graph Studio JSON.',
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					],
					'connection_id' => [
						'description' => 'The LLM Connection to use for non-canonical story sources. Canonical World Graph Studio JSON does not require one.',
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
					],
				],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/import/decompositions/(?P<job_id>[A-Za-z0-9_-]{32,86})', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_decomposition_job' ],
				'permission_callback' => [ $this, 'check_decomposition_job_permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'step_decomposition_job' ],
				'permission_callback' => [ $this, 'check_decomposition_job_permission' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'cancel_decomposition_job' ],
				'permission_callback' => [ $this, 'check_decomposition_job_permission' ],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/export/(?P<project_id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export_project' ],
				'permission_callback' => [ $this, 'check_export_permission' ],
				'args'                => [
					'project_id' => [
						'description' => 'Project ID.',
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					],
					'format'     => [
						'description' => 'Export format.',
						'type'        => 'string',
						'enum'        => [ 'json', 'screenplay', 'storyboard' ],
						'default'     => 'json',
					],
				],
			],
		] );
	}

	/**
	 * Import a World Graph Studio JSON document.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function import_json( WP_REST_Request $request ) {
		$json      = $request->get_param( 'json' );
		$overwrite = rest_sanitize_boolean( $request->get_param( 'overwrite' ) );

		$importer = new \WorldGraph\Importer\WorldGraph_Importer();
		$result   = $importer->import( $json, [ 'overwrite' => $overwrite ] );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response( [
			'success' => ! empty( $result['verified'] ),
			'report'  => $result,
		] );
	}

	/**
	 * Validate a World Graph Studio JSON document without importing.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function validate_json( WP_REST_Request $request ) {
		$json = $request->get_param( 'json' );

		$importer = new \WorldGraph\Importer\WorldGraph_Importer();
		$result   = $importer->import( $json, [ 'dry_run' => true ] );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => 'JSON is valid.',
		] );
	}

	/**
	 * Build a canonical import preview from one persisted story-source upload.
	 *
	 * The original manuscript is used only on the server. The response contains
	 * the canonical candidate and non-sensitive processing metadata, never the
	 * extracted source text or resolved Connection configuration.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function decompose_story( WP_REST_Request $request ) {
		$attachment_id = absint( $request->get_param( 'attachment_id' ) );
		$connection_id = absint( $request->get_param( 'connection_id' ) );
		$source        = ( new \WorldGraphStoryIO\Source_Extractor() )->extract_attachment( $attachment_id );
		if ( is_wp_error( $source ) ) {
			return $this->safe_error( $source, 400 );
		}

		$source_metadata = [
			'attachment_id' => $attachment_id,
			'filename'      => sanitize_file_name( (string) ( $source['filename'] ?? '' ) ),
			'format'        => sanitize_key( (string) ( $source['format'] ?? '' ) ),
			'characters'    => absint( $source['characters'] ?? 0 ),
		];

		if ( ! empty( $source['is_json'] ) ) {
			$json       = (string) $source['text'];
			$validation = ( new \WorldGraph\Importer\WorldGraph_Importer() )->import( $json, [ 'dry_run' => true ] );
			if ( is_wp_error( $validation ) ) {
				return $this->safe_error( $validation, 400 );
			}

			$document = json_decode( $json, true );
			if ( ! is_array( $document ) ) {
				return new WP_Error( 'worldgraph_story_json_invalid', 'The story source does not contain a valid World Graph Studio JSON document.', [ 'status' => 400 ] );
			}

			$json = wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $json ) {
				return new WP_Error( 'worldgraph_story_json_encode_failed', 'The World Graph Studio JSON preview could not be encoded.', [ 'status' => 500 ] );
			}

			return $this->private_response( [
				'success'       => true,
				'document'      => $document,
				'json'          => $json,
				'source'        => $source_metadata,
				'decomposition' => [
					'generated'     => false,
					'attempts'      => 0,
					'tokens'        => 0,
					'connection_id' => $connection_id,
				],
			] );
		}

		if ( ! $connection_id ) {
			$connection_id = \WorldGraphStoryIO\Story_Decomposer::default_connection_id();
		}
		if ( ! $connection_id ) {
			return new WP_Error(
				'worldgraph_story_connection_required',
				'No usable LLM Connection is configured for story decomposition.',
				[ 'status' => 400 ]
			);
		}
		if ( ! \WorldGraph\Utils\Connection_Repository::current_user_can_manage( $connection_id ) ) {
			return new WP_Error(
				'worldgraph_story_connection_forbidden',
				'You cannot use the configured LLM Connection for story decomposition.',
				[ 'status' => 403 ]
			);
		}

		$result = ( new \WorldGraphStoryIO\Story_Decomposer() )->decompose(
			(string) $source['text'],
			(string) $source['filename'],
			$connection_id,
			is_array( $source['boundaries'] ?? null ) ? $source['boundaries'] : []
		);
		if ( is_wp_error( $result ) ) {
			$status = 'worldgraph_llm_request_failed' === (string) $result->get_error_code() ? 502 : 422;
			return $this->safe_error( $result, $status );
		}

		return $this->private_response( [
			'success'       => true,
			'document'      => $result['document'],
			'json'          => $result['json'],
			'source'        => $source_metadata,
			'decomposition' => [
				'generated'     => true,
				'attempts'      => absint( $result['attempts'] ?? 0 ),
				'tokens'        => absint( $result['tokens'] ?? 0 ),
				'backend'       => sanitize_key( (string) ( $result['backend'] ?? '' ) ),
				'model'         => sanitize_text_field( (string) ( $result['model'] ?? '' ) ),
				'connection_id' => $connection_id,
				'chunks'        => max( 1, absint( $result['chunks'] ?? 1 ) ),
				'passes'         => max( 1, absint( $result['passes'] ?? 2 ) ),
				'sections'       => array_values( array_map( 'sanitize_text_field', array_filter( (array) ( $result['sections'] ?? [] ), 'is_scalar' ) ) ),
				'context_window' => absint( $result['context_window'] ?? 0 ),
				'source_hash'    => sanitize_text_field( (string) ( $result['source_hash'] ?? '' ) ),
			],
		] );
	}

	/** Return the safe public projection of one private decomposition job. */
	public function get_decomposition_job( WP_REST_Request $request ) {
		return $this->decomposition_job_response(
			\WorldGraphStoryIO\Decomposition_Job::status( (string) $request->get_param( 'job_id' ) )
		);
	}

	/** Advance exactly one bounded analysis, synthesis, or finalization stage. */
	public function step_decomposition_job( WP_REST_Request $request ) {
		return $this->decomposition_job_response(
			\WorldGraphStoryIO\Decomposition_Job::step( (string) $request->get_param( 'job_id' ) )
		);
	}

	/** Request cancellation of one private decomposition job. */
	public function cancel_decomposition_job( WP_REST_Request $request ) {
		return $this->decomposition_job_response(
			\WorldGraphStoryIO\Decomposition_Job::cancel( (string) $request->get_param( 'job_id' ) )
		);
	}

	/**
	 * Export one Project through the feature plugin's canonical exporters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public function export_project( WP_REST_Request $request ) {
		$project_id = absint( $request->get_param( 'project_id' ) );
		$format     = sanitize_key( (string) $request->get_param( 'format' ) );
		$format     = $format ?: 'json';
		$basename   = sanitize_file_name( get_the_title( $project_id ) ?: 'worldgraph-export' );
		$basename   = $basename ?: 'worldgraph-export';

		if ( 'json' === $format ) {
			$content   = ( new \WorldGraph\Exporter\WorldGraph_JSON_Exporter() )->export_project( $project_id );
			$filename  = $basename . '.worldgraph.json';
			$mime_type = 'application/json';
		} else {
			$exporter = new \WorldGraph\Exporter\WorldGraph_Exporter();
			if ( 'storyboard' === $format ) {
				$content  = $exporter->export_project_storyboard_markdown( $project_id );
				$filename = $basename . '-storyboard.md';
			} else {
				$content  = $exporter->export_project_markdown( $project_id );
				$filename = $basename . '-screenplay.md';
			}
			$mime_type = 'text/markdown';
		}

		if ( is_wp_error( $content ) ) {
			return $this->safe_error( $content, 422 );
		}

		return $this->private_response( [
			'success'    => true,
			'project_id' => $project_id,
			'format'     => $format,
			'filename'   => $filename,
			'mime_type'  => $mime_type,
			'content'    => $content,
		] );
	}

	/**
	 * Check permissions for import endpoints.
	 *
	 * @return bool|WP_Error
	 */
	public function check_import_permission() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', 'You must be an administrator to import World Graph Studio data.', [ 'status' => 403 ] );
		}
		return true;
	}

	/** Authorize private job endpoints; token and user scoping are enforced on load. */
	public function check_decomposition_job_permission() {
		return $this->check_import_permission();
	}

	/**
	 * Authorize a story preview against its attachment and selected Connection.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function check_decomposition_permission( WP_REST_Request $request ) {
		$permission = $this->check_import_permission();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$attachment_id = absint( $request->get_param( 'attachment_id' ) );
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'rest_story_source_not_found', 'Story source attachment not found.', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'read_post', $attachment_id ) ) {
			return new WP_Error( 'rest_forbidden', 'You cannot read this story source attachment.', [ 'status' => 403 ] );
		}
		if ( ! $this->attachment_is_in_uploads( $attachment_id ) ) {
			return new WP_Error( 'rest_story_source_path_invalid', 'The story source attachment is not stored in the WordPress uploads directory.', [ 'status' => 400 ] );
		}

		$connection_id = absint( $request->get_param( 'connection_id' ) );
		if ( $connection_id && ! \WorldGraph\Utils\Connection_Repository::current_user_can_manage( $connection_id ) ) {
			return new WP_Error( 'rest_forbidden', 'You cannot use this World Graph Studio Connection.', [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Authorize an export against the named Project.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function check_export_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'You must be logged in to export World Graph Studio data.', [ 'status' => 401 ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', 'You must be an administrator to export World Graph Studio data.', [ 'status' => 403 ] );
		}

		$project_id = absint( $request->get_param( 'project_id' ) );
		$project    = get_post( $project_id );
		if ( ! $project instanceof \WP_Post || 'worldgraph_project' !== $project->post_type ) {
			return new WP_Error( 'rest_project_not_found', 'Project not found.', [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'read_post', $project_id ) ) {
			return new WP_Error( 'rest_forbidden', 'You cannot read this Project.', [ 'status' => 403 ] );
		}

		return true;
	}

	/** Verify that an attachment resolves within the uploads tree. */
	private function attachment_is_in_uploads( int $attachment_id ): bool {
		$path        = get_attached_file( $attachment_id );
		$uploads     = wp_upload_dir( null, false );
		$real_path   = is_string( $path ) ? realpath( $path ) : false;
		$real_upload = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( false === $real_path || false === $real_upload ) {
			return false;
		}

		$real_path   = wp_normalize_path( $real_path );
		$real_upload = trailingslashit( wp_normalize_path( $real_upload ) );
		return str_starts_with( $real_path, $real_upload );
	}

	/** Return sensitive story/export content with explicit no-store headers. */
	private function private_response( array $data ): WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	/** Return a no-store job response without forwarding private WP_Error data. */
	private function decomposition_job_response( $result ) {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
			$status = $status ?: 400;
			$error  = $this->safe_error( $result, $status );
			$response = $this->private_response( [
				'code'    => (string) $error->get_error_code(),
				'message' => (string) $error->get_error_message(),
				'data'    => [ 'status' => $status ],
			] );
			$response->set_status( $status );
			return $response;
		}

		return $this->private_response( [
			'success' => true,
			'job'     => is_array( $result ) ? $result : [],
		] );
	}

	/** Rebuild a trusted REST error without carrying arbitrary provider data. */
	private function safe_error( WP_Error $error, int $status ): WP_Error {
		$code    = sanitize_key( (string) $error->get_error_code() );
		$message = sanitize_text_field( (string) $error->get_error_message() );
		if ( str_starts_with( $code, 'worldgraph_credential_' ) ) {
			$message = 'The selected LLM Connection could not complete the request. Review the Connection and try again.';
		}
		return new WP_Error( $code ?: 'worldgraph_story_io_error', $message ?: 'The Story Import & Export request failed.', [ 'status' => $status ] );
	}
}
