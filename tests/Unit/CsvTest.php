<?php
/**
 * CSV parsing, column detection and safe writing.
 *
 * The security-relevant assertions here are the formula-injection ones: an
 * export is a path from one user's input (a product name) to another user's
 * spreadsheet.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProfitGuard\Core\Csv;

final class CsvTest extends TestCase {

	/* ================================================================ *
	 * Delimiter detection
	 * ================================================================ */

	public function test_detects_a_comma_delimited_file(): void {
		$this->assertSame( ',', Csv::detect_delimiter( "SKU,Cost\nA-1,10.00\nA-2,20.00" ) );
	}

	public function test_detects_a_semicolon_file_with_decimal_commas(): void {
		/*
		 * The case frequency-counting gets wrong every time: a European export
		 * has MORE commas than semicolons, because every price contains one.
		 * Scoring on column-count consistency instead is what fixes it.
		 */
		$sample = "SKU;Cost\nA-1;10,50\nA-2;20,75\nA-3;5,25";
		$this->assertSame( ';', Csv::detect_delimiter( $sample ) );
	}

	public function test_detects_a_tab_delimited_file(): void {
		$this->assertSame( "\t", Csv::detect_delimiter( "SKU\tCost\nA-1\t10.00\nA-2\t20.00" ) );
	}

	public function test_falls_back_to_a_comma_for_an_empty_sample(): void {
		$this->assertSame( ',', Csv::detect_delimiter( '' ) );
		$this->assertSame( ',', Csv::detect_delimiter( "\n\n" ) );
	}

	/* ================================================================ *
	 * Parsing
	 * ================================================================ */

	public function test_parses_rows(): void {
		$result = Csv::parse( "SKU,Cost\nA-1,10.00\nA-2,20.00" );
		$this->assertSame( ',', $result['delimiter'] );
		$this->assertCount( 3, $result['rows'] );
		$this->assertSame( array( 'SKU', 'Cost' ), $result['rows'][0] );
		$this->assertSame( array( 'A-1', '10.00' ), $result['rows'][1] );
	}

	public function test_strips_the_excel_byte_order_mark(): void {
		/*
		 * Excel writes a BOM on almost every CSV it exports. Left in place it
		 * becomes part of the first header, so "SKU" arrives as an unmatchable
		 * string and the column mapper silently misses the most important
		 * column in the file.
		 */
		$result = Csv::parse( "\xEF\xBB\xBFSKU,Cost\nA-1,10.00" );
		$this->assertSame( 'SKU', $result['rows'][0][0] );
	}

	public function test_skips_blank_lines(): void {
		$result = Csv::parse( "SKU,Cost\n\nA-1,10.00\n\n\nA-2,20.00\n" );
		$this->assertCount( 3, $result['rows'] );
	}

	public function test_handles_quoted_fields_containing_the_delimiter(): void {
		$result = Csv::parse( "SKU,Name,Cost\nA-1,\"Charger, wireless\",10.00" );
		$this->assertSame( 'Charger, wireless', $result['rows'][1][1] );
	}

	public function test_caps_the_number_of_rows_and_says_so(): void {
		$lines = array( 'SKU,Cost' );
		for ( $i = 0; $i < 50; $i++ ) {
			$lines[] = "A-{$i},10.00";
		}
		$result = Csv::parse( implode( "\n", $lines ), null, 10 );
		$this->assertCount( 10, $result['rows'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_an_empty_document_parses_to_nothing(): void {
		$result = Csv::parse( '' );
		$this->assertSame( array(), $result['rows'] );
		$this->assertFalse( $result['truncated'] );
	}

	/* ================================================================ *
	 * Header normalisation
	 * ================================================================ */

	public function test_normalises_case_and_punctuation(): void {
		$this->assertSame( 'cost price net', Csv::normalise_header( 'Cost price (net)' ) );
		$this->assertSame( 'cost price net', Csv::normalise_header( 'COST_PRICE_NET' ) );
	}

	public function test_folds_letters_that_a_plain_accent_strip_would_delete(): void {
		/*
		 * "ss", "d" and "o" are distinct letters, not accented ones, so an
		 * accent strip removes them outright. Without the explicit fold,
		 * "Grosse" becomes "gro e" and Croatian "Sifra" loses its first letter,
		 * and neither matches any synonym.
		 */
		$this->assertSame( 'grosse', Csv::normalise_header( 'Größe' ) );
		$this->assertSame( 'sifra artikla', Csv::normalise_header( 'Šifra artikla' ) );
		$this->assertSame( 'einkaufspreis', Csv::normalise_header( 'Einkaufspreis' ) );
	}

	/* ================================================================ *
	 * Column suggestion
	 * ================================================================ */

	public function test_suggests_the_obvious_columns(): void {
		$mapping = Csv::suggest_columns( array( 'SKU', 'Cost', 'Currency' ) );
		$this->assertSame( 0, $mapping['sku'] );
		$this->assertSame( 1, $mapping['cost'] );
		$this->assertSame( 2, $mapping['currency'] );
	}

	public function test_suggests_columns_from_synonyms(): void {
		$mapping = Csv::suggest_columns( array( 'Artikelnummer', 'Einkaufspreis' ) );
		$this->assertSame( 0, $mapping['sku'] );
		$this->assertSame( 1, $mapping['cost'] );
	}

	public function test_prefers_the_more_specific_synonym(): void {
		// "Actual shipping cost" must map to actual_cost, not to cost.
		$mapping = Csv::suggest_columns( array( 'Order Number', 'Actual Shipping Cost' ) );
		$this->assertSame( 0, $mapping['order'] );
		$this->assertSame( 1, $mapping['actual_cost'] );
	}

	public function test_maps_a_carrier_file(): void {
		$mapping = Csv::suggest_columns(
			array( 'Order Number', 'Tracking Number', 'Carrier', 'Actual Shipping Cost', 'Currency' )
		);
		$this->assertSame( 0, $mapping['order'] );
		$this->assertSame( 1, $mapping['tracking'] );
		$this->assertSame( 2, $mapping['carrier'] );
		$this->assertSame( 3, $mapping['actual_cost'] );
		$this->assertSame( 4, $mapping['currency'] );
	}

	public function test_never_maps_two_concepts_to_one_column(): void {
		$mapping = Csv::suggest_columns( array( 'SKU', 'Cost', 'Cost centre', 'Name' ) );
		$this->assertSame( count( $mapping ), count( array_unique( array_values( $mapping ) ) ) );
	}

	public function test_leaves_unrecognised_columns_unmapped(): void {
		$mapping = Csv::suggest_columns( array( 'Zzz', 'Qqq' ) );
		$this->assertArrayNotHasKey( 'sku', $mapping );
		$this->assertArrayNotHasKey( 'cost', $mapping );
	}

	/* ================================================================ *
	 * Formula injection - the security-relevant part
	 * ================================================================ */

	public function test_escapes_every_formula_trigger(): void {
		/*
		 * A cell beginning with any of these is executed as a FORMULA by Excel,
		 * LibreOffice and Google Sheets. Product names come from whoever can
		 * edit products, so an export carries their input into someone else's
		 * spreadsheet.
		 */
		$this->assertSame( "'=HYPERLINK(\"http://evil\")", Csv::escape_cell( '=HYPERLINK("http://evil")' ) );
		$this->assertSame( "'+1+1", Csv::escape_cell( '+1+1' ) );
		$this->assertSame( "'-1+1", Csv::escape_cell( '-1+1' ) );
		$this->assertSame( "'@SUM(A1)", Csv::escape_cell( '@SUM(A1)' ) );
		$this->assertSame( "'\t=1+1", Csv::escape_cell( "\t=1+1" ) );
		$this->assertSame( "'\r=1+1", Csv::escape_cell( "\r=1+1" ) );
	}

	public function test_escapes_the_classic_command_payload(): void {
		$payload = '=cmd|\' /C calc\'!A0';
		$this->assertSame( "'" . $payload, Csv::escape_cell( $payload ) );
	}

	public function test_leaves_ordinary_values_alone(): void {
		$this->assertSame( 'Wireless Charger', Csv::escape_cell( 'Wireless Charger' ) );
		$this->assertSame( '29.99', Csv::escape_cell( '29.99' ) );
		$this->assertSame( 'A-1', Csv::escape_cell( 'A-1' ) );
		$this->assertSame( '', Csv::escape_cell( '' ) );
		$this->assertSame( '', Csv::escape_cell( null ) );
	}

	public function test_a_negative_number_is_escaped_because_a_spreadsheet_cannot_tell(): void {
		/*
		 * "-6.43" is a legitimate value AND a formula prefix, and a spreadsheet
		 * decides by the first character alone. Escaping it makes the cell text
		 * rather than a number, which is the correct trade: an export whose
		 * negative amounts are text is inconvenient, an export that executes a
		 * payload is a vulnerability. Amount columns are written unsigned with
		 * a separate direction column for this reason.
		 */
		$this->assertSame( "'-6.43", Csv::escape_cell( '-6.43' ) );
	}

	/* ================================================================ *
	 * Writing
	 * ================================================================ */

	public function test_builds_a_csv_with_a_bom_and_quoting(): void {
		$csv = Csv::build( array( 'SKU', 'Name' ), array( array( 'A-1', 'Charger, wireless' ) ) );

		$this->assertSame( "\xEF\xBB\xBF", substr( $csv, 0, 3 ) );
		$this->assertStringContainsString( '"Charger, wireless"', $csv );
	}

	public function test_a_built_csv_round_trips(): void {
		$csv    = Csv::build( array( 'SKU', 'Cost' ), array( array( 'A-1', '10.00' ), array( 'A-2', '20.00' ) ) );
		$result = Csv::parse( $csv );

		$this->assertSame( array( 'SKU', 'Cost' ), $result['rows'][0] );
		$this->assertSame( array( 'A-2', '20.00' ), $result['rows'][2] );
	}

	public function test_a_malicious_product_name_survives_a_round_trip_as_text(): void {
		$csv    = Csv::build( array( 'Name' ), array( array( '=1+1' ) ) );
		$result = Csv::parse( $csv );
		// Still quoted out, so reopening the export does not execute it either.
		$this->assertSame( "'=1+1", $result['rows'][1][0] );
	}
}
