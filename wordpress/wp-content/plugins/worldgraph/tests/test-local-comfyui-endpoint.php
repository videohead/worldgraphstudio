<?php
/**
 * Connection-scoped local ComfyUI endpoint tests.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = '';
		public string $post_title = '';
		public string $post_name = '';
		public string $post_status = 'publish';
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['worldgraph_local_comfy_posts'][ (int) $post_id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id ) {
		$post = get_post( $post_id );
		return $post instanceof WP_Post ? $post->post_type : false;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$value = $GLOBALS['worldgraph_local_comfy_meta'][ (int) $post_id ][ (string) $key ] ?? null;
		return $single ? ( null === $value ? '' : $value ) : ( null === $value ? [] : [ $value ] );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['worldgraph_local_comfy_options'][ (string) $name ] ?? $default;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ): string {
		return rtrim( (string) $value, '/\\' );
	}
}

/** Keep each local ComfyUI job on the endpoint selected by its Connection. */
class Test_Local_ComfyUI_Endpoint extends TestCase {

	/**
	 * A requested Connection endpoint wins while invalid IDs retain the legacy
	 * global-option fallback.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_connection_endpoint_precedes_global_fallback(): void {
		require_once dirname( __DIR__ ) . '/includes/utils/connection_repository.php';
		require_once dirname( __DIR__ ) . '/includes/utils/local-comfyui.php';

		$post             = new WP_Post();
		$post->ID         = 321;
		$post->post_type  = 'worldgraph_conn';
		$post->post_title = 'Secondary ComfyUI';
		$post->post_name  = 'secondary-comfyui';
		$GLOBALS['worldgraph_local_comfy_posts'][321] = $post;
		$GLOBALS['worldgraph_local_comfy_meta'][321]  = array_fill_keys( \WorldGraph\Utils\Connection_Repository::PUBLIC_FIELDS, '' );
		$GLOBALS['worldgraph_local_comfy_meta'][321]['endpoint_url'] = 'http://secondary.example:8288/';
		$GLOBALS['worldgraph_local_comfy_options']['worldgraph_comfy_local_url'] = 'http://global.example:8188/';

		$this->assertSame( 'http://secondary.example:8288', \WorldGraph\Utils\Local_ComfyUI::endpoint( 321 ) );
		$this->assertSame( 'http://global.example:8188', \WorldGraph\Utils\Local_ComfyUI::endpoint( 999 ) );
	}

	/** Submission, conversion, upload, polling, and output URLs share one endpoint. */
	public function test_runner_threads_the_resolved_endpoint_through_every_network_surface(): void {
		$source    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/local-comfyui.php' );
		$generator = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );
		$run       = self::method_source( $source, 'public static function run_template' );
		$status    = self::method_source( $source, 'public static function get_job_status' );
		$graph     = self::method_source( $source, 'private static function workflow' );
		$check     = self::method_source( $source, 'private static function preflight' );
		$upload    = self::method_source( $source, 'private static function upload_input' );

		$this->assertStringContainsString( 'Local_ComfyUI::is_configured( $connection_id )', $generator );
		$this->assertStringContainsString( '$endpoint = self::endpoint( $connection_id );', $run );
		$this->assertStringContainsString( 'self::workflow( $template_id, $modality, $parameters, $endpoint )', $run );
		$this->assertStringContainsString( 'self::preflight( $template_id, $connection_id, $endpoint )', $run );
		$this->assertStringContainsString( 'self::resolve_inputs( $modality, $inputs, $connection_id, $job_id, $endpoint )', $run );
		$this->assertStringContainsString( "self::url( 'prompt', \$endpoint )", $run );

		$this->assertStringContainsString( '$endpoint = self::endpoint( $connection_id );', $status );
		$this->assertStringContainsString( "self::url( 'history/' . rawurlencode( \$job_id ), \$endpoint )", $status );
		$this->assertStringContainsString( 'self::view_url( $image, $endpoint )', $status );

		$this->assertStringContainsString( "Comfy_Manifest::object_info( '' !== \$endpoint ? \$endpoint : self::endpoint() )", $graph );
		$this->assertStringContainsString( 'Comfy_Manifest::validate( $template_id, $endpoint )', $check );
		$this->assertStringContainsString( "self::url( 'upload/image', \$endpoint )", $upload );
	}

	/** Readiness, cache flushing, and post-download validation use one endpoint. */
	public function test_manifest_readiness_keeps_the_connection_endpoint(): void {
		$source   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/comfy-manifest.php' );
		$ready    = self::method_source( $source, 'public static function ensure_ready' );
		$download = self::method_source( $source, 'public static function request_downloads' );

		$this->assertStringContainsString( 'Local_ComfyUI::endpoint( $connection_id )', $ready );
		$this->assertSame( 2, substr_count( $ready, 'self::validate( $template_id, $endpoint )' ) );
		$this->assertStringContainsString( 'self::request_downloads( $template_id, $endpoint )', $ready );
		$this->assertStringContainsString( 'self::flush_catalog( $endpoint )', $ready );
		$this->assertStringContainsString( 'self::validate( $template_id, $endpoint )', $download );
	}

	/** Extract one class method without coupling assertions to unrelated code. */
	private static function method_source( string $source, string $signature ): string {
		$start = strpos( $source, $signature );
		if ( false === $start ) {
			return '';
		}
		$end = strpos( $source, "\n\t/**", $start );

		return false === $end ? substr( $source, $start ) : substr( $source, $start, $end - $start );
	}
}
