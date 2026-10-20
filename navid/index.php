<?php
/**
 * Blog home, archives and fallback: a list of post cards with pagination.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="navid-wrap">
	<?php if ( is_archive() ) : ?>
		<header class="navid-content">
			<?php the_archive_title( '<h1>', '</h1>' ); ?>
			<?php the_archive_description( '<div>', '</div>' ); ?>
		</header>
	<?php endif; ?>

	<?php if ( have_posts() ) : ?>
		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/entry', 'summary' );
		endwhile;
		?>
		<nav class="navid-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی', 'navid' ); ?>">
			<?php
			echo wp_kses_post( paginate_links( array(
				'prev_text' => '→',
				'next_text' => '←',
				'type'      => 'plain',
			) ) );
			?>
		</nav>
	<?php else : ?>
		<?php get_template_part( 'template-parts/none' ); ?>
	<?php endif; ?>
</div>
<?php
get_footer();
