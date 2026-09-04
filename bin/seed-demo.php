<?php
/**
 * Demo fixture data for local development.
 *
 * Run it with:
 *
 *     docker compose run --rm wpcli "wp eval-file wp-content/plugins/profitguard-for-woocommerce/bin/seed-demo.php"
 *
 * Builds a store that produces every finding type, so the dashboard, the
 * findings table and the screenshots all have something real to show.
 *
 * DELIBERATELY IMPERFECT. A third of the products have no cost, some are on
 * sale, one sells below cost, and only some orders will have a carrier row.
 * A demo where everything computes cleanly hides exactly the behaviour that
 * matters most - what the plugin does when data is missing.
 *
 * This file is a development tool. It is NOT loaded by the plugin and is
 * excluded from the distributable ZIP.
 *
 * No `declare(strict_types=1)` here: `wp eval-file` eval()s the contents, and
 * a strict_types declaration is only legal as the very first statement of a
 * real file.
 *
 * @package ProfitGuard
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/*
 * Both sample CSVs are denominated in EUR, and so is every price this script
 * writes. WooCommerce defaults a fresh install to USD, and the importer then
 * correctly rejects all 20 cost rows as a currency mismatch - correct
 * behaviour, useless demo. Set the store currency to match the data it is
 * about to be given.
 */
update_option( 'woocommerce_currency', 'EUR' );

/*
 * Clear ProfitGuard's own analytics tables.
 *
 * The seeder deletes and recreates its demo products and orders, which means
 * every previously imported carrier row now points at an order id that no
 * longer exists. Left in place they accumulate: a second run turned one
 * POSSIBLE_DUPLICATE_CARRIER_ROW into seventy-one, because from the detector's
 * point of view the store really did have two invoices for every shipment.
 *
 * This is a development script for a throwaway store. It says so, loudly,
 * because the same statement on a real store would delete a merchant's
 * imported carrier invoices.
 */
global $wpdb;
WP_CLI::log( 'Clearing ProfitGuard findings, carrier rows and run history.' );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}profitguard_findings" );
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}profitguard_carrier_costs" );
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}profitguard_runs" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'profitguard_scan_state' );

/**
 * Where to write the regenerated sample CSVs.
 *
 * Normally samples/ sits beside bin/; on the ZIP test stack the two are mounted
 * separately, so look there too. Returns '' when neither is writable, which is
 * not an error - the committed samples are already correct, because this script
 * is deterministic.
 *
 * @param string $file File name.
 * @return string Writable path, or '' when there is nowhere to write.
 */
function profitguard_sample_path( $file ) {
	foreach ( array( __DIR__ . '/../samples/', '/pgsamples/' ) as $dir ) {
		if ( is_dir( $dir ) && is_writable( $dir ) ) {
			return $dir . $file;
		}
	}
	return '';
}


use ProfitGuard\Woo\CostProvider;

/**
 * A seeded pseudo-random generator.
 *
 * Seeded with mt_srand so the demo store is identical on every machine -
 * otherwise screenshots, documentation figures and any expectation written
 * against them drift apart.
 */
mt_srand( 20260831 );

$words = array(
	'Wireless Charger',
	'USB-C Cable',
	'Bluetooth Speaker',
	'Laptop Stand',
	'Desk Mat',
	'Phone Case',
	'Screen Protector',
	'Power Bank',
	'Webcam Cover',
	'Cable Organiser',
	'Travel Adapter',
	'Wrist Rest',
	'Monitor Riser',
	'Headphone Hook',
	'Tablet Sleeve',
	'Car Mount',
	'Ring Light',
	'Mouse Pad',
	'Docking Station',
	'Stylus Pen',
);

/**
 * Remove anything a previous run of this script created.
 *
 * Re-running a fixture is the normal case - you tweak it and run it again - and
 * a script that fatals on the second run with "duplicated SKU" is a bad tool.
 *
 * ONLY ProfitGuard's own demo data is removed. Every product and order this
 * script creates is tagged with _profitguard_demo, and the cleanup is keyed on
 * that tag: it can never touch a real product or a real order, even if someone
 * runs it against a store with data in it.
 */
$purged_products = 0;
$purged_orders   = 0;

$existing = get_posts(
	array(
		'post_type'   => array( 'product', 'product_variation' ),
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
		'meta_key'    => '_profitguard_demo',
		'meta_value'  => '1',
	)
);
foreach ( $existing as $id ) {
	wp_delete_post( (int) $id, true );
	++$purged_products;
}

/*
 * Also sweep by the SKU prefix this fixture owns.
 *
 * Needed for products created before the _profitguard_demo tag existed, and as
 * a belt-and-braces match: the prefix is ours, so nothing a merchant created
 * can collide with it.
 */
for ( $n = 0; $n < 400; $n++ ) {
	$stale = wc_get_product_id_by_sku( sprintf( 'PG-%04d', 1000 + $n ) );
	if ( $stale ) {
		wp_delete_post( (int) $stale, true );
		++$purged_products;
	}
}

foreach ( wc_get_orders(
	array(
		'limit'  => -1,
		'return' => 'ids',
		'status' => 'any',
	)
) as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order && '1' === (string) $order->get_meta( '_profitguard_demo' ) ) {
		$order->delete( true );
		++$purged_orders;
	}
}

if ( $purged_products || $purged_orders ) {
	WP_CLI::log( sprintf( 'Removed previous demo data: %d products, %d orders.', $purged_products, $purged_orders ) );
}

WP_CLI::log( 'Seeding demo products...' );

$created   = 0;
$with_cost = 0;
$skus      = array();

for ( $i = 0; $i < 60; $i++ ) {
	$name = $words[ $i % count( $words ) ];
	if ( $i >= count( $words ) ) {
		$name .= ' Mk' . ( (int) floor( $i / count( $words ) ) + 1 );
	}

	$sku   = sprintf( 'PG-%04d', 1000 + $i );
	$price = mt_rand( 500, 9500 ) / 100;

	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_sku( $sku );
	$product->set_regular_price( (string) $price );
	$product->set_status( 'publish' );
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );

	// One in six is on sale, sometimes steeply enough to break the margin.
	if ( 0 === $i % 6 ) {
		$product->set_sale_price( (string) round( $price * ( mt_rand( 45, 85 ) / 100 ), 2 ) );
	}

	$product_id = $product->save();
	if ( ! $product_id ) {
		continue;
	}
	update_post_meta( $product_id, '_profitguard_demo', '1' );
	++$created;
	$skus[] = $sku;

	// A THIRD have no cost at all. This is the most realistic thing in the
	// fixture and it is what makes the "missing a cost" prompt appear.
	if ( 0 === $i % 3 ) {
		continue;
	}

	// Margins spread around the 30% target so the scan finds a mix.
	$margin_bp = mt_rand( -400, 6000 );
	$cost      = (int) round( ( $price * 100 ) * ( 10000 - $margin_bp ) / 10000 );

	// One deliberate below-cost product so the CRITICAL rule always fires.
	if ( 3 === $i ) {
		$cost = (int) round( $price * 100 * 1.18 );
	}

	CostProvider::set_cost( $product_id, max( 1, $cost ) );
	++$with_cost;
}//end for

WP_CLI::log( sprintf( '  %d products, %d with a cost.', $created, $with_cost ) );

/*
 * A cost increase on a handful of products, so the COST_INCREASE rule has
 * something to find. Setting the cost twice is what records a previous value -
 * the same path a second import takes.
 */
$bumped = 0;
foreach ( array_slice( $skus, 5, 6 ) as $sku ) {
	$product_id = wc_get_product_id_by_sku( $sku );
	if ( ! $product_id ) {
		continue;
	}
	$current = get_post_meta( $product_id, CostProvider::META_COST_MINOR, true );
	if ( '' === $current ) {
		continue;
	}
	CostProvider::set_cost( $product_id, (int) round( (int) $current * 1.22 ) );
	++$bumped;
}
WP_CLI::log( sprintf( '  %d products given a cost increase.', $bumped ) );

WP_CLI::log( 'Seeding demo orders...' );

$orders      = 0;
$refs        = array();
$product_ids = wc_get_products(
	array(
		'limit'      => -1,
		'return'     => 'ids',
		'meta_key'   => '_profitguard_demo',
		'meta_value' => '1',
	)
);
if ( empty( $product_ids ) ) {
	WP_CLI::error( 'No demo products to build orders from.' );
}

for ( $i = 0; $i < 120; $i++ ) {
	$order = wc_create_order();
	if ( is_wp_error( $order ) ) {
		continue;
	}

	$lines = mt_rand( 1, 3 );
	for ( $l = 0; $l < $lines; $l++ ) {
		$pid     = $product_ids[ array_rand( $product_ids ) ];
		$product = wc_get_product( $pid );
		if ( $product ) {
			$order->add_product( $product, mt_rand( 1, 3 ) );
		}
	}

	// Free shipping over EUR 50 - the commonest policy, and the commonest
	// source of shipping losses once a carrier bill arrives.
	$order->calculate_totals();
	$subtotal = (float) $order->get_subtotal();
	$charged  = $subtotal >= 50 ? 0.0 : round( mt_rand( 499, 999 ) / 100, 2 );

	/*
	 * Every fourth order charges a flat EUR 19.90 - a heavier or express rate.
	 * Carrier costs below top out around EUR 18, so these are the orders where
	 * shipping actually turns a profit. Without them the fixture would only
	 * ever produce losses, and SHIPPING_PROFIT would never be exercised: a
	 * demo that cannot show a good outcome is not a demo of a health tool.
	 */
	if ( 0 === $i % 4 ) {
		$charged = 19.90;
	}

	$item = new WC_Order_Item_Shipping();
	$item->set_method_title( 'Flat rate' );
	$item->set_method_id( 'flat_rate' );
	$item->set_total( (string) $charged );
	$order->add_item( $item );

	$order->calculate_totals();
	$order->set_status( 0 === $i % 5 ? 'processing' : 'completed' );
	$order->update_meta_data( '_profitguard_demo', '1' );
	$order->save();

	++$orders;
	$refs[] = $order->get_order_number();
}//end for

WP_CLI::log( sprintf( '  %d orders.', $orders ) );

/*
 * A carrier invoice covering about 40% of the orders, written to the samples
 * directory so it can be imported through the real UI rather than injected
 * into the database. That means the import path itself gets exercised.
 */
$rows = array( 'Order Number,Tracking Number,Carrier,Actual Shipping Cost,Currency' );
$n    = 0;
foreach ( $refs as $index => $ref ) {
	if ( 0 !== $index % 2 && 0 !== $index % 5 ) {
		continue;
	}
	$base = mt_rand( 450, 1800 ) / 100;

	// One deliberate outlier and one deliberate duplicate tracking number.
	if ( 17 === $index ) {
		$base = 151.99;
	}
	$tracking = ( 25 === $index || 26 === $index ) ? 'JD0000000042' : sprintf( 'JD%010d', 700000 + $index );

	$rows[] = sprintf( '%s,%s,DHL,%.2f,EUR', $ref, $tracking, $base );
	++$n;
}

/*
 * Two rows for orders that do not exist. Carrier invoices routinely contain
 * shipments from a different channel, a cancelled order, or a typo, and the
 * merchant needs to see them flagged rather than silently dropped. This is what
 * makes UNMATCHED_CARRIER_ROW appear.
 */
$rows[] = 'PG-NOT-AN-ORDER-1,JD0000999001,DHL,7.40,EUR';
$rows[] = '99999999,JD0000999002,GLS,12.15,EUR';
$n     += 2;

$path = profitguard_sample_path( 'sample-carrier-costs.csv' );
if ( '' === $path ) {
	// profitguard_sample_path() documents '' as "nowhere writable", and says
	// that is not an error because the committed samples are already correct.
	// Writing to it anyway was a ValueError waiting to happen, and it happened
	// on CI, where samples/ is mounted read-only precisely so the committed
	// fixtures are the ones under test.
	WP_CLI::log( sprintf( '  %d carrier rows generated; samples/ is read-only, keeping the committed CSV', $n ) );
} else {
	file_put_contents( $path, implode( "\n", $rows ) . "\n" );
	WP_CLI::log( sprintf( '  %d carrier rows written to samples/sample-carrier-costs.csv', $n ) );
}

/*
 * A product cost list covering products the fixture deliberately left without
 * one, so importing it visibly reduces the "missing a cost" count.
 */

/*
 * SEMICOLON-DELIMITED, on purpose.
 *
 * The costs below use a comma decimal separator, which is how most of Europe
 * writes them - and a comma decimal inside a comma-delimited file is simply
 * malformed. Real European spreadsheet exports use a semicolon for exactly
 * this reason, so the sample does too. It also means the sample exercises
 * Csv::detect_delimiter(), which scores on column-count consistency precisely
 * because a file like this one has more commas than semicolons.
 */
$cost_rows = array( 'SKU;Cost;Currency;Product Name' );
$c         = 0;
foreach ( $skus as $index => $sku ) {
	if ( 0 !== $index % 3 ) {
		continue;
	}

	/*
	 * Deliberately leave every fourth one OUT of the supplier list. A real
	 * cost list never covers the whole catalogue, and if the import filled
	 * every gap then the state this plugin exists to make visible - a cost we
	 * simply do not have - would vanish from the demo the moment you imported.
	 * These products keep producing MISSING_COST, and keep coverage below
	 * 100%, which is the honest picture.
	 */
	if ( 0 === $index % 12 ) {
		continue;
	}

	$product_id = wc_get_product_id_by_sku( $sku );
	$product    = $product_id ? wc_get_product( $product_id ) : null;
	if ( ! $product ) {
		continue;
	}
	$price = (float) $product->get_regular_price();
	// Costs written in EUROPEAN format, on purpose: the importer has to cope
	// with a comma decimal separator, and a sample that only uses dots would
	// never prove it.
	$cost        = number_format( $price * ( mt_rand( 40, 95 ) / 100 ), 2, ',', '' );
	$cost_rows[] = sprintf( '%s;%s;EUR;%s', $sku, $cost, $product->get_name() );
	++$c;
}//end foreach

$cost_path = profitguard_sample_path( 'sample-product-costs.csv' );
if ( '' === $cost_path ) {
	WP_CLI::log( sprintf( '  %d cost rows generated; samples/ is read-only, keeping the committed CSV', $c ) );
} else {
	file_put_contents( $cost_path, implode( "\n", $cost_rows ) . "\n" );
	WP_CLI::log( sprintf( '  %d cost rows written to samples/sample-product-costs.csv', $c ) );
}

WP_CLI::success( 'Demo data seeded. Run: wp profitguard scan (or press Run Profit Scan in the admin).' );
