<?php
/**
 * Continuity Checker for World Graph Studio.
 *
 * Provides continuity validation through local analysis,
 * auto-check on save, and persistence of continuity issues in WordPress.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch continuity validation from local analysis.
 *
 * Analyzes scenes and shots in the Story Graph for common continuity issues.
 *     @type int   $total_issues   Total number of issues found.
 *     @type int   $errors         Number of error-level issues.
 *     @type int   $warnings       Number of warning-level issues.
 *     @type int   $infos          Number of info-level issues.
 *     @type int   $scenes_validated Number of scenes validated.
 *     @type array $issues         Array of ContinuityIssue objects.
 * }
 */
function fetch_continuity_validation( int $episode_id = 0, array $scene_ids = [] ): array {
	$issues = [];
	$posts = ! empty( $scene_ids ) ? array_map( 'get_post', array_map( 'absint', $scene_ids ) ) : get_posts( [ 'post_type' => 'worldgraph_scene', 'post_parent' => $episode_id ?: 0, 'post_status' => 'any', 'posts_per_page' => -1 ] );
	foreach ( array_filter( $posts ) as $post ) {
		if ( '' === trim( wp_strip_all_tags( $post->post_content ) ) ) {
			$issues[] = [ 'severity' => 'warning', 'category' => 'content', 'description' => 'Scene has no content.', 'entities' => [ [ 'type' => $post->post_type, 'id' => $post->ID ] ], 'suggestion' => 'Add scene content before editorial review.' ];
		}
	}

	return [
		'total_issues'     => count( $issues ),
		'errors'           => 0,
		'warnings'         => count( $issues ),
		'infos'            => 0,
		'scenes_validated' => count( array_filter( $posts ) ),
		'issues'           => $issues,
	];
}

/**
 * Validate continuity for a single post when saved.
 *
 * Triggers validation for scenes and shots, and optionally for related entities.
 *
 * @param int    $post_id The post being saved.
 * @param \WP_Post $post The post object.
 * @param bool   $update Whether this is an update.
 */
function auto_check_continuity_on_save( int $post_id, \WP_Post $post, bool $update = false ): void {
	// Only run on actual CPTs.
	$cpts = worldgraph_get_all_cpts();
	if ( ! isset( $cpts[ $post->post_type ] ) ) {
		return;
	}

	// Skip autosave, revisions, and bulk actions.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( isset( $_REQUEST['bulk_edit'] ) ) {
		return;
	}

	// Only validate scenes and shots (primary continuity entities).
	$validate_types = [ 'worldgraph_scene', 'worldgraph_shot' ];
	if ( ! in_array( $post->post_type, $validate_types, true ) ) {
		return;
	}

	// Check if validation was explicitly requested via a meta flag.
	$validation_nonce = isset( $_POST['worldgraph_force_validation'] ) ? sanitize_text_field( wp_unslash( $_POST['worldgraph_force_validation'] ) ) : '';
	$force            = '' !== $validation_nonce && wp_verify_nonce( $validation_nonce, 'worldgraph_validation' );
	if ( ! $force && ! $update ) {
		// On initial create, only force validate. On update, always validate.
		return;
	}

	// Validate using local analysis.
	$result = fetch_continuity_validation( 0, [ $post_id ] );

	// Store issues in post meta for display in admin.
	$meta_key = WORLDGRAPH_CPT_PREFIX . 'continuity_issues';
	update_post_meta( $post_id, $meta_key, $result['issues'] );
	update_post_meta( $post_id, WORLDGRAPH_CPT_PREFIX . 'continuity_summary', [
		'total_issues'     => $result['total_issues'],
		'errors'           => $result['errors'],
		'warnings'         => $result['warnings'],
		'infos'            => $result['infos'],
		'scenes_validated' => $result['scenes_validated'],
		'validated_at'     => current_time( 'mysql' ),
	] );

	// Also store globally for the admin dashboard.
	store_global_continuity_issues( $result['issues'] );
}

/**
 * Store continuity issues globally (across all posts).
 *
 * @param array $issues The issues array.
 */
function store_global_continuity_issues( array $issues ): void {
	$meta_key = WORLDGRAPH_CPT_PREFIX . 'global_continuity_issues';
	$existing = get_option( $meta_key, [] );

	// Merge and deduplicate by description.
	$merged = [];
	$seen   = [];

	foreach ( array_merge( $existing, $issues ) as $issue ) {
		$hash = md5( $issue['description'] ?? '' );
		if ( ! isset( $seen[ $hash ] ) ) {
			$seen[ $hash ]  = true;
			$merged[]       = $issue;
		}
	}

	update_option( $meta_key, $merged, false );
}

/**
 * Get stored continuity issues for a post.
 *
 * @param int $post_id The post ID.
 * @return array
 */
function get_post_continuity_issues( int $post_id ): array {
	$issues = get_post_meta( $post_id, WORLDGRAPH_CPT_PREFIX . 'continuity_issues', true );
	return is_array( $issues ) ? $issues : [];
}

/**
 * Get stored continuity summary for a post.
 *
 * @param int $post_id The post ID.
 * @return array
 */
function get_post_continuity_summary( int $post_id ): array {
	$summary = get_post_meta( $post_id, WORLDGRAPH_CPT_PREFIX . 'continuity_summary', true );
	return is_array( $summary ) ? $summary : [];
}

/**
 * Get global continuity issues.
 *
 * @return array
 */
function get_global_continuity_issues(): array {
	$issues = get_option( WORLDGRAPH_CPT_PREFIX . 'global_continuity_issues', [] );
	return is_array( $issues ) ? $issues : [];
}

/**
 * Clear continuity issues for a post.
 *
 * @param int $post_id The post ID.
 * @return bool
 */
function clear_post_continuity_issues( int $post_id ): bool {
	delete_post_meta( $post_id, WORLDGRAPH_CPT_PREFIX . 'continuity_issues' );
	delete_post_meta( $post_id, WORLDGRAPH_CPT_PREFIX . 'continuity_summary' );
	return true;
}

/**
 * Clear all global continuity issues.
 *
 * @return bool
 */
function clear_global_continuity_issues(): bool {
	delete_option( WORLDGRAPH_CPT_PREFIX . 'global_continuity_issues' );
	return true;
}

/**
 * Filter continuity issues by severity.
 *
 * @param array $issues The issues array.
 * @param string $severity The severity to filter by ('error', 'warning', 'info').
 * @return array
 */
function filter_issues_by_severity( array $issues, string $severity ): array {
	return array_values( array_filter( $issues, function ( $issue ) use ( $severity ) {
		return ( $issue['severity'] ?? '' ) === $severity;
	} ) );
}

/**
 * Filter continuity issues by category.
 *
 * @param array $issues The issues array.
 * @param string $category The category to filter by.
 * @return array
 */
function filter_issues_by_category( array $issues, string $category ): array {
	return array_values( array_filter( $issues, function ( $issue ) use ( $category ) {
		return ( $issue['category'] ?? '' ) === $category;
	} ) );
}

/**
 * Get severity label and color.
 *
 * @param string $severity The severity.
 * @return array { label: string, color: string, icon: string }
 */
function severity_info( string $severity ): array {
	switch ( $severity ) {
		case 'error':
			return [
				'label'  => 'Error',
				'color'  => '#d63638',
				'icon'   => 'dismiss',
			];
		case 'warning':
			return [
				'label'  => 'Warning',
				'color'  => '#dba617',
				'icon'   => 'warning',
			];
		case 'info':
		default:
			return [
				'label'  => 'Info',
				'color'  => '#2271b1',
				'icon'   => 'info',
			];
	}
}

/**
 * Get category label.
 *
 * @param string $category The category.
 * @return string
 */
function category_label( string $category ): string {
	$labels = [
		'character' => 'Character',
		'scene'     => 'Scene',
		'location'  => 'Location',
		'prop'      => 'Prop',
		'timeline'  => 'Timeline',
		'general'   => 'General',
	];
	return $labels[ $category ] ?? ucfirst( $category );
}

/**
 * Build a permalink to an entity from its type and ID.
 *
 * @param string $type The entity type (CPT slug).
 * @param int    $id The entity ID.
 * @return string
 */
function entity_permalink( string $type, int $id ): string {
	return get_edit_post_link( $id, 'url' );
}

/**
 * Get entity display name.
 *
 * @param string $type The entity type.
 * @param int    $id The entity ID.
 * @return string
 */
if ( ! function_exists( __NAMESPACE__ . '\entity_display_name' ) ) :
function entity_display_name( string $type, int $id ): string {
	$title = get_the_title( $id );
	return $title ? $title : sprintf( '#%d', $id );
}
endif;
