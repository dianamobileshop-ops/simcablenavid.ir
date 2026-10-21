<?php
/**
 * Site footer: trust strip, columns (about, links, categories, contact, trust badges),
 * and copyright. The phone bottom bar comes from the price table settings.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_opt      = navid_options();
$navid_wc       = navid_wc();
$shop_url       = $navid_wc ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
$account_url    = $navid_wc ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
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
				<details class="navid-fold" open>
					<summary class="navid-ft-title"><?php echo navid_icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'دسترسی سریع', 'navid' ); ?></span><svg class="navid-fchev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
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
				</details>
			</nav>

			<?php if ( $navid_cats ) : ?>
				<nav class="navid-ft-col" aria-label="<?php esc_attr_e( 'دسته‌بندی‌ها', 'navid' ); ?>">
					<details class="navid-fold" open>
						<summary class="navid-ft-title"><?php echo navid_icon( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'دسته‌بندی محصولات', 'navid' ); ?></span><svg class="navid-fchev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
					<ul class="navid-ft-links">
						<?php foreach ( $navid_cats as $navid_cat ) : ?>
							<li><a href="<?php echo esc_url( get_term_link( $navid_cat ) ); ?>"><?php echo esc_html( $navid_cat->name ); ?></a></li>
						<?php endforeach; ?>
					</ul>
					</details>
				</nav>
			<?php endif; ?>

			<section class="navid-ft-col navid-ft-contact">
				<details class="navid-fold" open>
					<summary class="navid-ft-title"><?php echo navid_icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'تماس با ما', 'navid' ); ?></span><svg class="navid-fchev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
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
				</details>
			</section>

			<?php if ( $navid_badges ) : ?>
				<section class="navid-ft-col navid-ft-trust">
					<details class="navid-fold" open>
						<summary class="navid-ft-title"><?php echo navid_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'نمادهای اعتماد', 'navid' ); ?></span><svg class="navid-fchev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
					<div class="navid-ft-badges"><?php echo $navid_badges; // phpcs:ignore WordPress.Security.EscapeOutput -- built from parsed ids only ?></div>
					</details>
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
