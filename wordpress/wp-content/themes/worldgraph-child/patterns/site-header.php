<?php
/**
 * Title: World Graph Studio site header
 * Slug: worldgraph-child/site-header
 * Categories: header
 * Block Types: core/template-part/header
 * Inserter: no
 *
 * @package WorldGraphChild
 */
?>
<!-- wp:group {"align":"full","backgroundColor":"dark-espresso","textColor":"warm-ivory","style":{"border":{"bottom":{"color":"var:preset|color|sepia","style":"solid","width":"1px"}},"spacing":{"margin":{"top":"0"},"padding":{"bottom":"20px","left":"30px","right":"30px","top":"20px"}}},"className":"wg-site-header","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull wg-site-header has-warm-ivory-color has-dark-espresso-background-color has-text-color has-background" style="border-bottom-color:var(--wp--preset--color--sepia);border-bottom-style:solid;border-bottom-width:1px;margin-top:0;padding-top:20px;padding-right:30px;padding-bottom:20px;padding-left:30px">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"24px"}},"className":"wg-site-header__inner","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide wg-site-header__inner">
		<!-- wp:group {"style":{"spacing":{"blockGap":"2px"}},"className":"wg-brand-lockup","layout":{"type":"constrained"}} -->
		<div class="wp-block-group wg-brand-lockup">
			<!-- wp:paragraph {"className":"wg-wordmark"} -->
			<p class="wg-wordmark"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html_x( 'World Graph Studio', 'Product wordmark in the site header.', 'worldgraph-child' ); ?></a></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontSize":"0.82rem","letterSpacing":"0.02em"}}} -->
			<p style="font-size:0.82rem;letter-spacing:0.02em"><?php echo esc_html_x( 'Your ideas. Your assets. No credits needed.', 'Site tagline in the header.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"18px"}},"className":"wg-header-actions","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"right"}} -->
		<div class="wp-block-group wg-header-actions">
			<!-- wp:navigation {"textColor":"warm-ivory","overlayBackgroundColor":"dark-espresso","overlayTextColor":"warm-ivory","style":{"spacing":{"blockGap":"18px"},"typography":{"fontSize":"0.88rem"}},"layout":{"type":"flex","justifyContent":"right"},"ariaLabel":"<?php echo esc_attr_x( 'World Graph Studio sections', 'Header navigation label.', 'worldgraph-child' ); ?>"} -->
				<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'Overview', 'Header navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( home_url( '/overview/' ) ); ?>","kind":"custom"} /-->
				<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'Story Graph', 'Header navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( home_url( '/#story-graph' ) ); ?>","kind":"custom"} /-->
				<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'Capabilities', 'Header navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( home_url( '/#capabilities' ) ); ?>","kind":"custom"} /-->
				<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'Integrations', 'Header navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( home_url( '/#integrations' ) ); ?>","kind":"custom"} /-->
				<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'Extend', 'Header navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( home_url( '/#extensibility' ) ); ?>","kind":"custom"} /-->
				<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'Creative Control', 'Header navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( home_url( '/#creative-control' ) ); ?>","kind":"custom"} /-->
			<!-- /wp:navigation -->

			<!-- wp:button {"fontFamily":"headline","fontSize":"small"} -->
			<div class="wp-block-button has-custom-font-size has-small-font-size has-headline-font-family"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( admin_url( 'admin.php?page=worldgraph' ) ); ?>"><?php echo esc_html_x( 'Open Studio', 'Header call-to-action label.', 'worldgraph-child' ); ?></a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
