<?php
/**
 * Money arithmetic and parsing.
 *
 * Ported from the ProfitGuard TypeScript suite. Where a case here looks
 * oddly specific it is because it caught a real bug in the original.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProfitGuard\Core\Money;
use RuntimeException;

final class MoneyTest extends TestCase {

	// Platform.

	public function test_platform_is_64_bit(): void {
		Money::assert_platform();
		$this->assertSame( 8, PHP_INT_SIZE );
	}

	// mul_div_round.

	public function test_mul_div_round_computes_exactly(): void {
		$this->assertSame( 50, Money::mul_div_round( 100, 50, 100 ) );
		$this->assertSame( 2000, Money::mul_div_round( 1000, 10000, 5000 ) );
	}

	public function test_mul_div_round_rounds_half_away_from_zero(): void {
		// 5 / 2 = 2.5 -> 3, not 2.
		$this->assertSame( 3, Money::mul_div_round( 5, 1, 2 ) );
		// -5 / 2 = -2.5 -> -3, not -2. This is what "away from zero" means and
		// it is why the sign is stripped before rounding.
		$this->assertSame( -3, Money::mul_div_round( -5, 1, 2 ) );
	}

	public function test_mul_div_round_handles_a_negative_divisor(): void {
		$this->assertSame( -3, Money::mul_div_round( 5, 1, -2 ) );
		$this->assertSame( 3, Money::mul_div_round( -5, 1, -2 ) );
	}

	public function test_mul_div_round_is_exact_for_large_amounts(): void {
		// A EUR 10,000,000.00 amount scaled by basis points. The naive
		// transcription of the original doubles the numerator and would be far
		// closer to overflow here.
		$this->assertSame( 150000000, Money::mul_div_round( 1000000000, 1500, Money::BP_100 ) );
	}

	public function test_mul_div_round_refuses_to_divide_by_zero(): void {
		$this->expectException( InvalidArgumentException::class );
		Money::mul_div_round( 100, 1, 0 );
	}

	public function test_mul_div_round_throws_rather_than_silently_overflowing(): void {
		// PHP converts an overflowing product to a float instead of throwing,
		// which would turn an exact cent count into an approximation.
		$this->expectException( RuntimeException::class );
		Money::mul_div_round( PHP_INT_MAX, 2, 1 );
	}

	// mul_div_ceil.

	public function test_mul_div_ceil_rounds_toward_positive_infinity(): void {
		$this->assertSame( 3, Money::mul_div_ceil( 5, 1, 2 ) );
		$this->assertSame( 2, Money::mul_div_ceil( 4, 1, 2 ) );
		$this->assertSame( -2, Money::mul_div_ceil( -5, 1, 2 ) );
	}

	public function test_mul_div_ceil_requires_a_positive_divisor(): void {
		$this->expectException( InvalidArgumentException::class );
		Money::mul_div_ceil( 5, 1, 0 );
	}

	// Basis points.

	public function test_apply_bp_takes_a_percentage_of_an_amount(): void {
		// 14.5% of EUR 100.00
		$this->assertSame( 1450, Money::apply_bp( 10000, 1450 ) );
	}

	public function test_bp_conversions_round_trip(): void {
		$this->assertSame( 3000, Money::to_bp( 30.0 ) );
		$this->assertSame( 1450, Money::to_bp( 14.5 ) );
		$this->assertSame( 30.0, Money::bp_to_percent( 3000 ) );
	}

	// parse_decimal_to_minor - the strict, machine-format parser.

	public function test_parse_decimal_handles_woocommerce_prices(): void {
		$this->assertSame( 2999, Money::parse_decimal_to_minor( '29.99' ) );
		$this->assertSame( 100000, Money::parse_decimal_to_minor( '1000.00' ) );
		$this->assertSame( 0, Money::parse_decimal_to_minor( '0' ) );
		$this->assertSame( 500, Money::parse_decimal_to_minor( '5' ) );
	}

	public function test_parse_decimal_reads_one_thousandth_of_a_unit_as_a_decimal(): void {
		// THE reason this parser exists separately. "1.000" from WooCommerce is
		// one unit; the spreadsheet parser below reads the same string as one
		// thousand. Sending WooCommerce prices through that parser would
		// multiply some of them by a thousand.
		$this->assertSame( 100, Money::parse_decimal_to_minor( '1.000' ) );
		$this->assertSame( 100000, Money::parse_amount_to_minor( '1.000' ) );
	}

	public function test_parse_decimal_rounds_a_third_decimal_half_away_from_zero(): void {
		$this->assertSame( 1000, Money::parse_decimal_to_minor( '9.995' ) );
		$this->assertSame( 999, Money::parse_decimal_to_minor( '9.994' ) );
	}

	public function test_parse_decimal_avoids_float_error(): void {
		// 29.99 * 100 is 2998.9999999999995 in IEEE-754. Working on the string
		// is what keeps this exact.
		$this->assertSame( 2999, Money::parse_decimal_to_minor( '29.99' ) );
		$this->assertSame( 1070, Money::parse_decimal_to_minor( '10.70' ) );
	}

	public function test_parse_decimal_handles_negatives(): void {
		$this->assertSame( -2999, Money::parse_decimal_to_minor( '-29.99' ) );
	}

	public function test_parse_decimal_rejects_anything_that_is_not_a_plain_decimal(): void {
		$this->assertNull( Money::parse_decimal_to_minor( '' ) );
		$this->assertNull( Money::parse_decimal_to_minor( '1,234.56' ) );
		$this->assertNull( Money::parse_decimal_to_minor( 'EUR 10' ) );
		$this->assertNull( Money::parse_decimal_to_minor( 'abc' ) );
		$this->assertNull( Money::parse_decimal_to_minor( null ) );
	}

	// parse_amount_to_minor - the human/spreadsheet parser.

	public function test_parse_amount_handles_us_convention(): void {
		$this->assertSame( 123456, Money::parse_amount_to_minor( '1,234.56' ) );
		$this->assertSame( 2999, Money::parse_amount_to_minor( '29.99' ) );
	}

	public function test_parse_amount_handles_eu_convention(): void {
		$this->assertSame( 123456, Money::parse_amount_to_minor( '1.234,56' ) );
		$this->assertSame( 2999, Money::parse_amount_to_minor( '29,99' ) );
	}

	public function test_parse_amount_treats_a_lone_three_digit_group_as_thousands(): void {
		$this->assertSame( 100000, Money::parse_amount_to_minor( '1.000' ) );
		$this->assertSame( 100000, Money::parse_amount_to_minor( '1,000' ) );
	}

	public function test_a_leading_zero_settles_the_thousands_ambiguity(): void {
		/*
		 * The single most valuable edge case in the parser. Supplier cost lists
		 * routinely quote unit costs to three or four decimals. Without this
		 * exception "0.750" parses as 750.00 instead of 0.75, and every margin
		 * computed from that cost is wrong by a factor of a thousand.
		 */
		$this->assertSame( 75, Money::parse_amount_to_minor( '0.750' ) );
		$this->assertSame( 81, Money::parse_amount_to_minor( '0,812' ) );
		$this->assertSame( 1, Money::parse_amount_to_minor( '0.005' ) );
	}

	public function test_parse_amount_strips_currency_symbols_and_codes(): void {
		$this->assertSame( 2999, Money::parse_amount_to_minor( '€29.99' ) );
		$this->assertSame( 2999, Money::parse_amount_to_minor( 'EUR 29,99' ) );
		$this->assertSame( 2999, Money::parse_amount_to_minor( '29.99 USD' ) );
	}

	public function test_parse_amount_strips_non_breaking_space_thousands_separators(): void {
		// Exported spreadsheets are full of these and they are invisible.
		$this->assertSame( 123456, Money::parse_amount_to_minor( "1\u{00A0}234,56" ) );
	}

	public function test_parse_amount_handles_accounting_negatives(): void {
		$this->assertSame( -2999, Money::parse_amount_to_minor( '(29.99)' ) );
		$this->assertSame( -2999, Money::parse_amount_to_minor( '-29.99' ) );
		$this->assertSame( -2999, Money::parse_amount_to_minor( '29.99-' ) );
	}

	public function test_parse_amount_returns_null_rather_than_guessing(): void {
		$this->assertNull( Money::parse_amount_to_minor( '' ) );
		$this->assertNull( Money::parse_amount_to_minor( '   ' ) );
		$this->assertNull( Money::parse_amount_to_minor( 'n/a' ) );
		$this->assertNull( Money::parse_amount_to_minor( 'call us' ) );
		$this->assertNull( Money::parse_amount_to_minor( '--' ) );
		$this->assertNull( Money::parse_amount_to_minor( null ) );
	}

	public function test_parse_amount_accepts_numeric_types(): void {
		$this->assertSame( 2999, Money::parse_amount_to_minor( 29.99 ) );
		$this->assertSame( 3000, Money::parse_amount_to_minor( 30 ) );
	}

	// Percentages.

	public function test_parse_percent_to_bp(): void {
		$this->assertSame( 1450, Money::parse_percent_to_bp( '14.5%' ) );
		$this->assertSame( 1450, Money::parse_percent_to_bp( '14,5 %' ) );
		$this->assertSame( 3000, Money::parse_percent_to_bp( 30 ) );
		$this->assertNull( Money::parse_percent_to_bp( 'x' ) );
	}

	// Formatting.

	public function test_format_minor_groups_thousands(): void {
		$this->assertSame( '1,234.56', Money::format_minor( 123456 ) );
		$this->assertSame( '1.234,56', Money::format_minor( 123456, ',', '.' ) );
		$this->assertSame( '0.99', Money::format_minor( 99 ) );
		$this->assertSame( '10.00', Money::format_minor( 1000 ) );
	}

	public function test_format_minor_handles_millions_and_negatives(): void {
		$this->assertSame( '1,000,000.00', Money::format_minor( 100000000 ) );
		$this->assertSame( '-6.43', Money::format_minor( -643 ) );
	}

	public function test_format_minor_renders_null_as_a_dash_never_zero(): void {
		// The core honesty rule of the product, enforced at the last step
		// before a human reads it.
		$this->assertSame( '—', Money::format_minor( null ) );
		$this->assertNotSame( '0.00', Money::format_minor( null ) );
	}

	public function test_format_percent_bp(): void {
		$this->assertSame( '30%', Money::format_percent_bp( 3000 ) );
		$this->assertSame( '18.3%', Money::format_percent_bp( 1830 ) );
		$this->assertSame( '14.55%', Money::format_percent_bp( 1455 ) );
		$this->assertSame( '-20%', Money::format_percent_bp( -2000 ) );
		$this->assertSame( '—', Money::format_percent_bp( null ) );
	}
}
