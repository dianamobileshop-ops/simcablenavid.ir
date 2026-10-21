<?php
/**
 * One post as a card (blog page, archives, search, related posts).
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_cats  = get_the_category();
$navid_badge = $navid_cats ? $navid_cats[0]->name : '';
?>
<article <?php post_class( 'navid-post' ); ?>>
	<a class="navid-post-img" href="<?php the_permalink(); ?>">
		<?php if ( has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) ); ?>
		<?php endif; ?>
		<?php if ( $navid_badge ) : ?>
			<span class="navid-post-badge"><?php echo esc_html( $navid_badge ); ?></span>
		<?php endif; ?>
	</a>
	<div class="navid-post-body">
		<p class="navid-post-meta"><?php echo esc_html( sprintf( '%s دقیقه مطالعه', navid_reading_minutes( get_post() ) ) ); ?></p>
		<h2 class="navid-post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<p class="navid-post-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 22 ) ); ?></p>
		<div class="navid-post-foot">
			<span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
		</div>
	</div>
</article>
