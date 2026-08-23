<?php
/**
 * Sound Custom Post Type.
 *
 * @package WorldGraph
 */

namespace WorldGraph\CPT;

/**
 * Planned soundtrack cue linked to Story Graph entities.
 *
 * Sound records describe authorial and production intent. Rendered audio files
 * remain WordPress attachments represented by worldgraph_asset records linked
 * through the optional Asset field.
 */
class Sound {

	/**
	 * Register the CPT and its SCF validation hooks.
	 */
	public static function init(): void {
		self::register_cpt();
		add_action( 'acf/validate_save_post', [ __CLASS__, 'validate_scf_request' ], 20 );
		add_action( 'add_meta_boxes_worldgraph_sound', [ __CLASS__, 'remove_duplicate_taxonomy_boxes' ] );
	}

	/**
	 * Register the Sound CPT.
	 */
	private static function register_cpt(): void {
		$fields = [
			'sound_type'       => [
				'type'        => 'taxonomy',
				'taxonomy'    => 'worldgraph_sound_type',
				'label'       => 'Sound Type',
				'required'    => true,
				'description' => 'Narration, voice-over, music, effects, ambience, Foley, silence, or another soundtrack role.',
			],
			'production_status'=> [
				'type'        => 'taxonomy',
				'taxonomy'    => 'worldgraph_status',
				'label'       => 'Production Status',
				'required'    => false,
			],
			'spoken_text'      => [
				'type'        => 'textarea',
				'label'       => 'Spoken Text',
				'required'    => false,
				'description' => 'Narration, voice-over, or ADR copy. Ordinary screenplay dialogue remains on the Scene.',
			],
			'lyrics'           => [
				'type'        => 'textarea',
				'label'       => 'Lyrics',
				'required'    => false,
				'description' => 'Lyrics for a music cue, preserving line breaks.',
			],
			'start_timecode'   => [
				'type'        => 'text',
				'label'       => 'Start Timecode',
				'required'    => false,
				'description' => 'Cue position within the linked Scene or Shot, using the project timecode convention.',
			],
			'duration'         => [
				'type'        => 'text',
				'label'       => 'Duration',
				'required'    => false,
				'description' => 'ISO 8601 duration is preferred (for example, PT18S).',
			],
			'diegetic'         => [
				'type'        => 'select',
				'label'       => 'Story-world Relation',
				'required'    => false,
				'default'     => 'unspecified',
				'options'     => [
					'unspecified'  => 'Unspecified',
					'diegetic'     => 'Diegetic (heard by characters)',
					'non_diegetic' => 'Non-diegetic (audience only)',
					'internal'     => 'Internal / Subjective',
					'mixed'        => 'Mixed / Ambiguous',
				],
			],
			'production_notes' => [
				'type'        => 'textarea',
				'label'       => 'Production Notes',
				'required'    => false,
			],
			'scene'            => [
				'type'              => 'relationship',
				'label'             => 'Scene',
				'required'          => true,
				'related_cpt'       => 'worldgraph_scene',
				'relationship_type' => 'belongs_to',
			],
			'shot'             => [
				'type'              => 'relationship',
				'label'             => 'Shot',
				'required'          => false,
				'related_cpt'       => 'worldgraph_shot',
				'relationship_type' => 'belongs_to',
				'description'       => 'Optional when the cue applies to a specific shot rather than the whole scene.',
			],
			'character'        => [
				'type'              => 'relationship',
				'label'             => 'Narrator / Voice Character',
				'required'          => false,
				'related_cpt'       => 'worldgraph_character',
				'relationship_type' => 'linked_to',
			],
			'asset'            => [
				'type'              => 'relationship',
				'label'             => 'Rendered Audio Asset',
				'required'          => false,
				'related_cpt'       => 'worldgraph_asset',
				'relationship_type' => 'linked_to',
				'description'       => 'Optional audio Asset containing the recorded or generated result.',
				'query_args'        => [
					'tax_query' => [
						[
							'taxonomy' => 'worldgraph_asset_type',
							'field'    => 'slug',
							'terms'    => [ 'audio' ],
						],
					],
				],
			],
		];

		\WorldGraph\Utils\register_cpt(
			'worldgraph_sound',
			'Sounds',
			[
				'menu_icon'    => 'dashicons-format-audio',
				'show_in_menu' => 'worldgraph-editorial',
				'supports'     => [ 'title', 'editor', 'excerpt', 'revisions', 'page-attributes' ],
			],
			$fields
		);
	}

	/**
	 * Keep SCF as the single taxonomy editing surface.
	 */
	public static function remove_duplicate_taxonomy_boxes(): void {
		remove_meta_box( 'tagsdiv-worldgraph_sound_type', 'worldgraph_sound', 'side' );
		remove_meta_box( 'tagsdiv-worldgraph_status', 'worldgraph_sound', 'side' );
	}

	/**
	 * Validate cross-field Sound rules before SCF persists the edit form.
	 */
	public static function validate_scf_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- SCF verifies the enclosing field-group request before this validation hook runs.
		$submitted = isset( $_POST['acf'] ) && is_array( $_POST['acf'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- SCF owns validation nonce handling.
			? wp_unslash( $_POST['acf'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by field below.
			: [];
		$prefix    = 'field_worldgraph_sound_';
		$is_sound  = false;
		foreach ( array_keys( $submitted ) as $field_key ) {
			if ( 0 === strpos( (string) $field_key, $prefix ) ) {
				$is_sound = true;
				break;
			}
		}

		$post_id = isset( $_POST['post_ID'] )
			? absint( wp_unslash( $_POST['post_ID'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- SCF owns validation nonce handling.
			: ( isset( $_POST['_acf_post_id'] ) ? absint( wp_unslash( $_POST['_acf_post_id'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- SCF owns validation nonce handling.
		if ( ! $is_sound && ( ! $post_id || 'worldgraph_sound' !== get_post_type( $post_id ) ) ) {
			return;
		}

		if ( isset( $_POST['post_title'] ) && '' === trim( sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validation only.
			acf_add_validation_error( 'post_title', __( 'A Sound title is required.', 'worldgraph' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$type_id      = absint( self::scf_request_value( 'sound_type', $submitted, $post_id ) );
		$scene_id     = absint( self::scf_request_value( 'scene', $submitted, $post_id ) );
		$shot_id      = absint( self::scf_request_value( 'shot', $submitted, $post_id ) );
		$character_id = absint( self::scf_request_value( 'character', $submitted, $post_id ) );
		$asset_id     = absint( self::scf_request_value( 'asset', $submitted, $post_id ) );
		$lyrics       = trim( (string) self::scf_request_value( 'lyrics', $submitted, $post_id ) );
		$type         = $type_id ? get_term( $type_id, 'worldgraph_sound_type' ) : null;

		if ( ! $type || is_wp_error( $type ) || \WorldGraph\Utils\worldgraph_is_reserved_sound_type( $type ) ) {
			self::add_scf_error( 'sound_type', __( 'Select a valid non-dialogue Sound Type.', 'worldgraph' ) );
		}
		if ( ! $scene_id || 'worldgraph_scene' !== get_post_type( $scene_id ) ) {
			self::add_scf_error( 'scene', __( 'Select a valid Scene.', 'worldgraph' ) );
		}
		if ( $shot_id && ( 'worldgraph_shot' !== get_post_type( $shot_id ) || ! self::shot_belongs_to_scene( $shot_id, $scene_id ) ) ) {
			self::add_scf_error( 'shot', __( 'The selected Shot must belong to the selected Scene.', 'worldgraph' ) );
		}
		if ( $character_id && 'worldgraph_character' !== get_post_type( $character_id ) ) {
			self::add_scf_error( 'character', __( 'Select a valid narrator or voice Character.', 'worldgraph' ) );
		}
		if ( $asset_id && ! \WorldGraph\Utils\worldgraph_is_audio_asset( $asset_id ) ) {
			self::add_scf_error( 'asset', __( 'The rendered Asset must have the Audio asset type.', 'worldgraph' ) );
		}
		if ( '' !== $lyrics && ( ! $type || is_wp_error( $type ) || 'music' !== $type->slug ) ) {
			self::add_scf_error( 'lyrics', __( 'Lyrics may only be stored on a Music Sound.', 'worldgraph' ) );
		}
	}

	/**
	 * Read a submitted SCF value, falling back to the current stored value.
	 *
	 * @param string              $field_name Field name.
	 * @param array<string,mixed> $submitted  Submitted SCF values.
	 * @param int                 $post_id    Existing post ID.
	 * @return mixed
	 */
	private static function scf_request_value( string $field_name, array $submitted, int $post_id ) {
		$field_key = \WorldGraph\Utils\SCF_Fields::field_key( 'worldgraph_sound', $field_name );
		if ( array_key_exists( $field_key, $submitted ) ) {
			$value = $submitted[ $field_key ];
			return is_array( $value ) && 1 === count( $value ) ? reset( $value ) : $value;
		}

		return $post_id ? \WorldGraph\Utils\worldgraph_get_field_value( $post_id, $field_name ) : '';
	}

	/**
	 * Add an SCF validation error to a stable Sound field input.
	 */
	private static function add_scf_error( string $field_name, string $message ): void {
		$field_key = \WorldGraph\Utils\SCF_Fields::field_key( 'worldgraph_sound', $field_name );
		acf_add_validation_error( 'acf[' . $field_key . ']', $message );
	}

	/**
	 * Confirm that the selected Shot belongs to the selected Scene.
	 *
	 * @param int $shot_id  Shot post ID.
	 * @param int $scene_id Scene post ID.
	 * @return bool
	 */
	private static function shot_belongs_to_scene( int $shot_id, int $scene_id ): bool {
		foreach ( \WorldGraph\Utils\get_relationships( $shot_id, 'worldgraph_shot', 'outgoing' ) as $relationship ) {
			if ( $scene_id === (int) ( $relationship['to_id'] ?? 0 ) && 'worldgraph_scene' === (string) ( $relationship['to_type'] ?? '' ) ) {
				return true;
			}
		}

		foreach ( \WorldGraph\Utils\get_relationships( $scene_id, 'worldgraph_scene', 'outgoing' ) as $relationship ) {
			if ( $shot_id === (int) ( $relationship['to_id'] ?? 0 ) && 'worldgraph_shot' === (string) ( $relationship['to_type'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
