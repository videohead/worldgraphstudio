<?php
/**
 * The World Graph Studio Assets meta box: asset guidance plus the image/video
 * generation tools for a story element.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

use WorldGraph\Utils\Comfy_Bootstrap;
use WorldGraph\Utils\Connection_Adapters;
use WorldGraph\Utils\Connection_Repository;
use WorldGraph\Utils\Local_ComfyUI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate-asset meta box.
 */
class Asset_Generator_MetaBox {

	/**
	 * CPTs that can have a featured story asset generated or selected.
	 *
	 * @var array<int, string>
	 */
	private const ASSET_CPTS = [
		'worldgraph_project',
		'worldgraph_world',
		'worldgraph_character',
		'worldgraph_location',
		'worldgraph_prop',
		'worldgraph_org',
		'worldgraph_episode',
		'worldgraph_scene',
		'worldgraph_shot',
		'worldgraph_sound',
		'worldgraph_asset',
		'worldgraph_editorial',
	];

	/**
	 * Register the meta box and its assets.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	/**
	 * Add the meta box to every supported CPT.
	 */
	public static function register(): void {
		foreach ( self::ASSET_CPTS as $cpt ) {
			add_meta_box(
				'worldgraph_assets',
				__( 'World Graph Studio Assets', 'worldgraph' ),
				[ __CLASS__, 'render' ],
				$cpt,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Enqueue the generator styles and script on supported edit screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::ASSET_CPTS, true ) ) {
			return;
		}

		wp_enqueue_style(
			'worldgraph-asset-generator',
			WORLDGRAPH_PLUGIN_URL . 'assets/css/asset-generator.css',
			[],
			self::asset_version( 'assets/css/asset-generator.css' )
		);

		wp_enqueue_script(
			'worldgraph-asset-generator',
			WORLDGRAPH_PLUGIN_URL . 'assets/js/asset-generator.js',
			[],
			self::asset_version( 'assets/js/asset-generator.js' ),
			true
		);

		wp_localize_script( 'worldgraph-asset-generator', 'worldgraphAssetGenerator', [
			'restUrl'        => rest_url( 'worldgraph/v1/assets/generate' ),
			'generationRestUrl' => rest_url( 'worldgraph/v1/generation' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'pollIntervalMs' => 15000,
			'i18n'           => [
				'imageSelection'    => __( 'Image to create', 'worldgraph' ),
				'sequenceSelection' => __( 'Sequence to create', 'worldgraph' ),
				'videoSelection'    => __( 'Video to create', 'worldgraph' ),
				'audioSelection'    => __( 'Audio to create', 'worldgraph' ),
				'demonstrationSelection' => __( 'Demonstration to create', 'worldgraph' ),
				'notAvailable'      => __( 'Not defined for this item', 'worldgraph' ),
				'stillImage'        => __( 'still image', 'worldgraph' ),
				'video'             => __( 'video', 'worldgraph' ),
				'outputs'           => __( 'outputs', 'worldgraph' ),
				'createImage'       => __( 'Create image:', 'worldgraph' ),
				'createVideo'       => __( 'Create video:', 'worldgraph' ),
				'createAudio'       => __( 'Create audio:', 'worldgraph' ),
				'reviewQueue'       => __( 'Review and queue', 'worldgraph' ),
				'reviewProject'     => __( 'Review and queue all Project media', 'worldgraph' ),
				'reviewDemonstration' => __( 'Review and generate demonstration video', 'worldgraph' ),
				'chooseImage'       => __( 'Choose an image Template…', 'worldgraph' ),
				'chooseVideo'       => __( 'Choose a video Template…', 'worldgraph' ),
				'chooseAudio'       => __( 'Choose an audio Template…', 'worldgraph' ),
				'configuredPerItem' => __( 'Use each output’s configured Template', 'worldgraph' ),
				'singleTemplateHelp' => __( 'This Template will be used only for the selected output.', 'worldgraph' ),
				'batchTemplateHelp' => __( 'Choose a Template to override every matching output, or keep each output’s configured Template.', 'worldgraph' ),
				'imageRunControls'  => __( 'Image Template controls', 'worldgraph' ),
				'videoRunControls'  => __( 'Video Template controls', 'worldgraph' ),
				'audioRunControls'  => __( 'Audio Template controls', 'worldgraph' ),
				'conditioningGroup' => __( 'Conditioning', 'worldgraph' ),
				'samplingGroup'     => __( 'Sampling', 'worldgraph' ),
				'outputGroup'       => __( 'Output', 'worldgraph' ),
				'advancedGroup'     => __( 'Advanced', 'worldgraph' ),
				'useTemplateDefault' => __( 'Use Template default', 'worldgraph' ),
				'enabled'           => __( 'Enabled', 'worldgraph' ),
				'disabled'          => __( 'Disabled', 'worldgraph' ),
				'singlePromptHelp'  => __( 'These one-off instructions will be added to this output’s generated prompt.', 'worldgraph' ),
				'batchPromptHelp'   => __( 'These one-off instructions will be added to every generated prompt in this workflow.', 'worldgraph' ),
				'workflowPrompts'   => __( 'This workflow composes a separate detailed prompt for every output:', 'worldgraph' ),
				'moreOutputs'       => __( 'more outputs', 'worldgraph' ),
				'allProjectMedia'   => __( 'All Project representative media', 'worldgraph' ),
				'wholeStoryDemo'    => __( 'Whole-story demonstration video', 'worldgraph' ),
				'sources'           => __( 'Story Graph items', 'worldgraph' ),
				'singleChoiceHelp'  => __( 'Choose the exact output and Template. The prompt preview below updates with your choice.', 'worldgraph' ),
				'itemChoiceHelp'    => __( 'Queue every output in this item’s defined sequence as one tracked background batch.', 'worldgraph' ),
				'projectChoiceHelp' => __( 'Queue representative frames and videos for the Project and its owned Story Graph items. Review the plan before starting.', 'worldgraph' ),
				'demoChoiceHelp'    => __( 'Generate an ordered first pass of the complete story, reuse character and Shot stills for video conditioning, add available audio, then assemble a watchable H.264 rough cut with subtitles and still-frame fallbacks.', 'worldgraph' ),
				'missingTemplates'  => __( 'required outputs have no runnable Template.', 'worldgraph' ),
				'generatingImage'   => __( 'Queueing image…', 'worldgraph' ),
				'generatingVideo'   => __( 'Queueing video…', 'worldgraph' ),
				'generatingAudio'   => __( 'Queueing audio…', 'worldgraph' ),
				'queuedImage'       => __( 'Image generation queued. The background worker will import the completed media.', 'worldgraph' ),
				'queuedVideo'       => __( 'Video generation queued. The background worker will import the completed media.', 'worldgraph' ),
				'queuedAudio'       => __( 'Audio generation queued. The background worker will import the completed media.', 'worldgraph' ),
				'job'               => __( 'Job', 'worldgraph' ),
				'jobSingular'       => __( 'job', 'worldgraph' ),
				'image'             => __( 'image', 'worldgraph' ),
				'loading'           => __( 'Building generation context from saved Story Graph fields…', 'worldgraph' ),
				'planning'          => __( 'Planning representative media…', 'worldgraph' ),
				'starting'          => __( 'Freezing the plan and starting its background batch…', 'worldgraph' ),
				'workflow'          => __( 'Default workflow', 'worldgraph' ),
				'jobs'              => __( 'jobs', 'worldgraph' ),
				'images'            => __( 'images', 'worldgraph' ),
				'videos'            => __( 'videos', 'worldgraph' ),
				'audio'             => __( 'audio cue', 'worldgraph' ),
				'audios'            => __( 'audio cues', 'worldgraph' ),
				'confirmItem'       => __( 'Queue this item’s complete representative-media set? Provider charges may apply.', 'worldgraph' ),
				'confirmProject'    => __( 'Queue all representative frames and videos for this Project? This can incur substantial provider charges and run for hours or days.', 'worldgraph' ),
				'confirmDemonstration' => __( 'Generate the complete story demonstration and assemble it when ready? Provider charges may apply, and this can run for hours or days. Missing optional motion or audio will fall back to stills, subtitles, and silence.', 'worldgraph' ),
				'batchQueued'       => __( 'Generation batch queued.', 'worldgraph' ),
				'batchProgress'     => __( 'Batch progress', 'worldgraph' ),
				'roughCutProgress'  => __( 'Rough-cut assembly', 'worldgraph' ),
				'roughCutReady'     => __( 'Rough-cut demonstration ready.', 'worldgraph' ),
				'cancelBatch'       => __( 'Stop work that has not reached a provider?', 'worldgraph' ),
				'cancelled'         => __( 'Not-yet-dispatched work was stopped. Already-dispatched jobs will finish and import.', 'worldgraph' ),
				'done'              => __( 'Image generated and attached.', 'worldgraph' ),
				'doneVideo'         => __( 'Video generated and attached.', 'worldgraph' ),
				'doneAudio'         => __( 'Audio generated and attached.', 'worldgraph' ),
				'featured'          => __( 'Set as the featured asset.', 'worldgraph' ),
				'assetCreated'      => __( 'Linked Asset record created.', 'worldgraph' ),
				'reloadHint'        => __( 'Reload the editor to see completed media in the featured asset and gallery fields.', 'worldgraph' ),
				'error'             => __( 'Media generation failed.', 'worldgraph' ),
				'unconfiguredImage' => __( 'No runnable image Template is configured. Configure an active text-to-image Template and Connection first.', 'worldgraph' ),
				'unconfiguredVideo' => __( 'No runnable video Template is configured. Configure an active text-to-video Template and Connection first.', 'worldgraph' ),
				'unconfiguredAudio' => __( 'No runnable audio Template is configured. Configure an active text-to-speech, text-to-music, or other audio Template and Connection first.', 'worldgraph' ),
			],
		] );
	}

	/** Use the changed file timestamp so revised controls cannot remain cached. */
	private static function asset_version( string $relative_path ): string {
		$path = WORLDGRAPH_PLUGIN_DIR . ltrim( $relative_path, '/' );
		return is_file( $path ) ? (string) filemtime( $path ) : WORLDGRAPH_VERSION;
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render( \WP_Post $post ): void {
		?>
	    <?php
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		self::render_readiness();
		self::render_generator( $post );
	}

	/**
	 * Warn when the configured local ComfyUI cannot run a generation yet, and
	 * point at the screen that resolves it.
	 */
	private static function render_readiness(): void {
		if ( empty( Connection_Repository::get_all( [ 'provider_type' => 'comfyui' ] ) ) || ! Connection_Adapters::load( 'comfyui' ) ) {
			return;
		}
		if ( ! Local_ComfyUI::is_configured() ) {
			return;
		}

		$status = Comfy_Bootstrap::status();
		if ( ! empty( $status['ready'] ) ) {
			return;
		}

		Comfy_Readiness::render_notice( $status );
	}

	/**
	 * Render the guided single-output and representative-workflow controls.
	 *
	 * @param \WP_Post $post Current post.
	 */
	private static function render_generator( \WP_Post $post ): void {
		?>
		<div class="worldgraph-generate-asset" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-is-project="<?php echo esc_attr( 'worldgraph_project' === $post->post_type ? '1' : '0' ); ?>">
			<h4><?php esc_html_e( 'Generate representative media', 'worldgraph' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Every request automatically uses the saved title, body, relevant SCF fields, inherited Project/World context, and Generation Prompt Instructions. Save or update this post before queueing so the latest details are included.', 'worldgraph' ); ?></p>
			<fieldset class="worldgraph-generate-asset__modes">
				<legend><strong><?php esc_html_e( 'Choose a generation type', 'worldgraph' ); ?></strong></legend>
				<div class="worldgraph-generate-asset__mode-list">
					<label class="worldgraph-generate-asset__mode">
						<input type="radio" name="worldgraph-generation-mode-<?php echo esc_attr( $post->ID ); ?>" value="image" disabled />
						<span><strong><?php esc_html_e( 'Image', 'worldgraph' ); ?></strong><small><?php esc_html_e( 'Create one selected still image', 'worldgraph' ); ?></small></span>
					</label>
					<label class="worldgraph-generate-asset__mode">
						<input type="radio" name="worldgraph-generation-mode-<?php echo esc_attr( $post->ID ); ?>" value="sequence" disabled />
						<span><strong><?php esc_html_e( 'Sequence', 'worldgraph' ); ?></strong><small><?php esc_html_e( 'Create every image or video in a defined set', 'worldgraph' ); ?></small></span>
					</label>
					<label class="worldgraph-generate-asset__mode">
						<input type="radio" name="worldgraph-generation-mode-<?php echo esc_attr( $post->ID ); ?>" value="video" disabled />
						<span><strong><?php esc_html_e( 'Video', 'worldgraph' ); ?></strong><small><?php esc_html_e( 'Create one selected moving shot', 'worldgraph' ); ?></small></span>
					</label>
					<label class="worldgraph-generate-asset__mode">
						<input type="radio" name="worldgraph-generation-mode-<?php echo esc_attr( $post->ID ); ?>" value="audio" disabled />
						<span><strong><?php esc_html_e( 'Audio', 'worldgraph' ); ?></strong><small><?php esc_html_e( 'Create one selected speech, music, or sound-effect cue', 'worldgraph' ); ?></small></span>
					</label>
					<label class="worldgraph-generate-asset__mode">
						<input type="radio" name="worldgraph-generation-mode-<?php echo esc_attr( $post->ID ); ?>" value="demonstration" disabled />
						<span><strong><?php esc_html_e( 'Demonstration', 'worldgraph' ); ?></strong><small><?php esc_html_e( 'Generate and stitch the complete Project story', 'worldgraph' ); ?></small></span>
					</label>
				</div>
			</fieldset>
			<div class="worldgraph-generate-asset__selection">
				<label class="worldgraph-generate-asset__selection-label" for="worldgraph-generate-asset-action-<?php echo esc_attr( $post->ID ); ?>"><strong><?php esc_html_e( 'Output to create', 'worldgraph' ); ?></strong></label>
				<select class="widefat worldgraph-generate-asset__action-select" id="worldgraph-generate-asset-action-<?php echo esc_attr( $post->ID ); ?>" disabled>
					<option><?php esc_html_e( 'Loading available options…', 'worldgraph' ); ?></option>
				</select>
				<p class="description worldgraph-generate-asset__choice-description"></p>
			</div>
			<div class="worldgraph-generate-asset__workflow" aria-live="polite"></div>
			<div class="worldgraph-generate-asset__template-options">
				<div class="worldgraph-generate-asset__template-option worldgraph-generate-asset__image-template-option" hidden>
					<label for="worldgraph-generate-asset-template-<?php echo esc_attr( $post->ID ); ?>"><strong><?php esc_html_e( 'Image Template', 'worldgraph' ); ?></strong></label>
					<select class="widefat worldgraph-generate-asset__template" id="worldgraph-generate-asset-template-<?php echo esc_attr( $post->ID ); ?>"></select>
					<p class="description worldgraph-generate-asset__image-template-help"></p>
				</div>
				<div class="worldgraph-generate-asset__template-option worldgraph-generate-asset__video-template-option" hidden>
					<label for="worldgraph-generate-asset-video-template-<?php echo esc_attr( $post->ID ); ?>"><strong><?php esc_html_e( 'Video Template', 'worldgraph' ); ?></strong></label>
					<select class="widefat worldgraph-generate-asset__video-template" id="worldgraph-generate-asset-video-template-<?php echo esc_attr( $post->ID ); ?>"></select>
					<p class="description worldgraph-generate-asset__video-template-help"></p>
				</div>
				<div class="worldgraph-generate-asset__template-option worldgraph-generate-asset__audio-template-option" hidden>
					<label for="worldgraph-generate-asset-audio-template-<?php echo esc_attr( $post->ID ); ?>"><strong><?php esc_html_e( 'Audio Template', 'worldgraph' ); ?></strong></label>
					<select class="widefat worldgraph-generate-asset__audio-template" id="worldgraph-generate-asset-audio-template-<?php echo esc_attr( $post->ID ); ?>"></select>
					<p class="description worldgraph-generate-asset__audio-template-help"></p>
				</div>
			</div>
			<details class="worldgraph-generate-asset__run-controls" hidden>
				<summary><strong><?php esc_html_e( 'Run controls (optional)', 'worldgraph' ); ?></strong></summary>
				<p class="description"><?php esc_html_e( 'Output framing defaults come from the Project. Sampling and negative-prompt defaults come from the selected Template. Changes here apply only to this run.', 'worldgraph' ); ?></p>
				<div class="worldgraph-generate-asset__run-control-panels"></div>
			</details>
			<fieldset class="worldgraph-generate-asset__direct-options" hidden>
				<legend class="screen-reader-text"><?php esc_html_e( 'Options for this output', 'worldgraph' ); ?></legend>
				<label class="worldgraph-generate-asset__featured-option"><input type="checkbox" class="worldgraph-generate-asset__featured" checked /> <?php esc_html_e( 'Set this image as the featured asset', 'worldgraph' ); ?></label>
				<label><input type="checkbox" class="worldgraph-generate-asset__create" checked /> <?php esc_html_e( 'Create a linked Asset record', 'worldgraph' ); ?></label>
			</fieldset>
			<label for="worldgraph-generate-asset-prompt-<?php echo esc_attr( $post->ID ); ?>"><?php esc_html_e( 'Additional instructions for this run (optional)', 'worldgraph' ); ?></label>
			<textarea class="widefat worldgraph-generate-asset__prompt" id="worldgraph-generate-asset-prompt-<?php echo esc_attr( $post->ID ); ?>" rows="4" placeholder="<?php esc_attr_e( 'For example: no watermark; slow camera push-in; preserve the established wardrobe.', 'worldgraph' ); ?>"></textarea>
			<p class="description worldgraph-generate-asset__prompt-help"><?php esc_html_e( 'Enter only one-off directions here. They are appended to the saved Story Graph context; they never replace it. Put reusable directions in the Generation Prompt Instructions SCF field.', 'worldgraph' ); ?></p>
			<details class="worldgraph-generate-asset__context">
				<summary><?php esc_html_e( 'Review the generated prompt or workflow plan', 'worldgraph' ); ?></summary>
				<pre class="worldgraph-generate-asset__context-preview"></pre>
				<button type="button" class="button-link worldgraph-generate-asset__refresh-context"><?php esc_html_e( 'Refresh from saved fields', 'worldgraph' ); ?></button>
			</details>
			<div class="worldgraph-generate-asset__actions">
				<button type="button" class="button button-primary worldgraph-generate-asset__run" disabled><?php esc_html_e( 'Choose what to create', 'worldgraph' ); ?></button>
				<button type="button" class="button-link-delete worldgraph-generate-asset__cancel" hidden><?php esc_html_e( 'Stop pending work', 'worldgraph' ); ?></button>
			</div>
			<div class="worldgraph-generate-asset__status" role="status" aria-live="polite"></div>
			<div class="worldgraph-generate-asset__progress" hidden>
				<progress max="100" value="0" aria-label="<?php esc_attr_e( 'Representative media generation progress', 'worldgraph' ); ?>"></progress>
				<span></span>
			</div>
			<div class="worldgraph-generate-asset__result" hidden></div>
		</div>
		<?php
	}
}
