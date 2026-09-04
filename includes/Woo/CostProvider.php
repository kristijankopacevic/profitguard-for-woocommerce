<?php
/**
 * Where a product's cost comes from.
 *
 * WooCommerce 10.3 put Cost of Goods Sold into core, but it is opt-in and off
 * by default, so cost has to be resolved across three different store states.
 * Those states, the precedence between cost sources, and why the precedence
 * changed are documented on the class below.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Woo;

use ProfitGuard\Core\CogsResolution;
use ProfitGuard\Core\Money;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/*
 * Cost lives in POST META, which means reading it is a meta_key lookup.
 *
 * WPCS warns that querying by meta_key can be slow, and on an unindexed
 * arbitrary key it can be. These calls are get_post_meta()/update_post_meta()
 * for a SINGLE known post id, which WordPress serves from the object cache
 * after one primed query per post - not a meta_query across the catalog. The
 * scanner primes that cache by hydrating products in batches.
 *
 * Meta is still the right home for the cost: it belongs to the product, it
 * survives ProfitGuard being removed if the merchant chooses, and it is the
 * one piece of our data another plugin might reasonably want to read.
 *
 * The sniff is silenced in phpcs.xml.dist, not with an in-file directive.
 * A file-wide PHPCS suppression here made the WordPress.org Plugin Check
 * report this file as having no direct-access guard at all - the guard is on
 * line 54 and always has been. Removing the equivalent directive from
 * Plugin/Repository.php cleared the same false error there, which is how the
 * cause was established. Note that the directive keyword cannot even be
 * WRITTEN in this comment: PHPCS reads it out of prose as a real annotation.
 */

/**
 * Resolves a cost for a product or variation.
 *
 * THREE STORE STATES. WooCommerce 10.3 (October 2025) moved Cost of Goods Sold
 * into core, so a native cost field now exists - but it is OPT-IN and disabled
 * by default, under WooCommerce -> Settings -> Advanced -> Features:
 *
 *  1. WooCommerce older than 10.3. No native API. Read third-party COGS meta,
 *     then fall back to ProfitGuard's own key.
 *  2. WooCommerce 10.3+ with the feature DISABLED - the default, and so the
 *     common case. Do not write into a disabled feature's storage. Work
 *     exactly as in state 1, and tell the merchant the setting exists.
 *  3. WooCommerce 10.3+ with the feature ENABLED. Prefer and reuse the native
 *     value, and write through set_cogs_value() rather than a parallel private
 *     key - two disagreeing cost figures in the product editor are worse for a
 *     merchant than a single one they can trust.
 *
 * PRECEDENCE, and the fact that it changed. Before native COGS existed,
 * ProfitGuard's own key always won. Now, when the feature is enabled, the
 * NATIVE value wins, because it is the value the merchant sees and edits in
 * WooCommerce itself; ProfitGuard silently overriding the field shown in the
 * product editor is the confusing outcome. A store holding both a native cost
 * and an older ProfitGuard cost will therefore report the native one - a
 * deliberate behaviour change, recorded in the changelog rather than slipped in.
 *
 * THIRD-PARTY KEYS. Several plugins each invented their own, and reading them
 * is still a genuine kindness for the many stores in states 1 and 2 - a
 * merchant who already has costs there should not have to re-enter them - but
 * it has to be done carefully:
 *
 *  - Foreign keys are READ ONLY. ProfitGuard never writes to another plugin's
 *    meta. Writing there would corrupt their data and would survive
 *    ProfitGuard being uninstalled.
 *  - A foreign value is parsed with the strict decimal parser and rejected if
 *    it is not a plain number, because we do not know what conventions another
 *    plugin stores.
 *
 * The list is filterable so a merchant or a future add-on can teach it a key
 * without patching the plugin.
 */
final class CostProvider {

	/**
	 * ProfitGuard's own cost meta key, in minor units.
	 *
	 * Stored as an integer count of minor units rather than a decimal string,
	 * so no float ever round-trips through the database.
	 */
	public const META_COST_MINOR = '_profitguard_cost_minor';

	/**
	 * The previous cost, kept so a cost increase can be detected.
	 *
	 * Written by the importer when it overwrites an existing cost. Without it
	 * there is no COST_INCREASE finding, because there is nothing to compare
	 * the new cost against.
	 */
	public const META_PREVIOUS_COST_MINOR = '_profitguard_previous_cost_minor';

	/**
	 * When the cost was last set, as a Unix timestamp.
	 */
	public const META_COST_UPDATED_AT = '_profitguard_cost_updated_at';

	/**
	 * Where a resolved cost came from.
	 */
	public const SOURCE_PROFITGUARD = 'profitguard';
	public const SOURCE_FOREIGN     = 'foreign_meta';
	public const SOURCE_NONE        = 'none';

	/**
	 * WooCommerce's own COGS field, held by this product or variation.
	 */
	public const SOURCE_NATIVE = 'native_cogs';

	/**
	 * WooCommerce's own COGS field, inherited from the parent product.
	 *
	 * Reported separately from SOURCE_NATIVE because "4.00, its own" and
	 * "10.00, inherited from the parent" are different facts to show a
	 * merchant. Presenting an inherited cost as the variation's own is how
	 * someone comes to edit the wrong field.
	 */
	public const SOURCE_NATIVE_INHERITED = 'native_cogs_inherited';

	/**
	 * WooCommerce's own COGS field, parent plus an additive variation cost.
	 */
	public const SOURCE_NATIVE_COMBINED = 'native_cogs_combined';

	/**
	 * Known third-party cost meta keys, in preference order.
	 *
	 * @return string[]
	 */
	public static function foreign_meta_keys(): array {
		$keys = array(
			// WooCommerce Cost of Goods (several forks share this key).
			'_wc_cog_cost',
			// Cost of Goods for WooCommerce (WPFactory).
			'_alg_wc_cog_cost',
			// A common convention among smaller plugins and custom themes.
			'_cost_of_goods',
			'_wc_cost_of_goods',
			'_product_cost',
		);

		/**
		 * Filter the third-party cost meta keys ProfitGuard will read.
		 *
		 * Read only: ProfitGuard never writes to any of these.
		 *
		 * @since 1.0.0
		 * @param string[] $keys Meta keys, in preference order.
		 */
		$filtered = apply_filters( 'profitguard_cost_meta_keys', $keys );

		return is_array( $filtered ) ? array_values( array_filter( array_map( 'strval', $filtered ) ) ) : $keys;
	}

	/**
	 * Resolve the cost for a product or variation.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array{cost_minor:int|null,source:string,meta_key:string|null}
	 */
	public static function get_cost( WC_Product $product ): array {
		$product_id = $product->get_id();

		// 1. WooCommerce's own COGS field, when the store has the feature on.
		// It wins because it is the value the merchant sees and edits in
		// WooCommerce itself. NativeCogs returns null - never zero - when there
		// is no native cost, and resolves parent inheritance and the additive
		// flag to core's own measured rule.
		if ( NativeCogs::is_enabled() ) {
			$native = NativeCogs::get_cost( $product );
			if ( null !== $native['cost_minor'] ) {
				return array(
					'cost_minor' => $native['cost_minor'],
					'source'     => self::source_for_basis( $native['basis'] ),
					'meta_key'   => null,
				);
			}
		}

		// 2. ProfitGuard's own value.
		$own = get_post_meta( $product_id, self::META_COST_MINOR, true );
		if ( '' !== $own && null !== $own && is_numeric( $own ) ) {
			return array(
				'cost_minor' => (int) $own,
				'source'     => self::SOURCE_PROFITGUARD,
				'meta_key'   => self::META_COST_MINOR,
			);
		}

		// 3. A known third-party key, read only, parsed strictly.
		foreach ( self::foreign_meta_keys() as $key ) {
			$raw = get_post_meta( $product_id, $key, true );
			if ( '' === $raw || null === $raw ) {
				continue;
			}
			// Strict decimal parsing: these are machine-written decimals. Using
			// the spreadsheet heuristic here could read "1.000" as a thousand.
			$minor = Money::parse_decimal_to_minor( is_scalar( $raw ) ? (string) $raw : null );
			if ( null === $minor || $minor < 0 ) {
				continue;
			}
			return array(
				'cost_minor' => $minor,
				'source'     => self::SOURCE_FOREIGN,
				'meta_key'   => $key,
			);
		}

		/**
		 * Filter a resolved cost, for a future add-on or a bespoke source.
		 *
		 * Returning a non-null integer supplies a cost ProfitGuard could not
		 * find. Returning null leaves it genuinely unknown, which is a valid
		 * and important answer - it produces a MISSING_COST finding rather
		 * than a fabricated margin.
		 *
		 * @since 1.0.0
		 * @param int|null   $cost_minor Cost in minor units, or null.
		 * @param WC_Product $product    The product.
		 */
		$filtered = apply_filters( 'profitguard_product_cost_minor', null, $product );
		if ( null !== $filtered && is_numeric( $filtered ) ) {
			return array(
				'cost_minor' => (int) $filtered,
				'source'     => self::SOURCE_FOREIGN,
				'meta_key'   => null,
			);
		}

		// 4. Genuinely unknown. Never zero.
		return array(
			'cost_minor' => null,
			'source'     => self::SOURCE_NONE,
			'meta_key'   => null,
		);
	}

	/**
	 * Map a resolution basis onto the source this class reports.
	 *
	 * @param string $basis One of the CogsResolution::BASIS_* constants.
	 * @return string
	 */
	private static function source_for_basis( string $basis ): string {
		switch ( $basis ) {
			case CogsResolution::BASIS_INHERITED:
				return self::SOURCE_NATIVE_INHERITED;
			case CogsResolution::BASIS_COMBINED:
				return self::SOURCE_NATIVE_COMBINED;
			default:
				return self::SOURCE_NATIVE;
		}
	}

	/**
	 * Is this cost held in WooCommerce's own COGS field?
	 *
	 * Used by the importer, which must not silently replace a native cost, and
	 * by the admin, which must not offer a second cost field beside the one
	 * WooCommerce already shows.
	 *
	 * @param string $source A source returned by get_cost().
	 * @return bool
	 */
	public static function is_native_source( string $source ): bool {
		return in_array(
			$source,
			array( self::SOURCE_NATIVE, self::SOURCE_NATIVE_INHERITED, self::SOURCE_NATIVE_COMBINED ),
			true
		);
	}

	/**
	 * Store a cost, keeping the previous value so an increase can be detected.
	 *
	 * When WooCommerce's native COGS feature is enabled the cost is written
	 * THROUGH IT, so the merchant sees one cost field in the product editor
	 * rather than two that can disagree. The previous-cost and updated-at meta
	 * are still recorded either way, because they are ProfitGuard's own
	 * bookkeeping - they are what makes a COST_INCREASE finding possible, and
	 * WooCommerce keeps no history of its own.
	 *
	 * When the feature is disabled nothing is written into its storage; the
	 * cost goes to ProfitGuard's own key, as it always did.
	 *
	 * @param int $product_id Product or variation id.
	 * @param int $cost_minor Cost in minor units.
	 * @return bool True when the value changed.
	 */
	public static function set_cost( int $product_id, int $cost_minor ): bool {
		if ( $cost_minor < 0 ) {
			return false;
		}

		if ( NativeCogs::is_enabled() ) {
			return self::set_native_cost( $product_id, $cost_minor );
		}

		$existing = get_post_meta( $product_id, self::META_COST_MINOR, true );
		$previous = ( '' !== $existing && is_numeric( $existing ) ) ? (int) $existing : null;

		if ( null !== $previous && $previous === $cost_minor ) {
			return false;
		}

		if ( null !== $previous ) {
			update_post_meta( $product_id, self::META_PREVIOUS_COST_MINOR, $previous );
		}

		update_post_meta( $product_id, self::META_COST_MINOR, $cost_minor );
		update_post_meta( $product_id, self::META_COST_UPDATED_AT, time() );

		/**
		 * Fires after ProfitGuard stores a product cost.
		 *
		 * @since 1.0.0
		 * @param int      $product_id Product or variation id.
		 * @param int      $cost_minor New cost in minor units.
		 * @param int|null $previous   Previous cost in minor units, or null.
		 */
		do_action( 'profitguard_cost_updated', $product_id, $cost_minor, $previous );

		return true;
	}

	/**
	 * Write a cost through WooCommerce's native COGS field.
	 *
	 * The previous value read here is the RESOLVED native one, so a cost
	 * increase is detected against what the merchant was actually looking at
	 * rather than against a stale ProfitGuard key.
	 *
	 * @param int $product_id Product or variation id.
	 * @param int $cost_minor Cost in minor units.
	 * @return bool True when the value changed.
	 */
	private static function set_native_cost( int $product_id, int $cost_minor ): bool {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$before   = NativeCogs::get_cost( $product );
		$previous = $before['cost_minor'];

		if ( null !== $previous && $previous === $cost_minor ) {
			return false;
		}

		if ( ! NativeCogs::set_cost( $product, $cost_minor ) ) {
			return false;
		}

		if ( null !== $previous ) {
			update_post_meta( $product_id, self::META_PREVIOUS_COST_MINOR, $previous );
		}
		update_post_meta( $product_id, self::META_COST_UPDATED_AT, time() );

		/**
		 * Fires after ProfitGuard stores a product cost.
		 *
		 * @since 1.0.0
		 * @param int      $product_id Product or variation id.
		 * @param int      $cost_minor New cost in minor units.
		 * @param int|null $previous   Previous cost in minor units, or null.
		 */
		do_action( 'profitguard_cost_updated', $product_id, $cost_minor, $previous );

		return true;
	}

	/**
	 * The previous cost, when one was recorded.
	 *
	 * @param int $product_id Product or variation id.
	 * @return int|null
	 */
	public static function get_previous_cost( int $product_id ): ?int {
		$value = get_post_meta( $product_id, self::META_PREVIOUS_COST_MINOR, true );
		return ( '' !== $value && is_numeric( $value ) ) ? (int) $value : null;
	}

	/**
	 * Remove every ProfitGuard cost meta key. Used only by uninstall.
	 */
	public static function delete_all_cost_meta(): void {
		global $wpdb;

		foreach ( array( self::META_COST_MINOR, self::META_PREVIOUS_COST_MINOR, self::META_COST_UPDATED_AT ) as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup on uninstall; delete_post_meta_by_key is available but this keeps all three keys consistent.
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) );
		}
	}
}
