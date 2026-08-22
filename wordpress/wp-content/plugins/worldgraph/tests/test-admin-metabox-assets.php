<?php
/**
 * Tests for World Graph Studio Assets metabox behavior.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

class Test_Admin_Metabox_Assets extends TestCase {

	/**
	 * The metabox should not add custom featured/gallery asset controls when core
	 * Gutenberg controls already cover those responsibilities.
	 */
	public function test_worldgraph_assets_metabox_uses_core_editor_controls_only(): void {
		$file = dirname( __DIR__ ) . '/includes/admin/metaboxes.php';
		$source = file_get_contents( $file );

		$this->assertNotFalse( $source, 'The metabox file should be readable.' );
		$this->assertStringNotContainsString( 'worldgraph-select-featured-asset', $source );
		$this->assertStringNotContainsString( 'worldgraph-select-gallery', $source );
		$this->assertStringNotContainsString( 'worldgraph_featured_asset_nonce', $source );
		$this->assertStringNotContainsString( 'worldgraph_asset_gallery_nonce', $source );
		$this->assertStringContainsString( 'block editor', strtolower( $source ) );
	}

	/** Generation type drives one purpose-built selector and contextual action. */
	public function test_generator_controls_match_their_actions(): void {
		$metabox = file_get_contents( dirname( __DIR__ ) . '/includes/admin/asset-generator-metabox.php' );
		$script  = file_get_contents( dirname( __DIR__ ) . '/assets/js/asset-generator.js' );

		$this->assertNotFalse( $metabox );
		$this->assertNotFalse( $script );
		$this->assertStringContainsString( 'Choose a generation type', $metabox );
		$this->assertStringContainsString( 'Create one selected still image', $metabox );
		$this->assertStringContainsString( 'Create every image or video in a defined set', $metabox );
		$this->assertStringContainsString( 'Create one selected moving shot', $metabox );
		$this->assertStringContainsString( 'worldgraph-generate-asset__action-select', $metabox );
		$this->assertStringContainsString( 'worldgraph-generate-asset__image-template-option', $metabox );
		$this->assertStringContainsString( 'worldgraph-generate-asset__video-template-option', $metabox );
		$this->assertStringContainsString( 'worldgraph-generate-asset__run-controls', $metabox );
		$this->assertStringContainsString( '<details class="worldgraph-generate-asset__run-controls"', $metabox );
		$this->assertStringContainsString( 'Run controls (optional)', $metabox );
		$this->assertStringContainsString( 'Output framing defaults come from the Project', $metabox );
		$this->assertStringContainsString( 'Additional instructions for this run', $metabox );
		$this->assertStringContainsString( 'Review the generated prompt or workflow plan', $metabox );
		$this->assertStringContainsString( "self::asset_version( 'assets/js/asset-generator.js' )", $metabox );
		$this->assertStringContainsString( "'generationRestUrl' => rest_url( 'worldgraph/v1/generation' )", $metabox );
		$this->assertStringNotContainsString( 'Detailed prompt preview', $metabox );
		$this->assertStringNotContainsString( 'worldgraph-generate-asset__suggest', $metabox );
		$this->assertStringNotContainsString( 'Automatic per intent', $metabox );
		$this->assertStringNotContainsString( 'Direct output', $metabox );
		$this->assertStringNotContainsString( 'Generate this item’s full set', $metabox );

		$this->assertStringContainsString( 'body.actions || legacyActions( body )', $script );
		$this->assertStringContainsString( "image: actions.some", $script );
		$this->assertStringContainsString( "sequence: ( parseInt( body.total_jobs", $script );
		$this->assertStringContainsString( "video: actions.some", $script );
		$this->assertStringContainsString( 'type: action.type', $script );
		$this->assertStringContainsString( 'intent: action.intent', $script );
		$this->assertStringContainsString( 'function watchSingleJob( panel, generationId, type )', $script );
		$this->assertStringContainsString( 'generationStatusBaseUrl() + \'/\' + encodeURIComponent( generationId )', $script );
		$this->assertStringContainsString( 'if ( body.generation_id ) {', $script );
		$this->assertStringContainsString( 'watchSingleJob( panel, body.generation_id, action.type );', $script );
		$this->assertStringContainsString( 'base_prompt:', $script );
		$this->assertStringContainsString( 'startBatch( panel, info.scope )', $script );
		$this->assertStringContainsString( 'selectHasEnabledOption( template )', $script );
		$this->assertStringContainsString( "panel.querySelector( '.worldgraph-generate-asset__prompt' ).disabled = controlsLocked", $script );
		$this->assertStringContainsString( 'template.run_controls', $script );
		$this->assertStringContainsString( "var runControlGroups = [ 'conditioning', 'sampling', 'output', 'advanced' ]", $script );
		$this->assertStringContainsString( 'option.textContent =', $script );
		$this->assertStringContainsString( 'description.textContent = String( field.description )', $script );
		$this->assertStringContainsString( 'panel._worldgraphRunValues = {}', $script );
		$this->assertStringContainsString( 'function effectiveRunControlDefault( panel, field )', $script );
		$this->assertStringContainsString( "source: 'project'", $script );
		$this->assertStringContainsString( "'aspect_ratio' === runControlSemantic( field.key )", $script );
		$this->assertStringContainsString( 'input._worldgraphRunHasDefault', $script );
		$this->assertStringContainsString( 'input._worldgraphRunDirty', $script );
		$this->assertStringContainsString( 'Use Template default', $metabox );
		$this->assertStringContainsString( 'input.disabled = controlsLocked', $script );
		$this->assertStringContainsString( 'payload.run_values = runValues', $script );
		$this->assertStringContainsString( 'payload.image_run_values = imageRunValues', $script );
		$this->assertStringContainsString( 'payload.video_run_values = videoRunValues', $script );
		$this->assertStringNotContainsString( 'innerHTML', $script );
		$this->assertStringContainsString( 'panel._worldgraphKnownBatches = activeBatchesFromPrompt( body )', $script );
		$this->assertStringContainsString( "select.textContent = '';", $script );
		$this->assertStringNotContainsString( 'worldgraph-generate-asset__run-set', $script );
		$this->assertStringNotContainsString( 'worldgraph-generate-asset__run-project', $script );
	}

	/** Project framing is frozen separately from explicit per-run overrides. */
	public function test_project_output_defaults_are_projected_and_frozen(): void {
		$generator = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );
		$workflows = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );

		$this->assertNotFalse( $generator );
		$this->assertNotFalse( $workflows );
		$this->assertStringContainsString( 'self::project_template_defaults( $template_id, $profile, $description )', $generator );
		$this->assertStringContainsString( 'Template_Run_Controls::profile_defaults( $description, $profile )', $generator );
		$this->assertStringContainsString( "'_worldgraph_gen_profile_values'", $generator );
		$this->assertStringContainsString( "isset( \$job_params['width'], \$job_params['height'] )", $generator );
		$this->assertStringContainsString( "'profile_values' => (array) ( \$task['profile_values'] ?? [] )", $workflows );
		$this->assertStringContainsString( "'profile_values'     => (array) ( \$task['profile_values'] ?? [] )", $workflows );
	}

	/** Untouched Template selectors follow refreshed server defaults. */
	public function test_only_explicit_template_selection_changes_are_remembered(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/asset-generator.js' );

		$this->assertStringContainsString( 'function rememberTemplateSelection( panel, type )', $script );
		$this->assertStringContainsString( "rememberTemplateSelection( panel, 'image' );", $script );
		$this->assertStringContainsString( "rememberTemplateSelection( panel, 'video' );", $script );
		$this->assertSame( 3, substr_count( $script, 'rememberTemplateSelection(' ) );
		$this->assertStringNotContainsString( 'rememberTemplateSelections', $script );
	}
}
