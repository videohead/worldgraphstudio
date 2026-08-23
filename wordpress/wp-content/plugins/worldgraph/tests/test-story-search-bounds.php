<?php
/**
 * Public Story Search query-bound tests.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils {
	defined( 'ABSPATH' ) || exit;

	if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
		/** No-op hook registration for the WordPress-free unit suite. */
		function add_action( ...$args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\add_shortcode' ) ) {
		/** No-op shortcode registration for the WordPress-free unit suite. */
		function add_shortcode( ...$args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		}
	}
}

namespace {

use PHPUnit\Framework\TestCase;
use function WorldGraph\Utils\clamp_public_search_limit;

if ( ! class_exists( 'WP_Widget' ) ) {
	/** Minimal parent for loading the search widget declaration. */
	class WP_Widget {
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/story-search.php';

/** Public search requests must retain small, deterministic query ceilings. */
class Test_Story_Search_Bounds extends TestCase {

	/** Omitted and malformed limits use the endpoint's documented defaults. */
	public function test_public_limit_defaults_are_preserved(): void {
		$this->assertSame( 20, clamp_public_search_limit( null, 20, 50 ) );
		$this->assertSame( 5, clamp_public_search_limit( '', 5, 20 ) );
		$this->assertSame( 20, clamp_public_search_limit( [], 20, 50 ) );
		$this->assertSame( 5, clamp_public_search_limit( 'not-a-number', 5, 20 ) );
	}

	/** Oversized and negative values clamp to the hard maximum and minimum. */
	public function test_public_limits_are_clamped_at_both_boundaries(): void {
		$this->assertSame( 50, clamp_public_search_limit( 999999, 20, 50 ) );
		$this->assertSame( 20, clamp_public_search_limit( PHP_INT_MAX, 5, 20 ) );
		$this->assertSame( 1, clamp_public_search_limit( -999999, 20, 50 ) );
		$this->assertSame( 1, clamp_public_search_limit( 0, 5, 20 ) );
	}

	/** Keyword, REST search, and suggestion paths all use the same clamp. */
	public function test_every_public_query_path_uses_the_bounded_helper(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/story-search.php' );

		$this->assertStringContainsString(
			"clamp_public_search_limit( \$args['top_k'] ?? null, \$config['max_results'], 50 )",
			$source
		);
		$this->assertStringContainsString(
			"clamp_public_search_limit( \$request->get_param( 'top_k' ), 20, 50 )",
			$source
		);
		$this->assertStringContainsString(
			"clamp_public_search_limit( \$request->get_param( 'limit' ), 5, 20 )",
			$source
		);
		$this->assertStringContainsString( "'maximum'     => 50", $source );
		$this->assertStringContainsString( "'maximum'     => 20", $source );
	}
}
}
