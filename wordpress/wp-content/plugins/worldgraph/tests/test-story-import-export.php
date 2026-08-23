<?php
/**
 * Story Import & Export feature-plugin contracts.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraphStoryIO\Source_Extractor;
use WorldGraphStoryIO\Story_Decomposer;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return (string) $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ): string {
		$value = strtolower( trim( strip_tags( (string) $value ) ) );
		return trim( preg_replace( '/[^a-z0-9_]+/', '-', $value ), '-' );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $value ): string {
		return basename( (string) $value );
	}
}
if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $value ): string {
		return basename( (string) $value );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ): string {
		return (string) json_encode( $value, $flags );
	}
}

if ( ! defined( 'WORLDGRAPH_STORY_IO_PLUGIN_DIR' ) ) {
	define( 'WORLDGRAPH_STORY_IO_PLUGIN_DIR', dirname( __DIR__ ) . '/plugins/story-import-export/' );
}

require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-archive-reader.php';
require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-source-extractor.php';
require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-story-decomposer.php';

/** Feature-plugin extraction and decomposition coverage. */
class Test_Story_Import_Export extends TestCase {

	/** Core owns loading and state, while interchange implementation lives in the child plugin. */
	public function test_feature_plugin_is_default_enabled_and_loaded_before_consumers(): void {
		$root      = dirname( __DIR__ );
		$bootstrap = (string) file_get_contents( $root . '/worldgraph.php' );
		$plugin    = (string) file_get_contents( $root . '/plugins/story-import-export/story-import-export.php' );
		$registry  = (string) file_get_contents( $root . '/includes/admin/plugins.php' );

		$this->assertStringContainsString( "get_option( 'worldgraph_story_io_enabled', true )", $plugin );
		$this->assertStringContainsString( "add_option( 'worldgraph_story_io_enabled', true )", $bootstrap );
		$this->assertStringContainsString( "'story-import-export'", $registry );
		$this->assertLessThan(
			strpos( $bootstrap, "plugins/videodraft/videodraft-sync.php" ),
			strpos( $bootstrap, "plugins/story-import-export/story-import-export.php" )
		);
		$this->assertFileDoesNotExist( $root . '/includes/importer/class-worldgraph-importer.php' );
		$this->assertFileDoesNotExist( $root . '/includes/exporter/class-worldgraph-exporter.php' );
	}

	/** The wp-admin workflow stores an immutable preview before its only commit call. */
	public function test_admin_import_requires_server_preview_and_explicit_confirmation(): void {
		$source = (string) file_get_contents( WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-import-admin.php' );

		$this->assertStringContainsString( 'media_handle_upload(', $source );
		$this->assertSame( 2, substr_count( $source, "'dry_run'" ) );
		$this->assertStringContainsString( 'hash_equals(', $source );
		$this->assertStringContainsString( "'worldgraph_confirm_import'", $source );
		$this->assertStringContainsString( 'private static function acquire_lock(', $source );
		$this->assertSame( 3, substr_count( $source, ')->import(' ) );

		$confirm_start = strpos( $source, 'private static function confirm_preview(): void' );
		$cancel_start  = strpos( $source, 'private static function cancel_preview(): void' );
		$this->assertNotFalse( $confirm_start );
		$this->assertNotFalse( $cancel_start );
		$confirm = substr( $source, $confirm_start, $cancel_start - $confirm_start );
		$this->assertStringContainsString( "[ 'overwrite' => ! empty( \$preview['overwrite'] ) ]", $confirm );
	}

	/** Both maintained canonical fixtures are recognized without an LLM call. */
	public function test_canonical_examples_bypass_decomposition(): void {
		$project_root = dirname( dirname( __DIR__ ), 4 );
		$extractor    = new Source_Extractor();
		foreach ( [ 'little-red-riding-hood.worldgraph.json', 'little-red-riding-hood-full-featured.worldgraph.json' ] as $filename ) {
			$source = $extractor->extract_file( $project_root . '/about/example-workflow/' . $filename, $filename );
			$this->assertIsArray( $source );
			$this->assertTrue( $source['is_json'], $filename . ' should use the deterministic JSON path.' );
		}
	}

	/** Canonical JSON keeps the upload-size boundary instead of the manuscript text cap. */
	public function test_large_canonical_json_does_not_enter_manuscript_limit(): void {
		$document = [
			'worldgraph_version' => '1.2',
			'project'            => [ 'id' => 'large-project', 'title' => 'Large Project', 'description' => str_repeat( 'story ', 100000 ) ],
			'world'              => [ 'id' => 'large-world', 'name' => 'Large World', 'project' => 'large-project' ],
			'characters'         => [],
			'locations'          => [],
			'props'              => [],
			'scenes'             => [],
			'shots'              => [],
			'sequence'           => [ 'id' => 'large-sequence', 'title' => 'Main', 'order' => [] ],
		];
		$path = tempnam( sys_get_temp_dir(), 'wgs-story-' );
		$this->assertNotFalse( $path );

		try {
			file_put_contents( $path, json_encode( $document ) );
			$source = ( new Source_Extractor() )->extract_file( $path, 'large.worldgraph.json' );
			$this->assertIsArray( $source );
			$this->assertTrue( $source['is_json'] );
			$this->assertGreaterThan( Source_Extractor::MAX_TEXT_CHARS, $source['characters'] );
		} finally {
			if ( is_string( $path ) && file_exists( $path ) ) {
				unlink( $path );
			}
		}
	}

	/** The supplied EPUB and PDF examples produce useful story text when present. */
	public function test_public_domain_example_sources_extract_text(): void {
		$project_root = dirname( dirname( __DIR__ ), 4 );
		$fixtures = [
			'Preview of %E2%80%9CAn Occurence at Owl Creek%E2%80%9D.epub' => 'Owl Creek',
			'Beyond Lies the Wub - Philip K. Dick_175 (1).pdf'             => 'Beyond Lies the Wub',
		];
		foreach ( $fixtures as $filename => $expected ) {
			$path = $project_root . '/about/example-workflow/' . $filename;
			if ( ! file_exists( $path ) ) {
				$this->markTestSkipped( 'Optional public-domain source fixture is not present.' );
			}
			$source = ( new Source_Extractor() )->extract_file( $path, $filename );
			$this->assertIsArray( $source );
			$this->assertGreaterThan( 10000, $source['characters'] );
			$this->assertStringContainsString( $expected, $source['text'] );
		}
	}

	/** Generated IDs and references are deterministic and unsafe optional values are removed. */
	public function test_decomposition_normalization_is_deterministic(): void {
		$candidate = [
			'project'    => [ 'id' => 'p', 'title' => 'Forest Tale', 'production_stage' => 'released' ],
			'world'      => [ 'id' => 'w', 'name' => 'Forest' ],
			'characters' => [ [ 'id' => 'red', 'name' => 'Red', 'roles' => [ 'Hero' ] ] ],
			'locations'  => [ [ 'id' => 'path', 'name' => 'Path' ] ],
			'props'      => [],
			'scenes'     => [ [
				'id' => 's1', 'title' => 'Walk', 'location' => 'path', 'characters' => [ 'red' ],
				'props' => [], 'tags' => [ 'Forest Scene' ], 'dialogue' => [ [ 'speaker' => 'Red', 'text' => 'Hello.' ] ],
			] ],
			'shots'      => [
				[ 'id' => 'good', 'title' => 'Wide', 'scene' => 's1', 'type' => 'wide' ],
				[ 'id' => 'bad', 'title' => 'Lost', 'scene' => 'missing' ],
			],
			'sounds'     => [ [ 'id' => 'voice', 'title' => 'Voice', 'type' => 'Voice Over', 'scene' => 's1', 'shot' => 'bad' ] ],
			'sequence'   => [ 'id' => 'seq', 'title' => 'Main', 'order' => [ 's1' ] ],
		];

		$decomposer = new Story_Decomposer( new stdClass() );
		$first      = $decomposer->normalize_document( $candidate, 'Red walks.', 'forest.txt' );
		$second     = $decomposer->normalize_document( $candidate, 'Red walks.', 'forest.txt' );

		$this->assertSame( $first, $second );
		$this->assertArrayNotHasKey( 'production_stage', $first['project'] );
		$this->assertSame( [ 'hero' ], $first['characters'][0]['roles'] );
		$this->assertSame( [ 'forest-scene' ], $first['scenes'][0]['tags'] );
		$this->assertMatchesRegularExpression( '/\([a-f0-9]{12}\)$/', $first['sequence']['title'] );
		$this->assertSame( $first['characters'][0]['id'], $first['scenes'][0]['characters'][0] );
		$this->assertCount( 1, $first['shots'] );
		$this->assertSame( 'voiceover', $first['sounds'][0]['type'] );
		$this->assertArrayNotHasKey( 'shot', $first['sounds'][0] );
	}

	/** Long-source partials deduplicate named entities and preserve global Scene order. */
	public function test_chunk_merge_deduplicates_entities_and_orders_scenes(): void {
		$partials = [
			[
				'project' => [ 'title' => 'Long Tale' ], 'world' => [ 'name' => 'Forest' ],
				'characters' => [ [ 'id' => 'red-a', 'name' => 'Red' ] ], 'locations' => [], 'props' => [],
				'scenes' => [ [ 'id' => 'a', 'title' => 'First', 'characters' => [ 'red-a' ], 'props' => [] ] ],
			],
			[
				'project' => [ 'title' => 'Long Tale' ], 'world' => [ 'name' => 'Forest' ],
				'characters' => [ [ 'id' => 'red-b', 'name' => 'Red' ] ], 'locations' => [], 'props' => [],
				'scenes' => [ [ 'id' => 'b', 'title' => 'Second', 'characters' => [ 'red-b' ], 'props' => [] ] ],
			],
		];
		$decomposer = new Story_Decomposer( new stdClass() );
		$method     = new ReflectionMethod( Story_Decomposer::class, 'merge_partial_documents' );
		$method->setAccessible( true );
		$merged     = $method->invoke( $decomposer, $partials );
		$document   = $decomposer->normalize_document( $merged, str_repeat( 'Long story. ', 6000 ), 'long.txt' );

		$this->assertCount( 1, $document['characters'] );
		$this->assertCount( 2, $document['scenes'] );
		$this->assertSame( [ 1, 2 ], array_column( $document['scenes'], 'scene_number' ) );
		$this->assertSame( $document['characters'][0]['id'], $document['scenes'][0]['characters'][0] );
		$this->assertSame( $document['characters'][0]['id'], $document['scenes'][1]['characters'][0] );
		$this->assertSame( array_column( $document['scenes'], 'id' ), $document['sequence']['order'] );
	}
}
