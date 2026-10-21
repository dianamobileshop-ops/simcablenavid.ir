<?php
/**
 * Account dashboard: order summary, latest orders and shortcuts.
 *
 * @package Navid
 * @version 4.4.0
 */

defined( 'ABSPATH' ) || exit;

$navid_uid    = get_current_user_id();
$navid_count  = static function ( $statuses ) use ( $navid_uid ) {
	return count( wc_get_orders( array(
		'customer_id' => $navid_uid,
		'status'      => $statuses,
		'limit'       => -1,
		'return'      => 'ids',
	) ) );
};
$navid_groups = array(
	array( 'جاری', $navid_count( array( 'pending', 'on-hold', 'processing' ) ), '#1d6fd8' ),
	array( 'تحویل‌شده', $navid_count( array( 'completed' ) ), '#1faa6b' ),
	array( 'لغو / مرجوعی', $navid_count( array( 'cancelled', 'refunded', 'failed' ) ), '#e6a23c' ),
);
$navid_recent = wc_get_orders( array(
	'customer_id' => $navid_uid,
	'limit'       => 3,
	'orderby'     => 'date',
	'order'       => 'DESC',
) );
$navid_links  = array(
	array( 'همهٔ سفارش‌ها', wc_get_account_endpoint_url( 'orders' ) ),
	array( 'آدرس‌ها', wc_get_account_endpoint_url( 'edit-address' ) ),
	array( 'جزئیات حساب', wc_get_account_endpoint_url( 'edit-account' ) ),
	array( 'فروشگاه', function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ),
);
if ( function_exists( 'npt_online_view_url' ) ) {
	$navid_links[] = array( 'لیست قیمت', npt_online_view_url( '' ) );
}
if ( function_exists( 'navid_proforma_url' ) && navid_proforma_url() ) {
	$navid_links[] = array( 'پیش‌فاکتور جدید', navid_proforma_url() );
}
?>
<?php if ( class_exists( 'NPT_Panel' ) && class_exists( 'NPT_OTP' ) && NPT_OTP::enabled() && '' === NPT_Panel::user_mobile( wp_get_current_user() ) ) : ?>
<section class="navid-dash-card navid-bind" data-navid-bind>
	<header class="navid-dash-head"><h2><?php esc_html_e( 'تأیید شمارهٔ موبایل', 'navid' ); ?></h2></header>
	<p><?php esc_html_e( 'برای ورود سریع با کد پیامکی، شمارهٔ موبایل خود را وارد و تأیید کنید.', 'navid' ); ?></p>
	<form class="navid-bind-phone" novalidate>
		<input type="tel" name="phone" inputmode="tel" dir="ltr" autocomplete="tel" placeholder="09121234567" required>
		<button type="submit" class="navid-auth-btn"><?php esc_html_e( 'دریافت کد تأیید', 'navid' ); ?></button>
	</form>
	<form class="navid-bind-code" hidden novalidate>
		<input type="text" name="code" inputmode="numeric" dir="ltr" autocomplete="one-time-code" maxlength="6" placeholder="••••••" required>
		<button type="submit" class="navid-auth-btn"><?php esc_html_e( 'تأیید و ذخیره', 'navid' ); ?></button>
	</form>
	<p class="navid-auth-status" role="status"></p>
</section>
<?php endif; ?>
<section class="navid-dash-card">
	<header class="navid-dash-head">
		<h2><?php esc_html_e( 'سفارش‌های من', 'navid' ); ?></h2>
		<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>"><?php esc_html_e( 'مشاهده همه', 'navid' ); ?> ‹</a>
	</header>
	<div class="navid-dash-stats">
		<?php foreach ( $navid_groups as $navid_group ) : ?>
			<div class="navid-dash-stat" style="--c:<?php echo esc_attr( $navid_group[2] ); ?>">
				<b><?php echo esc_html( number_format_i18n( $navid_group[1] ) ); ?></b>
				<span><?php echo esc_html( $navid_group[0] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>
</section>

<section class="navid-dash-card">
	<header class="navid-dash-head">
		<h2><?php esc_html_e( 'آخرین سفارش‌ها', 'navid' ); ?></h2>
	</header>
	<?php if ( $navid_recent ) : ?>
		<ul class="navid-dash-orders">
			<?php foreach ( $navid_recent as $navid_order ) : ?>
				<li>
					<a href="<?php echo esc_url( $navid_order->get_view_order_url() ); ?>">
						<strong>#<?php echo esc_html( $navid_order->get_order_number() ); ?></strong>
						<span><?php echo esc_html( wc_format_datetime( $navid_order->get_date_created() ) ); ?></span>
						<span class="navid-dash-status"><?php echo esc_html( wc_get_order_status_name( $navid_order->get_status() ) ); ?></span>
						<em><?php echo wp_kses_post( $navid_order->get_formatted_order_total() ); ?></em>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="navid-dash-empty"><?php esc_html_e( 'هنوز سفارشی ثبت نکرده‌اید.', 'navid' ); ?> <a href="<?php echo esc_url( $navid_links[3][1] ); ?>"><?php esc_html_e( 'رفتن به فروشگاه', 'navid' ); ?></a></p>
	<?php endif; ?>
</section>

<section class="navid-dash-card">
	<header class="navid-dash-head"><h2><?php esc_html_e( 'دسترسی سریع', 'navid' ); ?></h2></header>
	<div class="navid-tiles navid-tiles--front">
		<?php foreach ( $navid_links as $navid_link ) : ?>
			<a class="navid-tile" href="<?php echo esc_url( $navid_link[1] ); ?>"><?php echo esc_html( $navid_link[0] ); ?></a>
		<?php endforeach; ?>
	</div>
</section>

<?php
do_action( 'woocommerce_account_dashboard' );
do_action( 'woocommerce_before_my_account' );
do_action( 'woocommerce_after_my_account' );
