<?php
/**
 * Health checks supplied by the bundled Connection adapters.
 *
 * These methods return unpersisted outcomes. Connection_Test_Service owns the
 * shared status, timestamp, health-report, and notification lifecycle.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/templates/class-template-manager.php';

/**
 * Bundled provider health checks.
 */
final class Builtin_Connection_Tests {

	/** HTTP timeout in seconds for the local ComfyUI health check. */
	private const TIMEOUT = 30;

	/**
	 * Test Comfy Cloud MCP credentials or a local ComfyUI HTTP endpoint.
	 *
	 * @param int                  $connection_id Connection post ID.
	 * @param array<string, mixed> $record        Connection record.
	 * @return array{success:bool,message:string,health:array}
	 */
	public static function test_comfyui( int $connection_id, array $record ): array {
		unset( $connection_id );

		$endpoint = untrailingslashit( (string) ( $record['endpoint_url'] ?? '' ) );
		if ( '' !== $endpoint && \WorldGraph\Utils\Comfy_Cloud_MCP::ENDPOINT !== $endpoint ) {
			return self::test_local_comfyui( $record );
		}

		$has_key = '' !== trim( (string) ( $record['credential_reference'] ?? '' ) );
		return self::outcome(
			$has_key,
			$has_key ? 'Comfy Cloud MCP credentials configured.' : 'Comfy Cloud MCP API key is not configured.'
		);
	}

	/** Test a fal Streamable HTTP MCP connection and required generation tools. */
	public static function test_fal( int $connection_id, array $record ): array {
		unset( $record );

		$tools = \WorldGraph\Utils\Fal_MCP::available_tools( $connection_id );
		if ( is_wp_error( $tools ) ) {
			return self::outcome( false, $tools->get_error_message() );
		}

		$missing = array_values( array_diff( \WorldGraph\Utils\Fal_MCP::GENERATION_TOOLS, $tools ) );
		$success = empty( $missing );
		$message = $success
			? sprintf( 'Connected to fal MCP; %d tools available.', count( $tools ) )
			: sprintf( 'fal MCP is reachable but does not expose required tools: %s.', implode( ', ', $missing ) );
		$health = [ 'tools' => $tools ];
		if ( $success ) {
			$provisioned = \WorldGraph\Templates\Template_Manager::provision_for_connection( $connection_id );
			if ( is_wp_error( $provisioned ) ) {
				$message .= ' Template provisioning needs attention: ' . $provisioned->get_error_message();
				$health['template_provisioning_error'] = $provisioned->get_error_message();
			} else {
				$count = count( (array) ( $provisioned['template_ids'] ?? [] ) );
				$message .= sprintf( ' %d World Graph Studio Template(s) synchronized from fal MCP.', $count );
				$health['template_ids'] = $provisioned['template_ids'] ?? [];
			}
		}

		return self::outcome( $success, $message, $health );
	}

	/** Test ElevenLabs authentication, catalog access, and Template provisioning. */
	public static function test_elevenlabs( int $connection_id, array $record ): array {
		unset( $record );

		$catalog = \WorldGraph\Utils\ElevenLabs_API::catalog( $connection_id );
		if ( is_wp_error( $catalog ) ) {
			return self::outcome( false, $catalog->get_error_message() );
		}
		$model_count = count( (array) ( $catalog['text_to_speech_models'] ?? [] ) );
		$voice_count = count( (array) ( $catalog['voices'] ?? [] ) );
		if ( 0 === $model_count || 0 === $voice_count ) {
			return self::outcome(
				false,
				'ElevenLabs returned no usable text-to-speech models or voices.',
				[ 'model_count' => $model_count, 'voice_count' => $voice_count ]
			);
		}

		$provisioned = \WorldGraph\Templates\Template_Manager::provision_for_connection( $connection_id );
		if ( is_wp_error( $provisioned ) ) {
			return self::outcome( false, $provisioned->get_error_message(), [ 'model_count' => $model_count, 'voice_count' => $voice_count ] );
		}

		$template_count = count( (array) ( $provisioned['template_ids'] ?? [] ) );
		return self::outcome(
			true,
			sprintf( 'Connected to ElevenLabs; %d voice(s), %d text-to-speech model(s), and %d endpoint Template(s) available.', $voice_count, $model_count, $template_count ),
			[ 'model_count' => $model_count, 'voice_count' => $voice_count, 'template_ids' => $provisioned['template_ids'] ?? [] ]
		);
	}

	/** Test both services represented by a combined Suno Connection. */
	public static function test_suno( int $connection_id, array $record ): array {
		unset( $record );

		$credits = \WorldGraph\Utils\Suno_API::credits( $connection_id );
		if ( is_wp_error( $credits ) ) {
			return self::outcome( false, $credits->get_error_message() );
		}

		$tools = \WorldGraph\Utils\Suno_MCP::available_tools( $connection_id );
		if ( is_wp_error( $tools ) ) {
			return self::outcome( false, $tools->get_error_message(), [ 'credits' => $credits ] );
		}

		$missing = array_values( array_diff( \WorldGraph\Utils\Suno_MCP::REQUIRED_TOOLS, $tools ) );
		if ( ! empty( $missing ) ) {
			return self::outcome(
				false,
				sprintf( 'Suno MCP is reachable but does not expose required tools: %s.', implode( ', ', $missing ) ),
				[ 'credits' => $credits, 'tools' => $tools ]
			);
		}

		$provisioned = \WorldGraph\Templates\Template_Manager::provision_for_connection( $connection_id );
		if ( is_wp_error( $provisioned ) ) {
			return self::outcome( false, $provisioned->get_error_message(), [ 'credits' => $credits, 'tools' => $tools ] );
		}

		$template_ids = (array) ( $provisioned['template_ids'] ?? [] );
		return self::outcome(
			true,
			sprintf( 'Connected to SunoAPI.org and AceData Cloud Suno MCP; %d MCP tools and %d transport-specific Templates are available.', count( $tools ), count( $template_ids ) ),
			[ 'credits' => $credits, 'tools' => $tools, 'template_ids' => $template_ids ]
		);
	}

	/** Test Higgsfield REST authentication, MCP OAuth discovery, and Templates. */
	public static function test_higgsfield( int $connection_id, array $record ): array {
		$api = \WorldGraph\Utils\Higgsfield_API::test_configuration(
			(string) ( $record['endpoint_url'] ?? '' ),
			(string) ( $record['credential_reference'] ?? '' )
		);
		if ( is_wp_error( $api ) ) {
			return self::outcome( false, $api->get_error_message() );
		}

		$tools = \WorldGraph\Utils\Higgsfield_MCP::available_tools( $connection_id );
		if ( is_wp_error( $tools ) ) {
			return self::outcome( false, $tools->get_error_message(), [ 'api_authenticated' => true ] );
		}
		if ( empty( $tools ) ) {
			return self::outcome( false, 'Higgsfield MCP returned no usable tools.', [ 'api_authenticated' => true, 'mcp_tool_count' => 0 ] );
		}

		$provisioned = \WorldGraph\Templates\Template_Manager::provision_for_connection( $connection_id );
		if ( is_wp_error( $provisioned ) ) {
			return self::outcome(
				false,
				$provisioned->get_error_message(),
				[ 'api_authenticated' => true, 'mcp_tool_count' => count( $tools ) ]
			);
		}

		$template_ids = (array) ( $provisioned['template_ids'] ?? [] );
		return self::outcome(
			true,
			sprintf( 'Connected to Higgsfield REST and MCP; %d MCP tool(s) discovered and %d reviewed REST Template(s) available.', count( $tools ), count( $template_ids ) ),
			[ 'api_authenticated' => true, 'mcp_tool_count' => count( $tools ), 'template_ids' => $template_ids ]
		);
	}

	/** Test VideoDraft generation, project-sync tools, and Template provisioning. */
	public static function test_videodraft( int $connection_id, array $record ): array {
		unset( $record );

		$tools = \WorldGraph\Utils\VideoDraft_API::available_tools( $connection_id );
		if ( is_wp_error( $tools ) ) {
			return self::outcome( false, $tools->get_error_message() );
		}

		$missing = array_values( array_diff( \WorldGraph\Utils\VideoDraft_API::REQUIRED_TOOLS, $tools ) );
		if ( ! empty( $missing ) ) {
			return self::outcome(
				false,
				sprintf( 'VideoDraft is reachable but does not expose required tools: %s.', implode( ', ', $missing ) ),
				[ 'tools' => $tools, 'missing_tools' => $missing ]
			);
		}

		$provisioned = \WorldGraph\Templates\Template_Manager::provision_for_connection( $connection_id );
		if ( is_wp_error( $provisioned ) ) {
			return self::outcome( false, $provisioned->get_error_message(), [ 'tools' => $tools ] );
		}

		$template_ids = (array) ( $provisioned['template_ids'] ?? [] );
		return self::outcome(
			true,
			sprintf( 'Connected to VideoDraft; %d tools and %d generation Templates are available.', count( $tools ), count( $template_ids ) ),
			[ 'tools' => $tools, 'template_ids' => $template_ids ]
		);
	}

	/** Test a Descript REST connection by listing one project. */
	public static function test_descript( int $connection_id, array $record ): array {
		unset( $record );

		$result = \WorldGraph\Utils\Descript_API::list_projects( $connection_id, [ 'limit' => 1 ] );
		if ( is_wp_error( $result ) ) {
			return self::outcome( false, $result->get_error_message() );
		}

		$count = is_array( $result['projects'] ?? null ) ? count( $result['projects'] ) : 0;
		return self::outcome(
			true,
			sprintf( 'Connected to Descript (%d project(s) visible in this drive).', $count ),
			[ 'projects' => $result['projects'] ?? [] ]
		);
	}

	/** Test OpenRouter authentication and video model discovery. */
	public static function test_openrouter( int $connection_id, array $record ): array {
		unset( $record );

		$models = \WorldGraph\Utils\OpenRouter_API::video_models( $connection_id );
		if ( is_wp_error( $models ) ) {
			return self::outcome( false, $models->get_error_message() );
		}

		return self::outcome(
			true,
			sprintf( 'Connected to OpenRouter; %d video generation model(s) available.', count( $models ) ),
			[ 'model_count' => count( $models ) ]
		);
	}

	/** Test one of the LLM Connection types through the existing AI client. */
	public static function test_llm( int $connection_id, array $record ): array {
		unset( $connection_id );

		$configuration = [
			'backend' => $record['provider_type'],
			'url'     => $record['endpoint_url'],
			'model'   => $record['model'],
			'api_key' => $record['credential_reference'],
		];

		$result = ( new \WorldGraph\AI\AI_LLM_Client() )->test_connection( $configuration );
		$message = $result['healthy']
			? ( ! empty( $result['url'] ) ? sprintf( 'Connected to %s.', $result['url'] ) : 'Provider credentials are configured.' )
			: ( $result['error'] ?? 'Unable to reach the LLM endpoint.' );

		return self::outcome( ! empty( $result['healthy'] ), $message, $result );
	}

	/** Test a local ComfyUI HTTP API connection. */
	private static function test_local_comfyui( array $record ): array {
		$url      = untrailingslashit( (string) ( $record['endpoint_url'] ?? '' ) );
		$response = wp_remote_get( $url . '/system_stats', [ 'timeout' => self::TIMEOUT ] );

		if ( is_wp_error( $response ) ) {
			return self::outcome( false, sprintf( 'Unable to reach ComfyUI: %s', $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return self::outcome( false, sprintf( 'ComfyUI returned HTTP %d from /system_stats.', $code ) );
		}

		return self::outcome( true, sprintf( 'Connected to ComfyUI at %s.', $url ) );
	}

	/** Build the normalized but unpersisted result expected by the test service. */
	private static function outcome( bool $success, string $message, array $health = [] ): array {
		return [
			'success' => $success,
			'message' => $message,
			'health'  => $health,
		];
	}
}
