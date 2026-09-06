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
	private const MAX_PDF_STREAM_BYTES = 16_777_216;

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
			$filename = wp_basename( rawurldecode( wp_basename( $source ) ) );
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

		$boundaries = [];
		$normalized = false;
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
		if ( is_array( $text ) ) {
			$boundaries = is_array( $text['boundaries'] ?? null ) ? $text['boundaries'] : [];
			$normalized = ! empty( $text['normalized'] );
			$text       = (string) ( $text['text'] ?? '' );
		}

		$text       = $normalized ? trim( (string) $text ) : self::normalize_text( (string) $text );
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
			'boundaries' => self::decorate_boundaries( $boundaries, $text ),
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
		$stream_bytes = 0;
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
				$stream_bytes += strlen( $raw );
				if ( $stream_bytes > self::MAX_PDF_STREAM_BYTES ) {
					return new \WP_Error(
						'worldgraph_story_pdf_expansion_too_large',
						__( 'The PDF expands beyond the safe text-extraction limit. Export its text or EPUB version and upload that file instead.', 'worldgraph' )
					);
				}
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


	/** Join EPUB body entries while preserving trusted heading and scene-break metadata. */
	private function join_markup_entries( Archive_Reader $archive, array $entries ) {
		$text       = '';
		$boundaries = [];
		$part_index = 0;
		foreach ( array_slice( $entries, 0, 1000 ) as $entry ) {
			$markup = $archive->get( $entry );
			if ( is_wp_error( $markup ) ) {
				continue;
			}

			$part = $this->extract_markup_body( (string) $markup, (string) $entry );
			if ( '' === trim( (string) ( $part['text'] ?? '' ) ) ) {
				continue;
			}

			if ( '' !== $text ) {
				$text .= "\n\n";
			}
			$part_start = mb_strlen( $text, 'UTF-8' );
			$part_index++;
			$part_boundaries = is_array( $part['boundaries'] ?? null ) ? $part['boundaries'] : [];
			$first_heading    = '';
			foreach ( $part_boundaries as $boundary ) {
				if ( '' !== (string) ( $boundary['label'] ?? '' ) ) {
					$first_heading = (string) $boundary['label'];
					break;
				}
			}
			$boundaries[] = [
				'offset'       => $part_start,
				'type'         => 'section',
				'label'        => '' !== $first_heading ? $first_heading : sprintf( 'Section %d', $part_index ),
				'source'       => 'epub_spine',
				'source_entry' => (string) $entry,
				'level'        => 0,
			];
			foreach ( $part_boundaries as $boundary ) {
				$boundary['offset'] = $part_start + max( 0, (int) ( $boundary['offset'] ?? 0 ) );
				$boundaries[]       = $boundary;
			}
			$text .= (string) $part['text'];
		}

		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'worldgraph_story_epub_text_missing', __( 'The EPUB contains no readable spine documents.', 'worldgraph' ) );
		}

		return [
			'text'       => $text,
			'boundaries' => $boundaries,
			'normalized' => true,
		];
	}

	/**
	 * Extract one XHTML body without document-head or known distribution wrapper text.
	 *
	 * Structural markers exist only while offsets are calculated and are removed
	 * before the source text leaves this method.
	 */
	private function extract_markup_body( string $markup, string $entry ): array {
		$document = $this->load_xml( $markup );
		if ( is_wp_error( $document ) ) {
			$document = $this->load_html( $markup );
		}
		if ( is_wp_error( $document ) ) {
			$without_head = preg_replace( '/<head\b[^>]*>.*?<\/head>/isu', '', $markup );
			$without_head = preg_replace( '/<(?:script|style|nav|footer|svg)\b[^>]*>.*?<\/(?:script|style|nav|footer|svg)>/isu', '', (string) $without_head );
			$without_head = preg_replace( '/<\s*br\s*\/?>/iu', "\n", (string) $without_head );
			$without_head = preg_replace( '/<\/(?:p|div|h[1-6]|li|blockquote|section|article)>/iu', "\n\n", (string) $without_head );
			return [
				'text'       => self::normalize_text( html_entity_decode( wp_strip_all_tags( (string) $without_head ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				'boundaries' => [],
			];
		}

		$xpath = new \DOMXPath( $document );
		$body  = $xpath->query( '//*[local-name()="body"]' );
		if ( ! $body || 0 === $body->length ) {
			return [ 'text' => '', 'boundaries' => [] ];
		}

		$prefix = '@@WORLDGRAPH_STRUCTURE_' . substr( hash( 'sha256', $entry . "\n" . $markup ), 0, 16 ) . '_';
		$suffix = 0;
		while ( str_contains( $markup, $prefix ) ) {
			$prefix = '@@WORLDGRAPH_STRUCTURE_' . substr( hash( 'sha256', ++$suffix . "\n" . $entry . "\n" . $markup ), 0, 16 ) . '_';
		}
		$state = [
			'text'       => '',
			'boundaries' => [],
			'prefix'     => $prefix,
			'entry'      => $entry,
			'next'       => 1,
			'scene'      => 0,
		];
		foreach ( $body->item( 0 )->childNodes as $node ) {
			$this->render_markup_node( $node, $state );
		}

		return $this->resolve_markup_boundaries( $state );
	}

	/** Render body text recursively, inserting temporary structural tokens. */
	private function render_markup_node( \DOMNode $node, array &$state ): void {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			$state['text'] .= (string) $node->nodeValue;
			return;
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return;
		}

		$name = strtolower( (string) ( $node->localName ?: $node->nodeName ) );
		if ( $this->skip_markup_node( $node, $name ) ) {
			return;
		}
		if ( 'br' === $name ) {
			$state['text'] .= "\n";
			return;
		}
		if ( 'hr' === $name ) {
			$state['text'] .= "\n\n";
			$state['scene']++;
			$this->add_markup_boundary(
				$state,
				[
					'type'         => 'scene',
					'label'        => sprintf( 'Scene break %d', $state['scene'] ),
					'source'       => 'epub_hr',
					'source_entry' => (string) $state['entry'],
					'level'        => 0,
				]
			);
			$state['text'] .= "\n\n";
			return;
		}

		$is_heading = 1 === preg_match( '/^h([1-6])$/', $name, $heading_match );
		$is_block   = in_array( $name, [ 'address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt', 'figcaption', 'figure', 'li', 'ol', 'p', 'pre', 'section', 'table', 'td', 'th', 'tr', 'ul' ], true );
		if ( $is_heading || $is_block ) {
			$state['text'] .= "\n\n";
		}
		if ( $is_heading ) {
			$label = self::structural_label( (string) $node->textContent );
			$this->add_markup_boundary(
				$state,
				[
					'type'         => self::heading_type( $label ),
					'label'        => $label,
					'source'       => 'epub_heading',
					'source_entry' => (string) $state['entry'],
					'level'        => (int) $heading_match[1],
				]
			);
		}

		foreach ( $node->childNodes as $child ) {
			$this->render_markup_node( $child, $state );
		}
		if ( $is_heading || $is_block ) {
			$state['text'] .= "\n\n";
		}
	}

	/** Whether an EPUB body node is non-narrative or active markup. */
	private function skip_markup_node( \DOMNode $node, string $name ): bool {
		if ( in_array( $name, [ 'footer', 'nav', 'noscript', 'script', 'style', 'svg' ], true ) ) {
			return true;
		}
		if ( ! $node->hasAttributes() ) {
			return false;
		}

		$id    = strtolower( (string) ( $node->attributes->getNamedItem( 'id' )?->nodeValue ?? '' ) );
		$class = strtolower( (string) ( $node->attributes->getNamedItem( 'class' )?->nodeValue ?? '' ) );
		return str_contains( $class, 'pg-boilerplate' )
			|| preg_match( '/(?:^|\s)(?:trn|transnote|transcriber(?:s|\x{0027}s)?(?:-note)?)(?:\s|$)/u', $class )
			|| in_array( $id, [ 'pg-header', 'pg-footer', 'pg-machine-header', 'pg-start-separator', 'pg-end-separator', 'project-gutenberg-license' ], true )
			|| ( str_contains( $id, 'transcriber' ) && str_contains( $id, 'note' ) );
	}

	/** Append a temporary token and retain the metadata that it represents. */
	private function add_markup_boundary( array &$state, array $boundary ): void {
		$token = (string) $state['prefix'] . sprintf( '%05d@@', (int) $state['next']++ );
		$state['text']              .= $token;
		$state['boundaries'][ $token ] = $boundary;
	}

	/** Resolve temporary tokens to UTF-8 character offsets, then remove them. */
	private function resolve_markup_boundaries( array $state ): array {
		$marked = self::normalize_text( (string) $state['text'] );
		$text   = '';
		$cursor = 0;
		$boundaries = [];
		foreach ( (array) $state['boundaries'] as $token => $boundary ) {
			$position = strpos( $marked, (string) $token, $cursor );
			if ( false === $position ) {
				continue;
			}
			$text            .= substr( $marked, $cursor, $position - $cursor );
			$boundary['offset'] = mb_strlen( $text, 'UTF-8' );
			$boundaries[]      = $boundary;
			$cursor             = $position + strlen( (string) $token );
		}
		$text .= substr( $marked, $cursor );

		$leading = 0;
		if ( preg_match( '/^\s+/u', $text, $leading_match ) ) {
			$leading = mb_strlen( (string) $leading_match[0], 'UTF-8' );
		}
		$text = trim( $text );
		foreach ( $boundaries as &$boundary ) {
			$boundary['offset'] = max( 0, (int) $boundary['offset'] - $leading );
		}
		unset( $boundary );

		return [
			'text'       => $text,
			'boundaries' => $boundaries,
		];
	}

	/** Classify a visible heading for chapter-first chunk planning. */
	private static function heading_type( string $label ): string {
		if ( preg_match( '/^(?:book|chapter|part)\b/iu', $label ) ) {
			return 'chapter';
		}
		if ( preg_match( '/^scene\b/iu', $label ) ) {
			return 'scene';
		}
		return 'section';
	}

	/** Normalize a visible structural label without retaining markup. */
	private static function structural_label( string $label ): string {
		$label = html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$label = preg_replace( '/\s+/u', ' ', trim( $label ) );
		return mb_substr( is_string( $label ) ? $label : '', 0, 180, 'UTF-8' );
	}

	/** Add bounded anchors so offsets can be remapped after safe preprocessing. */
	private static function decorate_boundaries( array $boundaries, string $text ): array {
		$length = mb_strlen( $text, 'UTF-8' );
		$result = [];
		foreach ( $boundaries as $boundary ) {
			if ( ! is_array( $boundary ) ) {
				continue;
			}
			$offset = max( 0, min( $length, (int) ( $boundary['offset'] ?? 0 ) ) );
			$before = mb_substr( $text, max( 0, $offset - 72 ), min( 72, $offset ), 'UTF-8' );
			$after  = mb_substr( $text, $offset, min( 72, $length - $offset ), 'UTF-8' );
			$boundary['offset']        = $offset;
			$boundary['anchor_before'] = $before;
			$boundary['anchor_after']  = $after;
			$boundary['anchor_hash']   = hash( 'sha256', $before . "\0" . $after );
			$result[] = $boundary;
		}

		usort( $result, static fn( array $left, array $right ): int => ( $left['offset'] <=> $right['offset'] ) ?: strcmp( (string) $left['type'], (string) $right['type'] ) );
		return $result;
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

	/** Recover a malformed XHTML content document without reading external resources. */
	private function load_html( string $html ) {
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $loaded ? $document : new \WP_Error( 'worldgraph_story_archive_html_invalid', __( 'The uploaded book contains malformed content markup.', 'worldgraph' ) );
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
		$text = strtr(
			$text,
			[
				'ﬀ' => 'ff',
				'ﬁ' => 'fi',
				'ﬂ' => 'fl',
				'ﬃ' => 'ffi',
				'ﬄ' => 'ffl',
				'ﬅ' => 'st',
				'ﬆ' => 'st',
			]
		);
		$latin_count    = preg_match_all( '/\p{Latin}/u', $text );
		$cyrillic_count = preg_match_all( '/\p{Cyrillic}/u', $text );
		$shcha_count    = substr_count( $text, 'щ' );
		if ( $shcha_count > 0 && $shcha_count === $cyrillic_count && $latin_count > $cyrillic_count * 100 ) {
			// Some Latin-script PDF font maps use U+0449 as a line-break glyph.
			// Apply this only when it is the sole Cyrillic character in an
			// overwhelmingly Latin document so genuine Cyrillic prose is retained.
			$text = str_replace( 'щ', "\n", $text );
		}
		$text = preg_replace( '/[^\P{C}\n\t]+/u', '', $text );
		$text = preg_replace( '/[ \t]+\n/u', "\n", (string) $text );
		$text = preg_replace( '/\n{4,}/u', "\n\n\n", (string) $text );
		return trim( (string) $text );
	}
}
