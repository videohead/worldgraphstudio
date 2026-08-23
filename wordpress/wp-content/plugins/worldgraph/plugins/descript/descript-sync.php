<?php
/**
 * Plugin Name: World Graph Studio - Descript Sync
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Import Descript project transcripts into the Story Graph and export bound Project media into new Descript projects.
 * Version: 1.0.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package WorldGraphDescript
 */

namespace WorldGraphDescript;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WORLDGRAPH_DESCRIPT_VERSION', '1.0.0' );
define( 'WORLDGRAPH_DESCRIPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORLDGRAPH_DESCRIPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WORLDGRAPH_DESCRIPT_PLUGIN_DIR . 'includes/class-descript-mapper.php';
require_once WORLDGRAPH_DESCRIPT_PLUGIN_DIR . 'includes/class-descript-sync.php';
require_once WORLDGRAPH_DESCRIPT_PLUGIN_DIR . 'includes/class-descript-settings.php';
require_once WORLDGRAPH_DESCRIPT_PLUGIN_DIR . 'includes/rest-api/class-descript-controller.php';

/** Bootstrap settings and the enabled sync surface. */
function init(): void {
	Settings::init();
	if ( ! Settings::is_enabled() ) {
		return;
	}

	\WorldGraph\Utils\Connection_Adapters::load( 'descript' );
	REST\Descript_Controller::init();
}

if ( did_action( 'init' ) ) {
	init();
} else {
	add_action( 'init', __NAMESPACE__ . '\\init' );
}
