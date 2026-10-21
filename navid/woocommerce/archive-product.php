<?php
/**
 * Shop and category page: breadcrumb, sub-category tiles, filter sidebar,
 * sorting links and the product grid.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();

$navid_base    = navid_shop_base_url();
$navid_subs    = navid_shop_subcategories();
$navid_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'menu_order'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$navid_min     = isset( $_GET['min_price'] ) ? wc_clean( wp_unslash( $_GET['min_price'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$navid_max     = isset( $_GET['max_price'] ) ? wc_clean( wp_unslash( $_GET['max_price'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$navid_stock   = ! empty( $_GET['instock'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$navid_active  = '' !== $navid_min || '' !== $navid_max || $navid_stock;
?>
<div class="navid-wrap navid-shop">
	<?php woocommerce_breadcrumb(); ?>

	<header class="navid-shop-head">
		<h1 class="navid-shop-title"><?php woocommerce_page_title(); ?></h1>
	</header>

	<?php if ( $navid_subs ) : ?>
		<div class="navid-subcats" role="list">
			<?php foreach ( $navid_subs as $navid_sub ) : ?>
				<?php
				$navid_thumb = (int) get_term_meta( $navid_sub->term_id, 'thumbnail_id', true );
				?>
				<a class="navid-subcat" role="listitem" href="<?php echo esc_url( get_term_link( $navid_sub ) ); ?>">
					<span class="navid-subcat-img">
						<?php
						if ( $navid_thumb ) {
							echo wp_get_attachment_image( $navid_thumb, 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'alt' => $navid_sub->name ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</span>
					<span class="navid-subcat-name"><?php echo esc_html( $navid_sub->name ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="navid-shop-layout">
		<aside class="navid-shop-side" aria-label="<?php esc_attr_e( 'فیلترها', 'navid' ); ?>">
			<details class="navid-filters" open>
				<summary class="navid-filters-title"><?php esc_html_e( 'فیلترها', 'navid' ); ?></summary>
				<form method="get" action="<?php echo esc_url( $navid_base ); ?>">
					<?php if ( 'menu_order' !== $navid_orderby ) : ?>
						<input type="hidden" name="orderby" value="<?php echo esc_attr( $navid_orderby ); ?>">
					<?php endif; ?>
					<?php if ( get_query_var( 's' ) ) : ?>
						<input type="hidden" name="s" value="<?php echo esc_attr( get_query_var( 's' ) ); ?>">
						<input type="hidden" name="post_type" value="product">
					<?php endif; ?>

					<details class="navid-filter" open>
						<summary><?php esc_html_e( 'محدوده قیمت', 'navid' ); ?></summary>
						<div class="navid-price-range">
							<label><?php esc_html_e( 'از', 'navid' ); ?> <input type="number" min="0" name="min_price" value="<?php echo esc_attr( $navid_min ); ?>" placeholder="۰"></label>
							<label><?php esc_html_e( 'تا', 'navid' ); ?> <input type="number" min="0" name="max_price" value="<?php echo esc_attr( $navid_max ); ?>" placeholder="۰"></label>
						</div>
					</details>

					<label class="navid-switch">
						<span><?php esc_html_e( 'فقط کالاهای موجود در انبار', 'navid' ); ?></span>
						<input type="checkbox" name="instock" value="1" <?php checked( $navid_stock ); ?>>
					</label>

					<div class="navid-filter-actions">
						<button type="submit" class="navid-btn navid-btn--primary"><?php esc_html_e( 'اعمال فیلتر', 'navid' ); ?></button>
						<?php if ( $navid_active ) : ?>
							<a href="<?php echo esc_url( $navid_base ); ?>"><?php esc_html_e( 'حذف فیلترها', 'navid' ); ?></a>
						<?php endif; ?>
					</div>
				</form>
			</details>
			<?php dynamic_sidebar( 'navid-shop-sidebar' ); ?>
		</aside>

		<div class="navid-shop-main">
			<?php if ( woocommerce_product_loop() ) : ?>
				<div class="navid-shop-bar">
					<nav class="navid-sort" aria-label="<?php esc_attr_e( 'مرتب‌سازی', 'navid' ); ?>">
						<span class="navid-sort-label"><?php esc_html_e( 'مرتب‌سازی:', 'navid' ); ?></span>
						<?php foreach ( navid_sort_options() as $navid_key => $navid_label ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'orderby', $navid_key, remove_query_arg( 'paged' ) ) ); ?>" class="<?php echo $navid_key === $navid_orderby ? 'is-on' : ''; ?>"><?php echo esc_html( $navid_label ); ?></a>
						<?php endforeach; ?>
					</nav>
					<p class="navid-count"><?php woocommerce_result_count(); ?></p>
				</div>

				<?php
				woocommerce_product_loop_start();
				if ( wc_get_loop_prop( 'total' ) ) {
					while ( have_posts() ) {
						the_post();
						wc_get_template_part( 'content', 'product' );
					}
				}
				woocommerce_product_loop_end();
				woocommerce_pagination();
				?>
			<?php else : ?>
				<?php do_action( 'woocommerce_no_products_found' ); ?>
			<?php endif; ?>
		</div>
	</div>

	<?php
	// The text of the category (or of the shop page) sits under the products, on the first page only.
	$navid_about = '';
	if ( ! is_paged() && ! is_search() ) {
		if ( is_product_taxonomy() ) {
			$navid_about = wc_format_content( wp_kses_post( term_description() ) );
		} elseif ( is_shop() ) {
			$navid_shop_page = get_post( wc_get_page_id( 'shop' ) );
			if ( $navid_shop_page && '' !== trim( $navid_shop_page->post_content ) ) {
				$navid_about = apply_filters( 'the_content', $navid_shop_page->post_content );
			}
		}
	}
	if ( '' !== trim( (string) $navid_about ) ) :
		?>
		<section class="navid-shop-about navid-entry-body"><?php echo $navid_about; // phpcs:ignore WordPress.Security.EscapeOutput ?></section>
	<?php endif; ?>
</div>
<?php
get_footer();
