<?php
/**
 * Account menu: profile card and a list of sections with icons.
 *
 * @package Navid
 * @version 9.3.0
 */

defined( 'ABSPATH' ) || exit;

$navid_user  = wp_get_current_user();
$navid_phone = class_exists( 'NPT_Panel' ) ? NPT_Panel::user_mobile( $navid_user ) : '';
$navid_icons = array(
	'dashboard'       => '<path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z"/>',
	'orders'          => '<path d="M6 7h12l1 13H5zM9 7a3 3 0 0 1 6 0"/>',
	'downloads'       => '<path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"/>',
	'edit-address'    => '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
	'edit-account'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
	'customer-logout' => '<path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/>',
);
do_action( 'woocommerce_before_account_navigation' );
?>
<nav class="woocommerce-MyAccount-navigation navid-acc-nav" aria-label="<?php esc_attr_e( 'منوی حساب کاربری', 'navid' ); ?>">
	<div class="navid-acc-profile">
		<span class="navid-acc-avatar"><?php echo esc_html( mb_substr( $navid_user->display_name, 0, 1 ) ); ?></span>
		<div>
			<strong><?php echo esc_html( $navid_user->display_name ); ?></strong>
			<?php if ( $navid_phone ) : ?><small dir="ltr"><?php echo esc_html( $navid_phone ); ?></small><?php endif; ?>
		</div>
		<a class="navid-acc-edit" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>" aria-label="<?php esc_attr_e( 'ویرایش', 'navid' ); ?>">✎</a>
	</div>
	<ul>
		<?php foreach ( wc_get_account_menu_items() as $navid_endpoint => $navid_label ) : ?>
			<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $navid_endpoint ) ); ?>">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( $navid_endpoint ) ); ?>">
					<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo isset( $navid_icons[ $navid_endpoint ] ) ? $navid_icons[ $navid_endpoint ] : '<circle cx="12" cy="12" r="8"/>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></svg>
					<?php echo esc_html( $navid_label ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
<?php do_action( 'woocommerce_after_account_navigation' ); ?>
