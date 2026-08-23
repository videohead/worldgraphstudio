<?php
/**
 * Plugin Name: World Graph Studio - Fountain Import
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Convert Fountain screenplay files to FDX in the browser and import them into the World Graph Studio Story Graph.
 * Version: 1.0.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package WorldGraphFountain
 */

namespace WorldGraphFountain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WORLDGRAPH_FOUNTAIN_VERSION', '1.0.0' );
define( 'WORLDGRAPH_FOUNTAIN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize the Fountain import extension.
 */
function init(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\add_admin_page' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
	add_action( 'admin_post_worldgraph_import_fountain', __NAMESPACE__ . '\handle_import' );
}

if ( did_action( 'plugins_loaded' ) ) {
	init();
} else {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\init' );
}

/**
 * Add the Fountain import page.
 */
function add_admin_page(): void {
	add_submenu_page(
		'worldgraph-plugins',
		'Import Fountain',
		'Import Fountain',
		'manage_worldgraph',
		'worldgraph-fountain',
		__NAMESPACE__ . '\render_admin_page'
	);
}

/**
 * Enqueue the existing FDX parser followed by the Fountain converter.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function enqueue_assets( string $hook_suffix ): void {
	if ( ! isset( $_GET['page'] ) || 'worldgraph-fountain' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing.
		return;
	}

	wp_enqueue_script(
		'worldgraph-fountain-fdx-parser',
		WORLDGRAPH_FOUNTAIN_PLUGIN_URL . '../fdx/js/fdx-import.js',
		[],
		WORLDGRAPH_FOUNTAIN_VERSION,
		true
	);
	wp_enqueue_script(
		'worldgraph-fountain-import',
		WORLDGRAPH_FOUNTAIN_PLUGIN_URL . 'js/fountain-import.js',
		[ 'worldgraph-fountain-fdx-parser' ],
		WORLDGRAPH_FOUNTAIN_VERSION,
		true
	);
}

/**
 * Handle the parsed Fountain document submitted by the browser converter.
 */
function handle_import(): void {
	if ( ! current_user_can( 'manage_worldgraph' ) ) {
		wp_die( 'You do not have permission to import Fountain files.' );
	}

	check_admin_referer( 'worldgraph_fountain_import' );
	$json      = isset( $_POST['worldgraph_fountain_json'] ) ? wp_unslash( $_POST['worldgraph_fountain_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON must remain intact and is schema-validated by the importer.
	$overwrite = ! empty( $_POST['worldgraph_fountain_overwrite'] );

	if ( '' === trim( (string) $json ) ) {
		redirect_with_error( 'Choose a valid Fountain file and wait for it to be parsed before importing.' );
	}

	$importer = new \WorldGraph\Importer\WorldGraph_Importer();
	$result   = $importer->import( (string) $json, [ 'overwrite' => $overwrite ] );

	if ( is_wp_error( $result ) ) {
		redirect_with_error( $result->get_error_message() );
	}

	set_transient( 'worldgraph_fountain_import_report', $result, MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-fountain', 'imported' => '1' ], admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Redirect back to the Fountain page with an error.
 *
 * @param string $message Error message.
 */
function redirect_with_error( string $message ): void {
	wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-fountain', 'error' => rawurlencode( $message ) ], admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Render the Fountain import page.
 */
function render_admin_page(): void {
	if ( ! current_user_can( 'manage_worldgraph' ) ) {
		wp_die( 'You do not have permission to import Fountain files.' );
	}

	$report   = get_transient( 'worldgraph_fountain_import_report' );
	$error    = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
	$imported = isset( $_GET['imported'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['imported'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
	delete_transient( 'worldgraph_fountain_import_report' );
	?>
	<div class="wrap worldgraph-fountain-wrap">
		<h1><?php esc_html_e( 'Import Fountain', 'worldgraph' ); ?></h1>
		<?php if ( $error ) : ?>
			<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Import failed:', 'worldgraph' ); ?></strong> <?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
		<?php if ( $imported && is_array( $report ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Fountain import completed.', 'worldgraph' ); ?></p></div>
			<?php render_report( $report ); ?>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Upload a .fountain or .fountain screenplay. The file is converted to Final Draft XML locally, then passed through the existing FDX importer.', 'worldgraph' ); ?></p>
		<form method="post" id="worldgraph-fountain-import-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="worldgraph_import_fountain" />
			<?php wp_nonce_field( 'worldgraph_fountain_import' ); ?>
			<input type="hidden" name="worldgraph_fountain_json" id="worldgraph_fountain_json" value="" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="worldgraph_fountain_file"><?php esc_html_e( 'Fountain file', 'worldgraph' ); ?></label></th>
					<td>
						<input type="file" id="worldgraph_fountain_file" accept=".fountain,.spmd,.txt,text/plain" />
						<p class="description"><?php esc_html_e( 'The conversion and FDX parsing happen locally in your browser. Only the generated World Graph JSON is submitted to WordPress.', 'worldgraph' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'worldgraph' ); ?></th>
					<td><label><input type="checkbox" name="worldgraph_fountain_overwrite" value="1" /> <?php esc_html_e( 'Overwrite existing entities with the same generated external ID', 'worldgraph' ); ?></label></td>
				</tr>
			</table>
			<p id="worldgraph-fountain-status" class="description" role="status"></p>
			<?php submit_button( __( 'Import Fountain screenplay', 'worldgraph' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Render a compact import report.
 *
 * @param array $report Import report.
 */
function render_report( array $report ): void {
	$totals = $report['totals'] ?? [];
	if ( empty( $totals ) ) {
		return;
	}
	?>
	<h2><?php esc_html_e( 'Import Report', 'worldgraph' ); ?></h2>
	<table class="widefat striped" style="max-width:600px"><tbody>
	<?php foreach ( $totals as $cpt => $count ) : ?>
		<tr><td><?php echo esc_html( $cpt ); ?></td><td><?php echo esc_html( (string) $count ); ?></td></tr>
	<?php endforeach; ?>
	</tbody></table>
	<?php
}
