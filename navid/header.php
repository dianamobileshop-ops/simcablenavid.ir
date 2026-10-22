<?php
/**
 * Site header: desktop top bar + menu row, and the mobile top card.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_opt    = navid_options();
$header_class = 'navid-header' . ( '1' === $navid_opt['sticky_header'] ? ' is-sticky' : '' ) . ( '1' === $navid_opt['sticky_header_mobile'] ? ' is-sticky-m' : '' );
$cart_count   = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
$shop_url     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
$account_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
$cart_url     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
$product_count = ( function_exists( 'wp_count_posts' ) && post_type_exists( 'product' ) ) ? (int) wp_count_posts( 'product' )->publish : 0;
$search_hint  = $product_count ? sprintf( 'جستجو در بین %s محصول', number_format_i18n( $product_count ) ) : ( navid_wc() ? 'جستجو در محصولات' : 'جستجو در سایت' );
$tel          = preg_replace( '/[^0-9+]/', '', (string) $navid_opt['phone'] );
?><!doctype html>
<html <?php language_attributes(); ?><?php echo is_rtl() ? '' : ' dir="rtl"'; ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
	<style>:root{--navid-primary:<?php echo esc_html( $navid_opt['primary_color'] ); ?>;--navid-primary-soft:color-mix(in srgb,<?php echo esc_html( $navid_opt['primary_color'] ); ?> 10%,#fff);--navid-accent:<?php echo esc_html( $navid_opt['accent_color'] ); ?>;--navid-footer:<?php echo esc_html( $navid_opt['footer_bg'] ); ?>}
	</style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="<?php echo esc_attr( $header_class ); ?>">
	<div class="navid-wrap navid-top">

		<button type="button" class="navid-iconbtn navid-menu-toggle" aria-expanded="false" aria-controls="navid-nav" data-navid-menu-toggle>
			<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
			<span class="navid-menu-label"><?php esc_html_e( 'منو', 'navid' ); ?></span>
		</button>

		<a class="navid-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php bloginfo( 'name' ); ?>">
			<?php echo navid_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</a>

		<a class="navid-iconbtn navid-phone" href="<?php echo esc_url( $account_url ); ?>" aria-label="<?php echo esc_attr( is_user_logged_in() ? __( 'حساب کاربری', 'navid' ) : __( 'ورود / ثبت نام', 'navid' ) ); ?>"<?php echo ( is_user_logged_in() || ! navid_wc() ) ? '' : ' data-navid-login'; ?>>
			<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
		</a>

		<form class="navid-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php if ( navid_wc() ) : ?><input type="hidden" name="post_type" value="product"><?php endif; ?>
			<button type="submit" aria-label="<?php esc_attr_e( 'جستجو', 'navid' ); ?>">
				<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
			</button>
			<label class="screen-reader-text" for="navid-s"><?php esc_html_e( 'جستجو', 'navid' ); ?></label>
			<input id="navid-s" type="search" name="s" placeholder="<?php echo esc_attr( $search_hint ); ?>" autocomplete="off">
			<div class="navid-live" hidden></div>
		</form>

		<?php if ( navid_wc() ) : ?>
		<a class="navid-mcart" href="<?php echo esc_url( $cart_url ); ?>" aria-label="<?php esc_attr_e( 'سبد خرید', 'navid' ); ?>">
			<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12zM6 6 5 3H2"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/></svg>
			<?php echo navid_cart_count_html( $cart_count ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</a>
		<?php endif; ?>

		<div class="navid-desk-actions">
			<a class="navid-account" href="<?php echo esc_url( $account_url ); ?>"<?php echo is_user_logged_in() ? '' : ' data-navid-login'; ?>>
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
				<?php echo esc_html( is_user_logged_in() ? __( 'حساب کاربری', 'navid' ) : __( 'ورود / ثبت نام', 'navid' ) ); ?>
			</a>
			<?php if ( navid_wc() ) : ?>
			<a class="navid-cart" href="<?php echo esc_url( $cart_url ); ?>" aria-label="<?php esc_attr_e( 'سبد خرید', 'navid' ); ?>">
				<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12zM6 6 5 3H2"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/></svg>
				<?php echo navid_cart_count_html( $cart_count ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
			<?php endif; ?>
		</div>
	</div>

	<nav id="navid-nav" class="navid-nav" aria-label="<?php esc_attr_e( 'منوی اصلی', 'navid' ); ?>">
		<div class="navid-wrap navid-navrow">
			<?php echo navid_catmenu_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in inc/woocommerce.php ?>
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'navid-menu',
				'fallback_cb'    => false,
			) );
			?>
			<?php if ( $navid_opt['special_label'] && $navid_opt['special_url'] ) : ?>
				<a class="navid-special" href="<?php echo esc_url( $navid_opt['special_url'] ); ?>"><?php echo esc_html( $navid_opt['special_label'] ); ?></a>
			<?php endif; ?>
		</div>
	</nav>
</header>
<main class="navid-main">
