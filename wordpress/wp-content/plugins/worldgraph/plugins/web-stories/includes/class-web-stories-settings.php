<?php
/**
 * Web Stories Settings Class.
 *
 * Handles admin settings for the Web Stories integration.
 *
 * @package WorldGraphWebStories
 */

namespace WorldGraphWebStories;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 *
 * Manages plugin settings and admin UI.
 */
class Settings {

	/**
	 * Settings instance.
	 *
	 * @var Settings|null
	 */
	private static $instance = null;

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'worldgraph-web-stories-settings';

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	private $option_name = 'worldgraph_web_stories_settings';

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	private $default_settings = [
		'sync_enabled'          => false,
		'sync_direction'        => 'bidirectional',
		'auto_sync_on_save'     => true,
		'sync_shots'            => false,
		'default_status'        => 'draft',
		'create_pages_from'     => 'summary',
	];

	/**
	 * Get the settings instance.
	 *
	 * @return Settings
	 */
	public static function init(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Register hooks.
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . WORLDGRAPH_WEB_STORIES_PLUGIN_BASE, [ $this, 'add_plugin_links' ] );
	}

	/**
	 * Add settings page to admin menu.
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			'worldgraph_menu',
			'Web Stories Settings',
			'Web Stories',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings(): void {
		register_setting(
			$this->page_slug,
			$this->option_name,
			[
				'type'              => 'array',
				'default'           => $this->default_settings,
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			]
		);

		// General section.
		add_settings_section(
			'worldgraph_web_stories_general',
			'General Settings',
			[ $this, 'render_general_section' ],
			$this->page_slug
		);

		// Sync settings.
		add_settings_section(
			'worldgraph_web_stories_sync',
			'Sync Settings',
			[ $this, 'render_sync_section' ],
			$this->page_slug
		);

		// Display settings.
		add_settings_section(
			'worldgraph_web_stories_display',
			'Display Settings',
			[ $this, 'render_display_section' ],
			$this->page_slug
		);

		// Enable sync toggle.
		add_settings_field(
			'sync_enabled',
			'Enable Sync',
			[ $this, 'render_field_toggle' ],
			$this->page_slug,
			'worldgraph_web_stories_general',
			[
				'field'  => 'sync_enabled',
				'label'  => 'Enable synchronization between World Graph Studio and Web Stories',
				'default' => false,
			]
		);

		// Sync direction.
		add_settings_field(
			'sync_direction',
			'Sync Direction',
			[ $this, 'render_field_select' ],
			$this->page_slug,
			'worldgraph_web_stories_sync',
			[
				'field'  => 'sync_direction',
				'label'  => 'Direction of synchronization',
				'options' => [
					'bidirectional' => 'Bidirectional (both ways)',
					'worldgraph_to_web' => 'World Graph Studio to Web Stories only',
					'web_to_worldgraph' => 'Web Stories to World Graph Studio only',
				],
				'default' => 'bidirectional',
			]
		);

		// Auto sync on save.
		add_settings_field(
			'auto_sync_on_save',
			'Auto Sync on Save',
			[ $this, 'render_field_toggle' ],
			$this->page_slug,
			'worldgraph_web_stories_sync',
			[
				'field'  => 'auto_sync_on_save',
				'label'  => 'Automatically sync when a Story or Scene is saved',
				'default' => true,
			]
		);

		// Default status.
		add_settings_field(
			'default_status',
			'Default Story Status',
			[ $this, 'render_field_select' ],
			$this->page_slug,
			'worldgraph_web_stories_sync',
			[
				'field'  => 'default_status',
				'label'  => 'Default status for newly created Web Stories',
				'options' => [
					'draft'     => 'Draft',
					'publish'   => 'Published',
					'archived'  => 'Archived',
				],
				'default' => 'draft',
			]
		);

		// Create pages from.
		add_settings_field(
			'create_pages_from',
			'Create Pages From',
			[ $this, 'render_field_select' ],
			$this->page_slug,
			'worldgraph_web_stories_display',
			[
				'field'  => 'create_pages_from',
				'label'  => 'Content source for Web Story pages',
				'options' => [
					'summary'     => 'Scene Summary',
					'script'      => 'Script Content',
					'content'     => 'Post Content',
					'combined'    => 'Summary + Script + Content',
				],
				'default' => 'summary',
			]
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( $this->page_slug );
				do_settings_sections( $this->page_slug );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render general section description.
	 */
	public function render_general_section(): void {
		echo '<p>Configure basic settings for the Web Stories integration.</p>';
	}

	/**
	 * Render sync section description.
	 */
	public function render_sync_section(): void {
		echo '<p>Configure how World Graph Studio and Web Stories synchronize.</p>';
	}

	/**
	 * Render display section description.
	 */
	public function render_display_section(): void {
		echo '<p>Configure how content is displayed in Web Stories.</p>';
	}

	/**
	 * Render a toggle field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_field_toggle( array $args ): void {
		$settings = $this->get_settings();
		$value = $settings[ $args['field'] ] ?? $args['default'] ?? false;
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( "{$this->option_name}[{$args['field']}]" ); ?>"
				value="1"
				<?php checked( (bool) $value, true ); ?>
			/>
			<?php echo esc_html( $args['label'] ?? '' ); ?>
		</label>
		<?php
	}

	/**
	 * Render a select field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_field_select( array $args ): void {
		$settings = $this->get_settings();
		$value = $settings[ $args['field'] ] ?? $args['default'] ?? '';
		?>
		<select name="<?php echo esc_attr( "{$this->option_name}[{$args['field']}]" ); ?>">
			<?php foreach ( $args['options'] as $option_value => $option_label ) : ?>
				<option value="<?php echo esc_attr( $option_value ); ?>"
					<?php selected( $value, $option_value ); ?>>
					<?php echo esc_html( $option_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Get plugin settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return get_option( $this->option_name, $this->default_settings );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get_setting( string $key, $default = null ) {
		$settings = $this->get_settings();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array $input The settings input.
	 * @return array
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = [];

		// Boolean fields.
		$boolean_fields = [ 'sync_enabled', 'auto_sync_on_save' ];
		foreach ( $boolean_fields as $field ) {
			$sanitized[ $field ] = isset( $input[ $field ] ) ? (bool) $input[ $field ] : $this->default_settings[ $field ];
		}

		// Select fields.
		$select_fields = [
			'sync_direction'  => [ 'bidirectional', 'worldgraph_to_web', 'web_to_worldgraph' ],
			'default_status'  => [ 'draft', 'publish', 'archived' ],
			'create_pages_from' => [ 'summary', 'script', 'content', 'combined' ],
		];
		foreach ( $select_fields as $field => $valid_values ) {
			$sanitized[ $field ] = in_array( $input[ $field ] ?? '', $valid_values, true ) ? $input[ $field ] : $this->default_settings[ $field ];
		}

		return $sanitized;
	}

	/**
	 * Add plugin settings link to plugins page.
	 *
	 * @param array $links Existing plugin links.
	 * @return array
	 */
	public function add_plugin_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . $this->page_slug ) ) . '">' . esc_html__( 'Settings', 'worldgraph' ) . '</a>';
		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Check if sync is enabled.
	 *
	 * @return bool
	 */
	public static function is_sync_enabled(): bool {
		$settings = self::init()->get_settings();
		return (bool) ( $settings['sync_enabled'] ?? false );
	}

	/**
	 * Get sync direction.
	 *
	 * @return string
	 */
	public static function get_sync_direction(): string {
		$settings = self::init()->get_settings();
		return $settings['sync_direction'] ?? 'bidirectional';
	}

	/**
	 * Check if auto sync is enabled.
	 *
	 * @return bool
	 */
	public static function is_auto_sync_enabled(): bool {
		$settings = self::init()->get_settings();
		return (bool) ( $settings['auto_sync_on_save'] ?? true );
	}

	/**
	 * Get content source for pages.
	 *
	 * @return string
	 */
	public static function get_page_content_source(): string {
		$settings = self::init()->get_settings();
		return $settings['create_pages_from'] ?? 'summary';
	}
}
