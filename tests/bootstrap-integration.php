<?php
/**
 * Integration test bootstrap: loads a real WordPress + WooCommerce site with this plugin active.
 *
 * Create the site with bin/install-wp-tests.sh (SQLite, no MySQL needed) or point
 * PNSCRIPTS_OMNIBUS_WP_DIR at another disposable install. Never point it at a real shop.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

$pnscripts_omnibus_wp_dir = getenv( 'PNSCRIPTS_OMNIBUS_WP_DIR' );
if ( false === $pnscripts_omnibus_wp_dir || '' === $pnscripts_omnibus_wp_dir ) {
	$pnscripts_omnibus_wp_dir = __DIR__ . '/.wp/wordpress';
}
if ( ! is_file( $pnscripts_omnibus_wp_dir . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress test site not found. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

require_once $pnscripts_omnibus_wp_dir . '/wp-load.php';

if ( null === \Pnscripts\Omnibus\Plugin::instance() ) {
	fwrite( STDERR, "PN Scripts Price History is not active on the test site.\n" );
	exit( 1 );
}
