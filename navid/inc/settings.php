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
		.navid-acc>summary{display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer;list-style:none;font-size:16px;font-weight:800;padding:2px 0}
		.navid-acc>summary::-webkit-details-marker{display:none}
		.navid-acc>summary:after{content:"\f347";font-family:dashicons;color:var(--n-muted);transition:transform .15s}
		.navid-acc[open]>summary:after{transform:rotate(180deg)}
		.navid-acc[open]>summary{margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--n-line)}
		.navid-card-inner{background:#fff;border:1px solid var(--n-line);border-radius:14px;padding:16px 18px;margin:0 0 14px}
		.navid-card-inner>h2{margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid var(--n-line);font-size:16px}
		.navid-brandbox{text-decoration:none;color:inherit}
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
		'slider_mode'   => ( isset( $raw['slider_mode'] ) && in_array( $raw['slider_mode'], array( 'slide', 'fade', 'cards', 'mosaic' ), true ) ) ? $raw['slider_mode'] : 'slide',
		'slider_speed'  => isset( $raw['slider_speed'] ) ? min( 15000, max( 2000, absint( $raw['slider_speed'] ) ) ) : 5000,
		'slider_ratio'  => ( isset( $raw['slider_ratio'] ) && in_array( $raw['slider_ratio'], array( '3/1', '2/1', '16/9', '4/3' ), true ) ) ? $raw['slider_ratio'] : '3/1',
		'slider_arrows' => empty( $raw['slider_arrows'] ) ? '0' : '1',
		'slider_autoplay' => empty( $raw['slider_autoplay'] ) ? '0' : '1',
		'home_cats'     => isset( $raw['home_cats'] ) ? array_values( array_filter( array_map( 'absint', (array) $raw['home_cats'] ) ) ) : array(),
		'cats_effect'   => ( isset( $raw['cats_effect'] ) && in_array( $raw['cats_effect'], array( 'none', 'lift', 'zoom', 'ring', 'tilt', 'shine' ), true ) ) ? $raw['cats_effect'] : 'lift',
		'cats_shape'    => ( isset( $raw['cats_shape'] ) && in_array( $raw['cats_shape'], array( 'circle', 'rounded', 'square' ), true ) ) ? $raw['cats_shape'] : 'circle',
		'cats_cols'     => isset( $raw['cats_cols'] ) ? min( 8, max( 3, absint( $raw['cats_cols'] ) ) ) : 6,
		'special_label' => isset( $raw['special_label'] ) ? sanitize_text_field( $raw['special_label'] ) : '',
		'special_url'   => isset( $raw['special_url'] ) ? esc_url_raw( $raw['special_url'] ) : '',
		'email'         => isset( $raw['email'] ) ? sanitize_email( $raw['email'] ) : '',
		'about'         => isset( $raw['about'] ) ? sanitize_textarea_field( $raw['about'] ) : '',
		'phone'         => isset( $raw['phone'] ) ? sanitize_text_field( $raw['phone'] ) : '',
		'address'       => isset( $raw['address'] ) ? sanitize_text_field( $raw['address'] ) : '',
	);
	// Only the keys of the screen that was submitted change; everything else stays.
	$groups = array(
		't-look'   => array( 'primary_color' ),
		't-header' => array( 'show_topbar', 'sticky_header', 'phone', 'address', 'email', 'about', 'special_label', 'special_url' ),
		't-bar'    => array( 'toolbar_title', 'toolbar_sub', 'price_url' ),
		't-cats'   => array( 'home_cats', 'cats_effect', 'cats_shape', 'cats_cols' ),
		't-slider' => array( 'slides', 'slider_mode', 'slider_speed', 'slider_ratio', 'slider_arrows', 'slider_autoplay' ),
	);
	$section = isset( $raw['_tab'] ) ? sanitize_key( $raw['_tab'] ) : '';
	if ( isset( $groups[ $section ] ) ) {
		$merged = navid_options();
		foreach ( $groups[ $section ] as $field ) {
			$merged[ $field ] = $clean[ $field ];
		}
		$clean = $merged;
	}
	update_option( 'navid_options', $clean );
	// The price list header, footer and PDF read these two, so they stay in sync.
	update_option( 'npt_phone', $clean['phone'] );
	update_option( 'npt_address', $clean['address'] );

	wp_safe_redirect( add_query_arg( array( 'page' => 'navid-settings', 'tab' => $section, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
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
		array(
			'label' => 'پیکربندی نمایش',
			'icon'  => 'dashicons-art',
			'items' => array(
				't-look'   => array( 'ظاهر و رنگ', $tab( 't-look' ) ),
				't-header' => array( 'هدر و فوتر', $tab( 't-header' ) ),
				't-bar'    => array( 'نوار جدول قیمت', $tab( 't-bar' ) ),
				't-cats'   => array( 'دسته‌بندی‌های صفحه اصلی', $tab( 't-cats' ) ),
				't-slider' => array( 'اسلایدر صفحه اصلی', $tab( 't-slider' ) ),
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
	echo '<a class="navid-brandbox" href="' . esc_url( admin_url( 'admin.php?page=navid-settings' ) ) . '"><span class="navid-brandmark">N</span><div><strong>پنل نوید</strong><small>مدیریت قالب و فروشگاه</small></div></a>';
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
		// Open the accordion that an anchor link points to.
		function openHash() {
			var id = location.hash.replace('#', '');
			var el = id && document.getElementById(id);
			if (el && el.tagName === 'DETAILS') { el.open = true; el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
		}
		window.addEventListener('hashchange', openHash);
		openHash();

		// Price table screens: every heading starts its own card, so the sections are clearly separated.
		Array.prototype.forEach.call(document.querySelectorAll('.navid-embedded .npt-panel'), function (panel) {
			var kids = Array.prototype.slice.call(panel.children);
			var current = null;
			kids.forEach(function (el) {
				if (el.tagName === 'H2') {
					current = document.createElement('section');
					current.className = 'navid-card-inner';
					panel.insertBefore(current, el);
					current.appendChild(el);
				} else if (current) {
					current.appendChild(el);
				}
			});
		});

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
 * One theme settings screen.
 *
 * @param string $key Tab key.
 * @param array  $opt Options.
 */
function navid_settings_section( $key, $opt ) {
	switch ( $key ) {
		case 't-look':
			?>
			<section class="navid-admin-card">
				<h2>ظاهر</h2>
			<div class="navid-row">
				<label for="navid-primary">رنگ اصلی</label>
				<input id="navid-primary" type="color" name="navid[primary_color]" value="<?php echo esc_attr( $opt['primary_color'] ); ?>">
			</div>
			</section>
			<?php
			break;
		case 't-header':
			?>
			<section class="navid-admin-card">
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
			<?php
			break;
		case 't-bar':
			?>
			<section class="navid-admin-card">
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
			<?php
			break;
		case 't-cats':
			?>
			<section class="navid-admin-card">
				<h2>دسته‌بندی‌های صفحه اصلی</h2>
			<div class="navid-row">
				<label for="navid-cats-effect">افکت تصویر دسته‌ها</label>
				<select id="navid-cats-effect" name="navid[cats_effect]">
					<?php
					foreach ( array( 'none' => 'بدون افکت', 'lift' => 'بالا آمدن با سایه', 'zoom' => 'بزرگ‌نمایی تصویر', 'ring' => 'حلقهٔ رنگی', 'tilt' => 'چرخش ملایم', 'shine' => 'درخشش' ) as $navid_k => $navid_l ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $navid_k ), selected( $opt['cats_effect'], $navid_k, false ), esc_html( $navid_l ) );
					}
					?>
				</select>
			</div>
			<div class="navid-row">
				<label for="navid-cats-shape">شکل تصویر</label>
				<select id="navid-cats-shape" name="navid[cats_shape]">
					<?php
					foreach ( array( 'circle' => 'دایره', 'rounded' => 'گوشه‌گرد', 'square' => 'مربع' ) as $navid_k => $navid_l ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $navid_k ), selected( $opt['cats_shape'], $navid_k, false ), esc_html( $navid_l ) );
					}
					?>
				</select>
			</div>
			<div class="navid-row">
				<label for="navid-cats-cols">تعداد ستون در دسکتاپ</label>
				<input id="navid-cats-cols" type="number" min="3" max="8" name="navid[cats_cols]" value="<?php echo esc_attr( (int) $opt['cats_cols'] ); ?>" style="width:80px">
			</div>
			<div class="navid-row">
				<label>دسته‌هایی که نمایش داده شوند</label>
				<div>
					<p class="description">هیچ‌کدام انتخاب نشود = همهٔ دسته‌های اصلی. ترتیب نمایش همان ترتیب فهرست زیر است.</p>
					<?php
					$navid_all = taxonomy_exists( 'product_cat' ) ? get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) ) : array();
					if ( ! is_wp_error( $navid_all ) ) :
						foreach ( $navid_all as $navid_term ) :
							?>
							<label style="display:inline-flex;align-items:center;gap:6px;margin:0 0 8px 16px">
								<input type="checkbox" name="navid[home_cats][]" value="<?php echo (int) $navid_term->term_id; ?>" <?php checked( in_array( (int) $navid_term->term_id, array_map( 'intval', (array) $opt['home_cats'] ), true ) ); ?>>
								<?php echo esc_html( ( $navid_term->parent ? '— ' : '' ) . $navid_term->name ); ?>
							</label>
							<?php
						endforeach;
					endif;
					?>
				</div>
			</div>
			</section>
			<?php
			break;
		case 't-slider':
			?>
			<section class="navid-admin-card">
				<h2>بنرهای اسلایدر صفحه اصلی</h2>
			<div class="navid-row">
				<label for="navid-slider-mode">حالت اسلایدر</label>
				<select id="navid-slider-mode" name="navid[slider_mode]">
					<?php
					foreach ( array( 'slide' => 'تمام‌عرض (کشویی)', 'fade' => 'محو شدن', 'cards' => 'کارتی (دیده شدن بنرهای کناری)', 'mosaic' => 'موزاییکی (یک بنر بزرگ + دو بنر کوچک)' ) as $navid_k => $navid_l ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $navid_k ), selected( $opt['slider_mode'], $navid_k, false ), esc_html( $navid_l ) );
					}
					?>
				</select>
			</div>
			<div class="navid-row">
				<label for="navid-slider-ratio">نسبت ابعاد بنر</label>
				<select id="navid-slider-ratio" name="navid[slider_ratio]">
					<?php
					foreach ( array( '3/1' => '۳ به ۱ (باریک)', '2/1' => '۲ به ۱', '16/9' => '۱۶ به ۹', '4/3' => '۴ به ۳' ) as $navid_k => $navid_l ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $navid_k ), selected( $opt['slider_ratio'], $navid_k, false ), esc_html( $navid_l ) );
					}
					?>
				</select>
			</div>
			<div class="navid-row">
				<label for="navid-slider-speed">مدت نمایش هر بنر (میلی‌ثانیه)</label>
				<input id="navid-slider-speed" type="number" min="2000" max="15000" step="500" name="navid[slider_speed]" value="<?php echo esc_attr( (int) $opt['slider_speed'] ); ?>" style="width:110px">
			</div>
			<div class="navid-row">
				<label>گزینه‌ها</label>
				<div>
					<label><input type="checkbox" name="navid[slider_autoplay]" value="1" <?php checked( '1', (string) $opt['slider_autoplay'] ); ?>> حرکت خودکار</label>
					<label style="margin-inline-start:16px"><input type="checkbox" name="navid[slider_arrows]" value="1" <?php checked( '1', (string) $opt['slider_arrows'] ); ?>> نمایش فلش‌ها</label>
				</div>
			</div>
			<div class="navid-row">
				<label for="navid-slides">هر بنر در یک خط: آدرس عکس | لینک | متن جایگزین | آدرس عکس موبایل (اختیاری)</label>
				<div>
					<textarea id="navid-slides" name="navid[slides]" rows="5" dir="ltr" placeholder="آدرس عکس | لینک | متن جایگزین | عکس موبایل (اختیاری)"><?php echo esc_textarea( $opt['slides'] ); ?></textarea>
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
			<?php
			break;
	}
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

	// Theme screens: one section per menu entry, saved by the top Save button.
	if ( in_array( $tab, array( 't-look', 't-header', 't-bar', 't-cats', 't-slider' ), true ) ) {
		navid_admin_shell_open( $tab, 'navid-settings-form', false );
		if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>
			<?php
		endif;
		?>
		<form id="navid-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="navid_save_settings">
			<input type="hidden" name="navid[_tab]" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( 'navid_save_settings' ); ?>
			<?php navid_settings_section( $tab, $opt ); ?>
		</form>
		<?php
		navid_admin_shell_close();
		return;
	}

	navid_render_report();
}

/**
 * Landing screen: a report of how the site is doing, plus shortcuts.
 */
function navid_render_report() {
	global $wpdb;
	$base    = admin_url( 'admin.php?page=navid-settings' );
	$wc_on   = class_exists( 'WooCommerce' );
	$sms_on  = class_exists( 'NPT_SMS' ) && NPT_SMS::otp_ready();
	$visits  = (int) get_option( 'npt_visits_' . wp_date( 'Ymd' ), 0 );
	$total   = (int) get_option( 'npt_visits_total', 0 );
	$counts  = $wc_on ? wp_count_posts( 'product' ) : null;
	$orders  = 0;
	if ( $wc_on && function_exists( 'wc_get_orders' ) ) {
		$orders = count( wc_get_orders( array( 'status' => array( 'processing', 'on-hold', 'pending' ), 'limit' => -1, 'return' => 'ids' ) ) );
	}
	$pf_total = 0;
	$pf_week  = 0;
	$contacts = 0;
	if ( class_exists( 'NPT_Proforma' ) ) {
		$pf_table = NPT_Proforma::table_proformas();
		if ( $pf_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pf_table ) ) ) { // phpcs:ignore
			$pf_total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $pf_table ); // phpcs:ignore
			$pf_week  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $pf_table . ' WHERE created_at >= %s', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		}
	}
	if ( class_exists( 'NPT_SMS' ) ) {
		$c_table = NPT_SMS::table_contacts();
		if ( $c_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $c_table ) ) ) { // phpcs:ignore
			$contacts = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $c_table ); // phpcs:ignore
		}
	}
	$errors   = trim( (string) ( class_exists( 'NPT_Log' ) ? NPT_Log::text() : '' ) );
	$activity = array_slice( (array) get_option( 'npt_activity', array() ), 0, 8 );

	$stats = array(
		array( 'بازدید امروز', number_format_i18n( $visits ), true ),
		array( 'کل بازدید', number_format_i18n( $total ), true ),
		array( 'محصولات منتشرشده', $counts ? number_format_i18n( (int) $counts->publish ) : '—', true ),
		array( 'سفارش‌های جاری', number_format_i18n( $orders ), true ),
		array( 'پیش‌فاکتورها', number_format_i18n( $pf_total ) . ' (' . number_format_i18n( $pf_week ) . ' در ۷ روز)', true ),
		array( 'مخاطبین پیامک', number_format_i18n( $contacts ), true ),
		array( 'ووکامرس', $wc_on ? 'فعال' : 'غیرفعال', $wc_on ),
		array( 'سرویس پیامک', $sms_on ? 'آماده' : 'تنظیم نشده', $sms_on ),
		array( 'خطاهای ثبت‌شده', '' === $errors ? 'ندارد' : 'دارد', '' === $errors ),
	);
	$tiles = array(
		array( 'محصولات', admin_url( 'edit.php?post_type=product' ), 'dashicons-products' ),
		array( 'سفارش‌ها', admin_url( 'admin.php?page=wc-orders' ), 'dashicons-cart' ),
		array( 'منوها', admin_url( 'nav-menus.php' ), 'dashicons-menu' ),
		array( 'پنل مدیر', function_exists( 'npt_panel_url' ) ? npt_panel_url() : home_url( '/' ), 'dashicons-businessperson' ),
		array( 'جدول قیمت', $base . '&tab=price', 'dashicons-list-view' ),
		array( 'پیامک', $base . '&tab=sms', 'dashicons-email-alt' ),
		array( 'پشتیبان', $base . '&tab=backup', 'dashicons-backup' ),
		array( 'گزارش خطاها', $base . '&tab=errors', 'dashicons-warning' ),
	);

	navid_admin_shell_open( 'overview', '', false );
	?>
	<h2 class="navid-sec-title">گزارش کلی</h2>
	<div class="navid-stats">
		<?php foreach ( $stats as $stat ) : ?>
			<div class="navid-stat <?php echo $stat[2] ? 'is-ok' : 'is-bad'; ?>"><b><?php echo esc_html( $stat[1] ); ?></b><span><?php echo esc_html( $stat[0] ); ?></span></div>
		<?php endforeach; ?>
	</div>

	<div class="navid-admin-card">
		<h2>آخرین فعالیت مدیران</h2>
		<?php if ( $activity ) : ?>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $activity as $row ) : ?>
					<tr><td dir="ltr" style="width:150px"><?php echo esc_html( isset( $row['t'] ) ? $row['t'] : '' ); ?></td><td style="width:120px"><?php echo esc_html( isset( $row['user'] ) ? $row['user'] : '' ); ?></td><td><?php echo esc_html( isset( $row['what'] ) ? $row['what'] : '' ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="description">هنوز فعالیتی ثبت نشده است.</p>
		<?php endif; ?>
	</div>

	<h2 class="navid-sec-title">دسترسی سریع</h2>
	<div class="navid-tiles">
		<?php foreach ( $tiles as $tile ) : ?>
			<a class="navid-tile" href="<?php echo esc_url( $tile[1] ); ?>"><span class="dashicons <?php echo esc_attr( $tile[2] ); ?>"></span><?php echo esc_html( $tile[0] ); ?></a>
		<?php endforeach; ?>
	</div>
	<?php
	navid_admin_shell_close();
}
