<?php
/**
 * AI LLM Client — handles connections to local and cloud LLM backends.
 *
 * @package WorldGraph
 */

namespace WorldGraph\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LLM Client class.
 */
class AI_LLM_Client {

	/**
	 * Cache for LLM responses.
	 *
	 * @var array
	 */
	private $response_cache = [];

	/**
	 * Send a chat request to the configured LLM backend.
	 *
	 * @param string $prompt The user prompt.
	 * @param array  $options Optional parameters (model, max_tokens, temperature, system_prompt, context, messages).
	 * @return array {
	 *     @type string $content The LLM response content.
	 *     @type string $backend Which backend was used.
	 *     @type int    $tokens Approximate token count.
	 * }
	 */
	public function chat( string $prompt, array $options = [] ): array {
		$backend   = $options['backend'] ?? get_option( 'worldgraph_ai_backend', 'local' );
		$model     = $options['model'] ?? get_option( 'worldgraph_ai_model', 'qwen3.6:35b-a3b-q4_K_M' );
		$max_tokens = $options['max_tokens'] ?? get_option( 'worldgraph_ai_max_tokens', 4096 );
		$temperature = $options['temperature'] ?? get_option( 'worldgraph_ai_temperature', 0.7 );
		$system_prompt = $options['system_prompt'] ?? '';
		$context   = $options['context'] ?? [];
		$history   = $this->normalize_messages( $options['messages'] ?? [] );
		$endpoint  = trim( (string) ( $options['endpoint_url'] ?? '' ) );
		$api_key   = (string) ( $options['api_key'] ?? '' );
		$use_cache = ! array_key_exists( 'cache', $options ) || ! empty( $options['cache'] );
		$allow_fallback = ! array_key_exists( 'allow_fallback', $options ) || ! empty( $options['allow_fallback'] );

		// Check rate limit.
		if ( ! $this->check_rate_limit() ) {
			return [
				'content'  => 'Rate limit exceeded. Please try again later.',
				'backend'  => $backend,
				'tokens'   => 0,
				'error'    => 'rate_limit_exceeded',
			];
		}

		// Check cache (both in-memory and transient).
		$cache_key = md5( $backend . '|' . $endpoint . '|' . (string) ( $options['connection_id'] ?? 0 ) . '|' . $prompt . $model . $max_tokens . $temperature . $system_prompt . wp_json_encode( $context ) . wp_json_encode( $history ) );
		$cache_ttl = get_option( 'worldgraph_ai_cache_ttl', 3600 );

		// First check in-memory cache.
		if ( $use_cache && isset( $this->response_cache[ $cache_key ] ) ) {
			$cached = $this->response_cache[ $cache_key ];
			if ( time() - $cached['timestamp'] < $cache_ttl ) {
				return [
					'content' => $cached['content'],
					'backend' => $cached['backend'],
					'tokens'  => $cached['tokens'],
				];
			}
			unset( $this->response_cache[ $cache_key ] );
		}

		// Then check WordPress transient cache (persists across requests).
		$transient_key = 'worldgraph_ai_' . $cache_key;
		$cached_content = $use_cache ? get_transient( $transient_key ) : false;
		if ( false !== $cached_content && is_array( $cached_content ) ) {
			// Validate transient data.
			if ( isset( $cached_content['content'], $cached_content['backend'], $cached_content['tokens'] ) ) {
				// Update in-memory cache with transient data.
				$this->response_cache[ $cache_key ] = [
					'content'   => $cached_content['content'],
					'backend'   => $cached_content['backend'],
					'tokens'    => $cached_content['tokens'],
					'timestamp' => time(),
				];
				return [
					'content' => $cached_content['content'],
					'backend' => $cached_content['backend'],
					'tokens'  => $cached_content['tokens'],
				];
			}
		}

		// Try primary backend.
		$result = $this->call_backend( $backend, $prompt, $model, $max_tokens, $temperature, $system_prompt, $context, $history, $api_key, $endpoint );

		if ( $result && empty( $result['error'] ) ) {
			if ( $use_cache ) {
				// Cache the result in both in-memory and transient storage.
				$cache_data = [
					'content'   => $result['content'],
					'backend'   => $result['backend'],
					'tokens'    => $result['tokens'] ?? 0,
				];
				$this->response_cache[ $cache_key ] = [
					'content'   => $result['content'],
					'backend'   => $result['backend'],
					'tokens'    => $result['tokens'] ?? 0,
					'timestamp' => time(),
				];
				// Store in WordPress transient for persistence across requests.
				set_transient( $transient_key, $cache_data, $cache_ttl );
			}
			return $result;
		}

		// Try fallback if available.
		$fallback_enabled = get_option( 'worldgraph_ai_fallback_enabled', true );
		if ( $allow_fallback && $fallback_enabled && 'dual' !== $backend ) {
			$fallback_backend = get_option( 'worldgraph_ai_fallback_backend', 'openai' );
			$result = $this->call_backend( $fallback_backend, $prompt, $model, $max_tokens, $temperature, $system_prompt, $context, $history, $this->fallback_api_key() );
			if ( $result && empty( $result['error'] ) ) {
				return $result;
			}
		}

		// Dual mode: try both.
		if ( $allow_fallback && 'dual' === $backend ) {
			$fallback_backend = get_option( 'worldgraph_ai_fallback_backend', 'openai' );
			$result = $this->call_backend( $fallback_backend, $prompt, $model, $max_tokens, $temperature, $system_prompt, $context, $history, $this->fallback_api_key() );
			if ( $result && empty( $result['error'] ) ) {
				return $result;
			}
		}

		// All backends failed.
		return [
			'content' => 'Unable to reach any LLM backend. Please check your configuration.',
			'backend' => $backend,
			'tokens'  => 0,
			'error'   => 'all_backends_failed',
		];
	}

	/**
	 * Send one chat request through an explicitly selected LLM Connection.
	 *
	 * Unlike the legacy option-backed chat path, this method never falls back to
	 * another provider and does not cache manuscript or structured-output data.
	 * Callers own the user/object capability check for the selected Connection.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $prompt        User prompt.
	 * @param array  $options       Server-owned chat options.
	 * @return array|\WP_Error Response or stable Connection/transport error.
	 */
	public function chat_with_connection( int $connection_id, string $prompt, array $options = [] ) {
		$record = \WorldGraph\Utils\Connection_Repository::get( $connection_id );
		if ( ! is_array( $record ) || 'publish' !== (string) ( $record['status_wp'] ?? '' ) ) {
			return new \WP_Error( 'worldgraph_llm_connection_missing', __( 'The selected published LLM Connection was not found.', 'worldgraph' ) );
		}

		$connection = \WorldGraph\Utils\Connection_Repository::resolve( $connection_id );
		if ( ! is_array( $connection ) ) {
			return new \WP_Error( 'worldgraph_llm_connection_missing', __( 'The selected LLM Connection was not found.', 'worldgraph' ) );
		}

		$backend = sanitize_key( (string) ( $connection['provider_type'] ?? '' ) );
		if ( ! in_array( $backend, [ 'openai_compatible', 'openai', 'anthropic' ], true ) ) {
			return new \WP_Error( 'worldgraph_llm_connection_provider_invalid', __( 'Select an OpenAI-compatible, OpenAI, or Anthropic LLM Connection.', 'worldgraph' ) );
		}
		if ( 'disabled' === (string) ( $connection['status'] ?? '' ) ) {
			return new \WP_Error( 'worldgraph_llm_connection_disabled', __( 'The selected LLM Connection is disabled.', 'worldgraph' ) );
		}

		$endpoint = trim( (string) ( $connection['endpoint_url'] ?? '' ) );
		if ( '' === $endpoint ) {
			return new \WP_Error( 'worldgraph_llm_connection_endpoint_missing', __( 'The selected LLM Connection has no endpoint URL.', 'worldgraph' ) );
		}
		$scheme = strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return new \WP_Error( 'worldgraph_llm_connection_endpoint_invalid', __( 'The selected LLM Connection must use an HTTP or HTTPS endpoint.', 'worldgraph' ) );
		}

		$credential = \WorldGraph\Utils\Credential_Store::resolve_reference( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		if ( in_array( $backend, [ 'openai', 'anthropic' ], true ) && '' === $credential ) {
			return new \WP_Error( 'worldgraph_llm_connection_credential_missing', __( 'The selected hosted LLM Connection has no credential.', 'worldgraph' ) );
		}

		$model = trim( (string) ( $connection['model'] ?? '' ) );
		if ( '' === $model ) {
			return new \WP_Error( 'worldgraph_llm_connection_model_missing', __( 'The selected LLM Connection has no model configured.', 'worldgraph' ) );
		}

		$configured_max = absint( $connection['max_tokens'] ?? 0 );
		$options['backend']        = $backend;
		$options['model']          = $model;
		$options['max_tokens']     = max( 256, min( 32768, $configured_max ?: absint( $options['max_tokens'] ?? 8192 ) ) );
		$options['temperature']    = max( 0.0, min( 2.0, (float) ( '' !== (string) ( $connection['temperature'] ?? '' ) ? $connection['temperature'] : ( $options['temperature'] ?? 0.1 ) ) ) );
		$options['endpoint_url']   = $endpoint;
		$options['api_key']        = $credential;
		$options['connection_id']  = $connection_id;
		$options['allow_fallback'] = false;
		$options['cache']          = false;

		$result = $this->chat( $prompt, $options );
		if ( ! empty( $result['error'] ) ) {
			return new \WP_Error(
				'worldgraph_llm_request_failed',
				sanitize_text_field( (string) ( $result['content'] ?? __( 'The selected LLM Connection could not complete the request.', 'worldgraph' ) ) )
			);
		}

		$result['connection_id'] = $connection_id;
		$result['model']         = $model;
		return $result;
	}

	/**
	 * Call a specific LLM backend.
	 *
	 * @param string $backend Backend type (openai_compatible, openai, anthropic).
	 * @param string $prompt The prompt.
	 * @param string $model Model name.
	 * @param int    $max_tokens Max tokens.
	 * @param float  $temperature Temperature.
	 * @param string $system_prompt System prompt.
	 * @param array  $context Additional context.
	 * @param array  $history Prior user and assistant messages.
	 * @return array|false Response array or false on failure.
	 */
	private function call_backend( string $backend, string $prompt, string $model, int $max_tokens, float $temperature, string $system_prompt, array $context, array $history, string $api_key = '', string $endpoint = '' ) {
		switch ( $backend ) {
			case 'local':
			case 'openai_compatible':
				return $this->call_openai_compatible( $prompt, $model, $max_tokens, $temperature, $system_prompt, $context, $history, $api_key, $backend, $endpoint );
			case 'openai':
				return $this->call_openai( $prompt, $model, $max_tokens, $temperature, $system_prompt, $context, $history, $api_key, $endpoint );
			case 'anthropic':
				return $this->call_anthropic( $prompt, $model, $max_tokens, $temperature, $system_prompt, $context, $history, $api_key, $endpoint );
			default:
				return [
					'content' => "Unknown backend: {$backend}",
					'error'   => 'unknown_backend',
				];
		}
	}

	/**
	 * Call an OpenAI-compatible endpoint, including local LLMs and BYOK services.
	 *
	 * @param string $prompt The prompt.
	 * @param string $model Model name.
	 * @param int    $max_tokens Max tokens.
	 * @param float  $temperature Temperature.
	 * @param string $system_prompt System prompt.
	 * @param array  $context Additional context.
	 * @param array  $history Prior user and assistant messages.
	 * @return array Response array.
	 */
	private function call_openai_compatible( string $prompt, string $model, int $max_tokens, float $temperature, string $system_prompt, array $context, array $history, string $api_key = '', string $backend = 'openai_compatible', string $endpoint = '' ): array {
		$url = rtrim( '' !== $endpoint ? $endpoint : get_option( 'worldgraph_ai_url', 'http://localhost:11434/v1' ), '/' );
		if ( ! str_ends_with( $url, '/chat/completions' ) ) {
			$url .= str_ends_with( $url, '/v1' ) ? '/chat/completions' : '/v1/chat/completions';
		}
		$api_key = '' !== $api_key ? $api_key : $this->primary_api_key();

		$messages = [];
		if ( ! empty( $system_prompt ) ) {
			$messages[] = [ 'role' => 'system', 'content' => $system_prompt ];
		}

		// Add context if provided.
		if ( ! empty( $context ) ) {
			$context_text = $this->format_context_for_llm( $context );
			if ( ! empty( $context_text ) ) {
				$messages[] = [ 'role' => 'system', 'content' => $context_text ];
			}
		}

		$messages = array_merge( $messages, $history );
		$messages[] = [ 'role' => 'user', 'content' => $prompt ];

		$args = [
			'method'  => 'POST',
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . ( '' !== $api_key ? $api_key : 'local-dev-key' ),
			],
			'body'    => wp_json_encode( [
				'model'       => $model,
				'messages'    => $messages,
				'max_tokens'  => $max_tokens,
				'temperature' => $temperature,
				'tool_choice' => 'none',
			] ),
			'timeout'             => 120,
			'limit_response_size' => 2_097_152,
		];

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [
				'content' => "LLM connection error: " . $response->get_error_message(),
				'backend' => $backend,
				'error'   => 'connection_error',
			];
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return [
				'content' => sprintf( 'The OpenAI-compatible endpoint returned HTTP %d.', $status ),
				'backend' => $backend,
				'error'   => 'http_error',
			];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return [
				'content' => 'Invalid response from OpenAI-compatible LLM.',
				'backend' => $backend,
				'error'   => 'invalid_response',
			];
		}

		return [
			'content' => $data['choices'][0]['message']['content'],
			'backend' => $backend,
			'tokens'  => $data['usage']['total_tokens'] ?? 0,
		];
	}

	/**
	 * Call OpenAI API.
	 *
	 * @param string $prompt The prompt.
	 * @param string $model Model name.
	 * @param int    $max_tokens Max tokens.
	 * @param float  $temperature Temperature.
	 * @param string $system_prompt System prompt.
	 * @param array  $context Additional context.
	 * @param array  $history Prior user and assistant messages.
	 * @return array Response array.
	 */
	private function call_openai( string $prompt, string $model, int $max_tokens, float $temperature, string $system_prompt, array $context, array $history, string $api_key = '', string $endpoint = '' ): array {
		$api_key = '' !== $api_key ? $api_key : $this->primary_api_key();
		if ( empty( $api_key ) ) {
			return [
				'content' => 'No OpenAI API key configured.',
				'backend' => 'openai',
				'error'   => 'no_api_key',
			];
		}

		$url = rtrim( '' !== $endpoint ? $endpoint : 'https://api.openai.com/v1', '/' );
		if ( ! str_ends_with( $url, '/chat/completions' ) ) {
			$url .= str_ends_with( $url, '/v1' ) ? '/chat/completions' : '/v1/chat/completions';
		}

		$messages = [];
		if ( ! empty( $system_prompt ) ) {
			$messages[] = [ 'role' => 'system', 'content' => $system_prompt ];
		}

		if ( ! empty( $context ) ) {
			$context_text = $this->format_context_for_llm( $context );
			if ( ! empty( $context_text ) ) {
				$messages[] = [ 'role' => 'system', 'content' => $context_text ];
			}
		}

		$messages = array_merge( $messages, $history );
		$messages[] = [ 'role' => 'user', 'content' => $prompt ];

		$args = [
			'method'  => 'POST',
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body'    => wp_json_encode( [
				'model'       => $model,
				'messages'    => $messages,
				'max_tokens'  => $max_tokens,
				'temperature' => $temperature,
			] ),
			'timeout'             => 120,
			'limit_response_size' => 2_097_152,
		];

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [
				'content' => "OpenAI API error: " . $response->get_error_message(),
				'backend' => 'openai',
				'error'   => 'api_error',
			];
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return [
				'content' => sprintf( 'The OpenAI endpoint returned HTTP %d.', $status ),
				'backend' => 'openai',
				'error'   => 'http_error',
			];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return [
				'content' => 'Invalid response from OpenAI.',
				'backend' => 'openai',
				'error'   => 'invalid_response',
			];
		}

		return [
			'content' => $data['choices'][0]['message']['content'],
			'backend' => 'openai',
			'tokens'  => $data['usage']['total_tokens'] ?? 0,
		];
	}

	/**
	 * Call Anthropic API.
	 *
	 * @param string $prompt The prompt.
	 * @param string $model Model name.
	 * @param int    $max_tokens Max tokens.
	 * @param float  $temperature Temperature.
	 * @param string $system_prompt System prompt.
	 * @param array  $context Additional context.
	 * @param array  $history Prior user and assistant messages.
	 * @return array Response array.
	 */
	private function call_anthropic( string $prompt, string $model, int $max_tokens, float $temperature, string $system_prompt, array $context, array $history, string $api_key = '', string $endpoint = '' ): array {
		$api_key = '' !== $api_key ? $api_key : $this->primary_api_key();
		if ( empty( $api_key ) ) {
			return [
				'content' => 'No Anthropic API key configured.',
				'backend' => 'anthropic',
				'error'   => 'no_api_key',
			];
		}

		$url = rtrim( '' !== $endpoint ? $endpoint : 'https://api.anthropic.com', '/' );
		$url .= str_ends_with( $url, '/v1/messages' ) ? '' : ( str_ends_with( $url, '/v1' ) ? '/messages' : '/v1/messages' );

		if ( ! empty( $context ) ) {
			$context_text = $this->format_context_for_llm( $context );
			if ( ! empty( $context_text ) ) {
				$system_prompt .= "\n\n" . $context_text;
			}
		}

		$messages = $history;
		$messages[] = [ 'role' => 'user', 'content' => $prompt ];

		$args = [
			'method'  => 'POST',
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			],
			'body'    => wp_json_encode( [
				'model'         => $model,
				'messages'      => $messages,
				'max_tokens'    => $max_tokens,
				'temperature'   => $temperature,
				'system'        => $system_prompt,
			] ),
			'timeout'             => 120,
			'limit_response_size' => 2_097_152,
		];

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [
				'content' => "Anthropic API error: " . $response->get_error_message(),
				'backend' => 'anthropic',
				'error'   => 'api_error',
			];
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return [
				'content' => sprintf( 'The Anthropic endpoint returned HTTP %d.', $status ),
				'backend' => 'anthropic',
				'error'   => 'http_error',
			];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['content'][0]['text'] ) ) {
			return [
				'content' => 'Invalid response from Anthropic.',
				'backend' => 'anthropic',
				'error'   => 'invalid_response',
			];
		}

		return [
			'content' => $data['content'][0]['text'],
			'backend' => 'anthropic',
			'tokens'  => absint( $data['usage']['input_tokens'] ?? 0 ) + absint( $data['usage']['output_tokens'] ?? 0 ),
		];
	}

	/**
	 * Normalize prior messages before building a provider request.
	 *
	 * REST callers are validated by the controller, while internal callers may
	 * provide messages directly. Keep this boundary defensive and server-owned.
	 *
	 * @param mixed $messages Candidate prior messages.
	 * @return array<int, array{role:string,content:string}>
	 */
	private function normalize_messages( $messages ): array {
		if ( ! is_array( $messages ) ) {
			return [];
		}

		$normalized = [];
		foreach ( array_slice( $messages, -20 ) as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) || ! in_array( $message['role'], [ 'user', 'assistant' ], true ) ) {
				continue;
			}

			$content = trim( (string) $message['content'] );
			if ( '' === $content ) {
				continue;
			}

			$normalized[] = [
				'role'    => $message['role'],
				'content' => substr( $content, 0, 10000 ),
			];
		}

		return $normalized;
	}

	/**
	 * Format context array for LLM consumption.
	 *
	 * @param array $context Context data.
	 * @return string Formatted context string.
	 */
	private function format_context_for_llm( array $context ): string {
		$output = "Story Graph Context:\n\n";
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$output .= "## {$key}\n";
				$output .= $this->format_array_recursive( $value, 2 ) . "\n\n";
			} else {
				$output .= "{$key}: {$value}\n\n";
			}
		}
		return $output;
	}

	private function primary_api_key(): string {
		return defined( 'WORLDGRAPH_AI_API_KEY' ) ? WORLDGRAPH_AI_API_KEY : (string) get_option( 'worldgraph_ai_api_key', '' );
	}

	private function fallback_api_key(): string {
		return defined( 'WORLDGRAPH_AI_FALLBACK_API_KEY' ) ? WORLDGRAPH_AI_FALLBACK_API_KEY : (string) get_option( 'worldgraph_ai_fallback_api_key', '' );
	}

	/**
	 * Recursively format an array for LLM consumption.
	 *
	 * @param array  $array The array.
	 * @param int    $depth Current depth.
	 * @return string Formatted string.
	 */
	private function format_array_recursive( array $array, int $depth = 0 ): string {
		$output = '';
		$indent = str_repeat('  ', $depth);
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$output .= "{$indent}{$key}:\n";
				$output .= $this->format_array_recursive( $value, $depth + 1 );
			} else {
				$output .= "{$indent}{$key}: {$value}\n";
			}
		}
		return $output;
	}

	/**
	 * Check rate limit.
	 *
	 * @return bool True if within limit.
	 */
	private function check_rate_limit(): bool {
		$limit  = max( 1, absint( get_option( 'worldgraph_ai_rate_limit', 10 ) ) );
		$now    = time();
		$window = 60; // 1 minute window.
		$user_id = get_current_user_id();
		$key     = 'worldgraph_ai_rate_' . absint( $user_id );
		$request_log = get_transient( $key );
		$request_log = is_array( $request_log ) ? $request_log : [];

		$request_log = array_values( array_filter( $request_log, static function ( $timestamp ) use ( $now, $window ) {
			return $now - $timestamp < $window;
		} ) );

		if ( count( $request_log ) >= $limit ) {
			set_transient( $key, $request_log, $window );
			return false;
		}

		$request_log[] = $now;
		set_transient( $key, $request_log, $window );
		return true;
	}

	/**
	 * Check health of configured LLM backend.
	 *
	 * @return array Health status.
	 */
	public function health_check(): array {
		return $this->test_connection();
	}

	/**
	 * Test an LLM configuration without saving it to WordPress options.
	 *
	 * @param array $configuration Optional backend, URL, model, and API key values.
	 * @return array Connection result.
	 */
	public function test_connection( array $configuration = [] ): array {
		$backend = $configuration['backend'] ?? get_option( 'worldgraph_ai_backend', 'openai_compatible' );
		$url     = $configuration['url'] ?? get_option( 'worldgraph_ai_url', 'http://localhost:11434/v1' );
		$model   = $configuration['model'] ?? get_option( 'worldgraph_ai_model', '' );
		$api_key = $configuration['api_key'] ?? $this->primary_api_key();
		if ( class_exists( '\\WorldGraph\\Utils\\Credential_Store' ) ) {
			$resolved_key = \WorldGraph\Utils\Credential_Store::resolve_reference( (string) $api_key );
			if ( is_wp_error( $resolved_key ) ) {
				return [
					'healthy' => false,
					'backend' => $backend,
					'error'   => $resolved_key->get_error_message(),
				];
			}
			$api_key = $resolved_key;
		}

		if ( ! in_array( $backend, [ 'openai_compatible', 'openai', 'anthropic', 'dual' ], true ) ) {
			return [
				'healthy' => false,
				'backend' => $backend,
				'error'   => 'Unsupported LLM backend.',
			];
		}

		if ( 'anthropic' === $backend ) {
			return [
				'healthy' => '' !== $api_key,
				'backend' => $backend,
				'error'   => '' === $api_key ? 'No Anthropic API key configured.' : '',
			];
		}

		if ( 'openai' === $backend ) {
			$url = 'https://api.openai.com/v1/models';
		} else {
			if ( '' === trim( $url ) ) {
				return [
					'healthy' => false,
					'backend' => $backend,
					'error'   => 'An OpenAI-compatible base URL is required.',
				];
			}
			$url = rtrim( $url, '/' );
			$url .= ( str_ends_with( $url, '/v1' ) ? '' : '/v1' ) . '/models';
		}

		$args = [
			'method'  => 'GET',
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . ( '' !== $api_key ? $api_key : 'local-dev-key' ),
			],
			'timeout' => 5,
		];

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
			$host  = wp_parse_url( $url, PHP_URL_HOST );
			if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
				$error .= ' WordPress makes this request from its container, where localhost is not your development host. Use host.lando.internal (Lando) or a Docker service hostname.';
			}

			return [
				'healthy' => false,
				'backend' => $backend,
				'error'   => $error,
			];
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = [];
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$models = array_filter( array_column( $body['data'], 'id' ) );
		}

		if ( 200 === $status && '' !== $model && ! empty( $models ) && ! in_array( $model, $models, true ) ) {
			return [
				'healthy' => false,
				'backend' => $backend,
				'url'     => $url,
				'status'  => $status,
				'models'  => $models,
				'error'   => sprintf( 'The endpoint is reachable, but model "%s" is not available.', $model ),
			];
		}

		return [
			'healthy' => ( 200 === $status ),
			'backend' => $backend,
			'url'     => $url,
			'status'  => $status,
			'models'  => $models,
			'error'   => 200 === $status ? '' : sprintf( 'Endpoint returned HTTP %d.', $status ),
		];
	}
}
