<?php
/**
 * Private ring-buffer log for provider generation requests, so failures can
 * be diagnosed before the WP-cron generation batch returns a result.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Generation_Log {
	const OPTION = 'worldgraph_gen_log';
	const MAX_ENTRIES = 200;
	const LOG_SUBDIR = 'worldgraph/logs';
	const LOG_FILENAME = 'generation.log';

	/** Per-Job event journal, so a Job record carries its own provider history. */
	const EVENTS_META = '_worldgraph_gen_events';

	/** Journal entries retained per Job. */
	const MAX_JOB_EVENTS = 100;

	/**
	 * Job record the current worker step belongs to, so provider adapters that
	 * only know a provider-side ID still log against the right Job.
	 *
	 * @var int
	 */
	private static int $current_job = 0;

	/**
	 * Attribute subsequent log entries to a Job record. Pass 0 to clear.
	 *
	 * @param int $job_id worldgraph_gen post ID.
	 */
	public static function set_current_job( int $job_id ): void {
		self::$current_job = max( 0, $job_id );
	}

	/**
	 * Append a log entry.
	 *
	 * @param string $level         'info', 'error', or 'debug'.
	 * @param string $source        Short origin tag, e.g. 'local_comfyui', 'comfy_cloud_mcp', 'generation_batch'.
	 * @param string $message       Human-readable message.
	 * @param array  $context       Optional structured detail (request/response payloads, etc).
	 * @param string $job_id        Optional World Graph Studio or provider job ID.
	 * @param int    $connection_id Optional parent worldgraph_conn post ID.
	 */
	public static function add( string $level, string $source, string $message, array $context = [], string $job_id = '', int $connection_id = 0 ): void {
		$generation_id = self::$current_job ?: ( ctype_digit( $job_id ) ? (int) $job_id : 0 );
		$entry = [
			'time'          => current_time( 'mysql' ),
			'level'         => $level,
			'source'        => $source,
			'job_id'        => $job_id,
			'generation_id' => $generation_id,
			'connection_id' => $connection_id,
			'message'       => $message,
			'context'       => $context,
		];

		$entries   = self::all();
		$entries[] = $entry;

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		self::write_entries( $entries );
		self::record_job_event( $generation_id, $entry );
	}

	/**
	 * Persist an event on its Job record so the Job survives the ring buffer.
	 *
	 * @param int   $generation_id worldgraph_gen post ID.
	 * @param array $entry         Log entry.
	 */
	private static function record_job_event( int $generation_id, array $entry ): void {
		if ( ! $generation_id || ! function_exists( 'get_post_type' ) || 'worldgraph_gen' !== get_post_type( $generation_id ) ) {
			return;
		}

		$events = get_post_meta( $generation_id, self::EVENTS_META, true );
		$events = is_array( $events ) ? $events : [];
		$events[] = $entry;

		if ( count( $events ) > self::MAX_JOB_EVENTS ) {
			$events = array_slice( $events, -self::MAX_JOB_EVENTS );
		}

		update_post_meta( $generation_id, self::EVENTS_META, wp_slash( $events ) );
	}

	/**
	 * The event journal stored on one Job record, oldest first.
	 *
	 * @param int $generation_id worldgraph_gen post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_job( int $generation_id ): array {
		$events = get_post_meta( $generation_id, self::EVENTS_META, true );

		return is_array( $events ) ? $events : [];
	}

	/**
	 * Recent activity for one Connection that no Job owns, such as template
	 * catalog syncs and capability probes.
	 *
	 * @param int $connection_id worldgraph_conn post ID.
	 * @param int $limit         Maximum entries to return, newest first.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_connection( int $connection_id, int $limit = 25 ): array {
		$entries = array_filter(
			self::all( $connection_id ),
			static function ( array $entry ): bool {
				return 0 === (int) ( $entry['generation_id'] ?? 0 );
			}
		);

		return array_slice( array_reverse( array_values( $entries ) ), 0, max( 1, $limit ) );
	}

	/**
	 * All log entries, oldest first.
	 *
	 * @param int $connection_id Optional: only entries for this Connection post ID.
	 * @return array
	 */
	public static function all( int $connection_id = 0 ): array {
		$entries = self::read_entries();

		if ( $connection_id > 0 ) {
			$entries = array_values( array_filter( $entries, static function ( $entry ) use ( $connection_id ) {
				return (int) ( $entry['connection_id'] ?? 0 ) === $connection_id;
			} ) );
		}

		return $entries;
	}

	/**
	 * Clear the log.
	 */
	public static function clear(): void {
		$file = self::legacy_log_file_path();
		if ( '' !== $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
		delete_option( self::OPTION );
	}

	/**
	 * Resolve the former public filesystem path without creating it.
	 *
	 * This path is retained only so installations upgraded from a file-backed
	 * log can migrate and remove that file. New log data is never written here.
	 *
	 * @return string
	 */
	private static function legacy_log_file_path(): string {
		$uploads = wp_upload_dir();
		$basedir = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
		if ( '' === $basedir ) {
			return '';
		}

		return trailingslashit( $basedir ) . self::LOG_SUBDIR . '/' . self::LOG_FILENAME;
	}

	/**
	 * Read entries from the non-autoloaded database option and, once, migrate
	 * entries from the former public JSONL file.
	 *
	 * The old file is removed only after the merged ring buffer can be read back
	 * from the database. If persistence fails, the file remains available for a
	 * later migration attempt.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function read_entries(): array {
		$stored  = get_option( self::OPTION, [] );
		$entries = self::normalize_entries( is_array( $stored ) ? $stored : [] );
		$file    = self::legacy_log_file_path();

		if ( '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return $entries;
		}

		$legacy  = self::read_legacy_file_entries( $file );
		$entries = self::merge_entries( $entries, $legacy );

		if ( self::write_entries( $entries ) ) {
			wp_delete_file( $file );
		}

		return $entries;
	}

	/**
	 * Read valid entries from the former JSONL file.
	 *
	 * @param string $file Absolute legacy file path.
	 * @return array<int, array<string, mixed>>
	 */
	private static function read_legacy_file_entries( string $file ): array {
		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $lines ) ) {
			return [];
		}

		$entries = [];
		foreach ( $lines as $line ) {
			$decoded = json_decode( (string) $line, true );
			if ( is_array( $decoded ) ) {
				$entries[] = $decoded;
			}
		}

		return self::normalize_entries( $entries );
	}

	/**
	 * Merge two ordered entry sets without duplicating identical records.
	 *
	 * Existing database records are ordered first, followed by records from the
	 * former file. The final result retains only the newest ring-buffer window.
	 *
	 * @param array<int, array<string, mixed>> $stored Database entries.
	 * @param array<int, array<string, mixed>> $legacy Legacy file entries.
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_entries( array $stored, array $legacy ): array {
		$merged = [];

		foreach ( array_merge( $stored, $legacy ) as $entry ) {
			if ( in_array( $entry, $merged, true ) ) {
				continue;
			}

			$merged[] = $entry;
		}

		return self::normalize_entries( $merged );
	}

	/**
	 * Keep only array records within the bounded ring-buffer window.
	 *
	 * @param array<int, mixed> $entries Candidate entries.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_entries( array $entries ): array {
		$entries = array_values( array_filter( $entries, 'is_array' ) );

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		return $entries;
	}

	/**
	 * Persist log entries in a bounded, non-autoloaded database option.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries ordered oldest-first.
	 * @return bool Whether the intended value is durably available.
	 */
	private static function write_entries( array $entries ): bool {
		$entries = self::normalize_entries( $entries );
		$missing = new \stdClass();
		$stored  = get_option( self::OPTION, $missing );

		if ( $missing === $stored ) {
			$written = add_option( self::OPTION, $entries, '', false );
		} else {
			$written = update_option( self::OPTION, $entries, false );
		}

		if ( $written ) {
			return true;
		}

		return $entries === get_option( self::OPTION, $missing );
	}
}
