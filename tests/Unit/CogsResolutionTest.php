<?php
/**
 * Variation cost resolution against a parent default.
 *
 * The three cases marked MEASURED are transcribed from what WooCommerce 11.1.0
 * actually produced at order-item level, recorded in
 * tests/cogs/MEASURED-FACTS.md. They are the contract: if these change,
 * ProfitGuard's product-level margins stop reconciling with WooCommerce's own
 * analytics on variable products.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProfitGuard\Core\CogsResolution;

final class CogsResolutionTest extends TestCase {

	// The three rules WooCommerce itself applies, measured on 11.1.0.

	public function test_measured_a_variation_with_no_cost_inherits_the_parent(): void {
		// MEASURED: own null, parent 10.00, qty 2 -> core order item 20.00.
		$resolved = CogsResolution::resolve( null, 1000, false );

		$this->assertSame( 1000, $resolved['cost_minor'] );
		$this->assertSame( CogsResolution::BASIS_INHERITED, $resolved['basis'] );
	}

	public function test_measured_an_additive_variation_adds_to_the_parent(): void {
		// MEASURED: own 4.00, parent 10.00, additive, qty 2 -> item 28.00,
		// i.e. a unit cost of 14.00, not 4.00 and not 10.00.
		$resolved = CogsResolution::resolve( 400, 1000, true );

		$this->assertSame( 1400, $resolved['cost_minor'] );
		$this->assertSame( CogsResolution::BASIS_COMBINED, $resolved['basis'] );
	}

	public function test_measured_a_non_additive_variation_replaces_the_parent(): void {
		// MEASURED: own 4.00, parent 10.00, not additive, qty 2 -> item 8.00.
		$resolved = CogsResolution::resolve( 400, 1000, false );

		$this->assertSame( 400, $resolved['cost_minor'] );
		$this->assertSame( CogsResolution::BASIS_OWN, $resolved['basis'] );
	}

	// Missing data stays missing. This is the property the whole plugin rests
	// on, and it is the one get_cogs_effective_value() breaks by answering 0.0.

	public function test_no_cost_anywhere_is_unknown_and_never_zero(): void {
		$resolved = CogsResolution::resolve( null, null, false );

		$this->assertNull( $resolved['cost_minor'] );
		$this->assertSame( CogsResolution::BASIS_UNKNOWN, $resolved['basis'] );
	}

	public function test_no_cost_anywhere_is_unknown_even_when_additive(): void {
		// An additive flag cannot manufacture a cost out of two absent ones.
		$resolved = CogsResolution::resolve( null, null, true );

		$this->assertNull( $resolved['cost_minor'] );
		$this->assertSame( CogsResolution::BASIS_UNKNOWN, $resolved['basis'] );
	}

	public function test_an_additive_variation_under_a_costless_parent_uses_its_own_cost(): void {
		// Treating the absent parent as 0 and reporting "combined" would state
		// a relationship that does not exist. The answer is its own cost.
		$resolved = CogsResolution::resolve( 400, null, true );

		$this->assertSame( 400, $resolved['cost_minor'] );
		$this->assertSame( CogsResolution::BASIS_OWN, $resolved['basis'] );
	}

	// A real zero is a real answer, and has to survive being distinguishable
	// from absence - which is the entire reason the type is nullable.

	public function test_a_genuine_zero_cost_is_kept_and_not_treated_as_missing(): void {
		$resolved = CogsResolution::resolve( 0, 1000, false );

		$this->assertSame( 0, $resolved['cost_minor'] );
		$this->assertSame( CogsResolution::BASIS_OWN, $resolved['basis'] );
	}

	public function test_a_parent_zero_is_inherited_rather_than_read_as_absent(): void {
		$resolved = CogsResolution::resolve( null, 0, false );

		$this->assertSame( 0, $resolved['cost_minor'] );
		$this->assertSame( CogsResolution::BASIS_INHERITED, $resolved['basis'] );
	}

	public function test_an_additive_variation_adds_to_a_zero_parent(): void {
		$resolved = CogsResolution::resolve( 400, 0, true );

		$this->assertSame( 400, $resolved['cost_minor'] );
		$this->assertSame( CogsResolution::BASIS_COMBINED, $resolved['basis'] );
	}
}
