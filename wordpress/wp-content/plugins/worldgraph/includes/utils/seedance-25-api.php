<?php
/**
 * Seedance 2.5 via CyberBara asynchronous REST generation client.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** CyberBara REST client for the reviewed Seedance 2.5 operations. */
class Seedance_25_API {

	/** Documented production API origin. */
	const ENDPOINT = 'https://cyberbara.com';

	/** Reviewed provider model identifier. */
	const MODEL = 'seedance-2.5';

	/** HTTP timeout for JSON requests. */
	const TIMEOUT = 60;

	/** HTTP timeout for a bounded reference-image upload. */
	const UPLOAD_TIMEOUT = 120;

	/** Maximum JSON response body retained in memory. */
	const MAX_RESPONSE_BYTES = 1048576;

	/** Maximum accepted provider catalog entries. */
	const MAX_MODEL_ITEMS = 100;

	/** Maximum accepted final video URLs for one task. */
	const MAX_OUTPUT_URLS = 20;

	/** Maximum accepted URL size at any provider boundary. */
	const MAX_URL_BYTES = 4096;

	/** CyberBara's documented per-image upload limit. */
	const MAX_UPLOAD_BYTES = 10485760;

	/** Maximum locally accepted prompt size. This is not a provider claim. */
	const MAX_PROMPT_BYTES = 10000;

	/** Narrow raster subset of CyberBara's documented image upload formats. */
	const UPLOAD_CONTENT_TYPES = [
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
		'image/avif',
	];

	/**
	 * Reviewed executable Template references.
	 *
	 * The upstream model also advertises video-to-video and audio references,
	 * but World Graph Studio does not yet have a matching reviewed source-video
	 * modality and local audio-upload contract for this provider.
	 */
	const OPERATIONS = [
		'seedance-2.5:text-to-video' => [
			'model' => self::MODEL,
			'scene' => 'text-to-video',
			'media' => [],
		],
		'seedance-2.5:image-to-video' => [
			'model' => self::MODEL,
			'scene' => 'image-to-video',
			'media' => [ 'image' => 'image_input' ],
		],
	];

	/** Test unsaved Connection values by listing the available video models. */
	public static function test_configuration( string $endpoint, string $credential_reference ) {
		return static::fetch_video_models( $endpoint, $credential_reference );
	}

	/** Return the bounded video-model catalog for a saved Connection. */
	public static function video_models( int $connection_id ) {
		$connection = static::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		return static::fetch_video_models(
			(string) ( $connection['endpoint_url'] ?? '' ),
			(string) ( $connection['credential_reference'] ?? '' )
		);
	}

	/**
	 * Return the supported scenes for one exact model in a normalized catalog.
	 *
	 * @param array<int, array<string, mixed>> $models Normalized model entries.
	 * @return array<int, string>
	 */
	public static function model_scenes( array $models, string $model = self::MODEL ): array {
		foreach ( array_slice( $models, 0, 100 ) as $entry ) {
			if ( ! is_array( $entry ) || $model !== (string) ( $entry['model'] ?? '' ) ) {
				continue;
			}
			if ( 'video' !== ( $entry['media_type'] ?? null ) || ! is_array( $entry['supported_scenes'] ?? null ) || ! array_is_list( $entry['supported_scenes'] ) ) {
				return [];
			}

			return array_values( array_intersect( [ 'text-to-video', 'image-to-video', 'video-to-video' ], $entry['supported_scenes'] ) );
		}

		return [];
	}

	/** Submit one allowlisted Seedance 2.5 video operation. */
	public static function run_template( string $template, string $prompt, array $parameters, int $connection_id = 0 ) {
		$reference = trim( $template );
		$operation = self::OPERATIONS[ $reference ] ?? null;
		if ( ! is_array( $operation ) ) {
			return new WP_Error( 'seedance_25_api_operation_not_allowed', __( 'That Seedance 2.5 operation is not available to Generation Templates.', 'worldgraph' ) );
		}

		$connection = static::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		if ( ! self::operation_is_allowed( $connection, $reference ) ) {
			return new WP_Error( 'seedance_25_api_operation_not_allowed', __( 'That Seedance 2.5 operation is not allowed by the selected Connection.', 'worldgraph' ) );
		}

		$text = trim( wp_strip_all_tags( $prompt ) );
		if ( '' === $text ) {
			return new WP_Error( 'seedance_25_api_prompt_missing', __( 'Enter a Seedance 2.5 generation prompt.', 'worldgraph' ) );
		}
		if ( strlen( $text ) > self::MAX_PROMPT_BYTES ) {
			return new WP_Error( 'seedance_25_api_prompt_too_long', __( 'The Seedance 2.5 generation prompt is too long.', 'worldgraph' ) );
		}

		$options = [];
		foreach ( [ 'duration', 'resolution', 'aspect_ratio' ] as $name ) {
			if ( array_key_exists( $name, $parameters ) && null !== $parameters[ $name ] && '' !== $parameters[ $name ] ) {
				$options[ $name ] = $parameters[ $name ];
			}
		}
		$options = self::normalize_options( $options );
		if ( is_wp_error( $options ) ) {
			return $options;
		}

		$inputs = is_array( $parameters['inputs'] ?? null ) ? $parameters['inputs'] : [];
		$job_id = absint( $parameters['_worldgraph_job_id'] ?? 0 );
		foreach ( (array) $operation['media'] as $slot => $provider_name ) {
			$value = $inputs[ $slot ] ?? '';
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				return new WP_Error( 'seedance_25_api_image_missing', __( 'Seedance 2.5 image-to-video generation requires a source image.', 'worldgraph' ) );
			}

			$url = static::resolve_media_input( $value, $connection_id, $job_id );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$options[ $provider_name ] = [ $url ];
		}

		$base = static::validated_endpoint( (string) ( $connection['endpoint_url'] ?? '' ) );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$headers = static::authorization_headers( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$payload = static::json_request(
			'POST',
			$base . '/api/v1/videos/generations',
			$headers,
			[
				'model'   => (string) $operation['model'],
				'scene'   => (string) $operation['scene'],
				'prompt'  => $text,
				'options' => $options,
			],
			'submit'
		);
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$result = self::normalize_result( $payload, $operation );
		if ( is_wp_error( $result ) ) {
			return self::submit_ambiguous_error();
		}
		if ( 'completed' === ( $result['status'] ?? '' ) && empty( $result['output_media'] ) ) {
			return new WP_Error( 'seedance_25_api_output_missing', __( 'CyberBara reported completion without a supported Seedance video output.', 'worldgraph' ) );
		}

		return $result;
	}

	/** Poll one asynchronous CyberBara task. */
	public static function get_job_status( string $job_id, int $connection_id = 0, string $template = '' ) {
		$job_id = self::validated_task_id( $job_id );
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}
		$operation = self::OPERATIONS[ trim( $template ) ] ?? null;
		if ( ! is_array( $operation ) ) {
			return new WP_Error( 'seedance_25_api_operation_not_allowed', __( 'The queued Seedance 2.5 task has no reviewed operation reference.', 'worldgraph' ) );
		}

		$connection = static::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		$base = static::validated_endpoint( (string) ( $connection['endpoint_url'] ?? '' ) );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$headers = static::authorization_headers( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$payload = static::json_request( 'GET', $base . '/api/v1/tasks/' . rawurlencode( $job_id ), $headers, null, 'poll' );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$result = self::normalize_result( $payload, $operation );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! hash_equals( $job_id, $result['job_id'] ) ) {
			return new WP_Error( 'seedance_25_api_job_id_mismatch', __( 'CyberBara returned a different task than the one requested.', 'worldgraph' ) );
		}
		if ( 'completed' === ( $result['status'] ?? '' ) && empty( $result['output_media'] ) ) {
			return new WP_Error( 'seedance_25_api_output_missing', __( 'CyberBara reported completion without a supported Seedance video output.', 'worldgraph' ) );
		}

		return $result;
	}

	/** Normalize provider response envelopes, states, and an all-valid video list. */
	public static function normalize_result( array $payload, array $expected_operation = [] ) {
		$data = self::unwrap_data( $payload );
		if ( is_array( $data['task'] ?? null ) ) {
			$data = $data['task'];
		}

		$raw_job_id = $data['task_id'] ?? $data['id'] ?? '';
		$raw_status = $data['status'] ?? '';
		$job_id     = self::validated_task_id( $raw_job_id );
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}
		$status = is_string( $raw_status ) ? self::normalize_status( $raw_status ) : new WP_Error( 'seedance_25_api_status_invalid', __( 'CyberBara returned an invalid Seedance task status.', 'worldgraph' ) );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		$contract = self::validate_task_contract( $data, $expected_operation );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}
		$result = [
			'job_id'          => $job_id,
			'status'          => $status,
			'provider_status' => $raw_status,
			'transport'       => 'api',
		];

		if ( in_array( $status, [ 'failed', 'cancelled' ], true ) ) {
			$message = self::failure_message( $data );
			if ( '' !== $message ) {
				$result['error'] = $message;
			}
		}

		if ( 'completed' === $status ) {
			$videos = $data['output']['videos'] ?? [];
			if ( ! is_array( $videos ) || ! array_is_list( $videos ) || count( $videos ) > self::MAX_OUTPUT_URLS ) {
				return new WP_Error( 'seedance_25_api_output_contract_invalid', __( 'CyberBara returned an invalid Seedance video-output list.', 'worldgraph' ) );
			}
			$output_media = [];
			foreach ( $videos as $video ) {
				if ( ! is_string( $video ) || strlen( $video ) > self::MAX_URL_BYTES ) {
					return new WP_Error( 'seedance_25_api_output_contract_invalid', __( 'CyberBara returned an invalid Seedance video URL.', 'worldgraph' ) );
				}
				$url = esc_url_raw( trim( $video ) );
				if ( ! self::is_safe_https_url( $url ) ) {
					return new WP_Error( 'seedance_25_api_output_contract_invalid', __( 'CyberBara returned an unsafe Seedance video URL.', 'worldgraph' ) );
				}
				$output_media[ $url ] = [ 'kind' => 'video', 'url' => $url ];
			}
			if ( ! empty( $output_media ) ) {
				$result['output_media'] = array_values( $output_media );
			}
		}

		return $result;
	}

	/** Map CyberBara task states to the generation worker vocabulary. */
	public static function normalize_status( string $status ) {
		$map = [
			'pending'    => 'submitted',
			'processing' => 'submitted',
			'success'    => 'completed',
			'failed'     => 'failed',
			'canceled'   => 'cancelled',
		];

		return $map[ $status ] ?? new WP_Error( 'seedance_25_api_status_invalid', __( 'CyberBara returned an unknown Seedance task status.', 'worldgraph' ) );
	}

	/** Validate one provider task ID without mutating its identity. */
	private static function validated_task_id( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
			return new WP_Error( 'seedance_25_api_job_id_invalid', __( 'The Seedance 2.5 task ID is missing or invalid.', 'worldgraph' ) );
		}

		return $value;
	}

	/** Verify provider-owned task metadata against the fixed queued operation. */
	private static function validate_task_contract( array $data, array $expected_operation ) {
		if ( empty( $expected_operation ) ) {
			return true;
		}
		if (
			'video' !== ( $data['media_type'] ?? null )
			|| (string) ( $expected_operation['model'] ?? '' ) !== ( $data['model'] ?? null )
			|| (string) ( $expected_operation['scene'] ?? '' ) !== ( $data['scene'] ?? null )
		) {
			return new WP_Error( 'seedance_25_api_task_contract_mismatch', __( 'CyberBara returned task metadata that does not match the queued Seedance operation.', 'worldgraph' ) );
		}

		return true;
	}

	/** Explain why a non-idempotent submission cannot be retried automatically. */
	private static function submit_ambiguous_error(): WP_Error {
		return new WP_Error(
			'seedance_25_api_submit_ambiguous',
			__( 'CyberBara may have accepted this paid Seedance task. Check CyberBara tasks before deliberately retrying.', 'worldgraph' )
		);
	}

	/** Whether an optional exact Template-reference allowlist permits a run. */
	public static function operation_is_allowed( array $connection, string $reference ): bool {
		$raw = trim( (string) ( $connection['model_access'] ?? '' ) );
		if ( '' === $raw ) {
			return isset( self::OPERATIONS[ $reference ] );
		}

		$allowed = json_decode( $raw, true );
		if ( ! is_array( $allowed ) || $allowed !== array_values( $allowed ) ) {
			return false;
		}
		foreach ( $allowed as $allowed_reference ) {
			if ( ! is_string( $allowed_reference ) || ! isset( self::OPERATIONS[ $allowed_reference ] ) ) {
				return false;
			}
		}

		return in_array( $reference, array_values( array_unique( $allowed ) ), true );
	}

	/** Validate and normalize the small reviewed provider-option subset. */
	protected static function normalize_options( array $options ) {
		$normalized = [];
		if ( array_key_exists( 'duration', $options ) ) {
			$duration = $options['duration'];
			if ( ! is_int( $duration ) && ! ( is_string( $duration ) && preg_match( '/^\d{1,2}$/', $duration ) ) ) {
				return new WP_Error( 'seedance_25_api_parameter_invalid', __( 'Seedance 2.5 duration must be a whole number from 4 to 30 seconds.', 'worldgraph' ) );
			}
			$duration = (int) $duration;
			if ( $duration < 4 || $duration > 30 ) {
				return new WP_Error( 'seedance_25_api_parameter_invalid', __( 'Seedance 2.5 duration must be a whole number from 4 to 30 seconds.', 'worldgraph' ) );
			}
			$normalized['duration'] = $duration;
		}
		if ( array_key_exists( 'resolution', $options ) ) {
			if ( ! is_string( $options['resolution'] ) || ! in_array( $options['resolution'], [ '480p', '720p' ], true ) ) {
				return new WP_Error( 'seedance_25_api_parameter_invalid', __( 'Seedance 2.5 resolution must be 480p or 720p.', 'worldgraph' ) );
			}
			$normalized['resolution'] = $options['resolution'];
		}
		if ( array_key_exists( 'aspect_ratio', $options ) ) {
			$ratios = [ '21:9', '16:9', '4:3', '1:1', '3:4', '9:16' ];
			if ( ! is_string( $options['aspect_ratio'] ) || ! in_array( $options['aspect_ratio'], $ratios, true ) ) {
				return new WP_Error( 'seedance_25_api_parameter_invalid', __( 'Select an aspect ratio supported by Seedance 2.5.', 'worldgraph' ) );
			}
			$normalized['aspect_ratio'] = $options['aspect_ratio'];
		}

		return $normalized;
	}

	/** Resolve and validate one saved Seedance 2.5 Connection. */
	protected static function connection( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'publish' !== ( $connection['status_wp'] ?? '' ) || 'seedance_25' !== ( $connection['provider_type'] ?? '' ) ) {
			return new WP_Error( 'seedance_25_api_connection_invalid', __( 'Select a published Seedance 2.5 via CyberBara Connection first.', 'worldgraph' ) );
		}
		if ( 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'seedance_25_api_connection_disabled', __( 'The selected Seedance 2.5 Connection is disabled.', 'worldgraph' ) );
		}

		return $connection;
	}

	/** Fetch and normalize the provider's video model list. */
	protected static function fetch_video_models( string $endpoint, string $credential_reference ) {
		$base = static::validated_endpoint( $endpoint );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$headers = static::authorization_headers( $credential_reference );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$payload = static::json_request( 'GET', $base . '/api/v1/models?media_type=video', $headers, null, 'models' );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$data   = self::unwrap_data( $payload );
		$models = $data['models'] ?? null;
		if ( ! is_array( $models ) || ! array_is_list( $models ) || empty( $models ) || count( $models ) > self::MAX_MODEL_ITEMS ) {
			return new WP_Error( 'seedance_25_api_models_invalid', __( 'CyberBara returned no usable video models.', 'worldgraph' ) );
		}

		$normalized = [];
		$seen       = [];
		foreach ( $models as $entry ) {
			if (
				! is_array( $entry )
				|| ! is_string( $entry['model'] ?? null )
				|| ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,199}$/', $entry['model'] )
				|| 'video' !== ( $entry['media_type'] ?? null )
				|| ! is_array( $entry['supported_scenes'] ?? null )
				|| ! array_is_list( $entry['supported_scenes'] )
				|| count( $entry['supported_scenes'] ) > 20
			) {
				return new WP_Error( 'seedance_25_api_models_invalid', __( 'CyberBara returned an invalid video-model catalog.', 'worldgraph' ) );
			}
			$model = $entry['model'];
			if ( isset( $seen[ $model ] ) ) {
				return new WP_Error( 'seedance_25_api_models_invalid', __( 'CyberBara returned duplicate video-model entries.', 'worldgraph' ) );
			}
			$seen[ $model ] = true;
			$scenes = [];
			foreach ( $entry['supported_scenes'] as $scene ) {
				if ( ! is_string( $scene ) || ! preg_match( '/^[a-z0-9][a-z0-9._:-]{0,99}$/', $scene ) ) {
					return new WP_Error( 'seedance_25_api_models_invalid', __( 'CyberBara returned an invalid video-model scene.', 'worldgraph' ) );
				}
				$scenes[] = $scene;
			}
			$normalized[] = [
				'model'            => $model,
				'media_type'       => 'video',
				'supported_scenes' => array_values( array_unique( $scenes ) ),
			];
		}
		if ( empty( $normalized ) ) {
			return new WP_Error( 'seedance_25_api_models_invalid', __( 'CyberBara returned no usable video models.', 'worldgraph' ) );
		}

		return $normalized;
	}

	/** Normalize and strictly validate the fixed CyberBara origin. */
	protected static function validated_endpoint( string $endpoint ) {
		$endpoint = untrailingslashit( trim( $endpoint ?: self::ENDPOINT ) );
		$parts    = wp_parse_url( $endpoint );
		$path     = is_array( $parts ) ? rtrim( (string) ( $parts['path'] ?? '' ), '/' ) : '';
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| 'cyberbara.com' !== strtolower( (string) ( $parts['host'] ?? '' ) )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| '' !== $path
		) {
			return new WP_Error( 'seedance_25_api_endpoint_invalid', __( 'Use the documented CyberBara endpoint: https://cyberbara.com.', 'worldgraph' ) );
		}

		return self::ENDPOINT;
	}

	/** Resolve a Connection credential and build the reviewed Bearer header. */
	protected static function authorization_headers( string $credential_reference ) {
		$credential = static::resolve_credential( $credential_reference );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		if ( '' === $credential ) {
			return new WP_Error( 'seedance_25_api_credential_missing', __( 'Set a CyberBara API key or env://CYBERBARA_API_KEY reference on this Connection.', 'worldgraph' ) );
		}
		if ( strlen( $credential ) > 4096 || preg_match( '/[\x00-\x1F\x7F]/', $credential ) ) {
			return new WP_Error( 'seedance_25_api_credential_invalid', __( 'The CyberBara API key is invalid.', 'worldgraph' ) );
		}

		return [
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $credential,
		];
	}

	/** Resolve a literal API key or strict env:// reference without exposing it. */
	protected static function resolve_credential( string $reference ) {
		if ( class_exists( Credential_Store::class ) ) {
			$resolved = Credential_Store::resolve_reference( $reference );
			if ( is_wp_error( $resolved ) ) {
				if ( 'worldgraph_credential_environment_missing' === $resolved->get_error_code() ) {
					return new WP_Error( 'seedance_25_api_credential_missing', __( 'The CyberBara API environment credential is unavailable.', 'worldgraph' ) );
				}
				return new WP_Error( 'seedance_25_api_credential_invalid', __( 'The CyberBara API credential reference could not be resolved.', 'worldgraph' ) );
			}
			return trim( (string) $resolved );
		}

		$reference = trim( $reference );
		if ( str_starts_with( $reference, 'env://' ) ) {
			$name = substr( $reference, 6 );
			if ( ! preg_match( '/^[A-Z_][A-Z0-9_]*$/', $name ) ) {
				return new WP_Error( 'seedance_25_api_credential_invalid', __( 'The CyberBara API key env:// reference is invalid.', 'worldgraph' ) );
			}
			$value = getenv( $name );
			return false === $value || '' === trim( (string) $value )
				? new WP_Error( 'seedance_25_api_credential_missing', __( 'The CyberBara API environment credential is unavailable.', 'worldgraph' ) )
				: trim( (string) $value );
		}

		return $reference;
	}

	/** Execute one bounded JSON request through WordPress's SSRF-safe client. */
	protected static function json_request( string $method, string $url, array $headers, ?array $body = null, string $context = 'request' ) {
		$args = [
			'method'              => $method,
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'headers'             => $headers,
		];
		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return new WP_Error( 'seedance_25_api_request_invalid', __( 'The Seedance request could not be encoded safely.', 'worldgraph' ) );
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $encoded;
		}

		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			if ( 'submit' === $context ) {
				return self::submit_ambiguous_error();
			}
			return new WP_Error( 'seedance_25_api_unreachable', __( 'CyberBara could not be reached.', 'worldgraph' ) );
		}

		return self::decode_response( $response, $context );
	}

	/** Resolve a public provider URL or upload one reauthorized attachment. */
	protected static function resolve_media_input( $value, int $connection_id, int $job_id ) {
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/', $value ) ) ) {
			$attachment_id = absint( $value );
			$authorized    = self::authorize_attachment( $attachment_id, $job_id );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}

			return static::upload_attachment( $attachment_id, $connection_id );
		}

		$url = esc_url_raw( trim( (string) $value ) );
		if ( ! self::is_safe_https_url( $url ) ) {
			return new WP_Error( 'seedance_25_api_media_url_invalid', __( 'Seedance media inputs must be WordPress attachment IDs or validated public HTTPS URLs.', 'worldgraph' ) );
		}

		return $url;
	}

	/** Recheck that the queued requester may still use a local attachment. */
	private static function authorize_attachment( int $attachment_id, int $job_id ) {
		$filter = 'worldgraph_generation_background_media_authorization';
		if ( $attachment_id < 1 || $job_id < 1 || ! has_filter( $filter ) ) {
			return new WP_Error( 'seedance_25_api_attachment_authorization_unavailable', __( 'The queued attachment authorization policy is unavailable.', 'worldgraph' ) );
		}
		$authorized = apply_filters( $filter, true, $job_id, $attachment_id );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		return true === $authorized
			? true
			: new WP_Error( 'seedance_25_api_attachment_forbidden', __( 'The queued requester may not upload that image to CyberBara.', 'worldgraph' ) );
	}

	/** Upload one bounded WordPress image attachment to CyberBara. */
	protected static function upload_attachment( int $attachment_id, int $connection_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'seedance_25_api_attachment_unreadable', __( 'A bound Seedance reference image could not be read.', 'worldgraph' ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error( 'seedance_25_api_attachment_too_large', __( 'A Seedance reference image must be non-empty and no larger than 10MB.', 'worldgraph' ) );
		}

		$declared_mime = strtolower( (string) get_post_mime_type( $attachment_id ) );
		$declared_mime = 'image/jpg' === $declared_mime ? 'image/jpeg' : $declared_mime;
		$actual_mime   = strtolower( (string) wp_get_image_mime( $path ) );
		if (
			! in_array( $actual_mime, self::UPLOAD_CONTENT_TYPES, true )
			|| ( '' !== $declared_mime && $declared_mime !== $actual_mime )
		) {
			return new WP_Error( 'seedance_25_api_attachment_type_unsupported', __( 'That image type is not supported by the Seedance upload path.', 'worldgraph' ) );
		}
		$mime = $actual_mime;

		$connection = static::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		$base = static::validated_endpoint( (string) ( $connection['endpoint_url'] ?? '' ) );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$headers = static::authorization_headers( (string) ( $connection['credential_reference'] ?? '' ) );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local attachment upload.
		if ( false === $bytes || strlen( $bytes ) !== (int) $size ) {
			return new WP_Error( 'seedance_25_api_attachment_unreadable', __( 'A bound Seedance reference image could not be read.', 'worldgraph' ) );
		}
		$boundary = 'WorldGraphSeedance' . preg_replace( '/[^A-Za-z0-9]/', '', wp_generate_uuid4() );
		$filename = str_replace( [ '"', "\r", "\n" ], '', sanitize_file_name( basename( $path ) ) );
		$body     = '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="files"; filename="' . $filename . '"' . "\r\n"
			. 'Content-Type: ' . $mime . "\r\n\r\n"
			. $bytes . "\r\n"
			. '--' . $boundary . "--\r\n";
		unset( $bytes );

		$payload = static::multipart_request(
			$base . '/api/v1/uploads/images',
			$headers,
			$body,
			'multipart/form-data; boundary=' . $boundary
		);
		unset( $body );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$data = self::unwrap_data( $payload );
		$url  = is_string( $data['urls'][0] ?? null ) ? (string) $data['urls'][0] : '';
		if ( '' === $url && is_array( $data['files'][0] ?? null ) ) {
			$url = (string) ( $data['files'][0]['url'] ?? '' );
		}
		$url = esc_url_raw( trim( $url ) );
		if ( ! self::is_safe_https_url( $url ) ) {
			return new WP_Error( 'seedance_25_api_upload_contract_invalid', __( 'CyberBara did not return a safe uploaded-image URL.', 'worldgraph' ) );
		}

		return $url;
	}

	/** Execute one bounded multipart request. */
	protected static function multipart_request( string $url, array $headers, string $body, string $content_type ) {
		$headers['Content-Type'] = $content_type;
		$response                = wp_safe_remote_request(
			$url,
			[
				'method'              => 'POST',
				'timeout'             => self::UPLOAD_TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => $headers,
				'body'                => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'seedance_25_api_upload_failed', __( 'The reference image could not be uploaded to CyberBara.', 'worldgraph' ) );
		}

		return self::decode_response( $response, 'upload' );
	}

	/** Decode one bounded response and map provider failures to stable errors. */
	private static function decode_response( $response, string $context = 'request' ) {
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = strlen( $body ) <= self::MAX_RESPONSE_BYTES ? json_decode( $body, true ) : null;
		if ( $status < 200 || $status >= 300 ) {
			return self::response_error( is_array( $decoded ) ? $decoded : [], $status, $context );
		}
		if ( ! is_array( $decoded ) ) {
			return 'submit' === $context
				? self::submit_ambiguous_error()
				: new WP_Error( 'seedance_25_api_invalid_response', __( 'CyberBara returned an invalid JSON response.', 'worldgraph' ), [ 'status' => $status ] );
		}

		return $decoded;
	}

	/** Map HTTP/provider failures without exposing response bodies or secrets. */
	private static function response_error( array $decoded, int $status, string $context ): WP_Error {
		$provider_code = self::provider_error_code( $decoded );
		if ( in_array( $status, [ 401, 403 ], true ) || in_array( $provider_code, [ 'api_key_required', 'invalid_api_key' ], true ) ) {
			$code = 'seedance_25_api_unauthorized';
		} elseif ( 'invalid_task_id' === $provider_code ) {
			$code = 'seedance_25_api_job_id_invalid';
		} elseif ( 'task_not_found' === $provider_code || ( 404 === $status && 'poll' === $context ) ) {
			$code = 'seedance_25_api_job_not_found';
		} elseif ( 'task_not_ready' === $provider_code ) {
			$code = 'seedance_25_api_task_not_ready';
		} elseif ( 429 === $status || 'too_many_requests' === $provider_code ) {
			$code = 'seedance_25_api_rate_limited';
		} elseif ( 'insufficient_credits' === $provider_code ) {
			$code = 'seedance_25_api_insufficient_credits';
		} elseif ( 'submit' === $context && ( 408 === $status || $status >= 500 ) ) {
			return self::submit_ambiguous_error();
		} elseif ( in_array( $status, [ 400, 409, 422 ], true ) ) {
			$code = 'seedance_25_api_request_invalid';
		} elseif ( $status >= 500 || 'service_unavailable' === $provider_code ) {
			$code = 'seedance_25_api_service_unavailable';
		} else {
			$code = 'seedance_25_api_request_failed';
		}

		return new WP_Error(
			$code,
			self::response_error_message( $code, $status ),
			[
				'status'        => $status,
				'provider_code' => $provider_code,
				'context'       => $context,
			]
		);
	}

	/** Return an actionable local message without retaining provider prose. */
	private static function response_error_message( string $code, int $status ): string {
		$messages = [
			'seedance_25_api_unauthorized'          => __( 'CyberBara rejected the configured API key.', 'worldgraph' ),
			'seedance_25_api_job_id_invalid'        => __( 'CyberBara rejected the Seedance task ID.', 'worldgraph' ),
			'seedance_25_api_job_not_found'         => __( 'CyberBara could not find that Seedance task.', 'worldgraph' ),
			'seedance_25_api_task_not_ready'        => __( 'The CyberBara task is not ready to be queried yet.', 'worldgraph' ),
			'seedance_25_api_rate_limited'          => __( 'CyberBara rate-limited the request.', 'worldgraph' ),
			'seedance_25_api_insufficient_credits'  => __( 'The CyberBara account has insufficient credits for this request.', 'worldgraph' ),
			'seedance_25_api_request_invalid'       => __( 'CyberBara rejected the Seedance request.', 'worldgraph' ),
			'seedance_25_api_service_unavailable'   => __( 'CyberBara is temporarily unavailable.', 'worldgraph' ),
		];
		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}

		return sprintf(
			/* translators: %d: provider HTTP status code. */
			__( 'CyberBara returned HTTP %d.', 'worldgraph' ),
			$status
		);
	}

	/** Retain only a bounded identifier from the provider error envelope. */
	private static function provider_error_code( array $decoded ): string {
		$error = $decoded['error'] ?? null;
		$value = is_array( $error ) ? ( $error['code'] ?? '' ) : '';
		return is_string( $value ) && strlen( $value ) <= 100 ? sanitize_key( $value ) : '';
	}

	/** Unwrap the provider's standard success envelope. */
	private static function unwrap_data( array $payload ): array {
		return is_array( $payload['data'] ?? null ) ? $payload['data'] : $payload;
	}

	/** Describe a terminal task failure without retaining remote prose. */
	private static function failure_message( array $data ): string {
		$code = self::provider_error_code( $data );
		if ( 'insufficient_credits' === $code ) {
			return __( 'CyberBara reported insufficient credits for the Seedance task.', 'worldgraph' );
		}
		if ( str_contains( $code, 'safety' ) || str_contains( $code, 'moderation' ) || str_contains( $code, 'policy' ) ) {
			return __( 'CyberBara reported that the Seedance task was blocked by provider safety policy.', 'worldgraph' );
		}

		return __( 'CyberBara reported that the Seedance task failed or was canceled.', 'worldgraph' );
	}

	/** Whether a provider-returned or operator-supplied URL passes WP SSRF checks. */
	private static function is_safe_https_url( string $url ): bool {
		return '' !== $url
			&& strlen( $url ) <= self::MAX_URL_BYTES
			&& str_starts_with( strtolower( $url ), 'https://' )
			&& (bool) wp_http_validate_url( $url );
	}
}
