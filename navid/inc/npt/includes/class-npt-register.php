<?php
/**
 * Customer registration ([navid_register]).
 *
 * A mobile-verified sign-up for shoppers: the number is proven with an OTP
 * (scene=register, so a login code can never be replayed here and vice versa),
 * then a plain subscriber account is created and the visitor is logged in.
 *
 * Guards that matter on a public form:
 *   - the role is hard-coded to «subscriber». Panel access (npt_manage_panel)
 *     is a capability the site owner grants by hand, and nothing in this file
 *     can ever add it;
 *   - a number that already has an account is rejected with «sign in instead»,
 *     so the form cannot be used to pile up duplicate users;
 *   - the honeypot field npt_website (same contract as the public proforma)
 *     plus the OTP itself stop bots: registering costs one SMS per real human;
 *   - WordPress' own users_can_register option stays untouched — this is a
 *     deliberate, code-reviewed path, not the open 99-registration switch.
 *
 * The form reuses the panel stylesheet and script (NPT_Panel::render_assets()),
 * which already knows how to drive #nptp-reg-form.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPT_Register {

	const PAGE_OPT = 'npt_register_page';

	public static function init() {
		add_shortcode( 'navid_register', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_npt_register_send', array( __CLASS__, 'ajax_send' ) );
		add_action( 'wp_ajax_nopriv_npt_register_send', array( __CLASS__, 'ajax_send' ) );
		add_action( 'wp_ajax_npt_register_done', array( __CLASS__, 'ajax_done' ) );
		add_action( 'wp_ajax_nopriv_npt_register_done', array( __CLASS__, 'ajax_done' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_page' ) );
	}

	/** Create (once) or adopt the page that holds the form. */
	public static function ensure_page() {
		$id = (int) get_option( self::PAGE_OPT, 0 );
		if ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			return $id;
		}
		$found = get_posts( array(
			'post_type'   => 'page',
			'post_status' => 'publish,draft',
			'search'      => '[navid_register]',
			'number'      => 1,
			'fields'      => 'IDs',
		) );
		if ( $found ) {
			// Some sites return the post object here instead of its ID.
			$found_id = (int) ( is_object( $found[0] ) ? $found[0]->ID : $found[0] );
			update_option( self::PAGE_OPT, $found_id, false );
			return $found_id;
		}
		$new_id = wp_insert_post( array(
			'post_title'   => 'ثبت‌نام',
			'post_name'    => 'register',
			'post_content' => '[navid_register]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'ping_status'  => 'closed',
			'to_ping'      => '',
		) );
		if ( is_wp_error( $new_id ) ) {
			return 0;
		}
		update_option( self::PAGE_OPT, (int) $new_id, false );
		flush_rewrite_rules( false );
		return (int) $new_id;
	}

	public static function page_id() {
		$id = (int) get_option( self::PAGE_OPT, 0 );
		return $id ? $id : self::ensure_page();
	}

	public static function url() {
		$id  = self::page_id();
		$url = $id ? get_permalink( $id ) : '';
		return $url ? $url : '';
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */
	public static function ajax_send() {
		self::honeypot_check();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( mb_strlen( $name ) < 3 ) {
			wp_send_json_error( array( 'message' => 'نام و نام خانوادگی را کامل وارد کنید (حداقل ۳ حرف).' ), 400 );
		}
		$phone = NPT_Proforma::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		if ( self::existing_user( $phone ) ) {
			wp_send_json_error( array( 'message' => 'این شماره قبلاً ثبت شده است. با همان شماره وارد شوید.' ), 409 );
		}
		// Reuse the OTP sender with the register scene so a registration code can
		// never be replayed as a login code.
		$_POST['scene'] = 'register';
		NPT_OTP::ajax_send();
	}

	public static function ajax_done() {
		self::honeypot_check();
		if ( ! is_user_logged_in() ) {
			// Someone signed in in another tab while this form was open.
			$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$phone = NPT_Proforma::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
			$code  = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '';

			$result = NPT_OTP::verify_code( $phone, $code, 'register' );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			$user = self::create_user( $phone, $name );
			if ( is_wp_error( $user ) ) {
				wp_send_json_error( array( 'message' => $user->get_error_message() ), 400 );
			}
			NPT_OTP::login( $user );
		}

		$redirect = function_exists( 'npt_panel_url' ) ? npt_panel_url() : home_url( '/' );
		wp_send_json_success( array(
			'message'  => 'ثبت‌نام انجام شد.',
			'redirect' => $redirect,
		) );
	}

	private static function honeypot_check() {
		if ( ! check_ajax_referer( NPT_OTP::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'نشست شما منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.' ), 403 );
		}
		if ( ! NPT_OTP::enabled() ) {
			wp_send_json_error( array( 'message' => 'ثبت‌نام با پیامک فعلاً غیرفعال است.' ), 403 );
		}
		if ( ! empty( $_POST['npt_website'] ) ) {
			// Bots fill every field; humans never see this one. Pretend success so
			// the bot does not learn to stop filling it.
			wp_send_json_success( array( 'message' => 'در حال بررسی…' ) );
		}
	}

	private static function existing_user( $phone ) {
		if ( ! $phone ) {
			return false;
		}
		foreach ( array( 'npt_mobile', 'billing_phone', 'billing_mobile' ) as $meta_key ) {
			$ids = get_users( array(
				'meta_key'    => $meta_key,
				'meta_value'  => $phone,
				'number'      => 1,
				'count_total' => false,
				'fields'      => 'ID',
			) );
			if ( $ids ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Subscriber, no email, no password anyone knows (a random one is set; the
	 * number + OTP is the way back in). Never touches capabilities beyond that.
	 *
	 * @return WP_User|WP_Error
	 */
	private static function create_user( $phone, $name ) {
		if ( ! $phone ) {
			return new WP_Error( 'npt_phone', 'شماره موبایل معتبر نیست.' );
		}
		if ( self::existing_user( $phone ) ) {
			return new WP_Error( 'npt_exists', 'این شماره قبلاً ثبت شده است. با همان شماره وارد شوید.' );
		}

		$login = 'npt_' . substr( $phone, 1 );
		if ( username_exists( $login ) ) {
			return new WP_Error( 'npt_taken', 'این شماره قابل ثبت‌نام نیست. با مدیر سایت تماس بگیرید.' );
		}

		$display = trim( $name );
		if ( $display === '' ) {
			$display = $phone;
		}

		$id = wp_insert_user( array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => $display,
			'first_name'   => $display === $phone ? '' : $display,
			'role'         => 'subscriber',
		) );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'npt_insert', 'ثبت‌نام انجام نشد. کمی بعد دوباره تلاش کنید.' );
		}

		update_user_meta( (int) $id, 'npt_mobile', $phone );
		update_user_meta( (int) $id, 'billing_phone', $phone );
		update_user_meta( (int) $id, 'billing_mobile', $phone );
		update_user_meta( (int) $id, 'user_mobile_number', $phone );

		do_action( 'npt_customer_registered', (int) $id, $phone, $display );

		$user = get_user_by( 'id', (int) $id );
		return $user ? $user : new WP_Error( 'npt_reload', 'ثبت‌نام انجام شد؛ حالا وارد شوید.' );
	}

	/* ---------------------------------------------------------------------
	 * Shortcode
	 * ------------------------------------------------------------------- */
	public static function shortcode( $atts = array() ) {
		if ( is_user_logged_in() ) {
			return '<div class="nptp" dir="rtl"><p class="nptp-note is-ok">شما وارد شده‌اید. '
				. '<a href="' . esc_url( function_exists( 'npt_panel_url' ) ? npt_panel_url() : home_url( '/' ) ) . '">رفتن به پنل</a></p></div>';
		}

		nocache_headers();
		$otp_ready = NPT_SMS::otp_ready();
		ob_start();
		?>
		<div class="nptp nptp-auth-wrap" dir="rtl">
			<div class="nptp-auth">
				<div class="nptp-brand">
					<span class="nptp-brand-mark" aria-hidden="true">＋</span>
					<div>
						<strong>ثبت‌نام در سایت</strong>
						<em>سیم و کابل نوید</em>
					</div>
				</div>

				<?php if ( ! $otp_ready ) : ?>
					<p class="nptp-note">فعلاً امکان ثبت‌نام وجود ندارد. برای دریافت حساب با فروشگاه تماس بگیرید.</p>
				<?php else : ?>
				<form class="nptp-form" id="nptp-reg-form">
					<label>نام و نام خانوادگی
						<input type="text" name="name" autocomplete="name" required>
					</label>
					<label>شماره موبایل
						<input type="tel" name="phone" inputmode="numeric" autocomplete="tel" maxlength="11" placeholder="09123456789" required>
					</label>
					<p class="nptp-foot muted">با ثبت‌نام، شماره موبایل شما به‌عنوان شناسه ورود ذخیره می‌شود و فقط برای اطلاع‌رسانی سفارش‌ها استفاده می‌گردد.</p>
					<?php echo NPT_OTP::captcha_html(); // phpcs:ignore ?>
					<div class="nptp-reg-code" hidden>
						<label>کد تأیید
							<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
						</label>
						<button type="button" class="nptp-link nptp-resend-btn" disabled>ارسال مجدد (<span>60</span>)</button>
					</div>
					<label for="nptp-reg-website" class="nptpf-hp" aria-hidden="true">وب‌سایت</label>
					<input type="text" id="nptp-reg-website" class="nptpf-hp" name="npt_website" tabindex="-1" autocomplete="off" aria-hidden="true" value="">
					<button type="submit" class="nptp-btn">ارسال کد تأیید</button>
				</form>
				<p class="nptp-status" role="status" aria-live="polite"></p>
				<p class="nptp-foot">حساب دارید؟ <a href="<?php echo esc_url( function_exists( 'npt_panel_url' ) ? npt_panel_url() : home_url( '/' ) ); ?>">ورود</a></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		$html = (string) ob_get_clean();

		// Shared stylesheet + the script that drives #nptp-reg-form. The off-screen
		// honeypot class lives in the proforma block, so repeat the two rules here.
		ob_start();
		echo '<style>.nptp .nptpf-hp{position:absolute!important;left:0!important;top:0!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;clip-path:inset(50%)!important;opacity:0}</style>';
		NPT_Panel::render_assets();
		return $html . (string) ob_get_clean();
	}
}
