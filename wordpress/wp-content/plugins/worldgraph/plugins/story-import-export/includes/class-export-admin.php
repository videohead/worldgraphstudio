<?php
/**
 * Story Import & Export plugin admin export page.
 *
 * Provides a UI for exporting live World Graph Studio projects to canonical
 * JSON, Markdown screenplay, or Markdown storyboard files.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export admin page class.
 */
class Export {

	/**
	 * Initialize the export admin page.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 20 );
		add_action( 'admin_post_worldgraph_export_markdown', [ __CLASS__, 'handle_export' ] );
	}

	/**
	 * Add the Export submenu page.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'worldgraph-plugins',
			'Export',
			'Export',
			'manage_options',
			'worldgraph-export',
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Export a World Graph Studio project in the selected portable format.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export World Graph Studio data.', 'worldgraph' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'worldgraph_export_markdown' );

		$project_id = isset( $_POST['worldgraph_project_id'] ) ? absint( wp_unslash( $_POST['worldgraph_project_id'] ) ) : 0;
		$format     = isset( $_POST['worldgraph_export_format'] ) ? sanitize_key( wp_unslash( $_POST['worldgraph_export_format'] ) ) : 'screenplay';
		if ( ! $project_id ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-export', 'error' => 'empty' ], admin_url( 'admin.php' ) ) );
			exit;
		}
		$project = get_post( $project_id );
		if ( ! $project instanceof \WP_Post || 'worldgraph_project' !== $project->post_type ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-export', 'error' => 'invalid_project' ], admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( ! current_user_can( 'read_post', $project_id ) ) {
			wp_die( esc_html__( 'You do not have permission to read this World Graph Studio project.', 'worldgraph' ), '', [ 'response' => 403 ] );
		}
		if ( ! in_array( $format, [ 'json', 'screenplay', 'storyboard' ], true ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-export', 'error' => 'invalid_format' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$basename = sanitize_file_name( get_the_title( $project_id ) ?: 'worldgraph-export' );
		$basename = $basename ?: 'worldgraph-export';
		if ( 'json' === $format ) {
			$content   = ( new \WorldGraph\Exporter\WorldGraph_JSON_Exporter() )->export_project_json( $project_id );
			$filename  = $basename . '.worldgraph.json';
		} else {
			$exporter = new \WorldGraph\Exporter\WorldGraph_Exporter();
			if ( 'storyboard' === $format ) {
				$content  = $exporter->export_project_storyboard_markdown( $project_id );
				$filename = $basename . '-storyboard.md';
			} else {
				$content  = $exporter->export_project_markdown( $project_id );
				$filename = $basename . '-screenplay.md';
			}
		}

		if ( is_wp_error( $content ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-export', 'error' => 'export_failed' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$upload = wp_upload_bits( $filename, null, $content );
		if ( ! empty( $upload['error'] ) || empty( $upload['url'] ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-export', 'error' => 'upload_failed' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( esc_url_raw( $upload['url'] ) );
		exit;
	}

	/**
	 * Render the export page.
	 */
	public static function render_page(): void {
		$error          = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
		$error_messages = [
			'empty'           => __( 'Select a project to export.', 'worldgraph' ),
			'invalid_project' => __( 'The selected project could not be found.', 'worldgraph' ),
			'invalid_format'  => __( 'Choose a supported export format.', 'worldgraph' ),
			'export_failed'   => __( 'The project export could not be generated.', 'worldgraph' ),
			'upload_failed'   => __( 'The export was generated but could not be saved to the WordPress uploads directory.', 'worldgraph' ),
		];
		$error_message = $error_messages[ $error ] ?? $error;
		$projects      = get_posts( [
			'post_type'      => 'worldgraph_project',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		$projects      = array_values( array_filter( $projects, static fn( \WP_Post $project ): bool => current_user_can( 'read_post', $project->ID ) ) );
		?>
		<div class="wrap worldgraph-export-wrap">
			<h1><?php esc_html_e( 'Export World Graph Studio Project', 'worldgraph' ); ?></h1>

			<?php if ( $error_message ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Export failed:', 'worldgraph' ); ?></strong> <?php echo esc_html( $error_message ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Export the current project as canonical World Graph Studio JSON, a Markdown screenplay, or a Markdown storyboard based on the live project data.', 'worldgraph' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="worldgraph_export_markdown" />
				<?php wp_nonce_field( 'worldgraph_export_markdown' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="worldgraph_project_id"><?php esc_html_e( 'Project', 'worldgraph' ); ?></label></th>
						<td>
							<select name="worldgraph_project_id" id="worldgraph_project_id" class="regular-text">
								<option value=""><?php esc_html_e( 'Select a project', 'worldgraph' ); ?></option>
								<?php foreach ( $projects as $project ) : ?>
									<option value="<?php echo esc_attr( (string) $project->ID ); ?>"><?php echo esc_html( $project->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="worldgraph_export_format"><?php esc_html_e( 'Format', 'worldgraph' ); ?></label></th>
						<td>
							<select name="worldgraph_export_format" id="worldgraph_export_format" class="regular-text">
								<option value="screenplay"><?php esc_html_e( 'Markdown Screenplay', 'worldgraph' ); ?></option>
								<option value="storyboard"><?php esc_html_e( 'Markdown Storyboard', 'worldgraph' ); ?></option>
								<option value="json"><?php esc_html_e( 'World Graph Studio JSON', 'worldgraph' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Export Project', 'worldgraph' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Example Output', 'worldgraph' ); ?></h2>
			<p>
				<?php esc_html_e( 'The example screenplay export is available at:', 'worldgraph' ); ?>
				<code>about/example-workflow/Little-Red-Riding-Hood-Screenplay-Example-Export.md</code>
			</p>
		</div>
		<?php
	}
}
