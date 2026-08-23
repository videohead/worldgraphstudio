<?php
/**
 * Secure Custom Fields integration for the World Graph Studio content model.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

/**
 * Persists World Graph Studio field contracts as SCF-managed field groups and adapts SCF
 * fields back to the small field dialect used by the World Graph Studio APIs.
 */
final class SCF_Fields {
	/** Version of the archive/database merge and verification algorithm. */
	private const SCHEMA_SYNC_VERSION = 3;

	/** State of the Local JSON archive last synchronized to editable DB groups. */
	private const ARCHIVE_HASH_OPTION = 'worldgraph_scf_archive_hash';

	/** Atomic lock for archive-to-database schema synchronization. */
	private const SCHEMA_SYNC_LOCK_OPTION = 'worldgraph_scf_schema_sync_lock';

	/** Durable diagnostics for a failed archive/database synchronization. */
	private const SCHEMA_SYNC_ERROR_OPTION = 'worldgraph_scf_schema_sync_error';

	/** Current one-time value migration version. */
	private const VALUE_MIGRATION_VERSION = 2;

	/** Option recording the completed value migration version. */
	private const VALUE_MIGRATION_OPTION = 'worldgraph_scf_value_migration_version';

	/** Atomic lock protecting serialized Story Graph writes during migration. */
	private const VALUE_MIGRATION_LOCK_OPTION = 'worldgraph_scf_value_migration_lock';

	/** Cursor for bounded legacy-value migration batches. */
	private const VALUE_MIGRATION_STATE_OPTION = 'worldgraph_scf_value_migration_state';

	/** Maximum number of World Graph Studio posts migrated in one request. */
	private const VALUE_MIGRATION_BATCH_SIZE = 100;

	/** Maximum attempts before a legacy value requires administrator review. */
	private const VALUE_MIGRATION_MAX_ATTEMPTS = 3;

	/**
	 * Whether runtime hooks have been registered.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Runtime field cache, keyed by CPT.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static $field_cache = [];

	/**
	 * SCF keys contained in each World Graph Studio-owned group, including sub-fields.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static $owned_field_keys = [];

	/** Whether an update originated from World Graph Studio's trusted field helper. */
	private static $internal_update = false;

	/** Errors collected during the current schema synchronization attempt. */
	private static $schema_sync_errors = [];

	/** Suppress field-level archive refreshes during archive-to-DB imports. */
	private static $schema_importing = false;

	/** World Graph Studio group IDs awaiting one debounced Local JSON refresh. */
	private static $pending_archive_group_ids = [];

	/**
	 * Initialize persisted groups and value synchronization.
	 *
	 * World Graph Studio runs on init priority 10, after SCF has registered its internal
	 * post types and APIs at priority 5. Persisted groups are used deliberately:
	 * unlike PHP-local groups, administrators can edit them in SCF's Field
	 * Groups screen.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $definitions Code-defined fields.
	 */
	public static function boot( array $definitions ): void {
		if ( ! self::is_available() ) {
			return;
		}

		self::maybe_sync_groups( $definitions );

		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		add_filter( 'acf/update_field', [ __CLASS__, 'enforce_canonical_field_contract' ], 1 );
		add_filter( 'acf/pre_update_field_group', [ __CLASS__, 'enforce_canonical_group_contract' ], 1 );
		add_filter( 'acf/prepare_field_group_for_import', [ __CLASS__, 'protect_external_field_group_import' ], 1 );
		add_filter( 'pre_delete_post', [ __CLASS__, 'protect_canonical_schema_post' ], 10, 3 );
		add_filter( 'pre_trash_post', [ __CLASS__, 'protect_canonical_schema_post' ], 10, 3 );
		add_action( 'pre_post_update', [ __CLASS__, 'protect_submitted_canonical_deletions' ], 1, 2 );
		add_filter( 'wp_delete_file', [ __CLASS__, 'protect_canonical_archive_file' ], 1 );
		add_action( 'acf/updated_field', [ __CLASS__, 'queue_field_archive_refresh' ], 20 );
		add_action( 'acf/delete_field', [ __CLASS__, 'queue_field_archive_refresh' ], 20 );
		add_action( 'acf/trash_field', [ __CLASS__, 'queue_field_archive_refresh' ], 20 );
		add_action( 'acf/untrash_field', [ __CLASS__, 'queue_field_archive_refresh' ], 20 );
		add_action( 'acf/import_field', [ __CLASS__, 'queue_field_archive_refresh' ], 20 );
		add_action( 'acf/update_field_group', [ __CLASS__, 'clear_pending_archive_refresh' ], 20 );
		add_action( 'shutdown', [ __CLASS__, 'flush_pending_archive_refreshes' ], 20 );
		add_filter( 'acf/pre_update_value', [ __CLASS__, 'protect_read_only_value' ], 20, 4 );
		add_filter( 'acf/pre_update_value', [ __CLASS__, 'protect_json_value' ], 30, 4 );
		add_filter( 'acf/update_value', [ __CLASS__, 'filter_update_value' ], 20, 4 );
		add_filter( 'acf/update_value', [ __CLASS__, 'slash_json_value_for_storage' ], 99, 4 );
		add_filter( 'acf/load_value', [ __CLASS__, 'filter_load_value' ], 20, 3 );
		add_filter( 'acf/prepare_field', [ __CLASS__, 'prepare_field' ] );
		add_filter( 'acf/validate_rest_value/type=textarea', [ __CLASS__, 'validate_rest_json_value' ], 20, 3 );
		add_action( 'added_post_meta', [ __CLASS__, 'sync_reference_meta' ], 10, 4 );
		add_action( 'updated_post_meta', [ __CLASS__, 'sync_reference_meta' ], 10, 4 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'delete_reference_meta' ], 10, 4 );
		add_action( 'admin_init', [ __CLASS__, 'maybe_migrate_legacy_values' ], 20 );
		add_action( 'admin_notices', [ __CLASS__, 'legacy_migration_notice' ] );
		add_action( 'admin_notices', [ __CLASS__, 'schema_sync_notice' ] );
	}

	/**
	 * Whether the installed SCF API is ready for persisted field groups.
	 */
	private static function is_available(): bool {
		return function_exists( 'acf_get_field_group' )
			&& function_exists( 'acf_get_field_groups' )
			&& function_exists( 'acf_get_fields' )
			&& function_exists( 'acf_import_field_group' );
	}

	/**
	 * Get the stable SCF group key for a World Graph Studio CPT.
	 *
	 * @param string $cpt CPT slug.
	 */
	public static function group_key( string $cpt ): string {
		return 'group_' . self::key_fragment( $cpt );
	}

	/**
	 * Get a globally unique, stable SCF field key.
	 *
	 * Field names repeat across World Graph Studio CPTs, so the CPT must be part of the key.
	 *
	 * @param string $cpt        CPT slug.
	 * @param string $field_name Field name.
	 */
	public static function field_key( string $cpt, string $field_name ): string {
		return 'field_' . self::key_fragment( $cpt . '_' . $field_name );
	}

	/**
	 * Normalize a string for an SCF key without requiring WordPress in unit tests.
	 */
	private static function key_fragment( string $value ): string {
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9_]+/', '_', $value );
		return trim( (string) $value, '_' );
	}

	/**
	 * Synchronize schemas only for privileged maintenance requests and only when
	 * the committed archive content changed. Front-end requests use the editable
	 * database groups without doing filesystem scans or deep field queries.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $definitions Code-defined fields.
	 */
	private static function maybe_sync_groups( array $definitions ): void {
		$is_cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( ! $is_cli && ( ! is_admin() || ! current_user_can( 'manage_options' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
			return;
		}

		$archive_hash = self::archive_hash( array_keys( $definitions ) );
		$retry        = false;
		if ( ! $is_cli && isset( $_GET['worldgraph_scf_retry_schema'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately below.
			check_admin_referer( 'worldgraph_scf_retry_schema' );
			$retry = true;
		}

		$sync_state = get_option( self::ARCHIVE_HASH_OPTION, [] );
		if (
			! $retry
			&& is_array( $sync_state )
			&& self::SCHEMA_SYNC_VERSION === (int) ( $sync_state['version'] ?? 0 )
			&& '' !== $archive_hash
			&& hash_equals( (string) ( $sync_state['archive_hash'] ?? '' ), $archive_hash )
			&& self::database_groups_have_canonical_fields( $definitions )
		) {
			return;
		}

		$failed_state = get_option( self::SCHEMA_SYNC_ERROR_OPTION, [] );
		if (
			! $retry
			&& is_array( $failed_state )
			&& self::SCHEMA_SYNC_VERSION === (int) ( $failed_state['version'] ?? 0 )
			&& hash_equals( (string) ( $failed_state['archive_hash'] ?? '' ), $archive_hash )
		) {
			return;
		}

		$token = self::acquire_option_lock( self::SCHEMA_SYNC_LOCK_OPTION, 5 * MINUTE_IN_SECONDS );
		if ( '' === $token ) {
			return;
		}

		try {
			self::$schema_sync_errors = [];
			if ( self::sync_groups( $definitions ) && '' !== $archive_hash ) {
				update_option(
					self::ARCHIVE_HASH_OPTION,
					[
						'version'      => self::SCHEMA_SYNC_VERSION,
						'archive_hash' => $archive_hash,
					],
					false
				);
				delete_option( self::SCHEMA_SYNC_ERROR_OPTION );
			} else {
				if ( empty( self::$schema_sync_errors ) ) {
					self::$schema_sync_errors['schema'] = 'SCF schema synchronization did not complete.';
				}
				update_option(
					self::SCHEMA_SYNC_ERROR_OPTION,
					[
						'version'      => self::SCHEMA_SYNC_VERSION,
						'archive_hash' => $archive_hash,
						'errors'       => self::$schema_sync_errors,
						'timestamp'    => time(),
					],
					false
				);
			}
		} finally {
			self::release_option_lock( self::SCHEMA_SYNC_LOCK_OPTION, $token );
		}
	}

	/** Calculate a deterministic content hash for the known archive files. */
	private static function archive_hash( array $cpts ): string {
		$plugin_dir = defined( 'WORLDGRAPH_PLUGIN_DIR' ) ? WORLDGRAPH_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';
		sort( $cpts );
		$context = hash_init( 'sha256' );
		foreach ( $cpts as $cpt ) {
			$file = $plugin_dir . 'acf-json/' . self::group_key( (string) $cpt ) . '.json';
			hash_update( $context, (string) $cpt . "\0" );
			hash_update( $context, is_readable( $file ) ? (string) file_get_contents( $file ) : 'missing' );
		}

		return hash_final( $context );
	}

	/** Acquire a non-autoloaded option lock, replacing an expired lock atomically. */
	private static function acquire_option_lock( string $option_name, int $ttl ): string {
		$token = wp_generate_uuid4();
		$lock  = [ 'token' => $token, 'created' => time() ];
		if ( add_option( $option_name, $lock, '', false ) ) {
			return $token;
		}

		$current = get_option( $option_name, [] );
		if ( is_array( $current ) && time() - (int) ( $current['created'] ?? 0 ) <= $ttl ) {
			return '';
		}

		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $lock ),
				$option_name,
				maybe_serialize( $current )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- compare-and-swap is required for lock ownership.
		wp_cache_delete( $option_name, 'options' );

		return 1 === $updated ? $token : '';
	}

	/** Release an option lock only when this request still owns its exact value. */
	private static function release_option_lock( string $option_name, string $token ): void {
		$current = get_option( $option_name, [] );
		if ( ! is_array( $current ) || $token !== (string) ( $current['token'] ?? '' ) ) {
			return;
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option_name,
				maybe_serialize( $current )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- conditional delete prevents releasing a successor's lock.
		wp_cache_delete( $option_name, 'options' );
	}

	/** Cheaply confirm every DB group still satisfies its canonical storage contract. */
	private static function database_groups_have_canonical_fields( array $definitions ): bool {
		if ( ! function_exists( 'acf_get_raw_field_group' ) ) {
			return false;
		}

		foreach ( $definitions as $cpt => $fields ) {
			$group = acf_get_raw_field_group( self::group_key( (string) $cpt ) );
			if ( ! $group || empty( $group['ID'] ) ) {
				return false;
			}

			$expected_group = self::enforce_canonical_group_contract(
				[
					'key' => self::group_key( (string) $cpt ),
				]
			);
			foreach ( [ 'active', 'location', 'show_in_rest' ] as $property ) {
				if ( $expected_group[ $property ] !== ( $group[ $property ] ?? null ) ) {
					return false;
				}
			}

			$database_fields = array_column( (array) acf_get_raw_fields( (int) $group['ID'] ), null, 'key' );
			foreach ( (array) $fields as $field_name => $definition ) {
				$expected = self::to_scf_field( (string) $cpt, (string) $field_name, (array) $definition );
				$actual   = $database_fields[ $expected['key'] ] ?? false;
				if ( ! $actual || ! self::database_field_has_canonical_tree( $expected, $actual ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/** Verify a canonical field, its storage settings, and every nested child. */
	private static function database_field_has_canonical_tree( array $expected, array $actual ): bool {
		if (
			(string) ( $expected['key'] ?? '' ) !== (string) ( $actual['key'] ?? '' )
			|| (string) ( $expected['name'] ?? '' ) !== (string) ( $actual['name'] ?? '' )
			|| (string) ( $expected['type'] ?? '' ) !== (string) ( $actual['type'] ?? '' )
			|| ! empty( $expected['required'] ) !== ! empty( $actual['required'] )
		) {
			return false;
		}

		foreach ( self::canonical_storage_properties() as $property ) {
			if ( array_key_exists( $property, $expected ) && $expected[ $property ] !== ( $actual[ $property ] ?? null ) ) {
				return false;
			}
		}
		if ( 'select' === (string) ( $expected['type'] ?? '' ) ) {
			$cpt = self::cpt_from_field_key( (string) ( $expected['key'] ?? '' ) );
			if (
				! self::is_dynamic_choice_field( $cpt, (string) ( $expected['name'] ?? '' ) )
				&& ! empty( array_diff( array_keys( (array) ( $expected['choices'] ?? [] ) ), array_keys( (array) ( $actual['choices'] ?? [] ) ) ) )
			) {
				return false;
			}
		}

		$expected_children = (array) ( $expected['sub_fields'] ?? [] );
		if ( empty( $expected_children ) ) {
			return true;
		}
		if ( empty( $actual['ID'] ) ) {
			return false;
		}

		$actual_children = array_column( (array) acf_get_raw_fields( (int) $actual['ID'] ), null, 'key' );
		foreach ( $expected_children as $expected_child ) {
			$actual_child = $actual_children[ $expected_child['key'] ] ?? false;
			if ( ! $actual_child || ! self::database_field_has_canonical_tree( $expected_child, $actual_child ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Synchronize Local JSON groups to editable SCF database records.
	 *
	 * The committed JSON files are the portable archive. Database copies provide
	 * SCF's normal editing UI; when an administrator saves a World Graph Studio group, SCF
	 * writes it back to the plugin JSON directory through the save-path filter.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $definitions Code-defined fields.
	 */
	private static function sync_groups( array $definitions ): bool {
		$success = true;
		foreach ( $definitions as $cpt => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}

			$group = self::read_archive_group( $cpt );
			if ( ! $group ) {
				$success = false;
				self::record_schema_sync_error( self::group_key( $cpt ), 'The archive is missing, unreadable, or invalid JSON.' );
				continue;
			}

			$validation_error = self::validate_group_schema( $cpt, $fields, $group );
			if ( '' !== $validation_error ) {
				$success = false;
				self::record_schema_sync_error( self::group_key( $cpt ), 'The archive was not imported: ' . $validation_error );
				continue;
			}

			// acf_get_raw_field_group() bypasses Local JSON and checks the
			// database. The editable database copy remains runtime-authoritative.
			$db_group = function_exists( 'acf_get_raw_field_group' )
				? acf_get_raw_field_group( self::group_key( $cpt ) )
				: false;

			$import = $group;
			if ( $db_group && ! empty( $db_group['ID'] ) ) {
				$db_fields = self::read_raw_database_fields( (int) $db_group['ID'] );
				if ( is_wp_error( $db_fields ) ) {
					$success = false;
					self::record_schema_sync_error( self::group_key( $cpt ), 'Database fields could not be exported safely: ' . $db_fields->get_error_message() );
					continue;
				}
				$merged    = self::merge_database_managed_group( $cpt, $group, $db_group, $db_fields );
				if ( is_wp_error( $merged ) ) {
					$success = false;
					self::record_schema_sync_error( self::group_key( $cpt ), 'The archive/database merge failed: ' . $merged->get_error_message() );
					continue;
				}
				$import = $merged;
			}

			$merged_validation_error = self::validate_group_schema( $cpt, $fields, $import );
			if ( '' !== $merged_validation_error ) {
				$success = false;
				self::record_schema_sync_error( self::group_key( $cpt ), 'The merged group was not imported: ' . $merged_validation_error );
				continue;
			}

			$should_import = ! $db_group || self::group_schema_hash( $import ) !== self::database_group_schema_hash( $db_group );
			if ( $should_import ) {
				unset( $import['local'], $import['local_file'] );
				$import['ID'] = $db_group ? (int) $db_group['ID'] : 0;

				// Archive-to-database synchronization must not rewrite the source
				// JSON file or advance its timestamp during this same import.
				$local_json = function_exists( 'acf_get_instance' ) ? acf_get_instance( 'ACF_Local_JSON' ) : null;
				if ( $local_json ) {
					remove_action( 'acf/update_field_group', [ $local_json, 'update_field_group' ] );
				}

				$previous_schema_importing = self::$schema_importing;
				self::$schema_importing     = true;
				try {
					acf_import_field_group( $import );
				} finally {
					self::$schema_importing = $previous_schema_importing;
					if ( $local_json ) {
						add_action( 'acf/update_field_group', [ $local_json, 'update_field_group' ] );
					}
				}

				$verified_group = function_exists( 'acf_get_raw_field_group' )
					? acf_get_raw_field_group( self::group_key( $cpt ) )
					: false;
				if (
					! $verified_group
					|| self::database_group_is_incomplete( $cpt, $verified_group, $fields )
					|| self::group_schema_hash( $import ) !== self::database_group_schema_hash( $verified_group )
				) {
					$success = false;
					self::record_schema_sync_error( self::group_key( $cpt ), 'Database verification failed after import.' );
				}
			}
		}

		self::$field_cache      = [];
		self::$owned_field_keys = [];
		return $success;
	}

	/** Collect and log an actionable schema synchronization failure. */
	private static function record_schema_sync_error( string $group_key, string $message ): void {
		self::$schema_sync_errors[ $group_key ] = $message;
		worldgraph_log( 'SCF schema synchronization error for ' . $group_key . ': ' . $message );
	}

	/** Show durable archive/database synchronization failures to administrators. */
	public static function schema_sync_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state  = get_option( self::SCHEMA_SYNC_ERROR_OPTION, [] );
		$errors = is_array( $state ) ? (array) ( $state['errors'] ?? [] ) : [];
		if ( empty( $errors ) ) {
			return;
		}

		$retry_url = wp_nonce_url(
			add_query_arg( 'worldgraph_scf_retry_schema', '1', admin_url( 'index.php' ) ),
			'worldgraph_scf_retry_schema'
		);
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'World Graph Studio could not synchronize its SCF archive.', 'worldgraph' )
			. '</strong></p><ul>';
		foreach ( $errors as $group_key => $message ) {
			echo '<li><code>' . esc_html( (string) $group_key ) . '</code>: ' . esc_html( (string) $message ) . '</li>';
		}
		echo '</ul><p><a class="button" href="' . esc_url( $retry_url ) . '">'
			. esc_html__( 'Retry SCF synchronization', 'worldgraph' )
			. '</a></p></div>';
	}

	/**
	 * Merge a portable archive revision into an editable database group.
	 *
	 * Database-managed presentation settings and all site-added fields win when
	 * the same key exists. Canonical identifiers and persistence settings are
	 * then restored from World Graph Studio so an upgrade cannot break existing data. This
	 * is intentionally a merge rather than a whole-group replacement because
	 * SCF's importer deletes every database field absent from its input.
	 *
	 * @param string               $cpt          World Graph Studio CPT.
	 * @param array<string, mixed> $archive      Validated archive group.
	 * @param array<string, mixed> $database     Raw database group.
	 * @param array<int, array<string, mixed>> $database_fields Raw database fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function merge_database_managed_group( string $cpt, array $archive, array $database, array $database_fields ) {
		$fields = self::merge_schema_fields( $cpt, (array) ( $archive['fields'] ?? [] ), $database_fields );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		// Preserve every database-managed group setting first, then restore the
		// small canonical contract that determines ownership and exposure.
		$merged           = array_replace( $archive, $database );
		$merged['fields'] = $fields;
		$merged           = self::enforce_canonical_group_contract( $merged );
		unset( $merged['local'], $merged['local_file'], $merged['modified'] );

		return $merged;
	}

	/**
	 * Preserve SCF-managed extension fields during native same-key imports.
	 *
	 * SCF's importer treats an incoming field list as a full replacement and
	 * deletes every existing key that is absent. World Graph Studio imports instead use the
	 * same stable-key merge as plugin schema upgrades. Internal archive-to-DB
	 * synchronization already supplies its own verified merge and bypasses this
	 * filter through the schema-importing guard.
	 *
	 * @param array<string, mixed> $group Incoming SCF field group.
	 * @return array<string, mixed>
	 */
	public static function protect_external_field_group_import( array $group ): array {
		if ( self::$schema_importing ) {
			return $group;
		}

		$key = self::restore_trashed_schema_key( (string) ( $group['key'] ?? '' ) );
		$cpt = self::cpt_from_group_key( $key );
		if ( '' === $cpt || ! function_exists( 'acf_get_raw_field_group' ) ) {
			return $group;
		}

		$db_group = acf_get_raw_field_group( $key );
		if ( ! $db_group || empty( $db_group['ID'] ) ) {
			$validation_error = self::validate_group_schema( $cpt, worldgraph_get_field_defaults( $cpt ), $group );
			if ( '' === $validation_error ) {
				return self::prepare_group_fields_for_import( self::enforce_canonical_group_contract( $group ) );
			}

			self::persist_external_import_error( $key, 'A new World Graph Studio group import was blocked: ' . $validation_error );
			$archive = self::read_archive_group( $cpt );
			if ( $archive && '' === self::validate_group_schema( $cpt, worldgraph_get_field_defaults( $cpt ), $archive ) ) {
				unset( $archive['local'], $archive['local_file'], $archive['modified'] );
				return self::prepare_group_fields_for_import( $archive );
			}

			wp_die(
				esc_html__( 'World Graph Studio blocked this SCF import because neither the submitted group nor its plugin archive is safe to import.', 'worldgraph' ),
				esc_html__( 'World Graph Studio SCF import blocked', 'worldgraph' ),
				[ 'response' => 409, 'back_link' => true ]
			);
		}

		$db_fields = self::read_raw_database_fields( (int) $db_group['ID'] );
		if ( is_wp_error( $db_fields ) && function_exists( 'acf_prepare_fields_for_export' ) ) {
			$db_fields = acf_prepare_fields_for_export( (array) acf_get_fields( $db_group ) );
		}
		if ( is_wp_error( $db_fields ) || ! is_array( $db_fields ) ) {
			self::persist_external_import_error( $key, 'The existing database fields could not be shaped safely, so the import was blocked.' );
			wp_die(
				esc_html__( 'World Graph Studio blocked this SCF import because it could not safely preserve the existing custom fields.', 'worldgraph' ),
				esc_html__( 'World Graph Studio SCF import blocked', 'worldgraph' ),
				[ 'response' => 409, 'back_link' => true ]
			);
		}

		$merged = self::merge_database_managed_group( $cpt, $group, $db_group, $db_fields );
		if ( ! is_wp_error( $merged ) ) {
			$validation_error = self::validate_group_schema( $cpt, worldgraph_get_field_defaults( $cpt ), $merged );
			if ( '' === $validation_error ) {
				return self::prepare_group_fields_for_import( $merged );
			}
			$merged = new \WP_Error( 'worldgraph_scf_import_validation_failed', $validation_error );
		}

		$message = 'The native SCF import was reduced to a no-op: ' . $merged->get_error_message();
		self::persist_external_import_error( $key, $message );

		$safe           = self::enforce_canonical_group_contract( $db_group );
		$safe['fields'] = array_map( [ __CLASS__, 'clean_database_field' ], $db_fields );
		unset( $safe['local'], $safe['local_file'], $safe['modified'] );
		return self::prepare_group_fields_for_import( $safe );
	}

	/** Restore the top-level parent/order settings SCF adds before this filter. */
	private static function prepare_group_fields_for_import( array $group ): array {
		$fields = array_values( (array) ( $group['fields'] ?? [] ) );
		foreach ( $fields as $index => &$field ) {
			$field['parent']     = (string) ( $group['key'] ?? '' );
			$field['menu_order'] = $index;
		}
		unset( $field );
		$group['fields'] = $fields;

		return $group;
	}

	/** Persist a native-import failure for the existing administrator notice. */
	private static function persist_external_import_error( string $group_key, string $message ): void {
		$state  = get_option( self::SCHEMA_SYNC_ERROR_OPTION, [] );
		$errors = is_array( $state ) ? (array) ( $state['errors'] ?? [] ) : [];
		$errors[ 'import:' . $group_key ] = $message;
		update_option(
			self::SCHEMA_SYNC_ERROR_OPTION,
			[
				'version'      => self::SCHEMA_SYNC_VERSION,
				'archive_hash' => self::archive_hash( array_keys( worldgraph_get_all_field_defaults() ) ),
				'errors'       => $errors,
				'timestamp'    => time(),
			],
			false
		);
		worldgraph_log( 'SCF native import error for ' . $group_key . ': ' . $message );
	}

	/**
	 * Merge fields by stable key while retaining database-only extensions.
	 *
	 * Existing database order is retained and newly archived fields are appended.
	 * A duplicate sibling name is rejected instead of deleting or silently
	 * shadowing a site-managed field.
	 *
	 * @param string                               $cpt             World Graph Studio CPT.
	 * @param array<int, array<string, mixed>>     $archive_fields  Archive fields.
	 * @param array<int, array<string, mixed>>     $database_fields Raw DB fields.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function merge_schema_fields( string $cpt, array $archive_fields, array $database_fields ) {
		$archive_by_key = [];
		foreach ( $archive_fields as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' !== $key ) {
				$archive_by_key[ $key ] = $field;
			}
		}

		$merged    = [];
		$seen_keys = [];
		$seen_names = [];
		foreach ( $database_fields as $database_field ) {
			$key = (string) ( $database_field['key'] ?? '' );
			if ( '' === $key ) {
				return new \WP_Error( 'worldgraph_scf_missing_field_key', __( 'An SCF field is missing its stable key.', 'worldgraph' ) );
			}

			if ( isset( $archive_by_key[ $key ] ) ) {
				$canonical = self::canonical_field_by_key( $cpt, $key );
				if ( $canonical && (
					(string) ( $canonical['name'] ?? '' ) !== (string) ( $database_field['name'] ?? '' )
					|| (string) ( $canonical['type'] ?? '' ) !== (string) ( $database_field['type'] ?? '' )
				) ) {
					return new \WP_Error(
						'worldgraph_scf_canonical_key_collision',
						sprintf(
							/* translators: %s: stable SCF field key. */
							__( 'Database field key "%s" is already used by an incompatible field.', 'worldgraph' ),
							$key
						)
					);
				}

				$field = self::merge_schema_field( $cpt, $archive_by_key[ $key ], $database_field );
				if ( is_wp_error( $field ) ) {
					return $field;
				}
				$seen_keys[ $key ] = true;
			} else {
				$field = self::clean_database_field( $database_field );
			}

			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name || isset( $seen_names[ $name ] ) ) {
				return new \WP_Error(
					'worldgraph_scf_duplicate_field_name',
					sprintf(
						/* translators: %s: SCF field name. */
						__( 'SCF sibling field name "%s" is empty or duplicated.', 'worldgraph' ),
						$name
					)
				);
			}
			$seen_names[ $name ] = true;
			$merged[]            = $field;
		}

		foreach ( $archive_fields as $archive_field ) {
			$key = (string) ( $archive_field['key'] ?? '' );
			if ( isset( $seen_keys[ $key ] ) ) {
				continue;
			}

			$field = self::clean_database_field( $archive_field );
			$name  = (string) ( $field['name'] ?? '' );
			if ( '' === $key || '' === $name || isset( $seen_names[ $name ] ) ) {
				return new \WP_Error(
					'worldgraph_scf_archive_field_collision',
					sprintf(
						/* translators: %s: archived SCF field name. */
						__( 'Archived SCF field "%s" conflicts with a database-managed field.', 'worldgraph' ),
						$name
					)
				);
			}
			$seen_names[ $name ] = true;
			$seen_keys[ $key ]   = true;
			$merged[]            = $field;
		}

		return $merged;
	}

	/**
	 * Merge one same-key archive/database field and its nested fields.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function merge_schema_field( string $cpt, array $archive, array $database ) {
		$archive_subfields  = (array) ( $archive['sub_fields'] ?? [] );
		$database_subfields = (array) ( $database['sub_fields'] ?? [] );
		$merged             = array_replace( $archive, self::clean_database_field( $database ) );

		if ( ! empty( $archive_subfields ) || ! empty( $database_subfields ) ) {
			$subfields = self::merge_schema_fields( $cpt, $archive_subfields, $database_subfields );
			if ( is_wp_error( $subfields ) ) {
				return $subfields;
			}
			$merged['sub_fields'] = $subfields;
		}
		if ( isset( $archive['layouts'] ) || isset( $database['layouts'] ) ) {
			$layouts = self::merge_flexible_layouts(
				$cpt,
				(array) ( $archive['layouts'] ?? [] ),
				(array) ( $database['layouts'] ?? [] )
			);
			if ( is_wp_error( $layouts ) ) {
				return $layouts;
			}
			$merged['layouts'] = $layouts;
		}

		$expected = self::canonical_field_by_key( $cpt, (string) ( $merged['key'] ?? '' ) );
		if ( $expected ) {
			foreach ( array_merge( [ 'key', 'name', 'type', 'required' ], self::canonical_storage_properties() ) as $property ) {
				if ( array_key_exists( $property, $expected ) ) {
					$merged[ $property ] = $expected[ $property ];
				}
			}

			if ( isset( $archive['choices'] ) || isset( $database['choices'] ) ) {
				$merged['choices'] = array_replace( (array) ( $archive['choices'] ?? [] ), (array) ( $database['choices'] ?? [] ) );
			}
		}

		return self::clean_database_field( $merged );
	}

	/**
	 * Merge Flexible Content layouts by stable layout key.
	 *
	 * Database layout order and settings are retained, DB-only layouts survive,
	 * and newly archived layouts/subfields are appended.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function merge_flexible_layouts( string $cpt, array $archive_layouts, array $database_layouts ) {
		$archive_by_key = [];
		foreach ( $archive_layouts as $layout ) {
			$key = (string) ( $layout['key'] ?? '' );
			if ( '' === $key || isset( $archive_by_key[ $key ] ) ) {
				return new \WP_Error( 'worldgraph_scf_duplicate_layout_key', __( 'Flexible Content layout keys must be nonempty and unique.', 'worldgraph' ) );
			}
			$archive_by_key[ $key ] = $layout;
		}

		$merged     = [];
		$seen_keys  = [];
		$seen_names = [];
		foreach ( $database_layouts as $database_layout ) {
			$key = (string) ( $database_layout['key'] ?? '' );
			if ( '' === $key || isset( $seen_keys[ $key ] ) ) {
				return new \WP_Error( 'worldgraph_scf_duplicate_database_layout_key', __( 'Database Flexible Content layout keys must be nonempty and unique.', 'worldgraph' ) );
			}

			if ( isset( $archive_by_key[ $key ] ) ) {
				$archive_layout  = $archive_by_key[ $key ];
				$layout         = array_replace( $archive_layout, $database_layout );
				$layout_fields  = self::merge_schema_fields(
					$cpt,
					(array) ( $archive_layout['sub_fields'] ?? [] ),
					(array) ( $database_layout['sub_fields'] ?? [] )
				);
				if ( is_wp_error( $layout_fields ) ) {
					return $layout_fields;
				}
				$layout['sub_fields'] = $layout_fields;
				$seen_keys[ $key ]     = true;
			} else {
				$layout = $database_layout;
			}

			$name = (string) ( $layout['name'] ?? '' );
			if ( '' === $name || isset( $seen_names[ $name ] ) ) {
				return new \WP_Error( 'worldgraph_scf_duplicate_layout_name', __( 'Flexible Content layout names must be nonempty and unique.', 'worldgraph' ) );
			}
			$seen_names[ $name ] = true;
			$seen_keys[ $key ]   = true;
			$merged[]            = $layout;
		}

		foreach ( $archive_layouts as $archive_layout ) {
			$key  = (string) ( $archive_layout['key'] ?? '' );
			$name = (string) ( $archive_layout['name'] ?? '' );
			if ( isset( $seen_keys[ $key ] ) ) {
				continue;
			}
			if ( '' === $name || isset( $seen_names[ $name ] ) ) {
				return new \WP_Error( 'worldgraph_scf_archive_layout_collision', __( 'An archived Flexible Content layout conflicts with a database-managed layout.', 'worldgraph' ) );
			}
			$seen_names[ $name ] = true;
			$seen_keys[ $key ]   = true;
			$merged[]            = $archive_layout;
		}

		return $merged;
	}

	/** Remove database-only runtime identifiers before passing a field to SCF's importer. */
	private static function clean_database_field( array $field ): array {
		foreach ( [ 'ID', 'parent', 'prefix', 'value', '_name', '_valid', '_prepare' ] as $property ) {
			unset( $field[ $property ] );
		}

		if ( isset( $field['sub_fields'] ) ) {
			$field['sub_fields'] = array_map( [ __CLASS__, 'clean_database_field' ], (array) $field['sub_fields'] );
		}
		if ( isset( $field['layouts'] ) ) {
			foreach ( $field['layouts'] as &$layout ) {
				$layout['sub_fields'] = array_map( [ __CLASS__, 'clean_database_field' ], (array) ( $layout['sub_fields'] ?? [] ) );
			}
			unset( $layout );
		}

		return $field;
	}

	/**
	 * Decode an immutable Local JSON payload without applying runtime
	 * `acf/load_field` filters to the schema that will be imported.
	 *
	 * @return array<string, mixed>|false
	 */
	private static function read_archive_group( string $cpt ) {
		$plugin_dir = defined( 'WORLDGRAPH_PLUGIN_DIR' ) ? WORLDGRAPH_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';
		$file       = $plugin_dir . 'acf-json/' . self::group_key( $cpt ) . '.json';
		if ( ! is_readable( $file ) ) {
			return false;
		}

		$group = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $group ) || JSON_ERROR_NONE !== json_last_error() ) {
			worldgraph_log( 'Invalid SCF Local JSON archive: ' . $file );
			return false;
		}

		$group['local']      = 'json';
		$group['local_file'] = $file;
		return $group;
	}

	/**
	 * Validate the canonical, storage-sensitive portion of a World Graph Studio group.
	 * Administrators may add fields and presentation settings, but canonical
	 * names, keys, types, locations, and persistence contracts remain stable.
	 *
	 * @param string                                  $cpt         World Graph Studio CPT.
	 * @param array<string, array<string, mixed>>      $definitions Canonical fields.
	 * @param array<string, mixed>                     $group       SCF group.
	 * @return string Empty when valid, otherwise a diagnostic.
	 */
	private static function validate_group_schema( string $cpt, array $definitions, array $group ): string {
		if ( self::group_key( $cpt ) !== (string) ( $group['key'] ?? '' ) ) {
			return 'the stable group key does not match its CPT';
		}

		$expected_location = [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => $cpt ] ] ];
		if ( $expected_location !== (array) ( $group['location'] ?? [] ) ) {
			return 'the field group must be located only on ' . $cpt;
		}
		if ( empty( $group['active'] ) ) {
			return 'the canonical field group must remain active';
		}
		$expected_rest_visibility = 'worldgraph_conn' === $cpt ? 0 : 1;
		if ( $expected_rest_visibility !== (int) ( $group['show_in_rest'] ?? 0 ) ) {
			return 'the field group has incompatible native REST visibility';
		}

		$fields = (array) ( $group['fields'] ?? [] );
		if ( empty( $fields ) ) {
			return 'the field list is empty';
		}

		$names = array_map( 'strval', array_column( $fields, 'name' ) );
		if ( in_array( '', $names, true ) || count( $names ) !== count( array_unique( $names ) ) ) {
			return 'top-level field names must be nonempty and unique';
		}

		$seen_keys = [];
		$key_error = self::validate_unique_field_keys( $fields, $seen_keys );
		if ( '' !== $key_error ) {
			return $key_error;
		}

		$by_name = array_column( $fields, null, 'name' );
		foreach ( $definitions as $field_name => $definition ) {
			if ( empty( $by_name[ $field_name ] ) || ! is_array( $by_name[ $field_name ] ) ) {
				return 'missing canonical field ' . $field_name;
			}

			$field    = $by_name[ $field_name ];
			$expected = self::to_scf_field( $cpt, $field_name, $definition );
			if ( (string) $expected['key'] !== (string) ( $field['key'] ?? '' ) ) {
				return 'field ' . $field_name . ' does not use its stable key';
			}
			if ( (string) $expected['type'] !== (string) ( $field['type'] ?? '' ) ) {
				return 'field ' . $field_name . ' has an incompatible SCF type';
			}
			if ( ! empty( $expected['required'] ) !== ! empty( $field['required'] ) ) {
				return 'field ' . $field_name . ' has incompatible required-state behavior';
			}

			if ( 'taxonomy' === (string) $expected['type'] && (
				(string) ( $expected['taxonomy'] ?? '' ) !== (string) ( $field['taxonomy'] ?? '' )
				|| empty( $field['load_terms'] )
				|| empty( $field['save_terms'] )
				|| (string) ( $expected['field_type'] ?? '' ) !== (string) ( $field['field_type'] ?? '' )
				|| ! empty( $expected['multiple'] ) !== ! empty( $field['multiple'] )
				|| (string) ( $expected['return_format'] ?? '' ) !== (string) ( $field['return_format'] ?? '' )
			) ) {
				return 'taxonomy field ' . $field_name . ' is not synchronized with WordPress terms';
			}

			if ( in_array( (string) $expected['type'], [ 'post_object', 'relationship' ], true ) ) {
				$expected_types = array_values( array_filter( (array) ( $expected['post_type'] ?? [] ) ) );
				$actual_types   = array_values( array_filter( (array) ( $field['post_type'] ?? [] ) ) );
				if ( $expected_types !== $actual_types || (string) ( $expected['return_format'] ?? '' ) !== (string) ( $field['return_format'] ?? '' ) ) {
					return 'relationship field ' . $field_name . ' targets an incompatible CPT';
				}
			}

			if ( 'date_picker' === (string) $expected['type'] && (
				(string) ( $expected['display_format'] ?? '' ) !== (string) ( $field['display_format'] ?? '' )
				|| (string) ( $expected['return_format'] ?? '' ) !== (string) ( $field['return_format'] ?? '' )
			) ) {
				return 'date field ' . $field_name . ' has an incompatible format';
			}

			$dynamic_choice_field = self::is_dynamic_choice_field( $cpt, $field_name );
			if ( 'select' === (string) $expected['type'] && ! $dynamic_choice_field ) {
				$expected_choices = array_keys( (array) ( $expected['choices'] ?? [] ) );
				$actual_choices   = array_keys( (array) ( $field['choices'] ?? [] ) );
				if ( ! empty( array_diff( $expected_choices, $actual_choices ) ) ) {
					return 'select field ' . $field_name . ' is missing canonical choices';
				}
			}

			if ( 'repeater' === (string) $expected['type'] ) {
				$expected_subfields = array_column( (array) ( $expected['sub_fields'] ?? [] ), null, 'name' );
				$actual_subfields   = array_column( (array) ( $field['sub_fields'] ?? [] ), null, 'name' );
				foreach ( $expected_subfields as $sub_name => $expected_subfield ) {
					$actual_subfield = $actual_subfields[ $sub_name ] ?? [];
					if ( (string) ( $expected_subfield['key'] ?? '' ) !== (string) ( $actual_subfield['key'] ?? '' ) || (string) ( $expected_subfield['type'] ?? '' ) !== (string) ( $actual_subfield['type'] ?? '' ) ) {
						return 'repeater field ' . $field_name . ' has an incompatible ' . $sub_name . ' subfield';
					}
				}
			}
		}

		return '';
	}

	/** Validate recursive field-key uniqueness. */
	private static function validate_unique_field_keys( array $fields, array &$seen_keys ): string {
		foreach ( $fields as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key ) {
				return 'every field and subfield must have a nonempty key';
			}
			if ( isset( $seen_keys[ $key ] ) ) {
				return 'duplicate field key ' . $key;
			}
			$seen_keys[ $key ] = true;

			$error = self::validate_unique_field_keys( (array) ( $field['sub_fields'] ?? [] ), $seen_keys );
			if ( '' !== $error ) {
				return $error;
			}

			foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
				$error = self::validate_unique_field_keys( (array) ( $layout['sub_fields'] ?? [] ), $seen_keys );
				if ( '' !== $error ) {
					return $error;
				}
			}
		}

		return '';
	}

	/**
	 * Keep graph/storage identifiers immutable while allowing administrators to
	 * manage labels, instructions, layouts, choices, and extension fields in SCF.
	 *
	 * @param array<string, mixed> $field Field being saved by SCF.
	 * @return array<string, mixed>
	 */
	public static function enforce_canonical_field_contract( array $field ): array {
		$key = (string) ( $field['key'] ?? '' );
		if ( ! empty( $field['ID'] ) ) {
			$existing_post = get_post( (int) $field['ID'] );
			if ( $existing_post && 'acf-field' === $existing_post->post_type ) {
				if ( ! self::$schema_importing ) {
					self::queue_archive_parent_id( (int) $existing_post->post_parent );
				}
				$existing_key = (string) $existing_post->post_name;
				$existing_field = function_exists( 'acf_get_raw_field' ) ? acf_get_raw_field( (int) $existing_post->ID ) : false;
				if (
					$existing_field
					&& self::field_post_belongs_to_canonical_group( $existing_post )
					&& in_array( (string) ( $existing_field['type'] ?? '' ), [ 'post_object', 'relationship' ], true )
				) {
					// Story Graph slots currently use the field name as their durable
					// identity. Keep extension relationship names stable while leaving
					// their labels and presentation settings editable.
					$field['key']  = self::restore_trashed_schema_key( $existing_key );
					$field['name'] = (string) $existing_post->post_excerpt;
					$key           = (string) $field['key'];
				}
				$existing_cpt = self::cpt_from_field_key( $existing_key );
				if ( '' !== $existing_cpt && self::canonical_field_by_key( $existing_cpt, $existing_key ) ) {
					$key = $existing_key;
				}
			}
		}

		$cpt = self::cpt_from_field_key( $key );
		if ( '' === $cpt ) {
			return $field;
		}

		$expected = self::canonical_field_by_key( $cpt, $key );
		if ( ! $expected ) {
			return $field;
		}

		foreach ( [ 'key', 'name', 'type', 'required' ] as $property ) {
			$field[ $property ] = $expected[ $property ];
		}

		foreach ( self::canonical_storage_properties() as $property ) {
			if ( array_key_exists( $property, $expected ) ) {
				$field[ $property ] = $expected[ $property ];
			}
		}
		if ( 'select' === (string) ( $expected['type'] ?? '' ) && ! self::is_dynamic_choice_field( $cpt, (string) $expected['name'] ) ) {
			$field['choices'] = array_replace( (array) ( $expected['choices'] ?? [] ), (array) ( $field['choices'] ?? [] ) );
		}

		$parent = false;
		if ( ! empty( $expected['parent'] ) && function_exists( 'acf_get_raw_field' ) ) {
			$parent = acf_get_raw_field( (string) $expected['parent'] );
		} elseif ( function_exists( 'acf_get_raw_field_group' ) ) {
			$parent = acf_get_raw_field_group( self::group_key( $cpt ) );
		}
		if ( $parent && ! empty( $parent['ID'] ) ) {
			$field['parent'] = (int) $parent['ID'];
		}

		return $field;
	}

	/** Settings whose drift would change canonical storage or graph behavior. */
	private static function canonical_storage_properties(): array {
		return [
			'taxonomy',
			'field_type',
			'multiple',
			'load_terms',
			'save_terms',
			'return_format',
			'post_type',
			'display_format',
		];
	}

	/** Keep each canonical group active and located only on its World Graph Studio CPT. */
	public static function enforce_canonical_group_contract( array $group ): array {
		$key = (string) ( $group['key'] ?? '' );
		if ( ! empty( $group['ID'] ) ) {
			$existing_post = get_post( (int) $group['ID'] );
			if ( $existing_post && 'acf-field-group' === $existing_post->post_type ) {
				$existing_key = self::restore_trashed_schema_key( (string) $existing_post->post_name );
				if ( '' !== self::cpt_from_group_key( $existing_key ) ) {
					$key = $existing_key;
				}
			}
		}
		$cpt = self::cpt_from_group_key( $key );
		if ( '' === $cpt ) {
			return $group;
		}

		$group['key']      = self::group_key( $cpt );
		$group['active']   = true;
		$group['location'] = [
			[
				[
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => $cpt,
				],
			],
		];
		$group['show_in_rest'] = 'worldgraph_conn' === $cpt ? 0 : 1;
		return $group;
	}

	/** Whether a canonical select receives its choices from a runtime registry. */
	private static function is_dynamic_choice_field( string $cpt, string $field_name ): bool {
		return in_array(
			$cpt . '.' . $field_name,
			[ 'worldgraph_conn.provider_type', 'worldgraph_template.modality', 'worldgraph_template.model_family' ],
			true
		);
	}

	/** Prevent deletion/trashing of canonical groups and fields. */
	public static function protect_canonical_schema_post( $delete, $post, $force = false ) {
		$post = $post instanceof \WP_Post ? $post : get_post( $post );
		if ( ! $post ) {
			return $delete;
		}

		if ( 'acf-field-group' === $post->post_type && '' !== self::cpt_from_group_key( (string) $post->post_name ) ) {
			return false;
		}

		if ( 'acf-field' === $post->post_type ) {
			if ( self::is_canonical_group_delete_context() && self::field_post_belongs_to_canonical_group( $post ) ) {
				return false;
			}

			$cpt = self::cpt_from_field_key( (string) $post->post_name );
			if ( '' !== $cpt && self::canonical_field_by_key( $cpt, (string) $post->post_name ) ) {
				return false;
			}
		}

		return $delete;
	}

	/** Detect SCF's direct group API, which otherwise deletes children before the protected group. */
	private static function is_canonical_group_delete_context(): bool {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) { // phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue,WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- bounded stack is required to identify SCF's destructive call path.
			if (
				'ACF_Field_Group' === (string) ( $frame['class'] ?? '' )
				&& in_array( (string) ( $frame['function'] ?? '' ), [ 'delete_post', 'trash_post' ], true )
			) {
				return true;
			}
		}

		return false;
	}

	/** Whether an SCF field post is nested anywhere inside a canonical group. */
	private static function field_post_belongs_to_canonical_group( \WP_Post $field_post ): bool {
		$parent_id = (int) $field_post->post_parent;
		$visited   = [];
		while ( $parent_id && ! isset( $visited[ $parent_id ] ) ) {
			$visited[ $parent_id ] = true;
			$parent = get_post( $parent_id );
			if ( ! $parent ) {
				return false;
			}
			if ( 'acf-field-group' === $parent->post_type ) {
				return '' !== self::cpt_from_group_key( (string) $parent->post_name );
			}
			if ( 'acf-field' !== $parent->post_type ) {
				return false;
			}
			$parent_id = (int) $parent->post_parent;
		}

		return false;
	}

	/** Remove canonical field IDs from SCF's pending deletion list before save. */
	public static function protect_submitted_canonical_deletions( int $post_id, array $data ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'acf-field-group' !== $post->post_type || '' === self::cpt_from_group_key( (string) $post->post_name ) ) {
			return;
		}

		if ( empty( $_POST['_acf_delete_fields'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- SCF verifies the enclosing field-group save.
			return;
		}

		$remaining = [];
		$ids       = array_map( 'absint', explode( '|', (string) wp_unslash( $_POST['_acf_delete_fields'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- integer-normalized below.
		foreach ( $ids as $field_id ) {
			$field_post = get_post( $field_id );
			if ( ! $field_post || 'acf-field' !== $field_post->post_type ) {
				continue;
			}

			$cpt = self::cpt_from_field_key( (string) $field_post->post_name );
			if ( '' === $cpt || ! self::canonical_field_by_key( $cpt, (string) $field_post->post_name ) ) {
				$remaining[] = $field_id;
			}
		}

		$_POST['_acf_delete_fields'] = implode( '|', $remaining ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only narrows SCF's validated deletion request.
	}

	/** Prevent SCF's delete/trash follow-up hooks from removing canonical archives. */
	public static function protect_canonical_archive_file( string $file ): string {
		$normalized = wp_normalize_path( $file );
		$plugin_dir = defined( 'WORLDGRAPH_PLUGIN_DIR' ) ? WORLDGRAPH_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';
		foreach ( array_keys( worldgraph_get_all_cpts() ) as $cpt ) {
			$archive = wp_normalize_path( $plugin_dir . 'acf-json/' . self::group_key( (string) $cpt ) . '.json' );
			if ( $normalized === $archive ) {
				return '';
			}
		}

		return $file;
	}

	/** Queue an owning World Graph Studio group after standalone SCF field CRUD. */
	public static function queue_field_archive_refresh( array $field ): void {
		if ( self::$schema_importing ) {
			return;
		}

		self::queue_archive_parent_id( absint( $field['parent'] ?? 0 ) );
	}

	/** Resolve a field parent chain and queue its canonical World Graph Studio group. */
	private static function queue_archive_parent_id( int $parent_id ): void {
		if ( $parent_id && function_exists( 'acf_cache_key' ) ) {
			// SCF flushes only the new parent's child list when a field moves.
			// Invalidate the old parent too so the archive cannot retain the field.
			wp_cache_delete( acf_cache_key( 'acf_get_field_posts:' . $parent_id ), 'secure-custom-fields' );
		}

		$visited   = [];
		while ( $parent_id && ! isset( $visited[ $parent_id ] ) ) {
			$visited[ $parent_id ] = true;
			$parent = get_post( $parent_id );
			if ( ! $parent ) {
				return;
			}

			if ( 'acf-field-group' === $parent->post_type ) {
				if ( '' !== self::cpt_from_group_key( (string) $parent->post_name ) ) {
					self::$pending_archive_group_ids[ $parent_id ] = true;
				}
				return;
			}

			if ( 'acf-field' !== $parent->post_type ) {
				return;
			}
			$parent_id = (int) $parent->post_parent;
		}
	}

	/** A normal SCF group save already refreshed JSON, so cancel its pending write. */
	public static function clear_pending_archive_refresh( array $group ): void {
		if ( ! empty( $group['ID'] ) ) {
			unset( self::$pending_archive_group_ids[ (int) $group['ID'] ] );
		}
	}

	/** Refresh each changed World Graph Studio group once after standalone field operations. */
	public static function flush_pending_archive_refreshes(): void {
		if ( self::$schema_importing || empty( self::$pending_archive_group_ids ) ) {
			return;
		}

		$group_ids = array_keys( self::$pending_archive_group_ids );
		self::$pending_archive_group_ids = [];
		foreach ( $group_ids as $group_id ) {
			$group = function_exists( 'acf_get_raw_field_group' ) ? acf_get_raw_field_group( (int) $group_id ) : false;
			if ( $group && '' !== self::cpt_from_group_key( (string) ( $group['key'] ?? '' ) ) ) {
				do_action( 'acf/update_field_group', $group );
			}
		}
	}

	/** Resolve a canonical top-level field or repeater subfield by stable key. */
	private static function canonical_field_by_key( string $cpt, string $key ) {
		$key = self::restore_trashed_schema_key( $key );
		foreach ( worldgraph_get_field_defaults( $cpt ) as $field_name => $definition ) {
			$expected = self::to_scf_field( $cpt, $field_name, $definition );
			$match    = self::find_field_by_key( [ $expected ], $key );
			if ( $match ) {
				return $match;
			}
		}

		return false;
	}

	/** Resolve the exact canonical CPT represented by a group key. */
	private static function cpt_from_group_key( string $group_key ): string {
		$group_key = self::restore_trashed_schema_key( $group_key );
		foreach ( array_keys( worldgraph_get_all_cpts() ) as $cpt ) {
			if ( self::group_key( $cpt ) === $group_key ) {
				return $cpt;
			}
		}

		return '';
	}

	/** Undo WordPress's temporary slug suffix while a schema post is trashed. */
	private static function restore_trashed_schema_key( string $key ): string {
		return preg_replace( '/__trashed$/', '', $key ) ?: $key;
	}

	/** Recursively find a field in a generated canonical field tree. */
	private static function find_field_by_key( array $fields, string $key ) {
		foreach ( $fields as $field ) {
			if ( $key === (string) ( $field['key'] ?? '' ) ) {
				return $field;
			}

			$match = self::find_field_by_key( (array) ( $field['sub_fields'] ?? [] ), $key );
			if ( $match ) {
				return $match;
			}

			foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
				$match = self::find_field_by_key( (array) ( $layout['sub_fields'] ?? [] ), $key );
				if ( $match ) {
					return $match;
				}
			}
		}

		return false;
	}

	/**
	 * Whether an editable database group is missing canonical World Graph Studio fields.
	 *
	 * This also repairs database copies created by older World Graph Studio synchronizers
	 * that imported the field-less Local JSON group object.
	 *
	 * @param string                                  $cpt         World Graph Studio CPT.
	 * @param array<string, mixed>                    $db_group    Raw database group.
	 * @param array<string, array<string, mixed>>     $definitions Canonical fields.
	 */
	private static function database_group_is_incomplete( string $cpt, array $db_group, array $definitions ): bool {
		if ( empty( $db_group['ID'] ) || ! function_exists( 'acf_get_raw_fields' ) ) {
			return false;
		}

		$db_fields = self::read_raw_database_fields( (int) $db_group['ID'] );
		if ( is_wp_error( $db_fields ) ) {
			return true;
		}
		$db_group['fields'] = $db_fields;
		return '' !== self::validate_group_schema( $cpt, $definitions, $db_group );
	}

	/**
	 * Recursively load raw database fields in SCF's portable export shape.
	 *
	 * Reading raw post content avoids `acf/load_field` filters, while explicitly
	 * rebuilding nested Repeater, Group, and Flexible Content structures prevents
	 * the importer from treating their children as stale fields.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function read_raw_database_fields( int $parent_id ) {
		$fields = [];
		foreach ( (array) acf_get_raw_fields( $parent_id ) as $field ) {
			$shaped = self::shape_raw_database_field( $field );
			if ( is_wp_error( $shaped ) ) {
				return $shaped;
			}
			$fields[] = $shaped;
		}

		return $fields;
	}

	/**
	 * Rebuild one raw database field and all of its nested children.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function shape_raw_database_field( array $field ) {
		if ( empty( $field['ID'] ) ) {
			return $field;
		}

		$children = (array) acf_get_raw_fields( (int) $field['ID'] );
		if ( empty( $children ) ) {
			return $field;
		}

		$type = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, [ 'repeater', 'group' ], true ) ) {
			$field['sub_fields'] = [];
			foreach ( $children as $child ) {
				$shaped = self::shape_raw_database_field( $child );
				if ( is_wp_error( $shaped ) ) {
					return $shaped;
				}
				$field['sub_fields'][] = $shaped;
			}
			return $field;
		}

		if ( 'flexible_content' === $type ) {
			$layouts = array_values( (array) ( $field['layouts'] ?? [] ) );
			if ( empty( $layouts ) ) {
				return new \WP_Error( 'worldgraph_scf_flexible_layout_missing', __( 'A Flexible Content field has children but no layouts.', 'worldgraph' ) );
			}

			$layout_indexes = [];
			foreach ( $layouts as $index => &$layout ) {
				$layout['sub_fields'] = [];
				$layout_indexes[ (string) ( $layout['key'] ?? '' ) ] = $index;
			}
			unset( $layout );

			foreach ( $children as $child ) {
				$layout_key = (string) ( $child['parent_layout'] ?? '' );
				if ( '' === $layout_key ) {
					$layout_key = (string) ( $layouts[0]['key'] ?? '' );
				}
				if ( ! array_key_exists( $layout_key, $layout_indexes ) ) {
					return new \WP_Error( 'worldgraph_scf_flexible_layout_orphan', __( 'A Flexible Content subfield references an unknown layout.', 'worldgraph' ) );
				}

				$shaped = self::shape_raw_database_field( $child );
				if ( is_wp_error( $shaped ) ) {
					return $shaped;
				}
				unset( $shaped['parent_layout'] );
				$layouts[ $layout_indexes[ $layout_key ] ]['sub_fields'][] = $shaped;
			}

			$field['layouts'] = $layouts;
			return $field;
		}

		return new \WP_Error(
			'worldgraph_scf_unsupported_nested_field',
			sprintf(
				/* translators: %s: SCF field type. */
				__( 'Nested database fields under SCF type "%s" cannot be archived safely.', 'worldgraph' ),
				$type
			)
		);
	}

	/** Hash the portable, recursively meaningful portion of a field group. */
	private static function group_schema_hash( array $group ): string {
		$group_keys = [
			'key',
			'title',
			'location',
			'menu_order',
			'position',
			'style',
			'label_placement',
			'instruction_placement',
			'hide_on_screen',
			'active',
			'description',
			'show_in_rest',
		];
		$normalized = [];
		foreach ( $group_keys as $key ) {
			$normalized[ $key ] = $group[ $key ] ?? null;
		}
		$normalized['fields'] = self::normalize_schema_fields( (array) ( $group['fields'] ?? [] ) );

		return hash( 'sha256', (string) wp_json_encode( $normalized ) );
	}

	/** Hash one raw editable database group without Local JSON precedence. */
	private static function database_group_schema_hash( array $db_group ): string {
		$fields = ! empty( $db_group['ID'] ) ? self::read_raw_database_fields( (int) $db_group['ID'] ) : [];
		if ( is_wp_error( $fields ) ) {
			return '';
		}
		$db_group['fields'] = $fields;
		return self::group_schema_hash( $db_group );
	}

	/** Normalize field settings that affect SCF management and persistence. */
	private static function normalize_schema_fields( array $fields ): array {
		$field_keys = [
			'key',
			'label',
			'name',
			'type',
			'instructions',
			'required',
			'conditional_logic',
			'wrapper',
			'default_value',
			'choices',
			'allow_null',
			'multiple',
			'ui',
			'return_format',
			'taxonomy',
			'field_type',
			'add_term',
			'load_terms',
			'save_terms',
			'post_type',
			'filters',
			'min',
			'max',
			'role',
			'display_format',
			'first_day',
			'layout',
			'button_label',
		];

		$normalized = [];
		foreach ( array_values( $fields ) as $index => $field ) {
			$item = [];
			foreach ( $field_keys as $key ) {
				if ( array_key_exists( $key, $field ) ) {
					$item[ $key ] = $field[ $key ];
				}
			}

			// SCF expands omitted defaults when a JSON field is persisted. Treat
			// those generated values as equivalent to the compact archive form.
			// SCF's portable export drops menu_order and its importer derives order
			// from array position, so persisted numeric gaps are not semantic.
			$item['menu_order'] = $index;
			if ( array_key_exists( 'default_value', $item ) && in_array( $item['default_value'], [ '', false, null, [] ], true ) ) {
				unset( $item['default_value'] );
			}
			if ( array_key_exists( 'taxonomy', $item ) && in_array( $item['taxonomy'], [ '', null, [] ], true ) ) {
				unset( $item['taxonomy'] );
			}
			foreach ( [ 'min', 'max' ] as $empty_numeric_setting ) {
				if ( array_key_exists( $empty_numeric_setting, $item ) && in_array( $item[ $empty_numeric_setting ], [ '', null ], true ) ) {
					unset( $item[ $empty_numeric_setting ] );
				}
			}
			$item['sub_fields'] = self::normalize_schema_fields( (array) ( $field['sub_fields'] ?? [] ) );
			if ( isset( $field['layouts'] ) ) {
				$item['layouts'] = [];
				foreach ( (array) $field['layouts'] as $layout ) {
					$normalized_layout = [];
					foreach ( [ 'key', 'name', 'label', 'display', 'min', 'max' ] as $layout_key ) {
						if ( array_key_exists( $layout_key, $layout ) ) {
							$normalized_layout[ $layout_key ] = $layout[ $layout_key ];
						}
					}
					foreach ( [ 'min', 'max' ] as $layout_limit ) {
						if ( array_key_exists( $layout_limit, $normalized_layout ) && in_array( $normalized_layout[ $layout_limit ], [ '', null ], true ) ) {
							unset( $normalized_layout[ $layout_limit ] );
						}
					}
					$normalized_layout['sub_fields'] = self::normalize_schema_fields( (array) ( $layout['sub_fields'] ?? [] ) );
					$item['layouts'][]               = $normalized_layout;
				}
			}
			$normalized[]       = $item;
		}

		return $normalized;
	}

	/**
	 * Build one SCF field group located on a World Graph Studio CPT.
	 *
	 * @param string                                      $cpt    CPT slug.
	 * @param array<string, array<string, mixed>>          $fields World Graph Studio fields.
	 * @return array<string, mixed>
	 */
	private static function build_group( string $cpt, array $fields ): array {
		$labels = worldgraph_get_all_cpts();
		$title  = isset( $labels[ $cpt ] ) ? $labels[ $cpt ] : ucwords( str_replace( '_', ' ', $cpt ) );
		$scf_fields = [];
		$menu_order = 0;

		foreach ( $fields as $field_name => $field ) {
			$scf_fields[] = self::to_scf_field( $cpt, $field_name, $field, $menu_order++ );
		}

		return [
			'key'                   => self::group_key( $cpt ),
			'title'                 => sprintf( 'World Graph Studio: %s Fields', $title ),
			'fields'                => $scf_fields,
			'location'              => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => $cpt,
					],
				],
			],
			'menu_order'            => 0,
			'position'              => 'normal',
			'style'                 => 'default',
			'label_placement'       => 'top',
			'instruction_placement' => 'label',
			'hide_on_screen'        => '',
			'active'                => true,
			'description'           => 'Structured metadata for the World Graph Studio Story Graph. This persisted group may be managed in Secure Custom Fields.',
			'show_in_rest'          => 'worldgraph_conn' === $cpt ? 0 : 1,
		];
	}

	/**
	 * Convert one World Graph Studio field definition to SCF's field schema.
	 *
	 * @param string               $cpt        CPT slug.
	 * @param string               $field_name Field name.
	 * @param array<string, mixed> $field      World Graph Studio field definition.
	 * @param int                  $menu_order Field order.
	 * @return array<string, mixed>
	 */
	public static function to_scf_field( string $cpt, string $field_name, array $field, int $menu_order = 0 ): array {
		$type = (string) ( $field['type'] ?? 'text' );
		$key  = self::field_key( $cpt, $field_name );
		$scf  = [
			'key'               => $key,
			'label'             => (string) ( $field['label'] ?? ucwords( str_replace( '_', ' ', $field_name ) ) ),
			'name'              => $field_name,
			'aria-label'        => '',
			'type'              => $type,
			'instructions'      => (string) ( $field['description'] ?? '' ),
			'required'          => empty( $field['required'] ) ? 0 : 1,
			'conditional_logic' => 0,
			'wrapper'           => [
				'width' => '',
				'class' => '',
				'id'    => '',
			],
			'menu_order'        => $menu_order,
		];

		if ( array_key_exists( 'default', $field ) ) {
			$scf['default_value'] = $field['default'];
		}

		switch ( $type ) {
			case 'date':
				$scf['type']           = 'date_picker';
				$scf['display_format'] = 'Y-m-d';
				$scf['return_format']  = 'Y-m-d';
				$scf['first_day']      = 1;
				break;

			case 'select':
				$scf['choices']      = (array) ( $field['options'] ?? [] );
				$scf['allow_null']   = empty( $field['required'] ) ? 1 : 0;
				$scf['multiple']     = empty( $field['multiple'] ) ? 0 : 1;
				$scf['ui']           = 1;
				$scf['return_format'] = 'value';
				break;

			case 'taxonomy':
				$multiple             = ! empty( $field['multiple'] );
				$scf['taxonomy']      = (string) ( $field['taxonomy'] ?? 'category' );
				$scf['field_type']    = $multiple ? 'multi_select' : 'select';
				$scf['multiple']      = $multiple ? 1 : 0;
				$scf['allow_null']    = empty( $field['required'] ) ? 1 : 0;
				$scf['return_format'] = 'id';
				$scf['add_term']      = 1;
				$scf['load_terms']    = 1;
				$scf['save_terms']    = 1;
				break;

			case 'relationship':
				$multiple             = ! empty( $field['multiple'] );
				$scf['type']          = $multiple ? 'relationship' : 'post_object';
				$scf['post_type']     = [ (string) ( $field['related_cpt'] ?? '' ) ];
				$scf['return_format'] = 'id';
				if ( $multiple ) {
					$scf['filters'] = [ 'search' ];
					$scf['min']     = empty( $field['required'] ) ? 0 : 1;
					$scf['max']     = 0;
				} else {
					$scf['allow_null'] = empty( $field['required'] ) ? 1 : 0;
					$scf['multiple']   = 0;
					$scf['ui']         = 1;
				}

				$taxonomy_filters = self::relationship_taxonomy_filters( $field );
				if ( ! empty( $taxonomy_filters ) ) {
					$scf['taxonomy'] = $taxonomy_filters;
				}
				break;

			case 'user':
				$scf['role']          = '';
				$scf['multiple']      = empty( $field['multiple'] ) ? 0 : 1;
				$scf['allow_null']    = empty( $field['required'] ) ? 1 : 0;
				$scf['return_format'] = 'id';
				break;

			case 'structured':
				$scf['type']         = 'repeater';
				$scf['layout']       = 'row';
				$scf['button_label'] = 'Add Row';
				$scf['min']          = 0;
				$scf['max']          = 0;
				$scf['sub_fields']   = self::structured_sub_fields( $cpt, $key, $field );
				break;
		}

		return $scf;
	}

	/**
	 * Convert World Graph Studio relationship query args into SCF taxonomy filters.
	 *
	 * @param array<string, mixed> $field World Graph Studio field definition.
	 * @return array<int, string>
	 */
	private static function relationship_taxonomy_filters( array $field ): array {
		$filters = [];
		$queries = (array) ( $field['query_args']['tax_query'] ?? [] );
		foreach ( $queries as $query ) {
			if ( empty( $query['taxonomy'] ) || empty( $query['terms'] ) ) {
				continue;
			}

			foreach ( (array) $query['terms'] as $term ) {
				$filters[] = (string) $query['taxonomy'] . ':' . (string) $term;
			}
		}

		return $filters;
	}

	/**
	 * Build SCF repeater sub-fields for structured World Graph Studio metadata.
	 *
	 * @param string               $cpt       CPT slug.
	 * @param string               $parent_key Parent field key.
	 * @param array<string, mixed> $field     World Graph Studio field definition.
	 * @return array<int, array<string, mixed>>
	 */
	private static function structured_sub_fields( string $cpt, string $parent_key, array $field ): array {
		$sub_fields = $field['sub_fields'] ?? [
			'speaker'     => [ 'type' => 'text', 'label' => 'Speaker' ],
			'line'        => [ 'type' => 'textarea', 'label' => 'Line' ],
			'description' => [ 'type' => 'textarea', 'label' => 'Description' ],
			'sequence'    => [ 'type' => 'number', 'label' => 'Sequence' ],
		];

		$converted = [];
		$order     = 0;
		foreach ( (array) $sub_fields as $name => $config ) {
			$sub_field           = self::to_scf_field( $cpt, $field['name'] . '_' . $name, (array) $config, $order++ );
			$sub_field['name']   = $name;
			$sub_field['parent'] = $parent_key;
			$converted[]         = $sub_field;
		}

		return $converted;
	}

	/**
	 * Get SCF-managed fields for a CPT in World Graph Studio's field dialect.
	 *
	 * @param string                             $cpt      CPT slug.
	 * @param array<string, array<string, mixed>> $defaults Code-defined fields.
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_fields( string $cpt, array $defaults = [] ): array {
		if ( isset( self::$field_cache[ $cpt ] ) ) {
			return self::$field_cache[ $cpt ];
		}

		if ( ! self::is_available() ) {
			return $defaults;
		}

		$core_group = acf_get_field_group( self::group_key( $cpt ) );
		$groups     = $core_group ? [ $core_group ] : [];

		$fields = [];
		foreach ( $groups as $group ) {
			$scf_fields = acf_get_fields( $group );
			if ( ! is_array( $scf_fields ) ) {
				continue;
			}

			foreach ( $scf_fields as $scf_field ) {
				$name = (string) ( $scf_field['name'] ?? '' );
				if ( '' === $name || in_array( (string) ( $scf_field['type'] ?? '' ), [ 'accordion', 'message', 'tab' ], true ) ) {
					continue;
				}

				$fields[ $name ] = self::from_scf_field( $scf_field, $defaults[ $name ] ?? [] );
			}
		}

		self::$field_cache[ $cpt ] = ! empty( $fields ) ? $fields : $defaults;
		return self::$field_cache[ $cpt ];
	}

	/**
	 * Convert an SCF field to World Graph Studio's runtime field dialect.
	 *
	 * @param array<string, mixed> $field    SCF field.
	 * @param array<string, mixed> $defaults World Graph Studio-only defaults.
	 * @return array<string, mixed>
	 */
	public static function from_scf_field( array $field, array $defaults = [] ): array {
		$scf_type = (string) ( $field['type'] ?? 'text' );
		$type     = $scf_type;
		if ( 'date_picker' === $scf_type ) {
			$type = 'date';
		} elseif ( in_array( $scf_type, [ 'post_object', 'relationship' ], true ) ) {
			$type = 'relationship';
		} elseif ( 'repeater' === $scf_type ) {
			$type = 'structured';
		}

		$mapped = [
			'name'        => (string) ( $field['name'] ?? '' ),
			'label'       => (string) ( $field['label'] ?? '' ),
			'type'        => $type,
			'required'    => ! empty( $field['required'] ),
			'description' => (string) ( $field['instructions'] ?? '' ),
			'scf_key'     => (string) ( $field['key'] ?? '' ),
		];

		if ( array_key_exists( 'default_value', $field ) ) {
			$mapped['default'] = $field['default_value'];
		}

		if ( 'select' === $scf_type ) {
			$mapped['options']  = (array) ( $field['choices'] ?? [] );
			$mapped['multiple'] = ! empty( $field['multiple'] );
		} elseif ( 'taxonomy' === $scf_type ) {
			$mapped['taxonomy'] = (string) ( $field['taxonomy'] ?? '' );
			$mapped['multiple'] = ! empty( $field['multiple'] ) || in_array( (string) ( $field['field_type'] ?? '' ), [ 'checkbox', 'multi_select' ], true );
		} elseif ( 'relationship' === $type ) {
			$post_types = array_values( array_filter( (array) ( $field['post_type'] ?? [] ) ) );
			$mapped['related_cpt']  = (string) ( $post_types[0] ?? '' );
			$mapped['related_cpts'] = $post_types;
			$mapped['multiple']     = 'relationship' === $scf_type || ! empty( $field['multiple'] );
		} elseif ( 'user' === $scf_type ) {
			$mapped['multiple'] = ! empty( $field['multiple'] );
		}

		return array_replace( $defaults, $mapped );
	}

	/**
	 * Whether a field belongs to the World Graph Studio-owned group for this CPT.
	 *
	 * @param string               $cpt   CPT slug.
	 * @param array<string, mixed> $field SCF field.
	 * @return bool
	 */
	private static function is_owned_field( string $cpt, array $field ): bool {
		if ( ! isset( self::$owned_field_keys[ $cpt ] ) ) {
			$group = acf_get_field_group( self::group_key( $cpt ) );
			$keys  = [];
			self::collect_field_keys( $group ? (array) acf_get_fields( $group ) : [], $keys );
			self::$owned_field_keys[ $cpt ] = $keys;
		}

		return in_array( (string) ( $field['key'] ?? '' ), self::$owned_field_keys[ $cpt ], true );
	}

	/**
	 * Collect field and nested sub-field keys.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @param array<int, string>               $keys   Collected keys.
	 */
	private static function collect_field_keys( array $fields, array &$keys ): void {
		foreach ( $fields as $field ) {
			if ( ! empty( $field['key'] ) ) {
				$keys[] = (string) $field['key'];
			}

			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				self::collect_field_keys( $field['sub_fields'], $keys );
			}

			foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
				self::collect_field_keys( (array) ( $layout['sub_fields'] ?? [] ), $keys );
			}
		}
	}

	/**
	 * Resolve an SCF field by CPT and field name.
	 *
	 * @return array<string, mixed>|false
	 */
	public static function get_field_object( string $cpt, string $field_name ) {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return false;
		}

		$field = acf_get_field( self::field_key( $cpt, $field_name ) );
		if ( $field ) {
			return $field;
		}

		$group = acf_get_field_group( self::group_key( $cpt ) );
		foreach ( $group ? (array) acf_get_fields( $group ) : [] as $candidate ) {
			if ( $field_name === (string) ( $candidate['name'] ?? '' ) ) {
				return $candidate;
			}
		}

		return false;
	}

	/**
	 * Read a scalar/structured field through SCF.
	 *
	 * @return mixed
	 */
	public static function get_value( int $post_id, string $field_name ) {
		$cpt   = (string) get_post_type( $post_id );
		$field = self::get_field_object( $cpt, $field_name );
		if ( $field && function_exists( 'get_field' ) ) {
			$format = in_array( (string) ( $field['type'] ?? '' ), [ 'date_picker', 'repeater' ], true );
			return get_field( (string) $field['key'], $post_id, $format );
		}

		return get_post_meta( $post_id, $field_name, true );
	}

	/**
	 * Update a field through SCF, falling back to post meta for undeclared data.
	 */
	public static function update_value( int $post_id, string $field_name, $value ): bool {
		$cpt   = (string) get_post_type( $post_id );
		$field = self::get_field_object( $cpt, $field_name );
		if ( $field && function_exists( 'update_field' ) ) {
			$previous_internal_update = self::$internal_update;
			self::$internal_update = true;
			try {
				$result = update_field( (string) $field['key'], $value, $post_id );
			} finally {
				self::$internal_update = $previous_internal_update;
			}
			return false !== $result;
		}

		return false !== update_post_meta( $post_id, $field_name, $value );
	}

	/**
	 * Delete a field through SCF, including its hidden field-key reference.
	 */
	public static function delete_value( int $post_id, string $field_name ): bool {
		$cpt   = (string) get_post_type( $post_id );
		$field = self::get_field_object( $cpt, $field_name );
		if ( $field && function_exists( 'delete_field' ) ) {
			$defaults = worldgraph_get_field_defaults( $cpt );
			$config   = self::from_scf_field( $field, $defaults[ $field_name ] ?? [] );
			if ( 'relationship' === $config['type'] && ! empty( $config['related_cpt'] ) && function_exists( __NAMESPACE__ . '\\set_relationships_for_field' ) ) {
				$result = set_relationships_for_field(
					$post_id,
					$cpt,
					[],
					(string) $config['related_cpt'],
					(string) ( $config['relationship_type'] ?? 'belongs_to' ),
					$field_name,
					! empty( $config['multiple'] )
				);
				if ( is_wp_error( $result ) ) {
					return false;
				}
			}

			return (bool) delete_field( (string) $field['key'], $post_id );
		}

		return delete_post_meta( $post_id, $field_name );
	}

	/**
	 * Prevent UI, native REST, and datastore writes to importer-managed fields.
	 * World Graph Studio's internal helper temporarily opts in for trusted import/migration
	 * writes.
	 *
	 * @param mixed                $check   Short-circuit value.
	 * @param mixed                $value   Proposed value.
	 * @param int|string           $post_id SCF object ID.
	 * @param array<string, mixed> $field   SCF field.
	 * @return mixed
	 */
	public static function protect_read_only_value( $check, $value, $post_id, array $field ) {
		if ( self::$internal_update || ! is_numeric( $post_id ) ) {
			return $check;
		}

		$cpt = (string) get_post_type( (int) $post_id );
		if ( ! isset( worldgraph_get_all_cpts()[ $cpt ] ) || ! self::is_owned_field( $cpt, $field ) ) {
			return $check;
		}

		$defaults = worldgraph_get_field_defaults( $cpt );
		return ! empty( $defaults[ $field['name'] ]['read_only'] ) ? false : $check;
	}

	/**
	 * Prevent invalid JSON from reaching any SCF write path.
	 *
	 * SCF's standard form validator does not run for every REST/datastore save,
	 * so the pre-update guard is the final integrity boundary.
	 *
	 * @param mixed                $check   Existing short-circuit value.
	 * @param mixed                $value   Proposed value.
	 * @param int|string           $post_id SCF object ID.
	 * @param array<string, mixed> $field   SCF field.
	 * @return mixed
	 */
	public static function protect_json_value( $check, $value, $post_id, array $field ) {
		if ( null !== $check || ! is_numeric( $post_id ) ) {
			return $check;
		}

		$cpt = (string) get_post_type( (int) $post_id );
		if ( ! self::is_json_field( $cpt, $field ) ) {
			return $check;
		}

		$value = self::json_value_for_processing( $value, $field );
		return self::is_valid_json_value( $value ) ? $check : false;
	}

	/**
	 * Return a useful native REST error for invalid World Graph Studio JSON textareas.
	 *
	 * @param bool|\WP_Error       $valid Current validation result.
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field SCF field.
	 * @return bool|\WP_Error
	 */
	public static function validate_rest_json_value( $valid, $value, array $field ) {
		if ( true !== $valid ) {
			return $valid;
		}

		$cpt = self::cpt_from_field_key( (string) ( $field['key'] ?? '' ) );
		if ( '' === $cpt || ! self::is_json_field( $cpt, $field ) || self::is_valid_json_value( $value ) ) {
			return $valid;
		}

		return new \WP_Error(
			'rest_invalid_param',
			__( 'Enter a valid JSON array or object.', 'worldgraph' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Slash normalized JSON once at the WordPress metadata boundary.
	 *
	 * Core's metadata API always unslashes scalar values before persistence.
	 * SCF passes programmatic and REST values to that API without adding the
	 * expected slashes, which would otherwise corrupt JSON escapes and Unicode.
	 *
	 * @param mixed                $value    Normalized field value.
	 * @param int|string           $post_id  SCF object ID.
	 * @param array<string, mixed> $field    SCF field.
	 * @param mixed                $original Original submitted value.
	 * @return mixed
	 */
	public static function slash_json_value_for_storage( $value, $post_id, array $field, $original ) {
		if ( ! is_numeric( $post_id ) ) {
			return $value;
		}

		$cpt = (string) get_post_type( (int) $post_id );
		return self::is_json_field( $cpt, $field ) ? wp_slash( (string) $value ) : $value;
	}

	/**
	 * Whether a field uses World Graph Studio's explicit JSON storage contract.
	 *
	 * @param string               $cpt   World Graph Studio CPT.
	 * @param array<string, mixed> $field SCF field.
	 */
	private static function is_json_field( string $cpt, array $field ): bool {
		if ( '' === $cpt || ! isset( worldgraph_get_all_cpts()[ $cpt ] ) || ! self::is_owned_field( $cpt, $field ) ) {
			return false;
		}

		$defaults = worldgraph_get_field_defaults( $cpt );
		$name     = (string) ( $field['name'] ?? '' );
		return 'json' === (string) ( $defaults[ $name ]['format'] ?? '' );
	}

	/** Whether a JSON textarea is blank or contains an array/object document. */
	private static function is_valid_json_value( $value ): bool {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return false;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return true;
		}

		$decoded = json_decode( $value, true );
		return JSON_ERROR_NONE === json_last_error() && is_array( $decoded );
	}

	/**
	 * Unslash only a value coming from SCF's normal slashed form payload.
	 *
	 * Programmatic and REST values are already unslashed and must remain byte
	 * stable. Matching the field's exact submitted form value distinguishes the
	 * one WordPress request boundary where unslashing is required.
	 *
	 * @param mixed                $value SCF value.
	 * @param array<string, mixed> $field SCF field.
	 * @return mixed
	 */
	private static function json_value_for_processing( $value, array $field ) {
		$key = (string) ( $field['key'] ?? '' );
		if (
			'' !== $key
			&& isset( $_POST['acf'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the enclosing SCF save verifies its request.
			&& is_array( $_POST['acf'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only; normalized below.
			&& array_key_exists( $key, $_POST['acf'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- exact field-key lookup.
			&& is_scalar( $_POST['acf'][ $key ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- type checked before comparison.
			&& is_scalar( $value )
			&& (string) $_POST['acf'][ $key ] === (string) $value // phpcs:ignore WordPress.Security.NonceVerification.Missing -- identity check only.
		) {
			return wp_unslash( $value );
		}

		return $value;
	}

	/**
	 * Sanitize World Graph Studio scalar values and mirror relational SCF fields to the
	 * canonical Story Graph.
	 *
	 * @param mixed                $value     New value.
	 * @param int|string           $post_id   SCF object ID.
	 * @param array<string, mixed> $field     SCF field.
	 * @param mixed                $original  Original value.
	 * @return mixed
	 */
	public static function filter_update_value( $value, $post_id, array $field, $original ) {
		if ( ! is_numeric( $post_id ) ) {
			return $value;
		}

		$post_id = (int) $post_id;
		$cpt     = (string) get_post_type( $post_id );
		if ( ! isset( worldgraph_get_all_cpts()[ $cpt ] ) || ! self::is_owned_field( $cpt, $field ) ) {
			return $value;
		}

		$defaults = worldgraph_get_field_defaults( $cpt );
		$config   = self::from_scf_field( $field, $defaults[ $field['name'] ] ?? [] );
		if ( 'relationship' === $config['type'] && ! empty( $config['related_cpt'] ) && function_exists( __NAMESPACE__ . '\\set_relationships_for_field' ) ) {
			$target_ids = [];
			foreach ( (array) $value as $target ) {
				$target_ids[] = is_object( $target ) && isset( $target->ID ) ? (int) $target->ID : (int) $target;
			}

			$result = set_relationships_for_field(
				$post_id,
				$cpt,
				array_values( array_filter( $target_ids ) ),
				(string) $config['related_cpt'],
				(string) ( $config['relationship_type'] ?? 'belongs_to' ),
				(string) $field['name'],
				! empty( $config['multiple'] )
			);
			if ( is_wp_error( $result ) ) {
				return get_post_meta( $post_id, (string) $field['name'], true );
			}

			return $value;
		}

		if ( in_array( (string) $config['type'], [ 'taxonomy', 'structured', 'user' ], true ) || is_array( $value ) || is_object( $value ) ) {
			return $value;
		}
		if ( 'date' === (string) $config['type'] ) {
			$value = trim( (string) $value );
			if ( '' === $value || preg_match( '/^\d{8}$/', $value ) ) {
				return $value;
			}

			$timestamp = strtotime( $value );
			return false === $timestamp ? get_post_meta( $post_id, (string) $field['name'], true ) : gmdate( 'Ymd', $timestamp );
		}
		if ( 'json' === (string) ( $config['format'] ?? '' ) ) {
			// Template/Connection filters normalize this untouched value later in
			// the update pipeline. Generic textarea sanitization would strip valid
			// tokens such as <lora:...> from JSON string values.
			return (string) self::json_value_for_processing( $value, $field );
		}

		return worldgraph_sanitize_field_value( $value, $config );
	}

	/**
	 * Load relational SCF controls from the canonical Story Graph.
	 *
	 * @param mixed                $value   Stored SCF value.
	 * @param int|string           $post_id SCF object ID.
	 * @param array<string, mixed> $field   SCF field.
	 * @return mixed
	 */
	public static function filter_load_value( $value, $post_id, array $field ) {
		if ( ! is_numeric( $post_id ) || ! in_array( (string) ( $field['type'] ?? '' ), [ 'post_object', 'relationship' ], true ) ) {
			return $value;
		}

		$post_id = (int) $post_id;
		$cpt     = (string) get_post_type( $post_id );
		if ( ! self::is_owned_field( $cpt, $field ) ) {
			return $value;
		}

		$defaults = worldgraph_get_field_defaults( $cpt );
		$config   = self::from_scf_field( $field, $defaults[ $field['name'] ] ?? [] );
		$to_type  = (string) ( $config['related_cpt'] ?? '' );
		if ( '' === $to_type || ! function_exists( __NAMESPACE__ . '\\get_relationships' ) ) {
			return $value;
		}

		$matches       = [];
		$expected_type = (string) ( $config['relationship_type'] ?? 'belongs_to' );
		$has_marker    = function_exists( __NAMESPACE__ . '\\relationship_field_marker_key' )
			&& metadata_exists( 'post', $post_id, relationship_field_marker_key( (string) $field['name'] ) );
		foreach ( get_relationships( $post_id, $cpt, 'outgoing' ) as $relationship ) {
			if ( $to_type !== (string) ( $relationship['to_type'] ?? '' ) ) {
				continue;
			}

			$relationship_field = (string) ( $relationship['metadata']['field'] ?? '' );
			if ( '' !== $relationship_field && (string) $field['name'] !== $relationship_field ) {
				continue;
			}
			if ( '' === $relationship_field && ( $has_marker || $expected_type !== (string) ( $relationship['type'] ?? '' ) ) ) {
				continue;
			}

			$matches[] = (int) $relationship['to_id'];
		}

		if ( ! empty( $matches ) ) {
			return 'relationship' === (string) $field['type'] || ! empty( $field['multiple'] ) ? $matches : $matches[0];
		}

		// A per-field marker distinguishes an intentionally empty graph slot from
		// legacy named relationship meta that has not been migrated yet.
		if ( $has_marker ) {
			return 'relationship' === (string) $field['type'] || ! empty( $field['multiple'] ) ? [] : '';
		}

		return $value;
	}

	/**
	 * Run one legacy migration batch from a privileged administration request,
	 * serialized by an atomic option lock.
	 */
	public static function maybe_migrate_legacy_values(): void {
		if ( (int) get_option( self::VALUE_MIGRATION_OPTION, 0 ) >= self::VALUE_MIGRATION_VERSION ) {
			return;
		}

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if (
			get_option( self::SCHEMA_SYNC_ERROR_OPTION, false )
			|| ! self::database_groups_have_canonical_fields( worldgraph_get_all_field_defaults() )
		) {
			return;
		}

		$retry_requested = false;
		if ( isset( $_GET['worldgraph_scf_retry_migration'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately below.
			check_admin_referer( 'worldgraph_scf_retry_migration' );
			$retry_requested = true;
		}

		$token = self::acquire_option_lock( self::VALUE_MIGRATION_LOCK_OPTION, 15 * MINUTE_IN_SECONDS );
		if ( '' === $token ) {
			return;
		}

		try {
			if ( $retry_requested ) {
				$state = get_option( self::VALUE_MIGRATION_STATE_OPTION, [] );
				if ( is_array( $state ) && self::VALUE_MIGRATION_VERSION === (int) ( $state['version'] ?? 0 ) ) {
					foreach ( (array) ( $state['failures'] ?? [] ) as &$failure ) {
						$failure['attempts'] = 0;
					}
					unset( $failure );
					$state['phase'] = 'retry';
					self::persist_migration_state( $state );
				}
			}
			self::migrate_legacy_values();
		} finally {
			self::release_option_lock( self::VALUE_MIGRATION_LOCK_OPTION, $token );
		}
	}

	/**
	 * Convert legacy values into SCF's storage contracts once per site.
	 *
	 * This migrates serialized dialogue arrays to repeater rows, normalizes date
	 * storage, and backfills SCF name-to-key references. Legacy Story Graph
	 * edges remain available through the compatibility loader and are adopted
	 * only when their SCF control is explicitly saved.
	 */
	private static function migrate_legacy_values(): void {
		global $wpdb;

		$state = get_option( self::VALUE_MIGRATION_STATE_OPTION, [] );
		$state = is_array( $state ) && self::VALUE_MIGRATION_VERSION === (int) ( $state['version'] ?? 0 )
			? $state
			: [
				'version'  => self::VALUE_MIGRATION_VERSION,
				'phase'    => 'scan',
				'last_id'  => 0,
				'failures' => [],
			];

		if ( in_array( (string) ( $state['phase'] ?? '' ), [ 'retry', 'review' ], true ) ) {
			self::retry_legacy_migration_failures( $state );
			return;
		}

		$last_id      = absint( $state['last_id'] ?? 0 );
		$cpts         = array_keys( worldgraph_get_all_cpts() );
		$placeholders = implode( ', ', array_fill( 0, count( $cpts ), '%s' ) );
		$query_args   = array_merge( [ $last_id ], $cpts, [ self::VALUE_MIGRATION_BATCH_SIZE ] );
		$sql          = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are generated from the canonical CPT count and match the flattened argument array.
			"SELECT ID, post_type FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$placeholders}) ORDER BY ID ASC LIMIT %d",
			$query_args
		);
		$batch        = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		if ( '' !== (string) $wpdb->last_error ) {
			worldgraph_log( 'SCF legacy value migration could not read its next post batch.' );
			return;
		}

		$started   = microtime( true );
		$processed = 0;
		foreach ( $batch as $row ) {
			$post_id = absint( $row['ID'] ?? 0 );
			$cpt     = (string) ( $row['post_type'] ?? '' );
			if ( ! $post_id || ! isset( worldgraph_get_all_cpts()[ $cpt ] ) ) {
				continue;
			}

			$result = self::migrate_legacy_post( $post_id, $cpt );
			if ( is_wp_error( $result ) ) {
				$previous = (array) ( $state['failures'][ $post_id ] ?? [] );
				$state['failures'][ $post_id ] = [
					'cpt'      => $cpt,
					'attempts' => (int) ( $previous['attempts'] ?? 0 ) + 1,
					'error'    => substr( $result->get_error_message(), 0, 500 ),
				];
			} else {
				unset( $state['failures'][ $post_id ] );
			}

			$state['last_id'] = $post_id;
			++$processed;
			if ( microtime( true ) - $started >= 8 ) {
				break;
			}
		}

		$scan_finished = $processed === count( $batch ) && count( $batch ) < self::VALUE_MIGRATION_BATCH_SIZE;
		if ( $scan_finished ) {
			if ( empty( $state['failures'] ) ) {
				self::complete_legacy_migration();
				return;
			}

			$state['phase'] = 'retry';
		}

		if ( ! self::persist_migration_state( $state ) ) {
			worldgraph_log( 'SCF legacy value migration could not persist its batch cursor.' );
		}
	}

	/**
	 * Convert all compatible legacy values on one World Graph Studio post.
	 *
	 * @return true|\WP_Error
	 */
	private static function migrate_legacy_post( int $post_id, string $cpt ) {
		if ( 'worldgraph_scene' === $cpt ) {
			$result = self::migrate_legacy_dialogue( $post_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		foreach ( worldgraph_get_field_defaults( $cpt ) as $field_name => $config ) {
			$field = self::get_field_object( $cpt, $field_name );
			if ( ! $field ) {
				return new \WP_Error(
					'worldgraph_scf_canonical_field_missing',
					sprintf(
						/* translators: %s: canonical SCF field name. */
						__( 'Canonical SCF field %s is unavailable.', 'worldgraph' ),
						$field_name
					)
				);
			}

			if ( metadata_exists( 'post', $post_id, $field_name ) ) {
				update_post_meta( $post_id, '_' . $field_name, (string) $field['key'] );
				if ( (string) get_post_meta( $post_id, '_' . $field_name, true ) !== (string) $field['key'] ) {
					return new \WP_Error(
						'worldgraph_scf_reference_migration_failed',
						sprintf(
							/* translators: %s: SCF field name. */
							__( 'Could not backfill the SCF reference for %s.', 'worldgraph' ),
							$field_name
						)
					);
				}
			}

			if ( 'date' !== (string) ( $config['type'] ?? '' ) ) {
				continue;
			}

			$raw_date   = (string) get_post_meta( $post_id, $field_name, true );
			$normalized = self::normalize_legacy_date( $raw_date );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			if ( $normalized !== $raw_date ) {
				worldgraph_update_field_value( $post_id, $field_name, $normalized );
				if ( (string) get_post_meta( $post_id, $field_name, true ) !== $normalized ) {
					return new \WP_Error(
						'worldgraph_scf_date_migration_failed',
						sprintf(
							/* translators: %s: SCF date field name. */
							__( 'Could not normalize the SCF date field %s.', 'worldgraph' ),
							$field_name
						)
					);
				}
			}
		}

		return true;
	}

	/** Return blank/Ymd or normalize only known legacy date representations. */
	private static function normalize_legacy_date( string $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $matches ) ) {
			return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] )
				? $value
				: new \WP_Error( 'worldgraph_scf_invalid_legacy_date', __( 'A legacy SCF date is not a real calendar date.', 'worldgraph' ) );
		}

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}:\d{2})?$/', $value, $matches ) ) {
			if ( checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
				return $matches[1] . $matches[2] . $matches[3];
			}
		}

		return new \WP_Error( 'worldgraph_scf_unknown_legacy_date', __( 'A legacy SCF date uses an unsupported or invalid format.', 'worldgraph' ) );
	}

	/** Convert one legacy serialized Scene dialogue array to SCF repeater rows. */
	private static function migrate_legacy_dialogue( int $scene_id ) {
		$backup_key = '_worldgraph_scf_legacy_dialogue_backup';
		$has_backup = metadata_exists( 'post', $scene_id, $backup_key );
		$raw        = $has_backup
			? get_post_meta( $scene_id, $backup_key, true )
			: get_post_meta( $scene_id, 'dialogue', true );
		if ( ! is_array( $raw ) ) {
			return $has_backup
				? new \WP_Error( 'worldgraph_scf_invalid_dialogue_backup', __( 'The preserved legacy dialogue backup is malformed.', 'worldgraph' ) )
				: true;
		}

		$rows = [];
		foreach ( $raw as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error(
					'worldgraph_scf_malformed_dialogue',
					sprintf(
						/* translators: %d: one-based dialogue row number. */
						__( 'Dialogue row %d is malformed.', 'worldgraph' ),
						(int) $index + 1
					)
				);
			}

			$known_properties = [ 'speaker', 'line', 'description', 'sequence' ];
			$unknown          = array_diff( array_map( 'strval', array_keys( $row ) ), $known_properties );
			if ( ! empty( $unknown ) ) {
				return new \WP_Error(
					'worldgraph_scf_unknown_dialogue_property',
					sprintf(
						/* translators: 1: one-based dialogue row number, 2: comma-separated unsupported field names. */
						__( 'Dialogue row %1$d contains unsupported data (%2$s).', 'worldgraph' ),
						(int) $index + 1,
						implode( ', ', $unknown )
					)
				);
			}

			foreach ( $known_properties as $property ) {
				if ( isset( $row[ $property ] ) && ! is_scalar( $row[ $property ] ) ) {
					return new \WP_Error(
						'worldgraph_scf_malformed_dialogue_value',
						sprintf(
							/* translators: 1: one-based dialogue row number, 2: dialogue field name. */
							__( 'Dialogue row %1$d has an invalid %2$s value.', 'worldgraph' ),
							(int) $index + 1,
							$property
						)
					);
				}
			}
			if ( isset( $row['sequence'] ) && '' !== (string) $row['sequence'] && ! is_numeric( $row['sequence'] ) ) {
				return new \WP_Error(
					'worldgraph_scf_malformed_dialogue_sequence',
					sprintf(
						/* translators: %d: one-based dialogue row number. */
						__( 'Dialogue row %d has a nonnumeric sequence.', 'worldgraph' ),
						(int) $index + 1
					)
				);
			}

			$rows[] = [
				'speaker'     => (string) ( $row['speaker'] ?? '' ),
				'line'        => (string) ( $row['line'] ?? '' ),
				'description' => (string) ( $row['description'] ?? '' ),
				'sequence'    => isset( $row['sequence'] ) && '' !== (string) $row['sequence'] ? 0 + $row['sequence'] : '',
			];
		}

		if ( ! $has_backup ) {
			add_post_meta( $scene_id, $backup_key, $raw, true );
			if ( $raw != get_post_meta( $scene_id, $backup_key, true ) ) {
				return new \WP_Error( 'worldgraph_scf_dialogue_backup_failed', __( 'World Graph Studio could not preserve the legacy dialogue before migration.', 'worldgraph' ) );
			}
		}

		worldgraph_update_field_value( $scene_id, 'dialogue', $rows );
		$stored_count = get_post_meta( $scene_id, 'dialogue', true );
		if ( is_array( $stored_count ) || (int) $stored_count !== count( $rows ) ) {
			return new \WP_Error( 'worldgraph_scf_dialogue_migration_failed', __( 'SCF did not persist the legacy dialogue as repeater rows.', 'worldgraph' ) );
		}

		foreach ( $rows as $index => $row ) {
			foreach ( $row as $name => $expected ) {
				if ( (string) get_post_meta( $scene_id, 'dialogue_' . $index . '_' . $name, true ) !== (string) $expected ) {
					return new \WP_Error(
						'worldgraph_scf_dialogue_verification_failed',
						sprintf(
							/* translators: 1: one-based dialogue row number, 2: dialogue field name. */
							__( 'SCF dialogue row %1$d did not preserve %2$s.', 'worldgraph' ),
							$index + 1,
							$name
						)
					);
				}
			}
		}

		delete_post_meta( $scene_id, $backup_key );
		return true;
	}

	/** Retry a bounded set of durable migration failures. */
	private static function retry_legacy_migration_failures( array $state ): void {
		$failures = (array) ( $state['failures'] ?? [] );
		$processed = 0;
		foreach ( $failures as $post_id => $failure ) {
			if ( $processed >= 25 || (int) ( $failure['attempts'] ?? 0 ) >= self::VALUE_MIGRATION_MAX_ATTEMPTS ) {
				continue;
			}

			$post_id = absint( $post_id );
			$cpt     = (string) get_post_type( $post_id );
			if ( ! $post_id || ! isset( worldgraph_get_all_cpts()[ $cpt ] ) ) {
				unset( $state['failures'][ $post_id ] );
				continue;
			}

			$result = self::migrate_legacy_post( $post_id, $cpt );
			if ( is_wp_error( $result ) ) {
				$state['failures'][ $post_id ] = [
					'cpt'      => $cpt,
					'attempts' => (int) ( $failure['attempts'] ?? 0 ) + 1,
					'error'    => substr( $result->get_error_message(), 0, 500 ),
				];
			} else {
				unset( $state['failures'][ $post_id ] );
			}
			++$processed;
		}

		if ( empty( $state['failures'] ) ) {
			self::complete_legacy_migration();
			return;
		}

		$retryable = array_filter(
			(array) $state['failures'],
			static function ( array $failure ): bool {
				return (int) ( $failure['attempts'] ?? 0 ) < self::VALUE_MIGRATION_MAX_ATTEMPTS;
			}
		);
		$state['phase'] = empty( $retryable ) ? 'review' : 'retry';
		if ( ! self::persist_migration_state( $state ) ) {
			worldgraph_log( 'SCF legacy value migration could not persist its failure state.' );
		}
	}

	/** Persist a non-autoloaded migration state and verify the write. */
	private static function persist_migration_state( array $state ): bool {
		if ( false === get_option( self::VALUE_MIGRATION_STATE_OPTION, false ) ) {
			add_option( self::VALUE_MIGRATION_STATE_OPTION, $state, '', false );
		} else {
			update_option( self::VALUE_MIGRATION_STATE_OPTION, $state, false );
		}

		return $state == get_option( self::VALUE_MIGRATION_STATE_OPTION, [] );
	}

	/** Mark the migration complete only after the durable failure set is empty. */
	private static function complete_legacy_migration(): void {
		update_option( self::VALUE_MIGRATION_OPTION, self::VALUE_MIGRATION_VERSION, false );
		if ( self::VALUE_MIGRATION_VERSION !== (int) get_option( self::VALUE_MIGRATION_OPTION, 0 ) ) {
			worldgraph_log( 'SCF legacy value migration could not record completion.' );
			return;
		}

		delete_option( self::VALUE_MIGRATION_STATE_OPTION );
	}

	/** Show durable migration failures and offer an explicit retry action. */
	public static function legacy_migration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = get_option( self::VALUE_MIGRATION_STATE_OPTION, [] );
		if ( ! is_array( $state ) || 'review' !== (string) ( $state['phase'] ?? '' ) || empty( $state['failures'] ) ) {
			return;
		}

		$retry_url = wp_nonce_url(
			add_query_arg( 'worldgraph_scf_retry_migration', '1', admin_url( 'index.php' ) ),
			'worldgraph_scf_retry_migration'
		);
		echo '<div class="notice notice-error"><p>'
			. esc_html( sprintf(
				/* translators: %d: number of legacy SCF values requiring review. */
				__( 'World Graph Studio preserved %d legacy SCF value(s) that require review.', 'worldgraph' ),
				count( $state['failures'] )
			) )
			. ' <a href="' . esc_url( $retry_url ) . '">' . esc_html__( 'Retry migration', 'worldgraph' ) . '</a>'
			. '</p></div>';
	}

	/**
	 * Disable importer-managed fields in the content editing form.
	 *
	 * @param array<string, mixed>|false $field SCF field.
	 * @return array<string, mixed>|false
	 */
	public static function prepare_field( $field ) {
		if ( ! is_array( $field ) || empty( $field['name'] ) ) {
			return $field;
		}

		$cpt = self::cpt_from_field_key( (string) ( $field['key'] ?? '' ) );
		if ( '' === $cpt ) {
			return $field;
		}
		if ( ! self::is_owned_field( $cpt, $field ) ) {
			return $field;
		}

		$defaults = worldgraph_get_field_defaults( $cpt );
		if ( ! empty( $defaults[ $field['name'] ]['read_only'] ) ) {
			$field['disabled'] = 1;
			$field['instructions'] = trim( (string) ( $field['instructions'] ?? '' ) . ' This field is managed by the World Graph Studio importer.' );
		}

		return $field;
	}

	/**
	 * Resolve a World Graph Studio CPT from one of this adapter's stable field keys.
	 */
	private static function cpt_from_field_key( string $field_key ): string {
		foreach ( array_keys( worldgraph_get_all_cpts() ) as $cpt ) {
			$prefix = 'field_' . self::key_fragment( $cpt ) . '_';
			if ( 0 === strpos( $field_key, $prefix ) ) {
				return $cpt;
			}
		}

		return '';
	}

	/**
	 * Add SCF's hidden name-to-key reference when legacy code writes raw meta.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function sync_reference_meta( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		if ( '' === $meta_key || '_' === $meta_key[0] ) {
			return;
		}

		$cpt   = (string) get_post_type( $post_id );
		$field = self::get_field_object( $cpt, $meta_key );
		if ( ! $field || empty( $field['key'] ) ) {
			return;
		}

		$reference_key = '_' . $meta_key;
		if ( (string) get_post_meta( $post_id, $reference_key, true ) !== (string) $field['key'] ) {
			update_post_meta( $post_id, $reference_key, (string) $field['key'] );
		}
		if ( function_exists( 'acf_flush_value_cache' ) ) {
			acf_flush_value_cache( $post_id, $meta_key );
		}
	}

	/**
	 * Remove an orphaned SCF field-key reference after raw meta deletion.
	 *
	 * @param int    $meta_ids   Deleted meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function delete_reference_meta( $meta_ids, int $post_id, string $meta_key, $meta_value ): void {
		if ( '' === $meta_key || '_' === $meta_key[0] || metadata_exists( 'post', $post_id, $meta_key ) ) {
			return;
		}

		$cpt = (string) get_post_type( $post_id );
		if ( self::get_field_object( $cpt, $meta_key ) ) {
			delete_post_meta( $post_id, '_' . $meta_key );
			if ( function_exists( 'acf_flush_value_cache' ) ) {
				acf_flush_value_cache( $post_id, $meta_key );
			}
		}
	}
}
