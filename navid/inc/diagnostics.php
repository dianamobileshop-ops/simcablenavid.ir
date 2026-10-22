<?php
/**
 * Theme-wide error report and system information.
 *
 * PHP warnings, notices and fatal errors that come from a theme file are written to the
 * same log as the price-table errors (Settings → سیستم → گزارش خطاها), together with a
 * short system report, so the owner can copy one block of text and send it to support.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes one line to the error log (same store the price-table modules use).
 *
 * @param string $area    Short area name.
 * @param string $message Message.
 */
function navid_log( $area, $message ) {
	if ( class_exists( 'NPT_Log' ) ) {
		NPT_Log::add( $area, $message );
	}
}

/**
 * Whether a file belongs to this theme.
 *
 * @param string $file Absolute path.
 * @return bool
 */
function navid_is_theme_file( $file ) {
	static $root = null;
	static $all = null;
	if ( null === $root ) {
		$root = wp_normalize_path( get_template_directory() ) . '/';
		$all  = '1' === (string) get_option( 'navid_log_all_php', '0' ) ? wp_normalize_path( WP_CONTENT_DIR ) . '/' : '';
	}
	$file = wp_normalize_path( (string) $file );
	// The theme always; with the switch on, every plugin and theme under wp-content too.
	return 0 === strpos( $file, $root ) || ( '' !== $all && 0 === strpos( $file, $all ) );
}

/**
 * Whether a file is inside the theme folder itself.
 *
 * @param string $file Path.
 * @return bool
 */
function navid_is_in_theme( $file ) {
	return 0 === strpos( wp_normalize_path( (string) $file ), wp_normalize_path( get_template_directory() ) . '/' );
}

/**
 * Logs PHP errors raised inside theme files, each distinct message once an hour.
 * WordPress and PHP still handle the error as usual afterwards.
 *
 * @param int    $no   Error level.
 * @param string $msg  Message.
 * @param string $file File.
 * @param int    $line Line.
 * @return bool Always false.
 */
function navid_error_handler( $no, $msg, $file, $line ) {
	if ( ! ( error_reporting() & $no ) || ! navid_is_theme_file( $file ) ) {
		return false;
	}
	$labels = array(
		E_WARNING => 'Warning', E_USER_WARNING => 'Warning', E_NOTICE => 'Notice', E_USER_NOTICE => 'Notice',
		E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'Deprecated',
	);
	$short = str_replace( wp_normalize_path( WP_CONTENT_DIR ) . '/', '', wp_normalize_path( $file ) );
	$text  = ( isset( $labels[ $no ] ) ? $labels[ $no ] : 'Error' ) . ': ' . $msg . ' (' . $short . ':' . $line . ')';
	$key   = 'navid_err_' . md5( $text );
	if ( false === get_transient( $key ) ) {
		set_transient( $key, 1, HOUR_IN_SECONDS );
		navid_log( navid_is_in_theme( $file ) ? 'قالب' : 'افزونه', $text );
	}
	return false;
}

/**
 * A fatal error in a theme file is logged as the request ends.
 */
function navid_shutdown_handler() {
	$err = error_get_last();
	if ( $err && in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) && navid_is_theme_file( $err['file'] ) && function_exists( 'update_option' ) ) {
		$short = str_replace( wp_normalize_path( WP_CONTENT_DIR ) . '/', '', wp_normalize_path( $err['file'] ) );
		navid_log( navid_is_in_theme( $err['file'] ) ? 'قالب' : 'افزونه', 'Fatal: ' . $err['message'] . ' (' . $short . ':' . $err['line'] . ')' );
	}
}

if ( apply_filters( 'navid_log_php_errors', true ) ) {
	set_error_handler( 'navid_error_handler' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	register_shutdown_function( 'navid_shutdown_handler' );
}

/**
 * Names of known cache plugins that are active.
 *
 * @return string[]
 */
function navid_active_cache_plugins() {
	$found = array();
	$map   = array(
		'WP_ROCKET_VERSION'      => 'WP Rocket',
		'LSCWP_V'                => 'LiteSpeed Cache',
		'W3TC'                   => 'W3 Total Cache',
		'WPFC_MAIN_PATH'         => 'WP Fastest Cache',
		'WPCACHEHOME'            => 'WP Super Cache',
		'AUTOPTIMIZE_PLUGIN_VERSION' => 'Autoptimize',
		'SG_CACHEPRESS_VERSION'  => 'SiteGround Optimizer',
	);
	foreach ( $map as $constant => $name ) {
		if ( defined( $constant ) ) {
			$found[] = $name;
		}
	}
	if ( defined( 'WP_CACHE' ) && WP_CACHE && ! $found ) {
		$found[] = 'WP_CACHE (کش صفحه فعال است)';
	}
	return $found;
}

/**
 * System report for support: versions, settings that matter and the active plugins.
 *
 * @return string
 */
function navid_system_report() {
	global $wp_version;
	$plugins = array();
	foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
		$plugins[] = dirname( $file ) === '.' ? $file : dirname( $file );
	}
	$lines = array(
		'قالب نوید: ' . NAVID_VERSION . ' | جدول قیمت داخل قالب: ' . ( defined( 'NPT_VERSION' ) ? NPT_VERSION : '—' ),
		'وردپرس: ' . $wp_version . ' | PHP: ' . PHP_VERSION . ' | ووکامرس: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'نصب نیست' ),
		'آدرس سایت: ' . home_url( '/' ),
		'حافظه PHP: ' . WP_MEMORY_LIMIT . ' | حالت دیباگ: ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'روشن' : 'خاموش' ),
		'افزونهٔ کش: ' . ( navid_active_cache_plugins() ? implode( '، ', navid_active_cache_plugins() ) : 'شناسایی نشد' ),
		'پیامک: ' . ( class_exists( 'NPT_SMS' ) && NPT_SMS::otp_ready() ? 'آماده' : 'آماده نیست' ),
		'افزونه‌های فعال: ' . ( $plugins ? implode( '، ', $plugins ) : '—' ),
	);
	return implode( "\n", $lines );
}

/**
 * Errors from visitors' browsers: a script of this site that crashed. The address is the
 * only thing stored about the visitor's page (no query string, no cookies, no IP).
 * Open to guests on purpose (cached pages carry no tokens) and kept small by limits.
 */
function navid_ajax_js_error() {
	$message = isset( $_POST['m'] ) ? sanitize_text_field( wp_unslash( $_POST['m'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$file    = isset( $_POST['f'] ) ? esc_url_raw( wp_unslash( $_POST['f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$line    = isset( $_POST['l'] ) ? absint( $_POST['l'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$page    = isset( $_POST['p'] ) ? sanitize_text_field( wp_unslash( $_POST['p'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$host    = wp_parse_url( $file, PHP_URL_HOST );
	$ours    = wp_parse_url( home_url(), PHP_URL_HOST );
	// Only this site's own files: browser extensions and third-party scripts are not ours to fix.
	if ( '' === $message || $host !== $ours || 'Script error.' === $message || false !== strpos( $message, 'ResizeObserver' ) ) {
		wp_send_json_success();
	}
	$ip   = function_exists( 'npt_client_ip' ) ? npt_client_ip() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
	$rate = 'navid_jsr_' . md5( $ip );
	$hits = (int) get_transient( $rate );
	if ( $hits >= 10 ) {
		wp_send_json_success();
	}
	set_transient( $rate, $hits + 1, HOUR_IN_SECONDS );
	$text = mb_substr( $message, 0, 200 ) . ' (' . wp_basename( (string) wp_parse_url( $file, PHP_URL_PATH ) ) . ':' . $line . ') صفحه: ' . mb_substr( $page, 0, 80 );
	$key  = 'navid_jse_' . md5( $text );
	if ( false === get_transient( $key ) ) {
		set_transient( $key, 1, DAY_IN_SECONDS );
		navid_log( 'مرورگر', $text );
	}
	wp_send_json_success();
}
add_action( 'wp_ajax_navid_js_error', 'navid_ajax_js_error' );
add_action( 'wp_ajax_nopriv_navid_js_error', 'navid_ajax_js_error' );

/**
 * Failed emails and failed orders go to the same log.
 *
 * @param WP_Error $error Mail error.
 */
function navid_log_mail_error( $error ) {
	navid_log( 'ایمیل', is_wp_error( $error ) ? $error->get_error_message() : 'ارسال ایمیل ناموفق بود.' );
}
add_action( 'wp_mail_failed', 'navid_log_mail_error' );

/**
 * A failed payment or order.
 *
 * @param int $order_id Order id.
 */
function navid_log_failed_order( $order_id ) {
	navid_log( 'سفارش', 'سفارش شمارهٔ ' . (int) $order_id . ' ناموفق شد (پرداخت انجام نشد یا رد شد).' );
}
add_action( 'woocommerce_order_status_failed', 'navid_log_failed_order' );

/**
 * Saves the «log plugin errors too» switch.
 */
function navid_handle_log_scope() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی ندارید.', 'navid' ) );
	}
	check_admin_referer( 'navid_log_scope' );
	update_option( 'navid_log_all_php', empty( $_POST['all'] ) ? '0' : '1', false );
	wp_safe_redirect( admin_url( 'admin.php?page=navid-settings&tab=errors&saved=1' ) );
	exit;
}
add_action( 'admin_post_navid_log_scope', 'navid_handle_log_scope' );
