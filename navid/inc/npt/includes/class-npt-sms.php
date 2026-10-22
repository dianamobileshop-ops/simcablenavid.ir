<?php
/**
 * SMS module (ملی‌پیامک / melipayamak) with a contact archive.
 *
 * Navid enters his own melipayamak panel credentials (username, password,
 * sender line) in the «تنظیمات» tab; contacts live in a dedicated table
 * (imported from CSV or added one by one), the «ارسال» tab pushes a
 * template — with the {name} placeholder — to a whole group in small
 * batches over AJAX, and the «گزارش» tab keeps the per-recipient log.
 *
 * API (verified against the official melipayamak SDKs): REST base
 * https://rest.payamak-panel.com/api/SendSMS/ with POST form bodies:
 *   SendSMS  → username, password, to (comma-joined = multiple recipients),
 *              from, text, isFlash
 *   GetCredit → username, password
 * Both answer JSON with RetStatus (1 = success) and Value.
 *
 * Credentials are stored as plain options (standard for such panels); the
 * settings field description warns about that. All send/batch/test
 * endpoints require the table-edit capability + nonce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPT_SMS {

	const NONCE      = 'npt_sms';
	const DB_VER_OPT = 'npt_sms_db_ver';
	const DB_VERSION = 2;
	const BATCH      = 20;
	const API_BASE   = 'https://rest.payamak-panel.com/api/SendSMS/';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_npt_sms_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_npt_sms_queue', array( __CLASS__, 'ajax_queue' ) );
		add_action( 'wp_ajax_npt_sms_send_batch', array( __CLASS__, 'ajax_send_batch' ) );
		add_action( 'wp_ajax_npt_sms_tpl_save', array( __CLASS__, 'ajax_tpl_save' ) );
		add_action( 'wp_ajax_npt_sms_tpl_delete', array( __CLASS__, 'ajax_tpl_delete' ) );
		add_action( 'admin_post_npt_sms_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_npt_sms_contact_add', array( __CLASS__, 'handle_contact_add' ) );
		add_action( 'admin_post_npt_sms_contact_delete', array( __CLASS__, 'handle_contact_delete' ) );
		add_action( 'admin_post_npt_sms_contact_update', array( __CLASS__, 'handle_contact_update' ) );
		add_action( 'npt_proforma_created', array( __CLASS__, 'on_proforma' ), 20, 2 );
		add_action( 'npt_proforma_created', array( __CLASS__, 'on_proforma_customer' ), 30, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Owner notification: SMS when a visitor submits a proforma
	 * ------------------------------------------------------------------- */
	public static function notify_numbers() {
		$raw = (string) get_option( 'npt_notify_mobiles', '' );
		$out = array();
		foreach ( preg_split( '/[\s,،;]+/u', $raw ) as $part ) {
			$phone = NPT_Proforma::normalize_phone( $part );
			if ( $phone ) {
				$out[ $phone ] = $phone;
			}
		}
		return array_values( array_slice( $out, 0, 5 ) );
	}

	public static function on_proforma( $saved, $info ) {
		if ( ! get_option( 'npt_notify_sms', 0 ) ) {
			return;
		}
		$numbers = self::notify_numbers();
		$creds   = self::credentials();
		if ( ! $numbers || ! self::auth_ready( $creds ) || '' === $creds['from'] ) {
			return;
		}
		$text = sprintf(
			"پیش‌فاکتور جدید #%s\n%s - %s\n%s",
			npt_to_persian_digits( (string) $saved['number'] ),
			$info['name'],
			npt_to_persian_digits( $info['phone'] ),
			npt_format_price( $info['total'], true )
		);
		self::api_call( 'SendSMS', array(
			'to'      => implode( ',', $numbers ),
			'from'    => $creds['from'],
			'text'    => $text,
			'isFlash' => 'false',
		) );
	}

	/** Site visitor's proforma: optionally send the customer the short link right away. */
	public static function on_proforma_customer( $saved, $info ) {
		if ( ! get_option( 'npt_pf_sms_site', 0 ) || empty( $saved['short'] ) || empty( $info['verified'] ) ) {
			return;
		}
		$phone = NPT_Proforma::normalize_phone( (string) $info['phone'] );
		if ( '' !== $phone ) {
			self::send_link( $phone, (string) $info['name'], NPT_Proforma::short_url( $saved['short'] ), (int) $saved['number'] );
		}
	}

	/** Text of the link SMS. Filter «npt_pf_sms_text» to change it. */
	public static function link_text( $name, $url ) {
		$hello = '' !== trim( $name ) ? 'سلام ' . $name . PHP_EOL : '';
		$text  = $hello . 'پیش‌فاکتور شما از سیم و کابل نوید آماده است:' . "\n" . $url;
		return apply_filters( 'npt_pf_sms_text', $text, $name, $url );
	}

	/** Sends the link to one customer and logs it. Returns the API result array. */
	public static function send_link( $phone, $name, $url, $number = 0 ) {
		global $wpdb;
		$creds = self::credentials();
		if ( ! self::auth_ready( $creds ) || '' === $creds['from'] ) {
			return array( 'ok' => false, 'str' => 'تنظیمات پیامک کامل نیست.' );
		}
		$text = self::link_text( $name, $url );
		$res  = self::api_call( 'SendSMS', array(
			'to'      => $phone,
			'from'    => $creds['from'],
			'text'    => $text,
			'isFlash' => 'false',
		) );
		$wpdb->insert( self::table_log(), array(
			'contact_id' => 0,
			'name'       => mb_substr( $name, 0, 190 ),
			'phone'      => $phone,
			'message'    => $text,
			'ok'         => ! empty( $res['ok'] ) ? 1 : 0,
			'response'   => mb_substr( (string) $res['str'], 0, 255 ),
			'created_at' => current_time( 'mysql' ),
		) );
		return $res;
	}

	/** Adds a proforma customer to the contacts list (existing phones are skipped). */
	public static function save_customer_contact( $name, $phone ) {
		return self::add_contact( (string) $name, (string) $phone, 'مشتری پیش‌فاکتور' );
	}

	public static function table_contacts() {
		global $wpdb;
		return $wpdb->prefix . 'npt_sms_contacts';
	}

	public static function table_log() {
		global $wpdb;
		return $wpdb->prefix . 'npt_sms_log';
	}

	public static function maybe_install() {
		if ( (int) get_option( self::DB_VER_OPT, 0 ) >= self::DB_VERSION ) {
			return;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$contacts = 'CREATE TABLE ' . self::table_contacts() . " (\n"
			. "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
			. "name varchar(190) NOT NULL DEFAULT '',\n"
			. "family varchar(190) NOT NULL DEFAULT '',\n"
			. "company varchar(190) NOT NULL DEFAULT '',\n"
			. "phone varchar(20) NOT NULL DEFAULT '',\n"
			. "grp varchar(190) NOT NULL DEFAULT '',\n"
			. "created_at datetime NOT NULL,\n"
			. "PRIMARY KEY  (id),\n"
			. "KEY phone (phone),\n"
			. "KEY grp (grp)\n"
			. ") $charset;\n";

		$log = 'CREATE TABLE ' . self::table_log() . " (\n"
			. "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
			. "contact_id bigint(20) unsigned NOT NULL DEFAULT 0,\n"
			. "name varchar(190) NOT NULL DEFAULT '',\n"
			. "phone varchar(20) NOT NULL DEFAULT '',\n"
			. "message text,\n"
			. "ok tinyint(1) NOT NULL DEFAULT 0,\n"
			. "response varchar(255) NOT NULL DEFAULT '',\n"
			. "created_at datetime NOT NULL,\n"
			. "PRIMARY KEY  (id),\n"
			. "KEY created_at (created_at)\n"
			. ") $charset;\n";

		dbDelta( $contacts );
		dbDelta( $log );
		update_option( self::DB_VER_OPT, self::DB_VERSION, false );
	}

	public static function register_settings() {
		// All five options join the single 'npt_settings' group: they are rendered
		// inside the one settings form (see npt_render_settings_page), and
		// WordPress blanks every option of the submitted group that is missing
		// from $_POST — a separate group would wipe them on each bar/price save.
		register_setting( 'npt_settings', 'npt_sms_mode', array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return 'token' === $v ? 'token' : 'password';
			},
			'default'           => 'password',
		) );
		register_setting( 'npt_settings', 'npt_sms_token', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'npt_settings', 'npt_sms_username', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'npt_settings', 'npt_sms_password', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'npt_settings', 'npt_sms_from', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'npt_settings', 'npt_sms_otp_pattern', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'npt_settings', 'npt_sms_console_otp', array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return empty( $v ) ? '0' : '1';
			},
			'default'           => '0',
		) );
		register_setting( 'npt_settings', 'npt_sms_otp_params', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'code',
		) );
	}

	/* ---------------------------------------------------------------------
	 * OTP (used by the mobile bar login, the employer panel and registration)
	 * ------------------------------------------------------------------- */

	/** True when the panel could deliver an OTP right now. */
	public static function otp_ready() {
		$creds   = self::credentials();
		$pattern = trim( (string) get_option( 'npt_sms_otp_pattern', '' ) );
		if ( ! self::auth_ready( $creds ) ) {
			return false;
		}
		return '' !== $pattern || '' !== $creds['from'];
	}

	/**
	 * Deliver a verification code. A saved pattern (bodyId) is used first
	 * because a service line only accepts approved patterns; when no pattern is
	 * configured the code goes out as a plain message from the sender line.
	 * There is no automatic retry from pattern to plain: a rejected send is
	 * reported instead of spending credit twice.
	 *
	 * @return true|WP_Error
	 */
	/** Token mode: the console makes the code and the text. Returns the code, '' in password mode, or WP_Error. */
	public static function api_otp_code( $phone ) {
		$creds = self::credentials();
		// The console's own OTP service needs extra set-up and approval in the provider's panel,
		// so it is opt-in. By default the code is made here and sent like every other message
		// (the same «send/simple» route that the connection test uses).
		if ( '1' !== (string) get_option( 'npt_sms_console_otp', '0' ) || 'token' !== $creds['mode'] || ! self::auth_ready( $creds ) ) {
			return '';
		}
		$response = wp_remote_post( 'https://console.melipayamak.com/api/send/otp/' . rawurlencode( $creds['token'] ), array(
			'timeout' => 25,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'to' => $phone ) ),
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'npt_otp_api', 'ارتباط با ملی‌پیامک برقرار نشد.' );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['code'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['status'] ) ? (string) $data['status'] : 'ارسال کد انجام نشد.';
			return new WP_Error( 'npt_otp_api', $msg );
		}
		return (string) $data['code'];
	}

	/** Sends one text to several numbers in one call. Returns the count, or WP_Error. */
	public static function send_text_to( array $phones, $text ) {
		$creds = self::credentials();
		if ( ! self::auth_ready( $creds ) || '' === $creds['from'] ) {
			return new WP_Error( 'npt_sms_setup', 'تنظیمات پیامک کامل نیست.' );
		}
		$res = self::api_call( 'SendSMS', array(
			'to'      => implode( ',', $phones ),
			'from'    => $creds['from'],
			'text'    => $text,
			'isFlash' => 'false',
		) );
		return $res['ok'] ? count( $phones ) : new WP_Error( 'npt_sms_send', $res['str'] ? $res['str'] : 'ارسال انجام نشد.' );
	}

	public static function send_otp( $phone, $code ) {
		if ( ! self::otp_ready() ) {
			return new WP_Error( 'npt_otp_unconfigured', 'سرویس پیامک هنوز کامل پیکربندی نشده است.' );
		}

		$params  = trim( (string) get_option( 'npt_sms_otp_params', '' ) );
		if ( '' === $params || 'code' === $params ) {
			$params = 'کد تأیید ورود به سیم و کابل نوید: code';
		}
		$text    = str_replace( 'code', $code, $params );
		$pattern = trim( (string) get_option( 'npt_sms_otp_pattern', '' ) );

		if ( '' !== $pattern ) {
			$res = self::api_call( 'BaseServiceNumber', array(
				'to'     => $phone,
				'text'   => $text,
				'bodyId' => $pattern,
			) );
			if ( $res['ok'] ) {
				return true;
			}
			return new WP_Error( 'npt_otp_failed', 'ارسال پیامک ناموفق بود: ' . ( $res['str'] ? $res['str'] : 'کد ' . $res['ret'] ) );
		}

		$creds = self::credentials();
		$res   = self::api_call( 'SendSMS', array(
			'to'      => $phone,
			'from'    => $creds['from'],
			'text'    => $text,
			'isFlash' => 'false',
		) );
		if ( $res['ok'] ) {
			return true;
		}
		return new WP_Error( 'npt_otp_failed', 'ارسال پیامک ناموفق بود: ' . ( $res['str'] ? $res['str'] : 'کد ' . $res['ret'] ) );
	}

	/** Where the SMS tabs live now that they are part of the settings page. */
	public static function settings_url( $tab = 'contacts' ) {
		return admin_url( 'admin.php?page=navid-settings&tab=' . $tab );
	}

	private static function credentials() {
		// NPT_SMS_USERNAME / NPT_SMS_PASSWORD in wp-config.php win over the stored
		// options, so the password never has to sit in the database.
		$mode = 'token' === get_option( 'npt_sms_mode', 'password' ) ? 'token' : 'password';
		return array(
			'mode'     => $mode,
			'token'    => trim( (string) ( defined( 'NPT_SMS_TOKEN' ) ? NPT_SMS_TOKEN : get_option( 'npt_sms_token', '' ) ) ),
			'username' => trim( (string) ( defined( 'NPT_SMS_USERNAME' ) ? NPT_SMS_USERNAME : get_option( 'npt_sms_username', '' ) ) ),
			'password' => trim( (string) ( defined( 'NPT_SMS_PASSWORD' ) ? NPT_SMS_PASSWORD : get_option( 'npt_sms_password', '' ) ) ),
			'from'     => trim( (string) get_option( 'npt_sms_from', '' ) ),
		);
	}

	/** Are the login details of the chosen mode filled in? */
	private static function auth_ready( array $creds ) {
		return 'token' === $creds['mode']
			? '' !== $creds['token']
			: ( '' !== $creds['username'] && '' !== $creds['password'] );
	}

	/**
	 * Token mode (console.melipayamak.com): the token sits in the URL path and
	 * the body is JSON. Only simple and shared (pattern) sends are mapped; the
	 * rest of the old password API has no equivalent here.
	 */
	private static function token_post( $path, array $body ) {
		$creds = self::credentials();
		$resp  = wp_remote_post( 'https://console.melipayamak.com/api/' . $path . '/' . rawurlencode( $creds['token'] ), array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body'        => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $resp ) ) {
			// The token is part of the URL: never let it reach a message or a log.
			return array( 'ok' => false, 'value' => '', 'ret' => 0, 'str' => str_replace( $creds['token'], '***', $resp->get_error_message() ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) ) {
			return array( 'ok' => false, 'value' => '', 'ret' => 0, 'str' => 'پاسخ نامعتبر از سرور (HTTP ' . (int) wp_remote_retrieve_response_code( $resp ) . ')' );
		}
		$rec    = isset( $data['recId'] ) ? $data['recId'] : '';
		$status = isset( $data['status'] ) ? trim( (string) $data['status'] ) : '';
		// A real record id is a long number; small values are provider error codes.
		$ok  = '' === $status && is_numeric( $rec ) && (float) $rec >= 1000;
		$str = '' !== $status ? $status : ( $ok ? '' : 'ارسال انجام نشد (کد ' . ( is_scalar( $rec ) ? $rec : '؟' ) . ')' );
		return array( 'ok' => $ok, 'value' => $rec, 'ret' => $ok ? 1 : (int) ( is_numeric( $rec ) ? $rec : 0 ), 'str' => $str );
	}

	private static function token_call( $method, array $extra ) {
		if ( 'SendSMS' === $method ) {
			$result = array( 'ok' => true, 'value' => '', 'ret' => 1, 'str' => '' );
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $extra['to'] ) ) ) as $to ) {
				$r = self::token_post( 'send/simple', array( 'from' => $extra['from'], 'to' => $to, 'text' => $extra['text'] ) );
				if ( $r['ok'] ) {
					$result['value'] = $r['value'];
				} else {
					$result = array( 'ok' => false, 'value' => '', 'ret' => $r['ret'], 'str' => $r['str'] );
				}
			}
			return $result;
		}
		if ( 'BaseServiceNumber' === $method ) {
			return self::token_post( 'send/shared', array(
				'bodyId' => (int) $extra['bodyId'],
				'to'     => $extra['to'],
				'args'   => array_map( 'trim', explode( ';', (string) $extra['text'] ) ),
			) );
		}
		return array( 'ok' => false, 'value' => '', 'ret' => 0, 'str' => 'این عملیات در حالت توکن پشتیبانی نمی‌شود.' );
	}

	/**
	 * POST form-data to a melipayamak REST method and decode the JSON
	 * reply. Returns array( 'ok' => bool, 'value' => mixed, 'ret' => int,
	 * 'str' => string ).
	 */
	private static function api_call( $method, array $extra = array() ) {
		$res = self::api_call_raw( $method, $extra );
		if ( empty( $res['ok'] ) ) {
			NPT_Log::add( 'پیامک', $method . ': ' . $res['str'] );
		}
		return $res;
	}

	private static function api_call_raw( $method, array $extra = array() ) {
		$creds = self::credentials();
		if ( 'token' === $creds['mode'] ) {
			return self::token_call( $method, $extra );
		}
		$body  = array_merge( array(
			'username' => $creds['username'],
			'password' => $creds['password'],
		), $extra );

		$response = wp_remote_post( self::API_BASE . $method, array(
			'timeout' => 25,
			'body'    => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'value' => '', 'ret' => 0, 'str' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array( 'ok' => false, 'value' => '', 'ret' => 0, 'str' => 'پاسخ نامعتبر از سرور (HTTP ' . $code . ')' );
		}
		$ret = isset( $data['RetStatus'] ) ? (int) $data['RetStatus'] : 0;
		$str = isset( $data['StrRetStatus'] ) ? (string) $data['StrRetStatus'] : '';
		return array(
			'ok'    => 1 === $ret,
			'value' => isset( $data['Value'] ) ? $data['Value'] : '',
			'ret'   => $ret,
			'str'   => $str,
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: connection test (remaining credit)
	 * ------------------------------------------------------------------- */
	public static function ajax_test() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! npt_current_user_can_edit() ) {
			wp_send_json_error( array( 'message' => 'دسترسی لازم را ندارید.' ), 403 );
		}
		$creds = self::credentials();
		if ( ! self::auth_ready( $creds ) ) {
			wp_send_json_error( array( 'message' => 'ابتدا اطلاعات ورود ملی‌پیامک را در تب «تنظیمات پیامک» ذخیره کنید.' ) );
		}
		if ( 'token' === $creds['mode'] ) {
			// The token API has no credit call, so the test is a real message.
			$to = NPT_Proforma::normalize_phone( isset( $_POST['to'] ) ? wp_unslash( $_POST['to'] ) : '' );
			if ( '' === $to || '' === $creds['from'] ) {
				wp_send_json_error( array( 'message' => 'شماره‌ی خودتان را برای دریافت پیامک آزمایشی وارد کنید و مطمئن شوید «خط ارسال» پر است.' ) );
			}
			$res = self::api_call( 'SendSMS', array( 'to' => $to, 'from' => $creds['from'], 'text' => 'تست اتصال افزونه جدول قیمت نوید', 'isFlash' => 'false' ) );
			if ( $res['ok'] ) {
				wp_send_json_success( array( 'message' => 'اتصال موفق — پیامک آزمایشی ارسال شد.' ) );
			}
			wp_send_json_error( array( 'message' => 'اتصال ناموفق: ' . $res['str'] ) );
		}
		$res = self::api_call( 'GetCredit' );
		if ( $res['ok'] ) {
			wp_send_json_success( array( 'message' => 'اتصال موفق — اعتبار پنل: ' . npt_to_persian_digits( (string) $res['value'] ) . ' پیامک' ) );
		}
		wp_send_json_error( array( 'message' => 'اتصال ناموفق: ' . $res['str'] . ' (کد ' . $res['ret'] . ')' ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: recipient queue for the send tab
	 * ------------------------------------------------------------------- */
	public static function ajax_queue() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! npt_current_user_can_edit() ) {
			wp_send_json_error( array( 'message' => 'دسترسی لازم را ندارید.' ), 403 );
		}
		global $wpdb;
		$group = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';
		// Hard ceiling on the queue handed to the browser: the send loop runs in JS
		// and a huge list would stall the tab (and the panel has 40M memory to spare).
		$queue_limit = 2000;
		if ( '' !== $group ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, name, phone FROM ' . self::table_contacts() . ' WHERE grp = %s ORDER BY id ASC LIMIT %d', // phpcs:ignore
				$group,
				$queue_limit
			) );
		} else {
			$rows = $wpdb->get_results( 'SELECT id, name, phone FROM ' . self::table_contacts() . ' ORDER BY id ASC LIMIT ' . (int) $queue_limit ); // phpcs:ignore
		}
		wp_send_json_success( array( 'contacts' => $rows, 'limited' => count( $rows ) >= $queue_limit ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: send one batch (max self::BATCH contacts) and log per recipient
	 * ------------------------------------------------------------------- */
	public static function ajax_send_batch() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! npt_current_user_can_edit() ) {
			wp_send_json_error( array( 'message' => 'دسترسی لازم را ندارید.' ), 403 );
		}
		NPT_Log::activity( 'ارسال پیامک انبوه به مخاطبین' );
		$creds = self::credentials();
		if ( ! self::auth_ready( $creds ) || '' === $creds['from'] ) {
			wp_send_json_error( array( 'message' => 'تنظیمات ملی‌پیامک کامل نیست (اطلاعات ورود و خط ارسال).' ) );
		}

		$template = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		if ( '' === trim( $template ) ) {
			wp_send_json_error( array( 'message' => 'متن پیامک خالی است.' ) );
		}
		$template = mb_substr( $template, 0, 500 );

		$ids = isset( $_POST['ids'] ) ? json_decode( wp_unslash( $_POST['ids'] ), true ) : array();
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			wp_send_json_error( array( 'message' => 'فهرست گیرندگان خالی است.' ) );
		}
		$ids = array_slice( array_map( 'absint', $ids ), 0, self::BATCH );

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, name, family, company, grp, phone FROM ' . self::table_contacts() . " WHERE id IN ($placeholders)", // phpcs:ignore
			$ids
		) );
		if ( empty( $rows ) ) {
			wp_send_json_error( array( 'message' => 'گیرنده‌ای یافت نشد.' ) );
		}

		$now        = current_time( 'mysql' );
		$personal   = self::has_vars( $template );
		$all_ok     = true;
		$last_error = '';

		// A text with a per-contact placeholder must go out one recipient at a time,
		// otherwise every number would receive the first contact's values. A fixed
		// text is sent as one comma-separated call.
		$groups = $personal ? array_map( function ( $row ) {
			return array( $row );
		}, $rows ) : array( $rows );

		foreach ( $groups as $group ) {
			$text = $personal ? self::render_text( $template, $group[0] ) : $template;
			$res  = self::api_call( 'SendSMS', array(
				'to'      => implode( ',', wp_list_pluck( $group, 'phone' ) ),
				'from'    => $creds['from'],
				'text'    => $text,
				'isFlash' => 'false',
			) );
			$summary = $res['ok'] ? 'موفق' : trim( $res['str'] . ' (کد ' . $res['ret'] . ')' );
			if ( ! $res['ok'] ) {
				$all_ok     = false;
				$last_error = $summary;
			}
			foreach ( $group as $row ) {
				$wpdb->insert(
					self::table_log(),
					array(
						'contact_id' => (int) $row->id,
						'name'       => $row->name,
						'phone'      => $row->phone,
						'message'    => self::render_text( $template, $row ),
						'ok'         => $res['ok'] ? 1 : 0,
						'response'   => mb_substr( (string) $summary, 0, 255 ),
						'created_at' => $now,
					),
					array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
				);
			}
		}

		wp_send_json_success( array(
			'sent' => count( $rows ),
			'ok'   => $all_ok,
			'msg'  => $all_ok ? 'موفق' : $last_error,
		) );
	}

	/* ---------------------------------------------------------------------
	 * Saved templates (name → text), kept in one autoload-off option
	 * ------------------------------------------------------------------- */
	private static function templates() {
		$tpl = get_option( 'npt_sms_templates', array() );
		return is_array( $tpl ) ? $tpl : array();
	}

	public static function ajax_tpl_save() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! npt_current_user_can_edit() ) {
			wp_send_json_error( array( 'message' => 'دسترسی لازم را ندارید.' ), 403 );
		}
		$name = isset( $_POST['name'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['name'] ) ), 0, 100 ) : '';
		$text = isset( $_POST['text'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['text'] ) ), 0, 500 ) : '';
		if ( '' === trim( $name ) || '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => 'نام قالب و متن آن را وارد کنید.' ) );
		}
		$tpl = self::templates();
		if ( ! isset( $tpl[ $name ] ) && count( $tpl ) >= 20 ) {
			wp_send_json_error( array( 'message' => 'حداکثر ۲۰ قالب ذخیره می‌شود. ابتدا یکی از قالب‌های قدیمی را حذف کنید.' ) );
		}
		$tpl[ $name ] = $text;
		update_option( 'npt_sms_templates', $tpl, false );
		wp_send_json_success( array( 'templates' => $tpl ) );
	}

	public static function ajax_tpl_delete() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! npt_current_user_can_edit() ) {
			wp_send_json_error( array( 'message' => 'دسترسی لازم را ندارید.' ), 403 );
		}
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$tpl  = self::templates();
		unset( $tpl[ $name ] );
		update_option( 'npt_sms_templates', $tpl, false );
		wp_send_json_success( array( 'templates' => $tpl ) );
	}

	/* ---------------------------------------------------------------------
	 * CSV import: columns «نام، موبایل، گروه» (header optional)
	 * ------------------------------------------------------------------- */
	/** Where an admin-post handler goes back to: the panel (front end) or wp-admin. */
	private static function back_url( $fallback = '' ) {
		$back = isset( $_REQUEST['npt_back'] ) ? esc_url_raw( wp_unslash( $_REQUEST['npt_back'] ) ) : ''; // phpcs:ignore
		if ( $back && wp_validate_redirect( $back, '' ) === $back ) {
			return $back;
		}
		return '' !== $fallback ? $fallback : self::settings_url();
	}

	/** Insert one contact unless the number is already stored. Returns true when added. */
	private static function add_contact( $name, $phone, $grp, $family = '', $company = '' ) {
		global $wpdb;
		$phone = NPT_Proforma::normalize_phone( $phone );
		if ( '' === $phone ) {
			return false;
		}
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table_contacts() . ' WHERE phone = %s LIMIT 1', // phpcs:ignore
			$phone
		) );
		if ( $exists ) {
			return false;
		}
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			$name = $phone;
		}
		return (bool) $wpdb->insert(
			self::table_contacts(),
			array(
				'name'       => mb_substr( $name, 0, 190 ),
				'family'     => mb_substr( sanitize_text_field( $family ), 0, 190 ),
				'company'    => mb_substr( sanitize_text_field( $company ), 0, 190 ),
				'phone'      => $phone,
				'grp'        => mb_substr( sanitize_text_field( $grp ), 0, 190 ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/** Placeholders a message can use. Each one is replaced with that contact's value. */
	public static function template_vars() {
		return array(
			'{name}'     => 'نام',
			'{family}'   => 'نام خانوادگی',
			'{fullname}' => 'نام کامل',
			'{company}'  => 'نام شرکت',
			'{group}'    => 'گروه',
		);
	}

	/** True when the text has a placeholder whose value differs from contact to contact. */
	public static function has_vars( $template ) {
		return (bool) preg_match( '/\{(?:name|family|fullname|company|group)\}/u', (string) $template );
	}

	/** Fill the placeholders of a message with one contact's values. */
	public static function render_text( $template, $row ) {
		return strtr( (string) $template, array(
			'{name}'     => (string) $row->name,
			'{family}'   => (string) $row->family,
			'{fullname}' => trim( $row->name . ' ' . $row->family ),
			'{company}'  => (string) $row->company,
			'{group}'    => (string) $row->grp,
		) );
	}

	/** vCard (.vcf) exported from a phone: FN/N + every TEL line becomes a row. */
	public static function parse_vcf( $text ) {
		$rows = array();
		$text = preg_replace( "/\r\n?/", "\n", (string) $text );
		$text = preg_replace( "/\n[ \t]/", '', $text ); // unfold wrapped lines
		foreach ( preg_split( '/BEGIN:VCARD/i', $text ) as $card ) {
			if ( false === stripos( $card, 'TEL' ) ) {
				continue;
			}
			$name    = '';
			$family  = '';
			$company = '';
			if ( preg_match( '/^FN[^:]*:(.+)$/mi', $card, $m ) ) {
				$name = trim( $m[1] );
			} elseif ( preg_match( '/^N[^:]*:(.+)$/mi', $card, $m ) ) {
				$name = trim( implode( ' ', array_reverse( array_filter( explode( ';', $m[1] ) ) ) ) );
			}
			// N is «family;given;…» and ORG is «company;department;…».
			if ( preg_match( '/^N(?:;[^:\r\n]*)?:([^;\r\n]*)/mi', $card, $m ) ) {
				$family = trim( $m[1] );
			}
			if ( preg_match( '/^ORG(?:;[^:\r\n]*)?:([^;\r\n]*)/mi', $card, $m ) ) {
				$company = trim( $m[1] );
			}
			if ( preg_match_all( '/^(?:item\d+\.)?TEL[^:]*:(.+)$/mi', $card, $tels ) ) {
				foreach ( $tels[1] as $tel ) {
					$rows[] = array( $name, trim( $tel ), $family, $company );
				}
			}
		}
		return $rows;
	}

	/** Pasted lines: «name, 0912…», «0912… name», or just a number per line. */
	public static function parse_lines( $text ) {
		$rows = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$line = strtr( $line, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
			if ( preg_match( '/(?:\+98|0098|98|0)?9[\d\s\-]{8,12}\d/', $line, $m ) ) {
				$name = preg_replace( '/^[\s,;،\-]+|[\s,;،\-]+$/u', '', str_replace( $m[0], '', $line ) );
				$rows[] = array( $name, $m[0] );
			}
		}
		return $rows;
	}

	public static function handle_import() {
		if ( ! npt_current_user_can_edit() ) {
			wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
		}
		check_admin_referer( 'npt_sms_import', 'npt_sms_import_nonce' );
		$back    = self::back_url();
		$default = isset( $_POST['npt_sms_default_group'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_sms_default_group'] ) ) : '';
		$paste   = isset( $_POST['npt_sms_paste'] ) ? sanitize_textarea_field( wp_unslash( $_POST['npt_sms_paste'] ) ) : '';

		// each: array( name, phone, group, family, company )
		$rows = array();
		$has_file = ! empty( $_FILES['npt_sms_csv']['tmp_name'] ) && is_uploaded_file( $_FILES['npt_sms_csv']['tmp_name'] );

		if ( $has_file ) {
			$tmp  = $_FILES['npt_sms_csv']['tmp_name']; // phpcs:ignore
			$ext  = strtolower( pathinfo( (string) $_FILES['npt_sms_csv']['name'], PATHINFO_EXTENSION ) ); // phpcs:ignore
			$data = file_get_contents( $tmp, false, null, 0, 2 * 1024 * 1024 ); // phpcs:ignore
			if ( 'vcf' === $ext || false !== stripos( (string) $data, 'BEGIN:VCARD' ) ) {
				foreach ( self::parse_vcf( $data ) as $r ) {
					$rows[] = array( $r[0], $r[1], $default, $r[2], $r[3] );
				}
			} else {
				$handle = fopen( $tmp, 'r' ); // phpcs:ignore
				if ( $handle ) {
					// CSV columns: نام، موبایل، گروه، نام خانوادگی، شرکت (the last three are optional).
					while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
						$row = array_map( 'trim', (array) $row );
						if ( count( $row ) < 2 ) {
							continue;
						}
						$rows[] = array(
							$row[0],
							$row[1],
							isset( $row[2] ) && '' !== $row[2] ? $row[2] : $default,
							isset( $row[3] ) ? $row[3] : '',
							isset( $row[4] ) ? $row[4] : '',
						);
					}
					fclose( $handle ); // phpcs:ignore
				}
			}
		}
		if ( '' !== trim( $paste ) ) {
			foreach ( self::parse_lines( $paste ) as $r ) {
				$rows[] = array( $r[0], $r[1], $default, '', '' );
			}
		}

		if ( ! $has_file && '' === trim( $paste ) ) {
			wp_safe_redirect( add_query_arg( 'npt_sms_msg', 'file', $back ) );
			exit;
		}

		$added   = 0;
		$skipped = 0;
		$seen    = 0;
		foreach ( array_slice( $rows, 0, 5000 ) as $r ) {
			$seen++;
			if ( NPT_Proforma::normalize_phone( $r[1] ) === '' ) {
				// A header row ("نام, موبایل") is not worth counting as skipped.
				if ( 1 === $seen && ! preg_match( '/\d{6,}/', (string) $r[1] ) ) {
					continue;
				}
				$skipped++;
				continue;
			}
			if ( self::add_contact( $r[0], $r[1], $r[2], $r[3], $r[4] ) ) {
				$added++;
			} else {
				$skipped++;
			}
		}

		wp_safe_redirect( add_query_arg( array( 'npt_sms_msg' => 'imported', 'added' => $added, 'skipped' => $skipped ), $back ) );
		exit;
	}

	public static function handle_contact_add() {
		if ( ! npt_current_user_can_edit() ) {
			wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
		}
		check_admin_referer( 'npt_sms_contact_add', 'npt_sms_contact_add_nonce' );
		$back  = self::back_url();
		$name    = isset( $_POST['npt_sms_new_name'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_sms_new_name'] ) ) : '';
		$family  = isset( $_POST['npt_sms_new_family'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_sms_new_family'] ) ) : '';
		$company = isset( $_POST['npt_sms_new_company'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_sms_new_company'] ) ) : '';
		$phone   = NPT_Proforma::normalize_phone( isset( $_POST['npt_sms_new_phone'] ) ? wp_unslash( $_POST['npt_sms_new_phone'] ) : '' );
		$grp     = isset( $_POST['npt_sms_new_group'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_sms_new_group'] ) ) : '';

		if ( '' === trim( $name ) || '' === $phone ) {
			wp_safe_redirect( add_query_arg( 'npt_sms_msg', 'invalid', $back ) );
			exit;
		}

		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table_contacts() . ' WHERE phone = %s LIMIT 1', // phpcs:ignore
			$phone
		) );
		if ( $exists ) {
			wp_safe_redirect( add_query_arg( 'npt_sms_msg', 'duplicate', $back ) );
			exit;
		}

		$wpdb->insert(
			self::table_contacts(),
			array(
				'name'       => $name,
				'family'     => $family,
				'company'    => $company,
				'phone'      => $phone,
				'grp'        => $grp,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		wp_safe_redirect( $back );
		exit;
	}

	public static function handle_contact_update() {
		if ( ! npt_current_user_can_edit() ) {
			wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
		}
		$id = isset( $_POST['npt_sms_edit_id'] ) ? absint( wp_unslash( $_POST['npt_sms_edit_id'] ) ) : 0;
		check_admin_referer( 'npt_sms_contact_update_' . $id, 'npt_sms_contact_update_nonce' );
		$back    = self::back_url();
		$name    = isset( $_POST['npt_sms_edit_name'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_sms_edit_name'] ) ) : '';
		$family  = isset( $_POST['npt_sms_edit_family'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_sms_edit_family'] ) ) : '';
		$company = isset( $_POST['npt_sms_edit_company'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_sms_edit_company'] ) ) : '';
		$grp     = isset( $_POST['npt_sms_edit_group'] ) ? sanitize_text_field( wp_unslash( $_POST['npt_sms_edit_group'] ) ) : '';
		$phone   = NPT_Proforma::normalize_phone( isset( $_POST['npt_sms_edit_phone'] ) ? wp_unslash( $_POST['npt_sms_edit_phone'] ) : '' );

		if ( ! $id || '' === trim( $name ) || '' === $phone ) {
			wp_safe_redirect( add_query_arg( 'npt_sms_msg', 'invalid', $back ) );
			exit;
		}

		global $wpdb;
		$taken = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table_contacts() . ' WHERE phone = %s AND id <> %d LIMIT 1', // phpcs:ignore
			$phone,
			$id
		) );
		if ( $taken ) {
			wp_safe_redirect( add_query_arg( 'npt_sms_msg', 'duplicate', $back ) );
			exit;
		}

		$wpdb->update(
			self::table_contacts(),
			array(
				'name'    => mb_substr( $name, 0, 190 ),
				'family'  => mb_substr( $family, 0, 190 ),
				'company' => mb_substr( $company, 0, 190 ),
				'phone'   => $phone,
				'grp'     => mb_substr( $grp, 0, 190 ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		wp_safe_redirect( add_query_arg( 'npt_sms_msg', 'updated', $back ) );
		exit;
	}

	public static function handle_contact_delete() {
		if ( ! npt_current_user_can_edit() ) {
			wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'npt_sms_del_' . $id );
		if ( $id ) {
			global $wpdb;
			$wpdb->delete( self::table_contacts(), array( 'id' => $id ), array( '%d' ) );
		}
		wp_safe_redirect( self::back_url() );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Settings tabs (rendered inside the plugin settings page)
	 *
	 * The fields below are part of the single settings form, so they must not
	 * open their own <form> and must not call settings_fields() again.
	 * ------------------------------------------------------------------- */
	public static function render_credentials_fields() {
		$creds = self::credentials();
		?>
		<p class="description">اعتبارنامه‌ی پنل ملی‌پیامک. همین خط و نام‌کاربری برای کد تأیید ورود پیامکی (پنل کارفرما، نوار موبایل و ثبت‌نام) هم استفاده می‌شود.</p>
		<table class="form-table">
			<tr>
				<th scope="row">روش اتصال</th>
				<td>
					<label><input type="radio" name="npt_sms_mode" value="password" <?php checked( $creds['mode'], 'password' ); ?>> نام کاربری و رمز پنل (وب‌سرویس REST)</label><br>
					<label><input type="radio" name="npt_sms_mode" value="token" <?php checked( $creds['mode'], 'token' ); ?>> توکن (کنسول ملی‌پیامک)</label>
				</td>
			</tr>
			<tr class="npts-row-token">
				<th scope="row"><label for="npt_sms_token">توکن</label></th>
				<td>
					<input type="password" id="npt_sms_token" name="npt_sms_token" class="regular-text" dir="ltr" value="<?php echo esc_attr( $creds['token'] ); ?>" autocomplete="new-password" <?php disabled( defined( 'NPT_SMS_TOKEN' ) ); ?>>
					<p class="description">توکن را از کنسول ملی‌پیامک (console.melipayamak.com) بگیرید. در این روش «نام کاربری و رمز» لازم نیست و تست اتصال یک پیامک واقعی می‌فرستد. می‌توانید به‌جای ذخیره در دیتابیس، <code>NPT_SMS_TOKEN</code> را در wp-config.php تعریف کنید. «کد الگو» همان bodyId الگوی کنسول است و پارامترها با «;» جدا می‌شوند.</p>
				</td>
			</tr>
			<tr class="npts-row-pass">
				<th scope="row"><label for="npt_sms_username">نام کاربری پنل</label></th>
				<td>
					<input type="text" id="npt_sms_username" name="npt_sms_username" class="regular-text" dir="ltr" value="<?php echo esc_attr( $creds['username'] ); ?>" autocomplete="off">
				</td>
			</tr>
			<tr class="npts-row-pass">
				<th scope="row"><label for="npt_sms_password">رمز عبور پنل</label></th>
				<td>
					<input type="password" id="npt_sms_password" name="npt_sms_password" class="regular-text" dir="ltr" value="<?php echo esc_attr( $creds['password'] ); ?>" autocomplete="new-password">
					<p class="description">این مقادیر در دیتابیس وردپرس ذخیره می‌شوند؛ فقط برای حساب پنل پیامک استفاده کنید و رمز اصلی پنل را جایی دیگر تکرار نکنید.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="npt_sms_from">خط ارسال (شماره فرستنده)</label></th>
				<td>
					<input type="text" id="npt_sms_from" name="npt_sms_from" class="regular-text" dir="ltr" value="<?php echo esc_attr( $creds['from'] ); ?>" placeholder="5000… یا 3000… یا 09xxxxxxxxx">
					<p class="description">همان خطی که در پنل ملی‌پیامک برای ارسال استفاده می‌کنید (خط خدماتی/اشتراک‌خطی/سیم‌کارت).</p>
				</td>
			</tr>
			<tr>
				<th scope="row">وضعیت اتصال</th>
				<td>
					<input type="text" id="npts-test-to" dir="ltr" placeholder="09xxxxxxxxx (فقط در حالت توکن)" style="width:210px;margin-inline-end:8px">
					<button type="button" class="button button-secondary" id="npts-test">تست اتصال</button>
					<span id="npts-test-out" style="margin-inline-start:12px;font-weight:700"></span>
					<p class="description">برای تست، نام کاربری و رمز را ذخیره کنید و سپس این دکمه را بزنید.</p>
				</td>
			</tr>
		</table>
		<script>
		(function () {
			var btn = document.getElementById('npts-test');
			if (! btn) { return; }
			function syncMode() {
				var token = document.querySelector('input[name=npt_sms_mode][value=token]').checked;
				Array.prototype.forEach.call(document.querySelectorAll('.npts-row-token'), function (r) { r.style.display = token ? '' : 'none'; });
				Array.prototype.forEach.call(document.querySelectorAll('.npts-row-pass'), function (r) { r.style.display = token ? 'none' : ''; });
				document.getElementById('npts-test-to').style.display = token ? '' : 'none';
			}
			Array.prototype.forEach.call(document.querySelectorAll('input[name=npt_sms_mode]'), function (r) { r.addEventListener('change', syncMode); });
			syncMode();
			btn.addEventListener('click', function () {
				var out = document.getElementById('npts-test-out');
				btn.disabled = true; out.textContent = 'در حال بررسی...'; out.style.color = '';
				var fd = new FormData();
				fd.append('action', 'npt_sms_test');
				fd.append('to', document.getElementById('npts-test-to').value);
				fd.append('nonce', <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>);
				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						btn.disabled = false;
						var ok = res && res.success;
						out.textContent = ok ? res.data.message : ((res && res.data && res.data.message) || 'خطا');
						out.style.color = ok ? '#00a32a' : '#b32d2e';
					})
					.catch(function () { btn.disabled = false; out.textContent = 'خطا در ارتباط با سرور.'; out.style.color = '#b32d2e'; });
			})();
		})();
		</script>
		<?php
	}

	public static function render_otp_fields() {
		$pattern = trim( (string) get_option( 'npt_sms_otp_pattern', '' ) );
		?>
		<p class="description"><strong>مسیر ارسال:</strong> همهٔ پیامک‌ها (کد ورود، پیش‌فاکتور، اطلاع به مدیر، ارسال گروهی و تست اتصال) از همان یک مسیر می‌روند: پیامک معمولی از «خط ارسال»؛ و فقط اگر «کد الگو» پر باشد، کد ورود با همان الگو.</p>
		<p class="description">کد تأیید ورود پیامکی با یکی از این دو روش ارسال می‌شود. اگر «کد الگو» پر باشد، همان الگو (سریع‌تر و ارزان‌تر) و اگر خالی باشد، پیامک معمولی از «خط ارسال» استفاده می‌شود.</p>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="npt_sms_otp_pattern">کد الگو (bodyId)</label></th>
				<td>
					<input type="text" id="npt_sms_otp_pattern" name="npt_sms_otp_pattern" class="regular-text" dir="ltr" value="<?php echo esc_attr( get_option( 'npt_sms_otp_pattern', '' ) ); ?>" placeholder="مثلاً 123456">
					<p class="description">
						شماره‌ی الگوی تأییدشده در پنل ملی‌پیامک.
						<?php if ( '' === $pattern ) : ?>
							الان خالی است، پس کد به‌صورت پیامک معمولی از «خط ارسال» فرستاده می‌شود.
						<?php else : ?>
							الان فعال است و کد با متد BaseServiceNumber و همین الگو ارسال می‌شود.
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="npt_sms_otp_params">پارامترهای الگو</label></th>
				<td>
					<input type="text" id="npt_sms_otp_params" name="npt_sms_otp_params" class="regular-text" dir="ltr" value="<?php echo esc_attr( get_option( 'npt_sms_otp_params', 'code' ) ); ?>">
					<p class="description">در متن الگو به‌جای جای‌گاه کد، کلمه‌ی <code>code</code> را بنویسید (مثلاً <code>code</code> یا <code>4,code</code>). این افزونه مقدار <code>code</code> را با کد تولیدشده جایگزین می‌کند؛ ترتیب و نام پارامترها باید با همان چیزی باشد که در پنل تأیید شده است.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">سرویس OTP کنسول ملی‌پیامک</th>
				<td>
					<label><input type="checkbox" name="npt_sms_console_otp" value="1" <?php checked( '1', (string) get_option( 'npt_sms_console_otp', '0' ) ); ?>> کد ورود را با سرویس OTP خود کنسول بفرست (روشی جدا از ارسال معمولی)</label>
					<p class="description">پیشنهاد: خاموش بماند. این سرویس در پنل ملی‌پیامک نیاز به تنظیم و تأیید مدیر دارد و اگر فعال نشده باشد، کد ارسال نمی‌شود. با خاموش بودن، کد مثل «تست اتصال» از خط ارسال می‌رود.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/** Saved bulk-SMS templates ({name} is replaced per recipient). */
	public static function render_templates_panel() {
		$tpls = self::templates();
		?>
		<p class="description">قالب‌هایی که برای ارسال انبوه ذخیره کرده‌اید. متن هر قالب باید حداکثر ۵۰۰ نویسه باشد و برای مشخصات گیرنده‌ها از متغیرهای <code>{name}</code> (نام)، <code>{family}</code> (نام خانوادگی)، <code>{fullname}</code> (نام کامل)، <code>{company}</code> (نام شرکت) و <code>{group}</code> (گروه) استفاده کنید.</p>
		<div class="nptst">
			<div class="nptst-box">
				<h2>افزودن یا ویرایش قالب</h2>
				<label for="nptst-name">نام قالب</label>
				<input type="text" id="nptst-name" list="nptst-names" placeholder="مثلاً: اطلاع‌رسانی قیمت جدید">
				<datalist id="nptst-names"><?php foreach ( array_keys( $tpls ) as $tname ) : ?><option value="<?php echo esc_attr( $tname ); ?>"></option><?php endforeach; ?></datalist>
				<label for="nptst-text">متن قالب</label>
				<textarea id="nptst-text" rows="4" maxlength="500" placeholder="سلام {name} عزیز، لیست قیمت جدید سیم و کابل نوید به‌روزرسانی شد."></textarea>
				<p>
					<button type="button" class="button button-primary" id="nptst-save">ذخیره قالب</button>
					<button type="button" class="button" id="nptst-del">حذف قالب انتخابی</button>
					<span id="nptst-msg" style="margin-inline-start:8px;font-weight:700"></span>
				</p>
			</div>
			<?php if ( $tpls ) : ?>
			<table class="nptst-t">
				<thead><tr><th>نام قالب</th><th>متن</th><th>انتخاب</th></tr></thead>
				<tbody>
				<?php foreach ( $tpls as $tname => $ttext ) : ?>
					<tr>
						<td><?php echo esc_html( $tname ); ?></td>
						<td><?php echo esc_html( $ttext ); ?></td>
						<td><button type="button" class="button button-small nptst-pick" data-name="<?php echo esc_attr( $tname ); ?>">ویرایش</button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
				<p>هنوز قالبی ذخیره نشده است.</p>
			<?php endif; ?>
		</div>
		<style>
			.nptst { max-width: 1100px; margin-top: 12px; }
			.nptst-box { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; max-width: 720px; }
			.nptst-box h2 { margin-top: 0; }
			.nptst-box label { display: block; font-weight: 600; margin: 10px 0 3px; }
			.nptst-box input[type=text], .nptst-box textarea { width: 100%; max-width: 640px; }
			.nptst-t { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dcdcde; }
			.nptst-t th, .nptst-t td { border-bottom: 1px solid #f0f0f1; padding: 8px 12px; text-align: right; font-size: 13px; }
			.nptst-t th { background: #f6f7f7; font-weight: 700; }
			@media (max-width:782px){ .nptst-t { display: block; overflow-x: auto; white-space: nowrap; } }
		</style>
		<script>
		(function () {
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var tpls = <?php echo wp_json_encode( $tpls ); ?>;
			var name = document.getElementById('nptst-name');
			var text = document.getElementById('nptst-text');
			var msg = document.getElementById('nptst-msg');
			function post(action) {
				var fd = new FormData();
				fd.append('action', action);
				fd.append('nonce', nonce);
				fd.append('name', name.value.trim());
				if (action === 'npt_sms_tpl_save') { fd.append('text', text.value.trim()); }
				return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
			}
			document.getElementById('nptst-save').addEventListener('click', function () {
				if (!name.value.trim()) { msg.textContent = 'نام قالب را وارد کنید.'; msg.style.color = '#b32d2e'; return; }
				if (!text.value.trim()) { msg.textContent = 'متن قالب خالی است.'; msg.style.color = '#b32d2e'; return; }
				post('npt_sms_tpl_save').then(function (res) {
					if (res && res.success) { location.reload(); }
					else { msg.textContent = (res && res.data && res.data.message) || 'خطا'; msg.style.color = '#b32d2e'; }
				});
			});
			document.getElementById('nptst-del').addEventListener('click', function () {
				if (!name.value.trim()) { msg.textContent = 'نام قالبی که می‌خواهید حذف کنید را وارد یا انتخاب کنید.'; msg.style.color = '#b32d2e'; return; }
				if (!confirm('قالب «' + name.value.trim() + '» حذف شود؟')) { return; }
				post('npt_sms_tpl_delete').then(function (res) { if (res && res.success) { location.reload(); } });
			});
			Array.prototype.forEach.call(document.querySelectorAll('.nptst-pick'), function (b) {
				b.addEventListener('click', function () {
					var k = b.getAttribute('data-name');
					name.value = k; text.value = tpls[k] || '';
					name.scrollIntoView({ behavior: 'smooth', block: 'center' });
				});
			});
			void tpls;
		})();
		</script>
		<?php
	}

	public static function render_contacts_panel( $back = '' ) {
		global $wpdb;
		$back_field = '' !== $back ? '<input type="hidden" name="npt_back" value="' . esc_url( $back ) . '">' : '';
		$base       = '' !== $back ? $back : self::settings_url( 'contacts' );
		$filter     = isset( $_GET['npt_grp'] ) ? sanitize_text_field( wp_unslash( $_GET['npt_grp'] ) ) : ''; // phpcs:ignore
		$q          = isset( $_GET['npt_q'] ) ? sanitize_text_field( wp_unslash( $_GET['npt_q'] ) ) : ''; // phpcs:ignore
		$add_open   = ! empty( $_GET['npt_add'] ); // phpcs:ignore
		$conds      = array();
		if ( '' !== $filter ) {
			$conds[] = $wpdb->prepare( 'grp = %s', $filter );
		}
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$conds[] = $wpdb->prepare( '(name LIKE %s OR family LIKE %s OR company LIKE %s OR phone LIKE %s)', $like, $like, $like, $like );
		}
		$where      = $conds ? ' WHERE ' . implode( ' AND ', $conds ) : '';
		$rows       = $wpdb->get_results( 'SELECT * FROM ' . self::table_contacts() . $where . ' ORDER BY name ASC, family ASC, id ASC LIMIT 500' ); // phpcs:ignore
		$groups     = $wpdb->get_col( 'SELECT DISTINCT grp FROM ' . self::table_contacts() . " WHERE grp <> '' ORDER BY grp ASC" ); // phpcs:ignore
		$total      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_contacts() ); // phpcs:ignore
		$edit_id    = isset( $_GET['npt_edit'] ) ? absint( wp_unslash( $_GET['npt_edit'] ) ) : 0; // phpcs:ignore
		$edit_row   = $edit_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_contacts() . ' WHERE id = %d', $edit_id ) ) : null; // phpcs:ignore

		if ( isset( $_GET['npt_sms_msg'] ) ) { // phpcs:ignore
			$msg = sanitize_key( $_GET['npt_sms_msg'] ); // phpcs:ignore
			if ( 'imported' === $msg ) {
				$added   = isset( $_GET['added'] ) ? (int) $_GET['added'] : 0;     // phpcs:ignore
				$skipped = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0; // phpcs:ignore
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html( sprintf( 'ورود انجام شد: %d مخاطب اضافه شد، %d مورد رد شد (تکراری یا شماره نامعتبر).', $added, $skipped ) );
				echo '</p></div>';
			} elseif ( 'updated' === $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>مخاطب ویرایش شد.</p></div>';
			} elseif ( 'invalid' === $msg ) {
				echo '<div class="notice notice-error is-dismissible"><p>نام و شماره موبایل معتبر لازم است.</p></div>';
			} elseif ( 'duplicate' === $msg ) {
				echo '<div class="notice notice-error is-dismissible"><p>این شماره قبلاً ثبت شده است.</p></div>';
			} elseif ( 'file' === $msg ) {
				echo '<div class="notice notice-error is-dismissible"><p>فایلی انتخاب نشده بود؛ دوباره تلاش کنید.</p></div>';
			}
		}
		?>
		<style>
			.nptsc { max-width: 1100px; margin-top: 12px; }
			.nptsc-box { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; }
			.nptsc-box h2 { margin-top: 0; }
			.nptsc-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
			.nptsc-form label { display: block; font-weight: 600; margin-bottom: 3px; font-size: 12px; }
			.nptsc-t { width: 100%; border-collapse: collapse; margin-top: 6px; background: #fff; border: 1px solid #dcdcde; }
			.nptsc-t th, .nptsc-t td { border-bottom: 1px solid #f0f0f1; padding: 8px 12px; text-align: right; font-size: 13px; }
			.nptsc-t th { background: #f6f7f7; font-weight: 700; }
			.nptsc-t .del { color: #b32d2e; text-decoration: none; }
			.nptsc-groups { margin-top: 8px; color: #666; font-size: 12.5px; }
			.nptsc-fold { margin: 10px 0; } .nptsc-fold > summary { cursor: pointer; font-weight: 700; padding: 10px 14px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; list-style: none; }
			.nptsc-fold[open] > summary { margin-bottom: 10px; }
			.nptsc-search { display: flex; gap: 8px; margin: 12px 0; } .nptsc-search input[type=search] { flex: 1; min-height: 40px; }
			@media (max-width:782px){ .nptsc-form { display: block; } .nptsc-form > div { margin-bottom: 10px; } .nptsc-t { display: block; overflow-x: auto; white-space: nowrap; } }
		</style>
		<div class="nptsc">
			<?php
			$q_action = strtok( $base, '?' );
			parse_str( (string) wp_parse_url( $base, PHP_URL_QUERY ), $q_hidden );
			unset( $q_hidden['npt_q'], $q_hidden['npt_grp'], $q_hidden['npt_edit'], $q_hidden['npt_add'] );
			?>
			<form method="get" action="<?php echo esc_url( $q_action ); ?>" class="nptsc-search">
				<?php foreach ( $q_hidden as $hidden_key => $hidden_value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $hidden_key ); ?>" value="<?php echo esc_attr( $hidden_value ); ?>">
				<?php endforeach; ?>
				<input type="search" name="npt_q" value="<?php echo esc_attr( $q ); ?>" placeholder="جستجوی نام، شماره یا شرکت">
				<button type="submit" class="button">جستجو</button>
			</form>
			<?php if ( $edit_row ) : ?>
			<div class="nptsc-box">
				<h2>ویرایش مخاطب</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nptsc-form">
					<?php wp_nonce_field( 'npt_sms_contact_update_' . $edit_row->id, 'npt_sms_contact_update_nonce' ); ?>
					<input type="hidden" name="action" value="npt_sms_contact_update">
					<input type="hidden" name="npt_sms_edit_id" value="<?php echo esc_attr( $edit_row->id ); ?>">
					<?php echo $back_field; // phpcs:ignore ?>
					<div><label>نام</label><input type="text" name="npt_sms_edit_name" value="<?php echo esc_attr( $edit_row->name ); ?>" required></div>
					<div><label>نام خانوادگی</label><input type="text" name="npt_sms_edit_family" value="<?php echo esc_attr( $edit_row->family ); ?>"></div>
					<div><label>نام شرکت</label><input type="text" name="npt_sms_edit_company" value="<?php echo esc_attr( $edit_row->company ); ?>"></div>
					<div><label>موبایل</label><input type="text" name="npt_sms_edit_phone" dir="ltr" value="<?php echo esc_attr( $edit_row->phone ); ?>" required></div>
					<div><label>گروه</label><input type="text" name="npt_sms_edit_group" list="nptsc-group-list" value="<?php echo esc_attr( $edit_row->grp ); ?>"></div>
					<div><button type="submit" class="button button-primary">ذخیره تغییرات</button> <a href="<?php echo esc_url( remove_query_arg( 'npt_edit', $base ) ); ?>">انصراف</a></div>
				</form>
			</div>
			<?php endif; ?>

			<details class="nptsc-fold" id="npt-quick-contact"<?php echo $add_open ? ' open' : ''; ?>>
			<summary>＋ افزودن مخاطب</summary>
			<div class="nptsc-box">
				<h2>افزودن مخاطب</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nptsc-form">
					<?php wp_nonce_field( 'npt_sms_contact_add', 'npt_sms_contact_add_nonce' ); ?>
					<input type="hidden" name="action" value="npt_sms_contact_add">
					<?php echo $back_field; // phpcs:ignore ?>
					<div><label>نام</label><input type="text" name="npt_sms_new_name" required></div>
					<div><label>نام خانوادگی</label><input type="text" name="npt_sms_new_family"></div>
					<div><label>نام شرکت</label><input type="text" name="npt_sms_new_company"></div>
					<div><label>موبایل</label><input type="text" name="npt_sms_new_phone" dir="ltr" placeholder="09121234567" required></div>
					<div><label>گروه (اختیاری)</label><input type="text" name="npt_sms_new_group" list="nptsc-group-list" placeholder="مشتریان"></div>
					<datalist id="nptsc-group-list">
						<?php foreach ( $groups as $g ) : ?><option value="<?php echo esc_attr( $g ); ?>"></option><?php endforeach; ?>
					</datalist>
					<div><button type="submit" class="button button-primary">افزودن</button></div>
				</form>
			</div>

			</details>
			<details class="nptsc-fold" id="npt-import">
			<summary>ورود گروهی (فایل مخاطبین، CSV یا لیست شماره‌ها)</summary>
			<div class="nptsc-box">
				<h2>ورود گروهی (فایل مخاطبین گوشی، CSV یا لیست شماره‌ها)</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="nptsc-form">
					<?php wp_nonce_field( 'npt_sms_import', 'npt_sms_import_nonce' ); ?>
					<input type="hidden" name="action" value="npt_sms_import">
					<?php echo $back_field; // phpcs:ignore ?>
					<div><label>فایل مخاطبین گوشی (‎.vcf‎) یا CSV (ستون‌ها: نام، موبایل، گروه، نام خانوادگی، نام شرکت)</label><input type="file" name="npt_sms_csv" accept=".vcf,.csv,text/vcard,text/x-vcard,text/csv"></div>
					<div><label>یا شماره‌ها را اینجا بچسبانید (هر خط یک مخاطب: «نام 09121234567» یا فقط شماره)</label><textarea name="npt_sms_paste" rows="4" dir="auto" placeholder="علی رضایی 09121234567&#10;09351234567"></textarea></div>
					<div><label>گروه پیش‌فرض برای ردیف‌های بدون گروه</label><input type="text" name="npt_sms_default_group" list="nptsc-group-list" placeholder="مثلاً مشتریان"></div>
					<div><button type="submit" class="button button-secondary">بارگذاری و افزودن</button></div>
				</form>
				<p class="description">شماره‌های تکراری خودکار رد می‌شوند. ردیف اول (عنوان ستون‌ها) اگر موبایل معتبر نداشته باشد نادیده گرفته می‌شود. گروه‌های فعلی: <?php echo esc_html( $groups ? implode( '، ', $groups ) : '—' ); ?></p>
			</div>

			</details>
			<?php
			// Group filter: one link per group, the active one highlighted.
			$all_link = remove_query_arg( 'npt_grp', $base );
			?>
			<p class="nptsc-groups">
				<a href="<?php echo esc_url( $all_link ); ?>"<?php echo '' === $filter ? ' style="font-weight:700"' : ''; ?>><?php echo esc_html( sprintf( 'همه (%s)', npt_to_persian_digits( (string) $total ) ) ); ?></a>
				<?php foreach ( $groups as $g ) : ?>
					| <a href="<?php echo esc_url( add_query_arg( 'npt_grp', $g, $base ) ); ?>"<?php echo $filter === $g ? ' style="font-weight:700"' : ''; ?>><?php echo esc_html( $g ); ?></a>
				<?php endforeach; ?>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php echo '' === $filter ? 'هنوز مخاطبی ثبت نشده است.' : 'مخاطبی در این گروه نیست.'; ?></p>
			<?php else : ?>
			<table class="nptsc-t">
				<thead>
					<tr>
						<th>نام</th>
						<th>نام خانوادگی</th>
						<th>نام شرکت</th>
						<th>موبایل</th>
						<th>گروه</th>
						<th>تاریخ افزودن</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->name ); ?></td>
						<td><?php echo esc_html( $r->family ? $r->family : '—' ); ?></td>
						<td><?php echo esc_html( $r->company ? $r->company : '—' ); ?></td>
						<td dir="ltr"><?php echo esc_html( npt_to_persian_digits( $r->phone ) ); ?></td>
						<td><?php echo esc_html( $r->grp ? $r->grp : '—' ); ?></td>
						<td><?php echo npt_date_html( $r->created_at ); ?></td>
						<td><a href="<?php echo esc_url( add_query_arg( 'npt_edit', $r->id, $base ) ); ?>">ویرایش</a> · <a class="del" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_filter( array( 'action' => 'npt_sms_contact_delete', 'id' => $r->id, 'npt_back' => $back ) ), admin_url( 'admin-post.php' ) ), 'npt_sms_del_' . $r->id ) ); ?>" onclick="return confirm('این مخاطب حذف شود؟');">حذف</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="nptsc-groups"><?php echo esc_html( sprintf( 'نمایش %d مخاطب.', count( $rows ) ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_send() {
		global $wpdb;
		$groups = $wpdb->get_col( 'SELECT DISTINCT grp FROM ' . self::table_contacts() . " WHERE grp <> '' ORDER BY grp ASC" ); // phpcs:ignore
		$total  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_contacts() ); // phpcs:ignore
		$pick_rows = $wpdb->get_results( 'SELECT id, name, family, phone FROM ' . self::table_contacts() . ' ORDER BY name ASC LIMIT 1000' ); // phpcs:ignore
		?>
		<style>
			.nptss { max-width: 760px; margin-top: 12px; }
			.nptss-box { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; }
			.nptss label { display: block; font-weight: 600; margin-bottom: 4px; }
			.nptss textarea { width: 100%; }
			.nptss .meta { color: #666; font-size: 12.5px; margin: 8px 0 0; }
			.nptss #nptss-bar { height: 22px; background: #f0f0f1; border-radius: 999px; overflow: hidden; margin-top: 12px; display: none; }
			.nptss #nptss-fill { height: 100%; width: 0; background: #2271b1; transition: width .2s; }
			.nptss #nptss-status { margin-top: 8px; font-weight: 700; }
		</style>
		<div class="nptss">
			<?php if ( ! $total ) : ?>
				<p>ابتدا از تب «مخاطبین» چند مخاطب اضافه یا وارد کنید.</p>
			<?php else : ?>
			<div class="nptss-box">
				<div style="margin-bottom:12px">
					<label for="nptss-group">گروه گیرندگان</label>
					<select id="nptss-group">
						<option value="">همه مخاطبین (<?php echo esc_html( npt_to_persian_digits( (string) $total ) ); ?> نفر)</option>
						<?php foreach ( $groups as $g ) : ?><option value="<?php echo esc_attr( $g ); ?>"><?php echo esc_html( $g ); ?></option><?php endforeach; ?>
					</select>
					<span id="nptss-count" style="margin-inline-start:10px;color:#666"></span>
				</div>
				<div style="margin-bottom:12px">
					<label for="nptss-pick">یا مخاطب‌های مشخصی را انتخاب کنید (Ctrl یا ⌘ برای چند انتخاب)</label>
					<select id="nptss-pick" multiple size="6" style="width:100%">
						<?php foreach ( $pick_rows as $pr ) : ?>
							<option value="<?php echo esc_attr( $pr->id ); ?>"><?php echo esc_html( trim( $pr->name . ' ' . $pr->family ) . ' — ' . npt_to_persian_digits( $pr->phone ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="meta">اگر مخاطبی انتخاب شود، گروه بالا نادیده گرفته می‌شود.</p>
				</div>
				<div style="margin-bottom:12px">
					<label for="nptss-tpl">قالب‌های ذخیره‌شده</label>
					<select id="nptss-tpl">
						<option value="">— متن دلخواه —</option>
						<?php foreach ( self::templates() as $tname => $ttext ) : ?>
							<option value="<?php echo esc_attr( $tname ); ?>"><?php echo esc_html( $tname ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<label for="nptss-text">متن پیامک</label>
				<textarea id="nptss-text" rows="4" maxlength="500" placeholder="سلام {fullname} عزیز، لیست قیمت جدید سیم و کابل نوید به‌روزرسانی شد.">سلام {fullname} عزیز، لیست قیمت جدید سیم و کابل نوید به‌روزرسانی شد.</textarea>
				<p class="nptss-vars">
					<span>درج متغیر:</span>
					<?php foreach ( self::template_vars() as $var => $var_label ) : ?>
						<button type="button" class="button button-small nptss-var" data-var="<?php echo esc_attr( $var ); ?>"><?php echo esc_html( $var_label . ' ' . $var ); ?></button>
					<?php endforeach; ?>
				</p>
				<p class="meta">ارسال به‌صورت دسته‌های <?php echo esc_html( npt_to_persian_digits( (string) self::BATCH ) ); ?> تایی انجام می‌شود و نتیجه هر نفر در تب «گزارش» ثبت می‌گردد. متغیرها با مشخصات هر مخاطب جایگزین می‌شوند؛ اگر متنی بدون متغیر باشد، همه یک‌جا و یک متن دریافت می‌کنند.</p>
				<script>
				(function () {
					var ta = document.getElementById('nptss-text');
					if (! ta) { return; }
					Array.prototype.forEach.call(document.querySelectorAll('.nptss-var'), function (b) {
						b.addEventListener('click', function () {
							var v = b.getAttribute('data-var');
							var s = ta.selectionStart || 0, e = ta.selectionEnd || 0;
							ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
							ta.focus();
							ta.setSelectionRange(s + v.length, s + v.length);
						});
					});
				}());
				</script>
				<p style="margin-bottom:0">
					<input type="text" id="nptss-tpl-name" placeholder="نام قالب (مثلاً: اطلاع‌رسانی قیمت جدید)" style="max-width:280px">
					<button type="button" class="button" id="nptss-tpl-save">ذخیره متن فعلی به‌عنوان قالب</button>
					<button type="button" class="button" id="nptss-tpl-del">حذف قالب انتخاب‌شده</button>
					<span id="nptss-tpl-msg" style="margin-inline-start:8px;font-weight:700"></span>
				</p>
				<p><button type="button" class="button button-primary" id="nptss-go">ارسال</button></p>
				<div id="nptss-bar"><div id="nptss-fill"></div></div>
				<div id="nptss-status"></div>
			</div>
			<script>
			(function () {
				var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
				var fa = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
				var group = document.getElementById('nptss-group');
				var countEl = document.getElementById('nptss-count');
				var statusEl = document.getElementById('nptss-status');
				var fill = document.getElementById('nptss-fill');
				var bar = document.getElementById('nptss-bar');
				var btn = document.getElementById('nptss-go');
				var queue = [];

				var pick = document.getElementById('nptss-pick');

				function refreshCount() {
					var chosen = pick ? Array.prototype.filter.call(pick.options, function (o) { return o.selected; }) : [];
					if (chosen.length) {
						queue = chosen.map(function (o) { return { id: o.value }; });
						countEl.textContent = fa(queue.length) + ' گیرنده (انتخاب دستی)';
						return;
					}
					var fd = new FormData();
					fd.append('action', 'npt_sms_queue');
					fd.append('nonce', nonce);
					fd.append('group', group.value);
					fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							queue = (res && res.success) ? res.data.contacts : [];
							countEl.textContent = fa(queue.length) + ' گیرنده' + ((res && res.success && res.data.limited) ? ' (سقف ۲۰۰۰ نفر؛ برای بقیه گروه را جداگانه انتخاب کنید)' : '');
						});
				}
				group.addEventListener('change', refreshCount);
				if (pick) { pick.addEventListener('change', refreshCount); }
				refreshCount();

				var tpls = <?php echo wp_json_encode( self::templates() ); ?>;
				var tplSel = document.getElementById('nptss-tpl');
				var tplName = document.getElementById('nptss-tpl-name');
				var tplMsg = document.getElementById('nptss-tpl-msg');

				function renderTplSelect() {
					var current = tplSel.value;
					tplSel.innerHTML = '<option value="">— متن دلخواه —</option>';
					Object.keys(tpls).forEach(function (n) {
						var o = document.createElement('option');
						o.value = n;
						o.textContent = n;
						tplSel.appendChild(o);
					});
					tplSel.value = tpls[current] ? current : '';
				}

				tplSel.addEventListener('change', function () {
					if (tplSel.value && tpls[tplSel.value]) {
						document.getElementById('nptss-text').value = tpls[tplSel.value];
						tplName.value = tplSel.value;
					}
				});

				function tplPost(action, name, text) {
					var fd = new FormData();
					fd.append('action', action);
					fd.append('nonce', nonce);
					fd.append('name', name);
					if (text !== undefined) { fd.append('text', text); }
					return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
				}

				document.getElementById('nptss-tpl-save').addEventListener('click', function () {
					var name = tplName.value.trim();
					var text = document.getElementById('nptss-text').value.trim();
					if (!name) { tplMsg.textContent = 'نام قالب را وارد کنید.'; tplMsg.style.color = '#b32d2e'; return; }
					if (!text) { tplMsg.textContent = 'متن پیامک خالی است.'; tplMsg.style.color = '#b32d2e'; return; }
					tplPost('npt_sms_tpl_save', name, text).then(function (res) {
						if (res && res.success) {
							tpls = res.data.templates;
							renderTplSelect();
							tplSel.value = name;
							tplMsg.textContent = 'قالب «' + name + '» ذخیره شد.';
							tplMsg.style.color = '#00a32a';
						} else {
							tplMsg.textContent = (res && res.data && res.data.message) || 'خطا';
							tplMsg.style.color = '#b32d2e';
						}
					});
				});

				document.getElementById('nptss-tpl-del').addEventListener('click', function () {
					var name = tplSel.value;
					if (!name) { tplMsg.textContent = 'ابتدا یک قالب را انتخاب کنید.'; tplMsg.style.color = '#b32d2e'; return; }
					if (!confirm('قالب «' + name + '» حذف شود؟')) { return; }
					tplPost('npt_sms_tpl_delete', name).then(function (res) {
						if (res && res.success) {
							tpls = res.data.templates;
							renderTplSelect();
							tplMsg.textContent = 'قالب حذف شد.';
							tplMsg.style.color = '';
						}
					});
				});

				function postBatch(ids, text) {
					var fd = new FormData();
					fd.append('action', 'npt_sms_send_batch');
					fd.append('nonce', nonce);
					fd.append('text', text);
					fd.append('ids', JSON.stringify(ids));
					return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
				}

				btn.addEventListener('click', function () {
					var text = document.getElementById('nptss-text').value.trim();
					if (!queue.length) { statusEl.textContent = 'گیرنده‌ای برای این گروه نیست.'; statusEl.style.color = '#b32d2e'; return; }
					if (!text) { statusEl.textContent = 'متن پیامک را بنویسید.'; statusEl.style.color = '#b32d2e'; return; }
					if (!confirm('پیامک به ' + fa(queue.length) + ' نفر ارسال شود؟')) { return; }
					btn.disabled = true;
					bar.style.display = 'block';
					var total = queue.length, done = 0, okN = 0, failN = 0;

					function step() {
						if (!queue.length) {
							btn.disabled = false;
							statusEl.textContent = 'پایان ارسال — موفق: ' + fa(okN) + ' / ناموفق: ' + fa(failN) + ' (جزئیات در تب «گزارش»)';
							statusEl.style.color = failN ? '#996800' : '#00a32a';
							return;
						}
						var ids = queue.splice(0, <?php echo (int) self::BATCH; ?>).map(function (c) { return c.id; });
						postBatch(ids, text).then(function (res) {
							var ok = res && res.success && res.data.ok;
							if (ok) { okN += ids.length; } else { failN += ids.length; }
							done += ids.length;
							fill.style.width = Math.round(done * 100 / total) + '%';
							statusEl.textContent = 'ارسال شده: ' + fa(done) + ' از ' + fa(total) + (ok ? '' : ' — آخرین دسته: ' + ((res && res.data && res.data.msg) || 'خطا'));
							statusEl.style.color = '';
							setTimeout(step, 1000);
						}).catch(function () {
							failN += ids.length;
							done += ids.length;
							fill.style.width = Math.round(done * 100 / total) + '%';
							statusEl.textContent = 'خطا در ارتباط؛ ادامه...';
							setTimeout(step, 2000);
						});
					}
					step();
				});
			})();
			</script>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_report() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table_log() . ' ORDER BY id DESC LIMIT 200' ); // phpcs:ignore
		?>
		<style>
			.nptsr { max-width: 1100px; margin-top: 12px; }
			.nptsr-t { width: 100%; border-collapse: collapse; margin-top: 6px; background: #fff; border: 1px solid #dcdcde; }
			.nptsr-t th, .nptsr-t td { border-bottom: 1px solid #f0f0f1; padding: 8px 12px; text-align: right; font-size: 13px; }
			.nptsr-t th { background: #f6f7f7; font-weight: 700; }
			.nptsr-t .ok-yes { color: #00a32a; font-weight: 700; }
			.nptsr-t .ok-no { color: #b32d2e; font-weight: 700; }
		</style>
		<div class="nptsr">
			<?php if ( empty( $rows ) ) : ?>
				<p>هنوز پیامکی ارسال نشده است.</p>
			<?php else : ?>
			<table class="nptsr-t">
				<thead>
					<tr>
						<th>زمان</th>
						<th>نام</th>
						<th>موبایل</th>
						<th>وضعیت</th>
						<th>متن</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo npt_date_html( $r->created_at ); ?></td>
						<td><?php echo esc_html( $r->name ); ?></td>
						<td dir="ltr"><?php echo esc_html( npt_to_persian_digits( $r->phone ) ); ?></td>
						<td class="<?php echo $r->ok ? 'ok-yes' : 'ok-no'; ?>"><?php echo esc_html( $r->ok ? 'موفق' : 'ناموفق' ); ?><?php if ( ! $r->ok && $r->response ) : ?><br><small><?php echo esc_html( $r->response ); ?></small><?php endif; ?></td>
						<td><?php echo esc_html( wp_trim_words( $r->message, 12, '…' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php echo esc_html( sprintf( 'نمایش آخرین %d رکورد (از جدید به قدیم).', count( $rows ) ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

NPT_SMS::init();
