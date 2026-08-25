<?php
/**
 * Dynamic Story Graph blocks and purpose-built presentation templates.
 *
 * @package WorldGraphChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Story post types owned by the theme presentation layer.
 *
 * @return array<string, array<string, string>>
 */
function worldgraph_child_story_post_types() {
	return array(
		'worldgraph_project'   => array(
			'variant'  => 'project',
			'singular' => __( 'Project', 'worldgraph-child' ),
			'plural'   => __( 'Projects', 'worldgraph-child' ),
		),
		'worldgraph_world'     => array(
			'variant'  => 'world',
			'singular' => __( 'World', 'worldgraph-child' ),
			'plural'   => __( 'Worlds', 'worldgraph-child' ),
		),
		'worldgraph_character' => array(
			'variant'  => 'character',
			'singular' => __( 'Character', 'worldgraph-child' ),
			'plural'   => __( 'Characters', 'worldgraph-child' ),
		),
		'worldgraph_scene'     => array(
			'variant'  => 'scene',
			'singular' => __( 'Scene', 'worldgraph-child' ),
			'plural'   => __( 'Scenes', 'worldgraph-child' ),
		),
		'worldgraph_prop'      => array(
			'variant'  => 'prop',
			'singular' => __( 'Prop', 'worldgraph-child' ),
			'plural'   => __( 'Props', 'worldgraph-child' ),
		),
		'worldgraph_sound'     => array(
			'variant'  => 'sound',
			'singular' => __( 'Sound', 'worldgraph-child' ),
			'plural'   => __( 'Sounds & Songs', 'worldgraph-child' ),
		),
	);
}

/**
 * Read one field through the plugin's SCF-aware accessor.
 *
 * @param int    $post_id    Post ID.
 * @param string $field_name Field name.
 * @return mixed
 */
function worldgraph_child_story_field( $post_id, $field_name ) {
	if ( ! function_exists( '\\WorldGraph\\Utils\\worldgraph_get_field_value' ) ) {
		return '';
	}

	return \WorldGraph\Utils\worldgraph_get_field_value( (int) $post_id, (string) $field_name );
}

/**
 * Return a scalar field value as a trimmed string.
 *
 * @param int    $post_id    Post ID.
 * @param string $field_name Field name.
 * @return string
 */
function worldgraph_child_story_field_text( $post_id, $field_name ) {
	$value = worldgraph_child_story_field( $post_id, $field_name );

	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

/**
 * Turn a stored slug into a display label.
 *
 * @param string $value Stored value.
 * @return string
 */
function worldgraph_child_story_value_label( $value ) {
	return ucwords( str_replace( array( '_', '-' ), ' ', (string) $value ) );
}

/**
 * Safely format editor-authored rich text.
 *
 * @param string $value Stored rich text.
 * @return string
 */
function worldgraph_child_story_rich_text( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}

	return wp_kses_post( wpautop( $value ) );
}

/**
 * Find the first meaningful summary for an item.
 *
 * @param WP_Post       $post   Story post.
 * @param array<string> $fields Preferred field names.
 * @return string
 */
function worldgraph_child_story_summary( $post, $fields ) {
	foreach ( $fields as $field_name ) {
		$value = worldgraph_child_story_field_text( $post->ID, $field_name );
		if ( '' !== $value ) {
			return $value;
		}
	}

	if ( '' !== trim( (string) $post->post_excerpt ) ) {
		return (string) $post->post_excerpt;
	}

	return (string) $post->post_content;
}

/**
 * Shared, calm empty state for missing story data.
 *
 * @param string $title   Empty-state title.
 * @param string $message Optional explanation.
 * @return string
 */
function worldgraph_child_story_empty_state( $title, $message = '' ) {
	$html  = '<div class="wg-story-empty">';
	$html .= '<span class="wg-story-empty__mark" aria-hidden="true">&mdash;</span>';
	$html .= '<p class="wg-story-empty__title">' . esc_html( $title ) . '</p>';
	if ( '' !== $message ) {
		$html .= '<p>' . esc_html( $message ) . '</p>';
	}
	$html .= '</div>';

	return $html;
}

/**
 * Determine a display media item's broad kind.
 *
 * @param array<string, mixed> $item Display media item.
 * @return string
 */
function worldgraph_child_story_media_kind( $item ) {
	$mime_type = strtolower( (string) ( $item['mime_type'] ?? '' ) );
	if ( 0 === strpos( $mime_type, 'image/' ) ) {
		return 'image';
	}
	if ( 0 === strpos( $mime_type, 'audio/' ) ) {
		return 'audio';
	}
	if ( 0 === strpos( $mime_type, 'video/' ) ) {
		return 'video';
	}

	$file_type = wp_check_filetype( (string) wp_parse_url( (string) ( $item['url'] ?? '' ), PHP_URL_PATH ) );
	$mime_type = strtolower( (string) ( $file_type['type'] ?? '' ) );
	if ( 0 === strpos( $mime_type, 'image/' ) ) {
		return 'image';
	}
	if ( 0 === strpos( $mime_type, 'audio/' ) ) {
		return 'audio';
	}
	if ( 0 === strpos( $mime_type, 'video/' ) ) {
		return 'video';
	}

	return 'file';
}

/**
 * Find the first image in a display payload.
 *
 * @param array<int, array<string, mixed>> $media Media items.
 * @return array<string, mixed>|null
 */
function worldgraph_child_story_first_image( $media ) {
	foreach ( (array) $media as $item ) {
		if ( is_array( $item ) && 'image' === worldgraph_child_story_media_kind( $item ) ) {
			return $item;
		}
	}

	return null;
}

/**
 * Render one image or native media player.
 *
 * @param array<string, mixed> $item    Display media item.
 * @param string               $label   Accessible fallback label.
 * @param string               $poster  Optional video poster URL.
 * @return string
 */
function worldgraph_child_story_media_element( $item, $label, $poster = '' ) {
	$url       = esc_url( (string) ( $item['url'] ?? '' ) );
	$mime_type = sanitize_mime_type( (string) ( $item['mime_type'] ?? '' ) );
	$kind      = worldgraph_child_story_media_kind( $item );
	if ( '' === $url ) {
		return '';
	}

	if ( 'image' === $kind ) {
		$alt    = trim( (string) ( $item['alt'] ?? '' ) );
		$alt    = '' !== $alt ? $alt : $label;
		$width  = absint( $item['width'] ?? 0 );
		$height = absint( $item['height'] ?? 0 );
		$size   = $width && $height ? ' width="' . esc_attr( (string) $width ) . '" height="' . esc_attr( (string) $height ) . '"' : '';

		return '<img src="' . $url . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async"' . $size . '>';
	}

	if ( 'audio' === $kind ) {
		return '<audio class="wg-story-player" controls preload="metadata" aria-label="' . esc_attr( $label ) . '"><source src="' . $url . '"' . ( $mime_type ? ' type="' . esc_attr( $mime_type ) . '"' : '' ) . '><a href="' . $url . '">' . esc_html__( 'Open audio file', 'worldgraph-child' ) . '</a></audio>';
	}

	if ( 'video' === $kind ) {
		$poster_attribute = $poster ? ' poster="' . esc_url( $poster ) . '"' : '';

		return '<video class="wg-story-player" controls preload="metadata" playsinline aria-label="' . esc_attr( $label ) . '"' . $poster_attribute . '><source src="' . $url . '"' . ( $mime_type ? ' type="' . esc_attr( $mime_type ) . '"' : '' ) . '><a href="' . $url . '">' . esc_html__( 'Open video file', 'worldgraph-child' ) . '</a></video>';
	}

	return '<a class="wg-story-file" href="' . $url . '">' . esc_html__( 'Open media file', 'worldgraph-child' ) . '</a>';
}

/**
 * Render the first image as a figure.
 *
 * @param array<int, array<string, mixed>> $media Media items.
 * @param string                           $label Accessible fallback label.
 * @param string                           $class Figure class.
 * @return string
 */
function worldgraph_child_story_featured_figure( $media, $label, $class = '' ) {
	$image = worldgraph_child_story_first_image( $media );
	if ( ! $image ) {
		return '';
	}

	return '<figure class="wg-story-figure ' . esc_attr( $class ) . '">' . worldgraph_child_story_media_element( $image, $label ) . '</figure>';
}

/**
 * Render an item title at the correct hierarchy level.
 *
 * @param WP_Post $post   Story post.
 * @param bool    $detail Whether this is a single-item display.
 * @return string
 */
function worldgraph_child_story_title( $post, $detail ) {
	$title = get_the_title( $post );
	if ( $detail ) {
		return '<h1 class="wg-story-title">' . esc_html( $title ) . '</h1>';
	}

	return '<h2 class="wg-story-title"><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $title ) . '</a></h2>';
}

/**
 * Render a compact set of label/value facts.
 *
 * @param array<string, string> $facts Facts keyed by display label.
 * @return string
 */
function worldgraph_child_story_facts( $facts ) {
	$facts = array_filter(
		$facts,
		static function ( $value ) {
			return '' !== trim( (string) $value );
		}
	);
	if ( empty( $facts ) ) {
		return '';
	}

	$html = '<dl class="wg-story-facts">';
	foreach ( $facts as $label => $value ) {
		$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
	}
	$html .= '</dl>';

	return $html;
}

/**
 * Render a rich-text detail section when content exists.
 *
 * @param string $heading Section heading.
 * @param string $content Stored content.
 * @return string
 */
function worldgraph_child_story_detail_section( $heading, $content ) {
	$content = worldgraph_child_story_rich_text( $content );
	if ( '' === $content ) {
		return '';
	}

	return '<section class="wg-story-detail-section"><h2>' . esc_html( $heading ) . '</h2><div class="wg-story-prose">' . $content . '</div></section>';
}

/**
 * Register the server-rendered Story Item and Story Collection blocks.
 *
 * @return void
 */
function worldgraph_child_register_story_blocks() {
	$theme = wp_get_theme();
	wp_register_script(
		'worldgraph-child-story-blocks',
		get_stylesheet_directory_uri() . '/assets/js/story-blocks.js',
		array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		$theme->get( 'Version' ),
		true
	);

	register_block_type(
		'worldgraph/story-item',
		array(
			'api_version'     => 3,
			'title'           => __( 'Story Item', 'worldgraph-child' ),
			'description'     => __( 'Display one Story Graph item using its purpose-built card.', 'worldgraph-child' ),
			'category'        => 'widgets',
			'icon'            => 'book-alt',
			'attributes'      => array(
				'postId'  => array(
					'type'    => 'integer',
					'default' => 0,
				),
				'display' => array(
					'type'    => 'string',
					'default' => 'auto',
				),
			),
			'uses_context'    => array( 'postId' ),
			'supports'        => array(
				'align'           => array( 'wide', 'full' ),
				'html'            => false,
				'customClassName' => true,
			),
			'editor_script'   => 'worldgraph-child-story-blocks',
			'render_callback' => 'worldgraph_child_render_story_item_block',
		)
	);

	register_block_type(
		'worldgraph/story-collection',
		array(
			'api_version'     => 3,
			'title'           => __( 'Story Collection', 'worldgraph-child' ),
			'description'     => __( 'Display a paginated collection of Story Graph cards.', 'worldgraph-child' ),
			'category'        => 'widgets',
			'icon'            => 'screenoptions',
			'attributes'      => array(
				'postType'     => array(
					'type'    => 'string',
					'default' => '',
				),
				'postsPerPage' => array(
					'type'    => 'integer',
					'default' => 12,
				),
				'title'        => array(
					'type'    => 'string',
					'default' => '',
				),
				'showHeading'  => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'supports'        => array(
				'align'           => array( 'wide', 'full' ),
				'html'            => false,
				'customClassName' => true,
			),
			'editor_script'   => 'worldgraph-child-story-blocks',
			'render_callback' => 'worldgraph_child_render_story_collection_block',
		)
	);
}
add_action( 'init', 'worldgraph_child_register_story_blocks', 30 );

/**
 * Whether the current request can contain an interactive story display.
 *
 * @return bool
 */
function worldgraph_child_is_story_display_request() {
	$post_types = array_keys( worldgraph_child_story_post_types() );
	if ( is_singular( $post_types ) || is_post_type_archive( $post_types ) ) {
		return true;
	}

	$post_id = get_queried_object_id();
	if ( ! $post_id ) {
		return false;
	}

	return has_block( 'worldgraph/story-item', $post_id ) || has_block( 'worldgraph/story-collection', $post_id );
}

/**
 * Enqueue the shared interaction script.
 *
 * @return void
 */
function worldgraph_child_enqueue_story_display_script() {
	$theme = wp_get_theme();
	wp_enqueue_script(
		'worldgraph-child-story-display',
		get_stylesheet_directory_uri() . '/assets/js/story-display.js',
		array(),
		$theme->get( 'Version' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}

/**
 * Load interaction code only on story displays.
 *
 * Render callbacks also enqueue the footer script, which covers a Story block
 * placed in a reusable pattern or Query Loop that is not visible to has_block().
 *
 * @return void
 */
function worldgraph_child_enqueue_story_display_assets() {
	if ( worldgraph_child_is_story_display_request() ) {
		worldgraph_child_enqueue_story_display_script();
	}
}
add_action( 'wp_enqueue_scripts', 'worldgraph_child_enqueue_story_display_assets', 20 );

/**
 * Dynamic Story Item block callback.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @param string               $content    Saved fallback content.
 * @param WP_Block             $block      Block instance.
 * @return string
 */
function worldgraph_child_render_story_item_block( $attributes, $content = '', $block = null ) {
	worldgraph_child_enqueue_story_display_script();

	$post_id = absint( $attributes['postId'] ?? 0 );
	if ( ! $post_id && $block instanceof WP_Block ) {
		$post_id = absint( $block->context['postId'] ?? 0 );
	}
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}

	$display = sanitize_key( (string) ( $attributes['display'] ?? 'auto' ) );
	$detail  = 'detail' === $display || ( 'card' !== $display && is_singular() && get_queried_object_id() === $post_id );
	$html    = worldgraph_child_render_story_item( $post_id, $detail );
	if ( '' === $html ) {
		$html = worldgraph_child_story_empty_state(
			__( 'Story item unavailable', 'worldgraph-child' ),
			__( 'This item may have been moved or is not available to view.', 'worldgraph-child' )
		);
	}

	$wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
		? get_block_wrapper_attributes( array( 'class' => 'wg-story-block' ) )
		: 'class="wp-block-worldgraph-story-item wg-story-block"';

	return '<div ' . $wrapper_attributes . '>' . $html . '</div>';
}

/**
 * Resolve a Story Collection's requested post type.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string
 */
function worldgraph_child_story_collection_post_type( $attributes ) {
	$post_types = worldgraph_child_story_post_types();
	$requested  = sanitize_key( (string) ( $attributes['postType'] ?? '' ) );
	if ( isset( $post_types[ $requested ] ) ) {
		return $requested;
	}

	$queried = get_queried_object();
	if ( $queried instanceof WP_Post_Type && isset( $post_types[ $queried->name ] ) ) {
		return $queried->name;
	}

	return '';
}

/**
 * Dynamic Story Collection block callback.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string
 */
function worldgraph_child_render_story_collection_block( $attributes ) {
	worldgraph_child_enqueue_story_display_script();

	$post_types = worldgraph_child_story_post_types();
	$post_type  = worldgraph_child_story_collection_post_type( $attributes );
	if ( ! $post_type ) {
		return worldgraph_child_story_empty_state(
			__( 'Choose a story collection', 'worldgraph-child' ),
			__( 'Select Projects, Worlds, Characters, Scenes, Props, or Sounds.', 'worldgraph-child' )
		);
	}

	$posts_per_page = min( 48, max( 1, absint( $attributes['postsPerPage'] ?? 12 ) ) );
	$paged          = max( 1, absint( get_query_var( 'paged' ) ), absint( get_query_var( 'page' ) ) );
	$query          = new WP_Query(
		array(
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => $posts_per_page,
			'paged'               => $paged,
			'ignore_sticky_posts' => true,
			'orderby'             => 'title',
			'order'               => 'ASC',
		)
	);

	$title       = trim( (string) ( $attributes['title'] ?? '' ) );
	$title       = $title ? $title : $post_types[ $post_type ]['plural'];
	$heading_id  = wp_unique_id( 'wg-story-collection-title-' );
	$show_heading = ! isset( $attributes['showHeading'] ) || (bool) $attributes['showHeading'];
	$variant     = $post_types[ $post_type ]['variant'];
	$wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
		? get_block_wrapper_attributes( array( 'class' => 'wg-story-collection-block' ) )
		: 'class="wp-block-worldgraph-story-collection wg-story-collection-block"';

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated by core. ?>>
		<section class="wg-story-collection wg-story-collection--<?php echo esc_attr( $variant ); ?>"<?php echo $show_heading ? ' aria-labelledby="' . esc_attr( $heading_id ) . '"' : ' aria-label="' . esc_attr( $title ) . '"'; ?>>
			<?php if ( $show_heading ) : ?>
				<header class="wg-story-collection__header">
					<p class="wg-story-kicker"><?php esc_html_e( 'Story Graph', 'worldgraph-child' ); ?></p>
					<h1 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $title ); ?></h1>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of published items. */
								_n( '%s published item', '%s published items', (int) $query->found_posts, 'worldgraph-child' ),
								number_format_i18n( (int) $query->found_posts )
							)
						);
						?>
					</p>
				</header>
			<?php endif; ?>

			<?php if ( $query->have_posts() ) : ?>
				<div class="wg-story-grid" role="list">
					<?php
					while ( $query->have_posts() ) {
						$query->the_post();
						echo '<div class="wg-story-grid__item" role="listitem">';
						echo worldgraph_child_render_story_item( get_the_ID(), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes fields.
						echo '</div>';
					}
					?>
				</div>
				<?php if ( $query->max_num_pages > 1 ) : ?>
					<nav class="wg-story-pagination" aria-label="<?php esc_attr_e( 'Story collection pages', 'worldgraph-child' ); ?>">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'current'   => $paged,
									'total'     => (int) $query->max_num_pages,
									'mid_size'  => 1,
									'prev_text' => __( 'Previous', 'worldgraph-child' ),
									'next_text' => __( 'Next', 'worldgraph-child' ),
								)
							)
						);
						?>
					</nav>
				<?php endif; ?>
			<?php else : ?>
				<?php
				echo worldgraph_child_story_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields.
					sprintf(
						/* translators: %s: plural story item type. */
						__( 'No %s yet', 'worldgraph-child' ),
						strtolower( $post_types[ $post_type ]['plural'] )
					),
					__( 'Published items will collect here automatically.', 'worldgraph-child' )
				);
				?>
			<?php endif; ?>
		</section>
	</div>
	<?php
	wp_reset_postdata();

	return (string) ob_get_clean();
}

/**
 * Route one Story Graph item to its purpose-built renderer.
 *
 * @param int  $post_id Post ID.
 * @param bool $detail  Whether to render the expanded display.
 * @return string
 */
function worldgraph_child_render_story_item( $post_id, $detail = false ) {
	$post       = get_post( $post_id );
	$post_types = worldgraph_child_story_post_types();
	if ( ! $post instanceof WP_Post || ! isset( $post_types[ $post->post_type ] ) ) {
		return '';
	}
	if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
		return '';
	}
	if ( post_password_required( $post ) ) {
		return '<article class="wg-story-item wg-story-item--protected">' . get_the_password_form( $post ) . '</article>';
	}
	if ( ! function_exists( '\\WorldGraph\\Utils\\worldgraph_get_story_display_payload' ) ) {
		return worldgraph_child_story_empty_state(
			__( 'Display data unavailable', 'worldgraph-child' ),
			__( 'Activate World Graph Studio to resolve this story item.', 'worldgraph-child' )
		);
	}

	$expanded        = $detail;
	$include_private = current_user_can( 'edit_post', $post->ID );
	$payload         = \WorldGraph\Utils\worldgraph_get_story_display_payload( $post->ID, $expanded, $include_private );

	switch ( $post->post_type ) {
		case 'worldgraph_project':
			return worldgraph_child_render_project_story( $post, $payload, $detail );
		case 'worldgraph_world':
			return worldgraph_child_render_world_story( $post, $payload, $detail );
		case 'worldgraph_character':
			return worldgraph_child_render_character_story( $post, $payload, $detail );
		case 'worldgraph_scene':
			return worldgraph_child_render_scene_story( $post, $payload, $detail );
		case 'worldgraph_prop':
			return worldgraph_child_render_prop_story( $post, $payload, $detail );
		case 'worldgraph_sound':
			return worldgraph_child_render_sound_story( $post, $payload, $detail );
	}

	return '';
}

/**
 * Render a Project card or production dashboard.
 *
 * @param WP_Post              $post    Project post.
 * @param array<string, mixed> $payload Display payload.
 * @param bool                 $detail  Expanded display flag.
 * @return string
 */
function worldgraph_child_render_project_story( $post, $payload, $detail ) {
	$project     = is_array( $payload['project'] ?? null ) ? $payload['project'] : array();
	$media       = (array) ( $payload['media'] ?? array() );
	$summary     = worldgraph_child_story_summary( $post, array( 'description' ) );
	$stage       = sanitize_key( (string) ( $project['stage'] ?? '' ) );
	$stage_label = trim( (string) ( $project['stage_label'] ?? '' ) );
	$status      = trim( (string) ( $project['status_label'] ?? '' ) );
	$stages      = array(
		'concept'         => __( 'Concept', 'worldgraph-child' ),
		'development'     => __( 'Development', 'worldgraph-child' ),
		'pre_production'  => __( 'Pre-production', 'worldgraph-child' ),
		'production'      => __( 'Production', 'worldgraph-child' ),
		'post_production' => __( 'Post-production', 'worldgraph-child' ),
		'released'        => __( 'Released', 'worldgraph-child' ),
	);
	$frame_width  = worldgraph_child_story_field_text( $post->ID, 'frame_width' );
	$frame_height = worldgraph_child_story_field_text( $post->ID, 'frame_height' );
	$frame        = $frame_width && $frame_height ? $frame_width . ' × ' . $frame_height : ( $frame_width ? $frame_width : $frame_height );

	ob_start();
	?>
	<article class="wg-story-item wg-story-item--project <?php echo $detail ? 'is-detail' : 'is-card'; ?>">
		<div class="wg-project-hero">
			<?php echo worldgraph_child_story_featured_figure( $media, get_the_title( $post ), 'wg-project-hero__media' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes media. ?>
			<div class="wg-project-hero__body">
				<p class="wg-story-kicker"><?php esc_html_e( 'Production dossier', 'worldgraph-child' ); ?></p>
				<?php echo worldgraph_child_story_title( $post, $detail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes title. ?>
				<?php if ( $summary ) : ?>
					<div class="wg-story-summary"><?php echo $detail ? worldgraph_child_story_rich_text( $summary ) : esc_html( wp_trim_words( wp_strip_all_tags( $summary ), 34 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches escape. ?></div>
				<?php endif; ?>

				<?php if ( $status || $stage_label ) : ?>
					<dl class="wg-project-state">
						<?php if ( $status ) : ?><div><dt><?php esc_html_e( 'Completion status', 'worldgraph-child' ); ?></dt><dd><?php echo esc_html( $status ); ?></dd></div><?php endif; ?>
						<?php if ( $stage_label ) : ?><div><dt><?php esc_html_e( 'Production stage', 'worldgraph-child' ); ?></dt><dd><?php echo esc_html( $stage_label ); ?></dd></div><?php endif; ?>
					</dl>
				<?php else : ?>
					<?php echo worldgraph_child_story_empty_state( __( 'Project status not set', 'worldgraph-child' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields. ?>
				<?php endif; ?>
				<?php if ( ! $detail ) : ?>
					<a class="wg-story-link" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php esc_html_e( 'Open project dossier', 'worldgraph-child' ); ?><span aria-hidden="true"> &rarr;</span></a>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $detail ) : ?>
			<section class="wg-project-stages" aria-labelledby="wg-project-stages-<?php echo esc_attr( (string) $post->ID ); ?>">
				<h2 id="wg-project-stages-<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Production path', 'worldgraph-child' ); ?></h2>
				<ol>
					<?php foreach ( $stages as $stage_key => $label ) : ?>
						<li<?php echo $stage_key === $stage ? ' class="is-current" aria-current="step"' : ''; ?>><span><?php echo esc_html( $label ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			</section>

			<?php
			echo worldgraph_child_story_facts( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields.
				array(
					__( 'Medium', 'worldgraph-child' )       => worldgraph_child_story_value_label( worldgraph_child_story_field_text( $post->ID, 'target_medium' ) ),
					__( 'Start date', 'worldgraph-child' )   => worldgraph_child_story_field_text( $post->ID, 'start_date' ),
					__( 'Target date', 'worldgraph-child' )  => worldgraph_child_story_field_text( $post->ID, 'end_date' ),
					__( 'Frame', 'worldgraph-child' )        => $frame,
					__( 'Aspect ratio', 'worldgraph-child' ) => worldgraph_child_story_field_text( $post->ID, 'aspect_ratio' ),
					__( 'Frame rate', 'worldgraph-child' )   => worldgraph_child_story_field_text( $post->ID, 'frame_rate' ) ? worldgraph_child_story_field_text( $post->ID, 'frame_rate' ) . ' fps' : '',
				)
			);
			?>

			<?php $analytics = is_array( $project['analytics'] ?? null ) ? $project['analytics'] : array(); ?>
			<section class="wg-project-analytics" aria-labelledby="wg-project-analytics-<?php echo esc_attr( (string) $post->ID ); ?>">
				<h2 id="wg-project-analytics-<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Story analysis snapshot', 'worldgraph-child' ); ?></h2>
				<?php if ( ! empty( $analytics ) ) : ?>
					<div class="wg-metric-grid">
						<div><strong><?php echo esc_html( number_format_i18n( (int) ( $analytics['total_entities'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Entities', 'worldgraph-child' ); ?></span></div>
						<div><strong><?php echo esc_html( number_format_i18n( (int) ( $analytics['total_relationships'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Connections', 'worldgraph-child' ); ?></span></div>
						<div><strong><?php echo esc_html( number_format_i18n( (float) ( $analytics['density'] ?? 0 ) * 100, 1 ) . '%' ); ?></strong><span><?php esc_html_e( 'Graph density', 'worldgraph-child' ); ?></span></div>
						<div><strong><?php echo esc_html( number_format_i18n( (int) ( $analytics['isolated_count'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Isolated items', 'worldgraph-child' ); ?></span></div>
					</div>
					<?php if ( ! empty( $analytics['entity_counts'] ) ) : ?>
						<ul class="wg-entity-counts" aria-label="<?php esc_attr_e( 'Entity counts by type', 'worldgraph-child' ); ?>">
							<?php foreach ( (array) $analytics['entity_counts'] as $entity_type => $count ) : ?>
								<?php
								$entity_type = sanitize_key( (string) $entity_type );
								$entity_url  = 'worldgraph_project' === $entity_type ? get_permalink( $post ) : get_post_type_archive_link( $entity_type );
								$entity_name = worldgraph_child_story_value_label( str_replace( 'worldgraph_', '', $entity_type ) );
								?>
								<li>
									<?php if ( $entity_url ) : ?><a class="wg-entity-count-link" href="<?php echo esc_url( $entity_url ); ?>"><span><?php echo esc_html( $entity_name ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></strong></a><?php else : ?><span><?php echo esc_html( $entity_name ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></strong><?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php else : ?>
					<?php echo worldgraph_child_story_empty_state( __( 'No analysis available yet', 'worldgraph-child' ), __( 'Connect story items to populate this snapshot.', 'worldgraph-child' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields. ?>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</article>
	<?php

	return (string) ob_get_clean();
}

/**
 * Render a World as a spacious atlas card or reference spread.
 *
 * @param WP_Post              $post    World post.
 * @param array<string, mixed> $payload Display payload.
 * @param bool                 $detail  Expanded display flag.
 * @return string
 */
function worldgraph_child_render_world_story( $post, $payload, $detail ) {
	$media   = (array) ( $payload['media'] ?? array() );
	$summary = worldgraph_child_story_summary( $post, array( 'synopsis' ) );
	$counts  = is_array( $payload['relationship_counts'] ?? null ) ? $payload['relationship_counts'] : array();

	ob_start();
	?>
	<article class="wg-story-item wg-story-item--world <?php echo $detail ? 'is-detail' : 'is-card'; ?>">
		<div class="wg-world-hero">
			<?php echo worldgraph_child_story_featured_figure( $media, get_the_title( $post ), 'wg-world-hero__media' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes media. ?>
			<div class="wg-world-hero__body">
				<p class="wg-story-kicker"><?php esc_html_e( 'World atlas', 'worldgraph-child' ); ?></p>
				<?php echo worldgraph_child_story_title( $post, $detail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes title. ?>
				<?php if ( $summary ) : ?>
					<div class="wg-story-summary"><?php echo $detail ? worldgraph_child_story_rich_text( $summary ) : esc_html( wp_trim_words( wp_strip_all_tags( $summary ), 48 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches escape. ?></div>
				<?php endif; ?>
				<?php if ( $counts ) : ?>
					<ul class="wg-world-counts" aria-label="<?php esc_attr_e( 'Connected story items', 'worldgraph-child' ); ?>">
						<?php foreach ( $counts as $related_type => $count ) : ?>
							<li><strong><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></strong><span><?php echo esc_html( worldgraph_child_story_value_label( str_replace( 'worldgraph_', '', (string) $related_type ) ) ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! $detail ) : ?>
					<a class="wg-story-link" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php esc_html_e( 'Explore this world', 'worldgraph-child' ); ?><span aria-hidden="true"> &rarr;</span></a>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $detail ) : ?>
			<?php if ( count( $media ) > 1 ) : ?>
				<div class="wg-world-media-grid" aria-label="<?php esc_attr_e( 'World reference gallery', 'worldgraph-child' ); ?>">
					<?php foreach ( array_slice( $media, 1, 4 ) as $item ) : ?>
						<?php if ( is_array( $item ) && 'image' === worldgraph_child_story_media_kind( $item ) ) : ?>
							<figure><?php echo worldgraph_child_story_media_element( $item, get_the_title( $post ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes media. ?></figure>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="wg-world-sections">
				<?php
				$sections = array(
					__( 'World rules', 'worldgraph-child' ) => worldgraph_child_story_field_text( $post->ID, 'rules' ),
					__( 'Geography', 'worldgraph-child' )   => worldgraph_child_story_field_text( $post->ID, 'geography' ),
					__( 'Timeline', 'worldgraph-child' )    => worldgraph_child_story_field_text( $post->ID, 'timeline' ),
					__( 'Themes', 'worldgraph-child' )      => worldgraph_child_story_field_text( $post->ID, 'themes' ),
					__( 'References', 'worldgraph-child' )  => worldgraph_child_story_field_text( $post->ID, 'references' ),
				);
				$rendered = false;
				foreach ( $sections as $heading => $content ) {
					$section = worldgraph_child_story_detail_section( $heading, $content );
					if ( $section ) {
						$rendered = true;
						echo $section; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields.
					}
				}
				if ( ! $rendered ) {
					echo worldgraph_child_story_empty_state( __( 'The atlas is still taking shape', 'worldgraph-child' ), __( 'World rules, geography, timeline, and themes will appear here.', 'worldgraph-child' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields.
				}
				?>
			</div>
		<?php endif; ?>
	</article>
	<?php

	return (string) ob_get_clean();
}

/**
 * Render a Character as an accessible, flippable profile card.
 *
 * @param WP_Post              $post    Character post.
 * @param array<string, mixed> $payload Display payload.
 * @param bool                 $detail  Expanded display flag.
 * @return string
 */
function worldgraph_child_render_character_story( $post, $payload, $detail ) {
	$media       = (array) ( $payload['media'] ?? array() );
	$image       = worldgraph_child_story_first_image( $media );
	$name        = get_the_title( $post );
	$age         = worldgraph_child_story_field_text( $post->ID, 'age' );
	$voice       = worldgraph_child_story_field_text( $post->ID, 'voice_profile' );
	$biography   = worldgraph_child_story_summary( $post, array( 'biography', 'backstory' ) );
	$personality = worldgraph_child_story_field_text( $post->ID, 'personality' );
	$motivation  = worldgraph_child_story_field_text( $post->ID, 'motivation' );
	$card_id     = wp_unique_id( 'wg-character-card-' );
	$front_id    = $card_id . '-front';
	$back_id     = $card_id . '-back';
	$initial     = strtoupper( substr( wp_strip_all_tags( $name ), 0, 1 ) );

	ob_start();
	?>
	<article class="wg-story-item wg-story-item--character <?php echo $detail ? 'is-detail' : 'is-card'; ?>">
		<div id="<?php echo esc_attr( $card_id ); ?>" class="wg-flip-card" data-wg-flip-card>
			<div class="wg-flip-card__inner">
				<section id="<?php echo esc_attr( $front_id ); ?>" class="wg-flip-card__face wg-flip-card__front" data-wg-flip-face="front">
					<p class="wg-story-kicker"><?php esc_html_e( 'Character profile', 'worldgraph-child' ); ?></p>
					<div class="wg-character-portrait">
						<?php if ( $image ) : ?>
							<?php echo worldgraph_child_story_media_element( $image, sprintf( __( 'Portrait of %s', 'worldgraph-child' ), $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes media. ?>
						<?php else : ?>
							<div class="wg-character-portrait__placeholder"><span aria-hidden="true"><?php echo esc_html( $initial ); ?></span><span class="screen-reader-text"><?php esc_html_e( 'No portrait attached', 'worldgraph-child' ); ?></span></div>
						<?php endif; ?>
					</div>
					<div class="wg-character-card__identity">
						<?php echo worldgraph_child_story_title( $post, $detail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes title. ?>
						<?php if ( $age || $voice ) : ?>
							<p><?php echo esc_html( implode( ' / ', array_filter( array( $age ? sprintf( __( 'Age %s', 'worldgraph-child' ), $age ) : '', $voice ) ) ) ); ?></p>
						<?php endif; ?>
					</div>
					<button class="wg-flip-control" type="button" data-wg-flip-control aria-controls="<?php echo esc_attr( $back_id ); ?>" aria-expanded="false" data-front-label="<?php esc_attr_e( 'Show profile details', 'worldgraph-child' ); ?>" data-back-label="<?php esc_attr_e( 'Show portrait', 'worldgraph-child' ); ?>">
						<span><?php esc_html_e( 'Flip for profile', 'worldgraph-child' ); ?></span><span aria-hidden="true">&#8635;</span>
					</button>
				</section>

				<section id="<?php echo esc_attr( $back_id ); ?>" class="wg-flip-card__face wg-flip-card__back" data-wg-flip-face="back" aria-hidden="true" inert>
					<p class="wg-story-kicker"><?php esc_html_e( 'Profile notes', 'worldgraph-child' ); ?></p>
					<h2 class="wg-character-card__name"><?php echo esc_html( $name ); ?></h2>
					<?php if ( $biography ) : ?>
						<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $biography ), $detail ? 70 : 40 ) ); ?></p>
					<?php elseif ( $personality || $motivation ) : ?>
						<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $personality ? $personality : $motivation ), 40 ) ); ?></p>
					<?php else : ?>
						<?php echo worldgraph_child_story_empty_state( __( 'Profile notes not added yet', 'worldgraph-child' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields. ?>
					<?php endif; ?>
					<?php if ( ! $detail ) : ?>
						<a class="wg-story-link" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php esc_html_e( 'Open full profile', 'worldgraph-child' ); ?><span aria-hidden="true"> &rarr;</span></a>
					<?php endif; ?>
					<button class="wg-flip-control" type="button" data-wg-flip-control aria-controls="<?php echo esc_attr( $front_id ); ?>" aria-expanded="true" data-front-label="<?php esc_attr_e( 'Show profile details', 'worldgraph-child' ); ?>" data-back-label="<?php esc_attr_e( 'Show portrait', 'worldgraph-child' ); ?>">
						<span><?php esc_html_e( 'Return to portrait', 'worldgraph-child' ); ?></span><span aria-hidden="true">&#8634;</span>
					</button>
				</section>
			</div>
		</div>

		<?php if ( $detail ) : ?>
			<?php
			echo worldgraph_child_story_facts( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields.
				array(
					__( 'Age', 'worldgraph-child' )   => $age,
					__( 'Voice', 'worldgraph-child' ) => $voice,
				)
			);
			?>
			<div class="wg-character-sections">
				<?php
				$sections = array(
					__( 'Biography', 'worldgraph-child' )  => worldgraph_child_story_field_text( $post->ID, 'biography' ),
					__( 'Appearance', 'worldgraph-child' ) => worldgraph_child_story_field_text( $post->ID, 'appearance' ),
					__( 'Personality', 'worldgraph-child' ) => $personality,
					__( 'Motivation', 'worldgraph-child' )  => $motivation,
					__( 'Backstory', 'worldgraph-child' )   => worldgraph_child_story_field_text( $post->ID, 'backstory' ),
				);
				$rendered = false;
				foreach ( $sections as $heading => $content ) {
					$section = worldgraph_child_story_detail_section( $heading, $content );
					if ( $section ) {
						$rendered = true;
						echo $section; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields.
					}
				}
				if ( ! $rendered ) {
					echo worldgraph_child_story_empty_state( __( 'This profile is ready for notes', 'worldgraph-child' ), __( 'Biography, appearance, personality, motivation, and backstory will appear here.', 'worldgraph-child' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields.
				}
				?>
			</div>
		<?php endif; ?>
	</article>
	<?php

	return (string) ob_get_clean();
}

/**
 * Render Shot records in a Scene's editorial order.
 *
 * @param array<int, array<string, mixed>> $shots Scene Shot payloads.
 * @param int                              $scene_id Scene post ID.
 * @return string
 */
function worldgraph_child_story_shot_sequence( $shots, $scene_id ) {
	if ( empty( $shots ) ) {
		return worldgraph_child_story_empty_state(
			__( 'No shots in this scene yet', 'worldgraph-child' ),
			current_user_can( 'edit_post', $scene_id )
				? __( 'Add shots in the editor, then drag them into cut order.', 'worldgraph-child' )
				: __( 'The shot sequence will appear here when it is ready.', 'worldgraph-child' )
		);
	}

	ob_start();
	?>
	<ol class="wg-shot-sequence">
		<?php foreach ( $shots as $index => $shot ) : ?>
			<?php
			$meta        = is_array( $shot['meta'] ?? null ) ? $shot['meta'] : array();
			$shot_number = absint( $meta['shot_number'] ?? 0 );
			$shot_number = $shot_number ? $shot_number : $index + 1;
			$shot_title  = trim( (string) ( $shot['title'] ?? '' ) );
			$description = trim( (string) ( $meta['shot_description'] ?? $shot['excerpt'] ?? '' ) );
			$shot_media  = (array) ( $shot['media'] ?? array() );
			?>
			<li class="wg-shot-card">
				<div class="wg-shot-card__number" aria-label="<?php echo esc_attr( sprintf( __( 'Shot %s', 'worldgraph-child' ), $shot_number ) ); ?>"><?php echo esc_html( str_pad( (string) $shot_number, 2, '0', STR_PAD_LEFT ) ); ?></div>
				<?php echo worldgraph_child_story_featured_figure( $shot_media, $shot_title, 'wg-shot-card__media' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes media. ?>
				<div class="wg-shot-card__body">
					<h3><?php echo esc_html( $shot_title ? $shot_title : sprintf( __( 'Shot %s', 'worldgraph-child' ), $shot_number ) ); ?></h3>
					<?php if ( $description ) : ?><div class="wg-shot-card__description"><?php echo worldgraph_child_story_rich_text( $description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper sanitizes HTML. ?></div><?php endif; ?>
					<ul class="wg-shot-card__meta" aria-label="<?php esc_attr_e( 'Shot details', 'worldgraph-child' ); ?>">
						<?php foreach ( array( 'shot_type', 'camera_angle', 'lens', 'duration', 'take_number', 'slate_id' ) as $field_name ) : ?>
							<?php if ( isset( $meta[ $field_name ] ) && '' !== trim( (string) $meta[ $field_name ] ) ) : ?>
								<li><span class="screen-reader-text"><?php echo esc_html( worldgraph_child_story_value_label( $field_name ) . ': ' ); ?></span><?php echo esc_html( worldgraph_child_story_value_label( (string) $meta[ $field_name ] ) ); ?></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php

	return (string) ob_get_clean();
}

/**
 * Render a Scene card or ordered Shot breakdown.
 *
 * @param WP_Post              $post    Scene post.
 * @param array<string, mixed> $payload Display payload.
 * @param bool                 $detail  Expanded display flag.
 * @return string
 */
function worldgraph_child_render_scene_story( $post, $payload, $detail ) {
	$media        = (array) ( $payload['media'] ?? array() );
	$shots        = (array) ( $payload['shots'] ?? array() );
	$summary      = worldgraph_child_story_summary( $post, array( 'summary' ) );
	$scene_number = worldgraph_child_story_field_text( $post->ID, 'scene_number' );
	$time_of_day  = worldgraph_child_story_field_text( $post->ID, 'time_of_day' );
	$tone         = worldgraph_child_story_field_text( $post->ID, 'emotional_tone' );

	ob_start();
	?>
	<article class="wg-story-item wg-story-item--scene <?php echo $detail ? 'is-detail' : 'is-card'; ?>">
		<header class="wg-scene-header">
			<div>
				<p class="wg-story-kicker"><?php echo esc_html( $scene_number ? sprintf( __( 'Scene %s', 'worldgraph-child' ), $scene_number ) : __( 'Scene', 'worldgraph-child' ) ); ?></p>
				<?php echo worldgraph_child_story_title( $post, $detail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes title. ?>
			</div>
			<?php if ( $time_of_day || $tone ) : ?>
				<div class="wg-story-tags">
					<?php if ( $time_of_day ) : ?><span><?php echo esc_html( worldgraph_child_story_value_label( $time_of_day ) ); ?></span><?php endif; ?>
					<?php if ( $tone ) : ?><span><?php echo esc_html( $tone ); ?></span><?php endif; ?>
				</div>
			<?php endif; ?>
		</header>
		<?php if ( $summary ) : ?>
			<div class="wg-story-summary"><?php echo $detail ? worldgraph_child_story_rich_text( $summary ) : esc_html( wp_trim_words( wp_strip_all_tags( $summary ), 32 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches escape. ?></div>
		<?php endif; ?>

		<?php if ( $detail ) : ?>
			<section class="wg-scene-shots" aria-labelledby="wg-scene-shots-<?php echo esc_attr( (string) $post->ID ); ?>">
				<div class="wg-scene-shots__header">
					<h2 id="wg-scene-shots-<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Shot sequence', 'worldgraph-child' ); ?></h2>
					<span><?php echo esc_html( sprintf( _n( '%s shot', '%s shots', count( $shots ), 'worldgraph-child' ), number_format_i18n( count( $shots ) ) ) ); ?></span>
				</div>
				<?php echo worldgraph_child_story_shot_sequence( $shots, $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes fields. ?>
			</section>
			<?php echo worldgraph_child_story_detail_section( __( 'Script', 'worldgraph-child' ), worldgraph_child_story_field_text( $post->ID, 'script_content' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper sanitizes HTML. ?>
			<?php echo worldgraph_child_story_detail_section( __( 'Production notes', 'worldgraph-child' ), worldgraph_child_story_field_text( $post->ID, 'production_notes' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper sanitizes HTML. ?>
		<?php else : ?>
			<?php
			$figure = worldgraph_child_story_featured_figure( $media, get_the_title( $post ), 'wg-scene-card__media' );
			echo $figure ? $figure : worldgraph_child_story_empty_state( __( 'Scene media pending', 'worldgraph-child' ), __( 'Open the Scene to view its published Shot sequence.', 'worldgraph-child' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both helpers escape fields.
			?>
			<a class="wg-story-link" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php esc_html_e( 'Open shot sequence', 'worldgraph-child' ); ?><span aria-hidden="true"> &rarr;</span></a>
		<?php endif; ?>
	</article>
	<?php

	return (string) ob_get_clean();
}

/**
 * Render an interactive, accessible multi-frame media gallery.
 *
 * @param array<int, array<string, mixed>> $media Media items.
 * @param string                           $label Gallery label.
 * @return string
 */
function worldgraph_child_story_gallery( $media, $label ) {
	$media = array_values(
		array_filter(
			(array) $media,
			static function ( $item ) {
				return is_array( $item ) && ! empty( $item['url'] );
			}
		)
	);
	if ( empty( $media ) ) {
		return worldgraph_child_story_empty_state(
			__( 'No reference frames attached', 'worldgraph-child' ),
			__( 'Add featured or gallery media to build this detail view.', 'worldgraph-child' )
		);
	}

	$gallery_id = wp_unique_id( 'wg-story-gallery-' );
	$has_tabs   = count( $media ) > 1;

	ob_start();
	?>
	<div id="<?php echo esc_attr( $gallery_id ); ?>" class="wg-story-gallery" data-wg-gallery>
		<div class="wg-story-gallery__stage">
			<?php foreach ( $media as $index => $item ) : ?>
				<?php
				$panel_id = $gallery_id . '-panel-' . $index;
				$tab_id   = $gallery_id . '-tab-' . $index;
				$title    = trim( (string) ( $item['title'] ?? '' ) );
				$caption  = trim( (string) ( $item['caption'] ?? '' ) );
				?>
				<figure id="<?php echo esc_attr( $panel_id ); ?>" class="wg-story-gallery__panel" data-wg-gallery-panel data-wg-gallery-index="<?php echo esc_attr( (string) $index ); ?>"<?php echo $has_tabs ? ' role="tabpanel" aria-labelledby="' . esc_attr( $tab_id ) . '"' : ''; ?><?php echo 0 === $index ? '' : ' hidden'; ?>>
					<?php echo worldgraph_child_story_media_element( $item, $title ? $title : $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes media. ?>
					<?php if ( $title || $caption ) : ?>
						<figcaption><?php if ( $title ) : ?><strong><?php echo esc_html( $title ); ?></strong><?php endif; ?><?php if ( $caption ) : ?><span><?php echo esc_html( $caption ); ?></span><?php endif; ?></figcaption>
					<?php endif; ?>
				</figure>
			<?php endforeach; ?>
		</div>

		<?php if ( $has_tabs ) : ?>
			<div class="wg-story-gallery__tabs" role="tablist" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php foreach ( $media as $index => $item ) : ?>
					<?php
					$panel_id = $gallery_id . '-panel-' . $index;
					$tab_id   = $gallery_id . '-tab-' . $index;
					$thumb    = esc_url( (string) ( $item['thumbnail_url'] ?? '' ) );
					$title    = trim( (string) ( $item['title'] ?? '' ) );
					$tab_text = sprintf(
						/* translators: 1: frame number, 2: total frame count, 3: media title. */
						__( 'View frame %1$s of %2$s%3$s', 'worldgraph-child' ),
						number_format_i18n( $index + 1 ),
						number_format_i18n( count( $media ) ),
						$title ? ': ' . $title : ''
					);
					?>
					<button id="<?php echo esc_attr( $tab_id ); ?>" type="button" role="tab" data-wg-gallery-trigger data-wg-gallery-index="<?php echo esc_attr( (string) $index ); ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>" tabindex="<?php echo 0 === $index ? '0' : '-1'; ?>">
						<?php if ( $thumb && 'image' === worldgraph_child_story_media_kind( $item ) ) : ?>
							<img src="<?php echo $thumb; ?>" alt="" loading="lazy">
						<?php else : ?>
							<span aria-hidden="true"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
						<?php endif; ?>
						<span class="screen-reader-text"><?php echo esc_html( $tab_text ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<p class="screen-reader-text" data-wg-gallery-status aria-live="polite"></p>
		<?php endif; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * Render a Prop as an object card or multi-frame detail sheet.
 *
 * @param WP_Post              $post    Prop post.
 * @param array<string, mixed> $payload Display payload.
 * @param bool                 $detail  Expanded display flag.
 * @return string
 */
function worldgraph_child_render_prop_story( $post, $payload, $detail ) {
	$media       = (array) ( $payload['media'] ?? array() );
	$description = worldgraph_child_story_summary( $post, array( 'description' ) );
	$purpose     = worldgraph_child_story_field_text( $post->ID, 'purpose' );
	$notes       = worldgraph_child_story_field_text( $post->ID, 'notes' );

	ob_start();
	?>
	<article class="wg-story-item wg-story-item--prop <?php echo $detail ? 'is-detail' : 'is-card'; ?>">
		<header class="wg-prop-header">
			<p class="wg-story-kicker"><?php esc_html_e( 'Prop reference', 'worldgraph-child' ); ?></p>
			<?php echo worldgraph_child_story_title( $post, $detail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes title. ?>
			<?php if ( $purpose ) : ?><p class="wg-prop-purpose"><?php echo esc_html( $purpose ); ?></p><?php endif; ?>
		</header>

		<?php if ( $detail ) : ?>
			<?php echo worldgraph_child_story_gallery( $media, sprintf( __( '%s reference frames', 'worldgraph-child' ), get_the_title( $post ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes fields. ?>
			<div class="wg-prop-details">
				<?php echo worldgraph_child_story_detail_section( __( 'Description', 'worldgraph-child' ), $description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper sanitizes HTML. ?>
				<?php echo worldgraph_child_story_detail_section( __( 'Continuity notes', 'worldgraph-child' ), $notes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper sanitizes HTML. ?>
			</div>
		<?php else : ?>
			<?php
			$figure = worldgraph_child_story_featured_figure( $media, get_the_title( $post ), 'wg-prop-card__media' );
			echo $figure ? $figure : worldgraph_child_story_empty_state( __( 'No reference frame', 'worldgraph-child' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both helpers escape fields.
			?>
			<?php if ( $description ) : ?><p class="wg-story-summary"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $description ), 30 ) ); ?></p><?php endif; ?>
			<a class="wg-story-link" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php esc_html_e( 'Inspect prop details', 'worldgraph-child' ); ?><span aria-hidden="true"> &rarr;</span></a>
		<?php endif; ?>
	</article>
	<?php

	return (string) ob_get_clean();
}

/**
 * Get the first term name for a story taxonomy.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy name.
 * @return string
 */
function worldgraph_child_story_first_term_name( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}

	return (string) $terms[0]->name;
}

/**
 * Render Sound and Music records with native media controls.
 *
 * @param WP_Post              $post    Sound post.
 * @param array<string, mixed> $payload Display payload.
 * @param bool                 $detail  Expanded display flag.
 * @return string
 */
function worldgraph_child_render_sound_story( $post, $payload, $detail ) {
	$media       = (array) ( $payload['media'] ?? array() );
	$playable    = array_values(
		array_filter(
			$media,
			static function ( $item ) {
				return is_array( $item ) && in_array( worldgraph_child_story_media_kind( $item ), array( 'audio', 'video' ), true );
			}
		)
	);
	$image       = worldgraph_child_story_first_image( $media );
	$poster      = $image ? (string) ( $image['url'] ?? '' ) : '';
	$is_song     = 'song' === (string) ( $payload['sound_kind'] ?? '' );
	$type_label  = worldgraph_child_story_first_term_name( $post->ID, 'worldgraph_sound_type' );
	$status      = worldgraph_child_story_first_term_name( $post->ID, 'worldgraph_status' );
	$spoken_text = worldgraph_child_story_field_text( $post->ID, 'spoken_text' );
	$lyrics      = worldgraph_child_story_field_text( $post->ID, 'lyrics' );
	$notes       = worldgraph_child_story_field_text( $post->ID, 'production_notes' );
	$timecode    = worldgraph_child_story_field_text( $post->ID, 'start_timecode' );
	$duration    = worldgraph_child_story_field_text( $post->ID, 'duration' );
	$diegetic    = worldgraph_child_story_field_text( $post->ID, 'diegetic' );
	$kind_label  = $is_song ? __( 'Song', 'worldgraph-child' ) : __( 'Sound cue', 'worldgraph-child' );
	$shown_media = $detail ? $playable : array_slice( $playable, 0, 1 );

	ob_start();
	?>
	<article class="wg-story-item wg-story-item--sound <?php echo $is_song ? 'is-song ' : ''; ?><?php echo $detail ? 'is-detail' : 'is-card'; ?>">
		<header class="wg-sound-header">
			<div>
				<p class="wg-story-kicker"><?php echo esc_html( $kind_label ); ?></p>
				<?php echo worldgraph_child_story_title( $post, $detail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes title. ?>
			</div>
			<?php if ( $type_label || $status ) : ?>
				<div class="wg-story-tags">
					<?php if ( $type_label ) : ?><span><?php echo esc_html( $type_label ); ?></span><?php endif; ?>
					<?php if ( $status ) : ?><span><?php echo esc_html( $status ); ?></span><?php endif; ?>
				</div>
			<?php endif; ?>
		</header>

		<div class="wg-sound-players">
			<?php if ( $shown_media ) : ?>
				<?php foreach ( $shown_media as $index => $item ) : ?>
					<div class="wg-sound-player">
						<?php echo worldgraph_child_story_media_element( $item, sprintf( __( '%1$s player for %2$s', 'worldgraph-child' ), $kind_label, get_the_title( $post ) ), $poster ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes media. ?>
						<?php if ( $detail && ! empty( $item['title'] ) ) : ?><p><?php echo esc_html( (string) $item['title'] ); ?></p><?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<?php echo worldgraph_child_story_empty_state( __( 'No playable media attached', 'worldgraph-child' ), __( 'Attach a rendered audio or video Asset to enable playback.', 'worldgraph-child' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields. ?>
			<?php endif; ?>
		</div>

		<?php
		echo worldgraph_child_story_facts( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields.
			array(
				__( 'Starts', 'worldgraph-child' )         => $timecode,
				__( 'Duration', 'worldgraph-child' )       => $duration,
				__( 'Story relation', 'worldgraph-child' ) => worldgraph_child_story_value_label( $diegetic ),
			)
		);
		?>

		<?php if ( $detail ) : ?>
			<?php if ( $spoken_text ) : ?>
				<?php echo worldgraph_child_story_detail_section( __( 'Spoken text', 'worldgraph-child' ), $spoken_text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper sanitizes HTML. ?>
			<?php endif; ?>
			<?php if ( $is_song && $lyrics ) : ?>
				<section class="wg-story-detail-section wg-song-lyrics">
					<h2><?php esc_html_e( 'Lyrics', 'worldgraph-child' ); ?></h2>
					<pre><?php echo esc_html( $lyrics ); ?></pre>
				</section>
			<?php elseif ( $is_song ) : ?>
				<?php echo worldgraph_child_story_empty_state( __( 'Lyrics not added yet', 'worldgraph-child' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes fields. ?>
			<?php endif; ?>
			<?php echo worldgraph_child_story_detail_section( __( 'Production notes', 'worldgraph-child' ), $notes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper sanitizes HTML. ?>
		<?php else : ?>
			<?php if ( $spoken_text ) : ?><p class="wg-story-summary"><?php echo esc_html( wp_trim_words( $spoken_text, 24 ) ); ?></p><?php endif; ?>
			<a class="wg-story-link" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( $is_song ? __( 'Open song sheet', 'worldgraph-child' ) : __( 'Open sound cue', 'worldgraph-child' ) ); ?><span aria-hidden="true"> &rarr;</span></a>
		<?php endif; ?>
	</article>
	<?php

	return (string) ob_get_clean();
}
