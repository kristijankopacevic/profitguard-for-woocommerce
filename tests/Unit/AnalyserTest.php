<?php
/**
 * The margin and shipping rule sets.
 *
 * These tests are about JUDGEMENT, not arithmetic - the arithmetic is already
 * property-tested in MarginTest. What matters here is which rule fires, how
 * severe it is, and above all when a monetary figure is and is not allowed to
 * exist.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProfitGuard\Analysis\MarginAnalyser;
use ProfitGuard\Analysis\ShippingAnalyser;
use ProfitGuard\Core\Finding;

final class AnalyserTest extends TestCase {

	/**
	 * Scan options with a 30% target.
	 *
	 * @return array<string, int>
	 */
	private function options(): array {
		return array(
			'target_margin_bp' => 3000,
			'warning_band_bp'  => 500,
			'critical_band_bp' => 1500,
		);
	}

	/**
	 * A normalised product.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function product( array $over = array() ): array {
		return array_merge(
			array(
				'id'                  => 101,
				'parent_id'           => 101,
				'type'                => 'simple',
				'sku'                 => 'WC-001',
				'name'                => 'Wireless Charger',
				'regular_price_minor' => 2999,
				'sale_price_minor'    => null,
				'price_minor'         => 2999,
				'cost_minor'          => 1300,
				'cost_source'         => 'profitguard',
				'previous_cost_minor' => null,
				'is_on_sale'          => false,
				'stock_status'        => 'instock',
				'category_ids'        => array(),
			),
			$over
		);
	}

	/**
	 * Collect finding types.
	 *
	 * @param Finding[] $findings Findings.
	 * @return string[]
	 */
	private function types( array $findings ): array {
		return array_map(
			static function ( Finding $f ) {
				return $f->type;
			},
			$findings
		);
	}

	// Margin rules.

	public function test_says_nothing_about_a_healthy_product(): void {
		// 29.99 selling, 13.00 cost -> 56.7%, well above a 30% target.
		$this->assertSame( array(), MarginAnalyser::analyse( $this->product(), $this->options() ) );
	}

	public function test_reproduces_the_specification_example(): void {
		// Wireless Charger: 29.99 selling, 24.50 cost, 30% target.
		$findings = MarginAnalyser::analyse(
			$this->product( array( 'cost_minor' => 2450 ) ),
			$this->options()
		);

		$this->assertCount( 1, $findings );
		$this->assertSame( Finding::TYPE_LOW_MARGIN, $findings[0]->type );
		$this->assertSame( 1831, $findings[0]->evidence['margin_bp'] );
		// Recommended EUR 35.00, as the brief states.
		$this->assertSame( 3500, $findings[0]->expected_minor );
	}

	public function test_flags_a_product_selling_below_cost_as_critical(): void {
		$findings = MarginAnalyser::analyse(
			$this->product(
				array(
					'price_minor' => 1000,
					'cost_minor'  => 1200,
				)
			),
			$this->options()
		);

		$this->assertCount( 1, $findings );
		$this->assertSame( Finding::TYPE_NEGATIVE_MARGIN, $findings[0]->type );
		$this->assertSame( Finding::SEVERITY_CRITICAL, $findings[0]->severity );
		// The loss per unit, as an exact calculation.
		$this->assertSame( -200, $findings[0]->impact_minor );
		$this->assertSame( Finding::FINANCIAL_CONFIRMED_CALCULATION, $findings[0]->financial_type );
	}

	public function test_does_not_also_report_a_below_cost_product_as_low_margin(): void {
		// The same problem said twice would double-count in any total.
		$types = $this->types(
			MarginAnalyser::analyse(
				$this->product(
					array(
						'price_minor' => 1000,
						'cost_minor'  => 1200,
					)
				),
				$this->options()
			)
		);
		$this->assertNotContains( Finding::TYPE_LOW_MARGIN, $types );
		$this->assertNotContains( Finding::TYPE_CRITICAL_MARGIN, $types );
	}

	public function test_scales_severity_with_the_shortfall(): void {
		$low      = MarginAnalyser::analyse(
			$this->product(
				array(
					'price_minor' => 1000,
					'cost_minor'  => 720,
				)
			),
			$this->options()
		);
		$critical = MarginAnalyser::analyse(
			$this->product(
				array(
					'price_minor' => 1000,
					'cost_minor'  => 900,
				)
			),
			$this->options()
		);

		$this->assertSame( Finding::TYPE_LOW_MARGIN, $low[0]->type );
		$this->assertSame( Finding::TYPE_CRITICAL_MARGIN, $critical[0]->type );
		$this->assertSame( Finding::SEVERITY_HIGH, $critical[0]->severity );
	}

	public function test_reports_a_missing_cost_per_product_with_no_amount(): void {
		$findings = MarginAnalyser::analyse( $this->product( array( 'cost_minor' => null ) ), $this->options() );

		$this->assertCount( 1, $findings );
		$this->assertSame( Finding::TYPE_MISSING_COST, $findings[0]->type );
		$this->assertSame( Finding::FINANCIAL_MISSING_DATA, $findings[0]->financial_type );
		// Null because it is unknowable, not because it is small.
		$this->assertNull( $findings[0]->impact_minor );
		$this->assertNotSame( 0, $findings[0]->impact_minor );
	}

	public function test_says_nothing_at_all_about_a_product_with_no_price(): void {
		// A draft or an unconfigured option, not a margin problem.
		$this->assertSame(
			array(),
			MarginAnalyser::analyse( $this->product( array( 'price_minor' => null ) ), $this->options() )
		);
	}

	public function test_a_missing_cost_short_circuits_the_other_rules(): void {
		$types = $this->types(
			MarginAnalyser::analyse(
				$this->product(
					array(
						'cost_minor'  => null,
						'is_on_sale'  => true,
						'price_minor' => 1000,
					)
				),
				$this->options()
			)
		);
		$this->assertSame( array( Finding::TYPE_MISSING_COST ), $types );
	}

	/* --- sale price risk ------------------------------------------- */

	public function test_flags_a_sale_that_broke_an_otherwise_healthy_margin(): void {
		// Regular 29.99 at cost 13.00 is 56.7% (healthy). On sale at 14.00 the
		// margin collapses to 7.1%, below half the 30% target.
		$findings = MarginAnalyser::analyse(
			$this->product(
				array(
					'regular_price_minor' => 2999,
					'price_minor'         => 1400,
					'sale_price_minor'    => 1400,
					'is_on_sale'          => true,
				)
			),
			$this->options()
		);

		$types = $this->types( $findings );
		$this->assertContains( Finding::TYPE_SALE_PRICE_MARGIN_RISK, $types );

		$risk = null;
		foreach ( $findings as $finding ) {
			if ( Finding::TYPE_SALE_PRICE_MARGIN_RISK === $finding->type ) {
				$risk = $finding;
			}
		}
		// Margin given up per unit at the sale price.
		$this->assertSame( 1599, $risk->impact_minor );
	}

	public function test_does_not_blame_the_sale_when_the_regular_price_also_misses_target(): void {
		/*
		 * A product whose REGULAR price is already below target has a pricing
		 * problem, not a discounting problem. Reporting both sends the merchant
		 * to the wrong fix.
		 */
		$types = $this->types(
			MarginAnalyser::analyse(
				$this->product(
					array(
						'regular_price_minor' => 1500,
						'price_minor'         => 1400,
						'sale_price_minor'    => 1400,
						'cost_minor'          => 1300,
						'is_on_sale'          => true,
					)
				),
				$this->options()
			)
		);
		$this->assertNotContains( Finding::TYPE_SALE_PRICE_MARGIN_RISK, $types );
	}

	public function test_ignores_a_sale_that_leaves_the_margin_healthy(): void {
		$types = $this->types(
			MarginAnalyser::analyse(
				$this->product(
					array(
						'regular_price_minor' => 2999,
						'price_minor'         => 2799,
						'sale_price_minor'    => 2799,
						'is_on_sale'          => true,
					)
				),
				$this->options()
			)
		);
		$this->assertNotContains( Finding::TYPE_SALE_PRICE_MARGIN_RISK, $types );
	}

	/* --- cost increase --------------------------------------------- */

	public function test_flags_a_material_cost_increase(): void {
		$findings = MarginAnalyser::analyse(
			$this->product(
				array(
					'cost_minor'          => 1500,
					'previous_cost_minor' => 1300,
				)
			),
			$this->options()
		);

		$types = $this->types( $findings );
		$this->assertContains( Finding::TYPE_COST_INCREASE, $types );

		$increase = null;
		foreach ( $findings as $finding ) {
			if ( Finding::TYPE_COST_INCREASE === $finding->type ) {
				$increase = $finding;
			}
		}
		$this->assertSame( 200, $increase->impact_minor );
		$this->assertSame( 1538, $increase->evidence['cost_change_bp'] );
		$this->assertSame( Finding::FINANCIAL_EVIDENCED_DIFFERENCE, $increase->financial_type );
	}

	public function test_ignores_a_trivial_cost_change(): void {
		// Re-import rounding noise, not a supplier increase.
		$types = $this->types(
			MarginAnalyser::analyse(
				$this->product(
					array(
						'cost_minor'          => 1301,
						'previous_cost_minor' => 1300,
					)
				),
				$this->options()
			)
		);
		$this->assertNotContains( Finding::TYPE_COST_INCREASE, $types );
	}

	public function test_ignores_a_cost_decrease(): void {
		$types = $this->types(
			MarginAnalyser::analyse(
				$this->product(
					array(
						'cost_minor'          => 1000,
						'previous_cost_minor' => 1300,
					)
				),
				$this->options()
			)
		);
		$this->assertNotContains( Finding::TYPE_COST_INCREASE, $types );
	}

	/* --- determinism ------------------------------------------------ */

	public function test_margin_analysis_is_deterministic(): void {
		$product = $this->product(
			array(
				'cost_minor'          => 2450,
				'previous_cost_minor' => 2000,
			)
		);
		$a       = MarginAnalyser::analyse( $product, $this->options() );
		$b       = MarginAnalyser::analyse( $product, $this->options() );

		$this->assertSame(
			array_map(
				static function ( Finding $f ) {
					return $f->to_array(); },
				$a
			),
			array_map(
				static function ( Finding $f ) {
					return $f->to_array(); },
				$b
			)
		);
	}

	// Tally - counts products, not findings.

	public function test_tally_counts_products_not_findings(): void {
		// This product produces TWO findings but is ONE unhealthy product.
		$product = $this->product(
			array(
				'cost_minor'          => 2450,
				'previous_cost_minor' => 2000,
			)
		);
		$totals  = MarginAnalyser::tally( $product, array(), 3000 );

		$this->assertSame( 1, $totals['products_seen'] );
		$this->assertSame( 1, $totals['assessed'] );
		$this->assertSame( 1, $totals['low'] );
	}

	public function test_tally_separates_the_reasons_a_product_could_not_be_assessed(): void {
		$totals = array();
		$totals = MarginAnalyser::tally( $this->product(), $totals, 3000 );
		$totals = MarginAnalyser::tally( $this->product( array( 'cost_minor' => null ) ), $totals, 3000 );
		$totals = MarginAnalyser::tally( $this->product( array( 'price_minor' => null ) ), $totals, 3000 );

		$this->assertSame( 3, $totals['products_seen'] );
		$this->assertSame( 1, $totals['assessed'] );
		$this->assertSame( 1, $totals['healthy'] );
		$this->assertSame( 1, $totals['missing_cost'] );
		$this->assertSame( 1, $totals['missing_price'] );
	}

	// Shipping rules.

	/**
	 * A normalised order.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function order( array $over = array() ): array {
		return array_merge(
			array(
				'id'                     => 1842,
				'number'                 => '1842',
				'total_minor'            => 6500,
				'shipping_charged_minor' => 799,
				'carrier_cost_minor'     => 1442,
				'shipping_method'        => 'Flat rate',
				'currency'               => 'EUR',
			),
			$over
		);
	}

	public function test_reports_a_shipping_loss_with_the_evidenced_difference(): void {
		// Order #1842 from the brief: EUR 7.99 charged against EUR 14.42
		// billed, on a EUR 65.00 order. The EUR 6.43 gap is 9.9% of the order,
		// which is a loss worth reporting but not a HIGH one - the high-loss
		// threshold is deliberately proportional, so a modest gap on a healthy
		// order does not outrank a gap that ate a third of a small one.
		$findings = ShippingAnalyser::analyse( $this->order() );

		$this->assertCount( 1, $findings );
		$this->assertSame( Finding::TYPE_SHIPPING_LOSS, $findings[0]->type );
		$this->assertSame( Finding::SEVERITY_MEDIUM, $findings[0]->severity );
		$this->assertSame( 643, $findings[0]->impact_minor );
		$this->assertSame( Finding::FINANCIAL_EVIDENCED_DIFFERENCE, $findings[0]->financial_type );
	}

	public function test_the_same_loss_on_a_small_order_is_a_high_loss(): void {
		// The identical EUR 6.43 gap, on a EUR 20.00 order, ate 32% of it.
		$findings = ShippingAnalyser::analyse( $this->order( array( 'total_minor' => 2000 ) ) );
		$this->assertSame( Finding::TYPE_HIGH_SHIPPING_LOSS, $findings[0]->type );
		$this->assertSame( Finding::SEVERITY_HIGH, $findings[0]->severity );
		// Same money, different priority - which is the whole point of judging
		// the loss against the order rather than against a fixed number.
		$this->assertSame( 643, $findings[0]->impact_minor );
	}

	public function test_a_small_loss_on_a_large_order_is_not_a_high_loss(): void {
		$findings = ShippingAnalyser::analyse( $this->order( array( 'total_minor' => 90000 ) ) );
		$this->assertSame( Finding::TYPE_SHIPPING_LOSS, $findings[0]->type );
		$this->assertSame( Finding::SEVERITY_MEDIUM, $findings[0]->severity );
	}

	public function test_records_profitable_shipping_as_information(): void {
		// A report that only lists failures reads as an accusation. The
		// merchant needs to see the orders that were fine too.
		$findings = ShippingAnalyser::analyse( $this->order( array( 'carrier_cost_minor' => 500 ) ) );
		$this->assertSame( Finding::TYPE_SHIPPING_PROFIT, $findings[0]->type );
		$this->assertSame( 299, $findings[0]->impact_minor );
		$this->assertSame( Finding::SEVERITY_INFO, $findings[0]->severity );
	}

	public function test_reports_a_missing_carrier_cost_with_no_amount(): void {
		$findings = ShippingAnalyser::analyse( $this->order( array( 'carrier_cost_minor' => null ) ) );

		$this->assertCount( 1, $findings );
		$this->assertSame( Finding::TYPE_MISSING_CARRIER_COST, $findings[0]->type );
		$this->assertNull( $findings[0]->impact_minor );
		$this->assertSame( Finding::FINANCIAL_MISSING_DATA, $findings[0]->financial_type );
	}

	public function test_says_nothing_about_a_digital_order(): void {
		// No shipping line sold AND no carrier cost: there is nothing to say.
		$findings = ShippingAnalyser::analyse(
			$this->order(
				array(
					'shipping_charged_minor' => null,
					'carrier_cost_minor'     => null,
				)
			)
		);
		$this->assertSame( array(), $findings );
	}

	public function test_caps_the_number_of_missing_rows_it_stores(): void {
		/*
		 * A store with 1,428 unpriced orders must not get 1,428 identical rows
		 * burying every real finding. A bounded sample is stored; the true
		 * total lives in the scan totals.
		 */
		$at_limit = ShippingAnalyser::analyse(
			$this->order( array( 'carrier_cost_minor' => null ) ),
			array( 'missing_emitted' => ShippingAnalyser::MISSING_SAMPLE_LIMIT )
		);
		$this->assertSame( array(), $at_limit );

		$under_limit = ShippingAnalyser::analyse(
			$this->order( array( 'carrier_cost_minor' => null ) ),
			array( 'missing_emitted' => 0 )
		);
		$this->assertCount( 1, $under_limit );
	}

	public function test_unmatched_rows_are_surfaced_not_dropped(): void {
		$findings = ShippingAnalyser::unmatched_findings(
			array(
				array(
					'order_reference' => '9999',
					'tracking_number' => 'JD9',
					'cost_minor'      => 1000,
					'row_index'       => 4,
				),
			)
		);
		$this->assertCount( 1, $findings );
		$this->assertSame( Finding::TYPE_UNMATCHED_CARRIER_ROW, $findings[0]->type );
		$this->assertNull( $findings[0]->impact_minor );
	}

	public function test_duplicates_are_reported_as_possible_not_confirmed(): void {
		$findings = ShippingAnalyser::duplicate_findings(
			array(
				array(
					'tracking_number'        => 'JD001',
					'row_indexes'            => array( 1, 2 ),
					'duplicate_amount_minor' => 1442,
				),
			)
		);

		$this->assertCount( 1, $findings );
		$this->assertSame( Finding::TYPE_POSSIBLE_DUPLICATE_CARRIER_ROW, $findings[0]->type );
		$this->assertSame( 1442, $findings[0]->impact_minor );
		// Two parcels can legitimately share a reference, so this is never
		// stated as certain. The plugin does not accuse carriers.
		$this->assertLessThan( 100, $findings[0]->confidence );
	}
}
