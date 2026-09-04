<?php
/**
 * File upload handling and the two importers.
 *
 * SECURITY. An upload endpoint is the most dangerous thing in this plugin, so
 * every one of these applies before a single byte is parsed:
 *
 *  - capability check and nonce (in the admin controller that calls this),
 *  - the file must arrive through $_FILES with no upload error,
 *  - size cap, checked against the real file, not a client-supplied header,
 *  - extension and MIME validated by wp_check_filetype_and_ext(), which reads
 *    the file rather than trusting its name,
 *  - the file is read with a bounded read and never executed, included or
 *    unserialised,
 *  - nothing is written to a public directory: the file is parsed from the
 *    PHP temp path and discarded.
 *
 * TWO-STEP BY DESIGN. Upload produces a PREVIEW; nothing is written until the
 * merchant confirms. The brief requires it, and it is the difference between a
 * mis-mapped column being an inconvenience and being a silent corruption of
 * every cost in the store.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Import;

use ProfitGuard\Core\Csv;
use ProfitGuard\Plugin\Repository;
use ProfitGuard\Woo\Catalog;
use ProfitGuard\Woo\CostProvider;
use ProfitGuard\Woo\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * CSV import for product costs and carrier costs.
 */
final class Importer {

	/** Hard cap on upload size. Generous for a CSV, small enough to be safe. */
	public const MAX_BYTES = 5242880;
	// 5 MB.

	/** Cap on rows parsed from one file. */
	public const MAX_ROWS = 20000;

	/** Transient prefix for a pending preview. */
	public const PREVIEW_PREFIX = 'profitguard_preview_';

	/** How long a pending preview survives. */
	public const PREVIEW_TTL = 1800;
	// 30 minutes.

	public const KIND_COST    = 'cost';
	public const KIND_CARRIER = 'carrier';

	/**
	 * Validate an upload and return its contents.
	 *
	 * @param array<string, mixed> $file One entry from $_FILES.
	 * @return array{ok:bool,contents:string,error:string}
	 */
	public static function read_upload( array $file ): array {
		$fail = static function ( string $error ): array {
			return array(
				'ok'       => false,
				'contents' => '',
				'error'    => $error,
			);
		};

		if ( ! isset( $file['tmp_name'], $file['name'], $file['error'] ) ) {
			return $fail( 'no_file' );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return $fail( UPLOAD_ERR_INI_SIZE === (int) $file['error'] ? 'too_large' : 'upload_failed' );
		}

		$tmp = (string) $file['tmp_name'];

		// The file must be one PHP itself received. Without this a crafted
		// request could point tmp_name at an arbitrary server path and have it
		// read back.
		if ( ! is_uploaded_file( $tmp ) ) {
			return $fail( 'not_an_upload' );
		}

		$size = (int) filesize( $tmp );
		if ( $size <= 0 ) {
			return $fail( 'empty' );
		}
		if ( $size > self::MAX_BYTES ) {
			return $fail( 'too_large' );
		}

		/*
		 * wp_check_filetype_and_ext() inspects the file, not just its name, and
		 * is what stops a .php renamed to .csv. CSV is a text format with no
		 * reliable magic bytes, so WordPress can return a null type for a
		 * perfectly good file - hence the extension check alongside rather than
		 * relying on either alone.
		 */
		$name      = sanitize_file_name( (string) $file['name'] );
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'txt' ), true ) ) {
			return $fail( 'not_csv' );
		}

		$checked = wp_check_filetype_and_ext(
			$tmp,
			$name,
			array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			)
		);
		if ( ! empty( $checked['proper_filename'] ) ) {
			// WordPress renamed it because the contents disagreed with the
			// extension. That is exactly the case to refuse.
			return $fail( 'not_csv' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading PHP's own upload temp file with a byte cap. wp_remote_get() is for URLs; WP_Filesystem cannot bound the read.
		$contents = file_get_contents( $tmp, false, null, 0, self::MAX_BYTES );
		if ( false === $contents || '' === $contents ) {
			return $fail( 'unreadable' );
		}

		// Reject anything with a NUL byte: a text CSV has none, and a binary
		// file that reached this point should not be parsed as text.
		if ( false !== strpos( $contents, "\0" ) ) {
			return $fail( 'not_csv' );
		}

		return array(
			'ok'       => true,
			'contents' => $contents,
			'error'    => '',
		);
	}

	/**
	 * Build a preview from uploaded CSV text.
	 *
	 * @param string $contents CSV text.
	 * @param string $kind     KIND_COST or KIND_CARRIER.
	 * @return array{ok:bool,error:string,token:string,headers:string[],mapping:array<string,int>,sample:array<int,string[]>,row_count:int,truncated:bool}
	 */
	public static function build_preview( string $contents, string $kind ): array {
		$parsed = Csv::parse( $contents, null, self::MAX_ROWS );
		$rows   = $parsed['rows'];

		if ( count( $rows ) < 2 ) {
			return array(
				'ok'        => false,
				'error'     => 'no_rows',
				'token'     => '',
				'headers'   => array(),
				'mapping'   => array(),
				'sample'    => array(),
				'row_count' => 0,
				'truncated' => false,
			);
		}

		$headers = array_shift( $rows );
		$mapping = Csv::suggest_columns( $headers );

		/*
		 * The preview is held in a transient rather than re-uploaded on
		 * confirm. Storing the parsed rows means the merchant's column
		 * corrections are applied to exactly the data they previewed, and it
		 * avoids a second upload of a file we have already validated.
		 */
		$token = wp_generate_password( 20, false, false );
		set_transient(
			self::PREVIEW_PREFIX . $token,
			array(
				'kind'    => $kind,
				'headers' => $headers,
				'rows'    => $rows,
				'user'    => get_current_user_id(),
			),
			self::PREVIEW_TTL
		);

		return array(
			'ok'        => true,
			'error'     => '',
			'token'     => $token,
			'headers'   => $headers,
			'mapping'   => $mapping,
			'sample'    => array_slice( $rows, 0, 10 ),
			'row_count' => count( $rows ),
			'truncated' => (bool) $parsed['truncated'],
		);
	}

	/**
	 * Retrieve a pending preview.
	 *
	 * The stored user id is re-checked: a transient key is a bearer token, and
	 * one administrator should not be able to commit another's pending import
	 * by guessing or reusing it.
	 *
	 * @param string $token Preview token.
	 * @return array<string, mixed>|null
	 */
	public static function get_preview( string $token ): ?array {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', $token );
		if ( '' === (string) $token ) {
			return null;
		}
		$data = get_transient( self::PREVIEW_PREFIX . $token );
		if ( ! is_array( $data ) || ! isset( $data['rows'] ) ) {
			return null;
		}
		if ( (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
			return null;
		}
		return $data;
	}

	/**
	 * Discard a pending preview.
	 *
	 * @param string $token Preview token.
	 */
	public static function forget_preview( string $token ): void {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', $token );
		if ( '' !== (string) $token ) {
			delete_transient( self::PREVIEW_PREFIX . $token );
		}
	}

	// Committing.

	/**
	 * What a cost import would change, row by row, before anything is written.
	 *
	 * WooCommerce 10.3 put Cost of Goods Sold in core, so an imported cost can
	 * now land on top of a value the merchant entered in the product editor
	 * themselves. Replacing that silently is the one outcome this plugin must
	 * not produce, so every row is resolved against the store first and any row
	 * that would replace a non-null NATIVE cost is flagged. The commit refuses
	 * those rows unless the merchant has explicitly confirmed them.
	 *
	 * Rows whose cost is unchanged are still returned, marked, so the preview
	 * can say "no change" rather than implying an update.
	 *
	 * @param array<int, string[]> $rows    Data rows.
	 * @param array<string, int>   $mapping Concept => column index.
	 * @param int                  $limit   How many rows to describe.
	 * @return array{rows:array<int, array<string, mixed>>,native_overwrites:int,valid:int}
	 */
	public static function cost_change_plan( array $rows, array $mapping, int $limit = 25 ): array {
		$currency = get_woocommerce_currency();
		$mapped   = Mapper::map_cost_rows( $rows, $mapping, $currency );

		$described         = array();
		$native_overwrites = 0;

		foreach ( $mapped['valid'] as $row ) {
			$product_id = Catalog::id_from_sku( $row['sku'] );
			$current    = null;
			$source     = CostProvider::SOURCE_NONE;

			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$resolved = CostProvider::get_cost( $product );
					$current  = $resolved['cost_minor'];
					$source   = $resolved['source'];
				}
			}

			// Only a NON-NULL native cost counts as an overwrite. A native
			// field that is empty is not something the merchant set, so filling
			// it in is what they asked for by importing.
			$replaces_native = ( null !== $current )
				&& CostProvider::is_native_source( $source )
				&& $current !== $row['cost_minor'];

			if ( $replaces_native ) {
				++$native_overwrites;
			}

			if ( count( $described ) < $limit ) {
				$described[] = array(
					'row'             => $row['row'],
					'sku'             => $row['sku'],
					'product_id'      => $product_id,
					'current_minor'   => $current,
					'new_minor'       => $row['cost_minor'],
					'source'          => $source,
					'unmatched'       => 0 === $product_id,
					'unchanged'       => null !== $current && $current === $row['cost_minor'],
					'replaces_native' => $replaces_native,
				);
			}
		}

		return array(
			'rows'              => $described,
			'native_overwrites' => $native_overwrites,
			'valid'             => count( $mapped['valid'] ),
		);
	}

	/**
	 * Commit product costs.
	 *
	 * @param array<int, string[]> $rows                   Data rows.
	 * @param array<string, int>   $mapping                Concept => column index.
	 * @param bool                 $allow_native_overwrite Whether the merchant confirmed
	 *                                                     replacing costs held in
	 *                                                     WooCommerce's own COGS field.
	 *                                                     Defaults to false, so an
	 *                                                     unconfirmed import cannot
	 *                                                     replace one.
	 * @return array<string, mixed> Result summary.
	 */
	public static function commit_costs( array $rows, array $mapping, bool $allow_native_overwrite = false ): array {
		$currency = get_woocommerce_currency();
		$mapped   = Mapper::map_cost_rows( $rows, $mapping, $currency );

		$run_id = Repository::start_run( Repository::RUN_COST_IMPORT, array( 'rows' => count( $rows ) ) );

		$updated   = 0;
		$unchanged = 0;
		$unmatched = array();
		$blocked   = array();

		foreach ( $mapped['valid'] as $row ) {
			$product_id = Catalog::id_from_sku( $row['sku'] );
			if ( 0 === $product_id ) {
				// Kept and reported. A file where nothing matched is a mapping
				// mistake the merchant can fix in a minute; silently importing
				// zero rows looks like a broken plugin.
				$unmatched[] = array(
					'row' => $row['row'],
					'sku' => $row['sku'],
				);
				continue;
			}
			// A row that would replace a cost the merchant entered in
			// WooCommerce's own field is refused unless they confirmed it on
			// the preview screen. Refusing is not a failure: it is the
			// preview-then-confirm contract holding.
			if ( ! $allow_native_overwrite ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$resolved = CostProvider::get_cost( $product );
					if ( null !== $resolved['cost_minor']
						&& CostProvider::is_native_source( $resolved['source'] )
						&& $resolved['cost_minor'] !== $row['cost_minor'] ) {
						$blocked[] = array(
							'row' => $row['row'],
							'sku' => $row['sku'],
						);
						continue;
					}
				}
			}

			if ( CostProvider::set_cost( $product_id, $row['cost_minor'] ) ) {
				++$updated;
			} else {
				++$unchanged;
			}
		}

		$totals = array(
			'rows'      => count( $rows ),
			'valid'     => count( $mapped['valid'] ),
			'updated'   => $updated,
			'unchanged' => $unchanged,
			'unmatched' => count( $unmatched ),
			'rejected'  => count( $mapped['rejected'] ),
			'blocked'   => count( $blocked ),
			'details'   => array(
				'rejected'  => array_slice( $mapped['rejected'], 0, 50 ),
				'unmatched' => array_slice( $unmatched, 0, 50 ),
				'blocked'   => array_slice( $blocked, 0, 50 ),
			),
		);

		Repository::finish_run( $run_id, Repository::STATUS_COMPLETED, $totals );

		return $totals;
	}

	/**
	 * Commit carrier costs.
	 *
	 * @param array<int, string[]> $rows    Data rows.
	 * @param array<string, int>   $mapping Concept => column index.
	 * @return array<string, mixed> Result summary.
	 */
	public static function commit_carrier( array $rows, array $mapping ): array {
		$currency = get_woocommerce_currency();
		$mapped   = Mapper::map_carrier_rows( $rows, $mapping, $currency );

		$run_id = Repository::start_run( Repository::RUN_CARRIER_IMPORT, array( 'rows' => count( $rows ) ) );

		$inserted  = 0;
		$duplicate = 0;
		$matched   = 0;
		$unmatched = 0;

		foreach ( $mapped['valid'] as $row ) {
			$order_id = Orders::id_from_reference( (string) $row['order_reference'] );
			if ( $order_id > 0 ) {
				++$matched;
			} else {
				++$unmatched;
			}

			$row['order_id']  = $order_id;
			$row['import_id'] = $run_id;
			$row['row_hash']  = Mapper::carrier_row_hash( $row );

			$result = Repository::insert_carrier_row( $row );
			if ( 'inserted' === $result ) {
				++$inserted;
			} elseif ( 'duplicate' === $result ) {
				++$duplicate;
			}
		}

		$totals = array(
			'rows'      => count( $rows ),
			'valid'     => count( $mapped['valid'] ),
			'inserted'  => $inserted,
			'duplicate' => $duplicate,
			'matched'   => $matched,
			'unmatched' => $unmatched,
			'rejected'  => count( $mapped['rejected'] ),
			'details'   => array(
				'rejected' => array_slice( $mapped['rejected'], 0, 50 ),
			),
		);

		Repository::finish_run( $run_id, Repository::STATUS_COMPLETED, $totals );

		return $totals;
	}

	/**
	 * Human-readable reason for a rejected row.
	 *
	 * Lives here rather than in Mapper so the pure core stays free of
	 * translated strings.
	 *
	 * @param string $reason A Mapper::REASON_* constant.
	 * @return string
	 */
	public static function reason_label( string $reason ): string {
		switch ( $reason ) {
			case Mapper::REASON_NO_SKU:
				return __( 'No SKU in this row', 'profitguard-for-woocommerce' );
			case Mapper::REASON_NO_COST:
				return __( 'No cost value', 'profitguard-for-woocommerce' );
			case Mapper::REASON_BAD_COST:
				return __( 'Cost could not be read as a number', 'profitguard-for-woocommerce' );
			case Mapper::REASON_NEGATIVE_COST:
				return __( 'Cost is negative', 'profitguard-for-woocommerce' );
			case Mapper::REASON_CURRENCY_MISMATCH:
				return __( 'Currency does not match your store currency', 'profitguard-for-woocommerce' );
			case Mapper::REASON_DUPLICATE_SKU:
				return __( 'This SKU appears more than once in the file', 'profitguard-for-woocommerce' );
			case Mapper::REASON_NO_ORDER_REF:
				return __( 'No order number or tracking number', 'profitguard-for-woocommerce' );
			case Mapper::REASON_NO_AMOUNT:
				return __( 'No shipping cost amount', 'profitguard-for-woocommerce' );
			default:
				return __( 'Row could not be used', 'profitguard-for-woocommerce' );
		}
	}

	/**
	 * Human-readable reason for a failed upload.
	 *
	 * @param string $error Error key from read_upload().
	 * @return string
	 */
	public static function upload_error_label( string $error ): string {
		switch ( $error ) {
			case 'too_large':
				return sprintf(
					/* translators: %s: maximum file size, already formatted. */
					__( 'That file is larger than the %s limit.', 'profitguard-for-woocommerce' ),
					size_format( self::MAX_BYTES )
				);
			case 'not_csv':
				return __( 'That does not look like a CSV file. Export your spreadsheet as CSV and try again.', 'profitguard-for-woocommerce' );
			case 'empty':
				return __( 'That file is empty.', 'profitguard-for-woocommerce' );
			case 'no_rows':
				return __( 'That file has no data rows below its header.', 'profitguard-for-woocommerce' );
			case 'no_file':
				return __( 'No file was received.', 'profitguard-for-woocommerce' );
			default:
				return __( 'That file could not be read. Please try again.', 'profitguard-for-woocommerce' );
		}
	}
}
