<?php
/**
 * OpenRouter Video Generation REST API Connection adapter.
 *
 * Implements the asynchronous submit/poll/download workflow documented at
 * https://openrouter.ai/docs/guides/overview/multimodal/video-generation.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** OpenRouter video-generation API client. */
class OpenRouter_API {

	/** Default API base URL. */
	const ENDPOINT = 'https://openrouter.ai/api/v1';

	/** HTTP timeout in seconds. */
	const TIMEOUT = 60;

	/** Template/request fields the Video Generation API documents. */
	const ALLOWED_PARAMETERS = [
		'duration',
		'resolution',
		'aspect_ratio',
		'size',
		'frame_images',
		'input_references',
		'generate_audio',
		'seed',
		'callback_url',
		'provider',
	];

	/** Test unsaved Setup Wizard credentials and return the available video models. */
	public static function test_configuration( string $endpoint, string $credential_reference ) {
		return self::fetch_video_models( $endpoint, $credential_reference );
	}

	/** Return video generation models available to a saved Connection. */
	public static function video_models( int $connection_id ) {
		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		return self::fetch_video_models( (string) $connection['endpoint_url'], (string) $connection['credential_reference'] );
	}

	/**
	 * Submit a Template's video generation job.
	 *
	 * `$template` is the OpenRouter model slug, e.g. `google/veo-3.1`.
	 *
	 * @return array|WP_Error [ 'job_id' => string, 'status' => string ].
	 */
	public static function run_template( string $template, string $prompt, array $parameters, int $connection_id = 0 ) {
		$model = trim( $template );
		if ( '' === $model ) {
			return new WP_Error( 'openrouter_model_missing', __( 'The selected Template has no OpenRouter model configured.', 'worldgraph' ) );
		}

		$text = trim( wp_strip_all_tags( $prompt ) );
		if ( '' === $text ) {
			return new WP_Error( 'openrouter_prompt_missing', __( 'Enter a generation prompt.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$body = array_intersect_key( $parameters, array_flip( self::ALLOWED_PARAMETERS ) );
		$body['model'] = $model;
		$body['prompt'] = $text;

		$headers = self::headers( (string) $connection['credential_reference'] );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$response = wp_remote_post( self::endpoint( $connection ) . '/videos', [
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'openrouter_unreachable', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			return new WP_Error( 'openrouter_request_failed', self::error_message( $decoded, $code ), [ 'status' => $code ] );
		}

		$job_id = sanitize_text_field( (string) ( $decoded['id'] ?? '' ) );
		if ( '' === $job_id ) {
			return new WP_Error( 'openrouter_invalid_response', __( 'OpenRouter did not return a video generation job ID.', 'worldgraph' ) );
		}

		return [ 'job_id' => $job_id, 'status' => self::normalize_status( (string) ( $decoded['status'] ?? 'pending' ) ) ];
	}

	/** Poll a submitted job and normalize its status for the generation batch processor. */
	public static function get_job_status( string $job_id, int $connection_id = 0 ) {
		$job_id = trim( $job_id );
		if ( '' === $job_id ) {
			return new WP_Error( 'openrouter_job_id_missing', __( 'The OpenRouter generation job ID is missing.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$headers = self::headers( (string) $connection['credential_reference'] );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$response = wp_remote_get( self::endpoint( $connection ) . '/videos/' . rawurlencode( $job_id ), [
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'openrouter_unreachable', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			return new WP_Error( 'openrouter_request_failed', self::error_message( $decoded, $code ), [ 'status' => $code ] );
		}

		$status = self::normalize_status( (string) ( $decoded['status'] ?? '' ) );
		$result = [ 'job_id' => $job_id, 'status' => $status ];
		if ( 'failed' === $status && ! empty( $decoded['error'] ) ) {
			$result['error'] = sanitize_text_field( (string) $decoded['error'] );
		}
		if ( 'completed' === $status ) {
			$urls = array_values( array_filter( array_map( 'esc_url_raw', (array) ( $decoded['unsigned_urls'] ?? [] ) ) ) );
			if ( empty( $urls ) ) {
				return new WP_Error( 'openrouter_invalid_response', __( 'OpenRouter reported a completed job with no downloadable video URLs.', 'worldgraph' ) );
			}
			$result['outputUrls'] = $urls;
			$result['output_media'] = array_map( static function ( string $url ): array {
				return [ 'kind' => 'video', 'url' => $url ];
			}, $urls );
		}

		return $result;
	}

	/** Authorization header the media importer replays against OpenRouter's protected content endpoint. */
	public static function download_headers( int $job_id ): array {
		$connection_id = absint( get_post_meta( $job_id, '_worldgraph_gen_connection_id', true ) );
		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return [];
		}

		$key = self::resolve_credential( (string) $connection['credential_reference'] );
		return '' === $key ? [] : [ 'Authorization' => 'Bearer ' . $key ];
	}

	/** Map OpenRouter job statuses to the generation batch processor's vocabulary. */
	private static function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		switch ( $status ) {
			case 'completed':
				return 'completed';
			case 'failed':
			case 'expired':
				return 'failed';
			case 'cancelled':
				return 'cancelled';
			default:
				return 'submitted';
		}
	}

	/** Fetch video generation models for unsaved or saved credentials. */
	private static function fetch_video_models( string $endpoint, string $credential_reference ) {
		$headers = self::headers( $credential_reference );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$response = wp_remote_get( self::api_root( $endpoint ) . '/videos/models', [
			'timeout' => 30,
			'headers' => $headers,
		] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'openrouter_unreachable', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			return new WP_Error( 'openrouter_request_failed', self::error_message( $decoded, $code ), [ 'status' => $code ] );
		}

		return array_values( array_filter( (array) ( $decoded['data'] ?? [] ), static function ( $model ): bool {
			return is_array( $model ) && ! empty( $model['id'] );
		} ) );
	}

	/** Resolve a saved Connection, validating its provider type. */
	private static function connection( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'openrouter' !== ( $connection['provider_type'] ?? '' ) ) {
			return new WP_Error( 'openrouter_connection_invalid', __( 'Select an OpenRouter Connection first.', 'worldgraph' ) );
		}

		return $connection;
	}

	/** Build authenticated JSON headers. */
	private static function headers( string $credential_reference ) {
		$key = self::resolve_credential( $credential_reference );
		if ( '' === $key ) {
			return new WP_Error( 'openrouter_credential_missing', __( 'Set an OpenRouter API key or env://OPENROUTER_API_KEY reference on this Connection.', 'worldgraph' ) );
		}

		return [ 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ];
	}

	/** Resolve a literal key or env:// environment-variable reference. */
	private static function resolve_credential( string $reference ): string {
		$reference = trim( $reference );
		if ( 0 === strpos( $reference, 'env://' ) ) {
			$name = substr( $reference, 6 );
			if ( ! preg_match( '/^[A-Z_][A-Z0-9_]*$/', $name ) ) {
				return '';
			}
			$value = getenv( $name );
			return false === $value ? '' : trim( (string) $value );
		}

		return $reference;
	}

	/** Normalize a configured endpoint to the OpenRouter API origin. */
	private static function endpoint( array $connection ): string {
		return self::api_root( (string) ( $connection['endpoint_url'] ?? '' ) );
	}

	/** Normalize a configured endpoint to the API origin. */
	private static function api_root( string $endpoint ): string {
		$endpoint = untrailingslashit( esc_url_raw( $endpoint ?: self::ENDPOINT ) );
		return (string) preg_replace( '#/v1$#', '', $endpoint ) . '/v1';
	}

	/** Extract a readable message from an OpenRouter error response. */
	private static function error_message( $decoded, int $code ): string {
		if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) {
			return sanitize_text_field( (string) $decoded['error']['message'] );
		}
		if ( is_array( $decoded ) && ! empty( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			return sanitize_text_field( $decoded['error'] );
		}

		return sprintf(
			/* translators: %d: HTTP response status code. */
			__( 'OpenRouter returned HTTP %d.', 'worldgraph' ),
			$code
		);
	}
}
