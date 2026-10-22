<?php
/**
 * Login protection for every account (customers, business managers, administrators).
 * Each protection has a switch in Settings → امنیت و ورود.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * A security option.
 *
 * @param string $key Option key.
 * @return string
 */
function navid_sec( $key ) {
	$opt = navid_options();
	return isset( $opt[ $key ] ) ? (string) $opt[ $key ] : '';
}

/**
 * Visitor address for the counters.
 *
 * @return string
 */
function navid_sec_ip() {
	if ( function_exists( 'npt_client_ip' ) ) {
		return (string) npt_client_ip();
	}
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Transient name for a counter or a lock.
 *
 * @param string $kind  fail|lock.
 * @param string $scope ip|user.
 * @param string $value Address or user name.
 * @return string
 */
function navid_sec_key( $kind, $scope, $value ) {
	return 'navid_' . $kind . '_' . $scope . '_' . substr( hash_hmac( 'sha256', strtolower( (string) $value ), wp_salt( 'auth' ) ), 0, 24 );
}

/**
 * Whether this address or this user name is locked right now.
 *
 * @param string $username User name or email typed in the form.
 * @return bool
 */
function navid_sec_is_locked( $username ) {
	return (bool) get_transient( navid_sec_key( 'lock', 'ip', navid_sec_ip() . '|' . $username ) )
		|| (bool) get_transient( navid_sec_key( 'lock', 'user', $username ) );
}

/**
 * A locked address/user is refused even with the right password.
 *
 * @param mixed  $user     WP_User, WP_Error or null.
 * @param string $username Typed user name.
 * @return mixed
 */
function navid_sec_authenticate( $user, $username ) {
	if ( '1' !== navid_sec( 'sec_limit' ) || '' === (string) $username ) {
		return $user;
	}
	if ( navid_sec_is_locked( $username ) ) {
		return new WP_Error( 'navid_locked', sprintf( 'تلاش‌های ناموفق زیاد بود. %s دقیقه بعد دوباره امتحان کنید.', navid_fa_digits( (string) max( 5, (int) navid_sec( 'sec_lock' ) ) ) ) );
	}
	return $user;
}
add_filter( 'authenticate', 'navid_sec_authenticate', 100, 2 );

/**
 * Counts a failed password and locks after the allowed number.
 *
 * @param string $username Typed user name.
 */
function navid_sec_failed( $username ) {
	if ( '1' !== navid_sec( 'sec_limit' ) || '' === (string) $username ) {
		return;
	}
	$max  = max( 3, (int) navid_sec( 'sec_max' ) );
	$lock = max( 5, (int) navid_sec( 'sec_lock' ) ) * MINUTE_IN_SECONDS;
	$ip   = navid_sec_ip();
	foreach ( array( array( 'ip', $ip . '|' . $username ), array( 'user', $username ) ) as $pair ) {
		$counter = navid_sec_key( 'fail', $pair[0], $pair[1] );
		$count   = (int) get_transient( $counter ) + 1;
		set_transient( $counter, $count, 15 * MINUTE_IN_SECONDS );
		if ( $count >= $max && ! get_transient( navid_sec_key( 'lock', $pair[0], $pair[1] ) ) ) {
			set_transient( navid_sec_key( 'lock', $pair[0], $pair[1] ), 1, $lock );
			navid_log( 'امنیت', 'ورود قفل شد: ' . $count . ' رمز اشتباه برای «' . mb_substr( sanitize_user( $username, true ), 0, 40 ) . '» (' . ( 'ip' === $pair[0] ? 'از یک IP' : 'روی حساب' ) . ').' );
		}
	}
}
add_action( 'wp_login_failed', 'navid_sec_failed' );

/**
 * A good login clears the counters of that user name.
 *
 * @param string  $login User login.
 * @param WP_User $user  User.
 */
function navid_sec_success( $login, $user ) {
	delete_transient( navid_sec_key( 'fail', 'user', $login ) );
	delete_transient( navid_sec_key( 'fail', 'ip', navid_sec_ip() . '|' . $login ) );
	if ( $user instanceof WP_User && $user->user_email ) {
		delete_transient( navid_sec_key( 'fail', 'user', $user->user_email ) );
	}
}
add_action( 'wp_login', 'navid_sec_success', 10, 2 );

/**
 * The same message for an unknown user and a wrong password.
 *
 * @param mixed $user User or error.
 * @return mixed
 */
function navid_sec_generic_error( $user ) {
	if ( '1' === navid_sec( 'sec_generic' ) && is_wp_error( $user ) && in_array( $user->get_error_code(), array( 'invalid_username', 'invalid_email', 'incorrect_password' ), true ) ) {
		return new WP_Error( 'navid_bad_login', 'نام کاربری یا رمز عبور درست نیست.' );
	}
	return $user;
}
add_filter( 'authenticate', 'navid_sec_generic_error', 99 );

/**
 * No guessing of user names through ?author=N or the REST users list.
 */
function navid_sec_block_enumeration() {
	if ( '1' !== navid_sec( 'sec_enum' ) || is_user_logged_in() ) {
		return;
	}
	if ( isset( $_GET['author'] ) && ( is_numeric( $_GET['author'] ) || is_array( $_GET['author'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'navid_sec_block_enumeration', 0 );

/**
 * REST: the users list is for signed-in users only.
 *
 * @param array $endpoints Routes.
 * @return array
 */
function navid_sec_rest_users( $endpoints ) {
	if ( '1' === navid_sec( 'sec_enum' ) && ! is_user_logged_in() ) {
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
}
add_filter( 'rest_endpoints', 'navid_sec_rest_users' );

/**
 * XML-RPC off.
 *
 * @param bool $enabled Current value.
 * @return bool
 */
function navid_sec_xmlrpc( $enabled ) {
	return '1' === navid_sec( 'sec_xmlrpc' ) ? false : $enabled;
}
add_filter( 'xmlrpc_enabled', 'navid_sec_xmlrpc' );

/**
 * Administrators may log in with an SMS code only when the owner switched that on.
 * Otherwise the price-table module's own rule stays (administrators need their password).
 *
 * @param bool    $block Current decision.
 * @param WP_User $user  User.
 * @return bool
 */
function navid_sec_admin_sms( $block, $user ) {
	unset( $user );
	return '1' === navid_sec( 'sec_admin_sms' ) ? false : $block;
}
add_filter( 'npt_otp_block_user', 'navid_sec_admin_sms', 20, 2 );
