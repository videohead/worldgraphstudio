<?php
/**
 * Accessible drag-and-drop Shot ordering on the Scene edit screen.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scene Shot sequence editor.
 */
class Scene_Shot_Sequencer {

	/** AJAX action name. */
	private const ACTION = 'worldgraph_reorder_scene_shots';

	/** Register the Scene editor hooks. */
	public static function init(): void {
		add_action( 'add_meta_boxes_worldgraph_scene', [ __CLASS__, 'register_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'ajax_reorder' ] );
	}

	/** Register the ordering panel on Scene posts. */
	public static function register_meta_box(): void {
		add_meta_box(
			'worldgraph_scene_shot_sequence',
			__( 'Shot Sequence', 'worldgraph' ),
			[ __CLASS__, 'render_meta_box' ],
			'worldgraph_scene',
			'normal',
			'high'
		);
	}

	/**
	 * Load Sortable and the small controller only on Scene edit screens.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'worldgraph_scene' !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen routing only.
		if ( ! $post_id ) {
			return;
		}
		$shots = \WorldGraph\Utils\worldgraph_get_scene_shots_for_reorder( $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) || array_filter( $shots, static fn( \WP_Post $shot ): bool => ! current_user_can( 'edit_post', $shot->ID ) ) ) {
			return;
		}

		$script_path = WORLDGRAPH_PLUGIN_DIR . 'assets/js/scene-shot-sequencer.js';
		$style_path  = WORLDGRAPH_PLUGIN_DIR . 'assets/css/scene-shot-sequencer.css';
		wp_enqueue_style(
			'worldgraph-scene-shot-sequencer',
			WORLDGRAPH_PLUGIN_URL . 'assets/css/scene-shot-sequencer.css',
			[],
			is_file( $style_path ) ? (string) filemtime( $style_path ) : WORLDGRAPH_VERSION
		);
		wp_enqueue_script(
			'worldgraph-scene-shot-sequencer',
			WORLDGRAPH_PLUGIN_URL . 'assets/js/scene-shot-sequencer.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			is_file( $script_path ) ? (string) filemtime( $script_path ) : WORLDGRAPH_VERSION,
			true
		);
		wp_localize_script(
			'worldgraph-scene-shot-sequencer',
			'worldgraphSceneShots',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
				'nonce'   => wp_create_nonce( self::ACTION ),
				'sceneId' => $post_id,
				'revision' => \WorldGraph\Utils\worldgraph_scene_shot_order_revision( $shots ),
				'i18n'    => [
					'saving' => __( 'Saving Shot order…', 'worldgraph' ),
					'saved'  => __( 'Shot order saved.', 'worldgraph' ),
					'error'  => __( 'The Shot order could not be saved. The previous order has been restored.', 'worldgraph' ),
					'moveUp' => __( 'Move Shot up', 'worldgraph' ),
					'moveDown' => __( 'Move Shot down', 'worldgraph' ),
				],
			]
		);
	}

	/**
	 * Render the Scene Shot sequence.
	 *
	 * @param \WP_Post $post Current Scene.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		$shots         = \WorldGraph\Utils\worldgraph_get_scene_shots_for_reorder( $post->ID );
		$blocked_shots = array_values(
			array_filter(
				$shots,
				static fn( \WP_Post $shot ): bool => ! current_user_can( 'edit_post', $shot->ID )
			)
		);
		$blocked_labels = array_values(
			array_map(
				static fn( \WP_Post $shot ): string => $shot->post_title ?: sprintf(
					/* translators: %d: Shot post ID. */
					__( 'Shot #%d', 'worldgraph' ),
					$shot->ID
				),
				array_filter( $blocked_shots, static fn( \WP_Post $shot ): bool => current_user_can( 'read_post', $shot->ID ) )
			)
		);
		$hidden_blocked_count = count( $blocked_shots ) - count( $blocked_labels );
		if ( $hidden_blocked_count > 0 ) {
			$blocked_labels[] = sprintf(
				/* translators: %d: number of Shots whose titles are not visible to this editor. */
				_n( '%d restricted Shot', '%d restricted Shots', $hidden_blocked_count, 'worldgraph' ),
				$hidden_blocked_count
			);
		}
		?>
		<div class="worldgraph-shot-sequencer" data-worldgraph-shot-sequencer>
			<p class="description">
				<?php esc_html_e( 'Drag Shots into editorial order, or use the Move up and Move down buttons. Changes save immediately and are reflected in Scene displays.', 'worldgraph' ); ?>
			</p>
			<?php if ( ! current_user_can( 'edit_post', $post->ID ) || ! empty( $blocked_shots ) ) : ?>
				<p class="notice notice-warning inline">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma-delimited Shot titles. */
							__( 'Reordering is unavailable because you need edit access to the Scene and every Shot. Restricted: %s', 'worldgraph' ),
							implode( ', ', $blocked_labels ) ?: __( 'this Scene', 'worldgraph' )
						)
					);
					?>
				</p>
			<?php elseif ( empty( $shots ) ) : ?>
				<p class="worldgraph-shot-sequencer__empty">
					<?php esc_html_e( 'No Shots belong to this Scene yet. Assign this Scene in a Shot’s Scene field, then return here to order it.', 'worldgraph' ); ?>
				</p>
			<?php else : ?>
				<ol class="worldgraph-shot-sequencer__list" data-shot-list>
					<?php foreach ( $shots as $index => $shot ) : ?>
						<?php
						$shot_number = \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'shot_number' );
						$shot_type   = \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'shot_type' );
						$duration    = \WorldGraph\Utils\worldgraph_get_field_value( $shot->ID, 'duration' );
						?>
						<li class="worldgraph-shot-sequencer__item" data-shot-id="<?php echo esc_attr( (string) $shot->ID ); ?>">
							<span class="worldgraph-shot-sequencer__handle dashicons dashicons-move" aria-hidden="true"></span>
							<span class="worldgraph-shot-sequencer__position" data-shot-position><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
							<span class="worldgraph-shot-sequencer__details">
								<a href="<?php echo esc_url( get_edit_post_link( $shot->ID ) ?: '#' ); ?>"><strong><?php echo esc_html( $shot->post_title ?: sprintf(
									/* translators: %d: Shot post ID. */
									__( 'Shot #%d', 'worldgraph' ),
									$shot->ID
								) ); ?></strong></a>
								<span class="worldgraph-shot-sequencer__meta">
									<?php
									echo esc_html(
										implode(
											' · ',
											array_filter(
												[
													$shot_number ? sprintf(
														/* translators: %s: editorial Shot number. */
														__( 'Shot %s', 'worldgraph' ),
														$shot_number
													) : '',
													$shot_type ? ucwords( str_replace( '_', ' ', (string) $shot_type ) ) : '',
													$duration,
												]
											)
										)
									);
									?>
								</span>
							</span>
							<span class="worldgraph-shot-sequencer__actions">
								<button type="button" class="button-link" data-shot-move="up">
									<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Move Shot up', 'worldgraph' ); ?></span>
								</button>
								<button type="button" class="button-link" data-shot-move="down">
									<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Move Shot down', 'worldgraph' ); ?></span>
								</button>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<p class="worldgraph-shot-sequencer__status" data-shot-status role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	/** Persist one complete, Scene-scoped Shot order. */
	public static function ajax_reorder(): void {
		check_ajax_referer( self::ACTION, 'nonce' );

		$scene_id = isset( $_POST['scene_id'] ) ? absint( wp_unslash( $_POST['scene_id'] ) ) : 0;
		$raw_order = isset( $_POST['ordered_ids'] ) && is_array( $_POST['ordered_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ordered_ids'] ) ) : [];
		$revision  = isset( $_POST['revision'] ) ? sanitize_key( wp_unslash( $_POST['revision'] ) ) : '';
		$result    = \WorldGraph\Utils\worldgraph_reorder_scene_shots( $scene_id, $raw_order, 0, $revision );
		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) ? absint( $error_data['status'] ?? 500 ) : 500;
			wp_send_json_error( [ 'message' => $result->get_error_message() ], $status ?: 500 );
		}

		wp_send_json_success(
			[
				'ordered_ids' => $result['ordered_ids'],
				'revision'    => $result['revision'],
				'message'     => __( 'Shot order saved.', 'worldgraph' ),
			]
		);
	}
}
