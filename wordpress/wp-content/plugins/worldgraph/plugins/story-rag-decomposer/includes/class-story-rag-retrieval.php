<?php
/**
 * User-and-source-scoped vector retrieval for Story Import & Export decomposition.
 *
 * @package WorldGraphStoryRAG
 */

namespace WorldGraphStoryRAG;

defined( 'ABSPATH' ) || exit;

/**
 * Adapts decomposition evidence to WPVDB embeddings without indexing the source.
 *
 * The WPVDB embeddings table is deliberately not used: private uploaded sources
 * are not WordPress indexable content. This bridge retains only vectors and
 * opaque identifiers in expiring transients and evicts WPVDB's shared object-
 * cache entry immediately after each scoped embedding request.
 */
final class Story_RAG_Retrieval {
	/** Fallback lifetime aligned with the maximum 1,000-part resumable job. */
	private const FALLBACK_TTL = 806_760;

	/** Maximum evidence vectors retained for one user/source scope. */
	private const MAX_ENTRIES = 128;

	/** Maximum source-corpus positions addressable by the decomposer. */
	private const MAX_CORPUS_ENTRIES = 1_000;

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
		self::init_cleanup();
	}

	/** Keep terminal cleanup registered even when capture/retrieval is inactive. */
	public static function init_cleanup(): void {
		add_action( 'worldgraph_story_rag_cleanup', [ __CLASS__, 'cleanup' ], 10, 2 );
	}

	/**
	 * Embed one bounded evidence object and retain only its vector and identifiers.
	 *
	 * @param mixed $evidence Structured evidence emitted by the analysis pass.
	 * @param mixed $chunk    Server-created story chunk descriptor.
	 * @param mixed $metadata Server-created decomposition metadata.
	 */
	public static function capture_evidence( $evidence, $chunk, $metadata = [] ): void {
		if ( ! is_array( $evidence ) || ! is_array( $chunk ) ) {
			return;
		}

		try {
			$user_id     = self::current_user_id();
			$source_hash = self::source_hash( $chunk );
			$run_scope   = self::run_scope( $metadata );
			$identity    = self::embedding_identity();
			$chunk_id    = self::chunk_id( $chunk, $evidence );
			$chunk_index = self::chunk_index( $chunk, $evidence );
			$chunk_total = self::chunk_total( $chunk, $chunk_index );
			$sample_bucket = self::sample_bucket( $chunk_index, $chunk_total );
			$text        = self::evidence_text( $evidence );

			if ( 0 === $user_id || '' === $source_hash || '' === $run_scope || null === $identity || '' === $chunk_id || '' === $text ) {
				return;
			}

			$index_key = self::index_key( $user_id, $run_scope );
			$index     = self::validated_index( get_transient( $index_key ), $user_id, $source_hash, $run_scope, $identity );
			$ttl       = self::ttl( $metadata );
			$evicted   = [];
			if ( is_array( $index ) ) {
				$normalized       = self::normalize_sample_entries( $index['entries'], $chunk_total );
				$index['entries'] = $normalized['entries'];
				$evicted          = $normalized['evicted'];
				if ( ! self::sample_candidate_is_selected( $index['entries'], $chunk_id, $chunk_index, $chunk_total, $sample_bucket ) ) {
					if ( ! empty( $evicted ) && set_transient( $index_key, $index, $ttl ) ) {
						self::delete_entry_vectors( $evicted, $user_id, $run_scope, $identity );
					}
					return;
				}
			}

			$vector = self::scoped_embedding( $text, $identity, $user_id, $source_hash, $run_scope );
			$vector = self::validated_vector( $vector );
			if ( null === $vector ) {
				return;
			}

			$vector_key = self::vector_key( $user_id, $run_scope, $chunk_id, $identity );

			if ( null === $index ) {
				$index = [
					'version'     => 2,
					'user_id'     => $user_id,
					'source_hash' => $source_hash,
					'run_scope'   => $run_scope,
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
				if ( $entry['chunk_id'] !== $chunk_id && (int) $entry['sample_bucket'] !== $sample_bucket ) {
					$entries[] = $entry;
				} else {
					$evicted[] = $entry;
				}
			}
			$entries[] = [
				'chunk_id'     => $chunk_id,
				'chunk_index'  => $chunk_index,
				'chunk_total'  => $chunk_total,
				'sample_bucket' => $sample_bucket,
			];

			while ( count( $entries ) > self::MAX_ENTRIES ) {
				$removed = array_shift( $entries );
				if ( is_array( $removed ) ) {
					$evicted[] = $removed;
				}
			}

			$index['entries'] = $entries;
			$previous_vector = get_transient( $vector_key );
			if ( ! set_transient( $vector_key, $vector, $ttl ) ) {
				return;
			}

			// A positive expiration keeps database-backed WordPress transients non-autoloaded.
			if ( ! set_transient( $index_key, $index, $ttl ) ) {
				if ( is_array( $previous_vector ) ) {
					set_transient( $vector_key, $previous_vector, $ttl );
				} else {
					delete_transient( $vector_key );
				}
				return;
			}
			self::delete_entry_vectors( $evicted, $user_id, $run_scope, $identity, $chunk_id );
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
			$run_scope   = self::run_scope( $metadata );
			$identity    = self::embedding_identity();
			if ( '' === $source_hash || '' === $run_scope || null === $identity ) {
				return $incoming;
			}

			$index_key = self::index_key( $user_id, $run_scope );
			$index     = self::validated_index( get_transient( $index_key ), $user_id, $source_hash, $run_scope, $identity );
			if ( null === $index || [] === $index['entries'] ) {
				return $incoming;
			}

			$query = self::query_text( $chunk );
			if ( '' === $query ) {
				return $incoming;
			}

			$query_vector = self::scoped_embedding( $query, $identity, $user_id, $source_hash, $run_scope );
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
					$entry_index === $current_index
				) {
					continue;
				}

				$vector = get_transient( self::vector_key( $user_id, $run_scope, $entry['chunk_id'], $identity ) );
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

			$related         = [];
			$related_indexes = [];
			foreach ( array_slice( $ranked, 0, self::TOP_K ) as $match ) {
				if ( isset( $corpus[ $match['index'] ] ) && is_array( $corpus[ $match['index'] ] ) ) {
					$related[] = $corpus[ $match['index'] ];
				} else {
					$related_indexes[] = (int) $match['index'];
				}
			}

			$result = [
				'backend'       => 'wpvdb-private-vector',
				'indexed_count' => count( $index['entries'] ),
				'current'       => isset( $incoming['current'] ) && is_array( $incoming['current'] )
					? $incoming['current']
					: ( is_array( $corpus[ $current_index ] ?? null ) ? $corpus[ $current_index ] : [] ),
				'related' => $related,
			];
			if ( ! empty( $related_indexes ) ) {
				$result['related_indexes'] = $related_indexes;
			}
			return $result;
		} catch ( \Throwable ) {
			return $incoming;
		}
	}

	/**
	 * Delete all private vectors for the current user's source scope.
	 *
	 * Consumers fire `worldgraph_story_rag_cleanup` with an opaque run scope and
	 * server-owned metadata. Expiration remains the mandatory backstop.
	 *
	 * @param mixed $run_scope Opaque decomposition-run scope.
	 * @param mixed $metadata  Expected current user/source metadata.
	 */
	public static function cleanup( $run_scope, $metadata = [] ): void {
		$current_user_id = self::current_user_id();
		$run_scope       = self::valid_run_scope( $run_scope );
		$metadata        = is_array( $metadata ) ? $metadata : [];
		$user_id         = absint( $metadata['user_id'] ?? 0 );

		if ( 0 === $current_user_id || '' === $run_scope || 0 === $user_id || $user_id !== $current_user_id ) {
			return;
		}

		$index_key = self::index_key( $current_user_id, $run_scope );
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
						delete_transient( self::vector_key( $current_user_id, $run_scope, $chunk_id, $identity ) );
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

	/**
	 * Scope WPVDB's text-derived cache key and immediately evict that cache entry.
	 *
	 * WPVDB caches Core embedding calls in the shared `wpvdb` object-cache group.
	 * A keyed, installation-local scope prevents cross-user/source reuse. Input is
	 * newline-normalized up front so the key we evict is also the key WPVDB stores.
	 *
	 * @return mixed WPVDB embedding response.
	 */
	private static function scoped_embedding( string $text, array $identity, int $user_id, string $source_hash, string $run_scope ) {
		$scope       = hash_hmac( 'sha256', $user_id . "\0" . $source_hash . "\0" . $run_scope, wp_salt( 'auth' ) );
		$text        = str_replace( [ "\r\n", "\r", "\n" ], ' ', $text );
		$scoped_text = '[worldgraph-private-scope:' . $scope . '] ' . $text;
		$cache_key   = 'embedding_' . $identity['model'] . '_' . hash( 'sha256', $scoped_text );

		try {
			wp_cache_delete( $cache_key, 'wpvdb' );
			return \WPVDB\Core::get_embedding_for_model( $scoped_text, $identity['model'], $identity['provider'] );
		} finally {
			wp_cache_delete( $cache_key, 'wpvdb' );
		}
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

	/** Read one server-generated decomposition run scope from hook metadata. */
	private static function run_scope( $metadata ): string {
		return self::valid_run_scope( is_array( $metadata ) ? ( $metadata['run_scope'] ?? '' ) : '' );
	}

	/** Validate an opaque HMAC run identifier. */
	private static function valid_run_scope( $candidate ): string {
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
		return max( 0, min( self::MAX_CORPUS_ENTRIES - 1, (int) $index ) );
	}

	/** Return the bounded final plan size used for uniform vector sampling. */
	private static function chunk_total( array $chunk, int $chunk_index ): int {
		return max( $chunk_index + 1, min( self::MAX_CORPUS_ENTRIES, (int) ( $chunk['total'] ?? $chunk_index + 1 ) ) );
	}

	/** Map a source position into at most MAX_ENTRIES uniform corpus buckets. */
	private static function sample_bucket( int $chunk_index, int $chunk_total ): int {
		if ( $chunk_total <= self::MAX_ENTRIES ) {
			return min( self::MAX_ENTRIES - 1, $chunk_index );
		}
		return min( self::MAX_ENTRIES - 1, (int) floor( $chunk_index * self::MAX_ENTRIES / $chunk_total ) );
	}

	/** Rebase retained samples when adaptive subdivision changes the plan total. */
	private static function normalize_sample_entries( array $entries, int $chunk_total ): array {
		$selected = [];
		$evicted  = [];
		foreach ( $entries as $entry ) {
			$chunk_index           = max( 0, min( self::MAX_CORPUS_ENTRIES - 1, (int) ( $entry['chunk_index'] ?? 0 ) ) );
			$bucket                = self::sample_bucket( $chunk_index, $chunk_total );
			$entry['chunk_index']  = $chunk_index;
			$entry['chunk_total']  = $chunk_total;
			$entry['sample_bucket'] = $bucket;
			if ( ! isset( $selected[ $bucket ] ) ) {
				$selected[ $bucket ] = $entry;
				continue;
			}

			$existing_index = (int) $selected[ $bucket ]['chunk_index'];
			$candidate_distance = self::sample_distance( $chunk_index, $bucket, $chunk_total );
			$existing_distance  = self::sample_distance( $existing_index, $bucket, $chunk_total );
			if ( $candidate_distance < $existing_distance || ( $candidate_distance === $existing_distance && $chunk_index < $existing_index ) ) {
				$evicted[]          = $selected[ $bucket ];
				$selected[ $bucket ] = $entry;
			} else {
				$evicted[] = $entry;
			}
		}
		ksort( $selected, SORT_NUMERIC );
		return [ 'entries' => array_values( $selected ), 'evicted' => $evicted ];
	}

	/** Measure a position's integer distance from the center of a sample bucket. */
	private static function sample_distance( int $chunk_index, int $sample_bucket, int $chunk_total ): int {
		return abs( ( ( 2 * $chunk_index + 1 ) * self::MAX_ENTRIES ) - ( ( 2 * $sample_bucket + 1 ) * $chunk_total ) );
	}

	/** Prefer the position nearest a uniform bucket center, never a rolling tail. */
	private static function sample_candidate_is_selected( array $entries, string $chunk_id, int $chunk_index, int $chunk_total, int $sample_bucket ): bool {
		$candidate_distance = self::sample_distance( $chunk_index, $sample_bucket, $chunk_total );
		foreach ( $entries as $entry ) {
			if ( (string) ( $entry['chunk_id'] ?? '' ) === $chunk_id ) {
				return true;
			}
			if ( (int) ( $entry['sample_bucket'] ?? -1 ) !== $sample_bucket ) {
				continue;
			}
			$existing_index    = (int) ( $entry['chunk_index'] ?? 0 );
			$existing_distance = self::sample_distance( $existing_index, $sample_bucket, $chunk_total );
			return $candidate_distance < $existing_distance || ( $candidate_distance === $existing_distance && $chunk_index < $existing_index );
		}
		return count( $entries ) < self::MAX_ENTRIES;
	}

	/** Delete vectors whose identifiers are no longer referenced by a committed index. */
	private static function delete_entry_vectors( array $entries, int $user_id, string $run_scope, array $identity, string $except_chunk_id = '' ): void {
		foreach ( $entries as $entry ) {
			$chunk_id = is_array( $entry ) ? (string) ( $entry['chunk_id'] ?? '' ) : '';
			if ( '' !== $chunk_id && $chunk_id !== $except_chunk_id ) {
				delete_transient( self::vector_key( $user_id, $run_scope, $chunk_id, $identity ) );
			}
		}
	}

	/** Map hashed server chunk identifiers back to evidence corpus positions. */
	private static function corpus_ids( array $corpus ): array {
		$result = [];
		foreach ( array_slice( $corpus, 0, self::MAX_CORPUS_ENTRIES, true ) as $index => $evidence ) {
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
	private static function validated_index( $candidate, int $user_id, string $source_hash, string $run_scope, array $identity ): ?array {
		if (
			! is_array( $candidate ) ||
			2 !== (int) ( $candidate['version'] ?? 0 ) ||
			$user_id !== (int) ( $candidate['user_id'] ?? 0 ) ||
			$source_hash !== (string) ( $candidate['source_hash'] ?? '' ) ||
			$run_scope !== (string) ( $candidate['run_scope'] ?? '' ) ||
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
				'chunk_id'      => $chunk_id,
				'chunk_index'   => max( 0, min( self::MAX_CORPUS_ENTRIES - 1, (int) ( $entry['chunk_index'] ?? 0 ) ) ),
				'chunk_total'   => max( 1, min( self::MAX_CORPUS_ENTRIES, (int) ( $entry['chunk_total'] ?? 1 ) ) ),
				'sample_bucket' => max( 0, min( self::MAX_ENTRIES - 1, (int) ( $entry['sample_bucket'] ?? 0 ) ) ),
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
	private static function index_key( int $user_id, string $run_scope ): string {
		return 'worldgraph_story_rag_i_' . $user_id . '_' . substr( $run_scope, 0, 40 );
	}

	/** Build a scoped vector transient key without placing source prose in the key. */
	private static function vector_key( int $user_id, string $run_scope, string $chunk_id, array $identity ): string {
		$seed = implode( '|', [ $run_scope, $chunk_id, $identity['provider'], $identity['model'] ] );
		return 'worldgraph_story_rag_v_' . $user_id . '_' . substr( hash( 'sha256', $seed ), 0, 40 );
	}

	/** Match, but never outlive, the owning job's absolute retention window. */
	private static function ttl( $metadata = [] ): int {
		$ttl = class_exists( '\\WorldGraphStoryIO\\Decomposition_Job' )
			? max( HOUR_IN_SECONDS, (int) \WorldGraphStoryIO\Decomposition_Job::ACTIVE_TTL )
			: self::FALLBACK_TTL;
		$expires_at = is_array( $metadata ) ? absint( $metadata['expires_at'] ?? 0 ) : 0;
		return $expires_at > 0 ? max( 1, min( $ttl, $expires_at - time() ) ) : $ttl;
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
