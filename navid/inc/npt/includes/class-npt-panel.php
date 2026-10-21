<?php
/**
 * Business panel (پنل مدیر کسب و کار).
 *
 * A stand-alone, mobile-first dashboard for the employer, deliberately living
 * OUTSIDE wp-admin: the site owner asked for a modern panel with access to this
 * section only, not a WooCommerce «My Account» tab and not a shop-manager
 * back office.
 *
 * How the access model works, and why:
 *   - a dedicated role (npt_business_manager) with one custom capability
 *     (npt_manage_panel) is created; administrator / shop_manager get the same
 *     capability so existing accounts can open the panel immediately;
 *   - authentication produces a real WordPress session (password form or OTP),
 *     so every pre-existing npt_current_user_can_edit() gate keeps protecting
 *     the price edit, proforma and SMS endpoints — nothing new can bypass them;
 *   - the panel page is a normal WP page holding the [navid_panel] shortcode,
 *     which keeps the theme chrome (Cando), permalinks and page cache behaving
 *     and needs no custom rewrite rule. The page is created on upgrade and the
 *     URL is therefore secret-free but guessable — hence the wp-admin lockdown
 *     and the login rate limit.
 *   - accounts with the panel capability but no back-office capability are
 *     redirected away from wp-admin and get no admin bar, so the panel really
 *     is the only place they can work.
 *
 * The panel reuses the existing renderers (proforma archive, price table,
 * bulk-SMSTab) instead of duplicating them, so a change in one place shows up
 * in both places.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPT_Panel {

	const ROLE         = 'npt_business_manager';
	const ROLE_LABEL   = 'مدیر کسب و کار';
	const CAP          = 'npt_manage_panel';
	const NONCE        = 'npt_panel';
	/** Bulk price change from the panel is limited to ±this many percent. */
	const PCT_LIMIT    = 30;
	const PAGE_OPT     = 'npt_panel_page';
	const INSTALL_OPT  = 'npt_panel_ver';
	const LOGIN_LIMIT  = 8;

	public static function init() {
		add_shortcode( 'navid_panel', array( __CLASS__, 'shortcode' ) );
		// The panel page gets its own full-screen template: no theme header/footer.
		add_filter( 'template_include', array( __CLASS__, 'panel_template' ), 20 );
		add_action( 'wp_ajax_npt_panel_login', array( __CLASS__, 'ajax_login' ) );
		add_action( 'wp_ajax_npt_panel_login_2fa', array( __CLASS__, 'ajax_login_2fa' ) );
		add_action( 'wp_ajax_nopriv_npt_panel_login_2fa', array( __CLASS__, 'ajax_login_2fa' ) );
		add_action( 'wp_ajax_npt_2fa_setup', array( __CLASS__, 'ajax_2fa_setup' ) );
		add_action( 'wp_ajax_npt_2fa_confirm', array( __CLASS__, 'ajax_2fa_confirm' ) );
		add_action( 'wp_ajax_npt_2fa_disable', array( __CLASS__, 'ajax_2fa_disable' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'admin_2fa_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'admin_2fa_save' ) );
		// Guests (not signed in yet) must reach the password login too.
		add_action( 'wp_ajax_nopriv_npt_panel_login', array( __CLASS__, 'ajax_login' ) );
		// Bound to wp_ajax_* only: binding a number needs a signed-in panel account.
		add_action( 'wp_ajax_npt_bind_send', array( __CLASS__, 'ajax_bind_send' ) );
		add_action( 'wp_ajax_npt_bind_done', array( __CLASS__, 'ajax_bind_done' ) );
		add_action( 'wp_ajax_npt_pct_preview', array( __CLASS__, 'ajax_pct_preview' ) );
		add_action( 'wp_ajax_npt_pct_apply', array( __CLASS__, 'ajax_pct_apply' ) );
		add_action( 'wp_ajax_npt_pct_groups', array( __CLASS__, 'ajax_pct_groups' ) );
		add_action( 'wp_ajax_npt_pct_notify', array( __CLASS__, 'ajax_pct_notify' ) );
		add_action( 'wp_ajax_npt_pct_undo', array( __CLASS__, 'ajax_pct_undo' ) );
		add_action( 'wp_ajax_npt_pct_item', array( __CLASS__, 'ajax_pct_item' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 2 );
		add_action( 'admin_init', array( __CLASS__, 'lock_admin' ) );
		add_action( 'admin_post_npt_manager_add', array( __CLASS__, 'handle_manager_add' ) );
		add_action( 'admin_post_npt_manager_remove', array( __CLASS__, 'handle_manager_remove' ) );
		add_action( 'admin_post_npt_manager_update', array( __CLASS__, 'handle_manager_update' ) );
		add_action( 'wp_ajax_npt_pf_unseen', array( __CLASS__, 'ajax_unseen' ) );
		add_action( 'wp_ajax_npt_pf_status', array( __CLASS__, 'ajax_pf_status' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ) );
		add_filter( 'wp_headers', array( __CLASS__, 'filter_nocache_header' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Install: role, capability, panel page
	 * ------------------------------------------------------------------- */
	public static function maybe_install() {
		if ( (string) get_option( self::INSTALL_OPT, '' ) === NPT_VERSION ) {
			return;
		}
		self::install();
		update_option( self::INSTALL_OPT, NPT_VERSION, false );
	}

	public static function install() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, self::ROLE_LABEL, array(
				'read'            => true,
				self::CAP         => true,
			) );
		}
		foreach ( array( 'administrator', 'shop_manager' ) as $slug ) {
			$role = get_role( $slug );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
		// Older versions also gave this to editors, which handed blog editors the
		// price list, customer data and the bulk SMS sender.
		$editor = get_role( 'editor' );
		if ( $editor && $editor->has_cap( self::CAP ) ) {
			$editor->remove_cap( self::CAP );
		}
		self::ensure_page();
	}

	/** Create the panel page once, or adopt an existing page that has the shortcode. */
	public static function ensure_page() {
		$id = (int) get_option( self::PAGE_OPT, 0 );
		if ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			return $id;
		}

		$found = npt_find_shortcode_page( 'navid_panel' );
		if ( $found ) {
			update_option( self::PAGE_OPT, $found, false );
			return $found;
		}

		$new_id = wp_insert_post( array(
			'post_title'   => 'پنل مدیر کسب و کار',
			'post_name'    => 'panel',
			'post_content' => '[navid_panel]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'ping_status'  => 'closed',
			'to_ping'      => '',
		) );
		if ( is_wp_error( $new_id ) ) {
			return 0;
		}
		update_option( self::PAGE_OPT, (int) $new_id, false );
		// The slug may have been taken, in which case permalinks must be rebuilt
		// before /panel/ resolves.
		flush_rewrite_rules( false );
		return (int) $new_id;
	}

	public static function panel_template( $template ) {
		if ( ! is_singular( 'page' ) ) {
			return $template;
		}
		$post = get_queried_object();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, 'navid_panel' ) ) {
			return $template;
		}
		return NPT_PATH . 'templates/panel-page.php';
	}

	public static function panel_page_id() {
		$id = (int) get_option( self::PAGE_OPT, 0 );
		return $id ? $id : self::ensure_page();
	}

	public static function panel_url() {
		// The page being viewed is the panel: link to it directly. The stored page
		// id can point elsewhere (or nowhere), and then every tab, form and redirect
		// used to fall back to the site home page.
		global $post;
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'navid_panel' ) ) {
			$here = get_permalink( $post );
			if ( $here ) {
				return $here;
			}
		}
		$id  = self::panel_page_id();
		$url = $id ? get_permalink( $id ) : '';
		return $url ? $url : home_url( '/' );
	}

	/* ---------------------------------------------------------------------
	 * Capability helpers
	 * ------------------------------------------------------------------- */
	public static function user_can_panel() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( self::CAP ) || current_user_can( 'manage_woocommerce' );
	}

	/** Panel-only accounts: allowed in the dashboard, not in wp-admin. */
	public static function is_panel_only( $user = null ) {
		if ( ! $user instanceof WP_User ) {
			$user = wp_get_current_user();
		}
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return $user->has_cap( self::CAP )
			&& ! $user->has_cap( 'manage_options' )
			&& ! $user->has_cap( 'manage_woocommerce' )
			&& ! $user->has_cap( 'edit_posts' );
	}

	public static function filter_admin_bar( $show ) {
		// The panel page is a full-screen app with its own top and bottom bars; the
		// WordPress toolbar would sit on top of them.
		if ( did_action( 'wp' ) && ! is_admin() && self::on_panel_page() ) {
			return false;
		}
		return self::is_panel_only() ? false : $show;
	}

	/** The number already attached to an account, in the shape OTP login reads. */
	public static function user_mobile( WP_User $user ) {
		if ( ! $user->exists() ) {
			return '';
		}
		foreach ( array( 'npt_mobile', 'billing_phone', 'billing_mobile' ) as $meta_key ) {
			$phone = NPT_Proforma::normalize_phone( (string) get_user_meta( $user->ID, $meta_key, true ) );
			if ( $phone ) {
				return $phone;
			}
		}
		return '';
	}

	/** The account that owns a number, or false. Same meta order as NPT_OTP. */
	private static function user_by_mobile( $phone ) {
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
				$user = get_user_by( 'id', (int) $ids[0] );
				if ( $user ) {
					return $user;
				}
			}
		}
		return false;
	}

	/**
	 * An employer account is normally created by hand (Users → Add new, role
	 * «مدیر کسب و کار») and therefore has no mobile number, which means the SMS
	 * login cannot resolve it. Rather than telling the owner "call me", the panel
	 * asks the signed-in account to prove ownership of a number with an OTP
	 * (scene=bind, so neither a login code nor a registration code can be
	 * replayed here) and stores it on that same account.
	 */
	private static function needs_bind() {
		if ( '' !== self::user_mobile( wp_get_current_user() ) ) {
			return false;
		}
		// Accounts that can edit users already have their number in the profile.
		if ( current_user_can( 'edit_users' ) ) {
			return false;
		}
		return NPT_OTP::enabled() && NPT_SMS::otp_ready();
	}

	public static function lock_admin() {
		global $pagenow;
		// admin-post.php carries the panel's own forms (contacts, import); the handlers
		// check the capability themselves.
		if ( wp_doing_ajax() || wp_doing_cron() || 'admin-post.php' === $pagenow ) {
			return;
		}
		if ( ! self::is_panel_only() ) {
			return;
		}
		wp_safe_redirect( self::panel_url() );
		exit;
	}

	/** wp-login.php after a panel-only login must land on the panel, not the dashboard. */
	public static function filter_login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && self::is_panel_only( $user ) ) {
			return self::panel_url();
		}
		return $redirect_to;
	}

	public static function filter_nocache_header( $headers ) {
		if ( is_page( self::panel_page_id() ) ) {
			// The page holds a nonce and a login form; a cached copy would hand the
			// next visitor an expired nonce.
			$headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
			$headers['Pragma']        = 'no-cache';
			// LiteSpeed Cache reads its own header, so this tells it not to store the
			// response at all; otherwise one cached copy could be served for every
			// tab (?npt_tab=…).
			$headers['X-LiteSpeed-Cache-Control'] = 'no-cache';
		}
		return $headers;
	}

	/* ---------------------------------------------------------------------
	 * POST / logout, handled before any output so redirects work
	 * ------------------------------------------------------------------- */
	/**
	 * Store the id of the page that really carries [navid_panel]. Links, form
	 * actions, the manifest and the push targets all read the stored id, and it
	 * can point to nothing (or the wrong page) after the page was re-created.
	 */
	public static function remember_page() {
		global $post;
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'navid_panel' ) && (int) get_option( self::PAGE_OPT, 0 ) !== (int) $post->ID ) {
			update_option( self::PAGE_OPT, (int) $post->ID, false );
		}
	}

	/** True on the page that shows the panel (by its content, or by the stored id). */
	public static function on_panel_page() {
		global $post;
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'navid_panel' ) ) {
			return true;
		}
		return is_page( self::panel_page_id() );
	}

	public static function handle_request() {
		self::remember_page();
		if ( isset( $_GET['npt_action'] ) && 'logout' === $_GET['npt_action'] ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( wp_verify_nonce( $nonce, 'npt_panel_logout' ) ) {
				wp_logout();
				wp_safe_redirect( self::panel_url() );
				exit;
			}
		}

		if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) && isset( $_POST['npt_panel_form'] ) ) {
			self::handle_post();
		}
	}

	private static function handle_post() {
		$form = isset( $_POST['npt_panel_form'] ) ? sanitize_key( wp_unslash( $_POST['npt_panel_form'] ) ) : '';
		check_admin_referer( self::NONCE . '_' . $form );

		$back = self::panel_url();
		if ( ! self::user_can_panel() ) {
			wp_safe_redirect( $back );
			exit;
		}

		if ( 'quick' === $form ) {
			$settings         = NPT_Bar::settings();
			$incoming         = isset( $_POST['npt_bar_settings'] ) && is_array( $_POST['npt_bar_settings'] ) ? wp_unslash( $_POST['npt_bar_settings'] ) : array();
			$allowed          = array( 'call_number', 'call_label', 'whatsapp_number', 'whatsapp_label', 'telegram_url', 'telegram_label', 'whatsapp_icon_url', 'call_icon_url', 'telegram_icon_url', 'popup_title', 'popup_subtitle', 'enable_call', 'enable_whatsapp', 'enable_telegram', 'enable_custom', 'custom_links' );
			foreach ( $allowed as $key ) {
				if ( array_key_exists( $key, $incoming ) ) {
					$settings[ $key ] = $incoming[ $key ];
				}
			}
			update_option( 'npt_bar_settings', NPT_Bar::sanitize( $settings ), false );
			npt_bump_table_cache_version();
			wp_safe_redirect( add_query_arg( array( 'npt_tab' => 'settings', 'npt_msg' => 'saved' ), $back ) );
			exit;
		}

		if ( 'notify' === $form ) {
			update_option( 'npt_notify_push', ! empty( $_POST['npt_notify_push'] ) ? 1 : 0 );
			update_option( 'npt_notify_sms', ! empty( $_POST['npt_notify_sms'] ) ? 1 : 0 );
			update_option( 'npt_pf_sms_site', ! empty( $_POST['npt_pf_sms_site'] ) ? 1 : 0 );
			$raw     = isset( $_POST['npt_notify_mobiles'] ) ? sanitize_textarea_field( wp_unslash( $_POST['npt_notify_mobiles'] ) ) : '';
			$numbers = array();
			foreach ( preg_split( '/[\s,،;]+/u', $raw ) as $part ) {
				$phone = NPT_Proforma::normalize_phone( $part );
				if ( $phone ) {
					$numbers[ $phone ] = $phone;
				}
			}
			update_option( 'npt_notify_mobiles', implode( "\n", array_slice( array_values( $numbers ), 0, 5 ) ), false );
			wp_safe_redirect( add_query_arg( array( 'npt_tab' => 'settings', 'npt_msg' => 'saved' ), $back ) );
			exit;
		}

		wp_safe_redirect( $back );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Password login (AJAX, rate limited, mobile / email / username accepted)
	 * ------------------------------------------------------------------- */
	public static function two_fa_on( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, self::TWO_FA_META, true );
	}

	/** Sends a fresh 6-digit code to the account's mobile; only its hash is kept. */
	private static function send_2fa_code( WP_User $user ) {
		$phone = self::user_mobile( $user );
		if ( '' === $phone ) {
			return new WP_Error( 'nophone', 'برای این حساب شماره‌ی موبایل ثبت نشده است.' );
		}
		if ( ! NPT_SMS::otp_ready() ) {
			return new WP_Error( 'nosms', 'سرویس پیامک هنوز آماده نیست.' );
		}
		$code = (string) random_int( 100000, 999999 );
		$sent = NPT_SMS::send_otp( $phone, $code );
		if ( is_wp_error( $sent ) ) {
			return new WP_Error( 'sms', 'کد ارسال نشد. دوباره تلاش کنید.' );
		}
		set_transient( 'npt_2fa_code_' . $user->ID, array( 'hash' => wp_hash_password( $code ), 'tries' => 0 ), self::PENDING_TTL );
		return true;
	}

	/** Checks a code from SMS first, then a backup code (a backup code is used up). */
	private static function verify_2fa( WP_User $user, $code ) {
		$code  = strtr( trim( (string) $code ), array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
		$key   = 'npt_2fa_code_' . $user->ID;
		$state = get_transient( $key );
		if ( is_array( $state ) ) {
			if ( (int) $state['tries'] >= 5 ) {
				delete_transient( $key );
				return false;
			}
			$state['tries'] = (int) $state['tries'] + 1;
			set_transient( $key, $state, self::PENDING_TTL );
			if ( preg_match( '/^\d{6}$/', $code ) && wp_check_password( $code, $state['hash'] ) ) {
				delete_transient( $key );
				return true;
			}
		}
		$hashes = get_user_meta( $user->ID, self::BACKUP_META, true );
		if ( is_array( $hashes ) ) {
			foreach ( $hashes as $i => $hash ) {
				if ( '' !== $code && wp_check_password( $code, $hash ) ) {
					unset( $hashes[ $i ] );
					update_user_meta( $user->ID, self::BACKUP_META, array_values( $hashes ) );
					return true;
				}
			}
		}
		return false;
	}

	/** Second step of the password login: the SMS code or a backup code. */
	public static function ajax_login_2fa() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'نشست شما منقضی شده است. صفحه را تازه کنید.' ), 403 );
		}
		$token = isset( $_POST['token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore
		$code  = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore
		$key   = 'npt_2fa_pending_' . $token;
		$pend  = '' !== $token ? get_transient( $key ) : false;
		if ( ! is_array( $pend ) ) {
			wp_send_json_error( array( 'message' => 'نشست ورود تمام شده است. دوباره رمز عبور را وارد کنید.' ), 401 );
		}
		$user = get_user_by( 'id', (int) $pend['uid'] );
		if ( ! $user || ! self::panel_user_allowed( $user ) ) {
			delete_transient( $key );
			wp_send_json_error( array( 'message' => 'نشست ورود تمام شده است. دوباره رمز عبور را وارد کنید.' ), 401 );
		}
		if ( (int) $pend['tries'] >= 5 ) {
			delete_transient( $key );
			wp_send_json_error( array( 'message' => 'تعداد تلاش زیاد بود. دوباره از اول وارد شوید.' ), 429 );
		}
		$pend['tries'] = (int) $pend['tries'] + 1;
		set_transient( $key, $pend, self::PENDING_TTL );
		if ( ! self::verify_2fa( $user, $code ) ) {
			wp_send_json_error( array( 'message' => 'کد درست نیست.' ), 401 );
		}
		delete_transient( $key );
		NPT_OTP::login( $user );
		wp_send_json_success( array( 'message' => 'ورود موفق.', 'reload' => true ) );
	}

	/** Dashboard settings: send the code that turns the two-step login on or off. */
	public static function ajax_2fa_setup() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::user_can_panel() ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$sent = self::send_2fa_code( wp_get_current_user() );
		if ( is_wp_error( $sent ) ) {
			wp_send_json_error( array( 'message' => $sent->get_error_message() ), 503 );
		}
		wp_send_json_success( array( 'message' => 'کد به موبایل شما ارسال شد.' ) );
	}

	/** Confirms the code, turns the two-step login on and returns 8 backup codes (shown once). */
	public static function ajax_2fa_confirm() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::user_can_panel() ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$user = wp_get_current_user();
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore
		if ( ! self::verify_2fa( $user, $code ) ) {
			wp_send_json_error( array( 'message' => 'کد درست نیست.' ), 401 );
		}
		$codes  = array();
		$hashes = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$plain    = wp_generate_password( 10, false, false );
			$codes[]  = $plain;
			$hashes[] = wp_hash_password( $plain );
		}
		update_user_meta( $user->ID, self::BACKUP_META, $hashes );
		update_user_meta( $user->ID, self::TWO_FA_META, 1 );
		wp_send_json_success( array( 'message' => 'ورود دومرحله‌ای فعال شد. کدهای پشتیبان را جایی امن ذخیره کنید.', 'codes' => $codes ) );
	}

	public static function ajax_2fa_disable() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::user_can_panel() ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$user = wp_get_current_user();
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore
		if ( ! self::verify_2fa( $user, $code ) ) {
			wp_send_json_error( array( 'message' => 'کد درست نیست.' ), 401 );
		}
		delete_user_meta( $user->ID, self::TWO_FA_META );
		delete_user_meta( $user->ID, self::BACKUP_META );
		wp_send_json_success( array( 'message' => 'ورود دومرحله‌ای خاموش شد.' ) );
	}

	/** WordPress profile screen (admins only): turn the two-step login off for a manager. */
	public static function admin_2fa_field( $user ) {
		if ( ! self::two_fa_on( $user->ID ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		echo '<h2>ورود دومرحله‌ای پنل مدیر</h2><table class="form-table"><tr><th>خاموش‌کردن</th><td>'
			. '<label><input type="checkbox" name="npt_2fa_reset" value="1"> ورود دومرحله‌ای این حساب خاموش شود (کدهای پشتیبان هم حذف می‌شوند)</label>'
			. '</td></tr></table>'; // phpcs:ignore
	}

	public static function admin_2fa_save( $user_id ) {
		if ( empty( $_POST['npt_2fa_reset'] ) || ! current_user_can( 'edit_user', $user_id ) ) { // phpcs:ignore
			return;
		}
		delete_user_meta( $user_id, self::TWO_FA_META );
		delete_user_meta( $user_id, self::BACKUP_META );
	}

	public static function ajax_login() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'نشست شما منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.' ), 403 );
		}
		$key  = 'npt_login_' . substr( hash_hmac( 'sha256', npt_client_ip(), wp_salt( 'auth' ) ), 0, 32 );
		$hits = absint( get_transient( $key ) );
		if ( $hits >= self::LOGIN_LIMIT ) {
			wp_send_json_error( array( 'message' => 'تلاش‌های ناموفق زیاد بود. ۱۵ دقیقه صبر کنید.' ), 429 );
		}

		$identity = sanitize_text_field( wp_unslash( isset( $_POST['identity'] ) ? $_POST['identity'] : '' ) );
		$password = (string) wp_unslash( isset( $_POST['password'] ) ? $_POST['password'] : '' );
		$remember = ! empty( $_POST['remember'] );

		$login  = $identity;
		$maybe  = NPT_Proforma::normalize_phone( $identity );
		if ( $maybe ) {
			$ids = get_users( array(
				'meta_key'    => 'npt_mobile',
				'meta_value'  => $maybe,
				'number'      => 1,
				'count_total' => false,
				'fields'      => 'ID',
			) );
			if ( ! $ids ) {
				$ids = get_users( array(
					'meta_key'    => 'billing_phone',
					'meta_value'  => $maybe,
					'number'      => 1,
					'count_total' => false,
					'fields'      => 'ID',
				) );
			}
			$found = $ids ? get_user_by( 'id', (int) $ids[0] ) : false;
			$login = $found ? $found->user_login : '';
		}

		$user = '' === $login ? null : wp_authenticate( $login, $password );
		if ( ! $user || is_wp_error( $user ) ) {
			set_transient( $key, $hits + 1, 15 * MINUTE_IN_SECONDS );
			wp_send_json_error( array( 'message' => 'نام کاربری/شماره یا رمز عبور درست نیست.' ), 401 );
		}
		if ( ! self::panel_user_allowed( $user ) ) {
			// Same answer as a wrong password: don't confirm that the account exists.
			set_transient( $key, $hits + 1, 15 * MINUTE_IN_SECONDS );
			wp_send_json_error( array( 'message' => 'نام کاربری/شماره یا رمز عبور درست نیست.' ), 401 );
		}

		delete_transient( $key );
		if ( self::two_fa_on( $user->ID ) ) {
			$sent = self::send_2fa_code( $user );
			if ( is_wp_error( $sent ) ) {
				wp_send_json_error( array( 'message' => $sent->get_error_message() ), 503 );
			}
			$token = wp_generate_password( 32, false, false );
			set_transient( 'npt_2fa_pending_' . $token, array( 'uid' => $user->ID, 'tries' => 0 ), self::PENDING_TTL );
			wp_send_json_success( array( 'needs_2fa' => true, 'token' => $token, 'message' => 'کد تأیید به موبایل شما ارسال شد.' ) );
		}
		NPT_OTP::login( $user );
		wp_send_json_success( array( 'message' => 'ورود موفق.', 'reload' => true ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings tab «مدیران کسب‌وکار»: add or remove panel accounts
	 * ------------------------------------------------------------------- */

	/**
	 * Add a business manager. A number that already belongs to an account
	 * (or an email that does) gets the role on that account; otherwise a new
	 * account is created. The login is always OTP-capable because the number
	 * is stored on the account.
	 */
	public static function handle_manager_add() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
		}
		check_admin_referer( 'npt_manager_add', 'npt_manager_add_nonce' );
		$back  = NPT_SMS::settings_url( 'managers' );
		$name  = isset( $_POST['npt_manager_name'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_manager_name'] ) ) : '';
		$phone = NPT_Proforma::normalize_phone( isset( $_POST['npt_manager_phone'] ) ? wp_unslash( $_POST['npt_manager_phone'] ) : '' );
		$email = isset( $_POST['npt_manager_email'] ) ? sanitize_email( wp_unslash( $_POST['npt_manager_email'] ) ) : '';

		if ( '' === $phone ) {
			wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'invalid', $back ) );
			exit;
		}

		// Only the number stored by this plugin counts: billing phones are typed by
		// customers themselves, so matching on them could give the role to a stranger.
		$user = false;
		$ids  = get_users( array( 'meta_key' => 'npt_mobile', 'meta_value' => $phone, 'number' => 1, 'count_total' => false, 'fields' => 'ID' ) ); // phpcs:ignore
		if ( $ids ) {
			$user = get_user_by( 'id', (int) $ids[0] );
		}
		if ( ! $user && '' !== $email ) {
			$user = get_user_by( 'email', $email );
		}
		if ( $user ) {
			$user->add_role( self::ROLE );
			update_user_meta( $user->ID, 'npt_mobile', $phone );
			wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'granted', $back ) );
			exit;
		}

		$login = 'npt_' . $phone;
		if ( username_exists( $login ) ) {
			$login .= '_' . wp_rand( 100, 999 );
		}
		$user_id = wp_insert_user( array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => $email,
			'display_name' => '' !== $name ? $name : $phone,
			'first_name'   => $name,
			'role'         => self::ROLE,
		) );
		if ( is_wp_error( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'failed', $back ) );
			exit;
		}
		update_user_meta( $user_id, 'npt_mobile', $phone );
		wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'added', $back ) );
		exit;
	}

	/** Take the panel role away. The account itself stays; a role-less account falls back to customer. */
	public static function handle_manager_remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'npt_manager_remove_' . $id );
		$back = NPT_SMS::settings_url( 'managers' );
		$user = $id ? get_user_by( 'id', $id ) : false;
		if ( $user && in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->remove_role( self::ROLE );
			$fresh = get_user_by( 'id', $id );
			if ( $fresh && empty( $fresh->roles ) ) {
				$fresh->set_role( get_role( 'customer' ) ? 'customer' : 'subscriber' );
			}
		}
		wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'removed', $back ) );
		exit;
	}

	const SEEN_META = 'npt_pf_seen';
	const TWO_FA_META = 'npt_2fa';
	const BACKUP_META = 'npt_2fa_backup';
	const PENDING_TTL = 300;

	/** Move one online proforma to another status. «call» only moves it out of «pending». */
	public static function ajax_pf_status() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::user_can_panel() ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$id     = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : ''; // phpcs:ignore
		if ( ! $id || ! isset( NPT_Proforma::STATUSES[ $status ] ) ) {
			wp_send_json_error( array( 'message' => 'وضعیت نامعتبر است.' ), 400 );
		}
		global $wpdb;
		$table = NPT_Proforma::table_proformas();
		if ( ! empty( $_POST['only_pending'] ) ) { // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = %s WHERE id = %d AND status = %s", $status, $id, 'pending' ) ); // phpcs:ignore
		} else {
			$wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}
		wp_send_json_success( array( 'status' => $status ) );
	}

	/** Highest site proforma id this account has already seen; null when never set. */
	private static function seen_id() {
		$value = get_user_meta( get_current_user_id(), self::SEEN_META, true );
		return '' === $value ? null : (int) $value;
	}

	/** Bell badge: site proformas that arrived after the account last opened the list. */
	public static function ajax_unseen() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::user_can_panel() ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		global $wpdb;
		$table = NPT_Proforma::table_proformas();
		if ( null === self::seen_id() ) {
			// First visit with the feature: start counting from now, not from the whole archive.
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(id),0) FROM $table WHERE source = %s", 'site' ) ); // phpcs:ignore
			update_user_meta( get_current_user_id(), self::SEEN_META, $max );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(MAX(number),0) AS last FROM $table WHERE source = %s AND id > %d", 'site', self::seen_id() ) ); // phpcs:ignore
		wp_send_json_success( array( 'count' => (int) $row->n, 'last' => (int) $row->last ) );
	}

	private static function render_menu_js() {
		?>
		<script>
		(function () {
			var menuBtn = document.getElementById('nptp-menu-btn');
			var drop = document.getElementById('nptp-side');
			var profBtn = document.getElementById('nptp-profile-btn');
			var profMenu = document.getElementById('nptp-profile-menu');
			function setMenu(open) {
				if (! drop) { return; }
				drop.classList.toggle('is-open', open);
				if (menuBtn) { menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
			}
			if (menuBtn && drop) {
				menuBtn.addEventListener('click', function () { setMenu(! drop.classList.contains('is-open')); });
				Array.prototype.forEach.call(drop.querySelectorAll('a'), function (a) {
					a.addEventListener('click', function (e) {
						e.preventDefault();
						var href = a.getAttribute('href');
						setMenu(false);
						window.setTimeout(function () { window.location.href = href; }, 260);
					});
				});
			}
			if (profBtn && profMenu) {
				profBtn.addEventListener('click', function () {
					var open = profMenu.hidden;
					profMenu.hidden = ! open;
					profBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
				});
			}
			document.addEventListener('click', function (e) {
				if (drop && menuBtn && ! drop.contains(e.target) && ! menuBtn.contains(e.target)) { setMenu(false); }
				if (profMenu && profBtn && ! profMenu.contains(e.target) && ! profBtn.contains(e.target)) { profMenu.hidden = true; }
			});
		}());
		</script>
		<?php
	}

	private static function render_unseen_js() {
		$cfg = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
		);
		?>
		<script>
		(function () {
			var dot = document.getElementById('nptp-bell-dot');
			if (! dot) { return; }
			var cfg = <?php echo wp_json_encode( $cfg ); ?>;
			var fa = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
			var known = null;
			function check() {
				var fd = new FormData();
				fd.append('action', 'npt_pf_unseen');
				fd.append('nonce', cfg.nonce);
				fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (! res || ! res.success) { return; }
						var n = res.data.count;
						dot.textContent = fa(n);
						dot.hidden = n < 1;
						if (known !== null && n > known && 'Notification' in window && Notification.permission === 'granted') {
							new Notification('پیش‌فاکتور جدید از سایت', { body: 'شماره #' + fa(res.data.last), lang: 'fa', dir: 'rtl' });
						}
						known = n;
					})
					.catch(function () {});
			}
			check();
			setInterval(check, 60000);
		}());
		</script>
		<?php
	}

	/** Edit a business manager: name, mobile, email, and optionally a new password. */
	public static function handle_manager_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
		}
		$id = isset( $_POST['npt_mgr_id'] ) ? absint( wp_unslash( $_POST['npt_mgr_id'] ) ) : 0;
		check_admin_referer( 'npt_manager_update_' . $id, 'npt_manager_update_nonce' );
		$back = NPT_SMS::settings_url( 'managers' );
		$user = $id ? get_user_by( 'id', $id ) : false;
		if ( ! $user || ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'failed', $back ) );
			exit;
		}

		$name  = isset( $_POST['npt_manager_name'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_manager_name'] ) ) : '';
		$email = isset( $_POST['npt_manager_email'] ) ? sanitize_email( wp_unslash( $_POST['npt_manager_email'] ) ) : '';
		$pass  = isset( $_POST['npt_manager_pass'] ) ? (string) wp_unslash( $_POST['npt_manager_pass'] ) : '';
		$phone = NPT_Proforma::normalize_phone( isset( $_POST['npt_manager_phone'] ) ? wp_unslash( $_POST['npt_manager_phone'] ) : '' );

		if ( '' === $phone ) {
			wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'invalid', $back ) );
			exit;
		}
		$other = self::user_by_mobile( $phone );
		if ( $other && (int) $other->ID !== $id ) {
			wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'duplicate', $back ) );
			exit;
		}
		if ( '' !== $pass && strlen( $pass ) < 8 ) {
			wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'weak', $back ) );
			exit;
		}

		$args = array(
			'ID'           => $id,
			'display_name' => '' !== $name ? $name : $phone,
			'first_name'   => $name,
			'user_email'   => '' !== $email ? $email : $user->user_email,
		);
		if ( '' !== $pass ) {
			$args['user_pass'] = $pass;
		}
		$res = wp_update_user( $args );
		if ( is_wp_error( $res ) ) {
			$msg = in_array( 'existing_user_email', $res->get_error_codes(), true ) ? 'duplicate' : 'failed';
			wp_safe_redirect( add_query_arg( 'npt_mgr_msg', $msg, $back ) );
			exit;
		}
		update_user_meta( $id, 'npt_mobile', $phone );
		wp_safe_redirect( add_query_arg( 'npt_mgr_msg', 'updated', $back ) );
		exit;
	}

	/** The settings tab: the add form and the current business managers. */
	public static function render_managers_panel() {
		$notes = array(
			'added'   => array( 'notice-success', 'حساب مدیر کسب‌وکار ساخته شد. ورود او با شماره موبایل و کد پیامکی انجام می‌شود.' ),
			'granted' => array( 'notice-success', 'نقش مدیر کسب‌وکار به حساب موجود داده شد.' ),
			'removed' => array( 'notice-success', 'دسترسی پنل از این حساب برداشته شد.' ),
			'invalid' => array( 'notice-error', 'شماره موبایل معتبر نیست.' ),
			'failed'  => array( 'notice-error', 'ساخت حساب انجام نشد؛ دوباره تلاش کنید.' ),
			'updated' => array( 'notice-success', 'مشخصات مدیر کسب‌وکار ذخیره شد.' ),
			'duplicate' => array( 'notice-error', 'این شماره موبایل به حساب دیگری تعلق دارد.' ),
			'weak'    => array( 'notice-error', 'رمز عبور باید حداقل ۸ نویسه باشد.' ),
		);
		$msg      = isset( $_GET['npt_mgr_msg'] ) ? sanitize_key( wp_unslash( $_GET['npt_mgr_msg'] ) ) : ''; // phpcs:ignore
		$managers = get_users( array( 'role' => self::ROLE, 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$edit_id   = isset( $_GET['npt_mgr_edit'] ) ? absint( wp_unslash( $_GET['npt_mgr_edit'] ) ) : 0; // phpcs:ignore
		$edit_user = $edit_id ? get_user_by( 'id', $edit_id ) : false;
		if ( $edit_user && ! in_array( self::ROLE, (array) $edit_user->roles, true ) ) {
			$edit_user = false;
		}
		?>
		<style>
			.nptmg { max-width: 1100px; margin-top: 12px; }
			.nptmg-box { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; }
			.nptmg-box h2 { margin-top: 0; }
			.nptmg-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
			.nptmg-form label { display: block; font-weight: 600; margin-bottom: 3px; font-size: 12px; }
			.nptmg-t { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dcdcde; }
			.nptmg-t th, .nptmg-t td { border-bottom: 1px solid #f0f0f1; padding: 8px 12px; text-align: right; font-size: 13px; }
			.nptmg-t th { background: #f6f7f7; font-weight: 700; }
			.nptmg-t .del { color: #b32d2e; text-decoration: none; }
			@media (max-width:782px){ .nptmg-form { display: block; } .nptmg-form > div { margin-bottom: 10px; } .nptmg-t { display: block; overflow-x: auto; white-space: nowrap; } }
		</style>
		<div class="nptmg">
			<p class="description">مدیران کسب‌وکار فقط به «پنل مدیر کسب و کار» دسترسی دارند و به پیشخوان وردپرس راه ندارند. اگر شماره‌ی موبایل یا ایمیل وارد‌شده متعلق به حساب موجودی باشد، همان حساب مدیر می‌شود؛ وگرنه حساب تازه‌ای ساخته می‌شود.</p>
			<?php if ( isset( $notes[ $msg ] ) ) : ?>
				<div class="notice <?php echo esc_attr( $notes[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notes[ $msg ][1] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $edit_user ) : ?>
			<div class="nptmg-box">
				<h2>ویرایش مدیر کسب‌وکار</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nptmg-form">
					<?php wp_nonce_field( 'npt_manager_update_' . $edit_user->ID, 'npt_manager_update_nonce' ); ?>
					<input type="hidden" name="action" value="npt_manager_update">
					<input type="hidden" name="npt_mgr_id" value="<?php echo esc_attr( $edit_user->ID ); ?>">
					<div><label>نام کاربری (ثابت)</label><input type="text" value="<?php echo esc_attr( $edit_user->user_login ); ?>" dir="ltr" disabled></div>
					<div><label>نام و نام خانوادگی</label><input type="text" name="npt_manager_name" value="<?php echo esc_attr( $edit_user->first_name ? $edit_user->first_name : $edit_user->display_name ); ?>"></div>
					<div><label>موبایل</label><input type="text" name="npt_manager_phone" dir="ltr" value="<?php echo esc_attr( self::user_mobile( $edit_user ) ); ?>" required></div>
					<div><label>ایمیل (اختیاری)</label><input type="email" name="npt_manager_email" dir="ltr" value="<?php echo esc_attr( $edit_user->user_email ); ?>"></div>
					<div><label>رمز جدید (خالی = بدون تغییر، حداقل ۸ نویسه)</label><input type="password" name="npt_manager_pass" minlength="8" autocomplete="new-password" dir="ltr"></div>
					<div><button type="submit" class="button button-primary">ذخیره</button> <a href="<?php echo esc_url( remove_query_arg( 'npt_mgr_edit', NPT_SMS::settings_url( 'managers' ) ) ); ?>">انصراف</a></div>
				</form>
			</div>
			<?php endif; ?>

			<div class="nptmg-box">
				<h2>افزودن مدیر کسب‌وکار</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nptmg-form">
					<?php wp_nonce_field( 'npt_manager_add', 'npt_manager_add_nonce' ); ?>
					<input type="hidden" name="action" value="npt_manager_add">
					<div><label>نام و نام خانوادگی</label><input type="text" name="npt_manager_name"></div>
					<div><label>موبایل (برای ورود با کد پیامکی)</label><input type="text" name="npt_manager_phone" dir="ltr" placeholder="09121234567" required></div>
					<div><label>ایمیل (اختیاری)</label><input type="email" name="npt_manager_email" dir="ltr"></div>
					<div><button type="submit" class="button button-primary">افزودن</button></div>
				</form>
			</div>

			<?php if ( empty( $managers ) ) : ?>
				<p>هنوز مدیر کسب‌وکاری ثبت نشده است.</p>
			<?php else : ?>
			<table class="nptmg-t">
				<thead>
					<tr>
						<th>نام</th>
						<th>موبایل</th>
						<th>ایمیل</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $managers as $m ) : ?>
					<tr>
						<td><?php echo esc_html( $m->display_name ? $m->display_name : $m->user_login ); ?></td>
						<td dir="ltr"><?php echo esc_html( self::user_mobile( $m ) ? npt_to_persian_digits( self::user_mobile( $m ) ) : '—' ); ?></td>
						<td dir="ltr"><?php echo esc_html( $m->user_email ? $m->user_email : '—' ); ?></td>
						<td><a href="<?php echo esc_url( add_query_arg( 'npt_mgr_edit', $m->ID, NPT_SMS::settings_url( 'managers' ) ) ); ?>">ویرایش</a> · <a class="del" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'npt_manager_remove', 'id' => $m->ID ), admin_url( 'admin-post.php' ) ), 'npt_manager_remove_' . $m->ID ) ); ?>" onclick="return confirm('دسترسی پنل از این حساب برداشته شود؟');">برداشتن دسترسی</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Only the panel capability, or a real shop manager / admin, may use the panel. */
	private static function panel_user_allowed( WP_User $user ) {
		return $user->has_cap( self::CAP ) || $user->has_cap( 'manage_woocommerce' );
	}

	/* ---------------------------------------------------------------------
	 * Bind my number (scene=bind OTP)
	 * ------------------------------------------------------------------- */
	public static function ajax_bind_send() {
		self::bind_guard();
		$phone = NPT_Proforma::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		if ( ! $phone ) {
			wp_send_json_error( array( 'message' => 'شماره موبایل معتبر نیست.' ), 400 );
		}
		self::number_is_free( $phone );
		// The OTP sender keeps its own nonce/limits/credentials logic; only the
		// scene differs, and scene is compared with hash_equals on verify.
		$_POST['scene'] = 'bind';
		NPT_OTP::ajax_send();
	}

	public static function ajax_bind_done() {
		self::bind_guard();
		if ( ! check_ajax_referer( NPT_OTP::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'نشست شما منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.' ), 403 );
		}
		$phone = NPT_Proforma::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		$code  = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '';

		$verified = NPT_OTP::verify_code( $phone, $code, 'bind' );
		if ( is_wp_error( $verified ) ) {
			wp_send_json_error( array( 'message' => $verified->get_error_message() ), self::bind_status( $verified ) );
		}
		self::number_is_free( $verified );

		$user = wp_get_current_user();
		// billing_phone as well, so WooCommerce/Cando order forms and any future
		// code that reads the shop field find the same number.
		update_user_meta( $user->ID, 'npt_mobile', $verified );
		update_user_meta( $user->ID, 'billing_phone', $verified );
		wp_send_json_success( array( 'message' => 'شماره ذخیره شد. از این به بعد می‌توانید با کد پیامکی وارد شوید.', 'reload' => true ) );
	}

	private static function bind_guard() {
		// Any signed-in account may attach its own number: the code proves it is theirs.
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'ابتدا وارد حساب خود شوید.' ), 403 );
		}
	}

	/** A number that belongs to a different account must never be adopted. */
	private static function number_is_free( $phone ) {
		$owner = self::user_by_mobile( $phone );
		if ( $owner && (int) $owner->ID !== (int) get_current_user_id() ) {
			wp_send_json_error( array( 'message' => 'این شماره روی حساب دیگری ثبت شده است.' ), 409 );
		}
	}

	private static function bind_status( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
	}

	/* ---------------------------------------------------------------------
	 * Shortcode entry point
	 * ------------------------------------------------------------------- */
	public static function shortcode( $atts = array() ) {
		self::maybe_install();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		ob_start();
		if ( self::user_can_panel() ) {
			self::render_dashboard();
		} else {
			self::render_login();
		}
		// The script queries the DOM as soon as it parses, so it has to come after
		// the markup rather than in the head.
		self::render_assets();
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Panel stylesheet / script (inline, only on the panel page)
	 * ------------------------------------------------------------------- */
	public static function render_assets() {
		$head     = vsprintf( '#%02x%02x%02x', npt_header_color_rgb() );
		$accent   = vsprintf( '#%02x%02x%02x', npt_accent_color_rgb() );
		// One look for the whole site: the panel follows the theme's main color
		// (the PDF header color stays for the printed price list only).
		if ( function_exists( 'navid_options' ) ) {
			$theme_color = sanitize_hex_color( navid_options()['primary_color'] );
			if ( $theme_color ) {
				$head   = $theme_color;
				$accent = $theme_color;
			}
		}
		$font_url = get_template_directory_uri() . '/assets/fonts/';
		$cooldown = 60;
		?>
		<style id="npt-panel-css">
		@font-face{font-family:'NPTPnlVazir';src:url('<?php echo esc_url( $font_url ); ?>Vazirmatn-Regular.woff2') format('woff2'),url('<?php echo esc_url( NPT_URL ); ?>vendor/fonts/Vazirmatn-Regular.ttf') format('truetype');font-weight:400;font-style:normal;font-display:swap}
		@font-face{font-family:'NPTPnlVazir';src:url('<?php echo esc_url( $font_url ); ?>Vazirmatn-Bold.woff2') format('woff2'),url('<?php echo esc_url( NPT_URL ); ?>vendor/fonts/Vazirmatn-Bold.ttf') format('truetype');font-weight:700;font-style:normal;font-display:swap}
		.nptp input[type=text],.nptp input[type=tel],.nptp input[type=email],.nptp input[type=number],.nptp input[type=search],.nptp input[type=password],.nptp select,.nptp textarea{padding:10px 12px;border:1px solid #dbe2ec;border-radius:11px;font:inherit;font-size:14.5px;background:#fbfcfe;color:inherit;min-height:42px;max-width:100%}
		.nptp input:focus,.nptp select:focus,.nptp textarea:focus{outline:0;border-color:var(--pnl-head);background:#fff;box-shadow:0 0 0 3px color-mix(in srgb,var(--pnl-head) 15%,transparent)}
		.nptp{--pnl-head:<?php echo esc_html( $head ); ?>;--pnl-accent:<?php echo esc_html( $accent ); ?>;direction:rtl;font-family:'NPTPnlVazir',Tahoma,Arial,sans-serif;color:#1c2430;max-width:1120px;margin:0 auto;padding:16px 12px;font-size:14.5px;line-height:1.85}
		.nptp *,.nptp *:before,.nptp *:after{box-sizing:border-box}
		.nptp img{max-width:100%;height:auto}
		.nptp-auth-wrap{display:flex;justify-content:center;align-items:center;min-height:calc(100vh - 140px);padding:24px 0}
		.nptp-auth{width:100%;max-width:430px;background:#fff;border:1px solid #eef1f6;border-radius:18px;box-shadow:0 12px 32px rgba(20,30,50,.10);padding:22px}
		.nptp-brand{display:flex;gap:12px;align-items:center;margin-bottom:18px}
		.nptp-brand-mark{width:44px;height:44px;border-radius:14px;background:var(--pnl-head);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;flex:none}
		.nptp-brand strong{display:block;font-size:17px;font-weight:700}
		.nptp-brand em{font-style:normal;font-size:12.5px;color:#68788f}
		.nptp-seg{display:flex;background:#f2f5fa;border-radius:12px;padding:4px;margin-bottom:16px;gap:4px}
		.nptp-seg-btn{flex:1;border:0;background:transparent;padding:9px 6px;border-radius:9px;font:inherit;font-weight:700;font-size:13.5px;color:#5a6b83;cursor:pointer}
		.nptp-seg-btn.is-active{background:#fff;color:var(--pnl-head);box-shadow:0 1px 6px rgba(20,30,50,.12)}
		.nptp-seg-btn:disabled{opacity:.45;cursor:not-allowed}
		.nptp-form label,.nptp-bind label{display:block;font-size:13px;font-weight:600;margin-bottom:14px;color:#3a4658}
		.nptp-form input,.nptp-bind input{display:block;width:100%;margin-top:6px;padding:11px 12px;border:1px solid #dbe2ec;border-radius:11px;font:inherit;font-size:15px;background:#fbfcfe;color:inherit}
		.nptp-form input:focus,.nptp-bind input:focus{outline:0;border-color:var(--pnl-head);background:#fff}
		.nptp-check{display:flex;align-items:center;gap:8px}
		.nptp-check input{width:auto;margin:0}
		.nptp-btn{width:100%;border:0;border-radius:12px;background:var(--pnl-head);color:#fff;padding:12px;font:inherit;font-weight:700;font-size:15px;cursor:pointer}
		.nptp-btn:disabled{opacity:.6;cursor:progress}
		.nptp-btn.is-ghost{background:#fff;color:var(--pnl-head);border:1px solid #dbe2ec;width:auto;padding:9px 18px}
		.nptp-link{background:none;border:0;color:var(--pnl-accent);font:inherit;font-size:13px;font-weight:700;cursor:pointer;padding:0;margin-top:6px;display:inline-block}
		.nptp-link:disabled{color:#9aa7b8;cursor:not-allowed}
		.nptp-back{display:inline-block;font-size:12.5px;color:#68788f;background:none;border:0;font-family:inherit;cursor:pointer;padding:0;margin-bottom:10px}
		.nptp-status{min-height:22px;font-size:13.5px;font-weight:700;margin:12px 0 0;color:#b3261e}
		.nptp-status.is-ok{color:#127a3c}
		.nptp-note{background:#f4f7fd;border:1px solid #e2eaf8;color:#3a4658;font-size:13px;padding:10px 12px;border-radius:11px;margin:0 0 14px}
		.nptp-note.is-ok{background:#eefaf1;border-color:#c8ecd4;color:#127a3c}
		.nptp-foot{font-size:13px;margin:14px 0 0}
		.nptp-foot.muted{color:#68788f;font-size:12px;line-height:1.7}
		.nptp-head{display:flex;justify-content:space-between;align-items:center;gap:10px;background:var(--pnl-head);color:#fff;border-radius:16px;padding:14px 18px}
		.nptp-head-id strong{display:block;font-size:16px}
		.nptp-head-id em{font-style:normal;font-size:12.5px;opacity:.85}
		.nptp-logout{color:#fff;background:rgba(255,255,255,.16);padding:8px 14px;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap}
		.nptp-subtabs{display:flex;gap:8px;overflow-x:auto;margin:12px 0}
		.nptp-subtabs a{white-space:nowrap;padding:8px 14px;border-radius:999px;background:#fff;border:1px solid #e3e8f1;font-size:13px;font-weight:700;color:#4a5a72;text-decoration:none}
		.nptp-subtabs a.is-active{background:var(--pnl-head);border-color:var(--pnl-head);color:#fff}
		.nptp-plusbar{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 4px}
		.nptp-menu-btn{width:38px;height:38px;border:0;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:20px;line-height:1;cursor:pointer}
		.nptp-menu-btn:focus-visible,.nptp-menu-btn:hover{background:rgba(255,255,255,.28)}
		.nptp-head{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:10px}
		.nptp-head-id{text-align:center;min-width:0;position:relative;z-index:1}
		.nptp-head-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;min-width:0;position:relative;z-index:2}
		.nptp-profile{position:relative}
		.nptp-profile-btn{display:flex;align-items:center;gap:6px;max-width:170px;padding:3px 10px 3px 3px;border:0;border-radius:999px;background:rgba(255,255,255,.16);color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
		.nptp-profile-btn:hover,.nptp-profile-btn:focus-visible{background:rgba(255,255,255,.28)}
		.nptp-pname{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
		.nptp-avatar{flex:0 0 28px;width:28px;height:28px;border-radius:50%;background:#fff;color:var(--pnl-head);display:grid;place-items:center;font-weight:800}
		.nptp-profile-menu{position:absolute;left:0;top:calc(100% + 8px);min-width:170px;background:#fff;border:1px solid #eef1f6;border-radius:12px;box-shadow:0 10px 24px rgba(20,30,50,.14);padding:6px;z-index:50}
		.nptp-profile-menu[hidden]{display:none}
		.nptp-profile-menu a{display:block;padding:10px 12px;border-radius:8px;color:#b32d2e;font-weight:700;text-decoration:none}
		.nptp-profile-menu a:hover{background:#fbeeee}
		.nptp-drop{display:grid;grid-template-rows:0fr;opacity:0;visibility:hidden;transition:grid-template-rows .26s ease,opacity .22s ease,visibility .26s;margin-top:0}
		.nptp-drop.is-open{grid-template-rows:1fr;opacity:1;visibility:visible;margin-top:12px}
		.nptp-drop-inner{min-height:0;overflow:hidden;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
		.nptp-drop-inner a{display:flex;align-items:center;gap:10px;padding:12px;border-radius:12px;background:#fff;border:1px solid #eef1f6;color:#1c2430;font-weight:700;text-decoration:none}
		.nptp-drop-inner a.is-current{background:var(--pnl-head);border-color:var(--pnl-head);color:#fff}
		.nptp-side-icon{flex:0 0 26px;width:26px;text-align:center;font-size:18px}
		@media (min-width:700px){.nptp-drop-inner{grid-template-columns:repeat(4,minmax(0,1fr))}}
		@media (prefers-reduced-motion:reduce){.nptp-drop{transition:none}}
		.nptp-main{min-width:0}
		.nptp-menu{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;background:#fff;border:1px solid #eef1f6;border-radius:16px;padding:10px;margin:12px 0;box-shadow:0 8px 20px rgba(20,30,50,.08)}
		.nptp-menu[hidden]{display:none}
		.nptp-menu a{display:flex;align-items:center;gap:10px;padding:12px;border-radius:12px;background:#f5f7fb;color:#1c2430;font-weight:700;text-decoration:none}
		.nptp-menu a.is-current{background:var(--pnl-head);color:#fff}
		.nptp-menu-icon{width:26px;text-align:center;font-size:18px}
		.nptp-login-tools{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:10px}
		.nptp-pf-status{display:grid;gap:4px;margin:8px 0 2px}
		.nptp-pf-cap{font-size:12px;font-weight:700;color:#68788f}
		.nptp-pf-status select{width:100%;min-height:40px;border-radius:10px;border:1px solid #d6dbe8;border-right-width:5px;padding:0 10px;font:inherit;font-weight:700;background:#fff}
		.nptp-pf-status select.nptp-st-pending{border-right-color:#e0a100;background:#fff8e6}
		.nptp-pf-status select.nptp-st-called{border-right-color:#1e6fd9;background:#eef4fd}
		.nptp-pf-status select.nptp-st-done{border-right-color:#127a3c;background:#eefaf1}
		.nptp-pf-status select.nptp-st-converted{border-right-color:#7b3fb0;background:#f5eefb}
		.nptp-status-chips{padding-top:9px}
		.nptp-status-chips a{position:relative}
		.nptp-chip-n{display:inline-block;min-width:20px;margin-right:6px;padding:0 6px;border-radius:10px;background:#eef1f7;color:#4a5a72;font-size:11px;line-height:18px;text-align:center}
		.nptp-status-chips a.is-active .nptp-chip-n{background:rgba(255,255,255,.22);color:#fff}
		.nptp-plus-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:12px;background:var(--pnl-head);color:#fff;font-weight:700;font-size:13.5px;text-decoration:none}
		.nptp-plus-btn span{font-size:18px;line-height:1}
		.nptp-plus-btn.is-alt{background:#fff;color:var(--pnl-head);border:1px solid var(--pnl-head)}
		.nptp-share{cursor:pointer;font:inherit}
		.nptp-item-results{display:flex;flex-direction:column;gap:6px;margin:6px 0}
		.nptp-item-pick{text-align:right;padding:8px 10px;border:1px solid #e3e8f1;border-radius:10px;background:#f7f8fa;cursor:pointer;font:inherit}
		.nptp-head-actions{display:flex;align-items:center;gap:8px}
		.nptp-logo{display:block;max-height:36px;max-width:96px;width:auto;height:auto;margin:0 auto 4px;object-fit:contain}
		.nptp-head{position:sticky;top:0;z-index:50}
		.nptp-head-id{overflow:hidden;min-width:0}
		.nptp-dash{padding-bottom:96px}
		.nptp-bottom{position:fixed;left:0;right:0;bottom:0;z-index:70;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:4px;background:#fff;border-top:1px solid #e3e8f1;box-shadow:0 -4px 14px rgba(20,40,80,.08);padding:6px 8px calc(6px + env(safe-area-inset-bottom))}
		.nptp-bottom a,.nptp-bottom button{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-width:0;font:inherit;font-size:11.5px;font-weight:700;color:#4a5a72;text-decoration:none;background:none;border:0;padding:6px 2px;border-radius:12px;cursor:pointer}
		.nptp-bottom a:hover,.nptp-bottom a:focus-visible,.nptp-bottom button:hover,.nptp-bottom button:focus-visible{background:#f1f5fb}
		.nptp-bottom span{font-size:18px;line-height:1}
		.nptp-bottom .nptp-avatar{width:26px;height:26px;font-size:13px}
		.nptp-stepper{display:flex;align-items:center;gap:8px;margin:8px 0 4px;max-width:260px}
		.nptp-stepper input{flex:1;min-width:0;height:44px;text-align:center;font-size:18px;font-weight:700;border-radius:12px;border:1px solid #d6dbe8}
		.nptp-step{width:44px;height:44px;flex:0 0 44px;border-radius:12px;border:1px solid #d6dbe8;background:#fff;font-size:22px;font-weight:700;line-height:1;color:var(--pnl-head);cursor:pointer}
		.nptp-price-tabs button{white-space:nowrap;padding:8px 14px;border-radius:999px;background:#fff;border:1px solid #e3e8f1;font:inherit;font-size:13px;font-weight:700;color:#4a5a72;cursor:pointer}
		.nptp-price-tabs button.is-active{background:var(--pnl-head);border-color:var(--pnl-head);color:#fff}
		.nptp-pt-panel[hidden]{display:none!important}
		.nptp-modal{position:fixed;inset:0;z-index:9000;background:rgba(10,20,40,.45);display:grid;place-items:center;padding:16px}
		.nptp-modal-card{background:#fff;border-radius:18px;padding:18px;width:100%;max-width:420px;max-height:90vh;overflow:auto;display:grid;gap:8px}
		.nptp-modal-card h3{margin:0}
		.nptp-modal-card textarea{width:100%;border-radius:10px;border:1px solid #d6dbe8;padding:8px;font:inherit}
		.nptp-2fa code{padding:6px 8px;background:#f4f7fd;border-radius:8px;margin:4px 0}
		.nptp-bottom-pop{position:fixed;left:12px;right:12px;bottom:84px;z-index:80;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(20,40,80,.18);padding:12px;display:grid;gap:8px;max-width:420px;margin:0 auto}
		.nptp-bottom-pop[hidden]{display:none!important}
		.nptp-bottom-who{font-weight:700;font-size:14px;padding:4px 6px;overflow-wrap:anywhere}
		.nptp-bottom-out{display:block;text-align:center;padding:12px;border-radius:12px;background:#fdecea;color:#b3261e;font-weight:700;text-decoration:none}
		.nptp-pf-del{color:#b3261e;border-color:#f1c6c2}
		.nptp-pf-acts{display:flex;flex-wrap:wrap;gap:6px}
		.nptp-pf-acts .nptp-act{flex:1 1 calc(50% - 6px);min-width:0;text-align:center;white-space:normal;word-break:keep-all;font-size:13px;padding:9px 6px}
		.nptp-pf-name,.nptp-pf-meta{overflow-wrap:anywhere}
		.nptp-pf-status select{max-width:100%}
		.nptp-pf-states{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:8px 0 2px}
		.nptp-pf-states .nptp-pf-cap{width:100%;font-size:12px;font-weight:700;color:#68788f}
		.nptp-st-btn{flex:1 1 calc(50% - 6px);min-width:0;padding:9px 6px;border-radius:10px;border:1px solid #d6dbe8;background:#fff;font:inherit;font-size:12.5px;font-weight:700;color:#4a5a72;cursor:pointer}
		.nptp-st-btn[data-busy],.nptp-pf-states[data-busy="1"] .nptp-st-btn{opacity:.7}
		.nptp-st-btn.is-on{color:#fff;border-color:transparent}
		.nptp-st-btn.is-on.nptp-st-pending{background:#e0a100}
		.nptp-st-btn.is-on.nptp-st-called{background:#1e6fd9}
		.nptp-st-btn.is-on.nptp-st-done{background:#127a3c}
		.nptp-st-btn.is-on.nptp-st-converted{background:#7b3fb0}
		.nptp .nptpf,.nptp .nptpf *{box-sizing:border-box;max-width:100%}
		.nptp .nptpf-items-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
		.nptp-bell{position:relative;display:grid;width:38px;height:38px;place-items:center;color:#fff;background:rgba(255,255,255,.16);border-radius:50%;text-decoration:none}
		.nptp-bell svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
		.nptp-bell:hover,.nptp-bell:focus-visible{background:rgba(255,255,255,.28)}
		.nptp-bell-dot{position:absolute;top:-4px;left:-4px;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:#e53935;color:#fff;font-size:11px;font-weight:700;line-height:20px;text-align:center}
		.nptp-tabs{display:flex;gap:8px;overflow-x:auto;margin:14px 0;padding-bottom:4px;-webkit-overflow-scrolling:touch}
		.nptp-tab{white-space:nowrap;padding:9px 16px;border-radius:999px;background:#fff;border:1px solid #e3e8f1;font-size:13.5px;font-weight:700;color:#4a5a72;text-decoration:none}
		.nptp-tab.is-active{background:var(--pnl-head);border-color:var(--pnl-head);color:#fff}
		.nptp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px}
		.nptp-stat{background:#fff;border:1px solid #e6ebf3;border-radius:14px;padding:14px 16px}
		.nptp-stat span{display:block;font-size:12.5px;color:#68788f}
		.nptp-stat strong{display:block;font-size:18px;margin:5px 0 2px;color:var(--pnl-head);font-weight:800}
		.nptp-stat em{font-style:normal;font-size:11.5px;color:#8796ab}
		.nptp-hint{font-size:12.5px;color:#68788f;background:#f7f9fc;border-radius:10px;padding:9px 12px}
		.nptp-box{background:#fff;border:1px solid #e6ebf3;border-radius:14px;padding:16px;margin-bottom:14px}
		.nptp-box h3{margin:0 0 14px;font-size:15px;font-weight:800}
		.nptp-box select{width:100%;max-width:340px;padding:9px 10px;border:1px solid #dbe2ec;border-radius:10px;font:inherit;font-size:14px;background:#fff}
		.nptp-box textarea{width:100%;padding:10px 12px;border:1px solid #dbe2ec;border-radius:10px;font:inherit;font-size:14px}
		.nptp-quick label{display:block;font-size:13px;font-weight:600;margin-bottom:14px;color:#3a4658}
		.nptp-quick input[type=text],.nptp-quick input[type=tel],.nptp-quick input[type=url]{display:block;width:100%;max-width:420px;margin-top:5px;padding:10px 12px;border:1px solid #dbe2ec;border-radius:10px;font:inherit;font-size:14.5px}
		.nptp-quick .nptp-check{font-weight:600;margin-bottom:14px}
		.nptp-quick .nptp-check input{width:auto;margin-inline-end:8px}
		.nptp .nptss,.nptp .nptpf-t{margin-top:0}
		.nptp .nptss-box,.nptp .nptpf-t{background:#fff;border:1px solid #e6ebf3;border-radius:14px;overflow:hidden}
		.nptp .nptss-box{padding:16px;margin-bottom:14px}
		.nptp .nptss label,.nptp .nptp-quick,.nptp .nptss textarea{margin-bottom:12px}
		.nptp .nptss textarea{width:100%}
		.nptp .nptss select{padding:9px 10px;border:1px solid #dbe2ec;border-radius:10px;font:inherit;background:#fff}
		.nptp .nptss input[type=text]{padding:9px 10px;border:1px solid #dbe2ec;border-radius:10px;font:inherit}
		.nptp .nptpf-t{width:100%;border-collapse:separate;border-spacing:0}
		.nptp .nptpf-t th,.nptp .nptpf-t td{border-bottom:1px solid #eef1f6;padding:10px 12px;text-align:right;font-size:13px}
		.nptp .nptpf-t th{background:#f6f8fc;font-weight:700}
		.nptp .button,.nptp .nptss .button{display:inline-block;background:#fff;border:1px solid #d5dce7;border-radius:10px;padding:8px 14px;font-size:13.5px;font-weight:600;cursor:pointer;color:#2c3a4f;text-decoration:none;font-family:inherit;line-height:1.6}
		.nptp .button-primary{background:var(--pnl-head);border-color:var(--pnl-head);color:#fff}
		.nptp [hidden]{display:none!important}
		.nptp-credit{text-align:center;font-size:12px;color:#8796ab;margin:22px 0 4px}
		.nptp-credit a{color:inherit}
		.nptp-dash{max-width:1180px}
		.nptp-head{border-radius:18px;padding:14px 16px}
		.nptp-head-id strong{font-size:16px}
		.nptp-head-id span{font-size:12.5px;opacity:.85}
		.nptp-tabs{display:grid;grid-auto-flow:column;grid-auto-columns:1fr;gap:4px;background:#eaeef5;padding:4px;border-radius:14px;margin:14px 0;position:sticky;top:8px;z-index:20;overflow:visible}
		.nptp-tab{text-align:center;padding:10px 4px;border:0;border-radius:10px;background:transparent;font-size:13.5px;color:#4a5a72}
		.nptp-tab.is-active{background:#fff;color:var(--pnl-head);box-shadow:0 1px 6px rgba(20,30,50,.14)}
		.nptp-stats{grid-template-columns:1fr 1fr}
		.nptp-stat{border-radius:16px}
		.nptp-pf{background:#fff;border:1px solid #e6ebf3;border-radius:16px;padding:14px 16px;margin-bottom:10px;display:grid;gap:4px}
		.nptp-pf-top{display:flex;justify-content:space-between;align-items:center}
		.nptp-pf-num{font-weight:800;color:var(--pnl-head)}
		.nptp-pf-name{font-weight:700;font-size:15.5px}
		.nptp-pf-meta{color:#68788f;font-size:12.5px}
		.nptp-pf-total{font-weight:800;font-size:15px}
		.nptp-pf-acts{display:flex;gap:8px;margin-top:6px}
		.nptp-act{flex:0 0 auto;padding:8px 14px;border-radius:10px;border:1px solid #d5dce7;background:#fff;color:#2c3a4f;font-weight:700;font-size:13px;text-decoration:none}
		.nptp-act.is-main{background:var(--pnl-head);border-color:var(--pnl-head);color:#fff}
		.nptp-badge{padding:2px 10px;border-radius:999px;font-size:11.5px;font-weight:700;background:#e7eaf3;color:#3c4a6b}
		.nptp-badge.is-site{background:#e3f6e9;color:#1a7f37}
		.nptp-empty{background:#fff;border:1px dashed #cfd7e4;border-radius:16px;padding:28px 16px;text-align:center;color:#68788f}
		.nptp-box{border-radius:16px}
		.nptp-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
		.nptp-chip{padding:9px 16px;border-radius:999px;border:1px solid #d5dce7;background:#fff;font:inherit;font-weight:700;font-size:14px;cursor:pointer;color:#2c3a4f}
		.nptp-chip.is-on{background:var(--pnl-head);border-color:var(--pnl-head);color:#fff}
		.nptp-pct-field{display:block;font-size:13px;font-weight:600;color:#3a4658}
		.nptp-pct-field input{display:block;width:140px;margin-top:6px;padding:10px 12px;border:1px solid #dbe2ec;border-radius:10px;font:inherit;font-size:15px}
		.nptp-pct-preview{background:#f7f9fc;border-radius:12px;padding:10px 12px;font-size:13px;margin:12px 0;line-height:1.9}
		.nptp-pct-row{display:flex;justify-content:space-between;gap:10px;color:#4a5a72}
		.nptp-pct-row em{font-style:normal;white-space:nowrap}
		.nptp-pct-actions,.nptp-device-actions{display:flex;gap:8px;flex-wrap:wrap}
		.nptp-pct-actions .nptp-btn,.nptp-device-actions .nptp-btn{width:auto;padding:11px 18px}
		.nptp-btn-ghost{background:#fff!important;color:#2c3a4f!important;border:1px solid #d5dce7!important}
		.nptp-btn:disabled{opacity:.5;cursor:not-allowed}
		.nptp-push-state{font-weight:700;margin:6px 0 10px}
		.nptp-device{margin-bottom:16px;padding-bottom:6px}
		.nptp-sub{display:flex;gap:6px;margin-bottom:14px}
		.nptp-sub a{padding:7px 16px;border-radius:999px;background:#fff;border:1px solid #e3e8f1;font-size:13px;font-weight:700;color:#4a5a72;text-decoration:none}
		.nptp-sub a.is-active{background:var(--pnl-head);border-color:var(--pnl-head);color:#fff}
		.nptp-details summary{cursor:pointer;font-weight:800;font-size:15px}
		.nptp-details form{margin-top:14px}
		.nptp-quick textarea{display:block;width:100%;max-width:420px;margin-top:5px}
		.nptp .nptsc-box,.nptp .nptst-box{background:#fff;border:1px solid #e6ebf3;border-radius:16px;padding:16px;margin-bottom:14px}
		.nptp .nptsc h2{font-size:15px;margin:0 0 12px}
		.nptp .nptsc input[type=text],.nptp .nptsc textarea{width:100%;max-width:420px;padding:9px 10px;border:1px solid #dbe2ec;border-radius:10px;font:inherit}
		.nptp .nptsc-form>div{margin-bottom:8px}
		.nptp .nptsc-t,.nptp .nptsr-t{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e6ebf3;border-radius:12px;display:block;overflow-x:auto}
		.nptp .nptsc-t th,.nptp .nptsc-t td,.nptp .nptsr-t th,.nptp .nptsr-t td{padding:9px 12px;border-bottom:1px solid #eef1f6;text-align:right;font-size:13px;white-space:nowrap}
		.nptp .notice{background:#f1f8f3;border:1px solid #cfe8d6;border-radius:12px;padding:4px 14px;margin-bottom:12px;font-size:13px}
		.nptp .notice-error{background:#fdf1f1;border-color:#f0cccc}
		.nptp .description,.nptp .meta{font-size:12.5px;color:#68788f}
		@media (max-width:600px){.nptp{padding:10px 4px}.nptp-auth{padding:18px;border-radius:14px}.nptp-head{padding:12px 14px;border-radius:12px}.nptp-stat strong{font-size:16px}}
		</style>
		<script>
		(function () {
			var wrap = document.querySelector('.nptp');
			if (! wrap) { return; }
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var otpNonce = <?php echo wp_json_encode( wp_create_nonce( NPT_OTP::NONCE ) ); ?>;
			var cooldownSeconds = <?php echo (int) $cooldown; ?>;
			var statusEl = wrap.querySelector('.nptp-status');
			var timer = null;
			// True between "code sent" and "code entered/aborted": the OTP mode has
			// two forms (request + verify) and only one of them may be visible.
			var codeSent = false;
			function setStatus(text, ok) {
				if (!statusEl) { return; }
				statusEl.textContent = text || '';
				statusEl.className = 'nptp-status' + (ok ? ' is-ok' : '');
			}
			function post(action, data, cb, which) {
				var fd = new FormData();
				Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
				fd.append('action', action);
				fd.append('nonce', which === 'otp' ? otpNonce : nonce);
				fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(cb)
					.catch(function () { setStatus('ارتباط با سرور برقرار نشد. دوباره تلاش کنید.'); });
			}
			function msg(res, fallback) {
				return (res && res.data && res.data.message) ? res.data.message : fallback;
			}
			function otpPost(action, data, cb) { return post(action, data, cb, 'otp'); }
			function tick(btn) {
				var left = cooldownSeconds, span = btn.querySelector('span');
				btn.disabled = true;
				if (span) { span.textContent = left; }
				clearInterval(timer);
				timer = setInterval(function () {
					left -= 1;
					if (span) { span.textContent = left; }
					if (left <= 0) { clearInterval(timer); btn.disabled = false; }
				}, 1000);
			}

			/* mode switch */
			var segs = wrap.querySelectorAll('.nptp-seg-btn');
			var forms = wrap.querySelectorAll('.nptp-form');
			function showMode(mode) {
				Array.prototype.forEach.call(segs, function (b) { b.classList.toggle('is-active', b.getAttribute('data-mode') === mode); });
				Array.prototype.forEach.call(forms, function (f) {
					if (f.getAttribute('data-mode') !== mode) { f.hidden = true; return; }
					f.hidden = (f === codeForm) ? !codeSent : codeSent;
				});
				setStatus('');
			}
			Array.prototype.forEach.call(segs, function (b) {
				b.addEventListener('click', function () { if (!b.disabled) { showMode(b.getAttribute('data-mode')); } });
			});

			var afterAuth = function () { if (window.nptpAfterAuth) { window.nptpAfterAuth(); } else { window.location.reload(); } };
			var askNotify = function () { if (window.nptpAskNotify) { window.nptpAskNotify(); } };

			/* OTP */
			var sendForm = wrap.querySelector('#nptp-otp-send');
			var codeForm = wrap.querySelector('#nptp-otp-code');
			if (sendForm && codeForm) {
				var phoneIn = sendForm.querySelector('[name=phone]');
				sendForm.addEventListener('submit', function (e) {
					e.preventDefault();
					var btn = sendForm.querySelector('button[type=submit]');
					btn.disabled = true;
					var capIn = function (form) { var b = form.querySelector('.nptp-cap'); return b ? { captcha_id: b.querySelector('.nptp-cap-id').value, captcha_answer: b.querySelector('.nptp-cap-a').value } : {}; };
					var capLoad = function (form) {
						otpPost('npt_captcha', {}, function (res) {
							var b = form.querySelector('.nptp-cap');
							if (!b || !res || !res.success) { return; }
							b.querySelector('.nptp-cap-q').textContent = res.data.q;
							b.querySelector('.nptp-cap-id').value = res.data.id;
							b.querySelector('.nptp-cap-a').value = '';
						});
					};
					var capNew = sendForm.querySelector('.nptp-cap-new');
					if (capNew) { capNew.addEventListener('click', function () { capLoad(sendForm); }); capLoad(sendForm); }
					function sendCode(extra) {
						return Object.assign({ phone: phoneIn.value }, capIn(sendForm), extra || {});
					}
					otpPost('npt_send_otp', sendCode(), function (res) {
						btn.disabled = false;
						capLoad(sendForm);
						setStatus(msg(res, 'ارسال کد انجام نشد.'), !!res.success);
						if (res && res.success) {
							codeSent = true;
							codeForm.hidden = false;
							sendForm.hidden = true;
							codeForm.querySelector('[name=code]').value = '';
							codeForm.querySelector('[name=code]').focus();
							tick(wrap.querySelector('#nptp-resend'));
						}
					});
				});
				codeForm.addEventListener('submit', function (e) {
					e.preventDefault();
					var btn = codeForm.querySelector('button[type=submit]');
					btn.disabled = true;
					askNotify();
					otpPost('npt_verify_otp', { phone: phoneIn.value, code: codeForm.querySelector('[name=code]').value }, function (res) {
						btn.disabled = false;
						setStatus(msg(res, 'بررسی کد انجام نشد.'), !!res.success);
						if (res && res.success) { afterAuth(); }
					});
				});
				var back = wrap.querySelector('#nptp-otp-back');
				if (back) {
					back.addEventListener('click', function () { codeSent = false; codeForm.hidden = true; sendForm.hidden = false; setStatus(''); });
				}
				var resend = wrap.querySelector('#nptp-resend');
				if (resend) {
					resend.addEventListener('click', function () {
						otpPost('npt_send_otp', sendCode(), function (res) {
							capLoad(sendForm);
							setStatus(msg(res, ''), !!res.success);
							if (res && res.success) { tick(resend); }
						});
					});
				}
			}

			/* password */
			var passForm = wrap.querySelector('#nptp-pass-form');
			if (passForm) {
				passForm.addEventListener('submit', function (e) {
					e.preventDefault();
					var btn = passForm.querySelector('button[type=submit]');
					btn.disabled = true;
					askNotify();
					post('npt_panel_login', {
						identity: passForm.querySelector('[name=identity]').value,
						password: passForm.querySelector('[name=password]').value,
						remember: passForm.querySelector('[name=remember]') ? 1 : ''
					}, function (res) {
						btn.disabled = false;
						if (res && res.data && res.data.needs_2fa) { show2fa(res.data.token); return; }
						setStatus(msg(res, 'ورود انجام نشد.'), !!res.success);
						if (res && res.success) { afterAuth(); }
					});
				});
			}

			/* two-step login: the code after a correct password */
			var twoForm = wrap.querySelector('#nptp-2fa-form');
			var pendingToken = '';
			function show2fa(token) {
				pendingToken = token;
				passForm.hidden = true;
				twoForm.hidden = false;
				twoForm.querySelector('[name=code]').value = '';
				twoForm.querySelector('[name=code]').focus();
				setStatus('کد پیامکی یا یکی از کدهای پشتیبان را وارد کنید.', true);
			}
			if (twoForm && passForm) {
				twoForm.addEventListener('submit', function (e) {
					e.preventDefault();
					var btn = twoForm.querySelector('button[type=submit]');
					btn.disabled = true;
					post('npt_panel_login_2fa', { token: pendingToken, code: twoForm.querySelector('[name=code]').value }, function (res) {
						btn.disabled = false;
						setStatus(msg(res, 'کد درست نیست.'), !!res.success);
						if (res && res.success) { afterAuth(); }
					});
				});
				var backBtn = twoForm.querySelector('#nptp-2fa-back');
				if (backBtn) {
					backBtn.addEventListener('click', function () { twoForm.hidden = true; passForm.hidden = false; setStatus(''); });
				}
			}

			/* registration */
			var regForm = wrap.querySelector('#nptp-reg-form');
			if (regForm) {
				var regStep = regForm.querySelector('.nptp-reg-code');
				regForm.addEventListener('submit', function (e) {
					e.preventDefault();
					var btn = regForm.querySelector('button[type=submit]');
					var action = regStep.hidden ? 'npt_register_send' : 'npt_register_done';
					btn.disabled = true;
					var data = { name: (regForm.querySelector('[name=name]') || {}).value || '', phone: regForm.querySelector('[name=phone]').value };
					if (action === 'npt_register_send') { var rb = regForm.querySelector('.nptp-cap'); if (rb) { data.captcha_id = rb.querySelector('.nptp-cap-id').value; data.captcha_answer = rb.querySelector('.nptp-cap-a').value; } }
					if (action === 'npt_register_done') {
						data.code = regForm.querySelector('[name=code]').value;
						data.name = regForm.querySelector('[name=name]').value;
					}
					otpPost(action, data, function (res) {
						btn.disabled = false;
						setStatus(msg(res, 'خطا.'), !!res.success);
						if (res && res.success) {
							if (action === 'npt_register_send') {
								regStep.hidden = false;
								regForm.querySelector('[name=code]').focus();
								tick(regForm.querySelector('.nptp-resend-btn'));
							} else if (res.data && res.data.redirect) {
								window.location.href = res.data.redirect;
							} else {
								window.location.reload();
							}
						}
					});
				});
				var regAgain = regForm.querySelector('.nptp-resend-btn');
				if (regAgain) {
					regAgain.addEventListener('click', function () {
						otpPost('npt_register_send', { name: regForm.querySelector('[name=name]').value, phone: regForm.querySelector('[name=phone]').value, captcha_id: (regForm.querySelector('.nptp-cap-id') || {}).value || '', captcha_answer: (regForm.querySelector('.nptp-cap-a') || {}).value || '' }, function (res) {
							var rbox = regForm.querySelector('.nptp-cap');
							if (rbox) { otpPost('npt_captcha', {}, function (c) { if (c && c.success) { rbox.querySelector('.nptp-cap-q').textContent = c.data.q; rbox.querySelector('.nptp-cap-id').value = c.data.id; rbox.querySelector('.nptp-cap-a').value = ''; } }); }
							setStatus(msg(res, ''), !!res.success);
							if (res && res.success) { tick(regAgain); }
						});
					});
				}
			}
			/* bind my number — dashboard only, same two-step shape as registration */
			var bindForm = wrap.querySelector('#nptp-bind-form');
			if (bindForm) {
				var bindStep = bindForm.querySelector('.nptp-bind-code');
				var bindPhone = bindForm.querySelector('[name=phone]');
				bindForm.addEventListener('submit', function (e) {
					e.preventDefault();
					var btn = bindForm.querySelector('button[type=submit]');
					var action = bindStep.hidden ? 'npt_bind_send' : 'npt_bind_done';
					btn.disabled = true;
					var data = { phone: bindPhone.value };
					if (action === 'npt_bind_done') { data.code = bindForm.querySelector('[name=code]').value; }
					otpPost(action, data, function (res) {
						btn.disabled = false;
						setStatus(msg(res, 'خطا.'), !!res.success);
						if (res && res.success) {
							if (action === 'npt_bind_send') {
								bindStep.hidden = false;
								bindForm.querySelector('[name=code]').focus();
								tick(bindForm.querySelector('.nptp-resend-btn'));
							} else {
								window.location.reload();
							}
						}
					});
				});
				var bindAgain = bindForm.querySelector('.nptp-resend-btn');
				if (bindAgain) {
					bindAgain.addEventListener('click', function () {
						otpPost('npt_bind_send', { phone: bindPhone.value }, function (res) {
							setStatus(msg(res, ''), !!res.success);
							if (res && res.success) { tick(bindAgain); }
						});
					});
				}
			}
		}());
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Login card
	 * ------------------------------------------------------------------- */
	private static function render_login() {
		$otp_on     = NPT_OTP::enabled() && NPT_SMS::otp_ready();
		$register   = function_exists( 'npt_register_url' ) ? npt_register_url() : '';
		$lost       = wp_lostpassword_url();
		?>
		<style>
			.nptp-done{position:fixed;inset:0;z-index:9999;background:rgba(10,20,40,.45);display:grid;place-items:center;padding:16px}
			.nptp-done-card{background:#fff;border-radius:18px;padding:22px;max-width:340px;width:100%;text-align:center;display:grid;gap:10px;justify-items:center;font-family:inherit}
			.nptp-done-card p{margin:0;font-size:13px;color:#4a5a72;line-height:1.8}
			.nptp-check-svg{width:64px;height:64px}
			.nptp-check-svg circle{fill:#e8f7ee;stroke:#14733b;stroke-width:2}
			.nptp-check-svg path{fill:none;stroke:#14733b;stroke-width:4;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:40;stroke-dashoffset:40;animation:nptp-draw .5s .1s forwards}
			@keyframes nptp-draw{to{stroke-dashoffset:0}}
		</style>
		<div class="nptp nptp-auth-wrap" dir="rtl">
			<div class="nptp-auth">
				<div class="nptp-login-tools">
					<button type="button" class="nptp-btn nptp-btn-ghost" id="nptp-login-install" hidden>نصب برنامه روی گوشی</button>
					<button type="button" class="nptp-btn nptp-btn-ghost" id="nptp-login-notify">فعال‌سازی اعلان‌ها</button>
				</div>
				<p class="nptp-hint" id="nptp-login-hint" hidden></p>
				<script>
				(function () {
					var installBtn = document.getElementById('nptp-login-install');
					var notifyBtn = document.getElementById('nptp-login-notify');
					var hint = document.getElementById('nptp-login-hint');
					var sw = <?php echo wp_json_encode( NPT_Push::sw_url() ); ?>;
					var scope = <?php echo wp_json_encode( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ); ?>;
					var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
					var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone;
					function say(text, ok) { hint.hidden = false; hint.textContent = text; hint.style.color = ok ? '#14733b' : ''; }
					if ('serviceWorker' in navigator) { navigator.serviceWorker.register(sw, { scope: scope || '/' }).catch(function () {}); }
					var deferred = null;
					window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; installBtn.hidden = false; });
					setTimeout(function () {
						if (! deferred && ! standalone) { installBtn.hidden = false; }
					}, 3000);
					installBtn.addEventListener('click', function () {
						if (deferred) { deferred.prompt(); deferred = null; installBtn.hidden = true; return; }
						if (isIOS) { say('در Safari: دکمه‌ی اشتراک‌گذاری را بزنید و «افزودن به صفحه‌ی اصلی» را انتخاب کنید.'); return; }
						say('در منوی مرورگر (⋮ یا ⋯) گزینه‌ی «نصب برنامه» یا «افزودن به صفحه‌ی اصلی» را بزنید.');
					});
					notifyBtn.addEventListener('click', function () {
						if (! ('Notification' in window)) { say('این مرورگر اعلان را پشتیبانی نمی‌کند.'); return; }
						Notification.requestPermission().then(function (perm) {
							if (perm === 'granted') { say('اعلان مجاز شد. بعد از ورود، روی زنگ اعلان، دریافت اعلان را روشن کنید.', true); }
							else { say('اجازه‌ی اعلان داده نشد. از تنظیمات مرورگر می‌توانید آن را باز کنید.'); }
						});
					});
				function markSeen() { try { localStorage.setItem('nptpInstallSeen', '1'); } catch (e) {} }
					function seen() { try { return localStorage.getItem('nptpInstallSeen') === '1'; } catch (e) { return false; } }
					/* Called from the submit click itself, because browsers only show the prompt after a user action. */
					window.nptpAskNotify = function () {
						try {
							if ('Notification' in window && Notification.permission === 'default' && !(isIOS && !standalone)) { Notification.requestPermission(); }
						} catch (e) {}
					};
					window.nptpAfterAuth = function () {
						var o = document.createElement('div');
						o.className = 'nptp-done';
						o.innerHTML = '<div class="nptp-done-card"><svg class="nptp-check-svg" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg><strong>ورود انجام شد</strong></div>';
						document.body.appendChild(o);
						var go = function () { window.location.reload(); };
						setTimeout(function () {
							if (standalone || seen() || (!deferred && !isIOS)) { go(); return; }
							var card = o.querySelector('.nptp-done-card');
							card.innerHTML = deferred
								? '<strong>نصب روی گوشی</strong><p>با نصب، پنل مثل یک برنامه باز می‌شود و اعلان‌ها هم می‌رسد.</p><button type="button" class="nptp-btn" id="nptp-inst-go">نصب برنامه</button><button type="button" class="nptp-link" id="nptp-inst-skip">فعلاً نه</button>'
								: '<strong>نصب روی آیفون</strong><p>در Safari دکمه‌ی اشتراک‌گذاری را بزنید و «افزودن به صفحه‌ی اصلی» را انتخاب کنید. بعد از نصب، از همان برنامه وارد شوید.</p><button type="button" class="nptp-btn" id="nptp-inst-skip">متوجه شدم، ادامه</button>';
							var skip = card.querySelector('#nptp-inst-skip'), inst = card.querySelector('#nptp-inst-go');
							skip.addEventListener('click', function () { markSeen(); go(); });
							if (inst) { inst.addEventListener('click', function () { markSeen(); deferred.prompt(); setTimeout(go, 400); }); }
						}, 900);
					};
				}());
				</script>
				<div class="nptp-brand">
					<span class="nptp-brand-mark" aria-hidden="true">▤</span>
					<div>
						<strong>پنل مدیر کسب و کار</strong>
						<em>سیم و کابل نوید</em>
					</div>
				</div>

				<div class="nptp-seg">
					<button type="button" class="nptp-seg-btn <?php echo $otp_on ? 'is-active' : ''; ?>" data-mode="otp" <?php echo $otp_on ? '' : 'disabled'; ?>>کد پیامکی</button>
					<button type="button" class="nptp-seg-btn <?php echo $otp_on ? '' : 'is-active'; ?>" data-mode="pass">رمز عبور</button>
				</div>

				<?php if ( ! $otp_on ) : ?>
					<p class="nptp-note">ورود با کد پیامکی فعلاً غیرفعال است (تنظیمات پنل پیامک در پیشخوان کامل نشده). با رمز عبور وارد شوید.</p>
				<?php endif; ?>

				<form class="nptp-form" data-mode="otp" id="nptp-otp-send" <?php echo $otp_on ? '' : 'hidden'; ?>>
					<label>شماره موبایل
						<input type="tel" name="phone" inputmode="numeric" autocomplete="tel" maxlength="11" placeholder="09123456789" required>
					</label>
					<button type="submit" class="nptp-btn">ارسال کد</button>
				</form>

				<form class="nptp-form" data-mode="otp" id="nptp-otp-code" hidden>
					<button type="button" class="nptp-back" id="nptp-otp-back">→ تغییر شماره</button>
					<label>کد تأیید
						<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
					</label>
					<button type="submit" class="nptp-btn">ورود</button>
					<button type="button" class="nptp-link" id="nptp-resend" disabled>ارسال مجدد (<span>60</span>)</button>
				</form>

				<form class="nptp-form" id="nptp-2fa-form" hidden>
					<p class="nptp-hint">کد پیامکی را وارد کنید. اگر پیامک نرسید، یکی از کدهای پشتیبان را بزنید.</p>
					<label>کد
						<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
					</label>
					<button type="submit" class="nptp-btn">ورود</button>
					<button type="button" class="nptp-link" id="nptp-2fa-back">بازگشت</button>
				</form>
				<form class="nptp-form" data-mode="pass" id="nptp-pass-form" <?php echo $otp_on ? 'hidden' : ''; ?>>
					<label>نام کاربری، ایمیل یا شماره موبایل
						<input type="text" name="identity" autocomplete="username" required>
					</label>
					<label>رمز عبور
						<input type="password" name="password" autocomplete="current-password" required>
					</label>
					<label class="nptp-check"><input type="checkbox" name="remember" value="1" checked> مرا به خاطر بسپار</label>
					<button type="submit" class="nptp-btn">ورود</button>
					<?php if ( $lost ) : ?>
						<button type="button" class="nptp-link" onclick="window.location.href=<?php echo wp_json_encode( $lost ); ?>">رمز عبورم را فراموش کرده‌ام</button>
					<?php endif; ?>
				</form>

				<p class="nptp-status" role="status" aria-live="polite"></p>

				<?php if ( $register ) : ?>
					<p class="nptp-foot">حساب ندارید؟ <a href="<?php echo esc_url( $register ); ?>">ثبت‌نام در سایت</a></p>
				<?php endif; ?>
				<p class="nptp-foot muted">این صفحه فقط «پنل مدیر کسب و کار» را باز می‌کند و ورودی به پیشخوان وردپرس نیست.</p>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------- */
	private static function render_dashboard() {
		$user = wp_get_current_user();
		$tab  = isset( $_GET['npt_tab'] ) ? sanitize_key( wp_unslash( $_GET['npt_tab'] ) ) : 'summary'; // phpcs:ignore
		if ( 'quick' === $tab ) {
			$tab = 'settings';
		}
		$sub_key = isset( $_GET['npt_sub'] ) ? sanitize_key( wp_unslash( $_GET['npt_sub'] ) ) : ''; // phpcs:ignore
		if ( ! in_array( $tab, array( 'summary', 'proformas', 'prices', 'sms', 'contacts', 'settings' ), true ) ) {
			$tab = 'summary';
		}
		$msg = isset( $_GET['npt_msg'] ) ? sanitize_key( wp_unslash( $_GET['npt_msg'] ) ) : ''; // phpcs:ignore
		?>
		<div class="nptp nptp-dash" dir="rtl">
			<header class="nptp-head">
				<button type="button" class="nptp-menu-btn" id="nptp-menu-btn" aria-expanded="false" aria-controls="nptp-side" aria-label="منوی پنل"><span aria-hidden="true">☰</span></button>
				<div class="nptp-head-id">
					<?php $panel_logo = npt_media_image_url( 'npt_panel_logo_id', '' ); ?>
					<?php if ( $panel_logo ) : ?><img class="nptp-logo" src="<?php echo esc_url( $panel_logo ); ?>" alt=""><?php endif; ?>
					<strong>پنل مدیریت</strong>
				</div>
				<div class="nptp-head-actions">
					<a class="nptp-bell" href="<?php echo esc_url( add_query_arg( array( 'npt_tab' => 'proformas', 'npt_st' => 'pending' ) ) ); ?>" aria-label="اعلان‌ها" title="پیش‌فاکتورهای در انتظار">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
						<span class="nptp-bell-dot" id="nptp-bell-dot" hidden></span>
					</a>
				</div>
			</header>

			<nav class="nptp-drop" id="nptp-side" aria-label="بخش‌های پنل">
				<div class="nptp-drop-inner">
				<?php foreach ( self::menu_items() as $item_key => $item ) : ?>
					<a class="<?php echo self::menu_is_current( $item_key ) ? 'is-current' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>"><span class="nptp-side-icon" aria-hidden="true"><?php echo self::icon( $item['svg'], $item['icon'] ); // phpcs:ignore ?></span><span class="nptp-side-label"><?php echo esc_html( $item['label'] ); ?></span></a>
				<?php endforeach; ?>
				</div>
			</nav>
			<div class="nptp-main">

			<?php if ( 'saved' === $msg ) : ?>
				<p class="nptp-note is-ok">ذخیره شد.</p>
			<?php endif; ?>

			<div class="nptp-box nptp-notif-card" id="nptp-notif-card" hidden>
				<h3>اعلان پیش‌فاکتور جدید</h3>
				<p class="nptp-hint">با فعال‌کردن اعلان، هر پیش‌فاکتور تازه از سایت روی همین دستگاه خبر داده می‌شود.</p>
				<button type="button" class="nptp-btn" id="nptp-notif-go">فعال‌سازی اعلان</button>
			</div>
			<?php if ( 'settings' === $tab ) : ?>
			<?php $two_fa = self::two_fa_on( get_current_user_id() ); ?>
			<div class="nptp-box nptp-2fa" id="nptp-2fa-box">
				<h3>ورود دومرحله‌ای</h3>
				<p class="nptp-hint"><?php echo $two_fa ? 'فعال است: بعد از رمز عبور، کد پیامکی هم خواسته می‌شود.' : 'فعال نیست. با فعال‌سازی، بعد از رمز عبور یک کد پیامکی هم خواسته می‌شود.'; ?></p>
				<button type="button" class="nptp-btn" id="nptp-2fa-start"><?php echo $two_fa ? 'غیرفعال‌کردن' : 'فعال‌سازی'; ?></button>
				<div id="nptp-2fa-step" hidden>
					<label>کد پیامکی<input type="text" id="nptp-2fa-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label>
					<button type="button" class="nptp-btn" id="nptp-2fa-go">تأیید</button>
				</div>
				<div id="nptp-2fa-codes" hidden></div>
				<p class="nptp-status" id="nptp-2fa-msg" role="status" aria-live="polite"></p>
			</div>
			<script>
			(function () {
				var box = document.getElementById('nptp-2fa-box');
				if (!box) { return; }
				var on = <?php echo $two_fa ? 'true' : 'false'; ?>;
				var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
				var start = document.getElementById('nptp-2fa-start');
				var step = document.getElementById('nptp-2fa-step');
				var code = document.getElementById('nptp-2fa-code');
				var go = document.getElementById('nptp-2fa-go');
				var out = document.getElementById('nptp-2fa-codes');
				var msg = document.getElementById('nptp-2fa-msg');
				function post(action, data) {
					var fd = new FormData();
					Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
					fd.append('action', action);
					fd.append('nonce', nonce);
					return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
				}
				function say(text, ok) { msg.textContent = text; msg.className = 'nptp-status' + (ok ? ' is-ok' : ''); }
				function errOf(res, fb) { return (res && res.data && res.data.message) || fb; }
				start.addEventListener('click', function () {
					start.disabled = true;
					post('npt_2fa_setup', {}).then(function (res) {
						start.disabled = false;
						if (res && res.success) { step.hidden = false; code.value = ''; code.focus(); say(res.data.message, true); }
						else { say(errOf(res, 'ارسال نشد.'), false); }
					}).catch(function () { start.disabled = false; say('خطا در ارتباط با سرور.', false); });
				});
				go.addEventListener('click', function () {
					post(on ? 'npt_2fa_disable' : 'npt_2fa_confirm', { code: code.value }).then(function (res) {
						if (!(res && res.success)) { say(errOf(res, 'کد درست نیست.'), false); return; }
						if (on) { window.location.reload(); return; }
						step.hidden = true;
						start.hidden = true;
						say(res.data.message, true);
						out.hidden = false;
						out.textContent = '';
						var title = document.createElement('strong');
						title.textContent = 'کدهای پشتیبان (فقط همین یک بار نمایش داده می‌شوند):';
						out.appendChild(title);
						res.data.codes.forEach(function (c) {
							var p = document.createElement('code');
							p.textContent = c;
							p.style.display = 'block';
							p.style.direction = 'ltr';
							out.appendChild(p);
						});
					}).catch(function () { say('خطا در ارتباط با سرور.', false); });
				});
			}());
			</script>
			<?php endif; ?>
			<?php if ( self::needs_bind() ) : ?>
				<div class="nptp-box nptp-bind">
					<h3>شماره موبایل برای ورود پیامکی</h3>
					<p class="nptp-hint">این حساب هنوز شماره‌ای ندارد. شماره را وارد و تأیید کنید تا ورود با کد پیامکی هم فعال شود.</p>
					<form id="nptp-bind-form">
						<label>شماره موبایل
							<input type="tel" name="phone" inputmode="numeric" autocomplete="tel" maxlength="11" placeholder="09123456789" required>
						</label>
						<div class="nptp-bind-code" hidden>
							<label>کد تأیید
								<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
							</label>
							<button type="button" class="nptp-link nptp-resend-btn" disabled>ارسال مجدد (<span>60</span>)</button>
						</div>
						<button type="submit" class="nptp-btn">ارسال کد تأیید</button>
					</form>
					<p class="nptp-status" role="status" aria-live="polite"></p>
				</div>
			<?php endif; ?>

			<?php
			if ( 'prices' === $tab ) {
				self::tab_prices();
			} elseif ( 'contacts' === $tab || ( 'sms' === $tab && 'contacts' === $sub_key ) ) {
				self::tab_contacts();
			} elseif ( 'sms' === $tab ) {
				self::tab_sms();
			} elseif ( 'settings' === $tab ) {
				self::tab_settings();
			} elseif ( 'proformas' === $tab ) {
				self::tab_proformas();
			} else {
				self::tab_summary();
			}
			?>
			</div>
			<?php $me = wp_get_current_user(); $me_name = $me->display_name ? $me->display_name : $me->user_login; ?>
			<div class="nptp-bottom-pop" id="nptp-bottom-pop" hidden>
				<div class="nptp-bottom-who"><?php echo esc_html( $me_name ); ?></div>
				<a class="nptp-bottom-out" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'npt_action', 'logout', self::panel_url() ), 'npt_panel_logout' ) ); ?>">خروج از حساب</a>
			</div>
			<nav class="nptp-bottom" aria-label="میانبرهای پنل">
				<a href="<?php echo esc_url( add_query_arg( array( 'npt_tab' => 'proformas', 'npt_sub' => 'new' ), self::panel_url() ) ); ?>"><span aria-hidden="true"><?php echo self::icon( 'plus', '＋' ); // phpcs:ignore ?></span>پیش‌فاکتور جدید</a>
				<a href="<?php echo esc_url( add_query_arg( 'npt_tab', 'contacts', self::panel_url() ) ); ?>"><span aria-hidden="true"><?php echo self::icon( 'users', '👥' ); // phpcs:ignore ?></span>مخاطبین</a>
				<button type="button" id="nptp-bottom-user" aria-label="حساب کاربری" aria-expanded="false" aria-controls="nptp-bottom-pop"><span class="nptp-avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $me_name, 0, 1 ) ); ?></span>حساب</button>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'npt_action', 'logout', self::panel_url() ), 'npt_panel_logout' ) ); ?>"><span aria-hidden="true"><?php echo self::icon( 'logout', '⎋' ); // phpcs:ignore ?></span>خروج</a>
			</nav>
			<script>(function () { var b = document.getElementById('nptp-bottom-user'), p = document.getElementById('nptp-bottom-pop'); if (!b || !p) { return; } b.addEventListener('click', function (e) { e.stopPropagation(); var open = p.hidden; p.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false'); }); document.addEventListener('click', function (e) { if (!p.contains(e.target) && !b.contains(e.target)) { p.hidden = true; b.setAttribute('aria-expanded', 'false'); } }); }());</script>
			<p class="nptp-credit">طراحی توسط <a href="https://spadts.ir" target="_blank" rel="noopener">ویرا فناوران اسپاد</a></p>
		</div>
		<?php
		self::render_dashboard_js();
		self::render_unseen_js();
		self::render_menu_js();
	}

	/* ---------------------------------------------------------------------
	 * Tab: proformas (cards, newest first)
	 * ------------------------------------------------------------------- */
	/** Panel sections: key => url, label, icon. Each is a plain address, so a link opens that section directly. */
	private static function menu_items() {
		$base = self::panel_url();
		return array(
			'summary'   => array( 'label' => 'خلاصه گزارش', 'svg' => 'chart', 'icon' => '📊', 'url' => add_query_arg( 'npt_tab', 'summary', $base ) ),
			'proformas' => array( 'label' => 'اعلان‌ها', 'svg' => 'bell', 'icon' => '🔔', 'url' => add_query_arg( 'npt_tab', 'proformas', $base ) ),
			'new'       => array( 'label' => 'پیش‌فاکتور جدید', 'svg' => 'plus', 'icon' => '＋', 'url' => add_query_arg( array( 'npt_tab' => 'proformas', 'npt_sub' => 'new' ), $base ) ),
			'prices'    => array( 'label' => 'قیمت‌ها', 'svg' => 'tag', 'icon' => '🏷', 'url' => add_query_arg( 'npt_tab', 'prices', $base ) ),
			'sms'       => array( 'label' => 'ارسال پیامک', 'svg' => 'send', 'icon' => '✉', 'url' => add_query_arg( 'npt_tab', 'sms', $base ) ),
			'report'    => array( 'label' => 'گزارش پیامک', 'svg' => 'report', 'icon' => '📋', 'url' => add_query_arg( array( 'npt_tab' => 'sms', 'npt_sub' => 'report' ), $base ) ),
			'contacts'  => array( 'label' => 'مخاطبین', 'svg' => 'users', 'icon' => '👥', 'url' => add_query_arg( 'npt_tab', 'contacts', $base ) ),
			'settings'  => array( 'label' => 'تنظیمات', 'svg' => 'gear', 'icon' => '⚙', 'url' => add_query_arg( 'npt_tab', 'settings', $base ) ),
		);
	}

	/** Theme line icon (same set as the site and the mobile bar), or the old glyph. */
	private static function icon( $name, $fallback ) {
		return function_exists( 'navid_icon' ) ? navid_icon( $name ) : esc_html( $fallback );
	}

	/** Whether a menu entry matches the current address. */
	private static function menu_is_current( $key ) {
		$tab = isset( $_GET['npt_tab'] ) ? sanitize_key( wp_unslash( $_GET['npt_tab'] ) ) : 'summary'; // phpcs:ignore
		$sub = isset( $_GET['npt_sub'] ) ? sanitize_key( wp_unslash( $_GET['npt_sub'] ) ) : ''; // phpcs:ignore
		if ( 'quick' === $tab ) {
			$tab = 'settings';
		}
		if ( 'sms' === $tab && 'contacts' === $sub ) {
			$tab = 'contacts';
		}
		switch ( $key ) {
			case 'new':
				return 'proformas' === $tab && 'new' === $sub;
			case 'proformas':
				return 'proformas' === $tab && 'new' !== $sub;
			case 'sms':
				return 'sms' === $tab && 'report' !== $sub;
			case 'report':
				return 'sms' === $tab && 'report' === $sub;
		}
		return $key === $tab;
	}

	/** Summary report: the only thing on the first screen. */
	private static function tab_summary() {
		global $wpdb;
		$table = NPT_Proforma::table_proformas();
		$first = current_time( 'Y-m-01 00:00:00' );
		$month = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE created_at >= %s", $first ) ); // phpcs:ignore
		$sum   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM $table WHERE created_at >= %s", $first ) ); // phpcs:ignore
		$counts = array();
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . $table . ' GROUP BY status' ) as $count_row ) { // phpcs:ignore
			$counts[ $count_row->status ] = (int) $count_row->n;
		}
		$pending = isset( $counts['pending'] ) ? $counts['pending'] : 0;
		$called  = isset( $counts['called'] ) ? $counts['called'] : 0;
		$visits_today = (int) get_option( 'npt_visits_' . ( new DateTime( 'now', wp_timezone() ) )->format( 'Ymd' ), 0 );
		$visits_total = (int) get_option( 'npt_visits_total', 0 );
		?>
		<div class="nptp-stats">
			<div class="nptp-stat"><span>بازدید امروز سایت</span><strong><?php echo esc_html( npt_to_persian_digits( (string) $visits_today ) ); ?></strong></div>
			<div class="nptp-stat"><span>کل بازدیدهای سایت</span><strong><?php echo esc_html( npt_to_persian_digits( (string) $visits_total ) ); ?></strong></div>
			<div class="nptp-stat"><span>پیش‌فاکتور این ماه</span><strong><?php echo esc_html( npt_to_persian_digits( (string) $month ) ); ?></strong></div>
			<div class="nptp-stat"><span>مجموع این ماه</span><strong><?php echo esc_html( npt_format_price( $sum, true ) ); ?></strong></div>
			<div class="nptp-stat"><span>در انتظار تماس</span><strong><?php echo esc_html( npt_to_persian_digits( (string) $pending ) ); ?></strong></div>
			<div class="nptp-stat"><span>تماس گرفته‌شده</span><strong><?php echo esc_html( npt_to_persian_digits( (string) $called ) ); ?></strong></div>
		</div>
		<p class="nptp-plusbar"><a class="nptp-plus-btn" href="<?php echo esc_url( add_query_arg( array( 'npt_tab' => 'proformas', 'npt_st' => 'pending' ), self::panel_url() ) ); ?>">پیش‌فاکتورهای در انتظار</a></p>
		<?php
	}

	private static function tab_proformas() {
		$sub  = isset( $_GET['npt_sub'] ) ? sanitize_key( wp_unslash( $_GET['npt_sub'] ) ) : ''; // phpcs:ignore
		$base = self::panel_url();
		?>
		<?php
		if ( 'new' === $sub ) {
			NPT_Proforma::render_panel_builder();
			return;
		}
		global $wpdb;
		$table = NPT_Proforma::table_proformas();
		$st = isset( $_GET['npt_st'] ) ? sanitize_key( wp_unslash( $_GET['npt_st'] ) ) : ''; // phpcs:ignore
		if ( ! isset( NPT_Proforma::STATUSES[ $st ] ) ) {
			$st = '';
		}
		$where = '' !== $st ? $wpdb->prepare( ' WHERE p.status = %s', $st ) : '';
		// Pending requests come in the order they arrived; everything else newest first.
		$order  = 'pending' === $st ? 'ASC' : 'DESC';
		$rows   = $wpdb->get_results( 'SELECT p.*, c.name AS c_name, c.phone AS c_phone FROM ' . $table . ' p LEFT JOIN ' . NPT_Proforma::table_customers() . ' c ON c.id = p.customer_id' . $where . ' ORDER BY p.id ' . $order . ' LIMIT 100' ); // phpcs:ignore
		$counts = array();
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . $table . ' GROUP BY status' ) as $count_row ) { // phpcs:ignore
			$counts[ $count_row->status ] = (int) $count_row->n;
		}
		if ( ! empty( $rows ) ) {
			$top = 0;
			foreach ( $rows as $row ) {
				$top = max( $top, (int) $row->id );
			}
			if ( $top > self::seen_id() ) {
				update_user_meta( get_current_user_id(), self::SEEN_META, $top );
			}
		}
		?>
		<nav class="nptp-subtabs nptp-status-chips">
			<a class="<?php echo '' === $st ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'npt_tab', 'proformas', $base ) ); ?>">همه<span class="nptp-chip-n"><?php echo esc_html( npt_to_persian_digits( (string) array_sum( $counts ) ) ); ?></span></a>
			<?php foreach ( NPT_Proforma::STATUSES as $status_key => $status_label ) : ?>
				<a class="<?php echo $status_key === $st ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'npt_tab' => 'proformas', 'npt_st' => $status_key ), $base ) ); ?>"><?php echo esc_html( $status_label ); ?><span class="nptp-chip-n"><?php echo esc_html( npt_to_persian_digits( (string) ( isset( $counts[ $status_key ] ) ? $counts[ $status_key ] : 0 ) ) ); ?></span></a>
			<?php endforeach; ?>
		</nav>
		<?php if ( empty( $rows ) ) : ?>
			<div class="nptp-empty">هنوز پیش‌فاکتوری ثبت نشده است.<br>وقتی مشتری فرم سایت را پر کند، همین‌جا نمایش داده می‌شود.</div>
		<?php else : ?>
			<?php foreach ( $rows as $r ) :
				$site = isset( $r->source ) && 'site' === $r->source;
				$share = NPT_Proforma::short_url( NPT_Proforma::ensure_short( (int) $r->id ) );
					$view  = add_query_arg( 'back', add_query_arg( 'npt_tab', 'proformas', self::panel_url() ), $share );
				?>
				<article class="nptp-pf">
					<div class="nptp-pf-top">
						<span class="nptp-pf-num">#<?php echo esc_html( npt_to_persian_digits( (string) $r->number ) ); ?></span>
						<span class="nptp-badge <?php echo $site ? 'is-site' : ''; ?>"><?php echo $site ? 'از سایت' : 'ساخته‌شده در پنل'; ?></span>
					</div>
					<div class="nptp-pf-name"><?php echo esc_html( $r->c_name ? $r->c_name : '—' ); ?></div>
					<div class="nptp-pf-meta"><?php echo npt_date_html( $r->created_at ); ?><?php if ( ! $site ) : $creator = $r->created_by ? get_userdata( (int) $r->created_by ) : false; ?> · ثبت‌کننده: <?php echo esc_html( $creator ? ( $creator->display_name ? $creator->display_name : $creator->user_login ) : 'نامشخص' ); ?><?php endif; ?></div>
					<div class="nptp-pf-total"><?php echo esc_html( npt_format_price( $r->total, true ) ); ?></div>
					<?php $cur_status = isset( $r->status ) && isset( NPT_Proforma::STATUSES[ $r->status ] ) ? $r->status : 'pending'; ?>
					<div class="nptp-pf-states" data-id="<?php echo (int) $r->id; ?>" role="group" aria-label="وضعیت">
							<span class="nptp-pf-cap">وضعیت</span>
							<?php foreach ( NPT_Proforma::STATUSES as $status_key => $status_label ) : $on = $status_key === $cur_status; ?>
								<button type="button" class="nptp-st-btn nptp-st-<?php echo esc_attr( $status_key ); ?><?php echo $on ? ' is-on' : ''; ?>" data-st="<?php echo esc_attr( $status_key ); ?>" aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"><?php echo esc_html( $status_label ); ?></button>
							<?php endforeach; ?>
						</div>
					<div class="nptp-pf-acts">
						<a class="nptp-act is-main" href="<?php echo esc_url( $view ); ?>">مشاهده / چاپ</a>
						<button type="button" class="nptp-act nptp-share" data-url="<?php echo esc_url( $share ); ?>" data-title="<?php echo esc_attr( 'پیش‌فاکتور #' . npt_to_persian_digits( (string) $r->number ) ); ?>">اشتراک‌گذاری</button>
						<button type="button" class="nptp-act nptp-pf-del" data-id="<?php echo (int) $r->id; ?>">حذف</button>
						<?php if ( $r->c_phone ) : ?>
							<button type="button" class="nptp-act nptp-pf-sms" data-id="<?php echo (int) $r->id; ?>">پیامک لینک</button>
							<a class="nptp-act nptp-call" data-id="<?php echo (int) $r->id; ?>" href="tel:<?php echo esc_attr( $r->c_phone ); ?>">تماس</a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		<?php endif; ?>
		<script>
		(function () {
			Array.prototype.forEach.call(document.querySelectorAll('.nptp-share'), function (b) {
				b.addEventListener('click', function () {
					var url = b.getAttribute('data-url'), title = b.getAttribute('data-title');
					if (navigator.share) { navigator.share({ title: title, url: url }).catch(function () {}); return; }
					if (navigator.clipboard) {
						navigator.clipboard.writeText(url).then(function () {
							var old = b.textContent; b.textContent = 'لینک کپی شد ✓';
							setTimeout(function () { b.textContent = old; }, 1800);
						});
						return;
					}
					window.prompt('لینک پیش‌فاکتور', url);
				});
			});
		}());
		</script>
		<script>
		(function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			function save(id, status, onlyPending) {
				var fd = new FormData();
				fd.append('action', 'npt_pf_status');
				fd.append('nonce', nonce);
				fd.append('id', id);
				fd.append('status', status);
				if (onlyPending) { fd.append('only_pending', '1'); }
				return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
			}
			Array.prototype.forEach.call(document.querySelectorAll('.nptp-pf-states'), function (box) {
					var id = box.getAttribute('data-id');
					Array.prototype.forEach.call(box.querySelectorAll('.nptp-st-btn'), function (btn) {
						btn.addEventListener('click', function () {
							if (btn.classList.contains('is-on') || box.getAttribute('data-busy') === '1') { return; }
							var prev = box.querySelector('.nptp-st-btn.is-on');
							var all = box.querySelectorAll('.nptp-st-btn');
							var setOn = function (on) {
								Array.prototype.forEach.call(all, function (x) {
									x.classList.toggle('is-on', x === on);
									x.setAttribute('aria-pressed', x === on ? 'true' : 'false');
								});
							};
							box.setAttribute('data-busy', '1');
							setOn(btn);
							var timeout = new Promise(function (_, reject) { setTimeout(function () { reject(new Error('timeout')); }, 15000); });
							Promise.race([save(id, btn.getAttribute('data-st'), false), timeout]).then(function (res) {
								if (!res || !res.success) { throw new Error('failed'); }
							}).catch(function () {
								setOn(prev);
								window.alert('وضعیت ذخیره نشد. دوباره تلاش کنید.');
							}).then(function () {
								box.removeAttribute('data-busy');
							});
						});
					});
				});
Array.prototype.forEach.call(document.querySelectorAll('.nptp-call'), function (a) {
				a.addEventListener('click', function () { save(a.getAttribute('data-id'), 'called', true); });
				});
				Array.prototype.forEach.call(document.querySelectorAll('.nptp-pf-del'), function (d) {
					d.addEventListener('click', function () {
						if (!window.confirm('این پیش‌فاکتور حذف شود؟ بعد از حذف برگشتی ندارد.')) { return; }
						var fd = new FormData();
						fd.append('action', 'npt_pf_delete');
						fd.append('nonce', nonce);
						fd.append('id', d.getAttribute('data-id'));
						fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
							if (res && res.success) {
								var card = d.closest('article');
								if (card) { card.parentNode.removeChild(card); }
							} else {
								window.alert((res && res.data && res.data.message) || 'حذف نشد.');
							}
						}).catch(function () { window.alert('خطا در ارتباط با سرور.'); });
					});
				});
				Array.prototype.forEach.call(document.querySelectorAll('.nptp-pf-sms'), function (b) {
					b.addEventListener('click', function () {
						var old = b.textContent;
						b.disabled = true;
						var fd = new FormData();
						fd.append('action', 'npt_pf_sms');
						fd.append('nonce', nonce);
						fd.append('id', b.getAttribute('data-id'));
						fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
							b.textContent = res && res.success ? res.data.message : ((res && res.data && res.data.message) || 'ارسال نشد');
						}).catch(function () {
							b.textContent = 'خطا در ارتباط';
						}).then(function () {
							b.disabled = false;
							setTimeout(function () { b.textContent = old; }, 3000);
						});
					});
			});
		}());
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: prices (bulk percentage card + the inline-edit table)
	 * ------------------------------------------------------------------- */
	private static function tab_prices() {
		$backup = get_option( 'npt_price_backup' );
		$has    = is_array( $backup ) && ! empty( $backup['prices'] );
		?>
		<nav class="nptp-subtabs nptp-price-tabs" aria-label="بخش‌های قیمت">
			<button type="button" class="is-active" data-pt="all">همه‌ی محصولات</button>
			<button type="button" data-pt="item">یک کالا</button>
		</nav>
		<div class="nptp-pt-panel" data-pt-panel="all">
		<div class="nptp-box nptp-pct" id="nptp-pct">
			<h3>تغییر درصدی همه‌ی قیمت‌ها</h3>
			<p class="nptp-hint">درصد را با ＋ و − تغییر دهید. حداکثر ۳۰ درصد کاهش یا افزایش.</p>
			<div class="nptp-stepper">
				<button type="button" class="nptp-step" id="nptp-pct-minus" aria-label="کاهش ۱ درصد">−</button>
				<input type="number" id="nptp-pct-input" step="1" min="-30" max="30" value="0" dir="ltr" inputmode="numeric" aria-label="درصد تغییر">
				<button type="button" class="nptp-step" id="nptp-pct-plus" aria-label="افزایش ۱ درصد">+</button>
			</div>
			<div class="nptp-pct-preview" id="nptp-pct-preview">یک درصد انتخاب کنید تا نمونه‌ی قیمت‌ها را ببینید.</div>
			<div class="nptp-pct-actions">
				<button type="button" class="nptp-btn" id="nptp-pct-apply" disabled>اعمال روی همه‌ی محصولات</button>
				<?php if ( $has ) : ?>
				<button type="button" class="nptp-btn nptp-btn-ghost" id="nptp-pct-undo">برگرداندن آخرین تغییر (<?php echo esc_html( ( $backup['pct'] > 0 ? '+' : '' ) . npt_to_persian_digits( rtrim( rtrim( number_format( (float) $backup['pct'], 1 ), '0' ), '.' ) ) . '٪' ); ?>)</button>
				<?php endif; ?>
			</div>
			<p class="nptp-status" id="nptp-pct-status" role="status" aria-live="polite"></p>
		</div>
		</div>

		<div class="nptp-pt-panel" data-pt-panel="item" hidden>
		<div class="nptp-box nptp-pct" id="nptp-item">
			<h3>تغییر درصدی یک کالا</h3>
			<label class="nptp-pct-field">جستجوی کالا
				<input type="search" id="nptp-item-q" autocomplete="off" placeholder="حداقل دو حرف از نام کالا">
			</label>
			<div id="nptp-item-results" class="nptp-item-results"></div>
			<p class="nptp-hint" id="nptp-item-chosen">کالایی انتخاب نشده است.</p>
			<label class="nptp-pct-field">درصد (منفی = کاهش، مثبت = افزایش)
				<input type="number" id="nptp-item-pct" step="0.1" dir="ltr" inputmode="decimal" placeholder="مثلاً -5 یا 8">
			</label>
			<div class="nptp-pct-actions">
				<button type="button" class="nptp-btn" id="nptp-item-apply" disabled>اعمال روی این کالا</button>
			</div>
			<p class="nptp-status" id="nptp-item-status" role="status" aria-live="polite"></p>
		</div>
		</div>
		<script>
		(function () {
			var nav = document.querySelector('.nptp-price-tabs');
			if (nav) {
				var tabBtns = nav.querySelectorAll('button');
				Array.prototype.forEach.call(tabBtns, function (b) {
					b.addEventListener('click', function () {
						var key = b.getAttribute('data-pt');
						Array.prototype.forEach.call(tabBtns, function (x) { x.classList.toggle('is-active', x === b); });
						Array.prototype.forEach.call(document.querySelectorAll('[data-pt-panel]'), function (p) {
							p.hidden = p.getAttribute('data-pt-panel') !== key;
						});
					});
				});
			}
		}());
		</script>
		<script>
		(function () {
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var searchNonce = <?php echo wp_json_encode( wp_create_nonce( NPT_Proforma::NONCE ) ); ?>;
			var q = document.getElementById('nptp-item-q'), list = document.getElementById('nptp-item-results');
			var chosenEl = document.getElementById('nptp-item-chosen'), pct = document.getElementById('nptp-item-pct');
			var apply = document.getElementById('nptp-item-apply'), status = document.getElementById('nptp-item-status');
			var chosen = null, timer = null;
			function post(action, data, token) {
				var fd = new FormData();
				fd.append('action', action); fd.append('nonce', token || nonce);
				Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
				return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
			}
			function refresh() { apply.disabled = !(chosen && pct.value !== '' && parseFloat(pct.value) !== 0); }
			q.addEventListener('input', function () {
				clearTimeout(timer);
				timer = setTimeout(function () {
					list.innerHTML = '';
					if (q.value.trim().length < 2) { return; }
					post('npt_proforma_search', { q: q.value.trim() }, searchNonce).then(function (res) {
						if (!res || !res.success) { return; }
						res.data.results.forEach(function (p) {
							var b = document.createElement('button');
							b.type = 'button'; b.className = 'nptp-item-pick';
							b.textContent = p.name;
							b.addEventListener('click', function () {
								chosen = p; chosenEl.textContent = 'کالای انتخابی: ' + p.name;
								list.innerHTML = ''; refresh();
							});
							list.appendChild(b);
						});
					});
				}, 300);
			});
			pct.addEventListener('input', refresh);
			apply.addEventListener('click', function () {
				if (!chosen) { return; }
				apply.disabled = true; status.textContent = 'در حال اعمال…'; status.style.color = '';
				post('npt_pct_item', { product_id: chosen.id, pct: pct.value }).then(function (res) {
					status.textContent = (res && res.success) ? 'قیمت «' + res.data.name + '» تغییر کرد.' : ((res && res.data && res.data.message) || 'انجام نشد.');
					status.style.color = (res && res.success) ? '#14733b' : '#9a1b1b';
					if (res && res.success) { setTimeout(function () { window.location.reload(); }, 900); }
					else { refresh(); }
				}).catch(function () { status.textContent = 'ارتباط با سرور برقرار نشد.'; refresh(); });
			});
		}());
		</script>

		<div class="nptp-prices">
			<?php echo do_shortcode( '[navid_price_table]' ); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: SMS (send / contacts / report)
	 * ------------------------------------------------------------------- */
	private static function tab_sms() {
		if ( ! class_exists( 'NPT_SMS' ) ) {
			echo '<p>بخش پیامک در دسترس نیست.</p>';
			return;
		}
		$sub  = isset( $_GET['npt_sub'] ) ? sanitize_key( wp_unslash( $_GET['npt_sub'] ) ) : 'send'; // phpcs:ignore
		$subs = array(
			'send'   => 'ارسال',
			'report' => 'گزارش',
		);
		if ( ! isset( $subs[ $sub ] ) ) {
			$sub = 'send';
		}
		?>
		<nav class="nptp-sub">
			<?php foreach ( $subs as $key => $label ) : ?>
				<a class="<?php echo $key === $sub ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'npt_tab' => 'sms', 'npt_sub' => $key ), self::panel_url() ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'report' === $sub ) {
			NPT_SMS::render_report();
		} else {
			NPT_SMS::render_send();
		}
	}

	/** Contacts: its own section, like the phone's contact list. */
	private static function tab_contacts() {
		if ( ! class_exists( 'NPT_SMS' ) ) {
			echo '<p>بخش مخاطبین در دسترس نیست.</p>';
			return;
		}
		NPT_SMS::render_contacts_panel( add_query_arg( 'npt_tab', 'contacts', self::panel_url() ) );
	}

	/* ---------------------------------------------------------------------
	 * Tab: settings (notifications first, shop contact details below)
	 * ------------------------------------------------------------------- */
	private static function tab_settings() {
		$s       = NPT_Bar::settings();
		$numbers = implode( "\n", NPT_SMS::notify_numbers() );
		$sms_ok  = NPT_SMS::otp_ready();
		?>
		<div class="nptp-box" id="nptp-notify">
			<h3>اعلان پیش‌فاکتور جدید</h3>
			<p class="nptp-hint">وقتی مشتری از سایت پیش‌فاکتور ثبت کند، به همین دستگاه اعلان می‌آید و (اگر بخواهید) پیامک هم می‌گیرید.</p>

			<div class="nptp-device">
				<div id="nptp-push-state" class="nptp-push-state">در حال بررسی…</div>
				<div class="nptp-device-actions">
					<button type="button" class="nptp-btn" id="nptp-push-on" hidden>فعال‌سازی اعلان روی این گوشی</button>
					<button type="button" class="nptp-btn nptp-btn-ghost" id="nptp-push-test" hidden>ارسال اعلان آزمایشی</button>
					<button type="button" class="nptp-btn nptp-btn-ghost" id="nptp-push-off" hidden>خاموش کردن</button>
					<button type="button" class="nptp-btn nptp-btn-ghost" id="nptp-install">نصب برنامه روی گوشی</button>
				</div>
				<p class="nptp-hint" id="nptp-install-hint" hidden>اگر دکمه‌ی «نصب برنامه» نیامد: در منوی مرورگر (⋮ یا ⋯) گزینه‌ی «افزودن به صفحه‌ی اصلی» یا «نصب برنامه» را بزنید. دکمه فقط وقتی ظاهر می‌شود که مرورگر آماده‌ی نصب باشد (نشانی https و چند بار بازدید از همین صفحه).</p>
				<p class="nptp-hint" id="nptp-ios-hint" hidden>روی آیفون: در Safari دکمه‌ی اشتراک‌گذاری (□↑) را بزنید، «Add to Home Screen» را انتخاب کنید و برنامه را از روی صفحه‌ی اصلی باز کنید؛ سپس اعلان را فعال کنید.</p>
				<p class="nptp-status" id="nptp-push-status" role="status" aria-live="polite"></p>
			</div>

			<form method="post" action="<?php echo esc_url( self::panel_url() ); ?>" class="nptp-quick">
				<?php wp_nonce_field( self::NONCE . '_notify' ); ?>
				<input type="hidden" name="npt_panel_form" value="notify">
				<label class="nptp-check"><input type="checkbox" name="npt_notify_push" value="1" <?php checked( NPT_Push::notify_push_enabled() ); ?>> اعلان در برنامه/مرورگر</label>
				<label class="nptp-check"><input type="checkbox" name="npt_pf_sms_site" value="1" <?php checked( (bool) get_option( 'npt_pf_sms_site', 0 ) ); ?>> بعد از ثبت از سایت، لینک پیش‌فاکتور را پیامک کن</label>
				<label class="nptp-check"><input type="checkbox" name="npt_notify_sms" value="1" <?php checked( (bool) get_option( 'npt_notify_sms', 0 ) ); ?>> پیامک هم بفرست</label>
				<label>شماره‌ی دریافت پیامک (تا ۵ شماره، هر خط یکی)
					<textarea name="npt_notify_mobiles" rows="2" dir="ltr" placeholder="09121234567"><?php echo esc_textarea( $numbers ); ?></textarea>
				</label>
				<?php if ( ! $sms_ok ) : ?>
					<p class="nptp-hint">برای پیامک، نام کاربری/رمز/خط ملی‌پیامک باید در پیشخوان وردپرس پر شود.</p>
				<?php endif; ?>
				<button type="submit" class="nptp-btn">ذخیره</button>
			</form>
		</div>

		<details class="nptp-box nptp-details">
			<summary>ارتباط با مشتری (تماس، واتس‌اپ، تلگرام)</summary>
			<form class="nptp-quick" method="post" action="<?php echo esc_url( self::panel_url() ); ?>">
				<?php wp_nonce_field( self::NONCE . '_quick' ); ?>
				<input type="hidden" name="npt_panel_form" value="quick">
				<label class="nptp-check"><input type="hidden" name="npt_bar_settings[enable_call]" value="0"><input type="checkbox" name="npt_bar_settings[enable_call]" value="1" <?php checked( $s['enable_call'], 1 ); ?>> تماس تلفنی</label>
				<label>شماره تماس<input type="tel" name="npt_bar_settings[call_number]" value="<?php echo esc_attr( $s['call_number'] ); ?>" dir="ltr"></label>
				<label class="nptp-check"><input type="hidden" name="npt_bar_settings[enable_whatsapp]" value="0"><input type="checkbox" name="npt_bar_settings[enable_whatsapp]" value="1" <?php checked( $s['enable_whatsapp'], 1 ); ?>> واتس‌اپ</label>
				<label>شماره واتس‌اپ (با کد کشور)<input type="tel" name="npt_bar_settings[whatsapp_number]" value="<?php echo esc_attr( $s['whatsapp_number'] ); ?>" dir="ltr"></label>
				<label class="nptp-check"><input type="hidden" name="npt_bar_settings[enable_telegram]" value="0"><input type="checkbox" name="npt_bar_settings[enable_telegram]" value="1" <?php checked( $s['enable_telegram'], 1 ); ?>> تلگرام</label>
				<label>نشانی تلگرام<input type="url" name="npt_bar_settings[telegram_url]" value="<?php echo esc_attr( $s['telegram_url'] ); ?>" dir="ltr"></label>
				<button type="submit" class="nptp-btn">ذخیره</button>
			</form>
		</details>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Bulk percentage + push: AJAX (panel session, nonce, edit capability)
	 * ------------------------------------------------------------------- */
	private static function price_guard() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'نشست منقضی شده است. صفحه را تازه کنید.' ), 403 );
		}
		if ( ! self::user_can_panel() || ! npt_current_user_can_edit() ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_error( array( 'message' => 'ووکامرس فعال نیست.' ), 503 );
		}
	}

	public static function ajax_pct_preview() {
		self::price_guard();
		$pct = isset( $_POST['pct'] ) ? (float) wp_unslash( $_POST['pct'] ) : 0.0; // phpcs:ignore
		if ( abs( $pct ) > self::PCT_LIMIT ) {
			wp_send_json_error( array( 'message' => 'درصد باید بین ' . npt_to_persian_digits( (string) -self::PCT_LIMIT ) . ' و ' . npt_to_persian_digits( (string) self::PCT_LIMIT ) . ' باشد.' ) );
		}
		$res = npt_price_percent_preview( $pct );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	/** Contact groups with their sizes, for the popup after a price update. */
	public static function ajax_pct_groups() {
		self::price_guard();
		global $wpdb;
		$rows   = $wpdb->get_results( 'SELECT grp, COUNT(*) AS n FROM ' . NPT_SMS::table_contacts() . ' GROUP BY grp ORDER BY grp ASC' ); // phpcs:ignore
		$groups = array();
		foreach ( (array) $rows as $row ) {
			$groups[] = array(
				'name'  => '' === $row->grp ? 'بدون گروه' : $row->grp,
				'value' => (string) $row->grp,
				'count' => (int) $row->n,
			);
		}
		wp_send_json_success( array( 'groups' => $groups ) );
	}

	/** Sends the chosen text to the contacts of the chosen groups (at most 200 numbers). */
	public static function ajax_pct_notify() {
		self::price_guard();
		$groups = isset( $_POST['groups'] ) ? json_decode( wp_unslash( $_POST['groups'] ), true ) : array(); // phpcs:ignore
		$text   = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : ''; // phpcs:ignore
		if ( ! is_array( $groups ) || empty( $groups ) ) {
			wp_send_json_error( array( 'message' => 'هیچ گروهی انتخاب نشده است.' ), 400 );
		}
		if ( '' === trim( $text ) || mb_strlen( $text ) > 300 ) {
			wp_send_json_error( array( 'message' => 'متن پیامک خالی است یا بیش از ۳۰۰ حرف است.' ), 400 );
		}
		$values = array_map( 'sanitize_text_field', array_map( 'strval', $groups ) );
		global $wpdb;
		$table        = NPT_SMS::table_contacts();
		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		$phones       = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT phone FROM $table WHERE grp IN ($placeholders) AND phone <> '' LIMIT 200", $values ) ); // phpcs:ignore
		if ( empty( $phones ) ) {
			wp_send_json_error( array( 'message' => 'مخاطبی در این گروه‌ها نیست.' ), 400 );
		}
		$sent = NPT_SMS::send_text_to( array_map( 'strval', $phones ), $text );
		if ( is_wp_error( $sent ) ) {
			wp_send_json_error( array( 'message' => $sent->get_error_message() ), 502 );
		}
		NPT_Log::activity( 'ارسال پیامک بروزرسانی قیمت به ' . $sent . ' مخاطب' );
		wp_send_json_success( array( 'message' => 'پیامک برای ' . npt_to_persian_digits( (string) $sent ) . ' مخاطب فرستاده شد.' ) );
	}

	public static function ajax_pct_apply() {
		self::price_guard();
		$pct = isset( $_POST['pct'] ) ? (float) wp_unslash( $_POST['pct'] ) : 0.0; // phpcs:ignore
		if ( abs( $pct ) > self::PCT_LIMIT ) {
			wp_send_json_error( array( 'message' => 'درصد باید بین ' . npt_to_persian_digits( (string) -self::PCT_LIMIT ) . ' و ' . npt_to_persian_digits( (string) self::PCT_LIMIT ) . ' باشد.' ) );
		}
		$res = npt_price_percent_apply( $pct );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		NPT_Log::activity( 'تغییر درصدی همه‌ی قیمت‌ها: ' . $pct . '٪' );
		wp_send_json_success( array( 'count' => (int) $res ) );
	}

	public static function ajax_pct_item() {
		self::price_guard();
		$id  = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$pct = isset( $_POST['pct'] ) ? (float) wp_unslash( $_POST['pct'] ) : 0.0;
		$res = npt_price_percent_item( $id, $pct );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	public static function ajax_pct_undo() {
		self::price_guard();
		$res = npt_price_percent_undo();
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		NPT_Log::activity( 'برگرداندن آخرین تغییر درصدی قیمت‌ها' );
		$skipped = (int) get_transient( 'npt_undo_skipped' );
		delete_transient( 'npt_undo_skipped' );
		wp_send_json_success( array( 'count' => (int) $res, 'skipped' => $skipped ) );
	}

	/* ---------------------------------------------------------------------
	 * Dashboard behaviour: percentage card, push enrolment, PWA install
	 * ------------------------------------------------------------------- */
	private static function render_dashboard_js() {
		$cfg = array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( self::NONCE ),
			'sw'     => NPT_Push::sw_url(),
			'scope'  => (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
			'key'    => NPT_Push::available() ? NPT_Push::public_key() : '',
			'devices' => NPT_Push::device_count(),
		);
		?>
		<script>
		(function () {
			var root = document.querySelector('.nptp-dash');
			if (! root) { return; }
			var cfg = <?php echo wp_json_encode( $cfg ); ?>;
			var fa = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
			function post(action, data) {
				var fd = new FormData();
				Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
				fd.append('action', action);
				fd.append('nonce', cfg.nonce);
				return fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
			}
			function say(el, text, ok) {
				if (! el) { return; }
				el.textContent = text || '';
				el.className = 'nptp-status' + (ok ? ' is-ok' : '');
			}
			function errMsg(res, fb) { return (res && res.data && res.data.message) || fb; }

			/* ---- bulk percentage ---- */
			function nptNotifyAsk(done) {
				post('npt_pct_groups', {}).then(function (res) {
					var groups = res && res.success ? res.data.groups : [];
					var wrap = document.createElement('div');
					wrap.className = 'nptp-modal';
					var card = document.createElement('div');
					card.className = 'nptp-modal-card';
					card.setAttribute('role', 'dialog');
					var h = document.createElement('h3');
					h.textContent = 'پیامک به مخاطبین؟';
					var p = document.createElement('p');
					p.className = 'nptp-hint';
					p.textContent = 'قیمت‌ها بروز شد. گروه‌هایی را که باید خبر بگیرند انتخاب کنید، یا لغو کنید تا پیامکی نرود.';
					card.appendChild(h);
					card.appendChild(p);
					var boxes = [];
					if (!groups.length) {
						var none = document.createElement('p');
						none.className = 'nptp-hint';
						none.textContent = 'هنوز مخاطبی ثبت نشده است.';
						card.appendChild(none);
					}
					groups.forEach(function (g) {
						var label = document.createElement('label');
						label.className = 'nptp-check';
						var cb = document.createElement('input');
						cb.type = 'checkbox';
						cb.value = g.value;
						boxes.push(cb);
						label.appendChild(cb);
						label.appendChild(document.createTextNode(' ' + g.name + ' (' + g.count + ' نفر)'));
						card.appendChild(label);
					});
					var ta = document.createElement('textarea');
					ta.rows = 3;
					ta.maxLength = 300;
					ta.value = 'قیمت‌های سیم و کابل نوید بروز شد.';
					var lab = document.createElement('label');
					lab.className = 'nptp-pct-field';
					lab.textContent = 'متن پیامک (حداکثر ۳۰۰ حرف)';
					lab.appendChild(ta);
					card.appendChild(lab);
					var status = document.createElement('p');
					status.className = 'nptp-status';
					card.appendChild(status);
					var row = document.createElement('div');
					row.className = 'nptp-pct-actions';
					var send = document.createElement('button');
					send.type = 'button';
					send.className = 'nptp-btn';
					send.textContent = 'ارسال پیامک';
					send.disabled = !groups.length;
					var cancel = document.createElement('button');
					cancel.type = 'button';
					cancel.className = 'nptp-btn nptp-btn-ghost';
					cancel.textContent = 'لغو (پیامک نرود)';
					row.appendChild(send);
					row.appendChild(cancel);
					card.appendChild(row);
					wrap.appendChild(card);
					document.body.appendChild(wrap);
					var close = function () { document.body.removeChild(wrap); done(); };
					cancel.addEventListener('click', close);
					send.addEventListener('click', function () {
						var chosen = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
						if (!chosen.length) { say(status, 'حداقل یک گروه را انتخاب کنید.'); return; }
						send.disabled = true;
						cancel.disabled = true;
						say(status, 'در حال ارسال…');
						post('npt_pct_notify', { groups: JSON.stringify(chosen), text: ta.value }).then(function (r) {
							if (r && r.success) { say(status, r.data.message, true); setTimeout(close, 1200); }
							else { say(status, errMsg(r, 'ارسال نشد.')); send.disabled = false; cancel.disabled = false; }
						}).catch(function () { say(status, 'ارتباط با سرور قطع شد.'); send.disabled = false; cancel.disabled = false; });
					});
				}).catch(done);
			}
			var pctInput = document.getElementById('nptp-pct-input');
			if (pctInput) {
				var chips = document.querySelectorAll('#nptp-pct-chips .nptp-chip');
				var minusBtn = document.getElementById('nptp-pct-minus'), plusBtn = document.getElementById('nptp-pct-plus');
				var stepBy = function (d) {
					var v = parseFloat(pctInput.value);
					if (isNaN(v)) { v = 0; }
					pctInput.value = Math.max(-30, Math.min(30, Math.round(v) + d));
					pctInput.dispatchEvent(new Event('input'));
				};
				if (minusBtn) { minusBtn.addEventListener('click', function () { stepBy(-1); }); }
				if (plusBtn) { plusBtn.addEventListener('click', function () { stepBy(1); }); }
				var preview = document.getElementById('nptp-pct-preview');
				var applyBtn = document.getElementById('nptp-pct-apply');
				var undoBtn = document.getElementById('nptp-pct-undo');
				var st = document.getElementById('nptp-pct-status');
				var timer = null, total = 0;

				function pct() { var v = parseFloat(pctInput.value); return isNaN(v) ? 0 : v; }
				function fmt(v) { return (v > 0 ? '+' : '') + fa(String(v).replace('-', '−')) + '٪'; }
				function refresh() {
					var v = pct();
					Array.prototype.forEach.call(chips, function (c) { c.classList.toggle('is-on', parseFloat(c.getAttribute('data-v')) === v && pctInput.value !== ''); });
					applyBtn.disabled = true;
					if (! v) { preview.textContent = 'یک درصد انتخاب کنید تا نمونه‌ی قیمت‌ها را ببینید.'; return; }
					preview.textContent = 'در حال محاسبه…';
					clearTimeout(timer);
					timer = setTimeout(function () {
						post('npt_pct_preview', { pct: v }).then(function (res) {
							if (! res || ! res.success) { preview.textContent = errMsg(res, 'محاسبه انجام نشد.'); return; }
							total = res.data.total;
							var html = '<strong>' + fmt(v) + '</strong> روی ' + fa(total) + ' محصول' + (res.data.sample.length ? ':' : '');
							res.data.sample.forEach(function (s) {
								html += '<div class="nptp-pct-row"><span></span><em>' + s.old + ' ← <b>' + s.new + '</b></em></div>';
							});
							preview.innerHTML = html;
							Array.prototype.forEach.call(preview.querySelectorAll('.nptp-pct-row span'), function (el, i) { el.textContent = res.data.sample[i].name; });
							applyBtn.disabled = false;
						});
					}, 350);
				}
				Array.prototype.forEach.call(chips, function (c) {
					c.addEventListener('click', function () { pctInput.value = c.getAttribute('data-v'); refresh(); });
				});
				pctInput.addEventListener('input', refresh);

				applyBtn.addEventListener('click', function () {
					var v = pct();
					if (! v || ! confirm('قیمت همه‌ی ' + fa(total) + ' محصول (قیمت‌ها به ' + <?php echo wp_json_encode( npt_currency_word() ); ?> + ') ' + fmt(v) + ' تغییر می‌کند. ادامه می‌دهید؟\n(بعدش می‌توانید با «برگرداندن آخرین تغییر» به حالت قبل برگردید.)')) { return; }
					applyBtn.disabled = true;
					say(st, 'در حال اعمال… چند لحظه صبر کنید.');
					post('npt_pct_apply', { pct: v }).then(function (res) {
						if (res && res.success) {
							say(st, 'قیمت ' + fa(res.data.count) + ' محصول تغییر کرد.', true);
							nptNotifyAsk(function () { window.location.reload(); });
						} else {
							say(st, errMsg(res, 'اعمال نشد.'));
							applyBtn.disabled = false;
						}
					}).catch(function () { say(st, 'ارتباط با سرور قطع شد؛ پیش از تلاش دوباره، قیمت‌ها را بررسی کنید.'); });
				});
				if (undoBtn) {
					undoBtn.addEventListener('click', function () {
						if (! confirm('قیمت‌ها به حالت قبل از آخرین تغییر برمی‌گردند. ادامه می‌دهید؟')) { return; }
						undoBtn.disabled = true;
						say(st, 'در حال برگرداندن…');
						post('npt_pct_undo', {}).then(function (res) {
							if (res && res.success) {
								say(st, 'قیمت ' + fa(res.data.count) + ' مورد برگردانده شد.' + (res.data.skipped ? ' ' + fa(res.data.skipped) + ' مورد برنگشت چون بعد از تغییر دستی ویرایش شده بود.' : ''), true);
								setTimeout(function () { window.location.reload(); }, 900);
							} else {
								say(st, errMsg(res, 'برگرداندن انجام نشد.'));
								undoBtn.disabled = false;
							}
						});
					});
				}
			}

			/* ---- PWA + push ---- */
			var canSW = 'serviceWorker' in navigator;
			if (canSW) { navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope || '/' }).catch(function () {}); }

			var stateEl = document.getElementById('nptp-push-state');
			if (! stateEl) { return; }
			var onBtn = document.getElementById('nptp-push-on');
			var offBtn = document.getElementById('nptp-push-off');
			var testBtn = document.getElementById('nptp-push-test');
			var instBtn = document.getElementById('nptp-install');
			var iosHint = document.getElementById('nptp-ios-hint');
			var pst = document.getElementById('nptp-push-status');
			var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
			var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
			var supported = canSW && 'PushManager' in window && 'Notification' in window;

			function u8(s) {
				s = s.replace(/-/g, '+').replace(/_/g, '/');
				var raw = atob(s + '='.repeat((4 - s.length % 4) % 4)), out = new Uint8Array(raw.length);
				for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
				return out;
			}
			function show(state) {
				onBtn.hidden = state !== 'off';
				offBtn.hidden = testBtn.hidden = state !== 'on';
			}
			function refreshState() {
				if (! cfg.key) { stateEl.textContent = 'اعلان پوش روی این سرور در دسترس نیست؛ پیامک همچنان کار می‌کند.'; show('none'); return; }
				if (location.protocol !== 'https:') { stateEl.textContent = 'اعلان فقط روی https کار می‌کند.'; show('none'); return; }
				if (isIOS && ! standalone) { stateEl.textContent = 'برای اعلان در آیفون، ابتدا برنامه را به صفحه‌ی اصلی اضافه کنید.'; iosHint.hidden = false; show('none'); return; }
				if (! supported) { stateEl.textContent = 'این مرورگر از اعلان پشتیبانی نمی‌کند.'; show('none'); return; }
				if (Notification.permission === 'denied') { stateEl.textContent = 'اعلان برای این سایت در مرورگر مسدود شده است؛ از تنظیمات مرورگر آزادش کنید.'; show('none'); return; }
				navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
					stateEl.textContent = sub ? 'اعلان روی این دستگاه فعال است ✓' : 'اعلان روی این دستگاه خاموش است.';
					show(sub ? 'on' : 'off');
				}).catch(function () { stateEl.textContent = 'وضعیت اعلان خوانده نشد.'; show('off'); });
			}
			refreshState();

			function subscribeNow() {
				return navigator.serviceWorker.ready.then(function (reg) {
					return reg.pushManager.getSubscription().then(function (sub) {
						return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: u8(cfg.key) });
					});
				}).then(function (sub) {
					return post('npt_push_subscribe', { endpoint: sub.endpoint });
				}).then(function () { refreshState(); });
			}
			var pushable = supported && !!cfg.key && location.protocol === 'https:' && !(isIOS && !standalone);
			var notifCard = document.getElementById('nptp-notif-card');
			if (pushable && Notification.permission === 'granted') { subscribeNow().catch(function () {}); }
			if (notifCard && pushable && Notification.permission === 'default') { notifCard.hidden = false; }
			var notifGo = document.getElementById('nptp-notif-go');
			if (notifGo) {
				notifGo.addEventListener('click', function () {
					Notification.requestPermission().then(function (perm) {
						if (perm === 'granted') { notifCard.hidden = true; return subscribeNow(); }
					}).catch(function () {});
				});
			}
			onBtn.addEventListener('click', function () {
				onBtn.disabled = true;
				Notification.requestPermission().then(function (perm) {
					if (perm !== 'granted') { say(pst, 'اجازه‌ی اعلان داده نشد.'); onBtn.disabled = false; return; }
					return navigator.serviceWorker.ready.then(function (reg) {
						return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: u8(cfg.key) });
					}).then(function (sub) {
						return post('npt_push_subscribe', { endpoint: sub.endpoint });
					}).then(function (res) {
						onBtn.disabled = false;
						say(pst, res && res.success ? 'فعال شد.' : errMsg(res, 'ثبت نشد.'), res && res.success);
						refreshState();
					});
				}).catch(function (e) { onBtn.disabled = false; say(pst, 'فعال‌سازی انجام نشد: ' + (e && e.message ? e.message : '')); });
			});
			offBtn.addEventListener('click', function () {
				navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
					if (! sub) { return; }
					var ep = sub.endpoint;
					return sub.unsubscribe().then(function () { return post('npt_push_unsubscribe', { endpoint: ep }); });
				}).then(function () { say(pst, 'خاموش شد.', true); refreshState(); });
			});
			testBtn.addEventListener('click', function () {
				testBtn.disabled = true;
				say(pst, 'در حال ارسال…');
				post('npt_push_test', {}).then(function (res) {
					testBtn.disabled = false;
					say(pst, res && res.success ? 'ارسال شد؛ چند ثانیه صبر کنید.' : errMsg(res, 'ارسال نشد.'), res && res.success);
				});
			});

			var deferred = null;
			window.addEventListener('beforeinstallprompt', function (e) {
				e.preventDefault();
				deferred = e;
				if (instBtn) { instBtn.hidden = false; }
			});
			var instHint = document.getElementById('nptp-install-hint');
			setTimeout(function () {
				if (! deferred && ! standalone && ! isIOS && instHint) { instHint.hidden = false; }
			}, 4000);
			if (instBtn) {
				instBtn.addEventListener('click', function () {
					if (! deferred) { if (instHint) { instHint.hidden = false; } return; }
					deferred.prompt();
					deferred = null;
					instBtn.hidden = true;
				});
			}
			if (isIOS && ! standalone && iosHint) { iosHint.hidden = false; }
		}());
		</script>
		<?php
	}

}
