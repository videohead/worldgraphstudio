<?php
/**
 * Provider-neutral Connection health-test lifecycle.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-adapter-registry.php';

/**
 * Dispatches adapter health checks and records their normalized outcome.
 */
class Connection_Test_Service {

	/** Compatibility constant retained from the original Connection_Tester. */
	public const TIMEOUT = 30;

	/** Maximum human-readable test message length. */
	private const MAX_MESSAGE_LENGTH = 500;

	/** Maximum nesting depth retained in a health report. */
	private const MAX_HEALTH_DEPTH = 5;

	/** Maximum total array entries retained in a health report. */
	private const MAX_HEALTH_ITEMS = 100;

	/** Maximum length retained for an individual health string. */
	private const MAX_HEALTH_STRING_LENGTH = 500;

	/** Maximum encoded health report size stored or exposed. */
	private const MAX_HEALTH_BYTES = 8192;

	/**
	 * Test a saved Connection through its registered adapter callback.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array{success:bool,status:string,message:string,health:array}
	 */
	public static function test( int $connection_id ): array {
		$record = \WorldGraph\Utils\Connection_Repository::get( $connection_id );
		if ( null === $record ) {
			return [
				'success' => false,
				'status'  => 'error',
				'message' => 'Connection not found.',
				'health'  => [],
			];
		}
		if ( 'disabled' === sanitize_key( (string) ( $record['status'] ?? '' ) ) ) {
			return [
				'success' => false,
				'status'  => 'disabled',
				'message' => 'Enable this Connection before testing it.',
				'health'  => [],
			];
		}

		$provider_type = sanitize_key( (string) ( $record['provider_type'] ?? '' ) );
		if ( '' === $provider_type ) {
			return self::record_result( $connection_id, false, 'Connection has no provider type configured.', [] );
		}

		$adapter = Adapter_Registry::get( $provider_type );
		if ( null === $adapter ) {
			return self::record_result(
				$connection_id,
				false,
				sprintf( 'No Connection adapter is registered for provider: %s.', $provider_type ),
				[]
			);
		}

		try {
			$callback = Adapter_Registry::callback( $provider_type, 'test' );
		} catch ( \Throwable ) {
			return self::record_result(
				$connection_id,
				false,
				sprintf( 'The Connection test registered for provider %s could not be loaded.', $provider_type ),
				[]
			);
		}
		if ( null === $callback ) {
			$message = Adapter_Registry::supports( $provider_type, 'test' )
				? sprintf( 'The Connection test registered for provider %s could not be loaded.', $provider_type )
				: sprintf( 'No Connection test is registered for provider: %s.', $provider_type );

			return self::record_result( $connection_id, false, $message, [] );
		}

		try {
			$result = call_user_func( $callback, $connection_id, $record );
		} catch ( \Throwable ) {
			return self::record_result(
				$connection_id,
				false,
				sprintf( 'The %s Connection test could not be completed.', $provider_type ),
				[]
			);
		}

		if ( is_wp_error( $result ) ) {
			return self::record_result( $connection_id, false, $result->get_error_message(), [] );
		}
		if ( ! is_array( $result ) || ! array_key_exists( 'success', $result ) ) {
			return self::record_result(
				$connection_id,
				false,
				sprintf( 'The %s Connection test returned an invalid result.', $provider_type ),
				[]
			);
		}

		$success = ! empty( $result['success'] );
		$message = trim( (string) ( $result['message'] ?? '' ) );
		$health  = isset( $result['health'] ) && is_array( $result['health'] ) ? $result['health'] : [];
		if ( '' === $message ) {
			$message = $success
				? sprintf( 'Connected to %s.', $provider_type )
				: sprintf( 'The %s Connection test failed.', $provider_type );
		}

		return self::record_result( $connection_id, $success, $message, $health );
	}

	/**
	 * Persist the canonical outcome of a Connection test.
	 *
	 * @param int                  $connection_id Connection post ID.
	 * @param bool                 $success       Whether the health check passed.
	 * @param string               $message       Human-readable result message.
	 * @param array<string, mixed> $health        Non-secret health payload.
	 * @return array{success:bool,status:string,message:string,health:array}
	 */
	public static function record_result( int $connection_id, bool $success, string $message, array $health ): array {
		$status  = $success ? 'verified' : 'error';
		$message = self::normalize_message( $message );
		$health  = self::normalize_health( $health );

		\WorldGraph\Utils\worldgraph_update_field_value( $connection_id, 'status', $status );
		update_post_meta( $connection_id, 'last_validated_at', gmdate( 'Y-m-d H:i:s' ) );
		if ( empty( $health ) ) {
			delete_post_meta( $connection_id, 'last_health_report' );
		} else {
			update_post_meta( $connection_id, 'last_health_report', wp_json_encode( $health ) );
		}

		/**
		 * Fires after a connection test completes.
		 *
		 * @param int    $connection_id Connection post ID.
		 * @param bool   $success       Whether the test passed.
		 * @param string $message       Result message.
		 * @param array  $health        Bounded, non-sensitive health payload.
		 */
		do_action( 'worldgraph_conn_tested', $connection_id, $success, $message, $health );

		return [
			'success' => $success,
			'status'  => $status,
			'message' => $message,
			'health'  => $health,
		];
	}

	/** Sanitize and bound a provider-supplied result message. */
	private static function normalize_message( string $message ): string {
		$message = sanitize_text_field( $message );
		return self::truncate_string( $message, self::MAX_MESSAGE_LENGTH );
	}

	/**
	 * Recursively sanitize, redact, and bound provider-supplied health data.
	 *
	 * @param array<string|int, mixed> $health Raw provider health data.
	 * @return array<string|int, mixed>
	 */
	private static function normalize_health( array $health ): array {
		$remaining  = self::MAX_HEALTH_ITEMS;
		$normalized = self::normalize_health_value( $health, 0, $remaining );
		$normalized = is_array( $normalized ) ? $normalized : [];
		$encoded    = wp_json_encode( $normalized );

		if ( false === $encoded || strlen( $encoded ) > self::MAX_HEALTH_BYTES ) {
			return [ 'report_truncated' => true ];
		}

		return $normalized;
	}

	/**
	 * Normalize one health value while sharing a global item budget.
	 *
	 * @param mixed $value     Raw value.
	 * @param int   $depth     Current array depth.
	 * @param int   $remaining Remaining item budget, updated by reference.
	 * @return mixed
	 */
	private static function normalize_health_value( $value, int $depth, int &$remaining ) {
		if ( is_array( $value ) ) {
			if ( $depth >= self::MAX_HEALTH_DEPTH || $remaining <= 0 ) {
				return '[truncated]';
			}

			$normalized = [];
			foreach ( $value as $key => $item ) {
				if ( $remaining <= 0 ) {
					break;
				}

				$key = is_int( $key ) ? $key : sanitize_text_field( (string) $key );
				--$remaining;
				if ( ! is_int( $key ) && self::is_sensitive_health_key( $key ) ) {
					continue;
				}

				$normalized[ $key ] = self::normalize_health_value( $item, $depth + 1, $remaining );
			}

			return $normalized;
		}

		if ( is_string( $value ) ) {
			return self::truncate_string( sanitize_text_field( $value ), self::MAX_HEALTH_STRING_LENGTH );
		}
		if ( is_int( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : null;
		}

		return null;
	}

	/** Whether a health-report key may identify secret material. */
	private static function is_sensitive_health_key( string $key ): bool {
		return 1 === preg_match( '/(?:credential|token|secret|authorization|api[_\\-\\s]?key|password|cookie)/i', $key );
	}

	/** Truncate a string without requiring the mbstring extension. */
	private static function truncate_string( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}
}
