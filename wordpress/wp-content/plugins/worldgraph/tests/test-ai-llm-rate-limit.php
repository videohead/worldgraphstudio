<?php
/**
 * Persistent AI LLM client rate-limit tests.
 *
 * @package WorldGraph
 */

namespace WorldGraph\AI {
	defined( 'ABSPATH' ) || exit;

	if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
		/** Return a controllable option value. */
		function get_option( string $name, $default = false ) {
			return array_key_exists( $name, (array) ( $GLOBALS['worldgraph_ai_rate_options'] ?? [] ) )
				? $GLOBALS['worldgraph_ai_rate_options'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\get_current_user_id' ) ) {
		/** Return the current test user. */
		function get_current_user_id(): int {
			return (int) ( $GLOBALS['worldgraph_ai_rate_user_id'] ?? 0 );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\get_transient' ) ) {
		/** Return persistent request state shared by fresh client instances. */
		function get_transient( string $key ) {
			return $GLOBALS['worldgraph_ai_rate_transients'][ $key ]['value'] ?? false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\set_transient' ) ) {
		/** Persist request state and its requested expiry. */
		function set_transient( string $key, $value, int $expiration ): bool {
			$GLOBALS['worldgraph_ai_rate_transients'][ $key ] = [
				'value'      => $value,
				'expiration' => $expiration,
			];
			return true;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\absint' ) ) {
		/** Minimal WordPress positive-integer sanitizer. */
		function absint( $value ): int {
			return abs( (int) $value );
		}
	}
}

namespace {

use PHPUnit\Framework\TestCase;
use WorldGraph\AI\AI_LLM_Client;

require_once dirname( __DIR__ ) . '/includes/ai-editor/class-ai-llm-client.php';

/** The LLM request budget must persist across PHP object instances. */
class Test_AI_LLM_Rate_Limit extends TestCase {

	protected function setUp(): void {
		$GLOBALS['worldgraph_ai_rate_options']    = [];
		$GLOBALS['worldgraph_ai_rate_transients'] = [];
		$GLOBALS['worldgraph_ai_rate_user_id']    = 42;
	}

	/** Invoke the private boundary without making an external LLM request. */
	private function check( AI_LLM_Client $client ): bool {
		$method = new ReflectionMethod( AI_LLM_Client::class, 'check_rate_limit' );
		$method->setAccessible( true );

		return (bool) $method->invoke( $client );
	}

	/** A new client object cannot reset the default ten-request user budget. */
	public function test_fresh_clients_share_the_default_ten_per_minute_limit(): void {
		for ( $request = 1; $request <= 10; $request++ ) {
			$this->assertTrue( $this->check( new AI_LLM_Client() ), "Request {$request} should be allowed." );
		}

		$this->assertFalse( $this->check( new AI_LLM_Client() ) );
		$this->assertCount( 10, $GLOBALS['worldgraph_ai_rate_transients']['worldgraph_ai_rate_42']['value'] );
		$this->assertSame( 60, $GLOBALS['worldgraph_ai_rate_transients']['worldgraph_ai_rate_42']['expiration'] );
	}

	/** One user's exhausted budget must not consume another user's budget. */
	public function test_rate_limit_state_is_scoped_by_user_id(): void {
		for ( $request = 0; $request < 10; $request++ ) {
			$this->assertTrue( $this->check( new AI_LLM_Client() ) );
		}
		$this->assertFalse( $this->check( new AI_LLM_Client() ) );

		$GLOBALS['worldgraph_ai_rate_user_id'] = 77;
		$this->assertTrue( $this->check( new AI_LLM_Client() ) );
		$this->assertCount( 1, $GLOBALS['worldgraph_ai_rate_transients']['worldgraph_ai_rate_77']['value'] );
	}
}
}
