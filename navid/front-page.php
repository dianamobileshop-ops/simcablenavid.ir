<?php
/**
 * Home page.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

get_header();

$navid_slides = navid_slides();
$navid_cats   = navid_home_categories();
$navid_opt    = navid_options();
?>

<div class="navid-wrap navid-home">
<?php if ( $navid_slides ) : ?>
	<section class="navid-slider" aria-label="<?php esc_attr_e( 'بنرها', 'navid' ); ?>" data-navid-slider>
		<div class="navid-slider-track">
			<?php foreach ( $navid_slides as $i => $slide ) : ?>
				<?php
				if ( isset( $slide['title'] ) ) :
					?>
					<div class="navid-slide<?php echo 0 === $i ? ' is-on' : ''; ?>">
						<div class="navid-slide-card navid-tone-<?php echo (int) $slide['tone']; ?>">
							<h2><?php echo esc_html( $slide['title'] ); ?></h2>
							<p><?php echo esc_html( $slide['text'] ); ?></p>
							<a class="navid-btn" href="<?php echo esc_url( $slide['link'] ); ?>"><?php echo esc_html( $slide['cta'] ); ?></a>
						</div>
					</div>
					<?php
					continue;
				endif;
				$img = sprintf(
					'<img src="%s" alt="%s" width="1200" height="400" %s>',
					esc_url( $slide['image'] ),
					esc_attr( $slide['alt'] ),
					0 === $i ? 'fetchpriority="high"' : 'loading="lazy"'
				);
				?>
				<div class="navid-slide<?php echo 0 === $i ? ' is-on' : ''; ?>">
					<?php echo $slide['link'] ? '<a href="' . esc_url( $slide['link'] ) . '">' . $img . '</a>' : $img; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( count( $navid_slides ) > 1 ) : ?>
			<div class="navid-slider-dots">
				<?php foreach ( $navid_slides as $i => $slide ) : ?>
					<button type="button" class="navid-dot<?php echo 0 === $i ? ' is-on' : ''; ?>" aria-label="<?php echo esc_attr( sprintf( 'بنر %d', $i + 1 ) ); ?>"></button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
<?php endif; ?>

<section class="navid-price-box" aria-label="<?php esc_attr_e( 'لیست قیمت', 'navid' ); ?>">
	<?php if ( '' !== $navid_opt['toolbar_sub'] ) : ?><p class="navid-price-box-sub"><?php echo esc_html( $navid_opt['toolbar_sub'] ); ?></p><?php endif; ?>
	<div class="navid-price-box-btns">
		<?php if ( function_exists( 'npt_pdf_download_url' ) ) : ?>
			<a class="navid-btn navid-btn--primary" href="<?php echo esc_url( npt_pdf_download_url( '' ) ); ?>" rel="nofollow"><?php esc_html_e( 'دانلود PDF', 'navid' ); ?></a>
		<?php endif; ?>
		<a class="navid-btn" href="<?php echo esc_url( function_exists( 'npt_online_view_url' ) ? npt_online_view_url( '' ) : ( $navid_opt['price_url'] ? $navid_opt['price_url'] : home_url( '/' ) ) ); ?>" rel="nofollow"><?php esc_html_e( 'مشاهده آنلاین', 'navid' ); ?></a>
		<?php if ( $navid_opt['price_url'] ) : ?>
			<a class="navid-btn" href="<?php echo esc_url( $navid_opt['price_url'] ); ?>"><?php esc_html_e( 'جدول قیمت', 'navid' ); ?></a>
		<?php endif; ?>
	</div>
</section>

<?php if ( $navid_cats ) : ?>
	<section class="navid-home-cats" aria-label="<?php esc_attr_e( 'دسته‌بندی‌ها', 'navid' ); ?>">
		<h2 class="navid-section-title"><?php esc_html_e( 'دسته‌بندی محصولات', 'navid' ); ?></h2>
		<div class="navid-cat-grid">
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
				</a>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php echo do_shortcode( '[navid_blog count="4"]' ); ?>
</div>

<?php
get_footer();
