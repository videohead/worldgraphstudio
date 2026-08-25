<?php
/**
 * Tests for hierarchical, Connection-and-Template-scoped run defaults.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils {
	defined( 'ABSPATH' ) || exit;

	if ( ! function_exists( __NAMESPACE__ . '\\get_post_meta' ) ) {
		function get_post_meta( $post_id, $key = '', $single = false ) {
			$value = $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ][ (string) $key ] ?? null;
			return $single ? ( null === $value ? '' : $value ) : ( null === $value ? [] : [ $value ] );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\update_post_meta' ) ) {
		function update_post_meta( $post_id, $key, $value, $prev_value = '' ) {
			$exists = isset( $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ] )
				&& array_key_exists( (string) $key, $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ] );
			$current = $exists ? $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ][ (string) $key ] : null;
			if ( ! empty( $prev_value ) && ( ! $exists || $current !== $prev_value ) ) {
				return false;
			}
			if ( $exists && $current === $value ) {
				return false;
			}
			$GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ][ (string) $key ] = $value;
			return 1;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\add_post_meta' ) ) {
		function add_post_meta( $post_id, $key, $value, $unique = false ) {
			$exists = isset( $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ] )
				&& array_key_exists( (string) $key, $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ] );
			if ( $unique && $exists ) {
				return false;
			}
			$GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ][ (string) $key ] = $value;
			return 1;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\delete_post_meta' ) ) {
		function delete_post_meta( $post_id, $key, $value = '' ): bool {
			$exists = isset( $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ] )
				&& array_key_exists( (string) $key, $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ] );
			if ( ! $exists ) {
				return false;
			}
			$current = $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ][ (string) $key ];
			if ( '' !== $value && null !== $value && false !== $value && $current !== $value ) {
				return false;
			}
			unset( $GLOBALS['worldgraph_import_journal_state']['meta'][ (int) $post_id ][ (string) $key ] );
			return true;
		}
	}
}

namespace {

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Generation_Run_Defaults;
use WorldGraph\Utils\Template_Run_Controls;

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal WP_Error used by pure run-control tests. */
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

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

require_once dirname( __DIR__ ) . '/includes/utils/template-run-controls.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-generation-run-defaults.php';

/** Run-default repository and merge-contract tests. */
final class Test_Generation_Run_Defaults extends TestCase {

	protected function setUp(): void {
		$GLOBALS['worldgraph_import_journal_state'] = [
			'meta'        => [],
			'post_types'  => [],
			'thumbnails'  => [],
		];
	}

	/** A compact normalized control description for stored-layer validation. */
	private function description( string $fingerprint = '' ): array {
		return [
			'version'     => Template_Run_Controls::VERSION,
			'fingerprint' => $fingerprint ?: str_repeat( 'a', 64 ),
			'fields'      => [
				[ 'key' => 'steps', 'label' => 'Steps', 'type' => 'integer', 'min' => 1, 'max' => 100, 'step' => 1, 'default' => 20 ],
				[ 'key' => 'cfg', 'label' => 'CFG', 'type' => 'number', 'min' => 0.0, 'max' => 30.0, 'step' => 0.5, 'default' => 7.0 ],
				[ 'key' => 'width', 'label' => 'Width', 'type' => 'integer', 'min' => 64, 'max' => 4096, 'step' => 1, 'default' => 1024 ],
				[ 'key' => 'auto_enhance', 'label' => 'Enhance', 'type' => 'boolean', 'default' => true ],
				[ 'key' => 'negative_prompt', 'label' => 'Avoid', 'type' => 'textarea', 'default' => 'watermark' ],
			],
		];
	}

	/** Invoke a private static repository helper. */
	private function invoke_defaults_helper( string $method, ...$arguments ) {
		$reflection = new ReflectionMethod( Generation_Run_Defaults::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$arguments );
	}

	/** Load a canonical document and retain the production CAS snapshot. */
	private function document_for_write( int $post_id, array &$snapshot ) {
		$reflection = new ReflectionMethod( Generation_Run_Defaults::class, 'document_for_write' );
		$reflection->setAccessible( true );
		$arguments = [ $post_id, &$snapshot ];
		return $reflection->invokeArgs( null, $arguments );
	}

	/** Apply the production overlay helper while preserving its reference arguments. */
	private function overlay( array &$effective, array &$sources, array $values, string $source ): void {
		$reflection = new ReflectionMethod( Generation_Run_Defaults::class, 'overlay' );
		$reflection->setAccessible( true );
		$arguments = [ &$effective, &$sources, $values, $source ];
		$reflection->invokeArgs( null, $arguments );
	}

	/** Store a versioned defaults document in the shared WordPress-meta shim. */
	private function store_document( int $post_id, array $entries ): void {
		$GLOBALS['worldgraph_import_journal_state']['meta'][ $post_id ][ Generation_Run_Defaults::META_KEY ] = [
			'version' => Generation_Run_Defaults::VERSION,
			'entries' => $entries,
		];
	}

	/** Extract one production method for narrow source-order assertions. */
	private function method_source( string $method ): string {
		$reflection = new ReflectionMethod( Generation_Run_Defaults::class, $method );
		$lines      = file( $reflection->getFileName() );
		$this->assertIsArray( $lines );
		return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
	}

	/** Both IDs are structural identity; visual row order has no meaning. */
	public function test_exact_pair_lookup_requires_connection_and_template_and_ignores_row_order(): void {
		$description = $this->description();
		$this->store_document(
			77,
			[
				'c:2:t:10' => [ 'connection_id' => 2, 'template_id' => 10, 'fingerprint' => $description['fingerprint'], 'values' => [ 'steps' => 42 ] ],
				'c:1:t:11' => [ 'connection_id' => 1, 'template_id' => 11, 'fingerprint' => $description['fingerprint'], 'values' => [ 'steps' => 51 ] ],
				'c:1:t:10' => [ 'connection_id' => 1, 'template_id' => 10, 'fingerprint' => $description['fingerprint'], 'values' => [ 'steps' => 31 ] ],
			]
		);

		$this->assertSame( 'c:1:t:10', Generation_Run_Defaults::pair_key( 1, 10 ) );
		$this->assertSame( [ 'steps' => 31 ], $this->invoke_defaults_helper( 'read_layer', 77, 1, 10, $description )['values'] );
		$this->assertSame( [ 'steps' => 42 ], $this->invoke_defaults_helper( 'read_layer', 77, 2, 10, $description )['values'] );
		$this->assertSame( [ 'steps' => 51 ], $this->invoke_defaults_helper( 'read_layer', 77, 1, 11, $description )['values'] );
		$this->assertSame( [], $this->invoke_defaults_helper( 'read_layer', 77, 9, 10, $description )['values'] );
	}

	/** Template, Project profile, Project, and item values overlay in that order. */
	public function test_layer_precedence_tracks_the_winning_source_and_preserves_falsey_values(): void {
		$effective = [];
		$sources   = [];
		$this->overlay( $effective, $sources, Template_Run_Controls::description_defaults( $this->description() ), 'template' );
		$this->overlay( $effective, $sources, [ 'width' => 1920 ], 'project_profile' );
		$this->overlay(
			$effective,
			$sources,
			[ 'steps' => 24, 'cfg' => 0.0, 'auto_enhance' => false, 'negative_prompt' => '' ],
			'project'
		);
		$this->overlay( $effective, $sources, [ 'steps' => 32 ], 'item' );

		$this->assertSame( 32, $effective['steps'] );
		$this->assertSame( 'item', $sources['steps'] );
		$this->assertSame( 1920, $effective['width'] );
		$this->assertSame( 'project_profile', $sources['width'] );
		$this->assertSame( 0.0, $effective['cfg'] );
		$this->assertSame( 'project', $sources['cfg'] );
		$this->assertFalse( $effective['auto_enhance'] );
		$this->assertSame( '', $effective['negative_prompt'] );

		$runtime = $this->method_source( 'runtime_values' );
		$this->assertStringContainsString( 'array_merge( self::runtime_overrides( $source_id, $template_id, $description ), $one_off )', $runtime );
	}

	/** Stored zero, false, and empty-string values survive strict revalidation. */
	public function test_read_layer_preserves_falsey_scalars(): void {
		$description = $this->description();
		$this->store_document(
			77,
			[
				'c:1:t:10' => [
					'connection_id' => 1,
					'template_id'   => 10,
					'fingerprint'   => $description['fingerprint'],
					'values'        => [ 'cfg' => 0, 'auto_enhance' => false, 'negative_prompt' => '' ],
				],
			]
		);

		$layer = $this->invoke_defaults_helper( 'read_layer', 77, 1, 10, $description );
		$this->assertSame( 'current', $layer['status'] );
		$this->assertSame( 0.0, $layer['values']['cfg'] );
		$this->assertFalse( $layer['values']['auto_enhance'] );
		$this->assertSame( '', $layer['values']['negative_prompt'] );
	}

	/** Compatible stale rows are marked revalidated; incompatible rows fail atomically. */
	public function test_stale_fingerprint_rows_are_revalidated_atomically(): void {
		$description = $this->description();
		$this->store_document(
			77,
			[
				'c:1:t:10' => [
					'connection_id' => 1,
					'template_id'   => 10,
					'fingerprint'   => str_repeat( 'b', 64 ),
					'values'        => [ 'steps' => 25 ],
				],
			]
		);

		$compatible = $this->invoke_defaults_helper( 'read_layer', 77, 1, 10, $description );
		$this->assertSame( 'revalidated', $compatible['status'] );
		$this->assertSame( [ 'steps' => 25 ], $compatible['values'] );
		$this->assertContains( 'revalidated', $compatible['warnings'] );

		$this->store_document(
			77,
			[
				'c:1:t:10' => [
					'connection_id' => 1,
					'template_id'   => 10,
					'fingerprint'   => str_repeat( 'b', 64 ),
					'values'        => [ 'steps' => 101, 'removed_control' => true ],
				],
			]
		);

		$incompatible = $this->invoke_defaults_helper( 'read_layer', 77, 1, 10, $description );
		$this->assertSame( 'incompatible', $incompatible['status'] );
		$this->assertSame( [], $incompatible['values'] );
		$this->assertContains( 'incompatible', $incompatible['warnings'] );

		$write = $this->method_source( 'write_context' );
		$this->assertStringContainsString( '! hash_equals( $current, $fingerprint )', $write );
		$this->assertStringContainsString( 'worldgraph_generation_default_fingerprint_stale', $write );
		$this->assertStringContainsString( ', 409 )', $write );
	}

	/** Reset removes one exact pair, while canonical empty storage is deleted. */
	public function test_reset_contract_preserves_other_pairs_and_deletes_empty_document(): void {
		$fingerprint = str_repeat( 'a', 64 );
		$this->store_document(
			77,
			[
				'c:2:t:10' => [ 'connection_id' => 2, 'template_id' => 10, 'fingerprint' => $fingerprint, 'values' => [ 'steps' => 42 ] ],
				'c:1:t:10' => [ 'connection_id' => 1, 'template_id' => 10, 'fingerprint' => $fingerprint, 'values' => [ 'steps' => 31 ] ],
			]
		);

		$reset_source = $this->method_source( 'reset' );
		$this->assertStringContainsString( "unset( \$document['entries'][ self::pair_key( (int) \$context['connection_id'], \$template_id ) ] );", $reset_source );

		$snapshot = [];
		$document = $this->document_for_write( 77, $snapshot );
		$this->assertSame( [ 'c:1:t:10', 'c:2:t:10' ], array_keys( $document['entries'] ), 'Stored row order is canonicalized before mutation.' );
		unset( $document['entries']['c:1:t:10'] );
		$this->assertTrue( $this->invoke_defaults_helper( 'persist_document', 77, $document, $snapshot ) );

		$stored = \WorldGraph\Utils\get_post_meta( 77, Generation_Run_Defaults::META_KEY, true );
		$this->assertSame( [ 'c:2:t:10' ], array_keys( $stored['entries'] ) );
		$this->assertSame( [ 'steps' => 42 ], $stored['entries']['c:2:t:10']['values'] );

		$snapshot = [];
		$stored   = $this->document_for_write( 77, $snapshot );
		unset( $stored['entries']['c:2:t:10'] );
		$this->assertTrue( $this->invoke_defaults_helper( 'persist_document', 77, $stored, $snapshot ) );
		$this->assertSame( '', \WorldGraph\Utils\get_post_meta( 77, Generation_Run_Defaults::META_KEY, true ) );
	}

	/** An overlapping update cannot overwrite metadata changed after its read. */
	public function test_persist_document_rejects_stale_existing_snapshot(): void {
		$fingerprint = str_repeat( 'a', 64 );
		$original    = [
			'c:1:t:10' => [ 'connection_id' => 1, 'template_id' => 10, 'fingerprint' => $fingerprint, 'values' => [ 'steps' => 31 ] ],
		];
		$this->store_document( 77, $original );

		$snapshot = [];
		$document = $this->document_for_write( 77, $snapshot );
		$document['entries']['c:1:t:10']['values']['steps'] = 32;

		$concurrent = [
			'c:1:t:10' => [ 'connection_id' => 1, 'template_id' => 10, 'fingerprint' => $fingerprint, 'values' => [ 'steps' => 45 ] ],
			'c:2:t:10' => [ 'connection_id' => 2, 'template_id' => 10, 'fingerprint' => $fingerprint, 'values' => [ 'steps' => 22 ] ],
		];
		$this->store_document( 77, $concurrent );

		$result = $this->invoke_defaults_helper( 'persist_document', 77, $document, $snapshot );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_default_conflict', $result->get_error_code() );
		$this->assertSame( [ 'version' => Generation_Run_Defaults::VERSION, 'entries' => $concurrent ], \WorldGraph\Utils\get_post_meta( 77, Generation_Run_Defaults::META_KEY, true ) );
	}

	/** A first writer uses an atomic unique insert and cannot replace a winner. */
	public function test_persist_document_rejects_first_write_race(): void {
		$fingerprint = str_repeat( 'a', 64 );
		$snapshot    = [];
		$document    = $this->document_for_write( 77, $snapshot );
		$document['entries']['c:1:t:10'] = [
			'connection_id' => 1,
			'template_id'   => 10,
			'fingerprint'   => $fingerprint,
			'values'        => [ 'steps' => 31 ],
		];

		$concurrent = [
			'c:2:t:20' => [ 'connection_id' => 2, 'template_id' => 20, 'fingerprint' => $fingerprint, 'values' => [ 'steps' => 44 ] ],
		];
		$this->store_document( 77, $concurrent );

		$result = $this->invoke_defaults_helper( 'persist_document', 77, $document, $snapshot );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_default_conflict', $result->get_error_code() );
		$this->assertSame( [ 'version' => Generation_Run_Defaults::VERSION, 'entries' => $concurrent ], \WorldGraph\Utils\get_post_meta( 77, Generation_Run_Defaults::META_KEY, true ) );
	}

	/** A stale reset cannot delete a document updated by another editor. */
	public function test_persist_document_rejects_stale_delete_snapshot(): void {
		$fingerprint = str_repeat( 'a', 64 );
		$this->store_document(
			77,
			[
				'c:1:t:10' => [ 'connection_id' => 1, 'template_id' => 10, 'fingerprint' => $fingerprint, 'values' => [ 'steps' => 31 ] ],
			]
		);
		$snapshot = [];
		$document = $this->document_for_write( 77, $snapshot );
		$document['entries'] = [];

		$concurrent = [
			'c:1:t:10' => [ 'connection_id' => 1, 'template_id' => 10, 'fingerprint' => $fingerprint, 'values' => [ 'steps' => 52 ] ],
		];
		$this->store_document( 77, $concurrent );

		$result = $this->invoke_defaults_helper( 'persist_document', 77, $document, $snapshot );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'worldgraph_generation_default_conflict', $result->get_error_code() );
		$this->assertSame( [ 'version' => Generation_Run_Defaults::VERSION, 'entries' => $concurrent ], \WorldGraph\Utils\get_post_meta( 77, Generation_Run_Defaults::META_KEY, true ) );
	}
}
}
