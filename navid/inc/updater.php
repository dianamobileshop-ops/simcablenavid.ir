<?php
/**
 * Theme updates from GitHub Releases.
 *
 * style.css carries an «Update URI» header, so WordPress asks this file about new versions.
 * The newest GitHub Release of the repository is read (every 12 hours) and its
 * navid-theme-X.zip asset is offered in Dashboard → Updates, installed with one click.
 *
 * A private repository needs a read-only token (fine-grained, Contents: read, only this
 * repository). The token is kept in the database, never printed, and only ever sent to
 * api.github.com; the file itself is fetched from GitHub's download host without it.
 *
 * Releases are built by .github/workflows/release.yml when a tag like v1.5.4 is pushed.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

const NAVID_UPDATE_HOST    = 'simcablenavid.ir';
const NAVID_GITHUB_DEFAULT = 'dianamobileshop-ops/simcablenavid.ir';

/**
 * «owner/name» of the repository that holds the releases.
 *
 * @return string
 */
function navid_github_repo() {
	$repo = trim( (string) get_option( 'navid_github_repo', '' ) );
	return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : NAVID_GITHUB_DEFAULT;
}

/**
 * The saved read-only token ('' for a public repository).
 *
 * @return string
 */
function navid_github_token() {
	return (string) get_option( 'navid_github_token', '' );
}

/**
 * Headers for a GitHub API request.
 *
 * @param bool $with_token Send the token.
 * @return array
 */
function navid_github_headers( $with_token = true ) {
	$headers = array(
		'Accept'               => 'application/vnd.github+json',
		'User-Agent'           => 'Navid-Theme/' . NAVID_VERSION,
		'X-GitHub-Api-Version' => '2022-11-28',
	);
	if ( $with_token && '' !== navid_github_token() ) {
		$headers['Authorization'] = 'Bearer ' . navid_github_token();
	}
	return $headers;
}

/**
 * Plain-language reason for a failed GitHub answer.
 *
 * @param array $res wp_remote_get result.
 * @return string
 */
function navid_github_error( $res ) {
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 401 === $code ) {
		return 'توکن گیت‌هاب نامعتبر یا منقضی است.';
	}
	if ( 403 === $code || 429 === $code ) {
		return 'گیت‌هاب درخواست را نپذیرفت (سقف درخواست یا دسترسی توکن). کمی بعد دوباره تلاش کنید.';
	}
	if ( 404 === $code ) {
		return '' === navid_github_token()
			? 'Release پیدا نشد. اگر مخزن خصوصی است، توکن لازم است؛ اگر عمومی است، هنوز Release منتشر نشده.'
			: 'Release پیدا نشد یا توکن به این مخزن دسترسی ندارد (دسترسی Contents: Read-only لازم است).';
	}
	return 'پاسخ گیت‌هاب: ' . $code;
}

/**
 * The newest release, cached for 12 hours (1 hour after a failure).
 *
 * @param bool $force Ignore the cache.
 * @return array version, package, url, changelog, checked — or error, checked.
 */
function navid_update_info( $force = false ) {
	$cached = get_transient( 'navid_update_info' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached;
	}
	$repo = navid_github_repo();
	$info = array( 'checked' => time() );
	$res  = wp_safe_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', array( 'timeout' => 10, 'headers' => navid_github_headers() ) );
	if ( is_wp_error( $res ) ) {
		$info['error'] = 'اتصال به گیت‌هاب برقرار نشد: ' . $res->get_error_message();
	} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		$info['error'] = navid_github_error( $res );
	} else {
		$json    = json_decode( wp_remote_retrieve_body( $res ), true );
		$version = is_array( $json ) && ! empty( $json['tag_name'] ) ? ltrim( sanitize_text_field( (string) $json['tag_name'] ), 'vV' ) : '';
		$asset   = null;
		foreach ( is_array( $json ) && isset( $json['assets'] ) ? (array) $json['assets'] : array() as $candidate ) {
			if ( isset( $candidate['name'] ) && preg_match( '/^navid-theme-[0-9A-Za-z.\-]+\.zip$/', (string) $candidate['name'] ) ) {
				$asset = $candidate;
				break;
			}
		}
		if ( '' === $version || ! $asset ) {
			$info['error'] = 'آخرین Release فایل navid-theme-X.zip ندارد.';
		} else {
			// Private repository: the API address of the asset (downloaded with the token, see
			// navid_pre_download). Public: the ordinary download link.
			$package = '' !== navid_github_token() ? (string) $asset['url'] : (string) $asset['browser_download_url'];
			$info['version']   = $version;
			$info['package']   = esc_url_raw( $package, array( 'https' ) );
			$info['url']       = isset( $json['html_url'] ) ? esc_url_raw( (string) $json['html_url'] ) : 'https://github.com/' . $repo;
			$info['changelog'] = isset( $json['body'] ) ? mb_substr( sanitize_textarea_field( (string) $json['body'] ), 0, 600 ) : '';
		}
	}
	if ( isset( $info['error'] ) ) {
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
 * Downloads the zip of a private release: the token goes to api.github.com only, which
 * answers with a short-lived address on GitHub's download host; that address is fetched
 * without the token (it refuses a request that carries both).
 *
 * @param mixed  $reply   Current value.
 * @param string $package Package address.
 * @return mixed false to carry on, a temp file path, or WP_Error.
 */
function navid_pre_download( $reply, $package ) {
	$prefix = 'https://api.github.com/repos/' . navid_github_repo() . '/releases/assets/';
	if ( 0 !== strpos( (string) $package, $prefix ) ) {
		return $reply;
	}
	$headers           = navid_github_headers();
	$headers['Accept'] = 'application/octet-stream';
	$res               = wp_remote_get( $package, array( 'timeout' => 20, 'redirection' => 0, 'headers' => $headers ) );
	if ( is_wp_error( $res ) ) {
		navid_log( 'بروزرسانی', 'دانلود: ' . $res->get_error_message() );
		return $res;
	}
	$code     = (int) wp_remote_retrieve_response_code( $res );
	$location = (string) wp_remote_retrieve_header( $res, 'location' );
	$host     = (string) wp_parse_url( $location, PHP_URL_HOST );
	if ( ! in_array( $code, array( 301, 302, 303, 307 ), true ) || 0 !== strpos( $location, 'https://' ) || ! preg_match( '/(^|\.)(githubusercontent\.com|github\.com)$/', $host ) ) {
		$message = 'دانلود فایل از گیت‌هاب ممکن نشد (' . $code . ').';
		navid_log( 'بروزرسانی', $message );
		return new WP_Error( 'navid_download', $message );
	}
	return download_url( $location, 300 );
}
add_filter( 'upgrader_pre_download', 'navid_pre_download', 10, 2 );

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
			<?php wp_nonce_field( 'navid_update_settings' ); ?>
			<input type="hidden" name="action" value="navid_update_settings">
			<p>
				<label for="navid-gh-repo">مخزن گیت‌هاب (نام‌کاربری/نام‌مخزن)</label><br>
				<input id="navid-gh-repo" type="text" name="navid_github_repo" value="<?php echo esc_attr( navid_github_repo() ); ?>" dir="ltr" style="width:100%;max-width:420px">
			</p>
			<p>
				<label for="navid-gh-token">توکن فقط‌خواندنی (فقط برای مخزن خصوصی)</label><br>
				<input id="navid-gh-token" type="password" name="navid_github_token" value="" autocomplete="new-password" dir="ltr" placeholder="<?php echo '' !== navid_github_token() ? '•••••••• (ذخیره شده؛ برای تغییر، توکن تازه را بنویسید)' : 'github_pat_…'; ?>" style="width:100%;max-width:420px">
				<?php if ( '' !== navid_github_token() ) : ?>
					<label style="display:block;margin-top:6px"><input type="checkbox" name="navid_github_token_clear" value="1"> حذف توکن ذخیره‌شده</label>
				<?php endif; ?>
			</p>
			<button type="submit" class="button">ذخیره</button>
			<p class="description">توکن را در گیت‌هاب بسازید: Settings ← Developer settings ← Fine-grained tokens. فقط همین مخزن را انتخاب کنید و دسترسی «Contents: Read-only» بدهید. توکن فقط به api.github.com فرستاده می‌شود.</p>
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
 * Saves the repository name and the token.
 */
function navid_handle_update_settings() {
	if ( ! current_user_can( 'update_themes' ) ) {
		wp_die( esc_html__( 'دسترسی ندارید.', 'navid' ) );
	}
	check_admin_referer( 'navid_update_settings' );
	$repo = isset( $_POST['navid_github_repo'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['navid_github_repo'] ) ) ) : '';
	update_option( 'navid_github_repo', preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '', false );
	$token = isset( $_POST['navid_github_token'] ) ? trim( (string) wp_unslash( $_POST['navid_github_token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( ! empty( $_POST['navid_github_token_clear'] ) ) {
		delete_option( 'navid_github_token' );
	} elseif ( '' !== $token && preg_match( '/^[A-Za-z0-9_]{20,255}$/', $token ) ) {
		update_option( 'navid_github_token', $token, false );
	}
	delete_transient( 'navid_update_info' );
	delete_site_transient( 'update_themes' );
	wp_safe_redirect( admin_url( 'admin.php?page=navid-settings&tab=t-support&checked=1' ) );
	exit;
}
add_action( 'admin_post_navid_update_settings', 'navid_handle_update_settings' );
