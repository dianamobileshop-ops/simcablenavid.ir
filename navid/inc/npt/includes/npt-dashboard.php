<?php
/**
 * Admin menu + branded wp-login screen.
 *
 * The graphical dashboard page was removed in 1.8.1: the site owner asked for
 * a single «جدول قیمت نوید» entry that opens the tabbed settings page
 * directly, so the top-level menu IS the settings page and every module
 * (price list, bar, SMS, OTP patterns, templates, contacts) is a tab of it.
 *
 * The login section styles wp-login.php (the screen the boss actually
 * types credentials into) with the same brand colors/logo from the
 * plugin settings, so the site feels custom-built end to end.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------
 * Admin menu: the parent entry opens the tabbed settings page
 * ------------------------------------------------------------------- */
// Admin menus are registered by the theme's panel (inc/settings.php).


/**
 * Hook names of the settings screen.
 *
 * The page used to be a submenu of a separate dashboard entry, which named
 * its hook «navid-price-table_page_navid-settings»; now that it
 * is the top-level entry WordPress may name it either of the two below,
 * depending on how the menu was resolved. Both are accepted so the assets
 * never silently stop loading.
 */
function npt_is_settings_hook( $hook ) {
	return 'toplevel_page_navid-settings' === $hook;
}

/* ---------------------------------------------------------------------
 * Branded wp-login screen (same colors/logo as the price list)
 * ------------------------------------------------------------------- */
add_action( 'login_enqueue_scripts', 'npt_login_branding' );
function npt_login_branding() {
	$hrgb = npt_header_color_rgb();
	$hcss = vsprintf( '#%02x%02x%02x', $hrgb );
	$hdark = vsprintf( '#%02x%02x%02x', array( (int) ( $hrgb[0] * 0.5 ), (int) ( $hrgb[1] * 0.5 ), (int) ( $hrgb[2] * 0.5 ) ) );
	$acss = vsprintf( '#%02x%02x%02x', npt_accent_color_rgb() );
	$logo = npt_media_image_url( 'npt_login_logo_id', npt_media_image_url( 'npt_logo_id', NPT_URL . 'assets/images/logo-navid.png' ) );
	?>
	<style>
		@font-face { font-family: 'NPTLoginVazir'; src: url('<?php echo esc_url( NPT_URL . 'vendor/fonts/Vazirmatn-Regular.ttf' ); ?>') format('truetype'); font-weight: 400; font-display: swap; }
		@font-face { font-family: 'NPTLoginVazir'; src: url('<?php echo esc_url( NPT_URL . 'vendor/fonts/Vazirmatn-Bold.ttf' ); ?>') format('truetype'); font-weight: 700; font-display: swap; }
		body.login { direction: rtl; font-family: 'NPTLoginVazir', Tahoma, sans-serif; background: linear-gradient(130deg, <?php echo esc_html( $hcss ); ?> 0%, <?php echo esc_html( $hdark ); ?> 100%); }
		body.login::before { content: ''; position: fixed; inset: 0; background: radial-gradient(600px 300px at 80% 10%, rgba(255,255,255,.10), transparent), radial-gradient(500px 260px at 15% 90%, rgba(255,255,255,.08), transparent); pointer-events: none; }
		#login { width: 380px; padding-top: 7vh; position: relative; }
		#login h1 a { background-image: url('<?php echo esc_url( $logo ); ?>'); background-size: contain; background-position: center; width: 280px; height: 92px; margin: 0 auto 26px; }
		#login form { background: rgba(255,255,255,.98); border: none; border-radius: 16px; box-shadow: 0 22px 50px rgba(0,0,0,.30); padding: 30px 30px 24px; }
		#login label { font-size: 14px; color: #2c3550; }
		#login input[type='text'], #login input[type='password'], #login input[type='email'] { border-radius: 9px; border: 1px solid #d6dbe8; padding: 8px 12px; box-shadow: none; transition: border-color .15s, box-shadow .15s; }
		#login input:focus { border-color: <?php echo esc_html( $hcss ); ?>; box-shadow: 0 0 0 3px <?php echo esc_html( $hcss ); ?>26; }
		#login .button-primary { background: <?php echo esc_html( $hcss ); ?>; border-color: <?php echo esc_html( $hdark ); ?>; border-radius: 9px; box-shadow: none; text-shadow: none; font-family: inherit; font-size: 15px; font-weight: 700; height: 44px; width: 100%; margin-top: 6px; transition: background .15s, transform .15s; }
		#login .button-primary:hover { background: <?php echo esc_html( $hdark ); ?>; transform: translateY(-1px); }
		#login .forgetmenot label { color: #66708a; font-size: 13px; }
		#login #nav, #login #backtoblog { text-align: center; }
		#login #nav a, #login #backtoblog a { color: #fff; font-size: 13.5px; text-shadow: none; }
		#login #nav { margin-top: 18px; }
		#login .language-switcher { margin-top: 8px; }
		#login .language-switcher select { border-radius: 8px; border-color: rgba(255,255,255,.4); background: rgba(255,255,255,.12); color: #fff; }
		.login h1 a:focus, .login .button-primary:focus { box-shadow: 0 0 0 3px <?php echo esc_html( $acss ); ?>55; }
	</style>
	<?php
}

add_action( 'login_head', function () {
	$icon = npt_media_image_url( 'npt_login_icon_id', npt_media_image_url( 'npt_app_icon_id', '' ) );
	if ( $icon ) {
		echo '<link rel="icon" href="' . esc_url( $icon ) . '">' . "\n";
	}
} );

add_filter( 'login_headerurl', function () {
	return home_url( '/' );
} );

add_filter( 'login_headertext', function () {
	return get_bloginfo( 'name' );
} );
