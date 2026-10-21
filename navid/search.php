<?php
/**
 * Search results.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

if ( 'product' === get_query_var( 'post_type' ) && function_exists( 'wc_get_template_part' ) ) {
	require get_theme_file_path( 'woocommerce/archive-product.php' );
	return;
}

get_header();
?>
<div class="navid-wrap">
	<header class="navid-content">
		<h1><?php echo esc_html( sprintf( 'نتایج جستجو برای «%s»', get_search_query() ) ); ?></h1>
	</header>
	<?php if ( have_posts() ) : ?>
		<div class="navid-blog-grid navid-blog-grid--page">
		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/entry', 'summary' );
		endwhile;
		?>
		</div>
		<nav class="navid-pagination"><?php echo wp_kses_post( paginate_links() ); ?></nav>
	<?php else : ?>
		<?php get_template_part( 'template-parts/none' ); ?>
	<?php endif; ?>
</div>
<?php
get_footer();
