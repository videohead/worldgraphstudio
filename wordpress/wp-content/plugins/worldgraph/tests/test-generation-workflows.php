<?php
/**
 * Tests for representative-media workflows and their editor controls.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Asset_Generator;
use WorldGraph\Utils\Generation_Workflows;

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return (string) $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ): string {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return 'UTF-8';
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php';

/** Representative workflow and metabox contract tests. */
class Test_Generation_Workflows extends TestCase {

	/** Read one production method without coupling assertions to unrelated code. */
	private function method_source( string $method ): string {
		$reflection = new ReflectionMethod( Generation_Workflows::class, $method );
		$lines      = file( $reflection->getFileName() );

		$this->assertIsArray( $lines );
		return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
	}

	/** Default recipes must retain the promised image/video output counts. */
	public function test_default_workflow_output_counts(): void {
		$definitions = Generation_Workflows::definitions();

		$this->assertCount( 1, $definitions['worldgraph_project']['outputs'] );
		$this->assertCount( 1, $definitions['worldgraph_world']['outputs'] );
		$this->assertCount( 6, $definitions['worldgraph_character']['outputs'] );
		$this->assertCount( 6, $definitions['worldgraph_prop']['outputs'] );
		$this->assertCount( 6, $definitions['worldgraph_location']['outputs'] );
		$this->assertCount( 2, $definitions['worldgraph_shot']['outputs'] );
		$this->assertCount( 1, $definitions['worldgraph_scene']['outputs'] );
		$this->assertCount( 1, $definitions['worldgraph_episode']['outputs'] );
		$this->assertSame( array_keys( $definitions ), Asset_Generator::supported_post_types() );
		$this->assertNotContains( 'worldgraph_asset', Asset_Generator::supported_post_types() );
	}

	/** Authored Shot/Sound timing is normalized before a compatible run control uses it. */
	public function test_source_duration_formats_normalize_to_seconds(): void {
		$method = ( new ReflectionClass( Asset_Generator::class ) )->getMethod( 'source_duration_seconds' );
		$method->setAccessible( true );

		$this->assertSame( 10.0, $method->invoke( null, 'PT10S' ) );
		$this->assertSame( 90.5, $method->invoke( null, 'PT1M30.5S' ) );
		$this->assertSame( 62.5, $method->invoke( null, '00:01:02.5' ) );
		$this->assertSame( 7.0, $method->invoke( null, '7 seconds' ) );
		$this->assertNull( $method->invoke( null, 'until the cue ends' ) );
	}

	/** A Shot is the direct authoring surface that deliberately offers video. */
	public function test_shot_workflow_has_explicit_image_and_video_actions(): void {
		$outputs = Generation_Workflows::definitions()['worldgraph_shot']['outputs'];

		$this->assertSame( [ 'image', 'video' ], array_column( $outputs, 'type' ) );
		$this->assertSame( [ 'shot-representative-still', 'shot-video' ], array_column( $outputs, 'intent' ) );
	}

	/** Inherited Scene context must not flood Shot prompts with a transcript. */
	public function test_inherited_context_uses_short_visual_fields(): void {
		$this->assertSame(
			[ 'location', 'time_of_day', 'emotional_tone' ],
			Generation_Workflows::INHERITED_PROMPT_FIELDS['worldgraph_scene']
		);
		$this->assertNotContains( 'summary', Generation_Workflows::INHERITED_PROMPT_FIELDS['worldgraph_scene'] );
		$this->assertNotContains( 'script_content', Generation_Workflows::INHERITED_PROMPT_FIELDS['worldgraph_scene'] );
		$this->assertNotContains( 'dialogue', Generation_Workflows::INHERITED_PROMPT_FIELDS['worldgraph_scene'] );
		$this->assertNotContains( 'frame_rate', Generation_Workflows::PROMPT_FIELDS['worldgraph_project'] );
	}

	/** Sound prompts use compact Scene context without truncating supplied copy. */
	public function test_sound_prompt_context_is_bounded_and_audio_copy_is_verbatim(): void {
		$prompt  = $this->method_source( 'demonstration_sound_prompt' );
		$context = $this->method_source( 'sound_scene_context' );
		$direction = $this->method_source( 'scene_audio_direction' );

		$this->assertStringContainsString( 'self::sound_scene_context( $sound )', $prompt );
		$this->assertStringContainsString( "self::audio_verbatim_text( (string) worldgraph_get_field_value( (int) \$sound->ID, 'spoken_text' ) )", $prompt );
		$this->assertStringContainsString( "self::audio_verbatim_text( (string) worldgraph_get_field_value( (int) \$sound->ID, 'lyrics' ) )", $prompt );
		$this->assertStringContainsString( 'self::clean_text( (string) ( $sound->post_excerpt ?: $sound->post_content ), 48 )', $prompt );
		$this->assertStringContainsString( "worldgraph_get_field_value( (int) \$sound->ID, 'production_notes' ), 40", $prompt );
		$this->assertStringNotContainsString( 'self::clean_text( implode(', $prompt );
		$this->assertStringContainsString( "worldgraph_get_field_value( (int) \$sound->ID, 'duration' )", $prompt );
		$this->assertStringContainsString( "worldgraph_get_field_value( (int) \$sound->ID, 'diegetic' )", $prompt );
		$this->assertStringContainsString( 'Generation_Prompt_Policy::for_template(', $prompt );
		$this->assertStringContainsString( 'Generation_Prompt_Policy::render( $sections, $policy )', $prompt );

		$this->assertStringContainsString( "'worldgraph_sound', 'scene', 'worldgraph_scene'", $context );
		$this->assertStringContainsString( 'self::demonstration_scene_location( $scene )', $context );
		$this->assertStringContainsString( "worldgraph_get_field_value( \$scene_id, 'time_of_day' )", $context );
		$this->assertStringContainsString( "worldgraph_get_field_value( \$scene_id, 'emotional_tone' )", $context );
		$this->assertStringNotContainsString( 'generation_prompt', $context );
		$this->assertStringNotContainsString( 'summary', $context );
		$this->assertStringContainsString( "worldgraph_get_field_value( \$scene_id, 'audio_direction' )", $direction );
		$workflow   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );
		$generator  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );
		$controller = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );
		$this->assertGreaterThanOrEqual( 2, substr_count( $workflow, 'validate_sound_prompt_copy(' ) );
		$this->assertStringContainsString( 'Generation_Workflows::validate_sound_prompt_copy( $post_id, $template_id )', $generator );
		$this->assertStringContainsString( 'Generation_Workflows::validate_sound_prompt_copy( $post_id, $template_id )', $controller );

		$verbatim = ( new ReflectionClass( Generation_Workflows::class ) )->getMethod( 'audio_verbatim_text' );
		$verbatim->setAccessible( true );
		$this->assertSame(
			"First  line exactly.\nSecond line & unchanged.",
			$verbatim->invoke( null, "  First  line exactly.\r\nSecond line & unchanged.\n  " )
		);
	}

	/** Completed setup beats stay outside every Shot-generation prompt. */
	public function test_completed_leading_beat_is_removed_from_visual_shot_prompts(): void {
		$compose    = $this->method_source( 'compose_prompt' );
		$primary    = $this->method_source( 'primary_prompt_text' );
		$characters = $this->method_source( 'character_mention_position' );
		$strip      = ( new ReflectionClass( Generation_Workflows::class ) )->getMethod( 'strip_completed_leading_beat' );
		$strip->setAccessible( true );

		$this->assertStringContainsString( "\$strip_completed_beat = 'worldgraph_shot' === \$post_type;", $compose );
		$this->assertStringContainsString( 'primary_prompt_text( $post, $post_type, $fields, $strip_completed_beat )', $compose );
		$this->assertStringContainsString( 'related_character_context( $post_id, $post_type, $strip_completed_beat )', $compose );
		$this->assertStringContainsString( "'worldgraph_shot' === \$post_type && \$strip_completed_beat", $primary );
		$this->assertStringContainsString( 'if ( $strip_completed_beat )', $characters );
		$this->assertSame(
			'Red closes the shutters.',
			$strip->invoke( null, 'After saying goodnight to the Woodsman, Red closes the shutters.' )
		);
		$this->assertSame(
			'While the Woodsman watches, Red closes the shutters.',
			$strip->invoke( null, 'While the Woodsman watches, Red closes the shutters.' )
		);
	}

	/** Current one-off instructions must consume a tight prompt budget before saved defaults. */
	public function test_current_run_prompt_precedes_saved_visual_instructions(): void {
		$compose = $this->method_source( 'compose_prompt' );
		$one_off = strpos( $compose, '$base_prompt = self::complete_phrase( $base_prompt, 24 );' );
		$saved   = strpos( $compose, "worldgraph_get_field_value( \$post_id, 'generation_prompt' )" );

		$this->assertNotFalse( $one_off );
		$this->assertNotFalse( $saved );
		$this->assertLessThan( $saved, $one_off );
		$this->assertStringContainsString( "prompt_section( 'author_instructions', __( 'Additional instructions'", $compose );
		$this->assertStringContainsString( "__( 'Shot exceptions override Scene changes', 'worldgraph' )", $compose );
		$this->assertStringContainsString( "__( 'Saved visual instructions', 'worldgraph' )", $compose );
	}

	/** Project style and Shot movement use distinct, compact authoring controls. */
	public function test_visual_direction_motion_and_camera_are_separate_prompt_sections(): void {
		$project_group = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/acf-json/group_worldgraph_project.json' ), true );
		$scene_group   = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/acf-json/group_worldgraph_scene.json' ), true );
		$shot_group    = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/acf-json/group_worldgraph_shot.json' ), true );
		$this->assertIsArray( $project_group );
		$this->assertIsArray( $scene_group );
		$this->assertIsArray( $shot_group );
		$project_fields = array_column( (array) ( $project_group['fields'] ?? [] ), null, 'name' );
		$scene_fields   = array_column( (array) ( $scene_group['fields'] ?? [] ), null, 'name' );
		$shot_fields    = array_column( (array) ( $shot_group['fields'] ?? [] ), null, 'name' );

		$this->assertSame( 'Project Visual Direction', $project_fields['generation_prompt']['label'] );
		$this->assertStringContainsString( 'about 20 words', $project_fields['generation_prompt']['instructions'] );
		$this->assertStringContainsString( 'medium or rendering style, lighting, palette, contrast, and texture', $project_fields['generation_prompt']['instructions'] );
		$this->assertSame( 'Scene Look & Lighting Changes', $scene_fields['generation_prompt']['label'] );
		$this->assertSame( 'Sound & Music Direction', $scene_fields['audio_direction']['label'] );
		$this->assertSame( 'Project (Standalone Scene)', $scene_fields['project']['label'] );
		$this->assertStringContainsString( 'Episode ownership takes precedence', $scene_fields['project']['instructions'] );
		$this->assertSame( 'text', $scene_fields['lens']['type'] );
		$this->assertSame( 'select', $scene_fields['camera_movement']['type'] );
		$this->assertSame( $shot_fields['camera_movement']['choices'], $scene_fields['camera_movement']['choices'] );
		$this->assertSame( 'select', $shot_fields['camera_movement']['type'] );
		$this->assertSame( 'text', $shot_fields['motion_direction']['type'] );
		$this->assertStringContainsString( 'inherit the containing Scene default', $shot_fields['lens']['instructions'] );
		$this->assertStringContainsString( 'inherit the containing Scene default', $shot_fields['camera_movement']['instructions'] );
		$this->assertStringContainsString( 'Locked Off to suppress Scene movement', $shot_fields['camera_movement']['instructions'] );
		$this->assertSame( 'Locked Off (Static)', $shot_fields['camera_movement']['choices']['locked_off'] );
		$this->assertSame( 'Follow Subject', $shot_fields['camera_movement']['choices']['follow_subject'] );
		$this->assertSame( 'Additional Generation Constraints', $shot_fields['generation_prompt']['label'] );
		$this->assertContains( 'camera_movement', \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( 'worldgraph_shot' ) );
		$this->assertContains( 'motion_direction', \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( 'worldgraph_shot' ) );
		$this->assertContains( 'lens', \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( 'worldgraph_scene' ) );
		$this->assertContains( 'camera_movement', \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( 'worldgraph_scene' ) );

		$compose   = $this->method_source( 'compose_prompt' );
		$style     = $this->method_source( 'project_visual_direction' );
		$scene_style = $this->method_source( 'scene_visual_direction' );
		$direction = $this->method_source( 'visual_direction_context' );
		$shot      = $this->method_source( 'shot_prompt_field_sections' );
		$inherited = $this->method_source( 'inherited_instructions' );
		$this->assertStringContainsString( "prompt_section( 'look', \$visual_direction, true )", $compose );
		$this->assertStringContainsString( "[ 'worldgraph_project', 'worldgraph_scene' ]", $compose );
		$this->assertStringContainsString( "worldgraph_get_field_value( (int) \$project->ID, 'generation_prompt' )", $style );
		$this->assertStringContainsString( "__( 'Project look', 'worldgraph' )", $style );
		$this->assertStringContainsString( "worldgraph_get_field_value( (int) \$scene->ID, 'generation_prompt' )", $scene_style );
		$this->assertStringContainsString( "__( 'Scene changes override Project look', 'worldgraph' )", $scene_style );
		$this->assertStringContainsString( "__( 'Match reference continuity.', 'worldgraph' )", $direction );
		$this->assertStringContainsString( "field_prompt_value( \$post_id, 'camera_movement'", $shot );
		$this->assertStringContainsString( "field_prompt_value( \$post_id, 'motion_direction'", $shot );
		$this->assertStringContainsString( "scene_default_prompt_value( \$post_id, 'lens'", $shot );
		$this->assertStringContainsString( "scene_default_prompt_value( \$post_id, 'camera_movement'", $shot );
		$this->assertStringContainsString( "prompt_section( 'camera', \$camera, true )", $shot );
		$this->assertStringContainsString( "prompt_section( 'motion', __( 'Motion', 'worldgraph' ) . ': ' . \$motion, true )", $shot );
		$this->assertStringContainsString( "__( 'Perform only the described Shot action as one continuous take', 'worldgraph' )", $shot );
		$this->assertStringContainsString( "prompt_section( 'motion', __( 'Motion', 'worldgraph' ) . ': ' . \$fallback, true )", $shot );
		$this->assertStringContainsString( "[ 'worldgraph_project', 'worldgraph_episode', 'worldgraph_scene' ]", $inherited );
	}

	/** Scene/Prop structural fallbacks keep Project style and defaults reachable. */
	public function test_scene_and_shared_prop_have_explicit_project_ancestry_fields(): void {
		$this->assertSame( [ 'project' ], Generation_Workflows::PARENT_RULES['worldgraph_scene']['fallback_fields'] );
		$this->assertSame( [ 'story_world' ], Generation_Workflows::PARENT_RULES['worldgraph_prop']['fallback_fields'] );
		$this->assertContains( 'worldgraph_project', Generation_Workflows::PARENT_RULES['worldgraph_scene']['types'] );
		$this->assertContains( 'worldgraph_world', Generation_Workflows::PARENT_RULES['worldgraph_prop']['types'] );

		$prop_group = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/acf-json/group_worldgraph_prop.json' ), true );
		$this->assertIsArray( $prop_group );
		$prop_fields = array_column( (array) ( $prop_group['fields'] ?? [] ), null, 'name' );
		$this->assertSame( 'Story World (Shared Prop)', $prop_fields['story_world']['label'] );
		$this->assertStringContainsString( 'inherit its Project visual direction', $prop_fields['story_world']['instructions'] );
	}

	/** Filmstrip commands must not look like removable labels to concise renderers. */
	public function test_filmstrip_primary_commands_do_not_end_in_colons(): void {
		$dependent = $this->method_source( 'dependent_context' );

		$this->assertStringContainsString( "__( 'One horizontal filmstrip showing these panels', 'worldgraph' ) . \"\\n- \"", $dependent );
		$this->assertStringContainsString( "__( 'One two-panel horizontal filmstrip showing', 'worldgraph' ) . \"\\n\"", $dependent );
		$this->assertStringNotContainsString( "__( 'One horizontal filmstrip', 'worldgraph' ) . \"\\n", $dependent );
		$this->assertStringNotContainsString( "__( 'One two-panel horizontal filmstrip', 'worldgraph' ) . \":\\n\"", $dependent );
		$this->assertStringContainsString( 'self::shot_panel_framing( $shot )', $dependent );
		$this->assertStringContainsString( 'self::scene_bookend_action( $scene, $closing )', $dependent );
		$this->assertStringNotContainsString( "worldgraph_get_field_value( \$scene->ID, 'summary' )", $dependent );
	}

	/** Compact phrase clipping must not leave directional prepositions dangling. */
	public function test_compact_panel_phrases_remove_dangling_direction_words(): void {
		$method = ( new ReflectionClass( Generation_Workflows::class ) )->getMethod( 'complete_phrase' );
		$method->setAccessible( true );

		$this->assertSame( 'Red turns.', $method->invoke( null, 'Red turns toward the open cottage door', 3 ) );
		$this->assertSame( 'Space remains.', $method->invoke( null, 'Space remains between Red and the Woodsman', 3 ) );
	}

	/** The prompt filter gains Template context while retaining its original hook contract. */
	public function test_generated_prompt_filter_passes_template_as_fourth_argument(): void {
		$generator = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );

		$this->assertStringContainsString(
			"apply_filters( 'worldgraph_generate_asset_prompt', \$prompt, \$post, \$intent, \$template_id )",
			$generator
		);
		$this->assertStringContainsString( '@param int      $template_id', $generator );
	}

	/** Demonstration planning is an explicit Project scope and durable batch kind. */
	public function test_demonstration_scope_and_batch_kind_are_public(): void {
		$this->assertSame( [ 'item', 'project', 'demonstration' ], Generation_Workflows::supported_scopes() );
		$this->assertContains( Generation_Workflows::REPRESENTATIVE_BATCH, Generation_Workflows::supported_batch_kinds() );
		$this->assertContains( Generation_Workflows::DEMONSTRATION_BATCH, Generation_Workflows::supported_batch_kinds() );
		$this->assertSame( Generation_Workflows::DEMONSTRATION_BATCH, Generation_Workflows::batch_kind_for_scope( 'demonstration' ) );
		$this->assertSame( Generation_Workflows::REPRESENTATIVE_BATCH, Generation_Workflows::batch_kind_for_scope( 'project' ) );
	}

	/** Character-name inference must avoid matching aliases inside other words. */
	public function test_demonstration_character_mentions_use_name_boundaries(): void {
		$method = ( new ReflectionClass( Generation_Workflows::class ) )->getMethod( 'character_ids_mentioned' );
		$method->setAccessible( true );
		$aliases = [ 10 => [ 'Al' ], 20 => [ 'Alice' ], 30 => [ 'Bob' ] ];

		$this->assertSame( [ 10, 30 ], $method->invoke( null, 'Al crosses frame while Bob looks left; Aliceblue signage stays blurred.', $aliases ) );
	}

	/** The frozen planner contract must describe dependencies, media bindings, and fallbacks. */
	public function test_demonstration_plan_declares_durable_media_contract(): void {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );

		$this->assertNotFalse( $workflow );
		$this->assertStringContainsString( 'public static function demonstration_plan', $workflow );
		$this->assertStringContainsString( "'task_key'", $workflow );
		$this->assertStringContainsString( "'phase'", $workflow );
		$this->assertStringContainsString( "'dependencies'", $workflow );
		$this->assertStringContainsString( "'input_refs'", $workflow );
		$this->assertStringContainsString( "'start_frame'", $workflow );
		$this->assertStringContainsString( "'end_frame'", $workflow );
		$this->assertStringContainsString( "'fallback_task_key'", $workflow );
		$this->assertStringContainsString( "'audio_fallback'    => 'silence'", $workflow );
		$this->assertStringContainsString( "'subtitle_fallback' => true", $workflow );
		$this->assertStringContainsString( "'preferred_modalities'", $workflow );
		$this->assertStringContainsString( "'generation_required'", $workflow );
	}

	/** Every character gets a still while two-frame video wins when both anchors exist. */
	public function test_demonstration_character_references_cover_story_occurrences(): void {
		$planner = $this->method_source( 'demonstration_plan' );

		$this->assertStringContainsString( '$story_characters = $character_usage[\'occurrences\'];', $planner );
		$this->assertStringContainsString( 'foreach ( $story_characters as $character_id => $occurrence )', $planner );
		$this->assertStringContainsString( '$left_recurring  = count( $left[\'segment_keys\'] ) > 1 ? 0 : 1;', $planner );
		$this->assertStringContainsString( 'return $left_recurring <=> $right_recurring;', $planner );
		$this->assertStringContainsString( "__( 'Character reference still', 'worldgraph' )", $planner );
		$this->assertStringContainsString( '$primary_image   = $still_key;', $planner );
		$this->assertStringContainsString( "[ 'video_to_video', 'text_image_to_video', 'text_to_video' ]", $planner );
		$this->assertStringContainsString( "[ 'text_image_to_video', 'text_to_video' ]", $planner );
		$this->assertStringNotContainsString( "static fn( array \$occurrence ): bool => count( \$occurrence['segment_keys'] ) > 1", $planner );
	}

	/** Demonstration retries are isolated by batch kind and freeze the coordinator contract. */
	public function test_demonstration_idempotency_and_frozen_task_contract(): void {
		$reflection  = new ReflectionClass( Generation_Workflows::class );
		$idempotency = $reflection->getMethod( 'idempotency_option_name' );
		$idempotency->setAccessible( true );
		$representative = $idempotency->invoke( null, 42, 7, 'same-request', Generation_Workflows::REPRESENTATIVE_BATCH );
		$demonstration  = $idempotency->invoke( null, 42, 7, 'same-request', Generation_Workflows::DEMONSTRATION_BATCH );

		$this->assertNotSame( $representative, $demonstration );
		$this->assertSame( $demonstration, $idempotency->invoke( null, 42, 7, 'same-request', Generation_Workflows::DEMONSTRATION_BATCH ) );

		$freeze = $reflection->getMethod( 'freeze_task' );
		$freeze->setAccessible( true );
		$frozen = $freeze->invoke( null, [
			'task_key'                  => 'Shot 9 Video!',
			'source_id'                 => -91,
			'source_type'               => 'worldgraph_shot',
			'source_title'              => '<b>Closing shot</b>',
			'workflow_id'               => 'shot-video',
			'intent'                    => 'demonstration-shot-video',
			'label'                     => 'Shot video',
			'type'                      => 'video',
			'phase'                     => 'video',
			'required'                  => false,
			'generation_required'       => true,
			'prompt'                    => 'Camera tracks forward.',
			'prompt_policy_fingerprint' => str_repeat( 'a', 64 ),
			'dependencies'              => [ 'Character Ref', 'Character Ref', '' ],
			'preferred_modalities'      => [ 'text_image_to_video', 'video_to_video', 'text_image_to_video' ],
			'input_refs'                => [
				'image'      => [ 'task_key' => 'Character Ref', 'fallback_task_key' => 'Shot Still' ],
				'end_frame'  => 'Next Still',
				'not_a_slot' => 'discard-me',
			],
		], 3 );

		$this->assertSame( 3, $frozen['step'] );
		$this->assertSame( 'shot9video', $frozen['task_key'] );
		$this->assertSame( 91, $frozen['source_id'] );
		$this->assertFalse( $frozen['required'] );
		$this->assertTrue( $frozen['generation_required'] );
		$this->assertSame( [ 'characterref' ], $frozen['dependencies'] );
		$this->assertSame( [ 'text_image_to_video', 'video_to_video' ], $frozen['preferred_modalities'] );
		$this->assertSame( [ 'task_key' => 'characterref', 'fallback_task_key' => 'shotstill' ], $frozen['input_refs']['image'] );
		$this->assertSame( [ 'task_key' => 'nextstill' ], $frozen['input_refs']['end_frame'] );
		$this->assertArrayNotHasKey( 'not_a_slot', $frozen['input_refs'] );
		$this->assertSame( hash( 'sha256', 'Camera tracks forward.' ), $frozen['prompt_hash'] );
		$this->assertSame( str_repeat( 'a', 64 ), $frozen['prompt_policy_fingerprint'] );

		$queue = $this->method_source( 'queue_batch' );
		$this->assertStringContainsString( "'scope'             => \$scope", $queue );
		$this->assertStringContainsString( 'batch_for_idempotency_key( $post_id, $requester_id, $idempotency_key, $batch_kind )', $queue );
		$this->assertStringContainsString( 'reserve_idempotency_key( $post_id, $requester_id, $idempotency_key, $request_hash, $batch_kind )', $queue );
		$this->assertStringContainsString( '$meta[ self::ASSEMBLY_PLAN_META ] = (array) ( $plan[\'assembly\'] ?? [] );', $queue );
	}

	/** Only an exact frozen batch prompt can bypass live policy finalization. */
	public function test_frozen_batch_prompt_integrity_and_direct_prompt_boundary(): void {
		$reflection     = new ReflectionClass( Asset_Generator::class );
		$candidate      = $reflection->getMethod( 'is_frozen_batch_prompt_candidate' );
		$matches        = $reflection->getMethod( 'prompt_matches_frozen_task' );
		$policy_matches = $reflection->getMethod( 'prompt_policy_matches_frozen_task' );
		$candidate->setAccessible( true );
		$matches->setAccessible( true );
		$policy_matches->setAccessible( true );

		$this->assertFalse( $candidate->invoke( null, 0, true, true ), 'A direct client-supplied prompt_profiled flag must not bypass finalization.' );
		$this->assertFalse( $candidate->invoke( null, 91, false, true ) );
		$this->assertFalse( $candidate->invoke( null, 91, true, false ) );
		$this->assertTrue( $candidate->invoke( null, 91, true, true ) );

		$prompt = 'Camera tracks forward.';
		$task   = [ 'prompt' => $prompt, 'prompt_hash' => hash( 'sha256', $prompt ) ];
		$this->assertTrue( $matches->invoke( null, $task, $prompt ) );
		$this->assertFalse( $matches->invoke( null, $task, $prompt . ' Add smoke.' ) );
		$this->assertFalse( $matches->invoke( null, [ 'prompt' => $prompt, 'prompt_hash' => 'not-a-digest' ], $prompt ) );
		$this->assertFalse( $matches->invoke( null, [ 'prompt' => $prompt . ' changed', 'prompt_hash' => hash( 'sha256', $prompt ) ], $prompt ) );

		$policy_fingerprint = hash( 'sha256', 'effective prompt policy' );
		$this->assertTrue( $policy_matches->invoke( null, [], 2, $policy_fingerprint ), 'Legacy plans may use their exact prompt without claiming a missing policy snapshot.' );
		$this->assertFalse( $policy_matches->invoke( null, [], Generation_Workflows::PROMPT_POLICY_FINGERPRINT_VERSION, $policy_fingerprint ) );
		$this->assertTrue( $policy_matches->invoke( null, [ 'prompt_policy_fingerprint' => $policy_fingerprint ], Generation_Workflows::WORKFLOW_VERSION, $policy_fingerprint ) );
		$this->assertFalse( $policy_matches->invoke( null, [ 'prompt_policy_fingerprint' => hash( 'sha256', 'changed policy' ) ], Generation_Workflows::WORKFLOW_VERSION, $policy_fingerprint ) );

		$generator              = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );
		$workflow               = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );
		$candidate_position     = strpos( $generator, 'if ( $frozen_batch_prompt )' );
		$validation_position    = strpos( $generator, 'self::validate_batch_task(', $candidate_position );
		$verified_position      = strpos( $generator, '$frozen_prompt_verified = true;', $validation_position );
		$dynamic_guard_position = strpos( $generator, 'if ( ! $frozen_prompt_verified )', $verified_position );
		$finalize_position      = strpos( $generator, 'Generation_Prompt_Policy::finalize_text(', $dynamic_guard_position );

		$this->assertNotFalse( $candidate_position );
		$this->assertNotFalse( $validation_position );
		$this->assertNotFalse( $verified_position );
		$this->assertNotFalse( $dynamic_guard_position );
		$this->assertNotFalse( $finalize_position );
		$this->assertTrue( $candidate_position < $validation_position && $validation_position < $verified_position );
		$this->assertTrue( $verified_position < $dynamic_guard_position && $dynamic_guard_position < $finalize_position );
		$this->assertStringContainsString( 'prompt_matches_frozen_task( $task, $prompt )', $generator );
		$this->assertStringContainsString( "'prompt_policy_fingerprint'", $generator );
		$this->assertStringContainsString( 'worldgraph_asset_batch_prompt_policy_changed', $generator );
		$this->assertSame( 2, preg_match_all( "/'prompt_is_composed'\s*=>\s*true/", $workflow ) );
		$this->assertSame( 2, preg_match_all( "/'prompt_profiled'\s*=>\s*true/", $workflow ) );
		$this->assertStringContainsString( "\$task['prompt_policy_fingerprint'] = Generation_Prompt_Policy::fingerprint(", $workflow );
	}

	/** Media references wait for siblings, verify provenance, and become immutable inputs. */
	public function test_demonstration_dependency_resolution_and_input_freeze_contract(): void {
		$resolve   = $this->method_source( 'resolve_demonstration_inputs' );
		$reference = $this->method_source( 'demonstration_reference_state' );
		$persist   = $this->method_source( 'persist_resolved_inputs' );

		$this->assertStringContainsString( 'Generation_Modality::media_inputs( $modality )', $resolve );
		$this->assertStringContainsString( 'Generation_Modality::required_inputs( $modality )', $resolve );
		$this->assertStringContainsString( 'demonstration_reference_state( $batch_id, $task_key, $slot, $plan )', $resolve );
		$this->assertStringContainsString( 'demonstration_reference_state( $batch_id, $fallback, $slot, $plan )', $resolve );
		$this->assertStringContainsString( "return [ 'status' => 'pending', 'inputs' => [] ];", $resolve );
		$this->assertStringContainsString( '$inputs[ $slot ] = (string) $state[\'attachment_id\'];', $resolve );
		$this->assertStringContainsString( '$batch_id === $attachment_batch', $reference );
		$this->assertStringContainsString( '$job_id === $attachment_job', $reference );
		$this->assertStringContainsString( 'get_post_mime_type( $attachment_id )', $reference );
		$this->assertStringContainsString( "array_key_exists( 'resolved_inputs', \$plan[ \$step ] )", $persist );
		$this->assertStringContainsString( 'worldgraph_generation_input_conflict', $persist );
		$this->assertStringContainsString( '$plan[ $step ][\'resolved_inputs\'] = $inputs;', $persist );
		$this->assertStringContainsString( 'self::BATCH_PLAN_META, wp_slash( $plan )', $persist );
	}

	/** Optional or already-linked enhancements produce terminal placeholders. */
	public function test_demonstration_optional_placeholder_and_waiting_contract(): void {
		$materialize = $this->method_source( 'materialize_demonstration_batch' );
		$placeholder = $this->method_source( 'create_placeholder_child' );

		$this->assertStringContainsString( "empty( \$task['generation_required'] )", $materialize );
		$this->assertStringContainsString( "empty( \$task['required'] ) ? 'skipped' : 'failed'", $materialize );
		$this->assertStringContainsString( "'pending' === ( \$resolved['status'] ?? '' )", $materialize );
		$this->assertStringContainsString( "'inputs'                => \$inputs", $materialize );
		$this->assertStringContainsString( "'initial_status'        => 'queued'", $materialize );
		$this->assertStringContainsString( "'batch_waiting_assembly', 'batch_materializing'", $materialize );
		$this->assertStringContainsString( "[ 'skipped', 'failed', 'cancelled' ]", $placeholder );
		$this->assertStringContainsString( "'_worldgraph_gen_task_key'", $placeholder );
		$this->assertStringContainsString( "update_post_meta( (int) \$job_id, '_worldgraph_gen_status', \$status )", $placeholder );
	}

	/** Assembly is handed to a separately claimed worker and records terminal outcomes. */
	public function test_demonstration_assembly_state_machine_contract(): void {
		$process = $this->method_source( 'process_batches' );
		$claim   = $this->method_source( 'maybe_assemble_demonstration' );
		$worker  = $this->method_source( 'process_assembly_queue' );
		$finish  = $this->method_source( 'finish_assembly' );
		$verify  = $this->method_source( 'verified_assembly_result' );
		$store   = $this->method_source( 'store_assembly_record' );
		$cleanup = $this->method_source( 'cleanup_cancelled_assembly_state' );
		$cancel  = $this->method_source( 'cancel_batch' );

		$this->assertStringContainsString( "'batch_waiting_assembly'", $process );
		$this->assertStringContainsString( 'self::maybe_assemble_demonstration( (int) $batch_id, $lock_token )', $process );
		$this->assertStringContainsString( "'_worldgraph_gen_status', 'value' => self::ACTIVE_JOB_STATES", $claim );
		$this->assertStringContainsString( "'batch_assembling', 'batch_waiting_assembly'", $claim );
		$this->assertStringContainsString( 'self::schedule_assembly()', $claim );
		$this->assertStringContainsString( 'self::acquire_named_lock(', $worker );
		$this->assertStringContainsString( 'self::ASSEMBLY_LOCK,', $worker );
		$this->assertStringContainsString( '2 * Rough_Cut_Assembler::PROCESS_TIMEOUT + self::COORDINATOR_LOCK_TTL', $worker );
		$this->assertStringContainsString( "'_worldgraph_gen_assembly_worker_token'", $worker );
		$this->assertStringContainsString( 'hash_equals( $lock_token, (string) get_post_meta( $batch_id, \'_worldgraph_gen_assembly_worker_token\', true ) )', $worker );
		$this->assertStringContainsString( "method_exists( Rough_Cut_Assembler::class, 'advance' )", $worker );
		$this->assertStringContainsString( "'pending' === ( \$result['status'] ?? '' )", $worker );
		$this->assertLessThan( strpos( $worker, "if ( is_array( \$result ) && 'pending'" ), strrpos( $worker, 'hash_equals( $lock_token' ) );
		$this->assertStringContainsString( 'worldgraph_rough_cut_schedule_failed', $worker );
		$this->assertStringContainsString( 'Rough_Cut_Assembler::cancel( $batch_id )', $worker );
		$this->assertStringContainsString( 'self::finish_assembly( $batch_id, $result )', $worker );
		$this->assertStringContainsString( 'self::cleanup_cancelled_assembly_state()', $worker );
		$this->assertStringContainsString( 'Rough_Cut_Assembler::cancel( $batch_id )', $cleanup );
		$this->assertStringContainsString( "'_worldgraph_gen_assembly_worker_token'", $cleanup );
		$this->assertStringContainsString( '2 * Rough_Cut_Assembler::PROCESS_TIMEOUT', $cleanup );
		$this->assertStringContainsString( "'' !== \$worker && \$liveness && \$liveness + \$stale_after >= time()", $cleanup );
		$this->assertStringContainsString( 'Rough_Cut_Assembler::cancel( $batch_id )', $cancel );
		$this->assertStringContainsString( '$cancelled_assembly_needs_cleanup', $cancel );
		$this->assertStringContainsString( "metadata_exists( 'post', \$batch_id, Rough_Cut_Assembler::STATE_META )", $cancel );
		$this->assertStringContainsString( 'get_option( self::ASSEMBLY_LOCK, [] )', $cancel );
		$this->assertStringContainsString( '$lease_is_live', $cancel );
		$this->assertStringContainsString( '$worker_is_stale', $cancel );
		$this->assertStringContainsString( '&& ! $lease_is_live', $cancel );
		$this->assertStringContainsString( "delete_post_meta( \$batch_id, '_worldgraph_gen_assembly_worker_token' )", $cancel );
		$this->assertStringContainsString( 'self::schedule_assembly()', $cancel );
		$this->assertStringContainsString( 'self::verified_assembly_result( $batch_id, $result )', $finish );
		$this->assertStringContainsString( "'status' => 'failed'", $finish );
		$this->assertStringContainsString( "'batch_assembly_failed', 'batch_assembling'", $finish );
		$this->assertStringContainsString( '$record[\'status\'] = \'completed\';', $finish );
		$this->assertStringContainsString( "'batch_complete', 'batch_assembling'", $finish );
		$this->assertStringContainsString( 'delete_post_meta( $batch_id, self::ASSEMBLY_META )', $finish );
		$this->assertStringContainsString( 'worldgraph_rough_cut_result_invalid', $verify );
		$this->assertStringContainsString( "'attachment' !== \$attachment->post_type", $verify );
		$this->assertStringContainsString( "str_starts_with( \$mime, 'video/' )", $verify );
		$this->assertStringContainsString( '$batch_id !== $attachment_batch', $verify );
		$this->assertStringContainsString( '! $is_rough_cut', $verify );
		$this->assertStringContainsString( '(int) $batch->post_parent !== (int) $attachment->post_parent', $verify );
		$this->assertStringContainsString( '$record === get_post_meta( $batch_id, self::ASSEMBLY_META, true )', $store );
		$this->assertLessThan(
			strpos( $claim, "update_post_meta( \$batch_id, '_worldgraph_gen_assembly_started_at'" ),
			strpos( $claim, "update_post_meta( \$batch_id, '_worldgraph_gen_status', 'batch_assembling', 'batch_waiting_assembly'" )
		);
	}

	/** An explicit optional Template is never silently discarded as a fallback. */
	public function test_explicit_template_incompatibility_is_rejected(): void {
		$queue    = $this->method_source( 'queue_batch' );
		$runnable = $this->method_source( 'runnable_templates' );
		$resolve  = $this->method_source( 'resolve_template_id' );

		$this->assertStringContainsString( '$invalid_explicit = [];', $queue );
		$this->assertStringContainsString( 'if ( $generation_required && $explicit )', $queue );
		$this->assertStringContainsString( 'worldgraph_generation_template_incompatible', $queue );
		$this->assertStringContainsString( "'incompatible' => \$invalid_explicit", $queue );
		$this->assertStringContainsString( 'Local_ComfyUI::endpoint( $connection_id )', $runnable );
		$this->assertStringContainsString( 'return in_array( $explicit_template_id, $ids, true ) ? $explicit_template_id : 0;', $resolve );
	}

	/** Resolved media inputs must remain identical when the frozen job is queued. */
	public function test_frozen_resolved_inputs_are_revalidated_at_job_creation(): void {
		$persist   = $this->method_source( 'persist_resolved_inputs' );
		$generator = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );

		$this->assertNotFalse( $generator );
		$this->assertStringContainsString( 'worldgraph_generation_input_conflict', $persist );
		$this->assertStringContainsString( "array_key_exists( 'resolved_inputs', \$task ) && \$inputs !== (array) \$task['resolved_inputs']", $generator );
	}

	/** Saved defaults resolve per task after Template choice and remain frozen. */
	public function test_run_defaults_are_resolved_per_source_and_frozen_with_the_batch(): void {
		$queue     = $this->method_source( 'queue_batch' );
		$generator = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );
		$workflow  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );

		$template_position = strpos( $queue, "\$task['template_id'] = \$template_id" );
		$defaults_position = strpos( $queue, 'Generation_Run_Defaults::runtime_overrides(' );
		$this->assertNotFalse( $template_position );
		$this->assertNotFalse( $defaults_position );
		$this->assertLessThan( $defaults_position, $template_position );
		$this->assertStringContainsString( "(int) \$task['source_id']", $queue );
		$this->assertStringContainsString( "array_merge( \$task['default_values'], \$task['requested_run_values'] )", $queue );
		$this->assertStringContainsString( "'run_controls_fingerprint'", $queue );

		$this->assertStringContainsString( "'run_defaults_frozen' => true", $workflow );
		$this->assertStringContainsString( "'default_values'", $workflow );
		$this->assertStringContainsString( "'requested_run_values'", $workflow );
		$this->assertStringContainsString( "'_worldgraph_gen_default_values'", $generator );
		$this->assertStringContainsString( "'_worldgraph_gen_requested_run_values'", $generator );
		$this->assertStringContainsString( 'array_merge( $default_values, $requested_run_values )', $generator );
	}

	/** Batch summaries expose demonstration progress, skipped work, and assembly. */
	public function test_demonstration_batch_status_and_latest_lookup_contract(): void {
		$status = $this->method_source( 'batch_status' );
		$latest = $this->method_source( 'latest_batch' );

		$this->assertStringContainsString( 'in_array( $batch_kind, self::supported_batch_kinds(), true )', $status );
		$this->assertStringContainsString( '$skipped                 = (int) ( $counts[\'skipped\'] ?? 0 );', $status );
		$this->assertStringContainsString( '$terminal                = $completed + $failed + $skipped + $cancelled;', $status );
		$this->assertStringContainsString( "'batch_kind'      => \$batch_kind", $status );
		$this->assertStringContainsString( "'skipped'         => \$skipped", $status );
		$this->assertStringContainsString( "'assembly'        => \$assembly", $status );
		$this->assertStringContainsString( '$progress = min( 99, $progress );', $status );
		$this->assertStringContainsString( 'self::batch_kind_for_scope( $scope )', $latest );
		$this->assertStringContainsString( "[ 'key' => self::BATCH_SCOPE_META, 'value' => \$scope ]", $latest );
	}

	/** The REST boundary forwards audio settings and reports non-generated inputs. */
	public function test_demonstration_controller_audio_and_generation_required_contract(): void {
		$controller = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );

		$this->assertNotFalse( $controller );
		$this->assertStringContainsString( "'audio_template_id' => absint( \$request->get_param( 'audio_template_id' ) )", $controller );
		$this->assertStringContainsString( "'audio_run_values'  => (array) \$request->get_param( 'audio_run_values' )", $controller );
		$this->assertStringContainsString( "Generation_Workflows::common_templates( (array) \$plan['tasks'], 'audio' )", $controller );
		$this->assertStringContainsString( "\$defaults        = [ 'image' => [], 'video' => [], 'audio' => [] ];", $controller );
		$this->assertStringContainsString( "array_key_exists( 'generation_required', \$task )", $controller );
		$this->assertStringContainsString( "'generation_required' => \$generation_required", $controller );
		$this->assertStringContainsString( "'audio_templates'      => \$audio_templates", $controller );
		$this->assertStringContainsString( "Generation_Workflows::latest_batch( \$post_id, 'demonstration' )", $controller );
	}

	/** Direct REST generation must route the selected output, not hard-code image. */
	public function test_direct_rest_contract_supports_video(): void {
		$controller = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );
		$generator  = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );

		$this->assertNotFalse( $controller );
		$this->assertNotFalse( $generator );
		$this->assertStringContainsString( "'enum' => [ 'image', 'video', 'audio' ]", $controller );
		$this->assertStringContainsString( "'type'         => \$type", $controller );
		$this->assertStringContainsString( "'prompt_is_composed' => false", $generator );
		$this->assertStringContainsString( 'self::build_prompt( $post_id, $intent, $provided_prompt, $template_id )', $generator );
	}

	/** The guided UI must receive every same-type intent, not only the first image. */
	public function test_prompt_rest_contract_exposes_every_direct_action(): void {
		$controller = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );

		$this->assertNotFalse( $controller );
		$this->assertStringContainsString( "foreach ( (array) ( \$plan['tasks'] ?? [] ) as \$task )", $controller );
		$this->assertStringContainsString( "'actions'              => \$actions", $controller );
		$this->assertStringContainsString( "'featured'             => ! empty( \$task['featured'] )", $controller );
		$this->assertStringContainsString( '// Preserve the original first-image/first-video response for API clients.', $controller );
	}

	/** Long-running batches publish only complete jobs and stream large media. */
	public function test_durable_batch_commit_and_media_guards_are_present(): void {
		$workflow  = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );
		$generator = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );

		$this->assertNotFalse( $workflow );
		$this->assertNotFalse( $generator );
		$this->assertStringContainsString( 'private static function reserve_idempotency_key', $workflow );
		$this->assertStringContainsString( 'private static function acquire_coordinator_lock', $workflow );
		$this->assertStringContainsString( 'self::is_cancel_requested( $batch_id )', $workflow );
		$this->assertStringContainsString( "update_post_meta( \$batch_id, '_worldgraph_gen_status', 'batch_materializing' )", $workflow );
		$this->assertStringContainsString( "\$job_meta['_worldgraph_gen_status'] = \$initial_status", $generator );
		$this->assertStringContainsString( "'image' === \$requested_type && ! \$image_attachment_id", $generator );
		$this->assertStringContainsString( 'self::download_to_file( $video_url, self::MAX_VIDEO_BYTES', $generator );
		$this->assertStringContainsString( "'limit_response_size' => \$maximum_bytes + 1", $generator );
	}

	/** Cancellation and coordinator leases must have explicit race boundaries. */
	public function test_long_running_queue_race_guards_are_present(): void {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );
		$worker   = file_get_contents( dirname( __DIR__ ) . '/includes/utils/generation-batch.php' );

		$this->assertNotFalse( $workflow );
		$this->assertNotFalse( $worker );
		$this->assertStringContainsString( 'private static function refresh_coordinator_lock', $workflow );
		$this->assertStringContainsString( "[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'NOT EXISTS' ]", $workflow );
		$this->assertStringContainsString( "update_post_meta( \$batch_id, '_worldgraph_gen_status', 'batch_cancelling' );", $workflow );
		$this->assertStringContainsString( "[ 'staged', 'queued', 'submitting' ]", $workflow );
		$this->assertStringContainsString( "self::claim_job( \$job_id, 'submitting', 'dispatching' )", $worker );
		$this->assertStringContainsString( "'submitting', 'dispatching', 'polling'", $worker );
		$this->assertStringContainsString( "elseif ( 'dispatching' === \$status )", $worker );
	}

	/** Root commits, downloads, and WordPress media types must be retry-safe. */
	public function test_batch_schedule_and_stream_boundaries_are_present(): void {
		$workflow  = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );
		$worker    = file_get_contents( dirname( __DIR__ ) . '/includes/utils/generation-batch.php' );
		$generator = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );
		$local     = file_get_contents( dirname( __DIR__ ) . '/includes/utils/local-comfyui.php' );

		$this->assertNotFalse( $workflow );
		$this->assertNotFalse( $worker );
		$this->assertNotFalse( $generator );
		$this->assertNotFalse( $local );
		$this->assertStringContainsString( 'public static function schedule(): bool', $worker );
		$this->assertStringContainsString( 'if ( ! Generation_Batch::schedule() )', $workflow );
		$this->assertStringContainsString( '$persisted = get_post_meta( $batch_id, $key, true )', $workflow );
		$this->assertStringContainsString( "if ( 'batch_materializing' !== get_post_meta( \$batch_id, '_worldgraph_gen_status', true ) )", $workflow );
		$this->assertStringContainsString( "'m4v'  => 'video/mp4'", $generator );
		$this->assertStringContainsString( "'avi'  => 'video/avi'", $generator );
		$this->assertStringContainsString( "[ 'video_url', 'videoUrl' ]", $generator );
		$this->assertStringContainsString( "wp_tempnam( 'worldgraph-generated-media' )", $generator );
		$this->assertStringContainsString( "'limit_response_size' => self::MAX_INPUT_BYTES + 1", $local );
	}
}
