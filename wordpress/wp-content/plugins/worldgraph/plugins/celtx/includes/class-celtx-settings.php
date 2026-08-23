<?php
/**
 * Celtx Settings.
 *
 * Handles the WordPress admin settings page for Celtx API credentials.
 *
 * @package WorldGraphCeltx
 */

namespace WorldGraphCeltx;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Celtx Settings class.
 *
 * Manages the admin settings page and API credential storage.
 */
class Settings {

	/**
	 * Settings instance.
	 *
	 * @var Settings|null
	 */
	private static $instance = null;

	/**
	 * Option name for storing Celtx credentials.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'celtx_credentials';

	/**
	 * Option name for the Celtx enabled/disabled state.
	 *
	 * @var string
	 */
	private const ENABLED_OPTION_NAME = 'celtx_enabled';

	/**
	 * Get the settings instance.
	 *
	 * @return Settings
	 */
	public static function init(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Register hooks.
		add_action( 'admin_menu', [ $this, 'add_settings_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Add settings page to admin menu.
	 */
	public function add_settings_menu(): void {
		add_submenu_page(
			'worldgraph-plugins', // Parent menu slug.
			'Celtx Sync', // Page title.
			'Celtx Sync', // Menu title.
			'manage_options', // Capability.
			'celtx-sync', // Menu slug.
			[ $this, 'render_settings_page' ] // Callback.
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings(): void {
		register_setting(
			'celtx_settings_group',
			self::OPTION_NAME,
			[
				'type'              => 'object',
				'sanitize_callback' => [ $this, 'sanitize_credentials' ],
				'default'           => [
					'api_key'    => '',
					'project_id' => '',
				],
			]
		);
	}

	/**
	 * Sanitize credentials input.
	 *
	 * @param array $input The input to sanitize.
	 * @return array
	 */
	public function sanitize_credentials( array $input ): array {
		$sanitized = [
			'api_key'    => '',
			'project_id' => '',
		];

		if ( ! empty( $input['api_key'] ) ) {
			$sanitized['api_key'] = sanitize_text_field( $input['api_key'] );
		}

		if ( ! empty( $input['project_id'] ) ) {
			$sanitized['project_id'] = sanitize_text_field( $input['project_id'] );
		}

		return $sanitized;
	}

	/**
	 * Get whether Celtx sync is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$creds = self::get_credentials();
		$enabled = get_option( self::ENABLED_OPTION_NAME, false );
		return ! empty( $creds['api_key'] ) && (bool) $enabled;
	}

	/**
	 * Enable Celtx sync.
	 */
	public static function enable(): void {
		update_option( self::ENABLED_OPTION_NAME, true );
	}

	/**
	 * Disable Celtx sync.
	 */
	public static function disable(): void {
		update_option( self::ENABLED_OPTION_NAME, false );
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Celtx Sync.', 'worldgraph' ) );
		}

		// Get current credentials.
		$credentials = get_option( self::OPTION_NAME, [
			'api_key'    => '',
			'project_id' => '',
		] );

		// Handle form submission.
		$message = '';
		$message_type = '';

		$settings_nonce = isset( $_POST['celtx_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['celtx_settings_nonce'] ) ) : '';
		if ( isset( $_POST['celtx_settings_submit'] ) && wp_verify_nonce( $settings_nonce, 'celtx_settings' ) ) {
			// Save settings.
			$submitted_credentials = isset( $_POST['celtx_credentials'] ) && is_array( $_POST['celtx_credentials'] ) ? wp_unslash( $_POST['celtx_credentials'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_credentials() allowlists and sanitizes both supported fields immediately below.
			$sanitized = $this->sanitize_credentials( $submitted_credentials );
			update_option( self::OPTION_NAME, $sanitized );

			// Save enabled/disabled state.
			$enabled = isset( $_POST['celtx_enabled'] ) && 1 === absint( wp_unslash( $_POST['celtx_enabled'] ) );
			if ( $enabled ) {
				self::enable();
			} else {
				self::disable();
			}

			$message = 'Settings saved successfully.';
			$message_type = 'success';

			$credentials = $sanitized;
		}

		// Handle test connection.
		$test_nonce = isset( $_POST['celtx_test_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['celtx_test_nonce'] ) ) : '';
		if ( isset( $_POST['celtx_test_connection'] ) && wp_verify_nonce( $test_nonce, 'celtx_test' ) ) {
			$client = \WorldGraphCeltx\API\Client::from_credentials();
			
			if ( $client ) {
				$response = $client->get_status();
				$parsed = $client->parse_response( $response );

				if ( $client->is_success( $response ) ) {
					$message = 'Connection successful! Celtx API is responding.';
					$message_type = 'success';
				} else {
					$message = 'Connection failed: ' . esc_html( $parsed['error'] ?? 'Unknown error' );
					$message_type = 'error';
				}
			} else {
				$message = 'Cannot test connection: API key is not configured.';
				$message_type = 'error';
			}
		}

		?>
		<div class="wrap">
			<h1>Celtx Sync Settings</h1>
			
			<?php if ( ! empty( $message ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( 'celtx_settings_group' ); ?>
				<?php do_settings_sections( 'celtx_settings_group' ); ?>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="celtx_credentials_api_key">Celtx API Key</label>
						</th>
						<td>
							<input 
								type="password" 
								id="celtx_credentials_api_key" 
								name="celtx_credentials[api_key]" 
								value="<?php echo esc_attr( \WorldGraph\Utils\Credential_Store::masked_value( $credentials['api_key'] ?? '' ) ); ?>"
								class="regular-text"
								autocomplete="new-password"
								placeholder="Enter your Celtx API key"
							/>
							<p class="description">
								Your Celtx API key is encrypted in the database. Leave the masked value unchanged to keep it.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="celtx_credentials_project_id">Celtx Project ID</label>
						</th>
						<td>
							<input 
								type="text" 
								id="celtx_credentials_project_id" 
								name="celtx_credentials[project_id]" 
								value="<?php echo esc_attr( $credentials['project_id'] ); ?>" 
								class="regular-text"
								placeholder="Enter your Celtx Project ID"
							/>
							<p class="description">
								The Celtx Project ID to sync World Graph Studio elements to. Leave empty to sync to a default project.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="celtx_enabled">Enable Celtx Sync</label>
						</th>
						<td>
							<label>
								<input 
									type="checkbox" 
									id="celtx_enabled" 
									name="celtx_enabled" 
									value="1"
									<?php checked( \WorldGraphCeltx\Settings::is_enabled() ); ?>
								/>
								<span class="dashicons dashicons-yes" style="color: #46b450; margin-right: 4px;"></span>
								Enable Celtx synchronization
							</label>
							<p class="description">
								When enabled, authenticated REST requests and connector actions can send World Graph Studio elements to Celtx. Disable this to pause outbound synchronization without removing your API credentials.
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input 
						type="submit" 
						name="celtx_settings_submit" 
						id="celtx_settings_submit" 
						class="button button-primary" 
						value="<?php esc_attr_e( 'Save Settings', 'worldgraph' ); ?>"
					/>
					<?php wp_nonce_field( 'celtx_settings', 'celtx_settings_nonce' ); ?>
				</p>
			</form>

			<hr/>

			<h2>Test Connection</h2>
			<p>Test your Celtx API credentials by making a request to the Celtx API.</p>
			
			<form method="post" action="">
				<p>
					<input 
						type="submit" 
						name="celtx_test_connection" 
						id="celtx_test_connection" 
						class="button button-secondary" 
						value="<?php esc_attr_e( 'Test Connection', 'worldgraph' ); ?>"
					/>
					<?php wp_nonce_field( 'celtx_test', 'celtx_test_nonce' ); ?>
				</p>
			</form>

			<hr/>

			<h2>About Celtx Sync</h2>
			<p>The Celtx Sync plugin synchronizes World Graph Studio elements with Celtx elements:</p>
			<ul>
				<li><strong>Projects</strong> → Celtx Projects</li>
				<li><strong>Characters</strong> → Celtx Characters (Elements)</li>
				<li><strong>Locations</strong> → Celtx Locations (Elements)</li>
				<li><strong>Scenes</strong> → Celtx Scenes</li>
				<li><strong>Shots</strong> → Celtx Scene Comments</li>
			</ul>
			<p>Each synchronized World Graph Studio element stores its corresponding Celtx element ID in post meta so later outbound updates can target the same remote element.</p>
		</div>
		<?php
	}

	/**
	 * Get stored credentials.
	 *
	 * @return array
	 */
	public static function get_credentials(): array {
		return get_option( self::OPTION_NAME, [
			'api_key'    => '',
			'project_id' => '',
		] );
	}

	/**
	 * Check if credentials are configured.
	 *
	 * @return bool
	 */
	public static function has_credentials(): bool {
		$creds = self::get_credentials();
		return ! empty( $creds['api_key'] );
	}
}

/**
 * Get Celtx credentials.
 *
 * @return array
 */
function get_celtx_credentials(): array {
	return Settings::get_credentials();
}

/**
 * Check if Celtx sync is enabled.
 *
 * @return bool
 */
function celtx_sync_enabled(): bool {
	return Settings::is_enabled();
}
