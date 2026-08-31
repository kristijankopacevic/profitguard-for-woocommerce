<?php
/**
 * Shipping profitability arithmetic and duplicate detection.
 *
 * The recurring theme: an unknown carrier cost produces MISSING_CARRIER_COST,
 * never a loss of zero and never an estimate presented as a fact.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProfitGuard\Core\Shipping;

final class ShippingTest extends TestCase {

	public function test_reproduces_the_worked_example_from_the_specification(): void {
		// Order #1842: customer paid EUR 7.99, carrier charged EUR 14.42.
		$result = Shipping::evaluate( 799, 1442, 6500 );

		$this->assertSame( -643, $result['profit_minor'] );
		$this->assertSame( 643, $result['loss_minor'] );
		$this->assertSame( 5541, $result['recovery_bp'] );
	}

	public function test_reports_a_profit_when_the_customer_paid_more(): void {
		$result = Shipping::evaluate( 1500, 1000, 6500 );
		$this->assertSame( Shipping::RESULT_PROFIT, $result['result'] );
		$this->assertSame( 500, $result['profit_minor'] );
	}

	public function test_reports_break_even_exactly(): void {
		$result = Shipping::evaluate( 1000, 1000, 6500 );
		$this->assertSame( Shipping::RESULT_BREAK_EVEN, $result['result'] );
		$this->assertSame( 0, $result['profit_minor'] );
		$this->assertSame( 10000, $result['recovery_bp'] );
	}

	public function test_says_missing_never_zero_when_no_carrier_cost_was_imported(): void {
		/*
		 * The honest answer for a store that has installed the plugin but not
		 * imported a carrier invoice. WooCommerce cannot know what the carrier
		 * billed, and guessing here would fabricate every downstream number.
		 */
		$result = Shipping::evaluate( 799, null, 6500 );

		$this->assertSame( Shipping::RESULT_MISSING_COST, $result['result'] );
		$this->assertNull( $result['profit_minor'] );
		$this->assertNull( $result['loss_minor'] );
		$this->assertNotSame( 0, $result['profit_minor'] );
		$this->assertNull( $result['recovery_bp'] );
	}

	public function test_free_shipping_that_cost_money_is_a_loss(): void {
		$result = Shipping::evaluate( 0, 1442, 6500 );
		$this->assertSame( 1442, $result['loss_minor'] );
		$this->assertSame( 0, $result['recovery_bp'] );
	}

	public function test_an_order_with_no_shipping_line_still_counts_the_absorbed_cost(): void {
		// No shipping line sold, but the carrier did bill: the merchant paid
		// all of it, and that is a real loss rather than an unknown.
		$result = Shipping::evaluate( null, 1442, 6500 );
		$this->assertSame( 1442, $result['loss_minor'] );
		$this->assertNotSame( Shipping::RESULT_MISSING_COST, $result['result'] );
	}

	public function test_a_high_loss_is_judged_against_the_order_value(): void {
		/*
		 * Losing EUR 6.43 on a EUR 20 order eats a third of it; losing the same
		 * EUR 6.43 on a EUR 900 order is a rounding error. An absolute
		 * threshold would rank those identically and send the merchant after
		 * the wrong one.
		 */
		$small = Shipping::evaluate( 799, 1442, 2000 );
		$large = Shipping::evaluate( 799, 1442, 90000 );

		$this->assertSame( Shipping::RESULT_HIGH_LOSS, $small['result'] );
		$this->assertSame( Shipping::RESULT_LOSS, $large['result'] );
	}

	public function test_falls_back_to_an_absolute_threshold_without_an_order_value(): void {
		$big   = Shipping::evaluate( 0, 5000, null );
		$small = Shipping::evaluate( 0, 500, null );
		$this->assertSame( Shipping::RESULT_HIGH_LOSS, $big['result'] );
		$this->assertSame( Shipping::RESULT_LOSS, $small['result'] );
	}

	public function test_zero_carrier_cost_is_not_a_division_by_zero(): void {
		$result = Shipping::evaluate( 500, 0, 6500 );
		$this->assertSame( Shipping::RESULT_PROFIT, $result['result'] );
		$this->assertNull( $result['recovery_bp'] );
	}

	/* ================================================================ *
	 * Summary
	 * ================================================================ */

	public function test_counts_orders_seen_separately_from_orders_it_could_price(): void {
		$summary = Shipping::summarise(
			array(
				array( 'shipping_charged_minor' => 799, 'carrier_cost_minor' => 1442, 'order_total_minor' => 6500 ),
				array( 'shipping_charged_minor' => 799, 'carrier_cost_minor' => null, 'order_total_minor' => 6500 ),
				array( 'shipping_charged_minor' => 799, 'carrier_cost_minor' => null, 'order_total_minor' => 6500 ),
			)
		);

		$this->assertSame( 3, $summary['orders_seen'] );
		$this->assertSame( 1, $summary['orders_assessed'] );
		$this->assertSame( 1, $summary['orders_at_loss'] );
	}

	public function test_sums_losses_for_out_of_pocket_but_nets_for_the_true_position(): void {
		$summary = Shipping::summarise(
			array(
				array( 'shipping_charged_minor' => 0, 'carrier_cost_minor' => 1000, 'order_total_minor' => 5000 ),
				array( 'shipping_charged_minor' => 2000, 'carrier_cost_minor' => 1000, 'order_total_minor' => 5000 ),
			)
		);

		$this->assertSame( 1000, $summary['total_loss_minor'] );
		$this->assertSame( 0, $summary['net_minor'] );
	}

	public function test_totals_are_null_not_zero_when_nothing_could_be_priced(): void {
		$summary = Shipping::summarise(
			array( array( 'shipping_charged_minor' => 799, 'carrier_cost_minor' => null, 'order_total_minor' => 6500 ) )
		);

		$this->assertSame( 0, $summary['orders_assessed'] );
		$this->assertNull( $summary['total_loss_minor'] );
		$this->assertNull( $summary['net_minor'] );
		$this->assertNull( $summary['shipping_charged_minor'] );
		$this->assertNull( $summary['carrier_cost_minor'] );
	}

	public function test_totals_cover_the_assessed_subset_only(): void {
		// Including the unpriced order's shipping revenue against a cost of
		// nothing would inflate the recovery ratio, and with it the shipping
		// score, on exactly the stores with the least evidence.
		$summary = Shipping::summarise(
			array(
				array( 'shipping_charged_minor' => 800, 'carrier_cost_minor' => 1000, 'order_total_minor' => 5000 ),
				array( 'shipping_charged_minor' => 9999, 'carrier_cost_minor' => null, 'order_total_minor' => 5000 ),
			)
		);

		$this->assertSame( 800, $summary['shipping_charged_minor'] );
		$this->assertSame( 1000, $summary['carrier_cost_minor'] );
	}

	/* ================================================================ *
	 * Duplicates
	 * ================================================================ */

	public function test_finds_one_tracking_number_billed_twice(): void {
		$found = Shipping::detect_duplicates(
			array(
				array( 'tracking_number' => 'JD001', 'carrier_cost_minor' => 1442, 'row_index' => 1 ),
				array( 'tracking_number' => 'JD001', 'carrier_cost_minor' => 1442, 'row_index' => 2 ),
				array( 'tracking_number' => 'JD002', 'carrier_cost_minor' => 900, 'row_index' => 3 ),
			)
		);

		$this->assertCount( 1, $found );
		$this->assertSame( 'JD001', $found[0]['tracking_number'] );
		// Everything after the first copy is recoverable.
		$this->assertSame( 1442, $found[0]['duplicate_amount_minor'] );
		$this->assertSame( array( 1, 2 ), $found[0]['row_indexes'] );
	}

	public function test_matches_tracking_numbers_regardless_of_case_and_padding(): void {
		$found = Shipping::detect_duplicates(
			array(
				array( 'tracking_number' => ' jd001 ', 'carrier_cost_minor' => 100, 'row_index' => 1 ),
				array( 'tracking_number' => 'JD001', 'carrier_cost_minor' => 100, 'row_index' => 2 ),
			)
		);
		$this->assertCount( 1, $found );
	}

	public function test_does_not_group_every_untracked_row_together(): void {
		// Grouping on the empty string would report all three as one enormous
		// duplicate - the classic bug in this kind of detector.
		$found = Shipping::detect_duplicates(
			array(
				array( 'tracking_number' => null, 'carrier_cost_minor' => 100, 'row_index' => 1 ),
				array( 'tracking_number' => '', 'carrier_cost_minor' => 100, 'row_index' => 2 ),
				array( 'tracking_number' => '   ', 'carrier_cost_minor' => 100, 'row_index' => 3 ),
			)
		);
		$this->assertCount( 0, $found );
	}

	public function test_reports_a_duplicate_without_an_amount_when_the_copies_are_unpriced(): void {
		$found = Shipping::detect_duplicates(
			array(
				array( 'tracking_number' => 'X1', 'carrier_cost_minor' => null, 'row_index' => 1 ),
				array( 'tracking_number' => 'X1', 'carrier_cost_minor' => null, 'row_index' => 2 ),
			)
		);
		$this->assertCount( 1, $found );
		$this->assertNull( $found[0]['duplicate_amount_minor'] );
	}

	public function test_duplicate_detection_is_deterministic(): void {
		$rows = array(
			array( 'tracking_number' => 'B2', 'carrier_cost_minor' => 100, 'row_index' => 1 ),
			array( 'tracking_number' => 'A1', 'carrier_cost_minor' => 100, 'row_index' => 2 ),
			array( 'tracking_number' => 'B2', 'carrier_cost_minor' => 100, 'row_index' => 3 ),
			array( 'tracking_number' => 'A1', 'carrier_cost_minor' => 100, 'row_index' => 4 ),
		);
		$this->assertSame(
			wp_json_encode_stub( Shipping::detect_duplicates( $rows ) ),
			wp_json_encode_stub( Shipping::detect_duplicates( $rows ) )
		);
		$this->assertSame( 'A1', Shipping::detect_duplicates( $rows )[0]['tracking_number'] );
	}
}

/**
 * Local JSON helper so the test file has no WordPress dependency.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_stub( $value ): string {
	return (string) json_encode( $value );
}
