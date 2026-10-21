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
<?php
if ( $navid_slides ) :
	$navid_mode  = in_array( $navid_opt['slider_mode'], array( 'slide', 'fade', 'cards', 'mosaic' ), true ) ? $navid_opt['slider_mode'] : 'slide';
	$navid_ratio = preg_replace( '/[^0-9\/. ]/', '', (string) $navid_opt['slider_ratio'] );
	if ( 'mosaic' === $navid_mode && count( $navid_slides ) < 3 ) {
		$navid_mode = 'slide';
	}
	?>
	<?php if ( 'mosaic' === $navid_mode ) : ?>
		<section class="navid-mosaic" aria-label="<?php esc_attr_e( 'بنرها', 'navid' ); ?>">
			<?php foreach ( array_slice( $navid_slides, 0, 3 ) as $i => $slide ) : ?>
				<div class="navid-mosaic-item<?php echo 0 === $i ? ' is-big' : ''; ?>"><?php echo navid_slide_html( $slide, $i ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<?php endforeach; ?>
		</section>
	<?php else : ?>
		<section class="navid-slider navid-slider--<?php echo esc_attr( $navid_mode ); ?>" style="--navid-ratio:<?php echo esc_attr( $navid_ratio ); ?>" aria-roledescription="carousel" aria-label="<?php esc_attr_e( 'بنرها', 'navid' ); ?>" data-navid-slider data-mode="<?php echo esc_attr( $navid_mode ); ?>" data-speed="<?php echo (int) $navid_opt['slider_speed']; ?>" data-autoplay="<?php echo '1' === (string) $navid_opt['slider_autoplay'] ? '1' : '0'; ?>">
			<div class="navid-slider-track">
				<?php foreach ( $navid_slides as $i => $slide ) : ?>
					<div class="navid-slide<?php echo 0 === $i ? ' is-on' : ''; ?>" role="group" aria-roledescription="slide"><?php echo navid_slide_html( $slide, $i ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php endforeach; ?>
			</div>
			<?php if ( count( $navid_slides ) > 1 ) : ?>
				<?php if ( '1' === (string) $navid_opt['slider_arrows'] ) : ?>
					<button type="button" class="navid-arrow navid-arrow--prev" aria-label="<?php esc_attr_e( 'قبلی', 'navid' ); ?>">›</button>
					<button type="button" class="navid-arrow navid-arrow--next" aria-label="<?php esc_attr_e( 'بعدی', 'navid' ); ?>">‹</button>
				<?php endif; ?>
				<div class="navid-slider-dots">
					<?php foreach ( $navid_slides as $i => $slide ) : ?>
						<button type="button" class="navid-dot<?php echo 0 === $i ? ' is-on' : ''; ?>" aria-label="<?php echo esc_attr( sprintf( 'بنر %d', $i + 1 ) ); ?>"></button>
					<?php endforeach; ?>
				</div>
				<div class="navid-progress"><span></span></div>
			<?php endif; ?>
		</section>
	<?php endif; ?>
<?php endif; ?>

<?php if ( navid_wc() || $navid_opt['price_url'] ) : ?>
<section class="navid-price-box" aria-label="<?php esc_attr_e( 'لیست قیمت', 'navid' ); ?>">
	<?php if ( '' !== $navid_opt['toolbar_sub'] ) : ?><p class="navid-price-box-sub"><?php echo esc_html( $navid_opt['toolbar_sub'] ); ?></p><?php endif; ?>
	<div class="navid-price-box-btns">
		<?php if ( navid_wc() && function_exists( 'npt_pdf_download_url' ) ) : ?>
			<a class="navid-btn navid-btn--primary" href="<?php echo esc_url( npt_pdf_download_url( '' ) ); ?>" rel="nofollow"><?php esc_html_e( 'دانلود PDF', 'navid' ); ?></a>
			<a class="navid-btn" href="<?php echo esc_url( npt_online_view_url( '' ) ); ?>" rel="nofollow"><?php esc_html_e( 'مشاهده آنلاین', 'navid' ); ?></a>
		<?php endif; ?>
		<?php if ( $navid_opt['price_url'] ) : ?>
			<a class="navid-btn" href="<?php echo esc_url( $navid_opt['price_url'] ); ?>"><?php esc_html_e( 'جدول قیمت', 'navid' ); ?></a>
		<?php endif; ?>
	</div>
</section>
<?php endif; ?>

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

<?php echo do_shortcode( '[navid_blog count="4"]' ); ?>
</div>

<?php
get_footer();
