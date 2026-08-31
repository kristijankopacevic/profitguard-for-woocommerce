<?php
/**
 * Run a Profit Scan to completion, synchronously.
 *
 * Action Scheduler drives the batches in production; its WP-CLI runner does
 * not pick up async actions, so this fires the same registered hooks in the
 * same order. It is the identical code path, just without the queue.
 *
 * Development tool. Not loaded by the plugin, not in the distributable ZIP.
 *
 * @package ProfitGuard
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 ); }

/*
 * Start a scan if one is not already running. Requiring the developer to click
 * "Run Profit Scan" in the admin first made this script useless for the very
 * thing it exists for - driving a scan on a headless container.
 *
 * Ask is_running(), NOT state()['scan_id']. A completed scan leaves its id in
 * the state so the dashboard can show what the last run found, so reading the
 * id alone silently re-drove the FINISHED scan - whose batches are all already
 * marked done, so every one of them was a no-op and the run produced nothing.
 * Scanner::start() makes the same distinction correctly; this script now does.
 */
if ( \ProfitGuard\Scan\Scanner::is_running() ) {
	$scan_id = (int) ( \ProfitGuard\Scan\Scanner::state()['scan_id'] ?? 0 );
	WP_CLI::log( 'resuming scan ' . $scan_id );
} else {
	$started = \ProfitGuard\Scan\Scanner::start();
	$scan_id = (int) ( $started['scan_id'] ?? 0 );
	WP_CLI::log( 'started scan ' . $scan_id );
}
if ( 0 === $scan_id ) {
	WP_CLI::error( 'could not start a scan' ); }

// Exactly what Action Scheduler does: fire the registered hooks in order.
$offset = 0;
for ( $i = 0; $i < 100; $i++ ) {
	do_action( 'profitguard_scan_products', $scan_id, $offset );
	$s      = \ProfitGuard\Scan\Scanner::state();
	$offset = (int) $s['product_offset'];
	if ( $offset >= (int) $s['product_total'] ) {
		break; }
}
WP_CLI::log( 'products done at offset ' . $offset );

$page = 1;
for ( $i = 0; $i < 100; $i++ ) {
	do_action( 'profitguard_scan_orders', $scan_id, $page );
	$s    = \ProfitGuard\Scan\Scanner::state();
	$page = (int) $s['order_page'];
	if ( (int) $s['shipping']['orders_seen'] >= (int) $s['order_total'] ) {
		break; }
}
WP_CLI::log( 'orders done at page ' . $page );

do_action( 'profitguard_scan_finish', $scan_id );
WP_CLI::success( 'scan finished' );
