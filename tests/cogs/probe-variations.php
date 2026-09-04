<?php
/**
 * Settle variation COGS semantics, rigorously.
 *
 * The first probe printed the parent's cost from the same in-memory object it
 * had just written, so it could not tell "the parent default does not
 * propagate" apart from "the parent value never persisted". Everything here is
 * re-fetched with wc_get_product() before it is read.
 *
 * The question that matters: can a variation with no cost of its own ever
 * report a usable cost, and does effective_value ever fabricate a 0.0 where the
 * honest answer is "unknown"?
 *
 * @package ProfitGuard
 */

$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'S', 'M' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );

$parent = new WC_Product_Variable();
$parent->set_name( 'Variation Probe Shirt' );
$parent->set_sku( 'VPROBE-SHIRT' );
$parent->set_attributes( array( $attribute ) );
$parent->set_cogs_value( 10.0 );
$parent->save();

// Re-fetch: this is the check the first probe was missing.
$fresh_parent = wc_get_product( $parent->get_id() );
printf(
	"PARENT_persisted_cogs=%s expect=10.0\n",
	var_export( $fresh_parent->get_cogs_value(), true )
);
printf(
	"PARENT_effective=%s\n",
	var_export( $fresh_parent->get_cogs_effective_value(), true )
);

/**
 * Build a variation and report how it resolves.
 *
 * @param int        $parent_id Parent product id.
 * @param string     $sku       Variation SKU.
 * @param string     $size      Size attribute value.
 * @param float|null $own       Variation's own cost, or null to leave unset.
 * @param bool|null  $additive  Additive flag, or null to leave at default.
 * @return void
 */
function pg_probe_variation( int $parent_id, string $sku, string $size, ?float $own, ?bool $additive ): void {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_sku( $sku );
	$variation->set_regular_price( '30.00' );
	$variation->set_attributes( array( 'size' => $size ) );

	if ( null !== $own ) {
		$variation->set_cogs_value( $own );
	}
	if ( null !== $additive ) {
		$variation->set_cogs_value_is_additive( $additive );
	}
	$variation->save();

	$fresh = wc_get_product( $variation->get_id() );
	printf(
		"VAR %-22s own=%-6s additive=%-5s => value=%-6s effective=%s\n",
		$sku,
		var_export( $own, true ),
		var_export( $additive, true ),
		var_export( $fresh->get_cogs_value(), true ),
		var_export( $fresh->get_cogs_effective_value(), true )
	);
}

// The four combinations that decide the integration rule.
pg_probe_variation( $parent->get_id(), 'VPROBE-NONE-DEFAULT', 'S', null, null );
pg_probe_variation( $parent->get_id(), 'VPROBE-NONE-ADDITIVE', 'M', null, true );
pg_probe_variation( $parent->get_id(), 'VPROBE-OWN-REPLACE', 'S', 4.0, false );
pg_probe_variation( $parent->get_id(), 'VPROBE-OWN-ADDITIVE', 'M', 4.0, true );

// A simple product with NO cost at all: does effective fabricate a zero?
$bare = new WC_Product_Simple();
$bare->set_name( 'Bare Probe' );
$bare->set_sku( 'VPROBE-BARE' );
$bare->set_regular_price( '15.00' );
$bare->save();

$fresh_bare = wc_get_product( $bare->get_id() );
printf(
	"BARE_SIMPLE value=%s effective=%s <- a 0.0 here would be a fabricated cost\n",
	var_export( $fresh_bare->get_cogs_value(), true ),
	var_export( $fresh_bare->get_cogs_effective_value(), true )
);
