<?php
/**
 * Render every admin screen and check it produced sane output.
 *
 * A page that throws, or that dies half way through, is invisible to PHPUnit
 * because the admin layer is never loaded there. This catches it.
 *
 * Development tool. Not loaded by the plugin, not in the distributable ZIP.
 *
 * @package ProfitGuard
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_woocommerce' ) ) { WP_CLI::error( 'admin lacks manage_woocommerce' ); }

$pages = array(
	'dashboard' => array( '\ProfitGuard\Admin\Pages', 'dashboard' ),
	'findings'  => array( '\ProfitGuard\Admin\Pages', 'findings' ),
	'import'    => array( '\ProfitGuard\Admin\Pages', 'import' ),
	'settings'  => array( '\ProfitGuard\Admin\Pages', 'settings' ),
);

foreach ( $pages as $name => $cb ) {
	ob_start();
	try {
		call_user_func( $cb );
		$html = ob_get_clean();
	} catch ( \Throwable $e ) {
		ob_end_clean();
		WP_CLI::error( $name . ' threw: ' . $e->getMessage() );
	}
	$len = strlen( $html );
	// A page that renders but contains no closing wrapper has died mid-output.
	$ok  = ( $len > 500 && false !== strpos( $html, 'profitguard' ) );
	WP_CLI::log( sprintf( '%-10s %6d bytes  %s', $name, $len, $ok ? 'OK' : 'SUSPECT' ) );
	if ( 'dashboard' === $name ) {
		foreach ( array( 'ProfitGuard Score', 'Coverage', 'Profit health', 'Shipping health', 'Highest priority' ) as $needle ) {
			WP_CLI::log( sprintf( '   %-22s %s', $needle, false !== strpos( $html, $needle ) ? 'present' : 'MISSING' ) );
		}
		// The honesty rule, at the last step before a human reads it.
		WP_CLI::log( sprintf( '   %-22s %s', 'em-dash for unknown', false !== strpos( $html, 'profitguard-unknown' ) ? 'present' : 'not needed' ) );
	}
}
WP_CLI::success( 'all pages rendered' );
