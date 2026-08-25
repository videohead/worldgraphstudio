<?php
/**
 * Prop Custom Post Type.
 *
 * @package WorldGraph
 */

namespace WorldGraph\CPT;

/**
 * Prop Custom Post Type handler.
 */
class Prop {
	/**
	 * Register the Prop CPT.
	 */
	public static function init(): void {
		self::register_cpt();
	}

	/**
	 * Register the Prop CPT.
	 */
	private static function register_cpt(): void {
		$fields = [
			'prop_name'       => [
				'type'        => 'text',
				'label'       => 'Prop Name',
				'required'    => true,
		],
		'description'     => [
			'type'        => 'wysiwyg',
			'label'       => 'Description',
			'required'    => false,
		],
		'generation_prompt' => [
			'type'        => 'textarea',
			'label'       => 'Generation Prompt Instructions',
			'required'    => false,
			'description' => 'Prop-specific visual instructions appended to generated media prompts, for example "no watermark" or material constraints.',
		],
		'purpose'         => [
			'type'        => 'text',
			'label'       => 'Purpose',
			'required'    => false,
		],
		'owner_character' => [
			'type'              => 'relationship',
			'label'             => 'Owner Character',
			'required'          => false,
			'related_cpt'       => 'worldgraph_character',
			'relationship_type' => 'linked_to',
		],
		'story_world'     => [
			'type'              => 'relationship',
			'label'             => 'Story World (Shared Prop)',
			'required'          => false,
			'related_cpt'       => 'worldgraph_world',
			'relationship_type' => 'belongs_to',
			'description'       => 'Use for an unowned or shared Prop so it can inherit its Project visual direction and generation defaults. An Owner Character takes precedence.',
		],
		'notes'           => [
			'type'        => 'wysiwyg',
			'label'       => 'Notes',
			'required'    => false,
		],
	];

	\WorldGraph\Utils\register_cpt(
		'worldgraph_prop',
		'Props',
		[
			'menu_icon' => 'dashicons-cart',
			'show_in_menu' => 'worldgraph-story-elements',
		],
		$fields
	);
	}
}
