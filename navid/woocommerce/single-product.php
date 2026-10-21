<?php
/**
 * Single product: gallery, title and details, and a purchase box.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	global $product;
	$product = wc_get_product( get_the_ID() );
	if ( ! $product ) {
		continue;
	}
	$navid_visible = navid_price_is_visible( $product );
	$navid_opt     = navid_options();
	$navid_tel     = preg_replace( '/[^0-9+]/', '', (string) $navid_opt['phone'] );
	$navid_reviews = (int) $product->get_review_count();
	$navid_rating  = (float) $product->get_average_rating();
	?>
	<div class="navid-wrap navid-single-product">
		<?php woocommerce_breadcrumb(); ?>
		<?php do_action( 'woocommerce_before_single_product' ); ?>

		<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'navid-product', $product ); ?>>
			<div class="navid-product-gallery">
				<?php woocommerce_show_product_images(); ?>
			</div>

			<div class="navid-product-info">
				<?php echo wc_get_product_category_list( $product->get_id(), ' · ', '<div class="navid-product-cats">', '</div>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<h1 class="navid-product-title"><?php the_title(); ?></h1>

				<div class="navid-product-chips">
					<?php if ( $navid_rating > 0 ) : ?>
						<span class="navid-chip navid-chip--rating">★ <?php echo esc_html( number_format_i18n( $navid_rating, 1 ) ); ?>
							<?php if ( $navid_reviews ) : ?><small>(<?php echo esc_html( number_format_i18n( $navid_reviews ) ); ?> <?php esc_html_e( 'دیدگاه', 'navid' ); ?>)</small><?php endif; ?>
						</span>
					<?php endif; ?>
					<span class="navid-chip <?php echo $product->is_in_stock() ? 'navid-chip--ok' : 'navid-chip--out'; ?>"><?php echo $product->is_in_stock() ? esc_html__( 'موجود در انبار', 'navid' ) : esc_html__( 'ناموجود', 'navid' ); ?></span>
					<?php if ( $product->get_sku() ) : ?>
						<span class="navid-chip"><?php esc_html_e( 'کد کالا:', 'navid' ); ?> <?php echo esc_html( $product->get_sku() ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( $product->get_short_description() ) : ?>
					<div class="navid-product-short navid-entry-body"><?php woocommerce_template_single_excerpt(); ?></div>
				<?php endif; ?>

				<?php
				ob_start();
				wc_display_product_attributes( $product );
				$navid_attrs = trim( ob_get_clean() );
				if ( '' !== $navid_attrs ) :
					?>
					<h2 class="navid-product-h2"><?php esc_html_e( 'ویژگی‌ها', 'navid' ); ?></h2>
					<?php echo $navid_attrs; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endif; ?>
			</div>

			<aside class="navid-buybox" aria-label="<?php esc_attr_e( 'خرید', 'navid' ); ?>">
				<div class="navid-seller">
					<span class="navid-seller-label"><?php esc_html_e( 'فروشنده', 'navid' ); ?></span>
					<strong><?php bloginfo( 'name' ); ?></strong>
				</div>

				<?php if ( $navid_visible ) : ?>
					<div class="navid-buybox-price"><?php woocommerce_template_single_price(); ?></div>
					<div class="navid-buybox-cart"><?php woocommerce_template_single_add_to_cart(); ?></div>
					<?php $navid_pf = navid_proforma_url(); ?>
					<?php if ( $navid_pf ) : ?>
						<a class="navid-buy-pf" href="<?php echo esc_url( add_query_arg( 'npt_add', $product->get_id(), $navid_pf ) ); ?>"><?php esc_html_e( 'افزودن به پیش‌فاکتور', 'navid' ); ?></a>
					<?php endif; ?>
				<?php else : ?>
					<div class="navid-buybox-price"><span class="navid-call-price"><?php esc_html_e( 'تماس بگیرید', 'navid' ); ?></span></div>
					<p class="navid-buybox-note"><?php esc_html_e( 'قیمت این کالا تلفنی اعلام می‌شود.', 'navid' ); ?></p>
					<?php if ( $navid_tel ) : ?>
						<a class="navid-buy-call" href="tel:<?php echo esc_attr( $navid_tel ); ?>"><?php esc_html_e( 'تماس با ما', 'navid' ); ?> <span dir="ltr"><?php echo esc_html( $navid_opt['phone'] ); ?></span></a>
					<?php endif; ?>
				<?php endif; ?>
				<?php
				// Quick question about this product on WhatsApp (number from the footer settings).
				$navid_wa = wp_list_filter( navid_social_links(), array( 'icon' => 'whatsapp' ) );
				if ( $navid_wa ) :
					$navid_wa  = reset( $navid_wa );
					$navid_msg = sprintf( 'سلام، دربارهٔ «%s» سؤال دارم: %s', get_the_title(), get_permalink() );
					?>
					<a class="navid-buy-wa" href="<?php echo esc_url( add_query_arg( 'text', rawurlencode( $navid_msg ), $navid_wa['url'] ) ); ?>" target="_blank" rel="noopener"><?php echo navid_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'مشاوره در واتس‌اپ', 'navid' ); ?></a>
				<?php endif; ?>
			</aside>
		</div>

		<?php
		if ( $navid_visible && isset( WC()->structured_data ) ) {
			WC()->structured_data->generate_product_data();
		}
		?>

		<div class="navid-product-tabs"><?php woocommerce_output_product_data_tabs(); ?></div>
		<?php woocommerce_upsell_display(); ?>
		<?php woocommerce_output_related_products(); ?>
	</div>
	<?php
endwhile;

get_footer();
