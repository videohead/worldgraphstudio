<?php
/**
 * Streamable HTTP client for the hosted AceData Cloud Suno MCP server.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** AceData Cloud Suno MCP adapter. */
class Suno_MCP {

	/** Hosted Suno MCP endpoint. */
	const ENDPOINT = 'https://suno.mcp.acedata.cloud/mcp';

	/** Tools required for the delivered generation Templates and polling. */
	const GENERATION_TOOLS = [
		'suno_generate_music',
		'suno_generate_custom_music',
		'suno_generate_lyrics',
		'suno_get_task',
	];

	/** Alias describing the complete tool requirement for Connection tests. */
	const REQUIRED_TOOLS = self::GENERATION_TOOLS;

	/** Supported AceData Cloud Suno music models. */
	const MODELS = [
		'chirp-v3-0',
		'chirp-v3-5',
		'chirp-v4',
		'chirp-v4-5',
		'chirp-v4-5-plus',
		'chirp-v5',
		'chirp-v5-5',
	];

	/** HTTP timeout in seconds. */
	const TIMEOUT = 60;

	/**
	 * Test an unsaved MCP configuration and require the delivered tool set.
	 *
	 * @param string $endpoint             MCP endpoint.
	 * @param string $credential_reference AceData Cloud key or env:// reference.
	 * @return array<int, string>|WP_Error
	 */
	public static function test_configuration( string $endpoint, string $credential_reference ) {
		$catalog = self::tool_catalog_for( $endpoint, $credential_reference );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$tools   = array_keys( $catalog );
		$missing = array_values( array_diff( self::REQUIRED_TOOLS, $tools ) );
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'suno_mcp_tools_missing',
				sprintf(
					/* translators: %s: comma-separated MCP tool names. */
					__( 'Suno MCP is missing required tools: %s.', 'worldgraph' ),
					implode( ', ', $missing )
				),
				[ 'available_tools' => $tools, 'missing_tools' => $missing ]
			);
		}

		return $tools;
	}

	/**
	 * Return the MCP tools exposed for a saved Suno Connection.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array<int, string>|WP_Error
	 */
	public static function available_tools( int $connection_id ) {
		$catalog = self::tool_schemas( $connection_id );
		return is_wp_error( $catalog ) ? $catalog : array_keys( $catalog );
	}

	/**
	 * Discover live MCP tool descriptions and input schemas for catalog sync.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array<string, array>|WP_Error
	 */
	public static function tool_schemas( int $connection_id ) {
		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		return self::tool_catalog_for( self::endpoint( $connection ), self::credential_reference( $connection, $connection_id ) );
	}

	/**
	 * Submit one MCP-backed Suno Template.
	 *
	 * @param string $template      Transport-specific Template reference.
	 * @param string $prompt        Music description, lyrics, or lyrics brief.
	 * @param array  $parameters    Provider input values.
	 * @param int    $connection_id Connection post ID.
	 * @return array|WP_Error
	 */
	public static function run_template( string $template, string $prompt, array $parameters, int $connection_id = 0 ) {
		$reference = strtolower( trim( $template ) );
		$tools     = [
			'mcp:suno_generate_music'        => 'suno_generate_music',
			'mcp:suno_generate_custom_music' => 'suno_generate_custom_music',
			'mcp:suno_generate_lyrics'       => 'suno_generate_lyrics',
		];
		if ( ! isset( $tools[ $reference ] ) ) {
			return new WP_Error( 'suno_mcp_template_invalid', __( 'The Suno Template has an unsupported MCP transport reference.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$tool = $tools[ $reference ];
		$text = trim( wp_strip_all_tags( $prompt ) );
		$args = [];
		switch ( $tool ) {
			case 'suno_generate_music':
				if ( '' === $text ) {
					return new WP_Error( 'suno_mcp_prompt_missing', __( 'Enter a description of the music to generate.', 'worldgraph' ) );
				}
				$args = self::allowed_parameters( $parameters, [ 'model', 'instrumental', 'variation_category' ] );
				$args['prompt'] = $text;
				$args['model']  = self::music_model( self::preferred_music_model( $args, $connection ) );
				break;

			case 'suno_generate_custom_music':
				$instrumental = rest_sanitize_boolean( $parameters['instrumental'] ?? false );
				$lyrics       = trim( wp_strip_all_tags( (string) ( $parameters['lyric'] ?? $text ) ) );
				$style        = trim( sanitize_text_field( (string) ( $parameters['style'] ?? '' ) ) );
				$title        = trim( sanitize_text_field( (string) ( $parameters['title'] ?? '' ) ) );
				if ( '' === $style || '' === $title ) {
					return new WP_Error( 'suno_mcp_custom_fields_missing', __( 'Custom Suno generation requires both a style and a title.', 'worldgraph' ) );
				}
				if ( ! $instrumental && '' === $lyrics ) {
					return new WP_Error( 'suno_mcp_lyrics_missing', __( 'Custom vocal music requires lyrics in the generation prompt.', 'worldgraph' ) );
				}
				$args = self::allowed_parameters(
					$parameters,
					[ 'model', 'instrumental', 'lyric_prompt', 'style_negative', 'vocal_gender', 'variation_category', 'weirdness', 'style_influence' ]
				);
				$args['lyric']        = $lyrics;
				$args['title']        = $title;
				$args['style']        = $style;
				$args['instrumental'] = $instrumental;
				$args['model']        = self::music_model( self::preferred_music_model( $args, $connection ) );
				break;

			case 'suno_generate_lyrics':
			default:
				if ( '' === $text ) {
					return new WP_Error( 'suno_mcp_prompt_missing', __( 'Enter a description of the lyrics to generate.', 'worldgraph' ) );
				}
				$args = self::allowed_parameters( $parameters, [ 'model' ] );
				$args['prompt'] = $text;
				$args['model']  = in_array( (string) ( $args['model'] ?? 'default' ), [ 'default', 'remi-v1' ], true ) ? (string) ( $args['model'] ?? 'default' ) : 'default';
				break;
		}

		if ( is_wp_error( $args['model'] ?? null ) ) {
			return $args['model'];
		}
		if ( 'suno_generate_lyrics' !== $tool && ! self::model_is_allowed( $connection, (string) $args['model'] ) ) {
			return new WP_Error( 'suno_mcp_model_not_allowed', __( 'That Suno model is not allowed by the selected Connection.', 'worldgraph' ) );
		}

		$result = self::call_tool( $tool, $args, $connection_id );
		return is_wp_error( $result ) ? $result : self::normalize_result( $result );
	}

	/**
	 * Poll a submitted MCP task through suno_get_task.
	 *
	 * @param string $job_id        AceData Cloud task ID.
	 * @param int    $connection_id Connection post ID.
	 * @param string $template      Template reference, retained for adapter parity.
	 * @return array|WP_Error
	 */
	public static function get_job_status( string $job_id, int $connection_id = 0, string $template = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$job_id = trim( $job_id );
		if ( '' === $job_id ) {
			return new WP_Error( 'suno_mcp_job_id_missing', __( 'The Suno generation job has no task ID.', 'worldgraph' ) );
		}

		$result = self::call_tool( 'suno_get_task', [ 'task_id' => $job_id ], $connection_id );
		return is_wp_error( $result ) ? $result : self::normalize_result( $result, true );
	}

	/**
	 * Normalize an MCP submission or task result.
	 *
	 * @param array $payload AceData Cloud tool result.
	 * @param bool  $polled  Whether this is a suno_get_task response.
	 * @return array
	 */
	public static function normalize_result( array $payload, bool $polled = false ): array {
		$job_id     = self::first_scalar( $payload, [ 'task_id', 'job_id', 'id' ] );
		$raw_status = self::first_scalar( $payload, [ 'state', 'status' ] );
		$status     = self::normalize_status( $raw_status );
		$response   = is_array( $payload['response'] ?? null ) ? $payload['response'] : [];

		if ( $polled ) {
			if ( array_key_exists( 'success', $response ) ) {
				if ( '' === $raw_status ) {
					$status = true === $response['success'] ? 'completed' : 'failed';
				} elseif ( 'completed' === $status && true !== $response['success'] ) {
					$status = 'failed';
				}
			} elseif ( 'completed' === $status ) {
				$status = 'submitted';
			}
		}

		$result = [
			'job_id'    => $job_id,
			'status'    => $status,
			'transport' => 'mcp',
		];
		if ( '' !== $raw_status ) {
			$result['provider_status'] = $raw_status;
		}

		$error = $response['error'] ?? $payload['error'] ?? '';
		if ( is_array( $error ) ) {
			$error = $error['message'] ?? $error['detail'] ?? '';
		}
		if ( is_scalar( $error ) && '' !== trim( (string) $error ) ) {
			$result['error'] = (string) $error;
		}

		if ( 'completed' === $status ) {
			$items = self::result_items( $payload );
			if ( ! empty( $items ) ) {
				$result['items'] = $items;
			}
		}

		return $result;
	}

	/** Map AceData Cloud task states onto World Graph Studio states. */
	public static function normalize_status( string $status ): string {
		$status = strtolower( str_replace( [ ' ', '-' ], '_', trim( $status ) ) );
		if ( in_array( $status, [ 'complete', 'completed', 'success', 'succeeded' ], true ) ) {
			return 'completed';
		}
		if ( in_array( $status, [ 'failed', 'error' ], true ) ) {
			return 'failed';
		}
		if ( in_array( $status, [ 'cancelled', 'canceled' ], true ) ) {
			return 'cancelled';
		}

		return 'submitted';
	}

	/** Whether a music model is permitted by the Connection's allowlist. */
	public static function model_is_allowed( array $connection, string $model ): bool {
		$raw = trim( (string) ( $connection['model_access'] ?? '' ) );
		if ( '' === $raw ) {
			return true;
		}

		$allowed = json_decode( $raw, true );
		if ( ! is_array( $allowed ) ) {
			return false;
		}

		foreach ( $allowed as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}
			$normalized = self::music_model( (string) $candidate );
			if ( ! is_wp_error( $normalized ) && $model === $normalized ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decode JSON text or structured content returned by tools/call.
	 *
	 * @param array $result MCP tools/call result.
	 * @return array|WP_Error
	 */
	public static function decode_tool_result( array $result ) {
		if ( ! empty( $result['isError'] ) ) {
			$message = __( 'Suno MCP tool call failed.', 'worldgraph' );
			foreach ( (array) ( $result['content'] ?? [] ) as $content ) {
				if ( is_array( $content ) && ! empty( $content['text'] ) ) {
					$message = (string) $content['text'];
					break;
				}
			}
			return new WP_Error( 'suno_mcp_tool_error', $message );
		}

		if ( is_array( $result['structuredContent'] ?? null ) ) {
			return $result['structuredContent'];
		}
		if ( is_array( $result['structured_content'] ?? null ) ) {
			return $result['structured_content'];
		}

		foreach ( (array) ( $result['content'] ?? [] ) as $content ) {
			if ( ! is_array( $content ) || ! isset( $content['text'] ) ) {
				continue;
			}
			$decoded = json_decode( (string) $content['text'], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return $result;
	}

	/** Discover tools for one endpoint and retain their live schemas. */
	private static function tool_catalog_for( string $endpoint, string $credential_reference ) {
		$initialized = self::request_to(
			$endpoint,
			$credential_reference,
			'initialize',
			[
				'protocolVersion' => '2025-03-26',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => [
					'name'    => 'World Graph Studio WordPress',
					'version' => defined( 'WORLDGRAPH_VERSION' ) ? WORLDGRAPH_VERSION : '1.0.0',
				],
			]
		);
		if ( is_wp_error( $initialized ) ) {
			return $initialized;
		}

		$result = self::request_to(
			$endpoint,
			$credential_reference,
			'tools/list',
			[],
			(string) ( $initialized['_session_id'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$catalog = [];
		foreach ( (array) ( $result['tools'] ?? [] ) as $tool ) {
			$name = is_array( $tool ) ? sanitize_key( (string) ( $tool['name'] ?? '' ) ) : '';
			if ( '' === $name ) {
				continue;
			}
			$catalog[ $name ] = [
				'name'        => $name,
				'description' => sanitize_textarea_field( (string) ( $tool['description'] ?? '' ) ),
				'inputSchema' => is_array( $tool['inputSchema'] ?? null ) ? $tool['inputSchema'] : (array) ( $tool['input_schema'] ?? [] ),
			];
		}

		return $catalog;
	}

	/** Call one allowlisted Suno MCP tool. */
	private static function call_tool( string $name, array $arguments, int $connection_id ) {
		if ( ! in_array( $name, self::REQUIRED_TOOLS, true ) ) {
			return new WP_Error( 'suno_mcp_tool_not_allowed', __( 'That Suno MCP tool is not available to generation Templates.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$endpoint   = self::endpoint( $connection );
		$credential = self::credential_reference( $connection, $connection_id );
		$initialized = self::request_to(
			$endpoint,
			$credential,
			'initialize',
			[
				'protocolVersion' => '2025-03-26',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => [
					'name'    => 'World Graph Studio WordPress',
					'version' => defined( 'WORLDGRAPH_VERSION' ) ? WORLDGRAPH_VERSION : '1.0.0',
				],
			]
		);
		if ( is_wp_error( $initialized ) ) {
			return $initialized;
		}

		$result = self::request_to(
			$endpoint,
			$credential,
			'tools/call',
			[
				'name'      => $name,
				'arguments' => (object) $arguments,
			],
			(string) ( $initialized['_session_id'] ?? '' )
		);
		return is_wp_error( $result ) ? $result : self::decode_tool_result( $result );
	}

	/** Send one JSON-RPC request to the hosted Streamable HTTP endpoint. */
	private static function request_to( string $endpoint, string $credential_reference, string $method, array $params, string $session_id = '' ) {
		$endpoint = untrailingslashit( esc_url_raw( $endpoint ?: self::ENDPOINT ) );
		$api_key  = self::resolve_credential( $credential_reference );
		if ( '' === $api_key ) {
			return new WP_Error( 'suno_mcp_credential_missing', __( 'Set an AceData Cloud token or env://ACEDATACLOUD_API_TOKEN reference on this Connection.', 'worldgraph' ) );
		}

		$headers = [
			'Accept'        => 'application/json, text/event-stream',
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		];
		if ( '' !== $session_id ) {
			$headers['Mcp-Session-Id'] = $session_id;
		}

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => wp_json_encode(
					[
						'jsonrpc' => '2.0',
						'id'      => wp_generate_uuid4(),
						'method'  => $method,
						'params'  => (object) $params,
					]
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'suno_mcp_unreachable', $response->get_error_message() );
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$payload = self::decode_response( (string) wp_remote_retrieve_body( $response ) );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $payload ) ? (string) ( $payload['error']['message'] ?? __( 'Suno MCP request failed.', 'worldgraph' ) ) : __( 'Suno MCP request failed.', 'worldgraph' );
			return new WP_Error( 'suno_mcp_request_failed', $message, [ 'status' => $status ] );
		}
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'suno_mcp_invalid_response', __( 'Suno MCP returned non-JSON content.', 'worldgraph' ) );
		}
		if ( isset( $payload['error'] ) ) {
			return new WP_Error( 'suno_mcp_error', (string) ( $payload['error']['message'] ?? __( 'Suno MCP returned an error.', 'worldgraph' ) ), $payload['error'] );
		}

		$result = $payload['result'] ?? $payload;
		if ( ! is_array( $result ) ) {
			return new WP_Error( 'suno_mcp_invalid_response', __( 'Suno MCP returned an invalid result.', 'worldgraph' ) );
		}
		if ( 'initialize' === $method ) {
			$mcp_session_id = (string) wp_remote_retrieve_header( $response, 'mcp-session-id' );
			if ( '' !== $mcp_session_id ) {
				$result['_session_id'] = $mcp_session_id;
			}
		}

		return $result;
	}

	/** Decode JSON or Streamable HTTP SSE data frames. */
	private static function decode_response( string $body ) {
		$decoded = json_decode( trim( $body ), true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$last = null;
		foreach ( preg_split( '/\r?\n/', $body ) as $line ) {
			if ( 0 !== strpos( $line, 'data:' ) ) {
				continue;
			}
			$decoded = json_decode( trim( substr( $line, 5 ) ), true );
			if ( is_array( $decoded ) ) {
				$last = $decoded;
			}
		}

		return $last;
	}

	/** Copy only fields declared by the selected MCP tool. */
	private static function allowed_parameters( array $parameters, array $allowed ): array {
		return array_filter(
			array_intersect_key( $parameters, array_flip( $allowed ) ),
			static function ( $value ): bool {
				return null !== $value;
			}
		);
	}

	/** Prefer a Template override, then the Connection setting, then MCP default. */
	private static function preferred_music_model( array $arguments, array $connection ): string {
		$model = trim( (string) ( $arguments['model'] ?? '' ) );
		if ( '' === $model ) {
			$model = trim( (string) ( $connection['model'] ?? '' ) );
		}

		return '' !== $model ? $model : 'chirp-v5-5';
	}

	/** Normalize a shared Suno model setting to an AceData Cloud chirp model. */
	private static function music_model( string $model ) {
		$aliases = [
			'V4'       => 'chirp-v4',
			'V4_5'     => 'chirp-v4-5',
			'V4_5PLUS' => 'chirp-v4-5-plus',
			'V4_5ALL'  => 'chirp-v4-5-plus',
			'V5'       => 'chirp-v5',
			'V5_5'     => 'chirp-v5-5',
		];
		$model   = trim( $model );
		$model   = $aliases[ strtoupper( $model ) ] ?? strtolower( $model );
		return in_array( $model, self::MODELS, true )
			? $model
			: new WP_Error( 'suno_mcp_model_invalid', __( 'Select a chirp model supported by Suno MCP.', 'worldgraph' ) );
	}

	/** Resolve and validate one saved Suno Connection. */
	private static function connection( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'suno' !== ( $connection['provider_type'] ?? '' ) ) {
			return new WP_Error( 'suno_mcp_connection_invalid', __( 'The selected Connection is not a Suno Connection.', 'worldgraph' ) );
		}

		return $connection;
	}

	/** Resolve the dedicated AceData Cloud MCP credential. */
	private static function credential_reference( array $connection, int $connection_id ): string {
		$reference = trim( (string) ( $connection['mcp_credential_reference'] ?? '' ) );
		if ( '' === $reference && $connection_id ) {
			$reference = trim( (string) worldgraph_get_field_value( $connection_id, 'mcp_credential_reference' ) );
		}

		return $reference;
	}

	/** Resolve a saved Connection's hosted MCP endpoint. */
	private static function endpoint( array $connection ): string {
		$endpoint = trim( (string) ( $connection['mcp_endpoint_url'] ?? '' ) );
		if ( '' !== $endpoint ) {
			return $endpoint;
		}

		$legacy = trim( (string) ( $connection['endpoint_url'] ?? '' ) );
		return false !== strpos( $legacy, '/mcp' ) ? $legacy : self::ENDPOINT;
	}

	/** Resolve a literal token or env:// environment-variable reference. */
	private static function resolve_credential( string $reference ): string {
		$reference = trim( $reference );
		if ( 0 === strpos( $reference, 'env://' ) ) {
			$name = substr( $reference, 6 );
			if ( ! preg_match( '/^[A-Z_][A-Z0-9_]*$/', $name ) ) {
				return '';
			}
			$value = getenv( $name );
			return false === $value ? '' : trim( (string) $value );
		}

		return $reference;
	}

	/** Return the first scalar value found beneath common response wrappers. */
	private static function first_scalar( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				return (string) $data[ $key ];
			}
		}
		foreach ( [ 'response', 'data' ] as $wrapper ) {
			if ( is_array( $data[ $wrapper ] ?? null ) ) {
				$value = self::first_scalar( $data[ $wrapper ], $keys );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	/** Whether an array uses zero-based consecutive integer keys. */
	private static function is_list( array $value ): bool {
		return $value === array_values( $value );
	}

	/** Normalize every final audio track or lyrics variant. */
	private static function result_items( array $payload ): array {
		$candidates = [
			$payload['response']['data'] ?? null,
			$payload['response']['suno_data'] ?? null,
			$payload['data'] ?? null,
		];
		$records = [];
		foreach ( $candidates as $candidate ) {
			if ( is_array( $candidate ) && self::is_list( $candidate ) ) {
				$records = $candidate;
				break;
			}
		}

		$items = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( isset( $record['text'] ) ) {
				$text = trim( (string) $record['text'] );
				if ( '' !== $text ) {
					$items[] = [
						'text'   => $text,
						'title'  => sanitize_text_field( (string) ( $record['title'] ?? '' ) ),
						'status' => sanitize_key( (string) ( $record['status'] ?? 'complete' ) ),
					];
				}
				continue;
			}

			$audio_url = (string) ( $record['audio_url'] ?? $record['audioUrl'] ?? $record['url'] ?? '' );
			if ( ! filter_var( $audio_url, FILTER_VALIDATE_URL ) ) {
				continue;
			}
			$item = [
				'url'       => $audio_url,
				'audio_url' => $audio_url,
			];
			$image_url = (string) ( $record['image_url'] ?? $record['imageUrl'] ?? '' );
			if ( filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
				$item['cover_image_url'] = $image_url;
			}
			foreach ( [ 'id', 'title', 'lyric', 'prompt', 'style', 'duration', 'model' ] as $key ) {
				if ( isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ) {
					$item[ $key ] = $record[ $key ];
				}
			}
			$items[] = $item;
		}

		return $items;
	}
}
