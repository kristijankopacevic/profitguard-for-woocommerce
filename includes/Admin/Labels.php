<?php
/**
 * Translated labels for machine values.
 *
 * The analysis core stores machine constants (LOW_MARGIN, EVIDENCED_DIFFERENCE)
 * and never translated text. That is not tidiness: a translated string written
 * into the database is frozen in whatever language was active at scan time, and
 * changing the site language would leave a findings table half in German. The
 * translation happens here, at render time, every time.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Admin;

use ProfitGuard\Core\Finding;
use ProfitGuard\Core\Money;
use ProfitGuard\Woo\CostProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Machine value to human string.
 */
final class Labels {

	/**
	 * Finding type.
	 *
	 * @param string $type A Finding::TYPE_* constant.
	 * @return string
	 */
	public static function type( string $type ): string {
		switch ( $type ) {
			case Finding::TYPE_LOW_MARGIN:
				return __( 'Low margin', 'profitguard-for-woocommerce' );
			case Finding::TYPE_CRITICAL_MARGIN:
				return __( 'Critical margin', 'profitguard-for-woocommerce' );
			case Finding::TYPE_NEGATIVE_MARGIN:
				return __( 'Selling below cost', 'profitguard-for-woocommerce' );
			case Finding::TYPE_MISSING_COST:
				return __( 'No cost recorded', 'profitguard-for-woocommerce' );
			case Finding::TYPE_SALE_PRICE_MARGIN_RISK:
				return __( 'Sale price margin risk', 'profitguard-for-woocommerce' );
			case Finding::TYPE_COST_INCREASE:
				return __( 'Cost increase', 'profitguard-for-woocommerce' );
			case Finding::TYPE_SHIPPING_PROFIT:
				return __( 'Shipping covered', 'profitguard-for-woocommerce' );
			case Finding::TYPE_SHIPPING_LOSS:
				return __( 'Shipping loss', 'profitguard-for-woocommerce' );
			case Finding::TYPE_HIGH_SHIPPING_LOSS:
				return __( 'High shipping loss', 'profitguard-for-woocommerce' );
			case Finding::TYPE_MISSING_CARRIER_COST:
				return __( 'No carrier cost imported', 'profitguard-for-woocommerce' );
			case Finding::TYPE_POSSIBLE_DUPLICATE_CARRIER_ROW:
				return __( 'Possible duplicate carrier charge', 'profitguard-for-woocommerce' );
			case Finding::TYPE_UNMATCHED_CARRIER_ROW:
				return __( 'Carrier row matched no order', 'profitguard-for-woocommerce' );
			default:
				return $type;
		}//end switch
	}

	/**
	 * A one-line explanation of what the merchant should do.
	 *
	 * @param string $type A Finding::TYPE_* constant.
	 * @return string
	 */
	public static function action( string $type ): string {
		switch ( $type ) {
			case Finding::TYPE_LOW_MARGIN:
			case Finding::TYPE_CRITICAL_MARGIN:
				return __( 'Raise the price to the suggested figure, or renegotiate the cost.', 'profitguard-for-woocommerce' );
			case Finding::TYPE_NEGATIVE_MARGIN:
				return __( 'Every sale of this loses money. Reprice it or stop selling it.', 'profitguard-for-woocommerce' );
			case Finding::TYPE_MISSING_COST:
				return __( 'Add a cost so this product can be included in your margin figures.', 'profitguard-for-woocommerce' );
			case Finding::TYPE_SALE_PRICE_MARGIN_RISK:
				return __( 'The sale price is what broke the margin here. Check the discount is still deliberate.', 'profitguard-for-woocommerce' );
			case Finding::TYPE_COST_INCREASE:
				return __( 'Your supplier cost went up. Check whether the selling price followed.', 'profitguard-for-woocommerce' );
			case Finding::TYPE_SHIPPING_PROFIT:
				return __( 'Nothing to do. Shown so you can see what is working.', 'profitguard-for-woocommerce' );
			case Finding::TYPE_SHIPPING_LOSS:
			case Finding::TYPE_HIGH_SHIPPING_LOSS:
				return __( 'Review your shipping rates for this weight and destination.', 'profitguard-for-woocommerce' );
			case Finding::TYPE_MISSING_CARRIER_COST:
				return __( 'Import a carrier invoice to see whether this order made or lost money on shipping.', 'profitguard-for-woocommerce' );
			case Finding::TYPE_POSSIBLE_DUPLICATE_CARRIER_ROW:
				return __( 'Check this against your carrier statement and ask for a credit if it is a duplicate.', 'profitguard-for-woocommerce' );
			case Finding::TYPE_UNMATCHED_CARRIER_ROW:
				return __( 'This carrier row did not match an order. Check the order-number column in your file.', 'profitguard-for-woocommerce' );
			default:
				return '';
		}//end switch
	}

	/**
	 * Severity.
	 *
	 * @param string $severity A Finding::SEVERITY_* constant.
	 * @return string
	 */
	public static function severity( string $severity ): string {
		switch ( $severity ) {
			case Finding::SEVERITY_CRITICAL:
				return __( 'Critical', 'profitguard-for-woocommerce' );
			case Finding::SEVERITY_HIGH:
				return __( 'High', 'profitguard-for-woocommerce' );
			case Finding::SEVERITY_MEDIUM:
				return __( 'Medium', 'profitguard-for-woocommerce' );
			case Finding::SEVERITY_LOW:
				return __( 'Low', 'profitguard-for-woocommerce' );
			default:
				return __( 'Info', 'profitguard-for-woocommerce' );
		}
	}

	/**
	 * Financial classification.
	 *
	 * Never omitted from the UI. "EUR 6.43 confirmed by two documents" and
	 * "EUR 6.43 we could not verify" are different claims, and rendering them
	 * identically teaches a merchant to distrust both.
	 *
	 * @param string $financial_type A Finding::FINANCIAL_* constant.
	 * @return string
	 */
	public static function financial_type( string $financial_type ): string {
		switch ( $financial_type ) {
			case Finding::FINANCIAL_CONFIRMED_CALCULATION:
				return __( 'Confirmed calculation', 'profitguard-for-woocommerce' );
			case Finding::FINANCIAL_EVIDENCED_DIFFERENCE:
				return __( 'Evidenced difference', 'profitguard-for-woocommerce' );
			case Finding::FINANCIAL_ESTIMATED_IMPACT:
				return __( 'Estimated impact', 'profitguard-for-woocommerce' );
			default:
				return __( 'Missing data', 'profitguard-for-woocommerce' );
		}
	}

	/**
	 * A tooltip explaining what a financial classification means.
	 *
	 * @param string $financial_type A Finding::FINANCIAL_* constant.
	 * @return string
	 */
	public static function financial_note( string $financial_type ): string {
		switch ( $financial_type ) {
			case Finding::FINANCIAL_CONFIRMED_CALCULATION:
				return __( 'Arithmetic on figures already in your store. Exact.', 'profitguard-for-woocommerce' );
			case Finding::FINANCIAL_EVIDENCED_DIFFERENCE:
				return __( 'Two sources you supplied disagree by this amount.', 'profitguard-for-woocommerce' );
			case Finding::FINANCIAL_ESTIMATED_IMPACT:
				return __( 'Projected from your own data over the stated period.', 'profitguard-for-woocommerce' );
			default:
				return __( 'No amount can be stated for this yet.', 'profitguard-for-woocommerce' );
		}
	}

	/**
	 * Module.
	 *
	 * @param string $module A Finding::MODULE_* constant.
	 * @return string
	 */
	public static function module( string $module ): string {
		return Finding::MODULE_SHIPPING === $module
			? __( 'Shipping', 'profitguard-for-woocommerce' )
			: __( 'Margin', 'profitguard-for-woocommerce' );
	}

	/**
	 * Score band.
	 *
	 * @param string|null $band Band, or null.
	 * @return string
	 */
	public static function band( ?string $band ): string {
		switch ( $band ) {
			case 'STRONG':
				return __( 'Strong', 'profitguard-for-woocommerce' );
			case 'GOOD':
				return __( 'Good', 'profitguard-for-woocommerce' );
			case 'FAIR':
				return __( 'Fair', 'profitguard-for-woocommerce' );
			case 'NEEDS_ATTENTION':
				return __( 'Needs attention', 'profitguard-for-woocommerce' );
			case 'AT_RISK':
				return __( 'At risk', 'profitguard-for-woocommerce' );
			default:
				return '';
		}
	}

	/**
	 * Format minor units as store currency, or an em dash for null.
	 *
	 * THE LAST PLACE the null-is-not-zero rule can be thrown away. A `?? 0`
	 * here would undo every precaution taken upstream, so the null path is
	 * explicit and returns a dash with a screen-reader explanation.
	 *
	 * @param int|null $minor Amount in minor units.
	 * @return string HTML-safe string (already escaped where it needs to be).
	 */
	public static function money( ?int $minor ): string {
		if ( null === $minor ) {
			return '<span class="profitguard-unknown" title="' .
				esc_attr__( 'No amount can be stated for this', 'profitguard-for-woocommerce' ) .
				'">&mdash;</span>';
		}
		return wp_kses_post( wc_price( $minor / 100 ) );
	}

	/**
	 * Format basis points as a percentage, or an em dash for null.
	 *
	 * @param int|null $bp Basis points.
	 * @return string
	 */
	public static function percent( ?int $bp ): string {
		return Money::format_percent_bp( $bp, wc_get_price_decimal_separator() );
	}
	/**
	 * Where a resolved cost is stored, in words.
	 *
	 * A merchant deciding whether to let an import replace a cost needs to know
	 * WHOSE cost it is. "WooCommerce's own field" and "imported into ProfitGuard"
	 * lead to different decisions, and an inherited parent cost is a third case
	 * again - editing the variation would not change it.
	 *
	 * @param string $source A source returned by CostProvider::get_cost().
	 * @return string
	 */
	public static function cost_source( string $source ): string {
		switch ( $source ) {
			case CostProvider::SOURCE_NATIVE:
				return __( 'WooCommerce Cost of Goods Sold', 'profitguard-for-woocommerce' );
			case CostProvider::SOURCE_NATIVE_INHERITED:
				return __( 'WooCommerce Cost of Goods Sold, inherited from the parent product', 'profitguard-for-woocommerce' );
			case CostProvider::SOURCE_NATIVE_COMBINED:
				return __( 'WooCommerce Cost of Goods Sold, parent plus this variation', 'profitguard-for-woocommerce' );
			case CostProvider::SOURCE_PROFITGUARD:
				return __( 'Imported into ProfitGuard', 'profitguard-for-woocommerce' );
			case CostProvider::SOURCE_FOREIGN:
				return __( 'Another cost-of-goods plugin', 'profitguard-for-woocommerce' );
			case CostProvider::SOURCE_NONE:
			default:
				return __( 'No cost recorded', 'profitguard-for-woocommerce' );
		}
	}
}
