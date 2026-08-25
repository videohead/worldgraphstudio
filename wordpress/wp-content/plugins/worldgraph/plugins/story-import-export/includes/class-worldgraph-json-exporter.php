<?php
/**
 * Canonical World Graph Studio JSON exporter.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * Project a live, project-scoped Story Graph into the portable v1.2 contract.
 */
class WorldGraph_JSON_Exporter {

	/** Portable document version produced by this exporter. */
	private const DOCUMENT_VERSION = '1.2';

	/**
	 * CPTs represented by the portable document.
	 *
	 * @var array<int, string>
	 */
	private const PORTABLE_POST_TYPES = [
		'worldgraph_project',
		'worldgraph_world',
		'worldgraph_character',
		'worldgraph_location',
		'worldgraph_prop',
		'worldgraph_org',
		'worldgraph_episode',
		'worldgraph_scene',
		'worldgraph_shot',
		'worldgraph_sound',
		'worldgraph_asset',
		'worldgraph_editorial',
	];

	/**
	 * Stable external IDs for the posts in the current export, keyed by post ID.
	 *
	 * IDs synthesized for legacy posts are deliberately not written back to the
	 * database. Repeated exports remain stable because the fallback includes the
	 * immutable WordPress post ID.
	 *
	 * @var array<int, string>
	 */
	private $external_ids = [];

	/**
	 * Export a Project as a canonical World Graph Studio v1.2 document.
	 *
	 * @param int $project_id World Graph Studio Project post ID.
	 * @return array<string, mixed>|\WP_Error Portable document or an error.
	 */
	public function export_project( int $project_id ) {
		$project = get_post( $project_id );
		if ( ! $project instanceof \WP_Post || 'worldgraph_project' !== $project->post_type ) {
			return new \WP_Error(
				'worldgraph_invalid_export_project',
				__( 'Select a valid World Graph Studio Project to export.', 'worldgraph' )
			);
		}

		$scoped_posts = $this->get_project_scoped_posts( $project_id );
		if ( is_wp_error( $scoped_posts ) ) {
			return $scoped_posts;
		}

		$world = $this->resolve_project_world( $project, $scoped_posts['worldgraph_world'] );
		if ( is_wp_error( $world ) ) {
			return $world;
		}

		// The v1.2 contract contains one Story World. Exclude any indirectly
		// connected World nodes after resolving the Project's direct World edge.
		$scoped_posts['worldgraph_world'] = [ $world->ID => $world ];

		$this->external_ids = [];
		foreach ( $scoped_posts as $posts ) {
			foreach ( $posts as $post ) {
				$this->external_ids[ $post->ID ] = $this->external_id( $post );
			}
		}

		$project_external_id  = $this->external_ids[ $project_id ];
		$sequence_external_id = $project_external_id . '-sequence';

		$this->sort_scoped_posts( $scoped_posts );
		$episode_membership = $this->resolve_episode_membership(
			$scoped_posts['worldgraph_episode'],
			$scoped_posts['worldgraph_scene']
		);
		if ( is_wp_error( $episode_membership ) ) {
			return $episode_membership;
		}

		$document = [
			'worldgraph_version' => self::DOCUMENT_VERSION,
			'project'            => $this->project_record( $project ),
			'world'              => $this->world_record( $world, $project_external_id ),
			'characters'         => [],
			'locations'          => [],
			'props'              => [],
			'organizations'      => [],
			'episodes'           => [],
			'scenes'             => [],
			'shots'              => [],
			'sounds'             => [],
			'assets'             => [],
			'editorial_artifacts' => [],
			'sequence'           => [],
		];

		foreach ( $scoped_posts['worldgraph_character'] as $character ) {
			$document['characters'][] = $this->character_record( $character, $world );
		}
		foreach ( $scoped_posts['worldgraph_location'] as $location ) {
			$document['locations'][] = $this->location_record( $location, $world );
		}
		foreach ( $scoped_posts['worldgraph_prop'] as $prop ) {
			$document['props'][] = $this->prop_record( $prop );
		}
		foreach ( $scoped_posts['worldgraph_org'] as $organization ) {
			$document['organizations'][] = $this->organization_record( $organization, $world );
		}
		foreach ( $scoped_posts['worldgraph_episode'] as $episode ) {
			$document['episodes'][] = $this->episode_record(
				$episode,
				$project_external_id,
				$episode_membership['episodes'][ $episode->ID ] ?? []
			);
		}
		foreach ( $scoped_posts['worldgraph_scene'] as $scene ) {
			$record = $this->scene_record(
				$scene,
				$sequence_external_id,
				$episode_membership['scenes'][ $scene->ID ] ?? 0
			);
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$document['scenes'][] = $record;
		}

		$scene_order = array_flip( array_map( 'absint', array_keys( $scoped_posts['worldgraph_scene'] ) ) );
		$this->sort_child_posts( $scoped_posts['worldgraph_shot'], 'worldgraph_scene', 'scene', $scene_order, 'shot_number' );
		$this->sort_child_posts( $scoped_posts['worldgraph_sound'], 'worldgraph_scene', 'scene', $scene_order, '' );

		foreach ( $scoped_posts['worldgraph_shot'] as $shot ) {
			$document['shots'][] = $this->shot_record( $shot, $sequence_external_id );
		}
		foreach ( $scoped_posts['worldgraph_sound'] as $sound ) {
			$document['sounds'][] = $this->sound_record( $sound );
		}
		foreach ( $scoped_posts['worldgraph_asset'] as $asset ) {
			$record = $this->asset_record( $asset, $project_external_id );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$document['assets'][] = $record;
		}
		foreach ( $scoped_posts['worldgraph_editorial'] as $artifact ) {
			$document['editorial_artifacts'][] = $this->editorial_artifact_record( $artifact, $project_external_id );
		}

		$document['sequence'] = [
			'id'             => $sequence_external_id,
			'title'          => $project->post_title . ' Portable Export Sequence',
			'order'          => array_column( $document['scenes'], 'id' ),
			'sequence_order' => 1,
		];

		$validated = $this->validate_document( $document );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $document;
	}

	/**
	 * Export a Project as pretty-printed JSON.
	 *
	 * @param int $project_id World Graph Studio Project post ID.
	 * @return string|\WP_Error JSON download body or an error.
	 */
	public function export_project_json( int $project_id ) {
		$document = $this->export_project( $project_id );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$json = wp_json_encode(
			$document,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( false === $json ) {
			return new \WP_Error(
				'worldgraph_export_json_encoding_failed',
				sprintf(
					/* translators: %s: JSON encoding error. */
					__( 'The World Graph Studio export could not be encoded as JSON: %s', 'worldgraph' ),
					json_last_error_msg()
				)
			);
		}

		return $json . "\n";
	}

	/**
	 * Get the nearest-project Story Graph and resolve its posts.
	 *
	 * The core graph filter includes shared nodes in every project when they are
	 * equally near, while excluding nodes that are closer to another Project.
	 * This is the established project-scoping rule used by graph analytics.
	 *
	 * @param int $project_id Selected Project post ID.
	 * @return array<string, array<int, \WP_Post>>|\WP_Error Posts keyed by CPT and ID.
	 */
	private function get_project_scoped_posts( int $project_id ) {
		if ( ! function_exists( '\\WorldGraph\\Utils\\fetch_relationship_graph' ) ) {
			return new \WP_Error(
				'worldgraph_export_graph_unavailable',
				__( 'The Story Graph traversal service is unavailable.', 'worldgraph' )
			);
		}

		$graph = \WorldGraph\Utils\fetch_relationship_graph( [ 'project_id' => $project_id ] );
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}

		$posts_by_type = array_fill_keys( self::PORTABLE_POST_TYPES, [] );
		foreach ( (array) ( $graph['nodes'] ?? [] ) as $node ) {
			$post_id   = absint( $node['id'] ?? 0 );
			$post_type = (string) ( $node['type'] ?? '' );
			if ( ! $post_id || ! isset( $posts_by_type[ $post_type ] ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post && $post_type === $post->post_type ) {
				$posts_by_type[ $post_type ][ $post_id ] = $post;
			}
		}

		$project = get_post( $project_id );
		if ( $project instanceof \WP_Post ) {
			$posts_by_type['worldgraph_project'] = [ $project_id => $project ];
		}

		return $posts_by_type;
	}

	/**
	 * Resolve the one direct Story World representable by the portable contract.
	 *
	 * @param \WP_Post                  $project      Selected Project.
	 * @param array<int, \WP_Post>      $scoped_worlds Project-scoped World posts.
	 * @return \WP_Post|\WP_Error Direct World or an error.
	 */
	private function resolve_project_world( \WP_Post $project, array $scoped_worlds ) {
		$world_ids = $this->relationship_post_ids(
			$project->ID,
			'worldgraph_project',
			'world',
			'worldgraph_world',
			'contains'
		);

		foreach ( $scoped_worlds as $world ) {
			$project_ids = $this->relationship_post_ids(
				$world->ID,
				'worldgraph_world',
				'project',
				'worldgraph_project',
				'belongs_to'
			);
			if ( in_array( $project->ID, $project_ids, true ) ) {
				$world_ids[] = $world->ID;
			}
		}

		$world_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $world_ids ),
					static function( int $world_id ) use ( $scoped_worlds ): bool {
						return isset( $scoped_worlds[ $world_id ] );
					}
				)
			)
		);

		if ( 1 !== count( $world_ids ) ) {
			$message = empty( $world_ids )
				? __( 'The selected Project has no directly related Story World.', 'worldgraph' )
				: __( 'The selected Project has more than one directly related Story World; the portable format supports exactly one.', 'worldgraph' );

			return new \WP_Error( 'worldgraph_export_invalid_world', $message );
		}

		return $scoped_worlds[ $world_ids[0] ];
	}

	/**
	 * Assign deterministic ordering to project-scoped entity collections.
	 *
	 * @param array<string, array<int, \WP_Post>> $posts_by_type Posts keyed by CPT.
	 */
	private function sort_scoped_posts( array &$posts_by_type ): void {
		foreach ( $posts_by_type as $post_type => &$posts ) {
			if ( in_array( $post_type, [ 'worldgraph_project', 'worldgraph_world', 'worldgraph_shot', 'worldgraph_sound' ], true ) ) {
				continue;
			}

			uasort(
				$posts,
				function( \WP_Post $left, \WP_Post $right ) use ( $post_type ): int {
					if ( 'worldgraph_episode' === $post_type ) {
						$comparison = $this->ordered_number( $left, 'episode_number' ) <=> $this->ordered_number( $right, 'episode_number' );
						if ( 0 !== $comparison ) {
							return $comparison;
						}
					} elseif ( 'worldgraph_scene' === $post_type ) {
						$left_sequence_order  = $this->positive_meta_number( $left->ID, 'sequence_order' );
						$right_sequence_order = $this->positive_meta_number( $right->ID, 'sequence_order' );
						if ( null !== $left_sequence_order || null !== $right_sequence_order ) {
							$comparison = ( $left_sequence_order ?? PHP_INT_MAX ) <=> ( $right_sequence_order ?? PHP_INT_MAX );
							if ( 0 !== $comparison ) {
								return $comparison;
							}
						}

						$comparison = $this->ordered_number( $left, 'scene_number' ) <=> $this->ordered_number( $right, 'scene_number' );
						if ( 0 !== $comparison ) {
							return $comparison;
						}
					}

					return $this->compare_external_ids( $left, $right );
				}
			);
		}
		unset( $posts );
	}

	/**
	 * Sort Shot or Sound posts by their Scene, then their local order.
	 *
	 * @param array<int, \WP_Post> $posts       Child posts, passed by reference.
	 * @param string               $parent_type Parent CPT.
	 * @param string               $field       Relationship field name.
	 * @param array<int, int>      $parent_order Zero-based parent positions.
	 * @param string               $number_field Optional numeric order field.
	 */
	private function sort_child_posts( array &$posts, string $parent_type, string $field, array $parent_order, string $number_field ): void {
		uasort(
			$posts,
			function( \WP_Post $left, \WP_Post $right ) use ( $parent_type, $field, $parent_order, $number_field ): int {
				$left_parent  = $this->relationship_post_ids( $left->ID, $left->post_type, $field, $parent_type, 'belongs_to' )[0] ?? 0;
				$right_parent = $this->relationship_post_ids( $right->ID, $right->post_type, $field, $parent_type, 'belongs_to' )[0] ?? 0;
				$comparison   = ( $parent_order[ $left_parent ] ?? PHP_INT_MAX ) <=> ( $parent_order[ $right_parent ] ?? PHP_INT_MAX );
				if ( 0 !== $comparison ) {
					return $comparison;
				}

				if ( '' !== $number_field ) {
					$comparison = $this->ordered_number( $left, $number_field ) <=> $this->ordered_number( $right, $number_field );
					if ( 0 !== $comparison ) {
						return $comparison;
					}
				} elseif ( $left->menu_order !== $right->menu_order ) {
					return $left->menu_order <=> $right->menu_order;
				}

				return $this->compare_external_ids( $left, $right );
			}
		);
	}

	/** Compare two posts by stable external ID and then WordPress ID. */
	private function compare_external_ids( \WP_Post $left, \WP_Post $right ): int {
		$comparison = strnatcasecmp( $this->external_ids[ $left->ID ], $this->external_ids[ $right->ID ] );
		return 0 !== $comparison ? $comparison : $left->ID <=> $right->ID;
	}

	/** Get a positive ordering value, falling back to menu order and infinity. */
	private function ordered_number( \WP_Post $post, string $field ): int {
		$value = $this->field_value( $post->ID, $field );
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return (int) $value;
		}
		if ( $post->menu_order > 0 ) {
			return (int) $post->menu_order;
		}
		return PHP_INT_MAX;
	}

	/** Read a positive integer directly from post meta. */
	private function positive_meta_number( int $post_id, string $key ): ?int {
		$value = get_post_meta( $post_id, $key, true );
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * Reconcile Episode -> Scenes and Scene -> Episode into one valid mapping.
	 *
	 * @param array<int, \WP_Post> $episodes Project-scoped Episodes.
	 * @param array<int, \WP_Post> $scenes   Project-scoped Scenes.
	 * @return array<string, array<int, mixed>>|\WP_Error Membership maps.
	 */
	private function resolve_episode_membership( array $episodes, array $scenes ) {
		$scene_episode = [];
		$episode_scenes = array_fill_keys( array_keys( $episodes ), [] );

		foreach ( $scenes as $scene ) {
			$episode_ids = array_values(
				array_filter(
					$this->relationship_post_ids( $scene->ID, 'worldgraph_scene', 'episode', 'worldgraph_episode', 'belongs_to' ),
					static function( int $episode_id ) use ( $episodes ): bool {
						return isset( $episodes[ $episode_id ] );
					}
				)
			);
			if ( count( $episode_ids ) > 1 ) {
				return new \WP_Error(
					'worldgraph_export_ambiguous_episode',
					sprintf(
						/* translators: %s: Scene title. */
						__( 'Scene "%s" belongs to more than one exported Episode.', 'worldgraph' ),
						$scene->post_title
					)
				);
			}
			if ( ! empty( $episode_ids ) ) {
				$scene_episode[ $scene->ID ] = $episode_ids[0];
			}
		}

		foreach ( $episodes as $episode ) {
			$scene_ids = $this->relationship_post_ids( $episode->ID, 'worldgraph_episode', 'scenes', 'worldgraph_scene', 'contains' );
			foreach ( $scene_ids as $scene_id ) {
				if ( ! isset( $scenes[ $scene_id ] ) ) {
					continue;
				}
				if ( isset( $scene_episode[ $scene_id ] ) && $episode->ID !== $scene_episode[ $scene_id ] ) {
					return new \WP_Error(
						'worldgraph_export_conflicting_episode',
						sprintf(
							/* translators: %s: Scene title. */
							__( 'Scene "%s" has conflicting Episode relationships.', 'worldgraph' ),
							$scenes[ $scene_id ]->post_title
						)
					);
				}
				$scene_episode[ $scene_id ] = $episode->ID;
			}
		}

		// Scene order is already canonical, so grouping here also gives each
		// Episode a stable narrative Scene order.
		foreach ( $scenes as $scene ) {
			$episode_id = $scene_episode[ $scene->ID ] ?? 0;
			if ( $episode_id && isset( $episode_scenes[ $episode_id ] ) ) {
				$episode_scenes[ $episode_id ][] = $scene->ID;
			}
		}

		return [
			'scenes'   => $scene_episode,
			'episodes' => $episode_scenes,
		];
	}

	/** Build the portable Project record. */
	private function project_record( \WP_Post $project ): array {
		$record = [
			'id'                => $this->external_ids[ $project->ID ],
			'title'             => (string) $project->post_title,
			'project_slug'      => $this->project_slug( $project ),
			'description'       => $this->content_field( $project, 'description' ),
			'generation_prompt' => $this->scalar_field( $project->ID, 'generation_prompt' ),
			'target_medium'     => $this->scalar_field( $project->ID, 'target_medium' ),
			'production_status' => $this->taxonomy_slug( $project->ID, 'worldgraph_status' ),
			'start_date'        => $this->date_field( $project->ID, 'start_date' ),
			'end_date'          => $this->date_field( $project->ID, 'end_date' ),
			'genres'            => $this->taxonomy_slugs( $project->ID, 'worldgraph_genre' ),
			'associates'      => $this->relationship_external_ids(
				$project->ID,
				'worldgraph_project',
				'associates',
				'worldgraph_character',
				'contains'
			),
			'production_stage'  => $this->scalar_field( $project->ID, 'production_stage' ),
			'aspect_ratio'      => $this->scalar_field( $project->ID, 'aspect_ratio' ),
		];

		$this->add_numeric_field( $record, 'frame_width', $this->field_value( $project->ID, 'frame_width' ), true );
		$this->add_numeric_field( $record, 'frame_height', $this->field_value( $project->ID, 'frame_height' ), true );
		$this->add_numeric_field( $record, 'frame_rate', $this->field_value( $project->ID, 'frame_rate' ), false );

		return $record;
	}

	/** Build the portable Story World record. */
	private function world_record( \WP_Post $world, string $project_external_id ): array {
		return [
			'id'          => $this->external_ids[ $world->ID ],
			'name'        => (string) $world->post_title,
			'description' => $this->content_field( $world, 'synopsis' ),
			'timeline'    => $this->scalar_field( $world->ID, 'timeline' ),
			'rules'       => $this->scalar_field( $world->ID, 'rules' ),
			'themes'      => $this->scalar_field( $world->ID, 'themes' ),
			'geography'   => $this->scalar_field( $world->ID, 'geography' ),
			'references'  => $this->scalar_field( $world->ID, 'references' ),
			'project'     => $project_external_id,
		];
	}

	/** Build a portable Character record. */
	private function character_record( \WP_Post $character, \WP_Post $world ): array {
		return [
			'id'           => $this->external_ids[ $character->ID ],
			'name'         => (string) $character->post_title,
			'description'  => $this->content_field( $character, 'biography' ),
			'roles'        => $this->taxonomy_slugs( $character->ID, 'worldgraph_character_role' ),
			'relations'    => $this->taxonomy_slugs( $character->ID, 'worldgraph_character_relation' ),
			'age'          => $this->scalar_field( $character->ID, 'age' ),
			'appearance'   => $this->scalar_field( $character->ID, 'appearance' ),
			'personality'  => $this->scalar_field( $character->ID, 'personality' ),
			'motivation'   => $this->scalar_field( $character->ID, 'motivation' ),
			'backstory'    => $this->scalar_field( $character->ID, 'backstory' ),
			'voice_profile' => $this->scalar_field( $character->ID, 'voice_profile' ),
			'avatar_asset' => $this->relationship_external_id(
				$character->ID,
				'worldgraph_character',
				'avatar_asset',
				'worldgraph_asset',
				'linked_to'
			),
			'story_world'  => $this->belongs_to_world( $character, $world, 'story_world' )
				? $this->external_ids[ $world->ID ]
				: '',
		];
	}

	/** Build a portable Location record. */
	private function location_record( \WP_Post $location, \WP_Post $world ): array {
		return [
			'id'               => $this->external_ids[ $location->ID ],
			'name'             => (string) $location->post_title,
			'description'      => $this->content_field( $location, 'description' ),
			'environment_type' => $this->scalar_field( $location->ID, 'environment_type' ),
			'geography'        => $this->scalar_field( $location->ID, 'geography' ),
			'mood'             => $this->scalar_field( $location->ID, 'mood' ),
			'visual_reference' => $this->relationship_external_id(
				$location->ID,
				'worldgraph_location',
				'visual_reference',
				'worldgraph_asset',
				'linked_to'
			),
			'story_world'      => $this->belongs_to_world( $location, $world, 'story_world' )
				? $this->external_ids[ $world->ID ]
				: '',
		];
	}

	/** Build a portable Prop record. */
	private function prop_record( \WP_Post $prop ): array {
		return [
			'id'              => $this->external_ids[ $prop->ID ],
			'name'            => (string) $prop->post_title,
			'description'     => $this->content_field( $prop, 'description' ),
			'purpose'         => $this->scalar_field( $prop->ID, 'purpose' ),
			'owner_character' => $this->relationship_external_id(
				$prop->ID,
				'worldgraph_prop',
				'owner_character',
				'worldgraph_character',
				'linked_to'
			),
			'notes'           => $this->scalar_field( $prop->ID, 'notes' ),
		];
	}

	/** Build a portable Organization record. */
	private function organization_record( \WP_Post $organization, \WP_Post $world ): array {
		return [
			'id'                => $this->external_ids[ $organization->ID ],
			'name'              => (string) $organization->post_title,
			'organization_type' => $this->scalar_field( $organization->ID, 'organization_type' ),
			'description'       => $this->content_field( $organization, 'description' ),
			'leadership'        => $this->relationship_external_id(
				$organization->ID,
				'worldgraph_org',
				'leadership',
				'worldgraph_character',
				'linked_to'
			),
			'members'           => $this->relationship_external_ids(
				$organization->ID,
				'worldgraph_org',
				'members',
				'worldgraph_character',
				'contains'
			),
			'goals'             => $this->scalar_field( $organization->ID, 'goals' ),
			'story_world'       => $this->belongs_to_world( $organization, $world, 'story_world' )
				? $this->external_ids[ $world->ID ]
				: '',
		];
	}

	/**
	 * Build a portable Episode record.
	 *
	 * @param \WP_Post       $episode             Episode post.
	 * @param string         $project_external_id Project external ID.
	 * @param array<int, int> $scene_ids           Ordered Scene post IDs.
	 */
	private function episode_record( \WP_Post $episode, string $project_external_id, array $scene_ids ): array {
		$record = [
			'id'                => $this->external_ids[ $episode->ID ],
			'title'             => (string) $episode->post_title,
			'synopsis'          => $this->content_field( $episode, 'synopsis' ),
			'production_status' => $this->taxonomy_slug( $episode->ID, 'worldgraph_status' ),
			'project'           => $project_external_id,
			'scenes'            => $this->external_ids_for_posts( $scene_ids, 'worldgraph_scene' ),
		];
		$episode_number = $this->field_value( $episode->ID, 'episode_number' );
		if ( '' === (string) $episode_number && $episode->menu_order > 0 ) {
			$episode_number = $episode->menu_order;
		}
		$this->add_numeric_field( $record, 'episode_number', $episode_number, true );

		return $record;
	}

	/**
	 * Build a portable Scene record.
	 *
	 * @return array<string, mixed>|\WP_Error Scene record or malformed dialogue error.
	 */
	private function scene_record( \WP_Post $scene, string $sequence_external_id, int $episode_id ) {
		$dialogue = $this->dialogue_value( $scene );
		if ( is_wp_error( $dialogue ) ) {
			return $dialogue;
		}

		$record = [
			'id'                => $this->external_ids[ $scene->ID ],
			'title'             => (string) $scene->post_title,
			'summary'           => $this->content_field( $scene, 'summary' ),
			'script_content'    => $this->scalar_field( $scene->ID, 'script_content' ),
			'location'          => $this->relationship_external_id(
				$scene->ID,
				'worldgraph_scene',
				'location',
				'worldgraph_location',
				'located_in'
			),
			'time_of_day'       => $this->scalar_field( $scene->ID, 'time_of_day' ),
			'emotional_tone'    => $this->scalar_field( $scene->ID, 'emotional_tone' ),
			'lens'              => $this->scalar_field( $scene->ID, 'lens' ),
			'camera_movement'   => $this->scalar_field( $scene->ID, 'camera_movement' ),
			'generation_prompt' => $this->scalar_field( $scene->ID, 'generation_prompt' ),
			'audio_direction'   => $this->scalar_field( $scene->ID, 'audio_direction' ),
			'characters'        => $this->relationship_external_ids(
				$scene->ID,
				'worldgraph_scene',
				'characters',
				'worldgraph_character',
				'appears_in'
			),
			'props'             => $this->relationship_external_ids(
				$scene->ID,
				'worldgraph_scene',
				'props',
				'worldgraph_prop',
				'used_in'
			),
			'dialogue'          => $dialogue,
			'tags'              => $this->taxonomy_slugs( $scene->ID, 'worldgraph_scene_tag' ),
			'production_notes'  => $this->scalar_field( $scene->ID, 'production_notes' ),
			'sequence'          => $sequence_external_id,
			'episode'           => $episode_id && isset( $this->external_ids[ $episode_id ] )
				? $this->external_ids[ $episode_id ]
				: '',
		];

		$scene_number = $this->field_value( $scene->ID, 'scene_number' );
		if ( '' === (string) $scene_number && $scene->menu_order > 0 ) {
			$scene_number = $scene->menu_order;
		}
		$this->add_numeric_field( $record, 'scene_number', $scene_number, true );

		return $record;
	}

	/** Build a portable Shot record. */
	private function shot_record( \WP_Post $shot, string $sequence_external_id ): array {
		$shot_type = $this->scalar_field( $shot->ID, 'shot_type' );
		if ( '' !== $shot_type && function_exists( '\\WorldGraph\\Utils\\worldgraph_normalize_shot_type' ) ) {
			$shot_type = \WorldGraph\Utils\worldgraph_normalize_shot_type( $shot_type );
		}

		$record = [
			'id'                => $this->external_ids[ $shot->ID ],
			'scene'             => $this->relationship_external_id(
				$shot->ID,
				'worldgraph_shot',
				'scene',
				'worldgraph_scene',
				'belongs_to'
			),
			'title'             => (string) $shot->post_title,
			'type'              => $shot_type,
			'camera_angle'      => $this->scalar_field( $shot->ID, 'camera_angle' ),
			'lens'              => $this->scalar_field( $shot->ID, 'lens' ),
			'camera_movement'   => $this->scalar_field( $shot->ID, 'camera_movement' ),
			'motion_direction'  => $this->scalar_field( $shot->ID, 'motion_direction' ),
			'duration'          => $this->scalar_field( $shot->ID, 'duration' ),
			'slate_id'          => $this->scalar_field( $shot->ID, 'slate_id' ),
			'description'       => $this->content_field( $shot, 'shot_description' ),
			'generation_prompt' => $this->scalar_field( $shot->ID, 'generation_prompt' ),
			'editorial_notes'   => $this->scalar_field( $shot->ID, 'editorial_notes' ),
			'sequence'          => $sequence_external_id,
		];

		$shot_number = $this->field_value( $shot->ID, 'shot_number' );
		if ( '' === (string) $shot_number && $shot->menu_order > 0 ) {
			$shot_number = $shot->menu_order;
		}
		$this->add_numeric_field( $record, 'shot_number', $shot_number, true );
		$this->add_numeric_field( $record, 'take_number', $this->field_value( $shot->ID, 'take_number' ), true );

		return $record;
	}

	/** Build a portable Sound record. */
	private function sound_record( \WP_Post $sound ): array {
		$diegetic = $this->scalar_field( $sound->ID, 'diegetic' );
		return [
			'id'                => $this->external_ids[ $sound->ID ],
			'title'             => (string) $sound->post_title,
			'type'              => $this->taxonomy_slug( $sound->ID, 'worldgraph_sound_type' ),
			'production_status' => $this->taxonomy_slug( $sound->ID, 'worldgraph_status' ),
			'description'       => (string) $sound->post_content,
			'spoken_text'       => $this->scalar_field( $sound->ID, 'spoken_text' ),
			'lyrics'            => $this->scalar_field( $sound->ID, 'lyrics' ),
			'start_timecode'    => $this->scalar_field( $sound->ID, 'start_timecode' ),
			'duration'          => $this->scalar_field( $sound->ID, 'duration' ),
			'diegetic'          => '' !== $diegetic ? $diegetic : 'unspecified',
			'production_notes'  => $this->scalar_field( $sound->ID, 'production_notes' ),
			'scene'             => $this->relationship_external_id(
				$sound->ID,
				'worldgraph_sound',
				'scene',
				'worldgraph_scene',
				'belongs_to'
			),
			'shot'              => $this->relationship_external_id(
				$sound->ID,
				'worldgraph_sound',
				'shot',
				'worldgraph_shot',
				'belongs_to'
			),
			'character'         => $this->relationship_external_id(
				$sound->ID,
				'worldgraph_sound',
				'character',
				'worldgraph_character',
				'linked_to'
			),
			'asset'             => $this->relationship_external_id(
				$sound->ID,
				'worldgraph_sound',
				'asset',
				'worldgraph_asset',
				'linked_to'
			),
		];
	}

	/**
	 * Build a portable Asset record.
	 *
	 * @return array<string, mixed>|\WP_Error Asset record or invalid JSON field error.
	 */
	private function asset_record( \WP_Post $asset, string $project_external_id ) {
		$record = [
			'id'            => $this->external_ids[ $asset->ID ],
			'title'         => (string) $asset->post_title,
			'asset_type'    => $this->taxonomy_slug( $asset->ID, 'worldgraph_asset_type' ),
			'workflow_name' => $this->scalar_field( $asset->ID, 'workflow_name' ),
			'prompt'        => $this->content_field( $asset, 'prompt' ),
			'model_name'    => $this->scalar_field( $asset->ID, 'model_name' ),
			'version'       => $this->scalar_field( $asset->ID, 'version' ),
			'status'        => $this->scalar_field( $asset->ID, 'status' ),
			'storage_uri'   => $this->scalar_field( $asset->ID, 'storage_uri' ),
			'project'       => $project_external_id,
			'character'     => $this->relationship_external_id(
				$asset->ID,
				'worldgraph_asset',
				'character',
				'worldgraph_character',
				'linked_to'
			),
			'location'      => $this->relationship_external_id(
				$asset->ID,
				'worldgraph_asset',
				'location',
				'worldgraph_location',
				'linked_to'
			),
			'scene'         => $this->relationship_external_id(
				$asset->ID,
				'worldgraph_asset',
				'scene',
				'worldgraph_scene',
				'linked_to'
			),
		];

		$this->add_numeric_field( $record, 'seed', $this->field_value( $asset->ID, 'seed' ), true );
		$generation_parameters = $this->generation_parameters( $asset );
		if ( is_wp_error( $generation_parameters ) ) {
			return $generation_parameters;
		}
		if ( null !== $generation_parameters ) {
			$record['generation_parameters'] = $generation_parameters;
		}

		return $record;
	}

	/** Build a portable Editorial Artifact record. */
	private function editorial_artifact_record( \WP_Post $artifact, string $project_external_id ): array {
		return [
			'id'             => $this->external_ids[ $artifact->ID ],
			'title'          => (string) $artifact->post_title,
			'artifact_type'  => $this->scalar_field( $artifact->ID, 'artifact_type' ),
			'export_format'  => $this->scalar_field( $artifact->ID, 'export_format' ),
			'generated_date' => $this->date_field( $artifact->ID, 'generated_date' ),
			'source_scene'   => $this->relationship_external_id(
				$artifact->ID,
				'worldgraph_editorial',
				'source_scene',
				'worldgraph_scene',
				'references'
			),
			'source_shot'    => $this->relationship_external_id(
				$artifact->ID,
				'worldgraph_editorial',
				'source_shot',
				'worldgraph_shot',
				'references'
			),
			'notes'          => $this->content_field( $artifact, 'notes' ),
			'project'        => $project_external_id,
		];
	}

	/** Read a field through the canonical SCF boundary. */
	private function field_value( int $post_id, string $field_name ) {
		if ( function_exists( '\\WorldGraph\\Utils\\worldgraph_get_field_value' ) ) {
			return \WorldGraph\Utils\worldgraph_get_field_value( $post_id, $field_name );
		}

		return get_post_meta( $post_id, $field_name, true );
	}

	/** Read a scalar SCF field as a portable string. */
	private function scalar_field( int $post_id, string $field_name ): string {
		$value = $this->field_value( $post_id, $field_name );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Use a populated SCF field, with post content as its canonical fallback. */
	private function content_field( \WP_Post $post, string $field_name ): string {
		$value = $this->field_value( $post->ID, $field_name );
		return is_scalar( $value ) && '' !== (string) $value
			? (string) $value
			: (string) $post->post_content;
	}

	/** Return an SCF date in the importer's YYYY-MM-DD shape. */
	private function date_field( int $post_id, string $field_name ): string {
		$value = trim( $this->scalar_field( $post_id, $field_name ) );
		if ( preg_match( '/^\d{8}$/', $value ) ) {
			return substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 );
		}

		return $value;
	}

	/** Resolve the portable Project slug without writing a missing field. */
	private function project_slug( \WP_Post $project ): string {
		$slug = $this->scalar_field( $project->ID, 'project_slug' );
		if ( '' !== trim( $slug ) ) {
			return $slug;
		}

		if ( function_exists( '\\WorldGraph\\Utils\\sanitize_story_id' ) ) {
			return \WorldGraph\Utils\sanitize_story_id( $this->external_ids[ $project->ID ] );
		}

		return sanitize_title( $project->post_name ?: $project->post_title );
	}

	/**
	 * Add a non-empty numeric field while preserving invalid source data for the
	 * final importer validation to reject.
	 *
	 * @param array<string, mixed> $record     Record passed by reference.
	 * @param string               $key        Portable field name.
	 * @param mixed                $value      Stored value.
	 * @param bool                 $as_integer Whether valid numbers are integral.
	 */
	private function add_numeric_field( array &$record, string $key, $value, bool $as_integer ): void {
		if ( null === $value || ( is_scalar( $value ) && '' === trim( (string) $value ) ) ) {
			return;
		}

		if ( is_numeric( $value ) ) {
			$record[ $key ] = $as_integer ? (int) $value : (float) $value;
			return;
		}

		$record[ $key ] = is_scalar( $value ) ? (string) $value : $value;
	}

	/** Get sorted canonical term slugs for an entity. */
	private function taxonomy_slugs( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$slugs = [];
		foreach ( $terms as $term ) {
			$slug = is_object( $term ) && isset( $term->slug ) ? (string) $term->slug : '';
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		$slugs = array_values( array_unique( $slugs ) );
		sort( $slugs, SORT_STRING );

		return $slugs;
	}

	/** Get the first canonical term slug for a scalar taxonomy field. */
	private function taxonomy_slug( int $post_id, string $taxonomy ): string {
		$slugs = $this->taxonomy_slugs( $post_id, $taxonomy );
		return $slugs[0] ?? '';
	}

	/**
	 * Resolve one relationship field from the Story Graph.
	 *
	 * Exact field-tagged edges take precedence. SCF is the second source because
	 * it understands current markers and field formatting. Untagged legacy edges
	 * are used only when the field has never been explicitly set or cleared.
	 *
	 * @return array<int, int> Related post IDs in stored relationship order.
	 */
	private function relationship_post_ids(
		int $source_id,
		string $source_type,
		string $field_name,
		string $target_type,
		string $relationship_type
	): array {
		$exact  = [];
		$legacy = [];
		if ( function_exists( '\\WorldGraph\\Utils\\get_relationships' ) ) {
			foreach ( \WorldGraph\Utils\get_relationships( $source_id, $source_type, 'outgoing' ) as $relationship ) {
				if ( $target_type !== (string) ( $relationship['to_type'] ?? '' ) ) {
					continue;
				}

				$target_id          = absint( $relationship['to_id'] ?? 0 );
				$relationship_field = sanitize_key( (string) ( $relationship['metadata']['field'] ?? '' ) );
				if ( sanitize_key( $field_name ) === $relationship_field ) {
					$exact[] = $target_id;
				} elseif (
					'' === $relationship_field
					&& ( '' === $relationship_type || $relationship_type === (string) ( $relationship['type'] ?? '' ) )
				) {
					$legacy[] = $target_id;
				}
			}
		}

		$exact = $this->valid_related_ids( $exact, $target_type );
		if ( ! empty( $exact ) ) {
			return $exact;
		}

		$stored_ids = $this->valid_related_ids(
			$this->normalize_post_ids( $this->field_value( $source_id, $field_name ) ),
			$target_type
		);
		if ( ! empty( $stored_ids ) ) {
			return $stored_ids;
		}

		$marker_exists = function_exists( '\\WorldGraph\\Utils\\relationship_field_marker_key' )
			&& function_exists( 'metadata_exists' )
			&& metadata_exists(
				'post',
				$source_id,
				\WorldGraph\Utils\relationship_field_marker_key( $field_name )
			);
		if ( $marker_exists ) {
			return [];
		}

		return $this->valid_related_ids( $legacy, $target_type );
	}

	/** Normalize SCF relationship return shapes to post IDs. */
	private function normalize_post_ids( $value ): array {
		if ( $value instanceof \WP_Post ) {
			return [ (int) $value->ID ];
		}
		if ( is_object( $value ) && isset( $value->ID ) ) {
			return [ absint( $value->ID ) ];
		}
		if ( is_numeric( $value ) ) {
			return [ absint( $value ) ];
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		if ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) ) {
			return [ absint( $value['ID'] ) ];
		}

		$ids = [];
		foreach ( $value as $item ) {
			$ids = array_merge( $ids, $this->normalize_post_ids( $item ) );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/** Keep only IDs that still resolve to the expected CPT. */
	private function valid_related_ids( array $post_ids, string $target_type ): array {
		$valid = [];
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) ) as $post_id ) {
			if ( $target_type === get_post_type( $post_id ) ) {
				$valid[] = $post_id;
			}
		}
		return $valid;
	}

	/** Resolve a multi-value relationship to in-document external IDs. */
	private function relationship_external_ids(
		int $source_id,
		string $source_type,
		string $field_name,
		string $target_type,
		string $relationship_type
	): array {
		return $this->external_ids_for_posts(
			$this->relationship_post_ids( $source_id, $source_type, $field_name, $target_type, $relationship_type ),
			$target_type
		);
	}

	/** Resolve a scalar relationship to an in-document external ID. */
	private function relationship_external_id(
		int $source_id,
		string $source_type,
		string $field_name,
		string $target_type,
		string $relationship_type
	): string {
		$ids = $this->relationship_external_ids( $source_id, $source_type, $field_name, $target_type, $relationship_type );
		return $ids[0] ?? '';
	}

	/** Convert related post IDs to external IDs only when they are in scope. */
	private function external_ids_for_posts( array $post_ids, string $target_type ): array {
		$external_ids = [];
		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( isset( $this->external_ids[ $post_id ] ) && $target_type === get_post_type( $post_id ) ) {
				$external_ids[] = $this->external_ids[ $post_id ];
			}
		}

		return array_values( array_unique( $external_ids ) );
	}

	/** Determine whether an entity belongs to the selected singular Story World. */
	private function belongs_to_world( \WP_Post $post, \WP_Post $world, string $field_name ): bool {
		$world_ids = $this->relationship_post_ids(
			$post->ID,
			$post->post_type,
			$field_name,
			'worldgraph_world',
			'belongs_to'
		);
		if ( in_array( $world->ID, $world_ids, true ) ) {
			return true;
		}

		if ( ! function_exists( '\\WorldGraph\\Utils\\get_relationships' ) ) {
			return false;
		}
		foreach ( \WorldGraph\Utils\get_relationships( $world->ID, 'worldgraph_world', 'outgoing' ) as $relationship ) {
			if (
				$post->ID === absint( $relationship['to_id'] ?? 0 )
				&& $post->post_type === (string) ( $relationship['to_type'] ?? '' )
				&& 'contains' === (string) ( $relationship['type'] ?? '' )
			) {
				return true;
			}
		}

		return false;
	}

	/** Normalize importer-managed Scene dialogue without flattening line breaks. */
	private function dialogue_value( \WP_Post $scene ) {
		$value = $this->field_value( $scene->ID, 'dialogue' );
		if ( null === $value || '' === $value || [] === $value ) {
			return [];
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error(
				'worldgraph_export_invalid_dialogue',
				sprintf(
					/* translators: %s: Scene title. */
					__( 'Scene "%s" has malformed structured dialogue.', 'worldgraph' ),
					$scene->post_title
				)
			);
		}

		$dialogue = [];
		foreach ( array_values( $value ) as $index => $line ) {
			if ( ! is_array( $line ) ) {
				return new \WP_Error(
					'worldgraph_export_invalid_dialogue',
					sprintf(
						/* translators: 1: Scene title, 2: one-based dialogue line number. */
						__( 'Scene "%1$s" has malformed dialogue at line %2$d.', 'worldgraph' ),
						$scene->post_title,
						$index + 1
					)
				);
			}

			$sequence = $line['sequence'] ?? ( $index + 1 );
			$dialogue[] = [
				'speaker'     => is_scalar( $line['speaker'] ?? '' ) ? (string) $line['speaker'] : '',
				'line'        => is_scalar( $line['line'] ?? $line['text'] ?? '' ) ? (string) ( $line['line'] ?? $line['text'] ?? '' ) : '',
				'description' => is_scalar( $line['description'] ?? '' ) ? (string) $line['description'] : '',
				'sequence'    => is_numeric( $sequence ) ? (int) $sequence : $sequence,
			];
		}

		return $dialogue;
	}

	/**
	 * Decode an Asset's generation parameters as a JSON object.
	 *
	 * @return object|null|\WP_Error Object, absent value, or malformed-field error.
	 */
	private function generation_parameters( \WP_Post $asset ) {
		$value = $this->field_value( $asset->ID, 'generation_parameters' );
		if ( null === $value || ( is_scalar( $value ) && '' === trim( (string) $value ) ) ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value );
		} elseif ( is_object( $value ) || is_array( $value ) ) {
			$encoded = wp_json_encode( $value );
			$decoded = false !== $encoded ? json_decode( $encoded ) : null;
		} else {
			$decoded = null;
		}

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $decoded ) ) {
			return new \WP_Error(
				'worldgraph_export_invalid_generation_parameters',
				sprintf(
					/* translators: %s: Asset title. */
					__( 'Asset "%s" has generation parameters that are not a JSON object.', 'worldgraph' ),
					$asset->post_title
				)
			);
		}

		return $decoded;
	}

	/** Return a stored external ID or a deterministic, non-persisted fallback. */
	private function external_id( \WP_Post $post ): string {
		$external_id = get_post_meta( $post->ID, 'external_id', true );
		if ( is_scalar( $external_id ) && '' !== trim( (string) $external_id ) ) {
			return (string) $external_id;
		}

		return sprintf(
			'worldgraph-%s-%d',
			str_replace( 'worldgraph_', '', $post->post_type ),
			$post->ID
		);
	}

	/** Validate the completed document through the canonical legacy FQCN importer. */
	private function validate_document( array $document ) {
		if ( ! class_exists( '\\WorldGraph\\Importer\\WorldGraph_Importer' ) ) {
			return new \WP_Error(
				'worldgraph_export_validator_unavailable',
				__( 'The World Graph Studio JSON validator is unavailable.', 'worldgraph' )
			);
		}

		$json = wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new \WP_Error(
				'worldgraph_export_json_encoding_failed',
				sprintf(
					/* translators: %s: JSON encoding error. */
					__( 'The World Graph Studio export could not be encoded for validation: %s', 'worldgraph' ),
					json_last_error_msg()
				)
			);
		}

		$importer   = new \WorldGraph\Importer\WorldGraph_Importer();
		$validation = $importer->import( $json, [ 'dry_run' => true ] );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! is_array( $validation ) || empty( $validation['verified'] ) || ! empty( $validation['errors'] ) ) {
			$errors = is_array( $validation ) ? array_filter( (array) ( $validation['errors'] ?? [] ) ) : [];
			return new \WP_Error(
				'worldgraph_export_validation_failed',
				! empty( $errors )
					? implode( ' ', array_map( 'strval', $errors ) )
					: __( 'The generated World Graph Studio document failed dry-run validation.', 'worldgraph' )
			);
		}

		return true;
	}
}
