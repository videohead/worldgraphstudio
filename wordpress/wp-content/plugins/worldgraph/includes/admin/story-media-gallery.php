<?php
/**
 * Media-library gallery editor for Story Graph posts.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Manage the ordered supporting-media gallery used by story displays. */
class Story_Media_Gallery {

	/** Stable gallery metadata key shared with the generation system. */
	private const META_KEY = '_worldgraph_asset_gallery_ids';

	/** Prefix for one-shot concurrent-edit notices, scoped by user and post. */
	private const CONFLICT_NOTICE_PREFIX = 'worldgraph_story_gallery_conflict_';

	/** Post types that may own supporting display media. */
	private const POST_TYPES = [
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

	/** Register editor hooks. */
	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'save_post', [ __CLASS__, 'save' ], 20, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'render_conflict_notice' ] );
	}

	/** Show a one-shot notice when two editors changed gallery order at once. */
	public static function render_conflict_notice(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only one-shot notice lookup.
		$user_id = get_current_user_id();
		if ( ! $post_id || ! $user_id ) {
			return;
		}

		$key = self::CONFLICT_NOTICE_PREFIX . $user_id . '_' . $post_id;
		if ( ! get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );
		?>
		<div class="notice notice-warning is-dismissible"><p>
			<?php esc_html_e( 'Story Media Gallery order was not saved because another editor reordered it after this screen loaded. Review the current order and save again.', 'worldgraph' ); ?>
		</p></div>
		<?php
	}

	/** Add the same media-curation panel to supported story entities. */
	public static function register_meta_boxes(): void {
		foreach ( self::POST_TYPES as $post_type ) {
			add_meta_box(
				'worldgraph_story_media_gallery',
				__( 'Story Media Gallery', 'worldgraph' ),
				[ __CLASS__, 'render' ],
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Load WordPress's media frame and the gallery controller on edit screens.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::POST_TYPES, true ) ) {
			return;
		}

		wp_enqueue_media();
		$script_path = WORLDGRAPH_PLUGIN_DIR . 'assets/js/story-media-gallery.js';
		$style_path  = WORLDGRAPH_PLUGIN_DIR . 'assets/css/story-media-gallery.css';
		wp_enqueue_style(
			'worldgraph-story-media-gallery',
			WORLDGRAPH_PLUGIN_URL . 'assets/css/story-media-gallery.css',
			[],
			is_file( $style_path ) ? (string) filemtime( $style_path ) : WORLDGRAPH_VERSION
		);
		wp_enqueue_script(
			'worldgraph-story-media-gallery',
			WORLDGRAPH_PLUGIN_URL . 'assets/js/story-media-gallery.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			is_file( $script_path ) ? (string) filemtime( $script_path ) : WORLDGRAPH_VERSION,
			true
		);
		wp_localize_script(
			'worldgraph-story-media-gallery',
			'worldgraphStoryGallery',
			[
				'title'  => __( 'Choose story media', 'worldgraph' ),
				'button' => __( 'Add to story gallery', 'worldgraph' ),
				'remove' => __( 'Remove from gallery', 'worldgraph' ),
				'moveUp' => __( 'Move media up', 'worldgraph' ),
				'moveDown' => __( 'Move media down', 'worldgraph' ),
				'audio'  => __( 'Audio', 'worldgraph' ),
				'video'  => __( 'Video', 'worldgraph' ),
				'file'   => __( 'Media', 'worldgraph' ),
			]
		);
	}

	/**
	 * Render the ordered gallery editor.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render( \WP_Post $post ): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to manage story media.', 'worldgraph' ) . '</p>';
			return;
		}

		wp_nonce_field( 'worldgraph_story_media_gallery', 'worldgraph_story_media_gallery_nonce' );
		$attachment_ids = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, self::META_KEY, true ) ) ) );
		?>
		<div class="worldgraph-story-gallery" data-worldgraph-story-gallery>
			<p class="description">
				<?php esc_html_e( 'Build a gallery of additional supporting images, audio, or video. Featured media remains the primary image.', 'worldgraph' ); ?>
			</p>
			<input type="hidden" name="worldgraph_story_media_gallery_ids" value="<?php echo esc_attr( implode( ',', $attachment_ids ) ); ?>" data-gallery-input />
			<input type="hidden" name="worldgraph_story_media_gallery_original_ids" value="<?php echo esc_attr( implode( ',', $attachment_ids ) ); ?>" />
			<input type="hidden" name="worldgraph_story_media_gallery_revision" value="<?php echo esc_attr( hash( 'sha256', implode( ',', $attachment_ids ) ) ); ?>" />
			<ul class="worldgraph-story-gallery__items" data-gallery-items>
				<?php foreach ( $attachment_ids as $attachment_id ) : ?>
					<?php
					$attachment = get_post( $attachment_id );
					if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
						continue;
					}
					$can_read_attachment = current_user_can( 'read_post', $attachment_id );
					$mime_type           = $can_read_attachment ? (string) get_post_mime_type( $attachment_id ) : '';
					$attachment_label    = $can_read_attachment ? get_the_title( $attachment_id ) : __( 'Restricted media', 'worldgraph' );
					?>
					<li class="worldgraph-story-gallery__item" data-attachment-id="<?php echo esc_attr( (string) $attachment_id ); ?>">
						<span class="worldgraph-story-gallery__handle dashicons dashicons-move" aria-hidden="true"></span>
						<span class="worldgraph-story-gallery__preview">
							<?php if ( $can_read_attachment && 0 === strpos( $mime_type, 'image/' ) ) : ?>
								<?php echo wp_get_attachment_image( $attachment_id, 'thumbnail', true, [ 'alt' => '' ] ); ?>
							<?php else : ?>
								<span class="dashicons <?php echo esc_attr( 0 === strpos( $mime_type, 'audio/' ) ? 'dashicons-format-audio' : ( 0 === strpos( $mime_type, 'video/' ) ? 'dashicons-format-video' : 'dashicons-lock' ) ); ?>" aria-hidden="true"></span>
							<?php endif; ?>
						</span>
						<span class="worldgraph-story-gallery__details">
							<strong><?php echo esc_html( $attachment_label ); ?></strong>
							<small><?php echo esc_html( $mime_type ); ?></small>
						</span>
						<span class="worldgraph-story-gallery__actions">
							<button type="button" class="button-link" data-gallery-move="up">
								<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Move media up', 'worldgraph' ); ?></span>
							</button>
							<button type="button" class="button-link" data-gallery-move="down">
								<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Move media down', 'worldgraph' ); ?></span>
							</button>
							<button type="button" class="button-link-delete" data-gallery-remove>
								<?php esc_html_e( 'Remove', 'worldgraph' ); ?>
								<span class="screen-reader-text"> <?php echo esc_html( $attachment_label ); ?></span>
							</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="worldgraph-story-gallery__empty" data-gallery-empty <?php echo empty( $attachment_ids ) ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'No supporting media selected.', 'worldgraph' ); ?>
			</p>
			<button type="button" class="button" data-gallery-add><?php esc_html_e( 'Add or choose media', 'worldgraph' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Persist the ordered attachment IDs with the post edit.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Saved post.
	 */
	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return;
		}
		if ( ! isset( $_POST['worldgraph_story_media_gallery_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['worldgraph_story_media_gallery_nonce'] ) ), 'worldgraph_story_media_gallery' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$raw_ids      = isset( $_POST['worldgraph_story_media_gallery_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['worldgraph_story_media_gallery_ids'] ) ) : '';
		$raw_original = isset( $_POST['worldgraph_story_media_gallery_original_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['worldgraph_story_media_gallery_original_ids'] ) ) : '';
		$revision     = isset( $_POST['worldgraph_story_media_gallery_revision'] ) ? sanitize_text_field( wp_unslash( $_POST['worldgraph_story_media_gallery_revision'] ) ) : '';
		$ids          = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) ) );
		$original_ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw_original ) ) ) ) );
		$current_ids  = array_values( array_unique( array_filter( array_map( 'absint', (array) get_post_meta( $post_id, self::META_KEY, true ) ) ) ) );
		$current_revision = hash( 'sha256', implode( ',', $current_ids ) );

		// Preserve media appended by an async generator or another editor after
		// this edit screen loaded, while still honoring intentional removals and
		// additions made in the submitted gallery.
		if ( $revision && ! hash_equals( $current_revision, $revision ) ) {
			$submitted_original_order = array_values( array_intersect( $ids, $original_ids ) );
			$original_for_submit      = array_values( array_intersect( $original_ids, $ids ) );
			$current_original_order   = array_values( array_intersect( $current_ids, $original_ids ) );
			$original_for_current     = array_values( array_intersect( $original_ids, $current_ids ) );
			$user_reordered           = $submitted_original_order !== $original_for_submit;
			$concurrent_reordered     = $current_original_order !== $original_for_current;

			$shared_original_ids = array_values( array_intersect( $original_ids, $ids, $current_ids ) );
			$user_shared_order   = array_values( array_intersect( $ids, $shared_original_ids ) );
			$current_shared_order = array_values( array_intersect( $current_ids, $shared_original_ids ) );
			if ( $user_reordered && $concurrent_reordered && $user_shared_order !== $current_shared_order ) {
				set_transient( self::CONFLICT_NOTICE_PREFIX . get_current_user_id() . '_' . $post_id, 1, MINUTE_IN_SECONDS );
				return;
			}

			$user_additions = array_values( array_diff( $ids, $original_ids ) );
			if ( ! $user_reordered ) {
				// The submitter did not change relative order, so preserve another
				// editor's current ordering while honoring removals and new media.
				$retained_current = array_values(
					array_filter(
						$current_ids,
						static function( int $attachment_id ) use ( $ids, $original_ids ): bool {
							return in_array( $attachment_id, $ids, true ) || ! in_array( $attachment_id, $original_ids, true );
						}
					)
				);
				$ids = array_values( array_unique( array_merge( $retained_current, $user_additions ) ) );
			} else {
				$retained_current     = array_values( array_intersect( $ids, $current_ids ) );
				$concurrent_additions = array_values( array_diff( $current_ids, $original_ids ) );
				$ids                  = array_values( array_unique( array_merge( $retained_current, $user_additions, $concurrent_additions ) ) );
			}
		}

		$ids     = array_values(
			array_filter(
				$ids,
				static function( int $attachment_id ) use ( $current_ids ): bool {
					if ( 'attachment' !== get_post_type( $attachment_id ) || ( ! in_array( $attachment_id, $current_ids, true ) && ! current_user_can( 'edit_post', $attachment_id ) ) ) {
						return false;
					}
					$mime_type = (string) get_post_mime_type( $attachment_id );
					return 0 === strpos( $mime_type, 'image/' ) || 0 === strpos( $mime_type, 'audio/' ) || 0 === strpos( $mime_type, 'video/' );
				}
			)
		);

		if ( empty( $ids ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		update_post_meta( $post_id, self::META_KEY, $ids );
	}
}
