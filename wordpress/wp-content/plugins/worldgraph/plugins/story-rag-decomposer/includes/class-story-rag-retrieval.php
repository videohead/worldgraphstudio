<?php
/**
 * Private transient vector retrieval for Story Import & Export decomposition.
 *
 * @package WorldGraphStoryRAG
 */

namespace WorldGraphStoryRAG;

defined( 'ABSPATH' ) || exit;

/**
 * Adapts decomposition evidence to WPVDB embeddings without indexing the source.
 *
 * The WPVDB embeddings table is deliberately not used: private uploaded sources
 * are not WordPress indexable content. Only vectors and opaque identifiers are
 * retained in expiring transients.
 */
final class Story_RAG_Retrieval {
	/** Maximum private-vector lifetime in seconds. */
	private const TTL = 7200;

	/** Maximum evidence vectors retained for one user/source scope. */
	private const MAX_ENTRIES = 128;

	/** Supported embedding dimension bounds. */
	private const MIN_DIMENSIONS = 8;
	private const MAX_DIMENSIONS = 4096;

	/** Maximum evidence and query text sent transiently to the configured provider. */
	private const MAX_EVIDENCE_CHARS = 12000;
	private const MAX_QUERY_CHARS    = 6000;

	/** Maximum vector neighbors returned to the synthesis pass. */
	private const TOP_K = 3;

	/** Register the two Story Import & Export extension hooks. */
	public static function init(): void {
		add_action( 'worldgraph_story_decomposition_evidence_ready', [ __CLASS__, 'capture_evidence' ], 10, 3 );
		add_filter( 'worldgraph_story_decomposition_retrieval_context', [ __CLASS__, 'retrieve' ], 10, 4 );
		add_action( 'worldgraph_story_rag_cleanup', [ __CLASS__, 'cleanup' ], 10, 2 );
	}

	/**
	 * Embed one bounded evidence object and retain only its vector and identifiers.
	 *
	 * @param mixed $evidence Structured evidence emitted by the analysis pass.
	 * @param mixed $chunk    Server-created story chunk descriptor.
	 * @param mixed $_metadata Server-created decomposition metadata.
	 */
	public static function capture_evidence( $evidence, $chunk, $_metadata = [] ): void {
		if ( ! is_array( $evidence ) || ! is_array( $chunk ) ) {
			return;
		}

		try {
			$user_id     = self::current_user_id();
			$source_hash = self::source_hash( $chunk );
			$identity    = self::embedding_identity();
			$chunk_id    = self::chunk_id( $chunk, $evidence );
			$chunk_index = self::chunk_index( $chunk, $evidence );
			$text        = self::evidence_text( $evidence );

			if ( 0 === $user_id || '' === $source_hash || null === $identity || '' === $chunk_id || '' === $text ) {
				return;
			}

			$vector = \WPVDB\Core::get_embedding_for_model( $text, $identity['model'], $identity['provider'] );
			$vector = self::validated_vector( $vector );
			if ( null === $vector ) {
				return;
			}

			$index_key  = self::index_key( $user_id, $source_hash );
			$vector_key = self::vector_key( $user_id, $source_hash, $chunk_id, $identity );
			$index      = self::validated_index( get_transient( $index_key ), $user_id, $source_hash, $identity );

			if ( null === $index ) {
				$index = [
					'version'     => 1,
					'user_id'     => $user_id,
					'source_hash' => $source_hash,
					'provider'    => $identity['provider'],
					'model'       => $identity['model'],
					'dimensions'  => count( $vector ),
					'entries'     => [],
				];
			} elseif ( (int) $index['dimensions'] !== count( $vector ) ) {
				return;
			}

			$entries = [];
			foreach ( $index['entries'] as $entry ) {
				if ( $entry['chunk_id'] !== $chunk_id ) {
					$entries[] = $entry;
				}
			}
			$entries[] = [
				'chunk_id'    => $chunk_id,
				'chunk_index' => $chunk_index,
			];

			while ( count( $entries ) > self::MAX_ENTRIES ) {
				$evicted = array_shift( $entries );
				if ( is_array( $evicted ) && isset( $evicted['chunk_id'] ) ) {
					delete_transient( self::vector_key( $user_id, $source_hash, (string) $evicted['chunk_id'], $identity ) );
				}
			}

			$index['entries'] = $entries;
			if ( ! set_transient( $vector_key, $vector, self::TTL ) ) {
				return;
			}

			// A positive expiration keeps database-backed WordPress transients non-autoloaded.
			if ( ! set_transient( $index_key, $index, self::TTL ) ) {
				delete_transient( $vector_key );
			}
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * Replace lexical neighbors with same-user, same-source cosine neighbors.
	 *
	 * @param mixed $incoming Existing lexical result.
	 * @param mixed $chunk    Current server-created story chunk descriptor.
	 * @param mixed $corpus   Evidence corpus already held by the decomposition job.
	 * @param mixed $metadata Server-created retrieval metadata.
	 * @return mixed The vector result, or the incoming lexical result on any failure.
	 */
	public static function retrieve( $incoming, $chunk, $corpus, $metadata = [] ) {
		if ( ! is_array( $incoming ) || ! is_array( $chunk ) || ! is_array( $corpus ) ) {
			return $incoming;
		}

		try {
			$user_id = self::current_user_id();
			$metadata_user_id = is_array( $metadata ) ? (int) ( $metadata['user_id'] ?? 0 ) : 0;
			if ( 0 === $user_id || ( $metadata_user_id > 0 && $user_id !== $metadata_user_id ) ) {
				return $incoming;
			}

			$source_hash = self::source_hash( $chunk );
			$identity    = self::embedding_identity();
			if ( '' === $source_hash || null === $identity ) {
				return $incoming;
			}

			$index_key = self::index_key( $user_id, $source_hash );
			$index     = self::validated_index( get_transient( $index_key ), $user_id, $source_hash, $identity );
			if ( null === $index || [] === $index['entries'] ) {
				return $incoming;
			}

			$query = self::query_text( $chunk );
			if ( '' === $query ) {
				return $incoming;
			}

			$query_vector = \WPVDB\Core::get_embedding_for_model( $query, $identity['model'], $identity['provider'] );
			$query_vector = self::validated_vector( $query_vector );
			if ( null === $query_vector || count( $query_vector ) !== (int) $index['dimensions'] ) {
				return $incoming;
			}

			$current_index    = is_array( $metadata ) ? max( 0, (int) ( $metadata['current_index'] ?? 0 ) ) : 0;
			$current_chunk_id = self::chunk_id( $chunk, [] );
			$corpus_ids       = self::corpus_ids( $corpus );
			$ranked           = [];

			foreach ( $index['entries'] as $entry ) {
				$entry_index = isset( $corpus_ids[ $entry['chunk_id'] ] )
					? $corpus_ids[ $entry['chunk_id'] ]
					: (int) $entry['chunk_index'];
				if (
					$entry['chunk_id'] === $current_chunk_id ||
					$entry_index === $current_index ||
					! isset( $corpus[ $entry_index ] ) ||
					! is_array( $corpus[ $entry_index ] )
				) {
					continue;
				}

				$vector = get_transient( self::vector_key( $user_id, $source_hash, $entry['chunk_id'], $identity ) );
				$vector = self::validated_vector( $vector );
				if ( null === $vector || count( $vector ) !== count( $query_vector ) ) {
					continue;
				}

				$score = self::cosine_similarity( $query_vector, $vector );
				if ( null !== $score ) {
					$ranked[] = [
						'score' => $score,
						'index' => $entry_index,
					];
				}
			}

			if ( [] === $ranked ) {
				return $incoming;
			}

			usort(
				$ranked,
				static function ( array $left, array $right ): int {
					if ( $left['score'] === $right['score'] ) {
						return $left['index'] <=> $right['index'];
					}
					return $left['score'] < $right['score'] ? 1 : -1;
				}
			);

			$related = [];
			foreach ( array_slice( $ranked, 0, self::TOP_K ) as $match ) {
				$related[] = $corpus[ $match['index'] ];
			}

			return [
				'backend' => 'wpvdb-private-vector',
				'current' => isset( $incoming['current'] ) && is_array( $incoming['current'] )
					? $incoming['current']
					: ( is_array( $corpus[ $current_index ] ?? null ) ? $corpus[ $current_index ] : [] ),
				'related' => $related,
			];
		} catch ( \Throwable ) {
			return $incoming;
		}
	}

	/**
	 * Delete all private vectors for the current user's source scope.
	 *
	 * Consumers may fire `worldgraph_story_rag_cleanup` with a source hash and
	 * current user ID. Expiration remains the mandatory backstop.
	 *
	 * @param mixed $source_hash Source SHA-256 identifier.
	 * @param mixed $user_id     Expected current user ID.
	 */
	public static function cleanup( $source_hash, $user_id = 0 ): void {
		$current_user_id = self::current_user_id();
		$source_hash     = self::valid_source_hash( $source_hash );
		$user_id         = (int) $user_id;

		if ( 0 === $current_user_id || '' === $source_hash || ( $user_id > 0 && $user_id !== $current_user_id ) ) {
			return;
		}

		$index_key = self::index_key( $current_user_id, $source_hash );
		$index     = get_transient( $index_key );
		if ( is_array( $index ) ) {
			$identity = [
				'provider' => self::bounded_identifier( $index['provider'] ?? '' ),
				'model'    => self::bounded_identifier( $index['model'] ?? '' ),
			];
			if ( '' !== $identity['provider'] && '' !== $identity['model'] && is_array( $index['entries'] ?? null ) ) {
				foreach ( array_slice( $index['entries'], 0, self::MAX_ENTRIES ) as $entry ) {
					$chunk_id = is_array( $entry ) ? self::bounded_identifier( $entry['chunk_id'] ?? '' ) : '';
					if ( '' !== $chunk_id ) {
						delete_transient( self::vector_key( $current_user_id, $source_hash, $chunk_id, $identity ) );
					}
				}
			}
		}
		delete_transient( $index_key );
	}

	/** Return the active, validated WPVDB provider/model identifiers. */
	private static function embedding_identity(): ?array {
		if ( ! is_configured() ) {
			return null;
		}

		$provider = self::bounded_identifier( \WPVDB\Settings::get_active_provider() );
		$model    = method_exists( '\\WPVDB\\Settings', 'get_active_model' )
			? self::bounded_identifier( \WPVDB\Settings::get_active_model() )
			: self::bounded_identifier( \WPVDB\Settings::get_default_model() );

		return '' === $provider || '' === $model ? null : compact( 'provider', 'model' );
	}

	/** Validate and normalize a provider vector. */
	private static function validated_vector( $candidate ): ?array {
		if ( is_wp_error( $candidate ) || ! is_array( $candidate ) || ! array_is_list( $candidate ) ) {
			return null;
		}

		$count = count( $candidate );
		if ( $count < self::MIN_DIMENSIONS || $count > self::MAX_DIMENSIONS ) {
			return null;
		}

		$vector = [];
		$norm   = 0.0;
		foreach ( $candidate as $value ) {
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				return null;
			}
			$value = (float) $value;
			if ( ! is_finite( $value ) ) {
				return null;
			}
			$norm += $value * $value;
			if ( ! is_finite( $norm ) ) {
				return null;
			}
			$vector[] = $value;
		}

		return $norm > 1.0e-30 ? $vector : null;
	}

	/** Validate an identifier without retaining untrusted prose. */
	private static function bounded_identifier( $candidate ): string {
		if ( ! is_scalar( $candidate ) ) {
			return '';
		}
		$candidate = trim( (string) $candidate );
		if ( '' === $candidate || strlen( $candidate ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $candidate ) ) {
			return '';
		}
		return $candidate;
	}

	/** Return one exact source scope from the server-created chunk metadata. */
	private static function source_hash( array $chunk ): string {
		$candidate = $chunk['metadata']['source_hash'] ?? $chunk['source_hash'] ?? '';
		return self::valid_source_hash( $candidate );
	}

	/** Validate a SHA-256 source identifier. */
	private static function valid_source_hash( $candidate ): string {
		$candidate = is_string( $candidate ) ? strtolower( $candidate ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $candidate ) ? $candidate : '';
	}

	/** Return a stable opaque chunk identifier. */
	private static function chunk_id( array $chunk, array $evidence ): string {
		$candidate = self::bounded_identifier( $chunk['id'] ?? $evidence['_retrieval']['chunk_id'] ?? '' );
		if ( '' !== $candidate ) {
			return substr( hash( 'sha256', $candidate ), 0, 32 );
		}

		$chunk_hash = self::bounded_identifier( $chunk['hash'] ?? '' );
		return '' !== $chunk_hash ? substr( hash( 'sha256', $chunk_hash ), 0, 32 ) : '';
	}

	/** Return the corpus position attached by Story_Decomposer. */
	private static function chunk_index( array $chunk, array $evidence ): int {
		$index = $evidence['_retrieval']['chunk_index'] ?? $chunk['index'] ?? 0;
		return max( 0, min( self::MAX_ENTRIES - 1, (int) $index ) );
	}

	/** Map hashed server chunk identifiers back to evidence corpus positions. */
	private static function corpus_ids( array $corpus ): array {
		$result = [];
		foreach ( array_slice( $corpus, 0, self::MAX_ENTRIES, true ) as $index => $evidence ) {
			if ( ! is_array( $evidence ) ) {
				continue;
			}
			$chunk_id = self::bounded_identifier( $evidence['_retrieval']['chunk_id'] ?? '' );
			if ( '' !== $chunk_id ) {
				$result[ substr( hash( 'sha256', $chunk_id ), 0, 32 ) ] = (int) $index;
			}
		}
		return $result;
	}

	/** Convert structured evidence into bounded, non-instructional embedding input. */
	private static function evidence_text( array $evidence ): string {
		$remaining = 400;
		$bounded   = self::bounded_value( $evidence, 0, $remaining );
		$encoded   = wp_json_encode( $bounded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? trim( self::truncate( $encoded, self::MAX_EVIDENCE_CHARS ) ) : '';
	}

	/** Return transient query input; it is never placed in this plugin's stored index. */
	private static function query_text( array $chunk ): string {
		$label = is_scalar( $chunk['label'] ?? null ) ? (string) $chunk['label'] : '';
		$text  = is_string( $chunk['text'] ?? null ) ? $chunk['text'] : '';
		return trim( self::truncate( $label . "\n" . $text, self::MAX_QUERY_CHARS ) );
	}

	/** Recursively bound evidence and exclude fields that can contain private deliberation or credentials. */
	private static function bounded_value( $value, int $depth, int &$remaining ) {
		if ( $depth > 6 || $remaining <= 0 ) {
			return null;
		}
		--$remaining;
		if ( is_string( $value ) ) {
			$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
			return self::truncate( (string) $value, 1000 );
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}

		$result        = [];
		$sensitive_key = '/(?:reason|thought|deliberat|prompt|credential|secret|api[_-]?key|password|token|story[_-]?text|source[_-]?text|manuscript)/i';
		foreach ( array_slice( $value, 0, 40, true ) as $key => $item ) {
			if ( $remaining <= 0 ) {
				break;
			}
			if ( is_string( $key ) && preg_match( $sensitive_key, $key ) ) {
				continue;
			}
			$result[ $key ] = self::bounded_value( $item, $depth + 1, $remaining );
		}
		return $result;
	}

	/** Validate the identifier-only source index. */
	private static function validated_index( $candidate, int $user_id, string $source_hash, array $identity ): ?array {
		if (
			! is_array( $candidate ) ||
			1 !== (int) ( $candidate['version'] ?? 0 ) ||
			$user_id !== (int) ( $candidate['user_id'] ?? 0 ) ||
			$source_hash !== (string) ( $candidate['source_hash'] ?? '' ) ||
			$identity['provider'] !== (string) ( $candidate['provider'] ?? '' ) ||
			$identity['model'] !== (string) ( $candidate['model'] ?? '' ) ||
			! is_array( $candidate['entries'] ?? null )
		) {
			return null;
		}

		$dimensions = (int) ( $candidate['dimensions'] ?? 0 );
		if ( $dimensions < self::MIN_DIMENSIONS || $dimensions > self::MAX_DIMENSIONS ) {
			return null;
		}

		$entries = [];
		foreach ( array_slice( $candidate['entries'], 0, self::MAX_ENTRIES ) as $entry ) {
			$chunk_id = is_array( $entry ) ? self::bounded_identifier( $entry['chunk_id'] ?? '' ) : '';
			if ( '' === $chunk_id || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $chunk_id ) ) {
				continue;
			}
			$entries[] = [
				'chunk_id'    => $chunk_id,
				'chunk_index' => max( 0, min( self::MAX_ENTRIES - 1, (int) ( $entry['chunk_index'] ?? 0 ) ) ),
			];
		}

		$candidate['dimensions'] = $dimensions;
		$candidate['entries']    = $entries;
		return $candidate;
	}

	/** Calculate finite cosine similarity for equal-length validated vectors. */
	private static function cosine_similarity( array $left, array $right ): ?float {
		if ( count( $left ) !== count( $right ) ) {
			return null;
		}

		$dot        = 0.0;
		$left_norm  = 0.0;
		$right_norm = 0.0;
		foreach ( $left as $index => $value ) {
			$other       = $right[ $index ];
			$dot        += $value * $other;
			$left_norm  += $value * $value;
			$right_norm += $other * $other;
			if ( ! is_finite( $dot ) || ! is_finite( $left_norm ) || ! is_finite( $right_norm ) ) {
				return null;
			}
		}

		if ( $left_norm <= 1.0e-30 || $right_norm <= 1.0e-30 ) {
			return null;
		}

		$score = $dot / ( sqrt( $left_norm ) * sqrt( $right_norm ) );
		return is_finite( $score ) ? $score : null;
	}

	/** Build the identifier-only index transient key. */
	private static function index_key( int $user_id, string $source_hash ): string {
		return 'worldgraph_story_rag_i_' . $user_id . '_' . substr( $source_hash, 0, 40 );
	}

	/** Build a scoped vector transient key without placing source prose in the key. */
	private static function vector_key( int $user_id, string $source_hash, string $chunk_id, array $identity ): string {
		$seed = implode( '|', [ $source_hash, $chunk_id, $identity['provider'], $identity['model'] ] );
		return 'worldgraph_story_rag_v_' . $user_id . '_' . substr( hash( 'sha256', $seed ), 0, 40 );
	}

	/** Return a positive WordPress user scope. */
	private static function current_user_id(): int {
		return max( 0, (int) get_current_user_id() );
	}

	/** UTF-8-safe bounded substring. */
	private static function truncate( string $value, int $max_chars ): string {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value, 'UTF-8' ) > $max_chars ? mb_substr( $value, 0, $max_chars, 'UTF-8' ) : $value;
		}
		return strlen( $value ) > $max_chars ? substr( $value, 0, $max_chars ) : $value;
	}
}
