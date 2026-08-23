<?php
/**
 * World Graph Studio markdown exporter.
 *
 * Exports live World Graph Studio project data into a screenplay-style Markdown document
 * that mirrors the example workflow export and stays aligned with the current
 * WordPress project state rather than a JSON snapshot.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Exporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export World Graph Studio data as Markdown screenplay text.
 */
class WorldGraph_Exporter {

	/**
	 * Export a live World Graph Studio project to a screenplay Markdown document.
	 *
	 * @param int|array $project_id_or_data Project ID or project data array.
	 * @return string Markdown document.
	 */
	public function export_project_markdown( $project_id_or_data = 0, array $project_data = [] ): string {
		$project = $this->resolve_project_data( $project_id_or_data, $project_data );
		if ( empty( $project['title'] ) ) {
			return "# World Graph Studio Export\n\n_There is no project data to export._\n";
		}

		$project_title = $this->clean_text( $project['title'] );
		$world_title   = $this->clean_text( $project['world'] ?? 'Story World' );
		$scenes        = $this->get_project_scenes( $project_id_or_data );
		$lines         = [];

		$lines[] = '# ' . $project_title;
		$lines[] = '';
		$lines[] = '## World Graph Studio Sample Export';
		$lines[] = '### Screenplay Format';
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '## FADE IN:';
		$lines[] = '';

		if ( empty( $scenes ) ) {
			$lines[] = '_No scenes found for this project yet._';
			$lines[] = '';
		} else {
			foreach ( $scenes as $scene ) {
				$lines[] = $this->format_scene_block( $scene );
				$lines[] = '';
			}
		}

		$lines[] = '---';
		$lines[] = '';
		$lines[] = '## SEQUENCE BREAKDOWN';
		foreach ( $scenes as $index => $scene ) {
			$scene_number = $scene['scene_number'] ?? ( $index + 1 );
			$scene_title  = $scene['title'] ?? 'Untitled Scene';
			$lines[] = '';
			$lines[] = '### Sequence ' . ( $index + 1 ) . ' - ' . $this->clean_text( $scene_title );
			$lines[] = '';
			$lines[] = '**Scene ' . $scene_number . '**';
			$lines[] = $this->clean_text( $scene_title );
			$lines[] = '';
		}
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '## PRODUCTION NOTES';
		$lines[] = '';
		$lines[] = '### Visual Style';
		$lines[] = 'World Graph Studio Project Export';
		$lines[] = '';
		$lines[] = '### World';
		$lines[] = $world_title;
		$lines[] = '';
		$lines[] = '## WORLD GRAPH STUDIO EXPORT METADATA';
		$lines[] = '';
		$lines[] = '```yaml';
		$lines[] = 'project: ' . $project_title;
		$lines[] = 'world: ' . $world_title;
		$lines[] = 'scenes: ' . count( $scenes );
		$lines[] = 'export_format:';
		$lines[] = '  - markdown';
		$lines[] = '  - screenplay';
		$lines[] = '```';
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '## Export Summary';
		$lines[] = '';
		$lines[] = 'This export was generated from the live World Graph Studio project data and reflects the current project state in WordPress.';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Export a live World Graph Studio project to a storyboard Markdown document.
	 *
	 * @param int|array $project_id_or_data Project ID or project data array.
	 * @param array     $project_data Optional fallback project data.
	 * @return string Markdown document.
	 */
	public function export_project_storyboard_markdown( $project_id_or_data = 0, array $project_data = [] ): string {
		$project = $this->resolve_project_data( $project_id_or_data, $project_data );
		if ( empty( $project['title'] ) ) {
			return "# World Graph Studio Storyboard Export\n\n_There is no project data to export._\n";
		}

		$project_title = $this->clean_text( $project['title'] );
		$world_title   = $this->clean_text( $project['world'] ?? 'Story World' );
		$scenes        = $this->get_project_scenes( $project_id_or_data );
		$shot_count    = 0;
		$lines         = [];

		$lines[] = '# ' . $project_title . ' Storyboard';
		$lines[] = '';
		$lines[] = '## Storyboard Export';
		$lines[] = '';
		$lines[] = 'Project: ' . $project_title;
		$lines[] = 'World: ' . $world_title;
		$lines[] = '';

		if ( empty( $scenes ) ) {
			$lines[] = '_No scenes found for this project yet._';
			$lines[] = '';
		} else {
			foreach ( $scenes as $index => $scene ) {
				$shots = $this->get_scene_shots( $scene );
				$shot_count += count( $shots );

				$lines[] = $this->format_storyboard_scene_block( $scene, $index + 1, $shots );
				$lines[] = '';
			}
		}

		$lines[] = '---';
		$lines[] = '';
		$lines[] = '## WORLD GRAPH STUDIO EXPORT METADATA';
		$lines[] = '';
		$lines[] = '```yaml';
		$lines[] = 'project: ' . $project_title;
		$lines[] = 'world: ' . $world_title;
		$lines[] = 'scenes: ' . count( $scenes );
		$lines[] = 'shots: ' . $shot_count;
		$lines[] = 'export_format:';
		$lines[] = '  - markdown';
		$lines[] = '  - storyboard';
		$lines[] = '```';
		$lines[] = '';
		$lines[] = 'This storyboard export was generated from the live World Graph Studio project data and reflects the current scenes and shots in WordPress.';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Resolve project title and related data from project ID or direct data.
	 *
	 * @param int|array $project_id_or_data Project ID or project data.
	 * @param array     $project_data Optional fallback data.
	 * @return array
	 */
	private function resolve_project_data( $project_id_or_data, array $project_data = [] ): array {
		if ( is_array( $project_id_or_data ) ) {
			return $project_id_or_data;
		}

		if ( ! function_exists( 'get_post' ) || ! function_exists( 'get_post_meta' ) ) {
			return $project_data;
		}

		$project_id = (int) $project_id_or_data;
		if ( ! $project_id ) {
			return $project_data;
		}

		$post = get_post( $project_id );
		if ( ! $post || 'worldgraph_project' !== $post->post_type ) {
			return $project_data;
		}

		$world_name = '';
		$world_rels = \WorldGraph\Utils\get_relationships( $project_id, 'worldgraph_project', 'outgoing' );
		foreach ( $world_rels as $rel ) {
			if ( 'worldgraph_world' === ( $rel['to_type'] ?? '' ) ) {
				$world = get_post( (int) $rel['to_id'] );
				if ( $world ) {
					$world_name = $world->post_title;
				}
			}
		}

		return [
			'title' => $post->post_title,
			'world' => $world_name,
			'project_id' => $project_id,
		];
	}

	/**
	 * Retrieve scenes for a project from live World Graph Studio relationships or direct data.
	 *
	 * @param int|array $project_id_or_data Project ID or data.
	 * @return array
	 */
	private function get_project_scenes( $project_id_or_data ): array {
		if ( is_array( $project_id_or_data ) && ! empty( $project_id_or_data['scenes'] ) && is_array( $project_id_or_data['scenes'] ) ) {
			return $project_id_or_data['scenes'];
		}

		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
			return [];
		}

		$project_id = is_numeric( $project_id_or_data ) ? (int) $project_id_or_data : 0;
		if ( ! $project_id ) {
			return [];
		}

		$scene_posts = get_posts( [
			'post_type'      => 'worldgraph_scene',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value_num',
			'meta_key'       => 'scene_number',
			'order'          => 'ASC',
		] );

		$scenes = [];
		foreach ( $scene_posts as $scene_post ) {
			$scene_meta = [
				'id' => $scene_post->ID,
				'title' => $scene_post->post_title,
				'summary' => \WorldGraph\Utils\worldgraph_get_field_value( $scene_post->ID, 'summary' ),
				'script_content' => \WorldGraph\Utils\worldgraph_get_field_value( $scene_post->ID, 'script_content' ),
				'location' => $this->get_scene_location_name( $scene_post->ID ),
				'time_of_day' => \WorldGraph\Utils\worldgraph_get_field_value( $scene_post->ID, 'time_of_day' ),
				'scene_number' => (int) \WorldGraph\Utils\worldgraph_get_field_value( $scene_post->ID, 'scene_number' ),
				'content' => $scene_post->post_content,
			];

			$scene_rels = \WorldGraph\Utils\get_relationships( $scene_post->ID, 'worldgraph_scene', 'outgoing' );
			foreach ( $scene_rels as $rel ) {
				if ( 'worldgraph_project' === ( $rel['to_type'] ?? '' ) ) {
					$scene_meta['project_id'] = (int) $rel['to_id'];
				}
			}

			if ( ! empty( $project_id ) && ( ( $scene_meta['project_id'] ?? 0 ) !== $project_id ) ) {
				continue;
			}

			$scenes[] = $scene_meta;
		}

		return $scenes;
	}

	/**
	 * Build one Markdown scene block.
	 *
	 * @param array $scene Scene data.
	 * @return string
	 */
	private function format_scene_block( array $scene ): string {
		$location = $this->clean_text( $scene['location'] ?? 'Location' );
		$time     = strtoupper( (string) ( $scene['time_of_day'] ?? 'DAY' ) );
		$title    = $this->clean_text( $scene['title'] ?? 'Untitled Scene' );
		$summary  = $this->clean_text( $scene['summary'] ?? $scene['content'] ?? '' );
		$script   = $this->clean_text( $scene['script_content'] ?? $summary );
		$lines    = [];

		$lines[] = '### ' . strtoupper( $location ) . ' - ' . $time;
		$lines[] = '';
		$lines[] = $summary ?: $script;
		$lines[] = '';
		if ( $script && $script !== $summary ) {
			$lines[] = $script;
			$lines[] = '';
		}

		$character_names = $this->get_scene_character_names( $scene['id'] ?? 0 );
		if ( ! empty( $character_names ) ) {
			foreach ( $character_names as $character_name ) {
				$lines[] = '**' . strtoupper( $this->clean_text( $character_name ) ) . '**';
				$lines[] = ''; 
			}
		}

		$lines[] = '---';
		$lines[] = '';
		$lines[] = '## SHOT LIST';
		$shot_lines = $this->get_scene_shot_list( $scene['id'] ?? 0 );
		if ( empty( $shot_lines ) ) {
			$lines[] = '_No shot list available yet._';
		} else {
			foreach ( $shot_lines as $shot_line ) {
				$lines[] = $shot_line;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get location name for a scene.
	 *
	 * @param int $scene_id Scene ID.
	 * @return string
	 */
	private function get_scene_location_name( int $scene_id ): string {
		if ( ! function_exists( 'get_post' ) ) {
			return 'Location';
		}

		$relationships = \WorldGraph\Utils\get_relationships( $scene_id, 'worldgraph_scene', 'outgoing' );
		foreach ( $relationships as $rel ) {
			if ( 'worldgraph_location' === ( $rel['to_type'] ?? '' ) ) {
				$post = get_post( (int) $rel['to_id'] );
				if ( $post ) {
					return $post->post_title;
				}
			}
		}

		return 'Location';
	}

	/**
	 * Get character names linked to a scene.
	 *
	 * @param int $scene_id Scene ID.
	 * @return array
	 */
	private function get_scene_character_names( int $scene_id ): array {
		if ( ! function_exists( 'get_post' ) ) {
			return [];
		}

		$characters = [];
		$relationships = \WorldGraph\Utils\get_relationships( $scene_id, 'worldgraph_scene', 'outgoing' );
		foreach ( $relationships as $rel ) {
			if ( 'worldgraph_character' !== ( $rel['to_type'] ?? '' ) ) {
				continue;
			}
			$post = get_post( (int) $rel['to_id'] );
			if ( $post ) {
				$characters[] = $post->post_title;
			}
		}

		return array_values( array_unique( $characters ) );
	}

	/**
	 * Get shot list for a scene.
	 *
	 * @param int $scene_id Scene ID.
	 * @return array
	 */
	private function get_scene_shot_list( int $scene_id ): array {
		$shot_lines = [];
		foreach ( $this->get_live_scene_shots( $scene_id ) as $shot ) {
			$shot_lines[] = '### ' . $this->format_shot_heading( $shot );
		}

		return $shot_lines;
	}

	/**
	 * Build one storyboard scene block.
	 *
	 * @param array $scene Scene data.
	 * @param int   $fallback_number Fallback scene number.
	 * @param array $shots Shots linked to the scene.
	 * @return string
	 */
	private function format_storyboard_scene_block( array $scene, int $fallback_number, array $shots ): string {
		$scene_number = $scene['scene_number'] ?? $fallback_number;
		$title        = $this->clean_text( $scene['title'] ?? 'Untitled Scene' );
		$location     = $this->clean_text( $scene['location'] ?? 'Location' );
		$time         = strtoupper( $this->clean_text( $scene['time_of_day'] ?? 'DAY' ) );
		$summary      = $this->clean_text( $scene['summary'] ?? $scene['content'] ?? '' );
		$lines        = [];

		$lines[] = '## Scene ' . $scene_number . ': ' . $title;
		$lines[] = '';
		$lines[] = '**Location:** ' . $location;
		$lines[] = '**Time of Day:** ' . $time;
		if ( $summary ) {
			$lines[] = '**Scene Summary:** ' . $summary;
		}
		$lines[] = '';

		if ( empty( $shots ) ) {
			$lines[] = '_No shots found for this scene yet._';
			return implode( "\n", $lines );
		}

		foreach ( $shots as $shot ) {
			$lines[] = $this->format_storyboard_shot_block( $shot );
			$lines[] = '';
		}

		return rtrim( implode( "\n", $lines ) );
	}

	/**
	 * Build one storyboard shot block.
	 *
	 * @param array $shot Shot data.
	 * @return string
	 */
	private function format_storyboard_shot_block( array $shot ): string {
		$description = $this->clean_text( $shot['shot_description'] ?? $shot['description'] ?? $shot['content'] ?? '' );
		$notes       = $this->clean_text( $shot['editorial_notes'] ?? '' );
		$details     = [];
		$lines       = [];

		foreach ( [
			'shot_type'    => 'Shot Type',
			'camera_angle' => 'Camera Angle',
			'lens'         => 'Lens',
			'duration'     => 'Duration',
			'slate_id'     => 'Slate',
		] as $key => $label ) {
			$value = $this->clean_text( $shot[ $key ] ?? '' );
			if ( $value ) {
				$details[] = $label . ': ' . $value;
			}
		}

		$lines[] = '### ' . $this->format_shot_heading( $shot );
		if ( ! empty( $details ) ) {
			$lines[] = implode( ' | ', $details );
		}
		if ( $description ) {
			$lines[] = '';
			$lines[] = $description;
		}
		if ( $notes ) {
			$lines[] = '';
			$lines[] = '**Editorial Notes:** ' . $notes;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Retrieve shots for a scene from direct data or live World Graph Studio relationships.
	 *
	 * @param array $scene Scene data.
	 * @return array
	 */
	private function get_scene_shots( array $scene ): array {
		if ( ! empty( $scene['shots'] ) && is_array( $scene['shots'] ) ) {
			$shots = $scene['shots'];
		} else {
			$shots = $this->get_live_scene_shots( (int) ( $scene['id'] ?? 0 ) );
		}

		usort( $shots, function ( array $a, array $b ): int {
			$a_order = (int) ( $a['menu_order'] ?? $a['shot_number'] ?? 0 );
			$b_order = (int) ( $b['menu_order'] ?? $b['shot_number'] ?? 0 );
			return $a_order <=> $b_order;
		} );

		return $shots;
	}

	private function get_live_scene_shots( int $scene_id ): array {
		if ( ! $scene_id || ! function_exists( 'get_posts' ) ) {
			return [];
		}

		$shots = get_posts( [
			'post_type'      => 'worldgraph_shot',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value_num',
			'meta_key'       => 'shot_number',
			'order'          => 'ASC',
		] );

		$scene_shots = [];
		foreach ( $shots as $shot ) {
			$relationship_found = false;
			foreach ( \WorldGraph\Utils\get_relationships( $shot->ID, 'worldgraph_shot', 'outgoing' ) as $rel ) {
				if ( $scene_id === (int) ( $rel['to_id'] ?? 0 ) && 'worldgraph_scene' === ( $rel['to_type'] ?? '' ) ) {
					$relationship_found = true;
					break;
				}
			}

			if ( ! $relationship_found ) {
				continue;
			}

			$scene_shots[] = [
				'id'               => $shot->ID,
				'title'            => \WorldGraph\Utils\worldgraph_get_shot_display_name( $shot->ID ),
				'shot_number'      => (int) \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'shot_number' ),
				'shot_name'        => \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'shot_name' ),
				'shot_type'        => \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'shot_type' ),
				'camera_angle'     => \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'camera_angle' ),
				'lens'             => \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'lens' ),
				'duration'         => \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'duration' ),
				'slate_id'         => \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'slate_id' ),
				'shot_description' => \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'shot_description' ) ?: $shot->post_content,
				'editorial_notes'  => \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'editorial_notes' ),
				'menu_order'       => (int) $shot->menu_order,
			];
		}

		return $scene_shots;
	}

	/**
	 * Format a shot heading for export.
	 *
	 * @param array $shot Shot data.
	 * @return string
	 */
	private function format_shot_heading( array $shot ): string {
		$title = $this->clean_text( $shot['title'] ?? $shot['shot_name'] ?? '' );
		$type  = $this->clean_text( $shot['shot_type'] ?? '' );
		$label = $title ?: 'Shot ' . ( $shot['shot_number'] ?? '' );

		if ( $type ) {
			$label .= ' - ' . ucwords( str_replace( '_', ' ', $type ) );
		}

		return trim( $label );
	}


	/**
	 * Normalize export text for Markdown output.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	private function clean_text( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( [ $this, 'clean_text' ], $value ) ) );
		}

		$value = (string) $value;
		$value = wp_strip_all_tags( $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value );
		return $value;
	}
}
