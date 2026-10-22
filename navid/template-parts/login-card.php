<?php
/**
 * Login / sign-up card, used on the account page and inside the login pop-up.
 *
 * With SMS on, one flow does both: mobile number → code (animated boxes) → «نام و نام‌خانوادگی»
 * for a number that has no account yet. The password form stays as a second tab.
 *
 * Args: id (unique prefix for field ids), redirect (where password login returns to).
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_args    = wp_parse_args( isset( $args ) ? $args : array(), array( 'id' => 'a', 'redirect' => '' ) );
$navid_p       = sanitize_key( $navid_args['id'] );
$navid_ready   = class_exists( 'NPT_OTP' ) && class_exists( 'NPT_SMS' ) && NPT_OTP::enabled() && NPT_SMS::otp_ready();
$navid_opt     = navid_options();
$navid_sms     = $navid_ready && '0' !== (string) $navid_opt['login_sms'];
$navid_pass    = '0' !== (string) $navid_opt['login_password'] || ! $navid_sms;
$navid_reg     = $navid_ready && '0' !== (string) $navid_opt['login_register'];
$navid_account = wc_get_page_permalink( 'myaccount' );
$navid_first   = $navid_sms ? 'sms' : 'password';
$navid_digits  = ( $navid_sms && class_exists( 'NPT_Bar' ) ) ? min( 6, max( 4, (int) NPT_Bar::settings()['otp_digits'] ) ) : 5;
$navid_title   = $navid_reg ? __( 'ورود | ثبت‌نام', 'navid' ) : __( 'ورود به حساب', 'navid' );
$navid_sub     = $navid_sms
	? ( $navid_reg ? __( 'شمارهٔ موبایل خود را وارد کنید. اگر حساب ندارید، همین‌جا ساخته می‌شود.', 'navid' ) : __( 'با شمارهٔ موبایل ثبت‌شده وارد شوید.', 'navid' ) )
	: __( 'نام کاربری و رمز عبور خود را وارد کنید.', 'navid' );
?>
<div class="navid-auth-card navid-lg" data-navid-card data-digits="<?php echo (int) $navid_digits; ?>">
	<div class="navid-lg-hero">
		<a class="navid-auth-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php bloginfo( 'name' ); ?>"><?php echo navid_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
		<h2 class="navid-lg-title"><?php echo esc_html( $navid_title ); ?></h2>
		<p class="navid-lg-sub"><?php echo esc_html( $navid_sub ); ?></p>
	</div>

	<div class="navid-lg-body">
		<?php if ( $navid_sms && $navid_pass ) : ?>
			<div class="navid-auth-tabs" role="tablist">
				<button type="button" role="tab" class="is-on" data-navid-tab="sms"><?php esc_html_e( 'ورود با شماره', 'navid' ); ?></button>
				<button type="button" role="tab" data-navid-tab="password"><?php esc_html_e( 'رمز عبور', 'navid' ); ?></button>
			</div>
		<?php endif; ?>

		<?php if ( $navid_sms ) : ?>
			<section class="navid-auth-panel navid-lg-panel" data-navid-panel="sms">
				<div class="navid-lg-steps" data-step="phone">
					<form class="navid-lg-step navid-otp-phone" data-step-name="phone" novalidate>
						<label for="navid-<?php echo esc_attr( $navid_p ); ?>-phone"><?php esc_html_e( 'شمارهٔ موبایل', 'navid' ); ?></label>
						<div class="navid-lg-phone" dir="ltr">
							<span aria-hidden="true">🇮🇷 +98</span>
							<input id="navid-<?php echo esc_attr( $navid_p ); ?>-phone" type="tel" inputmode="numeric" autocomplete="tel" maxlength="14" placeholder="09123456789" enterkeyhint="send">
						</div>
						<button type="submit" class="navid-lg-btn"><span><?php esc_html_e( 'دریافت کد تأیید', 'navid' ); ?></span></button>
					</form>

					<form class="navid-lg-step navid-otp-code" data-step-name="code" hidden novalidate>
						<p class="navid-lg-sent"><?php esc_html_e( 'کد تأیید به شمارهٔ', 'navid' ); ?> <b class="navid-lg-num" dir="ltr"></b> <?php esc_html_e( 'ارسال شد.', 'navid' ); ?>
							<button type="button" class="navid-lg-link navid-otp-change"><?php esc_html_e( 'ویرایش شماره', 'navid' ); ?></button></p>
						<div class="navid-lg-otp" dir="ltr" aria-label="<?php esc_attr_e( 'کد تأیید', 'navid' ); ?>"></div>
						<div class="navid-lg-resend">
							<svg class="navid-lg-ring" viewBox="0 0 36 36" width="34" height="34" aria-hidden="true"><circle cx="18" cy="18" r="15" fill="none" stroke-width="3"/></svg>
							<button type="button" class="navid-lg-link navid-otp-resend" disabled><?php esc_html_e( 'ارسال دوباره', 'navid' ); ?> (<span>60</span>)</button>
						</div>
						<button type="submit" class="navid-lg-btn"><span><?php esc_html_e( 'تأیید و ورود', 'navid' ); ?></span></button>
					</form>

					<form class="navid-lg-step navid-name-form" data-step-name="name" hidden novalidate>
						<p class="navid-lg-sent"><?php esc_html_e( 'خوش آمدید! برای تکمیل ثبت‌نام، نام خود را وارد کنید.', 'navid' ); ?></p>
						<label for="navid-<?php echo esc_attr( $navid_p ); ?>-name"><?php esc_html_e( 'نام و نام خانوادگی', 'navid' ); ?></label>
						<input id="navid-<?php echo esc_attr( $navid_p ); ?>-name" class="navid-lg-input" type="text" name="name" autocomplete="name" placeholder="<?php esc_attr_e( 'مثلاً علی رضایی', 'navid' ); ?>">
						<button type="submit" class="navid-lg-btn"><span><?php esc_html_e( 'ثبت و ادامه', 'navid' ); ?></span></button>
					</form>

					<div class="navid-lg-step navid-lg-done" data-step-name="done" hidden>
						<svg viewBox="0 0 52 52" width="76" height="76" aria-hidden="true"><circle class="c" cx="26" cy="26" r="24" fill="none" stroke-width="3"/><path class="t" d="M15 27l8 8 14-16" fill="none" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<p class="navid-lg-done-text"><?php esc_html_e( 'با موفقیت وارد شدید', 'navid' ); ?></p>
					</div>

					<p class="navid-auth-status" role="status" aria-live="polite"></p>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $navid_pass ) : ?>
			<section class="navid-auth-panel navid-lg-panel" data-navid-panel="password" <?php echo 'password' === $navid_first ? '' : 'hidden'; ?>>
				<form class="woocommerce-form woocommerce-form-login login" method="post" action="<?php echo esc_url( $navid_account ); ?>">
					<?php do_action( 'woocommerce_login_form_start' ); ?>
					<label for="navid-<?php echo esc_attr( $navid_p ); ?>-user"><?php esc_html_e( 'نام کاربری یا ایمیل', 'navid' ); ?></label>
					<input class="navid-lg-input" type="text" name="username" id="navid-<?php echo esc_attr( $navid_p ); ?>-user" autocomplete="username" dir="ltr" required>
					<label for="navid-<?php echo esc_attr( $navid_p ); ?>-pass"><?php esc_html_e( 'رمز عبور', 'navid' ); ?></label>
					<input class="navid-lg-input" type="password" name="password" id="navid-<?php echo esc_attr( $navid_p ); ?>-pass" autocomplete="current-password" dir="ltr" required>
					<?php do_action( 'woocommerce_login_form' ); ?>
					<label class="navid-auth-remember"><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e( 'مرا به خاطر بسپار', 'navid' ); ?></label>
					<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
					<?php if ( $navid_args['redirect'] ) : ?>
						<input type="hidden" name="redirect" value="<?php echo esc_url( $navid_args['redirect'] ); ?>">
					<?php endif; ?>
					<button type="submit" class="navid-lg-btn" name="login" value="<?php esc_attr_e( 'ورود', 'navid' ); ?>"><span><?php esc_html_e( 'ورود', 'navid' ); ?></span></button>
					<a class="navid-lg-link" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'رمز عبور را فراموش کرده‌اید؟', 'navid' ); ?></a>
					<?php do_action( 'woocommerce_login_form_end' ); ?>
				</form>
			</section>
		<?php endif; ?>

		<?php if ( '' !== trim( (string) $navid_opt['login_note'] ) ) : ?>
			<p class="navid-auth-foot"><?php echo esc_html( $navid_opt['login_note'] ); ?></p>
		<?php endif; ?>
	</div>
</div>
