<?php
/**
 * Optional WPVDB Story RAG bridge contracts.
 *
 * @package WorldGraph
 */

namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			/** @var string */
			private $code;
			/** @var string */
			private $message;
			/** @var mixed */
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
}

namespace WPVDB {
	if ( ! class_exists( __NAMESPACE__ . '\\Settings' ) ) {
		final class Settings {
			/** @var bool */
			public static $configured = true;

			/** @var string */
			public static $provider = 'openai';

			/** @var string */
			public static $model = 'text-embedding-test';

			public static function validate_configuration() {
				return self::$configured ? true : new \WP_Error( 'missing_api_key' );
			}

			public static function get_active_provider(): string {
				return self::$provider;
			}

			public static function get_active_model(): string {
				return self::$model;
			}

			public static function get_default_model(): string {
				return self::$model;
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\\Models' ) ) {
		final class Models {
			/** @var bool */
			public static $available = true;

			public static function get_model( $provider, $model ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return self::$available ? [ 'id' => $model ] : false;
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\\Core' ) ) {
		final class Core {
			/** @var array<int, mixed> */
			public static $responses = [];

			/** @var array<int, array{text: string, model: string, provider: string}> */
			public static $calls = [];

			public static function get_embedding_for_model( $text, $model, $provider ) {
				self::$calls[] = compact( 'text', 'model', 'provider' );
				$response      = array_shift( self::$responses );
				if ( $response instanceof \Throwable ) {
					throw $response;
				}
				return $response;
			}
		}
	}
}

namespace WorldGraphStoryRAG {
	function plugin_dir_path( string $file ): string {
		return trailingslashit_for_test( dirname( $file ) );
	}

	function trailingslashit_for_test( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}

	function get_option( string $key, $default = false ) {
		return $GLOBALS['worldgraph_story_rag_options'][ $key ] ?? $default;
	}

	function get_current_user_id(): int {
		return (int) ( $GLOBALS['worldgraph_story_rag_user_id'] ?? 0 );
	}

	function current_user_can( string $capability ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}

	function esc_html_e( string $text, string $domain = 'default' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function is_wp_error( $value ): bool {
		return $value instanceof \WP_Error;
	}

	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['worldgraph_story_rag_hooks']['actions'][ $hook ] = compact( 'callback', 'priority', 'accepted_args' );
	}

	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['worldgraph_story_rag_hooks']['filters'][ $hook ] = compact( 'callback', 'priority', 'accepted_args' );
	}

	function get_transient( string $key ) {
		return $GLOBALS['worldgraph_story_rag_transients'][ $key ]['value'] ?? false;
	}

	function set_transient( string $key, $value, int $expiration ): bool {
		$GLOBALS['worldgraph_story_rag_transients'][ $key ] = [
			'value'      => $value,
			'expiration' => $expiration,
		];
		return true;
	}

	function delete_transient( string $key ): bool {
		unset( $GLOBALS['worldgraph_story_rag_transients'][ $key ] );
		return true;
	}
}

namespace WorldGraph\Tests {
	use PHPUnit\Framework\TestCase;
	use WorldGraphStoryRAG\Story_RAG_Retrieval;

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
	}

	$GLOBALS['worldgraph_story_rag_options'] = [ 'worldgraph_story_rag_enabled' => false ];
	require_once dirname( __DIR__ ) . '/plugins/story-rag-decomposer/story-rag-decomposer.php';
	require_once dirname( __DIR__ ) . '/plugins/story-rag-decomposer/includes/class-story-rag-retrieval.php';

	final class Story_RAG_Decomposer_Test extends TestCase {
		private const SOURCE_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

		protected function setUp(): void {
			$GLOBALS['worldgraph_story_rag_options']    = [ 'worldgraph_story_rag_enabled' => false ];
			$GLOBALS['worldgraph_story_rag_user_id']    = 17;
			$GLOBALS['worldgraph_story_rag_transients'] = [];
			$GLOBALS['worldgraph_story_rag_hooks']      = [];
			\WPVDB\Settings::$configured                = true;
			\WPVDB\Settings::$provider                  = 'openai';
			\WPVDB\Settings::$model                     = 'text-embedding-test';
			\WPVDB\Models::$available                   = true;
			\WPVDB\Core::$responses                     = [];
			\WPVDB\Core::$calls                         = [];
		}

		public function test_feature_is_optional_and_declares_wpvdb_requirement(): void {
			$root      = dirname( __DIR__ );
			$plugin    = (string) file_get_contents( $root . '/plugins/story-rag-decomposer/story-rag-decomposer.php' );
			$bootstrap = (string) file_get_contents( $root . '/worldgraph.php' );
			$registry  = (string) file_get_contents( $root . '/includes/admin/plugins.php' );

			$this->assertStringContainsString( 'Requires Plugins: worldgraph, wpvdb', $plugin );
			$this->assertStringContainsString( "get_option( 'worldgraph_story_rag_enabled', false )", $plugin );
			$this->assertStringContainsString( "plugins/story-rag-decomposer/story-rag-decomposer.php", $bootstrap );
			$this->assertStringContainsString( "add_option( 'worldgraph_story_rag_enabled', false )", $bootstrap );
			$this->assertStringContainsString( "'story-rag-decomposer'", $registry );
			$this->assertStringContainsString( 'WorldGraphStoryRAG\\is_configured()', $registry );
		}

		public function test_configuration_requires_wpvdb_provider_and_model(): void {
			$this->assertTrue( \WorldGraphStoryRAG\is_configured() );

			\WPVDB\Models::$available = false;
			$this->assertFalse( \WorldGraphStoryRAG\is_configured() );

			\WPVDB\Models::$available    = true;
			\WPVDB\Settings::$configured = false;
			$this->assertFalse( \WorldGraphStoryRAG\is_configured() );
		}

		public function test_hooks_cover_evidence_retrieval_and_explicit_cleanup(): void {
			Story_RAG_Retrieval::init();

			$this->assertSame( 3, $GLOBALS['worldgraph_story_rag_hooks']['actions']['worldgraph_story_decomposition_evidence_ready']['accepted_args'] );
			$this->assertSame( 4, $GLOBALS['worldgraph_story_rag_hooks']['filters']['worldgraph_story_decomposition_retrieval_context']['accepted_args'] );
			$this->assertSame( 2, $GLOBALS['worldgraph_story_rag_hooks']['actions']['worldgraph_story_rag_cleanup']['accepted_args'] );
		}

		public function test_evidence_storage_contains_only_vectors_and_identifiers(): void {
			\WPVDB\Core::$responses[] = $this->vector( 1.0, 0.0 );
			$evidence                  = $this->evidence( 'chapter-1', 0, 'PRIVATE_EVIDENCE_TEXT' );
			$evidence['reasoning']     = 'PRIVATE_MODEL_REASONING';
			$evidence['credential']    = 'PRIVATE_API_SECRET';
			$evidence['story_text']    = 'PRIVATE_MANUSCRIPT_TEXT';

			Story_RAG_Retrieval::capture_evidence( $evidence, $this->chunk( 'chapter-1', 0 ), [] );

			$this->assertCount( 2, $GLOBALS['worldgraph_story_rag_transients'] );
			$stored = (string) json_encode( $GLOBALS['worldgraph_story_rag_transients'] );
			$this->assertStringNotContainsString( 'PRIVATE_EVIDENCE_TEXT', $stored );
			$this->assertStringNotContainsString( 'PRIVATE_MODEL_REASONING', $stored );
			$this->assertStringNotContainsString( 'PRIVATE_API_SECRET', $stored );
			$this->assertStringNotContainsString( 'PRIVATE_MANUSCRIPT_TEXT', $stored );
			$this->assertStringContainsString( 'PRIVATE_EVIDENCE_TEXT', \WPVDB\Core::$calls[0]['text'] );
			$this->assertStringNotContainsString( 'PRIVATE_MODEL_REASONING', \WPVDB\Core::$calls[0]['text'] );
			$this->assertStringNotContainsString( 'PRIVATE_API_SECRET', \WPVDB\Core::$calls[0]['text'] );
			$this->assertStringNotContainsString( 'PRIVATE_MANUSCRIPT_TEXT', \WPVDB\Core::$calls[0]['text'] );
			$this->assertSame( 'openai', \WPVDB\Core::$calls[0]['provider'] );
			$this->assertSame( 'text-embedding-test', \WPVDB\Core::$calls[0]['model'] );

			foreach ( $GLOBALS['worldgraph_story_rag_transients'] as $record ) {
				$this->assertSame( 7200, $record['expiration'] );
			}
		}

		public function test_cosine_retrieval_returns_only_top_same_source_evidence(): void {
			$corpus = [
				$this->evidence( 'chapter-0', 0, 'First' ),
				$this->evidence( 'chapter-1', 1, 'Current' ),
				$this->evidence( 'chapter-2', 2, 'Second' ),
				$this->evidence( 'chapter-3', 3, 'Third' ),
			];
			\WPVDB\Core::$responses = [
				$this->vector( 1.0, 0.0 ),
				$this->vector( 0.0, 1.0 ),
				$this->vector( 0.8, 0.2 ),
				$this->vector( 0.0, 0.9 ),
				$this->vector( 1.0, 0.0 ),
			];

			foreach ( $corpus as $index => $evidence ) {
				Story_RAG_Retrieval::capture_evidence( $evidence, $this->chunk( 'chapter-' . $index, $index ), [] );
			}

			$incoming = [
				'backend' => 'lexical',
				'current' => $corpus[1],
				'related' => [ $corpus[3] ],
			];
			$result   = Story_RAG_Retrieval::retrieve(
				$incoming,
				$this->chunk( 'chapter-1', 1 ),
				$corpus,
				[ 'current_index' => 1, 'user_id' => 17 ]
			);

			$this->assertSame( 'wpvdb-private-vector', $result['backend'] );
			$this->assertSame( $corpus[1], $result['current'] );
			$this->assertSame( [ $corpus[0], $corpus[2], $corpus[3] ], $result['related'] );
			$this->assertCount( 3, $result['related'] );
			$this->assertStringNotContainsString( 'dimensions', (string) json_encode( $result ) );
			$this->assertStringNotContainsString( 'text-embedding-test', (string) json_encode( $result ) );
		}

		public function test_errors_bad_dimensions_and_scope_mismatches_preserve_lexical_result(): void {
			$evidence = $this->evidence( 'chapter-0', 0, 'First' );
			\WPVDB\Core::$responses[] = $this->vector( 1.0, 0.0 );
			Story_RAG_Retrieval::capture_evidence( $evidence, $this->chunk( 'chapter-0', 0 ), [] );

			$incoming = [ 'backend' => 'lexical', 'current' => $evidence, 'related' => [] ];
			\WPVDB\Core::$responses[] = array_fill( 0, 9, 1.0 );
			$this->assertSame(
				$incoming,
				Story_RAG_Retrieval::retrieve( $incoming, $this->chunk( 'chapter-0', 0 ), [ $evidence ], [ 'current_index' => 0, 'user_id' => 17 ] )
			);

			\WPVDB\Core::$responses[] = new \WP_Error( 'embedding_rate_limited' );
			$this->assertSame(
				$incoming,
				Story_RAG_Retrieval::retrieve( $incoming, $this->chunk( 'chapter-0', 0 ), [ $evidence ], [ 'current_index' => 0, 'user_id' => 17 ] )
			);

			$GLOBALS['worldgraph_story_rag_user_id'] = 18;
			$this->assertSame(
				$incoming,
				Story_RAG_Retrieval::retrieve( $incoming, $this->chunk( 'chapter-0', 0 ), [ $evidence ], [ 'current_index' => 0, 'user_id' => 18 ] )
			);

			$GLOBALS['worldgraph_story_rag_user_id'] = 17;
			$other_source                             = $this->chunk( 'chapter-0', 0 );
			$other_source['metadata']['source_hash']  = str_repeat( 'b', 64 );
			$this->assertSame( $incoming, Story_RAG_Retrieval::retrieve( $incoming, $other_source, [ $evidence ], [ 'current_index' => 0, 'user_id' => 17 ] ) );
		}

		public function test_nonfinite_and_oversized_vectors_are_never_stored(): void {
			$nonfinite    = $this->vector( 1.0, 0.0 );
			$nonfinite[3] = INF;
			\WPVDB\Core::$responses = [ $nonfinite, array_fill( 0, 4097, 1.0 ) ];

			Story_RAG_Retrieval::capture_evidence( $this->evidence( 'chapter-0', 0, 'First' ), $this->chunk( 'chapter-0', 0 ), [] );
			Story_RAG_Retrieval::capture_evidence( $this->evidence( 'chapter-1', 1, 'Second' ), $this->chunk( 'chapter-1', 1 ), [] );

			$this->assertSame( [], $GLOBALS['worldgraph_story_rag_transients'] );
		}

		public function test_cleanup_removes_the_scoped_index_and_vectors(): void {
			\WPVDB\Core::$responses = [ $this->vector( 1.0, 0.0 ), $this->vector( 0.0, 1.0 ) ];
			Story_RAG_Retrieval::capture_evidence( $this->evidence( 'chapter-0', 0, 'First' ), $this->chunk( 'chapter-0', 0 ), [] );
			Story_RAG_Retrieval::capture_evidence( $this->evidence( 'chapter-1', 1, 'Second' ), $this->chunk( 'chapter-1', 1 ), [] );
			$this->assertCount( 3, $GLOBALS['worldgraph_story_rag_transients'] );

			Story_RAG_Retrieval::cleanup( self::SOURCE_HASH, 17 );

			$this->assertSame( [], $GLOBALS['worldgraph_story_rag_transients'] );
		}

		/** Return an eight-dimensional vector with two controllable axes. */
		private function vector( float $x, float $y ): array {
			return [ $x, $y, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0 ];
		}

		/** Return one evidence object using the production retrieval identifier shape. */
		private function evidence( string $chunk_id, int $index, string $summary ): array {
			return [
				'worldgraph_version' => '1.2',
				'scenes'             => [ [ 'id' => 'scene-' . $index, 'summary' => $summary ] ],
				'_retrieval'         => [ 'chunk_id' => $chunk_id, 'chunk_index' => $index ],
			];
		}

		/** Return one server-created chunk descriptor. */
		private function chunk( string $chunk_id, int $index ): array {
			return [
				'id'       => $chunk_id,
				'index'    => $index,
				'label'    => 'Chapter ' . ( $index + 1 ),
				'text'     => 'Private manuscript query ' . $index,
				'metadata' => [ 'source_hash' => self::SOURCE_HASH ],
			];
		}
	}
}
