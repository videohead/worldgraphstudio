<?php
/**
 * Tests for the internal generation-job record type.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/**
 * Class Test_WorldGraph_Generation_Record
 */
class Test_WorldGraph_Generation_Record extends TestCase {

	/** Jobs are readable in wp-admin but never public, creatable, or REST-exposed. */
	public function test_generation_record_type_is_internal(): void {
		$args = \WorldGraph\Utils\worldgraph_get_generation_record_cpt_args();

		$this->assertFalse( $args['public'] );
		$this->assertFalse( $args['publicly_queryable'] );
		$this->assertTrue( $args['show_ui'] );
		$this->assertSame( 'do_not_allow', $args['capabilities']['create_posts'] );
		$this->assertFalse( $args['show_in_rest'] );
		$this->assertFalse( $args['has_archive'] );
		$this->assertFalse( $args['rewrite'] );
		$this->assertFalse( $args['can_export'] );
		$this->assertTrue( $args['exclude_from_search'] );
		$this->assertSame( [ 'title' ], $args['supports'] );
	}

	/** Existing legacy jobs must be registered before their post type is migrated. */
	public function test_generation_record_type_registers_before_namespace_migration(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/worldgraph.php' );

		$this->assertNotFalse( $source );
		$registration = strpos( $source, 'Utils\\worldgraph_register_generation_record_type();' );
		$migration    = strpos( $source, 'Utils\\worldgraph_maybe_migrate_cpt_keys();' );

		$this->assertNotFalse( $registration );
		$this->assertNotFalse( $migration );
		$this->assertLessThan( $migration, $registration );
	}

	/** The Job list should not present WordPress Draft as generation state. */
	public function test_generation_record_list_hides_native_draft_state(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/cpts/class-generation-job.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "add_filter( 'display_post_states', [ __CLASS__, 'post_states' ], 10, 2 );", $source );
		$this->assertStringContainsString( "if ( self::POST_TYPE === \$post->post_type )", $source );
		$this->assertStringContainsString( "unset( \$states['draft'] );", $source );
	}

	/** The operational Status cell should identify its batch and provider job. */
	public function test_generation_record_status_cell_exposes_job_identity(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/cpts/class-generation-job.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "'_worldgraph_gen_status'", $source );
		$this->assertStringContainsString( "'_worldgraph_gen_batch_id'", $source );
		$this->assertStringContainsString( "'_worldgraph_gen_batch_kind'", $source );
		$this->assertStringContainsString( "'_worldgraph_gen_job_id'", $source );
		$this->assertStringContainsString( "esc_html__( 'Batch', 'worldgraph' )", $source );
		$this->assertStringContainsString( "esc_html__( 'Remote job:', 'worldgraph' )", $source );
	}
}
