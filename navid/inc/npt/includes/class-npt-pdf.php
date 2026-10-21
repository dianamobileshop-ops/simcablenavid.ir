<?php
/**
 * PDF export for Navid Price Table.
 * Builds a downloadable price list that visually mirrors the printed
 * Navid Cable sheet (PSD sample): branded header (title + Jalali month on
 * the right, logo centered, quality marks on the left), a four-column
 * masonry body of category bands with zebra price rows, and a footer with
 * the globe icon and the spaced site domain.
 *
 * Uses a trimmed, bundled copy of TCPDF (no Composer/network dependency).
 *
 * Security note: this endpoint is intentionally public (no login and no
 * wp_verify_nonce) because it's a read-only download, and the download
 * link is printed into HTML that full-page caching plugins (WP Rocket,
 * WP Super Cache, LiteSpeed Cache, …) will happily cache for hours. A
 * time-limited nonce baked into cached HTML goes stale and starts
 * throwing "درخواست نامعتبر است" at real visitors long before the cache
 * itself expires. Instead we protect the endpoint with:
 *   1) a per-IP request-rate limit, and
 *   2) an on-disk PDF cache (regenerated only when the underlying
 *      products actually changed),
 * which is both safer against abuse and considerably cheaper on the
 * server than the previous "regenerate the whole PDF on every click".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Make sure the writable font-cache folder exists (under wp-content/uploads),
 * since the plugin folder itself may not be writable on the live server.
 */
function npt_pdf_font_cache_dir() {
	$upload_dir = wp_upload_dir();
	$dir        = trailingslashit( $upload_dir['basedir'] ) . 'npt-fonts';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	return $dir;
}

/**
 * Writable folder where generated PDFs are cached, keyed by category.
 */
function npt_pdf_cache_dir() {
	$upload_dir = wp_upload_dir();
	$dir        = trailingslashit( $upload_dir['basedir'] ) . 'npt-pdf-cache';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
		// Belt-and-suspenders: keep this folder out of directory listings/search.
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
	}
	return $dir;
}

function npt_pdf_cache_path( $category_slug, $signature = '' ) {
	$key = $category_slug ? sanitize_title( $category_slug ) : 'all';
	if ( '' === $signature ) {
		$signature = npt_pdf_cache_signature( $category_slug );
	}
	// NPT_VERSION and the content signature are part of the file name, so a
	// plugin update (new layout) or any catalog change (product added,
	// removed or edited) produces a new, empty slot instead of reusing a
	// stale file.
	return npt_pdf_cache_dir() . '/price-list-' . $key . '-' . NPT_VERSION . '-' . $signature . '.pdf';
}

/**
 * Content signature for the cached PDF: changes when a product is added or
 * removed (count) or when the newest modification time moves. Because the
 * signature lives in the file name, deleting the most recently edited
 * product can no longer keep a stale PDF "fresh" the way a filemtime-only
 * check could — the old file simply stops being requested.
 */
function npt_pdf_cache_signature( $category_slug = '' ) {
	$count_args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	);

	if ( ! empty( $category_slug ) ) {
		$count_args['tax_query'] = array( // phpcs:ignore
			array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => sanitize_title( $category_slug ),
			),
		);
	}

	$count_query = new WP_Query( $count_args );
	$count       = (int) $count_query->found_posts;

	return md5( $count . '|' . npt_latest_product_modified_timestamp( $category_slug ) . '|' . npt_table_cache_version() . '|' . NPT_VERSION );
}

/**
 * Remove older cached PDFs of the same category once a fresh file is
 * published (the signature changed, so previous files are unreachable).
 */
function npt_pdf_cleanup_stale_cache( $category_slug, $keep_path ) {
	$key   = $category_slug ? sanitize_title( $category_slug ) : 'all';
	$files = glob( npt_pdf_cache_dir() . '/price-list-' . $key . '-' . NPT_VERSION . '-*.pdf' );
	if ( ! $files ) {
		return;
	}
	foreach ( $files as $file ) {
		if ( $file !== $keep_path ) {
			wp_delete_file( $file );
		}
	}
}

/**
 * Move a finished PDF from its temporary file to the final cache path.
 * rename() alone can fail on Windows when the target exists; the copy
 * fallback keeps that from breaking the download.
 */
function npt_pdf_publish_file( $tmp_path, $final_path ) {
	if ( ! file_exists( $tmp_path ) ) {
		return false;
	}
	if ( @rename( $tmp_path, $final_path ) ) { // phpcs:ignore
		return true;
	}
	$ok = @copy( $tmp_path, $final_path ); // phpcs:ignore
	wp_delete_file( $tmp_path );
	return $ok;
}

/**
 * Timestamp (GMT) of the most recently modified published product in the
 * given category (or overall). Used to decide whether a cached PDF file
 * is still fresh, without having to load every product just to check.
 */
function npt_latest_product_modified_timestamp( $category_slug = '' ) {
	$args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	);

	if ( ! empty( $category_slug ) ) {
		$args['tax_query'] = array( // phpcs:ignore
			array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => sanitize_title( $category_slug ),
			),
		);
	}

	$ids = get_posts( $args );
	if ( empty( $ids ) ) {
		return 0;
	}

	$modified = get_post_field( 'post_modified_gmt', $ids[0] );
	return $modified ? strtotime( $modified . ' +00:00' ) : 0;
}

/**
 * Simple per-IP rate limit for the public PDF endpoint using a transient
 * counter. Returns true when the request is allowed, false when the
 * caller has exceeded the limit and should be turned away.
 */
/**
 * Visitor address used by every rate limiter. REMOTE_ADDR by default; behind a
 * CDN/reverse proxy define NPT_TRUSTED_PROXY_HEADER in wp-config.php (e.g.
 * 'HTTP_CF_CONNECTING_IP') so visitors are not all counted as one address.
 * The header is trusted only when explicitly configured, and only if it holds
 * a valid IP.
 */
function npt_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( defined( 'NPT_TRUSTED_PROXY_HEADER' ) && NPT_TRUSTED_PROXY_HEADER && ! empty( $_SERVER[ NPT_TRUSTED_PROXY_HEADER ] ) ) {
		$parts  = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ NPT_TRUSTED_PROXY_HEADER ] ) ) );
		$header = trim( $parts[0] );
		if ( filter_var( $header, FILTER_VALIDATE_IP ) ) {
			$ip = $header;
		}
	}
	$ip = (string) apply_filters( 'npt_client_ip', $ip );
	return '' === $ip ? 'unknown' : $ip;
}

function npt_pdf_rate_limit_ok( $bucket = 'pdf' ) {
	$ip  = npt_client_ip();
	$key = 'npt_rl_' . $bucket . '_' . md5( $ip );

	// Per-bucket budgets for the same visitor over a 5-minute window.
	// Downloading a PDF costs far more than viewing a page; the public
	// proforma search is keystroke-driven (needs headroom) while public
	// proforma saves must stay rare.
	$limits = array(
		'view'      => 60,
		'pf_search' => 60,
		'pf_save'   => 5,
		'filter'    => 60,
		'live'      => 120,
	);
	$limit = isset( $limits[ $bucket ] ) ? $limits[ $bucket ] : 20;

	// Prefer external object cache when available (works in multi-node).
	$count = false;
	if ( function_exists( 'wp_cache_get' ) ) {
		$count = wp_cache_get( $key, 'npt' );
	}
	if ( false === $count ) {
		$count = (int) get_transient( $key );
	}

	if ( $count >= $limit ) {
		return false;
	}

	$count = (int) $count + 1;
	if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_set' ) ) {
		wp_cache_set( $key, $count, 'npt', 5 * MINUTE_IN_SECONDS );
	} else {
		set_transient( $key, $count, 5 * MINUTE_IN_SECONDS );
	}
	return true;
}

/**
 * Load TCPDF and register the configured Persian font (Vazirmatn by
 * default, optionally Sahel; once, cached on disk).
 * Returns the internal TCPDF font name to use in the document.
 */
function npt_pdf_bootstrap() {
	if ( ! defined( 'K_PATH_CACHE' ) ) {
		define( 'K_PATH_CACHE', npt_pdf_font_cache_dir() . '/' );
	}
	if ( ! defined( 'K_PATH_URL_CACHE' ) ) {
		define( 'K_PATH_URL_CACHE', trailingslashit( wp_upload_dir()['baseurl'] ) . 'npt-fonts/' );
	}

	if ( ! class_exists( 'TCPDF' ) ) {
		// Convert TCPDF's internal Error() die() calls into catchable
		// exceptions; otherwise a TCPDF failure would bypass the try/catch
		// in the download handler and dump raw output to the visitor.
		if ( ! defined( 'K_TCPDF_THROW_EXCEPTION_ERROR' ) ) {
			define( 'K_TCPDF_THROW_EXCEPTION_ERROR', true );
		}
		require_once NPT_PATH . 'vendor/tcpdf/tcpdf.php';
	}

	$chosen = npt_sanitize_pdf_font( get_option( 'npt_pdf_font', 'vazirmatn' ) );
	$sources = array(
		'vazirmatn' => array(
			'regular' => NPT_PATH . 'vendor/fonts/Vazirmatn-Regular.ttf',
			'bold'    => NPT_PATH . 'vendor/fonts/Vazirmatn-Bold.ttf',
		),
		'sahel' => array(
			'regular' => NPT_PATH . 'vendor/fonts/Sahel.ttf',
			'bold'    => NPT_PATH . 'vendor/fonts/Sahel-Bold.ttf',
		),
	);
	// The other font stays bundled as a guaranteed fallback, so a missing
	// Sahel file can never produce a PDF with no usable Persian glyph.
	if ( ! file_exists( $sources[ $chosen ]['regular'] ) || ! file_exists( $sources[ $chosen ]['bold'] ) ) {
		$chosen = 'vazirmatn';
	}

	$cache_dir      = npt_pdf_font_cache_dir();

	// NOTE: addTTFfont() concatenates $outpath with the file name directly,
	// so the trailing slash is required — without it the generated font
	// files land one level up (e.g. "npt-fontsvazirmatn.php").
	$font_out       = trailingslashit( $cache_dir );
	// Per-font markers so switching fonts re-registers cleanly; vazirmatn's
	// marker names match the original hardcoded ones, keeping existing installs.
	$regular_marker = $cache_dir . '/' . $chosen . '.name';
	$bold_marker    = $cache_dir . '/' . $chosen . 'b.name';

	$register_font = function ( $ttf_path, $marker ) use ( $font_out ) {
		$name = '';
		if ( file_exists( $marker ) ) {
			$name = trim( file_get_contents( $marker ) ); // phpcs:ignore
		}
		if ( $name && ! file_exists( $font_out . $name . '.php' ) ) {
			$name = ''; // stale marker, definition file is gone
		}
		if ( ! $name ) {
			$name = TCPDF_FONTS::addTTFfont( $ttf_path, 'TrueTypeUnicode', '', 96, $font_out );
			if ( $name ) {
				file_put_contents( $marker, $name ); // phpcs:ignore
			}
		}
		return $name;
	};

	$regular_name = $register_font( $sources[ $chosen ]['regular'], $regular_marker );
	$bold_name    = $register_font( $sources[ $chosen ]['bold'], $bold_marker );

	// SetFont() only searches K_PATH_FONTS (the bundled fonts folder), so
	// also return each definition file's absolute path — every SetFont()
	// call must pass it as the 4th ($fontfile) argument, which also lets
	// TCPDF locate the .z/.ctg.z companions for embedding.
	return array(
		'regular'      => $regular_name ? $regular_name : 'aealarabiya',
		'regular_file' => $regular_name ? $font_out . $regular_name . '.php' : '',
		'bold'         => $bold_name ? $bold_name : 'aealarabiya',
		'bold_file'    => $bold_name ? $font_out . $bold_name . '.php' : '',
	);
}

/**
 * Build the PDF price list in the layout of the printed Navid sheet and
 * save it to $output_path on disk (does not print/stream anything).
 *
 * Layout (A4 portrait, all measurements in mm):
 *  - Header band: "لیست قیمت" + Jalali month/year on the right, the Navid
 *    logo centered, three quality marks on the left, plus a small line
 *    with the currency unit and today's Jalali date.
 *  - Body: four vertical columns (right to left). Each category becomes a
 *    black band with white title followed by its product rows (size on the
 *    right sub-column, price on the left), zebra-striped like the sample.
 *    Flow is sequential first-fit, exactly like the printed sheet: a
 *    category that doesn't fit the space left in the current column starts
 *    fresh at the top of the next one, and a category taller than a whole
 *    column is split with its band repeated at the top of every
 *    continuation column (e.g. «کابل افشان» fills column two and about
 *    half of column three).
 *  - Footer: globe icon + spaced site domain, centered.
 *
 * The bundled TCPDF library predates PHP 8's stricter deprecation notices
 * and can emit stray PHP warnings/notices during rendering on newer PHP
 * versions. If those ever reach the output buffer they end up as garbage
 * bytes mixed into (or before) the actual PDF response, which is exactly
 * what makes a downloaded PDF "corrupted" / unreadable by PDF viewers.
 * We build inside our own output buffer and discard it — only the bytes
 * TCPDF writes to $output_path via fwrite() end up in the actual file,
 * so nothing can leak in from stray notices.
 */
function npt_build_pdf_file( $category_slug, $output_path ) {
	$previous_error_reporting = error_reporting( E_ERROR | E_PARSE );
	ob_start();

	// Build into a per-process temp file and publish it with a single
	// rename() at the end, so two concurrent requests can never interleave
	// writes into the same final file.
	$tmp_path = $output_path . '.' . getmypid() . '.tmp';

	$fonts = npt_pdf_bootstrap();

	$pdf = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
	$pdf->SetCreator( get_bloginfo( 'name' ) );
	$pdf->SetAuthor( get_bloginfo( 'name' ) );
	$pdf->SetTitle( 'لیست قیمت ' . get_bloginfo( 'name' ) );
	$pdf->setPrintHeader( false );
	$pdf->setPrintFooter( false );
	$pdf->SetMargins( 8, 8, 8 );
	$pdf->SetAutoPageBreak( false );
	$pdf->SetLineWidth( 0.25 );
	$pdf->SetDrawColor( 0, 0, 0 );

	// Geometry ------------------------------------------------------------
	$page_w   = 210;
	$margin   = 8;
	$y0       = 40;   // body top, below the header band
	$ymax     = 284;  // body bottom, above the footer
	$footer_y = 288;
	$cols     = 4;
	$gap      = 2;
	$usable   = $page_w - ( 2 * $margin );
	$colw     = ( $usable - ( ( $cols - 1 ) * $gap ) ) / $cols; // 47
	$name_w   = 20;
	$price_w  = $colw - $name_w;
	// Band/row heights match the printed sheet: ~8 mm black band, ~7.3 mm
	// price rows (no extra gap between categories — the band itself is the
	// separator). A full column holds band + 32 rows.
	$band_h   = 8.0;
	$row_h    = 7.3;

	$col_x = function ( $col ) use ( $page_w, $margin, $colw, $gap ) {
		// Columns fill right-to-left, like the printed sheet.
		return $page_w - $margin - $colw - ( $col * ( $colw + $gap ) );
	};

	$draw_header = function () use ( $pdf, $fonts, $page_w, $margin ) {
		$j    = npt_jalali_now();
		$hrgb = npt_header_color_rgb();
		$argb = npt_accent_color_rgb();

		// Like the printed sheet: title block on the left, logo centered,
		// quality marks on the right. Title/colors/visibility follow the
		// settings page; defaults reproduce the PSD exactly.
		$pdf->SetFont( $fonts['bold'], '', 17, $fonts['bold_file'] );
		$pdf->SetTextColor( $hrgb[0], $hrgb[1], $hrgb[2] );
		$pdf->SetXY( $margin, 9 );
		$pdf->Cell( 62, 9, npt_header_title_text(), 0, 1, 'C' );

		if ( get_option( 'npt_header_show_month', true ) ) {
			$pdf->SetFont( $fonts['bold'], '', 12, $fonts['bold_file'] );
			$pdf->SetTextColor( $argb[0], $argb[1], $argb[2] );
			$pdf->SetXY( $margin, 18.5 );
			$pdf->Cell( 62, 7, $j['month'] . ' ' . __( 'ماه', 'navid-price-table' ) . ' ' . $j['year'], 0, 1, 'C' );
		}

		$pdf->SetFont( $fonts['regular'], '', 7.5, $fonts['regular_file'] );
		$pdf->SetTextColor( 110, 110, 110 );
		$pdf->SetXY( $margin, 26 );
		$sentence = npt_unit_sentence();
		if ( get_option( 'npt_header_show_date', false ) ) {
			$sentence .= '   |   ' . __( 'تاریخ:', 'navid-price-table' ) . ' ' . $j['date'];
		}
		$pdf->Cell( 62, 5, $sentence, 0, 1, 'C' );

		// The meter sentence rides on a second small line. The default text
		// (~67mm at 7.5pt) exceeds the 62mm cell, so the font shrinks until
		// it fits. 31+4 stays above the body top (y=40).
		if ( npt_meter_badge_enabled() && npt_meter_sentence_text() ) {
			$meter = npt_meter_sentence_text();
			$size  = 7.5;
			while ( $size > 5 && $pdf->GetStringWidth( $meter ) > 60 ) {
				$size -= 0.25;
				$pdf->SetFont( $fonts['regular'], '', $size, $fonts['regular_file'] );
			}
			$pdf->SetXY( $margin, 31 );
			$pdf->Cell( 62, 4, $meter, 0, 1, 'C' );
		}

		// TCPDF only rasterizes png/jpg/gif — anything else (e.g. webp)
		// falls back to the bundled asset instead of failing the PDF.
		$raster_ok   = array( 'png', 'jpg', 'jpeg', 'gif' );
		$logo_option = npt_media_image_path( 'npt_logo_id', '' );
		if ( $logo_option && in_array( strtolower( pathinfo( $logo_option, PATHINFO_EXTENSION ) ), $raster_ok, true ) ) {
			$logo = $logo_option;
		} else {
			$logo = NPT_PATH . 'assets/images/logo-navid.png';
		}
		if ( file_exists( $logo ) ) {
			// Empty type argument = TCPDF sniffs the format from the extension,
			// so an uploaded JPG logo works the same as the bundled PNG.
			$pdf->Image( $logo, 81, 8, 48, 0, '', '', '', false, 300 );
		}

		$marks = array();
		$total = 0;
		foreach ( array(
			'npt_mark1_id' => NPT_PATH . 'assets/images/mark-1.png',
			'npt_mark2_id' => NPT_PATH . 'assets/images/mark-2.png',
			'npt_mark3_id' => NPT_PATH . 'assets/images/mark-3.png',
		) as $mark_option => $mark_fallback ) {
			$file = npt_media_image_path( $mark_option, $mark_fallback );
			if ( ! file_exists( $file ) || ! in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), $raster_ok, true ) ) {
				continue;
			}
			$size    = getimagesize( $file );
			$mw      = $size ? ( 12 * $size[0] / $size[1] ) : 10;
			$marks[] = array( $file, $mw );
			$total  += $mw;
		}
		if ( $marks ) {
			$total += 3 * ( count( $marks ) - 1 );
			$mx     = $page_w - $margin - $total;
			foreach ( $marks as $m ) {
				$pdf->Image( $m[0], $mx, 10, 0, 12, '', '', '', false, 300 );
				$mx += $m[1] + 3;
			}
		}
	};

	$draw_footer = function () use ( $pdf, $fonts, $page_w, $footer_y, $margin ) {
		$domain     = npt_footer_domain_text();
		$globe      = npt_media_image_path( 'npt_globe_id', NPT_PATH . 'assets/images/globe.png' );
		$gw         = 7;
		$show_globe = (bool) get_option( 'npt_show_globe', true );
		$telegram   = trim( (string) get_option( 'npt_telegram', '' ) );
		$instagram  = trim( (string) get_option( 'npt_instagram', '' ) );

		// Optional media-library icons; anything TCPDF can't rasterize falls
		// back to the built-in vector marks below.
		$raster_ok  = array( 'png', 'jpg', 'jpeg', 'gif' );
		$raster     = function ( $path ) use ( $raster_ok ) {
			return $path && file_exists( $path ) && in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), $raster_ok, true );
		};
		$insta_img  = npt_media_image_path( 'npt_instagram_icon_id', '' );
		$insta_img  = $raster( $insta_img ) ? $insta_img : '';
		$tg_img     = npt_media_image_path( 'npt_telegram_icon_id', '' );
		$tg_img     = $raster( $tg_img ) ? $tg_img : '';
		$globe      = $raster( $globe ) ? $globe : '';

		$pdf->SetFont( $fonts['bold'], '', 13, $fonts['bold_file'] );
		$pdf->SetTextColor( 21, 40, 150 );
		$tw    = $pdf->GetStringWidth( $domain );
		$row_w = $tw + ( $show_globe && $globe ? $gw + 2 : 0 ) + ( '' !== $telegram ? 7 : 0 ) + ( '' !== $instagram ? 7 : 0 );
		$sx    = ( $page_w - $row_w ) / 2;

		// Social icons use the media-library upload when set, otherwise the
		// vector primitives; each block is 7mm wide either way so the
		// globe/domain pair stays exactly where the printed sheet has it.
		if ( '' !== $instagram ) {
			$iy = $footer_y + 1;
			if ( $insta_img ) {
				$pdf->Image( $insta_img, $sx, $iy, 0, 5, '', '', '', false, 300 );
			} else {
				$pdf->SetDrawColor( 188, 42, 141 );
				$pdf->SetLineWidth( 0.35 );
				$pdf->RoundedRect( $sx, $iy, 5, 5, 1.2, '1111', 'D' );
				$pdf->Circle( $sx + 2.5, $iy + 2.5, 1.15, 0, 360, 'D' );
				$pdf->SetFillColor( 188, 42, 141 );
				$pdf->Circle( $sx + 3.75, $iy + 1.25, 0.35, 0, 360, 'F' );
			}
			$sx += 7;
		}
		if ( '' !== $telegram ) {
			$ty = $footer_y + 1;
			if ( $tg_img ) {
				$pdf->Image( $tg_img, $sx, $ty, 0, 5, '', '', '', false, 300 );
			} else {
				$pdf->SetDrawColor( 42, 171, 238 );
				$pdf->SetLineWidth( 0.35 );
				$pdf->Circle( $sx + 2.5, $ty + 2.5, 2.5, 0, 360, 'D' );
				$pdf->SetFillColor( 42, 171, 238 );
				$pdf->Polygon( array(
					$sx + 0.9, $ty + 2.6,
					$sx + 4.1, $ty + 1.3,
					$sx + 2.9, $ty + 4.6,
					$sx + 2.3, $ty + 3.2,
					$sx + 1.1, $ty + 3.9,
				), 'F', array(), array(), true );
			}
			$sx += 7;
		}
		if ( $show_globe && $globe ) {
			$pdf->Image( $globe, $sx, $footer_y, $gw, $gw, 'PNG', '', '', false, 300 );
		}
		$dx = ( $show_globe && $globe ) ? $sx + $gw + 2 : $sx;
		$pdf->SetXY( $dx, $footer_y - 1 );
		$pdf->Cell( $tw, 8, $domain, 0, 0, 'L' );

		// Optional contact line sits above the domain row, in the free band
		// between the last price row (~y=276) and the footer.
		$phone   = trim( (string) get_option( 'npt_phone', '' ) );
		$address = trim( (string) get_option( 'npt_address', '' ) );
		if ( $phone || $address ) {
			$pdf->SetFont( $fonts['regular'], '', 8, $fonts['regular_file'] );
			$pdf->SetTextColor( 70, 70, 70 );
			$pdf->SetXY( $margin, $footer_y - 5.5 );
			$pdf->Cell( $page_w - 2 * $margin, 4.5, trim( $address . ( $address && $phone ? '  |  ' : '' ) . $phone ), 0, 0, 'C' );
		}
	};

	// Writing position: top of the first (rightmost) column.
	$col = 0;
	$y   = $y0;

	// Advance to the top of the next column; a new page when the last
	// column is done.
	$new_column = function () use ( $pdf, $draw_footer, $draw_header, $cols, $y0, &$col, &$y ) {
		$col++;
		$y = $y0;
		if ( $col >= $cols ) {
			$col = 0;
			$draw_footer();
			$pdf->AddPage();
			$draw_header();
		}
	};

	// Black category band; returns the y position right below it. Drawn at
	// the top of every column chunk — including each continuation chunk of
	// a split category, like the printed sheet where «کابل افشان» appears
	// again at the top of its continuation column.
	$draw_band = function ( $col_pos, $y_pos, $name ) use ( $pdf, $fonts, $col_x, $colw, $band_h ) {
		$pdf->SetXY( $col_x( $col_pos ), $y_pos );
		$pdf->SetFont( $fonts['bold'], '', 9.5, $fonts['bold_file'] );
		$pdf->SetTextColor( 255, 255, 255 );
		$pdf->SetFillColor( 0, 0, 0 );
		$pdf->Cell( $colw, $band_h, $name, 1, 0, 'C', true );
		return $y_pos + $band_h;
	};

	$draw_row = function ( $col_pos, $y_pos, $row, $grey ) use ( $pdf, $fonts, $col_x, $price_w, $name_w, $row_h ) {
		$pdf->SetXY( $col_x( $col_pos ), $y_pos );
		// Bold at 8.5pt stays inside both cells: the widest real strings
		// measure 13.8mm (name incl. «(متر)») and 13.5mm (price) against
		// ~18mm and ~25mm of usable width.
		$pdf->SetFont( $fonts['bold'], '', 8.5, $fonts['bold_file'] );
		$pdf->SetTextColor( 20, 20, 20 );
		$fill = $grey ? 217 : 255;
		$pdf->SetFillColor( $fill, $fill, $fill );
		$label = $row['label'];
		$price = npt_format_price( $row['price'] );
		// The meter mark rides inside the price cell, exactly like the green
		// badge sits beside the price in the online view and the site table.
		// Widest real case «۴۵,۸۰۰,۰۰۰ (متر)» measures 20.3mm vs ~25mm usable.
		if ( npt_meter_badge_enabled() && ! empty( $row['meter'] ) ) {
			$price .= ' ' . __( '(متر)', 'navid-price-table' );
		}
		// Left-to-right cell order puts the price sub-column on the left and
		// the size/name sub-column on the right, as printed.
		$pdf->Cell( $price_w, $row_h, $price, 1, 0, 'C', true );
		$pdf->Cell( $name_w, $row_h, $label, 1, 0, 'C', true );
	};

	$catalog = npt_catalog_data( $category_slug );

	$pdf->AddPage();
	$draw_header();

	if ( empty( $catalog ) ) {
		$pdf->SetFont( $fonts['regular'], '', 11, $fonts['regular_file'] );
		$pdf->SetTextColor( 30, 30, 30 );
		$pdf->SetXY( 8, 140 );
		$pdf->Cell( 194, 10, __( 'محصولی یافت نشد.', 'navid-price-table' ), 0, 1, 'C' );
		$draw_footer();
		$pdf->Output( $tmp_path, 'F' );
		npt_pdf_publish_file( $tmp_path, $output_path );
		ob_end_clean();
		error_reporting( $previous_error_reporting );
		return;
	}

	// Body: sequential first-fit flow, exactly like the printed sheet.
	// Categories are laid out top-down, right column first. A category that
	// doesn't fit in the space left in the current column starts fresh at
	// the top of the next column; only a category taller than a whole column
	// is split, continuing in the next column with its band repeated.
	foreach ( $catalog as $group ) {
		$count  = count( $group['rows'] );
		$h_need = $band_h + $count * $row_h;

		if ( $y > $y0 && $y + $h_need > $ymax ) {
			$new_column();
		}

		$drawn = 0;
		while ( $drawn < $count ) {
			// Never start a band without room for at least one row under it.
			if ( $y + $band_h + $row_h > $ymax ) {
				$new_column();
			}
			$y     = $draw_band( $col, $y, $group['name'] );
			$chunk = 0;
			while ( $drawn < $count && $y + $row_h <= $ymax ) {
				// Zebra restarts grey under every band — also under the
				// repeated band of a continuation chunk, as on the printed
				// sheet.
				$draw_row( $col, $y, $group['rows'][ $drawn ], $chunk % 2 === 0 );
				$drawn++;
				$chunk++;
				$y += $row_h;
			}
		}
	}

	$draw_footer();

	$pdf->Output( $tmp_path, 'F' );
	npt_pdf_publish_file( $tmp_path, $output_path );

	// Discard anything TCPDF/PHP wrote to the output buffer (warnings,
	// notices) instead of the file — none of it belongs in the HTTP
	// response, so it's simply thrown away.
	ob_end_clean();
	error_reporting( $previous_error_reporting );
}

/* ---------------------------------------------------------------------
 * AJAX / direct-link download endpoint (public — customers don't need
 * to be logged in to download the price list).
 * ------------------------------------------------------------------- */
add_action( 'wp_ajax_npt_download_pdf', 'npt_handle_pdf_download' );
add_action( 'wp_ajax_nopriv_npt_download_pdf', 'npt_handle_pdf_download' );

function npt_handle_pdf_download() {
	if ( ! npt_pdf_rate_limit_ok() ) {
		wp_die(
			esc_html__( 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً چند دقیقه دیگر دوباره تلاش کنید.', 'navid-price-table' ),
			esc_html__( 'محدودیت درخواست', 'navid-price-table' ),
			array( 'response' => 429 )
		);
	}

	// Environment sanity checks first, with a specific message for each —
	// this turns "nothing happens" into an actionable diagnosis instead of
	// a silent failure or a blank/broken response.
	if ( ! file_exists( NPT_PATH . 'vendor/tcpdf/tcpdf.php' ) ) {
		npt_pdf_fail( esc_html__( 'فایل‌های کتابخانه‌ی PDF (پوشه vendor/tcpdf) روی سرور یافت نشد. لطفاً مطمئن شوید کل پوشه‌ی افزونه، از جمله پوشه‌ی vendor، به‌درستی آپلود شده است.', 'navid-price-table' ) );
	}
	if ( ! file_exists( NPT_PATH . 'vendor/fonts/Vazirmatn-Regular.ttf' ) && ! file_exists( NPT_PATH . 'vendor/fonts/Sahel.ttf' ) ) {
		npt_pdf_fail( esc_html__( 'فایل فونت فارسی (vendor/fonts) روی سرور یافت نشد. لطفاً مطمئن شوید کل پوشه‌ی افزونه به‌درستی آپلود شده است.', 'navid-price-table' ) );
	}

	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) ) {
		npt_pdf_fail( esc_html__( 'پوشه uploads وردپرس در دسترس نیست:', 'navid-price-table' ) . ' ' . esc_html( $upload_dir['error'] ) );
	}
	if ( ! wp_is_writable( $upload_dir['basedir'] ) ) {
		npt_pdf_fail( esc_html__( 'پوشه wp-content/uploads روی هاست قابل‌نوشتن نیست، بنابراین امکان ساخت کش فونت/PDF وجود ندارد. این مورد را با شرکت هاستینگ یا مدیر سرور بررسی کنید.', 'navid-price-table' ) );
	}

	// Belt-and-suspenders for genuinely uncatchable fatals (e.g. memory
	// exhaustion) that a try/catch can't intercept: without this, such an
	// error mid-generation leaves the request just... stopping, which is
	// exactly the "nothing happens on click" symptom that's impossible to
	// diagnose remotely. This turns it into a readable message instead.
	register_shutdown_function( function () {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			if ( ! headers_sent() ) {
				status_header( 500 );
				header( 'Content-Type: text/plain; charset=utf-8' );
			}
			echo 'خطای داخلی سرور هنگام ساخت PDF: ' . esc_html( $error['message'] ) . ' (' . esc_html( basename( $error['file'] ) ) . ':' . absint( $error['line'] ) . ')'; // phpcs:ignore
		}
	} );

	$category   = isset( $_GET['category'] ) ? npt_known_category( sanitize_title( wp_unslash( $_GET['category'] ) ) ) : '';
	$cache_path = npt_pdf_cache_path( $category );

	// The content signature is part of the file name, so existence alone
	// means freshness: any catalog change points at a not-yet-built path.
	$is_fresh = file_exists( $cache_path );

	if ( ! $is_fresh ) {
		try {
			npt_build_pdf_file( $category, $cache_path );
			npt_pdf_cleanup_stale_cache( $category, $cache_path );
		} catch ( \Throwable $e ) {
			// Drop any partially-written temp file so failed attempts don't
			// accumulate in the cache folder.
			foreach ( glob( $cache_path . '.*.tmp' ) ?: array() as $orphan ) {
				wp_delete_file( $orphan );
			}
			// Surface the real error instead of guessing blindly — this is
			// shown to the visitor (this endpoint has no separate admin-only
			// path to log to), but it never includes any product/customer
			// data, only the internal PHP error message, which is safe to
			// see and is exactly what's needed to pin down the real cause.
			npt_pdf_fail(
				esc_html__( 'خطای فنی هنگام ساخت PDF رخ داد:', 'navid-price-table' ) . ' ' .
				esc_html( $e->getMessage() ) .
				' (' . esc_html( basename( $e->getFile() ) ) . ':' . absint( $e->getLine() ) . ')'
			);
		}
	}

	if ( ! file_exists( $cache_path ) || filesize( $cache_path ) < 100 ) {
		if ( file_exists( $cache_path ) ) {
			wp_delete_file( $cache_path );
		}
		npt_pdf_fail( esc_html__( 'ساخت فایل PDF با خطا مواجه شد (فایل خروجی خالی یا ناقص بود).', 'navid-price-table' ) );
	}

	// If anything upstream (theme, another plugin) left an output buffer
	// open, flush/discard it now — any stray byte before our headers
	// would either break header() or get prepended to the PDF stream.
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	$site_name = sanitize_title( get_bloginfo( 'name' ) );
	$filename  = 'price-list-' . $site_name . ( $category ? '-' . $category : '' ) . '.pdf';

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . filesize( $cache_path ) );
	readfile( $cache_path ); // phpcs:ignore
	exit;
}

/**
 * Stop and show a clear, specific error page instead of a blank response
 * or a corrupted file. Kept as a small helper since several checks above
 * need to bail out the same way.
 */
function npt_pdf_fail( $message ) {
	// Log the error for server-side debugging (includes remote IP and request URI)
	$ip = npt_client_ip();
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( function_exists( 'error_log' ) ) {
		error_log( sprintf( "npt_pdf_fail: %s | IP: %s | REQ: %s", wp_strip_all_tags( $message ), $ip, $req ) );
	}

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	wp_die( $message, esc_html__( 'خطا در ساخت PDF', 'navid-price-table' ), array( 'response' => 500 ) ); // phpcs:ignore
}
