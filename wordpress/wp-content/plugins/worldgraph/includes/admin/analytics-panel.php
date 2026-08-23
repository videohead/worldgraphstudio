<?php
/**
 * Story Graph Analytics Admin Panel.
 *
 * Provides a comprehensive analytics dashboard at Tools > Story Graph Analytics.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

/**
 * Analytics Panel class.
 */
class Analytics_Panel {

	/**
	 * Initialize the analytics panel.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_filter( 'post_row_actions', [ __CLASS__, 'add_project_row_action' ], 10, 2 );
		add_action( 'wp_ajax_worldgraph_fetch_analytics', [ __CLASS__, 'ajax_fetch_analytics' ] );
		add_action( 'wp_ajax_worldgraph_fetch_network', [ __CLASS__, 'ajax_fetch_network' ] );
		add_action( 'wp_ajax_worldgraph_fetch_graph', [ __CLASS__, 'ajax_fetch_graph' ] );
		add_action( 'wp_ajax_worldgraph_clear_cache', [ __CLASS__, 'ajax_clear_cache' ] );
	}

	/**
	 * Add a direct analytics link to each World Graph Studio project row.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    Current post.
	 * @return array Row actions.
	 */
	public static function add_project_row_action( array $actions, \WP_Post $post ): array {
		if ( 'worldgraph_project' === $post->post_type && current_user_can( 'manage_options' ) ) {
			$url = add_query_arg(
				[
					'page'       => 'worldgraph-analytics',
					'project_id' => $post->ID,
				],
				admin_url( 'tools.php' )
			);
			$actions['worldgraph_analyze'] = '<a href="' . esc_url( $url ) . '">Analyze</a>';
		}

		return $actions;
	}

	/**
	 * Add analytics menu page.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'tools.php',
			'Story Graph Analytics',
			'Story Graph Analytics',
			'manage_options',
			'worldgraph-analytics',
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_scripts( string $hook ): void {
		if ( 'tools_page_worldgraph-analytics' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'worldgraph-analytics',
			WORLDGRAPH_PLUGIN_URL . 'assets/css/analytics-panel.css',
			[],
			WORLDGRAPH_VERSION
		);

		wp_enqueue_script(
			'worldgraph-analytics',
			WORLDGRAPH_PLUGIN_URL . 'assets/js/analytics-panel.js',
			[ 'jquery' ],
			WORLDGRAPH_VERSION,
			true
		);

		wp_localize_script(
			'worldgraph-analytics',
			'worldgraphAnalytics',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'worldgraph_analytics_nonce' ),
				'strings'      => [
					'loading'       => 'Loading analytics...',
					'error'         => 'Error loading analytics.',
					'fetching'      => 'Analyzing Story Graph...',
					'noData'        => 'No analytics data available.',
					'clearCache'    => 'Clear Cache',
					'cacheCleared'  => 'Cache cleared.',
					'fetchError'    => 'Failed to fetch analytics.',
					'networkError'  => 'Failed to fetch network data.',
					'graphError'    => 'Failed to fetch graph data.',
				],
			]
		);
	}

	/**
	 * Render the analytics page.
	 */
	public static function render_page(): void {
		$projects = get_posts( [
			'post_type'      => 'worldgraph_project',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		$selected_project_id = isset( $_GET['project_id'] ) ? absint( wp_unslash( $_GET['project_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only project selection.
		?>
		<div class="wrap worldgraph-analytics-wrap">
			<h1>Story Graph Analytics</h1>
			<p class="description">
				Comprehensive analytics for your Story Graph, powered by the World Graph Studio intelligence engine.
				Analyze network density, character relationships, entity connectivity, and isolated entities.
			</p>

			<div id="worldgraph-analytics-app">
				<div class="worldgraph-actions">
					<label for="worldgraph-analytics-project"><strong>Project</strong></label>
					<select id="worldgraph-analytics-project">
						<option value="">Select a project</option>
						<?php foreach ( $projects as $project ) : ?>
							<option value="<?php echo esc_attr( (string) $project->ID ); ?>" <?php selected( $selected_project_id, $project->ID ); ?>><?php echo esc_html( $project->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Summary Cards -->
				<div class="worldgraph-summary-cards" id="summary-cards">
					<div class="worldgraph-summary-card">
						<span class="worldgraph-card-number" id="total-entities">0</span>
						<span class="worldgraph-card-label">Total Entities</span>
					</div>
					<div class="worldgraph-summary-card">
						<span class="worldgraph-card-number" id="total-relationships">0</span>
						<span class="worldgraph-card-label">Total Relationships</span>
					</div>
					<div class="worldgraph-summary-card">
						<span class="worldgraph-card-number" id="network-density">0%</span>
						<span class="worldgraph-card-label">Network Density</span>
					</div>
					<div class="worldgraph-summary-card">
						<span class="worldgraph-card-number" id="isolated-count">0</span>
						<span class="worldgraph-card-label">Isolated Entities</span>
					</div>
				</div>

				<!-- Action Buttons -->
				<div class="worldgraph-actions">
					<button type="button" class="button button-primary" id="fetch-analytics-btn">
						<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
						Fetch Analytics
					</button>
					<button type="button" class="button" id="clear-cache-btn">
						<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
						Clear Cache
					</button>
				</div>

				<!-- Loading Indicator -->
				<div class="worldgraph-loading" id="analytics-loading" style="display: none;">
					<span class="spinner is-active"></span>
					<span class="worldgraph-loading-text">Loading analytics...</span>
				</div>

				<!-- Error Notice -->
				<div class="notice notice-error" id="analytics-error" style="display: none;">
					<p id="analytics-error-message"></p>
				</div>

				<!-- Analytics Content -->
				<div id="analytics-content" style="display: none;">
					<!-- Entity Counts -->
					<div class="worldgraph-section">
						<h2>Entity Counts</h2>
						<div class="worldgraph-entity-counts" id="entity-counts"></div>
					</div>

					<!-- Most Connected Entities -->
					<div class="worldgraph-section">
						<h2>Most Connected Entities</h2>
						<table class="wp-list-table widefat fixed striped" id="most-connected-table">
							<thead>
								<tr>
									<th>Entity</th>
									<th>Type</th>
									<th>Connections</th>
								</tr>
							</thead>
							<tbody id="most-connected-body"></tbody>
						</table>
					</div>

					<!-- Relationship Type Distribution -->
					<div class="worldgraph-section">
						<h2>Relationship Type Distribution</h2>
						<div class="worldgraph-distribution" id="relationship-distribution"></div>
					</div>

					<!-- Isolated Entities -->
					<div class="worldgraph-section">
						<h2>Isolated Entities</h2>
						<p class="description">Entities with no relationships in the graph.</p>
						<table class="wp-list-table widefat fixed striped" id="isolated-table">
							<thead>
								<tr>
									<th>Entity</th>
									<th>Type</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody id="isolated-body"></tbody>
						</table>
					</div>
				</div>

				<!-- Character Network Section -->
				<div class="worldgraph-section" id="network-section" style="display: none;">
					<h2>Character Network</h2>
					<div class="worldgraph-actions">
						<button type="button" class="button" id="fetch-network-btn">
							<span class="dashicons dashicons-groups" style="margin-top: 3px;"></span>
							Fetch Character Network
						</button>
					</div>
					<div class="worldgraph-loading" id="network-loading" style="display: none;">
						<span class="spinner is-active"></span>
						<span class="worldgraph-loading-text">Loading network data...</span>
					</div>

					<div id="network-content" style="display: none;">
						<!-- Strongest Relationships -->
						<div class="worldgraph-subsection">
							<h3>Strongest Character Relationships</h3>
							<table class="wp-list-table widefat fixed striped" id="strongest-table">
								<thead>
									<tr>
										<th>Character A</th>
										<th>Character B</th>
										<th>Relationship</th>
										<th>Co-occurrences</th>
									</tr>
								</thead>
								<tbody id="strongest-body"></tbody>
							</table>
						</div>

						<!-- Character Scene Presence -->
						<div class="worldgraph-subsection">
							<h3>Character Scene Presence</h3>
							<table class="wp-list-table widefat fixed striped" id="scene-presence-table">
								<thead>
									<tr>
										<th>Character</th>
										<th>Scenes</th>
										<th>Shots</th>
									</tr>
								</thead>
								<tbody id="scene-presence-body"></tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- No Data State -->
				<div class="worldgraph-no-data" id="no-data-state">
					<p>No analytics data available. Click "Fetch Analytics" to analyze your Story Graph.</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Fetch analytics from Story Graph.
	 */
	public static function ajax_fetch_analytics(): void {
		check_ajax_referer( 'worldgraph_analytics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}
		$project_id = self::requested_project_id();

		// Check cache first.
		$cached = \WorldGraph\Utils\get_cached_graph_analytics( $project_id );
		if ( is_array( $cached ) && isset( $cached['total_entities'], $cached['total_relationships'] ) ) {
			$cached['cached'] = true;
			wp_send_json_success( $cached );
		}

		// Fetch from local Story Graph.
		$analytics = \WorldGraph\Utils\fetch_graph_analytics( [ 'project_id' => $project_id ] );

		if ( is_wp_error( $analytics ) ) {
			wp_send_json_error( [
				'message' => $analytics->get_error_message(),
			] );
		}

		// Cache the result.
		\WorldGraph\Utils\cache_graph_analytics( $analytics, 3600, $project_id );

		$analytics['cached'] = false;
		wp_send_json_success( $analytics );
	}

	/**
	 * AJAX handler: Fetch character network from Story Graph.
	 */
	public static function ajax_fetch_network(): void {
		check_ajax_referer( 'worldgraph_analytics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}
		$project_id = self::requested_project_id();

		// Check cache first.
		$cached = \WorldGraph\Utils\get_cached_character_network( $project_id );
		if ( is_array( $cached ) && isset( $cached['strongest_relationships'], $cached['character_scene_presence'] ) ) {
			$cached['cached'] = true;
			wp_send_json_success( $cached );
		}

		// Fetch from local Story Graph.
		$network = \WorldGraph\Utils\fetch_character_network( [ 'project_id' => $project_id ] );

		if ( is_wp_error( $network ) ) {
			wp_send_json_error( [
				'message' => $network->get_error_message(),
			] );
		}

		// Cache the result.
		\WorldGraph\Utils\cache_character_network( $network, 3600, $project_id );

		$network['cached'] = false;
		wp_send_json_success( $network );
	}

	/**
	 * AJAX handler: Fetch full relationship graph.
	 */
	public static function ajax_fetch_graph(): void {
		check_ajax_referer( 'worldgraph_analytics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}
		$project_id = self::requested_project_id();

		$scene_ids = isset( $_REQUEST['scene_ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['scene_ids'] ) ) ) ) : [];

		$graph = \WorldGraph\Utils\fetch_relationship_graph( [
			'scene_ids'  => $scene_ids,
			'project_id' => $project_id,
		] );

		if ( is_wp_error( $graph ) ) {
			wp_send_json_error( [
				'message' => $graph->get_error_message(),
			] );
		}

		wp_send_json_success( $graph );
	}

	/**
	 * AJAX handler: Clear cache.
	 */
	public static function ajax_clear_cache(): void {
		check_ajax_referer( 'worldgraph_analytics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}
		$project_id = self::requested_project_id();

		\WorldGraph\Utils\clear_cached_graph_analytics( $project_id );
		\WorldGraph\Utils\clear_cached_character_network( $project_id );

		wp_send_json_success( [
			'message' => 'Cache cleared.',
		] );
	}

	/**
	 * Get and validate the project selected by the analytics request.
	 *
	 * @return int Project post ID.
	 */
	private static function requested_project_id(): int {
		$project_id = isset( $_REQUEST['project_id'] ) ? absint( wp_unslash( $_REQUEST['project_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Every caller verifies the analytics AJAX nonce first.
		if ( ! $project_id || 'worldgraph_project' !== get_post_type( $project_id ) ) {
			wp_send_json_error( [
				'message' => 'Select a valid World Graph Studio project to analyze.',
			], 400 );
			return 0;
		}

		return $project_id;
	}
}
