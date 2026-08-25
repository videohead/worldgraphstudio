<?php
/**
 * Reusable OAuth lifecycle for provider Connections.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Connections;

use WP_Error;
use WorldGraph\Utils\Connection_Repository;
use WorldGraph\Utils\Credential_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared public-client OAuth 2.0 authorization-code + PKCE service.
 *
 * Providers declare trusted endpoint and presentation metadata under the
 * adapter manifest's `oauth` key. The service owns admin routing, one-time
 * state, DCR, token exchange, mutation locking, and encrypted-at-rest storage.
 */
final class Connection_OAuth {

	/** Bounds for network, state, and credential handling. */
	private const TIMEOUT            = 30;
	private const MAX_RESPONSE_BYTES = 65536;
	private const STATE_TTL          = 600;
	private const STATE_LOCK_TTL     = 30;
	private const REFRESH_LEEWAY      = 60;
	private const MUTATION_LOCK_TTL   = 120;
	private const TOKEN_MAX_BYTES     = 16384;

	/** The one external host temporarily permitted for an authorization redirect. */
	private static string $redirect_host = '';

	/** OAuth forms rendered in the admin footer, outside WordPress's post form. */
	private static array $admin_forms = [];

	/** Prevent duplicate global action registration. */
	private static bool $initialized = false;

	/** Register provider-neutral authenticated admin-post handlers once. */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'admin_post_worldgraph_connection_oauth_start', [ __CLASS__, 'handle_start' ] );
		add_action( 'admin_post_worldgraph_connection_oauth_callback', [ __CLASS__, 'handle_callback' ] );
		add_action( 'admin_post_worldgraph_connection_oauth_disconnect', [ __CLASS__, 'handle_disconnect' ] );
		add_action( 'admin_footer', [ __CLASS__, 'render_queued_admin_forms' ] );
	}

	/**
	 * Render reusable provider OAuth controls from manifest metadata.
	 *
	 * @param \WP_Post             $post     Connection post.
	 * @param array<string, mixed> $manifest Adapter manifest.
	 */
	public static function render_admin( \WP_Post $post, array $manifest = [] ): void {
		$provider = sanitize_key( (string) \WorldGraph\Utils\worldgraph_get_field_value( (int) $post->ID, 'provider_type' ) );
		$connection = Connection_Repository::get( (int) $post->ID );
		if ( ! is_array( $connection ) || $provider !== ( $connection['provider_type'] ?? '' ) ) {
			echo '<p>' . esc_html__( 'Save this Connection before authorizing the provider.', 'worldgraph' ) . '</p>';
			return;
		}
		$profiles = self::profile_names( $manifest );
		if ( empty( $profiles ) ) {
			echo '<p>' . esc_html__( 'This provider’s OAuth configuration is unavailable.', 'worldgraph' ) . '</p>';
			return;
		}

		foreach ( $profiles as $profile ) {
			$config = self::normalize_config( $provider, $manifest, $profile );
			if ( is_wp_error( $config ) ) {
				echo '<p>' . esc_html__( 'This provider’s OAuth configuration is unavailable.', 'worldgraph' ) . '</p>';
				continue;
			}
			self::render_profile_controls( $post, $provider, $profile, $config );
		}

		$templates = is_array( $manifest['templates'] ?? null ) ? $manifest['templates'] : [];
		$prefix    = sanitize_key( (string) ( $templates['status_meta_prefix'] ?? '' ) );
		if ( '' !== $prefix ) {
			$synced_at = (string) get_post_meta( (int) $post->ID, $prefix . '_synced_at', true );
			$error     = (string) get_post_meta( (int) $post->ID, $prefix . '_error', true );
			echo '<p><strong>' . esc_html__( 'Last workflow refresh:', 'worldgraph' ) . '</strong> ' . esc_html( $synced_at ?: '—' ) . '</p>';
			if ( '' !== $error ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
			}
		}
	}

	/** Render one independently stored OAuth profile. */
	private static function render_profile_controls( \WP_Post $post, string $provider, string $profile, array $config ): void {

		$field      = (string) $config['credential_field'];
		$loaded     = Credential_Store::load_connection_secret( (int) $post->ID, $field );
		$credential = is_wp_error( $loaded ) ? '' : trim( (string) $loaded );
		$credential_status = self::credential_status( $provider, $credential, $config );
		$connected  = in_array( $credential_status, [ 'connected', 'refresh_available', 'external_token' ], true );
		$label      = (string) $config['service_label'];
		$notice_key = isset( $_GET['worldgraph_connection_oauth'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status from this class's bounded redirect.
			? sanitize_key( wp_unslash( $_GET['worldgraph_connection_oauth'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$notice_profile = isset( $_GET['oauth_profile'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status from this class's bounded redirect.
			? sanitize_key( wp_unslash( $_GET['oauth_profile'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$notices    = [
			'connected'           => [ 'success', sprintf( __( '%s authorization is connected.', 'worldgraph' ), $label ) ],
			'disconnected'        => [ 'success', sprintf( __( '%s authorization was removed locally.', 'worldgraph' ), $label ) ],
			'denied'              => [ 'error', sprintf( __( '%s did not grant authorization.', 'worldgraph' ), $label ) ],
			'registration_failed' => [ 'error', sprintf( __( '%s could not register or resolve its OAuth client. Confirm the site callback URL and try again.', 'worldgraph' ), $label ) ],
			'token_failed'        => [ 'error', sprintf( __( '%s did not complete the OAuth token exchange. Try connecting again.', 'worldgraph' ), $label ) ],
			'storage_failed'      => [ 'error', __( 'World Graph Studio could not store the OAuth credential securely.', 'worldgraph' ) ],
			'busy'                => [ 'error', __( 'Another request is updating this authorization. Retry shortly.', 'worldgraph' ) ],
		];
		if ( $profile === $notice_profile && isset( $notices[ $notice_key ] ) ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				esc_attr( $notices[ $notice_key ][0] ),
				esc_html( $notices[ $notice_key ][1] )
			);
		}

		if ( '' !== (string) $config['admin_intro'] ) {
			echo '<p>' . esc_html( (string) $config['admin_intro'] ) . '</p>';
		}
		?>
		<ul>
			<?php if ( '' !== (string) $config['credential_help'] ) : ?><li><?php echo esc_html( (string) $config['credential_help'] ); ?></li><?php endif; ?>
			<li><?php echo esc_html( sprintf( __( 'OAuth scopes: %s. Access and refresh tokens are encrypted at rest.', 'worldgraph' ), implode( ', ', (array) $config['scopes'] ) ) ); ?></li>
			<?php if ( '' !== (string) $config['usage_notice'] ) : ?><li><?php echo esc_html( (string) $config['usage_notice'] ); ?></li><?php endif; ?>
		</ul>
		<?php
		$status_labels = [
			'connected'          => __( 'Connected', 'worldgraph' ),
			'refresh_available'  => __( 'Connected; refresh available', 'worldgraph' ),
			'external_token'     => __( 'Externally managed token', 'worldgraph' ),
			'reconnect_required' => __( 'Reconnect required', 'worldgraph' ),
			'not_connected'      => __( 'Not connected', 'worldgraph' ),
		];
		?>
		<p><strong><?php echo esc_html( sprintf( __( '%s authorization:', 'worldgraph' ), $label ) ); ?></strong> <?php echo esc_html( $status_labels[ $credential_status ] ?? $status_labels['not_connected'] ); ?></p>
		<?php
		$action       = $connected ? 'worldgraph_connection_oauth_disconnect' : 'worldgraph_connection_oauth_start';
		$form_id      = 'worldgraph-connection-oauth-' . (int) $post->ID . '-' . $profile;
		$nonce_action = 'worldgraph_connection_oauth_' . ( $connected ? 'disconnect_' : 'start_' ) . $post->ID . '_' . $profile;
		self::$admin_forms[ $form_id ] = [
			'action'        => $action,
			'connection_id' => (int) $post->ID,
			'profile'       => $profile,
			'nonce_action'  => $nonce_action,
		];
		?>
		<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="button <?php echo $connected ? '' : 'button-primary'; ?>"><?php echo esc_html( $connected ? (string) $config['disconnect_label'] : (string) $config['connect_label'] ); ?></button>
		<?php
	}

	/** Render queued mutation forms after WordPress closes the native post form. */
	public static function render_queued_admin_forms(): void {
		foreach ( self::$admin_forms as $form_id => $form ) {
			?>
			<form id="<?php echo esc_attr( (string) $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
				<input type="hidden" name="action" value="<?php echo esc_attr( (string) $form['action'] ); ?>" />
				<input type="hidden" name="connection_id" value="<?php echo esc_attr( (string) $form['connection_id'] ); ?>" />
				<input type="hidden" name="oauth_profile" value="<?php echo esc_attr( (string) $form['profile'] ); ?>" />
				<?php wp_nonce_field( (string) $form['nonce_action'] ); ?>
			</form>
			<?php
		}
		self::$admin_forms = [];
	}

	/** Begin a one-time authorization-code + PKCE exchange. */
	public static function handle_start(): void {
		$connection_id = isset( $_POST['connection_id'] ) ? absint( wp_unslash( $_POST['connection_id'] ) ) : 0;
		$profile       = isset( $_POST['oauth_profile'] ) ? sanitize_key( wp_unslash( $_POST['oauth_profile'] ) ) : '';
		self::verify_admin_action( $connection_id, $profile, 'worldgraph_connection_oauth_start_' . $connection_id . '_' . $profile );
		$context = self::context_for_connection( $connection_id, $profile );
		if ( is_wp_error( $context ) ) {
			self::redirect_to_connection( $connection_id, $profile, 'registration_failed' );
		}

		$config       = $context['config'];
		$provider     = (string) $context['provider'];
		$redirect_uri = self::redirect_uri();
		if ( is_wp_error( $redirect_uri ) ) {
			self::redirect_to_connection( $connection_id, $profile, 'registration_failed' );
		}

		$client_id = self::resolve_client_id( $connection_id, $provider, $profile, $config, $redirect_uri );
		if ( is_wp_error( $client_id ) ) {
			self::redirect_to_connection( $connection_id, $profile, 'registration_failed' );
		}

		try {
			$state    = self::base64url( random_bytes( 32 ) );
			$verifier = self::base64url( random_bytes( 64 ) );
		} catch ( \Throwable ) {
			self::redirect_to_connection( $connection_id, $profile, 'registration_failed' );
		}

		$pending = [
				'connection_id' => $connection_id,
				'user_id'       => get_current_user_id(),
				'provider'      => $provider,
				'profile'       => $profile,
				'client_id'     => $client_id,
				'code_verifier' => $verifier,
				'redirect_uri'  => $redirect_uri,
				'config_hash'   => self::config_hash( $config ),
			];
		try {
			$pending = Credential_Store::encrypt( (string) wp_json_encode( $pending ) );
		} catch ( \Throwable ) {
			self::redirect_to_connection( $connection_id, $profile, 'storage_failed' );
		}
		$stored = set_transient(
			self::state_transient( $state ),
			$pending,
			self::STATE_TTL
		);
		if ( ! $stored ) {
			self::redirect_to_connection( $connection_id, $profile, 'registration_failed' );
		}

		$query = array_merge(
			(array) $config['authorization_parameters'],
			[
				'response_type'         => 'code',
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => implode( ' ', (array) $config['scopes'] ),
				'state'                 => $state,
				'code_challenge'        => self::pkce_challenge( $verifier ),
				'code_challenge_method' => 'S256',
			]
		);
		if ( '' !== (string) $config['resource'] ) {
			$query['resource'] = (string) $config['resource'];
		}
		$url = add_query_arg( $query, (string) $config['authorization_endpoint'] );
		self::$redirect_host = (string) wp_parse_url( $url, PHP_URL_HOST );
		add_filter( 'allowed_redirect_hosts', [ __CLASS__, 'allow_redirect_host' ] );
		wp_safe_redirect( $url );
		exit;
	}

	/** Exchange an authorization response and store its provider token envelope. */
	public static function handle_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'worldgraph' ) );
		}

		$state = isset( $_GET['state'] ) && is_scalar( $_GET['state'] ) ? (string) wp_unslash( $_GET['state'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time OAuth state is verified below.
		if ( 43 !== strlen( $state ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $state ) ) {
			wp_die( esc_html__( 'The OAuth state is invalid.', 'worldgraph' ) );
		}
		$transient_key = self::state_transient( $state );
		$state_lock    = self::acquire_state_lock( $state );
		if ( is_wp_error( $state_lock ) ) {
			wp_die( esc_html__( 'The OAuth request expired, was already used, or is already being processed.', 'worldgraph' ) );
		}
		try {
			$pending = get_transient( $transient_key );
			delete_transient( $transient_key );
			try {
				$pending = is_string( $pending ) ? json_decode( Credential_Store::decrypt( $pending ), true ) : null;
			} catch ( \Throwable ) {
				$pending = null;
			}
		} finally {
			self::release_lock( $state_lock );
		}
		if ( ! is_array( $pending ) ) {
			wp_die( esc_html__( 'The OAuth request expired or was already used.', 'worldgraph' ) );
		}

		$connection_id = absint( $pending['connection_id'] ?? 0 );
		$profile       = sanitize_key( (string) ( $pending['profile'] ?? '' ) );
		$context       = self::context_for_connection( $connection_id, $profile );
		if (
			is_wp_error( $context )
			|| (int) ( $pending['user_id'] ?? 0 ) !== get_current_user_id()
			|| (string) ( $pending['provider'] ?? '' ) !== (string) $context['provider']
			|| ! hash_equals( (string) ( $pending['config_hash'] ?? '' ), self::config_hash( $context['config'] ) )
			|| ! Connection_Repository::current_user_can_manage( $connection_id )
		) {
			wp_die( esc_html__( 'The OAuth request could not be verified.', 'worldgraph' ) );
		}

		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time OAuth state above is the callback CSRF control.
			self::redirect_to_connection( $connection_id, $profile, 'denied' );
		}
		$code = isset( $_GET['code'] ) && is_scalar( $_GET['code'] ) ? (string) wp_unslash( $_GET['code'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time OAuth state above is the callback CSRF control.
		if ( '' === $code || strlen( $code ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $code ) ) {
			self::redirect_to_connection( $connection_id, $profile, 'token_failed' );
		}

		$config = $context['config'];
		$form   = array_merge(
			(array) $config['token_parameters'],
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => (string) ( $pending['redirect_uri'] ?? '' ),
				'client_id'     => (string) ( $pending['client_id'] ?? '' ),
				'code_verifier' => (string) ( $pending['code_verifier'] ?? '' ),
			]
		);
		if ( '' !== (string) $config['resource'] ) {
			$form['resource'] = (string) $config['resource'];
		}
		$lock = self::acquire_credential_lock( $connection_id, (string) $context['provider'], $profile );
		if ( is_wp_error( $lock ) ) {
			self::redirect_to_connection( $connection_id, $profile, 'busy' );
		}
		$outcome = 'storage_failed';
		try {
			$revision = Credential_Store::connection_secret_revision( $connection_id, (string) $config['credential_field'] );
			if ( ! is_wp_error( $revision ) ) {
				$tokens = self::token_request( $config, $form );
				if ( is_wp_error( $tokens ) ) {
					$outcome = 'token_failed';
				} else {
					$envelope = self::envelope_from_response( $tokens, (string) $context['provider'], $profile, (string) $pending['client_id'], $config );
					$outcome  = ! is_wp_error( $envelope ) && self::store_envelope( $connection_id, $config, $envelope, $revision )
						? 'connected'
						: 'storage_failed';
				}
			}
		} finally {
			self::release_lock( $lock );
		}

		self::redirect_to_connection( $connection_id, $profile, $outcome );
	}

	/** Remove only the credential field declared by this provider OAuth contract. */
	public static function handle_disconnect(): void {
		$connection_id = isset( $_POST['connection_id'] ) ? absint( wp_unslash( $_POST['connection_id'] ) ) : 0;
		$profile       = isset( $_POST['oauth_profile'] ) ? sanitize_key( wp_unslash( $_POST['oauth_profile'] ) ) : '';
		self::verify_admin_action( $connection_id, $profile, 'worldgraph_connection_oauth_disconnect_' . $connection_id . '_' . $profile );
		$context = self::context_for_connection( $connection_id, $profile );
		if ( is_wp_error( $context ) ) {
			wp_die( esc_html__( 'This Connection does not have a valid OAuth configuration.', 'worldgraph' ) );
		}
		$lock = self::acquire_credential_lock( $connection_id, (string) $context['provider'], $profile );
		if ( is_wp_error( $lock ) ) {
			self::redirect_to_connection( $connection_id, $profile, 'busy' );
		}
		$stored = false;
		try {
			$stored = Credential_Store::store_connection_secret( $connection_id, (string) $context['config']['credential_field'], '' );
		} finally {
			self::release_lock( $lock );
		}
		self::redirect_to_connection( $connection_id, $profile, $stored ? 'disconnected' : 'storage_failed' );
	}

	/** Allow the current trusted manifest's authorization host for one redirect. */
	public static function allow_redirect_host( array $hosts ): array {
		if ( '' !== self::$redirect_host ) {
			$hosts[] = self::$redirect_host;
		}
		return array_values( array_unique( $hosts ) );
	}

	/** Return the S256 challenge for a PKCE verifier. */
	public static function pkce_challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/** Resolve a plain bearer reference or provider-bound OAuth envelope. */
	public static function token_from_reference( string $provider, string $profile, string $reference ) {
		$config = self::config_for_provider( $provider, $profile );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$resolved = Credential_Store::resolve_reference( $reference );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$resolved = trim( (string) $resolved );
		$envelope = self::credential_envelope( $resolved, $provider, $config );
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}
		$token = is_array( $envelope ) ? (string) ( $envelope['access_token'] ?? '' ) : $resolved;
		return self::valid_token( $token )
			? $token
			: new WP_Error( 'worldgraph_oauth_token_missing', __( 'Connect this provider with OAuth before using its protected service.', 'worldgraph' ) );
	}

	/** Resolve a saved provider token and refresh an expiring envelope once. */
	public static function access_token( int $connection_id, string $profile, string $expected_provider = '', bool $force_refresh = false ) {
		$profile = sanitize_key( $profile );
		$context = self::context_for_connection( $connection_id, $profile );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$provider = (string) $context['provider'];
		$config   = $context['config'];
		if ( '' !== $expected_provider && sanitize_key( $expected_provider ) !== $provider ) {
			return new WP_Error( 'worldgraph_oauth_provider_mismatch', __( 'The OAuth credential belongs to a different Connection provider.', 'worldgraph' ) );
		}

		$field     = (string) $config['credential_field'];
		$reference = Credential_Store::load_connection_secret( $connection_id, $field );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		$reference = (string) $reference;
		$initial_revision = hash( 'sha256', "worldgraph-connection-secret\0" . $reference );
		$external  = str_starts_with( trim( $reference ), 'env://' );
		$resolved  = Credential_Store::resolve_reference( $reference );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$resolved = trim( (string) $resolved );
		$envelope = self::credential_envelope( $resolved, $provider, $config );
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}
		if ( ! is_array( $envelope ) ) {
			return $force_refresh
				? new WP_Error( 'worldgraph_oauth_reconnect_required', __( 'This externally supplied bearer token cannot be refreshed automatically.', 'worldgraph' ) )
				: self::token_from_reference( $provider, $profile, $resolved );
		}
		if ( ! $force_refresh && self::envelope_is_fresh( $envelope ) ) {
			return (string) $envelope['access_token'];
		}
		if ( $external ) {
			return new WP_Error( 'worldgraph_oauth_external_rotation_required', __( 'The env:// OAuth credential must be refreshed by its external secret manager.', 'worldgraph' ) );
		}

		$refresh_token = (string) ( $envelope['refresh_token'] ?? '' );
		$client_id     = (string) ( $envelope['client_id'] ?? '' );
		if ( ! self::valid_token( $refresh_token ) || ! self::valid_client_id( $client_id ) ) {
			return new WP_Error( 'worldgraph_oauth_reconnect_required', __( 'The provider authorization expired. Reconnect it from the Connection editor.', 'worldgraph' ) );
		}

		$lock = self::acquire_credential_lock( $connection_id, $provider, $profile );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			// Re-resolve only the latest stored value after acquiring the mutation lock.
			$latest_reference = Credential_Store::load_connection_secret( $connection_id, $field );
			if ( is_wp_error( $latest_reference ) ) {
				return $latest_reference;
			}
			$latest_reference = (string) $latest_reference;
			$latest_revision  = Credential_Store::connection_secret_revision( $connection_id, $field );
			if ( is_wp_error( $latest_revision ) ) {
				return $latest_revision;
			}
			$latest_external = str_starts_with( trim( $latest_reference ), 'env://' );
			$latest_resolved = Credential_Store::resolve_reference( $latest_reference );
			if ( is_wp_error( $latest_resolved ) ) {
				return $latest_resolved;
			}
			$latest_resolved = trim( (string) $latest_resolved );
			$latest_env      = self::credential_envelope( $latest_resolved, $provider, $config );
			if ( is_wp_error( $latest_env ) ) {
				return $latest_env;
			}
			$credential_changed = ! hash_equals( $initial_revision, $latest_revision );
			if ( is_array( $latest_env ) && self::envelope_is_fresh( $latest_env ) && ( ! $force_refresh || $credential_changed ) ) {
				return (string) $latest_env['access_token'];
			}
			if ( ! is_array( $latest_env ) ) {
				return self::valid_token( $latest_resolved ) && $credential_changed
					? $latest_resolved
					: new WP_Error( 'worldgraph_oauth_reconnect_required', __( 'The stored provider authorization changed while refresh was waiting. Retry with the current credential.', 'worldgraph' ) );
			}
			if ( $latest_external ) {
				return new WP_Error( 'worldgraph_oauth_external_rotation_required', __( 'The env:// OAuth credential must be refreshed by its external secret manager.', 'worldgraph' ) );
			}
			$refresh_token = (string) ( $latest_env['refresh_token'] ?? '' );
			$client_id     = (string) ( $latest_env['client_id'] ?? '' );
			if ( ! self::valid_token( $refresh_token ) || ! self::valid_client_id( $client_id ) ) {
				return new WP_Error( 'worldgraph_oauth_reconnect_required', __( 'The provider authorization expired. Reconnect it from the Connection editor.', 'worldgraph' ) );
			}

			$form = array_merge(
				(array) $config['token_parameters'],
				[
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'client_id'     => $client_id,
				]
			);
			if ( '' !== (string) $config['resource'] ) {
				$form['resource'] = (string) $config['resource'];
			}
			$tokens = self::token_request( $config, $form );
			if ( is_wp_error( $tokens ) ) {
				return new WP_Error( 'worldgraph_oauth_refresh_failed', __( 'The provider authorization could not be refreshed. Reconnect it from the Connection editor.', 'worldgraph' ) );
			}
			$updated = self::envelope_from_response( $tokens, $provider, $profile, $client_id, $config, $refresh_token );
			if ( is_wp_error( $updated ) || ! self::store_envelope( $connection_id, $config, $updated, $latest_revision ) ) {
				return new WP_Error( 'worldgraph_oauth_storage_failed', __( 'World Graph Studio could not securely store the refreshed authorization.', 'worldgraph' ) );
			}

			return (string) $updated['access_token'];
		} finally {
			self::release_lock( $lock );
		}
	}

	/** Resolve and validate a saved Connection plus its adapter OAuth contract. */
	private static function context_for_connection( int $connection_id, string $profile ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'publish' !== ( $connection['status_wp'] ?? '' ) || 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'worldgraph_oauth_connection_invalid', __( 'OAuth requires a published, enabled Connection.', 'worldgraph' ) );
		}
		$provider = sanitize_key( (string) ( $connection['provider_type'] ?? '' ) );
		$config   = self::config_for_provider( $provider, $profile );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return [ 'connection' => $connection, 'provider' => $provider, 'config' => $config ];
	}

	/** Resolve one trusted adapter manifest's normalized OAuth contract. */
	private static function config_for_provider( string $provider, string $profile ) {
		$provider = sanitize_key( $provider );
		$adapter  = Adapter_Registry::get( $provider );
		return is_array( $adapter )
			? self::normalize_config( $provider, $adapter, $profile )
			: new WP_Error( 'worldgraph_oauth_provider_invalid', __( 'The Connection provider is not registered.', 'worldgraph' ) );
	}

	/** Return normalized profile slugs declared by one adapter manifest. */
	private static function profile_names( array $manifest ): array {
		$oauth = is_array( $manifest['oauth'] ?? null ) ? $manifest['oauth'] : [];
		if ( empty( $oauth ) ) {
			return [];
		}
		if ( ! is_array( $oauth['profiles'] ?? null ) ) {
			return [ 'default' ];
		}
		$profiles = [];
		foreach ( array_keys( $oauth['profiles'] ) as $profile ) {
			$profile = sanitize_key( (string) $profile );
			if ( '' !== $profile ) {
				$profiles[] = $profile;
			}
		}
		return array_values( array_unique( $profiles ) );
	}

	/** Normalize and strictly validate one reusable manifest OAuth profile. */
	private static function normalize_config( string $provider, array $manifest, string $profile ) {
		$oauth   = is_array( $manifest['oauth'] ?? null ) ? $manifest['oauth'] : [];
		$profile = sanitize_key( $profile );
		if ( '' === $provider || empty( $oauth ) || '' === $profile ) {
			return new WP_Error( 'worldgraph_oauth_configuration_missing', __( 'This provider does not declare OAuth.', 'worldgraph' ) );
		}
		if ( is_array( $oauth['profiles'] ?? null ) ) {
			foreach ( $oauth['profiles'] as $declared_profile => $declared_config ) {
				if ( ! is_string( $declared_profile ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $declared_profile ) || ! is_array( $declared_config ) ) {
					return new WP_Error( 'worldgraph_oauth_profile_invalid', __( 'This provider declares an invalid OAuth profile.', 'worldgraph' ) );
				}
			}
			$raw = is_array( $oauth['profiles'][ $profile ] ?? null ) ? $oauth['profiles'][ $profile ] : [];
		} else {
			$raw = 'default' === $profile ? $oauth : [];
		}
		if ( empty( $raw ) ) {
			return new WP_Error( 'worldgraph_oauth_profile_invalid', __( 'This provider does not declare that OAuth profile.', 'worldgraph' ) );
		}

		$field = is_scalar( $raw['credential_field'] ?? null ) ? sanitize_key( (string) $raw['credential_field'] ) : '';
		if ( ! in_array( $field, Credential_Store::CONNECTION_FIELDS, true ) ) {
			return new WP_Error( 'worldgraph_oauth_credential_field_invalid', __( 'The provider OAuth credential field is invalid.', 'worldgraph' ) );
		}
		if ( is_array( $oauth['profiles'] ?? null ) ) {
			foreach ( $oauth['profiles'] as $declared_profile => $declared_config ) {
				if ( $profile === $declared_profile ) {
					continue;
				}
				$declared_field = is_scalar( $declared_config['credential_field'] ?? null ) ? sanitize_key( (string) $declared_config['credential_field'] ) : '';
				if ( $field === $declared_field ) {
					return new WP_Error( 'worldgraph_oauth_credential_field_conflict', __( 'OAuth profiles for one provider must use different credential fields.', 'worldgraph' ) );
				}
			}
		}
		$authorization_url      = is_scalar( $raw['authorization_endpoint'] ?? null ) ? (string) $raw['authorization_endpoint'] : '';
		$token_url              = is_scalar( $raw['token_endpoint'] ?? null ) ? (string) $raw['token_endpoint'] : '';
		$registration_url       = is_scalar( $raw['registration_endpoint'] ?? null ) ? (string) $raw['registration_endpoint'] : '';
		$authorization_endpoint = self::trusted_https_endpoint( $authorization_url );
		$token_endpoint         = self::trusted_https_endpoint( $token_url );
		$registration_endpoint  = '' === trim( $registration_url )
			? ''
			: self::trusted_https_endpoint( $registration_url );
		if ( is_wp_error( $authorization_endpoint ) || is_wp_error( $token_endpoint ) || is_wp_error( $registration_endpoint ) ) {
			return new WP_Error( 'worldgraph_oauth_endpoint_invalid', __( 'The provider OAuth endpoints must be fixed HTTPS URLs.', 'worldgraph' ) );
		}

		$resource = is_scalar( $raw['resource'] ?? null ) ? trim( (string) $raw['resource'] ) : '';
		if ( '' !== $resource ) {
			$resource = self::trusted_https_endpoint( $resource );
			if ( is_wp_error( $resource ) ) {
				return new WP_Error( 'worldgraph_oauth_resource_invalid', __( 'The provider OAuth resource must be a fixed HTTPS URL.', 'worldgraph' ) );
			}
		}
		$scopes = $raw['scopes'] ?? null;
		if ( ! is_array( $scopes ) || $scopes !== array_values( $scopes ) || empty( $scopes ) || count( $scopes ) > 30 ) {
			return new WP_Error( 'worldgraph_oauth_scopes_invalid', __( 'The provider OAuth scopes are invalid.', 'worldgraph' ) );
		}
		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) || strlen( $scope ) > 200 || ! preg_match( '#^[A-Za-z0-9._~:/-]+$#', $scope ) ) {
				return new WP_Error( 'worldgraph_oauth_scopes_invalid', __( 'The provider OAuth scopes are invalid.', 'worldgraph' ) );
			}
		}
		$unique_scopes = array_values( array_unique( $scopes ) );
		if ( count( $unique_scopes ) !== count( $scopes ) ) {
			return new WP_Error( 'worldgraph_oauth_scopes_invalid', __( 'The provider OAuth scopes are invalid.', 'worldgraph' ) );
		}
		$scopes = $unique_scopes;
		$auth_method = is_scalar( $raw['token_endpoint_auth_method'] ?? null ) ? (string) $raw['token_endpoint_auth_method'] : 'none';
		if ( 'none' !== $auth_method ) {
			return new WP_Error( 'worldgraph_oauth_auth_method_unsupported', __( 'This OAuth service currently supports public PKCE clients.', 'worldgraph' ) );
		}
		$client_id = is_scalar( $raw['client_id'] ?? null ) ? (string) $raw['client_id'] : '';
		if ( '' !== $client_id && ! self::valid_client_id( $client_id ) ) {
			return new WP_Error( 'worldgraph_oauth_client_invalid', __( 'The provider OAuth public client ID is invalid.', 'worldgraph' ) );
		}
		$client_id_from_filter = true === ( $raw['client_id_from_filter'] ?? false );
		if ( '' === $client_id && '' === (string) $registration_endpoint && ! $client_id_from_filter ) {
			return new WP_Error( 'worldgraph_oauth_client_missing', __( 'This OAuth profile must declare dynamic registration, a public client ID, or a deployment-supplied client ID.', 'worldgraph' ) );
		}

		foreach ( [ 'authorization_parameters', 'token_parameters', 'registration_parameters' ] as $parameter_group ) {
			$parameters = $raw[ $parameter_group ] ?? [];
			if ( ! self::static_parameters_are_valid( $parameters ) ) {
				return new WP_Error( 'worldgraph_oauth_static_parameters_invalid', __( 'This provider declares invalid OAuth static parameters.', 'worldgraph' ) );
			}
			if ( self::contains_confidential_client_parameter( $parameters ) ) {
				return new WP_Error( 'worldgraph_oauth_confidential_parameter_forbidden', __( 'Public-client OAuth profiles cannot declare confidential-client parameters.', 'worldgraph' ) );
			}
		}

		$label = substr( sanitize_text_field( (string) ( $raw['service_label'] ?? $manifest['label'] ?? $provider ) ), 0, 100 );
		return [
			'profile'                => $profile,
			'authorization_endpoint' => $authorization_endpoint,
			'token_endpoint'         => $token_endpoint,
			'registration_endpoint'  => $registration_endpoint,
			'resource'               => $resource,
			'scopes'                 => $scopes,
			'credential_field'       => $field,
			'client_id'              => $client_id,
			'client_id_from_filter'  => $client_id_from_filter,
			'client_name'            => substr( sanitize_text_field( (string) ( $raw['client_name'] ?? 'World Graph Studio' ) ), 0, 100 ),
			'service_label'          => $label,
			'admin_intro'            => substr( sanitize_text_field( (string) ( $raw['admin_intro'] ?? '' ) ), 0, 1000 ),
			'credential_help'        => substr( sanitize_text_field( (string) ( $raw['credential_help'] ?? '' ) ), 0, 1000 ),
			'usage_notice'           => substr( sanitize_text_field( (string) ( $raw['usage_notice'] ?? '' ) ), 0, 1000 ),
			'connect_label'          => substr( sanitize_text_field( (string) ( $raw['connect_label'] ?? sprintf( __( 'Connect %s', 'worldgraph' ), $label ) ) ), 0, 100 ),
			'disconnect_label'       => substr( sanitize_text_field( (string) ( $raw['disconnect_label'] ?? sprintf( __( 'Disconnect %s', 'worldgraph' ), $label ) ) ), 0, 100 ),
			'authorization_parameters' => self::static_parameters( $raw['authorization_parameters'] ?? [] ),
			'token_parameters'       => self::static_parameters( $raw['token_parameters'] ?? [] ),
			'registration_parameters' => self::static_parameters( $raw['registration_parameters'] ?? [] ),
		];
	}

	/** Retain bounded scalar manifest parameters; protocol-owned keys win later. */
	private static function static_parameters( $parameters ): array {
		if ( ! is_array( $parameters ) || count( $parameters ) > 30 ) {
			return [];
		}
		$safe = [];
		foreach ( $parameters as $key => $value ) {
			$key = (string) $key;
			if ( preg_match( '/^[A-Za-z][A-Za-z0-9._~-]{0,99}$/', $key ) && is_scalar( $value ) && strlen( (string) $value ) <= 2000 ) {
				$safe[ $key ] = $value;
			}
		}
		return $safe;
	}

	/** Whether an optional static-parameter map matches the portable manifest contract. */
	private static function static_parameters_are_valid( $parameters ): bool {
		if ( ! is_array( $parameters ) || count( $parameters ) > 30 ) {
			return false;
		}
		foreach ( $parameters as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9._~-]{0,99}$/', $key ) || ! is_scalar( $value ) || strlen( (string) $value ) > 2000 ) {
				return false;
			}
		}

		return true;
	}

	/** Reject static parameters that would silently turn PKCE into a confidential client. */
	private static function contains_confidential_client_parameter( $parameters ): bool {
		if ( ! is_array( $parameters ) ) {
			return false;
		}
		foreach ( array_keys( $parameters ) as $key ) {
			if ( in_array( strtolower( (string) $key ), [ 'client_secret', 'client_assertion', 'client_assertion_type' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	/** Validate a trusted manifest endpoint and prohibit embedded authority/query data. */
	private static function trusted_https_endpoint( string $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if (
				'' === $url
				|| strlen( $url ) > 2048
			|| ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| '' === (string) ( $parts['host'] ?? '' )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return new WP_Error( 'worldgraph_oauth_endpoint_invalid', __( 'The provider OAuth endpoint is invalid.', 'worldgraph' ) );
		}

		return esc_url_raw( $url );
	}

	/** Resolve a static/filter client or register a reusable public client. */
	private static function resolve_client_id( int $connection_id, string $provider, string $profile, array $config, string $redirect_uri ) {
		$meta_key     = self::client_id_meta( $provider, $profile );
		$binding_key  = $meta_key . '_binding';
		$binding_hash = self::client_registration_hash( $config, $redirect_uri );
		$client_id    = (string) $config['client_id'];
		$client_id = (string) apply_filters( 'worldgraph_connection_oauth_client_id', $client_id, $provider, $profile, $connection_id, $config );
		if ( self::valid_client_id( $client_id ) ) {
			return $client_id;
		}
		$client_id  = (string) get_post_meta( $connection_id, $meta_key, true );
		$saved_hash = (string) get_post_meta( $connection_id, $binding_key, true );
		if ( self::valid_client_id( $client_id ) && hash_equals( $binding_hash, $saved_hash ) ) {
			return $client_id;
		}
		if ( '' === (string) $config['registration_endpoint'] ) {
			return new WP_Error( 'worldgraph_oauth_client_missing', __( 'This provider OAuth connection requires a public client ID.', 'worldgraph' ) );
		}

		$client_id = self::register_client( $config, $redirect_uri );
		if ( is_wp_error( $client_id ) ) {
			return $client_id;
		}
		update_post_meta( $connection_id, $meta_key, $client_id );
		update_post_meta( $connection_id, $binding_key, $binding_hash );
		if (
			! hash_equals( $client_id, (string) get_post_meta( $connection_id, $meta_key, true ) )
			|| ! hash_equals( $binding_hash, (string) get_post_meta( $connection_id, $binding_key, true ) )
		) {
			return new WP_Error( 'worldgraph_oauth_client_storage_failed', __( 'WordPress could not persist the provider OAuth client registration.', 'worldgraph' ) );
		}
		return $client_id;
	}

	/** Register a public PKCE client through a manifest-declared DCR endpoint. */
	private static function register_client( array $config, string $redirect_uri ) {
		$payload = array_merge(
			(array) $config['registration_parameters'],
			[
				'client_name'                => (string) $config['client_name'],
				'redirect_uris'              => [ $redirect_uri ],
				'grant_types'                => [ 'authorization_code', 'refresh_token' ],
				'response_types'             => [ 'code' ],
				'token_endpoint_auth_method' => 'none',
				'scope'                      => implode( ' ', (array) $config['scopes'] ),
			]
		);
		$response = self::http_request(
			(string) $config['registration_endpoint'],
			[
				'method'  => 'POST',
				'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$payload = self::successful_json( $response, 'worldgraph_oauth_registration_failed' );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( array_key_exists( 'token_endpoint_auth_method', $payload ) && ! is_string( $payload['token_endpoint_auth_method'] ) ) {
			return new WP_Error( 'worldgraph_oauth_client_not_public', __( 'The provider did not register a public PKCE client.', 'worldgraph' ) );
		}
		$auth_method = is_string( $payload['token_endpoint_auth_method'] ?? null ) ? $payload['token_endpoint_auth_method'] : 'none';
		if ( array_key_exists( 'client_secret', $payload ) || 'none' !== $auth_method ) {
			return new WP_Error( 'worldgraph_oauth_client_not_public', __( 'The provider did not register a public PKCE client.', 'worldgraph' ) );
		}

		$client_id = is_string( $payload['client_id'] ?? null ) ? $payload['client_id'] : '';
		return self::valid_client_id( $client_id )
			? $client_id
			: new WP_Error( 'worldgraph_oauth_client_invalid', __( 'The provider returned an invalid OAuth client registration.', 'worldgraph' ) );
	}

	/** Send one form-encoded token request. */
	private static function token_request( array $config, array $form ) {
		$response = self::http_request(
			(string) $config['token_endpoint'],
			[
				'method'  => 'POST',
				'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => http_build_query( $form, '', '&', PHP_QUERY_RFC3986 ),
			]
		);
		return is_wp_error( $response ) ? $response : self::successful_json( $response, 'worldgraph_oauth_token_failed' );
	}

	/** Perform one bounded, non-redirecting request to a manifest-owned endpoint. */
	private static function http_request( string $url, array $args ) {
		if ( is_wp_error( self::trusted_https_endpoint( $url ) ) ) {
			return new WP_Error( 'worldgraph_oauth_endpoint_invalid', __( 'The provider OAuth endpoint is invalid.', 'worldgraph' ) );
		}
		$args['timeout']             = self::TIMEOUT;
		$args['redirection']         = 0;
		$args['limit_response_size'] = self::MAX_RESPONSE_BYTES;
		$response = wp_safe_remote_request( $url, $args );
		return is_wp_error( $response )
			? new WP_Error( 'worldgraph_oauth_unreachable', __( 'The provider OAuth service could not be reached.', 'worldgraph' ) )
			: $response;
	}

	/** Decode a bounded 2xx JSON object without retaining arbitrary failures. */
	private static function successful_json( array $response, string $error_code ) {
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 || strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error( $error_code, __( 'The provider OAuth service returned an unsuccessful response.', 'worldgraph' ), [ 'status' => $status ] );
		}
		$payload = json_decode( $body, true );
		return is_array( $payload )
			? $payload
			: new WP_Error( $error_code, __( 'The provider OAuth service returned an invalid response.', 'worldgraph' ), [ 'status' => $status ] );
	}

	/** Build one versioned, provider-bound credential envelope. */
	private static function envelope_from_response( array $tokens, string $provider, string $profile, string $client_id, array $config, string $fallback_refresh_token = '' ) {
		$access_token = is_string( $tokens['access_token'] ?? null ) ? $tokens['access_token'] : '';
		$token_type   = is_string( $tokens['token_type'] ?? null ) ? strtolower( $tokens['token_type'] ) : '';
		if ( array_key_exists( 'refresh_token', $tokens ) && ! is_string( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'worldgraph_oauth_token_invalid', __( 'The provider returned an invalid OAuth token.', 'worldgraph' ) );
		}
		$refresh_token = is_string( $tokens['refresh_token'] ?? null ) ? $tokens['refresh_token'] : '';
		if ( '' === $refresh_token ) {
			$refresh_token = $fallback_refresh_token;
		}
		if ( ! self::valid_token( $access_token ) || 'bearer' !== $token_type || ! self::valid_client_id( $client_id ) || ( '' !== $refresh_token && ! self::valid_token( $refresh_token ) ) ) {
			return new WP_Error( 'worldgraph_oauth_token_invalid', __( 'The provider returned an invalid OAuth token.', 'worldgraph' ) );
		}
		$has_expiry = array_key_exists( 'expires_in', $tokens );
		if (
			$has_expiry
			&& ! is_int( $tokens['expires_in'] )
			&& ! ( is_string( $tokens['expires_in'] ) && ctype_digit( $tokens['expires_in'] ) )
		) {
			return new WP_Error( 'worldgraph_oauth_token_invalid', __( 'The provider returned an invalid OAuth token expiry.', 'worldgraph' ) );
		}
		if ( $has_expiry && (int) $tokens['expires_in'] < 0 ) {
			return new WP_Error( 'worldgraph_oauth_token_invalid', __( 'The provider returned an invalid OAuth token expiry.', 'worldgraph' ) );
		}
		$expires_in = $has_expiry
			? max( 0, min( DAY_IN_SECONDS * 365, (int) $tokens['expires_in'] ) )
			: 0;

		if ( array_key_exists( 'scope', $tokens ) && ! is_string( $tokens['scope'] ) ) {
			return new WP_Error( 'worldgraph_oauth_token_invalid', __( 'The provider returned an invalid OAuth scope.', 'worldgraph' ) );
		}
		$scope_value = is_string( $tokens['scope'] ?? null ) ? $tokens['scope'] : implode( ' ', (array) $config['scopes'] );
		$scope = preg_split( '/\s+/', trim( sanitize_text_field( $scope_value ) ) );
		$scope = is_array( $scope ) ? array_values( array_filter( $scope ) ) : (array) $config['scopes'];
		if ( count( $scope ) > 30 ) {
			return new WP_Error( 'worldgraph_oauth_token_invalid', __( 'The provider returned invalid OAuth scopes.', 'worldgraph' ) );
		}
		foreach ( $scope as $scope_item ) {
			if ( ! is_string( $scope_item ) || strlen( $scope_item ) > 200 || ! preg_match( '#^[A-Za-z0-9._~:/-]+$#', $scope_item ) ) {
				return new WP_Error( 'worldgraph_oauth_token_invalid', __( 'The provider returned invalid OAuth scopes.', 'worldgraph' ) );
			}
		}
		return [
			'kind'               => 'worldgraph-oauth',
			'version'            => 1,
			'provider_type'      => $provider,
			'profile'            => $profile,
			'configuration_hash' => self::config_hash( $config ),
			'token_type'         => 'Bearer',
			'access_token'       => $access_token,
			'refresh_token'      => $refresh_token,
			'expires_at'         => $has_expiry ? time() + $expires_in : 0,
			'client_id'          => $client_id,
			'scope'              => $scope,
			'token_endpoint'     => (string) $config['token_endpoint'],
		];
	}

	/** Persist and verify one envelope through an unchanged protected field. */
	private static function store_envelope( int $connection_id, array $config, array $envelope, string $expected_revision ): bool {
		$encoded = wp_json_encode( $envelope );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::TOKEN_MAX_BYTES ) {
			return false;
		}
		$field = (string) $config['credential_field'];
		if ( ! Credential_Store::store_connection_secret_if_revision( $connection_id, $field, $expected_revision, $encoded ) ) {
			return false;
		}
		$stored = Credential_Store::load_connection_secret( $connection_id, $field );
		$stored = is_wp_error( $stored ) ? '' : trim( (string) $stored );
		$parsed = self::credential_envelope( $stored, (string) $envelope['provider_type'], $config );
		return is_array( $parsed )
			&& hash_equals( hash( 'sha256', (string) $envelope['access_token'] ), hash( 'sha256', (string) ( $parsed['access_token'] ?? '' ) ) );
	}

	/** Parse only this service's versioned, provider- and endpoint-bound envelope. */
	private static function credential_envelope( string $value, string $provider, array $config ) {
		if ( '' === $value || '{' !== substr( ltrim( $value ), 0, 1 ) ) {
			return null;
		}
		if ( strlen( $value ) > self::TOKEN_MAX_BYTES ) {
			return new WP_Error( 'worldgraph_oauth_envelope_invalid', __( 'The stored OAuth credential is invalid.', 'worldgraph' ) );
		}
		$decoded = json_decode( $value, true );
		if (
			! is_array( $decoded )
			|| 'worldgraph-oauth' !== (string) ( $decoded['kind'] ?? '' )
			|| 1 !== (int) ( $decoded['version'] ?? 0 )
			|| 'Bearer' !== ( $decoded['token_type'] ?? null )
			|| $provider !== (string) ( $decoded['provider_type'] ?? '' )
			|| (string) $config['profile'] !== (string) ( $decoded['profile'] ?? '' )
			|| ! hash_equals( self::config_hash( $config ), (string) ( $decoded['configuration_hash'] ?? '' ) )
			|| (string) $config['token_endpoint'] !== (string) ( $decoded['token_endpoint'] ?? '' )
		) {
			return new WP_Error( 'worldgraph_oauth_envelope_invalid', __( 'The stored OAuth credential is invalid or belongs to a different provider.', 'worldgraph' ) );
		}
		$expires_at    = $decoded['expires_at'] ?? null;
		$access_token  = is_string( $decoded['access_token'] ?? null ) ? $decoded['access_token'] : '';
		$refresh_token = is_string( $decoded['refresh_token'] ?? null ) ? $decoded['refresh_token'] : '';
		$client_id     = is_string( $decoded['client_id'] ?? null ) ? $decoded['client_id'] : '';
		$scope         = $decoded['scope'] ?? null;
		if (
			! is_int( $expires_at )
			|| $expires_at < 0
			|| ! self::valid_token( $access_token )
			|| ( '' !== $refresh_token && ! self::valid_token( $refresh_token ) )
			|| ! self::valid_client_id( $client_id )
			|| ! is_array( $scope )
			|| count( $scope ) > 30
		) {
			return new WP_Error( 'worldgraph_oauth_envelope_invalid', __( 'The stored OAuth credential is invalid or belongs to a different provider.', 'worldgraph' ) );
		}
		foreach ( $scope as $scope_item ) {
			if ( ! is_string( $scope_item ) || strlen( $scope_item ) > 200 || ! preg_match( '#^[A-Za-z0-9._~:/-]+$#', $scope_item ) ) {
				return new WP_Error( 'worldgraph_oauth_envelope_invalid', __( 'The stored OAuth credential is invalid or belongs to a different provider.', 'worldgraph' ) );
			}
		}

		return $decoded;
	}

	/** Classify a saved credential without contacting or mutating the provider. */
	private static function credential_status( string $provider, string $credential, array $config ): string {
		$external = str_starts_with( trim( $credential ), 'env://' );
		$resolved = Credential_Store::resolve_reference( $credential );
		if ( is_wp_error( $resolved ) ) {
			return 'not_connected';
		}
		$resolved = trim( (string) $resolved );
		$envelope = self::credential_envelope( $resolved, $provider, $config );
		if ( is_wp_error( $envelope ) ) {
			return 'not_connected';
		}
		if ( is_array( $envelope ) ) {
			if ( self::envelope_is_fresh( $envelope ) ) {
				return $external ? 'external_token' : 'connected';
			}
			return self::valid_token( (string) ( $envelope['refresh_token'] ?? '' ) )
				? ( $external ? 'external_token' : 'refresh_available' )
				: 'reconnect_required';
		}
		return self::valid_token( $resolved ) ? 'external_token' : 'not_connected';
	}

	/** Whether an envelope's access token is currently usable. */
	private static function envelope_is_fresh( array $envelope ): bool {
		$expires_at = absint( $envelope['expires_at'] ?? 0 );
		return self::valid_token( (string) ( $envelope['access_token'] ?? '' ) )
			&& ( 0 === $expires_at || $expires_at > time() + self::REFRESH_LEEWAY );
	}

	/** Validate opaque token shape without interpreting its contents. */
	private static function valid_token( string $token ): bool {
		$length = strlen( $token );
		return $length > 0 && $length <= self::TOKEN_MAX_BYTES && ! preg_match( '/[\x00-\x20\x7f]/', $token );
	}

	/** Validate an opaque public client identifier. */
	private static function valid_client_id( string $client_id ): bool {
		$length = strlen( $client_id );
		return $length > 0 && $length <= 512 && ! preg_match( '/[\x00-\x20\x7f]/', $client_id );
	}

	/** Build and validate the exact authenticated WordPress callback URI. */
	private static function redirect_uri() {
		$url      = admin_url( 'admin-post.php?action=worldgraph_connection_oauth_callback' );
		$parts    = wp_parse_url( $url );
		$host     = strtolower( (string) ( $parts['host'] ?? '' ) );
		$https    = 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$loopback = in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true );
		if ( ! is_array( $parts ) || ( ! $https && ! $loopback ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return new WP_Error( 'worldgraph_oauth_redirect_invalid', __( 'Provider OAuth requires an HTTPS WordPress admin URL (or a loopback development URL).', 'worldgraph' ) );
		}

		return $url;
	}

	/** Verify nonce, capability, post type, and declared OAuth support. */
	private static function verify_admin_action( int $connection_id, string $profile, string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'worldgraph' ) );
		}
		check_admin_referer( $nonce_action );
		if ( ! Connection_Repository::current_user_can_manage( $connection_id ) || is_wp_error( self::context_for_connection( $connection_id, $profile ) ) ) {
			wp_die( esc_html__( 'Select a published OAuth-enabled Connection first.', 'worldgraph' ) );
		}
	}

	/** Stable non-secret client ID metadata key for one provider. */
	private static function client_id_meta( string $provider, string $profile ): string {
		return '_worldgraph_oauth_client_id_' . sanitize_key( $provider ) . '_' . sanitize_key( $profile );
	}

	/** Bind a dynamically registered client to its callback and registration contract. */
	private static function client_registration_hash( array $config, string $redirect_uri ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				[
					$redirect_uri,
					$config['authorization_endpoint'],
					$config['token_endpoint'],
					$config['registration_endpoint'],
					$config['scopes'],
					$config['client_name'],
					$config['registration_parameters'],
					$config['client_id'],
					$config['client_id_from_filter'],
				]
			)
		);
	}

	/** Hash security-relevant configuration into the one-time callback state. */
	private static function config_hash( array $config ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				[
					$config['profile'],
					$config['authorization_endpoint'],
					$config['token_endpoint'],
					$config['registration_endpoint'],
					$config['resource'],
					$config['scopes'],
					$config['credential_field'],
					$config['client_id'],
					$config['client_id_from_filter'],
					$config['authorization_parameters'],
					$config['token_parameters'],
					$config['registration_parameters'],
				]
			)
		);
	}

	/** One-time transient name derived from the unguessable OAuth state. */
	private static function state_transient( string $state ): string {
		return 'worldgraph_connection_oauth_' . hash( 'sha256', $state );
	}

	/** Base64url without padding. */
	private static function base64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** Acquire a short atomic lock while consuming one OAuth state. */
	private static function acquire_state_lock( string $state ) {
		return self::acquire_option_lock(
			'_worldgraph_oauth_state_' . hash( 'sha256', $state ),
			self::STATE_LOCK_TTL,
			'worldgraph_oauth_state_locked',
			__( 'This OAuth response is already being processed.', 'worldgraph' )
		);
	}

	/** Acquire an expiring lock shared by all OAuth credential mutations. */
	private static function acquire_credential_lock( int $connection_id, string $provider, string $profile ) {
		$blog_id     = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$option_name = '_worldgraph_oauth_mutation_' . md5( $blog_id . ':' . $provider . ':' . $profile . ':' . $connection_id );
		return self::acquire_option_lock(
			$option_name,
			self::MUTATION_LOCK_TTL,
			'worldgraph_oauth_mutation_locked',
			__( 'Another request is updating this provider authorization. Retry shortly.', 'worldgraph' )
		);
	}

	/** Atomically acquire or take over one expired option-backed lock. */
	private static function acquire_option_lock( string $option_name, int $ttl, string $error_code, string $error_message ) {
		$token = ( time() + $ttl ) . ':' . wp_generate_uuid4();
		$lock  = [ 'option_name' => $option_name, 'token' => $token ];
		if ( add_option( $option_name, $token, '', 'no' ) ) {
			return $lock;
		}

		$current = (string) get_option( $option_name, '' );
		if ( (int) ( explode( ':', $current, 2 )[0] ?? 0 ) >= time() ) {
			return new WP_Error( $error_code, $error_message );
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return new WP_Error( $error_code, $error_message );
		}
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->options,
			[ 'option_value' => $token ],
			[ 'option_name' => $option_name, 'option_value' => $current ],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		if ( 1 !== (int) $updated ) {
			return new WP_Error( $error_code, $error_message );
		}
		wp_cache_delete( $option_name, 'options' );
		return $lock;
	}

	/** Release only the option-lock token owned by this request. */
	private static function release_lock( array $lock ): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return;
		}
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->options,
			[ 'option_name' => (string) $lock['option_name'], 'option_value' => (string) $lock['token'] ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( (string) $lock['option_name'], 'options' );
	}

	/** Redirect to the Connection editor with a bounded status key. */
	private static function redirect_to_connection( int $connection_id, string $profile, string $status ): void {
		$status = in_array( $status, [ 'connected', 'disconnected', 'denied', 'registration_failed', 'token_failed', 'storage_failed', 'busy' ], true ) ? $status : 'token_failed';
		$url    = add_query_arg(
			[ 'post' => $connection_id, 'action' => 'edit', 'worldgraph_connection_oauth' => $status, 'oauth_profile' => sanitize_key( $profile ) ],
			admin_url( 'post.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
