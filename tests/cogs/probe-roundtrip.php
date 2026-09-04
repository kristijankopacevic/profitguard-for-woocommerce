<?php
/**
 * Round-trip the native COGS API on a simple product, variations and an order.
 *
 * Run with `wp eval-file` while the cost_of_goods_sold feature is ENABLED.
 * Prints measured values with the expected value beside them, so a mismatch
 * is visible without reading the assertions.
 *
 * @package ProfitGuard
 */

// Simple product: set 7.50, read it back.
$simple = new WC_Product_Simple();
$simple->set_name( 'Probe Mug' );
$simple->set_sku( 'PROBE-MUG' );
$simple->set_regular_price( '20.00' );
$simple->set_cogs_value( 7.5 );
$simple->save();

$fresh_simple = wc_get_product( $simple->get_id() );
printf( "SIMPLE_ID=%d\n", $simple->get_id() );
printf( "SIMPLE_cogs_value=%s expect=7.5\n", var_export( $fresh_simple->get_cogs_value(), true ) );
printf( "SIMPLE_cogs_effective=%s expect=7.5\n", var_export( $fresh_simple->get_cogs_effective_value(), true ) );

// Variable parent carries a DEFAULT that variations inherit.
$parent = new WC_Product_Variable();
$parent->set_name( 'Probe Shirt' );
$parent->set_sku( 'PROBE-SHIRT' );
$parent->set_cogs_value( 10.0 );
$parent->save();
printf( "PARENT_ID=%d PARENT_cogs=%s expect=10.0\n", $parent->get_id(), var_export( $parent->get_cogs_value(), true ) );

// One variation inherits, one overrides.
$inheriting = new WC_Product_Variation();
$inheriting->set_parent_id( $parent->get_id() );
$inheriting->set_sku( 'PROBE-INHERIT' );
$inheriting->set_regular_price( '30.00' );
$inheriting->save();

$overriding = new WC_Product_Variation();
$overriding->set_parent_id( $parent->get_id() );
$overriding->set_sku( 'PROBE-OVER' );
$overriding->set_regular_price( '30.00' );
$overriding->set_cogs_value( 4.0 );
$overriding->save();

foreach ( array( 'INHERIT' => $inheriting, 'OVERRIDE' => $overriding ) as $label => $variation ) {
	$fresh = wc_get_product( $variation->get_id() );
	printf(
		"VARIATION_%s own=%s effective=%s additive=%s\n",
		$label,
		var_export( $fresh->get_cogs_value(), true ),
		var_export( $fresh->get_cogs_effective_value(), true ),
		var_export( method_exists( $fresh, 'get_cogs_value_is_additive' ) ? $fresh->get_cogs_value_is_additive() : 'n/a', true )
	);
}

// Order level: 3 x the simple product at cost 7.50 should total 22.50.
$order = wc_create_order();
$order->add_product( wc_get_product( $simple->get_id() ), 3 );
$order->calculate_totals();
$order->save();

printf( "ORDER_ID=%d ORDER_has_cogs=%s\n", $order->get_id(), var_export( $order->has_cogs(), true ) );

if ( $order->has_cogs() ) {
	$order->calculate_cogs_total_value();
	$order->save();
	$fresh_order = wc_get_order( $order->get_id() );
	printf( "ORDER_cogs_total=%s expect=22.5\n", var_export( $fresh_order->get_cogs_total_value(), true ) );
	foreach ( $fresh_order->get_items() as $item ) {
		printf( "ORDER_ITEM_cogs=%s expect=22.5\n", var_export( $item->get_cogs_value(), true ) );
	}
}
