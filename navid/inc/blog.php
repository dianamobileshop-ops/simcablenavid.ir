<?php
/**
 * Latest posts as cards: [navid_blog count="4"].
 *
 * Only WordPress core and WooCommerce are needed; no blog plugin.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reading time in minutes, from the word count (Persian text, about 200 words a minute).
 *
 * @param WP_Post $post Post.
 * @return int
 */
function navid_reading_minutes( $post ) {
	// str_word_count() ignores non-Latin letters, so Persian text counted as zero words.
	$words = preg_match_all( '/[\p{L}\p{N}]+/u', wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
	return max( 1, (int) ceil( $words / 200 ) );
}

/**
 * Shortcode: a row of the latest posts.
 *
 * @param array $atts Attributes: count (default 4), category (slug, optional).
 * @return string
 */
function navid_blog_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'count'    => 4,
		'category' => '',
	), $atts, 'navid_blog' );

	$query = new WP_Query( array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, min( 12, (int) $atts['count'] ) ),
		'ignore_sticky_posts' => true,
		'category_name'       => sanitize_title( $atts['category'] ),
	) );
	if ( ! $query->have_posts() ) {
		return '';
	}

	ob_start();
	?>
	<section class="navid-blog" aria-label="<?php esc_attr_e( 'آخرین نوشته‌ها', 'navid' ); ?>">
		<div class="navid-blog-head">
			<h2 class="navid-section-title"><?php esc_html_e( 'آخرین نوشته‌ها', 'navid' ); ?></h2>
			<?php if ( get_option( 'page_for_posts' ) ) : ?>
				<a class="navid-btn" href="<?php echo esc_url( get_permalink( get_option( 'page_for_posts' ) ) ); ?>"><?php esc_html_e( 'همه‌ی نوشته‌ها', 'navid' ); ?></a>
			<?php endif; ?>
		</div>
		<div class="navid-blog-grid">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$post  = get_post();
				$cats  = get_the_category( $post->ID );
				$badge = $cats ? $cats[0]->name : '';
				?>
				<article class="navid-post">
					<a class="navid-post-img" href="<?php the_permalink(); ?>">
						<?php
						if ( has_post_thumbnail( $post ) ) {
							echo get_the_post_thumbnail( $post, 'medium_large', array( 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						if ( $badge ) {
							echo '<span class="navid-post-badge">' . esc_html( $badge ) . '</span>';
						}
						?>
					</a>
					<div class="navid-post-body">
						<p class="navid-post-meta"><?php echo esc_html( sprintf( '%s دقیقه مطالعه', navid_reading_minutes( $post ) ) ); ?></p>
						<h3 class="navid-post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
						<p class="navid-post-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt( $post ), 20 ) ); ?></p>
						<div class="navid-post-foot">
							<span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
							<time datetime="<?php echo esc_attr( get_the_date( 'c', $post ) ); ?>"><?php echo esc_html( get_the_date( '', $post ) ); ?></time>
						</div>
					</div>
				</article>
			<?php endwhile; ?>
		</div>
	</section>
	<?php
	wp_reset_postdata();
	return ob_get_clean();
}
add_shortcode( 'navid_blog', 'navid_blog_shortcode' );
