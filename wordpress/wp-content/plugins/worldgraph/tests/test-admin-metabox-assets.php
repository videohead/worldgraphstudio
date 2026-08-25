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
		$this->assertStringContainsString( 'worldgraph-generate-asset__audio-template-option', $metabox );
		$this->assertStringContainsString( 'worldgraph-generate-asset__run-controls', $metabox );
		$this->assertStringContainsString( '<details class="worldgraph-generate-asset__run-controls"', $metabox );
		$this->assertStringContainsString( 'Run controls (optional)', $metabox );
		$this->assertStringContainsString( 'Controls inherit from the Template, then the Project, then this item', $metabox );
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
		$this->assertStringContainsString( 'function effectiveRunControlDefault( panel, field, template )', $script );
		$this->assertStringContainsString( "source: 'project_profile'", $script );
		$this->assertStringContainsString( "'aspect_ratio' === runControlSemantic( field.key )", $script );
		$this->assertStringContainsString( 'input._worldgraphRunHasDefault', $script );
		$this->assertStringContainsString( 'input._worldgraphRunDirty', $script );
		$this->assertStringContainsString( 'Use Template default', $metabox );
		$this->assertStringContainsString( 'input.disabled = controlsLocked', $script );
		$this->assertStringContainsString( 'payload.run_values = runValues', $script );
		$this->assertStringContainsString( 'payload.image_run_values = imageRunValues', $script );
		$this->assertStringContainsString( 'payload.video_run_values = videoRunValues', $script );
		$this->assertStringContainsString( 'payload.audio_run_values = audioRunValues', $script );
		$this->assertStringContainsString( "'pending' === body.assembly.status", $script );
		$this->assertStringContainsString( 'body.assembly.progress_percent', $script );
		$this->assertStringContainsString( 'function clearResult( panel )', $script );
		$this->assertStringContainsString( 'function renderAssemblyResult( panel, assembly )', $script );
		$this->assertStringContainsString( 'body.latest_demonstration_batch.assembly.url', $script );
		$this->assertStringContainsString( 'renderAssemblyResult( panel, body.latest_demonstration_batch.assembly )', $script );
		$this->assertStringContainsString( 'renderAssemblyResult( panel, body.assembly );', $script );
		$this->assertStringContainsString( 'clearResult( panel );', $script );
		$this->assertSame( 4, substr_count( $script, 'clearResult( panel );' ) );
		$this->assertStringContainsString( "result.dataset.resultKind = 'direct'", $script );
		$this->assertStringContainsString( "'direct' !== panel.querySelector( '.worldgraph-generate-asset__result' ).dataset.resultKind", $script );
		$this->assertStringContainsString( "'demonstration' === body.scope", $script );
		$this->assertStringContainsString( "'audio' === task.type && false !== task.generation_required", $script );
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
		$this->assertStringContainsString( "'profile_values'           => (array) ( \$task['profile_values'] ?? [] )", $workflows );
		$this->assertStringContainsString( "'profile_values'     => (array) ( \$task['profile_values'] ?? [] )", $workflows );
	}

	/** Untouched Template selectors follow refreshed server defaults. */
	public function test_only_explicit_template_selection_changes_are_remembered(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/asset-generator.js' );

		$this->assertStringContainsString( 'function rememberTemplateSelection( panel, type )', $script );
		$this->assertStringContainsString( "rememberTemplateSelection( panel, 'image' );", $script );
		$this->assertStringContainsString( "rememberTemplateSelection( panel, 'video' );", $script );
		$this->assertStringContainsString( "rememberTemplateSelection( panel, 'audio' );", $script );
		$this->assertSame( 4, substr_count( $script, 'rememberTemplateSelection(' ) );
		$this->assertStringNotContainsString( 'rememberTemplateSelections', $script );
	}

	/** Reusable defaults are explicit, inherited, and editable without credential access. */
	public function test_run_default_editor_uses_explicit_save_reset_and_object_capabilities(): void {
		$metabox    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin/asset-generator-metabox.php' );
		$script     = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/asset-generator.js' );
		$controller = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );

		$this->assertStringContainsString( 'Template → Project → item → this run', $metabox );
		$this->assertStringContainsString( 'Save current values as Template defaults', $metabox );
		$this->assertStringContainsString( 'Save current values as Project defaults', $metabox );
		$this->assertStringContainsString( 'Save current values as item defaults', $metabox );
		$this->assertStringContainsString( 'Reset Template defaults', $metabox );
		$this->assertStringContainsString( 'Reset Project defaults', $metabox );
		$this->assertStringContainsString( 'Reset item defaults', $metabox );
		$this->assertStringContainsString( 'Template defaults affect every use of this Template across all Projects and items.', $metabox );
		$this->assertStringContainsString( 'This run (not saved)', $metabox );

		$this->assertStringContainsString( 'function persistRunDefaults( panel, templatePanel, target, reset )', $script );
		$this->assertStringContainsString( "request( settings.restUrl + '/defaults', { method: reset ? 'DELETE' : 'POST'", $script );
		$this->assertStringContainsString( 'fingerprint: String( defaults.fingerprint )', $script );
		$this->assertStringContainsString( 'payload.values = completeRunControlValues( templatePanel )', $script );
		$this->assertStringContainsString( "[ 'template', 'project', 'item' ].indexOf( String( target.scope ) )", $script );
		$this->assertStringContainsString( 'return target.editable;', $script );
		$this->assertStringContainsString( 'if ( target.has_overrides )', $script );
		$this->assertStringContainsString( "setRunControlSource( input, 'run' )", $script );
		$this->assertStringContainsString( "source.setAttribute( 'aria-live', 'polite' )", $script );
		$this->assertStringContainsString( 'function renderRunDefaultStatus( editor, defaults, targets )', $script );
		$this->assertStringContainsString( "defaults && Array.isArray( defaults.warnings )", $script );
		$this->assertStringContainsString( "region.setAttribute( 'role', 'status' )", $script );
		$this->assertStringContainsString( 'problematicRunDefaultStatus( targetStatus )', $script );

		$generate_start = strpos( $script, 'function generateSingle( panel, action )' );
		$generate_end   = strpos( $script, 'function startBatch( panel, scope )', $generate_start );
		$this->assertNotFalse( $generate_start );
		$this->assertNotFalse( $generate_end );
		$this->assertStringNotContainsString( 'persistRunDefaults', substr( $script, $generate_start, $generate_end - $generate_start ) );

		$this->assertStringContainsString( "'/assets/generate/defaults'", $controller );
		$this->assertStringContainsString( "'methods'             => 'GET'", $controller );
		$this->assertStringContainsString( "'methods'             => 'POST'", $controller );
		$this->assertStringContainsString( "'methods'             => 'DELETE'", $controller );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$target_id )", $controller );
		$permission_start = strpos( $controller, 'public static function check_defaults_permission' );
		$permission_end   = strpos( $controller, 'public static function check_batch_permission', $permission_start );
		$this->assertNotFalse( $permission_start );
		$this->assertNotFalse( $permission_end );
		$this->assertStringNotContainsString( 'manage_options', substr( $controller, $permission_start, $permission_end - $permission_start ) );
		$this->assertStringContainsString( 'worldgraph_generation_default_fingerprint_stale', (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-run-defaults.php' ) );
	}
}
