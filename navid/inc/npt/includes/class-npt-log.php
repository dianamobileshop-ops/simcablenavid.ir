<?php
/**
 * Error log shown in settings → گزارش خطاها, so the owner can copy and send it.
 * Only short, already-sanitized messages are stored: never passwords or tokens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPT_Log {

	const OPT     = 'npt_error_log';
	const MAX     = 200;
	const ACT_OPT = 'npt_activity';
	const ACT_MAX = 300;

	public static function init() {
		add_action( 'admin_post_npt_log_clear', array( __CLASS__, 'handle_clear' ) );
	}

	public static function add( $area, $message ) {
		$list = get_option( self::OPT, array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		array_unshift( $list, array(
			't'    => current_time( 'Y-m-d H:i:s' ),
			'area' => sanitize_text_field( $area ),
			'msg'  => mb_substr( trim( wp_strip_all_tags( (string) $message ) ), 0, 400 ),
		) );
		update_option( self::OPT, array_slice( $list, 0, self::MAX ), false );
	}

	/** Records one action with the name of the logged-in manager (or «سیستم»). */
	public static function activity( $what ) {
		$user = wp_get_current_user();
		$name = ( $user && $user->ID ) ? ( $user->display_name ? $user->display_name : $user->user_login ) : 'سیستم';
		$list = get_option( self::ACT_OPT, array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		array_unshift( $list, array(
			't'    => current_time( 'Y-m-d H:i:s' ),
			'user' => sanitize_text_field( $name ),
			'what' => mb_substr( sanitize_text_field( $what ), 0, 200 ),
		) );
		update_option( self::ACT_OPT, array_slice( $list, 0, self::ACT_MAX ), false );
	}

	/** Distinct areas in the log with how many lines each has. */
	public static function areas() {
		$out = array();
		foreach ( (array) get_option( self::OPT, array() ) as $row ) {
			$out[ $row['area'] ] = ( isset( $out[ $row['area'] ] ) ? $out[ $row['area'] ] : 0 ) + 1;
		}
		arsort( $out );
		return $out;
	}

	/** Lines logged in the last 24 hours (for the menu badge). */
	public static function recent_count() {
		$since = strtotime( '-1 day', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$n     = 0;
		foreach ( (array) get_option( self::OPT, array() ) as $row ) {
			if ( strtotime( $row['t'] ) >= $since ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Support text: system report plus the log lines (all, or one area).
	 *
	 * @param string $area Area name, '' for all.
	 * @return string
	 */
	public static function text( $area = '' ) {
		$list  = get_option( self::OPT, array() );
		$lines = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( '' !== $area && $row['area'] !== $area ) {
				continue;
			}
			$lines[] = '[' . $row['t'] . '] ' . $row['area'] . ': ' . $row['msg'];
		}
		$report = function_exists( 'navid_system_report' ) ? navid_system_report() : '';
		$body   = $lines ? implode( "\n", $lines ) : '';
		return '' !== $report ? $report . "\n\n— خطاها" . ( '' !== $area ? ' (' . $area . ')' : '' ) . " —\n" . ( $body ? $body : 'خطایی ثبت نشده است.' ) : $body;
	}

	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'npt_log_clear' );
		delete_option( self::OPT );
		wp_safe_redirect( admin_url( 'admin.php?page=navid-settings&tab=errors' ) );
		exit;
	}

	/** The settings panel: read-only text with one copy button, plus clear. */
	public static function render() {
		$area  = isset( $_GET['area'] ) ? sanitize_text_field( wp_unslash( $_GET['area'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$areas = self::areas();
		if ( '' !== $area && ! isset( $areas[ $area ] ) ) {
			$area = '';
		}
		$text = self::text( $area );
		$base = admin_url( 'admin.php?page=navid-settings&tab=errors' );
		?>
		<h2>گزارش خطاها</h2>
		<p class="navid-area-tabs" style="display:flex;flex-wrap:wrap;gap:6px">
			<a class="button<?php echo '' === $area ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $base ); ?>">همه (<?php echo (int) array_sum( $areas ); ?>)</a>
			<?php foreach ( $areas as $name => $count ) : ?>
				<a class="button<?php echo $name === $area ? ' button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'area', rawurlencode( $name ), $base ) ); ?>"><?php echo esc_html( $name ); ?> (<?php echo (int) $count; ?>)</a>
			<?php endforeach; ?>
		</p>
		<p class="description">همهٔ خطاهای سایت این‌جا جمع می‌شود: خطای PHP قالب، خطای جاوااسکریپت مرورگر بازدیدکنندگان، پیامک، درگاه، سفارش ناموفق، ایمیل، پیش‌فاکتور، پشتیبان و بروزرسانی. «کپی گزارش» را بزنید و برای پشتیبانی بفرستید.</p>
		<textarea id="npt-log-text" readonly rows="12" style="width:100%;max-width:760px;direction:ltr;text-align:left;font-family:monospace;font-size:12px"><?php echo esc_textarea( $text ); ?></textarea>
		<p>
			<button type="button" class="button button-primary" id="npt-log-copy" <?php echo '' === $text ? 'disabled' : ''; ?>>کپی گزارش برای پشتیبانی</button>
			<span id="npt-log-state" role="status" style="margin-right:8px"></span>
		</p>
		<h3>فعالیت مدیران</h3>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr><th>زمان</th><th>مدیر</th><th>کار</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( (array) get_option( self::ACT_OPT, array() ), 0, 100 ) as $act ) : ?>
				<tr><td dir="ltr"><?php echo esc_html( $act['t'] ); ?></td><td><?php echo esc_html( $act['user'] ); ?></td><td><?php echo esc_html( $act['what'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0">
			<?php wp_nonce_field( 'navid_log_scope' ); ?>
			<input type="hidden" name="action" value="navid_log_scope">
			<label><input type="checkbox" name="all" value="1" <?php checked( '1', (string) get_option( 'navid_log_all_php', '0' ) ); ?>> خطاهای PHP افزونه‌ها (ووکامرس و غیره) را هم ثبت کن</label>
			<button type="submit" class="button">ذخیره</button>
		</form>
		<?php if ( (array) get_option( self::OPT, array() ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('گزارش خطاها پاک شود؟');">
				<?php wp_nonce_field( 'npt_log_clear' ); ?>
				<input type="hidden" name="action" value="npt_log_clear">
				<button type="submit" class="button">پاک‌کردن گزارش</button>
			</form>
		<?php endif; ?>
		<script>
		(function () {
			var btn = document.getElementById('npt-log-copy');
			if (!btn) { return; }
			var ta = document.getElementById('npt-log-text');
			var state = document.getElementById('npt-log-state');
			btn.addEventListener('click', function () {
				ta.select();
				var done = function () { state.textContent = 'کپی شد ✓'; };
				if (navigator.clipboard) {
					navigator.clipboard.writeText(ta.value).then(done, function () { document.execCommand('copy'); done(); });
				} else {
					document.execCommand('copy');
					done();
				}
			});
		}());
		</script>
		<?php
	}
}
