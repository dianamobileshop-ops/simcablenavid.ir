<?php
/**
 * Online proforma (پیش‌فاکتور) builder + customer archive.
 *
 * Navid used to write proformas by hand; this module moves the whole flow
 * into the WP admin: pick a customer (or type a new one), search the live
 * WooCommerce catalog, adjust quantities (units for normal products, meters
 * for meter-sold ones), apply a discount and print a branded RTL sheet —
 * while every proforma and its customer are archived in dedicated tables
 * (dbDelta-managed) for later reference.
 *
 * Security model:
 *  - the admin builder/AJAX endpoints require the table-edit capability and
 *    a nonce;
 *  - the print/share endpoint is public but only reachable through a
 *    32-hex random token stored per proforma (unguessable, no nonce needed:
 *    the URL is meant to be kept/sent around, and full-page caches may hold
 *    it for hours), and it is rate-limited like the other public endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPT_Proforma {

	const NONCE       = 'npt_proforma';
	const SEQ_OPTION  = 'npt_proforma_seq';
	const DB_VER_OPT  = 'npt_proforma_db_ver';
	const DB_VERSION  = 4;
	/** Lifecycle of an online proforma, in the panel's order. */
	const STATUSES    = array( 'pending' => 'در انتظار', 'called' => 'تماس گرفته', 'done' => 'تکمیل شده', 'converted' => 'تبدیل به فاکتور' );
	const FIRST_NUM   = 1001;

	public static function init() {
		// Proforma creation lives only in the business panel (/panel/, tab «پیش‌فاکتورها»).
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'wp_ajax_npt_proforma_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_npt_proforma_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_npt_customer_lookup', array( __CLASS__, 'ajax_customer_lookup' ) );
		add_action( 'wp_ajax_npt_proforma_print', array( __CLASS__, 'render_print' ) );
		add_action( 'wp_ajax_nopriv_npt_proforma_print', array( __CLASS__, 'render_print' ) );

		// Public (front-end shortcode) endpoints: no capability check, but a
		// guest nonce + tight per-IP rate limits + honeypot + server-side
		// pricing instead. Registered for both logged-in and guest visitors
		// so a logged-in non-editor can also use the public form.
		add_action( 'wp_ajax_npt_proforma_search_pub', array( __CLASS__, 'ajax_search_public' ) );
		add_action( 'wp_ajax_nopriv_npt_proforma_search_pub', array( __CLASS__, 'ajax_search_public' ) );
		add_action( 'wp_ajax_npt_proforma_save_pub', array( __CLASS__, 'ajax_save_public' ) );
		add_action( 'wp_ajax_nopriv_npt_proforma_save_pub', array( __CLASS__, 'ajax_save_public' ) );

		add_shortcode( 'navid_proforma', array( __CLASS__, 'shortcode' ) );

		// Short public link for a proforma: /pf/{code}/ → the print view.
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_short' ), 0 );
		add_action( 'wp_ajax_npt_pf_sms', array( __CLASS__, 'ajax_pf_sms' ) );
		add_action( 'wp_ajax_npt_pf_verify', array( __CLASS__, 'ajax_pf_verify' ) );
		add_action( 'wp_ajax_nopriv_npt_pf_verify', array( __CLASS__, 'ajax_pf_verify' ) );
		add_action( 'wp_ajax_npt_pf_delete', array( __CLASS__, 'ajax_pf_delete' ) );
	}

	public static function table_customers() {
		global $wpdb;
		return $wpdb->prefix . 'npt_proforma_customers';
	}

	public static function table_proformas() {
		global $wpdb;
		return $wpdb->prefix . 'npt_proformas';
	}

	/**
	 * Create/upgrade the tables once per schema version. Doing this on
	 * admin_init behind a version guard avoids activation-hook ordering
	 * issues and still re-runs whenever the schema changes.
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VER_OPT, 0 ) >= self::DB_VERSION ) {
			return;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$customers = 'CREATE TABLE ' . self::table_customers() . " (\n"
			. "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
			. "name varchar(190) NOT NULL DEFAULT '',\n"
			. "phone varchar(20) NOT NULL DEFAULT '',\n"
			. "company varchar(190) NOT NULL DEFAULT '',\n"
			. "email varchar(190) NOT NULL DEFAULT '',\n"
			. "address text,\n"
			. "created_at datetime NOT NULL,\n"
			. "PRIMARY KEY  (id),\n"
			. "KEY phone (phone)\n"
			. ") $charset;\n";

		$proformas = 'CREATE TABLE ' . self::table_proformas() . " (\n"
			. "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
			. "number bigint(20) unsigned NOT NULL DEFAULT 0,\n"
			. "token varchar(32) NOT NULL DEFAULT '',\n"
			. "short varchar(16) NOT NULL DEFAULT '',\n"
			. "customer_id bigint(20) unsigned NOT NULL DEFAULT 0,\n"
			. "items longtext,\n"
			. "subtotal double NOT NULL DEFAULT 0,\n"
			. "discount double NOT NULL DEFAULT 0,\n"
			. "total double NOT NULL DEFAULT 0,\n"
			. "validity_days smallint(5) unsigned NOT NULL DEFAULT 7,\n"
			. "note text,\n"
			. "source varchar(10) NOT NULL DEFAULT 'admin',\n"
			. "status varchar(20) NOT NULL DEFAULT 'pending',\n"
			. "created_by bigint(20) unsigned NOT NULL DEFAULT 0,\n"
			. "created_at datetime NOT NULL,\n"
			. "PRIMARY KEY  (id),\n"
			. "KEY token (token),\n"
			. "KEY short (short),\n"
			. "KEY customer_id (customer_id)\n"
			. ") $charset;\n";

		dbDelta( $customers );
		dbDelta( $proformas );
		update_option( self::DB_VER_OPT, self::DB_VERSION, false );
	}

	/**
	 * Normalize an Iranian mobile number: Persian/Arabic digits → Latin,
	 * strip separators, 0098/98 prefixes → 0. Returns '' when the result is
	 * not a plausible 11-digit 09… number.
	 */
	public static function normalize_phone( $raw ) {
		$digits = strtr( trim( (string) $raw ), array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		) );
		$digits = preg_replace( '/[^0-9]/', '', $digits );
		if ( 0 === strpos( $digits, '0098' ) ) {
			$digits = '0' . substr( $digits, 4 );
		} elseif ( 0 === strpos( $digits, '98' ) && 12 === strlen( $digits ) ) {
			$digits = '0' . substr( $digits, 2 );
		}
		if ( ! preg_match( '/^09\d{9}$/', $digits ) ) {
			return '';
		}
		return $digits;
	}

	/* ---------------------------------------------------------------------
	 * AJAX: product search for the builder (admin + public shortcode)
	 * ------------------------------------------------------------------- */
	public static function ajax_search() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! npt_current_user_can_edit() || ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی لازم را ندارید.' ), 403 );
		}

		wp_send_json_success( array( 'results' => self::search_products( self::query_arg() ) ) );
	}

	/**
	 * Public search: same catalog data the price table already exposes, so
	 * no capability gate — but nonce + a per-IP budget keep it from being
	 * used as a scraping hammer.
	 */
	public static function ajax_search_public() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'نشست شما منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.' ), 403 );
		}

		if ( ! function_exists( 'wc_get_product' ) || ! npt_pdf_rate_limit_ok( 'pf_search' ) ) {
			wp_send_json_error( array( 'message' => 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.' ), 429 );
		}

		wp_send_json_success( array( 'results' => self::search_products( self::query_arg() ) ) );
	}

	private static function query_arg() {
		return isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
	}

	/** Persian/Arabic letters and digits to one form, so «ي»/«ی» and «۱۲» match «١٢» and «12». */
	private static function normalize_query( $q ) {
		$q = str_replace( array( "\xE2\x80\x8C", "\xE2\x80\x8D" ), '', (string) $q );
		$q = strtr( $q, array( 'ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه' ) );
		$q = strtr( $q, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
		return trim( preg_replace( '/\s+/u', ' ', $q ) );
	}

	private static function search_products( $q ) {
		$q = self::normalize_query( $q );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		global $wpdb;
		// Every word must appear in the title (any order), or the whole text must match a SKU.
		// Exact and «starts with» titles come first, so the wanted item is never cut off.
		$words = array_values( array_filter( explode( ' ', $q ) ) );
		$args  = array();
		$conds = array();
		foreach ( $words as $w ) {
			$conds[] = 'p.post_title LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $w ) . '%';
		}
		$args[] = '%' . $wpdb->esc_like( $q ) . '%';
		$args[] = $q;
		$args[] = $wpdb->esc_like( $q ) . '%';
		$sql = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'"
			. " WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( ( " . implode( ' AND ', $conds ) . " ) OR m.meta_value LIKE %s )"
			. ' GROUP BY p.ID ORDER BY ( p.post_title = %s ) DESC, ( p.post_title LIKE %s ) DESC, p.post_title ASC LIMIT 60';
		$ids   = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
		$posts = array();
		foreach ( array_map( 'absint', (array) $ids ) as $pid ) {
			$post = get_post( $pid );
			if ( $post ) {
				$posts[] = $post;
			}
		}

		$results = array();
		foreach ( $posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}
			$price = $product->get_price();
			if ( '' === $price || ! is_numeric( $price ) ) {
				continue;
			}
			// First category name, so the dropdown can show *where* each hit
			// lives — with hits from many categories visible at once.
			$terms = get_the_terms( $post->ID, 'product_cat' );
			$cat   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
			$results[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'price' => (float) $price,
				'meter' => (bool) npt_meter_is_meter_product( $product->get_id() ),
				'cat'   => $cat,
				'sku'   => (string) $product->get_sku(),
			);
		}

		return $results;
	}

	/**
	 * Jalali date (day-first) of "today + N days", for the اعتبار field on
	 * the print sheet.
	 */
	public static function validity_date( $days ) {
		$ts = current_time( 'timestamp' ) + max( 0, (int) $days ) * DAY_IN_SECONDS;
		// Plain date(), NOT date_i18n(): the Persian locale makes date_i18n()
		// return Jalali values, which the converter then shifted by -621 years.
		list( $jy, $jm, $jd ) = npt_gregorian_to_jalali(
			(int) date( 'Y', $ts ),
			(int) date( 'n', $ts ),
			(int) date( 'j', $ts )
		);
		return npt_to_persian_digits( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: save proforma + upsert customer (admin)
	 * ------------------------------------------------------------------- */
	public static function ajax_save() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! npt_current_user_can_edit() ) {
			wp_send_json_error( array( 'message' => 'دسترسی لازم را ندارید.' ), 403 );
		}

		$name    = isset( $_POST['c_name'] ) ? sanitize_text_field( wp_unslash( $_POST['c_name'] ) ) : '';
		$phone   = self::normalize_phone( isset( $_POST['c_phone'] ) ? wp_unslash( $_POST['c_phone'] ) : '' );
		$company = isset( $_POST['c_company'] ) ? sanitize_text_field( wp_unslash( $_POST['c_company'] ) ) : '';
		$email   = isset( $_POST['c_email'] ) ? sanitize_email( wp_unslash( $_POST['c_email'] ) ) : '';
		$address = isset( $_POST['c_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['c_address'] ) ) : '';
		$note    = isset( $_POST['p_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['p_note'] ) ) : '';

		if ( '' === trim( $name ) ) {
			wp_send_json_error( array( 'message' => 'نام مشتری الزامی است.' ) );
		}
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => 'شماره موبایل معتبر وارد کنید (مثال: 09121234567).' ) );
		}

		$raw_items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items     = json_decode( (string) $raw_items, true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( array( 'message' => 'حداقل یک قلم کالا اضافه کنید.' ) );
		}

		// The seller edits names/prices by design, so the admin path trusts
		// the submitted rows — but totals are always recomputed server-side.
		$clean = array();
		foreach ( $items as $item ) {
			$label = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
			$qty   = isset( $item['qty'] ) ? (float) $item['qty'] : 0.0;
			$price = isset( $item['price'] ) ? (float) $item['price'] : 0.0;
			$price = $price / npt_currency_factor();
			$meter = ! empty( $item['meter'] );
			if ( '' === $label || $qty <= 0 || $price < 0 ) {
				continue;
			}
			$clean[] = array(
				'name'  => $label,
				'qty'   => $qty,
				'price' => $price,
				'meter' => $meter,
			);
		}
		if ( empty( $clean ) ) {
			wp_send_json_error( array( 'message' => 'هیچ قلم معتبری در فهرست نیست (مقدار باید بزرگ‌تر از صفر باشد).' ) );
		}

		$discount = isset( $_POST['discount'] ) ? (float) wp_unslash( $_POST['discount'] ) / npt_currency_factor() : 0.0; // phpcs:ignore
		$validity = isset( $_POST['validity_days'] ) ? max( 0, (int) wp_unslash( $_POST['validity_days'] ) ) : 7;
		$validity = min( $validity, 365 );

		$saved = self::persist_proforma(
			array( 'name' => $name, 'phone' => $phone, 'company' => $company, 'email' => $email, 'address' => $address ),
			$clean, $discount, $validity, $note, 'admin'
		);
		if ( ! $saved ) {
			NPT_Log::add( 'پیش‌فاکتور', 'ذخیره‌سازی ناموفق بود.' );
			wp_send_json_error( array( 'message' => 'ذخیره‌سازی ناموفق بود. دوباره تلاش کنید.' ) );
		}
		NPT_Log::activity( 'ثبت پیش‌فاکتور #' . $saved['number'] . ' برای ' . $name );
		wp_send_json_success( self::save_response( $saved ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: save proforma from the public shortcode
	 *
	 * Trusts NOTHING from the visitor except quantities and their own
	 * contact details: every line is re-priced from the live WooCommerce
	 * product, discount is forced to zero and validity to the default —
	 * a visitor must not be able to hand themselves a price or a discount.
	 * ------------------------------------------------------------------- */
	public static function ajax_save_public() {
		// A full-page cache can serve a guest nonce that has since expired, so
		// the failure gets a message that tells the visitor to reload instead
		// of the bare "-1" wp_die() response.
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'نشست شما منقضی شده است. صفحه را تازه کنید و دوباره فرم را پر کنید.' ), 403 );
		}

		if ( ! npt_pdf_rate_limit_ok( 'pf_save' ) ) {
			wp_send_json_error( array( 'message' => 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.' ), 429 );
		}
		// Honeypot: invisible to real visitors, irresistible to bots.
		if ( '' !== trim( (string) ( isset( $_POST['npt_website'] ) ? wp_unslash( $_POST['npt_website'] ) : '' ) ) ) {
			wp_send_json_error( array( 'message' => 'خطا در اعتبارسنجی فرم.' ), 400 );
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_error( array( 'message' => 'فروشگاه فعال نیست.' ), 503 );
		}
		// Public visitors never trigger admin_init, so make sure the tables
		// (and the source column) exist before writing.
		self::maybe_install();

		$name    = isset( $_POST['c_name'] ) ? sanitize_text_field( wp_unslash( $_POST['c_name'] ) ) : '';
		$phone   = self::normalize_phone( isset( $_POST['c_phone'] ) ? wp_unslash( $_POST['c_phone'] ) : '' );
		$company = isset( $_POST['c_company'] ) ? sanitize_text_field( wp_unslash( $_POST['c_company'] ) ) : '';
		$email   = isset( $_POST['c_email'] ) ? sanitize_email( wp_unslash( $_POST['c_email'] ) ) : '';
		$address = isset( $_POST['c_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['c_address'] ) ) : '';
		$note    = isset( $_POST['p_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['p_note'] ) ) : '';

		if ( '' === trim( $name ) ) {
			wp_send_json_error( array( 'message' => 'نام و نام خانوادگی الزامی است.' ) );
		}
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => 'شماره موبایل معتبر وارد کنید (مثال: 09121234567).' ) );
		}

		$raw_items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items     = json_decode( (string) $raw_items, true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( array( 'message' => 'حداقل یک قلم کالا اضافه کنید.' ) );
		}
		if ( count( $items ) > 60 ) {
			wp_send_json_error( array( 'message' => 'تعداد اقلام بیش از حد مجاز است.' ) );
		}

		$clean = array();
		foreach ( $items as $item ) {
			$pid = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$qty = isset( $item['qty'] ) ? (float) $item['qty'] : 0.0;
			if ( ! $pid || $qty <= 0 || $qty > 1000000 ) {
				continue;
			}
			$product = wc_get_product( $pid );
			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}
			$price = $product->get_price();
			if ( '' === $price || ! is_numeric( $price ) ) {
				continue;
			}
			$clean[] = array(
				'name'  => $product->get_name(),
				'qty'   => $qty,
				'price' => (float) $price,
				'meter' => (bool) npt_meter_is_meter_product( $pid ),
			);
		}
		if ( empty( $clean ) ) {
			wp_send_json_error( array( 'message' => 'هیچ قلم معتبری در فهرست نیست.' ) );
		}

		// Anti-spam: one phone cannot flood the archive, and a double submit or a
		// re-sent form does not create the same proforma twice.
		$total_now = 0.0;
		foreach ( $clean as $line ) {
			$total_now += $line['qty'] * $line['price'];
		}
		if ( self::site_recent_duplicate( $phone, $total_now ) ) {
			wp_send_json_error( array( 'message' => 'یک پیش‌فاکتور با همین مشخصات چند دقیقه پیش ثبت شده است. اگر لازم است، با فروشگاه تماس بگیرید.' ), 409 );
		}
		if ( self::site_count_last_day( $phone ) >= self::SITE_DAILY_LIMIT ) {
			wp_send_json_error( array( 'message' => 'تعداد پیش‌فاکتورهای ثبت‌شده برای این شماره در امروز به حد مجاز رسیده است. با فروشگاه تماس بگیرید.' ), 429 );
		}

		$saved = self::persist_proforma(
			array( 'name' => $name, 'phone' => $phone, 'company' => $company, 'email' => $email, 'address' => $address ),
			$clean, 0.0, 7, $note, 'site'
		);
		if ( ! $saved ) {
			NPT_Log::add( 'پیش‌فاکتور', 'ذخیره‌سازی ناموفق بود.' );
			wp_send_json_error( array( 'message' => 'ذخیره‌سازی ناموفق بود. دوباره تلاش کنید.' ) );
		}

		// Answer the visitor first, then tell the owner (SMS / push take a
		// second or two and must not make the customer wait).
		npt_finish_json_then( array( 'success' => true, 'data' => self::save_response( $saved ) ), function () use ( $saved, $name, $phone ) {
			do_action( 'npt_proforma_created', $saved, array(
				'name'   => $name,
				'phone'  => $phone,
				'total'  => $saved['total'],
				'source'   => 'site',
				'verified' => NPT_OTP::is_verified( $phone ),
			) );
		} );
	}

	const SITE_DAILY_LIMIT = 5;

	/** A site proforma for this phone with the same total in the last 10 minutes. */
	private static function site_recent_duplicate( $phone, $total ) {
		global $wpdb;
		$since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 10 * MINUTE_IN_SECONDS ); // phpcs:ignore
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT p.id FROM ' . self::table_proformas() . ' p JOIN ' . self::table_customers() . ' c ON c.id = p.customer_id WHERE c.phone = %s AND p.source = %s AND p.created_at >= %s AND ABS(p.total - %f) < 1 LIMIT 1', // phpcs:ignore
			$phone, 'site', $since, (float) $total
		) );
	}

	private static function site_count_last_day( $phone ) {
		global $wpdb;
		$since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ); // phpcs:ignore
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table_proformas() . ' p JOIN ' . self::table_customers() . ' c ON c.id = p.customer_id WHERE c.phone = %s AND p.source = %s AND p.created_at >= %s', // phpcs:ignore
			$phone, 'site', $since
		) );
	}

	/** Panel only: fill the builder from a customer the archive already knows. */
	public static function ajax_customer_lookup() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! npt_current_user_can_edit() ) {
			wp_send_json_error( array( 'message' => 'دسترسی لازم را ندارید.' ), 403 );
		}
		$phone = self::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' ); // phpcs:ignore
		if ( '' === $phone ) {
			wp_send_json_success( array( 'found' => false ) );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT name, company, email, address FROM ' . self::table_customers() . ' WHERE phone = %s LIMIT 1', $phone ) ); // phpcs:ignore
		if ( ! $row ) {
			wp_send_json_success( array( 'found' => false ) );
		}
		wp_send_json_success( array(
			'found'   => true,
			'name'    => (string) $row->name,
			'company' => (string) $row->company,
			'email'   => (string) $row->email,
			'address' => (string) $row->address,
		) );
	}

	/**
	 * Shared write path: upsert the customer by phone, take the next
	 * proforma number, store the row. Both endpoints funnel through here
	 * so the archive stays consistent no matter who creates the proforma.
	 */
	private static function persist_proforma( $customer, $clean_items, $discount, $validity, $note, $source ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$subtotal = 0.0;
		foreach ( $clean_items as $item ) {
			$subtotal += (float) $item['qty'] * (float) $item['price'];
		}
		$discount = max( 0.0, min( (float) $discount, $subtotal ) );

		// Upsert the customer by phone (the archive's dedupe key).
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table_customers() . ' WHERE phone = %s LIMIT 1', // phpcs:ignore
			$customer['phone']
		) );
		if ( $existing && 'admin' !== $source ) {
			// A public visitor only proves they typed a number, not that they own
			// it, so they must never overwrite the stored details of an existing
			// customer. Only blanks are filled in.
			$customer_id = (int) $existing;
			$row         = $wpdb->get_row( $wpdb->prepare(
				'SELECT company, email, address FROM ' . self::table_customers() . ' WHERE id = %d', // phpcs:ignore
				$customer_id
			), ARRAY_A );
			$fill = array();
			foreach ( array( 'company', 'email', 'address' ) as $field ) {
				if ( $row && '' === trim( (string) $row[ $field ] ) && '' !== $customer[ $field ] ) {
					$fill[ $field ] = $customer[ $field ];
				}
			}
			if ( $fill ) {
				$wpdb->update( self::table_customers(), $fill, array( 'id' => $customer_id ) );
			}
		} elseif ( $existing ) {
			$customer_id = (int) $existing;
			$wpdb->update(
				self::table_customers(),
				array(
					'name'    => $customer['name'],
					'company' => $customer['company'],
					'email'   => $customer['email'],
					'address' => $customer['address'],
				),
				array( 'id' => $customer_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				self::table_customers(),
				array(
					'name'       => $customer['name'],
					'phone'      => $customer['phone'],
					'company'    => $customer['company'],
					'email'      => $customer['email'],
					'address'    => $customer['address'],
					'created_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$customer_id = (int) $wpdb->insert_id;
		}

		// Take the next number and insert the row under one DB lock, so two
		// simultaneous saves can never share a proforma number.
		$locked = (int) $wpdb->get_var( "SELECT GET_LOCK('npt_pf_seq', 5)" );
		$max    = (int) $wpdb->get_var( 'SELECT MAX(number) FROM ' . self::table_proformas() ); // phpcs:ignore
		$number = max( (int) get_option( self::SEQ_OPTION, self::FIRST_NUM ), $max + 1 );
		update_option( self::SEQ_OPTION, $number + 1, false );

		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->insert(
			self::table_proformas(),
			array(
				'number'        => $number,
				'token'         => $token,
				'customer_id'   => $customer_id,
				'items'         => wp_json_encode( $clean_items, JSON_UNESCAPED_UNICODE ),
				'subtotal'      => $subtotal,
				'discount'      => $discount,
				'total'         => $subtotal - $discount,
				'validity_days' => (int) $validity,
				'note'          => $note,
				'source'        => $source,
				'created_by'    => get_current_user_id(),
				'created_at'    => $now,
			),
			array( '%d', '%s', '%d', '%s', '%f', '%f', '%f', '%d', '%s', '%s', '%d', '%s' )
		);

		$id = (int) $wpdb->insert_id;
		$short = self::new_short();
		if ( $id ) {
			$wpdb->update( self::table_proformas(), array( 'short' => $short ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}
		if ( $locked ) {
			$wpdb->query( "DO RELEASE_LOCK('npt_pf_seq')" );
		}
		if ( ! $id ) {
			return null;
		}
		// Every new proforma also goes to the contacts list (duplicates are skipped there).
		NPT_SMS::save_customer_contact( $customer['name'], $customer['phone'] );
		return array( 'id' => $id, 'number' => $number, 'token' => $token, 'short' => $short, 'total' => $subtotal - $discount );
	}

	private static function save_response( $saved ) {
		return array(
			'number'    => npt_to_persian_digits( (string) $saved['number'] ),
			'id'        => (int) $saved['id'],
			'short_url' => self::short_url( $saved['short'] ),
			'print_url' => self::short_url( $saved['short'] ),
			'print_url_long' => add_query_arg( array(
				'action' => 'npt_proforma_print',
				'token'  => $saved['token'],
			), admin_url( 'admin-ajax.php' ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin page: builder / archive / customers
	 * ------------------------------------------------------------------- */
	/* ---------------------------------------------------------------------
	 * Public shortcode: [navid_proforma]
	 *
	 * Same builder as the admin tab, but visitors only choose products and
	 * quantities — prices come from the catalog, and discount/validity are
	 * not offered at all (the server forces them anyway).
	 * ------------------------------------------------------------------- */
	public static function shortcode( $atts = array() ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '<p>' . esc_html__( 'برای استفاده از فرم پیش‌فاکتور، افزونه ووکامرس باید فعال باشد.', 'navid-price-table' ) . '</p>';
		}
		self::maybe_install();
		ob_start();
		self::render_builder( '', true );
		return (string) ob_get_clean();
	}

	/** Builder for the business panel's «پیش‌فاکتور جدید» view. */
	public static function render_panel_builder() {
		self::maybe_install();
		self::render_builder( '' );
		?>
		<style>.nptpf-more { margin: 8px 0; } .nptpf-more summary { cursor: pointer; font-weight: 600; margin-bottom: 8px; }</style>
		<script>
		(function () {
			var phone = document.getElementById('nptpf-phone');
			if (! phone) { return; }
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var fields = { name: 'nptpf-name', company: 'nptpf-company', email: 'nptpf-email', address: 'nptpf-address' };
			phone.addEventListener('change', function () {
				var fd = new FormData();
				fd.append('action', 'npt_customer_lookup');
				fd.append('nonce', nonce);
				fd.append('phone', phone.value);
				fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (! res || ! res.success || ! res.data.found) { return; }
						Object.keys(fields).forEach(function (k) {
							var el = document.getElementById(fields[k]);
							if (el && ! el.value && res.data[k]) { el.value = res.data[k]; }
						});
					});
			});
		}());
		</script>
		<?php
	}

	private static function render_builder( $base, $public = false ) {
		// Prefill from the customers archive: ?pf_tab=new&customer=ID
		$prefill = array( 'name' => '', 'phone' => '', 'company' => '', 'email' => '', 'address' => '' );
		if ( ! $public && isset( $_GET['customer'] ) ) { // phpcs:ignore
			global $wpdb;
			$cid = (int) $_GET['customer']; // phpcs:ignore
			$row = $cid ? $wpdb->get_row( $wpdb->prepare( 'SELECT name, phone, company, email, address FROM ' . self::table_customers() . ' WHERE id = %d', $cid ) ) : null; // phpcs:ignore
			if ( $row ) {
				$prefill = array(
					'name'    => $row->name,
					'phone'   => $row->phone,
					'company' => $row->company,
					'email'   => $row->email,
					'address' => $row->address,
				);
			}
		}

		$unit_hint = 'IRR' === npt_currency_unit()
			? 'قیمت‌ها را به تومان وارد کنید؛ در چاپ، خودکار به ریال نمایش داده می‌شود.'
			: 'قیمت‌ها به تومان محاسبه و چاپ می‌شوند.';

		$hcss      = vsprintf( '#%02x%02x%02x', npt_header_color_rgb() );
		$acss      = vsprintf( '#%02x%02x%02x', npt_accent_color_rgb() );
		$save_text = $public ? 'ثبت و دریافت پیش‌فاکتور' : 'ذخیره و ساخت پیش‌فاکتور';
		?>
		<style>
			.nptpf { max-width: 1000px; }
			.nptpf-box { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 18px; margin: 14px 0; }
			.nptpf-box h2 { margin-top: 0; }
			.nptpf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
			.nptpf-grid label { display: block; font-weight: 600; margin-bottom: 4px; }
			.nptpf-grid input, .nptpf-grid textarea { width: 100%; }
			.nptpf-search { position: relative; max-width: 520px; }
			.nptpf-results { position: absolute; top: 100%; right: 0; left: 0; background: #fff; border: 1px solid #c3c4c7; border-radius: 0 0 6px 6px; box-shadow: 0 6px 14px rgba(0,0,0,.12); z-index: 50; max-height: 320px; overflow: auto; display: none; }
			.nptpf-results button { display: block; width: 100%; text-align: right; background: none; border: none; border-bottom: 1px solid #f0f0f1; padding: 8px 12px; cursor: pointer; font-size: 13px; }
			.nptpf-results button:hover { background: #f0f6fc; }
			.nptpf-pct { display: inline-flex; align-items: center; gap: 6px; }
			.nptpf-pct button { width: 40px; height: 40px; border: 1px solid #c3c4c7; border-radius: 10px; background: #fff; font-size: 20px; font-weight: 700; cursor: pointer; }
			.nptpf-pct input { width: 80px; height: 40px; text-align: center; font-size: 17px; font-weight: 700; }
			.nptpf-results .p { color: #2271b1; font-weight: 700; }
			.nptpf-results .plus { display: inline-grid; width: 24px; height: 24px; border-radius: 50%; background: <?php echo esc_html( $acss ); ?>; color: #fff; place-items: center; font-weight: 700; margin-inline-end: 6px; vertical-align: middle; }
			.nptpf-results button { min-height: 40px; }
			.nptpf-results-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 10px; background: #f6f7f7; border-bottom: 1px solid #e3e5e8; font-size: 12px; }
			.nptpf-results-head .nptpf-close { width: auto; min-height: 34px; padding: 6px 12px; border-radius: 8px; background: #3c4a6b; color: #fff; border: 0; cursor: pointer; font: inherit; }
			.nptpf-results button.is-added { background: #eef8f0; }
			.nptpf-results .added { color: #14733b; font-weight: 700; font-size: 12px; }
			.nptpf-done-acts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
			.nptpf-done-acts a, .nptpf-done-acts button { display: inline-block; padding: 8px 14px; border-radius: 8px; background: <?php echo esc_html( $acss ); ?>; color: #fff; border: 0; font: inherit; font-size: 13px; text-decoration: none; cursor: pointer; }
			.nptpf-results .m { display: inline-block; margin-inline-start: 6px; padding: 0 7px; border-radius: 999px; background: #28a745; color: #fff; font-size: 10px; font-weight: 700; }
			.nptpf-results .c { display: inline-block; margin-inline-start: 6px; padding: 0 7px; border-radius: 999px; background: #e7eaf3; color: #3c4a6b; font-size: 10px; font-weight: 700; }
			.nptpf-results .s { direction: ltr; display: inline-block; margin-inline-start: 6px; color: #8a8a8a; font-size: 11px; }
			.nptpf-items { width: 100%; border-collapse: collapse; margin-top: 10px; }
			.nptpf-items th, .nptpf-items td { border: 1px solid #dcdcde; padding: 6px 8px; font-size: 13px; vertical-align: middle; }
			.nptpf-items input { width: 100%; min-width: 70px; }
			.nptpf-items .rm { color: #b32d2e; background: none; border: none; cursor: pointer; font-size: 16px; }
			.nptpf-totals { margin-top: 12px; font-size: 14px; }
			.nptpf-totals .row { display: flex; justify-content: space-between; max-width: 420px; margin-inline-start: auto; padding: 3px 0; }
			.nptpf-totals .grand { font-weight: 800; font-size: 16px; border-top: 2px solid #2271b1; margin-top: 6px; padding-top: 8px; }
			.nptpf-empty-row { color: #8a8a8a; text-align: center; }
			#nptpf-save { margin-top: 14px; height: 34px; padding: 0 26px; font-size: 14px; }
			.nptpf-done { margin-top: 14px; padding: 12px 16px; border-inline-start: 4px solid #00a32a; background: #edfaef; display: none; }
			.nptpf-done a { font-weight: 700; }
			<?php if ( $public ) : ?>
			/* Standalone front-end skin: the admin CSS above assumes WP-admin
			   input/button styling, which a theme's page does not provide. */
			@font-face { font-family: 'NPTPfVazir'; src: url('<?php echo esc_url( NPT_URL ); ?>vendor/fonts/Vazirmatn-Regular.ttf') format('truetype'); font-weight: 400; font-style: normal; font-display: swap; }
			@font-face { font-family: 'NPTPfVazir'; src: url('<?php echo esc_url( NPT_URL ); ?>vendor/fonts/Vazirmatn-Bold.ttf') format('truetype'); font-weight: 700; font-style: normal; font-display: swap; }
			.nptpf { direction: rtl; max-width: 880px; margin: 0 auto; font-family: 'NPTPfVazir', Tahoma, Arial, sans-serif; font-size: 14px; line-height: 1.9; color: #222; }
			.nptpf *, .nptpf *::before, .nptpf *::after { box-sizing: border-box; }
			.nptpf-box { border-color: #e6e9ec; box-shadow: 0 2px 10px rgba(10,25,60,.06); }
			.nptpf-box h2 { font-size: 16px; font-weight: 700; color: <?php echo esc_html( $hcss ); ?>; }
			.nptpf label { font-size: 13.5px; }
			.nptpf input, .nptpf textarea { font-family: inherit; font-size: 14px; color: #222; background: #fff; border: 1px solid #c9ced2; border-radius: 6px; padding: 8px 10px; }
			.nptpf input:focus, .nptpf textarea:focus { outline: none; border-color: <?php echo esc_html( $acss ); ?>; box-shadow: 0 0 0 2px rgba(0,0,0,.07); }
			.nptpf .description { color: #666; font-size: 12.5px; margin: 8px 0 0; }
			.nptpf-items-wrap { overflow-x: auto; }
			.nptpf-items { min-width: 560px; }
			@media (max-width: 600px) {
				.nptpf-items { min-width: 0; }
				.nptpf-items thead { display: none; }
				.nptpf-items tr:not(.nptpf-empty-row) { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; padding: 8px; margin-bottom: 8px; border: 1px solid #dcdcde; border-radius: 10px; }
				.nptpf-items tr:not(.nptpf-empty-row) td { border: 0; padding: 0; min-width: 0; }
				.nptpf-items tr:not(.nptpf-empty-row) td:nth-child(1) { display: none; }
				.nptpf-items tr:not(.nptpf-empty-row) td:nth-child(2) { grid-column: 1 / -1; font-weight: 700; }
				.nptpf-items td:nth-child(4) { text-align: right; }
				.nptpf-items tr:not(.nptpf-empty-row) td:nth-child(7) { justify-self: end; }
				.nptpf-items input { font-size: 16px; }
			}
			.nptpf-items th { background: <?php echo esc_html( $hcss ); ?>; color: #fff; border-color: <?php echo esc_html( $hcss ); ?>; font-weight: 700; }
			.nptpf-btn { display: inline-block; margin-top: 14px; padding: 10px 30px; border: none; border-radius: 8px; background: <?php echo esc_html( $acss ); ?>; color: #fff; font-family: inherit; font-size: 15px; font-weight: 700; cursor: pointer; }
			.nptpf-btn:hover { filter: brightness(.94); }
			.nptpf-btn:disabled { opacity: .6; cursor: default; }
			.nptpf-hp { position: absolute !important; left: 0 !important; top: 0 !important; width: 1px !important; height: 1px !important; overflow: hidden !important; clip: rect(0 0 0 0) !important; clip-path: inset(50%) !important; opacity: 0; }
			.nptpf-price-cell { text-align: center; font-weight: 700; }
			<?php endif; ?>
		</style>
		<div class="nptpf">
			<div class="nptpf-box">
				<h2><?php echo $public ? 'مشخصات شما' : 'مشخصات مشتری'; ?></h2>
				<?php if ( $public ) : ?>
				<p class="description">فرم زیر را پر کنید و اقلام مورد نظر را اضافه کنید؛ پیش‌فاکتور شما برای کارشناسان ما ارسال می‌شود و در اسرع وقت با شما تماس می‌گیریم.</p>
				<?php endif; ?>
				<div class="nptpf-grid">
					<div>
						<label for="nptpf-name">نام و نام خانوادگی *</label>
						<input type="text" id="nptpf-name" value="<?php echo esc_attr( $prefill['name'] ); ?>">
					</div>
					<div>
						<label for="nptpf-phone">موبایل *</label>
						<input type="text" id="nptpf-phone" dir="ltr" value="<?php echo esc_attr( $prefill['phone'] ); ?>" placeholder="09121234567">
					</div>
					<?php if ( $public && get_option( 'npt_pf_sms_site', 0 ) && NPT_OTP::enabled() && NPT_SMS::otp_ready() ) : ?>
					<div class="nptpf-verify" id="nptpf-verify">
						<p>برای دریافت لینک پیش‌فاکتور روی پیامک، شماره‌ی موبایل را تأیید کنید.</p>
						<?php echo NPT_OTP::captcha_html(); // phpcs:ignore ?>
						<button type="button" class="button" id="nptpf-vsend">ارسال کد به موبایل</button>
						<span class="nptpf-vcodebox" hidden>
							<input type="text" class="nptpf-vcode" inputmode="numeric" maxlength="6" placeholder="کد پیامکی" autocomplete="one-time-code">
							<button type="button" class="button button-primary" id="nptpf-vcheck">تأیید</button>
						</span>
						<p class="nptpf-vmsg" role="status"></p>
					</div>
					<?php endif; ?>
					<details class="nptpf-more"><summary>اطلاعات تکمیلی (اختیاری)</summary><div>
						<label for="nptpf-company">شرکت / فروشگاه (اختیاری)</label>
						<input type="text" id="nptpf-company" value="<?php echo esc_attr( $prefill['company'] ); ?>">
					</div>
					<div>
						<label for="nptpf-email">ایمیل (اختیاری)</label>
						<input type="email" id="nptpf-email" dir="ltr" value="<?php echo esc_attr( $prefill['email'] ); ?>">
					</div>
					<div style="grid-column: 1 / -1;">
						<label for="nptpf-address">آدرس (اختیاری)</label>
						<textarea id="nptpf-address" rows="2"><?php echo esc_textarea( $prefill['address'] ); ?></textarea>
					</div></details>
				</div>
				<?php if ( $public ) : ?>
				<div>
					<label for="nptpf-website" class="nptpf-hp" aria-hidden="true">وب‌سایت</label>
					<input type="text" id="nptpf-website" class="nptpf-hp" name="npt_website" tabindex="-1" autocomplete="off" aria-hidden="true" value="">
				</div>
				<?php else : ?>
				<p class="description">اگر شماره موبایل قبلاً در آرشیو باشد، مشخصات همان مشتری به‌روزرسانی می‌شود؛ در غیر این صورت مشتری جدید ثبت می‌گردد. برای قرار دادن همین فرم در سایت، شورت‌کد <code>[navid_proforma]</code> را در یک برگه بگذارید.</p>
				<?php endif; ?>
			</div>

			<div class="nptpf-box">
				<h2>اقلام پیش‌فاکتور</h2>
				<div class="nptpf-search">
					<input type="text" id="nptpf-q" placeholder="جست‌وجوی محصول (حداقل ۲ حرف)…" style="width:100%">
					<div class="nptpf-results" id="nptpf-results"></div>
				</div>
				<div class="nptpf-items-wrap">
				<table class="nptpf-items">
					<thead>
						<tr>
							<th style="width:32px">#</th>
							<th>شرح کالا</th>
							<th style="width:110px">مقدار</th>
							<th style="width:70px">واحد</th>
							<th style="width:150px">قیمت واحد (<?php echo esc_html( npt_currency_word() ); ?>)</th>
							<th style="width:150px">مبلغ کل</th>
							<th style="width:36px"></th>
						</tr>
					</thead>
					<tbody id="nptpf-rows">
						<tr class="nptpf-empty-row"><td colspan="7">با جست‌وجوی بالا، محصول را به فهرست اضافه کنید.</td></tr>
					</tbody>
				</table>
				</div>
				<div class="nptpf-totals">
					<div class="row"><span>جمع کل</span><span id="nptpf-subtotal">۰</span></div>
					<?php if ( ! $public ) : ?>
					<div class="row">
						<label for="nptpf-discount" style="cursor:pointer">تخفیف (<?php echo esc_html( npt_currency_word() ); ?>)</label>
						<input type="number" id="nptpf-discount" min="0" step="any" value="0" style="width:140px">
					</div>
					<div class="row">
						<span>تخفیف درصدی <small>(فقط همین پیش‌فاکتور؛ قیمت سایت تغییر نمی‌کند)</small></span>
						<span class="nptpf-pct"><button type="button" id="nptpf-pct-minus" aria-label="کاهش ۱ درصد">−</button><input type="number" id="nptpf-pct" min="0" max="100" step="1" value="0" dir="ltr" aria-label="درصد تخفیف"><button type="button" id="nptpf-pct-plus" aria-label="افزایش ۱ درصد">+</button><span>٪</span></span>
					</div>
					<?php endif; ?>
					<div class="row grand"><span>مبلغ قابل پرداخت</span><span id="nptpf-grand">۰</span></div>
				</div>
			</div>

			<div class="nptpf-box">
				<h2>شرایط پیش‌فاکتور</h2>
				<div class="nptpf-grid">
					<?php if ( ! $public ) : ?>
					<div>
						<label for="nptpf-validity">اعتبار (روز)</label>
						<input type="number" id="nptpf-validity" min="0" max="365" value="7">
					</div>
					<?php endif; ?>
					<div style="grid-column: 1 / -1;">
						<label for="nptpf-note"><?php echo $public ? 'توضیحات (اختیاری)' : 'توضیحات روی پیش‌فاکتور (اختیاری)'; ?></label>
						<textarea id="nptpf-note" rows="2" placeholder="مثال: قیمت‌ها بدون احتساب هزینه ارسال است."></textarea>
					</div>
				</div>
				<?php if ( ! $public ) : ?>
				<p class="description"><?php echo esc_html( $unit_hint ); ?></p>
				<?php endif; ?>
				<button type="button" class="<?php echo $public ? 'nptpf-btn' : 'button button-primary'; ?>" id="nptpf-save"><?php echo esc_html( $save_text ); ?></button>
				<div class="nptpf-done" id="nptpf-done"></div>
			</div>
		</div>
		<script>
		(function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var meterWord = <?php echo wp_json_encode( __( 'متر', 'navid-price-table' ) ); ?>;
			var unitWord = <?php echo wp_json_encode( __( 'کلاف', 'navid-price-table' ) ); ?>;
			var isPublic = <?php echo $public ? 'true' : 'false'; ?>;
			var actSearch = <?php echo wp_json_encode( $public ? 'npt_proforma_search_pub' : 'npt_proforma_search' ); ?>;
			var actSave = <?php echo wp_json_encode( $public ? 'npt_proforma_save_pub' : 'npt_proforma_save' ); ?>;
			var saveText = <?php echo wp_json_encode( $save_text ); ?>;
			// The seller always types Toman; the public form shows the site's
			// display unit (Rial = ×10) so it matches the published price table.
			// Server-side storage stays Toman in both cases.
			var factor = <?php echo (int) npt_currency_factor(); ?>;
			var currency = <?php echo wp_json_encode( npt_currency_word() ); ?>;
			var fa = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
			var money = function (n) { return fa(Math.round(n * factor).toLocaleString('en-US')); };
			var rows = [];

			function fmtPrice(n) {
				return fa(Math.round(Number(n) * factor).toLocaleString('en-US'));
			}

			function recalc() {
				var sub = 0;
				rows.forEach(function (r) {
					r._line = (parseFloat(r.qty) || 0) * (parseFloat(r.price) || 0);
					sub += r._line;
				});
				var discEl = document.getElementById('nptpf-discount');
				var disc = discEl ? Math.min(Math.max(parseFloat(discEl.value) || 0, 0), sub * factor) / factor : 0;
				document.getElementById('nptpf-subtotal').textContent = money(sub) + ' ' + currency;
				document.getElementById('nptpf-grand').textContent = money(sub - disc) + ' ' + currency;
			}

			function draw() {
				var tb = document.getElementById('nptpf-rows');
				tb.innerHTML = '';
				if (!rows.length) {
					tb.innerHTML = '<tr class="nptpf-empty-row"><td colspan="7">با جست‌وجوی بالا، محصول را به فهرست اضافه کنید.</td></tr>';
					recalc();
					return;
				}
				rows.forEach(function (r, i) {
					var tr = document.createElement('tr');
					var unit = r.meter ? meterWord : unitWord;
					tr.innerHTML = '<td>' + (i + 1) + '</td>'
						+ '<td>' + r.name.replace(/</g, '&lt;') + '</td>'
						+ '<td><input type="number" min="0" step="any" data-k="qty" value="' + r.qty + '"></td>'
						+ '<td style="text-align:center">' + unit + '</td>'
						// Visitors never edit prices: the catalog is the only source.
						+ (isPublic
							? '<td class="nptpf-price-cell">' + fmtPrice(r.price) + '</td>'
							: '<td><input type="number" min="0" step="any" data-k="price" value="' + Math.round(r.price * factor) + '"></td>')
						+ '<td style="text-align:center" data-line></td>'
						+ '<td><button type="button" class="rm" title="حذف">×</button></td>';
					tr.querySelector('[data-k="qty"]').addEventListener('input', function () { r.qty = this.value; recalc(); paint(); });
					if (!isPublic) {
						tr.querySelector('[data-k="price"]').addEventListener('input', function () { r.price = (parseFloat(this.value) || 0) / factor; recalc(); paint(); });
					}
					tr.querySelector('.rm').addEventListener('click', function () { rows.splice(i, 1); draw(); });
					tb.appendChild(tr);
				});
				recalc();
				paint();
			}

			function paint() {
				var trs = document.querySelectorAll('#nptpf-rows tr');
				rows.forEach(function (r, i) {
					if (trs[i] && trs[i].querySelector('[data-line]')) {
						trs[i].querySelector('[data-line]').textContent = fmtPrice(r._line || 0);
					}
				});
			}

			var timer = null;
			document.getElementById('nptpf-q').addEventListener('input', function () {
				clearTimeout(timer);
				var q = this.value.trim();
				if (q.length < 2) { document.getElementById('nptpf-results').style.display = 'none'; return; }
				timer = setTimeout(function () {
					var fd = new FormData();
					fd.append('action', actSearch);
					fd.append('nonce', nonce);
					fd.append('q', q);
					fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							var box = document.getElementById('nptpf-results');
							if (!res || !res.success) {
								box.innerHTML = '<button type="button" disabled>' + ((res && res.data && res.data.message) ? res.data.message : 'خطا در جست‌وجو.') + '</button>';
								box.style.display = 'block';
								return;
							}
							if (!res.data.results.length) { box.innerHTML = '<button type="button" disabled>موردی یافت نشد.</button>'; box.style.display = 'block'; return; }
							box.innerHTML = '<div class="nptpf-results-head"><span>روی هر کالا بزنید تا اضافه شود؛ می‌توانید چند کالا را پشت سر هم اضافه کنید.</span><button type="button" class="nptpf-close">بستن اقلام</button></div>';
							box.querySelector('.nptpf-close').addEventListener('click', function () { box.style.display = 'none'; });
							res.data.results.forEach(function (p) {
								var b = document.createElement('button');
								b.type = 'button';
								b.innerHTML = '<span class="plus">+</span> ' + p.name.replace(/</g, '&lt;')
									+ (p.meter ? ' <span class="m">متر</span>' : '')
									+ (p.cat ? ' <span class="c">' + p.cat.replace(/</g, '&lt;') + '</span>' : '')
									+ (p.sku && !isPublic ? ' <span class="s">' + p.sku.replace(/</g, '&lt;') + '</span>' : '')
									+ ' <span class="p">' + fmtPrice(p.price) + '</span>';
								b.addEventListener('click', function () {
									rows.push({ id: p.id, name: p.name, qty: 1, price: p.price, meter: p.meter });
									draw();
									b.classList.add('is-added');
									b.insertAdjacentHTML('beforeend', ' <span class="added">✓ اضافه شد</span>');
								});
								box.appendChild(b);
							});
							box.style.display = 'block';
						})
						.catch(function () {
							var box = document.getElementById('nptpf-results');
							box.style.display = 'none';
						});
				}, 300);
			});
			document.addEventListener('click', function (e) {
				if (!e.target.closest('.nptpf-search')) {
					document.getElementById('nptpf-results').style.display = 'none';
				}
			});

			var discountEl = document.getElementById('nptpf-discount');
			if (discountEl) { discountEl.addEventListener('input', recalc); }
			var pctEl = document.getElementById('nptpf-pct');
			var setPct = function (v) {
				v = Math.max(0, Math.min(100, Math.round(parseFloat(v) || 0)));
				pctEl.value = v;
				var sub = 0;
				rows.forEach(function (r) { sub += (parseFloat(r.qty) || 0) * (parseFloat(r.price) || 0); });
				discountEl.value = Math.round(sub * factor * v / 100);
				recalc();
			};
			if (pctEl) {
				pctEl.addEventListener('input', function () { setPct(pctEl.value); });
				document.getElementById('nptpf-pct-minus').addEventListener('click', function () { setPct((parseFloat(pctEl.value) || 0) - 1); });
				document.getElementById('nptpf-pct-plus').addEventListener('click', function () { setPct((parseFloat(pctEl.value) || 0) + 1); });
			}

			var verifyBox = document.getElementById('nptpf-verify');
			if (verifyBox) {
				var otpNonce = <?php echo wp_json_encode( wp_create_nonce( NPT_OTP::NONCE ) ); ?>;
				var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var vmsg = verifyBox.querySelector('.nptpf-vmsg');
				var say = function (text, ok) { vmsg.textContent = text; vmsg.style.color = ok ? '#14733b' : '#b3261e'; };
				var post = function (action, data) {
					var fd = new FormData();
					Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
					fd.append('action', action);
					fd.append('nonce', otpNonce);
					return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
				};
				var capBox = verifyBox.querySelector('.nptp-cap');
				var capLoad = function () {
					post('npt_captcha', {}).then(function (res) {
						if (res && res.success) {
							capBox.querySelector('.nptp-cap-q').textContent = res.data.q;
							capBox.querySelector('.nptp-cap-id').value = res.data.id;
							capBox.querySelector('.nptp-cap-a').value = '';
						}
					});
				};
				var capData = function () {
					return { captcha_id: capBox.querySelector('.nptp-cap-id').value, captcha_answer: capBox.querySelector('.nptp-cap-a').value };
				};
				capBox.querySelector('.nptp-cap-new').addEventListener('click', capLoad);
				verifyBox.querySelector('#nptpf-vsend').addEventListener('click', function () {
					var data = Object.assign({ phone: document.getElementById('nptpf-phone').value, scene: 'proforma' }, capData());
					post('npt_send_otp', data).then(function (res) {
						if (res && res.success) {
							verifyBox.querySelector('.nptpf-vcodebox').hidden = false;
							say(res.data.message, true);
						} else {
							say((res && res.data && res.data.message) || 'ارسال نشد.', false);
						}
						capLoad();
					}).catch(function () { say('خطا در ارتباط با سرور.', false); });
				});
				verifyBox.querySelector('#nptpf-vcheck').addEventListener('click', function () {
					post('npt_pf_verify', { phone: document.getElementById('nptpf-phone').value, code: verifyBox.querySelector('.nptpf-vcode').value }).then(function (res) {
						if (res && res.success) {
							say(res.data.message, true);
							verifyBox.querySelector('.nptpf-vcodebox').hidden = true;
						} else {
							say((res && res.data && res.data.message) || 'کد درست نیست.', false);
						}
					});
				});
				capLoad();
			}
			document.getElementById('nptpf-save').addEventListener('click', function () {
				var btn = this, done = document.getElementById('nptpf-done');
				if (!rows.length) {
					done.style.display = 'block';
					done.style.borderColor = '#b32d2e';
					done.textContent = 'حداقل یک قلم کالا اضافه کنید.';
					return;
				}
				btn.disabled = true;
				btn.textContent = <?php echo wp_json_encode( __( 'در حال ذخیره...', 'navid-price-table' ) ); ?>;
				var fd = new FormData();
				fd.append('action', actSave);
				fd.append('nonce', nonce);
				fd.append('c_name', document.getElementById('nptpf-name').value);
				fd.append('c_phone', document.getElementById('nptpf-phone').value);
				fd.append('c_company', document.getElementById('nptpf-company').value);
				fd.append('c_email', document.getElementById('nptpf-email').value);
				fd.append('c_address', document.getElementById('nptpf-address').value);
				fd.append('p_note', document.getElementById('nptpf-note').value);
				if (isPublic) {
					fd.append('npt_website', document.getElementById('nptpf-website').value);
					// Only id + qty leave the browser; the server re-prices every line.
					fd.append('items', JSON.stringify(rows.map(function (r) { return { id: r.id, qty: r.qty }; })));
				} else {
					fd.append('discount', document.getElementById('nptpf-discount').value);
					fd.append('validity_days', document.getElementById('nptpf-validity').value);
					fd.append('items', JSON.stringify(rows));
				}
				fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						btn.disabled = false;
						btn.textContent = saveText;
						done.style.display = 'block';
						if (res && res.success) {
							done.style.borderColor = '#00a32a';
							done.innerHTML = (isPublic
								? 'پیش‌فاکتور شما به شماره <strong>' + res.data.number + '</strong> ثبت شد و برای کارشناسان ما ارسال گردید. '
								: 'پیش‌فاکتور شماره <strong>' + res.data.number + '</strong> ذخیره شد. ')
								+ '<div class="nptpf-done-acts">'
							+ '<a target="_blank" rel="noopener" href="' + res.data.print_url + '">مشاهده</a>'
							+ '<a target="_blank" rel="noopener" href="' + res.data.print_url + '&autoprint=1">دریافت (PDF)</a>'
							+ '<button type="button" class="nptpf-share" data-url="' + res.data.print_url + '">اشتراک‌گذاری</button>'
							+ '</div>';
							var share = done.querySelector('.nptpf-share');
							if (share) {
								share.addEventListener('click', function () {
									var url = share.getAttribute('data-url');
									if (navigator.share) { navigator.share({ title: 'پیش‌فاکتور', url: url }).catch(function () {}); return; }
									if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { share.textContent = 'لینک کپی شد ✓'; }); return; }
									window.prompt('لینک پیش‌فاکتور', url);
								});
							}
							if (!isPublic && res.data.id) {
								var smsBtn = document.createElement('button');
								smsBtn.type = 'button';
								smsBtn.className = 'button';
								smsBtn.style.marginTop = '8px';
								smsBtn.textContent = 'ارسال لینک به مشتری با پیامک';
								smsBtn.addEventListener('click', function () {
									smsBtn.disabled = true;
									var fd = new FormData();
									fd.append('action', 'npt_pf_sms');
									fd.append('nonce', <?php echo wp_json_encode( wp_create_nonce( NPT_Panel::NONCE ) ); ?>);
									fd.append('id', res.data.id);
									fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r2) {
										smsBtn.textContent = r2 && r2.success ? r2.data.message : ((r2 && r2.data && r2.data.message) || 'ارسال نشد');
									}).catch(function () {
										smsBtn.textContent = 'خطا در ارتباط';
									});
								});
								done.appendChild(smsBtn);
							}
							rows = [];
							draw();
							if (isPublic) {
								['nptpf-name', 'nptpf-phone', 'nptpf-company', 'nptpf-email', 'nptpf-address', 'nptpf-note']
									.forEach(function (id) { document.getElementById(id).value = ''; });
							}
						} else {
							done.style.borderColor = '#b32d2e';
							done.textContent = (res && res.data && res.data.message) ? res.data.message : 'خطا در ذخیره‌سازی.';
						}
					})
					.catch(function () {
						btn.disabled = false;
						btn.textContent = saveText;
						done.style.display = 'block';
						done.style.borderColor = '#b32d2e';
						done.textContent = 'خطا در ارتباط با سرور.';
					});
			});

			draw();
		})();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Delete a proforma (customer stays archived)
	 * ------------------------------------------------------------------- */
	/* ---------------------------------------------------------------------
	 * Public print view (token-gated, standalone HTML like the online view)
	 * ------------------------------------------------------------------- */
	/* ---------------------------------------------------------------------
	 * Short links and SMS to the customer
	 * ------------------------------------------------------------------- */
	public static function add_rewrite() {
		add_rewrite_rule( '^pf/([A-Za-z0-9]{4,16})/?$', 'index.php?npt_pf=$matches[1]', 'top' );
		if ( get_option( 'npt_rules_v' ) !== NPT_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'npt_rules_v', NPT_VERSION, false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'npt_pf';
		return $vars;
	}

	public static function short_url( $code ) {
		return home_url( '/pf/' . $code . '/' );
	}

	private static function new_short() {
		global $wpdb;
		for ( $i = 0; $i < 5; $i++ ) {
			$code = wp_generate_password( 6, false, false );
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table_proformas() . ' WHERE short = %s LIMIT 1', $code ) ) ) { // phpcs:ignore
				return $code;
			}
		}
		return wp_generate_password( 10, false, false );
	}

	/** Short code of a proforma; made on first use for older rows. */
	public static function ensure_short( $id ) {
		global $wpdb;
		$code = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT short FROM ' . self::table_proformas() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( '' === $code ) {
			$code = self::new_short();
			$wpdb->update( self::table_proformas(), array( 'short' => $code ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}
		return $code;
	}

	public static function redirect_short() {
		$code = get_query_var( 'npt_pf' );
		if ( ! $code ) {
			return;
		}
		// Codes are mixed case: keep the case, strip anything else, and rate-limit guesses.
		$code = preg_replace( '/[^A-Za-z0-9]/', '', (string) $code );
		if ( ! npt_pdf_rate_limit_ok( 'pf' ) ) {
			status_header( 429 );
			nocache_headers();
			echo esc_html__( 'تعداد درخواست‌ها بیش از حد مجاز است.', 'navid-price-table' ); // phpcs:ignore
			exit;
		}
		global $wpdb;
		$token = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT token FROM ' . self::table_proformas() . ' WHERE short = %s LIMIT 1', $code ) ); // phpcs:ignore
		if ( '' === $token ) {
			status_header( 404 );
			nocache_headers();
			echo esc_html__( 'پیش‌فاکتور یافت نشد.', 'navid-price-table' ); // phpcs:ignore
			exit;
		}
		$args = array( 'action' => 'npt_proforma_print', 'token' => $token );
		if ( ! empty( $_GET['back'] ) ) { // phpcs:ignore
			$args['back'] = wp_unslash( $_GET['back'] ); // phpcs:ignore
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin-ajax.php' ) ) );
		exit;
	}

	/** A «بازگشت به پنل» bar on the print view, only when the link came from the panel. */
	private static function with_back_bar( $html ) {
		$back = isset( $_GET['back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['back'] ) ), '' ) : ''; // phpcs:ignore
		if ( '' === $back || false === strpos( $html, '</body>' ) ) {
			return $html;
		}
		$bar = '<style>body{padding-bottom:76px}@media print{.npt-backbar{display:none!important}body{padding-bottom:0}}</style>'
			. '<nav class="npt-backbar" style="position:fixed;left:0;right:0;bottom:0;z-index:9999;display:flex;gap:10px;justify-content:space-between;align-items:center;padding:12px 14px;background:#fff;border-top:1px solid #dde3ec;box-shadow:0 -2px 10px rgba(0,0,0,.06);font-family:inherit">'
			. '<a href="' . esc_url( $back ) . '" style="font-weight:700;color:#1d3557;text-decoration:none;padding:10px 14px;border-radius:10px;background:#eef3fb">→ بازگشت به پنل</a>'
			. '<button type="button" onclick="window.print()" style="font:inherit;font-weight:700;padding:10px 14px;border-radius:10px;border:0;background:#1d3557;color:#fff">چاپ</button>'
			. '</nav>';
		return str_replace( '</body>', $bar . '</body>', $html );
	}

	/** Panel: send the short link by SMS to the proforma's customer. */
	public static function ajax_pf_sms() {
		check_ajax_referer( NPT_Panel::NONCE, 'nonce' );
		if ( ! NPT_Panel::user_can_panel() ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore
		global $wpdb;
		$row = $id ? $wpdb->get_row( $wpdb->prepare(
			'SELECT p.id, p.number, c.name AS c_name, c.phone AS c_phone FROM ' . self::table_proformas() . ' p LEFT JOIN ' . self::table_customers() . ' c ON c.id = p.customer_id WHERE p.id = %d LIMIT 1', // phpcs:ignore
			$id
		) ) : null;
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'پیش‌فاکتور پیدا نشد.' ), 404 );
		}
		$phone = self::normalize_phone( (string) $row->c_phone );
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => 'شماره‌ی مشتری ثبت نشده است.' ), 400 );
		}
		$res = NPT_SMS::send_link( $phone, (string) $row->c_name, self::short_url( self::ensure_short( (int) $row->id ) ), (int) $row->number );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => 'ارسال نشد: ' . $res['str'] ), 502 );
		}
		NPT_Log::activity( 'ارسال لینک پیش‌فاکتور #' . $row->number . ' با پیامک به ' . $phone );
		wp_send_json_success( array( 'message' => 'پیامک با لینک پیش‌فاکتور ارسال شد.' ) );
	}

	/** Public form: the visitor enters the code sent to their mobile. */
	public static function ajax_pf_verify() {
		if ( ! NPT_OTP::nonce_ok() ) {
			wp_send_json_error( array( 'message' => 'نشست منقضی شده است. صفحه را تازه کنید.' ), 403 );
		}
		$phone = self::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' ); // phpcs:ignore
		$code  = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : ''; // phpcs:ignore
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => 'شماره‌ی موبایل معتبر نیست.' ), 400 );
		}
		$res = NPT_OTP::verify_code( $phone, $code, 'proforma' );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		NPT_OTP::mark_verified( $phone );
		wp_send_json_success( array( 'message' => 'شماره تأیید شد. بعد از ثبت، لینک پیش‌فاکتور برایتان پیامک می‌شود.' ) );
	}

	/** Panel: delete a wrong proforma (the customer record stays). */
	public static function ajax_pf_delete() {
		check_ajax_referer( NPT_Panel::NONCE, 'nonce' );
		if ( ! NPT_Panel::user_can_panel() ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore
		global $wpdb;
		$number = $id ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT number FROM ' . self::table_proformas() . ' WHERE id = %d', $id ) ) : 0; // phpcs:ignore
		if ( ! $number ) {
			wp_send_json_error( array( 'message' => 'پیش‌فاکتور پیدا نشد.' ), 404 );
		}
		$wpdb->delete( self::table_proformas(), array( 'id' => $id ), array( '%d' ) );
		NPT_Log::activity( 'حذف پیش‌فاکتور #' . $number );
		wp_send_json_success( array( 'message' => 'حذف شد.' ) );
	}

	public static function render_print() {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) || ! npt_pdf_rate_limit_ok( 'print' ) ) {
			status_header( 403 );
			echo esc_html__( 'لینک پیش‌فاکتور معتبر نیست یا تعداد درخواست‌ها بیش از حد مجاز است.', 'navid-price-table' ); // phpcs:ignore
			exit;
		}

		$key  = 'npt_pf_' . md5( $token . '|' . NPT_VERSION );
		$html = get_transient( $key );
		if ( false === $html ) {
			$html = self::build_print_html( $token );
			if ( null === $html ) {
				status_header( 404 );
				echo esc_html__( 'پیش‌فاکتور یافت نشد.', 'navid-price-table' ); // phpcs:ignore
				exit;
			}
			set_transient( $key, $html, HOUR_IN_SECONDS );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$html = self::with_back_bar( $html );
		echo $html; // phpcs:ignore
		exit;
	}

	private static function build_print_html( $token ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare(
			'SELECT p.*, c.name AS c_name, c.phone AS c_phone, c.company AS c_company, c.email AS c_email, c.address AS c_address'
			. ' FROM ' . self::table_proformas() . ' p LEFT JOIN ' . self::table_customers() . ' c ON c.id = p.customer_id'
			. ' WHERE p.token = %s LIMIT 1', // phpcs:ignore
			$token
		) );
		if ( ! $p ) {
			return null;
		}

		$items    = json_decode( (string) $p->items, true );
		$logo     = npt_media_image_url( 'npt_proforma_logo_id', npt_media_image_url( 'npt_logo_id', NPT_URL . 'assets/images/logo-navid.png' ) );
		$stamp_id = (int) get_option( 'npt_proforma_stamp_id', 0 );
		$stamp    = $stamp_id ? wp_get_attachment_image_url( $stamp_id, 'medium' ) : '';
		$j        = npt_jalali_now();
		$hcss     = vsprintf( '#%02x%02x%02x', npt_header_color_rgb() );
		$acss     = vsprintf( '#%02x%02x%02x', npt_accent_color_rgb() );
		$unit     = npt_currency_word();
		$validity = self::validity_date( $p->validity_days );
		$phone    = trim( (string) get_option( 'npt_phone', '' ) );
		$address  = trim( (string) get_option( 'npt_address', '' ) );
		$i        = 0;

		ob_start();
		?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( 'پیش‌فاکتور ' . npt_to_persian_digits( (string) $p->number ) . ' - ' . get_bloginfo( 'name' ) ); ?></title>
<style>
	* { box-sizing: border-box; }
	body { margin: 0 auto; max-width: 900px; padding: 16px 18px 40px; background: #f7f9fa; color: #222; font-family: Vazirmatn, Tahoma, Arial, sans-serif; }
	.nptp-print { position: fixed; bottom: 14px; left: 14px; z-index: 9; padding: 8px 14px; border: 1px solid #0f4c5c; border-radius: 8px; background: #0f4c5c; color: #fff; font: inherit; font-size: 13px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
	.nptp-sheet { background: #fff; border: 1px solid #e6e9ec; border-radius: 10px; padding: 20px 22px 26px; }
	.nptp-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; border-bottom: 3px solid <?php echo esc_html( $hcss ); ?>; padding-bottom: 14px; }
	.nptp-head img { height: 64px; }
	.nptp-title h1 { margin: 0; font-size: 24px; color: <?php echo esc_html( $hcss ); ?>; }
	.nptp-meta { color: #555; font-size: 13px; margin-top: 4px; }
	.nptp-meta b { color: <?php echo esc_html( $acss ); ?>; }
	.nptp-cust { margin-top: 16px; border: 1px solid #e0e3e6; border-radius: 8px; padding: 12px 16px; font-size: 13.5px; line-height: 2; background: #fbfcfd; }
	.nptp-cust .t { font-weight: 800; color: <?php echo esc_html( $hcss ); ?>; margin-bottom: 4px; }
	.nptp-cust .g { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0 18px; }
	table.items { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 13px; }
	table.items th, table.items td { border: 1px solid #cfd4d8; padding: 7px 8px; text-align: center; }
	table.items th { background: <?php echo esc_html( $hcss ); ?>; color: #fff; font-weight: 700; }
	table.items .desc { text-align: right; }
	table.items td .nptp-meter-badge { display: inline-block; margin-inline-start: 5px; padding: 0 7px; border-radius: 999px; background: #28a745; color: #fff; font-size: 10px; font-weight: 700; line-height: 1.5; vertical-align: middle; }
	.nptp-sum { margin-top: 14px; max-width: 380px; margin-inline-start: auto; font-size: 14px; }
	.nptp-sum .row { display: flex; justify-content: space-between; padding: 4px 2px; }
	.nptp-sum .grand { font-weight: 800; font-size: 16px; border-top: 2px solid <?php echo esc_html( $hcss ); ?>; margin-top: 6px; padding-top: 8px; }
	.nptp-stamp { display: block; max-height: 70px; max-width: 160px; margin-bottom: 4px; object-fit: contain; }
	.nptp-note { margin-top: 14px; color: #666; font-size: 12.5px; border: 1px dashed #c9ced2; border-radius: 6px; padding: 8px 12px; background: #fbfcfd; }
	.nptp-foot { margin-top: 22px; border-top: 1px solid #e0e3e6; padding-top: 12px; text-align: center; color: #152896; font-weight: 800; font-size: 18px; letter-spacing: 2px; }
	.nptp-foot .contact { display: block; color: #555; font-weight: 400; font-size: 12.5px; letter-spacing: 0; margin-top: 6px; }
	.nptp-sig { display: flex; justify-content: space-between; margin-top: 40px; color: #666; font-size: 12.5px; }
	.nptp-share { position: fixed; bottom: 14px; right: 14px; z-index: 9; padding: 8px 14px; border: 1px solid #0f4c5c; border-radius: 8px; background: #fff; color: #0f4c5c; font: inherit; font-size: 14px; cursor: pointer; }
	.nptp-print, .nptp-share { min-height: 42px; }
	@media (max-width: 640px) {
		body { padding: 10px; }
		.nptp-sheet { padding: 14px 12px 18px; border-radius: 8px; }
		.nptp-title h1 { font-size: 19px; }
		.nptp-meta { font-size: 12.5px; }
		.nptp-cust { font-size: 13px; padding: 10px 12px; }
		table.items { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; font-size: 12.5px; }
		.nptp-sum { max-width: 100%; }
		.nptp-sig { flex-direction: column; gap: 16px; margin-top: 26px; }
		.nptp-print { left: 10px; bottom: 10px; }
		.nptp-share { right: 10px; bottom: 10px; }
		.nptp-print, .nptp-share { font-size: 15px; padding: 10px 16px; }
	}
	@media print { .nptp-print, .nptp-share { display: none; } body { background: #fff; padding: 0; max-width: none; } .nptp-sheet { border: none; } }
</style>
</head>
<body>
<button type="button" class="nptp-print" onclick="window.print()"><?php esc_html_e( 'چاپ / ذخیره PDF', 'navid-price-table' ); ?></button>
<button type="button" class="nptp-share" id="nptp-share-btn">اشتراک‌گذاری</button>
<script>
(function () {
	var btn = document.getElementById('nptp-share-btn');
	btn.addEventListener('click', function () {
		var url = location.href.replace(/&?autoprint=1/, '');
		if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function () {}); return; }
		if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { btn.textContent = 'لینک کپی شد ✓'; }); return; }
		window.prompt('لینک پیش‌فاکتور', url);
	});
}());
</script>
<div class="nptp-sheet">
	<header class="nptp-head">
		<div class="nptp-title">
			<h1>پیش‌فاکتور فروش</h1>
			<div class="nptp-meta">
				شماره: <b><?php echo esc_html( npt_to_persian_digits( (string) $p->number ) ); ?></b>
				&nbsp;|&nbsp; تاریخ: <b><?php echo esc_html( $j['date'] ); ?></b>
				&nbsp;|&nbsp; اعتبار تا: <b><?php echo esc_html( $validity ); ?></b>
			</div>
			<div class="nptp-meta"><?php echo esc_html( npt_unit_sentence() ); ?></div>
		</div>
		<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
	</header>

	<section class="nptp-cust">
		<div class="t">مشخصات خریدار</div>
		<div class="g">
			<div>نام: <b><?php echo esc_html( $p->c_name ); ?></b></div>
			<div>موبایل: <b dir="ltr"><?php echo esc_html( npt_to_persian_digits( (string) $p->c_phone ) ); ?></b></div>
			<?php if ( $p->c_company ) : ?><div>شرکت / فروشگاه: <?php echo esc_html( $p->c_company ); ?></div><?php endif; ?>
			<?php if ( $p->c_email ) : ?><div>ایمیل: <span dir="ltr"><?php echo esc_html( $p->c_email ); ?></span></div><?php endif; ?>
			<?php if ( $p->c_address ) : ?><div style="grid-column: 1 / -1;">آدرس: <?php echo esc_html( $p->c_address ); ?></div><?php endif; ?>
		</div>
	</section>

	<table class="items">
		<thead>
			<tr>
				<th style="width:36px">#</th>
				<th>شرح کالا</th>
				<th style="width:90px">مقدار</th>
				<th style="width:70px">واحد</th>
				<th style="width:130px">قیمت واحد</th>
				<th style="width:150px">مبلغ کل</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( (array) $items as $item ) : $i++; ?>
			<tr>
				<td><?php echo esc_html( npt_to_persian_digits( (string) $i ) ); ?></td>
				<td class="desc"><?php echo esc_html( $item['name'] ); ?><?php if ( ! empty( $item['meter'] ) ) : ?><span class="nptp-meter-badge">متر</span><?php endif; ?></td>
				<td><?php echo esc_html( npt_to_persian_digits( rtrim( rtrim( (string) ( 0 + $item['qty'] ), '0' ), '.' ) ) ); ?></td>
				<td><?php echo esc_html( ! empty( $item['meter'] ) ? __( 'متر', 'navid-price-table' ) : __( 'کلاف', 'navid-price-table' ) ); ?></td>
				<td><?php echo esc_html( npt_format_price( $item['price'] ) ); ?></td>
				<td><?php echo esc_html( npt_format_price( (float) $item['qty'] * (float) $item['price'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<div class="nptp-sum">
		<div class="row"><span>جمع کل</span><span><?php echo esc_html( npt_format_price( $p->subtotal, true ) ); ?></span></div>
		<?php if ( (float) $p->discount > 0 ) : ?>
		<div class="row"><span>تخفیف</span><span><?php echo esc_html( npt_format_price( $p->discount, true ) ); ?></span></div>
		<?php endif; ?>
		<div class="row grand"><span>مبلغ قابل پرداخت</span><span><?php echo esc_html( npt_format_price( $p->total, true ) ); ?></span></div>
	</div>

	<?php if ( trim( (string) $p->note ) ) : ?>
	<div class="nptp-note"><?php echo esc_html( $p->note ); ?></div>
	<?php endif; ?>

	<div class="nptp-sig">
		<span>
			<?php if ( $stamp ) : ?><img class="nptp-stamp" src="<?php echo esc_url( $stamp ); ?>" alt=""><?php endif; ?>
			امضای فروشنده: ....................
		</span>
		<span>امضای خریدار: ....................</span>
	</div>

	<footer class="nptp-foot">
		<?php echo esc_html( npt_footer_domain_text() ); ?>
		<?php if ( $phone || $address ) : ?>
		<span class="contact"><?php echo esc_html( trim( $address . ( $address && $phone ? '  |  ' : '' ) . $phone ) ); ?></span>
		<?php endif; ?>
	</footer>
</div>
<script>if (location.search.indexOf('autoprint=1') !== -1) { window.addEventListener('load', function () { window.print(); }); }</script>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}

NPT_Proforma::init();
