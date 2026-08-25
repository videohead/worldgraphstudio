<?php
/**
 * Tests for Template-aware prompt policy normalization and rendering.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Generation_Prompt_Policy;
use WorldGraph\Utils\Generation_Prompt_Profiles;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return 'UTF-8';
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55, $more = null ): string {
		$words = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : [];
		return implode( ' ', array_slice( $words, 0, max( 0, (int) $num_words ) ) ) . ( count( $words ) > $num_words ? (string) $more : '' );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/model_family.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-generation-prompt-policy.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-generation-prompt-profiles.php';

/** Prompt policy unit tests. */
final class Test_Generation_Prompt_Policy extends TestCase {

	/** Invoke a private static policy helper without duplicating its rules. */
	private function invoke_policy_helper( string $method, ...$arguments ) {
		$reflection = new ReflectionMethod( Generation_Prompt_Policy::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$arguments );
	}

	/** Count whitespace-delimited words in a rendered prompt. */
	private function word_count( string $value ): int {
		$words = preg_split( '/\s+/u', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/** Shot-image fallback policy is compact and cached for an identical context. */
	public function test_shot_image_policy_is_bounded_and_reuses_identical_cache_entry(): void {
		$cache = new ReflectionProperty( Generation_Prompt_Policy::class, 'policy_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, [] );

		$context = [
			'output_type' => 'image',
			'post_type'   => 'worldgraph_shot',
			'intent'      => 'shot-representative-still',
		];
		$first = Generation_Prompt_Policy::for_template( 0, $context );
		$again = Generation_Prompt_Policy::for_template( 0, $context );

		$this->assertSame( $first, $again );
		$this->assertCount( 1, $cache->getValue() );
		$this->assertSame( 120, $first['limits']['target_words'] );
		$this->assertSame( 120, $first['limits']['max_words'] );
		$this->assertSame( 4000, $first['limits']['max_characters'] );
		$this->assertSame( 12000, $first['limits']['max_bytes'] );
		$this->assertSame( 'fallback-image', $first['hints']['profile'] );
		$this->assertSame( [ 'core' ], $first['sources'], 'Empty policy layers and an unchanged final filter must not claim provenance.' );

		$preview = Generation_Prompt_Policy::preview( 'The shutters close.', $first, 0 );
		$this->assertSame( 3, $preview['word_count'] );
		$this->assertSame( hash( 'sha256', 'The shutters close.' ), $preview['prompt_hash'] );
		$this->assertArrayNotHasKey( 'sections', $preview );
		$this->assertArrayNotHasKey( 'sources', $preview );
	}

	/** Context insertion order does not alter the canonical effective policy. */
	public function test_equivalent_context_order_produces_the_same_canonical_policy(): void {
		$left = Generation_Prompt_Policy::for_template(
			0,
			[ 'output_type' => 'video', 'post_type' => 'worldgraph_shot', 'intent' => 'shot-video' ]
		);
		$right = Generation_Prompt_Policy::for_template(
			0,
			[ 'intent' => 'shot-video', 'post_type' => 'worldgraph_shot', 'output_type' => 'video' ]
		);

		$this->assertSame( $left, $right );
	}

	/** Fingerprints track effective prompt behavior, not diagnostic provenance. */
	public function test_policy_fingerprint_ignores_sources_and_detects_effective_changes(): void {
		$policy = Generation_Prompt_Policy::for_template(
			0,
			[ 'output_type' => 'video', 'post_type' => 'worldgraph_shot', 'intent' => 'shot-video' ]
		);
		$provenance_only            = $policy;
		$provenance_only['sources'] = [ 'core', 'template_editor', 'filter' ];

		$this->assertSame(
			Generation_Prompt_Policy::fingerprint( $policy ),
			Generation_Prompt_Policy::fingerprint( $provenance_only )
		);

		$tighter                        = $policy;
		$tighter['limits']['max_words'] = max( 1, (int) $policy['limits']['max_words'] - 1 );
		$this->assertNotSame(
			Generation_Prompt_Policy::fingerprint( $policy ),
			Generation_Prompt_Policy::fingerprint( $tighter )
		);

		$motion_first                       = $policy;
		$motion_first['hints']['lead_with'] = 'motion';
		$this->assertNotSame(
			Generation_Prompt_Policy::fingerprint( $policy ),
			Generation_Prompt_Policy::fingerprint( $motion_first )
		);
	}

	/** Compact family identifiers are recognized without substring false positives. */
	public function test_compact_template_family_hints_are_recognized_at_token_boundaries(): void {
		$this->assertSame( 'ltxv', $this->invoke_policy_helper( 'family_from_hint', 'LTXV' ) );
		$this->assertSame( 'ltxv', $this->invoke_policy_helper( 'family_from_hint', 'LTXV-13B text-to-video checkpoint' ) );
		$this->assertSame( 'wan', $this->invoke_policy_helper( 'family_from_hint', 'WanVideo 2.2' ) );
		$this->assertSame( '', $this->invoke_policy_helper( 'family_from_hint', 'A Swan Song' ) );
	}

	/** A pasted Comfy workflow is a stronger family signal than generic Template text. */
	public function test_template_family_is_detected_from_manual_comfy_workflow_nodes(): void {
		$template_id = 987655;
		$had_state   = array_key_exists( 'worldgraph_import_journal_state', $GLOBALS );
		$prior_state = $GLOBALS['worldgraph_import_journal_state'] ?? null;
		$GLOBALS['worldgraph_import_journal_state'] = [
			'post_types' => [ $template_id => 'worldgraph_template' ],
			'meta'       => [
				$template_id => [
					'model_family'  => '',
					'modality'      => 'text_image_to_video',
					'workflow_json' => wp_json_encode(
						[
							'1' => [ 'class_type' => 'WanImageToVideo', 'inputs' => [] ],
						]
					),
				],
			],
		];

		try {
			$this->assertSame( 'wan', $this->invoke_policy_helper( 'template_family', $template_id ) );
			$this->assertSame( 'wan-motion-first', Generation_Prompt_Profiles::for_template( $template_id )['id'] ?? '' );
		} finally {
			if ( $had_state ) {
				$GLOBALS['worldgraph_import_journal_state'] = $prior_state;
			} else {
				unset( $GLOBALS['worldgraph_import_journal_state'] );
			}
		}
	}

	/** MCP/REST schema wrappers all contribute trusted numeric prompt ceilings. */
	public function test_prompt_schema_limit_traverses_input_and_parameters_wrappers(): void {
		$this->assertSame(
			240,
			$this->invoke_policy_helper(
				'schema_prompt_limit',
				[
					'input'      => [ 'properties' => [ 'prompt' => [ 'type' => 'string', 'maxLength' => 320 ] ] ],
					'parameters' => [ 'properties' => [ 'positive_prompt' => [ 'type' => 'string', 'maxLength' => 240 ] ] ],
				]
			)
		);
		$this->assertSame(
			180,
			$this->invoke_policy_helper(
				'schema_prompt_limit',
				[ 'prompt' => [ 'type' => 'string', 'maxLength' => 180 ], 'seed' => [ 'type' => 'integer' ] ]
			)
		);
	}

	/** Wan's reviewed profile places visible motion directly after the primary beat. */
	public function test_wan_policy_leads_with_motion_after_primary(): void {
		$policy = $this->invoke_policy_helper(
			'model_policy',
			[
				'output_type'  => 'video',
				'modality'     => 'text_to_video',
				'model_family' => 'wan',
			]
		);
		$this->assertSame( 'motion', $policy['hints']['lead_with'] );

		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'camera', 'text' => 'Camera: hold an eye-level medium shot.' ],
				[ 'id' => 'action', 'text' => 'Action: Red reaches for the latch.' ],
				[ 'id' => 'primary', 'text' => 'Shot description: Red stands at the window.' ],
				[ 'id' => 'motion', 'text' => 'Motion: The shutters swing closed.' ],
			],
			$policy
		);

		$this->assertSame(
			'Red stands at the window. The shutters swing closed. hold an eye-level medium shot. Red reaches for the latch.',
			$result['prompt']
		);
	}

	/** Explicit Shot motion survives a tight WAN budget ahead of optional prose. */
	public function test_protected_shot_motion_survives_tight_wan_capacity(): void {
		$policy = $this->invoke_policy_helper(
			'model_policy',
			[
				'output_type'  => 'video',
				'modality'     => 'video_to_video',
				'model_family' => 'wan',
			]
		);
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'primary', 'text' => 'Shot description: Red faces the window and reaches toward the old wooden shutters with a steady expression.' ],
				[ 'id' => 'motion', 'text' => 'Motion: Red closes both shutters slowly; the curtains settle; then she holds still.', 'protected' => true ],
				[ 'id' => 'camera', 'text' => 'Framing: eye-level medium shot with a forty millimeter lens and a slow push in.', 'protected' => true ],
				[ 'id' => 'objective', 'text' => 'Output: one continuous cinematic moving shot showing exactly this moment without adding another action.' ],
				[ 'id' => 'author_instructions', 'text' => 'Additional instructions: retain the established costume and exact window design throughout every frame.' ],
				[ 'id' => 'look', 'text' => 'Project visual direction: hand-painted storybook animation, high-key warm interior, soft cel shading, muted earth palette.', 'protected' => true ],
				[ 'id' => 'constraints', 'text' => 'Constraints: clean unbranded shot containing only the described action with no cut.' ],
				[ 'id' => 'setting', 'text' => 'Setting: a long optional description that should yield before explicit motion when the hard budget has no spare capacity.' ],
			],
			$policy
		);

		$this->assertLessThanOrEqual( 100, $this->word_count( $result['prompt'] ) );
		$this->assertStringContainsString( 'Red closes both shutters slowly', $result['prompt'] );
		$this->assertStringContainsString( 'eye-level medium shot', $result['prompt'] );
		$this->assertStringContainsString( 'hand-painted storybook animation', $result['prompt'] );
		$this->assertStringContainsString( 'retain the established costume', $result['prompt'] );
		$this->assertStringNotContainsString( 'long optional description', $result['prompt'] );
	}

	/** Tight policies compact creative essentials instead of whole-dropping them. */
	public function test_tight_ceiling_keeps_camera_look_and_author_exception(): void {
		$policy = [
			'limits'   => [ 'target_words' => 20, 'max_words' => 20 ],
			'sections' => [ 'preferred' => [ 'primary', 'camera', 'look', 'author_instructions', 'constraints' ] ],
			'hints'    => [ 'format' => 'natural_language' ],
		];
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'primary', 'text' => 'Shot description: Red closes the old wooden shutters at dusk.', 'protected' => true ],
				[ 'id' => 'camera', 'text' => 'Camera: eye-level medium shot through a forty millimeter lens.', 'protected' => true ],
				[ 'id' => 'look', 'text' => 'Project look: warm hand-painted storybook animation.', 'protected' => true ],
				[ 'id' => 'author_instructions', 'text' => 'Additional instructions: keep the red cloak unchanged.', 'protected' => true ],
				[ 'id' => 'constraints', 'text' => 'Constraints: no text or logos.', 'protected' => true ],
			],
			$policy
		);

		$this->assertLessThanOrEqual( 20, $this->word_count( $result['prompt'] ) );
		$this->assertStringContainsString( 'Shot description:', $result['prompt'] );
		$this->assertStringContainsString( 'Camera:', $result['prompt'] );
		$this->assertStringContainsString( 'Project look:', $result['prompt'] );
		$this->assertStringContainsString( 'Additional instructions:', $result['prompt'] );
		$this->assertTrue( $result['truncated'] );

		$preview = Generation_Prompt_Policy::preview( $result['prompt'], $policy );
		$this->assertTrue( $preview['truncated'] );
		$this->assertSame( $result['omitted_sections'], $preview['omitted_sections'] );
	}

	/** Exact audio copy is reserved ahead of descriptive prompt overhead. */
	public function test_verbatim_audio_is_not_omitted_for_prompt_overhead(): void {
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'primary', 'text' => 'Cue: Closing narration.', 'protected' => true ],
				[ 'id' => 'subject', 'text' => 'Soundtrack role: voiceover.' ],
				[ 'id' => 'verbatim', 'text' => 'Speak this text exactly: Trust requires patience and care.', 'protected' => true ],
			],
			[
				'limits'   => [ 'target_words' => 9, 'max_words' => 9 ],
				'sections' => [ 'preferred' => [ 'primary', 'verbatim', 'subject' ] ],
				'hints'    => [ 'format' => 'natural_language' ],
			]
		);

		$this->assertSame( 'Speak this text exactly: Trust requires patience and care.', $result['prompt'] );
		$this->assertNotContains( 'verbatim', $result['omitted_sections'] );
	}

	/** Legacy Shots without a dedicated field retain one concise motion fallback. */
	public function test_protected_legacy_motion_fallback_survives_tight_wan_capacity(): void {
		$policy = $this->invoke_policy_helper(
			'model_policy',
			[
				'output_type'  => 'video',
				'modality'     => 'video_to_video',
				'model_family' => 'wan',
			]
		);
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'primary', 'text' => 'Shot description: Red looks toward the forest and closes the shutters with a thoughtful steady expression.' ],
				[ 'id' => 'motion', 'text' => 'Motion: Perform only the described Shot action as one continuous take; ending: hold on the warm interior. Complete it in 10 seconds.', 'protected' => true ],
				[ 'id' => 'camera', 'text' => 'Framing: eye-level medium shot, forty millimeter lens, locked-off camera.', 'protected' => true ],
				[ 'id' => 'objective', 'text' => 'Output: one continuous video shot.' ],
				[ 'id' => 'look', 'text' => "Look continuity: Preserve the reference frame's established Project and Scene look and lighting.", 'protected' => true ],
				[ 'id' => 'constraints', 'text' => 'Constraints: clean unbranded shot containing only the described action.' ],
				[ 'id' => 'setting', 'text' => 'Setting: optional inherited description that should yield first.' ],
			],
			$policy
		);

		$this->assertLessThanOrEqual( 100, $this->word_count( $result['prompt'] ) );
		$this->assertStringContainsString( 'Perform only the described Shot action', $result['prompt'] );
		$this->assertStringContainsString( 'Complete it in 10 seconds', $result['prompt'] );
	}

	/** A protected section that cannot fit must not reserve unusable capacity. */
	public function test_optional_sections_backfill_capacity_left_by_an_omitted_protected_section(): void {
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'primary', 'text' => 'Primary one two three four five six seven eight nine.' ],
				[ 'id' => 'objective', 'text' => 'Objective ten eleven twelve thirteen fourteen fifteen sixteen.' ],
				[ 'id' => 'constraints', 'text' => 'Constraints seventeen eighteen nineteen twenty twenty-one twenty-two twenty-three.' ],
				[ 'id' => 'motion', 'text' => 'Motion: shutters close slowly now.' ],
			],
			[
				'limits'   => [ 'target_words' => 25, 'max_words' => 25 ],
				'sections' => [ 'preferred' => [ 'primary', 'objective', 'constraints', 'motion' ] ],
				'hints'    => [ 'format' => 'natural_language' ],
			]
		);

		$this->assertSame( 23, $this->word_count( $result['prompt'] ) );
		$this->assertStringContainsString( 'shutters close slowly now', $result['prompt'] );
		$this->assertContains( 'constraints', $result['omitted_sections'] );
	}

	/** Template editor fields override JSON policy and participate in cache identity. */
	public function test_template_editor_fields_are_sparse_highest_precedence_overrides(): void {
		$template_id = 987654;
		$had_state   = array_key_exists( 'worldgraph_import_journal_state', $GLOBALS );
		$prior_state = $GLOBALS['worldgraph_import_journal_state'] ?? null;
		$cache       = new ReflectionProperty( Generation_Prompt_Policy::class, 'policy_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, [] );

		$GLOBALS['worldgraph_import_journal_state'] = [
			'post_types' => [ $template_id => 'worldgraph_template' ],
			'meta'       => [
				$template_id => [
					'modality'            => 'text_to_video',
					'model_family'         => 'wan',
					'configuration_json'   => wp_json_encode(
						[
							'prompt_policy' => [
								'limits' => [ 'target_words' => 77, 'max_words' => 99 ],
								'hints'  => [ 'lead_with' => 'subject', 'format' => 'natural_language' ],
							],
						]
					),
					'prompt_target_words' => '55',
					'prompt_max_words'    => '65',
					'prompt_lead_with'    => 'action',
					'prompt_format'       => 'concise_phrases',
				],
			],
		];

		try {
			$edited = Generation_Prompt_Policy::for_template( $template_id );
			$this->assertSame( 55, $edited['limits']['target_words'] );
			$this->assertSame( 65, $edited['limits']['max_words'] );
			$this->assertSame( 'action', $edited['hints']['lead_with'] );
			$this->assertSame( 'concise_phrases', $edited['hints']['format'] );
			$this->assertContains( 'template_editor', $edited['sources'] );

			$GLOBALS['worldgraph_import_journal_state']['meta'][ $template_id ]['prompt_target_words'] = 'many';
			$GLOBALS['worldgraph_import_journal_state']['meta'][ $template_id ]['prompt_max_words']    = '';
			$GLOBALS['worldgraph_import_journal_state']['meta'][ $template_id ]['prompt_lead_with']    = 'camera';
			$GLOBALS['worldgraph_import_journal_state']['meta'][ $template_id ]['prompt_format']       = 'verbose';

			$invalid = Generation_Prompt_Policy::for_template( $template_id );
			$this->assertSame( 77, $invalid['limits']['target_words'] );
			$this->assertSame( 99, $invalid['limits']['max_words'] );
			$this->assertSame( 'subject', $invalid['hints']['lead_with'] );
			$this->assertSame( 'natural_language', $invalid['hints']['format'] );
			$this->assertNotContains( 'template_editor', $invalid['sources'] );
			$this->assertCount( 2, $cache->getValue(), 'Changing editor fields must not reuse the prior cached policy.' );
		} finally {
			$cache->setValue( null, [] );
			if ( $had_state ) {
				$GLOBALS['worldgraph_import_journal_state'] = $prior_state;
			} else {
				unset( $GLOBALS['worldgraph_import_journal_state'] );
			}
		}
	}

	/** Later output/modality policy lists replace rather than index-merge earlier lists. */
	public function test_contextual_declaration_layers_replace_ordered_section_lists(): void {
		$declaration = [
			'default'             => [
				'limits'   => [ 'target_words' => 100, 'max_words' => 180 ],
				'sections' => [
					'preferred' => [ 'primary', 'setting', 'characters' ],
					'forbidden' => [ 'ancestor_context', 'dependent_context' ],
				],
			],
			'video'               => [
				'limits'   => [ 'target_words' => 80 ],
				'sections' => [ 'preferred' => [ 'primary', 'motion' ] ],
			],
			'text_image_to_video' => [
				'limits'   => [ 'max_words' => 90 ],
				'sections' => [
					'preferred' => [ 'primary', 'camera' ],
					'forbidden' => [ 'ancestor_context' ],
				],
			],
		];

		$result = $this->invoke_policy_helper( 'declaration_for_context', $declaration, 'text_image_to_video', 'video' );

		$this->assertSame( 80, $result['limits']['target_words'] );
		$this->assertSame( 90, $result['limits']['max_words'] );
		$this->assertSame( [ 'primary', 'camera' ], $result['sections']['preferred'] );
		$this->assertSame( [ 'ancestor_context' ], $result['sections']['forbidden'] );
	}

	/** Defensive maxima and semantic lists are canonicalized deterministically. */
	public function test_policy_finalization_clamps_limits_and_canonicalizes_sections(): void {
		$policy = $this->invoke_policy_helper(
			'finalize_policy',
			[
				'limits'   => [
					'target_words'  => 9000,
					'max_words'     => 9000,
					'max_characters' => 200000,
					'max_bytes'      => 200000,
				],
				'sections' => [
					'preferred' => [ 'camera', 'camera', 'not_a_section' ],
					'forbidden' => [ 'ancestor_context', 'primary', 'objective', 'not_a_section' ],
				],
				'hints'    => [ 'profile' => 'A Test Profile', 'lead_with' => 'invalid', 'format' => 'invalid' ],
			]
		);

		$this->assertSame( 4000, $policy['limits']['target_words'] );
		$this->assertSame( 4000, $policy['limits']['max_words'] );
		$this->assertSame( 100000, $policy['limits']['max_characters'] );
		$this->assertSame( 100000, $policy['limits']['max_bytes'] );
		$this->assertSame( 'camera', $policy['sections']['preferred'][0] );
		$this->assertSame( count( $policy['sections']['preferred'] ), count( array_unique( $policy['sections']['preferred'] ) ) );
		$this->assertSame( [ 'ancestor_context' ], $policy['sections']['forbidden'] );
		$this->assertSame( 'atestprofile', $policy['hints']['profile'] );
		$this->assertSame( 'subject', $policy['hints']['lead_with'] );
		$this->assertSame( 'natural_language', $policy['hints']['format'] );
	}

	/** Primary text leads, concise labels are removed, and optional forbidden context is absent. */
	public function test_concise_phrase_rendering_is_primary_first_and_section_aware(): void {
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'setting', 'text' => 'Setting: a warm cottage interior.' ],
				[ 'id' => 'ancestor_context', 'text' => 'Inherited story context: unrelated episode synopsis.' ],
				[ 'id' => 'primary', 'text' => 'Shot: The shutters meet inside the warm cottage.' ],
				[ 'id' => 'objective', 'text' => 'Objective: create one decisive frame.' ],
				[ 'id' => 'motion', 'text' => 'Motion: Red closes both shutters.' ],
				[ 'id' => 'constraints', 'text' => 'Constraints: clean and unbranded.' ],
			],
			[
				'limits'   => [ 'target_words' => 40, 'max_words' => 50 ],
				'sections' => [
					'preferred' => [ 'motion', 'setting', 'objective', 'constraints' ],
					'forbidden' => [ 'ancestor_context', 'objective' ],
				],
				'hints'    => [ 'format' => 'concise_phrases' ],
			]
		);

		$this->assertStringStartsWith( 'The shutters meet inside the warm cottage', $result['prompt'] );
		$this->assertStringContainsString( 'Red closes both shutters', $result['prompt'] );
		$this->assertStringContainsString( 'create one decisive frame', $result['prompt'], 'Protected objectives cannot be forbidden.' );
		$this->assertStringNotContainsString( 'unrelated episode synopsis', $result['prompt'] );
		$this->assertStringNotContainsString( "\n", $result['prompt'] );
	}

	/** lead_with deterministically places its semantic section directly after primary. */
	public function test_lead_with_orders_the_requested_section_immediately_after_primary(): void {
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'setting', 'text' => 'Setting: a warm cottage.' ],
				[ 'id' => 'objective', 'text' => 'Output: one decisive still.' ],
				[ 'id' => 'action', 'text' => 'Action: Red closes the shutters.' ],
				[ 'id' => 'primary', 'text' => 'Shot description: Red faces the window.' ],
			],
			[
				'limits'   => [ 'target_words' => 40, 'max_words' => 50 ],
				'sections' => [ 'preferred' => [ 'setting', 'objective', 'action' ] ],
				'hints'    => [ 'lead_with' => 'action', 'format' => 'natural_language' ],
			]
		);

		$this->assertSame(
			[
				'Shot description: Red faces the window.',
				'Action: Red closes the shutters.',
				'Setting: a warm cottage.',
				'Output: one decisive still.',
			],
			explode( "\n\n", $result['prompt'] )
		);
	}

	/** Compact formatting removes known labels but preserves primary commands. */
	public function test_compact_format_preserves_unrecognized_instruction_before_colon(): void {
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'primary', 'text' => 'One horizontal filmstrip: Panel 1 opening, Panel 2 closing.' ],
				[ 'id' => 'camera', 'text' => 'Framing: wide and level.' ],
			],
			[
				'limits'   => [ 'target_words' => 30, 'max_words' => 40 ],
				'sections' => [ 'preferred' => [ 'camera' ] ],
				'hints'    => [ 'format' => 'concise_phrases' ],
			]
		);

		$this->assertStringStartsWith( 'One horizontal filmstrip: Panel 1 opening, Panel 2 closing', $result['prompt'] );
		$this->assertStringContainsString( 'wide and level', $result['prompt'] );
		$this->assertStringNotContainsString( 'Framing:', $result['prompt'] );
	}

	/** Protected instructions reserve hard capacity before optional sections. */
	public function test_protected_sections_are_preserved_before_optional_capacity(): void {
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'primary', 'text' => 'Primary: one two three four five six seven eight nine' ],
				[ 'id' => 'motion', 'text' => 'Motion: Red closes both shutters' ],
				[ 'id' => 'objective', 'text' => 'Objective: ten eleven twelve thirteen fourteen fifteen sixteen' ],
				[ 'id' => 'constraints', 'text' => 'Constraints: seventeen eighteen nineteen twenty twenty-one' ],
			],
			[
				'limits'   => [ 'target_words' => 20, 'max_words' => 25 ],
				'sections' => [ 'preferred' => [ 'motion', 'objective', 'constraints' ] ],
				'hints'    => [ 'format' => 'natural_language' ],
			]
		);

		$this->assertSame( 24, $this->word_count( $result['prompt'] ) );
		$this->assertStringContainsString( 'Primary:', $result['prompt'] );
		$this->assertStringContainsString( 'Objective:', $result['prompt'] );
		$this->assertStringContainsString( 'Constraints:', $result['prompt'] );
		$this->assertStringNotContainsString( 'Motion:', $result['prompt'] );
		$this->assertContains( 'motion', $result['omitted_sections'] );
		$this->assertTrue( $result['truncated'], 'Omitting a section is reported as a bounded/truncated composition.' );
	}

	/** Word, Unicode-character, and byte ceilings all cut at safe word boundaries. */
	public function test_flat_prompt_hard_limits_cut_at_word_boundaries(): void {
		$words = Generation_Prompt_Policy::finalize_text(
			'one two three four five six',
			[ 'limits' => [ 'target_words' => 5, 'max_words' => 5 ] ]
		);
		$characters = Generation_Prompt_Policy::finalize_text(
			'alpha beta gamma',
			[ 'limits' => [ 'target_words' => 20, 'max_words' => 50, 'max_characters' => 12 ] ]
		);
		$bytes = Generation_Prompt_Policy::finalize_text(
			'café noir rouge',
			[ 'limits' => [ 'target_words' => 20, 'max_words' => 50, 'max_bytes' => 9 ] ]
		);

		$this->assertSame( 'one two three four five', $words );
		$this->assertSame( 'alpha beta', $characters );
		$this->assertSame( 'café', $bytes );
		$this->assertSame( 1, preg_match( '//u', $bytes ) );
	}

	/** Chronological profiles flatten labels and paragraphs into one prose line. */
	public function test_chronological_format_is_one_flowing_line(): void {
		$result = Generation_Prompt_Policy::render(
			[
				[ 'id' => 'primary', 'text' => "Shot: Red faces the window.\nShe steadies herself." ],
				[ 'id' => 'motion', 'text' => 'Motion: She closes both shutters.' ],
				[ 'id' => 'camera', 'text' => 'Camera: Hold at eye level.' ],
			],
			[
				'limits'   => [ 'target_words' => 40, 'max_words' => 50 ],
				'sections' => [ 'preferred' => [ 'motion', 'camera' ] ],
				'hints'    => [ 'format' => 'chronological_prose' ],
			]
		);

		$this->assertSame( 'Red faces the window. She steadies herself. She closes both shutters. Hold at eye level.', $result['prompt'] );
		$this->assertStringNotContainsString( "\n", $result['prompt'] );
	}
}
