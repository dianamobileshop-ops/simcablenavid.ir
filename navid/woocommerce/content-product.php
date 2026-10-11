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
?>
<li <?php wc_product_class( 'navid-card', $product ); ?>>
	<a class="navid-card-img" href="<?php echo esc_url( get_permalink() ); ?>">
		<?php echo $product->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</a>

	<h2 class="navid-card-title">
		<a href="<?php echo esc_url( get_permalink() ); ?>"><?php the_title(); ?></a>
	</h2>

	<p class="navid-card-stock">
		<?php echo $product->is_in_stock() ? esc_html__( 'موجود در انبار', 'navid' ) : esc_html__( 'ناموجود', 'navid' ); ?>
	</p>

	<p class="navid-card-seller"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>

	<div class="navid-card-price">
		<?php if ( $visible ) : ?>
			<span class="price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
		<?php else : ?>
			<a class="navid-call-price" href="<?php echo esc_url( get_permalink() ); ?>"><?php esc_html_e( 'تماس بگیرید', 'navid' ); ?></a>
		<?php endif; ?>
	</div>
</li>
