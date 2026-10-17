<?php
/**
 * Theme setup for the World Graph Studio child theme.
 *
 * @package WorldGraphChild
 */

require_once get_stylesheet_directory() . '/inc/story-templates.php';

/**
 * Load translations and keep the block editor aligned with the front end.
 *
 * @return void
 */
function worldgraph_child_setup() {
	load_child_theme_textdomain(
		'worldgraph-child',
		get_stylesheet_directory() . '/languages'
	);

	add_editor_style( 'style.css' );
}
add_action( 'after_setup_theme', 'worldgraph_child_setup', 20 );

/**
 * Keep Frost's functional loop patterns without exposing its demo marketing
 * content in the inserter.
 *
 * @param string[] $files   Pattern files found in the directory.
 * @param string   $dirpath Pattern directory being scanned.
 * @return string[]
 */
function worldgraph_child_filter_frost_patterns( $files, $dirpath ) {
	$frost_patterns = wp_normalize_path( get_template_directory() . '/patterns' );

	if ( untrailingslashit( wp_normalize_path( $dirpath ) ) !== $frost_patterns ) {
		return $files;
	}

	$allowed_patterns = array(
		'comments.php',
	);

	return array_values(
		array_filter(
			$files,
			static function ( $file ) use ( $allowed_patterns ) {
				return in_array( basename( $file ), $allowed_patterns, true );
			}
		)
	);
}
add_filter( 'theme_block_pattern_files', 'worldgraph_child_filter_frost_patterns', 10, 2 );

/**
 * Clear Frost's persisted pattern cache once for each child-theme release.
 *
 * Parent pattern caches are keyed to the parent version, so changing only this
 * child theme would otherwise leave removed Frost demo patterns registered
 * until the cache expires.
 *
 * @return void
 */
function worldgraph_child_refresh_frost_pattern_cache() {
	$theme         = wp_get_theme();
	$theme_version = $theme->get( 'Version' );

	if ( get_option( 'worldgraph_child_pattern_cache_version' ) === $theme_version ) {
		return;
	}

	$parent_theme = $theme->parent();
	if ( $parent_theme ) {
		$parent_theme->delete_pattern_cache();
	}

	update_option( 'worldgraph_child_pattern_cache_version', $theme_version, false );
}
add_action( 'init', 'worldgraph_child_refresh_frost_pattern_cache', 0 );

/**
 * Create the theme-owned About page when the expected slug is available.
 *
 * Existing pages are never changed. Storing the theme version allows a newly
 * installed or updated theme to provision the route without checking on every
 * request after the first successful pass.
 *
 * @return void
 */
function worldgraph_child_ensure_about_page() {
	$theme         = wp_get_theme();
	$theme_version = $theme->get( 'Version' );

	if ( get_option( 'worldgraph_child_about_page_version' ) === $theme_version ) {
		return;
	}

	$about_page = get_page_by_path( 'about', OBJECT, 'page' );

	if ( ! $about_page ) {
		$about_page_id = wp_insert_post(
			array(
				'post_title'     => __( 'About', 'worldgraph-child' ),
				'post_name'      => 'about',
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'post_content'   => '',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);

		if ( is_wp_error( $about_page_id ) ) {
			return;
		}
	}

	update_option( 'worldgraph_child_about_page_version', $theme_version, false );
}
add_action( 'init', 'worldgraph_child_ensure_about_page', 20 );

/**
 * Enqueue the child stylesheet.
 *
 * @return void
 */
function worldgraph_child_enqueue_styles() {
	$theme = wp_get_theme();

	wp_enqueue_style(
		'worldgraph-child',
		get_stylesheet_uri(),
		array(),
		$theme->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'worldgraph_child_enqueue_styles' );
