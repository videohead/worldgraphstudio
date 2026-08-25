<?php
/**
 * Backwards-compatible model prompt profiles.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes model-specific motion grammar without injecting meta-instructions.
 *
 * Loader files and sampling topology remain owned by the Template workflow.
 * These profiles affect only positive prompt text and are intentionally
 * separate from negative conditioning and runtime controls.
 */
class Generation_Prompt_Profiles {

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
				'positive_prefix'     => __( 'Video motion: begin with the described subject action, state the camera movement, and show visible progression to the final frame. Keep identity and lighting stable.', 'worldgraph' ),
				'assistant_guidance'  => __( 'Write motion first. Open with explicit subject action and camera movement, use temporal markers such as “then” or “as the camera pans,” and keep one consistent cinematic lighting/style anchor. Do not imply cuts unless requested.', 'worldgraph' ),
				'negative_suggestion' => __( 'For a shot that should visibly move, consider adding “static, frozen” to the negative prompt. Do not use it for an intentionally still or locked-off shot.', 'worldgraph' ),
			];
		}

		if ( Model_Family::LTXV === $family ) {
			return [
				'id'                  => 'ltx-chronological-action',
				'label'               => __( 'LTX chronological action', 'worldgraph' ),
				'positive_prefix'     => __( 'Video motion: show the described action as one continuous chronological shot. Include subject, environment, and camera movement, plus any visible lighting change.', 'worldgraph' ),
				'assistant_guidance'  => __( 'Write one detailed chronological paragraph beginning directly with the action. Include appearance, environment, camera framing and movement, lighting and color. Include synchronized dialogue, ambience, music, or sound cues only when this workflow generates audio.', 'worldgraph' ),
				'negative_suggestion' => __( 'Keep negative conditioning concise and workflow-specific; do not apply Wan motion negatives or generic game/cartoon terms when the requested style needs them.', 'worldgraph' ),
			];
		}

		return [
			'id'                  => 'generic-motion-first',
			'label'               => __( 'Motion-first video', 'worldgraph' ),
			'positive_prefix'     => __( 'Video motion: show the described action as one continuous shot with clear subject and camera movement from the opening to the final frame.', 'worldgraph' ),
			'assistant_guidance'  => __( 'Lead with camera and subject motion, describe events in temporal order, and state a consistent visual style and lighting direction.', 'worldgraph' ),
			'negative_suggestion' => '',
		];
	}

	/**
	 * Apply the selected Template's policy to positive prompt text.
	 *
	 * Profile guidance is deliberately not prepended to the media prompt. It is
	 * composition metadata for deterministic ordering and authoring assistance,
	 * not visual content the generation model should attempt to depict.
	 *
	 * @param string $prompt      Composed positive prompt.
	 * @param int    $post_id     Source Story Graph post ID.
	 * @param string $intent      Creative intent slug.
	 * @param int    $template_id Selected Template post ID.
	 * @return string
	 */
	public static function apply( string $prompt, int $post_id, string $intent, int $template_id ): string {
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			return $prompt;
		}
		$profile  = self::for_template( $template_id );
		$modality = Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template_id, 'modality' ) );
		$policy   = Generation_Prompt_Policy::for_template(
			$template_id,
			[
				'output_type' => Generation_Modality::output_type( $modality ),
				'post_type'   => (string) get_post_type( $post_id ),
				'intent'      => $intent,
			]
		);

		/**
		 * Filter a positive prompt after the server selects a Template.
		 *
		 * @param string $prompt      Composed prompt.
		 * @param array  $profile    Sanitized profile description.
		 * @param int    $post_id    Source post ID.
		 * @param string $intent     Creative intent.
		 * @param int    $template_id Template post ID.
		 */
		$filtered = $profile
			? (string) apply_filters( 'worldgraph_generation_prompt_profile', $prompt, $profile, $post_id, $intent, $template_id )
			: $prompt;

		// A trusted filter may add useful detail, but it may not bypass the
		// selected Template's hard provider/model bounds.
		return Generation_Prompt_Policy::finalize_text( $filtered, $policy );
	}

	/** Share the policy resolver so admin guidance and runtime ordering cannot diverge. */
	private static function template_family( int $template_id ): string {
		return Generation_Prompt_Policy::template_family( $template_id );
	}
}
