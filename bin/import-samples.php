<?php
/**
 * Import the sample CSVs through the real importer.
 *
 * Exercises delimiter detection, column suggestion, validation and the
 * duplicate-row guard. Everything except the HTTP upload, which is covered
 * by Importer::read_upload() and its unit tests.
 *
 * Development tool. Not loaded by the plugin, not in the distributable ZIP.
 *
 * @package ProfitGuard
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 ); }
use ProfitGuard\Core\Csv;
use ProfitGuard\Import\Importer;

/*
 * The samples normally sit beside bin/. On the ZIP test stack they do not: the
 * plugin there is installed from the ZIP, which contains neither directory, and
 * bin/ and samples/ are mounted separately. Look in both places and say so
 * clearly if neither has the file, rather than handing a false from
 * file_get_contents() to the parser and fataling three frames later.
 */
$candidates = array( dirname( __DIR__ ) . '/samples/', '/pgsamples/' );

foreach ( array(
	'cost'    => 'sample-product-costs.csv',
	'carrier' => 'sample-carrier-costs.csv',
) as $kind => $file ) {
	$path = '';
	foreach ( $candidates as $dir ) {
		if ( is_readable( $dir . $file ) ) {
			$path = $dir . $file;
			break;
		}
	}
	if ( '' === $path ) {
		WP_CLI::error( "Could not find {$file} in: " . implode( ', ', $candidates ) );
	}

	$text   = (string) file_get_contents( $path );
	$parsed = Csv::parse( $text );
	$rows   = $parsed['rows'];
	$header = array_shift( $rows );
	if ( ! is_array( $header ) ) {
		WP_CLI::error( "{$path} has no header row." );
	}
	$map = Csv::suggest_columns( $header );

	WP_CLI::log( strtoupper( $kind ) . ' mapping: ' . wp_json_encode( $map ) );

	$totals = ( 'cost' === $kind )
		? Importer::commit_costs( $rows, $map )
		: Importer::commit_carrier( $rows, $map );

	unset( $totals['details'] );
	WP_CLI::log( '  -> ' . wp_json_encode( $totals ) );
}//end foreach
WP_CLI::success( 'imports done' );
