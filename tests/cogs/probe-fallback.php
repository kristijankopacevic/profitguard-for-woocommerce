<?php
/**
 * What the native COGS API does once the feature is DISABLED again.
 *
 * Run with `wp eval-file` after turning cost_of_goods_sold off. This is the
 * state most real stores are in, because the feature is opt-in, so it decides
 * what ProfitGuard's fallback has to cope with.
 *
 * @package ProfitGuard
 */

$controller_class = 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController';
if ( function_exists( 'wc_get_container' ) && class_exists( $controller_class ) ) {
	$controller = wc_get_container()->get( $controller_class );
	printf(
		"feature_is_enabled_after_disable=%s\n",
		var_export( $controller->feature_is_enabled( 'cost_of_goods_sold' ), true )
	);
}

$product_id = (int) wc_get_product_id_by_sku( 'PROBE-MUG' );
printf( "FALLBACK_product_id=%d\n", $product_id );

if ( $product_id > 0 ) {
	$product = wc_get_product( $product_id );

	// Does the getter still answer, or does it refuse while disabled? This is
	// the fact that decides whether detection can rely on the getter at all.
	printf(
		"FALLBACK_get_cogs_value=%s\n",
		var_export( $product->get_cogs_value(), true )
	);
	printf(
		"FALLBACK_get_cogs_effective_value=%s\n",
		var_export( $product->get_cogs_effective_value(), true )
	);
}
