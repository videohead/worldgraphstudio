<?php
/**
 * Model family normalization tests.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Comfy_Template_Registry;
use WorldGraph\Utils\Model_Family;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/model_family.php';
require_once dirname( __DIR__ ) . '/includes/utils/comfy-template-registry.php';

/** Model versions must resolve to stable family slugs and generic labels. */
class Test_Model_Family extends TestCase {

	/** Versioned display names and filenames map to stable family slugs. */
	public function test_versioned_model_names_map_to_generic_family_slugs(): void {
		$this->assertSame( Model_Family::WAN, Model_Family::sanitize( 'Wan 2.2' ) );
		$this->assertSame( Model_Family::WAN, Model_Family::sanitize( 'wan2.2_t2v_high_noise_14B_fp8_scaled.safetensors' ) );
		$this->assertSame( Model_Family::LTXV, Model_Family::sanitize( 'LTX 2.5' ) );
		$this->assertSame( Model_Family::LTXV, Model_Family::sanitize( 'ltxv-2.5-dev-fp8.safetensors' ) );
		$this->assertSame( '', Model_Family::sanitize( 'unrelated-model-2.5' ) );
	}

	/** Registry model metadata uses the same version-independent inference. */
	public function test_registry_inference_uses_generic_family_slugs(): void {
		$method = new ReflectionMethod( Comfy_Template_Registry::class, 'family_for' );
		$method->setAccessible( true );

		$this->assertSame( Model_Family::WAN, $method->invoke( null, [ 'Wan 2.2' ] ) );
		$this->assertSame( Model_Family::LTXV, $method->invoke( null, [ 'LTX 2.5' ] ) );
	}

	/** Published FLF2V workflows require both Project frame bindings. */
	public function test_registry_maps_first_last_frame_video_to_video_modality(): void {
		$method = new ReflectionMethod( Comfy_Template_Registry::class, 'modality_for' );
		$method->setAccessible( true );

		$this->assertSame(
			\WorldGraph\Utils\Generation_Modality::VIDEO_TO_VIDEO,
			$method->invoke( null, [ 'FLF2V', 'Video' ], 'video' )
		);
		$this->assertSame(
			\WorldGraph\Utils\Generation_Modality::VIDEO_TO_VIDEO,
			Comfy_Template_Registry::TAG_MODALITIES['FLF2V']
		);
	}

	/** Admin labels and persisted ACF choices stay version-neutral and aligned. */
	public function test_labels_and_acf_choices_are_generic_and_aligned(): void {
		$labels = Model_Family::labels();
		$this->assertSame( 'Wan', $labels[ Model_Family::WAN ] );
		$this->assertSame( 'LTXV (LTX-Video)', $labels[ Model_Family::LTXV ] );

		$group = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/acf-json/group_worldgraph_template.json' ), true );
		$field = current( array_filter( (array) ( $group['fields'] ?? [] ), static function ( array $candidate ): bool {
			return 'model_family' === (string) ( $candidate['name'] ?? '' );
		} ) );

		$this->assertIsArray( $field );
		$this->assertSame( $labels, $field['choices'] );
	}
}
