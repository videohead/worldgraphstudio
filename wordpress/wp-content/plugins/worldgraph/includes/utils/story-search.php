<?php
/**
 * Story Graph Intelligence Search — WordPress search enhancement.
 *
 * Enhances default WordPress search with World Graph Studio entity type filters
 * and hybrid keyword+semantic ranking. Integrates seamlessly with WP_Query
 * and the WordPress admin bar using local graph analysis.
 *
 * @package WorldGraph\Utils
 */

namespace WorldGraph\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * World Graph Studio Intelligence Search configuration.
 *
 * @return array
 */
function search_config(): array {
	return [
		'entity_types'     => [
			'characters' => [
				'label'       => 'Characters',
				'post_type'   => 'worldgraph_character',
				'icon'        => 'admin-users',
				'color'       => '#d63384',
			],
			'scenes' => [
				'label'       => 'Scenes',
				'post_type'   => 'worldgraph_scene',
				'icon'        => 'format-image',
				'color'       => '#0073aa',
			],
			'locations' => [
				'label'       => 'Locations',
				'post_type'   => 'worldgraph_location',
				'icon'        => 'admin-location',
				'color'       => '#46b450',
			],
			'shots' => [
				'label'       => 'Shots',
				'post_type'   => 'worldgraph_shot',
				'icon'        => 'format-video',
				'color'       => '#ffba00',
			],
			'props' => [
				'label'       => 'Props',
				'post_type'   => 'worldgraph_prop',
				'icon'        => 'admin-collapse',
				'color'       => '#722094',
			],
			'assets' => [
				'label'       => 'Assets',
				'post_type'   => 'worldgraph_asset',
				'icon'        => 'admin-appearance',
				'color'       => '#c36d17',
			],
			'editorial_artifacts' => [
				'label'       => 'Editorial',
				'post_type'   => 'worldgraph_editorial',
				'icon'        => 'admin-tools',
				'color'       => '#dc2626',
			],
		],
		'search_modes'     => [
			'hybrid'  => [ 'label' => 'Hybrid (Recommended)', 'semantic_weight' => 0.7, 'keyword_weight' => 0.3 ],
			'semantic' => [ 'label' => 'Semantic Only', 'semantic_weight' => 1.0, 'keyword_weight' => 0.0 ],
			'keyword'  => [ 'label' => 'Keyword Only', 'semantic_weight' => 0.0, 'keyword_weight' => 1.0 ],
		],
		'min_semantic_score' => 0.1,
		'max_results'        => 20,
	];
}

/**
 * Fetch semantic search results using local keyword analysis.
 *
 * @param string $query The search query.
 * @param array  $args Optional search arguments.
 * @return array Search results.
 */
function fetch_semantic_search( string $query, array $args = [] ): array {
	return fetch_keyword_search( $query, $args );
}

/**
 * Normalize an untrusted public result limit while preserving its documented
 * default. Signed integers are used deliberately so negative values clamp to
 * the minimum instead of becoming large positive values through absint().
 *
 * @param mixed $value   Requested limit.
 * @param int   $default Default when the request omits or malforms the value.
 * @param int   $maximum Hard public-query ceiling.
 * @return int
 */
function clamp_public_search_limit( $value, int $default, int $maximum ): int {
	$maximum = max( 1, $maximum );
	$default = min( $maximum, max( 1, $default ) );

	if ( null === $value || '' === $value || ! is_scalar( $value ) || ! is_numeric( $value ) ) {
		return $default;
	}

	return min( $maximum, max( 1, (int) $value ) );
}

/**
 * Fetch keyword search results using WordPress post search.
 *
 * @param string $query The search query.
 * @param array  $args Optional search arguments.
 * @return array Search results.
 */
function fetch_keyword_search( string $query, array $args = [] ): array {
	$config = search_config();
	$entity_types = ! empty( $args['entity_types'] ) ? $args['entity_types'] : array_keys( $config['entity_types'] );
	$post_types = array_map( static function ( $type ) use ( $config ) { return entity_to_post_type( $type ); }, $entity_types );
	$post_status = current_user_can( 'edit_posts' )
		? [ 'publish', 'draft', 'pending', 'private', 'future' ]
		: 'publish';
	$top_k = clamp_public_search_limit( $args['top_k'] ?? null, $config['max_results'], 50 );
	$posts = get_posts( [ 'post_type' => $post_types, 'post_status' => $post_status, 'posts_per_page' => $top_k, 's' => $query ] );
	$results = [];
	foreach ( $posts as $post ) {
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
			continue;
		}
		$results[] = [ 'entity_type' => array_search( $post->post_type, array_column( $config['entity_types'], 'post_type' ), true ), 'entity_id' => $post->ID, 'title' => $post->post_title, 'score' => 1.0, 'snippet' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ) ];
	}
	return [ 'results' => $results ];
}

/**
 * Merge semantic and keyword search results with deduplication.
 *
 * @param array $semantic_results Semantic search results.
 * @param array $keyword_results  Keyword search results.
 * @param float $semantic_weight  Weight for semantic results (0-1).
 * @param float $keyword_weight   Weight for keyword results (0-1).
 * @return array Merged and ranked results.
 */
function merge_search_results( array $semantic_results, array $keyword_results, float $semantic_weight = 0.7, float $keyword_weight = 0.3 ): array {
	$merged = [];

	// Add semantic results
	foreach ( $semantic_results as $result ) {
		$key = "{$result['entity_type']}:{$result['entity_id']}";
		$merged[ $key ] = [
			'entity_type' => $result['entity_type'],
			'entity_id'   => $result['entity_id'],
			'title'       => $result['title'],
			'score'       => $result['score'] * $semantic_weight,
			'snippet'     => $result['snippet'] ?? '',
			'source'      => 'semantic',
			'url'         => get_edit_post_link( $result['entity_id'], 'url' ),
		];
	}

	// Merge or add keyword results
	foreach ( $keyword_results as $result ) {
		$key = "{$result['entity_type']}:{$result['entity_id']}";
		if ( isset( $merged[ $key ] ) ) {
			// Normalize keyword score to 0-1 and add to existing
			$normalized_score = $result['score'] ?? 0;
			$merged[ $key ]['score'] += $keyword_weight * $normalized_score;
			$merged[ $key ]['source'] = 'hybrid';
		} else {
			$merged[ $key ] = [
				'entity_type' => $result['entity_type'],
				'entity_id'   => $result['entity_id'],
				'title'       => $result['title'],
				'score'       => $keyword_weight * ( $result['score'] ?? 0 ),
				'snippet'     => $result['snippet'] ?? '',
				'source'      => 'keyword',
				'url'         => get_edit_post_link( $result['entity_id'], 'url' ),
			];
		}
	}

	// Sort by combined score
	uasort( $merged, function( $a, $b ) {
		return $b['score'] <=> $a['score'];
	} );

	return array_values( $merged );
}

/**
 * Get entity type label from slug.
 *
 * @param string $entity_type The entity type slug.
 * @return string The human-readable label.
 */
function get_entity_type_label( string $entity_type ): string {
	$config = search_config();
	return $config['entity_types'][ $entity_type ]['label'] ?? $entity_type;
}

/**
 * Get entity type icon from slug.
 *
 * @param string $entity_type The entity type slug.
 * @return string The Dashicon class.
 */
function get_entity_type_icon( string $entity_type ): string {
	$config = search_config();
	return $config['entity_types'][ $entity_type ]['icon'] ?? 'admin-generic';
}

/**
 * Get entity type color from slug.
 *
 * @param string $entity_type The entity type slug.
 * @return string The hex color.
 */
function get_entity_type_color( string $entity_type ): string {
	$config = search_config();
	return $config['entity_types'][ $entity_type ]['color'] ?? '#6c757d';
}

/**
 * Get post type from entity type.
 *
 * @param string $entity_type The entity type slug.
 * @return string The WordPress post type slug.
 */
function entity_to_post_type( string $entity_type ): string {
	$config = search_config();
	return $config['entity_types'][ $entity_type ]['post_type'] ?? "worldgraph_{$entity_type}";
}

/**
 * Enhance WordPress search with World Graph Studio entity filters.
 *
 * Modifies WP_Query to filter by World Graph Studio entity types when search is performed.
 *
 * @param WP_Query $query The WP_Query instance.
 */
function enhance_search_query( \WP_Query $query ): void {
	if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
		// Check if entity type filters are set
		$entity_types = isset( $_GET['worldgraph_entity_type'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['worldgraph_entity_type'] ) ) ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only search filter.

		if ( ! empty( $entity_types ) ) {
			// Convert entity types to post types
			$post_types = array_map( 'entity_to_post_type', $entity_types );
			$query->set( 'post_type', $post_types );
		}
	}
}
add_action( 'pre_get_posts', 'WorldGraph\Utils\enhance_search_query' );

/**
 * Add World Graph Studio entity type filters to WordPress search form.
 *
 * Hooks into the search form to add entity type checkboxes.
 *
 * @param string $form The search form HTML.
 * @return string The enhanced search form.
 */
function add_search_filters_to_form( string $form ): string {
	// Only add filters on frontend, not in admin
	if ( is_admin() ) {
		return $form;
	}

	$config = search_config();
	$entity_types = $config['entity_types'];

	// Build filter checkboxes
	$filters_html = '<div class="worldgraph-search-filters">';
	$filters_html .= '<fieldset>';
	$filters_html .= '<legend>Filter by type:</legend>';

	foreach ( $entity_types as $slug => $config ) {
		$checked = ''; // Checked by default
		$label = esc_html( $config['label'] );
		$icon = $config['icon'];
		$color = $config['color'];

		$filters_html .= sprintf(
			'<label class="worldgraph-filter-item" style="--entity-color: %s;">',
			esc_attr( $color )
		);
		$filters_html .= sprintf(
			'<input type="checkbox" name="worldgraph_entity_type[]" value="%s" %s />',
			esc_attr( $slug ),
			checked( $slug, '', false )
		);
		$filters_html .= sprintf(
			'<span class="dashicons dashicons-%s"></span>',
			esc_attr( $icon )
		);
		$filters_html .= esc_html( $label );
		$filters_html .= '</label>';
	}

	$filters_html .= '</fieldset>';
	$filters_html .= '</div>';

	// Insert filters before the search submit button
	$submit_pos = strpos( $form, '</form>' );
	if ( $submit_pos !== false ) {
		$form = substr( $form, 0, $submit_pos ) . $filters_html . substr( $form, $submit_pos );
	}

	return $form;
}

/**
 * Register World Graph Studio search REST endpoint.
 *
 * @return void
 */
function register_search_endpoint(): void {
	register_rest_route( 'worldgraph/v1', '/search', [
		'methods'             => 'POST',
		'callback'            => __NAMESPACE__ . '\\handle_search_request',
		'permission_callback' => '__return_true', // Public endpoint
		'args'                => [
			'query'     => [
				'required'    => true,
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => 200,
				'description' => 'Search query.',
			],
			'entity_types' => [
				'required'    => false,
				'type'        => 'array',
				'description' => 'Entity types to search.',
			],
			'mode'      => [
				'required'    => false,
				'type'        => 'string',
				'default'     => 'hybrid',
				'enum'        => [ 'hybrid', 'semantic', 'keyword' ],
				'description' => 'Search mode.',
			],
			'top_k'     => [
				'required'    => false,
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 50,
				'description' => 'Maximum results.',
			],
		],
	] );

	register_rest_route( 'worldgraph/v1', '/search/suggest', [
		'methods'             => 'GET',
		'callback'            => __NAMESPACE__ . '\\handle_search_suggestions',
		'permission_callback' => '__return_true',
		'args'                => [
			'q' => [
				'required'    => true,
				'type'        => 'string',
				'minLength'   => 2,
				'maxLength'   => 100,
				'description' => 'Partial search query.',
			],
			'limit' => [
				'required'    => false,
				'type'        => 'integer',
				'default'     => 5,
				'minimum'     => 1,
				'maximum'     => 20,
			],
		],
	] );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_search_endpoint' );

/**
 * Handle search REST API request.
 *
 * @param \WP_REST_Request $request The REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_search_request( \WP_REST_Request $request ) {
	$query     = sanitize_text_field( $request->get_param( 'query' ) );
	$entity_types = $request->get_param( 'entity_types' ) ?: [];
	$mode      = $request->get_param( 'mode' ) ?: 'hybrid';
	$top_k     = clamp_public_search_limit( $request->get_param( 'top_k' ), 20, 50 );

	if ( empty( $query ) ) {
		return new \WP_Error( 'empty_query', 'Search query is required.', [ 'status' => 400 ] );
	}

	// Fetch semantic results
	$semantic_results = fetch_semantic_search( $query, [
		'entity_types' => $entity_types,
		'mode'         => $mode,
		'top_k'        => $top_k,
	] );

	// Fetch keyword results if hybrid mode
	$keyword_results = [];
	if ( 'hybrid' === $mode ) {
		$keyword_results = fetch_keyword_search( $query, [
			'entity_types' => $entity_types,
			'top_k'        => $top_k,
		] );
	}

	// Merge results
	$config = search_config();
	$mode_config = $config['search_modes'][ $mode ];

	$merged = merge_search_results(
		$semantic_results['results'] ?? [],
		$keyword_results['results'] ?? [],
		$mode_config['semantic_weight'],
		$mode_config['keyword_weight']
	);

	return new \WP_REST_Response( [
		'success'   => true,
		'query'     => $query,
		'mode'      => $mode,
		'total'     => count( $merged ),
		'results'   => $merged,
		'entity_types' => array_keys( $config['entity_types'] ),
	], 200 );
}

/**
 * Handle search suggestions REST API request.
 *
 * @param \WP_REST_Request $request The REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_search_suggestions( \WP_REST_Request $request ) {
	$query = sanitize_text_field( $request->get_param( 'q' ) );
	$limit = clamp_public_search_limit( $request->get_param( 'limit' ), 5, 20 );

	if ( empty( $query ) || strlen( $query ) < 2 ) {
		return new \WP_REST_Response( [ 'suggestions' => [] ], 200 );
	}

	$config = search_config();
	$suggestions = [];

	// Get suggestions from each entity type
	foreach ( $config['entity_types'] as $slug => $entity_config ) {
		$posts = get_posts( [
			'post_type'      => $entity_config['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			's'              => $query,
			'fields'         => 'ids',
		] );

		foreach ( $posts as $post_id ) {
			$title = get_the_title( $post_id );
			$suggestions[] = [
				'id'          => $post_id,
				'title'       => $title,
				'entity_type' => $slug,
				'label'       => sprintf( '%s — %s', $entity_config['label'], $title ),
				'url'         => get_edit_post_link( $post_id, 'url' ),
			];
		}
	}

	// Sort by title and limit
	usort( $suggestions, function( $a, $b ) {
		return strcmp( $a['title'], $b['title'] );
	} );

	$suggestions = array_slice( $suggestions, 0, $limit * count( $config['entity_types'] ) );

	return new \WP_REST_Response( [
		'success'     => true,
		'query'       => $query,
		'suggestions' => $suggestions,
	], 200 );
}

/**
 * Enqueue search widget styles and scripts.
 *
 * @return void
 */
function enqueue_search_assets(): void {
	wp_enqueue_style(
		'worldgraph-search',
		WORLDGRAPH_PLUGIN_URL . 'assets/css/search-widget.css',
		[],
		WORLDGRAPH_VERSION
	);

	wp_enqueue_script(
		'worldgraph-search',
		WORLDGRAPH_PLUGIN_URL . 'assets/js/search-widget.js',
		[ 'jquery' ],
		WORLDGRAPH_VERSION,
		true
	);

	wp_localize_script( 'worldgraph-search', 'worldgraphSearch', [
		'ajax_url'  => admin_url( 'admin-ajax.php' ),
		'search_url' => rest_url( 'worldgraph/v1/search' ),
		'suggest_url' => rest_url( 'worldgraph/v1/search/suggest' ),
		'nonce'     => wp_create_nonce( 'worldgraph_search' ),
	] );
}

/**
 * Create a shortcode for embedding World Graph Studio search anywhere.
 *
 * [worldgraph_search mode="hybrid" show_filters="true" max_results="20"]
 *
 * @param array $atts Shortcode attributes.
 * @return string The search widget HTML.
 */
function worldgraph_search_shortcode( array $atts ): string {
	$atts = shortcode_atts( [
		'mode'        => 'hybrid',
		'show_filters' => 'true',
		'max_results' => '20',
		'placeholder' => 'Search stories, characters, scenes...',
	], $atts, 'worldgraph_search' );

	ob_start();
	?>
	<div class="worldgraph-search-widget" data-mode="<?php echo esc_attr( $atts['mode'] ); ?>" data-max-results="<?php echo esc_attr( $atts['max_results'] ); ?>">
		<form class="worldgraph-search-form" role="search">
			<div class="worldgraph-search-input-wrapper">
				<input type="search"
					class="worldgraph-search-input"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
					autocomplete="off"
				/>
				<button type="submit" class="worldgraph-search-button" aria-label="Search">
					<span class="dashicons dashicons-search"></span>
				</button>
			</div>
			<?php if ( 'true' === $atts['show_filters'] ) : ?>
				<div class="worldgraph-search-filters">
					<?php
					$config = search_config();
					foreach ( $config['entity_types'] as $slug => $entity_config ) : ?>
						<label class="worldgraph-filter-item" style="--entity-color: <?php echo esc_attr( $entity_config['color'] ); ?>">
							<input type="checkbox" name="worldgraph_entity_type[]" value="<?php echo esc_attr( $slug ); ?>" checked />
							<span class="dashicons dashicons-<?php echo esc_attr( $entity_config['icon'] ); ?>"></span>
							<?php echo esc_html( $entity_config['label'] ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="worldgraph-search-results" style="display: none;"></div>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'worldgraph_search', 'WorldGraph\Utils\worldgraph_search_shortcode' );

/**
 * Add World Graph Studio search to WordPress widget areas.
 *
 * @return void
 */
function register_worldgraph_search_widget(): void {
	register_widget( 'WorldGraph\\Utils\\Search_Widget' );
}
add_action( 'widgets_init', __NAMESPACE__ . '\\register_worldgraph_search_widget' );

/**
 * World Graph Studio Search Widget class.
 *
 * @package WorldGraph\Utils
 */
class Search_Widget extends \WP_Widget {

	/**
	 * Register widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'worldgraph_search',
			__( 'World Graph Studio Search', 'worldgraph' ),
			[ 'description' => __( 'Enhanced search with World Graph Studio entity type filters.', 'worldgraph' ) ]
		);
	}

	/**
	 * Front-end widget display.
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Widget instance settings.
	 */
	public function widget( $args, $instance ): void {
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$title    = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Story Search', 'worldgraph' );
		$mode     = ! empty( $instance['mode'] ) ? $instance['mode'] : 'hybrid';
		$show_filters = ! empty( $instance['show_filters'] );

		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="worldgraph-search-widget" data-mode="<?php echo esc_attr( $mode ); ?>">
			<form class="worldgraph-search-form" role="search">
				<div class="worldgraph-search-input-wrapper">
					<input type="search"
						class="worldgraph-search-input"
						placeholder="<?php esc_attr_e( 'Search stories, characters, scenes...', 'worldgraph' ); ?>"
						autocomplete="off"
					/>
					<button type="submit" class="worldgraph-search-button" aria-label="Search">
						<span class="dashicons dashicons-search"></span>
					</button>
				</div>
				<?php if ( $show_filters ) : ?>
					<div class="worldgraph-search-filters">
						<?php
						$config = search_config();
						foreach ( $config['entity_types'] as $slug => $entity_config ) : ?>
							<label class="worldgraph-filter-item" style="--entity-color: <?php echo esc_attr( $entity_config['color'] ); ?>">
								<input type="checkbox" name="worldgraph_entity_type[]" value="<?php echo esc_attr( $slug ); ?>" checked />
								<span class="dashicons dashicons-<?php echo esc_attr( $entity_config['icon'] ); ?>"></span>
								<?php echo esc_html( $entity_config['label'] ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<div class="worldgraph-search-results" style="display: none;"></div>
			</form>
		</div>
		<?php
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Back-end widget form.
	 *
	 * @param array $instance Previous widget settings.
	 */
	public function form( $instance ): void {
		$title      = isset( $instance['title'] ) ? $instance['title'] : __( 'Story Search', 'worldgraph' );
		$mode       = isset( $instance['mode'] ) ? $instance['mode'] : 'hybrid';
		$show_filters = isset( $instance['show_filters'] ) ? $instance['show_filters'] : true;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'worldgraph' ); ?>
				<input class="widefat"
					id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
					type="text"
					value="<?php echo esc_attr( $title ); ?>"
				/>
			</label>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'mode' ) ); ?>">
				<?php esc_html_e( 'Search Mode:', 'worldgraph' ); ?>
				<select class="widefat"
					id="<?php echo esc_attr( $this->get_field_id( 'mode' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'mode' ) ); ?>"
				>
					<?php
					$config = search_config();
					foreach ( $config['search_modes'] as $slug => $mode_config ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $mode, $slug ); ?>>
							<?php echo esc_html( $mode_config['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>
		<p>
			<input type="checkbox"
				id="<?php echo esc_attr( $this->get_field_id( 'show_filters' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_filters' ) ); ?>"
				value="1"
				<?php checked( $show_filters, true ); ?>
			/>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_filters' ) ); ?>">
				<?php esc_html_e( 'Show entity type filters', 'worldgraph' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Sanitize widget form values.
	 *
	 * @param array $new_instance New widget settings.
	 * @param array $old_instance Old widget settings.
	 * @return array Sanitized settings.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = [];
		$instance['title']      = sanitize_text_field( $new_instance['title'] );
		$instance['mode']       = in_array( $new_instance['mode'], [ 'hybrid', 'semantic', 'keyword' ], true ) ? $new_instance['mode'] : 'hybrid';
		$instance['show_filters'] = ! empty( $new_instance['show_filters'] );
		return $instance;
	}
}

/**
 * Add World Graph Studio search to admin bar.
 *
 * @param \WP_Admin_Bar $admin_bar The admin bar object.
 * @return void
 */
function add_admin_bar_search( \WP_Admin_Bar $admin_bar ): void {
	if ( ! is_admin_bar_showing() || ! current_user_can( 'read' ) ) {
		return;
	}

	$config = search_config();
	$entity_types = array_keys( $config['entity_types'] );

	$admin_bar->add_node( [
		'id'    => 'worldgraph-search',
		'title' => '<span class="ab-icon dashicons dashicons-search"></span><span class="screen-reader-text">World Graph Studio Search</span>',
		'href'  => '#',
		'meta'  => [
			'tabindex' => 0,
			'class'    => 'ab-item worldgraph-admin-search-trigger',
		],
	] );

	// Add submenu with entity type filters
	foreach ( $config['entity_types'] as $slug => $entity_config ) {
		$admin_bar->add_node( [
			'id'     => "worldgraph-search-{$slug}",
			'parent' => 'worldgraph-search',
			'title'  => sprintf(
				'<span style="color:%s;" class="dashicons dashicons-%s"></span> %s',
				esc_attr( $entity_config['color'] ),
				esc_attr( $entity_config['icon'] ),
				esc_html( $entity_config['label'] )
			),
			'href'   => '#',
			'meta'   => [
				'class' => 'worldgraph-search-entity-filter',
				'data-entity' => $slug,
			],
		] );
	}
}
add_action( 'admin_bar_menu', __NAMESPACE__ . '\\add_admin_bar_search', 999 );

/**
 * Get all searchable World Graph Studio entities for autocomplete.
 *
 * @param string $search The search string.
 * @param int    $limit  Maximum results.
 * @return array Searchable entities.
 */
function get_searchable_entities( string $search = '', int $limit = 20 ): array {
	$config = search_config();
	$results = [];

	foreach ( $config['entity_types'] as $slug => $entity_config ) {
		$args = [
			'post_type'      => $entity_config['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
		];

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$posts = get_posts( $args );

		foreach ( $posts as $post_id ) {
			$title = get_the_title( $post_id );
			$results[] = [
				'id'          => $post_id,
				'title'       => $title,
				'entity_type' => $slug,
				'label'       => sprintf( '%s — %s', $entity_config['label'], $title ),
				'url'         => get_edit_post_link( $post_id, 'url' ),
				'color'       => $entity_config['color'],
			];
		}
	}

	return $results;
}
