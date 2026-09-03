<?php
/**
 * Data-purge contract tests.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Admin\Data_Purge;

require_once dirname( __DIR__ ) . '/includes/admin/data-purge.php';

/** Verifies the destructive action's inventory and authorization contract. */
final class Data_Purge_Test extends TestCase {

	/** The purge inventory includes authoring, configuration, and operational records. */
	public function test_post_type_inventory_covers_all_data_classes(): void {
		$post_types = Data_Purge::known_post_types();

		foreach ( [
			'worldgraph_project',
			'worldgraph_scene',
			'worldgraph_asset',
			'worldgraph_template',
			'worldgraph_conn',
			'worldgraph_gen',
			'worldgraph_agent',
			'worldgraph_board',
		] as $post_type ) {
			$this->assertContains( $post_type, $post_types );
		}

		$this->assertNotContains( 'attachment', $post_types );
		$this->assertNotContains( 'acf-field-group', $post_types );
		$this->assertNotContains( 'acf-field', $post_types );
	}

	/** All nine current plugin taxonomies are covered. */
	public function test_taxonomy_inventory_is_complete(): void {
		$taxonomies = Data_Purge::known_taxonomies();

		$this->assertCount( 9, $taxonomies );
		$this->assertContains( 'worldgraph_asset_type', $taxonomies );
		$this->assertContains( 'worldgraph_sequence', $taxonomies );
		$this->assertContains( 'worldgraph_template_category', $taxonomies );
	}

	/** Prefix covers current plugin data without matching WordPress core. */
	public function test_owned_prefix_inventory_is_narrow(): void {
		$this->assertSame( [ 'worldgraph_' ], Data_Purge::data_prefixes() );
		$this->assertSame( 'PURGE WORLDGRAPH DATA', Data_Purge::CONFIRMATION );
		$this->assertSame( 'worldgraph_purge_data', Data_Purge::ACTION );
	}

	/** The request handler contains all three server-side destructive-action gates. */
	public function test_handler_requires_capability_nonce_and_typed_confirmation(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin/data-purge.php' );

		$this->assertStringContainsString( 'current_user_can( self::required_capability() )', $source );
		$this->assertStringContainsString( "is_multisite() ? 'manage_network_options' : 'manage_options'", $source );
		$this->assertStringContainsString( 'check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD )', $source );
		$this->assertStringContainsString( 'hash_equals( self::CONFIRMATION, $confirmation )', $source );
		$this->assertStringContainsString( "add_action( 'admin_post_' . self::ACTION", $source );
	}

	/** Media deletion is tied to explicit World Graph generation provenance. */
	public function test_attachment_purge_requires_generation_provenance(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin/data-purge.php' );

		$this->assertStringContainsString( "'_worldgraph_generated_from'", $source );
		$this->assertStringContainsString( 'pm.meta_key IN ( %s, %s )', $source );
		$this->assertStringContainsString( 'wp_delete_attachment( $attachment_id, true )', $source );
	}

	/** Multisite purges traverse every site in the current network safely. */
	public function test_multisite_purge_switches_and_restores_each_site(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin/data-purge.php' );

		$this->assertStringContainsString( '$site_ids = get_sites(', $source );
		$this->assertStringContainsString( "'network_id' => get_current_network_id()", $source );
		$this->assertStringContainsString( 'switch_to_blog( $site_id )', $source );
		$this->assertStringContainsString( 'restore_current_blog()', $source );
		$this->assertStringContainsString( 'self::purge_current_site_data( $result )', $source );
	}
}
