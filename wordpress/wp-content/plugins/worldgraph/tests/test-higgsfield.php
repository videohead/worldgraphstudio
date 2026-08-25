<?php
/**
 * Higgsfield REST, MCP, catalog, and reusable Connection OAuth contracts.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Connections\Connection_OAuth;
use WorldGraph\Utils\Connection_Adapters;
use WorldGraph\Utils\Credential_Store;
use WorldGraph\Utils\Generation_Modality;
use WorldGraph\Utils\Higgsfield_API;
use WorldGraph\Utils\Higgsfield_Catalog;
use WorldGraph\Utils\Higgsfield_MCP;

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal WordPress error value for the WordPress-free unit bootstrap. */
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
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return (string) $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) {
		return false !== filter_var( $url, FILTER_VALIDATE_URL ) ? (string) $url : false;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ): string {
		return (string) json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ): string {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ): string {
		return 'worldgraph-higgsfield-test-' . (string) $scheme;
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/class-credential-store.php';
require_once dirname( __DIR__ ) . '/includes/connections/class-connection-oauth.php';
require_once dirname( __DIR__ ) . '/includes/utils/connection-adapters.php';
require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/higgsfield-api.php';
require_once dirname( __DIR__ ) . '/includes/utils/higgsfield-mcp.php';
require_once dirname( __DIR__ ) . '/includes/utils/higgsfield-catalog.php';

/** Focused, network-free Higgsfield and Connection OAuth tests. */
class Test_Higgsfield extends TestCase {

	/** Read a plugin source file as a contract fixture. */
	private function source( string $relative_path ): string {
		$path = dirname( __DIR__ ) . '/' . ltrim( $relative_path, '/' );
		$this->assertFileExists( $path, "Missing Higgsfield source: {$relative_path}" );
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source, "Unreadable Higgsfield source: {$relative_path}" );

		return (string) $source;
	}

	/** REST and MCP origins, lazy files, OAuth profile, and execution stay explicit. */
	public function test_manifest_exposes_hybrid_connection_without_guided_setup(): void {
		$adapter = Connection_Adapters::get( 'higgsfield' );

		$this->assertIsArray( $adapter );
		$this->assertSame( 'https://platform.higgsfield.ai', Higgsfield_API::ENDPOINT );
		$this->assertSame( 'https://mcp.higgsfield.ai/mcp', Higgsfield_MCP::ENDPOINT );
		$this->assertSame( Higgsfield_API::ENDPOINT, Connection_Adapters::endpoint( 'higgsfield' ) );
		$this->assertSame( Higgsfield_MCP::ENDPOINT, Connection_Adapters::mcp_endpoint( 'higgsfield' ) );
		$this->assertSame( '2026-07-28', Higgsfield_MCP::CURRENT_PROTOCOL_VERSION );
		$this->assertSame( [], Higgsfield_MCP::REQUIRED_TOOLS );
		$this->assertSame(
			[
				'includes/utils/higgsfield-api.php',
				'includes/utils/higgsfield-mcp.php',
				'includes/utils/higgsfield-catalog.php',
			],
			$adapter['files']
		);
		$this->assertArrayNotHasKey( 'setup_options', $adapter );
		$this->assertNull( Connection_Adapters::setup_choice( 'higgsfield' ) );
		$this->assertSame( 'WorldGraph\\Utils\\Higgsfield_API', $adapter['generation']['client'] );
		$this->assertSame( 'higgsfield_api', $adapter['generation']['adapter'] );
		$this->assertTrue( $adapter['generation']['poll'] );
		$this->assertTrue( $adapter['generation']['poll_with_template'] );
		$this->assertTrue( $adapter['generation']['media_inputs'] );
		$this->assertSame( [ Connection_OAuth::class, 'render_admin' ], $adapter['callbacks']['render_admin'] );

		$oauth = $adapter['oauth']['profiles']['mcp'];
		$this->assertSame( 'mcp_credential_reference', $oauth['credential_field'] );
		$this->assertSame( 'https://mcp.higgsfield.ai/oauth2/authorize', $oauth['authorization_endpoint'] );
		$this->assertSame( 'https://mcp.higgsfield.ai/oauth2/token', $oauth['token_endpoint'] );
		$this->assertSame( 'https://mcp.higgsfield.ai/oauth2/register', $oauth['registration_endpoint'] );
		$this->assertSame( [ 'openid', 'email', 'offline_access' ], $oauth['scopes'] );
	}

	/** The reviewed fixed catalog and API client agree on all executable references. */
	public function test_catalog_definitions_have_reviewed_references_modalities_and_defaults(): void {
		$definitions = Higgsfield_Catalog::definitions();

		$this->assertSame(
			[
				'api:higgsfield-ai/soul/standard',
				'api:higgsfield-ai/dop/standard',
				'api:kling-video/v2.1/pro/image-to-video',
			],
			array_column( $definitions, 'reference' )
		);
		$this->assertSame(
			[ Generation_Modality::TEXT_TO_IMAGE, Generation_Modality::TEXT_IMAGE_TO_VIDEO, Generation_Modality::TEXT_IMAGE_TO_VIDEO ],
			array_column( $definitions, 'modality' )
		);
		$this->assertSame( array_keys( Higgsfield_API::OPERATIONS ), array_column( $definitions, 'reference' ) );
		$this->assertSame( [ 'num_images' => 1, 'resolution' => '2K', 'aspect_ratio' => '4:3' ], $definitions[0]['input'] );
		$this->assertSame( [ 'enhance_prompt' => true ], $definitions[1]['input'] );
		$this->assertSame( [ 'duration' => 5, 'cfg_scale' => 0.5, 'negative_prompt' => '' ], $definitions[2]['input'] );
		$this->assertSame( [ 'prompt' ], $definitions[0]['schema']['required'] );
		$this->assertSame( [ 'prompt', 'image_url' ], $definitions[1]['schema']['required'] );
		$this->assertSame( [ 'prompt', 'image_url' ], $definitions[2]['schema']['required'] );
	}

	/** Every documented asynchronous state maps to the worker's finite vocabulary. */
	public function test_api_status_normalization(): void {
		foreach ( [ 'queued', 'in_progress', 'processing', '' ] as $status ) {
			$this->assertSame( 'submitted', Higgsfield_API::normalize_status( $status ) );
		}
		$this->assertSame( 'completed', Higgsfield_API::normalize_status( 'completed' ) );
		$this->assertSame( 'failed', Higgsfield_API::normalize_status( 'failed' ) );
		$this->assertSame( 'failed', Higgsfield_API::normalize_status( 'nsfw' ) );
		$this->assertSame( 'cancelled', Higgsfield_API::normalize_status( 'canceled' ) );
		$this->assertSame( 'cancelled', Higgsfield_API::normalize_status( 'cancelled' ) );
	}

	/** Completion retains every distinct supported image, video, and audio URL. */
	public function test_api_normalizes_all_completed_outputs(): void {
		$result = Higgsfield_API::normalize_result(
			[
				'request_id' => 'request-1234567890',
				'status'     => 'completed',
				'images'     => [
					'https://cdn.example.test/image-a.webp',
					[ 'url' => 'https://cdn.example.test/image-b.png' ],
					'https://cdn.example.test/image-a.webp',
					'http://cdn.example.test/not-https.png',
				],
				'video'      => [ 'url' => 'https://cdn.example.test/video.mp4' ],
				'audio'      => 'https://cdn.example.test/audio-a.wav',
				'audios'     => [
					[ 'url' => 'https://cdn.example.test/audio-b.wav' ],
					'https://cdn.example.test/audio-a.wav',
				],
			]
		);

		$this->assertSame( 'request-1234567890', $result['job_id'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'api', $result['transport'] );
		$this->assertSame(
			[ 'image', 'image', 'video', 'audio', 'audio' ],
			array_column( $result['output_media'], 'kind' )
		);
		$this->assertSame(
			[
				'https://cdn.example.test/image-a.webp',
				'https://cdn.example.test/image-b.png',
				'https://cdn.example.test/video.mp4',
				'https://cdn.example.test/audio-a.wav',
				'https://cdn.example.test/audio-b.wav',
			],
			array_column( $result['output_media'], 'url' )
		);
	}

	/** Optional Model Access JSON can only narrow the reviewed operation set. */
	public function test_api_operation_allowlist_fails_closed(): void {
		$soul = 'api:higgsfield-ai/soul/standard';
		$dop  = 'api:higgsfield-ai/dop/standard';

		$this->assertTrue( Higgsfield_API::operation_is_allowed( [ 'model_access' => '' ], $soul ) );
		$this->assertFalse( Higgsfield_API::operation_is_allowed( [ 'model_access' => '' ], 'api:unreviewed/model' ) );
		$this->assertTrue( Higgsfield_API::operation_is_allowed( [ 'model_access' => '["' . $soul . '"]' ], $soul ) );
		$this->assertFalse( Higgsfield_API::operation_is_allowed( [ 'model_access' => '["' . $soul . '"]' ], $dop ) );
		$this->assertFalse( Higgsfield_API::operation_is_allowed( [ 'model_access' => 'not-json' ], $soul ) );
	}

	/** JSON and fully framed SSE ignore notifications and correlate by request ID. */
	public function test_mcp_json_and_sse_decoding_correlates_response_id(): void {
		$json = wp_json_encode(
			[
				[ 'jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [ 'progress' => 0.5 ] ],
				[ 'jsonrpc' => '2.0', 'id' => 'other-request', 'result' => [ 'resultType' => 'complete' ] ],
				[ 'jsonrpc' => '2.0', 'id' => 'wanted-request', 'result' => [ 'resultType' => 'complete', 'tools' => [] ] ],
			]
		);
		$decoded = Higgsfield_MCP::decode_response_for_id( $json, 'wanted-request' );
		$this->assertSame( 'wanted-request', $decoded['id'] );
		$this->assertSame( [], $decoded['result']['tools'] );

		$sse = "event: message\n"
			. 'data: {"jsonrpc":"2.0","method":"notifications/progress"}' . "\n\n"
			. "event: message\n"
			. 'data: {"jsonrpc":"2.0",' . "\n"
			. 'data: "id":"wanted-request","result":{"resultType":"complete","tools":[{"name":"generate"}]}}' . "\n\n"
			. "data: [DONE]\n\n";
		$decoded = Higgsfield_MCP::decode_response_for_id( $sse, 'wanted-request' );
		$this->assertSame( 'wanted-request', $decoded['id'] );
		$this->assertSame( 'generate', $decoded['result']['tools'][0]['name'] );
		$this->assertNull( Higgsfield_MCP::decode_response_for_id( $sse, 'missing-request' ) );
	}

	/** x-mcp-header is accepted only along static properties chains and safe scalar types. */
	public function test_mcp_header_annotation_placement_and_types(): void {
		$validate = new ReflectionMethod( Higgsfield_MCP::class, 'header_annotations_are_valid' );
		$validate->setAccessible( true );

		$this->assertTrue(
			$validate->invoke(
				null,
				[
					'type'       => 'object',
					'properties' => [
						'options' => [
							'type'       => 'object',
							'properties' => [
								'seed' => [ 'type' => 'integer', 'x-mcp-header' => 'X-Higgsfield-Seed' ],
							],
						],
					],
				]
			)
		);
		$this->assertFalse(
			$validate->invoke(
				null,
				[
					'oneOf' => [
						[ 'properties' => [ 'seed' => [ 'type' => 'integer', 'x-mcp-header' => 'X-Seed' ] ] ],
					],
				]
			)
		);
		$this->assertFalse(
			$validate->invoke(
				null,
				[ 'properties' => [ 'scale' => [ 'type' => 'number', 'x-mcp-header' => 'X-Scale' ] ] ]
			)
		);
		$this->assertFalse(
			$validate->invoke(
				null,
				[
					'properties' => [
						'a' => [ 'type' => 'string', 'x-mcp-header' => 'X-Trace' ],
						'b' => [ 'type' => 'boolean', 'x-mcp-header' => 'x-trace' ],
					],
				]
			)
		);
	}

	/** The reusable broker implements the RFC 7636 S256 transform exactly. */
	public function test_oauth_pkce_matches_rfc7636_vector(): void {
		$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Connection_OAuth::pkce_challenge( $verifier ) );
	}

	/** OAuth lifecycle is global, manifest-driven, replay-safe, and refresh-serialized. */
	public function test_reusable_oauth_source_contracts(): void {
		$oauth     = $this->source( 'includes/connections/class-connection-oauth.php' );
		$bootstrap = $this->source( 'worldgraph.php' );

		$this->assertStringContainsString( "admin_post_worldgraph_connection_oauth_start", $oauth );
		$this->assertStringContainsString( "admin_post_worldgraph_connection_oauth_callback", $oauth );
		$this->assertStringContainsString( "admin_post_worldgraph_connection_oauth_disconnect", $oauth );
		$this->assertStringNotContainsString( 'admin_post_worldgraph_higgsfield_oauth', $oauth );
		$this->assertStringContainsString( 'Connections\\Connection_OAuth::init();', $bootstrap );
		$this->assertStringContainsString( "is_array( \$oauth['profiles']", $oauth );

		$delete_state = strpos( $oauth, 'delete_transient( $transient_key )' );
		$decrypt_state = strpos( $oauth, 'Credential_Store::decrypt( $pending )' );
		$this->assertNotFalse( $delete_state );
		$this->assertNotFalse( $decrypt_state );
		$this->assertLessThan( $decrypt_state, $delete_state );
		$this->assertStringContainsString( 'Credential_Store::encrypt( (string) wp_json_encode( $pending ) )', $oauth );
		$this->assertStringContainsString( "'code_challenge_method' => 'S256'", $oauth );
		$this->assertStringContainsString( "'grant_types'", $oauth );
		$this->assertStringContainsString( "[ 'authorization_code', 'refresh_token' ]", $oauth );
		$this->assertStringContainsString( "'token_endpoint_auth_method' => 'none'", $oauth );
		$this->assertStringContainsString( 'wp_safe_remote_request( $url, $args )', $oauth );

		$this->assertStringContainsString( "add_option( \$option_name, \$token, '', 'no' )", $oauth );
		$this->assertStringContainsString( '$wpdb->update(', $oauth );
		$this->assertStringContainsString( 'finally {', $oauth );
		$this->assertStringContainsString( 'self::release_refresh_lock( $lock )', $oauth );
		$this->assertStringContainsString( "str_starts_with( trim( \$reference ), 'env://' )", $oauth );
		$this->assertStringContainsString( 'worldgraph_oauth_external_rotation_required', $oauth );
		$this->assertStringContainsString( 'Credential_Store::store_connection_secret(', $oauth );
		$this->assertStringContainsString( 'Credential_Store::load_connection_secret(', $oauth );

		$external_guard = strpos( $oauth, 'if ( $external )' );
		$refresh_lock   = strpos( $oauth, 'self::acquire_refresh_lock(', $external_guard );
		$refresh_store  = strpos( $oauth, 'self::store_envelope(', $refresh_lock );
		$this->assertNotFalse( $external_guard );
		$this->assertNotFalse( $refresh_lock );
		$this->assertNotFalse( $refresh_store );
		$this->assertLessThan( $refresh_lock, $external_guard );
		$this->assertLessThan( $refresh_store, $refresh_lock );
	}

	/** Credential storage round-trips OAuth envelopes without text-field mutation. */
	public function test_credential_store_preserves_opaque_oauth_bytes(): void {
		if ( ! Credential_Store::is_available() ) {
			$this->markTestSkipped( 'PHP OpenSSL AES-256-GCM is unavailable.' );
		}

		$opaque = '{"kind":"worldgraph-oauth","access_token":"a+b/c==._~-","refresh_token":"r%2F+=="}';
		$stored = Credential_Store::encrypt( $opaque );

		$this->assertNotSame( $opaque, $stored );
		$this->assertStringNotContainsString( $opaque, $stored );
		$this->assertSame( $opaque, Credential_Store::decrypt( $stored ) );

		$source = $this->source( 'includes/utils/class-credential-store.php' );
		$start  = strpos( $source, 'public static function store_connection_secret' );
		$end    = strpos( $source, 'public static function load_connection_secret', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $source, $start, $end - $start );
		$this->assertStringContainsString( 'self::encrypt( $plaintext )', $method );
		$this->assertStringNotContainsString( 'sanitize_text_field', $method );
	}

	/** Portable adapter schema versions OAuth as a reusable manifest key. */
	public function test_connection_adapter_schema_declares_oauth_profile_contract(): void {
		$root   = dirname( WORLDGRAPH_PLUGIN_DIR, 4 );
		$path   = $root . '/about/schemas/worldgraph-connection-adapter.schema.json';
		$schema = json_decode( (string) file_get_contents( $path ), true );

		$this->assertSame( JSON_ERROR_NONE, json_last_error(), "Invalid adapter schema JSON: {$path}" );
		$this->assertSame( '1.1.0', $schema['x-worldgraph-schema-version'] ?? null );
		$this->assertSame( '#/$defs/oauth', $schema['$defs']['adapter']['properties']['oauth']['$ref'] ?? null );
		$this->assertSame( '#/$defs/oauthProfile', $schema['$defs']['oauth']['properties']['profiles']['additionalProperties']['$ref'] ?? null );
		$this->assertSame(
			[ 'credential_reference', 'mcp_credential_reference' ],
			$schema['$defs']['oauthProfile']['properties']['credential_field']['enum'] ?? []
		);
	}

	/** Media-capable adapters receive the exact claimed job ID for cron authorization. */
	public function test_generation_passes_job_id_into_attachment_authorization(): void {
		$batch = $this->source( 'includes/utils/generation-batch.php' );
		$api   = $this->source( 'includes/utils/higgsfield-api.php' );

		$media_support = strpos( $batch, 'Connection_Adapters::supports_media_inputs( (string) $provider_type )' );
		$job_parameter = strpos( $batch, "\$params['_worldgraph_job_id'] = \$job_id", $media_support );
		$dispatch       = strpos( $batch, '$client::run_template(', $job_parameter );
		$this->assertNotFalse( $media_support );
		$this->assertNotFalse( $job_parameter );
		$this->assertNotFalse( $dispatch );
		$this->assertLessThan( $job_parameter, $media_support );
		$this->assertLessThan( $dispatch, $job_parameter );

		$this->assertStringContainsString( "\$job_id = absint( \$parameters['_worldgraph_job_id'] ?? 0 )", $api );
		$this->assertStringContainsString( 'self::resolve_media_input( $value, $connection_id, $job_id )', $api );
		$this->assertStringContainsString( "apply_filters( \$filter, true, \$job_id, \$attachment_id )", $api );
	}
}
