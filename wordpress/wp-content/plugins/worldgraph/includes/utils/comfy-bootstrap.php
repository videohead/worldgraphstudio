<?php
/**
 * First-run provisioning and readiness probing for a local ComfyUI Connection.
 *
 * ComfyUI normally loads its own default text-to-image workflow the first time
 * it starts, but only if the matching nodes and checkpoint are actually
 * installed. World Graph Studio cannot assume any of that, so this class probes the
 * instance, provisions the single text-to-image Template that the asset
 * generator falls back to, and reports the remaining work as ordered steps an
 * operator can be walked through.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local ComfyUI provisioning and readiness reporting.
 */
class Comfy_Bootstrap {

	/**
	 * Wizard slot marker for the provisioned text-to-image Template.
	 */
	const TEMPLATE_SLOT = 'local_comfyui_text_to_image';

	/**
	 * Obsolete wizard slot used by the original local ComfyUI bootstrap.
	 */
	const LEGACY_TEMPLATE_SLOT = 'local_comfyui_default';

	/**
	 * Transient holding the last readiness report.
	 */
	const STATUS_TRANSIENT = 'worldgraph_comfy_readiness';

	/**
	 * How long a readiness report stays cached, in seconds.
	 */
	const STATUS_TTL = 300;

	/**
	 * The checkpoint ComfyUI's own default text-to-image workflow loads.
	 */
	const DEFAULT_CHECKPOINT = 'v1-5-pruned-emaonly-fp16.safetensors';

	/**
	 * Human-readable name of ComfyUI's default checkpoint.
	 */
	const DEFAULT_CHECKPOINT_LABEL = 'Stable Diffusion 1.5 (FP16)';

	/**
	 * Where that checkpoint is published, for the one-click model install.
	 */
	const DEFAULT_CHECKPOINT_URL = 'https://huggingface.co/Comfy-Org/stable-diffusion-v1-5-archive/resolve/main/v1-5-pruned-emaonly-fp16.safetensors';

	/**
	 * The node class ComfyUI's default text-to-image graph loads its model with.
	 */
	const CHECKPOINT_NODE = 'CheckpointLoaderSimple';

	/**
	 * The readiness report for the configured local ComfyUI instance.
	 *
	 * @param bool $refresh Re-probe instead of reading the cached report.
	 * @return array{ready: bool, endpoint: string, template_id: int, steps: array<int, array>}
	 */
	public static function status( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::STATUS_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( $refresh ) {
			Comfy_Manifest::flush_catalog();
		}

		$status = self::probe();
		set_transient( self::STATUS_TRANSIENT, $status, self::STATUS_TTL );

		return $status;
	}

	/**
	 * Drop the cached readiness report.
	 */
	public static function flush(): void {
		delete_transient( self::STATUS_TRANSIENT );
	}

	/**
	 * The Template the local text-to-image fallback runs: the provisioned one,
	 * or any other ComfyUI text-to-image Template already on the site.
	 *
	 * @return int Template post ID, or 0 when none exists.
	 */
	public static function template_id(): int {
		$provisioned = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => 'worldgraph_wizard_slot', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => self::TEMPLATE_SLOT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		if ( $provisioned ) {
			return (int) $provisioned[0];
		}

		$existing = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => 'modality',
					'value' => Generation_Modality::TEXT_TO_IMAGE,
				],
				[
					'key'   => 'provider_type',
					'value' => 'comfyui',
				],
			],
		] );

		return $existing ? (int) $existing[0] : 0;
	}

	/**
	 * Provision the text-to-image Template a local ComfyUI Connection needs,
	 * pointing it at a checkpoint the instance actually has where possible.
	 *
	 * @param int $connection_id Parent worldgraph_conn post ID.
	 * @return int Template post ID, or 0 when the Template could not be created.
	 */
	public static function ensure_template( int $connection_id = 0 ): int {
		self::flush();

		$existing = self::template_id();
		if ( $existing ) {
			update_post_meta( $existing, 'worldgraph_wizard_slot', self::TEMPLATE_SLOT );
			worldgraph_update_field_value( $existing, 'modality', Generation_Modality::TEXT_TO_IMAGE );
			worldgraph_update_field_value( $existing, 'generation_structure', Generation_Modality::output_type( Generation_Modality::TEXT_TO_IMAGE ) );
			worldgraph_update_field_value( $existing, 'provider_type', 'comfyui' );
			worldgraph_update_field_value( $existing, 'status', 'active' );
			self::remove_legacy_parameter_defaults( $existing );
			$checkpoint = self::detect_checkpoint();
			worldgraph_update_field_value( $existing, 'checkpoint', $checkpoint );
			worldgraph_update_field_value( $existing, 'model_requirements', (string) wp_json_encode( [ self::download_entry( $checkpoint ) ] ) );
			if ( $connection_id ) {
				worldgraph_update_field_value( $existing, 'connection_id', (string) $connection_id );
			}

			self::retire_legacy_templates( $existing, $connection_id );
			self::apply_best_workflow( $existing );

			return $existing;
		}

		$checkpoint = self::detect_checkpoint();

		$template_id = \WorldGraph\CPT\Template::upsert_managed(
			self::TEMPLATE_SLOT,
			__( 'Local ComfyUI Text-to-Image (Baseline)', 'worldgraph' ),
			[
				'connection_id'        => (string) $connection_id,
				'generation_structure' => Generation_Modality::output_type( Generation_Modality::TEXT_TO_IMAGE ),
				'modality'             => Generation_Modality::TEXT_TO_IMAGE,
				'provider_type'        => 'comfyui',
				'status'               => 'active',
				'checkpoint'           => $checkpoint,
				'model_requirements'   => (string) wp_json_encode( [ self::download_entry( $checkpoint ) ] ),
			]
		);

		if ( $template_id ) {
			self::retire_legacy_templates( $template_id, $connection_id );
			self::apply_best_workflow( $template_id );
		}

		return $template_id;
	}

	/**
	 * Point the provisioned Template at the best published text-to-image
	 * workflow this instance can already run.
	 *
	 * The built-in graph is a Stable Diffusion 1.5 pipeline, which is the only
	 * thing that can be assumed of an unknown ComfyUI and is no longer close to
	 * what the model landscape offers. Where the instance already holds the
	 * files for something better, running the older graph is a choice nobody
	 * asked for, so the newer workflow is adopted instead. Nothing is downloaded
	 * here: an instance with nothing installed keeps the built-in fallback.
	 *
	 * @param int $template_id Template post ID.
	 * @return string The adopted registry entry ID, or '' when none was adopted.
	 */
	public static function apply_best_workflow( int $template_id ): string {
		$endpoint = Local_ComfyUI::endpoint();
		if ( '' === $endpoint || ! $template_id ) {
			return '';
		}

		$ranked = Comfy_Template_Registry::ranked(
			[ 'modality' => Generation_Modality::TEXT_TO_IMAGE, 'local_only' => true, 'limit' => 60 ],
			$endpoint,
			10
		);
		if ( is_wp_error( $ranked ) ) {
			return '';
		}

		foreach ( $ranked as $entry ) {
			if ( empty( $entry['ready'] ) ) {
				continue;
			}

			$workflow = Comfy_Template_Registry::workflow( (string) $entry['id'], $endpoint );
			if ( is_wp_error( $workflow ) ) {
				Generation_Log::add( 'debug', 'comfy_bootstrap', 'Published workflow rejected: ' . $workflow->get_error_message(), [ 'template' => $entry['id'] ] );
				continue;
			}

			worldgraph_update_field_value( $template_id, 'workflow_json', (string) wp_json_encode( $workflow ) );
			worldgraph_update_field_value( $template_id, 'provider_template_id', (string) $entry['id'] );
			worldgraph_update_field_value( $template_id, 'template_name', (string) $entry['name'] );
			worldgraph_update_field_value( $template_id, 'model_requirements', (string) wp_json_encode( self::registry_requirements( $entry ) ) );
			Generation_Log::add( 'info', 'comfy_bootstrap', sprintf( 'Provisioned the published workflow "%s".', (string) $entry['name'] ), [ 'template' => $entry['id'] ] );

			return (string) $entry['id'];
		}

		// Nothing better was runnable, so fall back to the built-in graph rather
		// than leaving a stale published workflow in place.
		worldgraph_delete_field_value( $template_id, 'workflow_json' );
		worldgraph_delete_field_value( $template_id, 'provider_template_id' );

		return '';
	}

	/**
	 * Model requirement records for an adopted published workflow.
	 *
	 * @param array $entry Ranked registry entry with readiness merged in.
	 * @return array<int, array<string, string>>
	 */
	private static function registry_requirements( array $entry ): array {
		$requirements = [];
		foreach ( (array) ( $entry['models_required'] ?? [] ) as $model ) {
			if ( ! is_array( $model ) || empty( $model['filename'] ) || empty( $model['url'] ) ) {
				continue;
			}

			$requirements[] = [
				'filename' => (string) $model['filename'],
				'folder'   => (string) $model['folder'],
				'url'      => (string) $model['url'],
			];
		}

		return $requirements;
	}

	/**
	 * Remove bootstrap-era hardcoded template parameter JSON so runtime values
	 * can inherit from the source project's media profile.
	 *
	 * @param int $template_id Template post ID.
	 * @return void
	 */
	private static function remove_legacy_parameter_defaults( int $template_id ): void {
		$decoded = json_decode( (string) worldgraph_get_field_value( $template_id, 'configuration_json' ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['parameters'] ) || ! is_array( $decoded['parameters'] ) ) {
			return;
		}

		$legacy_defaults = Generation_Modality::default_settings( Generation_Modality::TEXT_TO_IMAGE );
		$parameters      = $decoded['parameters'];
		$keys            = array_keys( $legacy_defaults );

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $parameters ) || (string) $parameters[ $key ] !== (string) $legacy_defaults[ $key ] ) {
				return;
			}
		}

		if ( count( array_intersect( array_keys( $parameters ), $keys ) ) === count( $parameters ) ) {
			worldgraph_delete_field_value( $template_id, 'configuration_json' );
		}
	}

	/**
	 * Retire only the obsolete Template previously managed by the bootstrap.
	 * Operator-created and catalog-materialized Templates must never be treated
	 * as migration debris based on their modality.
	 *
	 * @param int $keep_id       Template post ID to keep active.
	 * @param int $connection_id Optional parent worldgraph_conn post ID.
	 * @return void
	 */
	private static function retire_legacy_templates( int $keep_id, int $connection_id = 0 ): void {
		$templates = get_posts( [
			'post_type'      => 'worldgraph_template',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => 'provider_type',
					'value' => 'comfyui',
				],
				[
					'key'   => 'worldgraph_wizard_slot',
					'value' => self::LEGACY_TEMPLATE_SLOT,
				],
			],
		] );

		foreach ( $templates as $template_id ) {
			$template_id = (int) $template_id;
			if ( $template_id === $keep_id ) {
				continue;
			}

			$template_conn = (int) worldgraph_get_field_value( $template_id, 'connection_id' );

			if ( $connection_id && $template_conn && $template_conn !== $connection_id ) {
				continue;
			}

			wp_trash_post( $template_id );
		}
	}

	/**
	 * A checkpoint the connected ComfyUI can load, preferring the one its own
	 * default text-to-image workflow uses.
	 *
	 * @return string
	 */
	public static function detect_checkpoint(): string {
		$installed = Comfy_Manifest::installed_files( self::CHECKPOINT_NODE, 'ckpt_name' );
		if ( is_wp_error( $installed ) || empty( $installed ) ) {
			return self::DEFAULT_CHECKPOINT;
		}

		foreach ( $installed as $filename ) {
			if ( false !== stripos( (string) $filename, 'v1-5-pruned-emaonly' ) ) {
				return (string) $filename;
			}
		}

		return self::DEFAULT_CHECKPOINT;
	}

	/**
	 * Probe the instance and build the ordered setup steps.
	 *
	 * @return array
	 */
	private static function probe(): array {
		$endpoint = Local_ComfyUI::endpoint();
		$steps    = [];

		if ( '' === $endpoint ) {
			$steps[] = self::step(
				'endpoint',
				__( 'ComfyUI address', 'worldgraph' ),
				'todo',
				__( 'No local ComfyUI URL is set. Enter one in the Generation Connection section, e.g. http://host.lando.internal:8188 when ComfyUI runs on the Lando host.', 'worldgraph' )
			);

			return self::report( $endpoint, 0, $steps );
		}

		$steps[] = self::step(
			'endpoint',
			__( 'ComfyUI address', 'worldgraph' ),
			'ok',
			/* translators: %s: ComfyUI base URL. */
			sprintf( __( 'World Graph Studio calls ComfyUI at %s.', 'worldgraph' ), $endpoint )
		);

		$stats = wp_remote_get( $endpoint . '/system_stats', [ 'timeout' => 10 ] );
		if ( is_wp_error( $stats ) ) {
			$steps[] = self::step(
				'server',
				__( 'ComfyUI is running', 'worldgraph' ),
				'error',
				sprintf(
					/* translators: %s: HTTP error message. */
					__( 'ComfyUI did not answer: %s. Start ComfyUI, then re-check. From a container, localhost refers to the container, not the host.', 'worldgraph' ),
					$stats->get_error_message()
				)
			);

			return self::report( $endpoint, 0, $steps );
		}
		$code = wp_remote_retrieve_response_code( $stats );
		if ( $code < 200 || $code >= 300 ) {
			$steps[] = self::step(
				'server',
				__( 'ComfyUI is running', 'worldgraph' ),
				'error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'ComfyUI returned HTTP %d from /system_stats. Confirm the URL points at the ComfyUI API server.', 'worldgraph' ),
					(int) $code
				)
			);

			return self::report( $endpoint, 0, $steps );
		}

		$steps[] = self::step( 'server', __( 'ComfyUI is running', 'worldgraph' ), 'ok', __( 'The API server answered its status request.', 'worldgraph' ) );

		$checkpoints = Comfy_Manifest::installed_files( self::CHECKPOINT_NODE, 'ckpt_name', $endpoint );
		if ( is_wp_error( $checkpoints ) ) {
			$steps[] = self::step(
				'workflow',
				__( 'Default text-to-image workflow', 'worldgraph' ),
				'error',
				sprintf(
					/* translators: %s: error message from the node catalog read. */
					__( 'World Graph Studio could not read the ComfyUI node catalog: %s. Without it, ComfyUI has not loaded the nodes its default text-to-image workflow needs.', 'worldgraph' ),
					$checkpoints->get_error_message()
				)
			);

			return self::report( $endpoint, 0, $steps );
		}

		$steps[] = self::step(
			'workflow',
			__( 'Default text-to-image workflow', 'worldgraph' ),
			'ok',
			__( 'ComfyUI has loaded the checkpoint, prompt, sampler, and save nodes the built-in text-to-image graph uses.', 'worldgraph' )
		);

		if ( empty( $checkpoints ) ) {
			$steps[] = self::step(
				'models',
				__( 'Checkpoint installed', 'worldgraph' ),
				'todo',
				sprintf(
					/* translators: 1: checkpoint filename, 2: download URL. */
					__( 'ComfyUI has no checkpoint installed, so its default text-to-image workflow cannot run. Install one into models/checkpoints — ComfyUI\'s own default is %1$s (%2$s) — or use the Template\'s "Install missing models" button, then re-check.', 'worldgraph' ),
					self::DEFAULT_CHECKPOINT,
					self::DEFAULT_CHECKPOINT_URL
				)
			);
		} else {
			$sample = array_slice( array_map( 'strval', $checkpoints ), 0, 3 );
			$steps[] = self::step(
				'models',
				__( 'Checkpoint installed', 'worldgraph' ),
				'ok',
				sprintf(
					/* translators: 1: number of checkpoints, 2: comma-separated filenames. */
					_n( 'ComfyUI reports %1$d checkpoint: %2$s.', 'ComfyUI reports %1$d checkpoints, including %2$s.', count( $checkpoints ), 'worldgraph' ),
					count( $checkpoints ),
					implode( ', ', $sample )
				)
			);
		}

		$template_id = self::template_id();
		if ( ! $template_id ) {
			$steps[] = self::step(
				'template',
				__( 'Text-to-image Template', 'worldgraph' ),
				'todo',
				__( 'No text-to-image Template exists yet. Create one so story elements have a workflow to generate against.', 'worldgraph' ),
				'provision'
			);

			return self::report( $endpoint, 0, $steps );
		}

		$steps[] = self::step(
			'template',
			__( 'Text-to-image Template', 'worldgraph' ),
			'ok',
			sprintf(
				/* translators: %s: Template title. */
				__( 'Generating against the "%s" Template.', 'worldgraph' ),
				get_the_title( $template_id )
			),
			'',
			(string) get_edit_post_link( $template_id, '' )
		);

		$steps[] = self::requirements_step( $template_id, $endpoint );

		return self::report( $endpoint, $template_id, $steps );
	}

	/**
	 * Check the provisioned Template against the live instance.
	 *
	 * @param int    $template_id Template post ID.
	 * @param string $endpoint    ComfyUI base URL.
	 * @return array
	 */
	private static function requirements_step( int $template_id, string $endpoint ): array {
		$report = Comfy_Manifest::validate( $template_id, $endpoint );
		if ( is_wp_error( $report ) ) {
			return self::step( 'requirements', __( 'Template requirements', 'worldgraph' ), 'error', $report->get_error_message() );
		}
		if ( ! empty( $report['ok'] ) ) {
			return self::step( 'requirements', __( 'Template requirements', 'worldgraph' ), 'ok', __( 'Every node and model this Template needs is installed.', 'worldgraph' ) );
		}

		$problems = [];
		if ( ! empty( $report['missing_nodes'] ) ) {
			$problems[] = sprintf(
				/* translators: %s: comma-separated ComfyUI node class names. */
				__( 'Install the custom nodes providing: %s.', 'worldgraph' ),
				implode( ', ', $report['missing_nodes'] )
			);
		}
		foreach ( $report['missing_models'] as $model ) {
			$problems[] = sprintf(
				/* translators: 1: model filename, 2: ComfyUI models sub-directory. */
				__( 'Install %1$s into models/%2$s.', 'worldgraph' ),
				(string) $model['filename'],
				(string) $model['folder']
			);
		}

		return self::step(
			'requirements',
			__( 'Template requirements', 'worldgraph' ),
			'todo',
			implode( ' ', $problems ),
			'',
			(string) get_edit_post_link( $template_id, '' )
		);
	}

	/**
	 * Build one setup step.
	 *
	 * @param string $id      Step identifier.
	 * @param string $label   Human-readable step name.
	 * @param string $state   One of ok, todo, error.
	 * @param string $message Guidance for the operator.
	 * @param string $action  Optional action the readiness UI can offer.
	 * @param string $url     Optional link to the screen that resolves the step.
	 * @return array
	 */
	private static function step( string $id, string $label, string $state, string $message, string $action = '', string $url = '' ): array {
		return [
			'id'      => $id,
			'label'   => $label,
			'state'   => $state,
			'message' => $message,
			'action'  => $action,
			'url'     => $url,
		];
	}

	/**
	 * Assemble the report from its steps.
	 *
	 * @param string $endpoint    ComfyUI base URL.
	 * @param int    $template_id Template post ID, or 0.
	 * @param array  $steps       Ordered steps.
	 * @return array
	 */
	private static function report( string $endpoint, int $template_id, array $steps ): array {
		$ready = true;
		foreach ( $steps as $step ) {
			if ( 'ok' !== $step['state'] ) {
				$ready = false;
				break;
			}
		}

		return [
			'ready'       => $ready,
			'endpoint'    => $endpoint,
			'template_id' => $template_id,
			'checked_at'  => gmdate( 'Y-m-d H:i:s' ),
			'steps'       => $steps,
		];
	}

	/**
	 * The Model Requirements entry seeded on the provisioned Template, so the
	 * "Install missing models" action has a source to fetch from.
	 *
	 * @param string $checkpoint Checkpoint filename.
	 * @return array<string, string>
	 */
	private static function download_entry( string $checkpoint ): array {
		return [
			'filename' => $checkpoint,
			'folder'   => 'checkpoints',
			'url'      => self::DEFAULT_CHECKPOINT === $checkpoint ? self::DEFAULT_CHECKPOINT_URL : '',
		];
	}
}
