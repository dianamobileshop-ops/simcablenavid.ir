<?php
/**
 * Home page: banner slider, price box and category grid.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Banner slides from the settings page. One per line: image URL | link | alt text.
 *
 * @return array
 */
function navid_slides() {
	$opt   = navid_options();
	$lines = preg_split( '/\r\n|\r|\n/', (string) $opt['slides'] );
	$slides = array();
	foreach ( $lines as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( empty( $parts[0] ) ) {
			continue;
		}
		$slides[] = array(
			'image' => esc_url_raw( $parts[0] ),
			'link'  => isset( $parts[1] ) ? esc_url_raw( $parts[1] ) : '',
			'alt'   => isset( $parts[2] ) ? sanitize_text_field( $parts[2] ) : '',
		);
	}
	if ( $slides ) {
		return $slides;
	}

	// Nothing configured yet: three text banners so the home page never has an empty top.
	$shop  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
	$price = function_exists( 'npt_online_view_url' ) ? npt_online_view_url( '' ) : $shop;
	$tel   = preg_replace( '/[^0-9+]/', '', (string) $opt['phone'] );
	return array(
		array( 'title' => 'لیست قیمت روز سیم و کابل', 'text' => 'قیمت‌های به‌روز را آنلاین ببینید یا فایل PDF را دانلود کنید.', 'link' => $price, 'cta' => 'مشاهده قیمت‌ها', 'tone' => 1 ),
		array( 'title' => 'فروشگاه سیم و کابل نوید', 'text' => 'کابل افشان، سیم ارت، کابل زمینی و ده‌ها محصول دیگر.', 'link' => $shop, 'cta' => 'دیدن محصولات', 'tone' => 2 ),
		array( 'title' => 'مشاوره و خرید عمده', 'text' => 'برای استعلام قیمت و خرید عمده با ما تماس بگیرید.', 'link' => $tel ? 'tel:' . $tel : home_url( '/' ), 'cta' => 'تماس با ما', 'tone' => 3 ),
	);
}

/**
 * Top-level product categories with their image, for the home grid.
 *
 * @return WP_Term[]
 */
function navid_home_categories() {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return array();
	}
	$opt      = navid_options();
	$selected = array_filter( array_map( 'absint', (array) $opt['home_cats'] ) );
	if ( $selected ) {
		// The categories picked in the panel, in the order they are listed there.
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'include'    => $selected,
			'orderby'    => 'include',
			'hide_empty' => false,
		) );
		return is_wp_error( $terms ) ? array() : $terms;
	}
	$terms = get_terms( array(
		'taxonomy'   => 'product_cat',
		'parent'     => 0,
		'hide_empty' => true,
		'exclude'    => array( get_option( 'default_product_cat' ) ),
	) );
	return is_wp_error( $terms ) ? array() : $terms;
}
