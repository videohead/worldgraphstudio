<?php
/**
 * Tests for the bundled ComfyUI-TemplateVars feature plugin.
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

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return trim( strip_tags( (string) $text ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/template-run-controls.php';
require_once dirname( __DIR__ ) . '/plugins/comfy-template-vars/includes/class-template-vars.php';
require_once dirname( __DIR__ ) . '/plugins/comfy-template-vars/includes/class-bindings.php';

use WorldGraph\Utils\Template_Run_Controls;
use WorldGraphComfyTemplateVars\Bindings;
use WorldGraphComfyTemplateVars\Template_Vars;

/**
 * ComfyUI-TemplateVars workflow contract tests.
 */
class Test_Comfy_Template_Vars extends TestCase {

	/** H3-style TemplateVars graph: variables -> merge -> apply -> prompt link. */
	private function workflow(): array {
		return [
			'200' => [ 'class_type' => 'TemplateVariable', 'inputs' => [ 'name' => 'prompt', 'value' => 'A barista pours coffee' ] ],
			'201' => [ 'class_type' => 'TemplateVariable', 'inputs' => [ 'name' => 'lighting', 'value' => 'Warm morning light' ] ],
			'202' => [ 'class_type' => 'TemplateVariable', 'inputs' => [ 'name' => ' camera ', 'value' => 'Slow dolly in' ] ],
			'203' => [ 'class_type' => 'TemplateVariable', 'inputs' => [ 'name' => 'audio', 'value' => [ '9', 0 ] ] ],
			'204' => [ 'class_type' => 'TemplateVariable', 'inputs' => [ 'name' => '9bad', 'value' => 'ignored' ] ],
			'205' => [ 'class_type' => 'TemplateVariable', 'inputs' => [ 'name' => 'lighting', 'value' => 'Duplicate' ] ],
			'210' => [
				'class_type' => 'MergeTemplateVariables',
				'inputs'     => [
					'variables.variables0' => [ '200', 0 ],
					'variables.variables1' => [ '201', 0 ],
				],
			],
			'211' => [
				'class_type' => 'ApplyTemplateVariables',
				'inputs'     => [
					'template'          => '{prompt}. Lighting: {lighting}. Camera: {camera}.',
					'placeholder_style' => '{name}',
					'missing'           => 'error',
					'variables'         => [ '210', 0 ],
				],
			],
			'104' => [ 'class_type' => 'MiniMaxH3ImageToVideo', 'inputs' => [ 'prompt' => [ '211', 0 ] ] ],
		];
	}

	public function test_variables_discovers_literal_valid_names_first_wins(): void {
		$this->assertSame(
			[
				'prompt'   => 'A barista pours coffee',
				'lighting' => 'Warm morning light',
				'camera'   => 'Slow dolly in',
			],
			Template_Vars::variables( $this->workflow() )
		);
	}

	public function test_control_fields_exclude_story_graph_prompt(): void {
		$keys = array_column( Template_Vars::control_fields( $this->workflow() ), 'key' );

		$this->assertSame( [ 'template_var_lighting', 'template_var_camera' ], $keys );
	}

	public function test_extension_fields_normalize_to_public_textarea_controls(): void {
		$fields = Template_Run_Controls::normalize_extension_fields( Template_Vars::control_fields( $this->workflow() ) );
		$by_key = array_column( $fields, null, 'key' );

		$this->assertSame( 'textarea', $by_key['template_var_lighting']['type'] );
		$this->assertSame( 'conditioning', $by_key['template_var_lighting']['group'] );
		$this->assertSame( 'Lighting', $by_key['template_var_lighting']['label'] );
		$this->assertSame( 'Warm morning light', $by_key['template_var_lighting']['default'] );
		$this->assertStringContainsString( '{lighting}', $by_key['template_var_lighting']['description'] );
	}

	public function test_extension_fields_respect_collisions_denylist_and_semantics(): void {
		$fields = Template_Run_Controls::normalize_extension_fields(
			[
				[ 'key' => 'template_var_lighting', 'type' => 'textarea' ],
				[ 'key' => 'template_var_camera', 'type' => 'textarea' ],
				[ 'key' => 'template_var_api_token', 'type' => 'string' ],
				[ 'key' => 'steps', 'type' => 'integer' ],
				[ 'key' => '9invalid', 'type' => 'string' ],
				[ 'key' => 'template_var_mood', 'type' => 'textarea', 'default' => str_repeat( 'x', 1500 ) ],
			],
			[ 'template_var_lighting' ],
			[ 'template_var_camera' ]
		);

		$this->assertSame( [ 'template_var_mood' ], array_column( $fields, 'key' ) );
		$this->assertSame( Template_Run_Controls::MAX_TEXT_LENGTH, strlen( $fields[0]['default'] ) );
	}

	public function test_described_variable_controls_validate_submitted_values(): void {
		$fields      = Template_Run_Controls::normalize_extension_fields( Template_Vars::control_fields( $this->workflow() ) );
		$description = [ 'version' => 1, 'fingerprint' => 'x', 'fields' => $fields ];

		$this->assertSame(
			[ 'template_var_lighting' => 'Neon night' ],
			Template_Run_Controls::validate_description( $description, [ 'template_var_lighting' => '  Neon night ' ] )
		);
		$this->assertInstanceOf( WP_Error::class, Template_Run_Controls::validate_description( $description, [ 'template_var_prompt' => 'x' ] ) );
		$this->assertInstanceOf( WP_Error::class, Template_Run_Controls::validate_description( $description, [ 'template_var_lighting' => str_repeat( 'x', 1001 ) ] ) );
	}

	public function test_apply_writes_literal_values_and_preserves_links(): void {
		$values   = Template_Vars::values_by_name(
			[
				'template_var_lighting' => 'Neon night',
				'template_var_camera'   => 'Handheld',
				'template_var_audio'    => 'Rain',
				'template_var_prompt'   => 'must not override the Story Graph',
				'steps'                 => 20,
			]
		);
		$workflow = Template_Vars::apply( $this->workflow(), $values );

		$this->assertSame( [ 'lighting' => 'Neon night', 'camera' => 'Handheld', 'audio' => 'Rain' ], $values );
		$this->assertSame( 'Neon night', $workflow['201']['inputs']['value'] );
		$this->assertSame( 'Neon night', $workflow['205']['inputs']['value'] );
		$this->assertSame( 'Handheld', $workflow['202']['inputs']['value'] );
		$this->assertSame( [ '9', 0 ], $workflow['203']['inputs']['value'] );
		$this->assertSame( 'A barista pours coffee', $workflow['200']['inputs']['value'] );
	}

	public function test_prompt_variable_receives_story_graph_placeholder(): void {
		$workflow = $this->workflow();
		$this->assertTrue( Template_Vars::has_prompt_variable( $workflow ) );

		$workflow = Template_Vars::apply_prompt_placeholder( $workflow );
		$this->assertSame( '{{prompt}}', $workflow['200']['inputs']['value'] );
		$this->assertSame( 'Warm morning light', $workflow['201']['inputs']['value'] );

		unset( $workflow['200'] );
		$this->assertFalse( Template_Vars::has_prompt_variable( $workflow ) );
		$this->assertSame( $workflow, Template_Vars::apply_prompt_placeholder( $workflow ) );
	}

	public function test_control_keys_round_trip_and_stay_bounded(): void {
		$this->assertSame( 'template_var__private', Template_Vars::control_key( '_private' ) );
		$this->assertSame( '_private', Template_Vars::name_for_control_key( 'template_var__private' ) );
		$this->assertNull( Template_Vars::control_key( str_repeat( 'a', 60 ) ) );
		$this->assertNull( Template_Vars::control_key( 'has space' ) );
		$this->assertNull( Template_Vars::name_for_control_key( 'steps' ) );
	}

	public function test_bindings_normalize_accepts_object_and_string_sources(): void {
		$this->assertSame(
			[ 'lighting' => 'time_of_day', 'subject' => 'post_title' ],
			Bindings::normalize(
				[
					'lighting' => [ 'source' => 'time_of_day' ],
					'subject'  => 'post_title',
					'prompt'   => 'post_content',
					'bad name' => 'post_title',
					'camera'   => [ 'source' => 'https://example.test/x' ],
					'audio'    => [ 'source' => 7 ],
				]
			)
		);
	}
}
