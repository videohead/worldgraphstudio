<?php
/**
 * Template Workflow Test meta box.
 *
 * Lets an administrator run a throwaway, prompt-driven generation against a
 * Template's own capabilities — without first authoring a Story Graph element —
 * so the Template can be judged before it is used in production. The run goes
 * through the same `worldgraph/v1/generation` contract the rest of the product
 * uses, so a completed test returns a real Asset number.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

use WorldGraph\Utils\Connection_Repository;
use WorldGraph\Utils\Comfy_Manifest;
use WorldGraph\Utils\Generation_Modality;
use WorldGraph\Utils\Generation_Prompt_Profiles;
use WorldGraph\Utils\Template_Run_Controls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template workflow test meta box.
 */
class Template_Workflow_Test {

	/** Post type this meta box is attached to. */
	private const POST_TYPE = 'worldgraph_template';

	/** Register the meta box and its assets. */
	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	/** Add the meta box to the Template edit screen. */
	public static function register(): void {
		add_meta_box(
			'worldgraph_template_workflow_test',
			__( 'Template Workflow Test', 'worldgraph' ),
			[ __CLASS__, 'render' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Enqueue the test runner on the Template edit screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$post_id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style(
			'worldgraph-template-workflow-test',
			WORLDGRAPH_PLUGIN_URL . 'assets/css/template-workflow-test.css',
			[],
			self::asset_version( 'assets/css/template-workflow-test.css' )
		);
		wp_enqueue_script(
			'worldgraph-template-workflow-test',
			WORLDGRAPH_PLUGIN_URL . 'assets/js/template-workflow-test.js',
			[],
			self::asset_version( 'assets/js/template-workflow-test.js' ),
			true
		);

		$capability = self::capability( $post_id );
		wp_localize_script( 'worldgraph-template-workflow-test', 'worldgraphTemplateWorkflowTest', [
			'templateId'      => $post_id,
			'generationUrl'   => rest_url( 'worldgraph/v1/generation' ),
			'chatUrl'         => rest_url( 'worldgraph/v1/ai/chat' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'pollIntervalMs'  => 6000,
			'pollTimeoutMs'   => 900000,
			'capability'      => $capability,
			'assetEditUrlBase' => admin_url( 'post.php?action=edit&post=' ),
			'i18n'            => [
				'queueing'            => __( 'Queueing test run…', 'worldgraph' ),
				'queued'              => __( 'Queued. Waiting for the provider…', 'worldgraph' ),
				'running'             => __( 'Running…', 'worldgraph' ),
				'importing'           => __( 'Importing generated media…', 'worldgraph' ),
				'completed'           => __( 'Test run completed.', 'worldgraph' ),
				'failed'              => __( 'Test run failed.', 'worldgraph' ),
				'cancelled'           => __( 'Test run cancelled.', 'worldgraph' ),
				'timedOut'            => __( 'Still running. Reopen this Template later to see the result.', 'worldgraph' ),
				'promptMissing'       => __( 'Enter a prompt before running the test.', 'worldgraph' ),
				'mediaMissing'        => __( 'This Template needs a reference file for every required media input.', 'worldgraph' ),
				'assetNumber'         => __( 'Asset', 'worldgraph' ),
				'attachment'          => __( 'Attachment', 'worldgraph' ),
				'openAsset'           => __( 'Open Asset', 'worldgraph' ),
				'openJob'             => __( 'Open Job record', 'worldgraph' ),
				'openMedia'           => __( 'Open media file', 'worldgraph' ),
				'selectMedia'         => __( 'Select', 'worldgraph' ),
				'clearMedia'          => __( 'Clear', 'worldgraph' ),
				'chooseMedia'         => __( 'Choose a reference file', 'worldgraph' ),
				'useMedia'            => __( 'Use this file', 'worldgraph' ),
				'noMedia'             => __( 'No file selected.', 'worldgraph' ),
				'runSettings'         => __( 'Run settings', 'worldgraph' ),
				'runSettingsHelp'     => __( 'Only settings you change here are sent. Untouched settings keep the values saved in the workflow.', 'worldgraph' ),
				'promptGuidance'      => __( 'Prompt guidance', 'worldgraph' ),
				'negativeGuidance'    => __( 'Negative prompt note', 'worldgraph' ),
				'fixedSelections'     => __( 'Workflow model selections', 'worldgraph' ),
				'fixedSelectionsHelp' => __( 'The saved workflow asks ComfyUI to load these exact files. Install each file in the shown models folder.', 'worldgraph' ),
				'thinking'            => __( 'Thinking…', 'worldgraph' ),
				'chatError'           => __( 'The prompt assistant could not answer.', 'worldgraph' ),
				'usePrompt'           => __( 'Use as prompt', 'worldgraph' ),
				'you'                 => __( 'You', 'worldgraph' ),
				'assistant'           => __( 'Prompt assistant', 'worldgraph' ),
				'requestFailed'       => __( 'The request could not be completed.', 'worldgraph' ),
			],
		] );
	}

	/** Use the changed file timestamp so revised controls cannot remain cached. */
	private static function asset_version( string $relative_path ): string {
		$path = WORLDGRAPH_PLUGIN_DIR . ltrim( $relative_path, '/' );
		return is_file( $path ) ? (string) filemtime( $path ) : WORLDGRAPH_VERSION;
	}

	/**
	 * Describe what this Template can be asked to produce, and whether a test
	 * run can reach a provider at all.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string, mixed>
	 */
	private static function capability( int $template_id ): array {
		$modality             = Generation_Modality::sanitize( (string) \WorldGraph\Utils\worldgraph_get_field_value( $template_id, 'modality' ) );
		$definition           = Generation_Modality::get( $modality );
		$inputs               = Generation_Modality::inputs( $modality );
		$run_controls         = Template_Run_Controls::describe( $template_id );
		$has_negative_control = self::has_negative_prompt_control( $run_controls );

		$slots = [];
		foreach ( $inputs as $slot => $input ) {
			// Negative conditioning is a Template run control when the saved
			// workflow exposes it. Do not render a second, competing text slot.
			if ( 'negative_prompt' === (string) $slot && $has_negative_control ) {
				continue;
			}
			$slots[] = [
				'slot'     => (string) $slot,
				'label'    => (string) ( $input['label'] ?? $slot ),
				'type'     => in_array( $slot, Generation_Modality::MEDIA_SLOTS, true ) ? 'media' : 'text',
				'required' => ! empty( $input['required'] ),
			];
		}

		return [
			'modality'        => $modality,
			'label'           => (string) ( $definition['label'] ?? $modality ),
			'description'     => (string) ( $definition['description'] ?? '' ),
			'outputType'      => Generation_Modality::output_type( $modality ),
			'inputs'          => $slots,
			'runControls'     => $run_controls,
			'promptProfile'   => Generation_Prompt_Profiles::for_template( $template_id ),
			'fixedSelections' => self::fixed_selections( $template_id ),
			'blockers'        => self::blockers( $template_id ),
		];
	}

	/** Whether the safe run-control description owns negative conditioning. */
	private static function has_negative_prompt_control( array $description ): bool {
		foreach ( (array) ( $description['fields'] ?? [] ) as $field ) {
			$key = strtolower( str_replace( '-', '_', (string) ( is_array( $field ) ? ( $field['key'] ?? '' ) : '' ) ) );
			if ( in_array( $key, [ 'negative_prompt', 'negative_text' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Exact model-loader choices baked into a local ComfyUI workflow.
	 *
	 * The manifest is server-derived; only bounded plain scalar metadata is
	 * projected into the browser. Workflow JSON and loader node IDs stay private.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function fixed_selections( int $template_id ): array {
		$provider = sanitize_key( (string) \WorldGraph\Utils\worldgraph_get_field_value( $template_id, 'provider_type' ) );
		if ( 'comfyui' !== $provider ) {
			return [];
		}

		$manifest = Comfy_Manifest::for_template( $template_id );
		if ( is_wp_error( $manifest ) ) {
			return [];
		}

		$selections = [];
		foreach ( array_slice( (array) ( $manifest['models'] ?? [] ), 0, 64 ) as $model ) {
			if ( ! is_array( $model ) ) {
				continue;
			}
			$selection = [
				'filename'  => self::fixed_selection_text( $model['filename'] ?? '', 255 ),
				'folder'    => self::fixed_selection_text( $model['folder'] ?? '', 80 ),
				'nodeClass' => self::fixed_selection_text( $model['node_class'] ?? '', 120 ),
				'field'     => self::fixed_selection_text( $model['field'] ?? '', 80 ),
			];
			if ( '' !== $selection['filename'] && '' !== $selection['nodeClass'] && '' !== $selection['field'] ) {
				$selections[] = $selection;
			}
		}

		return $selections;
	}

	/**
	 * Sanitize and bound one model-loader label sent to the browser.
	 *
	 * @param mixed $value   Raw manifest value.
	 * @param int   $maximum Maximum character count.
	 * @return string
	 */
	private static function fixed_selection_text( $value, int $maximum ): string {
		$value = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $maximum, 'UTF-8' ) : substr( $value, 0, $maximum );
	}

	/**
	 * Reasons a test run would be rejected before it reaches a provider.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<int, string>
	 */
	private static function blockers( int $template_id ): array {
		$blockers = [];

		if ( 'publish' !== get_post_status( $template_id ) ) {
			$blockers[] = __( 'Publish this Template before running a test.', 'worldgraph' );
		}
		if ( 'active' !== (string) \WorldGraph\Utils\worldgraph_get_field_value( $template_id, 'status' ) ) {
			$blockers[] = __( 'Set this Template’s status to Active before running a test.', 'worldgraph' );
		}

		$connection_id     = absint( \WorldGraph\Utils\worldgraph_get_field_value( $template_id, 'connection_id' ) );
		$connection        = Connection_Repository::get( $connection_id );
		$template_provider = sanitize_key( (string) \WorldGraph\Utils\worldgraph_get_field_value( $template_id, 'provider_type' ) );
		if ( ! $connection ) {
			$blockers[] = __( 'Select a Connection for this Template.', 'worldgraph' );
		} elseif ( 'disabled' === $connection['status'] ) {
			$blockers[] = __( 'The selected Connection is disabled.', 'worldgraph' );
		} elseif ( '' === $template_provider || $template_provider !== $connection['provider_type'] ) {
			$blockers[] = __( 'This Template and its Connection must use the same provider.', 'worldgraph' );
		}

		$provider_template_id = (string) ( \WorldGraph\Utils\worldgraph_get_field_value( $template_id, 'provider_template_id' ) ?: get_post_meta( $template_id, 'comfy_template_id', true ) );
		if ( '' === trim( $provider_template_id ) && ( ! $connection || 'fal' !== $connection['provider_type'] || '' === trim( (string) ( $connection['model'] ?? '' ) ) ) ) {
			$blockers[] = __( 'Select a provider Template (or provider model) for this Template.', 'worldgraph' );
		}

		return $blockers;
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Current Template post.
	 */
	public static function render( \WP_Post $post ): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			echo '<p>' . esc_html__( 'You need media upload permissions to run a Template test.', 'worldgraph' ) . '</p>';
			return;
		}
		if ( ! $post->ID || 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save this Template before running a workflow test.', 'worldgraph' ) . '</p>';
			return;
		}
		?>
		<div class="worldgraph-template-test" id="worldgraph-template-test">
			<p class="worldgraph-template-test__intro">
				<?php echo esc_html__( 'Run a throwaway generation against this Template to judge its output. A completed run creates a real Asset and reports its number.', 'worldgraph' ); ?>
			</p>
			<div class="worldgraph-template-test__capability" id="worldgraph-template-test-capability"></div>
			<div class="worldgraph-template-test__columns">
				<div class="worldgraph-template-test__run">
					<h3><?php echo esc_html__( 'Test run', 'worldgraph' ); ?></h3>
					<div id="worldgraph-template-test-fields"></div>
					<p>
						<button type="button" class="button button-primary" id="worldgraph-template-test-run"><?php echo esc_html__( 'Run test', 'worldgraph' ); ?></button>
					</p>
					<div class="worldgraph-template-test__status" id="worldgraph-template-test-status" aria-live="polite"></div>
					<div class="worldgraph-template-test__result" id="worldgraph-template-test-result"></div>
				</div>
				<div class="worldgraph-template-test__chat">
					<h3><?php echo esc_html__( 'Prompt assistant', 'worldgraph' ); ?></h3>
					<p class="description"><?php echo esc_html__( 'Ask for a stronger prompt for this Template’s modality, then send it straight to the test run.', 'worldgraph' ); ?></p>
					<div class="worldgraph-template-test__log" id="worldgraph-template-test-chat-log" aria-live="polite"></div>
					<p>
						<label class="screen-reader-text" for="worldgraph-template-test-chat-input"><?php echo esc_html__( 'Message the prompt assistant', 'worldgraph' ); ?></label>
						<textarea id="worldgraph-template-test-chat-input" class="large-text" rows="3" placeholder="<?php echo esc_attr__( 'e.g. Make this prompt more cinematic and add lighting direction.', 'worldgraph' ); ?>"></textarea>
					</p>
					<p><button type="button" class="button" id="worldgraph-template-test-chat-send"><?php echo esc_html__( 'Send', 'worldgraph' ); ?></button></p>
				</div>
			</div>
		</div>
		<?php
	}
}
