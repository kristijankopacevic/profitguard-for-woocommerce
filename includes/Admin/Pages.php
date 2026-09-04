<?php
/**
 * The four admin screens.
 *
 * Every dynamic value is escaped at the point of output with the function that
 * matches its context (esc_html, esc_attr, esc_url). Labels::money() returns
 * markup from wc_price() and is passed through wp_kses_post() inside that
 * method, so it is echoed directly and never double-escaped.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Admin;

use ProfitGuard\Core\Csv;
use ProfitGuard\Core\Finding;
use ProfitGuard\Core\Money;
use ProfitGuard\Core\Score;
use ProfitGuard\Import\Importer;
use ProfitGuard\Plugin\Database;
use ProfitGuard\Plugin\Repository;
use ProfitGuard\Plugin\Settings;
use ProfitGuard\Scan\Scanner;
use ProfitGuard\Woo\NativeCogs;
use ProfitGuard\Woo\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Screen rendering.
 */
final class Pages {

	/**
	 * Shared header with tab navigation.
	 *
	 * @param string $current Current page slug.
	 */
	private static function header( string $current ): void {
		$tabs = array(
			Admin::SLUG          => __( 'Dashboard', 'profitguard-for-woocommerce' ),
			Admin::SLUG_FINDINGS => __( 'Findings', 'profitguard-for-woocommerce' ),
			Admin::SLUG_IMPORT   => __( 'Import data', 'profitguard-for-woocommerce' ),
			Admin::SLUG_SETTINGS => __( 'Settings', 'profitguard-for-woocommerce' ),
		);
		?>
		<div class="wrap profitguard">
			<h1><?php esc_html_e( 'ProfitGuard', 'profitguard-for-woocommerce' ); ?></h1>
			<nav class="nav-tab-wrapper profitguard-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
						class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php
	}

	/**
	 * Close the wrapper.
	 */
	private static function footer(): void {
		?>
			<p class="profitguard-privacy">
				<?php
				esc_html_e(
					'ProfitGuard analyses your store inside this WordPress installation. Your financial data is not sent to ProfitGuard servers.',
					'profitguard-for-woocommerce'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * A form that submits one ProfitGuard action.
	 *
	 * @param string                $action Action name.
	 * @param string                $label  Button label.
	 * @param string                $css_class Button class.
	 * @param array<string, string> $fields    Extra hidden fields.
	 */
	private static function action_form( string $action, string $label, string $css_class = 'button', array $fields = array() ): void {
		?>
		<form method="post" class="profitguard-inline-form">
			<?php wp_nonce_field( $action ); ?>
			<input type="hidden" name="profitguard_action" value="<?php echo esc_attr( $action ); ?>" />
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>
			<button type="submit" class="<?php echo esc_attr( $css_class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	// Dashboard.

	/**
	 * The dashboard.
	 */
	public static function dashboard(): void {
		self::header( Admin::SLUG );

		if ( ! Database::tables_exist() ) {
			// Some hosts silently fail DDL. A clear message beats a wall of
			// database warnings the merchant cannot interpret.
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'ProfitGuard could not create its database tables. Deactivate and reactivate the plugin, and contact your host if it persists.', 'profitguard-for-woocommerce' ) .
				'</p></div>';
			self::footer();
			return;
		}

		$run      = Repository::latest_completed_run( Repository::RUN_SCAN );
		$progress = Scanner::progress();

		?>
		<div class="profitguard-toolbar">
			<?php
			if ( $progress['running'] ) {
				self::action_form( Admin::ACTION_CANCEL, __( 'Cancel scan', 'profitguard-for-woocommerce' ) );
			} else {
				self::action_form( Admin::ACTION_SCAN, __( 'Run Profit Scan', 'profitguard-for-woocommerce' ), 'button button-primary' );
			}
			?>
			<span class="profitguard-storage">
				<?php
				echo esc_html(
					Orders::hpos_enabled()
						? __( 'Order storage: High-Performance Order Storage', 'profitguard-for-woocommerce' )
						: __( 'Order storage: legacy posts table', 'profitguard-for-woocommerce' )
				);
				?>
			</span>
		</div>
		<?php

		if ( $progress['running'] ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: products done, 2: products total, 3: orders done, 4: orders total. */
						__( 'Scan in progress: %1$d of %2$d products, %3$d of %4$d orders. Reload this page to see progress.', 'profitguard-for-woocommerce' ),
						$progress['products_done'],
						$progress['products_total'],
						$progress['orders_done'],
						$progress['orders_total']
					)
				)
			);
		}

		if ( null === $run ) {
			self::empty_state_no_scan();
			self::footer();
			return;
		}

		$totals   = is_array( $run['totals'] ) ? $run['totals'] : array();
		$margin   = isset( $totals['margin'] ) && is_array( $totals['margin'] ) ? $totals['margin'] : array();
		$shipping = isset( $totals['shipping'] ) && is_array( $totals['shipping'] ) ? $totals['shipping'] : array();
		$score    = isset( $totals['score'] ) && is_array( $totals['score'] ) ? $totals['score'] : array();
		$scan_id  = (int) $run['id'];

		self::score_card( $score );

		?>
		<h2><?php esc_html_e( 'Profit health', 'profitguard-for-woocommerce' ); ?></h2>
		<div class="profitguard-cards">
			<?php
			self::stat_card(
				__( 'Products analysed', 'profitguard-for-woocommerce' ),
				(string) (int) ( $margin['products_seen'] ?? 0 )
			);
			self::stat_card(
				__( 'Below target margin', 'profitguard-for-woocommerce' ),
				(string) ( (int) ( $margin['low'] ?? 0 ) + (int) ( $margin['critical'] ?? 0 ) )
			);
			self::stat_card(
				__( 'Selling below cost', 'profitguard-for-woocommerce' ),
				(string) (int) ( $margin['negative'] ?? 0 )
			);
			self::stat_card(
				__( 'Missing a cost', 'profitguard-for-woocommerce' ),
				(string) (int) ( $margin['missing_cost'] ?? 0 ),
				__( 'These cannot be given a margin until a cost is recorded.', 'profitguard-for-woocommerce' )
			);
			?>
		</div>
		<?php

		$orders_seen     = (int) ( $shipping['orders_seen'] ?? 0 );
		$orders_assessed = (int) ( $shipping['orders_assessed'] ?? 0 );
		$evidenced_loss  = Repository::sum_impact(
			$scan_id,
			array( Finding::TYPE_SHIPPING_LOSS, Finding::TYPE_HIGH_SHIPPING_LOSS )
		);
		?>
		<h2><?php esc_html_e( 'Shipping health', 'profitguard-for-woocommerce' ); ?></h2>
		<div class="profitguard-cards">
			<?php
			self::stat_card( __( 'Orders analysed', 'profitguard-for-woocommerce' ), (string) $orders_seen );
			self::stat_card(
				__( 'With an imported carrier cost', 'profitguard-for-woocommerce' ),
				(string) $orders_assessed
			);
			self::stat_card(
				__( 'Losing money on shipping', 'profitguard-for-woocommerce' ),
				(string) (int) ( $shipping['orders_at_loss'] ?? 0 )
			);
			?>
			<div class="profitguard-card">
				<span class="profitguard-card-label"><?php esc_html_e( 'Evidenced shipping loss', 'profitguard-for-woocommerce' ); ?></span>
				<span class="profitguard-card-value">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Labels::money().
					echo Labels::money( $evidenced_loss );
					?>
				</span>
				<span class="profitguard-card-note">
					<?php
					echo esc_html(
						0 === $orders_assessed
							? __( 'No carrier costs imported yet, so no shipping amount can be stated.', 'profitguard-for-woocommerce' )
							: __( 'Only orders with an imported carrier cost are included.', 'profitguard-for-woocommerce' )
					);
					?>
				</span>
			</div>
		</div>
		<?php

		if ( 0 === $orders_assessed && $orders_seen > 0 ) {
			printf(
				'<div class="notice notice-info inline"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__(
					'WooCommerce records what your customer paid for shipping, but not what your carrier billed you. Import a carrier invoice to compare the two.',
					'profitguard-for-woocommerce'
				),
				esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_IMPORT ) ),
				esc_html__( 'Import carrier costs', 'profitguard-for-woocommerce' )
			);
		}

		$top = Repository::query_findings(
			array(
				'scan_id'  => $scan_id,
				'orderby'  => 'impact',
				'per_page' => 10,
				'page'     => 1,
			)
		);

		?>
		<h2><?php esc_html_e( 'Highest priority findings', 'profitguard-for-woocommerce' ); ?></h2>
		<?php
		if ( empty( $top['rows'] ) ) {
			echo '<p>' . esc_html__( 'Nothing needs your attention from this scan.', 'profitguard-for-woocommerce' ) . '</p>';
		} else {
			self::findings_table( $top['rows'] );
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_FINDINGS ) ),
				esc_html__( 'Review all findings', 'profitguard-for-woocommerce' )
			);
		}

		self::footer();
	}

	/**
	 * The ProfitGuard Score card, with coverage.
	 *
	 * @param array<string, mixed> $score Score payload from the scan.
	 */
	private static function score_card( array $score ): void {
		$value = isset( $score['score'] ) ? $score['score'] : null;
		$band  = Score::band( null === $value ? null : (int) $value );
		?>
		<div class="profitguard-score">
			<div class="profitguard-score-value">
				<span class="profitguard-card-label"><?php esc_html_e( 'ProfitGuard Score', 'profitguard-for-woocommerce' ); ?></span>
				<?php if ( null === $value ) : ?>
					<span class="profitguard-unknown">&mdash;</span>
					<p class="profitguard-card-note">
						<?php
						esc_html_e(
							'We need at least one product with both a price and a cost before a score means anything.',
							'profitguard-for-woocommerce'
						);
						?>
					</p>
				<?php else : ?>
					<strong><?php echo esc_html( (string) (int) $value ); ?></strong>
					<span>/ 100</span>
					<em><?php echo esc_html( Labels::band( $band ) ); ?></em>
				<?php endif; ?>
			</div>
			<div class="profitguard-coverage">
				<h3><?php esc_html_e( 'Coverage', 'profitguard-for-woocommerce' ); ?></h3>
				<p class="profitguard-card-note">
					<?php
					esc_html_e(
						'How much of your store the score is based on. Missing data lowers coverage, never the score.',
						'profitguard-for-woocommerce'
					);
					?>
				</p>
				<ul>
					<?php
					$categories = isset( $score['categories'] ) && is_array( $score['categories'] ) ? $score['categories'] : array();
					foreach ( $categories as $category ) :
						$name = Score::CATEGORY_SHIPPING === ( $category['category'] ?? '' )
							? __( 'Shipping', 'profitguard-for-woocommerce' )
							: __( 'Margin', 'profitguard-for-woocommerce' );
						$cov  = $category['coverage_percent'] ?? null;
						?>
						<li>
							<span><?php echo esc_html( $name ); ?></span>
							<strong><?php echo null === $cov ? '&mdash;' : esc_html( (string) (int) $cov . '%' ); ?></strong>
							<?php if ( ! empty( $category['unavailable_reason'] ) ) : ?>
								<em><?php echo esc_html( (string) $category['unavailable_reason'] ); ?></em>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * A statistic card.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $note  Optional note.
	 */
	private static function stat_card( string $label, string $value, string $note = '' ): void {
		?>
		<div class="profitguard-card">
			<span class="profitguard-card-label"><?php echo esc_html( $label ); ?></span>
			<span class="profitguard-card-value"><?php echo esc_html( $value ); ?></span>
			<?php if ( '' !== $note ) : ?>
				<span class="profitguard-card-note"><?php echo esc_html( $note ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Empty state before the first scan.
	 */
	private static function empty_state_no_scan(): void {
		?>
		<div class="profitguard-empty">
			<h2><?php esc_html_e( 'No scan yet', 'profitguard-for-woocommerce' ); ?></h2>
			<p>
				<?php
				esc_html_e(
					'Run a scan to see which products are priced below your target margin and which orders lost money on shipping.',
					'profitguard-for-woocommerce'
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: target margin percentage. */
					esc_html__( 'Your target gross margin is currently %s. You can change it in Settings.', 'profitguard-for-woocommerce' ),
					esc_html( Money::format_percent_bp( Settings::target_margin_bp() ) )
				);
				?>
			</p>
			<?php self::action_form( Admin::ACTION_SCAN, __( 'Run Profit Scan', 'profitguard-for-woocommerce' ), 'button button-primary' ); ?>
		</div>
		<?php
	}

	// Findings.

	/**
	 * The findings table with filters.
	 */
	public static function findings(): void {
		self::header( Admin::SLUG_FINDINGS );

		$scan_id = Repository::latest_scan_id();
		if ( 0 === $scan_id ) {
			echo '<p>' . esc_html__( 'Run a scan first and the findings will appear here.', 'profitguard-for-woocommerce' ) . '</p>';
			self::footer();
			return;
		}

		// Read-only filters from the query string. A GET that only narrows a
		// list the user may already see needs no nonce, but every value is
		// still validated against a whitelist before it reaches a query.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$module   = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : '';
		$type     = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		$severity = isset( $_GET['severity'] ) ? sanitize_text_field( wp_unslash( $_GET['severity'] ) ) : '';
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'impact';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$module   = in_array( strtoupper( $module ), array( Finding::MODULE_MARGIN, Finding::MODULE_SHIPPING ), true ) ? strtoupper( $module ) : '';
		$orderby  = in_array( $orderby, array( 'impact', 'severity', 'type', 'newest' ), true ) ? $orderby : 'impact';
		$severity = in_array(
			$severity,
			array(
				Finding::SEVERITY_CRITICAL,
				Finding::SEVERITY_HIGH,
				Finding::SEVERITY_MEDIUM,
				Finding::SEVERITY_LOW,
				Finding::SEVERITY_INFO,
			),
			true
		) ? $severity : '';

		$counts = Repository::counts_by_type( $scan_id );
		$type   = isset( $counts[ $type ] ) ? $type : '';

		$per_page = 25;
		$result   = Repository::query_findings(
			array(
				'scan_id'  => $scan_id,
				'module'   => $module,
				'type'     => $type,
				'severity' => $severity,
				'orderby'  => $orderby,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		?>
		<form method="get" class="profitguard-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG_FINDINGS ); ?>" />

			<label for="pg-module"><?php esc_html_e( 'Area', 'profitguard-for-woocommerce' ); ?></label>
			<select name="module" id="pg-module">
				<option value=""><?php esc_html_e( 'All', 'profitguard-for-woocommerce' ); ?></option>
				<option value="MARGIN" <?php selected( $module, Finding::MODULE_MARGIN ); ?>><?php esc_html_e( 'Margin', 'profitguard-for-woocommerce' ); ?></option>
				<option value="SHIPPING" <?php selected( $module, Finding::MODULE_SHIPPING ); ?>><?php esc_html_e( 'Shipping', 'profitguard-for-woocommerce' ); ?></option>
			</select>

			<label for="pg-type"><?php esc_html_e( 'Type', 'profitguard-for-woocommerce' ); ?></label>
			<select name="type" id="pg-type">
				<option value=""><?php esc_html_e( 'All', 'profitguard-for-woocommerce' ); ?></option>
				<?php foreach ( $counts as $value => $count ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $type, (string) $value ); ?>>
						<?php echo esc_html( Labels::type( (string) $value ) . ' (' . (int) $count . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="pg-severity"><?php esc_html_e( 'Severity', 'profitguard-for-woocommerce' ); ?></label>
			<select name="severity" id="pg-severity">
				<option value=""><?php esc_html_e( 'All', 'profitguard-for-woocommerce' ); ?></option>
				<?php foreach ( array( Finding::SEVERITY_CRITICAL, Finding::SEVERITY_HIGH, Finding::SEVERITY_MEDIUM, Finding::SEVERITY_LOW, Finding::SEVERITY_INFO ) as $value ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $severity, $value ); ?>>
						<?php echo esc_html( Labels::severity( $value ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="pg-orderby"><?php esc_html_e( 'Sort by', 'profitguard-for-woocommerce' ); ?></label>
			<select name="orderby" id="pg-orderby">
				<option value="impact" <?php selected( $orderby, 'impact' ); ?>><?php esc_html_e( 'Largest amount', 'profitguard-for-woocommerce' ); ?></option>
				<option value="severity" <?php selected( $orderby, 'severity' ); ?>><?php esc_html_e( 'Severity', 'profitguard-for-woocommerce' ); ?></option>
				<option value="type" <?php selected( $orderby, 'type' ); ?>><?php esc_html_e( 'Type', 'profitguard-for-woocommerce' ); ?></option>
				<option value="newest" <?php selected( $orderby, 'newest' ); ?>><?php esc_html_e( 'Newest', 'profitguard-for-woocommerce' ); ?></option>
			</select>

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'profitguard-for-woocommerce' ); ?></button>
		</form>

		<div class="profitguard-toolbar">
			<?php self::action_form( Admin::ACTION_EXPORT, __( 'Export findings (CSV)', 'profitguard-for-woocommerce' ) ); ?>
			<span class="profitguard-count">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of findings. */
						_n( '%d finding', '%d findings', (int) $result['total'], 'profitguard-for-woocommerce' ),
						(int) $result['total']
					)
				);
				?>
			</span>
		</div>
		<?php

		if ( empty( $result['rows'] ) ) {
			echo '<p>' . esc_html__( 'No findings match those filters.', 'profitguard-for-woocommerce' ) . '</p>';
		} else {
			self::findings_table( $result['rows'] );
			self::pagination( (int) $result['total'], $per_page, $paged );
		}

		self::footer();
	}

	/**
	 * Render a findings table.
	 *
	 * @param array<int, array<string, mixed>> $rows Finding rows.
	 */
	private static function findings_table( array $rows ): void {
		?>
		<table class="widefat striped profitguard-findings">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'profitguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Product / Order', 'profitguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Current', 'profitguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Target / Expected', 'profitguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Difference', 'profitguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Basis', 'profitguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Severity', 'profitguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'What to do', 'profitguard-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$type     = (string) $row['type'];
				$severity = (string) $row['severity'];
				$impact   = ( null === $row['impact_minor'] ) ? null : (int) $row['impact_minor'];
				$current  = ( null === $row['current_minor'] ) ? null : (int) $row['current_minor'];
				$expected = ( null === $row['expected_minor'] ) ? null : (int) $row['expected_minor'];
				$link     = self::subject_link( $row );
				?>
				<tr>
					<td><?php echo esc_html( Labels::type( $type ) ); ?></td>
					<td>
						<?php if ( '' !== $link ) : ?>
							<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( (string) $row['subject_label'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) $row['subject_label'] ); ?>
						<?php endif; ?>
						<?php if ( '' !== (string) $row['reference'] && (string) $row['reference'] !== (string) $row['subject_label'] ) : ?>
							<span class="profitguard-ref"><?php echo esc_html( (string) $row['reference'] ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Labels::money().
						echo Labels::money( $current );
						?>
					</td>
					<td>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Labels::money().
						echo Labels::money( $expected );
						?>
					</td>
					<td>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Labels::money().
						echo Labels::money( $impact );
						?>
					</td>
					<td>
						<span title="<?php echo esc_attr( Labels::financial_note( (string) $row['financial_type'] ) ); ?>">
							<?php echo esc_html( Labels::financial_type( (string) $row['financial_type'] ) ); ?>
						</span>
					</td>
					<td>
						<span class="profitguard-sev profitguard-sev-<?php echo esc_attr( strtolower( $severity ) ); ?>">
							<?php echo esc_html( Labels::severity( $severity ) ); ?>
						</span>
					</td>
					<td class="profitguard-action"><?php echo esc_html( Labels::action( $type ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * An edit link for a finding's subject, when there is one.
	 *
	 * @param array<string, mixed> $row Finding row.
	 * @return string
	 */
	private static function subject_link( array $row ): string {
		$id   = (int) $row['subject_id'];
		$kind = (string) $row['subject_kind'];

		if ( $id <= 0 ) {
			return '';
		}
		if ( Finding::SUBJECT_ORDER === $kind ) {
			$order = wc_get_order( $id );
			return $order ? (string) $order->get_edit_order_url() : '';
		}
		if ( Finding::SUBJECT_PRODUCT === $kind || Finding::SUBJECT_VARIATION === $kind ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				return '';
			}
			// A variation is edited on its parent's screen.
			$edit_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $id;
			return (string) get_edit_post_link( $edit_id, 'raw' );
		}
		return '';
	}

	/**
	 * Pagination.
	 *
	 * @param int $total    Total rows.
	 * @param int $per_page Rows per page.
	 * @param int $paged    Current page.
	 */
	private static function pagination( int $total, int $per_page, int $paged ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'type'      => 'array',
				'prev_text' => __( 'Previous', 'profitguard-for-woocommerce' ),
				'next_text' => __( 'Next', 'profitguard-for-woocommerce' ),
			)
		);

		if ( ! is_array( $links ) ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		foreach ( $links as $link ) {
			echo wp_kses_post( $link );
		}
		echo '</div></div>';
	}

	// Import.

	/**
	 * The import screen: upload, preview, confirm.
	 */
	public static function import(): void {
		self::header( Admin::SLUG_IMPORT );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['preview'] ) ? sanitize_text_field( wp_unslash( $_GET['preview'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $token ) {
			$preview = Importer::get_preview( $token );
			if ( null !== $preview ) {
				self::import_preview( $token, $preview );
				self::footer();
				return;
			}
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'That import expired. Please upload the file again.', 'profitguard-for-woocommerce' ) .
				'</p></div>';
		}

		$carrier = Repository::carrier_row_counts();
		?>
		<h2><?php esc_html_e( 'Product costs', 'profitguard-for-woocommerce' ); ?></h2>
		<p>
			<?php
			if ( NativeCogs::is_enabled() ) {
				esc_html_e(
					'Costs are written to WooCommerce's own Cost of Goods Sold field, so they appear in the product editor as well. Upload a CSV with a SKU column and a cost column.',
					'profitguard-for-woocommerce'
				);
			} else {
				esc_html_e(
					'Margins need a cost for each product. Upload a CSV with a SKU column and a cost column.',
					'profitguard-for-woocommerce'
				);
			}
			?>
		</p>
		<?php self::native_cogs_notice(); ?>
		<?php self::upload_form( Importer::KIND_COST ); ?>

		<h2><?php esc_html_e( 'Carrier costs', 'profitguard-for-woocommerce' ); ?></h2>
		<p>
			<?php
			esc_html_e(
				'WooCommerce knows what your customer paid for shipping, but not what your carrier billed you. Upload a carrier invoice export with an order number and an actual cost.',
				'profitguard-for-woocommerce'
			);
			?>
		</p>
		<p class="profitguard-card-note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: rows imported, 2: rows matched to an order. */
					__( '%1$d carrier rows imported, %2$d matched to an order.', 'profitguard-for-woocommerce' ),
					(int) $carrier['total'],
					(int) $carrier['matched']
				)
			);
			?>
		</p>
		<?php
		self::upload_form( Importer::KIND_CARRIER );

		if ( $carrier['total'] > 0 ) {
			self::action_form(
				Admin::ACTION_CLEAR,
				__( 'Remove all imported carrier rows', 'profitguard-for-woocommerce' ),
				'button button-link-delete'
			);
		}

		self::import_history();
		self::footer();
	}

	/**
	 * An upload form.
	 *
	 * @param string $kind Importer::KIND_*.
	 */
	private static function upload_form( string $kind ): void {
		?>
		<form method="post" enctype="multipart/form-data" class="profitguard-upload">
			<?php wp_nonce_field( Admin::ACTION_UPLOAD ); ?>
			<input type="hidden" name="profitguard_action" value="<?php echo esc_attr( Admin::ACTION_UPLOAD ); ?>" />
			<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" />
			<input type="file" name="profitguard_file" accept=".csv,text/csv,text/plain" required />
			<button type="submit" class="button"><?php esc_html_e( 'Upload and preview', 'profitguard-for-woocommerce' ); ?></button>
			<p class="profitguard-card-note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: maximum upload size. */
						__( 'CSV only, up to %s. Nothing is saved until you confirm the preview.', 'profitguard-for-woocommerce' ),
						size_format( Importer::MAX_BYTES )
					)
				);
				?>
			</p>
		</form>
		<?php
	}

	/**
	 * The preview and column-mapping step.
	 *
	 * @param string               $token   Preview token.
	 * @param array<string, mixed> $preview Preview payload.
	 */
	private static function import_preview( string $token, array $preview ): void {
		$headers = is_array( $preview['headers'] ) ? $preview['headers'] : array();
		$rows    = is_array( $preview['rows'] ) ? $preview['rows'] : array();
		$kind    = (string) $preview['kind'];
		$mapping = Csv::suggest_columns( $headers );

		$concepts = Importer::KIND_COST === $kind
			? array(
				'sku'      => __( 'SKU (required)', 'profitguard-for-woocommerce' ),
				'cost'     => __( 'Cost (required)', 'profitguard-for-woocommerce' ),
				'currency' => __( 'Currency', 'profitguard-for-woocommerce' ),
				'name'     => __( 'Product name', 'profitguard-for-woocommerce' ),
			)
			: array(
				'order'       => __( 'Order number (required)', 'profitguard-for-woocommerce' ),
				'actual_cost' => __( 'Actual shipping cost (required)', 'profitguard-for-woocommerce' ),
				'tracking'    => __( 'Tracking number', 'profitguard-for-woocommerce' ),
				'carrier'     => __( 'Carrier', 'profitguard-for-woocommerce' ),
				'currency'    => __( 'Currency', 'profitguard-for-woocommerce' ),
				'surcharge'   => __( 'Surcharge', 'profitguard-for-woocommerce' ),
				'adjustment'  => __( 'Adjustment', 'profitguard-for-woocommerce' ),
				'date'        => __( 'Shipping date', 'profitguard-for-woocommerce' ),
			);

		?>
		<h2><?php esc_html_e( 'Check the columns', 'profitguard-for-woocommerce' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of data rows found. */
					__( '%d rows found. Nothing has been saved yet.', 'profitguard-for-woocommerce' ),
					count( $rows )
				)
			);
			?>
		</p>

		<form method="post">
			<?php wp_nonce_field( Admin::ACTION_COMMIT ); ?>
			<input type="hidden" name="profitguard_action" value="<?php echo esc_attr( Admin::ACTION_COMMIT ); ?>" />
			<input type="hidden" name="preview" value="<?php echo esc_attr( $token ); ?>" />

			<table class="form-table" role="presentation">
				<tbody>
				<?php foreach ( $concepts as $concept => $label ) : ?>
					<tr>
						<th scope="row">
							<label for="pg-map-<?php echo esc_attr( $concept ); ?>"><?php echo esc_html( $label ); ?></label>
						</th>
						<td>
							<select name="mapping[<?php echo esc_attr( $concept ); ?>]" id="pg-map-<?php echo esc_attr( $concept ); ?>">
								<option value="-1"><?php esc_html_e( '— not in this file —', 'profitguard-for-woocommerce' ); ?></option>
								<?php foreach ( $headers as $index => $header ) : ?>
									<option
										value="<?php echo esc_attr( (string) $index ); ?>"
										<?php selected( isset( $mapping[ $concept ] ) ? (int) $mapping[ $concept ] : -1, (int) $index ); ?>
									>
										<?php
										echo esc_html(
											'' === trim( (string) $header )
												? sprintf(
													/* translators: %d: column number. */
													__( 'Column %d', 'profitguard-for-woocommerce' ),
													(int) $index + 1
												)
												: (string) $header
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'First rows in your file', 'profitguard-for-woocommerce' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<?php foreach ( $headers as $header ) : ?>
							<th><?php echo esc_html( (string) $header ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( array_slice( $rows, 0, 10 ) as $row ) : ?>
					<tr>
						<?php foreach ( array_keys( $headers ) as $index ) : ?>
							<td><?php echo esc_html( isset( $row[ $index ] ) ? (string) $row[ $index ] : '' ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( Importer::KIND_COST === $kind ) : ?>
				<?php
				// Resolve every row against the store BEFORE anything is
				// written, so the merchant sees current -> new per row rather
				// than a raw CSV they have to trust.
				$plan = Importer::cost_change_plan( $rows, $mapping );
				?>
				<h3><?php esc_html_e( 'What this would change', 'profitguard-for-woocommerce' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'SKU', 'profitguard-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Cost now', 'profitguard-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Cost after import', 'profitguard-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Where the current cost lives', 'profitguard-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $plan['rows'] as $change ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $change['sku'] ); ?></td>
							<td>
								<?php
								// Labels::money() is already escaped and renders
								// null as a dash rather than as a zero.
								echo wp_kses_post( Labels::money( $change['current_minor'] ) );
								?>
							</td>
							<td>
								<?php echo wp_kses_post( Labels::money( (int) $change['new_minor'] ) ); ?>
								<?php if ( $change['unmatched'] ) : ?>
									<em><?php esc_html_e( '- no product with this SKU', 'profitguard-for-woocommerce' ); ?></em>
								<?php elseif ( $change['unchanged'] ) : ?>
									<em><?php esc_html_e( '- no change', 'profitguard-for-woocommerce' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( Labels::cost_source( (string) $change['source'] ) ); ?>
								<?php if ( $change['replaces_native'] ) : ?>
									<strong><?php esc_html_e( '- would be replaced', 'profitguard-for-woocommerce' ); ?></strong>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $plan['native_overwrites'] > 0 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of rows that would replace a cost in WooCommerce's own field. */
									_n(
										'%d row would replace a cost held in WooCommerce\'s own Cost of Goods Sold field - a value you or someone else entered in the product editor.',
										'%d rows would replace costs held in WooCommerce\'s own Cost of Goods Sold field - values you or someone else entered in the product editor.',
										(int) $plan['native_overwrites'],
										'profitguard-for-woocommerce'
									),
									(int) $plan['native_overwrites']
								)
							);
							?>
						</p>
						<p>
							<label>
								<input type="checkbox" name="confirm_native_overwrite" value="1" />
								<?php esc_html_e( 'Yes, replace those existing costs.', 'profitguard-for-woocommerce' ); ?>
							</label>
						</p>
						<p>
							<?php esc_html_e( 'Leave it unticked and those rows are skipped; everything else still imports.', 'profitguard-for-woocommerce' ); ?>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm and import', 'profitguard-for-woocommerce' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_IMPORT ) ); ?>"><?php esc_html_e( 'Cancel', 'profitguard-for-woocommerce' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Recent imports and scans.
	 */
	private static function import_history(): void {
		$runs = Repository::recent_runs( 10 );
		if ( empty( $runs ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Recent activity', 'profitguard-for-woocommerce' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'profitguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'What', 'profitguard-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Result', 'profitguard-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $runs as $run ) : ?>
				<?php
				$kind_label = array(
					Repository::RUN_SCAN           => __( 'Profit scan', 'profitguard-for-woocommerce' ),
					Repository::RUN_COST_IMPORT    => __( 'Product cost import', 'profitguard-for-woocommerce' ),
					Repository::RUN_CARRIER_IMPORT => __( 'Carrier cost import', 'profitguard-for-woocommerce' ),
				);
				$totals     = is_array( $run['totals'] ) ? $run['totals'] : array();

				if ( Repository::RUN_COST_IMPORT === $run['kind'] ) {
					$summary = sprintf(
						/* translators: 1: products updated, 2: rows rejected. */
						__( '%1$d updated, %2$d rejected', 'profitguard-for-woocommerce' ),
						(int) ( $totals['updated'] ?? 0 ),
						(int) ( $totals['rejected'] ?? 0 )
					);
				} elseif ( Repository::RUN_CARRIER_IMPORT === $run['kind'] ) {
					$summary = sprintf(
						/* translators: 1: rows added, 2: rows matched, 3: rows rejected. */
						__( '%1$d added, %2$d matched, %3$d rejected', 'profitguard-for-woocommerce' ),
						(int) ( $totals['inserted'] ?? 0 ),
						(int) ( $totals['matched'] ?? 0 ),
						(int) ( $totals['rejected'] ?? 0 )
					);
				} else {
					$margin  = isset( $totals['margin'] ) && is_array( $totals['margin'] ) ? $totals['margin'] : array();
					$summary = sprintf(
						/* translators: %d: products analysed. */
						__( '%d products analysed', 'profitguard-for-woocommerce' ),
						(int) ( $margin['products_seen'] ?? 0 )
					);
				}//end if
				?>
				<tr>
					<td><?php echo esc_html( (string) $run['started_at'] ); ?></td>
					<td><?php echo esc_html( $kind_label[ $run['kind'] ] ?? (string) $run['kind'] ); ?></td>
					<td><?php echo esc_html( Repository::STATUS_COMPLETED === $run['status'] ? $summary : (string) $run['status'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// Settings.

	/**
	 * The settings screen.
	 */
	public static function settings(): void {
		self::header( Admin::SLUG_SETTINGS );

		$target = Settings::target_margin_bp();
		?>
		<form method="post">
			<?php wp_nonce_field( Admin::ACTION_SETTINGS ); ?>
			<input type="hidden" name="profitguard_action" value="<?php echo esc_attr( Admin::ACTION_SETTINGS ); ?>" />

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="pg-target"><?php esc_html_e( 'Target gross margin', 'profitguard-for-woocommerce' ); ?></label></th>
						<td>
							<input
								type="number" id="pg-target" name="settings[target_margin_percent]"
								value="<?php echo esc_attr( (string) round( $target / 100, 2 ) ); ?>"
								min="0" max="99" step="0.1" class="small-text"
							/> %
							<p class="description">
								<?php esc_html_e( 'Products below this are flagged. 30% is a common starting point for general retail.', 'profitguard-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pg-retention"><?php esc_html_e( 'Keep scan history for', 'profitguard-for-woocommerce' ); ?></label></th>
						<td>
							<input
								type="number" id="pg-retention" name="settings[scan_retention_days]"
								value="<?php echo esc_attr( (string) Settings::scan_retention_days() ); ?>"
								min="1" max="3650" step="1" class="small-text"
							/> <?php esc_html_e( 'days', 'profitguard-for-woocommerce' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Store currency', 'profitguard-for-woocommerce' ); ?></th>
						<td>
							<p><?php echo esc_html( get_woocommerce_currency() ); ?></p>
							<p class="description">
								<?php
								esc_html_e(
									'Taken from WooCommerce. Imported rows in a different currency are rejected rather than converted, because ProfitGuard has no exchange rate and will not invent one.',
									'profitguard-for-woocommerce'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'profitguard-for-woocommerce' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox" name="settings[delete_data_on_uninstall]" value="1"
									<?php checked( Settings::delete_data_on_uninstall() ); ?>
								/>
								<?php esc_html_e( 'Delete all ProfitGuard data when the plugin is deleted', 'profitguard-for-woocommerce' ); ?>
							</label>
							<p class="description">
								<?php
								esc_html_e(
									'Off by default. Deactivating never deletes anything; this only applies when you delete the plugin outright.',
									'profitguard-for-woocommerce'
								);
								?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'profitguard-for-woocommerce' ); ?></button>
			</p>
		</form>

		<h2><?php esc_html_e( 'Privacy', 'profitguard-for-woocommerce' ); ?></h2>
		<p>
			<?php
			esc_html_e(
				'ProfitGuard runs entirely inside this WordPress installation. It makes no external requests, sends no analytics, and requires no account or API key. There is no telemetry of any kind in this plugin.',
				'profitguard-for-woocommerce'
			);
			?>
		</p>

		<?php
		self::footer();
	}
	/**
	 * Tell the merchant about WooCommerce's own cost field, when it is off.
	 *
	 * WooCommerce 10.3 added Cost of Goods Sold to core, but it is opt-in and
	 * disabled by default, so most stores have it available and unused. Saying
	 * so is more useful than silently writing costs into ProfitGuard's own key
	 * and leaving the merchant wondering why the product editor shows nothing.
	 *
	 * This notice does not enable anything. It points at the setting and says
	 * what turning it on would give them; nothing is ever written into the
	 * feature's storage while it is disabled.
	 */
	private static function native_cogs_notice(): void {
		if ( NativeCogs::is_enabled() || ! NativeCogs::is_available() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' );
		?>
		<div class="notice notice-info inline">
			<p>
				<?php
				esc_html_e(
					'WooCommerce has a built-in Cost of Goods Sold field, and it is switched off for this store. Turning it on lets ProfitGuard read and write the same cost you see in the product editor, and lets those costs feed WooCommerce\'s own analytics. ProfitGuard works either way, and will not write into the feature while it is disabled.',
					'profitguard-for-woocommerce'
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Open WooCommerce feature settings', 'profitguard-for-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
