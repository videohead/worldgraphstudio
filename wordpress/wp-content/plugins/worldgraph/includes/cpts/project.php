<?php
/**
 * Project Custom Post Type.
 *
 * @package WorldGraph
 */

namespace WorldGraph\CPT;

/**
 * Project Custom Post Type handler.
 */
class Project {
	/**
	 * Register the Project CPT.
	 */
	public static function init(): void {
		self::register_cpt();
		add_action( 'save_post_worldgraph_project', [ __CLASS__, 'hydrate_required_fields' ], 20, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'render_required_fields_notice' ] );
	}

	/**
	 * Auto-populate required Project fields from canonical WordPress post data.
	 *
	 * This prevents confusing publish failures when SCF-required fields are empty
	 * on newly created records.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @param bool     $update Whether this is an existing post update.
	 */
	public static function hydrate_required_fields( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$project_name = \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'project_name' );
		if ( '' === trim( (string) $project_name ) && '' !== trim( (string) $post->post_title ) ) {
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'project_name', sanitize_text_field( (string) $post->post_title ) );
		}

		$project_slug = \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'project_slug' );
		if ( '' === trim( (string) $project_slug ) && '' !== trim( (string) $post->post_name ) ) {
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'project_slug', sanitize_title( (string) $post->post_name ) );
		}

		$owner = \WorldGraph\Utils\worldgraph_get_field_value( $post_id, 'owner' );
		if ( empty( $owner ) && ! empty( $post->post_author ) ) {
			\WorldGraph\Utils\worldgraph_update_field_value( $post_id, 'owner', (int) $post->post_author );
		}
	}

	/**
	 * Show a precise missing-required-fields notice for Project editing.
	 */
	public static function render_required_fields_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'worldgraph_project' !== (string) $screen->post_type || 'post' !== (string) $screen->base ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin context.
		if ( $post_id <= 0 ) {
			return;
		}

		$missing = self::missing_required_field_labels( $post_id );
		if ( empty( $missing ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'This Project cannot be published yet.', 'worldgraph' )
			. '</strong> '
			. esc_html__( 'Fill these required fields:', 'worldgraph' )
			. ' '
			. esc_html( implode( ', ', $missing ) )
			. '.</p></div>';
	}

	/**
	 * Return the labels of required Project fields that are currently empty.
	 *
	 * @param int $post_id Project post ID.
	 * @return array<int, string>
	 */
	private static function missing_required_field_labels( int $post_id ): array {
		$fields = \WorldGraph\Utils\worldgraph_get_fields( 'worldgraph_project' );
		if ( empty( $fields ) ) {
			return [];
		}

		$missing = [];
		foreach ( $fields as $field_name => $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}

			$value = \WorldGraph\Utils\worldgraph_get_field_value( $post_id, (string) $field_name );
			if ( self::field_is_empty( $value ) ) {
				$missing[] = (string) ( $field['label'] ?? $field_name );
			}
		}

		return $missing;
	}

	/**
	 * Determine if an SCF field value should be treated as empty.
	 *
	 * @param mixed $value Raw field value.
	 */
	private static function field_is_empty( $value ): bool {
		if ( is_array( $value ) ) {
			return 0 === count( array_filter( $value, static function( $item ): bool {
				if ( is_numeric( $item ) ) {
					return (int) $item > 0;
				}
				return '' !== trim( (string) $item );
			} ) );
		}

		if ( is_numeric( $value ) ) {
			return (int) $value <= 0;
		}

		return '' === trim( (string) $value );
	}

	/**
	 * Register the Project CPT.
	 */
	public static function register_cpt(): void {
		$fields = [
		'project_name'        => [
			'type'        => 'text',
			'label'       => 'Project Name',
			'required'    => true,
			'description' => 'The official name of the project.',
		],
		'project_slug'        => [
			'type'        => 'text',
			'label'       => 'Project Slug',
			'required'    => true,
			'description' => 'URL-friendly identifier.',
		],
		'description'         => [
			'type'        => 'wysiwyg',
			'label'       => 'Description',
			'required'    => false,
			'description' => 'Project overview and synopsis.',
		],
		'generation_prompt'   => [
			'type'        => 'textarea',
			'label'       => 'Generation Prompt Instructions',
			'required'    => false,
			'description' => 'Project-specific visual instructions appended to generated media prompts, for example "no watermark" or a house style.',
		],
		'genre'               => [
			'type'        => 'taxonomy',
			'taxonomy'    => 'worldgraph_genre',
			'label'       => 'Genre',
			'required'    => false,
			'multiple'    => true,
		],
		'target_medium'       => [
			'type'        => 'select',
			'label'       => 'Target Medium',
			'required'    => false,
			'options'     => [
				'film'        => 'Feature Film',
				'short_film'  => 'Short Film',
				'tv_series'   => 'TV Series',
				'web_series'  => 'Web Series',
				'anime'       => 'Anime',
				'animation'   => 'Animation',
				'documentary' => 'Documentary',
				'game'        => 'Game',
				'other'       => 'Other',
			],
		],
		'status'              => [
			'type'        => 'taxonomy',
			'taxonomy'    => 'worldgraph_status',
			'label'       => 'Production Status',
			'required'    => false,
		],
		'owner'               => [
			'type'        => 'user',
			'label'       => 'Project Owner',
			'required'    => true,
		],
		'start_date'          => [
			'type'        => 'date',
			'label'       => 'Start Date',
			'required'    => false,
		],
		'end_date'            => [
			'type'        => 'date',
			'label'       => 'End Date',
			'required'    => false,
		],
		'team_members'        => [
			'type'              => 'relationship',
			'label'             => 'Team Members',
			'required'          => false,
			'related_cpt'       => 'worldgraph_character',
			'relationship_type' => 'contains',
			'multiple'          => true,
		],
		'production_stage'    => [
			'type'        => 'select',
			'label'       => 'Production Stage',
			'required'    => false,
			'options'     => [
				'concept'       => 'Concept',
				'development'   => 'Development',
				'pre_production'=> 'Pre-Production',
				'production'    => 'Production',
				'post_production' => 'Post-Production',
				'released'      => 'Released',
			],
		],
		'frame_width'        => [
			'type'        => 'number',
			'label'       => 'Frame Width (px)',
			'required'    => false,
			'description' => 'Pixel width used for generated images and video.',
		],
		'frame_height'       => [
			'type'        => 'number',
			'label'       => 'Frame Height (px)',
			'required'    => false,
			'description' => 'Pixel height used for generated images and video.',
		],
		'aspect_ratio'       => [
			'type'        => 'text',
			'label'       => 'Aspect Ratio',
			'required'    => false,
			'description' => 'Project frame ratio, for example 16:9 or 2.39:1.',
		],
		'frame_rate'         => [
			'type'        => 'number',
			'label'       => 'Frame Rate (fps)',
			'required'    => false,
			'description' => 'Frames per second used for generated video.',
		],
	];

	\WorldGraph\Utils\register_cpt(
		'worldgraph_project',
		'Projects',
		[
			'menu_icon' => 'dashicons-video-alt3',
			'menu_position' => 5,
		],
		$fields
	);
	}
}
