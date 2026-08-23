<?php
/**
 * MCP-driven fal model discovery and World Graph Studio Template provisioning.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps fal Template configuration owned by the Connection and its MCP schema.
 */
class Fal_Catalog {

	/** Background hook used after a fal Connection is saved. */
	const HOOK = 'worldgraph_provision_fal_templates';

	/** Register automatic provisioning hooks. */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'provision' ] );
		add_action( 'save_post_worldgraph_conn', [ __CLASS__, 'schedule_after_connection_save' ], 20, 2 );
	}

	/** Schedule discovery after a fal Connection has finished saving its meta. */
	public static function schedule_after_connection_save( int $post_id, \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status || 'fal' !== worldgraph_get_field_value( $post_id, 'provider_type' ) || 'disabled' === worldgraph_get_field_value( $post_id, 'status' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK, [ $post_id ] );
		}
	}

	/**
	 * Discover allowed fal endpoints and create/update their World Graph Studio Templates.
	 *
	 * A Connection allowlist is authoritative. A configured default model is
	 * next. With neither set, fal MCP selects the first current text-to-image
	 * catalog result so setup still completes without hand-copying model IDs.
	 *
	 * @return array|WP_Error Provisioning result.
	 */
	public static function provision( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'fal' !== ( $connection['provider_type'] ?? '' ) || 'publish' !== ( $connection['status_wp'] ?? '' ) || 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'fal_connection_invalid', __( 'Template provisioning requires a fal Connection.', 'worldgraph' ) );
		}

		$endpoint_ids = self::configured_endpoint_ids( $connection );
		$catalog      = [];
		if ( empty( $endpoint_ids ) ) {
			$search = Fal_MCP::search_models( [ 'category' => 'text-to-image', 'limit' => 10 ], $connection_id );
			if ( is_wp_error( $search ) ) {
				self::record_error( $connection_id, $search );
				return $search;
			}
			$catalog = self::models_from_result( $search );
			if ( ! empty( $catalog[0]['endpoint_id'] ) ) {
				$endpoint_ids[] = (string) $catalog[0]['endpoint_id'];
				worldgraph_update_field_value( $connection_id, 'model', $endpoint_ids[0] );
			}
		}

		if ( empty( $endpoint_ids ) ) {
			$error = new WP_Error( 'fal_catalog_empty', __( 'fal MCP returned no text-to-image models to provision.', 'worldgraph' ) );
			self::record_error( $connection_id, $error );
			return $error;
		}

		$template_ids = [];
		foreach ( array_values( array_unique( $endpoint_ids ) ) as $endpoint_id ) {
			$schema = Fal_MCP::get_model_schema( $endpoint_id, $connection_id );
			if ( is_wp_error( $schema ) ) {
				self::record_error( $connection_id, $schema );
				return $schema;
			}

			$model = self::find_model( $catalog, $endpoint_id );
			$template_id = self::materialize( $connection_id, $endpoint_id, $model, $schema );
			if ( is_wp_error( $template_id ) ) {
				self::record_error( $connection_id, $template_id );
				return $template_id;
			}
			$template_ids[] = $template_id;
		}

		update_post_meta( $connection_id, 'fal_catalog_synced_at', gmdate( 'Y-m-d H:i:s' ) );
		delete_post_meta( $connection_id, 'fal_catalog_error' );

		return [ 'connection_id' => $connection_id, 'template_ids' => $template_ids, 'endpoint_ids' => $endpoint_ids ];
	}

	/** Resolve explicitly configured fal endpoint IDs. */
	private static function configured_endpoint_ids( array $connection ): array {
		$allowed = json_decode( (string) ( $connection['model_access'] ?? '' ), true );
		if ( is_array( $allowed ) ) {
			return array_values( array_filter( array_map( 'strval', $allowed ) ) );
		}

		$model = trim( (string) ( $connection['model'] ?? '' ) );
		return '' === $model ? [] : [ $model ];
	}

	/** Normalize search_models output. */
	private static function models_from_result( array $result ): array {
		$models = $result['models'] ?? $result['results'] ?? [];
		return array_values( array_filter( (array) $models, static function ( $model ): bool {
			return is_array( $model ) && ! empty( $model['endpoint_id'] );
		} ) );
	}

	/** Find catalog metadata for one endpoint. */
	private static function find_model( array $models, string $endpoint_id ): array {
		foreach ( $models as $model ) {
			if ( $endpoint_id === (string) ( $model['endpoint_id'] ?? '' ) ) {
				return $model;
			}
		}
		return [];
	}

	/** Create or update the Template representing one fal model schema. */
	private static function materialize( int $connection_id, string $endpoint_id, array $model, array $schema ) {
		$existing = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => 'connection_id', 'value' => (string) $connection_id ],
				[ 'key' => 'provider_template_id', 'value' => $endpoint_id ],
			],
		] );

		$metadata = is_array( $model['metadata'] ?? null ) ? $model['metadata'] : [];
		$name     = (string) ( $model['name'] ?? $metadata['display_name'] ?? $endpoint_id );
		$post_id  = wp_insert_post( [
			'ID'          => $existing ? (int) $existing[0] : 0,
			'post_type'   => 'worldgraph_template',
			'post_title'  => $name,
			'post_status' => 'publish',
		], true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return new WP_Error( 'fal_template_write_failed', __( 'World Graph Studio could not save the Template discovered from fal MCP.', 'worldgraph' ) );
		}

		$configuration = [
			'input'           => self::schema_defaults( $schema ),
			'provider_schema' => $schema,
		];
		worldgraph_update_field_value( $post_id, 'template_name', $name );
		worldgraph_update_field_value( $post_id, 'provider_type', 'fal' );
		worldgraph_update_field_value( $post_id, 'connection_id', (string) $connection_id );
		worldgraph_update_field_value( $post_id, 'provider_template_id', $endpoint_id );
		worldgraph_update_field_value( $post_id, 'modality', Generation_Modality::TEXT_TO_IMAGE );
		worldgraph_update_field_value( $post_id, 'generation_structure', 'image' );
		worldgraph_update_field_value( $post_id, 'configuration_json', (string) wp_json_encode( $configuration ) );
		worldgraph_update_field_value( $post_id, 'status', 'active' );
		worldgraph_update_field_value( $post_id, 'version', (string) ( $metadata['updated_at'] ?? gmdate( 'Y-m-d' ) ) );

		return (int) $post_id;
	}

	/** Extract safe provider defaults without asking users to reproduce a schema. */
	private static function schema_defaults( array $schema ): array {
		$candidates = [
			$schema['input_schema']['properties'] ?? null,
			$schema['input']['properties'] ?? null,
			$schema['properties'] ?? null,
		];
		foreach ( $candidates as $properties ) {
			if ( ! is_array( $properties ) ) {
				continue;
			}
			$defaults = [];
			foreach ( $properties as $name => $definition ) {
				if ( 'prompt' !== $name && is_array( $definition ) && array_key_exists( 'default', $definition ) ) {
					$defaults[ $name ] = $definition['default'];
				}
			}
			return $defaults;
		}

		return [];
	}

	/** Store a visible, non-fatal background provisioning error. */
	private static function record_error( int $connection_id, WP_Error $error ): void {
		update_post_meta( $connection_id, 'fal_catalog_error', $error->get_error_message() );
		Generation_Log::add( 'error', 'fal_catalog', $error->get_error_message(), [], '', $connection_id );
	}
}
