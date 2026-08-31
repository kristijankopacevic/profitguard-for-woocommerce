<?php
/**
 * Reading orders out of WooCommerce, in batches.
 *
 * HPOS. Every read here goes through `wc_get_orders()` and the `WC_Order` CRUD
 * API, never through a `wp_posts` / `wp_postmeta` query. That is the entire
 * reason this plugin can honestly declare HPOS compatibility: the CRUD layer
 * returns the same objects whether the store keeps orders in posts or in the
 * custom order tables, so there is no second code path to keep in step and no
 * silent breakage when a merchant flips the setting.
 *
 * A direct `SELECT ... FROM wp_posts WHERE post_type = 'shop_order'` returns
 * zero rows on an HPOS store, which is the worst possible failure: no error,
 * just a report that says the store has no orders.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Woo;

use Automattic\WooCommerce\Utilities\OrderUtil;
use ProfitGuard\Core\Money;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Order reading and normalisation.
 */
final class Orders {

	/**
	 * How many orders to hydrate at once.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Order statuses worth analysing.
	 *
	 * Cancelled, failed and refunded orders are excluded: they are not sales,
	 * and a refunded order would otherwise appear as a shipping catastrophe.
	 * Pending and on-hold are excluded because they may never be paid.
	 *
	 * @return string[]
	 */
	public static function analysable_statuses(): array {
		/**
		 * Filter which order statuses ProfitGuard analyses.
		 *
		 * @since 1.0.0
		 * @param string[] $statuses Status slugs, without the `wc-` prefix.
		 */
		$statuses = apply_filters(
			'profitguard_analysable_order_statuses',
			array( 'processing', 'completed' )
		);
		return is_array( $statuses ) ? array_values( array_map( 'strval', $statuses ) ) : array( 'processing', 'completed' );
	}

	/**
	 * Whether the store is using High-Performance Order Storage.
	 *
	 * Reported on the dashboard so a merchant filing a support request can say
	 * which backend they are on without being asked.
	 *
	 * @return bool
	 */
	public static function hpos_enabled(): bool {
		if ( ! class_exists( OrderUtil::class ) ) {
			return false;
		}
		return OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Count analysable orders.
	 *
	 * @param int $days How many days back to look, or 0 for all time.
	 * @return int
	 */
	public static function count_analysable( int $days = 0 ): int {
		$args = self::query_args( $days );
		// `return => ids` with `limit => -1` is far cheaper than hydrating.
		$args['limit']  = -1;
		$args['return'] = 'ids';

		$ids = wc_get_orders( $args );
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * Ids of analysable orders, one page at a time.
	 *
	 * @param int $page  1-based page number.
	 * @param int $limit Page size.
	 * @param int $days  How many days back, or 0 for all time.
	 * @return int[]
	 */
	public static function ids_page( int $page, int $limit, int $days = 0 ): array {
		$args           = self::query_args( $days );
		$args['limit']  = max( 1, $limit );
		$args['paged']  = max( 1, $page );
		$args['return'] = 'ids';

		$ids = wc_get_orders( $args );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Shared query arguments.
	 *
	 * `orderby => ID` gives a STABLE order across pages. The WooCommerce
	 * default is by date, and paging a date-ordered set while new orders arrive
	 * silently skips rows - a scan running during business hours would miss
	 * orders and nobody would notice.
	 *
	 * @param int $days How many days back, or 0 for all time.
	 * @return array<string, mixed>
	 */
	private static function query_args( int $days ): array {
		$args = array(
			'status'  => self::analysable_statuses(),
			'type'    => 'shop_order',
			'orderby' => 'ID',
			'order'   => 'ASC',
		);

		if ( $days > 0 ) {
			$args['date_created'] = '>' . ( time() - ( $days * DAY_IN_SECONDS ) );
		}

		return $args;
	}

	/**
	 * Normalise one order into the plain array the shipping engine takes.
	 *
	 * @param WC_Order $order Order.
	 * @return array{
	 *     id:int,
	 *     number:string,
	 *     total_minor:int|null,
	 *     shipping_charged_minor:int|null,
	 *     shipping_method:string,
	 *     currency:string,
	 *     status:string,
	 *     date_created:string
	 * }
	 */
	public static function normalise( WC_Order $order ): array {
		/*
		 * get_shipping_total() is the amount charged to the CUSTOMER, excluding
		 * tax. It is compared against a carrier invoice, which is also net, so
		 * the two sides match.
		 *
		 * An order with no shipping line at all returns '' - which is NOT the
		 * same as a zero charge. A digital order sold no shipping; a
		 * free-shipping order sold shipping worth zero. Only the second is a
		 * shipping loss when a carrier bill turns up.
		 */
		$raw_shipping = $order->get_shipping_total();
		$shipping     = ( '' === $raw_shipping || null === $raw_shipping )
			? null
			: Money::parse_decimal_to_minor( (string) wc_format_decimal( $raw_shipping, wc_get_price_decimals() ) );

		$methods = array();
		foreach ( $order->get_shipping_methods() as $method ) {
			$name = $method->get_method_title();
			if ( '' !== $name ) {
				$methods[] = $name;
			}
		}

		$created = $order->get_date_created();

		return array(
			'id'                     => (int) $order->get_id(),
			'number'                 => (string) $order->get_order_number(),
			'total_minor'            => Money::parse_decimal_to_minor(
				(string) wc_format_decimal( $order->get_total(), wc_get_price_decimals() )
			),
			'shipping_charged_minor' => $shipping,
			'shipping_method'        => implode( ', ', $methods ),
			'currency'               => (string) $order->get_currency(),
			'status'                 => (string) $order->get_status(),
			'date_created'           => $created ? $created->date( 'Y-m-d' ) : '',
		);
	}

	/**
	 * Find an order id from a reference on a carrier invoice.
	 *
	 * Carrier files reference orders inconsistently: "1842", "#1842",
	 * "ORD-1842". The order NUMBER is also not necessarily the order ID - many
	 * stores use a sequential-number plugin - so a numeric lookup alone would
	 * match the wrong order on those stores, which is worse than not matching
	 * at all.
	 *
	 * Strategy, cheapest and most certain first:
	 *   1. exact order number, via WooCommerce's own resolver where available
	 *   2. the digits alone, as an order id, CONFIRMED by re-reading that
	 *      order's number
	 *
	 * Returns 0 when nothing matched with confidence. An unmatched row is
	 * surfaced to the merchant rather than guessed.
	 *
	 * @param string $reference Raw reference from the file.
	 * @return int Order id, or 0.
	 */
	public static function id_from_reference( string $reference ): int {
		$reference = trim( $reference );
		if ( '' === $reference ) {
			return 0;
		}

		$stripped = ltrim( $reference, '#' );

		/**
		 * Filter order-reference resolution, for stores with a custom scheme.
		 *
		 * Return a positive order id to short-circuit, or 0 to continue.
		 *
		 * @since 1.0.0
		 * @param int    $order_id  Resolved id, 0 when unresolved.
		 * @param string $reference Raw reference from the carrier file.
		 */
		$filtered = (int) apply_filters( 'profitguard_order_id_from_reference', 0, $reference );
		if ( $filtered > 0 ) {
			return $filtered;
		}

		// Digits alone, treated as an id and then CONFIRMED. Without the
		// confirmation this silently matches the wrong order on any store whose
		// order numbers are not their ids.
		if ( preg_match( '/^\d+$/', $stripped ) ) {
			$candidate = wc_get_order( (int) $stripped );
			if ( $candidate instanceof WC_Order ) {
				$number = (string) $candidate->get_order_number();
				if ( $number === $stripped || ltrim( $number, '#' ) === $stripped ) {
					return (int) $candidate->get_id();
				}
			}
		}

		return 0;
	}
}
