<?php
/**
 * Backup, restore and update-from-zip (settings → «پشتیبان و بروزرسانی»).
 *
 * The backup is one JSON file with the plugin's settings and its four
 * database tables (proformas, customers, SMS contacts, SMS log). Secrets and
 * per-site data are deliberately left out: the SMS password, the push private
 * key and device subscriptions, cache counters and page IDs. After a restore on
 * a fresh site only those few things have to be entered again.
 *
 * Updating takes a zip of a newer plugin version, saves a fresh backup first
 * and then lets WordPress's own plugin upgrader overwrite the plugin folder —
 * the same mechanism as Plugins → Add New → Upload → «Replace current».
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPT_Backup {

	const FORMAT    = 'navid-price-table-backup';
	const KEEP      = 5;
	const MAX_ZIP   = 25 * 1024 * 1024;
	const MAX_JSON  = 64 * 1024 * 1024;
	const PLUGIN_DIR = 'navid-price-table';

	/** Options never exported or imported. */
	private static $skip = array(
		'npt_sms_password',
		'npt_sms_token',
		'npt_push_keys',
		'npt_push_subs',
		'npt_push_last',
		'npt_table_cache_v',
		'npt_panel_ver',
		'npt_panel_page',
		'npt_register_page',
		'npt_proforma_db_ver',
		'npt_sms_db_ver',
		'npt_price_backup',
		'npt_pct_lock',
	);

	public static function init() {
		add_action( 'admin_post_npt_backup_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_npt_backup_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_npt_backup_file', array( __CLASS__, 'handle_file' ) );
		add_action( 'admin_post_npt_backup_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_npt_backup_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_npt_update_zip', array( __CLASS__, 'handle_update' ) );
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------- */
	private static function tables() {
		global $wpdb;
		return array(
			'proformas'    => $wpdb->prefix . 'npt_proformas',
			'customers'    => $wpdb->prefix . 'npt_proforma_customers',
			'sms_contacts' => $wpdb->prefix . 'npt_sms_contacts',
			'sms_log'      => $wpdb->prefix . 'npt_sms_log',
		);
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public static function collect() {
		global $wpdb;
		$options = array();
		$rows    = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'npt\\_%'" ); // phpcs:ignore
		foreach ( (array) $rows as $row ) {
			if ( in_array( $row->option_name, self::$skip, true ) ) {
				continue;
			}
			$options[ $row->option_name ] = maybe_unserialize( $row->option_value );
		}

		$tables = array();
		foreach ( self::tables() as $key => $table ) {
			$tables[ $key ] = self::table_exists( $table )
				? $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ) // phpcs:ignore
				: array();
		}

		return array(
			'format'   => self::FORMAT,
			'version'  => NPT_VERSION,
			'created'  => gmdate( 'c' ),
			'site'     => home_url( '/' ),
			'options'  => $options,
			'tables'   => $tables,
		);
	}

	private static function encode( array $data ) {
		return wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}

	/* ---------------------------------------------------------------------
	 * Server-side backup folder
	 * ------------------------------------------------------------------- */
	private static function dir() {
		$up  = wp_upload_dir();
		$old = trailingslashit( $up['basedir'] ) . 'npt-backups';
		$dir = $old;
		// Preferred: one folder above the web root (not reachable by URL at all).
		$private = dirname( untrailingslashit( ABSPATH ) ) . '/npt-private-backups';
		if ( ! is_dir( $private ) ) {
			wp_mkdir_p( $private );
		}
		if ( is_dir( $private ) && wp_is_writable( $private ) ) {
			$dir = $private;
			// Move backups made by older versions out of the uploads folder.
			if ( is_dir( $old ) ) {
				foreach ( (array) glob( $old . '/npt-*.json' ) as $path ) {
					@rename( $path, $dir . '/' . basename( $path ) ); // phpcs:ignore
				}
			}
		}
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) ) {
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			}
			if ( ! file_exists( $dir . '/index.html' ) ) {
				file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
			}
		}
		return $dir;
	}

	/** Save a backup file on the server; returns the file name or ''. */
	public static function save_file( $label = 'manual' ) {
		$dir = self::dir();
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return '';
		}
		$name = sprintf( 'npt-%s-%s-%s.json', sanitize_key( $label ), gmdate( 'Ymd-His' ), strtolower( wp_generate_password( 10, false, false ) ) );
		if ( false === file_put_contents( $dir . '/' . $name, self::encode( self::collect() ) ) ) { // phpcs:ignore
			return '';
		}
		return $name;
	}

	/** Newest first. */
	public static function files() {
		$dir = self::dir();
		$out = array();
		foreach ( (array) glob( $dir . '/npt-*.json' ) as $path ) {
			$out[] = array(
				'name' => basename( $path ),
				'time' => (int) filemtime( $path ),
				'size' => (int) filesize( $path ),
			);
		}
		usort( $out, function ( $a, $b ) {
			return $b['time'] - $a['time'];
		} );
		return $out;
	}

	private static function safe_name( $name ) {
		$name = basename( (string) $name );
		return preg_match( '/^npt-[a-z0-9]+-\d{8}-\d{6}-[a-z0-9]+\.json$/', $name ) ? $name : '';
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */
	private static function guard( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی لازم برای این عملیات را ندارید.', 'navid-price-table' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function back() {
		return admin_url( 'admin.php?page=navid-settings&tab=backup' );
	}

	private static function notify( $type, $text ) {
		if ( 'error' === $type ) {
			NPT_Log::add( 'پشتیبان', $text );
		}
		set_transient( 'npt_bk_msg_' . get_current_user_id(), array( $type, $text ), 120 );
		wp_safe_redirect( self::back() );
		exit;
	}

	private static function send_download( $json, $filename ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore
		exit;
	}

	public static function handle_download() {
		self::guard( 'npt_backup_download' );
		self::send_download( self::encode( self::collect() ), 'navid-price-table-backup-' . gmdate( 'Y-m-d-His' ) . '.json' );
	}

	public static function handle_save() {
		self::guard( 'npt_backup_save' );
		$name = self::save_file( 'manual' );
		self::notify( $name ? 'success' : 'error', $name ? 'پشتیبان روی سرور ذخیره شد.' : 'ذخیره‌ی پشتیبان روی سرور ممکن نبود (پوشه‌ی uploads قابل‌نوشتن نیست).' );
	}

	public static function handle_file() {
		self::guard( 'npt_backup_file' );
		$name = self::safe_name( isset( $_GET['f'] ) ? wp_unslash( $_GET['f'] ) : '' ); // phpcs:ignore
		$path = $name ? self::dir() . '/' . $name : '';
		if ( ! $path || ! is_file( $path ) ) {
			self::notify( 'error', 'فایل پشتیبان پیدا نشد.' );
		}
		self::send_download( (string) file_get_contents( $path ), $name ); // phpcs:ignore
	}

	public static function handle_delete() {
		self::guard( 'npt_backup_delete' );
		$name = self::safe_name( isset( $_GET['f'] ) ? wp_unslash( $_GET['f'] ) : '' ); // phpcs:ignore
		$path = $name ? self::dir() . '/' . $name : '';
		if ( $path && is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore
		}
		self::notify( 'success', 'پشتیبان حذف شد.' );
	}

	public static function handle_restore() {
		self::guard( 'npt_backup_restore' );
		$tmp = ! empty( $_FILES['npt_backup_file']['tmp_name'] ) ? $_FILES['npt_backup_file']['tmp_name'] : ''; // phpcs:ignore
		if ( ! $tmp || ! is_uploaded_file( $tmp ) || filesize( $tmp ) > self::MAX_JSON ) {
			self::notify( 'error', 'فایل پشتیبان انتخاب نشده یا بیش از حد بزرگ است.' );
		}
		$data = json_decode( (string) file_get_contents( $tmp ), true ); // phpcs:ignore
		if ( ! is_array( $data ) || ( isset( $data['format'] ) ? $data['format'] : '' ) !== self::FORMAT || ! isset( $data['options'], $data['tables'] ) ) {
			self::notify( 'error', 'این فایل، پشتیبان این افزونه نیست.' );
		}

		// A restore replaces data, so the current state is saved first.
		self::save_file( 'before-restore' );

		$res = self::restore( $data );
		self::notify( 'success', sprintf( 'بازیابی انجام شد: %d تنظیم و %d ردیف جدول. (نسخه‌ی پشتیبان: %s)', $res['options'], $res['rows'], isset( $data['version'] ) ? $data['version'] : '؟' ) );
	}

	public static function restore( array $data ) {
		global $wpdb;
		$n_opt = 0;
		foreach ( (array) $data['options'] as $key => $value ) {
			$key = (string) $key;
			if ( 0 !== strpos( $key, 'npt_' ) || in_array( $key, self::$skip, true ) ) {
				continue;
			}
			update_option( $key, $value ); // existing autoload flag is kept
			$n_opt++;
		}

		// Make sure the tables exist before filling them.
		if ( class_exists( 'NPT_Proforma' ) ) {
			delete_option( NPT_Proforma::DB_VER_OPT );
			NPT_Proforma::maybe_install();
		}
		if ( class_exists( 'NPT_SMS' ) ) {
			delete_option( NPT_SMS::DB_VER_OPT );
			NPT_SMS::maybe_install();
		}

		$n_rows = 0;
		foreach ( self::tables() as $key => $table ) {
			if ( ! isset( $data['tables'][ $key ] ) || ! is_array( $data['tables'][ $key ] ) || ! self::table_exists( $table ) ) {
				continue;
			}
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore
			$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore
			foreach ( $data['tables'][ $key ] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = array_intersect_key( $row, array_flip( $cols ) );
				if ( $clean && $wpdb->insert( $table, $clean ) ) {
					$n_rows++;
				}
			}
		}

		// Keep proforma numbering ahead of whatever was restored.
		if ( class_exists( 'NPT_Proforma' ) ) {
			$max = (int) $wpdb->get_var( 'SELECT MAX(number) FROM ' . NPT_Proforma::table_proformas() ); // phpcs:ignore
			if ( $max >= (int) get_option( NPT_Proforma::SEQ_OPTION, 0 ) ) {
				update_option( NPT_Proforma::SEQ_OPTION, $max + 1, false );
			}
		}
		if ( function_exists( 'npt_bump_table_cache_version' ) ) {
			npt_bump_table_cache_version();
		}
		return array( 'options' => $n_opt, 'rows' => $n_rows );
	}

	/** Version header of the uploaded zip's main file, or '' if it isn't this plugin. */
	private static function zip_version( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip', 'افزونه‌ی ZipArchive روی سرور فعال نیست.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip', 'فایل zip خراب است.' );
		}
		$main = $zip->getFromName( self::PLUGIN_DIR . '/navid-price-table.php' );
		$zip->close();
		if ( false === $main || ! preg_match( '/^[ \t\/*#@]*Plugin Name:\s*Navid Price Table/mi', $main ) ) {
			return new WP_Error( 'zip', 'این فایل بسته‌ی افزونه‌ی «جدول قیمت نوید» نیست (پوشه‌ی navid-price-table/ پیدا نشد).' );
		}
		return preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', $main, $m ) ? $m[1] : '';
	}

	public static function handle_update() {
		self::guard( 'npt_update_zip' );
		if ( defined( 'NAVID_VERSION' ) ) {
			// Inside the theme the plugin is updated with the theme, not from a zip.
			self::notify( 'error', 'بروزرسانی از فایل zip در حالت قالب فعال نیست؛ قالب را بروزرسانی کنید.' );
		}
		if ( basename( untrailingslashit( NPT_PATH ) ) !== self::PLUGIN_DIR ) {
			self::notify( 'error', 'پوشه‌ی افزونه روی سرور نام دیگری دارد؛ بروزرسانی خودکار ممکن نیست. از «افزونه‌ها ← افزودن ← بارگذاری» استفاده کنید.' );
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			self::notify( 'error', 'تغییر فایل‌ها در این سایت قفل است (DISALLOW_FILE_MODS).' );
		}
		$tmp = ! empty( $_FILES['npt_update_file']['tmp_name'] ) ? $_FILES['npt_update_file']['tmp_name'] : ''; // phpcs:ignore
		if ( ! $tmp || ! is_uploaded_file( $tmp ) || filesize( $tmp ) > self::MAX_ZIP ) {
			self::notify( 'error', 'فایل zip انتخاب نشده یا بیش از حد بزرگ است.' );
		}
		$version = self::zip_version( $tmp );
		if ( is_wp_error( $version ) ) {
			self::notify( 'error', $version->get_error_message() );
		}
		if ( '' !== $version && version_compare( $version, NPT_VERSION, '<' ) && empty( $_POST['npt_allow_downgrade'] ) ) {
			self::notify( 'error', sprintf( 'نسخه‌ی فایل (%s) قدیمی‌تر از نسخه‌ی نصب‌شده (%s) است. برای نصب نسخه‌ی قدیمی‌تر، گزینه‌ی «اجازه‌ی نسخه‌ی پایین‌تر» را بزنید.', $version, NPT_VERSION ) );
		}

		// Safety net first: if anything goes wrong, the data is on the server.
		$saved = self::save_file( 'before-update' );
		if ( '' === $saved ) {
			self::notify( 'error', 'پیش از بروزرسانی نتوانستم پشتیبان بگیرم؛ بروزرسانی انجام نشد.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		WP_Filesystem();

		$was_active = is_plugin_active( plugin_basename( NPT_PATH . 'navid-price-table.php' ) );
		$skin       = new Automatic_Upgrader_Skin();
		$upgrader   = new Plugin_Upgrader( $skin );
		$result     = $upgrader->install( $tmp, array( 'overwrite_package' => true ) );

		if ( is_wp_error( $result ) ) {
			self::notify( 'error', 'بروزرسانی ناموفق بود: ' . $result->get_error_message() . ' (پشتیبان قبلی روی سرور ذخیره است.)' );
		}
		if ( ! $result ) {
			$errors = $skin->get_errors();
			self::notify( 'error', 'بروزرسانی ناموفق بود' . ( is_wp_error( $errors ) && $errors->has_errors() ? ': ' . $errors->get_error_message() : '.' ) );
		}
		if ( $was_active ) {
			activate_plugin( plugin_basename( NPT_PATH . 'navid-price-table.php' ) );
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore
		}
		delete_option( 'npt_panel_ver' ); // re-run installers on the next admin load
		self::notify( 'success', sprintf( 'افزونه به نسخه‌ی %s بروزرسانی شد. پشتیبان پیش از بروزرسانی ذخیره شد.', $version ? $version : 'جدید' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings tab
	 * ------------------------------------------------------------------- */
	public static function render() {
		$msg = get_transient( 'npt_bk_msg_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'npt_bk_msg_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'success' === $msg[0] ? 'success' : 'error', esc_html( $msg[1] ) );
		}
		$post = esc_url( admin_url( 'admin-post.php' ) );
		$files = self::files();
		?>
		<h2>پشتیبان‌گیری</h2>
		<p class="description">یک فایل JSON شامل تنظیمات افزونه، آرشیو پیش‌فاکتورها و مشتریان، مخاطبین و گزارش پیامک. رمز پنل پیامک و کلیدهای اعلان پوش در فایل نیست و پس از بازیابی روی سایت جدید باید دوباره وارد/فعال شود.</p>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" style="display:inline-block;margin-inline-end:8px">
			<?php wp_nonce_field( 'npt_backup_download' ); ?>
			<input type="hidden" name="action" value="npt_backup_download">
			<button type="submit" class="button button-primary">دانلود پشتیبان کامل</button>
		</form>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" style="display:inline-block">
			<?php wp_nonce_field( 'npt_backup_save' ); ?>
			<input type="hidden" name="action" value="npt_backup_save">
			<button type="submit" class="button">ذخیره‌ی یک نسخه روی سرور</button>
		</form>

		<?php if ( $files ) : ?>
			<h3>پشتیبان‌های ذخیره‌شده روی سرور (همه‌ی نسخه‌ها، تا وقتی خودتان حذف کنید)</h3>
			<table class="widefat striped" style="max-width:760px">
				<thead><tr><th>تاریخ</th><th>نوع</th><th>حجم</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $files as $f ) :
					$kind = false !== strpos( $f['name'], 'before-update' ) ? 'قبل از بروزرسانی' : ( false !== strpos( $f['name'], 'before-restore' ) ? 'قبل از بازیابی' : 'دستی' );
					?>
					<tr>
						<td><?php echo esc_html( npt_to_persian_digits( npt_format_date( gmdate( 'Y-m-d H:i:s', $f['time'] ) ) ) ); ?></td>
						<td><?php echo esc_html( $kind ); ?></td>
						<td><?php echo esc_html( size_format( $f['size'] ) ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'npt_backup_file', 'f' => $f['name'] ), admin_url( 'admin-post.php' ) ), 'npt_backup_file' ) ); ?>">دانلود</a>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'npt_backup_delete', 'f' => $f['name'] ), admin_url( 'admin-post.php' ) ), 'npt_backup_delete' ) ); ?>" onclick="return confirm('این پشتیبان حذف شود؟');">حذف</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<hr>
		<h2>بازیابی از فایل پشتیبان</h2>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" enctype="multipart/form-data" onsubmit="return confirm('تنظیمات و جدول‌های فعلی با محتوای فایل جایگزین می‌شوند (پیش از آن یک نسخه از وضعیت فعلی ذخیره می‌شود). ادامه می‌دهید؟');">
			<?php wp_nonce_field( 'npt_backup_restore' ); ?>
			<input type="hidden" name="action" value="npt_backup_restore">
			<input type="file" name="npt_backup_file" accept=".json,application/json" required>
			<button type="submit" class="button">بازیابی</button>
		</form>

		<hr>
		<h2>بروزرسانی افزونه</h2>
		<p>نسخه‌ی نصب‌شده: <strong dir="ltr"><?php echo esc_html( NPT_VERSION ); ?></strong></p>
		<p class="description">فایل zip نسخه‌ی جدید را انتخاب کنید. پیش از جایگزینی فایل‌ها خودکار یک پشتیبان روی سرور ذخیره می‌شود و اگر چیزی خراب شود، می‌توانید همان را بازیابی کنید.</p>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" enctype="multipart/form-data" onsubmit="return confirm('افزونه با فایل انتخاب‌شده جایگزین می‌شود. ادامه می‌دهید؟');">
			<?php wp_nonce_field( 'npt_update_zip' ); ?>
			<input type="hidden" name="action" value="npt_update_zip">
			<input type="file" name="npt_update_file" accept=".zip,application/zip" required>
			<label style="margin-inline-start:8px"><input type="checkbox" name="npt_allow_downgrade" value="1"> اجازه‌ی نسخه‌ی پایین‌تر</label>
			<button type="submit" class="button button-primary">بروزرسانی</button>
		</form>
		<?php
	}
}
