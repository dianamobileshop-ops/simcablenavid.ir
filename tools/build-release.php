<?php
/**
 * Builds the theme zip locally (the GitHub release workflow does the same on a tag).
 * Run from the repository root:  php tools/build-release.php
 */
$root = dirname( __DIR__ );
passthru( 'php ' . escapeshellarg( __DIR__ . '/build-css.php' ) );
preg_match( '/^Version:\s*(\S+)/m', file_get_contents( $root . '/navid/style.css' ), $m );
$version = $m[1];
$zip     = $root . '/navid-theme-' . $version . '.zip';
@unlink( $zip );
passthru( 'cd ' . escapeshellarg( $root ) . ' && zip -qr ' . escapeshellarg( basename( $zip ) ) . ' navid' );
echo "Built $zip (version $version)\n";
