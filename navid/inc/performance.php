<?php
/**
 * Small speed-ups: no emoji script, no wp-embed, WebP for new JPEG uploads.
 * Switch it all off with: add_filter( 'navid_performance', '__return_false' );
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the speed-ups.
 */
function navid_performance_init() {
	if ( ! apply_filters( 'navid_performance', true ) ) {
		return;
	}
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'rsd_link' );
	add_action( 'wp_enqueue_scripts', function () {
		wp_dequeue_script( 'wp-embed' );
	}, 100 );
	// New JPEG uploads (and their sizes) are stored as WebP when the server can do it.
	add_filter( 'image_editor_output_format', function ( $formats ) {
		if ( function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$formats['image/jpeg'] = 'image/webp';
		}
		return $formats;
	} );
}
add_action( 'after_setup_theme', 'navid_performance_init', 20 );
