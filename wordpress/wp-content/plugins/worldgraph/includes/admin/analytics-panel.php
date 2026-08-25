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
				'createUrls'   => [
					'worldgraph_world'     => admin_url( 'post-new.php?post_type=worldgraph_world' ),
					'worldgraph_character' => admin_url( 'post-new.php?post_type=worldgraph_character' ),
					'worldgraph_location'  => admin_url( 'post-new.php?post_type=worldgraph_location' ),
					'worldgraph_scene'     => admin_url( 'post-new.php?post_type=worldgraph_scene' ),
					'worldgraph_prop'      => admin_url( 'post-new.php?post_type=worldgraph_prop' ),
					'worldgraph_episode'   => admin_url( 'post-new.php?post_type=worldgraph_episode' ),
				],
				'editUrl'      => admin_url( 'post.php?action=edit&post=__POST_ID__' ),
				'entityLabels' => [
					'worldgraph_world'     => __( 'Story World', 'worldgraph' ),
					'worldgraph_character' => __( 'Character', 'worldgraph' ),
					'worldgraph_location'  => __( 'Location', 'worldgraph' ),
					'worldgraph_scene'     => __( 'Scene', 'worldgraph' ),
					'worldgraph_prop'      => __( 'Prop', 'worldgraph' ),
					'worldgraph_episode'   => __( 'Episode', 'worldgraph' ),
				],
				'strings'      => [
					'loading'                 => __( 'Loading analytics...', 'worldgraph' ),
					'error'                   => __( 'Error loading analytics.', 'worldgraph' ),
					'fetching'                => __( 'Analyzing Story Graph...', 'worldgraph' ),
					'noData'                  => __( 'No analytics data available.', 'worldgraph' ),
					'clearCache'              => __( 'Clear Cache', 'worldgraph' ),
					'cacheCleared'            => __( 'Cache cleared.', 'worldgraph' ),
					'fetchError'              => __( 'Failed to fetch analytics.', 'worldgraph' ),
					'networkError'            => __( 'Failed to fetch network data.', 'worldgraph' ),
					'graphError'              => __( 'Failed to fetch graph data.', 'worldgraph' ),
					'reviewCoverage'          => __( 'Review graph coverage', 'worldgraph' ),
					'reviewSummary'           => __( 'Review the graph and choose the question that feels useful now.', 'worldgraph' ),
					'analysisEmpty'           => __( 'Analysis complete. No structural prompts surfaced.', 'worldgraph' ),
					/* translators: 1: displayed prompt count, 2: total prompt count. */
					'analysisTruncated'       => __( 'Analysis complete. Showing %1$d of %2$d prompts. Refine the graph, then analyze again to bring other elements forward.', 'worldgraph' ),
					'analysisOne'             => __( 'Analysis complete. One development prompt surfaced.', 'worldgraph' ),
					/* translators: %d: development prompt count. */
					'analysisMany'            => __( 'Analysis complete. %1$d development prompts surfaced.', 'worldgraph' ),
					'noPromptsTitle'          => __( 'No structural prompts surfaced.', 'worldgraph' ),
					'noPromptsBody'           => __( 'The current graph covers the foundational connections checked here. Use Dramaturgy for a closer reading of movement, stakes, and audience experience.', 'worldgraph' ),
					'noElements'              => __( 'No existing elements are singled out by these graph checks.', 'worldgraph' ),
					'untitledElement'         => __( 'Untitled element', 'worldgraph' ),
					'elementEvidenceFallback' => __( 'Open this element to develop its graph connections.', 'worldgraph' ),
					'openElement'             => __( 'Open element', 'worldgraph' ),
					'highPriority'            => __( 'high priority', 'worldgraph' ),
					'mediumPriority'          => __( 'medium priority', 'worldgraph' ),
					'developmentQuestion'     => __( 'Development question', 'worldgraph' ),
					'graphEvidence'           => __( 'Graph evidence: ', 'worldgraph' ),
					'noGraphDetail'           => __( 'No supporting graph detail was returned.', 'worldgraph' ),
					'creativeQuestion'        => __( 'What would you like to discover here?', 'worldgraph' ),
					/* translators: %s: Story Graph entity label. */
					'openEntity'              => __( 'Open %1$s', 'worldgraph' ),
					/* translators: %s: Story Graph entity label. */
					'draftEntity'             => __( 'Draft a %1$s', 'worldgraph' ),
					'storyElement'            => __( 'Story element', 'worldgraph' ),
					'foundation'              => __( 'Foundation', 'worldgraph' ),
					'exposure'                => __( 'Exposure', 'worldgraph' ),
					'sceneFocus'              => __( 'Scene focus', 'worldgraph' ),
					'sceneSetting'            => __( 'Scene setting', 'worldgraph' ),
					'nextEvent'               => __( 'Next event', 'worldgraph' ),
					'development'             => __( 'Development', 'worldgraph' ),
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
				See what is connected, discover story elements that have not reached a Scene, and turn graph evidence
				into practical questions for the next writing pass.
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
						Refresh Analysis
					</button>
					<button type="button" class="button" id="clear-cache-btn">
						<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
						Clear Cache
					</button>
				</div>

				<!-- Loading Indicator -->
				<div class="worldgraph-loading" id="analytics-loading" role="status" aria-live="polite" style="display: none;">
					<span class="spinner is-active"></span>
					<span class="worldgraph-loading-text">Loading analytics...</span>
				</div>

				<!-- Error Notice -->
				<div class="notice notice-error" id="analytics-error" role="alert" style="display: none;">
					<p id="analytics-error-message"></p>
				</div>

				<!-- Analytics Content -->
				<div id="analytics-content" aria-busy="false" style="display: none;">
					<!-- Development Compass -->
					<section class="worldgraph-section worldgraph-development-compass" aria-labelledby="worldgraph-development-title">
						<div class="worldgraph-development-header">
							<div>
								<p class="worldgraph-section-kicker"><?php esc_html_e( 'Where to go next', 'worldgraph' ); ?></p>
								<h2 id="worldgraph-development-title"><?php esc_html_e( 'Development Compass', 'worldgraph' ); ?></h2>
								<p class="description"><?php esc_html_e( 'These prompts use visible graph structure, not a story-quality score. Use them as invitations to develop, connect, imagine the next change, or deliberately leave an element offstage.', 'worldgraph' ); ?></p>
							</div>
							<span class="worldgraph-development-phase" id="development-phase"></span>
						</div>
						<p class="worldgraph-development-summary" id="development-summary"></p>
						<p class="worldgraph-development-result-count" id="development-result-count" role="status" aria-live="polite" aria-atomic="true"></p>
						<div class="worldgraph-opportunity-grid" id="development-opportunities"></div>

						<div class="worldgraph-elements-to-develop">
							<h3><?php esc_html_e( 'Elements to bring forward', 'worldgraph' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Open an existing element to deepen it, or draft a new story element from the related question. These links do not create graph relationships.', 'worldgraph' ); ?></p>
							<div class="worldgraph-element-list" id="elements-to-develop"></div>
						</div>

						<div class="worldgraph-quick-starts" aria-labelledby="worldgraph-quick-starts-title">
							<div>
								<h3 id="worldgraph-quick-starts-title"><?php esc_html_e( 'Start a new story element', 'worldgraph' ); ?></h3>
								<p class="description"><?php esc_html_e( 'Open a normal Story Graph draft. The Compass never creates or changes relationships automatically.', 'worldgraph' ); ?></p>
							</div>
							<div class="worldgraph-quick-start-actions">
								<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=worldgraph_character' ) ); ?>"><?php esc_html_e( 'New Character', 'worldgraph' ); ?></a>
								<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=worldgraph_location' ) ); ?>"><?php esc_html_e( 'New Location', 'worldgraph' ); ?></a>
								<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=worldgraph_scene' ) ); ?>"><?php esc_html_e( 'New Scene', 'worldgraph' ); ?></a>
								<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=worldgraph_prop' ) ); ?>"><?php esc_html_e( 'New Prop', 'worldgraph' ); ?></a>
								<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=worldgraph_world' ) ); ?>"><?php esc_html_e( 'New Story World', 'worldgraph' ); ?></a>
							</div>
						</div>
					</section>

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
					<p><?php esc_html_e( 'Select a Project, then use Refresh Analysis to inspect its Story Graph.', 'worldgraph' ); ?></p>
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
		$force_refresh = isset( $_POST['force'] ) && 1 === absint( wp_unslash( $_POST['force'] ) );
		$cached        = \WorldGraph\Utils\get_cached_graph_analytics( $project_id );
		if (
			! $force_refresh
			&& is_array( $cached )
			&& isset(
				$cached['total_entities'],
				$cached['total_relationships'],
				$cached['development']['phase'],
				$cached['development']['total_opportunities'],
				$cached['development']['has_more'],
				$cached['development']['opportunities'],
				$cached['development']['elements_to_develop']
			)
			&& is_array( $cached['development']['phase'] )
			&& is_array( $cached['development']['opportunities'] )
			&& is_array( $cached['development']['elements_to_develop'] )
		) {
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
