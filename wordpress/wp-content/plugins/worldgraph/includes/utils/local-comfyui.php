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
	 * @param int $connection_id Optional Connection whose endpoint takes precedence.
	 * @return bool
	 */
	public static function is_configured( int $connection_id = 0 ): bool {
		return '' !== self::endpoint( $connection_id );
	}

	/**
	 * Submit a workflow to ComfyUI.
	 *
	 * @param string $template Template post ID, slug, or wizard slot to run.
	 * @param string $prompt Text prompt.
	 * @param array  $parameters Generation parameters; `inputs` carries modality input slots.
	 * @param int    $connection_id Optional parent worldgraph_conn post ID, for endpoint resolution and log correlation.
	 * @return array|WP_Error
	 */
	public static function run_template( string $template, string $prompt, array $parameters, int $connection_id = 0 ) {
		$endpoint = self::endpoint( $connection_id );
		if ( '' === $endpoint ) {
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
		$workflow = self::workflow( $template_id, $modality, $parameters, $endpoint );
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

		$preflight = self::preflight( $template_id, $connection_id, $endpoint );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		$resolved = self::resolve_inputs( $modality, $inputs, $connection_id, $job_id, $endpoint );
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

		$submit_url = self::url( 'api/v2/jobs', $endpoint );
		Generation_Log::add( 'info', 'local_comfyui', 'Submitting workflow to ' . $submit_url, [ 'modality' => $modality, 'inputs' => $resolved ], '', $connection_id );

		$response = wp_remote_post( $submit_url, [
			'timeout' => 60,
			'headers' => self::headers( $connection_id, [ 'Content-Type' => 'application/json' ] ),
			'body'    => wp_json_encode( [
				'workflow' => self::apply_inputs( $workflow, $resolved ),
			] ),
		] );

		$result = self::decode_response( $response, 'submit the workflow', $connection_id );
		if ( is_wp_error( $result ) ) {
			Generation_Log::add( 'error', 'local_comfyui', $result->get_error_message(), [], '', $connection_id );
		} else {
			Generation_Log::add( 'info', 'local_comfyui', 'Workflow submitted as v2 job.', [ 'job_id' => (string) ( $result['id'] ?? '' ) ], (string) ( $result['id'] ?? '' ), $connection_id );
		}

		return $result;
	}

	/**
	 * Retrieve a local ComfyUI v2 job status and output URLs.
	 *
	 * @param string $job_id Comfy API v2 job UUID.
	 * @param int    $connection_id Optional parent worldgraph_conn post ID, for endpoint resolution and log correlation.
	 * @return array|WP_Error
	 */
	public static function get_job_status( string $job_id, int $connection_id = 0 ) {
		$endpoint = self::endpoint( $connection_id );
		$response = wp_remote_get( self::url( 'api/v2/jobs/' . rawurlencode( $job_id ), $endpoint ), [
			'timeout' => 60,
			'headers' => self::headers( $connection_id ),
		] );
		$result   = self::decode_response( $response, 'retrieve the job status', $connection_id );
		if ( is_wp_error( $result ) ) {
			Generation_Log::add( 'error', 'local_comfyui', $result->get_error_message(), [], $job_id, $connection_id );
			return $result;
		}

		$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
		switch ( $status ) {
			case 'succeeded':
				break;
			case 'failed':
			case 'expired':
				$error   = is_array( $result['error'] ?? null ) ? $result['error'] : [];
				$message = (string) ( $error['message'] ?? '' );
				Generation_Log::add( 'error', 'local_comfyui', 'ComfyUI reported that the workflow failed.', $error, $job_id, $connection_id );
				return [
					'status' => 'failed',
					'error'  => '' !== $message ? $message : __( 'ComfyUI reported that the workflow failed.', 'worldgraph' ),
				];
			case 'canceled':
			case 'canceling':
				return [ 'status' => 'cancelled' ];
			default:
				Generation_Log::add( 'debug', 'local_comfyui', 'Job still running.', [ 'status' => $status ], $job_id, $connection_id );
				return [ 'status' => 'submitted' ];
		}

		// v2 outputs are typed assets; keep images and videos separate so
		// `image_url` reliably points at a real image, not a video file.
		// The proxy mints `url` fields from its request origin, which can
		// drop a reverse-proxy path prefix, so content URLs are rebuilt from
		// the configured endpoint and the asset id instead of followed.
		$image_urls = [];
		$video_urls = [];
		foreach ( (array) ( $result['outputs'] ?? [] ) as $output ) {
			if ( empty( $output['id'] ) ) {
				continue;
			}
			$content_url = self::url( 'api/v2/assets/' . rawurlencode( (string) $output['id'] ) . '/content', $endpoint );
			if ( 'video' === (string) ( $output['type'] ?? '' ) ) {
				$video_urls[] = $content_url;
			} else {
				$image_urls[] = $content_url;
			}
		}

		$images = array_merge( $image_urls, $video_urls );
		if ( empty( $images ) ) {
			Generation_Log::add( 'debug', 'local_comfyui', 'Job succeeded but no outputs were committed yet.', [], $job_id, $connection_id );
			return [ 'status' => 'submitted' ];
		}

		Generation_Log::add( 'info', 'local_comfyui', 'Job completed with ' . count( $images ) . ' output(s).', [], $job_id, $connection_id );
		return [ 'status' => 'completed', 'image_url' => $image_urls[0] ?? $images[0], 'images' => $images ];
	}

	/**
	 * Get the configured ComfyUI base URL. A specifically requested Connection
	 * wins; legacy callers fall back to the global option and then the default
	 * local ComfyUI Connection.
	 *
	 * @param int $connection_id Optional Connection whose endpoint takes precedence.
	 * @return string
	 */
	public static function endpoint( int $connection_id = 0 ): string {
		if ( $connection_id ) {
			$connection = Connection_Repository::get( $connection_id );
			$url        = $connection ? untrailingslashit( esc_url_raw( (string) ( $connection['endpoint_url'] ?? '' ) ) ) : '';
			// A Cloud Connection may carry the MCP endpoint URL; the v2
			// execution surface lives at the same host without the /mcp path.
			if ( '/mcp' === substr( $url, -4 ) ) {
				$url = substr( $url, 0, -4 );
			}
			if ( '' !== $url ) {
				return $url;
			}
		}

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
	 * @param string $endpoint    Resolved ComfyUI base URL for this job.
	 * @return array|WP_Error
	 */
	private static function workflow( int $template_id, string $modality, array $runtime = [], string $endpoint = '' ) {
		$raw = $template_id
			? (string) worldgraph_get_field_value( $template_id, 'workflow_json' )
			: (string) get_option( 'worldgraph_comfy_local_workflow', '' );
		$workflow = json_decode( $raw, true );
		if ( is_array( $workflow ) && ! empty( $workflow ) ) {
			if ( Comfy_Graph::is_editor_graph( $workflow ) ) {
				$object_info = Comfy_Manifest::object_info( '' !== $endpoint ? $endpoint : self::endpoint() );
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
			return new WP_Error(
				'local_comfyui_workflow_missing',
				__( 'This Template has no ComfyUI workflow or checkpoint. Prepare a published workflow on the Template before generating.', 'worldgraph' )
			);
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
			/**
			 * Let a bundled feature plugin route the composed Story Graph prompt
			 * into a workflow before generic prompt-node discovery runs. Return
			 * the workflow with a `{{prompt}}` placeholder to claim the prompt.
			 *
			 * @param array $workflow    API-format workflow.
			 * @param int   $template_id Template post ID.
			 */
			$claimed = apply_filters( 'worldgraph_comfy_prompt_placeholder_workflow', $workflow, $template_id );
			if ( is_array( $claimed ) && ! empty( $claimed ) ) {
				$workflow = $claimed;
			}
		}
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
	 * @param string $endpoint   Resolved ComfyUI base URL for this job.
	 * @return true|WP_Error
	 */
	private static function preflight( int $template_id, int $connection_id, string $endpoint = '' ) {
		if ( ! $template_id ) {
			return true;
		}

		$report = Comfy_Manifest::validate( $template_id, $endpoint );
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
	 * @param int    $job_id        Queued job ID, for background authorization.
	 * @param string $endpoint      Resolved ComfyUI base URL for this job.
	 * @return array<string, string>|WP_Error
	 */
	private static function resolve_inputs( string $modality, array $inputs, int $connection_id, int $job_id = 0, string $endpoint = '' ) {
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

			$uploaded = self::upload_input( $value, $connection_id, $job_id, $endpoint );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}

			$resolved[ $slot ] = $uploaded;
		}

		return $resolved;
	}

	/**
	 * Upload a media input as a Comfy API v2 asset and return the JSON
	 * `core/ASSET` reference string its Load* nodes should carry. The proxy
	 * resolves the reference to the filename ComfyUI expects at submit time.
	 *
	 * @param string $reference     Attachment ID or validated HTTPS URL.
	 * @param int    $connection_id Connection post ID, for log correlation.
	 * @param int    $job_id        Queued job ID, for background authorization.
	 * @param string $endpoint      Resolved ComfyUI base URL for this job.
	 * @return string|WP_Error
	 */
	private static function upload_input( string $reference, int $connection_id, int $job_id = 0, string $endpoint = '' ) {
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
				return new WP_Error(
					'local_comfyui_input_download_failed',
					sprintf(
						/* translators: %s: validated HTTPS URL of a generation input. */
						__( 'Unable to download the generation input %s.', 'worldgraph' ),
						$reference
					)
				);
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
		$content_type = wp_check_filetype( $filename )['type'] ?: 'application/octet-stream';
		$boundary = wp_generate_uuid4();
		$body     = '';
		foreach ( [
			'content_type' => $content_type,
			'file_path'    => $filename,
			'tags'         => 'input',
		] as $field => $field_value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"\r\n\r\n{$field_value}\r\n";
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"tags\"\r\n\r\ninput\r\n";
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
		$body .= "Content-Type: {$content_type}\r\n\r\n";
		$body .= $bytes . "\r\n--{$boundary}--\r\n";

		if ( $cleanup ) {
			wp_delete_file( $path );
		}

		$response = wp_remote_post( self::url( 'api/v2/assets', $endpoint ), [
			'timeout' => 120,
			'headers' => self::headers( $connection_id, [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ] ),
			'body'    => $body,
		] );

		$result = self::decode_response( $response, 'upload a generation input', $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['id'] ) ) {
			return new WP_Error( 'local_comfyui_input_upload_failed', __( 'ComfyUI did not accept a generation input file.', 'worldgraph' ) );
		}

		return wp_json_encode( [
			'__type' => 'core/ASSET',
			'info'   => [
				'id'        => (string) $result['id'],
				'file_path' => (string) ( $result['file_path'] ?? $filename ),
			],
		] );
	}

	/**
	 * Replace `{{slot}}` placeholders in a ComfyUI API workflow with the
	 * resolved input values for the job.
	 *
	 * @param mixed $value  Workflow value.
	 * @param array $inputs Resolved slot => value map.
	 * @return mixed
	 */
	public static function apply_template_inputs( $value, array $inputs ) {
		return self::apply_inputs( $value, $inputs );
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
	 * @param string $path     Endpoint path.
	 * @param string $endpoint Optional already-resolved ComfyUI base URL.
	 * @return string
	 */
	private static function url( string $path, string $endpoint = '' ): string {
		$endpoint = '' !== $endpoint ? untrailingslashit( esc_url_raw( $endpoint ) ) : self::endpoint();

		return $endpoint . '/' . ltrim( $path, '/' );
	}

	/**
	 * Request headers for the Comfy API v2 surface, including the bearer
	 * credential when one is configured for this endpoint.
	 *
	 * @param int   $connection_id Connection post ID, for credential resolution.
	 * @param array $extra         Additional headers, e.g. Content-Type.
	 * @return array
	 */
	private static function headers( int $connection_id = 0, array $extra = [] ): array {
		$headers    = $extra;
		$credential = self::credential( $connection_id );
		if ( '' !== $credential ) {
			$headers['Authorization'] = 'Bearer ' . $credential;
		}

		return $headers;
	}

	/**
	 * Authorization headers for downloading a completed output's bytes from
	 * the Comfy API v2 content endpoint.
	 *
	 * @param int $job_id Generation job post ID, for Connection resolution.
	 * @return array
	 */
	public static function download_headers( int $job_id = 0 ): array {
		$connection_id = $job_id ? absint( (int) get_post_meta( $job_id, '_worldgraph_gen_connection_id', true ) ) : 0;

		return self::headers( $connection_id );
	}

	/**
	 * The bearer credential for the Comfy API v2 surface: the Connection's
	 * credential reference first (literal or env://), then the
	 * WORLDGRAPH_COMFY_API_KEY environment variable the WordPress container
	 * carries (matching comfy-api-proxy's --token).
	 *
	 * @param int $connection_id Connection post ID.
	 * @return string
	 */
	private static function credential( int $connection_id = 0 ): string {
		if ( $connection_id ) {
			$connection = Connection_Repository::get( $connection_id );
			$reference  = is_array( $connection ) ? trim( (string) ( $connection['credential_reference'] ?? '' ) ) : '';
			if ( '' !== $reference ) {
				if ( 0 === strpos( $reference, 'env://' ) ) {
					$name = substr( $reference, 6 );
					if ( preg_match( '/^[A-Z_][A-Z0-9_]*$/', $name ) ) {
						$value = getenv( $name );
						if ( false !== $value && '' !== trim( $value ) ) {
							return trim( $value );
						}
					}
				} else {
					return $reference;
				}
			}
		}

		$environment = getenv( 'WORLDGRAPH_COMFY_API_KEY' );
		if ( false !== $environment && '' !== trim( $environment ) ) {
			return trim( $environment );
		}

		// Cloud Connections carry no local proxy token; their key rides
		// the COMFY_CLOUD_API_KEY environment variable instead.
		$cloud = getenv( 'COMFY_CLOUD_API_KEY' );

		return false === $cloud ? '' : trim( $cloud );
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
			return new WP_Error(
				'local_comfyui_unreachable',
				sprintf(
					/* translators: 1: attempted ComfyUI action, 2: connection error message. */
					__( 'Unable to %1$s through local ComfyUI: %2$s', 'worldgraph' ),
					$action,
					$response->get_error_message()
				)
			);
		}
		if ( wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			Generation_Log::add( 'error', 'local_comfyui', sprintf( 'HTTP %d while trying to %s.', wp_remote_retrieve_response_code( $response ), $action ), [ 'body' => wp_remote_retrieve_body( $response ) ], '', $connection_id );
			return new WP_Error(
				'local_comfyui_request_failed',
				sprintf(
					/* translators: %s: attempted ComfyUI action. */
					__( 'Local ComfyUI could not %s.', 'worldgraph' ),
					$action
				)
			);
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $result ) ) {
			Generation_Log::add( 'error', 'local_comfyui', sprintf( 'Invalid response body while trying to %s.', $action ), [ 'body' => wp_remote_retrieve_body( $response ) ], '', $connection_id );
			return new WP_Error( 'local_comfyui_invalid_response', __( 'Local ComfyUI returned an invalid response.', 'worldgraph' ) );
		}

		return $result;
	}
}
