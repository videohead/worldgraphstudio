<?php
/**
 * ComfyUI requirement manifests for World Graph Studio generation Templates.
 *
 * Derives the node classes and model files a Template needs, checks them
 * against a live ComfyUI instance so a job never gets submitted into a graph
 * that ComfyUI cannot execute, and resolves download sources through the
 * Comfy MCP template system.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template requirement manifest builder and validator.
 */
class Comfy_Manifest {

	/**
	 * Full provider descriptors resolved during this request, keyed by
	 * Connection and provider template ID. Catalog discovery can encounter the
	 * same lightweight row through several filtered list calls, and resolving
	 * it more than once only adds latency and provider load.
	 *
	 * A null value records a failed or unusable lookup so that failure is not
	 * retried for every duplicate list row in the same request.
	 *
	 * @var array<string, array|null>
	 */
	private static $provider_descriptor_cache = [];

	/**
	 * Transient prefix for the cached ComfyUI `/object_info` catalog.
	 */
	const CATALOG_TRANSIENT = 'worldgraph_comfy_object_info_';

	/**
	 * How long the ComfyUI node/model catalog stays cached, in seconds.
	 */
	const CATALOG_TTL = 300;

	/**
	 * ComfyUI loader input names that name a model file, mapped to the
	 * `models/` sub-directory the file belongs in. Used to discover model
	 * requirements inside a pasted custom workflow and to tell an operator
	 * where a missing file has to be installed.
	 *
	 * @var array<string, string>
	 */
	const MODEL_FIELDS = [
		'ckpt_name'          => 'checkpoints',
		'unet_name'          => 'diffusion_models',
		'vae_name'           => 'vae',
		'clip_name'          => 'text_encoders',
		'clip_name1'         => 'text_encoders',
		'clip_name2'         => 'text_encoders',
		'clip_name3'         => 'text_encoders',
		'clip_name4'         => 'text_encoders',
		'lora_name'          => 'loras',
		'control_net_name'   => 'controlnet',
		'style_model_name'   => 'style_models',
		'clip_vision_name'   => 'clip_vision',
		'gligen_name'        => 'gligen',
		'upscale_model_name' => 'upscale_models',
	];

	/**
	 * Loader-specific model sockets whose generic field name is otherwise too
	 * ambiguous to classify safely.
	 *
	 * @var array<string, array<string, string>>
	 */
	const NODE_MODEL_FIELDS = [
		'LatentUpscaleModelLoader' => [
			'model_name' => 'latent_upscale_models',
		],
	];

	/**
	 * Resolve the ComfyUI models sub-directory for one loader socket.
	 *
	 * @param string $node_class ComfyUI node class.
	 * @param string $field      Loader input field.
	 * @return string Empty when the socket is not a known model selector.
	 */
	public static function model_folder( string $node_class, string $field ): string {
		return (string) ( self::NODE_MODEL_FIELDS[ $node_class ][ $field ] ?? self::MODEL_FIELDS[ $field ] ?? '' );
	}

	/**
	 * Build the requirement manifest for a Template.
	 *
	 * @param int $template_id Template post ID.
	 * @return array|WP_Error
	 */
	public static function for_template( int $template_id ) {
		$template = get_post( $template_id );
		if ( ! $template instanceof \WP_Post || 'worldgraph_template' !== $template->post_type ) {
			return new WP_Error( 'worldgraph_template_not_found', __( 'That generation Template does not exist.', 'worldgraph' ), [ 'status' => 404 ] );
		}

		$modality = Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template_id, 'modality' ) );
		$custom   = json_decode( (string) worldgraph_get_field_value( $template_id, 'workflow_json' ), true );
		$is_custom = is_array( $custom ) && ! empty( $custom );
		$settings  = self::template_settings( $template_id, $modality );
		if ( ! $is_custom && '' === trim( (string) ( $settings['checkpoint'] ?? '' ) ) ) {
			return new WP_Error(
				'worldgraph_template_workflow_missing',
				__( 'This Template has no ComfyUI workflow or checkpoint. Prepare a published workflow before using it.', 'worldgraph' )
			);
		}
		$workflow = $is_custom
			? $custom
			: Generation_Modality::default_workflow( $modality, $settings );

		return [
			'template_id'     => $template_id,
			'name'            => (string) ( worldgraph_get_field_value( $template_id, 'template_name' ) ?: $template->post_title ),
			'slug'            => (string) $template->post_name,
			'modality'        => $modality,
			'modality_label'  => (string) Generation_Modality::get( $modality )['label'],
			'output_type'     => Generation_Modality::output_type( $modality ),
			'inputs'          => Generation_Modality::inputs( $modality ),
			'workflow_source' => $is_custom ? 'custom' : 'builtin',
			'nodes'           => self::extract_nodes( $workflow, $modality, $is_custom ),
			'models'          => self::extract_models( $workflow ),
			'downloads'       => self::declared_downloads( $template_id ),
		];
	}

	/**
	 * Check a Template's manifest against a live ComfyUI instance.
	 *
	 * @param int    $template_id Template post ID.
	 * @param string $endpoint    Optional ComfyUI base URL; defaults to the configured one.
	 * @return array|WP_Error Report with `ok`, `missing_nodes`, and `missing_models`.
	 */
	public static function validate( int $template_id, string $endpoint = '' ) {
		$manifest = self::for_template( $template_id );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$endpoint = '' !== $endpoint ? untrailingslashit( esc_url_raw( $endpoint ) ) : Local_ComfyUI::endpoint();
		if ( '' === $endpoint ) {
			return new WP_Error( 'worldgraph_comfy_endpoint_missing', __( 'Set a local ComfyUI URL before checking Template requirements.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$catalog = self::catalog( $endpoint );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$missing_nodes = array_values( array_diff( $manifest['nodes'], array_keys( $catalog ) ) );

		$missing_models = [];
		$unverified     = [];
		foreach ( $manifest['models'] as $model ) {
			$installed = self::installed_options( $catalog, (string) $model['node_class'], (string) $model['field'] );
			if ( null === $installed ) {
				$unverified[] = $model;
				continue;
			}
			if ( ! in_array( (string) $model['filename'], $installed, true ) ) {
				$model['available'] = $installed;
				$model['source_url'] = self::download_url_for( $manifest['downloads'], (string) $model['filename'] );
				$missing_models[]   = $model;
			}
		}

		return [
			'ok'             => empty( $missing_nodes ) && empty( $missing_models ),
			'template_id'    => $template_id,
			'modality'       => $manifest['modality'],
			'endpoint'       => $endpoint,
			'checked_at'     => gmdate( 'Y-m-d H:i:s' ),
			'missing_nodes'  => $missing_nodes,
			'missing_models' => $missing_models,
			'unverified'     => $unverified,
		];
	}

	/**
	 * Confirm a local ComfyUI Template is actually runnable before it is
	 * queued, tested, or exposed for selection. This is the single gate every
	 * caller (submission, smoke check, panel listing) must share, so "ready"
	 * cannot mean different things in different code paths.
	 *
	 * When the Connection's MCP server is agentic (exposes `download_models`),
	 * a first failed check asks it to fetch the missing files and re-validates
	 * once before giving up, instead of only ever reporting the gap.
	 *
	 * @param int $template_id   Template post ID.
	 * @param int $connection_id Connection post ID, for MCP and log correlation.
	 * @return true|WP_Error
	 */
	public static function ensure_ready( int $template_id, int $connection_id = 0 ) {
		$endpoint = Local_ComfyUI::endpoint( $connection_id );
		$report   = self::validate( $template_id, $endpoint );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		if ( ! empty( $report['ok'] ) ) {
			return true;
		}

		$mcp_client = Comfy_Provider::client_for( $connection_id );
		if ( ! empty( $report['missing_models'] ) && $mcp_client::supports_tool( 'download_models', $connection_id ) ) {
			$download = self::request_downloads( $template_id, $endpoint );
			if ( ! is_wp_error( $download ) ) {
				self::flush_catalog( $endpoint );
				$report = self::validate( $template_id, $endpoint );
				if ( is_wp_error( $report ) ) {
					return $report;
				}
				if ( ! empty( $report['ok'] ) ) {
					return true;
				}
			}
		}

		$problems = [];
		foreach ( (array) ( $report['missing_nodes'] ?? [] ) as $node ) {
			$problems[] = sprintf(
				/* translators: %s: ComfyUI node class name. */
				__( 'missing node type %s', 'worldgraph' ),
				(string) $node
			);
		}
		foreach ( (array) ( $report['missing_models'] ?? [] ) as $model ) {
			$problems[] = sprintf(
				/* translators: 1: model filename, 2: ComfyUI models sub-directory. */
				__( 'missing model %1$s (install into models/%2$s)', 'worldgraph' ),
				(string) ( $model['filename'] ?? '' ),
				(string) ( $model['folder'] ?? '' )
			);
		}

		return new WP_Error(
			'worldgraph_local_comfyui_requirements_missing',
			sprintf(
				/* translators: %s: semicolon-separated list of unmet requirements. */
				__( 'ComfyUI cannot run this Template yet: %s.', 'worldgraph' ),
				implode( '; ', $problems )
			),
			[ 'status' => 400, 'report' => $report ]
		);
	}

	/**
	 * The model filenames a live ComfyUI offers for a loader input, e.g. the
	 * checkpoints its default text-to-image workflow can load.
	 *
	 * @param string $node_class Node class type.
	 * @param string $field      Input name.
	 * @param string $endpoint   Optional ComfyUI base URL; defaults to the configured one.
	 * @return array<int, string>|WP_Error Filenames, or an error when the catalog or node is unavailable.
	 */
	public static function installed_files( string $node_class, string $field, string $endpoint = '' ) {
		$endpoint = '' !== $endpoint ? untrailingslashit( esc_url_raw( $endpoint ) ) : Local_ComfyUI::endpoint();
		if ( '' === $endpoint ) {
			return new WP_Error( 'worldgraph_comfy_endpoint_missing', __( 'Set a local ComfyUI URL before reading its installed models.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$catalog = self::catalog( $endpoint );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}
		if ( ! isset( $catalog[ $node_class ] ) ) {
			return new WP_Error(
				'worldgraph_comfy_node_missing',
				sprintf(
					/* translators: %s: ComfyUI node class name. */
					__( 'ComfyUI has not loaded the %s node.', 'worldgraph' ),
					$node_class
				)
			);
		}

		return self::installed_options( $catalog, $node_class, $field ) ?? [];
	}

	/**
	 * Sampling and model settings a Template overrides on the built-in graph.
	 *
	 * @param int    $template_id Template post ID.
	 * @param string $modality    Modality slug.
	 * @param array  $runtime     Runtime parameter overrides for this job.
	 * @return array<string, mixed>
	 */
	public static function template_settings( int $template_id, string $modality, array $runtime = [] ): array {
		$settings = [
			'checkpoint'    => trim( (string) worldgraph_get_field_value( $template_id, 'checkpoint' ) ),
			'lora_name'     => trim( (string) worldgraph_get_field_value( $template_id, 'lora_name' ) ),
			'lora_strength' => trim( (string) worldgraph_get_field_value( $template_id, 'lora_strength' ) ),
		];

		foreach ( [ 'configuration_json', 'default_values' ] as $meta_key ) {
			$decoded = json_decode( (string) worldgraph_get_field_value( $template_id, $meta_key ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$parameters = isset( $decoded['parameters'] ) && is_array( $decoded['parameters'] ) ? $decoded['parameters'] : $decoded;
			foreach ( array_keys( Generation_Modality::default_settings( $modality ) ) as $key ) {
				if ( isset( $parameters[ $key ] ) && is_scalar( $parameters[ $key ] ) ) {
					$settings[ $key ] = $parameters[ $key ];
				}
			}
		}

		foreach ( array_keys( Generation_Modality::default_settings( $modality ) ) as $key ) {
			if ( isset( $runtime[ $key ] ) && is_scalar( $runtime[ $key ] ) ) {
				$settings[ $key ] = $runtime[ $key ];
			}
		}
		if ( isset( $runtime['seed'] ) && preg_match( '/^\d+$/', (string) $runtime['seed'] ) ) {
			$settings['seed'] = (string) $runtime['seed'];
		}

		$size = isset( $runtime['size'] ) && is_scalar( $runtime['size'] ) ? trim( (string) $runtime['size'] ) : '';
		if ( preg_match( '/^(\d+)x(\d+)$/i', $size, $matches ) ) {
			$settings['width']  = (int) $matches[1];
			$settings['height'] = (int) $matches[2];
		}

		return $settings;
	}

	/**
	 * Ask the Comfy MCP template system for templates that match a modality,
	 * so an operator can adopt a known-good graph and its model list instead
	 * of assembling one by hand. This is the reciprocal of the
	 * `worldgraph/templates-manifest` resource World Graph Studio exposes to MCP clients.
	 *
	 * @param string $modality Modality slug.
	 * @return array|WP_Error
	 */
	public static function discover( string $modality, int $connection_id = 0 ) {
		$modality = Generation_Modality::sanitize( $modality );
		$client   = Comfy_Provider::client_for( $connection_id );
		$result   = $client::list_templates( [
			'task_type' => (string) Generation_Modality::get( $modality )['task_type'],
		], $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$templates = $result['templates'] ?? $result;
		if ( ! is_array( $templates ) ) {
			return new WP_Error( 'worldgraph_comfy_discovery_invalid', __( 'Comfy MCP returned no usable template list.', 'worldgraph' ) );
		}

		$discovered = [];
		foreach ( $templates as $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}

			$workflow = is_array( $template['workflow'] ?? null ) ? $template['workflow'] : [];
			$discovered[] = [
				'id'             => (string) ( $template['id'] ?? $template['template_id'] ?? $template['name'] ?? '' ),
				'name'           => (string) ( $template['name'] ?? $template['template_name'] ?? '' ),
				'model_type'     => (string) ( $template['model_type'] ?? '' ),
				'task_type'      => (string) ( $template['task_type'] ?? '' ),
				'required_nodes' => array_values( array_unique( array_merge(
					array_map( 'strval', (array) ( $template['required_nodes'] ?? [] ) ),
					$workflow ? self::extract_nodes( $workflow, $modality, true ) : []
				) ) ),
				'models'         => $workflow ? self::extract_models( $workflow ) : [],
				'model_urls'     => self::extract_model_urls( $template ),
				'parameters'     => is_array( $template['parameters'] ?? null ) ? $template['parameters'] : [],
			];
		}

		return [ 'modality' => $modality, 'templates' => $discovered ];
	}

	/**
	 * Search the provider's own Comfy MCP template catalog.
	 *
	 * @param string $search Optional provider template name or ID.
	 * @param int    $connection_id Comfy Connection post ID.
	 * @param string $client MCP client class to call, resolved from the Connection's provider type.
	 * @return array|WP_Error
	 */
	public static function discover_provider_templates( string $search = '', int $connection_id = 0, string $client = Comfy_Cloud_MCP::class ) {
		$filters = '' !== trim( $search ) ? [ 'search' => sanitize_text_field( $search ) ] : [];
		$result  = $client::list_templates( $filters, $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$templates = $result['templates'] ?? $result;
		if ( ! is_array( $templates ) ) {
			return new WP_Error( 'worldgraph_comfy_discovery_invalid', __( 'Comfy MCP returned no usable template list.', 'worldgraph' ) );
		}

		$descriptors = [];
		foreach ( $templates as $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}

			$id = self::provider_template_id( $template );
			if ( '' === $id ) {
				continue;
			}

			$descriptors[ $id ] = isset( $descriptors[ $id ] )
				? self::merge_template_descriptors( $descriptors[ $id ], $template )
				: $template;
		}

		return array_values( array_filter( array_map( static function ( array $template ) use ( $connection_id, $client ) {
			return self::normalize_entry( $template, $connection_id, $client );
		}, $descriptors ) ) );
	}

	/**
	 * Reduce a Comfy MCP template descriptor to the catalog entry shape
	 * World Graph Studio stores and renders. Providers disagree about key names, so every
	 * discovery path funnels through here rather than reading the payload
	 * directly.
	 *
	 * The workflow graph is deliberately not retained: catalogs are stored per
	 * Connection and must stay small, so the graph is re-fetched with
	 * `get_template()` when a template is actually materialized.
	 *
	 * @param array $template Raw MCP template descriptor.
	 * @param int   $connection_id Connection post ID.
	 * @param string $client MCP client class to call, resolved from the Connection's provider type.
	 * @return array|null Catalog entry, or null when the descriptor has no usable ID.
	 */
	public static function normalize_entry( array $template, int $connection_id = 0, string $client = Comfy_Cloud_MCP::class ): ?array {
		$id = (string) ( $template['id'] ?? $template['template_id'] ?? $template['name'] ?? '' );
		if ( '' === trim( $id ) ) {
			return null;
		}

		$template = self::enrich_template_descriptor( $template, $connection_id, $client );

		$workflow  = is_array( $template['workflow'] ?? null ) ? $template['workflow'] : [];
		$task_type = (string) ( $template['task_type'] ?? '' );
		$modality  = self::modality_for_task_type( $task_type );

		$required_nodes = array_map( 'strval', (array) ( $template['required_nodes'] ?? [] ) );
		if ( empty( $required_nodes ) && is_array( $template['requirements']['node_classes'] ?? null ) ) {
			$required_nodes = array_map( 'strval', (array) $template['requirements']['node_classes'] );
		}

		$nodes = array_values( array_unique( array_merge(
			$required_nodes,
			$workflow ? self::extract_nodes( $workflow, $modality ?? Generation_Modality::TEXT_TO_IMAGE, true ) : []
		) ) );
		sort( $nodes );

		// First/last-frame workflows are sometimes advertised as generic I2V by
		// MCP providers. Their name and core conditioning node are stronger
		// evidence because the executable graph requires two distinct endpoints.
		if ( self::is_first_last_frame_template( $template, $nodes ) ) {
			$modality = Generation_Modality::VIDEO_TO_VIDEO;
		} elseif ( null === $modality ) {
			$modality = self::infer_modality( $template, $nodes );
		}

		$models = $workflow ? self::extract_models( $workflow ) : [];
		if ( empty( $models ) ) {
			$models = self::extract_requirement_models( $template );
		}
		$provider_schema = [];
		foreach ( [ 'inputSchema', 'input_schema', 'schema' ] as $schema_key ) {
			if ( is_array( $template[ $schema_key ] ?? null ) ) {
				$provider_schema = $template[ $schema_key ];
				break;
			}
		}

		return [
			'id'             => $id,
			'name'           => (string) ( $template['name'] ?? $template['template_name'] ?? $id ),
			'source'         => 'mcp',
			'model_type'     => (string) ( $template['model_type'] ?? '' ),
			'task_type'      => $task_type,
			'modality'       => $modality,
			'model_family'   => Model_Family::for_nodes( $nodes ),
			'required_nodes' => $nodes,
			'models'         => $models,
			'model_urls'     => self::extract_model_urls( $template ),
			'parameters'     => is_array( $template['parameters'] ?? null ) ? $template['parameters'] : [],
			'provider_schema' => $provider_schema,
			'workflow_hash'  => $workflow ? 'sha1:' . sha1( (string) wp_json_encode( $workflow ) ) : '',
		];
	}

	/**
	 * Map a Comfy MCP task type onto a World Graph Studio modality, or null when the
	 * provider offers something World Graph Studio has no modality for.
	 *
	 * @param string $task_type Provider task type.
	 * @return string|null
	 */
	public static function modality_for_task_type( string $task_type ): ?string {
		$task_type = strtolower( trim( str_replace( [ '_', ' ' ], '-', $task_type ) ) );
		if ( '' === $task_type ) {
			return null;
		}

		$aliases = [
			'txt2video' => 'text-to-video',
			'img2video' => 'image-to-video',
			'vid2video' => 'video-to-video',
			'txt2speech' => 'text-to-speech',
		];
		$task_type = $aliases[ $task_type ] ?? $task_type;

		foreach ( Generation_Modality::all() as $slug => $modality ) {
			if ( strtolower( (string) $modality['task_type'] ) === $task_type ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Download files advertised by a provider template.
	 *
	 * @param string $provider_template_id Provider template ID.
	 * @param int    $connection_id Comfy Connection post ID.
	 * @return array|WP_Error
	 */
	public static function request_provider_template_downloads( string $provider_template_id, int $connection_id = 0 ) {
		$client   = Comfy_Provider::client_for( $connection_id );
		$template = $client::get_template( $provider_template_id, [], $connection_id );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$urls = self::extract_model_urls( $template );
		if ( empty( $urls ) ) {
			$has_requirements = is_array( $template['requirements']['models'] ?? null ) && ! empty( $template['requirements']['models'] );
			$missing = array_values( array_filter( array_map( 'strval', (array) ( $template['requirements']['missing_models'] ?? [] ) ) ) );
			if ( empty( $missing ) && $has_requirements ) {
				foreach ( (array) ( $template['requirements']['models'] ?? [] ) as $model ) {
					if ( is_array( $model ) && ! empty( $model['filename'] ) && empty( $model['installed'] ) ) {
						$missing[] = (string) $model['filename'];
					}
				}
			}

			// A provider that reports every known requirement as installed has nothing left to fetch.
			if ( empty( $missing ) && $has_requirements ) {
				return [ 'requested' => [], 'message' => __( 'Every model this Template needs is already installed.', 'worldgraph' ) ];
			}

			$message = __( 'The provider Template did not advertise downloadable requirements.', 'worldgraph' );
			if ( ! empty( $missing ) ) {
				$message = sprintf(
					/* translators: %s: comma-separated list of model filenames. */
					__( 'The provider Template did not advertise downloadable requirements. Install manually: %s', 'worldgraph' ),
					implode( ', ', array_unique( $missing ) )
				);
			}

			return new WP_Error( 'worldgraph_comfy_template_no_downloads', $message );
		}

		$result = $client::download_models( $urls, $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [ 'requested' => $urls, 'result' => $result ];
	}

	/**
	 * Ask Comfy MCP to fetch the models a Template is missing.
	 *
	 * @param int    $template_id Template post ID.
	 * @param string $endpoint    Optional ComfyUI base URL for the Template's Connection.
	 * @return array|WP_Error Result payload, or an error describing the manual install plan.
	 */
	public static function request_downloads( int $template_id, string $endpoint = '' ) {
		$connection_id = self::template_connection_id( $template_id );
		$client         = Comfy_Provider::client_for( $connection_id );
		$report        = self::validate( $template_id, $endpoint );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		if ( empty( $report['missing_models'] ) ) {
			return [ 'requested' => [], 'message' => __( 'Every model this Template needs is already installed.', 'worldgraph' ) ];
		}

		$urls    = [];
		$manual  = [];
		foreach ( $report['missing_models'] as $model ) {
			if ( ! empty( $model['source_url'] ) ) {
				$urls[] = (string) $model['source_url'];
				continue;
			}
			$manual[] = sprintf( '%s (models/%s)', (string) $model['filename'], (string) $model['folder'] );
		}

		if ( empty( $urls ) ) {
			return new WP_Error(
				'worldgraph_comfy_no_download_urls',
				sprintf(
					/* translators: %s: comma-separated list of model files. */
					__( 'No download URL is recorded for: %s. Add each file to the Template\'s Model Requirements JSON as {"filename":"…","folder":"…","url":"…"}, or install it into ComfyUI manually.', 'worldgraph' ),
					implode( ', ', $manual )
				)
			);
		}

		$result = $client::download_models( $urls, $connection_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [ 'requested' => $urls, 'manual' => $manual, 'result' => $result ];
	}

	/**
	 * The Connection a Template runs on. Download and discovery calls must be
	 * scoped to it, or a local Template's requests are sent to Comfy Cloud.
	 *
	 * @param int $template_id Template post ID.
	 * @return int
	 */
	private static function template_connection_id( int $template_id ): int {
		return (int) worldgraph_get_field_value( $template_id, 'connection_id' );
	}

	/**
	 * Node class types a workflow executes.
	 *
	 * @param array  $workflow  ComfyUI API-format graph.
	 * @param string $modality  Modality slug.
	 * @param bool   $is_custom Whether the graph was supplied by the operator.
	 * @return array<int, string>
	 */
	private static function extract_nodes( array $workflow, string $modality, bool $is_custom ): array {
		$nodes = [];
		if ( Comfy_Graph::is_editor_graph( $workflow ) ) {
			$subgraph_ids = [];
			$bodies       = [ $workflow ];
			foreach ( (array) ( $workflow['definitions']['subgraphs'] ?? [] ) as $definition ) {
				if ( ! is_array( $definition ) ) {
					continue;
				}
				$bodies[] = $definition;
				if ( ! empty( $definition['id'] ) ) {
					$subgraph_ids[ (string) $definition['id'] ] = true;
				}
			}

			foreach ( $bodies as $body ) {
				foreach ( (array) ( $body['nodes'] ?? [] ) as $node ) {
					$class = is_array( $node ) ? (string) ( $node['type'] ?? '' ) : '';
					if ( '' !== $class && ! isset( $subgraph_ids[ $class ] ) ) {
						$nodes[] = $class;
					}
				}
			}
		} else {
			foreach ( $workflow as $node ) {
				if ( is_array( $node ) && ! empty( $node['class_type'] ) && is_string( $node['class_type'] ) ) {
					$nodes[] = $node['class_type'];
				}
			}
		}

		// A built-in graph is generated from the modality definition, so fall
		// back to it when a graph carries no recognizable nodes.
		if ( ! $is_custom || empty( $nodes ) ) {
			$nodes = array_merge( $nodes, Generation_Modality::required_nodes( $modality ) );
		}

		sort( $nodes );

		return array_values( array_unique( $nodes ) );
	}

	/**
	 * Model files a workflow loads.
	 *
	 * @param array $workflow ComfyUI API-format graph.
	 * @return array<int, array>
	 */
	private static function extract_models( array $workflow ): array {
		$models = [];
		foreach ( $workflow as $node ) {
			if ( ! is_array( $node ) || empty( $node['class_type'] ) || ! is_array( $node['inputs'] ?? null ) ) {
				continue;
			}

			foreach ( $node['inputs'] as $field => $value ) {
				$folder = self::model_folder( (string) $node['class_type'], (string) $field );
				if ( '' === $folder || ! is_string( $value ) || '' === trim( $value ) ) {
					continue;
				}

				$models[ $node['class_type'] . '|' . $field . '|' . $value ] = [
					'node_class' => (string) $node['class_type'],
					'field'      => (string) $field,
					'filename'   => trim( $value ),
					'folder'     => $folder,
				];
			}
		}

		return array_values( $models );
	}

	/**
	 * Download sources declared on a Template's Model Requirements field.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<int, array>
	 */
	private static function declared_downloads( int $template_id ): array {
		$decoded = json_decode( (string) worldgraph_get_field_value( $template_id, 'model_requirements' ), true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$entries = isset( $decoded['models'] ) && is_array( $decoded['models'] ) ? $decoded['models'] : $decoded;
		$downloads = [];
		foreach ( (array) $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['filename'] ) ) {
				continue;
			}

			$downloads[] = [
				'filename' => sanitize_text_field( (string) $entry['filename'] ),
				'folder'   => sanitize_text_field( (string) ( $entry['folder'] ?? '' ) ),
				'url'      => esc_url_raw( (string) ( $entry['url'] ?? '' ) ),
			];
		}

		return $downloads;
	}

	/**
	 * Find a declared download URL for a model filename.
	 *
	 * @param array  $downloads Declared downloads.
	 * @param string $filename  Model filename.
	 * @return string
	 */
	private static function download_url_for( array $downloads, string $filename ): string {
		foreach ( $downloads as $download ) {
			if ( ( $download['filename'] ?? '' ) === $filename ) {
				return (string) ( $download['url'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Model URLs advertised by a Comfy MCP template descriptor.
	 *
	 * @param array $template MCP template descriptor.
	 * @return array<int, string>
	 */
	private static function extract_model_urls( array $template ): array {
		$urls = [];
		array_walk_recursive( $template, static function ( $value, $key ) use ( &$urls ): void {
			if ( is_string( $value ) && preg_match( '#^https?://#', $value ) && in_array( $key, [ 'url', 'models', 'model_url', 'download_url', 'source_url' ], true ) ) {
				$urls[] = $value;
			}
		} );

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Enrich lightweight list_templates entries by resolving the full template
	 * descriptor from get_template when the provider supports it.
	 *
	 * @param array $template Raw MCP list_templates entry.
	 * @param int   $connection_id Connection post ID.
	 * @param string $client MCP client class to call, resolved from the Connection's provider type.
	 * @return array
	 */
	private static function enrich_template_descriptor( array $template, int $connection_id, string $client = Comfy_Cloud_MCP::class ): array {
		$has_workflow = is_array( $template['workflow'] ?? null ) && ! empty( $template['workflow'] );
		$has_requirements = is_array( $template['requirements'] ?? null ) || is_array( $template['required_nodes'] ?? null );
		if ( $has_workflow || $has_requirements ) {
			return $template;
		}

		$id = self::provider_template_id( $template );
		if ( '' === $id ) {
			return $template;
		}

		$cache_key = $connection_id . ':' . $id;
		if ( ! array_key_exists( $cache_key, self::$provider_descriptor_cache ) ) {
			$resolved = $client::get_template( $id, [], $connection_id );
			self::$provider_descriptor_cache[ $cache_key ] = is_wp_error( $resolved ) || ! is_array( $resolved )
				? null
				: $resolved;
		}

		$resolved = self::$provider_descriptor_cache[ $cache_key ];
		if ( ! is_array( $resolved ) ) {
			return $template;
		}

		$resolved['id'] = $id;
		$merged         = self::merge_template_descriptors( $template, $resolved );
		$merged['id']   = $id;
		if ( '' === trim( (string) ( $merged['name'] ?? '' ) ) ) {
			$merged['name'] = $id;
		}

		return $merged;
	}

	/**
	 * Stable ID shared by list and detail provider descriptors.
	 *
	 * @param array $template Provider template descriptor.
	 * @return string
	 */
	private static function provider_template_id( array $template ): string {
		return trim( (string) ( $template['id'] ?? $template['template_id'] ?? $template['name'] ?? '' ) );
	}

	/**
	 * Add richer descriptor metadata without erasing useful list metadata when
	 * the detail response omits a field or returns an empty placeholder.
	 *
	 * @param array $base     Existing descriptor.
	 * @param array $incoming Descriptor whose populated values take precedence.
	 * @return array
	 */
	private static function merge_template_descriptors( array $base, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			$is_missing = null === $value
				|| ( is_string( $value ) && '' === trim( $value ) )
				|| ( is_array( $value ) && empty( $value ) );
			if ( ! $is_missing || ! array_key_exists( $key, $base ) ) {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Infer modality when the provider omits explicit task_type metadata.
	 *
	 * @param array               $template Provider template descriptor.
	 * @param array<int, string>  $nodes    Required node classes.
	 * @return string|null
	 */
	private static function infer_modality( array $template, array $nodes ): ?string {
		$name = strtolower( trim( (string) ( $template['name'] ?? $template['id'] ?? '' ) ) );
		if ( '' !== $name ) {
			if ( false !== strpos( $name, 'flf2v' ) || false !== strpos( $name, 'first-last frame' ) || false !== strpos( $name, 'first last frame' ) || false !== strpos( $name, 'first frame to last frame' ) ) {
				return Generation_Modality::VIDEO_TO_VIDEO;
			}
			if ( false !== strpos( $name, 'video to video' ) || false !== strpos( $name, 'video-to-video' ) || false !== strpos( $name, 'vid2video' ) ) {
				return Generation_Modality::VIDEO_TO_VIDEO;
			}
			if ( false !== strpos( $name, 'image to video' ) || false !== strpos( $name, 'image-to-video' ) || false !== strpos( $name, 'img2video' ) || false !== strpos( $name, 'image and text to video' ) || false !== strpos( $name, 'text and image to video' ) ) {
				return Generation_Modality::TEXT_IMAGE_TO_VIDEO;
			}
			if ( false !== strpos( $name, 'text to video' ) || false !== strpos( $name, 'text-to-video' ) || false !== strpos( $name, 'txt2video' ) ) {
				return Generation_Modality::TEXT_TO_VIDEO;
			}
			if ( false !== strpos( $name, 'image and text to image' ) || false !== strpos( $name, 'text and image to image' ) || false !== strpos( $name, 'image-text-to-image' ) ) {
				return Generation_Modality::IMAGE_TEXT_TO_IMAGE;
			}
			if ( false !== strpos( $name, 'image to image' ) || false !== strpos( $name, 'image-to-image' ) || false !== strpos( $name, 'img2img' ) || false !== strpos( $name, 'image edit' ) || false !== strpos( $name, 'inpaint' ) || false !== strpos( $name, 'outpaint' ) ) {
				return Generation_Modality::IMAGE_TO_IMAGE;
			}
		}

		$has_video_nodes  = in_array( 'SaveVideo', $nodes, true ) || in_array( 'CreateVideo', $nodes, true );
		$workflow         = is_array( $template['workflow'] ?? null ) ? $template['workflow'] : [];
		$load_image_count = self::workflow_node_class_count( $workflow, 'LoadImage' );
		if ( 0 === $load_image_count && in_array( 'LoadImage', $nodes, true ) ) {
			$load_image_count = 1;
		}
		$has_source_video = (bool) array_filter( $nodes, static function ( string $node ): bool {
			return false !== stripos( $node, 'LoadVideo' );
		} );

		if ( $has_video_nodes ) {
			if ( $has_source_video || in_array( 'WanFirstLastFrameToVideo', $nodes, true ) || ( $load_image_count > 1 && in_array( 'LTXVAddGuide', $nodes, true ) ) ) {
				return Generation_Modality::VIDEO_TO_VIDEO;
			}

			if ( $load_image_count > 0 || in_array( 'LTXVImgToVideo', $nodes, true ) || in_array( 'LTXVImgToVideoInplace', $nodes, true ) ) {
				return Generation_Modality::TEXT_IMAGE_TO_VIDEO;
			}

			return Generation_Modality::TEXT_TO_VIDEO;
		}

		if ( $load_image_count > 0 && in_array( 'SaveImage', $nodes, true ) ) {
			return Generation_Modality::IMAGE_TO_IMAGE;
		}

		return null;
	}

	/** Whether provider metadata identifies a two-endpoint video workflow. */
	private static function is_first_last_frame_template( array $template, array $nodes ): bool {
		$name = strtolower( trim( (string) ( $template['name'] ?? $template['id'] ?? '' ) ) );

		return in_array( 'WanFirstLastFrameToVideo', $nodes, true )
			|| false !== strpos( $name, 'flf2v' )
			|| false !== strpos( $name, 'first-last frame' )
			|| false !== strpos( $name, 'first last frame' )
			|| false !== strpos( $name, 'first frame to last frame' );
	}

	/**
	 * Count a class in either API or editor workflow format without collapsing
	 * duplicate loader nodes, which are meaningful for start/end-frame inference.
	 *
	 * @param array  $workflow Workflow body.
	 * @param string $class    Node class to count.
	 * @return int
	 */
	private static function workflow_node_class_count( array $workflow, string $class ): int {
		$count = 0;
		if ( Comfy_Graph::is_editor_graph( $workflow ) ) {
			$bodies = [ $workflow ];
			foreach ( (array) ( $workflow['definitions']['subgraphs'] ?? [] ) as $definition ) {
				if ( is_array( $definition ) ) {
					$bodies[] = $definition;
				}
			}
			foreach ( $bodies as $body ) {
				foreach ( (array) ( $body['nodes'] ?? [] ) as $node ) {
					if ( is_array( $node ) && $class === (string) ( $node['type'] ?? '' ) ) {
						++$count;
					}
				}
			}

			return $count;
		}

		foreach ( $workflow as $node ) {
			if ( is_array( $node ) && $class === (string) ( $node['class_type'] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Model requirement hints from provider-supplied requirements metadata.
	 *
	 * @param array $template Provider template descriptor.
	 * @return array<int, array<string, string>>
	 */
	private static function extract_requirement_models( array $template ): array {
		$models = [];
		foreach ( (array) ( $template['requirements']['models'] ?? [] ) as $model ) {
			if ( ! is_array( $model ) ) {
				continue;
			}

			$filename = trim( (string) ( $model['filename'] ?? '' ) );
			if ( '' === $filename ) {
				continue;
			}

			$folder = trim( (string) ( $model['expected_folder'] ?? '' ) );
			if ( '' === $folder ) {
				$folder = 'checkpoints';
			}

			$models[] = [
				'node_class' => '',
				'field'      => '',
				'filename'   => $filename,
				'folder'     => sanitize_key( $folder ),
			];
		}

		return $models;
	}

	/**
	 * Which of a catalog entry's declared models a live ComfyUI does not
	 * actually have installed. Fields ComfyUI can't enumerate (unverifiable)
	 * are not counted as missing, matching {@see self::validate()}.
	 *
	 * @param array $models   Declared `node_class`/`field`/`filename` requirements.
	 * @param array $catalog  Decoded `/object_info` payload.
	 * @return array<int, array>
	 */
	public static function unresolved_models( array $models, array $catalog ): array {
		$missing = [];
		foreach ( $models as $model ) {
			if ( ! is_array( $model ) || empty( $model['node_class'] ) || empty( $model['field'] ) || empty( $model['filename'] ) ) {
				continue;
			}

			$installed = self::installed_options( $catalog, (string) $model['node_class'], (string) $model['field'] );
			if ( null === $installed ) {
				continue;
			}
			if ( ! in_array( (string) $model['filename'], $installed, true ) ) {
				$missing[] = $model;
			}
		}

		return $missing;
	}

	/**
	 * The filenames ComfyUI currently offers for a loader input, or null when
	 * the input is not an enumerated file list (so it cannot be validated).
	 *
	 * @param array  $catalog    Decoded `/object_info` payload.
	 * @param string $node_class Node class type.
	 * @param string $field      Input name.
	 * @return array<int, string>|null
	 */
	private static function installed_options( array $catalog, string $node_class, string $field ): ?array {
		foreach ( [ 'required', 'optional' ] as $group ) {
			$spec = $catalog[ $node_class ]['input'][ $group ][ $field ][0] ?? null;
			if ( ! is_array( $spec ) ) {
				continue;
			}

			$options = array_values( array_filter( $spec, 'is_string' ) );

			return $options === $spec ? $options : null;
		}

		return null;
	}

	/**
	 * Fetch and cache ComfyUI's node/model catalog.
	 *
	 * @param string $endpoint ComfyUI base URL.
	 * @return array|WP_Error
	 */
	public static function object_info( string $endpoint ) {
		return self::catalog( untrailingslashit( esc_url_raw( $endpoint ) ) );
	}

	/**
	 * Fetch and cache ComfyUI's node/model catalog.
	 *
	 * @param string $endpoint ComfyUI base URL.
	 * @return array|WP_Error
	 */
	private static function catalog( string $endpoint ) {
		$key    = self::CATALOG_TRANSIENT . md5( $endpoint );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( $endpoint . '/object_info', [ 'timeout' => 30 ] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'worldgraph_comfy_unreachable',
				sprintf(
					/* translators: %s: ComfyUI connection error message. */
					__( 'Unable to read the ComfyUI node catalog: %s', 'worldgraph' ),
					$response->get_error_message()
				)
			);
		}
		if ( wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return new WP_Error(
				'worldgraph_comfy_catalog_failed',
				sprintf(
					/* translators: %d: HTTP response status code. */
					__( 'ComfyUI returned HTTP %d from /object_info.', 'worldgraph' ),
					wp_remote_retrieve_response_code( $response )
				)
			);
		}

		$catalog = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $catalog ) ) {
			return new WP_Error( 'worldgraph_comfy_catalog_invalid', __( 'ComfyUI returned an unreadable node catalog.', 'worldgraph' ) );
		}

		set_transient( $key, $catalog, self::CATALOG_TTL );

		return $catalog;
	}

	/**
	 * Drop the cached ComfyUI catalog so the next check re-reads it.
	 *
	 * @param string $endpoint ComfyUI base URL.
	 */
	public static function flush_catalog( string $endpoint = '' ): void {
		$endpoint = '' !== $endpoint ? untrailingslashit( esc_url_raw( $endpoint ) ) : Local_ComfyUI::endpoint();
		if ( '' !== $endpoint ) {
			delete_transient( self::CATALOG_TRANSIENT . md5( $endpoint ) );
		}
	}
}
