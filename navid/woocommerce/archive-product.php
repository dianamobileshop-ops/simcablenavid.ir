<?php
/**
 * Shop and category archive: sidebar filters and the product grid.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="navid-wrap navid-shop">
	<header class="navid-shop-head">
		<h1 class="navid-shop-title"><?php woocommerce_page_title(); ?></h1>
		<?php do_action( 'woocommerce_archive_description' ); ?>
	</header>

	<?php if ( woocommerce_product_loop() ) : ?>
		<div class="navid-shop-bar">
			<p class="navid-count"><?php woocommerce_result_count(); ?></p>
			<?php woocommerce_catalog_ordering(); ?>
		</div>
	<?php endif; ?>

	<div class="navid-shop-layout">
		<aside class="navid-shop-side" aria-label="<?php esc_attr_e( 'فیلترها', 'navid' ); ?>">
			<?php dynamic_sidebar( 'navid-shop-sidebar' ); ?>
		</aside>

		<div class="navid-shop-main">
			<?php
			if ( woocommerce_product_loop() ) {
				woocommerce_product_loop_start();
				if ( wc_get_loop_prop( 'total' ) ) {
					while ( have_posts() ) {
						the_post();
						wc_get_template_part( 'content', 'product' );
					}
				}
				woocommerce_product_loop_end();
				woocommerce_pagination();
			} else {
				do_action( 'woocommerce_no_products_found' );
			}
			?>
		</div>
	</div>
</div>
<?php
get_footer();
