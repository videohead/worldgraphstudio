<?php
/**
 * Generate asset tools.
 *
 * Queues story-aware image and video outputs, imports completed media into the
 * WordPress media library, and links it back to the originating story element.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WorldGraph\AI\AI_Image_Client;
use WorldGraph\REST\Generation_Authorization;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset generator.
 */
class Asset_Generator {
	/** Content types captured from generated-media download responses. */
	private static $download_mime_types = [];

	/**
	 * Meta key holding supporting media attached to a story element.
	 */
	const GALLERY_META = '_worldgraph_asset_gallery_ids';

	/**
	 * Maximum accepted size for a generated video download, in bytes.
	 */
	const MAX_VIDEO_BYTES = 209715200; // 200MB.

	/**
	 * Accepted mime types for a generated video, keyed by file extension.
	 *
	 * @var array<string, string>
	 */
	const VIDEO_MIME_TYPES = [
		'mp4'  => 'video/mp4',
		'm4v'  => 'video/mp4',
		'webm' => 'video/webm',
		'mov'  => 'video/quicktime',
		'avi'  => 'video/avi',
	];

	/** Accepted generated-audio mime types, keyed by file extension. */
	const AUDIO_MIME_TYPES = [
		'mp3'  => 'audio/mpeg',
		'wav'  => 'audio/wav',
		'm4a'  => 'audio/mpeg',
		'aac'  => 'audio/aac',
		'ogg'  => 'audio/ogg',
		'flac' => 'audio/flac',
	];

	/** Maximum accepted generated-audio size (50MB). */
	const MAX_AUDIO_BYTES = 52428800;

	/**
	 * Meta key on an attachment/asset pointing at the source story element.
	 */
	const SOURCE_META = '_worldgraph_generated_from';

	/** Write-ahead journal for a generation job's media-import side effects. */
	const IMPORT_JOURNAL_META = '_worldgraph_gen_import_journal';

	/**
	 * Map of source CPT to the Asset relationship field it populates.
	 *
	 * @var array<string, string>
	 */
	const ASSET_RELATIONSHIP_FIELDS = [
		'worldgraph_character'        => 'character',
		'worldgraph_location'         => 'location',
		'worldgraph_scene'            => 'scene',
	];

	/**
	 * CPTs that can have representative media generated for them.
	 *
	 * Templates and provider connections are configuration, not story elements.
	 *
	 * @return array<int, string>
	 */
	public static function supported_post_types(): array {
		return array_keys( Generation_Workflows::definitions() );
	}

	/**
	 * Whether a post can have representative media generated for it.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function supports( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post && in_array( $post->post_type, self::supported_post_types(), true );
	}

	/**
	 * Build a detailed, intent-aware generation prompt from a story element.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $intent       Optional representative-media intent.
	 * @param string $base_prompt  Optional author-edited base prompt.
	 * @param int    $template_id  Optional selected Template for final policy.
	 * @return string
	 */
	public static function build_prompt( int $post_id, string $intent = '', string $base_prompt = '', int $template_id = 0 ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$prompt = Generation_Workflows::compose_prompt( $post_id, $intent, $base_prompt, $template_id );

		/**
		 * Filter the generated media prompt for a story element.
		 *
		 * @param string   $prompt Generated prompt.
		 * @param \WP_Post $post   Source post.
		 * @param string   $intent Representative-media intent, when supplied.
		 * @param int      $template_id Selected Template whose policy composed the prompt, or zero.
		 */
		$prompt = (string) apply_filters( 'worldgraph_generate_asset_prompt', $prompt, $post, $intent, $template_id );
		if ( $template_id > 0 ) {
			return Generation_Prompt_Profiles::apply( $prompt, $post_id, $intent, $template_id );
		}

		$output = Generation_Workflows::intent( (string) $post->post_type, $intent );
		$policy = Generation_Prompt_Policy::for_template(
			0,
			[
				'output_type' => (string) ( $output['type'] ?? 'image' ),
				'post_type'   => (string) $post->post_type,
				'intent'      => $intent,
			]
		);
		return Generation_Prompt_Policy::finalize_text( $prompt, $policy );
	}

	/**
	 * Queue an image, video, or audio generation job for a story element.
	 *
	 * @param int   $post_id Source post ID.
	 * @param array $args Optional output, additional prompt, Template, and linking settings.
	 * @return array|WP_Error
	 */
	public static function queue_for_post( int $post_id, array $args = [] ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::supports( $post_id ) ) {
			return new WP_Error( 'worldgraph_asset_invalid_post', __( 'That post cannot have a World Graph Studio asset generated for it.', 'worldgraph' ), [ 'status' => 404 ] );
		}

		$args   = wp_parse_args( $args, [
			'type'               => 'image',
			'prompt'             => '',
			'prompt_is_composed' => false,
			'prompt_profiled'    => false,
			'set_featured'       => true,
			'create_asset'       => true,
			'template_id'        => 0,
			'inputs'             => [],
			'run_values'         => [],
			'default_values'     => [],
			'requested_run_values' => [],
			'profile_values'     => [],
			'profile_values_frozen' => false,
			'run_values_validated' => false,
			'run_defaults_frozen' => false,
			'intent'             => '',
			'batch_id'           => 0,
			'batch_step'         => -1,
			'requester_id'       => 0,
			'initial_status'     => 'queued',
			'schedule'           => true,
		] );
		$type        = sanitize_key( (string) $args['type'] );
		$template_id = absint( $args['template_id'] );
		if ( ! in_array( $type, [ 'image', 'video', 'audio' ], true ) ) {
			return new WP_Error( 'worldgraph_asset_invalid_type', __( 'Generated story media must be an image, video, or audio file.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		$requester_id = absint( $args['requester_id'] ) ?: get_current_user_id();
		$batch_id     = absint( $args['batch_id'] );
		$batch_step   = (int) $args['batch_step'];
		$authorization = Generation_Authorization::authorize_submission( $type, $post_id, [], $requester_id );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		$intent = sanitize_key( (string) $args['intent'] );
		if ( '' !== $intent ) {
			$intent_definition = Generation_Workflows::intent( $post->post_type, $intent );
			if ( ( empty( $intent_definition ) || $type !== ( $intent_definition['type'] ?? '' ) ) && ! self::batch_declares_task( $batch_id, $batch_step, $post_id, $type, $intent ) ) {
				return new WP_Error( 'worldgraph_asset_intent_invalid', __( 'That representative-media intent does not apply to this item and output type.', 'worldgraph' ), [ 'status' => 400 ] );
			}
		}
		$provided_prompt = trim( wp_strip_all_tags( (string) $args['prompt'] ) );
		$prompt_is_composed = rest_sanitize_boolean( $args['prompt_is_composed'] );
		$prompt_profiled    = rest_sanitize_boolean( $args['prompt_profiled'] );
		$frozen_batch_prompt = self::is_frozen_batch_prompt_candidate( $batch_id, $prompt_is_composed, $prompt_profiled );
		$prompt          = $prompt_is_composed
			? $provided_prompt
			: self::build_prompt( $post_id, $intent, $provided_prompt, $template_id );
		if ( ! $prompt_is_composed ) {
			$prompt_profiled = true;
		}
		if ( '' === $prompt ) {
			return new WP_Error( 'worldgraph_asset_prompt_missing', __( 'A generation prompt could not be built from this item.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		$profile = self::project_media_profile( $post_id );

		if ( ! $template_id || ! self::is_active_template( $template_id ) ) {
			return new WP_Error( 'worldgraph_asset_invalid_template', __( 'That Template is not available to generate from.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		$template_modality = Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template_id, 'modality' ) );
		if ( $type !== Generation_Modality::output_type( $template_modality ) ) {
			return new WP_Error( 'worldgraph_asset_template_type_mismatch', __( 'The selected Template does not produce the required representative-media type.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		if ( 'worldgraph_sound' === $post->post_type && 'audio' === $type ) {
			$copy_validation = Generation_Workflows::validate_sound_prompt_copy( $post_id, $template_id );
			if ( is_wp_error( $copy_validation ) ) {
				return $copy_validation;
			}
		}
		$connection_id = absint( worldgraph_get_field_value( $template_id, 'connection_id' ) );
		$connection = Connection_Repository::get( $connection_id );
		$template_provider = sanitize_key( (string) worldgraph_get_field_value( $template_id, 'provider_type' ) );
		if ( ! $connection || '' === $template_provider || 'disabled' === $connection['status'] || $template_provider !== $connection['provider_type'] ) {
			return new WP_Error( 'worldgraph_asset_invalid_connection', __( 'That Template and Connection must use the same provider.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		$provider = $connection['provider_type'];
		Connection_Adapters::load( (string) $provider );
		$provider_template_id = sanitize_text_field( (string) ( worldgraph_get_field_value( $template_id, 'provider_template_id' ) ?: get_post_meta( $template_id, 'comfy_template_id', true ) ) );
		if ( 'fal' === $provider && '' === $provider_template_id ) {
			$provider_template_id = sanitize_text_field( (string) ( $connection['model'] ?? '' ) );
		}
		$use_local_template = 'comfyui' === $provider;
		if ( ! Connection_Adapters::supports_generation( (string) $provider ) ) {
			return new WP_Error( 'worldgraph_asset_provider_unsupported', __( 'This provider has no World Graph Studio asset generation adapter yet.', 'worldgraph' ), [ 'status' => 501 ] );
		}
		if ( '' === $provider_template_id && ! $use_local_template ) {
			return new WP_Error( 'worldgraph_asset_missing_provider_template', __( 'That Template has no provider MCP Template selected.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		if ( $use_local_template && ! Local_ComfyUI::is_configured( $connection_id ) ) {
			return new WP_Error( 'worldgraph_local_comfyui_unconfigured', __( 'The Template Connection has no configured local ComfyUI API endpoint.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		if ( 'comfy_cloud' === $provider && ! Comfy_Cloud_MCP::is_configured( $connection_id ) ) {
			return new WP_Error( 'worldgraph_comfy_mcp_unconfigured', __( 'The Template Connection has no configured Comfy Cloud API key.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		if ( 'fal' === $provider && '' === trim( (string) $connection['credential_reference'] ) ) {
			return new WP_Error( 'worldgraph_fal_unconfigured', __( 'The Template Connection has no fal API key or credential reference.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		if ( 'fal' === $provider && ! Fal_MCP::endpoint_is_allowed( $connection, $provider_template_id ) ) {
			return new WP_Error( 'worldgraph_fal_endpoint_not_allowed', __( 'That fal model endpoint is not allowed by the Template Connection.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		if ( 'videodraft' === $provider && '' === trim( (string) $connection['credential_reference'] ) ) {
			return new WP_Error( 'worldgraph_videodraft_unconfigured', __( 'The Template Connection has no VideoDraft token or credential reference.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		if ( 'videodraft' === $provider && ! in_array( $provider_template_id, VideoDraft_API::GENERATION_TOOLS, true ) ) {
			return new WP_Error( 'worldgraph_videodraft_tool_invalid', __( 'That Template does not select a supported VideoDraft generation tool.', 'worldgraph' ), [ 'status' => 400 ] );
		}
		if ( 'openrouter' === $provider && '' === trim( (string) $connection['credential_reference'] ) ) {
			return new WP_Error( 'worldgraph_openrouter_unconfigured', __( 'The Template Connection has no OpenRouter API key or credential reference.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$bound_inputs = [];
		if ( $template_id ) {
			$bound_inputs = array_merge(
				Template_Bindings::resolve( $template_id, $post_id ),
				self::sanitize_media_inputs( $args['inputs'] )
			);
			$missing = array_values( array_filter(
				array_intersect( Generation_Modality::required_inputs( $template_modality ), Generation_Modality::MEDIA_SLOTS ),
				static function ( string $slot ) use ( $bound_inputs ): bool {
					return ! isset( $bound_inputs[ $slot ] ) || '' === trim( (string) $bound_inputs[ $slot ] );
				}
			) );
			if ( ! empty( $missing ) ) {
				return new WP_Error(
					'worldgraph_asset_missing_template_input',
					sprintf(
						/* translators: %s: comma-separated missing input slot names. */
						__( 'That Template needs %s, which could not be found on this story element.', 'worldgraph' ),
						implode( ', ', $missing )
					),
					[ 'status' => 400 ]
				);
			}
		}

		$authorization = Generation_Authorization::authorize_submission( $type, $post_id, $bound_inputs, $requester_id );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}
		if ( $use_local_template ) {
			$requirements = self::ensure_local_template_requirements( $template_id, $connection_id );
			if ( is_wp_error( $requirements ) ) {
				return $requirements;
			}
		}
		$trusted_batch_values = $batch_id && rest_sanitize_boolean( $args['run_values_validated'] );
		$description          = Template_Run_Controls::describe( $template_id );
		$submitted_run_values = is_array( $args['run_values'] ) ? $args['run_values'] : [];
		$default_values       = [];
		$requested_run_values = [];
		if ( $trusted_batch_values ) {
			$run_values = $submitted_run_values;
			if ( rest_sanitize_boolean( $args['run_defaults_frozen'] ) ) {
				$default_values       = is_array( $args['default_values'] ) ? $args['default_values'] : [];
				$requested_run_values = is_array( $args['requested_run_values'] ) ? $args['requested_run_values'] : [];
			}
		} else {
			$requested_run_values = Template_Run_Controls::validate_description( $description, $submitted_run_values );
			if ( is_wp_error( $requested_run_values ) ) {
				return $requested_run_values;
			}
			$default_values = Generation_Run_Defaults::runtime_overrides( $post_id, $template_id, $description );
			$run_values     = Template_Run_Controls::validate_description( $description, array_merge( $default_values, $requested_run_values ) );
			if ( is_wp_error( $run_values ) ) {
				return $run_values;
			}
		}
		$profile_values = $trusted_batch_values && rest_sanitize_boolean( $args['profile_values_frozen'] ) && is_array( $args['profile_values'] )
			? $args['profile_values']
			: self::project_template_defaults( $template_id, $profile, $description );

		// A server-composed, already-profiled batch prompt may bypass current
		// policy finalization only after its complete frozen task is verified.
		$frozen_prompt_verified = false;
		if ( $frozen_batch_prompt ) {
			$batch_validation = self::validate_batch_task( $batch_id, $batch_step, $post_id, $type, $intent, $template_id, $requester_id, $prompt, $run_values, $profile_values, $bound_inputs );
			if ( is_wp_error( $batch_validation ) ) {
				return $batch_validation;
			}
			$frozen_prompt_verified = true;
		}

		if ( ! $frozen_prompt_verified ) {
			if ( ! $prompt_profiled ) {
				$prompt = Generation_Prompt_Profiles::apply( $prompt, $post_id, $intent, $template_id );
			} else {
				$prompt = Generation_Prompt_Policy::finalize_text(
					$prompt,
					Generation_Prompt_Policy::for_template(
						$template_id,
						[
							'output_type' => $type,
							'post_type'   => (string) $post->post_type,
							'intent'      => $intent,
						]
					)
				);
			}

			if ( $batch_id ) {
				$batch_validation = self::validate_batch_task( $batch_id, $batch_step, $post_id, $type, $intent, $template_id, $requester_id, $prompt, $run_values, $profile_values, $bound_inputs );
				if ( is_wp_error( $batch_validation ) ) {
					return $batch_validation;
				}
			}
		}

		// Resolve executable dispatch metadata before creating a job so a broken
		// third-party resolver cannot leave an orphaned generation post behind.
		$template = $use_local_template ? (string) $template_id : $provider_template_id;
		try {
			$adapter = Connection_Adapters::generation_adapter(
				(string) $provider,
				$connection,
				$template,
				$use_local_template ? 'local_comfyui' : ''
			);
			$client  = Connection_Adapters::generation_client( (string) $provider, $connection, $template, $adapter );
			$can_run = '' !== $client && is_callable( [ $client, 'run_template' ] );
		} catch ( \Throwable ) {
			return new WP_Error( 'worldgraph_asset_generation_client_invalid', __( 'The Template generation client could not be resolved safely.', 'worldgraph' ), [ 'status' => 501 ] );
		}
		if ( ! $can_run ) {
			return new WP_Error( 'worldgraph_asset_generation_client_unavailable', __( 'The Template generation client is unavailable.', 'worldgraph' ), [ 'status' => 501 ] );
		}

		$job_id = wp_insert_post( [
			'post_type'   => 'worldgraph_gen',
			'post_title'  => sprintf(
				/* translators: 1: generated media type, 2: source title. */
				__( '%1$s generation: %2$s', 'worldgraph' ),
				ucfirst( $type ),
				$post->post_title
			),
			'post_status' => 'draft',
			'post_parent' => $post_id,
		], true );

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		// A user-selected Template wins; otherwise keep the legacy per-CPT
		// workflow name so existing jobs without a Template keep working.
		$template_input = array_merge( self::fal_template_input( $template_id ), Template_Run_Controls::defaults( $template_id ) );
		$params         = $profile_values;
		$params         = array_merge( $params, $run_values );
		$initial_status = in_array( $args['initial_status'], [ 'staged', 'queued' ], true ) ? (string) $args['initial_status'] : 'queued';
		if ( 'staged' === $initial_status && ! $batch_id ) {
			wp_delete_post( $job_id, true );
			return new WP_Error( 'worldgraph_asset_staged_batch_required', __( 'A staged generation job must belong to a representative-media batch.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		$job_meta = [
			'_worldgraph_gen_type'             => $type,
			'_worldgraph_gen_prompt'           => $prompt,
			'_worldgraph_gen_prompt_hash'      => hash( 'sha256', $prompt ),
			'_worldgraph_gen_params'           => $params,
			'_worldgraph_gen_run_values'       => $run_values,
			'_worldgraph_gen_default_values'   => $default_values,
			'_worldgraph_gen_requested_run_values' => $requested_run_values,
			'_worldgraph_gen_profile_values'   => $profile_values,
			'_worldgraph_gen_template_input'   => $template_input,
			'_worldgraph_gen_workflow'         => $template,
			'_worldgraph_gen_adapter'          => $adapter,
			'_worldgraph_gen_template_id'      => $template_id,
			'_worldgraph_gen_provider_type'    => $provider,
			'_worldgraph_gen_connection_id'    => $connection_id,
			'_worldgraph_gen_source_post_id'   => $post_id,
			'_worldgraph_gen_requested_by'     => $requester_id,
			'_worldgraph_gen_set_featured'     => 'image' === $type && rest_sanitize_boolean( $args['set_featured'] ),
			'_worldgraph_gen_create_asset'     => rest_sanitize_boolean( $args['create_asset'] ),
			'_worldgraph_gen_workflow_version' => Generation_Workflows::WORKFLOW_VERSION,
			'_worldgraph_gen_created'          => current_time( 'mysql' ),
		];
		if ( ! empty( $bound_inputs ) ) {
			$job_meta['_worldgraph_gen_inputs'] = $bound_inputs;
		}
		if ( '' !== $intent ) {
			$job_meta[ Generation_Workflows::INTENT_META ] = $intent;
		}
		if ( $batch_id ) {
			$job_meta[ Generation_Workflows::BATCH_ID_META ] = $batch_id;
			$job_meta[ Generation_Workflows::STEP_META ]     = $batch_step;
		}
		// Status is the commit marker read by cron, so every required field must
		// already be durable before a worker can claim this job.
		$job_meta['_worldgraph_gen_status'] = $initial_status;
		if ( ! self::store_generation_job_meta( (int) $job_id, $job_meta ) ) {
			wp_delete_post( $job_id, true );
			return new WP_Error( 'worldgraph_asset_job_storage_failed', __( 'WordPress could not persist the generation job safely.', 'worldgraph' ), [ 'status' => 500 ] );
		}
		if ( 'queued' === $initial_status && rest_sanitize_boolean( $args['schedule'] ) ) {
			Generation_Batch::schedule();
		}

		return [
			'generation_id' => (int) $job_id,
			'post_id'       => $post_id,
			'prompt'        => $prompt,
			'status'        => $initial_status,
			'type'          => $type,
			'intent'        => $intent,
			'batch_id'      => $batch_id,
		];
	}

	/** Validate that a staged child exactly matches its frozen parent task. */
	private static function validate_batch_task( int $batch_id, int $step, int $post_id, string $type, string $intent, int $template_id, int $requester_id, string $prompt, array $run_values = [], array $profile_values = [], array $inputs = [] ) {
		$batch = get_post( $batch_id );
		$plan  = get_post_meta( $batch_id, Generation_Workflows::BATCH_PLAN_META, true );
		$task  = is_array( $plan ) && isset( $plan[ $step ] ) && is_array( $plan[ $step ] ) ? $plan[ $step ] : [];
		$expected_fingerprint = (string) ( $task['run_controls_fingerprint'] ?? '' );
		$current_controls     = $template_id ? Template_Run_Controls::describe( $template_id ) : [];
		$current_fingerprint  = (string) ( $current_controls['fingerprint'] ?? '' );
		if (
			! $batch instanceof \WP_Post
			|| 'worldgraph_gen' !== $batch->post_type
			|| ! in_array( get_post_meta( $batch_id, Generation_Workflows::BATCH_KIND_META, true ), [ Generation_Workflows::REPRESENTATIVE_BATCH, Generation_Workflows::DEMONSTRATION_BATCH ], true )
			|| $requester_id !== absint( get_post_meta( $batch_id, '_worldgraph_gen_requested_by', true ) )
			|| $post_id !== absint( $task['source_id'] ?? 0 )
			|| $type !== ( $task['type'] ?? '' )
			|| $intent !== ( $task['intent'] ?? '' )
			|| $template_id !== absint( $task['template_id'] ?? 0 )
			|| $run_values !== (array) ( $task['run_values'] ?? [] )
			|| ( array_key_exists( 'profile_values', $task ) && $profile_values !== (array) $task['profile_values'] )
			|| ( array_key_exists( 'resolved_inputs', $task ) && $inputs !== (array) $task['resolved_inputs'] )
			|| ( '' !== $expected_fingerprint && ! hash_equals( $expected_fingerprint, $current_fingerprint ) )
		) {
			return new WP_Error( 'worldgraph_asset_batch_task_invalid', __( 'The generation job does not match its frozen representative-media batch task.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		if ( ! self::prompt_matches_frozen_task( $task, $prompt ) ) {
			return new WP_Error( 'worldgraph_asset_batch_prompt_invalid', __( 'The generation prompt does not match the prompt accepted with this frozen batch. Refresh and start a new batch.', 'worldgraph' ), [ 'status' => 409 ] );
		}

		$workflow_version            = absint( get_post_meta( $batch_id, '_worldgraph_gen_workflow_version', true ) );
		$expected_policy_fingerprint = (string) ( $task['prompt_policy_fingerprint'] ?? '' );
		if ( $workflow_version >= Generation_Workflows::PROMPT_POLICY_FINGERPRINT_VERSION || '' !== $expected_policy_fingerprint ) {
			$current_policy_fingerprint = Generation_Prompt_Policy::fingerprint(
				Generation_Prompt_Policy::for_template(
					$template_id,
					[
						'output_type' => $type,
						'post_type'   => (string) ( $task['source_type'] ?? '' ),
						'intent'      => $intent,
					]
				)
			);
			if ( ! self::prompt_policy_matches_frozen_task( $task, $workflow_version, $current_policy_fingerprint ) ) {
				return new WP_Error( 'worldgraph_asset_batch_prompt_policy_changed', __( 'The Template prompt policy changed after this batch was accepted. Refresh and start a new batch.', 'worldgraph' ), [ 'status' => 409 ] );
			}
		}

		return true;
	}

	/** Verify both the supplied prompt and stored prompt against the frozen digest. */
	private static function prompt_matches_frozen_task( array $task, string $prompt ): bool {
		$expected = (string) ( $task['prompt_hash'] ?? '' );
		if ( ! self::is_sha256( $expected ) || ! array_key_exists( 'prompt', $task ) || ! is_string( $task['prompt'] ) ) {
			return false;
		}

		return hash_equals( $expected, hash( 'sha256', $prompt ) )
			&& hash_equals( $expected, hash( 'sha256', $task['prompt'] ) );
	}

	/** Only a composed/profiled prompt tied to a batch may seek a bypass. */
	private static function is_frozen_batch_prompt_candidate( int $batch_id, bool $prompt_is_composed, bool $prompt_profiled ): bool {
		return $batch_id > 0 && $prompt_is_composed && $prompt_profiled;
	}

	/** Compare a current effective policy with the snapshot required by the plan version. */
	private static function prompt_policy_matches_frozen_task( array $task, int $workflow_version, string $current ): bool {
		$expected = (string) ( $task['prompt_policy_fingerprint'] ?? '' );
		if ( $workflow_version < Generation_Workflows::PROMPT_POLICY_FINGERPRINT_VERSION && '' === $expected ) {
			return true;
		}

		return self::is_sha256( $expected ) && self::is_sha256( $current ) && hash_equals( $expected, $current );
	}

	/** Whether a value is a canonical lowercase SHA-256 digest. */
	private static function is_sha256( string $value ): bool {
		return 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $value );
	}

	/** Whether a frozen batch task authorizes a non-representative intent. */
	private static function batch_declares_task( int $batch_id, int $step, int $post_id, string $type, string $intent ): bool {
		if ( $batch_id < 1 || $step < 0 ) {
			return false;
		}
		$plan = get_post_meta( $batch_id, Generation_Workflows::BATCH_PLAN_META, true );
		$task = is_array( $plan ) && isset( $plan[ $step ] ) && is_array( $plan[ $step ] ) ? $plan[ $step ] : [];

		return $post_id === absint( $task['source_id'] ?? 0 )
			&& $type === (string) ( $task['type'] ?? '' )
			&& $intent === (string) ( $task['intent'] ?? '' );
	}

	/** Keep unattended media inputs to the provider-neutral allowlisted slots. */
	private static function sanitize_media_inputs( $inputs ): array {
		if ( ! is_array( $inputs ) ) {
			return [];
		}
		$sanitized = [];
		foreach ( Generation_Modality::MEDIA_SLOTS as $slot ) {
			if ( ! array_key_exists( $slot, $inputs ) || ! is_scalar( $inputs[ $slot ] ) ) {
				continue;
			}
			$value = trim( (string) $inputs[ $slot ] );
			if ( '' !== $value ) {
				$sanitized[ $slot ] = $value;
			}
		}
		return $sanitized;
	}

	/** Persist critical job metadata and verify it before a worker can claim it. */
	private static function store_generation_job_meta( int $job_id, array $meta ): bool {
		foreach ( $meta as $key => $value ) {
			update_post_meta( $job_id, (string) $key, is_array( $value ) ? wp_slash( $value ) : $value );
			$stored = get_post_meta( $job_id, (string) $key, true );
			if ( is_array( $value ) ? $stored !== $value : (string) $stored !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read fal model inputs from a Template's provider-neutral configuration.
	 *
	 * Supported shapes are {"input": {...}} (preferred),
	 * {"parameters": {...}}, or a flat object for simple configurations.
	 */
	private static function fal_template_input( int $template_id ): array {
		$decoded = json_decode( (string) worldgraph_get_field_value( $template_id, 'configuration_json' ), true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		if ( isset( $decoded['input'] ) && is_array( $decoded['input'] ) ) {
			return $decoded['input'];
		}
		if ( isset( $decoded['parameters'] ) && is_array( $decoded['parameters'] ) ) {
			return $decoded['parameters'];
		}

		return $decoded;
	}

	/**
	 * Validate local ComfyUI Template requirements before queueing. Delegates
	 * to the shared gate so submission, smoke checks, and panel listing agree
	 * on what "ready" means and equally benefit from MCP-assisted install.
	 *
	 * @param int $template_id   Template post ID.
	 * @param int $connection_id Connection post ID.
	 * @return true|WP_Error
	 */
	private static function ensure_local_template_requirements( int $template_id, int $connection_id ) {
		return Comfy_Manifest::ensure_ready( $template_id, $connection_id );
	}

	/**
	 * Resolve the media profile from the containing project.
	 *
	 * @param int $post_id Source story element ID.
	 * @return array<string, int|float|string>
	 */
	public static function project_media_profile( int $post_id ): array {
		$project_id = self::resolve_project_id( $post_id );

		$profile = [
			'width'        => 1024,
			'height'       => 1024,
			'aspect_ratio' => '1:1',
			'frame_rate'   => 24,
		];

		if ( $project_id ) {
			$profile['width']        = max( 1, absint( worldgraph_get_field_value( $project_id, 'frame_width' ) ?: $profile['width'] ) );
			$profile['height']       = max( 1, absint( worldgraph_get_field_value( $project_id, 'frame_height' ) ?: $profile['height'] ) );
			$profile['aspect_ratio'] = sanitize_text_field( (string) ( worldgraph_get_field_value( $project_id, 'aspect_ratio' ) ?: $profile['aspect_ratio'] ) );
			$profile['frame_rate']   = max( 0.001, (float) ( worldgraph_get_field_value( $project_id, 'frame_rate' ) ?: $profile['frame_rate'] ) );
		}

		if ( in_array( (string) get_post_type( $post_id ), [ 'worldgraph_shot', 'worldgraph_sound' ], true ) ) {
			$duration = self::source_duration_seconds( (string) worldgraph_get_field_value( $post_id, 'duration' ) );
			if ( null !== $duration ) {
				$profile['duration'] = $duration;
			}
		}

		$profile['size'] = $profile['width'] . 'x' . $profile['height'];

		return $profile;
	}

	/** Convert common authored duration formats into a provider control value. */
	private static function source_duration_seconds( string $value ): ?float {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			$seconds = (float) $value;
			return $seconds > 0 ? $seconds : null;
		}
		if ( 1 === preg_match( '/^PT(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?$/i', $value, $matches ) ) {
			$seconds = (float) ( $matches[1] ?? 0 ) * 3600
				+ (float) ( $matches[2] ?? 0 ) * 60
				+ (float) ( $matches[3] ?? 0 );
			return $seconds > 0 ? $seconds : null;
		}
		if ( 1 === preg_match( '/^(?:(\d+):)?(\d{1,2}):(\d{2}(?:\.\d+)?)$/', $value, $matches ) ) {
			$seconds = (float) ( $matches[1] ?? 0 ) * 3600 + (float) $matches[2] * 60 + (float) $matches[3];
			return $seconds > 0 ? $seconds : null;
		}
		if ( 1 === preg_match( '/^(\d+(?:\.\d+)?)\s*(?:s|sec|secs|second|seconds)$/i', $value, $matches ) ) {
			return (float) $matches[1] > 0 ? (float) $matches[1] : null;
		}

		return null;
	}

	/**
	 * Build the Project-derived provider parameters for one Template.
	 *
	 * The descriptor projection validates each output value independently and
	 * adds aliases such as `fps` only when the Template proves it accepts them.
	 * VideoDraft and OpenRouter retain their documented aspect-ratio baseline
	 * even when an older catalog record omitted that schema field.
	 *
	 * @param int   $template_id Template post ID.
	 * @param array $profile     Project media profile.
	 * @param array $description Optional already-derived run-control DTO.
	 * @return array<string,scalar>
	 */
	public static function project_template_defaults( int $template_id, array $profile, array $description = [] ): array {
		if ( empty( $description ) ) {
			$description = Template_Run_Controls::describe( $template_id );
		}
		$defaults = Template_Run_Controls::profile_defaults( $description, $profile );
		$provider = sanitize_key( (string) worldgraph_get_field_value( $template_id, 'provider_type' ) );
		if ( in_array( $provider, [ 'videodraft', 'openrouter' ], true ) && ! isset( $defaults['aspect_ratio'] ) ) {
			$fallback = Template_Run_Controls::describe_configuration( [
				'provider_schema' => [
					'properties' => [ 'aspect_ratio' => [ 'type' => 'string' ] ],
				],
			] );
			$defaults = array_merge( Template_Run_Controls::profile_defaults( $fallback, $profile ), $defaults );
		}

		return $defaults;
	}

	/**
	 * Walk canonical ownership ancestry to find the containing Project. Imports
	 * may store parent-to-child `contains` edges, while editors may store the
	 * equivalent child-to-parent `belongs_to` edge.
	 *
	 * @param int $post_id Source story element ID.
	 * @return int Project post ID, or 0 when none is found.
	 */
	private static function resolve_project_id( int $post_id ): int {
		return Generation_Workflows::project_id_for_source( $post_id );
	}

	/**
	 * Resolve the Connection record that owns a generation provider, so
	 * generation jobs and their log entries can be traced back to their
	 * parent Connection. Mirrors the connection lookup fallback used by
	 * Local_ComfyUI and the Setup Wizard's managed "generation" connection.
	 *
	 * @param string $provider 'local_comfyui' or 'comfy_mcp'.
	 * @return int Connection post ID, or 0 when none is configured.
	 */
	private static function resolve_connection_id( string $provider ): int {
		if ( 'local_comfyui' === $provider ) {
			return (int) ( Connection_Repository::get_default( 'comfyui', 'local' ) ?? 0 );
		}

		return (int) ( Connection_Repository::get_default( 'comfy_cloud', 'production' ) ?? 0 );
	}

	/**
	 * Whether a post ID is a published, active worldgraph_template.
	 *
	 * @param int $template_id Template post ID.
	 * @return bool
	 */
	private static function is_active_template( int $template_id ): bool {
		$template = get_post( $template_id );

		return $template instanceof \WP_Post
			&& 'worldgraph_template' === $template->post_type
			&& 'publish' === $template->post_status
			&& 'active' === worldgraph_get_field_value( $template_id, 'status' );
	}

	/**
	 * Generate an image for a story element and attach it.
	 *
	 * @param int   $post_id Source post ID.
	 * @param array $args    Optional: prompt, size, model, set_featured, create_asset.
	 * @return array|WP_Error
	 */
	public static function generate_for_post( int $post_id, array $args = [] ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::supports( $post_id ) ) {
			return new WP_Error( 'worldgraph_asset_invalid_post', __( 'That post cannot have a World Graph Studio asset generated for it.', 'worldgraph' ), [ 'status' => 404 ] );
		}

		$args = wp_parse_args( $args, [
			'prompt'       => '',
			'model'        => '',
			'set_featured' => true,
			'create_asset' => true,
			'template_id'  => 0,
		] );

		$prompt = self::build_prompt( $post_id, '', trim( wp_strip_all_tags( (string) $args['prompt'] ) ) );

		$client = new AI_Image_Client();
		$image  = $client->generate( $prompt, [
			'size'  => self::project_media_profile( $post_id )['size'],
			'model' => (string) $args['model'],
		] );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$attachment_id = self::sideload( $image, $post );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$set_featured = rest_sanitize_boolean( $args['set_featured'] );
		if ( $set_featured && post_type_supports( $post->post_type, 'thumbnail' ) ) {
			set_post_thumbnail( $post->ID, $attachment_id );
		}

		self::add_to_gallery( $post->ID, $attachment_id );

		$asset_id = 0;
		if ( rest_sanitize_boolean( $args['create_asset'] ) && 'worldgraph_asset' !== $post->post_type ) {
			$asset_id = self::create_asset_record( $post, $attachment_id, $prompt, $image );
		} elseif ( 'worldgraph_asset' === $post->post_type ) {
			self::store_asset_fields( $post->ID, $attachment_id, $prompt, $image );
		}

		/**
		 * Fires after a World Graph Studio asset image has been generated and attached.
		 *
		 * @param int      $attachment_id Generated attachment ID.
		 * @param \WP_Post $post          Source post.
		 * @param int      $asset_id      Created Asset post ID, or 0.
		 */
		do_action( 'worldgraph_asset_generated', $attachment_id, $post, $asset_id );

		return [
			'post_id'       => $post->ID,
			'attachment_id' => $attachment_id,
			'asset_id'      => $asset_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'thumbnail_url' => (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'featured'      => $set_featured && get_post_thumbnail_id( $post->ID ) === $attachment_id,
			'prompt'        => $prompt,
			'model'         => $image['model'],
			'size'          => $image['size'],
		];
	}

	/**
	 * Import completed generated media and link it to the originating post.
	 *
	 * @param int   $job_id Generation job ID.
	 * @param array $result MCP job status result.
	 * @return array|WP_Error Imported asset data.
	 */
	public static function import_completed_job( int $job_id, array $result ) {
		$post_id = (int) get_post_field( 'post_parent', $job_id );
		$post    = get_post( $post_id );
		$has_story_source = $post instanceof \WP_Post && self::supports( $post_id );
		if ( ! $has_story_source ) {
			$post = get_post( $job_id );
			if ( ! $post instanceof \WP_Post ) {
				return new WP_Error( 'worldgraph_gen_source_missing', __( 'The generation record no longer exists.', 'worldgraph' ) );
			}
		}
		if ( ! self::recover_import_journal( $job_id ) ) {
			return new WP_Error( 'worldgraph_gen_journal_failed', __( 'WordPress could not clean up an interrupted media import.', 'worldgraph' ) );
		}

		$provider       = (string) get_post_meta( $job_id, '_worldgraph_gen_provider_type', true );
		$adapter        = (string) get_post_meta( $job_id, '_worldgraph_gen_adapter', true );
		$requested_type = sanitize_key( (string) get_post_meta( $job_id, '_worldgraph_gen_type', true ) );
		$is_videodraft = 'videodraft' === $provider || 'videodraft' === $adapter;
		$typed_video_urls = self::find_typed_output_urls( $result, 'video' );
		$typed_audio_urls = self::find_typed_output_urls( $result, 'audio' );
		$typed_image_urls = self::find_typed_output_urls( $result, 'image' );
		$video_url  = (string) ( $typed_video_urls[0] ?? ( $is_videodraft ? '' : self::find_result_video_url( $result ) ) );
		$audio_urls = $is_videodraft ? $typed_audio_urls : array_values( array_unique( array_merge( $typed_audio_urls, self::find_result_audio_urls( $result ) ) ) );
		$audio_url  = (string) ( $audio_urls[0] ?? '' );
		$image_url  = (string) ( $typed_image_urls[0] ?? ( $is_videodraft ? '' : self::find_result_url( $result ) ) );

		// A video-only workflow reports its file through the same result keys
		// as an image, so do not try to decode the video as a still frame.
		if ( $image_url === $video_url ) {
			$image_url = '';
		}
		if ( $image_url === $audio_url ) {
			$image_url = '';
		}

		if ( '' === $image_url && '' === $video_url && '' === $audio_url && empty( $result['audio_data'] ) && empty( $result['audio_items'] ) ) {
			return new WP_Error( 'worldgraph_gen_output_missing', __( 'The generation provider completed the job but did not return downloadable media.', 'worldgraph' ) );
		}

		$attachment_id           = 0;
		$image_attachment_id     = 0;
		$video_attachment_id     = 0;
		$audio_attachment_id     = 0;
		$media                   = [];
		$generated_attachment_ids = [];
		if ( ! self::begin_import_journal( $job_id, $post->ID, (int) get_post_thumbnail_id( $post ) ) ) {
			return new WP_Error( 'worldgraph_gen_journal_failed', __( 'WordPress could not prepare a recoverable media import.', 'worldgraph' ) );
		}
		if ( '' !== $image_url ) {
			$download = $is_videodraft
				? self::download_to_file( $image_url, AI_Image_Client::MAX_IMAGE_BYTES, $job_id, $adapter )
				: self::download_bytes( $image_url, $adapter, $job_id );
			if ( is_wp_error( $download ) ) {
				return self::rollback_media_import( $job_id, $download );
			}

			$media = is_array( $download ) ? self::validate_image_file( $download ) : self::validate_image_bytes( $download );
			if ( is_wp_error( $media ) ) {
				return self::rollback_media_import( $job_id, $media );
			}

			$attachment_id = self::sideload( $media, $post, $job_id );
			if ( is_wp_error( $attachment_id ) ) {
				return self::rollback_media_import( $job_id, $attachment_id );
			}
			$generated_attachment_ids[] = $attachment_id;
			$image_attachment_id         = $attachment_id;

			if ( rest_sanitize_boolean( get_post_meta( $job_id, '_worldgraph_gen_set_featured', true ) ) && post_type_supports( $post->post_type, 'thumbnail' ) ) {
				if ( ! self::journal_featured_attachment( $job_id, $attachment_id ) ) {
					return self::rollback_media_import( $job_id, new WP_Error( 'worldgraph_gen_journal_failed', __( 'WordPress could not journal the generated featured image.', 'worldgraph' ) ) );
				}
				set_post_thumbnail( $post->ID, $attachment_id );
				if ( $attachment_id !== (int) get_post_thumbnail_id( $post->ID ) ) {
					return self::rollback_media_import( $job_id, new WP_Error( 'worldgraph_asset_link_failed', __( 'WordPress could not set the generated featured image.', 'worldgraph' ) ) );
				}
			}
			self::add_to_gallery( $post->ID, $attachment_id );
		}

		// Import the source video alongside its still frame, or on its own for
		// a text-to-video Template that produces no separate frame.
		if ( '' !== $video_url ) {
			$video_download = self::download_to_file( $video_url, self::MAX_VIDEO_BYTES, $job_id, $adapter );
			if ( is_wp_error( $video_download ) ) {
				return self::rollback_media_import( $job_id, $video_download );
			}
			$video = is_array( $video_download )
				? self::validate_video_file( $video_download, $video_url, true )
				: self::validate_video_bytes( $video_download, $video_url );
			if ( is_wp_error( $video ) ) {
				return self::rollback_media_import( $job_id, $video );
			}
			$video_attachment_id = self::sideload( $video, $post, $job_id );
			if ( is_wp_error( $video_attachment_id ) ) {
				return self::rollback_media_import( $job_id, $video_attachment_id );
			}
			$generated_attachment_ids[] = $video_attachment_id;
			self::add_to_gallery( $post->ID, $video_attachment_id );
			if ( ! $attachment_id || 'video' === $requested_type ) {
				$attachment_id = $video_attachment_id;
				$media         = $video;
			}
		}

		// Synchronous providers may return audio bytes directly; URL-based audio
		// is downloaded through the same WordPress-owned media boundary.
		if ( ! empty( $result['audio_data'] ) || '' !== $audio_url ) {
			$audio_mime = (string) ( $result['audio_mime'] ?? ( 'suno' === $provider ? 'audio/mpeg' : '' ) );
			if ( ! empty( $result['audio_data'] ) ) {
				$audio = self::validate_audio_bytes( (string) $result['audio_data'], $audio_mime, $audio_url );
			} else {
				$audio_download = self::download_to_file( $audio_url, self::MAX_AUDIO_BYTES, $job_id, $adapter );
				$audio = is_wp_error( $audio_download ) ? $audio_download : self::validate_audio_file( $audio_download, $audio_url );
			}
			if ( is_wp_error( $audio ) ) {
				return self::rollback_media_import( $job_id, $audio );
			}
			$audio_attachment_id = self::sideload( $audio, $post, $job_id );
			if ( is_wp_error( $audio_attachment_id ) ) {
				return self::rollback_media_import( $job_id, $audio_attachment_id );
			}
			$generated_attachment_ids[] = $audio_attachment_id;
			self::add_to_gallery( $post->ID, $audio_attachment_id );
			if ( ! $attachment_id || 'audio' === $requested_type ) {
				$attachment_id = $audio_attachment_id;
				$media = $audio;
			}
		}
		foreach ( (array) ( $result['audio_items'] ?? [] ) as $audio_item ) {
			if ( ! is_array( $audio_item ) || empty( $audio_item['data'] ) ) {
				return self::rollback_media_import( $job_id, new WP_Error( 'worldgraph_gen_invalid_payload', __( 'ElevenLabs returned an unreadable voice preview.', 'worldgraph' ) ) );
			}
			$audio = self::validate_audio_bytes( (string) $audio_item['data'], (string) ( $audio_item['mime'] ?? '' ), '' );
			if ( is_wp_error( $audio ) ) {
				return self::rollback_media_import( $job_id, $audio );
			}
			$audio_attachment_id = self::sideload( $audio, $post, $job_id );
			if ( is_wp_error( $audio_attachment_id ) ) {
				return self::rollback_media_import( $job_id, $audio_attachment_id );
			}
			if ( ! empty( $audio_item['generated_voice_id'] ) ) {
				update_post_meta( $audio_attachment_id, '_worldgraph_elevenlabs_generated_voice_id', sanitize_text_field( (string) $audio_item['generated_voice_id'] ) );
			}
			$generated_attachment_ids[] = $audio_attachment_id;
			self::add_to_gallery( $post->ID, $audio_attachment_id );
			if ( ! $attachment_id || 'audio' === $requested_type ) {
				$attachment_id = $audio_attachment_id;
				$media = $audio;
			}
		}

		// Providers such as fal can return multiple images. Every advertised
		// media URL must become a WordPress attachment before the job completes.
		$result_urls = $is_videodraft
			? array_merge( $typed_image_urls, $typed_video_urls, $typed_audio_urls )
			: self::find_result_urls( $result );
		$additional_urls = array_values( array_diff( array_unique( $result_urls ), [ $image_url, $video_url, $audio_url, '' ] ) );
		foreach ( $additional_urls as $additional_url ) {
			$is_video = self::is_video_url( $additional_url ) || in_array( $additional_url, $typed_video_urls, true );
			$is_audio = in_array( $additional_url, $audio_urls, true ) || self::is_audio_url( $additional_url );
			$additional_download = $is_videodraft || $is_video || $is_audio
				? self::download_to_file( $additional_url, $is_video ? self::MAX_VIDEO_BYTES : ( $is_audio ? self::MAX_AUDIO_BYTES : AI_Image_Client::MAX_IMAGE_BYTES ), $job_id, $adapter )
				: self::download_bytes( $additional_url, $adapter, $job_id );
			if ( is_wp_error( $additional_download ) ) {
				return self::rollback_media_import( $job_id, $additional_download );
			}
			$additional_media = $is_video
				? ( is_array( $additional_download ) ? self::validate_video_file( $additional_download, $additional_url, true ) : self::validate_video_bytes( $additional_download, $additional_url ) )
				: ( $is_audio
					? ( is_array( $additional_download ) ? self::validate_audio_file( $additional_download, $additional_url ) : self::validate_audio_bytes( $additional_download, 'suno' === $provider ? 'audio/mpeg' : '', $additional_url ) )
					: ( is_array( $additional_download ) ? self::validate_image_file( $additional_download ) : self::validate_image_bytes( $additional_download ) ) );
			if ( is_wp_error( $additional_media ) ) {
				return self::rollback_media_import( $job_id, $additional_media );
			}
			$additional_attachment_id = self::sideload( $additional_media, $post, $job_id );
			if ( is_wp_error( $additional_attachment_id ) ) {
				return self::rollback_media_import( $job_id, $additional_attachment_id );
			}
			$generated_attachment_ids[] = $additional_attachment_id;
			if ( ! $is_video && ! $is_audio && ! $image_attachment_id ) {
				$image_attachment_id = $additional_attachment_id;
			}
			self::add_to_gallery( $post->ID, $additional_attachment_id );
		}

		if ( ( 'image' === $requested_type && ! $image_attachment_id ) || ( 'video' === $requested_type && ! $video_attachment_id ) || ( 'audio' === $requested_type && ! $audio_attachment_id ) || ! $attachment_id ) {
			return self::rollback_media_import( $job_id, new WP_Error( 'worldgraph_gen_output_missing', __( 'The generated media could not be imported into the media library.', 'worldgraph' ) ) );
		}

		$prompt   = (string) get_post_meta( $job_id, '_worldgraph_gen_prompt', true );
		$asset_id = 0;
		if ( ( ! $has_story_source || rest_sanitize_boolean( get_post_meta( $job_id, '_worldgraph_gen_create_asset', true ) ) ) && 'worldgraph_asset' !== $post->post_type ) {
			$job_params     = get_post_meta( $job_id, '_worldgraph_gen_params', true );
			$profile_values = get_post_meta( $job_id, '_worldgraph_gen_profile_values', true );
			$output_size    = is_array( $job_params ) ? (string) ( $job_params['size'] ?? '' ) : '';
			if ( '' === $output_size && is_array( $job_params ) && isset( $job_params['width'], $job_params['height'] ) ) {
				$output_size = $job_params['width'] . 'x' . $job_params['height'];
			} elseif ( '' === $output_size && is_array( $profile_values ) && isset( $profile_values['width'], $profile_values['height'] ) ) {
				$output_size = $profile_values['width'] . 'x' . $profile_values['height'];
			}
			$asset_id = self::create_asset_record( $post, $attachment_id, $prompt, array_merge( $media, [ 'model' => $provider ?: 'generation-mcp', 'size' => $output_size, 'revised_prompt' => '', 'workflow' => (string) get_post_meta( $job_id, '_worldgraph_gen_workflow', true ) ] ), $job_id );
		}

		if ( ! in_array( $attachment_id, $generated_attachment_ids, true ) ) {
			$generated_attachment_ids[] = $attachment_id;
		}

		$intent = sanitize_key( (string) get_post_meta( $job_id, Generation_Workflows::INTENT_META, true ) );
		if ( '' !== $intent ) {
			foreach ( $generated_attachment_ids as $generated_attachment_id ) {
				update_post_meta( $generated_attachment_id, Generation_Workflows::INTENT_META, $intent );
			}
			if ( $asset_id ) {
				update_post_meta( $asset_id, Generation_Workflows::INTENT_META, $intent );
			}
		}
		$batch_id = absint( get_post_meta( $job_id, Generation_Workflows::BATCH_ID_META, true ) );
		if ( $batch_id ) {
			foreach ( $generated_attachment_ids as $generated_attachment_id ) {
				update_post_meta( $generated_attachment_id, Generation_Workflows::BATCH_ID_META, $batch_id );
			}
			if ( $asset_id ) {
				update_post_meta( $asset_id, Generation_Workflows::BATCH_ID_META, $batch_id );
			}
		}
		$provenance = [
			'_worldgraph_gen_job_id'           => $job_id,
			'_worldgraph_gen_source_post_id'   => $post_id,
			'_worldgraph_gen_type'             => $requested_type,
			'_worldgraph_gen_template_id'      => absint( get_post_meta( $job_id, '_worldgraph_gen_template_id', true ) ),
			'_worldgraph_gen_provider_type'    => $provider,
			'_worldgraph_gen_connection_id'    => absint( get_post_meta( $job_id, '_worldgraph_gen_connection_id', true ) ),
			'_worldgraph_gen_prompt_hash'      => (string) get_post_meta( $job_id, '_worldgraph_gen_prompt_hash', true ),
			'_worldgraph_gen_workflow_version' => absint( get_post_meta( $job_id, '_worldgraph_gen_workflow_version', true ) ),
		];
		foreach ( $provenance as $key => $value ) {
			foreach ( $generated_attachment_ids as $generated_attachment_id ) {
				update_post_meta( $generated_attachment_id, $key, $value );
			}
			if ( $asset_id ) {
				update_post_meta( $asset_id, $key, $value );
			}
		}

		return [ 'attachment_id' => $attachment_id, 'attachment_ids' => $generated_attachment_ids, 'asset_id' => $asset_id, 'url' => (string) wp_get_attachment_url( $attachment_id ) ];
	}

	/** Build a Media Library filename that remains identifiable outside WordPress. */
	private static function generated_filename( \WP_Post $post, string $extension, int $job_id = 0 ): string {
		$project_id     = self::resolve_project_id( (int) $post->ID );
		$project        = $project_id ? get_post( $project_id ) : null;
		$project_prefix = $project_id ? (string) worldgraph_get_field_value( $project_id, 'project_slug' ) : '';
		if ( '' === trim( $project_prefix ) && $project instanceof \WP_Post ) {
			$project_prefix = $project->post_name ?: $project->post_title;
		}
		$project_prefix = sanitize_title( $project_prefix ) ?: 'unassigned-project';
		$type           = sanitize_title( preg_replace( '/^worldgraph_/', '', $post->post_type ) ) ?: 'asset';
		$source         = sanitize_title( $post->post_name ?: $post->post_title ) ?: (string) $post->ID;
		$intent         = $job_id ? sanitize_title( (string) get_post_meta( $job_id, Generation_Workflows::INTENT_META, true ) ) : '';
		$tokens         = [ $project_prefix, $type ];
		if ( $source !== $project_prefix ) {
			$tokens[] = $source;
		}
		if ( '' !== $intent ) {
			$tokens[] = $intent;
		}
		$tokens[] = $job_id ? 'job-' . $job_id : gmdate( 'Ymd-His' );

		return sanitize_file_name( implode( '-', array_map( static function ( string $token ): string {
			return substr( $token, 0, 60 );
		}, $tokens ) ) . '.' . sanitize_key( $extension ) );
	}

	/** Build a readable Media Library title with Project, type, source, and intent. */
	private static function generated_media_title( \WP_Post $post, string $mime, int $job_id = 0 ): string {
		$project_id    = self::resolve_project_id( (int) $post->ID );
		$project_title = $project_id ? (string) get_the_title( $project_id ) : __( 'Unassigned project', 'worldgraph' );
		$labels        = worldgraph_get_all_cpts();
		$type_label    = (string) ( $labels[ $post->post_type ] ?? __( 'Asset', 'worldgraph' ) );
		$intent        = $job_id ? sanitize_key( (string) get_post_meta( $job_id, Generation_Workflows::INTENT_META, true ) ) : '';
		$definition    = '' !== $intent ? Generation_Workflows::intent( $post->post_type, $intent ) : [];
		$intent_label  = (string) ( $definition['label'] ?? '' );
		$media_label   = 0 === strpos( $mime, 'video/' ) ? __( 'Video', 'worldgraph' ) : ( 0 === strpos( $mime, 'audio/' ) ? __( 'Audio', 'worldgraph' ) : __( 'Image', 'worldgraph' ) );
		$parts         = [ $project_title, $type_label ];
		if ( $post->ID !== $project_id ) {
			$parts[] = $post->post_title;
		}
		$parts[] = '' !== $intent_label ? $intent_label : $media_label;

		return implode( ' — ', array_filter( $parts ) );
	}

	/**
	 * Store raw image bytes in the media library.
	 *
	 * @param array    $image Image payload from AI_Image_Client.
	 * @param \WP_Post $post   Source post.
	 * @param int      $job_id Generation job ID, when this is a queued import.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function sideload( array $image, \WP_Post $post, int $job_id = 0 ) {
		$filename = self::generated_filename( $post, (string) $image['extension'], $job_id );

		$checked = wp_check_filetype( $filename, null );
		if ( empty( $checked['type'] ) || $checked['type'] !== $image['mime'] ) {
			self::delete_temp_media( $image );
			return new WP_Error( 'worldgraph_asset_filetype_blocked', __( 'This site does not allow uploads of the generated image type.', 'worldgraph' ), [ 'status' => 400 ] );
		}

		if ( ! empty( $image['file'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$sideload_file = [
				'name'     => $filename,
				'type'     => $image['mime'],
				'tmp_name' => $image['file'],
				'error'    => 0,
				'size'     => (int) ( $image['size'] ?? 0 ),
			];
			$upload = wp_handle_sideload( $sideload_file, [ 'test_form' => false ] );
		} else {
			$upload = wp_upload_bits( $filename, null, $image['data'] );
		}
		if ( ! empty( $upload['error'] ) ) {
			self::delete_temp_media( $image );
			return new WP_Error( 'worldgraph_asset_upload_failed', (string) $upload['error'], [ 'status' => 500 ] );
		}

		$title = self::generated_media_title( $post, (string) $image['mime'], $job_id );

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $image['mime'],
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$upload['file'],
			$post->ID,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			return $attachment_id;
		}
		if ( $job_id && ! self::journal_attachment( $job_id, (int) $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'worldgraph_gen_journal_failed', __( 'WordPress could not journal the generated media attachment.', 'worldgraph' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );
		update_post_meta( $attachment_id, self::SOURCE_META, $post->ID );

		return (int) $attachment_id;
	}

	/** Delete a streamed temporary media file if WordPress did not move it. */
	private static function delete_temp_media( array $media ): void {
		if ( ! empty( $media['file'] ) && is_string( $media['file'] ) && file_exists( $media['file'] ) ) {
			wp_delete_file( $media['file'] );
		}
	}

	/** Start a durable record of side effects before importing provider media. */
	private static function begin_import_journal( int $job_id, int $post_id, int $previous_thumbnail_id ): bool {
		return self::update_import_journal(
			$job_id,
			[
				'version'                => 1,
				'post_id'                => $post_id,
				'previous_thumbnail_id'  => $previous_thumbnail_id,
				'featured_attachment_id' => 0,
				'attachment_ids'          => [],
				'asset_ids'               => [],
				'temp_files'              => [],
			]
		);
	}

	/** Persist a newly created attachment before generating metadata or links. */
	private static function journal_attachment( int $job_id, int $attachment_id ): bool {
		return self::append_import_journal_value( $job_id, 'attachment_ids', $attachment_id );
	}

	/** Persist the generated attachment that is about to become featured. */
	private static function journal_featured_attachment( int $job_id, int $attachment_id ): bool {
		$journal = get_post_meta( $job_id, self::IMPORT_JOURNAL_META, true );
		if ( ! is_array( $journal ) ) {
			return false;
		}
		$journal['featured_attachment_id'] = $attachment_id;
		return self::update_import_journal( $job_id, $journal );
	}

	/** Persist a generated Asset post so a crashed import cannot orphan it. */
	private static function journal_asset( int $job_id, int $asset_id ): bool {
		return self::append_import_journal_value( $job_id, 'asset_ids', $asset_id );
	}

	/** Persist a temporary download path before the remote request writes to it. */
	private static function journal_temp_file( int $job_id, string $file ): bool {
		return self::append_import_journal_value( $job_id, 'temp_files', $file );
	}

	/** Append one unique value to a list in the current import journal. */
	private static function append_import_journal_value( int $job_id, string $key, $value ): bool {
		$journal = get_post_meta( $job_id, self::IMPORT_JOURNAL_META, true );
		if ( ! is_array( $journal ) ) {
			return false;
		}
		$values = isset( $journal[ $key ] ) && is_array( $journal[ $key ] ) ? $journal[ $key ] : [];
		if ( ! in_array( $value, $values, true ) ) {
			$values[] = $value;
		}
		$journal[ $key ] = $values;
		return self::update_import_journal( $job_id, $journal );
	}

	/** Store a journal and treat an already-identical value as success. */
	private static function update_import_journal( int $job_id, array $journal ): bool {
		$updated = update_post_meta( $job_id, self::IMPORT_JOURNAL_META, $journal );
		return false !== $updated || $journal === get_post_meta( $job_id, self::IMPORT_JOURNAL_META, true );
	}

	/**
	 * Remove side effects from an interrupted import before retrying it.
	 *
	 * The thumbnail is restored only while it still points at this import's
	 * generated attachment, preserving a later editor's explicit change.
	 */
	public static function recover_import_journal( int $job_id ): bool {
		$journal = get_post_meta( $job_id, self::IMPORT_JOURNAL_META, true );
		if ( ! is_array( $journal ) || empty( $journal ) ) {
			return true;
		}

		$post_id                = absint( $journal['post_id'] ?? 0 );
		$previous_thumbnail_id  = absint( $journal['previous_thumbnail_id'] ?? 0 );
		$featured_attachment_id = absint( $journal['featured_attachment_id'] ?? 0 );
		$attachment_ids         = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $journal['attachment_ids'] ?? [] ) ) ) ) );
		$asset_ids              = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $journal['asset_ids'] ?? [] ) ) ) ) );
		$clean                  = true;

		if ( $post_id && $featured_attachment_id && $featured_attachment_id === (int) get_post_thumbnail_id( $post_id ) ) {
			if ( $previous_thumbnail_id && 'attachment' === get_post_type( $previous_thumbnail_id ) ) {
				set_post_thumbnail( $post_id, $previous_thumbnail_id );
				$clean = $previous_thumbnail_id === (int) get_post_thumbnail_id( $post_id ) && $clean;
			} else {
				delete_post_thumbnail( $post_id );
				$clean = 0 === (int) get_post_thumbnail_id( $post_id ) && $clean;
			}
		}

		if ( $post_id && ! empty( $attachment_ids ) ) {
			$current_gallery = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post_id, self::GALLERY_META, true ) ) ) );
			$gallery         = array_values( array_diff( $current_gallery, $attachment_ids ) );
			if ( $gallery !== $current_gallery ) {
				update_post_meta( $post_id, self::GALLERY_META, $gallery );
				$stored_gallery = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post_id, self::GALLERY_META, true ) ) ) );
				$clean          = $gallery === $stored_gallery && $clean;
			}
		}

		foreach ( $asset_ids as $asset_id ) {
			if ( 'worldgraph_asset' === get_post_type( $asset_id ) ) {
				wp_delete_post( $asset_id, true );
				$clean = ! get_post( $asset_id ) && $clean;
			}
		}
		foreach ( $attachment_ids as $attachment_id ) {
			if ( 'attachment' === get_post_type( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id, true );
				$clean = ! get_post( $attachment_id ) && $clean;
			}
		}

		delete_post_meta( $job_id, '_worldgraph_gen_attachment_id' );
		delete_post_meta( $job_id, '_worldgraph_gen_attachment_ids' );
		delete_post_meta( $job_id, '_worldgraph_gen_asset_id' );
		$clean = self::delete_journal_temp_files( $journal ) && $clean;
		if ( $clean ) {
			delete_post_meta( $job_id, self::IMPORT_JOURNAL_META );
			$clean = ! is_array( get_post_meta( $job_id, self::IMPORT_JOURNAL_META, true ) );
		}

		return $clean;
	}

	/** Clear recovery state after attachment metadata and final status are durable. */
	public static function commit_import_journal( int $job_id ): bool {
		$journal = get_post_meta( $job_id, self::IMPORT_JOURNAL_META, true );
		if ( ! is_array( $journal ) || empty( $journal ) ) {
			return true;
		}
		$clean = self::delete_journal_temp_files( $journal );
		if ( ! $clean ) {
			return false;
		}
		delete_post_meta( $job_id, self::IMPORT_JOURNAL_META );
		return ! is_array( get_post_meta( $job_id, self::IMPORT_JOURNAL_META, true ) );
	}

	/** Delete only temporary files created by this importer. */
	private static function delete_journal_temp_files( array $journal ): bool {
		$clean    = true;
		$temp_dir = realpath( get_temp_dir() );
		foreach ( array_unique( array_filter( (array) ( $journal['temp_files'] ?? [] ), 'is_string' ) ) as $file ) {
			$file_dir = realpath( dirname( $file ) );
			$basename = basename( $file );
			$owned    = 0 === strpos( $basename, 'worldgraph-generated-media' ) || 0 === strpos( $basename, 'worldgraph-videodraft-media' );
			if ( ! $owned || ! file_exists( $file ) || ! $temp_dir || $temp_dir !== $file_dir ) {
				continue;
			}
			wp_delete_file( $file );
			$clean = ! file_exists( $file ) && $clean;
		}
		return $clean;
	}

	/** Roll back a partial multi-output import before retrying. */
	private static function rollback_media_import( int $job_id, WP_Error $error ): WP_Error {
		if ( ! self::recover_import_journal( $job_id ) ) {
			return new WP_Error(
				'worldgraph_gen_cleanup_failed',
				__( 'WordPress could not finish rolling back the interrupted media import.', 'worldgraph' ),
				[ 'cause' => $error->get_error_code() ]
			);
		}
		return $error;
	}

	/**
	 * Find the first image URL in a Comfy MCP job result.
	 *
	 * @param array $result MCP result payload.
	 * @return string
	 */
	private static function find_result_url( array $result ): string {
		foreach ( [ 'image_url', 'imageUrl', 'output_url', 'outputUrl', 'url', 'audio_url', 'audioUrl' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_string( $result[ $key ] ) && filter_var( $result[ $key ], FILTER_VALIDATE_URL ) ) {
				return $result[ $key ];
			}
		}

		foreach ( $result as $value ) {
			if ( is_array( $value ) ) {
				$url = self::find_result_url( $value );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
	}

	/** Find all media URLs advertised in a nested provider result. */
	private static function find_result_urls( array $result ): array {
		$urls = [];
		foreach ( [ 'image_url', 'imageUrl', 'video_url', 'videoUrl', 'audio_url', 'audioUrl', 'speech_url', 'output_url', 'outputUrl', 'url', 'public_url' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_string( $result[ $key ] ) && filter_var( $result[ $key ], FILTER_VALIDATE_URL ) ) {
				$urls[] = $result[ $key ];
			}
		}
		foreach ( [ 'outputUrls', 'output_urls' ] as $key ) {
			foreach ( (array) ( $result[ $key ] ?? [] ) as $url ) {
				if ( is_string( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
					$urls[] = $url;
				}
			}
		}
		foreach ( $result as $value ) {
			if ( is_array( $value ) ) {
				$urls = array_merge( $urls, self::find_result_urls( $value ) );
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/** Find provider-normalized output_media URLs for one media kind. */
	private static function find_typed_output_urls( array $result, string $kind ): array {
		$urls = [];
		foreach ( (array) ( $result['output_media'] ?? [] ) as $media ) {
			if ( ! is_array( $media ) || $kind !== sanitize_key( (string) ( $media['kind'] ?? $media['type'] ?? '' ) ) ) {
				continue;
			}
			$url = (string) ( $media['url'] ?? '' );
			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/** Whether a result URL has a supported video extension. */
	private static function is_video_url( string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$ext = strtolower( pathinfo( (string) ( $query['filename'] ?? '' ), PATHINFO_EXTENSION ) );
		}

		return in_array( $ext, array_keys( self::VIDEO_MIME_TYPES ), true );
	}

	/** Whether a result URL has a supported audio extension. */
	private static function is_audio_url( string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array_keys( self::AUDIO_MIME_TYPES ), true );
	}

	/** Find all explicit or extension-recognizable audio URLs in a provider response. */
	private static function find_result_audio_urls( array $result ): array {
		$urls = [];
		foreach ( $result as $key => $value ) {
			if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$is_explicit_audio = in_array( (string) $key, [ 'audio_url', 'audioUrl', 'stream_audio_url', 'download_audio_url' ], true );
				if ( $is_explicit_audio || self::is_audio_url( $value ) ) {
					$urls[] = $value;
				}
			} elseif ( is_array( $value ) ) {
				$urls = array_merge( $urls, self::find_result_audio_urls( $value ) );
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Find the first video URL in a Comfy MCP job result, so a workflow that
	 * returns both a still frame and its source video can import both.
	 *
	 * @param array $result MCP result payload.
	 * @return string
	 */
	private static function find_result_video_url( array $result ): string {
		$extensions = array_keys( self::VIDEO_MIME_TYPES );
		foreach ( [ 'video_url', 'videoUrl' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_string( $result[ $key ] ) && filter_var( $result[ $key ], FILTER_VALIDATE_URL ) ) {
				return $result[ $key ];
			}
		}

		foreach ( $result as $value ) {
			if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$path = (string) wp_parse_url( $value, PHP_URL_PATH );
				$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				if ( '' === $ext ) {
					// ComfyUI's /view URL keeps the real name in a query arg.
					$filename = (string) wp_parse_url( $value, PHP_URL_QUERY );
					parse_str( $filename, $query );
					$ext = strtolower( pathinfo( (string) ( $query['filename'] ?? '' ), PATHINFO_EXTENSION ) );
				}
				if ( in_array( $ext, $extensions, true ) ) {
					return $value;
				}
			} elseif ( is_array( $value ) ) {
				$url = self::find_result_video_url( $value );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
	}

	/**
	 * Download bytes from a generation provider's output URL.
	 *
	 * @param string $url     Output URL.
	 * @param string $adapter Generation job adapter, e.g. 'local_comfyui'.
	 * @return string|WP_Error Raw bytes, or an error.
	 */
	private static function download_bytes( string $url, string $adapter, int $job_id = 0 ) {
		// Local ComfyUI runs on a trusted, non-public host (e.g. host.lando.internal),
		// which wp_safe_remote_get's SSRF check would otherwise reject.
		$timeout = 'videodraft' === $adapter ? 600 : 60;
		$args = [
			'timeout'             => $timeout,
			'limit_response_size' => AI_Image_Client::MAX_IMAGE_BYTES + 1,
		];
		// OpenRouter's content endpoint requires the same bearer credential used to submit the job.
		if ( $job_id && 'openrouter' === (string) get_post_meta( $job_id, '_worldgraph_gen_provider_type', true ) ) {
			$args['headers'] = OpenRouter_API::download_headers( $job_id );
		}
		$download = 'local_comfyui' === $adapter ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $download ) ) {
			Generation_Log::add( 'error', 'generation_batch', 'Download request failed: ' . $download->get_error_message(), [ 'url' => $url ], '', 0 );
			return new WP_Error( 'worldgraph_gen_download_failed', __( 'The completed output could not be downloaded from the generation provider.', 'worldgraph' ) );
		}
		$code = wp_remote_retrieve_response_code( $download );
		if ( $code < 200 || $code >= 300 ) {
			Generation_Log::add( 'error', 'generation_batch', sprintf( 'Download request returned HTTP %d.', $code ), [ 'url' => $url ], '', 0 );
			return new WP_Error( 'worldgraph_gen_download_failed', __( 'The completed output could not be downloaded from the generation provider.', 'worldgraph' ) );
		}
		$mime = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $download, 'content-type' ) )[0] ) );
		if ( '' !== $mime ) {
			self::$download_mime_types[ $url ] = $mime;
		}

		return (string) wp_remote_retrieve_body( $download );
	}

	/** Stream a large provider output into a bounded temporary file. */
	private static function download_to_file( string $url, int $maximum_bytes, int $job_id, string $adapter = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temporary = wp_tempnam( 'worldgraph-generated-media' );
		if ( ! $temporary ) {
			return new WP_Error( 'worldgraph_gen_download_failed', __( 'WordPress could not create temporary storage for the generated media.', 'worldgraph' ) );
		}
		if ( ! self::journal_temp_file( $job_id, $temporary ) ) {
			wp_delete_file( $temporary );
			return new WP_Error( 'worldgraph_gen_journal_failed', __( 'WordPress could not journal temporary storage for the generated media.', 'worldgraph' ) );
		}

		$args = [
			'timeout'             => 600,
			'stream'              => true,
			'filename'            => $temporary,
			'limit_response_size' => $maximum_bytes + 1,
		];
		if ( $job_id && 'openrouter' === (string) get_post_meta( $job_id, '_worldgraph_gen_provider_type', true ) ) {
			$args['headers'] = OpenRouter_API::download_headers( $job_id );
		}
		$download = 'local_comfyui' === $adapter ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $download ) ) {
			wp_delete_file( $temporary );
			Generation_Log::add( 'error', 'generation_batch', 'Download request failed: ' . $download->get_error_message(), [ 'url' => $url ], '', 0 );
			return new WP_Error( 'worldgraph_gen_download_failed', __( 'The completed output could not be downloaded from the generation provider.', 'worldgraph' ) );
		}

		$code = wp_remote_retrieve_response_code( $download );
		$size = file_exists( $temporary ) ? filesize( $temporary ) : false;
		if ( $code < 200 || $code >= 300 ) {
			wp_delete_file( $temporary );
			return new WP_Error(
				'worldgraph_gen_download_failed',
				sprintf(
					/* translators: %d: HTTP response status code. */
					__( 'The generation provider returned HTTP %d while downloading completed media.', 'worldgraph' ),
					$code
				)
			);
		}
		if ( false === $size || $size <= 0 || $size > $maximum_bytes ) {
			wp_delete_file( $temporary );
			return new WP_Error( 'worldgraph_gen_invalid_payload', __( 'The completed generated media is empty or too large to store.', 'worldgraph' ) );
		}

		$mime = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $download, 'content-type' ) )[0] ) );
		if ( '' !== $mime ) {
			self::$download_mime_types[ $url ] = $mime;
		}

		return [ 'file' => $temporary, 'mime' => $mime, 'size' => (int) $size ];
	}

	/**
	 * Validate generated image bytes for media-library import.
	 *
	 * @param string $bytes Raw image data.
	 * @return array|WP_Error
	 */
	private static function validate_image_bytes( string $bytes ) {
		if ( '' === $bytes || strlen( $bytes ) > AI_Image_Client::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'worldgraph_gen_invalid_payload', __( 'The completed image is empty or too large to store.', 'worldgraph' ) );
		}

		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || empty( $info['mime'] ) || ! in_array( $info['mime'], AI_Image_Client::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error( 'worldgraph_gen_unsupported_type', __( 'The generation provider returned a file that is not a supported image.', 'worldgraph' ) );
		}

		$extensions = [ 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif' ];
		return [
			'data'      => $bytes,
			'mime'      => $info['mime'],
			'extension' => $extensions[ $info['mime'] ],
			'width'     => (int) $info[0],
			'height'    => (int) $info[1],
		];
	}

	/** Validate a streamed image without materializing it in PHP memory. */
	private static function validate_image_file( array $download ) {
		if ( empty( $download['file'] ) || empty( $download['size'] ) || $download['size'] > AI_Image_Client::MAX_IMAGE_BYTES ) {
			self::delete_temp_media( $download );
			return new WP_Error( 'worldgraph_gen_invalid_payload', __( 'The completed image is empty or too large to store.', 'worldgraph' ) );
		}
		$info = @getimagesize( $download['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || empty( $info['mime'] ) || ! in_array( $info['mime'], AI_Image_Client::ALLOWED_MIME_TYPES, true ) ) {
			self::delete_temp_media( $download );
			return new WP_Error( 'worldgraph_gen_unsupported_type', __( 'The generation provider returned a file that is not a supported image.', 'worldgraph' ) );
		}
		$extensions = [ 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif' ];
		return array_merge( $download, [
			'mime'      => $info['mime'],
			'extension' => $extensions[ $info['mime'] ],
			'width'     => (int) $info[0],
			'height'    => (int) $info[1],
		] );
	}

	/**
	 * Validate generated video bytes for media-library import. Video content
	 * can't be sniffed the way getimagesizefromstring() sniffs images, so the
	 * source URL's extension (already restricted to VIDEO_MIME_TYPES by
	 * find_result_video_url()) determines the mime type.
	 *
	 * @param string $bytes Raw video data.
	 * @param string $url   Source URL the bytes were downloaded from.
	 * @return array|WP_Error
	 */
	private static function validate_video_bytes( string $bytes, string $url, bool $assume_mp4 = false ) {
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_VIDEO_BYTES ) {
			return new WP_Error( 'worldgraph_gen_invalid_payload', __( 'The completed video is empty or too large to store.', 'worldgraph' ) );
		}
		$filetype = self::video_filetype( $url, $assume_mp4 );
		return is_wp_error( $filetype ) ? $filetype : array_merge( [ 'data' => $bytes ], $filetype );
	}

	/** Validate a streamed video while retaining its temporary file. */
	private static function validate_video_file( array $download, string $url, bool $assume_mp4 = false ) {
		if ( empty( $download['file'] ) || empty( $download['size'] ) || $download['size'] > self::MAX_VIDEO_BYTES ) {
			self::delete_temp_media( $download );
			return new WP_Error( 'worldgraph_gen_invalid_payload', __( 'The completed video is empty or too large to store.', 'worldgraph' ) );
		}

		$filetype = self::video_filetype( $url, $assume_mp4 );
		if ( is_wp_error( $filetype ) ) {
			self::delete_temp_media( $download );
			return $filetype;
		}

		return array_merge( $download, $filetype );
	}

	/** Resolve a supported generated-video extension and mime type. */
	private static function video_filetype( string $url, bool $assume_mp4 = false ) {

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$ext = strtolower( pathinfo( (string) ( $query['filename'] ?? '' ), PATHINFO_EXTENSION ) );
		}
		if ( '' === $ext && isset( self::$download_mime_types[ $url ] ) ) {
			$ext = (string) array_search( self::$download_mime_types[ $url ], self::VIDEO_MIME_TYPES, true );
		}
		if ( '' === $ext && $assume_mp4 ) {
			$ext = 'mp4';
		}

		if ( ! isset( self::VIDEO_MIME_TYPES[ $ext ] ) ) {
			return new WP_Error( 'worldgraph_gen_unsupported_type', __( 'The generation provider returned an unsupported video type.', 'worldgraph' ) );
		}

		return [ 'mime' => self::VIDEO_MIME_TYPES[ $ext ], 'extension' => $ext ];
	}

	/** Validate generated audio bytes and normalize their WordPress file type. */
	private static function validate_audio_bytes( string $bytes, string $mime, string $url ) {
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_AUDIO_BYTES ) {
			return new WP_Error( 'worldgraph_gen_invalid_payload', __( 'The completed audio is empty or too large to store.', 'worldgraph' ) );
		}
		$filetype = self::audio_filetype( $mime, $url );
		return is_wp_error( $filetype ) ? $filetype : array_merge( [ 'data' => $bytes ], $filetype );
	}

	/** Validate a streamed audio file while retaining its temporary path. */
	private static function validate_audio_file( array $download, string $url ) {
		if ( empty( $download['file'] ) || empty( $download['size'] ) || $download['size'] > self::MAX_AUDIO_BYTES ) {
			self::delete_temp_media( $download );
			return new WP_Error( 'worldgraph_gen_invalid_payload', __( 'The completed audio is empty or too large to store.', 'worldgraph' ) );
		}
		$filetype = self::audio_filetype( (string) ( $download['mime'] ?? '' ), $url );
		if ( is_wp_error( $filetype ) ) {
			self::delete_temp_media( $download );
			return $filetype;
		}
		return array_merge( $download, $filetype );
	}

	/** Resolve a supported generated-audio extension and mime type. */
	private static function audio_filetype( string $mime, string $url ) {
		$mime = strtolower( trim( explode( ';', $mime ?: (string) ( self::$download_mime_types[ $url ] ?? '' ) )[0] ) );
		$mime = 'audio/mp3' === $mime ? 'audio/mpeg' : $mime;
		$mime = 'audio/x-wav' === $mime ? 'audio/wav' : $mime;
		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( '' === $ext && '' !== $mime ) {
			$ext = (string) array_search( $mime, self::AUDIO_MIME_TYPES, true );
		}
		if ( ! isset( self::AUDIO_MIME_TYPES[ $ext ] ) ) {
			return new WP_Error( 'worldgraph_gen_unsupported_type', __( 'The generation provider returned an unsupported audio type.', 'worldgraph' ) );
		}
		return [ 'mime' => self::AUDIO_MIME_TYPES[ $ext ], 'extension' => $ext ];
	}

	/**
	 * Add an attachment to a story element's supporting media gallery.
	 *
	 * @param int $post_id       Source post ID.
	 * @param int $attachment_id Attachment ID.
	 */
	private static function add_to_gallery( int $post_id, int $attachment_id ): void {
		$gallery_ids = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post_id, self::GALLERY_META, true ) ) ) );
		if ( in_array( $attachment_id, $gallery_ids, true ) ) {
			return;
		}

		$gallery_ids[] = $attachment_id;
		update_post_meta( $post_id, self::GALLERY_META, $gallery_ids );
	}

	/**
	 * Create an Asset record describing the generated image.
	 *
	 * @param \WP_Post $post          Source post.
	 * @param int      $attachment_id Attachment ID.
	 * @param string   $prompt        Prompt used.
	 * @param array    $image         Image payload.
	 * @param int      $job_id        Generation job ID, when this is a queued import.
	 * @return int Asset post ID, or 0 on failure.
	 */
	private static function create_asset_record( \WP_Post $post, int $attachment_id, string $prompt, array $image, int $job_id = 0 ): int {
		$mime = (string) ( $image['mime'] ?? '' );
		$kind = 0 === strpos( $mime, 'video/' ) ? __( 'Video', 'worldgraph' ) : ( 0 === strpos( $mime, 'audio/' ) ? __( 'Audio', 'worldgraph' ) : __( 'Image', 'worldgraph' ) );
		$title = sprintf(
			/* translators: 1: story element title, 2: generated media kind. */
			__( '%1$s — Generated %2$s', 'worldgraph' ),
			$post->post_title,
			$kind
		);

		$asset_id = wp_insert_post(
			[
				'post_type'   => 'worldgraph_asset',
				'post_title'  => $title,
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $asset_id ) ) {
			return 0;
		}

		$asset_id = (int) $asset_id;
		if ( $job_id && ! self::journal_asset( $job_id, $asset_id ) ) {
			wp_delete_post( $asset_id, true );
			return 0;
		}
		if ( 0 !== strpos( $mime, 'audio/' ) ) {
			set_post_thumbnail( $asset_id, $attachment_id );
		}
		worldgraph_update_field_value( $asset_id, 'asset_title', $title );
		update_post_meta( $asset_id, self::SOURCE_META, $post->ID );

		$asset_type = 0 === strpos( $mime, 'video/' ) ? 'video' : ( 0 === strpos( $mime, 'audio/' ) ? 'audio' : 'image' );
		$term       = term_exists( $asset_type, 'worldgraph_asset_type' );
		if ( ! $term ) {
			$term = wp_insert_term( ucfirst( $asset_type ), 'worldgraph_asset_type' );
		}
		if ( ! is_wp_error( $term ) ) {
			$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
			worldgraph_update_field_value( $asset_id, 'asset_type', $term_id );
		}

		if ( isset( self::ASSET_RELATIONSHIP_FIELDS[ $post->post_type ] ) ) {
			worldgraph_update_field_value( $asset_id, self::ASSET_RELATIONSHIP_FIELDS[ $post->post_type ], $post->ID );
		}

		self::store_asset_fields( $asset_id, $attachment_id, $prompt, $image );

		return $asset_id;
	}

	/**
	 * Write generation provenance onto an Asset post.
	 *
	 * @param int    $asset_id      Asset post ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $prompt        Prompt used.
	 * @param array  $image         Image payload.
	 */
	private static function store_asset_fields( int $asset_id, int $attachment_id, string $prompt, array $image ): void {
		worldgraph_update_field_value( $asset_id, 'workflow_name', (string) ( $image['workflow'] ?? '' ) ?: 'text-to-image' );
		worldgraph_update_field_value( $asset_id, 'prompt', $prompt );
		worldgraph_update_field_value( $asset_id, 'model_name', (string) ( $image['model'] ?? '' ) );
		worldgraph_update_field_value( $asset_id, 'status', 'done' );
		worldgraph_update_field_value( $asset_id, 'storage_uri', (string) wp_get_attachment_url( $attachment_id ) );
		worldgraph_update_field_value( $asset_id, 'generation_parameters', (string) wp_json_encode( [
			'size'           => (string) ( $image['size'] ?? '' ),
			'mime'           => (string) ( $image['mime'] ?? '' ),
			'width'          => (int) ( $image['width'] ?? 0 ),
			'height'         => (int) ( $image['height'] ?? 0 ),
			'revised_prompt' => (string) ( $image['revised_prompt'] ?? '' ),
		] ) );
	}

}
