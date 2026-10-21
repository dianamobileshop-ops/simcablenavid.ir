<?php
/**
 * Page.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<?php if ( function_exists( 'is_account_page' ) && is_account_page() ) : ?>
<div class="navid-wrap navid-account">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</div>
<?php else : ?>
<div class="navid-wrap">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'navid-content' ); ?>>
			<h1><?php the_title(); ?></h1>
			<div class="navid-entry-body"><?php the_content(); ?></div>
			<?php wp_link_pages(); ?>
		</article>
		<?php
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
	endwhile;
	?>
</div>
<?php endif; ?>
<?php
get_footer();
