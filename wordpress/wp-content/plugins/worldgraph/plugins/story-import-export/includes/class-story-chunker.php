<?php
/**
 * Deterministic, structure-aware story chunk planning.
 *
 * @package WorldGraphStoryIO
 */

namespace WorldGraphStoryIO;

defined( 'ABSPATH' ) || exit;

/** Build bounded, non-overlapping manuscript chunks with separate context. */
class Story_Chunker {
	public const DEFAULT_TARGET_CHARS  = 12_000;
	public const DEFAULT_MAX_CHARS     = 16_000;
	public const DEFAULT_MIN_CHARS     = 600;
	public const DEFAULT_MAX_PARTS     = 128;
	public const DEFAULT_CONTEXT_CHARS = 1_000;

	private const MINIMUM_LIMIT = 32;
	private const MAXIMUM_PARTS = 1_000;
	private const CONTEXT_LIMIT = 20_000;
	private const MAX_STRUCTURAL_BOUNDARIES = 10_000;

	private const PRIORITIES = [
		'chapter'   => 60,
		'section'   => 50,
		'scene'     => 40,
		'paragraph' => 30,
		'sentence'  => 20,
		'word'      => 10,
		'hard'      => 0,
	];

	/**
	 * Conservatively prepare source text and remap trusted structural offsets.
	 *
	 * @param string $text       Extracted source text.
	 * @param array  $boundaries Optional extractor-provided structural metadata.
	 * @return array{text:string,source_text:string,boundaries:array,removed_prefix_chars:int,removed_suffix_chars:int}
	 */
	public function prepare( string $text, array $boundaries = [] ): array {
		$original = $this->normalize_text( $text );
		$length   = mb_strlen( $original, 'UTF-8' );
		$start    = 0;
		$end      = $length;

		$start_match = [];
		if ( preg_match( '/\*{3}\s*START OF (?:THIS |THE )?PROJECT GUTENBERG EBOOK\b[^\r\n]*\*{3}/iu', $original, $start_match, PREG_OFFSET_CAPTURE ) ) {
			$start = $this->byte_to_character_offset( $original, (int) $start_match[0][1] + strlen( (string) $start_match[0][0] ) );
		}

		$end_match = [];
		if ( preg_match( '/\*{3}\s*END OF (?:THIS |THE )?PROJECT GUTENBERG EBOOK\b[^\r\n]*\*{3}/iu', $original, $end_match, PREG_OFFSET_CAPTURE ) ) {
			$candidate_end = $this->byte_to_character_offset( $original, (int) $end_match[0][1] );
			if ( $candidate_end >= $start ) {
				$end = $candidate_end;
			}
		}

		$sliced = mb_substr( $original, $start, max( 0, $end - $start ), 'UTF-8' );
		$note_matches = [];
		if ( preg_match_all( '/(?:^|\n)[ \t]*Transcriber(?:\x{2019}|\x{0027})?s? Note\s*:/imu', $sliced, $note_matches, PREG_OFFSET_CAPTURE ) ) {
			$last_note = end( $note_matches[0] );
			$note_at   = $this->byte_to_character_offset( $sliced, (int) $last_note[1] );
			if ( $note_at >= (int) floor( mb_strlen( $sliced, 'UTF-8' ) * 0.72 ) ) {
				$end    = $start + $note_at;
				$sliced = mb_substr( $sliced, 0, $note_at, 'UTF-8' );
			}
		}

		$leading = 0;
		if ( preg_match( '/^\s+/u', $sliced, $leading_match ) ) {
			$leading = mb_strlen( (string) $leading_match[0], 'UTF-8' );
		}
		$trailing = 0;
		if ( preg_match( '/\s+$/u', $sliced, $trailing_match ) ) {
			$trailing = mb_strlen( (string) $trailing_match[0], 'UTF-8' );
		}
		$prepared_start = min( $end, $start + $leading );
		$prepared_end   = max( $prepared_start, $end - $trailing );
		$prepared       = mb_substr( $original, $prepared_start, $prepared_end - $prepared_start, 'UTF-8' );
		$remapped       = [];
		foreach ( $boundaries as $boundary ) {
			if ( ! is_array( $boundary ) ) {
				continue;
			}
			$position = $this->remap_boundary( $boundary, $prepared, $prepared_start );
			if ( null === $position ) {
				continue;
			}
			$boundary['offset'] = $position;
			$remapped[]         = $boundary;
		}
		$remapped = $this->unique_boundaries( $remapped, mb_strlen( $prepared, 'UTF-8' ) );

		return [
			'text'                 => $prepared,
			'source_text'          => $prepared,
			'boundaries'           => $remapped,
			'removed_prefix_chars' => $prepared_start,
			'removed_suffix_chars' => max( 0, $length - $prepared_end ),
		];
	}

	/**
	 * Produce deterministic descriptors whose primary text exactly covers source_text.
	 *
	 * Supported options: target_chars, max_chars, min_chars, max_parts,
	 * context_chars (or separate context_before_chars/context_after_chars),
	 * boundaries, and source_is_prepared for exact internal subdivision.
	 *
	 * @return array|\WP_Error Chunk plan or a stable planning error.
	 */
	public function plan( string $text, array $options = [] ) {
		$max_chars    = max( self::MINIMUM_LIMIT, absint( $options['max_chars'] ?? self::DEFAULT_MAX_CHARS ) );
		$target_chars = max( self::MINIMUM_LIMIT, min( $max_chars, absint( $options['target_chars'] ?? self::DEFAULT_TARGET_CHARS ) ) );
		$min_chars    = max( 1, min( $target_chars, absint( $options['min_chars'] ?? self::DEFAULT_MIN_CHARS ) ) );
		$max_parts    = max( 1, min( self::MAXIMUM_PARTS, absint( $options['max_parts'] ?? self::DEFAULT_MAX_PARTS ) ) );
		$context_chars = absint( $options['context_chars'] ?? self::DEFAULT_CONTEXT_CHARS );
		$before_chars = max( 0, min( self::CONTEXT_LIMIT, absint( $options['context_before_chars'] ?? $context_chars ) ) );
		$after_chars  = max( 0, min( self::CONTEXT_LIMIT, absint( $options['context_after_chars'] ?? $context_chars ) ) );
		$external_boundaries = is_array( $options['boundaries'] ?? null ) ? $options['boundaries'] : [];
		if ( ! empty( $options['source_is_prepared'] ) ) {
			$prepared_text = $this->normalize_text( $text );
			$prepared      = [
				'text'                 => $prepared_text,
				'source_text'          => $prepared_text,
				'boundaries'           => $this->unique_boundaries( $external_boundaries, mb_strlen( $prepared_text, 'UTF-8' ) ),
				'removed_prefix_chars' => 0,
				'removed_suffix_chars' => 0,
			];
		} else {
			$prepared = $this->prepare( $text, $external_boundaries );
		}
		$source_text  = (string) $prepared['text'];
		$characters   = mb_strlen( $source_text, 'UTF-8' );
		if ( 0 === $characters ) {
			return new \WP_Error( 'worldgraph_story_chunk_empty', __( 'The story source contains no text to plan.', 'worldgraph' ) );
		}

		$minimum_parts = (int) ceil( $characters / $max_chars );
		if ( $minimum_parts > $max_parts ) {
			return $this->too_many_parts_error( $characters, $max_chars, $max_parts, $minimum_parts );
		}

		$candidates  = $this->boundary_candidates( $source_text, (array) $prepared['boundaries'] );
		$positions   = $candidates['positions']['word'];
		$source_hash = hash( 'sha256', $source_text );
		$ranges       = [];
		$start        = 0;
		while ( $start < $characters ) {
			if ( count( $ranges ) >= $max_parts ) {
				return $this->too_many_parts_error( $characters, $max_chars, $max_parts, count( $ranges ) + 1 );
			}

			$remaining = $characters - $start;
			if ( $remaining <= $max_chars ) {
				$ranges[] = [
					'start'       => $start,
					'end'         => $characters,
					'break_after' => [
						'position' => $characters,
						'type'     => 'end',
						'label'    => 'End of source',
						'source'   => 'source',
					],
				];
				break;
			}

			$slots_left      = $max_parts - count( $ranges );
			$raw_capacity    = max( $start + 1, $characters - ( $max_chars * max( 0, $slots_left - 1 ) ) );
			$quality_cut     = $start + min( $min_chars, max( 1, $remaining - $min_chars ) );
			$ideal_cut       = min( $start + $target_chars, $characters - $min_chars );
			$hard_cut        = min( $start + $max_chars, $characters - $min_chars );
			$natural_capacity = $this->earliest_suffix_start( $positions, $characters, $max_chars, $slots_left - 1 );
			$capacity_cut    = null !== $natural_capacity && $natural_capacity <= $hard_cut
				? max( $raw_capacity, $natural_capacity )
				: $raw_capacity;
			$minimum_cut = min( $start + $max_chars, max( $capacity_cut, $quality_cut ) );
			if ( $capacity_cut > $hard_cut ) {
				return $this->too_many_parts_error( $characters, $max_chars, $max_parts, $max_parts + 1 );
			}
			$selected = $this->choose_cut( $candidates, $start, $capacity_cut, $minimum_cut, $ideal_cut, $hard_cut );
			$end             = (int) $selected['position'];
			if ( $end <= $start || $end > $characters ) {
				return new \WP_Error( 'worldgraph_story_chunk_stalled', __( 'The story chunk planner could not advance through the source.', 'worldgraph' ) );
			}

			$remaining_parts = (int) ceil( ( $characters - $end ) / $max_chars );
			if ( $remaining_parts > $slots_left - 1 ) {
				return $this->too_many_parts_error( $characters, $max_chars, $max_parts, count( $ranges ) + 1 + $remaining_parts );
			}

			$ranges[] = [
				'start'       => $start,
				'end'         => $end,
				'break_after' => $selected,
			];
			$start = $end;
		}

		$total         = count( $ranges );
		$chunks        = [];
		$section_parts = [];
		foreach ( $ranges as $index => $range ) {
			$start   = (int) $range['start'];
			$end     = (int) $range['end'];
			$content = mb_substr( $source_text, $start, $end - $start, 'UTF-8' );
			$section = $this->active_section( $candidates['structural'], $start );
			$key     = (string) ( $section['position'] ?? 0 ) . '|' . (string) ( $section['type'] ?? 'section' ) . '|' . (string) ( $section['label'] ?? 'Opening' );
			$section_parts[ $key ] = ( $section_parts[ $key ] ?? 0 ) + 1;
			$section_part = $section_parts[ $key ];
			$base_label   = trim( (string) ( $section['label'] ?? '' ) );
			$base_label   = '' !== $base_label ? $base_label : 'Opening';
			$label        = 1 === $section_part ? $base_label : sprintf( '%s — part %d', $base_label, $section_part );
			$content_hash = hash( 'sha256', $content );

			$chunks[] = [
				'id'             => sprintf( 'story-chunk-%04d-%s', $index + 1, substr( $content_hash, 0, 12 ) ),
				'index'          => $index,
				'ordinal'        => $index + 1,
				'total'          => $total,
				'label'          => $label,
				'start'          => $start,
				'end'            => $end,
				'length'         => $end - $start,
				'hash'           => $content_hash,
				'text'           => $content,
				'context_before' => $this->context_descriptor( $source_text, $positions, max( 0, $start - $before_chars ), $start, true ),
				'context_after'  => $this->context_descriptor( $source_text, $positions, $end, min( $characters, $end + $after_chars ), false ),
				'metadata'       => [
					'section_type'   => (string) ( $section['type'] ?? 'section' ),
					'section_label'  => $base_label,
					'section_part'   => $section_part,
					'section_source' => (string) ( $section['source'] ?? 'source' ),
					'break_type'     => (string) ( $range['break_after']['type'] ?? 'end' ),
					'break_label'    => (string) ( $range['break_after']['label'] ?? '' ),
					'break_source'   => (string) ( $range['break_after']['source'] ?? 'source' ),
				],
			];
		}

		$structural_boundaries = $candidates['structural'];

		return [
			'version'      => 1,
			'text'         => $source_text,
			'source_text'  => $source_text,
			'source_hash'  => $source_hash,
			'characters'   => $characters,
			'chunk_count'  => $total,
			'boundaries'   => $structural_boundaries,
			'profile'      => [
				'target_chars'         => $target_chars,
				'max_chars'            => $max_chars,
				'min_chars'            => $min_chars,
				'max_parts'            => $max_parts,
				'context_before_chars' => $before_chars,
				'context_after_chars'  => $after_chars,
			],
			'preparation'  => [
				'removed_prefix_chars' => (int) $prepared['removed_prefix_chars'],
				'removed_suffix_chars' => (int) $prepared['removed_suffix_chars'],
			],
			'chunks'       => $chunks,
		];
	}

	/** Normalize UTF-8 and control characters without changing interior prose spacing. */
	private function normalize_text( string $text ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		$text = preg_replace( '/[^\P{C}\n\t]+/u', '', $text );
		return is_string( $text ) ? $text : '';
	}

	/** Build compact positional indexes in UTF-8 character coordinates. */
	private function boundary_candidates( string $text, array $external ): array {
		$positions  = $this->textual_boundary_positions( $text );
		$structural = [];

		foreach ( $external as $boundary ) {
			if ( ! is_array( $boundary ) ) {
				continue;
			}
			$type = strtolower( (string) ( $boundary['type'] ?? 'section' ) );
			$type = isset( self::PRIORITIES[ $type ] ) && ! in_array( $type, [ 'paragraph', 'sentence', 'word', 'hard' ], true ) ? $type : 'section';
			$this->add_structural_candidate(
				$structural,
				array_merge(
					$boundary,
					[
						'position' => max( 0, min( mb_strlen( $text, 'UTF-8' ), (int) ( $boundary['offset'] ?? 0 ) ) ),
						'type'     => $type,
						'label'    => $this->clean_label( (string) ( $boundary['label'] ?? ucfirst( $type ) ) ),
						'source'   => (string) ( $boundary['source'] ?? 'extractor' ),
						'extractor_boundary' => true,
					]
				)
			);
		}
		$this->collect_heading_candidates( $text, $structural );

		ksort( $structural, SORT_NUMERIC );
		$structural = array_values( $structural );
		foreach ( $structural as &$candidate ) {
			unset( $candidate['extractor_boundary'] );
		}
		unset( $candidate );
		foreach ( $structural as $candidate ) {
			$positions[ $candidate['type'] ][] = (int) $candidate['position'];
		}
		foreach ( $positions as &$type_positions ) {
			sort( $type_positions, SORT_NUMERIC );
		}
		unset( $type_positions );

		return [
			'positions'  => $positions,
			'structural' => $structural,
		];
	}

	/** Scan text once, retaining integer positions rather than per-space arrays. */
	private function textual_boundary_positions( string $text ): array {
		$result = [ 'chapter' => [], 'section' => [], 'scene' => [], 'paragraph' => [], 'sentence' => [], 'word' => [] ];
		$bytes  = strlen( $text );
		$byte   = 0;
		$character = 0;
		$in_whitespace = false;
		$newlines = 0;
		$sentence_before_whitespace = false;
		$sentence_pending = false;
		while ( $byte < $bytes ) {
			$lead = ord( $text[ $byte ] );
			$width = $lead < 0x80 ? 1 : ( ( $lead & 0xE0 ) === 0xC0 ? 2 : ( ( $lead & 0xF0 ) === 0xE0 ? 3 : 4 ) );
			$char  = substr( $text, $byte, min( $width, $bytes - $byte ) );
			$byte += $width;
			++$character;
			$is_whitespace = $lead < 0x80
				? in_array( $char, [ " ", "\t", "\n", "\f", "\v" ], true )
				: 1 === preg_match( '/^\s$/u', $char );

			if ( $is_whitespace ) {
				if ( ! $in_whitespace ) {
					$in_whitespace = true;
					$newlines = 0;
					$sentence_before_whitespace = $sentence_pending;
				}
				if ( "\n" === $char ) {
					++$newlines;
				}
				continue;
			}

			if ( $in_whitespace ) {
				$result['word'][] = $character - 1;
				if ( $newlines >= 2 ) {
					$result['paragraph'][] = $character - 1;
				}
				if ( $sentence_before_whitespace ) {
					$result['sentence'][] = $character - 1;
				}
				$in_whitespace = false;
			}

			if ( in_array( $char, [ '.', '!', '?', '…' ], true ) ) {
				$sentence_pending = true;
			} elseif ( $sentence_pending && in_array( $char, [ '"', "'", '’', '”', ')', ']' ], true ) ) {
				// Closing punctuation keeps a completed sentence pending.
			} else {
				$sentence_pending = false;
			}
		}
		if ( $in_whitespace ) {
			$result['word'][] = $character;
			if ( $newlines >= 2 ) {
				$result['paragraph'][] = $character;
			}
			if ( $sentence_before_whitespace ) {
				$result['sentence'][] = $character;
			}
		}
		return $result;
	}

	/** Detect Markdown, prose, Fountain, and separator headings one line at a time. */
	private function collect_heading_candidates( string $text, array &$candidates ): void {
		$byte_start = 0;
		$position   = 0;
		$scene      = 0;
		$byte_length = strlen( $text );
		while ( $byte_start <= $byte_length ) {
			$newline = strpos( $text, "\n", $byte_start );
			$byte_end = false === $newline ? $byte_length : $newline;
			$line     = substr( $text, $byte_start, $byte_end - $byte_start );
			$candidate = null;
			if ( preg_match( '/^[ \t]{0,3}(#{1,6})[ \t]+(.+?)[ \t]*#*[ \t]*$/u', $line, $match ) ) {
				$label = $this->clean_label( (string) $match[2] );
				$candidate = [ 'type' => $this->heading_type( $label ), 'label' => $label, 'source' => 'detected_markdown_heading', 'level' => strlen( (string) $match[1] ) ];
			} elseif ( preg_match( '/^[ \t]*((?:(?:BOOK|CHAPTER|PART|SECTION|SCENE)[ \t]+(?:[IVXLCDM]+|\d+|[\p{L}][\p{L} -]*)(?:[.:\-][ \t]*.*)?|PROLOGUE|EPILOGUE|INTRODUCTION|FOREWORD|AFTERWORD))[ \t]*$/iu', $line, $match ) ) {
				$label = $this->clean_label( (string) $match[1] );
				$candidate = [ 'type' => $this->heading_type( $label ), 'label' => $label, 'source' => 'detected_heading' ];
			} elseif ( preg_match( '/^[ \t]*((?:INT|EXT|EST|INT\.?\/EXT|I\/E)\.?(?:[ \t].*)?)$/iu', $line, $match ) ) {
				$candidate = [ 'type' => 'scene', 'label' => $this->clean_label( (string) $match[1] ), 'source' => 'detected_fountain_heading' ];
			} elseif ( preg_match( '/^[ \t]*(?:\*{3,}|-{3,}|_{3,}|#{3,})[ \t]*$/u', $line ) ) {
				$candidate = [ 'type' => 'scene', 'label' => 'Scene break ' . ++$scene, 'source' => 'detected_separator' ];
			}
			if ( is_array( $candidate ) ) {
				$candidate['position'] = $position;
				$this->add_structural_candidate( $candidates, $candidate );
			}
			$position += mb_strlen( $line, 'UTF-8' ) + ( false === $newline ? 0 : 1 );
			if ( false === $newline ) {
				break;
			}
			$byte_start = $byte_end + 1;
		}
	}

	/** Keep the strongest structural candidate at one character position. */
	private function add_structural_candidate( array &$candidates, array $candidate ): void {
		$position = (int) ( $candidate['position'] ?? 0 );
		$current  = $candidates[ $position ] ?? null;
		if ( ! is_array( $current ) && count( $candidates ) >= self::MAX_STRUCTURAL_BOUNDARIES ) {
			return;
		}
		if ( is_array( $current ) && ! empty( $current['extractor_boundary'] ) && empty( $candidate['extractor_boundary'] ) ) {
			return;
		}
		if ( ! is_array( $current ) || $this->priority( (string) $candidate['type'] ) >= $this->priority( (string) $current['type'] ) ) {
			$candidates[ $position ] = $candidate;
		}
	}

	/** Choose the strongest semantic boundary, cascading to whitespace then hard cut. */
	private function choose_cut( array $candidates, int $start, int $capacity, int $minimum, int $ideal, int $hard ): array {
		foreach ( [ 'chapter', 'section', 'scene', 'paragraph', 'sentence', 'word' ] as $type ) {
			$position = $this->nearest_position_in_range( $candidates['positions'][ $type ], $minimum, $hard, $ideal );
			if ( null !== $position ) {
				if ( in_array( $type, [ 'chapter', 'section', 'scene' ], true ) ) {
					foreach ( $candidates['structural'] as $structural ) {
						if ( (int) $structural['position'] === $position && (string) $structural['type'] === $type ) {
							return $structural;
						}
					}
				}
				return [ 'position' => $position, 'type' => $type, 'label' => ucfirst( $type ) . ' break', 'source' => 'detected_' . ( 'word' === $type ? 'whitespace' : $type ) ];
			}
		}

		$whitespace = $this->nearest_position_in_range( $candidates['positions']['word'], max( $start + 1, $capacity ), $hard, $hard );
		if ( null !== $whitespace ) {
			return [ 'position' => $whitespace, 'type' => 'word', 'label' => 'Word break', 'source' => 'detected_whitespace' ];
		}

		return [
			'position' => $hard,
			'type'     => 'hard',
			'label'    => 'Hard character limit',
			'source'   => 'planner',
		];
	}

	/** Find the nearest sorted position to an ideal point inside inclusive bounds. */
	private function nearest_position_in_range( array $positions, int $minimum, int $maximum, int $ideal ): ?int {
		$first = $this->lower_bound( $positions, $minimum );
		$last  = $this->lower_bound( $positions, $maximum + 1 ) - 1;
		if ( $first > $last ) {
			return null;
		}
		$index = max( $first, min( $last, $this->lower_bound( $positions, $ideal ) ) );
		$best  = null;
		foreach ( [ $index - 1, $index ] as $candidate_index ) {
			if ( ! isset( $positions[ $candidate_index ] ) ) {
				continue;
			}
			$position = (int) $positions[ $candidate_index ];
			if ( $position < $minimum || $position > $maximum ) {
				continue;
			}
			if ( null === $best || abs( $position - $ideal ) < abs( $best - $ideal ) || ( abs( $position - $ideal ) === abs( $best - $ideal ) && $position < $best ) ) {
				$best = $position;
			}
		}
		return $best;
	}

	/** Earliest suffix start that the remaining bounded slots can cover. */
	private function earliest_suffix_start( array $positions, int $end, int $max_chars, int $slots ): ?int {
		$start = $end;
		for ( $slot = 0; $slot < $slots && $start > 0; $slot++ ) {
			$minimum = max( 0, $start - $max_chars );
			if ( 0 === $minimum ) {
				return 0;
			}
			$boundary = $this->first_position_in_range( $positions, $minimum, $start );
			if ( null === $boundary ) {
				return null;
			}
			$start = $boundary;
		}
		return $start;
	}

	/** Find the first sorted boundary at or after minimum and before maximum. */
	private function first_position_in_range( array $positions, int $minimum, int $maximum ): ?int {
		$index = $this->lower_bound( $positions, $minimum );
		if ( isset( $positions[ $index ] ) && (int) $positions[ $index ] < $maximum ) {
			return (int) $positions[ $index ];
		}
		return null;
	}

	/** Find the last sorted boundary after minimum and at or before maximum. */
	private function last_position_in_range( array $positions, int $minimum, int $maximum ): ?int {
		$index = $this->lower_bound( $positions, $maximum + 1 ) - 1;
		if ( isset( $positions[ $index ] ) && (int) $positions[ $index ] > $minimum ) {
			return (int) $positions[ $index ];
		}
		return null;
	}

	/** Return the first index whose sorted integer value is at least the needle. */
	private function lower_bound( array $positions, int $needle ): int {
		$low  = 0;
		$high = count( $positions );
		while ( $low < $high ) {
			$middle = intdiv( $low + $high, 2 );
			if ( (int) $positions[ $middle ] < $needle ) {
				$low = $middle + 1;
			} else {
				$high = $middle;
			}
		}
		return $low;
	}

	/** Find the nearest active chapter, section, or scene for a chunk label. */
	private function active_section( array $candidates, int $start ): array {
		$active = [];
		foreach ( $candidates as $candidate ) {
			$position = (int) ( $candidate['position'] ?? 0 );
			$type     = (string) ( $candidate['type'] ?? '' );
			if ( $position > $start ) {
				break;
			}
			if ( in_array( $type, [ 'chapter', 'section', 'scene' ], true ) ) {
				$active = $candidate;
			}
		}
		return $active;
	}

	/** Build one bounded context slice that never becomes part of primary coverage. */
	private function context_descriptor( string $text, array $candidates, int $start, int $end, bool $before ): array {
		if ( $end <= $start ) {
			return [
				'start'  => $end,
				'end'    => $end,
				'length' => 0,
				'hash'   => hash( 'sha256', '' ),
				'text'   => '',
			];
		}

		if ( $before && $start > 0 ) {
			$boundary = $this->first_position_in_range( $candidates, $start, $end );
			if ( null !== $boundary ) {
				$start = $boundary;
			}
		} elseif ( ! $before ) {
			$boundary = $this->last_position_in_range( $candidates, $start, $end );
			if ( null !== $boundary ) {
				$end = $boundary;
			}
		}

		$context = mb_substr( $text, $start, max( 0, $end - $start ), 'UTF-8' );
		return [
			'start'  => $start,
			'end'    => $end,
			'length' => $end - $start,
			'hash'   => hash( 'sha256', $context ),
			'text'   => $context,
		];
	}

	/** Resolve an extractor offset against prepared text, using anchors when stale. */
	private function remap_boundary( array $boundary, string $text, int $removed_prefix ): ?int {
		$length = mb_strlen( $text, 'UTF-8' );
		$raw    = isset( $boundary['offset'] ) && is_numeric( $boundary['offset'] ) ? (int) $boundary['offset'] : null;
		$direct = null !== $raw ? $raw - $removed_prefix : null;
		if ( null !== $direct && $direct >= 0 && $direct <= $length && $this->boundary_matches( $boundary, $text, $direct ) ) {
			return $direct;
		}

		$before = is_string( $boundary['anchor_before'] ?? null ) ? (string) $boundary['anchor_before'] : '';
		$after  = is_string( $boundary['anchor_after'] ?? null ) ? (string) $boundary['anchor_after'] : '';
		if ( '' !== $after ) {
			$offset = 0;
			$found  = [];
			while ( false !== ( $position = mb_strpos( $text, $after, $offset, 'UTF-8' ) ) ) {
				$candidate = (int) $position;
				if ( '' === $before || mb_substr( $text, max( 0, $candidate - mb_strlen( $before, 'UTF-8' ) ), mb_strlen( $before, 'UTF-8' ), 'UTF-8' ) === $before ) {
					$found[] = $candidate;
				}
				$offset = $candidate + max( 1, mb_strlen( $after, 'UTF-8' ) );
			}
			if ( ! empty( $found ) ) {
				if ( null !== $direct ) {
					usort( $found, static fn( int $left, int $right ): int => abs( $left - $direct ) <=> abs( $right - $direct ) ?: $left <=> $right );
				}
				return $found[0];
			}
		}

		$label = $this->clean_label( (string) ( $boundary['label'] ?? '' ) );
		if ( '' !== $label && ! str_starts_with( strtolower( $label ), 'scene break ' ) ) {
			$matches = [];
			if ( preg_match( '/^[ \t]*' . preg_quote( $label, '/' ) . '[ \t]*$/imu', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
				return $this->byte_to_character_offset( $text, (int) $matches[0][1] );
			}
		}

		if ( null !== $direct && $direct >= 0 && $direct <= $length && '' === $before && '' === $after ) {
			return $direct;
		}
		return null;
	}

	/** Whether an offset still has the extractor-provided anchor pair. */
	private function boundary_matches( array $boundary, string $text, int $position ): bool {
		$before = is_string( $boundary['anchor_before'] ?? null ) ? (string) $boundary['anchor_before'] : '';
		$after  = is_string( $boundary['anchor_after'] ?? null ) ? (string) $boundary['anchor_after'] : '';
		if ( '' === $before && '' === $after ) {
			return true;
		}
		$actual_before = mb_substr( $text, max( 0, $position - mb_strlen( $before, 'UTF-8' ) ), mb_strlen( $before, 'UTF-8' ), 'UTF-8' );
		$actual_after  = mb_substr( $text, $position, mb_strlen( $after, 'UTF-8' ), 'UTF-8' );
		if ( $actual_before !== $before || $actual_after !== $after ) {
			return false;
		}
		if ( ! empty( $boundary['anchor_hash'] ) ) {
			return hash_equals( (string) $boundary['anchor_hash'], hash( 'sha256', $actual_before . "\0" . $actual_after ) );
		}
		return true;
	}

	/** Normalize, bound, sort, and deduplicate remapped structural boundaries. */
	private function unique_boundaries( array $boundaries, int $length ): array {
		$result = [];
		foreach ( $boundaries as $boundary ) {
			$type = strtolower( (string) ( $boundary['type'] ?? 'section' ) );
			if ( ! in_array( $type, [ 'chapter', 'section', 'scene' ], true ) ) {
				$type = 'section';
			}
			$boundary['type']   = $type;
			$boundary['offset'] = max( 0, min( $length, (int) ( $boundary['offset'] ?? 0 ) ) );
			$boundary['label']  = $this->clean_label( (string) ( $boundary['label'] ?? ucfirst( $type ) ) );
			$key = $boundary['offset'] . '|' . $type . '|' . strtolower( $boundary['label'] );
			$result[ $key ] = $boundary;
		}
		$result = array_values( $result );
		usort( $result, static fn( array $left, array $right ): int => ( (int) $left['offset'] <=> (int) $right['offset'] ) ?: strcmp( (string) $left['type'], (string) $right['type'] ) );
		return $result;
	}

	private function heading_type( string $label ): string {
		if ( preg_match( '/^(?:book|chapter|part)\b/iu', $label ) ) {
			return 'chapter';
		}
		if ( preg_match( '/^scene\b/iu', $label ) ) {
			return 'scene';
		}
		return 'section';
	}

	private function clean_label( string $label ): string {
		$label = html_entity_decode( strip_tags( $label ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$label = preg_replace( '/\s+/u', ' ', trim( $label ) );
		return mb_substr( is_string( $label ) ? $label : '', 0, 180, 'UTF-8' );
	}

	private function priority( string $type ): int {
		return self::PRIORITIES[ $type ] ?? 0;
	}

	private function byte_to_character_offset( string $text, int $byte ): int {
		return mb_strlen( substr( $text, 0, max( 0, $byte ) ), 'UTF-8' );
	}

	private function too_many_parts_error( int $characters, int $max_chars, int $max_parts, int $required ): \WP_Error {
		return new \WP_Error(
			'worldgraph_story_chunk_too_many_parts',
			sprintf(
				/* translators: 1: required parts, 2: configured maximum parts. */
				__( 'This story requires at least %1$d chunks, exceeding the configured maximum of %2$d. Increase the chunk size or split the source file.', 'worldgraph' ),
				$required,
				$max_parts
			),
			[
				'characters'     => $characters,
				'max_chars'      => $max_chars,
				'max_parts'      => $max_parts,
				'required_parts' => $required,
			]
		);
	}
}
