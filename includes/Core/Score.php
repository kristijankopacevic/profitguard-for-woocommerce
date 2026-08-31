<?php
/**
 * The ProfitGuard Score, and coverage.
 *
 * Ported from the ProfitGuard TypeScript core (lib/findings/score.ts) and
 * extended with the coverage reporting this platform asks for. The algorithm
 * is specified in full on the class below.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic scoring. Pure PHP: no WordPress, no WooCommerce.
 *
 * THE ALGORITHM. This comment is the specification; the code implements it.
 *
 * Two categories are scored independently, each 0-100, each from a ratio of
 * two real quantities:
 *
 *   MARGIN HEALTH        weight 60
 *     Over products where BOTH a cost and a selling price are known:
 *       demerit = (3*negative + 2*critical + 1*low) / (3 * assessed)
 *       score   = 100 - round(100 * demerit)
 *     A product at or above target contributes 0 demerit; one selling at or
 *     below cost contributes the full 3.
 *
 *   SHIPPING EFFICIENCY  weight 40
 *     Over orders where BOTH the shipping charged and an actual carrier cost
 *     are known:
 *       score = clamp(round(100 * shippingCharged / carrierCost), 0, 100)
 *     Read it as "what share of your carrier bill your customers cover".
 *     Recovering all of it scores 100; recovering half scores 50. Charging
 *     MORE than cost does not score above 100 - shipping is not a profit
 *     centre this tool rewards inflating.
 *
 * COMBINING. The overall score is the weighted mean over the categories that
 * are ASSESSABLE, with the weights renormalised across just those. A category
 * with no data is not scored 0 and not scored 100 - it is left out. When
 * nothing at all is assessable the score is NULL, never 0.
 *
 * WHY THAT MATTERS MORE HERE THAN ANYWHERE ELSE. A merchant who has just
 * installed the plugin has no cost data and no carrier invoices. Scoring those
 * categories zero would greet them with "ProfitGuard Score: 0" that says
 * nothing about their store and everything about our missing data - and it
 * would be the last thing they saw before deactivating.
 *
 * COVERAGE IS REPORTED SEPARATELY, and is the honest answer to "how much of my
 * store did you actually look at". A score of 92 over 12% of the catalog is a
 * different statement from a score of 92 over all of it, and the merchant is
 * shown both numbers rather than one number pretending to be both.
 */
final class Score {

	public const CATEGORY_MARGIN   = 'MARGIN';
	public const CATEGORY_SHIPPING = 'SHIPPING';

	/**
	 * Relative importance, renormalised over whichever categories have data.
	 *
	 * Margin outweighs shipping because it applies to every product a store
	 * sells, while shipping applies only to orders that shipped and only where
	 * the merchant has supplied a carrier invoice.
	 */
	public const WEIGHT_MARGIN   = 60;
	public const WEIGHT_SHIPPING = 40;

	/**
	 * Exact round-half-away-from-zero of (a * 100) / b, for non-negative b.
	 *
	 * @param int $a Numerator.
	 * @param int $b Denominator.
	 * @return int Percentage.
	 */
	private static function pct( int $a, int $b ): int {
		if ( $b <= 0 ) {
			return 0;
		}
		return Money::mul_div_round( $a, 100, $b );
	}

	/**
	 * Clamp to 0..100.
	 *
	 * @param int $n Value.
	 * @return int Clamped value.
	 */
	private static function clamp100( int $n ): int {
		return max( 0, min( 100, $n ) );
	}

	/**
	 * Margin health, 0-100, or null when nothing could be assessed.
	 *
	 * @param int $assessed Products with both a cost and a price.
	 * @param int $negative Products selling at or below cost.
	 * @param int $critical Products far below target.
	 * @param int $low      Products modestly below target.
	 * @return int|null
	 */
	public static function margin_score( int $assessed, int $negative, int $critical, int $low ): ?int {
		if ( $assessed <= 0 ) {
			return null;
		}
		$demerit_points = ( 3 * $negative ) + ( 2 * $critical ) + $low;
		$max_points     = 3 * $assessed;
		return self::clamp100( 100 - self::pct( $demerit_points, $max_points ) );
	}

	/**
	 * Shipping efficiency, 0-100, or null when nothing could be assessed.
	 *
	 * @param int $assessed_orders      Orders with both figures known.
	 * @param int $shipping_charged_minor Total charged to customers.
	 * @param int $carrier_cost_minor     Total billed by carriers.
	 * @return int|null
	 */
	public static function shipping_score( int $assessed_orders, int $shipping_charged_minor, int $carrier_cost_minor ): ?int {
		if ( $assessed_orders <= 0 ) {
			return null;
		}
		// No carrier cost to recover means there is nothing to be efficient
		// about, which is not the same as being perfectly efficient.
		if ( $carrier_cost_minor <= 0 ) {
			return null;
		}
		return self::clamp100( self::pct( max( 0, $shipping_charged_minor ), $carrier_cost_minor ) );
	}

	/**
	 * Coverage as a percentage: how much of the population we could assess.
	 *
	 * Returns null - not 0% - when the population itself is empty. "You have no
	 * products" and "we could assess none of your products" are different
	 * statements and a merchant with an empty store should see the first.
	 *
	 * @param int $assessed Items we could assess.
	 * @param int $total    Items in the population.
	 * @return int|null Percentage 0-100, or null.
	 */
	public static function coverage_percent( int $assessed, int $total ): ?int {
		if ( $total <= 0 ) {
			return null;
		}
		return self::clamp100( self::pct( max( 0, $assessed ), $total ) );
	}

	/**
	 * The overall score and its parts.
	 *
	 * @param int|null $margin_score        Margin category score, or null.
	 * @param int|null $shipping_score      Shipping category score, or null.
	 * @param int|null $margin_coverage     Margin coverage percent, or null.
	 * @param int|null $shipping_coverage   Shipping coverage percent, or null.
	 * @return array{
	 *     score:int|null,
	 *     assessed_categories:int,
	 *     categories:array<int, array{category:string,score:int|null,weight:int,coverage_percent:int|null,unavailable_reason:string|null}>
	 * }
	 */
	public static function overall(
		?int $margin_score,
		?int $shipping_score,
		?int $margin_coverage = null,
		?int $shipping_coverage = null
	): array {
		$categories = array(
			array(
				'category'           => self::CATEGORY_MARGIN,
				'score'              => $margin_score,
				'weight'             => null === $margin_score ? 0 : self::WEIGHT_MARGIN,
				'coverage_percent'   => $margin_coverage,
				'unavailable_reason' => null === $margin_score
					? 'No products yet have both a cost and a selling price.'
					: null,
			),
			array(
				'category'           => self::CATEGORY_SHIPPING,
				'score'              => $shipping_score,
				'weight'             => null === $shipping_score ? 0 : self::WEIGHT_SHIPPING,
				'coverage_percent'   => $shipping_coverage,
				'unavailable_reason' => null === $shipping_score
					? 'No orders yet have both a shipping charge and an imported carrier cost.'
					: null,
			),
		);

		$total_weight = 0;
		$weighted     = 0;
		$assessed     = 0;
		foreach ( $categories as $category ) {
			if ( null === $category['score'] ) {
				continue;
			}
			$total_weight += $category['weight'];
			$weighted     += $category['score'] * $category['weight'];
			++$assessed;
		}

		if ( 0 === $assessed || 0 === $total_weight ) {
			// Null, never 0. A store we know nothing about does not have a bad
			// score.
			return array(
				'score'               => null,
				'assessed_categories' => 0,
				'categories'          => $categories,
			);
		}

		return array(
			'score'               => self::clamp100( Money::mul_div_round( $weighted, 1, $total_weight ) ),
			'assessed_categories' => $assessed,
			'categories'          => $categories,
		);
	}

	/**
	 * Merchant-facing band for a score.
	 *
	 * @param int|null $score Score, or null.
	 * @return string|null Band, or null when unscored.
	 */
	public static function band( ?int $score ): ?string {
		if ( null === $score ) {
			return null;
		}
		if ( $score >= 85 ) {
			return 'STRONG';
		}
		if ( $score >= 70 ) {
			return 'GOOD';
		}
		if ( $score >= 55 ) {
			return 'FAIR';
		}
		if ( $score >= 35 ) {
			return 'NEEDS_ATTENTION';
		}
		return 'AT_RISK';
	}
}
