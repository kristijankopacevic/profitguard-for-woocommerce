<?php
/**
 * Custom tables and schema migrations.
 *
 * WHY CUSTOM TABLES RATHER THAN OPTIONS OR POST META.
 *
 * A store with 5,000 products and 20,000 orders produces tens of thousands of
 * finding and carrier rows. Putting those in `wp_options` would bloat the
 * autoloaded option cache on EVERY page load of the entire site, including the
 * shop front end - one of the most damaging things a plugin can do to a store,
 * and invisible until the site is slow. Putting them in post meta would work
 * but makes filtering and sorting a chain of meta joins.
 *
 * So: three purpose-built tables with the indexes the queries actually use.
 * Small, singular configuration stays in options, where it belongs.
 *
 * The per-product COST is the exception: it lives in product meta, because it
 * belongs to the product, must survive ProfitGuard being uninstalled if the
 * merchant chooses, and is the one piece of ProfitGuard data another plugin
 * might reasonably want to read.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Schema management.
 */
final class Database {

	/**
	 * Bump when any CREATE TABLE below changes.
	 *
	 * Note that dbDelta is additive and idempotent, so an upgrade is "run it again", but
	 * the version gate stops that happening on every admin page load.
	 */
	public const SCHEMA_VERSION = 1;

	public const OPTION_SCHEMA_VERSION = 'profitguard_schema_version';

	/**
	 * Findings table name.
	 *
	 * @return string
	 */
	public static function findings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'profitguard_findings';
	}

	/**
	 * Carrier cost rows table name.
	 *
	 * @return string
	 */
	public static function carrier_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'profitguard_carrier_costs';
	}

	/**
	 * Scan and import history table name.
	 *
	 * @return string
	 */
	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'profitguard_runs';
	}

	/**
	 * All ProfitGuard tables, for uninstall.
	 *
	 * @return string[]
	 */
	public static function all_tables(): array {
		return array(
			self::findings_table(),
			self::carrier_table(),
			self::runs_table(),
		);
	}

	/**
	 * Create or upgrade the schema.
	 *
	 * Note that dbDelta is fussy in ways that are not obvious and fail silently:
	 *  - two spaces between PRIMARY KEY and the column list,
	 *  - KEY rather than INDEX,
	 *  - each field on its own line,
	 *  - no backticks around the table name.
	 * Getting any of these wrong means the table is simply not created and the
	 * plugin appears to work until the first insert.
	 */
	public static function migrate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$findings        = self::findings_table();
		$carrier         = self::carrier_table();
		$runs            = self::runs_table();

		/*
		 * FINDINGS.
		 *
		 * `impact_minor` is BIGINT NULL and the null is load-bearing: a finding
		 * whose monetary effect cannot be established is stored as NULL, is
		 * counted, and is excluded from every SUM. Never COALESCE it to 0 in a
		 * query - see Core\Aggregate for why.
		 *
		 * `scan_id` lets a completed scan replace the previous one atomically
		 * rather than leaving the dashboard half-updated mid-scan.
		 */
		$sql_findings = "CREATE TABLE {$findings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			module varchar(20) NOT NULL DEFAULT '',
			type varchar(40) NOT NULL DEFAULT '',
			severity varchar(20) NOT NULL DEFAULT '',
			financial_type varchar(30) NOT NULL DEFAULT '',
			impact_minor bigint(20) DEFAULT NULL,
			confidence smallint(5) unsigned NOT NULL DEFAULT 0,
			subject_kind varchar(20) NOT NULL DEFAULT '',
			subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subject_label varchar(255) NOT NULL DEFAULT '',
			reference varchar(120) NOT NULL DEFAULT '',
			current_minor bigint(20) DEFAULT NULL,
			expected_minor bigint(20) DEFAULT NULL,
			evidence longtext NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY scan_module (scan_id,module),
			KEY scan_type (scan_id,type),
			KEY scan_severity (scan_id,severity),
			KEY subject (subject_kind,subject_id)
		) {$charset_collate};";

		/*
		 * CARRIER COSTS.
		 *
		 * One row per line of an imported carrier invoice. `order_id` is 0 until
		 * the row is matched to a WooCommerce order; unmatched rows are KEPT so
		 * the merchant can see what did not match rather than having it
		 * silently dropped.
		 *
		 * `row_hash` is a digest of the source line and carries a UNIQUE index:
		 * re-importing the same file cannot double a merchant's costs, which is
		 * the single most damaging import bug possible here.
		 */
		$sql_carrier = "CREATE TABLE {$carrier} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			import_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_reference varchar(120) NOT NULL DEFAULT '',
			tracking_number varchar(120) NOT NULL DEFAULT '',
			carrier varchar(120) NOT NULL DEFAULT '',
			cost_minor bigint(20) DEFAULT NULL,
			surcharge_minor bigint(20) DEFAULT NULL,
			adjustment_minor bigint(20) DEFAULT NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			shipped_on date DEFAULT NULL,
			row_hash char(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY row_hash (row_hash),
			KEY order_id (order_id),
			KEY order_reference (order_reference),
			KEY tracking_number (tracking_number),
			KEY import_id (import_id)
		) {$charset_collate};";

		/*
		 * RUNS: scan history and import history in one table.
		 *
		 * They share every column that matters (kind, status, counters, when)
		 * and the dashboard shows them on one timeline, so two tables would be
		 * two of everything for no gain.
		 */
		$sql_runs = "CREATE TABLE {$runs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(30) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			totals longtext NULL,
			message text NULL,
			started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY kind_started (kind,started_at)
		) {$charset_collate};";

		dbDelta( $sql_findings );
		dbDelta( $sql_carrier );
		dbDelta( $sql_runs );

		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Run the migration only when the stored version is behind.
	 *
	 * Called on admin_init so a plugin updated by file copy (which never fires
	 * the activation hook) still gets its schema.
	 */
	public static function maybe_migrate(): void {
		$installed = (int) get_option( self::OPTION_SCHEMA_VERSION, 0 );
		if ( $installed >= self::SCHEMA_VERSION ) {
			return;
		}
		self::migrate();
	}

	/**
	 * Whether every ProfitGuard table exists.
	 *
	 * Used by the admin to show a real error instead of a database warning when
	 * a table is missing - which happens on hosts that silently fail DDL.
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( self::all_tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check; no cache layer applies.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Drop every ProfitGuard table. Called only from uninstall.php.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( self::all_tables() as $table ) {
			// Table names cannot be parameterised, and these are built from
			// $wpdb->prefix plus a literal, never from input.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping this plugin's own tables during uninstall is the intended schema change.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
