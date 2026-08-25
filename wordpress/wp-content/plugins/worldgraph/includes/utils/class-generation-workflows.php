<?php
/**
 * Story Graph representative-media recipes and durable generation batches.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines provider-neutral creative intents for Story Graph content.
 *
 * Recipes decide what should be represented. Active Templates and Connections
 * still decide how each image or video is executed.
 */
class Generation_Workflows {

	/** Versioned site option for optional intent/output Template preferences. */
	const PREFERENCES_OPTION = 'worldgraph_generation_preferences_v1';

	/** Batch and child-job provenance keys. */
	const BATCH_KIND_META       = '_worldgraph_gen_batch_kind';
	const BATCH_ID_META         = '_worldgraph_gen_batch_id';
	const BATCH_SCOPE_META      = '_worldgraph_gen_batch_scope';
	const BATCH_PLAN_META       = '_worldgraph_gen_batch_plan';
	const BATCH_CURSOR_META     = '_worldgraph_gen_batch_cursor';
	const ASSEMBLY_PLAN_META    = '_worldgraph_gen_assembly_plan';
	const ASSEMBLY_META         = '_worldgraph_gen_assembly';
	const IDEMPOTENCY_META      = '_worldgraph_gen_idempotency_key';
	const REQUEST_HASH_META     = '_worldgraph_gen_request_hash';
	const INTENT_META           = '_worldgraph_gen_intent';
	const STEP_META             = '_worldgraph_gen_batch_step';
	const REPRESENTATIVE_BATCH = 'representative_media';
	const DEMONSTRATION_BATCH  = 'demonstration_video';
	const WORKFLOW_VERSION = 3;
	const PROMPT_POLICY_FINGERPRINT_VERSION = 3;
	const MATERIALIZE_PER_TICK  = 20;
	const ACTIVATE_PER_TICK     = 50;
	const COORDINATOR_LOCK      = 'worldgraph_generation_workflow_coordinator_lock';
	const ASSEMBLY_HOOK         = 'worldgraph_process_rough_cut_assembly';
	const ASSEMBLY_LOCK         = 'worldgraph_rough_cut_assembly_lock';
	const COORDINATOR_LOCK_TTL  = 300;
	const IDEMPOTENCY_LOCK_TTL  = 1800;

	/** Maximum number of child jobs one request may persist. */
	const MAX_BATCH_TASKS = 5000;

	/** Absolute provider-neutral safety ceiling before Template policy. */
	const MAX_CONTEXT_WORDS = 600;

	/** Audio may legitimately contain supplied dialogue or lyrics. */
	const MAX_AUDIO_PROMPT_WORDS = 2400;

	/** Job states that mean a batch is still doing work. */
	const ACTIVE_JOB_STATES = [
		'staged',
		'queued',
		'submitting',
		'dispatching',
		'submitted',
		'polling',
		'importing',
		'import_retry',
		'import_cleanup',
		'import_cleaning',
	];

	/** Primary visual description field for each supported Story Graph type. */
	const PRIMARY_PROMPT_FIELDS = [
		'worldgraph_project'   => 'description',
		'worldgraph_world'     => 'synopsis',
		'worldgraph_character' => 'appearance',
		'worldgraph_prop'      => 'description',
		'worldgraph_location'  => 'description',
		'worldgraph_shot'      => 'shot_description',
		'worldgraph_scene'     => 'summary',
		'worldgraph_episode'   => 'synopsis',
	];

	/** Prompt labels that tell a generation model what the leading text describes. */
	const PRIMARY_PROMPT_LABELS = [
		'worldgraph_project'   => 'Project description',
		'worldgraph_world'     => 'World description',
		'worldgraph_character' => 'Character appearance',
		'worldgraph_prop'      => 'Prop description',
		'worldgraph_location'  => 'Location description',
		'worldgraph_shot'      => 'Shot description',
		'worldgraph_scene'     => 'Scene description',
		'worldgraph_episode'   => 'Episode description',
	];

	/** Short visual fields used after the primary description. */
	const PROMPT_FIELDS = [
		'worldgraph_project'   => [ 'description', 'genre', 'target_medium' ],
		'worldgraph_world'     => [ 'synopsis', 'geography', 'references', 'timeline', 'rules' ],
		'worldgraph_character' => [ 'appearance', 'age', 'personality' ],
		'worldgraph_prop'      => [ 'description', 'purpose', 'notes' ],
		'worldgraph_location'  => [ 'description', 'environment_type', 'geography', 'mood' ],
		'worldgraph_shot'      => [ 'shot_description', 'shot_type', 'camera_angle', 'lens' ],
		'worldgraph_scene'     => [ 'summary', 'location', 'time_of_day', 'emotional_tone', 'lens', 'camera_movement' ],
		'worldgraph_episode'   => [ 'synopsis' ],
		'worldgraph_sound'     => [ 'sound_type', 'spoken_text', 'lyrics', 'diegetic', 'duration', 'production_notes' ],
	];

	/**
	 * Short, visual fields inherited from parent records.
	 *
	 * Ancestors contribute only compact visual cues. Narrative descriptions and
	 * synopses stay with their own records so a Shot does not ask a media model to
	 * depict unrelated plot events or characters.
	 */
	const INHERITED_PROMPT_FIELDS = [
		'worldgraph_project'   => [ 'genre', 'target_medium' ],
		'worldgraph_world'     => [ 'timeline', 'references' ],
		'worldgraph_character' => [ 'appearance' ],
		'worldgraph_episode'   => [],
		'worldgraph_scene'     => [ 'location', 'time_of_day', 'emotional_tone' ],
	];

	/** Canonical authoring fields and parent types used for context ancestry. */
	const PARENT_RULES = [
		'worldgraph_world'     => [ 'field' => 'project', 'types' => [ 'worldgraph_project' ] ],
		'worldgraph_character' => [ 'field' => 'story_world', 'types' => [ 'worldgraph_world' ] ],
		'worldgraph_location'  => [ 'field' => 'story_world', 'types' => [ 'worldgraph_world' ] ],
		// Props may inherit through an owner, their containing World, or a direct
		// Project relationship. The explicit owner field remains preferred.
		'worldgraph_prop'      => [ 'field' => 'owner_character', 'fallback_fields' => [ 'story_world' ], 'types' => [ 'worldgraph_character', 'worldgraph_world', 'worldgraph_project' ] ],
		'worldgraph_episode'   => [ 'field' => 'project', 'types' => [ 'worldgraph_project' ] ],
		// Standalone Scenes may belong directly to a Project; an explicit Episode
		// remains the canonical preferred parent when both relationships exist.
		'worldgraph_scene'     => [ 'field' => 'episode', 'fallback_fields' => [ 'project' ], 'types' => [ 'worldgraph_episode', 'worldgraph_project' ] ],
		'worldgraph_shot'      => [ 'field' => 'scene', 'types' => [ 'worldgraph_scene' ] ],
		'worldgraph_sound'     => [ 'field' => 'scene', 'types' => [ 'worldgraph_scene' ] ],
	];

	/** Request-local Template lookup cache, keyed by source post and output. */
	private static array $template_cache = [];

	/** Register the bounded parent-batch coordinator before the job worker. */
	public static function init(): void {
		add_action( Generation_Batch::HOOK, [ __CLASS__, 'process_batches' ], 5 );
		add_action( self::ASSEMBLY_HOOK, [ __CLASS__, 'process_assembly_queue' ] );
	}

	/** Supported planning scopes accepted by plan(). */
	public static function supported_scopes(): array {
		return [ 'item', 'project', 'demonstration' ];
	}

	/** Durable parent-batch kinds owned by this workflow coordinator. */
	public static function supported_batch_kinds(): array {
		return [ self::REPRESENTATIVE_BATCH, self::DEMONSTRATION_BATCH ];
	}

	/** Resolve the durable batch kind represented by a planning scope. */
	public static function batch_kind_for_scope( string $scope ): string {
		return 'demonstration' === sanitize_key( $scope ) ? self::DEMONSTRATION_BATCH : self::REPRESENTATIVE_BATCH;
	}

	/**
	 * Core representative-media workflow definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		return [
			'worldgraph_project'   => [
				'id'          => 'project-key-art',
				'label'       => __( 'Project key art', 'worldgraph' ),
				'description' => __( 'One defining still for the complete project.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'project-key-art', 'image', __( 'Project key art', 'worldgraph' ), __( 'Generate one cinematic key-art image with one clear focal subject and setting that expresses this project\'s premise, genre, and tone.', 'worldgraph' ), true ),
				],
			],
			'worldgraph_world'     => [
				'id'          => 'world-key-art',
				'label'       => __( 'Story world key art', 'worldgraph' ),
				'description' => __( 'One defining environmental still for the story world.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'world-key-art', 'image', __( 'Story world key art', 'worldgraph' ), __( 'Generate one wide environmental image with one clear focal area that establishes this world\'s geography, era, atmosphere, and visual style.', 'worldgraph' ), true ),
				],
			],
			'worldgraph_character' => [
				'id'          => 'character-look-set',
				'label'       => __( 'Character look set', 'worldgraph' ),
				'description' => __( 'Six consistent views that define the complete character look.', 'worldgraph' ),
				'outputs'     => self::character_look_outputs(),
			],
			'worldgraph_prop'      => [
				'id'          => 'prop-look-set',
				'label'       => __( 'Prop look set', 'worldgraph' ),
				'description' => __( 'Six consistent views that define the complete prop look.', 'worldgraph' ),
				'outputs'     => self::prop_look_outputs(),
			],
			'worldgraph_location'  => [
				'id'          => 'location-look-set',
				'label'       => __( 'Location look set', 'worldgraph' ),
				'description' => __( 'Six consistent views that define the complete location look.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'location-full-view', 'image', __( 'Full establishing view', 'worldgraph' ), __( 'Generate one wide establishing image of this location. Show its overall layout, scale, terrain, structures, and major landmarks.', 'worldgraph' ), true ),
					self::output( 'location-front-view', 'image', __( 'Front view', 'worldgraph' ), __( 'Generate one straight-on view toward this location\'s main facade, entrance, or focal area. Keep the established layout, materials, weather, era, and lighting.', 'worldgraph' ) ),
					self::output( 'location-three-quarter-view', 'image', __( 'Three-quarter view', 'worldgraph' ), __( 'Generate one view from a 45-degree angle. Show the location\'s width, depth, routes, and neighboring features without changing its design.', 'worldgraph' ) ),
					self::output( 'location-profile-view', 'image', __( 'Profile view', 'worldgraph' ), __( 'Generate one side-on view along the location\'s main axis. Show spatial depth, elevation changes, terrain, and access routes without changing its design.', 'worldgraph' ) ),
					self::output( 'location-back-view', 'image', __( 'Back view', 'worldgraph' ), __( 'Generate one reverse view from the primary focal area toward the opposite direction. Reveal what lies behind the establishing viewpoint and preserve the layout.', 'worldgraph' ) ),
					self::output( 'location-close-up', 'image', __( 'Detail close-up', 'worldgraph' ), __( 'Generate one close detail image of the location\'s most distinctive architecture, material, furnishing, vegetation, or terrain feature.', 'worldgraph' ) ),
				],
			],
			'worldgraph_shot'      => [
				'id'          => 'shot-still-and-video',
				'label'       => __( 'Shot still and video', 'worldgraph' ),
				'description' => __( 'One representative still plus one moving shot.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'shot-representative-still', 'image', __( 'Representative still', 'worldgraph' ), __( 'Generate one cinematic still of the exact moment described. Use the specified shot size, camera angle, lens, setting, action, and mood.', 'worldgraph' ), true ),
					self::output( 'shot-video', 'video', __( 'Shot video', 'worldgraph' ), __( 'Generate one continuous video shot of the described action. Use the specified framing, camera angle, lens, duration, and camera movement. Do not add a cut unless requested.', 'worldgraph' ) ),
				],
			],
			'worldgraph_scene'     => [
				'id'          => 'scene-filmstrip',
				'label'       => __( 'Scene filmstrip', 'worldgraph' ),
				'description' => __( 'A single filmstrip-style still summarizing multiple shots.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'scene-filmstrip', 'image', __( 'Scene filmstrip', 'worldgraph' ), __( 'Generate one horizontal filmstrip with up to three panels: the opening, midpoint, and closing visual beats. Keep character, wardrobe, location, time, and color consistent.', 'worldgraph' ), true ),
				],
			],
			'worldgraph_episode'   => [
				'id'          => 'episode-bookend-filmstrip',
				'label'       => __( 'Episode bookend filmstrip', 'worldgraph' ),
				'description' => __( 'A filmstrip contrasting the first and last scenes.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'episode-bookend-filmstrip', 'image', __( 'Episode bookend filmstrip', 'worldgraph' ), __( 'Generate one two-panel horizontal filmstrip: the opening scene on the left and final scene on the right. Show their visual and emotional contrast.', 'worldgraph' ), true ),
				],
			],
			'worldgraph_sound'     => [
				'id'          => 'sound-cue',
				'label'       => __( 'Soundtrack cue', 'worldgraph' ),
				'description' => __( 'One rendered audio cue for this Sound record.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'sound-cue', 'audio', __( 'Soundtrack cue', 'worldgraph' ), __( 'Render this soundtrack cue exactly as specified: narration or dialogue must be spoken verbatim, music must follow the given lyrics and mood, and a sound effect or ambience must match the described source and duration.', 'worldgraph' ), false ),
				],
			],
		];
	}

	/** Build one output definition. */
	private static function output( string $intent, string $type, string $label, string $instruction, bool $featured = false ): array {
		return compact( 'intent', 'type', 'label', 'instruction', 'featured' );
	}

	/** Six precise character-reference views. */
	private static function character_look_outputs(): array {
		return [
			self::output( 'character-full-view', 'image', __( 'Full view', 'worldgraph' ), __( 'Generate one full-body character reference, head to toe, centered and unobstructed in a neutral standing pose. Show the complete face, hair, body, wardrobe, and footwear.', 'worldgraph' ), true ),
			self::output( 'character-front-view', 'image', __( 'Front view', 'worldgraph' ), __( 'Generate one straight-on front full-body character reference in a neutral standing pose. Match the established face, hair, body, wardrobe, colors, and proportions.', 'worldgraph' ) ),
			self::output( 'character-three-quarter-view', 'image', __( 'Three-quarter view', 'worldgraph' ), __( 'Generate one full-body character reference turned 45 degrees from the camera. Match the established face, hair, body, wardrobe, colors, and proportions.', 'worldgraph' ) ),
			self::output( 'character-profile-view', 'image', __( 'Profile view', 'worldgraph' ), __( 'Generate one full-body 90-degree side profile of the character. Match the established face, hair, silhouette, wardrobe, colors, and proportions.', 'worldgraph' ) ),
			self::output( 'character-back-view', 'image', __( 'Back view', 'worldgraph' ), __( 'Generate one straight-on full-body rear view of the character. Show the back of the hair, wardrobe, and footwear while matching the established proportions and colors.', 'worldgraph' ) ),
			self::output( 'character-close-up', 'image', __( 'Close-up', 'worldgraph' ), __( 'Generate one head-and-shoulders character portrait. Clearly show the face, hair, expression, skin detail, and identifying features while matching the established design.', 'worldgraph' ) ),
		];
	}

	/** Six precise prop-reference views. */
	private static function prop_look_outputs(): array {
		return [
			self::output( 'prop-full-view', 'image', __( 'Full view', 'worldgraph' ), __( 'Generate one complete reference image of the prop, centered, fully visible, unobstructed, and shown at a useful scale. Establish its shape, construction, materials, colors, and identifying details.', 'worldgraph' ), true ),
			self::output( 'prop-front-view', 'image', __( 'Front view', 'worldgraph' ), __( 'Generate one straight-on front view of the prop. Match its established shape, construction, materials, colors, scale, and identifying details.', 'worldgraph' ) ),
			self::output( 'prop-three-quarter-view', 'image', __( 'Three-quarter view', 'worldgraph' ), __( 'Generate one view of the prop turned 45 degrees from the camera. Show its width, depth, construction, materials, and identifying details.', 'worldgraph' ) ),
			self::output( 'prop-profile-view', 'image', __( 'Profile view', 'worldgraph' ), __( 'Generate one 90-degree side view of the prop. Match its established silhouette, dimensions, construction, materials, colors, and identifying details.', 'worldgraph' ) ),
			self::output( 'prop-back-view', 'image', __( 'Back view', 'worldgraph' ), __( 'Generate one straight-on rear view of the prop. Reveal its back construction and details while matching the established shape, materials, colors, and scale.', 'worldgraph' ) ),
			self::output( 'prop-close-up', 'image', __( 'Close-up', 'worldgraph' ), __( 'Generate one close detail image of the prop. Focus on its most distinctive mechanism, material, texture, marking, or crafted feature.', 'worldgraph' ) ),
		];
	}

	/** Return a core recipe or a backwards-compatible generic image recipe. */
	public static function definition_for_post_type( string $post_type ): array {
		$definitions = self::definitions();
		if ( isset( $definitions[ $post_type ] ) ) {
			return $definitions[ $post_type ];
		}

		return [
			'id'          => 'generic-representative-image',
			'label'       => __( 'Representative image', 'worldgraph' ),
			'description' => __( 'One defining still for this Story Graph item.', 'worldgraph' ),
			'outputs'     => [
				self::output( 'generic-representative-image', 'image', __( 'Representative image', 'worldgraph' ), __( 'Create one clear, cinematic representative image for this Story Graph item using all supplied details.', 'worldgraph' ), true ),
			],
		];
	}

	/** Return one creative-intent definition. */
	public static function intent( string $post_type, string $intent ): array {
		foreach ( (array) ( self::definition_for_post_type( $post_type )['outputs'] ?? [] ) as $output ) {
			if ( $intent === (string) ( $output['intent'] ?? '' ) ) {
				return $output;
			}
		}

		return [];
	}

	/**
	 * Compose a detailed provider prompt from saved Story Graph/SCF content.
	 *
	 * @param int    $post_id      Story Graph post ID.
	 * @param string $intent       Optional representative-media intent.
	 * @param string $base_prompt  Optional author-edited base prompt.
	 * @param int    $template_id  Optional selected Template for final policy.
	 */
	public static function compose_prompt( int $post_id, string $intent = '', string $base_prompt = '', int $template_id = 0 ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$post_type = (string) $post->post_type;
		$output    = '' !== $intent ? self::intent( $post_type, $intent ) : [];
		if ( empty( $output ) ) {
			$definition = self::definition_for_post_type( $post_type );
			$output     = (array) ( $definition['outputs'][0] ?? [] );
			$intent     = (string) ( $output['intent'] ?? '' );
		}
		if ( 'worldgraph_sound' === $post_type && 'audio' === (string) ( $output['type'] ?? '' ) ) {
			return self::demonstration_sound_prompt( $post, self::demonstration_sound_role( $post_id ), $base_prompt, $template_id, $intent );
		}

		$labels               = worldgraph_get_all_cpts();
		$video                = 'video' === (string) ( $output['type'] ?? '' );
		$modality             = $template_id ? Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template_id, 'modality' ) ) : '';
		$reference_conditioned = in_array(
			$modality,
			[
				Generation_Modality::IMAGE_TO_IMAGE,
				Generation_Modality::IMAGE_TEXT_TO_IMAGE,
				Generation_Modality::TEXT_IMAGE_TO_VIDEO,
				Generation_Modality::VIDEO_TO_VIDEO,
			],
			true
		);
		$strip_completed_beat = 'worldgraph_shot' === $post_type;
		$fields               = worldgraph_get_fields( $post_type );
		$sections             = [];
		$seen                 = [];

		$composite_primary = in_array( $intent, [ 'scene-filmstrip', 'episode-bookend-filmstrip' ], true ) ? self::dependent_context( $post_id, $intent ) : '';
		$primary           = '' !== $composite_primary ? $composite_primary : self::primary_prompt_text( $post, $post_type, $fields, $strip_completed_beat );
		if ( '' !== $primary ) {
			$label       = (string) ( self::PRIMARY_PROMPT_LABELS[ $post_type ] ?? __( 'Description', 'worldgraph' ) );
			$sections[]  = self::prompt_section( 'primary', '' !== $composite_primary ? $primary : $label . ': ' . $primary, true );
			$seen[ md5( strtolower( $primary ) ) ] = true;
		}
		if ( ! empty( $output['instruction'] ) ) {
			$objective = self::objective_prompt_text( (string) $output['instruction'], $post_type, $intent );
			$sections[] = self::prompt_section( 'objective', __( 'Output', 'worldgraph' ) . ': ' . ucfirst( $objective ), true );
		}

		$identity_is_required = in_array( $post_type, [ 'worldgraph_character', 'worldgraph_prop', 'worldgraph_location' ], true );
		if ( $identity_is_required || '' === $primary ) {
			$sections[] = self::prompt_section(
				'identity',
				sprintf(
					'%s: %s',
					(string) ( $labels[ $post_type ] ?? __( 'Story element', 'worldgraph' ) ),
					self::clean_text( (string) $post->post_title, 18 )
				),
				$identity_is_required
			);
		}
		$field_sections = self::prompt_field_sections( $post_id, $post_type, $fields, $seen, $video );
		if ( 'scene-filmstrip' === $intent ) {
			// The composite primary already carries effective panel framing and one
			// shared Scene location/time/tone boundary. Do not repeat those fields.
			$field_sections = array_values( array_filter( $field_sections, static fn( array $section ): bool => ! in_array( $section['id'], [ 'setting', 'camera', 'look' ], true ) ) );
		}
		$sections = array_merge( $sections, $field_sections );
		$visual_direction = self::visual_direction_context( $post_id, $reference_conditioned );
		if ( '' !== $visual_direction ) {
			$sections[] = self::prompt_section( 'look', $visual_direction, true );
		}

		$inherited_context = 'worldgraph_shot' === $post_type ? self::shot_setting_context( $post_id ) : self::inherited_context( $post_id );
		if ( '' !== $inherited_context ) {
			$sections[] = self::prompt_section( 'worldgraph_shot' === $post_type ? 'setting' : 'ancestor_context', $inherited_context );
		}
		$character_context = 'scene-filmstrip' === $intent
			? ''
			: self::related_character_context( $post_id, $post_type, $strip_completed_beat );
		if ( '' !== $character_context ) {
			$sections[] = self::prompt_section( 'characters', $character_context );
		}
		$inherited_instructions = self::inherited_instructions( $post_id );
		if ( '' !== $inherited_instructions ) {
			$sections[] = self::prompt_section( 'continuity', $inherited_instructions );
		}
		$dependent_context = '' !== $composite_primary ? '' : self::dependent_context( $post_id, $intent );
		if ( '' !== $dependent_context ) {
			$sections[] = self::prompt_section(
				'dependent_context',
				$dependent_context,
				in_array( $intent, [ 'scene-filmstrip', 'episode-bookend-filmstrip' ], true )
			);
		}

		$base_prompt = self::complete_phrase( $base_prompt, 24 );
		if ( '' !== $base_prompt ) {
			$sections[] = self::prompt_section( 'author_instructions', __( 'Additional instructions', 'worldgraph' ) . ': ' . $base_prompt, true );
		}
		$instructions = in_array( $post_type, [ 'worldgraph_project', 'worldgraph_scene' ], true )
			? ''
			: self::complete_phrase( (string) worldgraph_get_field_value( $post_id, 'generation_prompt' ), 24 );
		if ( '' !== $instructions ) {
			$label      = 'worldgraph_shot' === $post_type ? __( 'Shot exceptions override Scene changes', 'worldgraph' ) : __( 'Saved visual instructions', 'worldgraph' );
			$sections[] = self::prompt_section( 'author_instructions', $label . ': ' . $instructions, true );
		}
		$sections[] = self::prompt_section( 'constraints', self::output_requirements( $post_type, $intent, $video ), true );

		$policy   = Generation_Prompt_Policy::for_template(
			$template_id,
			[
				'output_type' => (string) ( $output['type'] ?? 'image' ),
				'modality'    => $modality,
				'post_type'   => $post_type,
				'intent'      => $intent,
			]
		);
		$rendered = Generation_Prompt_Policy::render( $sections, $policy );

		return (string) $rendered['prompt'];
	}

	/** Reject Sound copy that the selected Template cannot carry verbatim. */
	public static function validate_sound_prompt_copy( int $post_id, int $template_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'worldgraph_sound' !== $post->post_type ) {
			return true;
		}
		$parts  = [];
		$spoken = self::audio_verbatim_text( (string) worldgraph_get_field_value( $post_id, 'spoken_text' ) );
		$lyrics = self::audio_verbatim_text( (string) worldgraph_get_field_value( $post_id, 'lyrics' ) );
		if ( '' !== $spoken ) {
			$parts[] = __( 'Speak this text exactly', 'worldgraph' ) . ': ' . $spoken;
		}
		if ( '' !== $lyrics ) {
			$parts[] = __( 'Lyrics', 'worldgraph' ) . ': ' . $lyrics;
		}
		if ( empty( $parts ) ) {
			return true;
		}

		$copy   = implode( "\n\n", $parts );
		$policy = Generation_Prompt_Policy::for_template(
			$template_id,
			[
				'output_type' => 'audio',
				'post_type'   => 'worldgraph_sound',
				'intent'      => 'sound-cue',
			]
		);
		$limits = (array) ( $policy['limits'] ?? [] );
		$words  = preg_split( '/\s+/u', trim( $copy ), -1, PREG_SPLIT_NO_EMPTY );
		$counts = [
			'words'      => is_array( $words ) ? count( $words ) : 0,
			'characters' => function_exists( 'mb_strlen' ) ? mb_strlen( $copy, 'UTF-8' ) : strlen( $copy ),
			'bytes'      => strlen( $copy ),
		];
		$exceeded = (int) ( $limits['max_words'] ?? 0 ) > 0 && $counts['words'] > (int) $limits['max_words'];
		$exceeded = $exceeded || ( (int) ( $limits['max_characters'] ?? 0 ) > 0 && $counts['characters'] > (int) $limits['max_characters'] );
		$exceeded = $exceeded || ( (int) ( $limits['max_bytes'] ?? 0 ) > 0 && $counts['bytes'] > (int) $limits['max_bytes'] );
		if ( ! $exceeded ) {
			return true;
		}

		return new WP_Error(
			'worldgraph_sound_copy_exceeds_prompt_limit',
			__( 'The supplied spoken text or lyrics exceed this Template’s prompt limit and cannot be sent verbatim. Shorten the copy or choose a Template with a larger prompt limit.', 'worldgraph' ),
			[ 'status' => 400, 'counts' => $counts, 'limits' => $limits ]
		);
	}

	/** Build one semantic prompt section without exposing metadata to providers. */
	private static function prompt_section( string $id, string $text, bool $protected = false ): array {
		return [
			'id'        => sanitize_key( $id ),
			'text'      => trim( $text ),
			'protected' => $protected,
		];
	}

	/** Reduce recipe prose to one complete, direct output instruction. */
	private static function objective_prompt_text( string $instruction, string $post_type, string $intent ): string {
		if ( 'worldgraph_shot' === $post_type ) {
			return 'shot-video' === $intent
				? __( 'one continuous video shot', 'worldgraph' )
				: __( 'one representative still frame', 'worldgraph' );
		}
		$instruction = (string) preg_replace( '/^(?:generate|create|render)\s+/i', '', self::clean_text( $instruction, 60 ) );
		if ( 'worldgraph_shot' === $post_type || in_array( $intent, [ 'scene-filmstrip', 'episode-bookend-filmstrip' ], true ) ) {
			return self::first_sentence( $instruction, 22 );
		}
		return self::complete_phrase( $instruction, 30 );
	}

	/** Return the best short visual description for the requested source. */
	private static function primary_prompt_text( \WP_Post $post, string $post_type, array $fields, bool $strip_completed_beat = false ): string {
		$field_name = (string) ( self::PRIMARY_PROMPT_FIELDS[ $post_type ] ?? '' );
		$candidates = [];
		if ( '' !== $field_name ) {
			$candidates[] = self::field_prompt_value( (int) $post->ID, $field_name, (array) ( $fields[ $field_name ] ?? [] ) );
		}
		$candidates[] = (string) $post->post_excerpt;
		$candidates[] = (string) $post->post_content;

		$maximum_words = 'worldgraph_shot' === $post_type
			? 26
			: ( in_array( $post_type, [ 'worldgraph_location', 'worldgraph_character', 'worldgraph_prop' ], true ) ? 38 : 32 );
		foreach ( $candidates as $candidate ) {
			if ( 'worldgraph_shot' === $post_type && $strip_completed_beat ) {
				$candidate = self::strip_completed_leading_beat( (string) $candidate );
			}
			$candidate = 'worldgraph_location' === $post_type
				? self::first_sentence( (string) $candidate, $maximum_words )
				: self::clean_text( (string) $candidate, $maximum_words );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/** Build short, intent-aware source details after the leading description. */
	private static function prompt_field_sections( int $post_id, string $post_type, array $fields, array &$seen, bool $video ): array {
		if ( 'worldgraph_shot' === $post_type ) {
			return self::shot_prompt_field_sections( $post_id, $fields, $video );
		}

		$parts         = [];
		$primary_field = (string) ( self::PRIMARY_PROMPT_FIELDS[ $post_type ] ?? '' );
		foreach ( self::PROMPT_FIELDS[ $post_type ] ?? [] as $field_name ) {
			if ( $field_name === $primary_field ) {
				continue;
			}
			if ( 'camera_movement' === $field_name && ! $video ) {
				continue;
			}
			$field = (array) ( $fields[ $field_name ] ?? [] );
			$value = 'worldgraph_scene' === $post_type && 'location' === $field_name
				? self::scene_location_prompt_value( $post_id, 14 )
				: self::clean_text( self::field_prompt_value( $post_id, $field_name, $field ), 26 );
			if ( 'personality' === $field_name && '' !== $value ) {
				$value = self::visible_character_traits( $value );
			}
			if ( '' === $value ) {
				continue;
			}
			$hash = md5( strtolower( $value ) );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$section_id = self::prompt_field_section_id( $field_name );
			$parts[]    = self::prompt_section( $section_id, self::prompt_field_label( $field_name, $field ) . ': ' . $value );
		}

		return $parts;
	}

	/** Keep character personality context to a short, visibly directable trait. */
	private static function visible_character_traits( string $value ): string {
		$parts = preg_split( '/\s*(?:;|,\s+and\s+)\s*/iu', $value, 2 );
		$value = is_array( $parts ) && isset( $parts[0] ) ? (string) $parts[0] : $value;
		return self::clean_text( $value, 12 );
	}

	/** Combine Shot camera fields into one unambiguous framing direction. */
	private static function shot_prompt_field_sections( int $post_id, array $fields, bool $video ): array {
		$framing = [];
		$type    = self::clean_text( self::field_prompt_value( $post_id, 'shot_type', (array) ( $fields['shot_type'] ?? [] ) ), 8 );
		$angle   = self::clean_text( self::field_prompt_value( $post_id, 'camera_angle', (array) ( $fields['camera_angle'] ?? [] ) ), 8 );
		$lens     = self::clean_text( self::field_prompt_value( $post_id, 'lens', (array) ( $fields['lens'] ?? [] ) ), 8 );
		$movement = $video ? self::clean_text( self::field_prompt_value( $post_id, 'camera_movement', (array) ( $fields['camera_movement'] ?? [] ) ), 8 ) : '';
		if ( '' === $lens ) {
			$lens = self::scene_default_prompt_value( $post_id, 'lens', 8 );
		}
		if ( $video && '' === $movement ) {
			$movement = self::scene_default_prompt_value( $post_id, 'camera_movement', 8 );
		}
		if ( '' !== $type ) {
			$framing[] = preg_match( '/\bshot$/i', $type ) ? $type : $type . ' ' . __( 'shot', 'worldgraph' );
		}
		if ( '' !== $angle ) {
			$framing[] = $angle . ' ' . __( 'camera angle', 'worldgraph' );
		}
		if ( '' !== $lens ) {
			$lens_text = preg_match( '/\blens(?:es)?$/i', $lens ) ? $lens : $lens . ' ' . __( 'lens', 'worldgraph' );
			$framing[] = $lens_text;
		}

		$parts = [];
		if ( $framing || '' !== $movement ) {
			$camera = $framing ? __( 'Framing', 'worldgraph' ) . ': ' . implode( ', ', $framing ) . '.' : '';
			if ( '' !== $movement ) {
				$camera .= ( '' !== $camera ? ' ' : '' ) . __( 'Camera movement', 'worldgraph' ) . ': ' . $movement . '.';
			}
			$parts[] = self::prompt_section( 'camera', $camera, true );
		}
		if ( $video ) {
			$motion   = self::complete_phrase( self::field_prompt_value( $post_id, 'motion_direction', (array) ( $fields['motion_direction'] ?? [] ) ), 20 );
			$duration = self::prompt_duration( self::field_prompt_value( $post_id, 'duration', (array) ( $fields['duration'] ?? [] ) ) );
			$notes    = self::video_motion_note( self::field_prompt_value( $post_id, 'editorial_notes', (array) ( $fields['editorial_notes'] ?? [] ) ) );
			if ( '' !== $motion ) {
				$parts[] = self::prompt_section( 'motion', __( 'Motion', 'worldgraph' ) . ': ' . $motion, true );
				if ( '' !== $duration ) {
					$parts[] = self::prompt_section( 'motion', __( 'Duration', 'worldgraph' ) . ': ' . $duration . '.' );
				}
			} else {
				$fallback = __( 'Perform only the described Shot action as one continuous take', 'worldgraph' );
				if ( '' !== $notes ) {
					$fallback .= '; ' . __( 'ending', 'worldgraph' ) . ': ' . $notes;
				}
				$fallback = rtrim( $fallback, '.; ' ) . '.';
				if ( '' !== $duration ) {
					$fallback .= ' ' . sprintf( __( 'Complete it in %s.', 'worldgraph' ), $duration );
				}
				$parts[] = self::prompt_section( 'motion', __( 'Motion', 'worldgraph' ) . ': ' . $fallback, true );
			}
		} else {
			$frame_note = self::still_frame_note( self::field_prompt_value( $post_id, 'editorial_notes', (array) ( $fields['editorial_notes'] ?? [] ) ) );
			if ( '' !== $frame_note ) {
				$parts[] = self::prompt_section( 'action', __( 'Frame moment', 'worldgraph' ) . ': ' . $frame_note );
			}
		}

		return $parts;
	}

	/** Keep a still-selection cue while removing edit-only fade/cut directions. */
	private static function still_frame_note( string $notes ): string {
		$notes = self::first_sentence( $notes, 20 );
		$notes = self::strip_edit_transition( $notes );
		$notes = (string) preg_replace( '/^hold\s+on\s+/iu', '', trim( $notes ) );
		return self::complete_phrase( $notes, 14 );
	}

	/** Keep visible Shot motion while leaving fades, cuts, and dissolves to editorial. */
	private static function video_motion_note( string $notes ): string {
		$notes = self::first_sentence( $notes, 20 );
		$notes = self::strip_edit_transition( $notes );
		return self::complete_phrase( $notes, 18 );
	}

	/** Remove an unmistakable editorial transition without eating visual nouns. */
	private static function strip_edit_transition( string $notes ): string {
		$command = '(?:fade(?:\s+(?:out|to)\b.*)?|cut(?:\s+(?:to|away)\b.*)?|dissolve(?:\s+(?:out|to)\b.*)?)';
		$notes   = (string) preg_replace( '/^\s*' . $command . '\s*\.?$/iu', '', $notes );
		$notes   = (string) preg_replace( '/(?:,\s*(?:then\s+)?|\s+then\s+)' . $command . '\s*\.?$/iu', '', $notes );
		return trim( $notes );
	}

	/** Map a Story Graph field to one stable policy section. */
	private static function prompt_field_section_id( string $field_name ): string {
		if ( in_array( $field_name, [ 'location', 'environment_type', 'geography', 'time_of_day' ], true ) ) {
			return 'setting';
		}
		if ( in_array( $field_name, [ 'genre', 'references', 'timeline', 'rules', 'mood', 'emotional_tone' ], true ) ) {
			return 'look';
		}
		if ( in_array( $field_name, [ 'lens', 'camera_movement' ], true ) ) {
			return 'camera';
		}
		if ( in_array( $field_name, [ 'age', 'personality' ], true ) ) {
			return 'characters';
		}

		return 'subject';
	}

	/** Convert common ISO 8601 durations into plain generation vocabulary. */
	private static function prompt_duration( string $duration ): string {
		$duration = self::clean_text( $duration, 8 );
		if ( 1 !== preg_match( '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/i', $duration, $matches ) ) {
			return $duration;
		}

		$parts = [];
		foreach ( [ 1 => 'hours', 2 => 'minutes', 3 => 'seconds' ] as $index => $unit ) {
			if ( isset( $matches[ $index ] ) && '' !== $matches[ $index ] ) {
				$parts[] = $matches[ $index ] . ' ' . $unit;
			}
		}

		return implode( ', ', $parts );
	}

	/** Use compact labels that describe how a visual model should apply a field. */
	private static function prompt_field_label( string $field_name, array $field ): string {
		$labels = [
			'genre'            => __( 'Genre', 'worldgraph' ),
			'geography'        => __( 'Physical setting', 'worldgraph' ),
			'references'       => __( 'Visual style', 'worldgraph' ),
			'timeline'         => __( 'Era and time', 'worldgraph' ),
			'rules'            => __( 'Visible world rules', 'worldgraph' ),
			'age'              => __( 'Age', 'worldgraph' ),
			'personality'      => __( 'Visible character traits', 'worldgraph' ),
			'purpose'          => __( 'Use', 'worldgraph' ),
			'notes'            => __( 'Visible details', 'worldgraph' ),
			'environment_type' => __( 'Environment', 'worldgraph' ),
			'mood'             => __( 'Atmosphere', 'worldgraph' ),
			'location'         => __( 'Location', 'worldgraph' ),
			'time_of_day'      => __( 'Time', 'worldgraph' ),
			'emotional_tone'   => __( 'Mood', 'worldgraph' ),
			'target_medium'    => __( 'Medium', 'worldgraph' ),
			'lens'             => __( 'Default lens', 'worldgraph' ),
			'camera_movement'  => __( 'Default camera movement', 'worldgraph' ),
		];

		return (string) ( $labels[ $field_name ] ?? $field['label'] ?? ucwords( str_replace( '_', ' ', $field_name ) ) );
	}

	/** Return concise exclusions tailored to the requested output. */
	private static function output_requirements( string $post_type, string $intent, bool $video ): string {
		if ( $video ) {
			return __( 'Clean, unbranded continuous shot containing only the described action.', 'worldgraph' );
		}
		if ( 'worldgraph_character' === $post_type ) {
			return __( 'Clean, unbranded reference of one character centered on a plain background.', 'worldgraph' );
		}
		if ( 'worldgraph_prop' === $post_type ) {
			return __( 'Clean, unbranded reference of one prop centered on a plain background.', 'worldgraph' );
		}
		if ( 'worldgraph_location' === $post_type ) {
			return __( 'Clean, unbranded location view with consistent layout and materials.', 'worldgraph' );
		}
		if ( in_array( $intent, [ 'scene-filmstrip', 'episode-bookend-filmstrip' ], true ) ) {
			return __( 'Clean, unbranded filmstrip containing only the requested panels with consistent designs.', 'worldgraph' );
		}

		return __( 'Clean, unbranded single composition focused only on the requested moment.', 'worldgraph' );
	}

	/** Return canonical immediate-parent-to-Project ancestry for one source. */
	public static function ancestors( int $post_id ): array {
		$ancestors    = [];
		$seen         = [ $post_id => true ];
		$current_id   = $post_id;
		$current_type = (string) get_post_type( $post_id );
		for ( $depth = 0; $depth < 12 && isset( self::PARENT_RULES[ $current_type ] ); ++$depth ) {
			$rule       = self::PARENT_RULES[ $current_type ];
			$candidates = [];
			$preferred  = [];
			$field_names = array_merge( [ (string) $rule['field'] ], (array) ( $rule['fallback_fields'] ?? [] ) );
			foreach ( $field_names as $field_name ) {
				$field_preferred = [];
				$value           = worldgraph_get_field_value( $current_id, (string) $field_name );
				foreach ( is_array( $value ) ? $value : [ $value ] as $candidate ) {
					$candidate_id   = $candidate instanceof \WP_Post ? (int) $candidate->ID : absint( $candidate );
					$candidate_type = $candidate_id ? (string) get_post_type( $candidate_id ) : '';
					if ( $candidate_id && in_array( $candidate_type, $rule['types'], true ) ) {
						$field_preferred[ $candidate_id ] = $candidate_type;
						$candidates[ $candidate_id ]      = $candidate_type;
					}
				}
				if ( $field_preferred ) {
					$preferred = $field_preferred;
					break;
				}
			}
			foreach ( get_relationships( $current_id, $current_type, 'incoming' ) as $relationship ) {
				$candidate_id   = absint( $relationship['from_id'] ?? 0 );
				$candidate_type = (string) ( $relationship['from_type'] ?? '' );
				if ( 'contains' === ( $relationship['type'] ?? '' ) && in_array( $candidate_type, $rule['types'], true ) ) {
					$candidates[ $candidate_id ] = $candidate_type;
				}
			}
			foreach ( get_relationships( $current_id, $current_type, 'outgoing' ) as $relationship ) {
				$candidate_id   = absint( $relationship['to_id'] ?? 0 );
				$candidate_type = (string) ( $relationship['to_type'] ?? '' );
				if ( 'belongs_to' === ( $relationship['type'] ?? '' ) && in_array( $candidate_type, $rule['types'], true ) ) {
					$candidates[ $candidate_id ] = $candidate_type;
				}
			}
			ksort( $preferred, SORT_NUMERIC );
			ksort( $candidates, SORT_NUMERIC );
			$candidate_id = (int) array_key_first( $preferred ?: $candidates );
			if ( ! $candidate_id || isset( $seen[ $candidate_id ] ) ) {
				break;
			}
			$parent = get_post( $candidate_id );
			if ( ! $parent instanceof \WP_Post ) {
				break;
			}
			$ancestors[]          = $parent;
			$seen[ $candidate_id ] = true;
			$current_id            = $candidate_id;
			$current_type          = (string) $parent->post_type;
		}

		return $ancestors;
	}

	/** Resolve the owning Project using the canonical ancestry rules. */
	public static function project_id_for_source( int $post_id ): int {
		if ( 'worldgraph_project' === get_post_type( $post_id ) ) {
			return $post_id;
		}
		foreach ( self::ancestors( $post_id ) as $ancestor ) {
			if ( 'worldgraph_project' === $ancestor->post_type ) {
				return (int) $ancestor->ID;
			}
		}

		return 0;
	}

	/** Format only the compact visual cues supplied by parent records. */
	private static function inherited_context( int $post_id ): string {
		$labels = worldgraph_get_all_cpts();
		$lines  = [];
		$source_type = (string) get_post_type( $post_id );
		foreach ( self::ancestors( $post_id ) as $ancestor ) {
			if ( 'worldgraph_prop' === $source_type && 'worldgraph_character' === $ancestor->post_type ) {
				continue;
			}
			$details = [];
			$fields  = worldgraph_get_fields( $ancestor->post_type );
			foreach ( self::INHERITED_PROMPT_FIELDS[ $ancestor->post_type ] ?? [] as $field_name ) {
				$field = (array) ( $fields[ $field_name ] ?? [] );
				$value = self::inherited_field_prompt_value( $ancestor, $field_name, $field );
				if ( '' !== $value ) {
					$details[] = self::prompt_field_label( $field_name, $field ) . ': ' . self::clean_text( $value, 30 );
				}
			}
			if ( empty( $details ) ) {
				continue;
			}
			$line    = sprintf( '%s: %s', (string) ( $labels[ $ancestor->post_type ] ?? $ancestor->post_type ), self::clean_text( (string) $ancestor->post_title, 12 ) );
			$lines[] = $line . ' — ' . implode( '; ', $details );
		}

		return $lines ? __( 'Visual context', 'worldgraph' ) . ":\n- " . implode( "\n- ", $lines ) : '';
	}

	/** Give a Shot only its immediate, visibly useful Scene setting. */
	private static function shot_setting_context( int $post_id ): string {
		$scene = self::scene_for_source( $post_id );
		if ( ! $scene instanceof \WP_Post ) {
			return '';
		}

		$parts         = [];
		$location_text = self::scene_location_prompt_value( (int) $scene->ID, 14 );
		if ( '' !== $location_text ) {
			$parts[] = $location_text;
		}

		$time = self::clean_text( (string) worldgraph_get_field_value( (int) $scene->ID, 'time_of_day' ), 5 );
		$mood = rtrim( self::complete_phrase( (string) worldgraph_get_field_value( (int) $scene->ID, 'emotional_tone' ), 10 ), '.; ' );
		if ( '' !== $time ) {
			$parts[] = $time;
		}
		if ( '' !== $mood ) {
			$parts[] = $mood;
		}

		return $parts ? __( 'Setting', 'worldgraph' ) . ': ' . implode( '; ', $parts ) : '';
	}

	/** Return one Scene's Location title plus a short physical description. */
	private static function scene_location_prompt_value( int $scene_id, int $description_words = 14 ): string {
		$location_id = self::related_field_id( $scene_id, 'worldgraph_scene', 'location', 'worldgraph_location' );
		$location    = $location_id ? get_post( $location_id ) : null;
		if ( $location instanceof \WP_Post && 'worldgraph_location' === $location->post_type ) {
			$title       = self::clean_text( (string) $location->post_title, 10 );
			$description = $description_words > 0
				? rtrim( self::first_sentence( (string) worldgraph_get_field_value( $location_id, 'description' ), $description_words ), '.; ' )
				: '';
			return trim( $title . ( '' !== $description ? ' — ' . $description : '' ) );
		}

		$field = (array) ( worldgraph_get_fields( 'worldgraph_scene' )['location'] ?? [] );
		return self::clean_text( self::field_prompt_value( $scene_id, 'location', $field ), 10 );
	}

	/** Resolve the source Scene itself or its closest canonical Scene ancestor. */
	private static function scene_for_source( int $post_id ): ?\WP_Post {
		$source = get_post( $post_id );
		if ( $source instanceof \WP_Post && 'worldgraph_scene' === $source->post_type ) {
			return $source;
		}
		foreach ( self::ancestors( $post_id ) as $ancestor ) {
			if ( 'worldgraph_scene' === $ancestor->post_type ) {
				return $ancestor;
			}
		}

		return null;
	}

	/** Return one camera default from the containing Scene for a blank Shot field. */
	private static function scene_default_prompt_value( int $post_id, string $field_name, int $maximum_words ): string {
		if ( ! in_array( $field_name, [ 'lens', 'camera_movement' ], true ) ) {
			return '';
		}
		$scene = self::scene_for_source( $post_id );
		if ( ! $scene instanceof \WP_Post ) {
			return '';
		}
		$fields = worldgraph_get_fields( 'worldgraph_scene' );

		return self::clean_text(
			self::field_prompt_value( (int) $scene->ID, $field_name, (array) ( $fields[ $field_name ] ?? [] ) ),
			$maximum_words
		);
	}

	/** Add a Location's physical description to a Scene setting without plot prose. */
	private static function inherited_field_prompt_value( \WP_Post $ancestor, string $field_name, array $field ): string {
		if ( 'worldgraph_scene' === $ancestor->post_type && 'location' === $field_name ) {
			return self::scene_location_prompt_value( (int) $ancestor->ID, 28 );
		}

		return self::field_prompt_value( (int) $ancestor->ID, $field_name, $field );
	}

	/**
	 * Return one concise visual language inherited from the containing Project.
	 *
	 * Project `generation_prompt` is the existing low-clutter authoring surface;
	 * treating it as a distinct look section keeps medium, lighting, palette, and
	 * texture separate from story action and Shot motion.
	 */
	private static function project_visual_direction( int $post_id, int $maximum_words = 10 ): string {
		$project = 'worldgraph_project' === get_post_type( $post_id ) ? get_post( $post_id ) : null;
		if ( ! $project instanceof \WP_Post ) {
			foreach ( self::ancestors( $post_id ) as $ancestor ) {
				if ( 'worldgraph_project' === $ancestor->post_type ) {
					$project = $ancestor;
					break;
				}
			}
		}
		if ( ! $project instanceof \WP_Post ) {
			return '';
		}

		$direction = self::complete_phrase( (string) worldgraph_get_field_value( (int) $project->ID, 'generation_prompt' ), $maximum_words );
		return '' !== $direction
			? __( 'Project look', 'worldgraph' ) . ': ' . $direction
			: '';
	}

	/** Return Scene-specific look changes without story prose. */
	private static function scene_visual_direction( int $post_id, int $maximum_words = 8 ): string {
		$scene = self::scene_for_source( $post_id );
		if ( ! $scene instanceof \WP_Post ) {
			return '';
		}

		$direction = self::complete_phrase( (string) worldgraph_get_field_value( (int) $scene->ID, 'generation_prompt' ), $maximum_words );
		return '' !== $direction
			? __( 'Scene changes override Project look', 'worldgraph' ) . ': ' . $direction
			: '';
	}

	/** Combine broad Project style with the closest Scene refinement exactly once. */
	private static function visual_direction_context( int $post_id, bool $reference_conditioned ): string {
		$project = self::project_visual_direction( $post_id, $reference_conditioned ? 6 : 10 );
		$scene   = self::scene_visual_direction( $post_id, $reference_conditioned ? 6 : 8 );
		if ( '' === $project && '' === $scene ) {
			return '';
		}

		$parts = array_filter( [ $project, $scene ] );
		if ( $reference_conditioned ) {
			array_unshift( $parts, __( 'Match reference continuity.', 'worldgraph' ) );
		}

		return trim( implode( ' ', $parts ) );
	}

	/** Preserve explicit parent visual instructions without inheriting parent narrative. */
	private static function inherited_instructions( int $post_id ): string {
		$instructions = [];
		$labels       = worldgraph_get_all_cpts();
		foreach ( self::ancestors( $post_id ) as $ancestor ) {
			if ( in_array( $ancestor->post_type, [ 'worldgraph_project', 'worldgraph_episode', 'worldgraph_scene' ], true ) ) {
				continue;
			}
			$value = self::clean_text( (string) worldgraph_get_field_value( (int) $ancestor->ID, 'generation_prompt' ), 16 );
			if ( '' === $value ) {
				continue;
			}
			$instructions[] = sprintf( '%s: %s', (string) ( $labels[ $ancestor->post_type ] ?? $ancestor->post_type ), $value );
		}

		return $instructions
			? self::clean_text( __( 'Inherited visual instructions', 'worldgraph' ) . ":\n- " . implode( "\n- ", $instructions ), 38 )
			: '';
	}

	/**
	 * Include only characters directly attached to a Shot or its immediate Scene.
	 *
	 * Project- or Episode-wide casts are intentionally excluded because they can
	 * cause a visual model to add people who are not present in the requested shot.
	 */
	private static function related_character_context( int $post_id, string $post_type, bool $strip_completed_beat = false ): string {
		if ( ! in_array( $post_type, [ 'worldgraph_shot', 'worldgraph_scene' ], true ) ) {
			return '';
		}

		$character_ids = self::related_character_ids( $post_id, $post_type );
		if ( 'worldgraph_shot' === $post_type && empty( $character_ids ) ) {
			$scene = null;
			foreach ( self::ancestors( $post_id ) as $ancestor ) {
				if ( 'worldgraph_scene' === $ancestor->post_type ) {
					$scene = $ancestor;
					break;
				}
			}
			if ( $scene instanceof \WP_Post ) {
				$haystack = implode( ' ', [
					(string) worldgraph_get_field_value( $post_id, 'shot_description' ),
					(string) get_the_title( $post_id ),
				] );
				$mentioned = [];
				foreach ( self::related_character_ids( (int) $scene->ID, 'worldgraph_scene' ) as $character_id ) {
					$character = get_post( $character_id );
					$position  = $character instanceof \WP_Post ? self::character_mention_position( $character, $haystack, $strip_completed_beat ) : null;
					if ( null !== $position ) {
						$mentioned[ $character_id ] = $position;
					}
				}
				asort( $mentioned, SORT_NUMERIC );
				$character_ids = array_map( 'intval', array_keys( $mentioned ) );
			}
		}
		sort( $character_ids, SORT_NUMERIC );
		if ( 'worldgraph_shot' === $post_type && isset( $mentioned ) ) {
			// Keep narrative mention order; numeric sorting above is only the
			// deterministic fallback for explicitly related records.
			$character_ids = array_map( 'intval', array_keys( $mentioned ) );
		}

		$lines   = [];
		$maximum = 'worldgraph_shot' === $post_type ? 2 : 4;
		foreach ( array_slice( $character_ids, 0, $maximum ) as $character_id ) {
			$character = get_post( (int) $character_id );
			if ( ! $character instanceof \WP_Post ) {
				continue;
			}
			$appearance = 'worldgraph_shot' === $post_type
				? self::clean_text( (string) worldgraph_get_field_value( (int) $character_id, 'appearance' ), 10 )
				: self::first_sentence( (string) worldgraph_get_field_value( (int) $character_id, 'appearance' ), 18 );
			$appearance = rtrim( $appearance, '.,; ' );
			$lines[]    = self::clean_text( (string) $character->post_title, 8 ) . ( '' !== $appearance ? ' — ' . $appearance : '' );
		}

		return $lines ? __( 'Visible characters', 'worldgraph' ) . ': ' . implode( '; ', $lines ) : '';
	}

	/** Return unique, deterministic Character relations for one source. */
	private static function related_character_ids( int $source_id, string $source_type ): array {
		$ids = [];
		foreach ( [ 'incoming', 'outgoing' ] as $direction ) {
			foreach ( get_relationships( $source_id, $source_type, $direction ) as $relationship ) {
				if ( 'appears_in' !== (string) ( $relationship['type'] ?? '' ) ) {
					continue;
				}
				$candidate_id   = 'incoming' === $direction ? absint( $relationship['from_id'] ?? 0 ) : absint( $relationship['to_id'] ?? 0 );
				$candidate_type = 'incoming' === $direction ? (string) ( $relationship['from_type'] ?? '' ) : (string) ( $relationship['to_type'] ?? '' );
				if ( $candidate_id && 'worldgraph_character' === $candidate_type ) {
					$ids[ $candidate_id ] = true;
				}
			}
		}
		$ids = array_map( 'intval', array_keys( $ids ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/** Find a full-name or distinctive-name-token mention in Shot text. */
	private static function character_mention_position( \WP_Post $character, string $haystack, bool $strip_completed_beat = false ): ?int {
		$haystack = html_entity_decode( wp_strip_all_tags( $haystack ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		if ( $strip_completed_beat ) {
			// A completed leading beat names who just left, not necessarily who is
			// visible in the representative frame (for example, “After ... Woodsman,”).
			$haystack = self::strip_completed_leading_beat( $haystack );
		}
		$title      = trim( (string) $character->post_title );
		$bare_title = (string) preg_replace( '/^the\s+/i', '', $title );
		$display    = trim( (string) worldgraph_get_field_value( (int) $character->ID, 'display_name' ) );
		$ambiguous  = [ 'red', 'blue', 'green', 'black', 'white', 'brown', 'gray', 'grey', 'gold', 'rose' ];
		$aliases    = [
			[ $title, in_array( strtolower( $title ), $ambiguous, true ) ],
			[ $bare_title, in_array( strtolower( $bare_title ), $ambiguous, true ) ],
		];
		if ( '' !== $display ) {
			$aliases[] = [ $display, in_array( strtolower( $display ), $ambiguous, true ) ];
		}
		$ignored   = [ 'the', 'and', 'with', 'little', 'riding', 'hood', 'girl', 'boy', 'woman', 'man' ];
		foreach ( preg_split( '/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $token ) {
			$normalized = strtolower( $token );
			if ( strlen( $token ) >= 3 && ! in_array( $normalized, $ignored, true ) ) {
				$aliases[] = [ $token, in_array( $normalized, $ambiguous, true ) ];
			}
		}

		$positions = [];
		$seen      = [];
		foreach ( $aliases as [ $alias, $case_sensitive ] ) {
			$alias = trim( (string) $alias );
			$key   = strtolower( $alias ) . ':' . ( $case_sensitive ? '1' : '0' );
			if ( '' === $alias || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$flags        = $case_sensitive ? 'u' : 'iu';
			if ( 1 === preg_match( '/(?<![\p{L}\p{N}_])' . preg_quote( $alias, '/' ) . '(?![\p{L}\p{N}_])/' . $flags, $haystack, $match, PREG_OFFSET_CAPTURE ) ) {
				$positions[] = (int) $match[0][1];
			}
		}
		return $positions ? min( $positions ) : null;
	}

	/** Keep a context description to its first complete sentence when possible. */
	private static function first_sentence( string $value, int $maximum_words ): string {
		$value = self::clean_text( $value, max( $maximum_words * 2, $maximum_words ) );
		if ( 1 === preg_match( '/^.*?[.!?](?:\s|$)/u', $value, $matches ) ) {
			$value = trim( (string) $matches[0] );
		}

		return self::complete_phrase( $value, $maximum_words );
	}

	/** Keep a short clause complete instead of ending a prompt mid-attribute. */
	private static function complete_phrase( string $value, int $maximum_words ): string {
		$value = self::clean_text( $value, max( $maximum_words * 2, $maximum_words ) );
		if ( '' === $value ) {
			return '';
		}
		$words = preg_split( '/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) || count( $words ) <= $maximum_words ) {
			return $value;
		}

		$clauses  = preg_split( '/\s*[,;:]\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY );
		$selected = [];
		$count    = 0;
		foreach ( is_array( $clauses ) ? $clauses : [] as $clause ) {
			$clause_words = preg_split( '/\s+/u', trim( (string) $clause ), -1, PREG_SPLIT_NO_EMPTY );
			$clause_count = is_array( $clause_words ) ? count( $clause_words ) : 0;
			if ( 0 === $clause_count || ( $selected && $count + $clause_count > $maximum_words ) ) {
				break;
			}
			if ( ! $selected && $clause_count > $maximum_words ) {
				break;
			}
			$selected[] = trim( (string) $clause );
			$count     += $clause_count;
		}

		$phrase = $selected ? implode( ', ', $selected ) : self::clean_text( $value, $maximum_words );
		$phrase = rtrim( trim( $phrase ), " \t\n\r\0\x0B,;:-" );
		while ( preg_match( '/\s+(?:a|an|the|and|or|but|with|without|on|in|at|to|from|of|for|by|over|under|into|onto|beside|near|toward|towards|between|across|through|as)$/iu', $phrase ) ) {
			$phrase = trim( (string) preg_replace( '/\s+\S+$/u', '', $phrase ) );
		}
		return '' === $phrase || preg_match( '/[.!?]$/u', $phrase ) ? $phrase : $phrase . '.';
	}

	/** Normalize one registered field for readable prompt context. */
	private static function field_prompt_value( int $post_id, string $field_name, array $field ): string {
		$type  = (string) ( $field['type'] ?? 'text' );
		$value = worldgraph_get_field_value( $post_id, $field_name );

		if ( 'relationship' === $type ) {
			$titles = [];
			foreach ( array_filter( array_map( 'absint', is_array( $value ) ? $value : [ $value ] ) ) as $related_id ) {
				$title = get_the_title( $related_id );
				if ( '' !== trim( (string) $title ) ) {
					$titles[] = (string) $title;
				}
			}
			return self::clean_text( implode( ', ', $titles ), 160 );
		}

		if ( 'taxonomy' === $type ) {
			$terms = get_the_terms( $post_id, (string) ( $field['taxonomy'] ?? '' ) );
			if ( is_array( $terms ) ) {
				return self::clean_text( implode( ', ', wp_list_pluck( $terms, 'name' ) ), 160 );
			}
		}

		if ( 'select' === $type && is_scalar( $value ) ) {
			$value = $field['options'][ (string) $value ] ?? $value;
		}

		if ( is_array( $value ) ) {
			$rows = [];
			foreach ( $value as $row ) {
				if ( is_array( $row ) ) {
					$speaker = self::clean_text( (string) ( $row['speaker'] ?? '' ), 30 );
					$line    = self::clean_text( (string) ( $row['line'] ?? '' ), 120 );
					$detail  = self::clean_text( (string) ( $row['description'] ?? '' ), 80 );
					$text    = trim( ( '' !== $speaker ? $speaker . ': ' : '' ) . $line . ( '' !== $detail ? ' (' . $detail . ')' : '' ) );
					if ( '' !== $text ) {
						$rows[] = $text;
					}
				} elseif ( is_scalar( $row ) ) {
					$rows[] = (string) $row;
				}
			}
			$value = implode( ' | ', $rows );
		}

		return is_scalar( $value ) ? self::clean_text( (string) $value, 600 ) : '';
	}

	/** Add shot progression or episode bookends to filmstrip prompts. */
	private static function dependent_context( int $post_id, string $intent ): string {
		$post_type = get_post_type( $post_id );
		if ( 'scene-filmstrip' === $intent && 'worldgraph_scene' === $post_type ) {
			$shots = array_values( array_filter( self::ownership_children( $post_id, $post_type ), static function ( \WP_Post $post ): bool {
				return 'worldgraph_shot' === $post->post_type;
			} ) );
			$frames = [];
			foreach ( self::representative_items( $shots, 3 ) as $index => $shot ) {
				$description = self::panel_description( (string) worldgraph_get_field_value( $shot->ID, 'shot_description' ), 8 );
				$details     = array_filter( [ self::shot_panel_framing( $shot ), self::panel_character_context( $shot, 1, 3 ) ] );
				$line        = sprintf(
					/* translators: 1: panel number, 2: Shot title, 3: Shot description. */
					__( 'Panel %1$d: %2$s — %3$s', 'worldgraph' ),
					$index + 1,
					self::clean_text( (string) $shot->post_title, 2 ),
					$description
				);
				$frames[] = $line . ( $details ? ' (' . implode( '; ', $details ) . ')' : '' );
			}
			if ( empty( $frames ) ) {
				return '';
			}
			$boundary = self::scene_panel_boundary( get_post( $post_id ), false );
			return __( 'One horizontal filmstrip showing these panels', 'worldgraph' ) . "\n- " . implode( "\n- ", $frames )
				. ( '' !== $boundary ? "\n" . __( 'Shared Scene continuity', 'worldgraph' ) . ': ' . $boundary : '' );
		}

		if ( 'episode-bookend-filmstrip' === $intent && 'worldgraph_episode' === $post_type ) {
			$scenes = array_values( array_filter( self::ownership_children( $post_id, $post_type ), static function ( \WP_Post $post ): bool {
				return 'worldgraph_scene' === $post->post_type;
			} ) );
			if ( empty( $scenes ) ) {
				return '';
			}
			$bookends = [ $scenes[0] ];
			if ( count( $scenes ) > 1 ) {
				$bookends[] = $scenes[ count( $scenes ) - 1 ];
			}
			$parts = [];
			foreach ( $bookends as $index => $scene ) {
				$closing  = count( $bookends ) > 1 && $index === count( $bookends ) - 1;
				$action   = self::scene_bookend_action( $scene, $closing );
				$boundary = self::scene_panel_boundary( $scene, true );
				$parts[]  = ( 0 === $index ? __( 'Left panel', 'worldgraph' ) : __( 'Right panel', 'worldgraph' ) ) . ': '
					. self::clean_text( (string) $scene->post_title, 3 ) . ' — ' . $action
					. ( '' !== $boundary ? ' ' . $boundary : '' );
			}
			return __( 'One two-panel horizontal filmstrip showing', 'worldgraph' ) . "\n" . implode( "\n", $parts );
		}

		return '';
	}

	/** Resolve compact, effective Shot framing for one filmstrip panel. */
	private static function shot_panel_framing( \WP_Post $shot ): string {
		$fields = worldgraph_get_fields( 'worldgraph_shot' );
		$type   = self::clean_text( self::field_prompt_value( (int) $shot->ID, 'shot_type', (array) ( $fields['shot_type'] ?? [] ) ), 3 );
		$angle  = self::clean_text( self::field_prompt_value( (int) $shot->ID, 'camera_angle', (array) ( $fields['camera_angle'] ?? [] ) ), 3 );
		$lens   = self::clean_text( self::field_prompt_value( (int) $shot->ID, 'lens', (array) ( $fields['lens'] ?? [] ) ), 3 );
		if ( '' === $lens ) {
			$lens = self::scene_default_prompt_value( (int) $shot->ID, 'lens', 3 );
		}
		if ( '' !== $type && ! preg_match( '/\bshot$/i', $type ) ) {
			$type .= ' ' . __( 'shot', 'worldgraph' );
		}
		if ( '' !== $lens && ! preg_match( '/\blens(?:es)?$/i', $lens ) ) {
			$lens .= ' ' . __( 'lens', 'worldgraph' );
		}

		return implode( ', ', array_filter( [ $type, $angle, $lens ] ) );
	}

	/** Add only characters visibly named or directly attached to a panel Shot. */
	private static function panel_character_context( \WP_Post $shot, int $maximum_characters = 1, int $appearance_words = 3 ): string {
		$ids = self::related_character_ids( (int) $shot->ID, 'worldgraph_shot' );
		if ( empty( $ids ) ) {
			$scene = self::scene_for_source( (int) $shot->ID );
			if ( $scene instanceof \WP_Post ) {
				$haystack = (string) worldgraph_get_field_value( (int) $shot->ID, 'shot_description' );
				$mentioned = [];
				foreach ( self::related_character_ids( (int) $scene->ID, 'worldgraph_scene' ) as $character_id ) {
					$character = get_post( $character_id );
					$position  = $character instanceof \WP_Post ? self::character_mention_position( $character, $haystack, true ) : null;
					if ( null !== $position ) {
						$mentioned[ $character_id ] = $position;
					}
				}
				asort( $mentioned, SORT_NUMERIC );
				$ids = array_map( 'intval', array_keys( $mentioned ) );
			}
		}

		$parts = [];
		foreach ( array_slice( $ids, 0, max( 1, $maximum_characters ) ) as $character_id ) {
			$character = get_post( (int) $character_id );
			if ( ! $character instanceof \WP_Post ) {
				continue;
			}
			$appearance = $appearance_words > 0
				? self::clean_text( (string) worldgraph_get_field_value( (int) $character_id, 'appearance' ), $appearance_words )
				: '';
			$parts[] = self::clean_text( (string) $character->post_title, 4 ) . ( '' !== $appearance ? ' — ' . $appearance : '' );
		}

		return $parts ? __( 'characters', 'worldgraph' ) . ': ' . implode( ', ', $parts ) : '';
	}

	/** Use a Scene's first/last Shot beat instead of broad synopsis prose. */
	private static function scene_bookend_action( \WP_Post $scene, bool $closing ): string {
		$shots = array_values( array_filter( self::ownership_children( (int) $scene->ID, 'worldgraph_scene' ), static function ( \WP_Post $post ): bool {
			return 'worldgraph_shot' === $post->post_type;
		} ) );
		$shot  = $shots ? ( $closing ? $shots[ count( $shots ) - 1 ] : $shots[0] ) : null;
		if ( $shot instanceof \WP_Post ) {
			$action  = self::panel_description( (string) worldgraph_get_field_value( (int) $shot->ID, 'shot_description' ), 8 );
			$details = array_filter( [ self::shot_panel_framing( $shot ), self::panel_character_context( $shot, 1, 3 ) ] );
			return trim( $action . ( $details ? ' (' . implode( '; ', $details ) . ')' : '' ) );
		}

		return self::panel_description( (string) worldgraph_get_field_value( (int) $scene->ID, 'summary' ), 8 );
	}

	/** Describe the location/time/tone boundary, plus per-Scene style for bookends. */
	private static function scene_panel_boundary( $scene, bool $include_style ): string {
		if ( ! $scene instanceof \WP_Post || 'worldgraph_scene' !== $scene->post_type ) {
			return '';
		}
		$parts    = [];
		$location = self::clean_text( self::scene_location_prompt_value( (int) $scene->ID, 0 ), 8 );
		$time     = self::clean_text( (string) worldgraph_get_field_value( (int) $scene->ID, 'time_of_day' ), 2 );
		$tone     = rtrim( self::complete_phrase( (string) worldgraph_get_field_value( (int) $scene->ID, 'emotional_tone' ), 4 ), '.; ' );
		if ( '' !== $location ) {
			$parts[] = __( 'Location', 'worldgraph' ) . ': ' . $location;
		}
		if ( '' !== $time ) {
			$parts[] = __( 'Time', 'worldgraph' ) . ': ' . $time;
		}
		if ( '' !== $tone ) {
			$parts[] = __( 'Mood', 'worldgraph' ) . ': ' . $tone;
		}
		if ( $include_style ) {
			$style = self::scene_visual_direction( (int) $scene->ID, 4 );
			if ( '' !== $style ) {
				$parts[] = $style;
			}
		}

		return implode( '; ', $parts ) . ( $parts ? '.' : '' );
	}

	/** Prefer the panel's visible main action over a completed leading beat. */
	private static function panel_description( string $value, int $maximum_words ): string {
		$value = self::strip_completed_leading_beat( $value );
		return self::complete_phrase( $value, $maximum_words );
	}

	/** Remove a completed subordinate beat so the visible action leads. */
	private static function strip_completed_leading_beat( string $value ): string {
		return trim( (string) preg_replace( '/^\s*(?:after|once|having)\b[^,]{0,160},\s*/iu', '', $value ) );
	}

	/** Select first, midpoint, and last items instead of describing an entire sequence. */
	private static function representative_items( array $items, int $maximum ): array {
		$items   = array_values( $items );
		$maximum = max( 1, $maximum );
		$count   = count( $items );
		if ( $count <= $maximum ) {
			return $items;
		}

		$indexes = [ 0, (int) floor( ( $count - 1 ) / 2 ), $count - 1 ];
		$selected = [];
		foreach ( array_slice( array_values( array_unique( $indexes ) ), 0, $maximum ) as $index ) {
			$selected[] = $items[ $index ];
		}

		return $selected;
	}

	/** Strip markup, collapse whitespace, and apply a deliberate global bound. */
	private static function clean_text( string $value, int $maximum_words ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$value = trim( (string) preg_replace( '/[\t ]+/', ' ', (string) preg_replace( '/\r\n?|\n{3,}/', "\n", $value ) ) );
		return '' === $value ? '' : wp_trim_words( $value, max( 1, $maximum_words ), '' );
	}

	/**
	 * Build the item or project-wide child-job plan before spending budget.
	 *
	 * @param int    $post_id         Source Story Graph post ID.
	 * @param string $scope           Item, Project, or demonstration scope.
	 * @param string $base_prompt     Optional one-off instructions.
	 * @param bool   $compose_prompts Whether to compose each prompt immediately.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function plan( int $post_id, string $scope = 'item', string $base_prompt = '', bool $compose_prompts = true ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Asset_Generator::supports( $post_id ) ) {
			return new WP_Error( 'worldgraph_generation_source_invalid', __( 'Select a supported Story Graph item first.', 'worldgraph' ), [ 'status' => 404 ] );
		}

		$requested_scope = sanitize_key( $scope );
		$scope           = in_array( $requested_scope, self::supported_scopes(), true ) ? $requested_scope : 'item';
		if ( in_array( $scope, [ 'project', 'demonstration' ], true ) && 'worldgraph_project' !== $post->post_type ) {
			return new WP_Error( 'worldgraph_generation_project_required', __( 'Project-wide generation requires a Project post.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		if ( 'demonstration' === $scope ) {
			return self::demonstration_plan( $post_id, $base_prompt );
		}

		$sources = 'project' === $scope ? self::project_sources( $post_id ) : [ $post ];
		$tasks   = [];
		foreach ( $sources as $source ) {
			$definition = self::definition_for_post_type( $source->post_type );
			foreach ( (array) ( $definition['outputs'] ?? [] ) as $output ) {
				$intent = (string) ( $output['intent'] ?? '' );
				$type   = (string) ( $output['type'] ?? 'image' );
				$task   = [
					'source_id'    => (int) $source->ID,
					'source_type'  => (string) $source->post_type,
					'source_title' => (string) $source->post_title,
					'workflow_id'  => (string) ( $definition['id'] ?? '' ),
					'intent'       => $intent,
					'label'        => (string) ( $output['label'] ?? $intent ),
					'type'         => $type,
					'featured'     => ! empty( $output['featured'] ),
					'prompt'       => $compose_prompts ? Asset_Generator::build_prompt( (int) $source->ID, $intent, $base_prompt ) : '',
				];
				if ( 'worldgraph_sound' === $source->post_type && 'audio' === $type ) {
					$role       = self::demonstration_sound_role( (int) $source->ID );
					$modalities = self::sound_modalities( $role );
					// A Silence record is already the requested fallback; it should
					// never spend provider budget on an arbitrary audio Template.
					if ( empty( $modalities ) ) {
						continue;
					}
					$task['audio_role']           = $role;
					$task['preferred_modalities'] = $modalities;
				}
				$tasks[] = $task;
			}
		}

		$maximum = max( 1, (int) apply_filters( 'worldgraph_generation_batch_max_tasks', self::MAX_BATCH_TASKS, $post_id, $scope ) );
		if ( count( $tasks ) > $maximum ) {
			return new WP_Error(
				'worldgraph_generation_batch_too_large',
				sprintf(
					/* translators: 1: number of jobs in the generation plan, 2: maximum number of jobs allowed. */
					__( 'This plan contains %1$d jobs; the current limit is %2$d.', 'worldgraph' ),
					count( $tasks ),
					$maximum
				),
				[ 'status' => 400 ]
			);
		}

		$counts = array_count_values( array_column( $tasks, 'type' ) );
		return [
			'post_id'    => $post_id,
			'scope'      => $scope,
			'batch_kind' => self::batch_kind_for_scope( $scope ),
			'workflow'   => self::definition_for_post_type( $post->post_type ),
			'sources'    => count( $sources ),
			'total_jobs' => count( $tasks ),
			'counts'     => [
				'image' => (int) ( $counts['image'] ?? 0 ),
				'video' => (int) ( $counts['video'] ?? 0 ),
				'audio' => (int) ( $counts['audio'] ?? 0 ),
			],
			'tasks'      => $tasks,
		];
	}

	/**
	 * Build a Project-only, dependency-aware rough-cut plan.
	 *
	 * Image tasks are the durable visual fallback. Video and Sound tasks are
	 * optional enhancements: a coordinator may skip them when no suitable
	 * Template exists and still hand the declared timeline to the assembler.
	 * Task references are symbolic until a completed child job resolves them to
	 * attachment IDs.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function demonstration_plan( int $project_id, string $base_prompt = '' ) {
		$project = get_post( $project_id );
		if ( ! $project instanceof \WP_Post || 'worldgraph_project' !== $project->post_type ) {
			return new WP_Error( 'worldgraph_generation_project_required', __( 'Demonstration-video generation requires a Project post.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$sources          = self::project_sources( $project_id );
		$story            = self::demonstration_story_order( $sources );
		$scenes           = $story['scenes'];
		$character_usage  = self::demonstration_character_usage( $sources, $scenes );
		$scene_locations  = [];
		$location_order   = [];
		$tasks            = [];
		$timeline         = [];
		$character_tasks  = [];
		$location_tasks   = [];
		$still_tasks      = [];
		$video_tasks      = [];
		$sound_tasks      = [];
		$sounds_by_scene  = [];
		$sounds_by_shot   = [];
		$all_scene_sounds = [];
		$source_ids       = array_fill_keys( array_map( static fn( \WP_Post $source ): int => (int) $source->ID, $sources ), true );

		foreach ( $scenes as $scene_index => $scene_item ) {
			$scene    = $scene_item['post'];
			$location = self::demonstration_scene_location( $scene );
			if ( $location instanceof \WP_Post ) {
				$scene_locations[ $scene->ID ] = (int) $location->ID;
				if ( ! isset( $location_order[ $location->ID ] ) ) {
					$location_order[ $location->ID ] = $scene_index;
				}
				$source_ids[ $location->ID ] = true;
			}
		}

		$story_characters = $character_usage['occurrences'];
		uasort(
			$story_characters,
			static function ( array $left, array $right ): int {
				$left_recurring  = count( $left['segment_keys'] ) > 1 ? 0 : 1;
				$right_recurring = count( $right['segment_keys'] ) > 1 ? 0 : 1;
				if ( $left_recurring !== $right_recurring ) {
					return $left_recurring <=> $right_recurring;
				}
				$first = (int) $left['first_order'] <=> (int) $right['first_order'];
				return 0 !== $first ? $first : (int) $left['character_id'] <=> (int) $right['character_id'];
			}
		);
		foreach ( $story_characters as $character_id => $occurrence ) {
			$character = $character_usage['characters'][ $character_id ] ?? null;
			if ( ! $character instanceof \WP_Post ) {
				continue;
			}
			$is_recurring = count( $occurrence['segment_keys'] ) > 1;
			$task_key     = 'demo-character-' . $character->ID . '-reference';
			$tasks[]      = self::demonstration_task(
				$task_key,
				$character,
				'character-look-set',
				'character-full-view',
				$is_recurring ? __( 'Recurring character reference', 'worldgraph' ) : __( 'Character reference still', 'worldgraph' ),
				'image',
				'references',
				true,
				Asset_Generator::build_prompt( (int) $character->ID, 'character-full-view', $base_prompt ),
				[
					'character_id'       => (int) $character->ID,
					'used_in_scene_ids'  => array_values( array_map( 'intval', array_keys( $occurrence['scene_ids'] ) ) ),
					'used_in_shot_ids'   => array_values( array_map( 'intval', array_keys( $occurrence['shot_ids'] ) ) ),
					'dependencies'       => [],
					'input_refs'         => [],
					'generation_required'=> true,
				]
			);
			$character_tasks[ $character->ID ] = $task_key;
			$source_ids[ $character->ID ]      = true;
		}

		asort( $location_order, SORT_NUMERIC );
		foreach ( array_keys( $location_order ) as $location_id ) {
			$location = get_post( (int) $location_id );
			if ( ! $location instanceof \WP_Post || 'worldgraph_location' !== $location->post_type ) {
				continue;
			}
			$task_key = 'demo-location-' . $location->ID . '-reference';
			$tasks[]  = self::demonstration_task(
				$task_key,
				$location,
				'location-look-set',
				'location-full-view',
				__( 'Story location reference', 'worldgraph' ),
				'image',
				'references',
				true,
				Asset_Generator::build_prompt( (int) $location->ID, 'location-full-view', $base_prompt ),
				[
					'location_id'        => (int) $location->ID,
					'dependencies'       => [],
					'input_refs'         => [],
					'generation_required'=> true,
				]
			);
			$location_tasks[ $location->ID ] = $task_key;
		}

		$timeline_order = 0;
		foreach ( $scenes as $scene_index => $scene_item ) {
			$scene         = $scene_item['post'];
			$shots         = $scene_item['shots'];
			$scene_task    = '';
			$location_id   = (int) ( $scene_locations[ $scene->ID ] ?? 0 );
			$location_ref  = (string) ( $location_tasks[ $location_id ] ?? '' );
			$source_ids[ $scene->ID ] = true;

			if ( empty( $shots ) ) {
				$scene_task = 'demo-scene-' . $scene->ID . '-still';
				$references = array_values( array_filter( [ $location_ref ] ) );
				$tasks[]    = self::demonstration_task(
					$scene_task,
					$scene,
					'scene-filmstrip',
					'scene-filmstrip',
					__( 'Scene fallback still', 'worldgraph' ),
					'image',
					'references',
					true,
					Asset_Generator::build_prompt( (int) $scene->ID, 'scene-filmstrip', $base_prompt ),
					[
						'scene_id'           => (int) $scene->ID,
						'scene_order'        => $scene_index,
						'timeline_order'     => $timeline_order,
						'dependencies'       => $references,
						'input_refs'         => self::reference_image_refs( $references ),
						'generation_required'=> true,
					]
				);
				$still_tasks[ 'scene:' . $scene->ID ] = $scene_task;
				++$timeline_order;
			}

			foreach ( $shots as $shot_index => $shot ) {
				$source_ids[ $shot->ID ] = true;
				$task_key       = 'demo-shot-' . $shot->ID . '-still';
				$character_ids  = (array) ( $character_usage['shot_characters'][ $shot->ID ] ?? [] );
				$reference_keys = [];
				foreach ( $character_ids as $character_id ) {
					if ( isset( $character_tasks[ $character_id ] ) ) {
						$reference_keys[] = $character_tasks[ $character_id ];
					}
				}
				if ( '' !== $location_ref ) {
					$reference_keys[] = $location_ref;
				}
				$reference_keys = array_values( array_unique( $reference_keys ) );
				$still_input_refs = self::reference_image_refs( $reference_keys );
				if ( ! empty( $reference_keys ) ) {
					$still_input_refs['image'] = [ 'task_key' => (string) $reference_keys[0] ];
				}
				$tasks[]        = self::demonstration_task(
					$task_key,
					$shot,
					'shot-still-and-video',
					'shot-representative-still',
					__( 'Rough-cut shot still', 'worldgraph' ),
					'image',
					'references',
					true,
					Asset_Generator::build_prompt( (int) $shot->ID, 'shot-representative-still', $base_prompt ),
					[
						'scene_id'            => (int) $scene->ID,
						'shot_id'             => (int) $shot->ID,
						'scene_order'         => $scene_index,
						'shot_order'          => $shot_index,
						'timeline_order'      => $timeline_order,
						'character_ids'       => array_values( array_map( 'intval', $character_ids ) ),
						'location_id'         => $location_id,
						'dependencies'        => $reference_keys,
						'input_refs'          => $still_input_refs,
						'preferred_modalities'=> ! empty( $reference_keys ) ? [ 'image_text_to_image', 'image_to_image', 'text_to_image' ] : [ 'text_to_image' ],
						'generation_required' => true,
					]
				);
				$still_tasks[ 'shot:' . $shot->ID ] = $task_key;
				++$timeline_order;
			}
		}

		if ( empty( $scenes ) ) {
			$task_key = 'demo-project-' . $project_id . '-still';
			$tasks[]  = self::demonstration_task(
				$task_key,
				$project,
				'project-key-art',
				'project-key-art',
				__( 'Project fallback still', 'worldgraph' ),
				'image',
				'references',
				true,
				Asset_Generator::build_prompt( $project_id, 'project-key-art', $base_prompt ),
				[
					'timeline_order'      => 0,
					'dependencies'        => [],
					'input_refs'          => [],
					'generation_required' => true,
				]
			);
			$still_tasks[ 'project:' . $project_id ] = $task_key;
		}

		foreach ( $scenes as $scene_index => $scene_item ) {
			$scene = $scene_item['post'];
			foreach ( self::demonstration_scene_sounds( $scene ) as $sound ) {
				if ( isset( $sound_tasks[ $sound->ID ] ) ) {
					continue;
				}
				$role              = self::demonstration_sound_role( (int) $sound->ID );
				$shot_id           = self::related_field_id( (int) $sound->ID, 'worldgraph_sound', 'shot', 'worldgraph_shot' );
				$character_id      = self::related_field_id( (int) $sound->ID, 'worldgraph_sound', 'character', 'worldgraph_character' );
				$existing_asset_id = self::related_field_id( (int) $sound->ID, 'worldgraph_sound', 'asset', 'worldgraph_asset' );
				$task_key          = 'demo-sound-' . $sound->ID . '-audio';
				$generation_needed = 0 === $existing_asset_id && 'silence' !== $role;
				$task              = self::demonstration_task(
					$task_key,
					$sound,
					'demonstration-sound',
					'sound-' . ( $role ?: 'effect' ),
					__( 'Soundtrack cue', 'worldgraph' ),
					'audio',
					'audio',
					false,
					self::demonstration_sound_prompt( $sound, $role, $base_prompt, 0, 'sound-cue' ),
					[
						'scene_id'            => (int) $scene->ID,
						'shot_id'             => $shot_id,
						'scene_order'         => $scene_index,
						'audio_role'          => $role,
						'character_id'        => $character_id,
						'existing_asset_id'   => $existing_asset_id,
						'start_timecode'      => self::clean_text( (string) worldgraph_get_field_value( (int) $sound->ID, 'start_timecode' ), 12 ),
						'duration'            => self::clean_text( (string) worldgraph_get_field_value( (int) $sound->ID, 'duration' ), 12 ),
						'diegetic'            => sanitize_key( (string) worldgraph_get_field_value( (int) $sound->ID, 'diegetic' ) ),
						'preferred_modalities'=> self::sound_modalities( $role ),
						'dependencies'        => [],
						'input_refs'          => [],
						'generation_required' => $generation_needed,
					]
				);
				$tasks[]            = $task;
				$sound_tasks[ $sound->ID ] = $task_key;
				$all_scene_sounds[ $scene->ID ][] = $task_key;
				if ( $shot_id ) {
					$sounds_by_shot[ $shot_id ][] = $task_key;
				} else {
					$sounds_by_scene[ $scene->ID ][] = $task_key;
				}
				$source_ids[ $sound->ID ] = true;
			}
		}

		foreach ( $scenes as $scene_index => $scene_item ) {
			$scene = $scene_item['post'];
			$shots = $scene_item['shots'];
			foreach ( $shots as $shot_index => $shot ) {
				$still_key       = (string) ( $still_tasks[ 'shot:' . $shot->ID ] ?? '' );
				$next_shot       = $shots[ $shot_index + 1 ] ?? null;
				$next_still_key  = $next_shot instanceof \WP_Post ? (string) ( $still_tasks[ 'shot:' . $next_shot->ID ] ?? '' ) : '';
				$character_ids   = (array) ( $character_usage['shot_characters'][ $shot->ID ] ?? [] );
				$character_refs  = array_values( array_filter( array_map( static fn( int $character_id ): string => (string) ( $character_tasks[ $character_id ] ?? '' ), $character_ids ) ) );
				$location_id     = (int) ( $scene_locations[ $scene->ID ] ?? 0 );
				$location_ref    = (string) ( $location_tasks[ $location_id ] ?? '' );
				$primary_image   = $still_key;
				$reference_keys  = array_values( array_unique( array_filter( array_merge( $character_refs, [ $location_ref ] ) ) ) );
				$dependencies    = array_values( array_unique( array_filter( array_merge( [ $still_key, $next_still_key, $primary_image ], $reference_keys ) ) ) );
				$input_refs      = [
					'image'            => [ 'task_key' => $primary_image, 'fallback_task_key' => $still_key ],
					'start_frame'      => [ 'task_key' => $still_key ],
					'reference_images' => array_map( static fn( string $key ): array => [ 'task_key' => $key ], $reference_keys ),
				];
				if ( '' !== $next_still_key ) {
					$input_refs['end_frame'] = [ 'task_key' => $next_still_key ];
				}
				$task_key = 'demo-shot-' . $shot->ID . '-video';
				$tasks[]  = self::demonstration_task(
					$task_key,
					$shot,
					'shot-still-and-video',
					'shot-video',
					__( 'Rough-cut moving shot', 'worldgraph' ),
					'video',
					'video',
					false,
					Asset_Generator::build_prompt( (int) $shot->ID, 'shot-video', $base_prompt ),
					[
						'scene_id'             => (int) $scene->ID,
						'shot_id'              => (int) $shot->ID,
						'scene_order'          => $scene_index,
						'shot_order'           => $shot_index,
						'timeline_order'       => self::shot_timeline_order( $scenes, (int) $shot->ID ),
						'character_ids'        => array_values( array_map( 'intval', $character_ids ) ),
						'location_id'          => $location_id,
						'preferred_modalities' => '' !== $next_still_key
							? [ 'video_to_video', 'text_image_to_video', 'text_to_video' ]
							: [ 'text_image_to_video', 'text_to_video' ],
						'dependencies'         => $dependencies,
						'input_refs'           => $input_refs,
						'fallback_task_key'    => $still_key,
						'generation_required'  => true,
					]
				);
				$video_tasks[ $shot->ID ] = $task_key;
			}
		}

		foreach ( $scenes as $scene_index => $scene_item ) {
			$scene          = $scene_item['post'];
			$scene_segments = [];
			foreach ( $scene_item['shots'] as $shot_index => $shot ) {
				$scene_segments[] = [
					'scene_id'                => (int) $scene->ID,
					'shot_id'                 => (int) $shot->ID,
					'scene_order'             => $scene_index,
					'shot_order'              => $shot_index,
					'video_task_key'          => (string) ( $video_tasks[ $shot->ID ] ?? '' ),
					'fallback_still_task_key' => (string) ( $still_tasks[ 'shot:' . $shot->ID ] ?? '' ),
					'duration'                => self::clean_text( (string) worldgraph_get_field_value( (int) $shot->ID, 'duration' ), 12 ),
					'subtitle_text'           => self::demonstration_segment_caption( $shot, $scene ),
					'audio_task_keys'         => array_values( array_unique( array_merge( (array) ( $sounds_by_scene[ $scene->ID ] ?? [] ), (array) ( $sounds_by_shot[ $shot->ID ] ?? [] ) ) ) ),
				];
			}
			if ( empty( $scene_segments ) ) {
				$scene_segments[] = [
					'scene_id'                => (int) $scene->ID,
					'shot_id'                 => 0,
					'scene_order'             => $scene_index,
					'shot_order'              => 0,
					'video_task_key'          => '',
					'fallback_still_task_key' => (string) ( $still_tasks[ 'scene:' . $scene->ID ] ?? '' ),
					'duration'                => '',
					'subtitle_text'           => self::demonstration_segment_caption( null, $scene ),
					'audio_task_keys'         => array_values( array_unique( (array) ( $sounds_by_scene[ $scene->ID ] ?? [] ) ) ),
				];
			}
			$timeline[] = [
				'episode_id'      => (int) $scene_item['episode_id'],
				'scene_id'        => (int) $scene->ID,
				'scene_order'     => $scene_index,
				'scene_title'     => (string) $scene->post_title,
				'subtitle_text'   => self::demonstration_dialogue_text( (int) $scene->ID ),
				'sound_task_keys' => array_values( array_unique( (array) ( $all_scene_sounds[ $scene->ID ] ?? [] ) ) ),
				'segments'        => $scene_segments,
			];
		}

		if ( empty( $timeline ) ) {
			$timeline[] = [
				'episode_id'      => 0,
				'scene_id'        => 0,
				'scene_order'     => 0,
				'scene_title'     => (string) $project->post_title,
				'sound_task_keys' => [],
				'segments'        => [
					[
						'scene_id'                => 0,
						'shot_id'                 => 0,
						'scene_order'             => 0,
						'shot_order'              => 0,
						'video_task_key'          => '',
						'fallback_still_task_key' => (string) ( $still_tasks[ 'project:' . $project_id ] ?? '' ),
						'duration'                => '',
						'subtitle_text'           => self::clean_text( (string) ( $project->post_excerpt ?: $project->post_content ?: $project->post_title ), 100 ),
						'audio_task_keys'         => [],
					],
				],
			];
		}

		$maximum = max( 1, (int) apply_filters( 'worldgraph_generation_batch_max_tasks', self::MAX_BATCH_TASKS, $project_id, 'demonstration' ) );
		if ( count( $tasks ) > $maximum ) {
			return new WP_Error(
				'worldgraph_generation_batch_too_large',
				sprintf(
					/* translators: 1: number of jobs in the generation plan, 2: maximum number of jobs allowed. */
					__( 'This plan contains %1$d jobs; the current limit is %2$d.', 'worldgraph' ),
					count( $tasks ),
					$maximum
				),
				[ 'status' => 400 ]
			);
		}

		$counts = array_count_values( array_column( $tasks, 'type' ) );
		return [
			'post_id'            => $project_id,
			'scope'              => 'demonstration',
			'batch_kind'         => self::DEMONSTRATION_BATCH,
			'workflow'           => [
				'id'          => 'project-demonstration-video',
				'label'       => __( 'End-to-end demonstration video', 'worldgraph' ),
				'description' => __( 'Ordered Story Graph shots with still, subtitle, silence, and title-card fallbacks.', 'worldgraph' ),
			],
			'sources'            => count( $source_ids ),
			'total_jobs'         => count( $tasks ),
			'generation_jobs'    => count( array_filter( $tasks, static fn( array $task ): bool => ! empty( $task['generation_required'] ) ) ),
			'counts'             => [
				'image' => (int) ( $counts['image'] ?? 0 ),
				'video' => (int) ( $counts['video'] ?? 0 ),
				'audio' => (int) ( $counts['audio'] ?? 0 ),
			],
			'required_task_keys' => array_values( array_column( array_filter( $tasks, static fn( array $task ): bool => ! empty( $task['required'] ) ), 'task_key' ) ),
			'optional_task_keys' => array_values( array_column( array_filter( $tasks, static fn( array $task ): bool => empty( $task['required'] ) ), 'task_key' ) ),
			'assembly'           => [
				'strategy'          => 'ffmpeg_rough_cut',
				'title'             => (string) $project->post_title,
				'video_fallback'    => 'still',
				'audio_fallback'    => 'silence',
				'subtitle_fallback' => true,
				'timeline'          => $timeline,
			],
			'tasks'              => $tasks,
		];
	}

	/** Construct the shared, frozen demonstration task shape. */
	private static function demonstration_task( string $task_key, \WP_Post $source, string $workflow_id, string $intent, string $label, string $type, string $phase, bool $required, string $prompt, array $metadata = [] ): array {
		return array_merge(
			[
				'task_key'     => $task_key,
				'source_id'    => (int) $source->ID,
				'source_type'  => (string) $source->post_type,
				'source_title' => (string) $source->post_title,
				'workflow_id'  => $workflow_id,
				'intent'       => $intent,
				'label'        => $label,
				'type'         => $type,
				'phase'        => $phase,
				'required'     => $required,
				'featured'     => 'image' === $type,
				'prompt'       => $prompt,
			],
			$metadata
		);
	}

	/** Convert symbolic reference task keys into the common multi-image shape. */
	private static function reference_image_refs( array $task_keys ): array {
		return empty( $task_keys )
			? []
			: [ 'reference_images' => array_map( static fn( string $task_key ): array => [ 'task_key' => $task_key ], array_values( $task_keys ) ) ];
	}

	/** Resolve Episodes, Scenes, and Shots into deterministic editorial order. */
	private static function demonstration_story_order( array $sources ): array {
		$episodes = array_values( array_filter( $sources, static fn( \WP_Post $post ): bool => 'worldgraph_episode' === $post->post_type ) );
		$scenes   = [];
		foreach ( $sources as $source ) {
			if ( 'worldgraph_scene' === $source->post_type ) {
				$scenes[ $source->ID ] = $source;
			}
			if ( in_array( $source->post_type, [ 'worldgraph_character', 'worldgraph_location' ], true ) ) {
				foreach ( self::related_posts_by_type( (int) $source->ID, (string) $source->post_type, 'worldgraph_scene' ) as $scene ) {
					$scenes[ $scene->ID ] = $scene;
				}
			}
		}
		$scenes = array_values( $scenes );
		self::sort_numbered_posts( $episodes, 'episode_number' );

		$by_episode = [];
		$orphans    = [];
		foreach ( $scenes as $scene ) {
			$episode_id = self::related_field_id( (int) $scene->ID, 'worldgraph_scene', 'episode', 'worldgraph_episode' );
			if ( $episode_id ) {
				$by_episode[ $episode_id ][] = $scene;
				if ( ! array_filter( $episodes, static fn( \WP_Post $episode ): bool => (int) $episode->ID === $episode_id ) ) {
					$episode = get_post( $episode_id );
					if ( $episode instanceof \WP_Post && 'worldgraph_episode' === $episode->post_type ) {
						$episodes[] = $episode;
					}
				}
			} else {
				$orphans[] = $scene;
			}
		}
		self::sort_numbered_posts( $episodes, 'episode_number' );
		foreach ( $by_episode as &$episode_scenes ) {
			self::sort_numbered_posts( $episode_scenes, 'scene_number' );
		}
		unset( $episode_scenes );
		self::sort_numbered_posts( $orphans, 'scene_number' );

		$ordered_scenes = [];
		$seen           = [];
		foreach ( $episodes as $episode ) {
			foreach ( $by_episode[ $episode->ID ] ?? [] as $scene ) {
				if ( isset( $seen[ $scene->ID ] ) ) {
					continue;
				}
				$seen[ $scene->ID ] = true;
				$ordered_scenes[]   = self::demonstration_scene_item( $scene, (int) $episode->ID );
			}
		}
		foreach ( $orphans as $scene ) {
			if ( isset( $seen[ $scene->ID ] ) ) {
				continue;
			}
			$seen[ $scene->ID ] = true;
			$ordered_scenes[]   = self::demonstration_scene_item( $scene, 0 );
		}

		return [ 'episodes' => $episodes, 'scenes' => $ordered_scenes ];
	}

	/** Build one ordered Scene item and prefer the canonical reorder service. */
	private static function demonstration_scene_item( \WP_Post $scene, int $episode_id ): array {
		if ( function_exists( __NAMESPACE__ . '\\worldgraph_get_scene_shots_for_reorder' ) ) {
			$shots = worldgraph_get_scene_shots_for_reorder( (int) $scene->ID );
		} else {
			$shots = array_values( array_filter( self::ownership_children( (int) $scene->ID, 'worldgraph_scene' ), static fn( \WP_Post $post ): bool => 'worldgraph_shot' === $post->post_type ) );
			self::sort_numbered_posts( $shots, 'shot_number' );
		}

		return [ 'post' => $scene, 'episode_id' => $episode_id, 'shots' => $shots ];
	}

	/** Apply menu order, numeric story order, title, then ID tie breakers. */
	private static function sort_numbered_posts( array &$posts, string $number_field ): void {
		usort(
			$posts,
			static function ( \WP_Post $left, \WP_Post $right ) use ( $number_field ): int {
				$left_number  = (float) worldgraph_get_field_value( (int) $left->ID, $number_field );
				$right_number = (float) worldgraph_get_field_value( (int) $right->ID, $number_field );
				$left_order   = (int) $left->menu_order > 0 ? (int) $left->menu_order : ( $left_number > 0 ? $left_number : PHP_INT_MAX );
				$right_order  = (int) $right->menu_order > 0 ? (int) $right->menu_order : ( $right_number > 0 ? $right_number : PHP_INT_MAX );
				if ( $left_order !== $right_order ) {
					return $left_order <=> $right_order;
				}
				if ( $left_number !== $right_number ) {
					return $left_number <=> $right_number;
				}
				$title_order = strcasecmp( (string) $left->post_title, (string) $right->post_title );
				return 0 !== $title_order ? $title_order : (int) $left->ID <=> (int) $right->ID;
			}
		);
	}

	/** Infer recurring characters and each Shot's strongest continuity subjects. */
	private static function demonstration_character_usage( array $sources, array $scenes ): array {
		$characters = [];
		foreach ( $sources as $source ) {
			if ( 'worldgraph_character' === $source->post_type ) {
				$characters[ $source->ID ] = $source;
			}
		}
		foreach ( $scenes as $scene_item ) {
			$scene = $scene_item['post'];
			foreach ( self::related_posts_by_type( (int) $scene->ID, 'worldgraph_scene', 'worldgraph_character' ) as $character ) {
				$characters[ $character->ID ] = $character;
			}
			foreach ( $scene_item['shots'] as $shot ) {
				foreach ( self::related_posts_by_type( (int) $shot->ID, 'worldgraph_shot', 'worldgraph_character' ) as $character ) {
					$characters[ $character->ID ] = $character;
				}
			}
		}
		ksort( $characters, SORT_NUMERIC );

		$aliases = [];
		foreach ( $characters as $character ) {
			$names = [ (string) $character->post_title, (string) worldgraph_get_field_value( (int) $character->ID, 'display_name' ) ];
			$names = array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );
			foreach ( $names as $name ) {
				$aliases[ $character->ID ][] = $name;
			}
		}

		$occurrences    = [];
		$shot_characters = [];
		$order          = 0;
		foreach ( $scenes as $scene_item ) {
			$scene          = $scene_item['post'];
			$scene_ids      = array_map( static fn( \WP_Post $post ): int => (int) $post->ID, self::related_posts_by_type( (int) $scene->ID, 'worldgraph_scene', 'worldgraph_character' ) );
			$scene_text     = implode( ' ', [ (string) $scene->post_title, (string) $scene->post_content, (string) worldgraph_get_field_value( (int) $scene->ID, 'summary' ), (string) worldgraph_get_field_value( (int) $scene->ID, 'script_content' ) ] );
			$scene_ids      = array_merge( $scene_ids, self::character_ids_mentioned( $scene_text, $aliases ), self::dialogue_character_ids( (int) $scene->ID, $aliases ) );
			$scene_ids      = array_values( array_unique( array_filter( array_map( 'absint', $scene_ids ) ) ) );
			if ( empty( $scene_item['shots'] ) ) {
				foreach ( $scene_ids as $character_id ) {
					self::record_character_occurrence( $occurrences, $character_id, (int) $scene->ID, 0, 'scene:' . $scene->ID, $order );
				}
				++$order;
			}

			foreach ( $scene_item['shots'] as $shot ) {
				$shot_ids  = array_map( static fn( \WP_Post $post ): int => (int) $post->ID, self::related_posts_by_type( (int) $shot->ID, 'worldgraph_shot', 'worldgraph_character' ) );
				$shot_text = implode( ' ', [ (string) $shot->post_title, (string) $shot->post_content, (string) worldgraph_get_field_value( (int) $shot->ID, 'shot_description' ), (string) worldgraph_get_field_value( (int) $shot->ID, 'editorial_notes' ) ] );
				$shot_ids  = array_merge( $shot_ids, self::character_ids_mentioned( $shot_text, $aliases ) );
				$shot_ids  = array_values( array_unique( array_filter( array_map( 'absint', $shot_ids ) ) ) );
				if ( empty( $shot_ids ) ) {
					$shot_ids = $scene_ids;
				}
				foreach ( $shot_ids as $character_id ) {
					self::record_character_occurrence( $occurrences, $character_id, (int) $scene->ID, (int) $shot->ID, 'shot:' . $shot->ID, $order );
				}
				$shot_characters[ $shot->ID ] = $shot_ids;
				++$order;
			}
		}

		foreach ( $shot_characters as &$character_ids ) {
			usort(
				$character_ids,
				static function ( int $left, int $right ) use ( $occurrences ): int {
					$left_count  = count( $occurrences[ $left ]['segment_keys'] ?? [] );
					$right_count = count( $occurrences[ $right ]['segment_keys'] ?? [] );
					if ( $left_count !== $right_count ) {
						return $right_count <=> $left_count;
					}
					$first = (int) ( $occurrences[ $left ]['first_order'] ?? PHP_INT_MAX ) <=> (int) ( $occurrences[ $right ]['first_order'] ?? PHP_INT_MAX );
					return 0 !== $first ? $first : $left <=> $right;
				}
			);
		}
		unset( $character_ids );

		return [ 'characters' => $characters, 'occurrences' => $occurrences, 'shot_characters' => $shot_characters ];
	}

	/** Add one distinct visual-segment occurrence to a character usage map. */
	private static function record_character_occurrence( array &$occurrences, int $character_id, int $scene_id, int $shot_id, string $segment_key, int $order ): void {
		if ( ! isset( $occurrences[ $character_id ] ) ) {
			$occurrences[ $character_id ] = [
				'character_id' => $character_id,
				'first_order'  => $order,
				'scene_ids'    => [],
				'shot_ids'     => [],
				'segment_keys' => [],
			];
		}
		$occurrences[ $character_id ]['scene_ids'][ $scene_id ]       = true;
		$occurrences[ $character_id ]['segment_keys'][ $segment_key ] = true;
		if ( $shot_id ) {
			$occurrences[ $character_id ]['shot_ids'][ $shot_id ] = true;
		}
	}

	/** Match candidate character aliases in natural-language story text. */
	private static function character_ids_mentioned( string $text, array $aliases ): array {
		if ( '' === trim( $text ) ) {
			return [];
		}
		$ids = [];
		foreach ( $aliases as $character_id => $names ) {
			foreach ( $names as $name ) {
				$pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}_])/iu';
				if ( 1 === @preg_match( $pattern, $text ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed imported encodings are treated as no match.
					$ids[] = (int) $character_id;
					break;
				}
			}
		}

		return $ids;
	}

	/** Match structured dialogue speakers to Character IDs. */
	private static function dialogue_character_ids( int $scene_id, array $aliases ): array {
		$dialogue = worldgraph_get_field_value( $scene_id, 'dialogue' );
		if ( is_string( $dialogue ) ) {
			$decoded  = json_decode( $dialogue, true );
			$dialogue = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! is_array( $dialogue ) ) {
			return [];
		}
		if ( isset( $dialogue['speaker'] ) || isset( $dialogue['line'] ) ) {
			$dialogue = [ $dialogue ];
		}

		$ids = [];
		foreach ( $dialogue as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$speaker = trim( (string) ( $row['speaker'] ?? $row['character'] ?? '' ) );
			if ( ctype_digit( $speaker ) && isset( $aliases[ (int) $speaker ] ) ) {
				$ids[] = (int) $speaker;
				continue;
			}
			foreach ( $aliases as $character_id => $names ) {
				foreach ( $names as $name ) {
					if ( 0 === strcasecmp( $speaker, $name ) ) {
						$ids[] = (int) $character_id;
						break 2;
					}
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/** Return directly adjacent posts of one requested type in either direction. */
	private static function related_posts_by_type( int $post_id, string $post_type, string $related_type ): array {
		$posts = [];
		foreach ( [ 'outgoing', 'incoming' ] as $direction ) {
			foreach ( get_relationships( $post_id, $post_type, $direction ) as $relationship ) {
				$type       = 'outgoing' === $direction ? (string) ( $relationship['to_type'] ?? '' ) : (string) ( $relationship['from_type'] ?? '' );
				$related_id = 'outgoing' === $direction ? absint( $relationship['to_id'] ?? 0 ) : absint( $relationship['from_id'] ?? 0 );
				if ( $related_type !== $type ) {
					continue;
				}
				$post = get_post( $related_id );
				if ( $post instanceof \WP_Post && $related_type === $post->post_type && ! in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
					$posts[ $post->ID ] = $post;
				}
			}
		}
		ksort( $posts, SORT_NUMERIC );

		return array_values( $posts );
	}

	/** Resolve one relationship field without assuming its storage representation. */
	private static function related_field_id( int $post_id, string $post_type, string $field_name, string $related_type ): int {
		$value = worldgraph_get_field_value( $post_id, $field_name );
		foreach ( is_array( $value ) ? $value : [ $value ] as $candidate ) {
			$candidate_id = $candidate instanceof \WP_Post ? (int) $candidate->ID : absint( $candidate );
			if ( $candidate_id && $related_type === get_post_type( $candidate_id ) ) {
				return $candidate_id;
			}
		}

		$fallback = 0;
		foreach ( [ 'outgoing', 'incoming' ] as $direction ) {
			foreach ( get_relationships( $post_id, $post_type, $direction ) as $relationship ) {
				$type       = 'outgoing' === $direction ? (string) ( $relationship['to_type'] ?? '' ) : (string) ( $relationship['from_type'] ?? '' );
				$related_id = 'outgoing' === $direction ? absint( $relationship['to_id'] ?? 0 ) : absint( $relationship['from_id'] ?? 0 );
				if ( $related_type !== $type ) {
					continue;
				}
				$relationship_field = sanitize_key( (string) ( $relationship['metadata']['field'] ?? '' ) );
				if ( sanitize_key( $field_name ) === $relationship_field ) {
					return $related_id;
				}
				$fallback = $fallback ?: $related_id;
			}
		}

		return $fallback;
	}

	/** Resolve the one visual Location selected by a Scene. */
	private static function demonstration_scene_location( \WP_Post $scene ): ?\WP_Post {
		$location_id = self::related_field_id( (int) $scene->ID, 'worldgraph_scene', 'location', 'worldgraph_location' );
		$location    = $location_id ? get_post( $location_id ) : null;

		return $location instanceof \WP_Post && 'worldgraph_location' === $location->post_type ? $location : null;
	}

	/** Sound cues configured for a Scene, including legacy named-meta storage. */
	private static function demonstration_scene_sounds( \WP_Post $scene ): array {
		$sounds = [];
		foreach ( self::related_posts_by_type( (int) $scene->ID, 'worldgraph_scene', 'worldgraph_sound' ) as $sound ) {
			$sounds[ $sound->ID ] = $sound;
		}
		$legacy = get_posts(
			[
				'post_type'      => 'worldgraph_sound',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_query'     => [
					[
						'key'     => 'scene',
						'value'   => (int) $scene->ID,
						'compare' => '=',
						'type'    => 'NUMERIC',
					],
				],
			]
		);
		foreach ( $legacy as $sound ) {
			if ( $sound instanceof \WP_Post && ! in_array( $sound->post_status, [ 'trash', 'auto-draft' ], true ) ) {
				$sounds[ $sound->ID ] = $sound;
			}
		}
		$sounds = array_values( $sounds );
		usort(
			$sounds,
			static function ( \WP_Post $left, \WP_Post $right ): int {
				$left_time  = (string) worldgraph_get_field_value( (int) $left->ID, 'start_timecode' );
				$right_time = (string) worldgraph_get_field_value( (int) $right->ID, 'start_timecode' );
				$time_order = strnatcasecmp( $left_time, $right_time );
				if ( 0 !== $time_order ) {
					return $time_order;
				}
				$menu_order = (int) $left->menu_order <=> (int) $right->menu_order;
				return 0 !== $menu_order ? $menu_order : (int) $left->ID <=> (int) $right->ID;
			}
		);

		return $sounds;
	}

	/** Resolve the canonical Sound Type slug with content-aware legacy fallbacks. */
	private static function demonstration_sound_role( int $sound_id ): string {
		$terms = get_the_terms( $sound_id, 'worldgraph_sound_type' );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			return sanitize_key( (string) $terms[0]->slug );
		}
		$value = worldgraph_get_field_value( $sound_id, 'sound_type' );
		if ( is_object( $value ) && isset( $value->slug ) ) {
			return sanitize_key( (string) $value->slug );
		}
		if ( is_scalar( $value ) && ! is_numeric( $value ) ) {
			return sanitize_key( (string) $value );
		}
		if ( '' !== trim( (string) worldgraph_get_field_value( $sound_id, 'spoken_text' ) ) ) {
			return 'voiceover';
		}
		if ( '' !== trim( (string) worldgraph_get_field_value( $sound_id, 'lyrics' ) ) ) {
			return 'music';
		}

		return 'sound-effect';
	}

	/** Preferred provider-neutral Template modalities for a soundtrack role. */
	private static function sound_modalities( string $role ): array {
		if ( in_array( $role, [ 'narration', 'voiceover', 'adr' ], true ) ) {
			return [ 'text_to_speech', 'text_to_dialogue' ];
		}
		if ( 'music' === $role ) {
			return [ 'text_to_music' ];
		}
		if ( 'silence' === $role ) {
			return [];
		}

		return [ 'text_to_sound_effect' ];
	}

	/** Compose one bounded semantic prompt from the canonical Sound fields. */
	private static function demonstration_sound_prompt( \WP_Post $sound, string $role, string $base_prompt, int $template_id = 0, string $intent = 'sound-cue' ): string {
		$role     = self::clean_text( $role ?: 'sound-effect', 4 );
		$sections = [
			self::prompt_section( 'primary', __( 'Cue', 'worldgraph' ) . ': ' . self::clean_text( (string) $sound->post_title, 20 ), true ),
			self::prompt_section( 'subject', __( 'Soundtrack role', 'worldgraph' ) . ': ' . $role ),
		];
		$scene_context = self::sound_scene_context( $sound );
		if ( '' !== $scene_context ) {
			$sections[] = self::prompt_section( 'setting', $scene_context );
		}
		$audio_direction = self::scene_audio_direction( $sound );
		if ( '' !== $audio_direction ) {
			$sections[] = self::prompt_section( 'continuity', $audio_direction, true );
		}

		// Supplied performance copy is protected. Bounds apply to the surrounding
		// descriptive context, never by truncating dialogue or lyric lines.
		$spoken = self::audio_verbatim_text( (string) worldgraph_get_field_value( (int) $sound->ID, 'spoken_text' ) );
		$lyrics = self::audio_verbatim_text( (string) worldgraph_get_field_value( (int) $sound->ID, 'lyrics' ) );
		$notes  = self::clean_text( (string) worldgraph_get_field_value( (int) $sound->ID, 'production_notes' ), 40 );
		if ( '' !== $spoken ) {
			$sections[] = self::prompt_section( 'verbatim', __( 'Speak this text exactly', 'worldgraph' ) . ': ' . $spoken, true );
		}
		if ( '' !== $lyrics ) {
			$sections[] = self::prompt_section( 'verbatim', __( 'Lyrics', 'worldgraph' ) . ': ' . $lyrics, true );
		}
		$duration = self::prompt_duration( (string) worldgraph_get_field_value( (int) $sound->ID, 'duration' ) );
		if ( '' !== $duration ) {
			$sections[] = self::prompt_section( 'motion', __( 'Target duration', 'worldgraph' ) . ': ' . $duration . '.', true );
		}
		$relation = self::sound_story_relation( (string) worldgraph_get_field_value( (int) $sound->ID, 'diegetic' ) );
		if ( '' !== $relation ) {
			$sections[] = self::prompt_section( 'subject', __( 'Story-world relation', 'worldgraph' ) . ': ' . $relation );
		}
		$description = self::clean_text( (string) ( $sound->post_excerpt ?: $sound->post_content ), 48 );
		if ( '' !== $description ) {
			$sections[] = self::prompt_section( 'action', __( 'Description', 'worldgraph' ) . ': ' . $description );
		}
		if ( '' !== $notes ) {
			$sections[] = self::prompt_section( 'author_instructions', __( 'Production notes', 'worldgraph' ) . ': ' . $notes, true );
		}
		$instructions = self::clean_text( (string) worldgraph_get_field_value( (int) $sound->ID, 'generation_prompt' ), 40 );
		if ( '' !== $instructions ) {
			$sections[] = self::prompt_section( 'author_instructions', __( 'Generation instructions', 'worldgraph' ) . ': ' . $instructions, true );
		}
		$base_prompt = self::clean_text( $base_prompt, 40 );
		if ( '' !== $base_prompt ) {
			$sections[] = self::prompt_section( 'author_instructions', __( 'Additional request instructions', 'worldgraph' ) . ': ' . $base_prompt, true );
		}
		if ( in_array( $role, [ 'narration', 'voiceover', 'adr' ], true ) ) {
			$constraints = __( 'Output constraints: speak only the supplied copy, preserve its wording, and produce clean intelligible audio without unrelated music or effects unless requested.', 'worldgraph' );
		} elseif ( 'music' === $role ) {
			$constraints = __( 'Output constraints: produce only the requested music cue, preserve supplied lyrics and mood, and avoid unrelated speech or sound effects.', 'worldgraph' );
		} else {
			$constraints = __( 'Output constraints: render only the described sound source with a clean beginning and ending, and add no unrelated speech or music.', 'worldgraph' );
		}
		$sections[] = self::prompt_section( 'constraints', $constraints, true );

		$modality = $template_id ? Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template_id, 'modality' ) ) : '';
		$policy   = Generation_Prompt_Policy::for_template(
			$template_id,
			[
				'output_type' => 'audio',
				'modality'    => $modality,
				'post_type'   => 'worldgraph_sound',
				'intent'      => $intent,
			]
		);

		$rendered = Generation_Prompt_Policy::render( $sections, $policy );
		return (string) $rendered['prompt'];
	}

	/** Explain a Sound's diegetic value in plain production language. */
	private static function sound_story_relation( string $value ): string {
		$relations = [
			'diegetic'     => __( 'Diegetic; characters hear it inside the story world.', 'worldgraph' ),
			'non_diegetic' => __( 'Non-diegetic; only the audience hears it.', 'worldgraph' ),
			'internal'     => __( 'Internal or subjective; present it from a character’s auditory perspective.', 'worldgraph' ),
			'mixed'        => __( 'Mixed or ambiguous; move deliberately between story-world and audience-only sound.', 'worldgraph' ),
		];

		return (string) ( $relations[ sanitize_key( $value ) ] ?? '' );
	}

	/** Return the concise sonic continuity inherited from a Sound's Scene. */
	private static function scene_audio_direction( \WP_Post $sound ): string {
		$scene_id = self::related_field_id( (int) $sound->ID, 'worldgraph_sound', 'scene', 'worldgraph_scene' );
		$direction = $scene_id
			? self::complete_phrase( (string) worldgraph_get_field_value( $scene_id, 'audio_direction' ), 16 )
			: '';

		return '' !== $direction
			? __( 'Scene sound and music direction', 'worldgraph' ) . ': ' . $direction
			: '';
	}

	/** Give a Sound only compact, non-visual context from its owning Scene. */
	private static function sound_scene_context( \WP_Post $sound ): string {
		$scene_id = self::related_field_id( (int) $sound->ID, 'worldgraph_sound', 'scene', 'worldgraph_scene' );
		$scene    = $scene_id ? get_post( $scene_id ) : null;
		if ( ! $scene instanceof \WP_Post || 'worldgraph_scene' !== $scene->post_type ) {
			return '';
		}

		$parts    = [];
		$location = self::demonstration_scene_location( $scene );
		if ( $location instanceof \WP_Post ) {
			$parts[] = __( 'Location', 'worldgraph' ) . ': ' . self::clean_text( (string) $location->post_title, 10 );
		}

		$time = self::clean_text( (string) worldgraph_get_field_value( $scene_id, 'time_of_day' ), 5 );
		$tone = self::clean_text( (string) worldgraph_get_field_value( $scene_id, 'emotional_tone' ), 12 );
		if ( '' !== $time ) {
			$parts[] = __( 'Time', 'worldgraph' ) . ': ' . $time;
		}
		if ( '' !== $tone ) {
			$parts[] = __( 'Emotional tone', 'worldgraph' ) . ': ' . $tone;
		}

		return $parts ? __( 'Scene context', 'worldgraph' ) . ': ' . implode( '; ', $parts ) : '';
	}

	/** Sanitize prompt copy without changing its words, spacing, or line breaks. */
	private static function audio_verbatim_text( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$value = str_replace( [ "\r\n", "\r" ], "\n", $value );
		$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );

		return trim( $value );
	}

	/** Stable zero-based order for a Shot among all demonstration Shots. */
	private static function shot_timeline_order( array $scenes, int $shot_id ): int {
		$order = 0;
		foreach ( $scenes as $scene_item ) {
			foreach ( $scene_item['shots'] as $shot ) {
				if ( (int) $shot->ID === $shot_id ) {
					return $order;
				}
				++$order;
			}
		}

		return $order;
	}

	/** Human-readable subtitle/title fallback for one visual timeline segment. */
	private static function demonstration_segment_caption( ?\WP_Post $shot, \WP_Post $scene ): string {
		if ( $shot instanceof \WP_Post ) {
			$description = (string) worldgraph_get_field_value( (int) $shot->ID, 'shot_description' );
			if ( '' !== trim( $description ) ) {
				return self::clean_text( $description, 100 );
			}
		}
		$dialogue = self::demonstration_dialogue_text( (int) $scene->ID );
		if ( '' !== $dialogue ) {
			return $dialogue;
		}
		$summary = (string) worldgraph_get_field_value( (int) $scene->ID, 'summary' );

		return self::clean_text( $summary ?: (string) $scene->post_excerpt ?: (string) $scene->post_content ?: (string) $scene->post_title, 100 );
	}

	/** Flatten structured Scene dialogue for subtitle fallback. */
	private static function demonstration_dialogue_text( int $scene_id ): string {
		$dialogue = worldgraph_get_field_value( $scene_id, 'dialogue' );
		if ( is_string( $dialogue ) ) {
			$decoded  = json_decode( $dialogue, true );
			$dialogue = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! is_array( $dialogue ) ) {
			return '';
		}
		if ( isset( $dialogue['speaker'] ) || isset( $dialogue['line'] ) ) {
			$dialogue = [ $dialogue ];
		}
		$lines = [];
		foreach ( $dialogue as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$speaker = self::clean_text( (string) ( $row['speaker'] ?? $row['character'] ?? '' ), 20 );
			$line    = self::clean_text( (string) ( $row['line'] ?? $row['text'] ?? '' ), 80 );
			if ( '' !== $line ) {
				$lines[] = ( '' !== $speaker ? $speaker . ': ' : '' ) . $line;
			}
		}

		return self::clean_text( implode( "\n", $lines ), 160 );
	}

	/** Project root plus descendants connected by canonical ownership edges. */
	private static function project_sources( int $project_id ): array {
		$allowed = array_keys( self::definitions() );
		$root    = get_post( $project_id );
		if ( ! $root instanceof \WP_Post ) {
			return [];
		}

		$queue   = [ $root ];
		$seen    = [];
		$sources = [];
		while ( ! empty( $queue ) ) {
			$current = array_shift( $queue );
			if ( isset( $seen[ $current->ID ] ) ) {
				continue;
			}
			$seen[ $current->ID ] = true;
			if ( in_array( $current->post_type, $allowed, true ) ) {
				$sources[] = $current;
			}
			foreach ( self::ownership_children( (int) $current->ID, (string) $current->post_type ) as $child ) {
				if ( in_array( $child->post_type, $allowed, true ) && ! isset( $seen[ $child->ID ] ) ) {
					$queue[] = $child;
				}
			}
		}

		return $sources;
	}

	/** Direct children represented by parent contains or child belongs_to edges. */
	private static function ownership_children( int $post_id, string $post_type ): array {
		$children = [];
		foreach ( get_relationships( $post_id, $post_type, 'outgoing' ) as $relationship ) {
			if ( 'contains' !== ( $relationship['type'] ?? '' ) ) {
				continue;
			}
			$child = get_post( absint( $relationship['to_id'] ?? 0 ) );
			if ( $child instanceof \WP_Post ) {
				$children[ $child->ID ] = $child;
			}
		}
		foreach ( get_relationships( $post_id, $post_type, 'incoming' ) as $relationship ) {
			if ( 'belongs_to' !== ( $relationship['type'] ?? '' ) ) {
				continue;
			}
			$child = get_post( absint( $relationship['from_id'] ?? 0 ) );
			if ( $child instanceof \WP_Post ) {
				$children[ $child->ID ] = $child;
			}
		}

		$children = array_values( $children );
		usort( $children, static function ( \WP_Post $left, \WP_Post $right ): int {
			$left_order  = (int) $left->menu_order;
			$right_order = (int) $right->menu_order;
			if ( $left_order !== $right_order ) {
				return $left_order <=> $right_order;
			}
			$number_key = 'worldgraph_shot' === $left->post_type ? 'shot_number' : ( 'worldgraph_scene' === $left->post_type ? 'scene_number' : '' );
			if ( '' !== $number_key && $left->post_type === $right->post_type ) {
				$number_compare = (float) worldgraph_get_field_value( $left->ID, $number_key ) <=> (float) worldgraph_get_field_value( $right->ID, $number_key );
				if ( 0 !== $number_compare ) {
					return $number_compare;
				}
			}
			return strcasecmp( $left->post_title, $right->post_title );
		} );

		return $children;
	}

	/**
	 * Active Templates that can execute one output for one source post.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function runnable_templates( int $post_id, string $type, array $task = [] ): array {
		$type      = sanitize_key( $type );
		$preferred = array_values( array_filter( array_map( [ Generation_Modality::class, 'sanitize' ], (array) ( $task['preferred_modalities'] ?? [] ) ) ) );
		$input_refs = is_array( $task['input_refs'] ?? null ) ? $task['input_refs'] : [];
		$cache_key = $post_id . ':' . $type . ':' . md5( wp_json_encode( [ $preferred, array_keys( $input_refs ), (string) ( $task['intent'] ?? '' ), (string) get_post_type( $post_id ) ] ) );
		if ( isset( self::$template_cache[ $cache_key ] ) ) {
			return self::$template_cache[ $cache_key ];
		}

		$templates = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => 'status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'active', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		$options = [];
		foreach ( $templates as $template ) {
			$modality = Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template->ID, 'modality' ) );
			if ( $type !== Generation_Modality::output_type( $modality ) || ( ! empty( $preferred ) && ! in_array( $modality, $preferred, true ) ) ) {
				continue;
			}
			$resolved_inputs = Template_Bindings::resolve( (int) $template->ID, $post_id );
			$missing_inputs  = array_values( array_filter(
				array_intersect( Generation_Modality::required_inputs( $modality ), Generation_Modality::MEDIA_SLOTS ),
				static function ( string $slot ) use ( $resolved_inputs, $input_refs ): bool {
					return empty( $resolved_inputs[ $slot ] ) && empty( $input_refs[ $slot ] );
				}
			) );
			if ( ! empty( $missing_inputs ) ) {
				continue;
			}
			$connection_id = absint( worldgraph_get_field_value( $template->ID, 'connection_id' ) );
			$connection    = $connection_id ? Connection_Repository::get( $connection_id ) : null;
			$provider      = sanitize_key( (string) worldgraph_get_field_value( $template->ID, 'provider_type' ) );
			if ( ! $connection || ! Connection_Repository::is_available( $connection_id ) || $provider !== ( $connection['provider_type'] ?? '' ) || ! Connection_Adapters::supports_generation( $provider ) ) {
				continue;
			}
			$requires_media       = ! empty( Generation_Modality::media_inputs( $modality ) );
			$provider_template_id = trim( (string) ( worldgraph_get_field_value( $template->ID, 'provider_template_id' ) ?: get_post_meta( $template->ID, 'comfy_template_id', true ) ) );
			$media_supported      = Connection_Adapters::supports_media_inputs( $provider )
				&& ( 'comfyui' !== $provider || 'local' === ( $connection['environment'] ?? '' ) );
			if ( $requires_media && ! $media_supported ) {
				continue;
			}
			// A local ComfyUI Template whose nodes/models are not installed
			// cannot actually run, so it must not appear as a choice until it
			// is verified ready. A catalog read failure (connectivity) is left
			// to surface at submission rather than hiding every Template here.
			if ( 'comfyui' === $provider && 'local' === ( $connection['environment'] ?? '' ) ) {
				Connection_Adapters::load( 'comfyui' );
				$report = Comfy_Manifest::validate( $template->ID, Local_ComfyUI::endpoint( $connection_id ) );
				if ( ! is_wp_error( $report ) && empty( $report['ok'] ) ) {
					continue;
				}
			}
			$options[] = [
				'id'             => (int) $template->ID,
				'name'           => (string) ( worldgraph_get_field_value( $template->ID, 'template_name' ) ?: $template->post_title ),
				'modality'       => $modality,
				'provider_type'  => $provider,
				'connection_id'  => $connection_id,
				'requires_media' => $requires_media,
				'media_inputs'   => Generation_Modality::media_inputs( $modality ),
				'preference_rank'=> false === array_search( $modality, $preferred, true ) ? count( $preferred ) : (int) array_search( $modality, $preferred, true ),
				'run_controls'   => Template_Run_Controls::describe( (int) $template->ID ),
				'prompt_policy'  => Generation_Prompt_Policy::for_template(
					(int) $template->ID,
					[
						'output_type' => $type,
						'post_type'   => (string) get_post_type( $post_id ),
						'intent'      => (string) ( $task['intent'] ?? '' ),
					]
				),
			];
		}

		usort( $options, static function ( array $left, array $right ): int {
			$rank = (int) ( $left['preference_rank'] ?? 0 ) <=> (int) ( $right['preference_rank'] ?? 0 );
			if ( 0 !== $rank ) {
				return $rank;
			}
			$left_media  = empty( $left['requires_media'] ) ? 0 : 1;
			$right_media = empty( $right['requires_media'] ) ? 0 : 1;
			return $left_media !== $right_media ? $left_media <=> $right_media : strcasecmp( (string) $left['name'], (string) $right['name'] );
		} );

		self::$template_cache[ $cache_key ] = $options;
		return $options;
	}

	/** Templates runnable for every task of one output type in a plan. */
	public static function common_templates( array $tasks, string $type ): array {
		$common = null;
		$by_id  = [];
		foreach ( $tasks as $task ) {
			if ( $type !== ( $task['type'] ?? '' ) || ( array_key_exists( 'generation_required', $task ) && empty( $task['generation_required'] ) ) ) {
				continue;
			}
			$options = self::runnable_templates( absint( $task['source_id'] ?? 0 ), $type, (array) $task );
			$ids     = array_map( 'intval', array_column( $options, 'id' ) );
			$common  = null === $common ? $ids : array_values( array_intersect( $common, $ids ) );
			foreach ( $options as $option ) {
				$by_id[ (int) $option['id'] ] = $option;
			}
		}

		return array_values( array_filter( array_map( static function ( int $id ) use ( $by_id ): ?array {
			return $by_id[ $id ] ?? null;
		}, $common ?? [] ) ) );
	}

	/** Resolve explicit, per-post, site-preferred, managed, then first Template. */
	public static function resolve_template_id( array $task, int $explicit_template_id = 0 ): int {
		$options = self::runnable_templates( (int) $task['source_id'], (string) $task['type'], $task );
		$ids     = array_map( 'intval', array_column( $options, 'id' ) );
		if ( $explicit_template_id ) {
			return in_array( $explicit_template_id, $ids, true ) ? $explicit_template_id : 0;
		}

		$candidates = [];
		$per_post   = absint( get_post_meta( (int) $task['source_id'], '_worldgraph_generation_template_' . sanitize_key( (string) $task['intent'] ), true ) );
		if ( $per_post ) {
			$candidates[] = $per_post;
		}
		$preferences = get_option( self::PREFERENCES_OPTION, [] );
		$preferences = is_array( $preferences ) ? $preferences : [];
		$candidates[] = absint( $preferences['intents'][ (string) $task['source_type'] ][ (string) $task['intent'] ] ?? 0 );
		$candidates[] = absint( $preferences['outputs'][ (string) $task['type'] ] ?? 0 );

		if ( 'image' === $task['type'] ) {
			$managed = get_posts( [
				'post_type'      => 'worldgraph_template',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => 'worldgraph_wizard_slot', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'local_comfyui_text_to_image', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			] );
			if ( $managed ) {
				$candidates[] = (int) $managed[0];
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( $candidate && in_array( $candidate, $ids, true ) ) {
				return (int) apply_filters( 'worldgraph_generation_default_template_id', $candidate, $task, $options );
			}
		}

		$first = isset( $options[0]['id'] ) ? (int) $options[0]['id'] : 0;
		return (int) apply_filters( 'worldgraph_generation_default_template_id', $first, $task, $options );
	}

	/** Render a planned task once its exact Template is known. */
	public static function finalize_task_prompt( array $task, int $template_id, string $base_prompt = '' ): string {
		$source_id   = absint( $task['source_id'] ?? 0 );
		$source_type = (string) ( $task['source_type'] ?? get_post_type( $source_id ) );
		$intent      = sanitize_key( (string) ( $task['intent'] ?? '' ) );
		$output      = self::intent( $source_type, $intent );
		if ( $source_id && 'worldgraph_sound' === $source_type && 'audio' === (string) ( $task['type'] ?? '' ) ) {
			return Asset_Generator::build_prompt( $source_id, 'sound-cue', $base_prompt, $template_id );
		}
		if ( $source_id && ! empty( $output ) && (string) ( $output['type'] ?? '' ) === (string) ( $task['type'] ?? '' ) ) {
			return Asset_Generator::build_prompt( $source_id, $intent, $base_prompt, $template_id );
		}

		return Generation_Prompt_Profiles::apply(
			(string) ( $task['prompt'] ?? '' ),
			$source_id,
			$intent,
			$template_id
		);
	}

	/** Freeze an item, Project, or demonstration plan for bounded background materialization. */
	public static function queue_batch( int $post_id, string $scope, array $args = [] ) {
		$args = wp_parse_args( $args, [
			'base_prompt'       => '',
			'image_template_id' => 0,
			'video_template_id' => 0,
			'audio_template_id' => 0,
			'image_run_values'  => [],
			'video_run_values'  => [],
			'audio_run_values'  => [],
			'idempotency_key'   => '',
		] );
		$scope = sanitize_key( $scope );
		$scope = in_array( $scope, self::supported_scopes(), true ) ? $scope : 'item';
		$batch_kind = self::batch_kind_for_scope( $scope );
		$requester_id = get_current_user_id();
		if ( ! $requester_id || ! user_can( $requester_id, 'upload_files' ) || ! user_can( $requester_id, 'edit_post', $post_id ) ) {
			return new WP_Error( 'worldgraph_generation_requester_forbidden', __( 'You are not allowed to queue generated story media for this item.', 'worldgraph' ), [ 'status' => $requester_id ? 403 : 401 ] );
		}

		$idempotency_key = sanitize_text_field( (string) $args['idempotency_key'] );
		if ( '' === $idempotency_key ) {
			return new WP_Error( 'worldgraph_generation_idempotency_required', __( 'A unique idempotency key is required to start a generation batch.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$run_values = [ 'image' => [], 'video' => [], 'audio' => [] ];
		foreach ( [ 'image', 'video', 'audio' ] as $type ) {
			$submitted   = is_array( $args[ $type . '_run_values' ] ) ? $args[ $type . '_run_values' ] : [];
			$template_id = absint( $args[ $type . '_template_id' ] );
			if ( ! empty( $submitted ) && ! $template_id ) {
				return new WP_Error(
					'worldgraph_generation_run_template_required',
					__( 'Choose one Template before applying run controls to a generation batch.', 'worldgraph' ),
					[ 'status' => 400, 'type' => $type ]
				);
			}
			if ( $template_id ) {
				$validated = Template_Run_Controls::validate( $template_id, $submitted );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				$run_values[ $type ] = $validated;
			}
		}
		$request_hash = hash( 'sha256', wp_json_encode( [
			'post_id'           => $post_id,
			'scope'             => $scope,
			'base_prompt'       => (string) $args['base_prompt'],
			'image_template_id' => absint( $args['image_template_id'] ),
			'video_template_id' => absint( $args['video_template_id'] ),
			'audio_template_id' => absint( $args['audio_template_id'] ),
			'image_run_values'  => $run_values['image'],
			'video_run_values'  => $run_values['video'],
			'audio_run_values'  => $run_values['audio'],
		] ) );

		$existing = self::batch_for_idempotency_key( $post_id, $requester_id, $idempotency_key, $batch_kind );
		if ( $existing ) {
			$stored_hash = (string) get_post_meta( $existing, self::REQUEST_HASH_META, true );
			if ( '' !== $stored_hash && ! hash_equals( $stored_hash, $request_hash ) ) {
				return new WP_Error( 'worldgraph_generation_idempotency_conflict', __( 'That idempotency key was already used for different generation settings.', 'worldgraph' ), [ 'status' => 409 ] );
			}
			if ( ! Generation_Batch::schedule() ) {
				return self::schedule_error();
			}
			return self::batch_status( $existing );
		}

		$plan = self::plan( $post_id, $scope, (string) $args['base_prompt'] );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$resolved_tasks = [];
		$missing        = [];
		$invalid_explicit = [];
		foreach ( $plan['tasks'] as $task ) {
			if ( ! user_can( $requester_id, 'edit_post', (int) $task['source_id'] ) ) {
				return new WP_Error(
					'worldgraph_generation_source_forbidden',
					sprintf(
						/* translators: %s: title of the Story Graph item. */
						__( 'You cannot generate media for %s.', 'worldgraph' ),
						$task['source_title']
					),
					[ 'status' => 403 ]
				);
			}
			$generation_required = ! array_key_exists( 'generation_required', $task ) || ! empty( $task['generation_required'] );
			$required            = self::REPRESENTATIVE_BATCH === $batch_kind || ! empty( $task['required'] );
			$explicit            = absint( $args[ (string) $task['type'] . '_template_id' ] ?? 0 );
			$template_id         = $generation_required ? self::resolve_template_id( $task, $explicit ) : 0;
			if ( ! $template_id ) {
				if ( $generation_required && $explicit ) {
					$invalid_explicit[] = [
						'template_id'  => $explicit,
						'source_id'    => (int) $task['source_id'],
						'source_title' => (string) $task['source_title'],
						'intent'       => (string) $task['intent'],
						'type'         => (string) $task['type'],
					];
					continue;
				}
				if ( $generation_required && $required ) {
					$missing[] = [
						'source_id'    => (int) $task['source_id'],
						'source_title' => (string) $task['source_title'],
						'intent'       => (string) $task['intent'],
						'type'         => (string) $task['type'],
					];
					continue;
				}
				$task['template_id']               = 0;
				$task['run_values']                = [];
				$task['default_values']            = [];
				$task['requested_run_values']      = [];
				$task['profile_values']            = [];
				$task['run_controls_fingerprint']  = '';
				$task['prompt_policy_fingerprint'] = '';
				$resolved_tasks[]                 = $task;
				continue;
			}
			$task['template_id'] = $template_id;
			if ( 'worldgraph_sound' === (string) $task['source_type'] && 'audio' === (string) $task['type'] ) {
				$copy_validation = self::validate_sound_prompt_copy( (int) $task['source_id'], $template_id );
				if ( is_wp_error( $copy_validation ) ) {
					return $copy_validation;
				}
			}
			$task['prompt']      = self::finalize_task_prompt(
				$task,
				$template_id,
				self::REPRESENTATIVE_BATCH === $batch_kind ? (string) $args['base_prompt'] : ''
			);
			$description         = Template_Run_Controls::describe( $template_id );
			$task['default_values'] = Generation_Run_Defaults::runtime_overrides(
				(int) $task['source_id'],
				$template_id,
				$description
			);
			$task['requested_run_values'] = $run_values[ (string) $task['type'] ];
			$task['run_values'] = Template_Run_Controls::validate_description(
				$description,
				array_merge( $task['default_values'], $task['requested_run_values'] )
			);
			if ( is_wp_error( $task['run_values'] ) ) {
				return $task['run_values'];
			}
			$task['profile_values'] = Asset_Generator::project_template_defaults(
				$template_id,
				Asset_Generator::project_media_profile( (int) $task['source_id'] ),
				$description
			);
			$task['run_controls_fingerprint'] = (string) ( $description['fingerprint'] ?? '' );
			$task['prompt_policy_fingerprint'] = Generation_Prompt_Policy::fingerprint(
				Generation_Prompt_Policy::for_template(
					$template_id,
					[
						'output_type' => (string) $task['type'],
						'post_type'   => (string) $task['source_type'],
						'intent'      => (string) $task['intent'],
					]
				)
			);
			$resolved_tasks[]    = $task;
		}

		if ( ! empty( $invalid_explicit ) ) {
			return new WP_Error(
				'worldgraph_generation_template_incompatible',
				__( 'A selected Template cannot run every matching task in this frozen plan. Refresh the plan and choose a common Template, or use each output’s configured Template.', 'worldgraph' ),
				[ 'status' => 409, 'incompatible' => $invalid_explicit ]
			);
		}
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'worldgraph_generation_template_missing',
				__( 'The generation plan cannot start until every required output has a runnable Template.', 'worldgraph' ),
				[ 'status' => 409, 'missing' => $missing ]
			);
		}

		$reservation = self::reserve_idempotency_key( $post_id, $requester_id, $idempotency_key, $request_hash, $batch_kind );
		if ( is_wp_error( $reservation ) ) {
			return $reservation;
		}
		if ( ! empty( $reservation['batch_id'] ) ) {
			if ( ! Generation_Batch::schedule() ) {
				return self::schedule_error();
			}
			return self::batch_status( (int) $reservation['batch_id'] );
		}
		$reservation_token = (string) $reservation['token'];

		$root  = get_post( $post_id );
		$batch_label = self::DEMONSTRATION_BATCH === $batch_kind
			? __( 'Demonstration video batch: %s', 'worldgraph' )
			: __( 'Representative media batch: %s', 'worldgraph' );
		$batch = wp_insert_post( [
			'post_type'   => 'worldgraph_gen',
			'post_status' => 'draft',
			'post_parent' => $post_id,
			'post_title'  => sprintf( $batch_label, $root instanceof \WP_Post ? $root->post_title : $post_id ),
		], true );
		if ( is_wp_error( $batch ) ) {
			self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token, $batch_kind );
			return $batch;
		}

		$batch_id    = (int) $batch;
		$frozen_plan = array_map( [ __CLASS__, 'freeze_task' ], $resolved_tasks, array_keys( $resolved_tasks ) );

		$meta = [
			self::BATCH_KIND_META        => $batch_kind,
			self::BATCH_SCOPE_META       => (string) $plan['scope'],
			self::IDEMPOTENCY_META       => $idempotency_key,
			self::REQUEST_HASH_META       => $request_hash,
			'_worldgraph_gen_requested_by' => $requester_id,
			'_worldgraph_gen_created'    => current_time( 'mysql' ),
			'_worldgraph_gen_total'      => count( $frozen_plan ),
			'_worldgraph_gen_workflow_version' => self::WORKFLOW_VERSION,
			self::BATCH_CURSOR_META      => 0,
			self::BATCH_PLAN_META        => $frozen_plan,
		];
		if ( self::DEMONSTRATION_BATCH === $batch_kind ) {
			$meta[ self::ASSEMBLY_PLAN_META ] = (array) ( $plan['assembly'] ?? [] );
		}
		$stored = true;
		foreach ( $meta as $key => $value ) {
			update_post_meta( $batch_id, $key, is_array( $value ) ? wp_slash( $value ) : $value );
			$persisted = get_post_meta( $batch_id, $key, true );
			if ( is_array( $value ) ? $persisted !== $value : (string) $persisted !== (string) $value ) {
				$stored = false;
				break;
			}
		}
		if ( ! $stored ) {
			wp_delete_post( $batch_id, true );
			self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token, $batch_kind );
			return new WP_Error( 'worldgraph_generation_batch_storage_failed', __( 'WordPress could not persist the generation plan.', 'worldgraph' ), [ 'status' => 500 ] );
		}

		// Ensure a wake-up exists before the root commit marker becomes visible.
		// An idempotent retry also schedules above, so a removed cron event heals.
		if ( ! Generation_Batch::schedule() ) {
			wp_delete_post( $batch_id, true );
			self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token, $batch_kind );
			return self::schedule_error();
		}

		// The root status is the coordinator's commit marker. Publish it only
		// after the complete frozen plan is durable and verified.
		update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_materializing' );
		if ( 'batch_materializing' !== get_post_meta( $batch_id, '_worldgraph_gen_status', true ) ) {
			wp_delete_post( $batch_id, true );
			self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token, $batch_kind );
			return new WP_Error( 'worldgraph_generation_batch_storage_failed', __( 'WordPress could not commit the generation plan.', 'worldgraph' ), [ 'status' => 500 ] );
		}

		self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token, $batch_kind );
		if ( ! Generation_Batch::schedule() ) {
			return self::schedule_error();
		}
		return self::batch_status( $batch_id );
	}

	/** Reduce a planned task to the durable, provider-neutral coordinator contract. */
	private static function freeze_task( array $task, int $index ): array {
		$dependencies = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $task['dependencies'] ?? [] ) ) ) ) );
		$modalities   = array_values( array_unique( array_filter( array_map( [ Generation_Modality::class, 'sanitize' ], (array) ( $task['preferred_modalities'] ?? [] ) ) ) ) );
		$input_refs   = [];
		foreach ( (array) ( $task['input_refs'] ?? [] ) as $slot => $reference ) {
			$slot = sanitize_key( (string) $slot );
			if ( 'reference_images' === $slot ) {
				$input_refs[ $slot ] = array_values( array_filter( array_map( static function ( $item ): array {
					$key = is_array( $item ) ? sanitize_key( (string) ( $item['task_key'] ?? '' ) ) : '';
					return '' === $key ? [] : [ 'task_key' => $key ];
				}, (array) $reference ) ) );
				continue;
			}
			if ( ! in_array( $slot, Generation_Modality::MEDIA_SLOTS, true ) ) {
				continue;
			}
			$reference = is_array( $reference ) ? $reference : [ 'task_key' => $reference ];
			$primary   = sanitize_key( (string) ( $reference['task_key'] ?? '' ) );
			$fallback  = sanitize_key( (string) ( $reference['fallback_task_key'] ?? '' ) );
			if ( '' !== $primary ) {
				$input_refs[ $slot ] = [ 'task_key' => $primary ];
				if ( '' !== $fallback && $fallback !== $primary ) {
					$input_refs[ $slot ]['fallback_task_key'] = $fallback;
				}
			}
		}

		$frozen = [
			'step'                     => $index,
			'task_key'                 => sanitize_key( (string) ( $task['task_key'] ?? 'task-' . $index ) ),
			'source_id'                => absint( $task['source_id'] ?? 0 ),
			'source_type'              => sanitize_key( (string) ( $task['source_type'] ?? '' ) ),
			'source_title'             => sanitize_text_field( (string) ( $task['source_title'] ?? '' ) ),
			'workflow_id'              => sanitize_key( (string) ( $task['workflow_id'] ?? '' ) ),
			'intent'                   => sanitize_key( (string) ( $task['intent'] ?? '' ) ),
			'label'                    => sanitize_text_field( (string) ( $task['label'] ?? '' ) ),
			'type'                     => sanitize_key( (string) ( $task['type'] ?? '' ) ),
			'phase'                    => sanitize_key( (string) ( $task['phase'] ?? '' ) ),
			'required'                 => ! array_key_exists( 'required', $task ) || ! empty( $task['required'] ),
			'generation_required'      => ! array_key_exists( 'generation_required', $task ) || ! empty( $task['generation_required'] ),
			'featured'                 => ! empty( $task['featured'] ),
			'template_id'              => absint( $task['template_id'] ?? 0 ),
			'run_values'               => (array) ( $task['run_values'] ?? [] ),
			'default_values'           => (array) ( $task['default_values'] ?? [] ),
			'requested_run_values'     => (array) ( $task['requested_run_values'] ?? [] ),
			'profile_values'           => (array) ( $task['profile_values'] ?? [] ),
			'run_controls_fingerprint' => sanitize_text_field( (string) ( $task['run_controls_fingerprint'] ?? '' ) ),
			'prompt_policy_fingerprint' => sanitize_text_field( (string) ( $task['prompt_policy_fingerprint'] ?? '' ) ),
			'prompt'                   => (string) ( $task['prompt'] ?? '' ),
			'prompt_hash'              => hash( 'sha256', (string) ( $task['prompt'] ?? '' ) ),
			'dependencies'             => $dependencies,
			'input_refs'               => $input_refs,
			'preferred_modalities'     => $modalities,
			'fallback_task_key'        => sanitize_key( (string) ( $task['fallback_task_key'] ?? '' ) ),
		];
		foreach ( [ 'scene_id', 'shot_id', 'location_id', 'character_id', 'existing_asset_id', 'scene_order', 'shot_order', 'timeline_order' ] as $key ) {
			if ( array_key_exists( $key, $task ) ) {
				$frozen[ $key ] = (int) $task[ $key ];
			}
		}
		foreach ( [ 'character_ids', 'used_in_scene_ids', 'used_in_shot_ids' ] as $key ) {
			if ( array_key_exists( $key, $task ) ) {
				$frozen[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', (array) $task[ $key ] ) ) ) );
			}
		}
		foreach ( [ 'audio_role', 'start_timecode', 'duration', 'diegetic' ] as $key ) {
			if ( array_key_exists( $key, $task ) ) {
				$frozen[ $key ] = sanitize_text_field( (string) $task[ $key ] );
			}
		}

		return $frozen;
	}

	/** Report a durable batch whose worker wake-up could not be guaranteed. */
	private static function schedule_error(): WP_Error {
		return new WP_Error(
			'worldgraph_generation_schedule_failed',
			__( 'WordPress could not schedule the generation batch. Retry the same request to resume it safely.', 'worldgraph' ),
			[ 'status' => 503 ]
		);
	}

	/** Find an existing caller-scoped batch for a retry-safe start request. */
	private static function batch_for_idempotency_key( int $post_id, int $requester_id, string $idempotency_key, string $batch_kind ): int {
		$existing = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'post_parent'    => $post_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => $batch_kind ],
				[ 'key' => self::IDEMPOTENCY_META, 'value' => $idempotency_key ],
				[ 'key' => '_worldgraph_gen_requested_by', 'value' => $requester_id ],
				[ 'key' => '_worldgraph_gen_status', 'compare' => 'EXISTS' ],
			],
		] );

		return $existing ? (int) $existing[0] : 0;
	}

	/** Atomically reserve a caller/root idempotency key before inserting a batch. */
	private static function reserve_idempotency_key( int $post_id, int $requester_id, string $idempotency_key, string $request_hash, string $batch_kind ) {
		global $wpdb;

		$existing = self::batch_for_idempotency_key( $post_id, $requester_id, $idempotency_key, $batch_kind );
		if ( $existing ) {
			$stored_hash = (string) get_post_meta( $existing, self::REQUEST_HASH_META, true );
			if ( '' !== $stored_hash && ! hash_equals( $stored_hash, $request_hash ) ) {
				return new WP_Error( 'worldgraph_generation_idempotency_conflict', __( 'That idempotency key was already used for different generation settings.', 'worldgraph' ), [ 'status' => 409 ] );
			}
			return [ 'batch_id' => $existing, 'token' => '' ];
		}

		$option_name = self::idempotency_option_name( $post_id, $requester_id, $idempotency_key, $batch_kind );
		$token       = wp_generate_uuid4();
		$value       = [ 'token' => $token, 'request_hash' => $request_hash, 'expires' => time() + self::IDEMPOTENCY_LOCK_TTL ];
		if ( add_option( $option_name, $value, '', false ) ) {
			return [ 'batch_id' => 0, 'token' => $token ];
		}

		$current = get_option( $option_name, [] );
		if ( is_array( $current ) && ! hash_equals( (string) ( $current['request_hash'] ?? '' ), $request_hash ) ) {
			return new WP_Error( 'worldgraph_generation_idempotency_conflict', __( 'That idempotency key is already reserved for different generation settings.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		if ( ! is_array( $current ) || absint( $current['expires'] ?? 0 ) >= time() ) {
			return new WP_Error( 'worldgraph_generation_idempotency_pending', __( 'That generation request is already being started. Retry the same request shortly.', 'worldgraph' ), [ 'status' => 409 ] );
		}

		$updated = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => maybe_serialize( $value ) ],
			[
				'option_name'  => $option_name,
				'option_value' => maybe_serialize( $current ),
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( $option_name, 'options' );
		return 1 === $updated
			? [ 'batch_id' => 0, 'token' => $token ]
			: new WP_Error( 'worldgraph_generation_idempotency_pending', __( 'That generation request is already being started. Retry the same request shortly.', 'worldgraph' ), [ 'status' => 409 ] );
	}

	/** Release only the matching idempotency reservation. */
	private static function release_idempotency_key( int $post_id, int $requester_id, string $idempotency_key, string $token, string $batch_kind ): void {
		global $wpdb;

		$option_name = self::idempotency_option_name( $post_id, $requester_id, $idempotency_key, $batch_kind );
		$current     = get_option( $option_name, [] );
		if ( ! is_array( $current ) || ! hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			return;
		}
		$wpdb->delete(
			$wpdb->options,
			[ 'option_name' => $option_name, 'option_value' => maybe_serialize( $current ) ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( $option_name, 'options' );
	}

	/** Stable, bounded option name for one caller/root operation key. */
	private static function idempotency_option_name( int $post_id, int $requester_id, string $idempotency_key, string $batch_kind ): string {
		return 'worldgraph_gen_idem_' . hash( 'sha256', $post_id . ':' . $requester_id . ':' . $batch_kind . ':' . $idempotency_key );
	}

	/** Materialize representative and dependency-aware demonstration batches. */
	public static function process_batches(): void {
		$lock_token = self::acquire_coordinator_lock();
		if ( '' === $lock_token ) {
			Generation_Batch::schedule();
			return;
		}

		$batches = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 4,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::supported_batch_kinds(), 'compare' => 'IN' ],
				[ 'key' => '_worldgraph_gen_status', 'value' => [ 'batch_materializing', 'batch_activating', 'batch_waiting_assembly' ], 'compare' => 'IN' ],
				[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'NOT EXISTS' ],
			],
		] );

		try {
			foreach ( $batches as $batch_id ) {
				if ( ! self::refresh_coordinator_lock( $lock_token ) ) {
					break;
				}
				$status = (string) get_post_meta( $batch_id, '_worldgraph_gen_status', true );
				$kind   = (string) get_post_meta( $batch_id, self::BATCH_KIND_META, true );
				if ( 'batch_materializing' === $status ) {
					if ( self::DEMONSTRATION_BATCH === $kind ) {
						self::materialize_demonstration_batch( (int) $batch_id, $lock_token );
					} else {
						self::materialize_batch( (int) $batch_id, $lock_token );
					}
				} elseif ( self::REPRESENTATIVE_BATCH === $kind && 'batch_activating' === $status ) {
					self::activate_batch( (int) $batch_id, $lock_token );
				} elseif ( self::DEMONSTRATION_BATCH === $kind && 'batch_waiting_assembly' === $status ) {
					self::maybe_assemble_demonstration( (int) $batch_id, $lock_token );
				}
			}
		} finally {
			self::release_coordinator_lock( $lock_token );
		}

		if ( self::has_pending_batches() ) {
			Generation_Batch::schedule();
		}
		if ( self::has_pending_assemblies() ) {
			self::schedule_assembly();
		}
	}

	/** Acquire or atomically replace an expired coordinator lease. */
	private static function acquire_coordinator_lock(): string {
		global $wpdb;

		$token = wp_generate_uuid4();
		$value = [ 'token' => $token, 'expires' => time() + self::COORDINATOR_LOCK_TTL ];
		if ( add_option( self::COORDINATOR_LOCK, $value, '', false ) ) {
			return $token;
		}

		$current = get_option( self::COORDINATOR_LOCK, [] );
		if ( ! is_array( $current ) || absint( $current['expires'] ?? 0 ) >= time() ) {
			return '';
		}
		$updated = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => maybe_serialize( $value ) ],
			[
				'option_name'  => self::COORDINATOR_LOCK,
				'option_value' => maybe_serialize( $current ),
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( self::COORDINATOR_LOCK, 'options' );

		return 1 === $updated ? $token : '';
	}

	/** Renew the coordinator lease and fail closed if another process owns it. */
	private static function refresh_coordinator_lock( string $token ): bool {
		global $wpdb;

		$current = get_option( self::COORDINATOR_LOCK, [] );
		if ( ! is_array( $current ) || ! hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			return false;
		}

		$renewed            = $current;
		$renewed['expires'] = time() + self::COORDINATOR_LOCK_TTL;
		if ( absint( $current['expires'] ?? 0 ) >= $renewed['expires'] - 1 ) {
			return true;
		}

		$updated = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => maybe_serialize( $renewed ) ],
			[
				'option_name'  => self::COORDINATOR_LOCK,
				'option_value' => maybe_serialize( $current ),
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( self::COORDINATOR_LOCK, 'options' );
		if ( 1 === $updated ) {
			return true;
		}

		$latest = get_option( self::COORDINATOR_LOCK, [] );
		return is_array( $latest )
			&& hash_equals( $token, (string) ( $latest['token'] ?? '' ) )
			&& absint( $latest['expires'] ?? 0 ) >= $renewed['expires'] - 1;
	}

	/** Release only the coordinator lease owned by this process. */
	private static function release_coordinator_lock( string $token ): void {
		global $wpdb;

		$current = get_option( self::COORDINATOR_LOCK, [] );
		if ( ! is_array( $current ) || ! hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			return;
		}
		$wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => self::COORDINATOR_LOCK,
				'option_value' => maybe_serialize( $current ),
			],
			[ '%s', '%s' ]
		);
		wp_cache_delete( self::COORDINATOR_LOCK, 'options' );
	}

	/** Persist a bounded set of non-runnable staged jobs from one frozen plan. */
	private static function materialize_batch( int $batch_id, string $lock_token ): void {
		if ( 'batch_materializing' !== get_post_meta( $batch_id, '_worldgraph_gen_status', true ) || self::is_cancel_requested( $batch_id ) ) {
			return;
		}
		$plan         = get_post_meta( $batch_id, self::BATCH_PLAN_META, true );
		$plan         = is_array( $plan ) ? array_values( $plan ) : [];
		$cursor       = absint( get_post_meta( $batch_id, self::BATCH_CURSOR_META, true ) );
		$requester_id = absint( get_post_meta( $batch_id, '_worldgraph_gen_requested_by', true ) );
		$limit        = min( count( $plan ), $cursor + self::MATERIALIZE_PER_TICK );

		for ( $index = $cursor; $index < $limit; ++$index ) {
			if ( ! self::refresh_coordinator_lock( $lock_token ) || self::is_cancel_requested( $batch_id ) || 'batch_materializing' !== get_post_meta( $batch_id, '_worldgraph_gen_status', true ) ) {
				return;
			}
			$task = (array) $plan[ $index ];
			if ( self::find_child_for_step( $batch_id, $index ) ) {
				update_post_meta( $batch_id, self::BATCH_CURSOR_META, $index + 1 );
				continue;
			}

			$result = Asset_Generator::queue_for_post( (int) $task['source_id'], [
				'type'               => (string) $task['type'],
					'prompt'             => (string) $task['prompt'],
					'prompt_is_composed' => true,
					'prompt_profiled'    => true,
				'set_featured'       => 'image' === $task['type'] && ! empty( $task['featured'] ),
				'create_asset'       => true,
				'template_id'        => (int) $task['template_id'],
				'run_values'         => (array) ( $task['run_values'] ?? [] ),
				'default_values'     => (array) ( $task['default_values'] ?? [] ),
				'requested_run_values' => (array) ( $task['requested_run_values'] ?? [] ),
				'profile_values'     => (array) ( $task['profile_values'] ?? [] ),
				'profile_values_frozen' => array_key_exists( 'profile_values', $task ),
				'run_values_validated' => true,
				'run_defaults_frozen' => true,
				'intent'             => (string) $task['intent'],
				'batch_id'           => $batch_id,
				'batch_step'         => $index,
				'requester_id'       => $requester_id,
				'initial_status'     => 'staged',
				'schedule'           => false,
			] );
			if ( is_wp_error( $result ) ) {
				if ( ! self::is_cancel_requested( $batch_id ) ) {
					self::fail_staged_batch( $batch_id, $result );
				}
				return;
			}
			if ( ! self::refresh_coordinator_lock( $lock_token ) ) {
				return;
			}
			if ( self::is_cancel_requested( $batch_id ) ) {
				update_post_meta( (int) $result['generation_id'], '_worldgraph_gen_status', 'cancelled', 'staged' );
				return;
			}
			update_post_meta( $batch_id, self::BATCH_CURSOR_META, $index + 1 );
		}

		if ( $limit >= count( $plan ) && ! self::is_cancel_requested( $batch_id ) ) {
			update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_activating', 'batch_materializing' );
		}
	}

	/** Queue demonstration tasks in order, waiting only for media inputs the chosen Template consumes. */
	private static function materialize_demonstration_batch( int $batch_id, string $lock_token ): void {
		if ( 'batch_materializing' !== get_post_meta( $batch_id, '_worldgraph_gen_status', true ) || self::is_cancel_requested( $batch_id ) ) {
			return;
		}
		$plan         = get_post_meta( $batch_id, self::BATCH_PLAN_META, true );
		$plan         = is_array( $plan ) ? array_values( $plan ) : [];
		$cursor       = absint( get_post_meta( $batch_id, self::BATCH_CURSOR_META, true ) );
		$requester_id = absint( get_post_meta( $batch_id, '_worldgraph_gen_requested_by', true ) );
		$limit        = min( count( $plan ), $cursor + self::MATERIALIZE_PER_TICK );

		for ( $index = $cursor; $index < $limit; ++$index ) {
			if ( ! self::refresh_coordinator_lock( $lock_token ) || self::is_cancel_requested( $batch_id ) || 'batch_materializing' !== get_post_meta( $batch_id, '_worldgraph_gen_status', true ) ) {
				return;
			}
			$task = (array) $plan[ $index ];
			if ( self::find_child_for_step( $batch_id, $index ) ) {
				update_post_meta( $batch_id, self::BATCH_CURSOR_META, $index + 1 );
				continue;
			}

			if ( empty( $task['generation_required'] ) ) {
				$placeholder = self::create_placeholder_child(
					$batch_id,
					$index,
					$task,
					'skipped',
					__( 'Generation was not required because linked media or the declared fallback can be used during assembly.', 'worldgraph' )
				);
				if ( is_wp_error( $placeholder ) ) {
					self::fail_demonstration_batch( $batch_id, $placeholder );
					return;
				}
				update_post_meta( $batch_id, self::BATCH_CURSOR_META, $index + 1 );
				continue;
			}

			$template_id = absint( $task['template_id'] ?? 0 );
			if ( ! $template_id ) {
				$placeholder = self::create_placeholder_child(
					$batch_id,
					$index,
					$task,
					empty( $task['required'] ) ? 'skipped' : 'failed',
					__( 'No runnable Template was available for this optional demonstration enhancement.', 'worldgraph' )
				);
				if ( is_wp_error( $placeholder ) ) {
					self::fail_demonstration_batch( $batch_id, $placeholder );
					return;
				}
				update_post_meta( $batch_id, self::BATCH_CURSOR_META, $index + 1 );
				continue;
			}

			$resolved = self::resolve_demonstration_inputs( $batch_id, $task, $plan );
			if ( 'pending' === ( $resolved['status'] ?? '' ) ) {
				Generation_Batch::schedule();
				return;
			}
			if ( 'unavailable' === ( $resolved['status'] ?? '' ) ) {
				$placeholder = self::create_placeholder_child(
					$batch_id,
					$index,
					$task,
					empty( $task['required'] ) ? 'skipped' : 'failed',
					(string) ( $resolved['error'] ?? __( 'A required generated media reference was unavailable.', 'worldgraph' ) )
				);
				if ( is_wp_error( $placeholder ) ) {
					self::fail_demonstration_batch( $batch_id, $placeholder );
					return;
				}
				update_post_meta( $batch_id, self::BATCH_CURSOR_META, $index + 1 );
				continue;
			}

			$inputs   = (array) ( $resolved['inputs'] ?? [] );
			$persisted = self::persist_resolved_inputs( $batch_id, $index, $inputs );
			if ( is_wp_error( $persisted ) ) {
				self::fail_demonstration_batch( $batch_id, $persisted );
				return;
			}

			$result = Asset_Generator::queue_for_post( (int) $task['source_id'], [
				'type'                  => (string) $task['type'],
				'prompt'                => (string) $task['prompt'],
				'prompt_is_composed'    => true,
				'prompt_profiled'       => true,
				'set_featured'          => 'image' === $task['type'] && ! empty( $task['featured'] ),
				'create_asset'          => true,
				'template_id'           => $template_id,
				'inputs'                => $inputs,
				'run_values'            => (array) ( $task['run_values'] ?? [] ),
				'default_values'        => (array) ( $task['default_values'] ?? [] ),
				'requested_run_values'  => (array) ( $task['requested_run_values'] ?? [] ),
				'profile_values'        => (array) ( $task['profile_values'] ?? [] ),
				'profile_values_frozen' => array_key_exists( 'profile_values', $task ),
				'run_values_validated'  => true,
				'run_defaults_frozen'   => true,
				'intent'                => (string) $task['intent'],
				'batch_id'              => $batch_id,
				'batch_step'            => $index,
				'requester_id'          => $requester_id,
				'initial_status'        => 'queued',
				'schedule'              => false,
			] );
			if ( is_wp_error( $result ) ) {
				$placeholder = self::create_placeholder_child( $batch_id, $index, $task, 'failed', $result->get_error_message() );
				if ( is_wp_error( $placeholder ) ) {
					self::fail_demonstration_batch( $batch_id, $placeholder );
					return;
				}
			} else {
				update_post_meta( (int) $result['generation_id'], '_worldgraph_gen_task_key', sanitize_key( (string) ( $task['task_key'] ?? '' ) ) );
			}
			if ( ! self::refresh_coordinator_lock( $lock_token ) ) {
				return;
			}
			if ( self::is_cancel_requested( $batch_id ) ) {
				if ( ! is_wp_error( $result ) ) {
					update_post_meta( (int) $result['generation_id'], '_worldgraph_gen_status', 'cancelled', 'queued' );
				}
				return;
			}
			update_post_meta( $batch_id, self::BATCH_CURSOR_META, $index + 1 );
		}

		if ( $limit >= count( $plan ) && ! self::is_cancel_requested( $batch_id ) ) {
			update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_waiting_assembly', 'batch_materializing' );
		}
		Generation_Batch::schedule();
	}

	/** Resolve chosen Template media slots from completed sibling task outputs. */
	private static function resolve_demonstration_inputs( int $batch_id, array $task, array $plan ): array {
		$template_id = absint( $task['template_id'] ?? 0 );
		$modality    = Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template_id, 'modality' ) );
		$media_slots = Generation_Modality::media_inputs( $modality );
		$required    = array_intersect( Generation_Modality::required_inputs( $modality ), Generation_Modality::MEDIA_SLOTS );
		$references  = is_array( $task['input_refs'] ?? null ) ? $task['input_refs'] : [];
		$bindings    = Template_Bindings::resolve( $template_id, absint( $task['source_id'] ?? 0 ) );
		$inputs      = array_map( 'strval', array_intersect_key( $bindings, array_flip( $media_slots ) ) );

		foreach ( $media_slots as $slot ) {
			$reference = is_array( $references[ $slot ] ?? null ) ? $references[ $slot ] : [];
			$task_key  = sanitize_key( (string) ( $reference['task_key'] ?? '' ) );
			$fallback  = sanitize_key( (string) ( $reference['fallback_task_key'] ?? '' ) );
			if ( '' === $task_key ) {
				if ( in_array( $slot, $required, true ) && empty( $bindings[ $slot ] ) ) {
					return [ 'status' => 'unavailable', 'inputs' => [], 'error' => sprintf( __( 'The selected Template requires %s, but the demonstration plan has no source for it.', 'worldgraph' ), $slot ) ];
				}
				continue;
			}

			$state = self::demonstration_reference_state( $batch_id, $task_key, $slot, $plan );
			if ( 'pending' === $state['status'] ) {
				return [ 'status' => 'pending', 'inputs' => [] ];
			}
			if ( 'ready' !== $state['status'] && '' !== $fallback ) {
				$state = self::demonstration_reference_state( $batch_id, $fallback, $slot, $plan );
				if ( 'pending' === $state['status'] ) {
					return [ 'status' => 'pending', 'inputs' => [] ];
				}
			}
			if ( 'ready' === $state['status'] ) {
				$inputs[ $slot ] = (string) $state['attachment_id'];
				continue;
			}
			if ( in_array( $slot, $required, true ) && empty( $bindings[ $slot ] ) ) {
				return [
					'status' => 'unavailable',
					'inputs' => [],
					'error'  => sprintf( __( 'The generated media reference for %s did not complete successfully.', 'worldgraph' ), $slot ),
				];
			}
		}

		return [ 'status' => 'ready', 'inputs' => $inputs ];
	}

	/** Inspect one symbolic sibling reference and return its provenance-checked attachment. */
	private static function demonstration_reference_state( int $batch_id, string $task_key, string $slot, array $plan ): array {
		$step = null;
		foreach ( $plan as $index => $candidate ) {
			if ( is_array( $candidate ) && $task_key === (string) ( $candidate['task_key'] ?? '' ) ) {
				$step = isset( $candidate['step'] ) ? (int) $candidate['step'] : (int) $index;
				break;
			}
		}
		if ( null === $step ) {
			return [ 'status' => 'unavailable', 'attachment_id' => 0 ];
		}
		$job_id = self::find_child_for_step( $batch_id, $step );
		if ( ! $job_id ) {
			return [ 'status' => 'pending', 'attachment_id' => 0 ];
		}
		$status = sanitize_key( (string) get_post_meta( $job_id, '_worldgraph_gen_status', true ) );
		if ( in_array( $status, self::ACTIVE_JOB_STATES, true ) ) {
			return [ 'status' => 'pending', 'attachment_id' => 0 ];
		}
		if ( 'completed' !== $status ) {
			return [ 'status' => 'unavailable', 'attachment_id' => 0 ];
		}

		$expected = in_array( $slot, [ 'image', 'start_frame', 'end_frame' ], true ) ? 'image/' : ( 'video' === $slot ? 'video/' : 'audio/' );
		$ids      = (array) get_post_meta( $job_id, '_worldgraph_gen_attachment_ids', true );
		array_unshift( $ids, get_post_meta( $job_id, '_worldgraph_gen_attachment_id', true ) );
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) || ! str_starts_with( strtolower( (string) get_post_mime_type( $attachment_id ) ), $expected ) ) {
				continue;
			}
			$attachment_batch = absint( get_post_meta( $attachment_id, self::BATCH_ID_META, true ) );
			$attachment_job   = absint( get_post_meta( $attachment_id, '_worldgraph_gen_job_id', true ) );
			if ( $batch_id === $attachment_batch && ( ! $attachment_job || $job_id === $attachment_job ) ) {
				return [ 'status' => 'ready', 'attachment_id' => $attachment_id ];
			}
		}

		return [ 'status' => 'unavailable', 'attachment_id' => 0 ];
	}

	/** Persist the exact symbolic-reference resolution before a child becomes queue-visible. */
	private static function persist_resolved_inputs( int $batch_id, int $step, array $inputs ) {
		$inputs = array_map( 'strval', array_intersect_key( $inputs, array_flip( Generation_Modality::MEDIA_SLOTS ) ) );
		$plan   = get_post_meta( $batch_id, self::BATCH_PLAN_META, true );
		if ( ! is_array( $plan ) || ! isset( $plan[ $step ] ) || ! is_array( $plan[ $step ] ) ) {
			return new WP_Error( 'worldgraph_generation_plan_invalid', __( 'The frozen demonstration task could not be resolved.', 'worldgraph' ) );
		}
		if ( array_key_exists( 'resolved_inputs', $plan[ $step ] ) ) {
			return $inputs === (array) $plan[ $step ]['resolved_inputs']
				? true
				: new WP_Error( 'worldgraph_generation_input_conflict', __( 'A frozen demonstration task resolved to different media inputs.', 'worldgraph' ) );
		}
		$plan[ $step ]['resolved_inputs'] = $inputs;
		update_post_meta( $batch_id, self::BATCH_PLAN_META, wp_slash( $plan ) );
		$stored = get_post_meta( $batch_id, self::BATCH_PLAN_META, true );
		return is_array( $stored ) && isset( $stored[ $step ]['resolved_inputs'] ) && $inputs === $stored[ $step ]['resolved_inputs']
			? true
			: new WP_Error( 'worldgraph_generation_plan_storage_failed', __( 'WordPress could not freeze the resolved demonstration media inputs.', 'worldgraph' ) );
	}

	/** Create a terminal child row for an optional fallback or failed enhancement. */
	private static function create_placeholder_child( int $batch_id, int $step, array $task, string $status, string $error ) {
		$status = in_array( $status, [ 'skipped', 'failed', 'cancelled' ], true ) ? $status : 'skipped';
		$job_id = wp_insert_post( [
			'post_type'   => 'worldgraph_gen',
			'post_status' => 'draft',
			'post_parent' => absint( $task['source_id'] ?? 0 ),
			'post_title'  => sprintf( __( '%1$s generation: %2$s', 'worldgraph' ), ucfirst( sanitize_key( (string) ( $task['type'] ?? 'media' ) ) ), sanitize_text_field( (string) ( $task['source_title'] ?? '' ) ) ),
		], true );
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}
		$meta = [
			self::BATCH_ID_META                  => $batch_id,
			self::STEP_META                      => $step,
			self::INTENT_META                    => sanitize_key( (string) ( $task['intent'] ?? '' ) ),
			'_worldgraph_gen_task_key'           => sanitize_key( (string) ( $task['task_key'] ?? '' ) ),
			'_worldgraph_gen_type'               => sanitize_key( (string) ( $task['type'] ?? '' ) ),
			'_worldgraph_gen_source_post_id'     => absint( $task['source_id'] ?? 0 ),
			'_worldgraph_gen_requested_by'       => absint( get_post_meta( $batch_id, '_worldgraph_gen_requested_by', true ) ),
			'_worldgraph_gen_workflow_version'   => self::WORKFLOW_VERSION,
			'_worldgraph_gen_created'            => current_time( 'mysql' ),
			'_worldgraph_gen_error'              => sanitize_text_field( $error ),
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( (int) $job_id, $key, $value );
		}
		update_post_meta( (int) $job_id, '_worldgraph_gen_status', $status );
		if ( $status !== get_post_meta( (int) $job_id, '_worldgraph_gen_status', true ) ) {
			wp_delete_post( (int) $job_id, true );
			return new WP_Error( 'worldgraph_generation_placeholder_storage_failed', __( 'WordPress could not persist a demonstration fallback job.', 'worldgraph' ) );
		}

		return (int) $job_id;
	}

	/** Stop only coordinator expansion when its durable plan can no longer be represented. */
	private static function fail_demonstration_batch( int $batch_id, WP_Error $error ): void {
		if ( self::is_cancel_requested( $batch_id ) ) {
			return;
		}
		if ( false !== update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_failed', 'batch_materializing' ) ) {
			update_post_meta( $batch_id, '_worldgraph_gen_error', sanitize_text_field( $error->get_error_message() ) );
		}
	}

	/** Assemble a demonstration once every materialized child has reached a terminal state. */
	private static function maybe_assemble_demonstration( int $batch_id, string $lock_token ): void {
		if ( self::is_cancel_requested( $batch_id ) ) {
			return;
		}
		$status = (string) get_post_meta( $batch_id, '_worldgraph_gen_status', true );
		if ( 'batch_waiting_assembly' !== $status || ! self::refresh_coordinator_lock( $lock_token ) ) {
			return;
		}

		$active = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_ID_META, 'value' => $batch_id ],
				[ 'key' => '_worldgraph_gen_status', 'value' => self::ACTIVE_JOB_STATES, 'compare' => 'IN' ],
			],
		] );
		if ( ! empty( $active ) ) {
			Generation_Batch::schedule();
			return;
		}
		if ( false === update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_assembling', 'batch_waiting_assembly' ) ) {
			return;
		}
		$started = time();
		update_post_meta( $batch_id, '_worldgraph_gen_assembly_started_at', $started );
		update_post_meta( $batch_id, '_worldgraph_gen_assembly_heartbeat', $started );
		if ( ! self::schedule_assembly() ) {
			$error = new WP_Error( 'worldgraph_rough_cut_schedule_failed', __( 'WordPress could not schedule the rough-cut assembly worker.', 'worldgraph' ) );
			self::finish_assembly( $batch_id, $error );
		}
	}

	/** Run the independently scheduled rough-cut worker without blocking provider polling. */
	public static function process_assembly_queue(): void {
		// The lease must outlive the longest bounded FFmpeg stage. Heartbeats are
		// the faster liveness signal, but the lease remains a safe fallback when
		// a metadata write fails.
		$lock_token = self::acquire_named_lock(
			self::ASSEMBLY_LOCK,
			2 * Rough_Cut_Assembler::PROCESS_TIMEOUT + self::COORDINATOR_LOCK_TTL
		);
		if ( '' === $lock_token ) {
			self::schedule_assembly( 60 );
			return;
		}

		try {
			if ( self::cleanup_cancelled_assembly_state() ) {
				return;
			}
			$batches = get_posts( [
				'post_type'      => 'worldgraph_gen',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => [
					[ 'key' => self::BATCH_KIND_META, 'value' => self::DEMONSTRATION_BATCH ],
					[ 'key' => '_worldgraph_gen_status', 'value' => 'batch_assembling' ],
					[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'NOT EXISTS' ],
				],
			] );
			if ( empty( $batches ) ) {
				return;
			}

			$batch_id    = (int) $batches[0];
			$worker      = (string) get_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token', true );
			$heartbeat   = absint( get_post_meta( $batch_id, '_worldgraph_gen_assembly_heartbeat', true ) );
			$started     = absint( get_post_meta( $batch_id, '_worldgraph_gen_assembly_started_at', true ) );
			$liveness    = $heartbeat ?: $started;
			$stale_after = 2 * Rough_Cut_Assembler::PROCESS_TIMEOUT + self::COORDINATOR_LOCK_TTL;
			if ( '' !== $worker && $liveness && $liveness + $stale_after >= time() ) {
				self::schedule_assembly( 60 );
				return;
			}

			update_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token', $lock_token );
			if ( ! hash_equals( $lock_token, (string) get_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token', true ) ) ) {
				self::schedule_assembly( 60 );
				return;
			}
			update_post_meta( $batch_id, '_worldgraph_gen_assembly_heartbeat', time() );
			update_post_meta( $batch_id, '_worldgraph_gen_assembly_attempts', absint( get_post_meta( $batch_id, '_worldgraph_gen_assembly_attempts', true ) ) + 1 );
			$result = method_exists( Rough_Cut_Assembler::class, 'advance' )
				? Rough_Cut_Assembler::advance( $batch_id )
				: Rough_Cut_Assembler::assemble( $batch_id );
			if ( ! hash_equals( $lock_token, (string) get_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token', true ) ) ) {
				return;
			}
			if ( is_array( $result ) && 'pending' === ( $result['status'] ?? '' ) ) {
				update_post_meta( $batch_id, '_worldgraph_gen_assembly_progress', wp_slash( $result ) );
				delete_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token' );
				delete_post_meta( $batch_id, '_worldgraph_gen_assembly_heartbeat' );
				if ( ! self::schedule_assembly() ) {
					Rough_Cut_Assembler::cancel( $batch_id );
					self::finish_assembly( $batch_id, new WP_Error( 'worldgraph_rough_cut_schedule_failed', __( 'WordPress could not reschedule the incomplete rough-cut assembly.', 'worldgraph' ) ) );
				}
				return;
			}
			self::finish_assembly( $batch_id, $result );
		} finally {
			self::release_named_lock( self::ASSEMBLY_LOCK, $lock_token );
		}

		if ( self::has_pending_assemblies() ) {
			self::schedule_assembly();
		}
	}

	/**
	 * Remove a cancelled between-tick assembly state once no live worker owns it.
	 *
	 * A running FFmpeg process polls the durable cancellation marker and performs
	 * its own cleanup. This recovery path handles cancellation between ticks and
	 * a worker that died before observing the marker.
	 */
	private static function cleanup_cancelled_assembly_state(): bool {
		$batches = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::DEMONSTRATION_BATCH ],
				[ 'key' => '_worldgraph_gen_status', 'value' => 'batch_cancelling' ],
				[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'EXISTS' ],
				[ 'key' => Rough_Cut_Assembler::STATE_META, 'compare' => 'EXISTS' ],
			],
		] );
		if ( empty( $batches ) ) {
			return false;
		}

		$batch_id    = (int) $batches[0];
		$worker      = (string) get_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token', true );
		$heartbeat   = absint( get_post_meta( $batch_id, '_worldgraph_gen_assembly_heartbeat', true ) );
		$started     = absint( get_post_meta( $batch_id, '_worldgraph_gen_assembly_started_at', true ) );
		$liveness    = $heartbeat ?: $started;
		$stale_after = 2 * Rough_Cut_Assembler::PROCESS_TIMEOUT + self::COORDINATOR_LOCK_TTL;
		if ( '' !== $worker && $liveness && $liveness + $stale_after >= time() ) {
			self::schedule_assembly( 60 );
			return true;
		}

		Rough_Cut_Assembler::cancel( $batch_id );
		delete_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token' );
		delete_post_meta( $batch_id, '_worldgraph_gen_assembly_heartbeat' );
		delete_post_meta( $batch_id, '_worldgraph_gen_assembly_progress' );
		if ( self::has_cancelled_assembly_state() ) {
			self::schedule_assembly();
		}

		return false;
	}

	/** Whether another cancelled demonstration still has durable temp state. */
	private static function has_cancelled_assembly_state(): bool {
		$batches = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::DEMONSTRATION_BATCH ],
				[ 'key' => '_worldgraph_gen_status', 'value' => 'batch_cancelling' ],
				[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'EXISTS' ],
				[ 'key' => Rough_Cut_Assembler::STATE_META, 'compare' => 'EXISTS' ],
			],
		] );

		return ! empty( $batches );
	}

	/** Publish a verified terminal assembly result, unless cancellation won first. */
	private static function finish_assembly( int $batch_id, $result ): void {
		delete_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token' );
		delete_post_meta( $batch_id, '_worldgraph_gen_assembly_heartbeat' );
		delete_post_meta( $batch_id, '_worldgraph_gen_assembly_progress' );
		if ( self::is_cancel_requested( $batch_id ) ) {
			return;
		}
		if ( ! is_wp_error( $result ) ) {
			$result = self::verified_assembly_result( $batch_id, $result );
		}
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$record = [
				'status' => 'failed',
				'code'   => sanitize_key( (string) $result->get_error_code() ),
				'error'  => sanitize_text_field( $result->get_error_message() ),
				'data'   => is_array( $data ) ? $data : [],
			];
			if ( ! self::store_assembly_record( $batch_id, $record ) ) {
				$record['error'] = __( 'WordPress could not persist the rough-cut assembly result.', 'worldgraph' );
			}
			if ( self::is_cancel_requested( $batch_id ) ) {
				delete_post_meta( $batch_id, self::ASSEMBLY_META );
				return;
			}
			update_post_meta( $batch_id, '_worldgraph_gen_error', $record['error'] );
			$transitioned = update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_assembly_failed', 'batch_assembling' );
			if ( false === $transitioned && self::is_cancel_requested( $batch_id ) ) {
				delete_post_meta( $batch_id, self::ASSEMBLY_META );
				delete_post_meta( $batch_id, '_worldgraph_gen_error' );
			}
			return;
		}

		$record           = (array) $result;
		$record['status'] = 'completed';
		if ( ! self::store_assembly_record( $batch_id, $record ) ) {
			update_post_meta( $batch_id, '_worldgraph_gen_error', __( 'WordPress could not persist the completed rough-cut result.', 'worldgraph' ) );
			update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_assembly_failed', 'batch_assembling' );
			return;
		}
		if ( self::is_cancel_requested( $batch_id ) ) {
			delete_post_meta( $batch_id, self::ASSEMBLY_META );
			return;
		}
		$transitioned = update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_complete', 'batch_assembling' );
		if ( false === $transitioned && self::is_cancel_requested( $batch_id ) ) {
			delete_post_meta( $batch_id, self::ASSEMBLY_META );
		}
	}

	/** Accept completion only for the batch's durable rough-cut video attachment. */
	private static function verified_assembly_result( int $batch_id, $result ) {
		if ( ! is_array( $result ) || ( isset( $result['status'] ) && 'completed' !== sanitize_key( (string) $result['status'] ) ) ) {
			return new WP_Error( 'worldgraph_rough_cut_result_invalid', __( 'The rough-cut worker returned an invalid completion result.', 'worldgraph' ) );
		}

		$attachment_id    = absint( $result['attachment_id'] ?? 0 );
		$batch             = get_post( $batch_id );
		$attachment        = $attachment_id ? get_post( $attachment_id ) : null;
		$mime              = strtolower( (string) get_post_mime_type( $attachment_id ) );
		$attachment_batch = absint( get_post_meta( $attachment_id, self::BATCH_ID_META, true ) );
		$is_rough_cut      = 1 === absint( get_post_meta( $attachment_id, '_worldgraph_rough_cut', true ) );
		if ( ! $batch instanceof \WP_Post
			|| ! $attachment instanceof \WP_Post
			|| 'attachment' !== $attachment->post_type
			|| ! str_starts_with( $mime, 'video/' )
			|| $batch_id !== $attachment_batch
			|| ! $is_rough_cut
			|| (int) $batch->post_parent !== (int) $attachment->post_parent ) {
			return new WP_Error( 'worldgraph_rough_cut_result_invalid', __( 'The rough-cut worker did not produce a verified video attachment for this batch.', 'worldgraph' ) );
		}

		$result['batch_id']      = $batch_id;
		$result['attachment_id'] = $attachment_id;
		$result['url']           = (string) wp_get_attachment_url( $attachment_id );
		return $result;
	}

	/** Store and verify the final assembly DTO before publishing a terminal root state. */
	private static function store_assembly_record( int $batch_id, array $record ): bool {
		update_post_meta( $batch_id, self::ASSEMBLY_META, wp_slash( $record ) );

		return $record === get_post_meta( $batch_id, self::ASSEMBLY_META, true );
	}

	/** Ensure a separate rough-cut worker wake-up exists. */
	private static function schedule_assembly( int $delay = 5 ): bool {
		if ( ! wp_next_scheduled( self::ASSEMBLY_HOOK ) ) {
			$scheduled = wp_schedule_single_event( time() + max( 1, $delay ), self::ASSEMBLY_HOOK );
			if ( false === $scheduled && ! wp_next_scheduled( self::ASSEMBLY_HOOK ) ) {
				return false;
			}
		}

		return true;
	}

	/** Whether an independently claimed demonstration still needs assembly work. */
	private static function has_pending_assemblies(): bool {
		$batches = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::DEMONSTRATION_BATCH ],
				[ 'key' => '_worldgraph_gen_status', 'value' => 'batch_assembling' ],
				[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'NOT EXISTS' ],
			],
		] );

		return ! empty( $batches );
	}

	/** Acquire an atomic option-backed lease for the separately scheduled assembler. */
	private static function acquire_named_lock( string $option_name, int $ttl ): string {
		global $wpdb;

		$token = wp_generate_uuid4();
		$value = [ 'token' => $token, 'expires' => time() + max( 60, $ttl ) ];
		if ( add_option( $option_name, $value, '', false ) ) {
			return $token;
		}
		$current = get_option( $option_name, [] );
		if ( ! is_array( $current ) || absint( $current['expires'] ?? 0 ) >= time() ) {
			return '';
		}
		$updated = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => maybe_serialize( $value ) ],
			[ 'option_name' => $option_name, 'option_value' => maybe_serialize( $current ) ],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( $option_name, 'options' );

		return 1 === $updated ? $token : '';
	}

	/** Release only the named assembler lease owned by this worker. */
	private static function release_named_lock( string $option_name, string $token ): void {
		global $wpdb;

		$current = get_option( $option_name, [] );
		if ( ! is_array( $current ) || ! hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			return;
		}
		$wpdb->delete(
			$wpdb->options,
			[ 'option_name' => $option_name, 'option_value' => maybe_serialize( $current ) ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( $option_name, 'options' );
	}

	/** Promote a bounded number of staged jobs only after the full plan exists. */
	private static function activate_batch( int $batch_id, string $lock_token ): void {
		if ( 'batch_activating' !== get_post_meta( $batch_id, '_worldgraph_gen_status', true ) || self::is_cancel_requested( $batch_id ) ) {
			return;
		}
		$staged = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => self::ACTIVATE_PER_TICK,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => [
				[ 'key' => self::BATCH_ID_META, 'value' => $batch_id ],
				[ 'key' => '_worldgraph_gen_status', 'value' => 'staged' ],
			],
		] );
		foreach ( $staged as $job_id ) {
			if ( ! self::refresh_coordinator_lock( $lock_token ) || self::is_cancel_requested( $batch_id ) ) {
				break;
			}
			update_post_meta( $job_id, '_worldgraph_gen_status', 'queued', 'staged' );
		}
		if ( ! self::refresh_coordinator_lock( $lock_token ) ) {
			return;
		}

		$remaining = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_ID_META, 'value' => $batch_id ],
				[ 'key' => '_worldgraph_gen_status', 'value' => 'staged' ],
			],
		] );
		if ( empty( $remaining ) && ! self::is_cancel_requested( $batch_id ) ) {
			update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_active', 'batch_activating' );
		}
		Generation_Batch::schedule();
	}

	/** Whether cancellation has won the race with staging or activation. */
	private static function is_cancel_requested( int $batch_id ): bool {
		return '' !== (string) get_post_meta( $batch_id, '_worldgraph_gen_cancel_requested', true )
			|| 'batch_cancelling' === get_post_meta( $batch_id, '_worldgraph_gen_status', true );
	}

	/** Locate a previously staged child so a restarted cursor cannot duplicate it. */
	private static function find_child_for_step( int $batch_id, int $step ): int {
		$children = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_ID_META, 'value' => $batch_id ],
				[ 'key' => self::STEP_META, 'value' => $step ],
			],
		] );

		return $children ? (int) $children[0] : 0;
	}

	/** Fail a batch before activation and cancel every already-staged child. */
	private static function fail_staged_batch( int $batch_id, WP_Error $error ): void {
		if ( self::is_cancel_requested( $batch_id ) ) {
			return;
		}
		$staged = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_ID_META, 'value' => $batch_id ],
				[ 'key' => '_worldgraph_gen_status', 'value' => 'staged' ],
			],
		] );
		foreach ( $staged as $job_id ) {
			update_post_meta( $job_id, '_worldgraph_gen_status', 'cancelled', 'staged' );
		}
		if ( false !== update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_failed', 'batch_materializing' ) ) {
			update_post_meta( $batch_id, '_worldgraph_gen_error', sanitize_text_field( $error->get_error_message() ) );
		}
	}

	/** Whether another cron tick is needed for expansion, activation, or assembly. */
	private static function has_pending_batches(): bool {
		$batches = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::supported_batch_kinds(), 'compare' => 'IN' ],
				[ 'key' => '_worldgraph_gen_status', 'value' => [ 'batch_materializing', 'batch_activating', 'batch_waiting_assembly' ], 'compare' => 'IN' ],
				[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'NOT EXISTS' ],
			],
		] );

		return ! empty( $batches );
	}

	/** Summarize durable progress for a representative-media or demonstration batch. */
	public static function batch_status( int $batch_id ): array {
		$batch = get_post( $batch_id );
		$batch_kind = (string) get_post_meta( $batch_id, self::BATCH_KIND_META, true );
		if ( ! $batch instanceof \WP_Post || 'worldgraph_gen' !== $batch->post_type || ! in_array( $batch_kind, self::supported_batch_kinds(), true ) ) {
			return [];
		}

		$job_ids = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::BATCH_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $batch_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		if ( $job_ids ) {
			update_meta_cache( 'post', $job_ids );
		}

		$counts      = [];
		$jobs        = [];
		$detail_limit = 200;
		foreach ( $job_ids as $index => $job_id ) {
			$status = sanitize_key( (string) get_post_meta( $job_id, '_worldgraph_gen_status', true ) ) ?: 'unknown';
			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
			if ( $index >= $detail_limit ) {
				continue;
			}
			$jobs[] = [
				'id'          => (int) $job_id,
				'step'        => (int) get_post_meta( $job_id, self::STEP_META, true ),
				'task_key'    => (string) get_post_meta( $job_id, '_worldgraph_gen_task_key', true ),
				'source_id'   => absint( get_post_meta( $job_id, '_worldgraph_gen_source_post_id', true ) ?: get_post_field( 'post_parent', $job_id ) ),
				'intent'      => (string) get_post_meta( $job_id, self::INTENT_META, true ),
				'type'        => (string) get_post_meta( $job_id, '_worldgraph_gen_type', true ),
				'status'      => $status,
				'attachment_id' => absint( get_post_meta( $job_id, '_worldgraph_gen_attachment_id', true ) ),
				'error'       => (string) get_post_meta( $job_id, '_worldgraph_gen_error', true ),
			];
		}

		$total                   = absint( get_post_meta( $batch_id, '_worldgraph_gen_total', true ) );
		$materialized            = count( $job_ids );
		$pending_materialization = max( 0, $total - $materialized );
		$root_status             = (string) get_post_meta( $batch_id, '_worldgraph_gen_status', true );
		$cancel_requested        = '' !== (string) get_post_meta( $batch_id, '_worldgraph_gen_cancel_requested', true );
		if ( ! $cancel_requested && 'batch_assembling' === $root_status ) {
			self::schedule_assembly();
		} elseif ( ! $cancel_requested && in_array( $root_status, [ 'batch_materializing', 'batch_activating', 'batch_waiting_assembly' ], true ) ) {
			Generation_Batch::schedule();
		}
		$active_children         = array_sum( array_intersect_key( $counts, array_flip( self::ACTIVE_JOB_STATES ) ) );
		$active                  = $active_children + ( ! $cancel_requested && in_array( $root_status, [ 'batch_materializing', 'batch_activating' ], true ) ? $pending_materialization : 0 );
		$completed               = (int) ( $counts['completed'] ?? 0 );
		$failed                  = (int) ( $counts['failed'] ?? 0 );
		$skipped                 = (int) ( $counts['skipped'] ?? 0 );
		$cancelled_children      = (int) ( $counts['cancelled'] ?? 0 );
		$cancelled               = $cancelled_children + ( $cancel_requested ? $pending_materialization : 0 );
		$terminal                = $completed + $failed + $skipped + $cancelled;
		$root_active             = in_array( $root_status, [ 'batch_materializing', 'batch_activating', 'batch_waiting_assembly', 'batch_assembling' ], true );
		if ( $cancel_requested ) {
			$status = $active_children > 0 ? 'cancelling' : 'cancelled';
		} elseif ( 'batch_failed' === $root_status ) {
			$status = 'failed';
		} elseif ( 'batch_assembly_failed' === $root_status ) {
			$status = 'completed_with_errors';
		} elseif ( $active > 0 || $root_active ) {
			$status = 'active';
		} elseif ( self::DEMONSTRATION_BATCH === $batch_kind && 'batch_complete' === $root_status ) {
			$status = $failed || $cancelled_children ? 'completed_with_errors' : 'completed';
		} elseif ( $total > 0 && $completed + $skipped === $total && 0 === $failed ) {
			$status = 'completed';
		} elseif ( $total > 0 && $terminal >= $total ) {
			$status = 'completed_with_errors';
		} else {
			$status = 'pending';
		}
		if ( $pending_materialization ) {
			$counts['not_materialized'] = $pending_materialization;
		}
		$assembly = get_post_meta( $batch_id, self::ASSEMBLY_META, true );
		$assembly = is_array( $assembly ) ? $assembly : [];
		if ( empty( $assembly ) && 'batch_assembling' === $root_status ) {
			$progress_record = get_post_meta( $batch_id, '_worldgraph_gen_assembly_progress', true );
			$assembly        = is_array( $progress_record ) ? $progress_record : [];
		}
		if ( ! empty( $assembly['attachment_id'] ) && empty( $assembly['url'] ) ) {
			$assembly['url'] = (string) wp_get_attachment_url( absint( $assembly['attachment_id'] ) );
		}
		$progress = $total ? min( 100, (int) floor( 100 * $terminal / $total ) ) : 0;
		if ( self::DEMONSTRATION_BATCH === $batch_kind && in_array( $root_status, [ 'batch_waiting_assembly', 'batch_assembling' ], true ) ) {
			$progress = min( 99, $progress );
		}

		return [
			'batch_id'        => $batch_id,
			'batch_kind'      => $batch_kind,
			'post_id'         => (int) $batch->post_parent,
			'scope'           => (string) get_post_meta( $batch_id, self::BATCH_SCOPE_META, true ),
			'status'          => $status,
			'total'           => $total,
			'materialized'    => $materialized,
			'remaining'       => max( 0, $total - $terminal ),
			'active'          => $active,
			'completed'       => $completed,
			'failed'          => $failed,
			'skipped'         => $skipped,
			'cancelled'       => $cancelled,
			'progress_percent'=> $progress,
			'counts'          => $counts,
			'created'         => (string) get_post_meta( $batch_id, '_worldgraph_gen_created', true ),
			'error'           => (string) get_post_meta( $batch_id, '_worldgraph_gen_error', true ),
			'assembly'        => $assembly,
			'jobs'            => $jobs,
			'jobs_truncated'  => count( $job_ids ) > $detail_limit,
		];
	}

	/** Most recent generation batch for one item and scope. */
	public static function latest_batch( int $post_id, string $scope = 'item' ): array {
		$scope = sanitize_key( $scope );
		$scope = in_array( $scope, self::supported_scopes(), true ) ? $scope : 'item';
		$batches = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'post_parent'    => $post_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::batch_kind_for_scope( $scope ) ],
				[ 'key' => self::BATCH_SCOPE_META, 'value' => $scope ],
			],
		] );

		return $batches ? self::batch_status( (int) $batches[0] ) : [];
	}

	/**
	 * Stop child jobs that have not reached a provider yet.
	 *
	 * Submitted work keeps polling and importing because local cancellation
	 * cannot reliably revoke paid work across every provider adapter.
	 */
	public static function cancel_batch( int $batch_id ): array {
		$status = self::batch_status( $batch_id );
		if ( empty( $status ) ) {
			return [];
		}
		$cancelled_assembly_needs_cleanup = 'cancelled' === $status['status']
			&& self::DEMONSTRATION_BATCH === (string) get_post_meta( $batch_id, self::BATCH_KIND_META, true )
			&& metadata_exists( 'post', $batch_id, Rough_Cut_Assembler::STATE_META );
		if ( in_array( $status['status'], [ 'completed', 'completed_with_errors', 'cancelled', 'failed' ], true ) && ! $cancelled_assembly_needs_cleanup ) {
			$status['stopped_queued'] = 0;
			$status['cancel_note']     = __( 'This batch had already reached a terminal state, so no cancellation was applied.', 'worldgraph' );
			return $status;
		}

		// Publish cancellation first. Materialization and activation recheck this
		// marker before and after every child transition.
		update_post_meta( $batch_id, '_worldgraph_gen_cancel_requested', current_time( 'mysql' ) );
		update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_cancelling' );
		if ( self::DEMONSTRATION_BATCH === (string) get_post_meta( $batch_id, self::BATCH_KIND_META, true ) ) {
			$assembly_worker = (string) get_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token', true );
			$heartbeat       = absint( get_post_meta( $batch_id, '_worldgraph_gen_assembly_heartbeat', true ) );
			$started         = absint( get_post_meta( $batch_id, '_worldgraph_gen_assembly_started_at', true ) );
			$liveness        = $heartbeat ?: $started;
			$stale_after     = 2 * Rough_Cut_Assembler::PROCESS_TIMEOUT + self::COORDINATOR_LOCK_TTL;
			$assembly_lease  = get_option( self::ASSEMBLY_LOCK, [] );
			$lease_is_live   = '' !== $assembly_worker
				&& is_array( $assembly_lease )
				&& hash_equals( $assembly_worker, (string) ( $assembly_lease['token'] ?? '' ) )
				&& absint( $assembly_lease['expires'] ?? 0 ) >= time();
			$worker_is_stale = '' !== $assembly_worker
				&& ! $lease_is_live
				&& ( ! $liveness || $liveness + $stale_after < time() );
			if ( '' === $assembly_worker || $worker_is_stale ) {
				Rough_Cut_Assembler::cancel( $batch_id );
				delete_post_meta( $batch_id, '_worldgraph_gen_assembly_worker_token' );
				delete_post_meta( $batch_id, '_worldgraph_gen_assembly_heartbeat' );
				delete_post_meta( $batch_id, '_worldgraph_gen_assembly_progress' );
			} else {
				self::schedule_assembly();
			}
		}

		$stoppable = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_ID_META, 'value' => $batch_id ],
				[ 'key' => '_worldgraph_gen_status', 'value' => [ 'staged', 'queued', 'submitting' ], 'compare' => 'IN' ],
			],
		] );
		$stopped = 0;
		foreach ( $stoppable as $job_id ) {
			$current = (string) get_post_meta( $job_id, '_worldgraph_gen_status', true );
			if ( in_array( $current, [ 'staged', 'queued', 'submitting' ], true ) && false !== update_post_meta( $job_id, '_worldgraph_gen_status', 'cancelled', $current ) ) {
				++$stopped;
			}
		}
		$status                    = self::batch_status( $batch_id );
		$status['stopped_queued'] = $stopped;
		$status['cancel_note']    = __( 'Staged, queued, and not-yet-dispatched jobs were stopped. Jobs already dispatched to a provider will finish polling and import their results.', 'worldgraph' );
		return $status;
	}
}
