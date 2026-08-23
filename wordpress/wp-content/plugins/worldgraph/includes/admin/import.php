<?php
/**
 * World Graph Studio Import admin page.
 *
 * Provides a UI for importing World Graph Studio JSON documents (e.g. the example workflow).
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import admin page class.
 */
class Import {

	/**
	 * Initialize the import admin page.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'admin_post_worldgraph_import_json', [ __CLASS__, 'handle_import' ] );
	}

	/**
	 * Add the Import submenu page.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'worldgraph-plugins',
			'Import',
			'Import',
			'manage_options',
			'worldgraph-import',
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Enqueue admin scripts on the import page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( string $hook ): void {
		if ( 'worldgraph_page_worldgraph-import' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'worldgraph-import',
			WORLDGRAPH_PLUGIN_URL . 'assets/js/import.js',
			[ 'jquery' ],
			WORLDGRAPH_VERSION,
			true
		);

		wp_localize_script( 'worldgraph-import', 'worldgraphImport', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'worldgraph_import' ),
		] );
	}

	/**
	 * Handle the import form submission.
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to import World Graph Studio data.' );
		}
		check_admin_referer( 'worldgraph_import' );

		$json     = '';
		$tmp_name = isset( $_FILES['worldgraph_json_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['worldgraph_json_file']['tmp_name'] ) ) : '';
		if ( '' !== $tmp_name && is_uploaded_file( $tmp_name ) ) {
			$json = file_get_contents( $tmp_name );
		} elseif ( isset( $_POST['worldgraph_json'] ) ) {
			$json = wp_unslash( $_POST['worldgraph_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON must remain intact and is schema-validated by the importer.
		}

		$overwrite = ! empty( $_POST['worldgraph_overwrite'] );

		if ( empty( trim( (string) $json ) ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-import', 'error' => 'empty' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$importer = new \WorldGraph\Importer\WorldGraph_Importer();
		$result   = $importer->import( (string) $json, [ 'overwrite' => $overwrite ] );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-import', 'error' => rawurlencode( $result->get_error_message() ) ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Store the report in a transient for display after redirect.
		set_transient( 'worldgraph_import_report', $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-import', 'imported' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the import page.
	 */
	public static function render_page(): void {
		$report = get_transient( 'worldgraph_import_report' );
		delete_transient( 'worldgraph_import_report' );

		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
		$imported = isset( $_GET['imported'] ) ? '1' === sanitize_text_field( wp_unslash( $_GET['imported'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
		?>
		<div class="wrap worldgraph-import-wrap">
			<h1><?php esc_html_e( 'Import World Graph Studio JSON', 'worldgraph' ); ?></h1>

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Import failed:', 'worldgraph' ); ?></strong> <?php echo esc_html( $error ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $imported && is_array( $report ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Import completed successfully.', 'worldgraph' ); ?></strong></p>
				</div>
				<?php self::render_report( $report ); ?>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Upload a World Graph Studio JSON file (for example, the full-featured Little Red Riding Hood version 1.2 example) to create a complete miniature project: Project, World, Characters, Locations, Props, Organizations, Episodes, Scenes, Shots, Sounds, Assets, Editorial Artifacts, and Sequence.', 'worldgraph' ); ?>
			</p>

			<form method="post" id="worldgraph-import-form" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="worldgraph_import_json" />
				<?php wp_nonce_field( 'worldgraph_import' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="worldgraph_json_file"><?php esc_html_e( 'World Graph Studio JSON File', 'worldgraph' ); ?></label></th>
						<td>
							<input type="file" name="worldgraph_json_file" id="worldgraph_json_file" accept=".json,application/json" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Select a JSON document that follows the World Graph Studio import contract, or use the full-featured version 1.2 example workflow document.', 'worldgraph' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'worldgraph' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="worldgraph_overwrite" value="1" />
								<?php esc_html_e( 'Overwrite existing entities with the same external ID', 'worldgraph' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Optional fields are patched: omitted keys are preserved, while explicitly empty values are cleared.', 'worldgraph' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Import World Graph Studio JSON', 'worldgraph' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Examples', 'worldgraph' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Full-featured version 1.2 (recommended):', 'worldgraph' ); ?></strong>
				<code>about/example-workflow/little-red-riding-hood-full-featured.worldgraph.json</code>
			</p>
			<p>
				<strong><?php esc_html_e( 'Legacy version 1.1 compatibility fixture:', 'worldgraph' ); ?></strong>
				<code>about/example-workflow/little-red-riding-hood.worldgraph.json</code>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the import report.
	 *
	 * @param array $report Import report.
	 */
	private static function render_report( array $report ): void {
		$totals = $report['totals'] ?? [];
		?>
		<h2><?php esc_html_e( 'Import Report', 'worldgraph' ); ?></h2>

		<h3><?php esc_html_e( 'Resolved Import Entities', 'worldgraph' ); ?></h3>
		<table class="widefat striped" style="max-width:600px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'CPT', 'worldgraph' ); ?></th>
					<th><?php esc_html_e( 'Count', 'worldgraph' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $totals as $cpt => $count ) : ?>
					<tr>
						<td><?php echo esc_html( $cpt ); ?></td>
						<td><?php echo esc_html( (string) $count ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $report['created'] ) ) : ?>
			<h3><?php esc_html_e( 'Created', 'worldgraph' ); ?></h3>
			<ul>
				<?php foreach ( $report['created'] as $entry ) : ?>
					<li><?php echo esc_html( $entry ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $report['updated'] ) ) : ?>
			<h3><?php esc_html_e( 'Updated', 'worldgraph' ); ?></h3>
			<ul>
				<?php foreach ( $report['updated'] as $entry ) : ?>
					<li><?php echo esc_html( $entry ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $report['skipped'] ) ) : ?>
			<h3><?php esc_html_e( 'Skipped', 'worldgraph' ); ?></h3>
			<ul>
				<?php foreach ( $report['skipped'] as $entry ) : ?>
					<li><?php echo esc_html( $entry ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $report['errors'] ) ) : ?>
			<h3><?php esc_html_e( 'Errors', 'worldgraph' ); ?></h3>
			<ul>
				<?php foreach ( $report['errors'] as $entry ) : ?>
					<li><?php echo esc_html( $entry ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $report['sequence'] ) ) : ?>
			<h3><?php esc_html_e( 'Sequence', 'worldgraph' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %1$s: sequence title, %2$d: scene count */
					esc_html__( 'Sequence "%1$s" created with %2$d scenes in order.', 'worldgraph' ),
					esc_html( $report['sequence']['title'] ),
					absint( $report['sequence']['order'] )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}
}
