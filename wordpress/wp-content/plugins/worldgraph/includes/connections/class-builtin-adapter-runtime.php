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

	/** Resolve the stable MidJourney transport marker from trusted job data. */
	public static function midjourney_adapter( array $connection = [], string $template = '', string $adapter = '' ): string {
		unset( $connection );

		$adapter = sanitize_key( $adapter );
		if ( in_array( $adapter, [ 'midjourney_api', 'midjourney_mcp' ], true ) ) {
			return $adapter;
		}

		return str_starts_with( trim( $template ), 'mcp:' ) ? 'midjourney_mcp' : 'midjourney_api';
	}

	/**
	 * Select the MidJourney REST or MCP client from the persisted transport.
	 *
	 * @param array<string, mixed> $connection Resolved Connection record.
	 * @param string               $template   Provider Template reference.
	 * @param string               $adapter    Persisted job adapter.
	 * @return class-string
	 */
	public static function midjourney_client( array $connection = [], string $template = '', string $adapter = '' ): string {
		$transport = self::midjourney_adapter( $connection, $template, $adapter );
		if ( 'midjourney_mcp' === $transport ) {
			return \WorldGraph\Utils\Midjourney_MCP::class;
		}

		return \WorldGraph\Utils\Midjourney_API::class;
	}
}
