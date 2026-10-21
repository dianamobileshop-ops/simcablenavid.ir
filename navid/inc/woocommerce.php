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

/* ---------------------------------------------------------------------
 * Shop / category page helpers
 * ------------------------------------------------------------------- */

/**
 * Address of the archive being viewed without any filter in it.
 *
 * @return string
 */
function navid_shop_base_url() {
	if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
		$link = get_term_link( get_queried_object() );
		if ( ! is_wp_error( $link ) ) {
			return $link;
		}
	}
	return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
}

/**
 * Sub-categories of the current category (top-level ones on the shop page).
 *
 * @return WP_Term[]
 */
function navid_shop_subcategories() {
	$parent = ( function_exists( 'is_product_category' ) && is_product_category() ) ? (int) get_queried_object_id() : 0;
	$terms  = get_terms( array(
		'taxonomy'   => 'product_cat',
		'parent'     => $parent,
		'hide_empty' => true,
		'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
	) );
	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Sort choices shown as a row of links above the grid.
 *
 * @return array orderby value => label
 */
function navid_sort_options() {
	return array(
		'menu_order' => 'پیش‌فرض',
		'popularity' => 'پربازدیدترین',
		'rating'     => 'بالاترین امتیاز',
		'date'       => 'جدیدترین',
		'price'      => 'ارزان‌ترین',
		'price-desc' => 'گران‌ترین',
	);
}

/**
 * «Only in stock» filter of the sidebar (price range is handled by WooCommerce itself).
 *
 * @param WP_Query $query Product query.
 */
function navid_product_query_filters( $query ) {
	if ( ! empty( $_GET['instock'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'   => '_stock_status',
			'value' => 'instock',
		);
		$query->set( 'meta_query', $meta );
	}
}
add_action( 'woocommerce_product_query', 'navid_product_query_filters' );

/**
 * Breadcrumb markup that the theme can style.
 *
 * @param array $args Defaults.
 * @return array
 */
function navid_breadcrumb_args( $args ) {
	$args['delimiter']   = '<span class="navid-crumb-sep">/</span>';
	$args['wrap_before'] = '<nav class="navid-breadcrumb" aria-label="breadcrumb">';
	$args['wrap_after']  = '</nav>';
	$args['home']        = 'خانه';
	return $args;
}
add_filter( 'woocommerce_breadcrumb_defaults', 'navid_breadcrumb_args' );

// The theme draws its own sorting links and result count.
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );

/**
 * Address of the page that carries the [navid_proforma] form (cached), or ''.
 *
 * @return string
 */
function navid_proforma_url() {
	$url = get_transient( 'navid_pf_url' );
	if ( false === $url ) {
		$url   = '';
		$pages = get_posts( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			's'           => '[navid_proforma',
			'numberposts' => 5,
		) );
		foreach ( $pages as $page ) {
			if ( has_shortcode( $page->post_content, 'navid_proforma' ) ) {
				$url = get_permalink( $page );
				break;
			}
		}
		set_transient( 'navid_pf_url', $url, 6 * HOUR_IN_SECONDS );
	}
	return (string) $url;
}
add_action( 'save_post_page', function () {
	delete_transient( 'navid_pf_url' );
} );

/**
 * Cart page: the proforma comes first. One click turns the cart into a proforma
 * (name and mobile number only); the order can still be placed afterwards.
 */
function navid_cart_proforma_card() {
	if ( ! class_exists( 'NPT_Proforma' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}
	$user  = wp_get_current_user();
	$name  = $user->exists() ? trim( $user->first_name . ' ' . $user->last_name ) : '';
	$phone = ( $user->exists() && class_exists( 'NPT_Panel' ) ) ? NPT_Panel::user_mobile( $user ) : '';
	?>
	<div class="navid-pfcard" data-navid-pf>
		<h3><?php esc_html_e( 'پیش‌فاکتور این سبد', 'navid' ); ?></h3>
		<p><?php esc_html_e( 'اول پیش‌فاکتور بگیرید و بررسی کنید؛ هر وقت خواستید همین سبد را ثبت سفارش کنید.', 'navid' ); ?></p>
		<form class="navid-pf-form" novalidate>
			<input type="text" name="c_name" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'نام و نام خانوادگی', 'navid' ); ?>" autocomplete="name" required>
			<input type="tel" name="c_phone" value="<?php echo esc_attr( $phone ); ?>" placeholder="<?php esc_attr_e( 'شمارهٔ موبایل (۰۹…)', 'navid' ); ?>" inputmode="tel" dir="ltr" autocomplete="tel" required>
			<button type="submit" class="navid-pf-btn"><?php esc_html_e( 'دریافت پیش‌فاکتور', 'navid' ); ?></button>
		</form>
		<p class="navid-pf-status" role="status"></p>
		<div class="navid-pf-done" hidden>
			<strong class="navid-pf-num"></strong>
			<a class="navid-btn navid-btn--primary navid-pf-view" href="#" target="_blank" rel="noopener"><?php esc_html_e( 'مشاهده و چاپ پیش‌فاکتور', 'navid' ); ?></a>
			<a class="navid-btn navid-pf-checkout" href="#"><?php esc_html_e( 'ادامه و ثبت سفارش', 'navid' ); ?></a>
		</div>
	</div>
	<?php
}
add_action( 'woocommerce_before_cart_totals', 'navid_cart_proforma_card' );

/**
 * Attribute terms used by the products of the current archive (cached for an hour).
 *
 * @param string $taxonomy pa_* taxonomy.
 * @return WP_Term[]
 */
function navid_archive_attribute_terms( $taxonomy ) {
	$scope = ( function_exists( 'is_product_category' ) && is_product_category() ) ? (int) get_queried_object_id() : 0;
	$key   = 'navid_attr_' . md5( $taxonomy . '|' . $scope . '|' . ( function_exists( 'npt_table_cache_version' ) ? npt_table_cache_version() : 1 ) );
	$ids   = get_transient( $key );
	if ( false === $ids ) {
		$args = array(
			'status' => 'publish',
			'limit'  => 500,
			'return' => 'ids',
		);
		if ( $scope ) {
			$term = get_term( $scope, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$args['category'] = array( $term->slug );
			}
		}
		$products = wc_get_products( $args );
		$terms    = $products ? wp_get_object_terms( $products, $taxonomy, array( 'fields' => 'ids' ) ) : array();
		$ids      = is_wp_error( $terms ) ? array() : array_values( array_unique( array_map( 'intval', $terms ) ) );
		set_transient( $key, $ids, HOUR_IN_SECONDS );
	}
	if ( ! $ids ) {
		return array();
	}
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'include' => $ids, 'hide_empty' => true, 'number' => 24 ) );
	return is_wp_error( $terms ) ? array() : $terms;
}
