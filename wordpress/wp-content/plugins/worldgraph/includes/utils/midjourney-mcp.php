<?php
/**
 * Streamable HTTP client for the hosted AceData Cloud Midjourney MCP server.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** AceData Cloud Midjourney MCP generation adapter. */
class Midjourney_MCP {

	/** Hosted Midjourney MCP endpoint. */
	const ENDPOINT = 'https://midjourney.mcp.acedata.cloud/mcp';

	/** Initialization-based protocol revision documented by the provider. */
	const PROTOCOL_VERSION = '2025-03-26';

	/** Tools required for the delivered generation Template and polling. */
	const REQUIRED_TOOLS = [
		'midjourney_imagine',
		'midjourney_get_task',
	];

	/** Provider default generation timeout in seconds. */
	const DEFAULT_GENERATION_TIMEOUT = 480;

	/** Smallest accepted provider generation timeout in seconds. */
	const MIN_GENERATION_TIMEOUT = 60;

	/** Largest accepted provider generation timeout in seconds. */
	const MAX_GENERATION_TIMEOUT = 1200;

	/** HTTP timeout in seconds for one request-scoped MCP exchange. */
	const TIMEOUT = 60;

	/** Maximum response bytes accepted from the MCP endpoint. */
	const MAX_RESPONSE_BYTES = 1048576;

	/** Maximum SSE events decoded from one response. */
	const MAX_EVENTS = 100;

	/** Maximum paginated tools/list requests. */
	const MAX_PAGES = 10;

	/** Maximum tools retained from the remote catalog. */
	const MAX_TOOLS = 200;

	/** Maximum encoded size retained for one tool schema. */
	const MAX_SCHEMA_BYTES = 65536;

	/** Maximum remote-schema nesting depth. */
	const MAX_SCHEMA_DEPTH = 10;

	/** Maximum nodes retained while sanitizing one remote schema. */
	const MAX_SCHEMA_ITEMS = 1000;

	/** Maximum decoded text accepted from one tools/call result. */
	const MAX_TOOL_RESULT_BYTES = 524288;

	/** Maximum prompt bytes sent to the provider. */
	const MAX_PROMPT_BYTES = 8000;

	/** Maximum task ID bytes accepted from provider or job state. */
	const MAX_TASK_ID_BYTES = 256;

	/** Maximum distinct image outputs retained from one provider result. */
	const MAX_OUTPUT_URLS = 32;

	/** Maximum provider-returned media URL size. */
	const MAX_MEDIA_URL_BYTES = 8192;

	/** Maximum bearer-token bytes accepted from a literal or environment reference. */
	const MAX_CREDENTIAL_BYTES = 8192;

	/**
	 * Test unsaved endpoint/token values and require the delivered tool set.
	 *
	 * @param string $endpoint             MCP endpoint.
	 * @param string $credential_reference AceData Cloud token or env:// reference.
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
				'midjourney_mcp_tools_missing',
				sprintf(
					/* translators: %s: comma-separated MCP tool names. */
					__( 'Midjourney MCP is missing required tools: %s.', 'worldgraph' ),
					implode( ', ', $missing )
				),
				[
					'available_tools' => $tools,
					'missing_tools'   => $missing,
				]
			);
		}

		return $tools;
	}

	/** Return valid tool names advertised for one saved Connection. */
	public static function available_tools( int $connection_id ) {
		$catalog = self::tool_schemas( $connection_id );
		return is_wp_error( $catalog ) ? $catalog : array_keys( $catalog );
	}

	/**
	 * Return a bounded, sanitized runtime tool catalog.
	 *
	 * @return array<string, array<string, mixed>>|WP_Error
	 */
	public static function tool_schemas( int $connection_id ) {
		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		return self::tool_catalog_for(
			self::endpoint( $connection ),
			self::credential_reference( $connection )
		);
	}

	/**
	 * Submit the reviewed Midjourney MCP imagine Template.
	 *
	 * @param string $template      Transport-specific Template reference.
	 * @param string $prompt        Image prompt.
	 * @param array  $parameters    Reviewed provider input values.
	 * @param int    $connection_id Connection post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run_template( string $template, string $prompt, array $parameters, int $connection_id = 0 ) {
		if ( 'mcp:midjourney_imagine' !== strtolower( trim( $template ) ) ) {
			return new WP_Error( 'midjourney_mcp_template_invalid', __( 'The Midjourney Template has an unsupported MCP transport reference.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$prompt = trim( sanitize_textarea_field( wp_strip_all_tags( $prompt ) ) );
		if ( '' === $prompt ) {
			return new WP_Error( 'midjourney_mcp_prompt_missing', __( 'Enter a prompt for Midjourney image generation.', 'worldgraph' ) );
		}
		if ( strlen( $prompt ) > self::MAX_PROMPT_BYTES ) {
			return new WP_Error( 'midjourney_mcp_prompt_too_large', __( 'The Midjourney prompt is too long.', 'worldgraph' ) );
		}

		$mode_value = $parameters['mode'] ?? 'fast';
		$mode       = is_scalar( $mode_value ) ? strtolower( trim( (string) $mode_value ) ) : '';
		if ( ! in_array( $mode, [ 'fast', 'relax', 'turbo' ], true ) ) {
			return new WP_Error( 'midjourney_mcp_mode_invalid', __( 'Select fast, relax, or turbo Midjourney generation mode.', 'worldgraph' ) );
		}

		$translation = self::boolean_parameter( $parameters['translation'] ?? false, 'translation' );
		if ( is_wp_error( $translation ) ) {
			return $translation;
		}
		$split_images = self::boolean_parameter( $parameters['split_images'] ?? true, 'split_images' );
		if ( is_wp_error( $split_images ) ) {
			return $split_images;
		}

		$arguments = [
			'prompt'       => $prompt,
			'mode'         => $mode,
			'translation'  => $translation,
			'split_images' => $split_images,
		];
		if ( array_key_exists( 'timeout', $parameters ) && null !== $parameters['timeout'] && '' !== $parameters['timeout'] ) {
			$timeout = self::generation_timeout( $parameters['timeout'] );
			if ( is_wp_error( $timeout ) ) {
				return $timeout;
			}
			$arguments['timeout'] = $timeout;
		}

		$result = self::call_tool(
			'midjourney_imagine',
			$arguments,
			self::endpoint( $connection ),
			self::credential_reference( $connection )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$normalized = self::normalize_result( $result );
		if ( '' === $normalized['job_id'] ) {
			return new WP_Error( 'midjourney_mcp_task_id_missing', __( 'Midjourney MCP accepted the request without returning a task ID.', 'worldgraph' ) );
		}

		return $normalized;
	}

	/**
	 * Poll one Midjourney MCP task through midjourney_get_task.
	 *
	 * @param string $job_id        AceData Cloud task ID.
	 * @param int    $connection_id Connection post ID.
	 * @param string $template      Template reference retained for polling parity.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_job_status( string $job_id, int $connection_id = 0, string $template = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$job_id = self::task_id( $job_id );
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$result = self::call_tool(
			'midjourney_get_task',
			[ 'task_id' => $job_id ],
			self::endpoint( $connection ),
			self::credential_reference( $connection )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$normalized = self::normalize_result( $result, true );
		if ( '' === $normalized['job_id'] ) {
			$normalized['job_id'] = $job_id;
		} elseif ( ! hash_equals( $job_id, $normalized['job_id'] ) ) {
			return new WP_Error( 'midjourney_mcp_job_id_mismatch', __( 'Midjourney MCP returned a different task than the one requested.', 'worldgraph' ) );
		}
		if ( 'completed' === $normalized['status'] && empty( $normalized['output_media'] ) ) {
			return new WP_Error( 'midjourney_mcp_output_missing', __( 'Midjourney MCP reported completion without a valid HTTPS image output.', 'worldgraph' ) );
		}

		return $normalized;
	}

	/**
	 * Decode structured content or JSON text returned by tools/call.
	 *
	 * @param array<string, mixed> $result MCP tools/call result.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function decode_tool_result( array $result ) {
		if ( ! empty( $result['isError'] ) ) {
			return new WP_Error( 'midjourney_mcp_tool_error', __( 'Midjourney MCP tool call failed.', 'worldgraph' ) );
		}

		foreach ( [ 'structuredContent', 'structured_content' ] as $key ) {
			if ( is_array( $result[ $key ] ?? null ) ) {
				return self::decode_structured_result( $result[ $key ] );
			}
		}

		$content_count = 0;
		foreach ( (array) ( $result['content'] ?? [] ) as $content ) {
			++$content_count;
			if ( $content_count > 50 ) {
				return new WP_Error( 'midjourney_mcp_tool_result_limit', __( 'Midjourney MCP returned too many tool-result content items.', 'worldgraph' ) );
			}
			if ( ! is_array( $content ) || ! is_scalar( $content['text'] ?? null ) ) {
				continue;
			}
			$text = trim( (string) $content['text'] );
			if ( strlen( $text ) > self::MAX_TOOL_RESULT_BYTES ) {
				return new WP_Error( 'midjourney_mcp_tool_result_too_large', __( 'Midjourney MCP returned an oversized tool result.', 'worldgraph' ) );
			}
			$decoded = json_decode( $text, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return new WP_Error( 'midjourney_mcp_tool_result_invalid', __( 'Midjourney MCP returned a malformed tool result.', 'worldgraph' ) );
	}

	/**
	 * Normalize a submission or task lookup for the generation worker.
	 *
	 * @param array<string, mixed> $payload Provider tool payload.
	 * @param bool                 $polled  Whether the payload came from midjourney_get_task.
	 * @return array<string, mixed>
	 */
	public static function normalize_result( array $payload, bool $polled = false ): array {
		$job_id         = self::first_scalar( $payload, [ 'task_id', 'taskId', 'id' ] );
		$provider_state = self::first_scalar( $payload, [ 'state', 'status' ] );
		$output_media   = self::image_outputs( $payload );
		$status         = self::normalize_status( $provider_state );
		$response       = is_array( $payload['response'] ?? null ) ? $payload['response'] : [];
		$polling        = is_array( $payload['mcp_task_polling'] ?? null ) ? $payload['mcp_task_polling'] : [];
		$validated_id   = self::task_id( $job_id );
		$job_id         = is_wp_error( $validated_id ) ? '' : $validated_id;

		$response_success = array_key_exists( 'success', $response ) && is_bool( $response['success'] ) ? $response['success'] : null;
		$top_success      = array_key_exists( 'success', $payload ) && is_bool( $payload['success'] ) ? $payload['success'] : null;
		if ( $polled ) {
			if ( ! in_array( $status, [ 'failed', 'cancelled' ], true ) && ( false === $response_success || false === $top_success || true === ( $polling['is_failed'] ?? false ) ) ) {
				$status = 'failed';
			} elseif ( ! in_array( $status, [ 'failed', 'cancelled' ], true ) && ( true === $response_success || true === ( $polling['is_complete'] ?? false ) ) ) {
				$status = 'completed';
			}
		} elseif ( false === $top_success ) {
			$status = 'failed';
		} elseif ( ! empty( $output_media ) && ( true === $top_success || 'completed' === $status ) ) {
			$status = 'completed';
		} else {
			$status = 'submitted';
		}

		$result = [
			'job_id'    => $job_id,
			'status'    => $status,
			'transport' => 'mcp',
		];
		if ( '' !== $provider_state ) {
			$result['provider_status'] = substr( sanitize_text_field( $provider_state ), 0, 100 );
		}
		if ( ! empty( $output_media ) ) {
			$result['output_media'] = $output_media;
		}

		if ( 'failed' === $status ) {
			$result['error'] = __( 'Midjourney generation failed.', 'worldgraph' );
		}

		return $result;
	}

	/** Map provider task states onto World Graph Studio generation states. */
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

	/**
	 * Decode JSON or fully framed SSE and correlate the terminal response by ID.
	 *
	 * @return array<string, mixed>|WP_Error|null
	 */
	public static function decode_response_for_id( string $body, string $request_id ) {
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error( 'midjourney_mcp_response_too_large', __( 'Midjourney MCP returned an oversized response.', 'worldgraph' ) );
		}

		$trimmed  = trim( $body );
		$messages = [];
		$decoded  = json_decode( $trimmed, true );
		if ( is_array( $decoded ) ) {
			$messages = self::is_list( $decoded ) ? $decoded : [ $decoded ];
		} elseif ( '' !== $trimmed ) {
			$normalized = str_replace( [ "\r\n", "\r" ], "\n", $body );
			$events     = preg_split( '/\n\n+/', $normalized );
			if ( ! is_array( $events ) || count( $events ) > self::MAX_EVENTS ) {
				return new WP_Error( 'midjourney_mcp_sse_invalid', __( 'Midjourney MCP returned too many SSE events.', 'worldgraph' ) );
			}
			foreach ( $events as $event ) {
				$data_lines = [];
				foreach ( explode( "\n", (string) $event ) as $line ) {
					if ( str_starts_with( $line, ':' ) ) {
						continue;
					}
					if ( 'data:' === substr( $line, 0, 5 ) ) {
						$data_lines[] = ltrim( substr( $line, 5 ), ' ' );
					}
				}
				if ( empty( $data_lines ) ) {
					continue;
				}
				$event_data = implode( "\n", $data_lines );
				if ( '[DONE]' === trim( $event_data ) ) {
					continue;
				}
				$message = json_decode( $event_data, true );
				if ( is_array( $message ) ) {
					$messages[] = $message;
				}
			}
		}

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! array_key_exists( 'id', $message ) || (string) $message['id'] !== $request_id ) {
				continue;
			}
			return $message;
		}

		return null;
	}

	/** Discover a bounded tool catalog, recovering one expired session. */
	private static function tool_catalog_for( string $endpoint, string $credential_reference ) {
		$endpoint = self::validated_endpoint( $endpoint );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		$token = self::resolve_credential( $credential_reference );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$catalog = self::tool_catalog_once( $endpoint, $token );
			if ( ! is_wp_error( $catalog ) || 'midjourney_mcp_session_expired' !== $catalog->get_error_code() ) {
				return $catalog;
			}
		}

		return $catalog;
	}

	/** Perform one initialized tools/list lifecycle. */
	private static function tool_catalog_once( string $endpoint, string $token ) {
		$session = self::open_session( $endpoint, $token );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$catalog    = [];
		$cursor     = '';
		$seen       = [];
		$session_id = (string) $session['session_id'];
		try {
			for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
				$params = '' === $cursor ? [] : [ 'cursor' => $cursor ];
				$listed = self::request( $endpoint, $token, 'tools/list', $params, $session_id );
				if ( is_wp_error( $listed ) ) {
					return $listed;
				}
				$result = (array) ( $listed['result'] ?? [] );
				$merged = self::merge_tools( $catalog, $result['tools'] ?? [] );
				if ( is_wp_error( $merged ) ) {
					return $merged;
				}
				$catalog = $merged;
				$cursor  = is_scalar( $result['nextCursor'] ?? null ) ? trim( (string) $result['nextCursor'] ) : '';
				if ( '' === $cursor ) {
					return $catalog;
				}
				if ( strlen( $cursor ) > 1024 || preg_match( '/[\x00-\x1F\x7F]/', $cursor ) || isset( $seen[ $cursor ] ) ) {
					return new WP_Error( 'midjourney_mcp_pagination_invalid', __( 'Midjourney MCP returned an invalid or repeated tools/list cursor.', 'worldgraph' ) );
				}
				$seen[ $cursor ] = true;
			}
		} finally {
			self::close_session( $endpoint, $token, $session_id );
		}

		return new WP_Error( 'midjourney_mcp_pagination_limit', __( 'Midjourney MCP exceeded the tools/list pagination limit.', 'worldgraph' ) );
	}

	/** Call one allowlisted tool, recovering one expired session. */
	private static function call_tool( string $name, array $arguments, string $endpoint, string $credential_reference ) {
		if ( ! in_array( $name, self::REQUIRED_TOOLS, true ) ) {
			return new WP_Error( 'midjourney_mcp_tool_not_allowed', __( 'That Midjourney MCP tool is not available to generation Templates.', 'worldgraph' ) );
		}

		$endpoint = self::validated_endpoint( $endpoint );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		$token = self::resolve_credential( $credential_reference );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$result = self::call_tool_once( $name, $arguments, $endpoint, $token );
			if ( ! is_wp_error( $result ) || 'midjourney_mcp_session_expired' !== $result->get_error_code() ) {
				return $result;
			}
		}

		return $result;
	}

	/** Perform one initialized tools/call lifecycle. */
	private static function call_tool_once( string $name, array $arguments, string $endpoint, string $token ) {
		$session = self::open_session( $endpoint, $token );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$session_id = (string) $session['session_id'];
		try {
			$called = self::request(
				$endpoint,
				$token,
				'tools/call',
				[
					'name'      => $name,
					'arguments' => (object) $arguments,
				],
				$session_id
			);
			if ( is_wp_error( $called ) ) {
				return $called;
			}

			return self::decode_tool_result( (array) ( $called['result'] ?? [] ) );
		} finally {
			self::close_session( $endpoint, $token, $session_id );
		}
	}

	/** Initialize MCP, validate the negotiated contract, and notify readiness. */
	private static function open_session( string $endpoint, string $token ) {
		$initialized = self::request(
			$endpoint,
			$token,
			'initialize',
			[
				'protocolVersion' => self::PROTOCOL_VERSION,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => self::client_info(),
			]
		);
		if ( is_wp_error( $initialized ) ) {
			return $initialized;
		}

		$version    = (string) ( $initialized['result']['protocolVersion'] ?? '' );
		$session_id = (string) ( $initialized['session_id'] ?? '' );
		if ( self::PROTOCOL_VERSION !== $version ) {
			self::close_session( $endpoint, $token, $session_id );
			return new WP_Error( 'midjourney_mcp_protocol_unsupported', __( 'Midjourney MCP negotiated a protocol version this client does not implement.', 'worldgraph' ) );
		}
		$capabilities = $initialized['result']['capabilities'] ?? null;
		if ( ! is_array( $capabilities ) || ! array_key_exists( 'tools', $capabilities ) ) {
			self::close_session( $endpoint, $token, $session_id );
			return new WP_Error( 'midjourney_mcp_tools_unsupported', __( 'Midjourney MCP did not advertise the tools capability.', 'worldgraph' ) );
		}
		if ( '' !== $session_id && ( strlen( $session_id ) > 512 || ! preg_match( '/^[\x21-\x7E]+$/D', $session_id ) ) ) {
			return new WP_Error( 'midjourney_mcp_session_invalid', __( 'Midjourney MCP returned an invalid session identifier.', 'worldgraph' ) );
		}

		$notification = self::initialized_notification( $endpoint, $token, $session_id );
		if ( is_wp_error( $notification ) ) {
			self::close_session( $endpoint, $token, $session_id );
			return $notification;
		}

		return [
			'protocol_version' => $version,
			'session_id'       => $session_id,
		];
	}

	/** Send one initialization-era JSON-RPC request. */
	private static function request( string $endpoint, string $token, string $method, array $params, string $session_id = '' ) {
		$id       = wp_generate_uuid4();
		$response = self::http_request(
			$endpoint,
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => (object) $params,
			],
			self::headers( $token, $session_id )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status  = (int) $response['status'];
		$message = self::decode_response_for_id( (string) $response['body'], $id );
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'midjourney_mcp_unauthorized', __( 'Midjourney MCP authorization is missing or expired.', 'worldgraph' ), [ 'status' => $status ] );
		}
		if ( 404 === $status && '' !== $session_id ) {
			return new WP_Error( 'midjourney_mcp_session_expired', __( 'Midjourney MCP expired the negotiated session.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		if ( $status < 200 || $status >= 300 ) {
			return self::response_error( $message, $status );
		}
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		if ( ! is_array( $message ) ) {
			return new WP_Error( 'midjourney_mcp_invalid_response', __( 'Midjourney MCP returned no correlated JSON-RPC response.', 'worldgraph' ) );
		}
		if ( isset( $message['error'] ) ) {
			return self::jsonrpc_error( $message['error'] );
		}
		if ( ! is_array( $message['result'] ?? null ) ) {
			return new WP_Error( 'midjourney_mcp_invalid_response', __( 'Midjourney MCP returned an invalid JSON-RPC result.', 'worldgraph' ) );
		}

		$session_header = self::validated_session_header( (string) ( $response['session_id'] ?? '' ) );
		if ( is_wp_error( $session_header ) ) {
			return $session_header;
		}

		return [
			'result'     => $message['result'],
			'session_id' => $session_header,
		];
	}

	/** Complete the required initialized notification exchange. */
	private static function initialized_notification( string $endpoint, string $token, string $session_id ) {
		$response = self::http_request(
			$endpoint,
			[
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
				'params'  => new \stdClass(),
			],
			self::headers( $token, $session_id )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) $response['status'];
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'midjourney_mcp_unauthorized', __( 'Midjourney MCP authorization is missing or expired.', 'worldgraph' ), [ 'status' => $status ] );
		}
		if ( 404 === $status && '' !== $session_id ) {
			return new WP_Error( 'midjourney_mcp_session_expired', __( 'Midjourney MCP expired the negotiated session.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		if ( 202 !== $status || '' !== trim( (string) $response['body'] ) ) {
			return new WP_Error( 'midjourney_mcp_initialized_rejected', __( 'Midjourney MCP did not accept the initialized notification.', 'worldgraph' ) );
		}

		return true;
	}

	/** Build headers for the provider's 2025-03-26 Streamable HTTP contract. */
	private static function headers( string $token, string $session_id ): array {
		$headers = [
			'Accept'        => 'application/json, text/event-stream',
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		];
		if ( '' !== $session_id ) {
			$headers['Mcp-Session-Id'] = $session_id;
		}

		return $headers;
	}

	/** Best-effort termination of an issued MCP session. */
	private static function close_session( string $endpoint, string $token, string $session_id ): void {
		if ( '' === $session_id ) {
			return;
		}

		wp_safe_remote_request(
			$endpoint,
			[
				'method'              => 'DELETE',
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'headers'             => self::headers( $token, $session_id ),
			]
		);
	}

	/** Send one bounded HTTP POST and retain only transport metadata. */
	private static function http_request( string $endpoint, array $payload, array $headers ) {
		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return new WP_Error( 'midjourney_mcp_request_invalid', __( 'Midjourney MCP request data could not be encoded.', 'worldgraph' ) );
		}

		$response = wp_safe_remote_request(
			$endpoint,
			[
				'method'              => 'POST',
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => $headers,
				'body'                => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'midjourney_mcp_unreachable', __( 'Midjourney MCP could not be reached.', 'worldgraph' ) );
		}

		return [
			'status'     => (int) wp_remote_retrieve_response_code( $response ),
			'body'       => (string) wp_remote_retrieve_body( $response ),
			'session_id' => (string) wp_remote_retrieve_header( $response, 'mcp-session-id' ),
		];
	}

	/** Convert one HTTP/JSON-RPC failure into a bounded provider error. */
	private static function response_error( $message, int $status ): WP_Error {
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		if ( is_array( $message ) && isset( $message['error'] ) ) {
			return self::jsonrpc_error( $message['error'], $status );
		}

		return new WP_Error(
			'midjourney_mcp_request_failed',
			sprintf(
				/* translators: %d: provider HTTP status code. */
				__( 'Midjourney MCP returned HTTP %d.', 'worldgraph' ),
				$status
			),
			[ 'status' => $status ]
		);
	}

	/** Normalize a JSON-RPC error without retaining arbitrary provider data. */
	private static function jsonrpc_error( $error, int $status = 0 ): WP_Error {
		unset( $error );

		return new WP_Error( 'midjourney_mcp_error', __( 'Midjourney MCP returned a JSON-RPC error.', 'worldgraph' ), [ 'status' => $status ] );
	}

	/** Merge and sanitize one tools/list page. */
	private static function merge_tools( array $catalog, $tools ) {
		if ( ! is_array( $tools ) ) {
			return new WP_Error( 'midjourney_mcp_catalog_invalid', __( 'Midjourney MCP returned an invalid tool catalog.', 'worldgraph' ) );
		}
		foreach ( $tools as $tool ) {
			if ( count( $catalog ) >= self::MAX_TOOLS ) {
				return new WP_Error( 'midjourney_mcp_catalog_limit', __( 'Midjourney MCP returned more tools than this client accepts.', 'worldgraph' ) );
			}
			if ( ! is_array( $tool ) ) {
				continue;
			}
			$name_value = $tool['name'] ?? '';
			if ( ! is_scalar( $name_value ) ) {
				continue;
			}
			$name = trim( sanitize_text_field( (string) $name_value ) );
			if ( '' === $name || strlen( $name ) > 128 || ! preg_match( '#^[A-Za-z0-9_.:/-]+$#', $name ) ) {
				continue;
			}
			$schema    = is_array( $tool['inputSchema'] ?? null ) ? $tool['inputSchema'] : ( is_array( $tool['input_schema'] ?? null ) ? $tool['input_schema'] : [] );
			$remaining = self::MAX_SCHEMA_ITEMS;
			$schema    = self::sanitize_schema( $schema, 0, $remaining );
			$encoded   = wp_json_encode( $schema );
			if ( ! is_array( $schema ) || ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_SCHEMA_BYTES ) {
				continue;
			}
			$description      = is_scalar( $tool['description'] ?? null ) ? (string) $tool['description'] : '';
			$catalog[ $name ] = [
				'name'        => $name,
				'description' => substr( sanitize_textarea_field( $description ), 0, 2000 ),
				'inputSchema' => $schema,
			];
		}

		return $catalog;
	}

	/** Bound remote schema depth, count, keys, and scalar lengths. */
	private static function sanitize_schema( $value, int $depth, int &$remaining ) {
		if ( $remaining <= 0 || $depth > self::MAX_SCHEMA_DEPTH ) {
			return null;
		}
		--$remaining;
		if ( is_array( $value ) ) {
			$sanitized = [];
			foreach ( $value as $key => $item ) {
				if ( $remaining <= 0 ) {
					break;
				}
				$key = is_int( $key ) ? $key : substr( sanitize_text_field( (string) $key ), 0, 200 );
				$sanitized[ $key ] = self::sanitize_schema( $item, $depth + 1, $remaining );
			}
			return $sanitized;
		}
		if ( is_string( $value ) ) {
			return substr( sanitize_textarea_field( $value ), 0, 4000 );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return null;
	}

	/** Resolve and validate one saved Midjourney Connection. */
	private static function connection( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'midjourney' !== ( $connection['provider_type'] ?? '' ) ) {
			return new WP_Error( 'midjourney_mcp_connection_invalid', __( 'Select a Midjourney Connection first.', 'worldgraph' ) );
		}
		if ( isset( $connection['status_wp'] ) && 'publish' !== (string) $connection['status_wp'] ) {
			return new WP_Error( 'midjourney_mcp_connection_unpublished', __( 'The selected Midjourney Connection is not published.', 'worldgraph' ) );
		}

		$status = (string) ( $connection['status'] ?? '' );
		if ( 'disabled' === $status ) {
			return new WP_Error( 'midjourney_mcp_connection_disabled', __( 'The selected Midjourney Connection is disabled.', 'worldgraph' ) );
		}
		if ( '' !== $status && ! in_array( $status, [ 'unverified', 'verified', 'error' ], true ) ) {
			return new WP_Error( 'midjourney_mcp_connection_invalid', __( 'The selected Midjourney Connection has an invalid status.', 'worldgraph' ) );
		}

		return $connection;
	}

	/** Resolve the dedicated MCP endpoint, never the separate REST origin. */
	private static function endpoint( array $connection ): string {
		return trim( (string) ( $connection['mcp_endpoint_url'] ?? '' ) ) ?: self::ENDPOINT;
	}

	/** Resolve the dedicated AceData Cloud MCP credential, never the REST key. */
	private static function credential_reference( array $connection ): string {
		return trim( (string) ( $connection['mcp_credential_reference'] ?? '' ) );
	}

	/** Allow only AceData Cloud's documented hosted HTTPS MCP endpoint. */
	private static function validated_endpoint( string $endpoint ) {
		$endpoint = untrailingslashit( trim( $endpoint ?: self::ENDPOINT ) );
		$parts    = wp_parse_url( $endpoint );
		$path     = is_array( $parts ) ? '/' . ltrim( rtrim( (string) ( $parts['path'] ?? '' ), '/' ), '/' ) : '';
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| 'midjourney.mcp.acedata.cloud' !== strtolower( (string) ( $parts['host'] ?? '' ) )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
			|| '/mcp' !== $path
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return new WP_Error( 'midjourney_mcp_endpoint_invalid', __( 'Use the documented Midjourney MCP endpoint: https://midjourney.mcp.acedata.cloud/mcp.', 'worldgraph' ) );
		}

		return self::ENDPOINT;
	}

	/** Resolve a literal token or strict env:// environment reference. */
	private static function resolve_credential( string $reference ) {
		$reference = trim( $reference );
		if ( str_starts_with( $reference, 'env://' ) ) {
			$name = substr( $reference, 6 );
			if ( ! preg_match( '/^[A-Z_][A-Z0-9_]*$/', $name ) ) {
				return new WP_Error( 'midjourney_mcp_credential_reference_invalid', __( 'Use a valid uppercase env:// variable name for the Midjourney MCP token.', 'worldgraph' ) );
			}
			$value = getenv( $name );
			if ( false === $value || '' === trim( (string) $value ) ) {
				return new WP_Error( 'midjourney_mcp_credential_missing', __( 'The Midjourney MCP environment credential is empty or unavailable.', 'worldgraph' ) );
			}
			$reference = trim( (string) $value );
		}
		if ( '' === $reference ) {
			return new WP_Error( 'midjourney_mcp_credential_missing', __( 'Set an AceData Cloud token or env://ACEDATACLOUD_API_TOKEN reference on this Connection.', 'worldgraph' ) );
		}
		if ( strlen( $reference ) > self::MAX_CREDENTIAL_BYTES || preg_match( '/[\x00-\x20\x7F]/', $reference ) ) {
			return new WP_Error( 'midjourney_mcp_credential_invalid', __( 'The Midjourney MCP credential is malformed.', 'worldgraph' ) );
		}

		return $reference;
	}

	/** Return a strictly typed boolean generation parameter. */
	private static function boolean_parameter( $value, string $name ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return new WP_Error(
				'midjourney_mcp_parameter_invalid',
				sprintf(
					/* translators: %s: provider parameter name. */
					__( 'Midjourney parameter %s must be true or false.', 'worldgraph' ),
					$name
				)
			);
		}
		if ( 1 === $value || '1' === $value || 'true' === strtolower( trim( (string) $value ) ) ) {
			return true;
		}
		if ( 0 === $value || '0' === $value || 'false' === strtolower( trim( (string) $value ) ) ) {
			return false;
		}

		return new WP_Error(
			'midjourney_mcp_parameter_invalid',
			sprintf(
				/* translators: %s: provider parameter name. */
				__( 'Midjourney parameter %s must be true or false.', 'worldgraph' ),
				$name
			)
		);
	}

	/** Return a bounded integer generation timeout. */
	private static function generation_timeout( $value ) {
		if ( is_int( $value ) ) {
			$timeout = $value;
		} elseif ( is_string( $value ) && preg_match( '/^[0-9]+$/', trim( $value ) ) ) {
			$timeout = (int) trim( $value );
		} else {
			return new WP_Error( 'midjourney_mcp_timeout_invalid', __( 'Midjourney timeout must be a whole number of seconds.', 'worldgraph' ) );
		}
		if ( $timeout < self::MIN_GENERATION_TIMEOUT || $timeout > self::MAX_GENERATION_TIMEOUT ) {
			return new WP_Error(
				'midjourney_mcp_timeout_invalid',
				sprintf(
					/* translators: 1: minimum seconds, 2: maximum seconds. */
					__( 'Midjourney timeout must be between %1$d and %2$d seconds.', 'worldgraph' ),
					self::MIN_GENERATION_TIMEOUT,
					self::MAX_GENERATION_TIMEOUT
				)
			);
		}

		return $timeout;
	}

	/** Validate a task identifier before sending it back to the provider. */
	private static function task_id( string $job_id ) {
		$job_id = trim( $job_id );
		if ( '' === $job_id ) {
			return new WP_Error( 'midjourney_mcp_job_id_missing', __( 'The Midjourney generation job has no task ID.', 'worldgraph' ) );
		}
		if ( strlen( $job_id ) > self::MAX_TASK_ID_BYTES || ! preg_match( '/^[A-Za-z0-9._:-]+$/D', $job_id ) ) {
			return new WP_Error( 'midjourney_mcp_job_id_invalid', __( 'The Midjourney generation task ID is invalid.', 'worldgraph' ) );
		}

		return $job_id;
	}

	/** Decode optional JSON embedded in a structuredContent result wrapper. */
	private static function decode_structured_result( array $structured ) {
		$encoded = wp_json_encode( $structured );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_TOOL_RESULT_BYTES ) {
			return new WP_Error( 'midjourney_mcp_tool_result_too_large', __( 'Midjourney MCP returned an oversized tool result.', 'worldgraph' ) );
		}
		if ( is_scalar( $structured['result'] ?? null ) ) {
			$text = trim( (string) $structured['result'] );
			if ( strlen( $text ) > self::MAX_TOOL_RESULT_BYTES ) {
				return new WP_Error( 'midjourney_mcp_tool_result_too_large', __( 'Midjourney MCP returned an oversized tool result.', 'worldgraph' ) );
			}
			$decoded = json_decode( $text, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return $structured;
	}

	/** Return every distinct safe HTTPS image result. */
	private static function image_outputs( array $payload ): array {
		$urls      = [];
		$remaining = 1000;
		self::collect_image_urls( $payload, false, 0, $remaining, $urls );

		return array_map(
			static function ( string $url ): array {
				return [
					'kind' => 'image',
					'url'  => $url,
				];
			},
			array_keys( $urls )
		);
	}

	/** Recursively collect URLs only beneath documented image/result fields. */
	private static function collect_image_urls( $value, bool $image_context, int $depth, int &$remaining, array &$urls ): void {
		if ( $remaining <= 0 || $depth > 8 || count( $urls ) >= self::MAX_OUTPUT_URLS ) {
			return;
		}
		--$remaining;
		if ( is_scalar( $value ) ) {
			if ( $image_context ) {
				$url = trim( (string) $value );
				if ( self::is_safe_https_url( $url ) ) {
					$urls[ $url ] = true;
				}
			}
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}

		$image_keys      = [ 'image_url', 'imageurl', 'raw_image_url', 'rawimageurl' ];
		$image_list_keys = [ 'sub_image_urls', 'subimageurls', 'image_urls', 'imageurls', 'images', 'output_media', 'outputmedia' ];
		$container_keys = [ 'response', 'data', 'result', 'output', 'outputs', 'items', 'media' ];
		foreach ( $value as $key => $item ) {
			$is_list_item = is_int( $key );
			$key          = strtolower( (string) $key );
			$normalized   = preg_replace( '/[^a-z0-9_]/', '', $key );
			if ( in_array( $normalized, $image_keys, true ) || in_array( $normalized, $image_list_keys, true ) ) {
				self::collect_image_urls( $item, true, $depth + 1, $remaining, $urls );
				continue;
			}
			if ( $image_context && in_array( $normalized, [ 'url', 'src' ], true ) ) {
				self::collect_image_urls( $item, true, $depth + 1, $remaining, $urls );
				continue;
			}
			if ( $image_context || in_array( $normalized, $container_keys, true ) || $is_list_item ) {
				self::collect_image_urls( $item, $image_context, $depth + 1, $remaining, $urls );
			}
		}
	}

	/** Require an HTTPS media URL without credentials or a non-default port. */
	private static function is_safe_https_url( string $url ): bool {
		if ( '' === $url || strlen( $url ) > self::MAX_MEDIA_URL_BYTES ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& false !== wp_http_validate_url( $url )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& '' !== trim( (string) ( $parts['host'] ?? '' ) )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] );
	}

	/** Return the first scalar value found beneath known response wrappers. */
	private static function first_scalar( array $data, array $keys, int $depth = 0 ): string {
		if ( $depth > 8 ) {
			return '';
		}
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				return (string) $data[ $key ];
			}
		}
		foreach ( [ 'response', 'data', 'result' ] as $wrapper ) {
			if ( is_array( $data[ $wrapper ] ?? null ) ) {
				$value = self::first_scalar( $data[ $wrapper ], $keys, $depth + 1 );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	/** Validate one optional session header without silently rewriting it. */
	private static function validated_session_header( string $session_id ) {
		$session_id = trim( $session_id );
		if ( '' === $session_id ) {
			return '';
		}
		if ( strlen( $session_id ) > 512 || ! preg_match( '/^[\x21-\x7E]+$/D', $session_id ) ) {
			return new WP_Error( 'midjourney_mcp_session_invalid', __( 'Midjourney MCP returned an invalid session identifier.', 'worldgraph' ) );
		}

		return $session_id;
	}

	/** Client identity sent during initialization. */
	private static function client_info(): array {
		return [
			'name'    => 'World Graph Studio WordPress',
			'version' => defined( 'WORLDGRAPH_VERSION' ) ? WORLDGRAPH_VERSION : '1.0.0',
		];
	}

	/** Whether an array uses zero-based consecutive integer keys. */
	private static function is_list( array $value ): bool {
		return $value === array_values( $value );
	}
}
