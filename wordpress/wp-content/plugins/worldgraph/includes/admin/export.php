<?php
/**
 * World Graph Studio Export admin page.
 *
 * Provides a UI for exporting live World Graph Studio projects to Markdown screenplay format.
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
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
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
	 * Export a World Graph Studio project as markdown.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to export World Graph Studio data.' );
		}
		check_admin_referer( 'worldgraph_export_markdown' );

		$project_id = isset( $_POST['worldgraph_project_id'] ) ? absint( wp_unslash( $_POST['worldgraph_project_id'] ) ) : 0;
		$format     = isset( $_POST['worldgraph_export_format'] ) ? sanitize_key( wp_unslash( $_POST['worldgraph_export_format'] ) ) : 'screenplay';
		if ( ! $project_id ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-export', 'error' => 'empty' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$exporter = new \WorldGraph\Exporter\WorldGraph_Exporter();
		if ( 'storyboard' === $format ) {
			$markdown = $exporter->export_project_storyboard_markdown( $project_id );
			$suffix   = '-storyboard';
		} else {
			$markdown = $exporter->export_project_markdown( $project_id );
			$suffix   = '-screenplay';
		}
		$filename = sanitize_file_name( ( get_the_title( $project_id ) ?: 'worldgraph-export' ) . $suffix ) . '.md';

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $markdown ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is a generated Markdown download, not HTML output.
		echo $markdown;
		exit;
	}

	/**
	 * Render the export page.
	 */
	public static function render_page(): void {
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
		$projects = get_posts( [
			'post_type'      => 'worldgraph_project',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		?>
		<div class="wrap worldgraph-export-wrap">
			<h1><?php esc_html_e( 'Export World Graph Studio Script', 'worldgraph' ); ?></h1>

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Export failed:', 'worldgraph' ); ?></strong> <?php echo esc_html( $error ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Export the current World Graph Studio project as a Markdown screenplay or storyboard file based on the live project data, scene records, shots, and storyboard frames.', 'worldgraph' ); ?>
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
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Export Markdown', 'worldgraph' ) ); ?>
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
