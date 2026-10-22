<?php
/**
 * Navid theme: setup, assets and settings.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

define( 'NAVID_VERSION', '1.6.0' );

/**
 * Whether WooCommerce is running. Every shop feature checks this, so the theme works
 * on its own (blog, pages, price list settings) when no plugin is installed.
 *
 * @return bool
 */
function navid_wc() {
	return class_exists( 'WooCommerce' );
}

/**
 * Basic theme support.
 */
function navid_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'custom-logo', array( 'height' => 80, 'width' => 240, 'flex-height' => true, 'flex-width' => true ) );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
	register_nav_menus( array(
		'primary' => 'منوی اصلی',
		'footer'  => 'منوی فوتر',
	) );
	load_theme_textdomain( 'navid', get_template_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'navid_setup' );

/**
 * A theme script, deferred (it never blocks the page from drawing) and in the footer.
 *
 * @param string $handle Handle.
 * @param string $file   File name under assets/js.
 * @param array  $deps   Dependencies.
 */
function navid_script( $handle, $file, $deps = array() ) {
	wp_enqueue_script( $handle, get_template_directory_uri() . '/assets/js/' . $file, $deps, navid_asset_version( 'assets/js/' . $file ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
}

/**
 * Version string for an asset URL: the theme version plus the file's own change time,
 * so a browser or CDN never keeps an old copy after an update.
 *
 * @param string $relative Path inside the theme.
 * @return string
 */
function navid_asset_version( $relative ) {
	$path = get_template_directory() . '/' . $relative;
	return NAVID_VERSION . ( file_exists( $path ) ? '.' . filemtime( $path ) : '' );
}

/**
 * Address of the stylesheet: the minified build when it was made for this theme version
 * (tools/build-css.php stamps it), else style.css.
 *
 * @return string
 */
function navid_stylesheet_uri() {
	$min = get_template_directory() . '/assets/css/navid.min.css';
	$ok  = false;
	if ( file_exists( $min ) ) {
		$fh = fopen( $min, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $fh ) {
			$ok = 0 === strpos( (string) fgets( $fh, 64 ), '/*navid:' . NAVID_VERSION . '*/' );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	return $ok ? get_template_directory_uri() . '/assets/css/navid.min.css' : get_stylesheet_uri();
}

/**
 * Styles and scripts: each page loads only what it uses.
 */
function navid_assets() {
	wp_enqueue_style( 'navid', navid_stylesheet_uri(), array(), navid_asset_version( 'style.css' ) );
	navid_script( 'navid-header', 'header.js' );
	wp_localize_script( 'navid-header', 'NAVID', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
	if ( navid_wc() && ( is_woocommerce() || is_cart() || is_checkout() ) ) {
		navid_script( 'navid-shop', 'shop.js' );
		wp_localize_script( 'navid-shop', 'NAVIDSHOP', array(
			'fragments' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'get_refreshed_fragments' ) : '',
			'cartUrl'   => wc_get_cart_url(),
			'added'     => 'به سبد خرید اضافه شد',
			'view'      => 'مشاهده سبد',
			'adding'    => 'در حال افزودن…',
			'addLabel'  => 'افزودن به سبد خرید',
			'error'     => 'افزودن به سبد انجام نشد. دوباره تلاش کنید.',
		) );
	}
	if ( navid_wc() && is_cart() ) {
		navid_script( 'navid-cart', 'cart.js' );
		wp_localize_script( 'navid-cart', 'NAVIDCART', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'navid_cart_pf' ),
		) );
	}
	if ( navid_wc() && is_user_logged_in() && is_account_page() && class_exists( 'NPT_OTP' ) ) {
		navid_script( 'navid-auth', 'auth.js' );
		wp_localize_script( 'navid-auth', 'NAVIDAUTH', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( NPT_OTP::NONCE ),
			'cooldown' => 60,
		) );
	}
	if ( navid_wc() && ! is_user_logged_in() ) {
		navid_script( 'navid-auth', 'auth.js' );
		$redirect = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_localize_script( 'navid-auth', 'NAVIDAUTH', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => class_exists( 'NPT_OTP' ) ? wp_create_nonce( NPT_OTP::NONCE ) : '',
			'redirect' => $redirect ? $redirect : wc_get_page_permalink( 'myaccount' ),
			'cooldown' => 60,
			// The price table bar's login buttons open this same pop-up.
			'trigger'  => class_exists( 'NPT_Bar' ) ? (string) NPT_Bar::settings()['otp_trigger'] : '',
		) );
	}
	if ( is_front_page() ) {
		navid_script( 'navid-slider', 'slider.js' );
	}
}

/**
 * WooCommerce block styles are only needed where a page actually has WooCommerce blocks
 * (the theme's shop pages are classic templates).
 */
function navid_trim_wc_styles() {
	if ( ! navid_wc() || is_cart() || is_checkout() || is_account_page() ) {
		return;
	}
	$post = is_singular() ? get_post() : null;
	if ( ! $post || false === strpos( (string) $post->post_content, '<!-- wp:woocommerce/' ) ) {
		wp_dequeue_style( 'wc-blocks-style' );
	}
}
add_action( 'wp_enqueue_scripts', 'navid_trim_wc_styles', 100 );
add_action( 'wp_enqueue_scripts', 'navid_assets' );

/**
 * Theme options, with defaults.
 *
 * @return array
 */
function navid_options() {
	$defaults = array(
		'primary_color' => '#1d4f9f',
		'accent_color'  => '#e56a00',
		'footer_bg'     => '#0f2342',
		'show_topbar'   => '1',
		'sticky_header' => '1',
		'sticky_header_mobile' => '1',
		'toolbar_title' => 'دریافت لیست قیمت رسمی سیم و کابل نوید',
		'toolbar_sub'   => '',
		'price_url'     => '',
		'login_sms'      => '1',
		'login_password' => '1',
		'login_register' => '1',
		'login_note'     => '',
		'bar_show_pdf'  => '1',
		'bar_show_view' => '1',
		'bar_show_proforma' => '1',
		'slides'        => '',
		// Same contact details the price list header/footer already uses.
		'slider_mode'   => 'slide',
		'slider_fit'    => 'full',
		'home_order'    => 'slider,pricebar,content,products1,products2,cats,blog',
		'home_on'       => array(),
		'ps1_title'     => 'محصولات منتخب',
		'ps1_ids'       => array(),
		'ps2_title'     => '',
		'ps2_ids'       => array(),
		'slider_speed'  => 5000,
		'slider_ratio'  => '3/1',
		'slider_arrows' => '1',
		'slider_autoplay' => '1',
		'home_cats'     => array(),
		'cats_effect'   => 'lift',
		'cats_shape'    => 'circle',
		'cats_cols'     => 6,
		'email'         => '',
		'special_label' => '',
		'special_url'   => '',
		'about'         => '',
		'hours'         => '',
		'ft_features_show' => '1',
		'ft_features'   => '',
		'enamad'        => '',
		'copyright'     => '',
		'social_telegram'  => '',
		'social_instagram' => '',
		'social_whatsapp'  => '',
		'social_eitaa'     => '',
		'social_bale'      => '',
		'social_aparat'    => '',
		'social_linkedin'  => '',
		'jalali'        => 'all',
		'fa_digits'     => '1',
		'phone'         => (string) get_option( 'npt_phone', '' ),
		'address'       => (string) get_option( 'npt_address', '' ),
	);
	return wp_parse_args( (array) get_option( 'navid_options', array() ), $defaults );
}

require_once get_template_directory() . '/inc/jalali.php';
require_once get_template_directory() . '/inc/diagnostics.php';
require_once get_template_directory() . '/inc/updater.php';
require_once get_template_directory() . '/inc/login.php';
require_once get_template_directory() . '/inc/footer.php';
require_once get_template_directory() . '/inc/settings.php';
require_once get_template_directory() . '/inc/woocommerce.php';
require_once get_template_directory() . '/inc/home.php';
require_once get_template_directory() . '/inc/blog.php';
require_once get_template_directory() . '/inc/search.php';
require_once get_template_directory() . '/inc/performance.php';
require_once get_template_directory() . '/inc/seo.php';
require_once get_template_directory() . '/inc/gateways/mellat.php';
// Price table, proforma, SMS and panel code (formerly the navid-price-table plugin).
require_once get_template_directory() . '/inc/npt/navid-price-table.php';

/**
 * The cart badge is refreshed through WooCommerce cart fragments, so a cached page
 * never shows a stale number.
 *
 * @param array $fragments Fragments.
 * @return array
 */
function navid_cart_fragments( $fragments ) {
	$count = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
	$fragments['span.navid-cart-count'] = navid_cart_count_html( $count );
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'navid_cart_fragments' );

/**
 * @param int $count Items in the cart.
 * @return string
 */
function navid_cart_count_html( $count ) {
	return '<span class="navid-cart-count">' . ( $count ? '<em class="navid-badge">' . esc_html( $count ) . '</em>' : '' ) . '</span>';
}

/**
 * Site logo as an <img> whose size is fixed by CSS variables and an inline style, so a
 * huge uploaded file or another stylesheet can never blow the header up.
 *
 * @return string
 */
function navid_logo_html() {
	$id = (int) get_theme_mod( 'custom_logo' );
	if ( $id ) {
		$img = wp_get_attachment_image( $id, 'medium', false, array(
			'class'    => 'custom-logo',
			'alt'      => get_bloginfo( 'name' ),
			'style'    => 'height:var(--navid-logo-h,48px);width:auto;max-width:var(--navid-logo-w,180px)',
			'decoding' => 'async',
		) );
		if ( $img ) {
			return $img;
		}
	}
	return esc_html( get_bloginfo( 'name' ) );
}

/**
 * Fresh security tokens for forms on a cached page (a page cache can serve HTML whose
 * tokens expired hours ago). The scripts ask for new ones once and retry.
 */
function navid_ajax_nonces() {
	nocache_headers();
	wp_send_json_success( array(
		'otp'  => class_exists( 'NPT_OTP' ) ? wp_create_nonce( NPT_OTP::NONCE ) : '',
		'cart' => wp_create_nonce( 'navid_cart_pf' ),
		'name' => is_user_logged_in() ? wp_create_nonce( 'navid_save_name' ) : '',
	) );
}
add_action( 'wp_ajax_navid_nonces', 'navid_ajax_nonces' );
add_action( 'wp_ajax_nopriv_navid_nonces', 'navid_ajax_nonces' );

/**
 * Address of the page being viewed (works on sub-directory installs too, where
 * home_url( add_query_arg( array() ) ) repeated the directory).
 *
 * @return string
 */
function navid_current_url() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$home = wp_parse_url( home_url() );
	$url  = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $uri;
	return wp_validate_redirect( $url, home_url( '/' ) );
}

/**
 * The body font is preloaded so the text does not reflow when it arrives.
 */
function navid_preload_font() {
	printf(
		'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
		esc_url( get_template_directory_uri() . '/assets/fonts/Vazirmatn-Regular.woff2' )
	);
}
add_action( 'wp_head', 'navid_preload_font', 2 );
