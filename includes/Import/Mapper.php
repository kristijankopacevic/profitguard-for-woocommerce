<?php
/**
 * Turning raw CSV rows into validated import rows.
 *
 * Pure PHP over plain arrays, so every validation rule - including the
 * malicious-input cases - is tested without WordPress.
 *
 * THE GOVERNING RULE: A ROW IS EITHER VALID OR REJECTED, NEVER GUESSED.
 *
 * A cost that cannot be parsed becomes a rejected row with a reason the
 * merchant can read, not a zero and not a skipped line. Silently dropping a
 * malformed row is how an import reports "300 products updated" while the
 * thirty that mattered were discarded.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Import;

use ProfitGuard\Core\Money;

defined( 'ABSPATH' ) || defined( 'PROFITGUARD_TESTING' ) || exit;

/**
 * Row validation and normalisation.
 */
final class Mapper {

	public const REASON_NO_SKU            = 'no_sku';
	public const REASON_NO_COST           = 'no_cost';
	public const REASON_BAD_COST          = 'bad_cost';
	public const REASON_NEGATIVE_COST     = 'negative_cost';
	public const REASON_CURRENCY_MISMATCH = 'currency_mismatch';
	public const REASON_DUPLICATE_SKU     = 'duplicate_sku';
	public const REASON_NO_ORDER_REF      = 'no_order_reference';
	public const REASON_NO_AMOUNT         = 'no_amount';

	/**
	 * Read a cell by concept from a mapped row.
	 *
	 * @param string[]           $row     Raw row.
	 * @param array<string, int> $mapping Concept => column index.
	 * @param string             $concept Concept.
	 * @return string Empty string when absent.
	 */
	private static function cell( array $row, array $mapping, string $concept ): string {
		if ( ! isset( $mapping[ $concept ] ) ) {
			return '';
		}
		$index = (int) $mapping[ $concept ];
		return isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
	}

	// Product costs.

	/**
	 * Validate product-cost rows.
	 *
	 * @param array<int, string[]> $rows           Data rows, header already removed.
	 * @param array<string, int>   $mapping        Concept => column index.
	 * @param string               $store_currency Store currency code.
	 * @return array{
	 *     valid:array<int, array{row:int,sku:string,cost_minor:int,name:string}>,
	 *     rejected:array<int, array{row:int,reason:string,value:string}>
	 * }
	 */
	public static function map_cost_rows( array $rows, array $mapping, string $store_currency ): array {
		$valid    = array();
		$rejected = array();
		$seen_sku = array();

		foreach ( $rows as $index => $row ) {
			// +2: the header is row 1 and spreadsheets are 1-based, so this is
			// the line number the merchant sees in their own file.
			$line = $index + 2;

			$sku = self::cell( $row, $mapping, 'sku' );
			if ( '' === $sku ) {
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_NO_SKU,
					'value'  => '',
				);
				continue;
			}

			$key = strtoupper( $sku );
			if ( isset( $seen_sku[ $key ] ) ) {
				/*
				 * A repeated SKU in one file is ambiguous: we cannot know which
				 * cost the merchant meant. Taking the last one silently would
				 * be a coin flip on their margin, so the duplicate is rejected
				 * and reported with the line it clashes with.
				 */
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_DUPLICATE_SKU,
					'value'  => $sku,
				);
				continue;
			}

			$currency = self::cell( $row, $mapping, 'currency' );
			if ( '' !== $currency && strtoupper( $currency ) !== strtoupper( $store_currency ) ) {
				/*
				 * WooCommerce has exactly one store currency. A cost in another
				 * one cannot be compared with a price without an exchange rate
				 * ProfitGuard does not have and will not invent, so the row is
				 * refused rather than silently treated as if it were local.
				 */
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_CURRENCY_MISMATCH,
					'value'  => $currency,
				);
				continue;
			}

			$raw_cost = self::cell( $row, $mapping, 'cost' );
			if ( '' === $raw_cost ) {
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_NO_COST,
					'value'  => '',
				);
				continue;
			}

			// The spreadsheet parser, not the strict one: this value was typed
			// by a person and may be "1.234,56" or "€12,50".
			$cost = Money::parse_amount_to_minor( $raw_cost );
			if ( null === $cost ) {
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_BAD_COST,
					'value'  => $raw_cost,
				);
				continue;
			}
			if ( $cost < 0 ) {
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_NEGATIVE_COST,
					'value'  => $raw_cost,
				);
				continue;
			}

			$seen_sku[ $key ] = $line;
			$valid[]          = array(
				'row'        => $line,
				'sku'        => $sku,
				'cost_minor' => $cost,
				'name'       => self::cell( $row, $mapping, 'name' ),
			);
		}//end foreach

		return array(
			'valid'    => $valid,
			'rejected' => $rejected,
		);
	}

	// Carrier costs.

	/**
	 * Validate carrier-cost rows.
	 *
	 * @param array<int, string[]> $rows           Data rows, header already removed.
	 * @param array<string, int>   $mapping        Concept => column index.
	 * @param string               $store_currency Store currency code.
	 * @return array{
	 *     valid:array<int, array<string, mixed>>,
	 *     rejected:array<int, array{row:int,reason:string,value:string}>
	 * }
	 */
	public static function map_carrier_rows( array $rows, array $mapping, string $store_currency ): array {
		$valid    = array();
		$rejected = array();

		foreach ( $rows as $index => $row ) {
			$line = $index + 2;

			$reference = self::cell( $row, $mapping, 'order' );
			$tracking  = self::cell( $row, $mapping, 'tracking' );

			// A row with neither an order reference nor a tracking number
			// cannot be attached to anything at all.
			if ( '' === $reference && '' === $tracking ) {
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_NO_ORDER_REF,
					'value'  => '',
				);
				continue;
			}

			$currency = self::cell( $row, $mapping, 'currency' );
			if ( '' !== $currency && strtoupper( $currency ) !== strtoupper( $store_currency ) ) {
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_CURRENCY_MISMATCH,
					'value'  => $currency,
				);
				continue;
			}

			$raw_cost = self::cell( $row, $mapping, 'actual_cost' );
			$cost     = '' === $raw_cost ? null : Money::parse_amount_to_minor( $raw_cost );

			if ( '' !== $raw_cost && null === $cost ) {
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_BAD_COST,
					'value'  => $raw_cost,
				);
				continue;
			}
			if ( null === $cost ) {
				// A carrier line with no amount tells us nothing about money
				// and would only add a phantom zero-cost shipment.
				$rejected[] = array(
					'row'    => $line,
					'reason' => self::REASON_NO_AMOUNT,
					'value'  => '',
				);
				continue;
			}

			$surcharge  = Money::parse_amount_to_minor( self::cell( $row, $mapping, 'surcharge' ) );
			$adjustment = Money::parse_amount_to_minor( self::cell( $row, $mapping, 'adjustment' ) );

			$valid[] = array(
				'row'              => $line,
				'order_reference'  => $reference,
				'tracking_number'  => $tracking,
				'carrier'          => self::cell( $row, $mapping, 'carrier' ),
				'cost_minor'       => $cost,
				'surcharge_minor'  => $surcharge,
				'adjustment_minor' => $adjustment,
				'currency'         => '' !== $currency ? strtoupper( $currency ) : strtoupper( $store_currency ),
				'shipped_on'       => self::normalise_date( self::cell( $row, $mapping, 'date' ) ),
			);
		}//end foreach

		return array(
			'valid'    => $valid,
			'rejected' => $rejected,
		);
	}

	/**
	 * Normalise a date cell to Y-m-d, or null.
	 *
	 * Deliberately conservative: an unrecognised date becomes null rather than
	 * a guess. The date is informational here - nothing is matched on it - so a
	 * wrong date is worse than no date.
	 *
	 * @param string $value Raw cell.
	 * @return string|null
	 */
	public static function normalise_date( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		// ISO first: unambiguous, and what most carrier exports use.
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
		}

		/*
		 * d/m/Y and d.m.Y. NOT m/d/Y: 03/04/2026 is genuinely ambiguous, and
		 * guessing wrong silently mislabels half the rows in a file. Day-first
		 * is assumed because the separator styles that reach this branch are
		 * overwhelmingly European; an American export is normally ISO already.
		 */
		if ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})#', $value, $m ) ) {
			$day   = (int) $m[1];
			$month = (int) $m[2];
			$year  = (int) $m[3];
			if ( checkdate( $month, $day, $year ) ) {
				return sprintf( '%04d-%02d-%02d', $year, $month, $day );
			}
		}

		return null;
	}

	/**
	 * A stable digest of a carrier row, for duplicate-import protection.
	 *
	 * Built from the fields that identify the SHIPMENT and its amount. Two
	 * genuinely different lines that happen to share a tracking number - a base
	 * charge and a later adjustment - produce different hashes because the
	 * amounts differ, so a legitimate second line is still imported.
	 *
	 * @param array<string, mixed> $row Validated carrier row.
	 * @return string 40-character hex digest.
	 */
	public static function carrier_row_hash( array $row ): string {
		$parts = array(
			strtoupper( trim( (string) ( $row['order_reference'] ?? '' ) ) ),
			strtoupper( trim( (string) ( $row['tracking_number'] ?? '' ) ) ),
			strtoupper( trim( (string) ( $row['carrier'] ?? '' ) ) ),
			(string) ( $row['cost_minor'] ?? '' ),
			(string) ( $row['surcharge_minor'] ?? '' ),
			(string) ( $row['adjustment_minor'] ?? '' ),
			(string) ( $row['shipped_on'] ?? '' ),
		);
		return sha1( implode( '|', $parts ) );
	}
}
