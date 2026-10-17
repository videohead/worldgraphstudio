<?php
/**
 * Title: World Graph Studio site footer
 * Slug: worldgraph-child/site-footer
 * Categories: footer
 * Block Types: core/template-part/footer
 * Inserter: no
 *
 * @package WorldGraphChild
 */
?>
<!-- wp:group {"align":"full","backgroundColor":"charcoal","textColor":"warm-ivory","style":{"border":{"top":{"color":"var:preset|color|sepia","style":"solid","width":"1px"}},"spacing":{"margin":{"top":"0"},"padding":{"bottom":"36px","left":"30px","right":"30px","top":"36px"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-warm-ivory-color has-charcoal-background-color has-text-color has-background" style="border-top-color:var(--wp--preset--color--sepia);border-top-style:solid;border-top-width:1px;margin-top:0;padding-top:36px;padding-right:30px;padding-bottom:36px;padding-left:30px">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"24px"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"spacing":{"blockGap":"8px"}},"layout":{"type":"constrained"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"fontFamily":"headline","fontSize":"large"} -->
			<p class="has-headline-font-family has-large-font-size"><?php echo esc_html_x( 'Build worlds. Connect ideas. Generate anything. No credits needed.', 'Site footer statement.', 'worldgraph-child' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontSize":"0.82rem"}}} -->
			<p style="font-size:0.82rem"><?php
			printf(
				/* translators: 1: Current year. 2: Product name. */
				esc_html__( '© %1$s %2$s', 'worldgraph-child' ),
				esc_html( wp_date( 'Y' ) ),
				esc_html_x( 'World Graph Studio', 'Product name in the site footer.', 'worldgraph-child' )
			);
			?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:navigation {"textColor":"warm-ivory","overlayMenu":"never","style":{"spacing":{"blockGap":"18px"},"typography":{"fontSize":"0.88rem"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"right"},"ariaLabel":"<?php echo esc_attr_x( 'World Graph Studio footer links', 'Footer navigation label.', 'worldgraph-child' ); ?>"} -->
			<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'Home', 'Footer navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( home_url( '/' ) ); ?>","kind":"custom"} /-->
			<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'About', 'Footer navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( home_url( '/about/' ) ); ?>","kind":"custom"} /-->
			<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'Story Graph', 'Footer navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( home_url( '/#story-graph' ) ); ?>","kind":"custom"} /-->
			<!-- wp:navigation-link {"label":"<?php echo esc_html_x( 'Open Studio', 'Footer navigation link.', 'worldgraph-child' ); ?>","url":"<?php echo esc_url( admin_url( 'admin.php?page=worldgraph' ) ); ?>","kind":"custom"} /-->
		<!-- /wp:navigation -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
