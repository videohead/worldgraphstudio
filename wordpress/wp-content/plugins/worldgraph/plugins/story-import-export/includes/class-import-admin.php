<?php
/**
 * Secure, review-first story import administration.
 *
 * @package WorldGraphStoryIO
 */

namespace WorldGraph\Admin;

defined( 'ABSPATH' ) || exit;

/** Upload, preview, and explicitly confirm a story import. */
class Import {
	private const PREVIEW_TTL = 30 * MINUTE_IN_SECONDS;
	private const REPORT_TTL  = 5 * MINUTE_IN_SECONDS;
	private const LOCK_TTL    = 5 * MINUTE_IN_SECONDS;
	private const FILE_TYPES  = [ 'json', 'txt', 'text', 'md', 'markdown', 'fountain', 'rtf', 'pdf', 'epub', 'docx', 'odt' ];
	private const LLM_TYPES   = [ 'openai_compatible', 'openai', 'anthropic' ];

	/** Register the legacy menu and form action. */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'admin_post_worldgraph_import_json', [ __CLASS__, 'handle_import' ] );
	}

	/** Add the Import submenu page. */
	public static function add_menu(): void {
		add_submenu_page(
			'worldgraph-plugins',
			__( 'Import', 'worldgraph' ),
			__( 'Import', 'worldgraph' ),
			'manage_options',
			'worldgraph-import',
			[ __CLASS__, 'render_page' ]
		);
	}

	/** Enqueue the feature plugin's import UI. */
	public static function enqueue_scripts( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_worldgraph-import' ) ) {
			return;
		}

		wp_enqueue_script(
			'worldgraph-import',
			WORLDGRAPH_STORY_IO_PLUGIN_URL . 'assets/import.js',
			[],
			WORLDGRAPH_STORY_IO_VERSION,
			true
		);
		wp_localize_script(
			'worldgraph-import',
			'worldgraphStoryImport',
			[
				'maxUploadBytes'   => \WorldGraphStoryIO\Source_Extractor::MAX_UPLOAD_BYTES,
				'allowedTypes'     => self::FILE_TYPES,
				'chooseFile'       => __( 'Choose a supported story file.', 'worldgraph' ),
				'unsupportedFile'  => __( 'Choose a JSON, text, Markdown, Fountain, RTF, PDF, EPUB, DOCX, or ODT file.', 'worldgraph' ),
				'fileTooLarge'     => __( 'Story source files may not exceed 20 MB.', 'worldgraph' ),
				'chooseConnection' => __( 'Select an LLM Connection for non-canonical story files.', 'worldgraph' ),
				'previewing'       => __( 'Uploading and preparing preview…', 'worldgraph' ),
				'confirmImport'    => __( 'Import the reviewed candidate now? This can create or update WordPress content.', 'worldgraph' ),
				'confirmRequired'  => __( 'Check the confirmation box before importing.', 'worldgraph' ),
				'importing'        => __( 'Importing reviewed candidate…', 'worldgraph' ),
			]
		);
	}

	/** Route submissions through preview, confirmation, or cancellation. */
	public static function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to import World Graph Studio data.', 'worldgraph' ),
				'',
				[ 'response' => 403 ]
			);
		}
		check_admin_referer( 'worldgraph_import' );

		$stage = isset( $_POST['worldgraph_import_stage'] )
			? sanitize_key( wp_unslash( $_POST['worldgraph_import_stage'] ) )
			: 'preview';

		if ( 'confirm' === $stage ) {
			self::confirm_preview();
		}
		if ( 'cancel' === $stage ) {
			self::cancel_preview();
		}
		if ( 'preview' !== $stage ) {
			self::redirect_with_notice( 'error', __( 'Unknown story import workflow stage.', 'worldgraph' ) );
		}

		self::create_preview();
	}

	/** Persist a source, produce canonical JSON, validate it, and store a preview. */
	private static function create_preview(): void {
		$overwrite     = ! empty( $_POST['worldgraph_overwrite'] );
		$attachment_id = self::persist_source();
		if ( is_wp_error( $attachment_id ) ) {
			self::redirect_with_notice( 'error', self::safe_error_message( $attachment_id ) );
		}

		$source = ( new \WorldGraphStoryIO\Source_Extractor() )->extract_attachment( $attachment_id );
		if ( is_wp_error( $source ) ) {
			self::mark_attachment( $attachment_id, 'preview_failed' );
			self::redirect_with_notice( 'error', self::safe_error_message( $source ), $attachment_id );
		}

		$connection_id = isset( $_POST['worldgraph_connection_id'] )
			? absint( wp_unslash( $_POST['worldgraph_connection_id'] ) )
			: 0;
		$decomposition = [
			'generated'     => false,
			'attempts'      => 0,
			'tokens'        => 0,
			'chunks'        => 0,
			'connection_id' => 0,
		];

		if ( ! empty( $source['is_json'] ) ) {
			$json = (string) $source['text'];
		} else {
			$connection = self::validate_connection( $connection_id );
			if ( is_wp_error( $connection ) ) {
				self::mark_attachment( $attachment_id, 'preview_failed' );
				self::redirect_with_notice( 'error', self::safe_error_message( $connection ), $attachment_id );
			}

			$result = ( new \WorldGraphStoryIO\Story_Decomposer() )->decompose(
				(string) $source['text'],
				(string) $source['filename'],
				$connection_id
			);
			if ( is_wp_error( $result ) ) {
				self::mark_attachment( $attachment_id, 'preview_failed' );
				self::redirect_with_notice( 'error', self::safe_error_message( $result ), $attachment_id );
			}

			$json          = (string) $result['json'];
			$decomposition = [
				'generated'       => true,
				'attempts'        => absint( $result['attempts'] ?? 0 ),
				'tokens'          => absint( $result['tokens'] ?? 0 ),
				'chunks'          => max( 1, absint( $result['chunks'] ?? 1 ) ),
				'backend'         => sanitize_key( (string) ( $result['backend'] ?? '' ) ),
				'model'           => sanitize_text_field( (string) ( $result['model'] ?? '' ) ),
				'connection_id'   => $connection_id,
				'connection_name' => self::connection_name( $connection ),
			];
		}

		$validation = ( new \WorldGraph\Importer\WorldGraph_Importer() )->import(
			$json,
			[
				'dry_run'  => true,
				'overwrite' => $overwrite,
			]
		);
		if ( is_wp_error( $validation ) ) {
			self::mark_attachment( $attachment_id, 'preview_failed' );
			self::redirect_with_notice( 'error', self::safe_error_message( $validation ), $attachment_id );
		}

		$document = json_decode( $json, true );
		if ( ! is_array( $document ) ) {
			self::mark_attachment( $attachment_id, 'preview_failed' );
			self::redirect_with_notice( 'error', __( 'The canonical candidate could not be decoded.', 'worldgraph' ), $attachment_id );
		}
		$json = wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			self::mark_attachment( $attachment_id, 'preview_failed' );
			self::redirect_with_notice( 'error', __( 'The canonical candidate could not be encoded.', 'worldgraph' ), $attachment_id );
		}

		$token   = self::new_token();
		$created = time();
		$preview = [
			'user_id'       => get_current_user_id(),
			'created_at'    => $created,
			'expires_at'    => $created + self::PREVIEW_TTL,
			'attachment_id' => $attachment_id,
			'source'        => [
				'filename'   => sanitize_file_name( (string) ( $source['filename'] ?? '' ) ),
				'format'     => sanitize_key( (string) ( $source['format'] ?? '' ) ),
				'characters' => absint( $source['characters'] ?? 0 ),
			],
			'overwrite'     => $overwrite,
			'decomposition' => $decomposition,
			'summary'       => self::document_summary( $document ),
			'json'          => $json,
			'json_hash'     => hash( 'sha256', $json ),
		];

		if ( ! set_transient( self::preview_key( $token ), $preview, self::PREVIEW_TTL ) ) {
			self::mark_attachment( $attachment_id, 'preview_failed' );
			self::redirect_with_notice( 'error', __( 'The import preview could not be stored. No project was imported.', 'worldgraph' ), $attachment_id );
		}

		self::mark_attachment( $attachment_id, 'awaiting_confirmation', $token );
		self::redirect( [ 'preview' => $token ] );
	}

	/** Revalidate and consume a preview, then perform the only mutating import. */
	private static function confirm_preview(): void {
		$token   = self::posted_token();
		$preview = self::get_preview( $token );
		if ( is_wp_error( $preview ) ) {
			self::redirect_with_notice( 'error', self::safe_error_message( $preview ) );
		}
		$confirmed = isset( $_POST['worldgraph_confirm_import'] )
			? sanitize_text_field( wp_unslash( $_POST['worldgraph_confirm_import'] ) )
			: '';
		if ( '1' !== $confirmed ) {
			self::redirect_with_notice( 'error', __( 'Review the candidate and check the confirmation box before importing.', 'worldgraph' ), 0, $token );
		}

		$json = (string) ( $preview['json'] ?? '' );
		if ( empty( $preview['json_hash'] ) || ! hash_equals( (string) $preview['json_hash'], hash( 'sha256', $json ) ) ) {
			delete_transient( self::preview_key( $token ) );
			self::redirect_with_notice( 'error', __( 'The stored preview failed its integrity check. Upload the source again.', 'worldgraph' ) );
		}

		$validation = ( new \WorldGraph\Importer\WorldGraph_Importer() )->import(
			$json,
			[
				'dry_run'  => true,
				'overwrite' => ! empty( $preview['overwrite'] ),
			]
		);
		if ( is_wp_error( $validation ) ) {
			self::redirect_with_notice( 'error', self::safe_error_message( $validation ), 0, $token );
		}

		$lock = self::acquire_lock( $token );
		if ( is_wp_error( $lock ) ) {
			self::redirect_with_notice( 'error', self::safe_error_message( $lock ) );
		}

		delete_transient( self::preview_key( $token ) );
		$result = null;
		try {
			$result = ( new \WorldGraph\Importer\WorldGraph_Importer() )->import(
				$json,
				[ 'overwrite' => ! empty( $preview['overwrite'] ) ]
			);
		} finally {
			delete_option( $lock );
		}

		$attachment_id = absint( $preview['attachment_id'] ?? 0 );
		if ( is_wp_error( $result ) ) {
			self::mark_attachment( $attachment_id, 'import_failed' );
			self::redirect_with_notice( 'error', self::safe_error_message( $result ), $attachment_id );
		}

		$verified = ! empty( $result['verified'] ) && empty( $result['errors'] );
		self::finish_attachment( $attachment_id, $json, $result, $verified );
		$report_token = self::new_token();
		$report_stored = set_transient(
			self::report_key( $report_token ),
			[
				'user_id'       => get_current_user_id(),
				'verified'      => $verified,
				'report'        => $result,
				'source'        => $preview['source'] ?? [],
				'attachment_id' => $attachment_id,
				'decomposition' => $preview['decomposition'] ?? [],
			],
			self::REPORT_TTL
		);
		if ( ! $report_stored ) {
			$message = $verified
				? __( 'The import completed and verified, but its detailed report could not be stored.', 'worldgraph' )
				: __( 'The import ran with verification errors, and its detailed report could not be stored.', 'worldgraph' );
			self::redirect_with_notice( $verified ? 'success' : 'error', $message, $attachment_id );
		}

		self::redirect( [ 'report' => $report_token ] );
	}

	/** Discard a candidate without deleting its Media Library source. */
	private static function cancel_preview(): void {
		$token   = self::posted_token();
		$preview = self::get_preview( $token );
		if ( is_wp_error( $preview ) ) {
			self::redirect_with_notice( 'error', self::safe_error_message( $preview ) );
		}

		delete_transient( self::preview_key( $token ) );
		$attachment_id = absint( $preview['attachment_id'] ?? 0 );
		self::mark_attachment( $attachment_id, 'preview_cancelled' );
		self::redirect_with_notice( 'info', __( 'Preview cancelled. The uploaded source remains in the Media Library.', 'worldgraph' ), $attachment_id );
	}

	/** Persist the new upload field, its legacy alias, or legacy inline JSON. */
	private static function persist_source() {
		$field = '';
		foreach ( [ 'worldgraph_story_file', 'worldgraph_json_file' ] as $candidate ) {
			if ( isset( $_FILES[ $candidate ] ) && UPLOAD_ERR_NO_FILE !== (int) ( $_FILES[ $candidate ]['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				$field = $candidate;
				break;
			}
		}

		if ( '' === $field ) {
			$legacy_json = isset( $_POST['worldgraph_json'] )
				? trim( (string) wp_unslash( $_POST['worldgraph_json'] ) )
				: '';
			if ( '' === $legacy_json ) {
				return new \WP_Error( 'worldgraph_story_upload_missing', __( 'Choose a story source file to preview.', 'worldgraph' ) );
			}
			return self::persist_inline_json( $legacy_json );
		}

		$error = (int) ( $_FILES[ $field ]['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			return new \WP_Error( 'worldgraph_story_upload_failed', self::upload_error_message( $error ) );
		}
		$size = (int) ( $_FILES[ $field ]['size'] ?? 0 );
		if ( $size <= 0 ) {
			return new \WP_Error( 'worldgraph_story_upload_empty', __( 'The selected story source file is empty.', 'worldgraph' ) );
		}
		if ( $size > \WorldGraphStoryIO\Source_Extractor::MAX_UPLOAD_BYTES ) {
			return new \WP_Error( 'worldgraph_story_upload_too_large', __( 'Story source files may not exceed 20 MB.', 'worldgraph' ) );
		}

		$name      = sanitize_file_name( (string) ( $_FILES[ $field ]['name'] ?? '' ) );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::FILE_TYPES, true ) ) {
			return new \WP_Error( 'worldgraph_story_upload_type', __( 'Choose a JSON, text, Markdown, Fountain, RTF, PDF, EPUB, DOCX, or ODT file.', 'worldgraph' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = media_handle_upload(
			$field,
			0,
			[
				'post_title'   => pathinfo( $name, PATHINFO_FILENAME ),
				'post_content' => '',
				'post_status'  => 'inherit',
			],
			[ 'test_form' => false ]
		);
		if ( is_wp_error( $attachment_id ) ) {
			return new \WP_Error( 'worldgraph_story_media_upload_failed', sanitize_text_field( $attachment_id->get_error_message() ) );
		}

		update_post_meta( $attachment_id, '_worldgraph_story_source_owner', get_current_user_id() );
		return (int) $attachment_id;
	}

	/** Preserve legacy inline JSON by turning it into a normal attachment. */
	private static function persist_inline_json( string $json ) {
		if ( strlen( $json ) > \WorldGraphStoryIO\Source_Extractor::MAX_UPLOAD_BYTES ) {
			return new \WP_Error( 'worldgraph_story_upload_too_large', __( 'Story source files may not exceed 20 MB.', 'worldgraph' ) );
		}

		$filename = 'worldgraph-import-' . gmdate( 'Ymd-His' ) . '.json';
		$upload   = wp_upload_bits( $filename, null, $json );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'worldgraph_story_media_upload_failed', sanitize_text_field( (string) $upload['error'] ) );
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => 'application/json',
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			(string) $upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, (string) $upload['file'] ) );
		update_post_meta( $attachment_id, '_worldgraph_story_source_owner', get_current_user_id() );
		return (int) $attachment_id;
	}

	/** Validate that the user deliberately selected a usable LLM Connection. */
	private static function validate_connection( int $connection_id ) {
		if ( ! $connection_id ) {
			return new \WP_Error( 'worldgraph_story_connection_required', __( 'Select an LLM Connection to decompose this story source.', 'worldgraph' ) );
		}
		if ( ! \WorldGraph\Utils\Connection_Repository::current_user_can_manage( $connection_id ) ) {
			return new \WP_Error( 'worldgraph_story_connection_forbidden', __( 'You cannot use the selected LLM Connection.', 'worldgraph' ) );
		}

		$connection = \WorldGraph\Utils\Connection_Repository::get( $connection_id );
		$provider   = sanitize_key( (string) ( $connection['provider_type'] ?? '' ) );
		if ( ! is_array( $connection ) || 'publish' !== (string) ( $connection['status_wp'] ?? '' ) || ! in_array( $provider, self::LLM_TYPES, true ) ) {
			return new \WP_Error( 'worldgraph_story_connection_invalid', __( 'Select a published OpenAI-compatible, OpenAI, or Anthropic LLM Connection.', 'worldgraph' ) );
		}
		if ( 'disabled' === (string) ( $connection['status'] ?? '' ) ) {
			return new \WP_Error( 'worldgraph_story_connection_disabled', __( 'The selected LLM Connection is disabled.', 'worldgraph' ) );
		}
		if ( '' === trim( (string) ( $connection['endpoint_url'] ?? '' ) ) || '' === trim( (string) ( $connection['model'] ?? '' ) ) ) {
			return new \WP_Error( 'worldgraph_story_connection_incomplete', __( 'The selected LLM Connection needs an endpoint URL and model.', 'worldgraph' ) );
		}

		return $connection;
	}

	/** Render the upload page, a candidate review, or the final report. */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		nocache_headers();

		$notice  = self::consume_notice();
		$preview = null;
		$report  = null;
		$token   = '';
		if ( isset( $_GET['preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- User-scoped random read-only preview token.
			$token   = self::sanitize_token( wp_unslash( $_GET['preview'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preview = self::get_preview( $token );
			if ( is_wp_error( $preview ) ) {
				$notice  = [ 'type' => 'error', 'message' => self::safe_error_message( $preview ) ];
				$preview = null;
			}
		}
		if ( isset( $_GET['report'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- User-scoped random one-time report token.
			$report_token = self::sanitize_token( wp_unslash( $_GET['report'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$report       = get_transient( self::report_key( $report_token ) );
			delete_transient( self::report_key( $report_token ) );
			if ( ! is_array( $report ) || get_current_user_id() !== absint( $report['user_id'] ?? 0 ) ) {
				$report = null;
				$notice = [ 'type' => 'error', 'message' => __( 'The import report expired or is unavailable.', 'worldgraph' ) ];
			}
		}
		?>
		<div class="wrap worldgraph-import-wrap">
			<h1><?php esc_html_e( 'Import Story into World Graph Studio', 'worldgraph' ); ?></h1>
			<p><?php esc_html_e( 'Use this tool to bring a complete World Graph Studio JSON export or a story manuscript into your Story Graph. The importer validates the source and shows a preview before anything is written; manuscripts are prepared with your selected LLM Connection, while canonical JSON is imported directly. You can choose whether matching external IDs should update existing entities or be skipped.', 'worldgraph' ); ?></p>
			<?php self::render_notice( $notice ); ?>
			<?php if ( is_array( $report ) ) : ?>
				<?php self::render_report_result( $report ); ?>
			<?php elseif ( is_array( $preview ) ) : ?>
				<?php self::render_preview( $preview, $token ); ?>
			<?php else : ?>
				<?php self::render_upload_form(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Render the initial source-selection form. */
	private static function render_upload_form(): void {
		$connections = self::llm_connections();
		?>
		<p><?php esc_html_e( 'Upload a canonical World Graph Studio JSON document or a story manuscript. Every source is retained in the WordPress Media Library and follows your site’s upload-access policy. Manuscripts are sent through the LLM Connection you select; canonical JSON is never sent to an LLM.', 'worldgraph' ); ?></p>
		<form method="post" id="worldgraph-import-form" data-worldgraph-import-stage="preview" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="worldgraph_import_json" />
			<input type="hidden" name="worldgraph_import_stage" value="preview" />
			<?php wp_nonce_field( 'worldgraph_import' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="worldgraph_story_file"><?php esc_html_e( 'Story source file', 'worldgraph' ); ?></label></th>
					<td>
						<input type="file" required name="worldgraph_story_file" id="worldgraph_story_file" accept=".json,.txt,.text,.md,.markdown,.fountain,.rtf,.pdf,.epub,.docx,.odt" />
						<p class="description"><?php esc_html_e( 'JSON, TXT, Markdown, Fountain, RTF, PDF, EPUB, DOCX, or ODT. Files may be up to 20 MB; manuscripts may contain up to 500,000 extracted characters and 300,000 characters per decomposition.', 'worldgraph' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="worldgraph_connection_id"><?php esc_html_e( 'LLM Connection', 'worldgraph' ); ?></label></th>
					<td>
						<select name="worldgraph_connection_id" id="worldgraph_connection_id">
							<option value=""><?php esc_html_e( 'Select for manuscript decomposition…', 'worldgraph' ); ?></option>
							<?php foreach ( $connections as $connection ) : ?>
								<option value="<?php echo esc_attr( (string) $connection['id'] ); ?>"><?php echo esc_html( self::connection_label( $connection ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Required for prose and non-canonical JSON. Not used for canonical World Graph Studio JSON.', 'worldgraph' ); ?></p>
						<?php if ( empty( $connections ) ) : ?>
							<p class="notice notice-warning inline"><?php esc_html_e( 'No usable LLM Connections are available. Canonical JSON can still be previewed and imported.', 'worldgraph' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Existing external IDs', 'worldgraph' ); ?></th>
					<td>
						<label><input type="checkbox" name="worldgraph_overwrite" value="1" /> <?php esc_html_e( 'Update existing entities with matching external IDs', 'worldgraph' ); ?></label>
						<p class="description"><?php esc_html_e( 'This choice is locked into the preview. Omitted fields are preserved; explicitly empty values are cleared.', 'worldgraph' ); ?></p>
					</td>
				</tr>
			</table>
			<p data-worldgraph-import-status aria-live="polite"></p>
			<?php submit_button( __( 'Create Import Preview', 'worldgraph' ) ); ?>
		</form>
		<?php
	}

	/** Render a server-validated, immutable candidate and explicit commit control. */
	private static function render_preview( array $preview, string $token ): void {
		$summary       = is_array( $preview['summary'] ?? null ) ? $preview['summary'] : [];
		$source        = is_array( $preview['source'] ?? null ) ? $preview['source'] : [];
		$decomposition = is_array( $preview['decomposition'] ?? null ) ? $preview['decomposition'] : [];
		$attachment_id = absint( $preview['attachment_id'] ?? 0 );
		?>
		<div class="notice notice-info"><p><strong><?php esc_html_e( 'Preview only — nothing has been imported yet.', 'worldgraph' ); ?></strong> <?php esc_html_e( 'Review this validated candidate, then explicitly confirm or cancel.', 'worldgraph' ); ?></p></div>
		<h2><?php esc_html_e( 'Candidate summary', 'worldgraph' ); ?></h2>
		<table class="widefat striped" style="max-width:760px"><tbody>
			<tr><th><?php esc_html_e( 'Project', 'worldgraph' ); ?></th><td><?php echo esc_html( (string) ( $summary['project'] ?? '' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Source', 'worldgraph' ); ?></th><td><?php echo esc_html( (string) ( $source['filename'] ?? '' ) ); ?> (<?php echo esc_html( strtoupper( (string) ( $source['format'] ?? '' ) ) ); ?>, <?php echo esc_html( number_format_i18n( absint( $source['characters'] ?? 0 ) ) ); ?> <?php esc_html_e( 'characters', 'worldgraph' ); ?>) <?php self::render_attachment_link( $attachment_id ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Preparation', 'worldgraph' ); ?></th><td>
				<?php if ( ! empty( $decomposition['generated'] ) ) : ?>
					<?php echo esc_html( sprintf( __( 'Generated with %1$s using %2$s (%3$d model pass(es) across %4$d part(s), %5$d reported tokens).', 'worldgraph' ), (string) ( $decomposition['connection_name'] ?? __( 'selected Connection', 'worldgraph' ) ), (string) ( $decomposition['model'] ?? __( 'configured model', 'worldgraph' ) ), absint( $decomposition['attempts'] ?? 0 ), max( 1, absint( $decomposition['chunks'] ?? 1 ) ), absint( $decomposition['tokens'] ?? 0 ) ) ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Canonical JSON validated directly; no LLM was called.', 'worldgraph' ); ?>
				<?php endif; ?>
			</td></tr>
			<tr><th><?php esc_html_e( 'Existing IDs', 'worldgraph' ); ?></th><td><?php echo ! empty( $preview['overwrite'] ) ? esc_html__( 'Update matches', 'worldgraph' ) : esc_html__( 'Keep and skip matches', 'worldgraph' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Preview expires', 'worldgraph' ); ?></th><td><?php echo esc_html( wp_date( 'Y-m-d H:i:s T', absint( $preview['expires_at'] ?? 0 ) ) ); ?></td></tr>
		</tbody></table>

		<h3><?php esc_html_e( 'Entity counts', 'worldgraph' ); ?></h3>
		<table class="widefat striped" style="max-width:520px"><thead><tr><th><?php esc_html_e( 'Section', 'worldgraph' ); ?></th><th><?php esc_html_e( 'Count', 'worldgraph' ); ?></th></tr></thead><tbody>
			<?php foreach ( (array) ( $summary['counts'] ?? [] ) as $section => $count ) : ?>
				<tr><td><?php echo esc_html( (string) $section ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $count ) ) ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table>

		<details style="margin-top:1em">
			<summary><strong><?php esc_html_e( 'Review canonical JSON', 'worldgraph' ); ?></strong></summary>
			<pre style="max-height:32rem;overflow:auto;white-space:pre-wrap;background:#fff;border:1px solid #c3c4c7;padding:1em"><?php echo esc_html( (string) ( $preview['json'] ?? '' ) ); ?></pre>
		</details>

		<form method="post" id="worldgraph-confirm-import-form" data-worldgraph-import-stage="confirm" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="worldgraph_import_json" />
			<input type="hidden" name="worldgraph_import_stage" value="confirm" />
			<input type="hidden" name="worldgraph_preview_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'worldgraph_import' ); ?>
			<p><label><input type="checkbox" required name="worldgraph_confirm_import" value="1" data-worldgraph-confirm /> <strong><?php esc_html_e( 'I reviewed this candidate and want to import it.', 'worldgraph' ); ?></strong></label></p>
			<p data-worldgraph-import-status aria-live="polite"></p>
			<?php submit_button( __( 'Confirm and Import Project', 'worldgraph' ), 'primary', 'submit', false ); ?>
		</form>
		<form method="post" style="display:inline-block;margin-left:8px" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="worldgraph_import_json" />
			<input type="hidden" name="worldgraph_import_stage" value="cancel" />
			<input type="hidden" name="worldgraph_preview_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'worldgraph_import' ); ?>
			<?php submit_button( __( 'Cancel Preview', 'worldgraph' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/** Render the one-time post-commit result. */
	private static function render_report_result( array $result ): void {
		$verified      = ! empty( $result['verified'] );
		$report        = is_array( $result['report'] ?? null ) ? $result['report'] : [];
		$attachment_id = absint( $result['attachment_id'] ?? 0 );
		?>
		<div class="notice <?php echo $verified ? 'notice-success' : 'notice-error'; ?>">
			<p><strong><?php echo $verified ? esc_html__( 'Import completed and verified.', 'worldgraph' ) : esc_html__( 'Import ran, but verification reported errors.', 'worldgraph' ); ?></strong></p>
		</div>
		<p><?php esc_html_e( 'Persisted source:', 'worldgraph' ); ?> <?php self::render_attachment_link( $attachment_id ); ?></p>
		<?php self::render_report( $report ); ?>
		<p><a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Import Another Story', 'worldgraph' ); ?></a></p>
		<?php
	}

	/** Render an importer report without treating partial verification as success. */
	private static function render_report( array $report ): void {
		$totals   = is_array( $report['totals'] ?? null ) ? $report['totals'] : [];
		$expected = is_array( $report['expected_totals'] ?? null ) ? $report['expected_totals'] : [];
		?>
		<h2><?php esc_html_e( 'Import Report', 'worldgraph' ); ?></h2>
		<table class="widefat striped" style="max-width:720px"><thead><tr><th><?php esc_html_e( 'Entity type', 'worldgraph' ); ?></th><th><?php esc_html_e( 'Resolved', 'worldgraph' ); ?></th><th><?php esc_html_e( 'Expected', 'worldgraph' ); ?></th></tr></thead><tbody>
			<?php foreach ( $totals as $type => $count ) : ?>
				<tr><td><?php echo esc_html( (string) $type ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $count ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $expected[ $type ] ?? 0 ) ) ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table>
		<?php foreach ( [ 'created' => __( 'Created', 'worldgraph' ), 'updated' => __( 'Updated', 'worldgraph' ), 'skipped' => __( 'Skipped', 'worldgraph' ), 'errors' => __( 'Errors', 'worldgraph' ) ] as $key => $label ) : ?>
			<?php if ( ! empty( $report[ $key ] ) && is_array( $report[ $key ] ) ) : ?>
				<h3><?php echo esc_html( $label ); ?></h3><ul>
					<?php foreach ( $report[ $key ] as $entry ) : ?><li><?php echo esc_html( (string) $entry ); ?></li><?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php
	}

	/** Build a non-sensitive summary from a validated document. */
	private static function document_summary( array $document ): array {
		$counts = [];
		foreach ( [ 'characters', 'locations', 'props', 'organizations', 'episodes', 'scenes', 'shots', 'sounds', 'assets', 'editorial_artifacts' ] as $section ) {
			$counts[ $section ] = is_array( $document[ $section ] ?? null ) ? count( $document[ $section ] ) : 0;
		}
		$counts['project']  = isset( $document['project'] ) ? 1 : 0;
		$counts['world']    = isset( $document['world'] ) ? 1 : 0;
		$counts['sequence'] = isset( $document['sequence'] ) ? 1 : 0;

		return [
			'project' => sanitize_text_field( (string) ( $document['project']['title'] ?? __( 'Untitled Project', 'worldgraph' ) ) ),
			'counts'  => $counts,
		];
	}

	/** List only manageable Connection records supported by Story_Decomposer. */
	private static function llm_connections(): array {
		$connections = [];
		foreach ( \WorldGraph\Utils\Connection_Repository::get_all() as $connection ) {
			$id       = absint( $connection['id'] ?? 0 );
			$provider = sanitize_key( (string) ( $connection['provider_type'] ?? '' ) );
			if (
				$id &&
				in_array( $provider, self::LLM_TYPES, true ) &&
				'disabled' !== (string) ( $connection['status'] ?? '' ) &&
				'' !== trim( (string) ( $connection['endpoint_url'] ?? '' ) ) &&
				'' !== trim( (string) ( $connection['model'] ?? '' ) ) &&
				\WorldGraph\Utils\Connection_Repository::current_user_can_manage( $id )
			) {
				$connections[] = $connection;
			}
		}
		return $connections;
	}

	/** Format a safe Connection option label. */
	private static function connection_label( array $connection ): string {
		return sprintf(
			'%1$s — %2$s / %3$s',
			self::connection_name( $connection ),
			(string) ( $connection['provider_type'] ?? '' ),
			(string) ( $connection['model'] ?? __( 'model not set', 'worldgraph' ) )
		);
	}

	/** Read the display name from a Connection record. */
	private static function connection_name( array $connection ): string {
		$name = ! empty( $connection['connection_name'] ) ? $connection['connection_name'] : ( $connection['title'] ?? '' );
		return sanitize_text_field( (string) $name );
	}

	/** Atomically serialize confirmation requests for one preview. */
	private static function acquire_lock( string $token ) {
		$key      = 'worldgraph_story_import_lock_' . substr( hash( 'sha256', get_current_user_id() . ':' . $token ), 0, 40 );
		$existing = absint( get_option( $key, 0 ) );
		if ( $existing && ( time() - $existing ) > self::LOCK_TTL ) {
			delete_option( $key );
		}
		if ( ! add_option( $key, time(), '', false ) ) {
			return new \WP_Error( 'worldgraph_story_import_locked', __( 'This preview is already being imported. Wait for the first request to finish.', 'worldgraph' ) );
		}
		return $key;
	}

	/** Resolve and authorize a user-scoped preview transient. */
	private static function get_preview( string $token ) {
		if ( '' === $token ) {
			return new \WP_Error( 'worldgraph_story_preview_missing', __( 'The import preview token is missing.', 'worldgraph' ) );
		}
		$preview = get_transient( self::preview_key( $token ) );
		if ( ! is_array( $preview ) || get_current_user_id() !== absint( $preview['user_id'] ?? 0 ) || time() >= absint( $preview['expires_at'] ?? 0 ) ) {
			delete_transient( self::preview_key( $token ) );
			return new \WP_Error( 'worldgraph_story_preview_expired', __( 'The import preview expired or is unavailable. Upload the source again.', 'worldgraph' ) );
		}
		return $preview;
	}

	/** Record provenance on the source attachment and attach it to the Project. */
	private static function finish_attachment( int $attachment_id, string $json, array $report, bool $verified ): void {
		if ( ! $attachment_id ) {
			return;
		}
		self::mark_attachment( $attachment_id, $verified ? 'imported' : 'verification_failed' );
		update_post_meta( $attachment_id, '_worldgraph_story_candidate_sha256', hash( 'sha256', $json ) );

		$document   = json_decode( $json, true );
		$external   = is_array( $document ) ? (string) ( $document['project']['id'] ?? '' ) : '';
		$project_id = absint( $report['id_map'][ $external ] ?? 0 );
		if ( $project_id && 'worldgraph_project' === get_post_type( $project_id ) ) {
			wp_update_post( [ 'ID' => $attachment_id, 'post_parent' => $project_id ] );
			update_post_meta( $attachment_id, '_worldgraph_story_project_id', $project_id );
		}
	}

	/** Mark safe workflow state on a persisted source attachment. */
	private static function mark_attachment( int $attachment_id, string $status, string $token = '' ): void {
		if ( ! $attachment_id ) {
			return;
		}
		update_post_meta( $attachment_id, '_worldgraph_story_import_status', sanitize_key( $status ) );
		update_post_meta( $attachment_id, '_worldgraph_story_imported_by', get_current_user_id() );
		if ( '' !== $token ) {
			update_post_meta( $attachment_id, '_worldgraph_story_preview_fingerprint', substr( hash( 'sha256', $token ), 0, 24 ) );
		}
	}

	/** Display a Media Library edit link, never the public source URL. */
	private static function render_attachment_link( int $attachment_id ): void {
		$link = $attachment_id ? get_edit_post_link( $attachment_id ) : '';
		if ( $link ) {
			printf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( sprintf( __( 'Media item #%d', 'worldgraph' ), $attachment_id ) ) );
		} else {
			echo esc_html( sprintf( __( 'Media item #%d', 'worldgraph' ), $attachment_id ) );
		}
	}

	/** Normalize provider/upload failures before showing them in wp-admin. */
	private static function safe_error_message( \WP_Error $error ): string {
		$code = sanitize_key( (string) $error->get_error_code() );
		if ( str_starts_with( $code, 'worldgraph_credential_' ) || 'worldgraph_llm_request_failed' === $code ) {
			return __( 'The selected LLM Connection could not complete the request. Review the Connection and try again.', 'worldgraph' );
		}
		$message = sanitize_text_field( (string) $error->get_error_message() );
		return '' !== $message ? $message : __( 'The story import request failed.', 'worldgraph' );
	}

	/** Convert PHP's upload status into an administrator-facing message. */
	private static function upload_error_message( int $error ): string {
		if ( in_array( $error, [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ) {
			return __( 'The selected story source exceeds the server upload limit.', 'worldgraph' );
		}
		if ( UPLOAD_ERR_PARTIAL === $error ) {
			return __( 'The story source upload was incomplete. Try again.', 'worldgraph' );
		}
		return __( 'The story source could not be uploaded.', 'worldgraph' );
	}

	/** Consume the one-time, user-scoped redirect notice. */
	private static function consume_notice(): ?array {
		if ( ! isset( $_GET['notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Random read-only notice token.
			return null;
		}
		$token  = self::sanitize_token( wp_unslash( $_GET['notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = get_transient( self::notice_key( $token ) );
		delete_transient( self::notice_key( $token ) );
		if ( ! is_array( $notice ) || get_current_user_id() !== absint( $notice['user_id'] ?? 0 ) ) {
			return null;
		}
		return $notice;
	}

	/** Render one sanitized admin notice. */
	private static function render_notice( ?array $notice ): void {
		if ( ! $notice ) {
			return;
		}
		$type = in_array( $notice['type'] ?? '', [ 'success', 'warning', 'error', 'info' ], true ) ? $notice['type'] : 'info';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?>
			<?php if ( ! empty( $notice['attachment_id'] ) ) : ?> <?php self::render_attachment_link( absint( $notice['attachment_id'] ) ); ?><?php endif; ?>
		</p></div>
		<?php
	}

	/** Store a user-scoped notice and redirect, optionally to a live preview. */
	private static function redirect_with_notice( string $type, string $message, int $attachment_id = 0, string $preview_token = '' ): void {
		$token = self::new_token();
		set_transient(
			self::notice_key( $token ),
			[
				'user_id'       => get_current_user_id(),
				'type'          => sanitize_key( $type ),
				'message'       => sanitize_text_field( $message ),
				'attachment_id' => $attachment_id,
			],
			self::REPORT_TTL
		);
		$args = [ 'notice' => $token ];
		if ( '' !== $preview_token ) {
			$args['preview'] = $preview_token;
		}
		self::redirect( $args );
	}

	/** Redirect back to the legacy import page. */
	private static function redirect( array $args = [] ): void {
		wp_safe_redirect( add_query_arg( $args, self::page_url() ) );
		exit;
	}

	/** Return the legacy page URL. */
	private static function page_url(): string {
		return add_query_arg( 'page', 'worldgraph-import', admin_url( 'admin.php' ) );
	}

	/** Read a preview token from a confirmation or cancellation form. */
	private static function posted_token(): string {
		return isset( $_POST['worldgraph_preview_token'] )
			? self::sanitize_token( wp_unslash( $_POST['worldgraph_preview_token'] ) )
			: '';
	}

	/** Limit tokens to the URL-safe alphabet generated below. */
	private static function sanitize_token( $token ): string {
		$token = (string) $token;
		return preg_match( '/\A[A-Za-z0-9_-]{32,86}\z/', $token ) ? $token : '';
	}

	/** Generate an unguessable URL-safe workflow token. */
	private static function new_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/** Scope transient names to workflow token and current user. */
	private static function preview_key( string $token ): string {
		return 'wgs_story_preview_' . get_current_user_id() . '_' . substr( hash( 'sha256', $token ), 0, 40 );
	}

	/** Scope final reports to the current user. */
	private static function report_key( string $token ): string {
		return 'wgs_story_report_' . get_current_user_id() . '_' . substr( hash( 'sha256', $token ), 0, 40 );
	}

	/** Scope redirect notices to the current user. */
	private static function notice_key( string $token ): string {
		return 'wgs_story_notice_' . get_current_user_id() . '_' . substr( hash( 'sha256', $token ), 0, 40 );
	}
}
