<?php
/**
 * Shipping profitability arithmetic.
 *
 * Ported from the ProfitGuard TypeScript core (lib/shipping/calc.ts), trimmed
 * to what a WooCommerce store can actually evidence.
 *
 * THE QUESTION IS NARROW AND ENTIRELY MECHANICAL: for one order, did the
 * shipping the customer paid cover what the carrier actually charged?
 *
 * WHERE THE NUMBERS COME FROM, AND WHERE THEY DO NOT
 *
 * WooCommerce knows what the customer was charged for shipping. It does NOT
 * know what the carrier eventually billed the merchant - that arrives weeks
 * later on an invoice, with fuel surcharges, residential fees, weight
 * corrections and address-correction charges that did not exist when the order
 * was placed. Any tool claiming to audit shipping profitability from
 * WooCommerce data alone is guessing.
 *
 * So `carrier_cost_minor` is nullable, it is populated only from a file the
 * merchant imported, and when it is null this class reports MISSING_CARRIER_COST
 * rather than estimating. That is the difference between a finding a merchant
 * can act on and a number they will stop trusting.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic shipping maths. Pure PHP: no WordPress, no WooCommerce.
 */
final class Shipping {

	public const RESULT_PROFIT       = 'SHIPPING_PROFIT';
	public const RESULT_BREAK_EVEN   = 'SHIPPING_BREAK_EVEN';
	public const RESULT_LOSS         = 'SHIPPING_LOSS';
	public const RESULT_HIGH_LOSS    = 'HIGH_SHIPPING_LOSS';
	public const RESULT_MISSING_COST = 'MISSING_CARRIER_COST';

	/**
	 * A loss at or above this share of the ORDER value is a high loss.
	 *
	 * Judged as a share rather than an absolute figure because losing EUR 6 on
	 * a EUR 30 order eats a fifth of it, while losing EUR 6 on a EUR 900 order
	 * is a rounding error. An absolute threshold ranks those the same way and
	 * sends the merchant after the wrong one.
	 */
	public const HIGH_LOSS_SHARE_BP = 2000;

	/**
	 * Absolute fallback when the order value is unknown, in minor units.
	 */
	public const HIGH_LOSS_ABSOLUTE_MINOR = 2000;

	/**
	 * Evaluate one order's shipping.
	 *
	 * @param int|null $shipping_charged_minor What the customer paid. Null when
	 *                                         the order had no shipping line at
	 *                                         all, which is not the same as a
	 *                                         zero charge.
	 * @param int|null $carrier_cost_minor     What the carrier billed. Null
	 *                                         until the merchant imports it.
	 * @param int|null $order_total_minor      Order value, for proportion.
	 * @return array{
	 *     result:string,
	 *     profit_minor:int|null,
	 *     loss_minor:int|null,
	 *     recovery_bp:int|null,
	 *     loss_of_order_bp:int|null
	 * }
	 */
	public static function evaluate(
		?int $shipping_charged_minor,
		?int $carrier_cost_minor,
		?int $order_total_minor = null
	): array {
		if ( null === $carrier_cost_minor ) {
			// The honest answer for a store that has not imported a carrier
			// invoice. Every figure stays null; nothing is estimated.
			return array(
				'result'           => self::RESULT_MISSING_COST,
				'profit_minor'     => null,
				'loss_minor'       => null,
				'recovery_bp'      => null,
				'loss_of_order_bp' => null,
			);
		}

		// A missing shipping line means the order did not SELL shipping.
		// Combined with a known carrier cost that is a merchant who shipped
		// something and absorbed the whole bill, which is a real loss.
		$charged = $shipping_charged_minor ?? 0;

		$profit = $charged - $carrier_cost_minor;
		$loss   = $carrier_cost_minor - $charged;

		$recovery_bp = $carrier_cost_minor > 0
			? Money::mul_div_round( $charged, Money::BP_100, $carrier_cost_minor )
			: null;

		$loss_of_order_bp = ( null !== $order_total_minor && $order_total_minor > 0 )
			? Money::mul_div_round( $loss, Money::BP_100, $order_total_minor )
			: null;

		if ( $profit > 0 ) {
			$result = self::RESULT_PROFIT;
		} elseif ( 0 === $profit ) {
			$result = self::RESULT_BREAK_EVEN;
		} else {
			$result = self::is_high_loss( $loss, $loss_of_order_bp )
				? self::RESULT_HIGH_LOSS
				: self::RESULT_LOSS;
		}

		return array(
			'result'           => $result,
			'profit_minor'     => $profit,
			'loss_minor'       => $loss,
			'recovery_bp'      => $recovery_bp,
			'loss_of_order_bp' => $loss_of_order_bp,
		);
	}

	/**
	 * Whether a loss is large enough to call high.
	 *
	 * @param int      $loss_minor       Positive loss in minor units.
	 * @param int|null $loss_of_order_bp Loss as a share of order value.
	 * @return bool
	 */
	private static function is_high_loss( int $loss_minor, ?int $loss_of_order_bp ): bool {
		if ( null !== $loss_of_order_bp ) {
			return $loss_of_order_bp >= self::HIGH_LOSS_SHARE_BP;
		}
		return $loss_minor >= self::HIGH_LOSS_ABSOLUTE_MINOR;
	}

	/**
	 * Summarise a set of evaluated orders.
	 *
	 * `orders_seen` and `orders_assessed` are reported separately for the same
	 * reason findings carry a null amount: "we looked at 1,428 orders and could
	 * price 41" is the honest sentence, and it is a completely different claim
	 * from "41 orders lost money".
	 *
	 * @param array<int, array{shipping_charged_minor:int|null,carrier_cost_minor:int|null,order_total_minor:int|null}> $orders Orders.
	 * @return array{
	 *     orders_seen:int,
	 *     orders_assessed:int,
	 *     orders_at_loss:int,
	 *     total_loss_minor:int|null,
	 *     net_minor:int|null,
	 *     shipping_charged_minor:int|null,
	 *     carrier_cost_minor:int|null
	 * }
	 */
	public static function summarise( array $orders ): array {
		$seen          = count( $orders );
		$assessed      = 0;
		$at_loss       = 0;
		$total_loss    = null;
		$net           = null;
		$total_charged = null;
		$total_carrier = null;

		foreach ( $orders as $order ) {
			$charged = $order['shipping_charged_minor'] ?? null;
			$cost    = $order['carrier_cost_minor'] ?? null;

			if ( null === $cost ) {
				continue;
			}

			++$assessed;
			$effective_charged = $charged ?? 0;
			$loss              = $cost - $effective_charged;

			// Totalled over the ASSESSED subset only. Including unpriced orders
			// would put their shipping revenue against a cost of nothing and
			// make the recovery ratio - and the shipping score - look far
			// better than the evidence supports.
			$total_charged = null === $total_charged ? $effective_charged : $total_charged + $effective_charged;
			$total_carrier = null === $total_carrier ? $cost : $total_carrier + $cost;
			$net           = null === $net ? $loss : $net + $loss;

			if ( $loss > 0 ) {
				++$at_loss;
				$total_loss = null === $total_loss ? $loss : $total_loss + $loss;
			}
		}//end foreach

		return array(
			'orders_seen'            => $seen,
			'orders_assessed'        => $assessed,
			'orders_at_loss'         => $at_loss,
			'total_loss_minor'       => $total_loss,
			'net_minor'              => $net,
			'shipping_charged_minor' => $total_charged,
			'carrier_cost_minor'     => $total_carrier,
		);
	}

	// Duplicate detection.

	/**
	 * Find carrier rows that look like the same shipment billed twice.
	 *
	 * Grouped on TRACKING NUMBER, because the duplicate that costs money is one
	 * shipment invoiced twice, and a repeated tracking number is exactly that.
	 *
	 * Rows with no tracking number are SKIPPED rather than grouped together
	 * under the empty string - grouping them would report every untracked row
	 * in the file as one enormous duplicate, which is the classic bug in this
	 * kind of detector.
	 *
	 * @param array<int, array{tracking_number:string|null,carrier_cost_minor:int|null,row_index:int}> $rows Rows.
	 * @return array<int, array{tracking_number:string,row_indexes:int[],duplicate_amount_minor:int|null}>
	 */
	public static function detect_duplicates( array $rows ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			$tracking = isset( $row['tracking_number'] ) ? strtoupper( trim( (string) $row['tracking_number'] ) ) : '';
			if ( '' === $tracking ) {
				continue;
			}
			if ( ! isset( $groups[ $tracking ] ) ) {
				$groups[ $tracking ] = array();
			}
			$groups[ $tracking ][] = $row;
		}

		$out = array();
		foreach ( $groups as $tracking => $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			$priced = array_values(
				array_filter(
					$group,
					static function ( $row ) {
						return null !== ( $row['carrier_cost_minor'] ?? null );
					}
				)
			);

			// The recoverable amount is every copy after the first.
			$duplicate_amount = null;
			if ( count( $priced ) >= 2 ) {
				$duplicate_amount = 0;
				foreach ( array_slice( $priced, 1 ) as $row ) {
					$duplicate_amount += (int) $row['carrier_cost_minor'];
				}
			}

			$out[] = array(
				'tracking_number'        => (string) $tracking,
				'row_indexes'            => array_map(
					static function ( $row ) {
						return (int) ( $row['row_index'] ?? 0 );
					},
					$group
				),
				'duplicate_amount_minor' => $duplicate_amount,
			);
		}//end foreach

		// Deterministic order so a report generated twice is identical.
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['tracking_number'], $b['tracking_number'] );
			}
		);

		return $out;
	}
}
