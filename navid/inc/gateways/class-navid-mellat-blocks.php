<?php
/**
 * Mellat in the Checkout block.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Navid_Mellat_Blocks extends AbstractPaymentMethodType {

	/** @var string */
	protected $name = 'navid_mellat';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_navid_mellat_settings', array() );
	}

	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['navid_mellat'] ) && $gateways['navid_mellat']->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'navid-mellat-blocks',
			get_template_directory_uri() . '/assets/js/mellat-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			NAVID_VERSION,
			true
		);
		return array( 'navid-mellat-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', 'پرداخت آنلاین (بانک ملت)' ),
			'description' => $this->get_setting( 'description', '' ),
			'icon'        => get_template_directory_uri() . '/assets/img/mellat.svg',
			'supports'    => array( 'products' ),
		);
	}
}
