<?php
/**
 * Scene Custom Post Type.
 *
 * @package WorldGraph
 */

namespace WorldGraph\CPT;

/**
 * Scene Custom Post Type handler.
 */
class Scene {
	/**
	 * Register the Scene CPT.
	 */
	public static function init(): void {
		self::register_cpt();
		add_action( 'acf/validate_save_post', [ __CLASS__, 'validate_scf_request' ], 20 );
	}

	/**
	 * Register the Scene CPT.
	 */
	private static function register_cpt(): void {
		$fields = [
			'scene_number'    => [
				'type'        => 'number',
				'label'       => 'Scene Number',
				'required'    => true,
		],
		'title'           => [
			'type'        => 'text',
			'label'       => 'Scene Title',
			'required'    => true,
		],
		'summary'         => [
			'type'        => 'wysiwyg',
			'label'       => 'Summary',
			'required'    => false,
		],
		'script_content'  => [
			'type'        => 'wysiwyg',
			'label'       => 'Script Content',
			'required'    => false,
		],
		'dialogue'        => [
			'type'        => 'structured',
			'label'       => 'Dialogue',
			'required'    => false,
			'admin_ui'    => false,
			'read_only'   => true,
			'description' => 'Importer-managed dialogue entries with speaker, line, description, and sequence fields.',
		],
		'location'        => [
			'type'              => 'relationship',
			'label'             => 'Location',
			'required'          => false,
			'related_cpt'       => 'worldgraph_location',
			'relationship_type' => 'located_in',
		],
		'time_of_day'     => [
			'type'        => 'select',
			'label'       => 'Time of Day',
			'required'    => false,
			'options'     => [
				'dawn'        => 'Dawn',
				'morning'     => 'Morning',
				'midday'      => 'Midday',
				'afternoon'   => 'Afternoon',
				'dusk'        => 'Dusk',
				'evening'     => 'Evening',
				'night'       => 'Night',
			],
		],
		'emotional_tone'  => [
			'type'        => 'text',
			'label'       => 'Emotional Tone',
			'required'    => false,
		],
		'generation_prompt' => [
			'type'        => 'textarea',
			'label'       => 'Scene Look & Lighting Changes',
			'required'    => false,
			'description' => 'Project Visual Direction is the baseline. Enter only Scene-specific differences (about 8 words): lighting, palette, atmosphere, weather, or texture. These Scene values take precedence inside this Scene. Do not repeat the Project style or add plot, characters, camera, or sound.',
		],
		'audio_direction' => [
			'type'        => 'textarea',
			'label'       => 'Sound & Music Direction',
			'required'    => false,
			'description' => 'Optional Scene-wide ambience, music, and sonic palette (about 16 words) inherited by linked Sound generation. Describe tone, instrumentation, texture, rhythm, or acoustic space—not dialogue, lyrics, or individual cue events.',
		],
		'lens'            => [
			'type'        => 'text',
			'label'       => 'Default Lens / Lens Family',
			'required'    => false,
			'description' => 'Optional camera continuity for this Scene, for example "40mm" or "restrained 35–50mm spherical lenses." A Shot lens overrides this value.',
		],
		'camera_movement' => [
			'type'        => 'select',
			'label'       => 'Default Camera Movement',
			'required'    => false,
			'description' => 'Optional camera behavior inherited by generated Shot video. A Shot movement overrides this value; choose Locked Off on a Shot to explicitly suppress Scene movement.',
			'options'     => [
				'locked_off'       => 'Locked Off (Static)',
				'handheld'         => 'Handheld Drift',
				'pan_left'         => 'Pan Left',
				'pan_right'        => 'Pan Right',
				'tilt_up'          => 'Tilt Up',
				'tilt_down'        => 'Tilt Down',
				'push_in'          => 'Dolly / Push In',
				'pull_back'        => 'Dolly / Pull Back',
				'track_left'       => 'Track Left',
				'track_right'      => 'Track Right',
				'follow_subject'   => 'Follow Subject',
				'orbit_left'       => 'Orbit Left',
				'orbit_right'      => 'Orbit Right',
				'crane_up'         => 'Crane Up',
				'crane_down'       => 'Crane Down',
				'zoom_in'          => 'Zoom In',
				'zoom_out'         => 'Zoom Out',
			],
		],
		'production_notes'=> [
			'type'        => 'wysiwyg',
			'label'       => 'Production Notes',
			'required'    => false,
		],
		'sequence'        => [
			'type'        => 'taxonomy',
			'taxonomy'    => 'worldgraph_sequence',
			'label'       => 'Sequence',
			'required'    => false,
		],
		'episode'         => [
			'type'        => 'relationship',
			'label'       => 'Episode',
			'required'    => false,
			'related_cpt' => 'worldgraph_episode',
		],
		'project'         => [
			'type'              => 'relationship',
			'label'             => 'Project (Standalone Scene)',
			'required'          => false,
			'related_cpt'       => 'worldgraph_project',
			'relationship_type' => 'belongs_to',
			'description'       => 'Use only when this Scene is not assigned to an Episode. Episode ownership takes precedence when both fields are set.',
		],
	];

	\WorldGraph\Utils\register_cpt(
		'worldgraph_scene',
		'Scenes',
		[
			'menu_icon' => 'dashicons-screenoptions',
			'show_in_menu' => 'worldgraph-editorial',
		],
		$fields
	);
	}

	/**
	 * Prevent an Episode-owned Scene from naming a conflicting standalone Project.
	 */
	public static function validate_scf_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- SCF verifies the enclosing field-group request before this validation hook runs.
		$submitted = isset( $_POST['acf'] ) && is_array( $_POST['acf'] )
			? wp_unslash( $_POST['acf'] )
			: [];
		$post_id   = isset( $_POST['post_ID'] )
			? absint( wp_unslash( $_POST['post_ID'] ) )
			: ( isset( $_POST['_acf_post_id'] ) ? absint( wp_unslash( $_POST['_acf_post_id'] ) ) : 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! self::is_scene_request( $submitted, $post_id ) ) {
			return;
		}

		$episode_id = self::relationship_id( self::scf_request_value( 'episode', $submitted, $post_id ) );
		$project_id = self::relationship_id( self::scf_request_value( 'project', $submitted, $post_id ) );
		$validation = self::validate_parent_pair( $episode_id, $project_id );
		if ( is_wp_error( $validation ) ) {
			$data = $validation->get_error_data();
			self::add_scf_error( (string) ( is_array( $data ) ? ( $data['field'] ?? 'project' ) : 'project' ), $validation->get_error_message() );
		}
	}

	/**
	 * Validate the canonical Episode/Project ownership pair at any write boundary.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_parent_pair( int $episode_id, int $project_id ) {
		if ( $episode_id && 'worldgraph_episode' !== get_post_type( $episode_id ) ) {
			return new \WP_Error( 'worldgraph_scene_episode_invalid', __( 'Select a valid Episode.', 'worldgraph' ), [ 'field' => 'episode', 'status' => 400 ] );
		}
		if ( $project_id && 'worldgraph_project' !== get_post_type( $project_id ) ) {
			return new \WP_Error( 'worldgraph_scene_project_invalid', __( 'Select a valid Project.', 'worldgraph' ), [ 'field' => 'project', 'status' => 400 ] );
		}
		if ( ! $episode_id || ! $project_id ) {
			return true;
		}

		$episode_project_id = self::episode_project_id( $episode_id );
		if ( $episode_project_id && $episode_project_id !== $project_id ) {
			return new \WP_Error(
				'worldgraph_scene_project_conflict',
				__( "This Scene's Project must match the selected Episode's Project, or be left empty.", 'worldgraph' ),
				[ 'field' => 'project', 'status' => 400 ]
			);
		}

		return true;
	}

	/** Whether the current SCF validation request edits a Scene. */
	private static function is_scene_request( array $submitted, int $post_id ): bool {
		$prefix = 'field_worldgraph_scene_';
		foreach ( array_keys( $submitted ) as $field_key ) {
			if ( 0 === strpos( (string) $field_key, $prefix ) ) {
				return true;
			}
		}

		return $post_id > 0 && 'worldgraph_scene' === get_post_type( $post_id );
	}

	/** Read one submitted Scene value, with the stored value as its edit fallback. */
	private static function scf_request_value( string $field_name, array $submitted, int $post_id ) {
		$field_key = \WorldGraph\Utils\SCF_Fields::field_key( 'worldgraph_scene', $field_name );
		if ( array_key_exists( $field_key, $submitted ) ) {
			return $submitted[ $field_key ];
		}

		return $post_id ? \WorldGraph\Utils\worldgraph_get_field_value( $post_id, $field_name ) : '';
	}

	/** Normalize an SCF relationship value to one post ID. */
	private static function relationship_id( $value ): int {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( $value instanceof \WP_Post ) {
			$value = $value->ID;
		}

		return absint( $value );
	}

	/** Resolve the canonical Project for an Episode from its field or graph edge. */
	private static function episode_project_id( int $episode_id ): int {
		$project_id = self::relationship_id( \WorldGraph\Utils\worldgraph_get_field_value( $episode_id, 'project' ) );
		if ( $project_id ) {
			return $project_id;
		}

		foreach ( \WorldGraph\Utils\get_relationships( $episode_id, 'worldgraph_episode', 'outgoing' ) as $relationship ) {
			if ( 'worldgraph_project' === (string) ( $relationship['to_type'] ?? '' ) ) {
				return absint( $relationship['to_id'] ?? 0 );
			}
		}
		foreach ( \WorldGraph\Utils\get_relationships( $episode_id, 'worldgraph_episode', 'incoming' ) as $relationship ) {
			if ( 'worldgraph_project' === (string) ( $relationship['from_type'] ?? '' ) ) {
				return absint( $relationship['from_id'] ?? 0 );
			}
		}

		return 0;
	}

	/** Add an SCF validation error beside the stable Scene field input. */
	private static function add_scf_error( string $field_name, string $message ): void {
		$field_key = \WorldGraph\Utils\SCF_Fields::field_key( 'worldgraph_scene', $field_name );
		acf_add_validation_error( 'acf[' . $field_key . ']', $message );
	}
}
