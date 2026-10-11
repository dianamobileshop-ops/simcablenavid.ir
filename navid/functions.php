<?php
/**
 * Navid theme: setup, assets and settings.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

define( 'NAVID_VERSION', '1.0.0' );

/**
 * Basic theme support.
 */
function navid_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'custom-logo', array( 'height' => 80, 'width' => 240, 'flex-height' => true, 'flex-width' => true ) );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
	load_theme_textdomain( 'navid', get_template_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'navid_setup' );

/**
 * Styles. Only the theme stylesheet; no extra libraries.
 */
function navid_assets() {
	wp_enqueue_style( 'navid', get_stylesheet_uri(), array(), NAVID_VERSION );
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
	);
	return wp_parse_args( (array) get_option( 'navid_options', array() ), $defaults );
}

require_once get_template_directory() . '/inc/settings.php';
