<?php
/**
 * Home page: banner slider, price box and category grid.
 *
 * @package Navid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Banner slides from the settings page. One per line: image URL | link | alt text.
 *
 * @return array
 */
function navid_slides() {
	$opt   = navid_options();
	$lines = preg_split( '/\r\n|\r|\n/', (string) $opt['slides'] );
	$slides = array();
	foreach ( $lines as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( empty( $parts[0] ) ) {
			continue;
		}
		$slides[] = array(
			'image' => esc_url_raw( $parts[0] ),
			'link'  => isset( $parts[1] ) ? esc_url_raw( $parts[1] ) : '',
			'alt'   => isset( $parts[2] ) ? sanitize_text_field( $parts[2] ) : '',
			'mobile' => isset( $parts[3] ) ? esc_url_raw( $parts[3] ) : '',
		);
	}
	if ( $slides ) {
		return $slides;
	}

	// Nothing configured yet: three text banners so the home page never has an empty top.
	$shop  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
	$price = function_exists( 'npt_online_view_url' ) ? npt_online_view_url( '' ) : $shop;
	$tel   = preg_replace( '/[^0-9+]/', '', (string) $opt['phone'] );
	return array(
		array( 'title' => 'لیست قیمت روز سیم و کابل', 'text' => 'قیمت‌های به‌روز را آنلاین ببینید یا فایل PDF را دانلود کنید.', 'link' => $price, 'cta' => 'مشاهده قیمت‌ها', 'tone' => 1 ),
		array( 'title' => 'فروشگاه سیم و کابل نوید', 'text' => 'کابل افشان، سیم ارت، کابل زمینی و ده‌ها محصول دیگر.', 'link' => $shop, 'cta' => 'دیدن محصولات', 'tone' => 2 ),
		array( 'title' => 'مشاوره و خرید عمده', 'text' => 'برای استعلام قیمت و خرید عمده با ما تماس بگیرید.', 'link' => $tel ? 'tel:' . $tel : home_url( '/' ), 'cta' => 'تماس با ما', 'tone' => 3 ),
	);
}

/**
 * Top-level product categories with their image, for the home grid.
 *
 * @return WP_Term[]
 */
function navid_home_categories() {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return array();
	}
	$opt      = navid_options();
	$selected = array_filter( array_map( 'absint', (array) $opt['home_cats'] ) );
	if ( $selected ) {
		// The categories picked in the panel, in the order they are listed there.
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'include'    => $selected,
			'orderby'    => 'include',
			'hide_empty' => false,
		) );
		return is_wp_error( $terms ) ? array() : $terms;
	}
	$terms = get_terms( array(
		'taxonomy'   => 'product_cat',
		'parent'     => 0,
		'hide_empty' => true,
		'exclude'    => array( get_option( 'default_product_cat' ) ),
	) );
	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Markup of one banner (image with optional phone image, or a text card).
 *
 * @param array $slide Slide.
 * @param int   $i     Position.
 * @return string
 */
function navid_slide_html( $slide, $i ) {
	if ( isset( $slide['title'] ) ) {
		ob_start();
		?>
		<div class="navid-slide-card navid-tone-<?php echo (int) $slide['tone']; ?>">
			<h2><?php echo esc_html( $slide['title'] ); ?></h2>
			<p><?php echo esc_html( $slide['text'] ); ?></p>
			<a class="navid-btn" href="<?php echo esc_url( $slide['link'] ); ?>"><?php echo esc_html( $slide['cta'] ); ?></a>
		</div>
		<?php
		return ob_get_clean();
	}
	$img = sprintf(
		'<img src="%s" alt="%s" width="1200" height="400" %s decoding="async">',
		esc_url( $slide['image'] ),
		esc_attr( $slide['alt'] ),
		0 === $i ? 'fetchpriority="high"' : 'loading="lazy"'
	);
	if ( ! empty( $slide['mobile'] ) ) {
		$img = '<picture><source media="(max-width: 640px)" srcset="' . esc_url( $slide['mobile'] ) . '">' . $img . '</picture>';
	}
	return $slide['link'] ? '<a href="' . esc_url( $slide['link'] ) . '">' . $img . '</a>' : $img;
}

/**
 * Sections of the home page, in their default order.
 *
 * @return array key => label
 */
function navid_home_sections() {
	return array(
		'slider'    => 'اسلایدر بنر',
		'pricebar'  => 'نوار لیست قیمت (زیر اسلایدر؛ چسبان هنگام اسکرول)',
		'content'   => 'محتوای برگهٔ خانه (متن و شورت‌کدهای ویرایشگر)',
		'products1' => 'اسلایدر محصولات ۱',
		'products2' => 'اسلایدر محصولات ۲',
		'cats'      => 'دسته‌بندی محصولات',
		'blog'      => 'آخرین مطالب وبلاگ',
	);
}

/**
 * The saved order and on/off state of the home sections.
 *
 * @return array[] each: key, label, on
 */
function navid_home_layout() {
	$opt      = navid_options();
	$all      = navid_home_sections();
	$saved    = array_filter( array_map( 'trim', explode( ',', (string) $opt['home_order'] ) ) );
	$order    = array();
	foreach ( $saved as $key ) {
		if ( isset( $all[ $key ] ) && ! in_array( $key, $order, true ) ) {
			$order[] = $key;
		}
	}
	foreach ( array_keys( $all ) as $key ) {
		if ( in_array( $key, $order, true ) ) {
			continue;
		}
		// A section added in a later version goes right after the slider, not at the very end.
		$at = ( 'pricebar' === $key ) ? array_search( 'slider', $order, true ) : false;
		if ( false !== $at ) {
			array_splice( $order, $at + 1, 0, array( $key ) );
		} else {
			$order[] = $key;
		}
	}
	$on  = is_array( $opt['home_on'] ) ? $opt['home_on'] : array();
	$out = array();
	foreach ( $order as $key ) {
		$out[] = array( 'key' => $key, 'label' => $all[ $key ], 'on' => ! isset( $on[ $key ] ) || '0' !== (string) $on[ $key ] );
	}
	return $out;
}

/**
 * A row of product cards that scrolls sideways (arrows on desktop, swipe on phones).
 *
 * @param string $title Heading.
 * @param array  $ids   Product ids, shown in this order.
 * @return string HTML, '' when there is nothing to show.
 */
function navid_products_slider_html( $title, $ids ) {
	if ( ! navid_wc() ) {
		return '';
	}
	$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	if ( ! $ids ) {
		return '';
	}
	global $product;
	$previous = $product;
	$GLOBALS['navid_card_simple'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	ob_start();
	foreach ( $ids as $id ) {
		$item = wc_get_product( $id );
		if ( ! $item || 'publish' !== $item->get_status() ) {
			continue;
		}
		$product = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		wc_get_template_part( 'content', 'product' );
	}
	$cards   = ob_get_clean();
	unset( $GLOBALS['navid_card_simple'] );
	wp_reset_postdata();
	$product = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	if ( '' === $cards ) {
		return '';
	}
	$html  = '<section class="navid-pslider" data-navid-pslider>';
	$html .= '<div class="navid-pslider-head">';
	if ( '' !== trim( (string) $title ) ) {
		$html .= '<h2 class="navid-section-title">' . esc_html( $title ) . '</h2>';
	}
	$html .= '<div class="navid-pslider-arrows"><button type="button" data-dir="-1" aria-label="' . esc_attr__( 'قبلی', 'navid' ) . '">›</button><button type="button" data-dir="1" aria-label="' . esc_attr__( 'بعدی', 'navid' ) . '">‹</button></div>';
	$html .= '</div><ul class="products navid-pslider-track">' . $cards . '</ul></section>';
	return $html;
}

/**
 * [navid_products ids="12,15,20" title="محصولات منتخب"]
 *
 * @param array $atts Attributes.
 * @return string
 */
function navid_products_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'ids' => '', 'title' => '' ), $atts, 'navid_products' );
	return navid_products_slider_html( $atts['title'], explode( ',', (string) $atts['ids'] ) );
}
add_shortcode( 'navid_products', 'navid_products_shortcode' );
