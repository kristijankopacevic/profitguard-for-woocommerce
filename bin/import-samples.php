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

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
use ProfitGuard\Core\Csv;
use ProfitGuard\Import\Importer;

$base = dirname( __DIR__ ) . '/samples/';

foreach ( array( 'cost' => 'sample-product-costs.csv', 'carrier' => 'sample-carrier-costs.csv' ) as $kind => $file ) {
	$text   = file_get_contents( $base . $file );
	$parsed = Csv::parse( $text );
	$rows   = $parsed['rows'];
	$header = array_shift( $rows );
	$map    = Csv::suggest_columns( $header );

	WP_CLI::log( strtoupper( $kind ) . ' mapping: ' . wp_json_encode( $map ) );

	$totals = ( 'cost' === $kind )
		? Importer::commit_costs( $rows, $map )
		: Importer::commit_carrier( $rows, $map );

	unset( $totals['details'] );
	WP_CLI::log( '  -> ' . wp_json_encode( $totals ) );
}
WP_CLI::success( 'imports done' );
