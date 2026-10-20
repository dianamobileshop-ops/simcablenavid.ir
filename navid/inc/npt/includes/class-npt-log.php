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
	const MAX     = 50;
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

	public static function text() {
		$list  = get_option( self::OPT, array() );
		$lines = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			$lines[] = '[' . $row['t'] . '] ' . $row['area'] . ': ' . $row['msg'];
		}
		return implode( "\n", $lines );
	}

	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'npt_log_clear' );
		delete_option( self::OPT );
		wp_safe_redirect( admin_url( 'admin.php?page=navid-price-table-settings&tab=errors' ) );
		exit;
	}

	/** The settings panel: read-only text with one copy button, plus clear. */
	public static function render() {
		$text = self::text();
		?>
		<h2>گزارش خطاها</h2>
		<p class="description">اگر جایی خطایی دیدید (پیامک، ذخیره‌ی پیش‌فاکتور، پشتیبان، بروزرسانی)، این‌جا ثبت می‌شود. «کپی همه» را بزنید و متن را برای پشتیبانی بفرستید.</p>
		<textarea id="npt-log-text" readonly rows="12" style="width:100%;max-width:760px;direction:ltr;text-align:left;font-family:monospace;font-size:12px"><?php echo esc_textarea( $text ); ?></textarea>
		<p>
			<button type="button" class="button button-primary" id="npt-log-copy" <?php echo '' === $text ? 'disabled' : ''; ?>>کپی همه‌ی خطاها</button>
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
		<?php if ( '' !== $text ) : ?>
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
