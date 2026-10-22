<?php
/**
 * Home page: the sections listed in Settings → Home layout, in that order.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="navid-wrap navid-home">
<?php
foreach ( navid_home_layout() as $navid_section ) {
	if ( ! $navid_section['on'] ) {
		continue;
	}
	switch ( $navid_section['key'] ) {
		case 'slider':
			get_template_part( 'template-parts/home', 'slider' );
			break;
		case 'pricebar':
			echo do_shortcode( '[navid_price_bar]' );
			break;
		case 'content':
			// Whatever is written (or any shortcode placed) in the home page editor.
			$navid_home_post = get_queried_object();
			if ( $navid_home_post instanceof WP_Post && '' !== trim( (string) $navid_home_post->post_content ) ) {
				echo '<div class="navid-home-content navid-entry-body">' . apply_filters( 'the_content', $navid_home_post->post_content ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			break;
		case 'products1':
		case 'products2':
			$navid_n = 'products1' === $navid_section['key'] ? 1 : 2;
			$navid_o = navid_options();
			echo navid_products_slider_html( $navid_o[ 'ps' . $navid_n . '_title' ], $navid_o[ 'ps' . $navid_n . '_ids' ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			break;
		case 'cats':
			get_template_part( 'template-parts/home', 'cats' );
			break;
		case 'blog':
			echo do_shortcode( '[navid_blog count="4"]' );
			break;
	}
}
?>
</div>

<?php
get_footer();
