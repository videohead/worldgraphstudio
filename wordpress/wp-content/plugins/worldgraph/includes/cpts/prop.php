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
		add_action( 'acf/validate_save_post', [ __CLASS__, 'validate_scf_request' ], 20 );
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

	/**
	 * Prevent an owned Prop from naming a Story World that conflicts with its owner.
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

		if ( ! self::is_prop_request( $submitted, $post_id ) ) {
			return;
		}

		$owner_id = self::relationship_id( self::scf_request_value( 'owner_character', $submitted, $post_id ) );
		$world_id = self::relationship_id( self::scf_request_value( 'story_world', $submitted, $post_id ) );
		if ( ! $owner_id || ! $world_id ) {
			return;
		}

		$owner_world_id = self::character_world_id( $owner_id );
		if ( $owner_world_id && $owner_world_id !== $world_id ) {
			self::add_scf_error(
				'story_world',
				__( "This Prop's Story World must match its Owner Character's Story World, or be left empty.", 'worldgraph' )
			);
		}
	}

	/** Whether the current SCF validation request edits a Prop. */
	private static function is_prop_request( array $submitted, int $post_id ): bool {
		$prefix = 'field_worldgraph_prop_';
		foreach ( array_keys( $submitted ) as $field_key ) {
			if ( 0 === strpos( (string) $field_key, $prefix ) ) {
				return true;
			}
		}

		return $post_id > 0 && 'worldgraph_prop' === get_post_type( $post_id );
	}

	/** Read one submitted Prop value, with the stored value as its edit fallback. */
	private static function scf_request_value( string $field_name, array $submitted, int $post_id ) {
		$field_key = \WorldGraph\Utils\SCF_Fields::field_key( 'worldgraph_prop', $field_name );
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

	/** Resolve the canonical Story World for a Character from its field or graph edge. */
	private static function character_world_id( int $character_id ): int {
		$world_id = self::relationship_id( \WorldGraph\Utils\worldgraph_get_field_value( $character_id, 'story_world' ) );
		if ( $world_id ) {
			return $world_id;
		}

		foreach ( \WorldGraph\Utils\get_relationships( $character_id, 'worldgraph_character', 'outgoing' ) as $relationship ) {
			if ( 'worldgraph_world' === (string) ( $relationship['to_type'] ?? '' ) ) {
				return absint( $relationship['to_id'] ?? 0 );
			}
		}
		foreach ( \WorldGraph\Utils\get_relationships( $character_id, 'worldgraph_character', 'incoming' ) as $relationship ) {
			if ( 'worldgraph_world' === (string) ( $relationship['from_type'] ?? '' ) ) {
				return absint( $relationship['from_id'] ?? 0 );
			}
		}

		return 0;
	}

	/** Add an SCF validation error beside the stable Prop field input. */
	private static function add_scf_error( string $field_name, string $message ): void {
		$field_key = \WorldGraph\Utils\SCF_Fields::field_key( 'worldgraph_prop', $field_name );
		acf_add_validation_error( 'acf[' . $field_key . ']', $message );
	}
}
