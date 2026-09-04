<?php
/**
 * Admin Plugins Manager for World Graph Studio.
 *
 * Manages World Graph Studio integrations and plugins from the admin area.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

/**
 * Plugins Manager class.
 */
class Plugins {

	/**
	 * Option name used to persist integration enabled states.
	 *
	 * @var string
	 */
	private const STATE_OPTION = 'worldgraph_plugin_states';

	/**
	 * Plugin registry.
	 *
	 * @var array
	 */
	private static $plugins = [];

	/**
	 * Initialize the plugins manager.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'wp_ajax_worldgraph_toggle_plugin', [ __CLASS__, 'ajax_toggle_plugin' ] );
		add_action( 'wp_ajax_worldgraph_test_connection', [ __CLASS__, 'ajax_test_connection' ] );

		// Register available plugins.
		self::register_plugins();
	}

	/**
	 * Register available plugins.
	 */
	private static function register_plugins(): void {
		// Canonical JSON, Markdown, and LLM-assisted story interchange.
		if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/story-import-export/story-import-export.php' ) ) {
			self::register_plugin(
				'story-import-export',
				'World Graph Studio - Story Import & Export',
				[
					'name'         => 'Story Import & Export',
					'description'  => 'Import canonical JSON or decompose uploaded story documents through a selected LLM Connection; export portable JSON or Markdown.',
					'version'      => defined( 'WORLDGRAPH_STORY_IO_VERSION' ) ? WORLDGRAPH_STORY_IO_VERSION : '1.0.0',
					'author'       => 'World Graph Studio Contributors',
					'icon'         => 'dashicons-migrate',
					'file'         => 'plugins/story-import-export/story-import-export.php',
					'has_settings' => true,
					'settings_url' => admin_url( 'admin.php?page=worldgraph-import' ),
					'testable'     => false,
				]
			);
		}

		// Optional WPVDB retrieval bridge for long-form story decomposition.
		if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/story-rag-decomposer/story-rag-decomposer.php' ) ) {
			self::register_plugin(
				'story-rag-decomposer',
				'World Graph Studio - Story RAG Decomposer',
				[
					'name'         => 'Story RAG Decomposer',
					'description'  => 'Adds private, transient WPVDB similarity retrieval to long-form story decomposition. Requires the separately installed WPVDB plugin.',
					'version'      => defined( 'WORLDGRAPH_STORY_RAG_VERSION' ) ? WORLDGRAPH_STORY_RAG_VERSION : '1.0.0',
					'author'       => 'World Graph Studio Contributors',
					'icon'         => 'dashicons-networking',
					'file'         => 'plugins/story-rag-decomposer/story-rag-decomposer.php',
					'has_settings' => true,
					'settings_url' => admin_url( 'admin.php?page=wpvdb-settings' ),
					'testable'     => false,
				]
			);
		}

		// Celtx connector.
		if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/celtx/celtx-sync.php' ) ) {
			self::register_plugin(
				'celtx',
				'World Graph Studio - Celtx Connector',
				[
					'name'        => 'Celtx Connector',
					'description' => 'Send supported World Graph Studio elements to Celtx and retain their remote element mappings.',
					'version'     => '1.0.0',
					'author'      => 'World Graph Studio Contributors',
					'icon'        => 'dashicons-external',
					'file'        => 'plugins/celtx/celtx-sync.php',
					'has_settings' => true,
					'settings_url' => admin_url( 'admin.php?page=celtx-sync' ),
				]
			);
		}

		// Generation Engine plugin.
		if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/comfy-generate/comfy-generate.php' ) ) {
			self::register_plugin(
				'comfy-generate',
				'World Graph Studio - Generation Engine',
				[
					'name'        => 'Generation Engine',
					'description' => 'Adds a generation button to WordPress posts and queues Comfy Cloud MCP jobs through WP-Cron.',
					'version'     => '1.0.0',
					'author'      => 'World Graph Studio Contributors',
					'icon'        => 'dashicons-video-alt3',
					'file'        => 'plugins/comfy-generate/comfy-generate.php',
					'has_settings' => true,
					'settings_url' => admin_url( 'admin.php?page=worldgraph-generation-engine' ),
				]
			);
		}

		// EDL format tools.
		if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/edl/edl-import-export.php' ) ) {
			self::register_plugin(
				'edl',
				'World Graph Studio - EDL Format Tools',
				[
					'name'        => 'EDL Format Tools',
					'description' => 'Parse, preview, and generate CMX-style text and XML edit decision list data for custom editorial adapters.',
					'version'     => '1.0.0',
					'author'      => 'World Graph Studio Contributors',
					'icon'        => 'dashicons-media-video',
					'file'        => 'plugins/edl/edl-import-export.php',
					'has_settings' => true,
					'settings_url' => admin_url( 'admin.php?page=worldgraph-edl' ),
				]
			);
		}

		// VideoDraft bidirectional project sync.
		if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/videodraft/videodraft-sync.php' ) ) {
			self::register_plugin(
				'videodraft',
				'World Graph Studio - VideoDraft Sync',
				[
					'name'         => 'VideoDraft Sync',
					'description'  => 'Import and export project structure through a VideoDraft Connection with preview, checkpoints, and conflict detection.',
					'version'      => '1.0.0',
					'author'       => 'World Graph Studio Contributors',
					'icon'         => 'dashicons-video-alt3',
					'file'         => 'plugins/videodraft/videodraft-sync.php',
					'has_settings' => true,
					'settings_url' => admin_url( 'admin.php?page=worldgraph-videodraft' ),
				]
			);
		}

		// Descript transcript import and project media export.
		if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/descript/descript-sync.php' ) ) {
			self::register_plugin(
				'descript',
				'World Graph Studio - Descript Sync',
				[
					'name'         => 'Descript Sync',
					'description'  => 'Import Descript project transcripts into the Story Graph and export bound Project media into new Descript projects.',
					'version'      => '1.0.0',
					'author'       => 'World Graph Studio Contributors',
					'icon'         => 'dashicons-media-text',
					'file'         => 'plugins/descript/descript-sync.php',
					'has_settings' => true,
					'settings_url' => admin_url( 'admin.php?page=worldgraph-descript' ),
				]
			);
		}

		// Final Draft FDX import.
		if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/fdx/fdx-import.php' ) ) {
			self::register_plugin(
				'fdx',
				'World Graph Studio - Final Draft FDX Import',
				[
					'name'        => 'Final Draft FDX Import',
					'description' => 'Import Final Draft FDX screenplay files into the World Graph Studio Story Graph.',
					'version'     => '1.0.0',
					'author'      => 'World Graph Studio Contributors',
					'icon'        => 'dashicons-media-document',
					'file'        => 'plugins/fdx/fdx-import.php',
					'has_settings' => true,
					'settings_url' => admin_url( 'admin.php?page=worldgraph-fdx' ),
				]
			);
		}

		// Fountain import.
		if ( file_exists( WORLDGRAPH_PLUGIN_DIR . 'plugins/fountain/fountain-import.php' ) ) {
			self::register_plugin(
				'fountain',
				'World Graph Studio - Fountain Import',
				[
					'name'        => 'Fountain Import',
					'description' => 'Convert Fountain screenplay files to FDX in the browser and import them into the World Graph Studio Story Graph.',
					'version'     => '1.0.0',
					'author'      => 'World Graph Studio Contributors',
					'icon'        => 'dashicons-media-text',
					'file'        => 'plugins/fountain/fountain-import.php',
					'has_settings' => true,
					'settings_url' => admin_url( 'admin.php?page=worldgraph-fountain' ),
				]
			);
		}

		// Future integrations can be registered here:
		// self::register_plugin( 'integration-name', 'Plugin Name', [ ... ] );

		self::hydrate_plugin_state();
	}

	/**
	 * Hydrate plugin status from persisted options.
	 */
	private static function hydrate_plugin_state(): void {
		foreach ( self::$plugins as $slug => $plugin ) {
			self::$plugins[ $slug ]['active'] = self::is_plugin_enabled( $slug );
			self::$plugins[ $slug ]['configured'] = self::is_plugin_configured( $slug );
		}
	}

	/**
	 * Get persisted plugin state map.
	 *
	 * @return array
	 */
	private static function get_saved_states(): array {
		$states = get_option( self::STATE_OPTION, [] );

		if ( ! is_array( $states ) ) {
			return [];
		}

		return $states;
	}

	/**
	 * Determine whether a plugin is enabled.
	 *
	 * @param string $slug Plugin slug.
	 * @return bool
	 */
	private static function is_plugin_enabled( string $slug ): bool {
		$states = self::get_saved_states();

		if ( array_key_exists( $slug, $states ) ) {
			return (bool) $states[ $slug ];
		}

		switch ( $slug ) {
			case 'story-import-export':
				return (bool) get_option( 'worldgraph_story_io_enabled', true );

			case 'story-rag-decomposer':
				return (bool) get_option( 'worldgraph_story_rag_enabled', false );

			case 'celtx':
				return (bool) get_option( 'celtx_enabled', false );

			case 'comfy-generate':
					if ( class_exists( '\\WorldGraphGenerationEngine\\Settings' ) ) {
						return \WorldGraphGenerationEngine\Settings::is_enabled();
				}
					$settings = get_option( 'worldgraph_gen_engine_settings', [] );
					return is_array( $settings ) && ! empty( $settings['enabled'] );

			case 'edl':
				return (bool) get_option( 'worldgraph_edl_enabled', true );

			case 'videodraft':
				return class_exists( '\\WorldGraphVideoDraft\\Settings' )
					? \WorldGraphVideoDraft\Settings::is_enabled()
					: (bool) get_option( 'worldgraph_videodraft_enabled', false );

			case 'descript':
				return class_exists( '\\WorldGraphDescript\\Settings' )
					? \WorldGraphDescript\Settings::is_enabled()
					: (bool) get_option( 'worldgraph_descript_enabled', false );

			case 'fdx':
				return (bool) get_option( 'worldgraph_fdx_enabled', true );

			case 'fountain':
				return (bool) get_option( 'worldgraph_fountain_enabled', true );

			default:
				return false;
		}
	}

	/**
	 * Determine whether a plugin has required configuration.
	 *
	 * @param string $slug Plugin slug.
	 * @return bool
	 */
	private static function is_plugin_configured( string $slug ): bool {
		switch ( $slug ) {
			case 'story-import-export':
				return true;

			case 'story-rag-decomposer':
				return function_exists( '\\WorldGraphStoryRAG\\is_configured' ) && \WorldGraphStoryRAG\is_configured();

			case 'celtx':
				if ( class_exists( '\\WorldGraphCeltx\\Settings' ) ) {
					return \WorldGraphCeltx\Settings::has_credentials();
				}
				$credentials = get_option( 'celtx_credentials', [] );
				return is_array( $credentials ) && ! empty( $credentials['api_key'] );

			case 'comfy-generate':
					if ( class_exists( '\\WorldGraphGenerationEngine\\Settings' ) ) {
						return \WorldGraphGenerationEngine\Settings::is_configured();
				}
					foreach ( \WorldGraph\Utils\Connection_Repository::get_all( [ 'provider_type' => 'comfyui' ] ) as $connection ) {
						if ( '' !== trim( (string) ( $connection['endpoint_url'] ?? '' ) ) ) {
							return true;
						}
					}
					foreach ( \WorldGraph\Utils\Connection_Repository::get_all( [ 'provider_type' => 'comfy_cloud' ] ) as $connection ) {
						if ( '' !== trim( (string) ( $connection['credential_reference'] ?? '' ) ) ) {
							return true;
						}
					}
					return false;

			case 'edl':
				return true;

			case 'videodraft':
				if ( class_exists( '\\WorldGraphVideoDraft\\Settings' ) ) {
					return \WorldGraphVideoDraft\Settings::is_configured();
				}
				foreach ( \WorldGraph\Utils\Connection_Repository::get_all( [ 'provider_type' => 'videodraft' ] ) as $connection ) {
					if ( 'disabled' !== ( $connection['status'] ?? '' ) && '' !== trim( (string) ( $connection['credential_reference'] ?? '' ) ) ) {
						return true;
					}
				}
				return false;

			case 'descript':
				if ( class_exists( '\\WorldGraphDescript\\Settings' ) ) {
					return \WorldGraphDescript\Settings::is_configured();
				}
				foreach ( \WorldGraph\Utils\Connection_Repository::get_all( [ 'provider_type' => 'descript' ] ) as $connection ) {
					if ( 'disabled' !== ( $connection['status'] ?? '' ) && '' !== trim( (string) ( $connection['credential_reference'] ?? '' ) ) ) {
						return true;
					}
				}
				return false;

			case 'fdx':
			case 'fountain':
				return true;

			default:
				return false;
		}
	}

	/**
	 * Persist plugin enabled state.
	 *
	 * @param string $slug Plugin slug.
	 * @param bool   $enabled New enabled state.
	 */
	private static function persist_plugin_state( string $slug, bool $enabled ): void {
		$states = self::get_saved_states();
		$states[ $slug ] = $enabled;
		update_option( self::STATE_OPTION, $states );

		switch ( $slug ) {
			case 'story-import-export':
				update_option( 'worldgraph_story_io_enabled', $enabled );
				break;

			case 'story-rag-decomposer':
				update_option( 'worldgraph_story_rag_enabled', $enabled );
				break;

			case 'celtx':
				if ( class_exists( '\\WorldGraphCeltx\\Settings' ) ) {
					if ( $enabled ) {
						\WorldGraphCeltx\Settings::enable();
					} else {
						\WorldGraphCeltx\Settings::disable();
					}
				} else {
					update_option( 'celtx_enabled', $enabled );
				}
				break;

			case 'comfy-generate':
					if ( class_exists( '\\WorldGraphGenerationEngine\\Settings' ) ) {
					if ( $enabled ) {
							\WorldGraphGenerationEngine\Settings::enable();
					} else {
							\WorldGraphGenerationEngine\Settings::disable();
					}
				}
				break;

			case 'edl':
				update_option( 'worldgraph_edl_enabled', $enabled );
				break;

			case 'videodraft':
				if ( class_exists( '\\WorldGraphVideoDraft\\Settings' ) ) {
					$enabled ? \WorldGraphVideoDraft\Settings::enable() : \WorldGraphVideoDraft\Settings::disable();
				} else {
					update_option( 'worldgraph_videodraft_enabled', $enabled );
				}
				break;

			case 'descript':
				if ( class_exists( '\\WorldGraphDescript\\Settings' ) ) {
					$enabled ? \WorldGraphDescript\Settings::enable() : \WorldGraphDescript\Settings::disable();
				} else {
					update_option( 'worldgraph_descript_enabled', $enabled );
				}
				break;

			case 'fdx':
				update_option( 'worldgraph_fdx_enabled', $enabled );
				break;

			case 'fountain':
				update_option( 'worldgraph_fountain_enabled', $enabled );
				break;
		}
	}

	/**
	 * Register a plugin.
	 *
	 * @param string $slug
	 * @param string $name
	 * @param array  $args
	 */
	public static function register_plugin( string $slug, string $name, array $args = [] ): void {
		self::$plugins[ $slug ] = [
			'slug'        => $slug,
			'name'        => $name,
			'description' => $args['description'] ?? '',
			'version'     => $args['version'] ?? '1.0.0',
			'author'      => $args['author'] ?? '',
			'icon'        => $args['icon'] ?? 'dashicons-admin-plugins',
			'file'        => $args['file'] ?? '',
			'active'      => false,
			'configured'  => false,
			'has_settings' => ! empty( $args['has_settings'] ),
			'settings_url' => $args['settings_url'] ?? '',
			'testable'     => ! array_key_exists( 'testable', $args ) || ! empty( $args['testable'] ),
		];
	}

	/**
	 * Get all registered plugins.
	 *
	 * @return array
	 */
	public static function get_plugins(): array {
		return self::$plugins;
	}

	/**
	 * Get a single plugin by slug.
	 *
	 * @param string $slug
	 * @return array|null
	 */
	public static function get_plugin( string $slug ): ?array {
		return self::$plugins[ $slug ] ?? null;
	}

	/**
	 * Add admin menu.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'worldgraph-administration',
			'Plugins',
			'Plugins',
			'manage_options',
			'worldgraph-plugins',
			[ __CLASS__, 'render_plugins_page' ]
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook
	 */
	public static function enqueue_scripts( string $hook ): void {
		if ( strpos( $hook, 'worldgraph-plugins' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'worldgraph-plugins',
			WORLDGRAPH_PLUGIN_URL . 'assets/js/plugins.js',
			[ 'jquery' ],
			WORLDGRAPH_VERSION,
			true
		);

		wp_localize_script( 'worldgraph-plugins', 'worldgraphPlugins', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'worldgraph_admin' ),
		] );
	}

	/**
	 * Render the plugins management page.
	 */
	public static function render_plugins_page(): void {
		$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
		$message_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
		$message_type = in_array( $message_type, [ 'success', 'error', 'warning', 'info' ], true ) ? $message_type : 'success';
		?>
		<div class="wrap worldgraph-plugins">
			<h1>World Graph Studio Plugins</h1>

			<?php if ( ! empty( $message ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<h2>Feature Plugins</h2>

			<?php if ( empty( self::$plugins ) ) : ?>
				<div class="notice notice-info">
					<p>No plugins registered yet. Integrations will appear here.</p>
				</div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Plugin</th>
						<th>Status</th>
						<th>Configuration</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::$plugins as $slug => $plugin ) : ?>
						<tr data-plugin="<?php echo esc_attr( $slug ); ?>">
							<td>
								<strong>
									<span class="dashicons <?php echo esc_attr( $plugin['icon'] ); ?>" style="margin-right: 5px;"></span>
									<?php echo esc_html( $plugin['name'] ); ?>
								</strong>
								<br>
								<small>
									<?php echo esc_html( $plugin['description'] ); ?>
									<br>
									<span class="worldgraph-plugin-meta">
										Version <?php echo esc_html( $plugin['version'] ); ?> &middot; <?php echo esc_html( $plugin['author'] ); ?>
									</span>
								</small>
							</td>
							<td>
								<?php if ( $plugin['active'] ) : ?>
									<span class="status-active">Active</span>
								<?php else : ?>
									<span class="status-inactive">Inactive</span>
								<?php endif; ?>
								<?php if ( $plugin['configured'] ) : ?>
									<br><span class="status-configured">✓ Configured</span>
								<?php else : ?>
									<br><span class="status-unconfigured">Not configured</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $plugin['has_settings'] ) : ?>
									<a href="<?php echo esc_url( $plugin['settings_url'] ); ?>" class="button button-small">
										<span class="dashicons dashicons-admin-generic"></span> Settings
									</a>
								<?php else : ?>
									<span class="dashicons dashicons-info" style="color: #999;"></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$requires_configuration = ! $plugin['active'] && ! $plugin['configured'] && $plugin['has_settings'];
								?>
								<button class="button button-small worldgraph-toggle-plugin" data-plugin="<?php echo esc_attr( $slug ); ?>" <?php disabled( $requires_configuration ); ?>>
									<?php echo $plugin['active'] ? 'Disable' : 'Enable'; ?>
								</button>
								<?php if ( $requires_configuration ) : ?>
									<a href="<?php echo esc_url( $plugin['settings_url'] ); ?>" class="button button-small button-primary">Configure First</a>
								<?php endif; ?>
								<?php if ( $plugin['active'] && $plugin['configured'] && $plugin['testable'] ) : ?>
									<button class="button button-small worldgraph-test-connection" data-plugin="<?php echo esc_attr( $slug ); ?>">
										Test Connection
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * AJAX handler for toggling plugins.
	 */
	public static function ajax_toggle_plugin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'You are not allowed to perform this action.' ], 403 );
		}

		check_ajax_referer( 'worldgraph_admin', 'nonce' );

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$plugin = self::get_plugin( $slug );

		if ( ! $plugin ) {
			wp_send_json_error( [ 'message' => 'Plugin not found.' ] );
		}

		// Toggle and persist plugin state.
		$new_state = ! self::is_plugin_enabled( $slug );

		if ( $new_state && ! self::is_plugin_configured( $slug ) && ! empty( $plugin['has_settings'] ) ) {
			wp_send_json_error( [
				'message' => 'Please configure this plugin before enabling it.',
				'settings_url' => $plugin['settings_url'],
			] );
		}

		self::persist_plugin_state( $slug, $new_state );
		self::$plugins[ $slug ]['active'] = $new_state;
		self::$plugins[ $slug ]['configured'] = self::is_plugin_configured( $slug );

		wp_send_json_success( [
			'message' => sprintf( '%s %s.', $plugin['name'], $new_state ? 'enabled' : 'disabled' ),
			'active'  => $new_state,
			'configured' => self::$plugins[ $slug ]['configured'],
			'reload_required' => true,
		] );
	}

	/**
	 * AJAX handler for testing connections.
	 */
	public static function ajax_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'You are not allowed to perform this action.' ], 403 );
		}
		check_ajax_referer( 'worldgraph_admin', 'nonce' );

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

		if ( $slug === 'celtx' ) {
			// Use the Celtx API client to test connection.
			if ( class_exists( 'WorldGraphCeltx\API\Client' ) ) {
				$client = \WorldGraphCeltx\API\Client::from_credentials();

				if ( is_wp_error( $client ) ) {
					wp_send_json_error( [ 'message' => 'Missing credentials. Please configure the plugin first.' ] );
				}

				$status = $client->get_status();

				if ( is_wp_error( $status ) ) {
					wp_send_json_error( [ 'message' => 'Connection failed: ' . $status->get_error_message() ] );
				}

				wp_send_json_success( [
					'message' => 'Connection successful!',
					'status'  => $status,
				] );
			} else {
				wp_send_json_error( [ 'message' => 'Celtx API client not available.' ] );
			}
		} elseif ( 'videodraft' === $slug ) {
			\WorldGraph\Utils\Connection_Adapters::load( 'videodraft' );
			$connection_id = class_exists( '\\WorldGraphVideoDraft\\Settings' ) ? \WorldGraphVideoDraft\Settings::connection_id() : 0;
			if ( ! $connection_id ) {
				$connection_id = (int) ( \WorldGraph\Utils\Connection_Repository::get_default( 'videodraft' ) ?? 0 );
			}
			if ( ! $connection_id ) {
				wp_send_json_error( [ 'message' => 'Select a VideoDraft Connection in the plugin settings first.' ] );
			}
			$result = \WorldGraph\Utils\Connection_Tester::test( $connection_id );
			if ( empty( $result['success'] ) ) {
				wp_send_json_error( [ 'message' => $result['message'] ?? 'VideoDraft connection failed.' ] );
			}
			wp_send_json_success( [ 'message' => $result['message'], 'status' => $result['health'] ?? [] ] );
		} else {
			wp_send_json_error( [ 'message' => 'No test handler for this plugin.' ] );
		}
	}
}
