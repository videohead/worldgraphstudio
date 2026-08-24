<?php
/**
 * Selected-Connection AI LLM client tests.
 *
 * @package WorldGraph
 */

namespace WorldGraph\AI {
	defined( 'ABSPATH' ) || exit;

	if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
		/** Return controllable option values without booting WordPress. */
		function get_option( string $name, $default = false ) {
			foreach ( [ 'worldgraph_ai_test_options', 'worldgraph_ai_rate_options' ] as $global_key ) {
				if ( array_key_exists( $name, (array) ( $GLOBALS[ $global_key ] ?? [] ) ) ) {
					return $GLOBALS[ $global_key ][ $name ];
				}
			}
			return $default;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\get_current_user_id' ) ) {
		/** Return the current test user. */
		function get_current_user_id(): int {
			return (int) ( $GLOBALS['worldgraph_ai_rate_user_id'] ?? 42 );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\get_transient' ) ) {
		/** Read one in-memory transient. */
		function get_transient( string $key ) {
			return $GLOBALS['worldgraph_ai_rate_transients'][ $key ]['value'] ?? false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\set_transient' ) ) {
		/** Write one in-memory transient. */
		function set_transient( string $key, $value, int $expiration ): bool {
			$GLOBALS['worldgraph_ai_rate_transients'][ $key ] = [
				'value'      => $value,
				'expiration' => $expiration,
			];
			return true;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\absint' ) ) {
		/** Minimal WordPress positive-integer sanitizer. */
		function absint( $value ): int {
			return abs( (int) $value );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\sanitize_key' ) ) {
		/** Minimal WordPress key sanitizer. */
		function sanitize_key( $value ): string {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\sanitize_text_field' ) ) {
		/** Minimal single-line text sanitizer. */
		function sanitize_text_field( $value ): string {
			return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_json_encode' ) ) {
		/** Stand in for WordPress JSON encoding. */
		function wp_json_encode( $value, int $flags = 0 ) {
			return json_encode( $value, $flags );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_parse_url' ) ) {
		/** Stand in for WordPress URL parsing. */
		function wp_parse_url( string $url, int $component = -1 ) {
			return parse_url( $url, $component );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\is_wp_error' ) ) {
		/** The focused mocks return arrays, never live WP_Error objects. */
		function is_wp_error( $value ): bool {
			return $value instanceof \WP_Error;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\__' ) ) {
		/** Return untranslated test strings. */
		function __( string $text, string $domain = '' ): string {
			return $text;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_post' ) ) {
		/** Return a prebuilt provider response and capture the request. */
		function wp_remote_post( string $url, array $args ) {
			$GLOBALS['worldgraph_ai_post_calls'][] = [ 'url' => $url, 'args' => $args ];
			return $GLOBALS['worldgraph_ai_post_response'];
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_response_code' ) ) {
		/** Read a mocked WordPress HTTP status. */
		function wp_remote_retrieve_response_code( $response ): int {
			return (int) ( $response['response']['code'] ?? 0 );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_body' ) ) {
		/** Read a mocked WordPress HTTP response body. */
		function wp_remote_retrieve_body( $response ): string {
			return (string) ( $response['body'] ?? '' );
		}
	}
}

namespace {

use PHPUnit\Framework\TestCase;
use WorldGraph\AI\AI_LLM_Client;

require_once dirname( __DIR__ ) . '/includes/ai-editor/class-ai-llm-client.php';

/** Capture selected-Connection requests without resolving records or using HTTP. */
class Mock_Connection_AI_LLM_Client extends AI_LLM_Client {
	/** @var array<string,mixed> */
	public $record = [ 'status_wp' => 'publish' ];

	/** @var array<string,mixed> */
	public $connection = [
		'provider_type'       => 'openai_compatible',
		'status'              => 'verified',
		'endpoint_url'        => 'http://models.test:11434/v1',
		'credential_reference' => 'test-reference',
		'model'               => 'chat-moe',
		'max_tokens'          => 2048,
		'temperature'         => '0.1',
	];

	/** @var array<string,mixed> */
	public $captured_chat_options = [];

	/** Exercise the real provider path when request-body behavior matters. */
	public $delegate_chat = false;

	/** @var array<int,array{url:string,args:array}> */
	public $remote_calls = [];

	/** @var array<string,mixed> */
	public $remote_response = [ 'response' => [ 'code' => 503 ], 'body' => '' ];

	protected function connection_record( int $connection_id ): ?array {
		return $this->record;
	}

	protected function connection_configuration( int $connection_id ): ?array {
		return $this->connection;
	}

	protected function connection_credential( string $reference ) {
		return 'server-only-test-credential';
	}

	protected function remote_get( string $url, array $args ) {
		$this->remote_calls[] = [ 'url' => $url, 'args' => $args ];
		return $this->remote_response;
	}

	public function chat( string $prompt, array $options = [] ): array {
		$this->captured_chat_options = $options;
		if ( $this->delegate_chat ) {
			return parent::chat( $prompt, $options );
		}
		return [
			'content'       => '{}',
			'backend'       => 'openai_compatible',
			'tokens'        => 20,
			'finish_reason' => 'stop',
		];
	}
}

/** Selected-Connection requests must be bounded and provider-transparent. */
class Test_AI_LLM_Client extends TestCase {

	protected function setUp(): void {
		$GLOBALS['worldgraph_ai_test_options']    = [ 'worldgraph_ai_rate_limit' => 1000 ];
		$GLOBALS['worldgraph_ai_rate_options']    = [];
		$GLOBALS['worldgraph_ai_rate_transients'] = [];
		$GLOBALS['worldgraph_ai_rate_user_id']    = 42;
		$GLOBALS['worldgraph_ai_post_calls']      = [];
		$GLOBALS['worldgraph_ai_post_response']   = [
			'response' => [ 'code' => 200 ],
			'body'     => '{}',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['worldgraph_ai_test_options'] = [];
	}

	/** Invoke a private provider method against the mocked WordPress HTTP API. */
	private function invoke_provider( AI_LLM_Client $client, string $method, array $arguments ): array {
		$reflection = new \ReflectionMethod( AI_LLM_Client::class, $method );
		$reflection->setAccessible( true );
		return (array) $reflection->invokeArgs( $client, $arguments );
	}

	/** Decode the most recent mocked provider request body. */
	private function last_request_body(): array {
		$calls = (array) $GLOBALS['worldgraph_ai_post_calls'];
		$call  = end( $calls );
		return is_array( $call ) ? (array) json_decode( (string) ( $call['args']['body'] ?? '' ), true ) : [];
	}

	/** Disabling fallback keeps the concrete selected-backend error. */
	public function test_chat_preserves_primary_error_when_fallback_is_disabled(): void {
		$result = ( new AI_LLM_Client() )->chat(
			'hello',
			[
				'backend'       => 'unsupported_backend',
				'allow_fallback' => false,
				'cache'          => false,
			]
		);

		$this->assertSame( 'unknown_backend', $result['error'] );
		$this->assertSame( 'Unknown backend: unsupported_backend', $result['content'] );
	}

	/** A lower caller budget wins, while the Connection remains the upper cap. */
	public function test_connection_max_tokens_honors_both_request_and_configuration_ceilings(): void {
		$client = new Mock_Connection_AI_LLM_Client();
		$client->chat_with_connection( 17, 'story', [ 'max_tokens' => 900 ] );
		$this->assertSame( 900, $client->captured_chat_options['max_tokens'] );

		$client->chat_with_connection( 17, 'story', [ 'max_tokens' => 9000 ] );
		$this->assertSame( 2048, $client->captured_chat_options['max_tokens'] );

		$client->chat_with_connection( 17, 'story' );
		$this->assertSame( 2048, $client->captured_chat_options['max_tokens'] );

		$client->connection['max_tokens'] = 0;
		$client->chat_with_connection( 17, 'story', [ 'max_tokens' => 50000 ] );
		$this->assertSame( 32768, $client->captured_chat_options['max_tokens'] );

		$client->connection['max_tokens'] = 128;
		$client->chat_with_connection( 17, 'story', [ 'max_tokens' => 900 ] );
		$this->assertSame( 128, $client->captured_chat_options['max_tokens'] );
		$this->assertFalse( $client->captured_chat_options['allow_fallback'] );
		$this->assertFalse( $client->captured_chat_options['cache'] );
	}

	/** A request may reduce, but not exceed, its selected Connection temperature. */
	public function test_connection_temperature_honors_request_beneath_configuration_ceiling(): void {
		$client = new Mock_Connection_AI_LLM_Client();
		$client->connection['temperature'] = '0.7';

		$client->chat_with_connection( 17, 'story', [ 'temperature' => 0.1 ] );
		$this->assertSame( 0.1, $client->captured_chat_options['temperature'] );

		$client->chat_with_connection( 17, 'story', [ 'temperature' => 1.2 ] );
		$this->assertSame( 0.7, $client->captured_chat_options['temperature'] );

		$client->chat_with_connection( 17, 'story' );
		$this->assertSame( 0.7, $client->captured_chat_options['temperature'] );

		$client->connection['temperature'] = '';
		$client->chat_with_connection( 17, 'story' );
		$this->assertSame( 0.1, $client->captured_chat_options['temperature'] );

		$client->connection['provider_type'] = 'anthropic';
		$client->connection['temperature']   = '2';
		$client->chat_with_connection( 17, 'story', [ 'temperature' => 1.5 ] );
		$this->assertSame( 1.0, $client->captured_chat_options['temperature'] );
	}

	/** OpenAI reasoning models use their supported token field and defaults. */
	public function test_selected_openai_reasoning_model_uses_supported_wire_controls(): void {
		$GLOBALS['worldgraph_ai_post_response']['body'] = json_encode( [
			'choices' => [ [
				'message'       => [ 'content' => '{}' ],
				'finish_reason' => 'stop',
			] ],
			'usage' => [ 'total_tokens' => 44 ],
		] );

		$client = new Mock_Connection_AI_LLM_Client();
		$client->delegate_chat = true;
		$client->connection['provider_type'] = 'openai';
		$client->connection['endpoint_url']  = 'https://api.test/v1';
		$client->connection['model']         = 'o3-mini';
		$result = $client->chat_with_connection(
			17,
			'story',
			[ 'max_tokens' => 900, 'temperature' => 0.1 ]
		);

		$this->assertIsArray( $result );
		$body = $this->last_request_body();
		$this->assertSame( 900, $body['max_completion_tokens'] );
		$this->assertArrayNotHasKey( 'max_tokens', $body );
		$this->assertArrayNotHasKey( 'temperature', $body );
	}

	/** New Anthropic models omit deprecated temperature; older models cap it. */
	public function test_selected_anthropic_temperature_is_model_aware(): void {
		$GLOBALS['worldgraph_ai_post_response']['body'] = json_encode( [
			'content'     => [ [ 'text' => '{}' ] ],
			'stop_reason' => 'end_turn',
			'usage'       => [ 'input_tokens' => 20, 'output_tokens' => 30 ],
		] );

		$client = new Mock_Connection_AI_LLM_Client();
		$client->delegate_chat = true;
		$client->connection['provider_type'] = 'anthropic';
		$client->connection['endpoint_url']  = 'https://api.anthropic.test/v1';
		$client->connection['temperature']   = '2';
		$client->connection['model']         = 'claude-opus-5';
		$result = $client->chat_with_connection( 17, 'story', [ 'max_tokens' => 900, 'temperature' => 0.1 ] );

		$this->assertIsArray( $result );
		$body = $this->last_request_body();
		$this->assertSame( 900, $body['max_tokens'] );
		$this->assertArrayNotHasKey( 'temperature', $body );

		$client->connection['model'] = 'claude-opus-4-6';
		$client->chat_with_connection( 17, 'story', [ 'temperature' => 1.5 ] );
		$body = $this->last_request_body();
		$this->assertSame( 1.0, $client->captured_chat_options['temperature'] );
		$this->assertSame( 1, $body['temperature'] );
	}

	/** Multi-pass selected Connections have a finite bucket separate from chat. */
	public function test_selected_connection_rate_bucket_supports_one_bounded_import(): void {
		$GLOBALS['worldgraph_ai_test_options']['worldgraph_ai_rate_limit'] = 10;
		$GLOBALS['worldgraph_ai_post_response']['body'] = json_encode( [
			'choices' => [ [
				'message'       => [ 'content' => '{}' ],
				'finish_reason' => 'stop',
			] ],
			'usage' => [ 'total_tokens' => 20 ],
		] );

		$client = new Mock_Connection_AI_LLM_Client();
		$client->delegate_chat = true;
		$result = null;
		for ( $request = 0; $request < 144; $request++ ) {
			$result = $client->chat_with_connection( 17, 'story part ' . $request );
		}

		$this->assertIsArray( $result );
		$this->assertCount( 144, $GLOBALS['worldgraph_ai_post_calls'] );
		$this->assertCount( 144, $GLOBALS['worldgraph_ai_rate_transients']['worldgraph_ai_rate_selected_connection_42']['value'] );

		$blocked = $client->chat_with_connection( 17, 'one request too many' );
		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertSame( 'worldgraph_llm_request_failed', $blocked->get_error_code() );

		$ordinary = ( new AI_LLM_Client() )->chat(
			'ordinary request',
			[ 'backend' => 'unsupported_backend', 'allow_fallback' => false, 'cache' => false ]
		);
		$this->assertSame( 'unknown_backend', $ordinary['error'] );
		$this->assertCount( 1, $GLOBALS['worldgraph_ai_rate_transients']['worldgraph_ai_rate_42']['value'] );
	}

	/** OpenAI-compatible success carries a sanitized finish reason. */
	public function test_openai_compatible_finish_reason_is_propagated(): void {
		$GLOBALS['worldgraph_ai_post_response']['body'] = json_encode( [
			'choices' => [ [
				'message'       => [ 'content' => '{}' ],
				'finish_reason' => 'length<script>',
			] ],
			'usage' => [ 'total_tokens' => 88 ],
		] );

		$result = $this->invoke_provider(
			new AI_LLM_Client(),
			'call_openai_compatible',
			[ 'story', 'model', 512, 0.1, '', [], [], '', 'openai_compatible', 'http://models.test/v1' ]
		);

		$this->assertSame( 'lengthscript', $result['finish_reason'] );
		$this->assertSame( 88, $result['tokens'] );
		$body = $this->last_request_body();
		$this->assertSame( 512, $body['max_tokens'] );
		$this->assertSame( 0.1, $body['temperature'] );
		$this->assertSame( 'none', $body['tool_choice'] );
	}

	/** Hosted OpenAI success carries its finish reason. */
	public function test_openai_finish_reason_is_propagated(): void {
		$GLOBALS['worldgraph_ai_post_response']['body'] = json_encode( [
			'choices' => [ [
				'message'       => [ 'content' => '{}' ],
				'finish_reason' => 'content_filter',
			] ],
			'usage' => [ 'total_tokens' => 55 ],
		] );

		$result = $this->invoke_provider(
			new AI_LLM_Client(),
			'call_openai',
			[ 'story', 'model', 512, 0.1, '', [], [], 'test-key', 'https://api.test/v1' ]
		);

		$this->assertSame( 'content_filter', $result['finish_reason'] );
		$body = $this->last_request_body();
		$this->assertSame( 512, $body['max_tokens'] );
		$this->assertSame( 0.1, $body['temperature'] );
		$this->assertArrayNotHasKey( 'max_completion_tokens', $body );
	}

	/** Anthropic success carries its sanitized stop reason. */
	public function test_anthropic_stop_reason_is_propagated(): void {
		$GLOBALS['worldgraph_ai_post_response']['body'] = json_encode( [
			'content'     => [ [ 'text' => '{}' ] ],
			'stop_reason' => 'max_tokens<script>',
			'usage'       => [ 'input_tokens' => 20, 'output_tokens' => 30 ],
		] );

		$result = $this->invoke_provider(
			new AI_LLM_Client(),
			'call_anthropic',
			[ 'story', 'model', 512, 0.1, '', [], [], 'test-key', 'https://api.test/v1' ]
		);

		$this->assertSame( 'max_tokensscript', $result['stop_reason'] );
		$this->assertSame( 50, $result['tokens'] );
		$this->assertSame( 0.1, $this->last_request_body()['temperature'] );
	}

	/** Explicit model-scoped metadata avoids all HTTP discovery. */
	public function test_context_window_prefers_explicit_model_metadata(): void {
		$client = new Mock_Connection_AI_LLM_Client();
		$client->connection['model_access'] = [
			'models' => [
				'chat-moe' => [ 'context_window' => 131072 ],
			],
		];

		$this->assertSame( 131072, $client->model_context_window( 17 ) );
		$this->assertSame( [], $client->remote_calls );
	}

	/** Discovery matches the configured model, stays bounded, and caches one integer. */
	public function test_context_window_discovers_exact_model_with_bounded_request_and_cache(): void {
		$client = new Mock_Connection_AI_LLM_Client();
		$client->remote_response = [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'data' => [
					[ 'id' => 'other-model', 'max_model_len' => 999999 ],
					[ 'id' => 'chat-moe', 'metadata' => [ 'max_model_len' => 65536 ] ],
				],
			] ),
		];

		$this->assertSame( 65536, $client->model_context_window( 17 ) );
		$this->assertCount( 1, $client->remote_calls );
		$call = $client->remote_calls[0];
		$this->assertSame( 'http://models.test:11434/v1/models', $call['url'] );
		$this->assertSame( 5, $call['args']['timeout'] );
		$this->assertSame( 0, $call['args']['redirection'] );
		$this->assertSame( 262144, $call['args']['limit_response_size'] );
		$this->assertSame( 'Bearer server-only-test-credential', $call['args']['headers']['Authorization'] );

		$context_cache = array_filter(
			$GLOBALS['worldgraph_ai_rate_transients'],
			static function ( string $key ): bool {
				return str_starts_with( $key, 'worldgraph_llm_context_17_' );
			},
			ARRAY_FILTER_USE_KEY
		);
		$this->assertCount( 1, $context_cache );
		$cached = reset( $context_cache );
		$this->assertSame( 65536, $cached['value'] );
		$this->assertSame( 300, $cached['expiration'] );

		$client->remote_response['body'] = json_encode( [ 'data' => [] ] );
		$this->assertSame( 65536, $client->model_context_window( 17 ) );
		$this->assertCount( 1, $client->remote_calls );
	}

	/** Discovery accepts every documented provider context-field spelling. */
	public function test_context_window_recognizes_context_window_field_variants(): void {
		foreach ( [ 'context_window', 'max_context_length' ] as $field ) {
			$GLOBALS['worldgraph_ai_rate_transients'] = [];
			$client = new Mock_Connection_AI_LLM_Client();
			$client->remote_response = [
				'response' => [ 'code' => 200 ],
				'body'     => json_encode( [
					'models' => [ [ 'name' => 'chat-moe', $field => 32768 ] ],
				] ),
			];
			$this->assertSame( 32768, $client->model_context_window( 17 ), $field );
		}
	}

	/** Wrong models, unsafe metadata, and provider failures return zero. */
	public function test_context_window_returns_zero_on_discovery_failure(): void {
		$client = new Mock_Connection_AI_LLM_Client();
		$client->connection['model_access'] = [ 'context_window' => 999999999 ];
		$client->remote_response = [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'data' => [ [ 'id' => 'different-model', 'context_window' => 65536 ] ],
			] ),
		];

		$this->assertSame( 0, $client->model_context_window( 17 ) );
		$this->assertCount( 1, $client->remote_calls );
	}
}
}
