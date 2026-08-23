<?php
/**
 * ElevenLabs Connection adapter contract tests.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\ElevenLabs_Catalog;

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free test shim.
		return trim( strip_tags( (string) $value ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/elevenlabs-api.php';
require_once dirname( __DIR__ ) . '/includes/utils/elevenlabs-catalog.php';

/** ElevenLabs adapter tests. */
class Test_ElevenLabs extends TestCase {

	/** The catalog prefers an explicitly configured model and the stable default. */
	public function test_catalog_model_selection(): void {
		$method = new ReflectionMethod( ElevenLabs_Catalog::class, 'select_model' );
		$method->setAccessible( true );
		$models = [
			[ 'model_id' => 'eleven_v3' ],
			[ 'model_id' => 'eleven_multilingual_v2' ],
		];

		$this->assertSame( 'eleven_v3', $method->invoke( null, 'eleven_v3', $models ) );
		$this->assertSame( 'eleven_multilingual_v2', $method->invoke( null, '', $models ) );
	}

	/** Voice allowlists control provisioning; empty configuration selects one voice. */
	public function test_catalog_voice_selection(): void {
		$method = new ReflectionMethod( ElevenLabs_Catalog::class, 'select_voices' );
		$method->setAccessible( true );
		$voices = [
			[ 'voice_id' => 'voice-a', 'name' => 'A' ],
			[ 'voice_id' => 'voice-b', 'name' => 'B' ],
		];

		$this->assertSame( [ $voices[0] ], $method->invoke( null, '', $voices ) );
		$this->assertSame( [ $voices[1] ], $method->invoke( null, '["voice-b"]', $voices ) );
	}

	/** Provisioning owns a distinct Template definition for each supported generation method. */
	public function test_endpoint_template_definitions(): void {
		$method = new ReflectionMethod( ElevenLabs_Catalog::class, 'method_definitions' );
		$method->setAccessible( true );
		$definitions = $method->invoke( null, [ 'voice_id' => 'voice-a', 'name' => 'A' ] );

		$this->assertSame(
			[ 'text-to-dialogue', 'sound-effects', 'music', 'voice-design' ],
			array_column( $definitions, 'reference' )
		);
		$this->assertSame(
			[ 'text_to_dialogue', 'text_to_sound_effect', 'text_to_music', 'text_to_voice' ],
			array_column( $definitions, 'modality' )
		);
		foreach ( $definitions as $definition ) {
			$this->assertStringStartsWith( 'POST /v1/', $definition['schema']['endpoint'] );
			$this->assertSame( 'audio', \WorldGraph\Utils\Generation_Modality::output_type( $definition['modality'] ) );
		}
	}

	/** Audio bytes must cross the WordPress import boundary before completion. */
	public function test_audio_completion_contract(): void {
		$batch = file_get_contents( dirname( __DIR__ ) . '/includes/utils/generation-batch.php' );
		$assets = file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );

		$this->assertNotFalse( $batch );
		$this->assertNotFalse( $assets );
		$this->assertStringContainsString( "[ 'image', 'video', 'audio' ]", $batch );
		$this->assertStringContainsString( 'Asset_Generator::import_completed_job', $batch );
		$this->assertLessThan(
			strpos( $batch, "self::persist_job_meta( \$job_id, '_worldgraph_gen_result', \$stored_result )" ),
			strpos( $batch, "unset( \$stored_result['audio_data'], \$stored_result['audio_items'] )" )
		);
		$this->assertStringContainsString( "'audio_data'", $assets );
		$this->assertStringContainsString( "'audio_items'", $assets );
		$this->assertStringContainsString( 'validate_audio_bytes', $assets );
	}

	/** Registry and setup UI expose ElevenLabs through conditional loading. */
	public function test_registry_contract(): void {
		$registry = file_get_contents( dirname( __DIR__ ) . '/includes/utils/connection-adapters.php' );
		$wizard = file_get_contents( dirname( __DIR__ ) . '/includes/admin/setup-wizard.php' );

		$this->assertStringContainsString( "'elevenlabs' => [", $registry );
		$this->assertStringContainsString( "includes/utils/elevenlabs-api.php", $registry );
		$this->assertStringContainsString( "ElevenLabs Generative Audio", $registry );
		$this->assertStringContainsString( "'elevenlabs' === \$mode", $wizard );
	}
}
