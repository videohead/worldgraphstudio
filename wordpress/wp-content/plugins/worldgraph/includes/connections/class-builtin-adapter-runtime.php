<?php
/**
 * Dynamic generation-client resolution for bundled Connection adapters.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the bundled providers whose client depends on saved runtime data.
 */
final class Builtin_Adapter_Runtime {

	/** Resolve the persisted execution marker for a ComfyUI Connection. */
	public static function comfy_adapter( array $connection = [], string $template = '', string $adapter = '' ): string {
		unset( $template );

		if ( 'local_comfyui' === sanitize_key( $adapter ) || 'local' === (string) ( $connection['environment'] ?? '' ) ) {
			return 'local_comfyui';
		}

		return 'comfy_mcp';
	}

	/**
	 * Select local ComfyUI HTTP execution or Comfy Cloud MCP execution.
	 *
	 * @param array<string, mixed> $connection Resolved Connection record.
	 * @param string               $template   Provider Template reference.
	 * @param string               $adapter    Previously persisted job adapter.
	 * @return class-string
	 */
	public static function comfy_client( array $connection = [], string $template = '', string $adapter = '' ): string {
		unset( $template );

		if ( 'local_comfyui' === sanitize_key( $adapter ) || 'local' === (string) ( $connection['environment'] ?? '' ) ) {
			return \WorldGraph\Utils\Local_ComfyUI::class;
		}

		return \WorldGraph\Utils\Comfy_Cloud_MCP::class;
	}

	/**
	 * Select the Suno REST or MCP client from the Template transport prefix.
	 *
	 * @param array<string, mixed> $connection Resolved Connection record.
	 * @param string               $template   Provider Template reference.
	 * @param string               $adapter    Previously persisted job adapter.
	 * @return class-string
	 */
	public static function suno_client( array $connection = [], string $template = '', string $adapter = '' ): string {
		unset( $connection );

		$adapter = sanitize_key( $adapter );
		if ( 'suno_mcp' === $adapter || 'mcp' === $adapter || str_starts_with( trim( $template ), 'mcp:' ) ) {
			return \WorldGraph\Utils\Suno_MCP::class;
		}

		return \WorldGraph\Utils\Suno_API::class;
	}
}
