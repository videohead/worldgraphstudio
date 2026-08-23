<?php
/**
 * Generation log private-storage contracts.
 *
 * @package WorldGraph
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Generation_Log;

require_once dirname( __DIR__ ) . '/includes/utils/generation-log.php';

/** Provider request diagnostics remain bounded and outside public uploads. */
class Test_Generation_Log_Storage extends TestCase {

	/** The canonical store is a bounded, non-autoloaded WordPress option. */
	public function test_log_uses_only_non_autoloaded_database_storage_for_new_entries(): void {
		$source = $this->source();
		$write  = $this->method_source( $source, 'private static function write_entries', "\n\t}\n}" );

		$this->assertStringContainsString( "add_option( self::OPTION, \$entries, '', false )", $write );
		$this->assertStringContainsString( 'update_option( self::OPTION, $entries, false )', $write );
		$this->assertStringNotContainsString( 'file_put_contents(', $source );
		$this->assertStringNotContainsString( 'wp_mkdir_p(', $source );
	}

	/** The public JSONL file is removed only after its entries are persisted. */
	public function test_legacy_public_file_is_migrated_before_it_is_deleted(): void {
		$source = $this->source();
		$read   = $this->method_source( $source, 'private static function read_entries', 'private static function read_legacy_file_entries' );

		$this->assertStringContainsString( 'self::legacy_log_file_path()', $read );
		$this->assertStringContainsString( 'self::merge_entries( $entries, $legacy )', $read );
		$this->assertStringContainsString( 'if ( self::write_entries( $entries ) )', $read );
		$this->assertTrue( strpos( $read, 'self::write_entries' ) < strpos( $read, 'wp_delete_file' ) );
	}

	/** Purging clears both the private option and the former public file. */
	public function test_clear_removes_current_and_legacy_storage(): void {
		$source = $this->source();
		$clear  = $this->method_source( $source, 'public static function clear', 'private static function legacy_log_file_path' );

		$this->assertStringContainsString( 'delete_option( self::OPTION )', $clear );
		$this->assertStringContainsString( 'wp_delete_file( $file )', $clear );
	}

	/** Normalization drops invalid records and retains the newest 200. */
	public function test_ring_buffer_normalization_retains_only_latest_entries(): void {
		$entries   = array_merge( [ 'invalid' ], array_map( static fn( int $id ): array => [ 'id' => $id ], range( 1, 205 ) ) );
		$normalized = $this->invoke_private( 'normalize_entries', [ $entries ] );

		$this->assertCount( Generation_Log::MAX_ENTRIES, $normalized );
		$this->assertSame( [ 'id' => 6 ], $normalized[0] );
		$this->assertSame( [ 'id' => 205 ], $normalized[ Generation_Log::MAX_ENTRIES - 1 ] );
	}

	/** Migration does not duplicate records already held in the option. */
	public function test_legacy_merge_deduplicates_identical_records(): void {
		$first  = [ 'time' => '2026-01-01 00:00:00', 'message' => 'first' ];
		$second = [ 'time' => '2026-01-01 00:00:01', 'message' => 'second' ];
		$merged = $this->invoke_private( 'merge_entries', [ [ $first ], [ $first, $second ] ] );

		$this->assertSame( [ $first, $second ], $merged );
	}

	/** Read the generation log implementation. */
	private function source(): string {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/utils/generation-log.php' );
		$this->assertNotFalse( $source );

		return (string) $source;
	}

	/** Return source between two markers. */
	private function method_source( string $source, string $start_marker, string $end_marker ): string {
		$start = strpos( $source, $start_marker );
		$end   = strpos( $source, $end_marker, false === $start ? 0 : $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );

		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	/** Invoke one pure private helper without bootstrapping WordPress. */
	private function invoke_private( string $method_name, array $arguments ): array {
		$method = new ReflectionMethod( Generation_Log::class, $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( null, $arguments );
	}
}
