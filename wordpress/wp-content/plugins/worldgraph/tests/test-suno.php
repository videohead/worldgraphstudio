<?php
/**
 * Suno REST API, MCP, and Template catalog contract tests.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Connection_Adapters;
use WorldGraph\Utils\Connection_Repository;
use WorldGraph\Utils\Generation_Modality;
use WorldGraph\Utils\Suno_API;
use WorldGraph\Utils\Suno_Catalog;
use WorldGraph\Utils\Suno_MCP;

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free test shim.
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ): string {
		return 'worldgraph-phpunit-' . (string) $scheme;
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/connection-adapters.php';
require_once dirname( __DIR__ ) . '/includes/utils/connection_repository.php';
require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/suno-api.php';
require_once dirname( __DIR__ ) . '/includes/utils/suno-mcp.php';
require_once dirname( __DIR__ ) . '/includes/utils/suno-catalog.php';

/** Suno integration tests. */
class Test_Suno extends TestCase {

	/** The adapter manifest keeps REST and MCP origins, credentials, and loaders distinct. */
	public function test_adapter_manifest_exposes_both_suno_transports(): void {
		$adapter = Connection_Adapters::all()['suno'];
		$choice  = Connection_Adapters::setup_choice( 'suno' );

		$this->assertSame( 'https://api.sunoapi.org', Suno_API::ENDPOINT );
		$this->assertSame( 'https://suno.mcp.acedata.cloud/mcp', Suno_MCP::ENDPOINT );
		$this->assertSame( 'https://api.sunoapi.org', Connection_Adapters::endpoint( 'suno' ) );
		$this->assertSame( 'https://suno.mcp.acedata.cloud/mcp', Connection_Adapters::mcp_endpoint( 'suno' ) );
		$this->assertSame(
			[
				'includes/utils/suno-api.php',
				'includes/utils/suno-mcp.php',
				'includes/utils/suno-catalog.php',
			],
			$adapter['files']
		);
		$this->assertSame( [ Suno_Catalog::class, 'init' ], $adapter['init'] );
		$this->assertSame( 'suno', $choice['provider_type'] );
		$this->assertTrue( $choice['mcp_endpoint'] );
		$this->assertTrue( $choice['separate_mcp_credential'] );
	}

	/** Catalog references are executable by their named transport and declare the right modality. */
	public function test_catalog_definitions_have_executable_references_and_modalities(): void {
		$api = Suno_Catalog::definitions( 'api' );
		$mcp = Suno_Catalog::definitions( 'mcp' );

		$this->assertSame(
			[ 'api:generate', 'api:generate-custom', 'api:lyrics' ],
			array_column( $api, 'reference' )
		);
		$this->assertSame(
			[ 'mcp:suno_generate_music', 'mcp:suno_generate_custom_music', 'mcp:suno_generate_lyrics' ],
			array_column( $mcp, 'reference' )
		);
		$this->assertSame(
			[ Generation_Modality::TEXT_TO_MUSIC, Generation_Modality::TEXT_TO_MUSIC, Generation_Modality::TEXT_TO_LYRICS ],
			array_column( $api, 'modality' )
		);
		$this->assertSame(
			[ Generation_Modality::TEXT_TO_MUSIC, Generation_Modality::TEXT_TO_MUSIC, Generation_Modality::TEXT_TO_LYRICS ],
			array_column( $mcp, 'modality' )
		);
		$this->assertSame( [ 'audio', 'audio', 'text' ], array_map( [ Generation_Modality::class, 'output_type' ], array_column( $api, 'modality' ) ) );
		$this->assertSame( [ 'audio', 'audio', 'text' ], array_map( [ Generation_Modality::class, 'output_type' ], array_column( $mcp, 'modality' ) ) );

		$api_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/suno-api.php' );
		foreach ( $api as $definition ) {
			$this->assertStringContainsString( "'{$definition['reference']}'", $api_source );
			$this->assertStringStartsWith( 'POST /api/v1/', $definition['schema']['endpoint'] );
		}
		foreach ( $mcp as $definition ) {
			$this->assertSame( 'mcp:' . $definition['tool'], $definition['reference'] );
			$this->assertContains( $definition['tool'], Suno_MCP::REQUIRED_TOOLS );
		}
	}

	/** The canonical schema and repository persist an independent MCP credential reference. */
	public function test_connection_schema_wires_a_separate_mcp_credential(): void {
		$this->assertContains( 'mcp_credential_reference', Connection_Repository::PUBLIC_FIELDS );
		$this->assertContains(
			'mcp_credential_reference',
			\WorldGraph\Utils\worldgraph_expected_fields_for_cpt( 'worldgraph_conn' )
		);

		$path  = dirname( __DIR__ ) . '/acf-json/group_worldgraph_conn.json';
		$group = json_decode( (string) file_get_contents( $path ), true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), "Invalid SCF JSON in {$path}" );
		$fields = array_column( (array) ( $group['fields'] ?? [] ), null, 'name' );

		$this->assertArrayHasKey( 'credential_reference', $fields );
		$this->assertArrayHasKey( 'mcp_credential_reference', $fields );
		$this->assertSame( 'field_worldgraph_conn_mcp_credential_reference', $fields['mcp_credential_reference']['key'] );

		$repository_source = (string) preg_replace(
			'/\s+/',
			' ',
			(string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/connection_repository.php' )
		);
		$this->assertStringContainsString( "'mcp_credential_reference' => \$record['mcp_credential_reference']", $repository_source );
	}

	/** Suno task states are mapped onto the states consumed by Generation_Batch. */
	public function test_api_status_normalization(): void {
		foreach ( [ 'PENDING', 'TEXT_SUCCESS', 'FIRST_SUCCESS' ] as $status ) {
			$this->assertSame( 'submitted', Suno_API::normalize_status( $status ) );
		}
		$this->assertSame( 'completed', Suno_API::normalize_status( 'SUCCESS' ) );
		$this->assertSame( 'cancelled', Suno_API::normalize_status( 'CANCELED' ) );
		foreach ( [ 'CREATE_TASK_FAILED', 'GENERATE_AUDIO_FAILED', 'GENERATE_LYRICS_FAILED', 'CALLBACK_EXCEPTION', 'SENSITIVE_WORD_ERROR' ] as $status ) {
			$this->assertSame( 'failed', Suno_API::normalize_status( $status ) );
		}
	}

	/** Every final track is exposed through the generic media-import URL contract. */
	public function test_api_normalizes_both_completed_music_tracks(): void {
		$result = Suno_API::normalize_result(
			[
				'code' => 200,
				'data' => [
					'taskId'  => 'rest-task-1',
					'status'  => 'SUCCESS',
					'response' => [
						'sunoData' => [
							[
								'id'             => 'track-a',
								'audioUrl'       => 'https://cdn.example.test/track-a.mp3',
								'streamAudioUrl' => 'https://stream.example.test/track-a',
								'imageUrl'       => 'https://cdn.example.test/track-a.jpg',
							],
							[
								'id'        => 'track-b',
								'audio_url' => 'https://cdn.example.test/track-b.mp3',
								'image_url' => 'https://cdn.example.test/track-b.jpg',
							],
						],
					],
				],
			],
			'api:generate'
		);

		$this->assertSame( 'rest-task-1', $result['job_id'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'api', $result['transport'] );
		$this->assertSame(
			[ 'https://cdn.example.test/track-a.mp3', 'https://cdn.example.test/track-b.mp3' ],
			array_column( $result['items'], 'url' )
		);
		$this->assertSame( array_column( $result['items'], 'url' ), array_column( $result['items'], 'audio_url' ) );
		$this->assertSame(
			[ 'https://cdn.example.test/track-a.jpg', 'https://cdn.example.test/track-b.jpg' ],
			array_column( $result['items'], 'cover_image_url' )
		);
		foreach ( $result['items'] as $item ) {
			$this->assertArrayNotHasKey( 'image_url', $item );
		}
	}

	/** MCP polling is successful only for a complete task with a successful response. */
	public function test_mcp_status_normalization_requires_a_successful_response(): void {
		foreach ( [ 'pending', 'processing', 'in_progress' ] as $status ) {
			$this->assertSame( 'submitted', Suno_MCP::normalize_status( $status ) );
		}
		$this->assertSame( 'completed', Suno_MCP::normalize_status( 'complete' ) );
		$this->assertSame( 'failed', Suno_MCP::normalize_status( 'failed' ) );
		$this->assertSame( 'cancelled', Suno_MCP::normalize_status( 'canceled' ) );

		$pending = Suno_MCP::normalize_result(
			[ 'state' => 'complete', 'response' => [ 'task_id' => 'mcp-task-1' ] ],
			true
		);
		$failed = Suno_MCP::normalize_result(
			[ 'response' => [ 'success' => false, 'task_id' => 'mcp-task-1', 'error' => 'Provider rejected the task.' ] ],
			true
		);

		$this->assertSame( 'submitted', $pending['status'] );
		$this->assertSame( 'failed', $failed['status'] );
		$this->assertSame( 'Provider rejected the task.', $failed['error'] );
	}

	/** MCP completion exposes every provider track through the media-import URL contract. */
	public function test_mcp_normalizes_both_completed_music_tracks(): void {
		$result = Suno_MCP::normalize_result(
			[
				'id'       => 'mcp-poll-record-1',
				'response' => [
					'success' => true,
					'task_id' => 'mcp-task-1',
					'data'    => [
						[
							'id'        => 'track-a',
							'audio_url' => 'https://cdn.example.test/mcp-track-a.mp3',
							'image_url' => 'https://cdn.example.test/mcp-track-a.jpg',
						],
						[
							'id'       => 'track-b',
							'audioUrl' => 'https://cdn.example.test/mcp-track-b.mp3',
							'imageUrl' => 'https://cdn.example.test/mcp-track-b.jpg',
						],
					],
				],
			],
			true
		);

		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'mcp', $result['transport'] );
		$this->assertSame(
			[ 'https://cdn.example.test/mcp-track-a.mp3', 'https://cdn.example.test/mcp-track-b.mp3' ],
			array_column( $result['items'], 'url' )
		);
		$this->assertSame( array_column( $result['items'], 'url' ), array_column( $result['items'], 'audio_url' ) );
		$this->assertSame(
			[ 'https://cdn.example.test/mcp-track-a.jpg', 'https://cdn.example.test/mcp-track-b.jpg' ],
			array_column( $result['items'], 'cover_image_url' )
		);
		foreach ( $result['items'] as $item ) {
			$this->assertArrayNotHasKey( 'image_url', $item );
		}
	}

	/** Saved Suno Connections prefer their dedicated AceData Cloud token and MCP origin. */
	public function test_mcp_connection_resolution_prefers_dedicated_values(): void {
		$credential = new ReflectionMethod( Suno_MCP::class, 'credential_reference' );
		$credential->setAccessible( true );
		$endpoint = new ReflectionMethod( Suno_MCP::class, 'endpoint' );
		$endpoint->setAccessible( true );
		$connection = [
			'credential_reference'     => 'sunoapi-key',
			'mcp_credential_reference' => 'acedata-token',
			'endpoint_url'             => 'https://api.sunoapi.org',
			'mcp_endpoint_url'         => 'https://suno.mcp.acedata.cloud/mcp',
		];

		$this->assertSame( 'acedata-token', $credential->invoke( null, $connection, 0 ) );
		$this->assertSame( 'https://suno.mcp.acedata.cloud/mcp', $endpoint->invoke( null, $connection ) );

		$connection['mcp_credential_reference'] = '';
		$this->assertSame( '', $credential->invoke( null, $connection, 0 ) );
	}

	/** Callback authentication is deterministic, Connection-scoped, and tamper-evident. */
	public function test_callback_hmac_is_connection_scoped(): void {
		$token    = Suno_API::callback_token( 41 );
		$tampered = ( '0' === $token[0] ? '1' : '0' ) . substr( $token, 1 );

		$this->assertSame( 64, strlen( $token ) );
		$this->assertSame( hash_hmac( 'sha256', 'worldgraph:suno:41', wp_salt( 'auth' ) ), $token );
		$this->assertTrue( Suno_API::verify_callback_token( 41, $token ) );
		$this->assertFalse( Suno_API::verify_callback_token( 42, $token ) );
		$this->assertFalse( Suno_API::verify_callback_token( 41, $tampered ) );
		$this->assertFalse( Suno_API::verify_callback_token( 41, '' ) );
	}

	/** The batch submits, polls, and selects the client from the Template transport prefix. */
	public function test_generation_batch_wires_both_suno_clients(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/generation-batch.php' );

		$this->assertMatchesRegularExpression( '/in_array\( \$provider_type, \[[^\]]*\'suno\'[^\]]*\], true \)/', $source );
		$this->assertMatchesRegularExpression( '/in_array\( \$connection\[\'provider_type\'\], \[[^\]]*\'suno\'[^\]]*\], true \)/', $source );
		$this->assertStringContainsString( "[ Fal_MCP::class, Suno_API::class, Suno_MCP::class, VideoDraft_API::class ]", $source );
		$this->assertStringContainsString( "str_starts_with( \$template, 'mcp:' ) ? Suno_MCP::class : Suno_API::class", $source );
		$this->assertStringContainsString( '$client::run_template(', $source );
		$this->assertStringContainsString( '$client::get_job_status(', $source );
	}

	/** Setup sends each service its own endpoint and key, then saves both references. */
	public function test_setup_keeps_suno_endpoints_and_keys_separate(): void {
		$source            = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin/setup-wizard.php' );
		$script            = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/setup-wizard.js' );
		$normalized_source = (string) preg_replace( '/\s+/', ' ', $source );

		$this->assertStringContainsString( "Connection_Adapters::endpoint( 'suno' ), \$api_key", $source );
		$this->assertStringContainsString( "Connection_Adapters::mcp_endpoint( 'suno' ), \$mcp_api_key", $source );
		$this->assertStringContainsString( "'credential_reference' => \$generation_api_key", $normalized_source );
		$this->assertStringContainsString( "'mcp_credential_reference' => ! empty( \$generation_choice['separate_mcp_credential'] ) ? \$generation_mcp_api_key : ''", $normalized_source );
		$this->assertStringContainsString( 'name="worldgraph_gen_credential_reference"', $source );
		$this->assertStringContainsString( 'name="worldgraph_gen_mcp_credential_reference"', $source );
		$this->assertStringContainsString( "'worldgraph_gen_mcp_credential_reference'", $script );
		$this->assertStringContainsString( 'mcp_api_key: generationMcpApiKeyInput.value', $script );
	}
}
