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
			'label'       => 'Scene Look & Lighting Override',
			'required'    => false,
			'description' => 'Optional scene-wide refinement of the Project visual direction (about 20 words). Describe only lighting, palette, atmosphere, weather, or texture that should remain consistent across this Scene—not plot, characters, camera, or sound.',
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
}
