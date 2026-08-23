<?php
/**
 * Compatibility migration for the StoryOS to World Graph Studio rename.
 *
 * Legacy identifiers intentionally live in this compatibility layer only. The
 * migration is safe to retry: source rows are removed only after their target
 * has been written and verified, and the version is advanced only after a
 * final database audit succeeds.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

defined( 'ABSPATH' ) || exit;

const WORLDGRAPH_NAMESPACE_MIGRATION_VERSION    = 1;
const WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE = 500;
const WORLDGRAPH_NAMESPACE_MIGRATION_LOCK        = 'worldgraph_namespace_migration_lock';
const WORLDGRAPH_NAMESPACE_MIGRATION_ERRORS      = 'worldgraph_namespace_migration_errors';
const WORLDGRAPH_NAMESPACE_MIGRATION_OPTION      = 'worldgraph_namespace_migration_version';
const WORLDGRAPH_NAMESPACE_OPTION_VALUE_CURSOR   = 'worldgraph_namespace_option_value_cursor';
const WORLDGRAPH_NAMESPACE_POSTMETA_VALUE_CURSOR = 'worldgraph_namespace_postmeta_value_cursor';

/**
 * Return every historical CPT key and its canonical replacement.
 *
 * @return array<string, string>
 */
function worldgraph_legacy_cpt_key_map(): array {
	return [
		'storyos_project'               => 'worldgraph_project',
		'storyos_story_world'           => 'worldgraph_world',
		'storyos_character'             => 'worldgraph_character',
		'storyos_location'              => 'worldgraph_location',
		'storyos_prop'                  => 'worldgraph_prop',
		'storyos_organization'          => 'worldgraph_org',
		'storyos_episode'               => 'worldgraph_episode',
		'storyos_scene'                 => 'worldgraph_scene',
		'storyos_shot'                  => 'worldgraph_shot',
		'storyos_sound'                 => 'worldgraph_sound',
		'storyos_asset'                 => 'worldgraph_asset',
		'storyos_editorial_artifact'    => 'worldgraph_editorial',
		'storyos_editorial_ar'          => 'worldgraph_editorial',
		'storyos_editorial'             => 'worldgraph_editorial',
		'storyos_template'              => 'worldgraph_template',
		'storyos_connection'            => 'worldgraph_conn',
		'storyos_generation'            => 'worldgraph_gen',
		'worldgraph_editorial_artifact' => 'worldgraph_editorial',
		'worldgraph_editorial_ar'       => 'worldgraph_editorial',
	];
}

/**
 * Return historical taxonomy keys and their canonical replacements.
 *
 * @return array<string, string>
 */
function worldgraph_legacy_taxonomy_key_map(): array {
	return [
		'storyos_asset_type'         => 'worldgraph_asset_type',
		'storyos_character_relation' => 'worldgraph_character_relation',
		'storyos_character_role'     => 'worldgraph_character_role',
		'storyos_genre'              => 'worldgraph_genre',
		'storyos_status'             => 'worldgraph_status',
		'storyos_scene_tag'          => 'worldgraph_scene_tag',
		'storyos_sequence'           => 'worldgraph_sequence',
		'storyos_sound_type'         => 'worldgraph_sound_type',
		'storyos_template_category'  => 'worldgraph_template_category',
	];
}

/**
 * Replace an exact machine identifier while leaving ordinary prose untouched.
 */
function worldgraph_migrate_machine_identifier( string $value ): string {
	$identifier_map = array_merge(
		worldgraph_legacy_cpt_key_map(),
		worldgraph_legacy_taxonomy_key_map(),
		[
			'storyos/storyos.php'                          => 'worldgraph/worldgraph.php',
			'storyos/v1'                                   => 'worldgraph/v1',
			'storyos-celtx/v1'                             => 'worldgraph/v1',
			'storyos-web-stories/v1'                       => 'worldgraph-web-stories/v1',
			'field_storyos_editorial_artifact_type'        => 'field_worldgraph_editorial_artifact_type',
		]
	);
	if ( isset( $identifier_map[ $value ] ) ) {
		return $identifier_map[ $value ];
	}

	foreach ( worldgraph_identifier_pairs_longest_first() as $legacy => $canonical ) {
		$field_prefix = 'field_' . $legacy . '_';
		if ( 0 === strpos( $value, $field_prefix ) ) {
			return 'field_' . $canonical . '_' . substr( $value, strlen( $field_prefix ) );
		}
		$group_key = 'group_' . $legacy;
		if ( $group_key === $value ) {
			return 'group_' . $canonical;
		}
	}

	if ( 0 === strpos( $value, 'field_storyos_' ) ) {
		return 'field_worldgraph_' . substr( $value, strlen( 'field_storyos_' ) );
	}
	if ( 0 === strpos( $value, 'group_storyos_' ) ) {
		return 'group_worldgraph_' . substr( $value, strlen( 'group_storyos_' ) );
	}
	if ( preg_match( '/^StoryOS(?:[A-Z][A-Za-z0-9]*)?\\\\/', $value ) ) {
		return 'WorldGraph' . substr( $value, strlen( 'StoryOS' ) );
	}
	// Ability, block, and REST namespaces are persisted as slash identifiers.
	if ( 0 === strpos( $value, 'storyos/' ) ) {
		return 'worldgraph/' . substr( $value, strlen( 'storyos/' ) );
	}

	// Persisted capabilities assigned directly to roles or users.
	if ( preg_match( '/^(?:manage|edit|read|delete|publish|create|assign|upload)_storyos(?:_|$)/', $value ) ) {
		return str_replace( 'storyos', 'worldgraph', $value );
	}
	// Known scheduled hooks and the search widget's persisted ID base.
	if ( preg_match( '/^storyos_(?:process_generation_batch|provision_(?:fal|elevenlabs)_templates|(?:celtx|web_stories)_sync_[a-z_]+|search-[0-9]+)$/', $value ) ) {
		return 'worldgraph_' . substr( $value, strlen( 'storyos_' ) );
	}
	if ( 0 === strpos( $value, 'storyos://' ) || 0 === strpos( $value, 'storyos/plugins/' ) ) {
		return 'worldgraph' . substr( $value, strlen( 'storyos' ) );
	}

	return $value;
}

/**
 * Rename only the legacy shortcode and block tokens embedded in authored
 * content. Product prose and similarly prefixed shortcode names are retained.
 */
function worldgraph_transform_post_content_identifiers( string $content, ?bool &$changed = null ): string {
	$transformed = preg_replace(
		'/(\[\/?)(storyos_search)(?=[\s\/\]])/',
		'$1worldgraph_search',
		$content
	);
	if ( null === $transformed ) {
		$transformed = $content;
	}
	$transformed = str_replace(
		'wp:storyos/ai-editor-panel',
		'wp:worldgraph/ai-editor-panel',
		$transformed
	);
	$changed = $transformed !== $content;
	return $transformed;
}

/** @return array<int, string> Exact token fragments used to bound content SQL. */
function worldgraph_legacy_post_content_tokens(): array {
	return [
		'[storyos_search ',
		'[storyos_search]',
		'[storyos_search/',
		'[/storyos_search]',
		'[/storyos_search ',
		'wp:storyos/ai-editor-panel',
	];
}

/** Rename an option/meta/screen key, including embedded CPT names. */
function worldgraph_migrate_machine_name( string $name ): string {
	foreach ( worldgraph_identifier_pairs_longest_first() as $legacy => $canonical ) {
		$name = str_replace( $legacy, $canonical, $name );
	}
	return str_replace( 'storyos', 'worldgraph', $name );
}

/**
 * Recursively transform machine identifiers and associative keys.
 *
 * @param mixed     $value         Stored value.
 * @param bool      $replace_brand Whether an SCF-owned presentation string may
 *                                 also receive the product-name replacement.
 * @param bool|null $changed       Set when a mutation occurred.
 * @param bool|null $collision     Set when legacy and canonical array keys
 *                                 contain different values.
 * @return mixed
 */
function worldgraph_transform_identifier_tree( $value, bool $replace_brand = false, ?bool &$changed = null, ?bool &$collision = null ) {
	$changed   = false;
	$collision = false;
	if ( is_array( $value ) ) {
		$result = [];
		foreach ( $value as $key => $item ) {
			$new_key        = is_string( $key ) ? worldgraph_migrate_machine_name( $key ) : $key;
			$item_changed   = false;
			$item_collision = false;
			$key_collision  = false;
			$new_item       = worldgraph_transform_identifier_tree( $item, $replace_brand, $item_changed, $item_collision );

			// Retain both branches if independently stored keys would collide.
			if ( $new_key !== $key && array_key_exists( $new_key, $value ) ) {
				$target_changed   = false;
				$target_collision = false;
				$target_item      = worldgraph_transform_identifier_tree( $value[ $new_key ], $replace_brand, $target_changed, $target_collision );
				if ( $target_collision || $target_item !== $new_item ) {
					$new_key       = $key;
					$key_collision = true;
				}
			}
			if ( $new_key !== $key && array_key_exists( $new_key, $result ) && $result[ $new_key ] !== $new_item ) {
				$new_key       = $key;
				$key_collision = true;
			}
			$result[ $new_key ] = $new_item;
			$changed   = $changed || $item_changed || $new_key !== $key;
			$collision = $collision || $item_collision || $key_collision;
		}
		return $result;
	}

	if ( ! is_string( $value ) ) {
		return $value;
	}
	$new_value = worldgraph_migrate_machine_identifier( $value );
	if ( $replace_brand ) {
		$new_value = str_replace( 'StoryOS', 'World Graph Studio', $new_value );
	}
	$changed = $new_value !== $value;
	return $new_value;
}

/**
 * Transform a scalar, serialized array, or JSON object without corrupting PHP
 * serialization lengths.
 */
function worldgraph_transform_stored_value( string $raw_value, bool $replace_brand = false, ?bool &$collision = null ): string {
	$collision = false;
	$format  = 'plain';
	$decoded = $raw_value;
	if ( worldgraph_is_serialized_string( $raw_value ) ) {
		$unserialized = @unserialize( trim( $raw_value ), [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( false !== $unserialized || 'b:0;' === trim( $raw_value ) ) {
			$decoded = $unserialized;
			$format  = 'serialized';
		}
	} elseif ( '' !== $raw_value && in_array( $raw_value[0], [ '{', '[' ], true ) ) {
		$json = json_decode( $raw_value, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $json ) ) {
			$decoded = $json;
			$format  = 'json';
		}
	}

	$changed     = false;
	$transformed = worldgraph_transform_identifier_tree( $decoded, $replace_brand, $changed, $collision );
	if ( ! $changed ) {
		return $raw_value;
	}
	if ( 'serialized' === $format ) {
		return serialize( $transformed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}
	if ( 'json' === $format ) {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $transformed ) : json_encode( $transformed );
		return false === $encoded ? $raw_value : (string) $encoded;
	}
	return is_string( $transformed ) ? $transformed : $raw_value;
}

/** Whether a string has a PHP serialization envelope. */
function worldgraph_is_serialized_string( string $value ): bool {
	$value = trim( $value );
	if ( 'N;' === $value ) {
		return true;
	}
	if ( strlen( $value ) < 4 || ':' !== $value[1] ) {
		return false;
	}
	return (bool) preg_match( '/^(?:a|O|s|b|i|d|C):/', $value );
}

/** Whether a value is a serialized or JSON container rather than a scalar. */
function worldgraph_storage_value_is_structured( string $value ): bool {
	$value = trim( $value );
	return worldgraph_is_serialized_string( $value )
		|| ( '' !== $value && in_array( $value[0], [ '{', '[' ], true ) );
}

/**
 * Return identifier pairs in longest-source-first order.
 *
 * @return array<string, string>
 */
function worldgraph_identifier_pairs_longest_first(): array {
	$pairs = array_merge( worldgraph_legacy_cpt_key_map(), worldgraph_legacy_taxonomy_key_map() );
	uksort(
		$pairs,
		static function ( string $left, string $right ): int {
			return strlen( $right ) <=> strlen( $left );
		}
	);
	return $pairs;
}

/**
 * Run the complete namespace migration once, with retryable phases.
 *
 * The bootstrap calls this after canonical CPT registration and before
 * taxonomy default seeding and SCF archive synchronization.
 */
function worldgraph_maybe_migrate_cpt_keys(): void {
	if ( (int) get_option( WORLDGRAPH_NAMESPACE_MIGRATION_OPTION, 0 ) >= WORLDGRAPH_NAMESPACE_MIGRATION_VERSION ) {
		return;
	}
	$token = worldgraph_acquire_namespace_migration_lock();
	if ( '' === $token ) {
		return;
	}

	$errors  = [];
	$pending = false;
	try {
		worldgraph_migrate_post_types( $errors, $pending );
		worldgraph_migrate_post_content_identifiers( $errors, $pending );
		worldgraph_migrate_taxonomies( $errors );
		worldgraph_migrate_options( $errors, $pending );
		worldgraph_migrate_option_values( $errors, $pending );
		worldgraph_migrate_metadata_table( 'post', $errors, $pending );
		worldgraph_migrate_metadata_table( 'term', $errors, $pending );
		worldgraph_migrate_metadata_table( 'user', $errors, $pending );
		worldgraph_migrate_user_capability_values( $errors, $pending );
		worldgraph_migrate_scf_schema_posts( $errors, $pending );
		worldgraph_migrate_structured_post_meta_values( $errors, $pending );
		worldgraph_migrate_cron_hooks( $errors );
		worldgraph_purge_legacy_caches();

		if ( empty( $errors ) && ! $pending && worldgraph_namespace_migration_is_complete( $errors ) ) {
			update_option( WORLDGRAPH_NAMESPACE_MIGRATION_OPTION, WORLDGRAPH_NAMESPACE_MIGRATION_VERSION, false );
			delete_option( WORLDGRAPH_NAMESPACE_MIGRATION_ERRORS );
			delete_option( WORLDGRAPH_NAMESPACE_OPTION_VALUE_CURSOR );
			delete_option( WORLDGRAPH_NAMESPACE_POSTMETA_VALUE_CURSOR );
			// Force SCF to verify the renamed archive/database identities once.
			delete_option( 'worldgraph_scf_archive_hash' );
			delete_option( 'worldgraph_scf_schema_sync_error' );
		} else {
			if ( $pending ) {
				$errors[] = 'The namespace migration has more rows to process on the next request.';
			}
			update_option( WORLDGRAPH_NAMESPACE_MIGRATION_ERRORS, array_values( array_unique( $errors ) ), false );
			add_action( 'admin_notices', __NAMESPACE__ . '\\worldgraph_namespace_migration_notice' );
		}
	} finally {
		worldgraph_release_namespace_migration_lock( $token );
	}
}

/** Acquire a short-lived atomic migration lock. */
function worldgraph_acquire_namespace_migration_lock(): string {
	$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'worldgraph_', true );
	$lock  = [ 'token' => $token, 'created' => time() ];
	if ( add_option( WORLDGRAPH_NAMESPACE_MIGRATION_LOCK, $lock, '', false ) ) {
		return $token;
	}
	$current = get_option( WORLDGRAPH_NAMESPACE_MIGRATION_LOCK, [] );
	if ( is_array( $current ) && time() - (int) ( $current['created'] ?? 0 ) <= 15 * MINUTE_IN_SECONDS ) {
		return '';
	}

	global $wpdb;
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			maybe_serialize( $lock ),
			WORLDGRAPH_NAMESPACE_MIGRATION_LOCK,
			maybe_serialize( $current )
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- compare-and-swap owns the migration lock.
	wp_cache_delete( WORLDGRAPH_NAMESPACE_MIGRATION_LOCK, 'options' );
	return 1 === $updated ? $token : '';
}

/** Release only the lock still owned by this request. */
function worldgraph_release_namespace_migration_lock( string $token ): void {
	$current = get_option( WORLDGRAPH_NAMESPACE_MIGRATION_LOCK, [] );
	if ( ! is_array( $current ) || $token !== (string) ( $current['token'] ?? '' ) ) {
		return;
	}
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			WORLDGRAPH_NAMESPACE_MIGRATION_LOCK,
			maybe_serialize( $current )
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- conditional delete cannot release a successor's lock.
	wp_cache_delete( WORLDGRAPH_NAMESPACE_MIGRATION_LOCK, 'options' );
}

/** Update wp_posts.post_type without touching timestamps or content. */
function worldgraph_migrate_post_types( array &$errors, bool &$pending ): void {
	global $wpdb;
	foreach ( worldgraph_legacy_cpt_key_map() as $legacy => $canonical ) {
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT %d",
				$legacy,
				WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE + 1
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( empty( $ids ) ) {
			continue;
		}
		if ( count( $ids ) > WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE ) {
			$pending = true;
			array_pop( $ids );
		}
		$ids        = array_map( 'absint', $ids );
		$id_list    = implode( ', ', $ids );
		$result     = $wpdb->query(
			$wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = %s WHERE ID IN ({$id_list})", $canonical )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ID list is absint-normalized.
		if ( false === $result ) {
			$errors[] = "Could not migrate post type {$legacy}.";
			continue;
		}
		foreach ( $ids as $post_id ) {
			clean_post_cache( (int) $post_id );
		}
	}
}

/** Migrate exact shortcode and Gutenberg block identifiers in post content. */
function worldgraph_migrate_post_content_identifiers( array &$errors, bool &$pending ): void {
	global $wpdb;
	$patterns = array_map(
		static function ( string $token ) use ( $wpdb ): string {
			return '%' . $wpdb->esc_like( $token ) . '%';
		},
		worldgraph_legacy_post_content_tokens()
	);
	$where    = implode( ' OR ', array_fill( 0, count( $patterns ), 'post_content LIKE %s' ) );
	$rows     = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			WHERE ({$where}) ORDER BY ID ASC LIMIT %d",
			...array_merge( $patterns, [ WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE + 1 ] )
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the WHERE fragment contains fixed placeholders only.
	if ( count( $rows ) > WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE ) {
		$pending = true;
		array_pop( $rows );
	}

	foreach ( $rows as $row ) {
		$content = worldgraph_transform_post_content_identifiers( (string) $row['post_content'] );
		if ( $content === (string) $row['post_content'] ) {
			continue;
		}
		if ( false === $wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => (int) $row['ID'] ], [ '%s' ], [ '%d' ] ) ) {
			$errors[] = 'Could not migrate persisted content identifiers for post ' . (int) $row['ID'] . '.';
			continue;
		}
		clean_post_cache( (int) $row['ID'] );
	}
}

/** Move taxonomy rows before canonical taxonomies seed default terms. */
function worldgraph_migrate_taxonomies( array &$errors ): void {
	global $wpdb;
	if ( ! isset( $wpdb->term_taxonomy, $wpdb->terms, $wpdb->term_relationships ) ) {
		$errors[] = 'WordPress taxonomy tables are unavailable.';
		return;
	}

	$cache_term_ids = [];
	foreach ( worldgraph_legacy_taxonomy_key_map() as $legacy => $canonical ) {
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_taxonomy_id, tt.term_id, tt.parent, tt.description, t.name, t.slug
				FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				$legacy
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$term_id_map = [];
		foreach ( $rows as $row ) {
			$cache_term_ids[] = (int) $row['term_id'];
			$target = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT tt.term_taxonomy_id, tt.term_id, tt.parent, tt.description, t.name
					FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s AND t.slug = %s ORDER BY tt.term_taxonomy_id ASC LIMIT 1",
					$canonical,
					(string) $row['slug']
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$old_term_id = (int) $row['term_id'];
			if ( $target ) {
				$cache_term_ids[] = (int) $target['term_id'];
				if ( worldgraph_merge_term_taxonomy_rows( $row, $target, $errors ) ) {
					$term_id_map[ $old_term_id ] = (int) $target['term_id'];
				}
				continue;
			}
			$updated = $wpdb->update(
				$wpdb->term_taxonomy,
				[ 'taxonomy' => $canonical ],
				[ 'term_taxonomy_id' => (int) $row['term_taxonomy_id'] ],
				[ '%s' ],
				[ '%d' ]
			);
			if ( false === $updated ) {
				$errors[] = "Could not migrate taxonomy {$legacy}.";
			} else {
				$term_id_map[ $old_term_id ] = $old_term_id;
			}
		}

		foreach ( $term_id_map as $old_parent => $new_parent ) {
			if ( $old_parent !== $new_parent ) {
				$wpdb->update(
					$wpdb->term_taxonomy,
					[ 'parent' => $new_parent ],
					[ 'taxonomy' => $canonical, 'parent' => $old_parent ],
					[ '%d' ],
					[ '%s', '%d' ]
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
		}
	}
	if ( ! empty( $cache_term_ids ) ) {
		clean_term_cache( array_values( array_unique( $cache_term_ids ) ), '', true );
	}
}

/** Merge an old taxonomy row into an already-created canonical term. */
function worldgraph_merge_term_taxonomy_rows( array $source, array $target, array &$errors ): bool {
	global $wpdb;
	$source_tt = (int) $source['term_taxonomy_id'];
	$target_tt = (int) $target['term_taxonomy_id'];
	$source_id = (int) $source['term_id'];
	$target_id = (int) $target['term_id'];
	$source_description = trim( (string) ( $source['description'] ?? '' ) );
	$target_description = trim( (string) ( $target['description'] ?? '' ) );
	if ( (string) ( $source['name'] ?? '' ) !== (string) ( $target['name'] ?? '' )
		|| ( '' !== $source_description && '' !== $target_description && $source_description !== $target_description ) ) {
		$errors[] = "Taxonomy term collision for slug {$source['slug']} requires administrator review.";
		return false;
	}
	if ( '' !== $source_description && '' === $target_description ) {
		$wpdb->update( $wpdb->term_taxonomy, [ 'description' => $source_description ], [ 'term_taxonomy_id' => $target_tt ], [ '%s' ], [ '%d' ] );
	}
	$source_parent = (int) ( $source['parent'] ?? 0 );
	$target_parent = (int) ( $target['parent'] ?? 0 );
	if ( $source_parent && $target_parent && $source_parent !== $target_parent ) {
		$errors[] = "Taxonomy term hierarchy collision for slug {$source['slug']} requires administrator review.";
		return false;
	}
	if ( $source_parent && ! $target_parent ) {
		$wpdb->update( $wpdb->term_taxonomy, [ 'parent' => $source_parent ], [ 'term_taxonomy_id' => $target_tt ], [ '%d' ], [ '%d' ] );
	}

	$moved = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order)
			SELECT object_id, %d, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
			$target_tt,
			$source_tt
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	if ( false === $moved ) {
		$errors[] = "Could not merge taxonomy relationship row {$source_tt}.";
		return false;
	}

	if ( $source_id !== $target_id && isset( $wpdb->termmeta ) ) {
		$meta_rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d", $source_id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		foreach ( $meta_rows as $meta ) {
			$key       = worldgraph_migrate_machine_name( (string) $meta['meta_key'] );
			$collision = false;
			$value     = worldgraph_transform_stored_value( (string) $meta['meta_value'], false, $collision );
			if ( $collision ) {
				$errors[] = "Conflicting legacy and canonical keys remain in term metadata for term {$source_id}.";
				return false;
			}
			if ( 'worldgraph_sequence_order' === $key ) {
				$wpdb->delete( $wpdb->termmeta, [ 'term_id' => $target_id, 'meta_key' => $key ], [ '%d', '%s' ] );
			}
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s AND meta_value = %s LIMIT 1",
					$target_id,
					$key,
					$value
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( ! $exists && false === $wpdb->insert( $wpdb->termmeta, [ 'term_id' => $target_id, 'meta_key' => $key, 'meta_value' => $value ], [ '%d', '%s', '%s' ] ) ) {
				$errors[] = "Could not preserve term metadata while merging term {$source_id}.";
				return false;
			}
		}
	}

	if ( false === $wpdb->delete( $wpdb->term_relationships, [ 'term_taxonomy_id' => $source_tt ], [ '%d' ] )
		|| false === $wpdb->delete( $wpdb->term_taxonomy, [ 'term_taxonomy_id' => $source_tt ], [ '%d' ] ) ) {
		$errors[] = "Could not remove migrated taxonomy row {$source_tt}.";
		return false;
	}
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $target_tt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->update( $wpdb->term_taxonomy, [ 'count' => $count ], [ 'term_taxonomy_id' => $target_tt ], [ '%d' ], [ '%d' ] );

	if ( $source_id !== $target_id ) {
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 0 === $remaining ) {
			if ( isset( $wpdb->termmeta ) ) {
				$wpdb->delete( $wpdb->termmeta, [ 'term_id' => $source_id ], [ '%d' ] );
			}
			$wpdb->delete( $wpdb->terms, [ 'term_id' => $source_id ], [ '%d' ] );
		}
	}
	return true;
}

/** Rename namespaced options and exact identifiers in their values. */
function worldgraph_migrate_options( array &$errors, bool &$pending ): void {
	global $wpdb;
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options}
			WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
			'%' . $wpdb->esc_like( 'storyos' ) . '%',
			WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE + 1
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	if ( count( $rows ) > WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE ) {
		$pending = true;
		array_pop( $rows );
	}

	foreach ( $rows as $row ) {
		$legacy = (string) $row['option_name'];
		if ( worldgraph_is_legacy_transient_option( $legacy ) || worldgraph_is_legacy_lock_option( $legacy ) ) {
			$wpdb->delete( $wpdb->options, [ 'option_id' => (int) $row['option_id'] ], [ '%d' ] );
			worldgraph_clear_option_cache( $legacy );
			continue;
		}

		$canonical = worldgraph_migrate_machine_name( $legacy );
		$collision = false;
		$value     = worldgraph_transform_stored_value( (string) $row['option_value'], false, $collision );
		if ( $collision ) {
			$errors[] = "Conflicting legacy and canonical keys remain in option {$legacy}.";
			continue;
		}
		$target    = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $canonical ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 'storyos_fields' === $legacy ) {
			$value = worldgraph_merge_field_definition_options( $value, $target ? (string) $target['option_value'] : '' );
		}

		if ( $target && (string) $target['option_value'] !== $value ) {
			if ( 'storyos_fields' !== $legacy ) {
				$errors[] = "Both {$legacy} and {$canonical} exist with different values; neither was overwritten.";
				continue;
			}
			$updated = $wpdb->update( $wpdb->options, [ 'option_value' => $value ], [ 'option_id' => (int) $target['option_id'] ], [ '%s' ], [ '%d' ] );
			if ( false === $updated ) {
				$errors[] = 'Could not merge the legacy field-definition option.';
				continue;
			}
		} elseif ( ! $target ) {
			$inserted = $wpdb->insert(
				$wpdb->options,
				[ 'option_name' => $canonical, 'option_value' => $value, 'autoload' => (string) $row['autoload'] ],
				[ '%s', '%s', '%s' ]
			);
			if ( false === $inserted ) {
				$errors[] = "Could not migrate option {$legacy}.";
				continue;
			}
		}

		if ( false === $wpdb->delete( $wpdb->options, [ 'option_id' => (int) $row['option_id'] ], [ '%d' ] ) ) {
			$errors[] = "Could not remove migrated option {$legacy}.";
			continue;
		}
		worldgraph_clear_option_cache( $legacy );
		worldgraph_clear_option_cache( $canonical );
	}

	worldgraph_migrate_core_serialized_option( 'active_plugins', $errors );
	worldgraph_migrate_core_serialized_option( 'sidebars_widgets', $errors );
	$role_options = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%\\_user\\_roles'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
	foreach ( $role_options as $role_option ) {
		worldgraph_migrate_core_serialized_option( (string) $role_option, $errors );
	}
	worldgraph_migrate_network_active_plugins( $errors );
}

/**
 * Transform exact legacy identifiers in canonical namespaced option values. A
 * cursor lets prose containing the old product name remain without repeatedly
 * occupying the first batch.
 */
function worldgraph_migrate_option_values( array &$errors, bool &$pending ): void {
	global $wpdb;
	$cursor = max( 0, (int) get_option( WORLDGRAPH_NAMESPACE_OPTION_VALUE_CURSOR, 0 ) );
	$rows   = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_id, option_name, option_value FROM {$wpdb->options}
			WHERE option_id > %d AND option_name != 'cron'
			AND option_name LIKE %s AND (option_value LIKE %s OR option_value LIKE %s)
			ORDER BY option_id ASC LIMIT %d",
			$cursor,
			'%' . $wpdb->esc_like( 'worldgraph' ) . '%',
			'%' . $wpdb->esc_like( 'storyos' ) . '%',
			'%' . $wpdb->esc_like( 'StoryOS' ) . '%',
			WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE + 1
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- cursor-bounded compatibility scan.
	if ( count( $rows ) > WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE ) {
		$pending = true;
		array_pop( $rows );
	}

	foreach ( $rows as $row ) {
		$collision = false;
		$value     = worldgraph_transform_stored_value( (string) $row['option_value'], false, $collision );
		if ( $collision ) {
			$errors[] = 'Conflicting legacy and canonical keys remain in option ' . (string) $row['option_name'] . '.';
			$pending  = true;
			break;
		}
		if ( $value !== (string) $row['option_value']
			&& false === $wpdb->update( $wpdb->options, [ 'option_value' => $value ], [ 'option_id' => (int) $row['option_id'] ], [ '%s' ], [ '%d' ] ) ) {
			$errors[] = 'Could not transform identifiers in option ' . (string) $row['option_name'] . '.';
			$pending  = true;
			break;
		}
		if ( $value !== (string) $row['option_value'] ) {
			worldgraph_clear_option_cache( (string) $row['option_name'] );
		}
		$cursor = (int) $row['option_id'];
	}
	update_option( WORLDGRAPH_NAMESPACE_OPTION_VALUE_CURSOR, $cursor, false );
}

/** Rename a network-active plugin basename on multisite installations. */
function worldgraph_migrate_network_active_plugins( array &$errors ): void {
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'get_site_option' ) ) {
		return;
	}
	$plugins = get_site_option( 'active_sitewide_plugins', [] );
	if ( ! is_array( $plugins ) ) {
		return;
	}
	$changed = false;
	$result  = [];
	foreach ( $plugins as $basename => $activated_at ) {
		$canonical = worldgraph_migrate_machine_identifier( (string) $basename );
		if ( isset( $result[ $canonical ] ) ) {
			$result[ $canonical ] = max( (int) $result[ $canonical ], (int) $activated_at );
		} else {
			$result[ $canonical ] = $activated_at;
		}
		$changed = $changed || $canonical !== $basename;
	}
	if ( $changed && ! update_site_option( 'active_sitewide_plugins', $result ) ) {
		$errors[] = 'Could not update the network-active plugin basename.';
	}
}

/** Merge legacy field definitions beneath current code-defined fields. */
function worldgraph_merge_field_definition_options( string $legacy_raw, string $canonical_raw ): string {
	$legacy    = worldgraph_decode_serialized_array( $legacy_raw );
	$canonical = worldgraph_decode_serialized_array( $canonical_raw );
	if ( ! is_array( $legacy ) ) {
		return $canonical_raw;
	}
	if ( ! is_array( $canonical ) ) {
		return serialize( $legacy ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}
	$merged = $legacy;
	foreach ( $canonical as $cpt => $fields ) {
		$merged[ $cpt ] = isset( $merged[ $cpt ] ) && is_array( $merged[ $cpt ] ) && is_array( $fields )
			? array_replace( $merged[ $cpt ], $fields )
			: $fields;
	}
	return serialize( $merged ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
}

/** Decode only serialized arrays. */
function worldgraph_decode_serialized_array( string $raw ) {
	if ( ! worldgraph_is_serialized_string( $raw ) ) {
		return null;
	}
	$value = @unserialize( trim( $raw ), [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
	return is_array( $value ) ? $value : null;
}

/** Transform one WordPress-owned serialized option in place. */
function worldgraph_migrate_core_serialized_option( string $option_name, array &$errors ): void {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name ),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	if ( ! $row ) {
		return;
	}
	$collision = false;
	$value     = worldgraph_transform_stored_value( (string) $row['option_value'], false, $collision );
	if ( $collision ) {
		$errors[] = "Conflicting legacy and canonical keys remain in option {$option_name}.";
		return;
	}
	if ( 'active_plugins' === $option_name ) {
		$plugins = worldgraph_decode_serialized_array( $value );
		if ( is_array( $plugins ) ) {
			$value = serialize( array_values( array_unique( array_map( 'strval', $plugins ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}
	}
	if ( $value === (string) $row['option_value'] ) {
		return;
	}
	if ( false === $wpdb->update( $wpdb->options, [ 'option_value' => $value ], [ 'option_id' => (int) $row['option_id'] ], [ '%s' ], [ '%d' ] ) ) {
		$errors[] = "Could not update identifiers in option {$option_name}.";
		return;
	}
	worldgraph_clear_option_cache( $option_name );
}

/** Whether an option row is an expendable transient or timeout. */
function worldgraph_is_legacy_transient_option( string $name ): bool {
	return 0 === strpos( $name, '_transient_storyos_' )
		|| 0 === strpos( $name, '_transient_timeout_storyos_' )
		|| 0 === strpos( $name, '_site_transient_storyos_' )
		|| 0 === strpos( $name, '_site_transient_timeout_storyos_' );
}

/** Whether an old lock must be discarded rather than inherited. */
function worldgraph_is_legacy_lock_option( string $name ): bool {
	return in_array(
		$name,
		[
			'storyos_cpt_key_migration_version',
			'storyos_namespace_migration_version',
			'storyos_namespace_migration_errors',
			'storyos_namespace_option_value_cursor',
			'storyos_namespace_postmeta_value_cursor',
			'storyos_scf_archive_hash',
			'storyos_scf_schema_sync_lock',
			'storyos_scf_schema_sync_error',
			'storyos_scf_value_migration_lock',
			'storyos_namespace_migration_lock',
		],
		true
	);
}

/** Rename namespaced metadata while preserving distinct multivalue rows. */
function worldgraph_migrate_metadata_table( string $type, array &$errors, bool &$pending ): void {
	global $wpdb;
	$config = [
		'post' => [ $wpdb->postmeta ?? '', 'meta_id', 'post_id', 'post_meta' ],
		'term' => [ $wpdb->termmeta ?? '', 'meta_id', 'term_id', 'term_meta' ],
		'user' => [ $wpdb->usermeta ?? '', 'umeta_id', 'user_id', 'user_meta' ],
	];
	if ( empty( $config[ $type ][0] ) ) {
		return;
	}
	[ $table, $primary, $object_column, $cache_group ] = $config[ $type ];
	$rows = (array) $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $primary is selected from the literal metadata-table map above, never request input.
		$wpdb->prepare(
			"SELECT {$primary} AS row_id, {$object_column} AS object_id, meta_key, meta_value
			FROM {$table} WHERE meta_key LIKE %s ORDER BY {$primary} ASC LIMIT %d",
			'%' . $wpdb->esc_like( 'storyos' ) . '%',
			WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE + 1
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from the fixed metadata-table map above.
	if ( count( $rows ) > WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE ) {
		$pending = true;
		array_pop( $rows );
	}

	foreach ( $rows as $row ) {
		$key       = worldgraph_migrate_machine_name( (string) $row['meta_key'] );
		$collision = false;
		$value     = worldgraph_transform_stored_value( (string) $row['meta_value'], false, $collision );
		if ( $collision ) {
			$errors[] = "Conflicting legacy and canonical keys remain in {$type} metadata row " . (int) $row['row_id'] . '.';
			continue;
		}
		$targets = (array) $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $primary is selected from the literal metadata-table map above, never request input.
			$wpdb->prepare(
				"SELECT {$primary} AS row_id, meta_value FROM {$table}
				WHERE {$object_column} = %d AND meta_key = %s AND {$primary} != %d",
				(int) $row['object_id'],
				$key,
				(int) $row['row_id']
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from the fixed metadata-table map above.
		$duplicate     = false;
		$target_failed = false;
		foreach ( $targets as $target ) {
			$target_collision = false;
			$target_value     = worldgraph_transform_stored_value( (string) $target['meta_value'], false, $target_collision );
			if ( $target_collision ) {
				$errors[]     = "Conflicting keys remain in canonical {$type} metadata row " . (int) $target['row_id'] . '.';
				$target_failed = true;
				break;
			}
			if ( $target_value !== (string) $target['meta_value'] ) {
				if ( false === $wpdb->update( $table, [ 'meta_value' => $target_value ], [ $primary => (int) $target['row_id'] ], [ '%s' ], [ '%d' ] ) ) {
					$errors[]     = "Could not transform canonical {$type} metadata row " . (int) $target['row_id'] . '.';
					$target_failed = true;
					break;
				}
				wp_cache_delete( (int) $row['object_id'], $cache_group );
			}
			if ( $target_value === $value ) {
				$duplicate = true;
				break;
			}
		}
		if ( $target_failed ) {
			continue;
		}
		if ( ! empty( $targets ) && ! $duplicate ) {
			$errors[] = "Legacy and canonical {$type} metadata differ for object " . (int) $row['object_id'] . " and key {$key}.";
			continue;
		}
		if ( $duplicate ) {
			$result = $wpdb->delete( $table, [ $primary => (int) $row['row_id'] ], [ '%d' ] );
		} else {
			$result = $wpdb->update( $table, [ 'meta_key' => $key, 'meta_value' => $value ], [ $primary => (int) $row['row_id'] ], [ '%s', '%s' ], [ '%d' ] );
		}
		if ( false === $result ) {
			$errors[] = "Could not migrate {$type} metadata row " . (int) $row['row_id'] . '.';
			continue;
		}
		wp_cache_delete( (int) $row['object_id'], $cache_group );
	}
}

/** Migrate directly assigned legacy capabilities in serialized user metadata. */
function worldgraph_migrate_user_capability_values( array &$errors, bool &$pending ): void {
	global $wpdb;
	if ( empty( $wpdb->usermeta ) ) {
		return;
	}
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT umeta_id, user_id, meta_value FROM {$wpdb->usermeta}
			WHERE meta_key LIKE %s AND meta_value LIKE %s ORDER BY umeta_id ASC LIMIT %d",
			'%' . $wpdb->esc_like( 'capabilities' ),
			'%' . $wpdb->esc_like( 'storyos' ) . '%',
			WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE + 1
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- capability keys are stored inside serialized user metadata.
	if ( count( $rows ) > WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE ) {
		$pending = true;
		array_pop( $rows );
	}
	foreach ( $rows as $row ) {
		$collision = false;
		$value     = worldgraph_transform_stored_value( (string) $row['meta_value'], false, $collision );
		if ( $collision ) {
			$errors[] = 'Conflicting capability keys remain in user metadata row ' . (int) $row['umeta_id'] . '.';
			continue;
		}
		if ( $value === (string) $row['meta_value'] ) {
			continue;
		}
		if ( false === $wpdb->update( $wpdb->usermeta, [ 'meta_value' => $value ], [ 'umeta_id' => (int) $row['umeta_id'] ], [ '%s' ], [ '%d' ] ) ) {
			$errors[] = 'Could not migrate user capability row ' . (int) $row['umeta_id'] . '.';
			continue;
		}
		wp_cache_delete( (int) $row['user_id'], 'user_meta' );
	}
}

/** Migrate SCF group/field keys and serialized settings in place. */
function worldgraph_migrate_scf_schema_posts( array &$errors, bool &$pending ): void {
	global $wpdb;
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_type, post_name, post_title, post_content
			FROM {$wpdb->posts}
			WHERE post_type IN ('acf-field-group', 'acf-field')
			AND (post_name LIKE %s OR post_name LIKE %s OR post_content LIKE %s)
			ORDER BY ID ASC LIMIT %d",
			'group\\_storyos\\_%',
			'field\\_storyos\\_%',
			'%' . $wpdb->esc_like( 'storyos_' ) . '%',
			WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE + 1
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	if ( count( $rows ) > WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE ) {
		$pending = true;
		array_pop( $rows );
	}

	foreach ( $rows as $row ) {
		$name      = worldgraph_migrate_machine_identifier( (string) $row['post_name'] );
		$collision = false;
		$content   = worldgraph_transform_stored_value( (string) $row['post_content'], true, $collision );
		$title     = str_replace( 'StoryOS', 'World Graph Studio', (string) $row['post_title'] );
		if ( $collision ) {
			$errors[] = 'Conflicting legacy and canonical keys remain in SCF schema post ' . (int) $row['ID'] . '.';
			continue;
		}
		$collision = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND ID != %d LIMIT 1",
				(string) $row['post_type'],
				$name,
				(int) $row['ID']
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( $collision ) {
			$errors[] = "SCF key {$name} already belongs to post {$collision}; legacy post " . (int) $row['ID'] . ' was retained.';
			continue;
		}

		$result = $wpdb->update(
			$wpdb->posts,
			[ 'post_name' => $name, 'post_title' => $title, 'post_content' => $content ],
			[ 'ID' => (int) $row['ID'] ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
		if ( false === $result ) {
			$errors[] = 'Could not migrate SCF schema post ' . (int) $row['ID'] . '.';
			continue;
		}
		clean_post_cache( (int) $row['ID'] );
	}
}

/** Update exact legacy identifiers in all post metadata value shapes. */
function worldgraph_migrate_structured_post_meta_values( array &$errors, bool &$pending ): void {
	global $wpdb;
	$cursor = max( 0, (int) get_option( WORLDGRAPH_NAMESPACE_POSTMETA_VALUE_CURSOR, 0 ) );
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
			WHERE meta_id > %d AND (meta_value LIKE %s OR meta_value LIKE %s)
			ORDER BY meta_id ASC LIMIT %d",
			$cursor,
			'%' . $wpdb->esc_like( 'storyos' ) . '%',
			'%' . $wpdb->esc_like( 'StoryOS' ) . '%',
			WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE + 1
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- cursor-bounded compatibility scan.
	if ( count( $rows ) > WORLDGRAPH_NAMESPACE_MIGRATION_BATCH_SIZE ) {
		$pending = true;
		array_pop( $rows );
	}

	foreach ( $rows as $row ) {
		$collision = false;
		$value     = worldgraph_transform_stored_value( (string) $row['meta_value'], false, $collision );
		if ( $collision ) {
			$errors[] = 'Conflicting legacy and canonical keys remain in post metadata row ' . (int) $row['meta_id'] . '.';
			$pending  = true;
			break;
		}
		if ( $value !== (string) $row['meta_value']
			&& false === $wpdb->update( $wpdb->postmeta, [ 'meta_value' => $value ], [ 'meta_id' => (int) $row['meta_id'] ], [ '%s' ], [ '%d' ] ) ) {
			$errors[] = 'Could not transform post metadata row ' . (int) $row['meta_id'] . '.';
			$pending  = true;
			break;
		}
		if ( $value !== (string) $row['meta_value'] ) {
			wp_cache_delete( (int) $row['post_id'], 'post_meta' );
		}
		$cursor = (int) $row['meta_id'];
	}
	update_option( WORLDGRAPH_NAMESPACE_POSTMETA_VALUE_CURSOR, $cursor, false );
}

/** Rename decoded WP-Cron hook keys while preserving event signatures. */
function worldgraph_migrate_cron_hooks( array &$errors ): void {
	if ( ! function_exists( '_get_cron_array' ) || ! function_exists( '_set_cron_array' ) ) {
		return;
	}
	$cron = _get_cron_array();
	if ( ! is_array( $cron ) ) {
		return;
	}
	$changed = false;
	$cron    = worldgraph_transform_cron_array( $cron, $errors, $changed );
	if ( $changed ) {
		$result = _set_cron_array( $cron, true );
		if ( is_wp_error( $result ) || false === $result ) {
			$errors[] = 'Could not persist renamed WP-Cron hooks.';
		}
	}
}

/**
 * Pure transformation of WordPress's decoded cron array.
 *
 * @param array<string|int, mixed> $cron    Decoded cron option.
 * @param array<int, string>       $errors  Collision diagnostics.
 * @param bool                     $changed Whether any hook was renamed.
 * @return array<string|int, mixed>
 */
function worldgraph_transform_cron_array( array $cron, array &$errors, bool &$changed ): array {
	$changed = false;
	foreach ( $cron as $timestamp => &$hooks ) {
		if ( ! is_array( $hooks ) ) {
			continue;
		}
		$rebuilt = [];
		$failed  = false;
		foreach ( $hooks as $hook => $events ) {
			$canonical = worldgraph_migrate_machine_identifier( (string) $hook );
			foreach ( (array) $events as $event ) {
				$event_changed   = false;
				$event_collision = false;
				$new_event       = worldgraph_transform_identifier_tree( $event, false, $event_changed, $event_collision );
				if ( $event_collision ) {
					$errors[] = "Conflicting legacy and canonical keys remain in cron hook {$hook} at {$timestamp}.";
					$failed   = true;
					break 2;
				}
				$new_signature = md5( serialize( (array) ( $new_event['args'] ?? [] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- this is WordPress's cron signature contract.
				if ( isset( $rebuilt[ $canonical ][ $new_signature ] ) && $rebuilt[ $canonical ][ $new_signature ] !== $new_event ) {
					$errors[] = "Cron hook {$hook} collides with {$canonical} at {$timestamp}.";
					$failed   = true;
					break 2;
				}
				$rebuilt[ $canonical ][ $new_signature ] = $new_event;
			}
		}
		if ( ! $failed && $rebuilt !== $hooks ) {
			$hooks   = $rebuilt;
			$changed = true;
		}
	}
	unset( $hooks );
	return $cron;
}

/** Purge caches and locks that should not cross namespaces. */
function worldgraph_purge_legacy_caches(): void {
	delete_option( 'rewrite_rules' );
	delete_option( 'acf_site_health' );
	delete_option( 'storyos_scf_archive_hash' );
	delete_option( 'storyos_scf_schema_sync_lock' );
	delete_option( 'storyos_scf_schema_sync_error' );
	delete_option( 'storyos_scf_value_migration_lock' );
	if ( function_exists( 'delete_site_transient' ) ) {
		delete_site_transient( 'update_plugins' );
	}
}

/** Final read-only audit of identifiers unsafe to leave behind. */
function worldgraph_namespace_migration_is_complete( array &$errors ): bool {
	global $wpdb;
	$complete     = true;
	$post_types   = array_keys( worldgraph_legacy_cpt_key_map() );
	$taxonomies   = array_keys( worldgraph_legacy_taxonomy_key_map() );
	$post_sources = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
	$tax_sources  = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$post_sources})", ...$post_types ) ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$errors[] = 'Legacy custom post type rows remain.';
		$complete = false;
	}
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$tax_sources})", ...$taxonomies ) ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$errors[] = 'Legacy taxonomy rows remain.';
		$complete = false;
	}
	foreach ( [ $wpdb->postmeta ?? '', $wpdb->termmeta ?? '', $wpdb->usermeta ?? '' ] as $table ) {
		if ( $table && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE meta_key LIKE '%storyos%'" ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names come from WordPress's fixed metadata table properties.
			$errors[] = "Legacy metadata keys remain in {$table}.";
			$complete = false;
		}
	}
	if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE 'field\\_storyos\\_%'" ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$errors[] = 'Legacy SCF reference values remain.';
		$complete = false;
	}
	if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'worldgraph_relationships' AND meta_value LIKE '%storyos\\_%'" ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$errors[] = 'Legacy CPT identifiers remain in relationship values.';
		$complete = false;
	}
	if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('acf-field-group','acf-field') AND (post_name LIKE 'group\\_storyos\\_%' OR post_name LIKE 'field\\_storyos\\_%')" ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$errors[] = 'Legacy SCF schema identities remain.';
		$complete = false;
	}
	if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%storyos%'" ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$errors[] = 'Legacy option names remain.';
		$complete = false;
	}
	$option_cursor = max( 0, (int) get_option( WORLDGRAPH_NAMESPACE_OPTION_VALUE_CURSOR, 0 ) );
	if ( $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_id FROM {$wpdb->options}
			WHERE option_id > %d AND option_name != 'cron'
			AND option_name LIKE %s AND (option_value LIKE %s OR option_value LIKE %s) LIMIT 1",
			$option_cursor,
			'%' . $wpdb->esc_like( 'worldgraph' ) . '%',
			'%' . $wpdb->esc_like( 'storyos' ) . '%',
			'%' . $wpdb->esc_like( 'StoryOS' ) . '%'
		)
	) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- verifies the bounded value scan reached every candidate.
		$errors[] = 'Legacy identifiers in option values have not all been audited.';
		$complete = false;
	}
	$postmeta_cursor = max( 0, (int) get_option( WORLDGRAPH_NAMESPACE_POSTMETA_VALUE_CURSOR, 0 ) );
	if ( $wpdb->get_var(
		$wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta}
			WHERE meta_id > %d AND (meta_value LIKE %s OR meta_value LIKE %s) LIMIT 1",
			$postmeta_cursor,
			'%' . $wpdb->esc_like( 'storyos' ) . '%',
			'%' . $wpdb->esc_like( 'StoryOS' ) . '%'
		)
	) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- verifies all postmeta value shapes passed through the safe transformer.
		$errors[] = 'Legacy identifiers in post metadata values have not all been audited.';
		$complete = false;
	}
	$content_patterns = array_map(
		static function ( string $token ) use ( $wpdb ): string {
			return '%' . $wpdb->esc_like( $token ) . '%';
		},
		worldgraph_legacy_post_content_tokens()
	);
	$content_where = implode( ' OR ', array_fill( 0, count( $content_patterns ), 'post_content LIKE %s' ) );
	if ( $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE ({$content_where}) LIMIT 1",
			...$content_patterns
		)
	) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed LIKE clauses target exact shortcode/block fragments.
		$errors[] = 'Legacy shortcode or block identifiers remain in post content.';
		$complete = false;
	}
	if ( function_exists( '_get_cron_array' ) ) {
		$cron_errors  = [];
		$cron_changed = false;
		worldgraph_transform_cron_array( (array) _get_cron_array(), $cron_errors, $cron_changed );
		if ( $cron_changed || ! empty( $cron_errors ) ) {
			$errors[] = 'Legacy or conflicting identifiers remain in WP-Cron events.';
			$complete = false;
		}
	}
	return $complete;
}

/** Evict individual and all-options cache entries after direct writes. */
function worldgraph_clear_option_cache( string $name ): void {
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
}

/** Show administrators why a collision or database failure needs attention. */
function worldgraph_namespace_migration_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$errors = get_option( WORLDGRAPH_NAMESPACE_MIGRATION_ERRORS, [] );
	if ( ! is_array( $errors ) || empty( $errors ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'World Graph Studio could not finish its legacy namespace migration. No conflicting value was overwritten.', 'worldgraph' )
		. '</p><ul>';
	foreach ( $errors as $error ) {
		echo '<li>' . esc_html( (string) $error ) . '</li>';
	}
	echo '</ul></div>';
}
