<?php
/**
 * Title: World Graph Studio home page
 * Slug: worldgraph-child/page-home
 * Categories: featured, pages
 * Keywords: home, landing, studio, story graph
 * Block Types: core/post-content
 * Post Types: page
 * Viewport Width: 1440
 * Description: A complete World Graph Studio landing page with product positioning, delivered interchange, extension surfaces, workflow, creator-control principles, audiences, and calls to action.
 *
 * @package WorldGraphChild
 */
?>

<!-- wp:group {"align":"full","className":"wg-home","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull wg-home">
	<!-- wp:group {"tagName":"section","align":"full","anchor":"top","backgroundColor":"warm-ivory","textColor":"dark-espresso","className":"wg-section wg-hero","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-hero has-dark-espresso-color has-warm-ivory-background-color has-text-color has-background" id="top">
		<!-- wp:group {"align":"wide","className":"wg-hero__inner","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-hero__inner">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'World Graph Studio', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","level":1,"className":"wg-hero__title","fontFamily":"headline"} -->
			<h1 class="wp-block-heading has-text-align-center wg-hero__title has-headline-font-family"><?php echo esc_html__( 'Your ideas. Your assets.', 'worldgraph-child' ); ?><br><?php echo esc_html__( 'No credits needed.', 'worldgraph-child' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-hero__summary"} -->
			<p class="has-text-align-center wg-hero__summary"><?php echo esc_html__( 'The extensible open-source studio for worldbuilding, storytelling, and AI-powered creative production. Import scripts, connect the tools you choose, and grow a team of 50+ specialist agents without any credits needed for local models.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons {"className":"wg-hero__actions","layout":{"type":"flex","justifyContent":"center"}} -->
			<div class="wp-block-buttons wg-hero__actions">
				<!-- wp:button {"className":"wg-button-primary"} -->
				<div class="wp-block-button wg-button-primary"><a class="wp-block-button__link wp-element-button" href="#story-graph"><?php echo esc_html__( 'Explore the Story Graph', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-outline wg-button-secondary"} -->
				<div class="wp-block-button is-style-outline wg-button-secondary"><a class="wp-block-button__link wp-element-button" href="#capabilities"><?php echo esc_html__( 'See what ships', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

			<!-- wp:group {"align":"wide","className":"wg-grid wg-proof-grid","layout":{"type":"grid","minimumColumnWidth":"12rem"}} -->
			<div class="wp-block-group alignwide wg-grid wg-proof-grid">
				<!-- wp:group {"className":"wg-stat","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-stat">
					<!-- wp:paragraph {"align":"center","className":"wg-stat__value"} -->
					<p class="has-text-align-center wg-stat__value"><strong><?php echo esc_html__( 'Free', 'worldgraph-child' ); ?></strong></p>
					<!-- /wp:paragraph -->
					<!-- wp:paragraph {"align":"center","className":"wg-stat__label"} -->
					<p class="has-text-align-center wg-stat__label"><?php echo esc_html__( 'Open-source software', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"className":"wg-stat","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-stat">
					<!-- wp:paragraph {"align":"center","className":"wg-stat__value"} -->
					<p class="has-text-align-center wg-stat__value"><strong><?php echo esc_html__( 'WordPress', 'worldgraph-child' ); ?></strong></p>
					<!-- /wp:paragraph -->
					<!-- wp:paragraph {"align":"center","className":"wg-stat__label"} -->
					<p class="has-text-align-center wg-stat__label"><?php echo esc_html__( 'Self-hosted foundation', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"className":"wg-stat","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-stat">
					<!-- wp:paragraph {"align":"center","className":"wg-stat__value"} -->
					<p class="has-text-align-center wg-stat__value"><strong><?php echo esc_html__( '15 + 9', 'worldgraph-child' ); ?></strong></p>
					<!-- /wp:paragraph -->
					<!-- wp:paragraph {"align":"center","className":"wg-stat__label"} -->
					<p class="has-text-align-center wg-stat__label"><?php echo esc_html__( 'Content types and taxonomies', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->

				<!-- wp:group {"className":"wg-stat","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-stat">
					<!-- wp:paragraph {"align":"center","className":"wg-stat__value"} -->
					<p class="has-text-align-center wg-stat__value"><strong><?php echo esc_html__( '50+', 'worldgraph-child' ); ?></strong></p>
					<!-- /wp:paragraph -->
					<!-- wp:paragraph {"align":"center","className":"wg-stat__label"} -->
					<p class="has-text-align-center wg-stat__label"><?php echo esc_html__( 'Extensible specialist agents', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","backgroundColor":"charcoal","textColor":"warm-ivory","className":"wg-section wg-problem-solution","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-problem-solution has-warm-ivory-color has-charcoal-background-color has-text-color has-background">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'From fragments to context', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Keep the story connected from idea to edit.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:columns {"align":"wide","className":"wg-grid wg-problem-solution__grid"} -->
		<div class="wp-block-columns alignwide wg-grid wg-problem-solution__grid">
			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-card wg-card--problem","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-card wg-card--problem has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background">
					<!-- wp:paragraph {"className":"wg-card__label"} -->
					<p class="wg-card__label"><?php echo esc_html__( 'The problem', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->

					<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Storytelling workflows are fragmented.', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Notes, scripts, prompts, assets, and editorial decisions drift into separate tools. The context that gives each choice meaning gets lost between story development and production.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"backgroundColor":"blueprint-blue","textColor":"warm-ivory","className":"wg-card wg-card--solution","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-card wg-card--solution has-warm-ivory-color has-blueprint-blue-background-color has-text-color has-background">
					<!-- wp:paragraph {"className":"wg-card__label"} -->
					<p class="wg-card__label"><?php echo esc_html__( 'The solution', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->

					<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'One connected creative system.', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'World Graph Studio connects storytelling, generation, production, and editorial workflows through the Story Graph, so ideas and assets remain connected, portable, and under creator control.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"story-graph","backgroundColor":"warm-ivory","textColor":"dark-espresso","className":"wg-section wg-story-graph","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-story-graph has-dark-espresso-color has-warm-ivory-background-color has-text-color has-background" id="story-graph">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Story first', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'The story graph is the source of truth.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Instead of treating a story as a pile of documents, World Graph Studio represents narrative, production, asset, and editorial information as structured elements connected by explicit relationships.', 'worldgraph-child' ); ?></p>
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
					<h3 class="wp-block-heading wg-node__title has-headline-font-family"><?php echo esc_html__( 'World', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Projects, story worlds, characters, locations, props, and organizations establish reusable context.', 'worldgraph-child' ); ?></p>
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
					<h3 class="wp-block-heading wg-node__title has-headline-font-family"><?php echo esc_html__( 'Story', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Episodes, scenes, shots, planned sounds, and storyboard frames carry the narrative into production planning.', 'worldgraph-child' ); ?></p>
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
					<h3 class="wp-block-heading wg-node__title has-headline-font-family"><?php echo esc_html__( 'Production', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Assets, editorial records, generation templates, and provider connections remain linked to the records that give them meaning.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->

		<!-- wp:group {"align":"wide","backgroundColor":"blueprint-blue","textColor":"warm-ivory","className":"wg-note wg-story-graph__note","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-note wg-story-graph__note has-warm-ivory-color has-blueprint-blue-background-color has-text-color has-background">
			<!-- wp:paragraph {"align":"center"} -->
			<p class="has-text-align-center"><?php echo esc_html__( 'Project records, relationships, permissions, media, and APIs stay in the application you control. Optional services connect around that core; they do not replace it.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"capabilities","backgroundColor":"charcoal","textColor":"warm-ivory","className":"wg-section wg-capabilities","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-capabilities has-warm-ivory-color has-charcoal-background-color has-text-color has-background" id="capabilities">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Delivered today', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'A connected creative workspace that ships now.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Core story and production planning work without an AI or generation connection. Extensibly connect Word Graph Studio to a wide array of resources for supplementing your story and its production, without sacrificing user control or distracting you from building compelling stories.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-grid wg-capability-grid","layout":{"type":"grid","minimumColumnWidth":"18rem"}} -->
		<div class="wp-block-group alignwide wg-grid wg-capability-grid">
			<!-- wp:group {"backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-card wg-capability","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-capability has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Worldbuilding and planning', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Develop worlds, characters, locations, props, scenes, shots, sounds, storyboards, assets, and editorial records as connected content.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-card wg-capability","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-capability has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Story intelligence', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Search Story Graph records, inspect relationship analytics, and run local continuity checks. Configured AI can support broader contextual review.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-card wg-capability","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-capability has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( '50+ extensible agents', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Use Story Graph-aware chat, analysis, drafting, and more than 50 specialist agent profiles inside WordPress. Add focused roles through portable profile files; suggestions remain human-directed.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-card wg-capability","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-capability has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Generation and provenance', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Use connections to other services including local generation to manage your production and asset generation workflow. Build one story, connect it to multiple generative tools including free local generators.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-card wg-capability","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-capability has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Production and editorial', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Create shot lists, storyboard sequences, production views, asset records, and editorial handoffs without separating them from story context.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-card wg-capability","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-capability has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Practical interchange', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Work within your preferred storyboarding, script writing, and idea platforms. Seamlessly integrate your story into one location.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-note wg-capabilities__boundary","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-note wg-capabilities__boundary">
			<!-- wp:paragraph {"align":"center"} -->
			<p class="has-text-align-center"><strong><?php echo esc_html__( 'Optional by design.', 'worldgraph-child' ); ?></strong> <?php echo esc_html__( 'AI and generation features require a configured compatible service. Provider pricing, quotas, licenses, and availability still apply. AI responses are suggestions; you decide what is saved or published.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"integrations","backgroundColor":"warm-ivory","textColor":"dark-espresso","className":"wg-section wg-integrations","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-integrations has-dark-espresso-color has-warm-ivory-background-color has-text-color has-background" id="integrations">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Integration catalog', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Bring work in.', 'worldgraph-child' ); ?><br><?php echo esc_html__( 'Send it out.', 'worldgraph-child' ); ?><br><?php echo esc_html__( 'Connect what comes next.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Formats, synchronization plugins, generation Connections, AI backends, and extension surfaces each have a defined role around the Story Graph.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-integration-stack","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-integration-stack">
			<!-- wp:heading {"level":3,"fontFamily":"headline"} -->
			<h3 class="wp-block-heading has-headline-font-family"><?php echo esc_html__( 'Interchange, synchronization, and bundled utilities', 'worldgraph-child' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:table {"className":"is-style-stripes wg-integration-table"} -->
			<figure class="wp-block-table is-style-stripes wg-integration-table"><table><thead><tr><th scope="col"><?php echo esc_html__( 'Integration', 'worldgraph-child' ); ?></th><th scope="col"><?php echo esc_html__( 'Surface', 'worldgraph-child' ); ?></th><th scope="col"><?php echo esc_html__( 'Direction or output', 'worldgraph-child' ); ?></th></tr></thead><tbody>
			<tr><td><?php echo esc_html__( 'World Graph Studio JSON + Markdown', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Core interchange', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'JSON in; screenplay and storyboard Markdown out', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'Final Draft FDX', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Bundled importer', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'FDX screenplay into the Story Graph', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'Fountain', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Bundled importer source', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Intended: Fountain into the Story Graph through FDX normalization', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'Celtx', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Bundled sync source', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Intended: supported Story Graph entities outbound to Celtx', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'VideoDraft Sync', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Bundled sync plugin', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Shared structural Project subset in both directions', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'Descript Exchange', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Bundled exchange source', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Intended: composition transcript in; bound Project audio/video media out', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'EDL Format Tools', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'PHP format library + admin scaffold', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'CMX and XML parsing, timecode, and format generation from clip arrays. Import and export to/from Final Cut Pro, DaVinci Resolve, Vegas, Adobe Premiere Pro.', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'Google Web Stories', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Connector source', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'No active runtime direction', 'worldgraph-child' ); ?></td></tr>
			</tbody></table></figure>
			<!-- /wp:table -->

			<!-- wp:heading {"level":3,"fontFamily":"headline"} -->
			<h3 class="wp-block-heading has-headline-font-family"><?php echo esc_html__( 'Executable generation Connection adapters', 'worldgraph-child' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:table {"className":"is-style-stripes wg-integration-table"} -->
			<figure class="wp-block-table is-style-stripes wg-integration-table"><table><thead><tr><th scope="col"><?php echo esc_html__( 'Connection', 'worldgraph-child' ); ?></th><th scope="col"><?php echo esc_html__( 'Transport', 'worldgraph-child' ); ?></th><th scope="col"><?php echo esc_html__( 'Delivered behavior', 'worldgraph-child' ); ?></th></tr></thead><tbody>
			<tr><td><?php echo esc_html__( 'ComfyUI', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Local HTTP / MCP / Comfy Cloud MCP', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Compatible Template-backed media workflows', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'fal', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'MCP', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Discovered text-to-image Templates and imported results', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'ElevenLabs', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'REST API', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Speech, dialogue, sound effects, music, and voice previews', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'Suno', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'REST API + MCP', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Prompt music, custom music, and lyrics', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'MidJourney', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'REST API + MCP', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Text-to-image Imagine Templates, task polling, and imported final images', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'Higgsfield', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'REST API + hosted MCP discovery', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Soul text-to-image plus Higgsfield DoP and Kling image-to-video generation', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'VideoDraft', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Hosted MCP', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Discovered image, video, and audio Templates with media import', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'OpenRouter', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'REST API', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Text-to-video, image-to-video, and reference-to-video jobs across any OpenRouter video model', 'worldgraph-child' ); ?></td></tr>
			</tbody></table></figure>
			<!-- /wp:table -->

			<!-- wp:paragraph {"className":"wg-integration-note"} -->
			<p class="wg-integration-note"><strong><?php echo esc_html__( 'How these surfaces fit:', 'worldgraph-child' ); ?></strong> <?php echo esc_html__( 'OpenAI-compatible, OpenAI, Anthropic, and Dual serve the AI Editor rather than media generation. The 50+ specialist agents extend separately through profile files that you can add or edit any time.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"extensibility","backgroundColor":"blueprint-blue","textColor":"warm-ivory","className":"wg-section wg-extensibility","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-extensibility has-warm-ivory-color has-blueprint-blue-background-color has-text-color has-background" id="extensibility">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Built to extend', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Grow the toolchain, not the project silo.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Your struggle should be in the story, the struggle between the protagonists and antagonists, not in the tools, toolchain, or battling with format converters. Stay focused on the work that you want to do and use new capabilities to explore new possibilitiles. Connect  to external tools whenever you want and use story tools however you like.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:columns {"align":"wide","className":"wg-grid wg-control-grid"} -->
		<div class="wp-block-columns alignwide wg-grid wg-control-grid">
			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"className":"wg-card wg-control-card","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-card wg-control-card">
					<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Manage Your Assets.', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Easily connect to your services with a simplfied workflow - no confusing nodes or parameters that you don\'t understand.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"className":"wg-card wg-control-card","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-card wg-control-card">
					<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Add a Connection', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Register provider metadata, a conditional loader, and setup choices through the filterable Connection adapter layer, then supply the provider-specific behavior the integration needs.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"className":"wg-card wg-control-card","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-card wg-control-card">
					<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Connect to Extensible Experts.', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Directors, producers, prop masters, hair styliists, and dramaturges are all featured as built-in experts to help you take your story to the next level.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"workflow","backgroundColor":"warm-ivory","textColor":"dark-espresso","className":"wg-section wg-workflow","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-workflow has-dark-espresso-color has-warm-ivory-background-color has-text-color has-background" id="workflow">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'A durable workflow', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Move from an idea to connected production.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-grid wg-workflow__steps","layout":{"type":"grid","minimumColumnWidth":"14rem"}} -->
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
				<p><?php echo esc_html__( 'Create the project in WordPress or bring in structured project and screenplay data through World Graph Studio JSON or Final Draft FDX.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-step","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-step">
				<!-- wp:paragraph {"className":"wg-step__number"} -->
				<p class="wg-step__number"><?php echo esc_html__( '02', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"className":"wg-step__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-step__title has-headline-font-family"><?php echo esc_html__( 'Review the context', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Search the graph, inspect relationships, check continuity, and invite a configured specialist agent to offer labeled suggestions.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-step","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-step">
				<!-- wp:paragraph {"className":"wg-step__number"} -->
				<p class="wg-step__number"><?php echo esc_html__( '03', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"className":"wg-step__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-step__title has-headline-font-family"><?php echo esc_html__( 'Make and organize', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Plan production, connect a supported generator when needed, and keep returned media beside its source and provenance.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-step","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-step">
				<!-- wp:paragraph {"className":"wg-step__number"} -->
				<p class="wg-step__number"><?php echo esc_html__( '04', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"className":"wg-step__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-step__title has-headline-font-family"><?php echo esc_html__( 'Exchange the work', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Take screenplay-style and storyboard views out as Markdown, synchronize the supported structural subset through VideoDraft, and reuse EDL format helpers while WordPress remains canonical.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"creative-control","backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-section wg-creative-control","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-creative-control has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background" id="creative-control">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Creator owned', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Creative control without a platform meter.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'World Graph Studio does not sell usage credits, require a World Graph Studio cloud, or make one model provider the owner of your project.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:columns {"align":"wide","className":"wg-grid wg-control-grid"} -->
		<div class="wp-block-columns alignwide wg-grid wg-control-grid">
			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"className":"wg-card wg-control-card","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-card wg-control-card">
					<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Choose the home', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Run WordPress in an environment you control, keep it private through your own configuration, or publish when you choose.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"className":"wg-card wg-control-card","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-card wg-control-card">
					<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Choose the connections', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Use supported local or hosted services, add provider types through Connection adapter hooks, and change providers without rebuilding the story graph.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"className":"wg-card wg-control-card","layout":{"type":"constrained"}} -->
				<div class="wp-block-group wg-card wg-control-card">
					<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Choose what becomes canon', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Specialist agents propose, analyze, and draft. Creators explicitly accept, revise, discard, save, generate, or publish.', 'worldgraph-child' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->

		<!-- wp:group {"align":"wide","backgroundColor":"sepia","textColor":"dark-espresso","className":"wg-note wg-provider-caveat","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-note wg-provider-caveat has-dark-espresso-color has-sepia-background-color has-text-color has-background">
			<!-- wp:paragraph -->
			<p><strong><?php echo esc_html__( 'Fully Extensible for free : ', 'worldgraph-child' ); ?></strong> <?php echo esc_html__( 'The tools are free and all agentic work can be completed on local GPU resources. No need to pay for anything, ever. Add agent experts and new models anytime.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","anchor":"audiences","backgroundColor":"warm-ivory","textColor":"dark-espresso","className":"wg-section wg-audiences","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-audiences has-dark-espresso-color has-warm-ivory-background-color has-text-color has-background" id="audiences">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'One studio, many disciplines', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'For people building connected stories.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-grid wg-audience-grid","layout":{"type":"grid","minimumColumnWidth":"12rem"}} -->
		<div class="wp-block-group alignwide wg-grid wg-audience-grid">
			<!-- wp:group {"className":"wg-card wg-audience-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-audience-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Filmmakers', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Develop scripts, coverage, storyboards, shots, assets, and editorial handoffs.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-card wg-audience-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-audience-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Game creators', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Design worlds, characters, locations, props, and narrative relationships.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-card wg-audience-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-audience-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Scriptwriters', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Keep structured story context close while writing, reviewing, and revising.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-card wg-audience-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-audience-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Video producers', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Organize scenes, sequences, sounds, media, and production metadata.', 'worldgraph-child' ); ?></p>
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

			<!-- wp:paragraph {"align":"center","className":"wg-cta__summary"} -->
			<p class="has-text-align-center wg-cta__summary"><?php echo esc_html__( 'Start with a portable Story Graph you control. Add or replace formats, provider Connections, and specialist agents as the work evolves.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons {"className":"wg-cta__actions","layout":{"type":"flex","justifyContent":"center"}} -->
			<div class="wp-block-buttons wg-cta__actions">
				<!-- wp:button {"className":"wg-button-primary"} -->
				<div class="wp-block-button wg-button-primary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( admin_url( 'admin.php?page=worldgraph' ) ); ?>"><?php echo esc_html__( 'Open Studio', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-outline wg-button-secondary"} -->
				<div class="wp-block-button is-style-outline wg-button-secondary"><a class="wp-block-button__link wp-element-button" href="#top"><?php echo esc_html__( 'Back to top', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
