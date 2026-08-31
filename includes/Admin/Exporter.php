<?php
/**
 * CSV export of findings.
 *
 * FREE, and deliberately so: an export the merchant has to pay for turns their
 * own data into a hostage, which is the pattern the WordPress.org guidelines
 * exist to prevent.
 *
 * TWO THINGS THIS FILE GETS RIGHT THAT ARE EASY TO GET WRONG
 *
 * 1. Formula injection. Every cell goes through Core\Csv::escape_cell(). A
 *    product name is merchant-supplied input, and an export carries it into
 *    whoever opens the spreadsheet.
 *
 * 2. Amounts are written UNSIGNED with a separate direction column. A negative
 *    number starts with "-", which a spreadsheet reads as a formula, so
 *    escaping turns it into text and the column stops being numeric. Splitting
 *    sign from magnitude keeps the amount column sortable AND safe.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Admin;

use ProfitGuard\Core\Csv;
use ProfitGuard\Core\Money;
use ProfitGuard\Plugin\Repository;
use ProfitGuard\Plugin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Findings export.
 */
final class Exporter {

	/**
	 * Stream the current scan's findings as a CSV download.
	 *
	 * Capability and nonce are verified by Admin::handle_post() before this is
	 * reached; the capability is re-checked here because a download endpoint
	 * that relies on its caller is one refactor away from being public.
	 */
	public static function send_findings_csv(): void {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to export ProfitGuard data.', 'profitguard-for-woocommerce' ),
				403
			);
		}

		$scan_id = Repository::latest_scan_id();
		$rows    = array();

		if ( $scan_id > 0 ) {
			// Paged rather than one unbounded query: a large store can produce
			// tens of thousands of findings and loading them all at once is the
			// same memory problem the scanner exists to avoid.
			$page = 1;
			do {
				$result = Repository::query_findings(
					array(
						'scan_id'  => $scan_id,
						'orderby'  => 'impact',
						'per_page' => 200,
						'page'     => $page,
					)
				);
				foreach ( $result['rows'] as $row ) {
					$rows[] = self::to_csv_row( $row );
				}
				++$page;
				$collected = count( $rows );
			} while ( ! empty( $result['rows'] ) && $collected < (int) $result['total'] && $page < 500 );
		}//end if

		$csv = Csv::build( self::headers(), $rows );

		$filename = 'profitguard-findings-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );

		// The CSV is fully constructed and escaped by Csv::build(); echoing it
		// through esc_html() would corrupt the file.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $csv;
		exit;
	}

	/**
	 * Column headers.
	 *
	 * @return string[]
	 */
	private static function headers(): array {
		return array(
			__( 'Area', 'profitguard-for-woocommerce' ),
			__( 'Type', 'profitguard-for-woocommerce' ),
			__( 'Severity', 'profitguard-for-woocommerce' ),
			__( 'Basis', 'profitguard-for-woocommerce' ),
			__( 'Subject', 'profitguard-for-woocommerce' ),
			__( 'Reference', 'profitguard-for-woocommerce' ),
			__( 'Current', 'profitguard-for-woocommerce' ),
			__( 'Target or expected', 'profitguard-for-woocommerce' ),
			__( 'Difference', 'profitguard-for-woocommerce' ),
			__( 'Difference direction', 'profitguard-for-woocommerce' ),
			__( 'Currency', 'profitguard-for-woocommerce' ),
			__( 'What to do', 'profitguard-for-woocommerce' ),
		);
	}

	/**
	 * One finding row as CSV cells.
	 *
	 * @param array<string, mixed> $row Finding row.
	 * @return array<int, string>
	 */
	private static function to_csv_row( array $row ): array {
		$impact = ( null === $row['impact_minor'] ) ? null : (int) $row['impact_minor'];

		return array(
			Labels::module( (string) $row['module'] ),
			Labels::type( (string) $row['type'] ),
			Labels::severity( (string) $row['severity'] ),
			Labels::financial_type( (string) $row['financial_type'] ),
			(string) $row['subject_label'],
			(string) $row['reference'],
			self::amount( ( null === $row['current_minor'] ) ? null : (int) $row['current_minor'] ),
			self::amount( ( null === $row['expected_minor'] ) ? null : (int) $row['expected_minor'] ),
			self::amount( null === $impact ? null : abs( $impact ) ),
			self::direction( $impact ),
			get_woocommerce_currency(),
			Labels::action( (string) $row['type'] ),
		);
	}

	/**
	 * Format an amount for a spreadsheet cell.
	 *
	 * A plain decimal with a dot, never a currency-formatted string: the
	 * merchant is going to sort and total this column, and "€1.234,56" is text
	 * to every spreadsheet program. An unknown amount is an EMPTY cell rather
	 * than a zero - the same rule as everywhere else, applied where it is most
	 * likely to be summed by accident.
	 *
	 * @param int|null $minor Amount in minor units.
	 * @return string
	 */
	private static function amount( ?int $minor ): string {
		if ( null === $minor ) {
			return '';
		}
		return Money::format_minor( $minor, '.', '' );
	}

	/**
	 * The sign of an amount, as a word.
	 *
	 * @param int|null $minor Amount in minor units.
	 * @return string
	 */
	private static function direction( ?int $minor ): string {
		if ( null === $minor ) {
			return '';
		}
		if ( $minor < 0 ) {
			return __( 'loss', 'profitguard-for-woocommerce' );
		}
		if ( 0 === $minor ) {
			return __( 'none', 'profitguard-for-woocommerce' );
		}
		return __( 'shortfall', 'profitguard-for-woocommerce' );
	}
}
