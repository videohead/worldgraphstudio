<?php
/**
 * Tests for safe Template runtime control discovery and workflow binding.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal WP_Error stand-in for the pure utility tests. */
	class WP_Error {
		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
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
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/template-run-controls.php';

use WorldGraph\Utils\Template_Run_Controls;

/**
 * Template runtime control contract tests.
 */
class Test_Template_Run_Controls extends TestCase {

	/**
	 * Return one field from a description by key.
	 *
	 * @param array  $description Runtime-control description.
	 * @param string $key         Field key.
	 * @return array<string,mixed>
	 */
	private function field( array $description, string $key ): array {
		foreach ( $description['fields'] as $field ) {
			if ( $key === (string) ( $field['key'] ?? '' ) ) {
				return $field;
			}
		}

		$this->fail( "Missing run-control field {$key}." );
	}

	/**
	 * Return all advertised field keys.
	 *
	 * @param array $description Runtime-control description.
	 * @return array<int,string>
	 */
	private function keys( array $description ): array {
		return array_column( $description['fields'], 'key' );
	}

	/**
	 * Representative API-format image/video graph with split SDXL conditioning.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function workflow(): array {
		return [
			'positive' => [
				'class_type' => 'CLIPTextEncodeSDXL',
				'inputs'     => [
					'text_g' => 'positive demo global',
					'text_l' => 'positive demo local',
					'clip'   => [ 'loader', 1 ],
				],
			],
			'negative' => [
				'class_type' => 'CLIPTextEncodeSDXL',
				'inputs'     => [
					'text_g' => 'negative demo',
					'text_l' => 'negative demo',
					'clip'   => [ 'loader', 1 ],
				],
			],
			'loader'   => [
				'class_type' => 'CheckpointLoaderSimple',
				'inputs'     => [ 'ckpt_name' => 'model.safetensors' ],
			],
			'steps'    => [
				'class_type' => 'PrimitiveNode',
				'inputs'     => [ 'value' => 28 ],
				'_meta'      => [ 'title' => 'Steps' ],
			],
			'sampler'  => [
				'class_type' => 'KSampler',
				'inputs'     => [
					'seed'         => 99,
					'steps'        => [ 'steps', 0 ],
					'cfg'          => 7.5,
					'guidance'     => 3.5,
					'sampler_name' => 'euler',
					'scheduler'    => 'normal',
					'denoise'      => 1.0,
					'positive'     => [ 'positive', 0 ],
					'negative'     => [ 'negative', 0 ],
				],
			],
			'latent'   => [
				'class_type' => 'EmptyLatentImage',
				'inputs'     => [ 'width' => 1024, 'height' => 768, 'batch_size' => 1 ],
			],
			'video'    => [
				'class_type' => 'CreateVideo',
				'inputs'     => [ 'fps' => 24, 'duration' => 4.0 ],
			],
		];
	}

	/**
	 * Provider schemas become bounded UI-native fields, not browser bindings.
	 */
	public function test_provider_schema_is_normalized_to_safe_ui_dto(): void {
		$configuration = [
			'input'           => [
				'guidance_scale' => 0,
				'cfg_scale'      => 6.5,
				'auto_enhance'   => false,
				'aspect_ratio'  => '16:9',
				'prompt'       => 'owned by the Story Graph',
				'model_id'     => 'secret-model-selection',
				'callback_url' => 'https://example.test/hook',
				'num_images'   => 4,
				'advanced'     => [ 'unsafe' => true ],
			],
			'provider_schema' => [
				'properties' => [
					'guidance_scale' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 20, 'title' => 'Guidance' ],
					'cfg_scale'      => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 30 ],
					'auto_enhance'   => [ 'type' => 'boolean', 'default' => true ],
					'aspect_ratio'  => [ 'type' => 'string', 'enum' => [ '1:1', '16:9' ] ],
					'prompt'       => [ 'type' => 'string' ],
					'model_id'     => [ 'type' => 'string' ],
					'callback_url' => [ 'type' => 'string' ],
					'access_key'   => [ 'type' => 'string', 'default' => 'do-not-leak' ],
					'temporary_note' => [ 'type' => 'string', 'default' => 'do-not-leak', 'writeOnly' => true ],
					'num_images'   => [ 'type' => 'integer' ],
					'advanced'     => [ 'type' => 'object' ],
				],
			],
		];

		$description = Template_Run_Controls::describe_configuration( $configuration );

		$this->assertSame( 1, $description['version'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $description['fingerprint'] );
		$this->assertContains( 'guidance_scale', $this->keys( $description ) );
		$this->assertContains( 'cfg_scale', $this->keys( $description ) );
		$this->assertContains( 'auto_enhance', $this->keys( $description ) );
		$this->assertNotContains( 'prompt', $this->keys( $description ) );
		$this->assertNotContains( 'model_id', $this->keys( $description ) );
		$this->assertNotContains( 'callback_url', $this->keys( $description ) );
		$this->assertNotContains( 'access_key', $this->keys( $description ) );
		$this->assertNotContains( 'temporary_note', $this->keys( $description ) );
		$this->assertNotContains( 'num_images', $this->keys( $description ) );
		$this->assertNotContains( 'advanced', $this->keys( $description ) );

		$guidance = $this->field( $description, 'guidance_scale' );
		$this->assertSame( 'number', $guidance['type'] );
		$this->assertSame( 0.0, $guidance['default'] );
		$this->assertSame( 0.0, $guidance['min'] );
		$this->assertSame( 20.0, $guidance['max'] );
		$this->assertSame( 'sampling', $guidance['group'] );
		$this->assertArrayNotHasKey( 'minimum', $guidance );
		$this->assertArrayNotHasKey( 'maximum', $guidance );

		$boolean = $this->field( $description, 'auto_enhance' );
		$this->assertSame( 'boolean', $boolean['type'] );
		$this->assertFalse( $boolean['default'] );

		$ratio = $this->field( $description, 'aspect_ratio' );
		$this->assertSame( 'select', $ratio['type'] );
		$this->assertSame(
			[
				[ 'value' => '1:1', 'label' => '1:1' ],
				[ 'value' => '16:9', 'label' => '16:9' ],
			],
			$ratio['options']
		);
		$this->assertArrayNotHasKey( 'enum', $ratio );
	}

	/**
	 * Explicit controls override discovery while dangerous paths remain ignored.
	 */
	public function test_explicit_controls_are_normalized_without_exposing_bindings(): void {
		$configuration = [
			'run_controls' => [
				'negative_prompt' => [
					'label'       => '<b>Avoid</b>',
					'type'        => 'textarea',
					'default'     => 'grain, blur',
					'description' => '<script>unsafe()</script> Be specific.',
					'path'        => 'sampler.inputs.negative',
				],
				'steps'          => [ 'type' => 'integer', 'min' => 4, 'max' => 60, 'default' => 32, 'node_id' => 'sampler' ],
				'prompt'         => [ 'type' => 'textarea' ],
				'credential'     => [ 'type' => 'string' ],
			],
		];

		$description = Template_Run_Controls::describe_configuration( $configuration );
		$negative    = $this->field( $description, 'negative_prompt' );
		$steps       = $this->field( $description, 'steps' );

		$this->assertSame( 'textarea', $negative['type'] );
		$this->assertSame( 'Avoid', $negative['label'] );
		$this->assertSame( 'unsafe() Be specific.', $negative['description'] );
		$this->assertSame( 'grain, blur', $negative['default'] );
		$this->assertArrayNotHasKey( 'path', $negative );
		$this->assertArrayNotHasKey( 'node_id', $steps );
		$this->assertSame( 4, $steps['min'] );
		$this->assertSame( 60, $steps['max'] );
		$this->assertNotContains( 'prompt', $this->keys( $description ) );
		$this->assertNotContains( 'credential', $this->keys( $description ) );
	}

	/**
	 * Validation rejects unknown/type/range/enum errors and preserves 0/false.
	 */
	public function test_validation_is_strict_and_preserves_falsey_scalars(): void {
		$description = Template_Run_Controls::describe_configuration(
			[
				'input'           => [ 'steps' => 20, 'guidance_scale' => 5, 'auto_enhance' => true, 'aspect_ratio' => '1:1' ],
				'provider_schema' => [
					'properties' => [
						'steps'          => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ],
						'guidance_scale' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 20 ],
						'auto_enhance'   => [ 'type' => 'boolean' ],
						'aspect_ratio'  => [ 'type' => 'string', 'enum' => [ '1:1', '16:9' ] ],
					],
				],
			]
		);

		$valid = Template_Run_Controls::validate_description(
			$description,
			[ 'steps' => '1', 'guidance_scale' => '0', 'auto_enhance' => 'false', 'aspect_ratio' => '16:9' ]
		);
		$this->assertSame( [ 'aspect_ratio', 'auto_enhance', 'guidance_scale', 'steps' ], array_keys( $valid ) );
		$this->assertSame( 1, $valid['steps'] );
		$this->assertSame( 0.0, $valid['guidance_scale'] );
		$this->assertFalse( $valid['auto_enhance'] );
		$this->assertSame( '16:9', $valid['aspect_ratio'] );

		$errors = [
			'worldgraph_run_control_unknown' => [ 'made_up' => 1 ],
			'worldgraph_run_control_type'    => [ 'steps' => '1.5' ],
			'worldgraph_run_control_range'   => [ 'steps' => 999 ],
			'worldgraph_run_control_enum'    => [ 'aspect_ratio' => '2:3' ],
		];
		foreach ( $errors as $code => $submitted ) {
			$result = Template_Run_Controls::validate_description( $description, $submitted );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( $code, $result->get_error_code() );
		}

		$stepped = Template_Run_Controls::validate_description(
			[ 'fields' => [ [ 'key' => 'fps', 'type' => 'number', 'min' => 1, 'max' => 240, 'step' => 0.001 ] ] ],
			[ 'fps' => 23.9765 ]
		);
		$this->assertSame( 'worldgraph_run_control_range', $stepped->get_error_code() );
	}

	/**
	 * Workflow values are display defaults, except seed and positive split text.
	 */
	public function test_workflow_discovery_omits_seed_default_and_clears_dual_defaults(): void {
		$description = Template_Run_Controls::describe_configuration( [], [ 'workflow' => $this->workflow() ] );

		$seed     = $this->field( $description, 'seed' );
		$text_g   = $this->field( $description, 'text_g' );
		$text_l   = $this->field( $description, 'text_l' );
		$negative = $this->field( $description, 'negative_prompt' );

		$this->assertArrayNotHasKey( 'default', $seed );
		$this->assertSame( 9007199254740991, $seed['max'] );
		$this->assertSame( [ 'seed' => 123 ], Template_Run_Controls::validate_description( $description, [ 'seed' => ' 123 ' ] ) );
		$too_large = Template_Run_Controls::validate_description( $description, [ 'seed' => '9007199254740992' ] );
		$this->assertInstanceOf( WP_Error::class, $too_large );
		$this->assertSame( 'worldgraph_run_control_range', $too_large->get_error_code() );
		$this->assertSame( '', $text_g['default'] );
		$this->assertSame( '', $text_l['default'] );
		$this->assertSame( 'negative demo', $negative['default'] );
		$this->assertSame( 28, $this->field( $description, 'steps' )['default'] );
		$this->assertSame( 0.001, $this->field( $description, 'fps' )['step'] );
		$this->assertSame( 'textarea', $text_g['type'] );
		$this->assertNotContains( 'clip_l', $this->keys( $description ) );
		$this->assertNotContains( 't5xxl', $this->keys( $description ) );

		$defaults = Template_Run_Controls::description_defaults( $description );
		$this->assertArrayNotHasKey( 'seed', $defaults );
		$this->assertSame( '', $defaults['text_g'] );
		$this->assertSame( 'negative demo', $defaults['negative_prompt'] );
		$this->assertSame( 28, $defaults['steps'] );
		$this->assertSame(
			$this->workflow(),
			Template_Run_Controls::apply_description_to_workflow( $description, $this->workflow(), [] )
		);

		$zero = Template_Run_Controls::validate_description( $description, [ 'seed' => '0' ] );
		$this->assertSame( 0, $zero['seed'] );
		$empty = Template_Run_Controls::validate_description( $description, [ 'seed' => '' ] );
		$this->assertSame( 'worldgraph_run_control_type', $empty->get_error_code() );
	}

	/**
	 * Workflow application keeps CFG/guidance separate and follows primitives.
	 */
	public function test_workflow_application_uses_server_derived_targets(): void {
		$workflow = Template_Run_Controls::apply_values_to_workflow(
			$this->workflow(),
			[
				'seed'            => 0,
				'steps'           => 18,
				'cfg'             => 0.0,
				'guidance'        => 2.25,
				'negative_prompt' => 'artifact, blur',
				'text_g'          => 'global composition',
				'text_l'          => '',
				'fps'             => 30.0,
				'unknown.path'    => 'ignored',
			]
		);

		$this->assertSame( 0, $workflow['sampler']['inputs']['seed'] );
		$this->assertSame( [ 'steps', 0 ], $workflow['sampler']['inputs']['steps'] );
		$this->assertSame( 18, $workflow['steps']['inputs']['value'] );
		$this->assertSame( 0.0, $workflow['sampler']['inputs']['cfg'] );
		$this->assertSame( 2.25, $workflow['sampler']['inputs']['guidance'] );
		$this->assertSame( 30.0, $workflow['video']['inputs']['fps'] );
		$this->assertSame( 'global composition', $workflow['positive']['inputs']['text_g'] );
		$this->assertSame( 'positive demo local', $workflow['positive']['inputs']['text_l'] );
		$this->assertSame( 'artifact, blur', $workflow['negative']['inputs']['text_g'] );
		$this->assertSame( 'artifact, blur', $workflow['negative']['inputs']['text_l'] );
		$this->assertSame( 'model.safetensors', $workflow['loader']['inputs']['ckpt_name'] );
	}

	/**
	 * Negative-conditioning traversal never treats framing primitives as text.
	 */
	public function test_negative_prompt_does_not_mutate_unrelated_conditioning_inputs(): void {
		$workflow = [
			'sampler'      => [ 'class_type' => 'KSampler', 'inputs' => [ 'negative' => [ 'conditioning', 1 ] ] ],
			'conditioning' => [
				'class_type' => 'LTXVConditioning',
				'inputs'     => [
					'negative'   => [ 'negative_text', 0 ],
					'positive'   => [ 'positive_text', 0 ],
					'frame_rate' => [ 'fps', 0 ],
					'width'      => [ 'width', 0 ],
				],
			],
			'negative_text' => [ 'class_type' => 'CLIPTextEncode', 'inputs' => [ 'text' => 'old negative' ] ],
			'positive_text' => [ 'class_type' => 'CLIPTextEncode', 'inputs' => [ 'text' => 'old positive' ] ],
			'fps'           => [ 'class_type' => 'PrimitiveInt', 'inputs' => [ 'value' => 24 ], '_meta' => [ 'title' => 'Int (FPS)' ] ],
			'width'         => [ 'class_type' => 'PrimitiveInt', 'inputs' => [ 'value' => 1280 ], '_meta' => [ 'title' => 'Int (Width)' ] ],
		];

		$applied = Template_Run_Controls::apply_values_to_workflow( $workflow, [ 'negative_prompt' => 'new negative' ] );
		$this->assertSame( 'new negative', $applied['negative_text']['inputs']['text'] );
		$this->assertSame( 'old positive', $applied['positive_text']['inputs']['text'] );
		$this->assertSame( 24, $applied['fps']['inputs']['value'] );
		$this->assertSame( 1280, $applied['width']['inputs']['value'] );
	}

	/** Legacy negative placeholders never become visible Template defaults. */
	public function test_negative_placeholder_is_not_a_template_default(): void {
		$workflow = $this->workflow();
		$workflow['negative']['inputs']['text_g'] = '{{negative_prompt}}';
		$workflow['negative']['inputs']['text_l'] = '{{negative_prompt}}';

		$field = $this->field( Template_Run_Controls::describe_configuration( [], [ 'workflow' => $workflow ] ), 'negative_prompt' );

		$this->assertArrayNotHasKey( 'default', $field );
	}

	/**
	 * Merged runner metadata is projected away before trusted values are applied.
	 */
	public function test_description_application_ignores_unknown_merged_runner_values(): void {
		$description = Template_Run_Controls::describe_configuration( [], [ 'workflow' => $this->workflow() ] );
		$workflow    = Template_Run_Controls::apply_description_to_workflow(
			$description,
			$this->workflow(),
			[
				'steps'             => 16,
				'inputs'            => [ 'image' => 42 ],
				'_worldgraph_job_id' => 900,
				'checkpoint'        => 'do-not-apply.safetensors',
			]
		);

		$this->assertSame( 16, $workflow['steps']['inputs']['value'] );
		$this->assertSame( 'model.safetensors', $workflow['loader']['inputs']['ckpt_name'] );
	}

	/**
	 * Switch branches are writable only through semantically titled constants.
	 */
	public function test_switch_linked_titled_primitives_are_discovered_and_mutated(): void {
		$workflow = [
			'sampler'     => [
				'class_type' => 'KSampler',
				'inputs'     => [ 'steps' => [ 'step_switch', 0 ], 'cfg' => [ 'cfg_switch', 0 ] ],
			],
			'step_switch' => [
				'class_type' => 'ComfySwitchNode',
				'inputs'     => [ 'on_true' => [ 'steps_a', 0 ], 'on_false' => [ 'steps_b', 0 ], 'select' => [ 'toggle', 0 ] ],
			],
			'cfg_switch'  => [
				'class_type' => 'ComfySwitchNode',
				'inputs'     => [ 'on_true' => [ 'cfg_a', 0 ], 'on_false' => [ 'cfg_b', 0 ], 'select' => [ 'toggle', 0 ] ],
			],
			'steps_a'     => [ 'class_type' => 'PrimitiveInt', 'inputs' => [ 'value' => 24 ], '_meta' => [ 'title' => 'Int (Steps)' ] ],
			'steps_b'     => [ 'class_type' => 'PrimitiveInt', 'inputs' => [ 'value' => 24 ], '_meta' => [ 'title' => 'Int (Steps)' ] ],
			'cfg_a'       => [ 'class_type' => 'PrimitiveFloat', 'inputs' => [ 'value' => 5.5 ], '_meta' => [ 'title' => 'Float(CFG)' ] ],
			'cfg_b'       => [ 'class_type' => 'PrimitiveFloat', 'inputs' => [ 'value' => 5.5 ], '_meta' => [ 'title' => 'Float (CFG)' ] ],
			'toggle'      => [ 'class_type' => 'PrimitiveBoolean', 'inputs' => [ 'value' => true ], '_meta' => [ 'title' => 'Quality mode' ] ],
		];

		$description = Template_Run_Controls::describe_configuration( [], [ 'workflow' => $workflow ] );
		$this->assertContains( 'steps', $this->keys( $description ) );
		$this->assertContains( 'cfg', $this->keys( $description ) );

		$applied = Template_Run_Controls::apply_values_to_workflow( $workflow, [ 'steps' => 12, 'cfg' => 0.0 ] );
		$this->assertSame( 12, $applied['steps_a']['inputs']['value'] );
		$this->assertSame( 12, $applied['steps_b']['inputs']['value'] );
		$this->assertSame( 0.0, $applied['cfg_a']['inputs']['value'] );
		$this->assertSame( 0.0, $applied['cfg_b']['inputs']['value'] );
		$this->assertTrue( $applied['toggle']['inputs']['value'] );
	}

	/**
	 * One public control never collapses intentionally different stage values.
	 */
	public function test_differing_multi_target_values_are_not_advertised(): void {
		$workflow = [
			'sampler' => [ 'class_type' => 'KSampler', 'inputs' => [ 'steps' => [ 'switch', 0 ] ] ],
			'switch'  => [ 'class_type' => 'ComfySwitchNode', 'inputs' => [ 'a' => [ 'low', 0 ], 'b' => [ 'high', 0 ] ] ],
			'low'     => [ 'class_type' => 'PrimitiveInt', 'inputs' => [ 'value' => 4 ], '_meta' => [ 'title' => 'Int (Steps)' ] ],
			'high'    => [ 'class_type' => 'PrimitiveInt', 'inputs' => [ 'value' => 20 ], '_meta' => [ 'title' => 'Int (Steps)' ] ],
		];

		$description = Template_Run_Controls::describe_configuration( [], [ 'workflow' => $workflow ] );
		$this->assertNotContains( 'steps', $this->keys( $description ) );
	}

	/**
	 * A semantic socket linked through an unsupported executable is not advertised.
	 */
	public function test_unresolved_non_primitive_links_do_not_advertise_noop_controls(): void {
		$workflow = [
			'latent' => [ 'class_type' => 'EmptyVideoLatent', 'inputs' => [ 'length' => [ 'math', 0 ], 'width' => [ 'width', 0 ] ] ],
			'math'   => [ 'class_type' => 'ComfyMathExpression', 'inputs' => [ 'a' => 97, 'expression' => 'a * 2' ] ],
			'width'  => [ 'class_type' => 'PrimitiveInt', 'inputs' => [ 'value' => 1024 ], '_meta' => [ 'title' => 'Int (Width)' ] ],
		];

		$description = Template_Run_Controls::describe_configuration( [], [ 'workflow' => $workflow ] );
		$this->assertContains( 'width', $this->keys( $description ) );
		$this->assertNotContains( 'length', $this->keys( $description ) );

		$applied = Template_Run_Controls::apply_values_to_workflow( $workflow, [ 'length' => 12, 'width' => 768 ] );
		$this->assertSame( 97, $applied['math']['inputs']['a'] );
		$this->assertSame( 768, $applied['width']['inputs']['value'] );
	}

	/**
	 * Flux split conditioning is advertised only with matching positive sockets.
	 */
	public function test_flux_dual_controls_require_corresponding_encoder_inputs(): void {
		$configuration = [
			'run_controls' => [
				'clip_l' => [ 'type' => 'textarea', 'default' => 'demo' ],
				't5xxl'  => [ 'type' => 'textarea', 'default' => 'demo' ],
				'text_g' => [ 'type' => 'textarea', 'default' => 'demo' ],
			],
		];
		$workflow = [
			'positive' => [ 'class_type' => 'CLIPTextEncodeFlux', 'inputs' => [ 'clip_l' => 'demo', 't5xxl' => 'demo' ] ],
		];

		$description = Template_Run_Controls::describe_configuration( $configuration, [ 'workflow' => $workflow ] );
		$this->assertContains( 'clip_l', $this->keys( $description ) );
		$this->assertContains( 't5xxl', $this->keys( $description ) );
		$this->assertNotContains( 'text_g', $this->keys( $description ) );
		$this->assertSame( '', $this->field( $description, 'clip_l' )['default'] );
	}

	/**
	 * Built-in defaults are fallback-only and never advertise no-op controls.
	 */
	public function test_comfy_builtins_are_only_used_without_a_stored_workflow(): void {
		$fallback = Template_Run_Controls::describe_configuration(
			[],
			[ 'provider_type' => 'comfyui', 'modality' => 'text_to_image', 'workflow' => [] ]
		);
		$this->assertContains( 'width', $this->keys( $fallback ) );
		$this->assertContains( 'denoise', $this->keys( $fallback ) );
		$this->assertSame( 'select', $this->field( $fallback, 'sampler' )['type'] );

		$stored = Template_Run_Controls::describe_configuration(
			[],
			[
				'provider_type' => 'comfyui',
				'modality'      => 'text_to_image',
				'workflow'      => [ 'sampler' => [ 'class_type' => 'KSampler', 'inputs' => [ 'steps' => 12 ] ] ],
			]
		);
		$this->assertSame( [ 'steps' ], $this->keys( $stored ) );
	}

	/**
	 * Workflow choices and explicit UI-native selects remain constrained selects.
	 */
	public function test_workflow_choices_and_explicit_selects_use_ui_options(): void {
		$workflow_description = Template_Run_Controls::describe_configuration(
			[ 'provider_schema' => [ 'properties' => [ 'steps' => [ 'type' => 'integer' ] ] ] ],
			[ 'workflow' => $this->workflow() ]
		);
		$sampler             = $this->field( $workflow_description, 'sampler' );
		$scheduler           = $this->field( $workflow_description, 'scheduler' );
		$this->assertSame( 'select', $sampler['type'] );
		$this->assertSame( 'select', $scheduler['type'] );
		$this->assertContains( 'euler', array_column( $sampler['options'], 'value' ) );
		$this->assertSame( 28, $this->field( $workflow_description, 'steps' )['default'] );

		$explicit = Template_Run_Controls::describe_configuration(
			[
				'run_controls' => [
					'style' => [
						'type'    => 'select',
						'options' => [
							[ 'value' => 'cinematic', 'label' => 'Cinematic' ],
							[ 'value' => 'natural', 'label' => 'Natural' ],
						],
					],
				],
			]
		);
		$style = $this->field( $explicit, 'style' );
		$this->assertSame( 'select', $style['type'] );
		$this->assertSame( 'Cinematic', $style['options'][0]['label'] );
		$this->assertArrayNotHasKey( 'default', $style );
	}

	/** Explicit default_values override configuration input and user values win last. */
	public function test_template_default_precedence_is_consistent(): void {
		$description = Template_Run_Controls::describe_configuration(
			[
				'input'           => [ 'steps' => 20 ],
				'provider_schema' => [
					'properties' => [
						'steps'           => [ 'type' => 'integer', 'default' => 10 ],
						'negative_prompt' => [ 'type' => 'string', 'default' => 'watermark' ],
					],
				],
			],
			[ 'default_values' => [ 'steps' => 30 ] ]
		);

		$this->assertSame( 30, $this->field( $description, 'steps' )['default'] );
		$this->assertSame( 'watermark', $this->field( $description, 'negative_prompt' )['default'] );
		$this->assertSame( [ 'steps' => 40 ], Template_Run_Controls::validate_description( $description, [ 'steps' => 40 ] ) );
	}

	/** Different negative branches cannot be collapsed into one public override. */
	public function test_differing_negative_branches_are_preserved_not_advertised(): void {
		$workflow = [
			'negative_a' => [ 'class_type' => 'CLIPTextEncode', 'inputs' => [ 'text' => 'avoid blur' ] ],
			'negative_b' => [ 'class_type' => 'CLIPTextEncode', 'inputs' => [ 'text' => 'avoid motion' ] ],
			'sampler_a'  => [ 'class_type' => 'KSampler', 'inputs' => [ 'negative' => [ 'negative_a', 0 ] ] ],
			'sampler_b'  => [ 'class_type' => 'KSampler', 'inputs' => [ 'negative' => [ 'negative_b', 0 ] ] ],
		];

		$description = Template_Run_Controls::describe_configuration( [], [ 'workflow' => $workflow ] );

		$this->assertNotContains( 'negative_prompt', $this->keys( $description ) );
	}

	/**
	 * Project profiles override only declared framing fields, one value at a time.
	 */
	public function test_profile_defaults_are_source_scoped_alias_aware_and_independent(): void {
		$description = Template_Run_Controls::describe_configuration(
			[
				'input'           => [ 'width' => 1024, 'height' => 768, 'aspect_ratio' => '1:1', 'frame_rate' => 24, 'steps' => 20 ],
				'provider_schema' => [
					'properties' => [
						'width'        => [ 'type' => 'integer' ],
						'height'       => [ 'type' => 'integer' ],
						'aspect_ratio' => [ 'type' => 'string', 'enum' => [ '1:1', '16:9' ] ],
						'frame_rate'   => [ 'type' => 'number' ],
						'steps'        => [ 'type' => 'integer' ],
					],
				],
			]
		);

		$defaults = Template_Run_Controls::profile_defaults(
			$description,
			[
				'output'     => [ 'width' => 1, 'height' => 1080, 'aspect_ratio' => '16:9' ],
				'frame_rate' => 23.976,
				'steps'      => 99,
			]
		);

		$this->assertArrayNotHasKey( 'width', $defaults );
		$this->assertSame( 1080, $defaults['height'] );
		$this->assertSame( '16:9', $defaults['aspect_ratio'] );
		$this->assertSame( 23.976, $defaults['frame_rate'] );
		$this->assertArrayNotHasKey( 'steps', $defaults );
	}

	/**
	 * The fingerprint is based on normalized fields, not associative input order.
	 */
	public function test_fingerprint_is_stable_for_equivalent_normalized_contracts(): void {
		$left = Template_Run_Controls::describe_configuration(
			[
				'provider_schema' => [
					'properties' => [
						'steps' => [ 'type' => 'integer', 'default' => 20 ],
						'cfg'   => [ 'type' => 'number', 'default' => 7 ],
					],
				],
			]
		);
		$right = Template_Run_Controls::describe_configuration(
			[
				'provider_schema' => [
					'properties' => [
						'cfg'   => [ 'default' => 7, 'type' => 'number' ],
						'steps' => [ 'default' => 20, 'type' => 'integer' ],
					],
				],
			]
		);

		$this->assertSame( $left['fields'], $right['fields'] );
		$this->assertSame( $left['fingerprint'], $right['fingerprint'] );

		$changed = Template_Run_Controls::describe_configuration(
			[ 'provider_schema' => [ 'properties' => [ 'steps' => [ 'type' => 'integer', 'default' => 21 ] ] ] ]
		);
		$this->assertNotSame( $left['fingerprint'], $changed['fingerprint'] );
	}
}
