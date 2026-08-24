<?php
/**
 * Tests for representative-media workflows and their editor controls.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Generation_Workflows;

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return (string) $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php';

/** Representative workflow and metabox contract tests. */
class Test_Generation_Workflows extends TestCase {

	/** Read one production method without coupling assertions to unrelated code. */
	private function method_source( string $method ): string {
		$reflection = new ReflectionMethod( Generation_Workflows::class, $method );
		$lines      = file( $reflection->getFileName() );

		$this->assertIsArray( $lines );
		return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
	}

	/** Default recipes must retain the promised image/video output counts. */
	public function test_default_workflow_output_counts(): void {
		$definitions = Generation_Workflows::definitions();

		$this->assertCount( 1, $definitions['worldgraph_project']['outputs'] );
		$this->assertCount( 1, $definitions['worldgraph_world']['outputs'] );
		$this->assertCount( 6, $definitions['worldgraph_character']['outputs'] );
		$this->assertCount( 6, $definitions['worldgraph_prop']['outputs'] );
		$this->assertCount( 6, $definitions['worldgraph_location']['outputs'] );
		$this->assertCount( 2, $definitions['worldgraph_shot']['outputs'] );
		$this->assertCount( 1, $definitions['worldgraph_scene']['outputs'] );
		$this->assertCount( 1, $definitions['worldgraph_episode']['outputs'] );
	}

	/** A Shot is the direct authoring surface that deliberately offers video. */
	public function test_shot_workflow_has_explicit_image_and_video_actions(): void {
		$outputs = Generation_Workflows::definitions()['worldgraph_shot']['outputs'];

		$this->assertSame( [ 'image', 'video' ], array_column( $outputs, 'type' ) );
		$this->assertSame( [ 'shot-representative-still', 'shot-video' ], array_column( $outputs, 'intent' ) );
	}

	/** Inherited Scene context must not flood Shot prompts with a transcript. */
	public function test_inherited_context_uses_short_visual_fields(): void {
		$this->assertSame(
			[ 'summary', 'location', 'time_of_day', 'emotional_tone' ],
			Generation_Workflows::INHERITED_PROMPT_FIELDS['worldgraph_scene']
		);
		$this->assertNotContains( 'script_content', Generation_Workflows::INHERITED_PROMPT_FIELDS['worldgraph_scene'] );
		$this->assertNotContains( 'dialogue', Generation_Workflows::INHERITED_PROMPT_FIELDS['worldgraph_scene'] );
		$this->assertNotContains( 'frame_rate', Generation_Workflows::PROMPT_FIELDS['worldgraph_project'] );
	}

	/** Demonstration planning is an explicit Project scope and durable batch kind. */
	public function test_demonstration_scope_and_batch_kind_are_public(): void {
		$this->assertSame( [ 'item', 'project', 'demonstration' ], Generation_Workflows::supported_scopes() );
		$this->assertContains( Generation_Workflows::REPRESENTATIVE_BATCH, Generation_Workflows::supported_batch_kinds() );
		$this->assertContains( Generation_Workflows::DEMONSTRATION_BATCH, Generation_Workflows::supported_batch_kinds() );
		$this->assertSame( Generation_Workflows::DEMONSTRATION_BATCH, Generation_Workflows::batch_kind_for_scope( 'demonstration' ) );
		$this->assertSame( Generation_Workflows::REPRESENTATIVE_BATCH, Generation_Workflows::batch_kind_for_scope( 'project' ) );
	}

	/** Character-name inference must avoid matching aliases inside other words. */
	public function test_demonstration_character_mentions_use_name_boundaries(): void {
		$method = ( new ReflectionClass( Generation_Workflows::class ) )->getMethod( 'character_ids_mentioned' );
		$method->setAccessible( true );
		$aliases = [ 10 => [ 'Al' ], 20 => [ 'Alice' ], 30 => [ 'Bob' ] ];

		$this->assertSame( [ 10, 30 ], $method->invoke( null, 'Al crosses frame while Bob looks left; Aliceblue signage stays blurred.', $aliases ) );
	}

	/** The frozen planner contract must describe dependencies, media bindings, and fallbacks. */
	public function test_demonstration_plan_declares_durable_media_contract(): void {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );

		$this->assertNotFalse( $workflow );
		$this->assertStringContainsString( 'public static function demonstration_plan', $workflow );
		$this->assertStringContainsString( "'task_key'", $workflow );
		$this->assertStringContainsString( "'phase'", $workflow );
		$this->assertStringContainsString( "'dependencies'", $workflow );
		$this->assertStringContainsString( "'input_refs'", $workflow );
		$this->assertStringContainsString( "'start_frame'", $workflow );
		$this->assertStringContainsString( "'end_frame'", $workflow );
		$this->assertStringContainsString( "'fallback_task_key'", $workflow );
		$this->assertStringContainsString( "'audio_fallback'    => 'silence'", $workflow );
		$this->assertStringContainsString( "'subtitle_fallback' => true", $workflow );
		$this->assertStringContainsString( "'preferred_modalities'", $workflow );
		$this->assertStringContainsString( "'generation_required'", $workflow );
	}

	/** Demonstration retries are isolated by batch kind and freeze the coordinator contract. */
	public function test_demonstration_idempotency_and_frozen_task_contract(): void {
		$reflection  = new ReflectionClass( Generation_Workflows::class );
		$idempotency = $reflection->getMethod( 'idempotency_option_name' );
		$idempotency->setAccessible( true );
		$representative = $idempotency->invoke( null, 42, 7, 'same-request', Generation_Workflows::REPRESENTATIVE_BATCH );
		$demonstration  = $idempotency->invoke( null, 42, 7, 'same-request', Generation_Workflows::DEMONSTRATION_BATCH );

		$this->assertNotSame( $representative, $demonstration );
		$this->assertSame( $demonstration, $idempotency->invoke( null, 42, 7, 'same-request', Generation_Workflows::DEMONSTRATION_BATCH ) );

		$freeze = $reflection->getMethod( 'freeze_task' );
		$freeze->setAccessible( true );
		$frozen = $freeze->invoke( null, [
			'task_key'             => 'Shot 9 Video!',
			'source_id'            => -91,
			'source_type'          => 'worldgraph_shot',
			'source_title'         => '<b>Closing shot</b>',
			'workflow_id'          => 'shot-video',
			'intent'               => 'demonstration-shot-video',
			'label'                => 'Shot video',
			'type'                 => 'video',
			'phase'                => 'video',
			'required'             => false,
			'generation_required'  => true,
			'prompt'               => 'Camera tracks forward.',
			'dependencies'         => [ 'Character Ref', 'Character Ref', '' ],
			'preferred_modalities' => [ 'text_image_to_video', 'video_to_video', 'text_image_to_video' ],
			'input_refs'           => [
				'image'      => [ 'task_key' => 'Character Ref', 'fallback_task_key' => 'Shot Still' ],
				'end_frame'  => 'Next Still',
				'not_a_slot' => 'discard-me',
			],
		], 3 );

		$this->assertSame( 3, $frozen['step'] );
		$this->assertSame( 'shot9video', $frozen['task_key'] );
		$this->assertSame( 91, $frozen['source_id'] );
		$this->assertFalse( $frozen['required'] );
		$this->assertTrue( $frozen['generation_required'] );
		$this->assertSame( [ 'characterref' ], $frozen['dependencies'] );
		$this->assertSame( [ 'text_image_to_video', 'video_to_video' ], $frozen['preferred_modalities'] );
		$this->assertSame( [ 'task_key' => 'characterref', 'fallback_task_key' => 'shotstill' ], $frozen['input_refs']['image'] );
		$this->assertSame( [ 'task_key' => 'nextstill' ], $frozen['input_refs']['end_frame'] );
		$this->assertArrayNotHasKey( 'not_a_slot', $frozen['input_refs'] );
		$this->assertSame( hash( 'sha256', 'Camera tracks forward.' ), $frozen['prompt_hash'] );

		$queue = $this->method_source( 'queue_batch' );
		$this->assertStringContainsString( "'scope'             => \$scope", $queue );
		$this->assertStringContainsString( 'batch_for_idempotency_key( $post_id, $requester_id, $idempotency_key, $batch_kind )', $queue );
		$this->assertStringContainsString( 'reserve_idempotency_key( $post_id, $requester_id, $idempotency_key, $request_hash, $batch_kind )', $queue );
		$this->assertStringContainsString( '$meta[ self::ASSEMBLY_PLAN_META ] = (array) ( $plan[\'assembly\'] ?? [] );', $queue );
	}

	/** Media references wait for siblings, verify provenance, and become immutable inputs. */
	public function test_demonstration_dependency_resolution_and_input_freeze_contract(): void {
		$resolve   = $this->method_source( 'resolve_demonstration_inputs' );
		$reference = $this->method_source( 'demonstration_reference_state' );
		$persist   = $this->method_source( 'persist_resolved_inputs' );

		$this->assertStringContainsString( 'Generation_Modality::media_inputs( $modality )', $resolve );
		$this->assertStringContainsString( 'Generation_Modality::required_inputs( $modality )', $resolve );
		$this->assertStringContainsString( 'demonstration_reference_state( $batch_id, $task_key, $slot, $plan )', $resolve );
		$this->assertStringContainsString( 'demonstration_reference_state( $batch_id, $fallback, $slot, $plan )', $resolve );
		$this->assertStringContainsString( "return [ 'status' => 'pending', 'inputs' => [] ];", $resolve );
		$this->assertStringContainsString( '$inputs[ $slot ] = (string) $state[\'attachment_id\'];', $resolve );
		$this->assertStringContainsString( '$batch_id === $attachment_batch', $reference );
		$this->assertStringContainsString( '$job_id === $attachment_job', $reference );
		$this->assertStringContainsString( 'get_post_mime_type( $attachment_id )', $reference );
		$this->assertStringContainsString( "array_key_exists( 'resolved_inputs', \$plan[ \$step ] )", $persist );
		$this->assertStringContainsString( 'worldgraph_generation_input_conflict', $persist );
		$this->assertStringContainsString( '$plan[ $step ][\'resolved_inputs\'] = $inputs;', $persist );
		$this->assertStringContainsString( 'self::BATCH_PLAN_META, wp_slash( $plan )', $persist );
	}

	/** Optional or already-linked enhancements produce terminal placeholders. */
	public function test_demonstration_optional_placeholder_and_waiting_contract(): void {
		$materialize = $this->method_source( 'materialize_demonstration_batch' );
		$placeholder = $this->method_source( 'create_placeholder_child' );

		$this->assertStringContainsString( "empty( \$task['generation_required'] )", $materialize );
		$this->assertStringContainsString( "empty( \$task['required'] ) ? 'skipped' : 'failed'", $materialize );
		$this->assertStringContainsString( "'pending' === ( \$resolved['status'] ?? '' )", $materialize );
		$this->assertStringContainsString( "'inputs'                => \$inputs", $materialize );
		$this->assertStringContainsString( "'initial_status'        => 'queued'", $materialize );
		$this->assertStringContainsString( "'batch_waiting_assembly', 'batch_materializing'", $materialize );
		$this->assertStringContainsString( "[ 'skipped', 'failed', 'cancelled' ]", $placeholder );
		$this->assertStringContainsString( "'_worldgraph_gen_task_key'", $placeholder );
		$this->assertStringContainsString( "update_post_meta( (int) \$job_id, '_worldgraph_gen_status', \$status )", $placeholder );
	}

	/** Assembly is claimed atomically and records both terminal outcomes. */
	public function test_demonstration_assembly_state_machine_contract(): void {
		$process  = $this->method_source( 'process_batches' );
		$assemble = $this->method_source( 'maybe_assemble_demonstration' );

		$this->assertStringContainsString( "[ 'batch_waiting_assembly', 'batch_assembling' ]", $process );
		$this->assertStringContainsString( 'self::maybe_assemble_demonstration( (int) $batch_id, $lock_token )', $process );
		$this->assertStringContainsString( 'self::is_cancel_requested( $batch_id )', $assemble );
		$this->assertStringContainsString( "'_worldgraph_gen_status', 'value' => self::ACTIVE_JOB_STATES", $assemble );
		$this->assertStringContainsString( "'batch_assembling', 'batch_waiting_assembly'", $assemble );
		$this->assertStringContainsString( 'Rough_Cut_Assembler::assemble( $batch_id )', $assemble );
		$this->assertStringContainsString( "'status' => 'failed'", $assemble );
		$this->assertStringContainsString( "'batch_assembly_failed', 'batch_assembling'", $assemble );
		$this->assertStringContainsString( '$record[\'status\'] = \'completed\';', $assemble );
		$this->assertStringContainsString( "'batch_complete', 'batch_assembling'", $assemble );
	}

	/** Batch summaries expose demonstration progress, skipped work, and assembly. */
	public function test_demonstration_batch_status_and_latest_lookup_contract(): void {
		$status = $this->method_source( 'batch_status' );
		$latest = $this->method_source( 'latest_batch' );

		$this->assertStringContainsString( 'in_array( $batch_kind, self::supported_batch_kinds(), true )', $status );
		$this->assertStringContainsString( '$skipped                 = (int) ( $counts[\'skipped\'] ?? 0 );', $status );
		$this->assertStringContainsString( '$terminal                = $completed + $failed + $skipped + $cancelled;', $status );
		$this->assertStringContainsString( "'batch_kind'      => \$batch_kind", $status );
		$this->assertStringContainsString( "'skipped'         => \$skipped", $status );
		$this->assertStringContainsString( "'assembly'        => \$assembly", $status );
		$this->assertStringContainsString( '$progress = min( 99, $progress );', $status );
		$this->assertStringContainsString( 'self::batch_kind_for_scope( $scope )', $latest );
		$this->assertStringContainsString( "[ 'key' => self::BATCH_SCOPE_META, 'value' => \$scope ]", $latest );
	}

	/** The REST boundary forwards audio settings and reports non-generated inputs. */
	public function test_demonstration_controller_audio_and_generation_required_contract(): void {
		$controller = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );

		$this->assertNotFalse( $controller );
		$this->assertStringContainsString( "'audio_template_id' => absint( \$request->get_param( 'audio_template_id' ) )", $controller );
		$this->assertStringContainsString( "'audio_run_values'  => (array) \$request->get_param( 'audio_run_values' )", $controller );
		$this->assertStringContainsString( "Generation_Workflows::common_templates( (array) \$plan['tasks'], 'audio' )", $controller );
		$this->assertStringContainsString( "\$defaults        = [ 'image' => [], 'video' => [], 'audio' => [] ];", $controller );
		$this->assertStringContainsString( "array_key_exists( 'generation_required', \$task )", $controller );
		$this->assertStringContainsString( "'generation_required' => \$generation_required", $controller );
		$this->assertStringContainsString( "'audio_templates'      => \$audio_templates", $controller );
		$this->assertStringContainsString( "Generation_Workflows::latest_batch( \$post_id, 'demonstration' )", $controller );
	}

	/** Direct REST generation must route the selected output, not hard-code image. */
	public function test_direct_rest_contract_supports_video(): void {
		$controller = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );
		$generator  = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );

		$this->assertNotFalse( $controller );
		$this->assertNotFalse( $generator );
		$this->assertStringContainsString( "'enum' => [ 'image', 'video' ]", $controller );
		$this->assertStringContainsString( "'type'         => \$type", $controller );
		$this->assertStringContainsString( "'prompt_is_composed' => false", $generator );
		$this->assertStringContainsString( 'self::build_prompt( $post_id, $intent, $provided_prompt )', $generator );
	}

	/** The guided UI must receive every same-type intent, not only the first image. */
	public function test_prompt_rest_contract_exposes_every_direct_action(): void {
		$controller = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );

		$this->assertNotFalse( $controller );
		$this->assertStringContainsString( "foreach ( (array) ( \$plan['tasks'] ?? [] ) as \$task )", $controller );
		$this->assertStringContainsString( "'actions'              => \$actions", $controller );
		$this->assertStringContainsString( "'featured'             => ! empty( \$task['featured'] )", $controller );
		$this->assertStringContainsString( '// Preserve the original first-image/first-video response for API clients.', $controller );
	}

	/** Long-running batches publish only complete jobs and stream large media. */
	public function test_durable_batch_commit_and_media_guards_are_present(): void {
		$workflow  = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );
		$generator = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );

		$this->assertNotFalse( $workflow );
		$this->assertNotFalse( $generator );
		$this->assertStringContainsString( 'private static function reserve_idempotency_key', $workflow );
		$this->assertStringContainsString( 'private static function acquire_coordinator_lock', $workflow );
		$this->assertStringContainsString( 'self::is_cancel_requested( $batch_id )', $workflow );
		$this->assertStringContainsString( "update_post_meta( \$batch_id, '_worldgraph_gen_status', 'batch_materializing' )", $workflow );
		$this->assertStringContainsString( "\$job_meta['_worldgraph_gen_status'] = \$initial_status", $generator );
		$this->assertStringContainsString( "'image' === \$requested_type && ! \$image_attachment_id", $generator );
		$this->assertStringContainsString( 'self::download_to_file( $video_url, self::MAX_VIDEO_BYTES', $generator );
		$this->assertStringContainsString( "'limit_response_size' => \$maximum_bytes + 1", $generator );
	}

	/** Cancellation and coordinator leases must have explicit race boundaries. */
	public function test_long_running_queue_race_guards_are_present(): void {
		$workflow = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );
		$worker   = file_get_contents( dirname( __DIR__ ) . '/includes/utils/generation-batch.php' );

		$this->assertNotFalse( $workflow );
		$this->assertNotFalse( $worker );
		$this->assertStringContainsString( 'private static function refresh_coordinator_lock', $workflow );
		$this->assertStringContainsString( "[ 'key' => '_worldgraph_gen_cancel_requested', 'compare' => 'NOT EXISTS' ]", $workflow );
		$this->assertStringContainsString( "update_post_meta( \$batch_id, '_worldgraph_gen_status', 'batch_cancelling' );", $workflow );
		$this->assertStringContainsString( "[ 'staged', 'queued', 'submitting' ]", $workflow );
		$this->assertStringContainsString( "self::claim_job( \$job_id, 'submitting', 'dispatching' )", $worker );
		$this->assertStringContainsString( "'submitting', 'dispatching', 'polling'", $worker );
		$this->assertStringContainsString( "elseif ( 'dispatching' === \$status )", $worker );
	}

	/** Root commits, downloads, and WordPress media types must be retry-safe. */
	public function test_batch_schedule_and_stream_boundaries_are_present(): void {
		$workflow  = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-generation-workflows.php' );
		$worker    = file_get_contents( dirname( __DIR__ ) . '/includes/utils/generation-batch.php' );
		$generator = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );
		$local     = file_get_contents( dirname( __DIR__ ) . '/includes/utils/local-comfyui.php' );

		$this->assertNotFalse( $workflow );
		$this->assertNotFalse( $worker );
		$this->assertNotFalse( $generator );
		$this->assertNotFalse( $local );
		$this->assertStringContainsString( 'public static function schedule(): bool', $worker );
		$this->assertStringContainsString( 'if ( ! Generation_Batch::schedule() )', $workflow );
		$this->assertStringContainsString( '$persisted = get_post_meta( $batch_id, $key, true )', $workflow );
		$this->assertStringContainsString( "if ( 'batch_materializing' !== get_post_meta( \$batch_id, '_worldgraph_gen_status', true ) )", $workflow );
		$this->assertStringContainsString( "'m4v'  => 'video/mp4'", $generator );
		$this->assertStringContainsString( "'avi'  => 'video/avi'", $generator );
		$this->assertStringContainsString( "[ 'video_url', 'videoUrl' ]", $generator );
		$this->assertStringContainsString( "wp_tempnam( 'worldgraph-generated-media' )", $generator );
		$this->assertStringContainsString( "'limit_response_size' => self::MAX_INPUT_BYTES + 1", $local );
	}
}
