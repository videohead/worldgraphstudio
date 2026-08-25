<?php
/**
 * Cascade deletion of a Project and every Story Graph entity scoped to it.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a destructive "Delete Project & All Assets" row action to the Projects list table.
 */
class Project_Cascade_Delete {

	/** admin-post.php action. */
	public const ACTION = 'worldgraph_delete_project_cascade';

	/** Nonce action prefix; the project ID is appended. */
	private const NONCE_ACTION = 'worldgraph_delete_project_cascade_';

	/** Nonce action for the one-request result notice. */
	private const NOTICE_NONCE_ACTION = 'worldgraph_cascade_delete_notice';

	/** Provenance meta key that ties generated Media Library attachments to a Story Graph entity. */
	private const GENERATED_FROM_META_KEY = '_worldgraph_generated_from';

	/** Register hooks. */
	public static function init(): void {
		add_filter( 'post_row_actions', [ __CLASS__, 'add_row_action' ], 20, 2 );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_delete' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_result_notice' ] );
	}

	/**
	 * Add the cascade-delete row action to each Project row.
	 *
	 * @param array     $actions Existing row actions.
	 * @param \WP_Post $post    Current post.
	 * @return array Row actions.
	 */
	public static function add_row_action( array $actions, \WP_Post $post ): array {
		if ( 'worldgraph_project' !== $post->post_type || ! current_user_can( 'delete_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				[
					'action'     => self::ACTION,
					'project_id' => $post->ID,
				],
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION . $post->ID
		);

		$warning = sprintf(
			/* translators: %s: project title. */
			__( 'Permanently delete "%s" and every World Graph Studio entity scoped to it (episodes, scenes, shots, assets, characters, locations, props, sounds, editorial artifacts, and their generated media)? This cannot be undone.', 'worldgraph' ),
			$post->post_title
		);

		$actions['worldgraph_delete_cascade'] = sprintf(
			'<a href="%1$s" class="submitdelete" style="color:#b32d2e" onclick="return confirm(%2$s);">%3$s</a>',
			esc_url( $url ),
			esc_attr( wp_json_encode( $warning ) ),
			esc_html__( 'Delete Project & All Assets', 'worldgraph' )
		);

		return $actions;
	}

	/** Handle the destructive admin-post request. */
	public static function handle_delete(): void {
		$project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;
		if ( ! $project_id || 'worldgraph_project' !== get_post_type( $project_id ) ) {
			wp_die( esc_html__( 'Invalid project.', 'worldgraph' ), esc_html__( 'Not Found', 'worldgraph' ), [ 'response' => 404 ] );
		}

		check_admin_referer( self::NONCE_ACTION . $project_id );

		if ( ! current_user_can( 'delete_post', $project_id ) ) {
			wp_die( esc_html__( 'You are not allowed to delete this project.', 'worldgraph' ), esc_html__( 'Forbidden', 'worldgraph' ), [ 'response' => 403 ] );
		}

		$result = self::delete_project_and_assets( $project_id );

		$query = [
			'post_type'                       => 'worldgraph_project',
			'worldgraph_cascade_delete_nonce' => wp_create_nonce( self::NOTICE_NONCE_ACTION ),
			'deleted_posts'                   => $result['posts'],
			'deleted_attachments'             => $result['attachments'],
			'project_title'                   => rawurlencode( $result['project_title'] ),
		];

		wp_safe_redirect( add_query_arg( $query, admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * Delete a project, every Story Graph entity scoped to it, and their generated media.
	 *
	 * @param int $project_id Project post ID.
	 * @return array{posts:int,attachments:int,project_title:string}
	 */
	private static function delete_project_and_assets( int $project_id ): array {
		$project_title = (string) get_the_title( $project_id );

		$graph      = \WorldGraph\Utils\fetch_relationship_graph( [ 'project_id' => $project_id ] );
		$entity_ids = [];
		foreach ( $graph['nodes'] ?? [] as $node ) {
			$id = absint( $node['id'] ?? 0 );
			if ( $id ) {
				$entity_ids[ $id ] = true;
			}
		}
		$entity_ids[ $project_id ] = true;
		$entity_ids                = array_keys( $entity_ids );

		$attachments_deleted = self::delete_generated_attachments( $entity_ids );

		$posts_deleted = 0;
		foreach ( $entity_ids as $id ) {
			if ( $project_id === $id ) {
				continue;
			}
			if ( get_post( $id ) && wp_delete_post( $id, true ) ) {
				++$posts_deleted;
			}
		}

		if ( get_post( $project_id ) && wp_delete_post( $project_id, true ) ) {
			++$posts_deleted;
		}

		return [
			'posts'         => $posts_deleted,
			'attachments'   => $attachments_deleted,
			'project_title' => $project_title,
		];
	}

	/**
	 * Delete Media Library attachments generated from any of the given entity IDs.
	 *
	 * @param array<int, int> $entity_ids Story Graph entity IDs scoped to the project.
	 * @return int Number of attachments deleted.
	 */
	private static function delete_generated_attachments( array $entity_ids ): int {
		if ( [] === $entity_ids ) {
			return 0;
		}

		$attachment_ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'     => self::GENERATED_FROM_META_KEY,
						'value'   => $entity_ids,
						'compare' => 'IN',
					],
				],
			]
		);

		$deleted = 0;
		foreach ( $attachment_ids as $attachment_id ) {
			if ( wp_delete_attachment( absint( $attachment_id ), true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/** Show a nonce-authenticated result notice after the POST-redirect. */
	public static function render_result_notice(): void {
		if ( empty( $_GET['worldgraph_cascade_delete_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['worldgraph_cascade_delete_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NOTICE_NONCE_ACTION ) ) {
			return;
		}

		$posts       = isset( $_GET['deleted_posts'] ) ? absint( $_GET['deleted_posts'] ) : 0;
		$attachments = isset( $_GET['deleted_attachments'] ) ? absint( $_GET['deleted_attachments'] ) : 0;
		$title       = isset( $_GET['project_title'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['project_title'] ) ) ) : '';

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: project title, 2: number of related entities deleted, 3: number of attachments deleted. */
					__( '"%1$s" and its Story Graph data were deleted (%2$d entities, %3$d generated media files).', 'worldgraph' ),
					$title,
					$posts,
					$attachments
				)
			)
		);
	}
}
