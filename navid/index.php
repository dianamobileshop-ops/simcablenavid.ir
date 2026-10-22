<?php
/**
 * Blog home, archives and fallback: a list of post cards with pagination.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

// Shop, product categories and tags use the shop layout even if WooCommerce
// handed the request to this fallback template (some plugins take over archives).
if ( function_exists( 'is_woocommerce' ) && ( is_shop() || is_product_taxonomy() ) ) {
	require get_theme_file_path( 'woocommerce/archive-product.php' );
	return;
}

get_header();
?>
<div class="navid-wrap navid-blog-page">
	<header class="navid-blog-hero">
		<?php if ( is_archive() ) : ?>
			<?php the_archive_title( '<h1>', '</h1>' ); ?>
			<?php the_archive_description( '<p>', '</p>' ); ?>
		<?php else : ?>
			<h1><?php echo esc_html( is_home() && get_option( 'page_for_posts' ) ? get_the_title( get_option( 'page_for_posts' ) ) : 'وبلاگ' ); ?></h1>
		<?php endif; ?>
		<?php
		$navid_blog_cats = get_categories( array( 'hide_empty' => true, 'number' => 12 ) );
		$navid_blog_home = get_option( 'page_for_posts' ) ? get_permalink( get_option( 'page_for_posts' ) ) : home_url( '/' );
		if ( $navid_blog_cats ) :
			?>
			<div class="navid-chips">
				<a href="<?php echo esc_url( $navid_blog_home ); ?>" class="<?php echo is_category() ? '' : 'is-on'; ?>"><?php esc_html_e( 'همه', 'navid' ); ?></a>
				<?php foreach ( $navid_blog_cats as $navid_blog_cat ) : ?>
					<a href="<?php echo esc_url( get_category_link( $navid_blog_cat ) ); ?>" class="<?php echo is_category( $navid_blog_cat->term_id ) ? 'is-on' : ''; ?>"><?php echo esc_html( $navid_blog_cat->name ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</header>

	<div class="navid-blog-layout">
	<div class="navid-blog-main">
	<?php if ( have_posts() ) : ?>
		<div class="navid-blog-grid navid-blog-grid--page">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/entry', 'summary' );
			endwhile;
			?>
		</div>
		<nav class="navid-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی', 'navid' ); ?>">
			<?php
			echo wp_kses_post( (string) paginate_links( array(
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
	<?php get_template_part( 'template-parts/sidebar', 'blog' ); ?>
	</div>
</div>
<?php
get_footer();
