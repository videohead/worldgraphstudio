<?php
/**
 * Authenticated Streamable HTTP discovery client for Higgsfield MCP.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;
use WorldGraph\Connections\Connection_OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Higgsfield's OAuth-protected hosted MCP client. */
class Higgsfield_MCP {

	/** Official hosted MCP endpoint. */
	const ENDPOINT = 'https://mcp.higgsfield.ai/mcp';

	/** Current per-request metadata protocol revision. */
	const CURRENT_PROTOCOL_VERSION = '2026-07-28';

	/** Initialization-era revisions this client implements for negotiation. */
	const LEGACY_PROTOCOL_VERSIONS = [ '2025-11-25', '2025-06-18', '2025-03-26' ];

	/**
	 * Higgsfield does not publish stable tool identifiers.
	 *
	 * Readiness therefore requires a non-empty, valid runtime catalog rather
	 * than invented tool names. No discovered tool is exposed for execution by
	 * this adapter until Higgsfield publishes a stable tool contract.
	 */
	const REQUIRED_TOOLS = [];

	/** HTTP timeout in seconds for bounded request-scoped streams. */
	const TIMEOUT = 60;

	/** Maximum response bytes accepted from the MCP endpoint. */
	const MAX_RESPONSE_BYTES = 1048576;

	/** Maximum SSE events decoded from one request. */
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

	/**
	 * Test unsaved endpoint/token values by discovering a non-empty tool set.
	 *
	 * @return array<int, string>|WP_Error
	 */
	public static function test_configuration( string $endpoint, string $credential_reference ) {
		$token = Connection_OAuth::token_from_reference( 'higgsfield', 'mcp', $credential_reference );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$catalog = self::tool_catalog_for( $endpoint, $token );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}
		if ( empty( $catalog ) ) {
			return new WP_Error( 'higgsfield_mcp_catalog_empty', __( 'Higgsfield MCP authenticated successfully but returned no valid tools.', 'worldgraph' ) );
		}

		return array_keys( $catalog );
	}

	/** Return valid tool names advertised for one saved Connection. */
	public static function available_tools( int $connection_id ) {
		$catalog = self::tool_schemas( $connection_id );
		return is_wp_error( $catalog ) ? $catalog : array_keys( $catalog );
	}

	/**
	 * Return a bounded, sanitized runtime tool catalog.
	 *
	 * The catalog is descriptive data only. Higgsfield does not publish stable
	 * tool names or execution result schemas, so this client deliberately does
	 * not expose an arbitrary tools/call method.
	 *
	 * @return array<string, array<string, mixed>>|WP_Error
	 */
	public static function tool_schemas( int $connection_id ) {
		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$token = Connection_OAuth::access_token( $connection_id, 'mcp', 'higgsfield' );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$catalog = self::tool_catalog_for( self::endpoint( $connection ), $token );
		if ( is_wp_error( $catalog ) && 'higgsfield_mcp_unauthorized' === $catalog->get_error_code() ) {
			$token = Connection_OAuth::access_token( $connection_id, 'mcp', 'higgsfield', true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$catalog = self::tool_catalog_for( self::endpoint( $connection ), $token );
		}

		return $catalog;
	}

	/** Discover tools using current MCP first and a bounded legacy fallback. */
	private static function tool_catalog_for( string $endpoint, string $token ) {
		$endpoint = self::validated_endpoint( $endpoint );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}
		if ( '' === trim( $token ) ) {
			return new WP_Error( 'higgsfield_mcp_credential_missing', __( 'Connect Higgsfield MCP with OAuth before checking its tools.', 'worldgraph' ) );
		}

		$modern = self::modern_tool_catalog( $endpoint, $token );
		if ( ! is_wp_error( $modern ) || 'higgsfield_mcp_legacy_required' !== $modern->get_error_code() ) {
			return $modern;
		}

		$legacy = self::legacy_tool_catalog( $endpoint, $token );
		if ( is_wp_error( $legacy ) && 'higgsfield_mcp_session_expired' === $legacy->get_error_code() ) {
			$legacy = self::legacy_tool_catalog( $endpoint, $token );
		}

		return $legacy;
	}

	/** Follow current-era tools/list pagination with per-request metadata. */
	private static function modern_tool_catalog( string $endpoint, string $token ) {
		$catalog = [];
		$cursor  = '';
		$seen    = [];

		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$params = '' === $cursor ? [] : [ 'cursor' => $cursor ];
			$result = self::modern_request( $endpoint, $token, 'tools/list', $params );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$merged = self::merge_tools( $catalog, $result['tools'] ?? [] );
			if ( is_wp_error( $merged ) ) {
				return $merged;
			}
			$catalog = $merged;
			$cursor  = is_scalar( $result['nextCursor'] ?? null ) ? trim( (string) $result['nextCursor'] ) : '';
			if ( '' === $cursor ) {
				return $catalog;
			}
			if ( isset( $seen[ $cursor ] ) ) {
				return new WP_Error( 'higgsfield_mcp_pagination_invalid', __( 'Higgsfield MCP repeated a tools/list cursor.', 'worldgraph' ) );
			}
			$seen[ $cursor ] = true;
		}

		return new WP_Error( 'higgsfield_mcp_pagination_limit', __( 'Higgsfield MCP exceeded the tools/list pagination limit.', 'worldgraph' ) );
	}

	/** Perform the initialization-era lifecycle and close any issued session. */
	private static function legacy_tool_catalog( string $endpoint, string $token ) {
		$initialized = self::legacy_request(
			$endpoint,
			$token,
			'initialize',
			[
				'protocolVersion' => self::LEGACY_PROTOCOL_VERSIONS[0],
				'capabilities'    => new \stdClass(),
				'clientInfo'      => self::client_info(),
			]
		);
		if ( is_wp_error( $initialized ) ) {
			return $initialized;
		}

		$version = is_scalar( $initialized['result']['protocolVersion'] ?? null ) ? (string) $initialized['result']['protocolVersion'] : '';
		$session = (string) ( $initialized['session_id'] ?? '' );
		if ( ! in_array( $version, self::LEGACY_PROTOCOL_VERSIONS, true ) ) {
			self::close_legacy_session( $endpoint, $token, $version, $session );
			return new WP_Error( 'higgsfield_mcp_protocol_unsupported', __( 'Higgsfield MCP negotiated a protocol version this client does not implement.', 'worldgraph' ) );
		}
		$capabilities = $initialized['result']['capabilities'] ?? null;
		if ( ! is_array( $capabilities ) || ! array_key_exists( 'tools', $capabilities ) ) {
			self::close_legacy_session( $endpoint, $token, $version, $session );
			return new WP_Error( 'higgsfield_mcp_tools_unsupported', __( 'Higgsfield MCP did not advertise the tools capability.', 'worldgraph' ) );
		}

		$notification = self::legacy_notification( $endpoint, $token, 'notifications/initialized', [], $version, $session );
		if ( is_wp_error( $notification ) ) {
			self::close_legacy_session( $endpoint, $token, $version, $session );
			return $notification;
		}

		$catalog = [];
		$cursor  = '';
		$seen    = [];
		try {
			for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
				$params = '' === $cursor ? [] : [ 'cursor' => $cursor ];
				$listed = self::legacy_request( $endpoint, $token, 'tools/list', $params, $version, $session );
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
				if ( isset( $seen[ $cursor ] ) ) {
					return new WP_Error( 'higgsfield_mcp_pagination_invalid', __( 'Higgsfield MCP repeated a tools/list cursor.', 'worldgraph' ) );
				}
				$seen[ $cursor ] = true;
			}
		} finally {
			self::close_legacy_session( $endpoint, $token, $version, $session );
		}

		return new WP_Error( 'higgsfield_mcp_pagination_limit', __( 'Higgsfield MCP exceeded the tools/list pagination limit.', 'worldgraph' ) );
	}

	/** Send one current per-request-metadata JSON-RPC call. */
	private static function modern_request( string $endpoint, string $token, string $method, array $params ) {
		$id = wp_generate_uuid4();
		$params['_meta'] = [
			'io.modelcontextprotocol/protocolVersion'    => self::CURRENT_PROTOCOL_VERSION,
			'io.modelcontextprotocol/clientInfo'         => self::client_info(),
			'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
		];
		$headers = [
			'Accept'               => 'application/json, text/event-stream',
			'Authorization'        => 'Bearer ' . $token,
			'Content-Type'         => 'application/json',
			'MCP-Protocol-Version' => self::CURRENT_PROTOCOL_VERSION,
			'Mcp-Method'           => $method,
		];

		$response = self::http_request(
			$endpoint,
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => (object) $params,
			],
			$headers
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status  = (int) $response['status'];
		$body    = (string) $response['body'];
		$message = self::decode_response_for_id( $body, $id );
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'higgsfield_mcp_unauthorized', __( 'Higgsfield MCP OAuth authorization is missing or expired.', 'worldgraph' ), [ 'status' => $status ] );
		}
		if ( 400 === $status ) {
			$modern_error = self::modern_error_response( $body );
			if ( self::should_fallback_to_legacy( $modern_error ) ) {
				return new WP_Error( 'higgsfield_mcp_legacy_required', __( 'Higgsfield MCP requires initialization-era negotiation.', 'worldgraph' ) );
			}

			return self::response_error( $modern_error, $status );
		}
		if ( $status < 200 || $status >= 300 ) {
			return self::response_error( $message, $status );
		}
		if ( ! self::content_type_is_valid( (string) ( $response['content_type'] ?? '' ) ) ) {
			return new WP_Error( 'higgsfield_mcp_content_type_invalid', __( 'Higgsfield MCP returned an unsupported response content type.', 'worldgraph' ) );
		}
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		if ( ! is_array( $message ) ) {
			return new WP_Error( 'higgsfield_mcp_invalid_response', __( 'Higgsfield MCP returned no correlated JSON-RPC response.', 'worldgraph' ) );
		}
		if ( isset( $message['error'] ) ) {
			return self::jsonrpc_error( $message['error'] );
		}

		$result = $message['result'] ?? null;
		if ( ! is_array( $result ) ) {
			return new WP_Error( 'higgsfield_mcp_invalid_response', __( 'Higgsfield MCP returned an invalid result.', 'worldgraph' ) );
		}
		$result_type = is_scalar( $result['resultType'] ?? null ) ? sanitize_key( (string) $result['resultType'] ) : '';
		if ( 'input_required' === $result_type ) {
			return new WP_Error( 'higgsfield_mcp_input_required_unsupported', __( 'Higgsfield MCP requested an interactive round trip that this background client cannot service.', 'worldgraph' ) );
		}
		if ( 'complete' !== $result_type ) {
			return new WP_Error( 'higgsfield_mcp_result_incomplete', __( 'Higgsfield MCP did not return a complete result.', 'worldgraph' ) );
		}
		if (
			'tools/list' === $method
			&& (
				! array_key_exists( 'ttlMs', $result )
				|| ( ! is_int( $result['ttlMs'] ) && ! is_float( $result['ttlMs'] ) )
				|| ! is_finite( (float) $result['ttlMs'] )
				|| (float) $result['ttlMs'] < 0
				|| ! is_string( $result['cacheScope'] ?? null )
				|| ! in_array( $result['cacheScope'], [ 'public', 'private' ], true )
			)
		) {
			return new WP_Error( 'higgsfield_mcp_cache_contract_invalid', __( 'Higgsfield MCP returned invalid tools/list cache metadata.', 'worldgraph' ) );
		}

		unset( $result['resultType'] );
		return $result;
	}

	/** Send one initialization-era JSON-RPC request. */
	private static function legacy_request( string $endpoint, string $token, string $method, array $params, string $version = '', string $session = '' ) {
		$id      = wp_generate_uuid4();
		$headers = self::legacy_headers( $token, $version, $session );
		$response = self::http_request(
			$endpoint,
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => (object) $params,
			],
			$headers
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status  = (int) $response['status'];
		$message = self::decode_response_for_id( (string) $response['body'], $id );
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'higgsfield_mcp_unauthorized', __( 'Higgsfield MCP OAuth authorization is missing or expired.', 'worldgraph' ), [ 'status' => $status ] );
		}
		if ( 404 === $status && '' !== $session ) {
			return new WP_Error( 'higgsfield_mcp_session_expired', __( 'Higgsfield MCP expired the negotiated session.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		if ( $status < 200 || $status >= 300 ) {
			return self::response_error( $message, $status );
		}
		if ( ! self::content_type_is_valid( (string) ( $response['content_type'] ?? '' ) ) ) {
			return new WP_Error( 'higgsfield_mcp_content_type_invalid', __( 'Higgsfield MCP returned an unsupported response content type.', 'worldgraph' ) );
		}
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		if ( ! is_array( $message ) || isset( $message['error'] ) || ! is_array( $message['result'] ?? null ) ) {
			return isset( $message['error'] ) ? self::jsonrpc_error( $message['error'] ) : new WP_Error( 'higgsfield_mcp_invalid_response', __( 'Higgsfield MCP returned an invalid JSON-RPC result.', 'worldgraph' ) );
		}

		return [
			'result'     => $message['result'],
			'session_id' => sanitize_text_field( (string) ( $response['session_id'] ?? '' ) ),
		];
	}

	/** Complete the required legacy initialized notification exchange. */
	private static function legacy_notification( string $endpoint, string $token, string $method, array $params, string $version, string $session ) {
		$response = self::http_request(
			$endpoint,
			[
				'jsonrpc' => '2.0',
				'method'  => $method,
				'params'  => (object) $params,
			],
			self::legacy_headers( $token, $version, $session )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) $response['status'];
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'higgsfield_mcp_unauthorized', __( 'Higgsfield MCP OAuth authorization is missing or expired.', 'worldgraph' ), [ 'status' => $status ] );
		}
		if ( 404 === $status && '' !== $session ) {
			return new WP_Error( 'higgsfield_mcp_session_expired', __( 'Higgsfield MCP expired the negotiated session.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		if ( 202 !== $status || '' !== trim( (string) $response['body'] ) ) {
			return new WP_Error( 'higgsfield_mcp_initialized_rejected', __( 'Higgsfield MCP did not accept the initialized notification.', 'worldgraph' ) );
		}

		return true;
	}

	/** Build headers for an initialization-era request. */
	private static function legacy_headers( string $token, string $version, string $session ): array {
		$headers = [
			'Accept'        => 'application/json, text/event-stream',
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		];
		if ( '' !== $version && '2025-03-26' !== $version ) {
			$headers['MCP-Protocol-Version'] = $version;
		}
		if ( '' !== $session ) {
			$headers['Mcp-Session-Id'] = $session;
		}

		return $headers;
	}

	/** Best-effort termination of an initialization-era session. */
	private static function close_legacy_session( string $endpoint, string $token, string $version, string $session ): void {
		if ( '' === $session ) {
			return;
		}
		wp_safe_remote_request(
			$endpoint,
			[
				'method'              => 'DELETE',
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'headers'             => self::legacy_headers( $token, $version, $session ),
			]
		);
	}

	/** Send one bounded HTTP POST and retain only transport metadata. */
	private static function http_request( string $endpoint, array $payload, array $headers ) {
		$response = wp_safe_remote_request(
			$endpoint,
			[
				'method'              => 'POST',
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => $headers,
				'body'                => wp_json_encode( $payload ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'higgsfield_mcp_unreachable', __( 'Higgsfield MCP could not be reached.', 'worldgraph' ) );
		}

		return [
			'status'       => (int) wp_remote_retrieve_response_code( $response ),
			'body'         => (string) wp_remote_retrieve_body( $response ),
			'session_id'   => (string) wp_remote_retrieve_header( $response, 'mcp-session-id' ),
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		];
	}

	/**
	 * Decode JSON or fully framed SSE and correlate the terminal response by ID.
	 *
	 * @return array<string, mixed>|WP_Error|null
	 */
	public static function decode_response_for_id( string $body, string $request_id ) {
		$messages = self::decode_messages( $body );
		if ( is_wp_error( $messages ) ) {
			return $messages;
		}

		foreach ( $messages as $message ) {
			if (
				! is_array( $message )
				|| '2.0' !== ( $message['jsonrpc'] ?? null )
				|| ! array_key_exists( 'id', $message )
				|| ! is_scalar( $message['id'] )
				|| (string) $message['id'] !== $request_id
			) {
				continue;
			}
			return $message;
		}

		return null;
	}

	/** Decode bounded JSON or fully framed SSE into candidate JSON-RPC messages. */
	private static function decode_messages( string $body ) {
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error( 'higgsfield_mcp_response_too_large', __( 'Higgsfield MCP returned an oversized response.', 'worldgraph' ) );
		}
		$trimmed  = trim( $body );
		$messages = [];
		$decoded  = json_decode( $trimmed, true );
		if ( is_array( $decoded ) ) {
			$messages = self::is_list( $decoded ) ? $decoded : [ $decoded ];
			return count( $messages ) <= self::MAX_EVENTS
				? $messages
				: new WP_Error( 'higgsfield_mcp_response_limit', __( 'Higgsfield MCP returned too many JSON-RPC messages.', 'worldgraph' ) );
		}
		if ( '' === $trimmed ) {
			return [];
		}

		$events = preg_split( '/(?:\r\n|\r|\n){2}/', $body );
		if ( ! is_array( $events ) || count( $events ) > self::MAX_EVENTS ) {
			return new WP_Error( 'higgsfield_mcp_sse_invalid', __( 'Higgsfield MCP returned too many SSE events.', 'worldgraph' ) );
		}
		foreach ( $events as $event ) {
			$data_lines = [];
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $event ) as $line ) {
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

		return $messages;
	}

	/** Extract the first valid current-era JSON-RPC error without requiring an ID. */
	private static function modern_error_response( string $body ) {
		$messages = self::decode_messages( $body );
		if ( is_wp_error( $messages ) ) {
			return $messages;
		}
		foreach ( $messages as $message ) {
			if ( is_array( $message ) && '2.0' === ( $message['jsonrpc'] ?? null ) && is_array( $message['error'] ?? null ) ) {
				return $message;
			}
		}

		return null;
	}

	/** Decide whether a current-era 400 is eligible for legacy negotiation. */
	private static function should_fallback_to_legacy( $message ): bool {
		if ( is_wp_error( $message ) ) {
			return true;
		}
		$error_code = is_array( $message ) && is_int( $message['error']['code'] ?? null )
			? $message['error']['code']
			: null;

		return ! in_array( $error_code, [ -32020, -32021, -32022 ], true );
	}

	/** Accept only the two response media types defined for Streamable HTTP. */
	private static function content_type_is_valid( string $content_type ): bool {
		$content_type = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
		return in_array( $content_type, [ 'application/json', 'text/event-stream' ], true );
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
			'higgsfield_mcp_request_failed',
			sprintf(
				/* translators: %d: provider HTTP status code. */
				__( 'Higgsfield MCP returned HTTP %d.', 'worldgraph' ),
				$status
			),
			[ 'status' => $status ]
		);
	}

	/** Normalize a JSON-RPC error without preserving arbitrary provider data. */
	private static function jsonrpc_error( $error, int $status = 0 ): WP_Error {
		$message = is_array( $error ) && is_scalar( $error['message'] ?? null )
			? substr( sanitize_text_field( (string) $error['message'] ), 0, 500 )
			: __( 'Higgsfield MCP returned a JSON-RPC error.', 'worldgraph' );

		return new WP_Error( 'higgsfield_mcp_error', $message, [ 'status' => $status ] );
	}

	/** Merge and sanitize one tools/list page. */
	private static function merge_tools( array $catalog, $tools ) {
		if ( ! is_array( $tools ) || ! self::is_list( $tools ) ) {
			return new WP_Error( 'higgsfield_mcp_catalog_invalid', __( 'Higgsfield MCP returned an invalid tool catalog.', 'worldgraph' ) );
		}
		foreach ( $tools as $tool ) {
			if ( count( $catalog ) >= self::MAX_TOOLS ) {
				return new WP_Error( 'higgsfield_mcp_catalog_limit', __( 'Higgsfield MCP returned more tools than this client accepts.', 'worldgraph' ) );
			}
			if ( ! is_array( $tool ) ) {
				continue;
			}
			$name = is_string( $tool['name'] ?? null ) ? trim( sanitize_text_field( $tool['name'] ) ) : '';
			if ( '' === $name || strlen( $name ) > 128 || ! preg_match( '#^[A-Za-z0-9_.:/-]+$#', $name ) ) {
				continue;
			}
			$schema = is_array( $tool['inputSchema'] ?? null ) ? $tool['inputSchema'] : null;
			if ( ! is_array( $schema ) || 'object' !== ( $schema['type'] ?? null ) ) {
				continue;
			}
			if ( ! self::header_annotations_are_valid( $schema ) ) {
				continue;
			}
			$remaining = self::MAX_SCHEMA_ITEMS;
			if ( ! self::schema_is_within_limits( $schema, 0, $remaining ) ) {
				continue;
			}
			$remaining = self::MAX_SCHEMA_ITEMS;
			$schema    = self::sanitize_schema( $schema, 0, $remaining );
			$encoded   = wp_json_encode( $schema );
			if ( ! is_array( $schema ) || ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_SCHEMA_BYTES ) {
				continue;
			}
			$catalog[ $name ] = [
				'name'        => $name,
				'description' => is_string( $tool['description'] ?? null ) ? substr( sanitize_textarea_field( $tool['description'] ), 0, 2000 ) : '',
				'inputSchema' => $schema,
			];
		}

		return $catalog;
	}

	/** Validate current x-mcp-header annotations before retaining a tool. */
	private static function header_annotations_are_valid( array $schema ): bool {
		$headers = [];
		return self::validate_schema_annotations( $schema, true, false, $headers );
	}

	/** Recursively validate where and how x-mcp-header appears. */
	private static function validate_schema_annotations( $node, bool $properties_reachable, bool $static_property, array &$headers ): bool {
		if ( ! is_array( $node ) ) {
			return true;
		}
		if ( array_key_exists( 'x-mcp-header', $node ) ) {
			if ( ! is_string( $node['x-mcp-header'] ) ) {
				return false;
			}
			$name = $node['x-mcp-header'];
			$type = $node['type'] ?? '';
			$key  = strtolower( $name );
			if ( ! $static_property || ! is_string( $type ) || ! in_array( $type, [ 'string', 'integer', 'boolean' ], true ) || ! preg_match( '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name ) || isset( $headers[ $key ] ) ) {
				return false;
			}
			$headers[ $key ] = true;
		}

		foreach ( $node as $key => $value ) {
			if ( 'properties' === $key && is_array( $value ) ) {
				foreach ( $value as $property_schema ) {
					if ( ! self::validate_schema_annotations( $property_schema, $properties_reachable, $properties_reachable, $headers ) ) {
						return false;
					}
				}
				continue;
			}
			if ( is_array( $value ) && ! self::validate_schema_annotations( $value, false, false, $headers ) ) {
				return false;
			}
		}

		return true;
	}

	/** Reject, rather than truncate, schemas that exceed discovery budgets. */
	private static function schema_is_within_limits( $value, int $depth, int &$remaining ): bool {
		if ( $remaining <= 0 || $depth > self::MAX_SCHEMA_DEPTH ) {
			return false;
		}
		--$remaining;
		if ( ! is_array( $value ) ) {
			return true;
		}
		foreach ( $value as $item ) {
			if ( ! self::schema_is_within_limits( $item, $depth + 1, $remaining ) ) {
				return false;
			}
		}

		return true;
	}

	/** Bound remote schema depth, count, keys, and scalar lengths. */
	private static function sanitize_schema( $value, int $depth, int &$remaining, string $parent_key = '' ) {
		if ( $remaining <= 0 || $depth > self::MAX_SCHEMA_DEPTH ) {
			return null;
		}
		--$remaining;
		if ( is_array( $value ) ) {
			if ( empty( $value ) && in_array( $parent_key, [ '$defs', 'definitions', 'dependentSchemas', 'patternProperties', 'properties' ], true ) ) {
				return new \stdClass();
			}
			$sanitized = [];
			foreach ( $value as $key => $item ) {
				if ( $remaining <= 0 ) {
					break;
				}
				$key               = is_int( $key ) ? $key : substr( sanitize_text_field( (string) $key ), 0, 200 );
				$sanitized[ $key ] = self::sanitize_schema( $item, $depth + 1, $remaining, is_string( $key ) ? $key : '' );
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

	/** Resolve and validate one saved Higgsfield Connection. */
	private static function connection( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'publish' !== ( $connection['status_wp'] ?? '' ) || 'higgsfield' !== ( $connection['provider_type'] ?? '' ) ) {
			return new WP_Error( 'higgsfield_mcp_connection_invalid', __( 'Select a Higgsfield Connection first.', 'worldgraph' ) );
		}
		if ( 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'higgsfield_mcp_connection_disabled', __( 'The selected Higgsfield Connection is disabled.', 'worldgraph' ) );
		}

		return $connection;
	}

	/** Resolve the dedicated MCP endpoint, never the REST origin. */
	private static function endpoint( array $connection ): string {
		return trim( (string) ( $connection['mcp_endpoint_url'] ?? '' ) ) ?: self::ENDPOINT;
	}

	/** Allow only Higgsfield's documented hosted HTTPS MCP endpoint. */
	private static function validated_endpoint( string $endpoint ) {
		$endpoint = untrailingslashit( trim( $endpoint ?: self::ENDPOINT ) );
		$parts    = wp_parse_url( $endpoint );
		$path     = is_array( $parts ) ? '/' . ltrim( rtrim( (string) ( $parts['path'] ?? '' ), '/' ), '/' ) : '';
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| 'mcp.higgsfield.ai' !== strtolower( (string) ( $parts['host'] ?? '' ) )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
			|| '/mcp' !== $path
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return new WP_Error( 'higgsfield_mcp_endpoint_invalid', __( 'Use the documented Higgsfield MCP endpoint: https://mcp.higgsfield.ai/mcp.', 'worldgraph' ) );
		}

		return self::ENDPOINT;
	}

	/** Client identity sent in current metadata or legacy initialization. */
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
