<?php
/**
 * Tolerant structural mapping between VideoDraft's live project blob and the
 * World Graph Studio JSON interchange subset.
 *
 * @package WorldGraphVideoDraft
 */

namespace WorldGraphVideoDraft;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** VideoDraft project mapper. */
class Mapper {

	/** Convert a VideoDraft raw project response to World Graph Studio JSON. */
	public static function from_videodraft( array $response, string $remote_project_id, string $scope = 'default', array $identity_map = [] ): array {
		$project = self::unwrap_project( $response );
		$remote_project_id = self::text( $remote_project_id ?: ( $project['id'] ?? $project['project_id'] ?? '' ) );
		$project_key = self::external_id( $remote_project_id, 'project', $remote_project_id ?: 'project', 0, $scope, $identity_map );
		$world_key   = self::external_id( $remote_project_id, 'world', 'world', 0, $scope, $identity_map );

		$visual_assets = self::visual_assets( $project );
		$characters = [];
		$locations  = [];
		$props      = [];
		$asset_ids  = [];
		foreach ( $visual_assets as $index => $asset ) {
			$type = self::asset_kind( self::text( $asset['type'] ?? $asset['category'] ?? $asset['kind'] ?? 'prop' ) );
			$id   = self::external_id( $remote_project_id, $type, $asset['id'] ?? $asset['asset_id'] ?? $asset['name'] ?? $index, $index, $scope, $identity_map );
			foreach ( [ $asset['id'] ?? null, $asset['asset_id'] ?? null, $asset['name'] ?? null ] as $lookup ) {
				if ( is_scalar( $lookup ) && '' !== (string) $lookup ) {
					$asset_ids[ (string) $lookup ] = $id;
				}
			}
			$item = [
				'id'          => $id,
				'name'        => self::text( $asset['name'] ?? $asset['title'] ?? ucfirst( $type ?: 'Asset' ) ),
				'description' => self::text( $asset['description'] ?? $asset['prompt'] ?? '' ),
			];
			if ( 'character' === $type ) {
				$characters[] = $item;
			} elseif ( 'location' === $type ) {
				$locations[] = $item;
			} else {
				$props[] = $item;
			}
		}

		$storyboard = is_array( $project['storyboard'] ?? null ) ? $project['storyboard'] : [];
		$remote_scenes = self::array_items( $storyboard['scenes'] ?? $project['scenes'] ?? [] );
		$scenes = [];
		$shots  = [];
		$frames = [];
		foreach ( $remote_scenes as $scene_index => $scene ) {
			if ( ! is_array( $scene ) ) {
				continue;
			}
			$scene_key = $scene['id'] ?? $scene['scene_id'] ?? $scene_index + 1;
			$scene_id = self::external_id( $remote_project_id, 'scene', $scene_key, $scene_index, $scope, $identity_map );
			$scene_item = [
				'id'             => $scene_id,
				'title'          => self::text( $scene['title'] ?? $scene['name'] ?? $scene['heading'] ?? sprintf( 'Scene %d', $scene_index + 1 ) ),
				'summary'        => self::text( $scene['summary'] ?? $scene['description'] ?? '' ),
				'script_content' => self::text( $scene['script'] ?? $scene['script_content'] ?? '' ),
				'dialogue'       => self::dialogue( $scene['dialogue'] ?? $scene['lines'] ?? [] ),
				'location'       => self::single_reference( $scene['location'] ?? $scene['location_id'] ?? '', $asset_ids ),
				'characters'     => self::references( $scene['characters'] ?? $scene['character_ids'] ?? [], $asset_ids ),
				'props'          => self::references( $scene['props'] ?? $scene['prop_ids'] ?? [], $asset_ids ),
			];
			$scenes[] = $scene_item;

			$remote_shots = self::array_items( $scene['shots'] ?? $scene['shot_cards'] ?? $scene['shotCards'] ?? [] );
			foreach ( $remote_shots as $shot_index => $shot ) {
				if ( ! is_array( $shot ) ) {
					$shot = [ 'description' => self::text( $shot ) ];
				}
				$shot_key = $shot['id'] ?? $shot['shot_id'] ?? ( $scene_index + 1 ) . '-' . ( $shot_index + 1 );
				$shot_id = self::external_id( $remote_project_id, 'shot', $shot_key, $shot_index, $scope, $identity_map );
				$description = self::text( $shot['description'] ?? $shot['prompt'] ?? $shot['action'] ?? '' );
				$shots[] = [
					'id'          => $shot_id,
					'scene'       => $scene_id,
					'title'       => self::text( $shot['title'] ?? $shot['name'] ?? sprintf( 'Shot %d', $shot_index + 1 ) ),
					'description' => $description,
					'type'        => self::text( $shot['shot_type'] ?? $shot['shotType'] ?? $shot['type'] ?? '' ),
				];
				if ( '' !== $description ) {
					$frames[] = [
						'id'          => self::external_id( $remote_project_id, 'frame', $shot_key, $shot_index, $scope, $identity_map ),
						'shot'        => $shot_id,
						'description' => $description,
					];
				}
			}
		}

		$title = self::text( $project['title'] ?? $project['name'] ?? 'VideoDraft Project' );
		$description = self::text( $project['description'] ?? $project['summary'] ?? '' );

		return [
			'project' => [ 'id' => $project_key, 'title' => $title, 'description' => $description ],
			'world'   => [ 'id' => $world_key, 'name' => $title . ' World', 'description' => $description ],
			'characters'  => $characters,
			'locations'   => $locations,
			'props'       => $props,
			'scenes'      => $scenes,
			'shots'       => $shots,
			'sounds'      => [],
			'storyboards' => $frames,
			'sequence'    => [
				'id'    => self::external_id( $remote_project_id, 'sequence', 'main', 0, $scope, $identity_map ),
				'title' => 'VideoDraft Storyboard',
				'order' => array_column( $scenes, 'id' ),
			],
		];
	}

	/** Serialize one local World Graph Studio project to VideoDraft's editable subset. */
	public static function from_worldgraph( int $project_id ): array {
		$project = get_post( $project_id );
		if ( ! $project instanceof \WP_Post || 'worldgraph_project' !== $project->post_type ) {
			return [];
		}

		$world = self::related_post( $project_id, 'worldgraph_project', 'worldgraph_world' );
		$visual_assets = [];
		if ( $world ) {
			foreach ( self::world_assets( $world->ID ) as $post ) {
				$type = $post->post_type;
				$kind_map = [ 'worldgraph_character' => 'character', 'worldgraph_location' => 'location', 'worldgraph_prop' => 'object' ];
				$description_field = 'worldgraph_character' === $type ? 'biography' : 'description';
				$visual_assets[] = [
					'id'          => self::local_external_id( $post ),
					'type'        => $kind_map[ $type ],
					'name'        => $post->post_title,
					'description' => self::field_text( $post->ID, $description_field, $post->post_content ),
				];
			}
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
		foreach ( $scene_posts as $scene ) {
			if ( ! self::belongs_to_project( $scene->ID, $project_id ) ) {
				continue;
			}
			$description = self::field_text( $scene->ID, 'summary', $scene->post_content );
			$script_text = self::field_text( $scene->ID, 'script_content', '' );
			$shot_items  = [];
			foreach ( self::shots_for_scene( $scene->ID ) as $shot ) {
				$shot_items[] = [
					'id'          => self::local_external_id( $shot ),
					'title'       => $shot->post_title,
					'description' => self::field_text( $shot->ID, 'shot_description', $shot->post_content ),
					'shot_type'   => self::field_text( $shot->ID, 'shot_type', '' ),
				];
			}
			$references = self::scene_references( $scene->ID );
			$scenes[] = [
				'id'          => self::local_external_id( $scene ),
				'title'       => $scene->post_title,
				'description' => $description,
				'script'      => $script_text,
				'shots'       => $shot_items,
				'characters'  => $references['characters'],
				'props'       => $references['props'],
				'location'    => $references['location'],
				'dialogue'    => self::field_dialogue( $scene->ID ),
			];
		}

		return [
			'title'         => $project->post_title,
			'description'   => self::field_text( $project_id, 'description', $project->post_content ),
			'storyboard'    => [ 'scenes' => $scenes ],
			'visual_assets' => $visual_assets,
		];
	}

	/**
	 * Preserve provider-owned array members while replacing WGS-owned fields.
	 *
	 * @param array  $response          Raw VideoDraft project response.
	 * @param array  $payload           World Graph Studio editable payload.
	 * @param string $remote_project_id VideoDraft project ID.
	 * @param string $scope             Connection-specific identity scope.
	 * @param array  $identity_map      Remote-to-local identity overrides.
	 * @return array
	 */
	public static function merge_worldgraph_payload( array $response, array $payload, string $remote_project_id, string $scope = 'default', array $identity_map = [] ): array {
		$project = self::unwrap_project( $response );
		$merged  = $payload;

		// Aggregate scripts and media URLs belong to VideoDraft and are intentionally
		// omitted from the partial top-level update.
		unset( $merged['script'], $merged['image_url'], $merged['imageUrl'], $merged['thumbnail_url'] );

		$remote_assets = self::visual_assets( $project );
		$local_assets  = self::array_items( $payload['visual_assets'] ?? [] );
		$asset_ids     = [];
		$merged['visual_assets'] = self::merge_visual_assets(
			$remote_assets,
			$local_assets,
			$remote_project_id,
			$scope,
			$identity_map,
			$asset_ids
		);

		$remote_storyboard = is_array( $project['storyboard'] ?? null ) ? $project['storyboard'] : [];
		$local_storyboard  = is_array( $payload['storyboard'] ?? null ) ? $payload['storyboard'] : [];
		$remote_scenes     = self::array_items( $remote_storyboard['scenes'] ?? $project['scenes'] ?? [] );
		$local_scenes      = self::translate_scene_references( self::array_items( $local_storyboard['scenes'] ?? [] ), $asset_ids );

		$remote_storyboard['scenes'] = self::merge_scenes( $remote_scenes, $local_scenes, $remote_project_id, $scope, $identity_map );
		$merged['storyboard']         = array_replace( $remote_storyboard, $local_storyboard, [ 'scenes' => $remote_storyboard['scenes'] ] );

		return $merged;
	}

	/** Capture remote IDs emitted during push so a later pull updates the same graph. */
	public static function identity_map_from_worldgraph( int $project_id, array $data = [] ): array {
		$project = get_post( $project_id );
		if ( ! $project instanceof \WP_Post || 'worldgraph_project' !== $project->post_type ) {
			return [];
		}
		$data = $data ?: self::from_worldgraph( $project_id );
		$map  = [ 'project' => [ '*' => self::local_external_id( $project ) ] ];
		$world = self::related_post( $project_id, 'worldgraph_project', 'worldgraph_world' );
		if ( $world ) {
			$map['world']['*'] = self::local_external_id( $world );
		}
		foreach ( (array) ( $data['visual_assets'] ?? [] ) as $asset ) {
			$id = is_array( $asset ) ? (string) ( $asset['id'] ?? '' ) : '';
			if ( '' !== $id ) {
				$map[ self::asset_kind( (string) ( $asset['type'] ?? 'prop' ) ) ][ $id ] = $id;
			}
		}
		foreach ( (array) ( $data['storyboard']['scenes'] ?? [] ) as $scene ) {
			$scene_id = is_array( $scene ) ? (string) ( $scene['id'] ?? '' ) : '';
			if ( '' !== $scene_id ) {
				$map['scene'][ $scene_id ] = $scene_id;
			}
			foreach ( (array) ( $scene['shots'] ?? [] ) as $shot ) {
				$shot_id = is_array( $shot ) ? (string) ( $shot['id'] ?? '' ) : '';
				if ( '' !== $shot_id ) {
					$map['shot'][ $shot_id ] = $shot_id;
				}
			}
		}
		return $map;
	}

	/** Unwrap the shapes returned by get_project and MCP content decoding. */
	public static function unwrap_project( array $response ): array {
		if ( is_array( $response['project'] ?? null ) ) {
			return $response['project'];
		}
		if ( is_array( $response['data']['project'] ?? null ) ) {
			return $response['data']['project'];
		}
		if ( is_array( $response['data'] ?? null ) ) {
			return $response['data'];
		}

		return $response;
	}

	/** Normalize exactly the remote fields that a push can replace. */
	public static function remote_editable_projection( array $response ): array {
		$project = self::unwrap_project( $response );
		$assets  = [];
		foreach ( self::visual_assets( $project ) as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$assets[] = array_filter( [
				'id'          => self::text( $asset['id'] ?? $asset['asset_id'] ?? $asset['name'] ?? '' ),
				'type'        => self::asset_kind( self::text( $asset['type'] ?? $asset['category'] ?? $asset['kind'] ?? 'prop' ) ),
				'name'        => self::text( $asset['name'] ?? $asset['title'] ?? '' ),
				'description' => self::text( $asset['description'] ?? $asset['prompt'] ?? '' ),
			] );
		}

		$storyboard = is_array( $project['storyboard'] ?? null ) ? $project['storyboard'] : [];
		$scenes = [];
		foreach ( self::array_items( $storyboard['scenes'] ?? $project['scenes'] ?? [] ) as $scene ) {
			if ( ! is_array( $scene ) ) {
				continue;
			}
			$shots = [];
			foreach ( self::array_items( $scene['shots'] ?? $scene['shot_cards'] ?? $scene['shotCards'] ?? [] ) as $shot ) {
				if ( ! is_array( $shot ) ) {
					continue;
				}
				$shots[] = array_filter( [
					'id'          => self::text( $shot['id'] ?? $shot['shot_id'] ?? '' ),
					'title'       => self::text( $shot['title'] ?? $shot['name'] ?? '' ),
					'description' => self::text( $shot['description'] ?? $shot['prompt'] ?? $shot['action'] ?? '' ),
					'shot_type'   => self::text( $shot['shot_type'] ?? $shot['shotType'] ?? $shot['type'] ?? '' ),
				] );
			}
			$scenes[] = array_filter( [
				'id'          => self::text( $scene['id'] ?? $scene['scene_id'] ?? '' ),
				'title'       => self::text( $scene['title'] ?? $scene['name'] ?? $scene['heading'] ?? '' ),
				'description' => self::text( $scene['description'] ?? $scene['summary'] ?? '' ),
				'script'      => self::text( $scene['script'] ?? $scene['script_content'] ?? '' ),
				'shots'       => $shots,
				'characters'  => self::raw_references( $scene['characters'] ?? $scene['character_ids'] ?? [] ),
				'props'       => self::raw_references( $scene['props'] ?? $scene['prop_ids'] ?? [] ),
				'location'    => self::raw_reference( $scene['location'] ?? $scene['location_id'] ?? '' ),
				'dialogue'    => self::dialogue( $scene['dialogue'] ?? $scene['lines'] ?? [] ),
			] );
		}

		return [
			'title'         => self::text( $project['title'] ?? $project['name'] ?? '' ),
			'description'   => self::text( $project['description'] ?? $project['summary'] ?? '' ),
			'storyboard'    => [ 'scenes' => $scenes ],
			'visual_assets' => $assets,
		];
	}

	/** Stable hash used for conflict detection. */
	public static function hash_payload( array $payload ): string {
		$payload = self::sort_recursive( $payload );
		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/** Stable, Connection-scoped external ID for imported objects. */
	private static function external_id( string $remote_project_id, string $kind, $candidate, int $index, string $scope, array $identity_map ): string {
		$candidate_key = is_scalar( $candidate ) ? (string) $candidate : '';
		$mapped = $identity_map[ $kind ][ $candidate_key ] ?? $identity_map[ $kind ]['*'] ?? '';
		if ( is_scalar( $mapped ) && '' !== (string) $mapped ) {
			return sanitize_text_field( (string) $mapped );
		}
		$scope  = substr( sanitize_title( $scope ), 0, 20 ) ?: 'default';
		$remote = substr( sha1( $remote_project_id ), 0, 16 );
		$value  = substr( sanitize_title( is_scalar( $candidate ) ? (string) $candidate : '' ), 0, 40 );
		$value  = $value ?: (string) ( $index + 1 );
		return sprintf( 'vd-%s-%s-%s-%s', $scope, $remote, sanitize_key( $kind ), $value );
	}

	/** Collapse VideoDraft's visual-asset aliases to canonical entity kinds. */
	private static function asset_kind( string $type ): string {
		$type = sanitize_key( $type );
		if ( in_array( $type, [ 'character', 'person', 'cast', 'actor' ], true ) ) {
			return 'character';
		}
		if ( in_array( $type, [ 'location', 'place', 'environment', 'set' ], true ) ) {
			return 'location';
		}
		return 'prop';
	}

	/** Normalize an optional array or object-map to a list. */
	private static function array_items( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}
		return is_scalar( $value ) && '' !== (string) $value ? [ $value ] : [];
	}

	/** Resolve local Scene relationships to VideoDraft visual-asset IDs. */
	private static function scene_references( int $scene_id ): array {
		$result = [ 'characters' => [], 'props' => [], 'location' => '' ];
		foreach ( \WorldGraph\Utils\get_relationships( $scene_id, 'worldgraph_scene', 'outgoing' ) as $relationship ) {
			$post = get_post( absint( $relationship['to_id'] ?? 0 ) );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( 'worldgraph_character' === ( $relationship['to_type'] ?? '' ) ) {
				$result['characters'][] = self::local_external_id( $post );
			} elseif ( 'worldgraph_prop' === ( $relationship['to_type'] ?? '' ) ) {
				$result['props'][] = self::local_external_id( $post );
			} elseif ( 'worldgraph_location' === ( $relationship['to_type'] ?? '' ) ) {
				$result['location'] = self::local_external_id( $post );
			}
		}
		$result['characters'] = array_values( array_unique( $result['characters'] ) );
		$result['props']      = array_values( array_unique( $result['props'] ) );
		return $result;
	}

	/** Normalize local structured dialogue for VideoDraft. */
	private static function field_dialogue( int $scene_id ): array {
		$value = function_exists( '\\WorldGraph\\Utils\\worldgraph_get_field_value' ) ? \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'dialogue' ) : get_post_meta( $scene_id, 'dialogue', true );
		$lines = [];
		foreach ( (array) $value as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$lines[] = array_filter( [
				'speaker'     => self::text( $line['speaker'] ?? '' ),
				'text'        => self::text( $line['line'] ?? $line['text'] ?? '' ),
				'description' => self::text( $line['description'] ?? '' ),
			] );
		}
		return $lines;
	}

	/** Flatten common VideoDraft visual-asset layouts. */
	private static function visual_assets( array $project ): array {
		$value = $project['visual_assets'] ?? $project['visualAssets'] ?? [];
		if ( ! is_array( $value ) ) {
			return [];
		}
		if ( self::is_list( $value ) ) {
			return $value;
		}
		$items = [];
		foreach ( $value as $type => $typed_items ) {
			foreach ( self::array_items( $typed_items ) as $item ) {
				if ( is_array( $item ) && empty( $item['type'] ) ) {
					$item['type'] = $type;
				}
				$items[] = $item;
			}
		}
		return $items;
	}

	/** Merge local asset fields into matching remote asset records. */
	private static function merge_visual_assets( array $remote_assets, array $local_assets, string $remote_project_id, string $scope, array $identity_map, array &$asset_ids ): array {
		$remaining = array_values( $remote_assets );
		$merged    = [];

		foreach ( $local_assets as $local_asset ) {
			if ( ! is_array( $local_asset ) ) {
				continue;
			}

			$local_id    = self::text( $local_asset['id'] ?? '' );
			$matched_key = null;
			foreach ( $remaining as $remote_index => $remote_asset ) {
				if ( ! is_array( $remote_asset ) ) {
					continue;
				}
				$candidate = $remote_asset['id'] ?? $remote_asset['asset_id'] ?? $remote_asset['name'] ?? $remote_index;
				$kind      = self::asset_kind( self::text( $remote_asset['type'] ?? $remote_asset['category'] ?? $remote_asset['kind'] ?? 'prop' ) );
				if ( self::identity_matches( $local_id, $remote_project_id, $kind, $candidate, $remote_index, $scope, $identity_map ) ) {
					$matched_key = $remote_index;
					break;
				}
			}

			if ( null === $matched_key ) {
				$merged[] = $local_asset;
				if ( '' !== $local_id ) {
					$asset_ids[ $local_id ] = $local_id;
				}
				continue;
			}

			$remote_asset = $remaining[ $matched_key ];
			$merged_asset = self::overlay_remote_record( $remote_asset, $local_asset );
			$remote_type  = self::text( $remote_asset['type'] ?? $remote_asset['category'] ?? $remote_asset['kind'] ?? '' );
			if ( '' !== $remote_type ) {
				$merged_asset['type'] = $remote_type;
			}
			$remote_id = self::text( $remote_asset['id'] ?? $remote_asset['asset_id'] ?? $remote_asset['name'] ?? '' );
			if ( '' !== $local_id && '' !== $remote_id ) {
				$asset_ids[ $local_id ] = $remote_id;
			}
			$merged[] = $merged_asset;
			unset( $remaining[ $matched_key ] );
		}

		foreach ( $remaining as $remote_asset ) {
			$merged[] = $remote_asset;
		}

		return $merged;
	}

	/** Merge local Scene fields and nested Shots into matching remote records. */
	private static function merge_scenes( array $remote_scenes, array $local_scenes, string $remote_project_id, string $scope, array $identity_map ): array {
		$remaining = array_values( $remote_scenes );
		$merged    = [];

		foreach ( $local_scenes as $local_scene ) {
			if ( ! is_array( $local_scene ) ) {
				continue;
			}

			$local_id    = self::text( $local_scene['id'] ?? '' );
			$matched_key = null;
			foreach ( $remaining as $remote_index => $remote_scene ) {
				if ( ! is_array( $remote_scene ) ) {
					continue;
				}
				$candidate = $remote_scene['id'] ?? $remote_scene['scene_id'] ?? $remote_index + 1;
				if ( self::identity_matches( $local_id, $remote_project_id, 'scene', $candidate, $remote_index, $scope, $identity_map ) ) {
					$matched_key = $remote_index;
					break;
				}
			}

			if ( null === $matched_key ) {
				$merged[] = $local_scene;
				continue;
			}

			$remote_scene = $remaining[ $matched_key ];
			$remote_shots  = self::array_items( $remote_scene['shots'] ?? $remote_scene['shot_cards'] ?? $remote_scene['shotCards'] ?? [] );
			$local_shots   = self::array_items( $local_scene['shots'] ?? [] );
			$merged_scene  = self::overlay_remote_record( $remote_scene, $local_scene );
			$merged_scene['shots'] = self::merge_shots( $remote_shots, $local_shots, $remote_project_id, $matched_key, $scope, $identity_map );
			unset( $merged_scene['shot_cards'], $merged_scene['shotCards'] );

			$merged[] = $merged_scene;
			unset( $remaining[ $matched_key ] );
		}

		foreach ( $remaining as $remote_scene ) {
			$merged[] = $remote_scene;
		}

		return $merged;
	}

	/** Merge local Shot fields into matching remote records. */
	private static function merge_shots( array $remote_shots, array $local_shots, string $remote_project_id, int $scene_index, string $scope, array $identity_map ): array {
		$remaining = array_values( $remote_shots );
		$merged    = [];

		foreach ( $local_shots as $local_shot ) {
			if ( ! is_array( $local_shot ) ) {
				continue;
			}

			$local_id    = self::text( $local_shot['id'] ?? '' );
			$matched_key = null;
			foreach ( $remaining as $remote_index => $remote_shot ) {
				if ( ! is_array( $remote_shot ) ) {
					continue;
				}
				$candidate = $remote_shot['id'] ?? $remote_shot['shot_id'] ?? ( $scene_index + 1 ) . '-' . ( $remote_index + 1 );
				if ( self::identity_matches( $local_id, $remote_project_id, 'shot', $candidate, $remote_index, $scope, $identity_map ) ) {
					$matched_key = $remote_index;
					break;
				}
			}

			if ( null === $matched_key ) {
				$merged[] = $local_shot;
				continue;
			}

			$merged[] = self::overlay_remote_record( $remaining[ $matched_key ], $local_shot );
			unset( $remaining[ $matched_key ] );
		}

		foreach ( $remaining as $remote_shot ) {
			$merged[] = $remote_shot;
		}

		return $merged;
	}

	/** Translate local visual-asset IDs back to the matching remote references. */
	private static function translate_scene_references( array $scenes, array $asset_ids ): array {
		foreach ( $scenes as $scene_index => $scene ) {
			if ( ! is_array( $scene ) ) {
				continue;
			}
			foreach ( [ 'characters', 'props' ] as $field ) {
				if ( ! is_array( $scene[ $field ] ?? null ) ) {
					continue;
				}
				$scene[ $field ] = array_values( array_map( static function ( $reference ) use ( $asset_ids ) {
					$reference = is_scalar( $reference ) ? (string) $reference : '';
					return $asset_ids[ $reference ] ?? $reference;
				}, $scene[ $field ] ) );
			}

			if ( is_scalar( $scene['location'] ?? null ) ) {
				$location          = (string) $scene['location'];
				$scene['location'] = $asset_ids[ $location ] ?? $location;
			}
			$scenes[ $scene_index ] = $scene;
		}

		return $scenes;
	}

	/** Overlay editable fields without replacing an existing provider record ID. */
	private static function overlay_remote_record( array $remote, array $local ): array {
		$merged = array_replace( $remote, $local );
		if ( array_key_exists( 'id', $remote ) ) {
			$merged['id'] = $remote['id'];
		} else {
			unset( $merged['id'] );
		}
		return $merged;
	}

	/** Whether a remote record resolves to a local exported identity. */
	private static function identity_matches( string $local_id, string $remote_project_id, string $kind, $candidate, int $index, string $scope, array $identity_map ): bool {
		if ( '' === $local_id ) {
			return false;
		}
		$candidate_id = is_scalar( $candidate ) ? self::text( $candidate ) : '';
		return $local_id === $candidate_id || $local_id === self::external_id( $remote_project_id, $kind, $candidate, $index, $scope, $identity_map );
	}

	/** Map remote object/id/name references to imported external IDs. */
	private static function references( $values, array $asset_ids ): array {
		$references = [];
		foreach ( self::array_items( $values ) as $value ) {
			if ( is_array( $value ) ) {
				$value = $value['id'] ?? $value['asset_id'] ?? $value['name'] ?? '';
			}
			if ( is_scalar( $value ) && isset( $asset_ids[ (string) $value ] ) ) {
				$references[] = $asset_ids[ (string) $value ];
			}
		}
		return array_values( array_unique( $references ) );
	}

	/** Map a scalar/object location reference. */
	private static function single_reference( $value, array $asset_ids ): string {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? $value['asset_id'] ?? $value['name'] ?? '';
		}
		return is_scalar( $value ) ? (string) ( $asset_ids[ (string) $value ] ?? '' ) : '';
	}

	/** Normalize remote references without translating them to local IDs. */
	private static function raw_references( $values ): array {
		$result = [];
		foreach ( self::array_items( $values ) as $value ) {
			$reference = self::raw_reference( $value );
			if ( '' !== $reference ) {
				$result[] = $reference;
			}
		}
		return array_values( array_unique( $result ) );
	}

	/** Normalize one scalar/object remote reference. */
	private static function raw_reference( $value ): string {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? $value['asset_id'] ?? $value['name'] ?? '';
		}
		return self::text( $value );
	}

	/** Normalize VideoDraft dialogue-like records. */
	private static function dialogue( $lines ): array {
		$result = [];
		foreach ( self::array_items( $lines ) as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$result[] = array_filter( [
				'speaker'     => self::text( $line['speaker'] ?? $line['character'] ?? '' ),
				'text'        => self::text( $line['text'] ?? $line['line'] ?? $line['dialogue'] ?? '' ),
				'description' => self::text( $line['description'] ?? $line['direction'] ?? '' ),
			] );
		}
		return $result;
	}

	/** Gather World assets from both explicit container edges and SCF-owned edges. */
	private static function world_assets( int $world_id ): array {
		$posts = [];
		foreach ( \WorldGraph\Utils\get_relationships( $world_id, 'worldgraph_world', 'outgoing' ) as $relationship ) {
			$type = (string) ( $relationship['to_type'] ?? '' );
			$id   = absint( $relationship['to_id'] ?? 0 );
			if ( in_array( $type, [ 'worldgraph_character', 'worldgraph_location', 'worldgraph_prop' ], true ) && $id ) {
				$posts[ $id ] = get_post( $id );
			}
		}
		foreach ( \WorldGraph\Utils\get_relationships( $world_id, 'worldgraph_world', 'incoming' ) as $relationship ) {
			$type = (string) ( $relationship['from_type'] ?? '' );
			$id   = absint( $relationship['from_id'] ?? 0 );
			if ( in_array( $type, [ 'worldgraph_character', 'worldgraph_location', 'worldgraph_prop' ], true ) && $id ) {
				$posts[ $id ] = get_post( $id );
			}
		}
		return array_values( array_filter( $posts, static function ( $post ): bool {
			return $post instanceof \WP_Post;
		} ) );
	}

	/** Find the first related post of a requested type in either edge direction. */
	private static function related_post( int $post_id, string $post_type, string $target_type ): ?\WP_Post {
		foreach ( \WorldGraph\Utils\get_relationships( $post_id, $post_type, 'outgoing' ) as $relationship ) {
			if ( $target_type === ( $relationship['to_type'] ?? '' ) ) {
				$post = get_post( absint( $relationship['to_id'] ?? 0 ) );
				return $post instanceof \WP_Post ? $post : null;
			}
		}
		foreach ( \WorldGraph\Utils\get_relationships( $post_id, $post_type, 'incoming' ) as $relationship ) {
			if ( $target_type === ( $relationship['from_type'] ?? '' ) ) {
				$post = get_post( absint( $relationship['from_id'] ?? 0 ) );
				return $post instanceof \WP_Post ? $post : null;
			}
		}
		return null;
	}

	/** Whether a Scene belongs directly to the Project or through an Episode. */
	private static function belongs_to_project( int $scene_id, int $project_id ): bool {
		foreach ( \WorldGraph\Utils\get_relationships( $scene_id, 'worldgraph_scene', 'outgoing' ) as $relationship ) {
			$target_type = (string) ( $relationship['to_type'] ?? '' );
			$target_id   = absint( $relationship['to_id'] ?? 0 );
			if ( 'worldgraph_project' === $target_type && $project_id === $target_id ) {
				return true;
			}
			if ( 'worldgraph_episode' !== $target_type || ! $target_id ) {
				continue;
			}
			foreach ( \WorldGraph\Utils\get_relationships( $target_id, 'worldgraph_episode', 'outgoing' ) as $episode_relationship ) {
				if ( 'worldgraph_project' === ( $episode_relationship['to_type'] ?? '' ) && $project_id === absint( $episode_relationship['to_id'] ?? 0 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Query only Shots whose required scene relationship targets this Scene. */
	private static function shots_for_scene( int $scene_id ): array {
		$shots = get_posts( [ 'post_type' => 'worldgraph_shot', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ] );
		return array_values( array_filter( $shots, static function ( $shot ) use ( $scene_id ): bool {
			foreach ( \WorldGraph\Utils\get_relationships( $shot->ID, 'worldgraph_shot', 'outgoing' ) as $relationship ) {
				if ( 'worldgraph_scene' === ( $relationship['to_type'] ?? '' ) && $scene_id === absint( $relationship['to_id'] ?? 0 ) ) {
					return true;
				}
			}
			return false;
		} ) );
	}

	/** Read an SCF value with post-content fallback. */
	private static function field_text( int $post_id, string $field, string $fallback ): string {
		$value = function_exists( '\\WorldGraph\\Utils\\worldgraph_get_field_value' ) ? \WorldGraph\Utils\worldgraph_get_field_value( $post_id, $field ) : get_post_meta( $post_id, $field, true );
		return self::text( '' !== (string) $value ? $value : $fallback );
	}

	/** Stable local identifier for repeat exports. */
	private static function local_external_id( \WP_Post $post ): string {
		$external = sanitize_text_field( (string) get_post_meta( $post->ID, 'external_id', true ) );
		if ( '' === $external ) {
			$external = sprintf( 'worldgraph-%s-%d', str_replace( 'worldgraph_', '', $post->post_type ), $post->ID );
			update_post_meta( $post->ID, 'external_id', $external );
		}
		return $external;
	}

	/** Normalize arbitrary scalar content to plain text. */
	private static function text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	/** Whether an array uses zero-based consecutive integer keys. */
	private static function is_list( array $value ): bool {
		return $value === array_values( $value );
	}

	/** Sort associative keys recursively without reordering lists. */
	private static function sort_recursive( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::sort_recursive( $item );
			}
		}
		if ( ! self::is_list( $value ) ) {
			ksort( $value );
		}
		return $value;
	}
}
