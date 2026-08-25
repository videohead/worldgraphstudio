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
function fetch_continuity_validation( int $episode_id = 0, array $scene_ids = [], int $project_id = 0 ): array {
	$issues = [];
	if ( $project_id > 0 && 'worldgraph_project' === get_post_type( $project_id ) ) {
		$posts = continuity_project_validation_posts( $project_id );
	} else {
		$posts = ! empty( $scene_ids ) ? array_map( 'get_post', array_map( 'absint', $scene_ids ) ) : get_posts( [ 'post_type' => 'worldgraph_scene', 'post_parent' => $episode_id ?: 0, 'post_status' => 'any', 'posts_per_page' => -1 ] );
	}

	foreach ( array_filter( $posts ) as $post ) {
		if ( '' === trim( wp_strip_all_tags( $post->post_content ) ) ) {
			$post_type_label = continuity_entity_type_label( $post->post_type );
			$issues[]        = [
				'severity'    => 'warning',
				'category'    => 'content',
				'description' => sprintf( '%s has no content.', $post_type_label ),
				'entities'    => [
					build_issue_entity_context( $post ),
				],
				'suggestion'  => sprintf( 'Open and add %s content before editorial review.', strtolower( $post_type_label ) ),
			];
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

	// Merge and deduplicate by issue meaning + entities so separate records remain traceable.
	$merged = [];
	$seen   = [];

	foreach ( array_merge( $existing, $issues ) as $issue ) {
		$hash = continuity_issue_hash( $issue );
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
	$issues = is_array( $issues ) ? $issues : [];

	$pruned = array_values( array_filter( $issues, __NAMESPACE__ . '\continuity_issue_entities_exist' ) );
	if ( count( $pruned ) !== count( $issues ) ) {
		update_option( WORLDGRAPH_CPT_PREFIX . 'global_continuity_issues', $pruned, false );
	}

	return $pruned;
}

/**
 * Determine whether every entity referenced by an issue still exists.
 *
 * @param array $issue The issue array.
 * @return bool
 */
function continuity_issue_entities_exist( $issue ): bool {
	if ( ! is_array( $issue ) || empty( $issue['entities'] ) || ! is_array( $issue['entities'] ) ) {
		return true;
	}

	foreach ( $issue['entities'] as $entity ) {
		$entity_id = absint( is_array( $entity ) ? ( $entity['id'] ?? 0 ) : 0 );
		if ( ! $entity_id ) {
			continue;
		}
		$entity_post = get_post( $entity_id );
		if ( ! $entity_post || in_array( $entity_post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Remove stored continuity issues that reference a deleted or trashed post.
 *
 * @param int $post_id The post ID.
 */
function purge_continuity_issues_for_post( int $post_id ): void {
	clear_post_continuity_issues( $post_id );

	$meta_key = WORLDGRAPH_CPT_PREFIX . 'global_continuity_issues';
	$issues   = get_option( $meta_key, [] );
	if ( ! is_array( $issues ) || empty( $issues ) ) {
		return;
	}

	$remaining = array_values( array_filter( $issues, function ( $issue ) use ( $post_id ) {
		if ( ! is_array( $issue ) || empty( $issue['entities'] ) || ! is_array( $issue['entities'] ) ) {
			return true;
		}
		foreach ( $issue['entities'] as $entity ) {
			if ( is_array( $entity ) && absint( $entity['id'] ?? 0 ) === $post_id ) {
				return false;
			}
		}
		return true;
	} ) );

	if ( count( $remaining ) !== count( $issues ) ) {
		update_option( $meta_key, $remaining, false );
	}
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
	$link = get_edit_post_link( $id, 'url' );
	return is_string( $link ) ? $link : '';
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

/**
 * Build structured entity context for a continuity issue.
 *
 * @param \WP_Post $post Source entity post.
 * @return array
 */
function build_issue_entity_context( \WP_Post $post ): array {
	$post_type_label = continuity_entity_type_label( $post->post_type );
	$title           = get_the_title( $post->ID );
	$label           = trim( sprintf( '%s #%d%s', $post_type_label, $post->ID, $title ? ': ' . $title : '' ) );

	$context = [
		'type'       => $post->post_type,
		'id'         => (int) $post->ID,
		'title'      => $title ? (string) $title : '',
		'label'      => $label,
		'edit_url'   => (string) get_edit_post_link( $post->ID, 'url' ),
		'review_url' => (string) get_permalink( $post->ID ),
	];

	// Include the parent Scene for Shot issues so editors can orient quickly.
	if ( 'worldgraph_shot' === $post->post_type ) {
		$scene_id = (int) worldgraph_get_shot_canonical_scene_id( $post->ID );
		if ( $scene_id > 0 ) {
			$scene_title                 = get_the_title( $scene_id );
			$context['scene']            = [
				'id'       => $scene_id,
				'title'    => $scene_title ? (string) $scene_title : '',
				'label'    => sprintf( 'Scene #%d%s', $scene_id, $scene_title ? ': ' . $scene_title : '' ),
				'edit_url' => (string) get_edit_post_link( $scene_id, 'url' ),
			];
		}
	}

	return $context;
}

/**
 * Build a stable hash for continuity issue deduplication.
 *
 * @param array $issue Issue payload.
 * @return string
 */
function continuity_issue_hash( array $issue ): string {
	$entity_refs = [];
	foreach ( (array) ( $issue['entities'] ?? [] ) as $entity ) {
		$entity_refs[] = sprintf( '%s:%d', sanitize_key( (string) ( $entity['type'] ?? '' ) ), absint( $entity['id'] ?? 0 ) );
	}

	sort( $entity_refs );

	$fingerprint = [
		'severity'    => sanitize_key( (string) ( $issue['severity'] ?? '' ) ),
		'category'    => sanitize_key( (string) ( $issue['category'] ?? '' ) ),
		'description' => (string) ( $issue['description'] ?? '' ),
		'suggestion'  => (string) ( $issue['suggestion'] ?? '' ),
		'entities'    => $entity_refs,
	];

	return md5( wp_json_encode( $fingerprint ) );
}

/**
 * Resolve a human-friendly singular label for a post type.
 *
 * @param string $post_type Post type slug.
 * @return string
 */
function continuity_entity_type_label( string $post_type ): string {
	$post_type_obj = get_post_type_object( $post_type );
	if ( $post_type_obj && isset( $post_type_obj->labels->singular_name ) ) {
		return (string) $post_type_obj->labels->singular_name;
	}

	return ucfirst( str_replace( '_', ' ', $post_type ) );
}

/**
 * Resolve the set of posts validated for a single project.
 *
 * @param int $project_id Project post ID.
 * @return array<int, \WP_Post>
 */
function continuity_project_validation_posts( int $project_id ): array {
	$scene_ids = continuity_project_scene_ids( $project_id );
	$shot_ids  = continuity_project_shot_ids( $scene_ids );

	$post_ids = array_values( array_unique( array_filter( array_map( 'absint', array_merge( $scene_ids, $shot_ids ) ) ) ) );
	if ( empty( $post_ids ) ) {
		return [];
	}

	$posts = get_posts( [
		'post_type'      => [ 'worldgraph_scene', 'worldgraph_shot' ],
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'post__in'       => $post_ids,
		'orderby'        => 'post__in',
	] );

	return array_values( array_filter( $posts, static function ( $post ): bool {
		return $post instanceof \WP_Post;
	} ) );
}

/**
 * Resolve Scene IDs that belong to one Project through canonical and legacy links.
 *
 * @param int $project_id Project post ID.
 * @return array<int, int>
 */
function continuity_project_scene_ids( int $project_id ): array {
	$scene_ids   = [];
	$episode_ids = [];

	foreach ( continuity_relationship_targets( $project_id, 'worldgraph_project' ) as $target ) {
		if ( 'worldgraph_scene' === $target['type'] ) {
			$scene_ids[] = $target['id'];
		} elseif ( 'worldgraph_episode' === $target['type'] ) {
			$episode_ids[] = $target['id'];
		}
	}

	$episodes = get_posts( [
		'post_type'      => 'worldgraph_episode',
		'post_status'    => 'any',
		'posts_per_page' => -1,
	] );
	foreach ( $episodes as $episode ) {
		$episode_id = absint( $episode->ID ?? 0 );
		$owner_id   = absint( worldgraph_get_field_value( $episode_id, 'project' ) );
		if ( $project_id === $owner_id || continuity_has_relationship_target( $episode_id, 'worldgraph_episode', $project_id, 'worldgraph_project' ) ) {
			$episode_ids[] = $episode_id;
		}
	}
	$episode_ids = array_values( array_unique( array_filter( array_map( 'absint', $episode_ids ) ) ) );

	foreach ( $episode_ids as $episode_id ) {
		foreach ( continuity_relationship_targets( $episode_id, 'worldgraph_episode' ) as $target ) {
			if ( 'worldgraph_scene' === $target['type'] ) {
				$scene_ids[] = $target['id'];
			}
		}
	}

	$scenes = get_posts( [
		'post_type'      => 'worldgraph_scene',
		'post_status'    => 'any',
		'posts_per_page' => -1,
	] );
	foreach ( $scenes as $scene ) {
		$scene_id = absint( $scene->ID ?? 0 );
		if (
			$project_id === absint( worldgraph_get_field_value( $scene_id, 'project' ) )
			|| continuity_has_relationship_target( $scene_id, 'worldgraph_scene', $project_id, 'worldgraph_project' )
		) {
			$scene_ids[] = $scene_id;
			continue;
		}

		$episode_id = absint( worldgraph_get_field_value( $scene_id, 'episode' ) );
		if ( in_array( $episode_id, $episode_ids, true ) ) {
			$scene_ids[] = $scene_id;
			continue;
		}

		foreach ( continuity_relationship_targets( $scene_id, 'worldgraph_scene' ) as $target ) {
			if ( 'worldgraph_episode' === $target['type'] && in_array( $target['id'], $episode_ids, true ) ) {
				$scene_ids[] = $scene_id;
				break;
			}
		}
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $scene_ids ) ) ) );
}

/**
 * Resolve Shot IDs that belong to the supplied Scene IDs.
 *
 * @param array<int, int> $scene_ids Scene post IDs.
 * @return array<int, int>
 */
function continuity_project_shot_ids( array $scene_ids ): array {
	$scene_ids = array_values( array_unique( array_filter( array_map( 'absint', $scene_ids ) ) ) );
	if ( empty( $scene_ids ) ) {
		return [];
	}

	$scene_lookup = array_fill_keys( $scene_ids, true );
	$shot_ids     = [];
	foreach ( $scene_ids as $scene_id ) {
		foreach ( continuity_relationship_targets( $scene_id, 'worldgraph_scene' ) as $target ) {
			if ( 'worldgraph_shot' === $target['type'] ) {
				$shot_ids[] = $target['id'];
			}
		}
	}

	$shots = get_posts( [
		'post_type'      => 'worldgraph_shot',
		'post_status'    => 'any',
		'posts_per_page' => -1,
	] );
	foreach ( $shots as $shot ) {
		$shot_id  = absint( $shot->ID ?? 0 );
		$scene_id = absint( worldgraph_get_field_value( $shot_id, 'scene' ) );
		if ( isset( $scene_lookup[ $scene_id ] ) ) {
			$shot_ids[] = $shot_id;
			continue;
		}

		foreach ( continuity_relationship_targets( $shot_id, 'worldgraph_shot' ) as $target ) {
			if ( 'worldgraph_scene' === $target['type'] && isset( $scene_lookup[ $target['id'] ] ) ) {
				$shot_ids[] = $shot_id;
				break;
			}
		}
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $shot_ids ) ) ) );
}

/**
 * Return normalized outgoing Story Graph targets for one source entity.
 *
 * @param int    $post_id Source post ID.
 * @param string $post_type Source post type.
 * @return array<int, array{id:int,type:string}>
 */
function continuity_relationship_targets( int $post_id, string $post_type ): array {
	$targets = [];
	foreach ( get_relationships( $post_id, $post_type, 'outgoing' ) as $relationship ) {
		$target_id   = absint( $relationship['to_id'] ?? 0 );
		$target_type = (string) ( $relationship['to_type'] ?? '' );
		if ( $target_id > 0 && '' !== $target_type ) {
			$targets[] = [
				'id'   => $target_id,
				'type' => $target_type,
			];
		}
	}

	return $targets;
}

/**
 * Check whether a source entity has one outgoing edge to the supplied target.
 *
 * @param int    $post_id Source post ID.
 * @param string $post_type Source post type.
 * @param int    $target_id Target post ID.
 * @param string $target_type Target post type.
 * @return bool
 */
function continuity_has_relationship_target( int $post_id, string $post_type, int $target_id, string $target_type ): bool {
	foreach ( continuity_relationship_targets( $post_id, $post_type ) as $target ) {
		if ( $target_id === $target['id'] && $target_type === $target['type'] ) {
			return true;
		}
	}

	return false;
}
