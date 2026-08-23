<?php
/**
 * Plugin Name: World Graph Studio - VideoDraft Sync
 * Plugin URI: https://github.com/videodraft-ai/cli
 * Description: Import and export project structure between World Graph Studio and VideoDraft.
 * Version: 1.0.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package WorldGraphVideoDraft
 */

namespace WorldGraphVideoDraft;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WORLDGRAPH_VIDEODRAFT_VERSION', '1.0.0' );
define( 'WORLDGRAPH_VIDEODRAFT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WORLDGRAPH_VIDEODRAFT_PLUGIN_DIR . 'includes/class-videodraft-mapper.php';
require_once WORLDGRAPH_VIDEODRAFT_PLUGIN_DIR . 'includes/class-videodraft-sync.php';
require_once WORLDGRAPH_VIDEODRAFT_PLUGIN_DIR . 'includes/class-videodraft-settings.php';
require_once WORLDGRAPH_VIDEODRAFT_PLUGIN_DIR . 'includes/rest-api/class-videodraft-controller.php';

/** Bootstrap settings and the enabled sync surface. */
function init(): void {
	Settings::init();
	if ( ! Settings::is_enabled() ) {
		return;
	}

	\WorldGraph\Utils\Connection_Adapters::load( 'videodraft' );
	Sync::init();
	REST\VideoDraft_Controller::init();
}

if ( did_action( 'init' ) ) {
	init();
} else {
	add_action( 'init', __NAMESPACE__ . '\\init' );
}
