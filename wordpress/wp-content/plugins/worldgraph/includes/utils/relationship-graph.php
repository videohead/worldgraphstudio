<?php
/**
 * Relationship Graph Analytics — network analysis over Story Graph entities.
 *
 * Provides relationship analytics from locally-stored WordPress post data including:
 * - Network density and connectivity metrics
 * - Character co-occurrence analysis
 * - Most connected entities
 * - Isolated entity detection
 * - Relationship type distribution
 * - Scene-based subgraph queries
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch relationship graph from local Story Graph.
 *
 * Constructs a relationship graph from WordPress posts and relationships.
 */
function fetch_relationship_graph( array $params = [] ) {
	$nodes = [];
	$edges = [];
	foreach ( get_posts( [ 'post_type' => array_keys( worldgraph_get_all_cpts() ), 'post_status' => 'any', 'posts_per_page' => -1 ] ) as $post ) {
		$nodes[] = [ 'id' => $post->ID, 'type' => $post->post_type, 'title' => $post->post_title ];
		foreach ( get_relationships( $post->ID, $post->post_type ) as $relationship ) {
			$edge = [
				'source' => $post->ID,
				'target' => absint( $relationship['to_id'] ),
				'type'   => $relationship['type'] ?? 'related_to',
			];
			$field = sanitize_key( (string) ( $relationship['metadata']['field'] ?? '' ) );
			if ( '' !== $field ) {
				$edge['field'] = $field;
			}
			$edges[] = $edge;
		}
	}
	$graph = [ 'nodes' => $nodes, 'edges' => $edges ];
	$project_id = absint( $params['project_id'] ?? 0 );

	return $project_id ? filter_relationship_graph_by_project( $graph, $project_id ) : $graph;
}

/**
 * Restrict a graph to entities nearest to one project root.
 *
 * Shared entities equidistant from multiple projects are included in each
 * project, while entities closer to another project are excluded.
 *
 * @param array $graph      Graph containing nodes and edges.
 * @param int   $project_id Selected project post ID.
 * @return array Project-scoped graph.
 */
function filter_relationship_graph_by_project( array $graph, int $project_id ): array {
	$nodes    = array_values( array_filter( $graph['nodes'] ?? [], 'is_array' ) );
	$edges    = array_values( array_filter( $graph['edges'] ?? [], 'is_array' ) );
	$projects = [];
	$adjacent = [];

	foreach ( $nodes as $node ) {
		$id = max( 0, (int) ( $node['id'] ?? 0 ) );
		if ( 'worldgraph_project' === ( $node['type'] ?? '' ) ) {
			$projects[] = $id;
		}
	}

	if ( ! in_array( $project_id, $projects, true ) ) {
		return [ 'nodes' => [], 'edges' => [] ];
	}

	foreach ( $edges as $edge ) {
		$source = max( 0, (int) ( $edge['source'] ?? 0 ) );
		$target = max( 0, (int) ( $edge['target'] ?? 0 ) );
		if ( $source && $target ) {
			$adjacent[ $source ][] = $target;
			$adjacent[ $target ][] = $source;
		}
	}

	$distances = [];
	foreach ( $projects as $root_id ) {
		$distances[ $root_id ] = graph_distances_from_node( $root_id, $adjacent );
	}

	$included = [];
	foreach ( $nodes as $node ) {
		$id                = max( 0, (int) ( $node['id'] ?? 0 ) );
		$selected_distance = $distances[ $project_id ][ $id ] ?? null;
		if ( null === $selected_distance ) {
			continue;
		}

		$nearest_distance = $selected_distance;
		foreach ( $projects as $root_id ) {
			if ( isset( $distances[ $root_id ][ $id ] ) ) {
				$nearest_distance = min( $nearest_distance, $distances[ $root_id ][ $id ] );
			}
		}

		if ( $selected_distance === $nearest_distance ) {
			$included[ $id ] = true;
		}
	}

	return [
		'nodes' => array_values( array_filter( $nodes, static function( array $node ) use ( $included ): bool { return isset( $included[ (int) ( $node['id'] ?? 0 ) ] ); } ) ),
		'edges' => array_values( array_filter( $edges, static function( array $edge ) use ( $included ): bool { return isset( $included[ (int) ( $edge['source'] ?? 0 ) ], $included[ (int) ( $edge['target'] ?? 0 ) ] ); } ) ),
	];
}

/**
 * Find unweighted graph distances from one node.
 *
 * @param int   $start_id Starting node ID.
 * @param array $adjacent Adjacency list.
 * @return array<int, int> Distance by node ID.
 */
function graph_distances_from_node( int $start_id, array $adjacent ): array {
	$distances = [ $start_id => 0 ];
	$queue     = [ $start_id ];

	while ( ! empty( $queue ) ) {
		$current = array_shift( $queue );
		foreach ( $adjacent[ $current ] ?? [] as $neighbor ) {
			if ( isset( $distances[ $neighbor ] ) ) {
				continue;
			}
			$distances[ $neighbor ] = $distances[ $current ] + 1;
			$queue[] = $neighbor;
		}
	}

	return $distances;
}

/**
 * Build graph analytics from local Story Graph relationships.
 *
 * @param array $params Query parameters including required project_id.
 * @return array|WP_Error The graph analytics or error.
 */
function fetch_graph_analytics( array $params = [] ) {
	$project_id = absint( $params['project_id'] ?? 0 );
	if ( ! $project_id || 'worldgraph_project' !== get_post_type( $project_id ) ) {
		return new \WP_Error( 'worldgraph_project_required', 'A valid World Graph Studio project is required for graph analysis.' );
	}

	$graph = fetch_relationship_graph( $params );
	return analyze_relationship_graph( $graph );
}

/**
 * Compute dashboard analytics for a relationship graph.
 *
 * @param array $graph Graph containing nodes and edges.
 * @return array Analytics matching the admin dashboard contract.
 */
function analyze_relationship_graph( array $graph ): array {
	$nodes   = array_values( array_filter( $graph['nodes'] ?? [], 'is_array' ) );
	$edges   = array_values( array_filter( $graph['edges'] ?? [], 'is_array' ) );
	$degrees = [];

	foreach ( $edges as $edge ) {
		$source = max( 0, (int) ( $edge['source'] ?? 0 ) );
		$target = max( 0, (int) ( $edge['target'] ?? 0 ) );
		if ( $source ) {
			$degrees[ $source ] = ( $degrees[ $source ] ?? 0 ) + 1;
		}
		if ( $target ) {
			$degrees[ $target ] = ( $degrees[ $target ] ?? 0 ) + 1;
		}
	}

	$entities = array_map(
		static function( array $node ) use ( $degrees ): array {
			$id = max( 0, (int) ( $node['id'] ?? 0 ) );
			return [
				'id'               => $id,
				'type'             => (string) ( $node['type'] ?? '' ),
				'name'             => (string) ( $node['name'] ?? $node['title'] ?? '' ),
				'connection_count' => $degrees[ $id ] ?? 0,
			];
		},
		$nodes
	);

	usort(
		$entities,
		static function( array $left, array $right ): int {
			return $right['connection_count'] <=> $left['connection_count'];
		}
	);

	$node_count = count( $nodes );
	$edge_count = count( $edges );

	return [
		'total_entities'           => $node_count,
		'total_relationships'      => $edge_count,
		'density'                 => $node_count > 1 ? $edge_count / ( $node_count * ( $node_count - 1 ) ) : 0.0,
		'most_connected'          => $entities,
		'isolated_entities'       => array_values( array_filter( $entities, static function( array $entity ): bool { return 0 === $entity['connection_count']; } ) ),
		'entity_counts'           => array_count_values( array_column( $nodes, 'type' ) ),
		'relationship_distribution' => get_relationship_type_distribution( [ 'edges' => $edges ] ),
		'development'              => build_graph_development_compass( [ 'nodes' => $nodes, 'edges' => $edges ] ),
	];
}

/**
 * Build deterministic story-development prompts from graph structure.
 *
 * The compass reports only explicit graph facts and canonical ownership paths.
 * It does not score the story, infer narrative intent, or alter any entity or
 * relationship.
 *
 * @param array $graph Graph containing nodes and edges.
 * @return array Development phase, opportunities, and existing elements to develop.
 */
function build_graph_development_compass( array $graph ): array {
	$opportunity_limit = 12;
	$foundation_types  = [
		'worldgraph_character' => [
			'id'       => 'character',
			'singular' => __( 'Character', 'worldgraph' ),
			'plural'   => __( 'Characters', 'worldgraph' ),
			'question' => __( 'Who could appear in this story?', 'worldgraph' ),
		],
		'worldgraph_location'  => [
			'id'       => 'location',
			'singular' => __( 'Location', 'worldgraph' ),
			'plural'   => __( 'Locations', 'worldgraph' ),
			'question' => __( 'Where could this story take place?', 'worldgraph' ),
		],
		'worldgraph_scene'     => [
			'id'       => 'scene',
			'singular' => __( 'Scene', 'worldgraph' ),
			'plural'   => __( 'Scenes', 'worldgraph' ),
			'question' => __( 'What event or change could be represented as a Scene?', 'worldgraph' ),
		],
	];
	$element_types     = [
		'worldgraph_character' => [ 'singular' => __( 'Character', 'worldgraph' ) ],
		'worldgraph_location'  => [ 'singular' => __( 'Location', 'worldgraph' ) ],
		'worldgraph_prop'      => [ 'singular' => __( 'Prop', 'worldgraph' ) ],
	];
	$supporting_types  = [
		'worldgraph_shot' => [ 'singular' => __( 'Shot', 'worldgraph' ) ],
	];
	$recognized_types = $foundation_types + $element_types + $supporting_types;
	$nodes_by_type     = [];
	$nodes_by_id       = [];

	foreach ( array_filter( $graph['nodes'] ?? [], 'is_array' ) as $node ) {
		$id   = max( 0, (int) ( $node['id'] ?? 0 ) );
		$type = (string) ( $node['type'] ?? '' );
		if ( ! $id || ! isset( $recognized_types[ $type ] ) ) {
			continue;
		}

		$singular = $recognized_types[ $type ]['singular'];
		$name     = trim( (string) ( $node['name'] ?? $node['title'] ?? '' ) );
		$entity = [
			'id'   => $id,
			'type' => $type,
			/* translators: 1: Story Graph entity type, 2: entity post ID. */
			'name' => '' !== $name ? $name : sprintf( __( '%1$s #%2$d', 'worldgraph' ), $singular, $id ),
		];

		$nodes_by_id[ $id ]       = $entity;
		$nodes_by_type[ $type ][] = $entity;
	}

	foreach ( $nodes_by_type as &$typed_nodes ) {
		usort(
			$typed_nodes,
			static function ( array $left, array $right ): int {
				$id_comparison = $left['id'] <=> $right['id'];
				return 0 !== $id_comparison ? $id_comparison : strcmp( $left['name'], $right['name'] );
			}
		);
	}
	unset( $typed_nodes );

	$scene_context_connections      = [];
	$entities_with_scene_connection = [];
	$named_child_owned_shot_scenes  = [];
	$child_owned_shot_scenes        = [];
	$parent_owned_shot_scenes       = [];
	$shot_scene_connections         = [];
	$graph_edges                    = array_values( array_filter( $graph['edges'] ?? [], 'is_array' ) );
	foreach ( $nodes_by_type['worldgraph_scene'] ?? [] as $scene ) {
		$scene_context_connections[ $scene['id'] ] = [];
	}

	// Resolve Shot ownership first so later element-to-Shot edges can inherit
	// the Scene context regardless of the input edge order or direction.
	foreach ( $graph_edges as $edge ) {
		$source = max( 0, (int) ( $edge['source'] ?? 0 ) );
		$target = max( 0, (int) ( $edge['target'] ?? 0 ) );
		if ( ! isset( $nodes_by_id[ $source ], $nodes_by_id[ $target ] ) ) {
			continue;
		}

		$source_type = $nodes_by_id[ $source ]['type'];
		$target_type = $nodes_by_id[ $target ]['type'];
		$edge_type   = (string) ( $edge['type'] ?? '' );
		if ( 'worldgraph_shot' === $source_type && 'worldgraph_scene' === $target_type && 'belongs_to' === $edge_type ) {
			$child_owned_shot_scenes[ $source ][ $target ] = true;
			if ( 'scene' === sanitize_key( (string) ( $edge['field'] ?? '' ) ) ) {
				$named_child_owned_shot_scenes[ $source ][ $target ] = true;
			}
		} elseif ( 'worldgraph_scene' === $source_type && 'worldgraph_shot' === $target_type && in_array( $edge_type, [ 'contains', 'belongs_to' ], true ) ) {
			$parent_owned_shot_scenes[ $target ][ $source ] = true;
		}
	}
	foreach ( $nodes_by_type['worldgraph_shot'] ?? [] as $shot ) {
		$scene_ids = array_keys(
			$named_child_owned_shot_scenes[ $shot['id'] ]
				?? $child_owned_shot_scenes[ $shot['id'] ]
				?? $parent_owned_shot_scenes[ $shot['id'] ]
				?? []
		);
		sort( $scene_ids, SORT_NUMERIC );
		if ( ! empty( $scene_ids ) ) {
			$shot_scene_connections[ $shot['id'] ] = (int) $scene_ids[0];
		}
	}

	foreach ( $graph_edges as $edge ) {
		$source = max( 0, (int) ( $edge['source'] ?? 0 ) );
		$target = max( 0, (int) ( $edge['target'] ?? 0 ) );
		if ( ! isset( $nodes_by_id[ $source ], $nodes_by_id[ $target ] ) ) {
			continue;
		}

		$source_type = $nodes_by_id[ $source ]['type'];
		$target_type = $nodes_by_id[ $target ]['type'];
		if ( 'worldgraph_scene' === $source_type && isset( $element_types[ $target_type ] ) ) {
			$entities_with_scene_connection[ $target ] = true;
			if ( in_array( $target_type, [ 'worldgraph_character', 'worldgraph_location' ], true ) ) {
				$scene_context_connections[ $source ][ $target_type ][ $target ] = true;
			}
		} elseif ( 'worldgraph_scene' === $target_type && isset( $element_types[ $source_type ] ) ) {
			$entities_with_scene_connection[ $source ] = true;
			if ( in_array( $source_type, [ 'worldgraph_character', 'worldgraph_location' ], true ) ) {
				$scene_context_connections[ $target ][ $source_type ][ $source ] = true;
			}
		}

		$shot_id      = 'worldgraph_shot' === $source_type ? $source : ( 'worldgraph_shot' === $target_type ? $target : 0 );
		$element_id   = $shot_id === $source ? $target : $source;
		$element_type = $nodes_by_id[ $element_id ]['type'] ?? '';
		if ( ! $shot_id || ! isset( $element_types[ $element_type ] ) ) {
			continue;
		}

		$scene_id = (int) ( $shot_scene_connections[ $shot_id ] ?? 0 );
		if ( $scene_id ) {
			$entities_with_scene_connection[ $element_id ] = true;
			if ( 'worldgraph_character' === $element_type ) {
				$scene_context_connections[ $scene_id ][ $element_type ][ $element_id ] = true;
			}
		}
	}

	$opportunities           = [];
	$missing_foundation      = 0;
	$elements_without_scenes = 0;
	$scene_context_gaps      = 0;
	$has_characters          = ! empty( $nodes_by_type['worldgraph_character'] );
	$has_locations           = ! empty( $nodes_by_type['worldgraph_location'] );
	$has_scenes              = ! empty( $nodes_by_type['worldgraph_scene'] );
	$total_opportunities     = 0;
	$add_opportunity         = static function ( array $opportunity ) use ( &$opportunities, &$total_opportunities, $opportunity_limit ): void {
		++$total_opportunities;
		if ( count( $opportunities ) < $opportunity_limit ) {
			$opportunities[] = $opportunity;
		}
	};

	foreach ( $foundation_types as $type => $definition ) {
		$count = count( $nodes_by_type[ $type ] ?? [] );
		if ( 0 < $count ) {
			continue;
		}

		++$missing_foundation;
		$add_opportunity(
			[
				'id'                    => 'missing-foundation-' . $definition['id'],
				'type'                  => 'missing_foundation',
				'priority'              => 'high',
				/* translators: %s: Story Graph entity type. */
				'title'                 => sprintf( __( 'Create a %s', 'worldgraph' ), $definition['singular'] ),
				/* translators: %s: plural Story Graph entity type. */
				'evidence'              => sprintf( __( 'This graph contains 0 %s.', 'worldgraph' ), $definition['plural'] ),
				'question'              => $definition['question'],
				'suggested_entity_type' => $type,
				'entity'                => null,
			]
		);
	}

	foreach ( $has_scenes ? $element_types : [] as $type => $definition ) {
		foreach ( $nodes_by_type[ $type ] ?? [] as $entity ) {
			if ( isset( $entities_with_scene_connection[ $entity['id'] ] ) ) {
				continue;
			}

			++$elements_without_scenes;
			$add_opportunity(
				[
					'id'                    => 'element-without-scene-' . $entity['id'],
					'type'                  => 'element_without_scene',
					'priority'              => 'medium',
					/* translators: %s: Story Graph entity name. */
					'title'                 => sprintf( __( 'Bring %s into a Scene', 'worldgraph' ), $entity['name'] ),
					'evidence'              => sprintf(
						/* translators: 1: entity name, 2: entity type, 3: entity post ID. */
						__( '%1$s (%2$s #%3$d) is represented in 0 Scenes, directly or through a Scene-owned Shot.', 'worldgraph' ),
						$entity['name'],
						$definition['singular'],
						$entity['id']
					),
					'question'              => graph_element_scene_question( $entity ),
					'suggested_entity_type' => 'worldgraph_scene',
					'entity'                => $entity,
				]
			);
		}
	}

	foreach ( $nodes_by_type['worldgraph_scene'] ?? [] as $scene ) {
		$connections = $scene_context_connections[ $scene['id'] ] ?? [];
		if ( $has_characters && empty( $connections['worldgraph_character'] ) ) {
			++$scene_context_gaps;
			$add_opportunity(
				[
					'id'                    => 'scene-missing-character-' . $scene['id'],
					'type'                  => 'scene_missing_character',
					'priority'              => 'medium',
					/* translators: %s: Scene name. */
					'title'                 => sprintf( __( 'Add Character context to %s', 'worldgraph' ), $scene['name'] ),
					'evidence'              => sprintf(
						/* translators: 1: Scene name, 2: Scene post ID. */
						__( '%1$s (Scene #%2$d) includes 0 Characters directly or through its Shots.', 'worldgraph' ),
						$scene['name'],
						$scene['id']
					),
					/* translators: %s: Scene name. */
					'question'              => sprintf( __( 'Who acts, reacts, learns something, or is affected in %s?', 'worldgraph' ), $scene['name'] ),
					'suggested_entity_type' => 'worldgraph_character',
					'entity'                => $scene,
				]
			);
		}

		if ( $has_locations && empty( $connections['worldgraph_location'] ) ) {
			++$scene_context_gaps;
			$add_opportunity(
				[
					'id'                    => 'scene-missing-location-' . $scene['id'],
					'type'                  => 'scene_missing_location',
					'priority'              => 'medium',
					/* translators: %s: Scene name. */
					'title'                 => sprintf( __( 'Add Location context to %s', 'worldgraph' ), $scene['name'] ),
					'evidence'              => sprintf(
						/* translators: 1: Scene name, 2: Scene post ID. */
						__( '%1$s (Scene #%2$d) is connected to 0 Locations.', 'worldgraph' ),
						$scene['name'],
						$scene['id']
					),
					/* translators: %s: Scene name. */
					'question'              => sprintf( __( 'Where does %s take place?', 'worldgraph' ), $scene['name'] ),
					'suggested_entity_type' => 'worldgraph_location',
					'entity'                => $scene,
				]
			);
		}
	}

	if ( 0 === $missing_foundation && 0 === $elements_without_scenes && 0 === $scene_context_gaps ) {
		$add_opportunity(
			[
				'id'                    => 'explore-next-story-event',
				'type'                  => 'next_story_event',
				'priority'              => 'medium',
				'title'                 => __( 'Imagine the next change', 'worldgraph' ),
				'evidence'              => __( 'The current foundation and Scene-coverage checks found no structural gaps.', 'worldgraph' ),
				'question'              => __( 'What changes next, who experiences it, and which new Scene or story element would make that change visible?', 'worldgraph' ),
				'suggested_entity_type' => 'worldgraph_scene',
				'entity'                => null,
			]
		);
	}

	if ( 0 < $missing_foundation ) {
		$phase = [
			'key'     => 'foundation',
			'label'   => __( 'Build the foundation', 'worldgraph' ),
			'summary' => sprintf(
				/* translators: 1: Character count, 2: Location count, 3: Scene count. */
				__( 'This graph contains %1$d Characters, %2$d Locations, and %3$d Scenes.', 'worldgraph' ),
				count( $nodes_by_type['worldgraph_character'] ?? [] ),
				count( $nodes_by_type['worldgraph_location'] ?? [] ),
				count( $nodes_by_type['worldgraph_scene'] ?? [] )
			),
		];
	} elseif ( 0 < $elements_without_scenes || 0 < $scene_context_gaps ) {
		$phase = [
			'key'     => 'connections',
			'label'   => __( 'Connect story elements', 'worldgraph' ),
			'summary' => sprintf(
				/* translators: 1: unrepresented element count, 2: Scene context gap count. */
				__( 'These checks found %1$d elements not represented in a Scene and %2$d Scene context gaps.', 'worldgraph' ),
				$elements_without_scenes,
				$scene_context_gaps
			),
		];
	} else {
		$phase = [
			'key'     => 'review',
			'label'   => __( 'Explore the next change', 'worldgraph' ),
			'summary' => __( 'The structural coverage checks are clear. Use the next-event question to explore what the story could change or reveal.', 'worldgraph' ),
		];
	}

	$elements_by_id      = [];
	$elements_to_develop = [];
	foreach ( $opportunities as $opportunity ) {
		$entity = $opportunity['entity'];
		if ( ! is_array( $entity ) ) {
			continue;
		}

		$element_key = $entity['type'] . ':' . $entity['id'];
		if ( ! isset( $elements_by_id[ $element_key ] ) ) {
			$elements_by_id[ $element_key ] = count( $elements_to_develop );
			$elements_to_develop[] = [
				'id'              => $entity['id'],
				'type'            => $entity['type'],
				'name'            => $entity['name'],
				'priority'        => $opportunity['priority'],
				'opportunity_ids' => [],
			];
		}

		$element_index = $elements_by_id[ $element_key ];
		$elements_to_develop[ $element_index ]['opportunity_ids'][] = $opportunity['id'];
	}

	return [
		'phase'                 => $phase,
		'total_opportunities'   => $total_opportunities,
		'has_more'              => $total_opportunities > count( $opportunities ),
		'opportunities'         => $opportunities,
		'elements_to_develop'   => array_slice( $elements_to_develop, 0, $opportunity_limit ),
	];
}

/**
 * Build an explicit Scene-connection question for an existing story element.
 *
 * @param array $entity Normalized graph entity.
 * @return string Development question.
 */
function graph_element_scene_question( array $entity ): string {
	switch ( $entity['type'] ) {
		case 'worldgraph_character':
			/* translators: %s: Character name. */
			return sprintf( __( 'In which Scene could %s act, learn something, or change?', 'worldgraph' ), $entity['name'] );
		case 'worldgraph_location':
			/* translators: %s: Location name. */
			return sprintf( __( 'Which Scene could take place at or refer to %s, and what changes there?', 'worldgraph' ), $entity['name'] );
		case 'worldgraph_prop':
			/* translators: %s: Prop name. */
			return sprintf( __( 'Which Scene could introduce, use, transfer, or transform %s?', 'worldgraph' ), $entity['name'] );
		default:
			/* translators: %s: Story Graph entity name. */
			return sprintf( __( 'Which Scene could connect to %s?', 'worldgraph' ), $entity['name'] );
	}
}

/**
 * Build character network analytics from local Story Graph relationships.
 *
 * @param array $params Query parameters.
 * @return array|WP_Error The character network or error.
 */
function fetch_character_network( array $params = [] ) {
	$project_id = absint( $params['project_id'] ?? 0 );
	if ( ! $project_id || 'worldgraph_project' !== get_post_type( $project_id ) ) {
		return new \WP_Error( 'worldgraph_project_required', 'A valid World Graph Studio project is required for character network analysis.' );
	}

	$graph = fetch_relationship_graph( $params );
	return analyze_character_network( $graph, absint( $params['limit'] ?? 0 ) );
}

/**
 * Compute character relationships and scene presence from a graph.
 *
 * @param array $graph Graph containing nodes and edges.
 * @param int   $limit Optional result limit.
 * @return array Character network matching the admin dashboard contract.
 */
function analyze_character_network( array $graph, int $limit = 0 ): array {
	$nodes      = array_values( array_filter( $graph['nodes'] ?? [], 'is_array' ) );
	$edges      = array_values( array_filter( $graph['edges'] ?? [], 'is_array' ) );
	$node_types = [];
	$characters = [];

	foreach ( $nodes as $node ) {
		$id                = max( 0, (int) ( $node['id'] ?? 0 ) );
		$node_types[ $id ] = (string) ( $node['type'] ?? '' );
		if ( 'worldgraph_character' === $node_types[ $id ] ) {
			$characters[ $id ] = (string) ( $node['name'] ?? $node['title'] ?? '' );
		}
	}

	$character_edges = [];
	$scene_characters = [];
	$presence = [];
	$relationships = [];
	foreach ( $characters as $id => $name ) {
		$presence[ $id ] = [ 'id' => $id, 'name' => $name, 'scenes' => [], 'shots' => [] ];
	}

	foreach ( $edges as $edge ) {
		$source      = max( 0, (int) ( $edge['source'] ?? 0 ) );
		$target      = max( 0, (int) ( $edge['target'] ?? 0 ) );
		$source_type = $node_types[ $source ] ?? '';
		$target_type = $node_types[ $target ] ?? '';
		$type        = (string) ( $edge['type'] ?? 'related_to' );

		if ( isset( $characters[ $source ], $characters[ $target ] ) ) {
			$character_edges[] = $edge;
			$pair = [ min( $source, $target ), max( $source, $target ) ];
			$key  = implode( ':', $pair );
			if ( ! isset( $relationships[ $key ] ) ) {
				$relationships[ $key ] = [
					'character_a_id' => $pair[0],
					'character_a'  => $characters[ $pair[0] ],
					'character_b_id' => $pair[1],
					'character_b'  => $characters[ $pair[1] ],
					'relationship' => ucwords( str_replace( '_', ' ', $type ) ),
					'cooccurrences' => 0,
				];
			}
		}

		$character_id = isset( $characters[ $source ] ) ? $source : ( isset( $characters[ $target ] ) ? $target : 0 );
		$entity_id    = $character_id === $source ? $target : $source;
		$entity_type  = $node_types[ $entity_id ] ?? '';
		if ( $character_id && in_array( $entity_type, [ 'worldgraph_scene', 'worldgraph_shot' ], true ) ) {
			$bucket = 'worldgraph_scene' === $entity_type ? 'scenes' : 'shots';
			$presence[ $character_id ][ $bucket ][ $entity_id ] = true;
			if ( 'worldgraph_scene' === $entity_type ) {
				$scene_characters[ $entity_id ][ $character_id ] = true;
			}
		}
	}

	foreach ( $scene_characters as $scene_character_ids ) {
		$ids = array_keys( $scene_character_ids );
		for ( $left = 0, $count = count( $ids ); $left < $count; $left++ ) {
			for ( $right = $left + 1; $right < $count; $right++ ) {
				$pair = [ min( $ids[ $left ], $ids[ $right ] ), max( $ids[ $left ], $ids[ $right ] ) ];
				$key  = implode( ':', $pair );
				if ( ! isset( $relationships[ $key ] ) ) {
					$relationships[ $key ] = [
						'character_a_id' => $pair[0],
						'character_a'  => $characters[ $pair[0] ],
						'character_b_id' => $pair[1],
						'character_b'  => $characters[ $pair[1] ],
						'relationship' => 'Appears Together',
						'cooccurrences' => 0,
					];
				}
				$relationships[ $key ]['cooccurrences']++;
			}
		}
	}

	$relationships = array_values( $relationships );
	usort( $relationships, static function( array $left, array $right ): int { return $right['cooccurrences'] <=> $left['cooccurrences']; } );
	$scene_presence = array_values(
		array_map(
			static function( array $item ): array {
				return [ 'id' => $item['id'], 'name' => $item['name'], 'scenes' => count( $item['scenes'] ), 'shots' => count( $item['shots'] ) ];
			},
			$presence
		)
	);

	if ( $limit > 0 ) {
		$relationships = array_slice( $relationships, 0, $limit );
		$scene_presence = array_slice( $scene_presence, 0, $limit );
	}

	return [
		'nodes'                    => array_values( array_filter( $nodes, static function( array $node ): bool { return 'worldgraph_character' === ( $node['type'] ?? '' ); } ) ),
		'edges'                    => $character_edges,
		'strongest_relationships'  => $relationships,
		'character_scene_presence' => $scene_presence,
		'cooccurrence_data'        => array_values( array_filter( $relationships, static function( array $item ): bool { return $item['cooccurrences'] > 0; } ) ),
	];
}

/**
 * Build relationship counts for specific characters.
 *
 * @param array $character_ids List of character post IDs.
 * @return array|WP_Error The character analytics or error.
 */
function fetch_character_analytics( array $character_ids = [] ) {
	$graph = fetch_relationship_graph();
	$ids = empty( $character_ids ) ? array_column( array_filter( $graph['nodes'], static function ( $node ) { return 'worldgraph_character' === $node['type']; } ), 'id' ) : array_map( 'absint', $character_ids );
	$analytics = [];
	foreach ( $ids as $id ) { $analytics[] = [ 'character_id' => $id, 'relationship_count' => count( array_filter( $graph['edges'], static function ( $edge ) use ( $id ) { return $edge['source'] === $id || $edge['target'] === $id; } ) ) ]; }
	return [ 'characters' => $analytics ];
}

/**
 * Compute network density from analytics data.
 *
 * @param array $analytics The graph analytics data.
 * @return float Network density (0.0 to 1.0).
 */
function compute_network_density( array $analytics ): float {
	if ( empty( $analytics['density'] ) ) {
		return 0.0;
	}
	return (float) $analytics['density'];
}

/**
 * Get relationship type distribution.
 *
 * @param array $graph The relationship graph data.
 * @return array Distribution by relationship type.
 */
function get_relationship_type_distribution( array $graph ): array {
	$distribution = [];

	if ( empty( $graph['edges'] ) || ! is_array( $graph['edges'] ) ) {
		return $distribution;
	}

	foreach ( $graph['edges'] as $edge ) {
		$type = $edge['type'] ?? 'unknown';
		if ( ! isset( $distribution[ $type ] ) ) {
			$distribution[ $type ] = 0;
		}
		$distribution[ $type ]++;
	}

	return $distribution;
}

/**
 * Get most connected entities from analytics.
 *
 * @param array $analytics The graph analytics data.
 * @param int   $limit Maximum number of entities to return.
 * @return array List of most connected entities.
 */
function get_most_connected_entities( array $analytics, int $limit = 10 ): array {
	if ( empty( $analytics['most_connected'] ) || ! is_array( $analytics['most_connected'] ) ) {
		return [];
	}

	return array_slice( $analytics['most_connected'], 0, $limit );
}

/**
 * Get isolated entities from analytics.
 *
 * @param array $analytics The graph analytics data.
 * @return array List of isolated entities.
 */
function get_isolated_entities( array $analytics ): array {
	return $analytics['isolated_entities'] ?? [];
}

/**
 * Get entity counts by type.
 *
 * @param array $analytics The graph analytics data.
 * @return array Entity counts.
 */
function get_entity_counts( array $analytics ): array {
	return $analytics['entity_counts'] ?? [];
}

/**
 * Get total entity count.
 *
 * @param array $analytics The graph analytics data.
 * @return int Total entities.
 */
function get_total_entities( array $analytics ): int {
	return (int) ( $analytics['total_entities'] ?? 0 );
}

/**
 * Get total relationship count.
 *
 * @param array $analytics The graph analytics data.
 * @return int Total relationships.
 */
function get_total_relationships( array $analytics ): int {
	return (int) ( $analytics['total_relationships'] ?? 0 );
}

/**
 * Get strongest character relationships.
 *
 * @param array $network The character network data.
 * @param int   $limit Maximum number of relationships to return.
 * @return array Strongest relationships.
 */
function get_strongest_relationships( array $network, int $limit = 10 ): array {
	return $network['strongest_relationships'] ?? [];
}

/**
 * Get character scene presence.
 *
 * @param array $network The character network data.
 * @return array Character scene presence data.
 */
function get_character_scene_presence( array $network ): array {
	return $network['character_scene_presence'] ?? [];
}

/**
 * Get character co-occurrence data.
 *
 * @param array $network The character network data.
 * @return array Co-occurrence data.
 */
function get_character_cooccurrence( array $network ): array {
	return $network['cooccurrence_data'] ?? [];
}

/**
 * Get entity display name for graph analytics.
 *
 * @param int    $post_id The post ID.
 * @param string $post_type The post type slug.
 * @return string The entity display name.
 */
if ( ! function_exists( __NAMESPACE__ . '\graph_entity_display_name' ) ) :
function graph_entity_display_name( int $post_id, string $post_type ): string {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return sprintf( '%s #%d (deleted)', $post_type, $post_id );
	}

	$title = get_the_title( $post_id );
	if ( empty( $title ) ) {
		return sprintf( '%s #%d', $post_type, $post_id );
	}

	return $title;
}
endif;

/**
 * Get entity permalink for graph analytics.
 *
 * @param int    $post_id The post ID.
 * @param string $post_type The post type slug.
 * @return string The entity permalink.
 */
if ( ! function_exists( __NAMESPACE__ . '\graph_entity_permalink' ) ) :
function graph_entity_permalink( int $post_id, string $post_type ): string {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return '#';
	}

	return get_permalink( $post_id );
}
endif;

/**
 * Get relationship type label.
 *
 * @param string $type The relationship type slug.
 * @return string The human-readable label.
 */
function relationship_type_label( string $type ): string {
	$types = relationship_types();
	return $types[ $type ] ?? $type;
}

/**
 * Cache graph analytics locally.
 *
 * @param array $analytics The analytics data.
 * @param int   $ttl        Cache TTL in seconds (default: 3600).
 * @param int   $project_id Project post ID.
 * @return bool
 */
function cache_graph_analytics( array $analytics, int $ttl = 3600, int $project_id = 0 ): bool {
	return set_transient( graph_analytics_cache_key( 'worldgraph_graph_analytics_', $project_id ), $analytics, $ttl );
}

/**
 * Get cached graph analytics.
 *
 * @param int $project_id Project post ID.
 * @return array|false The cached analytics or false.
 */
function get_cached_graph_analytics( int $project_id = 0 ) {
	return get_transient( graph_analytics_cache_key( 'worldgraph_graph_analytics_', $project_id ) );
}

/**
 * Clear cached graph analytics.
 *
 * @param int $project_id Project post ID.
 * @return bool
 */
function clear_cached_graph_analytics( int $project_id = 0 ): bool {
	$cleared_current = delete_transient( graph_analytics_cache_key( 'worldgraph_graph_analytics_', $project_id ) );
	$cleared_legacy  = delete_transient( 'worldgraph_graph_analytics_' . $project_id );
	return $cleared_current || $cleared_legacy;
}

/**
 * Cache character network locally.
 *
 * @param array $network The network data.
 * @param int   $ttl        Cache TTL in seconds (default: 3600).
 * @param int   $project_id Project post ID.
 * @return bool
 */
function cache_character_network( array $network, int $ttl = 3600, int $project_id = 0 ): bool {
	return set_transient( graph_analytics_cache_key( 'worldgraph_character_network_', $project_id ), $network, $ttl );
}

/**
 * Get cached character network.
 *
 * @param int $project_id Project post ID.
 * @return array|false The cached network or false.
 */
function get_cached_character_network( int $project_id = 0 ) {
	return get_transient( graph_analytics_cache_key( 'worldgraph_character_network_', $project_id ) );
}

/**
 * Clear cached character network.
 *
 * @param int $project_id Project post ID.
 * @return bool
 */
function clear_cached_character_network( int $project_id = 0 ): bool {
	$cleared_current = delete_transient( graph_analytics_cache_key( 'worldgraph_character_network_', $project_id ) );
	$cleared_legacy  = delete_transient( 'worldgraph_character_network_' . $project_id );
	return $cleared_current || $cleared_legacy;
}

/**
 * Build a versioned analytics transient key.
 *
 * A shared version makes all project aggregates unreachable as soon as any
 * canonical Story Graph post or relationship changes. Old transients then
 * expire naturally without requiring an unbounded project-key scan.
 *
 * @param string $prefix     Cache namespace.
 * @param int    $project_id Project post ID.
 * @return string Transient key.
 */
function graph_analytics_cache_key( string $prefix, int $project_id ): string {
	$version = max( 1, (int) get_option( 'worldgraph_graph_analytics_cache_version', 1 ) );
	return $prefix . $version . '_' . max( 0, $project_id );
}

/**
 * Invalidate cached analytics after a Story Graph post changes.
 *
 * @param int $post_id Optional post ID supplied by a WordPress hook.
 */
function invalidate_story_graph_analytics_cache( int $post_id = 0 ): void {
	if ( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! isset( worldgraph_get_all_cpts()[ $post_type ] ) ) {
			return;
		}
	}

	$version = max( 1, (int) get_option( 'worldgraph_graph_analytics_cache_version', 1 ) );
	update_option( 'worldgraph_graph_analytics_cache_version', $version + 1, false );
}

/**
 * Invalidate analytics when the canonical relationship meta changes.
 *
 * @param int|array<int, int> $meta_id Metadata row ID, or IDs after deletion.
 * @param int                 $post_id Post ID.
 * @param string              $meta_key Metadata key.
 */
function maybe_invalidate_story_graph_analytics_meta_cache( int|array $meta_id, int $post_id, string $meta_key ): void {
	unset( $meta_id );
	if ( WORLDGRAPH_CPT_PREFIX . 'relationships' === $meta_key ) {
		invalidate_story_graph_analytics_cache( $post_id );
	}
}

/** Register cache invalidation hooks for local Story Graph analytics. */
function relationship_graph_cache_init(): void {
	add_action( 'save_post', __NAMESPACE__ . '\\invalidate_story_graph_analytics_cache' );
	add_action( 'before_delete_post', __NAMESPACE__ . '\\invalidate_story_graph_analytics_cache' );
	add_action( 'added_post_meta', __NAMESPACE__ . '\\maybe_invalidate_story_graph_analytics_meta_cache', 10, 3 );
	add_action( 'updated_post_meta', __NAMESPACE__ . '\\maybe_invalidate_story_graph_analytics_meta_cache', 10, 3 );
	add_action( 'deleted_post_meta', __NAMESPACE__ . '\\maybe_invalidate_story_graph_analytics_meta_cache', 10, 3 );
}
