<?php
/**
 * Tests for Story Graph analytics.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/**
 * Class Test_WorldGraph_Relationship_Graph.
 */
class Test_WorldGraph_Relationship_Graph extends TestCase {

	/**
	 * Analytics include totals and dashboard-ready entities.
	 */
	public function test_graph_analytics_match_dashboard_contract() {
		$analytics = \WorldGraph\Utils\analyze_relationship_graph( $this->graph() );

		$this->assertSame( 5, $analytics['total_entities'] );
		$this->assertSame( 5, $analytics['total_relationships'] );
		$this->assertSame( 'Ada', $analytics['most_connected'][0]['name'] );
		$this->assertSame( 3, $analytics['most_connected'][0]['connection_count'] );
		$this->assertSame( 1, $analytics['relationship_distribution']['related_to'] );
		$this->assertCount( 0, $analytics['isolated_entities'] );
		$this->assertSame( 'review', $analytics['development']['phase']['key'] );
		$this->assertSame( [ 'explore-next-story-event' ], array_column( $analytics['development']['opportunities'], 'id' ) );
	}

	/**
	 * Development guidance identifies missing foundation entity types.
	 */
	public function test_development_compass_identifies_missing_foundation() {
		$analytics     = \WorldGraph\Utils\analyze_relationship_graph( [ 'nodes' => [], 'edges' => [] ] );
		$development   = $analytics['development'];
		$opportunities = $development['opportunities'];

		$this->assertSame( 'foundation', $development['phase']['key'] );
		$this->assertSame(
			[
				'missing-foundation-character',
				'missing-foundation-location',
				'missing-foundation-scene',
			],
			array_column( $opportunities, 'id' )
		);
		$this->assertSame( [ 'high', 'high', 'high' ], array_column( $opportunities, 'priority' ) );
		$this->assertSame(
			[ 'worldgraph_character', 'worldgraph_location', 'worldgraph_scene' ],
			array_column( $opportunities, 'suggested_entity_type' )
		);
		$this->assertSame( 'This graph contains 0 Characters.', $opportunities[0]['evidence'] );
		$this->assertSame( 3, $development['total_opportunities'] );
		$this->assertFalse( $development['has_more'] );
		$this->assertStringEndsWith( '?', $opportunities[0]['question'] );
		$this->assertNull( $opportunities[0]['entity'] );
		$this->assertSame( [], $development['elements_to_develop'] );
	}

	/**
	 * Foundation guidance does not flood the board with dependent prompts.
	 */
	public function test_development_compass_suppresses_dependent_prompts_until_scenes_exist() {
		$development = \WorldGraph\Utils\analyze_relationship_graph(
			[
				'nodes' => [
					[ 'id' => 1, 'type' => 'worldgraph_character', 'title' => 'Ada' ],
					[ 'id' => 2, 'type' => 'worldgraph_location', 'title' => 'Station' ],
					[ 'id' => 3, 'type' => 'worldgraph_prop', 'title' => 'Key' ],
				],
				'edges' => [],
			]
		)['development'];

		$this->assertSame( 'foundation', $development['phase']['key'] );
		$this->assertSame( [ 'missing-foundation-scene' ], array_column( $development['opportunities'], 'id' ) );
		$this->assertSame( 1, $development['total_opportunities'] );
		$this->assertSame( [], $development['elements_to_develop'] );
	}

	/**
	 * Development guidance exposes unconnected elements and missing Scene context.
	 */
	public function test_development_compass_identifies_underexposed_elements_deterministically() {
		$graph = $this->underexposed_graph();

		$development          = \WorldGraph\Utils\analyze_relationship_graph( $graph )['development'];
		$graph['nodes']       = array_reverse( $graph['nodes'] );
		$graph['edges']       = array_reverse( $graph['edges'] );
		$reversed_development = \WorldGraph\Utils\analyze_relationship_graph( $graph )['development'];

		$this->assertSame( $development, $reversed_development );
		$this->assertSame( 'connections', $development['phase']['key'] );
		$this->assertSame(
			[
				'element-without-scene-2',
				'element-without-scene-5',
				'element-without-scene-6',
				'scene-missing-character-7',
				'scene-missing-location-7',
			],
			array_column( $development['opportunities'], 'id' )
		);
		$this->assertSame(
			[
				'worldgraph_scene',
				'worldgraph_scene',
				'worldgraph_scene',
				'worldgraph_character',
				'worldgraph_location',
			],
			array_column( $development['opportunities'], 'suggested_entity_type' )
		);
		$this->assertSame( [ 2, 5, 6, 7 ], array_column( $development['elements_to_develop'], 'id' ) );
		$this->assertSame(
			[ 'scene-missing-character-7', 'scene-missing-location-7' ],
			$development['elements_to_develop'][3]['opportunity_ids']
		);
		foreach ( $development['opportunities'] as $opportunity ) {
			$this->assertStringEndsWith( '?', $opportunity['question'] );
			$this->assertArrayNotHasKey( 'url', $opportunity );
		}
	}

	/**
	 * A Story element exposed in a Scene-owned Shot is covered by that Scene.
	 */
	public function test_development_compass_resolves_scene_exposure_through_shots() {
		$base_nodes = [
			[ 'id' => 1, 'type' => 'worldgraph_character', 'title' => 'Ada' ],
			[ 'id' => 2, 'type' => 'worldgraph_location', 'title' => 'Station' ],
			[ 'id' => 3, 'type' => 'worldgraph_prop', 'title' => 'Key' ],
			[ 'id' => 4, 'type' => 'worldgraph_scene', 'title' => 'The Arrival' ],
			[ 'id' => 5, 'type' => 'worldgraph_shot', 'title' => 'Close-up' ],
		];
		$element_edges = [
			[ 'source' => 1, 'target' => 5, 'type' => 'appears_in' ],
			[ 'source' => 3, 'target' => 5, 'type' => 'appears_in' ],
			[ 'source' => 4, 'target' => 2, 'type' => 'located_in' ],
		];

		foreach (
			[
				[ 'source' => 4, 'target' => 5, 'type' => 'contains' ],
				[ 'source' => 5, 'target' => 4, 'type' => 'belongs_to' ],
			] as $ownership_edge
		) {
			$development = \WorldGraph\Utils\analyze_relationship_graph(
				[
					'nodes' => $base_nodes,
					'edges' => array_merge( [ $ownership_edge ], $element_edges ),
				]
			)['development'];

			$this->assertSame( 'review', $development['phase']['key'] );
			$this->assertSame( [ 'explore-next-story-event' ], array_column( $development['opportunities'], 'id' ) );
			$this->assertSame( 1, $development['total_opportunities'] );
		}
	}

	/**
	 * Shot Location exposure does not replace a Scene's direct Location context.
	 */
	public function test_development_compass_keeps_scene_location_context_direct() {
		$development = \WorldGraph\Utils\analyze_relationship_graph(
			[
				'nodes' => [
					[ 'id' => 1, 'type' => 'worldgraph_character', 'title' => 'Ada' ],
					[ 'id' => 2, 'type' => 'worldgraph_location', 'title' => 'Station' ],
					[ 'id' => 3, 'type' => 'worldgraph_scene', 'title' => 'The Arrival' ],
					[ 'id' => 4, 'type' => 'worldgraph_shot', 'title' => 'Close-up' ],
				],
				'edges' => [
					[ 'source' => 1, 'target' => 3, 'type' => 'appears_in' ],
					[ 'source' => 2, 'target' => 4, 'type' => 'appears_in' ],
					[ 'source' => 4, 'target' => 3, 'type' => 'belongs_to' ],
				],
			]
		)['development'];

		$this->assertSame( [ 'scene-missing-location-3' ], array_column( $development['opportunities'], 'id' ) );
		$this->assertSame( [ 3 ], array_column( $development['elements_to_develop'], 'id' ) );
	}

	/**
	 * A named child-owned Scene wins over lower-ID and parent-owned stale edges.
	 */
	public function test_development_compass_uses_one_canonical_shot_owner() {
		$development = \WorldGraph\Utils\analyze_relationship_graph(
			[
				'nodes' => [
					[ 'id' => 1, 'type' => 'worldgraph_character', 'title' => 'Ada' ],
					[ 'id' => 2, 'type' => 'worldgraph_location', 'title' => 'Station' ],
					[ 'id' => 10, 'type' => 'worldgraph_scene', 'title' => 'Old Owner' ],
					[ 'id' => 20, 'type' => 'worldgraph_scene', 'title' => 'Canonical Owner' ],
					[ 'id' => 30, 'type' => 'worldgraph_shot', 'title' => 'Close-up' ],
				],
				'edges' => [
					[ 'source' => 1, 'target' => 30, 'type' => 'appears_in' ],
					[ 'source' => 30, 'target' => 10, 'type' => 'belongs_to' ],
					[ 'source' => 30, 'target' => 20, 'type' => 'belongs_to', 'field' => 'scene' ],
					[ 'source' => 10, 'target' => 30, 'type' => 'contains' ],
					[ 'source' => 2, 'target' => 10, 'type' => 'located_in' ],
					[ 'source' => 2, 'target' => 20, 'type' => 'located_in' ],
				],
			]
		)['development'];

		$this->assertSame( [ 'scene-missing-character-10' ], array_column( $development['opportunities'], 'id' ) );
	}

	/**
	 * Development guidance remains bounded for larger graphs.
	 */
	public function test_development_compass_bounds_opportunities() {
		$nodes = [
			[ 'id' => 100, 'type' => 'worldgraph_location', 'title' => 'Station' ],
			[ 'id' => 200, 'type' => 'worldgraph_scene', 'title' => 'The Arrival' ],
		];
		for ( $id = 1; $id <= 20; ++$id ) {
			$nodes[] = [ 'id' => $id, 'type' => 'worldgraph_character', 'title' => 'Character ' . $id ];
		}

		$development = \WorldGraph\Utils\analyze_relationship_graph(
			[
				'nodes' => $nodes,
				'edges' => [
					[ 'source' => 100, 'target' => 200, 'type' => 'located_in' ],
				],
			]
		)['development'];

		$this->assertCount( 12, $development['opportunities'] );
		$this->assertCount( 12, $development['elements_to_develop'] );
		$this->assertSame( 21, $development['total_opportunities'] );
		$this->assertTrue( $development['has_more'] );
		$this->assertSame( 'element-without-scene-1', $development['opportunities'][0]['id'] );
		$this->assertSame( 'element-without-scene-12', $development['opportunities'][11]['id'] );
	}

	/**
	 * Character analytics combine direct links and shared scenes.
	 */
	public function test_character_network_includes_relationships_and_presence() {
		$network = \WorldGraph\Utils\analyze_character_network( $this->graph() );

		$this->assertCount( 2, $network['nodes'] );
		$this->assertSame( 'Ada', $network['strongest_relationships'][0]['character_a'] );
		$this->assertSame( 'Ben', $network['strongest_relationships'][0]['character_b'] );
		$this->assertSame( 1, $network['strongest_relationships'][0]['cooccurrences'] );
		$this->assertSame( 'Related To', $network['strongest_relationships'][0]['relationship'] );
		$this->assertSame( [ 'name' => 'Ada', 'scenes' => 1, 'shots' => 1 ], $network['character_scene_presence'][0] );
	}

	/**
	 * Project filtering excludes entities owned by a nearer project.
	 */
	public function test_project_filter_prevents_cross_project_leakage() {
		$graph = $this->graph();
		$graph['nodes'][] = [ 'id' => 10, 'type' => 'worldgraph_project', 'title' => 'Project A' ];
		$graph['nodes'][] = [ 'id' => 11, 'type' => 'worldgraph_project', 'title' => 'Project B' ];
		$graph['nodes'][] = [ 'id' => 12, 'type' => 'worldgraph_world', 'title' => 'World A' ];
		$graph['nodes'][] = [ 'id' => 13, 'type' => 'worldgraph_world', 'title' => 'World B' ];
		$graph['edges'][] = [ 'source' => 10, 'target' => 12, 'type' => 'contains' ];
		$graph['edges'][] = [ 'source' => 11, 'target' => 13, 'type' => 'contains' ];
		$graph['edges'][] = [ 'source' => 12, 'target' => 1, 'type' => 'contains' ];
		$graph['edges'][] = [ 'source' => 13, 'target' => 2, 'type' => 'contains' ];

		$filtered = \WorldGraph\Utils\filter_relationship_graph_by_project( $graph, 10 );
		$ids      = array_column( $filtered['nodes'], 'id' );

		$this->assertContains( 1, $ids );
		$this->assertContains( 3, $ids );
		$this->assertNotContains( 2, $ids );
		$this->assertNotContains( 11, $ids );
		$this->assertNotContains( 13, $ids );
	}

	/**
	 * Fixture graph.
	 *
	 * @return array
	 */
	private function graph(): array {
		return [
			'nodes' => [
				[ 'id' => 1, 'type' => 'worldgraph_character', 'title' => 'Ada' ],
				[ 'id' => 2, 'type' => 'worldgraph_character', 'title' => 'Ben' ],
				[ 'id' => 3, 'type' => 'worldgraph_scene', 'title' => 'The Arrival' ],
				[ 'id' => 4, 'type' => 'worldgraph_shot', 'title' => 'Close-up' ],
				[ 'id' => 5, 'type' => 'worldgraph_location', 'title' => 'Station' ],
			],
			'edges' => [
				[ 'source' => 1, 'target' => 2, 'type' => 'related_to' ],
				[ 'source' => 1, 'target' => 3, 'type' => 'appears_in' ],
				[ 'source' => 2, 'target' => 3, 'type' => 'appears_in' ],
				[ 'source' => 1, 'target' => 4, 'type' => 'appears_in' ],
				[ 'source' => 3, 'target' => 5, 'type' => 'located_in' ],
			],
		];
	}

	/**
	 * Fixture graph containing existing elements without direct Scene links.
	 *
	 * @return array
	 */
	private function underexposed_graph(): array {
		return [
			'nodes' => [
				[ 'id' => 1, 'type' => 'worldgraph_character', 'title' => 'Ada' ],
				[ 'id' => 2, 'type' => 'worldgraph_character', 'title' => 'Ben' ],
				[ 'id' => 3, 'type' => 'worldgraph_scene', 'title' => 'The Arrival' ],
				[ 'id' => 4, 'type' => 'worldgraph_location', 'title' => 'Station' ],
				[ 'id' => 5, 'type' => 'worldgraph_location', 'title' => 'Warehouse' ],
				[ 'id' => 6, 'type' => 'worldgraph_prop', 'title' => 'Key' ],
				[ 'id' => 7, 'type' => 'worldgraph_scene', 'title' => 'After Hours' ],
			],
			'edges' => [
				[ 'source' => 3, 'target' => 1, 'type' => 'appears_in' ],
				[ 'source' => 4, 'target' => 3, 'type' => 'located_in' ],
			],
		];
	}
}
