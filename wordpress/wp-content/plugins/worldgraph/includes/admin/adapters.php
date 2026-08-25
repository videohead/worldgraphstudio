<?php
/**
 * Provider adapter reference.
 *
 * Lists the Connection adapters this installation can run, whether each one is
 * loaded, and how to reach its Connections. Adapters are code, not content, so
 * this screen is read-only.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

use WorldGraph\Utils\Connection_Adapters;
use WorldGraph\Utils\Connection_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapters admin page.
 */
class Adapters {

	/**
	 * Register the Adapters submenu page.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
	}

	/**
	 * Add the Adapters page under Generate.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'worldgraph-generate',
			__( 'Adapters', 'worldgraph' ),
			__( 'Adapters', 'worldgraph' ),
			'manage_options',
			'worldgraph-adapters',
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Render the Adapters page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view provider adapters.', 'worldgraph' ) );
		}

		$connections_url = admin_url( 'admin.php?page=worldgraph-connections' );
		?>
		<div class="wrap worldgraph-adapters">
			<h1><?php esc_html_e( 'Connection Adapters', 'worldgraph' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: Connections page URL. */
					wp_kses( __( 'Adapters translate a Connection into REST or MCP calls, and load on demand when an enabled Connection uses them. Manage activation and credentials from <a href="%s">Connections</a>; there is no separate adapter toggle.', 'worldgraph' ), [ 'a' => [ 'href' => [] ] ] ),
					esc_url( $connections_url )
				);
				?>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Adapter', 'worldgraph' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'worldgraph' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Endpoints', 'worldgraph' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Connections', 'worldgraph' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'worldgraph' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Connection_Adapters::provider_types() as $provider_type ) : ?>
						<?php
						$adapter = Connection_Adapters::get( (string) $provider_type );
						if ( ! is_array( $adapter ) ) {
							continue;
						}
						if ( false === ( $adapter['show_in_plugins'] ?? true ) ) {
							continue;
						}
						$generation  = is_array( $adapter['generation'] ?? null ) ? $adapter['generation'] : [];
						$templates   = is_array( $adapter['templates'] ?? null ) ? $adapter['templates'] : [];
						$implemented = ! empty( $adapter['files'] )
							|| ! empty( $adapter['loader'] )
							|| ! empty( $adapter['init'] )
							|| ! empty( $adapter['callbacks'] )
							|| ! empty( $templates['provision'] )
							|| ! empty( $generation['client'] )
							|| ! empty( $generation['client_resolver'] );
						$connections = Connection_Repository::get_all( [ 'provider_type' => $provider_type ] );
						$enabled     = array_filter(
							$connections,
							static function ( array $connection ): bool {
								return 'disabled' !== ( $connection['status'] ?? '' );
							}
						);
						$verified    = array_filter(
							$connections,
							static function ( array $connection ): bool {
								return 'verified' === ( $connection['status'] ?? '' );
							}
						);
						?>
						<tr data-connection-adapter="<?php echo esc_attr( (string) $provider_type ); ?>">
							<td>
								<strong>
									<span class="dashicons <?php echo esc_attr( (string) ( $adapter['icon'] ?? 'dashicons-admin-plugins' ) ); ?>" aria-hidden="true"></span>
									<?php echo esc_html( (string) ( $adapter['label'] ?? $provider_type ) ); ?>
								</strong>
								<br /><code><?php echo esc_html( (string) $provider_type ); ?></code>
								<br /><small><?php echo esc_html( (string) ( $adapter['description'] ?? '' ) ); ?></small>
							</td>
							<td>
								<?php if ( ! $implemented ) : ?>
									<?php esc_html_e( 'Not implemented yet', 'worldgraph' ); ?>
								<?php elseif ( ! empty( $enabled ) ) : ?>
									<?php esc_html_e( 'Active on demand', 'worldgraph' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Available', 'worldgraph' ); ?>
								<?php endif; ?>
								<?php if ( $implemented ) : ?>
									<br /><small>
										<?php
										echo Connection_Adapters::is_loaded( (string) $provider_type )
											? esc_html__( 'Loaded for this request', 'worldgraph' )
											: esc_html__( 'Not loaded for this request', 'worldgraph' );
										?>
									</small>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $adapter['endpoint'] ) ) : ?>
									<code><?php echo esc_html( (string) $adapter['endpoint'] ); ?></code>
								<?php endif; ?>
								<?php if ( ! empty( $adapter['mcp_endpoint'] ) ) : ?>
									<br /><code><?php echo esc_html( (string) $adapter['mcp_endpoint'] ); ?></code>
								<?php endif; ?>
							</td>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: Connection count, 2: verified Connection count. */
										__( '%1$d configured; %2$d verified', 'worldgraph' ),
										count( $connections ),
										count( $verified )
									)
								);
								?>
							</td>
							<td>
								<?php if ( $implemented ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $connections_url ); ?>">
										<?php echo esc_html( empty( $connections ) ? __( 'Add Connection', 'worldgraph' ) : __( 'Manage Connections', 'worldgraph' ) ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
