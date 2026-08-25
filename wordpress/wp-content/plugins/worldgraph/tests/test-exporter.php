<?php
/**
 * Tests for World Graph Studio markdown export flow.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/**
 * Class Test_WorldGraph_Exporter
 */
class Test_WorldGraph_Exporter extends TestCase {
	/**
	 * The admin export flow should persist generated files through WordPress uploads.
	 */
	public function test_admin_export_uses_wordpress_upload_storage() {
		$path   = dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-export-admin.php';
		$source = file_get_contents( $path );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'wp_upload_bits( $filename, null, $content )', $source );
		$this->assertStringNotContainsString( "header( 'Content-Disposition", $source );
	}

	/**
	 * The exporter should work from live World Graph Studio project records rather than import JSON snapshots.
	 */
	public function test_exporter_builds_markdown_from_live_project_data() {
		$this->assertTrue( class_exists( '\\WorldGraph\\Exporter\\WorldGraph_Exporter' ), 'Exporter class must exist.' );

		$exporter = new \WorldGraph\Exporter\WorldGraph_Exporter();
		$this->assertIsObject( $exporter );
		$this->assertTrue( method_exists( $exporter, 'export_project_markdown' ) );
	}

	/**
	 * The exporter should build a storyboard document from scene and shot data.
	 */
	public function test_exporter_builds_storyboard_markdown_from_scene_and_shot_data() {
		$exporter = new \WorldGraph\Exporter\WorldGraph_Exporter();

		$markdown = $exporter->export_project_storyboard_markdown( [
			'title'  => 'Little Red Riding Hood',
			'world'  => 'Forest Edge',
			'scenes' => [
				[
					'id'             => 101,
					'title'          => 'The Warning',
					'scene_number'   => 1,
					'location'       => 'Mother\'s Cottage',
					'time_of_day'    => 'morning',
					'summary'        => 'Mother warns Red to stay on the path.',
					'shots'          => [
						[
							'id'               => 201,
							'title'            => 'Basket Close-Up',
							'shot_number'      => 2,
							'shot_type'        => 'close_up',
							'camera_angle'     => 'eye_level',
							'lens'             => '50mm',
							'duration'         => '00:00:04',
							'shot_description' => 'The basket is packed and handed to Red.',
						],
					],
				],
			],
		] );

		$this->assertStringContainsString( '# Little Red Riding Hood Storyboard', $markdown );
		$this->assertStringContainsString( '## Scene 1: The Warning', $markdown );
		$this->assertStringContainsString( '### Basket Close-Up - Close Up', $markdown );
		$this->assertStringContainsString( 'The basket is packed and handed to Red.', $markdown );
		$this->assertStringContainsString( 'shots: 1', $markdown );
	}

	/**
	 * A scene without shots still renders as a storyboard block.
	 */
	public function test_exporter_reports_scenes_without_shots() {
		$exporter = new \WorldGraph\Exporter\WorldGraph_Exporter();

		$markdown = $exporter->export_project_storyboard_markdown( [
			'title'  => 'Scene Board',
			'scenes' => [
				[
					'title'        => 'Forest Path',
					'scene_number' => 3,
				],
			],
		] );

		$this->assertStringContainsString( '## Scene 3: Forest Path', $markdown );
		$this->assertStringContainsString( '_No shots found for this scene yet._', $markdown );
		$this->assertStringContainsString( 'shots: 0', $markdown );
	}
}
