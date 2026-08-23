<?php
/**
 * Film dramaturgy admin tool.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

use WorldGraph\AI\AI_Context_Builder;
use WorldGraph\AI\AI_LLM_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides evidence-based dramaturgical analysis for Story Graph content.
 */
class Dramaturgy_Tool {
	/**
	 * Initialize the tool.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_worldgraph_run_dramaturgy', [ __CLASS__, 'ajax_run' ] );
		add_action( 'wp_ajax_worldgraph_save_dramaturgy', [ __CLASS__, 'ajax_save' ] );
	}

	/**
	 * Enqueue assets only on the Dramaturgy page.
	 *
	 * @param string $hook Current admin hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		if ( 'worldgraph-dramaturgy' !== $page ) {
			return;
		}

		wp_enqueue_style( 'worldgraph-dramaturgy-tool', WORLDGRAPH_PLUGIN_URL . 'assets/css/dramaturgy-tool.css', [], WORLDGRAPH_VERSION );
		wp_enqueue_script( 'worldgraph-dramaturgy-tool', WORLDGRAPH_PLUGIN_URL . 'assets/js/dramaturgy-tool.js', [], WORLDGRAPH_VERSION, true );
		wp_localize_script( 'worldgraph-dramaturgy-tool', 'worldgraphDramaturgyTool', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'worldgraph_dramaturgy_tool' ),
			'strings' => [
				'running' => __( 'Reading the story through this dramaturgical lens...', 'worldgraph' ),
				'ready'   => __( 'Dramaturgical reading ready to review.', 'worldgraph' ),
				'saving'  => __( 'Saving dramaturgical reading...', 'worldgraph' ),
				'saved'   => __( 'Dramaturgical reading saved.', 'worldgraph' ),
				'error'   => __( 'Something went wrong. Please try again.', 'worldgraph' ),
			],
		] );
	}

	/**
	 * Render the dramaturgy page.
	 */
	public static function render_page(): void {
		$sources = [];
		$source_types = [
			'worldgraph_project' => __( 'Project', 'worldgraph' ),
			'worldgraph_episode' => __( 'Episode', 'worldgraph' ),
			'worldgraph_scene'   => __( 'Scene', 'worldgraph' ),
		];
		foreach ( $source_types as $post_type => $label ) {
			$posts = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			] );
			foreach ( $posts as $post ) {
				$sources[] = [
					'id'    => $post->ID,
					'label' => $label,
					'title' => $post->post_title ?: __( '(Untitled)', 'worldgraph' ),
				];
			}
		}
		?>
		<div class="wrap worldgraph-dramaturgy-page">
			<h1><?php esc_html_e( 'Film Dramaturgy', 'worldgraph' ); ?></h1>
			<p class="worldgraph-dramaturgy-intro"><?php esc_html_e( 'Use dramaturgy as a practical, iterative reading of how story, form, time, and audience experience work together on screen.', 'worldgraph' ); ?></p>
			<div class="worldgraph-dramaturgy-layout">
				<section class="worldgraph-dramaturgy-panel worldgraph-dramaturgy-controls" aria-labelledby="worldgraph-dramaturgy-controls-title">
					<h2 id="worldgraph-dramaturgy-controls-title"><?php esc_html_e( 'Dramaturgical reading', 'worldgraph' ); ?></h2>
					<label for="worldgraph-dramaturgy-source"><?php esc_html_e( 'Story source', 'worldgraph' ); ?></label>
					<select id="worldgraph-dramaturgy-source" class="widefat" <?php disabled( empty( $sources ) ); ?>>
						<?php if ( empty( $sources ) ) : ?>
							<option><?php esc_html_e( 'No story content found', 'worldgraph' ); ?></option>
						<?php else : ?>
							<?php foreach ( $sources as $source ) : ?>
								<option value="<?php echo esc_attr( $source['id'] ); ?>"><?php echo esc_html( $source['label'] . ': ' . $source['title'] ); ?></option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
					<label for="worldgraph-dramaturgy-lens"><?php esc_html_e( 'Lens', 'worldgraph' ); ?></label>
					<select id="worldgraph-dramaturgy-lens" class="widefat">
						<option value="whole_story"><?php esc_html_e( 'Whole story: movement and dramatic question', 'worldgraph' ); ?></option>
						<option value="character"><?php esc_html_e( 'Character: desire, obstacles, and transformation', 'worldgraph' ); ?></option>
						<option value="structure"><?php esc_html_e( 'Structure: progression, rhythm, and escalation', 'worldgraph' ); ?></option>
						<option value="audience"><?php esc_html_e( 'Audience: information, anticipation, and feeling', 'worldgraph' ); ?></option>
					</select>
					<label for="worldgraph-dramaturgy-question"><?php esc_html_e( 'Question for the reading', 'worldgraph' ); ?></label>
					<textarea id="worldgraph-dramaturgy-question" class="widefat" rows="5" placeholder="<?php esc_attr_e( 'For example: where does the story lose momentum, and what could sharpen the turn?', 'worldgraph' ); ?>"></textarea>
					<button type="button" id="worldgraph-run-dramaturgy" class="button button-primary" <?php disabled( empty( $sources ) ); ?>><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span><?php esc_html_e( 'Run dramaturgical reading', 'worldgraph' ); ?></button>
					<p id="worldgraph-dramaturgy-status" class="worldgraph-dramaturgy-status" role="status" aria-live="polite"></p>
					<div class="worldgraph-dramaturgy-method">
						<strong><?php esc_html_e( 'Method', 'worldgraph' ); ?></strong>
						<p><?php esc_html_e( 'The reading separates observed evidence from interpretation and suggestions. It does not change canonical Story Graph relationships unless you choose to save the result as an editorial note.', 'worldgraph' ); ?></p>
					</div>
				</section>
				<section class="worldgraph-dramaturgy-panel worldgraph-dramaturgy-result" aria-labelledby="worldgraph-dramaturgy-result-title">
					<div class="worldgraph-dramaturgy-result-header">
						<h2 id="worldgraph-dramaturgy-result-title"><?php esc_html_e( 'Dramaturgical reading', 'worldgraph' ); ?></h2>
						<button type="button" id="worldgraph-save-dramaturgy" class="button" disabled><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Save as editorial note', 'worldgraph' ); ?></button>
					</div>
					<textarea id="worldgraph-dramaturgy-output" class="widefat" rows="22" placeholder="<?php esc_attr_e( 'Your dramaturgical reading will appear here.', 'worldgraph' ); ?>"></textarea>
					<p class="description"><?php esc_html_e( 'Review and edit the reading before saving it to the selected source.', 'worldgraph' ); ?></p>
				</section>
			</div>
		</div>
		<?php
	}

	/** Handle dramaturgical analysis. */
	public static function ajax_run(): void {
		check_ajax_referer( 'worldgraph_dramaturgy_tool', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to run dramaturgical readings.', 'worldgraph' ) ], 403 );
		}
		$post_id = absint( $_POST['source_id'] ?? 0 );
		$lens    = sanitize_key( $_POST['lens'] ?? 'whole_story' );
		$question = sanitize_textarea_field( wp_unslash( $_POST['question'] ?? '' ) );
		$post    = get_post( $post_id );
		$allowed = [ 'worldgraph_project', 'worldgraph_episode', 'worldgraph_scene' ];
		if ( ! $post || ! in_array( $post->post_type, $allowed, true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Choose a valid story source.', 'worldgraph' ) ], 400 );
		}

		$lens_instructions = [
			'whole_story' => 'Trace the central dramatic question, the story\'s progression, turning points, stakes, and how each major movement changes the situation.',
			'character'   => 'Trace the protagonist and key characters through desire, opposition, choices, relationships, stakes, and meaningful change. Distinguish stated traits from demonstrated action.',
			'structure'   => 'Examine scene and sequence functions, escalation, reversals, withholding and release of information, rhythm, duration, and places where the dramatic movement accelerates or stalls.',
			'audience'   => 'Examine the audience\'s changing knowledge, expectations, anticipation, surprise, emotional alignment, and the sensory or visual opportunities that shape the experience of time-based film.',
		];
		$instruction = $lens_instructions[ $lens ] ?? $lens_instructions['whole_story'];
		$context     = ( new AI_Context_Builder() )->build_post_context( $post_id );
		$prompt      = sprintf(
			'Create a rigorous but practical film dramaturgical reading of "%s". %s Use only evidence in the Story Graph context; mark uncertainty when evidence is missing. Structure the response with exactly these headings: Dramatic situation, Evidence and movement, Tensions or questions, Practical possibilities. Under Practical possibilities, offer specific revision or research questions rather than prescriptive rewrites. Do not invent scenes, characters, relationships, or events. This is an editorial analysis, not a canonical graph update.',
			$post->post_title,
			$instruction
		);
		if ( $question ) {
			$prompt .= ' The editor\'s question is: ' . $question;
		}
		$result = ( new AI_LLM_Client() )->chat( $prompt, [
			'system_prompt' => 'You are a film dramaturg and researcher. Attend to narrative form, performance, cinematic time, audience experience, and the difference between textual evidence and interpretation. Be concrete, nuanced, and useful to a working editor.',
			'context'       => $context,
			'max_tokens'    => 1300,
			'temperature'   => 0.4,
		] );
		if ( ! empty( $result['error'] ) || empty( $result['content'] ) ) {
			wp_send_json_error( [ 'message' => __( 'The AI service could not complete the dramaturgical reading.', 'worldgraph' ) ], 502 );
		}
		wp_send_json_success( [ 'analysis' => trim( wp_strip_all_tags( $result['content'] ) ) ] );
	}

	/** Save a dramaturgical reading as an editorial note. */
	public static function ajax_save(): void {
		check_ajax_referer( 'worldgraph_dramaturgy_tool', 'nonce' );
		$post_id = absint( $_POST['source_id'] ?? 0 );
		$analysis = sanitize_textarea_field( wp_unslash( $_POST['analysis'] ?? '' ) );
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, [ 'worldgraph_project', 'worldgraph_episode', 'worldgraph_scene' ], true ) || ! current_user_can( 'edit_post', $post_id ) || '' === $analysis ) {
			wp_send_json_error( [ 'message' => __( 'Unable to save this dramaturgical reading.', 'worldgraph' ) ], 400 );
		}
		update_post_meta( $post_id, 'worldgraph_dramaturgy_analysis', $analysis );
		update_post_meta( $post_id, 'worldgraph_dramaturgy_analysis_updated', current_time( 'mysql' ) );
		wp_send_json_success( [ 'message' => __( 'Dramaturgical reading saved.', 'worldgraph' ) ] );
	}
}
