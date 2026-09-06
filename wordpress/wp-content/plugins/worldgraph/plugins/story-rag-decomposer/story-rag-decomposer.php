<?php
/**
 * Plugin Name: World Graph Studio - Story RAG Decomposer
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Adds private, transient WPVDB vector retrieval to long-form story decomposition.
 * Version: 1.0.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph, wpvdb
 * Requires at least: 6.2
 * Requires PHP: 8.1
 *
 * @package WorldGraphStoryRAG
 */

namespace WorldGraphStoryRAG;

defined( 'ABSPATH' ) || exit;

define( 'WORLDGRAPH_STORY_RAG_VERSION', '1.0.0' );
define( 'WORLDGRAPH_STORY_RAG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** Whether the optional retrieval bridge is enabled. Defaults off. */
function is_enabled(): bool {
	return (bool) get_option( 'worldgraph_story_rag_enabled', false );
}

/** Whether the separately installed WPVDB runtime has a usable embedding configuration. */
function is_configured(): bool {
	if (
		! class_exists( '\\WPVDB\\Core' ) ||
		! class_exists( '\\WPVDB\\Settings' ) ||
		! class_exists( '\\WPVDB\\Models' ) ||
		! method_exists( '\\WPVDB\\Core', 'get_embedding_for_model' ) ||
		! method_exists( '\\WPVDB\\Settings', 'validate_configuration' )
	) {
		return false;
	}

	try {
		$configuration = \WPVDB\Settings::validate_configuration();
		if ( is_wp_error( $configuration ) || true !== $configuration ) {
			return false;
		}

		$provider = (string) \WPVDB\Settings::get_active_provider();
		$model    = method_exists( '\\WPVDB\\Settings', 'get_active_model' )
			? (string) \WPVDB\Settings::get_active_model()
			: (string) \WPVDB\Settings::get_default_model();

		return '' !== trim( $provider ) && '' !== trim( $model ) && (bool) \WPVDB\Models::get_model( $provider, $model );
	} catch ( \Throwable ) {
		return false;
	}
}

/** Show a bounded dependency/configuration notice only when an enabled bridge cannot start. */
function dependency_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || ! is_enabled() || is_configured() ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p>
			<?php
			esc_html_e(
				'Story RAG Decomposer is enabled but inactive. Install and activate WPVDB, then configure its embedding provider and model.',
				'worldgraph'
			);
			?>
		</p>
	</div>
	<?php
}

/** Bootstrap the enabled bridge without making WPVDB a core World Graph Studio dependency. */
function init(): void {
	static $initialized = false;
	if ( $initialized ) {
		return;
	}
	$initialized = true;

	// Cleanup must remain available after an operator disables RAG or while
	// WPVDB is temporarily unconfigured, otherwise an already-running job could
	// only wait for its fixed transient deadline.
	require_once WORLDGRAPH_STORY_RAG_PLUGIN_DIR . 'includes/class-story-rag-retrieval.php';
	Story_RAG_Retrieval::init_cleanup();

	if ( ! is_enabled() ) {
		return;
	}

	if ( ! is_configured() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\dependency_notice' );
		return;
	}

	Story_RAG_Retrieval::init();
}

init();
