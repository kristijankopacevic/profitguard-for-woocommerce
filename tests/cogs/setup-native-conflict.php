<?php
/**
 * Create a genuine native-cost conflict for the import guard to catch.
 *
 * bin/seed-demo.php writes the sample CSV *from* the store it just built, so
 * importing that CSV back finds every cost already equal and reports "no
 * change" - which would never exercise the overwrite guard and would let a
 * broken guard pass CI.
 *
 * This deliberately sets a DIFFERENT native cost on the first SKU in the CSV,
 * so re-importing it is a real replacement of a value a merchant would have
 * entered in the product editor.
 *
 * Run with `wp eval-file`, with the cost_of_goods_sold feature ENABLED.
 *
 * @package ProfitGuard
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$csv_candidates = array( '/pgsamples/sample-product-costs.csv', __DIR__ . '/../../samples/sample-product-costs.csv' );
$csv_path       = '';
foreach ( $csv_candidates as $candidate ) {
	if ( file_exists( $candidate ) ) {
		$csv_path = $candidate;
		break;
	}
}
if ( '' === $csv_path ) {
	WP_CLI::error( 'sample-product-costs.csv not found in any expected location' );
}

$handle = fopen( $csv_path, 'rb' );
if ( false === $handle ) {
	WP_CLI::error( 'could not open ' . $csv_path );
}

// The seeder writes a semicolon-delimited file with a header row.
$header = fgetcsv( $handle, 0, ';' );
$first  = fgetcsv( $handle, 0, ';' );
fclose( $handle );

if ( ! is_array( $first ) || ! isset( $first[0] ) ) {
	WP_CLI::error( 'could not read a data row from ' . $csv_path );
}

$sku = trim( (string) $first[0] );
$product_id = (int) wc_get_product_id_by_sku( $sku );
if ( $product_id < 1 ) {
	WP_CLI::error( 'no product for SKU ' . $sku );
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
	WP_CLI::error( 'could not load product ' . $product_id );
}

// A value no generated cost will coincide with.
$conflict = 1.11;
$product->set_cogs_value( $conflict );
$product->save();

$reread = wc_get_product( $product_id );
WP_CLI::log( sprintf( 'CONFLICT_SKU=%s', $sku ) );
WP_CLI::log( sprintf( 'CONFLICT_CSV_COST=%s', isset( $first[1] ) ? (string) $first[1] : '?' ) );
WP_CLI::log( sprintf( 'CONFLICT_NATIVE_COST=%s', var_export( $reread->get_cogs_value(), true ) ) );

if ( (float) $reread->get_cogs_value() !== $conflict ) {
	WP_CLI::error( 'the conflicting native cost did not persist' );
}

WP_CLI::success( 'NATIVE_CONFLICT_READY' );
