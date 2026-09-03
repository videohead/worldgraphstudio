<?php
/**
 * Provider Capability Synchronization.
 *
 * Provides the fixed capability descriptor for providers such as ComfyUI, Eleven Labs, etc.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

/**
 * Capability synchronization.
 */
class Capability_Sync {

	/**
	 * Option name for the cached capability snapshot.
	 *
	 * @var string
	 */
	const OPTION = 'worldgraph_provider_capabilities';

	/**
	 * Refresh the local Comfy Cloud MCP capability descriptor.
	 *
	 * @return array Result: [ 'success' => bool, 'message' => string, 'providers' => array ].
	 */
	public static function sync(): array {
		$providers = [ [
			'provider_type' => 'comfy_cloud',
			'label'         => 'Comfy Cloud',
			'endpoint'      => Connection_Adapters::endpoint( 'comfy_cloud' ),
			'capabilities'  => [ 'image', 'video', 'audio', '3d', 'template_execution' ],
		], [
			'provider_type' => 'fal',
			'label'         => 'fal MCP',
			'endpoint'      => Connection_Adapters::endpoint( 'fal' ),
			'capabilities'  => [ 'image', 'video', 'audio', '3d', 'model_discovery', 'queued_execution' ],
		], [
			'provider_type' => 'elevenlabs',
			'label'         => 'ElevenLabs',
			'endpoint'      => Connection_Adapters::endpoint( 'elevenlabs' ),
			'capabilities'  => [ 'audio', 'text_to_speech', 'text_to_dialogue', 'sound_effects', 'music', 'voice_design', 'voice_discovery', 'model_discovery' ],
		], [
			'provider_type' => 'suno',
			'label'         => 'SunoAPI.org + AceData Cloud MCP',
			'endpoint'      => Connection_Adapters::endpoint( 'suno' ),
			'mcp_endpoint'  => Connection_Adapters::mcp_endpoint( 'suno' ),
			'capabilities'  => [ 'audio', 'text', 'text_to_music', 'text_to_lyrics', 'async_generation', 'rest_execution', 'mcp_execution', 'tool_discovery' ],
		], [
			'provider_type' => 'videodraft',
			'label'         => 'VideoDraft',
			'endpoint'      => Connection_Adapters::endpoint( 'videodraft' ),
			'mcp_endpoint'  => Connection_Adapters::mcp_endpoint( 'videodraft' ),
			'capabilities'  => [ 'image', 'video', 'audio', 'async_generation', 'project_import', 'project_export', 'mcp_execution', 'tool_discovery' ],
		] ];

		$snapshot = [
			'synced_at' => gmdate( 'Y-m-d H:i:s' ),
			'providers' => $providers,
		];

		update_option( self::OPTION, $snapshot, false );

		return [
			'success'   => true,
			'message'   => 'Generation MCP capabilities refreshed.',
			'providers' => $providers,
		];
	}

	/**
	 * Get the cached capability snapshot.
	 *
	 * @return array Snapshot with synced_at and providers.
	 */
	public static function get_cached(): array {
		$snapshot = get_option( self::OPTION, [] );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = [];
		}

		return wp_parse_args(
			$snapshot,
			[
				'synced_at' => '',
				'providers' => [],
			]
		);
	}

	/**
	 * List known provider types from the cache (falls back to built-ins).
	 *
	 * @return array<int, string>
	 */
	public static function provider_types(): array {
		$cached = self::get_cached();
		$types = [];
		foreach ( (array) $cached['providers'] as $provider ) {
			if ( is_array( $provider ) && ! empty( $provider['provider_type'] ) ) {
				$types[] = $provider['provider_type'];
			}
		}

		$types = array_merge( $types, Connection_Adapters::provider_types() );

		return array_values( array_unique( $types ) );
	}

	/**
	 * Validate a model ID against the cached capability descriptors.
	 *
	 * @param string $provider_type Provider type slug.
	 * @param string $model_id      Model ID to check.
	 * @return bool True when the model is known to the provider, or when no
	 *              descriptor is cached (fail-open so connections keep working
	 *              offline).
	 */
	public static function model_is_known( string $provider_type, string $model_id ): bool {
		$cached = self::get_cached();
		$found = false;
		foreach ( (array) $cached['providers'] as $provider ) {
			if ( ! is_array( $provider ) || $provider_type !== ( $provider['provider_type'] ?? '' ) ) {
				continue;
			}

			$models = $provider['models'] ?? $provider['model_ids'] ?? [];
			if ( is_array( $models ) && in_array( $model_id, $models, true ) ) {
				$found = true;
				break;
			}
		}

		// Fail-open: no cached descriptor for this provider means we cannot
		// disprove the model, so allow it.
		return $found || empty( $cached['providers'] );
	}

}
