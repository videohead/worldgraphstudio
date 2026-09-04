<?php
/**
 * Resumable Story Decomposition Job contracts.
 *
 * @package WorldGraph
 */

namespace WorldGraph\AI {
	if ( ! class_exists( AI_LLM_Client::class, false ) ) {
		/** Provider-free client used only while creating a deterministic test plan. */
		class AI_LLM_Client {
			public function model_context_window( int $connection_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return 0;
			}
		}
	}
}

namespace WorldGraph\Admin {
	if ( ! class_exists( Import::class, false ) ) {
		/** Capture the job's final handoff without invoking the mutating importer. */
		class Import {
			/** @var array<string,mixed> */
			public static array $capture = [];

			public static function store_candidate_preview( int $attachment_id, array $source, string $json, bool $overwrite, array $decomposition = [] ): array {
				self::$capture = compact( 'attachment_id', 'source', 'json', 'overwrite', 'decomposition' );
				return [
					'token'      => 'preview-token-for-resumable-job',
					'url'        => 'https://example.test/wp-admin/admin.php?page=worldgraph-import&preview=preview-token-for-resumable-job',
					'expires_at' => time() + 1800,
				];
			}
		}
	}
}

namespace WorldGraphStoryIO {
	/** Read the controllable current test user. */
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['worldgraph_decomposition_job_user'] ?? 101 );
	}

	/** Grant only the capability used by the private job boundary. */
	function current_user_can( string $capability, ...$args ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return 'manage_options' === $capability && ! empty( $GLOBALS['worldgraph_decomposition_job_can_manage'] );
	}

	/** Store a private checkpoint in memory. */
	function set_transient( string $key, $value, int $expiration ): bool {
		$is_state  = str_starts_with( $key, 'worldgraph_story_decomp_state_' );
		$is_cancel = str_starts_with( $key, 'worldgraph_story_decomp_cancel_' );
		if ( $is_state && ! empty( $GLOBALS['worldgraph_decomposition_job_drop_state_updates'] ) && isset( $GLOBALS['worldgraph_decomposition_job_transients'][ $key ] ) ) {
			return false;
		}
		if ( $is_cancel && ! empty( $GLOBALS['worldgraph_decomposition_job_drop_cancel_writes'] ) ) {
			return false;
		}
		$GLOBALS['worldgraph_decomposition_job_transients'][ $key ] = [
			'value'      => $value,
			'expiration' => $expiration,
		];
		return true;
	}

	/** Read a private checkpoint from memory. */
	function get_transient( string $key ) {
		return $GLOBALS['worldgraph_decomposition_job_transients'][ $key ]['value'] ?? false;
	}

	/** Delete an in-memory checkpoint. */
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['worldgraph_decomposition_job_transients'][ $key ] );
		return true;
	}

	/** Read a test lock option. */
	function get_option( string $key, $default = false ) {
		return $GLOBALS['worldgraph_decomposition_job_options'][ $key ] ?? $default;
	}

	/** Atomically add a test lock option. */
	function add_option( string $key, $value, string $deprecated = '', bool $autoload = true ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( array_key_exists( $key, $GLOBALS['worldgraph_decomposition_job_options'] ) ) {
			return false;
		}
		$GLOBALS['worldgraph_decomposition_job_options'][ $key ] = $value;
		return true;
	}

	/** Release a test lock option. */
	function delete_option( string $key ): bool {
		unset( $GLOBALS['worldgraph_decomposition_job_options'][ $key ] );
		return true;
	}

	/** Capture terminal attachment workflow markers. */
	function update_post_meta( int $post_id, string $key, $value ): bool {
		$GLOBALS['worldgraph_decomposition_job_meta'][ $post_id ][ $key ] = $value;
		return true;
	}

	function wp_salt( string $scheme = 'auth' ): string {
		return 'worldgraph-job-test-salt-' . $scheme;
	}

	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}

	function sanitize_text_field( $value ): string {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
	}

	function sanitize_file_name( $value ): string {
		return basename( (string) $value );
	}

	function sanitize_key( $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}

	function absint( $value ): int {
		return abs( (int) $value );
	}

	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function is_wp_error( $value ): bool {
		return $value instanceof \WP_Error;
	}

	function __( $text, $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return (string) $text;
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use WorldGraphStoryIO\Decomposition_Job;
	use WorldGraphStoryIO\Story_Decomposer;

	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'WORLDGRAPH_STORY_IO_PLUGIN_DIR' ) ) {
		define( 'WORLDGRAPH_STORY_IO_PLUGIN_DIR', dirname( __DIR__ ) . '/plugins/story-import-export/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		/** Minimal WordPress error value for focused job tests. */
		class WP_Error {
			private string $code;
			private string $message;
			private $data;

			public function __construct( string $code = '', string $message = '', $data = null ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data() {
				return $this->data;
			}
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool {
			return $value instanceof WP_Error;
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, int $flags = 0 ) {
			return json_encode( $value, $flags );
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $value ): string {
			return \WorldGraphStoryIO\sanitize_key( $value );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string {
			return \WorldGraphStoryIO\sanitize_text_field( $value );
		}
	}
	if ( ! function_exists( 'sanitize_file_name' ) ) {
		function sanitize_file_name( $value ): string {
			return \WorldGraphStoryIO\sanitize_file_name( $value );
		}
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ): int {
			return \WorldGraphStoryIO\absint( $value );
		}
	}

	require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-story-chunker.php';
	require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-story-decomposer.php';
	require_once WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-decomposition-job.php';

	/** Deterministic, stateful fake for measuring exactly which bounded stage ran. */
	class Story_Decomposition_Job_Decomposer_Fake extends Story_Decomposer {
		public int $analysis_calls = 0;
		public int $synthesis_calls = 0;
		public int $finalize_calls = 0;
		public int $retrieval_calls = 0;
		public int $subdivision_calls = 0;
		public bool $fail_first_analysis = false;
		public bool $fail_terminal_analysis = false;
		/** @var array<int,string> */
		public array $successful_analysis_texts = [];
		/** @var array<int,array<int,string>> */
		public array $accepted_scene_ids = [];
		public string $subdivision_source = '';

		public function __construct( bool $fail_first_analysis = false ) {
			$this->fail_first_analysis = $fail_first_analysis;
		}

		public function analyze_planned_chunk( array $chunk, int $ordinal, int $total, string $filename, int $connection_id, array $profile = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			++$this->analysis_calls;
			if ( $this->fail_first_analysis && 1 === $this->analysis_calls ) {
				return new WP_Error(
					'worldgraph_story_decompose_truncated',
					'The bounded response was truncated.',
					[ 'retryable' => true, 'attempts' => 1, 'tokens' => 3 ]
				);
			}
			if ( $this->fail_terminal_analysis ) {
				return new WP_Error( 'worldgraph_story_analysis_rejected', 'The bounded analysis was rejected.' );
			}

			$this->successful_analysis_texts[] = (string) $chunk['text'];
			return [
				'evidence' => [
					'scenes' => [ [ 'id' => 'private-evidence-' . $ordinal, 'summary' => 'PRIVATE EVIDENCE ' . $ordinal ] ],
					'_retrieval' => [ 'chunk_id' => (string) $chunk['id'] ],
				],
				'attempts' => 1,
				'tokens'   => 10,
				'backend'  => 'fixture',
				'model'    => 'checkpoint-model',
			];
		}

		public function retrieve_planned_evidence( array $chunk, array $evidence, int $current_index ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			++$this->retrieval_calls;
			return [
				'backend' => 'test_vector',
				'current' => $evidence[ $current_index ],
				'related' => [ $evidence[ ( $current_index + 1 ) % count( $evidence ) ] ],
			];
		}

		public function synthesize_planned_chunk( array $chunk, array $evidence, array $accepted_documents, int $ordinal, int $total, string $filename, int $connection_id, array $profile = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			++$this->synthesis_calls;
			$this->accepted_scene_ids[] = array_values(
				array_filter(
					array_map(
						static fn( array $document ): string => (string) ( $document['scenes'][0]['id'] ?? '' ),
						$accepted_documents
					)
				)
			);
			return [
				'document' => [
					'project' => [ 'id' => 'checkpoint-project', 'title' => 'Checkpoint Story' ],
					'world'   => [ 'id' => 'checkpoint-world', 'name' => 'Checkpoint World' ],
					'scenes'  => [ [ 'id' => 'scene-' . $ordinal, 'title' => 'Scene ' . $ordinal ] ],
				],
				'attempts' => 1,
				'tokens'   => 20,
				'backend'  => 'fixture',
				'model'    => 'checkpoint-model',
			];
		}

		public function finalize_planned_documents( array $documents, string $source_text, string $filename, string $source_title, int $connection_id, array $metrics = [], array $plan = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			++$this->finalize_calls;
			return [
				'json'           => wp_json_encode( [
					'version' => '1.2',
					'project' => [ 'id' => 'checkpoint-project', 'title' => 'Checkpoint Story' ],
					'world'   => [ 'id' => 'checkpoint-world', 'name' => 'Checkpoint World' ],
					'scenes'  => array_merge( ...array_column( $documents, 'scenes' ) ),
				] ),
				'metrics'        => [
					'attempts' => (int) $metrics['attempts'],
					'tokens'   => (int) $metrics['tokens'],
					'backend'  => 'fixture',
					'model'    => 'checkpoint-model',
				],
				'passes'         => 2,
				'sections'       => [ 'CHAPTER ONE', 'CHAPTER TWO' ],
				'context_window' => 4096,
			];
		}

		public function subdivide_planned_chunk( array $chunk, array $profile = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			++$this->subdivision_calls;
			$this->subdivision_source = (string) $chunk['text'];
			$middle = intdiv( mb_strlen( $this->subdivision_source, 'UTF-8' ), 2 );
			return [
				[ 'id' => 'adaptive-a', 'text' => mb_substr( $this->subdivision_source, 0, $middle, 'UTF-8' ) ],
				[ 'id' => 'adaptive-b', 'text' => mb_substr( $this->subdivision_source, $middle, null, 'UTF-8' ) ],
			];
		}
	}

	/**
	 * Verify private, resumable decomposition checkpoints.
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	class Test_Story_Resumable_Decomposition_Job extends TestCase {
		protected function setUp(): void {
			$GLOBALS['worldgraph_decomposition_job_user']       = 101;
			$GLOBALS['worldgraph_decomposition_job_can_manage'] = true;
			$GLOBALS['worldgraph_decomposition_job_transients'] = [];
			$GLOBALS['worldgraph_decomposition_job_options']    = [];
			$GLOBALS['worldgraph_decomposition_job_meta']       = [];
			$GLOBALS['worldgraph_decomposition_job_drop_state_updates'] = false;
			$GLOBALS['worldgraph_decomposition_job_drop_cancel_writes'] = false;
			\WorldGraph\Admin\Import::$capture                 = [];
		}

		public function test_create_returns_only_a_safe_user_scoped_status_projection(): void {
			$manuscript = $this->two_chunk_story( 'PRIVATE MANUSCRIPT TOKEN' );
			$status     = $this->create_job( $manuscript );

			$this->assertFalse( is_wp_error( $status ) );
			$this->assertSame( 'ready', $status['status'] );
			$this->assertSame( 'analysis', $status['stage'] );
			$this->assertGreaterThanOrEqual( 2, $status['analysis']['total'] );
			$this->assertTrue( $status['can_step'] );
			$this->assertArrayNotHasKey( 'plan', $status );
			$this->assertArrayNotHasKey( 'analysis_results', $status );
			$this->assertStringNotContainsString( 'PRIVATE MANUSCRIPT TOKEN', wp_json_encode( $status ) );
			$this->assertGreaterThan( 5 * 24 * 60 * 60, $status['expires_at'] - time() );

			$state = $this->job_state( (string) $status['job_id'] );
			$this->assertStringNotContainsString( 'PRIVATE MANUSCRIPT TOKEN', serialize( $state ) );
			$this->assertArrayNotHasKey( 'text', $state['plan']['chunks'][0] );
			$this->assertArrayNotHasKey( 'text', $state['plan']['chunks'][0]['context_after'] );
			$this->assertCount( $status['analysis']['total'], $this->shard_keys() );

			$decomposer = new Story_Decomposition_Job_Decomposer_Fake();
			$checkpoint = Decomposition_Job::step( $status['job_id'], $decomposer );
			$this->assertFalse( is_wp_error( $checkpoint ) );
			$this->assertStringNotContainsString( 'PRIVATE EVIDENCE', wp_json_encode( $checkpoint ) );
			$this->assertStringNotContainsString( 'PRIVATE EVIDENCE', serialize( $this->job_state( (string) $status['job_id'] ) ) );
		}

		public function test_each_step_runs_exactly_one_stage_and_hands_metadata_to_preview(): void {
			$status     = $this->create_job( $this->two_chunk_story() );
			$job_id     = (string) $status['job_id'];
			$decomposer = new Story_Decomposition_Job_Decomposer_Fake();
			$total      = (int) $status['analysis']['total'];

			for ( $completed = 1; $completed <= $total; $completed++ ) {
				$status = Decomposition_Job::step( $job_id, $decomposer );
				$this->assertSame( [ $completed, 0, 0 ], [ $decomposer->analysis_calls, $decomposer->synthesis_calls, $decomposer->finalize_calls ] );
				$this->assertSame( [ 'completed' => $completed, 'total' => $total ], $status['analysis'] );
				$this->assertSame( $completed === $total ? 'synthesis' : 'analysis', $status['stage'] );
			}

			for ( $completed = 1; $completed <= $total; $completed++ ) {
				$status = Decomposition_Job::step( $job_id, $decomposer );
				$this->assertSame( [ $total, $completed, 0 ], [ $decomposer->analysis_calls, $decomposer->synthesis_calls, $decomposer->finalize_calls ] );
				$this->assertSame( [ 'completed' => $completed, 'total' => $total ], $status['synthesis'] );
				$this->assertSame( $completed === $total ? 'finalize' : 'synthesis', $status['stage'] );
			}

			$status = Decomposition_Job::step( $job_id, $decomposer );
			$this->assertSame( [ $total, $total, 1 ], [ $decomposer->analysis_calls, $decomposer->synthesis_calls, $decomposer->finalize_calls ] );
			$this->assertSame( 'complete', $status['status'] );
			$this->assertSame( 100, $status['progress']['percent'] );
			$this->assertFalse( $status['can_step'] );
			$this->assertStringContainsString( 'preview-token-for-resumable-job', $status['preview_url'] );
			$this->assertSame( $status['preview_expires_at'], $status['expires_at'] );
			$this->assertSame( [], $this->shard_keys() );
			$this->assertSame( [], $decomposer->accepted_scene_ids[0] );
			$this->assertSame( [ 'scene-1' ], $decomposer->accepted_scene_ids[1] );
			$completed_state = $this->job_state( $job_id );
			$this->assertArrayNotHasKey( 'chunks', $completed_state['plan'] );
			$this->assertCount( $total * 3, $completed_state['cleanup_shards'] );
			$this->assertSame( [ 'analysis', 'chunk', 'document' ], $this->cleanup_kinds( $completed_state ) );

			$handoff = \WorldGraph\Admin\Import::$capture;
			$this->assertSame( 2, $handoff['decomposition']['passes'] );
			$this->assertSame( [ 'CHAPTER ONE', 'CHAPTER TWO' ], $handoff['decomposition']['sections'] );
			$this->assertSame( 4096, $handoff['decomposition']['context_window'] );
			$this->assertSame( [ 'backend' => 'test_vector', 'indexed' => $total, 'retrieved' => $total ], $handoff['decomposition']['retrieval'] );
			$this->assertSame( $total * 2, $handoff['decomposition']['attempts'] );
			$this->assertSame( $total * 30, $handoff['decomposition']['tokens'] );
		}

		public function test_jobs_are_isolated_by_user_and_can_be_cancelled(): void {
			$status = $this->create_job( $this->two_chunk_story() );
			$job_id = (string) $status['job_id'];

			$GLOBALS['worldgraph_decomposition_job_user'] = 202;
			$foreign = Decomposition_Job::status( $job_id );
			$this->assertTrue( is_wp_error( $foreign ) );
			$this->assertSame( 'worldgraph_story_decomposition_not_found', $foreign->get_error_code() );

			$GLOBALS['worldgraph_decomposition_job_user'] = 101;
			$cancelled = Decomposition_Job::cancel( $job_id );
			$this->assertSame( 'cancelled', $cancelled['status'] );
			$this->assertFalse( $cancelled['can_step'] );
			$this->assertFalse( $cancelled['can_cancel'] );

			$decomposer = new Story_Decomposition_Job_Decomposer_Fake();
			$after      = Decomposition_Job::step( $job_id, $decomposer );
			$this->assertSame( 'cancelled', $after['status'] );
			$this->assertSame( [ 0, 0, 0 ], [ $decomposer->analysis_calls, $decomposer->synthesis_calls, $decomposer->finalize_calls ] );
			$this->assertSame( 'preview_cancelled', $GLOBALS['worldgraph_decomposition_job_meta'][91]['_worldgraph_story_import_status'] );
			$this->assertSame( [], $this->shard_keys() );
			$cancelled_state = $this->job_state( $job_id );
			$this->assertArrayNotHasKey( 'chunks', $cancelled_state['plan'] );
			$this->assertCount( $status['analysis']['total'], $cancelled_state['cleanup_shards'] );
			$this->assertSame( [ 'chunk' ], $this->cleanup_kinds( $cancelled_state ) );
		}

		public function test_retryable_failure_subdivides_with_exact_source_coverage_before_retry(): void {
			$source     = "CHAPTER ONE\n\n" . str_repeat( 'Ada crossed the quiet bridge. ', 35 );
			$status     = $this->create_job( $source );
			$job_id     = (string) $status['job_id'];
			$decomposer = new Story_Decomposition_Job_Decomposer_Fake( true );

			$status = Decomposition_Job::step( $job_id, $decomposer );
			$this->assertSame( 1, $decomposer->analysis_calls );
			$this->assertSame( 1, $decomposer->subdivision_calls );
			$this->assertSame( [ 'completed' => 0, 'total' => 2 ], $status['analysis'] );

			$status = Decomposition_Job::step( $job_id, $decomposer );
			$this->assertSame( [ 'completed' => 1, 'total' => 2 ], $status['analysis'] );
			$status = Decomposition_Job::step( $job_id, $decomposer );
			$this->assertSame( [ 'completed' => 2, 'total' => 2 ], $status['analysis'], wp_json_encode( $status ) );
			$this->assertSame( 'synthesis', $status['stage'] );
			$this->assertSame( $decomposer->subdivision_source, implode( '', $decomposer->successful_analysis_texts ) );
		}

		public function test_rejected_core_update_is_not_reported_as_a_checkpoint(): void {
			$status = $this->create_job( $this->two_chunk_story() );
			$GLOBALS['worldgraph_decomposition_job_drop_state_updates'] = true;
			$decomposer = new Story_Decomposition_Job_Decomposer_Fake();

			$result = Decomposition_Job::step( (string) $status['job_id'], $decomposer );

			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'worldgraph_story_decomposition_checkpoint_failed', $result->get_error_code() );
			$this->assertSame( 0, $decomposer->analysis_calls );
			$GLOBALS['worldgraph_decomposition_job_drop_state_updates'] = false;
			$this->assertSame( 'ready', Decomposition_Job::status( (string) $status['job_id'] )['status'] );
		}

		public function test_rejected_cancel_flag_write_does_not_claim_cancellation(): void {
			$status = $this->create_job( $this->two_chunk_story() );
			$GLOBALS['worldgraph_decomposition_job_drop_cancel_writes'] = true;

			$result = Decomposition_Job::cancel( (string) $status['job_id'] );

			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'worldgraph_story_decomposition_cancel_checkpoint_failed', $result->get_error_code() );
			$this->assertSame( 'ready', Decomposition_Job::status( (string) $status['job_id'] )['status'] );
		}

		public function test_reissued_cancel_commits_after_an_active_lock_is_released(): void {
			$status   = $this->create_job( $this->two_chunk_story() );
			$job_id   = (string) $status['job_id'];
			$lock_key = 'worldgraph_story_decomp_lock_' . substr( hash( 'sha256', '101:' . $job_id ), 0, 40 );
			$GLOBALS['worldgraph_decomposition_job_options'][ $lock_key ] = [
				'owner'       => 'active-worker',
				'acquired_at' => time(),
			];

			$waiting = Decomposition_Job::cancel( $job_id );
			$this->assertSame( 'cancelling', $waiting['status'] );
			unset( $GLOBALS['worldgraph_decomposition_job_options'][ $lock_key ] );

			$cancelled = Decomposition_Job::cancel( $job_id );
			$this->assertSame( 'cancelled', $cancelled['status'] );
			$this->assertSame( [], $this->shard_keys() );
		}

		public function test_terminal_failure_clears_every_private_payload_shard(): void {
			$status = $this->create_job( $this->two_chunk_story() );
			$decomposer = new Story_Decomposition_Job_Decomposer_Fake();
			$decomposer->fail_terminal_analysis = true;

			$failed = Decomposition_Job::step( (string) $status['job_id'], $decomposer );

			$this->assertSame( 'failed', $failed['status'] );
			$this->assertSame( [], $this->shard_keys() );
			$state = $this->job_state( (string) $status['job_id'] );
			$this->assertArrayNotHasKey( 'analysis_results', $state );
			$this->assertArrayNotHasKey( 'documents', $state );
			$this->assertArrayNotHasKey( 'chunks', $state['plan'] );
		}

		public function test_cancellation_poll_reissues_delete_and_lock_uses_owned_prefix(): void {
			$javascript = (string) file_get_contents( WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'assets/import.js' );
			$job_source = (string) file_get_contents( WORLDGRAPH_STORY_IO_PLUGIN_DIR . 'includes/class-decomposition-job.php' );

			$this->assertMatchesRegularExpression( '/function pollCancellation\(\).*?request\(\x27DELETE\x27\)/s', $javascript );
			$this->assertStringContainsString( "'worldgraph_story_decomp_lock_'", $job_source );
			$this->assertStringNotContainsString( "'wgs_story_decomp_", $job_source );
			$this->assertStringContainsString( 'register_shutdown_function', $job_source );
			$this->assertMatchesRegularExpression( '/function setTerminalError\(text\).*?restartLink\.hidden = false/s', $javascript );
			$this->assertStringContainsString( "typeof payload.job === 'object'", $javascript );
			$this->assertStringContainsString( "validStatuses.indexOf(job.status) === -1", $javascript );
		}

		/** Create a private job with the common persisted-source shape. */
		private function create_job( string $text ) {
			return Decomposition_Job::create(
				[
					'text'       => $text,
					'filename'   => 'checkpoint-story.txt',
					'format'     => 'txt',
					'characters' => mb_strlen( $text, 'UTF-8' ),
					'boundaries' => [],
				],
				91,
				37,
				false,
				'Fixture Connection'
			);
		}

		/** Return the core state record without reading any payload shard. */
		private function job_state( string $job_id ): array {
			foreach ( $GLOBALS['worldgraph_decomposition_job_transients'] as $key => $record ) {
				$value = is_array( $record ) ? ( $record['value'] ?? null ) : null;
				if ( str_starts_with( $key, 'worldgraph_story_decomp_state_' ) && is_array( $value ) && $job_id === (string) ( $value['job_id'] ?? '' ) ) {
					return $value;
				}
			}
			$this->fail( 'The expected decomposition job state was not stored.' );
		}

		/** Return all currently retained private payload shard keys. */
		private function shard_keys(): array {
			return array_values(
				array_filter(
					array_keys( $GLOBALS['worldgraph_decomposition_job_transients'] ),
					static fn( string $key ): bool => str_starts_with( $key, 'worldgraph_story_decomp_shard_' )
				)
			);
		}

		/** Return the sorted private shard kinds durably queued before deletion. */
		private function cleanup_kinds( array $state ): array {
			$kinds = array_values(
				array_unique(
					array_map(
						static fn( array $reference ): string => (string) ( $reference['kind'] ?? '' ),
						array_values( (array) ( $state['cleanup_shards'] ?? [] ) )
					)
				)
			);
			sort( $kinds );
			return $kinds;
		}

		/** Produce two chapter-sized semantic chunks under the unknown-model profile. */
		private function two_chunk_story( string $marker = 'The lantern stayed lit' ): string {
			return "CHAPTER ONE\n\n"
				. str_repeat( $marker . '. The traveler crossed the quiet bridge. ', 42 )
				. "\n\nCHAPTER TWO\n\n"
				. str_repeat( 'At sunrise the traveler returned safely. ', 58 );
		}
	}
}
