<?php
/**
 * Provision World Graph Studio Templates from VideoDraft's live MCP schemas.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;
use WorldGraph\Templates\Template_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/templates/class-template-repository.php';

/** VideoDraft Template catalog. */
class VideoDraft_Catalog {

	/** Background provisioning hook. */
	const HOOK = 'worldgraph_provision_videodraft_templates';

	/** Register the legacy provider provisioning hook. */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'provision' ] );
	}

	/** Schedule provisioning after Connection metadata is stored. */
	public static function schedule_after_connection_save( int $post_id, \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status || 'videodraft' !== worldgraph_get_field_value( $post_id, 'provider_type' ) || 'disabled' === worldgraph_get_field_value( $post_id, 'status' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK, [ $post_id ] );
		}
	}

	/** Discover supported tools and materialize one Template per modality. */
	public static function provision( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'videodraft' !== ( $connection['provider_type'] ?? '' ) || 'publish' !== ( $connection['status_wp'] ?? '' ) || 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'videodraft_connection_invalid', __( 'Template provisioning requires a VideoDraft Connection.', 'worldgraph' ) );
		}

		$catalog = VideoDraft_API::tool_catalog( $connection_id );
		if ( is_wp_error( $catalog ) ) {
			self::record_error( $connection_id, $catalog );
			return $catalog;
		}

		$definitions = self::template_definitions();
		$template_ids = [];
		$tool_names   = [];
		foreach ( $catalog as $tool ) {
			$name = sanitize_key( (string) ( $tool['name'] ?? '' ) );
			if ( ! isset( $definitions[ $name ] ) ) {
				continue;
			}
			$template_id = self::materialize( $connection_id, $tool, $definitions[ $name ] );
			if ( is_wp_error( $template_id ) ) {
				self::record_error( $connection_id, $template_id );
				return $template_id;
			}
			$template_ids[] = $template_id;
			$tool_names[]   = $name;
		}

		if ( empty( $template_ids ) ) {
			$error = new WP_Error( 'videodraft_catalog_empty', __( 'VideoDraft exposed no supported generation tools.', 'worldgraph' ) );
			self::record_error( $connection_id, $error );
			return $error;
		}

		$available = array_values( array_filter( array_map( static function ( $tool ): string {
			return is_array( $tool ) ? (string) ( $tool['name'] ?? '' ) : '';
		}, $catalog ) ) );
		worldgraph_update_field_value( $connection_id, 'capabilities', (string) wp_json_encode( [
			'provider'         => 'videodraft',
			'generation_tools' => $tool_names,
			'project_sync'     => empty( array_diff( [ 'list_projects', 'get_project', 'create_blank_project', 'update_project' ], $available ) ),
			'tools'            => $available,
		] ) );
		update_post_meta( $connection_id, 'videodraft_catalog_synced_at', gmdate( 'Y-m-d H:i:s' ) );
		delete_post_meta( $connection_id, 'videodraft_catalog_error' );

		return [ 'connection_id' => $connection_id, 'template_ids' => $template_ids, 'tools' => $available ];
	}

	/** Supported VideoDraft generation tools and their provider-neutral modes. */
	private static function template_definitions(): array {
		return [
			'generate_image' => [ 'label' => 'VideoDraft Image', 'modality' => Generation_Modality::TEXT_TO_IMAGE, 'output' => 'image' ],
			'generate_video' => [ 'label' => 'VideoDraft Video', 'modality' => Generation_Modality::TEXT_TO_VIDEO, 'output' => 'video' ],
			'generate_audio' => [ 'label' => 'VideoDraft Audio', 'modality' => Generation_Modality::TEXT_TO_SOUND_EFFECT, 'output' => 'audio' ],
			'generate_voiceover' => [ 'label' => 'VideoDraft Voiceover', 'modality' => Generation_Modality::TEXT_TO_SPEECH, 'output' => 'audio' ],
			'generate_music' => [ 'label' => 'VideoDraft Music', 'modality' => Generation_Modality::TEXT_TO_MUSIC, 'output' => 'audio' ],
			'generate_sound_effect' => [ 'label' => 'VideoDraft Sound Effect', 'modality' => Generation_Modality::TEXT_TO_SOUND_EFFECT, 'output' => 'audio' ],
		];
	}

	/** Upsert one VideoDraft tool as a Template post. */
	private static function materialize( int $connection_id, array $tool, array $definition ) {
		$name   = sanitize_key( (string) ( $tool['name'] ?? '' ) );
		$schema = is_array( $tool['inputSchema'] ?? null ) ? $tool['inputSchema'] : ( is_array( $tool['input_schema'] ?? null ) ? $tool['input_schema'] : [] );

		return Template_Repository::upsert_provider_template(
			$connection_id,
			[
				'provider_type'        => 'videodraft',
				'provider_template_id' => $name,
				'template_name'        => (string) ( $definition['label'] ?? $name ),
				'description'          => sanitize_textarea_field( (string) ( $tool['description'] ?? '' ) ),
				'modality'             => (string) ( $definition['modality'] ?? '' ),
				'input'                => self::schema_defaults( $schema ),
				'provider_schema'      => $schema,
				'configuration'        => [
					'provider_tool' => $name,
				],
				'status'               => 'active',
				'version'              => substr( hash( 'sha256', (string) wp_json_encode( $schema ) ), 0, 12 ),
			]
		);
	}

	/** Extract safe, non-prompt defaults from a JSON Schema. */
	private static function schema_defaults( array $schema ): array {
		$defaults = [];
		foreach ( (array) ( $schema['properties'] ?? [] ) as $name => $property ) {
			if ( in_array( $name, [ 'prompt', 'text', 'project_id', 'session_id', 'scene_index', 'shot_index' ], true ) || ! is_array( $property ) || ! array_key_exists( 'default', $property ) ) {
				continue;
			}
			$defaults[ sanitize_key( (string) $name ) ] = $property['default'];
		}

		return $defaults;
	}

	/** Persist a sanitized provisioning error. */
	private static function record_error( int $connection_id, WP_Error $error ): void {
		update_post_meta( $connection_id, 'videodraft_catalog_error', sanitize_text_field( $error->get_error_message() ) );
	}
}
