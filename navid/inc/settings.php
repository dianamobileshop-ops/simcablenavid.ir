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
	add_theme_page(
		'تنظیمات قالب نوید',
		'تنظیمات قالب نوید',
		'manage_options',
		'navid-settings',
		'navid_render_settings'
	);
}
add_action( 'admin_menu', 'navid_settings_menu' );

/**
 * Styles for the settings page only.
 *
 * @param string $hook Current admin page.
 */
function navid_settings_styles( $hook ) {
	if ( 'appearance_page_navid-settings' !== $hook ) {
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
	);
	update_option( 'navid_options', $clean );

	wp_safe_redirect( add_query_arg( array( 'page' => 'navid-settings', 'saved' => '1' ), admin_url( 'themes.php' ) ) );
	exit;
}
add_action( 'admin_post_navid_save_settings', 'navid_save_settings' );

/**
 * Renders the settings page.
 */
function navid_render_settings() {
	$opt   = navid_options();
	$saved = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tiles = array(
		'محصولات'         => admin_url( 'edit.php?post_type=product' ),
		'پیش‌فاکتورها'     => admin_url( 'admin.php?page=navid-proformas' ),
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
			<nav class="navid-admin-side" aria-label="بخش‌های تنظیمات">
				<a href="#navid-quick" class="is-on">دسترسی سریع</a>
				<a href="#navid-look">ظاهر</a>
				<a href="#navid-header">هدر و نوار بالا</a>
				<a href="#navid-price">جدول قیمت</a>
			</nav>

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
						<h2>هدر و نوار بالا</h2>
						<div class="navid-row">
							<label for="navid-topbar">نمایش نوار بالا</label>
							<input id="navid-topbar" type="checkbox" name="navid[show_topbar]" value="1" <?php checked( '1', $opt['show_topbar'] ); ?>>
						</div>
						<div class="navid-row">
							<label for="navid-sticky">هدر چسبان</label>
							<input id="navid-sticky" type="checkbox" name="navid[sticky_header]" value="1" <?php checked( '1', $opt['sticky_header'] ); ?>>
						</div>
					</section>

					<section id="navid-price" class="navid-admin-card">
						<h2>جدول قیمت</h2>
						<div class="navid-row">
							<label for="navid-toolbar-title">عنوان نوار جدول</label>
							<input id="navid-toolbar-title" type="text" name="navid[toolbar_title]" value="<?php echo esc_attr( $opt['toolbar_title'] ); ?>">
						</div>
						<div class="navid-row">
							<label for="navid-toolbar-sub">خط به‌روزرسانی</label>
							<input id="navid-toolbar-sub" type="text" name="navid[toolbar_sub]" value="<?php echo esc_attr( $opt['toolbar_sub'] ); ?>">
						</div>
						<div class="navid-row">
							<label for="navid-price-url">آدرس صفحه قیمت</label>
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
