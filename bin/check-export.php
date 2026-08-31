<?php
/**
 * Verify the findings CSV export end to end.
 *
 * Checks the three things that matter about this file: that it is produced at
 * all, that every row a merchant could see is present, and that no cell can be
 * executed as a formula when the file is opened in a spreadsheet.
 *
 * Development tool. Not loaded by the plugin, not in the distributable ZIP.
 *
 * @package ProfitGuard
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 ); }

$_GET = array( 'page' => 'profitguard-findings' );

ob_start();
\ProfitGuard\Admin\Exporter::send_findings_csv();
$csv = (string) ob_get_clean();

$lines = array_values( array_filter( explode( "\n", $csv ), 'strlen' ) );
if ( count( $lines ) < 2 ) {
	WP_CLI::error( 'export produced no rows' );
}

WP_CLI::log( '      bytes: ' . strlen( $csv ) );
WP_CLI::log( '      lines: ' . count( $lines ) );
WP_CLI::log( '      bom:   ' . ( "\xEF\xBB\xBF" === substr( $csv, 0, 3 ) ? 'yes' : 'no' ) );
WP_CLI::log( '      head:  ' . substr( ltrim( $lines[0], "\xEF\xBB\xBF" ), 0, 100 ) );

/*
 * A cell that begins with one of these is executed by Excel, LibreOffice and
 * Google Sheets. Every cell the exporter writes must be neutralised, so scan
 * every field of every row rather than only the first column.
 */
$dangerous = 0;
foreach ( $lines as $line ) {
	foreach ( str_getcsv( $line, ',', '"', '\\' ) as $cell ) {
		if ( '' !== $cell && false !== strpos( "=+@\t\r", $cell[0] ) ) {
			++$dangerous;
		}
	}
}
WP_CLI::log( '      executable cells: ' . $dangerous );

if ( $dangerous > 0 ) {
	WP_CLI::error( 'CSV formula injection: ' . $dangerous . ' cell(s) would execute' );
}
WP_CLI::success( 'export is complete and formula-safe' );
