<?php
/**
 * Template-aware media prompt policy resolution and enforcement.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves bounded, non-executable prompt preferences for one Template.
 *
 * Connections provide trusted provider fallbacks. Templates may persist a
 * reviewed provider policy and an operator override in configuration_json.
 * Remote schema prose is never used as an instruction; only an explicit
 * numeric prompt maxLength is considered.
 */
class Generation_Prompt_Policy {
	/** Request-local policy cache keeps composition, enforcement, and preview aligned. */
	private static array $policy_cache = [];

	/** Version of the normalized policy DTO. */
	public const VERSION = 1;

	/** Absolute defensive bounds for visual prompt configuration. */
	private const MIN_TARGET_WORDS = 1;
	private const MAX_TARGET_WORDS = 4000;
	private const MAX_HARD_WORDS   = 4000;
	private const MAX_HARD_CHARS   = 100000;
	private const MAX_HARD_BYTES   = 100000;

	/** Stable semantic sections understood by the deterministic composer. */
	private const SECTION_IDS = [
		'primary',
		'objective',
		'identity',
		'subject',
		'action',
		'setting',
		'characters',
		'camera',
		'motion',
		'look',
		'continuity',
		'ancestor_context',
		'dependent_context',
		'author_instructions',
		'constraints',
		'verbatim',
		'other',
	];

	/** Sections a remote/operator policy may not suppress. */
	private const PROTECTED_SECTIONS = [ 'primary', 'objective', 'author_instructions', 'constraints', 'verbatim' ];

	/** Allowed normalized composition hints. */
	private const LEAD_WITH = [ 'subject', 'action', 'motion' ];
	private const FORMATS   = [ 'natural_language', 'concise_phrases', 'chronological_prose' ];

	/**
	 * Labels emitted by the semantic composer that may be omitted in compact
	 * surface formats. Keeping this list explicit prevents an instruction before
	 * a colon (for example, "One horizontal filmstrip:") from being discarded.
	 */
	private const SECTION_LABELS = [
		'primary'             => [
			'project description',
			'world description',
			'character appearance',
			'prop description',
			'location description',
			'shot description',
			'scene description',
			'episode description',
			'description',
			'shot',
		],
		'objective'           => [ 'output', 'objective' ],
		'identity'            => [ 'identity', 'story element', 'project', 'world', 'character', 'prop', 'location', 'shot', 'scene', 'episode', 'sound' ],
		'subject'             => [ 'subject', 'use', 'visible details' ],
		'action'              => [ 'action', 'frame moment' ],
		'setting'             => [ 'setting', 'location', 'physical setting', 'environment', 'time' ],
		'characters'          => [ 'characters', 'visible characters', 'age', 'visible character traits' ],
		'camera'              => [ 'camera', 'framing' ],
		'motion'              => [ 'motion', 'duration', 'motion and ending' ],
		'look'                => [ 'look', 'look continuity', 'genre', 'visual style', 'project visual direction', 'scene look and lighting override', 'era and time', 'visible world rules', 'atmosphere', 'mood' ],
		'continuity'          => [ 'continuity', 'inherited visual instructions' ],
		'ancestor_context'    => [ 'visual context', 'inherited story context' ],
		'dependent_context'   => [ 'dependent context' ],
		'author_instructions' => [ 'author instructions', 'saved visual instructions', 'additional instructions' ],
		'constraints'         => [ 'constraints', 'output constraints' ],
		'verbatim'            => [ 'verbatim', 'dialogue', 'lyrics' ],
		'other'               => [ 'other' ],
	];

	/**
	 * Resolve an effective policy after a Template has been selected.
	 *
	 * Precedence for creative preferences is core -> adapter -> Connection ->
	 * model family -> provider-discovered Template policy -> operator JSON policy
	 * -> Template editor fields -> trusted filter. Positive hard ceilings always
	 * merge by taking the smallest value.
	 *
	 * @param int   $template_id Selected Template post ID, or zero for fallback.
	 * @param array $context     Output/post/intent context.
	 * @return array<string, mixed>
	 */
	public static function for_template( int $template_id, array $context = [] ): array {
		$modality        = Generation_Modality::sanitize( (string) ( $context['modality'] ?? '' ) );
		$output_type     = sanitize_key( (string) ( $context['output_type'] ?? '' ) );
		$provider        = sanitize_key( (string) ( $context['provider_type'] ?? '' ) );
		$connection_id   = absint( $context['connection_id'] ?? 0 );
		$family          = Model_Family::sanitize( (string) ( $context['model_family'] ?? '' ) );
		$hints           = [];
		$configuration   = [];
		$template_editor = [];

		if ( $template_id > 0 ) {
			$modality      = Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template_id, 'modality' ) );
			$output_type   = Generation_Modality::output_type( $modality );
			$provider      = sanitize_key( (string) worldgraph_get_field_value( $template_id, 'provider_type' ) );
			$connection_id = absint( worldgraph_get_field_value( $template_id, 'connection_id' ) );
			$family        = self::template_family( $template_id );
			$post          = get_post( $template_id );
			$hints         = [
				$post instanceof \WP_Post ? (string) $post->post_title : '',
				(string) worldgraph_get_field_value( $template_id, 'template_name' ),
				(string) worldgraph_get_field_value( $template_id, 'provider_template_id' ),
				(string) worldgraph_get_field_value( $template_id, 'checkpoint' ),
			];
			$configuration   = json_decode( (string) worldgraph_get_field_value( $template_id, 'configuration_json' ), true );
			$configuration   = is_array( $configuration ) ? $configuration : [];
			$template_editor = self::template_editor_declaration( $template_id );
		}

		if ( '' === $output_type ) {
			$output_type = Generation_Modality::output_type( $modality );
		}
		if ( '' === $output_type ) {
			$output_type = 'image';
		}

		$context['modality']      = $modality;
		$context['output_type']   = $output_type;
		$context['provider_type'] = $provider;
		$context['connection_id'] = $connection_id;
		$context['model_family']  = $family;
		$context['model_hints']   = $hints;

		$cache_key = hash(
			'sha256',
			(string) wp_json_encode(
				self::canonicalize_cache_value(
					[
						'template_id'     => $template_id,
						'context'         => $context,
						'configuration'   => $configuration,
						'template_editor' => $template_editor,
					]
				)
			)
		);
		if ( isset( self::$policy_cache[ $cache_key ] ) ) {
			return self::$policy_cache[ $cache_key ];
		}

		$policy = self::core_policy( $context );

		if ( '' !== $provider ) {
			$adapter = Connection_Adapters::get( $provider );
			$declared = is_array( $adapter['generation']['prompt_policy'] ?? null )
				? self::declaration_for_context( $adapter['generation']['prompt_policy'], $modality, $output_type )
				: [];
			$policy = self::merge( $policy, self::normalize( $declared, 'adapter' ) );
		}

		/**
		 * Supply a trusted, non-secret policy for one Connection instance.
		 *
		 * This extension point sits between provider adapter defaults and the
		 * exact Template policy. Remote MCP prose must never be returned here.
		 */
		$connection_policy = apply_filters( 'worldgraph_generation_connection_prompt_policy', [], $connection_id, $context );
		if ( is_array( $connection_policy ) ) {
			$policy = self::merge( $policy, self::normalize( $connection_policy, 'connection' ) );
		}

		$policy = self::merge( $policy, self::model_policy( $context ) );
		$policy = self::merge( $policy, self::normalize( (array) ( $configuration['provider_prompt_policy'] ?? [] ), 'provider_template' ) );
		$policy = self::merge( $policy, self::normalize( (array) ( $configuration['prompt_policy'] ?? [] ), 'template' ) );
		if ( $template_editor ) {
			$policy = self::merge( $policy, self::normalize( $template_editor, 'template_editor' ) );
		}

		$schema_limit = self::schema_prompt_limit( (array) ( $configuration['provider_schema'] ?? [] ) );
		if ( $schema_limit > 0 ) {
			$limit_key = 'midjourney' === $provider ? 'max_bytes' : 'max_characters';
			$policy = self::merge(
				$policy,
				self::normalize(
					[
						'limits' => [ $limit_key => $schema_limit ],
					],
					'provider_schema'
				)
			);
		}

		/**
		 * Filter a normalized, non-executable prompt policy for one Template.
		 *
		 * Callback code is trusted, but its returned shape is normalized again.
		 *
		 * @param array $policy      Effective normalized policy.
		 * @param int   $template_id Template post ID.
		 * @param array $context     Prompt composition context.
		 */
		$filtered = apply_filters( 'worldgraph_generation_prompt_policy', $policy, $template_id, $context );
		if ( is_array( $filtered ) && $filtered !== $policy ) {
			$policy = self::merge( $policy, self::normalize( $filtered, 'filter' ) );
		}

		self::$policy_cache[ $cache_key ] = self::finalize_policy( $policy );
		return self::$policy_cache[ $cache_key ];
	}

	/**
	 * Render semantic sections under an effective Template policy.
	 *
	 * @param array<int, array{id:string,text:string,protected?:bool}> $sections Prompt sections.
	 * @param array<string, mixed>                                    $policy   Normalized policy.
	 * @return array{prompt:string,omitted_sections:array<int,string>,truncated:bool}
	 */
	public static function render( array $sections, array $policy ): array {
		$policy    = self::finalize_policy( $policy );
		$preferred = (array) $policy['sections']['preferred'];
		$forbidden = array_flip( (array) $policy['sections']['forbidden'] );
		$lead_with = (string) $policy['hints']['lead_with'];
		$normalized = [];

		foreach ( $sections as $position => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $section['id'] ?? 'other' ) );
			$id = in_array( $id, self::SECTION_IDS, true ) ? $id : 'other';
			$text = self::clean_text( (string) ( $section['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$protected = ! empty( $section['protected'] ) || in_array( $id, self::PROTECTED_SECTIONS, true );
			if ( isset( $forbidden[ $id ] ) && ! $protected ) {
				continue;
			}
			$normalized[] = [
				'id'        => $id,
				'text'      => $text,
				'protected' => $protected,
				'position'  => (int) $position,
			];
		}

		$rank = array_flip( $preferred );
		// `lead_with` has one stable meaning: after every primary section,
		// prioritize that semantic section before the remaining preference list.
		$section_rank = static function ( array $section ) use ( $rank, $lead_with ): int {
			if ( 'primary' === $section['id'] ) {
				return 0;
			}
			if ( $lead_with === $section['id'] ) {
				return 1;
			}

			return 2 + ( $rank[ $section['id'] ] ?? count( $rank ) + $section['position'] );
		};
		usort(
			$normalized,
			static function ( array $left, array $right ) use ( $section_rank ): int {
				$left_rank  = $section_rank( $left );
				$right_rank = $section_rank( $right );
				return $left_rank === $right_rank ? $left['position'] <=> $right['position'] : $left_rank <=> $right_rank;
			}
		);

		$target          = (int) $policy['limits']['target_words'];
		$maximum         = (int) $policy['limits']['max_words'];
		$selected        = [];
		$omitted         = [];
		$truncated       = false;
		$selected_words  = 0;
		$protected_used  = 0;

		// Pick protected sections first in resolved priority order. Reserving the
		// sum of every protected candidate up front can strand usable capacity when
		// one large, lower-ranked section does not fit. A second optional pass below
		// backfills only the capacity the selected protected sections actually use.
		foreach ( $normalized as $section ) {
			if ( ! $section['protected'] ) {
				continue;
			}
			$words = self::word_count( $section['text'] );
			if ( 'primary' === $section['id'] && 0 === $protected_used && $words > $maximum ) {
				$section['text'] = self::trim_words( $section['text'], $maximum );
				$selected[]      = $section;
				$protected_used  = $maximum;
				$selected_words += $maximum;
				$truncated       = true;
				continue;
			}
			if ( $protected_used + $words <= $maximum ) {
				$selected[]      = $section;
				$protected_used += $words;
				$selected_words += $words;
			} else {
				$omitted[] = $section['id'];
			}
		}

		$optional_capacity = max( 0, $maximum - $protected_used );
		$optional_used     = 0;
		foreach ( $normalized as $section ) {
			if ( $section['protected'] ) {
				continue;
			}
			$words = self::word_count( $section['text'] );
			// The target is creative guidance, not a reservation that crowds out
			// protected instructions. Complete optional sections may fill it while
			// remaining beneath the actual hard capacity left after protected picks.
			if ( $selected_words + $words <= $target && $optional_used + $words <= $optional_capacity ) {
				$selected[]      = $section;
				$selected_words += $words;
				$optional_used  += $words;
				continue;
			}

			$omitted[] = $section['id'];
		}

		// The first semantic description is always the first visible instruction.
		usort(
			$selected,
			static function ( array $left, array $right ) use ( $section_rank ): int {
				$left_rank  = $section_rank( $left );
				$right_rank = $section_rank( $right );
				return $left_rank === $right_rank ? $left['position'] <=> $right['position'] : $left_rank <=> $right_rank;
			}
		);

		$format = (string) ( $policy['hints']['format'] ?? 'natural_language' );
		$texts  = array_map(
			static fn( array $section ): string => self::format_section_text( (string) $section['text'], $format, (string) $section['id'] ),
			$selected
		);
		$prompt = implode( 'concise_phrases' === $format ? ', ' : ( 'chronological_prose' === $format ? ' ' : "\n\n" ), array_filter( $texts ) );
		$before = $prompt;
		$prompt = self::enforce_hard_limits( $prompt, (array) $policy['limits'] );
		$truncated = $truncated || $prompt !== $before || ! empty( $omitted );

		return [
			'prompt'            => $prompt,
			'omitted_sections'  => array_values( array_unique( $omitted ) ),
			'truncated'         => $truncated,
		];
	}

	/** Enforce a Template's hard bounds on a caller-supplied flat prompt. */
	public static function finalize_text( string $prompt, array $policy ): string {
		$policy = self::finalize_policy( $policy );
		return self::enforce_hard_limits( self::clean_text( $prompt ), (array) $policy['limits'] );
	}

	/** Return a safe preview DTO without exposing provider schema or configuration. */
	public static function preview( string $prompt, array $policy, int $template_id = 0 ): array {
		$policy = self::finalize_policy( $policy );

		return [
			'template_id'     => $template_id,
			'policy_version'  => self::VERSION,
			'profile'         => (string) $policy['hints']['profile'],
			'word_count'      => self::word_count( $prompt ),
			'character_count' => self::character_count( $prompt ),
			'byte_count'      => strlen( $prompt ),
			'target_words'    => (int) $policy['limits']['target_words'],
			'hard_limits'     => [
				'max_words'      => (int) $policy['limits']['max_words'],
				'max_characters' => (int) $policy['limits']['max_characters'],
				'max_bytes'      => (int) $policy['limits']['max_bytes'],
			],
			'prompt_hash'     => hash( 'sha256', $prompt ),
		];
	}

	/** Provider-neutral fallbacks, refined by the source content intent. */
	private static function core_policy( array $context ): array {
		$modality   = (string) ( $context['modality'] ?? '' );
		$output_type = (string) ( $context['output_type'] ?? 'image' );
		$post_type   = (string) ( $context['post_type'] ?? '' );
		$intent      = (string) ( $context['intent'] ?? '' );
		$is_reference_conditioned = in_array(
			$modality,
			[
				Generation_Modality::IMAGE_TO_IMAGE,
				Generation_Modality::IMAGE_TEXT_TO_IMAGE,
				Generation_Modality::TEXT_IMAGE_TO_VIDEO,
				Generation_Modality::VIDEO_TO_VIDEO,
			],
			true
		);

		if ( 'audio' === $output_type ) {
			$target    = 2400;
			$maximum   = 2400;
			$max_characters = 50000;
			$max_bytes = 100000;
			$preferred = [ 'primary', 'verbatim', 'objective', 'author_instructions', 'subject', 'action', 'constraints', 'other' ];
			$format    = 'natural_language';
			$lead      = 'subject';
		} elseif ( 'text' === $output_type ) {
			$target    = 400;
			$maximum   = 1000;
			$max_characters = 16000;
			$max_bytes = 48000;
			$preferred = [ 'primary', 'verbatim', 'objective', 'author_instructions', 'constraints', 'other' ];
			$format    = 'natural_language';
			$lead      = 'subject';
		} elseif ( 'video' === $output_type ) {
			$target = $is_reference_conditioned ? 70 : 140;
			$maximum = $is_reference_conditioned ? 100 : 200;
			$max_characters = 6000;
			$max_bytes = 18000;
			$preferred = [ 'primary', 'motion', 'camera', 'objective', 'author_instructions', 'setting', 'characters', 'action', 'look', 'continuity', 'constraints', 'identity', 'dependent_context', 'ancestor_context', 'other' ];
			$format = 'chronological_prose';
			$lead   = 'action';
		} else {
			$target = $is_reference_conditioned ? 60 : 80;
			$maximum = $is_reference_conditioned ? 90 : 120;
			$max_characters = 4000;
			$max_bytes = 12000;
			$preferred = [ 'primary', 'identity', 'objective', 'author_instructions', 'subject', 'action', 'camera', 'setting', 'characters', 'look', 'continuity', 'dependent_context', 'constraints', 'ancestor_context', 'other' ];
			$format = 'natural_language';
			$lead   = 'subject';
		}

		if ( 'worldgraph_shot' === $post_type ) {
			$preferred = [ 'primary', 'objective', 'author_instructions', 'camera', 'action', 'motion', 'setting', 'characters', 'look', 'continuity', 'constraints', 'identity', 'ancestor_context', 'other' ];
			if ( 'image' === $output_type ) {
				$target = 120;
			}
		} elseif ( in_array( $intent, [ 'scene-filmstrip', 'episode-bookend-filmstrip' ], true ) ) {
			$preferred = [ 'primary', 'objective', 'dependent_context', 'author_instructions', 'setting', 'characters', 'look', 'continuity', 'constraints', 'identity', 'ancestor_context', 'other' ];
			$target    = 120;
		}

		return self::normalize(
			[
				'limits'   => [
					'target_words' => $target,
					'max_words'    => $maximum,
					'max_characters' => $max_characters,
					'max_bytes'      => $max_bytes,
				],
				'sections' => [ 'preferred' => $preferred ],
				'hints'    => [
					'profile'   => 'fallback-' . $output_type,
					'lead_with' => $lead,
					'format'    => $format,
				],
			],
			'core'
		);
	}

	/** Reviewed model/operation profiles selected from Template metadata. */
	private static function model_policy( array $context ): array {
		$family   = (string) ( $context['model_family'] ?? '' );
		$modality = (string) ( $context['modality'] ?? '' );
		$provider = (string) ( $context['provider_type'] ?? '' );
		$output_type = (string) ( $context['output_type'] ?? '' );
		$hint     = strtolower( implode( ' ', array_map( 'strval', (array) ( $context['model_hints'] ?? [] ) ) ) );
		$is_reference_conditioned = in_array( $modality, [ Generation_Modality::TEXT_IMAGE_TO_VIDEO, Generation_Modality::VIDEO_TO_VIDEO ], true );

		if ( 'video' === $output_type && ( Model_Family::SCAIL === $family || self::hint_has_token( $hint, 'scail' ) ) ) {
			return self::normalize(
				[
					'limits'   => [ 'target_words' => 45, 'max_words' => 80 ],
					'sections' => [ 'preferred' => [ 'primary', 'motion', 'action', 'camera', 'author_instructions', 'look', 'constraints', 'setting', 'ancestor_context' ] ],
					'hints'    => [ 'profile' => 'scail-motion-refinement', 'lead_with' => 'action', 'format' => 'concise_phrases' ],
				],
				'model_family'
			);
		}

		if ( 'video' === $output_type && ( Model_Family::WAN === $family || self::hint_has_token( $hint, 'wan' ) ) ) {
			return self::normalize(
				[
					'limits'   => [
						'target_words' => $is_reference_conditioned ? 75 : 100,
						'max_words'    => $is_reference_conditioned ? 100 : 200,
					],
					'sections' => [ 'preferred' => [ 'primary', 'motion', 'camera', 'action', 'objective', 'author_instructions', 'setting', 'characters', 'look', 'continuity', 'constraints', 'ancestor_context' ] ],
					'hints'    => [ 'profile' => 'wan-motion-first', 'lead_with' => 'motion', 'format' => 'chronological_prose' ],
				],
				'model_family'
			);
		}

		if ( 'video' === $output_type && ( Model_Family::LTXV === $family || self::hint_has_token( $hint, 'ltx' ) ) ) {
			return self::normalize(
				[
					'limits'   => [ 'target_words' => 140, 'max_words' => 200 ],
					'sections' => [ 'preferred' => [ 'primary', 'motion', 'camera', 'action', 'objective', 'author_instructions', 'setting', 'characters', 'look', 'continuity', 'constraints', 'ancestor_context' ] ],
					'hints'    => [ 'profile' => 'ltx-chronological-action', 'lead_with' => 'action', 'format' => 'chronological_prose' ],
				],
				'model_family'
			);
		}

		if ( 'video' === $output_type && ( Model_Family::MINIMAX === $family || self::hint_has_token( $hint, 'minimax' ) || self::hint_has_token( $hint, 'hailuo' ) ) ) {
			return self::normalize(
				[
					'limits' => [ 'target_words' => $is_reference_conditioned ? 60 : 100, 'max_words' => $is_reference_conditioned ? 100 : 140, 'max_characters' => 2000 ],
					'hints'  => [ 'profile' => 'minimax-direct-motion', 'lead_with' => 'action', 'format' => 'chronological_prose' ],
				],
				'model_family'
			);
		}

		if ( 'image' === $output_type && self::hint_has_token( $hint, 'flux' ) ) {
			return self::normalize(
				[
					'limits'   => [ 'target_words' => 70, 'max_words' => 120 ],
					'sections' => [ 'preferred' => [ 'primary', 'identity', 'action', 'objective', 'camera', 'setting', 'look', 'characters', 'author_instructions', 'continuity', 'constraints', 'ancestor_context' ] ],
					'hints'    => [ 'profile' => 'flux-subject-first', 'lead_with' => 'subject', 'format' => 'natural_language' ],
				],
				'model_slug'
			);
		}

		if ( 'image' === $output_type && 'midjourney' === $provider ) {
			return self::normalize(
				[
					'limits'   => [ 'target_words' => 50, 'max_words' => 90 ],
					'sections' => [
						'preferred' => [ 'primary', 'identity', 'action', 'setting', 'camera', 'look', 'characters', 'author_instructions', 'constraints' ],
						'forbidden' => [ 'ancestor_context' ],
					],
					'hints'    => [ 'profile' => 'midjourney-concise', 'lead_with' => 'subject', 'format' => 'concise_phrases' ],
				],
				'provider'
			);
		}

		if ( 'video' === $output_type && self::hint_has_token( $hint, 'veo' ) ) {
			return self::normalize(
				[
					'limits' => [ 'target_words' => 120, 'max_words' => 180 ],
					'hints'  => [ 'profile' => 'veo-shot-sequence', 'lead_with' => 'action', 'format' => 'chronological_prose' ],
				],
				'model_slug'
			);
		}

		return [];
	}

	/** Select a flat/default/output/modality declaration from adapter metadata. */
	private static function declaration_for_context( array $declaration, string $modality, string $output_type ): array {
		if ( isset( $declaration['limits'] ) || isset( $declaration['sections'] ) || isset( $declaration['hints'] ) ) {
			return $declaration;
		}

		$result = is_array( $declaration['default'] ?? null ) ? $declaration['default'] : [];
		if ( is_array( $declaration[ $output_type ] ?? null ) ) {
			$result = self::replace_declaration_layer( $result, $declaration[ $output_type ] );
		}
		if ( '' !== $modality && is_array( $declaration[ $modality ] ?? null ) ) {
			$result = self::replace_declaration_layer( $result, $declaration[ $modality ] );
		}

		return $result;
	}

	/** Build the sparse policy declaration exposed by the Template editor. */
	private static function template_editor_declaration( int $template_id ): array {
		$limits = [];
		foreach ( [ 'prompt_target_words' => 'target_words', 'prompt_max_words' => 'max_words' ] as $field_name => $policy_key ) {
			$value = worldgraph_get_field_value( $template_id, $field_name );
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				$limits[ $policy_key ] = (int) $value;
			}
		}

		$hints     = [];
		$lead_with = sanitize_key( (string) worldgraph_get_field_value( $template_id, 'prompt_lead_with' ) );
		if ( in_array( $lead_with, self::LEAD_WITH, true ) ) {
			$hints['lead_with'] = $lead_with;
		}
		$format = sanitize_key( (string) worldgraph_get_field_value( $template_id, 'prompt_format' ) );
		if ( in_array( $format, self::FORMATS, true ) ) {
			$hints['format'] = $format;
		}

		$declaration = [];
		if ( $limits ) {
			$declaration['limits'] = $limits;
		}
		if ( $hints ) {
			$declaration['hints'] = $hints;
		}

		return $declaration;
	}

	/**
	 * Overlay one declaration layer while treating ordered lists as atomic.
	 *
	 * Numeric section lists express a complete order or prohibition set. PHP's
	 * array_replace_recursive() combines their numeric indexes and can leave a
	 * hybrid of two policies, so a later explicit list replaces the earlier one.
	 */
	private static function replace_declaration_layer( array $base, array $overlay ): array {
		foreach ( $overlay as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! self::is_list( $value ) && ! self::is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::replace_declaration_layer( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}

	/** Normalize only allowlisted scalar/enumerated policy fields. */
	private static function normalize( array $candidate, string $source ): array {
		$limits = is_array( $candidate['limits'] ?? null ) ? $candidate['limits'] : $candidate;
		$sections = is_array( $candidate['sections'] ?? null ) ? $candidate['sections'] : [];
		$hints = is_array( $candidate['hints'] ?? null ) ? $candidate['hints'] : $candidate;
		$normalized = [
			'version'  => self::VERSION,
			'limits'   => [],
			'sections' => [],
			'hints'    => [],
			'sources'  => [],
		];

		foreach ( [ 'target_words', 'max_words', 'max_characters', 'max_bytes' ] as $key ) {
			if ( isset( $limits[ $key ] ) && is_numeric( $limits[ $key ] ) ) {
				$normalized['limits'][ $key ] = max( 0, (int) $limits[ $key ] );
			}
		}

		foreach ( [ 'preferred', 'forbidden' ] as $key ) {
			if ( ! is_array( $sections[ $key ] ?? null ) ) {
				continue;
			}
			$normalized['sections'][ $key ] = array_values( array_unique( array_filter( array_map(
				static function ( $value ): string {
					$value = sanitize_key( (string) $value );
					return in_array( $value, self::SECTION_IDS, true ) ? $value : '';
				},
				$sections[ $key ]
			) ) ) );
		}

		$profile = sanitize_key( (string) ( $hints['profile'] ?? '' ) );
		if ( '' !== $profile ) {
			$normalized['hints']['profile'] = substr( $profile, 0, 80 );
		}
		$lead_with = sanitize_key( (string) ( $hints['lead_with'] ?? '' ) );
		if ( in_array( $lead_with, self::LEAD_WITH, true ) ) {
			$normalized['hints']['lead_with'] = $lead_with;
		}
		$format = sanitize_key( (string) ( $hints['format'] ?? '' ) );
		if ( in_array( $format, self::FORMATS, true ) ) {
			$normalized['hints']['format'] = $format;
		}
		if ( '' !== $source && ( $normalized['limits'] || $normalized['sections'] || $normalized['hints'] ) ) {
			$normalized['sources'][] = sanitize_key( $source );
		}

		return $normalized;
	}

	/** Merge preferences by precedence and hard limits by minimum ceiling. */
	private static function merge( array $base, array $overlay ): array {
		if ( empty( $overlay ) ) {
			return $base;
		}
		$base = self::finalize_policy( $base );
		foreach ( [ 'target_words' ] as $key ) {
			if ( ! empty( $overlay['limits'][ $key ] ) ) {
				$base['limits'][ $key ] = (int) $overlay['limits'][ $key ];
			}
		}
		foreach ( [ 'max_words', 'max_characters', 'max_bytes' ] as $key ) {
			$value = (int) ( $overlay['limits'][ $key ] ?? 0 );
			if ( $value > 0 ) {
				$current = (int) ( $base['limits'][ $key ] ?? 0 );
				$base['limits'][ $key ] = $current > 0 ? min( $current, $value ) : $value;
			}
		}

		if ( ! empty( $overlay['sections']['preferred'] ) ) {
			$base['sections']['preferred'] = array_values( array_unique( array_merge( $overlay['sections']['preferred'], $base['sections']['preferred'] ) ) );
		}
		if ( ! empty( $overlay['sections']['forbidden'] ) ) {
			$base['sections']['forbidden'] = array_values( array_unique( array_merge( $base['sections']['forbidden'], $overlay['sections']['forbidden'] ) ) );
		}
		foreach ( [ 'profile', 'lead_with', 'format' ] as $key ) {
			if ( ! empty( $overlay['hints'][ $key ] ) ) {
				$base['hints'][ $key ] = $overlay['hints'][ $key ];
			}
		}
		$base['sources'] = array_values( array_unique( array_merge( (array) $base['sources'], (array) ( $overlay['sources'] ?? [] ) ) ) );

		return self::finalize_policy( $base );
	}

	/** Supply complete safe defaults and clamp all values. */
	private static function finalize_policy( array $policy ): array {
		$limits   = is_array( $policy['limits'] ?? null ) ? $policy['limits'] : [];
		$sections = is_array( $policy['sections'] ?? null ) ? $policy['sections'] : [];
		$hints    = is_array( $policy['hints'] ?? null ) ? $policy['hints'] : [];
		$maximum  = min( self::MAX_HARD_WORDS, max( 1, (int) ( $limits['max_words'] ?? 120 ) ) );
		$target   = min( $maximum, min( self::MAX_TARGET_WORDS, max( self::MIN_TARGET_WORDS, (int) ( $limits['target_words'] ?? min( 80, $maximum ) ) ) ) );
		$preferred = array_values( array_unique( array_filter( (array) ( $sections['preferred'] ?? self::SECTION_IDS ), static fn( $value ): bool => in_array( $value, self::SECTION_IDS, true ) ) ) );
		$forbidden = array_values( array_diff( array_unique( array_filter( (array) ( $sections['forbidden'] ?? [] ), static fn( $value ): bool => in_array( $value, self::SECTION_IDS, true ) ) ), self::PROTECTED_SECTIONS ) );

		return [
			'version'  => self::VERSION,
			'limits'   => [
				'target_words' => $target,
				'max_words'    => $maximum,
				'max_characters' => min( self::MAX_HARD_CHARS, max( 0, (int) ( $limits['max_characters'] ?? 0 ) ) ),
				'max_bytes'      => min( self::MAX_HARD_BYTES, max( 0, (int) ( $limits['max_bytes'] ?? 0 ) ) ),
			],
			'sections' => [
				'preferred' => array_values( array_unique( array_merge( $preferred, self::SECTION_IDS ) ) ),
				'forbidden' => $forbidden,
			],
			'hints'    => [
				'profile'   => substr( sanitize_key( (string) ( $hints['profile'] ?? 'fallback' ) ), 0, 80 ),
				'lead_with' => in_array( (string) ( $hints['lead_with'] ?? '' ), self::LEAD_WITH, true ) ? $hints['lead_with'] : 'subject',
				'format'    => in_array( (string) ( $hints['format'] ?? '' ), self::FORMATS, true ) ? $hints['format'] : 'natural_language',
			],
			'sources'  => array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $policy['sources'] ?? [] ) ) ) ) ), 0, 12 ),
		];
	}

	/** Find a direct positive-prompt maxLength in a bounded schema traversal. */
	private static function schema_prompt_limit( array $schema ): int {
		$queue   = [ [ $schema, 0 ] ];
		$visited = 0;
		$limits  = [];
		while ( $queue && $visited < 200 ) {
			[ $node, $depth ] = array_shift( $queue );
			++$visited;
			if ( ! is_array( $node ) || $depth > 6 ) {
				continue;
			}
			$properties = is_array( $node['properties'] ?? null ) ? $node['properties'] : [];
			foreach ( [ 'prompt', 'positive_prompt', 'text_prompt' ] as $key ) {
				$definition = $properties[ $key ] ?? null;
				if ( is_array( $definition ) && isset( $definition['maxLength'] ) && is_numeric( $definition['maxLength'] ) ) {
					$limits[] = min( self::MAX_HARD_CHARS, max( 1, (int) $definition['maxLength'] ) );
				}
			}
			foreach ( $node as $key => $child ) {
				if ( is_array( $child ) && in_array( (string) $key, [ 'inputSchema', 'input_schema', 'schema', 'properties', 'components' ], true ) ) {
					$queue[] = [ $child, $depth + 1 ];
				}
			}
		}

		return $limits ? min( $limits ) : 0;
	}

	/** Infer a stable family from explicit metadata and Template identifiers. */
	private static function template_family( int $template_id ): string {
		$family = Model_Family::sanitize( (string) worldgraph_get_field_value( $template_id, 'model_family' ) );
		if ( '' !== $family ) {
			return $family;
		}

		$post = get_post( $template_id );
		$hint = strtolower( implode( ' ', [
			$post instanceof \WP_Post ? (string) $post->post_title : '',
			(string) worldgraph_get_field_value( $template_id, 'template_name' ),
			(string) worldgraph_get_field_value( $template_id, 'provider_template_id' ),
			(string) worldgraph_get_field_value( $template_id, 'checkpoint' ),
		] ) );
		return self::family_from_hint( $hint );
	}

	/** Infer a registered family from compact identifiers embedded in metadata. */
	private static function family_from_hint( string $hint ): string {
		if ( self::hint_has_token( $hint, 'scail' ) ) {
			return Model_Family::SCAIL;
		}
		if ( self::hint_has_token( $hint, 'wan' ) ) {
			return Model_Family::WAN;
		}
		if ( self::hint_has_token( $hint, 'ltx' ) ) {
			return Model_Family::LTXV;
		}
		if ( self::hint_has_token( $hint, 'minimax' ) || self::hint_has_token( $hint, 'hailuo' ) ) {
			return Model_Family::MINIMAX;
		}

		return '';
	}

	/** Match model identifiers as tokens, avoiding names such as "Swan Song". */
	private static function hint_has_token( string $hint, string $token ): bool {
		$token = strtolower( $token );
		if ( 'wan' === $token ) {
			$token_pattern = 'wan(?:video)?';
		} elseif ( 'ltx' === $token ) {
			$token_pattern = 'ltx(?:v|video)?';
		} else {
			$token_pattern = preg_quote( $token, '/' );
		}

		return 1 === preg_match( '/(?:^|[^a-z0-9])' . $token_pattern . '(?:(?:[\s._-]*v?\d)[a-z0-9._-]*)?(?=$|[^a-z0-9])/i', $hint );
	}

	/** Enforce word, Unicode character, then byte ceilings at word boundaries. */
	private static function enforce_hard_limits( string $prompt, array $limits ): string {
		$prompt = self::trim_words( $prompt, max( 1, (int) ( $limits['max_words'] ?? self::MAX_HARD_WORDS ) ) );
		$max_characters = (int) ( $limits['max_characters'] ?? 0 );
		if ( $max_characters > 0 && self::character_count( $prompt ) > $max_characters ) {
			$prompt = self::trim_characters( $prompt, $max_characters );
		}
		$max_bytes = (int) ( $limits['max_bytes'] ?? 0 );
		if ( $max_bytes > 0 && strlen( $prompt ) > $max_bytes ) {
			$prompt = self::trim_bytes( $prompt, $max_bytes );
		}

		return trim( $prompt );
	}

	/** Collapse markup and horizontal whitespace while preserving paragraph order. */
	private static function clean_text( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$value = (string) preg_replace( '/\r\n?|\n{3,}/u', "\n\n", $value );
		$value = (string) preg_replace( '/[\t ]+/u', ' ', $value );
		return trim( $value );
	}

	/** Convert semantic labels into the model family's preferred surface form. */
	private static function format_section_text( string $text, string $format, string $section_id ): string {
		if ( 'natural_language' === $format ) {
			return $text;
		}
		$text   = trim( $text );
		$labels = (array) ( self::SECTION_LABELS[ $section_id ] ?? [] );
		if ( $labels && 1 === preg_match( '/^([^\n:]{1,48}):\s*/u', $text, $matches ) ) {
			$label = strtolower( trim( (string) $matches[1] ) );
			if ( in_array( $label, $labels, true ) ) {
				$text = (string) preg_replace( '/^[^\n:]{1,48}:\s*/u', '', $text, 1 );
			}
		}
		$text = trim( (string) preg_replace( '/\s*\n+\s*/u', ' ', $text ) );
		if ( 'concise_phrases' === $format ) {
			return rtrim( $text, " \t\n\r\0\x0B.;," );
		}
		return preg_match( '/[.!?]$/u', $text ) ? $text : $text . '.';
	}

	/** Canonicalize associative keys so equivalent prompt contexts share one policy. */
	private static function canonicalize_cache_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = self::is_list( $value );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize_cache_value( $item );
		}

		return $value;
	}

	/** PHP 8.1 array_is_list() with a stable fallback for test/runtime parity. */
	private static function is_list( array $value ): bool {
		return function_exists( 'array_is_list' )
			? array_is_list( $value )
			: [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/** Count whitespace-delimited words consistently across composer and preview. */
	private static function word_count( string $value ): int {
		$words = preg_split( '/\s+/u', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/** Trim words without adding an ellipsis that consumes provider context. */
	private static function trim_words( string $value, int $maximum ): string {
		if ( self::word_count( $value ) <= $maximum ) {
			return trim( $value );
		}
		return trim( wp_trim_words( $value, max( 1, $maximum ), '' ) );
	}

	/** Unicode-aware character count with a safe non-mbstring fallback. */
	private static function character_count( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/** Trim Unicode text to a character ceiling and then to a word boundary. */
	private static function trim_characters( string $value, int $maximum ): string {
		if ( ! function_exists( 'mb_substr' ) ) {
			return self::trim_bytes( $value, $maximum );
		}
		$value = mb_substr( $value, 0, $maximum, 'UTF-8' );
		return self::trim_partial_word( $value );
	}

	/** Trim to a byte ceiling without leaving an invalid UTF-8 or partial word. */
	private static function trim_bytes( string $value, int $maximum ): string {
		$value = substr( $value, 0, $maximum );
		while ( '' !== $value && 1 !== preg_match( '//u', $value ) ) {
			$value = substr( $value, 0, -1 );
		}
		return self::trim_partial_word( $value );
	}

	/** Drop a trailing partial word introduced by a character/byte hard cut. */
	private static function trim_partial_word( string $value ): string {
		$value = rtrim( $value );
		if ( '' === $value || preg_match( '/[\s.!?;,:\-]$/u', $value ) ) {
			return $value;
		}
		$trimmed = preg_replace( '/\s+\S*$/u', '', $value );
		return '' !== trim( (string) $trimmed ) ? trim( (string) $trimmed ) : $value;
	}
}
