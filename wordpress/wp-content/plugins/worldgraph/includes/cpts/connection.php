<?php
/**
 * Provider Connection Custom Post Type.
 *
 * A Provider Connection is a World Graph Studio-owned control-plane record that binds a
 * provider type (e.g. comfyui, veo) to a concrete endpoint, environment,
 * credential reference, and quota configuration. Generation jobs reference
 * connections by ID: { "provider_type": "comfyui", "connection_id": 32 }.
 *
 * Credential fields accept either provider-issued values or references such as
 * env://COMFYUI_API_KEY. Environment-backed references are preferred for
 * managed deployments.
 *
 * @package WorldGraph
 */

namespace WorldGraph\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider Connection Custom Post Type handler.
 */
class Connection {

	/**
	 * Connection CPT slug.
	 *
	 * @var string
	 */
	const CPT = 'worldgraph_conn';

	/**
	 * Allowed connection statuses.
	 *
	 * @var array<int, string>
	 */
	const STATUSES = [ 'unverified', 'verified', 'error', 'disabled' ];

	/**
	 * Allowed deployment environments.
	 *
	 * @var array<int, string>
	 */
	const ENVIRONMENTS = [ 'local', 'development', 'staging', 'production' ];

	/**
	 * Known provider types (extendable via the worldgraph_conn_provider_types filter).
	 *
	 * @return array<int, string>
	 */
	public static function provider_types(): array {
		$types = \WorldGraph\Utils\Connection_Adapters::provider_types();
		return apply_filters( 'worldgraph_conn_provider_types', $types );
	}

	/**
	 * Register the Provider Connection CPT and admin UI.
	 */
	public static function init(): void {
		self::register_cpt();
		self::register_meta_boxes();
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_editor_script' ] );
		add_filter( 'acf/update_value', [ __CLASS__, 'sanitize_scf_value' ], 30, 4 );
		add_filter( 'acf/validate_value', [ __CLASS__, 'validate_scf_value' ], 20, 4 );
		add_filter( 'acf/load_field/key=field_worldgraph_conn_provider_type', [ __CLASS__, 'load_provider_choices' ] );
		add_action( 'acf/save_post', [ __CLASS__, 'after_scf_save' ], 20 );
		add_action( 'worldgraph_after_rest_entity_save', [ __CLASS__, 'after_rest_save' ], 10, 3 );
		add_action( 'wp_ajax_worldgraph_sync_connection_catalog', [ __CLASS__, 'ajax_sync_catalog' ] );
		add_action( 'wp_ajax_worldgraph_enable_connection_catalog_entry', [ __CLASS__, 'ajax_enable_catalog_entry' ] );
		add_action( 'wp_ajax_worldgraph_disable_connection_catalog_entry', [ __CLASS__, 'ajax_disable_catalog_entry' ] );
		add_action( 'wp_ajax_worldgraph_materialize_connection_catalog_entry', [ __CLASS__, 'ajax_materialize_catalog_entry' ] );
		add_action( 'wp_ajax_worldgraph_download_connection_catalog_entry', [ __CLASS__, 'ajax_download_catalog_entry' ] );
	}

	/**
	 * Keep the archived Provider Type field aligned with adapter extensions.
	 *
	 * @param array<string, mixed> $field SCF field.
	 * @return array<string, mixed>
	 */
	public static function load_provider_choices( array $field ): array {
		$provider_types   = self::provider_types();
		$field['choices'] = empty( $provider_types ) ? [] : array_combine( $provider_types, $provider_types );
		return $field;
	}

	/**
	 * Apply Connection-specific normalization after SCF's field-type handling.
	 *
	 * @param mixed                $value    Submitted value.
	 * @param int|string           $post_id  SCF object ID.
	 * @param array<string, mixed> $field    SCF field.
	 * @param mixed                $original Original submitted value.
	 * @return mixed
	 */
	public static function sanitize_scf_value( $value, $post_id, array $field, $original ) {
		if ( ! is_numeric( $post_id ) || self::CPT !== get_post_type( (int) $post_id ) || 0 !== strpos( (string) ( $field['key'] ?? '' ), 'field_worldgraph_conn_' ) ) {
			return $value;
		}

		switch ( (string) ( $field['name'] ?? '' ) ) {
			case 'provider_type':
				$value = sanitize_key( (string) $value );
				return in_array( $value, self::provider_types(), true ) ? $value : '';

			case 'environment':
				$value = sanitize_key( (string) $value );
				return in_array( $value, self::ENVIRONMENTS, true ) ? $value : 'local';

			case 'status':
				$value = sanitize_key( (string) $value );
				return in_array( $value, self::STATUSES, true ) ? $value : 'unverified';

			case 'is_default':
				$value = sanitize_key( (string) $value );
				return 'yes' === $value ? 'yes' : 'no';

			case 'endpoint_url':
			case 'mcp_endpoint_url':
				return esc_url_raw( trim( (string) $value ) );

			case 'max_tokens':
				return '' === trim( (string) $value ) ? '' : (string) absint( $value );

			case 'temperature':
				return '' === trim( (string) $value ) ? '' : (string) (float) $value;

			case 'model_access':
			case 'enabled_structures':
			case 'enabled_templates':
			case 'capabilities':
			case 'mcp_configuration':
			case 'rate_limits':
			case 'cost_controls':
				$normalized = self::sanitize_json_field( (string) $value );
				return null === $normalized
					? \WorldGraph\Utils\worldgraph_get_field_value( (int) $post_id, (string) $field['name'] )
					: $normalized;

			case 'credential_reference':
			case 'mcp_credential_reference':
				return \WorldGraph\Utils\Credential_Store::prepare_connection_value( (string) $value, (int) $post_id, (string) $field['name'] );

			case 'connection_name':
			case 'model':
				return sanitize_text_field( (string) $value );
		}

		return $value;
	}

	/**
	 * Report domain validation errors in SCF before any Connection values save.
	 *
	 * @param bool|string          $valid Whether the value is valid so far.
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field SCF field.
	 * @param string               $input Input name.
	 * @return bool|string
	 */
	public static function validate_scf_value( $valid, $value, array $field, string $input ) {
		if ( true !== $valid || 0 !== strpos( (string) ( $field['key'] ?? '' ), 'field_worldgraph_conn_' ) ) {
			return $valid;
		}

		$name = (string) ( $field['name'] ?? '' );
		if ( 'provider_type' === $name && ! in_array( sanitize_key( (string) $value ), self::provider_types(), true ) ) {
			return __( 'Select a supported provider type.', 'worldgraph' );
		}
		if ( 'environment' === $name && ! in_array( sanitize_key( (string) $value ), self::ENVIRONMENTS, true ) ) {
			return __( 'Select a supported environment.', 'worldgraph' );
		}
		if ( 'status' === $name && ! in_array( sanitize_key( (string) $value ), self::STATUSES, true ) ) {
			return __( 'Select a supported connection status.', 'worldgraph' );
		}
		if ( 'is_default' === $name && ! in_array( sanitize_key( (string) $value ), [ 'yes', 'no' ], true ) ) {
			return __( 'Select yes or no for the active connection flag.', 'worldgraph' );
		}
		if ( in_array( $name, [ 'max_tokens', 'temperature' ], true ) && '' !== trim( (string) $value ) && ! is_numeric( $value ) ) {
			return __( 'Enter a numeric value.', 'worldgraph' );
		}
		if ( in_array( $name, [ 'model_access', 'enabled_structures', 'enabled_templates', 'capabilities', 'mcp_configuration', 'rate_limits', 'cost_controls' ], true ) && null === self::sanitize_json_field( (string) $value ) ) {
			return __( 'Enter a valid JSON array or object.', 'worldgraph' );
		}
		if ( in_array( $name, \WorldGraph\Utils\Credential_Store::CONNECTION_FIELDS, true ) && ! in_array( (string) $value, [ '', \WorldGraph\Utils\Credential_Store::MASK ], true ) && ! \WorldGraph\Utils\Credential_Store::is_available() ) {
			return __( 'Provider credentials cannot be saved because authenticated encryption is unavailable on this server.', 'worldgraph' );
		}

		return $valid;
	}

	/**
	 * Load the selected adapter and schedule provider catalog refreshes after
	 * SCF has persisted a Connection edit.
	 *
	 * @param int|string $post_id SCF object ID.
	 */
	public static function after_scf_save( $post_id ): void {
		if ( ! is_numeric( $post_id ) || self::CPT !== get_post_type( (int) $post_id ) || ! current_user_can( 'manage_options' ) || 'publish' !== get_post_status( (int) $post_id ) ) {
			return;
		}

		$post_id       = (int) $post_id;
		$provider_type = (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'provider_type' );
		$status        = (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'status' );
		$environment   = (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'environment' );
		self::enforce_single_default( $post_id, $provider_type, $environment );
		if ( 'disabled' === $status ) {
			return;
		}

		\WorldGraph\Utils\Connection_Adapters::load( $provider_type );

		if ( 'fal' === $provider_type && ! wp_next_scheduled( \WorldGraph\Utils\Fal_Catalog::HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, \WorldGraph\Utils\Fal_Catalog::HOOK, [ $post_id ] );
		} elseif ( 'elevenlabs' === $provider_type && ! wp_next_scheduled( \WorldGraph\Utils\ElevenLabs_Catalog::HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, \WorldGraph\Utils\ElevenLabs_Catalog::HOOK, [ $post_id ] );
		} elseif ( 'suno' === $provider_type && ! wp_next_scheduled( \WorldGraph\Utils\Suno_Catalog::HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, \WorldGraph\Utils\Suno_Catalog::HOOK, [ $post_id ] );
		} elseif ( 'videodraft' === $provider_type && ! wp_next_scheduled( \WorldGraph\Utils\VideoDraft_Catalog::HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, \WorldGraph\Utils\VideoDraft_Catalog::HOOK, [ $post_id ] );
		}
	}

	/**
	 * Only one Connection can be the active default per provider type and
	 * environment, so Generate has an unambiguous choice when a Template
	 * does not pin a Connection. Clear the flag on every sibling when a
	 * Connection is saved as the active one.
	 *
	 * @param int    $post_id       Saved Connection post ID.
	 * @param string $provider_type Provider type of the saved Connection.
	 * @param string $environment   Environment of the saved Connection.
	 */
	private static function enforce_single_default( int $post_id, string $provider_type, string $environment ): void {
		if ( 'yes' !== (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'is_default' ) || '' === $provider_type ) {
			return;
		}

		$siblings = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'exclude'        => [ $post_id ],
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => 'provider_type',
					'value' => $provider_type,
				],
				[
					'key'   => 'environment',
					'value' => $environment,
				],
			],
		] );

		foreach ( $siblings as $sibling_id ) {
			if ( 'yes' === \WorldGraph\Utils\worldgraph_get_field_value( (int) $sibling_id, 'is_default' ) ) {
				\WorldGraph\Utils\worldgraph_update_field_value( (int) $sibling_id, 'is_default', 'no' );
			}
		}
	}

	/**
	 * Run the same Connection lifecycle after custom World Graph Studio REST writes.
	 *
	 * @param int              $post_id Post ID.
	 * @param string           $cpt     CPT slug.
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function after_rest_save( int $post_id, string $cpt, \WP_REST_Request $request ): void {
		if ( self::CPT === $cpt ) {
			self::after_scf_save( $post_id );
		}
	}

	/**
	 * Register the Provider Connection CPT.
	 */
	private static function register_cpt(): void {
		$provider_types = self::provider_types();

		$fields = [
			'connection_name'      => [
				'type'        => 'text',
				'label'       => 'Connection Name',
				'required'    => true,
				'description' => 'Human-readable name for this provider connection.',
			],
			'provider_type'        => [
				'type'        => 'select',
				'label'       => 'Provider Type',
				'required'    => true,
				'options'     => array_combine( $provider_types, $provider_types ),
				'description' => 'Provider adapter used by the paired Templates, such as ComfyUI, FAL, Google Gemini, or Veo.',
			],
			'environment'          => [
				'type'        => 'select',
				'label'       => 'Environment',
				'required'    => true,
				'options'     => [
					'local'       => 'Local',
					'development' => 'Development',
					'staging'     => 'Staging',
					'production'  => 'Production',
				],
			],
			'status'               => [
				'type'        => 'select',
				'label'       => 'Status',
				'required'    => true,
				'options'     => [
					'unverified' => 'Unverified',
					'verified'   => 'Verified',
					'error'      => 'Error',
					'disabled'   => 'Disabled',
				],
				'description' => 'Status is normally maintained by connection validation; set manually only to disable a connection.',
			],
			'is_default'           => [
				'type'        => 'select',
				'label'       => 'Active Connection',
				'required'    => false,
				'options'     => [
					'no'  => 'No',
					'yes' => 'Yes',
				],
				'description' => 'Marks this the active Connection Generate uses for its provider type and environment when a Template does not pin one. Only one Connection per provider type and environment can be active; setting this saves the others as No.',
			],
			'endpoint_url'         => [
				'type'        => 'text',
				'label'       => 'Endpoint URL',
				'required'    => true,
				'description' => 'Provider endpoint. For SunoAPI.org use https://api.sunoapi.org; for ElevenLabs use https://api.elevenlabs.io/v1; for local ComfyUI use its HTTP API base URL.',
			],
			'mcp_endpoint_url'     => [
				'type'        => 'text',
				'label'       => 'MCP Endpoint URL',
				'required'    => false,
				'description' => 'Streamable HTTP MCP endpoint. Required for fal; use https://suno.mcp.acedata.cloud/mcp for Suno MCP; optional for local ComfyUI discovery and downloads.',
			],
			'credential_reference' => [
				'type'        => 'text',
				'label'       => 'API Key / OAuth (Reference)',
				'required'    => false,
				'description' => 'REST/API credential or environment reference, e.g. env://SUNO_API_KEY, env://ELEVENLABS_API_KEY, or env://COMFYUI_API_KEY.',
			],
			'mcp_credential_reference' => [
				'type'        => 'text',
				'label'       => 'MCP API Key / OAuth (Reference)',
				'required'    => false,
				'description' => 'Optional separate credential for the MCP endpoint. Suno MCP requires an AceData Cloud token such as env://ACEDATACLOUD_API_TOKEN, which is distinct from a SunoAPI.org key.',
			],
			'mcp_configuration'     => [
				'type'        => 'textarea',
				'label'       => 'MCP Configuration (JSON)',
				'required'    => false,
				'description' => 'Optional non-secret MCP deployment settings as a JSON object, such as transport, host, port, path, Docker service, or startup health-check details. Keep credentials in the MCP API Key / OAuth reference field.',
			],
			'capabilities'         => [
				'type'        => 'textarea',
				'label'       => 'Capabilities (JSON)',
				'required'    => false,
				'description' => 'Optional non-secret capability profile. Use chat, vision, asset_generation, and modalities to describe what this Connection and model can do; pair asset modalities with Templates.',
			],
			'model'                => [
				'type'        => 'text',
				'label'       => 'Model',
				'required'    => false,
				'description' => 'Optional default model. For SunoAPI.org use V5_5 (mapped to chirp-v5-5 for MCP); for fal use an endpoint ID; for ElevenLabs use a speech model ID.',
			],
			'max_tokens'           => [
				'type'        => 'text',
				'label'       => 'Max Tokens',
				'required'    => false,
				'description' => 'Maximum tokens for LLM responses.',
			],
			'temperature'          => [
				'type'        => 'text',
				'label'       => 'Temperature',
				'required'    => false,
				'description' => 'Creativity level (0.0 = deterministic, 1.0 = creative).',
			],
			'model_access'         => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'Model Access',
				'required'    => false,
				'description' => 'Optional JSON allowlist. fal uses model endpoint IDs; ElevenLabs uses voice IDs; Suno uses model version IDs. Empty lets the adapter select a default.',
			],
			'enabled_structures'   => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'Enabled Structures',
				'required'    => false,
				'description' => 'JSON array of generation structures enabled for this connection, e.g. ["character-sheet","scene-image"].',
			],
			'enabled_templates'    => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'Added Workflows (Managed JSON)',
				'required'    => false,
				'description' => 'Internal links between provider workflows added through Workflow Setup and their reusable Generation Templates. Managed automatically; edit only for recovery.',
			],
			'rate_limits'          => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'Rate Limits',
				'required'    => false,
				'description' => 'JSON object, e.g. {"max_concurrent":1,"requests_per_minute":10}.',
			],
			'cost_controls'        => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'Cost Controls',
				'required'    => false,
				'description' => 'JSON object, e.g. {"max_cost_per_job":0.5,"monthly_budget":50}.',
			],
		];

		\WorldGraph\Utils\register_cpt(
			self::CPT,
			'Connections',
			[
				'menu_icon'          => 'dashicons-admin-network',
				'public'             => false,
				'publicly_queryable' => false,
				'show_in_rest'       => false,
				'capabilities'       => [
					'edit_post'              => 'manage_options',
					'read_post'              => 'manage_options',
					'delete_post'            => 'manage_options',
					'edit_posts'             => 'manage_options',
					'edit_others_posts'      => 'manage_options',
					'publish_posts'          => 'manage_options',
					'read_private_posts'     => 'manage_options',
					'delete_posts'           => 'manage_options',
					'delete_private_posts'   => 'manage_options',
					'delete_published_posts' => 'manage_options',
					'delete_others_posts'    => 'manage_options',
					'edit_private_posts'     => 'manage_options',
					'edit_published_posts'   => 'manage_options',
					'create_posts'           => 'manage_options',
				],
				// The native post list is intentionally not registered in the admin menu;
				// WorldGraph\Admin\Connections::render_page() is the single Connections view.
				'show_in_menu'       => false,
			],
			$fields
		);
	}

	/**
	 * Register admin UI for connection configuration.
	 */
	private static function register_meta_boxes(): void {
		add_action( 'add_meta_boxes', function (): void {
			add_meta_box(
				'worldgraph_conn_configurator',
				__( 'Provider Setup & Workflows', 'worldgraph' ),
				[ self::class, 'render_configurator_meta_box' ],
				self::CPT,
				'normal',
				'default'
			);
			add_meta_box(
				'worldgraph_conn_activity',
				__( 'Setup Activity', 'worldgraph' ),
				[ self::class, 'render_activity_meta_box' ],
				self::CPT,
				'normal',
				'low'
			);
		} );
	}

	/**
	 * Enqueue the Connection editor controller on Connection edit and new screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_editor_script( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::CPT !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Used only to scope edit-screen data.
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$endpoint_urls     = [];
		$mcp_endpoint_urls = [];
		foreach ( self::provider_types() as $provider_type ) {
			$provider_type = sanitize_key( (string) $provider_type );
			if ( '' === $provider_type ) {
				continue;
			}

			$endpoint_urls[ $provider_type ]     = \WorldGraph\Utils\Connection_Adapters::endpoint( $provider_type );
			$mcp_endpoint_urls[ $provider_type ] = \WorldGraph\Utils\Connection_Adapters::mcp_endpoint( $provider_type );
		}

		$initial_catalog = [
			'synced_at' => '',
			'message'   => '',
			'entries'   => [],
		];
		if ( $post_id && 'comfyui' === sanitize_key( (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'provider_type' ) ) ) {
			\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );
			if ( class_exists( '\\WorldGraph\\Utils\\Comfy_Catalog' ) ) {
				$initial_catalog            = \WorldGraph\Utils\Comfy_Catalog::get( $post_id );
				$initial_catalog['entries'] = array_values( (array) ( $initial_catalog['entries'] ?? [] ) );
			}
		}

		$handle      = 'worldgraph-connection-editor';
		$script_path = WORLDGRAPH_PLUGIN_DIR . 'assets/js/connection-editor.js';

		wp_enqueue_script(
			$handle,
			WORLDGRAPH_PLUGIN_URL . 'assets/js/connection-editor.js',
			[],
			is_file( $script_path ) ? (string) filemtime( $script_path ) : WORLDGRAPH_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'worldgraphConnectionEditor',
			[
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'editPostUrl'           => admin_url( 'post.php?action=edit&post=' ),
				'nonce'                 => wp_create_nonce( 'worldgraph_conn_configurator' ),
				'connectionId'          => $post_id,
				'endpointUrls'          => $endpoint_urls,
				'mcpEndpointUrls'       => $mcp_endpoint_urls,
				'initialCatalog'        => $initial_catalog,
				'i18n'                  => [
					'statusLabels'             => [
						'ready'        => __( 'Ready now', 'worldgraph' ),
						'needs_models' => __( 'Model files required', 'worldgraph' ),
						'needs_nodes'  => __( 'Custom nodes required', 'worldgraph' ),
						'unverified'   => __( 'Availability not checked', 'worldgraph' ),
						'unmappable'   => __( 'Not supported by Studio', 'worldgraph' ),
						'withdrawn'    => __( 'No longer offered', 'worldgraph' ),
					],
					'unknownStatus'            => __( 'Unknown', 'worldgraph' ),
					'byteUnits'                => [
						__( 'B', 'worldgraph' ),
						__( 'KB', 'worldgraph' ),
						__( 'MB', 'worldgraph' ),
						__( 'GB', 'worldgraph' ),
						__( 'TB', 'worldgraph' ),
					],
					'summary'                  =>
						/* translators: 1: available workflows, 2: ready workflows, 3: workflows added to Studio, 4: workflows needing attention. */
						__( '%1$d available workflows · %2$d ready now · %3$d added to Studio · %4$d need attention', 'worldgraph' ),
					'noTemplates'              => __( 'No workflows have been checked yet. Refresh the available workflows to see what this provider can run.', 'worldgraph' ),
					'lastChecked'              =>
						/* translators: %s: formatted date and time of the last provider check. */
						__( 'Last checked: %s', 'worldgraph' ),
					'notCheckedYet'            => __( 'Not checked yet', 'worldgraph' ),
					'download'                 => __( 'Download', 'worldgraph' ),
					'providerBilling'          => __( 'Billed per generation by the provider', 'worldgraph' ),
					'modelFilesMissing'        => __( 'model file(s) missing', 'worldgraph' ),
					'missingNodes'             => __( 'Missing custom nodes', 'worldgraph' ),
					'addToStudio'              => __( 'Add to Studio', 'worldgraph' ),
					'addingWorkflow'           => __( 'Adding workflow to Studio…', 'worldgraph' ),
					'workflowAdded'            => __( 'Workflow added to Studio.', 'worldgraph' ),
					'workflowAddFailed'        => __( 'The workflow could not be added.', 'worldgraph' ),
					'addedToStudio'            => __( 'Added to Studio', 'worldgraph' ),
					'editTemplate'             => __( 'Edit Generation Template', 'worldgraph' ),
					'installModels'            => __( 'Install model files', 'worldgraph' ),
					'installingModels'         => __( 'Requesting the required model files…', 'worldgraph' ),
					'installRequestSent'       => __( 'Model installation requested. Refresh the workflows after the provider finishes.', 'worldgraph' ),
					'installRequestFailed'     => __( 'The model files could not be installed automatically.', 'worldgraph' ),
					'needsNodesHelp'           => __( 'Install the listed custom nodes in ComfyUI, then refresh this list.', 'worldgraph' ),
					'unverifiedHelp'           => __( 'Refresh the list to check whether this workflow is ready.', 'worldgraph' ),
					'unmappableHelp'           => __( 'This provider workflow does not map to a Studio generation type.', 'worldgraph' ),
					'withdrawnHelp'            => __( 'The provider no longer advertises this workflow. Existing Generation Templates are kept.', 'worldgraph' ),
					'refreshingWorkflows'      => __( 'Checking the provider for available workflows and requirements…', 'worldgraph' ),
					'workflowRefreshFailed'    => __( 'Available workflows could not be refreshed.', 'worldgraph' ),
					'workflowsRefreshed'       => __( 'Available workflows refreshed.', 'worldgraph' ),
					'addingReadyWorkflows'     => __( 'Refreshing the list before adding ready workflows…', 'worldgraph' ),
					'noReadyWorkflows'         => __( 'There are no new ready workflows to add.', 'worldgraph' ),
					'addAllProgress'           =>
						/* translators: 1: current workflow number, 2: total workflows, 3: workflow label. */
						__( 'Adding workflow %1$d of %2$d: %3$s', 'worldgraph' ),
					'addAllFinished'           =>
						/* translators: 1: workflows added, 2: workflows that could not be added. */
						__( 'Finished adding workflows: %1$d added, %2$d could not be added.', 'worldgraph' ),
					'addAllIncomplete'         => __( 'The ready workflows could not all be added.', 'worldgraph' ),
					'interfaceReady'           => __( 'Workflow setup is ready.', 'worldgraph' ),
					'networkError'             => __( 'The provider setup request did not return a usable response.', 'worldgraph' ),
				],
			]
		);
	}

	/**
	 * Render persistent provider-setup activity performed outside any Job.
	 * Job-scoped exchanges remain on the Job itself.
	 *
	 * @param \WP_Post $post Connection post.
	 */
	public static function render_activity_meta_box( \WP_Post $post ): void {
		$entries = \WorldGraph\Utils\Generation_Log::for_connection( $post->ID );
		?>
		<p class="description">
			<?php esc_html_e( 'This history records connection checks, workflow discovery, setup changes, and provider errors. It is kept across browser sessions.', 'worldgraph' ); ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Jobs list table URL filtered to this Connection. */
				wp_kses( __( 'Requests made while generating media are recorded on the corresponding <a href="%s">Job</a>.', 'worldgraph' ), [ 'a' => [ 'href' => [] ] ] ),
				esc_url( admin_url( 'edit.php?post_type=worldgraph_gen&worldgraph_gen_connection=' . $post->ID ) )
			);
			?>
		</p>
		<?php
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No setup activity has been recorded yet. Check the connection or refresh its available workflows to begin.', 'worldgraph' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col" style="width:150px;"><?php esc_html_e( 'Time', 'worldgraph' ); ?></th>
					<th scope="col" style="width:110px;"><?php esc_html_e( 'Outcome', 'worldgraph' ); ?></th>
					<th scope="col" style="width:170px;"><?php esc_html_e( 'Area', 'worldgraph' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Activity', 'worldgraph' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( self::activity_level_label( (string) ( $entry['level'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( self::activity_source_label( (string) ( $entry['source'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Translate an internal log level into an operator-facing outcome.
	 */
	private static function activity_level_label( string $level ): string {
		$labels = [
			'error' => __( 'Needs attention', 'worldgraph' ),
			'info'  => __( 'Completed', 'worldgraph' ),
			'debug' => __( 'Details', 'worldgraph' ),
		];

		return $labels[ $level ] ?? __( 'Update', 'worldgraph' );
	}

	/**
	 * Translate internal log source tags into recognizable admin areas.
	 */
	private static function activity_source_label( string $source ): string {
		$labels = [
			'connection_setup'   => __( 'Workflow setup', 'worldgraph' ),
			'comfy_catalog'      => __( 'Workflow setup', 'worldgraph' ),
			'comfy_cloud_mcp'    => __( 'Provider workflow service', 'worldgraph' ),
			'local_comfyui'      => __( 'ComfyUI', 'worldgraph' ),
			'connection_tester'  => __( 'Connection check', 'worldgraph' ),
			'fal_catalog'        => __( 'Workflow setup', 'worldgraph' ),
			'elevenlabs_catalog' => __( 'Workflow setup', 'worldgraph' ),
			'suno_catalog'       => __( 'Workflow setup', 'worldgraph' ),
			'videodraft_catalog' => __( 'Workflow setup', 'worldgraph' ),
		];

		if ( isset( $labels[ $source ] ) ) {
			return $labels[ $source ];
		}

		return '' !== $source ? ucwords( str_replace( '_', ' ', $source ) ) : __( 'Provider', 'worldgraph' );
	}

	/**
	 * Render provider setup controls that discover available workflows and add
	 * ready choices as reusable Generation Templates.
	 *
	 * @param \WP_Post $post Connection post.
	 */
	public static function render_configurator_meta_box( \WP_Post $post ): void {
		$provider_type = sanitize_key( (string) \WorldGraph\Utils\worldgraph_get_field_value( $post->ID, 'provider_type' ) );
		if ( '' === $provider_type ) {
			echo '<p>' . esc_html__( 'Choose a provider type and save this Connection to see its setup and available workflows.', 'worldgraph' ) . '</p>';
			return;
		}

		if ( 'fal' === $provider_type ) {
			\WorldGraph\Utils\Connection_Adapters::load( 'fal' );
			$synced_at = (string) get_post_meta( $post->ID, 'fal_catalog_synced_at', true );
			$error     = (string) get_post_meta( $post->ID, 'fal_catalog_error', true );
			?>
			<p><?php echo esc_html__( 'World Graph Studio asks fal to select or inspect models and automatically maintains the paired Generation Templates. Saving or checking this Connection refreshes the available workflows; users do not need to copy provider schemas by hand.', 'worldgraph' ); ?></p>
			<ul>
				<li><?php echo esc_html__( 'Model Access, when set, is the authoritative endpoint allowlist and provisions one Template per endpoint.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'Model is the preferred default endpoint. With neither field set, fal MCP supplies a current text-to-image model.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'MCP model schemas and their defaults are stored on the generated Templates; World Graph Studio supplies prompts and bound media at runtime.', 'worldgraph' ); ?></li>
			</ul>
			<p><strong><?php echo esc_html__( 'Last workflow refresh:', 'worldgraph' ); ?></strong> <?php echo esc_html( $synced_at ?: '—' ); ?></p>
			<?php if ( '' !== $error ) : ?><p class="notice notice-error inline"><?php echo esc_html( $error ); ?></p><?php endif; ?>
			<?php
			return;
		}

		if ( 'elevenlabs' === $provider_type ) {
			\WorldGraph\Utils\Connection_Adapters::load( 'elevenlabs' );
			$synced_at = (string) get_post_meta( $post->ID, 'elevenlabs_catalog_synced_at', true );
			$error = (string) get_post_meta( $post->ID, 'elevenlabs_catalog_error', true );
			?>
			<p><?php echo esc_html__( 'World Graph Studio discovers ElevenLabs voices and models and maintains endpoint-specific Generation Templates for speech, dialogue, sound effects, music, and voice design. Saving or checking this Connection refreshes the available workflows.', 'worldgraph' ); ?></p>
			<ul>
				<li><?php echo esc_html__( 'Model selects the ElevenLabs speech model. When empty, World Graph Studio prefers eleven_multilingual_v2.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'Model Access may contain a JSON array of voice IDs. When empty, World Graph Studio provisions one available voice to minimize setup.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'Each generated audio response is imported into WordPress before its generation job completes.', 'worldgraph' ); ?></li>
			</ul>
			<p><strong><?php echo esc_html__( 'Last workflow refresh:', 'worldgraph' ); ?></strong> <?php echo esc_html( $synced_at ?: '—' ); ?></p>
			<?php if ( '' !== $error ) : ?><p class="notice notice-error inline"><?php echo esc_html( $error ); ?></p><?php endif; ?>
			<?php
			return;
		}

		if ( 'suno' === $provider_type ) {
			\WorldGraph\Utils\Connection_Adapters::load( 'suno' );
			$synced_at = (string) get_post_meta( $post->ID, 'suno_catalog_synced_at', true );
			$error     = (string) get_post_meta( $post->ID, 'suno_catalog_error', true );
			?>
			<p><?php echo esc_html__( 'World Graph Studio maintains transport-specific music and lyrics Templates for SunoAPI.org REST and the AceData Cloud Suno MCP server. Saving or testing this Connection refreshes those Templates.', 'worldgraph' ); ?></p>
			<ul>
				<li><?php echo esc_html__( 'API Key authenticates api.sunoapi.org; MCP API Key authenticates suno.mcp.acedata.cloud. These services issue different bearer tokens.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'Model selects the preferred Suno version. World Graph Studio maps API model names such as V5_5 to MCP model names such as chirp-v5-5.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'Suno normally returns two tracks. Every final track URL is imported into WordPress before a generation job completes.', 'worldgraph' ); ?></li>
			</ul>
			<p><strong><?php echo esc_html__( 'Last workflow refresh:', 'worldgraph' ); ?></strong> <?php echo esc_html( $synced_at ?: '—' ); ?></p>
			<?php if ( '' !== $error ) : ?><p class="notice notice-error inline"><?php echo esc_html( $error ); ?></p><?php endif; ?>
			<?php
			return;
		}

		if ( 'videodraft' === $provider_type ) {
			\WorldGraph\Utils\Connection_Adapters::load( 'videodraft' );
			$synced_at = (string) get_post_meta( $post->ID, 'videodraft_catalog_synced_at', true );
			$error     = (string) get_post_meta( $post->ID, 'videodraft_catalog_error', true );
			?>
			<p><?php echo esc_html__( 'World Graph Studio discovers VideoDraft MCP tools and maintains provider-backed Templates for image, video, voiceover, music, and sound effects. This Connection is also shared with the bundled VideoDraft Sync plugin.', 'worldgraph' ); ?></p>
			<ul>
				<li><?php echo esc_html__( 'Use a dedicated VideoDraft personal access token or an env://VIDEODRAFT_API_KEY reference.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'Tool schemas are read live and stored with each generated Template.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'Generated media is downloaded into the WordPress Media Library before the job completes.', 'worldgraph' ); ?></li>
			</ul>
			<p><strong><?php echo esc_html__( 'Last workflow refresh:', 'worldgraph' ); ?></strong> <?php echo esc_html( $synced_at ?: '—' ); ?></p>
			<?php if ( '' !== $error ) : ?><p class="notice notice-error inline"><?php echo esc_html( $error ); ?></p><?php endif; ?>
			<?php
			return;
		}

		if ( 'descript' === $provider_type ) {
			?>
			<p><?php echo esc_html__( 'This Connection authenticates the Descript REST API and is shared with the bundled Descript Sync plugin.', 'worldgraph' ); ?></p>
			<ul>
				<li><?php echo esc_html__( 'Use a Descript personal API token or an env://DESCRIPT_API_TOKEN reference. Each token is scoped to one Descript drive.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'Descript Sync exports composition transcripts into Story Graph Scenes and imports bound video/audio media as new Descript projects.', 'worldgraph' ); ?></li>
				<li><?php echo esc_html__( 'Descript has no editable project schema, so this integration is one-way per direction rather than a structural mirror.', 'worldgraph' ); ?></li>
			</ul>
			<?php
			return;
		}

		if ( 'comfyui' !== $provider_type ) {
			echo '<p>' . esc_html__( 'This provider does not require additional workflow setup here. Save the Connection, check that it can be reached, and use any provider-specific integration controls shown elsewhere in Studio.', 'worldgraph' ) . '</p>';
			return;
		}
		\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );

		$snapshot = \WorldGraph\Utils\Comfy_Catalog::get( (int) $post->ID );
		?>
		<p><?php echo esc_html__( 'Available workflows are the generation recipes this provider can offer. Studio checks each workflow’s requirements before you add it as a reusable Generation Template.', 'worldgraph' ); ?></p>
		<ol id="worldgraph-connection-process-guide" class="worldgraph-connection-setup-steps">
			<li><strong><?php esc_html_e( 'Discover', 'worldgraph' ); ?></strong> — <?php esc_html_e( 'ask the provider which workflows and requirements are available.', 'worldgraph' ); ?></li>
			<li><strong><?php esc_html_e( 'Review readiness', 'worldgraph' ); ?></strong> — <?php esc_html_e( 'see what is ready now and what still needs model files or custom nodes.', 'worldgraph' ); ?></li>
			<li><strong><?php esc_html_e( 'Add to Studio', 'worldgraph' ); ?></strong> — <?php esc_html_e( 'create or update a reusable Generation Template. Refreshing later will not create duplicates.', 'worldgraph' ); ?></li>
		</ol>
		<p class="worldgraph-connection-setup-actions">
			<button type="button" class="button" id="worldgraph-connection-sync-catalog"><?php echo esc_html__( 'Refresh Available Workflows', 'worldgraph' ); ?></button>
			<button type="button" class="button button-primary" id="worldgraph-connection-guided-setup" style="margin-left:6px;"><?php echo esc_html__( 'Add All Ready Workflows', 'worldgraph' ); ?></button>
			<span class="description" id="worldgraph-connection-last-checked" style="margin-left:8px;"><?php
			printf(
				/* translators: %s: workflow refresh timestamp. */
				esc_html__( 'Last checked: %s', 'worldgraph' ),
				esc_html( (string) ( $snapshot['synced_at'] ?: '—' ) )
			);
			?></span>
		</p>
		<div id="worldgraph-connection-configurator-status" aria-live="polite"></div>
		<div id="worldgraph-connection-configurator-summary" aria-live="polite" style="margin:10px 0;"></div>
		<h4><?php esc_html_e( 'Available workflows', 'worldgraph' ); ?></h4>
		<div id="worldgraph-connection-configurator-results"></div>
		<h4><?php esc_html_e( 'Activity in this browser session', 'worldgraph' ); ?></h4>
		<div id="worldgraph-connection-configurator-log" class="description" style="margin:6px 0 10px;"></div>
		<?php
	}

	/** Sync the selected Connection's provider catalog. */
	public static function ajax_sync_catalog(): void {
		$connection_id = self::authorize_configurator_request();
		$result = self::catalog_sync( $connection_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/** Enable one provider catalog entry. */
	public static function ajax_enable_catalog_entry(): void {
		$connection_id = self::authorize_configurator_request();
		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_configurator_request() verifies this request's nonce.
		if ( '' === $entry_id ) {
			wp_send_json_error( [ 'message' => __( 'Select a provider workflow first.', 'worldgraph' ) ] );
		}

		$result = self::catalog_enable_entry( $connection_id, $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/** Disable one provider catalog entry. */
	public static function ajax_disable_catalog_entry(): void {
		$connection_id = self::authorize_configurator_request();
		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_configurator_request() verifies this request's nonce.
		if ( '' === $entry_id ) {
			wp_send_json_error( [ 'message' => __( 'Select a provider workflow first.', 'worldgraph' ) ] );
		}

		$result = self::catalog_disable_entry( $connection_id, $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/** Materialize one provider catalog entry into a World Graph Studio Template post. */
	public static function ajax_materialize_catalog_entry(): void {
		$connection_id = self::authorize_configurator_request();
		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_configurator_request() verifies this request's nonce.
		if ( '' === $entry_id ) {
			wp_send_json_error( [ 'message' => __( 'Select a provider workflow first.', 'worldgraph' ) ] );
		}

		$result = self::catalog_materialize_entry( $connection_id, $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/** Request provider-side downloads for one catalog entry. */
	public static function ajax_download_catalog_entry(): void {
		$connection_id = self::authorize_configurator_request();
		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_configurator_request() verifies this request's nonce.
		if ( '' === $entry_id ) {
			wp_send_json_error( [ 'message' => __( 'Select a provider workflow first.', 'worldgraph' ) ] );
		}

		$result = self::catalog_download_entry( $connection_id, $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Sync provider catalog for a ComfyUI connection.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array|\WP_Error
	 */
	public static function catalog_sync( int $connection_id ) {
		$result = \WorldGraph\Utils\Comfy_Catalog::sync( $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'message'  => sprintf(
				/* translators: %d: number of provider workflows found. */
				__( 'Found %d available provider workflow(s). Readiness is shown below.', 'worldgraph' ),
				count( (array) ( $result['entries'] ?? [] ) )
			),
			'snapshot' => \WorldGraph\Utils\Comfy_Catalog::get( $connection_id ),
		];
	}

	/**
	 * Enable one catalog entry.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id Catalog entry ID.
	 * @return array|\WP_Error
	 */
	public static function catalog_enable_entry( int $connection_id, string $entry_id ) {
		$result = \WorldGraph\Utils\Comfy_Catalog::enable( $connection_id, $entry_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'message'  => sprintf(
				/* translators: %s: provider workflow ID. */
				__( 'Selected provider workflow %s.', 'worldgraph' ),
				$entry_id
			),
			'snapshot' => \WorldGraph\Utils\Comfy_Catalog::get( $connection_id ),
		];
	}

	/**
	 * Disable one catalog entry.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id Catalog entry ID.
	 * @return array|\WP_Error
	 */
	public static function catalog_disable_entry( int $connection_id, string $entry_id ) {
		\WorldGraph\Utils\Comfy_Catalog::disable( $connection_id, $entry_id );

		return [
			'message'  => sprintf(
				/* translators: %s: provider workflow ID. */
				__( 'Removed provider workflow %s from this Connection.', 'worldgraph' ),
				$entry_id
			),
			'snapshot' => \WorldGraph\Utils\Comfy_Catalog::get( $connection_id ),
		];
	}

	/**
	 * Materialize one catalog entry into a Template.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id Catalog entry ID.
	 * @return array|\WP_Error
	 */
	public static function catalog_materialize_entry( int $connection_id, string $entry_id ) {
		\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );

		$template_id = self::materialize_catalog_entry( $connection_id, $entry_id );
		if ( is_wp_error( $template_id ) ) {
			\WorldGraph\Utils\Generation_Log::add(
				'error',
				'connection_setup',
				$template_id->get_error_message(),
				[ 'workflow_id' => $entry_id ],
				'',
				$connection_id
			);
			return $template_id;
		}

		$message = sprintf(
			/* translators: 1: provider workflow ID, 2: Generation Template post ID. */
			__( 'Added provider workflow %1$s to Studio as Generation Template #%2$d.', 'worldgraph' ),
			$entry_id,
			$template_id
		);
		\WorldGraph\Utils\Generation_Log::add(
			'info',
			'connection_setup',
			$message,
			[ 'workflow_id' => $entry_id, 'template_id' => (int) $template_id ],
			'',
			$connection_id
		);

		return [
			'message'     => $message,
			'template_id' => (int) $template_id,
			'edit_url'    => get_edit_post_link( (int) $template_id, '' ),
			'snapshot'    => \WorldGraph\Utils\Comfy_Catalog::get( $connection_id ),
		];
	}

	/**
	 * Trigger provider-side requirement downloads for one catalog entry.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id Catalog entry ID.
	 * @return array|\WP_Error
	 */
	public static function catalog_download_entry( int $connection_id, string $entry_id ) {
		\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );

		if ( \WorldGraph\Utils\Comfy_Template_Registry::owns( $entry_id ) ) {
			$result = self::catalog_download_registry_entry( $connection_id, $entry_id );
		} else {
			$provider_result = \WorldGraph\Utils\Comfy_Manifest::request_provider_template_downloads( $entry_id, $connection_id );
			$result = is_wp_error( $provider_result )
				? $provider_result
				: [
					'message' => sprintf(
						/* translators: %d: number of provider requirements requested for installation. */
						__( 'Requested installation of %d provider requirement(s).', 'worldgraph' ),
						count( (array) ( $provider_result['requested'] ?? [] ) )
					),
					'result'  => $provider_result,
				];
		}

		if ( is_wp_error( $result ) ) {
			\WorldGraph\Utils\Generation_Log::add( 'error', 'connection_setup', $result->get_error_message(), [ 'workflow_id' => $entry_id ], '', $connection_id );
			return $result;
		}

		\WorldGraph\Utils\Generation_Log::add( 'info', 'connection_setup', (string) ( $result['message'] ?? __( 'Requested the required model files.', 'worldgraph' ) ), [ 'workflow_id' => $entry_id ], '', $connection_id );
		return $result;
	}

	/**
	 * Install the models a published ComfyUI template needs.
	 *
	 * A ComfyUI reached over plain HTTP has no download API, so where the fetch
	 * cannot be delegated the operator is told exactly which file belongs in
	 * which folder rather than being left with a template that will not run.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id      Registry entry ID.
	 * @return array|\WP_Error
	 */
	private static function catalog_download_registry_entry( int $connection_id, string $entry_id ) {
		$endpoint  = (string) \WorldGraph\Utils\worldgraph_get_field_value( $connection_id, 'endpoint_url' );
		$readiness = \WorldGraph\Utils\Comfy_Template_Registry::readiness( $entry_id, $endpoint );
		if ( is_wp_error( $readiness ) ) {
			return $readiness;
		}

		$missing = (array) ( $readiness['missing'] ?? [] );
		if ( empty( $missing ) ) {
			return [ 'message' => __( 'Every model this template needs is already installed.', 'worldgraph' ), 'result' => [] ];
		}

		$urls   = [];
		$manual = [];
		foreach ( $missing as $model ) {
			if ( ! empty( $model['url'] ) ) {
				$urls[] = (string) $model['url'];
				continue;
			}
			$manual[] = sprintf( '%s (models/%s)', (string) $model['filename'], (string) $model['folder'] );
		}

		if ( ! empty( $urls ) ) {
			$result = \WorldGraph\Utils\Comfy_Cloud_MCP::download_models( $urls, $connection_id );
			if ( ! is_wp_error( $result ) ) {
				return [
					'message' => sprintf(
						/* translators: %d: number of model downloads requested. */
						__( 'Requested %d model download(s).', 'worldgraph' ),
						count( $urls )
					),
					'result'  => $result,
				];
			}

			foreach ( $missing as $model ) {
				$manual[] = sprintf( '%s → models/%s (%s)', (string) $model['filename'], (string) $model['folder'], (string) ( $model['url'] ?? '' ) );
			}
		}

		return new \WP_Error(
			'worldgraph_registry_manual_install',
			sprintf(
				/* translators: %s: newline-separated install instructions. */
				__( 'This ComfyUI cannot be asked to download models. Install these files manually: %s', 'worldgraph' ),
				implode( '; ', array_unique( $manual ) )
			),
			[ 'missing' => $missing ]
		);
	}

	/**
	 * Sync, then auto-enable and materialize every mappable catalog entry.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array|\WP_Error
	 */
	public static function catalog_prepare_mappable( int $connection_id ) {
		$sync = self::catalog_sync( $connection_id );
		if ( is_wp_error( $sync ) ) {
			return $sync;
		}

		$entries  = (array) ( $sync['snapshot']['entries'] ?? [] );
		$prepared = [];
		$failed   = [];

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['modality'] ) ) {
				continue;
			}

			// A published template that is missing models cannot be converted,
			// so auto-preparation only claims the ones already runnable.
			if ( 'registry' === ( $entry['source'] ?? '' ) && 'ready' !== ( $entry['status'] ?? '' ) ) {
				continue;
			}

			$entry_id = sanitize_text_field( (string) ( $entry['id'] ?? '' ) );
			if ( '' === $entry_id ) {
				continue;
			}

			if ( empty( $entry['enabled'] ) ) {
				$enabled = self::catalog_enable_entry( $connection_id, $entry_id );
				if ( is_wp_error( $enabled ) ) {
					$failed[] = [ 'entry_id' => $entry_id, 'message' => $enabled->get_error_message() ];
					continue;
				}
			}

			$materialized = self::catalog_materialize_entry( $connection_id, $entry_id );
			if ( is_wp_error( $materialized ) ) {
				$failed[] = [ 'entry_id' => $entry_id, 'message' => $materialized->get_error_message() ];
				continue;
			}

			$prepared[] = [
				'entry_id'    => $entry_id,
				'template_id' => (int) ( $materialized['template_id'] ?? 0 ),
			];
		}

		$snapshot = \WorldGraph\Utils\Comfy_Catalog::get( $connection_id );
		return [
			'message'  => sprintf(
				/* translators: 1: workflows added to Studio, 2: workflows that could not be added. */
				__( 'Added %1$d ready workflow(s) to Studio; %2$d could not be added.', 'worldgraph' ),
				count( $prepared ),
				count( $failed )
			),
			'prepared' => $prepared,
			'failed'   => $failed,
			'snapshot' => $snapshot,
		];
	}

	/**
	 * Permission and nonce gate for configurator actions.
	 *
	 * @return int Connection post ID.
	 */
	private static function authorize_configurator_request(): int {
		check_ajax_referer( 'worldgraph_conn_configurator', 'nonce' );
		$connection_id = isset( $_POST['connection_id'] ) ? absint( wp_unslash( $_POST['connection_id'] ) ) : 0;
		if ( ! $connection_id || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $connection_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to configure this Connection.', 'worldgraph' ) ], 403 );
		}

		$post = get_post( $connection_id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			wp_send_json_error( [ 'message' => __( 'That Connection record no longer exists.', 'worldgraph' ) ], 404 );
		}
		\WorldGraph\Utils\Connection_Adapters::load( (string) \WorldGraph\Utils\worldgraph_get_field_value( $connection_id, 'provider_type' ) );

		return $connection_id;
	}

	/**
	 * Materialize one provider entry into a World Graph Studio Template post.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id      Catalog entry ID.
	 * @return int|\WP_Error Template post ID.
	 */
	private static function materialize_catalog_entry( int $connection_id, string $entry_id ) {
		$entry = \WorldGraph\Utils\Comfy_Catalog::find( $connection_id, $entry_id );
		if ( ! is_array( $entry ) ) {
			return new \WP_Error( 'worldgraph_catalog_entry_missing', __( 'Refresh the available workflows before adding this one.', 'worldgraph' ) );
		}
		if ( empty( $entry['modality'] ) ) {
			return new \WP_Error( 'worldgraph_catalog_entry_unmappable', __( 'This provider workflow does not map to a Studio generation type.', 'worldgraph' ) );
		}

		if ( \WorldGraph\Utils\Comfy_Template_Registry::owns( $entry_id ) ) {
			$workflow = self::registry_workflow( $connection_id, $entry_id );
			if ( is_wp_error( $workflow ) ) {
				return $workflow;
			}
			$entry = self::registry_requirements( $connection_id, $entry_id, $entry );
		} else {
			$raw = \WorldGraph\Utils\Comfy_Cloud_MCP::get_template( $entry_id, [], $connection_id );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}
			$raw = is_array( $raw ) ? $raw : [];
			$normalized = \WorldGraph\Utils\Comfy_Manifest::normalize_entry( array_merge( $entry, $raw ), $connection_id );
			if ( is_array( $normalized ) ) {
				$entry = array_merge( $entry, $normalized );
			}
			$workflow = is_array( $raw['workflow'] ?? null ) ? $raw['workflow'] : [];
		}

		$existing = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => 'connection_id', 'value' => (string) $connection_id ],
				[ 'key' => 'provider_template_id', 'value' => $entry_id ],
			],
		] );

		$template_id = $existing ? (int) $existing[0] : 0;
		$template_id = wp_insert_post( [
			'ID'          => $template_id,
			'post_type'   => 'worldgraph_template',
			'post_title'  => (string) ( $entry['name'] ?? $entry_id ),
			'post_status' => 'publish',
		], true );
		if ( is_wp_error( $template_id ) || ! $template_id ) {
			return new \WP_Error( 'worldgraph_template_materialize_failed', __( 'Studio could not create a Generation Template for that provider workflow.', 'worldgraph' ) );
		}

		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'template_name', (string) ( $entry['name'] ?? $entry_id ) );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'provider_type', 'comfyui' );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'status', 'active' );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'modality', (string) $entry['modality'] );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'generation_structure', \WorldGraph\Utils\Generation_Modality::output_type( (string) $entry['modality'] ) );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'connection_id', (string) $connection_id );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'provider_template_id', $entry_id );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'model_family', \WorldGraph\Utils\Model_Family::sanitize( (string) ( $entry['model_family'] ?? '' ) ) );

		if ( ! empty( $workflow ) ) {
			if ( ! \WorldGraph\Utils\Comfy_Graph::is_editor_graph( $workflow ) ) {
				$workflow = \WorldGraph\Utils\Comfy_Graph::apply_prompt_placeholders( $workflow );
			}
			\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'workflow_json', (string) wp_json_encode( $workflow ) );
		}
		if ( ( ! empty( $entry['parameters'] ) && is_array( $entry['parameters'] ) ) || ( ! empty( $entry['provider_schema'] ) && is_array( $entry['provider_schema'] ) ) ) {
			$configuration = json_decode( (string) \WorldGraph\Utils\worldgraph_get_field_value( (int) $template_id, 'configuration_json' ), true );
			$configuration = is_array( $configuration ) ? $configuration : [];
			if ( ! empty( $entry['parameters'] ) && is_array( $entry['parameters'] ) ) {
				$configuration['parameters'] = $entry['parameters'];
			}
			if ( ! empty( $entry['provider_schema'] ) && is_array( $entry['provider_schema'] ) ) {
				$configuration['provider_schema'] = $entry['provider_schema'];
			}
			\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'configuration_json', (string) wp_json_encode( $configuration ) );
		}

		$requirements = self::requirements_from_entry( $entry );
		if ( ! empty( $requirements ) ) {
			\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'model_requirements', (string) wp_json_encode( $requirements ) );
		}

		$checkpoint = '';
		foreach ( (array) ( $entry['models'] ?? [] ) as $model ) {
			if ( is_array( $model ) && 'checkpoints' === (string) ( $model['folder'] ?? '' ) && ! empty( $model['filename'] ) ) {
				$checkpoint = (string) $model['filename'];
				break;
			}
		}
		if ( '' !== $checkpoint ) {
			\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'checkpoint', $checkpoint );
		}

		\WorldGraph\Utils\Comfy_Catalog::enable( $connection_id, $entry_id );
		\WorldGraph\Utils\Comfy_Catalog::link_template( $connection_id, $entry_id, (int) $template_id );

		return (int) $template_id;
	}

	/**
	 * Convert a published ComfyUI template into a workflow this Connection's
	 * instance can execute.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id      Registry entry ID.
	 * @return array|\WP_Error
	 */
	private static function registry_workflow( int $connection_id, string $entry_id ) {
		$endpoint = (string) \WorldGraph\Utils\worldgraph_get_field_value( $connection_id, 'endpoint_url' );
		if ( '' === trim( $endpoint ) ) {
			return new \WP_Error( 'worldgraph_registry_no_endpoint', __( 'Set this Connection\'s ComfyUI URL before installing a published template.', 'worldgraph' ) );
		}

		return \WorldGraph\Utils\Comfy_Template_Registry::workflow( $entry_id, $endpoint );
	}

	/**
	 * Fold a published template's resolved model files and download sources
	 * back onto its catalog entry, so the Template records what to install.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id      Registry entry ID.
	 * @param array  $entry         Catalog entry.
	 * @return array
	 */
	private static function registry_requirements( int $connection_id, string $entry_id, array $entry ): array {
		$endpoint = (string) \WorldGraph\Utils\worldgraph_get_field_value( $connection_id, 'endpoint_url' );
		$readiness = \WorldGraph\Utils\Comfy_Template_Registry::readiness( $entry_id, $endpoint );
		if ( is_wp_error( $readiness ) ) {
			return $entry;
		}

		$entry['models'] = array_values( array_filter(
			(array) ( $readiness['models_required'] ?? [] ),
			static function ( $model ): bool {
				return is_array( $model ) && ! empty( $model['filename'] ) && ! empty( $model['folder'] );
			}
		) );
		$entry['model_urls'] = array_values( array_filter( array_map(
			static function ( array $model ): string {
				return (string) ( $model['url'] ?? '' );
			},
			$entry['models']
		) ) );

		return $entry;
	}

	/**
	 * Convert provider entry model metadata into model_requirements JSON.
	 *
	 * @param array $entry Catalog entry.
	 * @return array<int, array<string, string>>
	 */
	private static function requirements_from_entry( array $entry ): array {
		$requirements = [];
		$urls = array_values( array_filter( array_map( 'strval', (array) ( $entry['model_urls'] ?? [] ) ) ) );
		$models = array_values( array_filter( (array) ( $entry['models'] ?? [] ), static function ( $model ): bool {
			return is_array( $model ) && ! empty( $model['filename'] ) && ! empty( $model['folder'] );
		} ) );

		foreach ( $models as $index => $model ) {
			$filename = (string) $model['filename'];
			$folder   = (string) $model['folder'];
			$url      = '';

			foreach ( $urls as $candidate ) {
				if ( false !== stripos( $candidate, $filename ) ) {
					$url = $candidate;
					break;
				}
			}
			if ( '' === $url && isset( $urls[ $index ] ) ) {
				$url = $urls[ $index ];
			}

			if ( '' === $url ) {
				continue;
			}

			$requirements[] = [ 'filename' => $filename, 'folder' => $folder, 'url' => $url ];
		}

		return $requirements;
	}

	/**
	 * Create or update the single Connection post managed for a given setup-wizard slot.
	 *
	 * Used by the setup wizard so that saving "Generation Connection" or "LLM
	 * Connection" populates a real Connection record instead of only options.
	 *
	 * @param string $slot  Wizard slot marker, e.g. 'generation' or 'llm'.
	 * @param string $title Post title / connection name.
	 * @param array  $meta  Meta fields to set (subset of the registered fields).
	 * @return int Connection post ID.
	 */
	public static function upsert_managed( string $slot, string $title, array $meta ): int {
		if ( ! current_user_can( 'manage_options' ) ) {
			return 0;
		}

		$existing = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => 'worldgraph_wizard_slot', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $slot, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
		] );

		$post_id = $existing ? (int) $existing[0] : 0;
		$post_id = wp_insert_post( [
			'ID'          => $post_id ?: 0,
			'post_type'   => self::CPT,
			'post_title'  => $title,
			'post_status' => 'publish',
		], true );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, 'worldgraph_wizard_slot', $slot );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $post_id, 'connection_name', $title );
		$fields = \WorldGraph\Utils\worldgraph_get_fields( self::CPT );
		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( isset( $fields[ $key ] ) ) {
				\WorldGraph\Utils\worldgraph_update_field_value( (int) $post_id, $key, $value );
			}
		}

		return (int) $post_id;
	}

	/**
	 * Sanitize a JSON textarea field.
	 *
	 * @param string $raw Raw input.
	 * @return string|null Normalized JSON string, or null when the input is
	 *                     non-empty but not valid JSON.
	 */
	private static function sanitize_json_field( string $raw ): ?string {
		$trimmed = trim( $raw );
		if ( '' === $trimmed ) {
			return '';
		}

		$decoded = json_decode( $trimmed );
		if ( JSON_ERROR_NONE !== json_last_error() || ( ! is_array( $decoded ) && ! is_object( $decoded ) ) ) {
			return null;
		}

		return wp_json_encode( $decoded );
	}
}
