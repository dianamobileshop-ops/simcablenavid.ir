<?php
/**
 * Single post.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="navid-wrap">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'navid-content' ); ?>>
			<h1><?php the_title(); ?></h1>
			<p class="navid-entry-meta"><?php echo esc_html( get_the_date() ); ?></p>
			<?php if ( has_post_thumbnail() ) : ?>
				<div class="navid-entry-thumb"><?php the_post_thumbnail( 'large' ); ?></div>
			<?php endif; ?>
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
<?php
get_footer();
