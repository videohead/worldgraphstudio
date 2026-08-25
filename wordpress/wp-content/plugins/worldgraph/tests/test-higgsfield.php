<?php
/**
 * Higgsfield REST, MCP, catalog, and reusable Connection OAuth contracts.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

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
		$this->assertFalse( Higgsfield_API::operation_is_allowed( [ 'model_access' => '{"selected":"' . $soul . '"}' ], $soul ) );
		$this->assertFalse( Higgsfield_API::operation_is_allowed( [ 'model_access' => '["' . $soul . '","api:unknown/model"]' ], $soul ) );
		$this->assertFalse( Higgsfield_API::operation_is_allowed( [ 'model_access' => '["' . $soul . '",7]' ], $soul ) );
	}

	/** Paid REST operation parameters reject schema-invalid types instead of coercing them. */
	public function test_api_operation_parameters_are_strictly_typed(): void {
		$normalize = new ReflectionMethod( Higgsfield_API::class, 'normalize_operation_body' );
		$normalize->setAccessible( true );
		$soul  = 'api:higgsfield-ai/soul/standard';
		$dop   = 'api:higgsfield-ai/dop/standard';
		$kling = 'api:kling-video/v2.1/pro/image-to-video';

		$this->assertIsArray( $normalize->invoke( null, $soul, [ 'prompt' => 'A portrait', 'num_images' => 2, 'resolution' => '2K', 'aspect_ratio' => '4:3' ] ) );
		$this->assertIsArray( $normalize->invoke( null, $dop, [ 'prompt' => 'Move', 'seed' => 7, 'enhance_prompt' => true ] ) );
		$this->assertIsArray( $normalize->invoke( null, $kling, [ 'prompt' => 'Move', 'duration' => 5, 'cfg_scale' => 0.25 ] ) );

		foreach (
			[
				[ $soul, [ 'num_images' => 1.5 ] ],
				[ $soul, [ 'num_images' => '2' ] ],
				[ $dop, [ 'seed' => 7.9 ] ],
				[ $dop, [ 'enhance_prompt' => 'true' ] ],
				[ $kling, [ 'duration' => '5' ] ],
				[ $kling, [ 'cfg_scale' => 'garbage' ] ],
				[ $kling, [ 'cfg_scale' => 0.555 ] ],
				[ $kling, [ 'negative_prompt' => [ 'not', 'text' ] ] ],
			] as [ $reference, $body ]
		) {
			$this->assertInstanceOf( WP_Error::class, $normalize->invoke( null, $reference, $body ) );
		}
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

		$bare_cr = 'data: {"jsonrpc":"2.0","id":"wanted-request",' . "\r"
			. 'data: "result":{"resultType":"complete"}}' . "\r\r";
		$this->assertSame( 'wanted-request', Higgsfield_MCP::decode_response_for_id( $bare_cr, 'wanted-request' )['id'] );
		$this->assertNull( Higgsfield_MCP::decode_response_for_id( '{"jsonrpc":"1.0","id":"wanted-request","result":{}}', 'wanted-request' ) );
		$this->assertNull( Higgsfield_MCP::decode_response_for_id( '{"jsonrpc":"2.0","id":[],"result":{}}', 'wanted-request' ) );
	}

	/** Current-era 400s use the exact official modern-error fallback matrix. */
	public function test_mcp_modern_400_fallback_matrix(): void {
		$decode   = new ReflectionMethod( Higgsfield_MCP::class, 'modern_error_response' );
		$fallback = new ReflectionMethod( Higgsfield_MCP::class, 'should_fallback_to_legacy' );
		$decode->setAccessible( true );
		$fallback->setAccessible( true );

		foreach ( [ '', '{malformed', '{"jsonrpc":"2.0","error":{"code":-32002,"message":"Not initialized"}}', '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Unknown"}}' ] as $body ) {
			$this->assertTrue( $fallback->invoke( null, $decode->invoke( null, $body ) ) );
		}
		$this->assertTrue( $fallback->invoke( null, new WP_Error( 'worldgraph_higgsfield_mcp_response_invalid', 'Malformed modern response.' ) ) );
		foreach ( [ -32020, -32021, -32022 ] as $code ) {
			$body = wp_json_encode( [ 'jsonrpc' => '2.0', 'error' => [ 'code' => $code, 'message' => 'Initialize is mentioned but the code is authoritative.' ] ] );
			$this->assertFalse( $fallback->invoke( null, $decode->invoke( null, $body ) ) );
		}
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
		$this->assertFalse(
			$validate->invoke(
				null,
				[ 'properties' => [ 'trace' => [ 'type' => 'string', 'x-mcp-header' => [ 'X-Trace' ] ] ] ]
			)
		);
	}

	/** Readiness retains only bounded tools with an object input schema. */
	public function test_mcp_tool_catalog_rejects_invalid_schemas_without_truncation(): void {
		$merge = new ReflectionMethod( Higgsfield_MCP::class, 'merge_tools' );
		$merge->setAccessible( true );

		$catalog = $merge->invoke(
			null,
			[],
			[
				[ 'name' => 'valid', 'description' => 'Valid tool', 'inputSchema' => [ 'type' => 'object', 'properties' => [] ] ],
				[ 'name' => 'missing-schema' ],
				[ 'name' => 'wrong-root', 'inputSchema' => [ 'type' => 'array' ] ],
			]
		);
		$this->assertSame( [ 'valid' ], array_keys( $catalog ) );
		$this->assertInstanceOf( stdClass::class, $catalog['valid']['inputSchema']['properties'] );

		$nested = [ 'type' => 'object' ];
		for ( $depth = 0; $depth < Higgsfield_MCP::MAX_SCHEMA_DEPTH + 2; ++$depth ) {
			$nested = [ 'type' => 'object', 'properties' => [ 'nested' => $nested ] ];
		}
		$this->assertSame( [], $merge->invoke( null, [], [ [ 'name' => 'too-deep', 'inputSchema' => $nested ] ] ) );
		$this->assertInstanceOf( WP_Error::class, $merge->invoke( null, [], [ 'not-a-list' => [ 'name' => 'tool', 'inputSchema' => [ 'type' => 'object' ] ] ] ) );
	}

	/** The reusable broker implements the RFC 7636 S256 transform exactly. */
	public function test_oauth_pkce_matches_rfc7636_vector(): void {
		$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Connection_OAuth::pkce_challenge( $verifier ) );
	}

	/** Reusable profile normalization rejects field collisions and confidential parameters. */
	public function test_oauth_profile_contract_is_behaviorally_validated(): void {
		$normalize = new ReflectionMethod( Connection_OAuth::class, 'normalize_config' );
		$normalize->setAccessible( true );
		$profile = [
			'service_label'          => 'Acme MCP',
			'credential_field'       => 'mcp_credential_reference',
			'authorization_endpoint' => 'https://identity.example.test/oauth2/authorize',
			'token_endpoint'         => 'https://identity.example.test/oauth2/token',
			'registration_endpoint'  => 'https://identity.example.test/oauth2/register',
			'scopes'                 => [ 'openid', 'offline_access' ],
			'token_endpoint_auth_method' => 'none',
		];
		$manifest = [ 'label' => 'Acme', 'oauth' => [ 'profiles' => [ 'mcp' => $profile ] ] ];
		$this->assertIsArray( $normalize->invoke( null, 'acme', $manifest, 'mcp' ) );

		$filter_profile = $profile;
		unset( $filter_profile['registration_endpoint'] );
		$filter_profile['client_id_from_filter'] = true;
		$this->assertIsArray( $normalize->invoke( null, 'acme', [ 'label' => 'Acme', 'oauth' => [ 'profiles' => [ 'mcp' => $filter_profile ] ] ], 'mcp' ) );

		$duplicate = $manifest;
		$duplicate['oauth']['profiles']['api'] = $profile;
		$duplicate_error = $normalize->invoke( null, 'acme', $duplicate, 'mcp' );
		$this->assertInstanceOf( WP_Error::class, $duplicate_error );
		$this->assertSame( 'worldgraph_oauth_credential_field_conflict', $duplicate_error->get_error_code() );

		$confidential = $manifest;
		$confidential['oauth']['profiles']['mcp']['token_parameters'] = [ 'ClIeNt_SeCrEt' => 'must-not-be-sent' ];
		$confidential_error = $normalize->invoke( null, 'acme', $confidential, 'mcp' );
		$this->assertInstanceOf( WP_Error::class, $confidential_error );
		$this->assertSame( 'worldgraph_oauth_confidential_parameter_forbidden', $confidential_error->get_error_code() );
	}

	/** Token envelopes require an explicit Bearer response and preserve refresh rotation. */
	public function test_oauth_token_envelope_validation_and_expiry(): void {
		$normalize = new ReflectionMethod( Connection_OAuth::class, 'normalize_config' );
		$envelope  = new ReflectionMethod( Connection_OAuth::class, 'envelope_from_response' );
		$fresh     = new ReflectionMethod( Connection_OAuth::class, 'envelope_is_fresh' );
		$normalize->setAccessible( true );
		$envelope->setAccessible( true );
		$fresh->setAccessible( true );
		$manifest = [
			'label' => 'Acme',
			'oauth' => [
				'profiles' => [
					'mcp' => [
						'credential_field'       => 'mcp_credential_reference',
						'authorization_endpoint' => 'https://identity.example.test/oauth2/authorize',
						'token_endpoint'         => 'https://identity.example.test/oauth2/token',
						'client_id'              => 'public-client',
						'scopes'                 => [ 'openid', 'offline_access' ],
					],
				],
			],
		];
		$config = $normalize->invoke( null, 'acme', $manifest, 'mcp' );

		$expires_now = $envelope->invoke(
			null,
			[ 'access_token' => 'access-token', 'refresh_token' => '', 'token_type' => 'Bearer', 'expires_in' => 0 ],
			'acme',
			'mcp',
			'public-client',
			$config,
			'preserved-refresh'
		);
		$this->assertIsArray( $expires_now );
		$this->assertSame( 'preserved-refresh', $expires_now['refresh_token'] );
		$this->assertFalse( $fresh->invoke( null, $expires_now ) );

		foreach (
			[
				[ 'access_token' => 'access-token', 'expires_in' => 3600 ],
				[ 'access_token' => 123, 'token_type' => 'Bearer' ],
				[ 'access_token' => 'access-token', 'token_type' => 123 ],
				[ 'access_token' => 'access-token', 'token_type' => 'Bearer', 'expires_in' => 'invalid' ],
				[ 'access_token' => 'access-token', 'token_type' => 'Bearer', 'expires_in' => -1 ],
				[ 'access_token' => 'access-token', 'token_type' => 'Bearer', 'refresh_token' => [] ],
				[ 'access_token' => 'access-token', 'token_type' => 'Bearer', 'scope' => [] ],
				[ 'access_token' => 'access-token', 'token_type' => 'Bearer', 'scope' => 'openid invalid,scope' ],
				[ 'access_token' => 'access-token', 'token_type' => 'Bearer', 'scope' => implode( ' ', array_fill( 0, 31, 'scope' ) ) ],
			] as $tokens
		) {
			$this->assertInstanceOf( WP_Error::class, $envelope->invoke( null, $tokens, 'acme', 'mcp', 'public-client', $config ) );
		}
	}

	/** OAuth lifecycle is global, manifest-driven, replay-safe, and mutation-serialized. */
	public function test_reusable_oauth_source_contracts(): void {
		$oauth     = $this->source( 'includes/connections/class-connection-oauth.php' );
		$bootstrap = $this->source( 'worldgraph.php' );

		$this->assertStringContainsString( "admin_post_worldgraph_connection_oauth_start", $oauth );
		$this->assertStringContainsString( "admin_post_worldgraph_connection_oauth_callback", $oauth );
		$this->assertStringContainsString( "admin_post_worldgraph_connection_oauth_disconnect", $oauth );
		$this->assertStringNotContainsString( 'admin_post_worldgraph_higgsfield_oauth', $oauth );
		$this->assertStringContainsString( 'Connections\\Connection_OAuth::init();', $bootstrap );
		$this->assertStringContainsString( "is_array( \$oauth['profiles']", $oauth );

		$state_lock    = strpos( $oauth, 'self::acquire_state_lock( $state )' );
		$delete_state  = strpos( $oauth, 'delete_transient( $transient_key )' );
		$decrypt_state = strpos( $oauth, 'Credential_Store::decrypt( $pending )' );
		$this->assertNotFalse( $state_lock );
		$this->assertNotFalse( $delete_state );
		$this->assertNotFalse( $decrypt_state );
		$this->assertLessThan( $delete_state, $state_lock );
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
		$this->assertStringContainsString( 'self::release_lock( $lock )', $oauth );
		$this->assertStringContainsString( "str_starts_with( trim( \$reference ), 'env://' )", $oauth );
		$this->assertStringContainsString( 'worldgraph_oauth_external_rotation_required', $oauth );
		$this->assertStringContainsString( 'Credential_Store::store_connection_secret(', $oauth );
		$this->assertStringContainsString( 'Credential_Store::store_connection_secret_if_revision(', $oauth );
		$this->assertStringContainsString( 'Credential_Store::load_connection_secret(', $oauth );
		$profile_controls = substr(
			$oauth,
			strpos( $oauth, 'private static function render_profile_controls' ),
			strpos( $oauth, 'public static function render_queued_admin_forms' ) - strpos( $oauth, 'private static function render_profile_controls' )
		);
		$this->assertStringNotContainsString( '<form', $profile_controls );
		$this->assertStringContainsString( 'form="<?php echo esc_attr( $form_id ); ?>"', $profile_controls );

		$resolve_client = substr(
			$oauth,
			strpos( $oauth, 'private static function resolve_client_id' ),
			strpos( $oauth, 'private static function register_client' ) - strpos( $oauth, 'private static function resolve_client_id' )
		);
		$this->assertLessThan( strpos( $resolve_client, 'get_post_meta( $connection_id, $meta_key' ), strpos( $resolve_client, "(string) \$config['client_id']" ) );

		$external_guard = strpos( $oauth, 'if ( $external )' );
		$refresh_lock   = strpos( $oauth, 'self::acquire_credential_lock(', $external_guard );
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
		$this->assertTrue( $schema['$defs']['oauthProfile']['properties']['client_id_from_filter']['const'] ?? false );
		$this->assertSame(
			'^[a-z][a-z0-9_-]{0,63}$',
			$schema['$defs']['oauth']['properties']['profiles']['propertyNames']['pattern'] ?? null
		);
		$this->assertArrayHasKey( 'not', $schema['$defs']['oauthStaticParameters']['propertyNames']['allOf'][1] ?? [] );
	}

	/** A saved credential must be cleared before its Connection can change providers. */
	public function test_provider_change_and_oauth_refresh_have_credential_integrity_guards(): void {
		$connection = $this->source( 'includes/cpts/connection.php' );
		$store      = $this->source( 'includes/utils/class-credential-store.php' );

		$this->assertStringContainsString( 'provider_change_has_credentials', $connection );
		$this->assertStringContainsString( 'Clear both saved credential fields before changing', $connection );
		$this->assertStringContainsString( 'public static function connection_secret_revision', $store );
		$this->assertStringContainsString( 'public static function store_connection_secret_if_revision', $store );
		$this->assertStringContainsString( 'BINARY meta_value = BINARY %s', $store );
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
