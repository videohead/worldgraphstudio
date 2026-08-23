<?php
/**
 * Provider Connection Management UI.
 *
 * Admin page under World Graph Studio > Connections. Lists all provider connections
 * with status, environment, and quota configuration, and provides:
 *
 * - Connection health checks
 * - Workflow readiness and setup summaries
 * - Environment and quota management through the Connection editor
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

use WorldGraph\Utils\Capability_Sync;
use WorldGraph\Utils\Connection_Repository;
use WorldGraph\Utils\Connection_Tester;

/**
 * Connections admin panel.
 */
class Connections {

	/**
	 * Initialize the connections admin UI.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_post_worldgraph_test_connection', [ __CLASS__, 'handle_test_connection' ] );
		add_action( 'admin_post_worldgraph_sync_capabilities', [ __CLASS__, 'handle_sync_capabilities' ] );
		add_action( 'admin_post_worldgraph_set_active_connection', [ __CLASS__, 'handle_set_active_connection' ] );
		add_filter( 'redirect_post_location', [ __CLASS__, 'redirect_after_save' ], 10, 2 );
	}

	/**
	 * Send connection add/edit saves back to the Connections page instead of
	 * the native post list, so there is a single Connections view.
	 *
	 * @param string $location Default redirect location.
	 * @param int    $post_id  Saved post ID.
	 * @return string
	 */
	public static function redirect_after_save( string $location, int $post_id ): string {
		if ( Connection_Repository::CPT !== get_post_type( $post_id ) ) {
			return $location;
		}

		return admin_url( 'admin.php?page=worldgraph-connections&connection_id=' . $post_id . '&worldgraph_conns=saved' );
	}

	/**
	 * Add the Connections submenu page.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'worldgraph-generate',
			'Connections',
			'Connections',
			'manage_options',
			'worldgraph-connections',
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Handle the "Test Connection" admin post action.
	 */
	public static function handle_test_connection(): void {
		self::verify_action( 'worldgraph_test_connection' );

		$connection_id = isset( $_GET['connection_id'] ) ? absint( wp_unslash( $_GET['connection_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verify_action() validated this admin action.
		$result        = Connection_Tester::test( $connection_id );
		\WorldGraph\Utils\Generation_Log::add(
			$result['success'] ? 'info' : 'error',
			'connection_tester',
			(string) $result['message'],
			[],
			'',
			$connection_id
		);

		$redirect = add_query_arg(
			[
				'worldgraph_conns' => 'tested',
				'connection_id'       => $connection_id,
				'message'             => rawurlencode( $result['message'] ),
				'success'             => $result['success'] ? '1' : '0',
			],
			admin_url( 'admin.php?page=worldgraph-connections' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the "Sync Capabilities" admin post action.
	 */
	public static function handle_sync_capabilities(): void {
		self::verify_action( 'worldgraph_sync_capabilities' );

		$result = Capability_Sync::sync();

		$redirect = add_query_arg(
			[
				'worldgraph_conns' => 'synced',
				'message'             => rawurlencode( $result['message'] ),
				'success'             => $result['success'] ? '1' : '0',
			],
			admin_url( 'admin.php?page=worldgraph-connections' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the "Set Active" admin post action, marking a Connection the
	 * one Generate uses by default for its provider type and environment.
	 */
	public static function handle_set_active_connection(): void {
		self::verify_action( 'worldgraph_set_active_connection' );

		$connection_id = isset( $_GET['connection_id'] ) ? absint( wp_unslash( $_GET['connection_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verify_action() validated this admin action.
		$connection    = Connection_Repository::get( $connection_id );
		if ( $connection ) {
			\WorldGraph\Utils\worldgraph_update_field_value( $connection_id, 'is_default', 'yes' );
			\WorldGraph\CPT\Connection::after_scf_save( $connection_id );
		}

		$redirect = add_query_arg(
			[
				'worldgraph_conns' => 'activated',
				'connection_id'    => $connection_id,
				'success'          => $connection ? '1' : '0',
				'message'          => rawurlencode( $connection ? __( 'Connection set as active.', 'worldgraph' ) : __( 'Connection not found.', 'worldgraph' ) ),
			],
			admin_url( 'admin.php?page=worldgraph-connections' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Verify nonce and capability for an admin post action.
	 *
	 * @param string $action Action slug.
	 */
	private static function verify_action( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'worldgraph' ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'Invalid security nonce.', 'worldgraph' ) );
		}
	}

	/**
	 * Render the connections management page.
	 */
	public static function render_page(): void {
		$connections       = Connection_Repository::get_all();
		$template_counts   = self::template_counts_by_connection();
		$latest_activities = self::latest_activity_by_connection();

		$notice = '';
		$notice_type = 'success';
		if ( isset( $_GET['worldgraph_conns'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'saved' === $_GET['worldgraph_conns'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$notice = __( 'Connection saved.', 'worldgraph' );
			} else {
				$notice = isset( $_GET['message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['message'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only redirect notice is decoded, sanitized, and escaped on output.
				$notice_type = ( isset( $_GET['success'] ) && '1' === $_GET['success'] ) ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		?>
		<div class="wrap worldgraph-connections-wrap">
			<h1><?php esc_html_e( 'Connections', 'worldgraph' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Connections tell Studio where provider work runs and which reusable generation workflows are ready to use. Use environment-backed credential references for managed deployments.', 'worldgraph' ); ?>
			</p>

			<h2><?php esc_html_e( 'How provider setup works', 'worldgraph' ); ?></h2>
			<ol style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;max-width:1100px;margin:12px 0 18px;list-style-position:inside;">
				<li style="padding:12px;background:#fff;border:1px solid #dcdcde;"><strong><?php esc_html_e( 'Check the connection', 'worldgraph' ); ?></strong><br><span class="description"><?php esc_html_e( 'Confirm Studio can reach the provider with the saved endpoint and credentials.', 'worldgraph' ); ?></span></li>
				<li style="padding:12px;background:#fff;border:1px solid #dcdcde;"><strong><?php esc_html_e( 'Review available workflows', 'worldgraph' ); ?></strong><br><span class="description"><?php esc_html_e( 'See what the provider offers and which model files or custom nodes are still required.', 'worldgraph' ); ?></span></li>
				<li style="padding:12px;background:#fff;border:1px solid #dcdcde;"><strong><?php esc_html_e( 'Add ready workflows', 'worldgraph' ); ?></strong><br><span class="description"><?php esc_html_e( 'Create reusable Generation Templates without duplicating workflows already in Studio.', 'worldgraph' ); ?></span></li>
			</ol>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div class="worldgraph-connections-toolbar" style="margin:16px 0;">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=worldgraph_conn' ) ); ?>">
					<?php esc_html_e( 'Add Connection', 'worldgraph' ); ?>
				</a>
			</div>

			<table class="widefat striped" style="max-width:1200px;">
				<caption class="screen-reader-text"><?php esc_html_e( 'Connection health and workflow setup status', 'worldgraph' ); ?></caption>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Connection', 'worldgraph' ); ?></th>
						<th><?php esc_html_e( 'Connection health', 'worldgraph' ); ?></th>
						<th><?php esc_html_e( 'Workflow setup', 'worldgraph' ); ?></th>
						<th><?php esc_html_e( 'Default', 'worldgraph' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'worldgraph' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $connections ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No connections yet. Add one to connect Studio to a generation or integration provider.', 'worldgraph' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $connections as $connection ) : ?>
							<?php
							$connection_id = (int) $connection['id'];
							$health        = self::connection_health( $connection );
							$setup         = self::workflow_setup_status(
								$connection,
								(int) ( $template_counts[ $connection_id ] ?? 0 ),
								$latest_activities[ $connection_id ] ?? []
							);
							$test_url      = wp_nonce_url(
								add_query_arg(
									[
										'action'        => 'worldgraph_test_connection',
										'connection_id' => $connection_id,
									],
									admin_url( 'admin-post.php' )
								),
								'worldgraph_test_connection'
							);
							$is_default   = 'yes' === ( $connection['is_default'] ?? '' );
							$activate_url = wp_nonce_url(
								add_query_arg(
									[
										'action'        => 'worldgraph_set_active_connection',
										'connection_id' => $connection_id,
									],
									admin_url( 'admin-post.php' )
								),
								'worldgraph_set_active_connection'
							);
							$edit_url      = get_edit_post_link( $connection_id );
							$endpoint_host = $connection['endpoint_url'] ? (string) wp_parse_url( (string) $connection['endpoint_url'], PHP_URL_HOST ) : '';
							?>
							<tr>
								<td style="min-width:190px;">
									<a href="<?php echo esc_url( (string) $edit_url ); ?>"><strong><?php echo esc_html( $connection['connection_name'] ?: $connection['title'] ); ?></strong></a>
									<div class="description"><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $connection['provider_type'] ?: __( 'Provider not selected', 'worldgraph' ) ) ) ) ); ?> · <?php echo esc_html( (string) ( $connection['environment'] ?: __( 'Environment not set', 'worldgraph' ) ) ); ?></div>
									<?php if ( '' !== $endpoint_host ) : ?><div class="description"><?php echo esc_html( $endpoint_host ); ?></div><?php endif; ?>
								</td>
								<td style="min-width:150px;">
									<strong style="color:<?php echo esc_attr( self::tone_color( $health['tone'] ) ); ?>;"><?php echo esc_html( $health['label'] ); ?></strong>
									<div class="description"><?php echo esc_html( $health['detail'] ); ?></div>
								</td>
								<td style="min-width:280px;">
									<strong style="color:<?php echo esc_attr( self::tone_color( $setup['tone'] ) ); ?>;"><?php echo esc_html( $setup['label'] ); ?></strong>
									<div><?php echo esc_html( $setup['summary'] ); ?></div>
									<?php if ( '' !== $setup['refreshed'] ) : ?><div class="description"><?php echo esc_html( $setup['refreshed'] ); ?></div><?php endif; ?>
									<?php if ( '' !== $setup['activity'] ) : ?><div class="description" style="margin-top:4px;"><?php echo esc_html( $setup['activity'] ); ?></div><?php endif; ?>
								</td>
								<td>
									<?php if ( $is_default ) : ?>
										<span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Used by default', 'worldgraph' ); ?></span>
									<?php else : ?>
										<a href="<?php echo esc_url( $activate_url ); ?>"><?php esc_html_e( 'Use by default', 'worldgraph' ); ?></a>
									<?php endif; ?>
								</td>
								<td style="white-space:nowrap;">
									<a class="button button-small" href="<?php echo esc_url( $test_url ); ?>"><?php esc_html_e( 'Check connection', 'worldgraph' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( (string) $edit_url ); ?>"><?php esc_html_e( 'Manage setup', 'worldgraph' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Summarize whether Studio can currently use a Connection.
	 *
	 * @param array<string, mixed> $connection Connection repository record.
	 * @return array{label:string,tone:string,detail:string}
	 */
	private static function connection_health( array $connection ): array {
		$status  = (string) ( $connection['status'] ?: 'unverified' );
		$checked = self::display_timestamp( (string) ( $connection['last_validated_at'] ?? '' ) );

		if ( 'disabled' === $status ) {
			return [
				'label'  => __( 'Disabled', 'worldgraph' ),
				'tone'   => 'neutral',
				'detail' => __( 'Not used for new generation jobs.', 'worldgraph' ),
			];
		}
		if ( empty( $connection['provider_type'] ) || empty( $connection['endpoint_url'] ) ) {
			return [
				'label'  => __( 'Needs setup', 'worldgraph' ),
				'tone'   => 'warning',
				'detail' => __( 'Choose a provider and endpoint.', 'worldgraph' ),
			];
		}
		if ( 'verified' === $status ) {
			return [
				'label'  => __( 'Connected', 'worldgraph' ),
				'tone'   => 'success',
				'detail' => $checked ? sprintf(
					/* translators: %s: formatted date and time of the connection check. */
					__( 'Checked %s', 'worldgraph' ),
					$checked
				) : __( 'Provider responded successfully.', 'worldgraph' ),
			];
		}
		if ( 'error' === $status ) {
			return [
				'label'  => __( 'Needs attention', 'worldgraph' ),
				'tone'   => 'error',
				'detail' => $checked ? sprintf(
					/* translators: %s: formatted date and time of the connection check. */
					__( 'Last checked %s', 'worldgraph' ),
					$checked
				) : __( 'The last connection check failed.', 'worldgraph' ),
			];
		}

		return [
			'label'  => __( 'Not checked', 'worldgraph' ),
			'tone'   => 'warning',
			'detail' => __( 'Run a connection check before relying on it.', 'worldgraph' ),
		];
	}

	/**
	 * Summarize provider workflow discovery and reusable Template readiness.
	 *
	 * @param array<string, mixed> $connection      Connection repository record.
	 * @param int                  $template_count  Templates assigned to this Connection.
	 * @param array<string, mixed> $latest_activity Latest non-Job activity entry.
	 * @return array{label:string,tone:string,summary:string,refreshed:string,activity:string}
	 */
	private static function workflow_setup_status( array $connection, int $template_count, array $latest_activity ): array {
		$connection_id = (int) $connection['id'];
		$provider       = sanitize_key( (string) ( $connection['provider_type'] ?? '' ) );
		$activity       = '';
		if ( ! empty( $latest_activity['message'] ) ) {
			$activity_time = self::display_timestamp( (string) ( $latest_activity['time'] ?? '' ), false );
			$activity      = sprintf(
				/* translators: 1: latest setup message, 2: activity timestamp. */
				__( 'Latest activity: %1$s%2$s', 'worldgraph' ),
				(string) $latest_activity['message'],
				$activity_time ? ' · ' . $activity_time : ''
			);
		}

		if ( 'disabled' === (string) ( $connection['status'] ?? '' ) ) {
			return [
				'label'     => __( 'Paused', 'worldgraph' ),
				'tone'      => 'neutral',
				'summary'   => sprintf(
					/* translators: %d: number of retained Generation Templates. */
					_n( '%d existing Generation Template is retained.', '%d existing Generation Templates are retained.', $template_count, 'worldgraph' ),
					$template_count
				),
				'refreshed' => '',
				'activity'  => $activity,
			];
		}

		if ( 'comfyui' === $provider ) {
			$snapshot = json_decode( (string) get_post_meta( $connection_id, 'comfy_template_catalog', true ), true );
			$snapshot = is_array( $snapshot ) ? $snapshot : [];
			$entries  = array_values( array_filter( (array) ( $snapshot['entries'] ?? [] ), 'is_array' ) );
			$ready    = count( array_filter( $entries, [ __CLASS__, 'stored_workflow_is_ready' ] ) );
			$total    = count( $entries );
			$attention = max( 0, $total - $ready );
			$synced_at = self::display_timestamp( (string) ( $snapshot['synced_at'] ?? '' ) );

			if ( '' === $synced_at ) {
				$label = __( 'Not checked', 'worldgraph' );
				$tone  = 'warning';
			} elseif ( 0 === $total ) {
				$label = __( 'No workflows found', 'worldgraph' );
				$tone  = 'warning';
			} elseif ( $attention > 0 ) {
				$label = __( 'Review needed', 'worldgraph' );
				$tone  = 'warning';
			} else {
				$label = __( 'Ready', 'worldgraph' );
				$tone  = 'success';
			}

			return [
				'label'     => $label,
				'tone'      => $tone,
				'summary'   => sprintf(
					/* translators: 1: available workflows, 2: ready workflows, 3: workflows added to Studio, 4: workflows needing attention. */
					__( '%1$d available · %2$d ready now · %3$d added to Studio · %4$d need attention', 'worldgraph' ),
					$total,
					$ready,
					$template_count,
					$attention
				),
				'refreshed' => $synced_at ? sprintf(
					/* translators: %s: formatted date and time when workflows were refreshed. */
					__( 'Workflows refreshed %s', 'worldgraph' ),
					$synced_at
				) : __( 'Open setup to refresh available workflows.', 'worldgraph' ),
				'activity'  => $activity,
			];
		}

		$catalog_prefixes = [
			'fal'         => 'fal_catalog',
			'elevenlabs'  => 'elevenlabs_catalog',
			'suno'        => 'suno_catalog',
			'videodraft'  => 'videodraft_catalog',
		];
		if ( isset( $catalog_prefixes[ $provider ] ) ) {
			$prefix     = $catalog_prefixes[ $provider ];
			$synced_at  = self::display_timestamp( (string) get_post_meta( $connection_id, $prefix . '_synced_at', true ) );
			$error      = (string) get_post_meta( $connection_id, $prefix . '_error', true );
			$has_error  = '' !== trim( $error );

			return [
				'label'     => $has_error ? __( 'Needs attention', 'worldgraph' ) : ( $synced_at ? __( 'Ready', 'worldgraph' ) : __( 'Setup pending', 'worldgraph' ) ),
				'tone'      => $has_error ? 'error' : ( $synced_at ? 'success' : 'warning' ),
				'summary'   => $has_error ? $error : sprintf(
					/* translators: %d: number of available Generation Templates. */
					_n( '%d Generation Template is available.', '%d Generation Templates are available.', $template_count, 'worldgraph' ),
					$template_count
				),
				'refreshed' => $synced_at ? sprintf(
					/* translators: %s: formatted date and time when workflows were refreshed. */
					__( 'Workflows refreshed %s', 'worldgraph' ),
					$synced_at
				) : __( 'Save or check this Connection to discover workflows.', 'worldgraph' ),
				'activity'  => $activity,
			];
		}

		return [
			'label'     => $template_count ? __( 'Ready', 'worldgraph' ) : __( 'No workflow setup', 'worldgraph' ),
			'tone'      => $template_count ? 'success' : 'neutral',
			'summary'   => $template_count
				? sprintf(
					/* translators: %d: number of available Generation Templates. */
					_n( '%d Generation Template is available.', '%d Generation Templates are available.', $template_count, 'worldgraph' ),
					$template_count
				)
				: __( 'This provider does not publish reusable workflows through the Connection screen.', 'worldgraph' ),
			'refreshed' => '',
			'activity'  => $activity,
		];
	}

	/**
	 * Estimate readiness from the last stored discovery result without making a
	 * provider network request while rendering the Connections list.
	 *
	 * @param array<string, mixed> $entry Stored provider workflow.
	 */
	private static function stored_workflow_is_ready( array $entry ): bool {
		if ( empty( $entry['modality'] ) || ! empty( $entry['missing_nodes'] ) || ! empty( $entry['missing_models'] ) ) {
			return false;
		}
		if ( isset( $entry['installable'] ) && false === $entry['installable'] ) {
			return false;
		}
		if ( ! empty( $entry['models'] ) && empty( $entry['model_urls'] ) && ( ! isset( $entry['installable'] ) || null === $entry['installable'] ) ) {
			return false;
		}
		if ( 'registry' === (string) ( $entry['source'] ?? '' ) ) {
			return isset( $entry['installable'] ) && true === $entry['installable'];
		}

		return true;
	}

	/**
	 * Count reusable Templates assigned to every Connection in one query.
	 *
	 * @return array<int, int>
	 */
	private static function template_counts_by_connection(): array {
		$template_ids = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );
		$counts = [];
		foreach ( $template_ids as $template_id ) {
			$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( (int) $template_id, 'connection_id' ) );
			if ( $connection_id ) {
				$counts[ $connection_id ] = ( $counts[ $connection_id ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * Index the latest persistent, non-Job activity for each Connection.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function latest_activity_by_connection(): array {
		$latest = [];
		foreach ( array_reverse( \WorldGraph\Utils\Generation_Log::all() ) as $entry ) {
			$connection_id = (int) ( $entry['connection_id'] ?? 0 );
			if ( $connection_id > 0 && 0 === (int) ( $entry['generation_id'] ?? 0 ) && ! isset( $latest[ $connection_id ] ) ) {
				$latest[ $connection_id ] = is_array( $entry ) ? $entry : [];
			}
		}

		return $latest;
	}

	/** Convert a stored timestamp into the site's date and time format. */
	private static function display_timestamp( string $value, bool $gmt = true ): string {
		if ( '' === trim( $value ) ) {
			return '';
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return $gmt ? get_date_from_gmt( $value, $format ) : mysql2date( $format, $value );
	}

	/** Return an accessible status accent color for a semantic tone. */
	private static function tone_color( string $tone ): string {
		$colors = [
			'success' => '#008a20',
			'error'   => '#d63638',
			'warning' => '#996800',
			'neutral' => '#50575e',
		];

		return $colors[ $tone ] ?? $colors['neutral'];
	}
}
