<?php
/**
 * Shipping rules: normalised orders in, findings out.
 *
 * Pure PHP over plain arrays, for the same reasons as MarginAnalyser.
 *
 * THE COVERAGE PROBLEM, HANDLED HONESTLY
 *
 * A merchant who has just installed the plugin has 1,428 orders and zero
 * carrier invoices, so every order is MISSING_CARRIER_COST and this module can
 * say nothing about money. That is the truth. The temptation is to fill the gap
 * with an estimate from a published rate card; we do not, because a fabricated
 * shipping loss is indistinguishable from a real one until the merchant checks
 * it against their carrier statement and stops trusting the whole plugin.
 *
 * PER-ORDER MISSING ROWS ARE CAPPED. Emitting 1,428 identical
 * MISSING_CARRIER_COST rows would bury every real finding and bloat the
 * findings table for no gain. A bounded sample is stored so the merchant can
 * see examples in the table, and the true total is carried in the scan totals
 * so the dashboard can state it exactly.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Analysis;

use ProfitGuard\Core\Finding;
use ProfitGuard\Core\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Turns orders into shipping findings.
 */
final class ShippingAnalyser {

	/**
	 * How many MISSING_CARRIER_COST examples to store per scan.
	 */
	public const MISSING_SAMPLE_LIMIT = 25;

	/**
	 * Analyse one order.
	 *
	 * @param array<string, mixed> $order   Normalised order from Woo\Orders.
	 * @param array<string, mixed> $context Scan context.
	 *     @type int $missing_emitted How many missing rows already stored.
	 * }
	 * @return Finding[]
	 */
	public static function analyse( array $order, array $context = array() ): array {
		$id      = (int) ( $order['id'] ?? 0 );
		$number  = (string) ( $order['number'] ?? '' );
		$charged = self::nullable_int( $order['shipping_charged_minor'] ?? null );
		$cost    = self::nullable_int( $order['carrier_cost_minor'] ?? null );
		$total   = self::nullable_int( $order['total_minor'] ?? null );

		$base = array(
			'module'        => Finding::MODULE_SHIPPING,
			'subject_kind'  => Finding::SUBJECT_ORDER,
			'subject_id'    => $id,
			'subject_label' => '' !== $number ? $number : (string) $id,
			'reference'     => '' !== $number ? $number : (string) $id,
		);

		$result = Shipping::evaluate( $charged, $cost, $total );

		/* --- no carrier cost ------------------------------------------ */
		if ( Shipping::RESULT_MISSING_COST === $result['result'] ) {
			// An order that never sold shipping and has no carrier cost is a
			// digital order. There is nothing to say about it at all.
			if ( null === $charged ) {
				return array();
			}

			$emitted = (int) ( $context['missing_emitted'] ?? 0 );
			if ( $emitted >= self::MISSING_SAMPLE_LIMIT ) {
				return array();
			}

			return array(
				new Finding(
					array_merge(
						$base,
						array(
							'type'           => Finding::TYPE_MISSING_CARRIER_COST,
							'severity'       => Finding::SEVERITY_INFO,
							'financial_type' => Finding::FINANCIAL_MISSING_DATA,
							// WooCommerce knows what the customer paid and
							// cannot know what the carrier billed. Estimating
							// the gap would manufacture a loss.
							'impact_minor'   => null,
							'confidence'     => 100,
							'current_minor'  => $charged,
							'expected_minor' => null,
							'evidence'       => array(
								'shipping_charged_minor' => $charged,
								'shipping_method'        => (string) ( $order['shipping_method'] ?? '' ),
							),
						)
					)
				),
			);
		}//end if

		/* --- priced --------------------------------------------------- */
		$evidence = array(
			'shipping_charged_minor' => $charged,
			'carrier_cost_minor'     => $cost,
			'order_total_minor'      => $total,
			'recovery_bp'            => $result['recovery_bp'],
			'shipping_method'        => (string) ( $order['shipping_method'] ?? '' ),
			'carrier'                => (string) ( $order['carrier'] ?? '' ),
		);

		if ( Shipping::RESULT_PROFIT === $result['result'] || Shipping::RESULT_BREAK_EVEN === $result['result'] ) {
			/*
			 * Profitable shipping is stored as an INFO finding rather than
			 * discarded. The brief lists SHIPPING_PROFIT as a finding type, and
			 * it earns its place: a merchant who has just imported an invoice
			 * needs to see the orders that were fine as well as the ones that
			 * were not, or the report reads as an accusation rather than an
			 * audit.
			 */
			return array(
				new Finding(
					array_merge(
						$base,
						array(
							'type'           => Finding::TYPE_SHIPPING_PROFIT,
							'severity'       => Finding::SEVERITY_INFO,
							'financial_type' => Finding::FINANCIAL_EVIDENCED_DIFFERENCE,
							'impact_minor'   => $result['profit_minor'],
							'confidence'     => 100,
							'current_minor'  => $charged,
							'expected_minor' => $cost,
							'evidence'       => $evidence,
						)
					)
				),
			);
		}//end if

		$high = Shipping::RESULT_HIGH_LOSS === $result['result'];

		return array(
			new Finding(
				array_merge(
					$base,
					array(
						'type'           => $high ? Finding::TYPE_HIGH_SHIPPING_LOSS : Finding::TYPE_SHIPPING_LOSS,
						'severity'       => $high ? Finding::SEVERITY_HIGH : Finding::SEVERITY_MEDIUM,
						// Both halves are documents the merchant supplied, so
						// this is the most certain kind of finding there is.
						'financial_type' => Finding::FINANCIAL_EVIDENCED_DIFFERENCE,
						'impact_minor'   => $result['loss_minor'],
						'confidence'     => 100,
						'current_minor'  => $charged,
						'expected_minor' => $cost,
						'evidence'       => array_merge(
							$evidence,
							array( 'loss_of_order_bp' => $result['loss_of_order_bp'] )
						),
					)
				)
			),
		);
	}

	/**
	 * Findings for carrier rows that did not match any order.
	 *
	 * Unmatched rows are surfaced rather than dropped: a file where nothing
	 * matched is a mapping mistake the merchant can fix in a minute, and a
	 * silent import that produced no findings looks like a broken plugin.
	 *
	 * @param array<int, array{order_reference:string,tracking_number:string,cost_minor:int|null,row_index:int}> $rows Rows.
	 * @param int                                                                                                $limit Cap.
	 * @return Finding[]
	 */
	public static function unmatched_findings( array $rows, int $limit = 50 ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$reference = (string) ( $row['order_reference'] ?? '' );
			$out[]     = new Finding(
				array(
					'module'         => Finding::MODULE_SHIPPING,
					'type'           => Finding::TYPE_UNMATCHED_CARRIER_ROW,
					'severity'       => Finding::SEVERITY_LOW,
					'financial_type' => Finding::FINANCIAL_MISSING_DATA,
					'impact_minor'   => null,
					'confidence'     => 100,
					'subject_kind'   => Finding::SUBJECT_IMPORT_ROW,
					'subject_id'     => (int) ( $row['row_index'] ?? 0 ),
					'subject_label'  => '' !== $reference ? $reference : (string) ( $row['tracking_number'] ?? '' ),
					'reference'      => $reference,
					'current_minor'  => self::nullable_int( $row['cost_minor'] ?? null ),
					'expected_minor' => null,
					'evidence'       => array(
						'order_reference' => $reference,
						'tracking_number' => (string) ( $row['tracking_number'] ?? '' ),
					),
				)
			);
		}//end foreach
		return $out;
	}

	/**
	 * Findings for carrier rows that look like the same shipment billed twice.
	 *
	 * @param array<int, array{tracking_number:string,row_indexes:int[],duplicate_amount_minor:int|null}> $duplicates From Core\Shipping::detect_duplicates().
	 * @return Finding[]
	 */
	public static function duplicate_findings( array $duplicates ): array {
		$out = array();
		foreach ( $duplicates as $duplicate ) {
			$out[] = new Finding(
				array(
					'module'         => Finding::MODULE_SHIPPING,
					'type'           => Finding::TYPE_POSSIBLE_DUPLICATE_CARRIER_ROW,
					'severity'       => Finding::SEVERITY_HIGH,
					// POSSIBLE, not confirmed: two parcels can legitimately
					// share one reference on some carriers, so this is a matter
					// for review rather than a stated error. The plugin never
					// accuses a carrier of anything.
					'financial_type' => Finding::FINANCIAL_EVIDENCED_DIFFERENCE,
					'impact_minor'   => self::nullable_int( $duplicate['duplicate_amount_minor'] ?? null ),
					'confidence'     => 80,
					'subject_kind'   => Finding::SUBJECT_IMPORT_ROW,
					'subject_id'     => (int) ( $duplicate['row_indexes'][0] ?? 0 ),
					'subject_label'  => (string) ( $duplicate['tracking_number'] ?? '' ),
					'reference'      => (string) ( $duplicate['tracking_number'] ?? '' ),
					'current_minor'  => null,
					'expected_minor' => null,
					'evidence'       => array(
						'tracking_number' => (string) ( $duplicate['tracking_number'] ?? '' ),
						'occurrences'     => count( $duplicate['row_indexes'] ?? array() ),
					),
				)
			);
		}//end foreach
		return $out;
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
