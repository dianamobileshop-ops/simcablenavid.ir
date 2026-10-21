<?php
/**
 * Live product search in the header (suggestions while typing).
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Up to six matching products as JSON: name, link, thumbnail and a price text that
 * respects hidden prices.
 */
function navid_live_search() {
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$q = strtr( $q, array( 'ي' => 'ی', 'ك' => 'ک' ) );
	if ( mb_strlen( $q ) < 2 || ! function_exists( 'wc_get_products' ) ) {
		wp_send_json_success( array() );
	}
	if ( function_exists( 'npt_pdf_rate_limit_ok' ) && ! npt_pdf_rate_limit_ok( 'live' ) ) {
		wp_send_json_error( array( 'message' => 'rate' ), 429 );
	}

	$key    = 'navid_ls_' . md5( $q . '|' . ( function_exists( 'npt_table_cache_version' ) ? npt_table_cache_version() : 1 ) );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		wp_send_json_success( $cached );
	}

	$out      = array();
	$products = wc_get_products( array(
		'status' => 'publish',
		'limit'  => 6,
		's'      => $q,
	) );
	foreach ( $products as $product ) {
		$image = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
		$price = navid_price_is_visible( $product ) && '' !== $product->get_price()
			? wp_strip_all_tags( html_entity_decode( $product->get_price_html() ) )
			: 'تماس بگیرید';
		$out[] = array(
			'name'  => $product->get_name(),
			'url'   => get_permalink( $product->get_id() ),
			'image' => $image,
			'price' => $price,
		);
	}
	set_transient( $key, $out, 5 * MINUTE_IN_SECONDS );
	wp_send_json_success( $out );
}
add_action( 'wp_ajax_navid_live_search', 'navid_live_search' );
add_action( 'wp_ajax_nopriv_navid_live_search', 'navid_live_search' );
