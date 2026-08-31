<?php
/**
 * Seedance 2.5 via CyberBara REST and Template contracts.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Connection_Adapters;
use WorldGraph\Utils\Credential_Store;
use WorldGraph\Utils\Generation_Modality;
use WorldGraph\Utils\Seedance_25_API;
use WorldGraph\Utils\Seedance_25_Catalog;

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal WordPress error value for the WordPress-free unit bootstrap. */
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

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) {
		return false !== filter_var( $url, FILTER_VALIDATE_URL ) ? (string) $url : false;
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

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ): string {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_safe_remote_request' ) ) {
	function wp_safe_remote_request( $url, $args ) {
		$GLOBALS['worldgraph_seedance_http_requests'][] = [ 'url' => $url, 'args' => $args ];
		return $GLOBALS['worldgraph_seedance_http_response'] ?? new WP_Error( 'http_request_failed', 'No mock HTTP response configured.' );
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id, $unfiltered = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $GLOBALS['worldgraph_seedance_attachment_paths'][ (int) $attachment_id ] ?? false;
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $attachment_id ) {
		return $GLOBALS['worldgraph_seedance_attachment_mimes'][ (int) $attachment_id ] ?? '';
	}
}

if ( ! function_exists( 'wp_get_image_mime' ) ) {
	function wp_get_image_mime( $file ) {
		$details = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test shim for malformed-file fixtures.
		return is_array( $details ) ? ( $details['mime'] ?? false ) : false;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		$bytes     = random_bytes( 16 );
		$bytes[6]  = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8]  = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex       = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $value ): string {
		return basename( (string) $value );
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook_name ) {
		return 'worldgraph_generation_background_media_authorization' === $hook_name
			&& ! empty( $GLOBALS['worldgraph_seedance_authorization_filter'] );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/class-credential-store.php';
require_once dirname( __DIR__ ) . '/includes/utils/connection-adapters.php';
require_once dirname( __DIR__ ) . '/includes/utils/generation-modality.php';
require_once dirname( __DIR__ ) . '/includes/utils/seedance-25-api.php';
require_once dirname( __DIR__ ) . '/includes/utils/seedance-25-catalog.php';

/** Network-free test double that records the exact CyberBara transport. */
class Seedance_25_API_Test_Double extends Seedance_25_API {

	/** @var array<string, mixed> */
	public static array $connection_record = [];

	/** @var array<int, array<string, mixed>|WP_Error> */
	public static array $responses = [];

	/** @var array<int, array<string, mixed>> */
	public static array $requests = [];

	/** @var array<int, array<string, mixed>> */
	public static array $media_resolutions = [];

	/** @var array<int, array<string, mixed>> */
	public static array $multipart_requests = [];

	/** Restore a deterministic test fixture. */
	public static function reset(): void {
		self::$connection_record = [
			'provider_type'       => 'seedance_25',
			'status_wp'           => 'publish',
			'status'              => 'verified',
			'endpoint_url'        => self::ENDPOINT,
			'credential_reference' => 'cyberbara-test-key',
			'model_access'        => '',
		];
		self::$responses        = [];
		self::$requests         = [];
		self::$media_resolutions = [];
		self::$multipart_requests = [];
	}

	/** Expose strict option normalization for focused tests. */
	public static function options_for_test( array $options ) {
		return parent::normalize_options( $options );
	}

	/** Expose fixed-origin validation for focused tests. */
	public static function endpoint_for_test( string $endpoint ) {
		return parent::validated_endpoint( $endpoint );
	}

	/** Expose credential/header validation for focused tests. */
	public static function headers_for_test( string $reference ) {
		return parent::authorization_headers( $reference );
	}

	/** Expose the real public-URL branch of media resolution. */
	public static function public_media_for_test( $value ) {
		return parent::resolve_media_input( $value, 7, 11 );
	}

	/** Expose the real bounded attachment upload implementation. */
	public static function upload_for_test( int $attachment_id, int $connection_id ) {
		return parent::upload_attachment( $attachment_id, $connection_id );
	}

	/** Supply a reviewed saved Connection without WordPress persistence. */
	protected static function connection( int $connection_id ) {
		unset( $connection_id );
		return self::$connection_record;
	}

	/** Record one JSON request and return the next queued provider response. */
	protected static function json_request( string $method, string $url, array $headers, ?array $body = null, string $context = 'request' ) {
		self::$requests[] = compact( 'method', 'url', 'headers', 'body', 'context' );
		return array_shift( self::$responses ) ?? new WP_Error( 'seedance_test_response_missing', 'No mock response was queued.' );
	}

	/** Model a successful bounded attachment upload while preserving its context. */
	protected static function resolve_media_input( $value, int $connection_id, int $job_id ) {
		self::$media_resolutions[] = compact( 'value', 'connection_id', 'job_id' );
		return 'https://cdn.example.test/reference.png';
	}

	/** Capture a fully framed multipart upload without external traffic. */
	protected static function multipart_request( string $url, array $headers, string $body, string $content_type ) {
		self::$multipart_requests[] = compact( 'url', 'headers', 'body', 'content_type' );
		return [ 'data' => [ 'urls' => [ 'https://cdn.example.test/uploaded-reference.png' ] ] ];
	}
}

/** Exposes the concrete bounded WordPress HTTP transport to mocked responses. */
class Seedance_25_API_Concrete_Transport extends Seedance_25_API {

	/** Execute the parent JSON transport with no network access. */
	public static function request_for_test( string $method, string $url, array $headers, ?array $body, string $context ) {
		return parent::json_request( $method, $url, $headers, $body, $context );
	}
}

/** Focused, network-free Seedance 2.5 adapter tests. */
class Test_Seedance_25 extends TestCase {

	/** Test environment variable name. */
	private const ENV_KEY = 'WORLDGRAPH_SEEDANCE_25_TEST_KEY';

	/** Stable UUID-shaped provider task fixtures. */
	private const TASK_TEXT  = '11111111-1111-4111-8111-111111111111';
	private const TASK_IMAGE = '22222222-2222-4222-8222-222222222222';
	private const TASK_POLL  = '33333333-3333-4333-8333-333333333333';
	private const TASK_OTHER = '44444444-4444-4444-8444-444444444444';
	private const TASK_EMPTY = '55555555-5555-4555-8555-555555555555';
	private const TASK_FAIL  = '66666666-6666-4666-8666-666666666666';

	protected function setUp(): void {
		parent::setUp();
		Seedance_25_API_Test_Double::reset();
		putenv( self::ENV_KEY );
		$GLOBALS['worldgraph_seedance_http_requests'] = [];
		$GLOBALS['worldgraph_seedance_attachment_paths'] = [];
		$GLOBALS['worldgraph_seedance_attachment_mimes'] = [];
		$GLOBALS['worldgraph_seedance_temp_files'] = [];
		$GLOBALS['worldgraph_seedance_authorization_filter'] = false;
		unset( $GLOBALS['worldgraph_seedance_http_response'] );
	}

	protected function tearDown(): void {
		putenv( self::ENV_KEY );
		foreach ( (array) ( $GLOBALS['worldgraph_seedance_temp_files'] ?? [] ) as $path ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test-owned temporary fixture.
			}
		}
		unset(
			$GLOBALS['worldgraph_seedance_http_requests'],
			$GLOBALS['worldgraph_seedance_http_response'],
			$GLOBALS['worldgraph_seedance_attachment_paths'],
			$GLOBALS['worldgraph_seedance_attachment_mimes'],
			$GLOBALS['worldgraph_seedance_temp_files'],
			$GLOBALS['worldgraph_seedance_authorization_filter']
		);
		parent::tearDown();
	}

	/** Read one plugin source file as a structural contract fixture. */
	private function source( string $relative_path ): string {
		$path = dirname( __DIR__ ) . '/' . ltrim( $relative_path, '/' );
		$this->assertFileExists( $path, "Missing Seedance source: {$relative_path}" );
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source, "Unreadable Seedance source: {$relative_path}" );

		return (string) $source;
	}

	/** The manifest exposes one manual, async, media-capable REST Connection. */
	public function test_manifest_exposes_manual_seedance_connection(): void {
		$adapter = Connection_Adapters::get( 'seedance_25' );

		$this->assertIsArray( $adapter );
		$this->assertSame( 'Seedance 2.5 via CyberBara', $adapter['label'] );
		$this->assertSame( Seedance_25_API::ENDPOINT, Connection_Adapters::endpoint( 'seedance_25' ) );
		$this->assertSame(
			[ 'includes/utils/seedance-25-api.php', 'includes/utils/seedance-25-catalog.php' ],
			$adapter['files']
		);
		$this->assertArrayNotHasKey( 'setup_options', $adapter );
		$this->assertNull( Connection_Adapters::setup_choice( 'seedance_25' ) );
		$this->assertSame( Seedance_25_API::class, $adapter['generation']['client'] );
		$this->assertSame( 'seedance_25_api', $adapter['generation']['adapter'] );
		$this->assertTrue( $adapter['generation']['poll'] );
		$this->assertTrue( $adapter['generation']['poll_with_template'] );
		$this->assertTrue( $adapter['generation']['media_inputs'] );
		$this->assertContains( 'seedance_25_api_job_id_mismatch', $adapter['generation']['permanent_error_codes'] );
		$this->assertContains( 'seedance_25_api_task_contract_mismatch', $adapter['generation']['permanent_error_codes'] );
		$this->assertContains( 'seedance_25_api_output_contract_invalid', $adapter['generation']['permanent_error_codes'] );
		$this->assertContains( 'seedance_25_api_output_missing', $adapter['generation']['permanent_error_codes'] );
	}

	/** Fixed Template identities, modalities, controls, and API operations agree. */
	public function test_catalog_definitions_match_reviewed_operations(): void {
		$definitions = Seedance_25_Catalog::definitions();

		$this->assertSame( array_keys( Seedance_25_API::OPERATIONS ), array_column( $definitions, 'reference' ) );
		$this->assertSame(
			[ Generation_Modality::TEXT_TO_VIDEO, Generation_Modality::TEXT_IMAGE_TO_VIDEO ],
			array_column( $definitions, 'modality' )
		);
		$this->assertSame(
			[ 'duration' => 10, 'resolution' => '720p', 'aspect_ratio' => '16:9' ],
			$definitions[0]['input']
		);
		$this->assertSame( $definitions[0]['input'], $definitions[1]['input'] );
		$this->assertSame( [ 'prompt' ], $definitions[0]['schema']['required'] );
		$this->assertSame( [ 'prompt', 'image_input' ], $definitions[1]['schema']['required'] );
		$this->assertSame( 1, $definitions[1]['schema']['properties']['image_input']['maxItems'] );
		$this->assertSame( 'image', $definitions[1]['schema']['properties']['image_input']['input_slot'] );
		$this->assertSame( 'worldgraph', $definitions[1]['schema']['properties']['image_input']['managed_by'] );
	}

	/** Optional Model Access JSON can only narrow the fixed operation set. */
	public function test_operation_allowlists_fail_closed(): void {
		$text  = 'seedance-2.5:text-to-video';
		$image = 'seedance-2.5:image-to-video';

		$this->assertTrue( Seedance_25_API::operation_is_allowed( [ 'model_access' => '' ], $text ) );
		$this->assertFalse( Seedance_25_API::operation_is_allowed( [ 'model_access' => '' ], 'seedance-2.5:video-to-video' ) );
		$this->assertTrue( Seedance_25_API::operation_is_allowed( [ 'model_access' => '["' . $text . '"]' ], $text ) );
		$this->assertFalse( Seedance_25_API::operation_is_allowed( [ 'model_access' => '["' . $text . '"]' ], $image ) );
		$this->assertFalse( Seedance_25_API::operation_is_allowed( [ 'model_access' => 'not-json' ], $text ) );
		$this->assertFalse( Seedance_25_API::operation_is_allowed( [ 'model_access' => '{"selected":"' . $text . '"}' ], $text ) );
		$this->assertFalse( Seedance_25_API::operation_is_allowed( [ 'model_access' => '["' . $text . '","seedance-2.5:video-to-video"]' ], $text ) );
		$this->assertFalse( Seedance_25_API::operation_is_allowed( [ 'model_access' => '["' . $text . '",7]' ], $text ) );
	}

	/** Only documented, strictly bounded video controls survive normalization. */
	public function test_generation_options_are_strict_and_bounded(): void {
		$this->assertSame(
			[ 'duration' => 30, 'resolution' => '720p', 'aspect_ratio' => '9:16' ],
			Seedance_25_API_Test_Double::options_for_test( [ 'duration' => '30', 'resolution' => '720p', 'aspect_ratio' => '9:16', 'unreviewed' => true ] )
		);

		foreach (
			[
				[ 'duration' => 3 ],
				[ 'duration' => 31 ],
				[ 'duration' => 4.5 ],
				[ 'duration' => '4.0' ],
				[ 'duration' => true ],
				[ 'resolution' => '1080p' ],
				[ 'resolution' => 720 ],
				[ 'aspect_ratio' => '2:1' ],
				[ 'aspect_ratio' => [ '16:9' ] ],
			] as $options
		) {
			$this->assertInstanceOf( WP_Error::class, Seedance_25_API_Test_Double::options_for_test( $options ) );
		}
	}

	/** The configurable-looking endpoint remains pinned to CyberBara production. */
	public function test_endpoint_and_credentials_are_strict(): void {
		$this->assertSame( Seedance_25_API::ENDPOINT, Seedance_25_API_Test_Double::endpoint_for_test( '' ) );
		$this->assertSame( Seedance_25_API::ENDPOINT, Seedance_25_API_Test_Double::endpoint_for_test( 'https://cyberbara.com/' ) );
		foreach ( [ 'http://cyberbara.com', 'https://api.cyberbara.com', 'https://cyberbara.com/api/v1', 'https://user@cyberbara.com', 'https://cyberbara.com?next=evil' ] as $endpoint ) {
			$this->assertInstanceOf( WP_Error::class, Seedance_25_API_Test_Double::endpoint_for_test( $endpoint ) );
		}

		$headers = Seedance_25_API_Test_Double::headers_for_test( 'literal-secret' );
		$this->assertSame( 'Bearer literal-secret', $headers['Authorization'] );
		$this->assertArrayNotHasKey( 'x-api-key', $headers );

		putenv( self::ENV_KEY . '=environment-secret' );
		$headers = Seedance_25_API_Test_Double::headers_for_test( 'env://' . self::ENV_KEY );
		$this->assertSame( 'Bearer environment-secret', $headers['Authorization'] );
		$this->assertInstanceOf( WP_Error::class, Seedance_25_API_Test_Double::headers_for_test( 'env://lowercase' ) );
		$this->assertInstanceOf( WP_Error::class, Seedance_25_API_Test_Double::headers_for_test( "bad\r\nheader" ) );
		putenv( self::ENV_KEY );
		$this->assertSame( 'seedance_25_api_credential_missing', Seedance_25_API_Test_Double::headers_for_test( 'env://' . self::ENV_KEY )->get_error_code() );
	}

	/** Discovery accepts the documented model envelope and bounds its catalog. */
	public function test_video_model_discovery_and_scene_selection(): void {
		Seedance_25_API_Test_Double::$responses[] = [
			'data' => [
				'models' => [
					[
					'model'            => 'seedance-2.5',
					'media_type'       => 'video',
					'supported_scenes' => [ 'text-to-video', 'image-to-video', 'unknown-scene' ],
					],
					[
					'model'            => 'seedance-2.5-stable',
					'media_type'       => 'video',
					'supported_scenes' => [ 'text-to-video' ],
					],
				],
			],
		];

		$models = Seedance_25_API_Test_Double::test_configuration( Seedance_25_API::ENDPOINT, 'test-key' );
		$this->assertCount( 2, $models );
		$this->assertSame( [ 'text-to-video', 'image-to-video' ], Seedance_25_API::model_scenes( $models ) );
		$this->assertSame( 'GET', Seedance_25_API_Test_Double::$requests[0]['method'] );
		$this->assertSame( 'https://cyberbara.com/api/v1/models?media_type=video', Seedance_25_API_Test_Double::$requests[0]['url'] );
		$this->assertSame( 'Bearer test-key', Seedance_25_API_Test_Double::$requests[0]['headers']['Authorization'] );
		$this->assertNull( Seedance_25_API_Test_Double::$requests[0]['body'] );
	}

	/** Malformed, duplicate, and oversized model catalogs never verify readiness. */
	public function test_video_model_discovery_fails_closed(): void {
		$valid = [
			'model'            => 'seedance-2.5',
			'media_type'       => 'video',
			'supported_scenes' => [ 'text-to-video', 'image-to-video' ],
		];
		$catalogs = [
			[ array_merge( $valid, [ 'media_type' => 'image' ] ) ],
			[ array_merge( $valid, [ 'supported_scenes' => 'text-to-video' ] ) ],
			[ $valid, $valid ],
			array_fill( 0, Seedance_25_API::MAX_MODEL_ITEMS + 1, $valid ),
			[ array_merge( $valid, [ 'model' => [ 'seedance-2.5' ] ] ) ],
		];

		foreach ( $catalogs as $models ) {
			Seedance_25_API_Test_Double::$responses[] = [ 'data' => [ 'models' => $models ] ];
			$error = Seedance_25_API_Test_Double::test_configuration( Seedance_25_API::ENDPOINT, 'test-key' );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( 'seedance_25_api_models_invalid', $error->get_error_code() );
		}

		$this->assertSame( [], Seedance_25_API::model_scenes( [ array_merge( $valid, [ 'media_type' => '' ] ) ] ) );
	}

	/** Text-to-video submission sends exactly the reviewed CyberBara payload. */
	public function test_text_to_video_submission_contract(): void {
		Seedance_25_API_Test_Double::$responses[] = [
			'data' => [
				'task_id'   => self::TASK_TEXT,
				'status'    => 'pending',
				'media_type' => 'video',
				'model'     => 'seedance-2.5',
				'scene'     => 'text-to-video',
			],
		];

		$result = Seedance_25_API_Test_Double::run_template(
			'seedance-2.5:text-to-video',
			'<b>A drifting paper city</b>',
			[
				'duration'     => 10,
				'resolution'   => '720p',
				'aspect_ratio' => '16:9',
				'model'        => 'attacker-owned-model',
				'scene'        => 'video-to-video',
				'options'      => [ 'unreviewed' => true ],
			],
			42
		);

		$this->assertSame( self::TASK_TEXT, $result['job_id'] );
		$this->assertSame( 'submitted', $result['status'] );
		$this->assertSame( 'api', $result['transport'] );
		$this->assertCount( 1, Seedance_25_API_Test_Double::$requests );
		$request = Seedance_25_API_Test_Double::$requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://cyberbara.com/api/v1/videos/generations', $request['url'] );
		$this->assertSame( 'Bearer cyberbara-test-key', $request['headers']['Authorization'] );
		$this->assertSame(
			[
				'model'   => 'seedance-2.5',
				'scene'   => 'text-to-video',
				'prompt'  => 'A drifting paper city',
				'options' => [ 'duration' => 10, 'resolution' => '720p', 'aspect_ratio' => '16:9' ],
			],
			$request['body']
		);
	}

	/** Image-to-video binds one authorized media input into options.image_input. */
	public function test_image_to_video_submission_contract(): void {
		Seedance_25_API_Test_Double::$responses[] = [
			'data' => [
				'task_id'   => self::TASK_IMAGE,
				'status'    => 'processing',
				'media_type' => 'video',
				'model'     => 'seedance-2.5',
				'scene'     => 'image-to-video',
			],
		];

		$result = Seedance_25_API_Test_Double::run_template(
			'seedance-2.5:image-to-video',
			'Animate the painted clouds',
			[
				'duration'           => 8,
				'inputs'             => [ 'image' => 321 ],
				'_worldgraph_job_id' => 654,
			],
			42
		);

		$this->assertSame( self::TASK_IMAGE, $result['job_id'] );
		$this->assertSame( [ [ 'value' => 321, 'connection_id' => 42, 'job_id' => 654 ] ], Seedance_25_API_Test_Double::$media_resolutions );
		$this->assertSame(
			[ 'duration' => 8, 'image_input' => [ 'https://cdn.example.test/reference.png' ] ],
			Seedance_25_API_Test_Double::$requests[0]['body']['options']
		);

		$missing = Seedance_25_API_Test_Double::run_template( 'seedance-2.5:image-to-video', 'Move', [], 42 );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'seedance_25_api_image_missing', $missing->get_error_code() );
	}

	/** Polling correlates the requested task and retains every safe video URL. */
	public function test_poll_contract_and_output_normalization(): void {
		Seedance_25_API_Test_Double::$responses[] = [
			'data' => [
				'task' => [
					'id'         => self::TASK_POLL,
					'status'     => 'success',
					'media_type' => 'video',
					'model'      => 'seedance-2.5',
					'scene'      => 'text-to-video',
					'output'  => [
						'videos' => [
							'https://cdn.example.test/a.mp4',
							'https://cdn.example.test/b.mp4',
							'https://cdn.example.test/a.mp4',
						],
					],
				],
			],
		];

		$result = Seedance_25_API_Test_Double::get_job_status( self::TASK_POLL, 42, 'seedance-2.5:text-to-video' );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( [ 'video', 'video' ], array_column( $result['output_media'], 'kind' ) );
		$this->assertSame(
			[ 'https://cdn.example.test/a.mp4', 'https://cdn.example.test/b.mp4' ],
			array_column( $result['output_media'], 'url' )
		);
		$this->assertSame( 'GET', Seedance_25_API_Test_Double::$requests[0]['method'] );
		$this->assertSame( 'https://cyberbara.com/api/v1/tasks/' . self::TASK_POLL, Seedance_25_API_Test_Double::$requests[0]['url'] );

		Seedance_25_API_Test_Double::$responses[] = [
			'data' => [
				'task' => [
					'id'         => self::TASK_OTHER,
					'status'     => 'processing',
					'media_type' => 'video',
					'model'      => 'seedance-2.5',
					'scene'      => 'text-to-video',
				],
			],
		];
		$mismatch = Seedance_25_API_Test_Double::get_job_status( self::TASK_POLL, 42, 'seedance-2.5:text-to-video' );
		$this->assertInstanceOf( WP_Error::class, $mismatch );
		$this->assertSame( 'seedance_25_api_job_id_mismatch', $mismatch->get_error_code() );
	}

	/** Final outputs are all-or-nothing, bounded, string-only HTTPS URLs. */
	public function test_completed_output_contract_fails_closed(): void {
		$operation = Seedance_25_API::OPERATIONS['seedance-2.5:text-to-video'];
		$task      = [
			'id'         => self::TASK_POLL,
			'status'     => 'success',
			'media_type' => 'video',
			'model'      => 'seedance-2.5',
			'scene'      => 'text-to-video',
		];
		$invalid_lists = [
			[ 'https://cdn.example.test/valid.mp4', 'http://cdn.example.test/unsafe.mp4' ],
			[ [ 'url' => 'https://cdn.example.test/not-a-string.mp4' ] ],
			[ 'named' => 'https://cdn.example.test/not-a-list.mp4' ],
			array_map(
				static fn ( int $index ): string => 'https://cdn.example.test/output-' . $index . '.mp4',
				range( 1, Seedance_25_API::MAX_OUTPUT_URLS + 1 )
			),
			[ 'https://cdn.example.test/' . str_repeat( 'x', Seedance_25_API::MAX_URL_BYTES ) ],
		];

		foreach ( $invalid_lists as $videos ) {
			$payload = [ 'data' => [ 'task' => array_merge( $task, [ 'output' => [ 'videos' => $videos ] ] ) ] ];
			$error   = Seedance_25_API::normalize_result( $payload, $operation );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( 'seedance_25_api_output_contract_invalid', $error->get_error_code() );
		}
	}

	/** Polling rejects malformed status, operation metadata, and local task IDs. */
	public function test_poll_task_contract_fails_closed(): void {
		Seedance_25_API_Test_Double::$responses[] = [
			'data' => [
				'task' => [
					'id'         => self::TASK_POLL,
					'status'     => 'processing',
					'media_type' => 'video',
					'model'      => 'seedance-2.5-stable',
					'scene'      => 'text-to-video',
				],
			],
		];
		$mismatch = Seedance_25_API_Test_Double::get_job_status( self::TASK_POLL, 42, 'seedance-2.5:text-to-video' );
		$this->assertSame( 'seedance_25_api_task_contract_mismatch', $mismatch->get_error_code() );

		Seedance_25_API_Test_Double::$responses[] = [
			'data' => [
				'task' => [
					'id'         => self::TASK_POLL,
					'status'     => 'complete',
					'media_type' => 'video',
					'model'      => 'seedance-2.5',
					'scene'      => 'text-to-video',
				],
			],
		];
		$status = Seedance_25_API_Test_Double::get_job_status( self::TASK_POLL, 42, 'seedance-2.5:text-to-video' );
		$this->assertSame( 'seedance_25_api_status_invalid', $status->get_error_code() );

		$before  = count( Seedance_25_API_Test_Double::$requests );
		$invalid = Seedance_25_API_Test_Double::get_job_status( 'not-a-provider-uuid', 42, 'seedance-2.5:text-to-video' );
		$this->assertSame( 'seedance_25_api_job_id_invalid', $invalid->get_error_code() );
		$this->assertCount( $before, Seedance_25_API_Test_Double::$requests );

		$operation = Seedance_25_API_Test_Double::get_job_status( self::TASK_POLL, 42, 'seedance-2.5:video-to-video' );
		$this->assertSame( 'seedance_25_api_operation_not_allowed', $operation->get_error_code() );
	}

	/** Every documented provider task state maps to the worker vocabulary. */
	public function test_status_and_failure_normalization(): void {
		foreach ( [ 'pending', 'processing' ] as $status ) {
			$this->assertSame( 'submitted', Seedance_25_API::normalize_status( $status ) );
		}
		$this->assertSame( 'completed', Seedance_25_API::normalize_status( 'success' ) );
		$this->assertSame( 'failed', Seedance_25_API::normalize_status( 'failed' ) );
		$this->assertSame( 'cancelled', Seedance_25_API::normalize_status( 'canceled' ) );
		foreach ( [ '', 'completed', 'error', 'cancelled', 'PENDING', 'unknown' ] as $status ) {
			$this->assertInstanceOf( WP_Error::class, Seedance_25_API::normalize_status( $status ) );
		}

		$result = Seedance_25_API::normalize_result(
			[
				'data' => [
					'task' => [
						'id'      => self::TASK_FAIL,
						'status'  => 'failed',
						'error'   => [ 'message' => '<b>Provider rejected the input.</b>', 'raw_message' => 'must not survive' ],
					],
				],
			]
		);
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'CyberBara reported that the Seedance task failed or was canceled.', $result['error'] );
		$this->assertStringNotContainsString( 'raw_message', wp_json_encode( $result ) );
	}

	/** A malformed accepted task ID is never persisted as a remote identity. */
	public function test_submission_rejects_unusable_remote_task_identity(): void {
		Seedance_25_API_Test_Double::$responses[] = [
			'data' => [
				'task_id'   => str_repeat( 'a', 5000 ),
				'status'    => 'pending',
				'media_type' => 'video',
				'model'     => 'seedance-2.5',
				'scene'     => 'text-to-video',
			],
		];

		$error = Seedance_25_API_Test_Double::run_template( 'seedance-2.5:text-to-video', 'Do not persist this ID', [], 42 );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'seedance_25_api_submit_ambiguous', $error->get_error_code() );
		$this->assertStringNotContainsString( str_repeat( 'a', 100 ), $error->get_error_message() );
	}

	/** Completed tasks without usable videos fail instead of silently succeeding. */
	public function test_completed_task_requires_a_safe_video_output(): void {
		Seedance_25_API_Test_Double::$responses[] = [
			'data' => [
				'task' => [
					'id'         => self::TASK_EMPTY,
					'status'     => 'success',
					'media_type' => 'video',
					'model'      => 'seedance-2.5',
					'scene'      => 'text-to-video',
					'output'     => [ 'videos' => [] ],
				],
			],
		];
		$error = Seedance_25_API_Test_Double::get_job_status( self::TASK_EMPTY, 42, 'seedance-2.5:text-to-video' );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'seedance_25_api_output_missing', $error->get_error_code() );
	}

	/** Provider HTTP failures normalize without retaining bodies or credentials. */
	public function test_http_error_and_malformed_response_mapping(): void {
		$decode = new ReflectionMethod( Seedance_25_API::class, 'decode_response' );
		$decode->setAccessible( true );

		$fixtures = [
			[ 401, 'invalid_api_key', 'seedance_25_api_unauthorized', 'request' ],
			[ 429, 'too_many_requests', 'seedance_25_api_rate_limited', 'poll' ],
			[ 402, 'insufficient_credits', 'seedance_25_api_insufficient_credits', 'submit' ],
			[ 400, 'invalid_options', 'seedance_25_api_request_invalid', 'submit' ],
			[ 500, 'internal_error', 'seedance_25_api_service_unavailable', 'poll' ],
			[ 404, '', 'seedance_25_api_job_not_found', 'poll' ],
			[ 404, '', 'seedance_25_api_request_failed', 'models' ],
			[ 409, 'task_not_ready', 'seedance_25_api_task_not_ready', 'poll' ],
			[ 400, 'invalid_task_id', 'seedance_25_api_job_id_invalid', 'poll' ],
		];
		foreach ( $fixtures as [ $status, $provider_code, $expected_code, $context ] ) {
			$error = $decode->invoke(
				null,
				[
					'response' => [ 'code' => $status ],
					'body'     => wp_json_encode(
						[
							'error' => [
								'code'    => $provider_code,
								'message' => '<b>Bounded provider message</b>',
								'details' => [ 'authorization' => 'must-not-survive' ],
							],
						]
					),
				],
				$context
			);
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( $expected_code, $error->get_error_code() );
			$this->assertStringNotContainsString( 'Bounded provider message', $error->get_error_message() );
			$this->assertStringNotContainsString( 'must-not-survive', wp_json_encode( $error->get_error_data() ) );
		}

		$malformed = $decode->invoke( null, [ 'response' => [ 'code' => 200 ], 'body' => '{malformed' ] );
		$this->assertInstanceOf( WP_Error::class, $malformed );
		$this->assertSame( 'seedance_25_api_invalid_response', $malformed->get_error_code() );

		foreach ( [ 401, 404, 429, 500 ] as $status ) {
			$error = $decode->invoke( null, [ 'response' => [ 'code' => $status ], 'body' => '<html>upstream error</html>' ], 'poll' );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertNotSame( 'seedance_25_api_invalid_response', $error->get_error_code() );
		}

		$ambiguous = $decode->invoke( null, [ 'response' => [ 'code' => 500 ], 'body' => '' ], 'submit' );
		$this->assertSame( 'seedance_25_api_submit_ambiguous', $ambiguous->get_error_code() );

		$scalar_error = $decode->invoke(
			null,
			[ 'response' => [ 'code' => 400 ], 'body' => '{"error":"invalid"}' ],
			'poll'
		);
		$this->assertSame( 'seedance_25_api_request_invalid', $scalar_error->get_error_code() );
	}

	/** The concrete WordPress HTTP layer applies bounds and submit ambiguity. */
	public function test_concrete_json_transport_is_bounded_and_context_aware(): void {
		$GLOBALS['worldgraph_seedance_http_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '{"data":{"ok":true}}',
		];
		$result = Seedance_25_API_Concrete_Transport::request_for_test(
			'POST',
			'https://cyberbara.com/api/v1/videos/generations',
			[ 'Authorization' => 'Bearer captured-only' ],
			[ 'model' => 'seedance-2.5' ],
			'submit'
		);
		$this->assertSame( [ 'data' => [ 'ok' => true ] ], $result );
		$request = $GLOBALS['worldgraph_seedance_http_requests'][0];
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( Seedance_25_API::MAX_RESPONSE_BYTES, $request['args']['limit_response_size'] );
		$this->assertSame( Seedance_25_API::TIMEOUT, $request['args']['timeout'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertSame( '{"model":"seedance-2.5"}', $request['args']['body'] );

		$GLOBALS['worldgraph_seedance_http_response'] = new WP_Error( 'http_request_failed', 'Timed out.' );
		$ambiguous = Seedance_25_API_Concrete_Transport::request_for_test(
			'POST',
			'https://cyberbara.com/api/v1/videos/generations',
			[],
			[ 'model' => 'seedance-2.5' ],
			'submit'
		);
		$this->assertSame( 'seedance_25_api_submit_ambiguous', $ambiguous->get_error_code() );
		$this->assertStringContainsString( 'Check CyberBara tasks', $ambiguous->get_error_message() );

		$GLOBALS['worldgraph_seedance_http_response'] = [ 'response' => [ 'code' => 401 ], 'body' => '<html>denied</html>' ];
		$unauthorized = Seedance_25_API_Concrete_Transport::request_for_test(
			'GET',
			'https://cyberbara.com/api/v1/tasks/' . self::TASK_POLL,
			[],
			null,
			'poll'
		);
		$this->assertSame( 'seedance_25_api_unauthorized', $unauthorized->get_error_code() );
	}

	/** Local image uploads reauthorize, inspect bytes, bound size, and frame multipart. */
	public function test_attachment_upload_validates_actual_image_bytes(): void {
		$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl9sAAAAASUVORK5CYII=', true );
		$this->assertIsString( $png_bytes );
		$png_path = tempnam( sys_get_temp_dir(), 'wg-seed-png-' );
		$this->assertIsString( $png_path );
		$this->assertSame( strlen( $png_bytes ), file_put_contents( $png_path, $png_bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test-owned temporary fixture.
		$GLOBALS['worldgraph_seedance_temp_files'][]       = $png_path;
		$GLOBALS['worldgraph_seedance_attachment_paths'][77] = $png_path;
		$GLOBALS['worldgraph_seedance_attachment_mimes'][77] = 'image/png';
		$GLOBALS['worldgraph_seedance_authorization_filter']  = true;

		$url = Seedance_25_API_Test_Double::public_media_for_test( 77 );
		$this->assertSame( 'https://cdn.example.test/uploaded-reference.png', $url );
		$this->assertCount( 1, Seedance_25_API_Test_Double::$multipart_requests );
		$request = Seedance_25_API_Test_Double::$multipart_requests[0];
		$this->assertSame( 'https://cyberbara.com/api/v1/uploads/images', $request['url'] );
		$this->assertSame( 'Bearer cyberbara-test-key', $request['headers']['Authorization'] );
		$this->assertStringStartsWith( 'multipart/form-data; boundary=WorldGraphSeedance', $request['content_type'] );
		$this->assertStringContainsString( 'name="files"', $request['body'] );
		$this->assertStringContainsString( 'Content-Type: image/png', $request['body'] );
		$this->assertStringContainsString( $png_bytes, $request['body'] );

		$GLOBALS['worldgraph_seedance_attachment_mimes'][77] = 'image/jpeg';
		$mismatch = Seedance_25_API_Test_Double::upload_for_test( 77, 7 );
		$this->assertSame( 'seedance_25_api_attachment_type_unsupported', $mismatch->get_error_code() );
		$this->assertCount( 1, Seedance_25_API_Test_Double::$multipart_requests );

		$text_path = tempnam( sys_get_temp_dir(), 'wg-seed-text-' );
		$this->assertIsString( $text_path );
		$this->assertSame( 16, file_put_contents( $text_path, 'not image bytes!' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test-owned temporary fixture.
		$GLOBALS['worldgraph_seedance_temp_files'][]         = $text_path;
		$GLOBALS['worldgraph_seedance_attachment_paths'][78] = $text_path;
		$GLOBALS['worldgraph_seedance_attachment_mimes'][78] = 'image/png';
		$renamed = Seedance_25_API_Test_Double::upload_for_test( 78, 7 );
		$this->assertSame( 'seedance_25_api_attachment_type_unsupported', $renamed->get_error_code() );

		$large_path = tempnam( sys_get_temp_dir(), 'wg-seed-large-' );
		$this->assertIsString( $large_path );
		$large_handle = fopen( $large_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- test-owned temporary fixture.
		$this->assertIsResource( $large_handle );
		$this->assertTrue( ftruncate( $large_handle, Seedance_25_API::MAX_UPLOAD_BYTES + 1 ) );
		fclose( $large_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- test-owned temporary fixture.
		$GLOBALS['worldgraph_seedance_temp_files'][]         = $large_path;
		$GLOBALS['worldgraph_seedance_attachment_paths'][79] = $large_path;
		$GLOBALS['worldgraph_seedance_attachment_mimes'][79] = 'image/png';
		$too_large = Seedance_25_API_Test_Double::upload_for_test( 79, 7 );
		$this->assertSame( 'seedance_25_api_attachment_too_large', $too_large->get_error_code() );
	}

	/** An ambiguous paid submission failure is returned after exactly one call. */
	public function test_submission_failure_is_not_retried(): void {
		Seedance_25_API_Test_Double::$responses[] = new WP_Error(
			'seedance_25_api_submit_ambiguous',
			'CyberBara may have accepted this paid Seedance task. Check CyberBara tasks before deliberately retrying.'
		);

		$error = Seedance_25_API_Test_Double::run_template(
			'seedance-2.5:text-to-video',
			'One paid request only',
			[ 'duration' => 10 ],
			42
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'seedance_25_api_submit_ambiguous', $error->get_error_code() );
		$this->assertStringContainsString( 'Check CyberBara tasks', $error->get_error_message() );
		$this->assertCount( 1, Seedance_25_API_Test_Double::$requests );
	}

	/** Public media URLs and the concrete HTTP/upload implementation stay bounded. */
	public function test_media_and_transport_security_contract(): void {
		$this->assertSame( 'https://cdn.example.test/reference.png', Seedance_25_API_Test_Double::public_media_for_test( 'https://cdn.example.test/reference.png' ) );
		$this->assertInstanceOf( WP_Error::class, Seedance_25_API_Test_Double::public_media_for_test( 'http://cdn.example.test/reference.png' ) );
		$this->assertInstanceOf( WP_Error::class, Seedance_25_API_Test_Double::public_media_for_test( '1.5' ) );
		$this->assertInstanceOf( WP_Error::class, Seedance_25_API_Test_Double::public_media_for_test( 'https://cdn.example.test/' . str_repeat( 'x', Seedance_25_API::MAX_URL_BYTES ) ) );

		$source = $this->source( 'includes/utils/seedance-25-api.php' );
		$this->assertGreaterThanOrEqual( 2, substr_count( $source, 'wp_safe_remote_request' ) );
		$this->assertStringContainsString( "'redirection'         => 0", $source );
		$this->assertStringContainsString( "'limit_response_size' => self::MAX_RESPONSE_BYTES", $source );
		$this->assertStringContainsString( 'worldgraph_generation_background_media_authorization', $source );
		$this->assertStringContainsString( "name=\"files\"", $source );
		$this->assertStringContainsString( 'MAX_UPLOAD_BYTES', $source );
		$this->assertStringNotContainsString( "'x-api-key'", $source );
	}
}
