<?php
/**
 * OTP module — merged from the standalone «Mobile Quick Bar» plugin.
 *
 * The mobile bar's login modal, the employer panel and the customer
 * registration form all authenticate through this one class, and a verified
 * code always produces a real WordPress session (wp_set_auth_cookie), so every
 * existing capability gate in the plugin keeps working unchanged. There is no
 * parallel token system to bypass them.
 *
 * Kept identical to the live Mobile Quick Bar front-end contract on purpose
 * (LiteSpeed Cache can serve a guest page whose HTML/JS were rendered before
 * this merge): the AJAX actions stay mqb_send_otp / mqb_verify_otp and the
 * nonce action stays mqb_otp_nonce.
 *
 * Differences from the standalone plugin, all deliberate:
 *   - credentials and the pattern code come from the npt_sms_* options, so the
 *     bulk sender and OTP share one Melipayamak account;
 *   - provider success is judged by RetStatus/StrRetStatus through
 *     NPT_SMS::api_call() instead of the old "Value is a positive number" test;
 *   - an expired nonce answers with JSON and a Persian «reload the page»
 *     message instead of dying with -1 (the old behaviour broke the modal on
 *     every cached page);
 *   - the scene=register flow verifies possession of the number without
 *     creating anything, so a half-finished registration cannot leave orphan
 *     users behind;
 *   - an auto-created login account is always a plain subscriber, hard-coded —
 *     panel access is granted by the site owner by hand, never by SMS;
 *   - when the panel is not configured the code is never even generated, so a
 *     broken account cannot burn requests or credit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPT_OTP {

	const NONCE      = 'mqb_otp_nonce';
	const EXPIRY     = 120;
	const LOCKOUT    = 600;
	const COOLDOWN   = 60;
	const IP_LIMIT   = 10;   // sends per IP per hour
	const DAY_LIMIT  = 300;  // sends for the whole site per day (SMS credit guard)
	const NUMBER_HOUR_LIMIT = 3; // codes per mobile number per hour

	public static function init() {
		add_action( 'wp_ajax_npt_captcha', array( __CLASS__, 'ajax_captcha' ) );
		add_action( 'wp_ajax_nopriv_npt_captcha', array( __CLASS__, 'ajax_captcha' ) );
		foreach ( array( 'mqb_send_otp', 'mqb_verify_otp', 'npt_send_otp', 'npt_verify_otp' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, 'ajax_' . $action ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	// Legacy names stay registered so cached pages keep working.
	public static function ajax_mqb_send_otp() {
		self::ajax_send();
	}

	public static function ajax_npt_send_otp() {
		self::ajax_send();
	}

	public static function ajax_mqb_verify_otp() {
		self::ajax_verify();
	}

	public static function ajax_npt_verify_otp() {
		self::ajax_verify();
	}

	/** Availability switch lives in the bar settings (the live site has it on). */
	public static function enabled() {
		$settings = NPT_Bar::settings();
		return ! empty( $settings['sms_enabled'] );
	}

	/* ---------------------------------------------------------------------
	 * AJAX endpoints
	 * ------------------------------------------------------------------- */
	public static function ajax_send() {
		self::guard_nonce();
		if ( ! self::enabled() ) {
			self::error( 'ورود پیامکی غیرفعال است.', 403 );
		}

		$phone = self::normalize( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		if ( ! $phone ) {
			self::error( 'شماره موبایل معتبر نیست.', 400 );
		}
		$scene = self::scene();

		// Guests must solve the small challenge first (bots cannot send codes).
		if ( ! is_user_logged_in() && 'login' !== $scene ) {
			self::captcha_check();
		}

		if ( ! self::limits_ok( $phone ) ) {
			self::error( 'برای ارسال مجدد کمی صبر کنید.', 429 );
		}
		if ( ! NPT_SMS::otp_ready() ) {
			self::error( 'سرویس پیامک هنوز در پیشخوان کامل پیکربندی نشده است.', 503 );
		}

		$settings = NPT_Bar::settings();
		$digits   = min( 6, max( 4, absint( $settings['otp_digits'] ) ) );
		$code     = (string) random_int( (int) pow( 10, $digits - 1 ), (int) pow( 10, $digits ) - 1 );

		// Claim the cooldown first: two simultaneous requests for one number
		// must not both reach the SMS gateway.
		set_transient( 'npt_cool_' . self::key( $phone ), 1, self::COOLDOWN );
		$api_code = NPT_SMS::api_otp_code( $phone );
		if ( is_wp_error( $api_code ) ) {
			$result = $api_code;
		} elseif ( '' !== $api_code ) {
			$code   = $api_code;
			$result = true;
		} else {
			$result = NPT_SMS::send_otp( $phone, $code );
		}

		if ( is_wp_error( $result ) ) {
			delete_transient( 'npt_cool_' . self::key( $phone ) );
			self::error( $result->get_error_message(), 502 );
		}

		set_transient( 'npt_otp_' . self::key( $phone ), array(
			'hash'     => wp_hash_password( $code ),
			'attempts' => 0,
			'scene'    => $scene,
		), self::EXPIRY );
		self::success( 'کد تأیید ارسال شد.' );
	}

	public static function ajax_verify() {
		self::guard_nonce();
		if ( ! self::enabled() ) {
			self::error( 'ورود پیامکی غیرفعال است.', 403 );
		}

		$phone = self::normalize( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		$code  = preg_replace( '/\D+/', '', isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '' );
		$scene = self::scene();

		$user = self::verify_code( $phone, $code, $scene );
		if ( is_wp_error( $user ) ) {
			self::error( $user->get_error_message(), self::http_status( $user ) );
		}

		if ( ! $user instanceof WP_User ) {
			// scene=register codes are consumed by NPT_Register, which also creates
			// the account; they must not open a session here.
			self::error( 'این کد برای ثبت‌نام صادر شده است.', 400 );
		}

		self::login( $user );
		do_action( 'npt_otp_verified', $user, $phone );
		self::success( 'با موفقیت وارد شدید.', array( 'loggedIn' => true ) );
	}

	/* ---------------------------------------------------------------------
	 * Core
	 * ------------------------------------------------------------------- */

	/**
	 * Check a submitted code. Returns WP_User on success (login scene) or the
	 * phone number string (register scene); WP_Error with a Persian message on
	 * any failure.
	 */
	public static function verify_code( $phone, $code, $scene = 'login' ) {
		$phone = self::normalize( $phone );
		$code  = preg_replace( '/\D+/', '', (string) $code );
		if ( ! $phone || ! preg_match( '/^\d{4,6}$/', $code ) ) {
			return new WP_Error( 'npt_invalid', 'اطلاعات واردشده معتبر نیست.' );
		}

		$key = self::key( $phone );
		if ( get_transient( 'npt_lock_' . $key ) ) {
			return new WP_Error( 'npt_locked', 'ورود موقتاً قفل شده است.', array( 'status' => 429 ) );
		}
		$record = get_transient( 'npt_otp_' . $key );
		if ( ! is_array( $record ) || empty( $record['hash'] ) ) {
			return new WP_Error( 'npt_expired', 'کد منقضی شده است. کد جدید بگیرید.' );
		}
		if ( ! hash_equals( (string) ( isset( $record['scene'] ) ? $record['scene'] : 'login' ), $scene ) ) {
			return new WP_Error( 'npt_scene', 'این کد برای همین کار صادر نشده است. کد جدید بگیرید.' );
		}

		// One verification at a time per number, so parallel guesses cannot all be
		// counted against the same stale attempts value.
		$guard = 'npt_vg_' . $key;
		if ( get_transient( $guard ) ) {
			return new WP_Error( 'npt_busy', 'لطفاً چند ثانیه صبر کنید و دوباره تلاش کنید.', array( 'status' => 429 ) );
		}
		set_transient( $guard, 1, 3 );

		if ( ! wp_check_password( $code, $record['hash'] ) ) {
			$record['attempts'] = absint( isset( $record['attempts'] ) ? $record['attempts'] : 0 ) + 1;
			if ( $record['attempts'] >= 3 ) {
				delete_transient( 'npt_otp_' . $key );
				set_transient( 'npt_lock_' . $key, 1, self::LOCKOUT );
				delete_transient( $guard );
				return new WP_Error( 'npt_attempts', 'سه بار کد اشتباه وارد شد. ورود ۱۰ دقیقه قفل شد.', array( 'status' => 429 ) );
			}
			set_transient( 'npt_otp_' . $key, $record, self::EXPIRY );
			delete_transient( $guard );
			return new WP_Error( 'npt_wrong', 'کد تأیید اشتباه است.' );
		}

		delete_transient( 'npt_otp_' . $key );
		delete_transient( 'npt_cool_' . $key );
		delete_transient( $guard );

		if ( 'register' === $scene || 'bind' === $scene || 'proforma' === $scene ) {
			// None of them creates a session (proforma: a guest must never get an account here): the caller (registration / the panel's
			// «bind my number» card) consumes the code and does its own write.
			return $phone;
		}

		$user = self::get_or_create_user( $phone );
		if ( is_wp_error( $user ) ) {
			if ( in_array( $user->get_error_code(), array( 'npt_privileged' ), true ) ) {
				return new WP_Error( 'npt_privileged', $user->get_error_message(), array( 'status' => 403 ) );
			}
			return new WP_Error( 'npt_user', 'ورود انجام نشد. با مدیر سایت تماس بگیرید.', array( 'status' => 500 ) );
		}
		return $user;
	}

	/** Open the real WordPress session. */
	public static function login( WP_User $user ) {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */
	private static function scene() {
		$scene = isset( $_POST['scene'] ) ? sanitize_key( wp_unslash( $_POST['scene'] ) ) : 'login';
		// bind = «attach this number to the account I am signed in with», used by
		// the panel so a manually created employer account can prove it owns the
		// number before OTP login starts working for it.
		return in_array( $scene, array( 'login', 'register', 'bind', 'proforma' ), true ) ? $scene : 'login';
	}

	/** Small arithmetic challenge for guests; one use, 10 minutes. */
	public static function ajax_captcha() {
		if ( ! self::nonce_ok() ) {
			self::error( 'نشست شما منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.', 403 );
		}
		$a  = random_int( 1, 9 );
		$b  = random_int( 1, 9 );
		$id = wp_generate_password( 16, false, false );
		set_transient( 'npt_cap_' . $id, (string) ( $a + $b ), 10 * MINUTE_IN_SECONDS );
		wp_send_json_success( array(
			'id' => $id,
			'q'  => npt_to_persian_digits( $a . ' + ' . $b . ' = ?' ),
		) );
	}

	/** Shared nonce check for every guest-facing OTP request. */
	public static function nonce_ok() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : ''; // phpcs:ignore
		return (bool) wp_verify_nonce( $nonce, self::NONCE );
	}

	/** The challenge box, used by the login, registration and public proforma forms. */
	public static function captcha_html() {
		return '<div class="nptp-cap"><label>کد امنیتی: <strong class="nptp-cap-q">…</strong> = '
			. '<input class="nptp-cap-a" type="text" inputmode="numeric" autocomplete="off" required style="width:90px"></label> '
			. '<input type="hidden" class="nptp-cap-id"> '
			. '<button type="button" class="nptp-link nptp-cap-new">عوض کن</button></div>';
	}

	private static function captcha_check() {
		$id  = isset( $_POST['captcha_id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_POST['captcha_id'] ) ) : ''; // phpcs:ignore
		$ans = isset( $_POST['captcha_answer'] ) ? wp_unslash( $_POST['captcha_answer'] ) : ''; // phpcs:ignore
		$ans = trim( strtr( (string) $ans, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) ) );
		$want = '' !== $id ? get_transient( 'npt_cap_' . $id ) : false;
		if ( '' !== $id ) {
			delete_transient( 'npt_cap_' . $id ); // one use only
		}
		if ( false === $want || (string) $want !== $ans ) {
			self::error( 'پاسخ کد امنیتی درست نیست. دوباره امتحان کنید.', 400 );
		}
	}

	/** A number that proved it owns its phone (used by the public proforma SMS). */
	public static function mark_verified( $phone ) {
		$phone = self::normalize( $phone );
		if ( $phone ) {
			set_transient( 'npt_pfv_' . self::key( $phone ), 1, 30 * MINUTE_IN_SECONDS );
		}
	}

	public static function is_verified( $phone ) {
		$phone = self::normalize( $phone );
		return $phone ? (bool) get_transient( 'npt_pfv_' . self::key( $phone ) ) : false;
	}

	private static function guard_nonce() {
		// false = don't die: a stale guest nonce (page cache) must answer JSON so
		// the modal can tell the visitor to reload instead of showing "-1".
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			self::error( 'نشست شما منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.', 403 );
		}
	}

	/**
	 * Per-number lockout + resend cooldown, then the per-IP ceiling. The
	 * address comes from npt_client_ip() (REMOTE_ADDR unless a trusted proxy
	 * header is configured in wp-config.php).
	 */
	private static function limits_ok( $phone ) {
		$key = self::key( $phone );
		if ( get_transient( 'npt_lock_' . $key ) ) {
			self::error( 'تلاش‌های ناموفق زیاد بود. کمی بعد دوباره امتحان کنید.', 429 );
		}
		if ( get_transient( 'npt_cool_' . $key ) ) {
			return false;
		}
		$hour_key = 'npt_hr_' . $key;
		$sent     = absint( get_transient( $hour_key ) );
		if ( $sent >= self::NUMBER_HOUR_LIMIT ) {
			self::error( 'برای این شماره در یک ساعت بیش از ' . npt_to_persian_digits( (string) self::NUMBER_HOUR_LIMIT ) . ' کد ارسال نمی‌شود.', 429 );
		}
		set_transient( $hour_key, $sent + 1, HOUR_IN_SECONDS );
		$ip_key = 'npt_ip_' . self::key( npt_client_ip() );
		$hits   = absint( get_transient( $ip_key ) );
		if ( $hits >= self::IP_LIMIT ) {
			self::error( 'درخواست‌های زیادی ارسال شده است. بعداً تلاش کنید.', 429 );
		}

		// Site-wide daily ceiling: many addresses together cannot drain the
		// SMS credit. Filterable for busy sites.
		$day_key = 'npt_day_' . gmdate( 'Ymd' );
		$day     = absint( get_transient( $day_key ) );
		if ( $day >= (int) apply_filters( 'npt_otp_daily_limit', self::DAY_LIMIT ) ) {
			self::error( 'سقف ارسال کد تأیید امروز پر شده است. بعداً یا فردا تلاش کنید.', 429 );
		}

		set_transient( $ip_key, $hits + 1, HOUR_IN_SECONDS );
		set_transient( $day_key, $day + 1, DAY_IN_SECONDS );
		return true;
	}

	private static function http_status( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
	}

	private static function error( $message, $status = 400 ) {
		wp_send_json_error( array( 'message' => $message ), $status );
	}

	private static function success( $message, $extra = array() ) {
		wp_send_json_success( array_merge( array( 'message' => $message ), $extra ) );
	}

	private static function normalize( $raw ) {
		return NPT_Proforma::normalize_phone( $raw );
	}

	private static function key( $value ) {
		return substr( hash_hmac( 'sha256', (string) $value, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Reuse the account that already owns this number (our own npt_mobile meta
	 * first, then WooCommerce / Cando billing metas) and otherwise create a
	 * plain subscriber. The role is hard-coded at insert time and is never
	 * written to an existing account, so SMS alone can't grant panel access.
	 */
	private static function get_or_create_user( $phone ) {
		foreach ( array( 'npt_mobile', 'billing_phone', 'billing_mobile' ) as $meta_key ) {
			$ids = get_users( array(
				'meta_key'    => $meta_key,
				'meta_value'  => $phone,
				'number'      => 2,
				'count_total' => false,
				'fields'      => 'ID',
			) );
			if ( ! $ids ) {
				continue;
			}
			// billing_* fields are customer-editable and unverified: if more than
			// one account claims the number, never guess which one to open.
			if ( 'npt_mobile' !== $meta_key && count( $ids ) > 1 ) {
				return new WP_Error( 'npt_ambiguous', 'این شماره به چند حساب متصل است؛ با مدیر سایت تماس بگیرید.' );
			}
			$user = get_user_by( 'id', (int) $ids[0] );
			if ( ! $user ) {
				continue;
			}
			// An SMS code must never open an administrator (it would bypass the
			// password and any 2FA); filterable for sites that accept the risk.
			if ( apply_filters( 'npt_otp_block_user', user_can( $user, 'manage_options' ) || user_can( $user, 'promote_users' ), $user ) ) {
				return new WP_Error( 'npt_privileged', 'این حساب با پیامک قابل ورود نیست. با رمز عبور وارد شوید.' );
			}
			if ( 'npt_mobile' !== $meta_key ) {
				add_user_meta( $user->ID, 'npt_mobile', $phone, true );
			}
			return $user;
		}

		$login = 'npt_' . substr( $phone, 1 );
		if ( username_exists( $login ) ) {
			// Someone already owns this derived username; never take it over.
			return new WP_Error( 'npt_taken', 'این شماره به حساب دیگری وصل نشده و نام کاربری آن گرفته شده است؛ با مدیر سایت تماس بگیرید.' );
		}

		$id = wp_insert_user( array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => $phone,
			'role'         => 'subscriber',
		) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_user_meta( (int) $id, 'npt_mobile', $phone );
		update_user_meta( (int) $id, 'billing_phone', $phone );
		update_user_meta( (int) $id, 'user_mobile_number', $phone );
		return get_user_by( 'id', (int) $id );
	}
}
