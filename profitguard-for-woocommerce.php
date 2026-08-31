<?php
/**
 * Plugin Name:       ProfitGuard for WooCommerce
 * Plugin URI:        https://github.com/kristijankopacevic/marginguard-ai
 * Description:       Find low-margin products and shipping losses inside WooCommerce. All analysis runs locally in your WordPress installation.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Kristijan Kopacevic
 * Author URI:        https://github.com/kristijankopacevic
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       profitguard-for-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * WC requires at least: 8.0
 * WC tested up to:      11.0
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'PROFITGUARD_VERSION', '1.0.0' );
define( 'PROFITGUARD_FILE', __FILE__ );
define( 'PROFITGUARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROFITGUARD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader.
 *
 * Hand-written rather than Composer's, because the distributed plugin ships
 * with NO vendor directory: it has zero runtime dependencies, and everything
 * Composer installs here is development tooling. Shipping a vendor tree to
 * WordPress.org for an autoloader we can write in fifteen lines would add
 * weight and review surface for nothing.
 *
 * Maps ProfitGuard\Some\Thing -> includes/Some/Thing.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'ProfitGuard\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = PROFITGUARD_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		// realpath + prefix check: the class name reaches this function from
		// PHP's autoloader and never from user input, but a path built by
		// string concatenation is worth pinning inside the plugin directory
		// regardless.
		$real = realpath( $path );
		$base = realpath( PROFITGUARD_DIR . 'includes' );
		if ( false === $real || false === $base || 0 !== strpos( $real, $base ) ) {
			return;
		}
		require_once $real;
	}
);

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 *
 * Must run on `before_woocommerce_init`, before WooCommerce decides which
 * storage to use. Without this declaration WooCommerce shows the merchant an
 * incompatibility warning on the HPOS settings screen and may refuse to let
 * them enable it, so an undeclared plugin is actively harmful to a store even
 * though it looks like it works.
 *
 * ProfitGuard reads orders exclusively through the WooCommerce CRUD APIs
 * (wc_get_orders, WC_Order), which work identically on both storage backends,
 * so the declaration is honest rather than optimistic.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PROFITGUARD_FILE,
				true
			);
		}
	}
);

register_activation_hook( PROFITGUARD_FILE, array( \ProfitGuard\Plugin\Activator::class, 'activate' ) );
register_deactivation_hook( PROFITGUARD_FILE, array( \ProfitGuard\Plugin\Activator::class, 'deactivate' ) );

/**
 * Boot the plugin.
 *
 * Deferred to `plugins_loaded` so WooCommerce has had its chance to load. The
 * dependency check runs here rather than at activation because a merchant can
 * deactivate WooCommerce at any time afterwards, and a plugin that only checks
 * once fatals the whole site when they do.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! \ProfitGuard\Plugin\Requirements::woocommerce_is_active() ) {
			add_action( 'admin_notices', array( \ProfitGuard\Plugin\Requirements::class, 'render_missing_woocommerce_notice' ) );
			return;
		}
		\ProfitGuard\Plugin\Plugin::instance()->boot();
	}
);
