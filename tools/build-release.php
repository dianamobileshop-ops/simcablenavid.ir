<?php
/**
 * Builds a release: rebuilds the CSS, zips the theme and writes update.json.
 * Run from the repository root:  php tools/build-release.php "short changelog text"
 * Upload navid-theme-X.zip and update.json to https://simcablenavid.ir/navid/ .
 */
$root = dirname( __DIR__ );
passthru( 'php ' . escapeshellarg( __DIR__ . '/build-css.php' ) );
preg_match( '/^Version:\s*(\S+)/m', file_get_contents( $root . '/navid/style.css' ), $m );
$version = $m[1];
$zip     = $root . '/navid-theme-' . $version . '.zip';
@unlink( $zip );
passthru( 'cd ' . escapeshellarg( $root ) . ' && zip -qr ' . escapeshellarg( basename( $zip ) ) . ' navid' );
$json = array(
	'version'   => $version,
	'package'   => 'https://simcablenavid.ir/navid/navid-theme-' . $version . '.zip',
	'url'       => 'https://simcablenavid.ir/navid',
	'changelog' => isset( $argv[1] ) ? $argv[1] : '',
);
file_put_contents( $root . '/update.json', json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" );
echo "Built $zip and update.json (version $version)\n";
