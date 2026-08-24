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
		$this->assertStringContainsString( "'demonstration_video'", $source );
		$this->assertStringContainsString( "'representative_media'", $source );
		$this->assertStringContainsString( 'get_post_thumbnail_id( $shot_id )', $source );
		$this->assertStringContainsString( 'Asset_Generator::GALLERY_META', $source );
		$this->assertStringContainsString( 'subtitles=filename=', $source );
		$this->assertStringContainsString( 'anullsrc=', $source );
		$this->assertStringContainsString( 'self::$known_files', $source );
		$this->assertStringContainsString( 'self::path_is_within( $file, $work_dir )', $source );
	}
}
