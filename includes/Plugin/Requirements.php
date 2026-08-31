<?php
/**
 * Dependency checks.
 *
 * Checked on every load rather than only at activation: a merchant can
 * deactivate WooCommerce at any time, and a plugin that assumes it is still
 * there fatals the entire site - including wp-admin, which is how a merchant
 * ends up locked out of their own store.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Requirement checks and their notices.
 */
final class Requirements {

	/** The oldest WooCommerce this plugin is tested against. */
	public const MIN_WOOCOMMERCE = '8.0';

	/**
	 * Whether WooCommerce is active and usable.
	 *
	 * Tests for the WooCommerce class rather than for a plugin file on disk:
	 * WooCommerce can be installed in a non-standard directory or loaded as a
	 * must-use plugin, and a path check would report it missing.
	 *
	 * @return bool
	 */
	public static function woocommerce_is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * The active WooCommerce version, or an empty string.
	 *
	 * @return string
	 */
	public static function woocommerce_version(): string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
	}

	/**
	 * Whether WooCommerce is new enough.
	 *
	 * @return bool
	 */
	public static function woocommerce_is_supported(): bool {
		$version = self::woocommerce_version();
		if ( '' === $version ) {
			return false;
		}
		return version_compare( $version, self::MIN_WOOCOMMERCE, '>=' );
	}

	/**
	 * Notice shown when WooCommerce is missing.
	 *
	 * Deliberately restrained and shown only to users who could act on it -
	 * telling a subscriber that a plugin they cannot install is missing is
	 * noise, and dashboard noise is what the WordPress.org guidelines are
	 * against.
	 */
	public static function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'ProfitGuard for WooCommerce needs WooCommerce to be installed and active. It is not doing anything until then.',
				'profitguard-for-woocommerce'
			)
		);
	}

	/**
	 * Notice shown when WooCommerce is too old.
	 */
	public static function render_old_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required WooCommerce version, 2: installed version. */
					__( 'ProfitGuard needs WooCommerce %1$s or newer. You are running %2$s.', 'profitguard-for-woocommerce' ),
					self::MIN_WOOCOMMERCE,
					self::woocommerce_version()
				)
			)
		);
	}
}
