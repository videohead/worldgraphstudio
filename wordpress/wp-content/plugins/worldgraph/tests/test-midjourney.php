<?php
/**
 * MidJourney REST API, MCP, catalog, and runtime contract tests.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Connections\Builtin_Adapter_Runtime;
use WorldGraph\Utils\Connection_Adapters;
use WorldGraph\Utils\Generation_Modality;
use WorldGraph\Utils\Midjourney_API;
use WorldGraph\Utils\Midjourney_Catalog;
use WorldGraph\Utils\Midjourney_MCP;

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

require_once dirname( __DIR__ ) . '/includes/utils/connection-adapters.php';
require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/midjourney-api.php';
require_once dirname( __DIR__ ) . '/includes/utils/midjourney-mcp.php';
require_once dirname( __DIR__ ) . '/includes/utils/midjourney-catalog.php';

/** Focused, network-free tests for the MidJourney hybrid Connection. */
class Test_Midjourney extends TestCase {

	/** Read a plugin source file as a contract fixture. */
	private function source( string $relative_path ): string {
		$path = dirname( __DIR__ ) . '/' . ltrim( $relative_path, '/' );
		$this->assertFileExists( $path, "Missing MidJourney source: {$relative_path}" );
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source, "Unreadable MidJourney source: {$relative_path}" );

		return (string) $source;
	}

	/** The manifest and trusted runtime resolver keep the two transports explicit. */
	public function test_manifest_and_runtime_wire_the_hybrid_connection(): void {
		$adapter = Connection_Adapters::get( 'midjourney' );

		$this->assertIsArray( $adapter );
		$this->assertSame( Midjourney_API::ENDPOINT, Connection_Adapters::endpoint( 'midjourney' ) );
		$this->assertSame( Midjourney_MCP::ENDPOINT, Connection_Adapters::mcp_endpoint( 'midjourney' ) );
		$this->assertSame(
			[
				'includes/utils/midjourney-api.php',
				'includes/utils/midjourney-mcp.php',
				'includes/utils/midjourney-catalog.php',
			],
			$adapter['files']
		);
		$this->assertArrayNotHasKey( 'setup_options', $adapter );
		$this->assertNull( Connection_Adapters::setup_choice( 'midjourney' ) );
		$this->assertSame( [ Midjourney_Catalog::class, 'provision' ], $adapter['templates']['provision'] );
		$this->assertSame( 'midjourney_catalog', $adapter['templates']['status_meta_prefix'] );
		$this->assertSame( [ Builtin_Adapter_Runtime::class, 'midjourney_client' ], $adapter['generation']['client_resolver'] );
		$this->assertSame( [ Builtin_Adapter_Runtime::class, 'midjourney_adapter' ], $adapter['generation']['adapter_resolver'] );
		$this->assertTrue( $adapter['generation']['poll'] );
		$this->assertTrue( $adapter['generation']['poll_with_template'] );
		$this->assertFalse( $adapter['generation']['media_inputs'] );
		$this->assertContains( 'midjourney_api_connection_unpublished', $adapter['generation']['permanent_error_codes'] );
		$this->assertContains( 'midjourney_mcp_connection_unpublished', $adapter['generation']['permanent_error_codes'] );
		$this->assertContains( 'midjourney_mcp_job_id_mismatch', $adapter['generation']['permanent_error_codes'] );
		$this->assertContains( 'midjourney_mcp_output_missing', $adapter['generation']['permanent_error_codes'] );

		$this->assertSame( 'midjourney_api', Builtin_Adapter_Runtime::midjourney_adapter( [], 'api:imagine' ) );
		$this->assertSame( 'midjourney_mcp', Builtin_Adapter_Runtime::midjourney_adapter( [], 'mcp:midjourney_imagine' ) );
		$this->assertSame( 'midjourney_api', Builtin_Adapter_Runtime::midjourney_adapter( [], 'mcp:midjourney_imagine', 'midjourney_api' ) );
		$this->assertSame( Midjourney_API::class, Builtin_Adapter_Runtime::midjourney_client( [], 'api:imagine' ) );
		$this->assertSame( Midjourney_MCP::class, Builtin_Adapter_Runtime::midjourney_client( [], 'mcp:midjourney_imagine' ) );
	}

	/** Reviewed catalog entries agree on modality while preserving provider mode spelling. */
	public function test_catalog_references_modalities_defaults_and_mode_spellings(): void {
		$definitions = Midjourney_Catalog::definitions();
		$api         = Midjourney_Catalog::definitions( 'api' )[0];
		$mcp         = Midjourney_Catalog::definitions( 'mcp' )[0];

		$this->assertSame( [ 'api:imagine', 'mcp:midjourney_imagine' ], array_column( $definitions, 'reference' ) );
		$this->assertSame( [ 'api', 'mcp' ], array_column( $definitions, 'transport' ) );
		$this->assertSame(
			[ Generation_Modality::TEXT_TO_IMAGE, Generation_Modality::TEXT_TO_IMAGE ],
			array_column( $definitions, 'modality' )
		);
		$this->assertSame( [ 'image', 'image' ], array_map( [ Generation_Modality::class, 'output_type' ], array_column( $definitions, 'modality' ) ) );
		$this->assertSame( array_keys( Midjourney_API::OPERATIONS ), [ $api['reference'] ] );
		$this->assertSame( 'mcp:' . $mcp['tool'], $mcp['reference'] );
		$this->assertContains( $mcp['tool'], Midjourney_MCP::REQUIRED_TOOLS );
		$this->assertSame( [ 'mode' => 'fast', 'timeout' => 600 ], $api['input'] );
		$this->assertSame(
			[
				'mode'         => 'fast',
				'translation'  => false,
				'split_images' => true,
				'timeout'      => Midjourney_MCP::DEFAULT_GENERATION_TIMEOUT,
			],
			$mcp['input']
		);
		$this->assertSame( [ 'fast', 'relaxed' ], $api['schema']['properties']['mode']['enum'] );
		$this->assertSame( [ 'fast', 'relax', 'turbo' ], $mcp['schema']['properties']['mode']['enum'] );
		$this->assertNotContains( 'relax', $api['schema']['properties']['mode']['enum'] );
		$this->assertNotContains( 'relaxed', $mcp['schema']['properties']['mode']['enum'] );
	}

	/** REST parameters are bounded, type checked, and copied through an allowlist. */
	public function test_api_parameter_normalization_and_allowlist(): void {
		$this->assertSame( [ 'mode' => 'fast', 'timeout' => 600 ], Midjourney_API::normalize_parameters( [] ) );
		$this->assertSame(
			[ 'mode' => 'relaxed', 'timeout' => 1200 ],
			Midjourney_API::normalize_parameters(
				[
					'mode'     => 'relaxed',
					'timeout'  => '1200',
					'hookUrl'  => 'https://attacker.example.test/callback',
					'arbitrary' => true,
				]
			)
		);

		foreach (
			[
				[ 'mode' => 'relax' ],
				[ 'timeout' => 299 ],
				[ 'timeout' => 1201 ],
				[ 'timeout' => 600.5 ],
			] as $invalid
		) {
			$result = Midjourney_API::normalize_parameters( $invalid );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'midjourney_api_parameter_invalid', $result->get_error_code() );
		}
	}

	/** REST task states and every distinct safe image normalize for the worker. */
	public function test_api_status_result_allowlist_and_job_id_normalization(): void {
		foreach ( [ 0, '0', null, 99 ] as $pending ) {
			$this->assertSame( 'submitted', Midjourney_API::normalize_status( $pending ) );
		}
		$this->assertSame( 'completed', Midjourney_API::normalize_status( 1 ) );
		$this->assertSame( 'failed', Midjourney_API::normalize_status( '2' ) );

		$result = Midjourney_API::normalize_result(
			[
				'taskId' => 'rest-task_123',
				'status' => 1,
				'image'  => 'https://cdn.example.test/grid.webp',
				'images' => [
					[ 'url' => 'https://cdn.example.test/upscale-a.png' ],
					'https://cdn.example.test/grid.webp',
					'http://cdn.example.test/not-secure.png',
					'https://user:pass@cdn.example.test/credentialed.png',
				],
			]
		);

		$this->assertSame( 'rest-task_123', $result['job_id'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'api', $result['transport'] );
		$this->assertSame(
			[ 'https://cdn.example.test/grid.webp', 'https://cdn.example.test/upscale-a.png' ],
			array_column( $result['output_media'], 'url' )
		);
		$this->assertTrue( Midjourney_API::operation_is_allowed( [ 'model_access' => '' ], 'api:imagine' ) );
		$this->assertTrue( Midjourney_API::operation_is_allowed( [ 'model_access' => '["api:imagine"]' ], 'api:imagine' ) );
		$this->assertFalse( Midjourney_API::operation_is_allowed( [ 'model_access' => '[]' ], 'api:imagine' ) );
		$this->assertFalse( Midjourney_API::operation_is_allowed( [ 'model_access' => 'not-json' ], 'api:imagine' ) );
		$this->assertFalse( Midjourney_API::operation_is_allowed( [], 'api:unreviewed' ) );
		$this->assertTrue( Midjourney_API::is_valid_job_id( 'task_ABC-123' ) );
		$this->assertFalse( Midjourney_API::is_valid_job_id( 'task/ABC' ) );
		$this->assertFalse( Midjourney_API::is_valid_job_id( '' ) );
	}

	/** MCP constants pin the reviewed legacy lifecycle and bounded tool surface. */
	public function test_mcp_required_tools_protocol_and_generation_bounds(): void {
		$this->assertSame( '2025-03-26', Midjourney_MCP::PROTOCOL_VERSION );
		$this->assertSame( [ 'midjourney_imagine', 'midjourney_get_task' ], Midjourney_MCP::REQUIRED_TOOLS );
		$this->assertGreaterThanOrEqual( Midjourney_MCP::MIN_GENERATION_TIMEOUT, Midjourney_MCP::DEFAULT_GENERATION_TIMEOUT );
		$this->assertLessThanOrEqual( Midjourney_MCP::MAX_GENERATION_TIMEOUT, Midjourney_MCP::DEFAULT_GENERATION_TIMEOUT );
		$this->assertGreaterThan( 0, Midjourney_MCP::MAX_PAGES );
		$this->assertGreaterThan( 0, Midjourney_MCP::MAX_EVENTS );
		$this->assertGreaterThan( 0, Midjourney_MCP::MAX_RESPONSE_BYTES );
	}

	/** MCP submission and polling normalize terminal flags and all safe images. */
	public function test_mcp_status_and_result_normalization(): void {
		foreach ( [ 'pending', 'queued', 'in-progress', '' ] as $pending ) {
			$this->assertSame( 'submitted', Midjourney_MCP::normalize_status( $pending ) );
		}
		$this->assertSame( 'completed', Midjourney_MCP::normalize_status( 'succeeded' ) );
		$this->assertSame( 'failed', Midjourney_MCP::normalize_status( 'error' ) );
		$this->assertSame( 'cancelled', Midjourney_MCP::normalize_status( 'canceled' ) );

		$submitted = Midjourney_MCP::normalize_result(
			[
				'task_id' => 'mcp-task_123',
				'state'   => 'pending',
				'success' => true,
			]
		);
		$this->assertSame( 'mcp-task_123', $submitted['job_id'] );
		$this->assertSame( 'submitted', $submitted['status'] );

		$completed = Midjourney_MCP::normalize_result(
			[
				'id'               => 'mcp-poll:123',
				'state'            => 'processing',
				'response'         => [
					'success'        => true,
					'image_url'      => 'https://cdn.example.test/grid.png',
					'sub_image_urls' => [
						'https://cdn.example.test/a.png',
						'https://cdn.example.test/grid.png',
						'http://cdn.example.test/not-secure.png',
						'https://cdn.example.test:8443/non-default-port.png',
					],
				],
				'mcp_task_polling' => [ 'is_complete' => true ],
			],
			true
		);
		$this->assertSame( 'completed', $completed['status'] );
		$this->assertSame( 'mcp', $completed['transport'] );
		$this->assertSame(
			[ 'https://cdn.example.test/grid.png', 'https://cdn.example.test/a.png' ],
			array_column( $completed['output_media'], 'url' )
		);

		$failed = Midjourney_MCP::normalize_result(
			[
				'task_id' => 'mcp-task_456',
				'state'   => 'failed',
				'response' => [ 'success' => true ],
				'error'   => 'Provider rejected the task.',
			],
			true
		);
		$this->assertSame( 'failed', $failed['status'] );
		$this->assertSame( 'Midjourney generation failed.', $failed['error'] );
	}

	/** JSON and fully framed SSE ignore notifications and correlate by request ID. */
	public function test_mcp_json_and_sse_decoding_correlates_request_id(): void {
		$json = wp_json_encode(
			[
				[ 'jsonrpc' => '2.0', 'method' => 'notifications/progress' ],
				[ 'jsonrpc' => '2.0', 'id' => 'other-request', 'result' => [ 'tools' => [] ] ],
				[ 'jsonrpc' => '2.0', 'id' => 'wanted-request', 'result' => [ 'tools' => [ [ 'name' => 'midjourney_imagine' ] ] ] ],
			]
		);
		$decoded = Midjourney_MCP::decode_response_for_id( $json, 'wanted-request' );
		$this->assertSame( 'wanted-request', $decoded['id'] );
		$this->assertSame( 'midjourney_imagine', $decoded['result']['tools'][0]['name'] );

		$sse = "event: message\n"
			. 'data: {"jsonrpc":"2.0","method":"notifications/progress"}' . "\n\n"
			. "event: message\n"
			. 'data: {"jsonrpc":"2.0",' . "\n"
			. 'data: "id":"wanted-request","result":{"task_id":"mcp-task_123"}}' . "\n\n"
			. "data: [DONE]\n\n";
		$decoded = Midjourney_MCP::decode_response_for_id( $sse, 'wanted-request' );
		$this->assertSame( 'mcp-task_123', $decoded['result']['task_id'] );
		$this->assertNull( Midjourney_MCP::decode_response_for_id( $sse, 'missing-request' ) );
	}

	/** Tool results support reviewed structured and JSON text forms and honor isError. */
	public function test_mcp_decodes_structured_and_text_tool_results(): void {
		$structured = Midjourney_MCP::decode_tool_result(
			[
				'structuredContent' => [
					'result' => '{"task_id":"structured-task","state":"pending"}',
				],
			]
		);
		$this->assertSame( 'structured-task', $structured['task_id'] );

		$text = Midjourney_MCP::decode_tool_result(
			[
				'content' => [
					[ 'type' => 'text', 'text' => '{"task_id":"text-task","state":"pending"}' ],
				],
			]
		);
		$this->assertSame( 'text-task', $text['task_id'] );

		$error = Midjourney_MCP::decode_tool_result(
			[
				'isError' => true,
				'content' => [ [ 'type' => 'text', 'text' => 'The tool rejected this request.' ] ],
			]
		);
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'midjourney_mcp_tool_error', $error->get_error_code() );
	}

	/** Fixed destinations and headers never reuse the other service's credential. */
	public function test_transport_endpoints_headers_and_credentials_are_separate(): void {
		$api_endpoint = new ReflectionMethod( Midjourney_API::class, 'validated_endpoint' );
		$api_endpoint->setAccessible( true );
		$mcp_endpoint = new ReflectionMethod( Midjourney_MCP::class, 'validated_endpoint' );
		$mcp_endpoint->setAccessible( true );
		$mcp_saved_endpoint = new ReflectionMethod( Midjourney_MCP::class, 'endpoint' );
		$mcp_saved_endpoint->setAccessible( true );
		$mcp_credential = new ReflectionMethod( Midjourney_MCP::class, 'credential_reference' );
		$mcp_credential->setAccessible( true );

		$this->assertSame( Midjourney_API::ENDPOINT, $api_endpoint->invoke( null, Midjourney_API::ENDPOINT . '/' ) );
		$this->assertInstanceOf( WP_Error::class, $api_endpoint->invoke( null, 'https://attacker.example.test' ) );
		$this->assertSame( Midjourney_MCP::ENDPOINT, $mcp_endpoint->invoke( null, Midjourney_MCP::ENDPOINT . '/' ) );
		$this->assertInstanceOf( WP_Error::class, $mcp_endpoint->invoke( null, 'https://midjourney.mcp.acedata.cloud/other' ) );

		$connection = [
			'endpoint_url'             => Midjourney_API::ENDPOINT,
			'mcp_endpoint_url'         => Midjourney_MCP::ENDPOINT,
			'credential_reference'     => 'rest-api-key-fixture',
			'mcp_credential_reference' => 'acedata-token-fixture',
		];
		$this->assertSame( Midjourney_MCP::ENDPOINT, $mcp_saved_endpoint->invoke( null, $connection ) );
		$this->assertSame( 'acedata-token-fixture', $mcp_credential->invoke( null, $connection ) );

		$api_headers = new ReflectionMethod( Midjourney_API::class, 'authorization_headers' );
		$api_headers->setAccessible( true );
		$api_headers = $api_headers->invoke( null, 'rest-api-key-fixture' );
		$this->assertSame( 'rest-api-key-fixture', $api_headers['API-KEY'] );
		$this->assertArrayNotHasKey( 'Authorization', $api_headers );

		$mcp_headers = new ReflectionMethod( Midjourney_MCP::class, 'headers' );
		$mcp_headers->setAccessible( true );
		$mcp_headers = $mcp_headers->invoke( null, 'acedata-token-fixture', 'session-fixture' );
		$this->assertSame( 'Bearer acedata-token-fixture', $mcp_headers['Authorization'] );
		$this->assertSame( 'session-fixture', $mcp_headers['Mcp-Session-Id'] );
		$this->assertArrayNotHasKey( 'API-KEY', $mcp_headers );
	}

	/** Private transport code preserves initialization, sessions, pagination, and call allowlists. */
	public function test_source_contracts_pin_rest_and_mcp_transport_lifecycles(): void {
		$api    = $this->source( 'includes/utils/midjourney-api.php' );
		$mcp    = $this->source( 'includes/utils/midjourney-mcp.php' );
		$health = $this->source( 'includes/connections/class-builtin-connection-tests.php' );

		$this->assertStringContainsString( "'/midjourney/v1/submit-jobs'", $api );
		$this->assertStringContainsString( "'/midjourney/v1/job-status'", $api );
		$this->assertStringContainsString( "'API-KEY'", $api );
		$this->assertStringContainsString( 'wp_safe_remote_request(', $api );
		$this->assertSame( [ 'mode', 'timeout' ], Midjourney_API::OPERATIONS[ Midjourney_API::TEMPLATE ]['parameters'] );

		$this->assertMatchesRegularExpression( '#self::request\(\s*\$endpoint,\s*\$token,\s*\'initialize\'#s', $mcp );
		$this->assertMatchesRegularExpression( '#\'method\'\s*=>\s*\'notifications/initialized\'#', $mcp );
		$this->assertStringContainsString( "'Mcp-Session-Id'", $mcp );
		$this->assertMatchesRegularExpression( '#\'method\'\s*=>\s*\'DELETE\'#', $mcp );
		$this->assertMatchesRegularExpression( '#self::request\(\s*\$endpoint,\s*\$token,\s*\'tools/list\'#s', $mcp );
		$this->assertMatchesRegularExpression( '#self::request\(.*?\'tools/call\'#s', $mcp );
		$this->assertStringContainsString( "\$result['nextCursor']", $mcp );
		$this->assertStringContainsString( 'wp_safe_remote_request(', $mcp );
		$this->assertStringContainsString( 'midjourney_mcp_job_id_mismatch', $mcp );
		$this->assertStringContainsString( 'midjourney_mcp_output_missing', $mcp );
		$this->assertStringContainsString( "if ( '' !== \$api_credential )", $health );
		$this->assertStringContainsString( "if ( '' !== \$mcp_credential )", $health );

		$call_tool = new ReflectionMethod( Midjourney_MCP::class, 'call_tool' );
		$call_tool->setAccessible( true );
		$denied = $call_tool->invoke(
			null,
			'unreviewed_destructive_tool',
			[],
			Midjourney_MCP::ENDPOINT,
			'acedata-token-fixture'
		);
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'midjourney_mcp_tool_not_allowed', $denied->get_error_code() );
	}
}
