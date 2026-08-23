<?php
/**
 * Web Stories API Client.
 *
 * Handles communication with Web Stories REST API.
 *
 * @package WorldGraphWebStories
 */

namespace WorldGraphWebStories\API;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Web Stories API Client class.
 *
 * Provides methods to interact with Web Stories REST API.
 */
class Client {

	/**
	 * Web Stories REST namespace.
	 */
	const REST_NAMESPACE = 'web-stories/v1';

	/**
	 * Web Stories post type slug.
	 */
	const POST_TYPE_SLUG = 'web-story';

	/**
	 * Get the Web Stories REST API base URL.
	 *
	 * @return string
	 */
	public static function rest_base(): string {
		return rest_url( self::REST_NAMESPACE . '/stories' );
	}

	/**
	 * Get all Web Stories.
	 *
	 * @param array $args Query arguments.
	 * @return array|WP_Error
	 */
	public static function get_stories( array $args = [] ) {
		$params = [
			'per_page' => 100,
			'page'     => 1,
			'status'   => 'any',
		];

		$params = wp_parse_args( $args, $params );

		$url = add_query_arg( $params, self::rest_base() );

		$response = wp_remote_get(
			$url,
			[
				'headers' => [
					'Accept'  => 'application/json',
					'Content-Type' => 'application/json',
				],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from Web Stories API.', 'worldgraph' ) );
		}

		return $data;
	}

	/**
	 * Get a single Web Story by ID.
	 *
	 * @param int $story_id The Web Story post ID.
	 * @return array|WP_Error
	 */
	public static function get_story( int $story_id ) {
		$url = rest_url( self::REST_NAMESPACE . '/stories/' . $story_id );

		$response = wp_remote_get(
			$url,
			[
				'headers' => [
					'Accept'  => 'application/json',
					'Content-Type' => 'application/json',
				],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from Web Stories API.', 'worldgraph' ) );
		}

		return $data;
	}

	/**
	 * Get pages from a Web Story.
	 *
	 * @param int $story_id The Web Story post ID.
	 * @return array|WP_Error
	 */
	public static function get_story_pages( int $story_id ) {
		$story = self::get_story( $story_id );

		if ( is_wp_error( $story ) ) {
			return $story;
		}

		// Pages are stored in the story_data JSON field.
		$story_data = $story['story_data'] ?? [];

		if ( empty( $story_data['pages'] ) ) {
			return [];
		}

		return $story_data['pages'];
	}

	/**
	 * Create a Web Story from World Graph Studio scene data.
	 *
	 * @param array $story_data The story data.
	 * @return array|WP_Error
	 */
	public static function create_story( array $story_data ) {
		$url = self::rest_base();

		$request = [
			'title'       => $story_data['title'] ?? 'Untitled Story',
			'content'     => '',
			'status'      => $story_data['status'] ?? 'draft',
			'story_data'  => $story_data['pages'] ?? [],
			'excerpt'     => $story_data['excerpt'] ?? '',
		];

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Accept'  => 'application/json',
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( $request ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from Web Stories API.', 'worldgraph' ) );
		}

		return $data;
	}

	/**
	 * Update a Web Story.
	 *
	 * @param int   $story_id The Web Story post ID.
	 * @param array $data     The data to update.
	 * @return array|WP_Error
	 */
	public static function update_story( int $story_id, array $data ) {
		$url = rest_url( self::REST_NAMESPACE . '/stories/' . $story_id );

		$response = wp_remote_post(
			$url,
			[
				'method'  => 'POST',
				'headers' => [
					'Accept'  => 'application/json',
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( $data ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from Web Stories API.', 'worldgraph' ) );
		}

		return $result;
	}

	/**
	 * Check if Web Stories plugin is active.
	 *
	 * @return bool
	 */
	public static function is_web_stories_active(): bool {
		return class_exists( 'Google\\Web_Stories\\Story_Post_Type' );
	}

	/**
	 * Get Web Story counts by status.
	 *
	 * @return array
	 */
	public static function get_story_counts(): array {
		$counts = [
			'publish' => 0,
			'draft'   => 0,
			'future'  => 0,
			'pending' => 0,
			'any'     => 0,
		];

		$args = [
			'per_page' => 1,
			'status'   => 'any',
		];

		$stories = self::get_stories( $args );

		if ( ! is_wp_error( $stories ) && isset( $stories['_links']['self'][0]['href'] ) ) {
			// Parse total count from headers.
			$headers = wp_remote_retrieve_headers( $stories );
			// This is a simplified approach - in production, use the X-WP-Total header.
			$counts['any'] = (int) ( $stories['total'] ?? 0 );
		}

		return $counts;
	}
}
