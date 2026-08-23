<?php
/**
 * Quick story summary admin tool.
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
 * Provides quick AI-generated summaries for Story Graph content.
 */
class Summary_Tool {
	/**
	 * Initialize the tool.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_worldgraph_generate_summary', [ __CLASS__, 'ajax_generate' ] );
		add_action( 'wp_ajax_worldgraph_save_summary', [ __CLASS__, 'ajax_save' ] );
	}

	/**
	 * Enqueue assets only on the Summaries page.
	 *
	 * @param string $hook Current admin hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		if ( 'worldgraph-summaries' !== $page ) {
			return;
		}

		wp_enqueue_style( 'worldgraph-summary-tool', WORLDGRAPH_PLUGIN_URL . 'assets/css/summary-tool.css', [], WORLDGRAPH_VERSION );
		wp_enqueue_script( 'worldgraph-summary-tool', WORLDGRAPH_PLUGIN_URL . 'assets/js/summary-tool.js', [], WORLDGRAPH_VERSION, true );
		wp_localize_script( 'worldgraph-summary-tool', 'worldgraphSummaryTool', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'worldgraph_summary_tool' ),
			'strings' => [
				'generating' => __( 'Generating summary...', 'worldgraph' ),
				'generated'  => __( 'Summary ready to review.', 'worldgraph' ),
				'saving'    => __( 'Saving summary...', 'worldgraph' ),
				'saved'     => __( 'Summary saved.', 'worldgraph' ),
				'error'     => __( 'Something went wrong. Please try again.', 'worldgraph' ),
			],
		] );
	}

	/**
	 * Render the summaries page.
	 */
	public static function render_page(): void {
		$sources = [];
		$source_types = [
			'worldgraph_project' => [ 'label' => __( 'Projects', 'worldgraph' ), 'field' => 'description' ],
			'worldgraph_episode' => [ 'label' => __( 'Episodes', 'worldgraph' ), 'field' => 'synopsis' ],
			'worldgraph_scene'   => [ 'label' => __( 'Scenes', 'worldgraph' ), 'field' => 'summary' ],
		];

		foreach ( $source_types as $post_type => $config ) {
			$posts = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			] );
			foreach ( $posts as $post ) {
				$sources[] = [
					'id'      => $post->ID,
					'type'    => $post_type,
					'label'   => $config['label'],
					'title'   => $post->post_title ?: __( '(Untitled)', 'worldgraph' ),
					'field'   => $config['field'],
					'summary' => \WorldGraph\Utils\worldgraph_get_field_value( $post->ID, (string) $config['field'] ),
				];
			}
		}
		?>
		<div class="wrap worldgraph-summary-page">
			<h1><?php esc_html_e( 'Quick Story Summary', 'worldgraph' ); ?></h1>
			<p class="worldgraph-summary-intro"><?php esc_html_e( 'Turn your Story Graph content into a concise summary you can review and save.', 'worldgraph' ); ?></p>
			<div class="worldgraph-summary-layout">
				<section class="worldgraph-summary-panel worldgraph-summary-controls" aria-labelledby="worldgraph-summary-controls-title">
					<h2 id="worldgraph-summary-controls-title"><?php esc_html_e( 'Summary source', 'worldgraph' ); ?></h2>
					<label for="worldgraph-summary-source"><?php esc_html_e( 'Choose a project, episode, or scene', 'worldgraph' ); ?></label>
					<select id="worldgraph-summary-source" class="widefat" <?php disabled( empty( $sources ) ); ?>>
						<?php if ( empty( $sources ) ) : ?>
							<option><?php esc_html_e( 'No story content found', 'worldgraph' ); ?></option>
						<?php else : ?>
							<?php foreach ( $sources as $source ) : ?>
								<option value="<?php echo esc_attr( $source['id'] ); ?>" data-type="<?php echo esc_attr( $source['type'] ); ?>" data-summary="<?php echo esc_attr( $source['summary'] ); ?>">
									<?php echo esc_html( $source['label'] . ': ' . $source['title'] ); ?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
					<label for="worldgraph-summary-length"><?php esc_html_e( 'Length', 'worldgraph' ); ?></label>
					<select id="worldgraph-summary-length" class="widefat">
						<option value="short"><?php esc_html_e( 'Short: 1-2 sentences', 'worldgraph' ); ?></option>
						<option value="standard" selected><?php esc_html_e( 'Standard: one paragraph', 'worldgraph' ); ?></option>
						<option value="detailed"><?php esc_html_e( 'Detailed: 2-3 paragraphs', 'worldgraph' ); ?></option>
					</select>
					<label for="worldgraph-summary-focus"><?php esc_html_e( 'Optional focus', 'worldgraph' ); ?></label>
					<textarea id="worldgraph-summary-focus" class="widefat" rows="4" placeholder="<?php esc_attr_e( 'For example: emphasize the protagonist\'s central conflict.', 'worldgraph' ); ?>"></textarea>
					<button type="button" id="worldgraph-generate-summary" class="button button-primary" <?php disabled( empty( $sources ) ); ?>>
						<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
						<?php esc_html_e( 'Generate summary', 'worldgraph' ); ?>
					</button>
					<p id="worldgraph-summary-status" class="worldgraph-summary-status" role="status" aria-live="polite"></p>
				</section>
				<section class="worldgraph-summary-panel worldgraph-summary-result" aria-labelledby="worldgraph-summary-result-title">
					<div class="worldgraph-summary-result-header">
						<h2 id="worldgraph-summary-result-title"><?php esc_html_e( 'Summary', 'worldgraph' ); ?></h2>
						<button type="button" id="worldgraph-save-summary" class="button" disabled><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Save to source', 'worldgraph' ); ?></button>
					</div>
					<textarea id="worldgraph-summary-output" class="widefat" rows="15" placeholder="<?php esc_attr_e( 'Your generated summary will appear here.', 'worldgraph' ); ?>"></textarea>
					<p class="description"><?php esc_html_e( 'Review the text before saving. Saving replaces the selected source summary field.', 'worldgraph' ); ?></p>
				</section>
			</div>
		</div>
		<?php
	}

	/** Handle summary generation. */
	public static function ajax_generate(): void {
		check_ajax_referer( 'worldgraph_summary_tool', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to generate summaries.', 'worldgraph' ) ], 403 );
		}

		$post_id = absint( $_POST['source_id'] ?? 0 );
		$length  = sanitize_key( $_POST['length'] ?? 'standard' );
		$focus   = sanitize_textarea_field( wp_unslash( $_POST['focus'] ?? '' ) );
		$post    = get_post( $post_id );
		$allowed = [ 'worldgraph_project', 'worldgraph_episode', 'worldgraph_scene' ];
		if ( ! $post || ! in_array( $post->post_type, $allowed, true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Choose a valid story source.', 'worldgraph' ) ], 400 );
		}

		$length_instruction = [
			'short'    => 'in 1 or 2 sentences',
			'standard' => 'as one concise paragraph of 3 to 5 sentences',
			'detailed' => 'in 2 or 3 concise paragraphs',
		][ $length ] ?? 'as one concise paragraph of 3 to 5 sentences';
		$context_builder = new AI_Context_Builder();
		$context         = $context_builder->build_post_context( $post_id );
		$prompt          = sprintf( 'Write a faithful story summary %s. Use only details supported by the provided Story Graph context. Do not add headings, bullet points, commentary, or invented details. Source title: %s.', $length_instruction, $post->post_title );
		if ( $focus ) {
			$prompt .= ' Focus on: ' . $focus;
		}
		$result = ( new AI_LLM_Client() )->chat( $prompt, [
			'system_prompt' => 'You are a precise story editor. Write clear, engaging summaries that preserve the source material.',
			'context'       => $context,
			'max_tokens'    => 700,
			'temperature'   => 0.45,
		] );
		if ( ! empty( $result['error'] ) || empty( $result['content'] ) ) {
			wp_send_json_error( [ 'message' => __( 'The AI service could not generate a summary.', 'worldgraph' ) ], 502 );
		}
		wp_send_json_success( [ 'summary' => trim( wp_strip_all_tags( $result['content'] ) ) ] );
	}

	/** Handle summary persistence. */
	public static function ajax_save(): void {
		check_ajax_referer( 'worldgraph_summary_tool', 'nonce' );
		$post_id = absint( $_POST['source_id'] ?? 0 );
		$summary = sanitize_textarea_field( wp_unslash( $_POST['summary'] ?? '' ) );
		$post    = get_post( $post_id );
		$fields  = [ 'worldgraph_project' => 'description', 'worldgraph_episode' => 'synopsis', 'worldgraph_scene' => 'summary' ];
		if ( ! $post || ! isset( $fields[ $post->post_type ] ) || ! current_user_can( 'edit_post', $post_id ) || '' === $summary ) {
			wp_send_json_error( [ 'message' => __( 'Unable to save this summary.', 'worldgraph' ) ], 400 );
		}
		\WorldGraph\Utils\worldgraph_update_field_value( $post_id, $fields[ $post->post_type ], $summary );
		wp_send_json_success( [ 'message' => __( 'Summary saved.', 'worldgraph' ) ] );
	}
}
