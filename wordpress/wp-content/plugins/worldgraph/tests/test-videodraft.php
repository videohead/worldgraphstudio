<?php
/**
 * VideoDraft connection and sync contract tests.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\VideoDraft_API;
use WorldGraph\Utils\VideoDraft_Catalog;
use WorldGraphVideoDraft\Mapper;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free test shim.
		$value = strtolower( trim( strip_tags( (string) $value ) ) );
		return trim( preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free test shim.
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free test shim.
		return strip_tags( (string) $value );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ): string {
		return (string) json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ): string {
		return filter_var( (string) $value, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ): string {
		return rtrim( (string) $value, '/\\' );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/videodraft-api.php';
require_once dirname( __DIR__ ) . '/includes/utils/videodraft-catalog.php';
require_once dirname( __DIR__ ) . '/plugins/videodraft/includes/class-videodraft-mapper.php';

/** VideoDraft integration contracts. */
class Test_VideoDraft extends TestCase {

	/** The PHP adapter follows the CLI's hosted JSON-RPC boundary. */
	public function test_api_endpoint_and_required_tools(): void {
		$this->assertSame( 'https://app.videodraft.ai/api/mcp', VideoDraft_API::ENDPOINT );
		$this->assertContains( 'generate_image', VideoDraft_API::REQUIRED_TOOLS );
		$this->assertContains( 'generate_video', VideoDraft_API::REQUIRED_TOOLS );
		$this->assertContains( 'check_generation_status', VideoDraft_API::REQUIRED_TOOLS );
		$this->assertContains( 'get_project_schema', VideoDraft_API::REQUIRED_TOOLS );
		$this->assertContains( 'create_project_checkpoint', VideoDraft_API::REQUIRED_TOOLS );
		$this->assertContains( 'update_project', VideoDraft_API::REQUIRED_TOOLS );
	}

	/** Base application URLs and full MCP URLs normalize to the same endpoint. */
	public function test_endpoint_normalization(): void {
		$method = new ReflectionMethod( VideoDraft_API::class, 'normalize_endpoint' );
		$method->setAccessible( true );
		$this->assertSame( VideoDraft_API::ENDPOINT, $method->invoke( null, 'https://app.videodraft.ai' ) );
		$this->assertSame( VideoDraft_API::ENDPOINT, $method->invoke( null, VideoDraft_API::ENDPOINT . '/' ) );
	}

	/** Literal and env:// PAT references resolve without persistence changes. */
	public function test_credential_reference_resolution(): void {
		$method = new ReflectionMethod( VideoDraft_API::class, 'resolve_credential' );
		$method->setAccessible( true );
		putenv( 'VIDEODRAFT_TEST_KEY=vd_mcp_test' );
		$this->assertSame( 'vd_mcp_literal', $method->invoke( null, 'vd_mcp_literal' ) );
		$this->assertSame( 'vd_mcp_test', $method->invoke( null, 'env://VIDEODRAFT_TEST_KEY' ) );
		$this->assertSame( '', $method->invoke( null, 'env://bad-name' ) );
		putenv( 'VIDEODRAFT_TEST_KEY' );
	}

	/** Provider queue states and all documented URL keys are normalized. */
	public function test_status_and_output_url_normalization(): void {
		$status = new ReflectionMethod( VideoDraft_API::class, 'normalize_status' );
		$status->setAccessible( true );
		$this->assertSame( 'submitted', $status->invoke( null, 'IN_PROGRESS' ) );
		$this->assertSame( 'completed', $status->invoke( null, 'finished' ) );
		$this->assertSame( 'failed', $status->invoke( null, 'error' ) );

		$urls = new ReflectionMethod( VideoDraft_API::class, 'output_urls' );
		$urls->setAccessible( true );
		$this->assertSame(
			[ 'https://cdn.example/image', 'https://cdn.example/video' ],
			$urls->invoke( null, [
				'outputUrls' => [ 'https://cdn.example/image', 'https://cdn.example/video' ],
				'nested'     => [ 'speech_url' => 'https://cdn.example/audio' ],
			] )
		);
		$this->assertSame( [ 'https://cdn.example/audio' ], $urls->invoke( null, [ 'speech_url' => 'https://cdn.example/audio' ] ) );
	}

	/** Seed Audio retries match the CLI's transport and reconciliation signals. */
	public function test_seed_audio_retry_contract(): void {
		$this->assertTrue( VideoDraft_API::is_retryable_audio_error( new WP_Error( 'videodraft_rpc_error', 'Transient RPC failure.', [ 'rpc_code' => 0 ] ) ) );
		$this->assertTrue( VideoDraft_API::is_retryable_audio_error( new WP_Error( 'videodraft_invalid_response', 'Non-JSON response.' ) ) );
		$this->assertTrue( VideoDraft_API::is_retryable_audio_error( new WP_Error( 'videodraft_request_failed', 'Gateway timeout.', [ 'status' => 504 ] ) ) );
		$this->assertFalse( VideoDraft_API::is_retryable_audio_error( new WP_Error( 'videodraft_tool_error', 'Permanent validation error.' ) ) );
	}

	/** Live schemas provision one provider-neutral Template per supported tool. */
	public function test_catalog_modality_mapping_and_defaults(): void {
		$definitions = new ReflectionMethod( VideoDraft_Catalog::class, 'template_definitions' );
		$definitions->setAccessible( true );
		$mapped = $definitions->invoke( null );
		$this->assertSame( 'text_to_image', $mapped['generate_image']['modality'] );
		$this->assertSame( 'text_to_video', $mapped['generate_video']['modality'] );
		$this->assertSame( 'text_to_speech', $mapped['generate_voiceover']['modality'] );

		$defaults = new ReflectionMethod( VideoDraft_Catalog::class, 'schema_defaults' );
		$defaults->setAccessible( true );
		$this->assertSame( [ 'aspect_ratio' => '16:9' ], $defaults->invoke( null, [
			'properties' => [
				'prompt'       => [ 'type' => 'string' ],
				'project_id'   => [ 'type' => 'string', 'default' => 'unsafe-default' ],
				'aspect_ratio' => [ 'type' => 'string', 'default' => '16:9' ],
			],
		] ) );
	}

	/** Remote project blobs become valid, stable World Graph Studio structures. */
	public function test_remote_project_mapping_preserves_structure(): void {
		$remote = [
			'project' => [
				'id'    => 'remote-1',
				'title' => 'Signal',
				'visual_assets' => [
					[ 'id' => 'char-1', 'type' => 'character', 'name' => 'Mara' ],
					[ 'id' => 'loc-1', 'type' => 'location', 'name' => 'Roof' ],
					[ 'id' => 'prop-1', 'type' => 'prop', 'name' => 'Radio' ],
				],
				'storyboard' => [ 'scenes' => [ [
					'id' => 'scene-1', 'title' => 'The call', 'location_id' => 'loc-1',
					'characters' => [ 'char-1' ], 'props' => [ 'prop-1' ],
					'shots' => [ [ 'id' => 'shot-1', 'title' => 'Wide', 'prompt' => 'Night roof', 'image_url' => 'https://cdn.example/shot.png' ] ],
				] ] ],
			],
		];

		$document = Mapper::from_videodraft( $remote, 'remote-1' );
		$this->assertSame( 'Signal', $document['project']['title'] );
		$this->assertCount( 1, $document['characters'] );
		$this->assertCount( 1, $document['locations'] );
		$this->assertCount( 1, $document['props'] );
		$this->assertCount( 1, $document['scenes'] );
		$this->assertCount( 1, $document['shots'] );
		$this->assertCount( 1, $document['storyboards'] );
		$this->assertSame( $document['locations'][0]['id'], $document['scenes'][0]['location'] );
		$this->assertSame( $document['characters'][0]['id'], $document['scenes'][0]['characters'][0] );
		$this->assertSame( $document['scenes'][0]['id'], $document['shots'][0]['scene'] );
		$this->assertSame( $document['scenes'][0]['id'], $document['sequence']['order'][0] );
	}

	/** Missing remote Scene values become explicit clears for overwrite imports. */
	public function test_remote_project_mapping_emits_explicit_scene_clears(): void {
		$document = Mapper::from_videodraft( [
			'project' => [
				'id'         => 'remote-1',
				'title'      => 'Signal',
				'storyboard' => [
					'scenes' => [
						[
							'id'    => 'scene-1',
							'title' => 'Empty scene',
							'shots' => [ [ 'id' => 'shot-1', 'title' => 'Untyped', 'image_url' => 'https://cdn.example/provider.png' ] ],
						],
					],
				],
			],
		], 'remote-1' );

		$this->assertSame( '', $document['scenes'][0]['script_content'] );
		$this->assertSame( [], $document['scenes'][0]['dialogue'] );
		$this->assertSame( '', $document['scenes'][0]['location'] );
		$this->assertSame( [], $document['scenes'][0]['characters'] );
		$this->assertSame( [], $document['scenes'][0]['props'] );
		$this->assertSame( '', $document['shots'][0]['type'] );
		$this->assertSame( [], $document['storyboards'] );
	}

	/** Imported IDs are stable within a Connection and isolated across Connections. */
	public function test_remote_project_ids_are_connection_scoped_and_can_restore_local_ids(): void {
		$remote = [ 'project' => [
			'id' => 'remote-project-with-a-very-long-shared-prefix-123',
			'title' => 'Scoped',
			'storyboard' => [ 'scenes' => [ [ 'id' => 'scene-1', 'title' => 'One', 'shots' => [] ] ] ],
		] ];
		$first = Mapper::from_videodraft( $remote, $remote['project']['id'], 'connection-1' );
		$second = Mapper::from_videodraft( $remote, $remote['project']['id'], 'connection-2' );
		$this->assertNotSame( $first['project']['id'], $second['project']['id'] );
		$this->assertNotSame( $first['scenes'][0]['id'], $second['scenes'][0]['id'] );

		$mapped = Mapper::from_videodraft( $remote, $remote['project']['id'], 'connection-1', [
			'project' => [ '*' => 'worldgraph-project-7' ],
			'world'   => [ '*' => 'worldgraph-world-8' ],
			'scene'   => [ 'scene-1' => 'worldgraph-scene-9' ],
		] );
		$this->assertSame( 'worldgraph-project-7', $mapped['project']['id'] );
		$this->assertSame( 'worldgraph-world-8', $mapped['world']['id'] );
		$this->assertSame( 'worldgraph-scene-9', $mapped['scenes'][0]['id'] );
	}

	/** Hashes ignore associative key order while preserving list order. */
	public function test_conflict_hash_is_stable_for_object_key_order(): void {
		$this->assertSame(
			Mapper::hash_payload( [ 'b' => 2, 'a' => [ 'd' => 4, 'c' => 3 ] ] ),
			Mapper::hash_payload( [ 'a' => [ 'c' => 3, 'd' => 4 ], 'b' => 2 ] )
		);
		$this->assertNotSame( Mapper::hash_payload( [ 'items' => [ 1, 2 ] ] ), Mapper::hash_payload( [ 'items' => [ 2, 1 ] ] ) );
	}

	/** Conflict projection ignores provider-owned aggregate script, media, and metadata. */
	public function test_remote_conflict_projection_matches_editable_subset(): void {
		$remote = [ 'project' => [
			'title' => 'Signal',
			'description' => 'A test',
			'script' => 'INT. ROOM',
			'provider_state' => [ 'credits' => 4 ],
			'visual_assets' => [ [ 'id' => 'char-1', 'type' => 'person', 'name' => 'Mara', 'image_url' => 'https://cdn.example/mara.png' ] ],
			'storyboard' => [ 'scenes' => [ [
				'id' => 'scene-1', 'title' => 'One', 'script' => 'Mara enters.',
				'shots' => [ [ 'id' => 'shot-1', 'image_url' => 'https://cdn.example/shot.png' ] ],
			] ] ],
		] ];
		$projection = Mapper::remote_editable_projection( $remote );
		$this->assertArrayNotHasKey( 'script', $projection );
		$this->assertSame( 'character', $projection['visual_assets'][0]['type'] );
		$this->assertArrayNotHasKey( 'image_url', $projection['visual_assets'][0] );
		$this->assertArrayNotHasKey( 'image_url', $projection['storyboard']['scenes'][0]['shots'][0] );
		$this->assertArrayNotHasKey( 'provider_state', $projection );
	}

	/** Array replacement keeps VideoDraft-owned identities, fields, subtypes, and media. */
	public function test_worldgraph_payload_merge_preserves_provider_owned_data(): void {
		$remote = [ 'project' => [
			'id'            => 'remote-1',
			'script'        => 'Provider aggregate script',
			'visual_assets' => [
				[
					'id'             => 'asset-1',
					'type'           => 'style',
					'name'           => 'Old asset',
					'description'    => 'Old description',
					'image_url'      => 'https://cdn.example/asset.png',
					'provider_state' => [ 'seed' => 42 ],
				],
				[ 'id' => 'asset-provider-only', 'type' => 'custom', 'name' => 'Provider only' ],
			],
			'storyboard' => [
				'provider_layout' => 'grid',
				'scenes'          => [
					[
						'id'             => 'scene-1',
						'title'          => 'Old scene',
						'script'         => 'Old Scene script',
						'dialogue'       => [ [ 'speaker' => 'Old', 'text' => 'Old line' ] ],
						'location'       => 'asset-1',
						'provider_state' => [ 'locked' => true ],
						'shots'          => [
							[
								'id'            => 'shot-1',
								'title'         => 'Old shot',
								'image_url'     => 'https://cdn.example/shot.png',
								'preview_media' => [ 'https://cdn.example/preview.png' ],
							],
							[ 'id' => 'shot-provider-only', 'title' => 'Provider only' ],
						],
					],
					[ 'id' => 'scene-provider-only', 'title' => 'Provider only', 'shots' => [] ],
				],
			],
		] ];
		$payload = [
			'title'         => 'Local title',
			'description'   => 'Local description',
			'script'        => 'Must not be sent',
			'visual_assets' => [
				[ 'id' => 'local-asset-1', 'type' => 'object', 'name' => 'Local asset', 'description' => 'Local description' ],
			],
			'storyboard'    => [
				'scenes' => [
					[
						'id'         => 'local-scene-1',
						'title'      => 'Local scene',
						'script'     => '',
						'dialogue'   => [],
						'characters' => [],
						'props'      => [ 'local-asset-1' ],
						'location'   => '',
						'shots'      => [
							[ 'id' => 'local-shot-1', 'title' => 'Local shot', 'description' => 'Changed', 'shot_type' => 'wide' ],
						],
					],
				],
			],
		];
		$identity_map = [
			'prop'  => [ 'asset-1' => 'local-asset-1' ],
			'scene' => [ 'scene-1' => 'local-scene-1' ],
			'shot'  => [ 'shot-1' => 'local-shot-1' ],
		];

		$merged = Mapper::merge_worldgraph_payload( $remote, $payload, 'remote-1', 'connection-1', $identity_map );
		$this->assertArrayNotHasKey( 'script', $merged );
		$this->assertCount( 2, $merged['visual_assets'] );
		$this->assertSame( 'asset-1', $merged['visual_assets'][0]['id'] );
		$this->assertSame( 'style', $merged['visual_assets'][0]['type'] );
		$this->assertSame( 'Local asset', $merged['visual_assets'][0]['name'] );
		$this->assertSame( 'https://cdn.example/asset.png', $merged['visual_assets'][0]['image_url'] );
		$this->assertSame( [ 'seed' => 42 ], $merged['visual_assets'][0]['provider_state'] );
		$this->assertSame( 'grid', $merged['storyboard']['provider_layout'] );
		$this->assertCount( 2, $merged['storyboard']['scenes'] );
		$this->assertSame( 'scene-1', $merged['storyboard']['scenes'][0]['id'] );
		$this->assertSame( 'Local scene', $merged['storyboard']['scenes'][0]['title'] );
		$this->assertSame( '', $merged['storyboard']['scenes'][0]['script'] );
		$this->assertSame( [], $merged['storyboard']['scenes'][0]['dialogue'] );
		$this->assertSame( '', $merged['storyboard']['scenes'][0]['location'] );
		$this->assertSame( [ 'asset-1' ], $merged['storyboard']['scenes'][0]['props'] );
		$this->assertSame( [ 'locked' => true ], $merged['storyboard']['scenes'][0]['provider_state'] );
		$this->assertCount( 2, $merged['storyboard']['scenes'][0]['shots'] );
		$this->assertSame( 'shot-1', $merged['storyboard']['scenes'][0]['shots'][0]['id'] );
		$this->assertSame( 'Local shot', $merged['storyboard']['scenes'][0]['shots'][0]['title'] );
		$this->assertSame( 'https://cdn.example/shot.png', $merged['storyboard']['scenes'][0]['shots'][0]['image_url'] );
		$this->assertSame( [ 'https://cdn.example/preview.png' ], $merged['storyboard']['scenes'][0]['shots'][0]['preview_media'] );
	}

	/** Core generation and plugin surfaces stay wired together. */
	public function test_runtime_and_sync_source_contracts(): void {
		$root = dirname( __DIR__ );
		$registry = file_get_contents( $root . '/includes/utils/connection-adapters.php' );
		$batch = file_get_contents( $root . '/includes/utils/generation-batch.php' );
		$assets = file_get_contents( $root . '/includes/utils/class-asset-generator.php' );
		$api = file_get_contents( $root . '/includes/utils/videodraft-api.php' );
		$bootstrap = file_get_contents( $root . '/worldgraph.php' );
		$mapper = file_get_contents( $root . '/plugins/videodraft/includes/class-videodraft-mapper.php' );
		$sync = file_get_contents( $root . '/plugins/videodraft/includes/class-videodraft-sync.php' );
		$rest = file_get_contents( $root . '/plugins/videodraft/includes/rest-api/class-videodraft-controller.php' );
		$importer = file_get_contents( $root . '/includes/importer/class-worldgraph-importer.php' );

		$this->assertStringContainsString( "'videodraft' => [", $registry );
		$this->assertStringContainsString( 'VideoDraft_API::class', $batch );
		$this->assertStringContainsString( "'videodraft' === \$provider", $assets );
		$this->assertStringContainsString( "'Content-Length: ' . \$size", $api );
		$this->assertStringContainsString( "\$finalized['cdn_url']", $api );
		$this->assertStringContainsString( "'videodraft_audio_format_unsupported'", $api );
		$this->assertStringContainsString( "'videodraft_attachment_type_unsupported'", $api );
		$this->assertStringContainsString( "\$arguments['image_url'] = \$media['image']", $api );
		$this->assertStringContainsString( "\$arguments['image_urls'] = [ \$media['image'] ]", $api );
		$this->assertStringContainsString( "'structured_content'", $api );
		$this->assertStringContainsString( "'rpc_data'", $api );
		$this->assertStringContainsString( "'_worldgraph_videodraft_resolved_request'", $api );
		$this->assertStringContainsString( "'attachment_ids' => array_values( array_unique( \$attachment_ids ) )", $api );
		$this->assertStringContainsString( 'clear_videodraft_submission_cache', $batch );
		$this->assertStringContainsString( 'persist_job_meta', $batch );
		$this->assertStringContainsString( 'wp_slash( $value )', $batch );
		$this->assertStringContainsString( '$remote_id_persisted', $batch );
		$this->assertStringContainsString( '$attachments_persisted', $batch );
		$this->assertStringContainsString( "'worldgraph_gen_cleanup_failed'", $batch );
		$this->assertStringContainsString( "maybe_serialize( \$current )", $batch );
		$this->assertStringContainsString( 'private static function refresh_lock()', $batch );
		$this->assertStringContainsString( 'const CLAIM_TTL = self::LOCK_TTL', $batch );
		$this->assertStringContainsString( "'videodraft' === \$provider || 'videodraft' === \$adapter", $assets );
		$this->assertStringContainsString( '$is_videodraft ? $typed_audio_urls', $assets );
		$this->assertStringContainsString( "plugins/videodraft/videodraft-sync.php", $bootstrap );
		$this->assertStringContainsString( "get_relationships( \$target_id, 'worldgraph_episode', 'outgoing' )", $mapper );
		$this->assertStringContainsString( "'worldgraph_prop' => 'object'", $mapper );
		$this->assertStringContainsString( 'Mapper::merge_worldgraph_payload', $sync );
		$this->assertStringContainsString( "'data'       => (object) \$update_data", $sync );
		$this->assertStringContainsString( "'videodraft_project_empty'", $sync );
		$this->assertStringContainsString( 'set_relationships_for_field', $importer );
		$this->assertStringContainsString( "array_key_exists( 'dialogue', \$scene )", $importer );
		$this->assertStringContainsString( "create_project_checkpoint", $sync );
		$this->assertStringContainsString( "'dry_run' => \$dry_run", $sync );
		$this->assertStringContainsString( "'/videodraft/push'", $rest );
		$this->assertStringContainsString( "'/videodraft/pull'", $rest );
	}
}
