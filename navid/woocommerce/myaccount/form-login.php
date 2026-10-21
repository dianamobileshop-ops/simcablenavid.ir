<?php
/**
 * Login / registration page: one card with SMS login, password login and sign-up.
 *
 * @package Navid
 * @version 9.9.0
 */

defined( 'ABSPATH' ) || exit;

$navid_sms      = class_exists( 'NPT_OTP' ) && class_exists( 'NPT_SMS' ) && NPT_OTP::enabled() && NPT_SMS::otp_ready();
$navid_register = function_exists( 'npt_register_url' ) ? npt_register_url() : '';
$navid_wc_reg   = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
$navid_first    = $navid_sms ? 'sms' : 'password';
do_action( 'woocommerce_before_customer_login_form' );
?>
<div class="navid-auth">
	<div class="navid-auth-card">
		<a class="navid-auth-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php bloginfo( 'name' ); ?>"><?php echo navid_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
		<h1 class="navid-auth-title"><?php esc_html_e( 'ورود | ثبت‌نام', 'navid' ); ?></h1>

		<div class="navid-auth-tabs" role="tablist">
			<?php if ( $navid_sms ) : ?>
				<button type="button" role="tab" class="<?php echo 'sms' === $navid_first ? 'is-on' : ''; ?>" data-navid-tab="sms"><?php esc_html_e( 'ورود با پیامک', 'navid' ); ?></button>
			<?php endif; ?>
			<button type="button" role="tab" class="<?php echo 'password' === $navid_first ? 'is-on' : ''; ?>" data-navid-tab="password"><?php esc_html_e( 'ورود با رمز عبور', 'navid' ); ?></button>
		</div>

		<?php if ( $navid_sms ) : ?>
			<section class="navid-auth-panel" data-navid-panel="sms" <?php echo 'sms' === $navid_first ? '' : 'hidden'; ?>>
				<form class="navid-otp-phone" novalidate>
					<label for="navid-otp-phone"><?php esc_html_e( 'شمارهٔ موبایل خود را وارد کنید', 'navid' ); ?></label>
					<input id="navid-otp-phone" type="tel" inputmode="tel" dir="ltr" autocomplete="tel" placeholder="09121234567">
					<button type="submit" class="navid-auth-btn"><?php esc_html_e( 'دریافت کد تأیید', 'navid' ); ?></button>
				</form>
				<form class="navid-otp-code" hidden novalidate>
					<label for="navid-otp-code"><?php esc_html_e( 'کد ارسال‌شده را وارد کنید', 'navid' ); ?></label>
					<input id="navid-otp-code" type="text" inputmode="numeric" dir="ltr" autocomplete="one-time-code" maxlength="6" placeholder="••••••">
					<button type="submit" class="navid-auth-btn"><?php esc_html_e( 'ورود', 'navid' ); ?></button>
					<button type="button" class="navid-auth-link navid-otp-resend" disabled><?php esc_html_e( 'ارسال دوباره', 'navid' ); ?> (<span>60</span>)</button>
					<button type="button" class="navid-auth-link navid-otp-change"><?php esc_html_e( 'تغییر شماره', 'navid' ); ?></button>
				</form>
				<p class="navid-auth-status" role="status"></p>
				<?php if ( $navid_register ) : ?>
					<p class="navid-auth-foot"><?php esc_html_e( 'اگر ثبت‌نام نکرده‌اید، با همین شماره وارد شوید یا', 'navid' ); ?> <a href="<?php echo esc_url( $navid_register ); ?>"><?php esc_html_e( 'ثبت‌نام کامل', 'navid' ); ?></a></p>
				<?php else : ?>
					<p class="navid-auth-foot"><?php esc_html_e( 'اگر حساب ندارید، با ورود با پیامک حساب شما ساخته می‌شود.', 'navid' ); ?></p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<section class="navid-auth-panel" data-navid-panel="password" <?php echo 'password' === $navid_first ? '' : 'hidden'; ?>>
			<form class="woocommerce-form woocommerce-form-login login" method="post">
				<?php do_action( 'woocommerce_login_form_start' ); ?>
				<label for="username"><?php esc_html_e( 'نام کاربری یا ایمیل', 'navid' ); ?></label>
				<input type="text" name="username" id="username" autocomplete="username" dir="ltr" value="<?php echo ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; // phpcs:ignore ?>" required>
				<label for="password"><?php esc_html_e( 'رمز عبور', 'navid' ); ?></label>
				<input type="password" name="password" id="password" autocomplete="current-password" dir="ltr" required>
				<?php do_action( 'woocommerce_login_form' ); ?>
				<label class="navid-auth-remember"><input type="checkbox" name="rememberme" id="rememberme" value="forever"> <?php esc_html_e( 'مرا به خاطر بسپار', 'navid' ); ?></label>
				<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
				<button type="submit" class="navid-auth-btn" name="login" value="<?php esc_attr_e( 'ورود', 'navid' ); ?>"><?php esc_html_e( 'ورود', 'navid' ); ?></button>
				<a class="navid-auth-link" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'رمز عبور را فراموش کرده‌اید؟', 'navid' ); ?></a>
				<?php do_action( 'woocommerce_login_form_end' ); ?>
			</form>
			<?php if ( $navid_register || $navid_wc_reg ) : ?>
				<p class="navid-auth-foot">
					<?php esc_html_e( 'حساب ندارید؟', 'navid' ); ?>
					<a href="<?php echo esc_url( $navid_register ? $navid_register : '#navid-register' ); ?>"><?php esc_html_e( 'ثبت‌نام کنید', 'navid' ); ?></a>
				</p>
			<?php endif; ?>
		</section>

		<?php if ( $navid_wc_reg && ! $navid_register ) : ?>
			<section class="navid-auth-panel navid-auth-register" id="navid-register">
				<h2><?php esc_html_e( 'ثبت‌نام', 'navid' ); ?></h2>
				<form method="post" class="woocommerce-form woocommerce-form-register register" <?php do_action( 'woocommerce_register_form_tag' ); ?>>
					<?php do_action( 'woocommerce_register_form_start' ); ?>
					<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>
						<label for="reg_username"><?php esc_html_e( 'نام کاربری', 'navid' ); ?></label>
						<input type="text" name="username" id="reg_username" dir="ltr" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; // phpcs:ignore ?>">
					<?php endif; ?>
					<label for="reg_email"><?php esc_html_e( 'ایمیل', 'navid' ); ?></label>
					<input type="email" name="email" id="reg_email" dir="ltr" value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore ?>" required>
					<?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>
						<label for="reg_password"><?php esc_html_e( 'رمز عبور', 'navid' ); ?></label>
						<input type="password" name="password" id="reg_password" dir="ltr">
					<?php endif; ?>
					<?php do_action( 'woocommerce_register_form' ); ?>
					<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
					<button type="submit" class="navid-auth-btn" name="register" value="<?php esc_attr_e( 'ثبت‌نام', 'navid' ); ?>"><?php esc_html_e( 'ثبت‌نام', 'navid' ); ?></button>
					<?php do_action( 'woocommerce_register_form_end' ); ?>
				</form>
			</section>
		<?php endif; ?>
	</div>
</div>
<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
