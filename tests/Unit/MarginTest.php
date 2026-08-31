<?php
/**
 * The financial core. If anything in this file goes red, the plugin is lying to
 * merchants about their money and must not ship.
 *
 * Ported from the ProfitGuard TypeScript suite (tests/margin.test.ts),
 * including the property test over the full cost x target grid.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProfitGuard\Core\Margin;

final class MarginTest extends TestCase {

	// Gross margin.

	public function test_gross_margin_divides_by_the_selling_price(): void {
		// EUR 10.00 selling, EUR 8.00 cost -> 20%
		$this->assertSame( 2000, Margin::gross_margin_bp( 1000, 800 ) );
		// EUR 1.05 selling, EUR 0.72 cost -> 31.43%
		$this->assertSame( 3143, Margin::gross_margin_bp( 105, 72 ) );
	}

	public function test_reproduces_the_worked_example_from_the_specification(): void {
		/*
		 * Wireless Charger: EUR 29.99 selling, EUR 24.50 cost, 30% target,
		 * recommended EUR 35.00.
		 *
		 * The specification quotes the margin as "18.3%". The exact value is
		 * 549 / 2999 = 18.306%, which this engine carries as 1831 basis points
		 * and renders at two decimals. The spec figure is that same number
		 * rounded for display, not a different answer - but it is worth an
		 * explicit note, because a reader comparing the two would otherwise
		 * think one of them is wrong.
		 */
		$margin = Margin::gross_margin_bp( 2999, 2450 );
		$this->assertSame( 1831, $margin );
		$this->assertSame( '18.31%', \ProfitGuard\Core\Money::format_percent_bp( $margin ) );
		$this->assertSame( 3500, Margin::recommended_price_minor( 2450, 3000 ) );
	}

	public function test_gross_margin_returns_null_rather_than_zero_when_unknown(): void {
		$this->assertNull( Margin::gross_margin_bp( null, 800 ) );
		$this->assertNull( Margin::gross_margin_bp( 1000, null ) );
		// No selling price means no margin - not a margin of zero.
		$this->assertNull( Margin::gross_margin_bp( 0, 800 ) );
	}

	public function test_gross_margin_preserves_a_negative_result(): void {
		// Selling below cost is the point of the product; it must survive.
		$this->assertSame( -2000, Margin::gross_margin_bp( 1000, 1200 ) );
	}

	public function test_gross_margin_rejects_a_negative_selling_price(): void {
		$this->assertNull( Margin::gross_margin_bp( -1000, 800 ) );
	}

	public function test_a_free_product_with_a_cost_has_no_margin(): void {
		$this->assertNull( Margin::gross_margin_bp( 0, 500 ) );
	}

	public function test_a_zero_cost_product_has_a_full_margin(): void {
		$this->assertSame( 10000, Margin::gross_margin_bp( 1000, 0 ) );
	}

	// Markup - deliberately not derived from margin.

	public function test_markup_divides_by_the_cost(): void {
		// Buying at EUR 8 and selling at EUR 10 is a 20% margin but a 25% markup.
		$this->assertSame( 2000, Margin::gross_margin_bp( 1000, 800 ) );
		$this->assertSame( 2500, Margin::markup_bp( 1000, 800 ) );
	}

	public function test_markup_is_null_when_the_cost_is_zero(): void {
		// An infinite markup is not a number to show someone.
		$this->assertNull( Margin::markup_bp( 1000, 0 ) );
		$this->assertNull( Margin::markup_bp( 1000, -5 ) );
	}

	public function test_markup_is_never_below_margin(): void {
		/*
		 * Mathematically markup > margin for any profitable item, because both
		 * share the numerator (price - cost) and markup divides by the smaller
		 * denominator.
		 *
		 * At basis-point resolution they can come out EQUAL, though: at a cost
		 * of 2000 and a price of 2001 the margin is 10000/2001 = 4.997 bp and
		 * the markup is 10000/2000 = 5 bp, and both round to 5. So the
		 * invariant that actually holds after rounding is >=, and asserting >
		 * here fails on a perfectly correct engine. Materially different
		 * prices are checked separately below.
		 */
		for ( $cost = 100; $cost <= 2000; $cost += 137 ) {
			for ( $price = $cost + 1; $price <= $cost * 3; $price += 211 ) {
				$margin = Margin::gross_margin_bp( $price, $cost );
				$markup = Margin::markup_bp( $price, $cost );
				$this->assertNotNull( $margin );
				$this->assertNotNull( $markup );
				$this->assertGreaterThanOrEqual(
					$margin,
					$markup,
					"markup must not be below margin at cost {$cost}, price {$price}"
				);
			}
		}
	}

	public function test_markup_strictly_exceeds_margin_on_a_real_gap(): void {
		// Once the gap is wide enough to survive rounding, the two must differ -
		// otherwise the engine has confused the denominators.
		for ( $cost = 100; $cost <= 2000; $cost += 137 ) {
			$price  = (int) round( $cost * 1.25 );
			$margin = Margin::gross_margin_bp( $price, $cost );
			$markup = Margin::markup_bp( $price, $cost );
			$this->assertGreaterThan(
				$margin,
				$markup,
				"markup must exceed margin at cost {$cost}, price {$price}"
			);
		}
	}

	// Profit per unit.

	public function test_profit_per_unit(): void {
		$this->assertSame( 200, Margin::profit_per_unit_minor( 1000, 800 ) );
		$this->assertSame( -200, Margin::profit_per_unit_minor( 800, 1000 ) );
		$this->assertNull( Margin::profit_per_unit_minor( null, 800 ) );
		$this->assertNull( Margin::profit_per_unit_minor( 1000, null ) );
	}

	// Cost change.

	public function test_cost_change(): void {
		// EUR 0.72 -> EUR 0.81 is +12.5%
		$this->assertSame( 1250, Margin::cost_change_bp( 72, 81 ) );
		$this->assertSame( -1000, Margin::cost_change_bp( 1000, 900 ) );
	}

	public function test_cost_change_is_null_from_an_unknown_or_zero_base(): void {
		// There is no percentage change from nothing.
		$this->assertNull( Margin::cost_change_bp( 0, 500 ) );
		$this->assertNull( Margin::cost_change_bp( null, 500 ) );
		$this->assertNull( Margin::cost_change_bp( 500, null ) );
	}

	// Recommended price - THE property that must survive the port.

	public function test_recommended_price_reaches_the_target(): void {
		$this->assertSame( 3500, Margin::recommended_price_minor( 2450, 3000 ) );
		// EUR 0.81 cost at a 30% target -> EUR 1.16
		$this->assertSame( 116, Margin::recommended_price_minor( 81, 3000 ) );
	}

	/**
	 * The single most important assertion in the whole port.
	 *
	 * Rounding to nearest can produce a price whose actual margin is a hair
	 * BELOW target, which would make the recommendation self-defeating: a
	 * merchant who followed it exactly would still miss their target. The
	 * invariant is checked across the full cost x target grid, not on a
	 * handful of examples, because the failures are sparse and specific.
	 */
	public function test_recommended_price_always_achieves_at_least_the_target(): void {
		$checked = 0;
		for ( $cost = 1; $cost <= 5000; $cost += 7 ) {
			for ( $target = 0; $target < 9500; $target += 97 ) {
				$recommended = Margin::recommended_price_minor( $cost, $target );
				$this->assertNotNull( $recommended );

				$achieved = Margin::gross_margin_bp( $recommended, $cost );
				$this->assertNotNull( $achieved );
				$this->assertGreaterThanOrEqual(
					$target,
					$achieved,
					"cost {$cost} at target {$target} produced {$recommended}, achieving only {$achieved}"
				);
				++$checked;
			}
		}
		$this->assertGreaterThan( 50000, $checked, 'the grid should be substantial' );
	}

	public function test_recommended_price_rounds_up_not_to_nearest(): void {
		// A case where rounding to nearest would land below target.
		$cost        = 333;
		$target      = 3000;
		$recommended = Margin::recommended_price_minor( $cost, $target );
		$this->assertSame( 476, $recommended );
		// 476 achieves 30.04%; 475 would achieve only 29.89%.
		$this->assertGreaterThanOrEqual( $target, Margin::gross_margin_bp( 476, $cost ) );
		$this->assertLessThan( $target, Margin::gross_margin_bp( 475, $cost ) );
	}

	public function test_recommended_price_is_null_for_an_unreachable_target(): void {
		// No finite price achieves a 100% margin.
		$this->assertNull( Margin::recommended_price_minor( 1000, 10000 ) );
		$this->assertNull( Margin::recommended_price_minor( 1000, 12000 ) );
		$this->assertNull( Margin::recommended_price_minor( 1000, -100 ) );
	}

	public function test_recommended_price_is_null_for_an_unknown_cost(): void {
		$this->assertNull( Margin::recommended_price_minor( null, 3000 ) );
		$this->assertNull( Margin::recommended_price_minor( -50, 3000 ) );
	}

	public function test_a_zero_cost_product_needs_no_price_to_hit_target(): void {
		$this->assertSame( 0, Margin::recommended_price_minor( 0, 3000 ) );
	}

	// Price increase required.

	public function test_price_increase_required(): void {
		$this->assertSame( 501, Margin::price_increase_required_minor( 2999, 3500 ) );
	}

	public function test_price_increase_is_never_negative(): void {
		// "You may cut your price by X" is a recommendation this plugin does
		// not make, so an already-healthy product needs an increase of zero.
		$this->assertSame( 0, Margin::price_increase_required_minor( 5000, 3500 ) );
	}

	// Status.

	public function test_status_healthy_at_or_above_target(): void {
		$this->assertSame( Margin::STATUS_HEALTHY, Margin::status( 3000, 3000 ) );
		$this->assertSame( Margin::STATUS_HEALTHY, Margin::status( 5000, 3000 ) );
	}

	public function test_status_scales_with_the_shortfall(): void {
		// Target 30%, warning band 5pp, critical band 15pp.
		$this->assertSame( Margin::STATUS_LOW_MARGIN, Margin::status( 2800, 3000 ) );
		$this->assertSame( Margin::STATUS_LOW_MARGIN, Margin::status( 2000, 3000 ) );
		$this->assertSame( Margin::STATUS_CRITICAL_MARGIN, Margin::status( 1000, 3000 ) );
	}

	public function test_a_non_positive_margin_is_always_negative_margin(): void {
		// Selling at or below cost is not a matter of degree, so the bands do
		// not get a say.
		$this->assertSame( Margin::STATUS_NEGATIVE_MARGIN, Margin::status( 0, 3000 ) );
		$this->assertSame( Margin::STATUS_NEGATIVE_MARGIN, Margin::status( -500, 3000 ) );
		$this->assertSame( Margin::STATUS_NEGATIVE_MARGIN, Margin::status( -500, 100 ) );
	}

	public function test_status_is_unknown_when_the_margin_is_unknown(): void {
		$this->assertSame( Margin::STATUS_UNKNOWN, Margin::status( null, 3000 ) );
	}

	public function test_status_severity_orders_worst_first(): void {
		$this->assertGreaterThan(
			Margin::status_severity( Margin::STATUS_CRITICAL_MARGIN ),
			Margin::status_severity( Margin::STATUS_NEGATIVE_MARGIN )
		);
		$this->assertGreaterThan(
			Margin::status_severity( Margin::STATUS_LOW_MARGIN ),
			Margin::status_severity( Margin::STATUS_CRITICAL_MARGIN )
		);
		$this->assertGreaterThan(
			Margin::status_severity( Margin::STATUS_HEALTHY ),
			Margin::status_severity( Margin::STATUS_UNKNOWN )
		);
	}

	// Monthly impact.

	public function test_monthly_impact_needs_a_real_volume(): void {
		$this->assertSame( 15030, Margin::monthly_impact_minor( 501, 30 ) );
	}

	public function test_monthly_impact_is_null_without_volume(): void {
		// Inventing a monetary impact from nothing is the failure mode this
		// null exists to prevent.
		$this->assertNull( Margin::monthly_impact_minor( 501, null ) );
		$this->assertNull( Margin::monthly_impact_minor( 501, 0 ) );
		$this->assertNotSame( 0, Margin::monthly_impact_minor( 501, 0 ) );
	}

	// evaluate() - one product, every number.

	public function test_evaluate_produces_a_consistent_picture(): void {
		$result = Margin::evaluate( 2999, 2450, 3000, 30 );

		$this->assertSame( 1831, $result['margin_bp'] );
		$this->assertSame( 2241, $result['markup_bp'] );
		$this->assertSame( 549, $result['profit_per_unit_minor'] );
		$this->assertSame( 3500, $result['recommended_price_minor'] );
		$this->assertSame( 501, $result['price_increase_minor'] );
		$this->assertSame( Margin::STATUS_LOW_MARGIN, $result['status'] );
		$this->assertSame( 15030, $result['monthly_impact_minor'] );
	}

	public function test_evaluate_carries_nulls_all_the_way_through(): void {
		$result = Margin::evaluate( 2999, null, 3000, 30 );

		$this->assertNull( $result['margin_bp'] );
		$this->assertNull( $result['markup_bp'] );
		$this->assertNull( $result['profit_per_unit_minor'] );
		$this->assertNull( $result['recommended_price_minor'] );
		$this->assertNull( $result['monthly_impact_minor'] );
		$this->assertSame( Margin::STATUS_UNKNOWN, $result['status'] );
	}

	public function test_evaluate_is_deterministic(): void {
		$a = Margin::evaluate( 2999, 2450, 3000, 30 );
		$b = Margin::evaluate( 2999, 2450, 3000, 30 );
		$this->assertSame( $a, $b );
	}
}
