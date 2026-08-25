<?php
/**
 * Template prompt-guidance editor contract tests.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/** Keep first-class prompt guidance aligned across PHP, SCF, and the editor. */
final class Test_Template_Prompt_Guidance extends TestCase {

	/** Load the committed Template SCF group, keyed by field name. */
	private function fields(): array {
		$path  = dirname( __DIR__ ) . '/acf-json/group_worldgraph_template.json';
		$group = json_decode( (string) file_get_contents( $path ), true );

		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertIsArray( $group );

		return array_column( (array) ( $group['fields'] ?? [] ), null, 'name' );
	}

	/** The canonical Template schema exposes optional, bounded guidance fields. */
	public function test_prompt_guidance_fields_are_portable_and_bounded(): void {
		$fields = $this->fields();
		$names  = [ 'prompt_lead_with', 'prompt_format', 'prompt_target_words', 'prompt_max_words' ];

		foreach ( $names as $name ) {
			$this->assertArrayHasKey( $name, $fields );
			$this->assertSame( 0, $fields[ $name ]['required'] );
			$this->assertSame( 'field_worldgraph_template_' . $name, $fields[ $name ]['key'] );
			$this->assertContains( $name, \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( 'worldgraph_template' ) );
		}

		$this->assertSame(
			[ 'subject' => 'Subject', 'action' => 'Action', 'motion' => 'Motion' ],
			$fields['prompt_lead_with']['choices']
		);
		$this->assertSame(
			[ 'natural_language' => 'Natural language', 'concise_phrases' => 'Concise phrases', 'chronological_prose' => 'Chronological prose' ],
			$fields['prompt_format']['choices']
		);

		foreach ( [ 'prompt_target_words', 'prompt_max_words' ] as $name ) {
			$this->assertSame( 'number', $fields[ $name ]['type'] );
			$this->assertSame( 1, $fields[ $name ]['min'] );
			$this->assertSame( 4000, $fields[ $name ]['max'] );
			$this->assertSame( 1, $fields[ $name ]['step'] );
			$this->assertSame( '', $fields[ $name ]['default_value'] );
		}
	}

	/** Saved fields participate in policy resolution and the editor shows the result. */
	public function test_template_editor_resolves_and_summarizes_effective_policy(): void {
		$source        = (string) file_get_contents( dirname( __DIR__ ) . '/includes/cpts/template.php' );
		$policy_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-prompt-policy.php' );

		$this->assertStringContainsString( "worldgraph_get_field_value( \$template_id, 'prompt_lead_with' )", $policy_source );
		$this->assertStringContainsString( "worldgraph_get_field_value( \$template_id, 'prompt_format' )", $policy_source );
		$this->assertStringContainsString( "'prompt_target_words' => 'target_words'", $policy_source );
		$this->assertStringContainsString( "'prompt_max_words' => 'max_words'", $policy_source );
		$this->assertStringContainsString( 'Generation_Prompt_Policy::for_template( $post->ID )', $source );
		$this->assertStringContainsString( 'Effective Prompt Guidance', $source );
		$this->assertStringContainsString( 'Wan uses motion first', $source );
		$this->assertStringContainsString( 'Blank prompt-guidance fields inherit reviewed Connection and model recommendations.', $source );
		$this->assertStringContainsString( 'Priority order', $source );
		$this->assertStringContainsString( 'target %1$d words; maximum %2$d words', $source );
	}

	/** Prompt guidance does not regress the safe provider-neutral JSON default. */
	public function test_configuration_json_remains_optional_with_a_safe_default(): void {
		$field = $this->fields()['configuration_json'];

		$this->assertSame( 0, $field['required'] );
		$this->assertSame( '{}', $field['default_value'] );
		$this->assertIsObject( json_decode( (string) $field['default_value'] ) );
	}
}
