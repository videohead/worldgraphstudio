<?php
/**
 * Local FFmpeg rough-cut assembly for completed demonstration/media batches.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assemble an editorially ordered Project rough cut without invoking a shell.
 */
class Rough_Cut_Assembler {

	/** Durable per-batch assembly state. */
	const STATE_META = '_worldgraph_rough_cut_state';

	/** Current durable state schema. */
	const STATE_VERSION = 1;

	/** Upper bound for one assembly request. */
	const MAX_SEGMENTS = 500;

	/** Upper bound for mixed Sound cues. */
	const MAX_AUDIO_CUES = 96;

	/** Upper bound for one assembled timeline, in seconds. */
	const MAX_TOTAL_DURATION = 14400.0;

	/** Upper bound for one final upload, in bytes. */
	const MAX_OUTPUT_BYTES = 2147483648;

	/** Default duration for a Shot without usable duration metadata. */
	const DEFAULT_SHOT_DURATION = 4.0;

	/** Maximum captured stdout/stderr bytes for one child process. */
	const MAX_PROCESS_OUTPUT = 131072;

	/** Maximum runtime for an FFmpeg operation. */
	const PROCESS_TIMEOUT = 1800;

	/** Generated files owned by the current request and safe to clean up. */
	private static array $known_files = [];

	/** Demonstration batch whose long-running FFmpeg process should emit a heartbeat. */
	private static int $current_batch_id = 0;

	/**
	 * Idempotently discard one batch's verified durable temp state.
	 *
	 * State meta is removed even when corrupt, but an unverified path is never
	 * touched. Call this only after cancellation is durable or the coordinator
	 * knows no live worker can continue the state.
	 */
	public static function cancel( int $batch_id ): void {
		$stored = get_post_meta( $batch_id, self::STATE_META, true );
		delete_post_meta( $batch_id, self::STATE_META );
		if ( ! is_array( $stored ) || $batch_id !== absint( $stored['batch_id'] ?? 0 ) ) {
			return;
		}
		$signature = (string) ( $stored['signature'] ?? '' );
		if ( '' === $signature || ! hash_equals( $signature, self::state_signature( $stored ) ) ) {
			return;
		}
		self::cleanup_state_directory( $batch_id, $stored );
	}

	/**
	 * Advance one durable, bounded rough-cut assembly stage.
	 *
	 * @param int $batch_id Demonstration generation batch ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function advance( int $batch_id ) {
		self::$current_batch_id = $batch_id;
		self::touch_assembly_heartbeat();

		try {
			$stored = get_post_meta( $batch_id, self::STATE_META, true );
			$state  = is_array( $stored ) ? $stored : [];
			if ( self::cancellation_requested() ) {
				self::cancel( $batch_id );
				return self::cancellation_error();
			}

			if ( empty( $state ) ) {
				return self::initialize_state( $batch_id );
			}

			$verified = self::verify_state( $batch_id, $state );
			if ( is_wp_error( $verified ) ) {
				self::cancel( $batch_id );
				return $verified;
			}
			$state    = $verified;
			$work_dir = (string) $state['work_dir'];
			$binary   = (string) $state['binary'];
			$profile  = (array) $state['profile'];

			switch ( (string) $state['stage'] ) {
				case 'normalize':
					$index = (int) $state['segment_index'];
					if ( $index >= count( $state['shots'] ) ) {
						$state['stage'] = 'concat';
						return self::save_pending_state( $batch_id, $state, __( 'Shot segments are normalized; concatenation is next.', 'worldgraph' ) );
					}

					$shot = (array) $state['shots'][ $index ];
					$mode = sanitize_key( (string) ( $shot['normalize_mode'] ?? 'card' ) );
					if ( 'video' === $mode ) {
						$media = self::attachment_file( absint( $shot['video_attachment_id'] ?? 0 ), 'video' );
						if ( empty( $media['file'] ) ) {
							$mode = ! empty( $shot['still_attachment_id'] ) ? 'still' : 'card';
						}
					} elseif ( 'still' === $mode ) {
						$media = self::attachment_file( absint( $shot['still_attachment_id'] ?? 0 ), 'image' );
						if ( empty( $media['file'] ) ) {
							$mode = 'card';
						}
					}
					$shot['normalize_mode'] = $mode;
					$shot['video_file']     = 'video' === $mode ? (string) ( $media['file'] ?? '' ) : '';
					$shot['still_file']     = 'still' === $mode ? (string) ( $media['file'] ?? '' ) : '';
					$output_name            = sprintf( 'segment-%04d.mp4', $index + 1 );
					$output                 = $work_dir . DIRECTORY_SEPARATOR . $output_name;
					$result                 = self::normalize_shot( $binary, $shot, $profile, $output, $work_dir, 'still' === $mode || 'card' === $mode );
					if ( is_wp_error( $result ) ) {
						if ( 'worldgraph_rough_cut_cancelled' === $result->get_error_code() ) {
							self::discard_state( $batch_id, $state );
							return $result;
						}
						$next_mode = 'video' === $mode && ! empty( $shot['still_attachment_id'] ) ? 'still' : ( 'card' !== $mode ? 'card' : '' );
						if ( '' !== $next_mode ) {
							$state['warnings'][] = sprintf(
								__( 'The %1$s source for “%2$s” could not be normalized; the %3$s fallback will be tried next. %4$s', 'worldgraph' ),
								$mode,
								(string) ( $shot['shot_title'] ?: $shot['scene_title'] ),
								$next_mode,
								$result->get_error_message()
							);
							$state['shots'][ $index ]['normalize_mode'] = $next_mode;
							return self::save_pending_state( $batch_id, $state, __( 'A Shot media fallback is queued for the next assembly tick.', 'worldgraph' ) );
						}

						return self::terminal_state_error( $batch_id, $state, $result );
					}

					$shot['video_file']      = '';
					$shot['still_file']      = '';
					$shot['normalized_name'] = $output_name;
					$state['normalized'][]   = $shot;
					$state['files'][]        = $output_name;
					$state['segment_index']  = $index + 1;
					if ( $state['segment_index'] >= count( $state['shots'] ) ) {
						$state['stage'] = 'concat';
					}

					return self::save_pending_state( $batch_id, $state, sprintf( __( 'Normalized %1$d of %2$d planned segments.', 'worldgraph' ), $state['segment_index'], count( $state['shots'] ) ) );

				case 'concat':
					$concat_name = 'segments.txt';
					$concat_file = $work_dir . DIRECTORY_SEPARATOR . $concat_name;
					$concat_body = '';
					foreach ( $state['normalized'] as $segment ) {
						$name = self::safe_state_filename( (string) ( $segment['normalized_name'] ?? '' ), 'segment' );
						if ( '' === $name || ! is_readable( $work_dir . DIRECTORY_SEPARATOR . $name ) ) {
							return self::terminal_state_error( $batch_id, $state, self::error( 'worldgraph_rough_cut_state_invalid', __( 'A normalized segment recorded in the durable assembly state is missing.', 'worldgraph' ), [ 'status' => 409 ] ) );
						}
						$concat_body .= "file '" . $name . "'\n";
					}
					if ( false === file_put_contents( $concat_file, $concat_body, LOCK_EX ) ) {
						return self::terminal_state_error( $batch_id, $state, self::error( 'worldgraph_rough_cut_work_file_failed', __( 'The temporary FFmpeg concat list could not be written.', 'worldgraph' ), [ 'status' => 500 ] ) );
					}
					$joined_name = 'joined.mp4';
					$joined_file = $work_dir . DIRECTORY_SEPARATOR . $joined_name;
					$result      = self::run_ffmpeg(
						$binary,
						[ '-f', 'concat', '-safe', '1', '-i', $concat_name, '-map', '0:v:0', '-an', '-c:v', 'copy', '-movflags', '+faststart', $joined_name ],
						$work_dir,
						'worldgraph_rough_cut_concat_failed',
						__( 'FFmpeg could not concatenate the normalized Shot segments.', 'worldgraph' )
					);
					if ( is_wp_error( $result ) ) {
						return self::terminal_state_error( $batch_id, $state, $result );
					}
					$state['files'] = array_merge( $state['files'], [ $concat_name, $joined_name ] );
					$state['stage'] = 'subtitle';

					return self::save_pending_state( $batch_id, $state, __( 'The normalized segments were concatenated.', 'worldgraph' ) );

				case 'subtitle':
					$srt_name = 'rough-cut.srt';
					$srt_file = $work_dir . DIRECTORY_SEPARATOR . $srt_name;
					if ( false === file_put_contents( $srt_file, self::build_srt( $state['normalized'] ), LOCK_EX ) ) {
						return self::terminal_state_error( $batch_id, $state, self::error( 'worldgraph_rough_cut_subtitle_failed', __( 'The rough-cut subtitle file could not be written.', 'worldgraph' ), [ 'status' => 500 ] ) );
					}
					$burned_name = 'subtitled.mp4';
					$result      = self::run_ffmpeg(
						$binary,
						[
							'-i', 'joined.mp4', '-map', '0:v:0', '-an', '-vf', 'subtitles=filename=' . $srt_name,
							'-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $burned_name,
						],
						$work_dir,
						'worldgraph_rough_cut_subtitle_burn_failed',
						__( 'FFmpeg could not burn the subtitles into the rough cut.', 'worldgraph' )
					);
					$state['files'][] = $srt_name;
					if ( is_wp_error( $result ) ) {
						if ( 'worldgraph_rough_cut_cancelled' === $result->get_error_code() ) {
							return self::terminal_state_error( $batch_id, $state, $result );
						}
						$state['warnings'][]       = __( 'Burned subtitles are unavailable; the SRT will be retained as a sidecar file.', 'worldgraph' ) . ' ' . $result->get_error_message();
						$state['burned_subtitles'] = false;
						$state['video_for_audio']  = 'joined.mp4';
					} else {
						$state['files'][]          = $burned_name;
						$state['burned_subtitles'] = true;
						$state['video_for_audio']  = $burned_name;
					}
					$state['stage'] = 'audio';

					return self::save_pending_state( $batch_id, $state, __( 'Subtitle processing is complete.', 'worldgraph' ) );

				case 'audio':
					$cues = self::sound_cues( (int) $state['project_id'], $batch_id, $state['normalized'], (float) $profile['fps'], $state['warnings'] );
					$state['audio_cues'] = count( $cues );
					$video_file = $work_dir . DIRECTORY_SEPARATOR . (string) $state['video_for_audio'];
					$final_name = 'rough-cut-final.mp4';
					$final_file = $work_dir . DIRECTORY_SEPARATOR . $final_name;
					$result = empty( $cues )
						? self::add_silence( $binary, $video_file, (float) $state['duration'], $final_file, $work_dir )
						: self::mix_audio( $binary, $video_file, $cues, (float) $state['duration'], $final_file, $work_dir );
					if ( is_wp_error( $result ) ) {
						if ( 'worldgraph_rough_cut_cancelled' === $result->get_error_code() ) {
							return self::terminal_state_error( $batch_id, $state, $result );
						}
						if ( ! empty( $cues ) ) {
							$state['warnings'][] = __( 'Generated Sound cues could not be mixed; a silent audio track will be used.', 'worldgraph' ) . ' ' . $result->get_error_message();
							$state['stage'] = 'silence';
							return self::save_pending_state( $batch_id, $state, __( 'The silent audio fallback is queued.', 'worldgraph' ) );
						}

						return self::terminal_state_error( $batch_id, $state, $result );
					}
					$state['files'][] = $final_name;
					$state['stage']   = 'import';

					return self::save_pending_state( $batch_id, $state, __( 'Audio processing is complete.', 'worldgraph' ) );

				case 'silence':
					$final_name = 'rough-cut-final.mp4';
					$result = self::add_silence(
						$binary,
						$work_dir . DIRECTORY_SEPARATOR . (string) $state['video_for_audio'],
						(float) $state['duration'],
						$work_dir . DIRECTORY_SEPARATOR . $final_name,
						$work_dir
					);
					if ( is_wp_error( $result ) ) {
						return self::terminal_state_error( $batch_id, $state, $result );
					}
					$state['files'][] = $final_name;
					$state['stage']   = 'import';

					return self::save_pending_state( $batch_id, $state, __( 'The silent audio fallback is complete.', 'worldgraph' ) );

				case 'import':
					if ( self::cancellation_requested() ) {
						self::discard_state( $batch_id, $state );
						return self::cancellation_error();
					}
					$video = self::existing_imported_video( $batch_id, (int) $state['project_id'] );
					if ( empty( $video ) ) {
						$video = self::import_video( $work_dir . DIRECTORY_SEPARATOR . 'rough-cut-final.mp4', (int) $state['project_id'], $batch_id );
					}
					if ( is_wp_error( $video ) ) {
						return self::terminal_state_error( $batch_id, $state, $video );
					}
					if ( self::cancellation_requested() ) {
						self::discard_state( $batch_id, $state );
						return self::cancellation_error();
					}

					$srt_attachment_id = 0;
					if ( empty( $state['burned_subtitles'] ) ) {
						$srt_attachment_id = self::existing_imported_srt( $batch_id, (int) $state['project_id'] );
						if ( ! $srt_attachment_id ) {
							$sidecar = self::import_srt( $work_dir . DIRECTORY_SEPARATOR . 'rough-cut.srt', (int) $state['project_id'], $batch_id );
							if ( is_wp_error( $sidecar ) ) {
								$state['warnings'][] = $sidecar->get_error_message();
							} else {
								$srt_attachment_id = (int) $sidecar;
							}
						}
					}
					$gallery = self::add_to_project_gallery( (int) $state['project_id'], (int) $video['attachment_id'] );
					if ( is_wp_error( $gallery ) ) {
						return self::terminal_state_error( $batch_id, $state, $gallery );
					}
					if ( $srt_attachment_id ) {
						update_post_meta( (int) $video['attachment_id'], '_worldgraph_rough_cut_srt_attachment_id', $srt_attachment_id );
					}

					$dto = [
						'batch_id'          => $batch_id,
						'batch_kind'        => (string) $state['batch_kind'],
						'project_id'        => (int) $state['project_id'],
						'attachment_id'     => (int) $video['attachment_id'],
						'srt_attachment_id' => $srt_attachment_id,
						'url'               => (string) $video['url'],
						'burned_subtitles'  => (bool) $state['burned_subtitles'],
						'sidecar_srt'       => ! $state['burned_subtitles'] && 0 !== $srt_attachment_id,
						'segments'          => count( $state['normalized'] ),
						'audio_cues'        => (int) $state['audio_cues'],
						'width'             => (int) $profile['width'],
						'height'            => (int) $profile['height'],
						'fps'               => (float) $profile['fps'],
						'duration'          => (float) $state['duration'],
						'warnings'          => array_values( array_unique( array_filter( array_map( 'strval', $state['warnings'] ) ) ) ),
					];
					self::discard_state( $batch_id, $state );

					return $dto;
			}

			return self::terminal_state_error( $batch_id, $state, self::error( 'worldgraph_rough_cut_state_invalid', __( 'The durable assembly stage is invalid.', 'worldgraph' ), [ 'status' => 409 ] ) );
		} finally {
			self::touch_assembly_heartbeat();
			self::$current_batch_id = 0;
		}
	}

	/** Initialize and persist a verified assembly state without doing heavy work. */
	private static function initialize_state( int $batch_id ) {
		$batch      = get_post( $batch_id );
		$batch_kind = (string) get_post_meta( $batch_id, '_worldgraph_gen_batch_kind', true );
		if ( ! $batch instanceof \WP_Post || 'worldgraph_gen' !== $batch->post_type || ! in_array( $batch_kind, self::supported_batch_kinds(), true ) ) {
			return self::error( 'worldgraph_rough_cut_batch_invalid', __( 'Select a demonstration-video or representative-media generation batch.', 'worldgraph' ), [ 'status' => 404 ] );
		}
		$project_id = self::project_id( (int) $batch->post_parent );
		if ( ! $project_id ) {
			return self::error( 'worldgraph_rough_cut_project_missing', __( 'The generation batch has no owning Project.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		$availability = self::availability();
		if ( self::cancellation_requested() ) {
			return self::cancellation_error();
		}
		if ( empty( $availability['available'] ) ) {
			return self::error(
				'worldgraph_rough_cut_ffmpeg_unavailable',
				__( 'FFmpeg is unavailable, so the rough cut could not be assembled.', 'worldgraph' ),
				[ 'binary' => (string) $availability['binary'], 'diagnostic' => (string) $availability['error'], 'status' => 503 ]
			);
		}

		$work_dir = self::create_work_dir( $batch_id );
		if ( is_wp_error( $work_dir ) ) {
			return $work_dir;
		}
		$warnings = [];
		$shots    = self::batch_shots( $batch_id, $project_id, $batch_kind, $warnings );
		if ( is_wp_error( $shots ) ) {
			self::cleanup_state_directory( $batch_id, [ 'work_dir' => $work_dir ] );
			return $shots;
		}
		if ( empty( $shots ) ) {
			self::cleanup_state_directory( $batch_id, [ 'work_dir' => $work_dir ] );
			return self::error( 'worldgraph_rough_cut_no_segments', __( 'No planned rough-cut segments are available.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		$duration = array_sum( array_map( static fn( array $shot ): float => (float) $shot['duration'], $shots ) );
		if ( $duration > self::MAX_TOTAL_DURATION ) {
			self::cleanup_state_directory( $batch_id, [ 'work_dir' => $work_dir ] );
			return self::error( 'worldgraph_rough_cut_duration_exceeded', __( 'The planned rough cut exceeds the bounded timeline duration.', 'worldgraph' ), [ 'duration' => $duration, 'limit' => self::MAX_TOTAL_DURATION, 'status' => 400 ] );
		}
		self::assign_timeline_and_dialogue( $shots );
		foreach ( $shots as &$shot ) {
			$shot['normalize_mode'] = ! empty( $shot['video_attachment_id'] ) ? 'video' : ( ! empty( $shot['still_attachment_id'] ) ? 'still' : 'card' );
			$shot['video_file']     = '';
			$shot['still_file']     = '';
		}
		unset( $shot );
		if ( self::cancellation_requested() ) {
			self::cleanup_state_directory( $batch_id, [ 'work_dir' => $work_dir ] );
			return self::cancellation_error();
		}

		$state = [
			'version'            => self::STATE_VERSION,
			'batch_id'           => $batch_id,
			'batch_kind'         => $batch_kind,
			'project_id'         => $project_id,
			'binary'             => (string) $availability['binary'],
			'profile'            => self::project_profile( $project_id ),
			'work_dir'           => $work_dir,
			'stage'              => 'normalize',
			'segment_index'      => 0,
			'shots'              => array_values( $shots ),
			'normalized'         => [],
			'files'              => [],
			'warnings'           => array_values( $warnings ),
			'duration'           => $duration,
			'burned_subtitles'   => false,
			'video_for_audio'    => '',
			'audio_cues'         => 0,
			'created_at'         => time(),
		];

		return self::save_pending_state( $batch_id, $state, __( 'Durable rough-cut assembly state was initialized.', 'worldgraph' ) );
	}

	/** Validate durable state, including its signature and batch-specific temp root. */
	private static function verify_state( int $batch_id, array $state ) {
		$signature = (string) ( $state['signature'] ?? '' );
		if ( '' === $signature || ! hash_equals( $signature, self::state_signature( $state ) ) ) {
			return self::error( 'worldgraph_rough_cut_state_invalid', __( 'The durable rough-cut state failed integrity verification.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		$stage = sanitize_key( (string) ( $state['stage'] ?? '' ) );
		$batch = get_post( $batch_id );
		$live_kind = (string) get_post_meta( $batch_id, '_worldgraph_gen_batch_kind', true );
		$live_project_id = $batch instanceof \WP_Post && 'worldgraph_gen' === $batch->post_type ? self::project_id( (int) $batch->post_parent ) : 0;
		if ( self::STATE_VERSION !== absint( $state['version'] ?? 0 )
			|| $batch_id !== absint( $state['batch_id'] ?? 0 )
			|| ! $live_project_id || $live_project_id !== absint( $state['project_id'] ?? 0 )
			|| $live_kind !== (string) ( $state['batch_kind'] ?? '' ) || ! in_array( $live_kind, self::supported_batch_kinds(), true )
			|| ! self::safe_batch_work_dir( (string) ( $state['work_dir'] ?? '' ), $batch_id )
			|| ! in_array( $stage, [ 'normalize', 'concat', 'subtitle', 'audio', 'silence', 'import' ], true )
			|| (string) ( $state['binary'] ?? '' ) !== self::configured_binary()
			|| empty( $state['profile'] ) || ! is_array( $state['profile'] )
			|| empty( $state['shots'] ) || ! is_array( $state['shots'] ) || count( $state['shots'] ) > self::MAX_SEGMENTS
			|| ! isset( $state['normalized'], $state['files'], $state['warnings'] )
			|| ! is_array( $state['normalized'] ) || ! is_array( $state['files'] ) || ! is_array( $state['warnings'] ) ) {
			return self::error( 'worldgraph_rough_cut_state_invalid', __( 'The durable rough-cut state is invalid or no longer matches this deployment.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		$index = (int) ( $state['segment_index'] ?? -1 );
		if ( $index < 0 || $index > count( $state['shots'] ) || count( $state['normalized'] ) !== $index ) {
			return self::error( 'worldgraph_rough_cut_state_invalid', __( 'The durable rough-cut segment cursor is invalid.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		foreach ( $state['files'] as $name ) {
			if ( '' === self::safe_state_filename( (string) $name ) ) {
				return self::error( 'worldgraph_rough_cut_state_invalid', __( 'The durable rough-cut state contains an unsafe file path.', 'worldgraph' ), [ 'status' => 409 ] );
			}
		}

		return $state;
	}

	/** Sign all state fields except the signature itself with the WordPress secret. */
	private static function state_signature( array $state ): string {
		unset( $state['signature'] );
		$secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' );
		if ( '' === $secret ) {
			return '';
		}

		return hash_hmac( 'sha256', serialize( $state ), $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- internal signed state, never unserialized here.
	}

	/** Persist signed state, verify the round trip, and return its progress DTO. */
	private static function save_pending_state( int $batch_id, array $state, string $message ) {
		$state['files']     = array_values( array_unique( array_filter( array_map( 'strval', (array) $state['files'] ) ) ) );
		$state['warnings']  = array_slice( array_values( array_unique( array_filter( array_map( 'strval', (array) $state['warnings'] ) ) ) ), 0, 500 );
		$state['signature'] = self::state_signature( $state );
		if ( '' === $state['signature'] ) {
			self::discard_state( $batch_id, $state );
			return self::error( 'worldgraph_rough_cut_state_store_failed', __( 'WordPress could not sign the durable rough-cut assembly state.', 'worldgraph' ), [ 'status' => 500 ] );
		}
		update_post_meta( $batch_id, self::STATE_META, wp_slash( $state ) );
		$stored = get_post_meta( $batch_id, self::STATE_META, true );
		if ( ! is_array( $stored ) || (string) ( $stored['signature'] ?? '' ) !== $state['signature'] ) {
			self::discard_state( $batch_id, $state );
			return self::error( 'worldgraph_rough_cut_state_store_failed', __( 'WordPress could not persist the durable rough-cut assembly state.', 'worldgraph' ), [ 'status' => 500 ] );
		}

		return self::pending_result( $batch_id, $state, $message );
	}

	/** Build the stable coordinator-facing pending result. */
	private static function pending_result( int $batch_id, array $state, string $message ): array {
		$segments = count( (array) ( $state['shots'] ?? [] ) );
		$stage    = sanitize_key( (string) ( $state['stage'] ?? 'normalize' ) );
		$completed = min( $segments, max( 0, (int) ( $state['segment_index'] ?? 0 ) ) );
		if ( 'subtitle' === $stage ) {
			$completed = $segments + 1;
		} elseif ( in_array( $stage, [ 'audio', 'silence' ], true ) ) {
			$completed = $segments + 2;
		} elseif ( 'import' === $stage ) {
			$completed = $segments + 3;
		}
		$total = $segments + 4;
		$progress = $total ? min( 99, (int) floor( 100 * $completed / $total ) ) : 0;

		return [
			'status'          => 'pending',
			'batch_id'        => $batch_id,
			'stage'           => $stage,
			'completed_steps' => $completed,
			'total_steps'     => $total,
			'progress'        => $progress,
			'progress_percent' => $progress,
			'message'         => self::subtitle_text( $message ),
		];
	}

	/** Return a state-safe generated filename, or an empty string. */
	private static function safe_state_filename( string $name, string $kind = '' ): string {
		if ( $name !== basename( $name ) || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			return '';
		}
		if ( 'segment' === $kind ) {
			return preg_match( '/^segment-\d{4}\.mp4$/', $name ) ? $name : '';
		}
		$fixed = [ 'segments.txt', 'joined.mp4', 'rough-cut.srt', 'subtitled.mp4', 'rough-cut-final.mp4', 'rough-cut-sideload.mp4' ];

		return in_array( $name, $fixed, true ) || preg_match( '/^segment-\d{4}\.mp4$/', $name ) ? $name : '';
	}

	/** Verify a persisted work directory belongs only to this batch under temp. */
	private static function safe_batch_work_dir( string $work_dir, int $batch_id ): bool {
		return self::safe_work_dir( $work_dir )
			&& 0 === strpos( basename( (string) realpath( $work_dir ) ), 'worldgraph-rough-cut-' . $batch_id . '-' );
	}

	/** Clean terminal durable state and return its original error. */
	private static function terminal_state_error( int $batch_id, array $state, WP_Error $error ): WP_Error {
		self::discard_state( $batch_id, $state );

		return $error;
	}

	/** Delete durable state and only predictable files below its verified root. */
	private static function discard_state( int $batch_id, array $state ): void {
		delete_post_meta( $batch_id, self::STATE_META );
		self::cleanup_state_directory( $batch_id, $state );
	}

	/** Remove safe state-owned files and the verified empty batch directory. */
	private static function cleanup_state_directory( int $batch_id, array $state ): void {
		$work_dir = (string) ( $state['work_dir'] ?? '' );
		if ( ! self::safe_batch_work_dir( $work_dir, $batch_id ) ) {
			return;
		}
		$contents = scandir( $work_dir );
		foreach ( is_array( $contents ) ? $contents : [] as $name ) {
			if ( '' === self::safe_state_filename( (string) $name ) ) {
				continue;
			}
			$file = $work_dir . DIRECTORY_SEPARATOR . $name;
			if ( self::path_is_within( $file, $work_dir ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$remaining = scandir( $work_dir );
		if ( [ '.', '..' ] === $remaining ) {
			rmdir( $work_dir );
		}
	}

	/**
	 * Report whether a usable FFmpeg process can be started.
	 *
	 * @return array{available: bool, binary: string, error: string}
	 */
	public static function availability(): array {
		$binary = self::configured_binary();
		if ( '' === $binary ) {
			return [
				'available' => false,
				'binary'    => '',
				'error'     => 'The configured FFmpeg binary is empty or unsafe.',
			];
		}
		if ( ! function_exists( 'proc_open' ) ) {
			return [
				'available' => false,
				'binary'    => $binary,
				'error'     => 'PHP proc_open is unavailable or disabled.',
			];
		}

		$result = self::run_process( [ $binary, '-hide_banner', '-version' ], '', 15 );
		if ( 0 !== $result['exit_code'] ) {
			$error = trim( (string) ( $result['stderr'] ?: $result['stdout'] ) );
			return [
				'available' => false,
				'binary'    => $binary,
				'error'     => '' !== $error ? $error : 'FFmpeg could not be started.',
			];
		}

		return [
			'available' => true,
			'binary'    => $binary,
			'error'     => '',
		];
	}

	/**
	 * Assemble completed Shot outputs, primarily for a demonstration batch.
	 *
	 * @param int $batch_id Demonstration or representative generation batch ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function assemble( int $batch_id ) {
		$availability = self::availability();
		if ( empty( $availability['available'] ) ) {
			return self::error(
				'worldgraph_rough_cut_ffmpeg_unavailable',
				__( 'FFmpeg is unavailable, so the rough cut could not be assembled.', 'worldgraph' ),
				[ 'binary' => (string) $availability['binary'], 'diagnostic' => (string) $availability['error'], 'status' => 503 ]
			);
		}

		$batch      = get_post( $batch_id );
		$batch_kind = (string) get_post_meta( $batch_id, '_worldgraph_gen_batch_kind', true );
		if ( ! $batch instanceof \WP_Post || 'worldgraph_gen' !== $batch->post_type || ! in_array( $batch_kind, self::supported_batch_kinds(), true ) ) {
			return self::error( 'worldgraph_rough_cut_batch_invalid', __( 'Select a demonstration-video or representative-media generation batch.', 'worldgraph' ), [ 'status' => 404 ] );
		}

		$project_id = self::project_id( (int) $batch->post_parent );
		if ( ! $project_id ) {
			return self::error( 'worldgraph_rough_cut_project_missing', __( 'The generation batch has no owning Project.', 'worldgraph' ), [ 'status' => 409 ] );
		}

		$profile = self::project_profile( $project_id );
		$work_dir = self::create_work_dir( $batch_id );
		if ( is_wp_error( $work_dir ) ) {
			return $work_dir;
		}

		self::$known_files = [];
		self::$current_batch_id = $batch_id;
		self::touch_assembly_heartbeat();
		$warnings          = [];
		$binary            = (string) $availability['binary'];

		try {
			if ( self::cancellation_requested() ) {
				return self::cancellation_error();
			}
			$shots = self::batch_shots( $batch_id, $project_id, $batch_kind, $warnings );
			if ( is_wp_error( $shots ) ) {
				return $shots;
			}
			$planned_duration = array_sum( array_map( static function ( array $shot ): float {
				return (float) $shot['duration'];
			}, $shots ) );
			if ( $planned_duration > self::MAX_TOTAL_DURATION ) {
				return self::error(
					'worldgraph_rough_cut_duration_exceeded',
					__( 'The planned rough cut exceeds the bounded timeline duration.', 'worldgraph' ),
					[ 'duration' => $planned_duration, 'limit' => self::MAX_TOTAL_DURATION, 'status' => 400 ]
				);
			}

			$normalized = [];
			foreach ( $shots as $index => $shot ) {
				if ( self::cancellation_requested() ) {
					return self::cancellation_error();
				}
				$output = $work_dir . DIRECTORY_SEPARATOR . sprintf( 'segment-%04d.mp4', $index + 1 );
				self::remember_file( $output, $work_dir );
				$result = self::normalize_shot( $binary, $shot, $profile, $output, $work_dir, false );

				if ( is_wp_error( $result ) && 'worldgraph_rough_cut_cancelled' === $result->get_error_code() ) {
					return $result;
				}
				if ( is_wp_error( $result ) && ! empty( $shot['still_file'] ) ) {
					$warnings[] = sprintf(
						/* translators: 1: Shot title, 2: FFmpeg diagnostic. */
						__( 'The video for “%1$s” could not be normalized; its representative still was used instead. %2$s', 'worldgraph' ),
						(string) $shot['shot_title'],
						$result->get_error_message()
					);
					$result = self::normalize_shot( $binary, $shot, $profile, $output, $work_dir, true );
				}
				if ( is_wp_error( $result ) && 'worldgraph_rough_cut_cancelled' === $result->get_error_code() ) {
					return $result;
				}
				if ( is_wp_error( $result ) ) {
					$warnings[] = sprintf(
						/* translators: 1: Shot title, 2: FFmpeg diagnostic. */
						__( 'Shot “%1$s” could not use its completed media; a black title/subtitle card was used instead. %2$s', 'worldgraph' ),
						(string) $shot['shot_title'],
						$result->get_error_message()
					);
					$card_shot               = $shot;
					$card_shot['video_file'] = '';
					$card_shot['still_file'] = '';
					$result = self::normalize_shot( $binary, $card_shot, $profile, $output, $work_dir, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}

				$shot['normalized_file'] = $output;
				$normalized[]            = $shot;
			}

			if ( empty( $normalized ) ) {
				return self::error(
					'worldgraph_rough_cut_no_segments',
					__( 'No completed Shot video or representative still could be assembled.', 'worldgraph' ),
					[ 'warnings' => $warnings, 'status' => 409 ]
				);
			}
			if ( self::cancellation_requested() ) {
				return self::cancellation_error();
			}

			self::assign_timeline_and_dialogue( $normalized );
			$concat_file = $work_dir . DIRECTORY_SEPARATOR . 'segments.txt';
			$concat_body = '';
			foreach ( $normalized as $shot ) {
				$concat_body .= "file '" . basename( (string) $shot['normalized_file'] ) . "'\n";
			}
			if ( false === file_put_contents( $concat_file, $concat_body, LOCK_EX ) ) {
				return self::error( 'worldgraph_rough_cut_work_file_failed', __( 'The temporary FFmpeg concat list could not be written.', 'worldgraph' ), [ 'status' => 500 ] );
			}
			self::remember_file( $concat_file, $work_dir );

			$joined_file = $work_dir . DIRECTORY_SEPARATOR . 'joined.mp4';
			self::remember_file( $joined_file, $work_dir );
			$join = self::run_ffmpeg(
				$binary,
				[
					'-f', 'concat', '-safe', '1', '-i', basename( $concat_file ),
					'-map', '0:v:0', '-an', '-c:v', 'copy', '-movflags', '+faststart', basename( $joined_file ),
				],
				$work_dir,
				'worldgraph_rough_cut_concat_failed',
				__( 'FFmpeg could not concatenate the normalized Shot segments.', 'worldgraph' )
			);
			if ( is_wp_error( $join ) ) {
				return $join;
			}
			if ( self::cancellation_requested() ) {
				return self::cancellation_error();
			}

			$srt_file = $work_dir . DIRECTORY_SEPARATOR . 'rough-cut.srt';
			$srt      = self::build_srt( $normalized );
			if ( false === file_put_contents( $srt_file, $srt, LOCK_EX ) ) {
				return self::error( 'worldgraph_rough_cut_subtitle_failed', __( 'The rough-cut subtitle file could not be written.', 'worldgraph' ), [ 'status' => 500 ] );
			}
			self::remember_file( $srt_file, $work_dir );

			$burned_subtitles = false;
			$video_for_audio   = $joined_file;
			$burned_file       = $work_dir . DIRECTORY_SEPARATOR . 'subtitled.mp4';
			self::remember_file( $burned_file, $work_dir );
			$burn = self::run_ffmpeg(
				$binary,
				[
					'-i', basename( $joined_file ), '-map', '0:v:0', '-an',
					'-vf', 'subtitles=filename=' . basename( $srt_file ),
					'-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p',
					'-movflags', '+faststart', basename( $burned_file ),
				],
				$work_dir,
				'worldgraph_rough_cut_subtitle_burn_failed',
				__( 'FFmpeg could not burn the subtitles into the rough cut.', 'worldgraph' )
			);
			if ( is_wp_error( $burn ) && 'worldgraph_rough_cut_cancelled' === $burn->get_error_code() ) {
				return $burn;
			}
			if ( is_wp_error( $burn ) ) {
				$warnings[] = __( 'Burned subtitles are unavailable; the SRT will be retained as a sidecar file.', 'worldgraph' ) . ' ' . $burn->get_error_message();
			} else {
				$burned_subtitles = true;
				$video_for_audio   = $burned_file;
			}

			$total_duration = array_sum( array_map( static function ( array $shot ): float {
				return (float) $shot['duration'];
			}, $normalized ) );
			$audio_cues = self::sound_cues( $project_id, $batch_id, $normalized, (float) $profile['fps'], $warnings );
			$final_file = $work_dir . DIRECTORY_SEPARATOR . 'rough-cut-final.mp4';
			self::remember_file( $final_file, $work_dir );
			$mixed = self::mix_audio( $binary, $video_for_audio, $audio_cues, $total_duration, $final_file, $work_dir );
			if ( is_wp_error( $mixed ) && 'worldgraph_rough_cut_cancelled' === $mixed->get_error_code() ) {
				return $mixed;
			}
			if ( is_wp_error( $mixed ) && ! empty( $audio_cues ) ) {
				$warnings[] = __( 'Generated Sound cues could not be mixed; a silent audio track was used.', 'worldgraph' ) . ' ' . $mixed->get_error_message();
				$mixed = self::add_silence( $binary, $video_for_audio, $total_duration, $final_file, $work_dir );
			}
			if ( is_wp_error( $mixed ) ) {
				return $mixed;
			}
			if ( self::cancellation_requested() ) {
				return self::cancellation_error();
			}

			$video = self::import_video( $final_file, $project_id, $batch_id );
			if ( is_wp_error( $video ) ) {
				return $video;
			}

			$srt_attachment_id = 0;
			if ( ! $burned_subtitles ) {
				$sidecar = self::import_srt( $srt_file, $project_id, $batch_id );
				if ( is_wp_error( $sidecar ) ) {
					$warnings[] = $sidecar->get_error_message();
				} else {
					$srt_attachment_id = (int) $sidecar;
				}
			}

			$gallery = self::add_to_project_gallery( $project_id, (int) $video['attachment_id'] );
			if ( is_wp_error( $gallery ) ) {
				return $gallery;
			}
			if ( $srt_attachment_id ) {
				update_post_meta( (int) $video['attachment_id'], '_worldgraph_rough_cut_srt_attachment_id', $srt_attachment_id );
			}

			return [
				'batch_id'           => $batch_id,
				'batch_kind'         => $batch_kind,
				'project_id'         => $project_id,
				'attachment_id'      => (int) $video['attachment_id'],
				'srt_attachment_id'  => $srt_attachment_id,
				'url'                => (string) $video['url'],
				'burned_subtitles'   => $burned_subtitles,
				'sidecar_srt'        => ! $burned_subtitles && 0 !== $srt_attachment_id,
				'segments'           => count( $normalized ),
				'audio_cues'         => count( $audio_cues ),
				'width'              => (int) $profile['width'],
				'height'             => (int) $profile['height'],
				'fps'                => (float) $profile['fps'],
				'duration'           => $total_duration,
				'warnings'           => array_values( array_unique( array_filter( array_map( 'strval', $warnings ) ) ) ),
			];
		} finally {
			self::cleanup( $work_dir );
			self::touch_assembly_heartbeat();
			self::$current_batch_id = 0;
		}
	}

	/**
	 * Parse seconds, ISO-8601 durations, and HH:MM:SS:frames timecodes.
	 */
	public static function parse_timecode( string $value, float $fps = 24.0 ): float {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0.0;
		}
		if ( preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
			return min( 86400.0, max( 0.0, (float) $value ) );
		}
		if ( preg_match( '/^PT(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?$/i', $value, $matches ) ) {
			$hours   = (float) ( $matches[1] ?? 0 );
			$minutes = (float) ( $matches[2] ?? 0 );
			$seconds = (float) ( $matches[3] ?? 0 );
			return min( 86400.0, max( 0.0, 3600 * $hours + 60 * $minutes + $seconds ) );
		}

		$parts = explode( ':', $value );
		if ( count( $parts ) < 2 || count( $parts ) > 4 ) {
			return 0.0;
		}
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $part ) ) {
				return 0.0;
			}
		}
		$parts = array_map( 'floatval', $parts );
		if ( 4 === count( $parts ) ) {
			return min( 86400.0, max( 0.0, 3600 * $parts[0] + 60 * $parts[1] + $parts[2] + $parts[3] / max( 0.001, $fps ) ) );
		}
		if ( 3 === count( $parts ) ) {
			return min( 86400.0, max( 0.0, 3600 * $parts[0] + 60 * $parts[1] + $parts[2] ) );
		}

		return min( 86400.0, max( 0.0, 60 * $parts[0] + $parts[1] ) );
	}

	/** Return a conservative mix level for a soundtrack role. */
	public static function role_volume( string $role ): float {
		$role = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', $role ), '-' ) );
		$levels = [
			'narration'    => 1.0,
			'voiceover'    => 1.0,
			'voice-over'   => 1.0,
			'adr'          => 1.0,
			'sound-effect' => 0.85,
			'foley'        => 0.8,
			'ambience'     => 0.35,
			'music'        => 0.28,
		];

		return (float) ( $levels[ $role ] ?? 0.65 );
	}

	/**
	 * Build a bounded SubRip document from timeline-enriched Shot segments.
	 *
	 * @param array<int,array<string,mixed>> $segments Shot timeline rows.
	 */
	public static function build_srt( array $segments ): string {
		$entries = [];
		$cursor  = 0.0;
		foreach ( array_slice( $segments, 0, self::MAX_SEGMENTS ) as $segment ) {
			$duration = self::bounded_duration( $segment['duration'] ?? self::DEFAULT_SHOT_DURATION );
			$start    = isset( $segment['start'] ) ? max( 0.0, (float) $segment['start'] ) : $cursor;
			$end      = isset( $segment['end'] ) ? max( $start + 0.001, (float) $segment['end'] ) : $start + $duration;
			$lines    = [];
			if ( ! empty( $segment['scene_title'] ) ) {
				$lines[] = self::subtitle_text( __( 'Scene', 'worldgraph' ) . ': ' . (string) $segment['scene_title'] );
			}
			if ( ! empty( $segment['shot_title'] ) ) {
				$lines[] = self::subtitle_text( __( 'Shot', 'worldgraph' ) . ': ' . (string) $segment['shot_title'] );
			}
			foreach ( array_slice( (array) ( $segment['dialogue_lines'] ?? [] ), 0, 12 ) as $line ) {
				$line = self::subtitle_text( (string) $line );
				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}
			$lines = array_values( array_filter( array_unique( $lines ) ) );
			if ( ! empty( $lines ) ) {
				$entries[] = count( $entries ) + 1 . "\r\n"
					. self::srt_time( $start ) . ' --> ' . self::srt_time( $end ) . "\r\n"
					. implode( "\r\n", $lines ) . "\r\n\r\n";
			}
			$cursor = $end;
		}

		return implode( '', $entries );
	}

	/**
	 * Resolve frozen demonstration timeline cards against completed task outputs.
	 *
	 * This helper deliberately accepts already validated local output records so
	 * the plan-to-timeline projection can be tested without WordPress storage.
	 * Only Scene and Project image tasks are eligible; real Shot media remains
	 * the primary assembly path.
	 *
	 * @param array<string,mixed>            $plan            Frozen assembly plan or a wrapper containing it.
	 * @param array<int,array<string,mixed>> $completed_tasks Validated completed image-task outputs.
	 * @return array<int,array<string,mixed>>
	 */
	public static function fallback_segments_from_plan( array $plan, array $completed_tasks ): array {
		$assembly = isset( $plan['assembly'] ) && is_array( $plan['assembly'] ) ? $plan['assembly'] : $plan;
		$timeline = isset( $assembly['timeline'] ) && is_array( $assembly['timeline'] ) ? $assembly['timeline'] : [];
		if ( empty( $timeline ) || empty( $completed_tasks ) ) {
			return [];
		}

		$completed_by_key = [];
		foreach ( array_slice( $completed_tasks, 0, self::MAX_SEGMENTS * 2 ) as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$task_key    = trim( (string) ( $task['task_key'] ?? '' ) );
			$source_type = sanitize_key( (string) ( $task['source_type'] ?? '' ) );
			if ( '' === $task_key || ! in_array( $source_type, [ 'worldgraph_scene', 'worldgraph_project' ], true ) || empty( $task['still_file'] ) ) {
				continue;
			}
			$completed_by_key[ $task_key ] = $task;
		}

		$segments = [];
		foreach ( array_slice( array_values( $timeline ), 0, self::MAX_SEGMENTS ) as $scene_index => $scene ) {
			if ( ! is_array( $scene ) ) {
				continue;
			}
			$scene_id    = absint( $scene['scene_id'] ?? 0 );
			$scene_title = self::subtitle_text( (string) ( $scene['scene_title'] ?? '' ) );
			$scene_order = isset( $scene['scene_order'] ) ? (int) $scene['scene_order'] : $scene_index;
			$scene_rows  = isset( $scene['segments'] ) && is_array( $scene['segments'] ) ? $scene['segments'] : [];
			foreach ( array_slice( array_values( $scene_rows ), 0, self::MAX_SEGMENTS ) as $shot_index => $segment ) {
				if ( ! is_array( $segment ) || count( $segments ) >= self::MAX_SEGMENTS ) {
					continue;
				}
				$task_key = trim( (string) ( $segment['fallback_still_task_key'] ?? '' ) );
				$task     = $completed_by_key[ $task_key ] ?? null;
				if ( ! is_array( $task ) ) {
					continue;
				}

				$source_id   = absint( $task['source_id'] ?? 0 );
				$source_type = sanitize_key( (string) ( $task['source_type'] ?? '' ) );
				$segment_scene_id = absint( $segment['scene_id'] ?? $scene_id );
				if ( ( 'worldgraph_scene' === $source_type && ( ! $segment_scene_id || $source_id !== $segment_scene_id ) )
					|| ( 'worldgraph_project' === $source_type && 0 !== $segment_scene_id ) ) {
					continue;
				}

				$source_title = self::subtitle_text( (string) ( $task['source_title'] ?? '' ) );
				$caption      = self::subtitle_text( (string) ( $segment['subtitle_text'] ?? '' ) );
				$segments[]   = [
					'shot_id'             => 0,
					'shot_title'          => $source_title,
					'shot_menu_order'     => isset( $segment['shot_order'] ) ? (int) $segment['shot_order'] : $shot_index,
					'shot_number'         => 0.0,
					'scene_id'            => $segment_scene_id,
					'scene_title'         => '' !== $scene_title ? $scene_title : $source_title,
					'scene_menu_order'    => $scene_order,
					'scene_sequence_order'=> $scene_order + 1,
					'scene_number'        => (float) $scene_order,
					'duration'            => self::bounded_duration( $segment['duration'] ?? self::DEFAULT_SHOT_DURATION ),
					'video_file'          => '',
					'still_file'          => (string) $task['still_file'],
					'still_attachment_id' => absint( $task['attachment_id'] ?? 0 ),
					'dialogue_lines'      => '' !== $caption ? [ $caption ] : [],
					'task_key'            => $task_key,
				];
			}
		}

		return $segments;
	}

	/**
	 * Project a frozen demonstration plan into its complete editorial timeline.
	 *
	 * Completed outputs are optional. A planned row without readable media is
	 * retained as a black card whose frozen labels and subtitle are burned later.
	 * Only output/task pairs whose frozen source provenance matches the timeline
	 * row are accepted.
	 *
	 * @param array<string,mixed>            $plan    Frozen assembly plan or wrapper.
	 * @param array<int,array<string,mixed>> $tasks   Flat frozen batch task list.
	 * @param array<int,array<string,mixed>> $outputs Validated completed task outputs.
	 * @return array<int,array<string,mixed>>
	 */
	public static function demonstration_segments_from_plan( array $plan, array $tasks, array $outputs ): array {
		$assembly = isset( $plan['assembly'] ) && is_array( $plan['assembly'] ) ? $plan['assembly'] : $plan;
		$timeline = isset( $assembly['timeline'] ) && is_array( $assembly['timeline'] ) ? $assembly['timeline'] : [];
		if ( empty( $timeline ) ) {
			return [];
		}

		$tasks_by_key = [];
		foreach ( array_slice( array_values( $tasks ), 0, self::MAX_SEGMENTS * 8 ) as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$key = trim( (string) ( $task['task_key'] ?? '' ) );
			if ( '' !== $key ) {
				$tasks_by_key[ $key ] = $task;
			}
		}

		$outputs_by_key = [];
		foreach ( array_slice( array_values( $outputs ), 0, self::MAX_SEGMENTS * 4 ) as $output ) {
			if ( ! is_array( $output ) || empty( $output['file'] ) ) {
				continue;
			}
			$key  = trim( (string) ( $output['task_key'] ?? '' ) );
			$task = $tasks_by_key[ $key ] ?? null;
			if ( '' === $key || ! is_array( $task ) ) {
				continue;
			}
			if ( absint( $output['source_id'] ?? 0 ) !== absint( $task['source_id'] ?? 0 )
				|| sanitize_key( (string) ( $output['source_type'] ?? '' ) ) !== sanitize_key( (string) ( $task['source_type'] ?? '' ) )
				|| sanitize_key( (string) ( $output['type'] ?? '' ) ) !== sanitize_key( (string) ( $task['type'] ?? '' ) ) ) {
				continue;
			}
			$outputs_by_key[ $key ] = $output;
		}

		$segments = [];
		foreach ( array_slice( array_values( $timeline ), 0, self::MAX_SEGMENTS ) as $scene_index => $scene ) {
			if ( ! is_array( $scene ) ) {
				continue;
			}
			$scene_id       = absint( $scene['scene_id'] ?? 0 );
			$scene_order    = isset( $scene['scene_order'] ) ? (int) $scene['scene_order'] : $scene_index;
			$scene_title    = self::subtitle_text( (string) ( $scene['scene_title'] ?? '' ) );
			$scene_subtitle = self::subtitle_text( (string) ( $scene['subtitle_text'] ?? '' ) );
			$scene_audio    = array_values( array_filter( array_map( 'strval', (array) ( $scene['sound_task_keys'] ?? [] ) ) ) );
			$rows           = isset( $scene['segments'] ) && is_array( $scene['segments'] ) ? $scene['segments'] : [];

			foreach ( array_slice( array_values( $rows ), 0, self::MAX_SEGMENTS ) as $shot_index => $row ) {
				if ( ! is_array( $row ) || count( $segments ) >= self::MAX_SEGMENTS ) {
					continue;
				}
				$row_scene_id = absint( $row['scene_id'] ?? $scene_id );
				$shot_id      = absint( $row['shot_id'] ?? 0 );
				$video_key    = trim( (string) ( $row['video_task_key'] ?? '' ) );
				$still_key    = trim( (string) ( $row['fallback_still_task_key'] ?? '' ) );
				$video_task   = $tasks_by_key[ $video_key ] ?? null;
				$still_task   = $tasks_by_key[ $still_key ] ?? null;

				$video_ok = $shot_id && is_array( $video_task )
					&& $shot_id === absint( $video_task['source_id'] ?? 0 )
					&& 'worldgraph_shot' === sanitize_key( (string) ( $video_task['source_type'] ?? '' ) )
					&& 'video' === sanitize_key( (string) ( $video_task['type'] ?? '' ) );
				$still_source_id   = is_array( $still_task ) ? absint( $still_task['source_id'] ?? 0 ) : 0;
				$still_source_type = is_array( $still_task ) ? sanitize_key( (string) ( $still_task['source_type'] ?? '' ) ) : '';
				$still_ok = is_array( $still_task ) && 'image' === sanitize_key( (string) ( $still_task['type'] ?? '' ) )
					&& ( ( $shot_id && 'worldgraph_shot' === $still_source_type && $shot_id === $still_source_id )
						|| ( ! $shot_id && $row_scene_id && 'worldgraph_scene' === $still_source_type && $row_scene_id === $still_source_id )
						|| ( ! $shot_id && ! $row_scene_id && 'worldgraph_project' === $still_source_type ) );

				$video_output = $video_ok && isset( $outputs_by_key[ $video_key ] ) ? $outputs_by_key[ $video_key ] : [];
				$still_output = $still_ok && isset( $outputs_by_key[ $still_key ] ) ? $outputs_by_key[ $still_key ] : [];
				$shot_title   = $video_ok ? self::subtitle_text( (string) ( $video_task['source_title'] ?? '' ) ) : '';
				if ( '' === $shot_title && $still_ok ) {
					$shot_title = self::subtitle_text( (string) ( $still_task['source_title'] ?? '' ) );
				}
				if ( '' === $shot_title && $shot_id ) {
					$shot_title = sprintf( __( 'Shot %d', 'worldgraph' ), $shot_id );
				}

				$subtitle   = self::subtitle_text( (string) ( $row['subtitle_text'] ?? '' ) );
				$dialogue   = array_values( array_filter( array_unique( [ $subtitle, $scene_subtitle ] ) ) );
				$audio_keys = array_values( array_unique( array_filter( array_merge( $scene_audio, array_map( 'strval', (array) ( $row['audio_task_keys'] ?? [] ) ) ) ) ) );
				$segments[] = [
					'shot_id'              => $shot_id,
					'shot_title'           => $shot_title,
					'shot_menu_order'      => isset( $row['shot_order'] ) ? (int) $row['shot_order'] : $shot_index,
					'shot_number'          => (float) $shot_index,
					'scene_id'             => $row_scene_id,
					'scene_title'          => $scene_title,
					'scene_menu_order'     => $scene_order,
					'scene_sequence_order' => $scene_order + 1,
					'scene_number'         => (float) $scene_order,
					'duration'             => self::bounded_duration( $row['duration'] ?? self::DEFAULT_SHOT_DURATION ),
					'video_file'           => (string) ( $video_output['file'] ?? '' ),
					'video_attachment_id'  => absint( $video_output['attachment_id'] ?? 0 ),
					'still_file'           => (string) ( $still_output['file'] ?? '' ),
					'still_attachment_id'  => absint( $still_output['attachment_id'] ?? 0 ),
					'dialogue_lines'       => $dialogue,
					'video_task_key'       => $video_key,
					'fallback_task_key'    => $still_key,
					'audio_task_keys'      => $audio_keys,
					'card_only'            => empty( $video_output['file'] ) && empty( $still_output['file'] ),
					'frozen_timeline'      => true,
				];
			}
		}

		return $segments;
	}

	/** Resolve and validate the configured binary without executing a shell. */
	private static function configured_binary(): string {
		if ( defined( 'WORLDGRAPH_FFMPEG_BINARY' ) ) {
			$binary = (string) constant( 'WORLDGRAPH_FFMPEG_BINARY' );
		} elseif ( defined( 'worldgraph_ffmpeg_binary' ) ) {
			$binary = (string) constant( 'worldgraph_ffmpeg_binary' );
		} else {
			$binary = 'ffmpeg';
		}
		$binary = (string) apply_filters( 'worldgraph_ffmpeg_binary', $binary );
		$binary = trim( $binary );
		if ( '' === $binary || preg_match( '/[\x00-\x1F\x7F]/', $binary ) ) {
			return '';
		}

		if ( false !== strpos( $binary, '/' ) || false !== strpos( $binary, '\\' ) ) {
			$resolved = realpath( $binary );
			return $resolved && is_file( $resolved ) && is_executable( $resolved ) ? $resolved : '';
		}

		return preg_match( '/^[A-Za-z0-9._+-]+$/', $binary ) ? $binary : '';
	}

	/** Batch kinds whose completed child jobs can supply an editorial cut. */
	private static function supported_batch_kinds(): array {
		$kinds = [];
		if ( defined( Generation_Workflows::class . '::DEMONSTRATION_BATCH' ) ) {
			$kinds[] = (string) constant( Generation_Workflows::class . '::DEMONSTRATION_BATCH' );
		}
		$kinds[] = 'demonstration_video';
		if ( defined( Generation_Workflows::class . '::REPRESENTATIVE_BATCH' ) ) {
			$kinds[] = (string) constant( Generation_Workflows::class . '::REPRESENTATIVE_BATCH' );
		}
		$kinds[] = 'representative_media';

		return array_values( array_unique( array_filter( $kinds ) ) );
	}

	/** Resolve the owning Project from a batch root. */
	private static function project_id( int $source_id ): int {
		if ( 'worldgraph_project' === get_post_type( $source_id ) ) {
			return $source_id;
		}
		if ( class_exists( Generation_Workflows::class ) ) {
			return Generation_Workflows::project_id_for_source( $source_id );
		}

		return 0;
	}

	/** Resolve even H.264 frame dimensions and a bounded Project frame rate. */
	private static function project_profile( int $project_id ): array {
		$profile = class_exists( Asset_Generator::class )
			? Asset_Generator::project_media_profile( $project_id )
			: [
				'width'      => worldgraph_get_field_value( $project_id, 'frame_width' ),
				'height'     => worldgraph_get_field_value( $project_id, 'frame_height' ),
				'frame_rate' => worldgraph_get_field_value( $project_id, 'frame_rate' ),
			];
		$width  = min( 8192, max( 2, absint( $profile['width'] ?? 1280 ) ) );
		$height = min( 8192, max( 2, absint( $profile['height'] ?? 720 ) ) );
		$width -= $width % 2;
		$height -= $height % 2;
		$fps = min( 120.0, max( 1.0, (float) ( $profile['frame_rate'] ?? $profile['fps'] ?? 24 ) ) );

		return [ 'width' => $width, 'height' => $height, 'fps' => $fps ];
	}

	/** Create one unpredictable request directory beneath WordPress's temp root. */
	private static function create_work_dir( int $batch_id ) {
		$temp_root = realpath( get_temp_dir() );
		if ( ! $temp_root || ! is_dir( $temp_root ) || ! is_writable( $temp_root ) ) {
			return self::error( 'worldgraph_rough_cut_temp_unavailable', __( 'WordPress has no writable temporary directory for FFmpeg.', 'worldgraph' ), [ 'status' => 500 ] );
		}

		$token = strtolower( wp_generate_password( 16, false, false ) );
		$token = preg_replace( '/[^a-z0-9]/', '', $token );
		if ( strlen( (string) $token ) < 8 ) {
			$token = substr( hash( 'sha256', wp_generate_uuid4() . ':' . microtime( true ) ), 0, 16 );
		}
		$directory = $temp_root . DIRECTORY_SEPARATOR . 'worldgraph-rough-cut-' . absint( $batch_id ) . '-' . $token;
		if ( strlen( $directory ) > 1024 || ! wp_mkdir_p( $directory ) ) {
			return self::error( 'worldgraph_rough_cut_temp_unavailable', __( 'The bounded FFmpeg working directory could not be created.', 'worldgraph' ), [ 'status' => 500 ] );
		}
		$real = realpath( $directory );
		if ( ! $real || ! self::path_is_within( $real, $temp_root ) || 0 !== strpos( basename( $real ), 'worldgraph-rough-cut-' ) ) {
			return self::error( 'worldgraph_rough_cut_temp_unsafe', __( 'The FFmpeg working directory could not be verified safely.', 'worldgraph' ), [ 'status' => 500 ] );
		}

		return $real;
	}

	/** Collect one best video and still for every Shot represented by the batch. */
	private static function batch_shots( int $batch_id, int $project_id, string $batch_kind, array &$warnings ) {
		$job_ids = get_posts( [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'posts_per_page' => 5000,
			'fields'         => 'ids',
			'meta_key'       => '_worldgraph_gen_batch_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $batch_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		if ( empty( $job_ids ) ) {
			return self::error( 'worldgraph_rough_cut_jobs_missing', __( 'The batch has no child generation jobs.', 'worldgraph' ), [ 'status' => 409 ] );
		}
		update_meta_cache( 'post', $job_ids );

		if ( 'demonstration_video' === $batch_kind ) {
			$frozen_tasks = self::frozen_batch_tasks( $batch_id );
			$assembly     = get_post_meta( $batch_id, '_worldgraph_gen_assembly_plan', true );
			$assembly     = is_array( $assembly ) ? $assembly : [];
			if ( isset( $assembly['assembly'] ) && is_array( $assembly['assembly'] ) ) {
				$assembly = $assembly['assembly'];
			}
			if ( ! empty( $assembly['timeline'] ) ) {
				$outputs  = self::completed_demonstration_outputs( $batch_id, $project_id, $job_ids, $frozen_tasks, $warnings );
				$segments = self::demonstration_segments_from_plan( [ 'assembly' => $assembly ], $frozen_tasks, $outputs );
				if ( count( $segments ) > self::MAX_SEGMENTS ) {
					return self::error(
						'worldgraph_rough_cut_too_large',
						__( 'The rough cut exceeds the bounded Shot segment limit.', 'worldgraph' ),
						[ 'segments' => count( $segments ), 'limit' => self::MAX_SEGMENTS, 'status' => 400 ]
					);
				}
				foreach ( $segments as $segment ) {
					if ( ! empty( $segment['card_only'] ) ) {
						$warnings[] = sprintf(
							__( 'Planned Shot “%s” has no completed video or still; a black title/subtitle card will preserve its timeline position.', 'worldgraph' ),
							(string) ( $segment['shot_title'] ?: $segment['scene_title'] )
						);
					}
				}

				// The frozen timeline order is authoritative for a demonstration.
				return $segments;
			}
		}

		$shots = [];
		foreach ( $job_ids as $job_id ) {
			$job_id    = absint( $job_id );
			$source_id = absint( get_post_meta( $job_id, '_worldgraph_gen_source_post_id', true ) ?: get_post_field( 'post_parent', $job_id ) );
			if ( ! $source_id || 'worldgraph_shot' !== get_post_type( $source_id ) ) {
				continue;
			}
			if ( self::project_id( $source_id ) !== $project_id ) {
				$warnings[] = sprintf( __( 'Shot %d was skipped because its Project provenance does not match the batch.', 'worldgraph' ), $source_id );
				continue;
			}
			if ( ! isset( $shots[ $source_id ] ) ) {
				$shots[ $source_id ] = self::shot_row( $source_id );
			}
			if ( 'completed' !== sanitize_key( (string) get_post_meta( $job_id, '_worldgraph_gen_status', true ) ) ) {
				continue;
			}

			$type = sanitize_key( (string) get_post_meta( $job_id, '_worldgraph_gen_type', true ) );
			if ( ! in_array( $type, [ 'video', 'image' ], true ) ) {
				continue;
			}
			$attachment = self::job_attachment( $job_id, $batch_id, $source_id, $type );
			if ( ! $attachment ) {
				continue;
			}
			$key = 'video' === $type ? 'video' : 'still';
			if ( empty( $shots[ $source_id ][ $key . '_file'] ) ) {
				$shots[ $source_id ][ $key . '_attachment_id'] = (int) $attachment['id'];
				$shots[ $source_id ][ $key . '_file']          = (string) $attachment['file'];
			}
		}

		foreach ( $shots as $shot_id => &$shot ) {
			if ( empty( $shot['still_file'] ) ) {
				$featured = self::attachment_file( (int) get_post_thumbnail_id( $shot_id ), 'image' );
				if ( $featured ) {
					$shot['still_attachment_id'] = (int) $featured['id'];
					$shot['still_file']          = (string) $featured['file'];
				}
			}
			if ( empty( $shot['video_file'] ) && empty( $shot['still_file'] ) ) {
				$warnings[] = sprintf( __( 'Shot “%s” has neither a completed video nor a readable representative still.', 'worldgraph' ), (string) $shot['shot_title'] );
			}
		}
		unset( $shot );

		$shots = array_values( array_filter( $shots, static function ( array $shot ): bool {
			return ! empty( $shot['video_file'] ) || ! empty( $shot['still_file'] );
		} ) );
		if ( empty( $shots ) && 'demonstration_video' === $batch_kind ) {
			$shots = self::demonstration_fallback_segments( $batch_id, $project_id, $job_ids, $warnings );
		}
		usort( $shots, [ __CLASS__, 'compare_editorial_rows' ] );
		if ( count( $shots ) > self::MAX_SEGMENTS ) {
			return self::error(
				'worldgraph_rough_cut_too_large',
				__( 'The rough cut exceeds the bounded Shot segment limit.', 'worldgraph' ),
				[ 'segments' => count( $shots ), 'limit' => self::MAX_SEGMENTS, 'status' => 400 ]
			);
		}

		return $shots;
	}

	/** Return the flat, frozen task list saved with a generation batch. */
	private static function frozen_batch_tasks( int $batch_id ): array {
		$frozen = get_post_meta( $batch_id, '_worldgraph_gen_batch_plan', true );
		$frozen = is_array( $frozen ) ? $frozen : [];
		if ( isset( $frozen['tasks'] ) && is_array( $frozen['tasks'] ) ) {
			$frozen = $frozen['tasks'];
		}

		return array_values( array_filter( $frozen, 'is_array' ) );
	}

	/**
	 * Resolve completed demonstration outputs through frozen step provenance.
	 *
	 * @param array<int,int|string>          $job_ids Child generation jobs.
	 * @param array<int,array<string,mixed>> $tasks   Flat frozen task list.
	 * @return array<int,array<string,mixed>>
	 */
	private static function completed_demonstration_outputs( int $batch_id, int $project_id, array $job_ids, array $tasks, array &$warnings ): array {
		$tasks_by_step = [];
		foreach ( array_slice( array_values( $tasks ), 0, 5000 ) as $index => $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$step = isset( $task['step'] ) ? (int) $task['step'] : $index;
			if ( $step >= 0 ) {
				$tasks_by_step[ $step ] = $task;
			}
		}

		$outputs = [];
		foreach ( $job_ids as $job_id ) {
			$job_id = absint( $job_id );
			if ( 'completed' !== sanitize_key( (string) get_post_meta( $job_id, '_worldgraph_gen_status', true ) ) ) {
				continue;
			}
			$step = (int) get_post_meta( $job_id, '_worldgraph_gen_batch_step', true );
			$task = $tasks_by_step[ $step ] ?? null;
			if ( ! is_array( $task ) ) {
				continue;
			}
			$task_key    = trim( (string) ( $task['task_key'] ?? '' ) );
			$type        = sanitize_key( (string) get_post_meta( $job_id, '_worldgraph_gen_type', true ) );
			$source_id   = absint( get_post_meta( $job_id, '_worldgraph_gen_source_post_id', true ) ?: get_post_field( 'post_parent', $job_id ) );
			$source_type = sanitize_key( (string) get_post_type( $source_id ) );
			if ( '' === $task_key || ! in_array( $type, [ 'video', 'image' ], true )
				|| $source_id !== absint( $task['source_id'] ?? 0 )
				|| $source_type !== sanitize_key( (string) ( $task['source_type'] ?? '' ) )
				|| $type !== sanitize_key( (string) ( $task['type'] ?? '' ) )
				|| self::project_id( $source_id ) !== $project_id ) {
				$warnings[] = sprintf( __( 'Generation job %d was skipped because its frozen demonstration step does not match its source provenance.', 'worldgraph' ), $job_id );
				continue;
			}
			$attachment = self::job_attachment( $job_id, $batch_id, $source_id, $type );
			if ( empty( $attachment ) ) {
				continue;
			}
			$outputs[] = [
				'task_key'      => $task_key,
				'source_id'     => $source_id,
				'source_type'   => $source_type,
				'type'          => $type,
				'attachment_id' => (int) $attachment['id'],
				'file'          => (string) $attachment['file'],
			];
		}

		return $outputs;
	}

	/**
	 * Build Scene/Project still cards when a demonstration story has no Shots.
	 *
	 * @param array<int,int|string> $job_ids Child job IDs from the current batch.
	 */
	private static function demonstration_fallback_segments( int $batch_id, int $project_id, array $job_ids, array &$warnings ): array {
		$frozen = get_post_meta( $batch_id, '_worldgraph_gen_batch_plan', true );
		$frozen = is_array( $frozen ) ? $frozen : [];
		$assembly = get_post_meta( $batch_id, '_worldgraph_gen_assembly_plan', true );
		$assembly = is_array( $assembly ) ? $assembly : [];
		if ( isset( $assembly['assembly'] ) && is_array( $assembly['assembly'] ) ) {
			$assembly = $assembly['assembly'];
		}

		$tasks = $frozen;
		if ( isset( $frozen['tasks'] ) && is_array( $frozen['tasks'] ) ) {
			$tasks = $frozen['tasks'];
		}
		if ( empty( $assembly ) && isset( $frozen['assembly'] ) && is_array( $frozen['assembly'] ) ) {
			$assembly = $frozen['assembly'];
		}

		$completed = self::completed_fallback_tasks( $batch_id, $project_id, $job_ids, is_array( $tasks ) ? $tasks : [], $warnings );
		$segments  = self::fallback_segments_from_plan( [ 'assembly' => $assembly ], $completed );
		if ( empty( $segments ) ) {
			$segments = self::direct_fallback_segments( $completed );
		}
		if ( ! empty( $segments ) ) {
			$warnings[] = __( 'No completed Shot media was available; completed Scene or Project stills were used as rough-cut title cards.', 'worldgraph' );
		}

		return array_slice( $segments, 0, self::MAX_SEGMENTS );
	}

	/**
	 * Map completed Scene/Project image children to their frozen task keys.
	 *
	 * @param array<int,int|string>          $job_ids Child job IDs.
	 * @param array<int,array<string,mixed>> $tasks   Flat frozen task list.
	 * @return array<int,array<string,mixed>>
	 */
	private static function completed_fallback_tasks( int $batch_id, int $project_id, array $job_ids, array $tasks, array &$warnings ): array {
		$tasks_by_step = [];
		foreach ( array_slice( array_values( $tasks ), 0, 5000 ) as $index => $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$step = isset( $task['step'] ) ? (int) $task['step'] : $index;
			if ( $step >= 0 ) {
				$tasks_by_step[ $step ] = $task;
			}
		}

		$completed = [];
		foreach ( $job_ids as $job_id ) {
			$job_id = absint( $job_id );
			if ( 'completed' !== sanitize_key( (string) get_post_meta( $job_id, '_worldgraph_gen_status', true ) )
				|| 'image' !== sanitize_key( (string) get_post_meta( $job_id, '_worldgraph_gen_type', true ) ) ) {
				continue;
			}
			$source_id   = absint( get_post_meta( $job_id, '_worldgraph_gen_source_post_id', true ) ?: get_post_field( 'post_parent', $job_id ) );
			$source_type = sanitize_key( (string) get_post_type( $source_id ) );
			if ( ! in_array( $source_type, [ 'worldgraph_scene', 'worldgraph_project' ], true )
				|| ( 'worldgraph_project' === $source_type ? $source_id !== $project_id : self::project_id( $source_id ) !== $project_id ) ) {
				continue;
			}

			$step = (int) get_post_meta( $job_id, '_worldgraph_gen_batch_step', true );
			$task = $tasks_by_step[ $step ] ?? [];
			if ( ! empty( $task ) ) {
				$declared_source = absint( $task['source_id'] ?? 0 );
				$declared_type   = sanitize_key( (string) ( $task['type'] ?? '' ) );
				$declared_source_type = sanitize_key( (string) ( $task['source_type'] ?? '' ) );
				if ( ( $declared_source && $declared_source !== $source_id )
					|| ( '' !== $declared_type && 'image' !== $declared_type )
					|| ( '' !== $declared_source_type && $declared_source_type !== $source_type ) ) {
					$warnings[] = sprintf( __( 'Generation job %d was skipped because its frozen demonstration step does not match its source provenance.', 'worldgraph' ), $job_id );
					continue;
				}
			}

			$attachment = self::job_attachment( $job_id, $batch_id, $source_id, 'image' );
			if ( empty( $attachment ) ) {
				continue;
			}
			$post = get_post( $source_id );
			$completed[] = [
				'task_key'      => trim( (string) ( $task['task_key'] ?? '' ) ),
				'step'          => $step,
				'timeline_order'=> isset( $task['timeline_order'] ) ? (int) $task['timeline_order'] : $step,
				'source_id'     => $source_id,
				'source_type'   => $source_type,
				'source_title'  => (string) ( $task['source_title'] ?? ( $post instanceof \WP_Post ? $post->post_title : '' ) ),
				'duration'      => $task['duration'] ?? '',
				'attachment_id' => (int) $attachment['id'],
				'still_file'    => (string) $attachment['file'],
			];
			if ( count( $completed ) >= self::MAX_SEGMENTS * 2 ) {
				break;
			}
		}

		return $completed;
	}

	/** Build bounded cards directly when an older frozen plan lacks assembly metadata. */
	private static function direct_fallback_segments( array $completed ): array {
		usort( $completed, static function ( array $left, array $right ): int {
			$order = (int) ( $left['timeline_order'] ?? $left['step'] ?? 0 ) <=> (int) ( $right['timeline_order'] ?? $right['step'] ?? 0 );
			return 0 !== $order ? $order : (int) ( $left['source_id'] ?? 0 ) <=> (int) ( $right['source_id'] ?? 0 );
		} );

		$segments = [];
		foreach ( array_slice( $completed, 0, self::MAX_SEGMENTS ) as $index => $task ) {
			$source_id   = absint( $task['source_id'] ?? 0 );
			$source_type = sanitize_key( (string) ( $task['source_type'] ?? '' ) );
			if ( ! $source_id || ! in_array( $source_type, [ 'worldgraph_scene', 'worldgraph_project' ], true ) || empty( $task['still_file'] ) ) {
				continue;
			}
			$post       = get_post( $source_id );
			$title      = self::subtitle_text( (string) ( $task['source_title'] ?? ( $post instanceof \WP_Post ? $post->post_title : '' ) ) );
			$caption    = $post instanceof \WP_Post ? (string) ( $post->post_excerpt ?: $post->post_content ?: $post->post_title ) : $title;
			$scene_id   = 'worldgraph_scene' === $source_type ? $source_id : 0;
			$scene_order = isset( $task['timeline_order'] ) ? (int) $task['timeline_order'] : $index;
			$segments[] = [
				'shot_id'             => 0,
				'shot_title'          => $title,
				'shot_menu_order'     => 0,
				'shot_number'         => 0.0,
				'scene_id'            => $scene_id,
				'scene_title'         => $title,
				'scene_menu_order'    => $scene_order,
				'scene_sequence_order'=> $scene_order + 1,
				'scene_number'        => (float) $scene_order,
				'duration'            => self::bounded_duration( $task['duration'] ?? '' ),
				'video_file'          => '',
				'still_file'          => (string) $task['still_file'],
				'still_attachment_id' => absint( $task['attachment_id'] ?? 0 ),
				'dialogue_lines'      => array_values( array_filter( [ self::subtitle_text( $caption ) ] ) ),
				'task_key'            => (string) ( $task['task_key'] ?? '' ),
			];
		}

		return $segments;
	}

	/** Build sortable Shot and Scene metadata. */
	private static function shot_row( int $shot_id ): array {
		$shot     = get_post( $shot_id );
		$scene_id = self::related_post_id( $shot_id, 'scene', 'worldgraph_scene' );
		$scene    = $scene_id ? get_post( $scene_id ) : null;

		return [
			'shot_id'             => $shot_id,
			'shot_title'          => $shot instanceof \WP_Post ? (string) $shot->post_title : (string) $shot_id,
			'shot_menu_order'     => $shot instanceof \WP_Post ? (int) $shot->menu_order : 0,
			'shot_number'         => (float) worldgraph_get_field_value( $shot_id, 'shot_number' ),
			'scene_id'            => $scene_id,
			'scene_title'         => $scene instanceof \WP_Post ? (string) $scene->post_title : '',
			'scene_menu_order'    => $scene instanceof \WP_Post ? (int) $scene->menu_order : 0,
			'scene_sequence_order'=> $scene_id ? absint( get_post_meta( $scene_id, 'sequence_order', true ) ) : 0,
			'scene_number'        => $scene_id ? (float) worldgraph_get_field_value( $scene_id, 'scene_number' ) : 0.0,
			'duration'            => self::bounded_duration( worldgraph_get_field_value( $shot_id, 'duration' ) ),
			'video_file'          => '',
			'still_file'          => '',
			'dialogue_lines'      => [],
		];
	}

	/** Editorial comparison: Scene sequence/menu/number, then Shot menu/number. */
	private static function compare_editorial_rows( array $left, array $right ): int {
		$keys = [
			[ 'scene_sequence_order', true ],
			[ 'scene_menu_order', false ],
			[ 'scene_number', false ],
			[ 'shot_menu_order', false ],
			[ 'shot_number', false ],
			[ 'scene_id', false ],
			[ 'shot_id', false ],
		];
		foreach ( $keys as $definition ) {
			$key   = (string) $definition[0];
			$left_value  = (float) ( $left[ $key ] ?? 0 );
			$right_value = (float) ( $right[ $key ] ?? 0 );
			if ( ! empty( $definition[1] ) ) {
				$left_value  = $left_value > 0 ? $left_value : PHP_INT_MAX;
				$right_value = $right_value > 0 ? $right_value : PHP_INT_MAX;
			}
			if ( $left_value !== $right_value ) {
				return $left_value <=> $right_value;
			}
		}

		return strcasecmp( (string) ( $left['shot_title'] ?? '' ), (string) ( $right['shot_title'] ?? '' ) );
	}

	/** Select a local imported attachment whose provenance still matches its job. */
	private static function job_attachment( int $job_id, int $batch_id, int $source_id, string $type ): array {
		$ids = (array) get_post_meta( $job_id, '_worldgraph_gen_attachment_ids', true );
		array_unshift( $ids, get_post_meta( $job_id, '_worldgraph_gen_attachment_id', true ) );
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) as $attachment_id ) {
			$attachment_job = absint( get_post_meta( $attachment_id, '_worldgraph_gen_job_id', true ) );
			$attachment_batch = absint( get_post_meta( $attachment_id, '_worldgraph_gen_batch_id', true ) );
			$attachment_source = absint( get_post_meta( $attachment_id, '_worldgraph_gen_source_post_id', true ) ?: get_post_meta( $attachment_id, '_worldgraph_generated_from', true ) );
			if ( ( $attachment_job && $attachment_job !== $job_id ) || ( $attachment_batch && $attachment_batch !== $batch_id ) || ( $attachment_source && $attachment_source !== $source_id ) ) {
				continue;
			}
			$file = self::attachment_file( $attachment_id, $type );
			if ( $file ) {
				return $file;
			}
		}

		return [];
	}

	/** Resolve a readable local Media Library file of the expected kind. */
	private static function attachment_file( int $attachment_id, string $kind ): array {
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return [];
		}
		$mime = strtolower( (string) get_post_mime_type( $attachment_id ) );
		if ( 0 !== strpos( $mime, $kind . '/' ) ) {
			return [];
		}
		$file = get_attached_file( $attachment_id );
		$file = is_string( $file ) ? realpath( $file ) : false;
		if ( ! $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return [];
		}

		return [ 'id' => $attachment_id, 'file' => $file, 'mime' => $mime ];
	}

	/** Resolve one relationship field to the requested post type. */
	private static function related_post_id( int $source_id, string $field, string $post_type ): int {
		$value = worldgraph_get_field_value( $source_id, $field );
		foreach ( is_array( $value ) ? $value : [ $value ] as $candidate ) {
			$candidate_id = $candidate instanceof \WP_Post ? (int) $candidate->ID : absint( $candidate );
			if ( $candidate_id && $post_type === get_post_type( $candidate_id ) ) {
				return $candidate_id;
			}
		}
		if ( 'worldgraph_shot' === get_post_type( $source_id ) && class_exists( Generation_Workflows::class ) ) {
			foreach ( Generation_Workflows::ancestors( $source_id ) as $ancestor ) {
				if ( $post_type === $ancestor->post_type ) {
					return (int) $ancestor->ID;
				}
			}
		}

		return 0;
	}

	/** Normalize a video, or a representative still, into one deterministic segment. */
	private static function normalize_shot( string $binary, array $shot, array $profile, string $output, string $work_dir, bool $force_still ) {
		$use_still = $force_still || empty( $shot['video_file'] );
		$input     = $use_still ? (string) ( $shot['still_file'] ?? '' ) : (string) ( $shot['video_file'] ?? '' );
		$use_card  = '' === $input;
		$duration = self::bounded_duration( $shot['duration'] ?? self::DEFAULT_SHOT_DURATION );
		$fps      = self::decimal( (float) $profile['fps'] );
		$seconds  = self::decimal( $duration );
		$filter   = sprintf(
			'scale=w=%1$d:h=%2$d:force_original_aspect_ratio=decrease,pad=%1$d:%2$d:(ow-iw)/2:(oh-ih)/2:color=black,fps=%3$s,format=yuv420p,tpad=stop_mode=clone:stop_duration=%4$s,trim=duration=%4$s,setpts=PTS-STARTPTS',
			(int) $profile['width'],
			(int) $profile['height'],
			$fps,
			$seconds
		);
		$args = [];
		if ( $use_card ) {
			$args = [ '-f', 'lavfi', '-i', sprintf( 'color=c=black:s=%1$dx%2$d:r=%3$s:d=%4$s', (int) $profile['width'], (int) $profile['height'], $fps, $seconds ) ];
		} elseif ( $use_still ) {
			$args = [ '-loop', '1', '-framerate', $fps ];
		}
		if ( ! $use_card ) {
			$args = array_merge( $args, [ '-i', $input ] );
		}
		$args = array_merge(
			$args,
			[
				'-map', '0:v:0', '-an', '-vf', $filter,
				'-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p',
				'-r', $fps, '-t', $seconds, '-movflags', '+faststart', $output,
			]
		);

		return self::run_ffmpeg(
			$binary,
			$args,
			$work_dir,
			'worldgraph_rough_cut_normalize_failed',
			__( 'FFmpeg could not normalize this Shot.', 'worldgraph' )
		);
	}

	/** Add cumulative times and distribute each Scene's dialogue across its Shots. */
	private static function assign_timeline_and_dialogue( array &$segments ): void {
		$cursor = 0.0;
		$groups = [];
		foreach ( $segments as $index => &$segment ) {
			$segment['start'] = $cursor;
			$cursor          += (float) $segment['duration'];
			$segment['end']   = $cursor;
			if ( empty( $segment['frozen_timeline'] ) ) {
				$groups[ (int) $segment['scene_id'] ][] = $index;
			}
		}
		unset( $segment );

		foreach ( $groups as $scene_id => $indices ) {
			$dialogue = self::scene_dialogue( (int) $scene_id );
			$count     = count( $dialogue );
			if ( ! $count ) {
				continue;
			}
			foreach ( $dialogue as $index => $line ) {
				$target = min( count( $indices ) - 1, (int) floor( $index * count( $indices ) / $count ) );
				$segments[ $indices[ $target ] ]['dialogue_lines'][] = $line;
			}
		}
	}

	/** Normalize and order structured Scene dialogue rows. */
	private static function scene_dialogue( int $scene_id ): array {
		$rows = $scene_id ? worldgraph_get_field_value( $scene_id, 'dialogue' ) : [];
		if ( is_string( $rows ) ) {
			$decoded = json_decode( $rows, true );
			$rows    = is_array( $decoded ) ? $decoded : [];
		}
		$rows = is_array( $rows ) ? array_values( $rows ) : [];
		usort( $rows, static function ( $left, $right ): int {
			$left  = is_array( $left ) ? (float) ( $left['sequence'] ?? PHP_INT_MAX ) : PHP_INT_MAX;
			$right = is_array( $right ) ? (float) ( $right['sequence'] ?? PHP_INT_MAX ) : PHP_INT_MAX;
			return $left <=> $right;
		} );

		$lines = [];
		foreach ( array_slice( $rows, 0, 200 ) as $row ) {
			if ( is_array( $row ) ) {
				$speaker = self::subtitle_text( (string) ( $row['speaker'] ?? '' ) );
				$line    = self::subtitle_text( (string) ( $row['line'] ?? $row['text'] ?? $row['description'] ?? '' ) );
				$text    = trim( ( '' !== $speaker ? $speaker . ': ' : '' ) . $line );
			} else {
				$text = is_scalar( $row ) ? self::subtitle_text( (string) $row ) : '';
			}
			if ( '' !== $text ) {
				$lines[] = $text;
			}
		}

		return $lines;
	}

	/** Discover locally imported, generated Sound audio linked to the cut. */
	private static function sound_cues( int $project_id, int $batch_id, array $segments, float $fps, array &$warnings ): array {
		$frozen = self::frozen_sound_cues( $project_id, $batch_id, $segments, $fps, $warnings );
		if ( null !== $frozen ) {
			return $frozen;
		}

		$scene_starts = [];
		$shot_starts  = [];
		$scene_ids    = [];
		$shot_ids     = [];
		foreach ( $segments as $segment ) {
			$scene_id = (int) $segment['scene_id'];
			$shot_id  = (int) $segment['shot_id'];
			$scene_ids[ $scene_id ] = true;
			$shot_ids[ $shot_id ]   = true;
			$scene_starts[ $scene_id ] = isset( $scene_starts[ $scene_id ] ) ? min( $scene_starts[ $scene_id ], (float) $segment['start'] ) : (float) $segment['start'];
			$shot_starts[ $shot_id ]   = (float) $segment['start'];
		}

		$sounds = get_posts( [
			'post_type'      => 'worldgraph_sound',
			'post_status'    => 'any',
			'posts_per_page' => 1000,
			'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
		] );
		$cues = [];
		$cut_duration = empty( $segments ) ? 0.0 : (float) $segments[ count( $segments ) - 1 ]['end'];
		foreach ( $sounds as $sound ) {
			if ( ! $sound instanceof \WP_Post ) {
				continue;
			}
			$scene_id = self::related_post_id( (int) $sound->ID, 'scene', 'worldgraph_scene' );
			$shot_id  = self::related_post_id( (int) $sound->ID, 'shot', 'worldgraph_shot' );
			if ( ( $scene_id && empty( $scene_ids[ $scene_id ] ) ) || ( $shot_id && empty( $shot_ids[ $shot_id ] ) ) || ( ! $scene_id && ! $shot_id ) ) {
				continue;
			}
			$owner_source = $shot_id ?: $scene_id;
			if ( $owner_source && self::project_id( $owner_source ) !== $project_id ) {
				continue;
			}
			$role = self::sound_role( (int) $sound->ID );
			if ( 'silence' === $role ) {
				continue;
			}
			$attachment = self::generated_sound_attachment( (int) $sound->ID, $batch_id );
			if ( empty( $attachment['file'] ) ) {
				$warnings[] = sprintf( __( 'Sound cue “%s” has no readable generated audio attachment.', 'worldgraph' ), (string) $sound->post_title );
				continue;
			}
			$base   = $shot_id ? (float) ( $shot_starts[ $shot_id ] ?? 0 ) : (float) ( $scene_starts[ $scene_id ] ?? 0 );
			$offset = $base + self::parse_timecode( (string) worldgraph_get_field_value( (int) $sound->ID, 'start_timecode' ), $fps );
			$offset = min( 86400.0, max( 0.0, $offset ) );
			if ( $offset >= $cut_duration ) {
				$warnings[] = sprintf( __( 'Sound cue “%s” starts after the assembled picture and was omitted.', 'worldgraph' ), (string) $sound->post_title );
				continue;
			}
			$cues[] = [
				'sound_id' => (int) $sound->ID,
				'file'     => (string) $attachment['file'],
				'offset'   => $offset,
				'duration' => min( max( 0.0, $cut_duration - $offset ), self::optional_duration( worldgraph_get_field_value( (int) $sound->ID, 'duration' ) ) ?: $cut_duration ),
				'role'     => $role,
				'volume'   => self::role_volume( $role ),
			];
			if ( count( $cues ) >= self::MAX_AUDIO_CUES ) {
				$warnings[] = __( 'Additional Sound cues were omitted because the rough-cut mix reached its bounded cue limit.', 'worldgraph' );
				break;
			}
		}

		return $cues;
	}

	/**
	 * Build demonstration audio placement from frozen task keys and cue metadata.
	 *
	 * Returning null means this is a legacy/non-demonstration batch and the
	 * mutable compatibility path may be used. Returning an empty array means the
	 * frozen plan deliberately contains no usable cues.
	 */
	private static function frozen_sound_cues( int $project_id, int $batch_id, array $segments, float $fps, array &$warnings ): ?array {
		if ( 'demonstration_video' !== (string) get_post_meta( $batch_id, '_worldgraph_gen_batch_kind', true ) ) {
			return null;
		}
		$tasks = self::frozen_batch_tasks( $batch_id );
		if ( empty( $tasks ) ) {
			return null;
		}

		$tasks_by_key = [];
		foreach ( $tasks as $task ) {
			$key = trim( (string) ( $task['task_key'] ?? '' ) );
			if ( '' !== $key && 'audio' === sanitize_key( (string) ( $task['type'] ?? '' ) ) ) {
				$tasks_by_key[ $key ] = $task;
			}
		}

		$key_context  = [];
		$cut_duration = empty( $segments ) ? 0.0 : (float) $segments[ count( $segments ) - 1 ]['end'];
		foreach ( $segments as $segment ) {
			foreach ( array_values( array_unique( array_filter( array_map( 'strval', (array) ( $segment['audio_task_keys'] ?? [] ) ) ) ) ) as $key ) {
				$key_context[ $key ][] = [
					'offset'   => (float) ( $segment['start'] ?? 0.0 ),
					'scene_id' => absint( $segment['scene_id'] ?? 0 ),
					'shot_id'  => absint( $segment['shot_id'] ?? 0 ),
				];
			}
		}

		$cues = [];
		foreach ( $key_context as $key => $contexts ) {
			$task = $tasks_by_key[ $key ] ?? null;
			if ( ! is_array( $task ) || 'worldgraph_sound' !== sanitize_key( (string) ( $task['source_type'] ?? '' ) ) ) {
				continue;
			}
			$task_scene_id = absint( $task['scene_id'] ?? 0 );
			$task_shot_id  = absint( $task['shot_id'] ?? 0 );
			$context       = null;
			foreach ( $contexts as $candidate ) {
				if ( ( ! $task_scene_id || $task_scene_id === (int) $candidate['scene_id'] )
					&& ( ! $task_shot_id || $task_shot_id === (int) $candidate['shot_id'] ) ) {
					$context = $candidate;
					break;
				}
			}
			if ( ! is_array( $context ) ) {
				$warnings[] = sprintf( __( 'Frozen audio task “%s” was skipped because its timeline context does not match.', 'worldgraph' ), $key );
				continue;
			}
			$sound_id = absint( $task['source_id'] ?? 0 );
			$role     = sanitize_key( (string) ( $task['audio_role'] ?? '' ) );
			if ( ! $sound_id || 'silence' === $role ) {
				continue;
			}
			$attachment = self::generated_sound_attachment( $sound_id, $batch_id );
			$title      = self::subtitle_text( (string) ( $task['source_title'] ?? $key ) );
			if ( empty( $attachment['file'] ) ) {
				$warnings[] = sprintf( __( 'Sound cue “%s” has no readable generated or linked audio attachment.', 'worldgraph' ), $title );
				continue;
			}
			$offset = min( 86400.0, max( 0.0, (float) $context['offset'] + self::parse_timecode( (string) ( $task['start_timecode'] ?? '' ), $fps ) ) );
			if ( $offset >= $cut_duration ) {
				$warnings[] = sprintf( __( 'Sound cue “%s” starts after the assembled picture and was omitted.', 'worldgraph' ), $title );
				continue;
			}
			$cues[] = [
				'sound_id' => $sound_id,
				'task_key' => $key,
				'file'     => (string) $attachment['file'],
				'offset'   => $offset,
				'duration' => min( max( 0.0, $cut_duration - $offset ), self::optional_duration( $task['duration'] ?? '' ) ?: $cut_duration ),
				'role'     => $role,
				'volume'   => self::role_volume( $role ),
			];
			if ( count( $cues ) >= self::MAX_AUDIO_CUES ) {
				$warnings[] = __( 'Additional Sound cues were omitted because the rough-cut mix reached its bounded cue limit.', 'worldgraph' );
				break;
			}
		}

		return $cues;
	}

	/** Resolve the first Sound Type term slug. */
	private static function sound_role( int $sound_id ): string {
		$terms = get_the_terms( $sound_id, 'worldgraph_sound_type' );
		if ( is_array( $terms ) && ! empty( $terms ) && ! empty( $terms[0]->slug ) ) {
			return sanitize_title( (string) $terms[0]->slug );
		}
		$value = worldgraph_get_field_value( $sound_id, 'sound_type' );
		if ( is_numeric( $value ) ) {
			$term = get_term( absint( $value ), 'worldgraph_sound_type' );
			return $term && ! is_wp_error( $term ) ? sanitize_title( (string) $term->slug ) : '';
		}

		return sanitize_title( (string) $value );
	}

	/** Find the newest completed audio generation attachment for one Sound. */
	private static function generated_sound_attachment( int $sound_id, int $batch_id ): array {
		$query = [
			'post_type'      => 'worldgraph_gen',
			'post_status'    => 'any',
			'post_parent'    => $sound_id,
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [
				[ 'key' => '_worldgraph_gen_status', 'value' => 'completed' ],
				[ 'key' => '_worldgraph_gen_type', 'value' => 'audio' ],
			],
		];
		$current_query = $query;
		$current_query['meta_query'][] = [ 'key' => '_worldgraph_gen_batch_id', 'value' => $batch_id ];
		$jobs = array_values( array_unique( array_merge( get_posts( $current_query ), get_posts( $query ) ) ) );
		foreach ( $jobs as $job_id ) {
			$ids = (array) get_post_meta( (int) $job_id, '_worldgraph_gen_attachment_ids', true );
			array_unshift( $ids, get_post_meta( (int) $job_id, '_worldgraph_gen_attachment_id', true ) );
			foreach ( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) as $attachment_id ) {
				$source = absint( get_post_meta( $attachment_id, '_worldgraph_gen_source_post_id', true ) ?: get_post_meta( $attachment_id, '_worldgraph_generated_from', true ) );
				$job    = absint( get_post_meta( $attachment_id, '_worldgraph_gen_job_id', true ) );
				if ( ( $source && $source !== $sound_id ) || ( $job && $job !== (int) $job_id ) ) {
					continue;
				}
				$file = self::attachment_file( $attachment_id, 'audio' );
				if ( $file ) {
					return $file;
				}
			}
		}

		$candidates = (array) get_post_meta( $sound_id, '_worldgraph_asset_gallery_ids', true );
		$asset_id   = self::related_post_id( $sound_id, 'asset', 'worldgraph_asset' );
		if ( $asset_id ) {
			$candidates = array_merge( $candidates, (array) get_post_meta( $asset_id, '_worldgraph_asset_gallery_ids', true ) );
			$candidates[] = get_post_thumbnail_id( $asset_id );
			$storage_url = trim( (string) worldgraph_get_field_value( $asset_id, 'storage_uri' ) );
			if ( '' !== $storage_url && function_exists( 'attachment_url_to_postid' ) ) {
				$candidates[] = attachment_url_to_postid( $storage_url );
			}
		}
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $candidates ) ) ) ) as $attachment_id ) {
			$source = absint( get_post_meta( $attachment_id, '_worldgraph_gen_source_post_id', true ) ?: get_post_meta( $attachment_id, '_worldgraph_generated_from', true ) );
			if ( $source && ! in_array( $source, array_filter( [ $sound_id, $asset_id ] ), true ) ) {
				continue;
			}
			$file = self::attachment_file( $attachment_id, 'audio' );
			if ( $file ) {
				return $file;
			}
		}

		return [];
	}

	/** Mix generated Sound cues over a bounded silent base track. */
	private static function mix_audio( string $binary, string $video, array $cues, float $duration, string $output, string $work_dir ) {
		if ( empty( $cues ) ) {
			return self::add_silence( $binary, $video, $duration, $output, $work_dir );
		}
		$args = [ '-i', $video ];
		foreach ( $cues as $cue ) {
			$args[] = '-i';
			$args[] = (string) $cue['file'];
		}
		$filters = [ 'anullsrc=r=48000:cl=stereo,atrim=duration=' . self::decimal( $duration ) . '[silent]' ];
		$mix     = [ '[silent]' ];
		foreach ( $cues as $index => $cue ) {
			$label = 'cue' . $index;
			$chain = '[' . ( $index + 1 ) . ':a:0]aformat=sample_rates=48000:channel_layouts=stereo,volume=' . self::decimal( (float) $cue['volume'] );
			if ( ! empty( $cue['duration'] ) ) {
				$chain .= ',atrim=duration=' . self::decimal( (float) $cue['duration'] );
			}
			$delay  = max( 0, (int) round( 1000 * (float) $cue['offset'] ) );
			$chain .= ',adelay=' . $delay . '|' . $delay . '[' . $label . ']';
			$filters[] = $chain;
			$mix[]     = '[' . $label . ']';
		}
		$filters[] = implode( '', $mix ) . 'amix=inputs=' . count( $mix ) . ':duration=first:dropout_transition=0:normalize=0[aout]';
		$args = array_merge(
			$args,
			[
				'-filter_complex', implode( ';', $filters ), '-map', '0:v:0', '-map', '[aout]',
				'-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-t', self::decimal( $duration ),
				'-movflags', '+faststart', $output,
			]
		);

		return self::run_ffmpeg( $binary, $args, $work_dir, 'worldgraph_rough_cut_audio_mix_failed', __( 'FFmpeg could not mix the generated Sound cues.', 'worldgraph' ) );
	}

	/** Add an AAC silence track when no usable Sound mix is available. */
	private static function add_silence( string $binary, string $video, float $duration, string $output, string $work_dir ) {
		return self::run_ffmpeg(
			$binary,
			[
				'-i', $video, '-f', 'lavfi', '-t', self::decimal( $duration ), '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000',
				'-map', '0:v:0', '-map', '1:a:0', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k',
				'-t', self::decimal( $duration ), '-shortest', '-movflags', '+faststart', $output,
			],
			$work_dir,
			'worldgraph_rough_cut_silence_failed',
			__( 'FFmpeg could not add a silent audio track to the rough cut.', 'worldgraph' )
		);
	}

	/** Reuse a previously imported final video after a worker crash/retry. */
	private static function existing_imported_video( int $batch_id, int $project_id ): array {
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_parent'    => $project_id,
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_query'     => [
				[ 'key' => '_worldgraph_gen_batch_id', 'value' => $batch_id ],
				[ 'key' => '_worldgraph_rough_cut', 'value' => 1 ],
			],
		] );
		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$source_id      = absint( get_post_meta( $attachment_id, '_worldgraph_generated_from', true ) );
			$url            = (string) wp_get_attachment_url( $attachment_id );
			$attached_file  = (string) get_attached_file( $attachment_id );
			if ( $source_id === $project_id && '' !== $url && is_readable( $attached_file ) && 0 === strpos( strtolower( (string) get_post_mime_type( $attachment_id ) ), 'video/' ) ) {
				return [ 'attachment_id' => $attachment_id, 'url' => $url ];
			}
		}

		return [];
	}

	/** Reuse a previously imported SRT sidecar after a worker crash/retry. */
	private static function existing_imported_srt( int $batch_id, int $project_id ): int {
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_parent'    => $project_id,
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_query'     => [
				[ 'key' => '_worldgraph_gen_batch_id', 'value' => $batch_id ],
				[ 'key' => '_worldgraph_rough_cut_sidecar', 'value' => 1 ],
			],
		] );
		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( $project_id === absint( get_post_meta( $attachment_id, '_worldgraph_generated_from', true ) ) ) {
				return $attachment_id;
			}
		}

		return 0;
	}

	/** Import the final MP4 as a Project-owned Media Library attachment. */
	private static function import_video( string $file, int $project_id, int $batch_id ) {
		$size = is_file( $file ) ? filesize( $file ) : false;
		if ( ! is_readable( $file ) || false === $size || $size <= 0 || $size > self::MAX_OUTPUT_BYTES ) {
			return self::error( 'worldgraph_rough_cut_output_missing', __( 'FFmpeg did not create a readable rough-cut video.', 'worldgraph' ), [ 'status' => 500 ] );
		}
		if ( ! function_exists( 'wp_handle_sideload' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_handle_sideload' ) || ! function_exists( 'wp_insert_attachment' ) ) {
			return self::error( 'worldgraph_rough_cut_upload_unavailable', __( 'WordPress Media Library upload functions are unavailable.', 'worldgraph' ), [ 'status' => 503 ] );
		}

		$project       = get_post( $project_id );
		$slug          = $project instanceof \WP_Post ? sanitize_title( $project->post_name ?: $project->post_title ) : 'project';
		$filename      = sanitize_file_name( ( $slug ?: 'project' ) . '-rough-cut-batch-' . $batch_id . '.mp4' );
		$work_dir      = dirname( $file );
		$sideload_file = $work_dir . DIRECTORY_SEPARATOR . 'rough-cut-sideload.mp4';
		// WordPress moves sideloads. Upload a bounded copy so the signed assembly
		// state retains its final source if import fails before provenance commits.
		if ( ! self::safe_batch_work_dir( $work_dir, $batch_id )
			|| 'rough-cut-final.mp4' !== basename( $file )
			|| ! self::path_is_within( $sideload_file, $work_dir )
			|| false === copy( $file, $sideload_file ) ) {
			return self::error( 'worldgraph_rough_cut_upload_copy_failed', __( 'The assembled rough cut could not be prepared for Media Library import.', 'worldgraph' ), [ 'status' => 500 ] );
		}
		clearstatcache( true, $sideload_file );
		$sideload_size = filesize( $sideload_file );
		if ( false === $sideload_size || (int) $size !== (int) $sideload_size ) {
			wp_delete_file( $sideload_file );
			return self::error( 'worldgraph_rough_cut_upload_copy_failed', __( 'The assembled rough cut could not be prepared for Media Library import.', 'worldgraph' ), [ 'status' => 500 ] );
		}
		$upload = wp_handle_sideload(
			[
				'name'     => $filename,
				'type'     => 'video/mp4',
				'tmp_name' => $sideload_file,
				'error'    => 0,
				'size'     => (int) $size,
			],
			[ 'test_form' => false ]
		);
		if ( is_file( $sideload_file ) ) {
			wp_delete_file( $sideload_file );
		}
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return self::error(
				'worldgraph_rough_cut_upload_failed',
				__( 'The assembled rough cut could not be moved into the Media Library.', 'worldgraph' ),
				[ 'diagnostic' => is_array( $upload ) ? (string) ( $upload['error'] ?? '' ) : 'Invalid WordPress upload response.', 'status' => 500 ]
			);
		}

		$title = sprintf( __( '%s — Rough Cut', 'worldgraph' ), get_the_title( $project_id ) );
		$id    = wp_insert_attachment(
			[
				'post_mime_type' => 'video/mp4',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			(string) $upload['file'],
			$project_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( (string) $upload['file'] );
			return $id;
		}
		$id = (int) $id;
		self::generate_attachment_metadata( $id, (string) $upload['file'] );
		update_post_meta( $id, '_worldgraph_generated_from', $project_id );
		update_post_meta( $id, '_worldgraph_gen_batch_id', $batch_id );
		update_post_meta( $id, '_worldgraph_rough_cut', 1 );
		$attachment    = get_post( $id );
		$attached_file = (string) get_attached_file( $id );
		$url           = (string) wp_get_attachment_url( $id );
		$verified      = $attachment instanceof \WP_Post
			&& 'attachment' === $attachment->post_type
			&& $project_id === (int) $attachment->post_parent
			&& $project_id === absint( get_post_meta( $id, '_worldgraph_generated_from', true ) )
			&& $batch_id === absint( get_post_meta( $id, '_worldgraph_gen_batch_id', true ) )
			&& 1 === absint( get_post_meta( $id, '_worldgraph_rough_cut', true ) )
			&& 0 === strpos( strtolower( (string) get_post_mime_type( $id ) ), 'video/' )
			&& '' !== $url
			&& is_readable( $attached_file );
		if ( ! $verified ) {
			$deleted = wp_delete_attachment( $id, true );
			if ( ! $deleted ) {
				wp_delete_post( $id, true );
				if ( is_file( (string) $upload['file'] ) ) {
					wp_delete_file( (string) $upload['file'] );
				}
			}
			return self::error( 'worldgraph_rough_cut_provenance_failed', __( 'The rough cut was uploaded, but its verified Project and batch provenance could not be stored.', 'worldgraph' ), [ 'status' => 500 ] );
		}

		return [ 'attachment_id' => $id, 'url' => $url ];
	}

	/** Import an SRT fallback without weakening the site's normal video policy. */
	private static function import_srt( string $file, int $project_id, int $batch_id ) {
		if ( ! is_file( $file ) || ! is_readable( $file ) || filesize( $file ) > 1048576 ) {
			return self::error( 'worldgraph_rough_cut_srt_invalid', __( 'The subtitle sidecar is missing or exceeds its safe size limit.', 'worldgraph' ), [ 'status' => 500 ] );
		}
		if ( ! function_exists( 'wp_upload_bits' ) || ! function_exists( 'wp_insert_attachment' ) ) {
			return self::error( 'worldgraph_rough_cut_upload_unavailable', __( 'WordPress could not upload the subtitle sidecar.', 'worldgraph' ), [ 'status' => 503 ] );
		}
		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			return self::error( 'worldgraph_rough_cut_srt_invalid', __( 'The subtitle sidecar could not be read.', 'worldgraph' ), [ 'status' => 500 ] );
		}
		$upload = wp_upload_bits( 'rough-cut-batch-' . $batch_id . '.srt', null, $contents );
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return self::error( 'worldgraph_rough_cut_srt_upload_failed', __( 'The subtitle sidecar could not be stored in the Media Library.', 'worldgraph' ), [ 'diagnostic' => is_array( $upload ) ? (string) ( $upload['error'] ?? '' ) : '', 'status' => 500 ] );
		}
		$id = wp_insert_attachment(
			[
				'post_mime_type' => 'application/x-subrip',
				'post_title'     => sprintf( __( '%s — Rough Cut Subtitles', 'worldgraph' ), get_the_title( $project_id ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			(string) $upload['file'],
			$project_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( (string) $upload['file'] );
			return $id;
		}
		$id = (int) $id;
		update_post_meta( $id, '_worldgraph_generated_from', $project_id );
		update_post_meta( $id, '_worldgraph_gen_batch_id', $batch_id );
		update_post_meta( $id, '_worldgraph_rough_cut_sidecar', 1 );

		return $id;
	}

	/** Generate attachment metadata when the WordPress media helpers are present. */
	private static function generate_attachment_metadata( int $attachment_id, string $file ): void {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( function_exists( 'wp_generate_attachment_metadata' ) && function_exists( 'wp_update_attachment_metadata' ) ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}
	}

	/** Add the final MP4 to the Project gallery and verify the durable link. */
	private static function add_to_project_gallery( int $project_id, int $attachment_id ) {
		$key     = class_exists( Asset_Generator::class ) ? Asset_Generator::GALLERY_META : '_worldgraph_asset_gallery_ids';
		$gallery = array_values( array_unique( array_filter( array_map( 'absint', (array) get_post_meta( $project_id, $key, true ) ) ) ) );
		if ( ! in_array( $attachment_id, $gallery, true ) ) {
			$gallery[] = $attachment_id;
			update_post_meta( $project_id, $key, $gallery );
		}
		$stored = array_values( array_unique( array_filter( array_map( 'absint', (array) get_post_meta( $project_id, $key, true ) ) ) ) );
		if ( ! in_array( $attachment_id, $stored, true ) ) {
			return self::error(
				'worldgraph_rough_cut_gallery_failed',
				__( 'The rough cut was imported, but WordPress could not add it to the Project asset gallery.', 'worldgraph' ),
				[ 'attachment_id' => $attachment_id, 'status' => 500 ]
			);
		}

		return true;
	}

	/** Execute an FFmpeg command with common noninteractive safety flags. */
	private static function run_ffmpeg( string $binary, array $arguments, string $work_dir, string $error_code, string $message ) {
		if ( self::cancellation_requested() ) {
			return self::cancellation_error();
		}
		$command = array_merge( [ $binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y' ], array_values( $arguments ) );
		$result  = self::run_process( $command, $work_dir, self::PROCESS_TIMEOUT );
		if ( ! empty( $result['cancelled'] ) ) {
			return self::cancellation_error();
		}
		if ( 0 !== $result['exit_code'] ) {
			$diagnostic = trim( (string) ( $result['stderr'] ?: $result['stdout'] ) );
			return self::error(
				$error_code,
				$message,
				[
					'exit_code'  => (int) $result['exit_code'],
					'timed_out'  => (bool) $result['timed_out'],
					'diagnostic' => self::bounded_diagnostic( $diagnostic ),
					'status'     => 500,
				]
			);
		}

		return $result;
	}

	/**
	 * Execute one argv vector directly with proc_open; no command string or shell.
	 *
	 * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,cancelled:bool}
	 */
	private static function run_process( array $command, string $work_dir = '', int $timeout = 60 ): array {
		if ( empty( $command ) || ! function_exists( 'proc_open' ) ) {
			return [ 'exit_code' => 127, 'stdout' => '', 'stderr' => 'proc_open is unavailable.', 'timed_out' => false, 'cancelled' => false ];
		}
		foreach ( $command as $argument ) {
			if ( ! is_scalar( $argument ) || preg_match( '/\x00/', (string) $argument ) ) {
				return [ 'exit_code' => 126, 'stdout' => '', 'stderr' => 'The process argument vector is invalid.', 'timed_out' => false, 'cancelled' => false ];
			}
		}
		$command = array_map( 'strval', array_values( $command ) );
		self::touch_assembly_heartbeat();
		$descriptors = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];
		$pipes   = [];
		$process = @proc_open( $command, $descriptors, $pipes, '' !== $work_dir ? $work_dir : null, null, [ 'bypass_shell' => true, 'suppress_errors' => true ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- normalized into diagnostics below.
		if ( ! is_resource( $process ) ) {
			return [ 'exit_code' => 127, 'stdout' => '', 'stderr' => 'proc_open could not start FFmpeg.', 'timed_out' => false, 'cancelled' => false ];
		}
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$stdout    = '';
		$stderr    = '';
		$timed_out = false;
		$cancelled = false;
		$deadline  = microtime( true ) + max( 1, min( self::PROCESS_TIMEOUT, $timeout ) );
		$heartbeat = microtime( true ) + 30;
		$cancel_check = microtime( true ) + 1;
		$exit_code = -1;

		do {
			$stdout .= (string) stream_get_contents( $pipes[1] );
			$stderr .= (string) stream_get_contents( $pipes[2] );
			if ( strlen( $stdout ) > self::MAX_PROCESS_OUTPUT ) {
				$stdout = substr( $stdout, -self::MAX_PROCESS_OUTPUT );
			}
			if ( strlen( $stderr ) > self::MAX_PROCESS_OUTPUT ) {
				$stderr = substr( $stderr, -self::MAX_PROCESS_OUTPUT );
			}
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$exit_code = (int) $status['exitcode'];
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				$timed_out = true;
				proc_terminate( $process );
				usleep( 100000 );
				$status = proc_get_status( $process );
				if ( ! empty( $status['running'] ) ) {
					proc_terminate( $process, 9 );
				}
				break;
			}
			if ( microtime( true ) >= $cancel_check ) {
				$cancel_check = microtime( true ) + 1;
				if ( self::cancellation_requested() ) {
					$cancelled = true;
					proc_terminate( $process );
					usleep( 100000 );
					$status = proc_get_status( $process );
					if ( ! empty( $status['running'] ) ) {
						proc_terminate( $process, 9 );
					}
					break;
				}
			}
			if ( microtime( true ) >= $heartbeat ) {
				self::touch_assembly_heartbeat();
				$heartbeat = microtime( true ) + 30;
			}
			usleep( 20000 );
		} while ( true );

		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$closed = proc_close( $process );
		if ( $exit_code < 0 && is_int( $closed ) ) {
			$exit_code = $closed;
		}
		if ( $timed_out ) {
			$exit_code = 124;
			$stderr   .= "\nFFmpeg exceeded the bounded process timeout.";
		}
		if ( $cancelled ) {
			$exit_code = 130;
			$stderr   .= "\nFFmpeg was stopped because the generation batch was cancelled.";
		}
		self::touch_assembly_heartbeat();

		return [
			'exit_code' => (int) $exit_code,
			'stdout'    => substr( $stdout, -self::MAX_PROCESS_OUTPUT ),
			'stderr'    => substr( $stderr, -self::MAX_PROCESS_OUTPUT ),
			'timed_out' => $timed_out,
			'cancelled' => $cancelled,
		];
	}

	/** Whether the active demonstration batch has a cooperative cancel request. */
	private static function cancellation_requested(): bool {
		return self::$current_batch_id > 0
			&& function_exists( 'get_post_meta' )
			&& (bool) get_post_meta( self::$current_batch_id, '_worldgraph_gen_cancel_requested', true );
	}

	/** Create the distinct cancellation result consumed by the batch coordinator. */
	private static function cancellation_error(): WP_Error {
		return self::error(
			'worldgraph_rough_cut_cancelled',
			__( 'Rough-cut assembly stopped because the generation batch was cancelled.', 'worldgraph' ),
			[ 'batch_id' => self::$current_batch_id, 'status' => 409 ]
		);
	}

	/** Publish liveness so a crashed assembly can recover without duplicating a live FFmpeg run. */
	private static function touch_assembly_heartbeat(): void {
		if ( self::$current_batch_id > 0 && function_exists( 'update_post_meta' ) ) {
			update_post_meta( self::$current_batch_id, '_worldgraph_gen_assembly_heartbeat', time() );
		}
	}

	/** Record only files created inside the verified request work directory. */
	private static function remember_file( string $file, string $work_dir ): void {
		if ( self::path_is_within( $file, $work_dir ) ) {
			self::$known_files[ $file ] = true;
		}
	}

	/** Remove only request-owned known files, then the verified empty directory. */
	private static function cleanup( string $work_dir ): void {
		foreach ( array_keys( self::$known_files ) as $file ) {
			if ( self::path_is_within( $file, $work_dir ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		self::$known_files = [];
		if ( self::safe_work_dir( $work_dir ) && is_dir( $work_dir ) ) {
			$contents = scandir( $work_dir );
			if ( [ '.', '..' ] === $contents ) {
				rmdir( $work_dir );
			}
		}
	}

	/** Confirm a work directory remains a specifically named child of temp. */
	private static function safe_work_dir( string $work_dir ): bool {
		$temp = realpath( get_temp_dir() );
		$real = realpath( $work_dir );
		return $temp && $real && self::path_is_within( $real, $temp ) && 0 === strpos( basename( $real ), 'worldgraph-rough-cut-' );
	}

	/** Lexically confirm a path is beneath an explicit directory boundary. */
	private static function path_is_within( string $path, string $directory ): bool {
		$path      = rtrim( str_replace( '\\', '/', $path ), '/' );
		$directory = rtrim( str_replace( '\\', '/', $directory ), '/' );
		return '' !== $directory && ( $path === $directory || 0 === strpos( $path, $directory . '/' ) );
	}

	/** Format a safe decimal for FFmpeg numeric arguments and filters. */
	private static function decimal( float $value ): string {
		return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' ) ?: '0';
	}

	/** Normalize a Shot duration into a bounded assembly value. */
	private static function bounded_duration( $value ): float {
		$seconds = is_numeric( $value ) ? (float) $value : self::parse_timecode( (string) $value );
		if ( $seconds <= 0 ) {
			$seconds = self::DEFAULT_SHOT_DURATION;
		}

		return min( 600.0, max( 0.25, $seconds ) );
	}

	/** Normalize an optional Sound duration without inventing one. */
	private static function optional_duration( $value ): float {
		$seconds = is_numeric( $value ) ? (float) $value : self::parse_timecode( (string) $value );
		return $seconds > 0 ? min( 3600.0, $seconds ) : 0.0;
	}

	/** Sanitize one subtitle line and apply a deliberate length bound. */
	private static function subtitle_text( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $text );
		$text = trim( (string) preg_replace( '/[\t ]+/', ' ', (string) preg_replace( '/\r\n?|\n{3,}/', "\n", $text ) ) );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, 500 );
		}

		return substr( $text, 0, 500 );
	}

	/** Format seconds as an SRT timestamp. */
	private static function srt_time( float $seconds ): string {
		$milliseconds = max( 0, (int) round( 1000 * $seconds ) );
		$hours        = (int) floor( $milliseconds / 3600000 );
		$milliseconds %= 3600000;
		$minutes      = (int) floor( $milliseconds / 60000 );
		$milliseconds %= 60000;
		$whole_seconds = (int) floor( $milliseconds / 1000 );
		$milliseconds %= 1000;

		return sprintf( '%02d:%02d:%02d,%03d', $hours, $minutes, $whole_seconds, $milliseconds );
	}

	/** Bound process diagnostics returned to administrators and logs. */
	private static function bounded_diagnostic( string $diagnostic ): string {
		$diagnostic = trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $diagnostic ) );
		return substr( $diagnostic, 0, 4000 );
	}

	/** Construct a consistent diagnostic WP_Error. */
	private static function error( string $code, string $message, array $data = [] ): WP_Error {
		if ( ! isset( $data['status'] ) ) {
			$data['status'] = 500;
		}

		return new WP_Error( $code, $message, $data );
	}
}
