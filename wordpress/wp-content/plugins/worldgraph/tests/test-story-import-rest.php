<?php
/**
 * Resumable story decomposition REST contracts.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\REST\Import_Controller;

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	/** Minimal WordPress REST base for controller loading. */
	class WP_REST_Controller {}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/** Minimal parameter-backed REST request. */
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

		/** @return array<string, mixed> Route parameters. */
		public function get_url_params(): array {
			return $this->params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/** Minimal status/header-aware REST response. */
	class WP_REST_Response {
		/** @var mixed */
		private $data;

		private int $status;

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

		/** Return the response status. */
		public function get_status(): int {
			return $this->status;
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
	/** Minimal WordPress error value. */
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

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/** @return mixed Error data. */
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

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $value ): string {
		return basename( (string) $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): bool {
		$GLOBALS['worldgraph_rest_api_routes'][] = compact( 'namespace', 'route', 'args' );
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/rest-api/base-controller.php';
require_once dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-import-controller.php';

/** Isolate the HTTP contract from file extraction, Connections, and LLM calls. */
final class WorldGraph_Story_Import_REST_Test_Controller extends Import_Controller {
	/** @var array<string, mixed>|WP_Error */
	public $source;

	/** @var array<string, mixed>|null */
	public $connection;

	/** @var array<string, mixed>|WP_Error */
	public $job;

	/** @var array<string, mixed> */
	public array $start_arguments = [];

	/** @var array<string, mixed> */
	public array $pending_marker = [];

	public int $decomposer_requests = 0;

	protected function extract_story_source( int $attachment_id ) {
		return $this->source;
	}

	protected function current_user_can_manage_decomposition_connection( int $connection_id ): bool {
		return true;
	}

	protected function decomposition_connection_record( int $connection_id ): ?array {
		return $this->connection;
	}

	protected function default_decomposition_connection_id(): int {
		return 37;
	}

	protected function start_decomposition_job(
		array $source,
		int $attachment_id,
		int $connection_id,
		bool $overwrite,
		string $connection_name
	) {
		$this->start_arguments = compact( 'source', 'attachment_id', 'connection_id', 'overwrite', 'connection_name' );
		return $this->job;
	}

	protected function mark_decomposition_job_pending( int $attachment_id, string $job_id ): void {
		$this->pending_marker = [
			'attachment_id' => $attachment_id,
			'fingerprint'   => substr( hash( 'sha256', $job_id ), 0, 24 ),
		];
	}

	protected function story_decomposer(): \WorldGraphStoryIO\Story_Decomposer {
		++$this->decomposer_requests;
		throw new RuntimeException( 'A long synchronous request must stop before constructing the decomposer.' );
	}
}

/** Resumable creation is discoverable, private, bounded, and asynchronous. */
final class Test_Story_Import_REST extends TestCase {
	private const JOB_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	protected function setUp(): void {
		$GLOBALS['worldgraph_rest_api_routes'] = [];
	}

	/** The collection route exposes create separately from status/step/cancel. */
	public function test_resumable_collection_route_is_registered(): void {
		$controller = $this->controller();
		$controller->register_routes();

		$route = $this->route( '/import/decompositions' );
		$this->assertSame( 'POST', $route['methods'] );
		$this->assertSame( 'create_decomposition_job', $route['callback'][1] );
		$this->assertSame( 'check_decomposition_permission', $route['permission_callback'][1] );
		$this->assertTrue( $route['args']['attachment_id']['required'] );
		$this->assertFalse( $route['args']['overwrite']['default'] );

		$item = $this->route_operations( '/import/decompositions/(?P<job_id>[A-Za-z0-9_-]{32,86})' );
		$this->assertSame( [ 'GET', 'POST', 'DELETE' ], array_column( $item, 'methods' ) );
		$this->assertSame(
			[ 'get_decomposition_job', 'step_decomposition_job', 'cancel_decomposition_job' ],
			array_map( static fn( array $operation ): string => (string) $operation['callback'][1], $item )
		);
	}

	/** Creation returns 202 + Location and strips every non-status job field. */
	public function test_create_returns_private_accepted_job_projection(): void {
		$controller       = $this->controller();
		$controller->job = [
			'job_id'       => self::JOB_ID,
			'status'       => 'ready',
			'stage'        => 'analysis',
			'stage_label'  => 'Analyzing story evidence',
			'section'      => 'Analysis section 1 of 4.',
			'progress'     => [ 'completed' => 0, 'total' => 9, 'percent' => 0 ],
			'analysis'     => [ 'completed' => 0, 'total' => 4 ],
			'synthesis'    => [ 'completed' => 0, 'total' => 4 ],
			'can_step'     => true,
			'can_cancel'   => true,
			'expires_at'   => 1_800_000_000,
			'source_text'  => 'private manuscript text',
			'profile'      => [ 'credential_reference' => 'never-return-this' ],
		];

		$response = $controller->create_decomposition_job(
			new WP_REST_Request(
				[
					'attachment_id' => 91,
					'connection_id' => 37,
					'overwrite'     => true,
				]
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		if ( method_exists( $response, 'get_status' ) ) {
			$this->assertSame( 202, $response->get_status() );
		}
		$this->assertSame(
			'https://example.test/wp-json/worldgraph/v1/import/decompositions/' . self::JOB_ID,
			$response->get_headers()['Location']
		);
		$this->assertSame( 'no-store, no-cache, must-revalidate, max-age=0', $response->get_headers()['Cache-Control'] );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( self::JOB_ID, $data['job']['job_id'] );
		$this->assertArrayNotHasKey( 'source_text', $data['job'] );
		$this->assertArrayNotHasKey( 'profile', $data['job'] );
		$this->assertSame( 91, $controller->start_arguments['attachment_id'] );
		$this->assertSame( 37, $controller->start_arguments['connection_id'] );
		$this->assertTrue( $controller->start_arguments['overwrite'] );
		$this->assertSame( 'Fixture LLM', $controller->start_arguments['connection_name'] );
		$this->assertSame(
			[
				'attachment_id' => 91,
				'fingerprint'   => substr( hash( 'sha256', self::JOB_ID ), 0, 24 ),
			],
			$controller->pending_marker
		);

		$source = (string) file_get_contents(
			dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-import-controller.php'
		);
		$this->assertStringContainsString( "'_worldgraph_story_preview_fingerprint'", $source );
		$this->assertStringContainsString( "substr( hash( 'sha256', \$job_id ), 0, 24 )", $source );
		$this->assertStringNotContainsString( "'_worldgraph_story_import_job'", $source );
	}

	/** Canonical JSON stays on validation/import paths instead of creating a fake job. */
	public function test_canonical_json_is_rejected_from_job_creation(): void {
		$controller               = $this->controller();
		$controller->source['is_json'] = true;
		$result                   = $controller->create_decomposition_job(
			new WP_REST_Request( [ 'attachment_id' => 91, 'connection_id' => 0 ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_story_decomposition_not_required', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( [], $controller->start_arguments );
	}

	/** A disappeared Connection fails closed without indexing a null record. */
	public function test_missing_connection_is_rejected_safely(): void {
		$controller             = $this->controller();
		$controller->connection = null;

		$result = $controller->create_decomposition_job(
			new WP_REST_Request( [ 'attachment_id' => 91, 'connection_id' => 37 ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_story_connection_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( [], $controller->start_arguments );
	}

	/** Long non-canonical work cannot execute through the synchronous route. */
	public function test_long_synchronous_preview_requires_a_resumable_job(): void {
		$controller                      = $this->controller();
		$controller->source['text']       = str_repeat( 'x', 1_401 );
		$controller->source['characters'] = 0;

		$result = $controller->decompose_story(
			new WP_REST_Request( [ 'attachment_id' => 91, 'connection_id' => 37 ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_story_decomposition_job_required', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 0, $controller->decomposer_requests );
	}

	/** Build a controller with one authorized, non-canonical source fixture. */
	private function controller(): WorldGraph_Story_Import_REST_Test_Controller {
		$controller             = new WorldGraph_Story_Import_REST_Test_Controller();
		$controller->source     = [
			'text'       => 'CHAPTER ONE. A traveler crossed the bridge.',
			'filename'   => 'fixture-story.txt',
			'format'     => 'txt',
			'characters' => 47,
			'boundaries' => [],
			'is_json'    => false,
		];
		$controller->connection = [
			'connection_name' => 'Fixture LLM',
			'provider_type'    => 'openai_compatible',
			'status_wp'        => 'publish',
			'status'           => 'verified',
			'endpoint_url'     => 'https://llm.example/v1',
			'model'            => 'fixture-model',
		];
		$controller->job        = new WP_Error( 'unconfigured_job', 'The test must configure a job result.' );
		return $controller;
	}

	/** Return the first operation registered for one exact route. */
	private function route( string $path ): array {
		$operations = $this->route_operations( $path );
		return (array) ( $operations[0] ?? [] );
	}

	/** Return every operation registered for one exact route. */
	private function route_operations( string $path ): array {
		foreach ( (array) $GLOBALS['worldgraph_rest_api_routes'] as $route ) {
			if ( 'worldgraph/v1' === (string) ( $route['namespace'] ?? '' ) && $path === (string) ( $route['route'] ?? '' ) ) {
				return array_values( (array) ( $route['args'] ?? [] ) );
			}
		}
		$this->fail( 'Expected REST route was not registered: ' . $path );
	}
}
