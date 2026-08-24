<?php
/**
 * Per-Connection catalog of the ComfyUI templates a provider advertises.
 *
 * Discovery answers "what could this Connection run", which is a different and
 * much larger question than "what has an operator chosen to offer". Entries are
 * cached on the Connection and stay inert until explicitly enabled, so a
 * provider advertising seventy example workflows never becomes seventy Template
 * posts.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template catalog discovery, caching, and curation.
 */
class Comfy_Catalog {

	/**
	 * Connection post meta holding the discovered catalog snapshot.
	 */
	const CATALOG_META = 'comfy_template_catalog';

	/**
	 * Connection post meta holding the operator's enabled allow-list. Kept
	 * separate from the snapshot so a re-sync never discards curation.
	 */
	const ENABLED_META = 'enabled_templates';

	/**
	 * How many published templates per modality are considered during a sync.
	 */
	const REGISTRY_CANDIDATES = 40;

	/**
	 * How many of those have their workflow fetched to resolve readiness.
	 * Each probe is an HTTP round trip, so this bounds how long a sync runs.
	 */
	const REGISTRY_PROBES = 8;

	/**
	 * How many published templates per modality reach the catalog.
	 */
	const REGISTRY_LISTED = 12;

	/**
	 * Discover everything a Connection's provider advertises and cache it.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array|WP_Error Catalog snapshot.
	 */
	public static function sync( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) ) {
			return new WP_Error( 'worldgraph_conn_not_found', __( 'That Connection does not exist.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		if ( 'comfyui' !== ( $connection['provider_type'] ?? '' ) ) {
			return new WP_Error( 'worldgraph_conn_not_comfy', __( 'Template discovery only applies to ComfyUI Connections.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$capability = Comfy_Cloud_MCP::capability_tier( $connection_id );
		$use_mcp    = in_array( $capability['tier'], [ 'a', 'b' ], true );
		$entries    = $use_mcp
			? self::discover_via_mcp( $connection_id, $capability )
			: self::synthesize_local( $connection );

		if ( is_wp_error( $entries ) ) {
			Generation_Log::add( 'error', 'comfy_catalog', $entries->get_error_message(), [ 'tier' => $capability['tier'] ], '', $connection_id );

			return $entries;
		}

		// A configured-but-unresponsive MCP endpoint still yields a local
		// catalog. Record the tier that actually produced these entries, and
		// keep the probe failure as the message so the cause stays visible.
		$message = $capability['message'];
		if ( 'unreachable' === $capability['tier'] ) {
			$message = sprintf(
				/* translators: %s: MCP probe error message. */
				__( 'Built from the local ComfyUI because the configured MCP endpoint did not respond: %s', 'worldgraph' ),
				$capability['message']
			);
		}

		$snapshot = [
			'synced_at' => gmdate( 'Y-m-d H:i:s' ),
			'tier'      => $use_mcp ? $capability['tier'] : 'c',
			'probed'    => $capability['tier'],
			'message'   => $message,
			'entries'   => $entries,
		];

		update_post_meta( $connection_id, self::CATALOG_META, wp_slash( (string) wp_json_encode( $snapshot ) ) );
		Generation_Log::add(
			'info',
			'comfy_catalog',
			sprintf(
				/* translators: %d: number of provider workflows found. */
				__( 'Found %d available provider workflow(s).', 'worldgraph' ),
				count( $entries )
			),
			[ 'tier' => $capability['tier'] ],
			'',
			$connection_id
		);

		return $snapshot;
	}

	/**
	 * The cached catalog for a Connection, with enable state and requirement
	 * status merged onto each entry.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array Snapshot, empty-but-shaped when nothing has been synced.
	 */
	public static function get( int $connection_id ): array {
		$decoded = json_decode( (string) get_post_meta( $connection_id, self::CATALOG_META, true ), true );
		$snapshot = is_array( $decoded ) ? $decoded : [];

		$snapshot += [ 'synced_at' => '', 'tier' => '', 'probed' => '', 'message' => '', 'entries' => [] ];
		$snapshot['entries'] = self::decorate( (array) $snapshot['entries'], $connection_id );
		$snapshot['enabled'] = self::enabled( $connection_id );

		return $snapshot;
	}

	/**
	 * The operator's enabled allow-list for a Connection.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array<int, array>
	 */
	public static function enabled( int $connection_id ): array {
		$decoded = json_decode( (string) worldgraph_get_field_value( $connection_id, self::ENABLED_META ), true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		return array_values( array_filter( $decoded, static function ( $entry ): bool {
			return is_array( $entry ) && ! empty( $entry['id'] );
		} ) );
	}

	/**
	 * Whether an entry is enabled on a Connection.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id      Catalog entry ID.
	 * @return bool
	 */
	public static function is_enabled( int $connection_id, string $entry_id ): bool {
		foreach ( self::enabled( $connection_id ) as $entry ) {
			if ( (string) $entry['id'] === $entry_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add an entry to the allow-list. Enabling downloads nothing; it only marks
	 * the entry as one this Connection should offer.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id      Catalog entry ID.
	 * @return array|WP_Error The stored allow-list entry.
	 */
	public static function enable( int $connection_id, string $entry_id ) {
		$entry = self::find( $connection_id, $entry_id );
		if ( null === $entry ) {
			return new WP_Error( 'worldgraph_catalog_entry_missing', __( 'That workflow is no longer in this Connection\'s available list. Refresh the workflows and try again.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		if ( empty( $entry['modality'] ) ) {
			return new WP_Error( 'worldgraph_catalog_entry_unmappable', __( 'This provider workflow does not map to a Studio generation type, so it cannot be added automatically.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$enabled = self::enabled( $connection_id );
		foreach ( $enabled as $existing ) {
			if ( (string) $existing['id'] === $entry_id ) {
				return $existing;
			}
		}

		$record = [
			'id'          => (string) $entry['id'],
			'modality'    => (string) $entry['modality'],
			'enabled_at'  => gmdate( 'Y-m-d H:i:s' ),
			'template_id' => 0,
		];

		$enabled[] = $record;
		self::store_enabled( $connection_id, $enabled );
		Generation_Log::add(
			'info',
			'comfy_catalog',
			sprintf(
				/* translators: %s: provider workflow ID. */
				__( 'Selected provider workflow "%s" for Studio.', 'worldgraph' ),
				$record['id']
			),
			$record,
			'',
			$connection_id
		);

		return $record;
	}

	/**
	 * Remove an entry from the allow-list.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id      Catalog entry ID.
	 * @return array<int, array> The remaining allow-list.
	 */
	public static function disable( int $connection_id, string $entry_id ): array {
		$remaining = array_values( array_filter( self::enabled( $connection_id ), static function ( array $entry ) use ( $entry_id ): bool {
			return (string) $entry['id'] !== $entry_id;
		} ) );

		self::store_enabled( $connection_id, $remaining );
		Generation_Log::add(
			'info',
			'comfy_catalog',
			sprintf(
				/* translators: %s: provider workflow ID. */
				__( 'Removed provider workflow "%s" from this Connection.', 'worldgraph' ),
				$entry_id
			),
			[],
			'',
			$connection_id
		);

		return $remaining;
	}

	/**
	 * Record the World Graph Studio Template a catalog entry was materialized into.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id      Catalog entry ID.
	 * @param int    $template_id   Template post ID.
	 */
	public static function link_template( int $connection_id, string $entry_id, int $template_id ): void {
		$enabled = self::enabled( $connection_id );
		foreach ( $enabled as $index => $entry ) {
			if ( (string) $entry['id'] === $entry_id ) {
				$enabled[ $index ]['template_id'] = $template_id;
			}
		}

		self::store_enabled( $connection_id, $enabled );
	}

	/**
	 * Look up one entry in a Connection's cached catalog.
	 *
	 * @param int    $connection_id Connection post ID.
	 * @param string $entry_id      Catalog entry ID.
	 * @return array|null
	 */
	public static function find( int $connection_id, string $entry_id ): ?array {
		foreach ( self::get( $connection_id )['entries'] as $entry ) {
			if ( (string) $entry['id'] === $entry_id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Discover through the Comfy MCP template system, one call per modality
	 * task type plus an unfiltered sweep, merged by entry ID. Filtering by task
	 * type is what lets a provider template be mapped onto a World Graph Studio modality
	 * without guessing.
	 *
	 * @param int   $connection_id Connection post ID.
	 * @param array $capability    Capability tier report.
	 * @return array<int, array>|WP_Error
	 */
	private static function discover_via_mcp( int $connection_id, array $capability ) {
		if ( ! in_array( 'list_templates', $capability['tools'], true ) ) {
			return new WP_Error( 'worldgraph_catalog_no_discovery', __( 'This MCP server does not expose list_templates, so its catalog cannot be discovered.', 'worldgraph' ), [ 'status' => 501 ] );
		}

		$task_types = [];
		foreach ( Generation_Modality::all() as $modality ) {
			$task_types[ (string) $modality['task_type'] ] = true;
		}

		$descriptors        = [];
		$filtered_task_types = [];
		$errors             = [];
		foreach ( array_merge( [ '' ], array_keys( $task_types ) ) as $task_type ) {
			$result = Comfy_Cloud_MCP::list_templates( '' !== $task_type ? [ 'task_type' => $task_type ] : [], $connection_id );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result;
				continue;
			}

			foreach ( (array) ( $result['templates'] ?? $result ) as $template ) {
				if ( ! is_array( $template ) ) {
					continue;
				}

				$id = trim( (string) ( $template['id'] ?? $template['template_id'] ?? $template['name'] ?? '' ) );
				if ( '' === $id ) {
					continue;
				}

				// Some providers omit task_type from rows even when the list was
				// filtered by it. Remember that context, but only trust it when the
				// template appears under one filter: an MCP server that ignores
				// filters would otherwise assign every template an arbitrary type.
				if ( '' !== $task_type && '' === trim( (string) ( $template['task_type'] ?? '' ) ) ) {
					$filtered_task_types[ $id ][ $task_type ] = true;
				}

				$descriptors[ $id ] = isset( $descriptors[ $id ] )
					? self::merge_template_descriptors( $descriptors[ $id ], $template )
					: $template;
			}
		}

		$entries = [];
		foreach ( $descriptors as $id => $template ) {
			$candidates = array_keys( $filtered_task_types[ $id ] ?? [] );
			if ( '' === trim( (string) ( $template['task_type'] ?? '' ) ) && 1 === count( $candidates ) ) {
				$template['task_type'] = $candidates[0];
			}

			$entry = Comfy_Manifest::normalize_entry( $template, $connection_id );
			if ( null !== $entry ) {
				$entries[ $entry['id'] ] = $entry;
			}
		}

		if ( empty( $entries ) && ! empty( $errors ) ) {
			return $errors[0];
		}

		return array_values( $entries );
	}

	/**
	 * Merge duplicate list rows without letting an omitted or empty value erase
	 * metadata already advertised by another discovery call.
	 *
	 * @param array $base     Existing provider descriptor.
	 * @param array $incoming Duplicate descriptor with additional metadata.
	 * @return array
	 */
	private static function merge_template_descriptors( array $base, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			$is_missing = null === $value
				|| ( is_string( $value ) && '' === trim( $value ) )
				|| ( is_array( $value ) && empty( $value ) );
			if ( ! $is_missing || ! array_key_exists( $key, $base ) ) {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Build a catalog for a ComfyUI with no MCP template system, from the
	 * built-in modalities plus what the instance actually reports installed.
	 * This is the honest local answer: these are the shapes World Graph Studio can run,
	 * and here is which ones your ComfyUI is ready for.
	 *
	 * @param array $connection Resolved Connection record.
	 * @return array<int, array>|WP_Error
	 */
	private static function synthesize_local( array $connection ) {
		$endpoint = untrailingslashit( esc_url_raw( (string) ( $connection['endpoint_url'] ?? '' ) ) );
		$catalog  = '' !== $endpoint ? Comfy_Manifest::object_info( $endpoint ) : [];
		$installed = is_wp_error( $catalog ) ? [] : array_keys( (array) $catalog );

		$entries = [];
		foreach ( Generation_Modality::all() as $slug => $modality ) {
			$nodes = Generation_Modality::required_nodes( $slug );
			$missing = $installed ? array_values( array_diff( $nodes, $installed ) ) : [];

			$entries[] = [
				'id'             => 'builtin:' . $slug,
				'name'           => (string) $modality['label'],
				'source'         => 'builtin',
				'model_type'     => '',
				'task_type'      => (string) $modality['task_type'],
				'modality'       => $slug,
				'model_family'   => Model_Family::for_nodes( $nodes ),
				'required_nodes' => $nodes,
				'models'         => [],
				'model_urls'     => [],
				'parameters'     => Generation_Modality::default_settings( $slug ),
				'workflow_hash'  => '',
				'missing_nodes'  => $missing,
				'installable'    => $installed ? empty( $missing ) : null,
			];
		}

		return array_merge( $entries, self::registry_entries( $endpoint ) );
	}

	/**
	 * The workflows ComfyUI publishes, ranked against this instance.
	 *
	 * The built-in graphs above describe what World Graph Studio can assemble
	 * unaided, which is deliberately conservative. The published registry is
	 * where the current generation of models actually lives, so an operator who
	 * has installed a current image or video family should be offered its
	 * published workflows rather than left on a legacy checkpoint graph.
	 *
	 * @param string $endpoint ComfyUI base URL.
	 * @return array<int, array>
	 */
	private static function registry_entries( string $endpoint ): array {
		if ( '' === $endpoint ) {
			return [];
		}

		$modalities = [
			Generation_Modality::TEXT_TO_IMAGE,
			Generation_Modality::IMAGE_TO_IMAGE,
			Generation_Modality::TEXT_TO_VIDEO,
			Generation_Modality::TEXT_IMAGE_TO_VIDEO,
			Generation_Modality::VIDEO_TO_VIDEO,
		];

		$entries = [];
		foreach ( $modalities as $modality ) {
			$ranked = Comfy_Template_Registry::ranked(
				[ 'modality' => $modality, 'local_only' => true, 'limit' => self::REGISTRY_CANDIDATES ],
				$endpoint,
				self::REGISTRY_PROBES
			);
			if ( is_wp_error( $ranked ) ) {
				Generation_Log::add( 'debug', 'comfy_catalog', 'Registry discovery skipped: ' . $ranked->get_error_message(), [ 'modality' => $modality ] );
				continue;
			}

			foreach ( array_slice( $ranked, 0, self::REGISTRY_LISTED ) as $ranked_entry ) {
				$entry = Comfy_Template_Registry::catalog_entry( $ranked_entry );

				// Readiness is only present for the candidates that were probed;
				// null means unknown rather than unavailable.
				$entry['models']        = (array) ( $ranked_entry['models_required'] ?? $entry['models'] );
				$entry['missing_nodes'] = (array) ( $ranked_entry['missing_nodes'] ?? [] );
				$entry['missing_models'] = (array) ( $ranked_entry['missing'] ?? [] );
				$entry['installable']   = isset( $ranked_entry['ready'] ) ? (bool) $ranked_entry['ready'] : null;

				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Merge enable state and a coarse readiness status onto catalog entries.
	 *
	 * @param array $entries       Stored catalog entries.
	 * @param int   $connection_id Connection post ID.
	 * @return array<int, array>
	 */
	private static function decorate( array $entries, int $connection_id ): array {
		$enabled = [];
		foreach ( self::enabled( $connection_id ) as $entry ) {
			$enabled[ (string) $entry['id'] ] = $entry;
		}

		// A reachable ComfyUI answers "is this model actually installed" directly,
		// which is more trustworthy than inferring it from a missing download URL.
		$connection = Connection_Repository::get( $connection_id );
		$endpoint   = is_array( $connection ) ? untrailingslashit( esc_url_raw( (string) ( $connection['endpoint_url'] ?? '' ) ) ) : '';
		$installed  = '' !== $endpoint ? Comfy_Manifest::object_info( $endpoint ) : null;
		if ( is_wp_error( $installed ) ) {
			$installed = null;
		}

		$decorated = [];
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}

			$id = (string) $entry['id'];
			$entry['enabled']     = isset( $enabled[ $id ] );
			$entry['template_id'] = (int) ( $enabled[ $id ]['template_id'] ?? 0 );
			$entry['status']      = self::entry_status( $entry, $installed );
			unset( $enabled[ $id ] );
			$decorated[] = $entry;
		}

		// Anything still enabled but no longer advertised has been withdrawn by
		// the provider. Surface it rather than letting a Template silently rot.
		foreach ( $enabled as $id => $entry ) {
			$decorated[] = [
				'id'          => (string) $id,
				'name'        => (string) $id,
				'source'      => 'withdrawn',
				'modality'    => (string) ( $entry['modality'] ?? '' ),
				'enabled'     => true,
				'template_id' => (int) ( $entry['template_id'] ?? 0 ),
				'status'      => 'withdrawn',
			];
		}

		return $decorated;
	}

	/**
	 * Coarse readiness for catalog rendering. Authoritative validation happens
	 * against a materialized Template via {@see Comfy_Manifest::validate()}.
	 *
	 * @param array      $entry     Catalog entry.
	 * @param array|null $installed Live ComfyUI `/object_info` catalog, or null when unreachable.
	 * @return string
	 */
	private static function entry_status( array $entry, ?array $installed = null ): string {
		if ( empty( $entry['modality'] ) ) {
			return 'unmappable';
		}
		if ( ! empty( $entry['missing_nodes'] ) ) {
			return 'needs_nodes';
		}
		if ( 'registry' === ( $entry['source'] ?? '' ) ) {
			if ( ! isset( $entry['installable'] ) || null === $entry['installable'] ) {
				return 'unverified';
			}

			return $entry['installable'] ? 'ready' : 'needs_models';
		}
		if ( ! empty( $entry['models'] ) ) {
			// A reachable ComfyUI is authoritative: check what it actually has on
			// disk instead of assuming missing just because no download URL was advertised.
			if ( null !== $installed ) {
				return empty( Comfy_Manifest::unresolved_models( $entry['models'], $installed ) ) ? 'ready' : 'needs_models';
			}
			if ( empty( $entry['model_urls'] ) ) {
				return 'needs_models';
			}
		}

		return 'ready';
	}

	/**
	 * Persist the allow-list.
	 *
	 * @param int   $connection_id Connection post ID.
	 * @param array $enabled       Allow-list entries.
	 */
	private static function store_enabled( int $connection_id, array $enabled ): void {
		worldgraph_update_field_value( $connection_id, self::ENABLED_META, (string) wp_json_encode( array_values( $enabled ) ) );
	}
}
