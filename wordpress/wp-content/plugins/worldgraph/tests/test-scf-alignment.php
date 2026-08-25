<?php
/**
 * Tests for the World Graph Studio Secure Custom Fields schema archive.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/**
 * Verify the committed Local JSON contract without requiring WordPress.
 */
class Test_WorldGraph_SCF_Alignment extends TestCase {

	/**
	 * Load archived field groups, keyed by their SCF group key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_groups(): array {
		$groups = [];
		$files  = glob( dirname( __DIR__ ) . '/acf-json/group_worldgraph_*.json' );
		$this->assertIsArray( $files );

		foreach ( $files as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true );
			$this->assertSame( JSON_ERROR_NONE, json_last_error(), "Invalid SCF JSON in {$file}" );
			$this->assertIsArray( $decoded );
			$this->assertNotEmpty( $decoded['key'] ?? '', "Missing SCF group key in {$file}" );
			$groups[ $decoded['key'] ] = $decoded;
		}

		return $groups;
	}

	/**
	 * Every World Graph Studio CPT has one portable SCF group at its exact post-type
	 * location. Connection control-plane fields stay off native public REST.
	 */
	public function test_every_cpt_has_an_archived_scf_group(): void {
		$groups = $this->get_groups();
		$cpts   = \WorldGraph\Utils\worldgraph_get_all_cpts();
		$this->assertCount( count( $cpts ), $groups );

		foreach ( array_keys( $cpts ) as $cpt ) {
			$key = \WorldGraph\Utils\SCF_Fields::group_key( $cpt );
			$this->assertArrayHasKey( $key, $groups, "Missing SCF group for {$cpt}" );
			$this->assertSame( $cpt, $groups[ $key ]['location'][0][0]['value'] ?? null );
			$this->assertSame( 'post_type', $groups[ $key ]['location'][0][0]['param'] ?? null );
			$expected_rest_visibility = 'worldgraph_conn' === $cpt ? 0 : 1;
			$this->assertSame( $expected_rest_visibility, $groups[ $key ]['show_in_rest'] ?? null );
			$this->assertTrue( $groups[ $key ]['active'] ?? false );
			$this->assertArrayNotHasKey( 'local_file', $groups[ $key ], 'Local JSON must not archive machine-specific paths.' );
		}
	}

	/**
	 * Archived groups retain every canonical field while allowing SCF-managed
	 * extension fields.
	 */
	public function test_archived_fields_match_the_cpt_manifest(): void {
		$groups = $this->get_groups();

		foreach ( array_keys( \WorldGraph\Utils\worldgraph_get_all_cpts() ) as $cpt ) {
			$group = $groups[ \WorldGraph\Utils\SCF_Fields::group_key( $cpt ) ];
			$names = array_values( array_map( static function( array $field ): string {
				return (string) ( $field['name'] ?? '' );
			}, (array) $group['fields'] ) );

			$missing = array_diff( \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( $cpt ), $names );
			$this->assertSame( [], array_values( $missing ), "SCF fields for {$cpt} are missing canonical metadata." );
		}
	}

	/**
	 * Canonical SCF field keys are deterministic and all archived keys are
	 * globally unique. Extension fields keep the keys assigned by SCF.
	 */
	public function test_archived_field_keys_are_stable_and_unique(): void {
		$keys = [];
		foreach ( $this->get_groups() as $group ) {
			$cpt = (string) $group['location'][0][0]['value'];
			$core_fields = \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( $cpt );
			foreach ( (array) $group['fields'] as $field ) {
				if ( in_array( (string) $field['name'], $core_fields, true ) ) {
					$this->assertSame(
						\WorldGraph\Utils\SCF_Fields::field_key( $cpt, (string) $field['name'] ),
						$field['key']
					);
				}

				$this->assertNotEmpty( $field['key'] ?? '' );
				$this->assertNotContains( $field['key'], $keys, "Duplicate SCF field key {$field['key']}" );
				$keys[] = $field['key'];
			}
		}
	}

	/**
	 * Verify the conversions that affect persistence outside ordinary post meta.
	 */
	public function test_archived_complex_fields_use_scf_storage_contracts(): void {
		$groups  = $this->get_groups();
		$project = array_column( $groups['group_worldgraph_project']['fields'], null, 'name' );
		$scene   = array_column( $groups['group_worldgraph_scene']['fields'], null, 'name' );

		$this->assertSame( 'date_picker', $project['start_date']['type'] );
		$this->assertSame( 'Y-m-d', $project['start_date']['return_format'] );
		$this->assertSame( 'taxonomy', $project['genre']['type'] );
		$this->assertSame( 1, $project['genre']['load_terms'] );
		$this->assertSame( 1, $project['genre']['save_terms'] );
		$this->assertSame( 'relationship', $project['associates']['type'] );
		$this->assertSame( [ 'worldgraph_character' ], $project['associates']['post_type'] );
		$this->assertSame( 'repeater', $scene['dialogue']['type'] );
		$this->assertSame(
			[ 'speaker', 'line', 'description', 'sequence' ],
			array_values( array_column( $scene['dialogue']['sub_fields'], 'name' ) )
		);
	}
}
