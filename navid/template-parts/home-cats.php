<?php
/**
 * Home section: category grid.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_cats = navid_home_categories();
$navid_opt  = navid_options();
?>
<?php if ( $navid_cats ) : ?>
	<section class="navid-home-cats" aria-label="<?php esc_attr_e( 'دسته‌بندی‌ها', 'navid' ); ?>">
		<h2 class="navid-section-title"><?php esc_html_e( 'دسته‌بندی محصولات', 'navid' ); ?></h2>
		<div class="navid-cat-grid navid-fx-<?php echo esc_attr( $navid_opt['cats_effect'] ); ?> navid-shape-<?php echo esc_attr( $navid_opt['cats_shape'] ); ?>" style="--navid-cols:<?php echo (int) $navid_opt['cats_cols']; ?>">
			<?php foreach ( $navid_cats as $cat ) : ?>
				<?php
				$thumb_id = (int) get_term_meta( $cat->term_id, 'thumbnail_id', true );
				$link     = get_term_link( $cat );
				?>
				<a class="navid-cat" href="<?php echo esc_url( $link ); ?>">
					<span class="navid-cat-img">
						<?php
						if ( $thumb_id ) {
							echo wp_get_attachment_image( $thumb_id, 'medium', false, array( 'loading' => 'lazy', 'alt' => $cat->name ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</span>
					<span class="navid-cat-name"><?php echo esc_html( $cat->name ); ?></span>
					<span class="navid-cat-count"><?php echo esc_html( sprintf( '%s محصول', number_format_i18n( (int) $cat->count ) ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

