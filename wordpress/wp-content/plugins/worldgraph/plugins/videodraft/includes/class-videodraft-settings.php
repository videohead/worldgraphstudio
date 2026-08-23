<?php
/**
 * VideoDraft Sync settings and manual operations page.
 *
 * @package WorldGraphVideoDraft
 */

namespace WorldGraphVideoDraft;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** VideoDraft Sync settings. */
class Settings {

	const OPTION_NAME = 'worldgraph_videodraft_sync_settings';
	const ENABLED_OPTION_NAME = 'worldgraph_videodraft_enabled';

	/** @var Settings|null */
	private static $instance = null;

	/** Register the admin page once. */
	public static function init(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Install admin hooks. */
	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_settings_menu' ] );
	}

	/** Add the sync page under the World Graph Studio plugin manager. */
	public function add_settings_menu(): void {
		add_submenu_page(
			'worldgraph-plugins',
			'VideoDraft Sync',
			'VideoDraft Sync',
			'manage_options',
			'worldgraph-videodraft',
			[ $this, 'render_settings_page' ]
		);
	}

	/** Whether sync routes and actions are enabled. */
	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION_NAME, false );
	}

	/** Enable sync. */
	public static function enable(): void {
		update_option( self::ENABLED_OPTION_NAME, true );
	}

	/** Disable sync. */
	public static function disable(): void {
		update_option( self::ENABLED_OPTION_NAME, false );
	}

	/** Whether at least one credentialed VideoDraft Connection exists. */
	public static function is_configured(): bool {
		foreach ( \WorldGraph\Utils\Connection_Repository::get_all( [ 'provider_type' => 'videodraft' ] ) as $connection ) {
			if ( 'disabled' !== ( $connection['status'] ?? '' ) && '' !== trim( (string) ( $connection['credential_reference'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Selected VideoDraft Connection ID. */
	public static function connection_id(): int {
		$settings = get_option( self::OPTION_NAME, [] );
		return is_array( $settings ) ? absint( $settings['connection_id'] ?? 0 ) : 0;
	}

	/** Save non-secret plugin settings. */
	private static function save( int $connection_id, bool $enabled ): void {
		update_option( self::OPTION_NAME, [ 'connection_id' => $connection_id ], false );
		$states = get_option( 'worldgraph_plugin_states', [] );
		$states = is_array( $states ) ? $states : [];
		$states['videodraft'] = $enabled;
		update_option( 'worldgraph_plugin_states', $states );
		$enabled ? self::enable() : self::disable();
	}

	/** Render settings, connection health, and manual sync controls. */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage VideoDraft Sync.', 'worldgraph' ) );
		}

		$connections = \WorldGraph\Utils\Connection_Repository::get_all( [ 'provider_type' => 'videodraft' ] );
		$projects = get_posts( [ 'post_type' => 'worldgraph_project', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$connection_id = self::connection_id();
		$project_id = absint( $_POST['project_id'] ?? 0 );
		$remote_project_id = sanitize_text_field( wp_unslash( $_POST['remote_project_id'] ?? '' ) );
		$message = '';
		$message_type = 'success';
		$result = null;
		$remote_projects = [];
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' === $request_method && isset( $_POST['worldgraph_videodraft_nonce'] ) ) {
			check_admin_referer( 'worldgraph_videodraft_settings', 'worldgraph_videodraft_nonce' );
			$action = sanitize_key( wp_unslash( $_POST['videodraft_action'] ?? 'save' ) );
			$connection_id = absint( $_POST['connection_id'] ?? 0 );
			$enabled = self::is_enabled();
			if ( 'save' === $action ) {
				$enabled = isset( $_POST['enabled'] );
				self::save( $connection_id, $enabled );
			}
			\WorldGraph\Utils\Connection_Adapters::load( 'videodraft' );
			if ( ! $enabled && in_array( $action, [ 'push', 'pull', 'unsync' ], true ) ) {
				$message = __( 'Enable VideoDraft Sync before changing local or remote project data.', 'worldgraph' );
				$message_type = 'error';
				$action = 'blocked';
			}

			switch ( $action ) {
				case 'blocked':
					break;
				case 'test':
					$result = \WorldGraph\Utils\Connection_Tester::test( $connection_id );
					$message = (string) ( $result['message'] ?? '' );
					$message_type = ! empty( $result['success'] ) ? 'success' : 'error';
					break;
				case 'list':
					$result = Sync::list_projects( $connection_id );
					if ( is_wp_error( $result ) ) {
						$message = $result->get_error_message();
						$message_type = 'error';
					} else {
						$remote_projects = $result;
						$message = sprintf(
							/* translators: %d: number of VideoDraft projects found. */
							_n( '%d VideoDraft project found.', '%d VideoDraft projects found.', count( $result ), 'worldgraph' ),
							count( $result )
						);
					}
					break;
				case 'push':
					$result = Sync::push( $project_id, $connection_id, $remote_project_id, isset( $_POST['force'] ) );
					$message = is_wp_error( $result ) ? $result->get_error_message() : sprintf(
						/* translators: %s: VideoDraft project ID. */
						__( 'Exported to VideoDraft project %s.', 'worldgraph' ),
						$result['remote_project_id']
					);
					$message_type = is_wp_error( $result ) ? 'error' : 'success';
					if ( ! is_wp_error( $result ) ) {
						$remote_project_id = (string) $result['remote_project_id'];
					}
					break;
				case 'preview_pull':
				case 'pull':
					$result = Sync::pull( $remote_project_id, $connection_id, isset( $_POST['force'] ), 'preview_pull' === $action );
					$message = is_wp_error( $result ) ? $result->get_error_message() : ( 'preview_pull' === $action ? __( 'Import preview validated without writing local data.', 'worldgraph' ) : __( 'VideoDraft project imported.', 'worldgraph' ) );
					$message_type = is_wp_error( $result ) ? 'error' : 'success';
					if ( ! is_wp_error( $result ) && ! empty( $result['project_id'] ) ) {
						$project_id = (int) $result['project_id'];
					}
					break;
				case 'unsync':
					$removed = Sync::unsync( $project_id, $connection_id );
					$message = $removed ? __( 'Local VideoDraft mapping removed. Neither project was deleted.', 'worldgraph' ) : __( 'No VideoDraft mapping was removed.', 'worldgraph' );
					$message_type = $removed ? 'success' : 'error';
					break;
				default:
					$message = __( 'VideoDraft Sync settings saved.', 'worldgraph' );
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VideoDraft Sync', 'worldgraph' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
			<?php if ( empty( $connections ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo wp_kses_post( sprintf(
					/* translators: %s: URL to the Connections admin screen. */
					__( 'Create a <strong>VideoDraft</strong> Connection before enabling sync. <a href="%s">Open Connections</a>.', 'worldgraph' ),
					esc_url( admin_url( 'edit.php?post_type=worldgraph_conn' ) )
				) ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'worldgraph_videodraft_settings', 'worldgraph_videodraft_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="videodraft-connection"><?php esc_html_e( 'Connection', 'worldgraph' ); ?></label></th>
						<td><select id="videodraft-connection" name="connection_id">
							<option value="0"><?php esc_html_e( 'Select a VideoDraft Connection', 'worldgraph' ); ?></option>
							<?php foreach ( $connections as $connection ) : ?>
								<option value="<?php echo esc_attr( $connection['id'] ); ?>" <?php selected( $connection_id, $connection['id'] ); ?>><?php echo esc_html( sprintf( '%s (%s)', $connection['connection_name'] ?: $connection['title'], $connection['status'] ) ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled', 'worldgraph' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( self::is_enabled() ); ?> /> <?php esc_html_e( 'Enable import/export sync and REST routes', 'worldgraph' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="videodraft-project"><?php esc_html_e( 'Local project', 'worldgraph' ); ?></label></th>
						<td><select id="videodraft-project" name="project_id">
							<option value="0"><?php esc_html_e( 'Select a project', 'worldgraph' ); ?></option>
							<?php foreach ( $projects as $project ) : ?>
								<option value="<?php echo esc_attr( $project->ID ); ?>" <?php selected( $project_id, $project->ID ); ?>><?php echo esc_html( $project->post_title ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="videodraft-remote-project"><?php esc_html_e( 'VideoDraft project ID', 'worldgraph' ); ?></label></th>
						<td><input id="videodraft-remote-project" name="remote_project_id" class="regular-text" value="<?php echo esc_attr( $remote_project_id ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Conflict override', 'worldgraph' ); ?></th>
						<td><label><input type="checkbox" name="force" value="1" /> <?php esc_html_e( 'Force after reviewing both versions', 'worldgraph' ); ?></label></td>
					</tr>
				</table>
				<p class="submit">
					<button class="button button-primary" name="videodraft_action" value="save"><?php esc_html_e( 'Save Settings', 'worldgraph' ); ?></button>
					<button class="button" name="videodraft_action" value="test"><?php esc_html_e( 'Test Connection', 'worldgraph' ); ?></button>
					<button class="button" name="videodraft_action" value="list"><?php esc_html_e( 'List Remote Projects', 'worldgraph' ); ?></button>
					<button class="button" name="videodraft_action" value="push"><?php esc_html_e( 'Push Project', 'worldgraph' ); ?></button>
					<button class="button" name="videodraft_action" value="preview_pull"><?php esc_html_e( 'Preview Pull', 'worldgraph' ); ?></button>
					<button class="button" name="videodraft_action" value="pull"><?php esc_html_e( 'Pull Project', 'worldgraph' ); ?></button>
					<button class="button" name="videodraft_action" value="unsync"><?php esc_html_e( 'Remove Mapping', 'worldgraph' ); ?></button>
				</p>
			</form>

			<?php if ( ! empty( $remote_projects ) ) : ?>
				<h2><?php esc_html_e( 'Remote Projects', 'worldgraph' ); ?></h2>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'ID', 'worldgraph' ); ?></th><th><?php esc_html_e( 'Title', 'worldgraph' ); ?></th><th><?php esc_html_e( 'Status', 'worldgraph' ); ?></th><th><?php esc_html_e( 'Modified', 'worldgraph' ); ?></th></tr></thead><tbody>
				<?php foreach ( $remote_projects as $remote ) : if ( ! is_array( $remote ) ) { continue; } ?>
					<tr><td><code><?php echo esc_html( (string) ( $remote['id'] ?? $remote['project_id'] ?? '' ) ); ?></code></td><td><?php echo esc_html( (string) ( $remote['title'] ?? $remote['name'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $remote['status'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $remote['lastModified'] ?? $remote['updated_at'] ?? '' ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>

			<?php if ( is_array( $result ) && isset( $result['counts'] ) ) : ?>
				<h2><?php esc_html_e( 'Last Operation', 'worldgraph' ); ?></h2>
				<pre><?php echo esc_html( (string) wp_json_encode( [ 'counts' => $result['counts'], 'report' => $result['report'] ?? null ], JSON_PRETTY_PRINT ) ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}
}
