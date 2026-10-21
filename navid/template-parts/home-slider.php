<?php
/**
 * Home section: banner slider.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

$navid_slides = navid_slides();
$navid_opt    = navid_options();
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
		<section class="navid-slider navid-slider--<?php echo esc_attr( $navid_mode ); ?><?php echo 'cover' === $navid_opt['slider_fit'] ? '' : ' navid-slider--full'; ?>" style="--navid-ratio:<?php echo esc_attr( $navid_ratio ); ?>" aria-roledescription="carousel" aria-label="<?php esc_attr_e( 'بنرها', 'navid' ); ?>" data-navid-slider data-mode="<?php echo esc_attr( $navid_mode ); ?>" data-speed="<?php echo (int) $navid_opt['slider_speed']; ?>" data-autoplay="<?php echo '1' === (string) $navid_opt['slider_autoplay'] ? '1' : '0'; ?>">
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


