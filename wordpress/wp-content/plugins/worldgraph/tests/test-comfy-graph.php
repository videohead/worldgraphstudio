<?php
/**
 * Behavioural tests for the ComfyUI editor-graph to API-prompt converter.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( $min, $max );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/comfy-graph.php';
require_once dirname( __DIR__ ) . '/includes/utils/local-comfyui.php';

use WorldGraph\Utils\Comfy_Graph;
use WorldGraph\Utils\Local_ComfyUI;

class Test_Comfy_Graph extends TestCase {

	/**
	 * A minimal node catalog covering the classes the fixtures use.
	 *
	 * @return array
	 */
	private function object_info(): array {
		return [
			'CheckpointLoaderSimple' => [
				'input'       => [ 'required' => [ 'ckpt_name' => [ [ 'model.safetensors' ] ] ] ],
				'input_order' => [ 'required' => [ 'ckpt_name' ] ],
			],
			'CLIPTextEncode'         => [
				'input'       => [ 'required' => [ 'text' => [ 'STRING', [ 'multiline' => true ] ], 'clip' => [ 'CLIP' ] ] ],
				'input_order' => [ 'required' => [ 'text', 'clip' ] ],
			],
			'EmptyLatentImage'       => [
				'input'       => [ 'required' => [ 'width' => [ 'INT' ], 'height' => [ 'INT' ], 'batch_size' => [ 'INT' ] ] ],
				'input_order' => [ 'required' => [ 'width', 'height', 'batch_size' ] ],
			],
			'KSampler'               => [
				'input'       => [
					'required' => [
						'model'        => [ 'MODEL' ],
						'positive'     => [ 'CONDITIONING' ],
						'negative'     => [ 'CONDITIONING' ],
						'latent_image' => [ 'LATENT' ],
						'seed'         => [ 'INT', [ 'control_after_generate' => true ] ],
						'steps'        => [ 'INT' ],
						'cfg'          => [ 'FLOAT' ],
						'sampler_name' => [ [ 'euler' ] ],
						'scheduler'    => [ [ 'normal' ] ],
						'denoise'      => [ 'FLOAT' ],
					],
				],
				'input_order' => [ 'required' => [ 'model', 'positive', 'negative', 'latent_image', 'seed', 'steps', 'cfg', 'sampler_name', 'scheduler', 'denoise' ] ],
			],
			'VAEDecode'              => [
				'input'       => [ 'required' => [ 'samples' => [ 'LATENT' ], 'vae' => [ 'VAE' ] ] ],
				'input_order' => [ 'required' => [ 'samples', 'vae' ] ],
			],
			'SaveImage'              => [
				'input'       => [ 'required' => [ 'images' => [ 'IMAGE' ], 'filename_prefix' => [ 'STRING' ] ] ],
				'input_order' => [ 'required' => [ 'images', 'filename_prefix' ] ],
				'output_node' => true,
			],
			'ImageInvert'            => [
				'input'       => [ 'required' => [ 'image' => [ 'IMAGE' ] ] ],
				'input_order' => [ 'required' => [ 'image' ] ],
			],
		];
	}

	/**
	 * A text-to-image editor graph exercising subgraph expansion, reroutes, a
	 * bypassed node, an editor-only note, and a seed control widget.
	 *
	 * @return array
	 */
	private function graph(): array {
		return [
			'nodes'       => [
				[ 'id' => 1, 'type' => 'sub-uuid', 'mode' => 0, 'inputs' => [ [ 'name' => 'text', 'type' => 'STRING', 'link' => 101 ] ], 'widgets_values' => [] ],
				[ 'id' => 2, 'type' => 'CLIPTextEncode', 'mode' => 0, 'inputs' => [ [ 'name' => 'clip', 'type' => 'CLIP', 'link' => 102 ] ], 'widgets_values' => [ 'a lighthouse at dusk' ] ],
				[ 'id' => 3, 'type' => 'Reroute', 'mode' => 0, 'inputs' => [ [ 'name' => '', 'type' => '*', 'link' => 103 ] ], 'outputs' => [ [ 'type' => 'IMAGE' ] ] ],
				[ 'id' => 4, 'type' => 'ImageInvert', 'mode' => 4, 'inputs' => [ [ 'name' => 'image', 'type' => 'IMAGE', 'link' => 104 ] ], 'outputs' => [ [ 'type' => 'IMAGE' ] ] ],
				[ 'id' => 5, 'type' => 'SaveImage', 'mode' => 0, 'inputs' => [ [ 'name' => 'images', 'type' => 'IMAGE', 'link' => 105 ] ], 'widgets_values' => [ 'worldgraph' ] ],
				[ 'id' => 6, 'type' => 'MarkdownNote', 'mode' => 0, 'inputs' => [], 'widgets_values' => [ '## Model links' ] ],
				[ 'id' => 7, 'type' => 'ImageInvert', 'mode' => 2, 'inputs' => [], 'outputs' => [ [ 'type' => 'IMAGE' ] ] ],
			],
			// [link_id, origin_id, origin_slot, target_id, target_slot, type]
			'links'       => [
				[ 101, 2, 0, 1, 0, 'CONDITIONING' ],
				[ 102, 1, 1, 2, 0, 'CLIP' ],
				[ 103, 1, 0, 3, 0, 'IMAGE' ],
				[ 104, 3, 0, 4, 0, 'IMAGE' ],
				[ 105, 4, 0, 5, 0, 'IMAGE' ],
			],
			'definitions' => [
				'subgraphs' => [
					[
						'id'         => 'sub-uuid',
						'name'       => 'Text to Image',
						'inputNode'  => [ 'id' => -10 ],
						'outputNode' => [ 'id' => -20 ],
						'inputs'     => [ [ 'name' => 'text', 'type' => 'CONDITIONING' ] ],
						'outputs'    => [ [ 'name' => 'IMAGE', 'type' => 'IMAGE' ], [ 'name' => 'CLIP', 'type' => 'CLIP' ] ],
						'nodes'      => [
							[ 'id' => 10, 'type' => 'CheckpointLoaderSimple', 'mode' => 0, 'inputs' => [], 'widgets_values' => [ 'model.safetensors' ] ],
							[ 'id' => 11, 'type' => 'CLIPTextEncode', 'mode' => 0, 'inputs' => [ [ 'name' => 'clip', 'type' => 'CLIP', 'link' => 201 ] ], 'widgets_values' => [ 'blurry, low quality' ] ],
							[ 'id' => 12, 'type' => 'EmptyLatentImage', 'mode' => 0, 'inputs' => [], 'widgets_values' => [ 1024, 1024, 1 ] ],
							[
								'id'             => 13,
								'type'           => 'KSampler',
								'mode'           => 0,
								'inputs'         => [
									[ 'name' => 'model', 'type' => 'MODEL', 'link' => 202 ],
									[ 'name' => 'positive', 'type' => 'CONDITIONING', 'link' => 203 ],
									[ 'name' => 'negative', 'type' => 'CONDITIONING', 'link' => 204 ],
									[ 'name' => 'latent_image', 'type' => 'LATENT', 'link' => 205 ],
								],
								'widgets_values' => [ 42, 'randomize', 28, 6.5, 'euler', 'normal', 1 ],
							],
							[ 'id' => 14, 'type' => 'VAEDecode', 'mode' => 0, 'inputs' => [ [ 'name' => 'samples', 'type' => 'LATENT', 'link' => 206 ], [ 'name' => 'vae', 'type' => 'VAE', 'link' => 207 ] ], 'widgets_values' => [] ],
						],
						'links'      => [
							[ 'id' => 201, 'origin_id' => 10, 'origin_slot' => 1, 'target_id' => 11, 'target_slot' => 0, 'type' => 'CLIP' ],
							[ 'id' => 202, 'origin_id' => 10, 'origin_slot' => 0, 'target_id' => 13, 'target_slot' => 0, 'type' => 'MODEL' ],
							[ 'id' => 203, 'origin_id' => -10, 'origin_slot' => 0, 'target_id' => 13, 'target_slot' => 1, 'type' => 'CONDITIONING' ],
							[ 'id' => 204, 'origin_id' => 11, 'origin_slot' => 0, 'target_id' => 13, 'target_slot' => 2, 'type' => 'CONDITIONING' ],
							[ 'id' => 205, 'origin_id' => 12, 'origin_slot' => 0, 'target_id' => 13, 'target_slot' => 3, 'type' => 'LATENT' ],
							[ 'id' => 206, 'origin_id' => 13, 'origin_slot' => 0, 'target_id' => 14, 'target_slot' => 0, 'type' => 'LATENT' ],
							[ 'id' => 207, 'origin_id' => 10, 'origin_slot' => 2, 'target_id' => 14, 'target_slot' => 1, 'type' => 'VAE' ],
							[ 'id' => 208, 'origin_id' => 14, 'origin_slot' => 0, 'target_id' => -20, 'target_slot' => 0, 'type' => 'IMAGE' ],
							[ 'id' => 209, 'origin_id' => 10, 'origin_slot' => 1, 'target_id' => -20, 'target_slot' => 1, 'type' => 'CLIP' ],
						],
					],
				],
			],
		];
	}

	/**
	 * Locate a converted node by class type.
	 *
	 * @param array  $api   Converted workflow.
	 * @param string $class Node class type.
	 * @return array<string, array>
	 */
	private function by_class( array $api, string $class ): array {
		return array_filter( $api, static function ( array $node ) use ( $class ): bool {
			return $node['class_type'] === $class;
		} );
	}

	/**
	 * The single converted node of a given class type.
	 *
	 * @param array  $api   Converted workflow.
	 * @param string $class Node class type.
	 * @return array
	 */
	private function only( array $api, string $class ): array {
		$matches = $this->by_class( $api, $class );
		$this->assertCount( 1, $matches );

		return reset( $matches );
	}

	/**
	 * An API-format graph is already executable and must pass through untouched.
	 */
	public function test_api_format_graphs_are_returned_unchanged(): void {
		$api = [ '1' => [ 'class_type' => 'SaveImage', 'inputs' => [] ] ];

		$this->assertSame( $api, Comfy_Graph::to_api( $api, $this->object_info() ) );
	}

	/**
	 * Conversion cannot name positional widget values without a catalog, so it
	 * must refuse rather than emit a graph with missing inputs.
	 */
	public function test_conversion_requires_a_node_catalog(): void {
		$result = Comfy_Graph::to_api( $this->graph(), [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_comfy_graph_no_catalog', $result->get_error_code() );
	}

	/**
	 * Subgraph interiors are inlined, and the boundary link is re-pointed at the
	 * node in the parent graph that feeds it.
	 */
	public function test_subgraphs_are_expanded_and_boundaries_resolved(): void {
		$api = Comfy_Graph::to_api( $this->graph(), $this->object_info() );

		$this->assertIsArray( $api );

		$sampler = $this->only( $api, 'KSampler' );

		$positive = $api[ $sampler['inputs']['positive'][0] ];
		$this->assertSame( 'CLIPTextEncode', $positive['class_type'] );
		$this->assertSame( 'a lighthouse at dusk', $positive['inputs']['text'] );

		$negative = $api[ $sampler['inputs']['negative'][0] ];
		$this->assertSame( 'blurry, low quality', $negative['inputs']['text'] );
	}

	/**
	 * Positional widget values are named from the catalog, and the unnamed
	 * "control after generate" value that follows a seed is discarded.
	 */
	public function test_widget_values_are_named_and_seed_control_is_dropped(): void {
		$api     = Comfy_Graph::to_api( $this->graph(), $this->object_info() );
		$sampler = $this->only( $api, 'KSampler' );

		$this->assertSame( 42, $sampler['inputs']['seed'] );
		$this->assertSame( 28, $sampler['inputs']['steps'] );
		$this->assertSame( 6.5, $sampler['inputs']['cfg'] );
		$this->assertSame( 'euler', $sampler['inputs']['sampler_name'] );
		$this->assertSame( 'normal', $sampler['inputs']['scheduler'] );
		$this->assertSame( 1, $sampler['inputs']['denoise'] );
		$this->assertArrayNotHasKey( 'control_after_generate', $sampler['inputs'] );
	}

	/**
	 * Reroutes and bypassed nodes are removed and their consumers re-pointed at
	 * the node that actually produces the value.
	 */
	public function test_reroutes_and_bypassed_nodes_are_routed_around(): void {
		$api  = Comfy_Graph::to_api( $this->graph(), $this->object_info() );
		$save = $this->only( $api, 'SaveImage' );

		$this->assertEmpty( $this->by_class( $api, 'ImageInvert' ) );
		$this->assertSame( 'VAEDecode', $api[ $save['inputs']['images'][0] ]['class_type'] );
	}

	/**
	 * Editor-only and unreachable nodes never reach the executor.
	 */
	public function test_editor_only_and_unreachable_nodes_are_pruned(): void {
		$api = Comfy_Graph::to_api( $this->graph(), $this->object_info() );

		foreach ( $api as $node ) {
			$this->assertNotSame( 'MarkdownNote', $node['class_type'] );
		}
		$this->assertCount( 7, $api );
	}

	/**
	 * Positive prompt copy is replaced while Template negative text is retained.
	 */
	public function test_prompt_placeholders_preserve_template_negative_text(): void {
		$api = Comfy_Graph::apply_prompt_placeholders( Comfy_Graph::to_api( $this->graph(), $this->object_info() ) );

		$texts = [];
		foreach ( $this->by_class( $api, 'CLIPTextEncode' ) as $node ) {
			$texts[] = $node['inputs']['text'];
		}
		sort( $texts );

		$this->assertSame( [ 'blurry, low quality', '{{prompt}}' ], $texts );
	}

	/** Distinct negative branches remain distinct when positive copy is bound. */
	public function test_prompt_placeholders_preserve_multiple_negative_branches(): void {
		$api = [
			'positive' => [ 'class_type' => 'CLIPTextEncode', 'inputs' => [ 'text' => 'demo' ] ],
			'negative_a' => [ 'class_type' => 'CLIPTextEncode', 'inputs' => [ 'text' => 'avoid blur' ] ],
			'negative_b' => [ 'class_type' => 'CLIPTextEncode', 'inputs' => [ 'text' => 'avoid motion' ] ],
			'sampler_a' => [ 'class_type' => 'KSampler', 'inputs' => [ 'positive' => [ 'positive', 0 ], 'negative' => [ 'negative_a', 0 ] ] ],
			'sampler_b' => [ 'class_type' => 'KSampler', 'inputs' => [ 'positive' => [ 'positive', 0 ], 'negative' => [ 'negative_b', 0 ] ] ],
		];

		$bound = Comfy_Graph::apply_prompt_placeholders( $api );

		$this->assertSame( '{{prompt}}', $bound['positive']['inputs']['text'] );
		$this->assertSame( 'avoid blur', $bound['negative_a']['inputs']['text'] );
		$this->assertSame( 'avoid motion', $bound['negative_b']['inputs']['text'] );
	}

	/** A literal demo filename is replaced by the uploaded runtime filename. */
	public function test_single_literal_load_image_is_bound_end_to_end(): void {
		$api = [
			'loader' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'demo.png' ] ],
			'save'   => [ 'class_type' => 'SaveImage', 'inputs' => [ 'images' => [ 'loader', 0 ] ] ],
		];

		$prepared = Comfy_Graph::apply_media_placeholders( $api, [ 'image' ] );
		$this->assertIsArray( $prepared );
		$this->assertSame( '{{image}}', $prepared['loader']['inputs']['image'] );

		$substitute = new ReflectionMethod( Local_ComfyUI::class, 'apply_inputs' );
		$substitute->setAccessible( true );
		$submitted = $substitute->invoke( null, $prepared, [ 'image' => 'worldgraph/uploaded.png' ] );

		$this->assertSame( 'worldgraph/uploaded.png', $submitted['loader']['inputs']['image'] );
	}

	/** Explicit loader placeholders are authoritative and remain unchanged. */
	public function test_explicit_media_placeholder_is_preserved(): void {
		$api = [
			'loader' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => '{{image}}' ] ],
		];

		$this->assertSame( $api, Comfy_Graph::apply_media_placeholders( $api, [ 'image' ] ) );
		$this->assertSame( [ 'image' ], Comfy_Graph::media_placeholders( $api ) );
	}

	/** Explicit bindings leave Template-owned mask/reference loaders untouched. */
	public function test_explicit_image_placeholder_does_not_consume_auxiliary_loader(): void {
		$api = [
			'input' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => '{{image}}' ] ],
			'mask'  => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'template-mask.png' ] ],
		];

		$this->assertSame( $api, Comfy_Graph::apply_media_placeholders( $api, [ 'image' ] ) );
	}

	/** Optional end-frame inference runs only when that slot has a value. */
	public function test_explicit_start_frame_leaves_auxiliary_loader_until_end_frame_is_requested(): void {
		$api = [
			'start' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => '{{start_frame}}' ] ],
			'aux'   => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'template-reference.png' ] ],
		];

		$without_end = Comfy_Graph::apply_media_placeholders( $api, [ 'start_frame', 'end_frame' ], [ 'start_frame' ] );
		$this->assertSame( $api, $without_end );

		$with_end = Comfy_Graph::apply_media_placeholders( $api, [ 'start_frame', 'end_frame' ], [ 'start_frame', 'end_frame' ] );
		$this->assertIsArray( $with_end );
		$this->assertSame( '{{end_frame}}', $with_end['aux']['inputs']['image'] );
	}

	/** A topology-proven requested frame also leaves unrelated literals alone. */
	public function test_inferred_start_frame_does_not_consume_auxiliary_loader(): void {
		$api = [
			'start' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'demo-start.png' ] ],
			'guide' => [ 'class_type' => 'LTXVAddGuide', 'inputs' => [ 'image' => [ 'start', 0 ], 'frame_idx' => 0 ] ],
			'aux'   => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'template-reference.png' ] ],
		];

		$result = Comfy_Graph::apply_media_placeholders( $api, [ 'start_frame', 'end_frame' ], [ 'start_frame' ] );

		$this->assertIsArray( $result );
		$this->assertSame( '{{start_frame}}', $result['start']['inputs']['image'] );
		$this->assertSame( 'template-reference.png', $result['aux']['inputs']['image'] );
	}

	/** Two anonymous loaders cannot safely be guessed for a single image slot. */
	public function test_multiple_literal_image_loaders_fail_closed(): void {
		$api = [
			'one' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'one.png' ] ],
			'two' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'two.png' ] ],
		];

		$result = Comfy_Graph::apply_media_placeholders( $api, [ 'image' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_comfy_media_binding_ambiguous', $result->get_error_code() );
		$this->assertCount( 2, $result->get_error_data()['candidates'] );
	}

	/** A placeholder outside a proven loader target is rejected. */
	public function test_media_placeholder_outside_load_image_fails_closed(): void {
		$api = [
			'loader' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'demo.png' ] ],
			'note'   => [ 'class_type' => 'PrimitiveString', 'inputs' => [ 'value' => '{{image}}' ] ],
		];

		$result = Comfy_Graph::apply_media_placeholders( $api, [ 'image' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_comfy_media_placeholder_unsafe', $result->get_error_code() );
	}

	/** Guide frame indices prove which literal loader is the first and last frame. */
	public function test_start_and_end_frames_are_inferred_from_workflow_topology(): void {
		$api = [
			'start_loader' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'demo-start.png' ] ],
			'resize'       => [ 'class_type' => 'ImageScale', 'inputs' => [ 'image' => [ 'start_loader', 0 ] ] ],
			'start_guide'  => [ 'class_type' => 'LTXVAddGuide', 'inputs' => [ 'image' => [ 'resize', 0 ], 'frame_idx' => 0 ] ],
			'end_loader'   => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'demo-end.png' ] ],
			'end_guide'    => [ 'class_type' => 'LTXVAddGuide', 'inputs' => [ 'image' => [ 'end_loader', 0 ], 'frame_idx' => -1 ] ],
		];

		$result = Comfy_Graph::apply_media_placeholders( $api, [ 'start_frame', 'end_frame' ] );

		$this->assertIsArray( $result );
		$this->assertSame( '{{start_frame}}', $result['start_loader']['inputs']['image'] );
		$this->assertSame( '{{end_frame}}', $result['end_loader']['inputs']['image'] );
		$this->assertEqualsCanonicalizing( [ 'start_frame', 'end_frame' ], Comfy_Graph::media_placeholders( $result ) );

		$substitute = new ReflectionMethod( Local_ComfyUI::class, 'apply_inputs' );
		$substitute->setAccessible( true );
		$submitted = $substitute->invoke( null, $result, [
			'start_frame' => 'worldgraph/first.png',
			'end_frame'   => 'worldgraph/last.png',
		] );
		$this->assertSame( 'worldgraph/first.png', $submitted['start_loader']['inputs']['image'] );
		$this->assertSame( 'worldgraph/last.png', $submitted['end_loader']['inputs']['image'] );
	}

	/** WAN's named consumer sockets prove its otherwise anonymous frame loaders. */
	public function test_wan_first_last_frame_sockets_bind_both_loaders(): void {
		$api = [
			'start_loader' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'wan-start.png' ] ],
			'end_loader'   => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'wan-end.png' ] ],
			'conditioning' => [
				'class_type' => 'WanFirstLastFrameToVideo',
				'inputs'     => [
					'start_image' => [ 'start_loader', 0 ],
					'end_image'   => [ 'end_loader', 0 ],
				],
			],
		];

		$result = Comfy_Graph::apply_media_placeholders( $api, [ 'start_frame', 'end_frame' ] );

		$this->assertIsArray( $result );
		$this->assertSame( '{{start_frame}}', $result['start_loader']['inputs']['image'] );
		$this->assertSame( '{{end_frame}}', $result['end_loader']['inputs']['image'] );
	}

	/** Anonymous two-frame graphs require explicit slot markers. */
	public function test_two_unlabelled_frame_loaders_fail_closed(): void {
		$api = [
			'one' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'one.png' ] ],
			'two' => [ 'class_type' => 'LoadImage', 'inputs' => [ 'image' => 'two.png' ] ],
		];

		$result = Comfy_Graph::apply_media_placeholders( $api, [ 'start_frame', 'end_frame' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_comfy_media_binding_ambiguous', $result->get_error_code() );
	}

	/**
	 * A published workflow ships a fixed seed, which would make every render
	 * identical unless it is re-rolled.
	 */
	public function test_seeds_are_randomized(): void {
		$api     = Comfy_Graph::randomize_seeds( Comfy_Graph::to_api( $this->graph(), $this->object_info() ) );
		$sampler = $this->only( $api, 'KSampler' );

		$this->assertNotSame( 42, $sampler['inputs']['seed'] );
		$this->assertIsInt( $sampler['inputs']['seed'] );
	}
}
