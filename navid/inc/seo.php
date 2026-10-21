<?php
/**
 * Basic SEO: a meta description and Open Graph tags when no SEO plugin is active, and
 * «noindex» for filtered / sorted / search URLs, which only duplicate the real pages.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * True when an SEO plugin already prints meta tags.
 *
 * @return bool
 */
function navid_seo_plugin_active() {
	return defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
}

/**
 * Meta description for the page being viewed.
 *
 * @return string
 */
function navid_meta_description() {
	$text = '';
	if ( is_singular() ) {
		$post = get_queried_object();
		$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$text = term_description();
	} elseif ( is_front_page() || is_home() ) {
		$text = get_bloginfo( 'description' );
	}
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( (string) $text ) ) ) );
	return wp_trim_words( $text, 30, '…' );
}

/**
 * Prints the tags in the page head.
 */
function navid_seo_head() {
	if ( navid_seo_plugin_active() || is_404() || is_search() ) {
		return;
	}
	$desc  = navid_meta_description();
	$title = wp_get_document_title();
	$url   = is_singular() ? get_permalink() : ( is_front_page() ? home_url( '/' ) : '' );
	if ( $desc ) {
		echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	echo '<meta property="og:locale" content="fa_IR">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:type" content="' . ( is_singular( 'post' ) ? 'article' : 'website' ) . '">' . "\n";
	if ( $desc ) {
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	if ( $url ) {
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	}
	if ( is_singular() && has_post_thumbnail() ) {
		echo '<meta property="og:image" content="' . esc_url( get_the_post_thumbnail_url( null, 'large' ) ) . '">' . "\n";
	}
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}
add_action( 'wp_head', 'navid_seo_head', 5 );

/**
 * Filtered, sorted and internal-search URLs stay out of the index.
 *
 * @param array $robots Robots directives.
 * @return array
 */
function navid_robots( $robots ) {
	if ( is_search() ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'] );
		return $robots;
	}
	$noisy = array( 'orderby', 'min_price', 'max_price', 'instock', 'npt_add' );
	foreach ( $_GET as $key => $unused ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $key, $noisy, true ) || 0 === strpos( (string) $key, 'filter_' ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
			break;
		}
	}
	return $robots;
}
add_filter( 'wp_robots', 'navid_robots' );
