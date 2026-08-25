<?php
/**
 * Generation REST API Controller for World Graph Studio.
 *
 * Handles asset generation requests and status tracking.
 *
 * @package WorldGraph
 */

namespace WorldGraph\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Generation Controller class.
 */
class Generation_Controller extends Base_Controller {

	/**
	 * CPT slug (not used).
	 *
	 * @var string
	 */
	protected $cpt = '';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'generation';

	/**
	 * Initialize the controller.
	 */
	public static function init(): void {
		Generation_Authorization::init();
		$instance = new self();
		add_action( 'rest_api_init', [ $instance, 'register_routes' ] );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Submit generation request.
		register_rest_route( 'worldgraph/v1', '/generation', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'submit_generation' ],
			'permission_callback' => [ $this, 'check_generation_create_permission' ],
			'args'                => [
				'type'       => [
					'description' => 'Generation type (image, video, audio).',
					'type'        => 'string',
					'required'    => true,
				],
				'prompt'     => [
					'description' => 'Generation prompt.',
					'type'        => 'string',
					'required'    => true,
				],
				'asset_id'   => [
					'description' => 'Associated asset ID.',
					'type'        => 'integer',
				],
				'params'     => [
					'description' => 'Legacy provider parameters retained for backward compatibility.',
					'type'        => 'object',
				],
				'run_values' => [
					'description' => 'Template-declared scalar overrides validated for this run.',
					'type'        => 'object',
				],
				'inputs'     => [
					'description' => 'Template prompt and media input slots.',
					'type'        => 'object',
				],
				'workflow'   => [
					'description' => 'Workflow template slug.',
					'type'        => 'string',
					'required'    => true,
				],
				'provider_type' => [
					'description' => 'Provider type slug.',
					'type'        => 'string',
				],
				'connection_id' => [
					'description' => 'Provider connection ID.',
					'type'        => 'integer',
				],
			],
		] );

		// SunoAPI.org requires a callback URL for generation requests. The
		// callback only wakes the canonical poller; provider payloads never mark
		// a job complete before WordPress fetches and imports the final result.
		register_rest_route( 'worldgraph/v1', '/generation/suno-callback', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'receive_suno_callback' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'connection_id' => [
					'type'     => 'integer',
					'required' => true,
				],
				'token'         => [
					'type'     => 'string',
					'required' => true,
				],
			],
		] );

		// Get generation status.
		register_rest_route( 'worldgraph/v1', '/generation/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_generation_status' ],
			'permission_callback' => [ $this, 'check_generation_read_permission' ],
			'args'                => [
				'id' => [
					'description' => 'Generation request ID.',
					'type'        => 'integer',
					'required'    => true,
				],
			],
		] );

		// Cancel generation.
		register_rest_route( 'worldgraph/v1', '/generation/(?P<id>\d+)/cancel', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'cancel_generation' ],
			'permission_callback' => [ $this, 'check_generation_manage_permission' ],
			'args'                => [
				'id' => [
					'description' => 'Generation request ID.',
					'type'        => 'integer',
					'required'    => true,
				],
			],
		] );

		// Get generation history for an asset.
		register_rest_route( 'worldgraph/v1', '/generation/asset/(?P<asset_id>\d+)/history', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_asset_history' ],
			'permission_callback' => [ $this, 'check_asset_history_permission' ],
			'args'                => [
				'asset_id' => [
					'description' => 'Asset ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'page'     => [ 'default' => 1 ],
				'per_page' => [ 'default' => 20, 'maximum' => 100 ],
			],
		] );

		// Inspect what a Template needs from ComfyUI, and whether it is installed.
		register_rest_route( 'worldgraph/v1', '/generation/templates/(?P<id>\d+)/requirements', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_template_requirements' ],
			'permission_callback' => [ $this, 'check_create_permission' ],
			'args'                => [
				'id'       => [
					'description' => 'Template post ID.',
					'type'        => 'integer',
					'required'    => true,
				],
				'validate' => [
					'description' => 'Also check the requirements against the configured ComfyUI instance.',
					'type'        => 'boolean',
					'default'     => true,
				],
			],
		] );
	}

	/** Restrict prompt/status data to the requester or an editor of its source. */
	public function check_generation_read_permission( WP_REST_Request $request ) {
		$generation_id = absint( $request->get_param( 'id' ) );
		$generation    = get_post( $generation_id );
		if ( ! $generation instanceof \WP_Post || 'worldgraph_gen' !== $generation->post_type ) {
			return new WP_Error( 'worldgraph_generation_not_found', 'Generation request not found.', [ 'status' => 404 ] );
		}

		$user_id      = get_current_user_id();
		$requester_id = absint( get_post_meta( $generation_id, Generation_Authorization::REQUESTER_META, true ) );
		$source_id    = absint( get_post_meta( $generation_id, '_worldgraph_gen_source_post_id', true ) ?: $generation->post_parent );
		if ( $user_id && ( $user_id === $requester_id || ( $source_id && current_user_can( 'edit_post', $source_id ) ) || current_user_can( 'edit_post', $generation_id ) ) ) {
			return true;
		}

		return new WP_Error( 'worldgraph_generation_forbidden', 'You are not allowed to view this generation request.', [ 'status' => $user_id ? 403 : 401 ] );
	}

	/** Generation history can reveal prompts, so require edit access to the Asset. */
	public function check_asset_history_permission( WP_REST_Request $request ) {
		$asset_id = absint( $request->get_param( 'asset_id' ) );
		if ( $asset_id && 'worldgraph_asset' === get_post_type( $asset_id ) && current_user_can( 'edit_post', $asset_id ) ) {
			return true;
		}

		return new WP_Error( 'worldgraph_generation_history_forbidden', 'You are not allowed to view generation history for this Asset.', [ 'status' => is_user_logged_in() ? 403 : 401 ] );
	}

	/** Only editors of a generation record may cancel it. */
	public function check_generation_manage_permission( WP_REST_Request $request ) {
		$permission = parent::check_create_permission( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$generation_id = absint( $request->get_param( 'id' ) );
		$generation = get_post( $generation_id );
		if ( ! $generation instanceof \WP_Post || 'worldgraph_gen' !== $generation->post_type || ! current_user_can( 'edit_post', $generation_id ) ) {
			return new WP_Error( 'worldgraph_generation_forbidden', 'You are not allowed to manage this generation request.', [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Authorize a generation request, including its source and media inputs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_generation_create_permission( WP_REST_Request $request ) {
		$permission = parent::check_create_permission( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		return Generation_Authorization::authorize_submission(
			(string) $request->get_param( 'type' ),
			absint( $request->get_param( 'asset_id' ) ),
			self::sanitize_inputs( $request->get_param( 'inputs' ) ),
			get_current_user_id()
		);
	}

	/**
	 * Authenticate a SunoAPI.org callback and wake the polling worker.
	 *
	 * @param WP_REST_Request $request Callback request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive_suno_callback( WP_REST_Request $request ) {
		$connection_id = absint( $request->get_param( 'connection_id' ) );
		$connection    = \WorldGraph\Utils\Connection_Repository::get( $connection_id );
		if ( ! $connection || 'suno' !== ( $connection['provider_type'] ?? '' ) ) {
			return new WP_Error( 'suno_callback_connection_invalid', 'The Suno callback Connection is invalid.', [ 'status' => 404 ] );
		}

		\WorldGraph\Utils\Connection_Adapters::load( 'suno' );
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( ! \WorldGraph\Utils\Suno_API::verify_callback_token( $connection_id, $token ) ) {
			return new WP_Error( 'suno_callback_unauthorized', 'The Suno callback token is invalid.', [ 'status' => 403 ] );
		}

		\WorldGraph\Utils\Generation_Batch::schedule();
		return new WP_REST_Response( [ 'accepted' => true ], 200 );
	}

	/**
	 * Return a Template's ComfyUI requirement manifest, optionally validated
	 * against the configured ComfyUI instance.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_template_requirements( WP_REST_Request $request ) {
		\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );
		$manifest = \WorldGraph\Utils\Comfy_Manifest::for_template( absint( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		if ( ! rest_sanitize_boolean( $request->get_param( 'validate' ) ) ) {
			return rest_ensure_response( $manifest );
		}

		$report = \WorldGraph\Utils\Comfy_Manifest::validate( absint( $request->get_param( 'id' ) ) );
		$manifest['validation'] = is_wp_error( $report )
			? [ 'ok' => false, 'error' => $report->get_error_message() ]
			: $report;

		return rest_ensure_response( $manifest );
	}

	/**
	 * Submit a generation request.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit_generation( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$prompt = $request->get_param( 'prompt' );
		$asset_id = $request->get_param( 'asset_id' ) ? absint( $request->get_param( 'asset_id' ) ) : null;
		$params = $request->get_param( 'params' );
		$params = is_array( $params ) ? $params : [];
		$run_values = $request->get_param( 'run_values' );
		$run_values = is_array( $run_values ) ? $run_values : [];
		$inputs = self::sanitize_inputs( $request->get_param( 'inputs' ) );
		$workflow = sanitize_text_field( (string) $request->get_param( 'workflow' ) );

		// Validate generation type.
		$valid_types = [ 'image', 'video', 'audio', 'text' ];
		if ( ! in_array( $type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_type', 'Invalid generation type.', [ 'status' => 400 ] );
		}

		$requester_id = get_current_user_id();
		$authorization = Generation_Authorization::authorize_submission( $type, absint( $asset_id ), $inputs, $requester_id );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		$template = self::resolve_active_template( $workflow );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$connection_id = absint( \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'connection_id' ) );
		$connection = \WorldGraph\Utils\Connection_Repository::get( $connection_id );
		$template_provider = sanitize_key( (string) \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'provider_type' ) );
		if ( ! $connection || '' === $template_provider || 'disabled' === $connection['status'] || $template_provider !== $connection['provider_type'] ) {
			return new WP_Error( 'invalid_template_connection', 'The selected Template and Connection must use the same provider.', [ 'status' => 400 ] );
		}
		$template_modality = sanitize_key( (string) \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'modality' ) );
		if ( $template_modality && $type !== \WorldGraph\Utils\Generation_Modality::output_type( $template_modality ) ) {
			return new WP_Error( 'generation_type_mismatch', 'The requested type must match the selected Template output type.', [ 'status' => 400 ] );
		}
		$prompt = \WorldGraph\Utils\Generation_Prompt_Profiles::apply(
			trim( wp_strip_all_tags( (string) $prompt ) ),
			absint( $asset_id ),
			'',
			(int) $template->ID
		);
		$modality = \WorldGraph\Utils\Generation_Modality::sanitize( $template_modality );
		$inputs   = self::resolve_template_media_inputs( (int) $template->ID, absint( $asset_id ), $inputs );
		$authorization = Generation_Authorization::authorize_submission( $type, absint( $asset_id ), $inputs, $requester_id );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}
		$missing_inputs = self::missing_required_media_inputs( $modality, $inputs );
		if ( ! empty( $missing_inputs ) ) {
			return new WP_Error(
				'worldgraph_generation_required_input_missing',
				sprintf(
					/* translators: %s: comma-separated required media input names. */
					__( 'The selected Template requires these media inputs: %s.', 'worldgraph' ),
					implode( ', ', $missing_inputs )
				),
				[ 'status' => 400, 'missing_inputs' => $missing_inputs ]
			);
		}
		\WorldGraph\Utils\Connection_Adapters::load( (string) $connection['provider_type'] );
		if ( ! \WorldGraph\Utils\Connection_Adapters::supports_generation( (string) $connection['provider_type'] ) ) {
			return new WP_Error( 'generation_provider_unsupported', 'The selected Connection has no registered generation client.', [ 'status' => 501 ] );
		}

		// Local ComfyUI needs its nodes/models physically installed; refuse the
		// request here (consulting the Connection's MCP server to auto-fetch
		// what it can) instead of letting it queue and fail in the WP-Cron worker.
		$use_local_comfyui = 'comfyui' === $connection['provider_type'] && 'local' === ( $connection['environment'] ?? '' );
		if ( $use_local_comfyui ) {
			$ready = \WorldGraph\Utils\Comfy_Manifest::ensure_ready( $template->ID, $connection_id );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}
		$provider_template_id = sanitize_text_field( (string) ( \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'provider_template_id' ) ?: get_post_meta( $template->ID, 'comfy_template_id', true ) ) );
		if ( 'fal' === $connection['provider_type'] && '' === $provider_template_id ) {
			$provider_template_id = sanitize_text_field( (string) ( $connection['model'] ?? '' ) );
		}
		if ( '' === $provider_template_id && ! $use_local_comfyui ) {
			return new WP_Error( 'missing_provider_template', 'The selected Template must reference a provider MCP Template.', [ 'status' => 400 ] );
		}
		if ( 'fal' === $connection['provider_type'] && ! \WorldGraph\Utils\Fal_MCP::endpoint_is_allowed( $connection, $provider_template_id ) ) {
			return new WP_Error( 'fal_endpoint_not_allowed', 'That fal model endpoint is not allowed by the selected Connection.', [ 'status' => 400 ] );
		}
		$provider_type = $connection['provider_type'];
		$workflow = '' !== $provider_template_id ? $provider_template_id : (string) $template->ID;
		try {
			$adapter = \WorldGraph\Utils\Connection_Adapters::generation_adapter(
				(string) $provider_type,
				$connection,
				$workflow,
				$use_local_comfyui ? 'local_comfyui' : ''
			);
			$client  = \WorldGraph\Utils\Connection_Adapters::generation_client( (string) $provider_type, $connection, $workflow, $adapter );
			$can_run = '' !== $client && is_callable( [ $client, 'run_template' ] );
		} catch ( \Throwable ) {
			return new WP_Error( 'generation_client_invalid', 'The selected Connection generation client could not be resolved safely.', [ 'status' => 501 ] );
		}
		if ( ! $can_run ) {
			return new WP_Error( 'generation_client_unavailable', 'The selected Connection generation client is unavailable.', [ 'status' => 501 ] );
		}
		$run_values = \WorldGraph\Utils\Template_Run_Controls::validate( (int) $template->ID, $run_values );
		if ( is_wp_error( $run_values ) ) {
			return $run_values;
		}
		// Legacy params remain permissive, but a local fixed seed is trusted only
		// after the selected Template accepts and normalizes it.
		$legacy_seed = null;
		if ( $use_local_comfyui && ! array_key_exists( 'seed', $run_values ) ) {
			$legacy_seed_key = array_key_exists( 'seed', $params )
				? 'seed'
				: ( array_key_exists( 'noise_seed', $params ) ? 'noise_seed' : '' );
			if ( '' !== $legacy_seed_key ) {
				$validated_seed = \WorldGraph\Utils\Template_Run_Controls::validate(
					(int) $template->ID,
					[ 'seed' => $params[ $legacy_seed_key ] ]
				);
				if ( ! is_wp_error( $validated_seed ) && array_key_exists( 'seed', $validated_seed ) ) {
					$legacy_seed = $validated_seed['seed'];
					unset( $params['seed'], $params['noise_seed'] );
					$params['seed'] = $legacy_seed;
				}
			}
		} elseif ( $use_local_comfyui ) {
			unset( $params['noise_seed'] );
		}
		// Provider/Template defaults are applied by the worker. Preserve the
		// original generic `params` contract, then let validated v1 run controls
		// override colliding legacy keys.
		$params = array_merge( $params, $run_values );

		// Create generation request post.
		$post_id = wp_insert_post( [
			'post_type'   => 'worldgraph_gen',
			'post_title'  => "Generation: {$type} - " . wp_strip_all_tags( $prompt ),
			'post_status' => 'draft',
			'post_parent' => $asset_id,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save generation metadata.
		update_post_meta( $post_id, '_worldgraph_gen_type', $type );
		update_post_meta( $post_id, '_worldgraph_gen_prompt', $prompt );
		update_post_meta( $post_id, '_worldgraph_gen_params', $params );
		update_post_meta( $post_id, '_worldgraph_gen_run_values', $run_values );
		if ( null !== $legacy_seed ) {
			update_post_meta( $post_id, '_worldgraph_gen_explicit_seed', $legacy_seed );
		}
		update_post_meta( $post_id, '_worldgraph_gen_inputs', $inputs );
		update_post_meta( $post_id, Generation_Authorization::REQUESTER_META, $requester_id );
		update_post_meta( $post_id, '_worldgraph_gen_workflow', $workflow );
		update_post_meta( $post_id, '_worldgraph_gen_template_id', $template->ID );
		update_post_meta( $post_id, '_worldgraph_gen_provider_type', $provider_type );
		update_post_meta( $post_id, '_worldgraph_gen_connection_id', $connection_id );
		update_post_meta( $post_id, '_worldgraph_gen_adapter', $adapter );
		update_post_meta( $post_id, '_worldgraph_gen_status', 'queued' );
		update_post_meta( $post_id, '_worldgraph_gen_created', current_time( 'mysql' ) );

		\WorldGraph\Utils\Generation_Batch::schedule();

		return rest_ensure_response( [
			'id'         => $post_id,
			'job_id'     => '',
			'status'     => 'queued',
			'type'       => $type,
			'provider_type' => $provider_type,
			'connection_id' => $connection_id,
			'created_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Merge server-owned Template media bindings beneath explicit request inputs.
	 *
	 * @param int                  $template_id Template post ID.
	 * @param int                  $source_id   Optional source Asset/post ID.
	 * @param array<string, mixed> $inputs      Sanitized explicit inputs.
	 * @return array<string, mixed>
	 */
	private static function resolve_template_media_inputs( int $template_id, int $source_id, array $inputs ): array {
		$bound = $source_id ? \WorldGraph\Utils\Template_Bindings::resolve( $template_id, $source_id ) : [];

		return array_merge( $bound, $inputs );
	}

	/**
	 * Required prompt text arrives through the top-level `prompt`; this check is
	 * only for required media slots declared by the resolved Template modality.
	 *
	 * @param string               $modality Registered Template modality.
	 * @param array<string, mixed> $inputs   Merged bound and explicit inputs.
	 * @return array<int, string>
	 */
	private static function missing_required_media_inputs( string $modality, array $inputs ): array {
		$required_media = array_intersect(
			\WorldGraph\Utils\Generation_Modality::required_inputs( $modality ),
			\WorldGraph\Utils\Generation_Modality::MEDIA_SLOTS
		);

		return array_values( array_filter( $required_media, static function ( string $slot ) use ( $inputs ): bool {
			return ! isset( $inputs[ $slot ] ) || ! is_scalar( $inputs[ $slot ] ) || '' === trim( (string) $inputs[ $slot ] );
		} ) );
	}

	/**
	 * Resolve an active Template by post ID, slug, or title reference.
	 *
	 * @param string $reference Template reference.
	 * @return \WP_Post|WP_Error
	 */
	private static function resolve_active_template( string $reference ) {
		$template = ctype_digit( $reference ) ? get_post( absint( $reference ) ) : null;
		if ( ! $template ) {
			$templates = get_posts( [
				'post_type'      => 'worldgraph_template',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'name'           => sanitize_title( $reference ),
			] );
			$template = $templates ? $templates[0] : null;
		}

		if ( ! $template instanceof \WP_Post || 'worldgraph_template' !== $template->post_type || 'publish' !== $template->post_status || 'active' !== \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'status' ) ) {
			return new WP_Error( 'invalid_template', 'An active Template is required for generation.', [ 'status' => 400 ] );
		}

		return $template;
	}

	/**
	 * Reduce submitted modality inputs to known slots and scalar values. Media
	 * slots stay as an attachment ID or URL; the provider client resolves and
	 * uploads them at submission time.
	 *
	 * @param mixed $inputs Raw `inputs` parameter.
	 * @return array<string, string>
	 */
	private static function sanitize_inputs( $inputs ): array {
		if ( ! is_array( $inputs ) ) {
			return [];
		}

		$slots     = array_merge( [ 'prompt', 'negative_prompt' ], \WorldGraph\Utils\Generation_Modality::MEDIA_SLOTS );
		$sanitized = [];
		foreach ( $slots as $slot ) {
			if ( ! isset( $inputs[ $slot ] ) || ! is_scalar( $inputs[ $slot ] ) ) {
				continue;
			}

			$value = trim( (string) $inputs[ $slot ] );
			if ( '' === $value ) {
				continue;
			}

			if ( ! in_array( $slot, \WorldGraph\Utils\Generation_Modality::MEDIA_SLOTS, true ) ) {
				$sanitized[ $slot ] = sanitize_textarea_field( $value );
				continue;
			}

			$sanitized[ $slot ] = preg_match( '#^https?://#', $value ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
		}

		return $sanitized;
	}

	/**
	 * Get generation status.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_generation_status( WP_REST_Request $request ) {
		$generation_id = absint( $request->get_param( 'id' ) );
		$job_id = get_post_meta( $generation_id, '_worldgraph_gen_job_id', true );
		$attachment_id = absint( get_post_meta( $generation_id, '_worldgraph_gen_attachment_id', true ) );
		$asset_id = absint( get_post_meta( $generation_id, '_worldgraph_gen_asset_id', true ) );
		$url = $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : '';
		$thumbnail_url = $attachment_id ? (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';

		$generation = [
			'id'            => $generation_id,
			'job_id'        => $job_id,
			'status'        => get_post_meta( $generation_id, '_worldgraph_gen_status', true ) ?: 'unknown',
			'type'          => get_post_meta( $generation_id, '_worldgraph_gen_type', true ),
			'prompt'        => get_post_meta( $generation_id, '_worldgraph_gen_prompt', true ),
			'run_values'    => (array) get_post_meta( $generation_id, '_worldgraph_gen_run_values', true ),
			'provider_type' => get_post_meta( $generation_id, '_worldgraph_gen_provider_type', true ),
			'connection_id' => absint( get_post_meta( $generation_id, '_worldgraph_gen_connection_id', true ) ),
			'created'       => get_post_meta( $generation_id, '_worldgraph_gen_created', true ),
			'attachment_id' => $attachment_id,
			'asset_id'      => $asset_id,
			'url'           => $url,
			'thumbnail_url' => $thumbnail_url,
			'error'         => (string) get_post_meta( $generation_id, '_worldgraph_gen_error', true ),
		];

		return rest_ensure_response( $generation );
	}

	/**
	 * Cancel a generation request.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel_generation( WP_REST_Request $request ) {
		$generation_id = absint( $request->get_param( 'id' ) );
		$job_id = get_post_meta( $generation_id, '_worldgraph_gen_job_id', true );
		$current_status = sanitize_key( (string) get_post_meta( $generation_id, '_worldgraph_gen_status', true ) );

		if ( ! in_array( $current_status, [ 'queued', 'submitted', 'import_retry' ], true ) ) {
			return new WP_Error( 'worldgraph_generation_not_cancellable', 'This generation request is already running or has reached a terminal state.', [ 'status' => 409 ] );
		}
		if ( false === update_post_meta( $generation_id, '_worldgraph_gen_status', 'cancelled', $current_status ) ) {
			return new WP_Error( 'worldgraph_generation_cancel_conflict', 'The generation request changed while cancellation was being applied.', [ 'status' => 409 ] );
		}
		delete_post_meta( $generation_id, '_worldgraph_videodraft_resolved_inputs' );
		delete_post_meta( $generation_id, '_worldgraph_videodraft_resolved_request' );

		return rest_ensure_response( [
			'id'       => $generation_id,
			'job_id'   => $job_id,
			'status'   => 'cancelled',
			'message'  => 'Generation request cancelled.',
		] );
	}

	/**
	 * Get generation history for an asset.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_asset_history( WP_REST_Request $request ) {
		$asset_id = absint( $request->get_param( 'asset_id' ) );

		$generations = new \WP_Query( [
			'post_type'      => 'worldgraph_gen',
			'post_parent'    => $asset_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$history = [];

		if ( $generations->have_posts() ) {
			foreach ( $generations->posts as $post ) {
				$history[] = [
					'id'       => $post->ID,
					'job_id'   => get_post_meta( $post->ID, '_worldgraph_gen_job_id', true ),
					'type'     => get_post_meta( $post->ID, '_worldgraph_gen_type', true ),
					'prompt'   => get_post_meta( $post->ID, '_worldgraph_gen_prompt', true ),
					'status'   => get_post_meta( $post->ID, '_worldgraph_gen_status', true ),
					'provider_type' => get_post_meta( $post->ID, '_worldgraph_gen_provider_type', true ),
					'connection_id' => absint( get_post_meta( $post->ID, '_worldgraph_gen_connection_id', true ) ),
					'created'  => get_post_meta( $post->ID, '_worldgraph_gen_created', true ),
				];
			}
			wp_reset_postdata();
		}

		return rest_ensure_response( $history );
	}

}
