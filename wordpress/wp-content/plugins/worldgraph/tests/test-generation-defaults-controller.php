<?php
/**
 * Static contract tests for the generation-defaults REST boundary.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/** Keeps the REST surface aligned with repository identity and capabilities. */
final class Test_Generation_Defaults_Controller extends TestCase {

	/** Template is an editable scope and remains server-bound to its provider. */
	public function test_template_scope_requires_template_capability_and_generation_adapter(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );
		$start  = strpos( $source, 'private static function defaults_context' );
		$end    = strpos( $source, '/** Attach source-aware defaults', $start );

		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$context = substr( $source, $start, $end - $start );
		$this->assertStringContainsString( "[ 'template', 'project', 'item' ]", $source );
		$this->assertStringContainsString( "'template' === \$scope", $context );
		$this->assertStringContainsString( '$target_id = $template_id;', $context );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$target_id )", $context );
		$this->assertStringContainsString( "worldgraph_get_field_value( \$template_id, 'provider_type' )", $context );
		$this->assertStringContainsString( "\$connection['provider_type']", $context );
		$this->assertStringContainsString( 'Connection_Adapters::supports_generation( $provider )', $context );
	}

	/** Raw Template defaults remain clearly marked as an advanced escape hatch. */
	public function test_template_default_values_field_points_to_the_validated_editor(): void {
		$cpt  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/cpts/template.php' );
		$json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/acf-json/group_worldgraph_template.json' ), true );

		$this->assertStringContainsString( "'label'       => 'Default Values JSON (Advanced)'", $cpt );
		$this->assertStringContainsString( 'validated per-Template defaults editor in Generate Representative Media', $cpt );
		$this->assertIsArray( $json );
		$fields = array_column( (array) ( $json['fields'] ?? [] ), null, 'name' );
		$this->assertSame( 'Default Values JSON (Advanced)', $fields['default_values']['label'] ?? '' );
		$this->assertStringContainsString( 'validated per-Template defaults editor in Generate Representative Media', (string) ( $fields['default_values']['instructions'] ?? '' ) );
	}

	/** Canonical Shot/Sound duration sits after Project defaults but before item overrides. */
	public function test_source_duration_uses_item_layer_precedence(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-run-defaults.php' );

		$this->assertStringContainsString( 'private static function profile_layers', $source );
		$this->assertStringContainsString( "'duration' === \$semantic", $source );
		$this->assertStringContainsString( "\$item['values'] = array_merge( (array) \$profiles['item'], (array) \$item['values'] );", $source );
		$this->assertLessThan(
			strpos( $source, "self::overlay( \$effective, \$sources, (array) \$item['values'], 'item' )" ),
			strpos( $source, "self::overlay( \$effective, \$sources, (array) \$project['values'], 'project' )" )
		);
	}
}
