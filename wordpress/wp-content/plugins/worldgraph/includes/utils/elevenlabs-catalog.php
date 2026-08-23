<?php
/**
 * Automatic ElevenLabs voice/model Template provisioning.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maintains endpoint-specific generative audio Templates from a Connection. */
class ElevenLabs_Catalog {

	/** Background hook used after an ElevenLabs Connection is saved. */
	const HOOK = 'worldgraph_provision_elevenlabs_templates';

	/** Register provisioning hooks only while this adapter is loaded. */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'provision' ] );
		add_action( 'save_post_worldgraph_conn', [ __CLASS__, 'schedule_after_connection_save' ], 20, 2 );
	}

	/** Schedule provisioning after Connection meta has been saved. */
	public static function schedule_after_connection_save( int $post_id, \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status || 'elevenlabs' !== worldgraph_get_field_value( $post_id, 'provider_type' ) || 'disabled' === worldgraph_get_field_value( $post_id, 'status' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK, [ $post_id ] );
		}
	}

	/** Discover usable models/voices and create Templates for generation methods. */
	public static function provision( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'elevenlabs' !== ( $connection['provider_type'] ?? '' ) || 'publish' !== ( $connection['status_wp'] ?? '' ) || 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'elevenlabs_connection_invalid', __( 'Template provisioning requires an ElevenLabs Connection.', 'worldgraph' ) );
		}

		$catalog = ElevenLabs_API::catalog( $connection_id );
		if ( is_wp_error( $catalog ) ) {
			self::record_error( $connection_id, $catalog );
			return $catalog;
		}
		$models = (array) ( $catalog['text_to_speech_models'] ?? [] );
		$voices = (array) ( $catalog['voices'] ?? [] );
		if ( empty( $models ) || empty( $voices ) ) {
			$error = new WP_Error( 'elevenlabs_catalog_empty', __( 'ElevenLabs returned no usable text-to-speech models or voices.', 'worldgraph' ) );
			self::record_error( $connection_id, $error );
			return $error;
		}

		$model_id = self::select_model( (string) ( $connection['model'] ?? '' ), $models );
		if ( '' === (string) ( $connection['model'] ?? '' ) ) {
			worldgraph_update_field_value( $connection_id, 'model', $model_id );
		}
		$selected_voices = self::select_voices( (string) ( $connection['model_access'] ?? '' ), $voices );
		if ( empty( $selected_voices ) ) {
			$error = new WP_Error( 'elevenlabs_voice_missing', __( 'None of the voice IDs allowed by this Connection are available.', 'worldgraph' ) );
			self::record_error( $connection_id, $error );
			return $error;
		}

		$template_ids = [];
		foreach ( $selected_voices as $voice ) {
			$template_id = self::materialize( $connection_id, self::speech_definition( $model_id, $voice ) );
			if ( is_wp_error( $template_id ) ) {
				self::record_error( $connection_id, $template_id );
				return $template_id;
			}
			$template_ids[] = $template_id;
		}
		foreach ( self::method_definitions( $selected_voices[0] ) as $definition ) {
			$template_id = self::materialize( $connection_id, $definition );
			if ( is_wp_error( $template_id ) ) {
				self::record_error( $connection_id, $template_id );
				return $template_id;
			}
			$template_ids[] = $template_id;
		}

		update_post_meta( $connection_id, 'elevenlabs_catalog_synced_at', gmdate( 'Y-m-d H:i:s' ) );
		delete_post_meta( $connection_id, 'elevenlabs_catalog_error' );
		return [ 'connection_id' => $connection_id, 'template_ids' => $template_ids, 'model_id' => $model_id ];
	}

	/** Select the configured model, then the stable multilingual default, then the first model. */
	private static function select_model( string $configured, array $models ): string {
		$ids = array_values( array_filter( array_map( static function ( $model ): string {
			return is_array( $model ) ? (string) ( $model['model_id'] ?? '' ) : '';
		}, $models ) ) );
		if ( '' !== $configured && in_array( $configured, $ids, true ) ) {
			return $configured;
		}
		return in_array( 'eleven_multilingual_v2', $ids, true ) ? 'eleven_multilingual_v2' : (string) ( $ids[0] ?? '' );
	}

	/** Apply an optional voice-ID allowlist; otherwise provision one default voice. */
	private static function select_voices( string $model_access, array $voices ): array {
		$allowed = json_decode( $model_access, true );
		if ( ! is_array( $allowed ) || empty( $allowed ) ) {
			return [ $voices[0] ];
		}
		$allowed = array_map( 'strval', $allowed );
		return array_values( array_filter( $voices, static function ( $voice ) use ( $allowed ): bool {
			return is_array( $voice ) && in_array( (string) ( $voice['voice_id'] ?? '' ), $allowed, true );
		} ) );
	}

	/** Definition for one voice-backed text-to-speech method. */
	private static function speech_definition( string $model_id, array $voice ): array {
		$voice_id = sanitize_text_field( (string) ( $voice['voice_id'] ?? '' ) );
		return [
			'reference' => 'text-to-speech:' . $voice_id,
			'name'      => sprintf( 'ElevenLabs — Speech — %s', (string) ( $voice['name'] ?? $voice_id ) ),
			'modality'  => Generation_Modality::TEXT_TO_SPEECH,
			'input'     => [ 'model_id' => $model_id, 'output_format' => 'mp3_44100_128' ],
			'schema'    => [
				'endpoint' => 'POST /v1/text-to-speech/{voice_id}',
				'required' => [ 'prompt', 'voice_id' ],
				'voice'    => $voice,
			],
		];
	}

	/** Definitions for generation methods that are not tied to one Template per voice. */
	private static function method_definitions( array $voice ): array {
		$voice_id = sanitize_text_field( (string) ( $voice['voice_id'] ?? '' ) );
		return [
			[
				'reference' => 'text-to-dialogue',
				'name'      => 'ElevenLabs — Text to Dialogue',
				'modality'  => Generation_Modality::TEXT_TO_DIALOGUE,
				'input'     => [ 'model_id' => 'eleven_v3', 'output_format' => 'mp3_44100_128', 'voice_id' => $voice_id ],
				'schema'    => [ 'endpoint' => 'POST /v1/text-to-dialogue', 'required' => [ 'inputs[].text', 'inputs[].voice_id' ], 'supports' => [ 'inputs', 'language_code', 'settings', 'seed' ] ],
			],
			[
				'reference' => 'sound-effects',
				'name'      => 'ElevenLabs — Sound Effects',
				'modality'  => Generation_Modality::TEXT_TO_SOUND_EFFECT,
				'input'     => [ 'model_id' => 'eleven_text_to_sound_v2', 'output_format' => 'mp3_44100_128', 'loop' => false, 'prompt_influence' => 0.3 ],
				'schema'    => [ 'endpoint' => 'POST /v1/sound-generation', 'required' => [ 'prompt' ], 'duration_seconds' => [ 'minimum' => 0.5, 'maximum' => 30 ] ],
			],
			[
				'reference' => 'music',
				'name'      => 'ElevenLabs — Music',
				'modality'  => Generation_Modality::TEXT_TO_MUSIC,
				'input'     => [ 'model_id' => 'music_v2', 'output_format' => 'auto', 'force_instrumental' => false ],
				'schema'    => [ 'endpoint' => 'POST /v1/music', 'required_one_of' => [ 'prompt', 'composition_plan' ], 'music_length_ms' => [ 'minimum' => 3000, 'maximum' => 600000 ] ],
			],
			[
				'reference' => 'voice-design',
				'name'      => 'ElevenLabs — Voice Design',
				'modality'  => Generation_Modality::TEXT_TO_VOICE,
				'input'     => [ 'model_id' => 'eleven_multilingual_ttv_v2', 'output_format' => 'mp3_44100_128', 'auto_generate_text' => true, 'guidance_scale' => 5 ],
				'schema'    => [ 'endpoint' => 'POST /v1/text-to-voice/design', 'required' => [ 'voice_description' ], 'returns' => 'multiple voice-preview audio files' ],
			],
		];
	}

	/** Create or update one endpoint-specific ElevenLabs Template. */
	private static function materialize( int $connection_id, array $definition ) {
		$reference = sanitize_text_field( (string) ( $definition['reference'] ?? '' ) );
		$existing = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => 'connection_id', 'value' => (string) $connection_id ],
				[ 'key' => 'provider_template_id', 'value' => $reference ],
			],
		] );
		$name = (string) ( $definition['name'] ?? $reference );
		$post_id = wp_insert_post( [
			'ID'          => $existing ? (int) $existing[0] : 0,
			'post_type'   => 'worldgraph_template',
			'post_title'  => $name,
			'post_status' => 'publish',
		], true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return new WP_Error( 'elevenlabs_template_write_failed', __( 'World Graph Studio could not save the Template discovered from ElevenLabs.', 'worldgraph' ) );
		}

		$configuration = [ 'input' => (array) ( $definition['input'] ?? [] ), 'provider_schema' => (array) ( $definition['schema'] ?? [] ) ];
		worldgraph_update_field_value( $post_id, 'template_name', $name );
		worldgraph_update_field_value( $post_id, 'provider_type', 'elevenlabs' );
		worldgraph_update_field_value( $post_id, 'connection_id', (string) $connection_id );
		worldgraph_update_field_value( $post_id, 'provider_template_id', $reference );
		worldgraph_update_field_value( $post_id, 'modality', (string) $definition['modality'] );
		worldgraph_update_field_value( $post_id, 'generation_structure', 'audio' );
		worldgraph_update_field_value( $post_id, 'configuration_json', (string) wp_json_encode( $configuration ) );
		worldgraph_update_field_value( $post_id, 'status', 'active' );
		worldgraph_update_field_value( $post_id, 'version', gmdate( 'Y-m-d' ) );
		return (int) $post_id;
	}

	/** Record a visible provisioning failure without failing Connection save. */
	private static function record_error( int $connection_id, WP_Error $error ): void {
		update_post_meta( $connection_id, 'elevenlabs_catalog_error', $error->get_error_message() );
		Generation_Log::add( 'error', 'elevenlabs_catalog', $error->get_error_message(), [], '', $connection_id );
	}
}
