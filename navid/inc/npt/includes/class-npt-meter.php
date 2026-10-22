<?php
/**
 * Meter sales ("فروش متری") — merged from the standalone
 * "فروش متری ووکامرس" plugin (v7.0.0) into Navid Price Table.
 *
 * Behaviour is identical to the standalone plugin. The product meta key is
 * kept unchanged (existing products keep working), while the functions are
 * prefixed npt_meter_* so a still-active standalone plugin can never cause
 * a redeclaration fatal.
 *
 * If the old standalone plugin is still active, this file does nothing and
 * lets the old plugin keep handling everything until it is deactivated.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'navid_is_meter_product_v70' ) ) {
	return;
}

define( 'NPT_METER_META_KEY', '_navid_is_meter_sales' );

/* -------------------------------------------------------------------------
 * 1) Backend: "sell by meter" checkbox on the product's General tab
 * ---------------------------------------------------------------------- */
add_action( 'woocommerce_product_options_general_product_data', 'npt_meter_add_checkbox' );
function npt_meter_add_checkbox() {
	echo '<div class="options_group">';
	woocommerce_wp_checkbox( array(
		'id'          => NPT_METER_META_KEY,
		'label'       => __( 'فروش به صورت متری؟', 'woocommerce' ),
		'description' => __( 'اگر تیک بزنید، برچسب "فروش متری" نمایش داده می‌شود و قابلیت انتخاب متراژ و محاسبه آنی قیمت فعال می‌گردد.', 'woocommerce' ),
		'desc_tip'    => true,
	) );
	echo '</div>';
}

add_action( 'woocommerce_process_product_meta', 'npt_meter_save_checkbox' );
function npt_meter_save_checkbox( $post_id ) {
	// Without this guard, saves coming from the block editor / REST API (which
	// don't post the classic meta-box nonce) would silently reset the flag.
	if ( ! isset( $_POST['woocommerce_meta_nonce'] ) ) {
		return;
	}
	$val = isset( $_POST[ NPT_METER_META_KEY ] ) ? 'yes' : 'no';
	update_post_meta( $post_id, NPT_METER_META_KEY, $val );
}

function npt_meter_is_meter_product( $product_id ) {
	return get_post_meta( $product_id, NPT_METER_META_KEY, true ) === 'yes';
}

/* -------------------------------------------------------------------------
 * 2) Price labels: "(قیمت هر متر)" vs "(قیمت کلاف ۱۰۰ متری)"
 * ---------------------------------------------------------------------- */
add_filter( 'woocommerce_get_price_html', 'npt_meter_price_labels', 10, 2 );
function npt_meter_price_labels( $price, $product ) {
	if ( ! $product->is_type( 'simple' ) || empty( $price ) ) {
		return $price;
	}
	// Only the product page says what the price is for; cards and lists show the unit tag instead.
	$main = function_exists( 'is_product' ) && is_product() && (int) get_queried_object_id() === (int) $product->get_id();
	if ( ! $main ) {
		return $price;
	}
	// Wrap the raw price so the meter JS can find it.
	if ( npt_meter_is_meter_product( $product->get_id() ) ) {
		$price = '<span class="navid-original-unit-price">' . $price . '</span>';
		$text  = 'قیمت برای هر متر';
	} else {
		$text = 'قیمت برای کلاف ۱۰۰ متری';
	}
	return $price . ' <span class="navid-price-label-suffix">' . esc_html( $text ) . '</span>';
}

/* -------------------------------------------------------------------------
 * 3) Single product: meter input UI + instant total + add-to-cart button
 * ---------------------------------------------------------------------- */

/**
 * The product being viewed. In wp_head the global $product can still be the product
 * slug (a string, from the query variable), so it is only trusted when it is an object.
 *
 * @return WC_Product|null
 */
function npt_meter_current_product() {
	global $product;
	if ( $product instanceof WC_Product ) {
		return $product;
	}
	$found = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
	return $found instanceof WC_Product ? $found : null;
}

add_action( 'wp_head', 'npt_meter_input_css' );
function npt_meter_input_css() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$product = npt_meter_current_product();
	if ( ! $product || ! $product->is_type( 'simple' ) || ! npt_meter_is_meter_product( $product->get_id() ) ) {
		return;
	}
	?>
	<style>
		.single-product div.product form.cart .quantity,
		.single-product div.product form.cart button.single_add_to_cart_button { display: none !important; }
		#navid-custom-add-to-cart-btn {
			width: 100%; margin-top: 20px; padding: 15px; font-size: 18px; font-weight: bold;
			background-color: #521a6d; color: #fff; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.3s;
		}
		#navid-custom-add-to-cart-btn:hover { background-color: #3a0f4f; }
		.navid-calculated-total-price { font-weight: bold; color: #521a6d; }
	</style>
	<?php
}

add_action( 'woocommerce_before_add_to_cart_button', 'npt_meter_input_html' );
function npt_meter_input_html() {
	$product = npt_meter_current_product();
	if ( ! $product || ! $product->is_type( 'simple' ) || ! npt_meter_is_meter_product( $product->get_id() ) ) {
		return;
	}
	?>
	<div class="navid-meter-input-container">
		<label for="navid-meter-fake-input" class="navid-meter-label">📏 متراژ مورد نیاز را وارد کنید:</label>
		<div class="navid-meter-wrapper">
			<button type="button" class="navid-qty-btn navid-minus">-</button>
			<input type="number" id="navid-meter-fake-input" class="navid-meter-input" value="1" min="1" step="1">
			<button type="button" class="navid-qty-btn navid-plus">+</button>
			<span class="navid-unit-label">متر</span>
		</div>
		<button type="button" id="navid-custom-add-to-cart-btn">افزودن به سبد خرید (متری)</button>
	</div>
	<style>
		.navid-meter-input-container { margin-bottom: 20px; padding: 20px; background: #f5f7fa; border-radius: 12px; border: 1px solid #e2e8f0; }
		.navid-meter-label { display: block; margin-bottom: 15px; font-weight: 700; color: #2d3748; font-size: 16px; }
		.navid-meter-wrapper { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px; }
		.navid-meter-input { width: 100px !important; text-align: center; font-size: 24px !important; font-weight: bold; border: 3px solid #cbd5e0 !important; border-radius: 10px !important; padding: 10px !important; height: 60px !important; -moz-appearance: textfield; color: #521a6d; }
		.navid-meter-input::-webkit-outer-spin-button, .navid-meter-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
		.navid-qty-btn { width: 60px; height: 60px; background: #fff; border: 3px solid #cbd5e0; border-radius: 10px; font-size: 30px; font-weight: bold; color: #4a5568; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; padding: 0; }
		.navid-qty-btn:hover { background: #edf2f7; border-color: #a0aec0; }
		.navid-qty-btn.navid-minus { color: #e53e3e; }
		.navid-qty-btn.navid-plus { color: #38a169; }
		.navid-unit-label { font-size: 20px; font-weight: bold; color: #4a5568; margin-right: 15px; }
	</style>
	<?php
}

add_action( 'wp_footer', 'npt_meter_js' );
function npt_meter_js() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$product = npt_meter_current_product();
	if ( ! $product || ! $product->is_type( 'simple' ) || ! npt_meter_is_meter_product( $product->get_id() ) ) {
		return;
	}

	$unit_price      = (float) wc_get_price_to_display( $product );
	$currency_symbol = get_woocommerce_currency_symbol();
	?>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		const fakeInput = $('#navid-meter-fake-input');
		const btnPlus = $('.navid-qty-btn.navid-plus');
		const btnMinus = $('.navid-qty-btn.navid-minus');
		const wooQtyInput = $('form.cart').find('input.qty');
		const customAddToCartBtn = $('#navid-custom-add-to-cart-btn');
		const productForm = $('form.cart');

		// Data passed from PHP
		const unitPrice = <?php echo esc_js( $unit_price ); ?>;
		const currencySymbol = "<?php echo esc_js( $currency_symbol ); ?>";
		const productId = <?php echo (int) $product->get_id(); ?>;

		// The per-unit price element created in section 2 above
		const priceContainer = $('.navid-original-unit-price').closest('.price');

		function formatMoney(number) {
			return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
		}

		function updateCalculatedPrice() {
			let qty = parseInt(fakeInput.val()) || 1;
			if (qty < 1) qty = 1;

			let totalPrice = qty * unitPrice;

			let newPriceHtml = '<span class="woocommerce-Price-amount amount navid-calculated-total-price">' + formatMoney(totalPrice) + '&nbsp;<span class="woocommerce-Price-currencySymbol">' + currencySymbol + '</span></span> <span style="font-size: 14px; color: #777;">(قیمت کل)</span>';

			if (priceContainer.length) {
				priceContainer.html(newPriceHtml);
			}
		}

		function updateAll() {
			wooQtyInput.val(fakeInput.val()).trigger('change');
			updateCalculatedPrice();
		}

		btnPlus.on('click', function() { let val = parseInt(fakeInput.val()) || 0; fakeInput.val(val + 1); updateAll(); });
		btnMinus.on('click', function() { let val = parseInt(fakeInput.val()) || 0; if (val > 1) { fakeInput.val(val - 1); updateAll(); } });
		fakeInput.on('change input', function() { let val = parseInt($(this).val()); if (isNaN(val) || val < 1) { $(this).val(1); } updateAll(); });

		customAddToCartBtn.on('click', function(e) {
			e.preventDefault();
			updateAll();
			// Some themes render the form without the add-to-cart input (the
			// button carries the ID instead); a bare .submit() would then post
			// without any product. Add the hidden field when it's missing.
			if (!productForm.find('input[name="add-to-cart"]').length) {
				productForm.append($('<input>', { type: 'hidden', name: 'add-to-cart', value: productId }));
			}
			productForm.submit();
		});

		updateAll();
	});
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * 4) Cart label, shop-loop badges, loop button text
 * ---------------------------------------------------------------------- */
// gettext_woocommerce runs for WooCommerce strings only; the plain gettext filter ran
// this check for every translated string on every page.
add_filter( 'gettext_woocommerce', 'npt_meter_cart_label', 20, 3 );
function npt_meter_cart_label( $translated_text, $text, $domain ) {
	if ( 'تعداد' === $translated_text && function_exists( 'is_cart' ) && is_cart() ) {
		return 'تعداد / متراژ';
	}
	return $translated_text;
}

add_filter( 'woocommerce_loop_add_to_cart_link', 'npt_meter_loop_button', 10, 3 );
function npt_meter_loop_button( $button_html, $product, $args = array() ) {
	if ( ! $product->is_type( 'simple' ) ) {
		return $button_html;
	}
	if ( ! npt_meter_is_meter_product( $product->get_id() ) ) {
		return $button_html;
	}

	// $args is supplied by WooCommerce via the woocommerce_loop_add_to_cart_link
	// filter (3rd argument): class may be a string or an array, and attributes
	// is an already-escaped attribute string.
	$classes = isset( $args['class'] ) ? $args['class'] : 'button product_type_simple';
	if ( is_array( $classes ) ) {
		$classes = implode( ' ', $classes );
	}
	$attributes = isset( $args['attributes'] ) ? $args['attributes'] : '';
	// WooCommerce passes the attributes as an array (name => value); the link needs a string.
	if ( is_array( $attributes ) ) {
		$pairs = array();
		foreach ( $attributes as $name => $value ) {
			$pairs[] = esc_attr( $name ) . '="' . esc_attr( is_array( $value ) ? implode( ' ', $value ) : $value ) . '"';
		}
		$attributes = implode( ' ', $pairs );
	}

	return sprintf(
		'<a href="%s" data-quantity="1" class="%s" %s>%s</a>',
		esc_url( $product->get_permalink() ),
		esc_attr( $classes ),
		$attributes,
		esc_html__( 'انتخاب متراژ', 'woocommerce' )
	);
}
