<?php
/**
 * Web Stories Sync Service.
 *
 * Handles synchronization between World Graph Studio elements and Web Stories.
 *
 * @package WorldGraphWebStories
 */

namespace WorldGraphWebStories;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Web Stories Sync class.
 *
 * Manages bidirectional sync between World Graph Studio and Web Stories.
 */
class Sync {

	/**
	 * Sync instance.
	 *
	 * @var Sync|null
	 */
	private static $instance = null;

	/**
	 * Get the sync instance.
	 *
	 * @return Sync
	 */
	public static function init(): Sync {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Register hooks.
		add_action( 'worldgraph_web_stories_sync_story', [ $this, 'sync_story' ], 10, 2 );
		add_action( 'worldgraph_web_stories_sync_scene', [ $this, 'sync_scene' ], 10, 2 );
		add_action( 'worldgraph_web_stories_sync_all', [ $this, 'sync_all' ], 10, 0 );
	}

	/**
	 * Get the Web Stories API client.
	 *
	 * @return \WorldGraphWebStories\API\Client|null
	 */
	private function get_client(): ?\WorldGraphWebStories\API\Client {
		if ( ! \WorldGraphWebStories\API\Client::is_web_stories_active() ) {
			return null;
		}

		return new \WorldGraphWebStories\API\Client();
	}

	/**
	 * Check if sync is possible.
	 *
	 * @return array {
	 *     @type bool   $success Whether sync is possible.
	 *     @type string $message Message explaining status.
	 * }
	 */
	private function can_sync(): array {
		if ( ! self::is_sync_enabled() ) {
			return [
				'success' => false,
				'message' => 'Web Stories sync is not enabled.',
			];
		}

		$client = $this->get_client();
		if ( ! $client ) {
			return [
				'success' => false,
				'message' => 'Web Stories plugin is not active.',
			];
		}

		return [
			'success' => true,
			'message' => '',
		];
	}

	/**
	 * Check if sync is enabled.
	 *
	 * @return bool
	 */
	public static function is_sync_enabled(): bool {
		$enabled = get_option( 'worldgraph_web_stories_sync_enabled', false );
		return (bool) $enabled;
	}

	/**
	 * Get stored Web Stories mapping for a World Graph Studio post.
	 *
	 * @param int $post_id The World Graph Studio post ID.
	 * @return array
	 */
	public function get_mapping( int $post_id ): array {
		return get_post_meta( $post_id, '_worldgraph_web_stories_mapping', true ) ?: [];
	}

	/**
	 * Store Web Stories mapping for a World Graph Studio post.
	 *
	 * @param int   $post_id The World Graph Studio post ID.
	 * @param array $mapping The mapping data.
	 */
	public function set_mapping( int $post_id, array $mapping ): void {
		update_post_meta( $post_id, '_worldgraph_web_stories_mapping', $mapping );
	}

	/**
	 * Get the Web Story ID for a World Graph Studio post.
	 *
	 * @param int $post_id The World Graph Studio post ID.
	 * @return int|null
	 */
	public function get_story_id( int $post_id ): ?int {
		$mapping = $this->get_mapping( $post_id );
		return isset( $mapping['story_id'] ) ? (int) $mapping['story_id'] : null;
	}

	/**
	 * Get the World Graph Studio Scene ID for a Web Story.
	 *
	 * @param int $story_id The Web Story post ID.
	 * @return int|null
	 */
	public function get_scene_id( int $story_id ): ?int {
		$mapping = get_post_meta( $story_id, '_worldgraph_web_stories_mapping', true ) ?: [];
		return isset( $mapping['scene_id'] ) ? (int) $mapping['scene_id'] : null;
	}

	/**
	 * Sync a Web Story to World Graph Studio Scene.
	 *
	 * When a Web Story is created/updated, create or update a corresponding World Graph Studio Scene.
	 *
	 * @param int   $story_id   The Web Story post ID.
	 * @param array $story_data Optional story data (defaults to fetching from post).
	 * @return array
	 */
	public function sync_story( int $story_id, array $story_data = [] ): array {
		$check = $this->can_sync();
		if ( ! $check['success'] ) {
			return [ 'success' => false, 'message' => $check['message'] ];
		}

		$client = $this->get_client();

		// Fetch story data if not provided.
		if ( empty( $story_data ) ) {
			$story = $client->get_story( $story_id );
			if ( is_wp_error( $story ) ) {
				return [ 'success' => false, 'message' => 'Failed to fetch Web Story.' ];
			}
			$story_data = $story;
		}

		$post = get_post( $story_id );
		if ( ! $post || 'web-story' !== $post->post_type ) {
			return [ 'success' => false, 'message' => 'Invalid Web Story post.' ];
		}

		// Get pages from story.
		$pages = $client->get_story_pages( $story_id );

		// Check if already synced.
		$scene_id = $this->get_scene_id( $story_id );

		$result = [
			'success'    => true,
			'action'     => 'created',
			'scene_id'   => null,
			'mapping'    => [],
			'pages'      => count( $pages ),
			'response'   => null,
		];

		if ( $scene_id ) {
			// Update existing scene.
			$result['action'] = 'updated';
			$result['scene_id'] = $scene_id;

			$updated = $this->update_scene_from_story( $scene_id, $post, $pages );
			if ( $updated['success'] ) {
				$result['response'] = $updated;
			} else {
				$result['success'] = false;
				$result['message'] = $updated['message'] ?? 'Failed to update scene.';
			}
		} else {
			// Create new scene.
			$scene_id = $this->create_scene_from_story( $story_id, $post, $pages );
			if ( $scene_id ) {
				$result['scene_id'] = $scene_id;
				$result['mapping'] = [
					'story_id' => $story_id,
					'scene_id' => $scene_id,
					'synced_at' => current_time( 'mysql' ),
				];
				update_post_meta( $scene_id, '_worldgraph_web_stories_mapping', $result['mapping'] );
			} else {
				$result['success'] = false;
				$result['message'] = 'Failed to create scene from Web Story.';
			}
		}

		return $result;
	}

	/**
	 * Sync a World Graph Studio Scene to Web Story.
	 *
	 * When a World Graph Studio Scene is created/updated, create or update a corresponding Web Story.
	 *
	 * @param int   $scene_id   The World Graph Studio Scene post ID.
	 * @param array $scene_data Optional scene data (defaults to fetching from post).
	 * @return array
	 */
	public function sync_scene( int $scene_id, array $scene_data = [] ): array {
		$check = $this->can_sync();
		if ( ! $check['success'] ) {
			return [ 'success' => false, 'message' => $check['message'] ];
		}

		$client = $this->get_client();

		// Fetch scene data if not provided.
		if ( empty( $scene_data ) ) {
			$post = get_post( $scene_id );
			if ( ! $post || 'worldgraph_scene' !== $post->post_type ) {
				return [ 'success' => false, 'message' => 'Invalid World Graph Studio scene post.' ];
			}

			$scene_data = [
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'scene_number'   => \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'scene_number' ),
				'title'          => \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'title' ),
				'summary'        => \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'summary' ),
				'script_content' => \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'script_content' ),
				'location'       => \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'location' ),
				'time_of_day'    => \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'time_of_day' ),
				'emotional_tone' => \WorldGraph\Utils\worldgraph_get_field_value( $scene_id, 'emotional_tone' ),
			];
		}

		// Check if already synced.
		$story_id = $this->get_story_id( $scene_id );

		$result = [
			'success'    => true,
			'action'     => 'created',
			'story_id'   => null,
			'mapping'    => [],
			'response'   => null,
		];

		if ( $story_id ) {
			// Update existing story.
			$result['action'] = 'updated';
			$result['story_id'] = $story_id;

			$updated = $this->update_story_from_scene( $story_id, $scene_id, $scene_data );
			if ( $updated['success'] ) {
				$result['response'] = $updated;
			} else {
				$result['success'] = false;
				$result['message'] = $updated['message'] ?? 'Failed to update Web Story.';
			}
		} else {
			// Create new story.
			$story_id = $this->create_story_from_scene( $scene_id, $scene_data );
			if ( $story_id ) {
				$result['story_id'] = $story_id;
				$result['mapping'] = [
					'scene_id' => $scene_id,
					'story_id' => $story_id,
					'synced_at' => current_time( 'mysql' ),
				];
				update_post_meta( $scene_id, '_worldgraph_web_stories_mapping', $result['mapping'] );
			} else {
				$result['success'] = false;
				$result['message'] = 'Failed to create Web Story from scene.';
			}
		}

		return $result;
	}

	/**
	 * Create a World Graph Studio Scene from a Web Story.
	 *
	 * @param int   $story_id The Web Story post ID.
	 * @param WP_Post $post   The Web Story post object.
	 * @param array   $pages  The pages from the Web Story.
	 * @return int|false The new Scene post ID, or false on failure.
	 */
	private function create_scene_from_story( int $story_id, \WP_Post $post, array $pages ) {
		// Prepare scene data.
		$scene_title = $post->post_title ?: 'Story: ' . $post->post_name;
		$scene_summary = $post->post_excerpt ?: '';

		// Build script content from pages.
		$script_content = $this->build_script_content_from_pages( $pages );

		// Get scene number from mapping or generate one.
		$scene_number = $this->generate_scene_number();

		// Create the scene.
		$scene_id = wp_insert_post(
			[
				'post_type'    => 'worldgraph_scene',
				'post_title'   => $scene_title,
				'post_content' => $script_content,
				'post_status'  => 'draft',
			],
			true
		);

		if ( is_wp_error( $scene_id ) ) {
			return false;
		}

		// Save metadata.
		\WorldGraph\Utils\worldgraph_update_field_value( $scene_id, 'scene_number', $scene_number );
		\WorldGraph\Utils\worldgraph_update_field_value( $scene_id, 'title', $scene_title );
		\WorldGraph\Utils\worldgraph_update_field_value( $scene_id, 'summary', $scene_summary );
		\WorldGraph\Utils\worldgraph_update_field_value( $scene_id, 'script_content', $script_content );
		\WorldGraph\Utils\worldgraph_delete_field_value( $scene_id, 'time_of_day' );

		// Store mapping.
		$mapping = [
			'story_id' => $story_id,
			'scene_id' => $scene_id,
			'synced_at' => current_time( 'mysql' ),
		];
		update_post_meta( $scene_id, '_worldgraph_web_stories_mapping', $mapping );
		update_post_meta( $story_id, '_worldgraph_web_stories_mapping', $mapping );

		return $scene_id;
	}

	/**
	 * Update a World Graph Studio Scene from a Web Story.
	 *
	 * @param int   $scene_id The World Graph Studio Scene post ID.
	 * @param WP_Post $post   The Web Story post object.
	 * @param array   $pages  The pages from the Web Story.
	 * @return array
	 */
	private function update_scene_from_story( int $scene_id, \WP_Post $post, array $pages ): array {
		// Update post content.
		$script_content = $this->build_script_content_from_pages( $pages );

		$update_result = wp_update_post(
			[
				'ID'           => $scene_id,
				'post_title'   => $post->post_title,
				'post_content' => $script_content,
			],
			true
		);

		if ( is_wp_error( $update_result ) ) {
			return [ 'success' => false, 'message' => 'Failed to update scene post.' ];
		}

		// Update metadata.
		\WorldGraph\Utils\worldgraph_update_field_value( $scene_id, 'summary', $post->post_excerpt );
		\WorldGraph\Utils\worldgraph_update_field_value( $scene_id, 'script_content', $script_content );

		return [ 'success' => true ];
	}

	/**
	 * Create a Web Story from a World Graph Studio Scene.
	 *
	 * @param int   $scene_id   The World Graph Studio Scene post ID.
	 * @param array $scene_data The scene data.
	 * @return int|false The new Web Story post ID, or false on failure.
	 */
	private function create_story_from_scene( int $scene_id, array $scene_data ): ?int {
		// Build story pages from scene data.
		$pages = $this->build_pages_from_scene( $scene_data );

		// Create the Web Story.
		$story_data = [
			'title'     => $scene_data['post_title'] ?? 'Scene Story',
			'status'    => 'draft',
			'excerpt'   => $scene_data['summary'] ?? $scene_data['script_content'] ?? '',
			'pages'     => $pages,
		];

		$result = \WorldGraphWebStories\API\Client::create_story( $story_data );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		$story_id = $result['id'] ?? null;
		if ( ! $story_id ) {
			return false;
		}

		// Store mapping.
		$mapping = [
			'scene_id' => $scene_id,
			'story_id' => $story_id,
			'synced_at' => current_time( 'mysql' ),
		];
		update_post_meta( $scene_id, '_worldgraph_web_stories_mapping', $mapping );
		update_post_meta( $story_id, '_worldgraph_web_stories_mapping', $mapping );

		return $story_id;
	}

	/**
	 * Update a Web Story from a World Graph Studio Scene.
	 *
	 * @param int   $story_id   The Web Story post ID.
	 * @param int   $scene_id   The World Graph Studio Scene post ID.
	 * @param array $scene_data The scene data.
	 * @return array
	 */
	private function update_story_from_scene( int $story_id, int $scene_id, array $scene_data ): array {
		// Build story pages from scene data.
		$pages = $this->build_pages_from_scene( $scene_data );

		// Update the Web Story.
		$result = \WorldGraphWebStories\API\Client::update_story( $story_id, [
			'title'   => $scene_data['post_title'] ?? '',
			'content' => $scene_data['post_content'] ?? '',
			'story_data' => $pages,
		] );

		if ( is_wp_error( $result ) ) {
			return [ 'success' => false, 'message' => 'Failed to update Web Story.' ];
		}

		return [ 'success' => true ];
	}

	/**
	 * Build script content HTML from Web Story pages.
	 *
	 * @param array $pages The pages from a Web Story.
	 * @return string
	 */
	private function build_script_content_from_pages( array $pages ): string {
		$html = '<div class="web-story-import">';

		foreach ( $pages as $index => $page ) {
			$html .= '<div class="page" data-page="' . ( $index + 1 ) . '">';

			// Extract text elements.
			if ( ! empty( $page['elements'] ) ) {
				foreach ( $page['elements'] as $element ) {
					if ( ! empty( $element['type'] ) && 'text' === $element['type'] && ! empty( $element['value']['text'] ) ) {
						$html .= '<p>' . esc_html( $element['value']['text'] ) . '</p>';
					}
				}
			}

			// Note media assets.
			$has_media = ! empty( $page['elements'] );
			if ( $has_media ) {
				$html .= '<div class="page-notes">';
				$html .= '<p>Page ' . ( $index + 1 ) . ': ' . count( $page['elements'] ?? [] ) . ' elements</p>';
				$html .= '</div>';
			}

			$html .= '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Build Web Story pages from World Graph Studio Scene data.
	 *
	 * @param array $scene_data The scene data.
	 * @return array
	 */
	private function build_pages_from_scene( array $scene_data ): array {
		$pages = [];

		// Create one page per scene, or split by paragraphs.
		$content = $scene_data['script_content'] ?? $scene_data['post_content'] ?? '';

		if ( ! empty( $content ) ) {
			// Split content into paragraphs for pages.
			$paragraphs = explode( '</p><p>', str_replace( [ '<p>', '</p>', '<br>', '<br/>' ], '', $content ) );

			foreach ( $paragraphs as $index => $paragraph ) {
				if ( empty( $paragraph ) ) {
					continue;
				}

				$pages[] = [
					'width'  => 1080,
					'height' => 1920,
					'elements' => [
						[
							'type'    => 'text',
							'values'  => [
								'text' => wp_strip_all_tags( $paragraph ),
							],
						],
					],
					'backgroundColor' => [
						'r' => 255,
						'g' => 255,
						'b' => 255,
						'a' => 1.0,
					],
				];
			}
		}

		// If no pages were created, create a placeholder page.
		if ( empty( $pages ) ) {
			$pages[] = [
				'width'  => 1080,
				'height' => 1920,
				'elements' => [],
				'backgroundColor' => [
					'r' => 255,
					'g' => 255,
					'b' => 255,
					'a' => 1.0,
				],
			];
		}

		return $pages;
	}

	/**
	 * Generate a scene number.
	 *
	 * @return int
	 */
	private function generate_scene_number(): int {
		$scene_ids = get_posts(
			[
				'post_type'      => 'worldgraph_scene',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'none',
			]
		);
		$highest = 0;

		foreach ( $scene_ids as $scene_id ) {
			$scene_number = \WorldGraph\Utils\worldgraph_get_field_value( (int) $scene_id, 'scene_number' );
			if ( is_numeric( $scene_number ) ) {
				$highest = max( $highest, (int) $scene_number );
			}
		}

		return $highest + 1;
	}

	/**
	 * Sync all mapped items.
	 *
	 * @return array
	 */
	public function sync_all(): array {
		$check = $this->can_sync();
		if ( ! $check['success'] ) {
			return [ 'success' => false, 'message' => $check['message'] ];
		}

		$results = [
			'success' => true,
			'synced'  => 0,
			'errors'  => 0,
			'details' => [],
		];

		// Sync all mapped Web Stories.
		$stories = \WorldGraphWebStories\API\Client::get_stories();

		if ( ! is_wp_error( $stories ) ) {
			foreach ( $stories as $story ) {
				$story_id = $story['id'] ?? null;
				if ( ! $story_id ) {
					continue;
				}

				$mapping = get_post_meta( $story_id, '_worldgraph_web_stories_mapping', true ) ?: [];
				if ( empty( $mapping['scene_id'] ) ) {
					continue;
				}

				$result = $this->sync_story( $story_id );
				if ( $result['success'] ) {
					$results['synced']++;
				} else {
					$results['errors']++;
				}
				$results['details'][] = $result;
			}
		}

		return $results;
	}
}
