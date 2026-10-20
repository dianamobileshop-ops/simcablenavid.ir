<?php
/**
 * Site header: desktop top bar + menu row, and the mobile top card.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_opt    = navid_options();
$header_class = 'navid-header' . ( '1' === $navid_opt['sticky_header'] ? ' is-sticky' : '' );
$cart_count   = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
$shop_url     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
$account_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
$cart_url     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
$tel          = preg_replace( '/[^0-9+]/', '', (string) $navid_opt['phone'] );
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
	<div class="navid-wrap navid-top">

		<?php if ( $tel ) : ?>
			<a class="navid-iconbtn navid-phone" href="tel:<?php echo esc_attr( $tel ); ?>" aria-label="<?php esc_attr_e( 'تماس تلفنی', 'navid' ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>
			</a>
		<?php endif; ?>

		<a class="navid-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php
			if ( has_custom_logo() ) {
				the_custom_logo();
			} else {
				echo esc_html( get_bloginfo( 'name' ) );
			}
			?>
		</a>

		<button type="button" class="navid-iconbtn navid-menu-toggle" aria-expanded="false" aria-controls="navid-nav" data-navid-menu-toggle>
			<span class="navid-menu-label"><?php esc_html_e( 'منو', 'navid' ); ?></span>
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
		</button>

		<form class="navid-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="hidden" name="post_type" value="product">
			<label class="screen-reader-text" for="navid-s"><?php esc_html_e( 'جستجو', 'navid' ); ?></label>
			<input id="navid-s" type="search" name="s" placeholder="<?php esc_attr_e( 'جستجو در بین محصولات', 'navid' ); ?>">
			<button type="submit" aria-label="<?php esc_attr_e( 'جستجو', 'navid' ); ?>">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
			</button>
		</form>

		<div class="navid-desk-actions">
			<a class="navid-iconbtn-text" href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'حساب کاربری', 'navid' ); ?></a>
			<a class="navid-iconbtn-text navid-cart" href="<?php echo esc_url( $cart_url ); ?>">
				<?php esc_html_e( 'سبد خرید', 'navid' ); ?>
				<?php echo navid_cart_count_html( $cart_count ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
		</div>
	</div>

	<nav id="navid-nav" class="navid-nav" aria-label="<?php esc_attr_e( 'منوی اصلی', 'navid' ); ?>">
		<div class="navid-wrap">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'navid-menu',
				'fallback_cb'    => false,
			) );
			?>
		</div>
	</nav>
</header>
<main class="navid-main">
