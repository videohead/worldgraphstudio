<?php
/**
 * Plugin Name: World Graph Studio - Headless Revalidation
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Notifies an optional headless Next.js frontend (see /headless, based on 9d8dev/next-wp) to revalidate its cache when content changes.
 * Version: 1.1.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package WorldGraphHeadless
 */

namespace WorldGraphHeadless;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WORLDGRAPH_HEADLESS_VERSION', '1.1.0' );
define( 'WORLDGRAPH_HEADLESS_OPTION', 'worldgraph_headless_settings' );
define( 'WORLDGRAPH_HEADLESS_NOTICE', 'worldgraph_headless_revalidation_failure' );

/**
 * Whether headless revalidation is enabled (requires both a site URL and secret).
 *
 * @return bool
 */
function is_enabled(): bool {
	$settings = get_headless_settings();
	return ! empty( $settings['next_url'] ) && ! empty( $settings['webhook_secret'] );
}

/**
 * Stored settings with defaults.
 *
 * @return array{next_url: string, webhook_secret: string, notifications: bool}
 */
function get_headless_settings(): array {
	$defaults = [
		'next_url'       => '',
		'webhook_secret' => '',
		'notifications'  => false,
	];

	return wp_parse_args( get_option( WORLDGRAPH_HEADLESS_OPTION, [] ), $defaults );
}

/**
 * Initialize the module.
 */
function init(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\add_settings_page' );
	add_action( 'admin_init', __NAMESPACE__ . '\\register_settings' );
	add_action( 'admin_notices', __NAMESPACE__ . '\\render_failure_notice' );

	if ( ! is_enabled() ) {
		return;
	}

	add_action( 'transition_post_status', __NAMESPACE__ . '\\on_post_status_transition', 10, 3 );
	add_action( 'save_post', __NAMESPACE__ . '\\on_story_post_saved', 100, 3 );
	add_action( 'delete_post', __NAMESPACE__ . '\\on_post_deleted' );
	add_action( 'add_attachment', __NAMESPACE__ . '\\on_attachment_changed' );
	add_action( 'edit_attachment', __NAMESPACE__ . '\\on_attachment_changed' );
	add_action( 'delete_attachment', __NAMESPACE__ . '\\on_attachment_changed' );
	add_action( 'added_post_meta', __NAMESPACE__ . '\\on_story_display_meta_changed', 100, 3 );
	add_action( 'updated_post_meta', __NAMESPACE__ . '\\on_story_display_meta_changed', 100, 3 );
	add_action( 'deleted_post_meta', __NAMESPACE__ . '\\on_story_display_meta_changed', 100, 3 );
	add_action( 'created_term', __NAMESPACE__ . '\\on_story_term_changed', 100, 3 );
	add_action( 'edited_term', __NAMESPACE__ . '\\on_story_term_changed', 100, 3 );
	add_action( 'delete_term', __NAMESPACE__ . '\\on_story_term_changed', 100, 3 );
	add_action( 'set_object_terms', __NAMESPACE__ . '\\on_story_object_terms_set', 100, 4 );
	add_action( 'created_category', __NAMESPACE__ . '\\on_category_changed' );
	add_action( 'edited_category', __NAMESPACE__ . '\\on_category_changed' );
	add_action( 'delete_category', __NAMESPACE__ . '\\on_category_changed' );
	add_action( 'created_post_tag', __NAMESPACE__ . '\\on_tag_changed' );
	add_action( 'edited_post_tag', __NAMESPACE__ . '\\on_tag_changed' );
	add_action( 'delete_post_tag', __NAMESPACE__ . '\\on_tag_changed' );
}

/**
 * Register the "Settings > Headless Revalidation" admin page.
 */
function add_settings_page(): void {
	add_options_page(
		__( 'Headless Revalidation', 'worldgraph' ),
		__( 'Headless Revalidation', 'worldgraph' ),
		'manage_options',
		'worldgraph-headless',
		__NAMESPACE__ . '\\render_settings_page'
	);
}

/**
 * Register the settings field with the Settings API.
 */
function register_settings(): void {
	register_setting(
		'worldgraph_headless',
		WORLDGRAPH_HEADLESS_OPTION,
		[ 'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings' ]
	);
}

/**
 * Sanitize submitted settings.
 *
 * @param array<string, mixed> $input Raw submitted values.
 * @return array<string, mixed>
 */
function sanitize_settings( array $input ): array {
	return [
		'next_url'       => isset( $input['next_url'] ) ? untrailingslashit( esc_url_raw( $input['next_url'] ) ) : '',
		'webhook_secret' => isset( $input['webhook_secret'] ) ? sanitize_text_field( $input['webhook_secret'] ) : '',
		'notifications'  => ! empty( $input['notifications'] ),
	];
}

/**
 * Render the settings page markup.
 */
function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = get_headless_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Headless Revalidation', 'worldgraph' ); ?></h1>
		<p>
			<?php esc_html_e( 'Configure the optional headless Next.js frontend (see the /headless directory, based on 9d8dev/next-wp) so WordPress can tell it to refresh its cache.', 'worldgraph' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'worldgraph_headless' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="worldgraph_headless_next_url"><?php esc_html_e( 'Next.js Site URL', 'worldgraph' ); ?></label>
					</th>
					<td>
						<input
							type="url"
							id="worldgraph_headless_next_url"
							name="<?php echo esc_attr( WORLDGRAPH_HEADLESS_OPTION ); ?>[next_url]"
							value="<?php echo esc_attr( $settings['next_url'] ); ?>"
							class="regular-text"
							placeholder="https://your-nextjs-site.com"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="worldgraph_headless_secret"><?php esc_html_e( 'Webhook Secret', 'worldgraph' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="worldgraph_headless_secret"
							name="<?php echo esc_attr( WORLDGRAPH_HEADLESS_OPTION ); ?>[webhook_secret]"
							value="<?php echo esc_attr( \WorldGraph\Utils\Credential_Store::masked_value( $settings['webhook_secret'] ) ); ?>"
							class="regular-text"
							autocomplete="new-password"
						/>
						<p class="description">
							<?php esc_html_e( 'Must match WORDPRESS_WEBHOOK_SECRET in the headless app\'s .env.local. It is encrypted in the WordPress database; leave the masked value unchanged to keep it.', 'worldgraph' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin Notifications', 'worldgraph' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( WORLDGRAPH_HEADLESS_OPTION ); ?>[notifications]"
								value="1"
								<?php checked( $settings['notifications'] ); ?>
							/>
							<?php esc_html_e( 'Show an admin notice if a revalidation request fails.', 'worldgraph' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/** Show the most recent webhook failure when notifications are enabled. */
function render_failure_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$message = get_transient( WORLDGRAPH_HEADLESS_NOTICE );
	if ( ! is_string( $message ) || '' === $message ) {
		return;
	}
	delete_transient( WORLDGRAPH_HEADLESS_NOTICE );
	?>
	<div class="notice notice-error is-dismissible"><p>
		<?php echo esc_html( $message ); ?>
	</p></div>
	<?php
}

/**
 * Log a webhook failure and optionally surface it to administrators.
 *
 * @param string               $message  Human-readable failure.
 * @param array<string, mixed> $settings Module settings.
 */
function record_failure( string $message, array $settings ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Failures are logged only when site debugging is explicitly enabled.
		error_log( '[worldgraph-headless] ' . $message );
	}
	if ( ! empty( $settings['notifications'] ) ) {
		set_transient( WORLDGRAPH_HEADLESS_NOTICE, $message, MINUTE_IN_SECONDS );
	}
}

/**
 * Whether one exact private-network target is explicitly allowed for local development.
 *
 * Lando's two known headless hostnames are enabled only inside a Lando service.
 * Other local stacks may define WORLDGRAPH_HEADLESS_LOCAL_HOSTS as an array or
 * comma-delimited list of exact hostnames in wp-config.php.
 *
 * @param string $url Candidate webhook URL.
 * @return bool
 */
function is_allowed_local_revalidation_target( string $url ): bool {
	$host = strtolower( rtrim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) );
	if ( '' === $host || ! in_array( (string) wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ) {
		return false;
	}

	$allowed_hosts = [];
	if ( 'ON' === getenv( 'LANDO' ) || false !== getenv( 'LANDO_INFO' ) ) {
		$allowed_hosts = [ 'headless', 'headless.worldgraph.lndo.site' ];
	}
	if ( defined( 'WORLDGRAPH_HEADLESS_LOCAL_HOSTS' ) ) {
		$configured_hosts = constant( 'WORLDGRAPH_HEADLESS_LOCAL_HOSTS' );
		if ( is_string( $configured_hosts ) ) {
			$configured_hosts = preg_split( '/\s*,\s*/', $configured_hosts, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( is_array( $configured_hosts ) ) {
			$allowed_hosts = array_merge( $allowed_hosts, $configured_hosts );
		}
	}

	$allowed_hosts = array_values(
		array_unique(
			array_filter(
				array_map(
					static function( $allowed_host ): string {
						return strtolower( rtrim( sanitize_text_field( (string) $allowed_host ), '.' ) );
					},
					$allowed_hosts
				)
			)
		)
	);

	return in_array( $host, $allowed_hosts, true );
}

/**
 * Send the revalidation webhook to the headless frontend.
 *
 * @param string          $content_type Content type slug, e.g. "posts", "pages", "story".
 * @param int|string|null $content_id   Numeric ID or slug of the affected content.
 * @param string|null     $slug         Optional slug, sent alongside the ID.
 * @param string|null     $story_type   Optional plural Story route key.
 */
function send_webhook( string $content_type, $content_id = null, ?string $slug = null, ?string $story_type = null ): void {
	$settings = get_headless_settings();

	if ( empty( $settings['next_url'] ) || empty( $settings['webhook_secret'] ) ) {
		return;
	}

	$payload = [
		'contentType' => $content_type,
		'contentId'   => $content_id,
		'slug'        => $slug,
	];
	if ( $story_type ) {
		$payload['storyType'] = $story_type;
	}

	$webhook_url = untrailingslashit( $settings['next_url'] ) . '/api/revalidate';
	$local_target = is_allowed_local_revalidation_target( $webhook_url );
	$host_filter  = static function( bool $external, string $host, string $url ) use ( $local_target, $webhook_url ): bool {
		$target_host = strtolower( rtrim( (string) wp_parse_url( $webhook_url, PHP_URL_HOST ), '.' ) );
		return $local_target && $webhook_url === $url && $target_host === strtolower( rtrim( $host, '.' ) ) ? true : $external;
	};
	$port_filter  = static function( array $allowed_ports, string $host, string $url ) use ( $local_target, $webhook_url ): array {
		$target_host = strtolower( rtrim( (string) wp_parse_url( $webhook_url, PHP_URL_HOST ), '.' ) );
		$target_port = absint( wp_parse_url( $webhook_url, PHP_URL_PORT ) );
		if ( $local_target && $target_port && $webhook_url === $url && $target_host === strtolower( rtrim( $host, '.' ) ) ) {
			$allowed_ports[] = $target_port;
		}
		return array_values( array_unique( $allowed_ports ) );
	};

	if ( $local_target ) {
		add_filter( 'http_request_host_is_external', $host_filter, PHP_INT_MAX, 3 );
		add_filter( 'http_allowed_safe_ports', $port_filter, PHP_INT_MAX, 3 );
	}
	try {
		$response = wp_safe_remote_post(
			$webhook_url,
			[
				'timeout' => 10,
				'headers' => [
					'Content-Type'      => 'application/json',
					'X-Webhook-Secret'  => $settings['webhook_secret'],
				],
				'body'    => wp_json_encode( $payload ),
			]
		);
	} finally {
		if ( $local_target ) {
			remove_filter( 'http_request_host_is_external', $host_filter, PHP_INT_MAX );
			remove_filter( 'http_allowed_safe_ports', $port_filter, PHP_INT_MAX );
		}
	}

	if ( is_wp_error( $response ) ) {
		record_failure( 'Revalidation webhook failed: ' . $response->get_error_message(), $settings );
		return;
	}

	$status = wp_remote_retrieve_response_code( $response );
	if ( $status >= 400 ) {
		record_failure( 'Revalidation webhook returned HTTP ' . $status, $settings );
	}
}

/**
 * Resolve a public Story route key from its WordPress post type.
 *
 * @param string $post_type WordPress post type.
 * @return string|null
 */
function story_route_type( string $post_type ): ?string {
	$routes = [
		'worldgraph_project'   => 'projects',
		'worldgraph_world'     => 'worlds',
		'worldgraph_character' => 'characters',
		'worldgraph_scene'     => 'scenes',
		'worldgraph_prop'      => 'props',
		'worldgraph_sound'     => 'sounds',
	];

	return $routes[ $post_type ] ?? null;
}

/**
 * Whether a post can affect one of the public Story views.
 *
 * Supporting graph entities invalidate the broad `story` tag because they can
 * change Scene media, World counts, or Project analytics without owning a route.
 *
 * @param string $post_type WordPress post type.
 * @return bool
 */
function is_story_display_dependency( string $post_type ): bool {
	return in_array(
		$post_type,
		[
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
			'attachment',
		],
		true
	);
}

/**
 * Queue Story invalidations until WordPress has finished saving all metadata.
 *
 * @param int  $post_id Story or supporting post ID.
 * @param bool $force   Queue an unpublish transition whose current status is private.
 */
function queue_story_revalidation( int $post_id, bool $force = false ): void {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! is_story_display_dependency( $post->post_type ) ) {
		return;
	}
	if ( ! $force && 'publish' !== $post->post_status && ! ( 'attachment' === $post->post_type && 'inherit' === $post->post_status ) ) {
		return;
	}

	if ( ! isset( $GLOBALS['worldgraph_headless_story_queue'] ) || ! is_array( $GLOBALS['worldgraph_headless_story_queue'] ) ) {
		$GLOBALS['worldgraph_headless_story_queue'] = [];
		add_action( 'shutdown', __NAMESPACE__ . '\\flush_story_revalidation_queue', 20 );
	}

	$story_type = story_route_type( $post->post_type );
	$key        = $story_type ? $story_type . ':' . $post_id : 'story';
	$GLOBALS['worldgraph_headless_story_queue'][ $key ] = [
		'id'         => $post_id,
		'slug'       => $post->post_name,
		'story_type' => $story_type,
	];
}

/** Queue one broad Story invalidation when no single route owns the change. */
function queue_broad_story_revalidation(): void {
	if ( ! isset( $GLOBALS['worldgraph_headless_story_queue'] ) || ! is_array( $GLOBALS['worldgraph_headless_story_queue'] ) ) {
		$GLOBALS['worldgraph_headless_story_queue'] = [];
		add_action( 'shutdown', __NAMESPACE__ . '\\flush_story_revalidation_queue', 20 );
	}

	$GLOBALS['worldgraph_headless_story_queue']['story'] = [
		'id'         => null,
		'slug'       => null,
		'story_type' => null,
	];
}

/** Send each queued Story invalidation once after the current save completes. */
function flush_story_revalidation_queue(): void {
	$queue = $GLOBALS['worldgraph_headless_story_queue'] ?? [];
	unset( $GLOBALS['worldgraph_headless_story_queue'] );

	if ( isset( $queue['story'] ) || count( $queue ) > 1 ) {
		send_webhook( 'story' );
		return;
	}

	foreach ( $queue as $item ) {
		send_webhook( 'story', $item['id'], $item['slug'], $item['story_type'] );
	}
}

/**
 * Revalidate published Story records and their presentation dependencies.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    Post object.
 * @param bool     $update  Whether this is an update.
 */
function on_story_post_saved( int $post_id, \WP_Post $post, bool $update ): void {
	unset( $update );
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	queue_story_revalidation( $post->ID );
}

/**
 * Catch presentation changes made directly through metadata APIs.
 *
 * @param int|array<int, int> $meta_id Metadata row ID, or IDs after deletion.
 * @param int    $post_id  Post ID.
 * @param string $meta_key Metadata key.
 */
function on_story_display_meta_changed( int|array $meta_id, int $post_id, string $meta_key ): void {
	unset( $meta_id );
	if ( in_array( $meta_key, [ '_thumbnail_id', '_worldgraph_asset_gallery_ids', '_worldgraph_gen_intent', '_wp_attachment_image_alt', '_wp_attached_file', '_wp_attachment_metadata', 'storage_uri', 'worldgraph_relationships', 'production_stage' ], true ) ) {
		queue_story_revalidation( $post_id );
	}
}

/**
 * Invalidate media projections when WordPress creates, regenerates, or removes
 * an attachment. These lifecycle hooks cover changes that do not consistently
 * pass through the generic post-save callback.
 *
 * @param int $post_id Attachment post ID.
 */
function on_attachment_changed( int $post_id ): void {
	queue_story_revalidation( $post_id, true );
}

/**
 * Invalidate Story displays when a World Graph taxonomy label changes.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term-taxonomy ID.
 * @param string $taxonomy Taxonomy name.
 */
function on_story_term_changed( int $term_id, int $tt_id, string $taxonomy ): void {
	unset( $term_id, $tt_id );
	if ( str_starts_with( $taxonomy, 'worldgraph_' ) ) {
		queue_broad_story_revalidation();
	}
}

/**
 * Invalidate an item's route when its Story taxonomy assignments change.
 *
 * @param int          $object_id Object ID.
 * @param array|string $terms     Submitted terms.
 * @param array<int>   $tt_ids    Resulting term-taxonomy IDs.
 * @param string       $taxonomy  Taxonomy name.
 */
function on_story_object_terms_set( int $object_id, $terms, array $tt_ids, string $taxonomy ): void {
	unset( $terms, $tt_ids );
	if ( str_starts_with( $taxonomy, 'worldgraph_' ) ) {
		queue_story_revalidation( $object_id );
	}
}

/**
 * Fire on post/page publish, update, or unpublish transitions.
 *
 * @param string    $new_status New post status.
 * @param string    $old_status Previous post status.
 * @param \WP_Post  $post       Post object.
 */
function on_post_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
	if ( is_story_display_dependency( $post->post_type ) ) {
		if ( $new_status !== $old_status && array_intersect( [ $new_status, $old_status ], [ 'publish', 'inherit' ] ) ) {
			if ( in_array( $new_status, [ 'publish', 'inherit' ], true ) ) {
				queue_story_revalidation( $post->ID, true );
			} else {
				// The private record's current slug is no longer needed externally;
				// the broad tag removes its previously public representation.
				queue_broad_story_revalidation();
			}
		}
		return;
	}

	if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
		return;
	}

	if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
		return;
	}

	$content_type = 'page' === $post->post_type ? 'pages' : 'posts';
	send_webhook( $content_type, $post->ID, $post->post_name );
}

/**
 * Fire when a post/page is deleted outright.
 *
 * @param int $post_id Deleted post ID.
 */
function on_post_deleted( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return;
	}
	if ( is_story_display_dependency( $post->post_type ) ) {
		queue_story_revalidation( $post_id );
		return;
	}
	if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
		return;
	}

	$content_type = 'page' === $post->post_type ? 'pages' : 'posts';
	send_webhook( $content_type, $post_id, $post->post_name );
}

/**
 * Fire on category create/update/delete.
 *
 * @param int $term_id Term ID.
 */
function on_category_changed( int $term_id ): void {
	send_webhook( 'categories', $term_id );
}

/**
 * Fire on tag create/update/delete.
 *
 * @param int $term_id Term ID.
 */
function on_tag_changed( int $term_id ): void {
	send_webhook( 'tags', $term_id );
}

if ( did_action( 'plugins_loaded' ) ) {
	init();
} else {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\init' );
}
