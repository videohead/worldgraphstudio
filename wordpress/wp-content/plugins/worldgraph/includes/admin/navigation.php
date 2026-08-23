<?php
/**
 * World Graph Studio admin navigation.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Admin;

/**
 * Registers the World Graph Studio sidebar groups and placeholder tool pages.
 */
class Navigation {

	/**
	 * Register navigation hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 5 );
	}

	/**
	 * Add the World Graph Studio sidebar groups.
	 */
	public static function add_menu(): void {
		$groups = [
			[ 'worldgraph-story-elements', 'Story Elements', 'Story Elements', 'edit_posts', 'dashicons-book-alt', 31 ],
			[ 'worldgraph-editorial', 'Editorial', 'Editorial', 'edit_posts', 'dashicons-edit', 32 ],
			[ 'worldgraph-analysis', 'Story Analysis', 'Story Analysis', 'edit_posts', 'dashicons-chart-area', 33 ],
		];

		foreach ( $groups as $group ) {
			add_menu_page(
				$group[1],
				$group[2],
				$group[3],
				$group[0],
				[ __CLASS__, 'render_group' ],
				$group[4],
				$group[5]
			);
		}

		// Keep direct dashboard destinations valid without showing these groups in the sidebar.
		// `worldgraph-administration` has no card of its own; it stays registered
		// so the Plugins and legacy AI Settings pages keep a valid parent.
		foreach ( [
			[ 'worldgraph-generate', 'Generate' ],
			[ 'worldgraph-administration', 'Administration' ],
			[ 'worldgraph-plugins', 'Plugins' ],
		] as $hidden_page ) {
			add_menu_page(
				$hidden_page[1],
				$hidden_page[1],
				'manage_options',
				$hidden_page[0],
				[ __CLASS__, 'render_group' ],
				'dashicons-admin-generic',
				99
			);
		}

		add_action( 'admin_menu', [ __CLASS__, 'hide_legacy_groups' ], 99 );

		add_submenu_page(
			'worldgraph-analysis',
			'Summaries',
			'Summaries',
			'edit_posts',
			'worldgraph-summaries',
			[ Summary_Tool::class, 'render_page' ]
		);
		add_submenu_page(
			'worldgraph-analysis',
			'Dramaturgy',
			'Dramaturgy',
			'edit_posts',
			'worldgraph-dramaturgy',
			[ Dramaturgy_Tool::class, 'render_page' ]
		);
	}

	/**
	 * Remove dashboard-only groups from the visible sidebar after child pages register.
	 */
	public static function hide_legacy_groups(): void {
		remove_menu_page( 'worldgraph-generate' );
		remove_menu_page( 'worldgraph-administration' );
		remove_menu_page( 'worldgraph-plugins' );
	}

	/**
	 * Add a page for a tool that does not have an implementation yet.
	 *
	 * @param string $slug Menu slug.
	 * @param string $label Menu label.
	 * @param string $parent Parent menu slug.
	 */
	private static function add_placeholder_page( string $slug, string $label, string $parent ): void {
		add_submenu_page(
			$parent,
			$label,
			$label,
			'edit_posts',
			$slug,
			[ __CLASS__, 'render_placeholder' ]
		);
	}

	/**
	 * Render a group landing page.
	 */
	public static function render_group(): void {
		$title = get_admin_page_title();
		$title = $title ?: __( 'World Graph Studio', 'worldgraph' );
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only menu selection.
		$cards  = self::get_group_cards( $page );
		?>
		<div class="wrap worldgraph-group-page">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p class="worldgraph-group-intro"><?php esc_html_e( 'Choose an area to continue.', 'worldgraph' ); ?></p>
			<?php if ( ! empty( $cards ) ) : ?>
				<div class="worldgraph-group-cards">
					<?php foreach ( $cards as $card ) : ?>
						<a class="worldgraph-group-card" href="<?php echo esc_url( $card['url'] ); ?>">
							<span class="worldgraph-group-card-icon dashicons <?php echo esc_attr( $card['icon'] ); ?>" aria-hidden="true"></span>
							<span class="worldgraph-group-card-content">
								<strong><?php echo esc_html( $card['title'] ); ?></strong>
								<span><?php echo esc_html( $card['description'] ); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get the navigation cards for a main World Graph Studio page.
	 *
	 * @param string $page Main page slug.
	 * @return array
	 */
	private static function get_group_cards( string $page ): array {
		$cards = [
			'worldgraph-story-elements' => [
				[ 'title' => 'Projects', 'description' => 'Manage the stories you are building.', 'icon' => 'dashicons-portfolio', 'url' => admin_url( 'edit.php?post_type=worldgraph_project' ) ],
				[ 'title' => 'Story Worlds', 'description' => 'Define the worlds and settings behind your stories.', 'icon' => 'dashicons-admin-site', 'url' => admin_url( 'edit.php?post_type=worldgraph_world' ) ],
				[ 'title' => 'Characters', 'description' => 'Build the people who drive the story.', 'icon' => 'dashicons-admin-users', 'url' => admin_url( 'edit.php?post_type=worldgraph_character' ) ],
				[ 'title' => 'Locations', 'description' => 'Organize the places where stories unfold.', 'icon' => 'dashicons-location', 'url' => admin_url( 'edit.php?post_type=worldgraph_location' ) ],
				[ 'title' => 'Props', 'description' => 'Track meaningful objects and story details.', 'icon' => 'dashicons-archive', 'url' => admin_url( 'edit.php?post_type=worldgraph_prop' ) ],
			],
			'worldgraph-editorial' => [
				[ 'title' => 'Episodes', 'description' => 'Organize the larger structure of your story.', 'icon' => 'dashicons-list-view', 'url' => admin_url( 'edit.php?post_type=worldgraph_episode' ) ],
				[ 'title' => 'Scenes', 'description' => 'Develop the story beat by beat.', 'icon' => 'dashicons-format-video', 'url' => admin_url( 'edit.php?post_type=worldgraph_scene' ) ],
				[ 'title' => 'Shots', 'description' => 'Plan the visual coverage of each scene.', 'icon' => 'dashicons-camera-alt', 'url' => admin_url( 'edit.php?post_type=worldgraph_shot' ) ],
				[ 'title' => 'Sounds', 'description' => 'Plan narration, music, effects, ambience, Foley, and silence.', 'icon' => 'dashicons-format-audio', 'url' => admin_url( 'edit.php?post_type=worldgraph_sound' ) ],
				[ 'title' => 'Assets', 'description' => 'Manage the media and files used by your story.', 'icon' => 'dashicons-media-default', 'url' => admin_url( 'edit.php?post_type=worldgraph_asset' ) ],
				[ 'title' => 'Editorial Cut', 'description' => 'Review and shape the assembled cut.', 'icon' => 'dashicons-editor-video', 'url' => admin_url( 'admin.php?page=worldgraph-editorial-cut' ) ],
			],
			'worldgraph-analysis' => [
				[ 'title' => 'Analysis', 'description' => 'Explore relationships and story graph intelligence.', 'icon' => 'dashicons-chart-area', 'url' => admin_url( 'admin.php?page=worldgraph-analytics' ) ],
				[ 'title' => 'Summaries', 'description' => 'Generate and review story summaries.', 'icon' => 'dashicons-media-document', 'url' => admin_url( 'admin.php?page=worldgraph-summaries' ) ],
				[ 'title' => 'Continuity', 'description' => 'Check your story for continuity issues.', 'icon' => 'dashicons-yes-alt', 'url' => admin_url( 'admin.php?page=worldgraph-continuity' ) ],
				[ 'title' => 'Dramaturgy', 'description' => 'Examine structure, tension, and narrative movement.', 'icon' => 'dashicons-lightbulb', 'url' => admin_url( 'admin.php?page=worldgraph-dramaturgy' ) ],
			],
			'worldgraph-generate' => [
				[ 'title' => 'Setup Wizard', 'description' => 'Configure World Graph Studio connections and workspace settings.', 'icon' => 'dashicons-admin-tools', 'url' => admin_url( 'admin.php?page=worldgraph-setup' ) ],
				[ 'title' => 'Connections', 'description' => 'Manage external services and integrations.', 'icon' => 'dashicons-admin-links', 'url' => admin_url( 'admin.php?page=worldgraph-connections' ) ],
				[ 'title' => 'Templates', 'description' => 'Manage the generation Templates a Connection can run.', 'icon' => 'dashicons-layout', 'url' => admin_url( 'edit.php?post_type=worldgraph_template' ) ],
				[ 'title' => 'Adapters', 'description' => 'See the provider adapters this installation can run.', 'icon' => 'dashicons-admin-plugins', 'url' => admin_url( 'admin.php?page=worldgraph-adapters' ) ],
				[ 'title' => 'Jobs', 'description' => 'Review generation Jobs, their Connection and Template, and what the provider returned.', 'icon' => 'dashicons-database-view', 'url' => admin_url( 'edit.php?post_type=worldgraph_gen' ) ],
			],
		];

		return $cards[ $page ] ?? [];
	}

	/**
	 * Render a not-yet-available tool page.
	 */
	public static function render_placeholder(): void {
		$title = get_admin_page_title();
		$title = $title ?: __( 'World Graph Studio Tool', 'worldgraph' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p><?php esc_html_e( 'This World Graph Studio tool is not available yet.', 'worldgraph' ); ?></p>
		</div>
		<?php
	}
}
