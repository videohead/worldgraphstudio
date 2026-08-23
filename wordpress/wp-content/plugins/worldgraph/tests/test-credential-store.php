<?php
/**
 * Credential encryption and exposure-boundary contracts.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Connection_Repository;
use WorldGraph\Utils\Credential_Store;

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ): string {
		return 'worldgraph-test-salt-' . (string) $scheme;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free test shim.
		return trim( strip_tags( (string) $value ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/class-credential-store.php';
require_once dirname( __DIR__ ) . '/includes/utils/connection_repository.php';

/** Protect credentials at rest and at HTTP/admin presentation boundaries. */
class Test_Credential_Store extends TestCase {

	/** Authenticated encryption must round-trip without exposing plaintext. */
	public function test_authenticated_encryption_round_trip(): void {
		if ( ! Credential_Store::is_available() ) {
			$this->markTestSkipped( 'PHP OpenSSL AES-256-GCM is unavailable.' );
		}

		$plaintext = 'provider-secret-example';
		$stored    = Credential_Store::encrypt( $plaintext );

		$this->assertNotSame( $plaintext, $stored );
		$this->assertStringNotContainsString( $plaintext, $stored );
		$this->assertTrue( Credential_Store::is_encrypted( $stored ) );
		$this->assertSame( $plaintext, Credential_Store::decrypt( $stored ) );
	}

	/** GCM authentication must reject a modified database value. */
	public function test_modified_ciphertext_is_rejected(): void {
		if ( ! Credential_Store::is_available() ) {
			$this->markTestSkipped( 'PHP OpenSSL AES-256-GCM is unavailable.' );
		}

		$stored   = Credential_Store::encrypt( 'provider-secret-example' );
		$offset   = (int) strrpos( $stored, ':' ) + 1;
		$tampered = substr( $stored, 0, $offset ) . ( 'A' === $stored[ $offset ] ? 'B' : 'A' ) . substr( $stored, $offset + 1 );

		$this->expectException( RuntimeException::class );
		Credential_Store::decrypt( $tampered );
	}

	/** Settings writes encrypt replacements and preserve a submitted mask. */
	public function test_option_values_are_encrypted_before_storage(): void {
		if ( ! Credential_Store::is_available() ) {
			$this->markTestSkipped( 'PHP OpenSSL AES-256-GCM is unavailable.' );
		}

		$replacement = Credential_Store::prepare_option_value( 'replacement-key', 'existing-key', 'worldgraph_ai_api_key' );
		$preserved   = Credential_Store::prepare_option_value( Credential_Store::MASK, 'existing-key', 'worldgraph_ai_api_key' );

		$this->assertTrue( Credential_Store::is_encrypted( $replacement ) );
		$this->assertSame( 'replacement-key', Credential_Store::decrypt( $replacement ) );
		$this->assertSame( 'existing-key', Credential_Store::decrypt( $preserved ) );
	}

	/** HTTP responses must explicitly redact both Connection secret fields. */
	public function test_connection_rest_controller_redacts_credentials(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/connections-controller.php' );

		$this->assertStringContainsString( 'Connection_Repository::redact_credentials( $data[\'meta\'] )', $source );
		$this->assertStringContainsString( 'Connection_Repository::redact_credentials( $config )', $source );
	}

	/** Redaction masks both credential shapes without changing public fields. */
	public function test_connection_repository_redacts_credentials(): void {
		$record = [
			'credential_reference'     => 'provider-secret',
			'mcp_credential_reference' => 'env://MCP_SECRET',
			'endpoint_url'             => 'https://provider.example/api',
		];

		$redacted = Connection_Repository::redact_credentials( $record );

		$this->assertSame( Credential_Store::MASK, $redacted['credential_reference'] );
		$this->assertSame( Credential_Store::MASK, $redacted['mcp_credential_reference'] );
		$this->assertSame( $record['endpoint_url'], $redacted['endpoint_url'] );
	}

	/** Direct post-meta writes cannot bypass the encryption boundary. */
	public function test_connection_metadata_writes_are_intercepted(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-credential-store.php' );

		$this->assertStringContainsString( "add_filter( 'add_post_metadata'", $source );
		$this->assertStringContainsString( "add_filter( 'update_post_metadata'", $source );
		$this->assertStringContainsString( 'self::prepare_connection_value( $value, $post_id, $meta_key )', $source );
	}

	/** The Connection post type and provider actions stay administrator-only. */
	public function test_connection_credentials_have_an_admin_capability_boundary(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/cpts/connection.php' );

		$this->assertStringContainsString( "'map_meta_cap'       => false", $source );
		$this->assertStringContainsString( "'create_posts'           => 'manage_options'", $source );
		$this->assertStringContainsString( "! current_user_can( 'manage_options' )", $source );
		$this->assertStringContainsString( "'publish' !== get_post_status", $source );
	}

	/** Strict env:// references resolve without accepting shell-like names. */
	public function test_environment_credential_references_are_strict(): void {
		putenv( 'WORLDGRAPH_STORY_IO_TEST_KEY=story-secret' );
		$this->assertSame( 'story-secret', Credential_Store::resolve_reference( 'env://WORLDGRAPH_STORY_IO_TEST_KEY' ) );
		$this->assertSame( 'literal-secret', Credential_Store::resolve_reference( 'literal-secret' ) );

		$invalid = Credential_Store::resolve_reference( 'env://bad-name' );
		$this->assertTrue( is_wp_error( $invalid ) );
		$this->assertSame( 'worldgraph_credential_reference_invalid', $invalid->get_error_code() );
		putenv( 'WORLDGRAPH_STORY_IO_TEST_KEY' );
	}

	/** Manuscript decomposition uses exactly the selected published Connection. */
	public function test_llm_connection_chat_disables_cache_and_fallback(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/ai-editor/class-ai-llm-client.php' );

		$this->assertStringContainsString( 'public function chat_with_connection(', $source );
		$this->assertStringContainsString( "'publish' !== (string) ( \$record['status_wp'] ?? '' )", $source );
		$this->assertStringContainsString( "\$options['allow_fallback'] = false", $source );
		$this->assertStringContainsString( "\$options['cache']          = false", $source );
		$this->assertStringContainsString( 'Credential_Store::resolve_reference(', $source );
	}
}
