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
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( array $args = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return [];
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return false;
	}
}

if ( ! defined( 'WORLDGRAPH_STORY_IO_PLUGIN_DIR' ) ) {
	define( 'WORLDGRAPH_STORY_IO_PLUGIN_DIR', dirname( __DIR__ ) . '/plugins/story-import-export/' );
}

require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-worldgraph-importer.php';
require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-archive-reader.php';
require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-source-extractor.php';
require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-story-decomposer.php';

/** Context-aware LLM double that emits one ordered Scene per bounded request. */
class Story_Import_Export_Context_LLM_Fake {
	/** @var array<int, int> */
	public array $context_requests = [];

	/** @var array<int, array{connection_id: int, prompt: string, options: array}> */
	public array $calls = [];

	public function model_context_window( int $connection_id ): int {
		$this->context_requests[] = $connection_id;
		return 4096;
	}

	/** Return a compact, parseable partial document for the next story part. */
	public function chat_with_connection( int $connection_id, string $prompt, array $options = [] ): array {
		$this->calls[] = compact( 'connection_id', 'prompt', 'options' );
		$part          = count( $this->calls );
		$scene_id      = 'partial-scene-' . $part;
		$document      = [
			'worldgraph_version' => '1.2',
			'project'            => [ 'id' => 'partial-project', 'title' => 'Context-Bounded Tale' ],
			'world'              => [ 'id' => 'partial-world', 'name' => 'Context-Bounded World' ],
			'characters'         => [],
			'locations'          => [],
			'props'              => [],
			'scenes'             => [ [
				'id'         => $scene_id,
				'title'      => 'Part ' . $part,
				'characters' => [],
				'props'      => [],
			] ],
			'shots'              => [],
			'sequence'           => [ 'id' => 'partial-sequence', 'title' => 'Main', 'order' => [ $scene_id ] ],
		];

		return [
			'content' => wp_json_encode( $document ),
			'tokens'  => 64,
			'backend' => 'test',
			'model'   => 'context-window-double',
		];
	}
}

/** Fail dense parts predictably so adaptive subdivision can be tested. */
class Story_Import_Export_Adaptive_LLM_Fake {
	/** @var array<int,array{prompt:string,options:array}> */
	public array $calls = [];

	private int $successes = 0;

	public function model_context_window( int $connection_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return 4096;
	}

	public function chat_with_connection( int $connection_id, string $prompt, array $options = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->calls[] = compact( 'prompt', 'options' );
		$parts         = explode( "BEGIN_UNTRUSTED_STORY_PART\n", $prompt, 2 );
		$part          = (string) ( $parts[1] ?? '' );
		if ( mb_strlen( $part, 'UTF-8' ) > 1000 ) {
			$response = [
				'content'       => wp_json_encode( [
					'project' => [ 'id' => 'p', 'title' => 'Parseable but truncated' ],
					'world'   => [ 'id' => 'w', 'name' => 'Adaptive World' ],
					'scenes'  => [ [ 'id' => 'cut-off', 'title' => 'Incomplete dense part' ] ],
				] ),
				'tokens'        => 25,
				'backend'       => 'test',
				'model'         => 'adaptive-double',
			];
			if ( 1 === count( $this->calls ) ) {
				$response['finish_reason'] = 'length';
			} else {
				$response['stop_reason'] = 'max_tokens';
			}
			return $response;
		}

		$this->successes++;
		$scene_id = 'adaptive-scene-' . $this->successes;
		return [
			'content' => wp_json_encode( [
				'project' => [ 'id' => 'p', 'title' => 'Adaptive Tale' ],
				'world'   => [ 'id' => 'w', 'name' => 'Adaptive World' ],
				'scenes'  => [ [ 'id' => $scene_id, 'title' => 'Recovered ' . $this->successes ] ],
			] ),
			'tokens'  => 40,
			'backend' => 'test',
			'model'   => 'adaptive-double',
		];
	}
}

/** Emit one parseable truncated direct candidate, then its complete repair. */
class Story_Import_Export_Direct_Truncation_LLM_Fake {
	/** @var array<int,array{prompt:string,options:array}> */
	public array $calls = [];

	public function model_context_window( int $connection_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return 131072;
	}

	public function chat_with_connection( int $connection_id, string $prompt, array $options = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->calls[] = compact( 'prompt', 'options' );
		$attempt       = count( $this->calls );
		$scene_id      = 'direct-scene-' . $attempt;
		return [
			'content'       => wp_json_encode( [
				'project' => [ 'id' => 'p', 'title' => 'Direct Repair Tale' ],
				'world'   => [ 'id' => 'w', 'name' => 'Direct Repair World' ],
				'scenes'  => [ [ 'id' => $scene_id, 'title' => 1 === $attempt ? 'Truncated draft' : 'Complete repair' ] ],
			] ),
			'tokens'        => 30,
			'backend'       => 'test',
			'model'         => 'direct-truncation-double',
			'finish_reason' => 1 === $attempt ? 'length' : 'stop',
		];
	}
}

/** Emit one graph-shaped diagnostic without Scenes, then a usable partial. */
class Story_Import_Export_Partial_Repair_LLM_Fake {
	/** @var array<int,array{prompt:string,options:array}> */
	public array $calls = [];

	public function model_context_window( int $connection_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return 4096;
	}

	public function chat_with_connection( int $connection_id, string $prompt, array $options = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->calls[] = compact( 'prompt', 'options' );
		$attempt       = count( $this->calls );
		$document      = 1 === $attempt
			? [ 'project' => [ 'title' => 'Diagnostic only' ], 'scenes' => [] ]
			: [
				'project' => [ 'id' => 'p', 'title' => 'Recovered Partial Tale' ],
				'world'   => [ 'id' => 'w', 'name' => 'Recovered Partial World' ],
				'scenes'  => [ [ 'id' => 's', 'title' => 'Recovered Scene' ] ],
			];

		return [
			'content' => wp_json_encode( $document ),
			'tokens'  => 20,
			'backend' => 'test',
			'model'   => 'partial-repair-double',
		];
	}
}

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

	/** Portable Scene and Shot camera movement accept the canonical editor choices. */
	public function test_portable_camera_movement_uses_scene_and_shot_choice_contract(): void {
		$document = [
			'worldgraph_version' => '1.2',
			'project'            => [
				'id'                => 'direction-project',
				'title'             => 'Direction Project',
				'generation_prompt' => 'Painterly animation, soft daylight, muted earth palette, and tactile paper texture.',
			],
			'world'              => [
				'id'      => 'direction-world',
				'name'    => 'Direction World',
				'project' => 'direction-project',
			],
			'characters'         => [],
			'locations'          => [],
			'props'              => [],
			'scenes'             => [
				[
					'id'                => 'direction-scene',
					'scene_number'      => 1,
					'title'             => 'Direction Scene',
					'characters'        => [],
					'props'             => [],
					'sequence'          => 'direction-sequence',
					'lens'              => '35mm',
					'generation_prompt' => 'Use cool window light and low-contrast shadows.',
				],
			],
			'shots'              => [
				[
					'id'                => 'direction-shot',
					'shot_number'       => 1,
					'title'             => 'Direction Shot',
					'scene'             => 'direction-scene',
					'sequence'          => 'direction-sequence',
					'motion_direction'  => 'The subject turns slowly, takes one step, and holds still.',
					'generation_prompt' => 'Keep the doorway unobstructed.',
				],
			],
			'sequence'           => [
				'id'             => 'direction-sequence',
				'title'          => 'Direction Sequence',
				'sequence_order' => 1,
				'order'          => [ 'direction-scene' ],
			],
		];

		$allowed = [ 'locked_off', 'handheld', 'pan_left', 'pan_right', 'tilt_up', 'tilt_down', 'push_in', 'pull_back', 'track_left', 'track_right', 'follow_subject', 'orbit_left', 'orbit_right', 'crane_up', 'crane_down', 'zoom_in', 'zoom_out' ];
		foreach ( [ 'scene', 'shot' ] as $entity ) {
			$group = json_decode( (string) file_get_contents( dirname( __DIR__ ) . "/acf-json/group_worldgraph_{$entity}.json" ), true );
			$field = current( array_filter( $group['fields'], static fn( array $candidate ): bool => 'camera_movement' === ( $candidate['name'] ?? '' ) ) );

			$this->assertIsArray( $field );
			$this->assertSame( $allowed, array_keys( $field['choices'] ) );
		}
		foreach ( $allowed as $movement ) {
			$document['scenes'][0]['camera_movement'] = $movement;
			$document['shots'][0]['camera_movement']  = $movement;
			$result = ( new \WorldGraph\Importer\WorldGraph_Importer() )->import( wp_json_encode( $document ), [ 'dry_run' => true ] );

			$this->assertIsArray( $result, "{$movement} should be a valid Scene and Shot camera movement." );
			$this->assertTrue( $result['verified'] );
		}

		$document['shots'][0]['camera_movement'] = 'whip_pan';
		$invalid = ( new \WorldGraph\Importer\WorldGraph_Importer() )->import( wp_json_encode( $document ), [ 'dry_run' => true ] );

		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'worldgraph_invalid_field', $invalid->get_error_code() );
		$this->assertStringContainsString( 'Shot direction-shot camera_movement has an invalid value.', $invalid->get_error_message() );

		$document['shots'][0]['camera_movement']   = 'locked_off';
		$document['scenes'][0]['camera_movement'] = 'whip_pan';
		$invalid = ( new \WorldGraph\Importer\WorldGraph_Importer() )->import( wp_json_encode( $document ), [ 'dry_run' => true ] );

		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'worldgraph_invalid_field', $invalid->get_error_code() );
		$this->assertStringContainsString( 'Scene direction-scene camera_movement has an invalid value.', $invalid->get_error_message() );
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

	/**
	 * The supplied EPUB and PDF examples produce useful story text when present.
	 *
	 * @dataProvider public_domain_source_provider
	 */
	public function test_public_domain_example_sources_extract_text( string $filename, string $expected ): void {
		$project_root = dirname( dirname( __DIR__ ), 4 );
		$locations    = [
			$project_root . '/about/example-workflow',
			$project_root . '/wordpress/wp-content/uploads',
		];
		$path         = '';
		foreach ( $locations as $location ) {
			$candidate = $location . '/' . $filename;
			if ( file_exists( $candidate ) ) {
				$path = $candidate;
				break;
			}
		}
		if ( '' === $path ) {
			$this->markTestSkipped( 'Optional public-domain source fixture is not present in either supported fixture location.' );
		}

		$source = ( new Source_Extractor() )->extract_file( $path, $filename );
		$this->assertIsArray( $source );
		$this->assertGreaterThan( 10000, $source['characters'] );
		$this->assertStringContainsString( $expected, $source['text'] );
		if ( str_ends_with( $filename, '.pdf' ) ) {
			$this->assertStringNotContainsString( 'щ', $source['text'] );
			$this->assertDoesNotMatchRegularExpression( '/[ﬀﬁﬂﬃﬄﬅﬆ]/u', $source['text'] );
		}
	}

	/** Keep independently located source fixtures from skipping one another. */
	public function public_domain_source_provider(): array {
		return [
			'EPUB' => [ 'Preview of %E2%80%9CAn Occurence at Owl Creek%E2%80%9D.epub', 'Owl Creek' ],
			'PDF'  => [ 'Beyond Lies the Wub - Philip K. Dick_175 (1).pdf', 'Beyond Lies the Wub' ],
		];
	}

	/** Presentation ligatures extracted from documents become portable ASCII text. */
	public function test_text_extraction_normalizes_unicode_presentation_ligatures(): void {
		$path = tempnam( sys_get_temp_dir(), 'wgs-ligatures-' );
		$this->assertNotFalse( $path );

		try {
			file_put_contents( $path, 'oﬀer ﬁction ﬂower eﬃcient waﬄe ﬅage ﬆage' );
			$source = ( new Source_Extractor() )->extract_file( $path, 'ligatures.txt' );
			$this->assertIsArray( $source );
			$this->assertSame( 'offer fiction flower efficient waffle stage stage', $source['text'] );
		} finally {
			if ( is_string( $path ) && file_exists( $path ) ) {
				unlink( $path );
			}
		}
	}

	/** Model chatter and harmless trailing commas do not discard a later valid object. */
	public function test_decomposition_json_extraction_recovers_bounded_model_output(): void {
		$decomposer = new Story_Decomposer( new stdClass() );
		$content    = "Rejected diagnostic: {\"status\":\"invalid\",\"message\":\"Try again\"}\nCorrected result:\n{\"worldgraph\":{\"project\":{\"title\":\"Recovered\",},\"scenes\":[{\"title\":\"Opening\",},],}}";
		$document   = $decomposer->extract_document( $content );

		$this->assertIsArray( $document );
		$this->assertSame( 'Recovered', $document['project']['title'] );
		$this->assertSame( 'Opening', $document['scenes'][0]['title'] );

		$fenced_correction = $decomposer->extract_document(
			"{\"project\":{\"title\":\"Abandoned draft\"}\n```json\n{\"worldgraph\":{\"project\":{\"title\":\"Fenced correction\"},\"scenes\":[]}}\n```"
		);
		$this->assertIsArray( $fenced_correction );
		$this->assertSame( 'Fenced correction', $fenced_correction['project']['title'] );

		$incomplete = $decomposer->extract_document( '{"project":{"title":"Cut off"}' );
		$this->assertTrue( is_wp_error( $incomplete ) );
		$this->assertSame( 'worldgraph_story_decompose_json_incomplete', $incomplete->get_error_code() );
	}

	/** Paragraph boundaries must not force a within-budget story past its part limit. */
	public function test_split_story_balances_paragraph_boundaries_across_remaining_slots(): void {
		$story      = str_repeat( str_repeat( 'x', 31000 ) . "\n\n", 9 );
		$decomposer = new Story_Decomposer( new stdClass() );
		$method     = new ReflectionMethod( Story_Decomposer::class, 'split_story' );
		$method->setAccessible( true );

		$this->assertSame( 279016, mb_strlen( trim( $story ), 'UTF-8' ) );
		$chunks = $method->invoke( $decomposer, $story, 50000, 6 );

		$this->assertIsArray( $chunks );
		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertLessThanOrEqual( 6, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 50000, mb_strlen( $chunk, 'UTF-8' ) );
		}
		$this->assertSame( str_repeat( 'x', 279000 ), str_replace( "\n", '', implode( '', $chunks ) ) );
	}

	/** Context-sized splitting avoids a tiny final fragment that loses narrative context. */
	public function test_split_story_balances_the_final_part(): void {
		$story      = trim( str_repeat( "A sustained narrative sentence continues through the excerpt. ", 260 ) );
		$decomposer = new Story_Decomposer( new stdClass() );
		$method     = new ReflectionMethod( Story_Decomposer::class, 'split_story' );
		$method->setAccessible( true );
		$chunks = $method->invoke( $decomposer, $story, 1638, 24 );

		$this->assertIsArray( $chunks );
		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertGreaterThan( 1200, min( array_map( 'mb_strlen', $chunks ) ) );
		$this->assertSame( preg_replace( '/\s+/', '', $story ), preg_replace( '/\s+/', '', implode( '', $chunks ) ) );
	}

	/** Recognizable ebook distribution wrappers are excluded from LLM input. */
	public function test_manuscript_preparation_removes_bounded_paratext(): void {
		$decomposer = new Story_Decomposer( new stdClass() );
		$method     = new ReflectionMethod( Story_Decomposer::class, 'prepare_manuscript' );
		$method->setAccessible( true );

		$gutenberg = "OCR notice\n*** START OF THIS PROJECT GUTENBERG EBOOK A TALE ***\nNarrative body continues.\n*** END OF THIS PROJECT GUTENBERG EBOOK A TALE ***\nLicense text.";
		$this->assertSame( 'Narrative body continues.', $method->invoke( $decomposer, $gutenberg ) );

		$wrapped = "*** START OF THIS PROJECT GUTENBERG EBOOK A TALE ***\nAN OCCURRENCE AT OWL CREEK BRIDGE by Ambrose Bierce THE MILLENNIUM FULCRUM EDITION, 1988 Narrative body. End of Project Gutenberg's An Occurrence at Owl Creek, by Ambrose Bierce\n*** END OF THIS PROJECT GUTENBERG EBOOK A TALE ***";
		$prepared = $method->invoke( $decomposer, $wrapped );
		$this->assertStringStartsWith( 'AN OCCURRENCE AT OWL CREEK BRIDGE by Ambrose Bierce', $prepared );
		$this->assertStringNotContainsString( 'MILLENNIUM FULCRUM EDITION', $prepared );
		$this->assertStringNotContainsString( 'End of Project Gutenberg', $prepared );
		$title_method = new ReflectionMethod( Story_Decomposer::class, 'manuscript_title' );
		$title_method->setAccessible( true );
		$this->assertSame( 'Beyond Lies the Wub', $title_method->invoke( $decomposer, "Beyond Lies the Wub\n\nPhilip K. Dick\n\nPublished:\n1952\n\nStory." ) );
		$this->assertSame( 'Beyond Lies the Wub', $title_method->invoke( $decomposer, "Beyond Lies the Wub\nBy Philip K. Dick\n\nStory." ) );
		$this->assertSame( 'An Occurrence at Owl Creek Bridge', $title_method->invoke( $decomposer, $prepared ) );

		$narrative = "OPENING narrative body.\n" . str_repeat( 'More narrative detail. ', 20 );
		$feedbooks = "Biography and catalog.\nTranscriber's Note:\nConversion details.\n\n" . $narrative . "\nwww.feedbooks.com\nFood for the mind";
		$this->assertSame( trim( $narrative ), $method->invoke( $decomposer, $feedbooks ) );
	}

	/** Excess weak-model Scenes retain their distinct boundaries and fields. */
	public function test_partial_scene_normalization_preserves_boundaries_and_fields(): void {
		$decomposer = new Story_Decomposer( new stdClass() );
		$method     = new ReflectionMethod( Story_Decomposer::class, 'compact_partial_scenes' );
		$method->setAccessible( true );
		$scenes = [
			[ 'id' => 'one', 'title' => 'One', 'summary' => 'First.', 'script_content' => 'First action.', 'characters' => [ 'a' ], 'location' => 'first-place' ],
			[ 'id' => 'two', 'title' => 'Two', 'summary' => 'Second.', 'script_content' => 'Second action.', 'characters' => [ 'b' ], 'time_of_day' => 'night' ],
			[ 'id' => 'three', 'title' => 'Three', 'summary' => 'Third.', 'script_content' => 'Third action.', 'characters' => [ 'c' ], 'props' => [ 'key' ], 'location' => 'third-place', 'dialogue' => [ [ 'speaker' => 'C', 'line' => 'Hello.' ] ] ],
		];
		$document = [ 'scenes' => [ $scenes[0], 'invalid', $scenes[1], $scenes[2] ] ];

		$normalized = $method->invoke( $decomposer, $document );
		$this->assertSame( $scenes, $normalized['scenes'] );
	}

	/** A small model context dynamically decomposes a medium story in safe ordered parts. */
	public function test_context_window_forces_bounded_partial_decomposition(): void {
		$llm         = new Story_Import_Export_Context_LLM_Fake();
		$decomposer  = new Story_Decomposer( $llm );
		$story       = mb_substr( str_repeat( "Ordered manuscript paragraph with enough detail for one scene.\n\n", 400 ), 0, 17500, 'UTF-8' );
		$connection  = 73;
		$result      = $decomposer->decompose( $story, 'context-bounded.txt', $connection );

		$this->assertSame( 17500, mb_strlen( $story, 'UTF-8' ) );
		$this->assertIsArray( $result );
		$this->assertContains( $connection, $llm->context_requests );
		$this->assertGreaterThan( 1, count( $llm->calls ) );
		$this->assertSame( count( $llm->calls ), $result['chunks'] );

		foreach ( $llm->calls as $call ) {
			$this->assertSame( $connection, $call['connection_id'] );
			$this->assertStringContainsString( 'BEGIN_UNTRUSTED_STORY_PART', $call['prompt'] );
			$this->assertLessThan( mb_strlen( $story, 'UTF-8' ), mb_strlen( $call['prompt'], 'UTF-8' ) );
			$this->assertArrayHasKey( 'max_tokens', $call['options'] );
			$this->assertIsInt( $call['options']['max_tokens'] );
			$this->assertGreaterThan( 0, $call['options']['max_tokens'] );
			$this->assertLessThan( 2048, $call['options']['max_tokens'] );
			$this->assertStringContainsString( 'compact partial World Graph Studio JSON object', $call['options']['system_prompt'] );
			$this->assertStringNotContainsString( 'Shots are optional', $call['options']['system_prompt'] );
		}

		$scene_count = count( $llm->calls );
		$this->assertSame(
			array_map( static fn( int $part ): string => 'Part ' . $part, range( 1, $scene_count ) ),
			array_column( $result['document']['scenes'], 'title' )
		);
		$this->assertSame( range( 1, $scene_count ), array_column( $result['document']['scenes'], 'scene_number' ) );
		$this->assertSame( array_column( $result['document']['scenes'], 'id' ), $result['document']['sequence']['order'] );

		$validation = ( new \WorldGraph\Importer\WorldGraph_Importer() )->import( $result['json'], [ 'dry_run' => true ] );
		$this->assertIsArray( $validation );
		$this->assertTrue( $validation['verified'] );
	}

	/** Unknown metadata stays conservative, large contexts allow direct detail, and unsafe tiny contexts fail closed. */
	public function test_context_profile_does_not_force_every_discovered_model_into_compact_parts(): void {
		$method = new ReflectionMethod( Story_Decomposer::class, 'decomposition_profile' );
		$method->setAccessible( true );

		$unknown = $method->invoke( new Story_Decomposer( new stdClass() ), 1, 'compact prompt' );
		$this->assertTrue( $unknown['force_chunks'] );
		$this->assertSame( 2500, $unknown['chunk_chars'] );
		$this->assertSame( 1536, $unknown['max_tokens'] );

		$large_llm = new class() {
			public function model_context_window( int $connection_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return 131072;
			}
		};
		$large = $method->invoke( new Story_Decomposer( $large_llm ), 2, 'compact prompt' );
		$this->assertFalse( $large['force_chunks'] );
		$this->assertSame( 50000, $large['chunk_chars'] );

		$tiny_llm = new class() {
			public function model_context_window( int $connection_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return 1024;
			}
		};
		$tiny = $method->invoke( new Story_Decomposer( $tiny_llm ), 3, 'compact prompt' );
		$this->assertArrayHasKey( 'error', $tiny );
		$this->assertTrue( is_wp_error( $tiny['error'] ) );
		$this->assertSame( 'worldgraph_story_decompose_context_too_small', $tiny['error']->get_error_code() );
	}

	/** A persistently truncated dense part is retried, halved, and merged in order. */
	public function test_truncated_part_is_adaptively_subdivided(): void {
		$llm        = new Story_Import_Export_Adaptive_LLM_Fake();
		$decomposer = new Story_Decomposer( $llm );
		$story      = mb_substr( str_repeat( 'Adaptive recovery sentence preserves ordered manuscript facts. ', 40 ), 0, 1400, 'UTF-8' );
		$result     = $decomposer->decompose( $story, 'adaptive.txt', 91 );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['chunks'] );
		$this->assertSame( 5, $result['attempts'] );
		$this->assertSame( 155, $result['tokens'] );
		$this->assertCount( 5, $llm->calls );
		$this->assertSame( [ 'Recovered 1', 'Recovered 2' ], array_column( $result['document']['scenes'], 'title' ) );
	}

	/** A graph-shaped partial without a usable Scene is repaired before merge. */
	public function test_partial_without_scene_evidence_is_repaired(): void {
		$llm        = new Story_Import_Export_Partial_Repair_LLM_Fake();
		$decomposer = new Story_Decomposer( $llm );
		$result     = $decomposer->decompose( 'A traveler crosses the bridge and reaches the far bank.', 'partial-repair.txt', 92 );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['attempts'] );
		$this->assertCount( 2, $llm->calls );
		$this->assertStringContainsString( 'did not contain any Scenes', $llm->calls[1]['prompt'] );
		$this->assertLessThan(
			strpos( $llm->calls[1]['prompt'], 'BEGIN_UNTRUSTED_STORY_PART' ),
			strpos( $llm->calls[1]['prompt'], 'prior response was not a usable compact story graph' )
		);
		$this->assertSame( [ 'Recovered Scene' ], array_column( $result['document']['scenes'], 'title' ) );
	}

	/** A parseable direct response at its output limit must receive a repair. */
	public function test_parseable_truncated_direct_document_is_repaired(): void {
		$llm        = new Story_Import_Export_Direct_Truncation_LLM_Fake();
		$decomposer = new Story_Decomposer( $llm );
		$result     = $decomposer->decompose( 'A traveler crosses the bridge and reaches the far bank.', 'direct-repair.txt', 93 );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['attempts'] );
		$this->assertSame( 60, $result['tokens'] );
		$this->assertCount( 2, $llm->calls );
		$this->assertStringContainsString( 'BEGIN_UNTRUSTED_STORY', $llm->calls[0]['prompt'] );
		$this->assertStringNotContainsString( 'BEGIN_UNTRUSTED_STORY_PART', $llm->calls[0]['prompt'] );
		$this->assertStringContainsString( 'reached its output limit', $llm->calls[1]['prompt'] );
		$this->assertStringContainsString( 'A traveler crosses the bridge and reaches the far bank.', $llm->calls[1]['prompt'] );
		$this->assertStringNotContainsString( 'Truncated draft', $llm->calls[1]['prompt'] );
		$this->assertLessThan(
			strpos( $llm->calls[1]['prompt'], 'BEGIN_UNTRUSTED_STORY' ),
			strpos( $llm->calls[1]['prompt'], 'previous candidate failed authoritative validation' )
		);
		$this->assertSame( [ 'Complete repair' ], array_column( $result['document']['scenes'], 'title' ) );
	}

	/** Generated IDs and references are deterministic and unsafe optional values are removed. */
	public function test_decomposition_normalization_is_deterministic(): void {
		$candidate = [
			'project'    => [ 'id' => 'p', 'title' => 'Forest Tale', 'production_stage' => 'released' ],
			'world'      => [ 'id' => 'w', 'name' => 'Forest' ],
			'characters' => [ [ 'id' => 'red', 'name' => 'Red', 'roles' => [ 'Hero' ] ] ],
			'locations'  => [ [ 'id' => 'path', 'name' => 'Path' ], [ 'id' => 'blank-location', 'name' => ' ' ] ],
			'props'      => [ [ 'id' => 'blank-prop' ] ],
			'scenes'     => [ [
				'id' => 's1', 'title' => ' ', 'location' => 'path', 'characters' => [ 'red' ],
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
		$first      = $decomposer->normalize_document( $candidate, 'Red walks on the Path.', 'forest.txt' );
		$second     = $decomposer->normalize_document( $candidate, 'Red walks on the Path.', 'forest.txt' );

		$this->assertSame( $first, $second );
		$this->assertArrayNotHasKey( 'production_stage', $first['project'] );
		$this->assertSame( [ 'hero' ], $first['characters'][0]['roles'] );
		$this->assertCount( 1, $first['locations'] );
		$this->assertSame( [], $first['props'] );
		$this->assertSame( 'Scene 1', $first['scenes'][0]['title'] );
		$this->assertSame( [ 'forest-scene' ], $first['scenes'][0]['tags'] );
		$this->assertMatchesRegularExpression( '/\([a-f0-9]{12}\)$/', $first['sequence']['title'] );
		$this->assertSame( $first['characters'][0]['id'], $first['scenes'][0]['characters'][0] );
		$this->assertCount( 1, $first['shots'] );
		$this->assertSame( 'voiceover', $first['sounds'][0]['type'] );
		$this->assertArrayNotHasKey( 'shot', $first['sounds'][0] );
	}

	/** A confident heading controls the title without deleting repeated, evidenced Character labels. */
	public function test_manuscript_heading_controls_project_title_and_catalog_grounding(): void {
		$candidate = [
			'project'    => [ 'title' => 'The Millennium Fulcrum Edition, 1988' ],
			'world'      => [],
			'characters' => [
				[ 'id' => 'heading-only', 'name' => 'An Occurrence at Owl Creek Bridge' ],
				[ 'id' => 'wrong-title', 'name' => 'An Occurrence at Owl Creek' ],
				[ 'id' => 'farquhar', 'name' => 'Peyton Farquhar' ],
			],
			'scenes'     => [ [ 'id' => 'scene', 'title' => 'Bridge' ] ],
		];
		$source = 'AN OCCURRENCE AT OWL CREEK BRIDGE by Ambrose Bierce A man stood on the bridge. Peyton Farquhar waited. An Occurrence at Owl Creek was printed above.';
		$document = ( new Story_Decomposer( new stdClass() ) )->normalize_document( $candidate, $source, 'preview.epub' );

		$this->assertSame( 'An Occurrence at Owl Creek Bridge', $document['project']['title'] );
		$this->assertSame( [ 'An Occurrence at Owl Creek', 'Peyton Farquhar' ], array_column( $document['characters'], 'name' ) );
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
		$document   = $decomposer->normalize_document( $merged, str_repeat( 'Red walks through the long story. ', 6000 ), 'long.txt' );

		$this->assertCount( 1, $document['characters'] );
		$this->assertCount( 2, $document['scenes'] );
		$this->assertSame( [ 1, 2 ], array_column( $document['scenes'], 'scene_number' ) );
		$this->assertSame( $document['characters'][0]['id'], $document['scenes'][0]['characters'][0] );
		$this->assertSame( $document['characters'][0]['id'], $document['scenes'][1]['characters'][0] );
		$this->assertSame( array_column( $document['scenes'], 'id' ), $document['sequence']['order'] );
	}

	/** Conflicting model field types do not trigger warnings or replace valid lists. */
	public function test_chunk_merge_handles_scalar_and_array_type_drift(): void {
		$partials = [
			[
				'project' => [ 'title' => [ 'invalid' ], 'genres' => [ 'science-fiction' ] ],
				'world'   => [ 'name' => 'Mars' ],
			],
			[
				'project' => [ 'title' => 'Beyond Lies the Wub', 'genres' => 'invalid-scalar' ],
				'world'   => [ 'name' => 'Mars' ],
			],
		];
		$decomposer = new Story_Decomposer( new stdClass() );
		$method     = new ReflectionMethod( Story_Decomposer::class, 'merge_partial_documents' );
		$method->setAccessible( true );
		$merged = $method->invoke( $decomposer, $partials );

		$this->assertSame( 'Beyond Lies the Wub', $merged['project']['title'] );
		$this->assertSame( [ 'science-fiction' ], $merged['project']['genres'] );
	}

	/** Common titles/articles deduplicate Characters and cross-type hallucinations. */
	public function test_chunk_normalization_deduplicates_aliases_and_cross_types(): void {
		$partials = [
			[
				'project' => [ 'title' => 'Wub' ], 'world' => [ 'name' => 'Mars' ],
				'characters' => [ [ 'id' => 'franco-a', 'name' => 'Captain Franco' ], [ 'id' => 'wub-a', 'name' => 'The Wub' ], [ 'id' => 'farquhar-a', 'name' => 'Peyton Farquhar' ] ],
				'locations' => [ [ 'id' => 'wrong-location', 'name' => 'Wub' ], [ 'id' => 'wrong-franco-location', 'name' => 'Captain Franco' ] ],
				'props' => [ [ 'id' => 'wrong-prop', 'name' => 'The Wub' ] ],
			],
			[
				'project' => [ 'title' => 'Wub' ], 'world' => [ 'name' => 'Mars' ],
				'characters' => [ [ 'id' => 'franco-b', 'name' => 'Franco' ], [ 'id' => 'captain-b', 'name' => 'Captain' ], [ 'id' => 'wub-b', 'name' => 'Wub' ], [ 'id' => 'farquhar-b', 'name' => 'Farquhar' ], [ 'id' => 'false-character', 'name' => 'Captain Ryker' ] ],
				'locations' => [ [ 'id' => 'false-location', 'name' => 'Moon Base' ] ],
				'props' => [ [ 'id' => 'false-prop', 'name' => 'Laser Rifle' ] ],
			],
		];
		$decomposer = new Story_Decomposer( new stdClass() );
		$method     = new ReflectionMethod( Story_Decomposer::class, 'merge_partial_documents' );
		$method->setAccessible( true );
		$source   = 'Captain Franco and the Wub spoke with Peyton Farquhar on Mars.';
		$merged   = $method->invoke( $decomposer, $partials, $source );
		$document = $decomposer->normalize_document( $merged, $source, 'wub.txt' );

		$this->assertCount( 3, $document['characters'] );
		$this->assertSame( [ 'Captain Franco', 'The Wub', 'Peyton Farquhar' ], array_column( $document['characters'], 'name' ) );
		$this->assertSame( [], $document['locations'] );
		$this->assertSame( [], $document['props'] );
	}

	/** Typed references preserve evidenced same-label entities across catalogs. */
	public function test_normalization_preserves_referenced_cross_type_entities(): void {
		$candidate = [
			'project'    => [ 'title' => 'Namesake' ],
			'world'      => [ 'name' => 'Namesake World' ],
			'characters' => [
				[ 'id' => 'georgia-character', 'name' => 'Georgia' ],
				[ 'id' => 'rose-character', 'name' => 'Rose' ],
			],
			'locations'  => [ [ 'id' => 'georgia-location', 'name' => 'Georgia' ] ],
			'props'      => [ [ 'id' => 'rose-prop', 'name' => 'Rose' ] ],
			'scenes'     => [ [
				'id'         => 'arrival',
				'title'      => 'Arrival',
				'location'   => 'georgia-location',
				'characters' => [ 'georgia-character', 'rose-character' ],
				'props'      => [ 'rose-prop' ],
			] ],
		];
		$source     = 'Georgia welcomed Rose back to Georgia. Rose carried a rose into the house.';
		$decomposer = new Story_Decomposer( new stdClass() );
		$method     = new ReflectionMethod( Story_Decomposer::class, 'merge_partial_documents' );
		$method->setAccessible( true );
		$merged = $method->invoke( $decomposer, [ $candidate ], $source );

		$document = $decomposer->normalize_document( $merged, $source, 'namesake.txt' );

		$this->assertSame( [ 'Georgia' ], array_column( $document['locations'], 'name' ) );
		$this->assertSame( [ 'Rose' ], array_column( $document['props'], 'name' ) );
		$this->assertSame( $document['locations'][0]['id'], $document['scenes'][0]['location'] );
		$this->assertSame( [ $document['props'][0]['id'] ], $document['scenes'][0]['props'] );
		$this->assertSame( array_column( $document['characters'], 'id' ), $document['scenes'][0]['characters'] );
	}
}
