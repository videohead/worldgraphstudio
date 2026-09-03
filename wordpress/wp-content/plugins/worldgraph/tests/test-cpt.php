<?php
/**
 * Tests for World Graph Studio CPT registration.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/**
 * Class Test_WorldGraph_CPT
 */
class Test_WorldGraph_CPT extends TestCase {

	/**
	 * Test register_cpt function signature.
	 */
	public function test_register_cpt_parameters() {
		// Mock the parameters that would be passed to register_cpt
		$post_type = 'worldgraph_project';
		$args = [
			'label'        => 'Projects',
			'public'       => true,
			'show_ui'      => true,
			'supports'     => [ 'title', 'editor' ],
			'rewrite'      => [ 'slug' => 'project' ],
			'capabilities' => [
				'edit_post'          => 'edit_project',
				'read_post'          => 'read_project',
				'delete_post'        => 'delete_project',
				'edit_posts'         => 'edit_projects',
				'edit_others_posts'  => 'edit_others_projects',
				'publish_posts'      => 'publish_projects',
				'read_private_posts' => 'read_private_projects',
			],
		];
		
		$this->assertIsString( $post_type );
		$this->assertIsArray( $args );
		$this->assertArrayHasKey( 'label', $args );
		$this->assertArrayHasKey( 'public', $args );
		$this->assertArrayHasKey( 'supports', $args );
	}

	/**
	 * Test CPT post type structure.
	 */
	public function test_cpt_post_type_structure() {
		// Test that a CPT definition has the required structure
		$cpt_definition = [
			'post_type' => 'worldgraph_character',
			'label'     => 'Characters',
			'public'    => true,
		];
		
		$this->assertArrayHasKey( 'post_type', $cpt_definition );
		$this->assertArrayHasKey( 'label', $cpt_definition );
		$this->assertArrayHasKey( 'public', $cpt_definition );
		$this->assertEquals( 'worldgraph_character', $cpt_definition['post_type'] );
	}

	/**
	 * Test CPT capabilities structure.
	 */
	public function test_cpt_capabilities_structure() {
		$capabilities = [
			'edit_post'          => 'edit_project',
			'read_post'          => 'read_project',
			'delete_post'        => 'delete_project',
			'edit_posts'         => 'edit_projects',
			'edit_others_posts'  => 'edit_others_projects',
			'publish_posts'      => 'publish_projects',
			'read_private_posts' => 'read_private_projects',
		];
		
		$this->assertArrayHasKey( 'edit_post', $capabilities );
		$this->assertArrayHasKey( 'read_post', $capabilities );
		$this->assertArrayHasKey( 'delete_post', $capabilities );
		$this->assertArrayHasKey( 'edit_posts', $capabilities );
	}

	/**
	 * Test World Graph Studio CPTs are mounted under the World Graph Studio menu.
	 */
	public function test_worldgraph_cpt_defaults_use_worldgraph_menu() {
		$args = \WorldGraph\Utils\worldgraph_get_default_cpt_args( 'worldgraph_project', 'Project' );

		$this->assertSame( 'worldgraph', $args['show_in_menu'] );
		$this->assertTrue( $args['show_ui'] );
	}

	/**
	 * SCF is the only custom-field editing surface for schema-backed CPTs.
	 */
	public function test_worldgraph_cpt_defaults_do_not_enable_native_custom_fields() {
		$args = \WorldGraph\Utils\worldgraph_get_default_cpt_args(
			'worldgraph_project',
			'Project',
			[ 'supports' => [ 'title', 'editor', 'custom-fields' ] ]
		);

		$this->assertNotContains( 'custom-fields', $args['supports'] );
		$this->assertSame( [ 'title', 'editor' ], $args['supports'] );
	}

	/**
	 * Content CPT files must leave canonical field rendering and persistence to SCF.
	 */
	public function test_content_cpts_have_no_legacy_named_field_save_paths() {
		$files = glob( dirname( __DIR__ ) . '/includes/cpts/*.php' ) ?: [];

		foreach ( $files as $path ) {
			$file = basename( $path );
			if ( 'class-generation-job.php' === $file ) {
				continue;
			}
			$source = file_get_contents( $path );

			$this->assertNotFalse( $source, "Could not read CPT source {$file}." );
			$this->assertStringNotContainsString( 'function save_meta(', $source, "Legacy save_meta() remains in {$file}." );
			$this->assertStringNotContainsString( 'function save_project_meta(', $source, "Legacy Project save handler remains in {$file}." );
			$this->assertStringNotContainsString( '$_POST[ $key ]', $source, "Named-field POST persistence remains in {$file}." );
			$this->assertStringNotContainsString( "add_action( 'save_post_worldgraph_", $source, "Legacy save_post hook remains in {$file}." );
		}
	}

	/**
	 * WordPress must recognize SCF as a plugin dependency on every activation path.
	 */
	public function test_scf_dependency_and_activation_guards() {
		$source = file_get_contents( dirname( __DIR__ ) . '/worldgraph.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'Requires Plugins: secure-custom-fields', $source );
		$this->assertStringNotContainsString( 'Requires Plugins: secure-custom-fields/secure-custom-fields.php', $source );
		$this->assertStringContainsString( "if ( ! scf_is_active() ) {\n\t\tworldgraph_missing_scf_dependency();", $source );
		$this->assertStringContainsString( "is_plugin_active_for_network( \$plugin )", $source );
	}

	/**
	 * World Graph Studio CPT keys must fit WordPress's 20-character database limit.
	 */
	public function test_worldgraph_cpt_keys_fit_wordpress_limit() {
		$cpts = \WorldGraph\Utils\worldgraph_get_all_cpts();

		$this->assertArrayHasKey( 'worldgraph_editorial', $cpts );
		$this->assertArrayNotHasKey( 'worldgraph_board', $cpts );
		$this->assertArrayNotHasKey( 'worldgraph_editorial_artifact', $cpts );

		foreach ( array_keys( $cpts ) as $cpt ) {
			$this->assertLessThanOrEqual( 20, strlen( $cpt ), "CPT key {$cpt} exceeds WordPress's 20-character limit." );
		}
	}

	/**
	 * Test the generic Details meta box excludes redundant built-in name/description fields.
	 */
	public function test_worldgraph_details_filters_redundant_name_and_description_fields() {
		$this->assertTrue( \WorldGraph\Utils\worldgraph_should_exclude_from_details( 'description' ) );
		$this->assertTrue( \WorldGraph\Utils\worldgraph_should_exclude_from_details( 'project_name' ) );
		$this->assertTrue( \WorldGraph\Utils\worldgraph_should_exclude_from_details( 'shot_description' ) );
		$this->assertFalse( \WorldGraph\Utils\worldgraph_should_exclude_from_details( 'status' ) );
		$this->assertFalse( \WorldGraph\Utils\worldgraph_should_exclude_from_details( 'target_medium' ) );
		$this->assertFalse( \WorldGraph\Utils\worldgraph_should_exclude_from_details( 'story_world' ) );
	}

	/**
	 * Sound is a first-class Story Graph entity with the planned-cue fields.
	 */
	public function test_sound_cpt_contract_is_registered_in_helpers() {
		$cpts = \WorldGraph\Utils\worldgraph_get_all_cpts();
		$this->assertArrayHasKey( 'worldgraph_sound', $cpts );

		$fields = \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( 'worldgraph_sound' );
		$this->assertSame(
			[ 'sound_type', 'production_status', 'spoken_text', 'lyrics', 'start_timecode', 'duration', 'diegetic', 'production_notes', 'scene', 'shot', 'character', 'asset' ],
			$fields
		);
	}

	/**
	 * Existing structured Scene dialogue is declared independently of Sounds.
	 */
	public function test_scene_contract_declares_structured_dialogue() {
		$this->assertContains( 'dialogue', \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( 'worldgraph_scene' ) );
	}

	/**
	 * Scene dialogue remains separate from the Sound taxonomy.
	 */
	public function test_sound_type_vocabulary_does_not_duplicate_dialogue() {
		$types = \WorldGraph\Utils\worldgraph_sound_types();

		foreach ( [ 'narration', 'voiceover', 'music', 'sound-effect', 'ambience', 'foley', 'silence', 'adr' ] as $required_type ) {
			$this->assertArrayHasKey( $required_type, $types );
		}

		$this->assertArrayNotHasKey( 'dialogue', $types );
	}
}
