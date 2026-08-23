<?php
/**
 * Template provider-operation authorization contract tests.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

/** Protect Connection-backed provider operations without restricting editing. */
class Test_Template_Provider_Authorization extends TestCase {

	/** Return one method body from PHP source using the following docblock. */
	private function method_source( string $source, string $signature, string $next_docblock ): string {
		$start = strpos( $source, $signature );
		$this->assertNotFalse( $start, "Missing method signature: {$signature}" );

		$end = strpos( $source, $next_docblock, (int) $start );
		$this->assertNotFalse( $end, "Missing method boundary after: {$signature}" );

		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	/** Managing a Connection requires both the site and object capabilities. */
	public function test_connection_management_requires_admin_and_object_capabilities(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/connection_repository.php' );
		$method = $this->method_source(
			$source,
			'public static function current_user_can_manage( int $id ): bool',
			"\n\t/**\n\t * Find the default connection"
		);

		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $method );
		$this->assertStringContainsString( 'self::CPT !== $post->post_type', $method );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$id )", $method );
	}

	/** Every Template provider AJAX action must cross the same Connection gate. */
	public function test_template_ajax_actions_authorize_connection_before_loading_adapter(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/cpts/template.php' );
		$method = $this->method_source(
			$source,
			'private static function authorize_requirements_request(): int',
			"\n\t/**\n\t * Whether the current user may expose"
		);

		$this->assertSame( 6, substr_count( $source, 'self::authorize_requirements_request();' ) );
		$this->assertStringContainsString( "check_ajax_referer( 'worldgraph_template_requirements', 'nonce' )", $method );
		$this->assertStringContainsString( "'worldgraph_template' !== \$post->post_type", $method );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$post_id )", $method );
		$this->assertStringContainsString( 'Connection_Repository::current_user_can_manage( $connection_id )', $method );
		$this->assertStringContainsString( "'comfyui' !== (string) ( \$connection['provider_type'] ?? '' )", $method );
		$this->assertLessThan(
			strpos( $method, "Connection_Adapters::load( 'comfyui' )" ),
			strpos( $method, 'Connection_Repository::current_user_can_manage( $connection_id )' )
		);
	}

	/** Non-admin Template saves and screens cannot initiate provider operations. */
	public function test_template_ui_and_automatic_smoke_check_use_admin_boundary(): void {
		$template = (string) file_get_contents( dirname( __DIR__ ) . '/includes/cpts/template.php' );
		$smoke    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/template-smoke-check.php' );

		$enqueue = $this->method_source(
			$template,
			'public static function enqueue_requirements_script( string $hook_suffix ): void',
			"\n\t/**\n\t * Render the ComfyUI requirements panel"
		);
		$this->assertStringContainsString( 'self::current_user_can_manage_provider_operations( $post_id )', $enqueue );
		$this->assertLessThan( strpos( $enqueue, 'wp_create_nonce' ), strpos( $enqueue, 'current_user_can_manage_provider_operations' ) );

		$this->assertStringContainsString( 'Connection_Repository::current_user_can_manage( $connection_id )', $template );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $smoke );
		$this->assertStringContainsString( 'Connection_Repository::current_user_can_manage( $connection_id )', $smoke );
		$this->assertLessThan( strpos( $smoke, 'self::run_for_template( $post_id )' ), strpos( $smoke, "current_user_can( 'manage_options' )" ) );
	}

	/** Provider hardening must not remap ordinary Template editing to admin caps. */
	public function test_template_post_type_keeps_standard_editing_capabilities(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/cpts/template.php' );
		$method = $this->method_source(
			$source,
			'private static function register_cpt(): void',
			"\n\t/**\n\t * Register admin UI for template configuration."
		);

		$this->assertStringNotContainsString( "'capabilities'", $method );
		$this->assertStringNotContainsString( "'manage_options'", $method );
	}
}
