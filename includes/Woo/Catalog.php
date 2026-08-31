<?php
/**
 * Reading products out of WooCommerce, in batches.
 *
 * BATCHING IS NOT OPTIONAL. A store with 20,000 variations will exhaust PHP's
 * memory limit if the scan calls wc_get_products() without a limit, and the
 * merchant sees a white screen with no explanation. Every read here is bounded
 * and paged, and the scanner drives it one page at a time through Action
 * Scheduler.
 *
 * IDS FIRST, OBJECTS SECOND. The paging query asks for ids only
 * (`return => 'ids'`), then hydrates one page at a time. Asking WooCommerce for
 * 20,000 hydrated product objects to count them is the most common way a
 * reporting plugin takes a store down.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Woo;

use ProfitGuard\Core\Money;
use WC_Product;
use WC_Product_Variable;

defined( 'ABSPATH' ) || exit;

/**
 * Product reading and normalisation.
 */
final class Catalog {

	/**
	 * How many products to hydrate at once.
	 *
	 * Deliberately modest. A larger page is faster on a healthy host and is the
	 * difference between working and fataling on a cheap one, and this plugin
	 * is aimed at stores on cheap hosts.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Count sellable products and variations.
	 *
	 * Counts the same population the scan will walk, so the progress figure and
	 * the result cannot disagree.
	 *
	 * @return int
	 */
	public static function count_sellable(): int {
		$ids = self::sellable_ids( 0, 0 );
		return count( $ids );
	}

	/**
	 * Ids of every sellable product and variation.
	 *
	 * A VARIABLE parent is excluded and its variations included instead: the
	 * parent has no price or cost of its own, so scoring it would add a
	 * meaningless MISSING_COST row for every variable product in the store.
	 *
	 * @param int $offset Offset, applied after the full id list is assembled.
	 * @param int $limit  Limit, or 0 for all.
	 * @return int[]
	 */
	public static function sellable_ids( int $offset = 0, int $limit = 0 ): array {
		$parent_ids = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		if ( ! is_array( $parent_ids ) ) {
			return array();
		}

		$ids = array();
		foreach ( $parent_ids as $parent_id ) {
			$product = wc_get_product( $parent_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( $product instanceof WC_Product_Variable ) {
				foreach ( $product->get_children() as $child_id ) {
					$ids[] = (int) $child_id;
				}
				continue;
			}
			$ids[] = (int) $parent_id;
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		if ( $limit > 0 ) {
			return array_slice( $ids, $offset, $limit );
		}
		return $offset > 0 ? array_slice( $ids, $offset ) : $ids;
	}

	/**
	 * Normalise one product into the plain array the margin engine takes.
	 *
	 * The engine is WordPress-free by design, so nothing WooCommerce-shaped
	 * crosses this boundary: what comes out is integers, strings and nulls.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array{
	 *     id:int,
	 *     parent_id:int,
	 *     type:string,
	 *     sku:string,
	 *     name:string,
	 *     regular_price_minor:int|null,
	 *     sale_price_minor:int|null,
	 *     price_minor:int|null,
	 *     cost_minor:int|null,
	 *     cost_source:string,
	 *     previous_cost_minor:int|null,
	 *     is_on_sale:bool,
	 *     stock_status:string,
	 *     category_ids:int[]
	 * }
	 */
	public static function normalise( WC_Product $product ): array {
		$parent_id = $product->get_parent_id() > 0 ? (int) $product->get_parent_id() : (int) $product->get_id();
		$cost      = CostProvider::get_cost( $product );

		/*
		 * wc_get_price_excluding_tax() rather than get_price().
		 *
		 * A store configured to enter prices INCLUSIVE of tax returns a
		 * tax-inclusive figure from get_price(), and comparing that against a
		 * net supplier cost overstates every margin in the store by the VAT
		 * rate. Costs are entered net, so the selling price has to be net too.
		 */
		$regular = self::price_to_minor( $product, $product->get_regular_price() );
		$sale    = self::price_to_minor( $product, $product->get_sale_price() );
		$active  = self::price_to_minor( $product, $product->get_price() );

		$category_ids = array();
		$terms        = get_the_terms( $parent_id, 'product_cat' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$category_ids[] = (int) $term->term_id;
			}
		}

		return array(
			'id'                  => (int) $product->get_id(),
			'parent_id'           => $parent_id,
			'type'                => (string) $product->get_type(),
			'sku'                 => (string) $product->get_sku(),
			'name'                => (string) $product->get_name(),
			'regular_price_minor' => $regular,
			'sale_price_minor'    => $sale,
			'price_minor'         => $active,
			'cost_minor'          => $cost['cost_minor'],
			'cost_source'         => $cost['source'],
			'previous_cost_minor' => CostProvider::get_previous_cost( (int) $product->get_id() ),
			'is_on_sale'          => (bool) $product->is_on_sale(),
			'stock_status'        => (string) $product->get_stock_status(),
			'category_ids'        => $category_ids,
		);
	}

	/**
	 * Convert a WooCommerce price string to net minor units.
	 *
	 * Returns null for an empty price. An empty price is NOT zero: a product
	 * with no price set is not free, it is unpriced, and treating it as free
	 * would report every unconfigured draft as a catastrophic negative margin.
	 *
	 * @param WC_Product  $product Product, needed for tax context.
	 * @param string|null $price   Raw price string from WooCommerce.
	 * @return int|null
	 */
	private static function price_to_minor( WC_Product $product, $price ): ?int {
		if ( null === $price || '' === $price ) {
			return null;
		}

		$net = wc_get_price_excluding_tax( $product, array( 'price' => $price ) );
		if ( '' === $net || null === $net ) {
			return null;
		}

		// wc_get_price_excluding_tax returns a float. Formatting it to the
		// store's decimal precision first, then parsing the string, avoids the
		// binary-float error that multiplying by 100 would introduce.
		$formatted = wc_format_decimal( $net, wc_get_price_decimals() );

		return Money::parse_decimal_to_minor( (string) $formatted );
	}

	/**
	 * Find a product or variation id by SKU.
	 *
	 * @param string $sku SKU.
	 * @return int 0 when not found.
	 */
	public static function id_from_sku( string $sku ): int {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return 0;
		}
		$id = wc_get_product_id_by_sku( $sku );
		return $id ? (int) $id : 0;
	}
}
