<?php
/**
 * The Profit Scan, run in background batches.
 *
 * WHY ACTION SCHEDULER. WooCommerce already bundles it, so this adds no
 * dependency and no cost. A scan over 20,000 products cannot run inside one
 * admin request: PHP's max_execution_time kills it, the merchant sees a blank
 * page, and any partial work is lost. Action Scheduler gives durable, resumable
 * batches driven by WP-Cron, which every WordPress install already has.
 *
 * SHAPE. One batch per tick, each batch queuing the next:
 *
 *     start -> products 1..50 -> products 51..100 -> ... -> orders 1..50 -> ... -> finish
 *
 * Each batch is small enough to finish comfortably inside a normal PHP time
 * limit on cheap shared hosting, which is the environment this plugin is aimed
 * at.
 *
 * ATOMIC RESULTS. Findings are written under the new scan's id while the
 * PREVIOUS scan's findings stay live. Only at the end are the old ones pruned,
 * so the dashboard flips from complete-old to complete-new rather than showing
 * a half-scanned store for several minutes.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Scan;

use ProfitGuard\Analysis\MarginAnalyser;
use ProfitGuard\Analysis\ShippingAnalyser;
use ProfitGuard\Core\Score;
use ProfitGuard\Plugin\Repository;
use ProfitGuard\Plugin\Settings;
use ProfitGuard\Woo\Catalog;
use ProfitGuard\Woo\Orders;
use WC_Order;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Batched scan orchestration.
 */
final class Scanner {

	public const HOOK_PRODUCTS = 'profitguard_scan_products';
	public const HOOK_ORDERS   = 'profitguard_scan_orders';
	public const HOOK_FINISH   = 'profitguard_scan_finish';
	public const GROUP         = 'profitguard';

	public const OPTION_STATE = 'profitguard_scan_state';

	/**
	 * Register the batch handlers.
	 */
	public static function register(): void {
		add_action( self::HOOK_PRODUCTS, array( self::class, 'run_product_batch' ), 10, 2 );
		add_action( self::HOOK_ORDERS, array( self::class, 'run_order_batch' ), 10, 2 );
		add_action( self::HOOK_FINISH, array( self::class, 'finish' ), 10, 1 );
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * It ships with WooCommerce, but a merchant can be running an unusual build
	 * and a fatal "undefined function" is a much worse failure than falling
	 * back to a synchronous scan.
	 *
	 * @return bool
	 */
	public static function scheduler_available(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Whether a scan is currently running.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		$state = self::state();
		return ! empty( $state['scan_id'] ) && empty( $state['finished'] );
	}

	/**
	 * Current scan state.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist scan state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function save_state( array $state ): void {
		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * Start a scan.
	 *
	 * @return array{started:bool,scan_id:int,message:string}
	 */
	public static function start(): array {
		if ( self::is_running() ) {
			return array(
				'started' => false,
				'scan_id' => (int) ( self::state()['scan_id'] ?? 0 ),
				'message' => 'already_running',
			);
		}

		$product_total = Catalog::count_sellable();
		$order_total   = Orders::count_analysable();

		$scan_id = Repository::start_run(
			Repository::RUN_SCAN,
			array(
				'product_total' => $product_total,
				'order_total'   => $order_total,
			)
		);

		self::save_state(
			array(
				'scan_id'         => $scan_id,
				'started_at'      => time(),
				'finished'        => false,
				'product_total'   => $product_total,
				'order_total'     => $order_total,
				'product_offset'  => 0,
				'order_page'      => 1,
				'orders_seen'     => 0,
				'missing_emitted' => 0,
				'margin'          => array(),
				'shipping'        => array(
					'orders_seen'            => 0,
					'orders_assessed'        => 0,
					'orders_at_loss'         => 0,
					'shipping_charged_minor' => 0,
					'carrier_cost_minor'     => 0,
				),
			)
		);

		if ( self::scheduler_available() ) {
			as_enqueue_async_action( self::HOOK_PRODUCTS, array( $scan_id, 0 ), self::GROUP );
		} else {
			// No scheduler: run it inline. Slower and riskier on a big store,
			// but a scan that runs is better than a button that does nothing.
			self::run_to_completion( $scan_id );
		}

		return array(
			'started' => true,
			'scan_id' => $scan_id,
			'message' => 'started',
		);
	}

	/**
	 * Process one page of products.
	 *
	 * @param int $scan_id Scan id.
	 * @param int $offset  Product offset.
	 */
	public static function run_product_batch( $scan_id, $offset ): void {
		$scan_id = (int) $scan_id;
		$offset  = (int) $offset;
		$state   = self::state();

		if ( (int) ( $state['scan_id'] ?? 0 ) !== $scan_id ) {
			// A newer scan superseded this one; abandon the stale batch rather
			// than writing its findings under the wrong scan id.
			return;
		}

		$options = self::scan_options();
		$ids     = Catalog::sellable_ids( $offset, Catalog::BATCH_SIZE );

		$findings = array();
		$totals   = is_array( $state['margin'] ?? null ) ? $state['margin'] : array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$normalised = Catalog::normalise( $product );
			$totals     = MarginAnalyser::tally(
				$normalised,
				$totals,
				$options['target_margin_bp'],
				$options['warning_band_bp'],
				$options['critical_band_bp']
			);
			$findings   = array_merge( $findings, MarginAnalyser::analyse( $normalised, $options ) );
		}

		if ( ! empty( $findings ) ) {
			Repository::insert_findings( $scan_id, $findings );
		}

		$state['margin']         = $totals;
		$state['product_offset'] = $offset + Catalog::BATCH_SIZE;
		self::save_state( $state );

		$more = count( $ids ) === Catalog::BATCH_SIZE;
		if ( $more && self::scheduler_available() ) {
			as_enqueue_async_action( self::HOOK_PRODUCTS, array( $scan_id, $state['product_offset'] ), self::GROUP );
		} elseif ( ! $more && self::scheduler_available() ) {
			as_enqueue_async_action( self::HOOK_ORDERS, array( $scan_id, 1 ), self::GROUP );
		}
	}

	/**
	 * Process one page of orders.
	 *
	 * @param int $scan_id Scan id.
	 * @param int $page    1-based page.
	 */
	public static function run_order_batch( $scan_id, $page ): void {
		$scan_id = (int) $scan_id;
		$page    = max( 1, (int) $page );
		$state   = self::state();

		if ( (int) ( $state['scan_id'] ?? 0 ) !== $scan_id ) {
			return;
		}

		$ids = Orders::ids_page( $page, Orders::BATCH_SIZE );

		// One query for the whole page's carrier costs rather than one per
		// order: 50 orders would otherwise be 50 round trips.
		$carrier_costs = Repository::carrier_costs_for_orders( $ids );

		$shipping = is_array( $state['shipping'] ?? null ) ? $state['shipping'] : array();
		$shipping = array_merge(
			array(
				'orders_seen'            => 0,
				'orders_assessed'        => 0,
				'orders_at_loss'         => 0,
				'shipping_charged_minor' => 0,
				'carrier_cost_minor'     => 0,
			),
			$shipping
		);

		$missing_emitted = (int) ( $state['missing_emitted'] ?? 0 );
		$findings        = array();

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$normalised                       = Orders::normalise( $order );
			$normalised['carrier_cost_minor'] = $carrier_costs[ $id ] ?? null;

			++$shipping['orders_seen'];

			if ( null !== $normalised['carrier_cost_minor'] ) {
				++$shipping['orders_assessed'];
				$charged = (int) ( $normalised['shipping_charged_minor'] ?? 0 );
				$cost    = (int) $normalised['carrier_cost_minor'];

				$shipping['shipping_charged_minor'] += $charged;
				$shipping['carrier_cost_minor']     += $cost;

				if ( $cost > $charged ) {
					++$shipping['orders_at_loss'];
				}
			}

			$batch = ShippingAnalyser::analyse( $normalised, array( 'missing_emitted' => $missing_emitted ) );
			foreach ( $batch as $finding ) {
				if ( \ProfitGuard\Core\Finding::TYPE_MISSING_CARRIER_COST === $finding->type ) {
					++$missing_emitted;
				}
			}
			$findings = array_merge( $findings, $batch );
		}//end foreach

		if ( ! empty( $findings ) ) {
			Repository::insert_findings( $scan_id, $findings );
		}

		$state['shipping']        = $shipping;
		$state['missing_emitted'] = $missing_emitted;
		$state['order_page']      = $page + 1;
		self::save_state( $state );

		$more = count( $ids ) === Orders::BATCH_SIZE;
		if ( self::scheduler_available() ) {
			if ( $more ) {
				as_enqueue_async_action( self::HOOK_ORDERS, array( $scan_id, $page + 1 ), self::GROUP );
			} else {
				as_enqueue_async_action( self::HOOK_FINISH, array( $scan_id ), self::GROUP );
			}
		}
	}

	/**
	 * Complete the scan: carrier-level findings, score, prune, record.
	 *
	 * @param int $scan_id Scan id.
	 */
	public static function finish( $scan_id ): void {
		$scan_id = (int) $scan_id;
		$state   = self::state();

		if ( (int) ( $state['scan_id'] ?? 0 ) !== $scan_id ) {
			return;
		}

		$extra = array();

		// Duplicates and unmatched rows are properties of the imported FILE,
		// not of any one order, so they are computed once at the end rather
		// than per batch.
		$duplicates = \ProfitGuard\Core\Shipping::detect_duplicates( Repository::all_carrier_rows_for_duplicates() );
		$extra      = array_merge( $extra, ShippingAnalyser::duplicate_findings( $duplicates ) );

		$unmatched = array();
		foreach ( Repository::unmatched_carrier_rows( 50 ) as $row ) {
			$unmatched[] = array(
				'order_reference' => (string) $row['order_reference'],
				'tracking_number' => (string) $row['tracking_number'],
				'cost_minor'      => ( null === $row['cost_minor'] ) ? null : (int) $row['cost_minor'],
				'row_index'       => (int) $row['id'],
			);
		}
		$extra = array_merge( $extra, ShippingAnalyser::unmatched_findings( $unmatched ) );

		if ( ! empty( $extra ) ) {
			Repository::insert_findings( $scan_id, $extra );
		}

		$margin   = is_array( $state['margin'] ?? null ) ? $state['margin'] : array();
		$shipping = is_array( $state['shipping'] ?? null ) ? $state['shipping'] : array();

		$margin_score = Score::margin_score(
			(int) ( $margin['assessed'] ?? 0 ),
			(int) ( $margin['negative'] ?? 0 ),
			(int) ( $margin['critical'] ?? 0 ),
			(int) ( $margin['low'] ?? 0 )
		);

		$shipping_score = Score::shipping_score(
			(int) ( $shipping['orders_assessed'] ?? 0 ),
			(int) ( $shipping['shipping_charged_minor'] ?? 0 ),
			(int) ( $shipping['carrier_cost_minor'] ?? 0 )
		);

		$margin_coverage   = Score::coverage_percent(
			(int) ( $margin['assessed'] ?? 0 ),
			(int) ( $margin['products_priced'] ?? 0 )
		);
		$shipping_coverage = Score::coverage_percent(
			(int) ( $shipping['orders_assessed'] ?? 0 ),
			(int) ( $shipping['orders_seen'] ?? 0 )
		);

		$score = Score::overall( $margin_score, $shipping_score, $margin_coverage, $shipping_coverage );

		$totals = array(
			'margin'     => $margin,
			'shipping'   => $shipping,
			'score'      => $score,
			'duplicates' => count( $duplicates ),
			'unmatched'  => count( $unmatched ),
		);

		// The old scan's findings stay live until this moment, so the dashboard
		// never shows a half-scanned store.
		Repository::prune_findings_except( $scan_id );
		Repository::finish_run( $scan_id, Repository::STATUS_COMPLETED, $totals );
		Repository::purge_runs( Settings::scan_retention_days() );

		$state['finished'] = true;
		self::save_state( $state );

		Settings::update(
			array(
				'last_scan_at'         => time(),
				'onboarding_dismissed' => true,
			)
		);

		/**
		 * Fires when a Profit Scan completes.
		 *
		 * The extension point a future add-on would use for alerts or
		 * reporting, without this plugin needing to know it exists.
		 *
		 * @since 1.0.0
		 * @param int                  $scan_id Scan id.
		 * @param array<string, mixed> $totals  Scan totals.
		 */
		do_action( 'profitguard_scan_completed', $scan_id, $totals );
	}

	/**
	 * Run every batch inline, for installs with no Action Scheduler.
	 *
	 * @param int $scan_id Scan id.
	 */
	private static function run_to_completion( int $scan_id ): void {
		$guard  = 0;
		$offset = 0;
		while ( $guard < 2000 ) {
			++$guard;
			self::run_product_batch( $scan_id, $offset );
			$state  = self::state();
			$offset = (int) ( $state['product_offset'] ?? 0 );
			if ( $offset >= (int) ( $state['product_total'] ?? 0 ) ) {
				break;
			}
		}

		$page  = 1;
		$guard = 0;
		while ( $guard < 2000 ) {
			++$guard;
			self::run_order_batch( $scan_id, $page );
			$state = self::state();
			$page  = (int) ( $state['order_page'] ?? 1 );
			if ( (int) ( $state['shipping']['orders_seen'] ?? 0 ) >= (int) ( $state['order_total'] ?? 0 ) ) {
				break;
			}
		}

		self::finish( $scan_id );
	}

	/**
	 * Scan options from settings.
	 *
	 * @return array<string, int>
	 */
	private static function scan_options(): array {
		return array(
			'target_margin_bp' => Settings::target_margin_bp(),
			'warning_band_bp'  => Settings::warning_band_bp(),
			'critical_band_bp' => Settings::critical_band_bp(),
		);
	}

	/**
	 * Cancel a running scan and clear its state.
	 */
	public static function cancel(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PRODUCTS, array(), self::GROUP );
			as_unschedule_all_actions( self::HOOK_ORDERS, array(), self::GROUP );
			as_unschedule_all_actions( self::HOOK_FINISH, array(), self::GROUP );
		}

		$state = self::state();
		if ( ! empty( $state['scan_id'] ) ) {
			Repository::finish_run( (int) $state['scan_id'], Repository::STATUS_FAILED, array(), 'cancelled' );
		}
		delete_option( self::OPTION_STATE );
	}

	/**
	 * Progress for the dashboard.
	 *
	 * @return array{running:bool,percent:int,products_done:int,products_total:int,orders_done:int,orders_total:int}
	 */
	public static function progress(): array {
		$state = self::state();

		$product_total = (int) ( $state['product_total'] ?? 0 );
		$order_total   = (int) ( $state['order_total'] ?? 0 );
		$products_done = min( (int) ( $state['product_offset'] ?? 0 ), $product_total );
		$orders_done   = min( (int) ( $state['shipping']['orders_seen'] ?? 0 ), $order_total );

		$total = $product_total + $order_total;
		$done  = $products_done + $orders_done;

		return array(
			'running'        => self::is_running(),
			'percent'        => $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0,
			'products_done'  => $products_done,
			'products_total' => $product_total,
			'orders_done'    => $orders_done,
			'orders_total'   => $order_total,
		);
	}
}
