<?php
/**
 * Plugin Name: World Graph Studio - Final Draft FDX Import
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Import Final Draft FDX screenplay files into the World Graph Studio Story Graph.
 * Version: 1.0.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package WorldGraphFDX
 */

namespace WorldGraphFDX;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WORLDGRAPH_FDX_VERSION', '1.0.0' );
define( 'WORLDGRAPH_FDX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize the FDX import extension.
 */
function init(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\add_admin_page' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
	add_action( 'admin_post_worldgraph_import_fdx', __NAMESPACE__ . '\handle_import' );
}

if ( did_action( 'plugins_loaded' ) ) {
	init();
} else {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\init' );
}

/**
 * Add the FDX import page.
 */
function add_admin_page(): void {
	add_submenu_page(
		'worldgraph-plugins',
		'Import Final Draft FDX',
		'Import Final Draft FDX',
		'manage_worldgraph',
		'worldgraph-fdx',
		__NAMESPACE__ . '\render_admin_page'
	);
}

/**
 * Enqueue the browser-side FDX parser.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function enqueue_assets( string $hook_suffix ): void {
	if ( ! isset( $_GET['page'] ) || 'worldgraph-fdx' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing.
		return;
	}

	wp_enqueue_script(
		'worldgraph-fdx-import',
		WORLDGRAPH_FDX_PLUGIN_URL . 'js/fdx-import.js',
		[],
		WORLDGRAPH_FDX_VERSION,
		true
	);
}

/**
 * Handle the parsed JSON document submitted by the browser parser.
 */
function handle_import(): void {
	if ( ! current_user_can( 'manage_worldgraph' ) ) {
		wp_die( 'You do not have permission to import Final Draft files.' );
	}

	check_admin_referer( 'worldgraph_fdx_import' );
	if ( ! class_exists( '\\WorldGraph\\Importer\\WorldGraph_Importer' ) ) {
		redirect_with_error( 'Enable the Story Import & Export plugin before importing Final Draft files.' );
	}
	$json      = isset( $_POST['worldgraph_fdx_json'] ) ? wp_unslash( $_POST['worldgraph_fdx_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON must remain intact and is schema-validated by the importer.
	$overwrite = ! empty( $_POST['worldgraph_fdx_overwrite'] );

	if ( '' === trim( (string) $json ) ) {
		redirect_with_error( 'Choose a valid FDX file and wait for it to be parsed before importing.' );
	}

	$importer = new \WorldGraph\Importer\WorldGraph_Importer();
	$result   = $importer->import( (string) $json, [ 'overwrite' => $overwrite ] );

	if ( is_wp_error( $result ) ) {
		redirect_with_error( $result->get_error_message() );
	}

	set_transient( 'worldgraph_fdx_import_report', $result, MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-fdx', 'imported' => '1' ], admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Redirect back to the FDX page with an error.
 *
 * @param string $message Error message.
 */
function redirect_with_error( string $message ): void {
	wp_safe_redirect( add_query_arg( [ 'page' => 'worldgraph-fdx', 'error' => rawurlencode( $message ) ], admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Render the FDX import page.
 */
function render_admin_page(): void {
	if ( ! current_user_can( 'manage_worldgraph' ) ) {
		wp_die( 'You do not have permission to import Final Draft files.' );
	}
	if ( ! class_exists( '\\WorldGraph\\Importer\\WorldGraph_Importer' ) ) {
		wp_die( esc_html__( 'Enable the Story Import & Export plugin before importing Final Draft files.', 'worldgraph' ) );
	}

	$report   = get_transient( 'worldgraph_fdx_import_report' );
	$error    = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
	$imported = isset( $_GET['imported'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['imported'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
	delete_transient( 'worldgraph_fdx_import_report' );
	?>
	<div class="wrap worldgraph-fdx-wrap">
		<h1><?php esc_html_e( 'Import Final Draft FDX', 'worldgraph' ); ?></h1>
		<?php if ( $error ) : ?>
			<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Import failed:', 'worldgraph' ); ?></strong> <?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
		<?php if ( $imported && is_array( $report ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Final Draft import completed.', 'worldgraph' ); ?></p></div>
			<?php render_report( $report ); ?>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Upload an .fdx screenplay. Scene headings become scenes and locations; action becomes scene summaries; character, dialogue, and parenthetical paragraphs become structured scene dialogue.', 'worldgraph' ); ?></p>
		<form method="post" id="worldgraph-fdx-import-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="worldgraph_import_fdx" />
			<?php wp_nonce_field( 'worldgraph_fdx_import' ); ?>
			<input type="hidden" name="worldgraph_fdx_json" id="worldgraph_fdx_json" value="" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="worldgraph_fdx_file"><?php esc_html_e( 'Final Draft file', 'worldgraph' ); ?></label></th>
					<td>
						<input type="file" id="worldgraph_fdx_file" accept=".fdx,application/xml,text/xml" />
						<p class="description"><?php esc_html_e( 'The file is parsed locally in your browser. Only the generated World Graph JSON is submitted to WordPress.', 'worldgraph' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'worldgraph' ); ?></th>
					<td><label><input type="checkbox" name="worldgraph_fdx_overwrite" value="1" /> <?php esc_html_e( 'Overwrite existing entities with the same generated external ID', 'worldgraph' ); ?></label></td>
				</tr>
			</table>
			<p id="worldgraph-fdx-status" class="description" role="status"></p>
			<?php submit_button( __( 'Import FDX screenplay', 'worldgraph' ) ); ?>
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
