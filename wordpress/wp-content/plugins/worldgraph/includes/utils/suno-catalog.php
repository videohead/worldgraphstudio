<?php
/**
 * Automatic Suno REST and MCP Template provisioning.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maintains transport-specific Suno Templates from one Connection. */
class Suno_Catalog {

	/** Background hook used after a Suno Connection is saved. */
	const HOOK = 'worldgraph_provision_suno_templates';

	/** Register automatic provisioning hooks only while this adapter is loaded. */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'provision' ] );
		add_action( 'save_post_worldgraph_conn', [ __CLASS__, 'schedule_after_connection_save' ], 20, 2 );
	}

	/** Schedule provisioning after Connection meta has been saved. */
	public static function schedule_after_connection_save( int $post_id, \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status || 'suno' !== worldgraph_get_field_value( $post_id, 'provider_type' ) || 'disabled' === worldgraph_get_field_value( $post_id, 'status' ) ) {
			return;
		}

		self::schedule( $post_id );
	}

	/** Schedule one idempotent background catalog sync. */
	public static function schedule( int $connection_id, int $delay = 5 ): void {
		$connection_id = absint( $connection_id );
		if ( ! $connection_id || wp_next_scheduled( self::HOOK, [ $connection_id ] ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, [ $connection_id ] );
	}

	/**
	 * Create or update three Templates for every explicitly configured transport.
	 *
	 * The REST API uses credential_reference. MCP is enabled only when the
	 * independent mcp_credential_reference is explicitly configured, ensuring
	 * credentials are never sent to the other service's endpoint.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array|WP_Error
	 */
	public static function provision( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'suno' !== ( $connection['provider_type'] ?? '' ) || 'publish' !== ( $connection['status_wp'] ?? '' ) || 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'suno_connection_invalid', __( 'Template provisioning requires a Suno Connection.', 'worldgraph' ) );
		}

		$api_reference = trim( (string) ( $connection['credential_reference'] ?? '' ) );
		$mcp_reference = trim( (string) ( $connection['mcp_credential_reference'] ?? '' ) );
		if ( '' === $mcp_reference ) {
			$mcp_reference = trim( (string) worldgraph_get_field_value( $connection_id, 'mcp_credential_reference' ) );
		}

		$definitions = [];
		$transports  = [];
		$sync_error  = null;
		if ( '' !== $api_reference ) {
			$definitions = array_merge( $definitions, self::definitions( 'api' ) );
			$transports[] = 'api';
		}

		if ( '' !== $mcp_reference ) {
			$mcp_definitions = self::definitions( 'mcp' );
			$live_schemas    = Suno_MCP::tool_schemas( $connection_id );
			if ( is_wp_error( $live_schemas ) ) {
				$sync_error = $live_schemas;
			} else {
				$mcp_definitions = self::merge_live_schemas( $mcp_definitions, $live_schemas );
				$missing_tools   = array_values(
					array_filter(
						array_map(
							static function ( array $definition ) use ( $live_schemas ): string {
								$tool = (string) ( $definition['tool'] ?? '' );
								return '' !== $tool && ! isset( $live_schemas[ $tool ] ) ? $tool : '';
							},
							$mcp_definitions
						)
					)
				);
				if ( ! empty( $missing_tools ) ) {
					$sync_error = new WP_Error(
						'suno_mcp_catalog_incomplete',
						sprintf(
							/* translators: %s: comma-separated MCP tool names. */
							__( 'Suno MCP did not advertise expected tools: %s.', 'worldgraph' ),
							implode( ', ', $missing_tools )
						)
					);
				}
			}
			$definitions = array_merge( $definitions, $mcp_definitions );
			$transports[] = 'mcp';
		}

		if ( empty( $definitions ) ) {
			$error = new WP_Error( 'suno_credentials_missing', __( 'Add a Suno API credential, an AceData Cloud MCP credential, or both before provisioning Templates.', 'worldgraph' ) );
			self::record_error( $connection_id, $error );
			return $error;
		}

		$template_ids = [];
		foreach ( $definitions as $definition ) {
			$template_id = self::materialize( $connection_id, $definition );
			if ( is_wp_error( $template_id ) ) {
				self::record_error( $connection_id, $template_id );
				return $template_id;
			}
			$template_ids[] = $template_id;
		}

		update_post_meta( $connection_id, 'suno_catalog_synced_at', gmdate( 'Y-m-d H:i:s' ) );
		if ( is_wp_error( $sync_error ) ) {
			self::record_error( $connection_id, $sync_error );
		} else {
			delete_post_meta( $connection_id, 'suno_catalog_error' );
		}

		$result = [
			'connection_id' => $connection_id,
			'template_ids'  => $template_ids,
			'transports'    => $transports,
		];
		if ( is_wp_error( $sync_error ) ) {
			$result['warning'] = $sync_error->get_error_message();
		}

		return $result;
	}

	/**
	 * Return static documented definitions for one or both transports.
	 *
	 * @param string $transport api, mcp, or an empty string for both.
	 * @return array<int, array>
	 */
	public static function definitions( string $transport = '' ): array {
		$transport = sanitize_key( $transport );
		if ( 'api' === $transport ) {
			return self::api_definitions();
		}
		if ( 'mcp' === $transport ) {
			return self::mcp_definitions();
		}

		return array_merge( self::api_definitions(), self::mcp_definitions() );
	}

	/** Documented SunoAPI.org Template definitions. */
	private static function api_definitions(): array {
		$model_schema = [
			'type'    => 'string',
			'enum'    => Suno_API::MODELS,
			'default' => 'V5',
		];

		return [
			[
				'reference'   => 'api:generate',
				'name'        => 'Suno API — Generate Music',
				'description' => 'Generate a complete song from a natural-language music brief using Suno Inspiration Mode.',
				'transport'   => 'api',
				'modality'    => Generation_Modality::TEXT_TO_MUSIC,
				'structure'   => 'audio',
				'input'       => [
					'instrumental' => false,
				],
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'Suno API Generate Music',
					'endpoint'   => 'POST /api/v1/generate',
					'type'       => 'object',
					'properties' => [
						'customMode'  => [ 'type' => 'boolean', 'const' => false ],
						'instrumental' => [ 'type' => 'boolean', 'default' => false ],
						'model'        => $model_schema,
						'callBackUrl'  => [ 'type' => 'string', 'format' => 'uri', 'managed_by' => 'worldgraph' ],
						'prompt'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 3000 ],
					],
					'required'   => [ 'customMode', 'instrumental', 'model', 'callBackUrl', 'prompt' ],
				],
			],
			[
				'reference'   => 'api:generate-custom',
				'name'        => 'Suno API — Generate Custom Music',
				'description' => 'Generate music with exact lyrics, title, style, and optional instrumental mode.',
				'transport'   => 'api',
				'modality'    => Generation_Modality::TEXT_TO_MUSIC,
				'structure'   => 'audio',
				'input'       => [
					'instrumental' => false,
					'style'        => '',
					'title'        => '',
				],
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'Suno API Generate Custom Music',
					'endpoint'   => 'POST /api/v1/generate',
					'type'       => 'object',
					'properties' => [
						'customMode'          => [ 'type' => 'boolean', 'const' => true ],
						'instrumental'         => [ 'type' => 'boolean', 'default' => false ],
						'model'                => $model_schema,
						'callBackUrl'          => [ 'type' => 'string', 'format' => 'uri', 'managed_by' => 'worldgraph' ],
						'prompt'               => [ 'type' => 'string', 'description' => 'Exact lyrics when instrumental is false.' ],
						'style'                => [ 'type' => 'string', 'minLength' => 1 ],
						'title'                => [ 'type' => 'string', 'minLength' => 1 ],
						'personaId'            => [ 'type' => 'string' ],
						'personaModel'         => [ 'type' => 'string', 'enum' => [ 'style_persona', 'voice_persona' ] ],
						'duration'             => [ 'type' => 'integer', 'minimum' => 10, 'maximum' => 360 ],
						'negativeTags'         => [ 'type' => 'string' ],
						'vocalGender'          => [ 'type' => 'string', 'enum' => [ 'm', 'f' ] ],
						'styleWeight'          => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ],
						'weirdnessConstraint'  => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ],
						'audioWeight'          => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ],
					],
					'required'   => [ 'customMode', 'instrumental', 'model', 'callBackUrl', 'style', 'title' ],
					'allOf'      => [
						[
							'if'   => [ 'properties' => [ 'instrumental' => [ 'const' => false ] ] ],
							'then' => [ 'required' => [ 'prompt' ] ],
						],
					],
				],
			],
			[
				'reference'   => 'api:lyrics',
				'name'        => 'Suno API — Generate Lyrics',
				'description' => 'Generate structured song lyrics from a short creative brief.',
				'transport'   => 'api',
				'modality'    => Generation_Modality::TEXT_TO_LYRICS,
				'structure'   => 'text',
				'input'       => [],
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'Suno API Generate Lyrics',
					'endpoint'   => 'POST /api/v1/lyrics',
					'type'       => 'object',
					'properties' => [
						'prompt'      => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
						'callBackUrl' => [ 'type' => 'string', 'format' => 'uri', 'managed_by' => 'worldgraph' ],
					],
					'required'   => [ 'prompt', 'callBackUrl' ],
				],
			],
		];
	}

	/** Static fallback definitions for AceData Cloud Suno MCP tools. */
	private static function mcp_definitions(): array {
		$model_schema = [
			'type'    => 'string',
			'enum'    => Suno_MCP::MODELS,
			'default' => 'chirp-v5-5',
		];

		return [
			[
				'reference'   => 'mcp:suno_generate_music',
				'tool'        => 'suno_generate_music',
				'name'        => 'Suno MCP — Generate Music',
				'description' => 'Generate music from a text prompt through the hosted AceData Cloud MCP server.',
				'transport'   => 'mcp',
				'modality'    => Generation_Modality::TEXT_TO_MUSIC,
				'structure'   => 'audio',
				'input'       => [
					'instrumental' => false,
				],
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'suno_generate_music',
					'type'       => 'object',
					'properties' => [
						'prompt'             => [ 'type' => 'string', 'minLength' => 1 ],
						'model'              => $model_schema,
						'instrumental'       => [ 'type' => 'boolean', 'default' => false ],
						'variation_category' => [ 'type' => [ 'string', 'null' ], 'enum' => [ 'high', 'normal', 'subtle', null ] ],
					],
					'required'   => [ 'prompt' ],
				],
			],
			[
				'reference'   => 'mcp:suno_generate_custom_music',
				'tool'        => 'suno_generate_custom_music',
				'name'        => 'Suno MCP — Generate Custom Music',
				'description' => 'Generate music with exact lyrics and style through the hosted AceData Cloud MCP server.',
				'transport'   => 'mcp',
				'modality'    => Generation_Modality::TEXT_TO_MUSIC,
				'structure'   => 'audio',
				'input'       => [
					'instrumental' => false,
					'style'        => '',
					'title'        => '',
				],
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'suno_generate_custom_music',
					'type'       => 'object',
					'properties' => [
						'lyric'              => [ 'type' => 'string', 'description' => 'Exact lyrics with Suno section markers.' ],
						'title'              => [ 'type' => 'string', 'minLength' => 1 ],
						'style'              => [ 'type' => 'string', 'minLength' => 1 ],
						'model'              => $model_schema,
						'instrumental'       => [ 'type' => 'boolean', 'default' => false ],
						'lyric_prompt'       => [ 'type' => [ 'object', 'null' ] ],
						'style_negative'     => [ 'type' => 'string' ],
						'vocal_gender'       => [ 'type' => 'string', 'enum' => [ '', 'f', 'm' ] ],
						'variation_category' => [ 'type' => [ 'string', 'null' ], 'enum' => [ 'high', 'normal', 'subtle', null ] ],
						'weirdness'          => [ 'type' => [ 'number', 'null' ] ],
						'style_influence'    => [ 'type' => [ 'number', 'null' ] ],
					],
					'required'   => [ 'title', 'style' ],
					'allOf'      => [
						[
							'if'   => [ 'properties' => [ 'instrumental' => [ 'const' => false ] ] ],
							'then' => [ 'required' => [ 'lyric' ] ],
						],
					],
				],
			],
			[
				'reference'   => 'mcp:suno_generate_lyrics',
				'tool'        => 'suno_generate_lyrics',
				'name'        => 'Suno MCP — Generate Lyrics',
				'description' => 'Generate structured lyrics through the hosted AceData Cloud MCP server.',
				'transport'   => 'mcp',
				'modality'    => Generation_Modality::TEXT_TO_LYRICS,
				'structure'   => 'text',
				'input'       => [ 'model' => 'default' ],
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'suno_generate_lyrics',
					'type'       => 'object',
					'properties' => [
						'prompt' => [ 'type' => 'string', 'minLength' => 1 ],
						'model'  => [ 'type' => 'string', 'enum' => [ 'default', 'remi-v1' ], 'default' => 'default' ],
					],
					'required'   => [ 'prompt' ],
				],
			],
		];
	}

	/** Merge tools/list input schemas into the static, documented fallback. */
	private static function merge_live_schemas( array $definitions, array $live_schemas ): array {
		foreach ( $definitions as &$definition ) {
			$tool = (string) ( $definition['tool'] ?? '' );
			$live = is_array( $live_schemas[ $tool ] ?? null ) ? $live_schemas[ $tool ] : [];
			$input_schema = is_array( $live['inputSchema'] ?? null ) ? $live['inputSchema'] : [];
			if ( ! empty( $input_schema ) ) {
				$definition['schema'] = array_replace_recursive( (array) $definition['schema'], $input_schema );
				if ( isset( $input_schema['required'] ) ) {
					$definition['schema']['required'] = $input_schema['required'];
				}
			}
			if ( ! empty( $live['description'] ) ) {
				$definition['schema']['description'] = (string) $live['description'];
			}
		}
		unset( $definition );

		return $definitions;
	}

	/** Create or update one transport-specific Suno Template. */
	private static function materialize( int $connection_id, array $definition ) {
		$reference = sanitize_text_field( (string) ( $definition['reference'] ?? '' ) );
		$existing  = get_posts(
			[
				'post_type'      => 'worldgraph_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[ 'key' => 'connection_id', 'value' => (string) $connection_id ],
					[ 'key' => 'provider_template_id', 'value' => $reference ],
				],
			]
		);

		$name        = (string) ( $definition['name'] ?? $reference );
		$description = (string) ( $definition['description'] ?? '' );
		$post_id     = wp_insert_post(
			[
				'ID'           => $existing ? (int) $existing[0] : 0,
				'post_type'    => 'worldgraph_template',
				'post_title'   => $name,
				'post_content' => $description,
				'post_status'  => 'publish',
			],
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return new WP_Error( 'suno_template_write_failed', __( 'World Graph Studio could not save a Suno generation Template.', 'worldgraph' ) );
		}

		$configuration = [
			'input'           => (array) ( $definition['input'] ?? [] ),
			'provider_schema' => (array) ( $definition['schema'] ?? [] ),
			'transport'       => (string) ( $definition['transport'] ?? '' ),
		];
		worldgraph_update_field_value( $post_id, 'template_name', $name );
		worldgraph_update_field_value( $post_id, 'description', wp_kses_post( $description ) );
		worldgraph_update_field_value( $post_id, 'provider_type', 'suno' );
		worldgraph_update_field_value( $post_id, 'connection_id', (string) $connection_id );
		worldgraph_update_field_value( $post_id, 'provider_template_id', $reference );
		worldgraph_update_field_value( $post_id, 'modality', (string) $definition['modality'] );
		worldgraph_update_field_value( $post_id, 'generation_structure', (string) ( $definition['structure'] ?? 'audio' ) );
		worldgraph_update_field_value( $post_id, 'configuration_json', (string) wp_json_encode( $configuration ) );
		worldgraph_update_field_value( $post_id, 'status', 'active' );
		worldgraph_update_field_value( $post_id, 'version', gmdate( 'Y-m-d' ) );

		return (int) $post_id;
	}

	/** Record a visible, non-fatal catalog synchronization failure. */
	private static function record_error( int $connection_id, WP_Error $error ): void {
		update_post_meta( $connection_id, 'suno_catalog_error', $error->get_error_message() );
		Generation_Log::add( 'error', 'suno_catalog', $error->get_error_message(), [], '', $connection_id );
	}
}
