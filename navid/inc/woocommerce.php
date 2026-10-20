<?php
/**
 * WooCommerce: shop sidebar, per-product price visibility.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sidebar for the shop filters. Filled from Appearance > Widgets.
 */
function navid_register_sidebars() {
	register_sidebar( array(
		'name'          => 'فیلترهای فروشگاه',
		'id'            => 'navid-shop-sidebar',
		'before_widget' => '<section class="navid-filter-card">',
		'after_widget'  => '</section>',
		'before_title'  => '<h2 class="navid-filter-title">',
		'after_title'   => '</h2>',
	) );
}
add_action( 'widgets_init', 'navid_register_sidebars' );

/**
 * Meta box: show the price or the "call" text for this product.
 */
function navid_price_metabox() {
	add_meta_box( 'navid-price-visibility', 'نمایش قیمت', 'navid_price_metabox_html', 'product', 'side', 'default' );
}
add_action( 'add_meta_boxes', 'navid_price_metabox' );

/**
 * @param WP_Post $post Current product.
 */
function navid_price_metabox_html( $post ) {
	$show = get_post_meta( $post->ID, '_navid_show_price', true );
	// Empty means "show", so existing products keep their prices.
	$checked = ( '0' !== $show );
	wp_nonce_field( 'navid_price_save', 'navid_price_nonce' );
	?>
	<label>
		<input type="checkbox" name="navid_show_price" value="1" <?php checked( $checked ); ?>>
		قیمت نمایش داده شود
	</label>
	<p class="description">اگر خاموش باشد، به جای قیمت «تماس بگیرید» نمایش داده می‌شود و خرید آنلاین غیرفعال است.</p>
	<?php
}

/**
 * Saves the meta box.
 *
 * @param int $post_id Product ID.
 */
function navid_price_metabox_save( $post_id ) {
	if ( ! isset( $_POST['navid_price_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['navid_price_nonce'] ) ), 'navid_price_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	update_post_meta( $post_id, '_navid_show_price', isset( $_POST['navid_show_price'] ) ? '1' : '0' );
}
add_action( 'save_post_product', 'navid_price_metabox_save' );

/**
 * Whether the price is shown for this product.
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function navid_price_is_visible( $product ) {
	// A variation follows the product it belongs to.
	$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	return '0' !== get_post_meta( $id, '_navid_show_price', true );
}

/**
 * Replaces the price with the call text when it is hidden.
 *
 * @param string     $price   Price HTML.
 * @param WC_Product $product Product.
 * @return string
 */
function navid_price_html( $price, $product ) {
	// The admin product list keeps showing the real price to the people who manage it.
	if ( ( is_admin() && ! wp_doing_ajax() ) || navid_price_is_visible( $product ) ) {
		return $price;
	}
	return '<span class="navid-call-price">' . esc_html__( 'تماس بگیرید', 'navid' ) . '</span>';
}
add_filter( 'woocommerce_get_price_html', 'navid_price_html', 10, 2 );

/**
 * Hidden-price products cannot be bought online.
 *
 * @param bool       $purchasable Current value.
 * @param WC_Product $product     Product.
 * @return bool
 */
function navid_price_purchasable( $purchasable, $product ) {
	return navid_price_is_visible( $product ) ? $purchasable : false;
}
add_filter( 'woocommerce_is_purchasable', 'navid_price_purchasable', 10, 2 );
add_filter( 'woocommerce_variation_is_purchasable', 'navid_price_purchasable', 10, 2 );

/**
 * Variation data sent to the product page must not carry the hidden price either.
 *
 * @param array                $data      Variation data.
 * @param WC_Product           $parent    Variable product.
 * @param WC_Product_Variation $variation Variation.
 * @return array
 */
function navid_variation_data( $data, $parent, $variation ) {
	if ( ! navid_price_is_visible( $variation ) ) {
		$data['price_html']    = '<span class="navid-call-price">' . esc_html__( 'تماس بگیرید', 'navid' ) . '</span>';
		$data['display_price'] = '';
		$data['display_regular_price'] = '';
		$data['is_purchasable'] = false;
	}
	return $data;
}
add_filter( 'woocommerce_available_variation', 'navid_variation_data', 10, 3 );
