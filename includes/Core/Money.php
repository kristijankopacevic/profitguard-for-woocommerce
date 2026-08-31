<?php
/**
 * Money and exact arithmetic.
 *
 * Ported from the ProfitGuard TypeScript core (lib/core/money.ts) formula for
 * formula. The behaviour, including every edge case, is intended to be
 * identical; tests/Unit/MoneyTest.php mirrors tests/margin.test.ts.
 *
 * INVARIANTS (every comparison number in the plugin depends on these):
 *  1. Every monetary amount is an INTEGER number of minor units (cents).
 *     Floating-point currency is never stored, compared, or summed.
 *  2. Percentages are INTEGER basis points (1 bp = 0.01%). 14.5% === 1450.
 *  3. All scaling goes through mul_div_round, which is written to avoid
 *     intermediate overflow rather than to be short.
 *  4. Rounding is HALF-AWAY-FROM-ZERO at the individual amount level.
 *
 * WHY NOT bcmath. The bcmath extension is not present on a default PHP build
 * and is not guaranteed on shared hosting, so depending on it would make the
 * plugin fail to work for an unknowable share of merchants. Native 64-bit
 * integers are available everywhere PHP 7.4+ runs and are exact, which is what
 * the arithmetic actually needs.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Core;

use InvalidArgumentException;
use RuntimeException;

defined( 'ABSPATH' ) || defined( 'PROFITGUARD_TESTING' ) || exit;

/**
 * Integer money helpers. Pure PHP: no WordPress, no WooCommerce, no globals.
 */
final class Money {

	/**
	 * 100% in basis points.
	 */
	public const BP_100 = 10000;

	/**
	 * Guard against a 32-bit PHP build.
	 *
	 * On a 32-bit build PHP_INT_MAX is 2147483647, which is only about EUR 21
	 * million in cents. A store total could exceed that and the overflow would
	 * be silent, so it is checked once rather than hoped for.
	 *
	 * @throws RuntimeException When integers are too narrow for money maths.
	 */
	public static function assert_platform(): void {
		if ( PHP_INT_SIZE < 8 ) {
			throw new RuntimeException( 'ProfitGuard requires a 64-bit PHP build for exact money arithmetic.' );
		}
	}

	/**
	 * Multiply two integers, refusing to return a silently wrong answer.
	 *
	 * PHP does not throw on integer overflow: it converts the result to a
	 * float, which loses precision above 2^53 and turns an exact cent count
	 * into an approximation. Checking is_int() is how that is caught.
	 *
	 * @param int $a Left operand.
	 * @param int $b Right operand.
	 * @return int Exact product.
	 * @throws RuntimeException When the product overflows the integer range.
	 */
	private static function mul_exact( int $a, int $b ): int {
		$product = $a * $b;
		if ( ! is_int( $product ) ) {
			throw new RuntimeException( 'ProfitGuard money overflow: the amounts involved are too large.' );
		}
		return $product;
	}

	/**
	 * Exact (a * b) / d with half-away-from-zero rounding.
	 *
	 * Deliberately NOT written as `intdiv( $a * $b * 2 + $d, $d * 2 )`, which is
	 * the obvious transcription of the TypeScript original. That version doubles
	 * the numerator and so halves the range before overflow. Taking the
	 * quotient and remainder first keeps every intermediate value no larger than
	 * the product itself.
	 *
	 * intdiv() truncates toward zero, so the sign is stripped up front and
	 * re-applied at the end; rounding the magnitude is what makes it
	 * half-AWAY-from-zero rather than half-up.
	 *
	 * @param int $a Left operand.
	 * @param int $b Right operand.
	 * @param int $d Divisor.
	 * @return int Rounded result.
	 * @throws InvalidArgumentException When dividing by zero.
	 */
	public static function mul_div_round( int $a, int $b, int $d ): int {
		if ( 0 === $d ) {
			throw new InvalidArgumentException( 'mul_div_round: division by zero' );
		}

		$product  = self::mul_exact( $a, $b );
		$negative = ( $product < 0 ) !== ( $d < 0 );

		$abs_product = self::abs_int( $product );
		$abs_divisor = self::abs_int( $d );

		$quotient  = intdiv( $abs_product, $abs_divisor );
		$remainder = $abs_product % $abs_divisor;

		// Round the magnitude up when the remainder is at least half.
		if ( $remainder * 2 >= $abs_divisor ) {
			++$quotient;
		}

		return $negative ? -$quotient : $quotient;
	}

	/**
	 * Exact ceil((a * b) / d), toward positive infinity.
	 *
	 * Used only by the recommended-price calculation, where rounding to nearest
	 * can land a cent below the target and make the recommendation
	 * self-defeating. See Margin::recommended_price_minor().
	 *
	 * @param int $a Left operand.
	 * @param int $b Right operand.
	 * @param int $d Divisor. Must be positive.
	 * @return int Result rounded toward positive infinity.
	 * @throws InvalidArgumentException When the divisor is zero or negative.
	 */
	public static function mul_div_ceil( int $a, int $b, int $d ): int {
		if ( $d <= 0 ) {
			throw new InvalidArgumentException( 'mul_div_ceil: divisor must be positive' );
		}

		$product = self::mul_exact( $a, $b );

		if ( $product >= 0 ) {
			$quotient = intdiv( $product, $d );
			return 0 === $product % $d ? $quotient : $quotient + 1;
		}

		// intdiv truncates toward zero, which for a negative numerator is
		// already the ceiling.
		return -intdiv( -$product, $d );
	}

	/**
	 * Absolute value that cannot overflow.
	 *
	 * abs( PHP_INT_MIN ) is not representable and PHP returns a float for it.
	 *
	 * @param int $n Value.
	 * @return int Absolute value.
	 * @throws RuntimeException When the value has no representable magnitude.
	 */
	private static function abs_int( int $n ): int {
		if ( PHP_INT_MIN === $n ) {
			throw new RuntimeException( 'ProfitGuard money overflow: value out of range.' );
		}
		return $n < 0 ? -$n : $n;
	}

	/**
	 * A percentage of a base amount. `$bp` is basis points (1450 === 14.5%).
	 *
	 * @param int $base_minor Base amount in minor units.
	 * @param int $bp         Basis points.
	 * @return int Result in minor units.
	 */
	public static function apply_bp( int $base_minor, int $bp ): int {
		return self::mul_div_round( $base_minor, $bp, self::BP_100 );
	}

	/**
	 * Convert a percentage expressed as a decimal number into basis points.
	 *
	 * @param float $percent Percentage, e.g. 30.5.
	 * @return int Basis points.
	 */
	public static function to_bp( float $percent ): int {
		return (int) round( $percent * 100 );
	}

	/**
	 * Convert basis points back to a percentage.
	 *
	 * @param int $bp Basis points.
	 * @return float Percentage.
	 */
	public static function bp_to_percent( int $bp ): float {
		return $bp / 100;
	}

	/* --------------------------------------------------------------- *
	 * Parsing
	 * --------------------------------------------------------------- */

	/**
	 * Parse a machine-written decimal into minor units.
	 *
	 * For values produced by WooCommerce itself, which are always a plain
	 * decimal string with a dot: "29.99", "1000.00", "0".
	 *
	 * SEPARATE FROM parse_amount_to_minor() ON PURPOSE. The spreadsheet parser
	 * below has to resolve the ambiguity between a thousands separator and a
	 * decimal point with a heuristic, and a heuristic is the wrong tool for a
	 * field whose format is known: "1.000" from a supplier spreadsheet is one
	 * thousand, and "1.000" from WooCommerce is one unit. Sending WooCommerce
	 * prices through the heuristic would multiply some of them by a thousand.
	 *
	 * The conversion works on the STRING rather than multiplying a float by 100,
	 * because 29.99 * 100 is 2998.9999999999995 in IEEE-754 and rounding that is
	 * one more place for a cent to go missing.
	 *
	 * @param string|float|int|null $value Raw value.
	 * @return int|null Minor units, or null when it is not a parseable decimal.
	 */
	public static function parse_decimal_to_minor( $value ): ?int {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return self::mul_exact( $value, 100 );
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? (int) round( $value * 100 ) : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$string = trim( $value );
		if ( '' === $string || 1 !== preg_match( '/^-?\d+(\.\d+)?$/', $string ) ) {
			return null;
		}

		$negative = 0 === strpos( $string, '-' );
		$body     = $negative ? substr( $string, 1 ) : $string;
		$parts    = explode( '.', $body );
		$whole    = $parts[0];
		$fraction = $parts[1] ?? '';

		// Two decimals, rounding half away from zero on anything longer. A rate
		// or a prorated amount can carry more, and truncating would quietly
		// lose a cent.
		$cents = (int) substr( $fraction . '00', 0, 2 );
		$third = strlen( $fraction ) > 2 ? (int) $fraction[2] : 0;

		$minor = self::mul_exact( (int) $whole, 100 ) + $cents;
		if ( $third >= 5 ) {
			++$minor;
		}

		return $negative ? -$minor : $minor;
	}

	/**
	 * Parse a human-written amount into minor units.
	 *
	 * For values a person typed into a spreadsheet. Handles EU (1.234,56) and
	 * US (1,234.56) conventions, currency symbols, parentheses and trailing
	 * negatives. Returns null when the input is not a parseable amount - it
	 * never guesses, because a guessed cost produces a wrong margin that looks
	 * exactly like a right one.
	 *
	 * @param string|float|int|null $value Raw value.
	 * @return int|null Minor units, or null.
	 */
	public static function parse_amount_to_minor( $value ): ?int {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return self::mul_exact( $value, 100 );
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? (int) round( $value * 100 ) : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$string = trim( $value );
		if ( '' === $string ) {
			return null;
		}

		// Strip NBSP / narrow-NBSP / thin-space thousands separators, currency
		// symbols and ISO codes.
		$string = preg_replace( '/[\x{00A0}\x{202F}\x{2009}]/u', '', $string );
		$string = preg_replace( '/EUR|USD|GBP|CHF|HRK|PLN|SEK|DKK|NOK|CZK/i', '', (string) $string );
		$string = preg_replace( '/[€£$¥]/u', '', (string) $string );
		$string = preg_replace( '/\s+/', '', (string) $string );
		$string = (string) $string;

		$negative = false;
		if ( 1 === preg_match( '/^\(.*\)$/', $string ) ) {
			$negative = true;
			$string   = substr( $string, 1, -1 );
		}
		if ( 0 === strpos( $string, '-' ) ) {
			$negative = true;
			$string   = substr( $string, 1 );
		}
		if ( '' !== $string && '-' === substr( $string, -1 ) ) {
			$negative = true;
			$string   = substr( $string, 0, -1 );
		}
		if ( 0 === strpos( $string, '+' ) ) {
			$string = substr( $string, 1 );
		}

		if ( 1 !== preg_match( '/^[\d.,]+$/', $string ) || 1 !== preg_match( '/\d/', $string ) ) {
			return null;
		}

		$last_comma = strrpos( $string, ',' );
		$last_dot   = strrpos( $string, '.' );

		if ( false === $last_comma && false === $last_dot ) {
			$normalized = $string;
		} elseif ( false === $last_dot || ( false !== $last_comma && $last_comma > $last_dot ) ) {
			// Comma is the decimal separator (EU): 1.234,56
			$normalized = str_replace( ',', '.', str_replace( '.', '', $string ) );
		} else {
			// Dot is the decimal separator (US): 1,234.56
			$normalized = str_replace( ',', '', $string );
		}

		/*
		 * Ambiguity guard: a single separator with exactly three trailing digits
		 * and a short integer part is a THOUSANDS separator, not a decimal, so
		 * "1.000" is one thousand.
		 *
		 * A LEADING ZERO settles the ambiguity for certain in the other
		 * direction: no thousands group is ever written "0.750", so that is
		 * 0.75. This exception is load-bearing for supplier cost lists, which
		 * routinely quote unit costs to three or four decimals - without it a
		 * cost of 0.81 parses as 8.12 and every margin computed from it is
		 * wrong.
		 */
		$single_separator = ( false === $last_comma ) !== ( false === $last_dot );
		if ( $single_separator ) {
			$separator_index = ( false === $last_comma ) ? $last_dot : $last_comma;
			$trailing        = strlen( $string ) - $separator_index - 1;
			$leading         = $separator_index;
			$starts_with_zero = 0 === strpos( $string, '0' );
			if ( 3 === $trailing && $leading > 0 && $leading <= 3 && ! $starts_with_zero ) {
				$normalized = str_replace( array( '.', ',' ), '', $string );
			}
		}

		if ( ! is_numeric( $normalized ) ) {
			return null;
		}

		$minor = (int) round( ( (float) $normalized ) * 100 );

		return $negative ? -$minor : $minor;
	}

	/**
	 * Parse a percentage into basis points. Accepts "14,5%", "14.5", "14.5 %".
	 *
	 * @param string|float|int|null $value Raw value.
	 * @return int|null Basis points, or null.
	 */
	public static function parse_percent_to_bp( $value ): ?int {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return is_finite( (float) $value ) ? (int) round( ( (float) $value ) * 100 ) : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$string = str_replace( array( '%', ' ' ), '', trim( $value ) );
		if ( '' === $string ) {
			return null;
		}
		// percent * 100 === basis points, which is what parse_amount_to_minor
		// already produces.
		return self::parse_amount_to_minor( $string );
	}

	/* --------------------------------------------------------------- *
	 * Formatting
	 * --------------------------------------------------------------- */

	/**
	 * Format minor units for display, without a currency symbol.
	 *
	 * Deterministic and locale-independent so a stored report and a screen
	 * never disagree. The admin layer adds the store's currency symbol via
	 * WooCommerce, which is the only component that knows it.
	 *
	 * A null amount renders as an em dash, NEVER as "0.00". That distinction is
	 * the core honesty rule of the whole product: an amount we could not
	 * establish is not an amount of zero.
	 *
	 * @param int|null $amount_minor    Amount in minor units, or null.
	 * @param string   $decimal_sep     Decimal separator.
	 * @param string   $thousands_sep   Thousands separator.
	 * @return string Formatted amount.
	 */
	public static function format_minor( ?int $amount_minor, string $decimal_sep = '.', string $thousands_sep = ',' ): string {
		if ( null === $amount_minor ) {
			return '—';
		}

		$negative = $amount_minor < 0;
		$absolute = self::abs_int( $amount_minor );
		$whole    = intdiv( $absolute, 100 );
		$cents    = $absolute % 100;

		$grouped = strrev( implode( $thousands_sep, str_split( strrev( (string) $whole ), 3 ) ) );
		$body    = $grouped . $decimal_sep . str_pad( (string) $cents, 2, '0', STR_PAD_LEFT );

		return $negative ? '-' . $body : $body;
	}

	/**
	 * Format basis points as a percentage.
	 *
	 * @param int|null $bp          Basis points, or null.
	 * @param string   $decimal_sep Decimal separator.
	 * @return string Formatted percentage.
	 */
	public static function format_percent_bp( ?int $bp, string $decimal_sep = '.' ): string {
		if ( null === $bp ) {
			return '—';
		}
		$absolute = self::abs_int( $bp );
		$whole    = intdiv( $absolute, 100 );
		$fraction = $absolute % 100;
		$sign     = $bp < 0 ? '-' : '';

		if ( 0 === $fraction ) {
			return $sign . $whole . '%';
		}
		if ( 0 === $fraction % 10 ) {
			return $sign . $whole . $decimal_sep . ( $fraction / 10 ) . '%';
		}
		return $sign . $whole . $decimal_sep . str_pad( (string) $fraction, 2, '0', STR_PAD_LEFT ) . '%';
	}
}
