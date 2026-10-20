<?php
/**
 * Mobile bar module — merged from the standalone «Mobile Quick Bar» plugin
 * (v1.3.2) into Navid Price Table, so the site ships one plugin, one set of
 * SMS credentials, one OTP modal and one font instead of two of each.
 *
 * Deliberate compatibility decisions (the bar is live on simcablenavid.ir and
 * LiteSpeed Cache serves guests stale HTML, so the front-end contract must not
 * change):
 *   - every DOM class, element id and CSS custom property keeps its mqb- /
 *     --mqb- prefix; assets/css/bar.css and assets/js/bar.js are byte-for-byte
 *     copies of the originals, and admin-bar.js only swaps the option name.
 *   - the localized global stays named mqbData, the AJAX actions stay
 *     mqb_send_otp / mqb_verify_otp and the nonce action stays mqb_otp_nonce,
 *     so a cached page rendered before the merge keeps working after it.
 *   - the stored settings move from the mqb_settings option to
 *     npt_bar_settings once (see maybe_import()); the old option is left in
 *     place so deactivating this plugin and re-activating Mobile Quick Bar
 *     still restores the exact previous configuration.
 *
 * The duplicated Melipayamak keys (sms_username / sms_password / sms_pattern /
 * sms_parameters) are NOT carried over — the bar now reads npt_sms_*, which is
 * the single credential source for both bulk SMS and OTP.
 *
 * While the standalone Mobile Quick Bar plugin is still active this module
 * renders nothing (prevents a double bar) and asks the site owner to
 * deactivate it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPT_Bar {

	const OPT          = 'npt_bar_settings';
	const LEGACY_OPT   = 'mqb_settings';
	const IMPORT_FLAG  = 'npt_bar_imported';
	const NONCE        = 'mqb_otp_nonce';
	const STYLE_HANDLE = 'npt-bar';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'theme_color_meta' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'admin_notices', array( __CLASS__, 'legacy_notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */
	public static function defaults() {
		return array(
			'enabled'               => 1,
			'menu_enabled'          => 1,
			'sms_enabled'           => 1,
			'enable_call'           => 1,
			'enable_whatsapp'       => 1,
			'enable_telegram'       => 1,
			'enable_custom'         => 1,
			'show_mobile_bar'       => 1,
			'floating_enabled'      => 1,
			'floating_icon'         => '',
			'floating_icon_text'    => '💬',
			'floating_icon_color'   => '#ffffff',
			'floating_background_color' => '#702584',
			'floating_effect'       => 'pulse',
			'floating_icons_only'   => 0,
			'call_icon'             => '☎',
			'call_icon_url'         => '',
			'whatsapp_icon'         => '◉',
			'whatsapp_icon_url'     => '',
			'telegram_icon'         => '➤',
			'telegram_icon_url'     => '',
			'popup_title'           => 'پاسخگوی شما هستیم',
			'popup_subtitle'        => 'یکی از راه‌های زیر را برای ارتباط انتخاب کنید',
			'call_label'            => 'تماس با فروشگاه',
			'whatsapp_label'        => 'ارتباط از طریق واتس‌اپ',
			'telegram_label'        => 'ارتباط از طریق تلگرام',
			'order'                 => array( 'call', 'whatsapp', 'telegram', 'custom' ),
			'call_number'           => '',
			'whatsapp_number'       => '',
			'telegram_url'          => '',
			'custom_links'          => array(),
			'background_color'      => '#17324d',
			'icon_size'             => 24,
			'font_family'           => 'Vazirmatn, Tahoma, Arial, sans-serif',
			'border_radius'         => 16,
			'max_width'             => 1024,
			'otp_trigger'           => '.mqb-otp-trigger',
			'otp_digits'            => 5,
			'menu_items'            => array(
				array( 'enabled' => 1, 'label' => 'خانه', 'url' => '/', 'icon' => '⌂', 'icon_url' => '', 'color' => '#702584' ),
				array( 'enabled' => 1, 'label' => 'لیست قیمت', 'url' => '/prices', 'icon' => '≡', 'icon_url' => '', 'color' => '#702584' ),
				array( 'enabled' => 1, 'label' => 'تماس', 'url' => 'tel:', 'icon' => '☎', 'icon_url' => '', 'color' => '#702584' ),
				array( 'enabled' => 1, 'label' => 'فروشگاه', 'url' => '/shop', 'icon' => '▣', 'icon_url' => '', 'color' => '#702584' ),
			),
			// New in the merged version — off by default so the live bar is not
			// changed by the upgrade on its own.
			'panel_item_enabled'    => 0,
			'panel_item_label'      => 'پنل من',
			'panel_item_url'        => '/panel',
			'panel_item_icon'       => '▤',
			'panel_item_color'      => '#702584',
		);
	}

	public static function settings() {
		self::maybe_import();
		$stored = get_option( self::OPT, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = wp_parse_args( $stored, self::defaults() );
		// «enabled» has no control in the form, so an older save may have stored 0 and
		// hidden the whole bar. The bar is always on; the other switches decide what shows.
		$settings['enabled'] = 1;
		return $settings;
	}

	/**
	 * One-time, non-destructive move of the live mqb_settings values into
	 * npt_bar_settings, plus the SMS credentials into the npt_sms_* options
	 * (only when those are still empty, so an already-configured panel wins).
	 */
	public static function maybe_import() {
		if ( (int) get_option( self::IMPORT_FLAG, 0 ) >= 1 ) {
			return;
		}
		$legacy = get_option( self::LEGACY_OPT, array() );
		update_option( self::IMPORT_FLAG, 1, false );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			return;
		}

		$clean = self::sanitize( $legacy );
		update_option( self::OPT, $clean, false );

		$map = array(
			'sms_username'   => 'npt_sms_username',
			'sms_password'   => 'npt_sms_password',
			'sms_pattern'    => 'npt_sms_otp_pattern',
			'sms_parameters' => 'npt_sms_otp_params',
		);
		foreach ( $map as $legacy_key => $option ) {
			if ( '' === trim( (string) get_option( $option, '' ) ) && ! empty( $legacy[ $legacy_key ] ) ) {
				update_option( $option, sanitize_text_field( $legacy[ $legacy_key ] ), false );
			}
		}
	}

	public static function register_setting() {
		register_setting( 'npt_settings', self::OPT, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => self::defaults(),
		) );
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$allowed  = array( 'call', 'whatsapp', 'telegram', 'custom' );
		$order    = isset( $input['order'] ) && is_array( $input['order'] )
			? array_values( array_intersect( $input['order'], $allowed ) )
			: $defaults['order'];
		$order    = array_values( array_unique( array_merge( $order, array_diff( $allowed, $order ) ) ) );

		$menu   = array();
		$custom = array();

		if ( ! empty( $input['menu_items'] ) && is_array( $input['menu_items'] ) ) {
			foreach ( array_slice( $input['menu_items'], 0, 8 ) as $item ) {
				$item = is_array( $item ) ? $item : array();
				$url  = isset( $item['url'] ) ? sanitize_text_field( $item['url'] ) : '';
				if ( 0 !== strpos( $url, '/' ) ) {
					$url = esc_url_raw( $url, array( 'http', 'https', 'tel' ) );
				}
				$menu[] = array(
					'enabled'  => empty( $item['enabled'] ) ? 0 : 1,
					'label'    => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '',
					'url'      => $url,
					'icon'     => isset( $item['icon'] ) ? sanitize_text_field( $item['icon'] ) : '●',
					'icon_url' => isset( $item['icon_url'] ) ? esc_url_raw( $item['icon_url'] ) : '',
					'color'    => ( isset( $item['color'] ) && sanitize_hex_color( $item['color'] ) ) ? sanitize_hex_color( $item['color'] ) : '#702584',
				);
			}
		}

		if ( ! empty( $input['custom_links'] ) && is_array( $input['custom_links'] ) ) {
			foreach ( array_slice( $input['custom_links'], 0, 6 ) as $link ) {
				$link  = is_array( $link ) ? $link : array();
				$label = isset( $link['label'] ) ? sanitize_text_field( $link['label'] ) : '';
				$url   = isset( $link['url'] ) ? sanitize_text_field( $link['url'] ) : '';
				// The OTP modal is opened by this exact sentinel value.
				if ( '#mqb-otp' !== $url ) {
					$url = esc_url_raw( $url, array( 'http', 'https', 'tel' ) );
				}
				if ( $label && $url ) {
					$custom[] = array(
						'label'    => $label,
						'url'      => $url,
						'icon_url' => isset( $link['icon_url'] ) ? esc_url_raw( $link['icon_url'] ) : '',
						'color'    => ( isset( $link['color'] ) && sanitize_hex_color( $link['color'] ) ) ? sanitize_hex_color( $link['color'] ) : '#d62d58',
					);
				}
			}
		}

		$font_family = isset( $input['font_family'] ) ? $input['font_family'] : $defaults['font_family'];
		$font_family = preg_replace( '/[^\p{L}\p{N}\s,\-\'"]+/u', '', sanitize_text_field( $font_family ) );

		// Kept in a variable: the whitelist check and the sanitized value must read
		// the same key, which array-key syntax cannot guarantee when absent.
		$floating_effect = isset( $input['floating_effect'] ) ? (string) $input['floating_effect'] : 'pulse';
		$floating_effect = in_array( $floating_effect, array( 'pulse', 'float', 'none' ), true ) ? $floating_effect : 'pulse';

		$clean = array(
			'enabled'                   => 1,
			'menu_enabled'              => empty( $input['menu_enabled'] ) ? 0 : 1,
			'sms_enabled'               => empty( $input['sms_enabled'] ) ? 0 : 1,
			'enable_call'               => empty( $input['enable_call'] ) ? 0 : 1,
			'enable_whatsapp'           => empty( $input['enable_whatsapp'] ) ? 0 : 1,
			'enable_telegram'           => empty( $input['enable_telegram'] ) ? 0 : 1,
			'enable_custom'             => empty( $input['enable_custom'] ) ? 0 : 1,
			'show_mobile_bar'           => empty( $input['show_mobile_bar'] ) ? 0 : 1,
			'floating_enabled'          => empty( $input['floating_enabled'] ) ? 0 : 1,
			'floating_icon'             => esc_url_raw( isset( $input['floating_icon'] ) ? $input['floating_icon'] : '' ),
			'floating_icon_text'        => sanitize_text_field( isset( $input['floating_icon_text'] ) ? $input['floating_icon_text'] : '💬' ),
			'floating_icon_color'       => self::hex( isset( $input['floating_icon_color'] ) ? $input['floating_icon_color'] : '', '#ffffff' ),
			'floating_background_color' => self::hex( isset( $input['floating_background_color'] ) ? $input['floating_background_color'] : '', '#702584' ),
			'floating_effect'           => $floating_effect,
			'floating_icons_only'       => empty( $input['floating_icons_only'] ) ? 0 : 1,
			'call_icon'                 => sanitize_text_field( isset( $input['call_icon'] ) ? $input['call_icon'] : '☎' ),
			'call_icon_url'             => esc_url_raw( isset( $input['call_icon_url'] ) ? $input['call_icon_url'] : '' ),
			'whatsapp_icon'             => sanitize_text_field( isset( $input['whatsapp_icon'] ) ? $input['whatsapp_icon'] : '◉' ),
			'whatsapp_icon_url'         => esc_url_raw( isset( $input['whatsapp_icon_url'] ) ? $input['whatsapp_icon_url'] : '' ),
			'telegram_icon'             => sanitize_text_field( isset( $input['telegram_icon'] ) ? $input['telegram_icon'] : '➤' ),
			'telegram_icon_url'         => esc_url_raw( isset( $input['telegram_icon_url'] ) ? $input['telegram_icon_url'] : '' ),
			'popup_title'               => sanitize_text_field( isset( $input['popup_title'] ) ? $input['popup_title'] : '' ),
			'popup_subtitle'            => sanitize_text_field( isset( $input['popup_subtitle'] ) ? $input['popup_subtitle'] : '' ),
			'call_label'                => sanitize_text_field( isset( $input['call_label'] ) ? $input['call_label'] : '' ),
			'whatsapp_label'            => sanitize_text_field( isset( $input['whatsapp_label'] ) ? $input['whatsapp_label'] : '' ),
			'telegram_label'            => sanitize_text_field( isset( $input['telegram_label'] ) ? $input['telegram_label'] : '' ),
			'order'                     => $order,
			'call_number'               => preg_replace( '/[^0-9+]/', '', (string) ( isset( $input['call_number'] ) ? $input['call_number'] : '' ) ),
			'whatsapp_number'           => preg_replace( '/\D+/', '', (string) ( isset( $input['whatsapp_number'] ) ? $input['whatsapp_number'] : '' ) ),
			'telegram_url'              => esc_url_raw( isset( $input['telegram_url'] ) ? $input['telegram_url'] : '', array( 'https' ) ),
			'custom_links'              => $custom,
			'background_color'          => self::hex( isset( $input['background_color'] ) ? $input['background_color'] : '', $defaults['background_color'] ),
			'icon_size'                 => min( 48, max( 16, absint( isset( $input['icon_size'] ) ? $input['icon_size'] : 24 ) ) ),
			'font_family'               => $font_family,
			'border_radius'             => min( 40, absint( isset( $input['border_radius'] ) ? $input['border_radius'] : 16 ) ),
			'max_width'                 => min( 1600, max( 320, absint( isset( $input['max_width'] ) ? $input['max_width'] : 1024 ) ) ),
			'otp_trigger'               => sanitize_text_field( isset( $input['otp_trigger'] ) ? $input['otp_trigger'] : $defaults['otp_trigger'] ),
			'otp_digits'                => min( 6, max( 4, absint( isset( $input['otp_digits'] ) ? $input['otp_digits'] : 5 ) ) ),
			'menu_items'                => $menu,
			'panel_item_enabled'        => empty( $input['panel_item_enabled'] ) ? 0 : 1,
			'panel_item_label'          => sanitize_text_field( isset( $input['panel_item_label'] ) ? $input['panel_item_label'] : $defaults['panel_item_label'] ),
			'panel_item_url'            => sanitize_text_field( isset( $input['panel_item_url'] ) ? $input['panel_item_url'] : $defaults['panel_item_url'] ),
			'panel_item_icon'           => sanitize_text_field( isset( $input['panel_item_icon'] ) ? $input['panel_item_icon'] : $defaults['panel_item_icon'] ),
			'panel_item_color'          => self::hex( isset( $input['panel_item_color'] ) ? $input['panel_item_color'] : '', '#702584' ),
		);

		return $clean;
	}

	private static function hex( $value, $fallback ) {
		$clean = sanitize_hex_color( (string) $value );
		return $clean ? $clean : $fallback;
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------- */

	/** True while the standalone Mobile Quick Bar plugin is still active. */
	public static function legacy_active() {
		return defined( 'MQB_VERSION' );
	}

	/** The business panel has its own navigation; the bar and the floating icon stay off there. */
	private static function on_panel_page() {
		return class_exists( 'NPT_Panel' ) && NPT_Panel::on_panel_page();
	}

	public static function is_off() {
		$s = self::settings();
		if ( self::legacy_active() || empty( $s['enabled'] ) ) {
			return true;
		}
		return empty( $s['menu_enabled'] ) && empty( $s['floating_enabled'] ) && empty( $s['sms_enabled'] );
	}

	public static function legacy_notice() {
		if ( ! self::legacy_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'نوار موبایل از این نسخه بخشی از «Navid Price Table» است، اما افزونه‌ی جداگانه‌ی «Mobile Quick Bar» هنوز فعال است؛ تا وقتی آن را غیرفعال نکنید، همان افزونه‌ی قدیمی نوار را نمایش می‌دهد و نوار تکراری ساخته نمی‌شود. تنظیمات شما از قبل کپی شده و گزینه‌ی قدیمی هم دست‌نخورده باقی مانده، پس با غیرفعال‌کردن افزونه‌ی قدیمی همه‌چیز مثل قبل ادامه پیدا می‌کند.', 'navid-price-table' );
		echo '</p></div>';
	}

	public static function theme_color_meta() {
		if ( self::is_off() ) {
			return;
		}
		$s = self::settings();
		if ( empty( $s['menu_enabled'] ) && empty( $s['floating_enabled'] ) ) {
			return;
		}
		printf( "<meta name=\"theme-color\" content=\"%s\">\n", esc_attr( $s['background_color'] ) );
	}

	public static function enqueue() {
		if ( self::is_off() || self::on_panel_page() ) {
			return;
		}
		$s = self::settings();
		wp_enqueue_style( self::STYLE_HANDLE, NPT_URL . 'assets/css/bar.css', array(), NPT_VERSION );
		wp_enqueue_script( self::STYLE_HANDLE, NPT_URL . 'assets/js/bar.js', array(), NPT_VERSION, true );
		wp_localize_script(
			self::STYLE_HANDLE,
			'mqbData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'trigger'  => ! empty( $s['sms_enabled'] ) ? $s['otp_trigger'] : '.mqb-otp-disabled',
				'cooldown' => 60,
				'messages' => array(
					'network' => __( 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.', 'navid-price-table' ),
				),
			)
		);
	}

	public static function render() {
		if ( self::is_off() || self::on_panel_page() ) {
			return;
		}
		$s            = self::settings();
		$items        = self::contact_items( $s );
		$menu_items   = self::menu_items( $s );
		$icons_only   = ! empty( $s['floating_icons_only'] );
		$otp_open     = ! empty( $s['sms_enabled'] );
		$effect       = in_array( $s['floating_effect'], array( 'pulse', 'float', 'none' ), true ) ? $s['floating_effect'] : 'pulse';
		$hide_at      = absint( $s['max_width'] ) + 1;
		$style        = sprintf(
			'--mqb-bg:%1$s;--mqb-icon:%2$dpx;--mqb-font:%3$s;--mqb-radius:%4$dpx;--mqb-max:%5$dpx;--mqb-float-bg:%6$s;--mqb-float-icon-color:%7$s;',
			esc_attr( $s['background_color'] ),
			absint( $s['icon_size'] ),
			esc_attr( $s['font_family'] ),
			absint( $s['border_radius'] ),
			absint( $s['max_width'] ),
			esc_attr( $s['floating_background_color'] ),
			esc_attr( $s['floating_icon_color'] )
		);
		?>
		<style id="mqb-responsive-css">@media (max-width:<?php echo esc_attr( $hide_at - 1 ); ?>px){.mqb-desktop-only{display:none!important}}@media (min-width:<?php echo esc_attr( $hide_at ); ?>px){.mqb-mobile-only{display:none!important}}</style>
		<div class="mqb-root<?php echo ! empty( $s['menu_enabled'] ) ? ' mqb-has-bar' : ''; ?>" dir="rtl" style="<?php echo esc_attr( $style ); ?>">
			<?php if ( $menu_items && ! empty( $s['menu_enabled'] ) ) : ?>
			<nav class="mqb-bar mqb-mobile-only" aria-label="<?php esc_attr_e( 'دسترسی سریع', 'navid-price-table' ); ?>">
				<?php foreach ( $menu_items as $item ) : ?>
					<a class="mqb-item <?php echo esc_attr( $item['class'] ); ?>" href="<?php echo esc_url( $item['url'], array( 'http', 'https', 'tel' ) ); ?>"<?php echo $item['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
						<span class="mqb-icon" aria-hidden="true" style="color:<?php echo esc_attr( $item['color'] ); ?>"><?php if ( $item['icon_url'] ) : ?><img src="<?php echo esc_url( $item['icon_url'] ); ?>" alt=""><?php else : ?><?php echo esc_html( $item['icon'] ); ?><?php endif; ?></span>
						<span class="mqb-label"><?php echo esc_html( $item['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php endif; ?>
			<?php if ( ! empty( $s['floating_enabled'] ) ) : ?>
			<button type="button" class="mqb-floating-trigger mqb-floating-trigger--<?php echo esc_attr( $effect ); ?> mqb-mobile-only" aria-expanded="false" aria-controls="mqb-contact-sheet" style="--mqb-float-bg:<?php echo esc_attr( $s['floating_background_color'] ); ?>;--mqb-float-icon-color:<?php echo esc_attr( $s['floating_icon_color'] ); ?>;">
				<?php if ( $s['floating_icon'] ) : ?><img src="<?php echo esc_url( $s['floating_icon'] ); ?>" alt=""><?php else : ?><span aria-hidden="true" style="color:<?php echo esc_attr( $s['floating_icon_color'] ); ?>"><?php echo esc_html( $s['floating_icon_text'] ? $s['floating_icon_text'] : '💬' ); ?></span><?php endif; ?>
				<span class="screen-reader-text"><?php esc_html_e( 'نمایش راه‌های ارتباطی', 'navid-price-table' ); ?></span>
			</button>
			<div id="mqb-contact-sheet" class="mqb-contact-modal mqb-mobile-only<?php echo $icons_only ? ' mqb-contact-icons-only' : ''; ?>" hidden role="dialog" aria-modal="true" aria-labelledby="mqb-contact-title">
				<div class="mqb-contact-backdrop" aria-hidden="true"></div>
				<div class="mqb-contact-card<?php echo $icons_only ? ' mqb-contact-card-icons-only' : ''; ?>">
					<div class="mqb-contact-header"><h2 id="mqb-contact-title"><?php echo esc_html( $s['popup_title'] ); ?></h2><p><?php echo esc_html( $s['popup_subtitle'] ); ?></p></div>
					<div class="mqb-contact-items">
					<?php if ( ! $items ) : ?><p class="mqb-contact-empty"><?php esc_html_e( 'هنوز راه ارتباطی فعالی ثبت نشده است.', 'navid-price-table' ); ?></p><?php endif; ?>
					<?php foreach ( $items as $item ) : ?>
						<a class="<?php echo esc_attr( $item['class'] ); ?>" href="<?php echo esc_url( $item['url'], array( 'http', 'https', 'tel' ) ); ?>"<?php echo $item['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> aria-label="<?php echo esc_attr( $item['label'] ); ?>">
							<span class="mqb-contact-item-icon" style="--mqb-item-color:<?php echo esc_attr( $item['color'] ); ?>"><?php if ( $item['icon_url'] ) : ?><img src="<?php echo esc_url( $item['icon_url'] ); ?>" alt=""><?php else : ?><?php echo esc_html( $item['icon'] ); ?><?php endif; ?></span><span class="mqb-contact-item-label"><?php echo esc_html( $item['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
					</div>
				</div>
			</div>
			<div class="mqb-desktop-contact mqb-desktop-only">
				<button type="button" class="mqb-desktop-trigger" aria-expanded="false" aria-controls="mqb-desktop-panel"><span aria-hidden="true">☎</span><span><?php esc_html_e( 'تماس با ما', 'navid-price-table' ); ?></span></button>
				<div id="mqb-desktop-panel" class="mqb-desktop-panel" hidden>
					<div class="mqb-desktop-heading"><strong><?php esc_html_e( 'راه‌های ارتباطی', 'navid-price-table' ); ?></strong><button type="button" class="mqb-desktop-close" aria-label="<?php esc_attr_e( 'بستن', 'navid-price-table' ); ?>">&times;</button></div>
					<div class="mqb-desktop-list">
					<?php foreach ( $items as $item ) : ?>
						<a href="<?php echo esc_url( $item['url'], array( 'http', 'https', 'tel' ) ); ?>"<?php echo $item['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><span class="mqb-icon" aria-hidden="true"><?php echo esc_html( $item['icon'] ); ?></span><span><?php echo esc_html( $item['label'] ); ?></span></a>
					<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>
			<?php if ( $otp_open && ! is_user_logged_in() ) : ?>
			<div class="mqb-modal mqb-mobile-only" hidden role="dialog" aria-modal="true" aria-labelledby="mqb-modal-title">
				<div class="mqb-backdrop" aria-hidden="true"></div>
				<div class="mqb-dialog" tabindex="-1">
					<button type="button" class="mqb-close" aria-label="<?php esc_attr_e( 'بستن', 'navid-price-table' ); ?>">&times;</button>
					<h2 id="mqb-modal-title"><?php esc_html_e( 'ورود با شماره موبایل', 'navid-price-table' ); ?></h2>
					<form class="mqb-phone-form">
						<label for="mqb-phone"><?php esc_html_e( 'شماره موبایل', 'navid-price-table' ); ?></label>
						<input id="mqb-phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" maxlength="11" placeholder="09123456789" required>
						<button type="submit"><?php esc_html_e( 'ارسال کد', 'navid-price-table' ); ?></button>
					</form>
					<form class="mqb-code-form" hidden>
						<label for="mqb-code"><?php esc_html_e( 'کد تأیید', 'navid-price-table' ); ?></label>
						<input id="mqb-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
						<button type="submit"><?php esc_html_e( 'تأیید و ورود', 'navid-price-table' ); ?></button>
						<button type="button" class="mqb-resend" disabled><?php esc_html_e( 'ارسال مجدد', 'navid-price-table' ); ?> (<span>60</span>)</button>
					</form>
					<p class="mqb-status" role="status" aria-live="polite"></p>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function menu_items( array $s ) {
		$out = array();
		foreach ( (array) $s['menu_items'] as $item ) {
			$item = is_array( $item ) ? $item : array();
			if ( empty( $item['enabled'] ) || empty( $item['label'] ) || empty( $item['url'] ) ) {
				continue;
			}
			$out[] = array(
				'label'    => $item['label'],
				'url'      => $item['url'],
				'icon'     => ! empty( $item['icon'] ) ? $item['icon'] : '●',
				'icon_url' => ! empty( $item['icon_url'] ) ? $item['icon_url'] : '',
				'color'    => ! empty( $item['color'] ) ? $item['color'] : '#702584',
				'class'    => 'mqb-menu-link',
				'external' => 0 === strpos( $item['url'], 'http' ),
			);
		}
		// «پنل» shortcut for employer/dashboard users only, never for guests.
		if ( ! empty( $s['panel_item_enabled'] ) && function_exists( 'npt_user_can_panel' ) && npt_user_can_panel() ) {
			$panel_url = function_exists( 'npt_panel_url' ) ? npt_panel_url() : home_url( '/panel/' );
			$out[] = array(
				'label'    => $s['panel_item_label'],
				'url'      => $panel_url,
				'icon'     => $s['panel_item_icon'],
				'icon_url' => '',
				'color'    => $s['panel_item_color'],
				'class'    => 'mqb-menu-link mqb-menu-panel',
				'external' => false,
			);
		}
		return $out;
	}

	private static function contact_items( array $s ) {
		$groups = array(
			'call'     => ! empty( $s['enable_call'] ) && $s['call_number'] ? array( array( 'label' => $s['call_label'], 'url' => 'tel:' . preg_replace( '/[^0-9+]/', '', $s['call_number'] ), 'icon' => $s['call_icon'] ? $s['call_icon'] : '☎', 'class' => 'mqb-call', 'external' => false, 'color' => '#21bd49', 'icon_url' => $s['call_icon_url'] ) ) : array(),
			'whatsapp' => ! empty( $s['enable_whatsapp'] ) && $s['whatsapp_number'] ? array( array( 'label' => $s['whatsapp_label'], 'url' => 'https://wa.me/' . preg_replace( '/\D+/', '', $s['whatsapp_number'] ), 'icon' => $s['whatsapp_icon'] ? $s['whatsapp_icon'] : '◉', 'class' => 'mqb-whatsapp', 'external' => true, 'color' => '#28cf73', 'icon_url' => $s['whatsapp_icon_url'] ) ) : array(),
			'telegram' => ! empty( $s['enable_telegram'] ) && $s['telegram_url'] ? array( array( 'label' => $s['telegram_label'], 'url' => $s['telegram_url'], 'icon' => $s['telegram_icon'] ? $s['telegram_icon'] : '➤', 'class' => 'mqb-telegram', 'external' => true, 'color' => '#27add7', 'icon_url' => $s['telegram_icon_url'] ) ) : array(),
			'custom'   => array(),
		);

		if ( ! empty( $s['enable_custom'] ) ) {
			foreach ( (array) $s['custom_links'] as $link ) {
				$link = is_array( $link ) ? $link : array();
				if ( empty( $link['label'] ) || empty( $link['url'] ) ) {
					continue;
				}
				$groups['custom'][] = array(
					'label'    => $link['label'],
					'url'      => $link['url'],
					'icon'     => '●',
					'class'    => ( '#mqb-otp' === $link['url'] && ! empty( $s['sms_enabled'] ) ) ? 'mqb-otp-trigger' : 'mqb-custom',
					'external' => 0 === strpos( $link['url'], 'http' ),
					'color'    => ! empty( $link['color'] ) ? $link['color'] : '#d62d58',
					'icon_url' => ! empty( $link['icon_url'] ) ? $link['icon_url'] : '',
				);
			}
		}

		$out = array();
		foreach ( $s['order'] as $type ) {
			if ( isset( $groups[ $type ] ) ) {
				$out = array_merge( $out, $groups[ $type ] );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Settings tabs (rendered inside the plugin settings page form)
	 *
	 * The two methods must be called from inside the single <form> of the
	 * settings page: every field of this module belongs to the same
	 * npt_bar_settings option, and WordPress writes an empty value for any
	 * registered option that is missing from a submitted form.
	 * ------------------------------------------------------------------- */
	public static function render_menu_tab() {
		$s = self::settings();
		$f = 'npt_bar_settings';
		?>
		<div class="mqb-admin-wrap" dir="rtl">
		<?php self::admin_intro(); ?>
		<div class="mqb-section-card">
			<div class="mqb-section-title"><h2>منوی پایین موبایل</h2><?php self::toggle( $f, 'menu_enabled', 'فعال‌سازی منوبار', $s['menu_enabled'] ); ?></div>
			<div id="mqb-menu-items">
			<?php foreach ( $s['menu_items'] as $i => $item ) : self::menu_row( $f, $i, $item ); endforeach; ?>
			</div>
			<button type="button" class="button" id="mqb-add-menu-item">افزودن آیتم جدید</button>
			<div class="mqb-fields-grid">
				<?php self::text_field( $f, 'background_color', 'رنگ پس‌زمینه', $s['background_color'], 'mqb-color' ); ?>
				<?php self::number_field( $f, 'icon_size', 'اندازه آیکن', $s['icon_size'], 16, 48 ); ?>
				<?php self::number_field( $f, 'border_radius', 'گردی گوشه‌ها', $s['border_radius'], 0, 40 ); ?>
				<?php self::number_field( $f, 'max_width', 'عرض تغییر حالت', $s['max_width'], 320, 1600 ); ?>
				<?php self::text_field( $f, 'font_family', 'فونت', $s['font_family'] ); ?>
			</div>
			<p class="description">برای آیتم‌هایی که لینکشان با «/» شروع شود، نشانی سایت به‌صورت خودکار اضافه می‌شود. اگر ورود پیامکی فعال است، می‌توانید لینک یک آیتم را دقیقاً <code>#mqb-otp</code> بگذارید تا همان آیتم دکمه‌ی ورود باشد.</p>
			<hr>
			<h3>آیتم پنل کاربری</h3>
			<?php self::toggle( $f, 'panel_item_enabled', 'افزودن آیتم «پنل» به منوی پایین (فقط برای کاربران دارای دسترسی پنل)', $s['panel_item_enabled'] ); ?>
			<div class="mqb-fields-grid">
				<?php self::text_field( $f, 'panel_item_label', 'عنوان آیتم پنل', $s['panel_item_label'] ); ?>
				<?php self::text_field( $f, 'panel_item_icon', 'نماد آیتم پنل', $s['panel_item_icon'] ); ?>
				<?php self::text_field( $f, 'panel_item_url', 'نشانی پنل', $s['panel_item_url'] ); ?>
			</div>
		</div>
		</div>
		<?php
	}

	public static function render_float_tab() {
		$s       = self::settings();
		$labels  = array( 'call' => 'تماس', 'whatsapp' => 'واتساپ', 'telegram' => 'تلگرام', 'custom' => 'لینک‌های سفارشی' );
		$toggles = array( 'call' => 'enable_call', 'whatsapp' => 'enable_whatsapp', 'telegram' => 'enable_telegram', 'custom' => 'enable_custom' );
		$f       = 'npt_bar_settings';
		?>
		<div class="mqb-admin-wrap" dir="rtl">
		<?php self::admin_intro(); ?>
		<div class="mqb-section-card">
			<div class="mqb-section-title"><h2>آیکن شناور و پنجره ارتباطی</h2><?php self::toggle( $f, 'floating_enabled', 'فعال‌سازی آیکون شناور', $s['floating_enabled'] ); ?></div>
			<?php self::media_field( $f, 'floating_icon', 'انتخاب تصویر آیکون شناور', $s['floating_icon'] ); ?>
			<?php self::text_field( $f, 'floating_icon_text', 'نماد/آیکون داخل دکمه', $s['floating_icon_text'] ); ?>
			<?php self::text_field( $f, 'floating_icon_color', 'رنگ آیکون', $s['floating_icon_color'], 'mqb-color' ); ?>
			<?php self::text_field( $f, 'floating_background_color', 'رنگ پس‌زمینه آیکون شناور', $s['floating_background_color'], 'mqb-color' ); ?>
			<label class="mqb-field"><span>افکت آیکون شناور</span><select name="<?php echo esc_attr( $f ); ?>[floating_effect]">
				<option value="pulse" <?php selected( $s['floating_effect'], 'pulse' ); ?>>پالس</option>
				<option value="float" <?php selected( $s['floating_effect'], 'float' ); ?>>شناور</option>
				<option value="none" <?php selected( $s['floating_effect'], 'none' ); ?>>بدون افکت</option>
			</select></label>
			<?php self::toggle( $f, 'floating_icons_only', 'فقط آیکن‌های راه‌های ارتباطی نمایش داده شود', $s['floating_icons_only'] ); ?>
			<?php self::text_field( $f, 'popup_title', 'عنوان پنجره', $s['popup_title'] ); ?>
			<?php self::text_field( $f, 'popup_subtitle', 'زیرعنوان', $s['popup_subtitle'] ); ?>
			<div class="mqb-floating-preview-panel">
				<div class="mqb-floating-preview-label">پیش‌نمایش آیکون شناور</div>
				<div class="mqb-floating-preview-shell">
					<div class="mqb-floating-preview-button mqb-floating-trigger mqb-floating-trigger--<?php echo esc_attr( $s['floating_effect'] ); ?>">
						<span class="mqb-floating-preview-icon" aria-hidden="true"><?php if ( $s['floating_icon'] ) : ?><img src="<?php echo esc_url( $s['floating_icon'] ); ?>" alt=""><?php else : ?><?php echo esc_html( $s['floating_icon_text'] ? $s['floating_icon_text'] : '💬' ); ?><?php endif; ?></span>
					</div>
					<div class="mqb-floating-preview-sheet<?php echo $s['floating_icons_only'] ? ' mqb-floating-preview-sheet--icons' : ''; ?>">
						<div class="mqb-floating-preview-item"><span class="mqb-floating-preview-item-icon"><?php echo esc_html( $s['call_icon'] ? $s['call_icon'] : '☎' ); ?></span><span><?php echo esc_html( $s['call_label'] ? $s['call_label'] : 'تماس' ); ?></span></div>
						<div class="mqb-floating-preview-item"><span class="mqb-floating-preview-item-icon"><?php echo esc_html( $s['whatsapp_icon'] ? $s['whatsapp_icon'] : '◉' ); ?></span><span><?php echo esc_html( $s['whatsapp_label'] ? $s['whatsapp_label'] : 'واتساپ' ); ?></span></div>
						<div class="mqb-floating-preview-item"><span class="mqb-floating-preview-item-icon"><?php echo esc_html( $s['telegram_icon'] ? $s['telegram_icon'] : '➤' ); ?></span><span><?php echo esc_html( $s['telegram_label'] ? $s['telegram_label'] : 'تلگرام' ); ?></span></div>
					</div>
				</div>
			</div>
			<h3>راه‌های ارتباطی پنجره</h3>
			<ul id="mqb-sortable"><?php foreach ( $s['order'] as $type ) : ?>
				<li><span class="dashicons dashicons-move"></span><span class="mqb-sort-label"><?php echo esc_html( $labels[ $type ] ); ?></span><input type="hidden" name="<?php echo esc_attr( $f ); ?>[order][]" value="<?php echo esc_attr( $type ); ?>"><?php self::toggle( $f, $toggles[ $type ], $labels[ $type ], $s[ $toggles[ $type ] ] ); ?></li>
			<?php endforeach; ?></ul>
			<div class="mqb-contact-config-grid">
				<div class="mqb-contact-config-card">
					<h4>تماس</h4>
					<div class="mqb-contact-config-row"><?php self::text_field( $f, 'call_icon', 'نماد', $s['call_icon'] ); self::media_field( $f, 'call_icon_url', 'تصویر', $s['call_icon_url'] ); ?></div>
					<?php self::text_field( $f, 'call_label', 'عنوان تماس', $s['call_label'] ); ?>
					<?php self::text_field( $f, 'call_number', 'شماره تماس', $s['call_number'] ); ?>
				</div>
				<div class="mqb-contact-config-card">
					<h4>واتساپ</h4>
					<div class="mqb-contact-config-row"><?php self::text_field( $f, 'whatsapp_icon', 'نماد', $s['whatsapp_icon'] ); self::media_field( $f, 'whatsapp_icon_url', 'تصویر', $s['whatsapp_icon_url'] ); ?></div>
					<?php self::text_field( $f, 'whatsapp_label', 'عنوان واتساپ', $s['whatsapp_label'] ); ?>
					<?php self::text_field( $f, 'whatsapp_number', 'شماره واتساپ', $s['whatsapp_number'] ); ?>
				</div>
				<div class="mqb-contact-config-card">
					<h4>تلگرام</h4>
					<div class="mqb-contact-config-row"><?php self::text_field( $f, 'telegram_icon', 'نماد', $s['telegram_icon'] ); self::media_field( $f, 'telegram_icon_url', 'تصویر', $s['telegram_icon_url'] ); ?></div>
					<?php self::text_field( $f, 'telegram_label', 'عنوان تلگرام', $s['telegram_label'] ); ?>
					<?php self::text_field( $f, 'telegram_url', 'نشانی تلگرام', $s['telegram_url'] ); ?>
				</div>
			</div>
			<h3>لینک‌های سفارشی</h3>
			<div id="mqb-custom-links">
			<?php for ( $i = 0; $i < 3; $i++ ) : $link = isset( $s['custom_links'][ $i ] ) ? $s['custom_links'][ $i ] : array(); ?>
				<div class="mqb-link-row">
					<input type="text" name="<?php echo esc_attr( $f ); ?>[custom_links][<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( isset( $link['label'] ) ? $link['label'] : '' ); ?>" placeholder="عنوان">
					<input type="text" name="<?php echo esc_attr( $f ); ?>[custom_links][<?php echo esc_attr( $i ); ?>][url]" value="<?php echo esc_attr( isset( $link['url'] ) ? $link['url'] : '' ); ?>" placeholder="https:// یا #mqb-otp">
					<input type="color" name="<?php echo esc_attr( $f ); ?>[custom_links][<?php echo esc_attr( $i ); ?>][color]" value="<?php echo esc_attr( isset( $link['color'] ) ? $link['color'] : '#d62d58' ); ?>">
					<?php self::media_field( $f, 'custom_links][' . $i . '][icon_url', 'آیکون', isset( $link['icon_url'] ) ? $link['icon_url'] : '' ); ?>
				</div>
			<?php endfor; ?>
			</div>
		</div>


		</div>
		<?php
	}

	/** The «ورود پیامکی» card. Lives in the SMS settings tab; the same option is saved. */
	public static function render_sms_login_card() {
		$s = self::settings();
		$f = 'npt_bar_settings';
		?>
		<div class="mqb-section-card">
				<div class="mqb-section-title"><h2>ورود پیامکی</h2><?php self::toggle( $f, 'sms_enabled', 'فعال‌سازی ورود با پیامک', $s['sms_enabled'] ); ?></div>
				<?php self::text_field( $f, 'otp_trigger', 'انتخاب‌گر دکمه ورود', $s['otp_trigger'] ); ?>
				<?php self::number_field( $f, 'otp_digits', 'تعداد ارقام کد', $s['otp_digits'], 4, 6 ); ?>
				<p class="description">نام کاربری، رمز، خط ارسال و «کد الگو» (bodyId) در تب‌های «تنظیمات پیامک» و «کدهای الگو» همین صفحه پر می‌شود. اگر کد الگو خالی باشد، کد به‌صورت پیامک معمولی از خط ارسال فرستاده می‌شود.</p>
			</div>
		<?php
	}

	/** Shown at the top of both bar tabs. */
	private static function admin_intro() {
		if ( self::legacy_active() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'افزونه‌ی جداگانه‌ی «Mobile Quick Bar» هنوز فعال است؛ تا وقتی غیرفعال نشده، همان افزونه‌ی قدیمی نوار را نمایش می‌دهد و نوار تکراری ساخته نمی‌شود.', 'navid-price-table' ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'تنظیمات نوار پایین موبایل و آیکن شناور — یکپارچه با این افزونه. برای ذخیره، دکمه‌ی «ذخیره تنظیمات» پایین همین صفحه را بزنید.', 'navid-price-table' ) . '</p>';
	}

	private static function menu_row( $f, $i, array $item ) {
		?>
		<div class="mqb-menu-item">
			<span class="dashicons dashicons-move mqb-menu-handle"></span>
			<?php self::toggle( $f, 'menu_items][' . $i . '][enabled', 'نمایش آیتم', isset( $item['enabled'] ) ? (int) $item['enabled'] : 0 ); ?>
			<input type="text" name="<?php echo esc_attr( $f ); ?>[menu_items][<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( isset( $item['label'] ) ? $item['label'] : '' ); ?>" placeholder="عنوان">
			<input type="text" name="<?php echo esc_attr( $f ); ?>[menu_items][<?php echo esc_attr( $i ); ?>][url]" value="<?php echo esc_attr( isset( $item['url'] ) ? $item['url'] : '' ); ?>" placeholder="لینک">
			<input type="text" name="<?php echo esc_attr( $f ); ?>[menu_items][<?php echo esc_attr( $i ); ?>][icon]" value="<?php echo esc_attr( isset( $item['icon'] ) ? $item['icon'] : '' ); ?>" placeholder="نماد">
			<input type="color" name="<?php echo esc_attr( $f ); ?>[menu_items][<?php echo esc_attr( $i ); ?>][color]" value="<?php echo esc_attr( isset( $item['color'] ) ? $item['color'] : '#702584' ); ?>">
			<?php self::media_field( $f, 'menu_items][' . $i . '][icon_url', 'تصویر آیکون', isset( $item['icon_url'] ) ? $item['icon_url'] : '' ); ?>
			<button type="button" class="button-link-delete mqb-remove-menu-item">حذف</button>
		</div>
		<?php
	}

	private static function text_field( $f, $key, $label, $value, $class = '' ) {
		printf( '<label class="mqb-field"><span>%1$s</span><input class="%2$s" type="text" name="%3$s[%4$s]" value="%5$s" autocomplete="off"></label>', esc_html( $label ), esc_attr( $class ), esc_attr( $f ), esc_attr( $key ), esc_attr( $value ) );
	}

	private static function number_field( $f, $key, $label, $value, $min, $max ) {
		printf( '<label class="mqb-field"><span>%1$s</span><input type="number" min="%2$d" max="%3$d" name="%4$s[%5$s]" value="%6$d"></label>', esc_html( $label ), (int) $min, (int) $max, esc_attr( $f ), esc_attr( $key ), absint( $value ) );
	}

	private static function toggle( $f, $key, $label, $value ) {
		printf( '<label class="mqb-switch"><span class="screen-reader-text">%1$s</span><input type="hidden" name="%2$s[%3$s]" value="0"><input type="checkbox" name="%2$s[%3$s]" value="1"%4$s><span class="mqb-switch-ui" aria-hidden="true"></span></label>', esc_html( $label ), esc_attr( $f ), esc_attr( $key ), checked( 1, (int) $value, false ) );
	}

	private static function media_field( $f, $key, $label, $value ) {
		printf( '<div class="mqb-media-field"><button type="button" class="button mqb-select-media">%1$s</button><input type="url" name="%2$s[%3$s]" value="%4$s" placeholder="https://"><span class="mqb-media-preview">%5$s</span></div>', esc_html( $label ), esc_attr( $f ), esc_attr( $key ), esc_attr( $value ), $value ? '<img src="' . esc_url( $value ) . '" alt="">' : '' );
	}

	/**
	 * Admin-side assets for the two bar tabs.
	 *
	 * They load on every settings page view, not just while a bar tab is
	 * visible: the tabs are switched in the browser now, so the stylesheets
	 * have to be there before the user opens them. Without admin-bar.css the
	 * media previews lose their 38px box and the chosen icons render at full
	 * size — which is exactly what was reported from production.
	 */
	public static function enqueue_admin( $hook ) {
		if ( ! npt_is_settings_hook( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_media();
		// The admin preview reuses the real front-end stylesheet, exactly like the
		// standalone plugin did, so the preview cannot drift from what visitors see.
		wp_enqueue_style( self::STYLE_HANDLE, NPT_URL . 'assets/css/bar.css', array(), NPT_VERSION );
		wp_enqueue_style( 'npt-bar-admin', NPT_URL . 'assets/css/admin-bar.css', array( 'wp-color-picker', self::STYLE_HANDLE ), NPT_VERSION );
		wp_enqueue_script( 'npt-bar-admin', NPT_URL . 'assets/js/admin-bar.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), NPT_VERSION, true );
	}
}
