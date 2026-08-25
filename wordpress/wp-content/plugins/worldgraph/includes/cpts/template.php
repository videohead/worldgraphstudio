<?php
/**
 * Generation Template Custom Post Type.
 *
 * @package WorldGraph
 */

namespace WorldGraph\CPT;

/**
 * Generation Template Custom Post Type handler.
 */
class Template {
	/**
	 * Register the Generation Template CPT and admin UI.
	 */
	public static function init(): void {
		self::register_cpt();
		self::register_meta_boxes();
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_requirements_script' ] );
		add_filter( 'manage_worldgraph_template_posts_columns', [ __CLASS__, 'admin_columns' ] );
		add_action( 'manage_worldgraph_template_posts_custom_column', [ __CLASS__, 'admin_column_content' ], 10, 2 );
		add_filter( 'acf/update_value', [ __CLASS__, 'sanitize_scf_value' ], 30, 4 );
		add_filter( 'acf/validate_value', [ __CLASS__, 'validate_scf_value' ], 20, 4 );
		add_filter( 'acf/load_field/key=field_worldgraph_template_modality', [ __CLASS__, 'load_modality_choices' ] );
		add_filter( 'acf/load_field/key=field_worldgraph_template_model_family', [ __CLASS__, 'load_model_family_choices' ] );
		add_action( 'wp_ajax_worldgraph_check_template_requirements', [ __CLASS__, 'ajax_check_requirements' ] );
		add_action( 'wp_ajax_worldgraph_install_template_models', [ __CLASS__, 'ajax_install_models' ] );
		add_action( 'wp_ajax_worldgraph_discover_comfy_templates', [ __CLASS__, 'ajax_discover_comfy_templates' ] );
		add_action( 'wp_ajax_worldgraph_download_comfy_template_requirements', [ __CLASS__, 'ajax_download_comfy_template_requirements' ] );
		add_action( 'wp_ajax_worldgraph_import_provider_template_definition', [ __CLASS__, 'ajax_import_provider_template_definition' ] );
		add_action( 'wp_ajax_worldgraph_run_template_smoke_test', [ __CLASS__, 'ajax_run_template_smoke_test' ] );
	}

	/**
	 * Refresh registry-backed choices without requiring a JSON rewrite when an
	 * adapter extends the available modality or model-family registry.
	 *
	 * @param array<string, mixed> $field SCF field.
	 * @return array<string, mixed>
	 */
	public static function admin_columns( array $columns ): array {
		$columns['worldgraph_template_status']    = __( 'Template Status', 'worldgraph' );
		$columns['worldgraph_template_connection'] = __( 'Connection', 'worldgraph' );
		$columns['worldgraph_template_model']      = __( 'Model', 'worldgraph' );
		$columns['worldgraph_template_smoke']      = __( 'Smoke Test', 'worldgraph' );
		$columns['worldgraph_template_summary']    = __( 'What it does', 'worldgraph' );
		return $columns;
	}

	public static function admin_column_content( string $column, int $post_id ): void {
		if ( 'worldgraph_template_status' === $column ) {
			$status = \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'status' );
			$status = '' === (string) $status ? 'draft' : (string) $status;
			echo esc_html( ucfirst( $status ) );
			return;
		}

		if ( 'worldgraph_template_connection' === $column ) {
			$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'connection_id' ) );
			if ( ! $connection_id ) {
				echo '—';
				return;
			}
			$connection = get_post( $connection_id );
			if ( ! $connection instanceof \WP_Post || 'worldgraph_conn' !== $connection->post_type ) {
				echo esc_html( (string) $connection_id );
				return;
			}
			$edit_url = admin_url( 'post.php?post=' . $connection_id . '&action=edit' );
			echo '<a href="' . esc_url( $edit_url ) . '" title="' . esc_attr__( 'Edit Connection', 'worldgraph' ) . '">' . esc_html( $connection->post_title ?: '#' . $connection_id ) . '</a>';
			return;
		}

		if ( 'worldgraph_template_model' === $column ) {
			$checkpoint = (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'checkpoint' );
			$model_family = (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'model_family' );
			$label = $checkpoint ?: $model_family;
			echo esc_html( $label ?: '—' );
			return;
		}

		if ( 'worldgraph_template_smoke' === $column ) {
			$result = get_post_meta( $post_id, '_worldgraph_template_smoke_result', true );
			if ( ! is_array( $result ) ) {
				echo '—';
				return;
			}
			$passed = ! empty( $result['passed'] );
			echo esc_html( $passed ? 'Passed' : ( $result['status'] ?? 'Unknown' ) );
			return;
		}

		if ( 'worldgraph_template_summary' === $column ) {
			$modality = (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'modality' );
			$modality_label = '' !== $modality ? (string) \WorldGraph\Utils\Generation_Modality::get( $modality )['label'] : '';
			$provider = (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'provider_type' );
			$checkpoint = (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'checkpoint' );
			$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'connection_id' ) );
			$connection_label = $connection_id ? get_the_title( $connection_id ) : '';
			$summary = array_filter( [ $modality_label ?: 'Template', $provider ? ucfirst( $provider ) : '', $connection_label ? 'Connection: ' . $connection_label : '', $checkpoint ? 'Model: ' . $checkpoint : '' ] );
			echo esc_html( implode( ' • ', $summary ) );
		}
	}

	public static function load_modality_choices( array $field ): array {
		$field['choices'] = \WorldGraph\Utils\Generation_Modality::labels();
		return $field;
	}

	/**
	 * Refresh model-family choices from the runtime registry.
	 *
	 * @param array<string, mixed> $field SCF field.
	 * @return array<string, mixed>
	 */
	public static function load_model_family_choices( array $field ): array {
		$field['choices'] = \WorldGraph\Utils\Model_Family::labels();
		return $field;
	}

	/**
	 * Apply Template-specific normalization after SCF field-type handling.
	 *
	 * @param mixed                $value    Submitted value.
	 * @param int|string           $post_id  SCF object ID.
	 * @param array<string, mixed> $field    SCF field.
	 * @param mixed                $original Original submitted value.
	 * @return mixed
	 */
	public static function sanitize_scf_value( $value, $post_id, array $field, $original ) {
		if ( ! is_numeric( $post_id ) || 'worldgraph_template' !== get_post_type( (int) $post_id ) || 0 !== strpos( (string) ( $field['key'] ?? '' ), 'field_worldgraph_template_' ) ) {
			return $value;
		}

		$name = (string) ( $field['name'] ?? '' );
		if ( 'modality' === $name ) {
			return \WorldGraph\Utils\Generation_Modality::sanitize( (string) $value );
		}
		if ( 'model_family' === $name ) {
			return \WorldGraph\Utils\Model_Family::sanitize( (string) $value );
		}
		if ( 'connection_id' === $name ) {
			return '' === trim( (string) $value ) ? '' : (string) absint( $value );
		}
		if ( 'status' === $name ) {
			$value = sanitize_key( (string) $value );
			return in_array( $value, [ 'draft', 'active', 'archived' ], true ) ? $value : 'draft';
		}
		if ( in_array( $name, [ 'workflow_json', 'configuration_json', 'input_bindings', 'model_requirements', 'default_values' ], true ) ) {
			$normalized = self::normalize_json( (string) $value );
			return null === $normalized
				? \WorldGraph\Utils\worldgraph_get_field_value( (int) $post_id, $name )
				: $normalized;
		}

		return $value;
	}

	/**
	 * Validate Template-specific SCF values before save.
	 *
	 * @param bool|string          $valid Whether the value is valid so far.
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field SCF field.
	 * @param string               $input Input name.
	 * @return bool|string
	 */
	public static function validate_scf_value( $valid, $value, array $field, string $input ) {
		if ( true !== $valid || 0 !== strpos( (string) ( $field['key'] ?? '' ), 'field_worldgraph_template_' ) ) {
			return $valid;
		}

		$name = (string) ( $field['name'] ?? '' );
		if ( 'connection_id' === $name && '' !== trim( (string) $value ) && ( ! ctype_digit( (string) $value ) || 'worldgraph_conn' !== get_post_type( (int) $value ) ) ) {
			return __( 'Select an existing World Graph Studio Connection ID.', 'worldgraph' );
		}
		if ( 'lora_strength' === $name && '' !== trim( (string) $value ) && ! is_numeric( $value ) ) {
			return __( 'Enter a numeric LoRA strength, e.g. 0.8.', 'worldgraph' );
		}
		if ( in_array( $name, [ 'workflow_json', 'configuration_json', 'input_bindings', 'model_requirements', 'default_values' ], true ) && null === self::normalize_json( (string) $value ) ) {
			return __( 'Enter valid JSON.', 'worldgraph' );
		}

		return $valid;
	}

	/**
	 * Normalize JSON text while preserving arrays, objects, and escaping.
	 *
	 * @return string|null Normalized JSON, blank, or null when invalid.
	 */
	private static function normalize_json( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$decoded = json_decode( $value );
		if ( JSON_ERROR_NONE !== json_last_error() || ( ! is_array( $decoded ) && ! is_object( $decoded ) ) ) {
			return null;
		}

		return (string) wp_json_encode( $decoded );
	}

	/**
	 * Register the Generation Template CPT.
	 */
	private static function register_cpt(): void {
		$fields = [
			'template_name'       => [
				'type'        => 'text',
				'label'       => 'Template Name',
				'required'    => true,
			],
			'description'         => [
				'type'        => 'wysiwyg',
				'label'       => 'Description',
				'required'    => false,
			],
			'generation_structure' => [
				'type'        => 'text',
				'label'       => 'Generation Structure',
				'required'    => true,
			],
			'modality'            => [
				'type'        => 'select',
				'label'       => 'Modality',
				'required'    => true,
				'options'     => \WorldGraph\Utils\Generation_Modality::labels(),
				'description' => 'What this Template generates and which inputs it consumes. Determines the output type and any provider-specific workflow requirements.',
			],
			'connection_id'       => [
				'type'        => 'text',
				'label'       => 'Connection ID',
				'required'    => false,
				'description' => 'The worldgraph_conn post ID this template runs against (a Connection can back many Templates/checkpoints).',
			],
			'checkpoint'          => [
				'type'        => 'text',
				'label'       => 'Checkpoint / Model',
				'required'    => false,
				'description' => 'Checkpoint filename installed on the Connection, e.g. LTX-2.3/ltx-2.3-22b-dev-fp8.safetensors.',
			],
			'lora_name'           => [
				'type'        => 'text',
				'label'       => 'LoRA',
				'required'    => false,
				'description' => 'Optional LoRA filename installed on the Connection\'s models/loras directory, applied on top of the checkpoint above for the built-in workflow. Leave blank to run without a LoRA. Ignored when a custom ComfyUI API Workflow is set below.',
			],
			'lora_strength'       => [
				'type'        => 'text',
				'label'       => 'LoRA Strength',
				'required'    => false,
				'description' => 'Model and CLIP strength applied to the LoRA above, typically 0.0-1.0. Defaults to 1.0 when left blank.',
			],
			'model_family'        => [
				'type'        => 'select',
				'label'       => 'Model Family',
				'required'    => false,
				'options'     => \WorldGraph\Utils\Model_Family::labels(),
				'description' => 'The generative model this Template runs, e.g. LTX 2.5, MiniMax, SCAIL, or Wan 2.2. Used to group Templates and cross-check against the Connection\'s allowed models.',
			],
			'workflow_json'       => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'ComfyUI API Workflow (optional)',
				'required'    => false,
				'description' => 'Paste a ComfyUI workflow exported with “Save (API Format)”. Managed Templates receive a published workflow automatically. A manually authored Template may leave this blank only when its checkpoint field defines a compatible simple graph.',
			],
			'provider_template_id' => [
				'type'        => 'text',
				'label'       => 'Provider Template / Model Endpoint ID',
				'required'    => false,
				'description' => 'Provider identifier paired with the Connection. For Suno or MidJourney use an api: or mcp: operation reference; for fal use a model endpoint ID; for ElevenLabs use a voice ID; for ComfyUI use the discovered MCP Template ID; for OpenRouter use a video model slug, e.g. google/veo-3.1.',
			],
			'configuration_json'  => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'Configuration JSON',
				'required'    => true,
				'description' => 'Provider-neutral JSON for optional parameter overrides, references, and SCF field mappings. Provider inputs live under {"input": {...}}; World Graph Studio adds the prompt and resolved bindings at runtime.',
			],
			'input_bindings'      => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'Input Bindings JSON',
				'required'    => false,
				'description' => 'Optional JSON mapping prompt-related fields to Story Graph sources for the text-to-image workflow.',
			],
			'model_requirements'  => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'Model Requirements JSON',
				'required'    => false,
				'description' => 'Optional JSON array of download sources for the models this Template loads: [{"filename":"ltx-2.3.safetensors","folder":"checkpoints","url":"https://…"}]. Used by the requirement check to offer a one-click install.',
			],
			'default_values'     => [
				'type'        => 'textarea',
				'format'      => 'json',
				'label'       => 'Default Values',
				'required'    => false,
			],
			'provider_type'      => [
				'type'        => 'text',
				'label'       => 'Provider Type',
				'required'    => false,
			],
			'version'            => [
				'type'        => 'text',
				'label'       => 'Version',
				'required'    => false,
			],
			'status'             => [
				'type'        => 'select',
				'label'       => 'Status',
				'required'    => true,
				'options'     => [
					'draft'     => 'Draft',
					'active'    => 'Active',
					'archived'  => 'Archived',
				],
			],
		];

		\WorldGraph\Utils\register_cpt(
			'worldgraph_template',
			'Templates',
			[
				'menu_icon' => 'dashicons-media-document',
				'show_in_menu' => 'worldgraph-generate',
			],
			$fields
		);
	}

	/**
	 * Register admin UI for template configuration.
	 */
	private static function register_meta_boxes(): void {
		add_action( 'add_meta_boxes', function (): void {
			add_meta_box(
				'worldgraph_template_requirements',
				'ComfyUI Requirements',
				[ self::class, 'render_requirements_meta_box' ],
				'worldgraph_template',
				'side',
				'default'
			);
		} );
	}

	/**
	 * Enqueue the ComfyUI requirements controller on Template edit screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_requirements_script( string $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'worldgraph_template' !== $screen->post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen routing only.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! self::current_user_can_manage_provider_operations( $post_id ) ) {
			return;
		}

		$handle      = 'worldgraph-template-requirements';
		$script_path = WORLDGRAPH_PLUGIN_DIR . 'assets/js/template-requirements.js';

		wp_enqueue_script(
			$handle,
			WORLDGRAPH_PLUGIN_URL . 'assets/js/template-requirements.js',
			[],
			is_file( $script_path ) ? (string) filemtime( $script_path ) : WORLDGRAPH_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'worldgraphTemplateRequirements',
			[
				'ajaxUrl'                  => admin_url( 'admin-ajax.php' ),
				'nonce'                    => wp_create_nonce( 'worldgraph_template_requirements' ),
				'postId'                   => $post_id,
				'providerTemplateFieldIds' => [
					'acf-field_worldgraph_template_provider_template_id',
					'provider_template_id',
					'acf-field_worldgraph_template_comfy_template_id',
					'comfy_template_id',
				],
				'i18n'                     => [
					'checking'             => __( 'Checking…', 'worldgraph' ),
					'requirementFailed'    => __( 'The requirement check could not be completed.', 'worldgraph' ),
					'searching'            => __( 'Searching Comfy MCP...', 'worldgraph' ),
					'discoveryFailed'      => __( 'Template discovery failed.', 'worldgraph' ),
					'selectTemplate'       => __( 'Select a ComfyUI MCP Template first.', 'worldgraph' ),
					'runningSmokeTest'     => __( 'Running smoke test…', 'worldgraph' ),
					'smokeTestFailed'      => __( 'The smoke test could not be completed.', 'worldgraph' ),
				],
			]
		);
	}

	/**
	 * Render the ComfyUI requirements panel: what this Template will ask
	 * ComfyUI for, and whether the connected instance can supply it.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public static function render_requirements_meta_box( \WP_Post $post ): void {
		$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post->ID, 'connection_id' ) );
		if ( ! $connection_id ) {
			echo '<p>' . esc_html__( 'Save this Template with a Connection before running provider checks.', 'worldgraph' ) . '</p>';
			return;
		}
		if ( ! \WorldGraph\Utils\Connection_Repository::current_user_can_manage( $connection_id ) ) {
			echo '<p>' . esc_html__( 'A site administrator who can manage the selected Connection must run provider checks, discovery, imports, and downloads.', 'worldgraph' ) . '</p>';
			return;
		}

		$connection = \WorldGraph\Utils\Connection_Repository::get( $connection_id );
		if ( ! $connection || 'comfyui' !== $connection['provider_type'] ) {
			echo '<p>' . esc_html__( 'This Template is paired with a non-ComfyUI provider. Use that provider connection\'s adapter to discover and download its requirements.', 'worldgraph' ) . '</p>';
			return;
		}
		\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );
		$manifest = \WorldGraph\Utils\Comfy_Manifest::for_template( $post->ID );
		if ( is_wp_error( $manifest ) ) {
			echo '<p>' . esc_html__( 'Save this Template to see its ComfyUI requirements.', 'worldgraph' ) . '</p>';
			return;
		}
		?>
		<p>
			<strong><?php echo esc_html( $manifest['modality_label'] ); ?></strong><br />
			<span class="description">
				<?php
				printf(
					/* translators: 1: output media kind, 2: workflow source. */
					esc_html__( 'Outputs %1$s using the %2$s workflow.', 'worldgraph' ),
					esc_html( $manifest['output_type'] ),
					esc_html( 'custom' === $manifest['workflow_source'] ? __( 'pasted custom', 'worldgraph' ) : __( 'built-in', 'worldgraph' ) )
				);
				?>
			</span>
		</p>
		<p><strong><?php echo esc_html__( 'Inputs', 'worldgraph' ); ?></strong><br />
		<?php foreach ( $manifest['inputs'] as $slot => $input ) : ?>
			<code>{{<?php echo esc_html( $slot ); ?>}}</code>
			<?php echo esc_html( empty( $input['required'] ) ? __( '(optional)', 'worldgraph' ) : __( '(required)', 'worldgraph' ) ); ?><br />
		<?php endforeach; ?>
		</p>
		<p><strong><?php echo esc_html__( 'Models', 'worldgraph' ); ?></strong><br />
		<?php if ( empty( $manifest['models'] ) ) : ?>
			<span class="description"><?php echo esc_html__( 'None detected. Set a checkpoint above.', 'worldgraph' ); ?></span>
		<?php else : ?>
			<?php foreach ( $manifest['models'] as $model ) : ?>
				<code><?php echo esc_html( $model['filename'] ); ?></code> &rarr; <code>models/<?php echo esc_html( $model['folder'] ); ?></code>
				<span class="description">
					<?php
					printf(
						/* translators: 1: ComfyUI loader node class, 2: loader input field. */
						esc_html__( '(loader: %1$s.%2$s)', 'worldgraph' ),
						esc_html( (string) ( $model['node_class'] ?? '' ) ),
						esc_html( (string) ( $model['field'] ?? '' ) )
					);
					?>
				</span><br />
			<?php endforeach; ?>
		<?php endif; ?>
		</p>
		<p>
			<button type="button" class="button" id="worldgraph-check-requirements"><?php echo esc_html__( 'Check ComfyUI', 'worldgraph' ); ?></button>
			<button type="button" class="button" id="worldgraph-install-models"><?php echo esc_html__( 'Install missing models', 'worldgraph' ); ?></button>
		</p>
		<p><strong><?php echo esc_html__( 'ComfyUI MCP Template', 'worldgraph' ); ?></strong></p>
		<p><input type="search" class="regular-text" id="worldgraph-comfy-template-search" placeholder="<?php echo esc_attr__( 'Search provider templates', 'worldgraph' ); ?>" />
		<button type="button" class="button" id="worldgraph-discover-comfy-templates"><?php echo esc_html__( 'Discover', 'worldgraph' ); ?></button></p>
		<div id="worldgraph-comfy-template-results"></div>
		<p><button type="button" class="button" id="worldgraph-import-provider-template"><?php echo esc_html__( 'Import definition into this Template', 'worldgraph' ); ?></button></p>
		<p><button type="button" class="button" id="worldgraph-download-comfy-requirements"><?php echo esc_html__( 'Download selected requirements', 'worldgraph' ); ?></button></p>
		<p><button type="button" class="button" id="worldgraph-run-template-smoke-test"><?php echo esc_html__( 'Run smoke test', 'worldgraph' ); ?></button></p>
		<div id="worldgraph-requirements-result" aria-live="polite"></div>
		<?php
	}

	/**
	 * Report whether the connected ComfyUI can run this Template.
	 */
	public static function ajax_run_template_smoke_test(): void {
		$post_id = self::authorize_requirements_request();
		$result  = \WorldGraph\Utils\Template_Smoke_Check::run_for_template( $post_id );

		if ( ! empty( $result['passed'] ) ) {
			wp_send_json_success( [
				'message' => sprintf(
					/* translators: %s: smoke-test result message. */
					__( 'Smoke test passed: %s', 'worldgraph' ),
					(string) ( $result['message'] ?? __( 'Template queue smoke check passed.', 'worldgraph' ) )
				),
			] );
		}

		wp_send_json_error( [
			'message' => sprintf(
				/* translators: %s: smoke-test result message. */
				__( 'Smoke test failed: %s', 'worldgraph' ),
				(string) ( $result['message'] ?? __( 'No result was reported.', 'worldgraph' ) )
			),
		] );
	}

	public static function ajax_check_requirements(): void {
		$post_id = self::authorize_requirements_request();
		\WorldGraph\Utils\Comfy_Manifest::flush_catalog();
		$report = \WorldGraph\Utils\Comfy_Manifest::validate( $post_id );
		if ( is_wp_error( $report ) ) {
			wp_send_json_error( [ 'message' => $report->get_error_message() ] );
		}

		if ( ! empty( $report['ok'] ) ) {
			wp_send_json_success( [
				'message' => sprintf(
					/* translators: %s: ComfyUI base URL. */
					__( 'ComfyUI at %s has every node and model this Template needs.', 'worldgraph' ),
					$report['endpoint']
				),
				'report'  => $report,
			] );
		}

		$problems = [];
		if ( ! empty( $report['missing_nodes'] ) ) {
			$problems[] = sprintf(
				/* translators: %s: comma-separated node class names. */
				__( 'Missing nodes: %s.', 'worldgraph' ),
				implode( ', ', $report['missing_nodes'] )
			);
		}
		foreach ( $report['missing_models'] as $model ) {
			$problems[] = sprintf(
				/* translators: 1: model filename, 2: ComfyUI models sub-directory. */
				__( 'Missing model %1$s in models/%2$s.', 'worldgraph' ),
				$model['filename'],
				$model['folder']
			);
		}

		wp_send_json_error( [ 'message' => implode( ' ', $problems ), 'report' => $report ] );
	}

	/**
	 * Ask Comfy MCP to fetch the model files this Template is missing.
	 */
	public static function ajax_install_models(): void {
		$post_id = self::authorize_requirements_request();
		$result  = \WorldGraph\Utils\Comfy_Manifest::request_downloads( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		\WorldGraph\Utils\Comfy_Manifest::flush_catalog();
		wp_send_json_success( [
			'message' => empty( $result['requested'] )
				? (string) $result['message']
				: sprintf(
					/* translators: %d: number of model downloads requested. */
					_n( 'Requested %d model download.', 'Requested %d model downloads.', count( $result['requested'] ), 'worldgraph' ),
					count( $result['requested'] )
				),
			'result'  => $result,
		] );
	}

	/** Search the connected Comfy MCP template catalog. */
	public static function ajax_discover_comfy_templates(): void {
		$post_id = self::authorize_requirements_request();
		$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'connection_id' ) );
		$result = \WorldGraph\Utils\Comfy_Manifest::discover_provider_templates( sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ), $connection_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_requirements_request() verified this AJAX request.
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'templates' => $result ] );
	}

	/** Download requirements advertised by the selected Comfy MCP template. */
	public static function ajax_download_comfy_template_requirements(): void {
		$post_id = self::authorize_requirements_request();
		$provider_template_id = self::requested_provider_template_id( $post_id );
		if ( '' === $provider_template_id ) {
			wp_send_json_error( [ 'message' => __( 'Save a ComfyUI MCP Template ID first.', 'worldgraph' ) ] );
		}

		$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'connection_id' ) );
		$result = \WorldGraph\Utils\Comfy_Manifest::request_provider_template_downloads( $provider_template_id, $connection_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %d: number of provider Template requirement downloads requested. */
				__( 'Requested %d provider Template requirement downloads.', 'worldgraph' ),
				count( $result['requested'] ?? [] )
			),
			'result'  => $result,
		] );
	}

	/** Import a provider template definition into this World Graph Studio Template post. */
	public static function ajax_import_provider_template_definition(): void {
		$post_id = self::authorize_requirements_request();
		$provider_template_id = self::requested_provider_template_id( $post_id );
		if ( '' === $provider_template_id ) {
			wp_send_json_error( [ 'message' => __( 'Select a provider Template first.', 'worldgraph' ) ] );
		}

		$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'connection_id' ) );
		$raw = \WorldGraph\Utils\Comfy_Cloud_MCP::get_template( $provider_template_id, [], $connection_id );
		if ( is_wp_error( $raw ) ) {
			wp_send_json_error( [ 'message' => $raw->get_error_message() ] );
		}

		$normalized = \WorldGraph\Utils\Comfy_Manifest::normalize_entry( array_merge( [
			'id'   => $provider_template_id,
			'name' => (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'template_name' ),
		], is_array( $raw ) ? $raw : [] ) );
		if ( ! is_array( $normalized ) ) {
			wp_send_json_error( [ 'message' => __( 'Provider template payload was unreadable.', 'worldgraph' ) ] );
		}

		$workflow = is_array( $raw['workflow'] ?? null ) ? $raw['workflow'] : [];
		if ( ! empty( $workflow ) ) {
			// A provider definition ships its own demo prompt; swap it for the
			// placeholders the generation runner substitutes per job.
			if ( ! \WorldGraph\Utils\Comfy_Graph::is_editor_graph( $workflow ) ) {
				$workflow = \WorldGraph\Utils\Comfy_Graph::apply_prompt_placeholders( $workflow );
			}
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'workflow_json', (string) wp_json_encode( $workflow ) );
		}

		if ( ( ! empty( $normalized['parameters'] ) && is_array( $normalized['parameters'] ) ) || ( ! empty( $normalized['provider_schema'] ) && is_array( $normalized['provider_schema'] ) ) ) {
			$configuration = json_decode( (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'configuration_json' ), true );
			$configuration = is_array( $configuration ) ? $configuration : [];
			if ( ! empty( $normalized['parameters'] ) && is_array( $normalized['parameters'] ) ) {
				$configuration['parameters'] = $normalized['parameters'];
			}
			if ( ! empty( $normalized['provider_schema'] ) && is_array( $normalized['provider_schema'] ) ) {
				$configuration['provider_schema'] = $normalized['provider_schema'];
			}
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'configuration_json', (string) wp_json_encode( $configuration ) );
		}

		$requirements = self::requirements_from_provider_entry( $normalized );
		if ( ! empty( $requirements ) ) {
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'model_requirements', (string) wp_json_encode( $requirements ) );
		}

		if ( ! empty( $normalized['modality'] ) ) {
			$modality = \WorldGraph\Utils\Generation_Modality::sanitize( (string) $normalized['modality'] );
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'modality', $modality );
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'generation_structure', \WorldGraph\Utils\Generation_Modality::output_type( $modality ) );
		}

		\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'provider_type', 'comfyui' );
		\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'provider_template_id', $provider_template_id );
		if ( ! empty( $normalized['model_family'] ) ) {
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'model_family', \WorldGraph\Utils\Model_Family::sanitize( (string) $normalized['model_family'] ) );
		}

		foreach ( (array) ( $normalized['models'] ?? [] ) as $model ) {
			if ( is_array( $model ) && 'checkpoints' === (string) ( $model['folder'] ?? '' ) && ! empty( $model['filename'] ) ) {
				\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'checkpoint', (string) $model['filename'] );
				break;
			}
		}

		wp_send_json_success( [ 'message' => __( 'Provider template definition imported into this Template. Save the post to persist any unsaved field edits.', 'worldgraph' ) ] );
	}

	/**
	 * The provider template the request names, falling back to the one already
	 * stored on the Template. The nonce is verified before any caller reaches
	 * this helper.
	 *
	 * @param int $post_id Template post ID.
	 * @return string
	 */
	private static function requested_provider_template_id( int $post_id ): string {
		$posted = isset( $_POST['provider_template_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_requirements_request() verified every caller.
			? sanitize_text_field( wp_unslash( $_POST['provider_template_id'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_requirements_request().
			: '';
		if ( '' !== $posted ) {
			return $posted;
		}

		$stored = (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'provider_template_id' );

		return '' !== $stored ? $stored : (string) get_post_meta( $post_id, 'comfy_template_id', true );
	}

	/**
	 * Shared permission and nonce gate for the requirements panel actions.
	 *
	 * @return int Template post ID.
	 */
	private static function authorize_requirements_request(): int {
		check_ajax_referer( 'worldgraph_template_requirements', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'worldgraph_template' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to inspect this Template.', 'worldgraph' ) ], 403 );
		}

		$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'connection_id' ) );
		if ( ! $connection_id ) {
			wp_send_json_error( [ 'message' => __( 'Save this Template with a Connection before running provider operations.', 'worldgraph' ) ], 400 );
		}
		if ( ! \WorldGraph\Utils\Connection_Repository::current_user_can_manage( $connection_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to manage this Template\'s Connection.', 'worldgraph' ) ], 403 );
		}

		$connection = \WorldGraph\Utils\Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'comfyui' !== (string) ( $connection['provider_type'] ?? '' ) ) {
			wp_send_json_error( [ 'message' => __( 'This Template is not paired with a ComfyUI Connection.', 'worldgraph' ) ], 400 );
		}
		\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );

		return $post_id;
	}

	/**
	 * Whether the current user may expose the provider-operation controls for a
	 * Template. Ordinary Template editing does not require this privilege.
	 *
	 * @param int $post_id Template post ID.
	 * @return bool
	 */
	private static function current_user_can_manage_provider_operations( int $post_id ): bool {
		$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'connection_id' ) );

		return $connection_id && \WorldGraph\Utils\Connection_Repository::current_user_can_manage( $connection_id );
	}

	/**
	 * Convert normalized provider entry metadata to model requirements JSON.
	 *
	 * @param array $entry Normalized provider entry.
	 * @return array<int, array<string, string>>
	 */
	private static function requirements_from_provider_entry( array $entry ): array {
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
	 * Create or update the single Template post managed for a given setup-wizard
	 * slot, so a Connection's checkpoint/workflow configuration lives on one
	 * default Template instead of a separate global option.
	 *
	 * @param string $slot  Wizard slot marker, e.g. 'local_comfyui_default'.
	 * @param string $title Post title / template name.
	 * @param array  $meta  Meta fields to set (subset of the registered fields).
	 * @return int Template post ID.
	 */
	public static function upsert_managed( string $slot, string $title, array $meta ): int {
		$existing = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => 'worldgraph_wizard_slot', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $slot, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
		] );

		$post_id = $existing ? (int) $existing[0] : 0;
		$post_id = wp_insert_post( [
			'ID'          => $post_id ?: 0,
			'post_type'   => 'worldgraph_template',
			'post_title'  => $title,
			'post_status' => 'publish',
		], true );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, 'worldgraph_wizard_slot', $slot );
		\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'template_name', $title );
		foreach ( $meta as $key => $value ) {
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, (string) $key, $value );
		}

		return (int) $post_id;
	}
}
