<?php
/**
 * Site header.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_opt = navid_options();
$header_class = 'navid-header' . ( '1' === $navid_opt['sticky_header'] ? ' is-sticky' : '' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
	<style>:root{--navid-primary:<?php echo esc_html( $navid_opt['primary_color'] ); ?>}</style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="<?php echo esc_attr( $header_class ); ?>">
	<div class="navid-wrap">
		<a class="navid-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php
			if ( has_custom_logo() ) {
				the_custom_logo();
			} else {
				echo esc_html( get_bloginfo( 'name' ) );
			}
			?>
		</a>
		<nav class="navid-nav" aria-label="<?php esc_attr_e( 'منوی اصلی', 'navid' ); ?>">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'fallback_cb'    => false,
			) );
			?>
		</nav>
		<?php if ( function_exists( 'wc_get_cart_url' ) ) : ?>
			<a class="navid-btn navid-cart" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
				<?php esc_html_e( 'سبد خرید', 'navid' ); ?>
			</a>
		<?php endif; ?>
	</div>
</header>
<main class="navid-main">
