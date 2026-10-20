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
		.navid-app{--n-primary:#1faa6b;--n-soft:#e9f8f0;--n-ink:#1f2430;--n-muted:#6b7280;--n-line:#e5e8ee;--n-bg:#f4f6f8;direction:rtl;font-family:inherit;color:var(--n-ink);margin:0}
		.navid-app *{box-sizing:border-box}
		.navid-topbar{position:sticky;top:32px;z-index:50;display:flex;align-items:center;gap:14px;background:#fff;border-bottom:1px solid var(--n-line);padding:12px 22px}
		.navid-brandbox{display:flex;align-items:center;gap:12px;min-width:230px}
		.navid-brandmark{display:grid;place-items:center;width:44px;height:44px;border-radius:14px;background:var(--n-primary);color:#fff;font-weight:800;font-size:20px}
		.navid-brandbox strong{display:block;font-size:20px;line-height:1.2}
		.navid-brandbox small{color:var(--n-muted)}
		.navid-status{display:flex;align-items:center;gap:10px;margin-inline-start:12px}
		.navid-pill{display:inline-flex;align-items:center;gap:6px;background:var(--n-primary);color:#fff;border-radius:999px;padding:4px 14px;font-weight:700}
		.navid-pill:before{content:"";width:8px;height:8px;border-radius:50%;background:#fff}
		.navid-ver{color:var(--n-muted);font-size:12px}
		.navid-topbar-actions{display:flex;align-items:center;gap:10px;margin-inline-start:auto}
		.navid-qsearch{width:240px;border:1px solid var(--n-line);border-radius:10px;padding:8px 12px;background:#fff}
		.navid-savebtn{border:0;border-radius:10px;background:var(--n-primary);color:#fff;font:inherit;font-weight:700;padding:10px 26px;cursor:pointer}
		.navid-savebtn:hover{filter:brightness(.95)}
		.navid-layout{display:grid;grid-template-columns:290px 1fr;gap:22px;padding:20px 22px;align-items:start}
		.navid-side{position:sticky;top:112px;background:#fff;border:1px solid var(--n-line);border-radius:16px;padding:10px;max-height:calc(100vh - 130px);overflow:auto}
		.navid-side a,.navid-side summary{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:12px;color:var(--n-ink);text-decoration:none;font-weight:600;cursor:pointer;list-style:none}
		.navid-side summary::-webkit-details-marker{display:none}
		.navid-side summary:after{content:"\f347";font-family:dashicons;margin-inline-start:auto;color:var(--n-muted);transition:transform .15s}
		.navid-side details[open]>summary:after{transform:rotate(180deg)}
		.navid-side .dashicons{color:var(--n-muted);width:22px;height:22px;font-size:20px}
		.navid-side a:hover,.navid-side summary:hover{background:var(--n-bg)}
		.navid-side a.is-on{background:var(--n-soft);color:var(--n-primary)}
		.navid-side a.is-on .dashicons{color:var(--n-primary)}
		.navid-side .navid-sub{margin:0 14px 6px 0;border-right:2px solid var(--n-line)}
		.navid-side .navid-sub a{padding:8px 12px;font-weight:500;font-size:13px}
		.navid-main{min-width:0}
		.navid-sec-title{font-size:16px;font-weight:800;margin:0 0 12px}
		.navid-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin-bottom:26px}
		.navid-tile{display:flex;align-items:center;gap:12px;min-height:56px;background:#fff;border:1px solid var(--n-line);border-radius:14px;padding:12px 14px;color:var(--n-ink);text-decoration:none;font-weight:600}
		.navid-tile:hover{border-color:var(--n-primary);color:var(--n-primary)}
		.navid-tile .dashicons{color:var(--n-primary)}
		.navid-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:26px}
		.navid-stat{background:#fff;border:1px solid var(--n-line);border-radius:14px;padding:14px 16px}
		.navid-stat b{display:block;font-size:22px}
		.navid-stat span{color:var(--n-muted);font-size:13px}
		.navid-stat.is-bad b{color:#d63638}
		.navid-stat.is-ok b{color:var(--n-primary)}
		.navid-admin-card{background:#fff;border:1px solid var(--n-line);border-radius:16px;padding:18px;margin-bottom:16px}
		.navid-admin-card h2{margin:0 0 12px;font-size:16px}
		.navid-row{display:grid;grid-template-columns:220px 1fr;gap:12px;align-items:center;padding:10px 0;border-top:1px solid #f0f2f5}
		.navid-row:first-of-type{border-top:0}
		.navid-row input[type=text],.navid-row textarea{width:100%;max-width:520px}
		/* The price table plugin screens, shown inside the same shell */
		.navid-embedded .npt-settings{margin:0;background:#fff;border:1px solid var(--n-line);border-radius:16px;padding:18px}
		.navid-embedded .npt-settings>h1,.navid-embedded .npt-settings>.description,.navid-embedded #npt-tabs,.navid-embedded .npt-subnav,.navid-embedded .npt-submit-row{display:none!important}
		.navid-embedded .npt-settings h2{font-size:16px}
		@media (max-width:960px){.navid-layout{grid-template-columns:1fr}.navid-side{position:static;max-height:none}.navid-topbar{flex-wrap:wrap;top:46px}.navid-qsearch{width:100%}.navid-row{grid-template-columns:1fr}}
	';
	wp_enqueue_media();
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
		'special_label' => isset( $raw['special_label'] ) ? sanitize_text_field( $raw['special_label'] ) : '',
		'special_url'   => isset( $raw['special_url'] ) ? esc_url_raw( $raw['special_url'] ) : '',
		'email'         => isset( $raw['email'] ) ? sanitize_email( $raw['email'] ) : '',
		'about'         => isset( $raw['about'] ) ? sanitize_textarea_field( $raw['about'] ) : '',
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
 * Menu of the panel: groups open/close, items open one screen. Items that point
 * to a '#' anchor are sections of the overview page; the rest are the price
 * table screens, rendered inside the same shell.
 *
 * @return array
 */
function navid_admin_tree() {
	$base = admin_url( 'admin.php?page=navid-settings' );
	$tab  = static function ( $key ) use ( $base ) {
		return $base . '&tab=' . $key;
	};
	return array(
		array( 'label' => 'هاب نوید', 'icon' => 'dashicons-admin-home', 'items' => array( 'overview' => array( 'هاب نوید', $base ) ), 'single' => true ),
		array(
			'label' => 'پیکربندی نمایش',
			'icon'  => 'dashicons-art',
			'items' => array(
				'a-look'   => array( 'ظاهر و رنگ', $base . '#navid-look' ),
				'a-header' => array( 'هدر و فوتر', $base . '#navid-header' ),
				'a-bar'    => array( 'نوار جدول قیمت', $base . '#navid-price' ),
				'a-slider' => array( 'بنرهای صفحه اصلی', $base . '#navid-slider' ),
			),
		),
		array(
			'label' => 'جدول قیمت',
			'icon'  => 'dashicons-list-view',
			'items' => array(
				'price'   => array( 'قیمت‌ها و تعدیل', $tab( 'price' ) ),
				'header'  => array( 'سربرگ PDF', $tab( 'header' ) ),
				'content' => array( 'محتوا', $tab( 'content' ) ),
				'footer'  => array( 'پابرگ', $tab( 'footer' ) ),
			),
		),
		array( 'label' => 'سئو', 'icon' => 'dashicons-search', 'items' => array( 'seo' => array( 'سئو', $tab( 'seo' ) ) ), 'single' => true ),
		array(
			'label' => 'پیامک',
			'icon'  => 'dashicons-email-alt',
			'items' => array(
				'sms'       => array( 'تنظیمات پیامک', $tab( 'sms' ) ),
				'otp'       => array( 'کدهای الگو', $tab( 'otp' ) ),
				'templates' => array( 'الگوهای پیامک', $tab( 'templates' ) ),
				'sms_login' => array( 'ورود پیامکی', $tab( 'sms_login' ) ),
			),
		),
		array( 'label' => 'مخاطبین', 'icon' => 'dashicons-groups', 'items' => array( 'contacts' => array( 'مخاطبین', $tab( 'contacts' ) ) ), 'single' => true ),
		array(
			'label' => 'نوار موبایل',
			'icon'  => 'dashicons-smartphone',
			'items' => array(
				'bar_menu'  => array( 'منوی پایین', $tab( 'bar_menu' ) ),
				'bar_float' => array( 'آیکن شناور', $tab( 'bar_float' ) ),
			),
		),
		array(
			'label' => 'پنل مدیر',
			'icon'  => 'dashicons-businessperson',
			'items' => array(
				'login_page' => array( 'صفحه ورود', $tab( 'login_page' ) ),
				'panel_dash' => array( 'داشبورد مدیر', $tab( 'panel_dash' ) ),
				'managers'   => array( 'مدیران کسب‌وکار', $tab( 'managers' ) ),
			),
		),
		array( 'label' => 'تنظیمات عمومی', 'icon' => 'dashicons-admin-generic', 'items' => array( 'general' => array( 'تنظیمات عمومی', $tab( 'general' ) ) ), 'single' => true ),
		array( 'label' => 'پشتیبان و بروزرسانی', 'icon' => 'dashicons-backup', 'items' => array( 'backup' => array( 'پشتیبان و بروزرسانی', $tab( 'backup' ) ) ), 'single' => true ),
		array( 'label' => 'گزارش خطاها', 'icon' => 'dashicons-warning', 'items' => array( 'errors' => array( 'گزارش خطاها', $tab( 'errors' ) ) ), 'single' => true ),
	);
}

/**
 * Sidebar of the panel.
 *
 * @param string $active Current tab key, or 'overview'.
 */
function navid_admin_sidebar( $active ) {
	echo '<nav class="navid-side" aria-label="' . esc_attr__( 'بخش‌های پنل', 'navid' ) . '">';
	foreach ( navid_admin_tree() as $group ) {
		if ( ! empty( $group['single'] ) ) {
			$key  = key( $group['items'] );
			$item = $group['items'][ $key ];
			printf(
				'<a href="%s" class="%s" data-navid-find="%s"><span class="dashicons %s"></span>%s</a>',
				esc_url( $item[1] ),
				$key === $active ? 'is-on' : '',
				esc_attr( $item[0] ),
				esc_attr( $group['icon'] ),
				esc_html( $item[0] )
			);
			continue;
		}
		$open = array_key_exists( $active, $group['items'] );
		printf( '<details%s><summary><span class="dashicons %s"></span>%s</summary><div class="navid-sub">', $open ? ' open' : '', esc_attr( $group['icon'] ), esc_html( $group['label'] ) );
		foreach ( $group['items'] as $key => $item ) {
			printf(
				'<a href="%s" class="%s" data-navid-find="%s">%s</a>',
				esc_url( $item[1] ),
				$key === $active ? 'is-on' : '',
				esc_attr( $item[0] ),
				esc_html( $item[0] )
			);
		}
		echo '</div></details>';
	}
	echo '</nav>';
}

/**
 * Opens the panel shell: top bar (name, status, search, save) and the sidebar.
 *
 * @param string $active    Current tab key or 'overview'.
 * @param string $save_form ID of the form the top Save button submits ('' hides it).
 * @param bool   $embedded  True for the price table screens.
 */
function navid_admin_shell_open( $active, $save_form, $embedded ) {
	echo '<div class="navid-app' . ( $embedded ? ' navid-embedded' : '' ) . '" dir="rtl">';
	echo '<header class="navid-topbar">';
	echo '<div class="navid-brandbox"><span class="navid-brandmark">N</span><div><strong>پنل نوید</strong><small>مدیریت قالب و فروشگاه</small></div></div>';
	echo '<div class="navid-status"><span class="navid-pill">فعال</span><span class="navid-ver">نسخه نصب شد: ' . esc_html( NAVID_VERSION ) . '</span></div>';
	echo '<div class="navid-topbar-actions">';
	echo '<input type="search" class="navid-qsearch" id="navid-qsearch" placeholder="' . esc_attr__( 'جستجوی سریع (Ctrl K)', 'navid' ) . '" autocomplete="off">';
	if ( '' !== $save_form ) {
		echo '<button type="submit" class="navid-savebtn" form="' . esc_attr( $save_form ) . '">' . esc_html__( 'ذخیره‌کردن', 'navid' ) . '</button>';
	}
	echo '</div></header>';
	echo '<div class="navid-layout">';
	navid_admin_sidebar( $active );
	echo '<main class="navid-main">';
}

/**
 * Closes the shell and adds the quick-search script.
 */
function navid_admin_shell_close() {
	echo '</main></div></div>';
	?>
	<script>
	(function () {
		var box = document.getElementById('navid-qsearch');
		if (!box) { return; }
		var links = document.querySelectorAll('[data-navid-find], .navid-tile');
		box.addEventListener('input', function () {
			var q = box.value.trim().toLowerCase();
			Array.prototype.forEach.call(links, function (el) {
				var text = (el.getAttribute('data-navid-find') || el.textContent).toLowerCase();
				var hit = !q || text.indexOf(q) !== -1;
				el.style.display = hit ? '' : 'none';
				var group = el.closest('details');
				if (group && q && hit) { group.open = true; }
			});
		});
		document.addEventListener('keydown', function (e) {
			if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); box.focus(); }
		});
	}());
	</script>
	<?php
}

/**
 * Renders the settings page.
 */
function navid_render_settings() {
	$opt = navid_options();
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Screens that belong to the price table code, rendered inside this shell.
	$plugin_tabs = array( 'general', 'price', 'seo', 'sms', 'contacts', 'bar_menu', 'bar_float', 'login_page', 'panel_dash', 'backup', 'managers', 'errors', 'header', 'content', 'footer', 'otp', 'templates', 'sms_login' );
	if ( '' !== $tab && in_array( $tab, $plugin_tabs, true ) && function_exists( 'npt_render_settings_page' ) ) {
		// These screens carry their own forms and have nothing for the Save button.
		$own_form = array( 'templates', 'contacts', 'managers', 'backup', 'errors' );
		navid_admin_shell_open( $tab, in_array( $tab, $own_form, true ) ? '' : 'npt-settings-form', true );
		npt_render_settings_page();
		navid_admin_shell_close();
		return;
	}

	$saved   = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$base    = admin_url( 'admin.php?page=navid-settings' );
	$tiles   = array(
		array( 'محصولات', admin_url( 'edit.php?post_type=product' ), 'dashicons-products' ),
		array( 'تنظیمات ووکامرس', admin_url( 'admin.php?page=wc-settings' ), 'dashicons-cart' ),
		array( 'سفارشی‌سازی', admin_url( 'customize.php' ), 'dashicons-admin-customizer' ),
		array( 'منوها', admin_url( 'nav-menus.php' ), 'dashicons-menu' ),
		array( 'ویجت‌ها', admin_url( 'widgets.php' ), 'dashicons-welcome-widgets-menus' ),
		array( 'پنل مدیر (پیش‌فاکتورها)', function_exists( 'npt_panel_url' ) ? npt_panel_url() : home_url( '/' ), 'dashicons-businessperson' ),
	);
	$tools   = array(
		array( 'پیکربندی جدول قیمت', $base . '&tab=price', 'dashicons-list-view' ),
		array( 'پیکربندی پیامک', $base . '&tab=sms', 'dashicons-email-alt' ),
		array( 'پیکربندی موبایل', $base . '&tab=bar_menu', 'dashicons-smartphone' ),
		array( 'مخاطبین', $base . '&tab=contacts', 'dashicons-groups' ),
		array( 'مدیران کسب‌وکار', $base . '&tab=managers', 'dashicons-businessperson' ),
		array( 'پشتیبان و بروزرسانی', $base . '&tab=backup', 'dashicons-backup' ),
		array( 'گزارش خطاها', $base . '&tab=errors', 'dashicons-warning' ),
		array( 'سئو', $base . '&tab=seo', 'dashicons-search' ),
	);
	$visits  = (int) get_option( 'npt_visits_' . wp_date( 'Ymd' ), 0 );
	$total   = (int) get_option( 'npt_visits_total', 0 );
	$wc_on   = class_exists( 'WooCommerce' );
	$sms_on  = class_exists( 'NPT_SMS' ) && NPT_SMS::otp_ready();
	$counts  = $wc_on ? wp_count_posts( 'product' ) : null;
	$stats   = array(
		array( 'ووکامرس', $wc_on ? 'فعال' : 'غیرفعال', $wc_on ),
		array( 'سرویس پیامک', $sms_on ? 'آماده' : 'تنظیم نشده', $sms_on ),
		array( 'محصولات منتشرشده', $counts ? number_format_i18n( (int) $counts->publish ) : '—', true ),
		array( 'بازدید امروز / کل', number_format_i18n( $visits ) . ' / ' . number_format_i18n( $total ), true ),
	);

	navid_admin_shell_open( 'overview', 'navid-settings-form', false );
	?>
	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>
	<?php endif; ?>

	<h2 class="navid-sec-title">وضعیت</h2>
	<div class="navid-stats">
		<?php foreach ( $stats as $stat ) : ?>
			<div class="navid-stat <?php echo $stat[2] ? 'is-ok' : 'is-bad'; ?>"><b><?php echo esc_html( $stat[1] ); ?></b><span><?php echo esc_html( $stat[0] ); ?></span></div>
		<?php endforeach; ?>
	</div>

	<h2 class="navid-sec-title">دسترسی‌های سریع</h2>
	<div class="navid-tiles">
		<?php foreach ( $tiles as $tile ) : ?>
			<a class="navid-tile" href="<?php echo esc_url( $tile[1] ); ?>"><span class="dashicons <?php echo esc_attr( $tile[2] ); ?>"></span><?php echo esc_html( $tile[0] ); ?></a>
		<?php endforeach; ?>
	</div>

	<h2 class="navid-sec-title">ابزارها</h2>
	<div class="navid-tiles">
		<?php foreach ( $tools as $tile ) : ?>
			<a class="navid-tile" href="<?php echo esc_url( $tile[1] ); ?>"><span class="dashicons <?php echo esc_attr( $tile[2] ); ?>"></span><?php echo esc_html( $tile[0] ); ?></a>
		<?php endforeach; ?>
	</div>

	<form id="navid-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
				<label for="navid-special-label">لینک ویژه کنار منو (مثلاً فروش ویژه)</label>
				<input id="navid-special-label" type="text" name="navid[special_label]" value="<?php echo esc_attr( $opt['special_label'] ); ?>" placeholder="فروش ویژه">
			</div>
			<div class="navid-row">
				<label for="navid-special-url">آدرس لینک ویژه</label>
				<input id="navid-special-url" type="text" name="navid[special_url]" value="<?php echo esc_attr( $opt['special_url'] ); ?>" dir="ltr" placeholder="https://">
			</div>
			<div class="navid-row">
				<label for="navid-email">ایمیل</label>
				<input id="navid-email" type="text" name="navid[email]" value="<?php echo esc_attr( $opt['email'] ); ?>" dir="ltr">
			</div>
			<div class="navid-row">
				<label for="navid-about">متن معرفی در فوتر</label>
				<textarea id="navid-about" name="navid[about]" rows="3"><?php echo esc_textarea( $opt['about'] ); ?></textarea>
			</div>
			<div class="navid-row">
				<label for="navid-address">آدرس</label>
				<input id="navid-address" type="text" name="navid[address]" value="<?php echo esc_attr( $opt['address'] ); ?>">
			</div>
		</section>

		<section id="navid-price" class="navid-admin-card">
			<h2>نوار جدول قیمت</h2>
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
				<input id="navid-price-url" type="text" name="navid[price_url]" value="<?php echo esc_attr( $opt['price_url'] ); ?>" placeholder="https://" dir="ltr">
			</div>
		</section>

		<section id="navid-slider" class="navid-admin-card">
			<h2>بنرهای اسلایدر صفحه اصلی</h2>
			<div class="navid-row">
				<label for="navid-slides">هر بنر در یک خط: آدرس عکس | لینک | متن جایگزین</label>
				<div>
					<textarea id="navid-slides" name="navid[slides]" rows="5" dir="ltr" placeholder="https://.../banner.jpg | https://.../link | متن جایگزین"><?php echo esc_textarea( $opt['slides'] ); ?></textarea>
					<p><button type="button" class="button" id="navid-add-slide">افزودن تصویر از کتابخانه رسانه</button>
					<span class="description">اگر خالی بماند، سه بنر پیش‌فرض نمایش داده می‌شود. اندازهٔ پیشنهادی تصویر ۱۲۰۰×۴۰۰.</span></p>
				</div>
				<script>
				jQuery(function ($) {
					$('#navid-add-slide').on('click', function (e) {
						e.preventDefault();
						var frame = wp.media({ title: 'انتخاب بنر', multiple: true, library: { type: 'image' } });
						frame.on('select', function () {
							var box = $('#navid-slides'), lines = box.val().replace(/\s+$/, '');
							frame.state().get('selection').each(function (att) {
								var a = att.toJSON();
								lines += (lines ? '\n' : '') + a.url + ' | | ' + (a.alt || a.title || '');
							});
							box.val(lines);
						});
						frame.open();
					});
				});
				</script>
			</div>
		</section>
	</form>
	<?php
	navid_admin_shell_close();
}
