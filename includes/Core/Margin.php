<?php
/**
 * Margin arithmetic. This class is the entire financial claim ProfitGuard makes.
 *
 * Ported from the ProfitGuard TypeScript core (lib/margin/calc.ts) formula for
 * formula, including the null semantics and the rounding direction.
 * tests/Unit/MarginTest.php mirrors the original property tests.
 *
 * NON-NEGOTIABLE RULES
 *  1. No AI, ever. Every number a merchant might act on is produced here, from
 *     integers, by code covered by tests.
 *  2. Money is an INTEGER count of minor units. Percentages are INTEGER basis
 *     points (1 bp = 0.01%; 30% === 3000).
 *  3. Unknown is `null`, never 0. A product with no cost has no margin; it does
 *     not have a margin of zero.
 *  4. MARGIN IS NOT MARKUP. Margin divides by the SELLING price, markup divides
 *     by the COST. Confusing them is the single most common pricing error in
 *     small retail, so they are separate methods with separate tests and are
 *     never derived from one another by an approximation.
 *  5. The recommended price ROUNDS UP. See recommended_price_minor().
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic margin maths. Pure PHP: no WordPress, no WooCommerce.
 */
final class Margin {

	/**
	 * 100% expressed in basis points.
	 */
	public const BP_100 = Money::BP_100;

	// Status values.

	public const STATUS_HEALTHY         = 'HEALTHY';
	public const STATUS_LOW_MARGIN      = 'LOW_MARGIN';
	public const STATUS_CRITICAL_MARGIN = 'CRITICAL_MARGIN';
	public const STATUS_NEGATIVE_MARGIN = 'NEGATIVE_MARGIN';
	public const STATUS_UNKNOWN         = 'UNKNOWN';

	/**
	 * Default band widths, in basis points.
	 *
	 * Below target by less than the warning band is LOW_MARGIN; below it by
	 * more than the critical band is CRITICAL_MARGIN. A margin at or below zero
	 * is always NEGATIVE_MARGIN regardless of the bands.
	 */
	public const DEFAULT_WARNING_BAND_BP  = 500;
	public const DEFAULT_CRITICAL_BAND_BP = 1500;

	// Gross margin.

	/**
	 * Gross margin in basis points: (selling - cost) / selling.
	 *
	 * Returns null when there is no selling price to divide by. A NEGATIVE
	 * result is legitimate and is preserved: selling below cost is precisely
	 * the situation this plugin exists to surface.
	 *
	 * @param int|null $selling_price_minor Selling price in minor units.
	 * @param int|null $cost_minor          Cost in minor units.
	 * @return int|null Basis points, or null when it cannot be computed.
	 */
	public static function gross_margin_bp( ?int $selling_price_minor, ?int $cost_minor ): ?int {
		if ( null === $selling_price_minor || null === $cost_minor ) {
			return null;
		}
		if ( 0 === $selling_price_minor ) {
			return null;
		}
		// A negative selling price is not a price.
		if ( $selling_price_minor < 0 ) {
			return null;
		}
		return Money::mul_div_round( $selling_price_minor - $cost_minor, self::BP_100, $selling_price_minor );
	}

	/**
	 * Markup in basis points: (selling - cost) / cost.
	 *
	 * Returns null when cost is zero - an infinite markup is not a number to
	 * show someone. Deliberately NOT derived from the margin.
	 *
	 * @param int|null $selling_price_minor Selling price in minor units.
	 * @param int|null $cost_minor          Cost in minor units.
	 * @return int|null Basis points, or null.
	 */
	public static function markup_bp( ?int $selling_price_minor, ?int $cost_minor ): ?int {
		if ( null === $selling_price_minor || null === $cost_minor ) {
			return null;
		}
		if ( 0 === $cost_minor || $cost_minor < 0 ) {
			return null;
		}
		return Money::mul_div_round( $selling_price_minor - $cost_minor, self::BP_100, $cost_minor );
	}

	/**
	 * Profit per unit in minor units. Null when either side is unknown.
	 *
	 * @param int|null $selling_price_minor Selling price in minor units.
	 * @param int|null $cost_minor          Cost in minor units.
	 * @return int|null Profit in minor units, or null.
	 */
	public static function profit_per_unit_minor( ?int $selling_price_minor, ?int $cost_minor ): ?int {
		if ( null === $selling_price_minor || null === $cost_minor ) {
			return null;
		}
		return $selling_price_minor - $cost_minor;
	}

	// Cost change.

	/**
	 * Supplier cost change in basis points: (new - old) / old.
	 *
	 * Returns null when the old cost is zero or unknown - there is no
	 * percentage change from nothing, and showing "+infinity%" or "+0%" would
	 * both be lies.
	 *
	 * @param int|null $old_cost_minor Previous cost in minor units.
	 * @param int|null $new_cost_minor New cost in minor units.
	 * @return int|null Basis points, or null.
	 */
	public static function cost_change_bp( ?int $old_cost_minor, ?int $new_cost_minor ): ?int {
		if ( null === $old_cost_minor || null === $new_cost_minor ) {
			return null;
		}
		if ( 0 === $old_cost_minor || $old_cost_minor < 0 ) {
			return null;
		}
		return Money::mul_div_round( $new_cost_minor - $old_cost_minor, self::BP_100, $old_cost_minor );
	}

	// Recommended selling price.

	/**
	 * The selling price that achieves `$target_margin_bp` at `$cost_minor`.
	 *
	 * The formula is `price = cost / (1 - target_margin)`.
	 *
	 * Rounds UP, always. Rounding to nearest can produce a price whose actual
	 * margin is a hair BELOW the target, which would make the whole
	 * recommendation self-defeating: a merchant who followed it would still
	 * miss their target. The invariant
	 *
	 *     gross_margin_bp( recommended, cost ) >= target_margin_bp
	 *
	 * is asserted as a property test over the full cost x target grid in
	 * tests/Unit/MarginTest.php, and is the single most important assertion in
	 * the port.
	 *
	 * Returns null for a target of 100% or more (no finite price reaches it),
	 * for a negative target, and for an unknown or negative cost.
	 *
	 * @param int|null $cost_minor       Cost in minor units.
	 * @param int      $target_margin_bp Target margin in basis points.
	 * @return int|null Recommended price in minor units, or null.
	 */
	public static function recommended_price_minor( ?int $cost_minor, int $target_margin_bp ): ?int {
		if ( null === $cost_minor || $cost_minor < 0 ) {
			return null;
		}
		if ( $target_margin_bp < 0 || $target_margin_bp >= self::BP_100 ) {
			return null;
		}
		if ( 0 === $cost_minor ) {
			return 0;
		}
		return Money::mul_div_ceil( $cost_minor, self::BP_100, self::BP_100 - $target_margin_bp );
	}

	/**
	 * How much the selling price must rise to reach the target.
	 *
	 * Zero when the current price already achieves it - never negative, because
	 * "you may cut your price by X" is a different recommendation this plugin
	 * does not make.
	 *
	 * @param int|null $current_selling_minor Current selling price.
	 * @param int|null $recommended_minor     Recommended selling price.
	 * @return int|null Increase required in minor units, or null.
	 */
	public static function price_increase_required_minor( ?int $current_selling_minor, ?int $recommended_minor ): ?int {
		if ( null === $current_selling_minor || null === $recommended_minor ) {
			return null;
		}
		return max( 0, $recommended_minor - $current_selling_minor );
	}

	// Status.

	/**
	 * Classify a margin against a target.
	 *
	 * A non-positive margin is ALWAYS negative-margin regardless of the bands:
	 * selling at or below cost is not a matter of degree.
	 *
	 * @param int|null $margin_bp        Margin in basis points, or null.
	 * @param int      $target_margin_bp Target margin in basis points.
	 * @param int|null $warning_band_bp  Warning band width, or null for default.
	 * @param int|null $critical_band_bp Critical band width, or null for default.
	 * @return string One of the STATUS_* constants.
	 */
	public static function status(
		?int $margin_bp,
		int $target_margin_bp,
		?int $warning_band_bp = null,
		?int $critical_band_bp = null
	): string {
		if ( null === $margin_bp ) {
			return self::STATUS_UNKNOWN;
		}
		if ( $margin_bp <= 0 ) {
			return self::STATUS_NEGATIVE_MARGIN;
		}
		if ( $margin_bp >= $target_margin_bp ) {
			return self::STATUS_HEALTHY;
		}

		$warning  = $warning_band_bp ?? self::DEFAULT_WARNING_BAND_BP;
		$critical = $critical_band_bp ?? self::DEFAULT_CRITICAL_BAND_BP;

		$shortfall = $target_margin_bp - $margin_bp;
		if ( $shortfall <= $warning ) {
			return self::STATUS_LOW_MARGIN;
		}
		if ( $shortfall <= $critical ) {
			return self::STATUS_LOW_MARGIN;
		}
		return self::STATUS_CRITICAL_MARGIN;
	}

	/**
	 * Ordering for "show me the worst first". Higher is worse.
	 *
	 * @param string $status A STATUS_* constant.
	 * @return int Severity rank.
	 */
	public static function status_severity( string $status ): int {
		switch ( $status ) {
			case self::STATUS_NEGATIVE_MARGIN:
				return 4;
			case self::STATUS_CRITICAL_MARGIN:
				return 3;
			case self::STATUS_LOW_MARGIN:
				return 2;
			case self::STATUS_UNKNOWN:
				return 1;
			default:
				return 0;
		}
	}

	// Volume-weighted impact.

	/**
	 * Monthly monetary impact of a margin gap, computable ONLY when a real
	 * sales volume is supplied.
	 *
	 * Returns null when volume is unknown or zero. This is deliberate and
	 * load-bearing: inventing a monetary impact from nothing is exactly the
	 * kind of number that destroys trust the first time a merchant checks it
	 * against their own books.
	 *
	 * @param int|null $per_unit_minor Amount per unit in minor units.
	 * @param int|null $monthly_units  Units sold per month.
	 * @return int|null Monthly impact in minor units, or null.
	 */
	public static function monthly_impact_minor( ?int $per_unit_minor, ?int $monthly_units ): ?int {
		if ( null === $per_unit_minor || null === $monthly_units ) {
			return null;
		}
		if ( $monthly_units <= 0 ) {
			return null;
		}
		return Money::mul_div_round( $per_unit_minor, $monthly_units, 1 );
	}

	// The whole picture for one product.

	/**
	 * Compute every derived number for one product in one place, so a screen,
	 * an export and a findings row can never disagree about the same product.
	 *
	 * @param int|null $selling_price_minor Effective selling price.
	 * @param int|null $cost_minor          Cost.
	 * @param int      $target_margin_bp    Target margin in basis points.
	 * @param int|null $monthly_units       Units sold per month, or null.
	 * @param int|null $warning_band_bp     Warning band, or null for default.
	 * @param int|null $critical_band_bp    Critical band, or null for default.
	 * @return array{
	 *     margin_bp:int|null,
	 *     markup_bp:int|null,
	 *     profit_per_unit_minor:int|null,
	 *     recommended_price_minor:int|null,
	 *     price_increase_minor:int|null,
	 *     status:string,
	 *     monthly_impact_minor:int|null
	 * }
	 */
	public static function evaluate(
		?int $selling_price_minor,
		?int $cost_minor,
		int $target_margin_bp,
		?int $monthly_units = null,
		?int $warning_band_bp = null,
		?int $critical_band_bp = null
	): array {
		$margin_bp   = self::gross_margin_bp( $selling_price_minor, $cost_minor );
		$recommended = self::recommended_price_minor( $cost_minor, $target_margin_bp );
		$increase    = self::price_increase_required_minor( $selling_price_minor, $recommended );

		return array(
			'margin_bp'               => $margin_bp,
			'markup_bp'               => self::markup_bp( $selling_price_minor, $cost_minor ),
			'profit_per_unit_minor'   => self::profit_per_unit_minor( $selling_price_minor, $cost_minor ),
			'recommended_price_minor' => $recommended,
			'price_increase_minor'    => $increase,
			'status'                  => self::status( $margin_bp, $target_margin_bp, $warning_band_bp, $critical_band_bp ),
			'monthly_impact_minor'    => self::monthly_impact_minor( $increase, $monthly_units ),
		);
	}
}
