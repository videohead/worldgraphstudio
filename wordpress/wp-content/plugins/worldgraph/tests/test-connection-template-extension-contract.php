<?php
/**
 * Connection and Template extension-boundary contract tests.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Connections\Connection_Test_Service;
use WorldGraph\Templates\Template_Manager;
use WorldGraph\Utils\Connection_Adapters;

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

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ): string {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ): string {
		return (string) json_encode( $value, $flags );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/connection-adapters.php';
require_once dirname( __DIR__ ) . '/includes/utils/connection_tester.php';
require_once dirname( __DIR__ ) . '/includes/templates/class-template-manager.php';

/** Protects the provider-neutral extraction and third-party registration seam. */
class Test_Connection_Template_Extension_Contract extends TestCase {

	/** Read one plugin source file. */
	private function source( string $relative_path ): string {
		$path = dirname( __DIR__ ) . '/' . ltrim( $relative_path, '/' );
		$this->assertFileExists( $path, "Missing extension-boundary source: {$relative_path}" );
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source, "Unreadable extension-boundary source: {$relative_path}" );

		return (string) $source;
	}

	/** Historical utility class names remain facades over the extracted modules. */
	public function test_legacy_connection_classes_are_thin_compatibility_facades(): void {
		$adapters = $this->source( 'includes/utils/connection-adapters.php' );
		$tester   = $this->source( 'includes/utils/connection_tester.php' );

		$this->assertStringContainsString( '/connections/class-adapter-registry.php', $adapters );
		$this->assertStringContainsString( 'extends \\WorldGraph\\Connections\\Adapter_Registry', $adapters );
		$this->assertStringContainsString( '/connections/class-connection-test-service.php', $tester );
		$this->assertStringContainsString( 'extends \\WorldGraph\\Connections\\Connection_Test_Service', $tester );
		$this->assertTrue( is_subclass_of( Connection_Adapters::class, \WorldGraph\Connections\Adapter_Registry::class ) );
	}

	/** Manifest capabilities drive the common test, Template, and worker paths. */
	public function test_bundled_manifest_declares_normalized_capabilities(): void {
		$fal = Connection_Adapters::get( 'fal' );
		$comfy = Connection_Adapters::get( 'comfyui' );

		$this->assertIsArray( $fal );
		$this->assertTrue( Connection_Adapters::supports( 'fal', 'test' ) );
		$this->assertTrue( Connection_Adapters::supports( 'fal', 'templates.provision' ) );
		$this->assertTrue( Connection_Adapters::supports( 'fal', 'generation.poll' ) );
		$this->assertFalse( Connection_Adapters::supports( 'fal', 'templates.missing' ) );
		$this->assertTrue( Connection_Adapters::supports_generation( 'fal' ) );
		$this->assertTrue( Connection_Adapters::supports_polling( 'fal' ) );
		$this->assertTrue( Connection_Adapters::poll_with_template( 'fal' ) );
		$this->assertSame( 10, $fal['generation']['poll_error_limit'] );
		$this->assertSame( 'fal_mcp', Connection_Adapters::generation_adapter( 'fal' ) );
		$this->assertSame( 'fal_catalog', $fal['templates']['status_meta_prefix'] );
		$this->assertIsArray( $comfy['generation']['adapter_resolver'] ?? null );
		$this->assertSame( 'comfy_mcp', \WorldGraph\Connections\Builtin_Adapter_Runtime::comfy_adapter() );
		$this->assertSame( 'local_comfyui', \WorldGraph\Connections\Builtin_Adapter_Runtime::comfy_adapter( [ 'environment' => 'local' ] ) );
	}

	/** Disabled Connections remain an explicit operational stop during testing. */
	public function test_disabled_connection_test_is_guarded_before_result_persistence(): void {
		$service = $this->source( 'includes/connections/class-connection-test-service.php' );
		$guard   = strpos( $service, "'disabled' === sanitize_key" );
		$persist = strpos( $service, 'return self::record_result' );

		$this->assertNotFalse( $guard );
		$this->assertNotFalse( $persist );
		$this->assertLessThan( $persist, $guard );
		$this->assertStringContainsString( "'status'  => 'disabled'", $service );
	}

	/** A configured URL does not imply an executable generation contract. */
	public function test_endpoint_metadata_alone_does_not_enable_generation(): void {
		$this->assertNotSame( '', Connection_Adapters::endpoint( 'descript' ) );
		$this->assertTrue( Connection_Adapters::supports( 'descript', 'test' ) );
		$this->assertFalse( Connection_Adapters::supports_generation( 'descript' ) );
		$this->assertFalse( Connection_Adapters::supports_generation( 'veo' ) );
	}

	/** Provider health reports are bounded and discard sensitive-key values. */
	public function test_connection_health_normalization_is_bounded_and_redacted(): void {
		$normalize = new ReflectionMethod( Connection_Test_Service::class, 'normalize_health' );
		$normalize->setAccessible( true );
		$health = $normalize->invoke(
			null,
			[
				'api_key' => 'must-not-survive',
				'nested'  => [
					'token' => 'must-not-survive',
					'ok'    => str_repeat( 'x', 700 ),
				],
				'items'   => range( 1, 250 ),
			]
		);

		$this->assertArrayNotHasKey( 'api_key', $health );
		$this->assertArrayNotHasKey( 'token', $health['nested'] );
		$this->assertSame( 500, strlen( $health['nested']['ok'] ) );
		$this->assertLessThan( 100, count( $health['items'] ) );
		$this->assertLessThanOrEqual( 8192, strlen( (string) wp_json_encode( $health ) ) );
	}

	/** Common lifecycle code no longer schedules provider catalogs by slug. */
	public function test_connection_save_and_generation_dispatch_are_registry_driven(): void {
		$bootstrap  = $this->source( 'worldgraph.php' );
		$connection = $this->source( 'includes/cpts/connection.php' );
		$wizard     = $this->source( 'includes/admin/setup-wizard.php' );
		$batch      = $this->source( 'includes/utils/generation-batch.php' );
		$adapters   = $this->source( 'includes/admin/adapters.php' );

		$this->assertStringContainsString( 'Template_Manager::schedule_for_connection', $connection );
		$this->assertStringContainsString( 'wp_clear_scheduled_hook( Templates\\Template_Manager::HOOK )', $bootstrap );
		$this->assertStringContainsString( "callback( \$provider_type, 'after_save' )", $connection );
		foreach ( [ 'Fal_Catalog::HOOK', 'ElevenLabs_Catalog::HOOK', 'Suno_Catalog::HOOK', 'VideoDraft_Catalog::HOOK' ] as $legacy_branch ) {
			$this->assertStringNotContainsString( $legacy_branch, $connection );
			$this->assertStringNotContainsString( $legacy_branch, $wizard );
		}

		$this->assertStringContainsString( 'Connection_Adapters::supports_generation', $batch );
		$this->assertStringContainsString( 'Connection_Adapters::supports_polling', $batch );
		$this->assertStringContainsString( 'Connection_Adapters::generation_client', $batch );
		$this->assertStringContainsString( "['poll_error_limit']", $batch );
		$this->assertStringContainsString( "['show_in_plugins']", $adapters );
		$this->assertStringContainsString( "['client_resolver']", $adapters );
	}

	/** Third-party generation failures stay inside one claimed job. */
	public function test_generation_dispatch_validates_and_contains_extension_clients(): void {
		$batch      = $this->source( 'includes/utils/generation-batch.php' );
		$controller = $this->source( 'includes/rest-api/generation-controller.php' );
		$assets     = $this->source( 'includes/utils/class-asset-generator.php' );

		$this->assertGreaterThanOrEqual( 4, substr_count( $batch, 'catch ( \\Throwable )' ) );
		$this->assertStringContainsString( 'self::validate_client_result( $result, \'submit\' )', $batch );
		$this->assertStringContainsString( 'self::validate_client_result( $result, \'poll\' )', $batch );
		$this->assertStringContainsString( "in_array( \$status, [ 'completed', 'failed', 'cancelled' ], true )", $batch );
		$this->assertStringContainsString( 'self::fail_claimed_job', $batch );
		$this->assertStringContainsString( 'self::terminal_result_message( $result, $status )', $batch );
		$this->assertStringContainsString( "Generation_Log::add( 'error', 'generation_batch'", $batch );
		$this->assertStringContainsString( "Generation_Log::add( 'warning', 'generation_batch'", $batch );

		$this->assertStringContainsString( 'Connection_Adapters::generation_adapter', $controller );
		$this->assertStringContainsString( 'Connection_Adapters::generation_client', $controller );
		$this->assertStringContainsString( "update_post_meta( \$post_id, '_worldgraph_gen_adapter', \$adapter )", $controller );
		$this->assertStringContainsString( 'Connection_Adapters::generation_adapter', $assets );
		$this->assertStringContainsString( 'Connection_Adapters::generation_client', $assets );
		$this->assertStringContainsString( 'worldgraph_asset_generation_client_unavailable', $assets );
	}

	/** Every shipped provider catalog delegates the shared persistence contract. */
	public function test_provider_catalogs_use_the_common_template_repository(): void {
		foreach ( [ 'fal-catalog.php', 'elevenlabs-catalog.php', 'suno-catalog.php', 'videodraft-catalog.php' ] as $catalog_file ) {
			$catalog = $this->source( 'includes/utils/' . $catalog_file );
			$this->assertStringContainsString( 'Template_Repository::upsert_provider_template', $catalog, $catalog_file );
			$this->assertStringNotContainsString( "add_action( 'save_post_worldgraph_conn'", $catalog, $catalog_file );
		}

		$repository = $this->source( 'includes/templates/class-template-repository.php' );
		$this->assertStringContainsString( "[ 'key' => 'connection_id'", $repository );
		$this->assertStringContainsString( "[ 'key' => 'provider_template_id'", $repository );
		$this->assertStringContainsString( 'Generation_Modality::has( $modality )', $repository );
		$this->assertStringContainsString( 'Generation_Modality::output_type( $modality )', $repository );
	}

	/** Common provisioning records bounded outcomes that WP-Cron would otherwise discard. */
	public function test_template_manager_persists_and_announces_bounded_status(): void {
		$manager = $this->source( 'includes/templates/class-template-manager.php' );

		$this->assertStringContainsString( "['status_meta_prefix']", $manager );
		$this->assertStringContainsString( "'_synced_at'", $manager );
		$this->assertStringContainsString( "'_error'", $manager );
		$this->assertStringContainsString( 'worldgraph_template_provisioning_status', $manager );
		$this->assertStringContainsString( 'MAX_STATUS_MESSAGE_LENGTH', $manager );
		$this->assertStringContainsString( 'Diagnostic observers are best-effort', $manager );
		$this->assertStringContainsString( 'self::record_failure( $connection_id, $templates, \'provision\', $result )', $manager );

		$normalize = new ReflectionMethod( Template_Manager::class, 'normalize_status_message' );
		$normalize->setAccessible( true );
		$message = $normalize->invoke( null, '<b>Failure</b> ' . str_repeat( 'x', 700 ) );

		$this->assertStringNotContainsString( '<b>', $message );
		$this->assertSame( 500, strlen( $message ) );
	}

	/** Exact Template identities are serialized and include trashed records. */
	public function test_template_repository_guards_identity_across_registered_statuses(): void {
		$repository = $this->source( 'includes/templates/class-template-repository.php' );

		$this->assertStringContainsString( "get_post_stati( [], 'names' )", $repository );
		$this->assertStringContainsString( "\$post_statuses[] = 'trash'", $repository );
		$this->assertStringContainsString( 'acquire_identity_lock', $repository );
		$this->assertStringContainsString( "add_option( \$option_name, \$token, '', 'no' )", $repository );
		$this->assertStringContainsString( '$wpdb->update(', $repository );
		$this->assertStringContainsString( 'finally {', $repository );
		$this->assertStringContainsString( 'release_identity_lock( $lock )', $repository );
		$this->assertStringNotContainsString( "'post_status'    => 'any'", $repository );
	}

	/** The human guide and portable schema describe the same extension keys. */
	public function test_documented_manifest_schema_matches_runtime_sections(): void {
		$root                 = dirname( WORLDGRAPH_PLUGIN_DIR, 4 );
		$schema_path          = $root . '/about/schemas/worldgraph-connection-adapter.schema.json';
		$template_schema_path = $root . '/about/schemas/worldgraph-provider-template-definition.schema.json';
		$guide_path           = $root . '/about/Adding_Connections_and_Templates.md';

		$this->assertFileExists( $schema_path );
		$this->assertFileExists( $template_schema_path );
		$this->assertFileExists( $guide_path );
		$schema = json_decode( (string) file_get_contents( $schema_path ), true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertSame( '1.0.0', $schema['x-worldgraph-schema-version'] ?? null );

		$adapter_properties = $schema['$defs']['adapter']['properties'] ?? [];
		$this->assertArrayHasKey( 'callbacks', $adapter_properties );
		$this->assertArrayHasKey( 'templates', $adapter_properties );
		$this->assertArrayHasKey( 'generation', $adapter_properties );
		$this->assertArrayHasKey( 'status_meta_prefix', $schema['$defs']['templates']['properties'] ?? [] );
		$this->assertArrayHasKey( 'poll_error_limit', $schema['$defs']['generation']['properties'] ?? [] );
		$this->assertArrayHasKey( 'permanent_error_codes', $schema['$defs']['generation']['properties'] ?? [] );
		$this->assertArrayHasKey( 'adapter_resolver', $schema['$defs']['generation']['properties'] ?? [] );

		$template_schema = json_decode( (string) file_get_contents( $template_schema_path ), true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertSame( '1.0.0', $template_schema['x-worldgraph-schema-version'] ?? null );
		$this->assertSame(
			[ 'provider_type', 'provider_template_id', 'template_name', 'modality' ],
			$template_schema['required'] ?? []
		);
		$this->assertSame( [ 'draft', 'active', 'archived' ], $template_schema['properties']['status']['enum'] ?? [] );

		$guide = (string) file_get_contents( $guide_path );
		$this->assertStringContainsString( 'URL-only registration is deliberately non-executable', $guide );
		$this->assertStringContainsString( 'Template_Repository::upsert_provider_template', $guide );
	}
}
