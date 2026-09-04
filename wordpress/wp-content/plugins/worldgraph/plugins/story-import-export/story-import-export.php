<?php
/**
 * Plugin Name: World Graph Studio - Story Import & Export
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Import canonical JSON or decompose uploaded story files through an LLM Connection, then export projects as portable JSON or Markdown.
 * Version: 1.1.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph
 * Requires at least: 6.2
 * Requires PHP: 8.1
 *
 * @package WorldGraphStoryIO
 */

namespace WorldGraphStoryIO;

defined( 'ABSPATH' ) || exit;

define( 'WORLDGRAPH_STORY_IO_VERSION', '1.1.0' );
define( 'WORLDGRAPH_STORY_IO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORLDGRAPH_STORY_IO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Whether the bundled feature plugin is enabled. Defaults on for compatibility. */
function is_enabled(): bool {
	return (bool) get_option( 'worldgraph_story_io_enabled', true );
}

/** Add the bounded source formats accepted by the administrator upload flow. */
function allowed_upload_mimes( array $mimes ): array {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $mimes;
	}

	$mimes['json']             = 'application/json';
	$mimes['txt|text']         = 'text/plain';
	$mimes['md|markdown']      = 'text/markdown';
	$mimes['fountain']         = 'text/plain';
	$mimes['rtf']              = 'application/rtf';
	$mimes['pdf']              = 'application/pdf';
	$mimes['epub']             = 'application/epub+zip';
	$mimes['docx']             = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
	$mimes['odt']              = 'application/vnd.oasis.opendocument.text';

	return $mimes;
}

/** Bootstrap the enabled import/export surface. */
function init(): void {
	static $initialized = false;
	if ( $initialized ) {
		return;
	}
	$initialized = true;

	if ( ! is_enabled() ) {
		return;
	}

	$files = [
		'includes/class-worldgraph-importer.php',
		'includes/class-worldgraph-exporter.php',
		'includes/class-archive-reader.php',
		'includes/class-source-extractor.php',
		'includes/class-story-chunker.php',
		'includes/class-story-decomposer.php',
		'includes/class-worldgraph-json-exporter.php',
		'includes/class-import-controller.php',
		'includes/class-import-admin.php',
		'includes/class-export-admin.php',
	];

	foreach ( $files as $relative_file ) {
		require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . $relative_file;
	}

	add_filter( 'upload_mimes', __NAMESPACE__ . '\\allowed_upload_mimes' );
	\WorldGraph\REST\Import_Controller::init();
	\WorldGraph\Admin\Import::init();
	\WorldGraph\Admin\Export::init();

	do_action( 'worldgraph_story_io_loaded' );
}

if ( did_action( 'init' ) ) {
	init();
} else {
	add_action( 'init', __NAMESPACE__ . '\\init' );
}
