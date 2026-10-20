<?php
/**
 * Site footer: desktop columns and the mobile bottom tab bar.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_opt   = navid_options();
$shop_url    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
$cart_url    = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
$cart_count  = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
?>
</main>

<footer class="navid-footer">
	<div class="navid-wrap navid-footer-grid">
		<div class="navid-footer-col">
			<p class="navid-footer-name"><?php bloginfo( 'name' ); ?></p>
			<?php if ( $navid_opt['address'] ) : ?>
				<p><?php echo esc_html( $navid_opt['address'] ); ?></p>
			<?php endif; ?>
			<?php if ( $navid_opt['phone'] ) : ?>
				<p><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $navid_opt['phone'] ) ); ?>" dir="ltr"><?php echo esc_html( $navid_opt['phone'] ); ?></a></p>
			<?php endif; ?>
		</div>

		<?php if ( has_nav_menu( 'footer' ) ) : ?>
			<nav class="navid-footer-col" aria-label="<?php esc_attr_e( 'لینک‌های سایت', 'navid' ); ?>">
				<?php
				wp_nav_menu( array(
					'theme_location' => 'footer',
					'container'      => false,
					'menu_class'     => 'navid-footer-menu',
					'fallback_cb'    => false,
				) );
				?>
			</nav>
		<?php endif; ?>

		<div class="navid-footer-col navid-footer-copy">
			<p>&copy; <?php echo esc_html( function_exists( 'npt_jalali_now' ) ? (function () { $j = npt_jalali_now(); return $j['year']; })() : gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
		</div>
	</div>
</footer>

<nav class="navid-tabbar" aria-label="<?php esc_attr_e( 'دسترسی سریع', 'navid' ); ?>">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z"/></svg>
		<span><?php esc_html_e( 'خانه', 'navid' ); ?></span>
	</a>
	<a href="#navid-s" data-navid-search>
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
		<span><?php esc_html_e( 'جستجو', 'navid' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $shop_url ); ?>">
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
		<span><?php esc_html_e( 'دسته‌بندی', 'navid' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $cart_url ); ?>" class="navid-tab-cart">
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12zM6 6 5 3H2"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/></svg>
		<span><?php esc_html_e( 'سبد خرید', 'navid' ); ?></span>
		<?php echo navid_cart_count_html( $cart_count ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</a>
	<a href="<?php echo esc_url( $account_url ); ?>">
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
		<span><?php esc_html_e( 'حساب کاربری', 'navid' ); ?></span>
	</a>
</nav>

<?php wp_footer(); ?>
</body>
</html>
