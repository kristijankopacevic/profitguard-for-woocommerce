<?php
/**
 * CSV reading, column detection and safe writing.
 *
 * Adapted from the ProfitGuard TypeScript parse/mapping layer, reduced to what
 * a local import actually needs.
 *
 * XLSX IS NOT SUPPORTED IN V1, ON PURPOSE. Reading it needs either the `zip`
 * PHP extension (absent from a default PHP build and not guaranteed on shared
 * hosting) or a bundled spreadsheet library, which would add a multi-megabyte
 * vendor tree to a WordPress.org plugin for a format every spreadsheet program
 * can export as CSV in two clicks. The brief conditions XLSX on "without
 * introducing unreliable dependencies", and neither option clears that bar. The
 * readme says so plainly rather than leaving a merchant to discover it.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Core;

defined( 'ABSPATH' ) || defined( 'PROFITGUARD_TESTING' ) || exit;

/**
 * CSV helpers. Pure PHP: no WordPress, no WooCommerce, no filesystem access
 * beyond an already-opened handle.
 */
final class Csv {

	/**
	 * Delimiters we will consider, in preference order.
	 */
	private const DELIMITERS = array( ',', ';', "\t", '|' );

	/**
	 * Characters that make a spreadsheet treat a cell as a formula.
	 *
	 * The tab and carriage return are included because Excel strips leading
	 * whitespace before deciding, so " =cmd" is still a formula to it.
	 */
	private const FORMULA_TRIGGERS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Detect the delimiter of a CSV sample.
	 *
	 * Chooses the delimiter that yields the most CONSISTENT column count across
	 * the sample, not simply the most frequent character. A semicolon file full
	 * of decimal commas has more commas than semicolons, and frequency alone
	 * picks the wrong one every time.
	 *
	 * @param string $sample First few kilobytes of the file.
	 * @return string The detected delimiter.
	 */
	public static function detect_delimiter( string $sample ): string {
		// Not `?: array()`: a short ternary hides the fact that preg_split
		// returns false on failure, and WPCS forbids it for that reason.
		$split = preg_split(
			'/
|
|
/',
			$sample
		);
		$lines = is_array( $split ) ? $split : array();
		$lines = array_values(
			array_filter(
				$lines,
				static function ( $line ) {
					return '' !== trim( (string) $line );
				}
			)
		);
		$lines = array_slice( $lines, 0, 20 );

		if ( empty( $lines ) ) {
			return ',';
		}

		$best       = ',';
		$best_score = -1.0;

		foreach ( self::DELIMITERS as $delimiter ) {
			$counts = array();
			foreach ( $lines as $line ) {
				$parsed   = str_getcsv( (string) $line, $delimiter );
				$counts[] = count( $parsed );
			}

			$columns = max( $counts );
			if ( $columns < 2 ) {
				continue;
			}

			// Score: how many lines agree on the modal column count, weighted
			// by how many columns that is.
			$modal     = self::modal( $counts );
			$agreement = count(
				array_filter(
					$counts,
					static function ( $n ) use ( $modal ) {
						return $n === $modal;
					}
				)
			) / count( $counts );
			$score     = $agreement * $modal;

			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $delimiter;
			}
		}//end foreach

		return $best;
	}

	/**
	 * The most common value in a list of integers.
	 *
	 * @param int[] $values Values.
	 * @return int Modal value.
	 */
	private static function modal( array $values ): int {
		$tally = array();
		foreach ( $values as $value ) {
			$tally[ $value ] = ( $tally[ $value ] ?? 0 ) + 1;
		}
		arsort( $tally );
		$keys = array_keys( $tally );
		return (int) ( $keys[0] ?? 0 );
	}

	/**
	 * Strip a UTF-8 byte order mark.
	 *
	 * Excel writes one on almost every CSV it exports. Left in place it becomes
	 * part of the first header name, so "SKU" arrives as "\u{FEFF}SKU" and the
	 * column mapper silently fails to recognise the most important column in
	 * the file.
	 *
	 * @param string $text Text.
	 * @return string Text without a BOM.
	 */
	public static function strip_bom( string $text ): string {
		if ( 0 === strncmp( $text, "\xEF\xBB\xBF", 3 ) ) {
			return substr( $text, 3 );
		}
		return $text;
	}

	/**
	 * Parse CSV text into rows.
	 *
	 * @param string      $text      CSV text.
	 * @param string|null $delimiter Delimiter, or null to detect.
	 * @param int         $max_rows  Hard cap on returned rows.
	 * @return array{delimiter:string,rows:array<int,string[]>,truncated:bool}
	 */
	public static function parse( string $text, ?string $delimiter = null, int $max_rows = 20000 ): array {
		$text      = self::strip_bom( $text );
		$delimiter = $delimiter ?? self::detect_delimiter( substr( $text, 0, 65536 ) );

		$rows      = array();
		$truncated = false;

		$handle = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory stream, not a file on disk. WP_Filesystem has no equivalent and nothing here touches the filesystem.
		if ( false === $handle ) {
			return array(
				'delimiter' => $delimiter,
				'rows'      => array(),
				'truncated' => false,
			);
		}
		fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://memory stream, not a file on disk. WP_Filesystem has no equivalent and nothing here touches the filesystem.
		rewind( $handle );

		while ( true ) {
			$row = fgetcsv( $handle, 0, $delimiter );
			if ( false === $row || null === $row ) {
				break;
			}
			// fgetcsv yields array( null ) for a blank line.
			if ( 1 === count( $row ) && ( null === $row[0] || '' === trim( (string) $row[0] ) ) ) {
				continue;
			}
			if ( count( $rows ) >= $max_rows ) {
				$truncated = true;
				break;
			}
			$rows[] = array_map(
				static function ( $cell ) {
					return null === $cell ? '' : trim( (string) $cell );
				},
				$row
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://memory stream, not a file on disk. WP_Filesystem has no equivalent and nothing here touches the filesystem.

		return array(
			'delimiter' => $delimiter,
			'rows'      => $rows,
			'truncated' => $truncated,
		);
	}

	// Column detection.

	/**
	 * Header synonyms for each concept ProfitGuard understands.
	 *
	 * Matched against a normalised header (lowercased, punctuation collapsed),
	 * so "Cost price (net)" and "cost_price_net" both reach the same key.
	 * Languages: EN, DE, FR, ES, IT, NL, HR/BS/SR, PL.
	 *
	 * @var array<string, string[]>
	 */
	private const HEADER_SYNONYMS = array(
		'sku'         => array(
			'sku',
			'item number',
			'itemnumber',
			'article',
			'article number',
			'articlenumber',
			'product code',
			'productcode',
			'code',
			'reference',
			'ref',
			'part number',
			'artikelnummer',
			'artikel',
			'referencia',
			'codice',
			'riferimento',
			'sifra',
			'sifra artikla',
			'kod',
			'indeks',
			'symbol',
		),
		'cost'        => array(
			'cost',
			'cost price',
			'costprice',
			'unit cost',
			'unitcost',
			'cogs',
			'purchase price',
			'purchaseprice',
			'buy price',
			'buying price',
			'net cost',
			'wholesale price',
			'supplier price',
			'ek',
			'einkaufspreis',
			'kosten',
			'prix achat',
			'prix d achat',
			'precio costo',
			'costo',
			'prezzo acquisto',
			'inkoopprijs',
			'nabavna cijena',
			'nabavna',
			'cena zakupu',
		),
		'currency'    => array(
			'currency',
			'curr',
			'ccy',
			'waehrung',
			'wahrung',
			'devise',
			'moneda',
			'valuta',
			'valuta oznaka',
		),
		'product_id'  => array(
			'product id',
			'productid',
			'id',
			'post id',
			'postid',
			'wc id',
			'variation id',
			'variationid',
		),
		'name'        => array(
			'name',
			'product',
			'product name',
			'productname',
			'title',
			'description',
			'bezeichnung',
			'artikelbezeichnung',
			'nom',
			'nombre',
			'nome',
			'naziv',
			'nazwa',
		),
		'order'       => array(
			'order',
			'order number',
			'ordernumber',
			'order id',
			'orderid',
			'order no',
			'orderno',
			'reference',
			'bestellnummer',
			'commande',
			'pedido',
			'ordine',
			'broj narudzbe',
			'narudzba',
			'zamowienie',
		),
		'tracking'    => array(
			'tracking',
			'tracking number',
			'trackingnumber',
			'tracking no',
			'trackingno',
			'awb',
			'consignment',
			'consignment number',
			'waybill',
			'parcel number',
			'sendungsnummer',
			'suivi',
			'seguimiento',
			'tracciatura',
			'broj posiljke',
		),
		'carrier'     => array(
			'carrier',
			'courier',
			'shipping carrier',
			'transporter',
			'transport',
			'spediteur',
			'transporteur',
			'transportista',
			'corriere',
			'prijevoznik',
			'przewoznik',
		),
		'actual_cost' => array(
			'actual shipping cost',
			'shipping cost',
			'shippingcost',
			'carrier cost',
			'carriercost',
			'freight',
			'freight cost',
			'actual cost',
			'net charge',
			'total charge',
			'amount',
			'versandkosten',
			'frachtkosten',
			'cout transport',
			'coste envio',
			'costo spedizione',
			'trosak dostave',
			'koszt wysylki',
		),
		'surcharge'   => array(
			'surcharge',
			'surcharges',
			'fuel surcharge',
			'accessorial',
			'extra',
			'zuschlag',
			'supplement',
			'recargo',
			'supplemento',
			'doplata',
		),
		'adjustment'  => array(
			'adjustment',
			'adjustments',
			'correction',
			'anpassung',
			'ajustement',
			'ajuste',
			'rettifica',
			'ispravak',
			'korekta',
		),
		'date'        => array(
			'date',
			'shipping date',
			'shipdate',
			'ship date',
			'invoice date',
			'datum',
			'fecha',
			'data',
			'datum otpreme',
		),
	);

	/**
	 * Normalise a header for matching.
	 *
	 * Folds the letters that NFD does NOT decompose before stripping accents.
	 * "ss", "d", "o" are distinct letters rather than accented ones, so a plain
	 * accent strip deletes them outright and "Grosse" or "Sifra" stops matching.
	 *
	 * @param string $header Raw header.
	 * @return string Normalised header.
	 */
	public static function normalise_header( string $header ): string {
		$header = self::strip_bom( $header );
		$header = mb_strtolower( trim( $header ), 'UTF-8' );

		$folds  = array(
			'ß' => 'ss',
			'đ' => 'd',
			'ø' => 'o',
			'å' => 'a',
			'æ' => 'ae',
			'œ' => 'oe',
			'ł' => 'l',
			'ä' => 'a',
			'ö' => 'o',
			'ü' => 'u',
			'á' => 'a',
			'à' => 'a',
			'â' => 'a',
			'é' => 'e',
			'è' => 'e',
			'ê' => 'e',
			'í' => 'i',
			'ì' => 'i',
			'ó' => 'o',
			'ò' => 'o',
			'ô' => 'o',
			'ú' => 'u',
			'ù' => 'u',
			'ç' => 'c',
			'ć' => 'c',
			'č' => 'c',
			'š' => 's',
			'ž' => 'z',
			'ń' => 'n',
			'ś' => 's',
			'ź' => 'z',
			'ż' => 'z',
			'ą' => 'a',
			'ę' => 'e',
		);
		$header = strtr( $header, $folds );

		$header = preg_replace( '/[^a-z0-9]+/', ' ', $header );
		$header = preg_replace( '/\s+/', ' ', (string) $header );

		return trim( (string) $header );
	}

	/**
	 * Suggest a column index for each concept, from a header row.
	 *
	 * Exact synonym matches win over substring matches, and the first column to
	 * claim a concept keeps it, so a file with both "Cost" and "Cost centre"
	 * maps "Cost".
	 *
	 * @param string[] $headers Header row.
	 * @return array<string, int> Concept => zero-based column index.
	 */
	public static function suggest_columns( array $headers ): array {
		$normalised = array();
		foreach ( $headers as $index => $header ) {
			$normalised[ $index ] = self::normalise_header( (string) $header );
		}

		$mapping = array();
		$claimed = array();

		// Pass 1: exact synonym match.
		foreach ( self::HEADER_SYNONYMS as $concept => $synonyms ) {
			foreach ( $normalised as $index => $header ) {
				if ( isset( $claimed[ $index ] ) || '' === $header ) {
					continue;
				}
				if ( in_array( $header, $synonyms, true ) ) {
					$mapping[ $concept ] = (int) $index;
					$claimed[ $index ]   = true;
					break;
				}
			}
		}

		// Pass 2: substring match, longest synonym first so "actual shipping
		// cost" beats "cost" for the same header.
		foreach ( self::HEADER_SYNONYMS as $concept => $synonyms ) {
			if ( isset( $mapping[ $concept ] ) ) {
				continue;
			}
			$sorted = $synonyms;
			usort(
				$sorted,
				static function ( $a, $b ) {
					return strlen( $b ) <=> strlen( $a );
				}
			);
			foreach ( $normalised as $index => $header ) {
				if ( isset( $claimed[ $index ] ) || '' === $header ) {
					continue;
				}
				foreach ( $sorted as $synonym ) {
					if ( false !== strpos( $header, $synonym ) ) {
						$mapping[ $concept ] = (int) $index;
						$claimed[ $index ]   = true;
						break 2;
					}
				}
			}
		}//end foreach

		return $mapping;
	}

	// Writing.

	/**
	 * Make a cell safe to write into a CSV a spreadsheet will open.
	 *
	 * CSV INJECTION. A cell beginning with =, +, -, @, tab or carriage return
	 * is interpreted as a FORMULA by Excel, LibreOffice and Google Sheets. A
	 * product name of `=HYPERLINK("http://evil","Click")` in a merchant's own
	 * catalog becomes a live link in the exported report, and worse payloads
	 * exist. Since product names come from whoever can edit products, an export
	 * is a path from one user's input to another user's spreadsheet.
	 *
	 * Prefixing with a single quote makes the spreadsheet treat it as text. The
	 * quote is visible in the cell, which is the correct trade: a slightly ugly
	 * cell beats executing someone's payload.
	 *
	 * Note this is NOT the same as CSV quoting - fputcsv already handles commas
	 * and quotes. This is about what the spreadsheet does after parsing.
	 *
	 * @param scalar|null $value Cell value.
	 * @return string Safe cell value.
	 */
	public static function escape_cell( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		$text = (string) $value;
		if ( '' === $text ) {
			return '';
		}

		$first = substr( $text, 0, 1 );
		if ( in_array( $first, self::FORMULA_TRIGGERS, true ) ) {
			return "'" . $text;
		}

		return $text;
	}

	/**
	 * Build a CSV document from a header row and data rows.
	 *
	 * Every cell goes through escape_cell(). Writing a CSV by concatenating
	 * strings is what produces broken files the first time a product name
	 * contains a comma, so fputcsv does the quoting.
	 *
	 * @param string[]             $headers Header row.
	 * @param array<int, scalar[]> $rows    Data rows.
	 * @return string CSV document.
	 */
	public static function build( array $headers, array $rows ): string {
		$handle = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory stream, not a file on disk. WP_Filesystem has no equivalent and nothing here touches the filesystem.
		if ( false === $handle ) {
			return '';
		}

		// A BOM so Excel opens UTF-8 correctly. Without it, accented product
		// names are mojibake on a default Windows install.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://memory stream, not a file on disk.
		fwrite( $handle, "\xEF\xBB\xBF" );

		fputcsv( $handle, array_map( array( self::class, 'escape_cell' ), $headers ) );
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_map( array( self::class, 'escape_cell' ), $row ) );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://memory stream, not a file on disk. WP_Filesystem has no equivalent and nothing here touches the filesystem.

		return false === $csv ? '' : $csv;
	}
}
