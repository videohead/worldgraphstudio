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

	/** Smallest provider output budget accepted for one request. */
	private const MIN_REQUEST_TOKENS = 256;

	/** Largest provider output budget accepted for one request. */
	private const MAX_REQUEST_TOKENS = 32768;

	/** Smallest plausible model context window. */
	private const MIN_CONTEXT_WINDOW = 256;

	/** Upper bound for nonsecret provider context metadata. */
	private const MAX_CONTEXT_WINDOW = 4_194_304;

	/** Cache only discovered nonsecret context metadata for five minutes. */
	private const CONTEXT_CACHE_TTL = 300;

	/**
	 * Bounded selected-Connection calls permitted per user and minute.
	 *
	 * One story preview can make up to 141 bounded part/repair calls. Keeping
	 * these calls in their own finite bucket lets one preview finish without
	 * weakening the ten-per-minute limit used by ordinary interactive chat.
	 */
	private const CONNECTION_REQUEST_RATE_LIMIT = 144;

	/**
	 * Cache for LLM responses.
	 *
	 * @var array
	 */
	private $response_cache = [];

	/** Depth of a server-resolved selected-Connection call. */
	private $selected_connection_depth = 0;

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

		// Multi-pass selected-Connection operations use a separate bounded bucket;
		// ordinary chat retains its existing per-user limit.
		$rate_limit_scope = $this->selected_connection_depth > 0 ? 'selected_connection' : 'default';
		if ( ! $this->check_rate_limit( $rate_limit_scope ) ) {
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
				$result = [
					'content' => $cached['content'],
					'backend' => $cached['backend'],
					'tokens'  => $cached['tokens'],
				];
				return $this->add_cached_stop_metadata( $result, $cached );
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
				$this->response_cache[ $cache_key ] = $this->add_cached_stop_metadata( $this->response_cache[ $cache_key ], $cached_content );
				$result = [
					'content' => $cached_content['content'],
					'backend' => $cached_content['backend'],
					'tokens'  => $cached_content['tokens'],
				];
				return $this->add_cached_stop_metadata( $result, $cached_content );
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
				$cache_data = $this->add_cached_stop_metadata( $cache_data, $result );
				$this->response_cache[ $cache_key ] = [
					'content'   => $result['content'],
					'backend'   => $result['backend'],
					'tokens'    => $result['tokens'] ?? 0,
					'timestamp' => time(),
				];
				$this->response_cache[ $cache_key ] = $this->add_cached_stop_metadata( $this->response_cache[ $cache_key ], $result );
				// Store in WordPress transient for persistence across requests.
				set_transient( $transient_key, $cache_data, $cache_ttl );
			}
			return $result;
		}

		// An explicitly selected backend must keep its concrete failure. This is
		// the path used by Connection-backed manuscript decomposition, where a
		// generic fallback error would hide actionable provider details.
		if ( ! $allow_fallback ) {
			return is_array( $result ) ? $result : [
				'content' => 'The selected LLM backend did not return a response.',
				'backend' => $backend,
				'tokens'  => 0,
				'error'   => 'backend_no_response',
			];
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
		$record = $this->connection_record( $connection_id );
		if ( ! is_array( $record ) || 'publish' !== (string) ( $record['status_wp'] ?? '' ) ) {
			return new \WP_Error( 'worldgraph_llm_connection_missing', __( 'The selected published LLM Connection was not found.', 'worldgraph' ) );
		}

		$connection = $this->connection_configuration( $connection_id );
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

		$credential = $this->connection_credential( (string) ( $connection['credential_reference'] ?? '' ) );
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

		$requested_max  = array_key_exists( 'max_tokens', $options ) ? $options['max_tokens'] : null;
		$configured_max = $connection['max_tokens'] ?? 0;
		$requested_temperature  = array_key_exists( 'temperature', $options ) ? $options['temperature'] : null;
		$configured_temperature = $connection['temperature'] ?? null;
		$options['backend']        = $backend;
		$options['model']          = $model;
		$options['max_tokens']     = $this->bounded_connection_tokens( $configured_max, $requested_max );
		$options['temperature']    = $this->bounded_connection_temperature(
			$configured_temperature,
			$requested_temperature,
			'anthropic' === $backend ? 1.0 : 2.0
		);
		$options['endpoint_url']   = $endpoint;
		$options['api_key']        = $credential;
		$options['connection_id']  = $connection_id;
		$options['allow_fallback'] = false;
		$options['cache']          = false;

		$this->selected_connection_depth++;
		try {
			$result = $this->chat( $prompt, $options );
		} finally {
			$this->selected_connection_depth = max( 0, $this->selected_connection_depth - 1 );
		}
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
	 * Discover the configured model's bounded context window without sending a
	 * prompt or caching provider output.
	 *
	 * Explicit nonsecret Connection metadata wins. OpenAI-compatible endpoints
	 * may otherwise expose the same metadata from their bounded /models route.
	 * Discovery failures are intentionally represented by zero.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return int Context window in tokens, or zero when unavailable.
	 */
	public function model_context_window( int $connection_id ): int {
		if ( $connection_id <= 0 ) {
			return 0;
		}

		$record = $this->connection_record( $connection_id );
		if ( ! is_array( $record ) || 'publish' !== (string) ( $record['status_wp'] ?? '' ) ) {
			return 0;
		}

		$connection = $this->connection_configuration( $connection_id );
		if (
			! is_array( $connection )
			|| 'openai_compatible' !== sanitize_key( (string) ( $connection['provider_type'] ?? '' ) )
			|| 'disabled' === (string) ( $connection['status'] ?? '' )
		) {
			return 0;
		}

		$model    = trim( (string) ( $connection['model'] ?? '' ) );
		$endpoint = trim( (string) ( $connection['endpoint_url'] ?? '' ) );
		if ( '' === $model || '' === $endpoint ) {
			return 0;
		}

		$explicit = $this->explicit_context_window( $connection, $model );
		if ( $explicit > 0 ) {
			return $explicit;
		}

		$models_url = $this->models_endpoint_url( $endpoint );
		if ( '' === $models_url ) {
			return 0;
		}

		$cache_key = sprintf(
			'worldgraph_llm_context_%d_%s',
			$connection_id,
			substr( hash( 'sha256', $models_url . '|' . $model ), 0, 16 )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached_window = $this->normalize_context_window( $cached );
			if ( $cached_window > 0 ) {
				return $cached_window;
			}
		}

		$credential = $this->connection_credential( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $credential ) ) {
			return 0;
		}

		$response = $this->remote_get(
			$models_url,
			[
				'method'  => 'GET',
				'headers' => [
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . ( '' !== (string) $credential ? (string) $credential : 'local-dev-key' ),
				],
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 262_144,
			]
		);
		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return 0;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return 0;
		}

		$window = $this->response_context_window( $data, $model );
		if ( $window > 0 ) {
			// Only the bounded integer is cached; never cache response bodies,
			// credentials, prompts, manuscripts, or model output here.
			set_transient( $cache_key, $window, self::CONTEXT_CACHE_TTL );
		}

		return $window;
	}

	/** Read one Connection record. Kept overridable as a provider-free test seam. */
	protected function connection_record( int $connection_id ): ?array {
		return \WorldGraph\Utils\Connection_Repository::get( $connection_id );
	}

	/** Resolve one trusted Connection configuration. */
	protected function connection_configuration( int $connection_id ): ?array {
		return \WorldGraph\Utils\Connection_Repository::resolve( $connection_id );
	}

	/** Resolve a Connection credential without exposing it to callers. */
	protected function connection_credential( string $reference ) {
		return \WorldGraph\Utils\Credential_Store::resolve_reference( $reference );
	}

	/** Perform the bounded metadata request. Kept overridable for mocked tests. */
	protected function remote_get( string $url, array $args ) {
		return wp_remote_get( $url, $args );
	}

	/** Apply caller and Connection ceilings within the supported request range. */
	private function bounded_connection_tokens( $configured, $requested ): int {
		$configured_tokens = absint( $configured );
		$configured_ceiling = $configured_tokens > 0
			? min( self::MAX_REQUEST_TOKENS, $configured_tokens )
			: self::MAX_REQUEST_TOKENS;

		$requested_tokens = null === $requested
			? ( $configured_tokens > 0 ? $configured_tokens : 8192 )
			: absint( $requested );
		$requested_tokens = max(
			self::MIN_REQUEST_TOKENS,
			min( self::MAX_REQUEST_TOKENS, $requested_tokens ?: self::MIN_REQUEST_TOKENS )
		);

		// An explicitly configured value below the normal request floor remains a
		// real ceiling. Connection policy must never be raised by client defaults.
		return max( 1, min( $configured_ceiling, $requested_tokens ) );
	}

	/** Apply a caller's deterministic temperature beneath the Connection ceiling. */
	private function bounded_connection_temperature( $configured, $requested, float $provider_maximum = 2.0 ): float {
		$has_configured = null !== $configured && '' !== trim( (string) $configured ) && is_numeric( $configured );
		$has_requested  = null !== $requested && '' !== trim( (string) $requested ) && is_numeric( $requested );
		$provider_maximum = max( 0.0, min( 2.0, $provider_maximum ) );

		$configured_temperature = $has_configured ? max( 0.0, min( $provider_maximum, (float) $configured ) ) : $provider_maximum;
		$requested_temperature  = $has_requested
			? max( 0.0, min( $provider_maximum, (float) $requested ) )
			: ( $has_configured ? $configured_temperature : 0.1 );

		return min( $configured_temperature, $requested_temperature );
	}

	/** Whether an OpenAI Chat Completions model uses reasoning-era controls. */
	private function is_openai_reasoning_model( string $model ): bool {
		$model = strtolower( trim( $model ) );
		return 1 === preg_match( '/^(?:o[1-9](?:[-_.]|$)|gpt-5(?:[-_.]|$)|codex-mini(?:[-_.]|$))/', $model );
	}

	/** Whether a current Anthropic model requires provider-default temperature. */
	private function anthropic_requires_default_temperature( string $model ): bool {
		$model = strtolower( trim( $model ) );
		$patterns = [
			'/^claude-(?:opus|sonnet|haiku)-(\d+)(?:[-_.](\d+))?/',
			'/^claude-(\d+)(?:[-_.](\d+))?-(?:opus|sonnet|haiku)/',
		];
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $model, $matches ) ) {
				continue;
			}
			$major = (int) $matches[1];
			$minor = isset( $matches[2] ) ? (int) $matches[2] : 0;
			return $major > 4 || ( 4 === $major && $minor > 6 );
		}

		return false;
	}

	/** Read explicit nonsecret context metadata from a Connection. */
	private function explicit_context_window( array $connection, string $model ): int {
		$model_specific = $this->model_metadata_context_window( $connection['model_access'] ?? null, $model );
		if ( $model_specific > 0 ) {
			return $model_specific;
		}

		foreach ( [ 'capabilities', 'rate_limits' ] as $metadata_key ) {
			$model_specific = $this->model_metadata_context_window( $connection[ $metadata_key ] ?? null, $model );
			if ( $model_specific > 0 ) {
				return $model_specific;
			}
		}

		return $this->context_window_from_node( $connection );
	}

	/** Find either model-specific or direct context metadata in a Connection field. */
	private function model_metadata_context_window( $metadata, string $model ): int {
		if ( ! is_array( $metadata ) ) {
			return 0;
		}

		if ( isset( $metadata[ $model ] ) && is_array( $metadata[ $model ] ) ) {
			$window = $this->context_window_from_node( $metadata[ $model ] );
			if ( $window > 0 ) {
				return $window;
			}
		}

		foreach ( [ $metadata['models'] ?? null, $metadata ] as $collection ) {
			if ( ! is_array( $collection ) ) {
				continue;
			}
			if ( isset( $collection[ $model ] ) && is_array( $collection[ $model ] ) ) {
				$window = $this->context_window_from_node( $collection[ $model ] );
				if ( $window > 0 ) {
					return $window;
				}
			}
			foreach ( $collection as $candidate ) {
				if ( ! is_array( $candidate ) || ! $this->model_record_matches( $candidate, $model ) ) {
					continue;
				}
				$window = $this->context_window_from_node( $candidate );
				if ( $window > 0 ) {
					return $window;
				}
			}
		}

		return $this->context_window_from_node( $metadata );
	}

	/** Build an OpenAI-compatible /models URL without retaining URL credentials. */
	private function models_endpoint_url( string $endpoint ): string {
		$parts = wp_parse_url( trim( $endpoint ) );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = (string) ( $parts['host'] ?? '' );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
			return '';
		}

		$path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		if ( str_ends_with( $path, '/chat/completions' ) ) {
			$path = substr( $path, 0, -strlen( '/chat/completions' ) );
		}
		if ( ! str_ends_with( $path, '/models' ) ) {
			$path .= str_ends_with( $path, '/v1' ) ? '/models' : '/v1/models';
		}

		if ( str_contains( $host, ':' ) && ! str_starts_with( $host, '[' ) ) {
			$host = '[' . $host . ']';
		}
		$url = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$url .= ':' . absint( $parts['port'] );
		}

		return $url . '/' . ltrim( $path, '/' );
	}

	/** Find context metadata for the exact configured model in a /models body. */
	private function response_context_window( array $data, string $model ): int {
		$collections = [];
		foreach ( [ 'data', 'models' ] as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$collections[] = $data[ $key ];
			}
		}
		if ( array_is_list( $data ) ) {
			$collections[] = $data;
		} elseif ( $this->model_record_matches( $data, $model ) ) {
			return $this->context_window_from_node( $data );
		}

		foreach ( $collections as $collection ) {
			if ( isset( $collection[ $model ] ) && is_array( $collection[ $model ] ) ) {
				$window = $this->context_window_from_node( $collection[ $model ] );
				if ( $window > 0 ) {
					return $window;
				}
			}
			foreach ( $collection as $candidate ) {
				if ( ! is_array( $candidate ) || ! $this->model_record_matches( $candidate, $model ) ) {
					continue;
				}
				$window = $this->context_window_from_node( $candidate );
				if ( $window > 0 ) {
					return $window;
				}
			}
		}

		return 0;
	}

	/** Require an exact provider model identifier match. */
	private function model_record_matches( array $record, string $model ): bool {
		foreach ( [ 'id', 'model', 'name' ] as $key ) {
			if ( isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) && hash_equals( $model, (string) $record[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Read recognized context fields from one bounded metadata node. */
	private function context_window_from_node( array $node, int $depth = 0 ): int {
		foreach ( [ 'max_model_len', 'context_window', 'max_context_length', 'model_context_window' ] as $key ) {
			if ( array_key_exists( $key, $node ) ) {
				$window = $this->normalize_context_window( $node[ $key ] );
				if ( $window > 0 ) {
					return $window;
				}
			}
		}

		if ( $depth >= 4 ) {
			return 0;
		}
		foreach ( [ 'metadata', 'model_info', 'limits', 'capabilities', 'details' ] as $nested_key ) {
			if ( isset( $node[ $nested_key ] ) && is_array( $node[ $nested_key ] ) ) {
				$window = $this->context_window_from_node( $node[ $nested_key ], $depth + 1 );
				if ( $window > 0 ) {
					return $window;
				}
			}
		}

		return 0;
	}

	/** Accept only plausible bounded integer context metadata. */
	private function normalize_context_window( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}
		$numeric = (float) $value;
		if ( ! is_finite( $numeric ) || floor( $numeric ) !== $numeric ) {
			return 0;
		}
		$window = (int) $numeric;
		return $window >= self::MIN_CONTEXT_WINDOW && $window <= self::MAX_CONTEXT_WINDOW ? $window : 0;
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

		$result = [
			'content' => $data['choices'][0]['message']['content'],
			'backend' => $backend,
			'tokens'  => absint( $data['usage']['total_tokens'] ?? 0 ),
		];
		$finish_reason = $this->sanitize_provider_reason( $data['choices'][0]['finish_reason'] ?? '' );
		if ( '' !== $finish_reason ) {
			$result['finish_reason'] = $finish_reason;
		}
		return $result;
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

		$reasoning_model = $this->is_openai_reasoning_model( $model );
		$body = [
			'model'    => $model,
			'messages' => $messages,
		];
		if ( $reasoning_model ) {
			$body['max_completion_tokens'] = $max_tokens;
		} else {
			$body['max_tokens']  = $max_tokens;
			$body['temperature'] = $temperature;
		}

		$args = [
			'method'  => 'POST',
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body'    => wp_json_encode( $body ),
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

		$result = [
			'content' => $data['choices'][0]['message']['content'],
			'backend' => 'openai',
			'tokens'  => absint( $data['usage']['total_tokens'] ?? 0 ),
		];
		$finish_reason = $this->sanitize_provider_reason( $data['choices'][0]['finish_reason'] ?? '' );
		if ( '' !== $finish_reason ) {
			$result['finish_reason'] = $finish_reason;
		}
		return $result;
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

		$body = [
			'model'      => $model,
			'messages'   => $messages,
			'max_tokens' => $max_tokens,
			'system'     => $system_prompt,
		];
		if ( ! $this->anthropic_requires_default_temperature( $model ) ) {
			$body['temperature'] = max( 0.0, min( 1.0, $temperature ) );
		}

		$args = [
			'method'  => 'POST',
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			],
			'body'    => wp_json_encode( $body ),
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

		$result = [
			'content' => $data['content'][0]['text'],
			'backend' => 'anthropic',
			'tokens'  => absint( $data['usage']['input_tokens'] ?? 0 ) + absint( $data['usage']['output_tokens'] ?? 0 ),
		];
		$stop_reason = $this->sanitize_provider_reason( $data['stop_reason'] ?? '' );
		if ( '' !== $stop_reason ) {
			$result['stop_reason'] = $stop_reason;
		}
		return $result;
	}

	/** Preserve only short provider-controlled stop metadata. */
	private function sanitize_provider_reason( $reason ): string {
		if ( ! is_scalar( $reason ) ) {
			return '';
		}
		return substr( sanitize_key( (string) $reason ), 0, 64 );
	}

	/** Copy optional stop metadata into or out of the legacy response cache. */
	private function add_cached_stop_metadata( array $target, array $source ): array {
		foreach ( [ 'finish_reason', 'stop_reason' ] as $key ) {
			$reason = $this->sanitize_provider_reason( $source[ $key ] ?? '' );
			if ( '' !== $reason ) {
				$target[ $key ] = $reason;
			}
		}
		return $target;
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
	 * @param string $scope Default interactive chat or selected Connection work.
	 * @return bool True if within limit.
	 */
	private function check_rate_limit( string $scope = 'default' ): bool {
		$limit  = max( 1, absint( get_option( 'worldgraph_ai_rate_limit', 10 ) ) );
		$selected_connection = 'selected_connection' === $scope;
		if ( $selected_connection ) {
			$limit = max( $limit, self::CONNECTION_REQUEST_RATE_LIMIT );
		}
		$now    = time();
		$window = 60; // 1 minute window.
		$user_id = get_current_user_id();
		$key     = 'worldgraph_ai_rate_' . ( $selected_connection ? 'selected_connection_' : '' ) . absint( $user_id );
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
