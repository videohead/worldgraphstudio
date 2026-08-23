<?php
/**
 * Descript project import (transcript pull) and media export (push) sync.
 *
 * @package WorldGraphDescript
 */

namespace WorldGraphDescript;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Manual transcript pull and media push service. */
class Sync {

	/** Per-Project Descript mappings, keyed by Connection ID. */
	const MAPPING_META = '_worldgraph_descript_mapping';

	/** Prevent recursive sync calls in the current request. */
	private static $syncing = false;

	/** Reserved for future opt-in hooks; sync is intentionally manual today. */
	public static function init(): void {}

	/** List projects visible to a Descript Connection's drive. */
	public static function list_projects( int $connection_id = 0 ) {
		$connection_id = self::connection_id( $connection_id );
		$valid = self::validate_connection( $connection_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return \WorldGraph\Utils\Descript_API::list_projects( $connection_id );
	}

	/** Get one remote project's metadata, media, and compositions. */
	public static function get_project( string $remote_project_id, int $connection_id = 0 ) {
		$connection_id = self::connection_id( $connection_id );
		$valid = self::validate_connection( $connection_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return \WorldGraph\Utils\Descript_API::get_project( $connection_id, $remote_project_id );
	}

	/**
	 * Export a Descript composition's transcript and import it into the
	 * Story Graph as a Project, World, and one transcript Scene.
	 */
	public static function pull_transcript( string $remote_project_id, string $composition_id = '', int $connection_id = 0, string $format = 'markdown', bool $force = false ) {
		if ( self::$syncing ) {
			return new WP_Error( 'descript_sync_reentrant', __( 'A Descript sync is already running.', 'worldgraph' ) );
		}
		if ( ! class_exists( '\\WorldGraph\\Importer\\WorldGraph_Importer' ) ) {
			return new WP_Error( 'descript_importer_disabled', __( 'Enable the Story Import & Export plugin before pulling a Descript transcript.', 'worldgraph' ) );
		}
		$connection_id = self::connection_id( $connection_id );
		$valid = self::validate_connection( $connection_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$remote_project_id = sanitize_text_field( $remote_project_id );
		if ( '' === $remote_project_id ) {
			return new WP_Error( 'descript_remote_project_missing', __( 'Enter a Descript project ID to import.', 'worldgraph' ) );
		}

		self::$syncing = true;
		try {
			$project = \WorldGraph\Utils\Descript_API::get_project( $connection_id, $remote_project_id );
			$project_title = is_wp_error( $project ) ? '' : (string) ( $project['title'] ?? $project['name'] ?? '' );

			$exported = \WorldGraph\Utils\Descript_API::export_transcript( $connection_id, [
				'project_id'     => $remote_project_id,
				'composition_id' => $composition_id,
				'format'         => $format,
			] );
			if ( is_wp_error( $exported ) ) {
				return $exported;
			}
			if ( '' === trim( (string) $exported['text'] ) ) {
				return new WP_Error( 'descript_transcript_empty', __( 'Descript returned an empty transcript for this project.', 'worldgraph' ) );
			}

			$local_project_id = self::find_mapped_project( $connection_id, $remote_project_id, $composition_id );
			$mapping = $local_project_id ? self::mapping( $local_project_id, $connection_id ) : [];
			if ( $local_project_id && ! $force && 'error' === ( $mapping['status'] ?? '' ) ) {
				return new WP_Error( 'descript_verification_required', __( 'The previous transcript import needs review before it can be repeated. Force the next pull after checking the local Scene.', 'worldgraph' ), [ 'status' => 409 ] );
			}
			$identity_map = is_array( $mapping['identity_map'] ?? null ) ? $mapping['identity_map'] : [];

			$document = Mapper::from_transcript(
				(string) $exported['text'],
				(string) $exported['format'],
				[
					'project_id'     => $remote_project_id,
					'composition_id' => $composition_id,
					'project_title'  => $project_title,
				],
				self::scope( $connection_id ),
				$identity_map
			);

			$importer = new \WorldGraph\Importer\WorldGraph_Importer();
			$report = $importer->import( (string) wp_json_encode( $document ), [ 'overwrite' => true ] );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			if ( ! empty( $report['errors'] ) ) {
				return new WP_Error( 'descript_import_incomplete', __( 'The Descript transcript import did not pass verification. Review the import report before retrying.', 'worldgraph' ), [ 'report' => $report ] );
			}

			$local_project_id = self::find_by_external_id( 'worldgraph_project', (string) $document['project']['id'] );
			if ( ! $local_project_id ) {
				return new WP_Error( 'descript_import_project_missing', __( 'The transcript imported, but its local Project record could not be resolved.', 'worldgraph' ) );
			}
			self::scope_imported_scenes( $local_project_id, $document );

			$now = gmdate( 'c' );
			self::set_mapping( $local_project_id, $connection_id, [
				'remote_project_id'     => $remote_project_id,
				'remote_composition_id' => $composition_id,
				'identity_map'          => self::identity_map_from_document( $document ),
				'last_pulled_at'        => $now,
				'status'                => 'synced',
				'error'                 => '',
			] );

			return [
				'success'           => true,
				'project_id'        => $local_project_id,
				'remote_project_id' => $remote_project_id,
				'pulled_at'         => $now,
				'format'            => $exported['format'],
				'report'            => $report,
			];
		} finally {
			self::$syncing = false;
		}
	}

	/** Submit a local Project's bound video/audio media as a new Descript import job. */
	public static function push_media( int $project_id, int $connection_id = 0, string $remote_project_id = '', string $folder_name = '' ) {
		if ( self::$syncing ) {
			return new WP_Error( 'descript_sync_reentrant', __( 'A Descript sync is already running.', 'worldgraph' ) );
		}
		$connection_id = self::connection_id( $connection_id );
		$valid = self::validate_project_and_connection( $project_id, $connection_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$payload = Mapper::media_from_worldgraph( $project_id );
		if ( empty( $payload['add_media'] ) ) {
			return new WP_Error( 'descript_export_empty', __( 'This Project has no bound video or audio media to export.', 'worldgraph' ) );
		}
		if ( '' !== $folder_name ) {
			$payload['folder_name'] = sanitize_text_field( $folder_name );
		}
		$remote_project_id = sanitize_text_field( $remote_project_id );
		if ( '' !== $remote_project_id ) {
			$payload['project_id'] = $remote_project_id;
			unset( $payload['project_name'] );
		}

		self::$syncing = true;
		try {
			$job = \WorldGraph\Utils\Descript_API::import_project_media( $connection_id, $payload );
			if ( is_wp_error( $job ) ) {
				return $job;
			}

			$job_id = sanitize_text_field( (string) ( $job['job_id'] ?? $job['id'] ?? '' ) );
			if ( '' === $job_id ) {
				return new WP_Error( 'descript_export_job_missing', __( 'Descript did not return a job ID for this import.', 'worldgraph' ) );
			}

			$now = gmdate( 'c' );
			self::set_mapping( $project_id, $connection_id, [
				'job_id'            => $job_id,
				'remote_project_id' => $remote_project_id,
				'status'            => 'submitted',
				'submitted_at'      => $now,
				'media_count'       => count( $payload['add_media'] ),
			] );

			return [
				'success'      => true,
				'job_id'       => $job_id,
				'project_id'   => $project_id,
				'submitted_at' => $now,
				'media_count'  => count( $payload['add_media'] ),
			];
		} finally {
			self::$syncing = false;
		}
	}

	/** Poll an asynchronous Descript job and refresh its local mapping. */
	public static function poll_job( string $job_id, int $connection_id = 0, int $project_id = 0 ) {
		$connection_id = self::connection_id( $connection_id );
		$valid = self::validate_connection( $connection_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$job = \WorldGraph\Utils\Descript_API::get_job_status( $connection_id, $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$status = \WorldGraph\Utils\Descript_API::normalize_status( (string) ( $job['status'] ?? $job['state'] ?? '' ) );
		if ( $project_id && self::is_project( $project_id ) && '' !== $status ) {
			$update = [ 'status' => $status ];
			$remote_project_id = (string) ( $job['project_id'] ?? $job['project']['id'] ?? '' );
			if ( '' !== $remote_project_id ) {
				$update['remote_project_id'] = sanitize_text_field( $remote_project_id );
			}
			if ( 'failed' === $status ) {
				$update['error'] = (string) ( $job['error'] ?? $job['message'] ?? __( 'Descript reported a failed import job.', 'worldgraph' ) );
			}
			self::set_mapping( $project_id, $connection_id, $update );
		}

		$job['status'] = $status ?: ( $job['status'] ?? '' );
		return $job;
	}

	/** Get the mapping for one local post and Connection. */
	public static function mapping( int $post_id, int $connection_id ): array {
		if ( ! self::is_project( $post_id ) ) {
			return [];
		}
		$mappings = get_post_meta( $post_id, self::MAPPING_META, true );
		$mappings = is_array( $mappings ) ? $mappings : [];
		$mapping = $mappings[ (string) $connection_id ] ?? [];
		return is_array( $mapping ) ? $mapping : [];
	}

	/** Remove a local mapping without deleting either project. */
	public static function unsync( int $post_id, int $connection_id = 0 ): bool {
		$connection_id = self::connection_id( $connection_id );
		if ( ! self::is_project( $post_id ) || is_wp_error( self::validate_connection( $connection_id ) ) ) {
			return false;
		}
		$mappings = get_post_meta( $post_id, self::MAPPING_META, true );
		$mappings = is_array( $mappings ) ? $mappings : [];
		unset( $mappings[ (string) $connection_id ] );
		return (bool) update_post_meta( $post_id, self::MAPPING_META, $mappings );
	}

	/** Resolve the effective Connection ID, falling back to the saved settings default. */
	private static function connection_id( int $connection_id ): int {
		return $connection_id ?: Settings::connection_id();
	}

	/** Validate that a Connection ID is a usable Descript Connection. */
	private static function validate_connection( int $connection_id ) {
		$connection = \WorldGraph\Utils\Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'descript' !== ( $connection['provider_type'] ?? '' ) || 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'descript_connection_invalid', __( 'Select an available Descript Connection first.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		return true;
	}

	/** Validate both a local Project and a Descript Connection. */
	private static function validate_project_and_connection( int $project_id, int $connection_id ) {
		if ( ! self::is_project( $project_id ) ) {
			return new WP_Error( 'descript_project_invalid', __( 'The requested World Graph Studio Project was not found.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		return self::validate_connection( $connection_id );
	}

	/** Whether a post ID is a worldgraph_project. */
	private static function is_project( int $project_id ): bool {
		$post = get_post( $project_id );
		return $post instanceof \WP_Post && 'worldgraph_project' === $post->post_type;
	}

	/** A stable per-Connection scope used for deterministic external IDs. */
	private static function scope( int $connection_id ): string {
		return 'conn-' . $connection_id;
	}

	/** Find a Project already mapped to a remote project/composition pair. */
	private static function find_mapped_project( int $connection_id, string $remote_project_id, string $composition_id ): int {
		$posts = get_posts( [
			'post_type'      => 'worldgraph_project',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::MAPPING_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		] );
		foreach ( $posts as $post_id ) {
			$mapping = self::mapping( (int) $post_id, $connection_id );
			if ( ( $mapping['remote_project_id'] ?? '' ) === $remote_project_id
				&& ( $mapping['remote_composition_id'] ?? '' ) === $composition_id ) {
				return (int) $post_id;
			}
		}
		return 0;
	}

	/** Merge and persist one Connection's mapping fields on a local post. */
	private static function set_mapping( int $post_id, int $connection_id, array $fields ): void {
		$mappings = get_post_meta( $post_id, self::MAPPING_META, true );
		$mappings = is_array( $mappings ) ? $mappings : [];
		$key = (string) $connection_id;
		$mappings[ $key ] = array_merge( is_array( $mappings[ $key ] ?? null ) ? $mappings[ $key ] : [], $fields );
		update_post_meta( $post_id, self::MAPPING_META, $mappings );
	}

	/** Derive a reusable identity map from an imported document's external IDs. */
	private static function identity_map_from_document( array $document ): array {
		$map = [
			'project'  => [ (string) ( $document['project']['id'] ?? '' ) => (string) ( $document['project']['id'] ?? '' ) ],
			'world'    => [ 'world' => (string) ( $document['world']['id'] ?? '' ) ],
			'sequence' => [ 'main' => (string) ( $document['sequence']['id'] ?? '' ) ],
		];
		foreach ( (array) ( $document['scenes'] ?? [] ) as $scene ) {
			$map['scene']['transcript'] = (string) ( $scene['id'] ?? '' );
		}
		return $map;
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
				\WorldGraph\Utils\add_relationship( $scene_id, 'worldgraph_scene', $project_id, 'worldgraph_project', 'belongs_to', [ 'source' => 'descript' ] );
			}
		}
	}
}
