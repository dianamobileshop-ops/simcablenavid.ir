<?php
/**
 * Full-screen template for the page that holds [navid_panel].
 * Uses its own head/body, so the theme header and footer never show on the panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'npt-panel-page' ); ?>>
	<main class="npt-panel-main" style="max-width:none;padding:0;margin:0">
		<?php echo do_shortcode( '[navid_panel]' ); // phpcs:ignore ?>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
