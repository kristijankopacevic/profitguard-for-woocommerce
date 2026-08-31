<?php
/**
 * Uninstall.
 *
 * DESTRUCTION IS OPT-IN. By default this removes nothing but the plugin's own
 * transients. A merchant who deletes a plugin to troubleshoot a conflict and
 * loses months of imported supplier costs has no way to get them back, so the
 * setting defaults to off and the Settings screen says plainly what it does.
 *
 * Runs in a bare context: the plugin is NOT loaded, so nothing here may assume
 * an autoloader or any ProfitGuard class exists. The few table and option names
 * are therefore repeated rather than imported.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

// Only WordPress may run this file, and only during an uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove ProfitGuard data for one site.
 *
 * @param wpdb $wpdb Database handle.
 */
function profitguard_uninstall_site( $wpdb ) {
	$settings = get_option( 'profitguard_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();

	// Scan state and any pending import previews go regardless: they are
	// worthless without the plugin and would otherwise linger forever.
	delete_option( 'profitguard_scan_state' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup on uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_profitguard_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_profitguard_' ) . '%'
		)
	);

	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		// The merchant did not ask for their data to be destroyed. Stop here.
		return;
	}

	foreach ( array( 'profitguard_findings', 'profitguard_carrier_costs', 'profitguard_runs' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		// A table name cannot be parameterised; this one is built from
		// $wpdb->prefix plus a literal and never from input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping this plugin's own tables is the whole point of an opt-in uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	foreach ( array( '_profitguard_cost_minor', '_profitguard_previous_cost_minor', '_profitguard_cost_updated_at' ) as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	delete_option( 'profitguard_settings' );
	delete_option( 'profitguard_schema_version' );
}

/**
 * Run the uninstall across every site this install has.
 *
 * Wrapped in a function so its locals are locals: at file scope PHPCS reads
 * them as plugin-defined globals, and it is right to - a stray global in an
 * uninstall script is exactly the kind of thing that collides with something
 * else mid-teardown.
 */
function profitguard_run_uninstall() {
	global $wpdb;

	if ( ! is_multisite() ) {
		profitguard_uninstall_site( $wpdb );
		return;
	}

	/*
	 * On multisite each site has its own tables, options and product meta, so
	 * each must be cleaned in its own context. The list is capped: an uninstall
	 * on a network with tens of thousands of sites would time out, and a
	 * half-finished uninstall is worse than one that needs running again.
	 */
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 1000,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		profitguard_uninstall_site( $wpdb );
		restore_current_blog();
	}
}

profitguard_run_uninstall();
