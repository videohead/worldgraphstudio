<?php
/**
 * Plugin Name: World Graph Studio - Celtx Connector
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Send supported World Graph Studio elements to Celtx and retain their remote element mappings.
 * Version: 1.0.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package WorldGraphCeltx
 */

namespace WorldGraphCeltx;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WORLDGRAPH_CELTX_VERSION', '1.0.0' );
define( 'WORLDGRAPH_CELTX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORLDGRAPH_CELTX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WORLDGRAPH_CELTX_PLUGIN_BASE', plugin_basename( __FILE__ ) );

/**
 * Autoloader for World Graph Studio Celtx classes.
 *
 * @param string $class The class name.
 */
function autoloader( string $class ): void {
	$prefix = 'WorldGraphCeltx\\';
	$base_dir = WORLDGRAPH_CELTX_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );

	// Convert namespace to file path.
	// WorldGraphCeltx\API\Client -> api/client.php
	// WorldGraphCeltx\REST\Sync_Controller -> rest/sync-controller.php
	// WorldGraphCeltx\Sync -> sync.php

	// Convert a class name to a kebab-case filename.
	// Underscores become hyphens; internal capitals get a hyphen unless one already precedes them.
	$to_kebab = static function ( string $name ): string {
		$name = str_replace( '_', '-', $name );
		return strtolower( preg_replace( '/(?<!^)(?<![-A-Z])[A-Z]/', '-$0', $name ) );
	};

	$last_backslash = strrpos( $relative_class, '\\' );
	if ( false !== $last_backslash ) {
		$namespace_dir = strtolower( str_replace( '\\', '/', substr( $relative_class, 0, $last_backslash ) ) );
		$filename      = $to_kebab( substr( $relative_class, $last_backslash + 1 ) );
		$path_key      = $namespace_dir . '/' . $filename;
	} else {
		$path_key = $to_kebab( $relative_class );
	}

	$file = $base_dir . $path_key . '.php';

	// Compatibility fallbacks for existing World Graph Studio Celtx file naming.
	if ( ! file_exists( $file ) ) {
		$fallback_map = [
			'sync'                 => $base_dir . 'class-celtx-sync.php',
			'settings'             => $base_dir . 'class-celtx-settings.php',
			'api/client'           => $base_dir . 'class-celtx-api.php',
			'rest/sync-controller' => $base_dir . 'rest-api/sync-controller.php',
		];

		if ( isset( $fallback_map[ $path_key ] ) ) {
			$file = $fallback_map[ $path_key ];
		}
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
	// Settings always load so the integration can be enabled from the admin UI.
	if ( ! class_exists( \WorldGraphCeltx\Settings::class ) ) {
		return;
	}

	\WorldGraphCeltx\Settings::init();

	if ( ! \WorldGraphCeltx\Settings::is_enabled() ) {
		return;
	}

	if ( class_exists( \WorldGraphCeltx\Sync::class ) ) {
		\WorldGraphCeltx\Sync::init();
	}

	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );
}

if ( did_action( 'init' ) ) {
	init();
} else {
	add_action( 'init', __NAMESPACE__ . '\\init' );
}

/**
 * Register REST API routes.
 */
function register_rest_routes(): void {
	if ( ! class_exists( \WorldGraphCeltx\REST\Sync_Controller::class ) ) {
		return;
	}

	\WorldGraphCeltx\REST\Sync_Controller::init();
}
