<?php
/**
 * Resumable, user-scoped story decomposition jobs.
 *
 * @package WorldGraphStoryIO
 */

namespace WorldGraphStoryIO;

defined( 'ABSPATH' ) || exit;

/**
 * Checkpoint one bounded analysis, synthesis, or finalization stage at a time.
 *
 * Manuscript text and model intermediates remain in bounded private server-side
 * transient shards. Browser responses are projections produced by public_status().
 */
class Decomposition_Job {
	private const VERSION                = 1;
	private const MAX_PLANNED_CHUNKS     = 1_000;
	private const REQUEST_TIME_LIMIT     = 6 * MINUTE_IN_SECONDS;
	private const RESUME_GRACE           = 24 * HOUR_IN_SECONDS;
	public const ACTIVE_TTL              = ( ( self::MAX_PLANNED_CHUNKS * 2 ) + 1 ) * self::REQUEST_TIME_LIMIT + self::RESUME_GRACE;
	private const COMPLETED_TTL          = 30 * MINUTE_IN_SECONDS;
	private const LOCK_TTL               = 10 * MINUTE_IN_SECONDS;
	private const MAX_CHECKPOINT_RETRIES = 3;
	private const RETRIEVAL_RADIUS       = 3;
	private const MAX_SHARD_BYTES        = 512 * 1024;
	private const TOKEN_PATTERN          = '/\A[A-Za-z0-9_-]{32,86}\z/';
	private const SHARD_KINDS            = [ 'chunk', 'analysis', 'document', 'memory' ];

	/** Create and checkpoint a decomposition plan for one persisted source. */
	public static function create( array $source, int $attachment_id, int $connection_id, bool $overwrite = false, string $connection_name = '' ) {
		$user_id = get_current_user_id();
		$text    = is_scalar( $source['text'] ?? null ) ? (string) $source['text'] : '';
		if ( ! $user_id || ! current_user_can( 'manage_options' ) || ! $attachment_id || ! $connection_id || '' === trim( $text ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_job_invalid', __( 'The story decomposition job could not be created from the supplied source.', 'worldgraph' ) );
		}

		$decomposer = new Story_Decomposer();
		if ( ! method_exists( $decomposer, 'create_plan' ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_plan_unavailable', __( 'The installed story decomposer does not support resumable decomposition plans.', 'worldgraph' ) );
		}

		try {
			$planned = $decomposer->create_plan(
				$text,
				(string) ( $source['filename'] ?? '' ),
				$connection_id,
				is_array( $source['boundaries'] ?? null ) ? $source['boundaries'] : []
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'worldgraph_story_decomposition_plan_failed', __( 'The story could not be divided into safe analysis sections.', 'worldgraph' ) );
		}
		if ( is_wp_error( $planned ) ) {
			return $planned;
		}

		$normalized = self::normalize_plan( $planned, $text );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$token     = self::new_token();
		$created   = time();
		$run_scope = hash_hmac( 'sha256', 'story-decomposition:' . $user_id . ':' . $token, wp_salt( 'auth' ) );
		$profile = $normalized['profile'];
		$profile['run_scope'] = $run_scope;
		$profile['run_expires_at'] = $created + self::ACTIVE_TTL;
		$state = [
			'version'           => self::VERSION,
			'job_id'            => $token,
			'user_id'           => $user_id,
			'created_at'        => $created,
			'checkpoint_revision' => 0,
			// ACTIVE_TTL is exactly ( ( 2 * 1,000 ) + 1 ) six-minute request
			// windows for analysis/synthesis/finalize, plus a 24-hour resume grace.
			'expires_at'        => $created + self::ACTIVE_TTL,
			'status'            => 'ready',
			'stage'             => 'analysis',
			'attachment_id'     => $attachment_id,
			'connection_id'     => $connection_id,
			'connection_name'   => sanitize_text_field( $connection_name ),
			'overwrite'         => $overwrite,
			'filename'          => sanitize_file_name( (string) ( $source['filename'] ?? '' ) ),
			'format'            => sanitize_key( (string) ( $source['format'] ?? '' ) ),
			'source_characters' => absint( $source['characters'] ?? mb_strlen( $text, 'UTF-8' ) ),
			'extracted_sha256'  => hash( 'sha256', $text ),
			'source_sha256'     => $normalized['source_sha256'],
			'run_scope'         => $run_scope,
			'source_title'      => $normalized['source_title'],
			'profile'           => $profile,
			'plan'              => $normalized['plan'],
			'chunk_count'       => count( $normalized['plan']['chunks'] ),
			'analysis_cursor'   => 0,
			'synthesis_cursor'  => 0,
			'analysis_results'  => [],
			'documents'         => [],
			'graph_memory'      => [],
			'metrics'           => [
				'attempts'     => 0,
				'tokens'       => 0,
				'backend'      => '',
				'model'        => '',
				'context_window' => absint( $normalized['profile']['context_window'] ?? 0 ),
				'subdivisions' => 0,
				'retrieval'    => [],
			],
			'last_error'       => [],
			'preview_token'    => '',
			'preview_url'      => '',
			'preview_expires_at' => 0,
		];
		$state['identity_sha256'] = self::identity_hash( $state );
		$sharded_chunks = self::store_chunk_shards( $state, (array) $state['plan']['chunks'] );
		if ( is_wp_error( $sharded_chunks ) ) {
			self::delete_all_state_shards( $state );
			return $sharded_chunks;
		}
		$state['plan']['chunks'] = $sharded_chunks;

		$saved = self::save_state( $state );
		if ( is_wp_error( $saved ) ) {
			self::delete_all_state_shards( $state );
			return $saved;
		}

		return self::public_status( $state );
	}

	/** Return a safe status projection for the current user. */
	public static function status( string $job_id ) {
		$lock = self::acquire_lock( $job_id );
		if ( ! is_wp_error( $lock ) ) {
			self::register_shutdown_lock_cleanup( $job_id, $lock );
			try {
				$state = self::load_state( $job_id );
				if ( ! is_wp_error( $state ) ) {
					self::post_commit_cleanup( $state );
				}
			} finally {
				self::release_lock( $job_id, $lock );
			}
		} else {
			// A worker owns the lock. A read-only projection remains safe, while
			// deferred cleanup must wait so it cannot overwrite a newer checkpoint.
			$state = self::load_state( $job_id );
		}
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return self::public_status( $state );
	}

	/**
	 * Process exactly one bounded chunk stage, or the single finalization stage.
	 */
	public static function step( string $job_id, ?Story_Decomposer $decomposer = null ) {
		$lock = self::acquire_lock( $job_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		self::register_shutdown_lock_cleanup( $job_id, $lock );

		try {
			$state = self::load_state( $job_id );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			if ( in_array( (string) $state['status'], [ 'complete', 'failed', 'cancelled' ], true ) ) {
				self::post_commit_cleanup( $state );
				delete_transient( self::cancel_key( $job_id ) );
				return self::public_status( $state );
			}
			if ( self::cancellation_requested( $job_id ) ) {
				self::mark_cancelled( $state );
				$saved = self::save_state( $state );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				self::post_commit_cleanup( $state );
				delete_transient( self::cancel_key( $job_id ) );
				return self::public_status( $state );
			}

			$decomposer = $decomposer ?: new Story_Decomposer();
			$state['status'] = 'running';
			$state['last_error'] = [];
			$saved = self::save_state( $state );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			try {
				switch ( (string) $state['stage'] ) {
					case 'analysis':
						$result = self::run_analysis_step( $state, $decomposer );
						break;
					case 'synthesis':
						$result = self::run_synthesis_step( $state, $decomposer );
						break;
					case 'finalize':
						$result = self::run_finalize_step( $state, $decomposer );
						break;
					default:
						$result = new \WP_Error( 'worldgraph_story_decomposition_stage_invalid', __( 'The decomposition job has an invalid processing stage.', 'worldgraph' ) );
				}
			} catch ( \Throwable $throwable ) {
				$result = new \WP_Error( 'worldgraph_story_decomposition_step_failed', __( 'The current story decomposition stage could not be completed.', 'worldgraph' ) );
			}

			if ( self::cancellation_requested( $job_id ) ) {
				self::mark_cancelled( $state );
			} elseif ( is_wp_error( $result ) ) {
				$disposition = self::defer_retryable_failure( $state, $result );
				if ( 'exhausted' === $disposition ) {
					self::mark_failed(
						$state,
						new \WP_Error(
							'worldgraph_story_decomposition_retry_exhausted',
							__( 'The LLM Connection remained unavailable after the bounded retry allowance. Start over when the provider is available.', 'worldgraph' )
						)
					);
				} elseif ( 'terminal' === $disposition ) {
					self::mark_failed( $state, $result );
				}
			} else {
				unset( $state['retry'] );
				if ( 'complete' !== (string) $state['status'] ) {
					$state['status'] = 'ready';
				}
			}

			$saved = self::save_state( $state );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			self::post_commit_cleanup( $state );
			if ( 'cancelled' === (string) ( $state['status'] ?? '' ) ) {
				delete_transient( self::cancel_key( $job_id ) );
			}

			return self::public_status( $state );
		} finally {
			self::release_lock( $job_id, $lock );
		}
	}

	/** Request cancellation without exposing or deleting the retained source attachment. */
	public static function cancel( string $job_id ) {
		$state = self::load_state( $job_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( in_array( (string) $state['status'], [ 'complete', 'failed', 'cancelled' ], true ) ) {
			delete_transient( self::cancel_key( $job_id ) );
			return self::status( $job_id );
		}

		$cancel_key = self::cancel_key( $job_id );
		set_transient( $cancel_key, 1, max( 1, (int) $state['expires_at'] - time() ) );
		if ( 1 !== get_transient( $cancel_key ) ) {
			return new \WP_Error(
				'worldgraph_story_decomposition_cancel_checkpoint_failed',
				__( 'The cancellation request could not be checkpointed. Try again.', 'worldgraph' ),
				[ 'status' => 503 ]
			);
		}
		$lock = self::acquire_lock( $job_id );
		if ( is_wp_error( $lock ) ) {
			$status = self::public_status( $state );
			$status['status']      = 'cancelling';
			$status['stage_label'] = __( 'Cancelling', 'worldgraph' );
			$status['section']     = __( 'Waiting for the active section to stop safely.', 'worldgraph' );
			$status['can_step']    = false;
			return $status;
		}
		self::register_shutdown_lock_cleanup( $job_id, $lock );

		try {
			$state = self::load_state( $job_id );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			// The job may have reached a terminal checkpoint after the initial
			// read but before this request acquired the lock. Never overwrite it.
			if ( in_array( (string) $state['status'], [ 'complete', 'failed', 'cancelled' ], true ) ) {
				self::post_commit_cleanup( $state );
				delete_transient( $cancel_key );
				return self::public_status( $state );
			}
			self::mark_cancelled( $state );
			$saved = self::save_state( $state );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			self::post_commit_cleanup( $state );
			delete_transient( self::cancel_key( $job_id ) );
			return self::public_status( $state );
		} finally {
			self::release_lock( $job_id, $lock );
		}
	}

	/** Normalize a trusted decomposer plan while keeping manuscript data private. */
	private static function normalize_plan( $planned, string $fallback_text ) {
		if ( ! is_array( $planned ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_plan_invalid', __( 'The story decomposer returned an invalid section plan.', 'worldgraph' ) );
		}

		$plan    = is_array( $planned['plan'] ?? null ) ? $planned['plan'] : $planned;
		$chunks  = is_array( $planned['chunks'] ?? null ) ? $planned['chunks'] : ( is_array( $plan['chunks'] ?? null ) ? $plan['chunks'] : [] );
		$profile = is_array( $planned['profile'] ?? null ) ? $planned['profile'] : ( is_array( $plan['profile'] ?? null ) ? $plan['profile'] : [] );
		if ( empty( $chunks ) || count( $chunks ) > self::MAX_PLANNED_CHUNKS ) {
			return new \WP_Error( 'worldgraph_story_decomposition_plan_invalid', __( 'The story decomposer returned an unsupported number of sections.', 'worldgraph' ) );
		}

		$normalized_chunks = self::normalize_chunks( $chunks );
		if ( is_wp_error( $normalized_chunks ) ) {
			return $normalized_chunks;
		}
		$prepared_text = self::planned_source_text( $planned, $plan, $normalized_chunks, $fallback_text );
		if ( '' === $prepared_text || ! hash_equals( $prepared_text, implode( '', array_column( $normalized_chunks, 'text' ) ) ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_plan_coverage_invalid', __( 'The story section plan did not preserve the complete prepared manuscript.', 'worldgraph' ) );
		}

		$source_title   = sanitize_text_field( (string) ( $planned['source_title'] ?? $plan['source_title'] ?? '' ) );
		$plan['chunks'] = $normalized_chunks;
		unset( $plan['text'], $plan['source_text'], $plan['source_title'], $plan['profile'], $plan['connection_id'], $plan['boundaries'] );

		return [
			'plan'          => $plan,
			'profile'       => $profile,
			'source_sha256' => hash( 'sha256', $prepared_text ),
			'source_title'  => $source_title,
		];
	}

	/** Normalize exact, ordered chunk snapshots and give each one a stable private key. */
	private static function normalize_chunks( array $chunks, string $parent_id = '' ) {
		$normalized = [];
		$ids        = [];
		foreach ( array_values( $chunks ) as $index => $chunk ) {
			if ( ! is_array( $chunk ) || ! is_scalar( $chunk['text'] ?? null ) ) {
				return new \WP_Error( 'worldgraph_story_decomposition_chunk_invalid', __( 'The story section plan contains an invalid section.', 'worldgraph' ) );
			}
			$text = (string) $chunk['text'];
			if ( '' === $text ) {
				return new \WP_Error( 'worldgraph_story_decomposition_chunk_empty', __( 'The story section plan contains an empty section.', 'worldgraph' ) );
			}

			$id_seed = (string) ( $chunk['id'] ?? $chunk['sha256'] ?? '' );
			$id      = substr( sanitize_key( str_replace( ':', '-', $id_seed ) ), 0, 96 );
			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 'part-' . ( $index + 1 ) . '-' . substr( hash( 'sha256', $parent_id . "\n" . $index . "\n" . $text ), 0, 16 );
			}
			$ids[ $id ]   = true;
			$chunk['id']  = $id;
			$chunk['text'] = $text;
			$chunk['sha256'] = hash( 'sha256', $text );
			$normalized[] = $chunk;
		}

		return $normalized;
	}

	/** Resolve the prepared source represented exactly by the planned chunks. */
	private static function planned_source_text( array $planned, array $plan, array $chunks, string $fallback_text ): string {
		foreach ( [ $planned['source_text'] ?? null, $planned['text'] ?? null, $plan['source_text'] ?? null, $plan['text'] ?? null ] as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== (string) $candidate ) {
				return (string) $candidate;
			}
		}

		$joined = implode( '', array_column( $chunks, 'text' ) );
		return '' !== $joined ? $joined : $fallback_text;
	}

	/** Persist exact manuscript chunks separately and return minimal state references. */
	private static function store_chunk_shards( array $state, array $chunks ) {
		$descriptors = [];
		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) || '' === (string) ( $chunk['id'] ?? '' ) ) {
				self::delete_shard_references( $state, 'chunk', $descriptors );
				return new \WP_Error( 'worldgraph_story_decomposition_chunk_invalid', __( 'The story section plan contains an invalid section.', 'worldgraph' ) );
			}
			$reference = self::store_value_shard( $state, 'chunk', (string) $chunk['id'], $chunk );
			if ( is_wp_error( $reference ) ) {
				self::delete_shard_references( $state, 'chunk', $descriptors );
				return $reference;
			}

			// Everything except the stable ordering ID and authenticated shard
			// reference is already stored inside the shard. Keeping metadata and
			// context descriptors here as well can push a 1,000-part job beyond
			// common persistent-object-cache item limits.
			$descriptors[] = [
				'id'           => (string) $chunk['id'],
				'shard_id'     => (string) $reference['shard_id'],
				'shard_sha256' => (string) $reference['shard_sha256'],
			];
		}

		return $descriptors;
	}

	/** Store one bounded array payload and verify the exact persisted value. */
	private static function store_value_shard( array $state, string $kind, string $shard_id, array $value ) {
		$job_id = self::sanitize_job_id( (string) ( $state['job_id'] ?? '' ) );
		$user_id = absint( $state['user_id'] ?? 0 );
		$ttl     = absint( $state['expires_at'] ?? 0 ) - time();
		if ( '' === $job_id || ! $user_id || $ttl <= 0 || ! in_array( $kind, self::SHARD_KINDS, true ) || '' === $shard_id ) {
			return new \WP_Error( 'worldgraph_story_decomposition_shard_invalid', __( 'A private story checkpoint shard could not be created.', 'worldgraph' ) );
		}

		$serialized = serialize( $value );
		if ( strlen( $serialized ) > self::MAX_SHARD_BYTES ) {
			return new \WP_Error( 'worldgraph_story_decomposition_shard_too_large', __( 'A private story checkpoint shard exceeded its bounded storage limit.', 'worldgraph' ) );
		}
		$value_hash = hash( 'sha256', $serialized );
		$record     = [
			'version'      => self::VERSION,
			'job_id'       => $job_id,
			'user_id'      => $user_id,
			'kind'         => $kind,
			'shard_id'     => $shard_id,
			'value_sha256' => $value_hash,
			'value'        => $value,
		];
		$key        = self::shard_key( $job_id, $user_id, $kind, $shard_id );
		set_transient( $key, $record, $ttl );
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || $stored !== $record ) {
			delete_transient( $key );
			return new \WP_Error( 'worldgraph_story_decomposition_shard_checkpoint_failed', __( 'A private story checkpoint shard could not be stored.', 'worldgraph' ) );
		}

		return [
			'shard_id'     => $shard_id,
			'shard_sha256' => $value_hash,
		];
	}

	/** Load and authenticate one private bounded shard through its state reference. */
	private static function load_shard_value( array $state, string $kind, $reference ) {
		if ( ! is_array( $reference ) || ! in_array( $kind, self::SHARD_KINDS, true ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_shard_missing', __( 'A private story checkpoint shard is missing.', 'worldgraph' ) );
		}
		$job_id   = self::sanitize_job_id( (string) ( $state['job_id'] ?? '' ) );
		$user_id  = absint( $state['user_id'] ?? 0 );
		$shard_id = (string) ( $reference['shard_id'] ?? '' );
		$expected = (string) ( $reference['shard_sha256'] ?? '' );
		if ( '' === $job_id || ! $user_id || '' === $shard_id || '' === $expected ) {
			return new \WP_Error( 'worldgraph_story_decomposition_shard_missing', __( 'A private story checkpoint shard is missing.', 'worldgraph' ) );
		}

		$record = get_transient( self::shard_key( $job_id, $user_id, $kind, $shard_id ) );
		$value  = is_array( $record ) && is_array( $record['value'] ?? null ) ? $record['value'] : null;
		if (
			! is_array( $record ) || ! is_array( $value )
			|| self::VERSION !== absint( $record['version'] ?? 0 )
			|| ! hash_equals( $job_id, (string) ( $record['job_id'] ?? '' ) )
			|| $user_id !== absint( $record['user_id'] ?? 0 )
			|| $kind !== (string) ( $record['kind'] ?? '' )
			|| ! hash_equals( $shard_id, (string) ( $record['shard_id'] ?? '' ) )
			|| ! hash_equals( $expected, (string) ( $record['value_sha256'] ?? '' ) )
			|| ! hash_equals( $expected, self::value_hash( $value ) )
		) {
			return new \WP_Error( 'worldgraph_story_decomposition_shard_integrity_failed', __( 'A private story checkpoint shard failed its integrity check.', 'worldgraph' ) );
		}

		return $value;
	}

	/** Hash serialized array values without placing their contents in the state record. */
	private static function value_hash( array $value ): string {
		return hash( 'sha256', serialize( $value ) );
	}

	/** Queue a shard for deletion after the referencing state checkpoint commits. */
	private static function queue_shard_cleanup( array &$state, string $kind, string $shard_id ): void {
		if ( ! in_array( $kind, self::SHARD_KINDS, true ) || '' === $shard_id ) {
			return;
		}
		$key = $kind . ':' . $shard_id;
		$state['cleanup_shards'][ $key ] = [ 'kind' => $kind, 'shard_id' => $shard_id ];
	}

	/** Delete only shards whose obsolete references were committed in core state. */
	private static function cleanup_pending_shards( array &$state ): bool {
		$changed = false;
		foreach ( (array) ( $state['cleanup_shards'] ?? [] ) as $key => $reference ) {
			if ( ! is_array( $reference ) ) {
				unset( $state['cleanup_shards'][ $key ] );
				$changed = true;
				continue;
			}
			if ( self::delete_shard( $state, (string) ( $reference['kind'] ?? '' ), (string) ( $reference['shard_id'] ?? '' ) ) ) {
				unset( $state['cleanup_shards'][ $key ] );
				$changed = true;
			}
		}
		if ( empty( $state['cleanup_shards'] ) ) {
			unset( $state['cleanup_shards'] );
		}
		return $changed;
	}

	/** Finish shard/RAG cleanup after the state no longer references derivative data. */
	private static function post_commit_cleanup( array &$state ): void {
		$changed = self::cleanup_pending_shards( $state );
		$status  = sanitize_key( (string) ( $state['status'] ?? '' ) );
		$scope   = (string) ( $state['run_scope'] ?? '' );
		if (
			! empty( $state['rag_cleanup_pending'] )
			&& in_array( $status, [ 'complete', 'failed', 'cancelled' ], true )
			&& 1 === preg_match( '/\A[a-f0-9]{64}\z/', $scope )
			&& function_exists( 'do_action' )
		) {
			try {
				do_action(
					'worldgraph_story_rag_cleanup',
					$scope,
					[
						'user_id'       => absint( $state['user_id'] ?? 0 ),
						'source_sha256' => sanitize_text_field( (string) ( $state['source_sha256'] ?? '' ) ),
						'status'        => $status,
					]
				);
				$state['rag_cleanup_pending'] = false;
				$changed = true;
			} catch ( \Throwable $throwable ) {
				// The durable pending marker lets a later status/step request retry.
			}
		}
		if ( $changed ) {
			// Best effort: on failure the durable cleanup list/pending flag is retried
			// during the next authenticated load, while the primary checkpoint remains valid.
			self::save_state( $state );
		}
	}

	/** Roll back a list of newly stored references before they enter core state. */
	private static function delete_shard_references( array $state, string $kind, array $references ): void {
		foreach ( $references as $reference ) {
			if ( is_array( $reference ) ) {
				self::delete_shard( $state, $kind, (string) ( $reference['shard_id'] ?? '' ) );
			}
		}
	}

	/** Delete every shard currently named by an uncommitted state. */
	private static function delete_all_state_shards( array $state ): void {
		foreach ( (array) ( $state['plan']['chunks'] ?? [] ) as $reference ) {
			if ( is_array( $reference ) ) {
				self::delete_shard( $state, 'chunk', (string) ( $reference['shard_id'] ?? $reference['id'] ?? '' ) );
			}
		}
		foreach ( [ 'analysis_results' => 'analysis', 'documents' => 'document' ] as $field => $kind ) {
			foreach ( (array) ( $state[ $field ] ?? [] ) as $reference ) {
				if ( is_array( $reference ) ) {
					self::delete_shard( $state, $kind, (string) ( $reference['shard_id'] ?? '' ) );
				}
			}
		}
		if ( is_array( $state['graph_memory'] ?? null ) ) {
			self::delete_shard( $state, 'memory', (string) ( $state['graph_memory']['shard_id'] ?? '' ) );
		}
		self::cleanup_pending_shards( $state );
	}

	/** Delete one private shard through its fully derived, bounded key. */
	private static function delete_shard( array $state, string $kind, string $shard_id ): bool {
		$job_id = self::sanitize_job_id( (string) ( $state['job_id'] ?? '' ) );
		$user_id = absint( $state['user_id'] ?? 0 );
		if ( '' !== $job_id && $user_id && '' !== $shard_id && in_array( $kind, self::SHARD_KINDS, true ) ) {
			$key = self::shard_key( $job_id, $user_id, $kind, $shard_id );
			delete_transient( $key );
			return false === get_transient( $key );
		}
		return false;
	}

	/** Select a constant-size evidence neighborhood for lexical/RAG candidate ranking. */
	private static function retrieval_candidate_indexes( int $total, int $cursor ): array {
		$indexes = [];
		$start   = max( 0, $cursor - self::RETRIEVAL_RADIUS );
		$end     = min( $total - 1, $cursor + self::RETRIEVAL_RADIUS );
		for ( $index = $start; $index <= $end; $index++ ) {
			$indexes[] = $index;
		}
		return $indexes;
	}

	/** Process one evidence-extraction section and checkpoint its private result. */
	private static function run_analysis_step( array &$state, Story_Decomposer $decomposer ) {
		if ( ! method_exists( $decomposer, 'analyze_planned_chunk' ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_analysis_unavailable', __( 'The installed story decomposer does not support resumable analysis.', 'worldgraph' ) );
		}

		$chunks = array_values( (array) ( $state['plan']['chunks'] ?? [] ) );
		$cursor = absint( $state['analysis_cursor'] ?? 0 );
		$total  = count( $chunks );
		if ( $cursor >= $total ) {
			$state['stage'] = 'synthesis';
			return true;
		}

		$chunk_descriptor = $chunks[ $cursor ];
		$chunk = self::load_shard_value( $state, 'chunk', $chunk_descriptor );
		if ( is_wp_error( $chunk ) ) {
			return $chunk;
		}
		$integrity = self::verify_chunk( $chunk );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}
		$result = $decomposer->analyze_planned_chunk(
			$chunk,
			$cursor + 1,
			$total,
			(string) $state['filename'],
			(int) $state['connection_id'],
			(array) $state['profile']
		);
		if ( is_wp_error( $result ) ) {
			self::merge_error_metrics( $state['metrics'], $result );
			$data = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['retryable'] ) ) {
				$subdivision = self::subdivide_chunk( $decomposer, $chunk, (array) $state['profile'] );
				if ( ! is_wp_error( $subdivision ) ) {
					if ( count( $chunks ) - 1 + count( $subdivision ) > self::MAX_PLANNED_CHUNKS ) {
						return new \WP_Error( 'worldgraph_story_decomposition_too_many_sections', __( 'Adaptive recovery would create too many story sections.', 'worldgraph' ) );
					}
					if ( ! hash_equals( (string) $chunk['text'], implode( '', array_column( $subdivision, 'text' ) ) ) ) {
						return new \WP_Error( 'worldgraph_story_decomposition_subdivision_invalid', __( 'Adaptive recovery did not preserve the complete story section.', 'worldgraph' ) );
					}
					$used_ids = [];
					foreach ( $chunks as $existing_chunk ) {
						if ( is_array( $existing_chunk ) ) {
							$used_ids[ (string) ( $existing_chunk['id'] ?? '' ) ] = true;
						}
					}
					foreach ( $subdivision as $subdivision_index => &$replacement ) {
						$replacement_id = (string) ( $replacement['id'] ?? '' );
						if ( '' === $replacement_id || isset( $used_ids[ $replacement_id ] ) ) {
							$replacement_id = 'part-' . ( $cursor + 1 ) . '-' . ( $subdivision_index + 1 ) . '-' . substr( hash( 'sha256', (string) $replacement['text'] ), 0, 16 );
							$replacement['id'] = $replacement_id;
						}
						$used_ids[ $replacement_id ] = true;
					}
					unset( $replacement );
					$sharded_subdivision = self::store_chunk_shards( $state, $subdivision );
					if ( is_wp_error( $sharded_subdivision ) ) {
						return $sharded_subdivision;
					}
					self::queue_shard_cleanup( $state, 'chunk', (string) ( $chunk_descriptor['shard_id'] ?? '' ) );
					array_splice( $chunks, $cursor, 1, $sharded_subdivision );
					$state['plan']['chunks']       = $chunks;
					$state['chunk_count']          = count( $chunks );
					$state['metrics']['subdivisions'] = absint( $state['metrics']['subdivisions'] ?? 0 ) + 1;
					return true;
				}
			}
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_analysis_invalid', __( 'The model returned an invalid story analysis result.', 'worldgraph' ) );
		}

		$evidence = $result['evidence'] ?? $result['analysis'] ?? $result['document'] ?? null;
		if ( ! is_array( $evidence ) ) {
			$evidence = self::without_metrics( $result );
		}
		if ( empty( $evidence ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_analysis_empty', __( 'The model returned no usable story evidence for this section.', 'worldgraph' ) );
		}

		$chunk_id = (string) $chunk['id'];
		$evidence_ref = self::store_value_shard( $state, 'analysis', $chunk_id, $evidence );
		if ( is_wp_error( $evidence_ref ) ) {
			return $evidence_ref;
		}
		$state['analysis_results'][ $chunk_id ] = $evidence_ref;
		$state['analysis_cursor'] = $cursor + 1;
		self::merge_metrics( $state['metrics'], $result );
		if ( $state['analysis_cursor'] >= count( $chunks ) ) {
			$state['stage'] = 'synthesis';
		}

		return true;
	}

	/** Turn one accepted evidence result into one bounded partial graph. */
	private static function run_synthesis_step( array &$state, Story_Decomposer $decomposer ) {
		if ( ! method_exists( $decomposer, 'synthesize_planned_chunk' ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_synthesis_unavailable', __( 'The installed story decomposer does not support resumable synthesis.', 'worldgraph' ) );
		}

		$chunks = array_values( (array) ( $state['plan']['chunks'] ?? [] ) );
		$cursor = absint( $state['synthesis_cursor'] ?? 0 );
		$total  = count( $chunks );
		if ( $cursor >= $total ) {
			$state['stage'] = 'finalize';
			return true;
		}

		$chunk_descriptor = $chunks[ $cursor ];
		$chunk_id = (string) $chunk_descriptor['id'];
		$chunk = self::load_shard_value( $state, 'chunk', $chunk_descriptor );
		if ( is_wp_error( $chunk ) ) {
			return $chunk;
		}
		$integrity = self::verify_chunk( $chunk );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
		}
		$evidence_ref = $state['analysis_results'][ $chunk_id ] ?? null;
		$evidence = self::load_shard_value( $state, 'analysis', $evidence_ref );
		if ( is_wp_error( $evidence ) ) {
			return $evidence;
		}
		if ( count( (array) ( $state['analysis_results'] ?? [] ) ) !== $total ) {
			return new \WP_Error( 'worldgraph_story_decomposition_evidence_missing', __( 'One or more checkpointed story analysis results are missing.', 'worldgraph' ) );
		}

		$candidate_evidence = [ $cursor => $evidence ];
		foreach ( self::retrieval_candidate_indexes( $total, $cursor ) as $candidate_index ) {
			if ( $candidate_index === $cursor ) {
				continue;
			}
			$planned_chunk = is_array( $chunks[ $candidate_index ] ?? null ) ? $chunks[ $candidate_index ] : [];
			$planned_id    = (string) ( $planned_chunk['id'] ?? '' );
			if ( '' === $planned_id || ! is_array( $state['analysis_results'][ $planned_id ] ?? null ) ) {
				return new \WP_Error( 'worldgraph_story_decomposition_evidence_missing', __( 'A checkpointed story analysis result is missing.', 'worldgraph' ) );
			}
			$planned_evidence = self::load_shard_value( $state, 'analysis', $state['analysis_results'][ $planned_id ] );
			if ( is_wp_error( $planned_evidence ) ) {
				return $planned_evidence;
			}
			$candidate_evidence[ $candidate_index ] = $planned_evidence;
		}
		if ( method_exists( $decomposer, 'retrieve_planned_evidence' ) ) {
			$evidence_bundle = $decomposer->retrieve_planned_evidence( $chunk, $candidate_evidence, $cursor, (array) $state['profile'] );
		} else {
			$related_candidates = $candidate_evidence;
			unset( $related_candidates[ $cursor ] );
			$evidence_bundle = [
				'backend' => 'recent',
				'current' => $evidence,
				'related' => array_slice( array_values( $related_candidates ), 0, 3 ),
			];
		}
		if ( ! is_array( $evidence_bundle ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_retrieval_invalid', __( 'The private evidence retrieval step returned an invalid result.', 'worldgraph' ) );
		}
		$related = is_array( $evidence_bundle['related'] ?? null )
			? $evidence_bundle['related']
			: ( is_array( $evidence_bundle['retrieved'] ?? null ) ? $evidence_bundle['retrieved'] : [] );
		$related     = array_values( array_filter( $related, 'is_array' ) );
		$related_ids = [];
		foreach ( $related as $related_evidence ) {
			$related_id = (string) ( $related_evidence['_retrieval']['chunk_id'] ?? '' );
			if ( '' !== $related_id ) {
				$related_ids[ $related_id ] = true;
			}
		}
		$seen_indexes = [];
		foreach ( array_slice( (array) ( $evidence_bundle['related_indexes'] ?? [] ), 0, 3 ) as $related_index ) {
			if ( count( $related ) >= 3 ) {
				break;
			}
			if ( ! is_int( $related_index ) || $related_index < 0 || $related_index >= $total || $related_index === $cursor || isset( $seen_indexes[ $related_index ] ) ) {
				continue;
			}
			$seen_indexes[ $related_index ] = true;
			$related_chunk = is_array( $chunks[ $related_index ] ?? null ) ? $chunks[ $related_index ] : [];
			$related_id    = (string) ( $related_chunk['id'] ?? '' );
			if ( '' === $related_id || isset( $related_ids[ $related_id ] ) ) {
				continue;
			}
			if ( isset( $candidate_evidence[ $related_index ] ) ) {
				$related[] = $candidate_evidence[ $related_index ];
				$related_ids[ $related_id ] = true;
				continue;
			}
			$related_ref   = $state['analysis_results'][ $related_id ] ?? null;
			$resolved      = self::load_shard_value( $state, 'analysis', $related_ref );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$related[] = $resolved;
			$related_ids[ $related_id ] = true;
		}
		$evidence_bundle['related'] = array_slice( $related, 0, 3 );
		$indexed_count = isset( $evidence_bundle['indexed_count'] )
			? min( $total, absint( $evidence_bundle['indexed_count'] ) )
			: $total;
		unset( $evidence_bundle['related_indexes'], $evidence_bundle['retrieved'], $evidence_bundle['indexed_count'] );
		$related = $evidence_bundle['related'];
		$retrieval_metrics = is_array( $state['metrics']['retrieval'] ?? null ) ? $state['metrics']['retrieval'] : [];
		$state['metrics']['retrieval'] = [
			'backend'   => sanitize_key( (string) ( $evidence_bundle['backend'] ?? '' ) ),
			'indexed'   => $indexed_count,
			'retrieved' => absint( $retrieval_metrics['retrieved'] ?? 0 ) + count( $related ),
		];

		if ( count( (array) ( $state['documents'] ?? [] ) ) !== $cursor ) {
			return new \WP_Error( 'worldgraph_story_decomposition_documents_missing', __( 'A checkpointed partial story graph is missing.', 'worldgraph' ) );
		}
		$previous_memory    = [];
		$accepted_documents = [];
		if ( $cursor > 0 ) {
			$previous_memory = self::load_shard_value( $state, 'memory', $state['graph_memory'] ?? null );
			if ( is_wp_error( $previous_memory ) ) {
				return $previous_memory;
			}
			$accepted_documents[] = $previous_memory;
		}

		$result = $decomposer->synthesize_planned_chunk(
			$chunk,
			$evidence_bundle,
			$accepted_documents,
			$cursor + 1,
			$total,
			(string) $state['filename'],
			(int) $state['connection_id'],
			(array) $state['profile']
		);
		if ( is_wp_error( $result ) ) {
			self::merge_error_metrics( $state['metrics'], $result );
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_synthesis_invalid', __( 'The model returned an invalid partial story graph.', 'worldgraph' ) );
		}

		$document = is_array( $result['document'] ?? null ) ? $result['document'] : self::without_metrics( $result );
		if ( empty( $document ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_synthesis_empty', __( 'The model returned an empty partial story graph.', 'worldgraph' ) );
		}

		$document_ref = self::store_value_shard( $state, 'document', $chunk_id, $document );
		if ( is_wp_error( $document_ref ) ) {
			return $document_ref;
		}
		$state['documents'][ $chunk_id ] = $document_ref;
		if ( ! method_exists( $decomposer, 'graph_memory' ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_memory_unavailable', __( 'The installed story decomposer cannot checkpoint evolving graph memory.', 'worldgraph' ) );
		}
		$memory_input = [];
		if ( ! empty( $previous_memory ) ) {
			$memory_input[-1] = $previous_memory;
		}
		$memory_input[ $cursor ] = $document;
		$graph_memory = $decomposer->graph_memory(
			$memory_input,
			max( 600, absint( $state['profile']['memory_chars'] ?? 3_000 ) )
		);
		if ( ! is_array( $graph_memory ) || empty( $graph_memory ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_memory_invalid', __( 'The evolving graph memory could not be checkpointed.', 'worldgraph' ) );
		}
		$memory_ref = self::store_value_shard( $state, 'memory', 'graph-memory-' . ( $cursor + 1 ), $graph_memory );
		if ( is_wp_error( $memory_ref ) ) {
			return $memory_ref;
		}
		if ( is_array( $state['graph_memory'] ?? null ) ) {
			self::queue_shard_cleanup( $state, 'memory', (string) ( $state['graph_memory']['shard_id'] ?? '' ) );
		}
		$state['graph_memory'] = $memory_ref;
		$state['synthesis_cursor'] = $cursor + 1;
		self::merge_metrics( $state['metrics'], $result );
		if ( $state['synthesis_cursor'] >= $total ) {
			$state['stage'] = 'finalize';
		}

		return true;
	}

	/** Compile, validate, and place the final candidate behind the existing preview boundary. */
	private static function run_finalize_step( array &$state, Story_Decomposer $decomposer ) {
		if ( ! method_exists( $decomposer, 'finalize_planned_documents' ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_finalize_unavailable', __( 'The installed story decomposer does not support resumable finalization.', 'worldgraph' ) );
		}

		$chunk_descriptors = array_values( (array) ( $state['plan']['chunks'] ?? [] ) );
		if ( count( (array) $state['documents'] ) !== count( $chunk_descriptors ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_documents_missing', __( 'One or more checkpointed partial story graphs are missing.', 'worldgraph' ) );
		}
		$chunks    = [];
		$documents = [];
		foreach ( $chunk_descriptors as $chunk_descriptor ) {
			$chunk = self::load_shard_value( $state, 'chunk', $chunk_descriptor );
			if ( is_wp_error( $chunk ) ) {
				return $chunk;
			}
			$chunk_id = (string) ( $chunk['id'] ?? '' );
			$document_ref = $state['documents'][ $chunk_id ] ?? null;
			$document = self::load_shard_value( $state, 'document', $document_ref );
			if ( is_wp_error( $document ) ) {
				return $document;
			}
			$chunks[]    = $chunk;
			$documents[] = $document;
		}
		$source_text = implode( '', array_column( $chunks, 'text' ) );
		if ( ! hash_equals( (string) $state['source_sha256'], hash( 'sha256', $source_text ) ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_source_changed', __( 'The checkpointed story source failed its integrity check.', 'worldgraph' ) );
		}

		$final_plan           = (array) $state['plan'];
		$final_plan['chunks'] = $chunks;
		$result               = $decomposer->finalize_planned_documents(
			$documents,
			$source_text,
			(string) $state['filename'],
			(string) $state['source_title'],
			(int) $state['connection_id'],
			(array) $state['metrics'],
			$final_plan
		);
		if ( is_wp_error( $result ) ) {
			self::merge_error_metrics( $state['metrics'], $result );
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_finalize_invalid', __( 'The story decomposer returned an invalid final candidate.', 'worldgraph' ) );
		}

		$json = is_scalar( $result['json'] ?? null ) ? (string) $result['json'] : '';
		if ( '' === $json && is_array( $result['document'] ?? null ) ) {
			$json = (string) wp_json_encode( $result['document'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( '' === $json ) {
			return new \WP_Error( 'worldgraph_story_decomposition_json_missing', __( 'The story decomposer returned no final JSON candidate.', 'worldgraph' ) );
		}

		$metrics = self::authoritative_final_metrics( (array) $state['metrics'], $result );
		if ( ! class_exists( '\\WorldGraph\\Admin\\Import' ) || ! method_exists( '\\WorldGraph\\Admin\\Import', 'store_candidate_preview' ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_preview_unavailable', __( 'The final story candidate could not be placed in the review workflow.', 'worldgraph' ) );
		}

		$preview = \WorldGraph\Admin\Import::store_candidate_preview(
			(int) $state['attachment_id'],
			[
				'filename'   => (string) $state['filename'],
				'format'     => (string) $state['format'],
				'characters' => (int) $state['source_characters'],
			],
			$json,
			! empty( $state['overwrite'] ),
			[
				'generated'       => true,
				'attempts'        => absint( $metrics['attempts'] ?? 0 ),
				'tokens'          => absint( $metrics['tokens'] ?? 0 ),
				'chunks'          => count( $chunks ),
				'backend'         => sanitize_key( (string) ( $metrics['backend'] ?? '' ) ),
				'model'           => sanitize_text_field( (string) ( $metrics['model'] ?? '' ) ),
				'connection_id'   => (int) $state['connection_id'],
				'connection_name' => (string) $state['connection_name'],
				'passes'          => max( 2, absint( $result['passes'] ?? 2 ) ),
				'sections'        => array_values( array_map( 'sanitize_text_field', array_filter( (array) ( $result['sections'] ?? [] ), 'is_scalar' ) ) ),
				'context_window'  => absint( $result['context_window'] ?? $metrics['context_window'] ?? 0 ),
				'retrieval'       => [
					'backend'   => sanitize_key( (string) ( $metrics['retrieval']['backend'] ?? '' ) ),
					'indexed'   => absint( $metrics['retrieval']['indexed'] ?? 0 ),
					'retrieved' => absint( $metrics['retrieval']['retrieved'] ?? 0 ),
				],
			]
		);
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$state['metrics']            = $metrics;
		$state['status']             = 'complete';
		$state['stage']              = 'complete';
		$state['preview_token']      = (string) $preview['token'];
		$state['preview_url']        = (string) $preview['url'];
		$state['preview_expires_at'] = absint( $preview['expires_at'] ?? ( time() + self::COMPLETED_TTL ) );
		// A completed job is only useful while its candidate preview exists.
		$state['expires_at']         = $state['preview_expires_at'];
		$state['last_error']         = [];
		self::clear_working_data( $state );

		return true;
	}

	/** Ask the decomposer for an exact adaptive replacement of one failed chunk. */
	private static function subdivide_chunk( Story_Decomposer $decomposer, array $chunk, array $profile ) {
		if ( ! method_exists( $decomposer, 'subdivide_planned_chunk' ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_subdivision_unavailable', __( 'The failed story section could not be divided further.', 'worldgraph' ) );
		}
		$subdivision = $decomposer->subdivide_planned_chunk( $chunk, $profile );
		if ( is_wp_error( $subdivision ) ) {
			return $subdivision;
		}
		if ( is_array( $subdivision['chunks'] ?? null ) ) {
			$subdivision = $subdivision['chunks'];
		}
		if ( ! is_array( $subdivision ) || count( $subdivision ) < 2 ) {
			return new \WP_Error( 'worldgraph_story_decomposition_subdivision_invalid', __( 'The failed story section could not be divided safely.', 'worldgraph' ) );
		}

		return self::normalize_chunks( $subdivision, (string) ( $chunk['id'] ?? '' ) );
	}

	/** Remove response bookkeeping before accepting a bare payload shape. */
	private static function without_metrics( array $result ): array {
		foreach ( [ 'attempts', 'tokens', 'backend', 'model', 'finish_reason', 'stop_reason', 'connection_id', 'metrics' ] as $field ) {
			unset( $result[ $field ] );
		}
		return $result;
	}

	/** Verify primary and contextual manuscript snapshots before every model call. */
	private static function verify_chunk( array $chunk ) {
		$text = is_scalar( $chunk['text'] ?? null ) ? (string) $chunk['text'] : '';
		$hash = (string) ( $chunk['sha256'] ?? '' );
		if ( '' === $text || '' === $hash || ! hash_equals( $hash, hash( 'sha256', $text ) ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_source_changed', __( 'A checkpointed story section failed its integrity check.', 'worldgraph' ) );
		}
		foreach ( [ 'context_before', 'context_after' ] as $field ) {
			$context = is_array( $chunk[ $field ] ?? null ) ? $chunk[ $field ] : [];
			if ( empty( $context ) || ! is_scalar( $context['text'] ?? null ) || ! is_scalar( $context['hash'] ?? null ) ) {
				continue;
			}
			if ( ! hash_equals( (string) $context['hash'], hash( 'sha256', (string) $context['text'] ) ) ) {
				return new \WP_Error( 'worldgraph_story_decomposition_source_changed', __( 'A checkpointed story context failed its integrity check.', 'worldgraph' ) );
			}
		}
		return true;
	}

	/** Add one bounded stage's provider metrics. */
	private static function merge_metrics( array &$metrics, array $result ): void {
		$stage_metrics = is_array( $result['metrics'] ?? null ) ? $result['metrics'] : $result;
		$metrics['attempts'] = absint( $metrics['attempts'] ?? 0 ) + absint( $stage_metrics['attempts'] ?? 0 );
		$metrics['tokens']   = absint( $metrics['tokens'] ?? 0 ) + absint( $stage_metrics['tokens'] ?? 0 );
		foreach ( [ 'backend' => 'sanitize_key', 'model' => 'sanitize_text_field' ] as $field => $sanitizer ) {
			if ( is_scalar( $stage_metrics[ $field ] ?? null ) && '' !== trim( (string) $stage_metrics[ $field ] ) ) {
				$metrics[ $field ] = $sanitizer( (string) $stage_metrics[ $field ] );
			}
		}
		$retrieval = is_array( $stage_metrics['retrieval'] ?? null ) ? $stage_metrics['retrieval'] : [];
		if ( is_scalar( $stage_metrics['retrieval_backend'] ?? null ) ) {
			$retrieval['backend'] = $stage_metrics['retrieval_backend'];
		}
		if ( ! empty( $retrieval ) ) {
			$metrics['retrieval'] = [
				'backend'    => sanitize_key( (string) ( $retrieval['backend'] ?? '' ) ),
				'indexed'    => absint( $retrieval['indexed'] ?? $retrieval['documents'] ?? 0 ),
				'retrieved'  => absint( $retrieval['retrieved'] ?? $retrieval['matches'] ?? 0 ),
			];
		}
	}

	/** Preserve billed attempts from a failed bounded stage when supplied. */
	private static function merge_error_metrics( array &$metrics, \WP_Error $error ): void {
		$data = $error->get_error_data();
		if ( is_array( $data ) ) {
			self::merge_metrics( $metrics, $data );
		}
	}

	/** Keep transient provider failures at the same durable checkpoint for manual resume. */
	private static function defer_retryable_failure( array &$state, \WP_Error $error ): string {
		if ( ! self::is_retryable_provider_error( $error ) ) {
			return 'terminal';
		}

		$stage      = sanitize_key( (string) ( $state['stage'] ?? '' ) );
		$cursor     = 'synthesis' === $stage ? absint( $state['synthesis_cursor'] ?? 0 ) : absint( $state['analysis_cursor'] ?? 0 );
		$checkpoint = $stage . ':' . $cursor . ':' . absint( $state['chunk_count'] ?? 0 );
		$previous   = is_array( $state['retry'] ?? null ) ? $state['retry'] : [];
		$failures   = hash_equals( $checkpoint, (string) ( $previous['checkpoint'] ?? '' ) )
			? absint( $previous['failures'] ?? 0 ) + 1
			: 1;
		$state['retry'] = [
			'checkpoint' => $checkpoint,
			'failures'   => $failures,
		];
		if ( $failures > self::MAX_CHECKPOINT_RETRIES ) {
			return 'exhausted';
		}

		$state['status'] = 'ready';
		$state['last_error'] = [
			'code'        => sanitize_key( (string) $error->get_error_code() ) ?: 'worldgraph_story_decomposition_retryable',
			'message'     => sprintf(
				/* translators: 1: failed request count, 2: retry allowance. */
				__( 'The LLM Connection was temporarily unavailable. Resume from the last safe checkpoint (retry %1$d of %2$d).', 'worldgraph' ),
				$failures,
				self::MAX_CHECKPOINT_RETRIES
			),
			'retryable'   => true,
			'retry_count' => $failures,
			'retry_limit' => self::MAX_CHECKPOINT_RETRIES,
		];
		return 'deferred';
	}

	/** Classify only temporary transport/provider failures, never malformed story content. */
	private static function is_retryable_provider_error( \WP_Error $error ): bool {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : [];
		$status = absint( $data['http_status'] ?? $data['status'] ?? 0 );
		if ( $status > 0 ) {
			return in_array( $status, [ 408, 409, 425, 429 ], true ) || ( $status >= 500 && $status <= 599 );
		}

		$code = sanitize_key( (string) $error->get_error_code() );
		if ( in_array( $code, [ 'http_request_failed', 'http_request_not_executed' ], true ) ) {
			return true;
		}
		if ( 'worldgraph_llm_request_failed' === $code ) {
			$provider_error = sanitize_key( (string) ( $data['provider_error'] ?? '' ) );
			return in_array( $provider_error, [ 'connection_error', 'api_error', 'rate_limit_exceeded', 'backend_no_response' ], true );
		}
		foreach ( [ 'timeout', 'timed_out', 'transport', 'connection_reset', 'rate_limit', 'temporarily_unavailable', 'service_unavailable' ] as $marker ) {
			if ( str_contains( $code, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/** Treat final metrics as authoritative when finalization returns them. */
	private static function authoritative_final_metrics( array $metrics, array $result ): array {
		$final = is_array( $result['metrics'] ?? null ) ? $result['metrics'] : $result;
		foreach ( [ 'attempts', 'tokens' ] as $field ) {
			if ( isset( $final[ $field ] ) ) {
				$metrics[ $field ] = absint( $final[ $field ] );
			}
		}
		foreach ( [ 'backend' => 'sanitize_key', 'model' => 'sanitize_text_field' ] as $field => $sanitizer ) {
			if ( is_scalar( $final[ $field ] ?? null ) && '' !== trim( (string) $final[ $field ] ) ) {
				$metrics[ $field ] = $sanitizer( (string) $final[ $field ] );
			}
		}
		return $metrics;
	}

	/** Mark a terminal failure using only a bounded, non-provider diagnostic. */
	private static function mark_failed( array &$state, \WP_Error $error ): void {
		$code    = sanitize_key( (string) $error->get_error_code() );
		$message = self::safe_error_message( $error );
		$state['status'] = 'failed';
		$state['last_error'] = [
			'code'    => $code ?: 'worldgraph_story_decomposition_failed',
			'message' => $message,
		];
		self::clear_working_data( $state );
		self::mark_attachment( $state, 'preview_failed' );
	}

	/** Mark cancellation and clear derivative manuscript data. */
	private static function mark_cancelled( array &$state ): void {
		$state['status']     = 'cancelled';
		$state['stage']      = 'cancelled';
		$state['last_error'] = [];
		self::clear_working_data( $state );
		self::mark_attachment( $state, 'preview_cancelled' );
	}

	/** Remove source excerpts and model intermediates after a terminal safe handoff. */
	private static function clear_working_data( array &$state ): void {
		foreach ( (array) ( $state['plan']['chunks'] ?? [] ) as $reference ) {
			if ( is_array( $reference ) ) {
				self::queue_shard_cleanup( $state, 'chunk', (string) ( $reference['shard_id'] ?? '' ) );
			}
		}
		foreach ( [ 'analysis_results' => 'analysis', 'documents' => 'document' ] as $field => $kind ) {
			foreach ( (array) ( $state[ $field ] ?? [] ) as $reference ) {
				if ( is_array( $reference ) ) {
					self::queue_shard_cleanup( $state, $kind, (string) ( $reference['shard_id'] ?? '' ) );
				}
			}
		}
		if ( is_array( $state['graph_memory'] ?? null ) ) {
			self::queue_shard_cleanup( $state, 'memory', (string) ( $state['graph_memory']['shard_id'] ?? '' ) );
		}
		$state['rag_cleanup_pending'] = true;
		unset( $state['profile'], $state['source_title'], $state['analysis_results'], $state['documents'], $state['graph_memory'], $state['retry'] );
		if ( isset( $state['plan'] ) && is_array( $state['plan'] ) ) {
			unset( $state['plan']['text'], $state['plan']['source_text'], $state['plan']['chunks'], $state['plan']['boundaries'] );
		}
	}

	/** Update the existing attachment workflow markers without exposing source data. */
	private static function mark_attachment( array $state, string $status ): void {
		$attachment_id = absint( $state['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			return;
		}
		update_post_meta( $attachment_id, '_worldgraph_story_import_status', sanitize_key( $status ) );
		update_post_meta( $attachment_id, '_worldgraph_story_imported_by', absint( $state['user_id'] ?? 0 ) );
	}

	/** Produce the only state shape permitted to cross the browser boundary. */
	private static function public_status( array $state ): array {
		$total      = max( 1, absint( $state['chunk_count'] ?? count( (array) ( $state['plan']['chunks'] ?? [] ) ) ) );
		$analysis   = min( $total, absint( $state['analysis_cursor'] ?? 0 ) );
		$synthesis  = min( $total, absint( $state['synthesis_cursor'] ?? 0 ) );
		$finalized  = 'complete' === (string) ( $state['status'] ?? '' ) ? 1 : 0;
		$total_work = ( $total * 2 ) + 1;
		$completed  = min( $total_work, $analysis + $synthesis + $finalized );
		$percent    = (int) floor( ( $completed / $total_work ) * 100 );
		$status     = sanitize_key( (string) ( $state['status'] ?? 'failed' ) );
		if ( self::cancellation_requested( (string) ( $state['job_id'] ?? '' ) ) && ! in_array( $status, [ 'complete', 'failed', 'cancelled' ], true ) ) {
			$status = 'cancelling';
		}

		$stage = sanitize_key( (string) ( $state['stage'] ?? 'analysis' ) );
		$labels = [
			'analysis'   => __( 'Analyzing story evidence', 'worldgraph' ),
			'synthesis'  => __( 'Building the evolving graph', 'worldgraph' ),
			'finalize'    => __( 'Finalizing and validating', 'worldgraph' ),
			'complete'    => __( 'Ready for review', 'worldgraph' ),
			'cancelled'   => __( 'Cancelled', 'worldgraph' ),
		];
		$section = __( 'Preparing the next bounded section.', 'worldgraph' );
		if ( 'analysis' === $stage ) {
			$section = sprintf(
				/* translators: 1: current section, 2: total sections. */
				__( 'Analysis section %1$d of %2$d.', 'worldgraph' ),
				min( $total, $analysis + 1 ),
				$total
			);
		} elseif ( 'synthesis' === $stage ) {
			$section = sprintf(
				/* translators: 1: current section, 2: total sections. */
				__( 'Graph section %1$d of %2$d.', 'worldgraph' ),
				min( $total, $synthesis + 1 ),
				$total
			);
		} elseif ( 'finalize' === $stage ) {
			$section = __( 'Compiling the reviewed sections into canonical World Graph Studio JSON.', 'worldgraph' );
		} elseif ( 'complete' === $stage ) {
			$section = __( 'The validated candidate is ready for review.', 'worldgraph' );
		} elseif ( 'cancelled' === $stage ) {
			$section = __( 'No Story Graph records were imported. The source remains in the Media Library.', 'worldgraph' );
		}
		if ( 'cancelling' === $status ) {
			$section = __( 'Waiting for the active section to stop safely.', 'worldgraph' );
		} elseif ( 'failed' === $status ) {
			$section = __( 'Preparation stopped at the last safe checkpoint. No Story Graph records were imported.', 'worldgraph' );
		}

		$error = is_array( $state['last_error'] ?? null ) ? $state['last_error'] : [];
		return [
			'job_id'       => (string) ( $state['job_id'] ?? '' ),
			'status'       => $status,
			'stage'        => $stage,
			'stage_label'  => 'cancelling' === $status
				? __( 'Cancelling', 'worldgraph' )
				: ( 'failed' === $status ? __( 'Preparation failed', 'worldgraph' ) : ( $labels[ $stage ] ?? __( 'Preparing story graph', 'worldgraph' ) ) ),
			'section'      => $section,
			'progress'     => [
				'completed' => $completed,
				'total'     => $total_work,
				'percent'   => 'complete' === $status ? 100 : min( 99, max( 0, $percent ) ),
			],
			'analysis'     => [ 'completed' => $analysis, 'total' => $total ],
			'synthesis'    => [ 'completed' => $synthesis, 'total' => $total ],
			'attempts'     => absint( $state['metrics']['attempts'] ?? 0 ),
			'tokens'       => absint( $state['metrics']['tokens'] ?? 0 ),
			'retrieval'    => [
				'backend'   => sanitize_key( (string) ( $state['metrics']['retrieval']['backend'] ?? '' ) ),
				'indexed'   => absint( $state['metrics']['retrieval']['indexed'] ?? 0 ),
				'retrieved' => absint( $state['metrics']['retrieval']['retrieved'] ?? 0 ),
			],
			'error'        => [
				'code'    => sanitize_key( (string) ( $error['code'] ?? '' ) ),
				'message' => sanitize_text_field( (string) ( $error['message'] ?? '' ) ),
				'retryable' => ! empty( $error['retryable'] ),
				'retry_count' => absint( $error['retry_count'] ?? 0 ),
				'retry_limit' => absint( $error['retry_limit'] ?? 0 ),
			],
			'can_step'     => ! in_array( $status, [ 'complete', 'failed', 'cancelled', 'cancelling' ], true ),
			'can_cancel'   => ! in_array( $status, [ 'complete', 'failed', 'cancelled', 'cancelling' ], true ),
			'preview_url'  => 'complete' === $status ? esc_url_raw( (string) ( $state['preview_url'] ?? '' ) ) : '',
			'preview_expires_at' => 'complete' === $status ? absint( $state['preview_expires_at'] ?? 0 ) : 0,
			'expires_at'   => absint( $state['expires_at'] ?? 0 ),
		];
	}

	/** Keep public diagnostics generic for provider- and credential-owned failures. */
	private static function safe_error_message( \WP_Error $error ): string {
		$code = sanitize_key( (string) $error->get_error_code() );
		if (
			str_contains( $code, 'llm' ) ||
			str_contains( $code, 'credential' ) ||
			str_contains( $code, 'connection' ) ||
			str_contains( $code, 'provider' )
		) {
			return __( 'The configured default LLM Connection could not complete this story section. Review the Connection and try again.', 'worldgraph' );
		}
		$message = sanitize_text_field( (string) $error->get_error_message() );
		return '' !== $message ? mb_substr( $message, 0, 500, 'UTF-8' ) : __( 'The story decomposition stage failed.', 'worldgraph' );
	}

	/** Read one unexpired job scoped by both its token and current user. */
	private static function load_state( string $job_id ) {
		$job_id = self::sanitize_job_id( $job_id );
		$user_id = get_current_user_id();
		if ( '' === $job_id || ! $user_id ) {
			return new \WP_Error( 'worldgraph_story_decomposition_not_found', __( 'The story decomposition job was not found.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_forbidden', __( 'You cannot access story decomposition jobs.', 'worldgraph' ), [ 'status' => 403 ] );
		}

		$state = get_transient( self::state_key( $job_id, $user_id ) );
		if ( ! is_array( $state ) || $user_id !== absint( $state['user_id'] ?? 0 ) || ! hash_equals( $job_id, (string) ( $state['job_id'] ?? '' ) ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_not_found', __( 'The story decomposition job was not found or has expired.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		$identity = (string) ( $state['identity_sha256'] ?? '' );
		if ( '' === $identity || ! hash_equals( $identity, self::identity_hash( $state ) ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_integrity_failed', __( 'The story decomposition job failed its integrity check.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		if ( time() >= absint( $state['expires_at'] ?? 0 ) ) {
			self::delete_all_state_shards( $state );
			delete_transient( self::state_key( $job_id, $user_id ) );
			delete_transient( self::cancel_key( $job_id ) );
			return new \WP_Error( 'worldgraph_story_decomposition_expired', __( 'The story decomposition job expired. Upload the source again.', 'worldgraph' ), [ 'status' => 410 ] );
		}

		return $state;
	}

	/** Persist state without extending its fixed active or preview-aligned deadline. */
	private static function save_state( array &$state ) {
		$job_id = self::sanitize_job_id( (string) ( $state['job_id'] ?? '' ) );
		$user_id = absint( $state['user_id'] ?? 0 );
		$ttl     = absint( $state['expires_at'] ?? 0 ) - time();
		if ( '' === $job_id || ! $user_id || $ttl <= 0 ) {
			return new \WP_Error( 'worldgraph_story_decomposition_expired', __( 'The story decomposition job expired before it could be checkpointed.', 'worldgraph' ) );
		}

		// A monotonically increasing revision makes a rejected write observable even
		// when the semantic state matches the preceding "running" checkpoint.
		$state['checkpoint_revision'] = absint( $state['checkpoint_revision'] ?? 0 ) + 1;
		$state['identity_sha256']     = self::identity_hash( $state );
		$expected_digest = self::value_hash( $state );
		set_transient( self::state_key( $job_id, $user_id ), $state, $ttl );
		$stored = get_transient( self::state_key( $job_id, $user_id ) );
		if ( ! is_array( $stored ) || ! hash_equals( $expected_digest, self::value_hash( $stored ) ) || $stored !== $state ) {
			return new \WP_Error( 'worldgraph_story_decomposition_checkpoint_failed', __( 'The story decomposition checkpoint could not be stored.', 'worldgraph' ) );
		}
		return true;
	}

	/** Atomically claim a per-job option lock and return its unique owner value. */
	private static function acquire_lock( string $job_id ) {
		$job_id = self::sanitize_job_id( $job_id );
		if ( '' === $job_id ) {
			return new \WP_Error( 'worldgraph_story_decomposition_not_found', __( 'The story decomposition job was not found.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		$key      = self::lock_key( $job_id );
		$existing = get_option( $key, [] );
		if ( is_array( $existing ) && ! empty( $existing['acquired_at'] ) && time() - absint( $existing['acquired_at'] ) > self::LOCK_TTL ) {
			delete_option( $key );
		}

		$owner = self::new_token();
		if ( ! add_option( $key, [ 'owner' => $owner, 'acquired_at' => time() ], '', false ) ) {
			return new \WP_Error( 'worldgraph_story_decomposition_locked', __( 'This story decomposition job is already processing a section.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		return $owner;
	}

	/** Release only the exact lock acquired by this request. */
	private static function release_lock( string $job_id, $owner ): void {
		if ( ! is_string( $owner ) || '' === $owner ) {
			return;
		}
		$key     = self::lock_key( $job_id );
		$current = get_option( $key, [] );
		if ( is_array( $current ) && isset( $current['owner'] ) && is_string( $current['owner'] ) && hash_equals( $owner, $current['owner'] ) ) {
			delete_option( $key );
		}
	}

	/** Release an owned option lock during ordinary shutdowns and catchable fatals. */
	private static function register_shutdown_lock_cleanup( string $job_id, $owner ): void {
		register_shutdown_function(
			static function () use ( $job_id, $owner ): void {
				self::release_lock( $job_id, $owner );
			}
		);
	}

	private static function cancellation_requested( string $job_id ): bool {
		$job_id = self::sanitize_job_id( $job_id );
		return '' !== $job_id && false !== get_transient( self::cancel_key( $job_id ) );
	}

	/** Bind immutable job identity fields to the WordPress authentication salt. */
	private static function identity_hash( array $state ): string {
		$identity = [
			'version'           => absint( $state['version'] ?? 0 ),
			'job_id'            => (string) ( $state['job_id'] ?? '' ),
			'user_id'           => absint( $state['user_id'] ?? 0 ),
			'created_at'        => absint( $state['created_at'] ?? 0 ),
			'expires_at'        => absint( $state['expires_at'] ?? 0 ),
			'attachment_id'     => absint( $state['attachment_id'] ?? 0 ),
			'connection_id'     => absint( $state['connection_id'] ?? 0 ),
			'connection_name'   => (string) ( $state['connection_name'] ?? '' ),
			'overwrite'         => ! empty( $state['overwrite'] ),
			'filename'          => (string) ( $state['filename'] ?? '' ),
			'format'            => (string) ( $state['format'] ?? '' ),
			'source_characters' => absint( $state['source_characters'] ?? 0 ),
			'extracted_sha256'  => (string) ( $state['extracted_sha256'] ?? '' ),
			'source_sha256'     => (string) ( $state['source_sha256'] ?? '' ),
			'run_scope'         => (string) ( $state['run_scope'] ?? '' ),
		];
		$encoded = wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash_hmac( 'sha256', false === $encoded ? '' : $encoded, wp_salt( 'auth' ) );
	}

	private static function state_key( string $job_id, int $user_id ): string {
		return 'worldgraph_story_decomp_state_' . $user_id . '_' . substr( hash( 'sha256', $job_id ), 0, 40 );
	}

	private static function lock_key( string $job_id ): string {
		return 'worldgraph_story_decomp_lock_' . substr( hash( 'sha256', get_current_user_id() . ':' . $job_id ), 0, 40 );
	}

	private static function cancel_key( string $job_id ): string {
		return 'worldgraph_story_decomp_cancel_' . get_current_user_id() . '_' . substr( hash( 'sha256', $job_id ), 0, 32 );
	}

	private static function shard_key( string $job_id, int $user_id, string $kind, string $shard_id ): string {
		return 'worldgraph_story_decomp_shard_' . $user_id . '_' . $kind . '_' . substr( hash( 'sha256', $job_id . ':' . $shard_id ), 0, 40 );
	}

	private static function sanitize_job_id( $job_id ): string {
		$job_id = (string) $job_id;
		return preg_match( self::TOKEN_PATTERN, $job_id ) ? $job_id : '';
	}

	private static function new_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}
}
