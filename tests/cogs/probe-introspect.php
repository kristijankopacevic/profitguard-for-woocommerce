<?php
/**
 * Introspect the real WooCommerce COGS surface.
 *
 * Run with `wp eval-file`. Prints only facts, so the step-5 integration is
 * written against a measured API rather than against documentation.
 *
 * @package ProfitGuard
 */

printf( "WC_VERSION=%s\n", defined( 'WC_VERSION' ) ? WC_VERSION : 'undefined' );

$controller_class = 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController';
printf( "FeaturesController_class_exists=%s\n", class_exists( $controller_class ) ? 'YES' : 'no' );

if ( function_exists( 'wc_get_container' ) && class_exists( $controller_class ) ) {
	$controller = wc_get_container()->get( $controller_class );
	printf(
		"feature_is_enabled(cost_of_goods_sold)=%s\n",
		var_export( $controller->feature_is_enabled( 'cost_of_goods_sold' ), true )
	);
}

$surface = array(
	'WC_Product_Simple'      => array( 'get_cogs_value', 'set_cogs_value', 'get_cogs_effective_value', 'get_cogs_total_value' ),
	'WC_Product_Variable'    => array( 'get_cogs_value', 'set_cogs_value' ),
	'WC_Product_Variation'   => array( 'get_cogs_value', 'get_cogs_effective_value', 'get_cogs_value_is_additive', 'set_cogs_value_is_additive' ),
	'WC_Order'               => array( 'has_cogs', 'calculate_cogs_total_value', 'get_cogs_total_value', 'set_cogs_total_value' ),
	'WC_Order_Item_Product'  => array( 'get_cogs_value', 'set_cogs_value', 'calculate_cogs_value_core' ),
);

foreach ( $surface as $class_name => $methods ) {
	foreach ( $methods as $method ) {
		printf(
			"METHOD %s::%s=%s\n",
			$class_name,
			$method,
			method_exists( $class_name, $method ) ? 'YES' : 'no'
		);
	}
}
