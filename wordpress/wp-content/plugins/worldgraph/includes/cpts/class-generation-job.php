<?php
/**
 * Job record type admin behavior.
 *
 * Connection -> Template -> Job. A Job is the durable operational record for
 * one generation request: what was asked for, which Connection and Template
 * answered, everything the provider returned, and the media it produced. The
 * native WordPress list table and edit screen present it read-only.
 *
 * @package WorldGraph
 */

namespace WorldGraph\CPT;

use WorldGraph\Utils\Generation_Batch;
use WorldGraph\Utils\Generation_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job record type.
 */
class Generation_Job {

	/**
	 * Job post type slug.
	 */
	const POST_TYPE = 'worldgraph_gen';

	/**
	 * List table views, and the raw statuses each one covers.
	 *
	 * @return array<string, array{label: string, statuses: array<int, string>}>
	 */
	public static function views(): array {
		return [
			'queued'    => [
				'label'    => __( 'Queued', 'worldgraph' ),
				'statuses' => [ 'staged', 'queued', 'submitting', 'dispatching' ],
			],
			'running'   => [
				'label'    => __( 'Running', 'worldgraph' ),
				'statuses' => [ 'submitted', 'polling', 'importing', 'import_retry', 'import_cleanup' ],
			],
			'completed' => [
				'label'    => __( 'Completed', 'worldgraph' ),
				'statuses' => [ 'completed' ],
			],
			'failed'    => [
				'label'    => __( 'Failed', 'worldgraph' ),
				'statuses' => [ 'failed', 'cancelled' ],
			],
		];
	}

	/**
	 * Statuses a Job can hold, and how they read in the admin.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return [
			'staged'         => __( 'Staged', 'worldgraph' ),
			'queued'         => __( 'Queued', 'worldgraph' ),
			'submitting'     => __( 'Submitting', 'worldgraph' ),
			'dispatching'    => __( 'Dispatching', 'worldgraph' ),
			'submitted'      => __( 'Running', 'worldgraph' ),
			'polling'        => __( 'Running', 'worldgraph' ),
			'importing'      => __( 'Importing media', 'worldgraph' ),
			'import_retry'   => __( 'Retrying import', 'worldgraph' ),
			'import_cleanup' => __( 'Finishing import', 'worldgraph' ),
			'completed'      => __( 'Completed', 'worldgraph' ),
			'failed'         => __( 'Failed', 'worldgraph' ),
			'cancelled'      => __( 'Cancelled', 'worldgraph' ),
		];
	}

	/**
	 * Register the Job admin surface on native WordPress hooks.
	 */
	public static function init(): void {
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', [ __CLASS__, 'sortable_columns' ] );
		add_filter( 'display_post_states', [ __CLASS__, 'post_states' ], 10, 2 );
		add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 10, 2 );
		add_filter( 'use_block_editor_for_post_type', [ __CLASS__, 'disable_block_editor' ], 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, [ __CLASS__, 'register_meta_boxes' ] );
		add_action( 'edit_form_after_title', [ __CLASS__, 'render_read_only_notice' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'views_edit-' . self::POST_TYPE, [ __CLASS__, 'status_views' ] );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'render_filters' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_query' ] );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, [ __CLASS__, 'bulk_actions' ] );
		add_action( 'manage_posts_extra_tablenav', [ __CLASS__, 'render_batch_runner' ] );
		add_action( 'admin_post_worldgraph_run_generation_batch', [ __CLASS__, 'handle_run_batch' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_batch_notice' ] );
	}

	/**
	 * Hide WordPress's storage status so the generation Status column remains
	 * the authoritative operational state.
	 *
	 * @param array<string, string> $states Native post-state labels.
	 * @param \WP_Post              $post   Current post.
	 * @return array<string, string>
	 */
	public static function post_states( array $states, \WP_Post $post ): array {
		if ( self::POST_TYPE === $post->post_type ) {
			unset( $states['draft'] );
		}

		return $states;
	}

	/**
	 * Offer a manual worker run so a waiting Job does not need the next
	 * WP-Cron tick to make progress.
	 *
	 * @param string $which Tablenav position.
	 */
	public static function render_batch_runner( string $which ): void {
		$screen = get_current_screen();
		if ( 'top' !== $which || ! $screen instanceof \WP_Screen || self::POST_TYPE !== $screen->post_type || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="alignleft actions"><a class="button" href="%1$s">%2$s</a></div>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=worldgraph_run_generation_batch' ), 'worldgraph_run_generation_batch' ) ),
			esc_html__( 'Run Batch Now', 'worldgraph' )
		);
	}

	/**
	 * Run the generation worker, then return to the Jobs list.
	 */
	public static function handle_run_batch(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'worldgraph_run_generation_batch' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'worldgraph' ) );
		}

		Generation_Batch::process();
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&worldgraph_batch=ran' ) );
		exit;
	}

	/**
	 * Confirm a manual worker run.
	 */
	public static function render_batch_notice(): void {
		$screen = get_current_screen();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only confirmation.
		if ( ! $screen instanceof \WP_Screen || self::POST_TYPE !== $screen->post_type || ! isset( $_GET['worldgraph_batch'] ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Generation batch run triggered.', 'worldgraph' ) . '</p></div>';
	}

	/**
	 * Replace the post-status views with generation status views.
	 *
	 * @param array<string, string> $views Existing views.
	 * @return array<string, string>
	 */
	public static function status_views( array $views ): array {
		$current = self::requested_view();
		$base    = admin_url( 'edit.php?post_type=' . self::POST_TYPE );

		$links = [
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'worldgraph' ),
				esc_html( (string) self::count_view( [] ) )
			),
		];

		foreach ( self::views() as $key => $view ) {
			$links[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'worldgraph_gen_view', $key, $base ) ),
				$key === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $view['label'] ),
				esc_html( (string) self::count_view( $view['statuses'] ) )
			);
		}

		return $links;
	}

	/**
	 * Count the Jobs a view covers.
	 *
	 * @param array<int, string> $statuses Generation statuses, or empty for all.
	 * @return int
	 */
	private static function count_view( array $statuses ): int {
		$args = [
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		if ( ! empty( $statuses ) ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded admin list table counts.
				[
					'key'     => '_worldgraph_gen_status',
					'value'   => $statuses,
					'compare' => 'IN',
				],
			];
		}

		$query = new \WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * The requested view key, or '' for all Jobs.
	 *
	 * @return string
	 */
	private static function requested_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list table filtering only.
		$view = isset( $_GET['worldgraph_gen_view'] ) ? sanitize_key( wp_unslash( $_GET['worldgraph_gen_view'] ) ) : '';

		return isset( self::views()[ $view ] ) ? $view : '';
	}

	/**
	 * Render the Connection and Template filters above the list table.
	 *
	 * @param string $post_type Current list table post type.
	 */
	public static function render_filters( string $post_type ): void {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		self::render_post_filter( 'worldgraph_gen_connection', 'worldgraph_conn', __( 'All Connections', 'worldgraph' ) );
		self::render_post_filter( 'worldgraph_gen_template', 'worldgraph_template', __( 'All Templates', 'worldgraph' ) );
	}

	/**
	 * Render one related-record filter dropdown.
	 *
	 * @param string $name      Query argument name.
	 * @param string $post_type Related post type.
	 * @param string $any_label Label for the unfiltered option.
	 */
	private static function render_post_filter( string $name, string $post_type, string $any_label ): void {
		$posts = get_posts(
			[
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'posts_per_page'   => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			]
		);
		if ( empty( $posts ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list table filtering only.
		$selected = isset( $_GET[ $name ] ) ? absint( $_GET[ $name ] ) : 0;
		?>
		<label class="screen-reader-text" for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $any_label ); ?></label>
		<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>">
			<option value="0"><?php echo esc_html( $any_label ); ?></option>
			<?php foreach ( $posts as $post ) : ?>
				<option value="<?php echo esc_attr( (string) $post->ID ); ?>" <?php selected( $selected, $post->ID ); ?>>
					<?php echo esc_html( $post->post_title ? $post->post_title : '#' . $post->ID ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Apply the Job list table filters to the main admin query.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public static function filter_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$meta_query = [];
		$view       = self::requested_view();
		if ( '' !== $view ) {
			$meta_query[] = [
				'key'     => '_worldgraph_gen_status',
				'value'   => self::views()[ $view ]['statuses'],
				'compare' => 'IN',
			];
		}

		foreach ( [ 'worldgraph_gen_connection' => '_worldgraph_gen_connection_id', 'worldgraph_gen_template' => '_worldgraph_gen_template_id' ] as $argument => $meta_key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list table filtering only.
			$value = isset( $_GET[ $argument ] ) ? absint( $_GET[ $argument ] ) : 0;
			if ( $value ) {
				$meta_query[] = [
					'key'   => $meta_key,
					'value' => $value,
				];
			}
		}

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded admin list table.
		}
	}

	/**
	 * A Job cannot be edited in bulk, only removed.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function bulk_actions( array $actions ): array {
		unset( $actions['edit'] );

		return $actions;
	}

	/**
	 * Keep long provider payloads inside their meta boxes.
	 */
	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_add_inline_style( 'common', '.worldgraph-gen-pre{white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto;margin:0;}' );
	}

	/**
	 * Define the Job list table columns.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		return [
			'cb'                        => $columns['cb'] ?? '',
			'title'                     => __( 'Job', 'worldgraph' ),
			'worldgraph_gen_status'     => __( 'Status', 'worldgraph' ),
			'worldgraph_gen_type'       => __( 'Output', 'worldgraph' ),
			'worldgraph_gen_connection' => __( 'Connection', 'worldgraph' ),
			'worldgraph_gen_template'   => __( 'Template', 'worldgraph' ),
			'worldgraph_gen_asset'      => __( 'Asset', 'worldgraph' ),
			'worldgraph_gen_created'    => __( 'Created', 'worldgraph' ),
		];
	}

	/**
	 * Allow sorting by the columns backed by a single meta value.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public static function sortable_columns( array $columns ): array {
		$columns['worldgraph_gen_created'] = 'date';

		return $columns;
	}

	/**
	 * Render one Job list table cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Job post ID.
	 */
	public static function column_content( string $column, int $post_id ): void {
		if ( 'worldgraph_gen_status' === $column ) {
			$status        = (string) get_post_meta( $post_id, '_worldgraph_gen_status', true );
			$statuses      = self::statuses();
			$error         = (string) get_post_meta( $post_id, '_worldgraph_gen_error', true );
			$batch_id      = absint( get_post_meta( $post_id, '_worldgraph_gen_batch_id', true ) );
			$batch_kind    = sanitize_key( (string) get_post_meta( $post_id, '_worldgraph_gen_batch_kind', true ) );
			$remote_job_id = trim( (string) get_post_meta( $post_id, '_worldgraph_gen_job_id', true ) );
			$fallback      = '' !== $status ? ucwords( str_replace( [ '_', '-' ], ' ', $status ) ) : __( 'Unknown', 'worldgraph' );
			echo esc_html( $statuses[ $status ] ?? $fallback );
			if ( '' !== $error ) {
				echo '<br /><span class="description">' . esc_html( $error ) . '</span>';
			}
			if ( ! $batch_id && '' !== $batch_kind ) {
				$batch_id = $post_id;
			}
			if ( $batch_id ) {
				printf(
					'<br /><span class="description">%1$s #%2$d</span>',
					esc_html__( 'Batch', 'worldgraph' ),
					$batch_id
				);
			}
			if ( '' !== $remote_job_id ) {
				printf(
					'<br /><span class="description">%1$s %2$s</span>',
					esc_html__( 'Remote job:', 'worldgraph' ),
					esc_html( $remote_job_id )
				);
			}
			return;
		}

		if ( 'worldgraph_gen_type' === $column ) {
			$type = (string) get_post_meta( $post_id, '_worldgraph_gen_type', true );
			echo esc_html( '' !== $type ? ucfirst( $type ) : '—' );
			return;
		}

		if ( 'worldgraph_gen_connection' === $column ) {
			self::render_post_link( absint( get_post_meta( $post_id, '_worldgraph_gen_connection_id', true ) ), 'worldgraph_conn' );
			return;
		}

		if ( 'worldgraph_gen_template' === $column ) {
			self::render_post_link( absint( get_post_meta( $post_id, '_worldgraph_gen_template_id', true ) ), 'worldgraph_template' );
			return;
		}

		if ( 'worldgraph_gen_asset' === $column ) {
			self::render_post_link( absint( get_post_meta( $post_id, '_worldgraph_gen_asset_id', true ) ), 'worldgraph_asset' );
			return;
		}

		if ( 'worldgraph_gen_created' === $column ) {
			$created = (string) get_post_meta( $post_id, '_worldgraph_gen_created', true );
			echo esc_html( '' !== $created ? $created : get_the_date( '', $post_id ) );
		}
	}

	/**
	 * Link a related record, or print a placeholder when it is unset or gone.
	 *
	 * @param int    $post_id   Related post ID.
	 * @param string $post_type Expected post type.
	 */
	private static function render_post_link( int $post_id, string $post_type ): void {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || $post_type !== $post->post_type ) {
			echo '—';
			return;
		}

		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( (string) get_edit_post_link( $post_id ) ),
			esc_html( $post->post_title ? $post->post_title : '#' . $post_id )
		);
	}

	/**
	 * A Job is written by the worker, so offer only view and delete.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Current post.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		unset( $actions['inline hide-if-no-js'], $actions['view'] );
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$actions['edit'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( (string) get_edit_post_link( $post->ID ) ),
				esc_html__( 'View', 'worldgraph' )
			);
		}

		return $actions;
	}

	/**
	 * Jobs are read-only records, so keep them on the classic screen where the
	 * meta boxes below are the whole interface.
	 *
	 * @param bool   $use_block_editor Whether to use the block editor.
	 * @param string $post_type        Post type being edited.
	 * @return bool
	 */
	public static function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		return self::POST_TYPE === $post_type ? false : $use_block_editor;
	}

	/**
	 * Replace the Job edit screen with read-only detail panels.
	 *
	 * @param \WP_Post $post Job post.
	 */
	public static function register_meta_boxes( \WP_Post $post ): void {
		remove_post_type_support( self::POST_TYPE, 'editor' );

		add_meta_box(
			'worldgraph_gen_details',
			__( 'Job Detail', 'worldgraph' ),
			[ __CLASS__, 'render_details_meta_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'worldgraph_gen_events',
			__( 'Job Log', 'worldgraph' ),
			[ __CLASS__, 'render_events_meta_box' ],
			self::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'worldgraph_gen_result',
			__( 'Provider Response', 'worldgraph' ),
			[ __CLASS__, 'render_result_meta_box' ],
			self::POST_TYPE,
			'normal',
			'low'
		);
	}

	/**
	 * Tell the operator the screen is a record, not an editor.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render_read_only_notice( \WP_Post $post ): void {
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		echo '<div class="notice notice-info inline"><p>'
			. esc_html__( 'A Job is a read-only record of one generation request. The generation worker owns its values.', 'worldgraph' )
			. '</p></div>';
	}

	/**
	 * Render the Connection -> Template -> Job summary.
	 *
	 * @param \WP_Post $post Job post.
	 */
	public static function render_details_meta_box( \WP_Post $post ): void {
		$statuses = self::statuses();
		$status   = (string) get_post_meta( $post->ID, '_worldgraph_gen_status', true );
		$rows     = [
			__( 'Status', 'worldgraph' )         => $statuses[ $status ] ?? $status,
			__( 'Output type', 'worldgraph' )    => (string) get_post_meta( $post->ID, '_worldgraph_gen_type', true ),
			__( 'Provider', 'worldgraph' )       => (string) get_post_meta( $post->ID, '_worldgraph_gen_provider_type', true ),
			__( 'Adapter', 'worldgraph' )        => (string) get_post_meta( $post->ID, '_worldgraph_gen_adapter', true ),
			__( 'Provider job ID', 'worldgraph' ) => (string) get_post_meta( $post->ID, '_worldgraph_gen_job_id', true ),
			__( 'Workflow', 'worldgraph' )       => (string) get_post_meta( $post->ID, '_worldgraph_gen_workflow', true ),
			__( 'Created', 'worldgraph' )        => (string) get_post_meta( $post->ID, '_worldgraph_gen_created', true ),
			__( 'Error', 'worldgraph' )          => (string) get_post_meta( $post->ID, '_worldgraph_gen_error', true ),
		];
		?>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'worldgraph' ); ?></th>
					<td><?php self::render_post_link( absint( get_post_meta( $post->ID, '_worldgraph_gen_connection_id', true ) ), 'worldgraph_conn' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Template', 'worldgraph' ); ?></th>
					<td><?php self::render_post_link( absint( get_post_meta( $post->ID, '_worldgraph_gen_template_id', true ) ), 'worldgraph_template' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Asset', 'worldgraph' ); ?></th>
					<td><?php self::render_post_link( absint( get_post_meta( $post->ID, '_worldgraph_gen_asset_id', true ) ), 'worldgraph_asset' ); ?></td>
				</tr>
				<?php foreach ( $rows as $label => $value ) : ?>
					<?php if ( '' !== trim( (string) $value ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( (string) $label ); ?></th>
							<td><?php echo esc_html( (string) $value ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Prompt', 'worldgraph' ); ?></th>
					<td><pre class="worldgraph-gen-pre"><?php echo esc_html( (string) get_post_meta( $post->ID, '_worldgraph_gen_prompt', true ) ); ?></pre></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the Job's own event journal.
	 *
	 * @param \WP_Post $post Job post.
	 */
	public static function render_events_meta_box( \WP_Post $post ): void {
		$events = array_reverse( Generation_Log::for_job( $post->ID ) );
		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'No events have been recorded for this Job yet.', 'worldgraph' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col" style="width:150px;"><?php esc_html_e( 'Time', 'worldgraph' ); ?></th>
					<th scope="col" style="width:70px;"><?php esc_html_e( 'Level', 'worldgraph' ); ?></th>
					<th scope="col" style="width:140px;"><?php esc_html_e( 'Source', 'worldgraph' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'worldgraph' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $event['time'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $event['level'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $event['source'] ?? '' ) ); ?></td>
						<td>
							<?php echo esc_html( (string) ( $event['message'] ?? '' ) ); ?>
							<?php if ( ! empty( $event['context'] ) ) : ?>
								<details>
									<summary><?php esc_html_e( 'Context', 'worldgraph' ); ?></summary>
									<pre class="worldgraph-gen-pre"><?php echo esc_html( (string) wp_json_encode( $event['context'], JSON_PRETTY_PRINT ) ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the raw payload the Connection returned for this Job.
	 *
	 * @param \WP_Post $post Job post.
	 */
	public static function render_result_meta_box( \WP_Post $post ): void {
		$result = get_post_meta( $post->ID, '_worldgraph_gen_result', true );
		if ( empty( $result ) ) {
			echo '<p>' . esc_html__( 'The Connection has not returned a result for this Job yet.', 'worldgraph' ) . '</p>';
			return;
		}

		echo '<pre class="worldgraph-gen-pre">' . esc_html( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) ) . '</pre>';
	}
}
