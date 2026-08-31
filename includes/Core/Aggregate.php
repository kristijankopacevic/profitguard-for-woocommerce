<?php
/**
 * Turning a list of findings into the numbers on the dashboard.
 *
 * Ported from the ProfitGuard TypeScript core (lib/findings/aggregate.ts).
 *
 * This class exists because one specific mistake would be very easy to make and
 * almost impossible to notice: treating a finding with no stateable amount as a
 * finding worth zero.
 *
 *     SELECT SUM( COALESCE( impact_minor, 0 ) ) ...   -- WRONG
 *
 * That query returns a number. It is not wrong in any way a test checking "is
 * it a number" would catch, and on a demo store it is barely wrong at all. On a
 * real store with no cost data on half the catalog it is wrong by half, and it
 * is wrong in the direction that makes ProfitGuard look like it found nothing.
 *
 * So every total here is summed over findings whose amount is NOT null, and
 * every total is returned alongside the count that was deliberately left out.
 * The UI is expected to say so out loud. tests/Unit/AggregateTest.php asserts
 * both halves.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Core;

defined( 'ABSPATH' ) || defined( 'PROFITGUARD_TESTING' ) || exit;

/**
 * Roll findings up. Pure PHP: no WordPress, no WooCommerce.
 */
final class Aggregate {

	/**
	 * Summarise findings for the dashboard.
	 *
	 * @param Finding[] $findings Findings.
	 * @return array{
	 *     total_minor:int|null,
	 *     count:int,
	 *     without_amount:int,
	 *     by_module:array<string, array{amount_minor:int|null,count:int,without_amount:int}>,
	 *     by_severity:array<string,int>,
	 *     by_type:array<string,int>,
	 *     ranked:Finding[]
	 * }
	 */
	public static function summarise( array $findings ): array {
		$by_module = array(
			Finding::MODULE_MARGIN   => array(
				'amount_minor'   => null,
				'count'          => 0,
				'without_amount' => 0,
			),
			Finding::MODULE_SHIPPING => array(
				'amount_minor'   => null,
				'count'          => 0,
				'without_amount' => 0,
			),
		);

		$by_severity = array(
			Finding::SEVERITY_CRITICAL => 0,
			Finding::SEVERITY_HIGH     => 0,
			Finding::SEVERITY_MEDIUM   => 0,
			Finding::SEVERITY_LOW      => 0,
			Finding::SEVERITY_INFO     => 0,
		);

		$by_type        = array();
		$total_minor    = null;
		$without_amount = 0;

		foreach ( $findings as $finding ) {
			$module = isset( $by_module[ $finding->module ] ) ? $finding->module : Finding::MODULE_MARGIN;

			++$by_module[ $module ]['count'];

			if ( isset( $by_severity[ $finding->severity ] ) ) {
				++$by_severity[ $finding->severity ];
			}

			if ( ! isset( $by_type[ $finding->type ] ) ) {
				$by_type[ $finding->type ] = 0;
			}
			++$by_type[ $finding->type ];

			if ( $finding->has_amount() ) {
				$amount = (int) $finding->impact_minor;

				// Null + amount = amount, not 0 + amount. Starting the running
				// total at null is what makes "we could price nothing" produce
				// null rather than zero.
				$total_minor = null === $total_minor ? $amount : $total_minor + $amount;

				$by_module[ $module ]['amount_minor'] = null === $by_module[ $module ]['amount_minor']
					? $amount
					: $by_module[ $module ]['amount_minor'] + $amount;
			} else {
				++$without_amount;
				++$by_module[ $module ]['without_amount'];
			}
		}

		return array(
			'total_minor'    => $total_minor,
			'count'          => count( $findings ),
			'without_amount' => $without_amount,
			'by_module'      => $by_module,
			'by_severity'    => $by_severity,
			'by_type'        => $by_type,
			'ranked'         => Finding::rank( $findings ),
		);
	}

	/**
	 * Count findings of a given type.
	 *
	 * @param array<string,int> $by_type Type counts from summarise().
	 * @param string            $type    A Finding::TYPE_* constant.
	 * @return int
	 */
	public static function count_of( array $by_type, string $type ): int {
		return isset( $by_type[ $type ] ) ? (int) $by_type[ $type ] : 0;
	}

	/**
	 * Sum only the amounts of findings of a given type.
	 *
	 * Used for "total evidenced shipping loss", which must not include findings
	 * of other types and must not include the ones with no amount.
	 *
	 * @param Finding[] $findings Findings.
	 * @param string[]  $types    Types to include.
	 * @return int|null Total in minor units, or null when none had an amount.
	 */
	public static function sum_types( array $findings, array $types ): ?int {
		$total = null;
		foreach ( $findings as $finding ) {
			if ( ! in_array( $finding->type, $types, true ) ) {
				continue;
			}
			if ( ! $finding->has_amount() ) {
				continue;
			}
			$total = null === $total ? (int) $finding->impact_minor : $total + (int) $finding->impact_minor;
		}
		return $total;
	}
}
