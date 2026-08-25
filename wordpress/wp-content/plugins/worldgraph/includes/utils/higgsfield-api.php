<?php
/**
 * Higgsfield asynchronous REST generation client.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Higgsfield REST API adapter. */
class Higgsfield_API {

	/** Documented production API origin. */
	const ENDPOINT = 'https://platform.higgsfield.ai';

	/** HTTP timeout for provider JSON requests. */
	const TIMEOUT = 60;

	/** HTTP timeout for presigned media uploads. */
	const UPLOAD_TIMEOUT = 120;

	/** Maximum JSON response body retained in memory. */
	const MAX_RESPONSE_BYTES = 1048576;

	/** Maximum media attachment accepted by the bounded upload path. */
	const MAX_UPLOAD_BYTES = 52428800;

	/** A guaranteed-invalid request ID used for a non-destructive auth check. */
	const HEALTH_REQUEST_ID = '00000000-0000-0000-0000-000000000000';

	/** Content types documented by Higgsfield's presigned upload flow. */
	const UPLOAD_CONTENT_TYPES = [
		'image/jpeg',
		'image/jpg',
		'image/png',
		'image/webp',
		'image/gif',
		'audio/wav',
		'audio/x-wav',
		'video/mp4',
	];

	/**
	 * Reviewed model operations exposed as executable Templates.
	 *
	 * Higgsfield's narrative guides document these operations. Their complete
	 * model schemas remain provider-owned and can change independently, so the
	 * client sends only this deliberately small parameter subset.
	 */
	const OPERATIONS = [
		'api:higgsfield-ai/soul/standard' => [
			'path'       => '/higgsfield-ai/soul/standard',
			'parameters' => [ 'num_images', 'resolution', 'aspect_ratio' ],
			'media'      => [],
			'kind'       => 'image',
		],
		'api:higgsfield-ai/dop/standard' => [
			'path'       => '/higgsfield-ai/dop/standard',
			'parameters' => [ 'seed', 'end_image_url', 'enhance_prompt' ],
			'media'      => [ 'image' => 'image_url', 'end_frame' => 'end_image_url' ],
			'kind'       => 'video',
		],
		'api:kling-video/v2.1/pro/image-to-video' => [
			'path'       => '/kling-video/v2.1/pro/image-to-video',
			'parameters' => [ 'duration', 'cfg_scale', 'negative_prompt' ],
			'media'      => [ 'image' => 'image_url' ],
			'kind'       => 'video',
		],
	];

	/**
	 * Test unsaved credentials against a stable, non-destructive status route.
	 *
	 * An authenticated lookup of the all-zero UUID returns 404. A 401 proves the
	 * combined key ID/secret is invalid, while no generation is submitted.
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

		$response = wp_safe_remote_get(
			$base . '/requests/' . self::HEALTH_REQUEST_ID . '/status',
			[
				'timeout'             => 30,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => $headers,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'higgsfield_api_unreachable', __( 'Higgsfield API could not be reached.', 'worldgraph' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return [ 'authenticated' => true, 'http_status' => 404 ];
		}
		if ( 401 === $status ) {
			return new WP_Error( 'higgsfield_api_unauthorized', __( 'Higgsfield rejected the API key ID and secret.', 'worldgraph' ), [ 'status' => 401 ] );
		}
		if ( $status >= 200 && $status < 300 ) {
			return [ 'authenticated' => true, 'http_status' => $status ];
		}

		$decoded = self::decode_json_body( $response );
		return new WP_Error( 'higgsfield_api_test_failed', self::error_message( $decoded, $status ), [ 'status' => $status ] );
	}

	/**
	 * Submit one reviewed Higgsfield model operation.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run_template( string $template, string $prompt, array $parameters, int $connection_id = 0 ) {
		$reference = trim( $template );
		$operation = self::OPERATIONS[ $reference ] ?? null;
		if ( ! is_array( $operation ) ) {
			return new WP_Error( 'higgsfield_api_operation_not_allowed', __( 'That Higgsfield API operation is not available to Generation Templates.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		if ( ! self::operation_is_allowed( $connection, $reference ) ) {
			return new WP_Error( 'higgsfield_api_operation_not_allowed', __( 'That Higgsfield API operation is not allowed by the selected Connection.', 'worldgraph' ) );
		}

		$text = trim( wp_strip_all_tags( $prompt ) );
		if ( '' === $text ) {
			return new WP_Error( 'higgsfield_api_prompt_missing', __( 'Enter a Higgsfield generation prompt.', 'worldgraph' ) );
		}
		if ( strlen( $text ) > 10000 ) {
			return new WP_Error( 'higgsfield_api_prompt_too_long', __( 'The Higgsfield generation prompt is too long.', 'worldgraph' ) );
		}

		$body = [ 'prompt' => $text ];
		foreach ( (array) $operation['parameters'] as $name ) {
			if ( array_key_exists( $name, $parameters ) && null !== $parameters[ $name ] && '' !== $parameters[ $name ] ) {
				$body[ $name ] = $parameters[ $name ];
			}
		}

		$inputs = is_array( $parameters['inputs'] ?? null ) ? $parameters['inputs'] : [];
		foreach ( [ 'negative_prompt', 'image', 'end_frame' ] as $input_name ) {
			if ( array_key_exists( $input_name, $inputs ) && ! array_key_exists( $input_name, $body ) ) {
				$body[ $input_name ] = $inputs[ $input_name ];
			}
		}

		$body = self::normalize_operation_body( $reference, $body );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$job_id = absint( $parameters['_worldgraph_job_id'] ?? 0 );
		foreach ( (array) $operation['media'] as $slot => $provider_name ) {
			$value = $inputs[ $slot ] ?? $parameters[ $provider_name ] ?? $body[ $provider_name ] ?? '';
			if ( '' === trim( (string) $value ) ) {
				if ( 'image' === $slot ) {
					return new WP_Error( 'higgsfield_api_image_missing', __( 'That Higgsfield video operation requires a source image.', 'worldgraph' ) );
				}
				continue;
			}

			$url = self::resolve_media_input( $value, $connection_id, $job_id );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$body[ $provider_name ] = $url;
		}
		unset( $body['image'], $body['end_frame'] );

		$base = self::validated_endpoint( (string) ( $connection['endpoint_url'] ?? '' ) );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$headers = self::authorization_headers( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$result = self::json_request( 'POST', $base . (string) $operation['path'], $headers, $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$normalized = self::normalize_result( $result, (string) $operation['kind'] );
		if ( '' === (string) ( $normalized['job_id'] ?? '' ) ) {
			return new WP_Error( 'higgsfield_api_invalid_response', __( 'Higgsfield accepted the request without returning a request ID.', 'worldgraph' ) );
		}

		return $normalized;
	}

	/** Poll one asynchronous request through the stable request-management API. */
	public static function get_job_status( string $job_id, int $connection_id = 0, string $template = '' ) {
		$job_id = trim( $job_id );
		if ( ! preg_match( '/^[a-f0-9-]{16,64}$/i', $job_id ) ) {
			return new WP_Error( 'higgsfield_api_job_id_invalid', __( 'The Higgsfield request ID is missing or invalid.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		$base = self::validated_endpoint( (string) ( $connection['endpoint_url'] ?? '' ) );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$headers = self::authorization_headers( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$result = self::json_request( 'GET', $base . '/requests/' . rawurlencode( $job_id ) . '/status', $headers );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$kind       = self::operation_kind( $template );
		$normalized = self::normalize_result( $result, $kind );
		if ( 'completed' === ( $normalized['status'] ?? '' ) && empty( $normalized['output_media'] ) ) {
			return new WP_Error( 'higgsfield_api_output_missing', __( 'Higgsfield reported completion without a supported media output.', 'worldgraph' ) );
		}

		return $normalized;
	}

	/** Map documented provider states and every supported output URL. */
	public static function normalize_result( array $payload, string $expected_kind = '' ): array {
		$job_id = sanitize_text_field( (string) ( $payload['request_id'] ?? '' ) );
		$status = self::normalize_status( (string) ( $payload['status'] ?? '' ) );
		$result = [
			'job_id'         => $job_id,
			'status'         => $status,
			'provider_status'=> sanitize_key( (string) ( $payload['status'] ?? '' ) ),
			'transport'      => 'api',
		];

		if ( in_array( $status, [ 'failed', 'cancelled' ], true ) && ! empty( $payload['error'] ) ) {
			$result['error'] = sanitize_text_field( is_scalar( $payload['error'] ) ? (string) $payload['error'] : __( 'Higgsfield generation failed.', 'worldgraph' ) );
		}

		if ( 'completed' === $status ) {
			$output_media = [];
			foreach ( (array) ( $payload['images'] ?? [] ) as $item ) {
				self::append_output( $output_media, 'image', $item );
			}
			self::append_output( $output_media, 'video', $payload['video'] ?? null );
			self::append_output( $output_media, 'audio', $payload['audio'] ?? null );
			foreach ( (array) ( $payload['audios'] ?? [] ) as $item ) {
				self::append_output( $output_media, 'audio', $item );
			}
			if ( empty( $output_media ) && '' !== $expected_kind ) {
				self::append_output( $output_media, $expected_kind, $payload['output'] ?? null );
			}
			if ( ! empty( $output_media ) ) {
				$result['output_media'] = array_values( self::unique_media( $output_media ) );
			}
		}

		return $result;
	}

	/** Map Higgsfield request states to the generation worker vocabulary. */
	public static function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( 'completed' === $status ) {
			return 'completed';
		}
		if ( in_array( $status, [ 'failed', 'nsfw' ], true ) ) {
			return 'failed';
		}
		if ( in_array( $status, [ 'canceled', 'cancelled' ], true ) ) {
			return 'cancelled';
		}

		return 'submitted';
	}

	/** Whether a reviewed API operation is permitted by the optional allowlist. */
	public static function operation_is_allowed( array $connection, string $reference ): bool {
		$raw = trim( (string) ( $connection['model_access'] ?? '' ) );
		if ( '' === $raw ) {
			return isset( self::OPERATIONS[ $reference ] );
		}

		$allowed = json_decode( $raw, true );
		if ( ! is_array( $allowed ) ) {
			return false;
		}

		return in_array( $reference, array_values( array_filter( $allowed, 'is_string' ) ), true );
	}

	/** Resolve and validate one saved Higgsfield Connection. */
	private static function connection( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'higgsfield' !== ( $connection['provider_type'] ?? '' ) ) {
			return new WP_Error( 'higgsfield_api_connection_invalid', __( 'Select a Higgsfield Connection first.', 'worldgraph' ) );
		}
		if ( 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'higgsfield_api_connection_disabled', __( 'The selected Higgsfield Connection is disabled.', 'worldgraph' ) );
		}

		return $connection;
	}

	/** Normalize and validate the fixed Higgsfield API origin. */
	private static function validated_endpoint( string $endpoint ) {
		$endpoint = untrailingslashit( trim( $endpoint ?: self::ENDPOINT ) );
		$parts    = wp_parse_url( $endpoint );
		$path     = is_array( $parts ) ? rtrim( (string) ( $parts['path'] ?? '' ), '/' ) : '';
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| 'platform.higgsfield.ai' !== strtolower( (string) ( $parts['host'] ?? '' ) )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| '' !== $path
		) {
			return new WP_Error( 'higgsfield_api_endpoint_invalid', __( 'Use the documented Higgsfield API endpoint: https://platform.higgsfield.ai.', 'worldgraph' ) );
		}

		return self::ENDPOINT;
	}

	/** Resolve the combined key ID/secret and build the preferred auth header. */
	private static function authorization_headers( string $credential_reference ) {
		$credential = self::resolve_credential( $credential_reference );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		if ( '' === $credential || false === strpos( $credential, ':' ) ) {
			return new WP_Error( 'higgsfield_api_credential_missing', __( 'Set the Higgsfield API credential as KEY_ID:KEY_SECRET or an env:// reference containing that combined value.', 'worldgraph' ) );
		}
		[ $key_id, $secret ] = array_map( 'trim', explode( ':', $credential, 2 ) );
		if ( '' === $key_id || '' === $secret || strlen( $credential ) > 4096 || preg_match( '/[\r\n]/', $credential ) ) {
			return new WP_Error( 'higgsfield_api_credential_invalid', __( 'The Higgsfield API credential must contain a key ID and secret separated by one colon.', 'worldgraph' ) );
		}

		return [
			'Accept'        => 'application/json',
			'Authorization' => 'Key ' . $key_id . ':' . $secret,
			'Content-Type'  => 'application/json',
		];
	}

	/** Resolve a literal secret or strict env:// reference. */
	private static function resolve_credential( string $reference ) {
		if ( class_exists( Credential_Store::class ) ) {
			$resolved = Credential_Store::resolve_reference( $reference );
			if ( is_wp_error( $resolved ) ) {
				return new WP_Error( 'higgsfield_api_credential_invalid', $resolved->get_error_message() );
			}
			return trim( (string) $resolved );
		}

		$reference = trim( $reference );
		if ( ! str_starts_with( $reference, 'env://' ) ) {
			return $reference;
		}
		$name = substr( $reference, 6 );
		if ( ! preg_match( '/^[A-Z_][A-Z0-9_]*$/', $name ) ) {
			return new WP_Error( 'higgsfield_api_credential_invalid', __( 'The Higgsfield API env:// reference is invalid.', 'worldgraph' ) );
		}
		$value = getenv( $name );
		return false === $value ? '' : trim( (string) $value );
	}

	/** Send one bounded JSON request to the fixed API origin. */
	private static function json_request( string $method, string $url, array $headers, array $body = [] ) {
		$args = [
			'method'              => strtoupper( $method ),
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'headers'             => $headers,
		];
		if ( 'GET' !== strtoupper( $method ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'higgsfield_api_unreachable', __( 'Higgsfield API could not be reached.', 'worldgraph' ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = self::decode_json_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$code = 401 === $status ? 'higgsfield_api_unauthorized' : ( 404 === $status ? 'higgsfield_api_not_found' : 'higgsfield_api_request_failed' );
			return new WP_Error( $code, self::error_message( $decoded, $status ), [ 'status' => $status ] );
		}
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'higgsfield_api_invalid_response', __( 'Higgsfield API returned an invalid JSON response.', 'worldgraph' ) );
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

	/** Extract a bounded provider error without echoing a response or credential. */
	private static function error_message( ?array $decoded, int $status ): string {
		$detail = $decoded['detail'] ?? $decoded['error'] ?? '';
		if ( is_array( $detail ) ) {
			$detail = $detail['message'] ?? $detail['detail'] ?? '';
		}
		if ( is_scalar( $detail ) && '' !== trim( (string) $detail ) ) {
			return substr( sanitize_text_field( (string) $detail ), 0, 500 );
		}

		return sprintf(
			/* translators: %d: provider HTTP status code. */
			__( 'Higgsfield API returned HTTP %d.', 'worldgraph' ),
			$status
		);
	}

	/** Validate reviewed fields without forwarding arbitrary Template values. */
	private static function normalize_operation_body( string $reference, array $body ) {
		if ( isset( $body['num_images'] ) ) {
			$body['num_images'] = (int) $body['num_images'];
			if ( $body['num_images'] < 1 || $body['num_images'] > 4 ) {
				return new WP_Error( 'higgsfield_api_parameter_invalid', __( 'Higgsfield num_images must be between 1 and 4.', 'worldgraph' ) );
			}
		}
		if ( isset( $body['aspect_ratio'] ) ) {
			$allowed = [ '1:1', '4:3', '3:4', '3:2', '2:3', '5:4', '4:5', '16:9', '9:16', '21:9' ];
			if ( ! in_array( (string) $body['aspect_ratio'], $allowed, true ) ) {
				return new WP_Error( 'higgsfield_api_parameter_invalid', __( 'Select an aspect ratio supported by the Higgsfield Template.', 'worldgraph' ) );
			}
		}
		if ( isset( $body['resolution'] ) ) {
			$body['resolution'] = sanitize_text_field( (string) $body['resolution'] );
			if ( ! in_array( $body['resolution'], [ '720p', '1080p', '2K', '4K' ], true ) ) {
				return new WP_Error( 'higgsfield_api_parameter_invalid', __( 'Select a resolution supported by the Higgsfield Template.', 'worldgraph' ) );
			}
		}
		if ( isset( $body['seed'] ) ) {
			$body['seed'] = (int) $body['seed'];
			if ( $body['seed'] < 1 || $body['seed'] > 1000000 ) {
				return new WP_Error( 'higgsfield_api_parameter_invalid', __( 'Higgsfield seed must be between 1 and 1000000.', 'worldgraph' ) );
			}
		}
		if ( isset( $body['enhance_prompt'] ) ) {
			$body['enhance_prompt'] = rest_sanitize_boolean( $body['enhance_prompt'] );
		}
		if ( isset( $body['duration'] ) ) {
			$body['duration'] = (int) $body['duration'];
			if ( ! in_array( $body['duration'], [ 5, 10 ], true ) ) {
				return new WP_Error( 'higgsfield_api_parameter_invalid', __( 'Kling video duration must be 5 or 10 seconds.', 'worldgraph' ) );
			}
		}
		if ( isset( $body['cfg_scale'] ) ) {
			$body['cfg_scale'] = (float) $body['cfg_scale'];
			if ( $body['cfg_scale'] < 0 || $body['cfg_scale'] > 1 ) {
				return new WP_Error( 'higgsfield_api_parameter_invalid', __( 'Kling CFG scale must be between 0 and 1.', 'worldgraph' ) );
			}
		}
		if ( isset( $body['negative_prompt'] ) ) {
			$body['negative_prompt'] = substr( sanitize_textarea_field( (string) $body['negative_prompt'] ), 0, 5000 );
		}

		$allowed = array_flip( array_merge( [ 'prompt' ], (array) ( self::OPERATIONS[ $reference ]['parameters'] ?? [] ), [ 'image_url', 'end_image_url' ] ) );
		return array_intersect_key( $body, $allowed );
	}

	/** Resolve a validated public HTTPS URL or upload an authorized attachment. */
	private static function resolve_media_input( $value, int $connection_id, int $job_id ) {
		if ( is_numeric( $value ) ) {
			$attachment_id = absint( $value );
			$authorized    = self::authorize_attachment( $attachment_id, $job_id );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}
			return self::upload_attachment( $attachment_id, $connection_id );
		}

		$url = esc_url_raw( trim( (string) $value ) );
		if ( ! self::is_safe_https_url( $url ) ) {
			return new WP_Error( 'higgsfield_api_media_url_invalid', __( 'Higgsfield media inputs must be WordPress attachment IDs or validated public HTTPS URLs.', 'worldgraph' ) );
		}

		return $url;
	}

	/** Recheck that the queued requester may still use a local attachment. */
	private static function authorize_attachment( int $attachment_id, int $job_id ) {
		$filter = 'worldgraph_generation_background_media_authorization';
		if ( $attachment_id < 1 || $job_id < 1 || ! has_filter( $filter ) ) {
			return new WP_Error( 'higgsfield_api_attachment_authorization_unavailable', __( 'The queued attachment authorization policy is unavailable.', 'worldgraph' ) );
		}
		$authorized = apply_filters( $filter, true, $job_id, $attachment_id );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		return true === $authorized
			? true
			: new WP_Error( 'higgsfield_api_attachment_forbidden', __( 'This generation job may not upload the selected attachment to Higgsfield.', 'worldgraph' ) );
	}

	/** Upload one WordPress attachment through Higgsfield's presigned flow. */
	private static function upload_attachment( int $attachment_id, int $connection_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'higgsfield_api_attachment_unreadable', __( 'A bound media attachment could not be read for Higgsfield.', 'worldgraph' ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error( 'higgsfield_api_attachment_too_large', __( 'A Higgsfield media input must be non-empty and no larger than 50MB.', 'worldgraph' ) );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( '' === $mime ) {
			$file_type = wp_check_filetype( basename( $path ) );
			$mime      = strtolower( (string) ( $file_type['type'] ?? '' ) );
		}
		if ( ! in_array( strtolower( $mime ), self::UPLOAD_CONTENT_TYPES, true ) ) {
			return new WP_Error( 'higgsfield_api_attachment_type_unsupported', __( 'That media type is not supported by Higgsfield uploads.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		$base    = self::validated_endpoint( (string) ( $connection['endpoint_url'] ?? '' ) );
		$headers = self::authorization_headers( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $base ) || is_wp_error( $headers ) ) {
			return is_wp_error( $base ) ? $base : $headers;
		}

		$created = self::json_request( 'POST', $base . '/files/generate-upload-url', $headers, [ 'content_type' => $mime ] );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$upload_url = esc_url_raw( (string) ( $created['upload_url'] ?? '' ) );
		$public_url = esc_url_raw( (string) ( $created['public_url'] ?? '' ) );
		if ( ! self::is_safe_https_url( $upload_url ) || ! self::is_safe_https_url( $public_url ) ) {
			return new WP_Error( 'higgsfield_api_upload_contract_invalid', __( 'Higgsfield did not return safe upload and public URLs.', 'worldgraph' ) );
		}

		$upload_headers = self::sanitize_upload_headers( $created['upload_headers'] ?? [], $mime );
		if ( is_wp_error( $upload_headers ) ) {
			return $upload_headers;
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local attachment upload.
		if ( false === $bytes || strlen( $bytes ) !== (int) $size ) {
			return new WP_Error( 'higgsfield_api_attachment_unreadable', __( 'A bound media attachment could not be read for Higgsfield.', 'worldgraph' ) );
		}

		$response = wp_safe_remote_request(
			$upload_url,
			[
				'method'              => 'PUT',
				'timeout'             => self::UPLOAD_TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'headers'             => $upload_headers,
				'body'                => $bytes,
			]
		);
		unset( $bytes );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return new WP_Error( 'higgsfield_api_upload_failed', __( 'Higgsfield could not receive the media input.', 'worldgraph' ) );
		}

		return $public_url;
	}

	/** Retain provider-required upload headers without forwarding secrets. */
	private static function sanitize_upload_headers( $headers, string $mime ) {
		if ( ! is_array( $headers ) || count( $headers ) > 20 ) {
			return new WP_Error( 'higgsfield_api_upload_contract_invalid', __( 'Higgsfield returned invalid upload headers.', 'worldgraph' ) );
		}
		$blocked = [ 'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'host', 'connection', 'transfer-encoding' ];
		$safe    = [];
		foreach ( $headers as $name => $value ) {
			$name = strtolower( trim( (string) $name ) );
			if ( ! preg_match( '/^[a-z0-9!#$%&\'*+.^_`|~-]{1,100}$/', $name ) || in_array( $name, $blocked, true ) || ! is_scalar( $value ) ) {
				return new WP_Error( 'higgsfield_api_upload_contract_invalid', __( 'Higgsfield returned unsafe upload headers.', 'worldgraph' ) );
			}
			$value = trim( (string) $value );
			if ( strlen( $value ) > 2000 || preg_match( '/[\r\n]/', $value ) ) {
				return new WP_Error( 'higgsfield_api_upload_contract_invalid', __( 'Higgsfield returned unsafe upload headers.', 'worldgraph' ) );
			}
			$safe[ $name ] = $value;
		}
		$safe['content-type'] = $mime;

		return $safe;
	}

	/** Whether a provider-returned or operator-supplied URL passes WP SSRF checks. */
	private static function is_safe_https_url( string $url ): bool {
		return '' !== $url && str_starts_with( strtolower( $url ), 'https://' ) && (bool) wp_http_validate_url( $url );
	}

	/** Add one typed media result when its URL is valid. */
	private static function append_output( array &$output_media, string $kind, $item ): void {
		$url = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : ( is_string( $item ) ? $item : '' );
		$url = esc_url_raw( $url );
		if ( self::is_safe_https_url( $url ) ) {
			$output_media[] = [ 'kind' => $kind, 'url' => $url ];
		}
	}

	/** De-duplicate typed output records without dropping distinct media. */
	private static function unique_media( array $items ): array {
		$unique = [];
		foreach ( $items as $item ) {
			$key = (string) ( $item['kind'] ?? '' ) . '|' . (string) ( $item['url'] ?? '' );
			$unique[ $key ] = $item;
		}
		return $unique;
	}

	/** Infer the expected output kind from a reviewed Template reference. */
	private static function operation_kind( string $reference ): string {
		return (string) ( self::OPERATIONS[ trim( $reference ) ]['kind'] ?? '' );
	}
}
