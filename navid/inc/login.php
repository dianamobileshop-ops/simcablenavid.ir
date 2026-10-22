<?php
/**
 * Customer login helpers: new sign-ups on/off, and the name asked after a first sign-up.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * New numbers may sign up only when the panel allows it.
 *
 * @param bool $allow Current value.
 * @return bool
 */
function navid_allow_new_customers( $allow ) {
	$opt = navid_options();
	return $allow && '0' !== (string) $opt['login_register'];
}
add_filter( 'npt_otp_allow_new_user', 'navid_allow_new_customers' );

/**
 * Saves «نام و نام خانوادگی» for the signed-in customer (first word = first name).
 */
function navid_ajax_save_name() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'ابتدا وارد شوید.' ), 403 );
	}
	if ( ! check_ajax_referer( 'navid_save_name', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'نشست شما منقضی شده است. صفحه را تازه کنید.' ), 403 );
	}
	$user = wp_get_current_user();
	// Only plain customer accounts: an editor or administrator keeps the name set by the owner.
	if ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' ) ) {
		wp_send_json_success( array( 'message' => 'انجام شد.' ) );
	}
	$name = isset( $_POST['name'] ) ? trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) ) : '';
	if ( mb_strlen( $name ) < 3 || false === strpos( $name, ' ' ) ) {
		wp_send_json_error( array( 'message' => 'نام و نام خانوادگی را کامل وارد کنید.' ), 400 );
	}
	if ( mb_strlen( $name ) > 60 ) {
		wp_send_json_error( array( 'message' => 'نام خیلی طولانی است.' ), 400 );
	}
	$parts = explode( ' ', $name, 2 );
	wp_update_user( array(
		'ID'           => $user->ID,
		'first_name'   => $parts[0],
		'last_name'    => $parts[1],
		'display_name' => $name,
		'nickname'     => $name,
	) );
	update_user_meta( $user->ID, 'billing_first_name', $parts[0] );
	update_user_meta( $user->ID, 'billing_last_name', $parts[1] );
	wp_send_json_success( array( 'message' => 'خوش آمدید ' . $parts[0] . '!' ) );
}
add_action( 'wp_ajax_navid_save_name', 'navid_ajax_save_name' );
