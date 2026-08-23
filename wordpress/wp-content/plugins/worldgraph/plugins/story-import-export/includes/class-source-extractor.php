<?php
/**
 * Text extraction for persisted story-source uploads.
 *
 * @package WorldGraphStoryIO
 */

namespace WorldGraphStoryIO;

defined( 'ABSPATH' ) || exit;

/** Convert supported story files into bounded UTF-8 text. */
class Source_Extractor {
	public const MAX_UPLOAD_BYTES = 20_971_520;
	public const MAX_TEXT_CHARS   = 500_000;

	private const TEXT_EXTENSIONS = [
		'txt',
		'text',
		'md',
		'markdown',
		'fountain',
	];

	/** Extract a source attachment already stored in the WordPress uploads tree. */
	public function extract_attachment( int $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || '' === $path ) {
			return new \WP_Error( 'worldgraph_story_source_missing', __( 'The stored story source file could not be found.', 'worldgraph' ) );
		}

		$filename = get_the_title( $attachment_id );
		$source   = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( is_string( $source ) && '' !== $source ) {
			$filename = wp_basename( $source );
		}

		return $this->extract_file( $path, (string) $filename );
	}

	/** Extract one supported path without moving or deleting it. */
	public function extract_file( string $path, string $filename = '' ) {
		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			return new \WP_Error( 'worldgraph_story_source_unreadable', __( 'The story source file is not readable.', 'worldgraph' ) );
		}

		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			return new \WP_Error( 'worldgraph_story_source_empty', __( 'The story source file is empty.', 'worldgraph' ) );
		}
		if ( $size > self::MAX_UPLOAD_BYTES ) {
			return new \WP_Error( 'worldgraph_story_source_too_large', __( 'Story source files may not exceed 20 MB.', 'worldgraph' ) );
		}

		$filename  = '' !== $filename ? $filename : wp_basename( $path );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$allowed   = array_merge( self::TEXT_EXTENSIONS, [ 'json', 'rtf', 'pdf', 'epub', 'docx', 'odt' ] );
		if ( ! in_array( $extension, $allowed, true ) ) {
			return new \WP_Error(
				'worldgraph_story_source_type_unsupported',
				__( 'Choose a JSON, plain text, Markdown, Fountain, RTF, PDF, EPUB, DOCX, or ODT story file.', 'worldgraph' )
			);
		}

		if ( in_array( $extension, self::TEXT_EXTENSIONS, true ) || 'json' === $extension || 'rtf' === $extension ) {
			$raw = file_get_contents( $path );
			if ( false === $raw ) {
				return new \WP_Error( 'worldgraph_story_source_unreadable', __( 'The story source file could not be read.', 'worldgraph' ) );
			}
			$text = 'rtf' === $extension ? $this->extract_rtf( $raw ) : $raw;
		} elseif ( 'pdf' === $extension ) {
			$text = $this->extract_pdf( $path );
		} elseif ( 'epub' === $extension ) {
			$text = $this->extract_epub( $path );
		} elseif ( 'docx' === $extension ) {
			$text = $this->extract_docx( $path );
		} else {
			$text = $this->extract_odt( $path );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$text       = self::normalize_text( (string) $text );
		$characters = mb_strlen( $text, 'UTF-8' );
		$is_json    = 'json' === $extension && $this->is_canonical_json( $text );
		if ( $characters < 20 ) {
			return new \WP_Error( 'worldgraph_story_source_no_text', __( 'No usable story text was found in the uploaded file.', 'worldgraph' ) );
		}
		// Canonical JSON follows the upload-size boundary and never enters the LLM
		// prompt path. Keep the extracted-text cap for manuscripts and arbitrary
		// JSON so existing large portable projects remain importable.
		if ( ! $is_json && $characters > self::MAX_TEXT_CHARS ) {
			return new \WP_Error( 'worldgraph_story_source_text_too_large', __( 'The extracted manuscript exceeds the current 500,000-character processing limit.', 'worldgraph' ) );
		}

		return [
			'text'       => $text,
			'format'     => $extension,
			'filename'   => sanitize_file_name( $filename ),
			'is_json'    => $is_json,
			'characters' => $characters,
		];
	}

	/** Whether a JSON string already resembles the canonical interchange contract. */
	public function is_canonical_json( string $text ): bool {
		$document = json_decode( $text, true );
		if ( ! is_array( $document ) ) {
			return false;
		}

		foreach ( [ 'project', 'world', 'characters', 'locations', 'props', 'scenes', 'shots', 'sequence' ] as $section ) {
			if ( ! array_key_exists( $section, $document ) ) {
				return false;
			}
		}

		return true;
	}

	/** Read an EPUB in package spine order. */
	private function extract_epub( string $path ) {
		$archive = Archive_Reader::from_file( $path );
		if ( is_wp_error( $archive ) ) {
			return $archive;
		}

		$container = $archive->get( 'META-INF/container.xml' );
		if ( is_wp_error( $container ) ) {
			return new \WP_Error( 'worldgraph_story_epub_container_missing', __( 'The EPUB has no package container.', 'worldgraph' ) );
		}

		$container_xml = $this->load_xml( $container );
		if ( is_wp_error( $container_xml ) ) {
			return $container_xml;
		}
		$xpath = new \DOMXPath( $container_xml );
		$nodes = $xpath->query( '//*[local-name()="rootfile"]/@full-path' );
		$opf   = $nodes && $nodes->length ? (string) $nodes->item( 0 )->nodeValue : '';
		$opf   = self::normalize_archive_path( $opf );
		if ( '' === $opf ) {
			return new \WP_Error( 'worldgraph_story_epub_package_missing', __( 'The EPUB package path is invalid.', 'worldgraph' ) );
		}

		$package = $archive->get( $opf );
		if ( is_wp_error( $package ) ) {
			return new \WP_Error( 'worldgraph_story_epub_package_missing', __( 'The EPUB package document is missing.', 'worldgraph' ) );
		}
		$package_xml = $this->load_xml( $package );
		if ( is_wp_error( $package_xml ) ) {
			return $package_xml;
		}

		$xpath    = new \DOMXPath( $package_xml );
		$manifest = [];
		foreach ( $xpath->query( '//*[local-name()="manifest"]/*[local-name()="item"]' ) as $item ) {
			$id   = (string) $item->attributes->getNamedItem( 'id' )?->nodeValue;
			$href = (string) $item->attributes->getNamedItem( 'href' )?->nodeValue;
			$type = (string) $item->attributes->getNamedItem( 'media-type' )?->nodeValue;
			if ( '' !== $id && ( str_contains( $type, 'html' ) || preg_match( '/\.(?:xhtml|html|htm)$/i', $href ) ) ) {
				$href_path       = strtok( $href, '#' );
				$manifest[ $id ] = self::resolve_archive_path( $opf, rawurldecode( false === $href_path ? '' : $href_path ) );
			}
		}

		$chapters = [];
		foreach ( $xpath->query( '//*[local-name()="spine"]/*[local-name()="itemref"]' ) as $itemref ) {
			$idref = (string) $itemref->attributes->getNamedItem( 'idref' )?->nodeValue;
			if ( isset( $manifest[ $idref ] ) && '' !== $manifest[ $idref ] ) {
				$chapters[] = $manifest[ $idref ];
			}
		}
		if ( empty( $chapters ) ) {
			$chapters = array_values( array_filter( $manifest ) );
		}

		return $this->join_markup_entries( $archive, array_values( array_unique( $chapters ) ) );
	}

	/** Read visible paragraphs from a DOCX archive. */
	private function extract_docx( string $path ) {
		$archive = Archive_Reader::from_file( $path );
		if ( is_wp_error( $archive ) ) {
			return $archive;
		}
		$xml = $archive->get( 'word/document.xml' );
		if ( is_wp_error( $xml ) ) {
			return new \WP_Error( 'worldgraph_story_docx_document_missing', __( 'The DOCX has no readable document body.', 'worldgraph' ) );
		}

		$xml = preg_replace( '/<w:(?:tab|br)[^>]*\/?\s*>/i', "\t", $xml );
		$xml = preg_replace( '/<\/w:p\s*>/i', "\n\n", (string) $xml );
		return html_entity_decode( wp_strip_all_tags( (string) $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/** Read visible paragraphs from an ODT archive. */
	private function extract_odt( string $path ) {
		$archive = Archive_Reader::from_file( $path );
		if ( is_wp_error( $archive ) ) {
			return $archive;
		}
		$xml = $archive->get( 'content.xml' );
		if ( is_wp_error( $xml ) ) {
			return new \WP_Error( 'worldgraph_story_odt_document_missing', __( 'The ODT has no readable document body.', 'worldgraph' ) );
		}

		$xml = preg_replace( '/<text:(?:line-break|tab)[^>]*\/?\s*>/i', "\t", $xml );
		$xml = preg_replace( '/<\/text:(?:p|h)\s*>/i', "\n\n", (string) $xml );
		return html_entity_decode( wp_strip_all_tags( (string) $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/** Extract text operators from a text-based PDF without executing binaries. */
	private function extract_pdf( string $path ) {
		$pdf = file_get_contents( $path );
		if ( false === $pdf || ! str_starts_with( $pdf, '%PDF-' ) ) {
			return new \WP_Error( 'worldgraph_story_pdf_invalid', __( 'The uploaded file is not a valid PDF.', 'worldgraph' ) );
		}
		if ( str_contains( $pdf, '/Encrypt' ) ) {
			return new \WP_Error( 'worldgraph_story_pdf_encrypted', __( 'Password-protected PDFs are not supported.', 'worldgraph' ) );
		}

		$streams = [];
		$offset  = 0;
		while ( false !== ( $stream_pos = strpos( $pdf, 'stream', $offset ) ) ) {
			$line_end = strpos( $pdf, "\n", $stream_pos );
			if ( false === $line_end ) {
				break;
			}
			$end = strpos( $pdf, 'endstream', $line_end );
			if ( false === $end ) {
				break;
			}
			$dictionary = substr( $pdf, max( 0, $stream_pos - 1200 ), min( 1200, $stream_pos ) );
			$raw        = rtrim( substr( $pdf, $line_end + 1, $end - $line_end - 1 ), "\r\n" );
			$offset     = $end + 9;

			if ( str_contains( $dictionary, '/Subtype/Image' ) || strlen( $raw ) > 4_194_304 ) {
				continue;
			}
			if ( str_contains( $dictionary, '/FlateDecode' ) ) {
				$decoded = @gzuncompress( $raw, 4_194_304 );
				if ( false === $decoded ) {
					$decoded = @gzinflate( $raw, 4_194_304 );
				}
				if ( false === $decoded && function_exists( 'zlib_decode' ) ) {
					$decoded = @zlib_decode( $raw, 4_194_304 );
				}
				if ( false === $decoded ) {
					continue;
				}
				$raw = $decoded;
			}
			if ( str_contains( $raw, 'BT' ) || str_contains( $raw, 'begincmap' ) ) {
				$streams[] = $raw;
			}
		}

		$cmap = $this->pdf_character_map( $streams );
		$text = [];
		foreach ( $streams as $stream ) {
			if ( str_contains( $stream, 'begincmap' ) ) {
				continue;
			}
			if ( preg_match_all( '/BT(.*?)ET/s', $stream, $blocks ) ) {
				foreach ( $blocks[1] as $block ) {
					$line = $this->pdf_text_block( $block, $cmap );
					if ( '' !== trim( $line ) ) {
						$text[] = $line;
					}
				}
			}
		}

		$result = implode( "\n\n", $text );
		$visible_length = mb_strlen( preg_replace( '/\s+/u', '', $result ), 'UTF-8' );
		$unknown_count  = substr_count( $result, '?' );
		if ( mb_strlen( trim( $result ), 'UTF-8' ) < 20 || ( $visible_length > 0 && $unknown_count / $visible_length > 0.3 ) ) {
			return new \WP_Error(
				'worldgraph_story_pdf_ocr_required',
				__( 'This PDF has no extractable text layer. Run OCR on the scanned PDF, then upload the searchable PDF or its text/EPUB version.', 'worldgraph' )
			);
		}

		return $result;
	}

	/** Decode PDF strings found inside one BT/ET content block. */
	private function pdf_text_block( string $block, array $cmap ): string {
		$output = '';
		$length = strlen( $block );
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $block[ $index ];
			if ( '(' === $char ) {
				$bytes  = '';
				$depth  = 1;
				$escape = false;
				for ( $index++; $index < $length && $depth > 0; $index++ ) {
					$current = $block[ $index ];
					if ( $escape ) {
						if ( preg_match( '/[0-7]/', $current ) ) {
							$octal = $current;
							for ( $step = 0; $step < 2 && $index + 1 < $length && preg_match( '/[0-7]/', $block[ $index + 1 ] ); $step++ ) {
								$octal .= $block[ ++$index ];
							}
							$bytes .= chr( octdec( $octal ) );
						} elseif ( in_array( $current, [ "\n", "\r" ], true ) ) {
							if ( "\r" === $current && $index + 1 < $length && "\n" === $block[ $index + 1 ] ) {
								$index++;
							}
						} else {
							$bytes .= [ 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c" ][ $current ] ?? $current;
						}
						$escape = false;
						continue;
					}
					if ( '\\' === $current ) {
						$escape = true;
					} elseif ( '(' === $current ) {
						$depth++;
						$bytes .= $current;
					} elseif ( ')' === $current ) {
						$depth--;
						if ( $depth > 0 ) {
							$bytes .= $current;
						}
					} else {
						$bytes .= $current;
					}
				}
				$index--;
				$output .= $this->decode_pdf_bytes( $bytes, $cmap );
			} elseif ( '<' === $char && ( $index + 1 >= $length || '<' !== $block[ $index + 1 ] ) ) {
				$end = strpos( $block, '>', $index + 1 );
				if ( false !== $end ) {
					$hex = preg_replace( '/\s+/', '', substr( $block, $index + 1, $end - $index - 1 ) );
					if ( '' !== $hex && ctype_xdigit( $hex ) ) {
						$hex    .= strlen( $hex ) % 2 ? '0' : '';
						$output .= $this->decode_pdf_bytes( (string) hex2bin( $hex ), $cmap );
					}
					$index = $end;
				}
			} elseif ( preg_match( '/[\r\n]/', $char ) || ( 'T' === $char && $index + 1 < $length && '*' === $block[ $index + 1 ] ) ) {
				$output .= "\n";
			}
		}

		return trim( preg_replace( '/[ \t]+/', ' ', $output ) );
	}

	/** Build a best-effort ToUnicode map from embedded CMap streams. */
	private function pdf_character_map( array $streams ): array {
		$map = [];
		foreach ( $streams as $stream ) {
			if ( ! str_contains( $stream, 'begincmap' ) ) {
				continue;
			}

			if ( preg_match_all( '/beginbfchar(.*?)endbfchar/s', $stream, $blocks ) ) {
				foreach ( $blocks[1] as $block ) {
					if ( ! preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER ) ) {
						continue;
					}
					foreach ( $pairs as $pair ) {
						$this->add_pdf_map_entry( $map, $pair[1], $pair[2] );
					}
				}
			}

			if ( preg_match_all( '/beginbfrange(.*?)endbfrange/s', $stream, $blocks ) ) {
				foreach ( $blocks[1] as $block ) {
					if ( preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $ranges, PREG_SET_ORDER ) ) {
						foreach ( $ranges as $range ) {
							$start  = hexdec( $range[1] );
							$end    = hexdec( $range[2] );
							$target = hexdec( $range[3] );
							$width  = strlen( $range[1] );
							if ( $end < $start || $end - $start > 4096 ) {
								continue;
							}
							for ( $code = $start; $code <= $end && count( $map ) < 65536; $code++ ) {
								$source_hex = str_pad( strtoupper( dechex( $code ) ), $width, '0', STR_PAD_LEFT );
								$target_hex = str_pad( strtoupper( dechex( $target + $code - $start ) ), strlen( $range[3] ), '0', STR_PAD_LEFT );
								$this->add_pdf_map_entry( $map, $source_hex, $target_hex );
							}
						}
					}

					if ( preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[((?:\s*<[0-9A-Fa-f]+>\s*)+)\]/', $block, $ranges, PREG_SET_ORDER ) ) {
						foreach ( $ranges as $range ) {
							$start = hexdec( $range[1] );
							$end   = hexdec( $range[2] );
							$width = strlen( $range[1] );
							preg_match_all( '/<([0-9A-Fa-f]+)>/', $range[3], $targets );
							foreach ( $targets[1] as $index => $target_hex ) {
								$code = $start + $index;
								if ( $code > $end || count( $map ) >= 65536 ) {
									break;
								}
								$this->add_pdf_map_entry( $map, str_pad( strtoupper( dechex( $code ) ), $width, '0', STR_PAD_LEFT ), $target_hex );
							}
						}
					}
				}
			}
		}
		return $map;
	}

	/** Add one validated UTF-16BE CMap entry. */
	private function add_pdf_map_entry( array &$map, string $source_hex, string $target_hex ): void {
		$source_hex = strtoupper( $source_hex );
		$target_hex = strlen( $target_hex ) % 2 ? '0' . $target_hex : $target_hex;
		$target     = hex2bin( $target_hex );
		if ( false !== $target && '' !== $source_hex ) {
			$map[ $source_hex ] = $this->utf16_to_utf8( $target );
		}
	}

	/** Decode one PDF string through ToUnicode, UTF-16, UTF-8, or Windows-1252. */
	private function decode_pdf_bytes( string $bytes, array $cmap ): string {
		$hex = strtoupper( bin2hex( $bytes ) );
		if ( ! empty( $cmap ) && '' !== $hex ) {
			$lengths = array_values( array_unique( array_map( 'strlen', array_keys( $cmap ) ) ) );
			rsort( $lengths );
			$output = '';
			for ( $offset = 0; $offset < strlen( $hex ); ) {
				$matched = false;
				foreach ( $lengths as $length ) {
					$key = substr( $hex, $offset, $length );
					if ( isset( $cmap[ $key ] ) ) {
						$output .= $cmap[ $key ];
						$offset += $length;
						$matched = true;
						break;
					}
				}
				if ( ! $matched ) {
					$output .= '?';
					$offset += 2;
				}
			}
			return $output;
		}

		if ( str_starts_with( $bytes, "\xFE\xFF" ) ) {
			return $this->utf16_to_utf8( substr( $bytes, 2 ) );
		}
		if ( mb_check_encoding( $bytes, 'UTF-8' ) ) {
			return $bytes;
		}
		return mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
	}

	private function utf16_to_utf8( string $bytes ): string {
		return mb_convert_encoding( $bytes, 'UTF-8', 'UTF-16BE' );
	}

	/** Join EPUB markup entries with structural line breaks and no active markup. */
	private function join_markup_entries( Archive_Reader $archive, array $entries ) {
		$parts = [];
		foreach ( array_slice( $entries, 0, 1000 ) as $entry ) {
			$markup = $archive->get( $entry );
			if ( is_wp_error( $markup ) ) {
				continue;
			}
			$markup = preg_replace( '/<\s*br\s*\/?>/i', "\n", $markup );
			$markup = preg_replace( '/<\/(?:p|div|h[1-6]|li|blockquote|section|article)>/i', "\n\n", (string) $markup );
			$parts[] = html_entity_decode( wp_strip_all_tags( (string) $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		if ( empty( $parts ) ) {
			return new \WP_Error( 'worldgraph_story_epub_text_missing', __( 'The EPUB contains no readable spine documents.', 'worldgraph' ) );
		}
		return implode( "\n\n", $parts );
	}

	/** Load untrusted package XML without network access or entity expansion. */
	private function load_xml( string $xml ) {
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadXML( $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $loaded ? $document : new \WP_Error( 'worldgraph_story_archive_xml_invalid', __( 'The uploaded book contains malformed package XML.', 'worldgraph' ) );
	}

	/** Resolve a package-relative archive path without allowing traversal. */
	private static function resolve_archive_path( string $base_file, string $relative ): string {
		$base = dirname( $base_file );
		return self::normalize_archive_path( ( '.' === $base ? '' : $base . '/' ) . $relative );
	}

	private static function normalize_archive_path( string $path ): string {
		$path  = str_replace( '\\', '/', trim( $path ) );
		$parts = [];
		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) ) {
			return '';
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( empty( $parts ) ) {
					return '';
				}
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}
		return implode( '/', $parts );
	}

	/** Convert basic RTF control words and escaped bytes to readable text. */
	private function extract_rtf( string $rtf ): string {
		$rtf = preg_replace_callback(
			"/\\\\'([0-9a-fA-F]{2})/",
			static fn( array $match ): string => mb_convert_encoding( chr( hexdec( $match[1] ) ), 'UTF-8', 'Windows-1252' ),
			$rtf
		);
		$rtf = preg_replace( '/\\\\(?:par|line)\b\s?/', "\n", (string) $rtf );
		$rtf = preg_replace( '/\\\\tab\b\s?/', "\t", (string) $rtf );
		$rtf = preg_replace( '/\\\\[a-zA-Z]+-?\d*\s?/', '', (string) $rtf );
		$rtf = str_replace( [ '\\{', '\\}', '\\\\' ], [ '{', '}', '\\' ], (string) $rtf );
		return str_replace( [ '{', '}' ], '', (string) $rtf );
	}

	/** Normalize encoding, controls, and paragraph whitespace while retaining structure. */
	private static function normalize_text( string $text ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		$text = preg_replace( '/[^\P{C}\n\t]+/u', '', $text );
		$text = preg_replace( '/[ \t]+\n/u', "\n", (string) $text );
		$text = preg_replace( '/\n{4,}/u', "\n\n\n", (string) $text );
		return trim( (string) $text );
	}
}
