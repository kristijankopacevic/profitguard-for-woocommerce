<?php
/**
 * Activation and deactivation.
 *
 * Activation is deliberately minimal: create the schema, seed defaults, and
 * nothing else. It does NOT start a scan. A merchant activating a plugin
 * expects the page to come back immediately, and kicking off a scan of 20,000
 * products inside the activation request is how a plugin earns a one-star
 * review for "breaking my site".
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Plugin;

use ProfitGuard\Scan\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Activation lifecycle.
 */
final class Activator {

	/**
	 * Run on activation.
	 */
	public static function activate(): void {
		Database::migrate();

		// add_option, not update_option: a merchant who deactivates and
		// reactivates keeps the target margin they chose.
		add_option( Settings::OPTION, Settings::defaults(), '', false );

		// Show the onboarding prompt again only if the store has never been
		// scanned. Someone reactivating after a year does not need it.
		if ( ! Settings::get( 'last_scan_at' ) ) {
			Settings::update( array( 'onboarding_dismissed' => false ) );
		}
	}

	/**
	 * Run on deactivation.
	 *
	 * Cancels queued work so a deactivated plugin does not leave orphaned
	 * Action Scheduler jobs firing against classes that no longer load. Data is
	 * NOT touched: deactivation is not uninstallation, and a merchant
	 * troubleshooting a conflict must not lose their imported costs.
	 */
	public static function deactivate(): void {
		if ( class_exists( Scanner::class ) ) {
			Scanner::cancel();
		}
	}
}
