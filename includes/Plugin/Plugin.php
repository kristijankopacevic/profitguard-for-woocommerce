<?php
/**
 * Plugin bootstrap.
 *
 * Wires hooks and nothing else. The work lives in the classes it points at, so
 * this file stays a readable index of everything the plugin does.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Plugin;

use ProfitGuard\Admin\Admin;
use ProfitGuard\Scan\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Instance accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Scan handlers register unconditionally: Action Scheduler runs them
		// from WP-Cron, which has no admin context.
		Scanner::register();

		if ( is_admin() ) {
			// Catches an update installed by file copy, which never fires the
			// activation hook and would otherwise leave the schema behind.
			add_action( 'admin_init', array( Database::class, 'maybe_migrate' ) );
			Admin::instance()->register();
		}
	}
}
