<?php
/**
 * Minimal schema contract smoke test for World Graph Studio Phase 8.
 *
 * This intentionally exercises the helper layer without requiring a full WordPress bootstrap.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WORLDGRAPH_CPT_PREFIX' ) ) {
	define( 'WORLDGRAPH_CPT_PREFIX', 'worldgraph_' );
}

function get_option( $name, $default = [] ) {
	static $options = [];
	return $options[ $name ] ?? $default;
}

function update_option( $name, $value ): void {
	static $options = [];
	$options[ $name ] = $value;
}

function register_post_type( $cpt, $args ): void {
	// no-op for smoke test
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}

require_once __DIR__ . '/../includes/utils/helpers.php';

$expected_project_fields = \WorldGraph\Utils\worldgraph_expected_fields_for_cpt( 'worldgraph_project' );
if ( ! in_array( 'project_name', $expected_project_fields, true ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only test output.
	fwrite( STDERR, "Expected project_name in schema manifest.\n" );
	exit( 1 );
}

\WorldGraph\Utils\worldgraph_register_fields( 'worldgraph_project', [
	'project_name' => [ 'type' => 'text' ],
	'project_slug' => [ 'type' => 'text' ],
] );

$report = \WorldGraph\Utils\worldgraph_validate_schema_alignment();
if ( empty( $report['worldgraph_project']['missing'] ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only test output.
	fwrite( STDERR, "Expected missing fields report for worldgraph_project.\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only test output.
fwrite( STDOUT, "Schema contract smoke test passed.\n" );
