<?php
/**
 * Login / sign-up card, used on the account page and inside the login pop-up.
 *
 * Args: id (unique prefix for field ids), redirect (where password login returns to).
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_args     = wp_parse_args( isset( $args ) ? $args : array(), array( 'id' => 'a', 'redirect' => '' ) );
$navid_p        = sanitize_key( $navid_args['id'] );
$navid_ready    = class_exists( 'NPT_OTP' ) && class_exists( 'NPT_SMS' ) && NPT_OTP::enabled() && NPT_SMS::otp_ready();
$navid_opt      = navid_options();
$navid_sms      = $navid_ready && '0' !== (string) $navid_opt['login_sms'];
$navid_pass     = '0' !== (string) $navid_opt['login_password'] || ! $navid_sms;
$navid_reg      = $navid_ready && '0' !== (string) $navid_opt['login_register'];
$navid_account  = wc_get_page_permalink( 'myaccount' );
$navid_first    = $navid_sms ? 'sms' : 'password';
?>
<div class="navid-auth-card" data-navid-card>
	<a class="navid-auth-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php bloginfo( 'name' ); ?>"><?php echo navid_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
	<h2 class="navid-auth-title"><?php esc_html_e( 'ورود | ثبت‌نام', 'navid' ); ?></h2>

	<?php if ( ( $navid_sms ? 1 : 0 ) + ( $navid_pass ? 1 : 0 ) + ( $navid_reg ? 1 : 0 ) > 1 ) : ?>
	<div class="navid-auth-tabs" role="tablist">
		<?php if ( $navid_sms ) : ?>
			<button type="button" role="tab" class="<?php echo 'sms' === $navid_first ? 'is-on' : ''; ?>" data-navid-tab="sms"><?php esc_html_e( 'ورود با شماره', 'navid' ); ?></button>
		<?php endif; ?>
		<?php if ( $navid_pass ) : ?>
			<button type="button" role="tab" class="<?php echo 'password' === $navid_first ? 'is-on' : ''; ?>" data-navid-tab="password"><?php esc_html_e( 'رمز عبور', 'navid' ); ?></button>
		<?php endif; ?>
		<?php if ( $navid_reg ) : ?>
			<button type="button" role="tab" data-navid-tab="register"><?php esc_html_e( 'ثبت‌نام', 'navid' ); ?></button>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ( $navid_sms ) : ?>
		<section class="navid-auth-panel" data-navid-panel="sms" <?php echo 'sms' === $navid_first ? '' : 'hidden'; ?>>
			<form class="navid-otp-phone" novalidate>
				<label for="navid-<?php echo esc_attr( $navid_p ); ?>-phone"><?php esc_html_e( 'شمارهٔ موبایل خود را وارد کنید', 'navid' ); ?></label>
				<input id="navid-<?php echo esc_attr( $navid_p ); ?>-phone" type="tel" inputmode="tel" dir="ltr" autocomplete="tel" placeholder="09121234567">
				<button type="submit" class="navid-auth-btn"><?php esc_html_e( 'دریافت کد تأیید', 'navid' ); ?></button>
			</form>
			<form class="navid-otp-code" hidden novalidate>
				<label for="navid-<?php echo esc_attr( $navid_p ); ?>-code"><?php esc_html_e( 'کد ارسال‌شده را وارد کنید', 'navid' ); ?></label>
				<input id="navid-<?php echo esc_attr( $navid_p ); ?>-code" type="text" inputmode="numeric" dir="ltr" autocomplete="one-time-code" maxlength="6" placeholder="••••••">
				<button type="submit" class="navid-auth-btn"><?php esc_html_e( 'ورود', 'navid' ); ?></button>
				<button type="button" class="navid-auth-link navid-otp-resend" disabled><?php esc_html_e( 'ارسال دوباره', 'navid' ); ?> (<span>60</span>)</button>
				<button type="button" class="navid-auth-link navid-otp-change"><?php esc_html_e( 'تغییر شماره', 'navid' ); ?></button>
			</form>
			<p class="navid-auth-status" role="status"></p>
			<p class="navid-auth-foot"><?php echo esc_html( $navid_reg ? __( 'با شمارهٔ ثبت‌شده وارد شوید. اگر حساب ندارید از تب «ثبت‌نام» استفاده کنید.', 'navid' ) : __( 'با شمارهٔ ثبت‌شده وارد شوید.', 'navid' ) ); ?></p>
		</section>
	<?php endif; ?>

	<?php if ( $navid_pass ) : ?>
	<section class="navid-auth-panel" data-navid-panel="password" <?php echo 'password' === $navid_first ? '' : 'hidden'; ?>>
		<form class="woocommerce-form woocommerce-form-login login" method="post" action="<?php echo esc_url( $navid_account ); ?>">
			<?php do_action( 'woocommerce_login_form_start' ); ?>
			<label for="navid-<?php echo esc_attr( $navid_p ); ?>-user"><?php esc_html_e( 'نام کاربری یا ایمیل', 'navid' ); ?></label>
			<input type="text" name="username" id="navid-<?php echo esc_attr( $navid_p ); ?>-user" autocomplete="username" dir="ltr" required>
			<label for="navid-<?php echo esc_attr( $navid_p ); ?>-pass"><?php esc_html_e( 'رمز عبور', 'navid' ); ?></label>
			<input type="password" name="password" id="navid-<?php echo esc_attr( $navid_p ); ?>-pass" autocomplete="current-password" dir="ltr" required>
			<?php do_action( 'woocommerce_login_form' ); ?>
			<label class="navid-auth-remember"><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e( 'مرا به خاطر بسپار', 'navid' ); ?></label>
			<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
			<?php if ( $navid_args['redirect'] ) : ?>
				<input type="hidden" name="redirect" value="<?php echo esc_url( $navid_args['redirect'] ); ?>">
			<?php endif; ?>
			<button type="submit" class="navid-auth-btn" name="login" value="<?php esc_attr_e( 'ورود', 'navid' ); ?>"><?php esc_html_e( 'ورود', 'navid' ); ?></button>
			<a class="navid-auth-link" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'رمز عبور را فراموش کرده‌اید؟', 'navid' ); ?></a>
			<?php do_action( 'woocommerce_login_form_end' ); ?>
		</form>
	</section>
	<?php endif; ?>

	<?php if ( $navid_reg ) : ?>
		<section class="navid-auth-panel" data-navid-panel="register" hidden>
			<form class="navid-reg-form" novalidate>
				<label for="navid-<?php echo esc_attr( $navid_p ); ?>-rname"><?php esc_html_e( 'نام و نام خانوادگی', 'navid' ); ?></label>
				<input id="navid-<?php echo esc_attr( $navid_p ); ?>-rname" type="text" name="name" autocomplete="name">
				<label for="navid-<?php echo esc_attr( $navid_p ); ?>-rphone"><?php esc_html_e( 'شمارهٔ موبایل', 'navid' ); ?></label>
				<input id="navid-<?php echo esc_attr( $navid_p ); ?>-rphone" type="tel" name="phone" inputmode="tel" dir="ltr" autocomplete="tel" placeholder="09121234567">
				<label class="navid-reg-cap"><span class="navid-cap-q">…</span> <input type="text" name="captcha_answer" inputmode="numeric" dir="ltr" autocomplete="off" style="width:90px"></label>
				<input type="hidden" name="captcha_id" value="">
				<input type="text" name="npt_website" value="" tabindex="-1" autocomplete="off" class="navid-hp" aria-hidden="true">
				<button type="submit" class="navid-auth-btn"><?php esc_html_e( 'دریافت کد تأیید', 'navid' ); ?></button>
			</form>
			<form class="navid-reg-code" hidden novalidate>
				<label for="navid-<?php echo esc_attr( $navid_p ); ?>-rcode"><?php esc_html_e( 'کد ارسال‌شده را وارد کنید', 'navid' ); ?></label>
				<input id="navid-<?php echo esc_attr( $navid_p ); ?>-rcode" type="text" name="code" inputmode="numeric" dir="ltr" autocomplete="one-time-code" maxlength="6" placeholder="••••••">
				<button type="submit" class="navid-auth-btn"><?php esc_html_e( 'تأیید و ثبت‌نام', 'navid' ); ?></button>
				<button type="button" class="navid-auth-link navid-reg-back"><?php esc_html_e( 'تغییر شماره', 'navid' ); ?></button>
			</form>
			<p class="navid-auth-status navid-reg-status" role="status"></p>
		</section>
	<?php endif; ?>

	<?php if ( '' !== trim( (string) $navid_opt['login_note'] ) ) : ?>
		<p class="navid-auth-foot"><?php echo esc_html( $navid_opt['login_note'] ); ?></p>
	<?php endif; ?>
</div>
