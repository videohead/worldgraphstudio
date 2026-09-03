<?php
/**
 * Streamable HTTP client for an operator's own local ComfyUI MCP server.
 *
 * A local ComfyUI is reached over its own plain HTTP API for generation
 * (see Local_ComfyUI). This client only speaks to an optional, separately
 * run local MCP process for workflow discovery and model downloads; it is
 * never used to submit or poll a generation job. It has no fixed endpoint
 * and no commercial credential, unlike the hosted Comfy_Cloud_MCP.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Local_Comfy_MCP {
	const PROTOCOL_VERSIONS = [ '2026-07-28', '2025-03-26' ];

	/**
	 * Transient holding the MCP server's advertised tool names.
	 */
	const TOOLS_TRANSIENT = 'worldgraph_local_comfy_mcp_tools';

	/**
	 * Transient holding full MCP tool descriptors (name, input schema, output schema).
	 */
	const TOOL_DEFS_TRANSIENT = 'worldgraph_local_comfy_mcp_tool_defs';

	/**
	 * Tools required for World Graph Studio to discover and provision templates without
	 * operator intervention.
	 *
	 * @var array<int, string>
	 */
	const TEMPLATE_TOOLS = [ 'list_templates', 'get_template', 'download_models' ];

	/**
	 * Whether a local MCP endpoint is configured for this Connection.
	 *
	 * @return bool
	 */
	public static function is_configured( int $connection_id = 0 ): bool {
		return '' !== self::endpoint( $connection_id );
	}

	/**
	 * The tool names this MCP server advertises, cached for an hour so a
	 * capability probe does not cost a round trip per call.
	 *
	 * @param int $connection_id Connection post ID, for log correlation.
	 * @return array<int, string>|WP_Error
	 */
	public static function available_tools( int $connection_id = 0 ) {
		$cached = get_transient( self::TOOLS_TRANSIENT . md5( self::endpoint( $connection_id ) ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = self::available_tool_definitions( $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$tools = [];
		foreach ( (array) $result as $tool ) {
			if ( is_array( $tool ) && ! empty( $tool['name'] ) ) {
				$tools[] = (string) $tool['name'];
			}
		}

		set_transient( self::TOOLS_TRANSIENT . md5( self::endpoint( $connection_id ) ), $tools, HOUR_IN_SECONDS );

		return $tools;
	}

	/**
	 * Whether the MCP server behind a Connection exposes a tool.
	 *
	 * @param string $name Tool name.
	 * @param int    $connection_id Connection post ID.
	 * @return bool
	 */
	public static function supports_tool( string $name, int $connection_id = 0 ): bool {
		$tools = self::available_tools( $connection_id );

		return is_array( $tools ) && in_array( $name, $tools, true );
	}

	/**
	 * Classify what a Connection's local MCP server can actually do, so callers
	 * can offer the right affordances instead of failing deep inside a job.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array{tier: string, tools: array<int, string>, endpoint: string, message: string}
	 */
	public static function capability_tier( int $connection_id = 0 ): array {
		$endpoint = self::endpoint( $connection_id );
		if ( '' === $endpoint ) {
			return [
				'tier'     => 'c',
				'tools'    => [],
				'endpoint' => '',
				'message'  => __( 'No local MCP endpoint is configured, so template discovery falls back to the built-in modalities.', 'worldgraph' ),
			];
		}

		$tools = self::available_tools( $connection_id );
		if ( is_wp_error( $tools ) ) {
			return [
				'tier'     => 'unreachable',
				'tools'    => [],
				'endpoint' => $endpoint,
				'message'  => $tools->get_error_message(),
			];
		}

		$missing = array_values( array_diff( self::TEMPLATE_TOOLS, $tools ) );
		if ( empty( $missing ) ) {
			return [
				'tier'     => 'a',
				'tools'    => $tools,
				'endpoint' => $endpoint,
				'message'  => __( 'This connection exposes the full Comfy MCP template system.', 'worldgraph' ),
			];
		}

		return [
			'tier'     => 'b',
			'tools'    => $tools,
			'endpoint' => $endpoint,
			'message'  => sprintf(
				/* translators: %s: comma-separated list of MCP tool names. */
				__( 'This MCP server does not expose: %s. Some discovery or download steps will need manual work.', 'worldgraph' ),
				implode( ', ', $missing )
			),
		];
	}

	/**
	 * Discover ComfyUI workflow templates the local MCP template system knows about.
	 *
	 * @param array $filters Optional `model_type` / `task_type` filters.
	 * @param int   $connection_id Connection post ID, for log correlation.
	 * @return array|WP_Error
	 */
	public static function list_templates( array $filters = [], int $connection_id = 0 ) {
		$filters = self::normalize_list_template_filters( array_filter( $filters, static function ( $value ) {
			return null !== $value && '' !== $value;
		} ), $connection_id );

		return self::call_discovery_tool( 'list_templates', $filters, $connection_id );
	}

	/**
	 * Load one discovered template, including its workflow graph, required
	 * nodes, and default settings.
	 *
	 * @param string $template_id Template identifier from list_templates().
	 * @param array  $parameters  Optional parameter overrides.
	 * @param int    $connection_id Connection post ID, for log correlation.
	 * @return array|WP_Error
	 */
	public static function get_template( string $template_id, array $parameters = [], int $connection_id = 0 ) {
		$arguments = [
			'parameters' => (object) $parameters,
		];
		$argument_name = self::tool_argument_name( 'get_template', [ 'template_id', 'templateId', 'id', 'name' ], $connection_id );
		$arguments[ $argument_name ?: 'template_id' ] = $template_id;

		return self::call_discovery_tool( 'get_template', $arguments, $connection_id );
	}

	/**
	 * Ask the local MCP server to fetch model files into the ComfyUI workspace.
	 *
	 * @param array $urls Model download URLs.
	 * @param int   $connection_id Connection post ID, for log correlation.
	 * @return array|WP_Error
	 */
	public static function download_models( array $urls, int $connection_id = 0 ) {
		$urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );
		if ( empty( $urls ) ) {
			return new WP_Error( 'comfy_mcp_no_models', 'No model download URLs were supplied.' );
		}

		$argument_name = self::tool_argument_name( 'download_models', [ 'urls', 'model_urls', 'models' ], $connection_id );

		return self::call_discovery_tool( 'download_models', [ ( $argument_name ?: 'urls' ) => $urls ], $connection_id );
	}

	/**
	 * Call a template-system tool, reporting clearly when the connected MCP
	 * server does not implement it rather than failing deep inside a job.
	 *
	 * @param string $name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @param int    $connection_id Connection post ID, for log correlation.
	 * @return array|WP_Error
	 */
	private static function call_discovery_tool( string $name, array $arguments, int $connection_id ) {
		$tools = self::available_tools( $connection_id );
		if ( is_wp_error( $tools ) ) {
			return $tools;
		}
		if ( ! in_array( $name, $tools, true ) ) {
			return new WP_Error(
				'local_comfy_mcp_tool_unavailable',
				sprintf( 'The connected local Comfy MCP server does not expose the "%s" tool.', $name ),
				[ 'available_tools' => $tools ]
			);
		}

		return self::call_tool( $name, $arguments, $connection_id );
	}

	private static function call_tool( string $name, array $arguments, int $connection_id = 0 ) {
		$session = self::initialize( $connection_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$result = self::request( 'tools/call', [
			'name'      => $name,
			'arguments' => (object) $arguments,
		], $session, $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['isError'] ) ) {
			$message = '';
			if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
				foreach ( $result['content'] as $content ) {
					if ( isset( $content['text'] ) ) {
						$message = trim( (string) $content['text'] );
						if ( '' !== $message ) {
							break;
						}
					}
				}
			}

			return new WP_Error(
				'local_comfy_mcp_tool_error',
				'' !== $message ? $message : __( 'The local Comfy MCP server reported a tool error.', 'worldgraph' ),
				is_array( $result ) ? $result : []
			);
		}

		if ( isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ) {
			return $result['structuredContent'];
		}

		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			foreach ( $result['content'] as $content ) {
				if ( isset( $content['text'] ) ) {
					$decoded = json_decode( (string) $content['text'], true );
					if ( is_array( $decoded ) ) {
						return $decoded;
					}
				}
			}
		}

		return is_array( $result ) ? $result : new WP_Error( 'local_comfy_mcp_invalid_response', 'The local Comfy MCP server returned an invalid tool response.' );
	}

	private static function initialize( int $connection_id = 0 ) {
		$last_error = null;
		foreach ( self::PROTOCOL_VERSIONS as $protocol_version ) {
			$result = self::request( 'initialize', [
				'protocolVersion' => $protocol_version,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => [
					'name'    => 'World Graph Studio WordPress',
					'version' => defined( 'WORLDGRAPH_VERSION' ) ? WORLDGRAPH_VERSION : '1.0.0',
				],
			], '', $connection_id );

			if ( is_wp_error( $result ) ) {
				$last_error = $result;
				continue;
			}

			if ( empty( $result['_session_id'] ) ) {
				$last_error = new WP_Error( 'local_comfy_mcp_session_missing', 'The local Comfy MCP server did not establish a session.' );
				continue;
			}

			return $result['_session_id'];
		}

		return $last_error ?: new WP_Error( 'local_comfy_mcp_initialize_failed', 'Local Comfy MCP initialization failed.' );
	}

	private static function request( string $method, array $params, string $session_id = '', int $connection_id = 0 ) {
		if ( ! self::is_configured( $connection_id ) ) {
			return new WP_Error( 'local_comfy_mcp_connection_missing', 'Set a local Comfy MCP URL on the selected Connection before discovering workflows.' );
		}

		$headers = [
			'Accept'       => 'application/json, text/event-stream',
			'Content-Type' => 'application/json',
		];
		if ( '' !== $session_id ) {
			$headers['Mcp-Session-Id'] = $session_id;
		}

		$response = wp_remote_post( self::endpoint( $connection_id ), [
			'timeout' => 60,
			'headers' => $headers,
			'body'    => wp_json_encode( [
				'jsonrpc' => '2.0',
				'id'      => wp_generate_uuid4(),
				'method'  => $method,
				// Cast to object so an empty $params encodes as JSON `{}` rather
				// than `[]` — MCP servers reject an array where a params object
				// (however empty) is required.
				'params'  => (object) $params,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			Generation_Log::add( 'error', 'local_comfy_mcp', 'Unreachable: ' . $response->get_error_message(), [ 'method' => $method ], '', $connection_id );
			return new WP_Error( 'local_comfy_mcp_unreachable', $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$payload = self::decode_response( wp_remote_retrieve_body( $response ) );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $payload ) ? ( $payload['error']['message'] ?? 'Local Comfy MCP request failed.' ) : 'Local Comfy MCP request failed.';
			Generation_Log::add( 'error', 'local_comfy_mcp', sprintf( 'HTTP %d on %s: %s', $status, $method, $message ), [ 'method' => $method, 'params' => $params ], '', $connection_id );
			return new WP_Error( 'local_comfy_mcp_request_failed', $message, [ 'status' => $status ] );
		}
		if ( ! is_array( $payload ) ) {
			Generation_Log::add( 'error', 'local_comfy_mcp', 'Non-JSON response on ' . $method, [ 'method' => $method ], '', $connection_id );
			return new WP_Error( 'local_comfy_mcp_invalid_response', 'Local Comfy MCP returned non-JSON content.' );
		}
		if ( isset( $payload['error'] ) ) {
			Generation_Log::add( 'error', 'local_comfy_mcp', sprintf( 'MCP error on %s: %s', $method, (string) ( $payload['error']['message'] ?? '' ) ), [ 'method' => $method, 'error' => $payload['error'] ], '', $connection_id );
			return new WP_Error( 'local_comfy_mcp_tool_error', (string) ( $payload['error']['message'] ?? 'Local Comfy MCP returned an error.' ), $payload['error'] );
		}

		$result = $payload['result'] ?? $payload;
		$headers = wp_remote_retrieve_headers( $response );
		if ( 'initialize' === $method && isset( $headers['mcp-session-id'] ) ) {
			$result['_session_id'] = (string) $headers['mcp-session-id'];
		}

		return $result;
	}

	/**
	 * Resolve the local MCP endpoint configured on the Connection.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return string
	 */
	private static function endpoint( int $connection_id = 0 ): string {
		$connection = $connection_id ? Connection_Repository::get( $connection_id ) : null;
		$endpoint = is_array( $connection ) ? (string) ( $connection['mcp_endpoint_url'] ?? '' ) : '';

		return untrailingslashit( esc_url_raw( $endpoint ?: (string) get_option( 'worldgraph_comfy_local_mcp_url', '' ) ) );
	}

	private static function decode_response( string $body ) {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		foreach ( preg_split( '/\r?\n/', $body ) as $line ) {
			if ( 0 === strpos( $line, 'data: ' ) ) {
				$decoded = json_decode( substr( $line, 6 ), true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return null;
	}

	/**
	 * Full tools/list payload for this endpoint, cached for one hour.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array<int, array>|WP_Error
	 */
	private static function available_tool_definitions( int $connection_id = 0 ) {
		$key = self::TOOL_DEFS_TRANSIENT . md5( self::endpoint( $connection_id ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$session = self::initialize( $connection_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$result = self::request( 'tools/list', [], $session, $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$tools = array_values( array_filter( (array) ( $result['tools'] ?? [] ), 'is_array' ) );
		set_transient( $key, $tools, HOUR_IN_SECONDS );

		return $tools;
	}

	/**
	 * Pick the first candidate argument name a tool's input schema advertises.
	 *
	 * @param string             $tool_name Tool name.
	 * @param array<int, string> $candidates Candidate argument names in preference order.
	 * @param int                $connection_id Connection post ID.
	 * @return string|null
	 */
	private static function tool_argument_name( string $tool_name, array $candidates, int $connection_id ): ?string {
		$definitions = self::available_tool_definitions( $connection_id );
		if ( is_wp_error( $definitions ) ) {
			return null;
		}

		foreach ( $definitions as $tool ) {
			if ( (string) ( $tool['name'] ?? '' ) !== $tool_name ) {
				continue;
			}

			$properties = $tool['inputSchema']['properties'] ?? [];
			if ( ! is_array( $properties ) ) {
				return null;
			}

			foreach ( $candidates as $candidate ) {
				if ( array_key_exists( $candidate, $properties ) ) {
					return $candidate;
				}
			}

			return null;
		}

		return null;
	}

	/**
	 * Adapt list_templates filter key casing to the provider's declared schema.
	 *
	 * @param array<string, mixed> $filters Requested filters.
	 * @param int                  $connection_id Connection post ID.
	 * @return array<string, mixed>
	 */
	private static function normalize_list_template_filters( array $filters, int $connection_id ): array {
		if ( empty( $filters ) ) {
			return [];
		}

		$definitions = self::available_tool_definitions( $connection_id );
		if ( is_wp_error( $definitions ) ) {
			return $filters;
		}

		$properties = [];
		foreach ( $definitions as $tool ) {
			if ( 'list_templates' !== (string) ( $tool['name'] ?? '' ) ) {
				continue;
			}
			$properties = is_array( $tool['inputSchema']['properties'] ?? null ) ? $tool['inputSchema']['properties'] : [];
			break;
		}

		if ( empty( $properties ) ) {
			return [];
		}

		$mapped = [];
		$aliases = [
			'task_type'  => [ 'task_type', 'taskType' ],
			'model_type' => [ 'model_type', 'modelType' ],
			'search'     => [ 'search', 'query' ],
		];

		foreach ( $aliases as $source => $targets ) {
			if ( ! array_key_exists( $source, $filters ) ) {
				continue;
			}
			foreach ( $targets as $target ) {
				if ( array_key_exists( $target, $properties ) ) {
					$mapped[ $target ] = $filters[ $source ];
					break;
				}
			}
		}

		return empty( $mapped ) ? $filters : $mapped;
	}
}
