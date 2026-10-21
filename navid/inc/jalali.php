<?php
/**
 * Jalali (Shamsi) dates for the whole site, built into the theme: no date plugin needed.
 *
 * Every date WordPress and WooCommerce print goes through wp_date() (date_i18n() calls
 * it too), so one filter there turns post, comment, order and email dates into Jalali.
 * Formats used to store or exchange data ('Y-m-d H:i:s', 'Ymd', 'c', 'U', …) are left
 * alone, so nothing that is saved or compared ever gets a Jalali value.
 *
 * Panel switch: «تاریخ شمسی» = everywhere / site only / off.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gregorian to Jalali.
 *
 * @param int $gy Year.
 * @param int $gm Month.
 * @param int $gd Day.
 * @return int[] array( year, month, day )
 */
function navid_g2j( $gy, $gm, $gd ) {
	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
	$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
	$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
	$days %= 12053;
	$jy   += 4 * intdiv( $days, 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$jy  += intdiv( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}
	if ( $days < 186 ) {
		$jm = 1 + intdiv( $days, 31 );
		$jd = 1 + ( $days % 31 );
	} else {
		$jm = 7 + intdiv( $days - 186, 30 );
		$jd = 1 + ( ( $days - 186 ) % 30 );
	}
	return array( $jy, $jm, $jd );
}

/**
 * Jalali to Gregorian.
 *
 * @param int $jy Year.
 * @param int $jm Month.
 * @param int $jd Day.
 * @return int[] array( year, month, day )
 */
function navid_j2g( $jy, $jm, $jd ) {
	$jy  += 1595;
	$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd + ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
	$gy   = 400 * intdiv( $days, 146097 );
	$days %= 146097;
	if ( $days > 36524 ) {
		$gy  += 100 * intdiv( --$days, 36524 );
		$days %= 36524;
		if ( $days >= 365 ) {
			$days++;
		}
	}
	$gy  += 4 * intdiv( $days, 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$gy  += intdiv( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}
	$gd   = $days + 1;
	$leap = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;
	$sal  = array( 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
	for ( $gm = 0; $gm < 13 && $gd > $sal[ $gm ]; $gm++ ) {
		$gd -= $sal[ $gm ];
	}
	return array( $gy, $gm, $gd );
}

/**
 * ASCII digits to Persian digits.
 *
 * @param string $text Text.
 * @return string
 */
function navid_fa_digits( $text ) {
	return strtr( (string) $text, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
}

/**
 * Persian / Arabic digits to ASCII (for numbers people type).
 *
 * @param string $text Text.
 * @return string
 */
function navid_en_digits( $text ) {
	return strtr( (string) $text, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
}

/**
 * Like wp_date(), but Jalali. Unknown or machine letters are formatted by PHP as usual.
 *
 * @param string            $format    PHP date format.
 * @param int|null          $timestamp Unix timestamp (now when null).
 * @param DateTimeZone|null $timezone  Time zone (site zone when null).
 * @param bool|null         $fa_digits Persian digits (panel setting when null).
 * @return string
 */
function navid_jdate( $format, $timestamp = null, $timezone = null, $fa_digits = null ) {
	$timestamp = null === $timestamp ? time() : (int) $timestamp;
	$timezone  = $timezone instanceof DateTimeZone ? $timezone : wp_timezone();
	$dt        = new DateTime( '@' . $timestamp );
	$dt->setTimezone( $timezone );

	list( $jy, $jm, $jd ) = navid_g2j( (int) $dt->format( 'Y' ), (int) $dt->format( 'n' ), (int) $dt->format( 'j' ) );

	$months = array( 1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );
	$days   = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' );
	$short  = array( 'ی', 'د', 'س', 'چ', 'پ', 'ج', 'ش' );
	$w      = (int) $dt->format( 'w' );
	$doy    = ( $jm <= 6 ) ? ( ( $jm - 1 ) * 31 + $jd - 1 ) : ( 186 + ( $jm - 7 ) * 30 + $jd - 1 );
	$next_first = navid_j2g( $jy + 1, 1, 1 );
	$this_first = navid_j2g( $jy, 1, 1 );
	$year_days  = (int) round( ( gmmktime( 0, 0, 0, $next_first[1], $next_first[2], $next_first[0] ) - gmmktime( 0, 0, 0, $this_first[1], $this_first[2], $this_first[0] ) ) / DAY_IN_SECONDS );
	$leap       = 366 === $year_days;
	$month_len  = $jm <= 6 ? 31 : ( $jm < 12 ? 30 : ( $leap ? 30 : 29 ) );

	$out = '';
	$len = strlen( $format );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $format[ $i ];
		if ( '\\' === $c ) {
			$i++;
			$out .= $i < $len ? $format[ $i ] : '';
			continue;
		}
		switch ( $c ) {
			case 'd':
				$out .= sprintf( '%02d', $jd );
				break;
			case 'j':
				$out .= $jd;
				break;
			case 'D':
				$out .= $short[ $w ];
				break;
			case 'l':
				$out .= $days[ $w ];
				break;
			case 'S':
				$out .= 'ام';
				break;
			case 'z':
				$out .= $doy;
				break;
			case 'F':
			case 'M':
				$out .= $months[ $jm ];
				break;
			case 'm':
				$out .= sprintf( '%02d', $jm );
				break;
			case 'n':
				$out .= $jm;
				break;
			case 't':
				$out .= $month_len;
				break;
			case 'L':
				$out .= $leap ? '1' : '0';
				break;
			case 'Y':
			case 'o':
				$out .= $jy;
				break;
			case 'y':
				$out .= sprintf( '%02d', $jy % 100 );
				break;
			case 'a':
				$out .= ( (int) $dt->format( 'G' ) < 12 ) ? 'ق.ظ' : 'ب.ظ';
				break;
			case 'A':
				$out .= ( (int) $dt->format( 'G' ) < 12 ) ? 'قبل از ظهر' : 'بعد از ظهر';
				break;
			case 'c':
			case 'r':
			case 'U':
				// Machine values stay Gregorian (they are read by programs, not people).
				$out .= $dt->format( $c );
				break;
			default:
				$out .= ctype_alpha( $c ) ? $dt->format( $c ) : $c;
		}
	}

	if ( null === $fa_digits ) {
		$fa_digits = navid_jalali_fa_digits();
	}
	return $fa_digits ? navid_fa_digits( $out ) : $out;
}

/**
 * Panel setting: 'all' (site and admin), 'front' (site only) or 'off'.
 *
 * @return string
 */
function navid_jalali_mode() {
	$opt  = function_exists( 'navid_options' ) ? navid_options() : array();
	$mode = isset( $opt['jalali'] ) ? $opt['jalali'] : 'all';
	return in_array( $mode, array( 'all', 'front', 'off' ), true ) ? $mode : 'all';
}

/**
 * Whether dates use Persian digits.
 *
 * @return bool
 */
function navid_jalali_fa_digits() {
	$opt = function_exists( 'navid_options' ) ? navid_options() : array();
	return ! isset( $opt['fa_digits'] ) || '1' === (string) $opt['fa_digits'];
}

/**
 * A Persian date plugin that already converts dates (the theme then stays out of its way
 * so the two never fight; the plugin can be removed, the theme does the same job).
 *
 * @return string Plugin name, or ''.
 */
function navid_jalali_plugin() {
	if ( defined( 'WP_PARSI_VER' ) || function_exists( 'parsidate' ) ) {
		return 'WP-Parsidate';
	}
	if ( function_exists( 'jdate' ) && ( defined( 'MPS_JD_VER' ) || defined( 'WPJ_VERSION' ) ) ) {
		return 'Persian Date';
	}
	return '';
}

/**
 * Whether a format prints a date for people (and may therefore become Jalali).
 *
 * @param string $format PHP date format.
 * @return bool
 */
function navid_jalali_is_display_format( $format ) {
	$format = (string) $format;
	$machine = array(
		'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y-m', 'Ymd', 'YmdHis', 'Ym', 'Y', 'U', 'c', 'r', 'G', 'H', 'i', 's',
		'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sO', DATE_ATOM, DATE_RFC2822, DATE_RSS, DATE_W3C, DATE_ISO8601,
		'D, d M Y H:i:s', 'D, d M Y H:i:s O', 'D, d M Y H:i:s +0000', 'H:i:s', 'H:i',
	);
	if ( in_array( $format, $machine, true ) ) {
		return false;
	}
	$plain = preg_replace( '/\\\\./', '', $format );
	if ( in_array( $format, array( get_option( 'date_format' ), get_option( 'time_format' ) ), true ) ) {
		return true;
	}
	// Month or day names, a non-padded day, or a slash: written for a reader.
	return (bool) preg_match( '/[FMlDjS\/]/', $plain );
}

/**
 * The filter on wp_date() (date_i18n() and every WordPress / WooCommerce date use it).
 *
 * @param string       $date      Formatted date.
 * @param string       $format    Format.
 * @param int          $timestamp Timestamp.
 * @param DateTimeZone $timezone  Zone.
 * @return string
 */
function navid_jalali_wp_date( $date, $format, $timestamp, $timezone ) {
	static $busy = false;
	if ( $busy || ! navid_jalali_active() || ! navid_jalali_is_display_format( $format ) ) {
		return $date;
	}
	$busy = true;
	$out  = navid_jdate( $format, $timestamp, $timezone );
	$busy = false;
	return $out;
}

/**
 * Whether the conversion runs for this request.
 *
 * @return bool
 */
function navid_jalali_active() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$mode = navid_jalali_mode();
	if ( 'off' === $mode || '' !== navid_jalali_plugin() ) {
		return false;
	}
	// Feeds, sitemaps and the REST API exchange data with other programs.
	if ( ( function_exists( 'is_feed' ) && did_action( 'parse_query' ) && is_feed() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}
	if ( 'front' === $mode && is_admin() && ! wp_doing_ajax() ) {
		return false;
	}
	// Decided once the query is known (feeds), so earlier calls do not freeze the answer.
	if ( did_action( 'wp' ) || is_admin() ) {
		$cache = true;
	}
	return true;
}
add_filter( 'wp_date', 'navid_jalali_wp_date', 20, 4 );

/**
 * Counts printed on the site (comments, products in a category, …) in Persian digits.
 *
 * @param string $formatted Number.
 * @return string
 */
function navid_jalali_number( $formatted ) {
	if ( is_admin() || ! navid_jalali_fa_digits() ) {
		return $formatted;
	}
	return navid_fa_digits( $formatted );
}
add_filter( 'number_format_i18n', 'navid_jalali_number', 20 );

/**
 * Jalali "today" as numbers.
 *
 * @return int[] array( year, month, day )
 */
function navid_jalali_today() {
	$dt = new DateTime( 'now', wp_timezone() );
	return navid_g2j( (int) $dt->format( 'Y' ), (int) $dt->format( 'n' ), (int) $dt->format( 'j' ) );
}
