<?php
/**
 * Title: World Graph Studio about page
 * Slug: worldgraph-child/page-about
 * Categories: featured, pages
 * Keywords: about, origin, story, wordpress, open source
 * Block Types: core/post-content
 * Post Types: page
 * Viewport Width: 1440
 * Description: The first-person story behind World Graph Studio and its promise to creators.
 *
 * @package WorldGraphChild
 */
?>

<!-- wp:group {"align":"full","className":"wg-home wg-about","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull wg-home wg-about">
	<!-- wp:group {"tagName":"section","align":"full","anchor":"about-top","backgroundColor":"warm-ivory","textColor":"dark-espresso","className":"wg-section wg-hero","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-hero has-dark-espresso-color has-warm-ivory-background-color has-text-color has-background" id="about-top">
		<!-- wp:group {"align":"wide","className":"wg-hero__inner","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-hero__inner">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'About World Graph Studio', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","level":1,"className":"wg-hero__title","fontFamily":"headline"} -->
			<h1 class="wp-block-heading has-text-align-center wg-hero__title has-headline-font-family"><?php echo esc_html__( 'Why does this project exist?', 'worldgraph-child' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-hero__summary"} -->
			<p class="has-text-align-center wg-hero__summary"><?php echo esc_html__( 'World Graph Studio grew from a creator’s need for an open production workspace that keeps story—not tools, trends, or platforms—at the center.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","backgroundColor":"charcoal","textColor":"warm-ivory","className":"wg-section wg-about__origin","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-about__origin has-warm-ivory-color has-charcoal-background-color has-text-color has-background">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'The creative starting point', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Built from many disciplines. Focused on story.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-grid wg-about__origin-grid","layout":{"type":"grid","minimumColumnWidth":"20rem"}} -->
		<div class="wp-block-group alignwide wg-grid wg-about__origin-grid">
			<!-- wp:group {"className":"wg-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'A multifaceted creative career', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Hi! I am a multifaceted creative professional. At various points in my careers, I have been a digital artist, writer, filmmaker, video producer, web developer, production manager, camera operator, and about 20 other things.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:group {"className":"wg-card","layout":{"type":"constrained"}} -->
			<div class="wp-block-group wg-card">
				<!-- wp:heading {"level":3,"className":"wg-card__title","fontFamily":"headline"} -->
				<h3 class="wp-block-heading wg-card__title has-headline-font-family"><?php echo esc_html__( 'The missing center', 'worldgraph-child' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'I discovered AI filmmaking through friends, including the Machine Cinema group, and was intrigued. But there was a lot missing from the AI gold rush and the never-ending FOMO stream of new models and tools. Most of all, I saw a missing focus on story.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","backgroundColor":"blueprint-blue","textColor":"warm-ivory","className":"wg-section","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section has-warm-ivory-color has-blueprint-blue-background-color has-text-color has-background">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Why WordPress', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'A platform that already understands content.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-about__copy","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-about__copy">
			<!-- wp:paragraph -->
			<p><?php echo esc_html__( 'WordPress is a web platform used across the world. There are more WordPress websites than any other kind of website; it is a juggernaut. And it is good at understanding content—it is a content management system.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph -->
			<p><?php echo esc_html__( 'World Graph Studio builds on that foundation with Story Graph-aware agents and schema-described WordPress Abilities. Through a compatible MCP Adapter, assistants can use those permission-checked abilities to import and develop stories, review projects, plan generation, inspect assets, and exchange editorial data without receiving unrestricted access to the site.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","backgroundColor":"warm-ivory","textColor":"dark-espresso","className":"wg-section","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section has-dark-espresso-color has-warm-ivory-background-color has-text-color has-background">
		<!-- wp:group {"align":"wide","className":"wg-section__header","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-section__header">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'Why a Story Graph', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-section__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-section__title has-headline-font-family"><?php echo esc_html__( 'Where human stories and machine context meet.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"align":"wide","className":"wg-about__copy","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-about__copy">
			<!-- wp:paragraph -->
			<p><?php echo esc_html__( 'Story graphs and plot-analysis graphs have been around for a while—the 1950s? They are not something I invented. I have enjoyed working in this space for a long time, and it is pretty much the perfect intersection between how we humans understand the world and its stories and how AI perceives ideas and concepts.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->

	<!-- wp:group {"tagName":"section","align":"full","backgroundColor":"charcoal","textColor":"warm-ivory","className":"wg-section wg-cta","layout":{"type":"constrained"}} -->
	<section class="wp-block-group alignfull wg-section wg-cta has-warm-ivory-color has-charcoal-background-color has-text-color has-background">
		<!-- wp:group {"align":"wide","className":"wg-cta__inner","layout":{"type":"constrained"}} -->
		<div class="wp-block-group alignwide wg-cta__inner">
			<!-- wp:paragraph {"align":"center","className":"wg-eyebrow"} -->
			<p class="has-text-align-center wg-eyebrow"><?php echo esc_html__( 'The original experiment', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"textAlign":"center","className":"wg-cta__title","fontFamily":"headline"} -->
			<h2 class="wp-block-heading has-text-align-center wg-cta__title has-headline-font-family"><?php echo esc_html__( 'Keep it open. Keep the story yours.', 'worldgraph-child' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","className":"wg-cta__summary"} -->
			<p class="has-text-align-center wg-cta__summary"><?php echo esc_html__( 'My original idea was simply to marry my project sketches in WordPress with ComfyUI, see what I could write and generate for free on my crappy desktop, run it through some Story Graph analysis tools, and see what happened. Pretty quickly, I found that I needed a lot more tools, so I built them in.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:group {"align":"wide","className":"wg-note wg-about__promise","layout":{"type":"constrained"}} -->
			<div class="wp-block-group alignwide wg-note wg-about__promise">
				<!-- wp:paragraph {"align":"center"} -->
				<p class="has-text-align-center"><?php echo esc_html__( 'The idea of keeping it open—get in and get out for free—was a core part of the effort. I promise never to change that.', 'worldgraph-child' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->

			<!-- wp:buttons {"className":"wg-cta__actions","layout":{"type":"flex","justifyContent":"center"}} -->
			<div class="wp-block-buttons wg-cta__actions">
				<!-- wp:button {"className":"wg-button-primary"} -->
				<div class="wp-block-button wg-button-primary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/overview/' ) ); ?>"><?php echo esc_html__( 'See the Overview', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-outline wg-button-secondary"} -->
				<div class="wp-block-button is-style-outline wg-button-secondary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( 'https://github.com/videohead/worldgraphstudio' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open Studio', 'worldgraph-child' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</section>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
