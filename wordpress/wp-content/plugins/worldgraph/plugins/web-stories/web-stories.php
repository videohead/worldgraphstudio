<?php
/**
 * Plugin Name: World Graph Studio - Web Stories Prototype
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Experimental adapter source for exploring World Graph Studio and Google Web Stories interoperability; not loaded by the main plugin.
 * Version: 1.0.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph, web-stories
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package WorldGraphWebStories
 */

namespace WorldGraphWebStories;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WORLDGRAPH_WEB_STORIES_VERSION', '1.0.0' );
define( 'WORLDGRAPH_WEB_STORIES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORLDGRAPH_WEB_STORIES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WORLDGRAPH_WEB_STORIES_PLUGIN_BASE', plugin_basename( __FILE__ ) );

/**
 * Autoloader for World Graph Studio Web Stories classes.
 *
 * @param string $class The class name.
 */
function autoloader( string $class ): void {
	$prefix = 'WorldGraphWebStories\\';
	$base_dir = WORLDGRAPH_WEB_STORIES_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );

	// Convert namespace to file path.
	$last_backslash = strrpos( $relative_class, '\\' );
	if ( false !== $last_backslash ) {
		$namespace = substr( $relative_class, 0, $last_backslash );
		$class_name = substr( $relative_class, $last_backslash + 1 );

		$namespace_dir = strtolower( str_replace( '\\', '/', $namespace ) );
		$filename = strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $class_name ) );

		$file = $base_dir . $namespace_dir . '/' . $filename . '.php';
	} else {
		$class_name = $relative_class;
		$filename = strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $class_name ) );
		$file = $base_dir . $filename . '.php';
	}

	if ( file_exists( $file ) ) {
		require $file;
	}
}

spl_autoload_register( __NAMESPACE__ . '\\autoloader' );

/**
 * Initialize the plugin.
 */
function init(): void {
	// Check dependencies.
	if ( ! class_exists( 'Google\\Web_Stories\\Story_Post_Type' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\admin_notice_web_stories_missing' );
		return;
	}

	if ( ! in_array( 'worldgraph/worldgraph.php', get_option( 'active_plugins', [] ), true ) && ! class_exists( 'WorldGraph\\Utils\\register_cpt' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\admin_notice_worldgraph_missing' );
		return;
	}

	// Initialize components.
	\WorldGraphWebStories\API\Client::class;
	\WorldGraphWebStories\Sync::init();
	\WorldGraphWebStories\Settings::init();

	// Register REST API routes.
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );

	// Register hooks for sync triggers.
	add_action( 'save_post_web-story', __NAMESPACE__ . '\\on_web_story_save', 10, 3 );
	add_action( 'save_post_worldgraph_scene', __NAMESPACE__ . '\\on_worldgraph_scene_save', 10, 3 );
}
add_action( 'init', __NAMESPACE__ . '\\init' );

/**
 * Register REST API routes.
 */
function register_rest_routes(): void {
	\WorldGraphWebStories\REST\Sync_Controller::init();
}

/**
 * Admin notice when Web Stories plugin is missing.
 */
function admin_notice_web_stories_missing(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo wp_kses(
				__( 'World Graph Studio Web Stories Sync requires the <strong>Web Stories</strong> plugin by Google to be installed and activated.', 'worldgraph' ),
				[ 'strong' => [] ]
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Admin notice when World Graph Studio plugin is missing.
 */
function admin_notice_worldgraph_missing(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo wp_kses(
				__( 'World Graph Studio Web Stories Sync requires the <strong>World Graph Studio</strong> plugin to be installed and activated.', 'worldgraph' ),
				[ 'strong' => [] ]
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Trigger sync when a Web Story is saved.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an existing post being updated.
 */
function on_web_story_save( int $post_id, \WP_Post $post, bool $update ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Only sync if this story is already mapped.
	$mapping = get_post_meta( $post_id, '_worldgraph_web_stories_mapping', true );
	if ( empty( $mapping['scene_id'] ) ) {
		return;
	}

	// Trigger sync.
	\WorldGraphWebStories\Sync::init()->sync_story( $post_id );
}

/**
 * Trigger sync when a World Graph Studio Scene is saved.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an existing post being updated.
 */
function on_worldgraph_scene_save( int $post_id, \WP_Post $post, bool $update ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Only sync if this scene is already mapped.
	$mapping = get_post_meta( $post_id, '_worldgraph_web_stories_mapping', true );
	if ( empty( $mapping['story_id'] ) ) {
		return;
	}

	// Trigger sync.
	\WorldGraphWebStories\Sync::init()->sync_scene( $post_id );
}
