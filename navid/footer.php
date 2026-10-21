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

<?php
$navid_telegram  = trim( (string) get_option( 'npt_telegram', '' ), " @" );
$navid_instagram = trim( (string) get_option( 'npt_instagram', '' ), " @" );
$navid_cats      = function_exists( 'navid_home_categories' ) ? array_slice( navid_home_categories(), 0, 8 ) : array();
$navid_blog_url  = get_option( 'page_for_posts' ) ? get_permalink( get_option( 'page_for_posts' ) ) : '';
$navid_price_url = function_exists( 'npt_online_view_url' ) ? npt_online_view_url( '' ) : '';
?>
<footer class="navid-footer">
	<div class="navid-wrap navid-footer-grid">
		<div class="navid-footer-col navid-footer-about">
			<a class="navid-footer-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo navid_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
			<?php if ( $navid_opt['about'] ) : ?>
				<p><?php echo esc_html( $navid_opt['about'] ); ?></p>
			<?php endif; ?>
			<?php if ( $navid_telegram || $navid_instagram ) : ?>
				<div class="navid-social">
					<?php if ( $navid_telegram ) : ?><a href="<?php echo esc_url( 'https://t.me/' . $navid_telegram ); ?>" target="_blank" rel="noopener" aria-label="Telegram">تلگرام</a><?php endif; ?>
					<?php if ( $navid_instagram ) : ?><a href="<?php echo esc_url( 'https://instagram.com/' . $navid_instagram ); ?>" target="_blank" rel="noopener" aria-label="Instagram">اینستاگرام</a><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<nav class="navid-footer-col" aria-label="<?php esc_attr_e( 'لینک‌های سایت', 'navid' ); ?>">
			<h3 class="navid-footer-title"><?php esc_html_e( 'دسترسی سریع', 'navid' ); ?></h3>
			<?php
			if ( has_nav_menu( 'footer' ) ) {
				wp_nav_menu( array(
					'theme_location' => 'footer',
					'container'      => false,
					'menu_class'     => 'navid-footer-menu',
					'depth'          => 1,
					'fallback_cb'    => false,
				) );
			} else {
				?>
				<ul class="navid-footer-menu">
					<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'صفحه اصلی', 'navid' ); ?></a></li>
					<li><a href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'فروشگاه', 'navid' ); ?></a></li>
					<?php if ( $navid_price_url ) : ?><li><a href="<?php echo esc_url( $navid_price_url ); ?>" rel="nofollow"><?php esc_html_e( 'لیست قیمت', 'navid' ); ?></a></li><?php endif; ?>
					<?php if ( $navid_blog_url ) : ?><li><a href="<?php echo esc_url( $navid_blog_url ); ?>"><?php esc_html_e( 'وبلاگ', 'navid' ); ?></a></li><?php endif; ?>
					<li><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'حساب کاربری', 'navid' ); ?></a></li>
				</ul>
				<?php
			}
			?>
		</nav>

		<?php if ( $navid_cats ) : ?>
			<nav class="navid-footer-col" aria-label="<?php esc_attr_e( 'دسته‌بندی‌ها', 'navid' ); ?>">
				<h3 class="navid-footer-title"><?php esc_html_e( 'دسته‌بندی محصولات', 'navid' ); ?></h3>
				<ul class="navid-footer-menu">
					<?php foreach ( $navid_cats as $navid_cat ) : ?>
						<li><a href="<?php echo esc_url( get_term_link( $navid_cat ) ); ?>"><?php echo esc_html( $navid_cat->name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>

		<div class="navid-footer-col">
			<h3 class="navid-footer-title"><?php esc_html_e( 'تماس با ما', 'navid' ); ?></h3>
			<ul class="navid-footer-menu navid-footer-contact">
				<?php if ( $navid_opt['address'] ) : ?><li><?php echo esc_html( $navid_opt['address'] ); ?></li><?php endif; ?>
				<?php if ( $navid_opt['phone'] ) : ?><li><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $navid_opt['phone'] ) ); ?>" dir="ltr"><?php echo esc_html( $navid_opt['phone'] ); ?></a></li><?php endif; ?>
				<?php if ( $navid_opt['email'] ) : ?><li><a href="mailto:<?php echo esc_attr( $navid_opt['email'] ); ?>" dir="ltr"><?php echo esc_html( $navid_opt['email'] ); ?></a></li><?php endif; ?>
			</ul>
		</div>
	</div>

	<div class="navid-footer-bottom">
		<div class="navid-wrap">
			<span>&copy; <?php echo esc_html( function_exists( 'npt_jalali_now' ) ? (function () { $j = npt_jalali_now(); return $j['year']; })() : gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?> — <?php esc_html_e( 'تمامی حقوق محفوظ است.', 'navid' ); ?></span>
		</div>
	</div>
</footer>

<?php if ( navid_tabbar_enabled() ) : ?>
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
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
