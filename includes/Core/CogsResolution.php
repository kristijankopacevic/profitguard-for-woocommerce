<?php
/**
 * How a variation's cost is resolved against its parent's.
 *
 * WooCommerce applies three rules when it builds an ORDER ITEM - inherit the
 * parent default, add parent and variation together, or let the variation
 * replace the parent - and applies none of them in the product getters.
 * `WC_Product_Variation::get_cogs_value()` returns the variation's own value or
 * null, and `get_cogs_effective_value()` returns own-or-0.0.
 *
 * ProfitGuard reports margins at PRODUCT level, so it has to reproduce core's
 * order-item rule itself. It has to reproduce it exactly: if it does not, a
 * product-level margin and WooCommerce's own analytics disagree on every
 * variable product, and a merchant reconciling the two has no way to tell which
 * is wrong.
 *
 * Measured against WooCommerce 11.1.0, parent 10.00, quantity 2
 * (tests/cogs/MEASURED-FACTS.md):
 *
 *   own    additive   core's order item   rule
 *   ----   --------   -----------------   --------------------------
 *   null   false      20.00 = 10 x 2      parent default inherited
 *   4.00   true       28.00 = 14 x 2      parent + own
 *   4.00   false       8.00 =  4 x 2      own replaces parent
 *
 * Pure: no WordPress, no WooCommerce, no globals, so the table above is
 * unit-tested without standing up a store.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a variation cost against its parent's, the way core does.
 */
final class CogsResolution {

	/**
	 * The variation carried its own cost, and that cost stands alone.
	 */
	public const BASIS_OWN = 'own';

	/**
	 * The variation had no cost, so the parent's applies to it.
	 */
	public const BASIS_INHERITED = 'inherited';

	/**
	 * The variation is additive: its cost is the parent's plus its own.
	 */
	public const BASIS_COMBINED = 'combined';

	/**
	 * Neither the variation nor its parent has a cost. Genuinely unknown.
	 */
	public const BASIS_UNKNOWN = 'unknown';

	/**
	 * Resolve a cost, reporting how it was arrived at.
	 *
	 * The basis is returned alongside the amount because "4.00, its own" and
	 * "10.00, inherited from the parent" are different facts to show a
	 * merchant, and presenting an inherited cost as the variation's own is how
	 * someone comes to edit the wrong field.
	 *
	 * @param int|null $own_minor      The variation's own cost in minor units, or null.
	 * @param int|null $parent_minor   The parent's cost in minor units, or null.
	 * @param bool     $is_additive    Whether the variation's cost adds to the parent's.
	 * @return array{cost_minor:int|null,basis:string}
	 */
	public static function resolve( ?int $own_minor, ?int $parent_minor, bool $is_additive ): array {
		// No cost of its own: the parent's default applies, exactly as core
		// does when it builds an order item. The parent may itself have none,
		// in which case the honest answer stays "unknown" rather than zero.
		if ( null === $own_minor ) {
			return null === $parent_minor
				? self::unknown()
				: array(
					'cost_minor' => $parent_minor,
					'basis'      => self::BASIS_INHERITED,
				);
		}

		// Additive only means anything when there is a parent value to add to.
		// An additive variation under a parent with no cost is just its own
		// cost; treating the absent parent as 0 would be the same fabrication
		// as trusting get_cogs_effective_value().
		if ( $is_additive && null !== $parent_minor ) {
			return array(
				'cost_minor' => $parent_minor + $own_minor,
				'basis'      => self::BASIS_COMBINED,
			);
		}

		return array(
			'cost_minor' => $own_minor,
			'basis'      => self::BASIS_OWN,
		);
	}

	/**
	 * The "no cost anywhere" answer.
	 *
	 * @return array{cost_minor:int|null,basis:string}
	 */
	private static function unknown(): array {
		return array(
			'cost_minor' => null,
			'basis'      => self::BASIS_UNKNOWN,
		);
	}
}
