<?php
/**
 * Tests for generation request and queued-media authorization.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;
use WorldGraph\REST\Generation_Authorization;
use WorldGraph\REST\Generation_Controller;

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	/** Minimal REST base for loading the generation controller. */
	class WP_REST_Controller {
		public function prepare_response_for_collection( $response ) {
			if ( ! $response instanceof WP_REST_Response ) {
				return $response;
			}

			$data  = $response->get_data();
			$links = $response->get_links();
			if ( ! empty( $links ) ) {
				$data['_links'] = $links;
			}
			return $data;
		}
	}
}

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

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public string $post_type;
		public int $post_parent;

		public function __construct( int $id, string $post_type, int $post_parent = 0 ) {
			$this->ID          = $id;
			$this->post_type   = $post_type;
			$this->post_parent = $post_parent;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ): string {
		return (string) $text;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) {
		return false !== filter_var( $url, FILTER_VALIDATE_URL ) ? (string) $url : false;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability, ...$args ): bool {
		$capabilities = $GLOBALS['worldgraph_generation_auth_caps'][ (int) $user_id ] ?? [];
		$object_key   = $capability . ( isset( $args[0] ) ? ':' . (int) $args[0] : '' );
		return (bool) ( $capabilities[ $object_key ] ?? $capabilities[ $capability ] ?? false );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['worldgraph_generation_auth_posts'][ (int) $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id ) {
		$post = get_post( (int) $post_id );
		return $post instanceof WP_Post ? $post->post_type : false;
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post_id ): int {
		return absint( $GLOBALS['worldgraph_generation_auth_thumbnails'][ (int) $post_id ] ?? 0 );
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$value = $GLOBALS['worldgraph_generation_auth_meta'][ (int) $post_id ][ (string) $key ] ?? null;
		if ( $single ) {
			return null === $value ? '' : $value;
		}
		return null === $value ? [] : [ $value ];
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/template_bindings.php';
require_once dirname( __DIR__ ) . '/includes/utils/template-run-controls.php';
require_once dirname( __DIR__ ) . '/includes/rest-api/generation-authorization.php';
require_once dirname( __DIR__ ) . '/includes/rest-api/base-controller.php';
require_once dirname( __DIR__ ) . '/includes/rest-api/generation-controller.php';

/** Generation authorization unit and wiring tests. */
class Test_Generation_Authorization extends TestCase {

	protected function setUp(): void {
		$GLOBALS['worldgraph_generation_auth_caps']  = [];
		$GLOBALS['worldgraph_generation_auth_posts'] = [];
		$GLOBALS['worldgraph_generation_auth_meta']  = [];
		$GLOBALS['worldgraph_generation_auth_thumbnails'] = [];
		$GLOBALS['worldgraph_import_journal_state'] = [
			'meta'        => [],
			'post_types'  => [],
			'thumbnails'  => [],
		];
	}

	/** Media output requires upload permission without changing text-only jobs. */
	public function test_media_generation_requires_upload_files(): void {
		$this->grant( 7, 'edit_posts' );

		$this->assertTrue( Generation_Authorization::authorize_submission( 'text', 0, [], 7 ) );

		$result = Generation_Authorization::authorize_submission( 'video', 0, [], 7 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_upload_forbidden', $result->get_error_code() );

		$this->grant( 7, 'upload_files' );
		$this->assertTrue( Generation_Authorization::authorize_submission( 'video', 0, [], 7 ) );
	}

	/** A selected source is authorized with its object-level edit capability. */
	public function test_selected_source_requires_edit_post(): void {
		$this->grant( 7, 'edit_posts' );
		$result = Generation_Authorization::authorize_submission( 'text', 42, [], 7 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_source_forbidden', $result->get_error_code() );

		$this->grant( 7, 'edit_post', 42 );
		$this->assertTrue( Generation_Authorization::authorize_submission( 'text', 42, [], 7 ) );
	}

	/** Every numeric media value must be an attachment the requester can access. */
	public function test_numeric_media_inputs_require_accessible_attachments(): void {
		$this->grant( 7, 'edit_posts' );
		$this->grant( 7, 'upload_files' );
		$this->add_post( 17, 'attachment' );
		$this->add_post( 18, 'worldgraph_asset' );
		$this->grant( 7, 'read_post', 17 );

		$this->assertTrue( Generation_Authorization::authorize_submission( 'image', 0, [ 'image' => '17' ], 7 ) );

		$result = Generation_Authorization::authorize_submission( 'image', 0, [ 'image' => '18' ], 7 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_attachment_invalid', $result->get_error_code() );

		$this->add_post( 18, 'attachment' );
		$result = Generation_Authorization::authorize_submission( 'image', 0, [ 'image' => '17', 'audio' => '18' ], 7 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_attachment_forbidden', $result->get_error_code() );
	}

	/** URLs remain provider inputs, while malformed numeric IDs fail closed. */
	public function test_url_inputs_remain_compatible_and_numeric_ids_are_not_coerced(): void {
		$this->grant( 7, 'edit_posts' );
		$this->grant( 7, 'upload_files' );

		$this->assertTrue(
			Generation_Authorization::authorize_submission( 'image', 0, [ 'image' => 'https://cdn.example/reference.png' ], 7 )
		);

		$result = Generation_Authorization::authorize_submission( 'image', 0, [ 'image' => '17.5' ], 7 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_attachment_invalid', $result->get_error_code() );

		$result = Generation_Authorization::authorize_submission( 'image', 0, [ 'image' => '/etc/passwd' ], 7 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_media_input_invalid', $result->get_error_code() );

		$result = Generation_Authorization::authorize_submission( 'image', 0, [ 'image' => 'http://cdn.example/reference.png' ], 7 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_media_input_invalid', $result->get_error_code() );
	}

	/** Cron revalidates the recorded requester and only permits bound attachments. */
	public function test_background_media_revalidates_requester_and_binding(): void {
		$this->add_post( 90, 'worldgraph_gen', 42 );
		$this->add_post( 17, 'attachment' );
		$this->set_meta( 90, Generation_Authorization::REQUESTER_META, 7 );
		$this->set_meta( 90, '_worldgraph_gen_type', 'video' );
		$this->set_meta( 90, '_worldgraph_gen_inputs', [ 'image' => '17' ] );
		$this->grant( 7, 'edit_posts' );
		$this->grant( 7, 'upload_files' );
		$this->grant( 7, 'edit_post', 42 );
		$this->grant( 7, 'read_post', 17 );

		$this->assertTrue( Generation_Authorization::authorize_background_media( 90, 17 ) );

		$result = Generation_Authorization::authorize_background_media( 90, 19 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_attachment_not_bound', $result->get_error_code() );

		unset( $GLOBALS['worldgraph_generation_auth_caps'][7]['read_post:17'] );
		$result = Generation_Authorization::authorize_background_media( 90, 17 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_attachment_forbidden', $result->get_error_code() );

		$this->set_meta( 90, Generation_Authorization::REQUESTER_META, 0 );
		$result = Generation_Authorization::authorize_background_media( 90, 17 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_requester_missing', $result->get_error_code() );
	}

	/** The generic REST route uses the stricter policy and records its requester. */
	public function test_generation_controller_wires_authorization_before_storage(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/generation-controller.php' );
		$this->assertStringContainsString( "'permission_callback' => [ \$this, 'check_generation_create_permission' ]", $source );
		$this->assertStringContainsString( "'permission_callback' => [ \$this, 'check_generation_manage_permission' ]", $source );
		$this->assertStringContainsString( "'permission_callback' => [ \$this, 'check_generation_read_permission' ]", $source );
		$this->assertStringContainsString( "'permission_callback' => [ \$this, 'check_asset_history_permission' ]", $source );
		$this->assertStringContainsString( 'Generation_Authorization::authorize_submission(', $source );
		$this->assertStringContainsString( 'Generation_Authorization::REQUESTER_META, $requester_id', $source );
		$this->assertStringContainsString( "update_post_meta( \$generation_id, '_worldgraph_gen_status', 'cancelled', \$current_status )", $source );
		$this->assertLessThan( strpos( $source, 'wp_insert_post(' ), strpos( $source, '$authorization = Generation_Authorization::authorize_submission' ) );
	}

	/** Generic generation keeps legacy params while validating the v1 map. */
	public function test_generic_generation_separates_legacy_params_and_run_values(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/generation-controller.php' );
		$local  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/local-comfyui.php' );

		$this->assertStringContainsString( "'run_values' => [", $source );
		$this->assertStringContainsString( 'Template_Run_Controls::validate( (int) $template->ID, $run_values )', $source );
		$this->assertStringContainsString( '$params = array_merge( $params, $run_values );', $source );
		$this->assertStringContainsString( "update_post_meta( \$post_id, '_worldgraph_gen_params', \$params );", $source );
		$this->assertStringContainsString( "update_post_meta( \$post_id, '_worldgraph_gen_run_values', \$run_values );", $source );
		$this->assertStringContainsString( "[ 'seed' => \$params[ \$legacy_seed_key ] ]", $source );
		$this->assertStringContainsString( "update_post_meta( \$post_id, '_worldgraph_gen_explicit_seed', \$legacy_seed );", $source );
		$this->assertStringContainsString( "const EXPLICIT_SEED_META = '_worldgraph_gen_explicit_seed';", $local );
		$this->assertStringContainsString( "\$parameters['seed'] = (int) get_post_meta( \$job_id, self::EXPLICIT_SEED_META, true );", $local );
		$this->assertStringNotContainsString( 'Template_Run_Controls::validate( (int) $template->ID, $params )', $source );
	}

	/** Local HTTP ComfyUI executes its Template record without an MCP ID. */
	public function test_generic_generation_allows_local_comfy_template_without_provider_id(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/generation-controller.php' );

		$this->assertStringContainsString( "'comfyui' === \$connection['provider_type'] && 'local' === ( \$connection['environment'] ?? '' )", $source );
		$this->assertStringContainsString( "'' === \$provider_template_id && ! \$use_local_comfyui", $source );
		$this->assertStringContainsString( "\$workflow = '' !== \$provider_template_id ? \$provider_template_id : (string) \$template->ID;", $source );
		$this->assertStringContainsString( "update_post_meta( \$post_id, '_worldgraph_gen_adapter', 'local_comfyui' );", $source );
	}

	/** Template bindings fill required i2v media, while explicit input wins. */
	public function test_generic_generation_resolves_and_requires_i2v_media(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/generation-controller.php' );
		$binding_position = strpos( $source, '$inputs   = self::resolve_template_media_inputs' );
		$authorization_position = strpos( $source, '$authorization = Generation_Authorization::authorize_submission', $binding_position );
		$required_position = strpos( $source, '$missing_inputs = self::missing_required_media_inputs', $authorization_position );
		$this->assertNotFalse( $binding_position );
		$this->assertNotFalse( $authorization_position );
		$this->assertNotFalse( $required_position );
		$this->assertLessThan( $authorization_position, $binding_position );
		$this->assertLessThan( $required_position, $authorization_position );
		$this->assertStringContainsString( "'worldgraph_generation_required_input_missing'", $source );

		$this->add_post( 42, 'worldgraph_asset' );
		$this->add_post( 100, 'worldgraph_template' );
		$this->set_meta( 100, 'modality', 'text_image_to_video' );
		$this->set_meta( 100, 'input_bindings', json_encode( [
			'image' => [ 'source' => 'reference_image' ],
		] ) );
		$this->set_meta( 42, 'reference_image', '17' );
		$this->assertSame( 'text_image_to_video', \WorldGraph\Utils\worldgraph_get_field_value( 100, 'modality' ) );
		$this->assertSame( '17', \WorldGraph\Utils\worldgraph_get_field_value( 42, 'reference_image' ) );

		$resolve = new ReflectionMethod( Generation_Controller::class, 'resolve_template_media_inputs' );
		$resolve->setAccessible( true );
		$missing = new ReflectionMethod( Generation_Controller::class, 'missing_required_media_inputs' );
		$missing->setAccessible( true );

		$bound = $resolve->invoke( null, 100, 42, [] );
		$this->assertSame( [ 'image' => '17' ], $bound );
		$this->assertSame( [], $missing->invoke( null, 'text_image_to_video', $bound ) );

		$explicit = $resolve->invoke( null, 100, 42, [ 'image' => '23' ] );
		$this->assertSame( [ 'image' => '23' ], $explicit );
		$this->assertSame( [ 'image' ], $missing->invoke( null, 'text_image_to_video', [] ) );
	}

	private function grant( int $user_id, string $capability, int $object_id = 0 ): void {
		$key = $capability . ( $object_id ? ':' . $object_id : '' );
		$GLOBALS['worldgraph_generation_auth_caps'][ $user_id ][ $key ] = true;
	}

	private function add_post( int $post_id, string $post_type, int $post_parent = 0 ): void {
		$post = new WP_Post( $post_id, $post_type, $post_parent );
		$GLOBALS['worldgraph_generation_auth_posts'][ $post_id ] = $post;
		$GLOBALS['worldgraph_rest_api_post_objects'][ $post_id ]  = $post;
		$GLOBALS['worldgraph_rest_api_posts'][ $post_id ]         = $post_type;
		$GLOBALS['worldgraph_import_journal_state']['post_types'][ $post_id ] = $post_type;
	}

	private function set_meta( int $post_id, string $key, $value ): void {
		$GLOBALS['worldgraph_generation_auth_meta'][ $post_id ][ $key ] = $value;
		$GLOBALS['worldgraph_rest_api_post_meta'][ $post_id ][ $key ]    = $value;
		$GLOBALS['worldgraph_import_journal_state']['meta'][ $post_id ][ $key ] = $value;
	}
}
