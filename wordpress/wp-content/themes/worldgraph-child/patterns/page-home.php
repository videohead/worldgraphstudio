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
			<p class="has-text-align-center wg-hero__summary"><?php echo esc_html__( 'An open-source, self-hosted studio for worldbuilding, connected storytelling, and AI-powered creative production. Import and develop stories, build worlds, generate media, plan productions, and manage your assets in one connected workspace.', 'worldgraph-child' ); ?></p>
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
					<p><?php echo esc_html__( 'Unify your story ideas in one web-based platform with text, images, video all serving to move the story and your production goals forward. Unify disparate tools and workflows with one story-centered source of truth.', 'worldgraph-child' ); ?></p>
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
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Persistent Creative Memory', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Your Story Never Forgets.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'World Graph Studio stores your story as structured knowledge. Characters, locations, props, scenes, storyboards, assets, and production notes remain available to AI assistants through the World Graph and AI-powered memory retrieval.', 'worldgraph-child' ); ?></p>
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
					<p><?php echo esc_html__( 'Your story elements (characters, locations, props, dialog and sound) are linked through the prompts used to create them, across one or many generative tools and budgets.', 'worldgraph-child' ); ?></p>
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
			<p class="has-text-align-center"><?php echo esc_html__( 'The result is a creative workspace that understands not only what your assets are, but what they mean. Project records, relationships, permissions, and media stay in the application you control while optional services connect around that core.', 'worldgraph-child' ); ?></p>
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
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'A connected creative workspace for all of your tools.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'World Graph Studio gives your stories and production assets a unified home that can stay private or become a website you share. You decide where to integrate AI in your creative workflow - advisor, generator, or creative consultant.', 'worldgraph-child' ); ?></p>
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
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Agents and MCP workflows', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Use 50+ Story Graph-aware specialist profiles inside WordPress, or connect a compatible external MCP client to permission-checked tools for story import, graph editing, project review, generation planning, asset review, and EDL exchange.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"backgroundColor":"dark-espresso","textColor":"warm-ivory","className":"wg-card wg-capability","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-capability has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Budget and Generate', 'worldgraph-child' ); ?></h3>
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
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Use the right tool for each part of the work', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Bring work in.', 'worldgraph-child' ); ?><br><?php echo esc_html__( 'Send it out.', 'worldgraph-child' ); ?><br><?php echo esc_html__( 'Connect what comes next.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-section__summary"} -->
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Bring existing work into the shared structure, send specific jobs to outside tools, and return useful results to the project. The tools may change; the Project, Characters, Locations, Scenes, Shots, and Assets stay connected.', 'worldgraph-child' ); ?></p>
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
			<tr><td><a href="<?php echo esc_url( 'https://github.com/videohead/storyos/blob/main/about/plugins/SEEDANCE.md' ); ?>"><?php echo esc_html__( 'Seedance 2.5 via CyberBara', 'worldgraph-child' ); ?></a></td><td><?php echo esc_html__( 'Third-party CyberBara REST API', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Fixed text-to-video and image-to-video Templates, authorized reference-image uploads, task polling, and imported final videos', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'Higgsfield', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'REST API + hosted MCP discovery', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Soul text-to-image plus Higgsfield DoP and Kling image-to-video generation', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'VideoDraft', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Hosted MCP', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Discovered image, video, and audio Templates with media import', 'worldgraph-child' ); ?></td></tr>
			<tr><td><?php echo esc_html__( 'OpenRouter', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'REST API', 'worldgraph-child' ); ?></td><td><?php echo esc_html__( 'Text-to-video, image-to-video, and reference-to-video jobs across any OpenRouter video model', 'worldgraph-child' ); ?></td></tr>
			</tbody></table></figure>
			<!-- /wp:table -->

			<!-- wp:paragraph {"className":"wg-integration-note"} -->
			<p class="wg-integration-note"><strong><?php echo esc_html__( 'Use your existing tools:', 'worldgraph-child' ); ?></strong> <?php echo esc_html__( 'External content generators serve and enhance your capabilities. Stop wandering through complex node-based interfaces and work with your text to generate the best possible outcomes.', 'worldgraph-child' ); ?></p>
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
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Creative technology changes quickly. World Graph Studio is designed to evolve with it, so formats, provider Connections, and specialist agents can grow around the Story Graph without replacing it.', 'worldgraph-child' ); ?></p>
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
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Exchange creative data', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Import adapters translate external files and services into the shared Story Graph. Exporters create portable versions of live production data for the next tool in your workflow.', 'worldgraph-child' ); ?></p>
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
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Add or replace Connections', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Use supported local or hosted services and change providers without rebuilding the project. Your Story Graph and Connection records remain stable.', 'worldgraph-child' ); ?></p>
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
					<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Expand your specialist team', 'worldgraph-child' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph -->
					<p><?php echo esc_html__( 'Start with more than 50 focused creative and production roles, then add or customize portable specialist profiles that share the same project context and permissions.', 'worldgraph-child' ); ?></p>
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
				<p><?php echo esc_html__( 'Create the project directly in our tools, or import plain text or a screenplay from Final Draft, Celtx, or Fountain. AI maps your story to a user-controlled structured graph.', 'worldgraph-child' ); ?></p>
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
			<p class="has-text-align-center wg-section__summary"><?php echo esc_html__( 'Your creativity is not metered. Your content is not trapped. Your workflow is not limited. You decide where WordPress runs, which services it can access, and what stays private or becomes public.', 'worldgraph-child' ); ?></p>
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
			<p><strong><?php echo esc_html__( 'No World Graph Studio credits.', 'worldgraph-child' ); ?></strong> <?php echo esc_html__( 'Local and open-model workflows do not require a platform credit balance. Optional third-party providers may still have their own prices, quotas, licenses, moderation policies, and terms.', 'worldgraph-child' ); ?></p>
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
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Writers', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Keep structured story context close while drafting, reviewing, and revising.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

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
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Game Creators', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Design worlds, characters, locations, props, and narrative relationships.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-card wg-audience-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-audience-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Worldbuilders', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Connect lore, histories, people, places, objects, and cultures in one evolving world.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-card wg-audience-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-audience-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Showrunners', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Track characters, episodes, arcs, continuity, and production decisions across a series.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-card wg-audience-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-audience-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Narrative Teams', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Share durable creative context so collaborators and AI assistants work from the same story knowledge.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-card wg-audience-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card wg-audience-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'Production Studios', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Keep projects, production notes, media, and handoffs connected from development through delivery.', 'worldgraph-child' ); ?></p>
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
			<p class="has-text-align-center wg-cta__summary"><?php echo esc_html__( 'One studio for the world behind your work. Start with a portable Story Graph you control, then add or replace formats, provider Connections, and specialist agents as the work evolves.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons {"className":"wg-cta__actions","layout":{"type":"flex","justifyContent":"center"}} -->
			<div class="wp-block-buttons wg-cta__actions">
				<!-- wp:button {"className":"wg-button-primary"} -->
				<div class="wp-block-button wg-button-primary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( 'https://github.com/videohead/worldgraphstudio' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open Studio', 'worldgraph-child' ); ?></a></div>
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
