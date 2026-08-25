<?php
/**
 * AI Context Builder — assembles Story Graph context for LLM queries.
 *
 * @package WorldGraph
 */

namespace WorldGraph\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Context Builder class.
 */
class AI_Context_Builder {

	/**
	 * Build context for a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Context data.
	 */
	public function build_post_context( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$context = [
			'post_id'    => $post_id,
			'post_type'  => $post->post_type,
			'post_title' => $post->post_title,
			'post_status' => $post->post_status,
			'content'    => $post->post_content,
			'excerpt'    => $post->post_excerpt,
		];

		// Add character data if post type is a World Graph Character.
		if ( 'worldgraph_character' === $post->post_type ) {
			$context = array_merge( $context, $this->build_character_context( $post_id ) );
		}

		// Add scene data if post type is a World Graph Scene.
		if ( 'worldgraph_scene' === $post->post_type ) {
			$context = array_merge( $context, $this->build_scene_context( $post_id ) );
		}

		// Add project context, scoped to the Project that owns this post.
		$context = array_merge( $context, $this->build_project_context( $post_id, $post->post_type ) );

		return $context;
	}

	/**
	 * Build character context.
	 *
	 * @param int $post_id Character post ID.
	 * @return array Character context.
	 */
	public function build_character_context( int $post_id ): array {
		$context = [
			'character_name' => \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'display_name' ) ?: get_the_title( $post_id ),
			'personality'    => wp_strip_all_tags( (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'personality' ) ),
			'motivation'     => wp_strip_all_tags( (string) \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'motivation' ) ),
		];

		// Get scenes this character appears in via the Story Graph relationship edge.
		$scenes = [];
		foreach ( \WorldGraph\Utils\get_relationships( $post_id, 'worldgraph_character', 'incoming' ) as $relationship ) {
			if ( 'worldgraph_scene' !== (string) ( $relationship['from_type'] ?? '' ) ) {
				continue;
			}
			$scene_id = absint( $relationship['from_id'] ?? 0 );
			if ( $scene_id ) {
				$scenes[ $scene_id ] = [
					'id'    => $scene_id,
					'title' => get_the_title( $scene_id ),
				];
			}
		}

		if ( $scenes ) {
			$context['appears_in_scenes'] = array_values( $scenes );
		}

		return $context;
	}

	/**
	 * Build scene context.
	 *
	 * @param int $post_id Scene post ID.
	 * @return array Scene context.
	 */
	public function build_scene_context( int $post_id ): array {
		$location_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'location' ) );
		$context     = [
			'scene_title'   => get_the_title( $post_id ),
			'setting'       => $location_id ? get_the_title( $location_id ) : '',
			'time_of_day'   => \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'time_of_day' ) ?: '',
			'tone'          => \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'emotional_tone' ) ?: '',
			'scene_content' => wp_strip_all_tags( (string) ( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'script_content' ) ?: get_post_field( 'post_content', $post_id ) ) ),
		];

		// Get characters linked to this scene via the Story Graph relationship edge.
		$characters = [];
		foreach ( \WorldGraph\Utils\get_relationships( $post_id, 'worldgraph_scene', 'outgoing' ) as $relationship ) {
			if ( 'worldgraph_character' !== (string) ( $relationship['to_type'] ?? '' ) ) {
				continue;
			}
			$character_id = absint( $relationship['to_id'] ?? 0 );
			if ( $character_id ) {
				$characters[ $character_id ] = [
					'id'   => $character_id,
					'name' => get_the_title( $character_id ),
				];
			}
		}
		if ( $characters ) {
			$context['characters'] = array_values( $characters );
		}

		return $context;
	}

	/**
	 * Resolve the Project that owns a Story Graph post, walking Scene and Episode edges.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return int Project post ID, or zero when unresolved.
	 */
	private function resolve_project_id( int $post_id, string $post_type ): int {
		if ( 'worldgraph_project' === $post_type ) {
			return $post_id;
		}

		if ( 'worldgraph_episode' === $post_type ) {
			$project_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'project' ) );
			if ( $project_id ) {
				return $project_id;
			}
			foreach ( \WorldGraph\Utils\get_relationships( $post_id, $post_type, 'outgoing' ) as $relationship ) {
				if ( 'worldgraph_project' === (string) ( $relationship['to_type'] ?? '' ) ) {
					return absint( $relationship['to_id'] ?? 0 );
				}
			}
			return 0;
		}

		if ( 'worldgraph_scene' === $post_type ) {
			$project_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'project' ) );
			if ( $project_id ) {
				return $project_id;
			}
			foreach ( \WorldGraph\Utils\get_relationships( $post_id, $post_type, 'outgoing' ) as $relationship ) {
				if ( 'worldgraph_project' === (string) ( $relationship['to_type'] ?? '' ) ) {
					return absint( $relationship['to_id'] ?? 0 );
				}
				if ( 'worldgraph_episode' === (string) ( $relationship['to_type'] ?? '' ) ) {
					$episode_id = absint( $relationship['to_id'] ?? 0 );
					if ( $episode_id ) {
						return $this->resolve_project_id( $episode_id, 'worldgraph_episode' );
					}
				}
			}
			$episode_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'episode' ) );
			if ( $episode_id ) {
				return $this->resolve_project_id( $episode_id, 'worldgraph_episode' );
			}
		}

		return 0;
	}

	/**
	 * Build project-level context, scoped to the Project that owns this post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return array Project context.
	 */
	private function build_project_context( int $post_id, string $post_type ): array {
		$project_id = $this->resolve_project_id( $post_id, $post_type );
		if ( ! $project_id || 'worldgraph_project' !== get_post_type( $project_id ) ) {
			return [];
		}

		$context = [
			'project_title'   => get_the_title( $project_id ),
			'project_logline' => wp_strip_all_tags( (string) \WorldGraph\Utils\worldgraph_get_field_value( $project_id, 'description' ) ),
		];

		$scene_ids = \WorldGraph\Utils\continuity_project_scene_ids( $project_id );
		if ( $scene_ids ) {
			$scenes = get_posts( [
				'post_type'      => 'worldgraph_scene',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'post__in'       => $scene_ids,
				'orderby'        => 'post__in',
			] );
			$context['all_scenes'] = array_map( static function ( $scene ) {
				return [
					'id'     => $scene->ID,
					'title'  => $scene->post_title,
					'status' => $scene->post_status,
				];
			}, $scenes );
		}

		$character_ids = [];
		$location_ids   = [];
		foreach ( $scene_ids as $scene_id ) {
			foreach ( \WorldGraph\Utils\get_relationships( $scene_id, 'worldgraph_scene', 'outgoing' ) as $relationship ) {
				$to_type = (string) ( $relationship['to_type'] ?? '' );
				$to_id   = absint( $relationship['to_id'] ?? 0 );
				if ( ! $to_id ) {
					continue;
				}
				if ( 'worldgraph_character' === $to_type ) {
					$character_ids[ $to_id ] = true;
				} elseif ( 'worldgraph_location' === $to_type ) {
					$location_ids[ $to_id ] = true;
				}
			}
		}

		if ( $character_ids ) {
			$context['all_characters'] = array_map( static function ( $id ) {
				return [ 'id' => $id, 'name' => get_the_title( $id ) ];
			}, array_keys( $character_ids ) );
		}

		if ( $location_ids ) {
			$context['all_locations'] = array_map( static function ( $id ) {
				return [ 'id' => $id, 'name' => get_the_title( $id ) ];
			}, array_keys( $location_ids ) );
		}

		return $context;
	}

	/**
	 * Build context formatted for LLM consumption.
	 *
	 * @param array $context Context data.
	 * @return string Formatted context string.
	 */
	public function build_context_for_llm( array $context ): string {
		if ( empty( $context ) ) {
			return '';
		}

		$output = "Story Graph Context:\n\n";

		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$output .= "## {$key}\n";
				$output .= $this->format_array_recursive( $value, 2 ) . "\n\n";
			} else {
				$output .= "{$key}: {$value}\n\n";
			}
		}

		return $output;
	}

	/**
	 * Recursively format an array.
	 *
	 * @param array  $array The array.
	 * @param int    $depth Current depth.
	 * @return string Formatted string.
	 */
	private function format_array_recursive( array $array, int $depth = 0 ): string {
		$output = '';
		$indent = str_repeat('  ', $depth);

		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$output .= "{$indent}{$key}:\n";
				$output .= $this->format_array_recursive( $value, $depth + 1 );
			} else {
				$output .= "{$indent}{$key}: {$value}\n";
			}
		}

		return $output;
	}
}
