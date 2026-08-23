<?php
/**
 * VideoDraft hosted MCP client.
 *
 * Implements the JSON-RPC transport used by the VideoDraft CLI directly in
 * WordPress so generation and project sync do not depend on a Node sidecar.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** VideoDraft API adapter. */
class VideoDraft_API {

	/** Default hosted MCP endpoint. */
	const ENDPOINT = 'https://app.videodraft.ai/api/mcp';

	/** Tools required by the generation connection and bidirectional sync plugin. */
	const REQUIRED_TOOLS = [
		'generate_image',
		'generate_video',
		'check_generation_status',
		'list_projects',
		'get_project',
		'get_project_schema',
		'create_blank_project',
		'create_project_checkpoint',
		'update_project',
	];

	/** Generation tools that may be selected by a World Graph Studio Template. */
	const GENERATION_TOOLS = [
		'generate_image',
		'generate_video',
		'generate_audio',
		'generate_voiceover',
		'generate_music',
		'generate_sound_effect',
	];

	/** Project tools exposed to the sync plugin. */
	const PROJECT_TOOLS = [
		'whoami',
		'list_projects',
		'get_project',
		'get_project_schema',
		'create_blank_project',
		'create_project_checkpoint',
		'update_project',
	];

	/** Hosted MCP request timeout, in seconds. */
	const TIMEOUT = 300;

	/** Presigned media upload timeout, in seconds. */
	const UPLOAD_TIMEOUT = 600;

	/** Maximum local attachment uploaded to VideoDraft (200 MB). */
	const MAX_UPLOAD_BYTES = 209715200;

	/** Test an unsaved setup-wizard configuration. */
	public static function test_configuration( string $endpoint, string $credential_reference ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'videodraft_curl_required', __( 'VideoDraft requires the PHP cURL extension for streamed reference uploads.', 'worldgraph' ) );
		}
		$catalog = self::tool_catalog_for( $endpoint, $credential_reference );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		return array_values( array_filter( array_map( static function ( $tool ): string {
			return is_array( $tool ) ? (string) ( $tool['name'] ?? '' ) : '';
		}, $catalog ) ) );
	}

	/** Return tool names advertised for a saved VideoDraft Connection. */
	public static function available_tools( int $connection_id ) {
		$catalog = self::tool_catalog( $connection_id );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		return array_values( array_filter( array_map( static function ( $tool ): string {
			return is_array( $tool ) ? (string) ( $tool['name'] ?? '' ) : '';
		}, $catalog ) ) );
	}

	/** Return live tool definitions for a saved VideoDraft Connection. */
	public static function tool_catalog( int $connection_id ) {
		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		return self::tool_catalog_for( self::endpoint( $connection ), (string) $connection['credential_reference'] );
	}

	/**
	 * Run a VideoDraft generation Template.
	 *
	 * `$template` is a live MCP tool name such as generate_image. Provider
	 * parameters are filtered to that tool's documented input surface.
	 */
	public static function run_template( string $template, string $prompt, array $parameters, int $connection_id = 0 ) {
		$tool = self::normalize_tool_name( $template );
		if ( ! in_array( $tool, self::GENERATION_TOOLS, true ) ) {
			return new WP_Error( 'videodraft_template_invalid', __( 'The selected Template is not a supported VideoDraft generation tool.', 'worldgraph' ) );
		}

		$job_id = absint( $parameters['_worldgraph_job_id'] ?? 0 );
		$cached_request = 'generate_audio' === $tool && $job_id
			? get_post_meta( $job_id, '_worldgraph_videodraft_resolved_request', true )
			: [];
		if ( is_array( $cached_request ) && $tool === ( $cached_request['tool'] ?? '' ) && is_array( $cached_request['arguments'] ?? null ) ) {
			foreach ( array_map( 'absint', (array) ( $cached_request['attachment_ids'] ?? [] ) ) as $attachment_id ) {
				$authorized = self::authorize_attachment( $attachment_id, $job_id );
				if ( is_wp_error( $authorized ) ) {
					return $authorized;
				}
			}
			$arguments = $cached_request['arguments'];
		} else {
			$arguments = self::generation_arguments( $tool, $prompt, $parameters, $connection_id );
			if ( is_wp_error( $arguments ) ) {
				return $arguments;
			}
			if ( 'generate_audio' === $tool && $job_id ) {
				$attachment_ids = [];
				foreach ( (array) ( $parameters['inputs'] ?? [] ) as $value ) {
					if ( is_numeric( $value ) && absint( $value ) ) {
						$attachment_ids[] = absint( $value );
					}
				}
				$request_cache = [
					'tool'           => $tool,
					'arguments'      => $arguments,
					'attachment_ids' => array_values( array_unique( $attachment_ids ) ),
				];
				update_post_meta( $job_id, '_worldgraph_videodraft_resolved_request', wp_slash( $request_cache ) );
				if ( $request_cache !== get_post_meta( $job_id, '_worldgraph_videodraft_resolved_request', true ) ) {
					return new WP_Error( 'videodraft_retry_state_unavailable', __( 'WordPress could not persist the recoverable VideoDraft audio request.', 'worldgraph' ) );
				}
			}
		}

		$result = self::call_tool( $tool, $arguments, $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::normalize_generation_result( $result, $tool );
	}

	/** Poll an asynchronous VideoDraft image or video generation. */
	public static function get_job_status( string $job_id, int $connection_id = 0, string $template = '' ) {
		if ( '' === trim( $job_id ) ) {
			return new WP_Error( 'videodraft_job_id_missing', __( 'The VideoDraft generation job ID is missing.', 'worldgraph' ) );
		}

		$result = self::call_tool( 'check_generation_status', [ 'job_id' => $job_id ], $connection_id, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::normalize_generation_result( $result, self::normalize_tool_name( $template ) );
	}

	/** Whether Seed Audio should be reconciled again with the same idempotency key. */
	public static function is_retryable_audio_error( WP_Error $error ): bool {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];
		$structured = is_array( $data['structured_content'] ?? null ) ? $data['structured_content'] : [];
		if ( ! empty( $structured['retryable'] ) ) {
			return true;
		}
		$code = $data['rpc_code'] ?? $data['status'] ?? null;
		if ( ( is_numeric( $code ) && in_array( (int) $code, [ 0, 408, 502, 503, 504 ], true ) ) || in_array( $error->get_error_code(), [ 'videodraft_unreachable', 'videodraft_invalid_response' ], true ) ) {
			return true;
		}

		return (bool) preg_match( '/already in progress|still being reconciled|settlement is still pending|recovery_pending/i', $error->get_error_message() );
	}

	/**
	 * Call one allowlisted VideoDraft tool for the sync or generation adapters.
	 *
	 * @return array|WP_Error
	 */
	public static function call_tool( string $name, array $arguments, int $connection_id, bool $internal = false ) {
		$allowed = array_merge( self::GENERATION_TOOLS, self::PROJECT_TOOLS, [ 'check_generation_status' ] );
		if ( $internal ) {
			$allowed = array_merge( $allowed, [ 'create_media_upload', 'finalize_media_upload' ] );
		}
		if ( ! in_array( $name, $allowed, true ) ) {
			return new WP_Error( 'videodraft_tool_not_allowed', __( 'That VideoDraft tool is not available to this integration.', 'worldgraph' ) );
		}

		$connection = self::connection( $connection_id );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$result = self::request_to(
			self::endpoint( $connection ),
			(string) $connection['credential_reference'],
			'tools/call',
			[ 'name' => $name, 'arguments' => (object) $arguments ]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::decode_tool_result( $result, $name );
	}

	/** Convert a Template prompt, defaults, and media bindings to tool args. */
	private static function generation_arguments( string $tool, string $prompt, array $parameters, int $connection_id ) {
		$inputs = is_array( $parameters['inputs'] ?? null ) ? $parameters['inputs'] : [];
		$job_id = absint( $parameters['_worldgraph_job_id'] ?? 0 );
		$resolved_inputs = $job_id ? get_post_meta( $job_id, '_worldgraph_videodraft_resolved_inputs', true ) : [];
		$resolved_inputs = is_array( $resolved_inputs ) ? $resolved_inputs : [];
		unset( $parameters['inputs'], $parameters['provider_schema'], $parameters['_worldgraph_job_id'] );

		$allowed = [
			'generate_image' => [ 'model', 'aspect_ratio', 'resolution', 'quality', 'rendering_speed', 'num_images', 'seed', 'reference_images', 'video_url', 'style', 'project_id', 'session_id', 'scene_index', 'shot_index' ],
			'generate_video' => [ 'model', 'aspect_ratio', 'duration_seconds', 'resolution', 'quality', 'generate_audio', 'allow_real_people', 'start_image_url', 'end_image_url', 'reference_images', 'reference_videos', 'reference_audio', 'elements', 'voice_ids', 'multi_prompt', 'keyframes', 'negative_prompt', 'camera_fixed', 'seed', 'project_id', 'session_id', 'scene_index', 'shot_index' ],
			'generate_audio' => [ 'voice', 'audio_urls', 'image_url', 'output_format', 'sample_rate', 'speed', 'volume', 'pitch', 'project_id', 'session_id', 'idempotency_key' ],
			'generate_voiceover' => [ 'voice_id', 'target_language', 'project_id', 'session_id', 'scene_index' ],
			'generate_music' => [ 'model', 'length_seconds', 'force_instrumental', 'image_urls', 'project_id', 'attach_to_project_id', 'volume', 'enabled', 'session_id' ],
			'generate_sound_effect' => [ 'duration_seconds', 'prompt_influence', 'project_id', 'session_id' ],
		];

		$arguments = [];
		foreach ( $allowed[ $tool ] as $key ) {
			if ( array_key_exists( $key, $parameters ) && null !== $parameters[ $key ] && '' !== $parameters[ $key ] ) {
				$arguments[ $key ] = $parameters[ $key ];
			}
		}

		if ( isset( $parameters['size'] ) && ! isset( $arguments['resolution'] ) ) {
			$arguments['resolution'] = (string) $parameters['size'];
		}
		if ( isset( $parameters['aspect_ratio'] ) ) {
			$arguments['aspect_ratio'] = (string) $parameters['aspect_ratio'];
		}
		if ( 'generate_audio' === $tool && isset( $arguments['output_format'] ) ) {
			$arguments['output_format'] = strtolower( sanitize_key( (string) $arguments['output_format'] ) );
			if ( ! in_array( $arguments['output_format'], [ 'mp3', 'wav', 'ogg_opus' ], true ) ) {
				return new WP_Error( 'videodraft_audio_format_unsupported', __( 'Choose MP3, WAV, or Ogg/Opus so WordPress can store the generated audio.', 'worldgraph' ) );
			}
		}

		$media = [];
		foreach ( $inputs as $slot => $value ) {
			$slot_key = sanitize_key( (string) $slot );
			$cached = is_array( $resolved_inputs[ $slot_key ] ?? null ) ? $resolved_inputs[ $slot_key ] : [];
			$cached_url = absint( $cached['attachment_id'] ?? 0 ) === absint( $value ) ? (string) ( $cached['url'] ?? '' ) : '';
			$url = self::resolve_media_input( $value, $connection_id, $job_id, $cached_url );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			if ( '' !== $url ) {
				$media[ $slot_key ] = $url;
				if ( $job_id && is_numeric( $value ) ) {
					$resolved_inputs[ $slot_key ] = [
						'attachment_id' => absint( $value ),
						'url'           => $url,
					];
					update_post_meta( $job_id, '_worldgraph_videodraft_resolved_inputs', $resolved_inputs );
				}
			}
		}

		if ( 'generate_image' === $tool ) {
			if ( ! empty( $media['image'] ) ) {
				$arguments['reference_images'] = [ $media['image'] ];
			}
			if ( ! empty( $media['video'] ) ) {
				$arguments['video_url'] = $media['video'];
			}
		} elseif ( 'generate_video' === $tool ) {
			if ( ! empty( $media['image'] ) ) {
				$arguments['reference_images'] = [ $media['image'] ];
			}
			if ( ! empty( $media['start_frame'] ) ) {
				$arguments['start_image_url'] = $media['start_frame'];
			}
			if ( ! empty( $media['end_frame'] ) ) {
				$arguments['end_image_url'] = $media['end_frame'];
			}
			if ( ! empty( $media['video'] ) ) {
				$arguments['reference_videos'] = [ $media['video'] ];
			}
			if ( ! empty( $media['audio'] ) ) {
				$arguments['reference_audio'] = [ $media['audio'] ];
			}
		} elseif ( 'generate_audio' === $tool ) {
			if ( ! empty( $media['audio'] ) ) {
				$arguments['audio_urls'] = [ $media['audio'] ];
			}
			if ( ! empty( $media['image'] ) ) {
				$arguments['image_url'] = $media['image'];
			}
		} elseif ( 'generate_music' === $tool && ! empty( $media['image'] ) ) {
			$arguments['image_urls'] = [ $media['image'] ];
		}

		$prompt_key = 'generate_voiceover' === $tool ? 'text' : 'prompt';
		$arguments[ $prompt_key ] = $prompt;

		return array_filter( $arguments, static function ( $value ): bool {
			return null !== $value && '' !== $value && [] !== $value;
		} );
	}

	/** Resolve a public URL or upload a local WordPress attachment. */
	private static function resolve_media_input( $value, int $connection_id, int $job_id, string $cached_url = '' ) {
		if ( is_numeric( $value ) ) {
			$attachment_id = absint( $value );
			$authorized = self::authorize_attachment( $attachment_id, $job_id );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}

			$cached_url = esc_url_raw( $cached_url );
			if ( filter_var( $cached_url, FILTER_VALIDATE_URL ) ) {
				return $cached_url;
			}

			return self::upload_attachment( $attachment_id, $connection_id, $job_id, true );
		}

		$url = esc_url_raw( (string) $value );
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}

	/** Recheck that the queued requester may still use a local attachment. */
	private static function authorize_attachment( int $attachment_id, int $job_id ) {
		$filter = 'worldgraph_generation_background_media_authorization';
		if ( ! has_filter( $filter ) ) {
			return new WP_Error( 'videodraft_attachment_authorization_unavailable', __( 'The queued attachment authorization policy is unavailable.', 'worldgraph' ) );
		}
		$authorized = apply_filters( $filter, true, $job_id, $attachment_id );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		return true === $authorized
			? true
			: new WP_Error( 'videodraft_attachment_forbidden', __( 'This generation job is not allowed to upload the selected attachment.', 'worldgraph' ) );
	}

	/** Upload one WordPress attachment through VideoDraft's presigned flow. */
	private static function upload_attachment( int $attachment_id, int $connection_id, int $job_id, bool $authorized = false ) {
		if ( ! $authorized ) {
			$authorization = self::authorize_attachment( $attachment_id, $job_id );
			if ( is_wp_error( $authorization ) ) {
				return $authorization;
			}
		}
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'videodraft_attachment_unreadable', __( 'A bound media attachment could not be read for VideoDraft.', 'worldgraph' ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error( 'videodraft_attachment_too_large', __( 'A bound media attachment is empty or exceeds the VideoDraft upload limit.', 'worldgraph' ) );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( '' === $mime ) {
			$file_type = wp_check_filetype( basename( $path ) );
			$mime      = (string) ( $file_type['type'] ?? '' );
		}
		if ( ! preg_match( '#^(?:image|video|audio)/#i', $mime ) ) {
			return new WP_Error( 'videodraft_attachment_type_unsupported', __( 'VideoDraft reference uploads must be image, video, or audio attachments.', 'worldgraph' ) );
		}
		$created = self::call_tool( 'create_media_upload', [
			'filename'     => basename( $path ),
			'content_type' => $mime,
		], $connection_id, true );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$upload_url = esc_url_raw( (string) ( $created['upload_url'] ?? $created['uploadUrl'] ?? '' ) );
		$file_path  = (string) ( $created['file_path'] ?? $created['filePath'] ?? '' );
		if ( '' === $upload_url || '' === $file_path ) {
			return new WP_Error( 'videodraft_upload_contract_invalid', __( 'VideoDraft did not return a usable media upload target.', 'worldgraph' ) );
		}

		$uploaded = self::put_file( $upload_url, $path, $mime, (int) $size );
		if ( is_wp_error( $uploaded ) ) {
			return $uploaded;
		}

		$finalized = self::call_tool( 'finalize_media_upload', [
			'file_path'         => $file_path,
			'original_filename' => basename( $path ),
		], $connection_id, true );
		if ( is_wp_error( $finalized ) ) {
			return $finalized;
		}

		$url = esc_url_raw( (string) ( $finalized['url'] ?? $finalized['cdn_url'] ?? $finalized['cdnUrl'] ?? $finalized['public_url'] ?? $finalized['publicUrl'] ?? '' ) );
		return '' !== $url ? $url : new WP_Error( 'videodraft_upload_finalize_invalid', __( 'VideoDraft did not return a public media URL.', 'worldgraph' ) );
	}

	/** Normalize VideoDraft job states and output URLs for Generation_Batch. */
	private static function normalize_generation_result( array $result, string $tool ): array {
		$status = self::normalize_status( (string) ( $result['status'] ?? $result['state'] ?? '' ) );
		$job_id = (string) ( $result['job_id'] ?? $result['jobId'] ?? $result['id'] ?? '' );
		$urls   = self::output_urls( $result );

		if ( '' === $status ) {
			$status = ! empty( $urls ) ? 'completed' : ( '' !== $job_id ? 'submitted' : ( ! empty( $result['success'] ) ? 'completed' : 'submitted' ) );
		}
		$result['status'] = $status;
		if ( '' !== $job_id ) {
			$result['job_id'] = $job_id;
		}
		if ( ! empty( $urls ) ) {
			$result['outputUrls'] = $urls;
			$kind = str_contains( $tool, 'video' ) ? 'video' : ( in_array( $tool, [ 'generate_audio', 'generate_voiceover', 'generate_music', 'generate_sound_effect' ], true ) ? 'audio' : 'image' );
			$result['output_media'] = array_map( static function ( string $url ) use ( $kind ): array {
				return [ 'kind' => $kind, 'url' => $url ];
			}, $urls );
		}

		return $result;
	}

	/** Extract documented top-level provider output URLs. */
	private static function output_urls( array $result ): array {
		$urls = [];
		$explicit_keys = [ 'outputUrls', 'output_urls', 'outputUrl', 'output_url', 'video_url', 'videoUrl', 'image_url', 'imageUrl', 'audio_url', 'audioUrl', 'speech_url', 'public_url', 'url' ];
		foreach ( $explicit_keys as $key ) {
			foreach ( is_array( $result[ $key ] ?? null ) ? $result[ $key ] : [ $result[ $key ] ?? null ] as $candidate ) {
				if ( is_string( $candidate ) && preg_match( '#^https?://#i', $candidate ) && filter_var( $candidate, FILTER_VALIDATE_URL ) ) {
					$urls[] = $candidate;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/** Stream an attachment to a trusted VideoDraft presigned HTTPS URL. */
	private static function put_file( string $url, string $path, string $mime, int $size ) {
		if ( ! function_exists( 'curl_init' ) || ! wp_http_validate_url( $url ) || ! str_starts_with( strtolower( $url ), 'https://' ) ) {
			return new WP_Error( 'videodraft_upload_transport_unavailable', __( 'A secure streaming upload transport is not available for VideoDraft.', 'worldgraph' ) );
		}
		$file = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $file ) {
			return new WP_Error( 'videodraft_attachment_unreadable', __( 'A bound media attachment could not be read for VideoDraft.', 'worldgraph' ) );
		}
		$handle = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		if ( false === $handle ) {
			fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'videodraft_upload_transport_unavailable', __( 'A secure streaming upload transport is not available for VideoDraft.', 'worldgraph' ) );
		}
		$options = [
			CURLOPT_UPLOAD         => true,
			CURLOPT_INFILE         => $file,
			CURLOPT_INFILESIZE     => $size,
			CURLOPT_HTTPHEADER     => [ 'Content-Type: ' . $mime, 'Content-Length: ' . $size ],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT        => self::UPLOAD_TIMEOUT,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
		];
		$ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		if ( is_readable( $ca_bundle ) ) {
			$options[ CURLOPT_CAINFO ] = $ca_bundle;
		}
		if ( class_exists( '\\WP_HTTP_Proxy' ) ) {
			$proxy = new \WP_HTTP_Proxy();
			if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
				$options[ CURLOPT_PROXY ]     = $proxy->host();
				$options[ CURLOPT_PROXYPORT ] = (int) $proxy->port();
				if ( $proxy->use_authentication() ) {
					$options[ CURLOPT_PROXYUSERPWD ] = $proxy->authentication();
				}
			}
		}
		curl_setopt_array( $handle, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
		$result = curl_exec( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		curl_close( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( false === $result || $status < 200 || $status >= 300 ) {
			return new WP_Error( 'videodraft_upload_failed', __( 'VideoDraft could not receive a bound media attachment.', 'worldgraph' ), [ 'status' => $status ] );
		}
		return true;
	}

	/** Map provider states to the generation queue's terminal vocabulary. */
	private static function normalize_status( string $status ): string {
		$status = strtolower( str_replace( [ ' ', '-' ], '_', trim( $status ) ) );
		if ( in_array( $status, [ 'completed', 'complete', 'finished', 'succeeded', 'success', 'ok' ], true ) ) {
			return 'completed';
		}
		if ( in_array( $status, [ 'failed', 'failure', 'error' ], true ) ) {
			return 'failed';
		}
		if ( in_array( $status, [ 'cancelled', 'canceled' ], true ) ) {
			return 'cancelled';
		}
		if ( in_array( $status, [ 'submitted', 'queued', 'pending', 'processing', 'in_progress', 'running' ], true ) ) {
			return 'submitted';
		}

		return '';
	}

	/** Fetch live MCP tool schemas. */
	private static function tool_catalog_for( string $endpoint, string $credential_reference ) {
		$result = self::request_to( $endpoint, $credential_reference, 'tools/list', [] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_values( array_filter( (array) ( $result['tools'] ?? [] ), static function ( $tool ): bool {
			return is_array( $tool ) && ! empty( $tool['name'] );
		} ) );
	}

	/** Send one JSON-RPC request to VideoDraft's stateless MCP endpoint. */
	private static function request_to( string $endpoint, string $credential_reference, string $method, array $params ) {
		$endpoint = self::normalize_endpoint( $endpoint );
		$token    = self::resolve_credential( $credential_reference );
		if ( '' === $token ) {
			return new WP_Error( 'videodraft_credential_missing', __( 'Set a VideoDraft personal access token or env://VIDEODRAFT_API_KEY reference on this Connection.', 'worldgraph' ) );
		}

		$response = wp_safe_remote_post( $endpoint, [
			'timeout'            => self::TIMEOUT,
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
			'headers' => [
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			'body' => wp_json_encode( [
				'jsonrpc' => '2.0',
				'id'      => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'worldgraph_', true ),
				'method'  => $method,
				'params'  => (object) $params,
			] ),
		] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'videodraft_unreachable', __( 'World Graph Studio could not reach VideoDraft.', 'worldgraph' ) );
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'videodraft_request_failed',
				sprintf(
					/* translators: %d: HTTP response status code. */
					__( 'VideoDraft returned HTTP %d.', 'worldgraph' ),
					$status
				),
				[ 'status' => $status ]
			);
		}
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'videodraft_invalid_response', __( 'VideoDraft returned an invalid JSON response.', 'worldgraph' ) );
		}
		if ( isset( $payload['error'] ) ) {
			$message = sanitize_text_field( (string) ( $payload['error']['message'] ?? __( 'VideoDraft returned a JSON-RPC error.', 'worldgraph' ) ) );
			return new WP_Error( 'videodraft_rpc_error', $message, [
				'rpc_code' => $payload['error']['code'] ?? null,
				'rpc_data' => $payload['error']['data'] ?? null,
			] );
		}

		return is_array( $payload['result'] ?? null ) ? $payload['result'] : [];
	}

	/** Decode JSON stored in an MCP text content block. */
	private static function decode_tool_result( array $result, string $tool ) {
		$text = '';
		foreach ( (array) ( $result['content'] ?? [] ) as $content ) {
			if ( is_array( $content ) && isset( $content['text'] ) ) {
				$text = (string) $content['text'];
				break;
			}
		}
		if ( ! empty( $result['isError'] ) ) {
			$message = '' !== $text ? substr( sanitize_text_field( wp_strip_all_tags( $text ) ), 0, 500 ) : __( 'VideoDraft tool call failed.', 'worldgraph' );
			return new WP_Error( 'videodraft_tool_error', $message, [
				'tool'               => $tool,
				'structured_content' => is_array( $result['structuredContent'] ?? null ) ? $result['structuredContent'] : [],
			] );
		}
		if ( '' !== $text ) {
			$decoded = json_decode( $text, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return $result;
	}

	/** Resolve and validate a saved VideoDraft Connection. */
	private static function connection( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) || 'videodraft' !== ( $connection['provider_type'] ?? '' ) || 'disabled' === ( $connection['status'] ?? '' ) ) {
			return new WP_Error( 'videodraft_connection_invalid', __( 'Select an available VideoDraft Connection first.', 'worldgraph' ) );
		}

		return $connection;
	}

	/** Resolve a saved Connection's MCP endpoint. */
	private static function endpoint( array $connection ): string {
		return self::normalize_endpoint( (string) ( $connection['mcp_endpoint_url'] ?: $connection['endpoint_url'] ?: self::ENDPOINT ) );
	}

	/** Accept either the application base URL or its full MCP URL. */
	private static function normalize_endpoint( string $endpoint ): string {
		$endpoint = untrailingslashit( esc_url_raw( $endpoint ?: self::ENDPOINT ) );
		if ( ! str_ends_with( $endpoint, '/api/mcp' ) ) {
			$endpoint .= '/api/mcp';
		}

		return $endpoint;
	}

	/** Resolve a literal token or an env:// environment-variable reference. */
	private static function resolve_credential( string $reference ): string {
		$reference = trim( $reference );
		if ( str_starts_with( $reference, 'env://' ) ) {
			$name = substr( $reference, 6 );
			if ( ! preg_match( '/^[A-Z_][A-Z0-9_]*$/', $name ) ) {
				return '';
			}
			$value = getenv( $name );
			return false === $value ? '' : trim( (string) $value );
		}

		return $reference;
	}

	/** Strip the optional mcp: prefix used by some older Template records. */
	private static function normalize_tool_name( string $template ): string {
		$template = trim( $template );
		return str_starts_with( $template, 'mcp:' ) ? substr( $template, 4 ) : $template;
	}
}
