<?php
/**
 * Web Push + PWA for the employer panel.
 *
 * What it does
 *   - serves a web-app manifest and a service worker for the panel, so the
 *     employer can «add to home screen» and receive notifications;
 *   - stores one push subscription per installed device;
 *   - on every new public proforma sends a push to those devices.
 *
 * Why the push carries no payload
 *   Encrypted payloads (RFC 8291) would need ECDH + HKDF + AES-GCM on the
 *   server. An empty push signed with VAPID needs only an ES256 JWT, which
 *   OpenSSL does natively. The service worker, on receiving the push, asks the
 *   site for the latest notification text (the request carries the employer's
 *   cookies, so the customer's name never travels through the push service).
 *
 * Everything degrades quietly: no OpenSSL EC support, no HTTPS or an
 * unsupported browser only means the «enable notifications» button explains
 * why it cannot work; SMS notification keeps working independently.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPT_Push {

	const KEYS_OPT = 'npt_push_keys';
	const SUBS_OPT = 'npt_push_subs';
	const LAST_OPT = 'npt_push_last';
	const MAX_SUBS = 20;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve_assets' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'head_tags' ), 1 );
		add_action( 'wp_ajax_npt_push_subscribe', array( __CLASS__, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_npt_push_unsubscribe', array( __CLASS__, 'ajax_unsubscribe' ) );
		add_action( 'wp_ajax_npt_push_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_npt_push_latest', array( __CLASS__, 'ajax_latest' ) );
		add_action( 'wp_ajax_nopriv_npt_push_latest', array( __CLASS__, 'ajax_latest' ) );
		add_action( 'npt_proforma_created', array( __CLASS__, 'on_proforma' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Availability / URLs
	 * ------------------------------------------------------------------- */
	public static function available() {
		return extension_loaded( 'openssl' ) && function_exists( 'openssl_pkey_new' ) && false !== self::keys();
	}

	public static function public_key() {
		$keys = self::keys();
		return $keys ? $keys['pub'] : '';
	}

	public static function sw_url() {
		return add_query_arg( 'npt_sw', '1', home_url( '/' ) );
	}

	public static function manifest_url() {
		return add_query_arg( 'npt_manifest', '1', home_url( '/' ) );
	}

	public static function device_count() {
		return count( self::subs() );
	}

	public static function notify_push_enabled() {
		return (bool) get_option( 'npt_notify_push', 1 );
	}

	/* ---------------------------------------------------------------------
	 * Manifest + service worker (served from the site root, no files on disk)
	 * ------------------------------------------------------------------- */
	public static function serve_assets() {
		if ( isset( $_GET['npt_manifest'] ) ) { // phpcs:ignore
			self::serve_manifest();
		}
		if ( isset( $_GET['npt_sw'] ) ) { // phpcs:ignore
			self::serve_sw();
		}
	}

	private static function serve_manifest() {
		$panel = class_exists( 'NPT_Panel' ) ? NPT_Panel::panel_url() : home_url( '/' );
		$color = vsprintf( '#%02x%02x%02x', npt_header_color_rgb() );
		$data  = array(
			'name'             => 'پنل ' . get_bloginfo( 'name' ),
			'short_name'       => 'پنل نوید',
			'lang'             => 'fa',
			'dir'              => 'rtl',
			'start_url'        => $panel,
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => $color,
			'icons'            => array(
				array( 'src' => npt_media_image_url( 'npt_app_icon_id', NPT_URL . 'assets/images/pwa-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' ),
				array( 'src' => npt_media_image_url( 'npt_app_icon_id', NPT_URL . 'assets/images/pwa-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ),
			),
		);
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private static function serve_sw() {
		$panel  = class_exists( 'NPT_Panel' ) ? NPT_Panel::panel_url() : home_url( '/' );
		$latest = add_query_arg( 'action', 'npt_push_latest', admin_url( 'admin-ajax.php' ) );
		$icon   = npt_media_image_url( 'npt_app_icon_id', NPT_URL . 'assets/images/pwa-192.png' );
		$js     = "var PANEL=" . wp_json_encode( $panel ) . ",LATEST=" . wp_json_encode( $latest ) . ",ICON=" . wp_json_encode( $icon ) . ";\n"
			. <<<'JS'
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
// Chrome only offers the install prompt to a worker that has a fetch handler.
self.addEventListener('fetch', function () {});
self.addEventListener('push', function (e) {
	var fallback = { title: 'پیش‌فاکتور جدید', body: 'یک پیش‌فاکتور تازه ثبت شد.', url: PANEL };
	e.waitUntil(
		fetch(LATEST, { credentials: 'include', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (j) { return (j && j.success && j.data) ? j.data : fallback; })
			.catch(function () { return fallback; })
			.then(function (d) {
				return self.registration.showNotification(d.title || fallback.title, {
					body: d.body || '',
					icon: ICON,
					badge: ICON,
					tag: 'npt-proforma',
					renotify: true,
					dir: 'rtl',
					lang: 'fa',
					data: { url: d.url || PANEL }
				});
			})
	);
});
self.addEventListener('notificationclick', function (e) {
	e.notification.close();
	var url = (e.notification.data && e.notification.data.url) || PANEL;
	e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
		for (var i = 0; i < list.length; i++) {
			if (list[i].url.indexOf(PANEL) === 0 && 'focus' in list[i]) { return list[i].focus(); }
		}
		return self.clients.openWindow(url);
	}));
});
JS;
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache, max-age=0' );
		header( 'Service-Worker-Allowed: ' . wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		echo $js; // phpcs:ignore
		exit;
	}

	/** manifest + theme colour, only in the head of the panel page. */
	public static function head_tags() {
		if ( ! class_exists( 'NPT_Panel' ) || ! NPT_Panel::on_panel_page() ) {
			return;
		}
		$color = vsprintf( '#%02x%02x%02x', npt_header_color_rgb() );
		echo '<link rel="manifest" href="' . esc_url( self::manifest_url() ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( $color ) . '">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		$panel_icon = npt_media_image_url( 'npt_panel_icon_id', npt_media_image_url( 'npt_app_icon_id', '' ) );
		if ( $panel_icon ) {
			echo '<link rel="icon" href="' . esc_url( $panel_icon ) . '">' . "\n";
		}
		echo '<link rel="apple-touch-icon" href="' . esc_url( npt_media_image_url( 'npt_app_icon_id', NPT_URL . 'assets/images/pwa-192.png' ) ) . '">' . "\n";
	}

	/* ---------------------------------------------------------------------
	 * VAPID keys (P-256) and JWT
	 * ------------------------------------------------------------------- */
	private static function b64u( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function keys() {
		$keys = get_option( self::KEYS_OPT );
		if ( is_array( $keys ) && ! empty( $keys['pem'] ) && ! empty( $keys['pub'] ) ) {
			return $keys;
		}
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return false;
		}
		$res = @openssl_pkey_new( array( // phpcs:ignore
			'curve_name'       => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		) );
		if ( ! $res ) {
			return false;
		}
		$pem = '';
		openssl_pkey_export( $res, $pem );
		$det = openssl_pkey_get_details( $res );
		if ( ! $pem || empty( $det['ec']['x'] ) || empty( $det['ec']['y'] ) ) {
			return false;
		}
		$x    = str_pad( $det['ec']['x'], 32, "\0", STR_PAD_LEFT );
		$y    = str_pad( $det['ec']['y'], 32, "\0", STR_PAD_LEFT );
		$keys = array(
			'pem' => $pem,
			'pub' => self::b64u( "\x04" . $x . $y ),
		);
		update_option( self::KEYS_OPT, $keys, false );
		return $keys;
	}

	/** OpenSSL returns a DER ECDSA signature; JWT wants raw R||S (64 bytes). */
	private static function der_to_raw( $der ) {
		$off = 2;
		if ( ord( $der[1] ) & 0x80 ) {
			$off += ord( $der[1] ) & 0x7f;
		}
		$rlen = ord( $der[ $off + 1 ] );
		$r    = substr( $der, $off + 2, $rlen );
		$off += 2 + $rlen;
		$slen = ord( $der[ $off + 1 ] );
		$s    = substr( $der, $off + 2, $slen );
		$fix  = function ( $n ) {
			return str_pad( ltrim( $n, "\0" ), 32, "\0", STR_PAD_LEFT );
		};
		return $fix( $r ) . $fix( $s );
	}

	private static function authorization( $endpoint ) {
		$keys = self::keys();
		if ( ! $keys ) {
			return '';
		}
		$parts = wp_parse_url( $endpoint );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$aud   = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$head  = self::b64u( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$claim = self::b64u( wp_json_encode( array(
			'aud' => $aud,
			'exp' => time() + 12 * HOUR_IN_SECONDS,
			'sub' => 'mailto:' . get_option( 'admin_email' ),
		), JSON_UNESCAPED_SLASHES ) );
		$input = $head . '.' . $claim;
		$der   = '';
		if ( ! openssl_sign( $input, $der, $keys['pem'], OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}
		return 'vapid t=' . $input . '.' . self::b64u( self::der_to_raw( $der ) ) . ', k=' . $keys['pub'];
	}

	/* ---------------------------------------------------------------------
	 * Subscriptions
	 * ------------------------------------------------------------------- */
	private static function subs() {
		$subs = get_option( self::SUBS_OPT, array() );
		return is_array( $subs ) ? $subs : array();
	}

	private static function save_subs( array $subs ) {
		update_option( self::SUBS_OPT, $subs, false );
	}

	private static function guard() {
		if ( ! check_ajax_referer( NPT_Panel::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'نشست منقضی شده است. صفحه را تازه کنید.' ), 403 );
		}
		if ( ! NPT_Panel::user_can_panel() ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
	}

	public static function ajax_subscribe() {
		self::guard();
		if ( ! self::available() ) {
			wp_send_json_error( array( 'message' => 'سرور از اعلان پوش پشتیبانی نمی‌کند (OpenSSL).' ), 503 );
		}
		$endpoint = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '';
		if ( 0 !== strpos( $endpoint, 'https://' ) || strlen( $endpoint ) > 1000 ) {
			wp_send_json_error( array( 'message' => 'نشانی اشتراک معتبر نیست.' ), 400 );
		}
		$subs = self::subs();
		$id   = md5( $endpoint );
		$subs[ $id ] = array(
			'endpoint' => $endpoint,
			'user'     => get_current_user_id(),
			'time'     => time(),
		);
		if ( count( $subs ) > self::MAX_SUBS ) {
			$subs = array_slice( $subs, -self::MAX_SUBS, null, true );
		}
		self::save_subs( $subs );
		wp_send_json_success( array( 'devices' => count( $subs ) ) );
	}

	public static function ajax_unsubscribe() {
		self::guard();
		$endpoint = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '';
		$subs     = self::subs();
		unset( $subs[ md5( $endpoint ) ] );
		self::save_subs( $subs );
		wp_send_json_success( array( 'devices' => count( $subs ) ) );
	}

	public static function ajax_test() {
		self::guard();
		self::remember( 'اعلان آزمایشی', 'اگر این پیام را می‌بینید، اعلان‌ها روی این دستگاه درست کار می‌کنند.', NPT_Panel::panel_url() );
		$sent = self::broadcast();
		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => 'هیچ دستگاهی ثبت نشده یا ارسال ناموفق بود. اعلان‌ها را دوباره فعال کنید.' ) );
		}
		wp_send_json_success( array( 'sent' => $sent ) );
	}

	/** The service worker asks for this when a push arrives. */
	public static function ajax_latest() {
		$fallback = array(
			'title' => 'پیش‌فاکتور جدید',
			'body'  => 'یک پیش‌فاکتور تازه ثبت شد.',
			'url'   => class_exists( 'NPT_Panel' ) ? NPT_Panel::panel_url() : home_url( '/' ),
		);
		if ( ! class_exists( 'NPT_Panel' ) || ! NPT_Panel::user_can_panel() ) {
			wp_send_json_success( $fallback );
		}
		$last = get_option( self::LAST_OPT );
		if ( ! is_array( $last ) || empty( $last['title'] ) ) {
			wp_send_json_success( $fallback );
		}
		nocache_headers();
		wp_send_json_success( array(
			'title' => (string) $last['title'],
			'body'  => (string) $last['body'],
			'url'   => (string) $last['url'],
		) );
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------- */
	private static function remember( $title, $body, $url ) {
		update_option( self::LAST_OPT, array(
			'title' => $title,
			'body'  => $body,
			'url'   => $url,
			'time'  => time(),
		), false );
	}

	/** Push to every registered device; returns how many accepted it. */
	public static function broadcast() {
		$subs = self::subs();
		if ( ! $subs || ! self::available() ) {
			return 0;
		}
		$sent    = 0;
		$changed = false;
		foreach ( $subs as $id => $sub ) {
			$auth = self::authorization( $sub['endpoint'] );
			if ( '' === $auth ) {
				continue;
			}
			// wp_safe_*: the endpoint is supplied by a browser, never let it point inside the network.
			$res = wp_safe_remote_post( $sub['endpoint'], array(
				'timeout' => 6,
				'headers' => array(
					'Authorization' => $auth,
					'TTL'           => '86400',
					'Urgency'       => 'high',
				),
				'body'    => '',
			) );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code >= 200 && $code < 300 ) {
				$sent++;
			} elseif ( 404 === $code || 410 === $code ) {
				unset( $subs[ $id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::save_subs( $subs );
		}
		return $sent;
	}

	public static function on_proforma( $saved, $info ) {
		if ( ! self::notify_push_enabled() ) {
			return;
		}
		$body = $info['name'];
		if ( ! empty( $info['total'] ) ) {
			$body .= ' — ' . npt_format_price( $info['total'], true );
		}
		$url = add_query_arg( 'npt_tab', 'proformas', NPT_Panel::panel_url() );
		self::remember( 'پیش‌فاکتور جدید #' . npt_to_persian_digits( (string) $saved['number'] ), $body, $url );
		self::broadcast();
	}
}
