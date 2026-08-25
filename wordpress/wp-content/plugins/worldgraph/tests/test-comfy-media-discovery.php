<?php
/**
 * Focused discovery tests for media-bearing ComfyUI Templates.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free test shim.
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ): string {
		return (string) json_encode( $value );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/comfy-graph.php';
require_once dirname( __DIR__ ) . '/includes/utils/model_family.php';
require_once dirname( __DIR__ ) . '/includes/utils/comfy-manifest.php';

use WorldGraph\Utils\Comfy_Manifest;
use WorldGraph\Utils\Generation_Modality;

/** Media modality inference from provider workflows. */
class Test_Comfy_Media_Discovery extends TestCase {

	/** A compound name must not be swallowed by the generic text-video match. */
	public function test_image_and_text_name_is_discovered_as_image_to_video(): void {
		$entry = Comfy_Manifest::normalize_entry( [
			'id'       => 'compound-name-workflow',
			'name'     => 'Image and Text to Video',
			'workflow' => [
				'1' => [ 'class_type' => 'SaveVideo', 'inputs' => [] ],
			],
		] );

		$this->assertIsArray( $entry );
		$this->assertSame( Generation_Modality::TEXT_IMAGE_TO_VIDEO, $entry['modality'] );
	}

	/** A still workflow with a loader is usable as image-to-image. */
	public function test_api_workflow_with_load_image_is_discovered_as_image_to_image(): void {
		$entry = Comfy_Manifest::normalize_entry( [
			'id'       => 'opaque-still-workflow',
			'workflow' => [
				'1' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'demo.png' ] ],
				'2' => [ 'class_type' => 'VAEEncode', 'inputs' => [ 'pixels' => [ '1', 0 ] ] ],
				'3' => [ 'class_type' => 'SaveImage', 'inputs' => [ 'images' => [ '2', 0 ] ] ],
			],
		] );

		$this->assertIsArray( $entry );
		$this->assertSame( Generation_Modality::IMAGE_TO_IMAGE, $entry['modality'] );
	}

	/** Editor-format node types participate in discovery before conversion. */
	public function test_editor_workflow_with_load_image_is_discovered_as_image_to_video(): void {
		$entry = Comfy_Manifest::normalize_entry( [
			'id'       => 'opaque-editor-workflow',
			'workflow' => [
				'nodes' => [
					[ 'id' => 1, 'type' => 'LoadImage' ],
					[ 'id' => 2, 'type' => 'CreateVideo' ],
					[ 'id' => 3, 'type' => 'SaveVideo' ],
				],
			],
		] );

		$this->assertIsArray( $entry );
		$this->assertSame( Generation_Modality::TEXT_IMAGE_TO_VIDEO, $entry['modality'] );
	}

	/** Two guided frame loaders are distinguished from a one-image video seed. */
	public function test_two_guided_loaders_are_discovered_as_video_to_video(): void {
		$entry = Comfy_Manifest::normalize_entry( [
			'id'       => 'opaque-guided-workflow',
			'workflow' => [
				'1' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'start.png' ] ],
				'2' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'end.png' ] ],
				'3' => [ 'class_type' => 'LTXVAddGuide', 'inputs' => [ 'image' => [ '1', 0 ] ] ],
				'4' => [ 'class_type' => 'SaveVideo', 'inputs' => [ 'video' => [ '3', 0 ] ] ],
			],
		] );

		$this->assertIsArray( $entry );
		$this->assertSame( Generation_Modality::VIDEO_TO_VIDEO, $entry['modality'] );
	}

	/** FLF evidence overrides a provider's overly broad image-to-video task type. */
	public function test_wan_flf_is_discovered_as_video_to_video(): void {
		$entry = Comfy_Manifest::normalize_entry( [
			'id'        => 'video_wan2_2_14B_flf2v',
			'name'      => 'Wan 2.2 14B First-Last Frame to Video',
			'task_type' => 'image-to-video',
			'workflow'  => [
				'1' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'start.png' ] ],
				'2' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'end.png' ] ],
				'3' => [
					'class_type' => 'WanFirstLastFrameToVideo',
					'inputs'     => [ 'start_image' => [ '1', 0 ], 'end_image' => [ '2', 0 ] ],
				],
				'4' => [ 'class_type' => 'SaveVideo', 'inputs' => [ 'video' => [ '3', 0 ] ] ],
			],
		] );

		$this->assertIsArray( $entry );
		$this->assertSame( Generation_Modality::VIDEO_TO_VIDEO, $entry['modality'] );
	}
}
