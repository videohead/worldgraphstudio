<?php
/**
 * Presentation-layer contract regression tests.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

class Test_Story_Display_UI extends TestCase {

	/** Development guidance must expose safe, working creator handoffs. */
	public function test_analytics_panel_renders_development_compass_actions(): void {
		$panel  = file_get_contents( dirname( __DIR__ ) . '/includes/admin/analytics-panel.php' );
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/js/analytics-panel.js' );
		$graph  = file_get_contents( dirname( __DIR__ ) . '/includes/utils/relationship-graph.php' );
		$plugin = file_get_contents( dirname( __DIR__ ) . '/worldgraph.php' );

		$this->assertNotFalse( $panel );
		$this->assertNotFalse( $script );
		$this->assertNotFalse( $graph );
		$this->assertNotFalse( $plugin );
		$this->assertStringContainsString( 'Development Compass', $panel );
		$this->assertStringContainsString( "'createUrls'", $panel );
		$this->assertStringContainsString( "\$cached['development']", $panel );
		$this->assertStringContainsString( '! $force_refresh', $panel );
		$this->assertStringContainsString( '&& is_array( $cached )', $panel );
		$this->assertStringContainsString( 'self.renderDevelopment(response.data)', $script );
		$this->assertStringContainsString( 'fetchAnalytics(true)', $script );
		$this->assertStringContainsString( 'requestId !== self.analyticsRequestId || projectId !== self.getProjectId()', $script );
		$this->assertStringContainsString( 'requestId !== self.networkRequestId || projectId !== self.getProjectId()', $script );
		$this->assertStringContainsString( 'requestId !== self.cacheRequestId || projectId !== self.getProjectId()', $script );
		$this->assertStringContainsString( 'aria-live="polite"', $panel );
		$this->assertStringContainsString( 'aria-busy="false"', $panel );
		$this->assertStringContainsString( 'never creates or changes relationships automatically', $panel );
		$this->assertStringContainsString( "'draftEntity'", $panel );
		$this->assertStringContainsString( 'document.createTextNode(opportunity.evidence', $script );
		$this->assertStringContainsString( "replace('__POST_ID__', String(id))", $script );
		$this->assertStringContainsString( 'worldgraph_graph_analytics_cache_version', $graph );
		$this->assertStringContainsString( "WORLDGRAPH_CPT_PREFIX . 'relationships'", $graph );
		$this->assertStringContainsString( 'Utils\\relationship_graph_cache_init();', $plugin );
	}

	/** The public projection must stay read-only and visibility-aware. */
	public function test_story_display_projection_is_read_only_and_permission_scoped(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/utils/story-display.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'worldgraph_register_story_display_rest_field', $source );
		$this->assertStringContainsString( "'worldgraph_display'", $source );
		$this->assertStringNotContainsString( "'update_callback'", $source );
		$this->assertStringContainsString( "current_user_can( 'read_post', \$post_id )", $source );
		$this->assertStringContainsString( 'worldgraph_story_display_can_read( $node_id, true )', $source );
		$this->assertStringContainsString( 'worldgraph_story_display_graph_post_types()', $source );
		$this->assertStringContainsString( 'fetch_relationship_graph();', $source );
		$this->assertStringContainsString( 'filter_relationship_graph_by_project( $graph, $project_id )', $source );
		$this->assertStringContainsString( "'development'         => (array) ( \$analytics['development'] ?? [] )", $source );
		$this->assertStringContainsString( "'worldgraph_display_v2_'", $source );
		$this->assertStringNotContainsString( "\t\t'worldgraph_conn',", $source );
		$this->assertStringContainsString( 'post_password_required( $post )', $source );
		$this->assertStringContainsString( "'' === (string) \$node_post->post_password", $source );
		$this->assertStringContainsString( "'publish' === \$post->post_status", $source );
		$this->assertStringContainsString( 'worldgraph_hide_protected_story_rest_fields', $source );
		$this->assertStringContainsString( 'array_keys( worldgraph_get_all_cpts() )', $source );
		$this->assertStringContainsString( "\$data['acf']                = [];", $source );
		$this->assertStringContainsString( "str_starts_with( (string) \$relation, 'acf:' )", $source );
		$this->assertStringContainsString( 'hash_equals( (string) $post->post_password, $request_password )', $source );
	}

	/** Scene details must use canonical ownership and deterministic editorial order. */
	public function test_scene_display_resolves_and_orders_shots(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/utils/story-display.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'worldgraph_get_scene_display_shots', $source );
		$this->assertStringContainsString( "[ 'belongs_to', 'contains' ]", $source );
		$this->assertStringContainsString( "'key'     => 'scene'", $source );
		$this->assertStringContainsString( 'worldgraph_get_shot_canonical_scene_id', $source );
		$this->assertStringContainsString( '$scene_id !== $canonical_scene_id', $source );
		$this->assertStringContainsString( '$left->ID <=> $right->ID', $source );
		$this->assertStringContainsString( "\$payload['shots']", $source );
	}

	/** Media DTOs need player metadata and deterministic generated-view intent. */
	public function test_media_projection_supports_gallery_and_players(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/utils/story-display.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "'_worldgraph_asset_gallery_ids'", $source );
		$this->assertStringContainsString( "'_worldgraph_gen_intent'", $source );
		$this->assertStringContainsString( "'mime_type'", $source );
		$this->assertStringContainsString( "'audio/mpeg'", $source );
		$this->assertStringContainsString( "'video/mp4'", $source );
		$this->assertStringContainsString( 'worldgraph_story_display_intent_rank', $source );
		$this->assertStringContainsString( "'shot-video'", $source );
		$this->assertStringContainsString( "get_post_meta( \$asset_id, '_worldgraph_gen_intent', true )", $source );
		$this->assertStringContainsString( "worldgraph_story_display_can_read( (int) \$attachment->post_parent, \$include_private )", $source );
		$this->assertStringContainsString( "return '';", $source );
	}

	/** Scene ordering must be complete, scoped, authorized, and keyboard operable. */
	public function test_scene_shot_sequencer_validates_complete_membership(): void {
		$controller = file_get_contents( dirname( __DIR__ ) . '/includes/admin/scene-shot-sequencer.php' );
		$service    = file_get_contents( dirname( __DIR__ ) . '/includes/utils/scene-shot-order.php' );
		$rest       = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/shots-controller.php' );
		$base_rest  = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/base-controller.php' );
		$shot_cpt   = file_get_contents( dirname( __DIR__ ) . '/includes/cpts/shot.php' );
		$script     = file_get_contents( dirname( __DIR__ ) . '/assets/js/scene-shot-sequencer.js' );

		$this->assertNotFalse( $controller );
		$this->assertNotFalse( $service );
		$this->assertNotFalse( $rest );
		$this->assertNotFalse( $base_rest );
		$this->assertNotFalse( $shot_cpt );
		$this->assertNotFalse( $script );
		$this->assertStringContainsString( 'worldgraph_reorder_scene_shots', $controller );
		$this->assertStringContainsString( '$submitted_set !== $expected_set', $service );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$scene_id )", $service );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$shot_id )", $service );
		$this->assertStringContainsString( '$order_slots[ $index ]', $service );
		$this->assertStringContainsString( "'post__not_in'   => \$scene_shot_ids", $service );
		$this->assertStringContainsString( 'worldgraph_acquire_shot_order_lock', $service );
		$this->assertStringContainsString( "[ 'option_name' => \$key, 'option_value' => \$token ]", $service );
		$this->assertStringContainsString( 'worldgraph_scene_shot_order_revision', $service );
		$this->assertStringContainsString( 'worldgraph_scene_shot_conflict', $service );
		$this->assertStringContainsString( 'worldgraph_rollback_scene_shot_order', $service );
		$this->assertStringContainsString( "'scene_id'", $rest );
		$this->assertStringContainsString( "'permission_callback' => [ \$this, 'check_reorder_permission' ]", $rest );
		$this->assertStringContainsString( 'worldgraph_shot_order_requires_scene', $rest );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$post_id )", $base_rest );
		$this->assertStringContainsString( "current_user_can( 'delete_post', \$post_id )", $base_rest );
		$this->assertStringNotContainsString( "'page-attributes'", $shot_cpt );
		$this->assertStringNotContainsString( 'set_relationships_for_field', $shot_cpt );
		$this->assertStringNotContainsString( 'save_post_worldgraph_shot', $shot_cpt );
		$this->assertStringContainsString( "data-shot-move=\"up\"", $controller );
		$this->assertStringContainsString( 'aria-live="polite"', $controller );
		$this->assertStringContainsString( "'revision'", $rest );
		$this->assertStringContainsString( 'edit access to the Scene and every Shot', $controller );
		$this->assertStringContainsString( "current_user_can( 'read_post', \$shot->ID )", $controller );
		$this->assertStringContainsString( 'sortable', $script );
		$this->assertStringContainsString( 'queuedSave', $script );
	}

	/** Legacy Scene and Sequence reorder routes must be scoped transactions. */
	public function test_legacy_story_reorder_routes_are_complete_authorized_and_atomic(): void {
		$scenes    = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/scenes-controller.php' );
		$sequences = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/sequences-controller.php' );

		$this->assertNotFalse( $scenes );
		$this->assertNotFalse( $sequences );
		$this->assertStringContainsString( "'permission_callback' => [ \$this, 'check_reorder_permission' ]", $scenes );
		$this->assertStringContainsString( 'A valid sequence_id is required.', $scenes );
		$this->assertStringContainsString( 'Submit every Scene assigned to this Sequence exactly once.', $scenes );
		$this->assertStringContainsString( "current_user_can( \$taxonomy->cap->assign_terms )", $scenes );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$scene_id )", $scenes );
		$this->assertStringContainsString( 'worldgraph_scene_reorder_lock_', $scenes );
		$this->assertStringContainsString( 'INSERT IGNORE INTO', $scenes );
		$this->assertStringContainsString( 'has_verified_order_meta', $scenes );
		$this->assertStringContainsString( "get_metadata_raw( \$meta_type, \$object_id, \$meta_key, false )", $scenes );
		$this->assertStringContainsString( 'rollback_order_meta', $scenes );
		$this->assertStringNotContainsString( 'wp_update_post', $scenes );
		$this->assertStringNotContainsString( 'wp_set_object_terms', $scenes );

		$this->assertStringContainsString( 'validate_complete_sequence_order', $sequences );
		$this->assertStringContainsString( 'ordered_ids cannot contain duplicate Sequence term IDs.', $sequences );
		$this->assertStringContainsString( 'Submit every existing Sequence term exactly once.', $sequences );
		$this->assertStringContainsString( "'fields'          => 'ids'", $sequences );
		$this->assertStringContainsString( 'worldgraph_sequence_reorder_lock', $sequences );
		$this->assertStringContainsString( 'INSERT IGNORE INTO', $sequences );
		$this->assertStringContainsString( 'has_verified_sequence_order', $sequences );
		$this->assertStringContainsString( "get_metadata_raw( 'term'", $sequences );
		$this->assertStringContainsString( 'rollback_sequence_order_meta', $sequences );
		$this->assertStringContainsString( 'get_raw_scene_order_meta', $sequences );
		$this->assertStringContainsString( 'has_verified_scene_order', $sequences );
		$this->assertStringContainsString( 'restore_sequence_terms( $original_terms )', $sequences );
		$this->assertStringContainsString( 'rollback_scene_order_meta( $original_order_meta )', $sequences );
		$this->assertStringContainsString( 'in_array( $scene_id, $existing, true )', $sequences );
	}

	/** The gallery editor must use core media selection and validate attachments. */
	public function test_story_media_gallery_is_curated_and_nonce_protected(): void {
		$controller = file_get_contents( dirname( __DIR__ ) . '/includes/admin/story-media-gallery.php' );
		$script     = file_get_contents( dirname( __DIR__ ) . '/assets/js/story-media-gallery.js' );

		$this->assertNotFalse( $controller );
		$this->assertNotFalse( $script );
		$this->assertStringContainsString( 'wp_enqueue_media()', $controller );
		$this->assertStringContainsString( 'wp_verify_nonce', $controller );
		$this->assertStringContainsString( "current_user_can( 'upload_files' )", $controller );
		$this->assertStringContainsString( "'attachment' !== get_post_type( \$attachment_id )", $controller );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$attachment_id )", $controller );
		$this->assertStringContainsString( 'hash_equals( $current_revision, $revision )', $controller );
		$this->assertStringContainsString( '$concurrent_additions', $controller );
		$this->assertStringContainsString( '$user_reordered', $controller );
		$this->assertStringContainsString( '$concurrent_reordered', $controller );
		$this->assertStringContainsString( 'render_conflict_notice', $controller );
		$this->assertStringContainsString( "current_user_can( 'read_post', \$attachment_id )", $controller );
		$this->assertStringContainsString( "__( 'Restricted media', 'worldgraph' )", $controller );
		$this->assertStringContainsString( "[ 'image', 'audio', 'video' ]", $script );
		$this->assertStringContainsString( 'const frame = window.wp.media', $script );
		$this->assertStringContainsString( 'sortable', $script );
		$this->assertStringContainsString( 'data-gallery-move="up"', $controller );
		$this->assertStringContainsString( 'dataset.galleryMove', $script );
	}

	/** Story saves must invalidate both broad and route-specific headless caches. */
	public function test_headless_revalidation_covers_story_views_and_dependencies(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/plugins/headless-revalidate/headless-revalidate.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "'worldgraph_scene'     => 'scenes'", $source );
		$this->assertStringContainsString( "'worldgraph_sound'     => 'sounds'", $source );
		$this->assertStringContainsString( "'worldgraph_shot'", $source );
		$this->assertStringContainsString( "'worldgraph_asset'", $source );
		$this->assertStringContainsString( "'storyType'", $source );
		$this->assertStringContainsString( "send_webhook( 'story'", $source );
		$this->assertStringContainsString( 'flush_story_revalidation_queue', $source );
		$this->assertStringContainsString( 'wp_safe_remote_post', $source );
		$this->assertStringContainsString( 'is_allowed_local_revalidation_target', $source );
		$this->assertStringContainsString( "[ 'headless', 'headless.worldgraph.lndo.site' ]", $source );
		$this->assertStringContainsString( "'http_request_host_is_external'", $source );
		$this->assertStringContainsString( "'http_allowed_safe_ports'", $source );
		$this->assertStringContainsString( '} finally {', $source );
		$this->assertStringContainsString( 'render_failure_notice', $source );
		$this->assertStringContainsString( "'_thumbnail_id'", $source );
		$this->assertStringContainsString( "'_wp_attachment_metadata'", $source );
		$this->assertStringContainsString( "add_action( 'edit_attachment'", $source );
		$this->assertStringContainsString( "'production_stage'", $source );
		$this->assertStringContainsString( "'publish' !== \$post->post_status", $source );
		$this->assertStringContainsString( "add_action( 'set_object_terms'", $source );
		$this->assertStringContainsString( 'queue_broad_story_revalidation', $source );
	}
}
