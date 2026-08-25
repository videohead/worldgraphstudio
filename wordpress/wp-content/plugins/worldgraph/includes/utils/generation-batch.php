<?php
/**
 * WordPress cron processor for queued generation-provider jobs.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Generation_Batch {
	const HOOK = 'worldgraph_process_generation_batch';
	const LOCK = 'worldgraph_gen_batch_lock';
	const LOCK_TTL = 7200;
	const CLAIM_TTL = self::LOCK_TTL;
	const IMPORT_RETRY_LIMIT = 3;
	const VIDEODRAFT_AUDIO_RETRY_LIMIT = 45;
	const PROVIDER_MESSAGE_LIMIT = 500;

	/** Token held by the current batch request. */
	private static string $lock_token = '';

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'process' ] );
	}

	/** Ensure that at least one future worker wake-up exists. */
	public static function schedule(): bool {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$scheduled = wp_schedule_single_event( time() + 5, self::HOOK );
			if ( false === $scheduled && ! wp_next_scheduled( self::HOOK ) ) {
				return false;
			}
		}

		return true;
	}

	public static function process(): void {
		$lock_token = self::acquire_lock();
		if ( '' === $lock_token ) {
			Generation_Log::add( 'debug', 'generation_batch', 'Batch already running; skipped.' );
			if ( self::has_active_jobs() && ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + 60, self::HOOK );
			}
			return;
		}

		self::$lock_token = $lock_token;
		Generation_Log::add( 'debug', 'generation_batch', 'Batch run starting.' );
		try {
			self::recover_stale_claims();
			self::finalize_media_imports();
			self::retry_media_imports();
			self::poll_submitted_jobs();
			self::submit_queued_jobs();
		} finally {
			self::release_lock( $lock_token );
			self::$lock_token = '';
		}

		if ( self::has_active_jobs() ) {
			wp_schedule_single_event( time() + 60, self::HOOK );
			Generation_Log::add( 'debug', 'generation_batch', 'Active jobs remain; rescheduled in 60s.' );
		}
	}

	/** Acquire a cross-request option lock atomically. */
	private static function acquire_lock(): string {
		global $wpdb;

		$token = wp_generate_uuid4();
		$value = [ 'token' => $token, 'expires' => time() + self::LOCK_TTL ];
		if ( add_option( self::LOCK, $value, '', false ) ) {
			return $token;
		}

		$current = get_option( self::LOCK, [] );
		if ( ! is_array( $current ) || absint( $current['expires'] ?? 0 ) < time() ) {
			$updated = $wpdb->update(
				$wpdb->options,
				[ 'option_value' => maybe_serialize( $value ) ],
				[
					'option_name'  => self::LOCK,
					'option_value' => maybe_serialize( $current ),
				],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			if ( 1 === $updated ) {
				wp_cache_delete( self::LOCK, 'options' );
				return $token;
			}
		}

		return '';
	}

	/** Extend the current worker's lease with an atomic compare-and-swap. */
	private static function refresh_lock(): bool {
		global $wpdb;

		if ( '' === self::$lock_token ) {
			return false;
		}
		$current = get_option( self::LOCK, [] );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), self::$lock_token ) ) {
			return false;
		}

		$expires = time() + self::LOCK_TTL;
		if ( absint( $current['expires'] ?? 0 ) >= $expires - 1 ) {
			return true;
		}
		$renewed = $current;
		$renewed['expires'] = $expires;
		$updated = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => maybe_serialize( $renewed ) ],
			[
				'option_name'  => self::LOCK,
				'option_value' => maybe_serialize( $current ),
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		wp_cache_delete( self::LOCK, 'options' );
		if ( 1 === $updated ) {
			return true;
		}

		$latest = get_option( self::LOCK, [] );
		return is_array( $latest )
			&& hash_equals( (string) ( $latest['token'] ?? '' ), self::$lock_token )
			&& absint( $latest['expires'] ?? 0 ) >= $expires - 1;
	}

	/** Release only the batch lock owned by this worker. */
	private static function release_lock( string $token ): void {
		global $wpdb;

		$current = get_option( self::LOCK, [] );
		if ( is_array( $current ) && hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			$wpdb->delete(
				$wpdb->options,
				[
					'option_name'  => self::LOCK,
					'option_value' => maybe_serialize( $current ),
				],
				[ '%s', '%s' ]
			);
			wp_cache_delete( self::LOCK, 'options' );
		}
	}

	/** Atomically move one job into a worker-owned state. */
	private static function claim_job( int $job_id, string $expected, string $claimed ): bool {
		if ( false === update_post_meta( $job_id, '_worldgraph_gen_status', $claimed, $expected ) ) {
			return false;
		}
		update_post_meta( $job_id, '_worldgraph_gen_claimed_at', time() );
		return true;
	}

	/** Clear worker-claim metadata after publishing a durable job state. */
	private static function clear_job_claim( int $job_id ): void {
		delete_post_meta( $job_id, '_worldgraph_gen_claimed_at' );
	}

	/** Recover crashed workers without blindly duplicating an ambiguous submit. */
	private static function recover_stale_claims(): void {
		$jobs = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => '_worldgraph_gen_status', 'value' => [ 'submitting', 'dispatching', 'polling', 'importing', 'import_cleaning' ], 'compare' => 'IN' ],
			],
		] );
		foreach ( $jobs as $job_id ) {
			if ( ! self::refresh_lock() ) {
				Generation_Log::add( 'warning', 'generation_batch', 'Batch lease was lost during stale-job recovery.' );
				return;
			}
			$claimed_at = absint( get_post_meta( $job_id, '_worldgraph_gen_claimed_at', true ) );
			if ( $claimed_at && $claimed_at + self::CLAIM_TTL >= time() ) {
				continue;
			}
			$status = (string) get_post_meta( $job_id, '_worldgraph_gen_status', true );
			if ( 'polling' === $status ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'submitted' );
				self::clear_videodraft_submission_cache( (int) $job_id );
			} elseif ( 'import_cleaning' === $status ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'import_cleanup' );
			} elseif ( 'importing' === $status ) {
				$recovered = Asset_Generator::recover_import_journal( (int) $job_id );
				if ( ! $recovered ) {
					update_post_meta( $job_id, '_worldgraph_gen_status', 'import_retry' );
					update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not finish cleaning up an interrupted media import.' );
				} elseif ( is_array( get_post_meta( $job_id, '_worldgraph_gen_result', true ) ) ) {
					update_post_meta( $job_id, '_worldgraph_gen_status', 'import_retry' );
				} else {
					update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
					update_post_meta( $job_id, '_worldgraph_gen_error', 'The completed provider result is unavailable after an interrupted media import.' );
				}
				self::clear_videodraft_submission_cache( (int) $job_id );
			} elseif ( 'dispatching' === $status ) {
				// The provider call began, but no durable response was recorded. Never
				// submit it again automatically because the remote job may exist.
				update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
				update_post_meta( $job_id, '_worldgraph_gen_error', 'The generation submission was interrupted after dispatch began and its provider outcome is unknown. Verify the provider before retrying.' );
				self::clear_videodraft_submission_cache( (int) $job_id );
			} elseif ( 'submitting' === $status && 'generate_audio' === preg_replace( '/^mcp:/', '', (string) get_post_meta( $job_id, '_worldgraph_gen_workflow', true ) ) && get_post_meta( $job_id, '_worldgraph_videodraft_idempotency_key', true ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'queued' );
			} elseif ( 'submitting' === $status && get_post_meta( $job_id, '_worldgraph_gen_job_id', true ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'submitted' );
				self::clear_videodraft_submission_cache( (int) $job_id );
			} else {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
				update_post_meta( $job_id, '_worldgraph_gen_error', 'The generation submission was interrupted and its provider outcome is unknown. Verify the provider before retrying.' );
				self::clear_videodraft_submission_cache( (int) $job_id );
			}
			self::clear_job_claim( (int) $job_id );
		}
	}

	private static function submit_queued_jobs(): void {
		$jobs = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'meta_key'       => '_worldgraph_gen_status',
			'meta_value'     => 'queued',
		] );

		foreach ( $jobs as $job_id ) {
			if ( ! self::refresh_lock() ) {
				Generation_Log::add( 'warning', 'generation_batch', 'Batch lease was lost before generation submission.' );
				return;
			}
			$job_id = (int) $job_id;
			Generation_Log::set_current_job( $job_id );
			if ( ! self::claim_job( $job_id, 'queued', 'submitting' ) ) {
				continue;
			}
			$connection_id = absint( get_post_meta( $job_id, '_worldgraph_gen_connection_id', true ) );
			$connection = Connection_Repository::get( $connection_id );
			if ( ! $connection || 'disabled' === $connection['status'] ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
				update_post_meta( $job_id, '_worldgraph_gen_error', 'The generation Template has no available Connection.' );
				self::clear_videodraft_submission_cache( $job_id );
				self::clear_job_claim( $job_id );
				Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d has no available Connection.', $job_id ), [], (string) $job_id );
				continue;
			}
			$provider_type = $connection['provider_type'];
			Connection_Adapters::load( (string) $provider_type );
			if ( ! Connection_Adapters::supports_generation( (string) $provider_type ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
				update_post_meta( $job_id, '_worldgraph_gen_error', sprintf( 'No generation adapter is registered for provider: %s.', $provider_type ) );
				self::clear_videodraft_submission_cache( $job_id );
				self::clear_job_claim( $job_id );
				Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d has no adapter for provider %s.', $job_id, $provider_type ), [], (string) $job_id );
				continue;
			}
			try {
				$client  = self::client_for_job( $job_id, $connection );
				$can_run = '' !== $client && is_callable( [ $client, 'run_template' ] );
			} catch ( \Throwable ) {
				self::fail_claimed_job( $job_id, sprintf( 'The generation client for provider %s could not be resolved safely.', $provider_type ) );
				Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d generation client resolution failed.', $job_id ), [], (string) $job_id );
				continue;
			}
			if ( ! $can_run ) {
				self::fail_claimed_job( $job_id, sprintf( 'The generation client for provider %s is unavailable.', $provider_type ) );
				continue;
			}
			$params = (array) get_post_meta( $job_id, '_worldgraph_gen_params', true );
			$template_id = absint( get_post_meta( $job_id, '_worldgraph_gen_template_id', true ) );
			if ( $template_id ) {
				$template_input = get_post_meta( $job_id, '_worldgraph_gen_template_input', true );
				$template_input = is_array( $template_input ) ? $template_input : self::template_input( $template_id );
				$params         = array_merge( $template_input, $params );
			}
			$inputs = get_post_meta( $job_id, '_worldgraph_gen_inputs', true );
			$generation_config = Connection_Adapters::generation_config( (string) $provider_type );
			if ( ! empty( $generation_config['flatten_inputs'] ) && is_array( $inputs ) && ! empty( $inputs ) ) {
				$params = array_merge( $params, $inputs );
			} elseif ( is_array( $inputs ) && ! empty( $inputs ) ) {
				$params['inputs'] = $inputs;
			}
			$workflow = (string) get_post_meta( $job_id, '_worldgraph_gen_workflow', true );
			$provider_workflow = str_starts_with( $workflow, 'mcp:' ) ? substr( $workflow, 4 ) : $workflow;
			if ( 'videodraft' === $provider_type && 'generate_audio' === $provider_workflow ) {
				$idempotency_key = (string) get_post_meta( $job_id, '_worldgraph_videodraft_idempotency_key', true );
				if ( '' === $idempotency_key ) {
					$idempotency_key = wp_generate_uuid4();
					update_post_meta( $job_id, '_worldgraph_videodraft_idempotency_key', $idempotency_key );
					if ( ! hash_equals( $idempotency_key, (string) get_post_meta( $job_id, '_worldgraph_videodraft_idempotency_key', true ) ) ) {
						update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
						update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not persist the VideoDraft audio idempotency key.' );
						self::clear_videodraft_submission_cache( $job_id );
						self::clear_job_claim( $job_id );
						Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d could not persist its VideoDraft idempotency key.', $job_id ), [], (string) $job_id );
						continue;
					}
				}
				$params['idempotency_key'] = $idempotency_key;
			}
			if ( 'videodraft' === $provider_type ) {
				$params['_worldgraph_job_id'] = $job_id;
			}
			if ( Local_ComfyUI::class === $client ) {
				$params['_worldgraph_job_id'] = $job_id;
			}

			// Cancellation may claim a prepared job up to this atomic dispatch
			// boundary. Once dispatching wins, the provider outcome is ambiguous
			// until the request returns and must be reconciled normally.
			if ( self::batch_cancel_requested( $job_id ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'cancelled', 'submitting' );
				self::clear_videodraft_submission_cache( $job_id );
				self::clear_job_claim( $job_id );
				continue;
			}
			if ( ! self::claim_job( $job_id, 'submitting', 'dispatching' ) ) {
				self::clear_job_claim( $job_id );
				continue;
			}

			Generation_Log::add( 'info', 'generation_batch', sprintf( 'Submitting job %d via %s.', $job_id, $provider_type ), [], (string) $job_id );
			if ( Local_ComfyUI::class === $client ) {
				// The local runner reads the Template post's own workflow, so
				// address it by record rather than by provider template name.
				$workflow = (string) absint( get_post_meta( $job_id, '_worldgraph_gen_template_id', true ) ) ?: $workflow;
				update_post_meta( $job_id, '_worldgraph_gen_adapter', 'local_comfyui' );
			}
			try {
				$result = $client::run_template(
					$workflow,
					(string) get_post_meta( $job_id, '_worldgraph_gen_prompt', true ),
					$params,
					$connection_id
				);
			} catch ( \Throwable ) {
				$result = new \WP_Error( 'worldgraph_generation_submit_exception', 'The registered generation client threw an exception before returning a valid result.' );
			}
			$result = self::validate_client_result( $result, 'submit' );

			if ( is_wp_error( $result ) ) {
				$attempts = absint( get_post_meta( $job_id, '_worldgraph_gen_submit_attempts', true ) ) + 1;
				$reconcile_audio = 'videodraft' === $provider_type
					&& 'generate_audio' === $provider_workflow
					&& VideoDraft_API::is_retryable_audio_error( $result )
					&& $attempts < self::VIDEODRAFT_AUDIO_RETRY_LIMIT;
				update_post_meta( $job_id, '_worldgraph_gen_submit_attempts', $attempts );
				self::store_job_error( $job_id, $result );
				update_post_meta( $job_id, '_worldgraph_gen_status', $reconcile_audio ? 'queued' : 'failed' );
				if ( ! $reconcile_audio ) {
					self::clear_videodraft_submission_cache( $job_id );
				}
				self::clear_job_claim( $job_id );
				$level = $reconcile_audio ? 'warning' : 'error';
				Generation_Log::add( $level, 'generation_batch', sprintf( 'Job %d failed to submit: %s', $job_id, $result->get_error_message() ), [], (string) $job_id );
				continue;
			}
			delete_post_meta( $job_id, '_worldgraph_gen_submit_attempts' );
			$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
			if ( in_array( $status, [ 'completed', 'failed', 'cancelled' ], true ) ) {
				self::complete_job( $job_id, $result );
				continue;
			}

			$remote_job_id = sanitize_text_field( (string) ( $result['job_id'] ?? $result['id'] ?? $result['prompt_id'] ?? '' ) );
			if ( '' === $remote_job_id ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
				update_post_meta( $job_id, '_worldgraph_gen_error', 'The generation provider did not return a job ID.' );
				self::clear_videodraft_submission_cache( $job_id );
				self::clear_job_claim( $job_id );
				Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d: provider did not return a job ID.', $job_id ), $result, (string) $job_id );
				continue;
			}
			if ( ! Connection_Adapters::supports_polling( (string) $provider_type ) ) {
				self::fail_claimed_job( $job_id, 'The generation client returned an asynchronous job, but its adapter does not declare polling support.' );
				Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d returned an asynchronous result without polling support.', $job_id ), [], (string) $job_id );
				continue;
			}

			$remote_id_persisted = self::persist_job_meta( $job_id, '_worldgraph_gen_job_id', $remote_job_id );
			$status_persisted    = $remote_id_persisted && self::persist_job_status( $job_id, 'submitted' );
			if ( ! $remote_id_persisted || ! $status_persisted ) {
				update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not persist the submitted provider job state.' );
				self::clear_job_claim( $job_id );
				Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d could not persist remote submission state.', $job_id ), [], (string) $job_id );
				continue;
			}
			self::clear_videodraft_submission_cache( $job_id );
			self::clear_job_claim( $job_id );
			Generation_Log::add( 'info', 'generation_batch', sprintf( 'Job %d submitted as remote job %s.', $job_id, $remote_job_id ), [], (string) $job_id );
		}

		Generation_Log::set_current_job( 0 );
	}

	private static function poll_submitted_jobs(): void {
		$jobs = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 10,
			'fields'         => 'ids',
			'meta_key'       => '_worldgraph_gen_status',
			'meta_value'     => 'submitted',
		] );

		foreach ( $jobs as $job_id ) {
			if ( ! self::refresh_lock() ) {
				Generation_Log::add( 'warning', 'generation_batch', 'Batch lease was lost before generation polling.' );
				return;
			}
			$job_id = (int) $job_id;
			Generation_Log::set_current_job( $job_id );
			if ( ! self::claim_job( $job_id, 'submitted', 'polling' ) ) {
				continue;
			}
			$connection_id = absint( get_post_meta( $job_id, '_worldgraph_gen_connection_id', true ) );
			$connection = Connection_Repository::get( $connection_id );
			if ( ! $connection || ! Connection_Adapters::supports_polling( (string) ( $connection['provider_type'] ?? '' ) ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
				update_post_meta( $job_id, '_worldgraph_gen_error', 'No generation adapter is registered for this Connection provider.' );
				self::clear_videodraft_submission_cache( $job_id );
				self::clear_job_claim( $job_id );
				continue;
			}
			Connection_Adapters::load( (string) $connection['provider_type'] );
			try {
				$client   = self::client_for_job( $job_id, $connection );
				$can_poll = '' !== $client && is_callable( [ $client, 'get_job_status' ] );
			} catch ( \Throwable ) {
				self::fail_claimed_job( $job_id, 'The registered generation polling client could not be resolved safely.' );
				Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d polling client resolution failed.', $job_id ), [], (string) $job_id );
				continue;
			}
			if ( ! $can_poll ) {
				self::fail_claimed_job( $job_id, 'The registered generation polling client is unavailable.' );
				continue;
			}
			try {
				if ( Connection_Adapters::poll_with_template( (string) $connection['provider_type'] ) ) {
					$result = $client::get_job_status(
						(string) get_post_meta( $job_id, '_worldgraph_gen_job_id', true ),
						$connection_id,
						(string) get_post_meta( $job_id, '_worldgraph_gen_workflow', true )
					);
				} else {
					$result = $client::get_job_status(
						(string) get_post_meta( $job_id, '_worldgraph_gen_job_id', true ),
						$connection_id
					);
				}
			} catch ( \Throwable ) {
				$result = new \WP_Error( 'worldgraph_generation_poll_exception', 'The registered generation polling client threw an exception.' );
			}
			$result = self::validate_client_result( $result, 'poll' );
			if ( is_wp_error( $result ) ) {
				$generation_config = Connection_Adapters::generation_config( (string) $connection['provider_type'] );
				$attempts          = absint( get_post_meta( $job_id, '_worldgraph_gen_poll_attempts', true ) ) + 1;
				$retry_limit       = max( 1, absint( $generation_config['poll_error_limit'] ?? 10 ) );
				$permanent_codes   = array_values( array_filter( array_map( 'sanitize_key', (array) ( $generation_config['permanent_error_codes'] ?? [] ) ) ) );
				$terminal          = $attempts >= $retry_limit || in_array( $result->get_error_code(), $permanent_codes, true );
				update_post_meta( $job_id, '_worldgraph_gen_poll_attempts', $attempts );
				self::store_job_error( $job_id, $result );
				update_post_meta( $job_id, '_worldgraph_gen_status', $terminal ? 'failed' : 'submitted' );
				self::clear_videodraft_submission_cache( $job_id );
				self::clear_job_claim( $job_id );
				Generation_Log::add(
					'error',
					'generation_batch',
					sprintf( 'Job %d status poll failed: %s', $job_id, $result->get_error_message() ),
					[],
					(string) $job_id,
					$connection_id
				);
				continue;
			}
			delete_post_meta( $job_id, '_worldgraph_gen_poll_attempts' );

			$status = sanitize_key( (string) ( $result['status'] ?? 'submitted' ) );
			if ( in_array( $status, [ 'completed', 'failed', 'cancelled' ], true ) ) {
				self::complete_job( $job_id, $result );
			} else {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'submitted' );
				self::clear_videodraft_submission_cache( $job_id );
				self::clear_job_claim( $job_id );
			}
		}

		Generation_Log::set_current_job( 0 );
	}

	private static function has_active_jobs(): bool {
		return (bool) get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => '_worldgraph_gen_status', 'value' => [ 'queued', 'submitted', 'submitting', 'dispatching', 'polling', 'importing', 'import_retry', 'import_cleanup', 'import_cleaning' ], 'compare' => 'IN' ],
			],
		] );
	}

	/** Whether this job belongs to a representative batch being cancelled. */
	private static function batch_cancel_requested( int $job_id ): bool {
		$batch_id = absint( get_post_meta( $job_id, Generation_Workflows::BATCH_ID_META, true ) );
		return $batch_id > 0 && '' !== (string) get_post_meta( $batch_id, '_worldgraph_gen_cancel_requested', true );
	}

	/**
	 * Resolve the generation adapter a queued job should run on.
	 *
	 * @param int   $job_id      Generation job post ID.
	 * @param array $connection  Resolved Connection record.
	 * @return string
	 */
	private static function client_for_job( int $job_id, array $connection ): string {
		return Connection_Adapters::generation_client(
			(string) ( $connection['provider_type'] ?? '' ),
			$connection,
			(string) get_post_meta( $job_id, '_worldgraph_gen_workflow', true ),
			(string) get_post_meta( $job_id, '_worldgraph_gen_adapter', true )
		);
	}

	/**
	 * Enforce the shared client boundary before worker code reads result fields.
	 *
	 * @param mixed  $result Provider client result.
	 * @param string $phase  Either submit or poll.
	 * @return array|\WP_Error
	 */
	private static function validate_client_result( $result, string $phase ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$phase = 'poll' === $phase ? 'poll' : 'submit';
		if ( ! is_array( $result ) ) {
			return new \WP_Error(
				'worldgraph_generation_' . $phase . '_invalid_response',
				'The registered generation client returned an invalid response.'
			);
		}
		if ( array_key_exists( 'status', $result ) && null !== $result['status'] && ! is_scalar( $result['status'] ) ) {
			return new \WP_Error(
				'worldgraph_generation_' . $phase . '_invalid_response',
				'The registered generation client returned an invalid status.'
			);
		}
		if ( 'submit' === $phase ) {
			foreach ( [ 'job_id', 'id', 'prompt_id' ] as $identifier_key ) {
				if ( array_key_exists( $identifier_key, $result ) && null !== $result[ $identifier_key ] && ! is_scalar( $result[ $identifier_key ] ) ) {
					return new \WP_Error(
						'worldgraph_generation_submit_invalid_response',
						'The registered generation client returned an invalid job identifier.'
					);
				}
			}
		}

		return $result;
	}

	/** Persist a terminal failure and always release this worker's job claim. */
	private static function fail_claimed_job( int $job_id, string $message ): void {
		update_post_meta( $job_id, '_worldgraph_gen_error', sanitize_text_field( $message ) );
		delete_post_meta( $job_id, '_worldgraph_gen_error_data' );
		if ( ! self::persist_job_status( $job_id, 'failed' ) ) {
			update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
		}
		self::clear_videodraft_submission_cache( $job_id );
		self::clear_job_claim( $job_id );
	}

	/** Finish journal cleanup without reimporting already-persisted attachments. */
	private static function finalize_media_imports(): void {
		$jobs = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'meta_key'       => '_worldgraph_gen_status',
			'meta_value'     => 'import_cleanup',
		] );
		foreach ( $jobs as $job_id ) {
			if ( ! self::refresh_lock() ) {
				Generation_Log::add( 'warning', 'generation_batch', 'Batch lease was lost before media import cleanup.' );
				return;
			}
			$job_id = (int) $job_id;
			if ( ! self::claim_job( $job_id, 'import_cleanup', 'import_cleaning' ) ) {
				continue;
			}
			if ( ! Asset_Generator::commit_import_journal( $job_id ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'import_cleanup' );
				update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not finish cleaning up temporary media-import state.' );
				self::clear_job_claim( $job_id );
				continue;
			}

			update_post_meta( $job_id, '_worldgraph_gen_status', 'completed' );
			if ( 'completed' !== (string) get_post_meta( $job_id, '_worldgraph_gen_status', true ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'import_cleanup' );
				self::clear_job_claim( $job_id );
				continue;
			}
			delete_post_meta( $job_id, '_worldgraph_gen_error' );
			delete_post_meta( $job_id, '_worldgraph_gen_error_data' );
			self::clear_job_claim( $job_id );
			Generation_Log::add( 'info', 'generation_batch', sprintf( 'Job %d completed media import cleanup.', $job_id ), [], (string) $job_id );
		}
	}

	/** Retry a completed VideoDraft result without submitting or polling again. */
	private static function retry_media_imports(): void {
		$jobs = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'meta_key'       => '_worldgraph_gen_status',
			'meta_value'     => 'import_retry',
		] );
		foreach ( $jobs as $job_id ) {
			if ( ! self::refresh_lock() ) {
				Generation_Log::add( 'warning', 'generation_batch', 'Batch lease was lost before media import retry.' );
				return;
			}
			$job_id = (int) $job_id;
			if ( ! self::claim_job( $job_id, 'import_retry', 'importing' ) ) {
				continue;
			}
			if ( ! Asset_Generator::recover_import_journal( $job_id ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'import_retry' );
				update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not clean up the interrupted media import before retrying.' );
				self::clear_job_claim( $job_id );
				continue;
			}
			$result = get_post_meta( $job_id, '_worldgraph_gen_result', true );
			if ( ! is_array( $result ) || empty( $result ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_status', 'failed' );
				update_post_meta( $job_id, '_worldgraph_gen_error', 'The completed provider result is unavailable for media import retry.' );
				self::clear_videodraft_submission_cache( $job_id );
				self::clear_job_claim( $job_id );
				continue;
			}
			self::complete_job( $job_id, $result );
		}
	}

	/** Complete a terminal provider result, importing all media before success. */
	private static function complete_job( int $job_id, array $result ): void {
		$status = sanitize_key( (string) ( $result['status'] ?? 'completed' ) );
		$terminal_message = self::terminal_result_message( $result, $status );
		$import_succeeded = false;
		$stored_result = $result;
		// Never persist raw synchronous provider bytes in post meta.
		unset( $stored_result['audio_data'], $stored_result['audio_items'] );
		if ( ! self::persist_job_meta( $job_id, '_worldgraph_gen_result', $stored_result ) ) {
			update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not persist the completed provider result.' );
			self::clear_job_claim( $job_id );
			return;
		}
		if ( 'completed' === $status && in_array( get_post_meta( $job_id, '_worldgraph_gen_type', true ), [ 'image', 'video', 'audio' ], true ) ) {
			if ( ! self::persist_job_status( $job_id, 'importing' ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not persist media import recovery state.' );
				self::clear_job_claim( $job_id );
				return;
			}
			update_post_meta( $job_id, '_worldgraph_gen_claimed_at', time() );
			self::clear_videodraft_submission_cache( $job_id );
			$asset = Asset_Generator::import_completed_job( $job_id, $result );
			if ( is_wp_error( $asset ) ) {
				$attempts = absint( get_post_meta( $job_id, '_worldgraph_gen_import_attempts', true ) ) + 1;
				update_post_meta( $job_id, '_worldgraph_gen_import_attempts', $attempts );
				$retry = 'videodraft' === (string) get_post_meta( $job_id, '_worldgraph_gen_provider_type', true )
					&& $attempts < self::IMPORT_RETRY_LIMIT
					&& self::is_retryable_import_error( $asset )
					&& ! empty( $stored_result['output_media'] );
				self::store_job_error( $job_id, $asset );
				update_post_meta( $job_id, '_worldgraph_gen_status', $retry ? 'import_retry' : 'failed' );
				self::clear_job_claim( $job_id );
				Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d asset import failed: %s', $job_id, $asset->get_error_message() ), [], (string) $job_id );
				return;
			}
			delete_post_meta( $job_id, '_worldgraph_gen_import_attempts' );
			$attachment_id  = absint( $asset['attachment_id'] ?? 0 );
			$attachment_ids = array_values( array_filter( array_map( 'absint', (array) ( $asset['attachment_ids'] ?? [ $attachment_id ] ) ) ) );
			$asset_id       = absint( $asset['asset_id'] ?? 0 );
			$attachments_persisted = self::persist_job_meta( $job_id, '_worldgraph_gen_attachment_id', $attachment_id )
				&& self::persist_job_meta( $job_id, '_worldgraph_gen_attachment_ids', $attachment_ids )
				&& self::persist_job_meta( $job_id, '_worldgraph_gen_asset_id', $asset_id );
			if ( ! $attachments_persisted ) {
				update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not persist the imported attachment state.' );
				self::clear_job_claim( $job_id );
				return;
			}
			$import_succeeded = true;
		}

		if ( $import_succeeded ) {
			update_post_meta( $job_id, '_worldgraph_gen_status', 'import_cleanup' );
			if ( 'import_cleanup' !== (string) get_post_meta( $job_id, '_worldgraph_gen_status', true ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not persist media import cleanup state.' );
				self::clear_job_claim( $job_id );
				return;
			}
			if ( ! Asset_Generator::commit_import_journal( $job_id ) ) {
				update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not finish cleaning up temporary media-import state.' );
				self::clear_job_claim( $job_id );
				return;
			}
		}

		if ( ! self::persist_job_status( $job_id, $status ) ) {
			if ( $import_succeeded ) {
				self::persist_job_status( $job_id, 'import_cleanup' );
			}
			update_post_meta( $job_id, '_worldgraph_gen_error', 'WordPress could not persist the completed media import status.' );
			self::clear_job_claim( $job_id );
			return;
		}
		self::clear_videodraft_submission_cache( $job_id );
		if ( 'failed' === $status ) {
			update_post_meta( $job_id, '_worldgraph_gen_error', $terminal_message );
			delete_post_meta( $job_id, '_worldgraph_gen_error_data' );
			self::clear_job_claim( $job_id );
			Generation_Log::add( 'error', 'generation_batch', sprintf( 'Job %d failed: %s', $job_id, $terminal_message ), [], (string) $job_id );
			return;
		}
		delete_post_meta( $job_id, '_worldgraph_gen_error' );
		delete_post_meta( $job_id, '_worldgraph_gen_error_data' );
		self::clear_job_claim( $job_id );
		if ( 'cancelled' === $status ) {
			Generation_Log::add( 'warning', 'generation_batch', sprintf( 'Job %d was cancelled: %s', $job_id, $terminal_message ), [], (string) $job_id );
			return;
		}
		Generation_Log::add( 'info', 'generation_batch', sprintf( 'Job %d reached status: %s.', $job_id, $status ), [], (string) $job_id );
	}

	/** Extract one sanitized, bounded provider terminal-state message. */
	private static function terminal_result_message( array $result, string $status ): string {
		$error = $result['error'] ?? null;
		$candidates = [
			is_array( $error ) ? ( $error['message'] ?? null ) : $error,
			$result['error_message'] ?? null,
			$result['message'] ?? null,
			$result['detail'] ?? null,
		];
		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}
			$message = substr( (string) $candidate, 0, self::PROVIDER_MESSAGE_LIMIT * 2 );
			$message = trim( sanitize_text_field( $message ) );
			if ( '' !== $message ) {
				return substr( $message, 0, self::PROVIDER_MESSAGE_LIMIT );
			}
		}

		return 'cancelled' === $status
			? 'The generation provider reported that the job was cancelled.'
			: 'The generation provider reported that the job failed.';
	}

	/** Store provider recovery metadata without ever persisting credentials. */
	private static function store_job_error( int $job_id, \WP_Error $error ): void {
		update_post_meta( $job_id, '_worldgraph_gen_error', sanitize_text_field( $error->get_error_message() ) );
		$data = $error->get_error_data();
		if ( is_array( $data ) ) {
			update_post_meta( $job_id, '_worldgraph_gen_error_data', $data );
		} else {
			delete_post_meta( $job_id, '_worldgraph_gen_error_data' );
		}
	}

	/** Persist one job-meta value and verify the recovery barrier by read-back. */
	private static function persist_job_meta( int $job_id, string $key, $value ): bool {
		update_post_meta( $job_id, $key, wp_slash( $value ) );
		$persisted = get_post_meta( $job_id, $key, true );
		return is_scalar( $value )
			? (string) $value === (string) $persisted
			: $value === $persisted;
	}

	/** Publish and verify a generation status before releasing recovery data. */
	private static function persist_job_status( int $job_id, string $status ): bool {
		return self::persist_job_meta( $job_id, '_worldgraph_gen_status', $status );
	}

	/** Clear temporary VideoDraft data only after a durable recovery transition. */
	private static function clear_videodraft_submission_cache( int $job_id ): bool {
		$status = (string) get_post_meta( $job_id, '_worldgraph_gen_status', true );
		$durable = in_array( $status, [ 'completed', 'failed', 'cancelled' ], true );
		if ( 'submitted' === $status ) {
			$durable = '' !== trim( (string) get_post_meta( $job_id, '_worldgraph_gen_job_id', true ) );
		} elseif ( in_array( $status, [ 'importing', 'import_retry', 'import_cleanup', 'import_cleaning' ], true ) ) {
			$result  = get_post_meta( $job_id, '_worldgraph_gen_result', true );
			$durable = is_array( $result ) && ! empty( $result );
		}
		if ( ! $durable ) {
			Generation_Log::add( 'warning', 'generation_batch', sprintf( 'Job %d retained VideoDraft retry state because its recovery transition was not durable.', $job_id ), [], (string) $job_id );
			return false;
		}

		delete_post_meta( $job_id, '_worldgraph_videodraft_resolved_inputs' );
		delete_post_meta( $job_id, '_worldgraph_videodraft_resolved_request' );
		return true;
	}

	/** Whether retrying a stored VideoDraft output can recover the import. */
	private static function is_retryable_import_error( \WP_Error $error ): bool {
		return in_array( $error->get_error_code(), [ 'worldgraph_gen_download_failed', 'worldgraph_asset_upload_failed', 'worldgraph_gen_cleanup_failed' ], true );
	}

	/** Read provider defaults provisioned onto a Template. */
	private static function template_input( int $template_id ): array {
		$configuration = json_decode( (string) worldgraph_get_field_value( $template_id, 'configuration_json' ), true );
		if ( ! is_array( $configuration ) ) {
			return Template_Run_Controls::defaults( $template_id );
		}
		$input = [];
		if ( is_array( $configuration['input'] ?? null ) ) {
			$input = $configuration['input'];
		} elseif ( is_array( $configuration['parameters'] ?? null ) ) {
			$input = $configuration['parameters'];
		}

		return array_merge( $input, Template_Run_Controls::defaults( $template_id ) );
	}
}
