<?php
/**
 * Behpardakht Mellat payment method: loader. Nothing here runs without WooCommerce.
 *
 * Settings: WooCommerce → Settings → Payments → بانک ملت (also linked from پنل نوید).
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-navid-mellat-client.php';

/**
 * Registers the payment method once WooCommerce is loaded.
 */
function navid_mellat_boot() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once __DIR__ . '/class-navid-gateway-mellat.php';

	add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
		$methods[] = 'Navid_Gateway_Mellat';
		return $methods;
	} );
	add_action( 'woocommerce_api_' . Navid_Gateway_Mellat::ID, array( 'Navid_Gateway_Mellat', 'callback' ) );
	add_action( 'before_woocommerce_pay', array( 'Navid_Gateway_Mellat', 'pay_notice' ) );
	add_action( 'woocommerce_admin_order_data_after_billing_address', array( 'Navid_Gateway_Mellat', 'show_reference' ) );
	add_action( 'woocommerce_order_details_after_order_table', array( 'Navid_Gateway_Mellat', 'show_reference' ) );

	// Block checkout (Cart/Checkout blocks) needs its own small registration.
	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once __DIR__ . '/class-navid-mellat-blocks.php';
		$registry->register( new Navid_Mellat_Blocks() );
	} );
}
if ( did_action( 'woocommerce_loaded' ) ) {
	navid_mellat_boot();
} else {
	add_action( 'woocommerce_loaded', 'navid_mellat_boot' );
}
