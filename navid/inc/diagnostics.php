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
	if ( null === $root ) {
		$root = wp_normalize_path( get_template_directory() ) . '/';
	}
	return 0 === strpos( wp_normalize_path( (string) $file ), $root );
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
	$short = str_replace( wp_normalize_path( get_template_directory() ) . '/', '', wp_normalize_path( $file ) );
	$text  = ( isset( $labels[ $no ] ) ? $labels[ $no ] : 'Error' ) . ': ' . $msg . ' (' . $short . ':' . $line . ')';
	$key   = 'navid_err_' . md5( $text );
	if ( false === get_transient( $key ) ) {
		set_transient( $key, 1, HOUR_IN_SECONDS );
		navid_log( 'قالب', $text );
	}
	return false;
}

/**
 * A fatal error in a theme file is logged as the request ends.
 */
function navid_shutdown_handler() {
	$err = error_get_last();
	if ( $err && in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) && navid_is_theme_file( $err['file'] ) && function_exists( 'update_option' ) ) {
		$short = str_replace( wp_normalize_path( get_template_directory() ) . '/', '', wp_normalize_path( $err['file'] ) );
		navid_log( 'قالب', 'Fatal: ' . $err['message'] . ' (' . $short . ':' . $err['line'] . ')' );
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
