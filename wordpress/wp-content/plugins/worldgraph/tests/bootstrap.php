<?php
/**
 * PHPUnit bootstrap file for World Graph Studio tests.
 *
 * @package WorldGraph
 */

// Define plugin constants for testing.
define( 'WORLDGRAPH_VERSION', '1.0.0' );
define( 'WORLDGRAPH_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

// Plugin files guard against direct access with `exit`, which would end the
// PHPUnit run silently, so stand in for the WordPress root constant.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( WORLDGRAPH_PLUGIN_DIR, 3 ) . '/' );
}
define( 'WORLDGRAPH_PLUGIN_URL', 'file://' . WORLDGRAPH_PLUGIN_DIR );
define( 'WORLDGRAPH_PLUGIN_BASE', 'worldgraph/worldgraph.php' );
define( 'WORLDGRAPH_API_NAMESPACE', 'worldgraph/v1' );
define( 'WORLDGRAPH_CPT_PREFIX', 'worldgraph_' );

// Load the World Graph Studio helper layer directly for unit tests.
require_once dirname( __DIR__ ) . '/includes/utils/helpers.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-scf-fields.php';
require_once dirname( __DIR__ ) . '/includes/utils/cpt-key-migration.php';
require_once dirname( __DIR__ ) . '/includes/utils/relationships.php';
require_once dirname( __DIR__ ) . '/includes/utils/relationship-graph.php';
require_once dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-exporter.php';

// Test files reference the global helper names used in older World Graph Studio tests.
if ( ! function_exists( 'prefix' ) ) {
	function prefix( string $name = '', string $custom_prefix = '' ): string {
		return \WorldGraph\Utils\prefix( $name, $custom_prefix );
	}
}

if ( ! function_exists( 'sanitize_story_id' ) ) {
	function sanitize_story_id( $id ): string {
		return \WorldGraph\Utils\sanitize_story_id( $id );
	}
}
