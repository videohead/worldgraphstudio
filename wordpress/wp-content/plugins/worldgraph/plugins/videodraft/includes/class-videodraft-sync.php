<?php
/**
 * Bidirectional VideoDraft project synchronization.
 *
 * @package WorldGraphVideoDraft
 */

namespace WorldGraphVideoDraft;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Manual project sync service. */
class Sync {

	/** Per-project mappings, keyed by Connection ID. */
	const MAPPING_META = '_worldgraph_videodraft_mapping';

	/** Prevent recursive sync callbacks in the current request. */
	private static $syncing = false;

	/** Live tool schemas cached for this request. */
	private static $catalogs = [];

	/** Reserved for future opt-in hooks; sync is intentionally manual today. */
	public static function init(): void {}

	/** Push one World Graph Studio project into VideoDraft. */
	public static function push( int $project_id, int $connection_id = 0, string $remote_project_id = '', bool $force = false ) {
		if ( self::$syncing ) {
			return new WP_Error( 'videodraft_sync_reentrant', __( 'A VideoDraft sync is already running.', 'worldgraph' ) );
		}
		$connection_id = self::connection_id( $connection_id );
		$valid = self::validate_project_and_connection( $project_id, $connection_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$data = Mapper::from_worldgraph( $project_id );
		if ( empty( $data ) ) {
			return new WP_Error( 'videodraft_export_empty', __( 'The selected project could not be serialized for VideoDraft.', 'worldgraph' ) );
		}
		if ( empty( $data['storyboard']['scenes'] ) ) {
			return new WP_Error( 'videodraft_project_empty', __( 'This World Graph Studio project has no storyboard scenes to export.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$mapping = self::mapping( $project_id, $connection_id );
		$remote_project_id = sanitize_text_field( $remote_project_id ?: (string) ( $mapping['remote_project_id'] ?? '' ) );
		$replace_mapping = false;
		if ( ! empty( $mapping['remote_project_id'] ) && $remote_project_id !== (string) $mapping['remote_project_id'] ) {
			if ( ! $force ) {
				return new WP_Error( 'videodraft_mapping_mismatch', __( 'This Project is already mapped to a different VideoDraft project. Remove the mapping first, or force an intentional remap.', 'worldgraph' ), [ 'status' => 409 ] );
			}
			$mapping = [];
			$replace_mapping = true;
		}
		if ( ! $force && 'verification_required' === ( $mapping['status'] ?? '' ) ) {
			return new WP_Error( 'videodraft_verification_required', __( 'The previous VideoDraft update could not be verified. Pull a preview or force the next push after checking the remote project.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		$identity_map = Mapper::identity_map_from_worldgraph( $project_id, $data );
		if ( '' !== $remote_project_id ) {
			$mapped_project_id = self::find_mapped_project( $connection_id, $remote_project_id );
			if ( $mapped_project_id && $mapped_project_id !== $project_id ) {
				return new WP_Error( 'videodraft_remote_already_mapped', __( 'That VideoDraft project is already mapped to another local Project. Remove its mapping before transferring ownership.', 'worldgraph' ), [ 'status' => 409, 'project_id' => $mapped_project_id ] );
			}
		}
		$created = false;
		self::$syncing = true;
		try {
			$schema = self::project_schema( $connection_id );
			if ( is_wp_error( $schema ) ) {
				return $schema;
			}

			if ( '' === $remote_project_id ) {
				$created_project = self::create_remote_project( $connection_id, (string) $data['title'], (string) ( $data['description'] ?? '' ) );
				if ( is_wp_error( $created_project ) ) {
					return $created_project;
				}
				$remote_project_id = sanitize_text_field( (string) ( $created_project['project_id'] ?? $created_project['id'] ?? $created_project['project']['id'] ?? '' ) );
				if ( '' === $remote_project_id ) {
					return new WP_Error( 'videodraft_create_contract_invalid', __( 'VideoDraft created a project but did not return its ID.', 'worldgraph' ) );
				}
				$created = true;
			}

			$remote_before = self::get_remote_project( $connection_id, $remote_project_id );
			if ( is_wp_error( $remote_before ) ) {
				return $remote_before;
			}
			$remote_hash = self::remote_hash( $remote_before );
			$local_hash  = Mapper::hash_payload( $data );
			if ( ! $force && ! $created && ! empty( $mapping['remote_hash'] ) && ! hash_equals( (string) $mapping['remote_hash'], $remote_hash ) ) {
				return self::conflict( 'remote', $project_id, $remote_project_id, $connection_id );
			}
			$update_data = Mapper::merge_worldgraph_payload( $remote_before, $data, $remote_project_id, self::scope( $connection_id ), $identity_map );

			if ( ! $created ) {
				$checkpoint = \WorldGraph\Utils\VideoDraft_API::call_tool( 'create_project_checkpoint', [
					'project_id'  => $remote_project_id,
					'name'        => 'Before World Graph Studio push',
					'description' => sprintf( 'Automatic checkpoint before WordPress project %d export.', $project_id ),
				], $connection_id );
				if ( is_wp_error( $checkpoint ) ) {
					return $checkpoint;
				}
			}

			$result = \WorldGraph\Utils\VideoDraft_API::call_tool( 'update_project', [
				'project_id' => $remote_project_id,
				'data'       => (object) $update_data,
			], $connection_id );
			if ( is_wp_error( $result ) ) {
				self::store_error( $project_id, $connection_id, $remote_project_id, $result );
				return $result;
			}

			$remote_after = self::get_remote_project( $connection_id, $remote_project_id );
			$now = gmdate( 'c' );
			if ( is_wp_error( $remote_after ) ) {
				self::set_mapping( $project_id, $connection_id, [
					'remote_project_id' => $remote_project_id,
					'remote_hash'       => '',
					'local_hash'        => $local_hash,
					'identity_map'      => $identity_map,
					'last_pushed_at'    => $now,
					'status'            => 'verification_required',
					'error'             => sanitize_text_field( $remote_after->get_error_message() ),
				], $replace_mapping );
				return new WP_Error( 'videodraft_push_verification_failed', __( 'VideoDraft accepted the update, but World Graph Studio could not verify the saved project. Preview the remote project before retrying.', 'worldgraph' ), [ 'remote_updated' => true, 'remote_project_id' => $remote_project_id ] );
			}
			$remote_after_hash = self::remote_hash( $remote_after );
			self::set_mapping( $project_id, $connection_id, [
				'remote_project_id' => $remote_project_id,
				'remote_hash'       => $remote_after_hash,
				'local_hash'        => $local_hash,
				'identity_map'      => $identity_map,
				'last_pushed_at'    => $now,
				'status'            => 'synced',
				'error'             => '',
			], $replace_mapping );
			do_action( 'worldgraph_videodraft_project_pushed', $project_id, $remote_project_id, $connection_id, $result );

			return [
				'success'           => true,
				'created'           => $created,
				'project_id'        => $project_id,
				'remote_project_id' => $remote_project_id,
				'pushed_at'         => $now,
				'counts'            => self::counts( $data ),
				'result'            => $result,
			];
		} finally {
			self::$syncing = false;
		}
	}

	/** Pull and optionally import one VideoDraft project. */
	public static function pull( string $remote_project_id, int $connection_id = 0, bool $force = false, bool $dry_run = false ) {
		if ( self::$syncing ) {
			return new WP_Error( 'videodraft_sync_reentrant', __( 'A VideoDraft sync is already running.', 'worldgraph' ) );
		}
		if ( ! class_exists( '\\WorldGraph\\Importer\\WorldGraph_Importer' ) ) {
			return new WP_Error( 'videodraft_importer_disabled', __( 'Enable the Story Import & Export plugin before pulling a VideoDraft project.', 'worldgraph' ) );
		}
		$connection_id = self::connection_id( $connection_id );
		$valid = self::validate_connection( $connection_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$remote_project_id = sanitize_text_field( $remote_project_id );
		if ( '' === $remote_project_id ) {
			return new WP_Error( 'videodraft_remote_project_missing', __( 'Enter a VideoDraft project ID to import.', 'worldgraph' ) );
		}

		self::$syncing = true;
		try {
			$remote = self::get_remote_project( $connection_id, $remote_project_id );
			if ( is_wp_error( $remote ) ) {
				return $remote;
			}
			$local_project_id = self::find_mapped_project( $connection_id, $remote_project_id );
			$mapping = $local_project_id ? self::mapping( $local_project_id, $connection_id ) : [];
			$identity_map = is_array( $mapping['identity_map'] ?? null ) ? $mapping['identity_map'] : [];
			if ( $local_project_id && empty( $identity_map ) ) {
				$identity_map = Mapper::identity_map_from_worldgraph( $local_project_id );
			}
			$document = Mapper::from_videodraft( $remote, $remote_project_id, self::scope( $connection_id ), $identity_map );
			if ( empty( $document['scenes'] ) ) {
				return new WP_Error( 'videodraft_project_empty', __( 'This VideoDraft project has no storyboard scenes to import.', 'worldgraph' ) );
			}

			$local_project_id = $local_project_id ?: self::find_by_external_id( 'worldgraph_project', (string) $document['project']['id'] );
			$mapping = $local_project_id ? self::mapping( $local_project_id, $connection_id ) : [];
			if ( ! $dry_run && $local_project_id && ! $force && ! empty( $mapping['local_hash'] ) ) {
				$current = Mapper::from_worldgraph( $local_project_id );
				if ( ! empty( $current ) && ! hash_equals( (string) $mapping['local_hash'], Mapper::hash_payload( $current ) ) ) {
					return self::conflict( 'local', $local_project_id, $remote_project_id, $connection_id );
				}
			}

			$importer = new \WorldGraph\Importer\WorldGraph_Importer();
			$report = $importer->import( (string) wp_json_encode( $document ), [ 'overwrite' => true, 'dry_run' => $dry_run ] );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			if ( $dry_run ) {
				return [
					'success'           => true,
					'dry_run'           => true,
					'remote_project_id' => $remote_project_id,
					'counts'            => self::document_counts( $document ),
					'report'            => $report,
				];
			}
			if ( empty( $report['verified'] ) || ! empty( $report['errors'] ) ) {
				$error = new WP_Error( 'videodraft_import_incomplete', __( 'The VideoDraft import did not pass verification. Review the import report before retrying.', 'worldgraph' ), [ 'report' => $report ] );
				if ( $local_project_id ) {
					self::store_error( $local_project_id, $connection_id, $remote_project_id, $error, 'partial' );
				}
				return $error;
			}

			$local_project_id = self::find_by_external_id( 'worldgraph_project', (string) $document['project']['id'] );
			if ( ! $local_project_id ) {
				return new WP_Error( 'videodraft_import_project_missing', __( 'The VideoDraft project imported, but its local Project record could not be resolved.', 'worldgraph' ) );
			}
			self::scope_imported_scenes( $local_project_id, $document );
			$local_data = Mapper::from_worldgraph( $local_project_id );
			$identity_map = Mapper::identity_map_from_worldgraph( $local_project_id, $local_data );
			$now = gmdate( 'c' );
			self::set_mapping( $local_project_id, $connection_id, [
				'remote_project_id' => $remote_project_id,
				'remote_hash'       => self::remote_hash( $remote ),
				'local_hash'        => Mapper::hash_payload( $local_data ),
				'identity_map'      => $identity_map,
				'last_pulled_at'    => $now,
				'status'            => 'synced',
				'error'             => '',
			] );
			do_action( 'worldgraph_videodraft_project_pulled', $local_project_id, $remote_project_id, $connection_id, $report );

			return [
				'success'           => true,
				'dry_run'           => false,
				'project_id'        => $local_project_id,
				'remote_project_id' => $remote_project_id,
				'pulled_at'         => $now,
				'counts'            => self::document_counts( $document ),
				'report'            => $report,
			];
		} finally {
			self::$syncing = false;
		}
	}

	/** List projects visible to a VideoDraft Connection. */
	public static function list_projects( int $connection_id = 0 ) {
		$connection_id = self::connection_id( $connection_id );
		$valid = self::validate_connection( $connection_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$result = \WorldGraph\Utils\VideoDraft_API::call_tool( 'list_projects', [], $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$projects = $result['projects'] ?? $result['items'] ?? $result['data'] ?? $result;
		return is_array( $projects ) ? array_values( $projects ) : [];
	}

	/** Return VideoDraft's current project schema. */
	public static function project_schema( int $connection_id = 0 ) {
		$connection_id = self::connection_id( $connection_id );
		$valid = self::validate_connection( $connection_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		return \WorldGraph\Utils\VideoDraft_API::call_tool( 'get_project_schema', [], $connection_id );
	}

	/** Get the mapping for one local project and Connection. */
	public static function mapping( int $project_id, int $connection_id ): array {
		if ( ! self::is_project( $project_id ) ) {
			return [];
		}
		$mappings = get_post_meta( $project_id, self::MAPPING_META, true );
		$mappings = is_array( $mappings ) ? $mappings : [];
		$mapping = $mappings[ (string) $connection_id ] ?? [];
		return is_array( $mapping ) ? $mapping : [];
	}

	/** Remove a local mapping without deleting either project. */
	public static function unsync( int $project_id, int $connection_id = 0 ): bool {
		$connection_id = self::connection_id( $connection_id );
		if ( ! self::is_project( $project_id ) || is_wp_error( self::validate_connection( $connection_id ) ) ) {
			return false;
		}
		$mappings = get_post_meta( $project_id, self::MAPPING_META, true );
		$mappings = is_array( $mappings ) ? $mappings : [];
		unset( $mappings[ (string) $connection_id ] );
		return (bool) update_post_meta( $project_id, self::MAPPING_META, $mappings );
	}

	/** Fetch a raw VideoDraft project. */
	private static function get_remote_project( int $connection_id, string $remote_project_id ) {
		return \WorldGraph\Utils\VideoDraft_API::call_tool( 'get_project', [ 'project_id' => $remote_project_id, 'view' => 'raw' ], $connection_id );
	}

	/** Create a blank remote project, adapting to its live input schema. */
	private static function create_remote_project( int $connection_id, string $title, string $description ) {
		$properties = self::tool_properties( $connection_id, 'create_blank_project' );
		$args = [];
		foreach ( [ 'title', 'name', 'project_name', 'project_title' ] as $key ) {
			if ( isset( $properties[ $key ] ) ) {
				$args[ $key ] = $title;
				break;
			}
		}
		if ( empty( $args ) ) {
			$args['title'] = $title;
		}
		if ( '' !== $description && ( empty( $properties ) || isset( $properties['description'] ) ) ) {
			$args['description'] = $description;
		}
		return \WorldGraph\Utils\VideoDraft_API::call_tool( 'create_blank_project', $args, $connection_id );
	}

	/** Read a tool's live JSON Schema properties. */
	private static function tool_properties( int $connection_id, string $tool_name ): array {
		if ( ! isset( self::$catalogs[ $connection_id ] ) ) {
			$catalog = \WorldGraph\Utils\VideoDraft_API::tool_catalog( $connection_id );
			self::$catalogs[ $connection_id ] = is_wp_error( $catalog ) ? [] : $catalog;
		}
		foreach ( self::$catalogs[ $connection_id ] as $tool ) {
			if ( $tool_name === ( $tool['name'] ?? '' ) ) {
				$schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? [];
				return is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
			}
		}
		return [];
	}

	/** Resolve a requested or configured Connection. */
	private static function connection_id( int $connection_id ): int {
		return $connection_id ?: Settings::connection_id();
	}

	/** Validate the selected Connection. */
	private static function validate_connection( int $connection_id ) {
		\WorldGraph\Utils\Connection_Adapters::load( 'videodraft' );
		$connection = \WorldGraph\Utils\Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'videodraft' !== ( $connection['provider_type'] ?? '' ) || 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'videodraft_connection_invalid', __( 'Select an available VideoDraft Connection first.', 'worldgraph' ) );
		}
		return true;
	}

	/** Validate both ends of a push. */
	private static function validate_project_and_connection( int $project_id, int $connection_id ) {
		$post = get_post( $project_id );
		if ( ! $post instanceof \WP_Post || 'worldgraph_project' !== $post->post_type ) {
			return new WP_Error( 'videodraft_project_invalid', __( 'Select a World Graph Studio Project to export.', 'worldgraph' ) );
		}
		return self::validate_connection( $connection_id );
	}

	/** Merge new mapping state without losing other Connections or timestamps. */
	private static function set_mapping( int $project_id, int $connection_id, array $values, bool $replace = false ): void {
		$mappings = get_post_meta( $project_id, self::MAPPING_META, true );
		$mappings = is_array( $mappings ) ? $mappings : [];
		$current  = ! $replace && is_array( $mappings[ (string) $connection_id ] ?? null ) ? $mappings[ (string) $connection_id ] : [];
		$mappings[ (string) $connection_id ] = array_merge( $current, $values, [ 'connection_id' => $connection_id ] );
		update_post_meta( $project_id, self::MAPPING_META, $mappings );
	}

	/** Store an API error on the mapping without persisting request credentials. */
	private static function store_error( int $project_id, int $connection_id, string $remote_project_id, WP_Error $error, string $status = 'error' ): void {
		self::set_mapping( $project_id, $connection_id, [
			'remote_project_id' => $remote_project_id,
			'status'            => $status,
			'error'             => sanitize_text_field( $error->get_error_message() ),
		] );
	}

	/** Build a conflict error and persist its state. */
	private static function conflict( string $side, int $project_id, string $remote_project_id, int $connection_id ): WP_Error {
		$message = 'remote' === $side
			? __( 'VideoDraft changed since the last sync. Preview or pull it first, or force this push after reviewing the differences.', 'worldgraph' )
			: __( 'The local project changed since the last sync. Export it first, or force this pull after reviewing the differences.', 'worldgraph' );
		self::set_mapping( $project_id, $connection_id, [
			'status'        => 'conflict',
			'conflict_side' => $side,
			'conflict_at'   => gmdate( 'c' ),
			'error'         => $message,
		] );
		return new WP_Error( 'videodraft_sync_conflict', $message, [ 'status' => 409, 'side' => $side, 'project_id' => $project_id, 'remote_project_id' => $remote_project_id ] );
	}

	/** Locate the local Project explicitly mapped to this Connection and remote ID. */
	private static function find_mapped_project( int $connection_id, string $remote_project_id ): int {
		$projects = get_posts( [
			'post_type'      => 'worldgraph_project',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::MAPPING_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		] );
		foreach ( $projects as $project_id ) {
			$mapping = self::mapping( (int) $project_id, $connection_id );
			if ( hash_equals( (string) ( $mapping['remote_project_id'] ?? '' ), $remote_project_id ) ) {
				return (int) $project_id;
			}
		}
		return 0;
	}

	/** Hash only the structural subset represented by the mapper. */
	private static function remote_hash( array $remote ): string {
		return Mapper::hash_payload( Mapper::remote_editable_projection( $remote ) );
	}

	/** Stable external-ID namespace for one VideoDraft Connection. */
	private static function scope( int $connection_id ): string {
		return 'connection-' . $connection_id;
	}

	/** Whether an ID identifies a local World Graph Studio Project. */
	private static function is_project( int $project_id ): bool {
		$post = get_post( $project_id );
		return $post instanceof \WP_Post && 'worldgraph_project' === $post->post_type;
	}

	/** Find an imported record by its stable external ID. */
	private static function find_by_external_id( string $post_type, string $external_id ): int {
		$posts = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => 'external_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		return $posts ? (int) $posts[0] : 0;
	}

	/** Add the project edge omitted by the generic JSON importer. */
	private static function scope_imported_scenes( int $project_id, array $document ): void {
		foreach ( (array) ( $document['scenes'] ?? [] ) as $scene ) {
			$scene_id = self::find_by_external_id( 'worldgraph_scene', (string) ( $scene['id'] ?? '' ) );
			if ( $scene_id ) {
				\WorldGraph\Utils\add_relationship( $scene_id, 'worldgraph_scene', $project_id, 'worldgraph_project', 'belongs_to', [ 'source' => 'videodraft' ] );
			}
		}
	}

	/** Counts for an outbound VideoDraft payload. */
	private static function counts( array $data ): array {
		$scenes = (array) ( $data['storyboard']['scenes'] ?? [] );
		$shots = 0;
		foreach ( $scenes as $scene ) {
			$shots += count( (array) ( $scene['shots'] ?? [] ) );
		}
		return [ 'scenes' => count( $scenes ), 'shots' => $shots, 'visual_assets' => count( (array) ( $data['visual_assets'] ?? [] ) ) ];
	}

	/** Counts for a World Graph Studio interchange document. */
	private static function document_counts( array $document ): array {
		return [
			'characters'  => count( (array) ( $document['characters'] ?? [] ) ),
			'locations'   => count( (array) ( $document['locations'] ?? [] ) ),
			'props'       => count( (array) ( $document['props'] ?? [] ) ),
			'scenes'      => count( (array) ( $document['scenes'] ?? [] ) ),
			'shots'       => count( (array) ( $document['shots'] ?? [] ) ),
			'storyboards' => count( (array) ( $document['storyboards'] ?? [] ) ),
		];
	}
}
