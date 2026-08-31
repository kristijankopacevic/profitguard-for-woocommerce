<?php
/**
 * Import row validation.
 *
 * The governing rule under test: a row is either VALID or REJECTED WITH A
 * REASON, never silently guessed and never silently dropped. An import that
 * reports "300 products updated" while quietly discarding the thirty that
 * mattered is worse than one that fails outright.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProfitGuard\Import\Mapper;

final class MapperTest extends TestCase {

	/**
	 * The usual cost-file mapping.
	 *
	 * @return array<string, int>
	 */
	private function cost_mapping(): array {
		return array(
			'sku'      => 0,
			'cost'     => 1,
			'currency' => 2,
			'name'     => 3,
		);
	}

	/**
	 * The usual carrier-file mapping.
	 *
	 * @return array<string, int>
	 */
	private function carrier_mapping(): array {
		return array(
			'order'       => 0,
			'tracking'    => 1,
			'carrier'     => 2,
			'actual_cost' => 3,
			'currency'    => 4,
		);
	}

	// Product costs.

	public function test_accepts_a_valid_row(): void {
		$result = Mapper::map_cost_rows(
			array( array( 'WC-001', '13.00', 'EUR', 'Wireless Charger' ) ),
			$this->cost_mapping(),
			'EUR'
		);

		$this->assertCount( 1, $result['valid'] );
		$this->assertSame( 'WC-001', $result['valid'][0]['sku'] );
		$this->assertSame( 1300, $result['valid'][0]['cost_minor'] );
		$this->assertSame( array(), $result['rejected'] );
	}

	public function test_reports_the_line_number_the_merchant_sees(): void {
		// Header is line 1, so the first data row is line 2.
		$result = Mapper::map_cost_rows(
			array(
				array( 'WC-001', '13.00', '', '' ),
				array( '', '13.00', '', '' ),
			),
			$this->cost_mapping(),
			'EUR'
		);

		$this->assertSame( 2, $result['valid'][0]['row'] );
		$this->assertSame( 3, $result['rejected'][0]['row'] );
	}

	public function test_accepts_european_number_formatting(): void {
		$result = Mapper::map_cost_rows(
			array(
				array( 'A', '1.234,56', '', '' ),
				array( 'B', '13,00', '', '' ),
				array( 'C', '€12,50', '', '' ),
			),
			$this->cost_mapping(),
			'EUR'
		);

		$this->assertCount( 3, $result['valid'] );
		$this->assertSame( 123456, $result['valid'][0]['cost_minor'] );
		$this->assertSame( 1300, $result['valid'][1]['cost_minor'] );
		$this->assertSame( 1250, $result['valid'][2]['cost_minor'] );
	}

	public function test_accepts_a_sub_unit_cost_to_three_decimals(): void {
		// Supplier lists routinely quote unit costs this way. Without the
		// leading-zero exception in the parser this becomes 750.00.
		$result = Mapper::map_cost_rows(
			array( array( 'A', '0.750', '', '' ) ),
			$this->cost_mapping(),
			'EUR'
		);
		$this->assertSame( 75, $result['valid'][0]['cost_minor'] );
	}

	public function test_rejects_a_row_with_no_sku(): void {
		$result = Mapper::map_cost_rows(
			array( array( '', '13.00', '', 'Nameless' ) ),
			$this->cost_mapping(),
			'EUR'
		);
		$this->assertSame( array(), $result['valid'] );
		$this->assertSame( Mapper::REASON_NO_SKU, $result['rejected'][0]['reason'] );
	}

	public function test_rejects_a_row_with_no_cost(): void {
		$result = Mapper::map_cost_rows(
			array( array( 'WC-001', '', '', '' ) ),
			$this->cost_mapping(),
			'EUR'
		);
		$this->assertSame( Mapper::REASON_NO_COST, $result['rejected'][0]['reason'] );
	}

	public function test_rejects_an_unreadable_cost_rather_than_treating_it_as_zero(): void {
		$result = Mapper::map_cost_rows(
			array(
				array( 'A', 'n/a', '', '' ),
				array( 'B', 'call us', '', '' ),
				array( 'C', '--', '', '' ),
			),
			$this->cost_mapping(),
			'EUR'
		);

		$this->assertSame( array(), $result['valid'] );
		$this->assertCount( 3, $result['rejected'] );
		foreach ( $result['rejected'] as $rejected ) {
			$this->assertSame( Mapper::REASON_BAD_COST, $rejected['reason'] );
		}
	}

	public function test_rejects_a_negative_cost(): void {
		$result = Mapper::map_cost_rows(
			array( array( 'WC-001', '-13.00', '', '' ) ),
			$this->cost_mapping(),
			'EUR'
		);
		$this->assertSame( Mapper::REASON_NEGATIVE_COST, $result['rejected'][0]['reason'] );
	}

	public function test_rejects_a_row_in_another_currency(): void {
		/*
		 * WooCommerce has exactly one store currency. A cost in another one
		 * cannot be compared with a price without an exchange rate ProfitGuard
		 * does not have, so the row is refused rather than silently treated as
		 * if it were local - which would understate or overstate every margin
		 * derived from it.
		 */
		$result = Mapper::map_cost_rows(
			array( array( 'WC-001', '13.00', 'USD', '' ) ),
			$this->cost_mapping(),
			'EUR'
		);

		$this->assertSame( array(), $result['valid'] );
		$this->assertSame( Mapper::REASON_CURRENCY_MISMATCH, $result['rejected'][0]['reason'] );
		$this->assertSame( 'USD', $result['rejected'][0]['value'] );
	}

	public function test_accepts_a_matching_currency_in_any_case(): void {
		$result = Mapper::map_cost_rows(
			array( array( 'WC-001', '13.00', 'eur', '' ) ),
			$this->cost_mapping(),
			'EUR'
		);
		$this->assertCount( 1, $result['valid'] );
	}

	public function test_accepts_a_row_with_no_currency_column(): void {
		// A file with no currency column is the common case and is assumed to
		// be in the store's currency.
		$result = Mapper::map_cost_rows(
			array( array( 'WC-001', '13.00' ) ),
			array(
				'sku'  => 0,
				'cost' => 1,
			),
			'EUR'
		);
		$this->assertCount( 1, $result['valid'] );
	}

	public function test_rejects_a_duplicate_sku_rather_than_picking_one(): void {
		/*
		 * A repeated SKU is ambiguous: we cannot know which cost was meant, and
		 * taking the last one silently is a coin flip on the merchant's margin.
		 */
		$result = Mapper::map_cost_rows(
			array(
				array( 'WC-001', '13.00', '', '' ),
				array( 'WC-001', '19.00', '', '' ),
			),
			$this->cost_mapping(),
			'EUR'
		);

		$this->assertCount( 1, $result['valid'] );
		$this->assertSame( 1300, $result['valid'][0]['cost_minor'] );
		$this->assertSame( Mapper::REASON_DUPLICATE_SKU, $result['rejected'][0]['reason'] );
	}

	public function test_treats_a_duplicate_sku_case_insensitively(): void {
		$result = Mapper::map_cost_rows(
			array(
				array( 'wc-001', '13.00', '', '' ),
				array( 'WC-001', '19.00', '', '' ),
			),
			$this->cost_mapping(),
			'EUR'
		);
		$this->assertCount( 1, $result['valid'] );
		$this->assertCount( 1, $result['rejected'] );
	}

	public function test_tolerates_a_short_row_without_a_php_notice(): void {
		// A ragged CSV is normal. A missing trailing column must be an absent
		// value, not an undefined-index warning.
		$result = Mapper::map_cost_rows(
			array( array( 'WC-001' ) ),
			$this->cost_mapping(),
			'EUR'
		);
		$this->assertSame( Mapper::REASON_NO_COST, $result['rejected'][0]['reason'] );
	}

	public function test_a_malicious_cell_is_data_not_a_formula(): void {
		/*
		 * A crafted SKU is only ever compared and stored as a string here, and
		 * the export layer escapes it separately. What matters is that it does
		 * not blow up validation or smuggle a number through.
		 */
		$result = Mapper::map_cost_rows(
			array(
				array( '=HYPERLINK("http://evil")', '13.00', '', '' ),
				array( 'B', '=1+1', '', '' ),
			),
			$this->cost_mapping(),
			'EUR'
		);

		$this->assertCount( 1, $result['valid'] );
		$this->assertSame( '=HYPERLINK("http://evil")', $result['valid'][0]['sku'] );
		// "=1+1" is not a number and must be refused, not evaluated.
		$this->assertSame( Mapper::REASON_BAD_COST, $result['rejected'][0]['reason'] );
	}

	// Carrier costs.

	public function test_accepts_a_valid_carrier_row(): void {
		$result = Mapper::map_carrier_rows(
			array( array( '1842', 'JD001', 'DHL', '14.42', 'EUR' ) ),
			$this->carrier_mapping(),
			'EUR'
		);

		$this->assertCount( 1, $result['valid'] );
		$this->assertSame( '1842', $result['valid'][0]['order_reference'] );
		$this->assertSame( 1442, $result['valid'][0]['cost_minor'] );
		$this->assertSame( 'DHL', $result['valid'][0]['carrier'] );
	}

	public function test_rejects_a_carrier_row_with_nothing_to_match_on(): void {
		$result = Mapper::map_carrier_rows(
			array( array( '', '', 'DHL', '14.42', 'EUR' ) ),
			$this->carrier_mapping(),
			'EUR'
		);
		$this->assertSame( Mapper::REASON_NO_ORDER_REF, $result['rejected'][0]['reason'] );
	}

	public function test_accepts_a_carrier_row_identified_only_by_tracking(): void {
		$result = Mapper::map_carrier_rows(
			array( array( '', 'JD001', 'DHL', '14.42', 'EUR' ) ),
			$this->carrier_mapping(),
			'EUR'
		);
		$this->assertCount( 1, $result['valid'] );
	}

	public function test_rejects_a_carrier_row_with_no_amount(): void {
		// A line with no amount tells us nothing about money and would only add
		// a phantom zero-cost shipment.
		$result = Mapper::map_carrier_rows(
			array( array( '1842', 'JD001', 'DHL', '', 'EUR' ) ),
			$this->carrier_mapping(),
			'EUR'
		);
		$this->assertSame( Mapper::REASON_NO_AMOUNT, $result['rejected'][0]['reason'] );
	}

	public function test_rejects_a_carrier_row_in_another_currency(): void {
		$result = Mapper::map_carrier_rows(
			array( array( '1842', 'JD001', 'DHL', '14.42', 'GBP' ) ),
			$this->carrier_mapping(),
			'EUR'
		);
		$this->assertSame( Mapper::REASON_CURRENCY_MISMATCH, $result['rejected'][0]['reason'] );
	}

	// Dates.

	public function test_reads_an_iso_date(): void {
		$this->assertSame( '2026-08-31', Mapper::normalise_date( '2026-08-31' ) );
		$this->assertSame( '2026-08-31', Mapper::normalise_date( '2026-08-31 14:02:00' ) );
	}

	public function test_reads_a_day_first_date(): void {
		$this->assertSame( '2026-08-31', Mapper::normalise_date( '31/08/2026' ) );
		$this->assertSame( '2026-08-31', Mapper::normalise_date( '31.08.2026' ) );
	}

	public function test_returns_null_for_an_unreadable_date_rather_than_guessing(): void {
		// The date is informational - nothing is matched on it - so a wrong
		// date is worse than no date.
		$this->assertNull( Mapper::normalise_date( 'last Tuesday' ) );
		$this->assertNull( Mapper::normalise_date( '' ) );
		$this->assertNull( Mapper::normalise_date( '31/13/2026' ) );
	}

	// Duplicate-import protection.

	public function test_the_same_row_hashes_the_same(): void {
		$row = array(
			'order_reference'  => '1842',
			'tracking_number'  => 'JD001',
			'carrier'          => 'DHL',
			'cost_minor'       => 1442,
			'surcharge_minor'  => null,
			'adjustment_minor' => null,
			'shipped_on'       => '2026-08-31',
		);
		$this->assertSame( Mapper::carrier_row_hash( $row ), Mapper::carrier_row_hash( $row ) );
	}

	public function test_the_hash_ignores_case_and_padding(): void {
		// Re-exporting the same invoice with different capitalisation must not
		// double a merchant's costs.
		$a = array(
			'order_reference' => '1842',
			'tracking_number' => 'jd001 ',
			'carrier'         => 'dhl',
			'cost_minor'      => 1442,
		);
		$b = array(
			'order_reference' => '1842',
			'tracking_number' => 'JD001',
			'carrier'         => 'DHL',
			'cost_minor'      => 1442,
		);
		$this->assertSame( Mapper::carrier_row_hash( $a ), Mapper::carrier_row_hash( $b ) );
	}

	public function test_a_genuinely_different_amount_hashes_differently(): void {
		/*
		 * One shipment can legitimately appear twice - a base charge and a
		 * later adjustment share a tracking number. Including the amount in
		 * the hash means the second line is still imported.
		 */
		$a = array(
			'order_reference' => '1842',
			'tracking_number' => 'JD001',
			'cost_minor'      => 1442,
		);
		$b = array(
			'order_reference' => '1842',
			'tracking_number' => 'JD001',
			'cost_minor'      => 200,
		);
		$this->assertNotSame( Mapper::carrier_row_hash( $a ), Mapper::carrier_row_hash( $b ) );
	}
}
