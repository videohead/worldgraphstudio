<?php
/**
 * Bounded ZIP reader used for EPUB, DOCX, and ODT story sources.
 *
 * @package WorldGraphStoryIO
 */

namespace WorldGraphStoryIO;

defined( 'ABSPATH' ) || exit;

/** Read selected ZIP entries without expanding an archive onto disk. */
class Archive_Reader {
	private const MAX_ENTRIES           = 2000;
	private const MAX_ENTRY_BYTES       = 4_194_304;
	private const MAX_UNCOMPRESSED_BYTES = 33_554_432;
	private const MAX_COMPRESSION_RATIO = 150;

	/** @var string */
	private $data;

	/** @var array<string, array<string, int>> */
	private $entries = [];

	/** @param string $data Raw ZIP bytes. */
	private function __construct( string $data ) {
		$this->data = $data;
	}

	/** Parse and validate one bounded ZIP archive. */
	public static function from_file( string $path ) {
		$data = file_get_contents( $path );
		if ( false === $data || ! str_starts_with( $data, "PK" ) ) {
			return new \WP_Error( 'worldgraph_story_archive_invalid', __( 'The uploaded book archive is not a valid ZIP-based document.', 'worldgraph' ) );
		}

		$reader = new self( $data );
		$result = $reader->parse_directory();
		return is_wp_error( $result ) ? $result : $reader;
	}

	/** Return normalized entry names in archive order. */
	public function names(): array {
		return array_keys( $this->entries );
	}

	/** Return one decompressed entry or a validation error. */
	public function get( string $name ) {
		$name = self::normalize_name( $name );
		if ( '' === $name || ! isset( $this->entries[ $name ] ) ) {
			return new \WP_Error( 'worldgraph_story_archive_entry_missing', __( 'A required document entry is missing from the uploaded archive.', 'worldgraph' ) );
		}

		$entry  = $this->entries[ $name ];
		$offset = $entry['offset'];
		if ( $offset < 0 || $offset + 30 > strlen( $this->data ) || "PK\x03\x04" !== substr( $this->data, $offset, 4 ) ) {
			return new \WP_Error( 'worldgraph_story_archive_invalid', __( 'The uploaded archive contains an invalid local entry header.', 'worldgraph' ) );
		}

		$name_length  = self::u16( $this->data, $offset + 26 );
		$extra_length = self::u16( $this->data, $offset + 28 );
		$data_offset  = $offset + 30 + $name_length + $extra_length;
		$compressed   = substr( $this->data, $data_offset, $entry['compressed'] );
		if ( strlen( $compressed ) !== $entry['compressed'] ) {
			return new \WP_Error( 'worldgraph_story_archive_invalid', __( 'The uploaded archive ended before an entry was complete.', 'worldgraph' ) );
		}

		if ( 0 === $entry['method'] ) {
			$output = $compressed;
		} elseif ( 8 === $entry['method'] ) {
			$output = gzinflate( $compressed, self::MAX_ENTRY_BYTES );
			if ( false === $output ) {
				return new \WP_Error( 'worldgraph_story_archive_inflate_failed', __( 'A compressed document entry could not be read.', 'worldgraph' ) );
			}
		} else {
			return new \WP_Error( 'worldgraph_story_archive_method_unsupported', __( 'The uploaded archive uses an unsupported compression method.', 'worldgraph' ) );
		}

		if ( strlen( $output ) !== $entry['uncompressed'] || strlen( $output ) > self::MAX_ENTRY_BYTES ) {
			return new \WP_Error( 'worldgraph_story_archive_entry_too_large', __( 'A document entry exceeds the safe extraction limit.', 'worldgraph' ) );
		}

		return $output;
	}

	/** Parse the central directory and enforce expansion limits before reads. */
	private function parse_directory() {
		$search_start = max( 0, strlen( $this->data ) - 65_557 );
		$eocd         = strrpos( substr( $this->data, $search_start ), "PK\x05\x06" );
		if ( false === $eocd ) {
			return new \WP_Error( 'worldgraph_story_archive_invalid', __( 'The uploaded archive has no valid directory.', 'worldgraph' ) );
		}
		$eocd += $search_start;

		$entry_count = self::u16( $this->data, $eocd + 10 );
		$directory   = self::u32( $this->data, $eocd + 16 );
		if ( $entry_count > self::MAX_ENTRIES || $directory < 0 || $directory >= strlen( $this->data ) ) {
			return new \WP_Error( 'worldgraph_story_archive_too_large', __( 'The uploaded archive exceeds the safe entry limit.', 'worldgraph' ) );
		}

		$offset = $directory;
		$total  = 0;
		for ( $index = 0; $index < $entry_count; $index++ ) {
			if ( $offset < 0 || $offset + 46 > strlen( $this->data ) || "PK\x01\x02" !== substr( $this->data, $offset, 4 ) ) {
				return new \WP_Error( 'worldgraph_story_archive_invalid', __( 'The uploaded archive directory is malformed.', 'worldgraph' ) );
			}

			$flags        = self::u16( $this->data, $offset + 8 );
			$method       = self::u16( $this->data, $offset + 10 );
			$compressed   = self::u32( $this->data, $offset + 20 );
			$uncompressed = self::u32( $this->data, $offset + 24 );
			$name_length  = self::u16( $this->data, $offset + 28 );
			$extra_length = self::u16( $this->data, $offset + 30 );
			$comment_len  = self::u16( $this->data, $offset + 32 );
			$local_offset = self::u32( $this->data, $offset + 42 );
			$raw_name      = substr( $this->data, $offset + 46, $name_length );
			$name          = self::normalize_name( $raw_name );
			$is_directory  = str_ends_with( str_replace( '\\', '/', $raw_name ), '/' );

			if ( '' === $name ) {
				return new \WP_Error( 'worldgraph_story_archive_path_invalid', __( 'The uploaded archive contains an unsafe entry path.', 'worldgraph' ) );
			}

			if ( 0 !== ( $flags & 1 ) ) {
				return new \WP_Error( 'worldgraph_story_archive_encrypted', __( 'Password-protected story archives are not supported.', 'worldgraph' ) );
			}
			if ( ! in_array( $method, [ 0, 8 ], true ) ) {
				return new \WP_Error( 'worldgraph_story_archive_method_unsupported', __( 'The uploaded archive uses an unsupported compression method.', 'worldgraph' ) );
			}
			if ( $uncompressed > self::MAX_ENTRY_BYTES ) {
				return new \WP_Error( 'worldgraph_story_archive_entry_too_large', __( 'A document entry exceeds the safe extraction limit.', 'worldgraph' ) );
			}
			if ( $compressed > 0 && $uncompressed / $compressed > self::MAX_COMPRESSION_RATIO ) {
				return new \WP_Error( 'worldgraph_story_archive_ratio_invalid', __( 'The uploaded archive has an unsafe compression ratio.', 'worldgraph' ) );
			}

			$total += $uncompressed;
			if ( $total > self::MAX_UNCOMPRESSED_BYTES ) {
				return new \WP_Error( 'worldgraph_story_archive_too_large', __( 'The uploaded archive expands beyond the safe size limit.', 'worldgraph' ) );
			}

			if ( ! $is_directory ) {
				$this->entries[ $name ] = [
					'flags'        => $flags,
					'method'       => $method,
					'compressed'   => $compressed,
					'uncompressed' => $uncompressed,
					'offset'       => $local_offset,
				];
			}

			$offset += 46 + $name_length + $extra_length + $comment_len;
		}

		return true;
	}

	/** Reject traversal, absolute, NUL, and Windows-drive entry names. */
	private static function normalize_name( string $name ): string {
		$name = str_replace( '\\', '/', trim( $name ) );
		if ( '' === $name || str_contains( $name, "\0" ) || str_starts_with( $name, '/' ) || preg_match( '/^[A-Za-z]:/', $name ) ) {
			return '';
		}

		$parts = [];
		foreach ( explode( '/', $name ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return '';
			}
			$parts[] = $part;
		}

		return implode( '/', $parts );
	}

	private static function u16( string $data, int $offset ): int {
		$value = unpack( 'vvalue', substr( $data, $offset, 2 ) );
		return (int) ( $value['value'] ?? -1 );
	}

	private static function u32( string $data, int $offset ): int {
		$value = unpack( 'Vvalue', substr( $data, $offset, 4 ) );
		return (int) ( $value['value'] ?? -1 );
	}
}
