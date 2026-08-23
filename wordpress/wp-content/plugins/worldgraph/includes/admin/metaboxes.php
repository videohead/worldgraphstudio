<?php
/**
 * Admin MetaBoxes for World Graph Studio.
 *
 * Provides Story Graph connection meta boxes for World Graph Studio CPTs.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

/**
 * MetaBoxes class.
 */
class MetaBoxes {

	/**
	 * Initialize the meta boxes.
	 *
	 * Featured images and supporting galleries are managed by the block editor;
	 * the dedicated asset metabox owns generation tools only.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ] );
	}

	/**
	 * Register meta boxes.
	 */
	public static function register_meta_boxes(): void {
		$cpts = array_keys( \WorldGraph\Utils\worldgraph_get_all_cpts() );

		foreach ( $cpts as $cpt ) {
			// SCF owns structured metadata controls and saving. World Graph Studio retains a
			// separate read-only view of canonical graph edges.
			add_meta_box(
				'worldgraph_graph',
				__( 'Graph Connections', 'worldgraph' ),
				[ __CLASS__, 'render_graph_box' ],
				$cpt,
				'side',
				'default'
			);

		}
	}

	/**
	 * Render the details meta box.
	 *
	 * @param \WP_Post $post
	 */
	public static function render_details_box( \WP_Post $post ): void {
		wp_nonce_field( 'worldgraph_details', 'worldgraph_details_nonce' );

		// Get all fields for this CPT.
		$fields = \WorldGraph\Utils\worldgraph_get_fields( $post->post_type );

		if ( empty( $fields ) ) {
			echo '<p>No fields defined for this post type.</p>';
			return;
		}

		$cpt_fields = $fields;

		?>
		<table class="form-table">
			<?php foreach ( $cpt_fields as $field_name => $field ) : ?>
				<?php
				if ( false === ( $field['admin_ui'] ?? true ) || \WorldGraph\Utils\worldgraph_should_exclude_from_details( $field_name, $field ) ) {
					continue;
				}

				$value = \WorldGraph\Utils\worldgraph_get_field_value( $post->ID, (string) $field['name'] );
				if ( empty( $value ) && isset( $field['default'] ) ) {
					$value = $field['default'];
				}
				?>
				<tr>
					<th><label for="<?php echo esc_attr( $field['name'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
					<td>
						<?php
						switch ( $field['type'] ) {
							case 'textarea':
								?>
								<textarea name="<?php echo esc_attr( $field['name'] ); ?>" id="<?php echo esc_attr( $field['name'] ); ?>" class="large-text" rows="3"><?php echo esc_textarea( $value ); ?></textarea>
								<?php
								if ( ! empty( $field['description'] ) ) {
									echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
								}
								break;

							case 'wysiwyg':
								wp_editor(
									$value,
									$field['name'],
									[
										'tinymce'  => true,
										'quicktags' => true,
										'editor_height' => 150,
									]
								);
								break;

							case 'select':
								?>
								<select name="<?php echo esc_attr( $field['name'] ); ?>" id="<?php echo esc_attr( $field['name'] ); ?>">
									<option value=""><?php echo esc_html( 'Select...' ); ?></option>
									<?php foreach ( (array) $field['options'] as $option_value => $option_label ) : ?>
										<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
											<?php echo esc_html( $option_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php
								if ( ! empty( $field['description'] ) ) {
									echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
								}
								break;

							case 'number':
								?>
								<input type="number" name="<?php echo esc_attr( $field['name'] ); ?>" id="<?php echo esc_attr( $field['name'] ); ?>" value="<?php echo esc_attr( $value ); ?>" class="small-text" />
								<?php
								if ( ! empty( $field['description'] ) ) {
									echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
								}
								break;

							case 'date':
								?>
								<input type="date" name="<?php echo esc_attr( $field['name'] ); ?>" id="<?php echo esc_attr( $field['name'] ); ?>" value="<?php echo esc_attr( $value ); ?>" />
								<?php
								if ( ! empty( $field['description'] ) ) {
									echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
								}
								break;

							case 'taxonomy':
								$taxonomy      = (string) ( $field['taxonomy'] ?? '' );
								$assigned_terms = get_the_terms( $post->ID, $taxonomy );
								$selected_term = ( $assigned_terms && ! is_wp_error( $assigned_terms ) ) ? (int) $assigned_terms[0]->term_id : 0;
								$terms         = get_terms(
									[
										'taxonomy'   => $taxonomy,
										'hide_empty' => false,
									]
								);
								?>
								<select name="<?php echo esc_attr( $field['name'] ); ?>" id="<?php echo esc_attr( $field['name'] ); ?>" <?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>>
									<option value=""><?php esc_html_e( 'Select...', 'worldgraph' ); ?></option>
									<?php if ( ! is_wp_error( $terms ) ) : ?>
										<?php foreach ( $terms as $term ) : ?>
											<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $selected_term, $term->term_id ); ?>>
												<?php echo esc_html( $term->name ); ?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>
								<?php
								if ( ! empty( $field['description'] ) ) {
									echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
								}
								break;

							case 'relationship':
								$selected_id  = 0;
								$relationships = \WorldGraph\Utils\get_relationships( $post->ID, $post->post_type, 'outgoing' );
								foreach ( $relationships as $relationship ) {
									if ( (string) ( $relationship['to_type'] ?? '' ) !== (string) ( $field['related_cpt'] ?? '' ) ) {
										continue;
									}

									$relationship_field = (string) ( $relationship['metadata']['field'] ?? '' );
									if ( '' === $relationship_field || $field_name === $relationship_field ) {
										$selected_id = (int) $relationship['to_id'];
										break;
									}
								}

								$relationship_query = array_replace_recursive(
									[
										'post_type'      => (string) ( $field['related_cpt'] ?? '' ),
										'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
										'posts_per_page' => -1,
										'orderby'        => 'title',
										'order'          => 'ASC',
									],
									(array) ( $field['query_args'] ?? [] )
								);
								$related_posts = get_posts( $relationship_query );
								?>
								<select name="<?php echo esc_attr( $field['name'] ); ?>" id="<?php echo esc_attr( $field['name'] ); ?>" <?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>>
									<option value=""><?php esc_html_e( 'None', 'worldgraph' ); ?></option>
									<?php foreach ( $related_posts as $related_post ) : ?>
										<option value="<?php echo esc_attr( $related_post->ID ); ?>" <?php selected( $selected_id, $related_post->ID ); ?>>
											<?php
											echo esc_html( $related_post->post_title ?: sprintf(
												/* translators: %d: related post ID. */
												__( 'Untitled #%d', 'worldgraph' ),
												$related_post->ID
											) );
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php
								if ( ! empty( $field['description'] ) ) {
									echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
								}
								break;

							default:
								?>
								<input type="text" name="<?php echo esc_attr( $field['name'] ); ?>" id="<?php echo esc_attr( $field['name'] ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
								<?php
								if ( ! empty( $field['description'] ) ) {
									echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
								}
								break;
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Render the graph connections meta box.
	 *
	 * @param \WP_Post $post
	 */
	public static function render_graph_box( \WP_Post $post ): void {
		$relationships = \WorldGraph\Utils\get_relationships( $post->ID );

		if ( empty( $relationships ) ) {
			echo '<p>' . esc_html( 'No connections yet.' ) . '</p>';
			return;
		}

		?>
		<ul class="list-inside">
			<?php foreach ( $relationships as $rel ) : ?>
				<li>
					<?php
					$target = get_post( $rel['to_id'] );
					if ( $target ) :
						?>
						<a href="<?php echo esc_url( get_edit_post_link( $rel['to_id'] ) ); ?>">
							<?php echo esc_html( $target->post_title ); ?>
						</a>
						<span class="dashicons dashicons-arrow-right-alt"></span>
						<small><?php echo esc_html( $rel['type'] ); ?></small>
					<?php else : ?>
						<small><?php echo esc_html( 'Deleted' ); ?></small>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
