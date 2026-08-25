<?php
/**
 * Exact-Template prompt preview contracts.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/** Prevent live prompt previews from composing unrelated recipe outputs. */
class Test_Generation_Prompt_Preview extends TestCase {

	/** Return one controller method body up to the next docblock. */
	private function method_source( string $source, string $signature, string $next_docblock ): string {
		$start = strpos( $source, $signature );
		$this->assertNotFalse( $start, "Missing method: {$signature}" );
		$end = strpos( $source, $next_docblock, (int) $start );
		$this->assertNotFalse( $end, "Missing boundary after: {$signature}" );
		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	/** Preview constructs and finalizes only the selected item task. */
	public function test_preview_does_not_build_a_full_sibling_plan(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );
		$method = $this->method_source(
			$source,
			'public static function preview_prompt( WP_REST_Request $request )',
			"\n\t/** Return the detailed default prompt"
		);

		$this->assertStringContainsString( 'self::direct_item_task( $post_id, $type, $intent )', $method );
		$this->assertStringNotContainsString( 'Generation_Workflows::plan', $method );
		$this->assertSame( 1, substr_count( $method, 'Generation_Workflows::finalize_task_prompt' ) );
	}

	/** Direct queue validation likewise avoids composing every sibling action. */
	public function test_direct_generation_validates_only_the_requested_task(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );
		$method = $this->method_source(
			$source,
			'public static function generate( WP_REST_Request $request )',
			"\n\t/** Return one selected Template's exact prompt"
		);

		$this->assertStringContainsString( 'self::direct_item_task( $post_id, $type, $intent )', $method );
		$this->assertStringNotContainsString( 'Generation_Workflows::plan', $method );
	}

	/** Initial metabox data composes each action only after its Template is known. */
	public function test_initial_prompt_response_uses_a_non_composing_item_plan(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/asset-generation-controller.php' );
		$method = $this->method_source(
			$source,
			'public static function get_prompt( WP_REST_Request $request )',
			"\n\t/** Return one exact Template's inherited"
		);

		$this->assertStringContainsString( "Generation_Workflows::plan( \$post_id, 'item', '', false )", $method );
		$this->assertSame( 1, substr_count( $method, 'Generation_Workflows::finalize_task_prompt' ) );
		$this->assertStringContainsString( 'Asset_Generator::build_prompt', $method );
	}
}
