<?php
/**
 * The findings model, aggregation, and the ProfitGuard Score.
 *
 * The single most important assertion here is that a finding with no stateable
 * amount is COUNTED but not SUMMED. If that regresses, the dashboard quietly
 * understates every merchant's exposure and the only symptom is a headline that
 * looks plausible.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProfitGuard\Core\Aggregate;
use ProfitGuard\Core\Finding;
use ProfitGuard\Core\Score;

final class FindingsTest extends TestCase {

	/**
	 * Build a finding with sensible defaults.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return Finding
	 */
	private function finding( array $over = array() ): Finding {
		return new Finding(
			array_merge(
				array(
					'module'         => Finding::MODULE_MARGIN,
					'type'           => Finding::TYPE_LOW_MARGIN,
					'severity'       => Finding::SEVERITY_MEDIUM,
					'financial_type' => Finding::FINANCIAL_ESTIMATED_IMPACT,
					'impact_minor'   => 10000,
					'confidence'     => 90,
					'subject_kind'   => Finding::SUBJECT_PRODUCT,
					'subject_id'     => 1,
					'subject_label'  => 'Wireless Charger',
					'reference'      => 'WC-001',
				),
				$over
			)
		);
	}

	/* ================================================================ *
	 * Aggregation - unknown is null, never zero
	 * ================================================================ */

	public function test_sums_only_findings_that_state_an_amount(): void {
		$summary = Aggregate::summarise(
			array(
				$this->finding( array( 'impact_minor' => 50000 ) ),
				$this->finding( array( 'impact_minor' => 30000 ) ),
				$this->finding( array( 'impact_minor' => null ) ),
				$this->finding( array( 'impact_minor' => null ) ),
			)
		);

		$this->assertSame( 80000, $summary['total_minor'] );
		// All four are real findings and all four are counted.
		$this->assertSame( 4, $summary['count'] );
		// Two were deliberately left out of the money figure.
		$this->assertSame( 2, $summary['without_amount'] );
	}

	public function test_reports_a_null_total_rather_than_zero_when_nothing_could_be_priced(): void {
		$summary = Aggregate::summarise(
			array(
				$this->finding( array( 'impact_minor' => null ) ),
				$this->finding( array( 'impact_minor' => null ) ),
			)
		);

		// The distinction the whole product rests on.
		$this->assertNull( $summary['total_minor'] );
		$this->assertNotSame( 0, $summary['total_minor'] );
		$this->assertSame( 2, $summary['count'] );
		$this->assertSame( 2, $summary['without_amount'] );
	}

	public function test_an_empty_set_totals_null_not_zero(): void {
		$summary = Aggregate::summarise( array() );
		$this->assertNull( $summary['total_minor'] );
		$this->assertSame( 0, $summary['count'] );
	}

	public function test_keeps_the_distinction_per_module(): void {
		$summary = Aggregate::summarise(
			array(
				$this->finding( array( 'module' => Finding::MODULE_MARGIN, 'impact_minor' => 81400 ) ),
				$this->finding( array( 'module' => Finding::MODULE_SHIPPING, 'impact_minor' => 42600 ) ),
				$this->finding( array( 'module' => Finding::MODULE_SHIPPING, 'impact_minor' => null ) ),
			)
		);

		$this->assertSame( 81400, $summary['by_module'][ Finding::MODULE_MARGIN ]['amount_minor'] );
		$this->assertSame( 42600, $summary['by_module'][ Finding::MODULE_SHIPPING ]['amount_minor'] );
		$this->assertSame( 2, $summary['by_module'][ Finding::MODULE_SHIPPING ]['count'] );
		$this->assertSame( 1, $summary['by_module'][ Finding::MODULE_SHIPPING ]['without_amount'] );
	}

	public function test_a_module_with_no_findings_totals_null(): void {
		$summary = Aggregate::summarise( array( $this->finding( array( 'module' => Finding::MODULE_MARGIN ) ) ) );
		$this->assertNull( $summary['by_module'][ Finding::MODULE_SHIPPING ]['amount_minor'] );
		$this->assertSame( 0, $summary['by_module'][ Finding::MODULE_SHIPPING ]['count'] );
	}

	public function test_counts_by_severity_and_type(): void {
		$summary = Aggregate::summarise(
			array(
				$this->finding( array( 'severity' => Finding::SEVERITY_CRITICAL, 'type' => Finding::TYPE_NEGATIVE_MARGIN ) ),
				$this->finding( array( 'severity' => Finding::SEVERITY_CRITICAL, 'type' => Finding::TYPE_NEGATIVE_MARGIN ) ),
				$this->finding( array( 'severity' => Finding::SEVERITY_LOW, 'type' => Finding::TYPE_MISSING_COST ) ),
			)
		);

		$this->assertSame( 2, $summary['by_severity'][ Finding::SEVERITY_CRITICAL ] );
		$this->assertSame( 1, $summary['by_severity'][ Finding::SEVERITY_LOW ] );
		$this->assertSame( 0, $summary['by_severity'][ Finding::SEVERITY_HIGH ] );
		$this->assertSame( 2, Aggregate::count_of( $summary['by_type'], Finding::TYPE_NEGATIVE_MARGIN ) );
		$this->assertSame( 1, Aggregate::count_of( $summary['by_type'], Finding::TYPE_MISSING_COST ) );
		$this->assertSame( 0, Aggregate::count_of( $summary['by_type'], Finding::TYPE_COST_INCREASE ) );
	}

	public function test_sum_types_totals_only_the_named_types(): void {
		$findings = array(
			$this->finding( array( 'type' => Finding::TYPE_SHIPPING_LOSS, 'impact_minor' => 643 ) ),
			$this->finding( array( 'type' => Finding::TYPE_HIGH_SHIPPING_LOSS, 'impact_minor' => 1442 ) ),
			$this->finding( array( 'type' => Finding::TYPE_LOW_MARGIN, 'impact_minor' => 99999 ) ),
			$this->finding( array( 'type' => Finding::TYPE_MISSING_CARRIER_COST, 'impact_minor' => null ) ),
		);

		$total = Aggregate::sum_types(
			$findings,
			array( Finding::TYPE_SHIPPING_LOSS, Finding::TYPE_HIGH_SHIPPING_LOSS )
		);
		$this->assertSame( 2085, $total );
	}

	public function test_sum_types_is_null_when_no_named_type_had_an_amount(): void {
		$findings = array( $this->finding( array( 'type' => Finding::TYPE_MISSING_CARRIER_COST, 'impact_minor' => null ) ) );
		$this->assertNull( Aggregate::sum_types( $findings, array( Finding::TYPE_MISSING_CARRIER_COST ) ) );
	}

	/* ================================================================ *
	 * Ranking
	 * ================================================================ */

	public function test_ranks_the_largest_amount_first(): void {
		$ranked = Finding::rank(
			array(
				$this->finding( array( 'reference' => 'small', 'impact_minor' => 100 ) ),
				$this->finding( array( 'reference' => 'large', 'impact_minor' => 90000 ) ),
				$this->finding( array( 'reference' => 'medium', 'impact_minor' => 5000 ) ),
			)
		);
		$this->assertSame( array( 'large', 'medium', 'small' ), array_column( array_map( static function ( $f ) {
			return array( 'reference' => $f->reference );
		}, $ranked ), 'reference' ) );
	}

	public function test_every_priced_finding_outranks_every_unpriced_one(): void {
		$ranked = Finding::rank(
			array(
				$this->finding(
					array(
						'reference'    => 'unpriced-critical',
						'impact_minor' => null,
						'severity'     => Finding::SEVERITY_CRITICAL,
					)
				),
				$this->finding(
					array(
						'reference'    => 'priced-info',
						'impact_minor' => 1,
						'severity'     => Finding::SEVERITY_INFO,
					)
				),
			)
		);
		$this->assertSame( 'priced-info', $ranked[0]->reference );
		$this->assertSame( 'unpriced-critical', $ranked[1]->reference );
	}

	public function test_ranks_by_magnitude_so_a_loss_and_a_gain_tie_on_amount(): void {
		$ranked = Finding::rank(
			array(
				$this->finding(
					array(
						'reference'    => 'gain',
						'impact_minor' => 5000,
						'severity'     => Finding::SEVERITY_LOW,
					)
				),
				$this->finding(
					array(
						'reference'    => 'loss',
						'impact_minor' => -5000,
						'severity'     => Finding::SEVERITY_HIGH,
					)
				),
			)
		);
		// Equal magnitude, so severity decides.
		$this->assertSame( 'loss', $ranked[0]->reference );
	}

	public function test_falls_back_to_severity_then_confidence_for_unpriced_findings(): void {
		$ranked = Finding::rank(
			array(
				$this->finding( array( 'reference' => 'low', 'impact_minor' => null, 'severity' => Finding::SEVERITY_LOW ) ),
				$this->finding(
					array(
						'reference'    => 'crit-unsure',
						'impact_minor' => null,
						'severity'     => Finding::SEVERITY_CRITICAL,
						'confidence'   => 40,
					)
				),
				$this->finding(
					array(
						'reference'    => 'crit-sure',
						'impact_minor' => null,
						'severity'     => Finding::SEVERITY_CRITICAL,
						'confidence'   => 99,
					)
				),
			)
		);
		$this->assertSame( 'crit-sure', $ranked[0]->reference );
		$this->assertSame( 'crit-unsure', $ranked[1]->reference );
		$this->assertSame( 'low', $ranked[2]->reference );
	}

	public function test_ranking_is_a_total_order(): void {
		$set = array(
			$this->finding( array( 'reference' => 'B', 'impact_minor' => 500 ) ),
			$this->finding( array( 'reference' => 'A', 'impact_minor' => 500 ) ),
		);
		$this->assertSame( 'A', Finding::rank( $set )[0]->reference );
		$this->assertSame( 'A', Finding::rank( array_reverse( $set ) )[0]->reference );
	}

	public function test_ranking_does_not_mutate_the_input(): void {
		$set = array(
			$this->finding( array( 'reference' => 'a', 'impact_minor' => 1 ) ),
			$this->finding( array( 'reference' => 'b', 'impact_minor' => 2 ) ),
		);
		Finding::rank( $set );
		$this->assertSame( 'a', $set[0]->reference );
	}

	/* ================================================================ *
	 * The plan gate must NOT exist
	 * ================================================================ */

	public function test_the_finding_model_has_no_plan_gate(): void {
		/*
		 * WordPress.org forbids "20 findings free, pay to unlock the rest" in a
		 * directory plugin, so the concept must not exist even as a dormant
		 * field. This is a structural assertion, not a behavioural one: if
		 * someone later ports the Shopify dashboard's `locked` count across,
		 * this test is what stops it shipping.
		 */
		$fields = array_keys( $this->finding()->to_array() );
		foreach ( array( 'locked', 'visible', 'plan', 'gated', 'premium', 'pro_only' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $fields );
		}
	}

	/* ================================================================ *
	 * Score
	 * ================================================================ */

	public function test_margin_score_is_100_for_a_healthy_catalog(): void {
		$this->assertSame( 100, Score::margin_score( 100, 0, 0, 0 ) );
	}

	public function test_margin_score_is_0_when_everything_sells_below_cost(): void {
		$this->assertSame( 0, Score::margin_score( 100, 100, 0, 0 ) );
	}

	public function test_margin_score_weights_negative_three_times_a_low_margin(): void {
		$this->assertSame( Score::margin_score( 30, 1, 0, 0 ), Score::margin_score( 30, 0, 0, 3 ) );
	}

	public function test_margin_score_is_null_not_zero_when_nothing_was_assessed(): void {
		$this->assertNull( Score::margin_score( 0, 0, 0, 0 ) );
	}

	public function test_shipping_score_is_recovery_of_the_carrier_bill(): void {
		$this->assertSame( 100, Score::shipping_score( 10, 5000, 5000 ) );
		$this->assertSame( 50, Score::shipping_score( 10, 2500, 5000 ) );
		// Order #1842 from the specification: EUR 7.99 against EUR 14.42.
		$this->assertSame( 55, Score::shipping_score( 1, 799, 1442 ) );
	}

	public function test_shipping_score_does_not_reward_overcharging(): void {
		$this->assertSame( 100, Score::shipping_score( 10, 50000, 5000 ) );
	}

	public function test_shipping_score_is_null_without_evidence(): void {
		$this->assertNull( Score::shipping_score( 0, 0, 0 ) );
		$this->assertNull( Score::shipping_score( 5, 900, 0 ) );
	}

	public function test_overall_score_is_null_for_a_store_we_know_nothing_about(): void {
		$result = Score::overall( null, null );
		// Not zero. A merchant who just installed the plugin must not be told
		// their store scores 0 because we have no data yet.
		$this->assertNull( $result['score'] );
		$this->assertSame( 0, $result['assessed_categories'] );
		$this->assertNull( Score::band( $result['score'] ) );
	}

	public function test_a_margin_only_store_scores_on_margin_alone(): void {
		$result = Score::overall( 100, null );
		// A perfect margin picture scores 100 even though shipping has no data.
		$this->assertSame( 100, $result['score'] );
		$this->assertSame( 1, $result['assessed_categories'] );
	}

	public function test_weights_are_renormalised_over_assessed_categories(): void {
		$result = Score::overall( 0, 100 );
		// (0*60 + 100*40) / 100 = 40
		$this->assertSame( 40, $result['score'] );
		$this->assertSame( 2, $result['assessed_categories'] );
	}

	public function test_an_unassessed_category_carries_zero_weight_and_a_reason(): void {
		$result   = Score::overall( 80, null );
		$shipping = null;
		foreach ( $result['categories'] as $category ) {
			if ( Score::CATEGORY_SHIPPING === $category['category'] ) {
				$shipping = $category;
			}
		}
		$this->assertNotNull( $shipping );
		$this->assertNull( $shipping['score'] );
		$this->assertSame( 0, $shipping['weight'] );
		$this->assertStringContainsString( 'carrier cost', (string) $shipping['unavailable_reason'] );
	}

	public function test_coverage_is_reported_separately_from_the_score(): void {
		// The example from the brief: a score alongside honest coverage.
		$result = Score::overall( 78, 60, 81, 34 );
		$this->assertSame( 71, $result['score'] );
		$this->assertSame( 81, $result['categories'][0]['coverage_percent'] );
		$this->assertSame( 34, $result['categories'][1]['coverage_percent'] );
	}

	public function test_coverage_percent(): void {
		$this->assertSame( 81, Score::coverage_percent( 726, 897 ) );
		$this->assertSame( 100, Score::coverage_percent( 10, 10 ) );
		$this->assertSame( 0, Score::coverage_percent( 0, 10 ) );
	}

	public function test_coverage_is_null_not_zero_for_an_empty_store(): void {
		// "You have no products" is a different statement from "we could
		// assess none of your products".
		$this->assertNull( Score::coverage_percent( 0, 0 ) );
	}

	public function test_score_never_leaves_the_0_100_range(): void {
		for ( $negative = 0; $negative <= 50; $negative++ ) {
			$margin = Score::margin_score( 50, $negative, 50 - $negative, 0 );
			$this->assertNotNull( $margin );
			$this->assertGreaterThanOrEqual( 0, $margin );
			$this->assertLessThanOrEqual( 100, $margin );
		}
	}

	public function test_score_is_deterministic(): void {
		$a = Score::overall( 71, 42, 80, 30 );
		$b = Score::overall( 71, 42, 80, 30 );
		$this->assertSame( $a, $b );
	}

	public function test_band_boundaries(): void {
		$this->assertSame( 'STRONG', Score::band( 85 ) );
		$this->assertSame( 'GOOD', Score::band( 84 ) );
		$this->assertSame( 'GOOD', Score::band( 70 ) );
		$this->assertSame( 'FAIR', Score::band( 69 ) );
		$this->assertSame( 'NEEDS_ATTENTION', Score::band( 54 ) );
		$this->assertSame( 'AT_RISK', Score::band( 34 ) );
	}
}
