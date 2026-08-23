<?php
/**
 * Behavioral tests for provider Comfy template discovery.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils {
	defined( 'ABSPATH' ) || exit;

	/** Controllable MCP stand-in for discovery tests. */
	class Comfy_Cloud_MCP {
		/** @var array<string, array> */
		public static $list_responses = [];

		/** @var array<string, array> */
		public static $get_responses = [];

		/** @var array<int, array> */
		public static $list_calls = [];

		/** @var array<int, array> */
		public static $get_calls = [];

		/** Reset test state. */
		public static function reset(): void {
			self::$list_responses = [];
			self::$get_responses  = [];
			self::$list_calls      = [];
			self::$get_calls       = [];
		}

		/** Return rows configured for the requested task type. */
		public static function list_templates( array $filters = [], int $connection_id = 0 ): array {
			$key = (string) ( $filters['task_type'] ?? '*' );
			self::$list_calls[] = [
				'filters'       => $filters,
				'connection_id' => $connection_id,
			];

			return [
				'templates' => self::$list_responses[ $key ] ?? self::$list_responses['*'] ?? [],
			];
		}

		/** Resolve one full descriptor and record its Connection scope. */
		public static function get_template( string $template_id, array $parameters = [], int $connection_id = 0 ): array {
			self::$get_calls[] = [
				'id'            => $template_id,
				'parameters'    => $parameters,
				'connection_id' => $connection_id,
			];

			return self::$get_responses[ $connection_id ][ $template_id ]
				?? self::$get_responses[ $template_id ]
				?? [];
		}
	}
}

namespace {

	use PHPUnit\Framework\TestCase;
	use WorldGraph\Utils\Comfy_Catalog;
	use WorldGraph\Utils\Comfy_Cloud_MCP;
	use WorldGraph\Utils\Comfy_Manifest;
	use WorldGraph\Utils\Generation_Modality;

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return $value;
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ): string {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free test shim.
			return trim( strip_tags( (string) $value ) );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, int $flags = 0 ): string {
			return (string) json_encode( $value, $flags );
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool {
			return $value instanceof WP_Error;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
	require_once dirname( __DIR__ ) . '/includes/utils/model_family.php';
	require_once dirname( __DIR__ ) . '/includes/utils/comfy-manifest.php';
	require_once dirname( __DIR__ ) . '/includes/utils/comfy-catalog.php';

	/** Provider discovery must retain sparse list metadata without redundant lookups. */
	class Test_Comfy_Template_Discovery extends TestCase {

		/** Reset the MCP stand-in and request-local descriptor cache. */
		protected function setUp(): void {
			Comfy_Cloud_MCP::reset();

			$cache = new ReflectionProperty( Comfy_Manifest::class, 'provider_descriptor_cache' );
			$cache->setAccessible( true );
			$cache->setValue( null, [] );
		}

		/** Filter context supplies a task type that a sparse result row omitted. */
		public function test_catalog_preserves_filtered_task_type_and_list_metadata(): void {
			Comfy_Cloud_MCP::$list_responses['text-to-video'] = [
				[
					'id'          => 'sparse-video-template',
					'name'        => 'Sparse provider video',
					'model_type'  => 'wan',
					'parameters'  => [ 'steps' => 21 ],
					'inputSchema' => [ 'properties' => [ 'prompt' => [ 'type' => 'string' ] ] ],
				],
			];
			Comfy_Cloud_MCP::$get_responses['sparse-video-template'] = [
				'workflow' => $this->video_workflow(),
			];

			$entries = $this->discover_catalog( 73 );

			$this->assertCount( 1, $entries );
			$this->assertSame( 'text-to-video', $entries[0]['task_type'] );
			$this->assertSame( Generation_Modality::TEXT_TO_VIDEO, $entries[0]['modality'] );
			$this->assertSame( 'wan', $entries[0]['model_type'] );
			$this->assertSame( [ 'steps' => 21 ], $entries[0]['parameters'] );
			$this->assertArrayHasKey( 'prompt', $entries[0]['provider_schema']['properties'] );
			$this->assertSame(
				[ [ 'id' => 'sparse-video-template', 'parameters' => [], 'connection_id' => 73 ] ],
				Comfy_Cloud_MCP::$get_calls
			);
		}

		/** An MCP server ignoring filters must not cause N duplicate detail calls. */
		public function test_catalog_deduplicates_before_enrichment_when_filters_are_ignored(): void {
			Comfy_Cloud_MCP::$list_responses['*'] = [
				[
					'id'         => 'repeated-template',
					'name'       => 'Repeated provider result',
					'parameters' => [ 'cfg' => 4.5 ],
				],
			];
			Comfy_Cloud_MCP::$get_responses['repeated-template'] = [
				'workflow' => $this->video_workflow(),
			];

			$entries = $this->discover_catalog( 74 );

			$this->assertCount( 1, $entries );
			$this->assertSame( '', $entries[0]['task_type'], 'Conflicting filter contexts must not invent a task type.' );
			$this->assertSame( Generation_Modality::TEXT_TO_VIDEO, $entries[0]['modality'] );
			$this->assertSame( [ 'cfg' => 4.5 ], $entries[0]['parameters'] );
			$this->assertCount( 1, Comfy_Cloud_MCP::$get_calls );
			$this->assertSame( 74, Comfy_Cloud_MCP::$get_calls[0]['connection_id'] );
		}

		/** Provider search deduplicates rows and resolves them on the requested Connection. */
		public function test_provider_search_deduplicates_and_passes_connection_to_enrichment(): void {
			Comfy_Cloud_MCP::$list_responses['*'] = [
				[
					'id'         => 'search-template',
					'task_type'  => 'text-to-video',
					'parameters' => [ 'steps' => 18 ],
				],
				[
					'id'          => 'search-template',
					'name'        => 'Search result',
					'inputSchema' => [ 'properties' => [ 'image' => [ 'type' => 'string' ] ] ],
				],
			];
			Comfy_Cloud_MCP::$get_responses['search-template'] = [
				'workflow' => $this->video_workflow(),
			];

			$entries = Comfy_Manifest::discover_provider_templates( 'search', 91 );

			$this->assertCount( 1, $entries );
			$this->assertSame( 'text-to-video', $entries[0]['task_type'] );
			$this->assertSame( [ 'steps' => 18 ], $entries[0]['parameters'] );
			$this->assertArrayHasKey( 'image', $entries[0]['provider_schema']['properties'] );
			$this->assertSame( 91, Comfy_Cloud_MCP::$list_calls[0]['connection_id'] );
			$this->assertSame( 91, Comfy_Cloud_MCP::$get_calls[0]['connection_id'] );
			$this->assertCount( 1, Comfy_Cloud_MCP::$get_calls );
		}

		/** Detail lookup caching is scoped by Connection as well as template ID. */
		public function test_descriptor_cache_is_connection_scoped(): void {
			Comfy_Cloud_MCP::$get_responses['cached-template'] = [
				'workflow' => $this->video_workflow(),
			];
			$row = [ 'id' => 'cached-template', 'task_type' => 'text-to-video' ];

			Comfy_Manifest::normalize_entry( $row, 11 );
			Comfy_Manifest::normalize_entry( $row, 11 );
			Comfy_Manifest::normalize_entry( $row, 12 );

			$this->assertSame( [ 11, 12 ], array_column( Comfy_Cloud_MCP::$get_calls, 'connection_id' ) );
		}

		/** Invoke the private catalog discovery seam without involving post storage. */
		private function discover_catalog( int $connection_id ): array {
			$method = new ReflectionMethod( Comfy_Catalog::class, 'discover_via_mcp' );
			$method->setAccessible( true );

			return $method->invoke( null, $connection_id, [ 'tools' => [ 'list_templates' ] ] );
		}

		/** Minimal graph whose nodes unambiguously indicate text-to-video. */
		private function video_workflow(): array {
			return [
				'1' => [ 'class_type' => 'WanTextToVideoApi', 'inputs' => [] ],
				'2' => [ 'class_type' => 'SaveVideo', 'inputs' => [] ],
			];
		}
	}
}
