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
			$edges[] = [ 'source' => $post->ID, 'target' => absint( $relationship['to_id'] ), 'type' => $relationship['type'] ?? 'related_to' ];
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
	];
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
		$presence[ $id ] = [ 'name' => $name, 'scenes' => [], 'shots' => [] ];
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
					'character_a'  => $characters[ $pair[0] ],
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
						'character_a'  => $characters[ $pair[0] ],
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
				return [ 'name' => $item['name'], 'scenes' => count( $item['scenes'] ), 'shots' => count( $item['shots'] ) ];
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
	return set_transient( 'worldgraph_graph_analytics_' . $project_id, $analytics, $ttl );
}

/**
 * Get cached graph analytics.
 *
 * @param int $project_id Project post ID.
 * @return array|false The cached analytics or false.
 */
function get_cached_graph_analytics( int $project_id = 0 ) {
	return get_transient( 'worldgraph_graph_analytics_' . $project_id );
}

/**
 * Clear cached graph analytics.
 *
 * @param int $project_id Project post ID.
 * @return bool
 */
function clear_cached_graph_analytics( int $project_id = 0 ): bool {
	return delete_transient( 'worldgraph_graph_analytics_' . $project_id );
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
	return set_transient( 'worldgraph_character_network_' . $project_id, $network, $ttl );
}

/**
 * Get cached character network.
 *
 * @param int $project_id Project post ID.
 * @return array|false The cached network or false.
 */
function get_cached_character_network( int $project_id = 0 ) {
	return get_transient( 'worldgraph_character_network_' . $project_id );
}

/**
 * Clear cached character network.
 *
 * @param int $project_id Project post ID.
 * @return bool
 */
function clear_cached_character_network( int $project_id = 0 ): bool {
	return delete_transient( 'worldgraph_character_network_' . $project_id );
}
