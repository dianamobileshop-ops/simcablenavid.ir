<?php
/**
 * Theme updates from the site's own server.
 *
 * style.css carries «Update URI: https://simcablenavid.ir/navid». WordPress then asks this
 * file for the newest version: a small JSON file (update.json) next to the zip on the
 * server. Updates appear in Dashboard → Updates like any other theme and install with one
 * click. Build both files with: php tools/build-release.php
 *
 * update.json: { "version": "1.5.2", "package": "https://simcablenavid.ir/navid/navid-theme-1.5.2.zip",
 *                "url": "https://simcablenavid.ir/navid", "changelog": "…" }
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

const NAVID_UPDATE_HOST    = 'simcablenavid.ir';
const NAVID_UPDATE_DEFAULT = 'https://simcablenavid.ir/navid/update.json';

/**
 * Address of update.json (changeable in the support screen).
 *
 * @return string
 */
function navid_update_source() {
	$url = (string) get_option( 'navid_update_url', '' );
	return apply_filters( 'navid_update_source', '' !== $url ? $url : NAVID_UPDATE_DEFAULT );
}

/**
 * The newest release the server announces, cached for 12 hours.
 *
 * @param bool $force Ignore the cache.
 * @return array version, package, url, changelog, checked — or error, checked.
 */
function navid_update_info( $force = false ) {
	$cached = get_transient( 'navid_update_info' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached;
	}
	$source = navid_update_source();
	$info   = array( 'checked' => time() );
	$res    = wp_safe_remote_get( $source, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );
	if ( is_wp_error( $res ) ) {
		$info['error'] = $res->get_error_message();
	} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		$code          = (int) wp_remote_retrieve_response_code( $res );
		$info['error'] = 404 === $code ? 'فایل update.json هنوز روی سرور بروزرسانی گذاشته نشده است.' : 'پاسخ سرور بروزرسانی: ' . $code;
	} else {
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) || empty( $json['version'] ) || empty( $json['package'] ) ) {
			$info['error'] = 'فایل update.json معتبر نیست.';
		} else {
			$package = esc_url_raw( (string) $json['package'], array( 'https' ) );
			$source_host  = wp_parse_url( $source, PHP_URL_HOST );
			$package_host = wp_parse_url( $package, PHP_URL_HOST );
			if ( '' === $package || $package_host !== $source_host ) {
				// A package from another host is never offered.
				$info['error'] = 'آدرس بستهٔ بروزرسانی باید روی همان سرور فایل update.json باشد (و https).';
			} else {
				$info['version']   = sanitize_text_field( (string) $json['version'] );
				$info['package']   = $package;
				$info['url']       = isset( $json['url'] ) ? esc_url_raw( (string) $json['url'] ) : 'https://' . NAVID_UPDATE_HOST;
				$info['changelog'] = isset( $json['changelog'] ) ? sanitize_textarea_field( (string) $json['changelog'] ) : '';
			}
		}
	}
	// A missing update.json is a normal state before the first release is uploaded: no log line.
	if ( isset( $info['error'] ) && false === strpos( $info['error'], 'update.json هنوز' ) ) {
		navid_log( 'بروزرسانی', $info['error'] );
	}
	set_transient( 'navid_update_info', $info, isset( $info['error'] ) ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
	return $info;
}

/**
 * Tells WordPress about the newest version (Update URI hook).
 *
 * @param mixed  $update     Current value.
 * @param array  $theme_data Theme headers.
 * @param string $stylesheet Theme folder.
 * @return mixed
 */
function navid_update_filter( $update, $theme_data, $stylesheet ) {
	if ( get_template() !== $stylesheet && get_stylesheet() !== $stylesheet ) {
		return $update;
	}
	$info = navid_update_info();
	if ( empty( $info['version'] ) || empty( $info['package'] ) ) {
		return $update;
	}
	return array(
		'theme'        => $stylesheet,
		'version'      => $info['version'],
		'url'          => $info['url'],
		'package'      => $info['package'],
		'requires'     => '5.8',
		'requires_php' => '7.4',
	);
}
add_filter( 'update_themes_' . NAVID_UPDATE_HOST, 'navid_update_filter', 10, 3 );

/**
 * Support screen: version, update check, update address, support details, system report.
 */
function navid_render_support_screen() {
	$info    = navid_update_info();
	$newer   = ! empty( $info['version'] ) && version_compare( $info['version'], NAVID_VERSION, '>' );
	$opt     = navid_options();
	$tel     = (string) $opt['phone'];
	$email   = (string) $opt['email'];
	$checked = ! empty( $info['checked'] ) ? human_time_diff( $info['checked'] ) . ' پیش' : '—';
	if ( isset( $_GET['checked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="notice notice-success is-dismissible"><p>بررسی بروزرسانی انجام شد.</p></div>
		<?php
	endif;
	?>
	<section class="navid-admin-card">
		<h2>بروزرسانی قالب</h2>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
				<tr><td style="width:180px">نسخهٔ نصب‌شده</td><td dir="ltr"><?php echo esc_html( NAVID_VERSION ); ?></td></tr>
				<tr><td>آخرین نسخه</td><td dir="ltr"><?php echo esc_html( ! empty( $info['version'] ) ? $info['version'] : '—' ); ?></td></tr>
				<tr><td>وضعیت</td><td class="<?php echo isset( $info['error'] ) ? 'navid-bad' : ( $newer ? 'navid-bad' : 'navid-ok' ); ?>">
					<?php
					if ( isset( $info['error'] ) ) {
						echo esc_html( 'بررسی نشد: ' . $info['error'] );
					} elseif ( $newer ) {
						echo esc_html( 'نسخهٔ جدید آماده است' );
					} else {
						echo esc_html( 'قالب بروز است' );
					}
					?>
				</td></tr>
				<tr><td>آخرین بررسی</td><td><?php echo esc_html( $checked ); ?></td></tr>
			</tbody>
		</table>
		<?php if ( $newer && ! empty( $info['changelog'] ) ) : ?>
			<p class="description" style="white-space:pre-line"><?php echo esc_html( $info['changelog'] ); ?></p>
		<?php endif; ?>
		<p>
			<?php if ( $newer ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>">رفتن به بروزرسانی‌ها</a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=navid_check_update' ), 'navid_check_update' ) ); ?>">بررسی دوبارهٔ بروزرسانی</a>
		</p>
		<p class="description">پیش از هر بروزرسانی از «پشتیبان‌گیری و بازیابی» یک پشتیبان بگیرید. تنظیمات قالب در پایگاه‌داده می‌ماند و با بروزرسانی پاک نمی‌شود.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'navid_update_url' ); ?>
			<input type="hidden" name="action" value="navid_update_url">
			<label for="navid-update-url">آدرس فایل update.json</label><br>
			<input id="navid-update-url" type="url" name="navid_update_url" value="<?php echo esc_attr( navid_update_source() ); ?>" dir="ltr" style="width:100%;max-width:520px">
			<button type="submit" class="button">ذخیره</button>
		</form>
	</section>

	<section class="navid-admin-card">
		<h2>پشتیبانی</h2>
		<p>برای گزارش مشکل، متن زیر را کپی کنید و برای پشتیبان بفرستید؛ نسخه‌ها، افزونه‌های فعال و آخرین خطاهای قالب در آن هست.</p>
		<?php if ( '' !== $tel || '' !== $email ) : ?>
			<p>
				<?php echo '' !== $tel ? 'تلفن: <strong dir="ltr">' . esc_html( $tel ) . '</strong> ' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo '' !== $email ? 'ایمیل: <strong dir="ltr">' . esc_html( $email ) . '</strong>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>
		<?php endif; ?>
		<textarea id="navid-support-text" readonly rows="10" style="width:100%;max-width:760px;direction:ltr;text-align:left;font-family:monospace;font-size:12px"><?php echo esc_textarea( class_exists( 'NPT_Log' ) ? NPT_Log::text() : navid_system_report() ); ?></textarea>
		<p>
			<button type="button" class="button button-primary" onclick="var t=document.getElementById('navid-support-text');t.select();document.execCommand('copy');this.textContent='کپی شد ✓';">کپی گزارش</button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=navid-settings&tab=errors' ) ); ?>">همهٔ خطاها</a>
		</p>
	</section>
	<?php
}

/**
 * «Check again»: forget the cached answer and ask the server now.
 */
function navid_handle_check_update() {
	if ( ! current_user_can( 'update_themes' ) ) {
		wp_die( esc_html__( 'دسترسی ندارید.', 'navid' ) );
	}
	check_admin_referer( 'navid_check_update' );
	delete_transient( 'navid_update_info' );
	delete_site_transient( 'update_themes' );
	navid_update_info( true );
	wp_update_themes();
	wp_safe_redirect( admin_url( 'admin.php?page=navid-settings&tab=t-support&checked=1' ) );
	exit;
}
add_action( 'admin_post_navid_check_update', 'navid_handle_check_update' );

/**
 * Saves the address of update.json.
 */
function navid_handle_update_url() {
	if ( ! current_user_can( 'update_themes' ) ) {
		wp_die( esc_html__( 'دسترسی ندارید.', 'navid' ) );
	}
	check_admin_referer( 'navid_update_url' );
	$url = isset( $_POST['navid_update_url'] ) ? esc_url_raw( wp_unslash( $_POST['navid_update_url'] ), array( 'https' ) ) : '';
	update_option( 'navid_update_url', $url, false );
	delete_transient( 'navid_update_info' );
	wp_safe_redirect( admin_url( 'admin.php?page=navid-settings&tab=t-support&checked=1' ) );
	exit;
}
add_action( 'admin_post_navid_update_url', 'navid_handle_update_url' );
