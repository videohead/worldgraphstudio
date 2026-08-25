<?php
/**
 * Tests for the classic-editor filmmaking agent chat contract.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/ai-editor/class-ai-agent-router.php';

class Test_AI_Metabox_Chat extends TestCase {

	/**
	 * ComfyUI questions should route to the installed specialist profile.
	 */
	public function test_router_selects_comfy_technician(): void {
		$router = new \WorldGraph\AI\AI_Agent_Router();
		$route  = $router->route( 'Why is this ComfyUI workflow missing a custom node?' );

		$this->assertSame( 'ComfyTechnician', $route['agent'] );
		$this->assertSame( 'comfyui', $route['category'] );
	}

	/**
	 * Story Graph intelligence questions should select focused advisor profiles.
	 */
	public function test_router_selects_story_graph_intelligence_advisors(): void {
		$router = new \WorldGraph\AI\AI_Agent_Router();

		$this->assertSame( 'StoryGraphAnalyst', $router->route( 'Find entities connected to this character in the story graph.' )['agent'] );
		$this->assertSame( 'ContinuityAnalyst', $router->route( 'Review this continuity issue between the scene and its location.' )['agent'] );
		$this->assertSame( 'RelationshipAnalyst', $router->route( 'Explain the graph density and isolated entities.' )['agent'] );
		$this->assertSame( 'DevelopmentAdvisor', $router->route( 'What should I develop next from the Development Compass?' )['agent'] );
	}

	/**
	 * Story Graph intelligence profiles must be available to the registry.
	 */
	public function test_story_graph_intelligence_profiles_exist(): void {
		$agents_dir = dirname( __DIR__ ) . '/includes/agents/';
		$profiles   = [
			'story_graph_analyst.agent.md' => 'StoryGraphAnalyst',
			'continuity_analyst.agent.md'  => 'ContinuityAnalyst',
			'relationship_analyst.agent.md' => 'RelationshipAnalyst',
			'development_advisor.agent.md' => 'DevelopmentAdvisor',
		];

		foreach ( $profiles as $filename => $name ) {
			$source = file_get_contents( $agents_dir . $filename );

			$this->assertNotFalse( $source );
			$this->assertStringContainsString( 'name: ' . $name, $source );
			$this->assertStringContainsString( 'Advise only', $source );
		}
	}

	/**
	 * The specialist definition must remain available to the agent registry.
	 */
	public function test_comfy_technician_definition_exists(): void {
		$file   = dirname( __DIR__ ) . '/includes/agents/comfy_technician.agent.md';
		$source = file_get_contents( $file );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'name: ComfyTechnician', $source );
		$this->assertStringContainsString( 'You are the ComfyUI Technician for World Graph Studio.', $source );
	}

	/**
	 * The vanilla JavaScript metabox must use the shared REST chat route and
	 * send prior messages rather than selecting action-specific endpoints.
	 */
	public function test_metabox_uses_rest_chat_with_history(): void {
		$file   = dirname( __DIR__ ) . '/assets/ai-editor/js/shot-workflow.js';
		$source = file_get_contents( $file );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "request( '/ai/chat'", $source );
		$this->assertStringContainsString( 'messages: history', $source );
		$this->assertStringNotContainsString( "var endpoint = '/ai/' + action", $source );
	}
}
