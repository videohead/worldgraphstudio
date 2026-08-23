<?php
/**
 * Template update smoke checks.
 *
 * Runs a minimum non-dispatched generation queue check whenever a Template is
 * saved, so provider/template contract regressions are surfaced immediately.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Smoke_Check {
	const RESULT_META = '_worldgraph_template_smoke_result';

	/** Register template save hooks. */
	public static function init(): void {
		add_action( 'save_post_worldgraph_template', [ __CLASS__, 'handle_template_save' ], 20, 3 );
	}

	/**
	 * Run smoke validation on Template saves (excluding autosaves/revisions).
	 *
	 * @param int      $post_id Template post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an existing post update.
	 */
	public static function handle_template_save( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! $update && ! in_array( $post->post_status, [ 'publish', 'draft' ], true ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connection_id = absint( worldgraph_get_field_value( $post_id, 'connection_id' ) );
		if ( $connection_id && ! Connection_Repository::current_user_can_manage( $connection_id ) ) {
			return;
		}

		self::run_for_template( $post_id );
	}

	/**
	 * Execute the minimum generation queue smoke check for one Template.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string, mixed>
	 */
	public static function run_for_template( int $template_id ): array {
		$modality = Generation_Modality::sanitize( (string) worldgraph_get_field_value( $template_id, 'modality' ) );
		$type     = Generation_Modality::output_type( $modality );
		if ( ! in_array( $type, [ 'image', 'video' ], true ) ) {
			return self::store_result( $template_id, [
				'passed'  => false,
				'status'  => 'skipped',
				'message' => 'Template output type is not a representative-media image/video output.',
				'type'    => $type,
			] );
		}

		// Check ComfyUI readiness directly and first: a Template with missing
		// nodes/models must never read as "passed" regardless of whether a
		// source story element exists to simulate a queue against.
		$connection_id = absint( worldgraph_get_field_value( $template_id, 'connection_id' ) );
		$connection    = $connection_id ? Connection_Repository::get( $connection_id ) : null;
		$provider      = sanitize_key( (string) worldgraph_get_field_value( $template_id, 'provider_type' ) );
		if ( is_array( $connection ) && 'comfyui' === $provider && 'local' === ( $connection['environment'] ?? '' ) ) {
			Connection_Adapters::load( 'comfyui' );
			$ready = Comfy_Manifest::ensure_ready( $template_id, $connection_id );
			if ( is_wp_error( $ready ) ) {
				return self::store_result( $template_id, [
					'passed'  => false,
					'status'  => 'failed',
					'message' => $ready->get_error_message(),
					'type'    => $type,
				] );
			}
		}

		$source_id = self::find_source_post( $type );
		if ( ! $source_id ) {
			return self::store_result( $template_id, [
				'passed'  => false,
				'status'  => 'skipped',
				'message' => 'No source story element is available for smoke validation.',
				'type'    => $type,
			] );
		}

		$plan = Generation_Workflows::plan( $source_id, 'item' );
		if ( is_wp_error( $plan ) ) {
			return self::store_result( $template_id, [
				'passed'    => false,
				'status'    => 'failed',
				'message'   => $plan->get_error_message(),
				'type'      => $type,
				'source_id' => $source_id,
			] );
		}

		$task = self::first_task_for_type( (array) ( $plan['tasks'] ?? [] ), $type );
		if ( empty( $task ) ) {
			return self::store_result( $template_id, [
				'passed'    => false,
				'status'    => 'failed',
				'message'   => 'The source item has no generation task matching this Template output type.',
				'type'      => $type,
				'source_id' => $source_id,
			] );
		}

		$result = Asset_Generator::queue_for_post( $source_id, [
			'type'         => $type,
			'intent'       => (string) ( $task['intent'] ?? '' ),
			'template_id'  => $template_id,
			'set_featured' => false,
			'create_asset' => false,
			'schedule'     => false,
		] );

		if ( is_wp_error( $result ) ) {
			return self::store_result( $template_id, [
				'passed'    => false,
				'status'    => 'failed',
				'message'   => $result->get_error_message(),
				'type'      => $type,
				'source_id' => $source_id,
			] );
		}

		$generation_id = absint( $result['generation_id'] ?? 0 );
		if ( $generation_id ) {
			wp_delete_post( $generation_id, true );
		}

		return self::store_result( $template_id, [
			'passed'        => true,
			'status'        => 'passed',
			'message'       => 'Template queue smoke check passed.',
			'type'          => $type,
			'source_id'     => $source_id,
			'intent'        => (string) ( $task['intent'] ?? '' ),
			'generation_id' => $generation_id,
		] );
	}

	/**
	 * Locate a source post that can generate this media type.
	 *
	 * @param string $type image|video
	 * @return int
	 */
	private static function find_source_post( string $type ): int {
		$candidates = 'video' === $type
			? [ 'worldgraph_shot', 'worldgraph_scene', 'worldgraph_project' ]
			: [ 'worldgraph_project', 'worldgraph_scene', 'worldgraph_character', 'worldgraph_location', 'worldgraph_shot' ];

		foreach ( $candidates as $post_type ) {
			$post = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
			] );
			if ( ! empty( $post[0] ) ) {
				return absint( $post[0] );
			}
		}

		return 0;
	}

	/**
	 * Return the first planned task matching a media type.
	 *
	 * @param array<int, array<string, mixed>> $tasks Planned tasks.
	 * @param string                            $type  image|video.
	 * @return array<string, mixed>
	 */
	private static function first_task_for_type( array $tasks, string $type ): array {
		foreach ( $tasks as $task ) {
			if ( $type === (string) ( $task['type'] ?? '' ) ) {
				return $task;
			}
		}
		return [];
	}

	/**
	 * Persist and log the latest smoke check result.
	 *
	 * @param int                  $template_id Template post ID.
	 * @param array<string, mixed> $result      Result payload.
	 * @return array<string, mixed>
	 */
	private static function store_result( int $template_id, array $result ): array {
		$result['checked_at'] = current_time( 'mysql' );
		update_post_meta( $template_id, self::RESULT_META, $result );
		Generation_Log::add(
			! empty( $result['passed'] ) ? 'info' : 'warning',
			'template_smoke',
			'Template smoke check: ' . (string) ( $result['message'] ?? '' ),
			[ 'template_id' => $template_id, 'result' => $result ],
			'',
			absint( worldgraph_get_field_value( $template_id, 'connection_id' ) )
		);

		return $result;
	}
}
