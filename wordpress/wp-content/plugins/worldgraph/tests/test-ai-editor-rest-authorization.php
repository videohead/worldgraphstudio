<?php
/**
 * Tests for AI Editor REST post-context authorization.
 *
 * @package WorldGraph
 */

namespace WorldGraph\AI {
	defined( 'ABSPATH' ) || exit;

	/** Capture AI route registration without loading WordPress. */
	function register_rest_route( string $namespace, string $route, array $args ): void {
		$GLOBALS['worldgraph_ai_test_routes'][ $namespace . $route ] = $args;
	}

	/** Object-capability shim scoped to the AI namespace. */
	function current_user_can( string $capability, ...$args ): bool {
		$key = $capability . ( empty( $args ) ? '' : ':' . implode( ':', array_map( 'strval', $args ) ) );
		return (bool) ( $GLOBALS['worldgraph_ai_test_capabilities'][ $key ] ?? false );
	}

	/** Post lookup shim scoped to the AI namespace. */
	function get_post( $post_id ) {
		return $GLOBALS['worldgraph_ai_test_posts'][ (int) $post_id ] ?? null;
	}

	/** Minimal text sanitizer used before handler authorization checks. */
	function sanitize_text_field( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test-only WordPress sanitizer shim.
		return trim( strip_tags( (string) $value ) );
	}

	/** Minimal positive-integer sanitizer for direct handler tests. */
	function absint( $value ): int {
		return abs( (int) $value );
	}

	/** Translation shim. */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	function __( $text, $domain = 'default' ): string {
		return (string) $text;
	}

	/** WordPress error predicate shim. */
	function is_wp_error( $value ): bool {
		return $value instanceof \WP_Error;
	}
}

namespace {

use PHPUnit\Framework\TestCase;
use WorldGraph\AI\AI_Editor_REST;

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/** Minimal REST request used by controller unit tests. */
	class WP_REST_Request {
		/** @var array<string, mixed> */
		private array $params;

		/** @param array<string, mixed> $params Request parameters. */
		public function __construct( array $params = [] ) {
			$this->params = $params;
		}

		/** Return one request parameter. */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/** @return array<string, mixed> All request parameters. */
		public function get_params(): array {
			return $this->params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/** Minimal REST response compatible with the other unit-test shims. */
	class WP_REST_Response {
		/** @var mixed */
		private $data;

		private int $status;

		/** @var array<string, mixed> */
		private array $links = [];

		/** @var array<string, mixed> */
		private array $headers = [];

		/** @param mixed $data Response data. */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/** @return mixed Response data. */
		public function get_data() {
			return $this->data;
		}

		/** Return the HTTP status. */
		public function get_status(): int {
			return $this->status;
		}

		/** @param array<string, mixed> $links Response links. */
		public function add_links( array $links ): void {
			$this->links = $links;
		}

		/** @return array<string, mixed> Response links. */
		public function get_links(): array {
			return $this->links;
		}

		/** Store one response header. */
		public function header( string $key, $value ): void {
			$this->headers[ $key ] = $value;
		}

		/** @return array<string, mixed> Response headers. */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal WordPress error object. */
	class WP_Error {
		private string $code;
		private string $message;

		/** @var mixed */
		private $data;

		/** @param mixed $data Error data. */
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/** Return the error code. */
		public function get_error_code(): string {
			return $this->code;
		}

		/** Return the error message. */
		public function get_error_message(): string {
			return $this->message;
		}

		/** @return mixed Error data. */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/** Minimal post object used for object-level capability checks. */
	class WP_Post {
		public int $ID;
		public string $post_type;
		public int $post_parent;

		public function __construct( int $id, string $post_type = 'post', int $post_parent = 0 ) {
			$this->ID          = $id;
			$this->post_type   = $post_type;
			$this->post_parent = $post_parent;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/ai-editor/class-ai-editor-rest.php';

/** AI Editor REST authorization regression tests. */
class Test_AI_Editor_REST_Authorization extends TestCase {

	protected function setUp(): void {
		$GLOBALS['worldgraph_ai_test_capabilities'] = [];
		$GLOBALS['worldgraph_ai_test_posts']        = [];
		$GLOBALS['worldgraph_ai_test_routes']       = [];
	}

	/** The affected routes use object-aware callbacks while chat remains unchanged. */
	public function test_routes_use_post_context_permission_callbacks(): void {
		$controller = new AI_Editor_REST();
		$controller->register_routes();

		$routes = $GLOBALS['worldgraph_ai_test_routes'];
		$this->assertSame( 'check_permission', $routes['worldgraph/v1/ai/chat']['permission_callback'][1] );
		$this->assertSame(
			'check_optional_edit_post_permission',
			$routes['worldgraph/v1/ai/analyze']['permission_callback'][1]
		);
		$this->assertSame(
			'check_optional_edit_post_permission',
			$routes['worldgraph/v1/ai/generate']['permission_callback'][1]
		);
		$this->assertSame( 'check_edit_post_permission', $routes['worldgraph/v1/ai/continuity']['permission_callback'][1] );
		$this->assertSame( 'check_read_post_permission', $routes['worldgraph/v1/ai/context']['permission_callback'][1] );
		$this->assertTrue( $routes['worldgraph/v1/ai/context']['args']['post_id']['required'] );
	}

	/** A Contributor-level user cannot use another private post as AI context. */
	public function test_object_capabilities_gate_edit_and_read_context(): void {
		$controller = new AI_Editor_REST();
		$this->grant( 'edit_posts' );
		$this->add_post( 99 );
		$request = new WP_REST_Request( [ 'post_id' => 99 ] );

		$edit_result = $controller->check_edit_post_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $edit_result );
		$this->assertSame( 'worldgraph_ai_post_forbidden', $edit_result->get_error_code() );

		$read_result = $controller->check_read_post_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $read_result );
		$this->assertSame( 'worldgraph_ai_post_forbidden', $read_result->get_error_code() );

		$this->grant( 'read_post', 99 );
		$this->assertTrue( $controller->check_read_post_permission( $request ) );
		$this->assertInstanceOf( WP_Error::class, $controller->check_edit_post_permission( $request ) );

		$this->grant( 'edit_post', 99 );
		$this->assertTrue( $controller->check_edit_post_permission( $request ) );
	}

	/** Missing, malformed, nonexistent, and non-post IDs fail closed. */
	public function test_invalid_post_context_fails_closed(): void {
		$controller = new AI_Editor_REST();
		$this->grant( 'edit_posts' );

		$this->assertTrue( $controller->check_optional_edit_post_permission( new WP_REST_Request() ) );
		$this->assertInvalidPostError( $controller->check_edit_post_permission( new WP_REST_Request() ) );
		$this->assertInvalidPostError(
			$controller->check_edit_post_permission( new WP_REST_Request( [ 'post_id' => -7 ] ) )
		);
		$this->assertInvalidPostError(
			$controller->check_edit_post_permission( new WP_REST_Request( [ 'post_id' => 404 ] ) )
		);

		$GLOBALS['worldgraph_ai_test_posts'][12] = (object) [ 'ID' => 12 ];
		$this->assertInvalidPostError(
			$controller->check_edit_post_permission( new WP_REST_Request( [ 'post_id' => 12 ] ) )
		);
	}

	/** Handler-level checks prevent context building even if a callback is bypassed. */
	public function test_handlers_reject_unauthorized_post_context_directly(): void {
		$controller = new AI_Editor_REST();
		$this->grant( 'edit_posts' );
		$this->add_post( 99 );

		$requests = [
			'analyze'          => [ 'prompt' => 'Analyze this.', 'post_id' => 99 ],
			'generate'         => [ 'prompt' => 'Generate this.', 'post_id' => 99 ],
			'continuity_check' => [ 'post_id' => 99 ],
			'get_context'      => [ 'post_id' => 99 ],
		];

		foreach ( $requests as $method => $params ) {
			$response = $controller->{$method}( new WP_REST_Request( $params ) );
			$this->assertSame( 403, $response->get_status(), $method );
			$this->assertFalse( $response->get_data()['success'], $method );
		}
	}

	/** Generic AI access remains required even when no optional post is supplied. */
	public function test_optional_context_still_requires_generic_ai_permission(): void {
		$controller = new AI_Editor_REST();

		$this->assertFalse( $controller->check_optional_edit_post_permission( new WP_REST_Request() ) );
	}

	/** Grant a generic or object-level capability. */
	private function grant( string $capability, ?int $post_id = null ): void {
		$key = $capability . ( null === $post_id ? '' : ':' . $post_id );
		$GLOBALS['worldgraph_ai_test_capabilities'][ $key ] = true;
	}

	/** Add a valid post to the test registry. */
	private function add_post( int $post_id ): void {
		$GLOBALS['worldgraph_ai_test_posts'][ $post_id ] = new WP_Post( $post_id );
	}

	/** Assert the controller's canonical invalid-post result. */
	private function assertInvalidPostError( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_ai_post_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}
}

}
