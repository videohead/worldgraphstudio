<?php
/**
 * Plugin Name: World Graph Studio - Story Core
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Structured story graphs, production planning, assets, relationships, and optional AI-assisted media workflows for WordPress.
 * Version: 1.0.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: secure-custom-fields
 * Requires at least: 6.2
 * Requires PHP: 8.1
 *
 * @package WorldGraph
 */

namespace WorldGraph;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WORLDGRAPH_VERSION', '1.0.0' );
define( 'WORLDGRAPH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORLDGRAPH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WORLDGRAPH_PLUGIN_BASE', plugin_basename( __FILE__ ) );
define( 'WORLDGRAPH_API_NAMESPACE', 'worldgraph/v1' );
define( 'WORLDGRAPH_CPT_PREFIX', 'worldgraph_' );

/** Stable SCF groups owned by the World Graph Studio Local JSON archive. */
function worldgraph_scf_group_keys(): array {
	return [
		'group_worldgraph_project',
		'group_worldgraph_world',
		'group_worldgraph_character',
		'group_worldgraph_location',
		'group_worldgraph_prop',
		'group_worldgraph_org',
		'group_worldgraph_episode',
		'group_worldgraph_scene',
		'group_worldgraph_shot',
		'group_worldgraph_sound',
		'group_worldgraph_asset',
		'group_worldgraph_editorial',
		'group_worldgraph_template',
		'group_worldgraph_conn',
	];
}

/** Whether the current administrator can persist World Graph Studio Local JSON edits. */
function worldgraph_scf_archive_is_writable(): bool {
	$path = WORLDGRAPH_PLUGIN_DIR . 'acf-json';
	if ( ! is_dir( $path ) || ! wp_is_writable( $path ) || ( is_multisite() && ! is_super_admin() ) ) {
		return false;
	}

	foreach ( worldgraph_scf_group_keys() as $key ) {
		$file = $path . '/' . $key . '.json';
		if ( file_exists( $file ) && ! wp_is_writable( $file ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Save World Graph Studio-owned group changes back to the plugin's JSON archive.
 *
 * Other SCF groups retain their existing save paths.
 *
 * @param array<int, string>  $paths Candidate save paths.
 * @param array<string, mixed> $post  SCF field group or internal post type.
 * @return array<int, string>
 */
function worldgraph_scf_json_save_paths( array $paths, array $post ): array {
	if ( in_array( (string) ( $post['key'] ?? '' ), worldgraph_scf_group_keys(), true ) ) {
		return [ WORLDGRAPH_PLUGIN_DIR . 'acf-json' ];
	}

	return $paths;
}

/** Warn administrators when World Graph Studio SCF changes cannot be archived. */
function worldgraph_scf_archive_notice(): void {
	if ( worldgraph_scf_archive_is_writable() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>'
		. esc_html__( 'World Graph Studio SCF fields remain editable in the database, but this account cannot update the plugin acf-json archive. Make that directory and its group files writable (and use a multisite super administrator) before editing if the changes must be portable.', 'worldgraph' )
		. '</p></div>';
}

add_filter( 'acf/json/save_paths', __NAMESPACE__ . '\\worldgraph_scf_json_save_paths', 10, 2 );
add_action( 'admin_notices', __NAMESPACE__ . '\\worldgraph_scf_archive_notice' );

/**
 * Autoloader for World Graph Studio classes.
 *
 * @param string $class The class name.
 */
function autoloader( string $class ): void {
	$prefix = 'WorldGraph\\';
	$base_dir = WORLDGRAPH_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	if ( 'AI\\Abilities\\Abilities' === $relative_class ) {
		require_once $base_dir . 'ai-editor/class-ai-abilities.php';
		return;
	}
	
	// Handle special namespace mappings (singular → plural directories).
	$special_mappings = [
		'CPT\\' => 'cpts/',
		'REST\\' => 'rest-api/',
		'Taxonomies\\' => 'taxonomies/',
		'Admin\\' => 'admin/',
		'Connections\\' => 'connections/',
		'Templates\\' => 'templates/',
		'Utils\\' => 'utils/',
		'AI\\' => 'ai-editor/',
	];
	foreach ( $special_mappings as $ns => $dir ) {
		if ( strpos( $relative_class, $ns ) === 0 ) {
			$relative_class = $dir . substr( $relative_class, strlen( $ns ) );
			break;
		}
	}
	
	// Convert class names to filenames based on namespace.
	// CPT files: StoryWorld -> story-world.php (camelCase to kebab-case)
	// REST files: Projects_Controller -> projects-controller.php (underscore to hyphen)
	$path_parts = explode( '/', $relative_class );
	$filename = array_pop( $path_parts );
	$original_filename = $filename;
	
	// Check if this is a REST controller (has _Controller suffix)
	if ( strpos( $relative_class, 'rest-api/' ) !== false ) {
		// REST controllers: replace underscores with hyphens and lowercase
		$filename = str_replace( '_', '-', strtolower( $filename ) ) . '.php';
	} elseif ( strpos( $relative_class, 'ai-editor/' ) !== false ) {
		$filename = 'class-' . str_replace( '_', '-', strtolower( $filename ) ) . '.php';
	} else {
		// CPT and others: convert camelCase to kebab-case
		$filename = strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $filename ) ) . '.php';
	}
	
	$path_parts[] = $filename;
	$kebab_class = implode( '/', $path_parts );

	// WordPress-style filename: class-worldgraph-importer.php (lowercase, underscores to hyphens).
	$class_prefixed_parts = $path_parts;
	array_pop( $class_prefixed_parts );
	$class_prefixed_parts[] = 'class-' . str_replace( '_', '-', strtolower( $original_filename ) ) . '.php';
	$class_prefixed_class = implode( '/', $class_prefixed_parts );
	
	// Also try lowercase version of the full path (e.g., cpts/story-world.php).
	$lower_class = strtolower( $relative_class ) . '.php';
	$file = $base_dir . $relative_class . '.php';

	// Try kebab-case, WordPress class-prefixed, then lowercase and original case.
	if ( ! file_exists( $file ) ) {
		$file = $base_dir . $kebab_class;
	}
	if ( ! file_exists( $file ) ) {
		$file = $base_dir . $class_prefixed_class;
	}
	if ( ! file_exists( $file ) ) {
		$file = $base_dir . $lower_class;
	}

	if ( file_exists( $file ) ) {
		require $file;
	}
}

spl_autoload_register( __NAMESPACE__ . '\\autoloader' );

/**
 * Check if SCF (Secure Custom Fields) is active.
 *
 * @return bool
 */
function scf_is_active(): bool {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin = 'secure-custom-fields/secure-custom-fields.php';
	return is_plugin_active( $plugin ) || ( is_multisite() && is_plugin_active_for_network( $plugin ) );
}

/**
 * Handle missing SCF dependency.
 */
function worldgraph_missing_scf_dependency(): void {
	wp_die(
		'<h1>World Graph Studio requires Secure Custom Fields (SCF)</h1>
		<p>World Graph Studio depends on the <strong>Secure Custom Fields (SCF)</strong> plugin for field management. Please install and activate it before enabling World Graph Studio.</p>
		<p><a href="' . esc_url( admin_url( 'plugin-install.php?s=secure+custom+fields&tab=search&type=tabs' ) ) . '" class="button button-primary">Install SCF</a>
		<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '" class="button">Go to Plugins</a></p>
		<p><small>Secure Custom Fields: <a href="https://wordpress.org/plugins/secure-custom-fields/">WordPress.org</a></small></p>',
		'World Graph Studio Missing Dependency',
		[ 'response' => 500 ]
	);
}

/**
 * Initialize the plugin.
 */
function init(): void {
	// Check SCF dependency.
	if ( ! scf_is_active() ) {
		add_action( 'admin_notices', function(): void {
			?>
			<div class="notice notice-error">
				<p><strong>World Graph Studio</strong> requires the <strong>Secure Custom Fields (SCF)</strong> plugin to be installed and activated. <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Go to Plugins</a></p>
			</div>
			<?php
		} );
		return;
	}

	// Load dependencies.
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/helpers.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/class-credential-store.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/cpt-key-migration.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/generation-log.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/template-smoke-check.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/generation-modality.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/template-run-controls.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/connection-adapters.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/connections/class-connection-oauth.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/templates/class-template-manager.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/generation-batch.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/relationships.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/story-search.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/continuity-checker.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/relationship-graph.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/story-display.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/utils/scene-shot-order.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/dashboard.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/navigation.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/data-purge.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/project-cascade-delete.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/setup-wizard.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/metaboxes.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/asset-generator-metabox.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/template-workflow-test.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/comfy-readiness.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/adapters.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/plugins.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/continuity-panel.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/summary-tool.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/dramaturgy-tool.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/analytics-panel.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/editorial-cut.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/scene-shot-sequencer.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/admin/story-media-gallery.php';
	require_once WORLDGRAPH_PLUGIN_DIR . 'includes/cli/class-storyboard-cleanup.php';

	// Credential filters must be active before Connections and provider modules
	// read or write any protected values.
	Utils\Credential_Store::init();
	Connections\Connection_OAuth::init();

	// Register CPTs.
	CPT\Project::init();
	CPT\StoryWorld::init();
	CPT\Character::init();
	CPT\Location::init();
	CPT\Prop::init();
	CPT\Organization::init();
	CPT\Episode::init();
	CPT\Scene::init();
	CPT\Shot::init();
	CPT\Sound::init();
	CPT\Asset::init();
	CPT\EditorialArtifact::init();
	CPT\Template::init();
	CPT\Connection::init();
	Utils\worldgraph_register_generation_record_type();
	CPT\Generation_Job::init();
	Utils\worldgraph_maybe_migrate_cpt_keys();
	Utils\Template_Smoke_Check::init();
	Templates\Template_Manager::init();
	Utils\Connection_Adapters::load_configured();

	// Register taxonomies.
	Taxonomies\Genre::init();
	Taxonomies\AssetType::init();
	Taxonomies\ProductionStatus::init();
	Taxonomies\CharacterRelation::init();
	Taxonomies\CharacterRole::init();
	Taxonomies\SceneTag::init();
	Taxonomies\Sequence::init();
	Taxonomies\SoundType::init();
	Taxonomies\TemplateCategory::init();
	Utils\relationship_graph_cache_init();
	Utils\worldgraph_story_display_init();

	// SCF JSON archives seed editable persisted groups; the database copies are
	// runtime-authoritative and managed in Secure Custom Fields.
	Utils\SCF_Fields::boot( Utils\worldgraph_get_all_field_defaults() );
	add_action( 'save_post', 'WorldGraph\\Utils\\worldgraph_hydrate_required_fields_on_save', 20, 3 );
	add_action( 'admin_notices', 'WorldGraph\\Utils\\worldgraph_render_required_fields_notice' );

	// Canonical story interchange is owned by a bundled, independently gated
	// feature plugin. Load it before any connector that consumes the legacy
	// WorldGraph\Importer or WorldGraph\Exporter compatibility class names.
	$story_io_plugin = WORLDGRAPH_PLUGIN_DIR . 'plugins/story-import-export/story-import-export.php';
	if ( file_exists( $story_io_plugin ) ) {
		require_once $story_io_plugin;
	}

	// Register REST API routes.
	REST\Projects_Controller::init();
	REST\StoryWorlds_Controller::init();
	REST\Characters_Controller::init();
	REST\Locations_Controller::init();
	REST\Props_Controller::init();
	REST\Organizations_Controller::init();
	REST\Episodes_Controller::init();
	REST\Scenes_Controller::init();
	REST\Shots_Controller::init();
	REST\Sounds_Controller::init();
	REST\Sequences_Controller::init();
	REST\Assets_Controller::init();
	REST\Asset_Generation_Controller::init();
	REST\EditorialArtifacts_Controller::init();
	REST\Graph_Controller::init();
	REST\Agents_Controller::init();
	REST\Generation_Controller::init();
	REST\Production_Controller::init();
	REST\Editorial_Controller::init();
	REST\Connections_Controller::init();

	// Register admin pages and hooks.
	Admin\Dashboard::init();
	Admin\Navigation::init();
	Admin\Data_Purge::init();
	Admin\Project_Cascade_Delete::init();
	Admin\Setup_Wizard::init();
	Admin\MetaBoxes::init();
	Admin\Asset_Generator_MetaBox::init();
	Admin\Template_Workflow_Test::init();
	Admin\Comfy_Readiness::init();
	Admin\Adapters::init();
	Admin\Plugins::init();
	Admin\Continuity_Panel::init();
	Admin\Summary_Tool::init();
	Admin\Dramaturgy_Tool::init();
	Admin\Analytics_Panel::init();
	Admin\Connections::init();
	Admin\Editorial_Cut::init();
	Admin\Scene_Shot_Sequencer::init();
	Admin\Story_Media_Gallery::init();
	Utils\Generation_Workflows::init();
	Utils\Generation_Batch::init();
	CLI\Storyboard_Cleanup::init();

	// Initialize AI Editor module (LLM, MAF bridge, Gutenberg panel, REST endpoints).
	if ( class_exists( '\WorldGraph\AI\AI_Editor' ) ) {
		\WorldGraph\AI\AI_Editor::init();

		// Initialize World Graph Studio Abilities for MCP exposure (requires WP 6.9+).
		if ( function_exists( 'wp_register_ability' ) ) {
			\WorldGraph\AI\Abilities\Abilities::instance()->init();
		}
	}

	// Load Celtx Sync integration.
	if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/celtx/celtx-sync.php' ) ) {
		require_once WORLDGRAPH_PLUGIN_DIR . 'plugins/celtx/celtx-sync.php';
	}

	// Load VideoDraft import/export sync integration.
	if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/videodraft/videodraft-sync.php' ) ) {
		require_once WORLDGRAPH_PLUGIN_DIR . 'plugins/videodraft/videodraft-sync.php';
	}

	// Load Descript import/export sync integration.
	if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/descript/descript-sync.php' ) ) {
		require_once WORLDGRAPH_PLUGIN_DIR . 'plugins/descript/descript-sync.php';
	}

	// Load ComfyUI Generate integration.
	if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/comfy-generate/comfy-generate.php' ) ) {
		require_once WORLDGRAPH_PLUGIN_DIR . 'plugins/comfy-generate/comfy-generate.php';
	}

	// Load EDL Import/Export integration.
	if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/edl/edl-import-export.php' ) ) {
		require_once WORLDGRAPH_PLUGIN_DIR . 'plugins/edl/edl-import-export.php';
	}

	// Load optional headless (Next.js) cache revalidation integration.
	if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/headless-revalidate/headless-revalidate.php' ) ) {
		require_once WORLDGRAPH_PLUGIN_DIR . 'plugins/headless-revalidate/headless-revalidate.php';
	}

	// Enqueue search widget assets on frontend.
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\Utils\\enqueue_search_assets' );

	// Enqueue continuity panel assets in admin.
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_continuity_assets' );

	// Hook auto-validation on save for scenes and shots.
	add_action( 'save_post_worldgraph_scene', __NAMESPACE__ . '\\auto_validate_scene', 20, 3 );
	add_action( 'save_post_worldgraph_shot', __NAMESPACE__ . '\\auto_validate_shot', 20, 3 );

	// Drop stored continuity issues when the referenced post goes away.
	add_action( 'trashed_post', '\\WorldGraph\\Utils\\purge_continuity_issues_for_post' );
	add_action( 'deleted_post', '\\WorldGraph\\Utils\\purge_continuity_issues_for_post' );

	// Auto-generate useful shot names for shots with placeholder titles.
	add_action( 'save_post_worldgraph_shot', __NAMESPACE__ . '\\worldgraph_maybe_name_shot', 5, 3 );

	// Keep serialized Story Graph edges free of targets that have been deleted.
	add_action( 'before_delete_post', __NAMESPACE__ . '\\Utils\\cleanup_relationships_for_deleted_post' );

}

/**
 * Auto-generate a useful name for shots with default placeholder titles.
 *
 * Runs at priority 5 (before continuity validation) and guards against
 * recursion when it updates the post title.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    Post object.
 * @param bool     $update  Whether this is an update.
 * @return void
 */
function worldgraph_maybe_name_shot( int $post_id, \WP_Post $post, bool $update ): void {
	// Only runs on our own plugin saves; never during autosave or batch operations.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	// Never rename shots that already have an intentional title.
	$title = trim( (string) $post->post_title );
	if ( '' !== $title && ! preg_match( '/^shot \d+$/i', $title ) ) {
		return;
	}

	$name = \WorldGraph\Utils\worldgraph_get_shot_display_name( $post_id );
	if ( '' === $name || $name === $title ) {
		return;
	}

	// Remove this hook before the nested update to avoid infinite recursion.
	remove_action( 'save_post_worldgraph_shot', __NAMESPACE__ . '\\worldgraph_maybe_name_shot', 5 );

	wp_update_post( [
		'ID'         => $post_id,
		'post_title' => $name,
	] );

	add_action( 'save_post_worldgraph_shot', __NAMESPACE__ . '\\worldgraph_maybe_name_shot', 5, 3 );
}
add_action( 'init', __NAMESPACE__ . '\\init' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Enqueue continuity panel admin assets.
 */
function enqueue_continuity_assets(): void {
	// Assets are enqueued by Admin\Continuity_Panel::enqueue_scripts().
	// This hook ensures the CSS/JS files are registered.
	wp_enqueue_style(
		'worldgraph-continuity',
		WORLDGRAPH_PLUGIN_URL . 'assets/css/continuity-panel.css',
		[],
		WORLDGRAPH_VERSION
	);
	wp_enqueue_script(
		'worldgraph-continuity',
		WORLDGRAPH_PLUGIN_URL . 'assets/js/continuity-panel.js',
		[ 'jquery' ],
		WORLDGRAPH_VERSION,
		true
	);
}

/**
 * Auto-validate a scene on save.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post Post object.
 * @param bool     $update Whether this is an update.
 */
function auto_validate_scene( int $post_id, \WP_Post $post, bool $update ): void {
	\WorldGraph\Utils\auto_check_continuity_on_save( $post_id, $post, $update );
}

/**
 * Auto-validate a shot on save.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post Post object.
 * @param bool     $update Whether this is an update.
 */
function auto_validate_shot( int $post_id, \WP_Post $post, bool $update ): void {
	\WorldGraph\Utils\auto_check_continuity_on_save( $post_id, $post, $update );
}

/**
 * Flush rewrite rules on activation.
 */
function activate(): void {
	if ( ! scf_is_active() ) {
		worldgraph_missing_scf_dependency();
	}

	init();
	flush_rewrite_rules();

	// Set default World Graph Studio options.
	add_option( 'worldgraph_version', WORLDGRAPH_VERSION );
	add_option( 'worldgraph_enabled', true );
	add_option( 'worldgraph_story_io_enabled', true );
	Utils\Generation_Batch::schedule();

	// Send the admin to the connection setup wizard on first activation.
	if ( ! get_option( 'worldgraph_setup_complete', false ) ) {
		set_transient( 'worldgraph_activation_redirect', true, MINUTE_IN_SECONDS );
	}
}

/**
 * Flush rewrite rules on deactivation.
 */
function deactivate(): void {
	wp_clear_scheduled_hook( Utils\Generation_Batch::HOOK );
	wp_clear_scheduled_hook( Utils\Generation_Workflows::ASSEMBLY_HOOK );
	wp_clear_scheduled_hook( Templates\Template_Manager::HOOK );
	flush_rewrite_rules();
}
