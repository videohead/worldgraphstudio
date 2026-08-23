<?php
/**
 * Descript Sync settings and manual operations page.
 *
 * @package WorldGraphDescript
 */

namespace WorldGraphDescript;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Descript Sync settings. */
class Settings {

	const OPTION_NAME = 'worldgraph_descript_sync_settings';
	const ENABLED_OPTION_NAME = 'worldgraph_descript_enabled';

	/** @var Settings|null */
	private static $instance = null;

	/** @var string Descript settings page hook suffix. */
	private $settings_page_hook = '';

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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/** Add the sync page under the World Graph Studio plugin manager. */
	public function add_settings_menu(): void {
		$this->settings_page_hook = (string) add_submenu_page(
			'worldgraph-plugins',
			'Descript Sync',
			'Descript Sync',
			'manage_options',
			'worldgraph-descript',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Enqueue settings-page behavior only on the Descript admin screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->settings_page_hook || $this->settings_page_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'worldgraph-descript-settings',
			WORLDGRAPH_DESCRIPT_PLUGIN_URL . 'js/descript-settings.js',
			[],
			WORLDGRAPH_DESCRIPT_VERSION,
			true
		);

		wp_localize_script(
			'worldgraph-descript-settings',
			'worldgraphDescriptSettings',
			[
				'i18n' => [
					'confirmUnsync' => __( 'Remove the local Descript mapping? Neither project will be deleted.', 'worldgraph' ),
				],
			]
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

	/** Whether at least one credentialed Descript Connection exists. */
	public static function is_configured(): bool {
		foreach ( \WorldGraph\Utils\Connection_Repository::get_all( [ 'provider_type' => 'descript' ] ) as $connection ) {
			if ( 'disabled' !== ( $connection['status'] ?? '' ) && '' !== trim( (string) ( $connection['credential_reference'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Selected Descript Connection ID. */
	public static function connection_id(): int {
		$settings = get_option( self::OPTION_NAME, [] );
		return is_array( $settings ) ? absint( $settings['connection_id'] ?? 0 ) : 0;
	}

	/** Save non-secret plugin settings. */
	private static function save( int $connection_id, bool $enabled ): void {
		update_option( self::OPTION_NAME, [ 'connection_id' => $connection_id ], false );
		$states = get_option( 'worldgraph_plugin_states', [] );
		$states = is_array( $states ) ? $states : [];
		$states['descript'] = $enabled;
		update_option( 'worldgraph_plugin_states', $states );
		$enabled ? self::enable() : self::disable();
	}

	/** Render settings, connection health, and manual sync controls. */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Descript Sync.', 'worldgraph' ) );
		}

		$connections = \WorldGraph\Utils\Connection_Repository::get_all( [ 'provider_type' => 'descript' ] );
		$projects = get_posts( [ 'post_type' => 'worldgraph_project', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$connection_id = self::connection_id();
		$project_id = absint( $_POST['project_id'] ?? 0 );
		$remote_project_id = sanitize_text_field( wp_unslash( $_POST['remote_project_id'] ?? '' ) );
		$composition_id = sanitize_text_field( wp_unslash( $_POST['composition_id'] ?? '' ) );
		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
		$message = '';
		$message_type = 'success';
		$result = null;
		$remote_projects = [];
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' === $request_method && isset( $_POST['worldgraph_descript_nonce'] ) ) {
			check_admin_referer( 'worldgraph_descript_settings', 'worldgraph_descript_nonce' );
			$action = sanitize_key( wp_unslash( $_POST['descript_action'] ?? 'save' ) );
			$connection_id = absint( $_POST['connection_id'] ?? 0 );
			$enabled = self::is_enabled();
			if ( 'save' === $action ) {
				$enabled = isset( $_POST['enabled'] );
				self::save( $connection_id, $enabled );
			}
			\WorldGraph\Utils\Connection_Adapters::load( 'descript' );
			if ( ! $enabled && in_array( $action, [ 'pull', 'push', 'poll', 'unsync' ], true ) ) {
				$message = __( 'Enable Descript Sync before importing, exporting, or polling jobs.', 'worldgraph' );
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
						$remote_projects = (array) ( $result['projects'] ?? [] );
						$message = sprintf(
							/* translators: %d: number of Descript projects found. */
							_n( '%d Descript project found.', '%d Descript projects found.', count( $remote_projects ), 'worldgraph' ),
							count( $remote_projects )
						);
					}
					break;
				case 'pull':
					$result = Sync::pull_transcript( $remote_project_id, $composition_id, $connection_id, sanitize_key( wp_unslash( $_POST['format'] ?? 'markdown' ) ), isset( $_POST['force'] ) );
					$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Descript transcript imported.', 'worldgraph' );
					$message_type = is_wp_error( $result ) ? 'error' : 'success';
					if ( ! is_wp_error( $result ) && ! empty( $result['project_id'] ) ) {
						$project_id = (int) $result['project_id'];
					}
					break;
				case 'push':
					$result = Sync::push_media( $project_id, $connection_id, $remote_project_id, sanitize_text_field( wp_unslash( $_POST['folder_name'] ?? '' ) ) );
					$message = is_wp_error( $result ) ? $result->get_error_message() : sprintf(
						/* translators: %s: Descript import job ID. */
						__( 'Submitted a Descript import job (%s).', 'worldgraph' ),
						$result['job_id']
					);
					$message_type = is_wp_error( $result ) ? 'error' : 'success';
					if ( ! is_wp_error( $result ) ) {
						$job_id = (string) $result['job_id'];
					}
					break;
				case 'poll':
					$result = Sync::poll_job( $job_id, $connection_id, $project_id );
					$message = is_wp_error( $result ) ? $result->get_error_message() : sprintf(
						/* translators: %s: Descript import job status. */
						__( 'Job status: %s.', 'worldgraph' ),
						(string) ( $result['status'] ?? 'unknown' )
					);
					$message_type = is_wp_error( $result ) ? 'error' : 'success';
					break;
				case 'unsync':
					$removed = Sync::unsync( $project_id, $connection_id );
					$message = $removed ? __( 'Local Descript mapping removed. Neither project was deleted.', 'worldgraph' ) : __( 'No Descript mapping was removed.', 'worldgraph' );
					$message_type = $removed ? 'success' : 'error';
					break;
				default:
					$message = __( 'Descript Sync settings saved.', 'worldgraph' );
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Descript Sync', 'worldgraph' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
			<?php if ( empty( $connections ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo wp_kses_post( sprintf(
					/* translators: %s: URL to the Connections admin screen. */
					__( 'Create a <strong>Descript</strong> Connection with an API token before enabling sync. <a href="%s">Open Connections</a>.', 'worldgraph' ),
					esc_url( admin_url( 'edit.php?post_type=worldgraph_conn' ) )
				) ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'worldgraph_descript_settings', 'worldgraph_descript_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="descript-connection"><?php esc_html_e( 'Connection', 'worldgraph' ); ?></label></th>
						<td><select id="descript-connection" name="connection_id">
							<option value="0"><?php esc_html_e( 'Select a Descript Connection', 'worldgraph' ); ?></option>
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
						<th scope="row"><label for="descript-project"><?php esc_html_e( 'Local project', 'worldgraph' ); ?></label></th>
						<td><select id="descript-project" name="project_id">
							<option value="0"><?php esc_html_e( 'Select a project', 'worldgraph' ); ?></option>
							<?php foreach ( $projects as $project ) : ?>
								<option value="<?php echo esc_attr( $project->ID ); ?>" <?php selected( $project_id, $project->ID ); ?>><?php echo esc_html( $project->post_title ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="descript-remote-project"><?php esc_html_e( 'Descript project ID', 'worldgraph' ); ?></label></th>
						<td><input type="text" id="descript-remote-project" name="remote_project_id" class="regular-text" value="<?php echo esc_attr( $remote_project_id ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="descript-composition"><?php esc_html_e( 'Composition ID (optional)', 'worldgraph' ); ?></label></th>
						<td><input type="text" id="descript-composition" name="composition_id" class="regular-text" value="<?php echo esc_attr( $composition_id ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="descript-format"><?php esc_html_e( 'Transcript format', 'worldgraph' ); ?></label></th>
						<td><select id="descript-format" name="format">
							<?php foreach ( [ 'markdown', 'txt', 'html', 'rtf', 'docx' ] as $format ) : ?>
								<option value="<?php echo esc_attr( $format ); ?>"><?php echo esc_html( $format ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="descript-folder"><?php esc_html_e( 'Export folder name (optional)', 'worldgraph' ); ?></label></th>
						<td><input type="text" id="descript-folder" name="folder_name" class="regular-text" placeholder="World Graph Studio" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="descript-job"><?php esc_html_e( 'Job ID (for polling)', 'worldgraph' ); ?></label></th>
						<td><input type="text" id="descript-job" name="job_id" class="regular-text" value="<?php echo esc_attr( $job_id ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Force', 'worldgraph' ); ?></th>
						<td><label><input type="checkbox" name="force" value="1" /> <?php esc_html_e( 'Repeat a pull even after a previous import needed review', 'worldgraph' ); ?></label></td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="descript_action" value="save" class="button button-primary"><?php esc_html_e( 'Save Settings', 'worldgraph' ); ?></button>
					<button type="submit" name="descript_action" value="test" class="button"><?php esc_html_e( 'Test Connection', 'worldgraph' ); ?></button>
					<button type="submit" name="descript_action" value="list" class="button"><?php esc_html_e( 'List Descript Projects', 'worldgraph' ); ?></button>
					<button type="submit" name="descript_action" value="pull" class="button"><?php esc_html_e( 'Pull Transcript', 'worldgraph' ); ?></button>
					<button type="submit" name="descript_action" value="push" class="button"><?php esc_html_e( 'Push Project Media', 'worldgraph' ); ?></button>
					<button type="submit" name="descript_action" value="poll" class="button"><?php esc_html_e( 'Poll Job', 'worldgraph' ); ?></button>
					<button type="submit" name="descript_action" value="unsync" class="button" data-worldgraph-descript-confirm-unsync><?php esc_html_e( 'Remove Mapping', 'worldgraph' ); ?></button>
				</p>
			</form>

			<?php if ( ! empty( $remote_projects ) ) : ?>
				<h2><?php esc_html_e( 'Descript Projects', 'worldgraph' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Project ID', 'worldgraph' ); ?></th><th><?php esc_html_e( 'Name', 'worldgraph' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $remote_projects as $remote ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $remote['id'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $remote['name'] ?? $remote['title'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $project_id ) : ?>
				<h2><?php esc_html_e( 'Current Mapping', 'worldgraph' ); ?></h2>
				<pre><?php echo esc_html( wp_json_encode( Sync::mapping( $project_id, $connection_id ), JSON_PRETTY_PRINT ) ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}
}
