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
		add_action( 'admin_notices', [ __CLASS__, 'render_import_notice' ] );
	}

	/**
	 * Show written-project import guidance on the Projects list screen.
	 */
	public static function render_import_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || 'worldgraph_project' !== $screen->post_type || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'Have a written project, screenplay, or manuscript? You can import it and turn it into a structured Story Graph before creating a project manually.', 'worldgraph' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=worldgraph-import' ) ); ?>"><?php esc_html_e( 'Import a written project', 'worldgraph' ); ?></a> <span aria-hidden="true">|</span> <a href="<?php echo esc_url( admin_url( 'admin.php?page=worldgraph-export' ) ); ?>"><?php esc_html_e( 'Export a project', 'worldgraph' ); ?></a></p>
		</div>
		<?php
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
			'label'       => 'Project Visual Direction',
			'required'    => false,
			'description' => 'One concise, pervasive visual language (about 12 words) for every generated image and video. Put the most important medium or rendering style, lighting, palette, contrast, and texture first—not plot, characters, camera movement, or resolution. Example: "hand-painted storybook animation; high-key lighting; soft cel shading; muted earth palette."',
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
		'associates'        => [
			'type'              => 'relationship',
			'label'             => 'Associates',
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
