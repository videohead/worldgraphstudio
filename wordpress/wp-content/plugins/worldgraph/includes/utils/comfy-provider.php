<?php
/**
 * Resolves which Comfy MCP client a Connection's provider type owns.
 *
 * `comfy_cloud` is the hosted commercial Comfy Cloud service; `comfyui` is a
 * self-hosted local ComfyUI installation with its own optional local MCP
 * server. Discovery code shares one manifest/catalog implementation, but must
 * never call the wrong client for a Connection's provider type.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Comfy_Provider {

	/**
	 * The MCP client class for an already-resolved Connection record.
	 *
	 * @param array<string, mixed> $connection Resolved Connection record.
	 * @return class-string
	 */
	public static function client_for_connection( array $connection ): string {
		return 'comfyui' === ( $connection['provider_type'] ?? '' ) ? Local_Comfy_MCP::class : Comfy_Cloud_MCP::class;
	}

	/**
	 * The MCP client class for a Connection ID.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return class-string
	 */
	public static function client_for( int $connection_id ): string {
		$connection = $connection_id ? Connection_Repository::get( $connection_id ) : null;

		return self::client_for_connection( is_array( $connection ) ? $connection : [] );
	}
}
