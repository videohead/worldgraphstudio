<?php
/**
 * Administrative purge for data owned by World Graph Studio.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides an explicit, administrator-only way to remove plugin data.
 *
 * Plugin files, activation state, and the code-defined SCF schema are not data
 * targets. Generated Media Library items are removed only when World Graph
 * Studio's provenance metadata proves that the plugin created them.
 */
class Data_Purge {

	/** Admin page slug. */
	public const PAGE_SLUG = 'worldgraph-purge-data';

	/** admin-post.php action. */
	public const ACTION = 'worldgraph_purge_data';

	/** Confirmation text required by both the browser and the server. */
	public const CONFIRMATION = 'PURGE WORLDGRAPH DATA';

	/** Nonce action and request field. */
	private const NONCE_ACTION = 'worldgraph_purge_data';
	private const NONCE_FIELD  = 'worldgraph_purge_nonce';

	/** Nonce used to authenticate the one-request result notice. */
	private const NOTICE_NONCE_ACTION = 'worldgraph_purge_notice';

	/** Number of database records handled per pass. */
	private const BATCH_SIZE = 200;

	/** Exact upload directory owned by the plugin. */
	private const UPLOAD_SUBDIRECTORY = 'worldgraph';

	/**
	 * Current, internal, retired, and compatibility post types owned by the plugin.
	 *
	 * The purge query also matches the reserved worldgraph_ and legacy storyos_
	 * post-type prefixes so an unregistered or future plugin record cannot remain.
	 *
	 * @var array<int, string>
	 */
	private const KNOWN_POST_TYPES = [
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
		'worldgraph_template',
		'worldgraph_conn',
		'worldgraph_gen',
		'worldgraph_agent',
		'worldgraph_board',
		'worldgraph_editorial_artifact',
		'worldgraph_editorial_ar',
		'storyos_project',
		'storyos_story_world',
		'storyos_character',
		'storyos_location',
		'storyos_prop',
		'storyos_organization',
		'storyos_episode',
		'storyos_scene',
		'storyos_shot',
		'storyos_sound',
		'storyos_asset',
		'storyos_editorial_artifact',
		'storyos_editorial_ar',
		'storyos_editorial',
		'storyos_template',
		'storyos_connection',
		'storyos_generation',
	];

	/** Current taxonomies owned by the plugin. */
	private const TAXONOMIES = [
		'worldgraph_asset_type',
		'worldgraph_character_relation',
		'worldgraph_character_role',
		'worldgraph_genre',
		'worldgraph_status',
		'worldgraph_scene_tag',
		'worldgraph_sequence',
		'worldgraph_sound_type',
		'worldgraph_template_category',
	];

	/** Historical taxonomy names owned by the predecessor plugin. */
	private const LEGACY_TAXONOMIES = [
		'storyos_asset_type',
		'storyos_character_relation',
		'storyos_character_role',
		'storyos_genre',
		'storyos_status',
		'storyos_scene_tag',
		'storyos_sequence',
		'storyos_sound_type',
		'storyos_template_category',
	];

	/** Prefixes reserved for plugin options, transients, metadata, and cron hooks. */
	private const DATA_PREFIXES = [ 'worldgraph_', 'storyos_' ];

	/** Plugin-owned options that predate the World Graph Studio prefix. */
	private const EXACT_OPTIONS = [
		'celtx_credentials',
		'celtx_enabled',
		'widget_worldgraph_search',
		'widget_storyos_search',
	];

	/** Provenance keys that unequivocally identify plugin-generated attachments. */
	private const GENERATED_ATTACHMENT_META_KEYS = [
		'_worldgraph_generated_from',
		'_storyos_generated_from',
	];

	/** Register the menu and POST endpoint. */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ], 30 );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_purge' ] );
	}

	/** Add the destructive-data page beneath the main plugin dashboard. */
	public static function add_page(): void {
		add_submenu_page(
			'worldgraph',
			__( 'Purge World Graph Studio Data', 'worldgraph' ),
			__( 'Purge Data', 'worldgraph' ),
			self::required_capability(),
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/** Render the confirmation page. */
	public static function render_page(): void {
		if ( ! self::current_user_can_purge() ) {
			wp_die(
				esc_html__( 'You are not allowed to purge World Graph Studio data.', 'worldgraph' ),
				esc_html__( 'Forbidden', 'worldgraph' ),
				[ 'response' => 403 ]
			);
		}

		self::render_result_notice();
		?>
		<div class="wrap worldgraph-data-purge">
			<h1><?php esc_html_e( 'Purge World Graph Studio Data', 'worldgraph' ); ?></h1>
			<p><?php esc_html_e( 'This permanently returns the active plugin to an unconfigured, empty-data state.', 'worldgraph' ); ?></p>
			<?php if ( is_multisite() ) : ?>
				<p><strong><?php esc_html_e( 'On multisite, this removes World Graph Studio data from every site in the current network.', 'worldgraph' ); ?></strong></p>
			<?php endif; ?>

			<div class="notice notice-error inline">
				<p><strong><?php esc_html_e( 'This cannot be undone.', 'worldgraph' ); ?></strong></p>
				<p><?php esc_html_e( 'Export or back up anything you may need before continuing.', 'worldgraph' ); ?></p>
			</div>

			<h2><?php esc_html_e( 'The purge removes', 'worldgraph' ); ?></h2>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'All World Graph Studio story records, Connections, Templates, Jobs, and their SCF field values and API metadata.', 'worldgraph' ); ?></li>
				<li><?php esc_html_e( 'World Graph Studio taxonomy terms, settings, encrypted credentials, integration mappings, transients, caches, and scheduled events.', 'worldgraph' ); ?></li>
				<li><?php esc_html_e( 'Generation logs and files stored in the plugin-owned uploads/worldgraph directory.', 'worldgraph' ); ?></li>
				<li><?php esc_html_e( 'Media Library attachments created by World Graph Studio and marked with its generation provenance metadata.', 'worldgraph' ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'The purge preserves', 'worldgraph' ); ?></h2>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'The plugin files, activation state, and code-defined SCF schema, so the plugin remains usable.', 'worldgraph' ); ?></li>
				<li><?php esc_html_e( 'Unrelated WordPress posts, users, taxonomy terms, options, and Media Library attachments.', 'worldgraph' ); ?></li>
				<li><?php esc_html_e( 'Data already held by external providers. Revoke credentials or delete provider-side data with those services when required.', 'worldgraph' ); ?></li>
			</ul>

			<p><?php esc_html_e( 'Code-defined field groups and default taxonomy terms may be recreated automatically on the next request.', 'worldgraph' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<p>
					<label for="worldgraph-purge-confirmation">
						<?php
						printf(
							/* translators: %s: exact confirmation phrase the administrator must type. */
							esc_html__( 'Type %s to confirm:', 'worldgraph' ),
							'<code>' . esc_html( self::CONFIRMATION ) . '</code>'
						);
						?>
					</label>
				</p>
				<p>
					<input
						type="text"
						class="regular-text"
						id="worldgraph-purge-confirmation"
						name="worldgraph_purge_confirmation"
						autocomplete="off"
						spellcheck="false"
						required
						pattern="<?php echo esc_attr( self::CONFIRMATION ); ?>"
					/>
				</p>
				<?php submit_button( __( 'Permanently Purge Data', 'worldgraph' ), 'delete' ); ?>
			</form>
		</div>
		<?php
	}

	/** Authenticate the destructive request, run it, and redirect to a result notice. */
	public static function handle_purge(): void {
		if ( ! self::current_user_can_purge() ) {
			wp_die(
				esc_html__( 'You are not allowed to purge World Graph Studio data.', 'worldgraph' ),
				esc_html__( 'Forbidden', 'worldgraph' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$confirmation = isset( $_POST['worldgraph_purge_confirmation'] )
			? sanitize_text_field( wp_unslash( $_POST['worldgraph_purge_confirmation'] ) )
			: '';
		if ( ! hash_equals( self::CONFIRMATION, $confirmation ) ) {
			wp_die(
				esc_html__( 'The confirmation phrase did not match. No purge was started.', 'worldgraph' ),
				esc_html__( 'Confirmation Required', 'worldgraph' ),
				[ 'response' => 400 ]
			);
		}

		$result = self::purge();
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Purge Failed', 'worldgraph' ),
				[ 'response' => 500 ]
			);
		}

		$query = [
			'page'                          => self::PAGE_SLUG,
			'worldgraph_purge_status'       => $result['error_count'] > 0 ? 'partial' : 'success',
			'worldgraph_purge_notice_nonce' => wp_create_nonce( self::NOTICE_NONCE_ACTION ),
			'posts'                         => $result['posts'],
			'attachments'                   => $result['attachments'],
			'terms'                         => $result['terms'],
			'options'                       => $result['options'] + $result['site_options'],
			'metadata'                      => $result['postmeta'] + $result['termmeta'] + $result['usermeta'],
			'events'                        => $result['events'],
			'files'                         => $result['files'],
			'issues'                        => $result['error_count'],
		];

		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Permanently remove data owned by World Graph Studio.
	 *
	 * @return array<string, int|array<int, string>>|\WP_Error Purge counts or an authorization error.
	 */
	public static function purge() {
		if ( ! self::current_user_can_purge() ) {
			return new \WP_Error( 'worldgraph_purge_forbidden', __( 'Administrator permission is required to purge World Graph Studio data.', 'worldgraph' ) );
		}

		$result = [
			'posts'              => 0,
			'attachments'        => 0,
			'terms'              => 0,
			'options'            => 0,
			'site_options'       => 0,
			'postmeta'           => 0,
			'termmeta'           => 0,
			'usermeta'           => 0,
			'events'             => 0,
			'files'              => 0,
			'widget_assignments' => 0,
			'error_count'        => 0,
			'errors'             => [],
		];

		// Network settings and user metadata are shared, while every site has its
		// own records, options, cron queue, and uploads directory.
		self::purge_site_options( $result );

		$original_site_id = get_current_blog_id();
		$site_ids         = [ $original_site_id ];
		if ( is_multisite() ) {
			$site_ids = get_sites(
				[
					'network_id' => get_current_network_id(),
					'fields'     => 'ids',
					'number'     => 0,
				]
			);
			$site_ids = is_array( $site_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $site_ids ) ) ) ) : [];
			if ( [] === $site_ids ) {
				self::add_error( $result, __( 'Sites in the current network could not be enumerated; only the current site was purged.', 'worldgraph' ) );
				$site_ids = [ $original_site_id ];
			}
		}

		foreach ( $site_ids as $site_id ) {
			$switched = $site_id !== get_current_blog_id();
			if ( $switched && ! switch_to_blog( $site_id ) ) {
				self::add_error( $result, sprintf( 'Could not switch to site #%d for deletion.', $site_id ) );
				continue;
			}

			try {
				self::purge_current_site_data( $result );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}

		self::purge_metadata( 'user', $result, 'usermeta' );

		// A deletion hook on any site may recreate a network-scoped setting.
		self::purge_site_options( $result );
		unset( $GLOBALS['worldgraph_incoming_relationship_index'], $GLOBALS['worldgraph_headless_story_queue'] );

		return $result;
	}

	/** Remove data stored in the currently selected site's tables and uploads. */
	private static function purge_current_site_data( array &$result ): void {
		// Stop future workers, then remove options first so deletion hooks cannot
		// reuse credentials or notify a configured external frontend.
		self::purge_scheduled_events( $result );
		self::purge_widget_assignments( $result );
		self::purge_options( $result );
		self::purge_generated_attachments( $result );
		self::purge_plugin_posts( $result );
		self::purge_taxonomy_terms( $result );
		self::purge_metadata( 'post', $result, 'postmeta' );
		self::purge_metadata( 'term', $result, 'termmeta' );
		self::purge_upload_artifacts( $result );

		// Deletion hooks can recreate options or schedule follow-up work.
		self::purge_options( $result );
		self::purge_scheduled_events( $result );
	}

	/** Multisite-wide metadata and options require a network administrator. */
	private static function required_capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/** Whether the current administrator may remove every data class in scope. */
	private static function current_user_can_purge(): bool {
		return current_user_can( self::required_capability() );
	}

	/** @return array<int, string> Known post types, useful for audits and tests. */
	public static function known_post_types(): array {
		return self::KNOWN_POST_TYPES;
	}

	/** @return array<int, string> Current and historical taxonomies. */
	public static function known_taxonomies(): array {
		return array_merge( self::TAXONOMIES, self::LEGACY_TAXONOMIES );
	}

	/** @return array<int, string> Reserved data prefixes removed by the purge. */
	public static function data_prefixes(): array {
		return self::DATA_PREFIXES;
	}

	/** Show a nonce-authenticated result notice after the POST redirect. */
	private static function render_result_notice(): void {
		if ( empty( $_GET['worldgraph_purge_notice_nonce'] ) ) {
			return;
		}

		$notice_nonce = sanitize_text_field( wp_unslash( $_GET['worldgraph_purge_notice_nonce'] ) );
		if ( ! wp_verify_nonce( $notice_nonce, self::NOTICE_NONCE_ACTION ) ) {
			return;
		}

		$status = isset( $_GET['worldgraph_purge_status'] ) ? sanitize_key( wp_unslash( $_GET['worldgraph_purge_status'] ) ) : '';
		if ( ! in_array( $status, [ 'success', 'partial' ], true ) ) {
			return;
		}

		$counts = [];
		foreach ( [ 'posts', 'attachments', 'terms', 'options', 'metadata', 'events', 'files', 'issues' ] as $key ) {
			$counts[ $key ] = isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
		}

		$class = 'success' === $status ? 'notice-success' : 'notice-warning';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible"><p>
			<?php
			printf(
				/* translators: 1: posts, 2: generated attachments, 3: terms, 4: options, 5: metadata rows, 6: cron events, 7: files. */
				esc_html__( 'World Graph Studio purge finished: %1$d records, %2$d generated attachments, %3$d terms, %4$d options, %5$d metadata rows, %6$d scheduled events, and %7$d upload files removed.', 'worldgraph' ),
				absint( $counts['posts'] ),
				absint( $counts['attachments'] ),
				absint( $counts['terms'] ),
				absint( $counts['options'] ),
				absint( $counts['metadata'] ),
				absint( $counts['events'] ),
				absint( $counts['files'] )
			);
			?>
			<?php if ( $counts['issues'] > 0 ) : ?>
				<?php
				printf(
					/* translators: %d: number of records or files WordPress could not remove. */
					esc_html__( ' %d item(s) could not be removed; review filesystem permissions and run the purge again.', 'worldgraph' ),
					absint( $counts['issues'] )
				);
				?>
			<?php endif; ?>
		</p></div>
		<?php
	}

	/** Delete every scheduled event whose hook belongs to the plugin namespace. */
	private static function purge_scheduled_events( array &$result ): void {
		if ( ! function_exists( '_get_cron_array' ) ) {
			self::add_error( $result, __( 'WordPress cron data was unavailable.', 'worldgraph' ) );
			return;
		}

		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return;
		}

		foreach ( $cron as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( ! self::has_owned_prefix( (string) $hook ) ) {
					continue;
				}
				foreach ( (array) $events as $event ) {
					$args      = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : [];
					$unscheduled = wp_unschedule_event( (int) $timestamp, (string) $hook, $args, true );
					if ( is_wp_error( $unscheduled ) || false === $unscheduled ) {
						self::add_error( $result, sprintf( 'Could not remove scheduled hook %s.', (string) $hook ) );
						continue;
					}
					$result['events']++;
				}
			}
		}
	}

	/** Remove generated Media Library items carrying explicit plugin provenance. */
	private static function purge_generated_attachments( array &$result ): void {
		global $wpdb;

		$cursor = 0;
		do {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded ownership query before deleting through the attachment API.
				$wpdb->prepare(
					"SELECT DISTINCT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.ID > %d AND p.post_type = 'attachment'
					AND pm.meta_key IN ( %s, %s )
					ORDER BY p.ID ASC LIMIT %d",
					$cursor,
					self::GENERATED_ATTACHMENT_META_KEYS[0],
					self::GENERATED_ATTACHMENT_META_KEYS[1],
					self::BATCH_SIZE
				)
			);
			if ( ! is_array( $ids ) || '' !== $wpdb->last_error ) {
				self::add_error( $result, __( 'Generated attachments could not be queried.', 'worldgraph' ) );
				return;
			}

			foreach ( $ids as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				$cursor        = max( $cursor, $attachment_id );
				if ( wp_delete_attachment( $attachment_id, true ) ) {
					$result['attachments']++;
				} else {
					self::add_error( $result, sprintf( 'Could not delete generated attachment #%d.', $attachment_id ) );
				}
			}
		} while ( count( $ids ) === self::BATCH_SIZE );
	}

	/** Delete every plugin-namespaced post, including unregistered legacy types. */
	private static function purge_plugin_posts( array &$result ): void {
		global $wpdb;

		$cursor = 0;
		do {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- unregistered retired CPTs cannot be comprehensively selected with WP_Query.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE ID > %d AND ( post_type LIKE %s OR post_type LIKE %s )
					ORDER BY ID ASC LIMIT %d",
					$cursor,
					$wpdb->esc_like( self::DATA_PREFIXES[0] ) . '%',
					$wpdb->esc_like( self::DATA_PREFIXES[1] ) . '%',
					self::BATCH_SIZE
				)
			);
			if ( ! is_array( $ids ) || '' !== $wpdb->last_error ) {
				self::add_error( $result, __( 'World Graph Studio records could not be queried.', 'worldgraph' ) );
				return;
			}

			foreach ( $ids as $post_id ) {
				$post_id = absint( $post_id );
				$cursor  = max( $cursor, $post_id );
				if ( wp_delete_post( $post_id, true ) ) {
					$result['posts']++;
				} else {
					self::add_error( $result, sprintf( 'Could not delete World Graph Studio record #%d.', $post_id ) );
				}
			}
		} while ( count( $ids ) === self::BATCH_SIZE );
	}

	/** Remove all terms from current and historical plugin taxonomies. */
	private static function purge_taxonomy_terms( array &$result ): void {
		$taxonomies = self::known_taxonomies();
		foreach ( (array) get_taxonomies( [], 'names' ) as $taxonomy ) {
			if ( self::has_owned_prefix( (string) $taxonomy ) ) {
				$taxonomies[] = (string) $taxonomy;
			}
		}
		$taxonomies = array_values( array_unique( $taxonomies ) );

		foreach ( $taxonomies as $taxonomy ) {
			$temporary = false;
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$registered = register_taxonomy(
					$taxonomy,
					[],
					[
						'public'  => false,
						'show_ui' => false,
						'rewrite' => false,
					]
				);
				if ( is_wp_error( $registered ) ) {
					self::add_error( $result, sprintf( 'Could not prepare taxonomy %s for deletion.', $taxonomy ) );
					continue;
				}
				$temporary = true;
			}

			$excluded = [];
			do {
				$term_ids = get_terms(
					[
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'fields'     => 'ids',
						'number'     => self::BATCH_SIZE,
						'exclude'    => $excluded,
					]
				);
				if ( is_wp_error( $term_ids ) ) {
					self::add_error( $result, sprintf( 'Could not query taxonomy %s.', $taxonomy ) );
					break;
				}

				foreach ( $term_ids as $term_id ) {
					$term_id = absint( $term_id );
					$deleted = wp_delete_term( $term_id, $taxonomy );
					if ( is_wp_error( $deleted ) || false === $deleted ) {
						$excluded[] = $term_id;
						self::add_error( $result, sprintf( 'Could not delete term #%1$d from %2$s.', $term_id, $taxonomy ) );
					} else {
						$result['terms']++;
					}
				}
			} while ( count( $term_ids ) === self::BATCH_SIZE );

			if ( $temporary ) {
				unregister_taxonomy( $taxonomy );
			}
		}
	}

	/** Remove plugin options and all plugin-prefixed transient variants. */
	private static function purge_options( array &$result ): void {
		global $wpdb;

		$names = self::query_owned_option_names( $wpdb->options, 'option_name' );
		if ( is_wp_error( $names ) ) {
			self::add_error( $result, $names->get_error_message() );
			return;
		}
		foreach ( $names as $name ) {
			if ( delete_option( $name ) ) {
				$result['options']++;
				continue;
			}
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- verifies a failed Settings API deletion.
				$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name )
			);
			if ( $exists ) {
				self::add_error( $result, sprintf( 'Could not delete option %s.', $name ) );
			}
		}
	}

	/** Remove network-scoped plugin options for the current network, when present. */
	private static function purge_site_options( array &$result ): void {
		global $wpdb;

		if ( ! is_multisite() || empty( $wpdb->sitemeta ) ) {
			return;
		}

		$network_id = get_current_network_id();
		$names      = self::query_owned_option_names( $wpdb->sitemeta, 'meta_key', $network_id );
		if ( is_wp_error( $names ) ) {
			self::add_error( $result, $names->get_error_message() );
			return;
		}
		foreach ( $names as $name ) {
			if ( delete_site_option( $name ) ) {
				$result['site_options']++;
				continue;
			}
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- verifies a failed Settings API deletion.
				$wpdb->prepare( "SELECT meta_id FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1", $network_id, $name )
			);
			if ( $exists ) {
				self::add_error( $result, sprintf( 'Could not delete network option %s.', $name ) );
			}
		}
	}

	/**
	 * Select option names owned by the plugin.
	 *
	 * @param string $table      Trusted WordPress option table.
	 * @param string $name_column Trusted option-name column.
	 * @param int    $network_id Optional network ID for sitemeta.
	 * @return array<int, string>|\WP_Error
	 */
	private static function query_owned_option_names( string $table, string $name_column, int $network_id = 0 ): array {
		global $wpdb;

		$patterns = [];
		foreach ( self::DATA_PREFIXES as $prefix ) {
			foreach ( [ '', '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ] as $wrapper ) {
				$patterns[] = $wpdb->esc_like( $wrapper . $prefix ) . '%';
			}
		}

		$like_sql = implode( ' OR ', array_fill( 0, count( $patterns ), "{$name_column} LIKE %s" ) );
		$in_sql   = implode( ', ', array_fill( 0, count( self::EXACT_OPTIONS ), '%s' ) );
		$where    = "( {$like_sql} OR {$name_column} IN ( {$in_sql} ) )";
		$args     = array_merge( $patterns, self::EXACT_OPTIONS );
		if ( $network_id > 0 ) {
			$where  = "site_id = %d AND {$where}";
			$args   = array_merge( [ $network_id ], $args );
		}

		$sql   = $wpdb->prepare( "SELECT DISTINCT {$name_column} FROM {$table} WHERE {$where}", ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table, column, and condition fragments are internal constants.
		$names = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query was prepared above from allowlisted table/column identifiers; inventory is deleted through Settings APIs.
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'worldgraph_purge_option_query_failed', __( 'World Graph Studio options could not be queried.', 'worldgraph' ) );
		}

		return array_values( array_filter( array_map( 'strval', (array) $names ) ) );
	}

	/** Remove plugin-prefixed metadata even when it lives on an external record. */
	private static function purge_metadata( string $meta_type, array &$result, string $result_key ): void {
		global $wpdb;

		$tables = [
			'post' => $wpdb->postmeta,
			'term' => $wpdb->termmeta,
			'user' => $wpdb->usermeta,
		];
		if ( empty( $tables[ $meta_type ] ) ) {
			return;
		}

		$patterns = [];
		foreach ( self::DATA_PREFIXES as $prefix ) {
			$patterns[] = $wpdb->esc_like( $prefix ) . '%';
			$patterns[] = $wpdb->esc_like( '_' . $prefix ) . '%';
		}
		$where = implode( ' OR ', array_fill( 0, count( $patterns ), 'meta_key LIKE %s' ) );
		$table = $tables[ $meta_type ];
		$sql   = $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$table} WHERE {$where}", ...$patterns ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and conditions are internal constants.
		$keys  = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Query was prepared above; values are removed with Metadata API.
		if ( '' !== $wpdb->last_error ) {
			self::add_error( $result, sprintf( 'Could not query %s metadata.', $meta_type ) );
			return;
		}
		$keys = array_values( array_filter( array_map( 'strval', (array) $keys ) ) );

		foreach ( $keys as $key ) {
			$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- count reports the Metadata API result.
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key = %s", $key )
			);
			if ( delete_metadata( $meta_type, 0, $key, '', true ) ) {
				$result[ $result_key ] += $count;
				continue;
			}
			$remaining = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- verifies a failed Metadata API deletion.
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key = %s", $key )
			);
			if ( $remaining > 0 ) {
				self::add_error( $result, sprintf( 'Could not delete %1$s metadata key %2$s.', $meta_type, $key ) );
			}
		}
	}

	/** Remove this plugin's widget IDs without disturbing other sidebar widgets. */
	private static function purge_widget_assignments( array &$result ): void {
		$sidebars = get_option( 'sidebars_widgets', [] );
		if ( ! is_array( $sidebars ) ) {
			return;
		}

		$updated = $sidebars;
		$removed = 0;
		foreach ( $updated as $sidebar => $widgets ) {
			if ( ! is_array( $widgets ) ) {
				continue;
			}
			$kept = array_values(
				array_filter(
					$widgets,
					static function ( $widget_id ) use ( &$removed ): bool {
						$owned = is_string( $widget_id ) && ( str_starts_with( $widget_id, 'worldgraph_search-' ) || str_starts_with( $widget_id, 'storyos_search-' ) );
						if ( $owned ) {
							$removed++;
						}
						return ! $owned;
					}
				)
			);
			$updated[ $sidebar ] = $kept;
		}

		if ( 0 === $removed ) {
			return;
		}
		$written = update_option( 'sidebars_widgets', $updated );
		if ( false === $written && get_option( 'sidebars_widgets', [] ) !== $updated ) {
			self::add_error( $result, __( 'World Graph Studio widget assignments could not be removed.', 'worldgraph' ) );
			return;
		}
		$result['widget_assignments'] += $removed;
	}

	/** Delete the exact plugin-owned uploads directory through the WP filesystem API. */
	private static function purge_upload_artifacts( array &$result ): void {
		$uploads = wp_upload_dir( null, false );
		$basedir = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
		if ( '' === $basedir ) {
			self::add_error( $result, __( 'The WordPress uploads directory was unavailable.', 'worldgraph' ) );
			return;
		}

		$base   = untrailingslashit( wp_normalize_path( $basedir ) );
		$target = wp_normalize_path( $base . '/' . self::UPLOAD_SUBDIRECTORY );
		if ( $base !== wp_normalize_path( dirname( $target ) ) || self::UPLOAD_SUBDIRECTORY !== basename( $target ) ) {
			self::add_error( $result, __( 'The World Graph Studio uploads path failed its safety check.', 'worldgraph' ) );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			self::add_error( $result, __( 'WordPress could not initialize filesystem access for the generation log directory.', 'worldgraph' ) );
			return;
		}
		if ( ! $wp_filesystem->exists( $target ) ) {
			return;
		}

		$file_count = self::count_filesystem_files( $wp_filesystem->dirlist( $target, true, true ) );
		if ( ! $wp_filesystem->delete( $target, true, 'd' ) ) {
			self::add_error( $result, __( 'The World Graph Studio uploads directory could not be removed.', 'worldgraph' ) );
			return;
		}
		$result['files'] += $file_count;
	}

	/** Count files in the recursive shape returned by WP_Filesystem::dirlist(). */
	private static function count_filesystem_files( $entries ): int {
		if ( ! is_array( $entries ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( 'f' === ( $entry['type'] ?? '' ) ) {
				$count++;
			} elseif ( ! empty( $entry['files'] ) ) {
				$count += self::count_filesystem_files( $entry['files'] );
			}
		}
		return $count;
	}

	/** Whether a name begins with a reserved current or predecessor prefix. */
	private static function has_owned_prefix( string $name ): bool {
		foreach ( self::DATA_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/** Record a bounded set of diagnostics while retaining the full issue count. */
	private static function add_error( array &$result, string $message ): void {
		$result['error_count']++;
		if ( count( $result['errors'] ) < 20 ) {
			$result['errors'][] = $message;
		}
	}
}
