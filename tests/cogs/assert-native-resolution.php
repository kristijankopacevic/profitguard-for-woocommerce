<?php
/**
 * Assert ProfitGuard resolves native COGS the way WooCommerce does.
 *
 * Run with `wp eval-file`, with the cost_of_goods_sold feature ENABLED.
 *
 * The unit suite covers Core\CogsResolution's decision table without
 * WordPress. This covers the part that unit tests cannot reach: that
 * Woo\NativeCogs and Woo\CostProvider read a REAL WooCommerce store correctly,
 * and that the answers agree with what core itself computes at order-item
 * level. If these two ever disagree, ProfitGuard's product margins and
 * WooCommerce's own analytics disagree, and a merchant reconciling them has no
 * way to know which is wrong.
 *
 * Class names are fully qualified rather than imported: `wp eval-file` eval()s
 * the file contents, and a `use` statement - like `declare(strict_types=1)` -
 * is only legal as a real file own top-level declaration. bin/seed-demo.php
 * records the same constraint.
 *
 * @package ProfitGuard
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}


$failures = array();

/**
 * Record a pass or a failure.
 *
 * @param string $label     What was checked.
 * @param mixed  $actual    Measured value.
 * @param mixed  $expected  Wanted value.
 * @return void
 */
function pg_expect( string $label, $actual, $expected ): void {
	global $failures;
	$ok = ( $actual === $expected );
	WP_CLI::log(
		sprintf(
			'%s %s: got %s, wanted %s',
			$ok ? '  ok  ' : '  FAIL',
			$label,
			var_export( $actual, true ),
			var_export( $expected, true )
		)
	);
	if ( ! $ok ) {
		$failures[] = $label;
	}
}

\ProfitGuard\Woo\NativeCogs::reset_feature_cache();
pg_expect( 'the feature is detected as enabled', \ProfitGuard\Woo\NativeCogs::is_enabled(), true );

// ---------------------------------------------------------------------------
// 1. A simple product's native cost is read and analysed.
// ---------------------------------------------------------------------------
$simple = new WC_Product_Simple();
$simple->set_name( 'Native Assert Mug' );
$simple->set_sku( 'NA-MUG' );
$simple->set_regular_price( '20.00' );
$simple->set_cogs_value( 7.5 );
$simple->save();

$resolved = \ProfitGuard\Woo\CostProvider::get_cost( wc_get_product( $simple->get_id() ) );
pg_expect( 'simple product native cost in minor units', $resolved['cost_minor'], 750 );
pg_expect( 'simple product cost source', $resolved['source'], \ProfitGuard\Woo\CostProvider::SOURCE_NATIVE );

// ---------------------------------------------------------------------------
// 2. Variations: one overriding the parent, one inheriting the parent default.
// ---------------------------------------------------------------------------
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'S', 'M' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );

$parent = new WC_Product_Variable();
$parent->set_name( 'Native Assert Shirt' );
$parent->set_sku( 'NA-SHIRT' );
$parent->set_attributes( array( $attribute ) );
$parent->set_cogs_value( 10.0 );
$parent->save();

$inheriting = new WC_Product_Variation();
$inheriting->set_parent_id( $parent->get_id() );
$inheriting->set_sku( 'NA-INHERIT' );
$inheriting->set_regular_price( '30.00' );
$inheriting->set_attributes( array( 'size' => 'S' ) );
$inheriting->save();

$overriding = new WC_Product_Variation();
$overriding->set_parent_id( $parent->get_id() );
$overriding->set_sku( 'NA-OVERRIDE' );
$overriding->set_regular_price( '30.00' );
$overriding->set_attributes( array( 'size' => 'M' ) );
$overriding->set_cogs_value( 4.0 );
$overriding->save();

$inherited_cost = \ProfitGuard\Woo\CostProvider::get_cost( wc_get_product( $inheriting->get_id() ) );
pg_expect( 'inheriting variation resolves to the parent cost', $inherited_cost['cost_minor'], 1000 );
pg_expect( 'inheriting variation is labelled as inherited', $inherited_cost['source'], \ProfitGuard\Woo\CostProvider::SOURCE_NATIVE_INHERITED );

$overridden_cost = \ProfitGuard\Woo\CostProvider::get_cost( wc_get_product( $overriding->get_id() ) );
pg_expect( 'overriding variation resolves to its own cost', $overridden_cost['cost_minor'], 400 );
pg_expect( 'overriding variation is labelled as its own', $overridden_cost['source'], \ProfitGuard\Woo\CostProvider::SOURCE_NATIVE );

// A variation with no cost of its own, under a parent that has none either,
// must stay UNKNOWN. get_cogs_effective_value() answers 0.0 here, and a 0.0
// cost is a 100% margin - a confident wrong number. This is the case that
// proves ProfitGuard is not reading that getter.
$bare_parent = new WC_Product_Variable();
$bare_parent->set_name( 'Native Assert Bare Parent' );
$bare_parent->set_sku( 'NA-BARE-PARENT' );
$bare_parent->set_attributes( array( $attribute ) );
$bare_parent->save();

$bare_variation = new WC_Product_Variation();
$bare_variation->set_parent_id( $bare_parent->get_id() );
$bare_variation->set_sku( 'NA-BARE-VAR' );
$bare_variation->set_regular_price( '25.00' );
$bare_variation->set_attributes( array( 'size' => 'S' ) );
$bare_variation->save();

$bare_cost = \ProfitGuard\Woo\CostProvider::get_cost( wc_get_product( $bare_variation->get_id() ) );
pg_expect( 'a variation with no cost anywhere stays unknown, not zero', $bare_cost['cost_minor'], null );
pg_expect( 'a variation with no cost anywhere reports no source', $bare_cost['source'], \ProfitGuard\Woo\CostProvider::SOURCE_NONE );

// ---------------------------------------------------------------------------
// 3. ProfitGuard's product-level answer AGREES with core's order-item answer.
//    This is the reconciliation property, and it is the whole point.
// ---------------------------------------------------------------------------
foreach ( array( 'NA-INHERIT' => 1000, 'NA-OVERRIDE' => 400 ) as $sku => $expected_unit_minor ) {
	$variation_id = (int) wc_get_product_id_by_sku( $sku );
	$order        = wc_create_order();
	$order->add_product( wc_get_product( $variation_id ), 2 );
	$order->calculate_totals();
	$order->save();

	if ( $order->has_cogs() ) {
		$order->calculate_cogs_total_value();
		$order->save();
	}

	$fresh      = wc_get_order( $order->get_id() );
	$core_total = \ProfitGuard\Woo\NativeCogs::get_order_total( $fresh );

	// Core multiplies by quantity itself, so 2 x the unit cost.
	pg_expect(
		sprintf( 'core order total for %s matches ProfitGuard unit cost x qty', $sku ),
		$core_total,
		$expected_unit_minor * 2
	);
}

// ---------------------------------------------------------------------------
// 4. The order-level total is read rather than recomputed.
// ---------------------------------------------------------------------------
$simple_order = wc_create_order();
$simple_order->add_product( wc_get_product( $simple->get_id() ), 3 );
$simple_order->calculate_totals();
$simple_order->save();
if ( $simple_order->has_cogs() ) {
	$simple_order->calculate_cogs_total_value();
	$simple_order->save();
}
$normalised = \ProfitGuard\Woo\Orders::normalise( wc_get_order( $simple_order->get_id() ) );
pg_expect( 'normalised order carries the native goods cost', $normalised['goods_cost_minor'], 2250 );

// ---------------------------------------------------------------------------
// 5. Nothing was written into a third-party or private key behind the scenes.
// ---------------------------------------------------------------------------
$private = get_post_meta( $simple->get_id(), \ProfitGuard\Woo\CostProvider::META_COST_MINOR, true );
pg_expect( 'no shadow ProfitGuard cost meta was written alongside the native value', ( '' === $private || null === $private ), true );

if ( ! empty( $failures ) ) {
	WP_CLI::error( count( $failures ) . ' native resolution assertion(s) failed: ' . implode( ', ', $failures ) );
}

WP_CLI::success( 'NATIVE_RESOLUTION_PASS' );
