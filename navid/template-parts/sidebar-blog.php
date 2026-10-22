<?php
/**
 * Blog sidebar (left side in RTL): categories with counts, latest posts and a search box.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_sb_cats   = get_categories( array( 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 20 ) );
$navid_sb_recent = new WP_Query( array(
	'post_type'           => 'post',
	'posts_per_page'      => 5,
	'post__not_in'        => is_singular( 'post' ) ? array( get_the_ID() ) : array(),
	'ignore_sticky_posts' => true,
	'no_found_rows'       => true,
) );
?>
<aside class="navid-blog-side" aria-label="<?php esc_attr_e( 'سایدبار وبلاگ', 'navid' ); ?>">
	<form class="navid-side-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<label class="screen-reader-text" for="navid-side-s"><?php esc_html_e( 'جستجوی نوشته‌ها', 'navid' ); ?></label>
		<input id="navid-side-s" type="search" name="s" placeholder="<?php esc_attr_e( 'جستجو در نوشته‌ها…', 'navid' ); ?>">
		<input type="hidden" name="post_type" value="post">
	</form>

	<?php if ( $navid_sb_cats ) : ?>
		<section class="navid-side-box">
			<h2 class="navid-side-title"><?php esc_html_e( 'دسته‌بندی نوشته‌ها', 'navid' ); ?></h2>
			<ul class="navid-side-cats">
				<?php foreach ( $navid_sb_cats as $navid_sb_cat ) : ?>
					<li<?php echo is_category( $navid_sb_cat->term_id ) ? ' class="is-on"' : ''; ?>>
						<a href="<?php echo esc_url( get_category_link( $navid_sb_cat ) ); ?>"><?php echo esc_html( $navid_sb_cat->name ); ?></a>
						<span><?php echo esc_html( navid_fa_digits( (string) $navid_sb_cat->count ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( $navid_sb_recent->have_posts() ) : ?>
		<section class="navid-side-box">
			<h2 class="navid-side-title"><?php esc_html_e( 'تازه‌ترین نوشته‌ها', 'navid' ); ?></h2>
			<ul class="navid-side-recent">
				<?php
				while ( $navid_sb_recent->have_posts() ) :
					$navid_sb_recent->the_post();
					?>
					<li>
						<a href="<?php the_permalink(); ?>">
							<?php if ( has_post_thumbnail() ) : ?>
								<?php the_post_thumbnail( 'thumbnail', array( 'loading' => 'lazy' ) ); ?>
							<?php endif; ?>
							<span><?php the_title(); ?></span>
						</a>
					</li>
				<?php endwhile; ?>
			</ul>
		</section>
		<?php wp_reset_postdata(); ?>
	<?php endif; ?>
</aside>
