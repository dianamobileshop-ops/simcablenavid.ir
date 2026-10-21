<?php
/**
 * WooCommerce payment method: Behpardakht Mellat.
 *
 * Flow: checkout → receipt page asks the bank for a token (bpPayRequest) and sends the
 * customer to the bank → the bank posts the result to the callback → verify + settle →
 * order paid. The callback identifies the order by its id and order key (the bank's POST
 * is cross-site, so the browser does not send the shop's session cookies with it).
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

class Navid_Gateway_Mellat extends WC_Payment_Gateway {

	const ID = 'navid_mellat';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = 'بانک ملت (به‌پرداخت ملت)';
		$this->method_description = 'پرداخت آنلاین با همهٔ کارت‌های عضو شتاب از درگاه به‌پرداخت ملت. شمارهٔ ترمینال، نام کاربری و رمز را از بانک بگیرید و IP سرور سایت را به بانک اعلام کنید.';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = apply_filters( 'navid_mellat_icon', get_template_directory_uri() . '/assets/img/mellat.svg' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => 'فعال‌سازی',
				'type'    => 'checkbox',
				'label'   => 'درگاه بانک ملت فعال باشد',
				'default' => 'no',
			),
			'title'       => array(
				'title'   => 'عنوان در صفحهٔ پرداخت',
				'type'    => 'text',
				'default' => 'پرداخت آنلاین (بانک ملت)',
			),
			'description' => array(
				'title'   => 'توضیح',
				'type'    => 'textarea',
				'default' => 'پرداخت امن با همهٔ کارت‌های بانکی عضو شتاب.',
			),
			'terminal'    => array(
				'title'       => 'شمارهٔ ترمینال',
				'type'        => 'text',
				'description' => 'Terminal ID که بانک داده است.',
			),
			'username'    => array(
				'title' => 'نام کاربری',
				'type'  => 'text',
			),
			'password'    => array(
				'title' => 'رمز عبور',
				'type'  => 'password',
			),
		);
	}

	/**
	 * Only for Iranian currencies and with the bank details filled in.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( null === self::rial_factor() ) {
			return false;
		}
		return '' !== trim( (string) $this->get_option( 'terminal' ) ) && '' !== (string) $this->get_option( 'username' ) && '' !== (string) $this->get_option( 'password' );
	}

	/**
	 * Store currency → Rials multiplier (the bank works in Rials).
	 *
	 * @return int|null
	 */
	public static function rial_factor() {
		$map = apply_filters( 'navid_mellat_rial_factors', array(
			'IRR'  => 1,
			'IRT'  => 10,
			'IRHR' => 1000,
			'IRHT' => 10000,
		) );
		$cur = get_woocommerce_currency();
		return isset( $map[ $cur ] ) ? (int) $map[ $cur ] : null;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return int Rials.
	 */
	public static function order_rials( $order ) {
		return (int) round( (float) $order->get_total() * (int) self::rial_factor() );
	}

	/**
	 * @return Navid_Mellat_Client
	 */
	private function client() {
		return new Navid_Mellat_Client( $this->get_option( 'terminal' ), $this->get_option( 'username' ), $this->get_option( 'password' ) );
	}

	/**
	 * Checkout: on to the receipt page, which talks to the bank.
	 *
	 * @param int $order_id Order.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Receipt page: get a token and hand the customer to the bank.
	 *
	 * @param int $order_id Order.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$amount = self::order_rials( $order );
		if ( $amount < 1000 ) {
			echo '<p class="navid-pay-error">' . esc_html( 'مبلغ سفارش برای پرداخت آنلاین کمتر از حد مجاز بانک است.' ) . '</p>';
			return;
		}

		// A fresh number for every attempt: the bank refuses a repeated orderId (code 41).
		$attempt  = (int) ( time() . sprintf( '%03d', wp_rand( 0, 999 ) ) );
		$callback = add_query_arg( array(
			'order' => $order->get_id(),
			'key'   => $order->get_order_key(),
		), WC()->api_request_url( self::ID ) );

		$result = $this->client()->pay_request( $attempt, $amount, $callback, 'سفارش ' . $order->get_order_number() );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'درگاه ملت: درخواست پرداخت ناموفق بود. ' . $result->get_error_message() );
			if ( class_exists( 'NPT_Log' ) ) {
				NPT_Log::add( 'درگاه ملت', 'سفارش ' . $order->get_order_number() . ': ' . $result->get_error_message() );
			}
			echo '<div class="navid-pay-box is-bad"><p>' . esc_html( 'اتصال به درگاه بانک انجام نشد: ' . $result->get_error_message() ) . '</p>';
			echo '<a class="navid-btn navid-btn--primary" href="' . esc_url( $order->get_checkout_payment_url( true ) ) . '">' . esc_html( 'تلاش دوباره' ) . '</a></div>';
			return;
		}

		$order->update_meta_data( '_navid_mellat_attempt', (string) $attempt );
		$order->update_meta_data( '_navid_mellat_amount', (string) $amount );
		$order->update_meta_data( '_navid_mellat_ref', $result['ref_id'] );
		$order->save();

		$mobile = preg_replace( '/\D+/', '', navid_en_digits( (string) $order->get_billing_phone() ) );
		$mobile = preg_match( '/^0?9\d{9}$/', $mobile ) ? '98' . substr( $mobile, -10 ) : '';
		?>
		<div class="navid-pay-box">
			<p><?php echo esc_html( 'در حال انتقال به درگاه امن بانک ملت…' ); ?></p>
			<form id="navid-mellat-form" method="post" action="<?php echo esc_url( Navid_Mellat_Client::START_PAY ); ?>">
				<input type="hidden" name="RefId" value="<?php echo esc_attr( $result['ref_id'] ); ?>">
				<?php if ( $mobile ) : ?><input type="hidden" name="MobileNo" value="<?php echo esc_attr( $mobile ); ?>"><?php endif; ?>
				<button type="submit" class="navid-btn navid-btn--primary"><?php echo esc_html( 'پرداخت ' . wp_strip_all_tags( wc_price( $order->get_total() ) ) ); ?></button>
			</form>
			<script>document.getElementById('navid-mellat-form').submit();</script>
		</div>
		<?php
	}

	/**
	 * The bank's answer (wc-api=navid_mellat).
	 */
	public static function callback() {
		// phpcs:disable WordPress.Security.NonceVerification -- the bank posts here; the order key and the bank's own verification protect it.
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$res_code  = isset( $_POST['ResCode'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['ResCode'] ) ) : '';
		$sale_id   = isset( $_POST['SaleOrderId'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['SaleOrderId'] ) ) : '';
		$reference = isset( $_POST['SaleReferenceId'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['SaleReferenceId'] ) ) : '';
		$card      = isset( $_POST['CardHolderPan'] ) ? sanitize_text_field( wp_unslash( $_POST['CardHolderPan'] ) ) : '';
		// phpcs:enable
		$attempt = (string) $order->get_meta( '_navid_mellat_attempt' );

		if ( '0' !== $res_code ) {
			$code = '' === $res_code ? '17' : $res_code;
			$order->add_order_note( 'درگاه ملت: پرداخت انجام نشد. ' . Navid_Mellat_Client::message( $code ) );
			self::fail_redirect( $order, $code );
		}
		if ( '' === $attempt || $sale_id !== $attempt || '' === $reference ) {
			$order->add_order_note( 'درگاه ملت: پاسخ بانک با این سفارش نمی‌خواند (شمارهٔ درخواست ' . $sale_id . ').' );
			self::fail_redirect( $order, '55' );
		}

		// Two answers for one payment (double submit, back button) must not both settle.
		$lock = 'navid_mellat_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );

		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = isset( $gateways[ self::ID ] ) ? $gateways[ self::ID ] : new self();
		$result   = $gateway->client()->verify_and_settle( $attempt, $sale_id, $reference );
		delete_transient( $lock );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'درگاه ملت: تأیید پرداخت ناموفق بود و مبلغ به حساب مشتری برمی‌گردد. ' . $result->get_error_message() . ' (کد پیگیری ' . $reference . ')' );
			$data = $result->get_error_data();
			self::fail_redirect( $order, is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '34' );
		}

		$order->update_meta_data( '_navid_mellat_reference', $reference );
		if ( $card ) {
			$order->update_meta_data( '_navid_mellat_card', $card );
		}
		$order->save();
		$order->add_order_note( 'درگاه ملت: پرداخت موفق. کد پیگیری ' . $reference . ( $card ? ' — کارت ' . $card : '' ) );
		$order->payment_complete( $reference );
		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	/**
	 * Back to the order's pay page with the reason (shown there, see pay_notice()).
	 *
	 * @param WC_Order $order Order.
	 * @param string   $code  Bank code.
	 */
	private static function fail_redirect( $order, $code ) {
		if ( $order->has_status( 'pending' ) ) {
			$order->update_status( 'failed' );
		}
		wp_safe_redirect( add_query_arg( 'mellat', rawurlencode( (string) $code ), $order->get_checkout_payment_url() ) );
		exit;
	}

	/**
	 * The failure reason on the pay page (the bank's cross-site POST had no session,
	 * so a normal WooCommerce notice would be lost).
	 */
	public static function pay_notice() {
		if ( empty( $_GET['mellat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = preg_replace( '/\D+/', '', wp_unslash( $_GET['mellat'] ) ); // phpcs:ignore
		wc_print_notice( 'پرداخت انجام نشد: ' . Navid_Mellat_Client::message( $code ) . ' می‌توانید دوباره پرداخت کنید.', 'error' );
	}

	/**
	 * Tracking code in the admin order screen and the customer's order view.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function show_reference( $order ) {
		$ref = $order->get_meta( '_navid_mellat_reference' );
		if ( $ref ) {
			echo '<p><strong>' . esc_html( 'کد پیگیری بانک ملت:' ) . '</strong> <span dir="ltr">' . esc_html( $ref ) . '</span></p>';
		}
	}
}
