<?php
/**
 * Smoke tests for Comfy MCP submission and fallback safety rails.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

class Test_Comfy_MCP_Smoke extends TestCase {

	/**
	 * Setup guidance must only reference metadata exposed by the current
	 * fallback checkpoint contract.
	 */
	public function test_setup_guidance_matches_the_sd15_fallback(): void {
		require_once dirname( __DIR__ ) . '/includes/utils/comfy-bootstrap.php';

		$wizard    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin/setup-wizard.php' );
		$readiness = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin/comfy-readiness.php' );

		$this->assertSame( 'Stable Diffusion 1.5 (FP16)', \WorldGraph\Utils\Comfy_Bootstrap::DEFAULT_CHECKPOINT_LABEL );
		$this->assertStringNotContainsString( 'DEFAULT_CHECKPOINT_SIZE', $wizard );
		$this->assertStringNotContainsString( 'SDXL-class', $wizard . $readiness );
	}

	/** Bootstrap cleanup must never retire operator or catalog video Templates. */
	public function test_bootstrap_retires_only_the_obsolete_managed_slot(): void {
		require_once dirname( __DIR__ ) . '/includes/utils/comfy-bootstrap.php';

		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/comfy-bootstrap.php' );
		$start  = strpos( $source, 'private static function retire_legacy_templates' );
		$end    = false !== $start ? strpos( $source, "\n\t/**", $start ) : false;
		$method = false !== $start && false !== $end ? substr( $source, $start, $end - $start ) : '';

		$this->assertSame( 'local_comfyui_default', \WorldGraph\Utils\Comfy_Bootstrap::LEGACY_TEMPLATE_SLOT );
		$this->assertStringContainsString( "'key'   => 'worldgraph_wizard_slot'", $method );
		$this->assertStringContainsString( "'value' => self::LEGACY_TEMPLATE_SLOT", $method );
		$this->assertStringNotContainsString( 'Generation_Modality::TEXT_TO_VIDEO', $method );
		$this->assertStringNotContainsString( '$is_video_template', $method );
	}

	/**
	 * MCP tool payloads with isError=true must be normalized into WP_Error so
	 * Generation_Batch can trigger local fallback when available.
	 */
	public function test_mcp_in_band_tool_errors_are_normalized(): void {
		$client = file_get_contents( dirname( __DIR__ ) . '/includes/utils/comfy-cloud-mcp.php' );

		$this->assertNotFalse( $client );
		$this->assertStringContainsString( "if ( ! empty( \$result['isError'] ) )", $client );
		$this->assertStringContainsString( 'return new WP_Error(', $client );
		$this->assertStringContainsString( "'comfy_mcp_tool_error'", $client );
	}

	/**
	 * A local Comfy connection generates through its own HTTP API. Its MCP
	 * endpoint serves discovery and downloads, never generation.
	 */
	public function test_generation_batch_routes_local_comfy_to_local_api(): void {
		$batch = file_get_contents( dirname( __DIR__ ) . '/includes/utils/generation-batch.php' );

		$this->assertNotFalse( $batch );
		$this->assertStringContainsString( "if ( 'local' === ( \$connection['environment'] ?? '' ) ) {", $batch );
		$this->assertStringContainsString( "update_post_meta( \$job_id, '_worldgraph_gen_adapter', 'local_comfyui' );", $batch );
		$this->assertStringNotContainsString( 'Retrying via local ComfyUI API', $batch );
	}
}
