<?php
/**
 * Admin UI for local ComfyUI readiness and first-run guidance.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

use WorldGraph\Utils\Comfy_Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the ComfyUI setup checklist and its recheck/provision actions.
 */
class Comfy_Readiness {

	/**
	 * Nonce action shared by the checklist actions.
	 */
	const NONCE = 'worldgraph_comfy_readiness';

	/**
	 * Whether the component script has already been localized this request.
	 *
	 * @var bool
	 */
	private static $script_enqueued = false;

	/**
	 * Register the checklist AJAX handlers.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_worldgraph_comfy_readiness', [ __CLASS__, 'ajax_check' ] );
		add_action( 'wp_ajax_worldgraph_comfy_provision_template', [ __CLASS__, 'ajax_provision' ] );
	}

	/**
	 * Render the interactive checklist with its recheck and provision buttons.
	 */
	public static function render_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_script();
		\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );
		?>
		<div class="worldgraph-comfy-readiness">
			<div id="worldgraph-comfy-readiness-steps">
				<?php self::render_steps( Comfy_Bootstrap::status() ); ?>
			</div>
			<p>
				<button type="button" class="button" id="worldgraph-comfy-recheck"><?php esc_html_e( 'Re-check ComfyUI', 'worldgraph' ); ?></button>
				<button type="button" class="button" id="worldgraph-comfy-provision"><?php esc_html_e( 'Prepare local image Template', 'worldgraph' ); ?></button>
				<span id="worldgraph-comfy-readiness-message" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue and localize the controller only when the readiness panel renders.
	 */
	private static function enqueue_script(): void {
		if ( self::$script_enqueued ) {
			return;
		}

		$handle      = 'worldgraph-comfy-readiness';
		$script_path = WORLDGRAPH_PLUGIN_DIR . 'assets/js/comfy-readiness.js';

		wp_enqueue_script(
			$handle,
			WORLDGRAPH_PLUGIN_URL . 'assets/js/comfy-readiness.js',
			[],
			is_file( $script_path ) ? (string) filemtime( $script_path ) : WORLDGRAPH_VERSION,
			true
		);
		wp_localize_script(
			$handle,
			'worldgraphComfyReadiness',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'actions' => [
					'check'     => 'worldgraph_comfy_readiness',
					'provision' => 'worldgraph_comfy_provision_template',
				],
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => [
					'checking' => __( 'Checking ComfyUI…', 'worldgraph' ),
					'failed'   => __( 'The ComfyUI check could not be completed.', 'worldgraph' ),
				],
			]
		);

		self::$script_enqueued = true;
	}

	/**
	 * Render a readiness report as a checklist.
	 *
	 * @param array $status Report from Comfy_Bootstrap::status().
	 */
	public static function render_steps( array $status ): void {
		if ( empty( $status['steps'] ) ) {
			echo '<p>' . esc_html__( 'Nothing to check yet.', 'worldgraph' ) . '</p>';
			return;
		}
		?>
		<ul class="worldgraph-comfy-readiness__steps">
			<?php foreach ( $status['steps'] as $step ) : ?>
				<li>
					<span class="dashicons <?php echo esc_attr( self::icon( (string) $step['state'] ) ); ?>" aria-hidden="true"></span>
					<strong><?php echo esc_html( $step['label'] ); ?></strong>
					<span class="screen-reader-text"><?php echo esc_html( self::state_label( (string) $step['state'] ) ); ?></span><br />
					<span class="description">
						<?php echo esc_html( $step['message'] ); ?>
						<?php if ( ! empty( $step['url'] ) ) : ?>
							<a href="<?php echo esc_url( $step['url'] ); ?>"><?php esc_html_e( 'Open Template', 'worldgraph' ); ?></a>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Render the compact notice used outside the setup screen.
	 *
	 * @param array $status Report from Comfy_Bootstrap::status().
	 */
	public static function render_notice( array $status ): void {
		$pending = array_values( array_filter( $status['steps'] ?? [], static function ( array $step ): bool {
			return 'ok' !== $step['state'];
		} ) );
		if ( empty( $pending ) ) {
			return;
		}
		?>
		<div class="notice notice-warning inline worldgraph-comfy-readiness__notice">
			<p>
				<strong><?php esc_html_e( 'ComfyUI is not ready to generate yet.', 'worldgraph' ); ?></strong>
				<?php echo esc_html( $pending[0]['message'] ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=worldgraph-setup' ) ); ?>"><?php esc_html_e( 'Finish ComfyUI setup', 'worldgraph' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Re-probe ComfyUI and return the refreshed checklist.
	 */
	public static function ajax_check(): void {
		self::authorize();
		self::respond( Comfy_Bootstrap::status( true ) );
	}

	/**
	 * Provision the text-to-image Template, then return the refreshed checklist.
	 */
	public static function ajax_provision(): void {
		self::authorize();

		$template_id = Comfy_Bootstrap::ensure_template( \WorldGraph\Utils\Connection_Repository::get_default( 'comfyui' ) ?? 0 );
		if ( ! $template_id ) {
			wp_send_json_error( [ 'message' => __( 'The text-to-image Template could not be created.', 'worldgraph' ) ] );
		}

		self::respond(
			Comfy_Bootstrap::status( true ),
			sprintf(
				/* translators: %s: Template title. */
				__( 'Using %s. The managed local image Template is registry-backed and never silently falls back to a legacy checkpoint. If an exact model or node is missing, the checklist above states what to install.', 'worldgraph' ),
				get_the_title( $template_id )
			)
		);
	}

	/**
	 * Permission and nonce gate for the checklist actions.
	 */
	private static function authorize(): void {
		\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to configure ComfyUI.', 'worldgraph' ) ], 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Send a checklist response.
	 *
	 * @param array  $status  Readiness report.
	 * @param string $message Optional message to show beside the buttons.
	 */
	private static function respond( array $status, string $message = '' ): void {
		ob_start();
		self::render_steps( $status );
		$html = (string) ob_get_clean();

		if ( '' === $message ) {
			$message = ! empty( $status['ready'] )
				? __( 'ComfyUI is ready to generate.', 'worldgraph' )
				: __( 'ComfyUI still needs the steps marked above.', 'worldgraph' );
		}

		wp_send_json_success( [
			'ready'   => ! empty( $status['ready'] ),
			'message' => $message,
			'html'    => $html,
		] );
	}

	/**
	 * Dashicon for a step state.
	 *
	 * @param string $state Step state.
	 * @return string
	 */
	private static function icon( string $state ): string {
		if ( 'ok' === $state ) {
			return 'dashicons-yes-alt';
		}

		return 'error' === $state ? 'dashicons-dismiss' : 'dashicons-clock';
	}

	/**
	 * Screen-reader label for a step state.
	 *
	 * @param string $state Step state.
	 * @return string
	 */
	private static function state_label( string $state ): string {
		if ( 'ok' === $state ) {
			return __( 'Done', 'worldgraph' );
		}

		return 'error' === $state ? __( 'Failed', 'worldgraph' ) : __( 'Action needed', 'worldgraph' );
	}
}
