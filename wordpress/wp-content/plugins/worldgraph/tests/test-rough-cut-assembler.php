<?php
/**
 * FFmpeg rough-cut assembly contracts.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Rough_Cut_Assembler;

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return (string) $text;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ): string {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return 'UTF-8';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/class-rough-cut-assembler.php';

/** Rough-cut helper and orchestration tests. */
class Test_Rough_Cut_Assembler extends TestCase {

	/** Availability always returns the documented diagnostic shape. */
	public function test_availability_contract_is_stable(): void {
		$availability = Rough_Cut_Assembler::availability();

		$this->assertSame( [ 'available', 'binary', 'error' ], array_keys( $availability ) );
		$this->assertIsBool( $availability['available'] );
		$this->assertIsString( $availability['binary'] );
		$this->assertIsString( $availability['error'] );
	}

	/** Cue positions accept seconds, ISO durations, and frame timecodes. */
	public function test_timecode_parsing_is_deterministic(): void {
		$this->assertSame( 12.5, Rough_Cut_Assembler::parse_timecode( '12.5' ) );
		$this->assertSame( 3723.5, Rough_Cut_Assembler::parse_timecode( 'PT1H2M3.5S' ) );
		$this->assertSame( 62.5, Rough_Cut_Assembler::parse_timecode( '00:01:02:12', 24 ) );
		$this->assertSame( 0.0, Rough_Cut_Assembler::parse_timecode( 'not-a-timecode' ) );
	}

	/** Dialogue remains ordered, bounded, and valid SubRip text. */
	public function test_srt_generation_uses_shot_timeline_and_plain_text(): void {
		$srt = Rough_Cut_Assembler::build_srt( [
			[
				'scene_title'   => '<b>The Arrival</b>',
				'shot_title'    => 'Wide shot',
				'duration'      => 2.5,
				'dialogue_lines'=> [ "MARA: We made it.\x00" ],
			],
			[
				'scene_title' => 'The Arrival',
				'shot_title'  => 'Reaction',
				'duration'    => 1.25,
			],
		] );

		$this->assertStringContainsString( "1\r\n00:00:00,000 --> 00:00:02,500", $srt );
		$this->assertStringContainsString( "2\r\n00:00:02,500 --> 00:00:03,750", $srt );
		$this->assertStringContainsString( 'MARA: We made it.', $srt );
		$this->assertStringNotContainsString( '<b>', $srt );
		$this->assertStringNotContainsString( "\x00", $srt );
	}

	/** Mix levels favor speech while keeping music and ambience conservative. */
	public function test_role_aware_volumes_are_conservative(): void {
		$this->assertSame( 1.0, Rough_Cut_Assembler::role_volume( 'voice-over' ) );
		$this->assertGreaterThan( Rough_Cut_Assembler::role_volume( 'music' ), Rough_Cut_Assembler::role_volume( 'sound-effect' ) );
		$this->assertGreaterThan( Rough_Cut_Assembler::role_volume( 'music' ), Rough_Cut_Assembler::role_volume( 'ambience' ) );
	}

	/** A frozen no-Shot timeline resolves completed Scene/Project cards by task key. */
	public function test_frozen_demonstration_timeline_maps_fallback_stills(): void {
		$segments = Rough_Cut_Assembler::fallback_segments_from_plan(
			[
				'assembly' => [
					'timeline' => [
						[
							'scene_id'    => 41,
							'scene_order' => 0,
							'scene_title' => 'The Crossing',
							'segments'    => [
								[
									'scene_id'                => 41,
									'fallback_still_task_key' => 'demo-scene-41-still',
									'duration'                => 'PT6S',
									'subtitle_text'           => 'They cross at dawn.',
								],
							],
						],
						[
							'scene_id'    => 0,
							'scene_order' => 1,
							'scene_title' => 'Project Epilogue',
							'segments'    => [
								[
									'scene_id'                => 0,
									'fallback_still_task_key' => 'demo-project-7-still',
									'duration'                => 3,
									'subtitle_text'           => 'The end.',
								],
							],
						],
					],
				],
			],
			[
				[
					'task_key'      => 'demo-scene-41-still',
					'source_id'     => 41,
					'source_type'   => 'worldgraph_scene',
					'source_title'  => 'The Crossing',
					'attachment_id' => 901,
					'still_file'    => '/bounded/scene-41.jpg',
				],
				[
					'task_key'      => 'demo-project-7-still',
					'source_id'     => 7,
					'source_type'   => 'worldgraph_project',
					'source_title'  => 'Project Epilogue',
					'attachment_id' => 902,
					'still_file'    => '/bounded/project-7.jpg',
				],
			]
		);

		$this->assertCount( 2, $segments );
		$this->assertSame( 0, $segments[0]['shot_id'] );
		$this->assertSame( 41, $segments[0]['scene_id'] );
		$this->assertSame( 6.0, $segments[0]['duration'] );
		$this->assertSame( '/bounded/scene-41.jpg', $segments[0]['still_file'] );
		$this->assertSame( [ 'They cross at dawn.' ], $segments[0]['dialogue_lines'] );
		$this->assertSame( 0, $segments[1]['scene_id'] );
		$this->assertSame( '/bounded/project-7.jpg', $segments[1]['still_file'] );
		$this->assertSame( [ 'The end.' ], $segments[1]['dialogue_lines'] );
		$this->assertStringContainsString( 'They cross at dawn.', Rough_Cut_Assembler::build_srt( $segments ) );
	}

	/** Frozen Shot rows retain plan order and become cards when generation fails. */
	public function test_frozen_shot_timeline_merges_outputs_and_retains_card_rows(): void {
		$tasks = [
			[
				'task_key'    => 'demo-shot-80-video',
				'source_id'   => 80,
				'source_type' => 'worldgraph_shot',
				'source_title' => 'Doorway reveal',
				'type'        => 'video',
			],
			[
				'task_key'    => 'demo-shot-80-still',
				'source_id'   => 80,
				'source_type' => 'worldgraph_shot',
				'source_title' => 'Doorway reveal',
				'type'        => 'image',
			],
			[
				'task_key'    => 'demo-shot-12-video',
				'source_id'   => 12,
				'source_type' => 'worldgraph_shot',
				'source_title' => 'Mara reacts',
				'type'        => 'video',
			],
			[
				'task_key'    => 'demo-shot-12-still',
				'source_id'   => 12,
				'source_type' => 'worldgraph_shot',
				'source_title' => 'Mara reacts',
				'type'        => 'image',
			],
		];
		$plan = [
			'timeline' => [
				[
					'scene_id'        => 9,
					'scene_order'     => 0,
					'scene_title'     => 'Frozen Scene Name',
					'subtitle_text'   => 'MARA: Stay close.',
					'sound_task_keys' => [ 'demo-sound-4-audio' ],
					'segments'        => [
						[
							'scene_id'                => 9,
							'shot_id'                 => 80,
							'shot_order'              => 0,
							'video_task_key'          => 'demo-shot-80-video',
							'fallback_still_task_key' => 'demo-shot-80-still',
							'duration'                => 'PT2.5S',
							'subtitle_text'           => 'The door opens.',
							'audio_task_keys'         => [ 'demo-sound-4-audio' ],
						],
						[
							'scene_id'                => 9,
							'shot_id'                 => 12,
							'shot_order'              => 1,
							'video_task_key'          => 'demo-shot-12-video',
							'fallback_still_task_key' => 'demo-shot-12-still',
							'duration'                => 6,
							'subtitle_text'           => 'Mara sees the visitor.',
						],
					],
				],
			],
		];
		$outputs = [
			[
				'task_key'      => 'demo-shot-80-video',
				'source_id'     => 80,
				'source_type'   => 'worldgraph_shot',
				'type'          => 'video',
				'attachment_id' => 901,
				'file'          => '/bounded/shot-80.mp4',
			],
			// Wrong source provenance must not fill the second planned Shot.
			[
				'task_key'      => 'demo-shot-12-still',
				'source_id'     => 80,
				'source_type'   => 'worldgraph_shot',
				'type'          => 'image',
				'attachment_id' => 902,
				'file'          => '/bounded/wrong-source.jpg',
			],
		];

		$segments = Rough_Cut_Assembler::demonstration_segments_from_plan( $plan, $tasks, $outputs );

		$this->assertCount( 2, $segments );
		$this->assertSame( [ 80, 12 ], array_column( $segments, 'shot_id' ) );
		$this->assertSame( 'Frozen Scene Name', $segments[0]['scene_title'] );
		$this->assertSame( 'Doorway reveal', $segments[0]['shot_title'] );
		$this->assertSame( 2.5, $segments[0]['duration'] );
		$this->assertSame( '/bounded/shot-80.mp4', $segments[0]['video_file'] );
		$this->assertFalse( $segments[0]['card_only'] );
		$this->assertSame( [ 'The door opens.', 'MARA: Stay close.' ], $segments[0]['dialogue_lines'] );
		$this->assertSame( [ 'demo-sound-4-audio' ], $segments[0]['audio_task_keys'] );
		$this->assertSame( 'Mara reacts', $segments[1]['shot_title'] );
		$this->assertSame( 6.0, $segments[1]['duration'] );
		$this->assertSame( '', $segments[1]['still_file'] );
		$this->assertTrue( $segments[1]['card_only'] );
		$this->assertStringContainsString( 'Mara sees the visitor.', Rough_Cut_Assembler::build_srt( $segments ) );
	}

	/** Durable progress reports bounded stage completion without claiming 100%. */
	public function test_durable_state_progress_is_stable_and_bounded(): void {
		$method = new ReflectionMethod( Rough_Cut_Assembler::class, 'pending_result' );
		$method->setAccessible( true );
		$state = [
			'shots'         => [ [ 'shot_id' => 1 ], [ 'shot_id' => 2 ] ],
			'segment_index' => 2,
			'stage'         => 'subtitle',
		];
		$result = $method->invoke( null, 77, $state, 'Subtitle stage' );

		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 77, $result['batch_id'] );
		$this->assertSame( 'subtitle', $result['stage'] );
		$this->assertSame( 3, $result['completed_steps'] );
		$this->assertSame( 6, $result['total_steps'] );
		$this->assertSame( 50, $result['progress'] );
		$this->assertSame( 50, $result['progress_percent'] );
		$this->assertSame( 'Subtitle stage', $result['message'] );

		$state['stage'] = 'import';
		$result = $method->invoke( null, 77, $state, 'Import stage' );
		$this->assertSame( 5, $result['completed_steps'] );
		$this->assertSame( 83, $result['progress'] );
		$this->assertLessThan( 100, $result['progress'] );
	}

	/** Durable temp state accepts only its fixed generated filenames. */
	public function test_durable_state_filename_allowlist_rejects_paths(): void {
		$method = new ReflectionMethod( Rough_Cut_Assembler::class, 'safe_state_filename' );
		$method->setAccessible( true );

		$this->assertSame( 'segment-0001.mp4', $method->invoke( null, 'segment-0001.mp4', 'segment' ) );
		$this->assertSame( 'rough-cut-final.mp4', $method->invoke( null, 'rough-cut-final.mp4' ) );
		$this->assertSame( 'rough-cut-sideload.mp4', $method->invoke( null, 'rough-cut-sideload.mp4' ) );
		$this->assertSame( '', $method->invoke( null, '../rough-cut-final.mp4' ) );
		$this->assertSame( '', $method->invoke( null, 'unowned.tmp' ) );
	}

	/** Orchestration keeps execution shell-free and cleanup scoped to owned files. */
	public function test_orchestration_has_required_safety_and_provenance_contracts(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-rough-cut-assembler.php' );

		$this->assertStringContainsString( "apply_filters( 'worldgraph_ffmpeg_binary'", $source );
		$this->assertStringContainsString( 'proc_open( $command,', $source );
		$this->assertStringContainsString( "[ 'bypass_shell' => true", $source );
		$this->assertStringNotContainsString( 'shell_exec(', $source );
		$this->assertStringNotContainsString( 'system(', $source );
		$this->assertStringContainsString( "'_worldgraph_gen_batch_id'", $source );
		$this->assertStringContainsString( "'_worldgraph_gen_job_id'", $source );
		$this->assertStringContainsString( "'_worldgraph_gen_assembly_plan'", $source );
		$this->assertStringContainsString( "'_worldgraph_gen_batch_step'", $source );
		$this->assertStringContainsString( "'fallback_still_task_key'", $source );
		$this->assertStringContainsString( 'self::direct_fallback_segments( $completed )', $source );
		$this->assertStringContainsString( "'demonstration_video'", $source );
		$this->assertStringContainsString( "'representative_media'", $source );
		$this->assertStringContainsString( 'get_post_thumbnail_id( $shot_id )', $source );
		$this->assertStringContainsString( 'Asset_Generator::GALLERY_META', $source );
		$this->assertStringContainsString( 'subtitles=filename=', $source );
		$this->assertStringContainsString( 'anullsrc=', $source );
		$this->assertStringContainsString( 'color=c=black:s=', $source );
		$this->assertStringContainsString( "'_worldgraph_gen_cancel_requested'", $source );
		$this->assertStringContainsString( "'worldgraph_rough_cut_cancelled'", $source );
		$this->assertStringContainsString( "const STATE_META = '_worldgraph_rough_cut_state'", $source );
		$this->assertStringContainsString( 'public static function advance( int $batch_id )', $source );
		$this->assertStringContainsString( 'public static function cancel( int $batch_id ): void', $source );
		$this->assertStringContainsString( 'delete_post_meta( $batch_id, self::STATE_META )', $source );
		$this->assertStringContainsString( 'hash_equals( $signature, self::state_signature( $stored ) )', $source );
		$this->assertStringContainsString( 'self::state_signature( $state )', $source );
		$this->assertStringContainsString( 'self::safe_batch_work_dir(', $source );
		$this->assertStringContainsString( 'self::existing_imported_video(', $source );
		$this->assertStringContainsString( "'rough-cut-sideload.mp4'", $source );
		$this->assertStringContainsString( "'worldgraph_rough_cut_provenance_failed'", $source );
		$this->assertStringContainsString( 'wp_delete_attachment( $id, true )', $source );
		$this->assertStringContainsString( 'self::$known_files', $source );
		$this->assertStringContainsString( 'self::path_is_within( $file, $work_dir )', $source );
	}
}
