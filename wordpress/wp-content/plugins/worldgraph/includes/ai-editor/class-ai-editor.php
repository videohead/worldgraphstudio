<?php
/**
 * AI Editor — Main module class.
 *
 * Bootstraps the AI Editor subsystem: REST endpoints, admin UI, Gutenberg panel,
 * LLM client, MAF bridge, agent router, and agent-skills loader.
 *
 * @package WorldGraph
 */

namespace WorldGraph\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main AI Editor class.
 */
class AI_Editor {

	/**
	 * LLM client instance.
	 *
	 * @var AI_LLM_Client
	 */
	private $llm_client;

	/**
	 * MAF bridge instance.
	 *
	 * @var AI_MAF_Bridge
	 */
	private $maf_bridge;

	/**
	 * Context builder instance.
	 *
	 * @var AI_Context_Builder
	 */
	private $context_builder;

	/**
	 * Agent router instance.
	 *
	 * @var AI_Agent_Router
	 */
	private $agent_router;

	/**
	 * Agent skills loader instance.
	 *
	 * @var AI_Agent_Skills
	 */
	private $agent_skills;

	/**
	 * Initialize the AI Editor module.
	 *
	 * @return void
	 */
	public static function init(): void {
		$instance = new self();
		add_action( 'rest_api_init', [ $instance, 'register_rest_routes' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_story_element_workflow_metabox' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_assets' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_filter( 'worldgraph_rest_context', [ __CLASS__, 'add_ai_context' ], 10, 2 );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->llm_client     = new AI_LLM_Client();
		$this->maf_bridge     = new AI_MAF_Bridge( $this->llm_client );
		$this->context_builder = new AI_Context_Builder();
		$this->agent_router   = new AI_Agent_Router();
		$this->agent_skills   = new AI_Agent_Skills();
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$controller = new AI_Editor_REST();
		$controller->register_routes();
	}

	/**
	 * Register WordPress settings.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting( 'worldgraph_ai', 'worldgraph_comfy_local_url', [
			'type'              => 'string',
			'default'           => 'http://host.lando.internal:8188',
			'sanitize_callback' => 'esc_url_raw',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_backend', [
			'type'              => 'string',
			'default'           => 'openai_compatible',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_url', [
			'type'              => 'string',
			'default'           => 'http://localhost:11434/v1',
			'sanitize_callback' => 'esc_url_raw',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_api_key', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_model', [
			'type'              => 'string',
			'default'           => 'qwen3.6:35b-a3b-q4_K_M',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_image_url', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_image_api_key', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_image_model', [
			'type'              => 'string',
			'default'           => AI_Image_Client::DEFAULT_MODEL,
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_image_size', [
			'type'              => 'string',
			'default'           => AI_Image_Client::DEFAULT_SIZE,
			'sanitize_callback' => [ __CLASS__, 'sanitize_image_size' ],
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_max_tokens', [
			'type'              => 'integer',
			'default'           => 4096,
			'sanitize_callback' => 'absint',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_temperature', [
			'type'              => 'number',
			'default'           => 0.7,
			'sanitize_callback' => 'floatval',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_fallback_enabled', [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_fallback_backend', [
			'type'              => 'string',
			'default'           => 'openai',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_fallback_api_key', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_rate_limit', [
			'type'              => 'integer',
			'default'           => 10,
			'sanitize_callback' => 'absint',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_cache_ttl', [
			'type'              => 'integer',
			'default'           => 3600,
			'sanitize_callback' => 'absint',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_agent_skills_path', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( 'worldgraph_ai', 'worldgraph_ai_enabled_agents', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );
	}

	/**
	 * Restrict the stored image size to the supported list.
	 *
	 * @param string $value Submitted size.
	 * @return string
	 */
	public static function sanitize_image_size( $value ): string {
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, AI_Image_Client::ALLOWED_SIZES, true ) ? $value : AI_Image_Client::DEFAULT_SIZE;
	}

	/**
	 * Add AI Settings page to admin menu.
	 *
	 * @return void
	 */
	public static function add_settings_page(): void {
		add_submenu_page(
			'worldgraph-administration',
			'World Graph Studio AI Settings',
			'AI Settings',
			'manage_options',
			'worldgraph-ai-settings',
			[ __CLASS__, 'redirect_to_setup_page' ]
		);
		remove_submenu_page( 'worldgraph', 'worldgraph-ai-settings' );
	}

	/**
	 * Redirect legacy AI settings URLs to the single setup page.
	 *
	 * @return void
	 */
	public static function redirect_to_setup_page(): void {
		$url = admin_url( 'admin.php?page=worldgraph-setup' );
		if ( isset( $_GET['required'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag.
			$url = add_query_arg( [ 'required' => '1' ], $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render a link to the single default local ComfyUI Template record,
	 * creating it first if the Setup Wizard hasn't run yet.
	 *
	 * @return void
	 */
	private static function render_default_template_link(): void {
		$posts = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => 'worldgraph_wizard_slot', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'local_comfyui_text_to_image', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );

		if ( $posts ) {
			printf(
				'<a href="%s">%s</a>',
				esc_url( get_edit_post_link( $posts[0]->ID, '' ) ),
				esc_html__( 'Edit the default Template', 'worldgraph' )
			);
			return;
		}

		printf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit.php?post_type=worldgraph_template' ) ),
			esc_html__( 'Manage Templates', 'worldgraph' )
		);
	}

	/**
	 * Render the AI Settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		?>
		<div class="wrap">
			<h1>World Graph Studio AI Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'worldgraph_ai' ); ?>
				<table class="form-table">
					<tr>
					<tr>
						<th scope="row">Local ComfyUI MCP</th>
						<td>
							<label for="worldgraph_comfy_local_url">ComfyUI API URL</label><br />
							<input type="url" name="worldgraph_comfy_local_url" id="worldgraph_comfy_local_url" value="<?php echo esc_attr( get_option( 'worldgraph_comfy_local_url', 'http://host.lando.internal:8188' ) ); ?>" class="regular-text" placeholder="http://host.lando.internal:8188" />
							<p class="description">The address reachable from the WordPress container, not the browser's localhost.</p>
							<p class="description">The checkpoint/model and workflow JSON for this connection are set on its Template, not here &mdash; one Connection can back many checkpoints. <?php self::render_default_template_link(); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_backend">LLM Backend</label></th>
						<td>
							<select name="worldgraph_ai_backend" id="worldgraph_ai_backend">
								<option value="openai_compatible" <?php selected( get_option( 'worldgraph_ai_backend' ), 'openai_compatible' ); ?>>OpenAI-Compatible / Local LLM</option>
								<option value="openai" <?php selected( get_option( 'worldgraph_ai_backend' ), 'openai' ); ?>>OpenAI API</option>
								<option value="anthropic" <?php selected( get_option( 'worldgraph_ai_backend' ), 'anthropic' ); ?>>Anthropic API</option>
								<option value="dual" <?php selected( get_option( 'worldgraph_ai_backend' ), 'dual' ); ?>>Dual (Local + Fallback)</option>
							</select>
							<p class="description">Use OpenAI-compatible for llama.cpp, Ollama, vLLM, LM Studio, OpenRouter, or another compatible BYOK endpoint. Browser-only ChatGPT, Claude, and Claude Code subscriptions are not supported.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_url">OpenAI-Compatible Base URL</label></th>
						<td>
							<input type="url" name="worldgraph_ai_url" id="worldgraph_ai_url" value="<?php echo esc_attr( get_option( 'worldgraph_ai_url' ) ); ?>" class="regular-text" />
							<p class="description">Examples: http://host.lando.internal:11434/v1, http://host.lando.internal:1234/v1, or a compatible hosted endpoint.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_model">Model</label></th>
						<td>
							<input type="text" name="worldgraph_ai_model" id="worldgraph_ai_model" value="<?php echo esc_attr( get_option( 'worldgraph_ai_model' ) ); ?>" class="regular-text" />
							<p class="description">Model name to use (default: qwen3.6:35b-a3b-q4_K_M)</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_api_key">API Key</label></th>
						<td>
							<input type="password" name="worldgraph_ai_api_key" id="worldgraph_ai_api_key" value="<?php echo esc_attr( \WorldGraph\Utils\Credential_Store::masked_value( get_option( 'worldgraph_ai_api_key' ) ) ); ?>" class="regular-text" autocomplete="new-password" />
							<p class="description">Required for hosted providers. A browser subscription without an API key cannot connect to World Graph Studio; local servers may be left blank only when they do not require authentication.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_image_url">Image Base URL</label></th>
						<td>
							<input type="url" name="worldgraph_ai_image_url" id="worldgraph_ai_image_url" value="<?php echo esc_attr( get_option( 'worldgraph_ai_image_url' ) ); ?>" class="regular-text" />
							<p class="description">OpenAI-compatible base URL for `/images/generations`, used by the Generate Asset tools. Leave blank to reuse the LLM base URL.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_image_model">Image Model</label></th>
						<td>
							<input type="text" name="worldgraph_ai_image_model" id="worldgraph_ai_image_model" value="<?php echo esc_attr( get_option( 'worldgraph_ai_image_model' ) ); ?>" class="regular-text" />
							<p class="description">Text-to-image model name (default: <?php echo esc_html( AI_Image_Client::DEFAULT_MODEL ); ?>).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_image_size">Image Size</label></th>
						<td>
							<select name="worldgraph_ai_image_size" id="worldgraph_ai_image_size">
								<?php foreach ( AI_Image_Client::ALLOWED_SIZES as $size ) : ?>
									<option value="<?php echo esc_attr( $size ); ?>" <?php selected( get_option( 'worldgraph_ai_image_size', AI_Image_Client::DEFAULT_SIZE ), $size ); ?>><?php echo esc_html( $size ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Default size for generated story element images.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_image_api_key">Image API Key</label></th>
						<td>
							<input type="password" name="worldgraph_ai_image_api_key" id="worldgraph_ai_image_api_key" value="<?php echo esc_attr( \WorldGraph\Utils\Credential_Store::masked_value( get_option( 'worldgraph_ai_image_api_key' ) ) ); ?>" class="regular-text" autocomplete="new-password" <?php disabled( defined( 'WORLDGRAPH_AI_IMAGE_API_KEY' ) ); ?> />
							<p class="description">Leave blank to reuse the API Key above. The `WORLDGRAPH_AI_IMAGE_API_KEY` constant takes precedence when defined.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_max_tokens">Max Tokens</label></th>
						<td>
							<input type="number" name="worldgraph_ai_max_tokens" id="worldgraph_ai_max_tokens" value="<?php echo esc_attr( get_option( 'worldgraph_ai_max_tokens' ) ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_temperature">Temperature</label></th>
						<td>
							<input type="number" name="worldgraph_ai_temperature" id="worldgraph_ai_temperature" value="<?php echo esc_attr( get_option( 'worldgraph_ai_temperature' ) ); ?>" step="0.1" min="0" max="1" class="small-text" />
							<p class="description">Creativity setting (0.0 = deterministic, 1.0 = creative).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_fallback_enabled">Enable Cloud Fallback</label></th>
						<td>
							<input type="checkbox" name="worldgraph_ai_fallback_enabled" id="worldgraph_ai_fallback_enabled" value="1" <?php checked( get_option( 'worldgraph_ai_fallback_enabled' ), true ); ?> />
							<p class="description">Fall back to cloud LLM if local instance is unavailable.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_fallback_backend">Fallback Backend</label></th>
						<td>
							<select name="worldgraph_ai_fallback_backend" id="worldgraph_ai_fallback_backend">
								<option value="openai" <?php selected( get_option( 'worldgraph_ai_fallback_backend' ), 'openai' ); ?>>OpenAI</option>
								<option value="anthropic" <?php selected( get_option( 'worldgraph_ai_fallback_backend' ), 'anthropic' ); ?>>Anthropic</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_fallback_api_key">Fallback API Key</label></th>
						<td>
							<input type="password" name="worldgraph_ai_fallback_api_key" id="worldgraph_ai_fallback_api_key" value="<?php echo esc_attr( \WorldGraph\Utils\Credential_Store::masked_value( get_option( 'worldgraph_ai_fallback_api_key' ) ) ); ?>" class="regular-text" autocomplete="new-password" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_rate_limit">Rate Limit (requests/min)</label></th>
						<td>
							<input type="number" name="worldgraph_ai_rate_limit" id="worldgraph_ai_rate_limit" value="<?php echo esc_attr( get_option( 'worldgraph_ai_rate_limit' ) ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_cache_ttl">Cache TTL (seconds)</label></th>
						<td>
							<input type="number" name="worldgraph_ai_cache_ttl" id="worldgraph_ai_cache_ttl" value="<?php echo esc_attr( get_option( 'worldgraph_ai_cache_ttl' ) ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_agent_skills_path">Agent Skills Path</label></th>
						<td>
							<input type="text" name="worldgraph_ai_agent_skills_path" id="worldgraph_ai_agent_skills_path" value="<?php echo esc_attr( get_option( 'worldgraph_ai_agent_skills_path' ) ); ?>" class="regular-text" />
							<p class="description">Path to WordPress/agent-skills directory (e.g., /path/to/agent-skills/skills).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_ai_enabled_agents">Enabled Agents</label></th>
						<td>
							<input type="text" name="worldgraph_ai_enabled_agents" id="worldgraph_ai_enabled_agents" value="<?php echo esc_attr( get_option( 'worldgraph_ai_enabled_agents' ) ); ?>" class="regular-text" />
							<p class="description">Comma-separated agent names. Leave blank to enable all agents in the World Graph Studio agents directory.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Enqueue Gutenberg block editor assets.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets(): void {
		$asset_file = include WORLDGRAPH_PLUGIN_DIR . 'assets/ai-editor/js/ai-editor.asset.php';

		wp_enqueue_script(
			'worldgraph-ai-editor',
			WORLDGRAPH_PLUGIN_URL . 'assets/ai-editor/js/ai-editor.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);
		wp_set_script_translations( 'worldgraph-ai-editor', 'worldgraph', WORLDGRAPH_PLUGIN_DIR . 'languages' );

		wp_enqueue_style(
			'worldgraph-ai-editor',
			WORLDGRAPH_PLUGIN_URL . 'assets/ai-editor/css/ai-editor.css',
			[],
			$asset_file['version']
		);

		wp_localize_script( 'worldgraph-ai-editor', 'worldgraphAI', [
			'restUrl'   => rest_url( 'worldgraph/v1' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'backend'   => get_option( 'worldgraph_ai_backend', 'local' ),
			'model'     => get_option( 'worldgraph_ai_model', 'qwen3.6:35b-a3b-q4_K_M' ),
			'maxTokens' => get_option( 'worldgraph_ai_max_tokens', 4096 ),
			'temperature' => get_option( 'worldgraph_ai_temperature', 0.7 ),
		] );
	}

	/**
	 * Enqueue admin assets for AI Settings page.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook ): void {
		if ( 'toplevel_page_worldgraph-ai-settings' === $hook || 'settings_page_worldgraph-ai-settings' === $hook ) {
			wp_enqueue_style( 'wp-components' );
		}

		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::get_story_element_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'worldgraph-ai-workflow',
			WORLDGRAPH_PLUGIN_URL . 'assets/ai-editor/js/shot-workflow.js',
			[],
			WORLDGRAPH_VERSION,
			true
		);

		wp_enqueue_style(
			'worldgraph-ai-workflow',
			WORLDGRAPH_PLUGIN_URL . 'assets/ai-editor/css/shot-workflow.css',
			[],
			WORLDGRAPH_VERSION
		);

		wp_localize_script( 'worldgraph-ai-workflow', 'worldgraphAIWorkflow', [
			'restUrl' => rest_url( 'worldgraph/v1' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'postId'  => get_the_ID(),
		] );
	}

	/**
	 * Get CPTs that represent elements of a World Graph Studio narrative or production.
	 *
	 * Templates and provider connections configure the system; every other
	 * registered World Graph Studio CPT is a story or production element with graph context.
	 *
	 * @return array<int, string>
	 */
	private static function get_story_element_post_types(): array {
		$cpts = array_keys( \WorldGraph\Utils\worldgraph_get_all_cpts() );

		return array_values( array_diff( $cpts, [ 'worldgraph_template', 'worldgraph_conn' ] ) );
	}

	/**
	 * Register the agent workflow in classic story-element editors.
	 *
	 * @param string   $post_type Current post type.
	 * @param \WP_Post $post      Current post.
	 * @return void
	 */
	public static function register_story_element_workflow_metabox( string $post_type, \WP_Post $post ): void {
		if ( ! in_array( $post_type, self::get_story_element_post_types(), true ) ) {
			return;
		}

		$post_type_object = get_post_type_object( $post_type );
		$label            = $post_type_object ? $post_type_object->labels->singular_name : __( 'Story Element', 'worldgraph' );

		add_meta_box(
			'worldgraph_ai_workflow',
			sprintf(
				/* translators: %s: singular Story Graph post-type label. */
				__( 'AI %s Workflow', 'worldgraph' ),
				$label
			),
			[ __CLASS__, 'render_story_element_workflow_metabox' ],
			$post_type,
			'normal',
			'high'
		);
	}

	/**
	 * Render the filmmaking agent chat for a World Graph Studio story element.
	 *
	 * @param \WP_Post $post Current Shot post.
	 * @return void
	 */
	public static function render_story_element_workflow_metabox( \WP_Post $post ): void {
		?>
		<div class="worldgraph-ai-workflow" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<p class="description"><?php esc_html_e( 'Chat with a filmmaking agent using this Story Graph element as context. Conversation history stays in this browser tab, and agent output remains a suggestion.', 'worldgraph' ); ?></p>
			<div class="worldgraph-ai-workflow__controls">
				<label for="worldgraph-ai-workflow-agent-<?php echo esc_attr( $post->ID ); ?>"><?php esc_html_e( 'Agent', 'worldgraph' ); ?></label>
				<select class="worldgraph-ai-workflow__agent" id="worldgraph-ai-workflow-agent-<?php echo esc_attr( $post->ID ); ?>" disabled>
					<option><?php esc_html_e( 'Loading agents...', 'worldgraph' ); ?></option>
				</select>
			</div>
			<div class="worldgraph-ai-workflow__messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Film production chat transcript', 'worldgraph' ); ?>">
				<p class="worldgraph-ai-workflow__empty"><?php esc_html_e( 'Start a conversation about this story element.', 'worldgraph' ); ?></p>
			</div>
			<label for="worldgraph-ai-workflow-prompt-<?php echo esc_attr( $post->ID ); ?>"><?php esc_html_e( 'Message', 'worldgraph' ); ?></label>
			<textarea class="widefat worldgraph-ai-workflow__prompt" id="worldgraph-ai-workflow-prompt-<?php echo esc_attr( $post->ID ); ?>" rows="3" placeholder="<?php esc_attr_e( 'Ask a creative, technical, or production question. Enter sends; Shift+Enter adds a line.', 'worldgraph' ); ?>"></textarea>
			<div class="worldgraph-ai-workflow__actions">
				<button type="button" class="button button-primary worldgraph-ai-workflow__run" data-action="chat"><?php esc_html_e( 'Send', 'worldgraph' ); ?></button>
				<button type="button" class="button worldgraph-ai-workflow__run" data-action="analyze"><?php esc_html_e( 'Analyze element', 'worldgraph' ); ?></button>
				<button type="button" class="button worldgraph-ai-workflow__run" data-action="continuity"><?php esc_html_e( 'Check continuity', 'worldgraph' ); ?></button>
				<button type="button" class="button-link worldgraph-ai-workflow__clear"><?php esc_html_e( 'Clear chat', 'worldgraph' ); ?></button>
			</div>
			<div class="worldgraph-ai-workflow__status" role="status" aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * Add AI-related data to World Graph Studio REST context.
	 *
	 * @param array  $context Existing context.
	 * @param string $post_type Post type being queried.
	 * @return array Modified context.
	 */
	public static function add_ai_context( array $context, string $post_type ): array {
		$context['ai_enabled'] = true;
		$context['ai_backend'] = get_option( 'worldgraph_ai_backend', 'local' );
		return $context;
	}
}
