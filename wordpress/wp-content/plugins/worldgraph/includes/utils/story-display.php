<?php
/**
 * Read-only presentation helpers for WordPress and headless story templates.
 *
 * This layer resolves existing Story Graph fields, relationships, and media. It
 * does not persist a second content model or make presentation data writable.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post types with purpose-built story displays.
 *
 * @return array<int, string>
 */
function worldgraph_story_display_post_types(): array {
	return [
		'worldgraph_project',
		'worldgraph_world',
		'worldgraph_character',
		'worldgraph_scene',
		'worldgraph_shot',
		'worldgraph_prop',
		'worldgraph_sound',
		'worldgraph_asset',
	];
}

/**
 * Story entity types permitted in public Project analytics.
 *
 * Provider Connections and generation Templates are deliberately excluded even
 * when they happen to be published, because they are operational records rather
 * than part of the public story graph.
 *
 * @return array<int, string>
 */
function worldgraph_story_display_graph_post_types(): array {
	return [
		'worldgraph_project',
		'worldgraph_world',
		'worldgraph_character',
		'worldgraph_location',
		'worldgraph_prop',
		'worldgraph_org',
		'worldgraph_episode',
		'worldgraph_scene',
		'worldgraph_shot',
		'worldgraph_sound',
		'worldgraph_asset',
		'worldgraph_editorial',
	];
}

/**
 * Whether a related post may appear in a presentation response.
 *
 * Published records are public. Editors may also see records they can read,
 * which keeps wp-admin and authenticated previews useful without exposing
 * drafts through anonymous headless requests.
 *
 * @param int  $post_id         Post ID.
 * @param bool $include_private Whether readable non-published posts may appear.
 * @return bool
 */
function worldgraph_story_display_can_read( int $post_id, bool $include_private = false ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post ) {
		return false;
	}

	if ( $include_private && current_user_can( 'read_post', $post_id ) ) {
		return true;
	}

	return 'publish' === $post->post_status && ( '' === (string) $post->post_password || ! post_password_required( $post ) );
}

/**
 * Find posts related to one Story Graph entity in either edge direction.
 *
 * @param int          $post_id         Source post ID.
 * @param string       $post_type       Source post type.
 * @param string       $related_type    Related post type.
 * @param bool         $include_private Whether readable non-published posts may appear.
 * @param array|string $relationship_types Optional relationship verb or verbs.
 * @return array<int, \WP_Post>
 */
function worldgraph_story_display_related_posts( int $post_id, string $post_type, string $related_type, bool $include_private = false, $relationship_types = [] ): array {
	$related_ids       = [];
	$relationship_types = array_values( array_filter( array_map( 'sanitize_key', (array) $relationship_types ) ) );

	foreach ( get_relationships( $post_id, $post_type, 'outgoing' ) as $relationship ) {
		if ( $related_type !== (string) ( $relationship['to_type'] ?? '' ) ) {
			continue;
		}
		if ( ! empty( $relationship_types ) && ! in_array( (string) ( $relationship['type'] ?? '' ), $relationship_types, true ) ) {
			continue;
		}
		$related_ids[] = absint( $relationship['to_id'] ?? 0 );
	}

	foreach ( get_relationships( $post_id, $post_type, 'incoming' ) as $relationship ) {
		if ( $related_type !== (string) ( $relationship['from_type'] ?? '' ) ) {
			continue;
		}
		if ( ! empty( $relationship_types ) && ! in_array( (string) ( $relationship['type'] ?? '' ), $relationship_types, true ) ) {
			continue;
		}
		$related_ids[] = absint( $relationship['from_id'] ?? 0 );
	}

	$related_ids = array_values( array_unique( array_filter( $related_ids ) ) );
	if ( empty( $related_ids ) ) {
		return [];
	}

	$posts = get_posts(
		[
			'post_type'      => $related_type,
			'post_status'    => $include_private ? 'any' : 'publish',
			'post__in'       => $related_ids,
			'posts_per_page' => -1,
		]
	);

	return array_values(
		array_filter(
			$posts,
			static function( \WP_Post $post ) use ( $include_private ): bool {
				return worldgraph_story_display_can_read( $post->ID, $include_private );
			}
		)
	);
}

/**
 * Resolve the single canonical Scene owned by a Shot.
 *
 * Named `scene` edges written by SCF take precedence, followed by older
 * unnamed child-owned edges, the scalar SCF projection, and finally a legacy
 * parent-owned Scene edge. Each tier chooses one deterministic owner so a Shot
 * cannot appear in multiple Scene sequencers.
 *
 * @param int $shot_id Shot post ID.
 * @return int Scene post ID, or zero when the Shot has no canonical owner.
 */
function worldgraph_get_shot_canonical_scene_id( int $shot_id ): int {
	if ( 'worldgraph_shot' !== get_post_type( $shot_id ) ) {
		return 0;
	}

	$named_scene_ids = [];
	$scene_ids       = [];
	foreach ( get_relationships( $shot_id, 'worldgraph_shot', 'outgoing' ) as $relationship ) {
		if ( 'worldgraph_scene' !== (string) ( $relationship['to_type'] ?? '' ) || 'belongs_to' !== (string) ( $relationship['type'] ?? '' ) ) {
			continue;
		}

		$scene_id = absint( $relationship['to_id'] ?? 0 );
		if ( ! $scene_id ) {
			continue;
		}
		$scene_ids[] = $scene_id;
		if ( 'scene' === sanitize_key( (string) ( $relationship['metadata']['field'] ?? '' ) ) ) {
			$named_scene_ids[] = $scene_id;
		}
	}

	$named_scene_ids = array_values( array_unique( $named_scene_ids ) );
	sort( $named_scene_ids, SORT_NUMERIC );
	if ( ! empty( $named_scene_ids ) ) {
		return (int) $named_scene_ids[0];
	}

	$scene_ids = array_values( array_unique( $scene_ids ) );
	sort( $scene_ids, SORT_NUMERIC );
	if ( ! empty( $scene_ids ) ) {
		return (int) $scene_ids[0];
	}

	$meta_scene = worldgraph_get_field_value( $shot_id, 'scene' );
	if ( $meta_scene instanceof \WP_Post ) {
		$meta_scene = $meta_scene->ID;
	} elseif ( is_array( $meta_scene ) ) {
		$first_meta_scene = reset( $meta_scene );
		$meta_scene       = $first_meta_scene instanceof \WP_Post ? $first_meta_scene->ID : $first_meta_scene;
	}
	$meta_scene_id = absint( $meta_scene );
	if ( $meta_scene_id && 'worldgraph_scene' === get_post_type( $meta_scene_id ) ) {
		return $meta_scene_id;
	}

	$legacy_scene_ids = [];
	foreach ( get_relationships( $shot_id, 'worldgraph_shot', 'incoming' ) as $relationship ) {
		if ( 'worldgraph_scene' === (string) ( $relationship['from_type'] ?? '' ) && in_array( (string) ( $relationship['type'] ?? '' ), [ 'contains', 'belongs_to' ], true ) ) {
			$legacy_scene_ids[] = absint( $relationship['from_id'] ?? 0 );
		}
	}
	$legacy_scene_ids = array_values( array_unique( array_filter( $legacy_scene_ids ) ) );
	sort( $legacy_scene_ids, SORT_NUMERIC );

	return empty( $legacy_scene_ids ) ? 0 : (int) $legacy_scene_ids[0];
}

/**
 * Return the Shots belonging to a Scene in editorial order.
 *
 * Canonical child-owned relationships are preferred, while the SCF `scene`
 * value and legacy parent-owned edges remain supported during migration.
 *
 * @param int  $scene_id        Scene post ID.
 * @param bool $include_private Whether readable non-published Shots may appear.
 * @return array<int, \WP_Post>
 */
function worldgraph_get_scene_display_shots( int $scene_id, bool $include_private = false ): array {
	if ( 'worldgraph_scene' !== get_post_type( $scene_id ) ) {
		return [];
	}

	$shots = worldgraph_story_display_related_posts(
		$scene_id,
		'worldgraph_scene',
		'worldgraph_shot',
		$include_private,
		[ 'belongs_to', 'contains' ]
	);
	$by_id = [];
	foreach ( $shots as $shot ) {
		$canonical_scene_id = worldgraph_get_shot_canonical_scene_id( $shot->ID );
		if ( $canonical_scene_id && $scene_id !== $canonical_scene_id ) {
			continue;
		}
		$by_id[ $shot->ID ] = $shot;
	}

	// SCF retains the relationship value as a compatibility projection. Include
	// it when a legacy record has not yet acquired a canonical graph edge.
	$meta_shots = get_posts(
		[
			'post_type'      => 'worldgraph_shot',
			'post_status'    => $include_private ? 'any' : 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'     => 'scene',
					'value'   => $scene_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				],
			],
		]
	);
	foreach ( $meta_shots as $shot ) {
		$canonical_scene_id = worldgraph_get_shot_canonical_scene_id( $shot->ID );
		if ( ( ! $canonical_scene_id || $scene_id === $canonical_scene_id ) && worldgraph_story_display_can_read( $shot->ID, $include_private ) ) {
			$by_id[ $shot->ID ] = $shot;
		}
	}

	$shots = array_values( $by_id );
	usort(
		$shots,
		static function( \WP_Post $left, \WP_Post $right ): int {
			$left_number  = absint( worldgraph_get_field_value( $left->ID, 'shot_number' ) );
			$right_number = absint( worldgraph_get_field_value( $right->ID, 'shot_number' ) );
			$left_order   = (int) $left->menu_order > 0 ? (int) $left->menu_order : ( $left_number ?: PHP_INT_MAX );
			$right_order  = (int) $right->menu_order > 0 ? (int) $right->menu_order : ( $right_number ?: PHP_INT_MAX );
			if ( $left_order !== $right_order ) {
				return $left_order <=> $right_order;
			}

			if ( $left_number !== $right_number ) {
				return $left_number <=> $right_number;
			}

			$title_order = strcasecmp( $left->post_title, $right->post_title );
			return 0 !== $title_order ? $title_order : $left->ID <=> $right->ID;
		}
	);

	return $shots;
}

/**
 * Convert a media-library attachment to the display DTO.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $origin        How the media is connected to the story item.
 * @param bool   $include_private Whether readable private media may appear.
 * @return array<string, mixed>|null
 */
function worldgraph_story_display_attachment( int $attachment_id, string $origin = 'gallery', bool $include_private = false ): ?array {
	$attachment = get_post( $attachment_id );
	if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
		return null;
	}

	$can_read = $include_private && current_user_can( 'read_post', $attachment_id );
	if ( ! $can_read && 'publish' === $attachment->post_status ) {
		$can_read = true;
	}
	if ( ! $can_read && 'inherit' === $attachment->post_status ) {
		$can_read = ! $attachment->post_parent || worldgraph_story_display_can_read( (int) $attachment->post_parent, $include_private );
	}
	if ( ! $can_read ) {
		return null;
	}

	$url = wp_get_attachment_url( $attachment_id );
	if ( ! $url ) {
		return null;
	}

	$metadata = wp_get_attachment_metadata( $attachment_id );
	return [
		'id'            => $attachment_id,
		'url'           => esc_url_raw( $url ),
		'thumbnail_url' => esc_url_raw( wp_get_attachment_image_url( $attachment_id, 'medium' ) ?: $url ),
		'alt'           => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		'title'         => get_the_title( $attachment_id ),
		'caption'       => wp_strip_all_tags( (string) $attachment->post_excerpt ),
		'mime_type'     => (string) get_post_mime_type( $attachment_id ),
		'width'         => is_array( $metadata ) ? absint( $metadata['width'] ?? 0 ) : 0,
		'height'        => is_array( $metadata ) ? absint( $metadata['height'] ?? 0 ) : 0,
		'intent'        => sanitize_key( (string) get_post_meta( $attachment_id, '_worldgraph_gen_intent', true ) ),
		'origin'        => sanitize_key( $origin ),
	];
}

/**
 * Infer a media MIME type for an Asset storage URL.
 *
 * @param int    $asset_id Asset post ID.
 * @param string $url      Public storage URL.
 * @return string
 */
function worldgraph_story_display_asset_mime_type( int $asset_id, string $url ): string {
	$file_type = wp_check_filetype( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	if ( ! empty( $file_type['type'] ) ) {
		return (string) $file_type['type'];
	}

	$terms = get_the_terms( $asset_id, 'worldgraph_asset_type' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		$slugs = wp_list_pluck( $terms, 'slug' );
		if ( in_array( 'audio', $slugs, true ) ) {
			return 'audio/mpeg';
		}
		if ( in_array( 'video', $slugs, true ) ) {
			return 'video/mp4';
		}
		if ( array_intersect( $slugs, [ 'image', 'character', 'environment', 'prop', 'storyboard', 'lookbook', 'concept-art' ] ) ) {
			return 'image/jpeg';
		}
	}

	return '';
}

/**
 * Stable presentation rank for generated look-development frames.
 *
 * @param string $intent Generation intent stored on the attachment.
 * @return int|null
 */
function worldgraph_story_display_intent_rank( string $intent ): ?int {
	$order = [
		'project-key-art',
		'world-key-art',
		'character-full-view',
		'character-front-view',
		'character-three-quarter-view',
		'character-profile-view',
		'character-back-view',
		'character-close-up',
		'prop-full-view',
		'prop-front-view',
		'prop-three-quarter-view',
		'prop-profile-view',
		'prop-back-view',
		'prop-close-up',
		'location-full-view',
		'location-front-view',
		'location-three-quarter-view',
		'location-profile-view',
		'location-back-view',
		'location-close-up',
		'shot-representative-still',
		'shot-video',
		'scene-filmstrip',
		'episode-bookend-filmstrip',
	];
	$rank  = array_search( sanitize_key( $intent ), $order, true );

	return false === $rank ? null : (int) $rank;
}

/**
 * Resolve public media represented by a World Graph Asset post.
 *
 * @param int  $asset_id        Asset post ID.
 * @param bool $include_private Whether readable non-published Assets may appear.
 * @return array<int, array<string, mixed>>
 */
function worldgraph_story_display_asset_media( int $asset_id, bool $include_private = false ): array {
	if ( 'worldgraph_asset' !== get_post_type( $asset_id ) || ! worldgraph_story_display_can_read( $asset_id, $include_private ) ) {
		return [];
	}

	$media       = [];
	$featured_id = get_post_thumbnail_id( $asset_id );
	if ( $featured_id ) {
		$item = worldgraph_story_display_attachment( $featured_id, 'asset', $include_private );
		if ( $item ) {
			$media[] = $item;
		}
	}

	foreach ( array_filter( array_map( 'absint', (array) get_post_meta( $asset_id, '_worldgraph_asset_gallery_ids', true ) ) ) as $attachment_id ) {
		$item = worldgraph_story_display_attachment( $attachment_id, 'asset_gallery', $include_private );
		if ( $item ) {
			$media[] = $item;
		}
	}

	$storage_url = trim( (string) worldgraph_get_field_value( $asset_id, 'storage_uri' ) );
	$scheme      = strtolower( (string) wp_parse_url( $storage_url, PHP_URL_SCHEME ) );
	if ( $storage_url && in_array( $scheme, [ 'http', 'https' ], true ) ) {
		$thumbnail_url = $featured_id ? wp_get_attachment_image_url( $featured_id, 'medium' ) : '';
		$media[]       = [
			'id'            => 0,
			'asset_id'      => $asset_id,
			'url'           => esc_url_raw( $storage_url ),
			'thumbnail_url' => esc_url_raw( $thumbnail_url ?: $storage_url ),
			'alt'           => get_the_title( $asset_id ),
			'title'         => get_the_title( $asset_id ),
			'caption'       => '',
			'mime_type'     => worldgraph_story_display_asset_mime_type( $asset_id, $storage_url ),
			'width'         => 0,
			'height'        => 0,
			'intent'        => sanitize_key( (string) get_post_meta( $asset_id, '_worldgraph_gen_intent', true ) ),
			'origin'        => 'asset',
		];
	}

	return $media;
}

/**
 * Resolve featured, gallery, and linked Asset media for one story item.
 *
 * @param int  $post_id         Story item ID.
 * @param bool $include_private Whether readable non-published Assets may appear.
 * @return array<int, array<string, mixed>>
 */
function worldgraph_get_story_display_media( int $post_id, bool $include_private = false ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, worldgraph_story_display_post_types(), true ) || ! worldgraph_story_display_can_read( $post_id, $include_private ) ) {
		return [];
	}

	$media       = [];
	$featured_id = get_post_thumbnail_id( $post_id );
	if ( $featured_id ) {
		$item = worldgraph_story_display_attachment( $featured_id, 'featured', $include_private );
		if ( $item ) {
			$media[] = $item;
		}
	}

	foreach ( array_filter( array_map( 'absint', (array) get_post_meta( $post_id, '_worldgraph_asset_gallery_ids', true ) ) ) as $attachment_id ) {
		$item = worldgraph_story_display_attachment( $attachment_id, 'gallery', $include_private );
		if ( $item ) {
			$media[] = $item;
		}
	}

	$asset_ids = [];
	if ( 'worldgraph_asset' === $post->post_type ) {
		$asset_ids[] = $post_id;
	}

	foreach ( worldgraph_get_fields( $post->post_type ) as $field_name => $field ) {
		if ( 'relationship' !== ( $field['type'] ?? '' ) || 'worldgraph_asset' !== ( $field['related_cpt'] ?? '' ) ) {
			continue;
		}
		$asset_ids = array_merge( $asset_ids, array_map( 'absint', (array) worldgraph_get_field_value( $post_id, $field_name ) ) );
	}

	foreach ( worldgraph_story_display_related_posts( $post_id, $post->post_type, 'worldgraph_asset', $include_private ) as $asset ) {
		$asset_ids[] = $asset->ID;
	}

	foreach ( array_values( array_unique( array_filter( $asset_ids ) ) ) as $asset_id ) {
		$media = array_merge( $media, worldgraph_story_display_asset_media( $asset_id, $include_private ) );
	}

	$deduplicated = [];
	foreach ( $media as $index => $item ) {
		$url = (string) ( $item['url'] ?? '' );
		if ( '' === $url || isset( $deduplicated[ $url ] ) ) {
			continue;
		}
		$item['_display_index'] = $index;
		$deduplicated[ $url ] = $item;
	}

	$media = array_values( $deduplicated );
	usort(
		$media,
		static function( array $left, array $right ): int {
			$left_featured  = 'featured' === (string) ( $left['origin'] ?? '' );
			$right_featured = 'featured' === (string) ( $right['origin'] ?? '' );
			if ( $left_featured !== $right_featured ) {
				return $left_featured ? -1 : 1;
			}

			$left_rank  = worldgraph_story_display_intent_rank( (string) ( $left['intent'] ?? '' ) );
			$right_rank = worldgraph_story_display_intent_rank( (string) ( $right['intent'] ?? '' ) );
			if ( null !== $left_rank && null !== $right_rank && $left_rank !== $right_rank ) {
				return $left_rank <=> $right_rank;
			}
			if ( null !== $left_rank && null === $right_rank ) {
				return -1;
			}
			if ( null === $left_rank && null !== $right_rank ) {
				return 1;
			}
			return (int) ( $left['_display_index'] ?? 0 ) <=> (int) ( $right['_display_index'] ?? 0 );
		}
	);

	return array_map(
		static function( array $item ): array {
			unset( $item['_display_index'] );
			return $item;
		},
		$media
	);
}

/**
 * Summarize Project graph analytics with the existing intelligence service.
 *
 * Anonymous responses exclude every non-published graph node and any edge that
 * touches it. Editors receive the full Project graph they are authorized to
 * inspect in WordPress.
 *
 * @param int  $project_id      Project post ID.
 * @param bool $include_private Whether readable non-published nodes may appear.
 * @return array<string, mixed>
 */
function worldgraph_get_project_display_analytics( int $project_id, bool $include_private = false ): array {
	if ( 'worldgraph_project' !== get_post_type( $project_id ) || ! function_exists( __NAMESPACE__ . '\\fetch_relationship_graph' ) ) {
		return [];
	}

	$cache_key = '';
	if ( ! $include_private ) {
		$version   = max( 1, (int) get_option( 'worldgraph_story_display_cache_version', 1 ) );
		$cache_key = 'worldgraph_display_v2_' . $version . '_' . $project_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	// Visibility must be applied before Project-nearest scoping. Otherwise an
	// unreadable competing Project can capture a shared public node and skew the
	// selected Project's public totals without appearing in the response.
	$graph       = fetch_relationship_graph();
	$visible_ids = [];
	$nodes       = [];
	foreach ( (array) ( $graph['nodes'] ?? [] ) as $node ) {
		$node_id   = absint( $node['id'] ?? 0 );
		$node_type = (string) ( $node['type'] ?? '' );
		$node_post = $node_id ? get_post( $node_id ) : null;
		$can_read  = $include_private
			? worldgraph_story_display_can_read( $node_id, true )
			: $node_post instanceof \WP_Post && 'publish' === $node_post->post_status && '' === (string) $node_post->post_password;
		if ( $node_id && in_array( $node_type, worldgraph_story_display_graph_post_types(), true ) && $can_read ) {
			$visible_ids[ $node_id ] = true;
			$nodes[]                 = $node;
		}
	}
	$graph['nodes'] = $nodes;
	$graph['edges'] = array_values(
		array_filter(
			(array) ( $graph['edges'] ?? [] ),
			static function( array $edge ) use ( $visible_ids ): bool {
				return isset( $visible_ids[ absint( $edge['source'] ?? 0 ) ], $visible_ids[ absint( $edge['target'] ?? 0 ) ] );
			}
		)
	);
	$graph = filter_relationship_graph_by_project( $graph, $project_id );

	$analytics = analyze_relationship_graph( $graph );
	$summary   = [
		'total_entities'      => (int) ( $analytics['total_entities'] ?? 0 ),
		'total_relationships' => (int) ( $analytics['total_relationships'] ?? 0 ),
		'density'             => (float) ( $analytics['density'] ?? 0 ),
		'isolated_count'      => count( (array) ( $analytics['isolated_entities'] ?? [] ) ),
		'entity_counts'       => (array) ( $analytics['entity_counts'] ?? [] ),
		'most_connected'      => array_slice( (array) ( $analytics['most_connected'] ?? [] ), 0, 5 ),
		'development'         => (array) ( $analytics['development'] ?? [] ),
	];
	if ( $cache_key ) {
		set_transient( $cache_key, $summary, 5 * MINUTE_IN_SECONDS );
	}

	return $summary;
}

/**
 * Human-readable production-stage label.
 *
 * @param string $stage Production-stage slug.
 * @return string
 */
function worldgraph_story_display_stage_label( string $stage ): string {
	$labels = [
		'concept'         => __( 'Concept', 'worldgraph' ),
		'development'     => __( 'Development', 'worldgraph' ),
		'pre_production'  => __( 'Pre-Production', 'worldgraph' ),
		'production'      => __( 'Production', 'worldgraph' ),
		'post_production' => __( 'Post-Production', 'worldgraph' ),
		'released'        => __( 'Released', 'worldgraph' ),
	];

	return $labels[ $stage ] ?? ucwords( str_replace( '_', ' ', $stage ) );
}

/**
 * Create a compact Shot record for a Scene presentation.
 *
 * @param \WP_Post $shot            Shot post.
 * @param bool     $include_private Whether readable non-published media may appear.
 * @return array<string, mixed>
 */
function worldgraph_story_display_shot_payload( \WP_Post $shot, bool $include_private = false ): array {
	$fields = [];
	foreach ( [ 'shot_number', 'shot_type', 'camera_angle', 'lens', 'duration', 'take_number', 'slate_id', 'shot_description', 'editorial_notes' ] as $field_name ) {
		$value = worldgraph_get_field_value( $shot->ID, $field_name );
		if ( '' !== $value && null !== $value ) {
			$fields[ $field_name ] = $value;
		}
	}

	return [
		'id'         => $shot->ID,
		'slug'       => $shot->post_name,
		'title'      => $shot->post_title,
		'menu_order' => (int) $shot->menu_order,
		'excerpt'    => $shot->post_excerpt,
		'meta'       => $fields,
		'media'      => worldgraph_get_story_display_media( $shot->ID, $include_private ),
	];
}

/**
 * Count visible related Story Graph posts by type.
 *
 * @param int    $post_id         Source post ID.
 * @param string $post_type       Source post type.
 * @param bool   $include_private Whether readable non-published posts may appear.
 * @return array<string, int>
 */
function worldgraph_story_display_relationship_counts( int $post_id, string $post_type, bool $include_private = false ): array {
	$counts        = [];
	$related_types = [
		'worldgraph_character',
		'worldgraph_location',
		'worldgraph_prop',
		'worldgraph_org',
		'worldgraph_episode',
		'worldgraph_scene',
		'worldgraph_shot',
		'worldgraph_sound',
		'worldgraph_asset',
	];
	foreach ( $related_types as $related_type ) {
		if ( $related_type === $post_type ) {
			continue;
		}
		$count = count( worldgraph_story_display_related_posts( $post_id, $post_type, $related_type, $include_private ) );
		if ( $count > 0 ) {
			$counts[ $related_type ] = $count;
		}
	}

	return $counts;
}

/**
 * Invalidate public presentation aggregates after Story Graph changes.
 *
 * @param int $post_id Optional post ID supplied by WordPress hooks.
 */
function worldgraph_invalidate_story_display_cache( int $post_id = 0 ): void {
	unset( $post_id );
	$version = max( 1, (int) get_option( 'worldgraph_story_display_cache_version', 1 ) );
	update_option( 'worldgraph_story_display_cache_version', $version + 1, false );
}

/**
 * Invalidate presentation aggregates when relationship metadata changes.
 *
 * @param int|array<int, int> $meta_id Metadata row ID, or IDs after deletion.
 * @param int    $post_id  Post ID.
 * @param string $meta_key Metadata key.
 */
function worldgraph_maybe_invalidate_story_display_meta_cache( int|array $meta_id, int $post_id, string $meta_key ): void {
	if ( WORLDGRAPH_CPT_PREFIX . 'relationships' === $meta_key || '_worldgraph_asset_gallery_ids' === $meta_key ) {
		worldgraph_invalidate_story_display_cache();
	}
}

/** Register presentation hooks shared by REST and the WordPress theme. */
function worldgraph_story_display_init(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\worldgraph_register_story_display_rest_field' );
	foreach ( array_keys( worldgraph_get_all_cpts() ) as $post_type ) {
		add_filter( 'rest_prepare_' . $post_type, __NAMESPACE__ . '\\worldgraph_hide_protected_story_rest_fields', PHP_INT_MAX, 3 );
	}
	add_action( 'save_post', __NAMESPACE__ . '\\worldgraph_invalidate_story_display_cache', 30 );
	add_action( 'deleted_post', __NAMESPACE__ . '\\worldgraph_invalidate_story_display_cache' );
	add_action( 'added_post_meta', __NAMESPACE__ . '\\worldgraph_maybe_invalidate_story_display_meta_cache', 10, 3 );
	add_action( 'updated_post_meta', __NAMESPACE__ . '\\worldgraph_maybe_invalidate_story_display_meta_cache', 10, 3 );
	add_action( 'deleted_post_meta', __NAMESPACE__ . '\\worldgraph_maybe_invalidate_story_display_meta_cache', 10, 3 );
}

/**
 * Remove SCF presentation data when core withholds a password-protected post.
 *
 * Secure Custom Fields prepares its public `acf` REST property independently
 * of WordPress core's password gate. Applying the same access decision to the
 * final response prevents collection requests from exposing protected story
 * fields while retaining edit-context, cookie, and explicit-password access.
 *
 * @param mixed            $response Prepared REST response.
 * @param \WP_Post         $post     Story post.
 * @param \WP_REST_Request $request  REST request.
 * @return mixed
 */
function worldgraph_hide_protected_story_rest_fields( $response, \WP_Post $post, \WP_REST_Request $request ) {
	if ( '' === (string) $post->post_password || is_wp_error( $response ) || ! method_exists( $response, 'get_data' ) ) {
		return $response;
	}

	$request_password = (string) $request->get_param( 'password' );
	$can_access       = ! post_password_required( $post )
		|| ( 'edit' === (string) $request->get_param( 'context' ) && current_user_can( 'edit_post', $post->ID ) )
		|| ( '' !== $request_password && hash_equals( (string) $post->post_password, $request_password ) );
	if ( $can_access ) {
		return $response;
	}

	$data = $response->get_data();
	if ( is_array( $data ) ) {
		$data['acf']                = [];
		$data['worldgraph_display'] = [];
		$response->set_data( $data );
	}
	if ( method_exists( $response, 'get_links' ) && method_exists( $response, 'remove_link' ) ) {
		foreach ( array_keys( $response->get_links() ) as $relation ) {
			if ( str_starts_with( (string) $relation, 'acf:' ) ) {
				$response->remove_link( $relation );
			}
		}
	}

	return $response;
}

/**
 * Build the read-only display projection for one story item.
 *
 * @param int  $post_id         Story item ID.
 * @param bool $expanded        Whether detail-only aggregates should be included.
 * @param bool $include_private Whether readable non-published related data may appear.
 * @return array<string, mixed>
 */
function worldgraph_get_story_display_payload( int $post_id, bool $expanded = false, bool $include_private = false ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, worldgraph_story_display_post_types(), true ) || ! worldgraph_story_display_can_read( $post_id, $include_private ) ) {
		return [];
	}

	$payload = [
		'variant' => str_replace( 'worldgraph_', '', $post->post_type ),
		'media'   => worldgraph_get_story_display_media( $post_id, $include_private ),
	];

	if ( 'worldgraph_sound' === $post->post_type ) {
		$types = get_the_terms( $post_id, 'worldgraph_sound_type' );
		$slug  = ( $types && ! is_wp_error( $types ) ) ? (string) $types[0]->slug : '';
		$payload['sound_kind'] = 'music' === $slug ? 'song' : 'sound';
	}

	if ( 'worldgraph_project' === $post->post_type ) {
		$stage    = (string) worldgraph_get_field_value( $post_id, 'production_stage' );
		$statuses = get_the_terms( $post_id, 'worldgraph_status' );
		$payload['project'] = [
			'stage'        => $stage,
			'stage_label'  => worldgraph_story_display_stage_label( $stage ),
			'status'       => ( $statuses && ! is_wp_error( $statuses ) ) ? (string) $statuses[0]->slug : '',
			'status_label' => ( $statuses && ! is_wp_error( $statuses ) ) ? (string) $statuses[0]->name : '',
		];
		if ( $expanded ) {
			$payload['project']['analytics'] = worldgraph_get_project_display_analytics( $post_id, $include_private );
		}
	}

	if ( $expanded && 'worldgraph_scene' === $post->post_type ) {
		$scene_shots = worldgraph_get_scene_display_shots( $post_id, $include_private );
		$payload['shots'] = array_map(
			static function( \WP_Post $shot ) use ( $include_private ): array {
				return worldgraph_story_display_shot_payload( $shot, $include_private );
			},
			$scene_shots
		);
		if ( $include_private && function_exists( __NAMESPACE__ . '\\worldgraph_scene_shot_order_revision' ) ) {
			$payload['shot_order_revision'] = worldgraph_scene_shot_order_revision( worldgraph_get_scene_shots_for_reorder( $post_id ) );
		}
	}

	if ( $expanded && 'worldgraph_world' === $post->post_type ) {
		$payload['relationship_counts'] = worldgraph_story_display_relationship_counts( $post_id, $post->post_type, $include_private );
	}

	return $payload;
}

/**
 * Expose the UI projection on public WordPress REST resources.
 *
 * Headless templates use this field with the already-public wp/v2 + SCF
 * projection. It deliberately has no update callback.
 */
function worldgraph_register_story_display_rest_field(): void {
	register_rest_field(
		worldgraph_story_display_post_types(),
		'worldgraph_display',
		[
			'get_callback' => static function( array $object, string $field_name, \WP_REST_Request $request ): array {
				$post_id         = absint( $object['id'] ?? 0 );
				$expanded        = (bool) preg_match( '#/\d+$#', $request->get_route() );
				$include_private = $post_id > 0 && current_user_can( 'edit_post', $post_id );
				$post_password   = (string) get_post_field( 'post_password', $post_id );
				$request_password = (string) $request->get_param( 'password' );
				$password_matches = '' !== $post_password && '' !== $request_password && hash_equals( $post_password, $request_password );
				if ( ! $password_matches ) {
					return worldgraph_get_story_display_payload( $post_id, $expanded, $include_private );
				}

				$password_filter = static function( bool $required, $password_post ) use ( $post_id ): bool {
					return $password_post instanceof \WP_Post && $post_id === $password_post->ID ? false : $required;
				};
				add_filter( 'post_password_required', $password_filter, PHP_INT_MAX, 2 );
				try {
					return worldgraph_get_story_display_payload( $post_id, $expanded, $include_private );
				} finally {
					remove_filter( 'post_password_required', $password_filter, PHP_INT_MAX );
				}
			},
			'schema'       => [
				'description' => __( 'Read-only media and aggregate data used by World Graph Studio story displays.', 'worldgraph' ),
				'type'        => 'object',
				'readonly'    => true,
				'context'     => [ 'view', 'edit', 'embed' ],
			],
		]
	);
}
