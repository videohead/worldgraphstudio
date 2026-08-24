<?php
/**
 * Template Workflow Test operator contract tests.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/** Protect the Template test panel's safe, model-aware execution controls. */
class Test_Template_Workflow_Test extends TestCase {

	/** Read one plugin file. */
	private function source( string $relative_path ): string {
		$path = dirname( __DIR__ ) . '/' . ltrim( $relative_path, '/' );
		$this->assertFileExists( $path, "Missing Template Workflow Test source: {$relative_path}" );

		$source = file_get_contents( $path );
		$this->assertNotFalse( $source, "Unreadable Template Workflow Test source: {$relative_path}" );

		return (string) $source;
	}

	/** The browser receives server-derived controls, guidance, and exact loaders. */
	public function test_capability_exposes_safe_model_aware_metadata(): void {
		$source = $this->source( 'includes/admin/template-workflow-test.php' );

		$this->assertStringContainsString( 'Template_Run_Controls::describe( $template_id )', $source );
		$this->assertStringContainsString( 'Generation_Prompt_Profiles::for_template( $template_id )', $source );
		$this->assertStringContainsString( 'Comfy_Manifest::for_template( $template_id )', $source );
		$this->assertStringContainsString( "'runControls'", $source );
		$this->assertStringContainsString( "'promptProfile'", $source );
		$this->assertStringContainsString( "'fixedSelections'", $source );
		$this->assertStringContainsString( "'nodeClass'", $source );
		$this->assertStringContainsString( "'negative_prompt' === (string) \$slot && \$has_negative_control", $source );
	}

	/** Controls are allowlisted in the DOM and only changed values reach REST. */
	public function test_client_submits_only_touched_safe_run_controls(): void {
		$script = $this->source( 'assets/js/template-workflow-test.js' );

		$this->assertStringContainsString( 'function safeRunControlKey( key )', $script );
		$this->assertStringContainsString( "var allowedTypes = [ 'string', 'textarea', 'integer', 'number', 'boolean', 'select' ];", $script );
		$this->assertStringContainsString( 'if ( ! touchedRunValues[ field.key ] )', $script );
		$this->assertStringContainsString( 'requestBody.run_values = runValues;', $script );
		$this->assertStringContainsString( "control.dataset.worldgraphRunControl = field.key;", $script );
		$this->assertStringNotContainsString( 'innerHTML', $script );
	}

	/** Prompt help names the model grammar and distinguishes loader selections. */
	public function test_client_renders_prompt_and_fixed_selection_guidance(): void {
		$script = $this->source( 'assets/js/template-workflow-test.js' );

		$this->assertStringContainsString( 'promptProfile.assistant_guidance', $script );
		$this->assertStringContainsString( 'promptProfile.negative_suggestion', $script );
		$this->assertStringContainsString( 'selection.nodeClass + \'.\' + selection.field', $script );
		$this->assertStringContainsString( 'these are workflow settings, not prompt keywords', $script );
	}

	/** The Template editor identifies the precise loader socket for every file. */
	public function test_requirements_list_shows_loader_role(): void {
		$template = $this->source( 'includes/cpts/template.php' );

		$this->assertStringContainsString( '(loader: %1$s.%2$s)', $template );
		$this->assertStringContainsString( "\$model['node_class']", $template );
		$this->assertStringContainsString( "\$model['field']", $template );
	}
}
