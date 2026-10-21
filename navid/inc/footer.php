<?php
/**
 * Footer helpers: icons, social links, the trust strip and trust badges (eNamad).
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small line icon (24×24, currentColor).
 *
 * @param string $name Icon name.
 * @return string SVG markup.
 */
function navid_icon( $name ) {
	$paths = array(
		'truck'     => '<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
		'shield'    => '<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
		'card'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
		'headset'   => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/>',
		'pin'       => '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
		'phone'     => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
		'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'up'        => '<path d="m6 15 6-6 6 6"/>',
		'telegram'  => '<path d="M21 4 3 11l6 2 2 6 3-4 5 4z"/><path d="m9 13 8-6"/>',
		'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".8"/>',
		'whatsapp'  => '<path d="M4 20l1.3-3.9A8 8 0 1 1 8 19z"/><path d="M9.5 8.5c0 3 3 6 6 6l1-1.5-2-1-1 1c-1-.4-2.1-1.5-2.5-2.5l1-1-1-2z"/>',
		'eitaa'     => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 10h8M8 13h5"/>',
		'bale'      => '<circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/>',
		'aparat'    => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z"/>',
		'linkedin'  => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10v7M8 7v.01M12 17v-4a2 2 0 0 1 4 0v4M12 10v7"/>',
		'cart'      => '<path d="M6 6h15l-1.5 9h-12zM6 6 5 3H2"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/>',
		'home'      => '<path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z"/>',
		'list'      => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
		'panel'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
		'chat'      => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
		'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
		'plus'      => '<path d="M12 5v14M5 12h14"/>',
		'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c1-3.5 3.5-5.5 6.5-5.5s5.5 2 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5c2 .6 3.2 2.5 3.5 5.5"/>',
		'logout'    => '<path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/>',
		'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
		'bell'      => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 20a2 2 0 0 0 4 0"/>',
		'tag'       => '<path d="M3 12V3h9l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
		'send'      => '<path d="M21 3 3 10l7 3 3 7z"/><path d="m10 13 4-4"/>',
		'report'    => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
		'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

/**
 * Social links from the panel (a username or a full address each).
 *
 * @return array[] label, url, icon
 */
function navid_social_links() {
	$opt  = navid_options();
	$bar  = class_exists( 'NPT_Bar' ) ? NPT_Bar::settings() : array();
	$list = array(
		'telegram'  => array( 'تلگرام', 'https://t.me/', '' !== $opt['social_telegram'] ? $opt['social_telegram'] : (string) get_option( 'npt_telegram', '' ) ),
		'instagram' => array( 'اینستاگرام', 'https://instagram.com/', '' !== $opt['social_instagram'] ? $opt['social_instagram'] : (string) get_option( 'npt_instagram', '' ) ),
		'whatsapp'  => array( 'واتس‌اپ', 'https://wa.me/', '' !== $opt['social_whatsapp'] ? $opt['social_whatsapp'] : ( isset( $bar['whatsapp_number'] ) ? (string) $bar['whatsapp_number'] : '' ) ),
		'eitaa'     => array( 'ایتا', 'https://eitaa.com/', $opt['social_eitaa'] ),
		'bale'      => array( 'بله', 'https://ble.ir/', $opt['social_bale'] ),
		'aparat'    => array( 'آپارات', 'https://www.aparat.com/', $opt['social_aparat'] ),
		'linkedin'  => array( 'لینکدین', 'https://www.linkedin.com/company/', $opt['social_linkedin'] ),
	);
	$out = array();
	foreach ( $list as $icon => $item ) {
		$value = trim( (string) $item[2] );
		if ( '' === $value ) {
			continue;
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			$url = $value;
		} elseif ( 'whatsapp' === $icon ) {
			$digits = preg_replace( '/\D+/', '', navid_en_digits( $value ) );
			$url    = 'https://wa.me/' . ( 0 === strpos( $digits, '0' ) ? '98' . substr( $digits, 1 ) : $digits );
		} else {
			$url = $item[1] . ltrim( $value, '@ ' );
		}
		$out[] = array( 'label' => $item[0], 'url' => $url, 'icon' => $icon );
	}
	return $out;
}

/**
 * The strip of promises above the footer columns: up to four «title | text» lines.
 *
 * @return array[] array( title, text )
 */
function navid_footer_features() {
	$opt = navid_options();
	if ( '1' !== (string) $opt['ft_features_show'] ) {
		return array();
	}
	$text = trim( (string) $opt['ft_features'] );
	if ( '' === $text ) {
		$text = "ارسال سریع | به سراسر کشور\nضمانت اصالت کالا | تأمین مستقیم از کارخانه\nپرداخت امن | درگاه بانکی معتبر\nمشاوره رایگان | پیش از خرید با ما تماس بگیرید";
	}
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( '' !== $parts[0] ) {
			$out[] = array( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
		}
	}
	return array_slice( $out, 0, 4 );
}

/**
 * Reads the eNamad id and code from whatever was pasted: the whole HTML snippet,
 * the trustseal link, or «id,code».
 *
 * @param string $raw Pasted text.
 * @return array|null array( id, code )
 */
function navid_parse_enamad( $raw ) {
	$raw = html_entity_decode( (string) $raw, ENT_QUOTES, 'UTF-8' );
	if ( preg_match( '/[?&]id=(\d+)/i', $raw, $id ) && preg_match( '/[?&]Code=([A-Za-z0-9]+)/i', $raw, $code ) ) {
		return array( $id[1], $code[1] );
	}
	if ( preg_match( '/^\s*(\d+)\s*[,|\s]\s*([A-Za-z0-9]+)\s*$/', $raw, $m ) ) {
		return array( $m[1], $m[2] );
	}
	return null;
}

/**
 * Trust badges for the footer. Only the parsed numbers are used, so nothing pasted
 * into the panel is ever printed as HTML.
 *
 * @return string
 */
function navid_trust_badges_html() {
	$opt = navid_options();
	$out = '';
	$en  = navid_parse_enamad( $opt['enamad'] );
	if ( $en ) {
		$query = 'id=' . rawurlencode( $en[0] ) . '&Code=' . rawurlencode( $en[1] );
		$out  .= sprintf(
			'<a class="navid-badge-box" referrerpolicy="origin" target="_blank" rel="noopener" href="%1$s"><img referrerpolicy="origin" src="%2$s" alt="%3$s" width="96" height="104" loading="lazy" style="cursor:pointer" code="%4$s"></a>',
			esc_url( 'https://trustseal.enamad.ir/?' . $query ),
			esc_url( 'https://trustseal.enamad.ir/logo.aspx?' . $query ),
			esc_attr__( 'نماد اعتماد الکترونیکی', 'navid' ),
			esc_attr( $en[1] )
		);
	}
	return $out;
}

/**
 * Copyright line; {year} is the Jalali year and {site} the site name.
 *
 * @return string
 */
function navid_copyright_text() {
	$opt  = navid_options();
	$text = '' !== trim( (string) $opt['copyright'] ) ? $opt['copyright'] : '© {year} {site} — تمامی حقوق محفوظ است.';
	$year = navid_jalali_today();
	return strtr( $text, array(
		'{year}' => navid_fa_digits( $year[0] ),
		'{site}' => get_bloginfo( 'name' ),
	) );
}

/**
 * Whether this page carries the login pop-up.
 *
 * @return bool
 */
function navid_login_modal_enabled() {
	return navid_wc() && ! is_user_logged_in() && ! is_account_page() && ! is_checkout();
}
