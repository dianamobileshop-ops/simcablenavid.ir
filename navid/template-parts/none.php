<?php
/**
 * Nothing to show (empty search, empty archive).
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="navid-content navid-404">
	<h1><?php esc_html_e( 'چیزی پیدا نشد', 'navid' ); ?></h1>
	<p><?php esc_html_e( 'عبارت دیگری را جستجو کنید.', 'navid' ); ?></p>
	<?php get_search_form(); ?>
</section>
