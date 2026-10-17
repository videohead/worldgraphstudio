<?php
/** Agent-facing Story Graph workflow abilities. */

namespace WorldGraph\AI\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Generic controller for Templates, which otherwise use the native WP editor. */
final class Agent_Template_Controller extends \WorldGraph\REST\Base_Controller {
	protected $cpt = 'worldgraph_template';
	protected $rest_base = 'templates';
	public static function init(): void {}
}

/** Exposes the complete no-UI workflow while reusing the REST authorization boundary. */
final class Agent_Workflow_Abilities extends AbstractAbilityGroup {
	protected $slug = 'worldgraph-agent-workflows';
	protected $label = 'Agent Workflows';
	protected $description = 'Import, inspect, revise, generate, and review a Story Graph without the UI.';

	/** @var array<string, class-string> */
	private const CONTROLLERS = [
		'project' => '\\WorldGraph\\REST\\Projects_Controller', 'world' => '\\WorldGraph\\REST\\StoryWorlds_Controller',
		'character' => '\\WorldGraph\\REST\\Characters_Controller', 'location' => '\\WorldGraph\\REST\\Locations_Controller',
		'prop' => '\\WorldGraph\\REST\\Props_Controller', 'organization' => '\\WorldGraph\\REST\\Organizations_Controller',
		'episode' => '\\WorldGraph\\REST\\Episodes_Controller', 'scene' => '\\WorldGraph\\REST\\Scenes_Controller',
		'shot' => '\\WorldGraph\\REST\\Shots_Controller', 'sound' => '\\WorldGraph\\REST\\Sounds_Controller',
		'asset' => '\\WorldGraph\\REST\\Assets_Controller', 'editorial-artifact' => '\\WorldGraph\\REST\\EditorialArtifacts_Controller',
		'template' => Agent_Template_Controller::class, 'connection' => '\\WorldGraph\\REST\\Connections_Controller',
	];

	public function register(): void {
		$types = array_keys( self::CONTROLLERS );
		$this->ability( 'content-schema', 'Story Graph Content Schema', 'Discover every agent-editable post type and current SCF field contract.', [ 'type' => [ 'type' => 'string', 'enum' => $types ] ], [], function( $in ) use ( $types ) {
			$out = [];
			foreach ( empty( $in['type'] ) ? $types : [ $in['type'] ] as $type ) {
				$controller = self::controller( $type );
				if ( is_wp_error( $controller ) ) { return $controller; }
				$out[ $type ] = [ 'post_type' => $controller->get_cpt(), 'fields' => \WorldGraph\Utils\worldgraph_get_fields( $controller->get_cpt() ) ];
			}
			return [ 'types' => $out ];
		}, true );

		$this->ability( 'list-entities', 'List Story Graph Entities', 'List a bounded page of entities with SCF fields.', [ 'type' => [ 'type' => 'string', 'enum' => $types ], 'page' => [ 'type' => 'integer', 'minimum' => 1 ], 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'status' => [ 'type' => 'string' ], 'project' => [ 'type' => 'integer' ], 'character' => [ 'type' => 'integer' ], 'scene' => [ 'type' => 'integer' ] ], [ 'type' ], static fn( $in ) => self::entity_call( $in['type'], 'get_items', $in ), true );
		$this->ability( 'get-entity', 'Get Story Graph Entity', 'Read one entity with all exposed SCF fields and relationships.', [ 'type' => [ 'type' => 'string', 'enum' => $types ], 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ], [ 'type', 'id' ], static fn( $in ) => self::entity_call( $in['type'], 'get_item', $in ), true );

		$write = [ 'type' => [ 'type' => 'string', 'enum' => $types ], 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'title' => [ 'type' => 'string' ], 'content' => [ 'type' => 'string' ], 'excerpt' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string' ], 'menu_order' => [ 'type' => 'integer' ], 'meta' => [ 'type' => 'object', 'additionalProperties' => true ] ];
		$this->ability( 'create-entity', 'Create Story Graph Entity', 'Create an entity and populate writable SCF fields and relationships.', $write, [ 'type' ], static fn( $in ) => self::entity_call( $in['type'], 'create_item', $in ), false );
		$this->ability( 'update-entity', 'Update Story Graph Entity', 'Revise post properties, every writable SCF field, and relationships.', $write, [ 'type', 'id' ], static fn( $in ) => self::entity_call( $in['type'], 'update_item', $in ), false );

		$this->ability( 'decompose-story', 'Decompose Story', 'Turn supplied story text into reviewed canonical Story Graph JSON; does not populate the database.', [ 'story' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500000 ], 'filename' => [ 'type' => 'string' ], 'connection_id' => [ 'type' => 'integer', 'minimum' => 0 ] ], [ 'story' ], static function( $in ) {
			if ( ! class_exists( '\\WorldGraphStoryIO\\Story_Decomposer' ) ) { return new WP_Error( 'worldgraph_story_io_unavailable', 'Enable Story Import & Export first.' ); }
			$id = absint( $in['connection_id'] ?? 0 ) ?: \WorldGraphStoryIO\Story_Decomposer::default_connection_id();
			if ( ! $id || ! \WorldGraph\Utils\Connection_Repository::current_user_can_manage( $id ) ) { return new WP_Error( 'worldgraph_story_connection_forbidden', 'Select a manageable LLM Connection.' ); }
			$connection = \WorldGraph\Utils\Connection_Repository::get( $id );
			$provider = sanitize_key( (string) ( $connection['provider_type'] ?? '' ) );
			if ( ! is_array( $connection ) || 'publish' !== (string) ( $connection['status_wp'] ?? '' ) || 'disabled' === (string) ( $connection['status'] ?? '' ) || ! in_array( $provider, [ 'litellm', 'openai_compatible', 'openai', 'anthropic' ], true ) || '' === trim( (string) ( $connection['endpoint_url'] ?? '' ) ) || '' === trim( (string) ( $connection['model'] ?? '' ) ) ) {
				return new WP_Error( 'worldgraph_story_connection_invalid', 'Select a published, enabled, complete LLM Connection.' );
			}
			$result = ( new \WorldGraphStoryIO\Story_Decomposer() )->decompose( (string) $in['story'], sanitize_file_name( $in['filename'] ?? 'agent-story.txt' ), $id );
			return is_wp_error( $result ) ? $result : [ 'success' => true, 'document' => $result['document'], 'json' => $result['json'] ];
		}, false, 'manage_options' );
		$this->ability( 'decompose-story-upload', 'Decompose Uploaded Story', 'Preview a story already uploaded through the existing form by Media Library attachment ID.', [ 'attachment_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'connection_id' => [ 'type' => 'integer', 'minimum' => 0 ] ], [ 'attachment_id' ], static function( $in ) {
			if ( ! class_exists( '\\WorldGraph\\REST\\Import_Controller' ) ) { return new WP_Error( 'worldgraph_story_io_unavailable', 'Enable Story Import & Export first.' ); }
			$controller = new \WorldGraph\REST\Import_Controller();
			$request = self::request( $in );
			$permission = $controller->check_decomposition_permission( $request );
			return is_wp_error( $permission ) ? $permission : self::data( $controller->decompose_story( $request ) );
		}, false, 'manage_options' );
		$this->ability( 'import-story', 'Import Story Graph', 'Populate the database from reviewed canonical World Graph Studio JSON.', [ 'json' => [ 'type' => 'string', 'minLength' => 2 ], 'overwrite' => [ 'type' => 'boolean' ] ], [ 'json' ], static function( $in ) {
			if ( ! class_exists( '\\WorldGraph\\REST\\Import_Controller' ) ) { return new WP_Error( 'worldgraph_story_io_unavailable', 'Enable Story Import & Export first.' ); }
			return self::data( ( new \WorldGraph\REST\Import_Controller() )->import_json( self::request( $in ) ) );
		}, false, 'manage_options' );

		$this->ability( 'review-project', 'Review Project Workflow', 'Review graph, production pipeline, timeline, editorial state, and notes.', [ 'project_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], [ 'project_id' ], static function( $in ) {
			if ( ! current_user_can( 'read_post', absint( $in['project_id'] ) ) ) { return new WP_Error( 'worldgraph_project_forbidden', 'You cannot read this project.' ); }
			$r = self::request( $in );
			return [ 'graph' => self::data( \WorldGraph\REST\Projects_Controller::get_graph( $r ) ), 'production' => self::data( \WorldGraph\REST\Production_Controller::get_overview( $r ) ), 'pipeline' => self::data( \WorldGraph\REST\Production_Controller::get_pipeline( $r ) ), 'timeline' => self::data( \WorldGraph\REST\Production_Controller::get_timeline( $r ) ), 'editorial' => self::data( \WorldGraph\REST\Editorial_Controller::get_overview( $r ) ), 'reviews' => self::data( \WorldGraph\REST\Editorial_Controller::get_reviews( $r ) ) ];
		}, true );
		$this->ability( 'add-review-note', 'Add Editorial Review Note', 'Add a project review note, optionally anchored to an entity.', [ 'project_id' => [ 'type' => 'integer' ], 'content' => [ 'type' => 'string', 'minLength' => 1 ], 'entity_id' => [ 'type' => 'integer' ], 'entity_type' => [ 'type' => 'string' ] ], [ 'project_id', 'content' ], static function( $in ) { if ( ! current_user_can( 'edit_post', absint( $in['project_id'] ) ) ) { return new WP_Error( 'worldgraph_project_forbidden', 'You cannot edit this project.' ); } return self::data( \WorldGraph\REST\Editorial_Controller::add_review( self::request( $in ) ) ); }, false );

		$this->ability( 'plan-end-to-end-generation', 'Plan End-to-End Generation', 'Preview a complete project demonstration and its template blockers without spending provider credits.', [ 'project_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], [ 'project_id' ], static function( $in ) { if ( ! current_user_can( 'edit_post', absint( $in['project_id'] ) ) ) { return new WP_Error( 'worldgraph_project_forbidden', 'You cannot generate this project.' ); } return self::data( \WorldGraph\REST\Asset_Generation_Controller::get_plan( self::request( [ 'post_id' => $in['project_id'], 'scope' => 'demonstration' ] ) ) ); }, true );
		$run = [ 'project_id' => [ 'type' => 'integer' ], 'image_template_id' => [ 'type' => 'integer' ], 'video_template_id' => [ 'type' => 'integer' ], 'audio_template_id' => [ 'type' => 'integer' ], 'base_prompt' => [ 'type' => 'string' ], 'image_run_values' => [ 'type' => 'object' ], 'video_run_values' => [ 'type' => 'object' ], 'audio_run_values' => [ 'type' => 'object' ], 'idempotency_key' => [ 'type' => 'string', 'minLength' => 8 ] ];
		$this->ability( 'run-end-to-end-generation', 'Run End-to-End Generation', 'Queue a confirmed full-story demonstration through configured generative tools.', $run, [ 'project_id', 'idempotency_key' ], static function( $in ) { if ( ! current_user_can( 'edit_post', absint( $in['project_id'] ) ) ) { return new WP_Error( 'worldgraph_project_forbidden', 'You cannot generate this project.' ); } $in['post_id'] = $in['project_id']; $in['scope'] = 'demonstration'; return self::data( \WorldGraph\REST\Asset_Generation_Controller::create_batch( self::request( $in ) ) ); }, false );
		$this->ability( 'review-generation', 'Review Generated Assets', 'Review a durable generation batch and its project asset records.', [ 'batch_id' => [ 'type' => 'integer' ], 'project_id' => [ 'type' => 'integer' ], 'page' => [ 'type' => 'integer' ], 'per_page' => [ 'type' => 'integer', 'maximum' => 100 ] ], [ 'batch_id', 'project_id' ], static function( $in ) { if ( ! current_user_can( 'read_post', absint( $in['project_id'] ) ) ) { return new WP_Error( 'worldgraph_project_forbidden', 'You cannot read this project.' ); } return [ 'batch' => self::data( \WorldGraph\REST\Asset_Generation_Controller::get_batch( self::request( [ 'id' => $in['batch_id'] ] ) ) ), 'assets' => self::entity_call( 'asset', 'get_items', [ 'project' => $in['project_id'], 'page' => $in['page'] ?? 1, 'per_page' => $in['per_page'] ?? 50 ] ) ]; }, true );

		$edl = [ 'content' => [ 'type' => 'string' ], 'format' => [ 'type' => 'string', 'enum' => [ 'cmx3600', 'xml' ] ], 'fps' => [ 'type' => 'number' ], 'target_type' => [ 'type' => 'string', 'enum' => [ 'project', 'episode' ] ], 'target_id' => [ 'type' => 'integer', 'minimum' => 1 ] ];
		$this->ability( 'preview-edl-import', 'Preview EDL Import', 'Parse CMX 3600 or XML EDL content without changing the database.', $edl, [ 'content', 'format' ], static function( $in ) { if ( ! function_exists( '\\WorldGraphEDL\\parse_edl' ) ) { return new WP_Error( 'worldgraph_edl_unavailable', 'Enable the EDL Format Tools plugin first.' ); } if ( ! empty( $in['target_id'] ) && ! current_user_can( 'edit_post', absint( $in['target_id'] ) ) ) { return new WP_Error( 'worldgraph_edl_target_forbidden', 'You cannot edit this EDL target.' ); } $preview = \WorldGraphEDL\parse_edl( (string) $in['content'], (string) $in['format'], (float) ( $in['fps'] ?? 24 ) ); if ( ! is_wp_error( $preview ) ) { $preview['target_type'] = $in['target_type'] ?? ''; $preview['target_id'] = absint( $in['target_id'] ?? 0 ); } return $preview; }, true, 'manage_worldgraph' );
		$edl['preview'] = [ 'type' => 'object', 'additionalProperties' => true ];
		$this->ability( 'import-edl', 'Import Reviewed EDL', 'Persist a reviewed EDL preview as an Editorial Artifact.', $edl, [ 'preview' ], static function( $in ) { if ( ! function_exists( '\\WorldGraphEDL\\persist_edl_preview' ) ) { return new WP_Error( 'worldgraph_edl_unavailable', 'Enable the EDL Format Tools plugin first.' ); } $preview = (array) $in['preview']; $target_id = absint( $preview['target_id'] ?? 0 ); if ( $target_id && ! current_user_can( 'edit_post', $target_id ) ) { return new WP_Error( 'worldgraph_edl_target_forbidden', 'You cannot edit this EDL target.' ); } return \WorldGraphEDL\persist_edl_preview( $preview ); }, false, 'manage_worldgraph' );
		$this->ability( 'export-edl', 'Export EDL', 'Generate CMX 3600 or XML EDL content from a live Project or Episode timeline.', $edl, [ 'target_type', 'target_id', 'format' ], static function( $in ) { if ( ! function_exists( '\\WorldGraphEDL\\export_edl' ) ) { return new WP_Error( 'worldgraph_edl_unavailable', 'Enable the EDL Format Tools plugin first.' ); } if ( ! current_user_can( 'read_post', absint( $in['target_id'] ) ) ) { return new WP_Error( 'worldgraph_edl_target_forbidden', 'You cannot read this EDL target.' ); } $content = \WorldGraphEDL\export_edl( (string) $in['target_type'], absint( $in['target_id'] ), (string) $in['format'], (float) ( $in['fps'] ?? 24 ) ); return is_wp_error( $content ) ? $content : [ 'format' => $in['format'], 'filename' => 'worldgraph_edl.' . ( 'xml' === $in['format'] ? 'xml' : 'edl' ), 'content' => $content ]; }, true, 'manage_worldgraph' );
	}

	private function ability( string $name, string $label, string $description, array $properties, array $required, callable $callback, bool $readonly, string $capability = 'edit_posts' ): void {
		$this->register_ability( 'worldgraph/' . $name, [ 'label' => $label, 'description' => $description, 'input_schema' => [ 'type' => 'object', 'properties' => $properties, 'required' => $required ], 'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ], 'execute_callback' => $callback, 'permission_callback' => static fn() => current_user_can( $capability ), 'meta' => [ 'public' => true, 'mcp' => [ 'type' => 'tool' ], 'annotations' => [ 'readonly' => $readonly, 'destructive' => false, 'idempotent' => $readonly ] ] ] );
	}

	private static function controller( string $type ) { $type = sanitize_key( $type ); if ( ! isset( self::CONTROLLERS[ $type ] ) ) { return new WP_Error( 'worldgraph_entity_type_invalid', 'Unsupported entity type.' ); } $class = self::CONTROLLERS[ $type ]; return new $class(); }
	private static function entity_call( string $type, string $method, array $in ) { $controller = self::controller( $type ); return is_wp_error( $controller ) ? $controller : self::data( $controller->{$method}( self::request( $in ) ) ); }
	private static function request( array $in ): \WP_REST_Request { $request = new \WP_REST_Request( 'POST' ); foreach ( $in as $key => $value ) { $request->set_param( $key, $value ); } return $request; }
	private static function data( $response ) { return $response instanceof \WP_REST_Response ? $response->get_data() : $response; }
}
