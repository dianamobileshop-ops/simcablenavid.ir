<?php
/**
 * Single post.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="navid-wrap navid-single">
	<?php
	while ( have_posts() ) :
		the_post();
		$navid_cats = get_the_category();
		?>
		<article <?php post_class( 'navid-article' ); ?>>
			<header class="navid-article-head">
				<?php if ( $navid_cats ) : ?>
					<div class="navid-chips">
						<?php foreach ( $navid_cats as $navid_cat ) : ?>
							<a href="<?php echo esc_url( get_category_link( $navid_cat ) ); ?>"><?php echo esc_html( $navid_cat->name ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<h1><?php the_title(); ?></h1>
				<p class="navid-entry-meta">
					<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
					· <?php echo esc_html( sprintf( '%s دقیقه مطالعه', navid_fa_digits( navid_reading_minutes( get_post() ) ) ) ); ?>
				</p>
			</header>
			<?php if ( has_post_thumbnail() ) : ?>
				<div class="navid-entry-thumb"><?php the_post_thumbnail( 'large' ); ?></div>
			<?php endif; ?>
			<div class="navid-entry-body"><?php the_content(); ?></div>
			<?php wp_link_pages(); ?>
			<?php the_tags( '<p class="navid-tags">', ' ', '</p>' ); ?>
		</article>

		<?php
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}

		$navid_related = new WP_Query( array(
			'post_type'           => 'post',
			'posts_per_page'      => 3,
			'post__not_in'        => array( get_the_ID() ),
			'category__in'        => wp_get_post_categories( get_the_ID() ),
			'ignore_sticky_posts' => true,
		) );
		if ( $navid_related->have_posts() ) :
			?>
			<section class="navid-related">
				<h2 class="navid-section-title"><?php esc_html_e( 'نوشته‌های مرتبط', 'navid' ); ?></h2>
				<div class="navid-blog-grid navid-blog-grid--page">
					<?php
					while ( $navid_related->have_posts() ) :
						$navid_related->the_post();
						get_template_part( 'template-parts/entry', 'summary' );
					endwhile;
					?>
				</div>
			</section>
			<?php
		endif;
		wp_reset_postdata();
	endwhile;
	?>
</div>
<?php
get_footer();
