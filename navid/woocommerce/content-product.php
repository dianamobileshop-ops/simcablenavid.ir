<?php
/**
 * Product card in the shop grid.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( empty( $product ) || ! $product->is_visible() ) {
	return;
}
$visible = navid_price_is_visible( $product );
$rating  = (float) $product->get_average_rating();
?>
<li <?php wc_product_class( 'navid-card', $product ); ?>>
	<a class="navid-card-img" href="<?php echo esc_url( get_permalink() ); ?>">
		<?php echo $product->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php if ( $product->is_on_sale() && $visible ) : ?>
			<span class="navid-card-sale"><?php esc_html_e( 'حراج', 'navid' ); ?></span>
		<?php endif; ?>
	</a>

	<h2 class="navid-card-title">
		<a href="<?php echo esc_url( get_permalink() ); ?>"><?php the_title(); ?></a>
	</h2>

	<div class="navid-card-meta">
		<span class="navid-card-stock<?php echo $product->is_in_stock() ? '' : ' is-out'; ?>">
			<?php echo $product->is_in_stock() ? esc_html__( 'موجود در انبار', 'navid' ) : esc_html__( 'ناموجود', 'navid' ); ?>
		</span>
		<?php if ( $rating > 0 ) : ?>
			<span class="navid-card-rating">★ <?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?></span>
		<?php endif; ?>
	</div>

	<div class="navid-card-price">
		<?php if ( $visible ) : ?>
			<span class="price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
		<?php else : ?>
			<a class="navid-call-price" href="<?php echo esc_url( get_permalink() ); ?>"><?php esc_html_e( 'تماس بگیرید', 'navid' ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( $visible ) : ?>
		<div class="navid-card-cart"><?php woocommerce_template_loop_add_to_cart(); ?></div>
	<?php endif; ?>
</li>
