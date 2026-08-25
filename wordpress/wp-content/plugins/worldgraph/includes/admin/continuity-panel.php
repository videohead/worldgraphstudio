<?php
/**
 * Continuity Validation Admin Panel for World Graph Studio.
 *
 * Provides a dedicated admin page for viewing and managing continuity issues.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

/**
 * Continuity panel class.
 */
class Continuity_Panel {

	/**
	 * Initialize the continuity panel.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'wp_ajax_worldgraph_run_validation', [ __CLASS__, 'ajax_run_validation' ] );
		add_action( 'wp_ajax_worldgraph_clear_issues', [ __CLASS__, 'ajax_clear_issues' ] );
	}

	/**
	 * Add admin menu page.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'worldgraph-analysis',
			'Continuity Validation',
			'Continuity',
			'manage_options',
			'worldgraph-continuity',
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_scripts( string $hook ): void {
		if ( 'toplevel_page_worldgraph' !== $hook && 'worldgraph_page_worldgraph-continuity' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'worldgraph-continuity',
			WORLDGRAPH_PLUGIN_URL . 'assets/css/continuity-panel.css',
			[],
			WORLDGRAPH_VERSION
		);

		wp_enqueue_script(
			'worldgraph-continuity',
			WORLDGRAPH_PLUGIN_URL . 'assets/js/continuity-panel.js',
			[ 'jquery' ],
			WORLDGRAPH_VERSION,
			true
		);

		wp_localize_script(
			'worldgraph-continuity',
			'worldgraph_continuity',
			[
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'worldgraph_validation' ),
				'selected_project_id' => isset( $_GET['project_id'] ) ? absint( wp_unslash( $_GET['project_id'] ) ) : 0,
				'strings'     => [
					'running'    => 'Running continuity validation...',
					'complete'   => 'Validation complete.',
					'errors'     => 'errors',
					'warnings'   => 'warnings',
					'infos'      => 'info items',
					'clear'      => 'Clear all issues',
					'confirm'    => 'Are you sure you want to clear all continuity issues?',
					'cleared'    => 'Issues cleared.',
					'error'      => 'Error running validation.',
					'no_issues'  => 'No continuity issues found.',
					'filter_all' => 'All',
					'filter_error' => 'Errors',
					'filter_warning' => 'Warnings',
					'filter_info' => 'Info',
				],
			]
		);
	}

	/**
	 * Render the continuity validation admin page.
	 */
	public static function render_page(): void {
		// Handle AJAX clear action.
		if ( isset( $_POST['action'] ) && 'worldgraph_clear_issues' === $_POST['action'] && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'worldgraph_validation' );
			\WorldGraph\Utils\clear_global_continuity_issues();
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'All continuity issues have been cleared.', 'worldgraph' ); ?></p>
			</div>
			<?php
		}

		$selected_project_id = isset( $_GET['project_id'] ) ? absint( wp_unslash( $_GET['project_id'] ) ) : 0;
		$projects            = get_posts( [
			'post_type'      => 'worldgraph_project',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		if ( $selected_project_id > 0 && 'worldgraph_project' === get_post_type( $selected_project_id ) ) {
			$project_result = \WorldGraph\Utils\fetch_continuity_validation( 0, [], $selected_project_id );
			$issues         = $project_result['issues'] ?? [];
		} else {
			$issues = \WorldGraph\Utils\get_global_continuity_issues();
		}

		$summary = self::compute_summary( $issues );

		// Filter by severity if requested.
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		if ( in_array( $filter, [ 'error', 'warning', 'info' ], true ) ) {
			$filtered_issues = \WorldGraph\Utils\filter_issues_by_severity( $issues, $filter );
		} else {
			$filtered_issues = $issues;
		}

		$project_query_arg = $selected_project_id > 0 ? '&project_id=' . rawurlencode( (string) $selected_project_id ) : '';

		// Group by category.
		$by_category = self::group_by_category( $filtered_issues );

		?>
		<div class="wrap worldgraph-continuity-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Continuity Validation', 'worldgraph' ); ?></h1>

			<!-- Action buttons -->
			<div class="worldgraph-actions">
				<label for="worldgraph-project-filter" style="margin-right: 8px; font-weight: 600;"><?php esc_html_e( 'Project:', 'worldgraph' ); ?></label>
				<select id="worldgraph-project-filter" style="min-width: 240px; margin-right: 10px;">
					<option value="0"><?php esc_html_e( 'All projects', 'worldgraph' ); ?></option>
					<?php foreach ( $projects as $project ) : ?>
						<option value="<?php echo esc_attr( (string) $project->ID ); ?>" <?php selected( $selected_project_id, (int) $project->ID ); ?>>
							<?php echo esc_html( $project->post_title ? $project->post_title : sprintf( __( 'Project #%d', 'worldgraph' ), (int) $project->ID ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="button" id="worldgraph-run-validation" class="button button-primary">
					<span class="dashicons dashicons-refresh" style="margin-top: 3px;"></span>
					<?php esc_html_e( 'Run Validation', 'worldgraph' ); ?>
				</button>
				<?php if ( ! empty( $issues ) ) : ?>
					<button type="button" id="worldgraph-clear-all" class="button" style="margin-left: 10px;">
						<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Clear All Issues', 'worldgraph' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<!-- Summary cards -->
			<div class="worldgraph-summary" id="worldgraph-summary">
				<div class="worldgraph-summary-card worldgraph-card-errors">
					<div class="worldgraph-summary-number"><?php echo esc_html( $summary['errors'] ); ?></div>
					<div class="worldgraph-summary-label"><?php esc_html_e( 'Errors', 'worldgraph' ); ?></div>
				</div>
				<div class="worldgraph-summary-card worldgraph-card-warnings">
					<div class="worldgraph-summary-number"><?php echo esc_html( $summary['warnings'] ); ?></div>
					<div class="worldgraph-summary-label"><?php esc_html_e( 'Warnings', 'worldgraph' ); ?></div>
				</div>
				<div class="worldgraph-summary-card worldgraph-card-infos">
					<div class="worldgraph-summary-number"><?php echo esc_html( $summary['infos'] ); ?></div>
					<div class="worldgraph-summary-label"><?php esc_html_e( 'Info', 'worldgraph' ); ?></div>
				</div>
				<div class="worldgraph-summary-card worldgraph-card-total">
					<div class="worldgraph-summary-number"><?php echo esc_html( $summary['total'] ); ?></div>
					<div class="worldgraph-summary-label"><?php esc_html_e( 'Total Issues', 'worldgraph' ); ?></div>
				</div>
			</div>

			<!-- Filter tabs -->
			<div class="worldgraph-filter-tabs">
				<a href="?page=worldgraph-continuity&filter=all<?php echo esc_attr( $project_query_arg ); ?>" class="button <?php echo 'all' === $filter ? 'button-primary' : ''; ?>">
					<?php esc_html_e( 'All', 'worldgraph' ); ?> (<?php echo esc_html( count( $filtered_issues ) ); ?>)
				</a>
				<a href="?page=worldgraph-continuity&filter=error<?php echo esc_attr( $project_query_arg ); ?>" class="button <?php echo 'error' === $filter ? 'button-primary' : ''; ?>">
					<?php esc_html_e( 'Errors', 'worldgraph' ); ?> (<?php echo esc_html( $summary['errors'] ); ?>)
				</a>
				<a href="?page=worldgraph-continuity&filter=warning<?php echo esc_attr( $project_query_arg ); ?>" class="button <?php echo 'warning' === $filter ? 'button-primary' : ''; ?>">
					<?php esc_html_e( 'Warnings', 'worldgraph' ); ?> (<?php echo esc_html( $summary['warnings'] ); ?>)
				</a>
				<a href="?page=worldgraph-continuity&filter=info<?php echo esc_attr( $project_query_arg ); ?>" class="button <?php echo 'info' === $filter ? 'button-primary' : ''; ?>">
					<?php esc_html_e( 'Info', 'worldgraph' ); ?> (<?php echo esc_html( $summary['infos'] ); ?>)
				</a>
			</div>

			<!-- Loading indicator -->
			<div class="worldgraph-loading" id="worldgraph-loading" style="display: none;">
				<span class="spinner is-active"></span>
				<?php esc_html_e( 'Running continuity validation...', 'worldgraph' ); ?>
			</div>

			<!-- Issues list -->
			<?php if ( empty( $filtered_issues ) ) : ?>
				<div class="worldgraph-no-issues">
					<span class="dashicons dashicons-yes-alt"></span>
					<p><?php esc_html_e( 'No continuity issues found. Run validation to check your content.', 'worldgraph' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $by_category as $category => $category_issues ) : ?>
					<div class="worldgraph-category-section">
						<h2><?php echo esc_html( \WorldGraph\Utils\category_label( $category ) ); ?></h2>
						<?php foreach ( $category_issues as $issue ) : ?>
							<div class="worldgraph-issue-card worldgraph-issue-<?php echo esc_attr( $issue['severity'] ?? 'warning' ); ?>">
								<div class="worldgraph-issue-header">
									<span class="worldgraph-issue-severity" style="background-color: <?php echo esc_attr( \WorldGraph\Utils\severity_info( $issue['severity'] ?? 'warning' )['color'] ); ?>">
										<?php echo esc_html( \WorldGraph\Utils\severity_info( $issue['severity'] ?? 'warning' )['label'] ); ?>
									</span>
									<span class="worldgraph-issue-category"><?php echo esc_html( \WorldGraph\Utils\category_label( $issue['category'] ?? 'general' ) ); ?></span>
								</div>
								<div class="worldgraph-issue-description"><?php echo esc_html( $issue['description'] ?? '' ); ?></div>
								<?php if ( ! empty( $issue['entities'] ) && is_array( $issue['entities'] ) ) : ?>
									<div class="worldgraph-issue-entities">
										<?php foreach ( $issue['entities'] as $entity ) : ?>
											<?php
											$entity_id   = absint( $entity['id'] ?? 0 );
											$entity_type = sanitize_key( (string) ( $entity['type'] ?? '' ) );
											$entity_name = $entity['label'] ?? '';
											if ( '' === $entity_name && $entity_id > 0 ) {
												$entity_name = \WorldGraph\Utils\entity_display_name( $entity_type, $entity_id );
											}
											$entity_name = '' !== $entity_name ? $entity_name : ucfirst( $entity_type ) . ( $entity_id ? ' #' . $entity_id : '' );
											$edit_url    = $entity['edit_url'] ?? ( $entity_id ? \WorldGraph\Utils\entity_permalink( $entity_type, $entity_id ) : '' );
											$review_url  = $entity['review_url'] ?? ( $entity_id ? get_permalink( $entity_id ) : '' );
											$scene       = is_array( $entity['scene'] ?? null ) ? $entity['scene'] : null;
											$scene_label = $scene['label'] ?? '';
											?>
											<span class="worldgraph-entity-tag">
												<?php echo esc_html( (string) $entity_name ); ?>
												<?php if ( '' !== $scene_label ) : ?>
													<span class="worldgraph-entity-context"> | <?php echo esc_html( (string) $scene_label ); ?></span>
												<?php endif; ?>
												<?php if ( ! empty( $review_url ) || ! empty( $edit_url ) ) : ?>
													<span class="worldgraph-entity-actions">
														<?php if ( ! empty( $review_url ) ) : ?>
															<a href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Review', 'worldgraph' ); ?></a>
														<?php endif; ?>
														<?php if ( ! empty( $review_url ) && ! empty( $edit_url ) ) : ?>
															<span aria-hidden="true"> | </span>
														<?php endif; ?>
														<?php if ( ! empty( $edit_url ) ) : ?>
															<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'worldgraph' ); ?></a>
														<?php endif; ?>
													</span>
												<?php endif; ?>
											</span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<?php if ( ! empty( $issue['suggestion'] ) ) : ?>
									<div class="worldgraph-issue-suggestion">
										<strong><?php esc_html_e( 'Suggestion:', 'worldgraph' ); ?></strong>
										<?php echo esc_html( $issue['suggestion'] ); ?>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Compute summary counts from issues.
	 *
	 * @param array $issues The issues array.
	 * @return array
	 */
	private static function compute_summary( array $issues ): array {
		$errors   = 0;
		$warnings = 0;
		$infos    = 0;

		foreach ( $issues as $issue ) {
			switch ( $issue['severity'] ?? '' ) {
				case 'error':
					$errors++;
					break;
				case 'warning':
					$warnings++;
					break;
				case 'info':
					$infos++;
					break;
			}
		}

		return [
			'total'    => count( $issues ),
			'errors'   => $errors,
			'warnings' => $warnings,
			'infos'    => $infos,
		];
	}

	/**
	 * Group issues by category.
	 *
	 * @param array $issues The issues array.
	 * @return array
	 */
	private static function group_by_category( array $issues ): array {
		$grouped = [];
		foreach ( $issues as $issue ) {
			$category = $issue['category'] ?? 'general';
			if ( ! isset( $grouped[ $category ] ) ) {
				$grouped[ $category ] = [];
			}
			$grouped[ $category ][] = $issue;
		}
		return $grouped;
	}

	/**
	 * AJAX handler for running validation.
	 */
	public static function ajax_run_validation(): void {
		check_ajax_referer( 'worldgraph_validation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied', 403 );
		}

		$episode_id = isset( $_POST['episode_id'] ) ? absint( $_POST['episode_id'] ) : 0;
		$scene_ids  = isset( $_POST['scene_ids'] ) ? array_map( 'absint', $_POST['scene_ids'] ) : [];
		$project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

		if ( $project_id > 0 && 'worldgraph_project' !== get_post_type( $project_id ) ) {
			wp_send_json_error( 'Invalid project selected.', 400 );
		}

		$result = \WorldGraph\Utils\fetch_continuity_validation( $episode_id, $scene_ids, $project_id );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( 'Validation error: ' . $result['error'], 500 );
		}

		// Store issues.
		\WorldGraph\Utils\store_global_continuity_issues( $result['issues'] );

		wp_send_json_success( [
			'summary'        => [
				'total'    => $result['total_issues'],
				'errors'   => $result['errors'],
				'warnings' => $result['warnings'],
				'infos'    => $result['infos'],
			],
			'issues'         => $result['issues'],
			'scenes_validated' => $result['scenes_validated'],
		] );
	}

	/**
	 * AJAX handler for clearing issues.
	 */
	public static function ajax_clear_issues(): void {
		check_ajax_referer( 'worldgraph_validation', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied', 403 );
		}

		\WorldGraph\Utils\clear_global_continuity_issues();
		wp_send_json_success( 'Issues cleared.' );
	}
}
