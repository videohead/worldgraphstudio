<?php
/**
 * Title: World Graph Studio overview page
 * Slug: worldgraph-child/page-overview
 * Categories: featured, pages
 * Keywords: overview, workflow, import, export, story graph
 * Block Types: core/post-content
 * Post Types: page
 * Viewport Width: 1440
 * Description: A narrative overview page with workflow and import/export diagrams, linked from the home page navigation.
 *
 * @package WorldGraphChild
 */
?>

<!-- wp:group {"align":"full","className":"wg-home wg-overview","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull wg-home wg-overview">
	<!-- wp:group {"tagName":"section","align":"full","anchor":"overview-top","backgroundColor":"warm-ivory","textColor":"dark-espresso","className":"wg-section wg-hero","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-hero has-dark-espresso-color has-warm-ivory-background-color has-text-color has-background" id="overview-top">
		<!-- wp:group {"align":"wide","className":"wg-hero__inner","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-hero__inner">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Overview', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","level":1,"className":"wg-hero__title","fontFamily":"headline"} -->
			<h1 class="wp-block-heading has-text-align-center wg-hero__title has-headline-font-family"><?php echo esc_html__( 'One connected studio for your whole creative world.', 'worldgraph-child' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-hero__summary"} -->
			<p class="has-text-align-center wg-hero__summary"><?php echo esc_html__( 'World Graph Studio brings worldbuilding, story development, AI-powered production, and asset management into one workspace built on WordPress. Bring your existing work with you, generate with the tools you choose, and export anytime. Your ideas, your assets, no credits needed.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons {"className":"wg-hero__actions","layout":{"type":"flex","justifyContent":"center"}} -->
			<div class="wp-block-buttons wg-hero__actions">
				<!-- wp:button {"className":"wg-button-primary"} -->
				<div class="wp-block-button wg-button-primary"><a class="wp-block-button__link wp-element-button" href="#overview-workflow"><?php echo esc_html__( 'See the workflow', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-outline wg-button-secondary"} -->
				<div class="wp-block-button is-style-outline wg-button-secondary"><a class="wp-block-button__link wp-element-button" href="#overview-import-export"><?php echo esc_html__( 'Import and export', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"overview-graph","backgroundColor":"charcoal","textColor":"warm-ivory","className":"wg-section wg-story-graph","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-story-graph has-warm-ivory-color has-charcoal-background-color has-text-color has-background" id="overview-graph">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'One connected world', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'The Story Graph keeps every element connected.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Instead of scattering prompts, files, and decisions across disconnected tools, World Graph Studio preserves the relationships between the people, places, scenes, shots, sounds, storyboards, and media that make up your project.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:columns {"align":"wide","className":"wg-grid wg-story-graph__grid"} -->
		<div class="wp-block-columns alignwide wg-grid wg-story-graph__grid">
			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"className":"wg-node","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-node">
					<!-- wp:heading {"level":3,"className":"wg-node__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-node__title has-headline-font-family"><?php echo esc_html__( 'People and places', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Characters, locations, props, and organizations carry reusable context wherever they appear.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"className":"wg-node","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-node">
					<!-- wp:heading {"level":3,"className":"wg-node__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-node__title has-headline-font-family"><?php echo esc_html__( 'Scenes and shots', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Storyboards, sounds, and shot lists remain connected to the scene and story behind them.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"className":"wg-node","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-node">
					<!-- wp:heading {"level":3,"className":"wg-node__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-node__title has-headline-font-family"><?php echo esc_html__( 'Media and decisions', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Generated media, editorial choices, and provenance stay linked to the records that give them meaning.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"overview-workflow","backgroundColor":"warm-ivory","textColor":"dark-espresso","className":"wg-section wg-workflow","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-workflow has-dark-espresso-color has-warm-ivory-background-color has-text-color has-background" id="overview-workflow">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'From idea to final edit', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'A durable workflow, start to finish.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Every stage of production reads and writes back to the same Story Graph, so nothing has to be rebuilt as the project moves forward.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-grid wg-workflow__steps","layout":{"type":"grid","minimumColumnWidth":"12rem"}} -->
		<div class="wp-block-group alignwide wg-grid wg-workflow__steps">
			<!-- wp:group {"className":"wg-step","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-step">
				<!-- wp:paragraph {"className":"wg-step__number"} -->
				<p class="wg-step__number"><?php echo esc_html__( '01', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"className":"wg-step__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-step__title has-headline-font-family"><?php echo esc_html__( 'Build or import', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Start a project in WordPress or bring in scripts, stories, images, and production data you already have.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-step","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-step">
				<!-- wp:paragraph {"className":"wg-step__number"} -->
				<p class="wg-step__number"><?php echo esc_html__( '02', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"className":"wg-step__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-step__title has-headline-font-family"><?php echo esc_html__( 'Develop the world', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Connect characters, locations, props, scenes, and shots, then search and analyze the graph with 50+ specialist agents.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-step","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-step">
				<!-- wp:paragraph {"className":"wg-step__number"} -->
				<p class="wg-step__number"><?php echo esc_html__( '03', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"className":"wg-step__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-step__title has-headline-font-family"><?php echo esc_html__( 'Generate with any tool', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Send work through supported Connections such as ComfyUI, VideoDraft, fal, ElevenLabs, Suno, Seedance 2.5 via third-party CyberBara, and OpenRouter, or use free local models.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-step","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-step">
				<!-- wp:paragraph {"className":"wg-step__number"} -->
				<p class="wg-step__number"><?php echo esc_html__( '04', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"className":"wg-step__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-step__title has-headline-font-family"><?php echo esc_html__( 'Manage and organize', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Generated media, footage, and editorial files stay stored beside their prompts, provenance, and story records.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-step","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-step">
				<!-- wp:paragraph {"className":"wg-step__number"} -->
				<p class="wg-step__number"><?php echo esc_html__( '05', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"className":"wg-step__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-step__title has-headline-font-family"><?php echo esc_html__( 'Export or roundtrip', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Send the work back out to writing, editing, and production tools whenever you are ready for the next stage.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"overview-import-export","backgroundColor":"blueprint-blue","textColor":"warm-ivory","className":"wg-section wg-import-export","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-import-export has-warm-ivory-color has-blueprint-blue-background-color has-text-color has-background" id="overview-import-export">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Bring it in. Send it out.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'You do not have to start over.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Import existing stories, scripts, images, storyboards, and production data, then export back out whenever you need the next tool in your pipeline.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-flow","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-flow">
			<!-- wp:paragraph {"align":"center","className":"wg-flow__label"} -->
			<p class="has-text-align-center wg-flow__label"><?php echo esc_html__( 'Import from', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:group {"className":"wg-flow__badges","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"center"}} -->
			<div class="wp-block-group wg-flow__badges">
				<!-- wp:paragraph {"className":"wg-chip"} --><p class="wg-chip"><?php echo esc_html__( 'Plain text, PDF, ePub, Doc', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip"} --><p class="wg-chip"><?php echo esc_html__( 'Final Draft &amp; Fountain', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip"} --><p class="wg-chip"><?php echo esc_html__( 'Character &amp; reference images', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip"} --><p class="wg-chip"><?php echo esc_html__( 'Descript storyboards', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip"} --><p class="wg-chip"><?php echo esc_html__( 'VideoDraft projects', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip"} --><p class="wg-chip"><?php echo esc_html__( 'Google Web Stories', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip"} --><p class="wg-chip"><?php echo esc_html__( 'Celtx projects', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip"} --><p class="wg-chip"><?php echo esc_html__( 'Premiere, Final Cut, Avid, Resolve EDLs', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip"} --><p class="wg-chip"><?php echo esc_html__( 'Unity &amp; real-time game data', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:paragraph {"align":"center","className":"wg-flow__arrow"} -->
			<p class="has-text-align-center wg-flow__arrow" aria-hidden="true">&#8595;</p>
			<!-- /wp:paragraph -->

			<!-- wp:group {"className":"wg-flow__center","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-flow__center">
				<!-- wp:heading {"level":3,"textAlign":"center","className":"wg-flow__center-title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading has-text-align-center wg-flow__center-title has-headline-font-family"><?php echo esc_html__( 'Story Graph', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph {"align":"center"} -->
				<p class="has-text-align-center"><?php echo esc_html__( 'One canonical, connected project record inside WordPress.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:paragraph {"align":"center","className":"wg-flow__arrow"} -->
			<p class="has-text-align-center wg-flow__arrow" aria-hidden="true">&#8595;</p>
			<!-- /wp:paragraph -->

			<!-- wp:group {"className":"wg-flow__badges","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"center"}} -->
			<div class="wp-block-group wg-flow__badges">
				<!-- wp:paragraph {"className":"wg-chip wg-chip--export"} --><p class="wg-chip wg-chip--export"><?php echo esc_html__( 'Screenplay &amp; storyboard Markdown', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip wg-chip--export"} --><p class="wg-chip wg-chip--export"><?php echo esc_html__( 'World Graph Studio JSON', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip wg-chip--export"} --><p class="wg-chip wg-chip--export"><?php echo esc_html__( 'VideoDraft synchronization', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip wg-chip--export"} --><p class="wg-chip wg-chip--export"><?php echo esc_html__( 'CMX &amp; XML EDLs for NLEs', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip wg-chip--export"} --><p class="wg-chip wg-chip--export"><?php echo esc_html__( 'Celtx handoff', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"wg-chip wg-chip--export"} --><p class="wg-chip wg-chip--export"><?php echo esc_html__( 'REST API for any tool you connect', 'worldgraph-child' ); ?></p><!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:paragraph {"align":"center","className":"wg-flow__label"} -->
			<p class="has-text-align-center wg-flow__label"><?php echo esc_html__( 'Export to', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-note wg-import-export__note","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-note wg-import-export__note has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background">
			<!-- wp:paragraph {"align":"center"} -->
			<p class="has-text-align-center"><?php echo esc_html__( 'Your project structure does not change when a format or provider does. Import adapters translate outside files into the Story Graph; exporters create portable versions for the next tool in your pipeline.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"overview-metered","backgroundColor":"charcoal","textColor":"warm-ivory","className":"wg-section wg-not-metered","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-not-metered has-warm-ivory-color has-charcoal-background-color has-text-color has-background" id="overview-metered">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Open by design', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Your creativity is not metered.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-grid wg-proof-grid","layout":{"type":"grid","minimumColumnWidth":"12rem"}} -->
		<div class="wp-block-group alignwide wg-grid wg-proof-grid">
			<!-- wp:group {"className":"wg-stat","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-stat">
				<!-- wp:paragraph {"align":"center","className":"wg-stat__value"} -->
				<p class="has-text-align-center wg-stat__value"><strong><?php echo esc_html__( 'GPL v2+', 'worldgraph-child' ); ?></strong></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"align":"center","className":"wg-stat__label"} -->
				<p class="has-text-align-center wg-stat__label"><?php echo esc_html__( 'Free and open source', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-stat","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-stat">
				<!-- wp:paragraph {"align":"center","className":"wg-stat__value"} -->
				<p class="has-text-align-center wg-stat__value"><strong><?php echo esc_html__( 'Self-hosted', 'worldgraph-child' ); ?></strong></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"align":"center","className":"wg-stat__label"} -->
				<p class="has-text-align-center wg-stat__label"><?php echo esc_html__( 'Run it in an environment you control', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-stat","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-stat">
				<!-- wp:paragraph {"align":"center","className":"wg-stat__value"} -->
				<p class="has-text-align-center wg-stat__value"><strong><?php echo esc_html__( 'No credits', 'worldgraph-child' ); ?></strong></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"align":"center","className":"wg-stat__label"} -->
				<p class="has-text-align-center wg-stat__label"><?php echo esc_html__( 'No platform meter on local workflows', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-stat","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-stat">
				<!-- wp:paragraph {"align":"center","className":"wg-stat__value"} -->
				<p class="has-text-align-center wg-stat__value"><strong><?php echo esc_html__( 'No lock-in', 'worldgraph-child' ); ?></strong></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"align":"center","className":"wg-stat__label"} -->
				<p class="has-text-align-center wg-stat__label"><?php echo esc_html__( 'Your work exports whenever you need it', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","backgroundColor":"charcoal","textColor":"warm-ivory","className":"wg-section wg-cta","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-cta has-warm-ivory-color has-charcoal-background-color has-text-color has-background">
		<!-- wp:group {"align":"wide","className":"wg-cta__inner","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-cta__inner">
			<!-- wp:heading {"textAlign":"center","className":"wg-cta__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-cta__title has-headline-font-family"><?php echo esc_html__( 'Build worlds. Connect ideas. Generate anything. No credits needed.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:buttons {"className":"wg-cta__actions","layout":{"type":"flex","justifyContent":"center"}} -->
			<div class="wp-block-buttons wg-cta__actions">
				<!-- wp:button {"className":"wg-button-primary"} -->
				<div class="wp-block-button wg-button-primary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( admin_url( 'admin.php?page=worldgraph' ) ); ?>"><?php echo esc_html__( 'Open Studio', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-outline wg-button-secondary"} -->
				<div class="wp-block-button is-style-outline wg-button-secondary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/#integrations' ) ); ?>"><?php echo esc_html__( 'See the full Integration Catalog', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
