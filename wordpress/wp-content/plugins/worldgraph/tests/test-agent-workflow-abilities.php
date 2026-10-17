<?php
/** Agent workflow ability contract tests. */

use PHPUnit\Framework\TestCase;

final class Test_Agent_Workflow_Abilities extends TestCase {
	private string $workflow_source;
	private string $abilities_source;
	private string $bootstrap_source;

	protected function setUp(): void {
		$this->workflow_source  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/ai-editor/class-agent-workflow-abilities.php' );
		$this->abilities_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/ai-editor/class-ai-abilities.php' );
		$this->bootstrap_source = (string) file_get_contents( dirname( __DIR__ ) . '/worldgraph.php' );
	}

	public function test_complete_agent_workflow_is_declared(): void {
		foreach ( [ 'decompose-story-upload', 'decompose-story', 'import-story', 'content-schema', 'list-entities', 'get-entity', 'create-entity', 'update-entity', 'review-project', 'add-review-note', 'plan-end-to-end-generation', 'run-end-to-end-generation', 'review-generation', 'preview-edl-import', 'import-edl', 'export-edl' ] as $ability ) {
			$this->assertStringContainsString( "'{$ability}'", $this->workflow_source, $ability );
		}
	}

	public function test_workflow_delegates_to_existing_rest_controllers(): void {
		foreach ( [ 'Import_Controller', 'Asset_Generation_Controller', 'Editorial_Controller', 'Production_Controller' ] as $controller ) {
			$this->assertStringContainsString( $controller, $this->workflow_source );
		}
		$this->assertStringContainsString( "'idempotency_key'", $this->workflow_source );
		$this->assertStringContainsString( "'template' => Agent_Template_Controller::class", $this->workflow_source );
		$this->assertStringContainsString( "'connection' => '\\\\WorldGraph\\\\REST\\\\Connections_Controller'", $this->workflow_source );
		$this->assertStringContainsString( 'persist_edl_preview', $this->workflow_source );
		$this->assertStringContainsString( 'export_edl', $this->workflow_source );
	}

	public function test_abilities_use_required_category_lifecycle(): void {
		$this->assertStringContainsString( "'category'] = \$this->category_slug", $this->abilities_source );
		$this->assertStringContainsString( 'wp_abilities_api_categories_init', $this->bootstrap_source );
		$this->assertLessThan( strpos( $this->bootstrap_source, "add_action( 'init'" ), strpos( $this->bootstrap_source, 'wp_abilities_api_categories_init' ) );
	}
}
