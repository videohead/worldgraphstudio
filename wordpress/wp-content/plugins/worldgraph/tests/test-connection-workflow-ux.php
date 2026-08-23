<?php
/**
 * Connection workflow user-experience source contracts.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/**
 * Protect the operator-facing Connection workflow language and activity view.
 */
class Test_Connection_Workflow_UX extends TestCase {

	/** Read a plugin source file and fail with its relative path when unavailable. */
	private function source( string $relative_path ): string {
		$path = dirname( __DIR__ ) . '/' . ltrim( $relative_path, '/' );
		$this->assertFileExists( $path, "Missing Connection UX source: {$relative_path}" );

		$source = file_get_contents( $path );
		$this->assertNotFalse( $source, "Unreadable Connection UX source: {$relative_path}" );

		return (string) $source;
	}

	/** The Connection editor behavior must live in an enqueued JavaScript asset. */
	public function test_connection_editor_asset_exists_and_is_enqueued(): void {
		$this->source( 'assets/js/connection-editor.js' );
		$editor = $this->source( 'includes/cpts/connection.php' );

		$this->assertStringContainsString( 'wp_enqueue_script(', $editor );
		$this->assertStringContainsString( "'assets/js/connection-editor.js'", $editor );
	}

	/** Workflow discovery and bulk setup use concise operator-facing actions. */
	public function test_connection_editor_uses_current_workflow_actions(): void {
		$editor = $this->source( 'includes/cpts/connection.php' );

		$this->assertStringContainsString( 'Refresh Available Workflows', $editor );
		$this->assertStringContainsString( 'Add All Ready Workflows', $editor );
		$this->assertStringContainsString( 'Added Workflows (Managed JSON)', $editor );
		$this->assertStringNotContainsString( 'Sync Catalog', $editor );
		$this->assertStringNotContainsString( 'Auto-Prepare Mappable Templates', $editor );
		$this->assertStringNotContainsString( 'Managed by the Template Catalog panel', $editor );
	}

	/** The Connections screen separates health checks from workflow setup. */
	public function test_connections_admin_uses_health_and_setup_language(): void {
		$admin = $this->source( 'includes/admin/connections.php' );

		foreach ( [ 'Connection health', 'Workflow setup', 'Check connection', 'Manage setup' ] as $label ) {
			$this->assertStringContainsString( $label, $admin );
		}

		$this->assertDoesNotMatchRegularExpression(
			'/(?:esc_html_e|esc_html__|_e|__)\(\s*[\'\"]Sync Capabilities[\'\"]/',
			$admin,
			'The main Connections UI must not render the legacy Sync Capabilities action or panel.'
		);
		$this->assertStringNotContainsString( '>Sync Capabilities<', preg_replace( '/\s+/', ' ', $admin ) ?? $admin );
	}

	/** The Setup Wizard must explain third-party ownership and API account costs. */
	public function test_setup_wizard_discloses_third_party_services_and_api_billing(): void {
		$wizard = $this->source( 'includes/admin/setup-wizard.php' );

		foreach (
			[
				'does not own, operate, maintain, or provide',
				'Connections and Templates are configuration records',
				'You must independently obtain and connect every external service',
				'official developer or API portal',
				'Enable API access and billing',
				'the provider bills you directly',
				'Access to a model in a web chat or consumer app is not API access',
			] as $disclosure
		) {
			$this->assertStringContainsString( $disclosure, $wizard );
		}
	}

	/** Connection activity is projected from the canonical generation log. */
	public function test_connection_activity_uses_generation_log(): void {
		$editor = $this->source( 'includes/cpts/connection.php' );

		$this->assertStringContainsString( 'Generation_Log::for_connection', $editor );
	}
}
