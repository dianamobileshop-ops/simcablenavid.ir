<?php
/**
 * 404 page.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="navid-wrap">
	<section class="navid-content navid-404">
		<h1><?php esc_html_e( 'صفحه پیدا نشد', 'navid' ); ?></h1>
		<p><?php esc_html_e( 'این آدرس وجود ندارد یا جابه‌جا شده است.', 'navid' ); ?></p>
		<p><a class="navid-btn navid-btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'بازگشت به صفحه اصلی', 'navid' ); ?></a></p>
		<?php get_search_form(); ?>
	</section>
</div>
<?php
get_footer();
