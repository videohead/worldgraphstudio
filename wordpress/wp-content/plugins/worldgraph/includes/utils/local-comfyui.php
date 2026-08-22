<?php
/**
 * HTTP client for a local ComfyUI API server.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WorldGraph\REST\Generation_Authorization;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Local_ComfyUI {
	/** Maximum media input buffered for ComfyUI's multipart upload (50MB). */
	private const MAX_INPUT_BYTES = 52428800;

	/** Normalized legacy generic-request seed explicitly supplied by the caller. */
	const EXPLICIT_SEED_META = '_worldgraph_gen_explicit_seed';

	/**
	 * Wizard slot marker for the single default local ComfyUI Template. One
	 * Connection can back many checkpoints/Templates; only one is auto-managed
	 * as the default for now.
	 */
	const TEMPLATE_SLOT = 'local_comfyui_text_to_image';

	/**
	 * Whether WordPress has a local ComfyUI URL. A workflow is always
	 * available: either a pasted custom one or the built-in default.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::endpoint();
	}

	/**
	 * Submit a workflow to ComfyUI.
	 *
	 * @param string $template Template post ID, slug, or wizard slot to run.
	 * @param string $prompt Text prompt.
	 * @param array  $parameters Generation parameters; `inputs` carries modality input slots.
	 * @param int    $connection_id Optional parent worldgraph_conn post ID, for log correlation.
	 * @return array|WP_Error
	 */
	public static function run_template( string $template, string $prompt, array $parameters, int $connection_id = 0 ) {
		if ( '' === self::endpoint() ) {
			Generation_Log::add( 'error', 'local_comfyui', 'Local ComfyUI is not configured.', [], '', $connection_id );
			return new WP_Error( 'local_comfyui_unconfigured', __( 'Set a local ComfyUI URL in World Graph Studio AI Settings before generating an asset.', 'worldgraph' ) );
		}

		$template_post = self::resolve_template( $template );
		$template_id   = $template_post ? (int) $template_post->ID : 0;
		$modality      = self::modality( $template_id );

		$inputs = isset( $parameters['inputs'] ) && is_array( $parameters['inputs'] ) ? $parameters['inputs'] : [];
		if ( '' !== $prompt && '' === trim( (string) ( $inputs['prompt'] ?? '' ) ) ) {
			$inputs['prompt'] = $prompt;
		}
		if ( ! array_key_exists( 'negative_prompt', $inputs ) ) {
			$inputs['negative_prompt'] = isset( $parameters['negative_prompt'] ) && is_scalar( $parameters['negative_prompt'] )
				? sanitize_textarea_field( (string) $parameters['negative_prompt'] )
				: '';
		}

		$job_id   = absint( $parameters['_worldgraph_job_id'] ?? 0 );
		if ( $job_id && metadata_exists( 'post', $job_id, '_worldgraph_gen_run_values' ) ) {
			$submitted = get_post_meta( $job_id, '_worldgraph_gen_run_values', true );
			$submitted = is_array( $submitted ) ? $submitted : [];
			$has_explicit_seed = array_key_exists( 'seed', $submitted )
				|| array_key_exists( 'noise_seed', $submitted );
			if ( metadata_exists( 'post', $job_id, self::EXPLICIT_SEED_META ) ) {
				$parameters['seed'] = (int) get_post_meta( $job_id, self::EXPLICIT_SEED_META, true );
				unset( $parameters['noise_seed'] );
				$has_explicit_seed = true;
			}
			if ( ! $has_explicit_seed ) {
				unset( $parameters['seed'], $parameters['noise_seed'] );
			}
		}
		$workflow = self::workflow( $template_id, $modality, $parameters );
		if ( is_wp_error( $workflow ) ) {
			Generation_Log::add( 'error', 'local_comfyui', $workflow->get_error_message(), [], '', $connection_id );
			return $workflow;
		}
		if ( empty( $workflow ) ) {
			return new WP_Error( 'local_comfyui_unconfigured', __( 'This Template has no runnable ComfyUI workflow.', 'worldgraph' ) );
		}

		$media_slots     = Generation_Modality::media_inputs( $modality );
		$requested_slots = [];
		$definitions     = Generation_Modality::inputs( $modality );
		foreach ( $media_slots as $slot ) {
			$value = $inputs[ $slot ] ?? '';
			if ( ! empty( $definitions[ $slot ]['required'] ) || ( is_scalar( $value ) && '' !== trim( (string) $value ) ) ) {
				$requested_slots[] = $slot;
			}
		}
		$workflow = Comfy_Graph::apply_media_placeholders( $workflow, $media_slots, $requested_slots );
		if ( is_wp_error( $workflow ) ) {
			$error_data = $workflow->get_error_data();
			Generation_Log::add( 'error', 'local_comfyui', $workflow->get_error_message(), is_array( $error_data ) ? $error_data : [], '', $connection_id );
			return $workflow;
		}

		$preflight = self::preflight( $template_id, $connection_id );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		$resolved = self::resolve_inputs( $modality, $inputs, $connection_id, $job_id );
		if ( is_wp_error( $resolved ) ) {
			Generation_Log::add( 'error', 'local_comfyui', $resolved->get_error_message(), [], '', $connection_id );
			return $resolved;
		}
		foreach ( Comfy_Graph::media_placeholders( $workflow ) as $slot ) {
			if ( '' !== (string) ( $resolved[ $slot ] ?? '' ) ) {
				continue;
			}

			$error = new WP_Error(
				'local_comfyui_missing_workflow_media_input',
				sprintf(
					/* translators: %s: media input slot name. */
					__( 'This workflow uses the %s media slot, but the Template binding did not resolve a file for it.', 'worldgraph' ),
					$slot
				)
			);
			Generation_Log::add( 'error', 'local_comfyui', $error->get_error_message(), [ 'slot' => $slot ], '', $connection_id );
			return $error;
		}

		Generation_Log::add( 'info', 'local_comfyui', 'Submitting workflow to ' . self::url( 'prompt' ), [ 'modality' => $modality, 'inputs' => $resolved ], '', $connection_id );

		$response = wp_remote_post( self::url( 'prompt' ), [
			'timeout' => 60,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'prompt'    => self::apply_inputs( $workflow, $resolved ),
				'client_id' => wp_generate_uuid4(),
			] ),
		] );

		$result = self::decode_response( $response, 'submit the workflow', $connection_id );
		if ( is_wp_error( $result ) ) {
			Generation_Log::add( 'error', 'local_comfyui', $result->get_error_message(), [], '', $connection_id );
		} else {
			Generation_Log::add( 'info', 'local_comfyui', 'Workflow submitted.', $result, (string) ( $result['prompt_id'] ?? '' ), $connection_id );
		}

		return $result;
	}

	/**
	 * Retrieve a local ComfyUI job status and output URLs.
	 *
	 * @param string $job_id ComfyUI prompt ID.
	 * @param int    $connection_id Optional parent worldgraph_conn post ID, for log correlation.
	 * @return array|WP_Error
	 */
	public static function get_job_status( string $job_id, int $connection_id = 0 ) {
		$response = wp_remote_get( self::url( 'history/' . rawurlencode( $job_id ) ), [ 'timeout' => 60 ] );
		$result   = self::decode_response( $response, 'retrieve the job history', $connection_id );
		if ( is_wp_error( $result ) ) {
			Generation_Log::add( 'error', 'local_comfyui', $result->get_error_message(), [], $job_id, $connection_id );
			return $result;
		}

		$history = $result[ $job_id ] ?? [];
		if ( empty( $history ) || ! is_array( $history ) ) {
			Generation_Log::add( 'debug', 'local_comfyui', 'No history yet; job still running.', [], $job_id, $connection_id );
			return [ 'status' => 'submitted' ];
		}
		if ( ! empty( $history['status']['status_str'] ) && 'error' === $history['status']['status_str'] ) {
			Generation_Log::add( 'error', 'local_comfyui', 'ComfyUI reported that the workflow failed.', $history, $job_id, $connection_id );
			return [ 'status' => 'failed', 'error' => __( 'ComfyUI reported that the workflow failed.', 'worldgraph' ) ];
		}

		// ComfyUI's SaveVideo node writes its output under the same "images"
		// output key as SaveImage, so a workflow with both a still frame and
		// its source video (e.g. an LTX-Video Template) can list either one
		// first depending on node execution order. Keep them separate so
		// `image_url` reliably points at a real image, not a video file.
		$image_urls = [];
		$video_urls = [];
		foreach ( (array) ( $history['outputs'] ?? [] ) as $output ) {
			foreach ( (array) ( $output['images'] ?? [] ) as $image ) {
				if ( empty( $image['filename'] ) ) {
					continue;
				}

				$ext = strtolower( pathinfo( (string) $image['filename'], PATHINFO_EXTENSION ) );
				if ( in_array( $ext, [ 'mp4', 'webm', 'mov', 'avi' ], true ) ) {
					$video_urls[] = self::view_url( $image );
				} else {
					$image_urls[] = self::view_url( $image );
				}
			}
		}

		$images = array_merge( $image_urls, $video_urls );
		if ( empty( $images ) ) {
			Generation_Log::add( 'debug', 'local_comfyui', 'History present but no output images yet.', [], $job_id, $connection_id );
			return [ 'status' => 'submitted' ];
		}

		Generation_Log::add( 'info', 'local_comfyui', 'Job completed with ' . count( $images ) . ' image(s).', [], $job_id, $connection_id );
		return [ 'status' => 'completed', 'image_url' => $image_urls[0] ?? $images[0], 'images' => $images ];
	}

	/**
	 * Get the configured ComfyUI base URL: the `worldgraph_comfy_local_url`
	 * option, falling back to a local `worldgraph_conn` record's
	 * endpoint URL so the two configuration surfaces cannot drift apart.
	 *
	 * @return string
	 */
	public static function endpoint(): string {
		$url = untrailingslashit( esc_url_raw( (string) get_option( 'worldgraph_comfy_local_url', '' ) ) );
		if ( '' !== $url ) {
			return $url;
		}

		$connection_id = Connection_Repository::get_default( 'comfyui', 'local' );
		$connection    = $connection_id ? Connection_Repository::get( $connection_id ) : null;
		if ( $connection && '' !== $connection['endpoint_url'] ) {
			return untrailingslashit( esc_url_raw( (string) $connection['endpoint_url'] ) );
		}

		return '';
	}

	/**
	 * Resolve the Template a generation job names. Accepts a Template post ID,
	 * post slug, or wizard slot marker, and falls back to the single default
	 * local ComfyUI Template so legacy jobs keep working.
	 *
	 * @param string $reference Template post ID, slug, or wizard slot.
	 * @return \WP_Post|null
	 */
	private static function resolve_template( string $reference ): ?\WP_Post {
		$reference = trim( $reference );

		if ( ctype_digit( $reference ) ) {
			$post = get_post( (int) $reference );
			if ( $post instanceof \WP_Post && 'worldgraph_template' === $post->post_type ) {
				return $post;
			}
		}

		if ( '' !== $reference ) {
			$posts = get_posts( [
				'post_type'      => 'worldgraph_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'name'           => sanitize_title( $reference ),
			] );
			if ( $posts ) {
				return $posts[0];
			}
		}

		return self::default_template();
	}

	/**
	 * The single default local ComfyUI Template record, or null when none has
	 * been configured yet.
	 *
	 * @return \WP_Post|null
	 */
	private static function default_template(): ?\WP_Post {
		$posts = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => 'worldgraph_wizard_slot', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => self::TEMPLATE_SLOT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );

		return $posts ? $posts[0] : null;
	}

	/**
	 * The modality a Template generates.
	 *
	 * @param int $template_id Template post ID, or 0 for none.
	 * @return string
	 */
	private static function modality( int $template_id ): string {
		return Generation_Modality::sanitize( $template_id ? (string) worldgraph_get_field_value( $template_id, 'modality' ) : '' );
	}

	/**
	 * Decode a Template's pasted API workflow, or build the modality's
	 * built-in graph from its checkpoint and parameter overrides.
	 *
	 * @param int    $template_id Template post ID, or 0 for none.
	 * @param string $modality    Modality slug.
	 * @param array  $runtime     Runtime parameter overrides for this job.
	 * @return array|WP_Error
	 */
	private static function workflow( int $template_id, string $modality, array $runtime = [] ) {
		$raw = $template_id
			? (string) worldgraph_get_field_value( $template_id, 'workflow_json' )
			: (string) get_option( 'worldgraph_comfy_local_workflow', '' );
		$workflow = json_decode( $raw, true );
		if ( is_array( $workflow ) && ! empty( $workflow ) ) {
			if ( Comfy_Graph::is_editor_graph( $workflow ) ) {
				$object_info = Comfy_Manifest::object_info( self::endpoint() );
				if ( is_wp_error( $object_info ) ) {
					return $object_info;
				}
				$workflow = Comfy_Graph::to_api( $workflow, $object_info );
				if ( is_wp_error( $workflow ) ) {
					return $workflow;
				}
			}

			return self::prepare_pasted_workflow( $template_id, $workflow, $runtime );
		}

		$settings = $template_id
			? Comfy_Manifest::template_settings( $template_id, $modality, $runtime )
			: [ 'checkpoint' => trim( (string) get_option( 'worldgraph_comfy_local_checkpoint', '' ) ) ];
		if ( ! $template_id ) {
			$runtime_keys = array_keys( Generation_Modality::default_settings( $modality ) );
			foreach ( $runtime_keys as $key ) {
				if ( isset( $runtime[ $key ] ) && is_scalar( $runtime[ $key ] ) ) {
					$settings[ $key ] = $runtime[ $key ];
				}
			}

			$size = isset( $runtime['size'] ) && is_scalar( $runtime['size'] ) ? trim( (string) $runtime['size'] ) : '';
			if ( preg_match( '/^(\d+)x(\d+)$/i', $size, $matches ) ) {
				$settings['width']  = (int) $matches[1];
				$settings['height'] = (int) $matches[2];
			}
		}
		if ( '' === trim( (string) ( $settings['checkpoint'] ?? '' ) ) ) {
			$settings['checkpoint'] = Comfy_Bootstrap::DEFAULT_CHECKPOINT;
		}

		$workflow = Generation_Modality::default_workflow( $modality, $settings );

		return $template_id ? Template_Run_Controls::apply_to_workflow( $template_id, $workflow, $runtime ) : $workflow;
	}

	/**
	 * A workflow pasted or imported from a provider keeps that template's demo
	 * prompt and fixed seed, so it would ignore the job prompt and repeat the
	 * same output. Give it per-job placeholders and a fresh seed before it runs.
	 *
	 * @param int   $template_id Template post ID.
	 * @param array $workflow    API-format workflow.
	 * @param array $runtime     Validated per-run values.
	 * @return array
	 */
	private static function prepare_pasted_workflow( int $template_id, array $workflow, array $runtime ): array {
		if ( false === strpos( (string) wp_json_encode( $workflow ), '{{prompt}}' ) ) {
			$workflow = Comfy_Graph::apply_prompt_placeholders( $workflow );
		}
		$workflow = Template_Run_Controls::apply_to_workflow( $template_id, $workflow, $runtime );

		$fixed_seed = false;
		foreach ( [ 'seed', 'noise_seed' ] as $seed_key ) {
			if ( isset( $runtime[ $seed_key ] ) && preg_match( '/^\d+$/', (string) $runtime[ $seed_key ] ) ) {
				$fixed_seed = true;
				break;
			}
		}

		return $fixed_seed ? $workflow : Comfy_Graph::randomize_seeds( $workflow );
	}

	/**
	 * Refuse to submit a job whose Template needs a node or model that this
	 * ComfyUI instance does not have, so the failure is reported in World Graph Studio
	 * instead of surfacing as an opaque ComfyUI execution error.
	 *
	 * @param int $template_id   Template post ID, or 0 when none is resolvable.
	 * @param int $connection_id Connection post ID, for log correlation.
	 * @return true|WP_Error
	 */
	private static function preflight( int $template_id, int $connection_id ) {
		if ( ! $template_id ) {
			return true;
		}

		$report = Comfy_Manifest::validate( $template_id );
		if ( is_wp_error( $report ) ) {
			// A catalog that cannot be read is a transient connectivity
			// problem, not a Template defect; let ComfyUI report it instead.
			Generation_Log::add( 'debug', 'local_comfyui', 'Requirement check skipped: ' . $report->get_error_message(), [], '', $connection_id );
			return true;
		}
		if ( ! empty( $report['ok'] ) ) {
			return true;
		}

		$problems = [];
		if ( ! empty( $report['missing_nodes'] ) ) {
			$problems[] = sprintf(
				/* translators: %s: comma-separated list of ComfyUI node class names. */
				__( 'missing node types: %s', 'worldgraph' ),
				implode( ', ', $report['missing_nodes'] )
			);
		}
		foreach ( $report['missing_models'] as $model ) {
			$problems[] = sprintf(
				/* translators: 1: model filename, 2: ComfyUI models sub-directory. */
				__( 'missing model %1$s (install into models/%2$s)', 'worldgraph' ),
				(string) $model['filename'],
				(string) $model['folder']
			);
		}

		$message = sprintf(
			/* translators: %s: semicolon-separated list of unmet requirements. */
			__( 'ComfyUI cannot run this Template yet: %s.', 'worldgraph' ),
			implode( '; ', $problems )
		);
		Generation_Log::add( 'error', 'local_comfyui', $message, $report, '', $connection_id );

		return new WP_Error( 'local_comfyui_requirements_missing', $message, $report );
	}

	/**
	 * Validate the modality's input slots and upload every media input to
	 * ComfyUI's input directory, returning a placeholder value map.
	 *
	 * @param string $modality      Modality slug.
	 * @param array  $inputs        Raw input slot values.
	 * @param int    $connection_id Connection post ID, for log correlation.
	 * @return array<string, string>|WP_Error
	 */
	private static function resolve_inputs( string $modality, array $inputs, int $connection_id, int $job_id = 0 ) {
		$resolved = [];
		foreach ( Generation_Modality::inputs( $modality ) as $slot => $definition ) {
			$value = $inputs[ $slot ] ?? '';
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( '' === $value ) {
				if ( ! empty( $definition['required'] ) ) {
					return new WP_Error(
						'local_comfyui_missing_input',
						sprintf(
							/* translators: 1: input label, 2: modality label. */
							__( '%1$s is required for %2$s generations.', 'worldgraph' ),
							(string) $definition['label'],
							(string) Generation_Modality::get( $modality )['label']
						)
					);
				}

				$resolved[ $slot ] = '';
				continue;
			}

			if ( 'media' !== ( $definition['type'] ?? '' ) ) {
				$resolved[ $slot ] = $value;
				continue;
			}

			$uploaded = self::upload_input( $value, $connection_id, $job_id );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}

			$resolved[ $slot ] = $uploaded;
		}

		return $resolved;
	}

	/**
	 * Push a media input into ComfyUI's input directory and return the name
	 * its Load* nodes should reference.
	 *
	 * @param string $reference     Attachment ID or validated HTTPS URL.
	 * @param int    $connection_id Connection post ID, for log correlation.
	 * @param int    $job_id        Queued job ID, for background authorization.
	 * @return string|WP_Error
	 */
	private static function upload_input( string $reference, int $connection_id, int $job_id = 0 ) {
		$path    = '';
		$cleanup = false;

		if ( ctype_digit( $reference ) ) {
			if ( $job_id ) {
				$authorized = Generation_Authorization::authorize_background_media( $job_id, (int) $reference );
				if ( is_wp_error( $authorized ) ) {
					return $authorized;
				}
			}
			$path = (string) get_attached_file( (int) $reference );
		} elseif ( 0 === stripos( $reference, 'https://' ) && wp_http_validate_url( $reference ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$path = (string) wp_tempnam( 'worldgraph-comfy-input' );
			if ( '' === $path ) {
				return new WP_Error( 'local_comfyui_input_download_failed', __( 'Unable to create temporary storage for the generation input.', 'worldgraph' ) );
			}
			$cleanup = true;
			$download = wp_safe_remote_get( $reference, [
				'timeout'             => 60,
				'stream'              => true,
				'filename'            => $path,
				'limit_response_size' => self::MAX_INPUT_BYTES + 1,
			] );
			if ( is_wp_error( $download ) || wp_remote_retrieve_response_code( $download ) < 200 || wp_remote_retrieve_response_code( $download ) >= 300 ) {
				wp_delete_file( $path );
				return new WP_Error( 'local_comfyui_input_download_failed', sprintf( __( 'Unable to download the generation input %s.', 'worldgraph' ), $reference ) );
			}
		} else {
			return new WP_Error( 'local_comfyui_input_invalid', __( 'A generation input must be a WordPress attachment ID or a validated HTTPS URL.', 'worldgraph' ) );
		}

		if ( '' === $path || ! is_readable( $path ) ) {
			if ( $cleanup && '' !== $path ) {
				wp_delete_file( $path );
			}
			return new WP_Error( 'local_comfyui_input_unreadable', __( 'A generation input file could not be read.', 'worldgraph' ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::MAX_INPUT_BYTES ) {
			if ( $cleanup ) {
				wp_delete_file( $path );
			}
			return new WP_Error( 'local_comfyui_input_too_large', __( 'A generation input must be a non-empty file no larger than 50MB.', 'worldgraph' ) );
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			if ( $cleanup ) {
				wp_delete_file( $path );
			}
			return new WP_Error( 'local_comfyui_input_unreadable', __( 'A generation input file could not be read.', 'worldgraph' ) );
		}

		$filename = wp_basename( $path );
		$boundary = wp_generate_uuid4();
		$body     = '';
		foreach ( [ 'type' => 'input', 'overwrite' => 'true', 'subfolder' => 'worldgraph' ] as $field => $field_value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"\r\n\r\n{$field_value}\r\n";
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"image\"; filename=\"{$filename}\"\r\n";
		$body .= 'Content-Type: ' . ( wp_check_filetype( $filename )['type'] ?: 'application/octet-stream' ) . "\r\n\r\n";
		$body .= $bytes . "\r\n--{$boundary}--\r\n";

		if ( $cleanup ) {
			wp_delete_file( $path );
		}

		$response = wp_remote_post( self::url( 'upload/image' ), [
			'timeout' => 120,
			'headers' => [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ],
			'body'    => $body,
		] );

		$result = self::decode_response( $response, 'upload a generation input', $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['name'] ) ) {
			return new WP_Error( 'local_comfyui_input_upload_failed', __( 'ComfyUI did not accept a generation input file.', 'worldgraph' ) );
		}

		$subfolder = trim( (string) ( $result['subfolder'] ?? '' ), '/' );

		return '' !== $subfolder ? $subfolder . '/' . $result['name'] : (string) $result['name'];
	}

	/**
	 * Replace `{{slot}}` placeholders in a ComfyUI API workflow with the
	 * resolved input values for the job.
	 *
	 * @param mixed $value  Workflow value.
	 * @param array $inputs Resolved slot => value map.
	 * @return mixed
	 */
	private static function apply_inputs( $value, array $inputs ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::apply_inputs( $item, $inputs );
			}
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$search  = [];
		$replace = [];
		foreach ( $inputs as $slot => $slot_value ) {
			$search[]  = '{{' . $slot . '}}';
			$replace[] = (string) $slot_value;
		}

		return str_replace( $search, $replace, $value );
	}

	/**
	 * Build a URL relative to the configured API endpoint.
	 *
	 * @param string $path Endpoint path.
	 * @return string
	 */
	private static function url( string $path ): string {
		return self::endpoint() . '/' . ltrim( $path, '/' );
	}

	/**
	 * Build a downloadable ComfyUI output image URL.
	 *
	 * @param array $image ComfyUI output descriptor.
	 * @return string
	 */
	private static function view_url( array $image ): string {
		return add_query_arg( [
			'filename'  => (string) $image['filename'],
			'subfolder' => (string) ( $image['subfolder'] ?? '' ),
			'type'      => (string) ( $image['type'] ?? 'output' ),
		], self::url( 'view' ) );
	}

	/**
	 * Validate and decode a ComfyUI HTTP response.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param string         $action Action for an error message.
	 * @return array|WP_Error
	 */
	private static function decode_response( $response, string $action, int $connection_id = 0 ) {
		if ( is_wp_error( $response ) ) {
			Generation_Log::add( 'error', 'local_comfyui', sprintf( 'Unreachable while trying to %s: %s', $action, $response->get_error_message() ), [], '', $connection_id );
			return new WP_Error( 'local_comfyui_unreachable', sprintf( __( 'Unable to %s through local ComfyUI: %s', 'worldgraph' ), $action, $response->get_error_message() ) );
		}
		if ( wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			Generation_Log::add( 'error', 'local_comfyui', sprintf( 'HTTP %d while trying to %s.', wp_remote_retrieve_response_code( $response ), $action ), [ 'body' => wp_remote_retrieve_body( $response ) ], '', $connection_id );
			return new WP_Error( 'local_comfyui_request_failed', sprintf( __( 'Local ComfyUI could not %s.', 'worldgraph' ), $action ) );
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $result ) ) {
			Generation_Log::add( 'error', 'local_comfyui', sprintf( 'Invalid response body while trying to %s.', $action ), [ 'body' => wp_remote_retrieve_body( $response ) ], '', $connection_id );
			return new WP_Error( 'local_comfyui_invalid_response', __( 'Local ComfyUI returned an invalid response.', 'worldgraph' ) );
		}

		return $result;
	}
}
