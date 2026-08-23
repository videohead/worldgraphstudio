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
