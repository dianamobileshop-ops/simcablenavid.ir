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
	return $slides;
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
	$terms = get_terms( array(
		'taxonomy'   => 'product_cat',
		'parent'     => 0,
		'hide_empty' => true,
		'exclude'    => array( get_option( 'default_product_cat' ) ),
	) );
	return is_wp_error( $terms ) ? array() : $terms;
}
