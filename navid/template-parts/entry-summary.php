<?php
/**
 * One post in a list.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;
?>
<article <?php post_class( 'navid-content' ); ?>>
	<?php if ( has_post_thumbnail() ) : ?>
		<a class="navid-entry-thumb" href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) ); ?></a>
	<?php endif; ?>
	<h2 class="navid-entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
	<p class="navid-entry-meta"><?php echo esc_html( get_the_date() ); ?></p>
	<div><?php the_excerpt(); ?></div>
</article>
