<?php
/**
 * Builds navid/assets/css/navid.min.css from navid/style.css.
 * Run: php tools/build-css.php   (the theme uses style.css whenever the build is older).
 */
$root = dirname( __DIR__ ) . '/navid';
$css  = file_get_contents( $root . '/style.css' );
$css  = preg_replace( '#/\*.*?\*/#s', '', $css );                 // comments (incl. the theme header)
$css  = preg_replace( '/\s+/', ' ', $css );                         // whitespace runs
$css  = preg_replace( '/\s*([{};,])\s*/', '$1', $css );             // around braces, semicolons, commas
$css  = preg_replace( '/:\s+/', ':', $css );                        // after colons (selectors never have ": ")
$css  = str_replace( ';}', '}', $css );
// The file moves two folders down: relative urls must follow.
$css  = preg_replace_callback( '/url\(\s*([\'"]?)(?!data:|https?:|\/)([^\'")]+)\1\s*\)/', function ( $m ) {
	return 'url(' . $m[1] . '../../' . $m[2] . $m[1] . ')';
}, $css );
@mkdir( $root . '/assets/css', 0755, true );
file_put_contents( $root . '/assets/css/navid.min.css', trim( $css ) . "\n" );
printf( "style.css %d bytes -> navid.min.css %d bytes\n", filesize( $root . '/style.css' ), filesize( $root . '/assets/css/navid.min.css' ) );
