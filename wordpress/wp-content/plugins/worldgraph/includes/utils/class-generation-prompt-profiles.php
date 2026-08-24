<?php
/**
 * Model-aware prompt openings for generated video.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a small, repeatable motion grammar without changing workflow settings.
 *
 * Loader files and sampling topology remain owned by the Template workflow.
 * These profiles affect only positive prompt text and are intentionally
 * separate from negative conditioning and runtime controls.
 */
class Generation_Prompt_Profiles {

	/** Profile marker used to keep prompt application idempotent. */
	private const MARKER = 'Video prompt profile';

	/**
	 * Describe the prompt guidance appropriate to a Template.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string, string>
	 */
	public static function for_template( int $template_id ): array {
		$modality = Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template_id, 'modality' ) );
		if ( 'video' !== Generation_Modality::output_type( $modality ) ) {
			return [];
		}

		$family = self::template_family( $template_id );
		if ( Model_Family::WAN === $family ) {
			return [
				'id'                  => 'wan-motion-first',
				'label'               => __( 'Wan motion-first', 'worldgraph' ),
				'positive_prefix'     => __( 'Camera and subject motion first: establish the opening action immediately, name the camera movement, and describe visible temporal progression through the final frame. Anchor the shot with cinematic lighting, coherent motion, and stable subject identity.', 'worldgraph' ),
				'assistant_guidance'  => __( 'Write motion first. Open with explicit subject action and camera movement, use temporal markers such as “then” or “as the camera pans,” and keep one consistent cinematic lighting/style anchor. Do not imply cuts unless requested.', 'worldgraph' ),
				'negative_suggestion' => __( 'For a shot that should visibly move, consider adding “static, frozen” to the negative prompt. Do not use it for an intentionally still or locked-off shot.', 'worldgraph' ),
			];
		}

		if ( Model_Family::LTXV === $family ) {
			return [
				'id'                  => 'ltx-chronological-action',
				'label'               => __( 'LTX chronological action', 'worldgraph' ),
				'positive_prefix'     => __( 'Action begins immediately in one continuous shot. Describe events in chronological order: subject movement, environmental response, camera angle and movement, then lighting and color changes through the final frame.', 'worldgraph' ),
				'assistant_guidance'  => __( 'Write one detailed chronological paragraph beginning directly with the action. Include appearance, environment, camera framing and movement, lighting and color. Include synchronized dialogue, ambience, music, or sound cues only when this workflow generates audio.', 'worldgraph' ),
				'negative_suggestion' => __( 'Keep negative conditioning concise and workflow-specific; do not apply Wan motion negatives or generic game/cartoon terms when the requested style needs them.', 'worldgraph' ),
			];
		}

		return [
			'id'                  => 'generic-motion-first',
			'label'               => __( 'Motion-first video', 'worldgraph' ),
			'positive_prefix'     => __( 'Motion first: begin with the subject action and camera movement, then describe the shot’s visible progression from opening frame to closing frame while preserving continuity.', 'worldgraph' ),
			'assistant_guidance'  => __( 'Lead with camera and subject motion, describe events in temporal order, and state a consistent visual style and lighting direction.', 'worldgraph' ),
			'negative_suggestion' => '',
		];
	}

	/**
	 * Apply the Template profile exactly once to positive prompt text.
	 *
	 * @param string $prompt      Composed positive prompt.
	 * @param int    $post_id     Source Story Graph post ID.
	 * @param string $intent      Creative intent slug.
	 * @param int    $template_id Selected Template post ID.
	 * @return string
	 */
	public static function apply( string $prompt, int $post_id, string $intent, int $template_id ): string {
		$prompt  = trim( $prompt );
		$profile = self::for_template( $template_id );
		if ( '' === $prompt || empty( $profile['positive_prefix'] ) || false !== strpos( $prompt, self::MARKER . ':' ) ) {
			return $prompt;
		}

		$profiled = self::MARKER . ': ' . (string) $profile['positive_prefix'] . "\n\n" . $prompt;

		/**
		 * Filter a model-aware positive prompt after the server selects a Template.
		 *
		 * @param string $profiled   Profiled prompt.
		 * @param array  $profile    Sanitized profile description.
		 * @param int    $post_id    Source post ID.
		 * @param string $intent     Creative intent.
		 * @param int    $template_id Template post ID.
		 */
		return (string) apply_filters( 'worldgraph_generation_prompt_profile', $profiled, $profile, $post_id, $intent, $template_id );
	}

	/** Infer a stable family slug from Template metadata and graph filenames. */
	private static function template_family( int $template_id ): string {
		$family = Model_Family::sanitize( (string) worldgraph_get_field_value( $template_id, 'model_family' ) );
		if ( '' !== $family ) {
			return $family;
		}

		$post = get_post( $template_id );
		$hints = [
			$post instanceof \WP_Post ? $post->post_title : '',
			(string) worldgraph_get_field_value( $template_id, 'template_name' ),
			(string) worldgraph_get_field_value( $template_id, 'provider_template_id' ),
			(string) worldgraph_get_field_value( $template_id, 'workflow_json' ),
		];
		foreach ( $hints as $hint ) {
			$family = Model_Family::sanitize( $hint );
			if ( '' !== $family ) {
				return $family;
			}
		}

		return '';
	}
}
