<?php
/**
 * Generation media-import crash recovery contracts.
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

	function get_post_thumbnail_id( $post_id ): int {
		$post_id = is_object( $post_id ) ? $post_id->ID : $post_id;
		return (int) ( $GLOBALS['worldgraph_import_journal_state']['thumbnails'][ (int) $post_id ] ?? 0 );
	}

	function set_post_thumbnail( $post_id, $attachment_id ): bool {
		$GLOBALS['worldgraph_import_journal_state']['thumbnails'][ (int) $post_id ] = (int) $attachment_id;
		return true;
	}

	function delete_post_thumbnail( $post_id ): bool {
		unset( $GLOBALS['worldgraph_import_journal_state']['thumbnails'][ (int) $post_id ] );
		return true;
	}

	function get_post_type( $post_id ) {
		return $GLOBALS['worldgraph_import_journal_state']['post_types'][ (int) $post_id ] ?? false;
	}

	function get_post( $post_id ) {
		return isset( $GLOBALS['worldgraph_import_journal_state']['post_types'][ (int) $post_id ] ) ? (object) [ 'ID' => (int) $post_id ] : null;
	}

	function wp_delete_post( $post_id, $force_delete = false ) {
		$post = get_post( $post_id );
		unset( $GLOBALS['worldgraph_import_journal_state']['post_types'][ (int) $post_id ] );
		return $post;
	}

	function wp_delete_attachment( $post_id, $force_delete = false ) {
		return wp_delete_post( $post_id, $force_delete );
	}

	function get_temp_dir(): string {
		return rtrim( sys_get_temp_dir(), '/\\' ) . '/';
	}

	function wp_delete_file( $file ): void {
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WordPress-free test shim.
			unlink( $file );
		}
	}

	function absint( $value ): int {
		return abs( (int) $value );
	}
}

namespace {

use PHPUnit\Framework\TestCase;
use WorldGraph\Utils\Asset_Generator;

require_once dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php';

/** Import side effects remain recoverable until a job is durably complete. */
class Test_Generation_Import_Journal extends TestCase {

	protected function setUp(): void {
		$GLOBALS['worldgraph_import_journal_state'] = [
			'meta'        => [],
			'post_types'  => [],
			'thumbnails'  => [],
		];
	}

	/** Recovery restores links and removes every journal-owned object. */
	public function test_recovery_executes_the_journal_and_then_clears_it(): void {
		$temp_file = tempnam( sys_get_temp_dir(), 'worldgraph-generated-media' );
		$legacy_temp_file = tempnam( sys_get_temp_dir(), 'worldgraph-videodraft-media' );
		$this->assertNotFalse( $temp_file );
		$this->assertNotFalse( $legacy_temp_file );
		$state =& $GLOBALS['worldgraph_import_journal_state'];
		$state['post_types'] = [ 9 => 'attachment', 21 => 'attachment', 22 => 'attachment', 31 => 'worldgraph_asset' ];
		$state['thumbnails'][11] = 21;
		$state['meta'][11][Asset_Generator::GALLERY_META] = [ 8, 21, 22 ];
		$state['meta'][90][Asset_Generator::IMPORT_JOURNAL_META] = [
			'post_id'                => 11,
			'previous_thumbnail_id'  => 9,
			'featured_attachment_id' => 21,
			'attachment_ids'          => [ 21, 22 ],
			'asset_ids'               => [ 31 ],
			'temp_files'              => [ $temp_file, $legacy_temp_file ],
		];
		$state['meta'][90]['_worldgraph_gen_attachment_ids'] = [ 21, 22 ];

		$this->assertTrue( Asset_Generator::recover_import_journal( 90 ) );
		$this->assertSame( 9, $state['thumbnails'][11] );
		$this->assertSame( [ 8 ], $state['meta'][11][Asset_Generator::GALLERY_META] );
		$this->assertArrayNotHasKey( 21, $state['post_types'] );
		$this->assertArrayNotHasKey( 22, $state['post_types'] );
		$this->assertArrayNotHasKey( 31, $state['post_types'] );
		$this->assertArrayNotHasKey( Asset_Generator::IMPORT_JOURNAL_META, $state['meta'][90] );
		$this->assertFileDoesNotExist( $temp_file );
		$this->assertFileDoesNotExist( $legacy_temp_file );
	}

	/** A later editor thumbnail choice is not overwritten during recovery. */
	public function test_recovery_preserves_a_newer_thumbnail_choice(): void {
		$state =& $GLOBALS['worldgraph_import_journal_state'];
		$state['post_types'] = [ 9 => 'attachment', 21 => 'attachment', 77 => 'attachment' ];
		$state['thumbnails'][11] = 77;
		$state['meta'][90][Asset_Generator::IMPORT_JOURNAL_META] = [
			'post_id'                => 11,
			'previous_thumbnail_id'  => 9,
			'featured_attachment_id' => 21,
			'attachment_ids'          => [ 21 ],
		];

		$this->assertTrue( Asset_Generator::recover_import_journal( 90 ) );
		$this->assertSame( 77, $state['thumbnails'][11] );
		$this->assertArrayNotHasKey( 21, $state['post_types'] );
	}

	/** Committing clears recovery metadata without deleting imported media. */
	public function test_commit_keeps_imported_objects(): void {
		$state =& $GLOBALS['worldgraph_import_journal_state'];
		$state['post_types'][21] = 'attachment';
		$state['meta'][90][Asset_Generator::IMPORT_JOURNAL_META] = [
			'post_id'       => 11,
			'attachment_ids' => [ 21 ],
		];

		$this->assertTrue( Asset_Generator::commit_import_journal( 90 ) );
		$this->assertSame( 'attachment', $state['post_types'][21] );
		$this->assertArrayNotHasKey( Asset_Generator::IMPORT_JOURNAL_META, $state['meta'][90] );
	}

	/** Newly created media is journaled before later import side effects. */
	public function test_import_journals_thumbnail_attachment_and_temp_file_before_use(): void {
		$source = $this->asset_generator_source();
		$import = $this->method_source( $source, 'public static function import_completed_job', 'private static function sideload' );
		$side   = $this->method_source( $source, 'private static function sideload', 'private static function delete_temp_media' );
		$stream = $this->method_source( $source, 'private static function download_to_file', 'private static function validate_image_bytes' );

		$this->assertStringContainsString( "'previous_thumbnail_id'  => \$previous_thumbnail_id", $source );
		$this->assertTrue( strpos( $import, 'self::begin_import_journal' ) < strpos( $import, 'self::download_to_file' ) );
		$this->assertTrue( strpos( $side, 'wp_insert_attachment' ) < strpos( $side, 'self::journal_attachment' ) );
		$this->assertTrue( strpos( $side, 'self::journal_attachment' ) < strpos( $side, 'wp_generate_attachment_metadata' ) );
		$this->assertTrue( strpos( $import, 'self::journal_featured_attachment' ) < strpos( $import, 'set_post_thumbnail' ) );
		$this->assertTrue( strpos( $stream, 'wp_tempnam' ) < strpos( $stream, 'self::journal_temp_file' ) );
		$this->assertTrue( strpos( $stream, 'self::journal_temp_file' ) < strpos( $stream, 'wp_safe_remote_get' ) );
	}

	/** WordPress receives a variable for the sideload argument it accepts by reference. */
	public function test_sideload_passes_a_named_file_array_to_wordpress(): void {
		$source   = $this->asset_generator_source();
		$sideload = $this->method_source( $source, 'private static function sideload', 'private static function delete_temp_media' );

		$this->assertStringContainsString( '$sideload_file = [', $sideload );
		$this->assertStringContainsString( 'wp_handle_sideload( $sideload_file,', $sideload );
		$this->assertStringNotContainsString( 'wp_handle_sideload( [', $sideload );
	}

	/** Recovery reverses links and owned records before clearing the journal. */
	public function test_recovery_rolls_back_all_journaled_side_effects(): void {
		$source   = $this->asset_generator_source();
		$recovery = $this->method_source( $source, 'public static function recover_import_journal', 'public static function commit_import_journal' );

		$this->assertStringContainsString( '$featured_attachment_id === (int) get_post_thumbnail_id( $post_id )', $recovery );
		$this->assertStringContainsString( 'array_diff( $current_gallery, $attachment_ids )', $recovery );
		$this->assertStringContainsString( 'wp_delete_post( $asset_id, true )', $recovery );
		$this->assertStringContainsString( 'wp_delete_attachment( $attachment_id, true )', $recovery );
		$this->assertStringContainsString( 'self::delete_journal_temp_files( $journal )', $recovery );
		$this->assertTrue( strpos( $recovery, 'wp_delete_attachment' ) < strrpos( $recovery, 'delete_post_meta( $job_id, self::IMPORT_JOURNAL_META )' ) );
		$this->assertStringContainsString( "'worldgraph_gen_cleanup_failed'", $source );
	}

	/** Stale and explicit retries reconcile imports before running them again. */
	public function test_batch_recovers_before_retry_and_commits_after_final_status(): void {
		$source   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/generation-batch.php' );
		$stale    = $this->method_source( $source, 'private static function recover_stale_claims', 'private static function submit_queued_jobs' );
		$retry    = $this->method_source( $source, 'private static function retry_media_imports', 'private static function complete_job' );
		$complete = $this->method_source( $source, 'private static function complete_job', 'private static function store_job_error' );

		$this->assertStringContainsString( "'importing' === \$status", $stale );
		$this->assertStringContainsString( 'if ( ! $recovered )', $stale );
		$this->assertStringContainsString( 'Asset_Generator::recover_import_journal', $stale );
		$this->assertTrue( strpos( $retry, 'Asset_Generator::recover_import_journal' ) < strpos( $retry, 'self::complete_job' ) );
		$this->assertTrue( strpos( $complete, "self::persist_job_meta( \$job_id, '_worldgraph_gen_attachment_ids'" ) < strpos( $complete, "self::persist_job_status( \$job_id, \$status )" ) );
		$this->assertTrue( strpos( $complete, "update_post_meta( \$job_id, '_worldgraph_gen_status', 'import_cleanup' )" ) < strpos( $complete, 'Asset_Generator::commit_import_journal' ) );
		$this->assertTrue( strpos( $complete, 'Asset_Generator::commit_import_journal' ) < strpos( $complete, "self::persist_job_status( \$job_id, \$status )" ) );
		$this->assertStringContainsString( 'private static function finalize_media_imports', $source );
	}

	/** Read the asset generator under test. */
	private function asset_generator_source(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/includes/utils/class-asset-generator.php' );
	}

	/** Return source between two method declarations. */
	private function method_source( string $source, string $start_marker, string $end_marker ): string {
		$start = strpos( $source, $start_marker );
		$end   = strpos( $source, $end_marker, false === $start ? 0 : $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		return substr( $source, (int) $start, (int) $end - (int) $start );
	}
}
}
