<?php
/**
 * Site footer: trust strip, columns (about, links, categories, contact, trust badges),
 * copyright, and the mobile bottom tab bar.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_opt      = navid_options();
$navid_wc       = navid_wc();
$shop_url       = $navid_wc ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
$account_url    = $navid_wc ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
$cart_url       = $navid_wc ? wc_get_cart_url() : home_url( '/' );
$cart_count     = ( $navid_wc && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
$navid_cats     = $navid_wc ? array_slice( navid_home_categories(), 0, 7 ) : array();
$navid_blog_url = get_option( 'page_for_posts' ) ? get_permalink( get_option( 'page_for_posts' ) ) : '';
$navid_price    = ( $navid_wc && function_exists( 'npt_online_view_url' ) ) ? npt_online_view_url( '' ) : '';
$navid_socials  = navid_social_links();
$navid_features = navid_footer_features();
$navid_badges   = navid_trust_badges_html();
$navid_tel      = preg_replace( '/[^0-9+]/', '', navid_en_digits( (string) $navid_opt['phone'] ) );
?>
</main>

<footer class="navid-footer">
	<?php if ( $navid_features ) : ?>
		<div class="navid-wrap">
			<ul class="navid-ft-features">
				<?php foreach ( $navid_features as $navid_i => $navid_feature ) : ?>
					<li>
						<span class="navid-ft-ficon" aria-hidden="true"><?php echo navid_icon( array( 'truck', 'shield', 'card', 'headset' )[ $navid_i % 4 ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<span><strong><?php echo esc_html( $navid_feature[0] ); ?></strong><?php if ( '' !== $navid_feature[1] ) : ?><small><?php echo esc_html( $navid_feature[1] ); ?></small><?php endif; ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div class="navid-ft-main">
		<div class="navid-wrap navid-ft-grid<?php echo $navid_badges ? ' has-badges' : ''; ?>">
			<section class="navid-ft-col navid-ft-about">
				<a class="navid-ft-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo navid_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
				<p><?php echo esc_html( '' !== $navid_opt['about'] ? $navid_opt['about'] : get_bloginfo( 'description' ) ); ?></p>
				<?php if ( $navid_socials ) : ?>
					<div class="navid-ft-social">
						<?php foreach ( $navid_socials as $navid_social ) : ?>
							<a href="<?php echo esc_url( $navid_social['url'] ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( $navid_social['label'] ); ?>" title="<?php echo esc_attr( $navid_social['label'] ); ?>"><?php echo navid_icon( $navid_social['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<nav class="navid-ft-col" aria-label="<?php esc_attr_e( 'دسترسی سریع', 'navid' ); ?>">
				<h2 class="navid-ft-title"><?php esc_html_e( 'دسترسی سریع', 'navid' ); ?></h2>
				<?php
				if ( has_nav_menu( 'footer' ) ) {
					wp_nav_menu( array(
						'theme_location' => 'footer',
						'container'      => false,
						'menu_class'     => 'navid-ft-links',
						'depth'          => 1,
						'fallback_cb'    => false,
					) );
				} else {
					?>
					<ul class="navid-ft-links">
						<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'صفحه اصلی', 'navid' ); ?></a></li>
						<?php if ( $navid_wc ) : ?><li><a href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'فروشگاه', 'navid' ); ?></a></li><?php endif; ?>
						<?php if ( $navid_price ) : ?><li><a href="<?php echo esc_url( $navid_price ); ?>" rel="nofollow"><?php esc_html_e( 'لیست قیمت', 'navid' ); ?></a></li><?php endif; ?>
						<?php if ( $navid_blog_url ) : ?><li><a href="<?php echo esc_url( $navid_blog_url ); ?>"><?php esc_html_e( 'وبلاگ', 'navid' ); ?></a></li><?php endif; ?>
						<li><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'حساب کاربری', 'navid' ); ?></a></li>
					</ul>
					<?php
				}
				?>
			</nav>

			<?php if ( $navid_cats ) : ?>
				<nav class="navid-ft-col" aria-label="<?php esc_attr_e( 'دسته‌بندی‌ها', 'navid' ); ?>">
					<h2 class="navid-ft-title"><?php esc_html_e( 'دسته‌بندی محصولات', 'navid' ); ?></h2>
					<ul class="navid-ft-links">
						<?php foreach ( $navid_cats as $navid_cat ) : ?>
							<li><a href="<?php echo esc_url( get_term_link( $navid_cat ) ); ?>"><?php echo esc_html( $navid_cat->name ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<section class="navid-ft-col navid-ft-contact">
				<h2 class="navid-ft-title"><?php esc_html_e( 'تماس با ما', 'navid' ); ?></h2>
				<ul>
					<?php if ( $navid_opt['address'] ) : ?>
						<li><?php echo navid_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html( $navid_opt['address'] ); ?></span></li>
					<?php endif; ?>
					<?php if ( $navid_tel ) : ?>
						<li><?php echo navid_icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><a href="tel:<?php echo esc_attr( $navid_tel ); ?>" dir="ltr"><?php echo esc_html( $navid_opt['phone'] ); ?></a></li>
					<?php endif; ?>
					<?php if ( $navid_opt['email'] ) : ?>
						<li><?php echo navid_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><a href="mailto:<?php echo esc_attr( $navid_opt['email'] ); ?>" dir="ltr"><?php echo esc_html( $navid_opt['email'] ); ?></a></li>
					<?php endif; ?>
					<?php if ( $navid_opt['hours'] ) : ?>
						<li><?php echo navid_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html( $navid_opt['hours'] ); ?></span></li>
					<?php endif; ?>
				</ul>
			</section>

			<?php if ( $navid_badges ) : ?>
				<section class="navid-ft-col navid-ft-trust">
					<h2 class="navid-ft-title"><?php esc_html_e( 'نمادهای اعتماد', 'navid' ); ?></h2>
					<div class="navid-ft-badges"><?php echo $navid_badges; // phpcs:ignore WordPress.Security.EscapeOutput -- built from parsed ids only ?></div>
				</section>
			<?php endif; ?>
		</div>
	</div>

	<div class="navid-ft-bottom">
		<div class="navid-wrap">
			<span><?php echo esc_html( navid_copyright_text() ); ?></span>
			<button type="button" class="navid-totop" data-navid-totop aria-label="<?php esc_attr_e( 'بازگشت به بالا', 'navid' ); ?>"><?php echo navid_icon( 'up' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
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
	<?php if ( $navid_wc ) : ?>
	<a href="<?php echo esc_url( $shop_url ); ?>">
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
		<span><?php esc_html_e( 'دسته‌بندی', 'navid' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $cart_url ); ?>" class="navid-tab-cart">
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12zM6 6 5 3H2"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/></svg>
		<span><?php esc_html_e( 'سبد خرید', 'navid' ); ?></span>
		<?php echo navid_cart_count_html( $cart_count ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</a>
	<?php elseif ( $navid_blog_url ) : ?>
	<a href="<?php echo esc_url( $navid_blog_url ); ?>">
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg>
		<span><?php esc_html_e( 'وبلاگ', 'navid' ); ?></span>
	</a>
	<?php endif; ?>
	<a href="<?php echo esc_url( $account_url ); ?>"<?php echo ( is_user_logged_in() || ! $navid_wc ) ? '' : ' data-navid-login'; ?>>
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
		<span><?php esc_html_e( 'حساب کاربری', 'navid' ); ?></span>
	</a>
</nav>
<?php endif; ?>

<?php if ( navid_login_modal_enabled() ) : ?>
<div class="navid-modal" id="navid-login-modal" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'ورود | ثبت‌نام', 'navid' ); ?>" hidden>
	<div class="navid-modal-backdrop"></div>
	<div class="navid-modal-dialog">
		<button type="button" class="navid-modal-close" aria-label="<?php esc_attr_e( 'بستن', 'navid' ); ?>">×</button>
		<?php get_template_part( 'template-parts/login-card', null, array( 'id' => 'modal', 'redirect' => navid_current_url() ) ); ?>
	</div>
</div>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
