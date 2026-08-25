<?php
/**
 * Utility functions for World Graph Studio.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( __NAMESPACE__ . '\\wp_strip_all_tags' ) ) {
	/**
	 * Lightweight fallback for environments without WordPress loaded.
	 *
	 * @param string $text Raw HTML.
	 * @return string
	 */
	function wp_strip_all_tags( string $text ): string {
		return trim( preg_replace( '/<[^>]+>/', ' ', $text ) ?? $text );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_trim_words' ) ) {
	/**
	 * Lightweight fallback for environments without WordPress loaded.
	 *
	 * @param string $text       Raw text.
	 * @param int    $num_words  Number of words to keep.
	 * @param string $more       Optional suffix.
	 * @return string
	 */
	function wp_trim_words( string $text, int $num_words = 55, string $more = '…' ): string {
		$words = preg_split( '/\s+/', trim( $text ) );
		if ( ! is_array( $words ) ) {
			return trim( $text );
		}
		if ( count( $words ) <= $num_words ) {
			return trim( $text );
		}
		return trim( implode( ' ', array_slice( $words, 0, $num_words ) ) ) . $more;
	}
}

/**
 * Get the prefix for World Graph Studio CPTs and meta keys.
 *
 * @param string $name Optional name to append to the prefix.
 * @param string $custom_prefix Optional custom prefix override.
 * @return string
 */
function prefix( string $name = '', string $custom_prefix = '' ): string {
	$prefix = '' === $custom_prefix ? WORLDGRAPH_CPT_PREFIX : $custom_prefix;

	if ( '' === $name ) {
		return $prefix;
	}

	return $prefix . $name;
}

/**
 * Build the default register_post_type arguments for a World Graph Studio CPT.
 *
 * @param string $cpt   The CPT slug.
 * @param string $label The display label.
 * @param array  $args  Additional register_post_type args.
 * @return array
 */
function worldgraph_get_default_cpt_args( string $cpt, string $label, array $args = [] ): array {
	$defaults = [
		'labels'             => [
			'name'               => $label,
			'singular_name'      => $label,
			'menu_name'          => $label,
			'add_new'            => 'Add New',
			'add_new_item'       => "Add New {$label}",
			'edit_item'          => "Edit {$label}",
			'new_item'           => "New {$label}",
			'view_item'          => "View {$label}",
			'search_items'       => "Search {$label}",
			'not_found'          => "No {$label} found",
			'not_found_in_trash' => "No {$label} found in Trash",
			'all_items'          => $label,
		],
		'public'             => true,
		'show_ui'            => true,
		'has_archive'        => true,
		'rewrite'            => [ 'slug' => $cpt ],
		'show_in_menu'       => 'worldgraph',
		'show_in_rest'       => true,
		'rest_base'          => $cpt,
		'supports'           => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ],
		'capability_type'    => 'post',
		'map_meta_cap'       => true,
	];

	$args             = wp_parse_args( $args, $defaults );
	$args['supports'] = array_values( array_diff( (array) $args['supports'], [ 'custom-fields' ] ) );

	return $args;
}

/**
 * Register a World Graph Studio CPT.
 *
 * @param string $cpt      The CPT slug.
 * @param string $label    The display label.
 * @param array  $args     Additional register_post_type args.
 * @param array  $fields   SCF field definitions.
 */
function register_cpt( string $cpt, string $label, array $args = [], array $fields = [] ): void {
	$args = worldgraph_get_default_cpt_args( $cpt, $label, $args );
	register_post_type( $cpt, $args );

	// Store field definitions for REST API and admin.
	if ( ! empty( $fields ) ) {
		worldgraph_register_fields( $cpt, $fields );
	}
}

/**
 * Get registration arguments for the Job record type.
 *
 * Generation Jobs are persisted so WP-Cron can submit and poll them. They are
 * operational records, not authoring content, so the native list table is
 * exposed read-only: WordPress itself denies creation and editing through the
 * mapped capabilities below. Dedicated worldgraph/v1 generation routes expose
 * the permitted workflow surface.
 *
 * @return array<string, mixed>
 */
function worldgraph_get_generation_record_cpt_args(): array {
	return [
		'labels'              => [
			'name'               => __( 'Jobs', 'worldgraph' ),
			'singular_name'      => __( 'Job', 'worldgraph' ),
			'menu_name'          => __( 'Jobs', 'worldgraph' ),
			'all_items'          => __( 'Jobs', 'worldgraph' ),
			'search_items'       => __( 'Search Jobs', 'worldgraph' ),
			'not_found'          => __( 'No generation Jobs yet.', 'worldgraph' ),
			'not_found_in_trash' => __( 'No generation Jobs in the trash.', 'worldgraph' ),
		],
		'label'               => __( 'Jobs', 'worldgraph' ),
		'public'              => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'show_ui'             => true,
		'show_in_menu'        => 'worldgraph-generate',
		'menu_icon'           => 'dashicons-database-view',
		'show_in_nav_menus'   => false,
		'show_in_admin_bar'   => false,
		'show_in_rest'        => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'can_export'          => false,
		'delete_with_user'    => false,
		'supports'            => [ 'title' ],
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'capabilities'        => [
			'create_posts' => 'do_not_allow',
		],
	];
}

/** Register the internal record type used by generation controllers and workers. */
function worldgraph_register_generation_record_type(): void {
	register_post_type( 'worldgraph_gen', worldgraph_get_generation_record_cpt_args() );
}

/**
 * Register structured content fields for a CPT.
 *
 * @param string $cpt    The CPT slug.
 * @param array  $fields Field definitions.
 */
function worldgraph_register_fields( string $cpt, array $fields ): void {
	$normalized_fields = [];
	foreach ( $fields as $field_name => $field_config ) {
		$normalized_fields[ $field_name ] = array_merge( [ 'name' => $field_name ], $field_config );
	}

	// Keep the code-defined contract available during this request. SCF_Fields
	// persists this contract as editable SCF field groups after every CPT has
	// registered, and worldgraph_get_fields() then treats those groups as the
	// authoritative runtime schema.
	$GLOBALS['worldgraph_field_definitions'][ $cpt ] = $normalized_fields;

	// Retain the legacy option as a compatibility fallback for CLI/unit contexts
	// where SCF is unavailable. It is no longer the authoritative field store.
	if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
		$all_fields = get_option( 'worldgraph_fields', [] );
		$all_fields = is_array( $all_fields ) ? $all_fields : [];
		$all_fields[ $cpt ] = $normalized_fields;
		update_option( 'worldgraph_fields', $all_fields );
	}
}

/**
 * Get the code-defined fallback fields for a CPT.

 * SCF groups are the runtime authority. These definitions seed those groups
 * and preserve World Graph Studio-only relationship semantics that SCF does not model.
 *
 * @param string $cpt The CPT slug.
 * @return array
 */
function worldgraph_get_field_defaults( string $cpt ): array {
	$registered = $GLOBALS['worldgraph_field_definitions'] ?? [];
	if ( isset( $registered[ $cpt ] ) && is_array( $registered[ $cpt ] ) ) {
		return $registered[ $cpt ];
	}

	if ( ! function_exists( 'get_option' ) ) {
		return [];
	}

	$all_fields = get_option( 'worldgraph_fields', [] );
	return is_array( $all_fields ) && isset( $all_fields[ $cpt ] ) && is_array( $all_fields[ $cpt ] )
		? $all_fields[ $cpt ]
		: [];
}

/**
 * Get all code-defined World Graph Studio field contracts.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function worldgraph_get_all_field_defaults(): array {
	$registered = $GLOBALS['worldgraph_field_definitions'] ?? [];
	if ( is_array( $registered ) && ! empty( $registered ) ) {
		return $registered;
	}

	if ( ! function_exists( 'get_option' ) ) {
		return [];
	}

	$all_fields = get_option( 'worldgraph_fields', [] );
	return is_array( $all_fields ) ? $all_fields : [];
}

/**
 * Get registered fields for a CPT, preferring SCF's persisted field groups.
 *
 * @param string $cpt The CPT slug.
 * @return array<string, array<string, mixed>>
 */
function worldgraph_get_fields( string $cpt ): array {
	$defaults = worldgraph_get_field_defaults( $cpt );
	if ( class_exists( __NAMESPACE__ . '\\SCF_Fields' ) ) {
		return SCF_Fields::get_fields( $cpt, $defaults );
	}

	return $defaults;
}

/**
 * Read a World Graph Studio scalar field through SCF when its field definition exists.
 *
 * @param int    $post_id    Post ID.
 * @param string $field_name Field name.
 * @return mixed
 */
function worldgraph_get_field_value( int $post_id, string $field_name ) {
	if ( class_exists( __NAMESPACE__ . '\\SCF_Fields' ) ) {
		return SCF_Fields::get_value( $post_id, $field_name );
	}

	return get_post_meta( $post_id, $field_name, true );
}

/**
 * Update a World Graph Studio scalar field through SCF so its reference metadata and
 * formatting lifecycle stay intact.
 *
 * @param int    $post_id    Post ID.
 * @param string $field_name Field name.
 * @param mixed  $value      Field value.
 * @return bool
 */
function worldgraph_update_field_value( int $post_id, string $field_name, $value ): bool {
	if ( class_exists( __NAMESPACE__ . '\\SCF_Fields' ) ) {
		return SCF_Fields::update_value( $post_id, $field_name, $value );
	}

	return false !== update_post_meta( $post_id, $field_name, $value );
}

/**
 * Delete a World Graph Studio scalar field through SCF.
 *
 * @param int    $post_id    Post ID.
 * @param string $field_name Field name.
 * @return bool
 */
function worldgraph_delete_field_value( int $post_id, string $field_name ): bool {
	if ( class_exists( __NAMESPACE__ . '\\SCF_Fields' ) ) {
		return SCF_Fields::delete_value( $post_id, $field_name );
	}

	return delete_post_meta( $post_id, $field_name );
}

/**
 * Determine whether a field is redundant in the generic World Graph Studio Details meta box.
 *
 * WordPress already provides the post title and content fields, so dedicated
 * per-CPT name/description fields are duplicated and should be hidden there.
 *
 * @param string $field_name Field key.
 * @param array  $field_config Optional field definition.
 * @return bool
 */
function worldgraph_should_exclude_from_details( string $field_name, array $field_config = [] ): bool {
	$normalized_field_name = strtolower( $field_name );
	if ( preg_match( '/(^|_)(name|description)$/', $normalized_field_name ) ) {
		return true;
	}

	if ( empty( $field_config['label'] ) ) {
		return false;
	}

	$label = strtolower( trim( (string) $field_config['label'] ) );
	return 'name' === $label || 'description' === $label || preg_match( '/\s+(name|description)$/', $label );
}

/**
 * Get the expected field names for a World Graph Studio CPT from the canonical schema contract.
 *
 * @param string $cpt CPT slug.
 * @return array<int, string>
 */
function worldgraph_expected_fields_for_cpt( string $cpt ): array {
	$expected_fields = [
		'worldgraph_project'            => [ 'project_name', 'project_slug', 'description', 'genre', 'target_medium', 'status', 'owner', 'start_date', 'end_date', 'team_members', 'production_stage', 'frame_width', 'frame_height', 'aspect_ratio', 'frame_rate', 'generation_prompt' ],
		'worldgraph_world'        => [ 'world_name', 'synopsis', 'timeline', 'rules', 'themes', 'geography', 'references', 'project', 'generation_prompt' ],
		'worldgraph_character'          => [ 'display_name', 'biography', 'age', 'appearance', 'personality', 'motivation', 'backstory', 'voice_profile', 'avatar_asset', 'story_world', 'generation_prompt' ],
		'worldgraph_location'           => [ 'location_name', 'description', 'environment_type', 'geography', 'mood', 'visual_reference', 'story_world', 'generation_prompt' ],
		'worldgraph_prop'               => [ 'prop_name', 'description', 'purpose', 'owner_character', 'story_world', 'notes', 'generation_prompt' ],
		'worldgraph_org'       => [ 'organization_name', 'organization_type', 'description', 'leadership', 'goals', 'story_world' ],
		'worldgraph_episode'            => [ 'episode_number', 'title', 'synopsis', 'status', 'project', 'generation_prompt' ],
		'worldgraph_scene'              => [ 'scene_number', 'title', 'summary', 'script_content', 'dialogue', 'location', 'time_of_day', 'emotional_tone', 'production_notes', 'sequence', 'episode', 'project', 'generation_prompt', 'audio_direction', 'lens', 'camera_movement' ],
		'worldgraph_shot'               => [ 'shot_name', 'shot_number', 'shot_type', 'camera_angle', 'lens', 'camera_movement', 'motion_direction', 'duration', 'take_number', 'slate_id', 'shot_description', 'editorial_notes', 'scene', 'sequence', 'generation_prompt' ],
		'worldgraph_sound'              => [ 'sound_type', 'production_status', 'spoken_text', 'lyrics', 'start_timecode', 'duration', 'diegetic', 'production_notes', 'scene', 'shot', 'character', 'asset' ],
		'worldgraph_asset'              => [ 'asset_title', 'asset_type', 'workflow_name', 'prompt', 'model_name', 'seed', 'generation_parameters', 'version', 'status', 'storage_uri', 'character', 'location', 'scene' ],
		'worldgraph_editorial'          => [ 'artifact_type', 'export_format', 'generated_date', 'source_scene', 'source_shot', 'notes', 'project' ],
		'worldgraph_template'           => [ 'template_name', 'description', 'generation_structure', 'modality', 'connection_id', 'checkpoint', 'model_family', 'prompt_lead_with', 'prompt_format', 'prompt_target_words', 'prompt_max_words', 'workflow_json', 'provider_template_id', 'configuration_json', 'input_bindings', 'model_requirements', 'default_values', 'provider_type', 'version', 'status' ],
		'worldgraph_conn'         => [ 'connection_name', 'provider_type', 'environment', 'status', 'is_default', 'endpoint_url', 'mcp_endpoint_url', 'credential_reference', 'mcp_credential_reference', 'capabilities', 'mcp_configuration', 'model', 'max_tokens', 'temperature', 'model_access', 'enabled_structures', 'enabled_templates', 'rate_limits', 'cost_controls' ],
	];

	return $expected_fields[ $cpt ] ?? [];
}

/**
 * Validate that a World Graph Studio CPT's registered fields match the canonical schema contract.
 *
 * @return array<string, array<string, mixed>>
 */
function worldgraph_validate_schema_alignment(): array {
	$report = [];
	foreach ( array_keys( worldgraph_get_all_cpts() ) as $cpt ) {
		$registered_fields = worldgraph_get_fields( $cpt );
		$registered_field_names = array_keys( $registered_fields );
		$expected_fields = worldgraph_expected_fields_for_cpt( $cpt );
		$missing_fields = array_values( array_diff( $expected_fields, $registered_field_names ) );

		$report[ $cpt ] = [
			'expected'   => $expected_fields,
			'registered' => $registered_field_names,
			'missing'    => $missing_fields,
			'has_alignment' => empty( $missing_fields ),
		];
	}

	return $report;
}

/**
 * Get all registered World Graph Studio CPTs.
 *
 * @return array
 */
function worldgraph_get_all_cpts(): array {
	return [
		'worldgraph_project'         => 'Project',
		'worldgraph_world'     => 'Story World',
		'worldgraph_character'       => 'Character',
		'worldgraph_location'        => 'Location',
		'worldgraph_prop'            => 'Prop',
		'worldgraph_org'    => 'Organization',
		'worldgraph_episode'         => 'Episode',
		'worldgraph_scene'           => 'Scene',
		'worldgraph_shot'            => 'Shot',
		'worldgraph_sound'           => 'Sound',
		'worldgraph_asset'           => 'Asset',
		'worldgraph_editorial'       => 'Editorial Artifact',
		'worldgraph_template'        => 'Template',
		'worldgraph_conn'      => 'Connection',
	];
}

/**
 * Return required-field hydration rules keyed by CPT.
 *
 * @return array<string, array<string, string>>
 */
function worldgraph_required_field_hydration_map(): array {
	return [
		'worldgraph_project'   => [
			'project_name' => 'post_title',
			'project_slug' => 'post_name',
			'owner'        => 'post_author',
		],
		'worldgraph_world'     => [ 'world_name' => 'post_title' ],
		'worldgraph_character' => [ 'display_name' => 'post_title' ],
		'worldgraph_location'  => [ 'location_name' => 'post_title' ],
		'worldgraph_prop'      => [ 'prop_name' => 'post_title' ],
		'worldgraph_org'       => [ 'organization_name' => 'post_title' ],
		'worldgraph_episode'   => [ 'title' => 'post_title' ],
		'worldgraph_scene'     => [ 'title' => 'post_title' ],
		'worldgraph_asset'     => [ 'asset_title' => 'post_title' ],
		'worldgraph_template'  => [ 'template_name' => 'post_title' ],
		'worldgraph_conn'      => [ 'connection_name' => 'post_title' ],
	];
}

/**
 * Backfill required World Graph fields from canonical WordPress post values.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post Post object.
 * @param bool     $update Whether this is an update.
 */
function worldgraph_hydrate_required_fields_on_save( int $post_id, \WP_Post $post, bool $update ): void {
	unset( $update );

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$cpt = $post->post_type;
	$map = worldgraph_required_field_hydration_map();
	if ( empty( $map[ $cpt ] ) ) {
		return;
	}

	foreach ( $map[ $cpt ] as $field_name => $source ) {
		$current = worldgraph_get_field_value( $post_id, $field_name );
		if ( ! worldgraph_required_field_value_is_empty( $current ) ) {
			continue;
		}

		if ( 'post_title' === $source ) {
			$incoming = trim( (string) $post->post_title );
			if ( '' !== $incoming ) {
				worldgraph_update_field_value( $post_id, $field_name, sanitize_text_field( $incoming ) );
			}
			continue;
		}

		if ( 'post_name' === $source ) {
			$incoming = trim( (string) $post->post_name );
			if ( '' === $incoming && '' !== trim( (string) $post->post_title ) ) {
				$incoming = sanitize_title( (string) $post->post_title );
			}
			if ( '' !== $incoming ) {
				worldgraph_update_field_value( $post_id, $field_name, sanitize_title( $incoming ) );
			}
			continue;
		}

		if ( 'post_author' === $source && ! empty( $post->post_author ) ) {
			worldgraph_update_field_value( $post_id, $field_name, (int) $post->post_author );
		}
	}
}

/**
 * Show a missing-required-fields warning on World Graph edit screens.
 */
function worldgraph_render_required_fields_notice(): void {
	if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'post' !== (string) $screen->base ) {
		return;
	}

	$cpt = (string) ( $screen->post_type ?? '' );
	$all = worldgraph_get_all_cpts();
	if ( ! isset( $all[ $cpt ] ) ) {
		return;
	}

	$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin context.
	if ( $post_id <= 0 ) {
		return;
	}

	$missing = worldgraph_missing_required_field_labels( $post_id, $cpt );
	if ( empty( $missing ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p><strong>'
		. esc_html__( 'This item cannot be published yet.', 'worldgraph' )
		. '</strong> '
		. esc_html__( 'Fill these required fields:', 'worldgraph' )
		. ' '
		. esc_html( implode( ', ', $missing ) )
		. '.</p></div>';
}

/**
 * Return labels for required fields that are currently empty.
 *
 * @param int    $post_id Post ID.
 * @param string $cpt CPT slug.
 * @return array<int, string>
 */
function worldgraph_missing_required_field_labels( int $post_id, string $cpt ): array {
	$fields = worldgraph_get_fields( $cpt );
	if ( empty( $fields ) ) {
		return [];
	}

	$missing = [];
	foreach ( $fields as $field_name => $field ) {
		if ( empty( $field['required'] ) ) {
			continue;
		}

		$value = worldgraph_get_field_value( $post_id, (string) $field_name );
		if ( worldgraph_required_field_value_is_empty( $value ) ) {
			$missing[] = (string) ( $field['label'] ?? $field_name );
		}
	}

	return $missing;
}

/**
 * Whether a field value should be treated as empty for required-field checks.
 *
 * @param mixed $value Field value.
 */
function worldgraph_required_field_value_is_empty( $value ): bool {
	if ( is_array( $value ) ) {
		return 0 === count( array_filter( $value, static function( $item ): bool {
			if ( is_numeric( $item ) ) {
				return (int) $item > 0;
			}
			return '' !== trim( (string) $item );
		} ) );
	}

	if ( is_numeric( $value ) ) {
		return (int) $value <= 0;
	}

	return '' === trim( (string) $value );
}

/**
 * Get Schema.org base type for each World Graph Studio CPT.
 *
 * This is a non-destructive semantic alignment layer used for interoperability.
 *
 * @return array<string, string>
 */
function worldgraph_schema_type_map(): array {
	return [
		'worldgraph_project'           => 'CreativeWork',
		'worldgraph_world'       => 'CreativeWork',
		'worldgraph_character'         => 'Person',
		'worldgraph_location'          => 'Place',
		'worldgraph_prop'              => 'Thing',
		'worldgraph_org'      => 'Organization',
		'worldgraph_episode'           => 'Episode',
		'worldgraph_scene'             => 'Clip',
		'worldgraph_shot'              => 'Clip',
		'worldgraph_sound'             => 'CreativeWork',
		'worldgraph_asset'             => 'MediaObject',
		'worldgraph_editorial'         => 'CreativeWork',
		'worldgraph_template'           => 'CreativeWork',
		'worldgraph_conn'         => 'Service',
	];
}

/**
 * Resolve Schema.org type for a specific entity using available metadata.
 *
 * This remains non-destructive and only affects semantic interpretation.
 *
 * @param string $cpt        World Graph Studio CPT slug.
 * @param array  $meta       World Graph Studio meta values.
 * @param array  $taxonomies World Graph Studio taxonomy values.
 * @return string
 */
function worldgraph_schema_type_for_entity( string $cpt, array $meta = [], array $taxonomies = [] ): string {
	$type_map = worldgraph_schema_type_map();
	$base_type = $type_map[ $cpt ] ?? 'Thing';

	if ( 'worldgraph_project' === $cpt ) {
		$target_medium = strtolower( (string) ( $meta['target_medium'] ?? '' ) );
		if ( in_array( $target_medium, [ 'film', 'short_film' ], true ) ) {
			return 'Movie';
		}
	}

	if ( 'worldgraph_asset' === $cpt ) {
		$asset_terms = $taxonomies['worldgraph_asset_type'] ?? [];
		$asset_slugs = array_map(
			static function( $term ) {
				return strtolower( (string) ( $term['slug'] ?? '' ) );
			},
			$asset_terms
		);

		if ( in_array( 'video', $asset_slugs, true ) ) {
			return 'VideoObject';
		}
		if ( in_array( 'audio', $asset_slugs, true ) ) {
			return 'AudioObject';
		}
		if ( array_intersect( $asset_slugs, [ 'character', 'environment', 'prop', 'storyboard', 'lookbook', 'concept-art' ] ) ) {
			return 'ImageObject';
		}
	}

	if ( 'worldgraph_sound' === $cpt ) {
		$sound_terms = $taxonomies['worldgraph_sound_type'] ?? [];
		$sound_slugs = array_map(
			static function( $term ) {
				return strtolower( (string) ( $term['slug'] ?? '' ) );
			},
			$sound_terms
		);

		if ( in_array( 'music', $sound_slugs, true ) ) {
			return 'MusicComposition';
		}
	}

	return $base_type;
}

/**
 * Get per-CPT field mappings to closest Schema.org properties.
 *
 * Match levels:
 * - exact: direct semantic equivalent
 * - close: strong practical equivalent
 * - weak: partial or context-dependent equivalent
 *
 * @return array<string, array<string, array<string, string>>>
 */
function worldgraph_schema_field_map(): array {
	return [
		'worldgraph_project' => [
			'project_name'     => [ 'property' => 'name', 'match' => 'exact' ],
			'project_slug'     => [ 'property' => 'identifier', 'match' => 'close' ],
			'description'      => [ 'property' => 'description', 'match' => 'exact' ],
			'genre'            => [ 'property' => 'genre', 'match' => 'exact' ],
			'target_medium'    => [ 'property' => 'additionalType', 'match' => 'close' ],
			'status'           => [ 'property' => 'creativeWorkStatus', 'match' => 'close' ],
			'owner'            => [ 'property' => 'creator', 'match' => 'close' ],
			'start_date'       => [ 'property' => 'dateCreated', 'match' => 'close' ],
			'end_date'         => [ 'property' => 'expires', 'match' => 'weak' ],
			'team_members'     => [ 'property' => 'contributor', 'match' => 'close' ],
			'production_stage' => [ 'property' => 'creativeWorkStatus', 'match' => 'close' ],
		],
		'worldgraph_world' => [
			'world_name'  => [ 'property' => 'name', 'match' => 'exact' ],
			'synopsis'    => [ 'property' => 'description', 'match' => 'close' ],
			'timeline'    => [ 'property' => 'temporalCoverage', 'match' => 'close' ],
			'rules'       => [ 'property' => 'text', 'match' => 'weak' ],
			'themes'      => [ 'property' => 'about', 'match' => 'close' ],
			'geography'   => [ 'property' => 'spatialCoverage', 'match' => 'close' ],
			'references'  => [ 'property' => 'citation', 'match' => 'close' ],
			'project'     => [ 'property' => 'isPartOf', 'match' => 'close' ],
		],
		'worldgraph_character' => [
			'display_name'  => [ 'property' => 'name', 'match' => 'exact' ],
			'biography'     => [ 'property' => 'description', 'match' => 'close' ],
			'age'           => [ 'property' => 'description', 'match' => 'weak' ],
			'appearance'    => [ 'property' => 'description', 'match' => 'weak' ],
			'personality'   => [ 'property' => 'description', 'match' => 'weak' ],
			'motivation'    => [ 'property' => 'knowsAbout', 'match' => 'weak' ],
			'backstory'     => [ 'property' => 'description', 'match' => 'close' ],
			'voice_profile' => [ 'property' => 'description', 'match' => 'weak' ],
			'avatar_asset'  => [ 'property' => 'image', 'match' => 'close' ],
			'story_world'   => [ 'property' => 'subjectOf', 'match' => 'weak' ],
		],
		'worldgraph_location' => [
			'location_name'    => [ 'property' => 'name', 'match' => 'exact' ],
			'description'      => [ 'property' => 'description', 'match' => 'exact' ],
			'environment_type' => [ 'property' => 'additionalType', 'match' => 'close' ],
			'geography'        => [ 'property' => 'address', 'match' => 'close' ],
			'mood'             => [ 'property' => 'description', 'match' => 'weak' ],
			'visual_reference' => [ 'property' => 'photo', 'match' => 'close' ],
			'story_world'      => [ 'property' => 'containedInPlace', 'match' => 'close' ],
		],
		'worldgraph_prop' => [
			'prop_name'        => [ 'property' => 'name', 'match' => 'exact' ],
			'description'      => [ 'property' => 'description', 'match' => 'exact' ],
			'purpose'          => [ 'property' => 'about', 'match' => 'close' ],
			'owner_character'  => [ 'property' => 'owner', 'match' => 'close' ],
			'notes'            => [ 'property' => 'text', 'match' => 'weak' ],
		],
		'worldgraph_org' => [
			'organization_name' => [ 'property' => 'name', 'match' => 'exact' ],
			'organization_type' => [ 'property' => 'additionalType', 'match' => 'close' ],
			'description'       => [ 'property' => 'description', 'match' => 'exact' ],
			'leadership'        => [ 'property' => 'member', 'match' => 'close' ],
			'goals'             => [ 'property' => 'slogan', 'match' => 'weak' ],
			'story_world'       => [ 'property' => 'subjectOf', 'match' => 'weak' ],
		],
		'worldgraph_episode' => [
			'episode_number' => [ 'property' => 'episodeNumber', 'match' => 'exact' ],
			'title'          => [ 'property' => 'name', 'match' => 'exact' ],
			'synopsis'       => [ 'property' => 'description', 'match' => 'close' ],
			'status'         => [ 'property' => 'creativeWorkStatus', 'match' => 'close' ],
			'project'        => [ 'property' => 'isPartOf', 'match' => 'exact' ],
		],
		'worldgraph_scene' => [
			'scene_number'      => [ 'property' => 'position', 'match' => 'close' ],
			'title'             => [ 'property' => 'name', 'match' => 'exact' ],
			'summary'           => [ 'property' => 'description', 'match' => 'close' ],
			'script_content'    => [ 'property' => 'text', 'match' => 'close' ],
			'dialogue'          => [ 'property' => 'text', 'match' => 'weak' ],
			'location'          => [ 'property' => 'contentLocation', 'match' => 'exact' ],
			'time_of_day'       => [ 'property' => 'temporal', 'match' => 'close' ],
			'emotional_tone'    => [ 'property' => 'about', 'match' => 'weak' ],
			'production_notes'  => [ 'property' => 'text', 'match' => 'weak' ],
			'episode'           => [ 'property' => 'isPartOf', 'match' => 'exact' ],
		],
		'worldgraph_shot' => [
			'shot_name'         => [ 'property' => 'name', 'match' => 'close' ],
			'shot_number'       => [ 'property' => 'position', 'match' => 'close' ],
			'shot_type'         => [ 'property' => 'additionalType', 'match' => 'close' ],
			'camera_angle'      => [ 'property' => 'description', 'match' => 'weak' ],
			'lens'              => [ 'property' => 'description', 'match' => 'weak' ],
			'duration'          => [ 'property' => 'duration', 'match' => 'exact' ],
			'shot_description'  => [ 'property' => 'description', 'match' => 'exact' ],
			'editorial_notes'   => [ 'property' => 'text', 'match' => 'weak' ],
			'scene'             => [ 'property' => 'isPartOf', 'match' => 'exact' ],
			'sequence'          => [ 'property' => 'isPartOf', 'match' => 'close' ],
		],
		'worldgraph_sound' => [
			'sound_type'       => [ 'property' => 'additionalType', 'match' => 'close' ],
			'production_status'=> [ 'property' => 'creativeWorkStatus', 'match' => 'close' ],
			'spoken_text'      => [ 'property' => 'text', 'match' => 'exact' ],
			'lyrics'           => [ 'property' => 'lyrics', 'match' => 'exact' ],
			'start_timecode'   => [ 'property' => 'temporal', 'match' => 'weak' ],
			'duration'         => [ 'property' => 'duration', 'match' => 'close' ],
			'diegetic'         => [ 'property' => 'additionalType', 'match' => 'weak' ],
			'production_notes' => [ 'property' => 'text', 'match' => 'weak' ],
			'scene'            => [ 'property' => 'isPartOf', 'match' => 'exact' ],
			'shot'             => [ 'property' => 'isPartOf', 'match' => 'close' ],
			'character'        => [ 'property' => 'character', 'match' => 'close' ],
			'asset'            => [ 'property' => 'encoding', 'match' => 'close' ],
		],
		'worldgraph_asset' => [
			'asset_title'            => [ 'property' => 'name', 'match' => 'exact' ],
			'asset_type'             => [ 'property' => 'additionalType', 'match' => 'close' ],
			'workflow_name'          => [ 'property' => 'producer', 'match' => 'weak' ],
			'prompt'                 => [ 'property' => 'text', 'match' => 'close' ],
			'model_name'             => [ 'property' => 'producer', 'match' => 'weak' ],
			'seed'                   => [ 'property' => 'identifier', 'match' => 'weak' ],
			'generation_parameters'  => [ 'property' => 'additionalProperty', 'match' => 'close' ],
			'version'                => [ 'property' => 'version', 'match' => 'exact' ],
			'status'                 => [ 'property' => 'creativeWorkStatus', 'match' => 'close' ],
			'storage_uri'            => [ 'property' => 'contentUrl', 'match' => 'close' ],
			'character'              => [ 'property' => 'about', 'match' => 'close' ],
			'location'               => [ 'property' => 'contentLocation', 'match' => 'close' ],
			'scene'                  => [ 'property' => 'isPartOf', 'match' => 'close' ],
			'storyboard'             => [ 'property' => 'isPartOf', 'match' => 'close' ],
		],
		'worldgraph_editorial' => [
			'artifact_type'   => [ 'property' => 'additionalType', 'match' => 'close' ],
			'export_format'   => [ 'property' => 'encodingFormat', 'match' => 'exact' ],
			'generated_date'  => [ 'property' => 'dateCreated', 'match' => 'close' ],
			'source_scene'    => [ 'property' => 'isBasedOn', 'match' => 'close' ],
			'source_shot'     => [ 'property' => 'isBasedOn', 'match' => 'close' ],
			'notes'           => [ 'property' => 'text', 'match' => 'close' ],
			'project'         => [ 'property' => 'isPartOf', 'match' => 'close' ],
		],
		'worldgraph_conn' => [
			'connection_name'      => [ 'property' => 'name', 'match' => 'exact' ],
			'endpoint_url'         => [ 'property' => 'url', 'match' => 'exact' ],
			'status'               => [ 'property' => 'status', 'match' => 'close' ],
			'environment'          => [ 'property' => 'additionalType', 'match' => 'close' ],
			'provider_type'        => [ 'property' => 'provider', 'match' => 'close' ],
			'enabled_structures'   => [ 'property' => 'hasPart', 'match' => 'close' ],
			'cost_controls'        => [ 'property' => 'priceSpecification', 'match' => 'close' ],
			'model_access'         => [ 'property' => 'encodingFormat', 'match' => 'weak' ],
			'rate_limits'          => [ 'property' => 'additionalProperty', 'match' => 'weak' ],
			'credential_reference' => [ 'property' => 'identifier', 'match' => 'weak' ],
		],
	];
}

/**
 * Resolve the closest Schema.org property for a World Graph Studio field.
 *
 * @param string $cpt        World Graph Studio CPT slug.
 * @param string $field_name World Graph Studio field name.
 * @return array<string, string>|null
 */
function worldgraph_schema_property_for_field( string $cpt, string $field_name ): ?array {
	$map = worldgraph_schema_field_map();
	if ( empty( $map[ $cpt ] ) || empty( $map[ $cpt ][ $field_name ] ) ) {
		return null;
	}

	return $map[ $cpt ][ $field_name ];
}

/**
 * Summarize exact/close/weak match counts for each CPT.
 *
 * @return array<string, array<string, int>>
 */
function worldgraph_schema_similarity_summary(): array {
	$map = worldgraph_schema_field_map();
	$summary = [];

	foreach ( $map as $cpt => $fields ) {
		$summary[ $cpt ] = [
			'exact' => 0,
			'close' => 0,
			'weak'  => 0,
		];

		foreach ( $fields as $field ) {
			$match = $field['match'] ?? 'weak';
			if ( isset( $summary[ $cpt ][ $match ] ) ) {
				$summary[ $cpt ][ $match ]++;
			}
		}
	}

	return $summary;
}

/**
 * Map an internal World Graph Studio relationship type to a Schema.org property.
 *
 * @param string $relationship_type Internal World Graph Studio relationship type.
 * @param string $from_cpt          Source World Graph Studio CPT slug.
 * @param string $to_cpt            Target World Graph Studio CPT slug.
 * @return string
 */
function worldgraph_schema_property_for_relationship( string $relationship_type, string $from_cpt = '', string $to_cpt = '' ): string {
	$relationship_type = strtolower( $relationship_type );

	switch ( $relationship_type ) {
		case 'contains':
			return 'hasPart';

		case 'belongs_to':
			return 'isPartOf';

		case 'derived_from':
			return 'isBasedOn';

		case 'references':
			return 'mentions';

		case 'related_to':
			return 'isRelatedTo';

		case 'located_in':
			return 'contentLocation';

		case 'used_in':
			return 'isPartOf';

		case 'generated_by':
			return 'creator';

		case 'appears_in':
			if ( 'worldgraph_character' === $from_cpt ) {
				return 'subjectOf';
			}
			if ( 'worldgraph_character' === $to_cpt ) {
				return 'character';
			}
			return 'mentions';

		case 'linked_to':
			if ( 'worldgraph_character' === $to_cpt ) {
				if ( in_array( $from_cpt, [ 'worldgraph_project', 'worldgraph_episode', 'worldgraph_scene', 'worldgraph_shot', 'worldgraph_sound' ], true ) ) {
					return 'character';
				}
				return 'about';
			}

			if ( 'worldgraph_sound' === $from_cpt && 'worldgraph_asset' === $to_cpt ) {
				return 'encoding';
			}

			if ( 'worldgraph_location' === $to_cpt ) {
				return 'contentLocation';
			}

			if ( in_array( $to_cpt, [ 'worldgraph_project', 'worldgraph_episode', 'worldgraph_scene', 'worldgraph_shot' ], true ) ) {
				return 'isPartOf';
			}

			return 'about';

		default:
			return 'mentions';
	}
}

/**
 * Build canonical Schema.org property hints from World Graph Studio field metadata.
 *
 * @param string $cpt  World Graph Studio CPT slug.
 * @param array  $meta World Graph Studio meta key-value map.
 * @return array<string, mixed>
 */
function worldgraph_schema_hints_from_meta( string $cpt, array $meta ): array {
	$field_map = worldgraph_schema_field_map();
	$cpt_map = $field_map[ $cpt ] ?? [];
	$hints = [];

	foreach ( $cpt_map as $field_name => $mapping ) {
		if ( ! array_key_exists( $field_name, $meta ) ) {
			continue;
		}

		$property = $mapping['property'] ?? '';
		if ( '' === $property ) {
			continue;
		}

		$value = $meta[ $field_name ];
		if ( 'worldgraph_sound' === $cpt && 'lyrics' === $field_name ) {
			$value = [
				'@type' => 'CreativeWork',
				'text'  => (string) $value,
			];
		}

		if ( ! isset( $hints[ $property ] ) ) {
			$hints[ $property ] = $value;
			continue;
		}

		if ( ! is_array( $hints[ $property ] ) ) {
			$hints[ $property ] = [ $hints[ $property ] ];
		}

		$hints[ $property ][] = $value;
	}

	return $hints;
}

/**
 * Sanitize a story graph ID into a stable slug-like identifier.
 *
 * @param mixed $id The ID.
 * @return string
 */
function sanitize_story_id( $id ): string {
	$raw = strtolower( (string) $id );
	$sanitized = preg_replace( '/[^a-z0-9]+/', '-', $raw ) ?? $raw;
	$sanitized = trim( $sanitized, '-' );

	return $sanitized !== '' ? $sanitized : 'story';
}

/**
 * Get the current user's World Graph Studio role.
 *
 * @return string
 */
function worldgraph_user_role(): string {
	if ( ! is_user_logged_in() ) {
		return 'guest';
	}

	if ( current_user_can( 'manage_worldgraph' ) ) {
		return 'administrator';
	}

	if ( current_user_can( 'edit_worldgraph_projects' ) ) {
		return 'producer';
	}

	if ( current_user_can( 'edit_worldgraph_characters' ) ) {
		return 'writer';
	}

	return 'contributor';
}

/**
 * Check if a post exists and is of a given type.
 *
 * @param int    $post_id The post ID.
 * @param string $post_type The post type.
 * @return bool
 */
function worldgraph_post_exists( int $post_id, string $post_type = 'post' ): bool {
	if ( ! $post_id ) {
		return false;
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}

	return $post->post_type === $post_type;
}

/**
 * Get World Graph Studio options.
 *
 * @param string $key   The option key.
 * @param mixed  $default Default value.
 * @return mixed
 */
function worldgraph_option( string $key, $default = null ) {
	return get_option( 'worldgraph_' . $key, $default );
}

/**
 * Log a World Graph Studio event.
 *
 * @param string $message The message.
 * @param string $level   The log level.
 */
function worldgraph_log( string $message, string $level = 'info' ): void {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	$log_entry = sprintf(
		'[%s] [%s] %s',
		current_time( 'Y-m-d H:i:s' ),
		strtoupper( $level ),
		$message
	);

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This helper already requires WP_DEBUG.
	error_log( $log_entry );
}

/**
 * Canonical seed vocabulary for planned sound cues.
 *
 * Ordinary screenplay dialogue remains structured Scene metadata. These terms
 * describe additional soundtrack cues that need their own timing, production,
 * and media relationships.
 *
 * @return array<string, string> Term slug to display label map.
 */
function worldgraph_sound_types(): array {
	return [
		'narration'    => 'Narration',
		'voiceover'    => 'Voice-over',
		'music'        => 'Music',
		'sound-effect' => 'Sound Effect',
		'ambience'     => 'Ambience',
		'foley'        => 'Foley',
		'silence'      => 'Intentional Silence',
		'adr'          => 'ADR',
	];
}

/**
 * Determine whether a Sound Type is reserved for Scene-owned content.
 *
 * @param mixed $value Term object, slug, name, or ID.
 * @return bool
 */
function worldgraph_is_reserved_sound_type( $value ): bool {
	if ( is_object( $value ) && isset( $value->slug ) ) {
		$value = $value->slug;
	} elseif ( is_numeric( $value ) && taxonomy_exists( 'worldgraph_sound_type' ) ) {
		$term  = get_term( absint( $value ), 'worldgraph_sound_type' );
		$value = ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
	}

	return 'dialogue' === sanitize_title( (string) $value );
}

/**
 * Determine whether a World Graph Studio Asset is classified as audio.
 *
 * @param int $asset_id Asset post ID.
 * @return bool
 */
function worldgraph_is_audio_asset( int $asset_id ): bool {
	return $asset_id > 0 && 'worldgraph_asset' === get_post_type( $asset_id ) && has_term( 'audio', 'worldgraph_asset_type', $asset_id );
}

/**
 * Sanitize a value using its World Graph Studio field definition.
 *
 * @param mixed $value Raw field value.
 * @param array $field World Graph Studio field definition.
 * @return mixed Sanitized value.
 */
function worldgraph_sanitize_field_value( $value, array $field ) {
	$type = (string) ( $field['type'] ?? 'text' );

	if ( 'number' === $type ) {
		return is_numeric( $value ) ? 0 + $value : '';
	}

	if ( 'wysiwyg' === $type ) {
		return wp_kses_post( (string) $value );
	}

	if ( 'textarea' === $type ) {
		return sanitize_textarea_field( (string) $value );
	}

	if ( 'select' === $type ) {
		$value   = sanitize_key( (string) $value );
		$options = (array) ( $field['options'] ?? [] );
		return isset( $options[ $value ] ) ? $value : '';
	}

	return sanitize_text_field( (string) $value );
}

/**
 * Canonical shot type map (slug => display label).
 *
 * Kept in one place so the CPT, name generator, exporter and UI agree.
 *
 * @return array<string, string>
 */
function worldgraph_shot_types(): array {
	return [
		'establishing'      => 'Establishing',
		'extreme_close_up'  => 'Extreme Close Up',
		'close_up'          => 'Close Up',
		'closeup'           => 'Close Up',
		'medium_close_up'   => 'Medium Close Up',
		'medium'            => 'Medium',
		'medium_wide'       => 'Medium Wide',
		'wide'              => 'Wide',
		'extreme_wide'      => 'Extreme Wide',
		'over_the_shoulder' => 'Over The Shoulder',
		'point_of_view'     => 'Point of View',
		'cutaway'           => 'Cutaway',
		'reaction'          => 'Reaction Shot',
		'insert'            => 'Insert',
		'close-up'          => 'Close Up',
		'closeup_shot'      => 'Close Up',
	];
}

/**
 * Human-friendly label for a shot type slug.
 *
 * @param string $slug Raw shot type value.
 * @return string
 */
function worldgraph_shot_type_label( string $slug ): string {
	$slug = strtolower( trim( $slug ) );
	$types = worldgraph_shot_types();

	if ( isset( $types[ $slug ] ) ) {
		return $types[ $slug ];
	}

	return ucwords( str_replace( [ '_', '-' ], ' ', $slug ) );
}

/**
 * Normalize a shot type slug to its canonical representation.
 *
 * @param string $slug Raw shot type value.
 * @return string Canonical slug (or a best-effort slug when unknown).
 */
function worldgraph_normalize_shot_type( string $slug ): string {
	$slug = strtolower( trim( $slug ) );

	$aliases = [
		'closeup'            => 'close_up',
		'close-up'           => 'close_up',
		'closeup_shot'       => 'close_up',
		'extreme-close-up'   => 'extreme_close_up',
		'point-of-view'      => 'point_of_view',
		'over-the-shoulder'  => 'over_the_shoulder',
	];

	if ( isset( $aliases[ $slug ] ) ) {
		return $aliases[ $slug ];
	}

	return in_array( $slug, array_keys( worldgraph_shot_types() ), true ) ? $slug : str_replace( '-', '_', $slug );
}

/**
 * Generate a useful, human-friendly name for a shot.
 *
 * Pure function so it is unit-testable without a WordPress bootstrap.
 * Example: "Shot 1: Wide — The Assignment (Village cottage exterior)".
 *
 * @param array $shot Shot data with optional keys:
 *                    - shot_number      (int|string)
 *                    - shot_type        (string) e.g. 'wide' or 'close_up'
 *                    - shot_description (string)
 *                    - scene_title      (string) scene post title
 *                    - scene_number     (int|string)
 * @return string
 */
function worldgraph_generate_shot_name( array $shot ): string {
	$explicit_title = isset( $shot['title'] ) ? trim( (string) $shot['title'] ) : '';
	if ( '' === $explicit_title && isset( $shot['label'] ) ) {
		$explicit_title = trim( (string) $shot['label'] );
	}
	if ( '' !== $explicit_title ) {
		return $explicit_title;
	}

	$number = isset( $shot['shot_number'] ) && '' !== $shot['shot_number'] ? $shot['shot_number'] : '';

	$type_label = isset( $shot['shot_type'] ) && '' !== $shot['shot_type']
		? worldgraph_shot_type_label( (string) $shot['shot_type'] )
		: '';

	$scene_title = isset( $shot['scene_title'] ) ? trim( (string) $shot['scene_title'] ) : '';

	$description = '';
	if ( ! empty( $shot['shot_description'] ) ) {
		$description = wp_strip_all_tags( (string) $shot['shot_description'] );
		$description = trim( preg_replace( '/\s+/', ' ', $description ) ?? $description );
		if ( function_exists( 'wp_trim_words' ) ) {
			$description = wp_trim_words( $description, 10, '…' );
		}
	}

	$parts = [];

	if ( '' !== $number ) {
		$parts[] = 'Shot ' . $number;
	}

	if ( '' !== $type_label ) {
		$parts[] = $type_label;
	}

	if ( '' !== $scene_title ) {
		$parts[] = $scene_title;
	}

	if ( '' !== $description ) {
		$parts[] = '(' . $description . ')';
	}

	if ( empty( $parts ) ) {
		return 'Untitled Shot';
	}

	$primary = array_slice( $parts, 0, 2 );
	$tail    = array_slice( $parts, 2 );

	if ( empty( $tail ) ) {
		return implode( ': ', $primary );
	}

	return implode( ': ', $primary ) . ' — ' . implode( ' ', $tail );
}

/**
 * Get the display name for a shot post.
 *
 * Prefers the post title when it looks intentional (not the default
 * "Shot N" placeholder), otherwise falls back to a generated name.
 *
 * @param int $shot_id Shot post ID.
 * @return string
 */
function worldgraph_get_shot_display_name( int $shot_id ): string {
	$post = get_post( $shot_id );
	if ( ! $post || 'worldgraph_shot' !== $post->post_type ) {
		return '';
	}

	$title = trim( (string) $post->post_title );
	if ( '' !== $title && ! preg_match( '/^shot \d+$/i', $title ) ) {
		return $title;
	}

	$scene_id = 0;
	foreach ( get_relationships( $shot_id, 'worldgraph_shot', 'outgoing' ) as $rel ) {
		if ( 'worldgraph_scene' === ( $rel['to_type'] ?? '' ) ) {
			$scene_id = (int) ( $rel['to_id'] ?? 0 );
			break;
		}
	}

	$scene     = $scene_id ? get_post( $scene_id ) : null;
	$shot_name = worldgraph_get_field_value( $shot_id, 'shot_name' );

	return worldgraph_generate_shot_name( [
		'shot_number'      => worldgraph_get_field_value( $shot_id, 'shot_number' ),
		'shot_type'        => worldgraph_get_field_value( $shot_id, 'shot_type' ),
		'shot_description' => $shot_name ?: worldgraph_get_field_value( $shot_id, 'shot_description' ),
		'scene_title'      => $scene ? $scene->post_title : '',
		'scene_number'     => $scene ? worldgraph_get_field_value( $scene->ID, 'scene_number' ) : '',
	] );
}

/**
 * Get the editorial order of a sequence term.
 *
 * @param int $term_id Sequence term ID.
 * @return int
 */
function worldgraph_get_sequence_order( int $term_id ): int {
	$order = get_term_meta( $term_id, \WorldGraph\Taxonomies\Sequence::ORDER_META_KEY, true );
	return '' !== $order ? absint( $order ) : PHP_INT_MAX;
}

/**
 * Set the editorial order of a sequence term.
 *
 * @param int $term_id Sequence term ID.
 * @param int $order   New position (1-based).
 * @return void
 */
function worldgraph_set_sequence_order( int $term_id, int $order ): void {
	update_term_meta( $term_id, \WorldGraph\Taxonomies\Sequence::ORDER_META_KEY, max( 1, $order ) );
}

/**
 * Get objects assigned to a Sequence term, filtered to one post type.
 *
 * WordPress core's get_objects_in_term() returns every object assigned to the
 * taxonomy term; its third argument controls query ordering, not object type.
 * Sequences are shared by Scenes and Shots, so callers must filter explicitly.
 *
 * @param int    $term_id   Sequence term ID.
 * @param string $post_type World Graph Studio CPT slug.
 * @return array<int, int>
 */
function worldgraph_get_sequence_object_ids( int $term_id, string $post_type ): array {
	$object_ids = get_objects_in_term( $term_id, \WorldGraph\Taxonomies\Sequence::TAXONOMY );
	if ( is_wp_error( $object_ids ) ) {
		return [];
	}

	return array_values(
		array_filter(
			array_unique( array_map( 'absint', (array) $object_ids ) ),
			static function( int $object_id ) use ( $post_type ): bool {
				return $post_type === get_post_type( $object_id );
			}
		)
	);
}

/**
 * Get all sequence terms ordered for the editorial cut.
 *
 * @return array<int, array{id:int,name:string,slug:string,order:int}>
 */
function worldgraph_get_ordered_sequences(): array {
	$terms = get_terms( [
		'taxonomy'   => \WorldGraph\Taxonomies\Sequence::TAXONOMY,
		'hide_empty' => false,
		'orderby'    => 'term_id',
		'order'      => 'ASC',
	] );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return [];
	}

	$sequences = array_map( static function( $term ) {
		return [
			'id'    => (int) $term->term_id,
			'name'  => $term->name,
			'slug'  => $term->slug,
			'order' => worldgraph_get_sequence_order( (int) $term->term_id ),
		];
	}, $terms );

	usort( $sequences, static function( array $a, array $b ) {
		// Terms without an explicit order stay at the end, stable by term id.
		$cmp = $a['order'] <=> $b['order'];
		if ( 0 !== $cmp ) {
			return $cmp;
		}
		return $a['id'] <=> $b['id'];
	} );

	return $sequences;
}
