<?php
/**
 * Midjourney-API.com asynchronous REST generation client.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Midjourney-API.com REST API adapter. */
class Midjourney_API {

	/** Documented production API origin. */
	const ENDPOINT = 'https://api.midjourney-api.com';

	/** HTTP timeout for bounded provider JSON requests. */
	const TIMEOUT = 60;

	/** Maximum JSON response body retained in memory. */
	const MAX_RESPONSE_BYTES = 1048576;

	/** Maximum prompt size sent to the provider. */
	const MAX_PROMPT_BYTES = 10000;

	/** Maximum opaque task ID size accepted from the provider. */
	const MAX_JOB_ID_BYTES = 256;

	/** Maximum number of status records accepted in one response. */
	const MAX_STATUS_ITEMS = 50;

	/** Maximum image-list size accepted in one task record. */
	const MAX_OUTPUT_URLS = 32;

	/** Maximum provider-returned media URL size. */
	const MAX_MEDIA_URL_BYTES = 8192;

	/** Non-destructive task lookup used to verify unsaved credentials. */
	const HEALTH_TASK_ID = 'worldgraph-connection-test';

	/** Documented generation speed modes. */
	const MODES = [ 'fast', 'relaxed' ];

	/** The only reviewed REST Template reference. */
	const TEMPLATE = 'api:imagine';

	/** Reviewed REST operations exposed to Generation Templates. */
	const OPERATIONS = [
		self::TEMPLATE => [
			'path'       => '/midjourney/v1/submit-jobs',
			'parameters' => [ 'mode', 'timeout' ],
			'kind'       => 'image',
		],
	];

	/**
	 * Test unsaved credentials through the documented read-only status route.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function test_configuration( string $endpoint, string $credential_reference ) {
		$base = self::validated_endpoint( $endpoint );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$headers = self::authorization_headers( $credential_reference );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$result = self::json_request(
			$base . '/midjourney/v1/job-status',
			$headers,
			[ 'taskIds' => [ self::HEALTH_TASK_ID ] ]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = self::status_items( $result );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		return [
			'authenticated' => true,
			'transport'     => 'api',
		];
	}

	/**
	 * Submit one reviewed imagine operation.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run_template( string $template, string $prompt, array $parameters, int $connection_id = 0 ) {
		$reference = trim( $template );
		$operation = self::OPERATIONS[ $reference ] ?? null;
		if ( ! is_array( $operation ) ) {
			return new WP_Error( 'midjourney_api_template_invalid', __( 'That Midjourney API Template is not supported.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		if ( ! self::operation_is_allowed( $connection, $reference ) ) {
			return new WP_Error( 'midjourney_api_template_invalid', __( 'That Midjourney API Template is not allowed by the selected Connection.', 'worldgraph' ) );
		}

		$text = trim( wp_strip_all_tags( $prompt ) );
		if ( '' === $text ) {
			return new WP_Error( 'midjourney_api_prompt_missing', __( 'Enter a Midjourney generation prompt.', 'worldgraph' ) );
		}
		if ( strlen( $text ) > self::MAX_PROMPT_BYTES ) {
			return new WP_Error( 'midjourney_api_prompt_too_long', __( 'The Midjourney generation prompt is too long.', 'worldgraph' ) );
		}

		$normalized_parameters = self::normalize_parameters( $parameters );
		if ( is_wp_error( $normalized_parameters ) ) {
			return $normalized_parameters;
		}

		$base = self::validated_endpoint( (string) ( $connection['endpoint_url'] ?? '' ) );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$headers = self::authorization_headers( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		// Deliberately omit hookUrl: WordPress owns bounded polling and media import.
		$body = array_merge( [ 'prompt' => $text ], $normalized_parameters );
		$result = self::json_request( $base . (string) $operation['path'], $headers, $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data   = $result['data'] ?? null;
		$job_id = is_array( $data ) && is_scalar( $data['taskId'] ?? null ) ? trim( (string) $data['taskId'] ) : '';
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return new WP_Error( 'midjourney_api_invalid_response', __( 'Midjourney API accepted the request without returning a valid task ID.', 'worldgraph' ) );
		}

		return [
			'job_id'          => $job_id,
			'status'          => 'submitted',
			'provider_status' => '0',
			'transport'       => 'api',
		];
	}

	/** Poll one asynchronous Midjourney task through the batch status route. */
	public static function get_job_status( string $job_id, int $connection_id = 0, string $template = '' ) {
		$job_id = trim( $job_id );
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return new WP_Error( 'midjourney_api_job_id_invalid', __( 'The Midjourney task ID is missing or invalid.', 'worldgraph' ) );
		}

		$reference = trim( $template );
		if ( '' !== $reference && ! isset( self::OPERATIONS[ $reference ] ) ) {
			return new WP_Error( 'midjourney_api_template_invalid', __( 'That Midjourney API Template is not supported.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		if ( '' !== $reference && ! self::operation_is_allowed( $connection, $reference ) ) {
			return new WP_Error( 'midjourney_api_template_invalid', __( 'That Midjourney API Template is not allowed by the selected Connection.', 'worldgraph' ) );
		}

		$base = self::validated_endpoint( (string) ( $connection['endpoint_url'] ?? '' ) );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$headers = self::authorization_headers( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$result = self::json_request(
			$base . '/midjourney/v1/job-status',
			$headers,
			[ 'taskIds' => [ $job_id ] ]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = self::status_items( $result );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$record = null;
		foreach ( $items as $item ) {
			$candidate_id = is_scalar( $item['taskId'] ?? null ) ? trim( (string) $item['taskId'] ) : '';
			if ( hash_equals( $job_id, $candidate_id ) ) {
				$record = $item;
				break;
			}
		}
		if ( ! is_array( $record ) ) {
			return new WP_Error( 'midjourney_api_job_not_found', __( 'Midjourney API did not return the requested task.', 'worldgraph' ) );
		}

		$validated = self::validate_status_record( $record );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$normalized = self::normalize_result( $record, $job_id );
		if ( 'completed' === ( $normalized['status'] ?? '' ) && empty( $normalized['output_media'] ) ) {
			return new WP_Error( 'midjourney_api_output_missing', __( 'Midjourney API reported completion without a valid HTTPS image output.', 'worldgraph' ) );
		}

		return $normalized;
	}

	/** Validate and copy only reviewed imagine parameters. */
	public static function normalize_parameters( array $parameters ) {
		$mode = array_key_exists( 'mode', $parameters ) ? trim( (string) $parameters['mode'] ) : 'fast';
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new WP_Error( 'midjourney_api_parameter_invalid', __( 'Midjourney mode must be fast or relaxed.', 'worldgraph' ) );
		}

		$timeout_value = $parameters['timeout'] ?? 600;
		$timeout       = self::integer_value( $timeout_value );
		if ( null === $timeout || $timeout < 300 || $timeout > 1200 ) {
			return new WP_Error( 'midjourney_api_parameter_invalid', __( 'Midjourney timeout must be a whole number from 300 through 1200 seconds.', 'worldgraph' ) );
		}

		return [
			'mode'    => $mode,
			'timeout' => $timeout,
		];
	}

	/** Whether a reviewed API operation is permitted by Model Access. */
	public static function operation_is_allowed( array $connection, string $reference ): bool {
		$reference = trim( $reference );
		if ( ! isset( self::OPERATIONS[ $reference ] ) ) {
			return false;
		}

		$raw = $connection['model_access'] ?? '';
		if ( is_array( $raw ) ) {
			$allowed = $raw;
		} else {
			$raw = trim( (string) $raw );
			if ( '' === $raw ) {
				return true;
			}
			if ( strlen( $raw ) > self::MAX_RESPONSE_BYTES ) {
				return false;
			}
			$allowed = json_decode( $raw, true );
		}

		if ( ! is_array( $allowed ) || ! self::is_list( $allowed ) || count( $allowed ) > 100 ) {
			return false;
		}
		foreach ( $allowed as $candidate ) {
			if ( is_string( $candidate ) && $reference === $candidate ) {
				return true;
			}
		}

		return false;
	}

	/** Map the documented numeric task states to generation-worker states. */
	public static function normalize_status( $status ): string {
		$status = self::integer_value( $status );
		if ( 1 === $status ) {
			return 'completed';
		}
		if ( 2 === $status ) {
			return 'failed';
		}

		return 'submitted';
	}

	/** Map one task record and every valid final image URL. */
	public static function normalize_result( array $payload, string $fallback_job_id = '' ): array {
		$payload_job_id = is_scalar( $payload['taskId'] ?? null ) ? trim( (string) $payload['taskId'] ) : '';
		$job_id         = self::is_valid_job_id( $payload_job_id ) ? $payload_job_id : trim( $fallback_job_id );
		$provider_state = self::integer_value( $payload['status'] ?? null );
		$status         = self::normalize_status( $provider_state );
		$result         = [
			'job_id'          => self::is_valid_job_id( $job_id ) ? $job_id : '',
			'status'          => $status,
			'provider_status' => null === $provider_state ? '' : (string) $provider_state,
			'transport'       => 'api',
		];

		if ( 'failed' === $status ) {
			$result['error'] = self::failure_message( $payload );
		}

		if ( 'completed' === $status ) {
			$output_media = [];
			self::append_output( $output_media, $payload['image'] ?? null );
			$images = is_array( $payload['images'] ?? null ) ? $payload['images'] : [];
			foreach ( array_slice( $images, 0, self::MAX_OUTPUT_URLS ) as $item ) {
				self::append_output( $output_media, $item );
			}
			if ( ! empty( $output_media ) ) {
				$result['output_media'] = array_values( self::unique_media( $output_media ) );
			}
		}

		return $result;
	}

	/** Whether a task ID matches the documented opaque URL-safe shape. */
	public static function is_valid_job_id( string $job_id ): bool {
		$job_id = trim( $job_id );
		return '' !== $job_id
			&& strlen( $job_id ) <= self::MAX_JOB_ID_BYTES
			&& 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $job_id );
	}

	/** Resolve and validate one saved Midjourney Connection. */
	private static function connection( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'midjourney' !== ( $connection['provider_type'] ?? '' ) ) {
			return new WP_Error( 'midjourney_api_connection_invalid', __( 'Select a Midjourney Connection first.', 'worldgraph' ) );
		}
		if ( isset( $connection['status_wp'] ) && 'publish' !== (string) $connection['status_wp'] ) {
			return new WP_Error( 'midjourney_api_connection_unpublished', __( 'The selected Midjourney Connection is not published.', 'worldgraph' ) );
		}

		$status = (string) ( $connection['status'] ?? '' );
		if ( 'disabled' === $status ) {
			return new WP_Error( 'midjourney_api_connection_disabled', __( 'The selected Midjourney Connection is disabled.', 'worldgraph' ) );
		}
		if ( '' !== $status && ! in_array( $status, [ 'unverified', 'verified', 'error' ], true ) ) {
			return new WP_Error( 'midjourney_api_connection_invalid', __( 'The selected Midjourney Connection has an invalid status.', 'worldgraph' ) );
		}

		return $connection;
	}

	/** Normalize and validate the fixed Midjourney API origin. */
	private static function validated_endpoint( string $endpoint ) {
		$endpoint = untrailingslashit( trim( $endpoint ?: self::ENDPOINT ) );
		$parts    = wp_parse_url( $endpoint );
		$path     = is_array( $parts ) ? rtrim( (string) ( $parts['path'] ?? '' ), '/' ) : '';
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| 'api.midjourney-api.com' !== strtolower( (string) ( $parts['host'] ?? '' ) )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| '' !== $path
		) {
			return new WP_Error( 'midjourney_api_endpoint_invalid', __( 'Use the documented Midjourney API endpoint: https://api.midjourney-api.com.', 'worldgraph' ) );
		}

		return self::ENDPOINT;
	}

	/** Resolve the API key and build fixed JSON request headers. */
	private static function authorization_headers( string $credential_reference ) {
		$credential = self::resolve_credential( $credential_reference );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		if ( '' === $credential ) {
			return new WP_Error( 'midjourney_api_credential_missing', __( 'Set a Midjourney API key or env://MIDJOURNEY_API_KEY reference on this Connection.', 'worldgraph' ) );
		}
		if ( strlen( $credential ) > 4096 || preg_match( '/[\x00-\x1F\x7F]/', $credential ) ) {
			return new WP_Error( 'midjourney_api_credential_invalid', __( 'The Midjourney API key is invalid.', 'worldgraph' ) );
		}

		return [
			'Accept'       => 'application/json',
			'API-KEY'      => $credential,
			'Content-Type' => 'application/json',
		];
	}

	/** Resolve a literal secret or strict env:// reference without exposing it. */
	private static function resolve_credential( string $reference ) {
		if ( class_exists( Credential_Store::class ) ) {
			$resolved = Credential_Store::resolve_reference( $reference );
			if ( is_wp_error( $resolved ) ) {
				return new WP_Error( 'midjourney_api_credential_invalid', __( 'The Midjourney API credential reference could not be resolved.', 'worldgraph' ) );
			}
			return trim( (string) $resolved );
		}

		$reference = trim( $reference );
		if ( ! str_starts_with( $reference, 'env://' ) ) {
			return $reference;
		}
		$name = substr( $reference, 6 );
		if ( ! preg_match( '/^[A-Z_][A-Z0-9_]*$/', $name ) ) {
			return new WP_Error( 'midjourney_api_credential_invalid', __( 'The Midjourney API env:// reference is invalid.', 'worldgraph' ) );
		}
		$value = getenv( $name );
		if ( false === $value || '' === trim( (string) $value ) ) {
			return new WP_Error( 'midjourney_api_credential_missing', __( 'The Midjourney API environment credential is unavailable.', 'worldgraph' ) );
		}

		return trim( (string) $value );
	}

	/** Send one bounded JSON POST to the fixed provider origin. */
	private static function json_request( string $url, array $headers, array $body ) {
		$encoded = wp_json_encode( $body );
		if ( ! is_string( $encoded ) ) {
			return new WP_Error( 'midjourney_api_request_invalid', __( 'The Midjourney API request could not be encoded safely.', 'worldgraph' ) );
		}

		$response = wp_safe_remote_request(
			$url,
			[
				'method'              => 'POST',
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => $headers,
				'body'                => $encoded,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'midjourney_api_unreachable', __( 'Midjourney API could not be reached.', 'worldgraph' ) );
		}

		$http_status = (int) wp_remote_retrieve_response_code( $response );
		$decoded     = self::decode_json_body( $response );
		if ( $http_status < 200 || $http_status >= 300 ) {
			return self::request_error( $decoded, $http_status );
		}
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'midjourney_api_invalid_response', __( 'Midjourney API returned an invalid or oversized JSON response.', 'worldgraph' ) );
		}

		$provider_code = self::envelope_code( $decoded );
		if ( null !== $provider_code && 0 !== $provider_code ) {
			return self::request_error( $decoded, $http_status, $provider_code );
		}
		if ( array_key_exists( 'error', $decoded ) && null === $provider_code ) {
			return self::request_error( $decoded, $http_status );
		}

		return $decoded;
	}

	/** Decode one already-bounded WordPress HTTP response body. */
	private static function decode_json_body( $response ): ?array {
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return null;
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/** Build a stable provider error without returning raw responses or secrets. */
	private static function request_error( ?array $decoded, int $http_status, ?int $provider_code = null ): WP_Error {
		if ( null === $provider_code && is_array( $decoded ) ) {
			$provider_code = self::envelope_code( $decoded );
		}
		$effective_code = null !== $provider_code ? $provider_code : $http_status;

		if ( in_array( $http_status, [ 401, 403 ], true ) ) {
			$code    = 'midjourney_api_unauthorized';
			$message = __( 'Midjourney API rejected the API key.', 'worldgraph' );
		} elseif ( 1001 === $effective_code ) {
			$code    = 'midjourney_api_quota_exhausted';
			$message = __( 'The Midjourney API account has insufficient quota.', 'worldgraph' );
		} elseif ( 1002 === $effective_code ) {
			$code    = 'midjourney_api_concurrency_limit';
			$message = __( 'The Midjourney API account reached its concurrent-job limit.', 'worldgraph' );
		} elseif ( 429 === $http_status ) {
			$code    = 'midjourney_api_rate_limited';
			$message = __( 'Midjourney API temporarily limited the request rate.', 'worldgraph' );
		} elseif ( 404 === $http_status ) {
			$code    = 'midjourney_api_not_found';
			$message = __( 'The requested Midjourney API route was not found.', 'worldgraph' );
		} else {
			$code = 'midjourney_api_request_failed';
			$message = sprintf(
				/* translators: %d: provider HTTP or business status code. */
				__( 'Midjourney API returned status %d.', 'worldgraph' ),
				$effective_code
			);
		}

		$data = [ 'status' => $http_status ];
		if ( null !== $provider_code ) {
			$data['provider_status'] = $provider_code;
		}
		return new WP_Error( $code, $message, $data );
	}

	/** Extract a numeric API-envelope status or error code. */
	private static function envelope_code( array $decoded ): ?int {
		if ( array_key_exists( 'status', $decoded ) ) {
			return self::integer_value( $decoded['status'] );
		}
		if ( array_key_exists( 'error', $decoded ) && ! is_array( $decoded['error'] ) ) {
			return self::integer_value( $decoded['error'] );
		}

		return null;
	}

	/** Validate and return a bounded list from the provider status envelope. */
	private static function status_items( array $result ) {
		$items = $result['data'] ?? null;
		if ( ! is_array( $items ) || ! self::is_list( $items ) || count( $items ) > self::MAX_STATUS_ITEMS ) {
			return new WP_Error( 'midjourney_api_invalid_response', __( 'Midjourney API returned an invalid task-status list.', 'worldgraph' ) );
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'midjourney_api_invalid_response', __( 'Midjourney API returned an invalid task-status record.', 'worldgraph' ) );
			}
		}

		return $items;
	}

	/** Validate one selected task record before normalizing it. */
	private static function validate_status_record( array $record ) {
		$status = self::integer_value( $record['status'] ?? null );
		if ( ! in_array( $status, [ 0, 1, 2 ], true ) ) {
			return new WP_Error( 'midjourney_api_invalid_response', __( 'Midjourney API returned an unknown task status.', 'worldgraph' ) );
		}
		if ( isset( $record['images'] ) && ( ! is_array( $record['images'] ) || count( $record['images'] ) > self::MAX_OUTPUT_URLS ) ) {
			return new WP_Error( 'midjourney_api_invalid_response', __( 'Midjourney API returned an invalid or oversized image list.', 'worldgraph' ) );
		}

		return true;
	}

	/** Return one strict integer value without truncating floats or junk strings. */
	private static function integer_value( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?[0-9]+$/', $value ) ) {
			return (int) $value;
		}

		return null;
	}

	/** Return a bounded task failure message that cannot contain request secrets. */
	private static function failure_message( array $payload ): string {
		$message = $payload['failReason'] ?? $payload['message'] ?? $payload['error'] ?? '';
		if ( is_array( $message ) ) {
			$message = $message['message'] ?? '';
		}
		if ( is_scalar( $message ) && '' !== trim( (string) $message ) ) {
			return substr( sanitize_text_field( (string) $message ), 0, 500 );
		}

		return __( 'Midjourney generation failed.', 'worldgraph' );
	}

	/** Whether an output URL is HTTPS and passes WordPress SSRF validation. */
	private static function is_safe_https_url( string $url ): bool {
		if ( '' === $url || strlen( $url ) > self::MAX_MEDIA_URL_BYTES ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& (bool) wp_http_validate_url( $url );
	}

	/** Add one typed image result when its URL is safe. */
	private static function append_output( array &$output_media, $item ): void {
		$url = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : ( is_string( $item ) ? $item : '' );
		$url = esc_url_raw( trim( $url ) );
		if ( self::is_safe_https_url( $url ) ) {
			$output_media[] = [ 'kind' => 'image', 'url' => $url ];
		}
	}

	/** De-duplicate output records without dropping distinct images. */
	private static function unique_media( array $items ): array {
		$unique = [];
		foreach ( $items as $item ) {
			$url = (string) ( $item['url'] ?? '' );
			$unique[ $url ] = $item;
		}

		return $unique;
	}

	/** Whether an array is a zero-based list on all supported PHP versions. */
	private static function is_list( array $value ): bool {
		return $value === array_values( $value );
	}
}
