<?php
/**
 * Navid theme: setup, assets and settings.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

define( 'NAVID_VERSION', '1.3.1' );

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
 * Styles. Only the theme stylesheet; no extra libraries.
 */
function navid_assets() {
	wp_enqueue_style( 'navid', get_stylesheet_uri(), array(), NAVID_VERSION );
	wp_enqueue_script( 'navid-header', get_template_directory_uri() . '/assets/js/header.js', array(), NAVID_VERSION, true );
	// Search in the mobile tab bar focuses the header search field.
	wp_add_inline_script( 'navid-header', "document.addEventListener('DOMContentLoaded',function(){var a=document.querySelector('[data-navid-search]'),i=document.getElementById('navid-s');if(a&&i){a.addEventListener('click',function(e){e.preventDefault();document.body.classList.toggle('navid-search-open');window.scrollTo({top:0,behavior:'smooth'});i.focus();});}});" );
	wp_localize_script( 'navid-header', 'NAVID', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
	if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() ) ) {
		wp_enqueue_script( 'navid-shop', get_template_directory_uri() . '/assets/js/shop.js', array(), NAVID_VERSION, true );
		wp_localize_script( 'navid-shop', 'NAVIDSHOP', array(
			'fragments' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'get_refreshed_fragments' ) : '',
			'cartUrl'   => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'added'     => 'به سبد خرید اضافه شد',
			'view'      => 'مشاهده سبد',
			'adding'    => 'در حال افزودن…',
			'addLabel'  => 'افزودن به سبد خرید',
			'error'     => 'افزودن به سبد انجام نشد. دوباره تلاش کنید.',
		) );
	}
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		wp_enqueue_script( 'navid-cart', get_template_directory_uri() . '/assets/js/cart.js', array(), NAVID_VERSION, true );
		wp_localize_script( 'navid-cart', 'NAVIDCART', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'navid_cart_pf' ),
		) );
	}
	if ( function_exists( 'wc_get_page_permalink' ) && ! is_user_logged_in() ) {
		wp_enqueue_script( 'navid-auth', get_template_directory_uri() . '/assets/js/auth.js', array(), NAVID_VERSION, true );
		$redirect = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_localize_script( 'navid-auth', 'NAVIDAUTH', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => class_exists( 'NPT_OTP' ) ? wp_create_nonce( NPT_OTP::NONCE ) : '',
			'redirect' => $redirect ? $redirect : wc_get_page_permalink( 'myaccount' ),
			'cooldown' => 60,
		) );
	}
	if ( is_front_page() ) {
		wp_enqueue_script( 'navid-slider', get_template_directory_uri() . '/assets/js/slider.js', array(), NAVID_VERSION, true );
	}
}
add_action( 'wp_enqueue_scripts', 'navid_assets' );

/**
 * Theme options, with defaults.
 *
 * @return array
 */
function navid_options() {
	$defaults = array(
		'primary_color' => '#1faa6b',
		'show_topbar'   => '1',
		'sticky_header' => '1',
		'toolbar_title' => 'دریافت لیست قیمت رسمی سیم و کابل نوید',
		'toolbar_sub'   => '',
		'price_url'     => '',
		'slides'        => '',
		// Same contact details the price list header/footer already uses.
		'slider_mode'   => 'slide',
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
		'phone'         => (string) get_option( 'npt_phone', '' ),
		'address'       => (string) get_option( 'npt_address', '' ),
	);
	return wp_parse_args( (array) get_option( 'navid_options', array() ), $defaults );
}

require_once get_template_directory() . '/inc/settings.php';
require_once get_template_directory() . '/inc/woocommerce.php';
require_once get_template_directory() . '/inc/home.php';
require_once get_template_directory() . '/inc/blog.php';
require_once get_template_directory() . '/inc/search.php';
require_once get_template_directory() . '/inc/performance.php';
require_once get_template_directory() . '/inc/seo.php';
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
 * Whether the theme draws its own bottom tab bar on phones. The price table code has a
 * configurable bottom bar too; when that one is on, the theme stays out of its way.
 *
 * @return bool
 */
function navid_tabbar_enabled() {
	if ( class_exists( 'NPT_Bar' ) ) {
		// «enabled» is always on in the price table code; what matters is whether its
		// bottom menu is actually drawn. With the menu off the theme bar comes back, so
		// phones are never left without a bottom bar.
		$settings = NPT_Bar::settings();
		if ( ! NPT_Bar::is_off() && ! empty( $settings['menu_enabled'] ) ) {
			return false;
		}
	}
	return true;
}

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

/**
 * Body classes: the layout leaves room for whichever bottom bar is shown.
 *
 * @param array $classes Classes.
 * @return array
 */
function navid_body_class( $classes ) {
	if ( navid_tabbar_enabled() ) {
		$classes[] = 'navid-has-tabbar';
	}
	return $classes;
}
add_filter( 'body_class', 'navid_body_class' );
