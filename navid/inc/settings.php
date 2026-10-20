<?php
/**
 * Theme settings page (پنل تنظیمات قالب نوید).
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the menu entry under Appearance.
 */
function navid_settings_menu() {
	add_menu_page(
		'پنل نوید',
		'پنل نوید',
		'manage_options',
		'navid-settings',
		'navid_render_settings',
		'dashicons-admin-generic',
		3
	);
}
add_action( 'admin_menu', 'navid_settings_menu' );

/**
 * Styles for the settings page only.
 *
 * @param string $hook Current admin page.
 */
function navid_settings_styles( $hook ) {
	if ( 'toplevel_page_navid-settings' !== $hook ) {
		return;
	}
	$css = '
		.navid-admin{display:grid;grid-template-columns:260px 1fr;gap:20px;margin-top:16px;font-family:inherit}
		.navid-admin-side{background:#fff;border:1px solid #e5e8ee;border-radius:14px;padding:10px}
		.navid-admin-side a{display:block;padding:10px 14px;border-radius:10px;color:#1f2430;text-decoration:none;font-weight:600}
		.navid-admin-side a.is-on,.navid-admin-side a:hover{background:#e9f8f0;color:#1faa6b}
		.navid-admin-card{background:#fff;border:1px solid #e5e8ee;border-radius:14px;padding:18px;margin-bottom:16px}
		.navid-admin-card h2{margin:0 0 12px;font-size:16px}
		.navid-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
		.navid-tile{display:flex;align-items:center;justify-content:center;min-height:52px;border:1px solid #e5e8ee;border-radius:12px;padding:10px;color:#1f2430;text-decoration:none;font-weight:600;background:#fff}
		.navid-tile:hover{border-color:#1faa6b;color:#1faa6b}
		.navid-row{display:grid;grid-template-columns:200px 1fr;gap:12px;align-items:center;padding:10px 0;border-top:1px solid #f0f2f5}
		.navid-row:first-child{border-top:0}
		.navid-row input[type=text],.navid-row input[type=color]{width:100%;max-width:320px}
		.navid-savebar{position:sticky;bottom:0;background:#fff;border:1px solid #e5e8ee;border-radius:14px;padding:12px;display:flex;justify-content:flex-end}
		@media (max-width:782px){.navid-admin{grid-template-columns:1fr}.navid-row{grid-template-columns:1fr}}
	';
	wp_register_style( 'navid-settings', false, array(), NAVID_VERSION );
	wp_enqueue_style( 'navid-settings' );
	wp_add_inline_style( 'navid-settings', $css );
}
add_action( 'admin_enqueue_scripts', 'navid_settings_styles' );

/**
 * Saves the form after checking the nonce and the capability.
 */
function navid_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی ندارید.', 'navid' ) );
	}
	check_admin_referer( 'navid_save_settings' );

	$raw   = isset( $_POST['navid'] ) ? wp_unslash( $_POST['navid'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$color = isset( $raw['primary_color'] ) ? sanitize_hex_color( $raw['primary_color'] ) : '';

	$clean = array(
		'primary_color' => $color ? $color : '#1faa6b',
		'show_topbar'   => empty( $raw['show_topbar'] ) ? '0' : '1',
		'sticky_header' => empty( $raw['sticky_header'] ) ? '0' : '1',
		'toolbar_title' => isset( $raw['toolbar_title'] ) ? sanitize_text_field( $raw['toolbar_title'] ) : '',
		'toolbar_sub'   => isset( $raw['toolbar_sub'] ) ? sanitize_text_field( $raw['toolbar_sub'] ) : '',
		'price_url'     => isset( $raw['price_url'] ) ? esc_url_raw( $raw['price_url'] ) : '',
		'slides'        => isset( $raw['slides'] ) ? sanitize_textarea_field( $raw['slides'] ) : '',
		'phone'         => isset( $raw['phone'] ) ? sanitize_text_field( $raw['phone'] ) : '',
		'address'       => isset( $raw['address'] ) ? sanitize_text_field( $raw['address'] ) : '',
	);
	update_option( 'navid_options', $clean );
	// The price list header, footer and PDF read these two, so they stay in sync.
	update_option( 'npt_phone', $clean['phone'] );
	update_option( 'npt_address', $clean['address'] );

	wp_safe_redirect( add_query_arg( array( 'page' => 'navid-settings', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_navid_save_settings', 'navid_save_settings' );

/**
 * Renders the settings page.
 */
/**
 * Sidebar of the panel. Plugin screens (price table, SMS, contacts, bar, logs...)
 * are listed next to the theme sections.
 *
 * @param string $active Current tab key, or 'overview'.
 */
function navid_admin_sidebar( $active ) {
	$base  = admin_url( 'admin.php?page=navid-settings' );
	$items = array(
		'overview'  => array( 'نمای کلی', $base ),
		'look'      => array( 'پیکربندی نمایش', $base . '#navid-look' ),
		'header'    => array( 'هدر و فوتر', $base . '#navid-header' ),
		'home'      => array( 'صفحه اصلی', $base . '#navid-slider' ),
		'price'     => array( 'جدول قیمت', $base . '&tab=price' ),
		'contacts'  => array( 'مخاطبین', $base . '&tab=contacts' ),
		'sms'       => array( 'پیامک', $base . '&tab=sms' ),
		'seo'       => array( 'سئو', $base . '&tab=seo' ),
		'bar_menu'  => array( 'منوی پایین موبایل', $base . '&tab=bar_menu' ),
		'bar_float' => array( 'آیکن شناور', $base . '&tab=bar_float' ),
		'general'   => array( 'تنظیمات عمومی', $base . '&tab=general' ),
		'backup'    => array( 'پشتیبان', $base . '&tab=backup' ),
		'errors'    => array( 'گزارش خطاها', $base . '&tab=errors' ),
	);
	echo '<nav class="navid-admin-side" aria-label="' . esc_attr__( 'بخش‌های پنل', 'navid' ) . '">';
	foreach ( $items as $key => $item ) {
		printf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( $item[1] ),
			$key === $active ? 'is-on' : '',
			esc_html( $item[0] )
		);
	}
	echo '</nav>';
}

function navid_render_settings() {
	$opt   = navid_options();
	$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	// Screens that belong to the price table plugin, rendered inside this panel.
	$plugin_tabs = array( 'general', 'price', 'seo', 'sms', 'contacts', 'bar_menu', 'bar_float', 'login_page', 'panel_dash', 'backup', 'managers', 'errors', 'header', 'content', 'footer', 'otp', 'templates', 'sms_login' );
	if ( '' !== $tab && in_array( $tab, $plugin_tabs, true ) && function_exists( 'npt_render_settings_page' ) ) {
		echo '<div class="navid-admin-wrap"><div class="navid-admin">';
		navid_admin_sidebar( $tab );
		echo '<div class="navid-admin-main">';
		npt_render_settings_page();
		echo '</div></div></div>';
		return;
	}
	$saved = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tiles = array(
		'محصولات'         => admin_url( 'edit.php?post_type=product' ),
		'پنل مدیر (پیش‌فاکتورها)' => function_exists( 'npt_panel_url' ) ? npt_panel_url() : home_url( '/' ),
		'گزارش خطاها'      => admin_url( 'admin.php?page=navid-settings&tab=errors' ),
		'تنظیمات ووکامرس'  => admin_url( 'admin.php?page=wc-settings' ),
		'منوها'           => admin_url( 'nav-menus.php' ),
		'ویجت‌ها'         => admin_url( 'widgets.php' ),
	);
	?>
	<div class="wrap">
		<h1>تنظیمات قالب نوید</h1>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>
		<?php endif; ?>

		<div class="navid-admin">
			<?php navid_admin_sidebar( 'overview' ); ?>

			<div>
				<section id="navid-quick" class="navid-admin-card">
					<h2>دسترسی سریع</h2>
					<div class="navid-tiles">
						<?php foreach ( $tiles as $label => $url ) : ?>
							<a class="navid-tile" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</div>
				</section>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="navid_save_settings">
					<?php wp_nonce_field( 'navid_save_settings' ); ?>

					<section id="navid-look" class="navid-admin-card">
						<h2>ظاهر</h2>
						<div class="navid-row">
							<label for="navid-primary">رنگ اصلی</label>
							<input id="navid-primary" type="color" name="navid[primary_color]" value="<?php echo esc_attr( $opt['primary_color'] ); ?>">
						</div>
					</section>

					<section id="navid-header" class="navid-admin-card">
						<h2>هدر و فوتر</h2>
						<div class="navid-row">
							<label for="navid-topbar">نمایش نوار بالا</label>
							<input id="navid-topbar" type="checkbox" name="navid[show_topbar]" value="1" <?php checked( '1', $opt['show_topbar'] ); ?>>
						</div>
						<div class="navid-row">
							<label for="navid-sticky">هدر چسبان</label>
							<input id="navid-sticky" type="checkbox" name="navid[sticky_header]" value="1" <?php checked( '1', $opt['sticky_header'] ); ?>>
						</div>
						<div class="navid-row">
							<label for="navid-phone">شماره تماس</label>
							<input id="navid-phone" type="text" name="navid[phone]" value="<?php echo esc_attr( $opt['phone'] ); ?>" dir="ltr">
						</div>
						<div class="navid-row">
							<label for="navid-address">آدرس</label>
							<input id="navid-address" type="text" name="navid[address]" value="<?php echo esc_attr( $opt['address'] ); ?>">
						</div>
					</section>

					<section id="navid-price" class="navid-admin-card">
						<h2>جدول قیمت</h2>
						<div class="navid-row">
							<label for="navid-toolbar-title">عنوان نوار جدول</label>
							<input id="navid-toolbar-title" type="text" name="navid[toolbar_title]" value="<?php echo esc_attr( $opt['toolbar_title'] ); ?>">
						</div>
						<div class="navid-row">
							<label for="navid-toolbar-sub">متن زیر عنوان (اختیاری)</label>
							<input id="navid-toolbar-sub" type="text" name="navid[toolbar_sub]" value="<?php echo esc_attr( $opt['toolbar_sub'] ); ?>">
						</div>
						<div class="navid-row">
							<label for="navid-price-url">آدرس صفحه جدول قیمت</label>
							<input id="navid-price-url" type="text" name="navid[price_url]" value="<?php echo esc_attr( $opt['price_url'] ); ?>" placeholder="https://">
						</div>
					</section>

					<section id="navid-slider" class="navid-admin-card">
						<h2>بنرهای اسلایدر صفحه اصلی</h2>
						<div class="navid-row">
							<label for="navid-slides">هر بنر در یک خط: آدرس عکس | لینک | متن جایگزین</label>
							<textarea id="navid-slides" name="navid[slides]" rows="5" dir="ltr"><?php echo esc_textarea( $opt['slides'] ); ?></textarea>
						</div>
					</section>

					<div class="navid-savebar">
						<button type="submit" class="navid-btn navid-btn--primary">ذخیره تغییرات</button>
					</div>
				</form>
			</div>
		</div>
	</div>
	<?php
}
