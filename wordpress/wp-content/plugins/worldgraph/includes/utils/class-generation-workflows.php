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
	const IDEMPOTENCY_META      = '_worldgraph_gen_idempotency_key';
	const REQUEST_HASH_META     = '_worldgraph_gen_request_hash';
	const INTENT_META           = '_worldgraph_gen_intent';
	const STEP_META             = '_worldgraph_gen_batch_step';
	const REPRESENTATIVE_BATCH = 'representative_media';
	const WORKFLOW_VERSION      = 2;
	const MATERIALIZE_PER_TICK  = 20;
	const ACTIVATE_PER_TICK     = 50;
	const COORDINATOR_LOCK      = 'worldgraph_generation_workflow_coordinator_lock';
	const COORDINATOR_LOCK_TTL  = 300;
	const IDEMPOTENCY_LOCK_TTL  = 1800;

	/** Maximum number of child jobs one request may persist. */
	const MAX_BATCH_TASKS = 5000;

	/** Maximum detailed Story Graph context retained in one provider prompt. */
	const MAX_CONTEXT_WORDS = 2400;

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

	/** Story Graph fields that provide useful generation context. */
	const PROMPT_FIELDS = [
		'worldgraph_project'   => [ 'description', 'genre', 'target_medium', 'aspect_ratio' ],
		'worldgraph_world'     => [ 'synopsis', 'timeline', 'rules', 'themes', 'geography', 'references' ],
		'worldgraph_character' => [ 'biography', 'age', 'appearance', 'personality', 'motivation', 'backstory' ],
		'worldgraph_prop'      => [ 'description', 'purpose', 'notes' ],
		'worldgraph_location'  => [ 'description', 'environment_type', 'geography', 'mood' ],
		'worldgraph_shot'      => [ 'shot_number', 'shot_type', 'camera_angle', 'lens', 'duration', 'shot_description', 'editorial_notes' ],
		'worldgraph_scene'     => [ 'scene_number', 'summary', 'script_content', 'dialogue', 'location', 'time_of_day', 'emotional_tone', 'production_notes' ],
		'worldgraph_episode'   => [ 'episode_number', 'synopsis' ],
	];

	/**
	 * Short, visual fields inherited from parent records.
	 *
	 * Source records retain their full prompt map above. Ancestors deliberately
	 * omit screenplay/dialogue bodies and production-state metadata so a Shot
	 * does not receive an unrelated Scene transcript merely because those fields
	 * happened to appear early in its schema.
	 */
	const INHERITED_PROMPT_FIELDS = [
		'worldgraph_project'   => [ 'description', 'genre', 'target_medium', 'aspect_ratio' ],
		'worldgraph_world'     => [ 'synopsis', 'rules', 'geography', 'themes' ],
		'worldgraph_character' => [ 'appearance', 'personality', 'biography', 'motivation' ],
		'worldgraph_episode'   => [ 'episode_number', 'synopsis' ],
		'worldgraph_scene'     => [ 'summary', 'location', 'time_of_day', 'emotional_tone' ],
	];

	/** Canonical authoring fields and parent types used for context ancestry. */
	const PARENT_RULES = [
		'worldgraph_world'     => [ 'field' => 'project', 'types' => [ 'worldgraph_project' ] ],
		'worldgraph_character' => [ 'field' => 'story_world', 'types' => [ 'worldgraph_world' ] ],
		'worldgraph_location'  => [ 'field' => 'story_world', 'types' => [ 'worldgraph_world' ] ],
		'worldgraph_prop'      => [ 'field' => 'owner_character', 'types' => [ 'worldgraph_character' ] ],
		'worldgraph_episode'   => [ 'field' => 'project', 'types' => [ 'worldgraph_project' ] ],
		'worldgraph_scene'     => [ 'field' => 'episode', 'types' => [ 'worldgraph_episode' ] ],
		'worldgraph_shot'      => [ 'field' => 'scene', 'types' => [ 'worldgraph_scene' ] ],
	];

	/** Request-local Template lookup cache, keyed by source post and output. */
	private static array $template_cache = [];

	/** Register the bounded parent-batch coordinator before the job worker. */
	public static function init(): void {
		add_action( Generation_Batch::HOOK, [ __CLASS__, 'process_batches' ], 5 );
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
					self::output( 'project-key-art', 'image', __( 'Project key art', 'worldgraph' ), __( 'Create one defining cinematic key-art frame that communicates the project premise, world, genre, tone, and primary visual identity. Use a single coherent composition.', 'worldgraph' ), true ),
				],
			],
			'worldgraph_world'     => [
				'id'          => 'world-key-art',
				'label'       => __( 'Story world key art', 'worldgraph' ),
				'description' => __( 'One defining environmental still for the story world.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'world-key-art', 'image', __( 'Story world key art', 'worldgraph' ), __( 'Create a defining wide environmental frame that establishes this world\'s geography, rules, culture, era, atmosphere, and visual language.', 'worldgraph' ), true ),
				],
			],
			'worldgraph_character' => [
				'id'          => 'character-look-set',
				'label'       => __( 'Character look set', 'worldgraph' ),
				'description' => __( 'Six consistent views that define the complete character look.', 'worldgraph' ),
				'outputs'     => self::look_outputs( 'character', __( 'character', 'worldgraph' ) ),
			],
			'worldgraph_prop'      => [
				'id'          => 'prop-look-set',
				'label'       => __( 'Prop look set', 'worldgraph' ),
				'description' => __( 'Six consistent views that define the complete prop look.', 'worldgraph' ),
				'outputs'     => self::look_outputs( 'prop', __( 'prop', 'worldgraph' ) ),
			],
			'worldgraph_location'  => [
				'id'          => 'location-look-set',
				'label'       => __( 'Location look set', 'worldgraph' ),
				'description' => __( 'Six consistent views that define the complete location look.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'location-full-view', 'image', __( 'Full establishing view', 'worldgraph' ), __( 'Create a full establishing view of the entire location, clearly showing its scale, layout, geography, atmosphere, and major landmarks.', 'worldgraph' ), true ),
					self::output( 'location-front-view', 'image', __( 'Front view', 'worldgraph' ), __( 'Create a straight-on front-facing view of the location or its primary entrance, preserving the same design, weather, era, and lighting language.', 'worldgraph' ) ),
					self::output( 'location-three-quarter-view', 'image', __( 'Three-quarter view', 'worldgraph' ), __( 'Create a three-quarter view that reveals the location\'s depth, circulation, adjacent structures, and spatial relationships while preserving continuity.', 'worldgraph' ) ),
					self::output( 'location-profile-view', 'image', __( 'Profile view', 'worldgraph' ), __( 'Create a side or profile view of the location that clearly communicates its silhouette, elevation, terrain, and practical access.', 'worldgraph' ) ),
					self::output( 'location-back-view', 'image', __( 'Back view', 'worldgraph' ), __( 'Create the reverse or back-facing view from the opposite direction, preserving every established architectural and environmental detail.', 'worldgraph' ) ),
					self::output( 'location-close-up', 'image', __( 'Detail close-up', 'worldgraph' ), __( 'Create a close-up of the location\'s most story-defining architectural, material, signage-free, or environmental detail.', 'worldgraph' ) ),
				],
			],
			'worldgraph_shot'      => [
				'id'          => 'shot-still-and-video',
				'label'       => __( 'Shot still and video', 'worldgraph' ),
				'description' => __( 'One representative still plus one moving shot.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'shot-representative-still', 'image', __( 'Representative still', 'worldgraph' ), __( 'Create the decisive representative frame for this shot. Honor the specified framing, camera angle, lens, blocking, action, setting, emotional beat, and continuity.', 'worldgraph' ), true ),
					self::output( 'shot-video', 'video', __( 'Shot video', 'worldgraph' ), __( 'Create the complete moving shot described here. Preserve the still-frame composition and continuity while expressing intentional subject action, camera movement, timing, and cinematic motion. Avoid cuts unless the shot description explicitly calls for one.', 'worldgraph' ) ),
				],
			],
			'worldgraph_scene'     => [
				'id'          => 'scene-filmstrip',
				'label'       => __( 'Scene filmstrip', 'worldgraph' ),
				'description' => __( 'A single filmstrip-style still summarizing multiple shots.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'scene-filmstrip', 'image', __( 'Scene filmstrip', 'worldgraph' ), __( 'Create a polished horizontal cinematic filmstrip/contact-sheet composition made from several distinct frames that trace the scene\'s shot progression and emotional arc. Keep characters, wardrobe, location, time of day, and color continuity consistent across every frame.', 'worldgraph' ), true ),
				],
			],
			'worldgraph_episode'   => [
				'id'          => 'episode-bookend-filmstrip',
				'label'       => __( 'Episode bookend filmstrip', 'worldgraph' ),
				'description' => __( 'A filmstrip contrasting the first and last scenes.', 'worldgraph' ),
				'outputs'     => [
					self::output( 'episode-bookend-filmstrip', 'image', __( 'Episode bookend filmstrip', 'worldgraph' ), __( 'Create a cinematic filmstrip composition that contrasts the episode\'s opening scene with its final scene. Show the visual and emotional transformation between the bookends while preserving story-world continuity.', 'worldgraph' ), true ),
				],
			],
		];
	}

	/** Build one output definition. */
	private static function output( string $intent, string $type, string $label, string $instruction, bool $featured = false ): array {
		return compact( 'intent', 'type', 'label', 'instruction', 'featured' );
	}

	/** Six continuity-sensitive look-development views for a subject. */
	private static function look_outputs( string $prefix, string $subject ): array {
		return [
			self::output(
				$prefix . '-full-view',
				'image',
				__( 'Full view', 'worldgraph' ),
				sprintf(
					/* translators: %s: subject type, such as character or location. */
					__( 'Create a complete head-to-toe or edge-to-edge full view of the %s, fully visible, centered, and unobstructed. Establish the canonical proportions, silhouette, materials, colors, and identifying details.', 'worldgraph' ),
					$subject
				),
				true
			),
			self::output(
				$prefix . '-front-view',
				'image',
				__( 'Front view', 'worldgraph' ),
				sprintf(
					/* translators: %s: subject type, such as character or location. */
					__( 'Create a straight-on front orthographic-style view of the same %s. Preserve exact proportions, colors, construction, styling, and identifying details from the canonical design.', 'worldgraph' ),
					$subject
				)
			),
			self::output(
				$prefix . '-three-quarter-view',
				'image',
				__( 'Three-quarter view', 'worldgraph' ),
				sprintf(
					/* translators: %s: subject type, such as character or location. */
					__( 'Create a three-quarter view of the same %s that clearly reveals volume and depth while preserving exact design continuity.', 'worldgraph' ),
					$subject
				)
			),
			self::output(
				$prefix . '-profile-view',
				'image',
				__( 'Profile view', 'worldgraph' ),
				sprintf(
					/* translators: %s: subject type, such as character or location. */
					__( 'Create a clean side-profile view of the same %s, preserving the exact silhouette, proportions, materials, colors, and identifying details.', 'worldgraph' ),
					$subject
				)
			),
			self::output(
				$prefix . '-back-view',
				'image',
				__( 'Back view', 'worldgraph' ),
				sprintf(
					/* translators: %s: subject type, such as character or location. */
					__( 'Create a straight-on back view of the same %s, revealing rear construction and details while preserving exact continuity with every other view.', 'worldgraph' ),
					$subject
				)
			),
			self::output(
				$prefix . '-close-up',
				'image',
				__( 'Close-up', 'worldgraph' ),
				sprintf(
					/* translators: %s: subject type, such as character or location. */
					__( 'Create a close-up of the same %s focused on its most story-defining features, surface detail, materials, and craftsmanship. Preserve exact continuity.', 'worldgraph' ),
					$subject
				)
			),
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
	 */
	public static function compose_prompt( int $post_id, string $intent = '', string $base_prompt = '' ): string {
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

		$labels  = worldgraph_get_all_cpts();
		$parts   = [ sprintf( '%s: %s', (string) ( $labels[ $post_type ] ?? __( 'Story element', 'worldgraph' ) ), self::clean_text( $post->post_title, 80 ) ) ];
		$inherited_context = self::inherited_context( $post_id );
		if ( '' !== $inherited_context ) {
			$parts[] = $inherited_context;
		}

		$seen = [];
		foreach ( [ $post->post_excerpt, $post->post_content ] as $core_text ) {
			$core_text = self::clean_text( (string) $core_text, 700 );
			if ( '' !== $core_text && ! isset( $seen[ md5( strtolower( $core_text ) ) ] ) ) {
				$seen[ md5( strtolower( $core_text ) ) ] = true;
				$parts[] = __( 'Narrative description', 'worldgraph' ) . ': ' . $core_text;
			}
		}

		$fields = worldgraph_get_fields( $post_type );
		foreach ( self::PROMPT_FIELDS[ $post_type ] ?? [] as $field_name ) {
			$field = (array) ( $fields[ $field_name ] ?? [] );
			$value = self::field_prompt_value( $post_id, $field_name, $field );
			if ( '' === $value ) {
				continue;
			}
			$hash = md5( strtolower( $value ) );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$label         = (string) ( $field['label'] ?? ucwords( str_replace( '_', ' ', $field_name ) ) );
			$parts[]       = $label . ': ' . $value;
		}

		$dependent_context = self::dependent_context( $post_id, $intent );
		if ( '' !== $dependent_context ) {
			$parts[] = $dependent_context;
		}

		if ( ! empty( $output['instruction'] ) ) {
			$parts[] = __( 'Creative objective', 'worldgraph' ) . ': ' . self::clean_text( (string) $output['instruction'], 240 );
		}

		$instructions = self::clean_text( (string) worldgraph_get_field_value( $post_id, 'generation_prompt' ), 500 );
		if ( '' !== $instructions ) {
			$parts[] = __( 'Generation instructions', 'worldgraph' ) . ': ' . $instructions;
		}
		$base_prompt = self::clean_text( $base_prompt, 700 );
		if ( '' !== $base_prompt ) {
			$parts[] = __( 'Additional request instructions', 'worldgraph' ) . ': ' . $base_prompt;
		}

		$parts[] = __( 'Output constraints: cinematic production design, coherent lighting and continuity, high detail, no watermarks, logos, interface chrome, or unrelated text.', 'worldgraph' );
		$prompt  = self::clean_text( implode( "\n\n", array_filter( $parts ) ), self::MAX_CONTEXT_WORDS );

		return $prompt;
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
			$value      = worldgraph_get_field_value( $current_id, (string) $rule['field'] );
			foreach ( is_array( $value ) ? $value : [ $value ] as $candidate ) {
				$candidate_id = $candidate instanceof \WP_Post ? (int) $candidate->ID : absint( $candidate );
				$candidate_type = $candidate_id ? (string) get_post_type( $candidate_id ) : '';
				if ( $candidate_id && in_array( $candidate_type, $rule['types'], true ) ) {
					$preferred[ $candidate_id ] = $candidate_type;
					$candidates[ $candidate_id ] = $candidate_type;
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

	/** Format bounded parent context and inherited prompt instructions. */
	private static function inherited_context( int $post_id ): string {
		$labels = worldgraph_get_all_cpts();
		$lines  = [];
		foreach ( array_reverse( self::ancestors( $post_id ) ) as $ancestor ) {
			$details = [];
			$fields  = worldgraph_get_fields( $ancestor->post_type );
			foreach ( self::INHERITED_PROMPT_FIELDS[ $ancestor->post_type ] ?? [] as $field_name ) {
				$field = (array) ( $fields[ $field_name ] ?? [] );
				$value = self::field_prompt_value( (int) $ancestor->ID, $field_name, $field );
				if ( '' !== $value ) {
					$label     = (string) ( $field['label'] ?? ucwords( str_replace( '_', ' ', $field_name ) ) );
					$details[] = $label . ': ' . self::clean_text( $value, 90 );
				}
			}
			$instructions = self::clean_text( (string) worldgraph_get_field_value( (int) $ancestor->ID, 'generation_prompt' ), 180 );
			if ( '' !== $instructions ) {
				$details[] = __( 'Generation instructions', 'worldgraph' ) . ': ' . $instructions;
			}
			$line = sprintf( '%s: %s', (string) ( $labels[ $ancestor->post_type ] ?? $ancestor->post_type ), $ancestor->post_title );
			if ( $details ) {
				$line .= ' — ' . implode( '; ', $details );
			}
			$lines[] = self::clean_text( $line, 320 );
		}

		return $lines ? __( 'Inherited story context', 'worldgraph' ) . ":\n- " . implode( "\n- ", $lines ) : '';
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
			foreach ( array_slice( $shots, 0, 24 ) as $shot ) {
				$number      = worldgraph_get_field_value( $shot->ID, 'shot_number' );
				$type        = worldgraph_get_field_value( $shot->ID, 'shot_type' );
				$description = worldgraph_get_field_value( $shot->ID, 'shot_description' );
				$frames[]    = sprintf( '%s%s — %s', $number ? __( 'Shot ', 'worldgraph' ) . $number . ': ' : '', $shot->post_title, self::clean_text( trim( (string) $type . ' ' . (string) $description ), 120 ) );
			}
			return empty( $frames ) ? '' : __( 'Filmstrip shot progression', 'worldgraph' ) . ":\n- " . implode( "\n- ", $frames );
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
				$parts[] = ( 0 === $index ? __( 'Opening scene', 'worldgraph' ) : __( 'Final scene', 'worldgraph' ) ) . ': ' . $scene->post_title . ' — ' . self::clean_text( (string) worldgraph_get_field_value( $scene->ID, 'summary' ), 240 );
			}
			return implode( "\n", $parts );
		}

		return '';
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
	 * @return array<string, mixed>|WP_Error
	 */
	public static function plan( int $post_id, string $scope = 'item', string $base_prompt = '' ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Asset_Generator::supports( $post_id ) ) {
			return new WP_Error( 'worldgraph_generation_source_invalid', __( 'Select a supported Story Graph item first.', 'worldgraph' ), [ 'status' => 404 ] );
		}

		$scope = 'project' === sanitize_key( $scope ) ? 'project' : 'item';
		if ( 'project' === $scope && 'worldgraph_project' !== $post->post_type ) {
			return new WP_Error( 'worldgraph_generation_project_required', __( 'Project-wide generation requires a Project post.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$sources = 'project' === $scope ? self::project_sources( $post_id ) : [ $post ];
		$tasks   = [];
		foreach ( $sources as $source ) {
			$definition = self::definition_for_post_type( $source->post_type );
			foreach ( (array) ( $definition['outputs'] ?? [] ) as $output ) {
				$intent = (string) ( $output['intent'] ?? '' );
				$tasks[] = [
					'source_id'    => (int) $source->ID,
					'source_type'  => (string) $source->post_type,
					'source_title' => (string) $source->post_title,
					'workflow_id'  => (string) ( $definition['id'] ?? '' ),
					'intent'       => $intent,
					'label'        => (string) ( $output['label'] ?? $intent ),
					'type'         => (string) ( $output['type'] ?? 'image' ),
					'featured'     => ! empty( $output['featured'] ),
					'prompt'       => Asset_Generator::build_prompt( (int) $source->ID, $intent, $base_prompt ),
				];
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
			'workflow'   => self::definition_for_post_type( $post->post_type ),
			'sources'    => count( $sources ),
			'total_jobs' => count( $tasks ),
			'counts'     => [ 'image' => (int) ( $counts['image'] ?? 0 ), 'video' => (int) ( $counts['video'] ?? 0 ) ],
			'tasks'      => $tasks,
		];
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
	public static function runnable_templates( int $post_id, string $type ): array {
		$type      = sanitize_key( $type );
		$cache_key = $post_id . ':' . $type;
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
			if ( $type !== Generation_Modality::output_type( $modality ) || ! empty( Template_Bindings::missing_required( $template->ID, $post_id ) ) ) {
				continue;
			}
			$connection_id = absint( worldgraph_get_field_value( $template->ID, 'connection_id' ) );
			$connection    = $connection_id ? Connection_Repository::get( $connection_id ) : null;
			$provider      = sanitize_key( (string) worldgraph_get_field_value( $template->ID, 'provider_type' ) );
			if ( ! $connection || ! Connection_Repository::is_available( $connection_id ) || $provider !== ( $connection['provider_type'] ?? '' ) || ! in_array( $provider, [ 'comfyui', 'fal', 'videodraft', 'openrouter' ], true ) ) {
				continue;
			}
			$requires_media       = ! empty( Generation_Modality::media_inputs( $modality ) );
			$provider_template_id = trim( (string) ( worldgraph_get_field_value( $template->ID, 'provider_template_id' ) ?: get_post_meta( $template->ID, 'comfy_template_id', true ) ) );
			$media_supported      = 'videodraft' === $provider || ( 'comfyui' === $provider && 'local' === ( $connection['environment'] ?? '' ) );
			if ( $requires_media && ! $media_supported ) {
				continue;
			}
			// A local ComfyUI Template whose nodes/models are not installed
			// cannot actually run, so it must not appear as a choice until it
			// is verified ready. A catalog read failure (connectivity) is left
			// to surface at submission rather than hiding every Template here.
			if ( 'comfyui' === $provider && 'local' === ( $connection['environment'] ?? '' ) ) {
				Connection_Adapters::load( 'comfyui' );
				$report = Comfy_Manifest::validate( $template->ID );
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
				'run_controls'   => Template_Run_Controls::describe( (int) $template->ID ),
			];
		}

		usort( $options, static function ( array $left, array $right ): int {
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
			if ( $type !== ( $task['type'] ?? '' ) ) {
				continue;
			}
			$options = self::runnable_templates( absint( $task['source_id'] ?? 0 ), $type );
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
		$options = self::runnable_templates( (int) $task['source_id'], (string) $task['type'] );
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

	/** Freeze an item or Project plan for bounded background materialization. */
	public static function queue_batch( int $post_id, string $scope, array $args = [] ) {
		$args = wp_parse_args( $args, [
			'base_prompt'       => '',
			'image_template_id' => 0,
			'video_template_id' => 0,
			'image_run_values'  => [],
			'video_run_values'  => [],
			'idempotency_key'   => '',
		] );
		$requester_id = get_current_user_id();
		if ( ! $requester_id || ! user_can( $requester_id, 'upload_files' ) || ! user_can( $requester_id, 'edit_post', $post_id ) ) {
			return new WP_Error( 'worldgraph_generation_requester_forbidden', __( 'You are not allowed to queue representative media for this item.', 'worldgraph' ), [ 'status' => $requester_id ? 403 : 401 ] );
		}

		$idempotency_key = sanitize_text_field( (string) $args['idempotency_key'] );
		if ( '' === $idempotency_key ) {
			return new WP_Error( 'worldgraph_generation_idempotency_required', __( 'A unique idempotency key is required to start a representative-media batch.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$run_values = [ 'image' => [], 'video' => [] ];
		foreach ( [ 'image', 'video' ] as $type ) {
			$submitted   = is_array( $args[ $type . '_run_values' ] ) ? $args[ $type . '_run_values' ] : [];
			$template_id = absint( $args[ $type . '_template_id' ] );
			if ( ! empty( $submitted ) && ! $template_id ) {
				return new WP_Error(
					'worldgraph_generation_run_template_required',
					__( 'Choose one Template before applying run controls to a representative-media batch.', 'worldgraph' ),
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
			'scope'             => 'project' === $scope ? 'project' : 'item',
			'base_prompt'       => (string) $args['base_prompt'],
			'image_template_id' => absint( $args['image_template_id'] ),
			'video_template_id' => absint( $args['video_template_id'] ),
			'image_run_values'  => $run_values['image'],
			'video_run_values'  => $run_values['video'],
		] ) );

		$existing = self::batch_for_idempotency_key( $post_id, $requester_id, $idempotency_key );
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
			$explicit    = absint( $args[ (string) $task['type'] . '_template_id' ] ?? 0 );
			$template_id = self::resolve_template_id( $task, $explicit );
			if ( ! $template_id ) {
				$missing[] = [
					'source_id'    => (int) $task['source_id'],
					'source_title' => (string) $task['source_title'],
					'intent'       => (string) $task['intent'],
					'type'         => (string) $task['type'],
				];
				continue;
			}
			$task['template_id'] = $template_id;
			$task['run_values']  = $run_values[ (string) $task['type'] ];
			$description         = Template_Run_Controls::describe( $template_id );
			$task['profile_values'] = Asset_Generator::project_template_defaults(
				$template_id,
				Asset_Generator::project_media_profile( (int) $task['source_id'] ),
				$description
			);
			$task['run_controls_fingerprint'] = (string) ( $description['fingerprint'] ?? '' );
			$resolved_tasks[]    = $task;
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'worldgraph_generation_template_missing',
				__( 'The representative-media plan cannot start until every required image and video output has a runnable Template.', 'worldgraph' ),
				[ 'status' => 409, 'missing' => $missing ]
			);
		}

		$reservation = self::reserve_idempotency_key( $post_id, $requester_id, $idempotency_key, $request_hash );
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
		$batch = wp_insert_post( [
			'post_type'   => 'worldgraph_gen',
			'post_status' => 'draft',
			'post_parent' => $post_id,
			'post_title'  => sprintf(
				/* translators: %s: title or ID of the Story Graph item. */
				__( 'Representative media batch: %s', 'worldgraph' ),
				$root instanceof \WP_Post ? $root->post_title : $post_id
			),
		], true );
		if ( is_wp_error( $batch ) ) {
			self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token );
			return $batch;
		}

		$batch_id   = (int) $batch;
		$frozen_plan = array_map( static function ( array $task, int $index ): array {
			return [
				'step'         => $index,
				'source_id'    => (int) $task['source_id'],
				'source_type'  => (string) $task['source_type'],
				'source_title' => (string) $task['source_title'],
				'workflow_id'  => (string) $task['workflow_id'],
				'intent'       => (string) $task['intent'],
				'type'         => (string) $task['type'],
				'featured'     => ! empty( $task['featured'] ),
				'template_id'  => (int) $task['template_id'],
				'run_values'   => (array) ( $task['run_values'] ?? [] ),
				'profile_values' => (array) ( $task['profile_values'] ?? [] ),
				'run_controls_fingerprint' => (string) ( $task['run_controls_fingerprint'] ?? '' ),
				'prompt'       => (string) $task['prompt'],
				'prompt_hash'  => hash( 'sha256', (string) $task['prompt'] ),
			];
		}, $resolved_tasks, array_keys( $resolved_tasks ) );

		$meta = [
			self::BATCH_KIND_META        => self::REPRESENTATIVE_BATCH,
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
			self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token );
			return new WP_Error( 'worldgraph_generation_batch_storage_failed', __( 'WordPress could not persist the representative-media plan.', 'worldgraph' ), [ 'status' => 500 ] );
		}

		// Ensure a wake-up exists before the root commit marker becomes visible.
		// An idempotent retry also schedules above, so a removed cron event heals.
		if ( ! Generation_Batch::schedule() ) {
			wp_delete_post( $batch_id, true );
			self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token );
			return self::schedule_error();
		}

		// The root status is the coordinator's commit marker. Publish it only
		// after the complete frozen plan is durable and verified.
		update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_materializing' );
		if ( 'batch_materializing' !== get_post_meta( $batch_id, '_worldgraph_gen_status', true ) ) {
			wp_delete_post( $batch_id, true );
			self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token );
			return new WP_Error( 'worldgraph_generation_batch_storage_failed', __( 'WordPress could not commit the representative-media plan.', 'worldgraph' ), [ 'status' => 500 ] );
		}

		self::release_idempotency_key( $post_id, $requester_id, $idempotency_key, $reservation_token );
		if ( ! Generation_Batch::schedule() ) {
			return self::schedule_error();
		}
		return self::batch_status( $batch_id );
	}

	/** Report a durable batch whose worker wake-up could not be guaranteed. */
	private static function schedule_error(): WP_Error {
		return new WP_Error(
			'worldgraph_generation_schedule_failed',
			__( 'WordPress could not schedule the representative-media batch. Retry the same request to resume it safely.', 'worldgraph' ),
			[ 'status' => 503 ]
		);
	}

	/** Find an existing caller-scoped batch for a retry-safe start request. */
	private static function batch_for_idempotency_key( int $post_id, int $requester_id, string $idempotency_key ): int {
		$existing = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'post_parent'    => $post_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::REPRESENTATIVE_BATCH ],
				[ 'key' => self::IDEMPOTENCY_META, 'value' => $idempotency_key ],
				[ 'key' => '_worldgraph_gen_requested_by', 'value' => $requester_id ],
				[ 'key' => '_worldgraph_gen_status', 'compare' => 'EXISTS' ],
			],
		] );

		return $existing ? (int) $existing[0] : 0;
	}

	/** Atomically reserve a caller/root idempotency key before inserting a batch. */
	private static function reserve_idempotency_key( int $post_id, int $requester_id, string $idempotency_key, string $request_hash ) {
		global $wpdb;

		$existing = self::batch_for_idempotency_key( $post_id, $requester_id, $idempotency_key );
		if ( $existing ) {
			$stored_hash = (string) get_post_meta( $existing, self::REQUEST_HASH_META, true );
			if ( '' !== $stored_hash && ! hash_equals( $stored_hash, $request_hash ) ) {
				return new WP_Error( 'worldgraph_generation_idempotency_conflict', __( 'That idempotency key was already used for different generation settings.', 'worldgraph' ), [ 'status' => 409 ] );
			}
			return [ 'batch_id' => $existing, 'token' => '' ];
		}

		$option_name = self::idempotency_option_name( $post_id, $requester_id, $idempotency_key );
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
	private static function release_idempotency_key( int $post_id, int $requester_id, string $idempotency_key, string $token ): void {
		global $wpdb;

		$option_name = self::idempotency_option_name( $post_id, $requester_id, $idempotency_key );
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
	private static function idempotency_option_name( int $post_id, int $requester_id, string $idempotency_key ): string {
		return 'worldgraph_gen_idem_' . hash( 'sha256', $post_id . ':' . $requester_id . ':' . $idempotency_key );
	}

	/** Stage and then activate frozen child tasks in bounded cron chunks. */
	public static function process_batches(): void {
		$lock_token = self::acquire_coordinator_lock();
		if ( '' === $lock_token ) {
			Generation_Batch::schedule();
			return;
		}

		$batches = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 2,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::REPRESENTATIVE_BATCH ],
				[ 'key' => '_worldgraph_gen_status', 'value' => [ 'batch_materializing', 'batch_activating' ], 'compare' => 'IN' ],
				[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'NOT EXISTS' ],
			],
		] );

		try {
			foreach ( $batches as $batch_id ) {
				if ( ! self::refresh_coordinator_lock( $lock_token ) ) {
					break;
				}
				$status = (string) get_post_meta( $batch_id, '_worldgraph_gen_status', true );
				if ( 'batch_materializing' === $status ) {
					self::materialize_batch( (int) $batch_id, $lock_token );
				} elseif ( 'batch_activating' === $status ) {
					self::activate_batch( (int) $batch_id, $lock_token );
				}
			}
		} finally {
			self::release_coordinator_lock( $lock_token );
		}

		if ( self::has_pending_batches() ) {
			Generation_Batch::schedule();
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
				'set_featured'       => 'image' === $task['type'] && ! empty( $task['featured'] ),
				'create_asset'       => true,
				'template_id'        => (int) $task['template_id'],
				'run_values'         => (array) ( $task['run_values'] ?? [] ),
				'profile_values'     => (array) ( $task['profile_values'] ?? [] ),
				'profile_values_frozen' => array_key_exists( 'profile_values', $task ),
				'run_values_validated' => true,
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

	/** Whether another cron tick is needed to finish staging or activation. */
	private static function has_pending_batches(): bool {
		$batches = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::REPRESENTATIVE_BATCH ],
				[ 'key' => '_worldgraph_gen_status', 'value' => [ 'batch_materializing', 'batch_activating' ], 'compare' => 'IN' ],
				[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'NOT EXISTS' ],
			],
		] );

		return ! empty( $batches );
	}

	/** Summarize durable progress for a representative-media batch. */
	public static function batch_status( int $batch_id ): array {
		$batch = get_post( $batch_id );
		if ( ! $batch instanceof \WP_Post || 'worldgraph_gen' !== $batch->post_type || self::REPRESENTATIVE_BATCH !== get_post_meta( $batch_id, self::BATCH_KIND_META, true ) ) {
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
		$active_children         = array_sum( array_intersect_key( $counts, array_flip( self::ACTIVE_JOB_STATES ) ) );
		$active                  = $active_children + ( ! $cancel_requested && in_array( $root_status, [ 'batch_materializing', 'batch_activating' ], true ) ? $pending_materialization : 0 );
		$completed               = (int) ( $counts['completed'] ?? 0 );
		$failed                  = (int) ( $counts['failed'] ?? 0 );
		$cancelled_children      = (int) ( $counts['cancelled'] ?? 0 );
		$cancelled               = $cancelled_children + ( $cancel_requested ? $pending_materialization : 0 );
		$terminal                = $completed + $failed + $cancelled;
		if ( $cancel_requested ) {
			$status = $active_children > 0 ? 'cancelling' : 'cancelled';
		} elseif ( 'batch_failed' === $root_status ) {
			$status = 'failed';
		} elseif ( $active > 0 ) {
			$status = 'active';
		} elseif ( $total > 0 && $completed === $total ) {
			$status = 'completed';
		} elseif ( $total > 0 && $terminal >= $total ) {
			$status = 'completed_with_errors';
		} else {
			$status = 'pending';
		}
		if ( $pending_materialization ) {
			$counts['not_materialized'] = $pending_materialization;
		}

		return [
			'batch_id'        => $batch_id,
			'post_id'         => (int) $batch->post_parent,
			'scope'           => (string) get_post_meta( $batch_id, self::BATCH_SCOPE_META, true ),
			'status'          => $status,
			'total'           => $total,
			'materialized'    => $materialized,
			'remaining'       => max( 0, $total - $terminal ),
			'active'          => $active,
			'completed'       => $completed,
			'failed'          => $failed,
			'cancelled'       => $cancelled,
			'progress_percent'=> $total ? min( 100, (int) floor( 100 * $terminal / $total ) ) : 0,
			'counts'          => $counts,
			'created'         => (string) get_post_meta( $batch_id, '_worldgraph_gen_created', true ),
			'error'           => (string) get_post_meta( $batch_id, '_worldgraph_gen_error', true ),
			'jobs'            => $jobs,
			'jobs_truncated'  => count( $job_ids ) > $detail_limit,
		];
	}

	/** Most recent representative batch for one item and scope. */
	public static function latest_batch( int $post_id, string $scope = 'item' ): array {
		$batches = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'post_parent'    => $post_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_query'     => [
				[ 'key' => self::BATCH_KIND_META, 'value' => self::REPRESENTATIVE_BATCH ],
				[ 'key' => self::BATCH_SCOPE_META, 'value' => 'project' === $scope ? 'project' : 'item' ],
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
		if ( in_array( $status['status'], [ 'completed', 'completed_with_errors', 'cancelled', 'failed' ], true ) ) {
			$status['stopped_queued'] = 0;
			$status['cancel_note']     = __( 'This batch had already reached a terminal state, so no cancellation was applied.', 'worldgraph' );
			return $status;
		}

		// Publish cancellation first. Materialization and activation recheck this
		// marker before and after every child transition.
		update_post_meta( $batch_id, '_worldgraph_gen_cancel_requested', current_time( 'mysql' ) );
		update_post_meta( $batch_id, '_worldgraph_gen_status', 'batch_cancelling' );

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
