<?php
/**
 * Margin rules: normalised products in, findings out.
 *
 * A PURE function over plain arrays. No WordPress, no WooCommerce, no database,
 * no clock. That is why the entire rule set is verified in
 * tests/Unit/MarginAnalyserTest.php against literal inputs, and why two scans
 * over an unchanged catalog produce identical findings.
 *
 * NO HUMAN-READABLE COPY LIVES HERE. Findings carry a machine `type` and
 * structured numbers; the admin layer turns those into sentences with __().
 * Putting translated strings in this class would both break the purity that
 * makes it testable and produce untranslatable output baked into the database.
 *
 * EVERY NUMBER COMES FROM Core\Margin. Nothing here does arithmetic on a price;
 * it decides which rule applies and how serious it is.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Analysis;

use ProfitGuard\Core\Finding;
use ProfitGuard\Core\Margin;

defined( 'ABSPATH' ) || exit;

/**
 * Turns products into margin findings.
 */
final class MarginAnalyser {

	/**
	 * A sale price that cuts the margin below this is a SALE_PRICE_MARGIN_RISK.
	 *
	 * Expressed as a share of the target rather than an absolute margin, so it
	 * scales with whatever the merchant is aiming for.
	 */
	public const SALE_RISK_SHARE_BP = 5000;

	/**
	 * A cost rise of at least this much is worth reporting.
	 *
	 * 5% filters out the rounding noise of a re-import while catching anything
	 * a supplier actually did.
	 */
	public const COST_INCREASE_BP = 500;

	/**
	 * Analyse one product.
	 *
	 * At most ONE margin-status finding per product: a product selling below
	 * cost is not also reported as low margin, because it is the same problem
	 * said twice and would double-count in any total.
	 *
	 * @param array<string, mixed> $product Normalised product from Woo\Catalog.
	 * @param array<string, mixed> $options {
	 *     Scan options.
	 *     @type int      $target_margin_bp Target margin.
	 *     @type int|null $warning_band_bp  Warning band.
	 *     @type int|null $critical_band_bp Critical band.
	 * }
	 * @return Finding[]
	 */
	public static function analyse( array $product, array $options ): array {
		$target   = (int) ( $options['target_margin_bp'] ?? 3000 );
		$warning  = isset( $options['warning_band_bp'] ) ? (int) $options['warning_band_bp'] : null;
		$critical = isset( $options['critical_band_bp'] ) ? (int) $options['critical_band_bp'] : null;

		$id    = (int) ( $product['id'] ?? 0 );
		$name  = (string) ( $product['name'] ?? '' );
		$sku   = (string) ( $product['sku'] ?? '' );
		$price = self::nullable_int( $product['price_minor'] ?? null );
		$cost  = self::nullable_int( $product['cost_minor'] ?? null );

		$subject_kind = ( 'variation' === ( $product['type'] ?? '' ) )
			? Finding::SUBJECT_VARIATION
			: Finding::SUBJECT_PRODUCT;

		$base = array(
			'module'        => Finding::MODULE_MARGIN,
			'subject_kind'  => $subject_kind,
			'subject_id'    => $id,
			'subject_label' => $name,
			'reference'     => '' !== $sku ? $sku : (string) $id,
		);

		// A product with no price is a draft or an unconfigured option, not a
		// margin problem. Nothing is claimed about it.
		if ( null === $price ) {
			return array();
		}

		$findings = array();

		/* --- no cost ------------------------------------------------- */
		if ( null === $cost ) {
			/*
			 * Emitted PER PRODUCT so the findings table can filter and export
			 * it, which the platform requires. The dashboard summarises these
			 * to a single count rather than listing them, so 121 identical rows
			 * cannot bury four negative-margin products.
			 */
			$findings[] = new Finding(
				array_merge(
					$base,
					array(
						'type'           => Finding::TYPE_MISSING_COST,
						'severity'       => Finding::SEVERITY_LOW,
						'financial_type' => Finding::FINANCIAL_MISSING_DATA,
						// Null because it is UNKNOWABLE, not because it is
						// small. This product could be the most profitable in
						// the store or the least; that is precisely the point.
						'impact_minor'   => null,
						'confidence'     => 100,
						'current_minor'  => $price,
						'expected_minor' => null,
						'evidence'       => array(
							'price_minor' => $price,
						),
					)
				)
			);
			return $findings;
		}//end if

		$outcome = Margin::evaluate( $price, $cost, $target, null, $warning, $critical );
		$margin  = $outcome['margin_bp'];

		/* --- margin status ------------------------------------------- */
		$status_type = null;
		$severity    = Finding::SEVERITY_INFO;

		switch ( $outcome['status'] ) {
			case Margin::STATUS_NEGATIVE_MARGIN:
				$status_type = Finding::TYPE_NEGATIVE_MARGIN;
				$severity    = Finding::SEVERITY_CRITICAL;
				break;
			case Margin::STATUS_CRITICAL_MARGIN:
				$status_type = Finding::TYPE_CRITICAL_MARGIN;
				$severity    = Finding::SEVERITY_HIGH;
				break;
			case Margin::STATUS_LOW_MARGIN:
				$status_type = Finding::TYPE_LOW_MARGIN;
				$severity    = Finding::SEVERITY_MEDIUM;
				break;
			default:
				$status_type = null;
		}

		if ( null !== $status_type ) {
			$is_negative = Finding::TYPE_NEGATIVE_MARGIN === $status_type;

			/*
			 * THE AMOUNT.
			 *
			 * For a negative margin it is the loss per unit sold - arithmetic
			 * on two figures the store already holds, so CONFIRMED_CALCULATION.
			 *
			 * For a shortfall it is the per-unit increase needed to reach
			 * target. That is also exact arithmetic, but it is a per-unit
			 * figure and NOT a claim about monthly money: ProfitGuard does not
			 * read sales volume in V1, so no monthly projection is offered at
			 * all rather than one built on an assumed volume.
			 */
			$amount = $is_negative
				? $outcome['profit_per_unit_minor']
				: $outcome['price_increase_minor'];

			$findings[] = new Finding(
				array_merge(
					$base,
					array(
						'type'           => $status_type,
						'severity'       => $severity,
						'financial_type' => Finding::FINANCIAL_CONFIRMED_CALCULATION,
						'impact_minor'   => $amount,
						'confidence'     => 100,
						'current_minor'  => $price,
						'expected_minor' => $outcome['recommended_price_minor'],
						'evidence'       => array(
							'price_minor'             => $price,
							'cost_minor'              => $cost,
							'margin_bp'               => $margin,
							'target_margin_bp'        => $target,
							'markup_bp'               => $outcome['markup_bp'],
							'profit_per_unit_minor'   => $outcome['profit_per_unit_minor'],
							'recommended_price_minor' => $outcome['recommended_price_minor'],
						),
					)
				)
			);
		}//end if

		/* --- sale price risk ----------------------------------------- */
		$regular = self::nullable_int( $product['regular_price_minor'] ?? null );
		$on_sale = ! empty( $product['is_on_sale'] );

		if ( $on_sale && null !== $regular && $regular > $price ) {
			$regular_margin = Margin::gross_margin_bp( $regular, $cost );
			$risk_threshold = (int) round( $target * self::SALE_RISK_SHARE_BP / Margin::BP_100 );

			/*
			 * Only worth saying when the SALE is what broke the margin. A
			 * product whose regular price also misses target has a pricing
			 * problem, not a discounting problem, and it is already reported
			 * above - saying it twice sends the merchant to the wrong fix.
			 */
			if ( null !== $margin && null !== $regular_margin
				&& $margin < $risk_threshold
				&& $regular_margin >= $target ) {

				$findings[] = new Finding(
					array_merge(
						$base,
						array(
							'type'           => Finding::TYPE_SALE_PRICE_MARGIN_RISK,
							'severity'       => Finding::SEVERITY_MEDIUM,
							'financial_type' => Finding::FINANCIAL_CONFIRMED_CALCULATION,
							// Margin given up per unit sold at the sale price.
							'impact_minor'   => $regular - $price,
							'confidence'     => 100,
							'current_minor'  => $price,
							'expected_minor' => $regular,
							'evidence'       => array(
								'sale_price_minor'    => $price,
								'regular_price_minor' => $regular,
								'cost_minor'          => $cost,
								'sale_margin_bp'      => $margin,
								'regular_margin_bp'   => $regular_margin,
								'target_margin_bp'    => $target,
							),
						)
					)
				);
			}//end if
		}//end if

		/* --- cost increase -------------------------------------------- */
		$previous = self::nullable_int( $product['previous_cost_minor'] ?? null );
		if ( null !== $previous && $previous > 0 && $cost > $previous ) {
			$change = Margin::cost_change_bp( $previous, $cost );
			if ( null !== $change && $change >= self::COST_INCREASE_BP ) {
				$findings[] = new Finding(
					array_merge(
						$base,
						array(
							'type'           => Finding::TYPE_COST_INCREASE,
							// A cost rise that also broke the margin is already
							// reported above with the higher severity; this row
							// explains WHY, so it stays informational.
							'severity'       => Finding::SEVERITY_MEDIUM,
							'financial_type' => Finding::FINANCIAL_EVIDENCED_DIFFERENCE,
							'impact_minor'   => $cost - $previous,
							'confidence'     => 100,
							'current_minor'  => $cost,
							'expected_minor' => $previous,
							'evidence'       => array(
								'previous_cost_minor' => $previous,
								'new_cost_minor'      => $cost,
								'cost_change_bp'      => $change,
								'margin_bp'           => $margin,
							),
						)
					)
				);
			}//end if
		}//end if

		return $findings;
	}

	/**
	 * Running totals across a scan, for the score and the dashboard.
	 *
	 * Counts PRODUCTS, not findings: a product that produced both a low-margin
	 * and a cost-increase finding is one unhealthy product, and telling the
	 * score otherwise would double-count it.
	 *
	 * @param array<string, mixed> $product Normalised product.
	 * @param array<string, int>   $totals  Running totals.
	 * @param int                  $target  Target margin.
	 * @param int|null             $warning Warning band.
	 * @param int|null             $critical Critical band.
	 * @return array<string, int> Updated totals.
	 */
	public static function tally( array $product, array $totals, int $target, ?int $warning = null, ?int $critical = null ): array {
		$defaults = array(
			'products_seen'   => 0,
			'products_priced' => 0,
			'assessed'        => 0,
			'healthy'         => 0,
			'low'             => 0,
			'critical'        => 0,
			'negative'        => 0,
			'missing_cost'    => 0,
			'missing_price'   => 0,
		);
		$totals   = array_merge( $defaults, $totals );

		++$totals['products_seen'];

		$price = self::nullable_int( $product['price_minor'] ?? null );
		$cost  = self::nullable_int( $product['cost_minor'] ?? null );

		if ( null === $price ) {
			++$totals['missing_price'];
			return $totals;
		}
		++$totals['products_priced'];

		if ( null === $cost ) {
			++$totals['missing_cost'];
			return $totals;
		}

		++$totals['assessed'];

		$status = Margin::status( Margin::gross_margin_bp( $price, $cost ), $target, $warning, $critical );
		switch ( $status ) {
			case Margin::STATUS_NEGATIVE_MARGIN:
				++$totals['negative'];
				break;
			case Margin::STATUS_CRITICAL_MARGIN:
				++$totals['critical'];
				break;
			case Margin::STATUS_LOW_MARGIN:
				++$totals['low'];
				break;
			default:
				++$totals['healthy'];
		}

		return $totals;
	}

	/**
	 * Cast to int, preserving null.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function nullable_int( $value ): ?int {
		return ( null === $value || '' === $value ) ? null : (int) $value;
	}
}
