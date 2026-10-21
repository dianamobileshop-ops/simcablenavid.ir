<?php
/**
 * Behpardakht Mellat (bpm.shaparak.ir) web service client.
 *
 * Talks SOAP with plain HTTPS requests (wp_remote_post), so the PHP SOAP extension is
 * not needed. Every call returns the bank's raw answer string, or a WP_Error.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

class Navid_Mellat_Client {

	const ENDPOINT  = 'https://bpm.shaparak.ir/pgwchannel/services/pgw';
	const START_PAY = 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat';
	const NS        = 'http://interfaces.core.sw.bps.com/';

	/** @var int */
	private $terminal;
	/** @var string */
	private $user;
	/** @var string */
	private $pass;

	/**
	 * @param int|string $terminal Terminal number.
	 * @param string     $user     Web service user name.
	 * @param string     $pass     Web service password.
	 */
	public function __construct( $terminal, $user, $pass ) {
		$this->terminal = (int) preg_replace( '/\D+/', '', (string) $terminal );
		$this->user     = (string) $user;
		$this->pass     = (string) $pass;
	}

	/**
	 * Asks for a payment token.
	 *
	 * @param int    $order_id    Unique number of this attempt (never reused).
	 * @param int    $amount      Amount in Rials.
	 * @param string $callback    Address the bank posts the result to.
	 * @param string $additional  Free text shown in the bank's report.
	 * @return array|WP_Error array( 'ref_id' => … ) or the bank's error.
	 */
	public function pay_request( $order_id, $amount, $callback, $additional = '' ) {
		$now = new DateTime( 'now', new DateTimeZone( 'Asia/Tehran' ) );
		$raw = $this->call( 'bpPayRequest', array(
			'terminalId'     => $this->terminal,
			'userName'       => $this->user,
			'userPassword'   => $this->pass,
			'orderId'        => (int) $order_id,
			'amount'         => (int) $amount,
			'localDate'      => $now->format( 'Ymd' ),
			'localTime'      => $now->format( 'His' ),
			'additionalData' => $additional,
			'callBackUrl'    => $callback,
			'payerId'        => 0,
		) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$parts = array_map( 'trim', explode( ',', $raw ) );
		if ( '0' === $parts[0] && ! empty( $parts[1] ) ) {
			return array( 'ref_id' => $parts[1] );
		}
		return self::error( $parts[0] );
	}

	/**
	 * Verify, then settle (the money only moves after settle).
	 *
	 * @param int        $order_id       Attempt number sent in pay_request().
	 * @param int|string $sale_order_id  SaleOrderId from the callback.
	 * @param int|string $sale_reference SaleReferenceId from the callback.
	 * @return true|WP_Error
	 */
	public function verify_and_settle( $order_id, $sale_order_id, $sale_reference ) {
		$args = array(
			'terminalId'      => $this->terminal,
			'userName'        => $this->user,
			'userPassword'    => $this->pass,
			'orderId'         => (int) $order_id,
			'saleOrderId'     => (int) $sale_order_id,
			'saleReferenceId' => (int) $sale_reference,
		);

		$verify = $this->call( 'bpVerifyRequest', $args );
		// 43 = already verified (e.g. the visitor pressed back and the bank called again).
		if ( is_wp_error( $verify ) || ! in_array( trim( $verify ), array( '0', '43' ), true ) ) {
			// No clear answer: ask the bank what happened before giving up.
			$inquiry = $this->call( 'bpInquiryRequest', $args );
			if ( is_wp_error( $inquiry ) || '0' !== trim( $inquiry ) ) {
				$this->call( 'bpReversalRequest', $args );
				return is_wp_error( $verify ) ? $verify : self::error( trim( $verify ) );
			}
		}

		$settle = $this->call( 'bpSettleRequest', $args );
		// 45 = already settled.
		if ( ! is_wp_error( $settle ) && in_array( trim( $settle ), array( '0', '45' ), true ) ) {
			return true;
		}
		// Settle failed: give the money back so the customer is not charged for an unpaid order.
		$this->call( 'bpReversalRequest', $args );
		return is_wp_error( $settle ) ? $settle : self::error( trim( $settle ) );
	}

	/**
	 * One SOAP call.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Parameters, in the order of the WSDL.
	 * @return string|WP_Error Contents of <return>.
	 */
	public function call( $method, $args ) {
		$body = '';
		foreach ( $args as $key => $value ) {
			$body .= '<' . $key . '>' . htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</' . $key . '>';
		}
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="' . self::NS . '">'
			. '<soapenv:Body><ns1:' . $method . '>' . $body . '</ns1:' . $method . '></soapenv:Body></soapenv:Envelope>';

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'text/xml; charset=utf-8',
				'SOAPAction'   => '""',
			),
			'body'    => $xml,
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mellat_http', 'ارتباط با بانک ملت برقرار نشد: ' . $response->get_error_message() );
		}
		$text = (string) wp_remote_retrieve_body( $response );
		if ( preg_match( '#<return>(.*?)</return>#s', $text, $m ) ) {
			return html_entity_decode( trim( $m[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
		}
		if ( preg_match( '#<faultstring>(.*?)</faultstring>#s', $text, $m ) ) {
			return new WP_Error( 'mellat_fault', 'خطای وب‌سرویس بانک: ' . wp_strip_all_tags( $m[1] ) );
		}
		return new WP_Error( 'mellat_reply', 'پاسخ نامعتبر از بانک (کد HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ').' );
	}

	/**
	 * The bank's numeric result code as a Persian error.
	 *
	 * @param string $code Code.
	 * @return WP_Error
	 */
	public static function error( $code ) {
		return new WP_Error( 'mellat_' . $code, self::message( $code ), array( 'code' => (string) $code ) );
	}

	/**
	 * @param string $code Result code.
	 * @return string
	 */
	public static function message( $code ) {
		$messages = array(
			'0'   => 'تراکنش با موفقیت انجام شد.',
			'11'  => 'شماره کارت نامعتبر است.',
			'12'  => 'موجودی کافی نیست.',
			'13'  => 'رمز نادرست است.',
			'14'  => 'تعداد دفعات وارد کردن رمز بیش از حد مجاز است.',
			'15'  => 'کارت نامعتبر است.',
			'16'  => 'دفعات برداشت وجه بیش از حد مجاز است.',
			'17'  => 'پرداخت را لغو کردید.',
			'18'  => 'تاریخ انقضای کارت گذشته است.',
			'19'  => 'مبلغ برداشت وجه بیش از حد مجاز است.',
			'111' => 'صادرکنندهٔ کارت نامعتبر است.',
			'112' => 'خطای سوییچ صادرکنندهٔ کارت.',
			'113' => 'پاسخی از صادرکنندهٔ کارت دریافت نشد.',
			'114' => 'دارندهٔ کارت مجاز به انجام این تراکنش نیست.',
			'21'  => 'پذیرنده نامعتبر است.',
			'23'  => 'خطای امنیتی رخ داده است.',
			'24'  => 'اطلاعات کاربری پذیرنده (نام کاربری یا رمز درگاه) نامعتبر است.',
			'25'  => 'مبلغ نامعتبر است.',
			'31'  => 'پاسخ نامعتبر است.',
			'32'  => 'فرمت اطلاعات واردشده صحیح نیست.',
			'33'  => 'حساب نامعتبر است.',
			'34'  => 'خطای سیستمی بانک.',
			'35'  => 'تاریخ نامعتبر است.',
			'41'  => 'شمارهٔ درخواست تکراری است.',
			'42'  => 'تراکنش خرید پیدا نشد.',
			'43'  => 'قبلاً درخواست تأیید داده شده است.',
			'44'  => 'درخواست تأیید پیدا نشد.',
			'45'  => 'تراکنش قبلاً تسویه شده است.',
			'46'  => 'تراکنش تسویه نشده است.',
			'47'  => 'تراکنش تسویه پیدا نشد.',
			'48'  => 'تراکنش برگشت خورده است.',
			'49'  => 'تراکنش بازگشت وجه پیدا نشد.',
			'412' => 'شناسهٔ قبض نادرست است.',
			'413' => 'شناسهٔ پرداخت نادرست است.',
			'414' => 'سازمان صادرکنندهٔ قبض نامعتبر است.',
			'415' => 'زمان جلسهٔ کاری به پایان رسیده است.',
			'416' => 'خطا در ثبت اطلاعات.',
			'417' => 'شناسهٔ پرداخت‌کننده نامعتبر است.',
			'418' => 'اشکال در تعریف اطلاعات مشتری.',
			'419' => 'تعداد دفعات ورود اطلاعات بیش از حد مجاز است.',
			'421' => 'IP سرور سایت در درگاه ملت ثبت نشده است.',
			'51'  => 'تراکنش تکراری است.',
			'54'  => 'تراکنش مرجع موجود نیست.',
			'55'  => 'تراکنش نامعتبر است.',
			'61'  => 'خطا در واریز.',
		);
		$code = (string) $code;
		return isset( $messages[ $code ] ) ? $messages[ $code ] : 'خطای ناشناخته از بانک (کد ' . $code . ').';
	}
}
