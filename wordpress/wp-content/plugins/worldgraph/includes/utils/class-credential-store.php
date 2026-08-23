<?php
/**
 * Encrypted storage for World Graph Studio provider credentials.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps provider credentials encrypted at rest while exposing plaintext only
 * to trusted server-side consumers.
 */
class Credential_Store {

	/** Prefix used to distinguish encrypted values from legacy plaintext. */
	private const PREFIX = 'worldgraph-encrypted:v1:aes-256-gcm:';

	/** Authenticated-data label bound to every ciphertext. */
	private const AAD = 'worldgraph-credential:v1';

	/** One-time migration version option. */
	private const MIGRATION_OPTION = 'worldgraph_credential_storage_version';

	/** Value rendered in password inputs instead of a stored credential. */
	public const MASK = '********';

	/** Connection fields that contain credentials. */
	public const CONNECTION_FIELDS = [
		'credential_reference',
		'mcp_credential_reference',
	];

	/** Scalar options that contain provider API keys. */
	private const SCALAR_OPTIONS = [
		'worldgraph_ai_api_key',
		'worldgraph_ai_image_api_key',
		'worldgraph_ai_fallback_api_key',
	];

	/** Option arrays and the credential key within each array. */
	private const ARRAY_OPTIONS = [
		'celtx_credentials'         => 'api_key',
		'worldgraph_headless_settings' => 'webhook_secret',
	];

	/** Whether an encryption or decryption problem occurred this request. */
	private static bool $storage_error = false;

	/** Prevent duplicate filters when activation initializes the plugin inline. */
	private static bool $initialized = false;

	/** Prevent recursion while a protected metadata write is re-issued. */
	private static bool $writing_connection_metadata = false;

	/** Register credential storage hooks. */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		foreach ( self::CONNECTION_FIELDS as $field_name ) {
			add_filter( 'acf/load_value/name=' . $field_name, [ __CLASS__, 'load_connection_value' ], 30, 3 );
			add_filter( 'acf/prepare_field/name=' . $field_name, [ __CLASS__, 'prepare_connection_field' ], 50 );
		}
		add_filter( 'add_post_metadata', [ __CLASS__, 'protect_added_connection_metadata' ], 10, 5 );
		add_filter( 'update_post_metadata', [ __CLASS__, 'protect_updated_connection_metadata' ], 10, 5 );

		foreach ( array_merge( self::SCALAR_OPTIONS, array_keys( self::ARRAY_OPTIONS ) ) as $option_name ) {
			add_filter( 'option_' . $option_name, [ __CLASS__, 'load_option_value' ], 10, 2 );
			add_filter( 'pre_update_option_' . $option_name, [ __CLASS__, 'prepare_option_value' ], 10, 3 );
		}

		add_action( 'admin_init', [ __CLASS__, 'maybe_migrate_plaintext' ], 5 );
		add_action( 'admin_notices', [ __CLASS__, 'render_storage_notice' ] );
	}

	/** Whether this host can encrypt provider credentials. */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& function_exists( 'random_bytes' )
			&& in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	/** Whether a value uses the World Graph Studio encrypted envelope. */
	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Encrypt one non-empty credential.
	 *
	 * @throws \RuntimeException When authenticated encryption is unavailable.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( self::is_encrypted( $plaintext ) ) {
			return $plaintext;
		}
		if ( ! self::is_available() ) {
			throw new \RuntimeException( 'Authenticated credential encryption is unavailable.' );
		}

		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			self::key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			self::AAD,
			16
		);
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			throw new \RuntimeException( 'Provider credential encryption failed.' );
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt one credential envelope.
	 *
	 * Legacy plaintext is returned unchanged so it can be migrated safely.
	 *
	 * @throws \RuntimeException When the envelope is malformed or cannot authenticate.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || ! self::is_encrypted( $stored ) ) {
			return $stored;
		}
		if ( ! self::is_available() ) {
			throw new \RuntimeException( 'Authenticated credential decryption is unavailable.' );
		}

		$encoded = substr( $stored, strlen( self::PREFIX ) );
		$payload = base64_decode( $encoded, true );
		if ( false === $payload || strlen( $payload ) < 29 ) {
			throw new \RuntimeException( 'The stored provider credential is malformed.' );
		}

		$iv         = substr( $payload, 0, 12 );
		$tag        = substr( $payload, 12, 16 );
		$ciphertext = substr( $payload, 28 );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			self::key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			self::AAD
		);
		if ( false === $plaintext ) {
			throw new \RuntimeException( 'The stored provider credential could not be authenticated.' );
		}

		return $plaintext;
	}

	/**
	 * Prepare a Connection credential for SCF/post-meta storage.
	 *
	 * A submitted mask means "keep the existing secret". Encryption failures
	 * preserve the previous database value and never write new plaintext.
	 */
	public static function prepare_connection_value( string $value, int $post_id, string $field_name ): string {
		$value = sanitize_text_field( $value );
		$old   = (string) get_post_meta( $post_id, $field_name, true );
		if ( self::MASK === $value ) {
			return $old;
		}
		if ( '' === $value || self::is_encrypted( $value ) ) {
			return $value;
		}

		try {
			return self::encrypt( $value );
		} catch ( \RuntimeException $exception ) {
			self::$storage_error = true;
			return $old;
		}
	}

	/** Encrypt a protected Connection field before a direct metadata insert. */
	public static function protect_added_connection_metadata( $check, int $post_id, string $meta_key, $meta_value, bool $unique ) {
		return self::protect_connection_metadata_write( $check, 'add', $post_id, $meta_key, $meta_value, $unique );
	}

	/** Encrypt a protected Connection field before a direct metadata update. */
	public static function protect_updated_connection_metadata( $check, int $post_id, string $meta_key, $meta_value, $previous_value ) {
		return self::protect_connection_metadata_write( $check, 'update', $post_id, $meta_key, $meta_value, $previous_value );
	}

	/** Decrypt a Connection credential as it enters the SCF value lifecycle. */
	public static function load_connection_value( $value, $post_id, array $field ) {
		if ( 0 !== strpos( (string) ( $field['key'] ?? '' ), 'field_worldgraph_conn_' ) || ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		try {
			return self::decrypt( $value );
		} catch ( \RuntimeException $exception ) {
			self::$storage_error = true;
			return '';
		}
	}

	/** Replace a loaded Connection secret with a fixed admin-form placeholder. */
	public static function prepare_connection_field( array $field ): array {
		if ( 0 !== strpos( (string) ( $field['key'] ?? '' ), 'field_worldgraph_conn_' ) ) {
			return $field;
		}
		if ( isset( $field['value'] ) && '' !== (string) $field['value'] ) {
			$field['value'] = self::MASK;
		}
		$field['type']         = 'password';
		$field['autocomplete'] = 'new-password';
		$field['instructions'] = trim( (string) ( $field['instructions'] ?? '' ) . ' ' . __( 'Stored encrypted. Leave the masked value unchanged to keep the current credential; clear it to remove the credential.', 'worldgraph' ) );
		return $field;
	}

	/** Return a mask for an already-loaded non-empty credential. */
	public static function masked_value( $value ): string {
		return '' === trim( (string) $value ) ? '' : self::MASK;
	}

	/**
	 * Convert a masked submitted option value back to its current plaintext for
	 * a trusted server-side copy into a Connection record.
	 */
	public static function resolve_masked_option_input( string $value, string $option_name ): string {
		return self::MASK === $value ? (string) get_option( $option_name, '' ) : $value;
	}

	/** Decrypt a protected option for server-side consumers. */
	public static function load_option_value( $value, string $option_name ) {
		try {
			if ( in_array( $option_name, self::SCALAR_OPTIONS, true ) && is_string( $value ) ) {
				return self::decrypt( $value );
			}
			if ( isset( self::ARRAY_OPTIONS[ $option_name ] ) && is_array( $value ) ) {
				$key = self::ARRAY_OPTIONS[ $option_name ];
				if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
					$value[ $key ] = self::decrypt( $value[ $key ] );
				}
			}
		} catch ( \RuntimeException $exception ) {
			self::$storage_error = true;
			if ( in_array( $option_name, self::SCALAR_OPTIONS, true ) ) {
				return '';
			}
			$value[ self::ARRAY_OPTIONS[ $option_name ] ] = '';
		}

		return $value;
	}

	/** Encrypt a protected option before WordPress writes it. */
	public static function prepare_option_value( $value, $old_value, string $option_name ) {
		return self::prepare_option( $value, $old_value, $option_name );
	}

	/** Migrate legacy plaintext credentials to authenticated ciphertext once. */
	public static function maybe_migrate_plaintext(): void {
		if ( ! current_user_can( 'manage_options' ) || '1' === (string) get_option( self::MIGRATION_OPTION, '' ) ) {
			return;
		}

		$connection_ids = get_posts(
			[
				'post_type'      => 'worldgraph_conn',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);
		foreach ( $connection_ids as $post_id ) {
			foreach ( self::CONNECTION_FIELDS as $field_name ) {
				$stored = (string) get_post_meta( (int) $post_id, $field_name, true );
				if ( '' !== $stored && ! self::is_encrypted( $stored ) ) {
					try {
						if ( false === update_post_meta( (int) $post_id, $field_name, self::encrypt( $stored ) ) ) {
							self::$storage_error = true;
						}
					} catch ( \RuntimeException $exception ) {
						self::$storage_error = true;
					}
				}
			}
		}

		foreach ( array_merge( self::SCALAR_OPTIONS, array_keys( self::ARRAY_OPTIONS ) ) as $option_name ) {
			$stored = self::raw_option( $option_name );
			if ( in_array( $option_name, self::SCALAR_OPTIONS, true ) && is_string( $stored ) && '' !== $stored && ! self::is_encrypted( $stored ) ) {
				if ( ! update_option( $option_name, $stored, false ) ) {
					self::$storage_error = true;
				}
			} elseif ( isset( self::ARRAY_OPTIONS[ $option_name ] ) && is_array( $stored ) ) {
				$key = self::ARRAY_OPTIONS[ $option_name ];
				if ( isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) && '' !== $stored[ $key ] && ! self::is_encrypted( $stored[ $key ] ) ) {
					if ( ! update_option( $option_name, $stored, false ) ) {
						self::$storage_error = true;
					}
				}
			}
		}

		if ( ! self::$storage_error ) {
			if ( ! update_option( self::MIGRATION_OPTION, '1', false ) ) {
				self::$storage_error = true;
			}
		}
	}

	/** Warn administrators without exposing any credential material. */
	public static function render_storage_notice(): void {
		if ( ! self::$storage_error || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error"><p>
			<?php esc_html_e( 'World Graph Studio could not encrypt or decrypt one or more provider credentials. Confirm that PHP OpenSSL with AES-256-GCM is available and that the WordPress authentication salts have not changed, then re-enter affected credentials.', 'worldgraph' ); ?>
		</p></div>
		<?php
	}

	/** Prepare one scalar or array option without ever storing new plaintext. */
	private static function prepare_option( $value, $old_value, string $option_name ) {
		try {
			if ( in_array( $option_name, self::SCALAR_OPTIONS, true ) ) {
				$value = sanitize_text_field( (string) $value );
				if ( self::MASK === $value ) {
					$value = (string) $old_value;
				}
				return '' === $value || self::is_encrypted( $value ) ? $value : self::encrypt( $value );
			}

			if ( isset( self::ARRAY_OPTIONS[ $option_name ] ) && is_array( $value ) ) {
				$key      = self::ARRAY_OPTIONS[ $option_name ];
				$old      = is_array( $old_value ) ? (string) ( $old_value[ $key ] ?? '' ) : '';
				$submitted = sanitize_text_field( (string) ( $value[ $key ] ?? '' ) );
				if ( self::MASK === $submitted ) {
					$submitted = $old;
				}
				$value[ $key ] = '' === $submitted || self::is_encrypted( $submitted ) ? $submitted : self::encrypt( $submitted );
			}
		} catch ( \RuntimeException $exception ) {
			self::$storage_error = true;
			return $old_value;
		}

		return $value;
	}

	/**
	 * Protect Connection credentials even when a caller bypasses the SCF API.
	 *
	 * Returning a non-null value short-circuits the original metadata operation;
	 * the guarded recursive call writes only the encrypted replacement.
	 */
	private static function protect_connection_metadata_write( $check, string $operation, int $post_id, string $meta_key, $meta_value, $extra ) {
		if (
			null !== $check
			|| self::$writing_connection_metadata
			|| ! in_array( $meta_key, self::CONNECTION_FIELDS, true )
			|| 'worldgraph_conn' !== get_post_type( $post_id )
		) {
			return $check;
		}

		if ( ! is_scalar( $meta_value ) && null !== $meta_value ) {
			self::$storage_error = true;
			return false;
		}

		$value = (string) $meta_value;
		if ( '' === $value || self::is_encrypted( $value ) ) {
			return $check;
		}

		$prepared = self::prepare_connection_value( $value, $post_id, $meta_key );
		if ( '' === $prepared || ! self::is_encrypted( $prepared ) ) {
			self::$storage_error = true;
			return false;
		}

		self::$writing_connection_metadata = true;
		try {
			if ( 'add' === $operation ) {
				return add_post_meta( $post_id, $meta_key, $prepared, (bool) $extra );
			}

			return update_post_meta( $post_id, $meta_key, $prepared, $extra );
		} finally {
			self::$writing_connection_metadata = false;
		}
	}

	/** Read an option without applying this class's decryption filter. */
	private static function raw_option( string $option_name ) {
		remove_filter( 'option_' . $option_name, [ __CLASS__, 'load_option_value' ], 10 );
		$value = get_option( $option_name, null );
		add_filter( 'option_' . $option_name, [ __CLASS__, 'load_option_value' ], 10, 2 );
		return $value;
	}

	/** Derive a binary encryption key from installation-specific WP salts. */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|worldgraph-credentials-v1', true );
	}
}
