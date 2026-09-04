<?php
/**
 * WooCommerce's own Cost of Goods Sold feature.
 *
 * Added to core in WooCommerce 10.3, but opt-in and disabled by default, so
 * most stores still have no cost data - which is why ProfitGuard's
 * missing-cost detection stays useful. The three measured API facts that shape
 * this class are documented on the class below.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Woo;

use ProfitGuard\Core\CogsResolution;
use ProfitGuard\Core\Money;
use Throwable;
use WC_Abstract_Order;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes cost through WooCommerce's native COGS API.
 *
 * WooCommerce 10.3 (October 2025) moved Cost of Goods Sold out of experimental
 * and into core. So a cost field now exists - but it is OPT-IN, disabled by
 * default, under WooCommerce -> Settings -> Advanced -> Features. Most stores
 * therefore still have no cost data, which is why ProfitGuard's missing-cost
 * detection stays useful, and why this class has to handle three store states
 * rather than two.
 *
 * Everything here is measured behaviour, recorded in
 * tests/cogs/MEASURED-FACTS.md against WooCommerce 11.1.0. Three measurements
 * shape the implementation:
 *
 *  1. `get_cogs_effective_value()` returns 0.0 for a product with no cost,
 *     which is indistinguishable from a genuine zero and would produce a
 *     confident 100% margin. This class NEVER calls it. It reads
 *     `get_cogs_value()`, which returns null when there is no cost.
 *
 *  2. The product getters apply neither parent-default inheritance nor the
 *     additive flag; core applies both when it builds an order item. So the
 *     resolution is done here, by Core\CogsResolution, to core's measured
 *     rule - otherwise product-level margins and WooCommerce's own analytics
 *     disagree on every variable product.
 *
 *  3. With the feature disabled, `get_cogs_value()` returns null even for a
 *     product that demonstrably holds a stored value. Reads are therefore
 *     gated on the feature being enabled, and nothing is ever written into a
 *     disabled feature's storage.
 *
 * @package ProfitGuard
 */
final class NativeCogs {

	/**
	 * The core feature slug.
	 */
	public const FEATURE = 'cost_of_goods_sold';

	/**
	 * Cached answer for this request, or null when not yet resolved.
	 *
	 * Resolving goes through the WooCommerce container, and the scanner asks
	 * once per product across a whole catalogue.
	 *
	 * @var bool|null
	 */
	private static $enabled;

	/**
	 * Is WooCommerce's native COGS feature switched on for this store?
	 *
	 * Asked through FeaturesController rather than by reading the option or
	 * sniffing `_cogs_total_value`, so the storage stays WooCommerce's business
	 * and this keeps working if they change where the flag lives.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( null !== self::$enabled ) {
			return self::$enabled;
		}

		self::$enabled = false;

		$controller_class = 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController';
		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $controller_class ) ) {
			// WooCommerce older than 10.3, or too early in the request for the
			// container. Either way there is no native feature to use.
			return self::$enabled;
		}

		try {
			$controller = wc_get_container()->get( $controller_class );
			if ( is_object( $controller ) && method_exists( $controller, 'feature_is_enabled' ) ) {
				self::$enabled = (bool) $controller->feature_is_enabled( self::FEATURE );
			}
		} catch ( Throwable $e ) {
			// A container that cannot resolve the controller is not a reason to
			// fail a scan; it means "no native feature available", and the
			// caller falls back to ProfitGuard's own cost meta.
			self::$enabled = false;
		}

		return self::$enabled;
	}

	/**
	 * Forget the cached feature state.
	 *
	 * Only for tests and for the settings screen, which can toggle the feature
	 * inside a single request.
	 */
	public static function reset_feature_cache(): void {
		self::$enabled = null;
	}

	/**
	 * Does WooCommerce expose a native cost for products at all?
	 *
	 * Distinct from is_enabled(): this asks whether the API exists, which is
	 * what decides whether the merchant can be told the setting is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return method_exists( 'WC_Product', 'get_cogs_value' );
	}

	/**
	 * The native cost for a product or variation, in minor units.
	 *
	 * Returns null for cost_minor when the store has no native cost for this
	 * product. Null means unknown; it never means zero.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array{cost_minor:int|null,basis:string}
	 */
	public static function get_cost( WC_Product $product ): array {
		if ( ! self::is_enabled() || ! self::is_available() ) {
			return array(
				'cost_minor' => null,
				'basis'      => CogsResolution::BASIS_UNKNOWN,
			);
		}

		$own_minor = self::read_value( $product );

		// Only a variation has a parent whose cost acts as a default.
		$parent_minor = null;
		$is_additive  = false;

		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( $parent_id > 0 ) {
				$parent = wc_get_product( $parent_id );
				if ( $parent instanceof WC_Product ) {
					$parent_minor = self::read_value( $parent );
				}
			}
			if ( method_exists( $product, 'get_cogs_value_is_additive' ) ) {
				$is_additive = (bool) $product->get_cogs_value_is_additive();
			}
		}

		return CogsResolution::resolve( $own_minor, $parent_minor, $is_additive );
	}

	/**
	 * Read one product's own native cost, with no inheritance applied.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return int|null Minor units, or null when there is no value.
	 */
	private static function read_value( WC_Product $product ): ?int {
		if ( ! method_exists( $product, 'get_cogs_value' ) ) {
			return null;
		}

		$value = $product->get_cogs_value();
		if ( null === $value || '' === $value ) {
			return null;
		}

		// Floats arrive from the CRUD API; parse_decimal_to_minor scales by 100
		// and rounds half away from zero, so no float is ever stored or summed.
		return Money::parse_decimal_to_minor( is_scalar( $value ) ? (float) $value : null );
	}

	/**
	 * Write a cost through WooCommerce's own API.
	 *
	 * Deliberately uses set_cogs_value() rather than a parallel ProfitGuard
	 * meta key: two cost figures disagreeing in the product editor are worse
	 * for a merchant than a single one they can trust.
	 *
	 * @param WC_Product $product    Product or variation.
	 * @param int        $cost_minor Cost in minor units.
	 * @return bool True when the value was written.
	 */
	public static function set_cost( WC_Product $product, int $cost_minor ): bool {
		if ( ! self::is_enabled() || ! self::is_available() || $cost_minor < 0 ) {
			return false;
		}
		if ( ! method_exists( $product, 'set_cogs_value' ) ) {
			return false;
		}

		$product->set_cogs_value( $cost_minor / 100 );
		$product->save();

		return true;
	}

	/**
	 * The order's own COGS total, in minor units.
	 *
	 * Preferred over recomputing from products so ProfitGuard's shipping and
	 * margin figures reconcile with WooCommerce's analytics rather than quietly
	 * disagreeing with them. Returns null when the order carries no COGS, which
	 * is the signal to fall back to per-item resolution.
	 *
	 * Note the measured detail: an order ITEM's COGS value is already
	 * multiplied by quantity, so nothing downstream may multiply again.
	 *
	 * @param WC_Abstract_Order $order Order.
	 * @return int|null Minor units, or null when the order has no COGS.
	 */
	public static function get_order_total( WC_Abstract_Order $order ): ?int {
		if ( ! self::is_enabled() ) {
			return null;
		}
		if ( ! method_exists( $order, 'has_cogs' ) || ! method_exists( $order, 'get_cogs_total_value' ) ) {
			return null;
		}
		if ( ! $order->has_cogs() ) {
			return null;
		}

		$value = $order->get_cogs_total_value();
		if ( null === $value || '' === $value ) {
			return null;
		}

		return Money::parse_decimal_to_minor( is_scalar( $value ) ? (float) $value : null );
	}
}
