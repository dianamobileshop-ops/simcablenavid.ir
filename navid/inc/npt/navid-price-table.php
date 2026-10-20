<?php
/**
 * Plugin Name: Navid Price Table
 * Description: نمایش جدولی محصولات ووکامرس (نام، قیمت، آخرین به‌روزرسانی) با فیلتر دسته‌بندی، از طریق شورت‌کد [navid_price_table]. کاربران دارای دسترسی مجاز (کارفرما/مدیر فروشگاه) می‌توانند قیمت را مستقیماً از همان جدول ویرایش کنند. شامل پیش‌فاکتور آنلاین با فرم عمومی ([navid_proforma])، فروش متری (ادغام‌شده از افزونه «فروش متری ووکامرس»)، پنل مستقل کارفرما با ورود پیامکی ([navid_panel])، ثبت‌نام مشتری ([navid_register]) و نوار سریع موبایل (ادغام‌شده از افزونه‌ی جداگانه‌ی Mobile Quick Bar).
 * Version: 2.0.16
 * Plugin URI: https://spadts.ir
 * Author: ویرا فناوران اسپاد
 * Author URI: https://spadts.ir
 * Text Domain: navid-price-table
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

define( 'NPT_VERSION', '2.0.16' );
define( 'NPT_PATH', plugin_dir_path( __FILE__ ) );
define( 'NPT_URL', get_template_directory_uri() . '/inc/npt/' );

require_once NPT_PATH . 'includes/class-npt-log.php';
require_once NPT_PATH . 'includes/class-npt-pdf.php';
require_once NPT_PATH . 'includes/class-npt-meter.php';
require_once NPT_PATH . 'includes/class-npt-proforma.php';
require_once NPT_PATH . 'includes/class-npt-sms.php';
// Load order matters: the OTP module reads the bar settings, and the panel
// reuses both of them plus the proforma/SMS renderers.
require_once NPT_PATH . 'includes/class-npt-bar.php';
require_once NPT_PATH . 'includes/class-npt-otp.php';
require_once NPT_PATH . 'includes/class-npt-push.php';
require_once NPT_PATH . 'includes/class-npt-panel.php';
require_once NPT_PATH . 'includes/class-npt-register.php';
require_once NPT_PATH . 'includes/class-npt-backup.php';
require_once NPT_PATH . 'includes/npt-dashboard.php';

NPT_Bar::init();
NPT_OTP::init();
NPT_Push::init();
NPT_Panel::init();
NPT_Register::init();
NPT_Backup::init();
NPT_Log::init();

/**
 * Load translations.
 */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'navid-price-table', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Bail early with an admin notice if WooCommerce is not active.
 */
function npt_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'افزونه «Navid Price Table» برای کار کردن به ووکامرس نیاز دارد. لطفاً ابتدا ووکامرس را فعال کنید.', 'navid-price-table' ) .
				'</p></div>';
		} );
		return false;
	}
	return true;
}
add_action( 'plugins_loaded', 'npt_check_woocommerce' );

/**
 * Whitelist for the «چه کسانی اجازه ویرایش قیمت دارند» select. An empty or
 * unknown value would make every gate that reads it fail closed, so the
 * sanitizer refuses to store anything outside this list.
 */
function npt_sanitize_edit_capability( $value ) {
	$value = sanitize_text_field( (string) $value );
	return in_array( $value, npt_edit_capability_values(), true ) ? $value : 'manage_woocommerce';
}

/** The capability strings the settings dropdown may store. */
function npt_edit_capability_values() {
	return array( 'manage_woocommerce', 'manage_options', 'edit_products' );
}

/**
 * Which capability is allowed to see/use the edit pencil.
 * Filterable: add_filter( 'npt_edit_capability', function(){ return 'edit_products'; } );
 */
function npt_edit_capability() {
	$default = get_option( 'npt_edit_capability', 'manage_woocommerce' );
	// An empty value here used to lock every admin screen out (WordPress treats
	// an empty capability string as "nobody has it"), including for administrators.
	if ( ! in_array( $default, npt_edit_capability_values(), true ) ) {
		$default = 'manage_woocommerce';
	}
	return apply_filters( 'npt_edit_capability', $default );
}

function npt_current_user_can_edit() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	// The business panel (نقش «مدیر کسب و کار») intentionally shares these
	// gates: the panel shows the price table, the proforma archive and the bulk
	// sender, and every one of those endpoints checks this function.
	return current_user_can( npt_edit_capability() ) || current_user_can( 'npt_manage_panel' );
}

/**
 * Panel access. Administrators / shop managers pass automatically (they hold
 * manage_woocommerce), so nothing breaks if the role has not been created yet.
 */
function npt_user_can_panel() {
	return NPT_Panel::user_can_panel();
}

function npt_panel_url() {
	return NPT_Panel::panel_url();
}

function npt_register_url() {
	return NPT_Register::url();
}

/* ---------------------------------------------------------------------
 * Table render cache (versioned transient) — avoids re-querying every
 * product on every page view/AJAX filter for large catalogs. The version
 * number is bumped whenever a product or product category changes, which
 * makes old cache entries irrelevant instantly without having to hunt
 * them down and delete them one by one (they simply expire on their own).
 * ------------------------------------------------------------------- */
function npt_table_cache_version() {
	return (int) get_option( 'npt_table_cache_v', 1 );
}

function npt_bump_table_cache_version() {
	update_option( 'npt_table_cache_v', npt_table_cache_version() + 1, false );
}
add_action( 'save_post_product', 'npt_bump_table_cache_version' );
add_action( 'woocommerce_update_product', 'npt_bump_table_cache_version' );
add_action( 'edited_product_cat', 'npt_bump_table_cache_version' );
add_action( 'created_product_cat', 'npt_bump_table_cache_version' );
add_action( 'delete_product_cat', 'npt_bump_table_cache_version' );

// Any plugin setting (unit, colors, texts, images, font, …) can change what
// the table, online view and PDF render, so a settings save invalidates the
// caches too. The version option itself is excluded to avoid recursion.
add_action( 'update_option', function ( $option ) {
	if ( is_string( $option ) && 0 === strpos( $option, 'npt_' ) && 'npt_table_cache_v' !== $option ) {
		npt_bump_table_cache_version();
	}
} );

function npt_table_cache_key( $category_slug, $can_edit ) {
	// NPT_VERSION is part of the key on purpose: any plugin update (changed
	// markup, changed query) must never reuse table HTML cached by an older
	// version of the code.
	return 'npt_tbl_' . md5( $category_slug . '|' . ( $can_edit ? '1' : '0' ) . '|' . npt_table_cache_version() . '|' . NPT_VERSION );
}

/* ---------------------------------------------------------------------
 * Settings page (edit capability, PDF contact info, SEO options)
 * ------------------------------------------------------------------- */
// The admin menu itself (dashboard parent + settings submenu) is registered
// in includes/npt-dashboard.php at priority 9, with the settings page living
// under the 'navid-settings' slug.

add_action( 'admin_init', function () {
	register_setting( 'npt_settings', 'npt_edit_capability', array(
		'type'              => 'string',
		'sanitize_callback' => 'npt_sanitize_edit_capability',
		'default'           => 'manage_woocommerce',
	) );
	register_setting( 'npt_settings', 'npt_phone', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'npt_settings', 'npt_address', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );

	// --- Header (PDF + online view) -------------------------------------
	register_setting( 'npt_settings', 'npt_header_title', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'npt_settings', 'npt_header_show_month', array(
		'type'              => 'boolean',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'default'           => true,
	) );
	register_setting( 'npt_settings', 'npt_header_show_date', array(
		'type'              => 'boolean',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'default'           => true,
	) );
	register_setting( 'npt_settings', 'npt_unit_sentence', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'npt_settings', 'npt_logo_id', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
	foreach ( array( 'npt_app_icon_id', 'npt_proforma_logo_id', 'npt_proforma_stamp_id', 'npt_login_logo_id', 'npt_login_icon_id', 'npt_panel_logo_id', 'npt_panel_icon_id' ) as $npt_media_option ) {
		register_setting( 'npt_settings', $npt_media_option, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
	}
	register_setting( 'npt_settings', 'npt_mark1_id', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
	register_setting( 'npt_settings', 'npt_mark2_id', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
	register_setting( 'npt_settings', 'npt_mark3_id', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
	register_setting( 'npt_settings', 'npt_header_color', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_hex_color',
		'default'           => '#2350b4',
	) );
	register_setting( 'npt_settings', 'npt_accent_color', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_hex_color',
		'default'           => '#ed1c24',
	) );
	register_setting( 'npt_settings', 'npt_pdf_font', array(
		'type'              => 'string',
		'sanitize_callback' => 'npt_sanitize_pdf_font',
		'default'           => 'vazirmatn',
	) );

	// --- Content (table-wide behavior) -----------------------------------
	register_setting( 'npt_settings', 'npt_currency_unit', array(
		'type'              => 'string',
		'sanitize_callback' => 'npt_sanitize_currency_code',
		'default'           => 'IRT',
	) );
	register_setting( 'npt_settings', 'npt_online_size_format', array(
		'type'              => 'string',
		'sanitize_callback' => 'npt_sanitize_online_size_format',
		'default'           => 'psd',
	) );

	// --- Footer (PDF + online view) ---------------------------------------
	register_setting( 'npt_settings', 'npt_footer_domain', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'npt_settings', 'npt_show_globe', array(
		'type'              => 'boolean',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'default'           => true,
	) );
	register_setting( 'npt_settings', 'npt_telegram', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'npt_settings', 'npt_instagram', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );

	register_setting( 'npt_settings', 'npt_delete_data', array(
		'type'              => 'boolean',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'default'           => false,
	) );

	// --- SEO settings ---------------------------------------------------
	register_setting( 'npt_settings', 'npt_seo_heading', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'npt_settings', 'npt_seo_heading_tag', array(
		'type'              => 'string',
		'sanitize_callback' => 'npt_sanitize_heading_tag',
		'default'           => 'h2',
	) );
	register_setting( 'npt_settings', 'npt_seo_intro', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );
	register_setting( 'npt_settings', 'npt_enable_schema', array(
		'type'              => 'boolean',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'default'           => true,
	) );

	// --- Meter-sold badge (v1.6.0) --------------------------------------
	register_setting( 'npt_settings', 'npt_meter_badge_enable', array(
		'type'              => 'boolean',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'default'           => true,
	) );
	register_setting( 'npt_settings', 'npt_meter_sentence', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );

	// --- Footer icons from the media library (v1.6.0) --------------------
	register_setting( 'npt_settings', 'npt_globe_id', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
	register_setting( 'npt_settings', 'npt_telegram_icon_id', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
	register_setting( 'npt_settings', 'npt_instagram_icon_id', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
} );

function npt_sanitize_heading_tag( $value ) {
	$allowed = array( 'h1', 'h2', 'h3' );
	return in_array( $value, $allowed, true ) ? $value : 'h2';
}

function npt_sanitize_currency_code( $value ) {
	$allowed = array( 'IRR', 'IRT' );
	$value   = strtoupper( sanitize_text_field( $value ) );
	return in_array( $value, $allowed, true ) ? $value : 'IRT';
}

function npt_sanitize_pdf_font( $value ) {
	$allowed = array( 'vazirmatn', 'sahel' );
	return in_array( $value, $allowed, true ) ? $value : 'vazirmatn';
}

function npt_sanitize_online_size_format( $value ) {
	$allowed = array( 'psd', 'name' );
	return in_array( $value, $allowed, true ) ? $value : 'psd';
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	// The settings page is the top-level menu entry now (see includes/npt-dashboard.php).
	if ( ! npt_is_settings_hook( $hook ) ) {
		return;
	}
	wp_enqueue_media();
	wp_add_inline_script( 'jquery', "
jQuery(function($){
	$(document).on('click', '.npt-media-pick', function(e){
		e.preventDefault();
		var btn = this;
		var frame = wp.media({ title: 'انتخاب تصویر', multiple: false, library: { type: 'image' } });
		frame.on('select', function(){
			var att = frame.state().get('selection').first().toJSON();
			var wrap = $(btn).closest('.npt-media-field');
			wrap.find('input[type=hidden]').val(att.id);
			var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
			wrap.find('.npt-media-preview').html('<img src=\"' + url + '\" style=\"max-width:80px;max-height:80px;display:block;margin-bottom:4px;\">');
			wrap.find('.npt-media-remove').show();
		});
		frame.open();
	});
	$(document).on('click', '.npt-media-remove', function(e){
		e.preventDefault();
		var wrap = $(this).closest('.npt-media-field');
		wrap.find('input[type=hidden]').val('0');
		wrap.find('.npt-media-preview').empty();
		$(this).hide();
	});
});
" );
} );

/**
 * One image-picker row (hidden attachment-ID input + preview + pick/remove
 * buttons) for the header/footer image settings.
 */
function npt_media_field_row( $label, $option, $description = '' ) {
	$id  = (int) get_option( $option, 0 );
	$url = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
	?>
	<tr>
		<th scope="row"><?php echo esc_html( $label ); ?></th>
		<td>
			<div class="npt-media-field">
				<div class="npt-media-preview">
					<?php if ( $url ) : ?>
						<img src="<?php echo esc_url( $url ); ?>" style="max-width:80px;max-height:80px;display:block;margin-bottom:4px;">
					<?php endif; ?>
				</div>
				<input type="hidden" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $id ); ?>">
				<button type="button" class="button npt-media-pick">انتخاب تصویر</button>
				<button type="button" class="button npt-media-remove" <?php echo $url ? '' : 'style="display:none;"'; ?>>حذف</button>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</div>
		</td>
	</tr>
	<?php
}

/**
 * Open one settings panel. Panels of inactive tabs are sent with the hidden
 * attribute so nothing flashes before the tab script runs; they are still in
 * the DOM, which is what keeps their fields inside the submitted form.
 */
function npt_panel_open( $key ) {
	$active = isset( $GLOBALS['npt_active_tab'] ) ? $GLOBALS['npt_active_tab'] : 'general';
	printf(
		'<section class="npt-panel" data-npt-panel="%1$s"%2$s>',
		esc_attr( $key ),
		$key === $active ? '' : ' hidden'
	);
}

function npt_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	/*
	 * Every tab is rendered on every page load and only *hidden* in the
	 * browser. Rendering one tab at a time used to erase the other tabs'
	 * options: options.php walks the option names registered in the group and
	 * writes an empty value for each one the submitted form does not contain.
	 */
	$tabs = array(
		'general'   => 'عمومی',
		'price'     => 'تنظیمات قیمت',
		'seo'       => 'سئو',
		'sms'       => 'پیامک',
		'contacts'  => 'مخاطبین',
		'bar_menu'  => 'منوی پایین',
		'bar_float' => 'آیکن شناور',
	);
	// Sub-tabs: each group lists its own key first, then the panels it shows.
	$groups = array(
		'general' => array(
			'general'    => 'تنظیمات عمومی',
			'login_page' => 'صفحه ورود',
			'panel_dash' => 'داشبورد مدیر',
			'backup'     => 'پشتیبان و بروزرسانی',
			'managers'   => 'مدیران کسب‌وکار',
			'errors'     => 'گزارش خطاها',
		),
		'price'   => array(
			'price'   => 'قیمت‌ها',
			'header'  => 'سربرگ',
			'content' => 'محتوا',
			'footer'  => 'پابرگ',
		),
		'sms'     => array(
			'sms'       => 'تنظیمات',
			'otp'       => 'کدهای الگو',
			'templates' => 'الگوهای پیامک',
			'sms_login' => 'ورود پیامکی',
		),
	);
	$parent_of = array();
	foreach ( $groups as $group_key => $items ) {
		foreach ( array_keys( $items ) as $item_key ) {
			$parent_of[ $item_key ] = $group_key;
		}
	}
	// Every key that can be shown: top tabs, group members, and the outside-form panels.
	$valid_tabs = array_merge( $tabs, $parent_of );
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore
	if ( ! isset( $valid_tabs[ $tab ] ) ) {
		$tab = 'general';
	}
	// Panels that hold their own <form> (contacts/templates CRUD) must sit
	// outside the settings form, and the shared submit button hides on them.
	$base_url              = admin_url( 'admin.php?page=navid-settings' );
	$GLOBALS['npt_active_tab'] = $tab;

	$current          = get_option( 'npt_edit_capability', 'manage_woocommerce' );
	$caps             = array(
		'manage_woocommerce' => 'مدیر فروشگاه / مدیر سایت (Shop Manager / Admin)',
		'manage_options'     => 'فقط مدیر سایت (Administrator)',
		'edit_products'      => 'هرکسی که اجازه‌ی ویرایش محصول دارد (Editor محصولات)',
	);
	$seo_heading      = get_option( 'npt_seo_heading', '' );
	$seo_heading_tag  = get_option( 'npt_seo_heading_tag', 'h2' );
	$seo_intro        = get_option( 'npt_seo_intro', '' );
	$enable_schema    = get_option( 'npt_enable_schema', true );
	$currency_unit    = npt_currency_unit();
	$online_format    = get_option( 'npt_online_size_format', 'psd' );
	$pdf_font         = get_option( 'npt_pdf_font', 'vazirmatn' );
	$header_title     = get_option( 'npt_header_title', '' );
	$header_color     = get_option( 'npt_header_color', '#2350b4' );
	$accent_color     = get_option( 'npt_accent_color', '#ed1c24' );
	$unit_sentence    = get_option( 'npt_unit_sentence', '' );
	$footer_domain    = get_option( 'npt_footer_domain', '' );
	$show_globe       = get_option( 'npt_show_globe', true );
	$telegram         = get_option( 'npt_telegram', '' );
	$instagram        = get_option( 'npt_instagram', '' );
	$meter_badge      = get_option( 'npt_meter_badge_enable', true );
	$meter_sentence   = get_option( 'npt_meter_sentence', '' );
	?>
	<div class="wrap npt-settings" dir="rtl">
		<h1>تنظیمات جدول قیمت نوید</h1>
		<p class="description" style="margin:-6px 0 12px">نسخه <?php echo esc_html( NPT_VERSION ); ?> — طراحی توسط <a href="https://spadts.ir" target="_blank" rel="noopener">ویرا فناوران اسپاد</a></p>
		<?php if ( isset( $_GET['npt_applied'] ) ) : // phpcs:ignore ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html( sprintf( __( 'درصد وارد شده روی قیمت %d محصول اعمال و ذخیره شد.', 'navid-price-table' ), (int) $_GET['npt_applied'] ) ); // phpcs:ignore ?></p>
			</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['npt_undone'] ) ) : // phpcs:ignore ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( 'قیمت %d محصول/وارییشن به مقدار قبل از تعدیل برگردانده شد.', (int) $_GET['npt_undone'] ) ); // phpcs:ignore ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['npt_pct_err'] ) ) : // phpcs:ignore
			$npt_pct_msgs = array(
				'range'    => sprintf( 'درصد باید بین %d و %d باشد.', NPT_PCT_MIN, NPT_PCT_MAX ),
				'busy'     => 'یک تعدیل قیمت تازه اجرا شده است؛ چند دقیقه صبر کنید (از اعمال دوباره‌ی ناخواسته جلوگیری شد).',
				'nobackup' => 'نسخه‌ی پشتیبانی برای برگرداندن وجود ندارد.',
			);
			$npt_pct_key = sanitize_key( wp_unslash( $_GET['npt_pct_err'] ) ); // phpcs:ignore
			?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( isset( $npt_pct_msgs[ $npt_pct_key ] ) ? $npt_pct_msgs[ $npt_pct_key ] : 'خطا' ); ?></p></div>
		<?php endif; ?>

		<nav class="nav-tab-wrapper" id="npt-tabs">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<button type="button" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" data-npt-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</nav>
		<?php foreach ( $groups as $group_key => $items ) : ?>
		<nav class="nav-tab-wrapper npt-subnav" data-npt-group="<?php echo esc_attr( $group_key ); ?>" hidden>
			<?php foreach ( $items as $item_key => $item_label ) : ?>
				<button type="button" class="nav-tab" data-npt-sub="<?php echo esc_attr( $item_key ); ?>"><?php echo esc_html( $item_label ); ?></button>
			<?php endforeach; ?>
		</nav>
		<?php endforeach; ?>

		<form method="post" action="options.php" id="npt-settings-form">
			<?php settings_fields( 'npt_settings' ); ?>

			<?php npt_panel_open( 'general' ); ?>
			<h2>آیکون اپلیکیشن و تصاویر پیش‌فاکتور</h2>
			<table class="form-table">
				<?php
				npt_media_field_row( 'آیکون اپلیکیشن (پنل روی گوشی)', 'npt_app_icon_id', 'تصویر مربعی ۵۱۲×۵۱۲ پیکسل (PNG). اگر انتخاب نشود، آیکون همراه افزونه استفاده می‌شود. بعد از تغییر، ممکن است برای نمایش روی گوشی نصب دوباره لازم باشد.' );
				npt_media_field_row( 'لوگوی پیش‌فاکتور', 'npt_proforma_logo_id', 'اگر خالی بماند، لوگوی سربرگ استفاده می‌شود.' );
				npt_media_field_row( 'مهر یا امضای فروشنده', 'npt_proforma_stamp_id', 'بالای خط «امضای فروشنده» در پیش‌فاکتور چاپی نمایش داده می‌شود.' );
				?>
			</table>


				<p class="description">تنظیمات عمومی افزونه: دسترسی ویرایش قیمت و راهنمای استفاده.</p>
				<table class="form-table">
					<tr>
						<th scope="row">چه کسانی اجازه‌ی ویرایش قیمت از داخل جدول را داشته باشند؟</th>
						<td>
							<select name="npt_edit_capability">
								<?php foreach ( $caps as $cap => $label ) : ?>
									<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $current, $cap ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">پیشنهاد پیش‌فرض: «مدیر فروشگاه / مدیر سایت» — یعنی کارفرما با نقش Shop Manager یا Administrator وارد شود، آیکون مداد را می‌بیند (هم در دسکتاپ، هم در موبایل).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">حذف داده‌ها هنگام حذف افزونه</th>
						<td>
							<label><input type="checkbox" name="npt_delete_data" value="1" <?php checked( (bool) get_option( 'npt_delete_data', false ) ); ?>> آرشیو پیش‌فاکتورها، مشتریان و مخاطبین پیامک هم پاک شود</label>
							<p class="description">پیش‌فرض خاموش است؛ با حذف افزونه این اطلاعات در دیتابیس می‌ماند.</p>
						</td>
					</tr>
				</table>

			</section>

			<?php npt_panel_open( 'seo' ); ?>


				<p class="description">این بخش، عناوین و متن سئوی بالای جدول سایت و خروجی داده ساختاریافته را کنترل می‌کند.</p>
				<table class="form-table">
					<tr>
						<th scope="row">عنوان بالای جدول (سئو)</th>
						<td>
							<input type="text" name="npt_seo_heading" value="<?php echo esc_attr( $seo_heading ); ?>" class="regular-text" placeholder="مثال: لیست قیمت روز سیم و کابل">
							<p class="description">اگر پر شود، این متن به‌صورت یک تگ عنوان (H1/H2/H3) بالای جدول نمایش داده می‌شود — برای موتورهای جست‌وجو مفید است. برای هر شورت‌کد هم می‌توانید جداگانه بازنویسی‌اش کنید: <code>[navid_price_table heading="عنوان دلخواه"]</code></p>
						</td>
					</tr>
					<tr>
						<th scope="row">سطح تگ عنوان</th>
						<td>
							<select name="npt_seo_heading_tag">
								<?php foreach ( array( 'h1', 'h2', 'h3' ) as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $seo_heading_tag, $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">اگر صفحه‌ای که شورت‌کد در آن قرار می‌گیرد از قبل یک H1 دارد (اسم صفحه)، معمولاً بهتر است اینجا H2 انتخاب شود تا ساختار عناوین صفحه به‌هم نریزد.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">متن توضیحی زیر عنوان (اختیاری)</th>
						<td>
							<textarea name="npt_seo_intro" rows="3" class="large-text"><?php echo esc_textarea( $seo_intro ); ?></textarea>
							<p class="description">یک یا دو جمله‌ی کوتاه حاوی کلمات کلیدی، مثلاً معرفی کوتاه محصولات یا برند. زیر عنوان و بالای جدول نمایش داده می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">داده ساختاریافته (Schema.org)</th>
						<td>
							<label>
								<input type="checkbox" name="npt_enable_schema" value="1" <?php checked( $enable_schema ); ?>>
								خروجی JSON-LD (نوع ItemList/Product/Offer) برای گوگل تولید شود
							</label>
							<p class="description">این گزینه به گوگل کمک می‌کند نام و قیمت محصولات جدول را بهتر بفهمد (مفید برای نتایج غنی/Rich Results). داده فقط در بارگذاری اولیه‌ی صفحه ساخته می‌شود، نه در فیلتر AJAX. واحد پول آن از گزینه‌ی «واحد نمایش قیمت» در تب «قیمت» پیروی می‌کند.</p>
						</td>
					</tr>
				</table>

			</section>

			<?php npt_panel_open( 'price' ); ?>


				<p class="description">این بخش، نحوه‌ی نمایش قیمت‌ها و تعدیل سراسری آن‌ها را کنترل می‌کند.</p>
				<table class="form-table">
					<tr>
						<th scope="row">واحد نمایش قیمت</th>
						<td>
							<select name="npt_currency_unit">
								<option value="IRT" <?php selected( $currency_unit, 'IRT' ); ?>>تومان</option>
								<option value="IRR" <?php selected( $currency_unit, 'IRR' ); ?>>ریال</option>
							</select>
							<p class="description">قیمت‌ها در دیتابیس همیشه به تومان ذخیره می‌شوند. با انتخاب «ریال»، همه‌جا (جدول سایت، مشاهده آنلاین، PDF، داده ساختاریافته و ویرایش درجا) عدد ×۱۰ (یک صفر اضافه) نمایش داده می‌شود و جمله‌ی واحد هم به «کلیه قیمت‌ها به ریال می‌باشد» تغییر می‌کند. در ویرایش درجا عدد به ریال وارد می‌شود و خودکار به تومان ذخیره می‌گردد.</p>
						</td>
					</tr>
				</table>

			</section>

			<?php npt_panel_open( 'header' ); ?>


				<p class="description">این بخش، سربرگِ «لیست قیمت» را در فایل PDF و صفحه‌ی مشاهده آنلاین کنترل می‌کند. اگر چیزی را خالی بگذارید، طرح فعلی (مطابق کاتالوگ چاپی) حفظ می‌شود.</p>
				<table class="form-table">
					<tr>
						<th scope="row">عنوان سربرگ</th>
						<td>
							<input type="text" name="npt_header_title" value="<?php echo esc_attr( $header_title ); ?>" class="regular-text" placeholder="لیست قیمت">
							<p class="description">اگر خالی باشد، «لیست قیمت» نمایش داده می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">رنگ عنوان سربرگ</th>
						<td>
							<input type="color" name="npt_header_color" value="<?php echo esc_attr( $header_color ); ?>">
							<p class="description">رنگ فعلی طرح: آبی #2350b4</p>
						</td>
					</tr>
					<tr>
						<th scope="row">رنگ خط ماه / رنگ تأکیدی</th>
						<td>
							<input type="color" name="npt_accent_color" value="<?php echo esc_attr( $accent_color ); ?>">
							<p class="description">رنگ خط «مهر ماه ۱۴۰۵» و همچنین رنگ تأکیدی کارت‌های موبایل جدول سایت. رنگ فعلی طرح: قرمز #ed1c24</p>
						</td>
					</tr>
					<tr>
						<th scope="row">خط ماه (نظیر «مهر ماه ۱۴۰۵»)</th>
						<td>
							<label><input type="checkbox" name="npt_header_show_month" value="1" <?php checked( get_option( 'npt_header_show_month', true ) ); ?>> نمایش خط ماه زیر عنوان</label>
						</td>
					</tr>
					<tr>
						<th scope="row">جمله‌ی واحد قیمت</th>
						<td>
							<input type="text" name="npt_unit_sentence" value="<?php echo esc_attr( $unit_sentence ); ?>" class="regular-text" placeholder="<?php echo esc_attr( npt_unit_sentence() ); ?>">
							<p class="description">مثلاً «کلیه قیمت‌ها به تومان می‌باشد». اگر خالی بماند، بر اساس واحد انتخاب‌شده در تب «قیمت» به‌طور خودکار ساخته می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">نشان «متر»</th>
						<td>
							<label><input type="checkbox" name="npt_meter_badge_enable" value="1" <?php checked( $meter_badge ); ?>> کنار قیمت محصولات فروش متری، نشان (متر) نمایش داده شود</label>
							<p class="description">نشان به‌صورت یک برچسب سبز کوچک کنار قیمت، در جدول سایت، مشاهده آنلاین و PDF ظاهر می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">جمله‌ی توضیح نشان متری</th>
						<td>
							<input type="text" name="npt_meter_sentence" value="<?php echo esc_attr( $meter_sentence ); ?>" class="regular-text" placeholder="<?php echo esc_attr( npt_meter_sentence_text() ); ?>">
							<p class="description">در «مشاهده آنلاین» کنار جمله‌ی واحد قیمت و در PDF به‌صورت خط دوم زیر همان جمله، در سربرگ نمایش داده می‌شود. اگر خالی بماند از متن پیش‌فرض استفاده می‌شود.</p>
						</td>
					</tr>
					<?php
					npt_media_field_row( 'لوگوی سربرگ', 'npt_logo_id', 'اگر انتخاب نشود، لوگوی همراه افزونه (مطابق کاتالوگ چاپی) استفاده می‌شود.' );
					npt_media_field_row( 'نشان کیفیت ۱', 'npt_mark1_id', 'سه نشان سمت چپ سربرگ (استاندارد، رتبه، نماد اعتماد). اگر انتخاب نشود، نشان‌های همراه افزونه استفاده می‌شوند.' );
					npt_media_field_row( 'نشان کیفیت ۲', 'npt_mark2_id' );
					npt_media_field_row( 'نشان کیفیت ۳', 'npt_mark3_id' );
					?>
					<tr>
						<th scope="row">فونت فایل PDF</th>
						<td>
							<select name="npt_pdf_font">
								<option value="vazirmatn" <?php selected( $pdf_font, 'vazirmatn' ); ?>>وزیرمتن (پیش‌فرض)</option>
								<option value="sahel" <?php selected( $pdf_font, 'sahel' ); ?>>ساحل</option>
							</select>
						</td>
					</tr>
				</table>

			</section>

			<?php npt_panel_open( 'content' ); ?>


				<p class="description">این تب فقط قالب نمایش سایزها را در صفحه‌ی «مشاهده آنلاین» کنترل می‌کند؛ سایر تنظیمات محتوایی به تب‌های «سربرگ»، «قیمت» و «سئو» منتقل شده‌اند.</p>
				<table class="form-table">
					<tr>
						<th scope="row">قالب سایز در مشاهده آنلاین</th>
						<td>
							<select name="npt_online_size_format">
								<option value="psd" <?php selected( $online_format, 'psd' ); ?>>تعداد×سایز (مطابق کاتالوگ چاپی و PDF)</option>
								<option value="name" <?php selected( $online_format, 'name' ); ?>>سایز×تعداد (قالب قدیمی‌تر)</option>
							</select>
							<p class="description">جدول سایت و PDF همیشه با قالب کاتالوگ چاپی («تعداد×سایز») نمایش داده می‌شوند؛ این گزینه فقط صفحه‌ی «مشاهده آنلاین» را عوض می‌کند.</p>
						</td>
					</tr>
				</table>

			</section>

			<?php npt_panel_open( 'footer' ); ?>


				<p class="description">این بخش، پابرگِ «لیست قیمت» را در فایل PDF و صفحه‌ی مشاهده آنلاین کنترل می‌کند. موارد خالی = طرح فعلی؛ موارد پرشده به طرح اضافه می‌شوند.</p>
				<table class="form-table">
					<tr>
						<th scope="row">متن دامنه در پابرگ</th>
						<td>
							<input type="text" name="npt_footer_domain" value="<?php echo esc_attr( $footer_domain ); ?>" class="regular-text" placeholder="W w w . S i m c a b l e n a v i d . I R">
							<p class="description">اگر خالی باشد، از آدرس سایت به‌صورت حرف‌فاصله‌دار (مطابق کاتالوگ چاپی) ساخته می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">آیکن کره‌ی زمین</th>
						<td>
							<label><input type="checkbox" name="npt_show_globe" value="1" <?php checked( $show_globe ); ?>> نمایش آیکن کره‌ی زمین کنار دامنه</label>
						</td>
					</tr>
					<tr>
						<th scope="row">شماره تماس</th>
						<td>
							<input type="text" name="npt_phone" value="<?php echo esc_attr( get_option( 'npt_phone', '' ) ); ?>" class="regular-text" placeholder="021-xxxxxxx / 0912xxxxxxx">
							<p class="description">اگر پر شود، زیر دامنه در پابرگ نمایش داده می‌شود (مشاهده آنلاین و PDF).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">آدرس</th>
						<td>
							<input type="text" name="npt_address" value="<?php echo esc_attr( get_option( 'npt_address', '' ) ); ?>" class="regular-text">
							<p class="description">اگر پر شود، زیر شماره تماس در پابرگ نمایش داده می‌شود (مشاهده آنلاین و PDF).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">آیدی تلگرام</th>
						<td>
							<input type="text" name="npt_telegram" value="<?php echo esc_attr( $telegram ); ?>" class="regular-text" placeholder="navidcable">
							<p class="description">فقط آیدی را بنویسید (بدون @). اگر پر شود، آیکن تلگرام با لینک t.me/ در پابرگ نمایش داده می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">آیدی اینستاگرام</th>
						<td>
							<input type="text" name="npt_instagram" value="<?php echo esc_attr( $instagram ); ?>" class="regular-text" placeholder="navidcable">
							<p class="description">فقط آیدی را بنویسید (بدون @). اگر پر شود، آیکن اینستاگرام با لینک instagram.com/ در پابرگ نمایش داده می‌شود.</p>
						</td>
					</tr>
					<?php npt_media_field_row( 'تصویر آیکن کره‌ی زمین', 'npt_globe_id', 'اختیاری — اگر از کتابخانه‌ی رسانه انتخاب نشود، آیکن همراه افزونه استفاده می‌شود.' ); ?>
					<?php npt_media_field_row( 'تصویر آیکن تلگرام', 'npt_telegram_icon_id', 'اختیاری — اگر انتخاب نشود، آیکن وکتور پیش‌فرض (مطابق طرح فعلی) رسم می‌شود.' ); ?>
					<?php npt_media_field_row( 'تصویر آیکن اینستاگرام', 'npt_instagram_icon_id', 'اختیاری — اگر انتخاب نشود، آیکن وکتور پیش‌فرض (مطابق طرح فعلی) رسم می‌شود.' ); ?>
				</table>

			</section>

			<?php npt_panel_open( 'bar_menu' ); ?>


				<?php NPT_Bar::render_menu_tab(); ?>

			</section>

			<?php npt_panel_open( 'bar_float' ); ?>


				<?php NPT_Bar::render_float_tab(); ?>

			</section>

			<?php npt_panel_open( 'sms' ); ?>


				<?php NPT_SMS::render_credentials_fields(); ?>
			</section>

			<?php npt_panel_open( 'sms_login' ); ?>
				<?php NPT_Bar::render_sms_login_card(); ?>
			</section>

			<?php npt_panel_open( 'otp' ); ?>


				<?php NPT_SMS::render_otp_fields(); ?>

			</section>

			<?php npt_panel_open( 'login_page' ); ?>
				<h2>تنظیمات صفحه ورود</h2>
				<table class="form-table">
					<?php
					npt_media_field_row( 'لوگوی صفحه ورود', 'npt_login_logo_id', 'اگر خالی بماند، لوگوی سربرگ استفاده می‌شود.' );
					npt_media_field_row( 'آیکن مرورگر در صفحه ورود', 'npt_login_icon_id', 'تصویر مربع (PNG). اگر خالی بماند، آیکن اپ استفاده می‌شود.' );
					?>
				</table>
			</section>

			<?php npt_panel_open( 'panel_dash' ); ?>
				<h2>تنظیمات داشبورد مدیر کسب‌وکار</h2>
				<table class="form-table">
					<?php
					npt_media_field_row( 'لوگوی داشبورد', 'npt_panel_logo_id', 'در سربرگ پنل مدیر نمایش داده می‌شود. اگر خالی بماند، نام سایت نمایش داده می‌شود.' );
					npt_media_field_row( 'آیکن مرورگر پنل', 'npt_panel_icon_id', 'اگر خالی بماند، آیکن اپ استفاده می‌شود.' );
					?>
				</table>
			</section>

			<p class="npt-submit-row"><?php submit_button( 'ذخیره تنظیمات' ); ?></p>
		</form>

		<?php /* Panels below keep their own <form> and therefore live outside the settings form. */ ?>

		<?php npt_panel_open( 'templates' ); ?>

			<?php NPT_SMS::render_templates_panel(); ?>
		</section>

		<?php npt_panel_open( 'contacts' ); ?>

			<?php NPT_SMS::render_contacts_panel(); ?>
		</section>

		<?php npt_panel_open( 'managers' ); ?>

			<?php NPT_Panel::render_managers_panel(); ?>
		</section>

		<?php npt_panel_open( 'backup' ); ?>

			<?php NPT_Backup::render(); ?>
		</section>

		<?php npt_panel_open( 'errors' ); ?>
			<?php NPT_Log::render(); ?>
		</section>

		<?php npt_panel_open( 'price' ); ?>

			<hr>
			<h2>تعدیل سراسری قیمت‌ها (اعمال درصدی یکجا)</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm( 'قیمت همه‌ی محصولات برای همیشه به درصد وارد شده تغییر می‌کند. ادامه می‌دهید؟' );">
				<?php wp_nonce_field( 'npt_apply_percent', 'npt_apply_nonce' ); ?>
				<input type="hidden" name="action" value="npt_apply_percent">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="npt_adjust_percent">درصد تغییر قیمت</label></th>
						<td>
							<input type="number" step="0.1" min="<?php echo (int) NPT_PCT_MIN; ?>" max="<?php echo (int) NPT_PCT_MAX; ?>" id="npt_adjust_percent" name="npt_adjust_percent" value="<?php echo esc_attr( get_option( 'npt_adjust_percent', 0 ) ); ?>" class="small-text"> %
							<p class="description">
								با زدن دکمه‌ی زیر، قیمت همه‌ی محصولات منتشرشده (قیمت معمولی و قیمت حراج، شامل وارییشن‌های محصولات متغیر) همین حالا و به‌صورت دائم در این درصد ضرب می‌شود.
								مثال: برای ۵٪ گران‌تر کردن عدد <code>5</code> و برای ۲٪ ارزان‌تر کردن عدد <code>-2</code> را وارد کنید.
								محدوده‌ی مجاز ‎-50‎ تا ‎+100‎ درصد است. قیمت‌های قبلی ذخیره می‌شوند و با دکمه‌ی «برگرداندن آخرین تعدیل» قابل بازگشت‌اند؛ با این حال پیش از اعمال از دیتابیس بک‌آپ بگیرید.
							</p>
						</td>
					</tr>
				</table>
				<button type="submit" class="button button-secondary">اعمال درصد روی قیمت همه محصولات</button>
			</form>
			<?php $npt_backup = get_option( 'npt_price_backup' ); ?>
			<?php if ( is_array( $npt_backup ) && ! empty( $npt_backup['prices'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px" onsubmit="return confirm( 'قیمت‌ها به مقدار قبل از آخرین تعدیل برگردانده می‌شود. ادامه می‌دهید؟' );">
					<?php wp_nonce_field( 'npt_undo_percent', 'npt_undo_nonce' ); ?>
					<input type="hidden" name="action" value="npt_undo_percent">
					<button type="submit" class="button">برگرداندن آخرین تعدیل (<?php echo esc_html( number_format_i18n( (float) $npt_backup['pct'], 1 ) ); ?>٪، <?php echo esc_html( number_format_i18n( count( $npt_backup['prices'] ) ) ); ?> مورد)</button>
				</form>
			<?php endif; ?>
		</section>

		<?php npt_panel_open( 'general' ); ?>
			<hr>
			<h2>شورت‌کد</h2>
			<p>برای نمایش جدول در هر صفحه یا نوشته، از شورت‌کد زیر استفاده کنید:</p>
			<code>[navid_price_table]</code>
			<p>می‌توانید یک دسته‌بندی پیش‌فرض هم مشخص کنید:</p>
			<code>[navid_price_table category="کابل-افشان"]</code>
		</section>

		<?php npt_render_settings_tab_assets( $tab, $base_url, $parent_of ); ?>
	</div>
	<?php
}

/**
 * Tab switching for the settings page.
 *
 * The panels are all in the DOM at once (see the note in
 * npt_render_settings_page()); this only shows one of them. The submit button
 * hides on the panels that carry their own form, and the current tab is kept
 * in the URL so a save returns to the same place.
 */
function npt_render_settings_tab_assets( $tab, $base_url, $parent_of ) {
	?>
	<style id="npt-settings-tabs-css">
		.npt-settings .npt-panel[hidden] { display: none; }
		.npt-settings .npt-subnav[hidden] { display: none !important; }
		.npt-settings .nav-tab { cursor: pointer; font-family: inherit; }
		.npt-settings .npt-submit-row { margin-top: 18px; }
		.npt-settings.npt-active-noform .npt-submit-row { display: none; }
		/* Media rows must never grow with the chosen image, and the settings
		   page has to stay usable on a phone. */
		.npt-settings .npt-media-preview img { max-width: 80px; max-height: 80px; object-fit: contain; }
		@media (max-width: 782px) {
			.npt-settings form { max-width: 100%; }
			.npt-settings .form-table > tbody > tr > th,
			.npt-settings .form-table > tr > th { display: block; float: none; width: 100%; padding: 14px 0 2px; }
			.npt-settings .form-table > tbody > tr > td,
			.npt-settings .form-table > tr > td { display: block; float: none; width: 100%; padding: 0 0 12px; }
			.npt-settings .form-table input[type=text],
			.npt-settings .form-table input[type=tel],
			.npt-settings .form-table input[type=url],
			.npt-settings .form-table select,
			.npt-settings .form-table textarea { max-width: 100%; width: 100%; }
			.npt-settings #npt-tabs { display: flex; flex-wrap: wrap; gap: 2px; }
			.npt-settings .nav-tab { flex: 0 0 auto; margin: 0; }
		}
	</style>
	<script id="npt-settings-tabs-js">
	(function () {
		var wrap = document.querySelector('.npt-settings');
		var nav = document.getElementById('npt-tabs');
		var form = document.getElementById('npt-settings-form');
		if (! wrap || ! nav) { return; }
		var base = <?php echo wp_json_encode( $base_url ); ?>;
		var parentOf = <?php echo wp_json_encode( $parent_of ); ?>;
		var buttons = nav.querySelectorAll('[data-npt-tab]');
		var subnavs = wrap.querySelectorAll('.npt-subnav');

		function show(key, pushUrl) {
			var found = false;
			Array.prototype.forEach.call(wrap.querySelectorAll('[data-npt-panel]'), function (panel) {
				var on = panel.getAttribute('data-npt-panel') === key;
				panel.hidden = ! on;
				if (on) { found = true; }
			});
			if (! found) { show('general', pushUrl); return; }
			var top = parentOf[key] || key;
			Array.prototype.forEach.call(buttons, function (b) {
				b.classList.toggle('nav-tab-active', b.getAttribute('data-npt-tab') === top);
			});
			Array.prototype.forEach.call(subnavs, function (nv) {
				var mine = nv.getAttribute('data-npt-group') === top;
				nv.hidden = ! mine;
				Array.prototype.forEach.call(nv.querySelectorAll('[data-npt-sub]'), function (b) {
					b.classList.toggle('nav-tab-active', b.getAttribute('data-npt-sub') === key);
				});
			});
			var inForm = !! ( form && form.querySelector( '[data-npt-panel="' + key + '"]' ) );
			wrap.classList.toggle( 'npt-active-noform', ! inForm );
			if (form) { form.action = 'options.php'; }
			if (pushUrl && window.history && history.replaceState) {
				history.replaceState(null, '', base + '&tab=' + encodeURIComponent(key));
			}
		}

		Array.prototype.forEach.call(buttons, function (b) {
			b.addEventListener('click', function () { show(b.getAttribute('data-npt-tab'), true); });
		});
		Array.prototype.forEach.call(subnavs, function (nv) {
			Array.prototype.forEach.call(nv.querySelectorAll('[data-npt-sub]'), function (b) {
				b.addEventListener('click', function () { show(b.getAttribute('data-npt-sub'), true); });
			});
		});

		var initial = <?php echo wp_json_encode( $tab ); ?>;
		show(initial, false);

		if (form) {
			form.addEventListener('submit', function () {
				var active = nav.querySelector('.nav-tab-active');
				form.action = 'options.php';
				if (active) {
					history.replaceState(null, '', base + '&tab=' + encodeURIComponent(active.getAttribute('data-npt-tab')));
				}
			});
		}
	})();
	</script>
	<?php
}

/* ---------------------------------------------------------------------
 * Assets
 * ------------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {
	// Use the file's own last-modified time as the asset version instead of
	// a hand-maintained constant: this guarantees the CSS/JS URL changes
	// automatically the instant either file's *content* changes, so a
	// forgotten version bump can never cause browsers/CDNs to keep serving
	// a stale, already-fixed stylesheet.
	$css_path = NPT_PATH . 'assets/css/style.css';
	$js_path  = NPT_PATH . 'assets/js/script.js';
	$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : NPT_VERSION;
	$js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : NPT_VERSION;

	wp_register_style( 'npt-style', NPT_URL . 'assets/css/style.css', array(), $css_ver );
	// Brand colors from the settings page flow into the stylesheet as
	// custom properties, so the site table (esp. mobile cards) and any
	// future accents follow the header color without touching CSS.
	wp_add_inline_style(
		'npt-style',
		'.npt-wrap{--npt-brand:' . esc_html( vsprintf( '#%02x%02x%02x', npt_header_color_rgb() ) ) . ';--npt-accent:' . esc_html( vsprintf( '#%02x%02x%02x', npt_accent_color_rgb() ) ) . ';}'
	);
	wp_register_script( 'npt-script', NPT_URL . 'assets/js/script.js', array( 'jquery' ), $js_ver, true );
	wp_localize_script( 'npt-script', 'NPT', array(
		'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
		'nonce'          => wp_create_nonce( 'npt_nonce' ),
		'canEdit'        => npt_current_user_can_edit(),
		'currencyFactor' => npt_currency_factor(),
		'i18n'           => array(
			'save'     => __( 'ذخیره', 'navid-price-table' ),
			'cancel'   => __( 'انصراف', 'navid-price-table' ),
			'saving'   => __( 'در حال ذخیره...', 'navid-price-table' ),
			'error'    => __( 'خطا در ذخیره‌سازی. دوباره تلاش کنید.', 'navid-price-table' ),
			'invalid'  => __( 'قیمت وارد شده معتبر نیست.', 'navid-price-table' ),
			'all'      => __( 'همه دسته‌ها', 'navid-price-table' ),
			'currency' => npt_currency_word(),
		),
	) );
} );

/* ---------------------------------------------------------------------
 * Shortcode
 * ------------------------------------------------------------------- */
add_shortcode( 'navid_price_table', 'npt_render_shortcode' );

function npt_render_shortcode( $atts ) {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return '';
	}

	wp_enqueue_style( 'npt-style' );
	wp_enqueue_script( 'npt-script' );

	$atts = shortcode_atts( array(
		'category' => '',
		'heading'  => null,
	), $atts, 'navid_price_table' );

	$instance_id = 'npt-' . wp_generate_password( 6, false, false );

	$heading     = null !== $atts['heading'] ? sanitize_text_field( $atts['heading'] ) : get_option( 'npt_seo_heading', '' );
	$heading_tag = npt_sanitize_heading_tag( get_option( 'npt_seo_heading_tag', 'h2' ) );
	$intro       = get_option( 'npt_seo_intro', '' );

	ob_start();
	?>
	<div class="npt-wrap" id="<?php echo esc_attr( $instance_id ); ?>" data-default-cat="<?php echo esc_attr( $atts['category'] ); ?>">
		<?php
		/*
		 * A tiny inline critical-CSS rule, always delivered inside the page
		 * HTML itself — not from the external stylesheet file. This exists
		 * purely as a caching failsafe: if a page-caching plugin serves an
		 * old cached copy of this page (with an old <link> to the CSS file)
		 * before it's been purged, this inline rule still guarantees the
		 * desktop table and mobile row list are never both visible at once.
		 * The full stylesheet in style.css remains the source of truth for
		 * everything else; this only covers the one rule that matters most.
		 */
		?>
		<style>
			#<?php echo esc_attr( $instance_id ); ?> .npt-mobile-only { display: none; }
			@media (max-width: 600px) {
				#<?php echo esc_attr( $instance_id ); ?> .npt-desktop-only { display: none !important; }
				#<?php echo esc_attr( $instance_id ); ?> .npt-mobile-only { display: block !important; }
			}
		</style>
		<?php if ( $heading ) : ?>
			<<?php echo esc_html( $heading_tag ); ?> class="npt-seo-heading"><?php echo esc_html( $heading ); ?></<?php echo esc_html( $heading_tag ); ?>>
		<?php endif; ?>
		<?php if ( $intro ) : ?>
			<p class="npt-seo-intro"><?php echo esc_html( $intro ); ?></p>
		<?php endif; ?>
		<div class="npt-toolbar">
			<div class="npt-tb-title"><?php echo npt_pdf_icon(); // phpcs:ignore ?><span><?php echo esc_html( get_option( 'npt_toolbar_title', 'دریافت لیست قیمت رسمی سیم و کابل نوید' ) ); ?></span></div>
			<div class="npt-tb-sub"><?php echo esc_html( get_option( 'npt_toolbar_sub', 'آخرین به‌روزرسانی: امروز | شامل قیمت‌های بازار لاله زار' ) ); ?></div>
			<div class="npt-tb-btns">
				<a href="<?php echo esc_url( npt_pdf_download_url( $atts['category'] ) ); ?>" class="npt-pdf-btn" rel="nofollow"><?php echo npt_pdf_icon(); // phpcs:ignore ?><span><?php esc_html_e( 'دانلود PDF', 'navid-price-table' ); ?></span></a>
				<a href="<?php echo esc_url( get_permalink() ? get_permalink() : home_url( '/' ) ); ?>#<?php echo esc_attr( $instance_id ); ?>" class="npt-view-btn"><?php echo npt_eye_icon(); // phpcs:ignore ?><span><?php esc_html_e( 'مشاهده آنلاین', 'navid-price-table' ); ?></span></a>
			</div>
		</div>
		<div class="npt-filter-row">
			<label for="<?php echo esc_attr( $instance_id ); ?>-filter" class="npt-filter-label"><?php esc_html_e( 'فیلتر دسته‌بندی:', 'navid-price-table' ); ?></label>
			<?php echo npt_category_dropdown( $atts['category'], $instance_id . '-filter' ); // phpcs:ignore ?>
		</div>
		<div class="npt-table-holder">
			<?php echo npt_render_table( $atts['category'] ); // phpcs:ignore ?>
		</div>
	</div>
	<?php
	echo npt_render_schema_json_ld( $atts['category'] ); // phpcs:ignore

	return ob_get_clean();
}

/**
 * Build the <select> of product categories.
 */
function npt_category_dropdown( $selected = '', $id = '' ) {
	$terms = npt_sort_categories_by_brand( get_terms( array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
	) ) );

	$html  = '<select class="npt-cat-filter" id="' . esc_attr( $id ) . '">';
	$html .= '<option value="">' . esc_html__( 'همه دسته‌ها', 'navid-price-table' ) . '</option>';

	if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
		foreach ( $terms as $term ) {
			// Persian term slugs can come back percent-encoded; decode both
			// sides before comparing so the active filter stays selected.
			$html .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $term->slug ),
				selected( sanitize_title( urldecode( $selected ) ), sanitize_title( urldecode( $term->slug ) ), false ),
				esc_html( $term->name )
			);
		}
	}

	$html .= '</select>';
	return $html;
}

/**
 * Fetch the products for a given category slug (or all products).
 * Shared by the table renderer, and the schema.org builder so the query
 * logic lives in exactly one place.
 */
function npt_query_products( $category_slug = '' ) {
	$args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	);

	if ( ! empty( $category_slug ) ) {
		$args['tax_query'] = array( // phpcs:ignore
			array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => sanitize_title( $category_slug ),
			),
		);
	}

	return new WP_Query( $args );
}

/**
 * Convert ASCII 0-9 digits inside a string to Persian digits.
 */
function npt_to_persian_digits( $string ) {
	static $map = null;
	if ( null === $map ) {
		$map = array_combine(
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' )
		);
	}
	return strtr( $string, $map );
}

/**
 * Display currency unit: 'IRT' (Toman — the unit prices are stored in) or
 * 'IRR' (Rial — display values are multiplied by 10). Storage and the
 * inline-edit save path always stay in Toman.
 */
function npt_currency_unit() {
	return 'IRR' === get_option( 'npt_currency_unit', 'IRT' ) ? 'IRR' : 'IRT';
}

function npt_currency_word() {
	return 'IRR' === npt_currency_unit()
		? __( 'ریال', 'navid-price-table' )
		: __( 'تومان', 'navid-price-table' );
}

function npt_currency_factor() {
	// 1 Toman = 10 Rial.
	return 'IRR' === npt_currency_unit() ? 10 : 1;
}

/**
 * Stored (Toman) price → the value an editor should see in the inline-edit
 * input, in the configured display unit.
 */
function npt_display_price_value( $price ) {
	$value = (float) $price * npt_currency_factor();
	return ( $value == (int) $value ) ? (int) $value : $value;
}

/**
 * The "کلیه قیمت‌ها به تومان می‌باشد" sentence shown in the PDF header and
 * the online view. Falls back to an auto-generated sentence matching the
 * selected currency unit when no custom text is set.
 */
function npt_unit_sentence() {
	$custom = trim( (string) get_option( 'npt_unit_sentence', '' ) );
	if ( '' !== $custom ) {
		return $custom;
	}
	/* translators: %s: currency word (تومان/ریال) */
	return sprintf( __( 'کلیه قیمت‌ها به %s می‌باشد', 'navid-price-table' ), npt_currency_word() );
}

/**
 * Format a price for display: converts to the configured display unit
 * (Rial = ×10), thousands-separated, Persian digits, and optionally with
 * the currency word appended (used on mobile and the online view, where no
 * column header carries the unit).
 */
function npt_format_price( $price, $with_currency = false ) {
	if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
		return '—';
	}
	$value     = (float) $price * npt_currency_factor();
	$formatted = npt_to_persian_digits( number_format( $value ) );
	if ( $with_currency ) {
		$formatted .= ' ' . npt_currency_word();
	}
	return $formatted;
}

/**
 * The fixed brand order for the price list (PDF + online view + dropdown),
 * exactly as the printed catalog is laid out. Categories missing from this
 * list are appended at the end (sorted by name) until added here.
 */
function npt_category_brand_order() {
	return array(
		'سیم افشان',
		'سیم ارت افشان',
		'سیم مفتولی',
		'کابل افشان',
		'کابل زمینی',
		'سیم نایلون',
		'سیم کیسه‌ای',
		'کابل کولر',
		'کابل آلومینیوم زمینی',
	);
}

/**
 * Normalize a name for comparison: drop ZWNJ, spaces and tabs, and unify
 * the Arabic ی/ک variants to Persian. Product names in the shop mix all of
 * these ("کابل افشان ۰.۷۵ ×۲" vs "کابل افشان۰.۷۵×۳"), so matching must be
 * normalization-tolerant.
 */
function npt_norm_name( $string ) {
	return str_replace(
		array( "\xE2\x80\x8C", ' ', "\t", 'ي', 'ك' ),
		array( '', '', '', 'ی', 'ک' ),
		$string
	);
}

/**
 * Index of a category inside the brand order, matched by exact normalized
 * name or by prefix in either direction (so "کابل کولر" also catches the
 * existing "کابل کولری" term). Unknown categories get PHP_INT_MAX (last).
 */
function npt_brand_index( $norm_name, $order ) {
	foreach ( $order as $i => $brand ) {
		if ( $norm_name === $brand || 0 === strpos( $norm_name, $brand ) || 0 === strpos( $brand, $norm_name ) ) {
			return $i;
		}
	}
	return PHP_INT_MAX;
}

/**
 * Sort a list of WP_Term categories into the fixed brand order.
 */
function npt_sort_categories_by_brand( $terms ) {
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return $terms;
	}
	$order = array_map( 'npt_norm_name', npt_category_brand_order() );

	usort( $terms, function ( $a, $b ) use ( $order ) {
		$ia = npt_brand_index( npt_norm_name( $a->name ), $order );
		$ib = npt_brand_index( npt_norm_name( $b->name ), $order );
		if ( $ia !== $ib ) {
			return $ia - $ib;
		}
		return strcmp( npt_norm_name( $a->name ), npt_norm_name( $b->name ) );
	} );
	return $terms;
}

/**
 * Strip the category name prefix from a product name, walking the ORIGINAL
 * string char-by-char so the untouched remainder (size part) survives.
 * Returns '' when the name doesn't start with the category prefix.
 */
function npt_strip_category_prefix( $product_name, $category_name ) {
	$norm_product = npt_norm_name( $product_name );
	$norm_cat     = npt_norm_name( $category_name );

	if ( '' === $norm_cat || 0 !== strpos( $norm_product, $norm_cat ) ) {
		return '';
	}

	$parts = preg_split( '//u', $product_name, -1, PREG_SPLIT_NO_EMPTY );
	$cat   = preg_split( '//u', $norm_cat, -1, PREG_SPLIT_NO_EMPTY );
	$ci    = 0;
	$out   = array();

	foreach ( $parts as $i => $ch ) {
		if ( $ci < count( $cat ) && str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $ch ) === $cat[ $ci ] ) {
			$ci++;
			continue;
		}
		if ( $ci < count( $cat ) && in_array( $ch, array( ' ', "\t", "\xE2\x80\x8C" ), true ) ) {
			continue;
		}
		$out = array_slice( $parts, $i );
		break;
	}

	// Drop leftover leading separators ("  ۱۶×۲" → "۱۶×۲"), then a stray «ی»
	// left when the category term word is a letter shorter than the product's
	// («کابل کولر» term vs «کابل کولری 1×4» product). Sizes always start with a
	// digit, so a leading «ی» can never be part of the size.
	$short = preg_replace( '/^[\s\x{200C}]+/u', '', implode( '', $out ) );
	return preg_replace( '/^ی[\s\x{200C}]*(?=[0-9۰-۹٠-٩])/u', '', $short );
}

/**
 * Normalize a size label for the printed/online price list: ASCII and
 * Arabic-Indic digits become Persian digits, '*' becomes '×', and a decimal
 * point between digits becomes '/' (the PSD convention, "0.75" → "۰/۷۵").
 */
function npt_normalize_size_display( $string ) {
	$string = strtr( trim( $string ), array(
		'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
		'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
		'٠' => '۰', '١' => '۱', '٢' => '۲', '٣' => '۳', '٤' => '۴',
		'٥' => '۵', '٦' => '۶', '٧' => '۷', '٨' => '۸', '٩' => '۹',
		'*' => '×',
	) );
	return preg_replace( '/([۰-۹])[\.٫]([۰-۹])/u', '$1/$2', $string );
}

/**
 * Convert a raw size token to the printed PSD convention {count}×{size}.
 * WooCommerce product names use the reversed order ("0.50×1" = size×count),
 * while the PSD prints "۱×۰/۵۰". Combination sizes swap each side:
 * "50+25×3" → "۳×۵۰+۲۵". Non-matching tokens pass through unchanged.
 */
function npt_product_display_size( $raw ) {
	$raw = trim( $raw );
	$num = '[0-9۰-۹٠-٩\.\/٫]+';
	if ( preg_match( '/^(' . $num . ')(?:\+(' . $num . '))?\s*[×*]\s*([0-9۰-۹٠-٩]+)$/u', $raw, $m ) ) {
		$left = ( '' !== ( $m[2] ?? '' ) ) ? $m[2] . '+' . $m[1] : $m[1];
		$raw  = $m[3] . '×' . $left;
	}
	return npt_normalize_size_display( $raw );
}

/**
 * Canonical comparable key for a displayed size label, so curated-list
 * lookups survive digit-script / separator variations: anything that reads
 * as {count}×{size} (with optional +section) yields "count:size[+sec]",
 * otherwise '' (unknown).
 */
function npt_size_order_key( $label ) {
	$s = npt_norm_name( $label );
	$s = strtr( $s, array(
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
		'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
		'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		'×' => '*',
	) );
	$s = str_replace( array( '/', '٫' ), '.', $s );
	if ( ! preg_match( '/^([0-9.]+)\*([0-9.]+)(?:\+([0-9.]+))?$/', $s, $m ) ) {
		return '';
	}
	$key = (string) (float) $m[1] . ':' . (string) (float) $m[2];
	if ( isset( $m[3] ) ) {
		$key .= '+' . (string) (float) $m[3];
	}
	return $key;
}

/**
 * The curated row order per category, transcribed from the original PSD
 * layout (سایز صحیح). Order is hand-made by the brand and is not
 * reproducible by numeric sorting (e.g. آلومینیوم 2*50 comes last).
 */
function npt_category_size_order() {
	return array(
		'سیم افشان'            => array( '1*0/50', '1*0/75', '1*1', '1*1.5', '1*2/5', '1*4', '1*6', '1*10', '1*16', '1*25', '1*35', '1*50', '1*70', '1*95' ),
		'سیم ارت افشان'        => array( '1*1/5', '1*2/5', '1*4', '1*6', '1*10', '1*16', '1*25', '1*35', '1*50', '1*70', '1*95' ),
		'سیم مفتول'            => array( '1*1', '1*1/5', '1*16', '1*25', '1*35', '1*50' ),
		'کابل افشان'           => array(
			'2*0/50', '2*0/75', '2*1', '2*1/5', '2*2/5', '2*4', '2*6', '2*10', '2*16',
			'3*0/50', '3*0/75', '3*1', '3*1/5', '3*2/5', '3*4', '3*6', '3*10', '3*16', '3*25',
			'3*25+16', '3*35', '3*35+16', '3*50', '3*50+25', '3*70', '3*70+35', '3*95+50',
			'4*0/50', '4*0/75', '4*1', '4*1/5', '4*2/5', '4*4', '4*6', '4*10', '4*16', '4*25', '4*35',
			'5*1', '5*1/5', '5*2/5', '5*4', '5*6', '5*10', '5*16', '5*25', '5*35',
			'6*1/5', '7*1/5',
		),
		'کابل زمینی'           => array( '2*4', '2*6', '2*10', '2*16', '3*25+16', '3*35+16', '3*50+25', '4*4', '4*6', '4*10', '4*16' ),
		'سیم نایلون'           => array( '2*0/50', '2*0/75', '2*1', '2*1/5', '2*2/5' ),
		'سیم کیسه ای'          => array( '2*0/50', '2*0/75', '2*1' ),
		'کابل کولری'           => array( '4*1', '4*1/5', '5*1', '5*1/5' ),
		'کابل آلومینیوم زمینی' => array( '2*10', '2*16', '2*25', '2*35', '4*10', '4*16', '4*25', '4*35', '3*25+16', '3*35+16', '3*50+25', '2*50' ),
	);
}

/**
 * Curated sizes of a category as a comparable key → printed-label map.
 * Brand matching tolerates wording drift between product categories and
 * the PSD column names (e.g. «کابل کولر» ↔ «کابل کولری»).
 */
function npt_curated_size_map( $category_name ) {
	static $cache = array();
	if ( isset( $cache[ $category_name ] ) ) {
		return $cache[ $category_name ];
	}

	$order = npt_category_size_order();
	$names = array_keys( $order );
	$bi    = npt_brand_index( npt_norm_name( $category_name ), array_map( 'npt_norm_name', $names ) );
	$map   = array();

	if ( isset( $names[ $bi ] ) ) {
		foreach ( $order[ $names[ $bi ] ] as $label ) {
			$key = npt_size_order_key( npt_normalize_size_display( $label ) );
			if ( '' !== $key ) {
				$map[ $key ] = npt_normalize_size_display( $label );
			}
		}
	}

	$cache[ $category_name ] = $map;
	return $map;
}

/**
 * The short label shown in the PDF and the online price list: manual سایز
 * field first, then auto-strip the category prefix from the product name
 * and reverse it into the PSD {count}×{size} convention. When the result
 * matches the category's curated size list, the list's exact spelling is
 * used. Falls back to the full product name when nothing matches.
 */
function npt_product_list_label( $product, $category_name ) {
	$size = trim( (string) get_post_meta( $product->get_id(), '_npt_size_label', true ) );
	if ( '' !== $size ) {
		return npt_normalize_size_display( $size );
	}

	$short = npt_strip_category_prefix( $product->get_name(), $category_name );
	if ( '' === $short ) {
		return npt_normalize_size_display( $product->get_name() );
	}

	$display = npt_product_display_size( $short );
	$map     = npt_curated_size_map( $category_name );
	$key     = npt_size_order_key( $display );
	if ( '' !== $key && isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}
	return $display;
}

/* -------------------------------------------------------------------------
 * سایز field on the product's General tab (optional override for the
 * PDF / online price-list label)
 * ---------------------------------------------------------------------- */
add_action( 'woocommerce_product_options_general_product_data', 'npt_size_label_field' );
function npt_size_label_field() {
	echo '<div class="options_group">';
	woocommerce_wp_text_input( array(
		'id'          => '_npt_size_label',
		'label'       => __( 'سایز (نمایش در لیست قیمت)', 'navid-price-table' ),
		'description' => __( 'دقیقاً همان چیزی که باید در PDF نمایش داده شود؛ مثال: ۲×۴. اگر خالی بماند، سایز به‌صورت خودکار از نام محصول استخراج و به قالب «تعداد×سایز» تبدیل می‌شود.', 'navid-price-table' ),
		'desc_tip'    => true,
	) );
	echo '</div>';
}

add_action( 'woocommerce_process_product_meta', 'npt_size_label_save' );
function npt_size_label_save( $post_id ) {
	// Same guard as the meter checkbox: without it, block-editor/REST saves
	// (which don't post the classic meta-box nonce) would be ignored.
	if ( ! isset( $_POST['woocommerce_meta_nonce'] ) ) {
		return;
	}
	$val = isset( $_POST['_npt_size_label'] ) ? sanitize_text_field( wp_unslash( $_POST['_npt_size_label'] ) ) : '';
	update_post_meta( $post_id, '_npt_size_label', $val );
}

/* -------------------------------------------------------------------------
 * سایز column in WooCommerce's built-in product CSV export/import, so the
 * price-list label can be bulk-edited in a spreadsheet and imported back.
 * ---------------------------------------------------------------------- */
add_filter( 'woocommerce_product_export_column_names', 'npt_csv_export_add_column' );
function npt_csv_export_add_column( $columns ) {
	$columns['size_label'] = __( 'سایز (نمایش در لیست قیمت)', 'navid-price-table' );
	return $columns;
}

add_filter( 'woocommerce_product_export_product_column_size_label', 'npt_csv_export_column_value', 10, 2 );
function npt_csv_export_column_value( $value, $product ) {
	return get_post_meta( $product->get_id(), '_npt_size_label', true );
}

add_filter( 'woocommerce_csv_product_import_mapping_options', 'npt_csv_import_add_option' );
function npt_csv_import_add_option( $options ) {
	$options['size_label'] = __( 'سایز (نمایش در لیست قیمت)', 'navid-price-table' );
	return $options;
}

add_filter( 'woocommerce_product_import_pre_insert_product_object', 'npt_csv_import_size_label', 10, 2 );
function npt_csv_import_size_label( $object, $data ) {
	// array_key_exists on purpose: an empty imported cell clears the label
	// so an export→edit→import round-trip behaves exactly like the sheet.
	if ( array_key_exists( 'size_label', $data ) ) {
		$object->update_meta_data( '_npt_size_label', sanitize_text_field( (string) $data['size_label'] ) );
	}
	return $object;
}

/**
 * Shared catalog snapshot: categories (in fixed brand order) with their published
 * products' name + price. Used by the PDF builder and the online view so
 * both outputs always show exactly the same data.
 */
function npt_catalog_data( $category_slug = '' ) {
	$cat_args = array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
	);
	if ( ! empty( $category_slug ) ) {
		$cat_args['slug'] = array( sanitize_title( $category_slug ) );
	}
	$categories = npt_sort_categories_by_brand( get_terms( $cat_args ) );

	$data = array();
	if ( is_wp_error( $categories ) || empty( $categories ) ) {
		return $data;
	}

	foreach ( $categories as $cat ) {
		$products = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'tax_query'      => array( // phpcs:ignore
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $cat->term_id,
				),
			),
		) );

		$rows = array();
		foreach ( $products as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}
			$rows[] = array(
				'name'  => $product->get_name(),
				'label' => npt_product_list_label( $product, $cat->name ),
				'price' => $product->get_price(),
				'meter' => npt_meter_is_meter_product( $product->get_id() ),
			);
		}

		// Print the rows in the original PSD's curated order; sizes the
		// curated list doesn't know sink to the bottom, name-sorted.
		$curated_pos = array_flip( array_keys( npt_curated_size_map( $cat->name ) ) );
		usort( $rows, function ( $a, $b ) use ( $curated_pos ) {
			$ka = npt_size_order_key( $a['label'] );
			$kb = npt_size_order_key( $b['label'] );
			$pa = isset( $curated_pos[ $ka ] ) ? $curated_pos[ $ka ] : PHP_INT_MAX;
			$pb = isset( $curated_pos[ $kb ] ) ? $curated_pos[ $kb ] : PHP_INT_MAX;
			if ( $pa !== $pb ) {
				return $pa <=> $pb;
			}
			return strcmp( $a['name'], $b['name'] );
		} );

		if ( ! empty( $rows ) ) {
			$data[] = array(
				'name' => $cat->name,
				'slug' => $cat->slug,
				'rows' => $rows,
			);
		}
	}
	return $data;
}

/**
 * Current Jalali month name / year / full date, for the PDF header title
 * ("مهر ماه ۱۴۰۵"). Self-contained, accurate conversion — no dependency on
 * external Persian-date plugins.
 */
function npt_jalali_now() {
	$ts = current_time( 'timestamp' );
	// Plain date(), NOT date_i18n(): on this Persian-locale site date_i18n()
	// already returns Jalali values, and feeding them through the converter
	// below subtracted 621 years a second time (۱۴۰۵ → ۰۷۸۴).
	$gy = (int) date( 'Y', $ts );
	$gm = (int) date( 'n', $ts );
	$gd = (int) date( 'j', $ts );

	list( $jy, $jm, $jd ) = npt_gregorian_to_jalali( $gy, $gm, $gd );

	$names = array( 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );

	return array(
		'month' => $names[ $jm - 1 ],
		'year'  => npt_to_persian_digits( (string) $jy ),
		// Day-first, like the printed sheet (۱۴۰۵/۰۷/۱۴ → ۱۴/۰۷/۱۴۰۵).
		'date'  => npt_to_persian_digits( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) ),
	);
}

/* ---------------------------------------------------------------------
 * Gregorian → Jalali conversion (port of the jalaali-js algorithm,
 * Behrang Noruzi Niya, MIT). The naive "33-year cycle" formula used
 * before drifted +1 day from 2025-03-21 and +2 days from 2026-03-21;
 * this break-table version matches the official Persian calendar.
 * ------------------------------------------------------------------- */

function npt_jdiv( $a, $b ) {
	return (int) ( $a / $b );
}

function npt_jmod( $a, $b ) {
	return $a - (int) ( $a / $b ) * $b;
}

/**
 * Jalali year metadata: leap flag (0-4) and Gregorian day-of-March of
 * Nowruz. Valid for Jalali years 1178–3177 AP.
 */
function npt_jal_cal( $jy ) {
	static $breaks = array( -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178 );

	$gy    = $jy + 621;
	$count = count( $breaks );
	if ( $jy < $breaks[0] || $jy >= $breaks[ $count - 1 ] ) {
		return null;
	}

	$leap_j = -14;
	$jp     = $breaks[0];
	$jump   = 0;

	for ( $i = 1; $i < $count; $i++ ) {
		$jm   = $breaks[ $i ];
		$jump = $jm - $jp;
		if ( $jy < $jm ) {
			break;
		}
		$leap_j += npt_jdiv( $jump, 33 ) * 8 + npt_jdiv( npt_jmod( $jump, 33 ), 4 );
		$jp = $jm;
	}

	$n = $jy - $jp;

	$leap_j += npt_jdiv( $n, 33 ) * 8 + npt_jdiv( npt_jmod( $n, 33 ) + 3, 4 );
	if ( 4 === npt_jmod( $jump, 33 ) && 4 === $jump - $n ) {
		$leap_j += 1;
	}

	$leap_g = npt_jdiv( $gy, 4 ) - npt_jdiv( ( npt_jdiv( $gy, 100 ) + 1 ) * 3, 4 ) - 150;
	$march  = 20 + $leap_j - $leap_g;

	$nn = $n;
	if ( $jump - $n < 6 ) {
		$nn = $n - $jump + npt_jdiv( $jump + 4, 33 ) * 33;
	}
	$leap = npt_jmod( npt_jmod( $nn + 1, 33 ) - 1, 4 );
	if ( -1 === $leap ) {
		$leap = 4;
	}

	return array(
		'leap'  => $leap,
		'gy'    => $gy,
		'march' => $march,
	);
}

function npt_jalali_g2d( $gy, $gm, $gd ) {
	$d = npt_jdiv( ( $gy + npt_jdiv( $gm - 8, 6 ) + 100100 ) * 1461, 4 )
		+ npt_jdiv( 153 * npt_jmod( $gm + 9, 12 ) + 2, 5 )
		+ $gd - 34840408;
	$d = $d - npt_jdiv( npt_jdiv( $gy + 100100 + npt_jdiv( $gm - 8, 6 ), 100 ) * 3, 4 ) + 752;
	return $d;
}

/**
 * Gregorian date → Jalali date array( $jy, $jm, $jd ).
 */
function npt_gregorian_to_jalali( $gy, $gm, $gd ) {
	$jy    = $gy - 621;
	$r     = npt_jal_cal( $jy );
	$jdn1f = npt_jalali_g2d( $r['gy'], 3, $r['march'] );
	$k     = npt_jalali_g2d( $gy, $gm, $gd ) - $jdn1f;

	if ( $k >= 0 ) {
		if ( $k <= 185 ) {
			return array( $jy, 1 + npt_jdiv( $k, 31 ), npt_jmod( $k, 31 ) + 1 );
		}
		$k -= 186;
	} else {
		$jy -= 1;
		$k += 179;
		if ( 1 === $r['leap'] ) {
			$k += 1;
		}
	}

	return array( $jy, 7 + npt_jdiv( $k, 30 ), npt_jmod( $k, 30 ) + 1 );
}

/**
 * The footer domain in the printed sheet's spaced style, e.g.
 * "W w w . S i m c a b l e n a v i d . I R", derived from the site host.
 */
function npt_spaced_domain() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$host = $host ? preg_replace( '/^www\./i', '', $host ) : 'SimCableNavid.ir';
	$base = 'www.' . $host;
	// Capitalize the first letter of every dot-segment, and the TLD fully.
	$base = preg_replace_callback(
		'/(^|\.)([a-z]+)$|(^|\.)([a-z])/',
		function ( $m ) {
			return isset( $m[2] ) && '' !== $m[2] ? $m[1] . strtoupper( $m[2] ) : $m[3] . strtoupper( $m[4] );
		},
		$base
	);
	$chars = preg_split( '//u', $base, -1, PREG_SPLIT_NO_EMPTY );
	return implode( ' ', $chars );
}

/**
 * Shared header/footer presentation for the PDF and the online view.
 * Every getter falls back to the printed-sheet default, so an untouched
 * settings page reproduces the original PSD layout exactly.
 */
function npt_header_title_text() {
	$title = trim( (string) get_option( 'npt_header_title', '' ) );
	return '' !== $title ? $title : __( 'لیست قیمت', 'navid-price-table' );
}

function npt_hex_to_rgb( $hex, $fallback ) {
	$hex = sanitize_hex_color( $hex );
	$hex = $hex ? ltrim( $hex, '#' ) : ltrim( $fallback, '#' );
	return array(
		(int) hexdec( substr( $hex, 0, 2 ) ),
		(int) hexdec( substr( $hex, 2, 2 ) ),
		(int) hexdec( substr( $hex, 4, 2 ) ),
	);
}

function npt_header_color_rgb() {
	return npt_hex_to_rgb( get_option( 'npt_header_color', '#2350b4' ), '#2350b4' );
}

function npt_accent_color_rgb() {
	return npt_hex_to_rgb( get_option( 'npt_accent_color', '#ed1c24' ), '#ed1c24' );
}

function npt_footer_domain_text() {
	$domain = trim( (string) get_option( 'npt_footer_domain', '' ) );
	return '' !== $domain ? $domain : npt_spaced_domain();
}

/**
 * Resolve a media-picker option to an attachment file path (PDF side);
 * empty string when unset so callers can apply their bundled fallback.
 */
function npt_media_image_path( $option, $fallback ) {
	$id = (int) get_option( $option, 0 );
	if ( $id ) {
		$path = get_attached_file( $id );
		if ( $path && file_exists( $path ) ) {
			return $path;
		}
	}
	return $fallback;
}

/**
 * Same resolution for the online view, which needs a URL, not a path.
 */
function npt_media_image_url( $option, $fallback_url ) {
	$id = (int) get_option( $option, 0 );
	if ( $id ) {
		$url = wp_get_attachment_image_url( $id, 'full' );
		if ( $url ) {
			return $url;
		}
	}
	return $fallback_url;
}

/**
 * Render the products table.
 *
 * Two parallel markups are output for the same data — a real <table> for
 * desktop, and a plain <div>-based row layout for mobile — toggled purely
 * with CSS (`npt-desktop-only` / `npt-mobile-only`). This is deliberate:
 * many WordPress themes carry generic responsive CSS that forces every
 * <table>/<tr>/<td> on the site into a stacked "card" layout, and that
 * rule can out-specificity or out-load-order a plugin stylesheet. Since
 * the mobile version here isn't a <table> at all, that theme rule simply
 * has nothing to match — it can't touch elements that aren't table tags.
 * Both markups share the same classes (npt-row, npt-price-display, …) so
 * the existing edit/save JS binds to whichever one is actually visible
 * without any extra JS.
 */
function npt_render_table( $category_slug = '' ) {
	$cache_key = npt_table_cache_key( $category_slug, npt_current_user_can_edit() );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$can_edit = npt_current_user_can_edit();

	// Fetch categories to render
	$cat_args = array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
	);
	if ( ! empty( $category_slug ) ) {
		$cat_args['slug'] = array( sanitize_title( $category_slug ) );
	}
	$categories = npt_sort_categories_by_brand( get_terms( $cat_args ) );

	ob_start();

	if ( is_wp_error( $categories ) || empty( $categories ) ) {
		echo '<p class="npt-empty">' . esc_html__( 'محصولی یافت نشد.', 'navid-price-table' ) . '</p>';
		$html = ob_get_clean();
		set_transient( $cache_key, $html, 6 * HOUR_IN_SECONDS );
		return $html;
	}

	$latest_timestamp = 0;
	$catalog_data     = array();

	foreach ( $categories as $cat ) {
		$products = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'tax_query'      => array( // phpcs:ignore
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $cat->term_id,
				),
			),
		) );

		if ( empty( $products ) ) {
			continue;
		}

		$cat_rows = array();
		foreach ( $products as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}
			// GMT keeps the timestamp a true Unix moment regardless of the
			// site's timezone; npt_format_date() converts it back for display.
			$modified = get_post_field( 'post_modified_gmt', $post->ID );
			$ts       = $modified ? strtotime( $modified . ' +00:00' ) : 0;
			if ( $ts > $latest_timestamp ) {
				$latest_timestamp = $ts;
			}

			// Mark whether this product type supports inline editing (simple products only)
			$cat_rows[] = array(
				'id'          => $product->get_id(),
				'name'        => $product->get_name(),
				'price'       => $product->get_price(),
				'modified'    => $modified,
				'is_editable' => $product->is_type( 'simple' ),
				'meter'       => npt_meter_is_meter_product( $product->get_id() ),
			);
		}

		if ( ! empty( $cat_rows ) ) {
			$catalog_data[] = array(
				'cat_name' => $cat->name,
				'cat_slug' => $cat->slug,
				'rows'     => $cat_rows,
			);
		}
	}

	if ( empty( $catalog_data ) ) {
		echo '<p class="npt-empty">' . esc_html__( 'محصولی یافت نشد.', 'navid-price-table' ) . '</p>';
		$html = ob_get_clean();
		set_transient( $cache_key, $html, 6 * HOUR_IN_SECONDS );
		return $html;
	}
	?>

	<?php if ( $latest_timestamp ) : ?>
		<div class="npt-catalog-meta">
			<span class="npt-update-badge">
				<?php esc_html_e( 'آخرین به‌روزرسانی قیمت‌ها:', 'navid-price-table' ); ?>
				<strong><?php echo esc_html( npt_to_persian_digits( npt_format_date( gmdate( 'Y-m-d H:i:s', $latest_timestamp ) ) ) ); ?></strong>
			</span>
		</div>
	<?php endif; ?>

	<div class="npt-catalog-grid">
		<?php foreach ( $catalog_data as $cat_group ) : ?>
			<div class="npt-cat-card" data-category="<?php echo esc_attr( $cat_group['cat_slug'] ); ?>">
				<div class="npt-cat-header">
					<h3 class="npt-cat-title"><?php echo esc_html( $cat_group['cat_name'] ); ?></h3>
				</div>
				<div class="npt-desktop-only">
					<table class="npt-table">
						<thead>
							<tr>
								<th class="npt-col-main"><?php esc_html_e( 'نام محصول / قیمت', 'navid-price-table' ); ?></th>
								<?php if ( $can_edit ) : ?>
									<th class="npt-col-edit"></th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $cat_group['rows'] as $row ) : ?>
								<tr class="npt-row" data-product-id="<?php echo esc_attr( $row['id'] ); ?>">
									<td class="npt-col-main">
										<div class="npt-main-inner">
											<span class="npt-col-name"><?php echo esc_html( $row['name'] ); ?></span>
											<span class="npt-col-price npt-inline-price"><span class="npt-price-display"><?php echo esc_html( npt_format_price( $row['price'] ) ); ?></span><?php echo npt_row_meter_badge( $row ); // phpcs:ignore ?></span>
											<?php if ( $can_edit ) : ?>
												<input type="number" step="1" min="0" class="npt-price-input" style="display:none;" value="<?php echo esc_attr( npt_display_price_value( $row['price'] ) ); ?>">
											<?php endif; ?>
										</div>
									</td>
									<?php if ( $can_edit ) : ?>
										<td class="npt-col-edit">
											<div class="npt-edit-actions"><?php echo npt_edit_controls_html( $row ); // phpcs:ignore ?></div>
										</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="npt-mobile-rows npt-mobile-only">
					<?php foreach ( $cat_group['rows'] as $row ) : ?>
						<div class="npt-row npt-mobile-row" data-product-id="<?php echo esc_attr( $row['id'] ); ?>">
							<div class="npt-mobile-row-main">
								<span class="npt-col-name"><?php echo esc_html( $row['name'] ); ?></span>
								<span class="npt-col-price npt-inline-price"><span class="npt-price-display"><?php echo esc_html( npt_format_price( $row['price'], true ) ); ?></span><?php echo npt_row_meter_badge( $row ); // phpcs:ignore ?></span>
								<?php if ( $can_edit ) : ?>
									<input type="number" step="1" min="0" class="npt-price-input" style="display:none;" value="<?php echo esc_attr( npt_display_price_value( $row['price'] ) ); ?>">
								<?php endif; ?>
							</div>
							<?php if ( $can_edit ) : ?>
								<div class="npt-col-edit">
									<div class="npt-edit-actions"><?php echo npt_edit_controls_html( $row ); // phpcs:ignore ?></div>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="npt-catalog-footer">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="npt-brand-domain" target="_blank" rel="noopener">
			<?php
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			echo esc_html( $host ? 'www.' . preg_replace( '/^www\./i', '', $host ) : 'SimCableNavid.IR' );
			?>
		</a>
	</div>
	<?php
	$html = ob_get_clean();
	set_transient( $cache_key, $html, 6 * HOUR_IN_SECONDS );
	return $html;
}

/**
 * The small "(متر)" pill shown next to the price of meter-sold products.
 * Renders nothing when the badge is turned off in settings or the product
 * isn't flagged as meter-sold.
 */
function npt_meter_badge_enabled() {
	// Checkbox saves via rest_sanitize_boolean store true/false (not 'yes'),
	// so a strict string compare would silently disable the badge after the
	// first settings save.
	return (bool) get_option( 'npt_meter_badge_enable', true );
}

function npt_meter_sentence_text() {
	$sentence = trim( (string) get_option( 'npt_meter_sentence', '' ) );
	return '' !== $sentence ? $sentence : __( 'محصولاتی که نشان (متر) دارند به‌صورت متری به فروش می‌رسند.', 'navid-price-table' );
}

function npt_row_meter_badge( $row ) {
	if ( empty( $row['meter'] ) || ! npt_meter_badge_enabled() ) {
		return '';
	}
	return ' <span class="npt-meter-badge">' . esc_html__( 'متر', 'navid-price-table' ) . '</span>';
}

/**
 * The edit controls (pencil / save / cancel buttons) shared by the desktop
 * <td> markup and the mobile <div> markup. For products that don't support
 * inline price editing (non-simple types) a link to the WP admin edit
 * screen is shown instead; users lacking edit rights see a dash.
 */
function npt_edit_controls_html( $row ) {
	ob_start();
	if ( ! empty( $row['is_editable'] ) ) : ?>
		<button type="button" class="npt-edit-btn" title="<?php esc_attr_e( 'ویرایش قیمت', 'navid-price-table' ); ?>" aria-label="<?php esc_attr_e( 'ویرایش قیمت', 'navid-price-table' ); ?>">
			<?php echo npt_pencil_icon(); // phpcs:ignore ?>
		</button>
		<button type="button" class="npt-save-btn" style="display:none;"><?php esc_html_e( 'ذخیره', 'navid-price-table' ); ?></button>
		<button type="button" class="npt-cancel-btn" style="display:none;"><?php esc_html_e( 'انصراف', 'navid-price-table' ); ?></button>
	<?php elseif ( current_user_can( 'edit_post', $row['id'] ) ) : ?>
		<a class="npt-edit-link" href="<?php echo esc_url( admin_url( 'post.php?post=' . $row['id'] . '&action=edit' ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'ویرایش محصول در پیشخوان', 'navid-price-table' ); ?>">
			<?php echo npt_pencil_icon(); // phpcs:ignore ?>
		</a>
	<?php else : ?>
		<span class="npt-edit-disabled" title="<?php esc_attr_e( 'ویرایش مستقیم برای این نوع محصول پشتیبانی نمی‌شود.', 'navid-price-table' ); ?>">&mdash;</span>
	<?php endif;
	return ob_get_clean();
}

/**
 * Build a JSON-LD (schema.org) ItemList of the products currently shown,
 * so search engines can better understand product names/prices. Rendered
 * once on the initial page load only (not re-rendered on AJAX filtering —
 * crawlers don't run the filter, so this is not a loss for SEO purposes).
 */
function npt_render_schema_json_ld( $category_slug = '' ) {
	if ( ! get_option( 'npt_enable_schema', true ) ) {
		return '';
	}

	$query = npt_query_products( $category_slug );
	if ( ! $query->have_posts() ) {
		return '';
	}

	$currency = npt_currency_unit();
	$items    = array();
	$position = 1;

	foreach ( $query->posts as $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			continue;
		}
		$price = $product->get_price();

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position++,
			'item'     => array(
				'@type'  => 'Product',
				'name'   => $product->get_name(),
				'url'    => get_permalink( $product->get_id() ),
				'offers' => array(
					'@type'         => 'Offer',
					'price'         => $price !== '' ? (string) round( (float) $price * npt_currency_factor() ) : '0',
					'priceCurrency' => $currency,
					'url'           => get_permalink( $product->get_id() ),
					'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
				),
			),
		);
	}
	wp_reset_postdata();

	if ( empty( $items ) ) {
		return '';
	}

	$schema = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'ItemList',
		'itemListElement' => $items,
	);

	return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
}

/**
 * Format a GMT MySQL datetime for display as Jalali in the site's timezone.
 * Callers pass post_modified_gmt values (see npt_render_table and the AJAX
 * price-update response).
 */
/** Date cell HTML: the text is always left-to-right, even on RTL pages. */
function npt_date_html( $mysql_datetime ) {
	return '<span dir="ltr" style="direction:ltr;unicode-bidi:isolate-override;display:inline-block">' . esc_html( npt_format_date( $mysql_datetime ) ) . '</span>';
}

/** Site visit counter: one daily counter and a running total, both autoload-off. */
function npt_count_visit() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() || is_embed() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}
	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
	if ( '' === $agent || preg_match( '/bot|crawl|spider|slurp|preview|monitor/', $agent ) ) {
		return;
	}
	$day = 'npt_visits_' . wp_date( 'Ymd' );
	update_option( $day, (int) get_option( $day, 0 ) + 1, false );
	update_option( 'npt_visits_total', (int) get_option( 'npt_visits_total', 0 ) + 1, false );
}
add_action( 'template_redirect', 'npt_count_visit', 1 );

function npt_format_date( $mysql_datetime ) {
	if ( empty( $mysql_datetime ) || '0000-00-00 00:00:00' === $mysql_datetime ) {
		return '—';
	}

	$local = get_date_from_gmt( $mysql_datetime, 'Y-m-d H:i:s' );
	$gy    = (int) substr( $local, 0, 4 );
	$gm    = (int) substr( $local, 5, 2 );
	$gd    = (int) substr( $local, 8, 2 );
	$time  = substr( $local, 11, 5 );

	list( $jy, $jm, $jd ) = npt_gregorian_to_jalali( $gy, $gm, $gd );

	// Day-first to match the printed sheet.
	// LRI…PDI isolate: keeps «day/month/year hour:minute» left-to-right inside RTL pages.
	return "\u{2066}" . npt_to_persian_digits( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) ) . ' ' . npt_to_persian_digits( $time ) . "\u{2069}";
}

function npt_pdf_download_url( $category_slug = '' ) {
	return add_query_arg( array(
		'action'   => 'npt_download_pdf',
		'category' => sanitize_title( $category_slug ),
	), admin_url( 'admin-ajax.php' ) );
}

function npt_online_view_url( $category_slug = '' ) {
	return add_query_arg( array(
		'action'   => 'npt_online_view',
		'category' => sanitize_title( $category_slug ),
	), admin_url( 'admin-ajax.php' ) );
}

function npt_pdf_icon() {
	return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="vertical-align:-2px;margin-inline-end:4px;">
		<path d="M12 3v12m0 0 4-4m-4 4-4-4M5 17v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
	</svg>';
}

function npt_pencil_icon() {
	return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
		<path d="M16.474 5.408a2.5 2.5 0 0 1 3.535 3.536L7.5 21.453 3 22l.547-4.5L16.474 5.408Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
	</svg>';
}

function npt_eye_icon() {
	return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="vertical-align:-2px;margin-inline-end:4px;">
		<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
		<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/>
	</svg>';
}

/* ---------------------------------------------------------------------
 * AJAX: category filter (re-renders the table server-side)
 * ------------------------------------------------------------------- */
add_action( 'wp_ajax_npt_filter', 'npt_ajax_filter' );
add_action( 'wp_ajax_nopriv_npt_filter', 'npt_ajax_filter' );

/**
 * Send a JSON reply, close the connection to the browser, then run $after.
 * Falls back to running $after before the reply on hosts without a
 * finish-request function, so the work is never skipped.
 */
function npt_finish_json_then( array $payload, callable $after ) {
	ignore_user_abort( true );
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	status_header( 200 );
	header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	$body = wp_json_encode( $payload );
	header( 'Content-Length: ' . strlen( $body ) );
	header( 'Connection: close' );
	echo $body; // phpcs:ignore
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} elseif ( function_exists( 'litespeed_finish_request' ) ) {
		litespeed_finish_request();
	} else {
		flush();
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 60 ); // phpcs:ignore
	}
	try {
		$after();
	} catch ( \Throwable $e ) { // phpcs:ignore
		error_log( 'npt notify: ' . $e->getMessage() ); // phpcs:ignore
	}
	exit;
}

/**
 * Unknown slugs collapse to '' (all products) so arbitrary public input can't
 * create an unlimited number of cached table/view transients.
 */
function npt_known_category( $slug ) {
	if ( '' === $slug ) {
		return '';
	}
	return term_exists( $slug, 'product_cat' ) ? $slug : '';
}

function npt_ajax_filter() {
	// No nonce here on purpose: this endpoint only returns the public price
	// table, and full-page caches would bake a nonce into HTML that outlives
	// the nonce's 24h window, breaking the filter for cached visitors. The
	// data returned is identical to what the public page already renders.
	if ( ! function_exists( 'wc_get_product' ) ) {
		wp_send_json_error( array( 'message' => __( 'ووکامرس فعال نیست.', 'navid-price-table' ) ) );
	}

	$category = isset( $_POST['category'] ) ? sanitize_title( wp_unslash( $_POST['category'] ) ) : '';
	$category = npt_known_category( $category );

	if ( ! npt_pdf_rate_limit_ok( 'filter' ) ) {
		wp_send_json_error( array( 'message' => __( 'تعداد درخواست‌ها بیش از حد مجاز است.', 'navid-price-table' ) ), 429 );
	}

	wp_send_json_success( array(
		'html' => npt_render_table( $category ),
	) );
}

/* ---------------------------------------------------------------------
 * AJAX: inline price update (permission-checked server-side)
 * ------------------------------------------------------------------- */
add_action( 'wp_ajax_npt_update_price', 'npt_ajax_update_price' );

function npt_ajax_update_price() {
	check_ajax_referer( 'npt_nonce', 'nonce' );

	if ( ! function_exists( 'wc_get_product' ) ) {
		wp_send_json_error( array( 'message' => __( 'ووکامرس فعال نیست.', 'navid-price-table' ) ) );
	}

	if ( ! npt_current_user_can_edit() ) {
		wp_send_json_error( array( 'message' => __( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) ), 403 );
	}

	$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$new_price  = isset( $_POST['price'] ) ? wc_format_decimal( wp_unslash( $_POST['price'] ) ) : '';

	if ( ! $product_id || '' === $new_price || ! is_numeric( $new_price ) || $new_price < 0 ) {
		wp_send_json_error( array( 'message' => __( 'قیمت وارد شده معتبر نیست.', 'navid-price-table' ) ) );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		wp_send_json_error( array( 'message' => __( 'محصول یافت نشد.', 'navid-price-table' ) ) );
	}

	// Inline editing is only supported for simple products — the UI hides the
	// pencil otherwise, but the endpoint must enforce it too.
	if ( ! $product->is_type( 'simple' ) ) {
		wp_send_json_error( array( 'message' => __( 'ویرایش مستقیم برای این نوع محصول پشتیبانی نمی‌شود.', 'navid-price-table' ) ) );
	}

	$product->set_regular_price( $new_price );

	// Keep sale price logic sane: if a sale price exists and is now >= the
	// new regular price, it no longer makes sense as a "sale" — clear it.
	$sale_price = $product->get_sale_price();
	if ( $sale_price && (float) $sale_price >= (float) $new_price ) {
		$product->set_sale_price( '' );
		$product->set_date_on_sale_from( '' );
		$product->set_date_on_sale_to( '' );
	}

	npt_sync_active_price( $product );
	$product->save(); // This also bumps post_modified, which drives the "last updated" column.
	npt_bump_table_cache_version();

	$modified = get_post_field( 'post_modified_gmt', $product_id );

	// Persian digits, no currency word — the client appends "تومان" itself
	// only for mobile rows (desktop already has it in the column header).
	wp_send_json_success( array(
		'price_display' => npt_format_price( $product->get_price() ),
		'date_display'  => npt_to_persian_digits( npt_format_date( $modified ) ),
	) );
}

/**
 * Recompute which price (regular or sale) is currently "active" for a
 * simple product, honoring scheduled sale start/end dates, and write it
 * to the product's working price so it's correct the moment we save —
 * without waiting for WooCommerce's hourly sale-schedule cron to run.
 */
function npt_sync_active_price( $product ) {
	$regular = $product->get_regular_price();
	$sale    = $product->get_sale_price();

	if ( '' === $sale || ! is_numeric( $sale ) || (float) $sale >= (float) $regular ) {
		$product->set_price( $regular );
		return;
	}

	$from = $product->get_date_on_sale_from();
	$to   = $product->get_date_on_sale_to();
	$now  = time();

	$after_from = ! $from || $now >= $from->getTimestamp();
	$before_to  = ! $to || $now <= $to->getTimestamp();

	$product->set_price( ( $after_from && $before_to ) ? $sale : $regular );
}

/* ---------------------------------------------------------------------
 * Bulk percentage adjustment: multiplies every published product's
 * regular (and sale) price — variations included — by (1 + pct/100),
 * permanently, from the settings page.
 * ------------------------------------------------------------------- */
add_action( 'admin_post_npt_apply_percent', 'npt_handle_apply_percent' );
add_action( 'admin_post_npt_undo_percent', 'npt_handle_undo_percent' );

const NPT_PCT_MIN = -50;
const NPT_PCT_MAX = 100;

/**
 * Multiply every published product/variation price by (1 + pct/100).
 * Shared by the wp-admin form and the employer panel. Returns the number of
 * changed products, or WP_Error('range'|'busy').
 */
/**
 * Change the price of one product (and its variations) by a percentage.
 * Same rounding and active-price sync as the bulk change; the old prices are
 * not stored, so this single change cannot be undone with the bulk «برگرداندن».
 */
function npt_price_percent_item( $product_id, $pct ) {
	$pct = (float) $pct;
	if ( ! is_finite( $pct ) || 0.0 === $pct || $pct < NPT_PCT_MIN || $pct > NPT_PCT_MAX ) {
		return new WP_Error( 'range', sprintf( 'درصد باید غیرصفر و بین %d و %d باشد.', NPT_PCT_MIN, NPT_PCT_MAX ) );
	}
	$product = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
	if ( ! $product ) {
		return new WP_Error( 'missing', 'کالای انتخاب‌شده پیدا نشد.' );
	}

	$factor  = 1 + ( $pct / 100 );
	$targets = array( $product );
	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation ) {
				$targets[] = $variation;
			}
		}
	}

	$changed = 0;
	foreach ( $targets as $target ) {
		$target_changed = false;
		foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
			$value = 'regular_price' === $field ? $target->get_regular_price() : $target->get_sale_price();
			if ( '' === $value || ! is_numeric( $value ) ) {
				continue;
			}
			$target->{'set_' . $field}( (string) max( 0, round( (float) $value * $factor ) ) );
			$target_changed = true;
		}
		if ( ! $target_changed ) {
			continue;
		}
		if ( $target->is_type( 'simple' ) ) {
			npt_sync_active_price( $target );
		}
		$target->save();
		$changed++;
	}

	if ( $changed && $product->is_type( 'variable' ) ) {
		WC_Product_Variable::sync( $product_id );
	}
	return array(
		'name'    => $product->get_name(),
		'changed' => $changed,
	);
}

function npt_price_percent_apply( $pct ) {
	$pct = (float) $pct;
	if ( ! is_finite( $pct ) || $pct < NPT_PCT_MIN || $pct > NPT_PCT_MAX ) {
		return new WP_Error( 'range', sprintf( 'درصد باید بین %d و %d باشد.', NPT_PCT_MIN, NPT_PCT_MAX ) );
	}
	if ( 0.0 === $pct ) {
		return 0;
	}
	// Double-click / refresh guard: a second run while one is in progress (or
	// just finished) would multiply the prices twice.
	$pending = get_option( 'npt_price_backup' );
	if ( is_array( $pending ) && ! empty( $pending['prices'] ) ) {
		return new WP_Error( 'pending', 'تغییر قبلی (' . npt_to_persian_digits( (string) $pending['pct'] ) . '٪ در ' . wp_date( 'Y-m-d H:i', (int) $pending['time'] ) . ') هنوز برنگشته است. اول آن را برگردانید، بعد تغییر جدید را بزنید.' );
	}
	if ( get_transient( 'npt_pct_lock' ) ) {
		return new WP_Error( 'busy', 'یک تعدیل قیمت تازه اجرا شده است؛ چند دقیقه صبر کنید (از اعمال دوباره‌ی ناخواسته جلوگیری شد).' );
	}
	set_transient( 'npt_pct_lock', 1, 5 * MINUTE_IN_SECONDS );
	update_option( 'npt_adjust_percent', $pct );

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore
	}

	$factor = 1 + ( $pct / 100 );
	$ids    = get_posts( array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	// Old prices of every touched product/variation, so the run can be undone.
	$backup = array();
	$after  = array();
	$count  = 0;
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}

		$targets = array( $product );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation ) {
					$targets[] = $variation;
				}
			}
		}

		$product_changed = false;
		foreach ( $targets as $target ) {
			$target_changed = false;
			$old            = array(
				'regular_price' => $target->get_regular_price(),
				'sale_price'    => $target->get_sale_price(),
			);
			foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
				$value = $old[ $field ];
				if ( '' === $value || ! is_numeric( $value ) ) {
					continue;
				}
				$target->{'set_' . $field}( (string) max( 0, round( (float) $value * $factor ) ) );
				$target_changed = true;
			}
			if ( ! $target_changed ) {
				continue;
			}
			if ( $target->is_type( 'simple' ) ) {
				npt_sync_active_price( $target );
			}
			$backup[ $target->get_id() ] = $old;
			$target->save();
			$after[ $target->get_id() ] = array(
				'regular_price' => (string) $target->get_regular_price(),
				'sale_price'    => (string) $target->get_sale_price(),
			);
			$product_changed = true;
		}

		if ( $product_changed ) {
			if ( $product->is_type( 'variable' ) ) {
				WC_Product_Variable::sync( $id );
			}
			$count++;
		}
	}

	update_option( 'npt_price_backup', array(
		'time'   => time(),
		'pct'    => $pct,
		'prices' => $backup,
		'after'  => $after,
	), false );
	delete_transient( 'npt_pct_lock' );
	npt_bump_table_cache_version();
	return $count;
}

/** Restore the prices saved before the last bulk run. Returns count or WP_Error('nobackup'). */
function npt_price_percent_undo() {
	$backup = get_option( 'npt_price_backup' );
	if ( ! is_array( $backup ) || empty( $backup['prices'] ) ) {
		return new WP_Error( 'nobackup', 'نسخه‌ی پشتیبانی برای برگرداندن وجود ندارد.' );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore
	}

	$parents = array();
	$count   = 0;
	$skipped = 0;
	foreach ( $backup['prices'] as $pid => $old ) {
		$product = wc_get_product( (int) $pid );
		if ( ! $product ) {
			continue;
		}
		$now = isset( $backup['after'][ $pid ] ) ? $backup['after'][ $pid ] : null;
		if ( null !== $now && ( (string) $product->get_regular_price() !== $now['regular_price'] || (string) $product->get_sale_price() !== $now['sale_price'] ) ) {
			$skipped++;
			continue;
		}
		$product->set_regular_price( $old['regular_price'] );
		$product->set_sale_price( $old['sale_price'] );
		if ( $product->is_type( 'simple' ) ) {
			npt_sync_active_price( $product );
		}
		$product->save();
		if ( $product->get_parent_id() ) {
			$parents[ $product->get_parent_id() ] = true;
		}
		$count++;
	}
	foreach ( array_keys( $parents ) as $parent_id ) {
		WC_Product_Variable::sync( $parent_id );
	}

	set_transient( 'npt_undo_skipped', $skipped, MINUTE_IN_SECONDS );
	delete_option( 'npt_price_backup' );
	update_option( 'npt_adjust_percent', 0 );
	delete_transient( 'npt_pct_lock' );
	npt_bump_table_cache_version();
	return $count;
}

/** A few example products with old → new price, for the preview before applying. */
function npt_price_percent_preview( $pct ) {
	$pct = (float) $pct;
	if ( ! is_finite( $pct ) || $pct < NPT_PCT_MIN || $pct > NPT_PCT_MAX ) {
		return new WP_Error( 'range', sprintf( 'درصد باید بین %d و %d باشد.', NPT_PCT_MIN, NPT_PCT_MAX ) );
	}
	$counts = wp_count_posts( 'product' );
	$total  = isset( $counts->publish ) ? (int) $counts->publish : 0;
	$ids    = get_posts( array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 3,
		'orderby'        => 'rand',
		'fields'         => 'ids',
	) );
	$sample = array();
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		$price   = $product ? $product->get_price() : '';
		if ( '' === $price || ! is_numeric( $price ) ) {
			continue;
		}
		$sample[] = array(
			'name' => $product->get_name(),
			'old'  => npt_format_price( $price, true ),
			'new'  => npt_format_price( max( 0, round( (float) $price * ( 1 + $pct / 100 ) ) ), true ),
		);
	}
	return array( 'total' => $total, 'sample' => $sample );
}

function npt_handle_apply_percent() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
	}
	check_admin_referer( 'npt_apply_percent', 'npt_apply_nonce' );

	$back   = admin_url( 'admin.php?page=navid-settings&tab=price' );
	$pct    = isset( $_POST['npt_adjust_percent'] ) ? (float) wp_unslash( $_POST['npt_adjust_percent'] ) : 0.0;
	$result = npt_price_percent_apply( $pct );
	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( add_query_arg( 'npt_pct_err', $result->get_error_code(), $back ) );
		exit;
	}
	wp_safe_redirect( add_query_arg( 'npt_applied', $result, $back ) );
	exit;
}

/** Restore the prices saved before the last bulk percentage run. */
function npt_handle_undo_percent() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
	}
	check_admin_referer( 'npt_undo_percent', 'npt_undo_nonce' );

	$back   = admin_url( 'admin.php?page=navid-settings&tab=price' );
	$result = npt_price_percent_undo();
	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( add_query_arg( 'npt_pct_err', $result->get_error_code(), $back ) );
		exit;
	}
	wp_safe_redirect( add_query_arg( 'npt_undone', $result, $back ) );
	exit;
}

/* ---------------------------------------------------------------------
 * Online view: a public, self-contained HTML page that mirrors the
 * printed sheet layout (branded header, four-column category grid,
 * spaced-domain footer). Cached per category like the table.
 * ------------------------------------------------------------------- */

/**
 * Online-view row label. The PDF and the site table always use the PSD
 * format «تعداد×سایز». The online view is an RTL document, so the browser's
 * bidi algorithm mirrors a label made only of digits and symbols and would
 * show «سایز×تعداد» — the opposite of the PDF. Swapping the two halves of
 * such labels puts the count back on the left, matching the PDF exactly.
 * Labels that contain Persian text (e.g. «۱×۲/۵ (متر)») resolve identically
 * in both engines and are never swapped. The legacy «سایز×تعداد» option
 * keeps the raw label, which the browser already renders size-first.
 */
function npt_online_view_label( $label ) {
	if ( 'psd' !== get_option( 'npt_online_size_format', 'psd' ) ) {
		return $label;
	}
	if ( ! preg_match( '/^[\p{Nd}×+\/\.\s\-]+$/u', $label ) ) {
		return $label;
	}
	$parts = explode( '×', $label, 2 );
	if ( 2 !== count( $parts ) ) {
		return $label;
	}
	return trim( $parts[1] ) . '×' . trim( $parts[0] );
}

add_action( 'wp_ajax_npt_online_view', 'npt_handle_online_view' );
add_action( 'wp_ajax_nopriv_npt_online_view', 'npt_handle_online_view' );

function npt_handle_online_view() {
	$category = isset( $_GET['category'] ) ? npt_known_category( sanitize_title( wp_unslash( $_GET['category'] ) ) ) : '';

	if ( ! npt_pdf_rate_limit_ok( 'view' ) ) {
		status_header( 429 );
		echo esc_html__( 'تعداد درخواست‌ها بیش از حد مجاز است؛ لطفاً چند دقیقه بعد دوباره تلاش کنید.', 'navid-price-table' ); // phpcs:ignore
		exit;
	}

	// NPT_VERSION is part of the key so a plugin update never serves HTML
	// cached by a previous version (same reasoning as the table cache).
	$key      = 'npt_view_' . md5( $category . '|' . npt_table_cache_version() . '|' . NPT_VERSION );
	$html     = get_transient( $key );
	if ( false === $html ) {
		$html = npt_render_online_view( $category );
		set_transient( $key, $html, 6 * HOUR_IN_SECONDS );
	}

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	echo $html; // phpcs:ignore
	exit;
}

function npt_render_online_view( $category_slug = '' ) {
	$catalog = npt_catalog_data( $category_slug );
	$j       = npt_jalali_now();
	$imgs    = NPT_URL . 'assets/images/';
	$hcss     = vsprintf( '#%02x%02x%02x', npt_header_color_rgb() );
	$acss     = vsprintf( '#%02x%02x%02x', npt_accent_color_rgb() );
	$telegram = trim( (string) get_option( 'npt_telegram', '' ) );
	$insta    = trim( (string) get_option( 'npt_instagram', '' ) );

	ob_start();
	?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( npt_header_title_text() . ' ' . get_bloginfo( 'name' ) . ' - ' . $j['month'] . ' ' . __( 'ماه', 'navid-price-table' ) . ' ' . $j['year'] ); ?></title>
<style>
	* { box-sizing: border-box; }
	/* The bundled fonts keep the sheet identical to the PDF instead of
	   falling back to Tahoma on systems without Vazirmatn installed. */
	@font-face { font-family: Vazirmatn; src: url('<?php echo esc_url( NPT_URL ); ?>vendor/fonts/Vazirmatn-Regular.ttf') format('truetype'); font-weight: 400; font-style: normal; font-display: swap; }
	@font-face { font-family: Vazirmatn; src: url('<?php echo esc_url( NPT_URL ); ?>vendor/fonts/Vazirmatn-Bold.ttf') format('truetype'); font-weight: 700; font-style: normal; font-display: swap; }
	body { margin: 0 auto; max-width: 1680px; padding: 12px 14px 30px; background: #f7f9fa; color: #222; font-family: Vazirmatn, Tahoma, Arial, sans-serif; }
	.nptv-print { position: fixed; bottom: 14px; left: 14px; z-index: 9; padding: 8px 14px; border: 1px solid #0f4c5c; border-radius: 8px; background: #0f4c5c; color: #fff; font: inherit; font-size: 13px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
	.nptv-header { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; background: #fff; border: 1px solid #e6e9ec; border-radius: 10px; padding: 12px 16px; margin-bottom: 14px; }
	.nptv-title h1 { margin: 0; font-size: 26px; color: <?php echo esc_html( $hcss ); ?>; }
	.nptv-month { color: <?php echo esc_html( $acss ); ?>; font-weight: 700; font-size: 16px; margin-top: 2px; }
	.nptv-note { color: #8a8a8a; font-size: 11px; margin-top: 6px; }
	.nptv-logo { height: 68px; max-width: 220px; object-fit: contain; }
	.nptv-marks { display: flex; gap: 12px; align-items: center; }
	.nptv-marks img { height: 46px; max-width: 90px; object-fit: contain; }
	.nptv-grid { column-count: 5; column-gap: 10px; }
	.nptv-cat { break-inside: avoid; background: #fff; border: 1px solid #000; margin: 0 0 10px; }
	.nptv-band { margin: 0; padding: 5px 4px; background: #000; color: #fff; text-align: center; font-size: 13px; font-weight: 800; }
	.nptv-row { display: flex; border-top: 1px solid #000; }
	.nptv-band + .nptv-row { border-top: none; }
	.nptv-row:nth-of-type(odd) { background: #d9d9d9; }
	.nptv-name { flex: 0 0 38%; padding: 4px; text-align: center; font-size: 12px; font-weight: 700; border-left: 1px solid #000; }
	.nptv-price { flex: 1; padding: 4px; text-align: center; font-size: 12px; font-weight: 700; }
	.nptv-meter-badge, .npt-meter-badge { display: inline-block; margin-inline-start: 4px; padding: 0 7px; border-radius: 999px; background: #28a745; color: #fff; font-size: 10px; font-weight: 700; line-height: 1.5; vertical-align: middle; white-space: nowrap; }
	.nptv-empty { text-align: center; padding: 50px 0; font-size: 16px; }
	.nptv-footer { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 18px; color: #152896; font-weight: 800; font-size: 20px; letter-spacing: 2px; }
	.nptv-footer img { width: 36px; max-height: 36px; object-fit: contain; }
	.nptv-soc { display: inline-flex; }
	.nptv-soc img { width: 30px; max-height: 30px; object-fit: contain; }
	.nptv-contact { text-align: center; color: #555; font-size: 14px; font-weight: 400; letter-spacing: 0; margin-top: 8px; }
	@media (min-width: 1800px) { .nptv-grid { column-count: 6; } }
	@media (max-width: 1400px) { .nptv-grid { column-count: 4; } }
	@media (max-width: 1100px) { .nptv-grid { column-count: 3; } }
	@media (max-width: 800px)  { .nptv-grid { column-count: 2; } .nptv-logo { height: 52px; } }
	@media (max-width: 520px)  { .nptv-grid { column-count: 1; } }
	@media print { .nptv-print { display: none; } body { background: #fff; padding: 0; max-width: none; } .nptv-header { border: none; } .nptv-grid { column-count: 4; } }
</style>
</head>
<body>
<button type="button" class="nptv-print" onclick="window.print()"><?php esc_html_e( 'چاپ / ذخیره PDF', 'navid-price-table' ); ?></button>
<header class="nptv-header">
	<div class="nptv-marks">
		<img src="<?php echo esc_url( npt_media_image_url( 'npt_mark1_id', $imgs . 'mark-1.png' ) ); ?>" alt="">
		<img src="<?php echo esc_url( npt_media_image_url( 'npt_mark2_id', $imgs . 'mark-2.png' ) ); ?>" alt="">
		<img src="<?php echo esc_url( npt_media_image_url( 'npt_mark3_id', $imgs . 'mark-3.png' ) ); ?>" alt="">
	</div>
	<img class="nptv-logo" src="<?php echo esc_url( npt_media_image_url( 'npt_logo_id', $imgs . 'logo-navid.png' ) ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
	<div class="nptv-title">
		<h1><?php echo esc_html( npt_header_title_text() ); ?></h1>
		<?php if ( get_option( 'npt_header_show_month', true ) ) : ?>
		<div class="nptv-month"><?php echo esc_html( $j['month'] . ' ' . __( 'ماه', 'navid-price-table' ) . ' ' . $j['year'] ); ?></div>
		<?php endif; ?>
		<div class="nptv-note"><?php echo esc_html( npt_unit_sentence() ); ?><?php if ( get_option( 'npt_header_show_date', false ) ) : ?> | <?php esc_html_e( 'تاریخ:', 'navid-price-table' ); ?> <?php echo esc_html( $j['date'] ); ?><?php endif; ?><?php if ( npt_meter_badge_enabled() ) : ?> | <?php echo esc_html( npt_meter_sentence_text() ); ?><?php endif; ?></div>
	</div>
</header>
	<?php if ( empty( $catalog ) ) : ?>
		<p class="nptv-empty"><?php esc_html_e( 'محصولی یافت نشد.', 'navid-price-table' ); ?></p>
	<?php else : ?>
<main class="nptv-grid">
		<?php
		foreach ( $catalog as $group ) :
			?>
		<section class="nptv-cat">
			<h2 class="nptv-band"><?php echo esc_html( $group['name'] ); ?></h2>
			<?php foreach ( $group['rows'] as $row ) : ?>
			<div class="nptv-row"><span class="nptv-name"><?php echo esc_html( npt_online_view_label( $row['label'] ) ); ?></span><span class="nptv-price"><?php echo esc_html( npt_format_price( $row['price'] ) ); ?><?php echo npt_row_meter_badge( $row ); // phpcs:ignore ?></span></div>
			<?php endforeach; ?>
		</section>
		<?php endforeach; ?>
</main>
	<?php endif; ?>
<footer class="nptv-footer">
	<?php
	$insta_icon  = npt_media_image_url( 'npt_instagram_icon_id', '' );
	$telegram_ik = npt_media_image_url( 'npt_telegram_icon_id', '' );
	?>
	<?php if ( $insta || $insta_icon ) : ?>
	<?php if ( $insta ) : ?><a class="nptv-soc" href="https://www.instagram.com/<?php echo esc_attr( ltrim( $insta, '@' ) ); ?>/" target="_blank" rel="noopener" aria-label="Instagram"><?php else : ?><span class="nptv-soc"><?php endif; ?>
		<?php if ( $insta_icon ) : ?>
			<img src="<?php echo esc_url( $insta_icon ); ?>" alt="Instagram">
		<?php else : ?>
			<svg viewBox="0 0 24 24" width="30" height="30" role="img"><rect x="2.5" y="2.5" width="19" height="19" rx="5.5" fill="none" stroke="#C13584" stroke-width="2"/><circle cx="12" cy="12" r="4.5" fill="none" stroke="#C13584" stroke-width="2"/><circle cx="17.4" cy="6.6" r="1.4" fill="#C13584"/></svg>
		<?php endif; ?>
	<?php echo $insta ? '</a>' : '</span>'; ?>
	<?php endif; ?>
	<?php if ( $telegram || $telegram_ik ) : ?>
	<?php if ( $telegram ) : ?><a class="nptv-soc" href="https://t.me/<?php echo esc_attr( ltrim( $telegram, '@' ) ); ?>" target="_blank" rel="noopener" aria-label="Telegram"><?php else : ?><span class="nptv-soc"><?php endif; ?>
		<?php if ( $telegram_ik ) : ?>
			<img src="<?php echo esc_url( $telegram_ik ); ?>" alt="Telegram">
		<?php else : ?>
			<svg viewBox="0 0 24 24" width="30" height="30" role="img"><circle cx="12" cy="12" r="11" fill="#2AABEE"/><path d="M4.6 11.7l14.6-5.8c.8-.3 1.5.4 1.2 1.2l-4.5 11.7c-.3.8-1.4.8-1.8.1l-2.3-3.8-2.9 2.4c-.5.4-1.3.1-1.4-.5l-.6-3.3-2.4-1c-.7-.3-.7-1.3.1-1z" fill="#fff"/></svg>
		<?php endif; ?>
	<?php echo $telegram ? '</a>' : '</span>'; ?>
	<?php endif; ?>
	<?php if ( get_option( 'npt_show_globe', true ) ) : ?>
	<img src="<?php echo esc_url( npt_media_image_url( 'npt_globe_id', $imgs . 'globe.png' ) ); ?>" alt="">
	<?php endif; ?>
	<span><?php echo esc_html( npt_footer_domain_text() ); ?></span>
</footer>
<?php
$npt_phone   = trim( (string) get_option( 'npt_phone', '' ) );
$npt_address = trim( (string) get_option( 'npt_address', '' ) );
if ( $npt_phone || $npt_address ) :
	?>
<div class="nptv-contact"><?php echo esc_html( trim( $npt_address . ( $npt_address && $npt_phone ? '  |  ' : '' ) . $npt_phone ) ); ?></div>
<?php endif; ?>
</body>
</html>
	<?php
	return ob_get_clean();
}

/** After a plugin update: clear LiteSpeed/object caches and the price-list caches once. */
function npt_purge_caches_on_update() {
	if ( get_option( 'npt_purged_v' ) === NPT_VERSION ) {
		return;
	}
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'npt_bump_table_cache_version' ) ) {
		npt_bump_table_cache_version();
	}
	wp_cache_flush();
	update_option( 'npt_purged_v', NPT_VERSION, false );
}
add_action( 'admin_init', 'npt_purge_caches_on_update' );
