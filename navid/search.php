<?php
/**
 * Search results.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="navid-wrap">
	<header class="navid-content">
		<h1><?php echo esc_html( sprintf( 'نتایج جستجو برای «%s»', get_search_query() ) ); ?></h1>
	</header>
	<?php if ( have_posts() ) : ?>
		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/entry', 'summary' );
		endwhile;
		?>
		<nav class="navid-pagination"><?php echo wp_kses_post( paginate_links() ); ?></nav>
	<?php else : ?>
		<?php get_template_part( 'template-parts/none' ); ?>
	<?php endif; ?>
</div>
<?php
get_footer();
