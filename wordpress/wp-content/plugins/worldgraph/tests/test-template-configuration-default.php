<?php
/**
 * Template configuration editor defaults.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/** Keep provider-neutral Template configuration usable without hand-written JSON. */
class Test_Template_Configuration_Default extends TestCase {

	/** The SCF editor exposes a valid empty object and does not require input. */
	public function test_configuration_json_has_an_optional_empty_object_default(): void {
		$group = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/acf-json/group_worldgraph_template.json' ),
			true
		);
		$field = current( array_filter( (array) ( $group['fields'] ?? [] ), static function ( array $candidate ): bool {
			return 'configuration_json' === (string) ( $candidate['name'] ?? '' );
		} ) );

		$this->assertIsArray( $field );
		$this->assertSame( 0, $field['required'] );
		$this->assertSame( '{}', $field['default_value'] );
		$this->assertIsObject( json_decode( (string) $field['default_value'] ) );
	}

	/** Older blank records and blank form submissions normalize to the same default. */
	public function test_blank_configuration_is_loaded_and_saved_as_an_empty_object(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/cpts/template.php' );

		$this->assertStringContainsString(
			"acf/load_field/key=field_worldgraph_template_configuration_json",
			$source
		);
		$this->assertStringContainsString(
			"acf/prepare_field/key=field_worldgraph_template_configuration_json",
			$source
		);
		$this->assertStringContainsString(
			"acf/load_value/key=field_worldgraph_template_configuration_json",
			$source
		);
		$this->assertStringContainsString( "\$field['default_value'] = '{}';", $source );
		$this->assertStringContainsString( "public static function load_configuration_json_default", $source );
		$this->assertStringContainsString( "'configuration_json' === \$name && '' === trim( (string) \$value )", $source );
		$this->assertStringContainsString( "\$value = '{}';", $source );
	}
}
