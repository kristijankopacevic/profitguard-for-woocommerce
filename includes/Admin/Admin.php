<?php
/**
 * Admin controller: menus, assets, notices, and every form POST.
 *
 * SECURITY. Every state-changing request in this plugin passes through
 * handle_post(), and that method applies the same three checks to all of them
 * before dispatching:
 *
 *   1. the user holds Settings::CAPABILITY (manage_woocommerce),
 *   2. a valid, action-specific nonce is present,
 *   3. input is unslashed and sanitised at the point of use.
 *
 * Centralising it means a new action cannot accidentally ship without them,
 * which is exactly how privilege-escalation bugs get into plugins.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Admin;

use ProfitGuard\Import\Importer;
use ProfitGuard\Plugin\Repository;
use ProfitGuard\Plugin\Settings;
use ProfitGuard\Scan\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The admin experience.
 */
final class Admin {

	public const SLUG          = 'profitguard';
	public const SLUG_FINDINGS = 'profitguard-findings';
	public const SLUG_IMPORT   = 'profitguard-import';
	public const SLUG_SETTINGS = 'profitguard-settings';

	public const ACTION_SCAN     = 'profitguard_scan';
	public const ACTION_CANCEL   = 'profitguard_cancel_scan';
	public const ACTION_UPLOAD   = 'profitguard_upload';
	public const ACTION_COMMIT   = 'profitguard_commit';
	public const ACTION_SETTINGS = 'profitguard_save_settings';
	public const ACTION_DISMISS  = 'profitguard_dismiss_notice';
	public const ACTION_EXPORT   = 'profitguard_export';
	public const ACTION_CLEAR    = 'profitguard_clear_carrier';

	/**
	 * Singleton.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/**
	 * Notice to show after a redirect.
	 *
	 * @var array{type:string,message:string}|null
	 */
	private $flash = null;

	/**
	 * Instance accessor.
	 *
	 * @return Admin
	 */
	public static function instance(): Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( PROFITGUARD_FILE ),
			array( $this, 'plugin_action_links' )
		);
	}

	/**
	 * Add the WooCommerce submenu.
	 */
	public function register_menu(): void {
		$capability = Settings::CAPABILITY;

		add_submenu_page(
			'woocommerce',
			__( 'ProfitGuard', 'profitguard-for-woocommerce' ),
			__( 'ProfitGuard', 'profitguard-for-woocommerce' ),
			$capability,
			self::SLUG,
			array( $this, 'render_dashboard' )
		);

		// Registered under a null parent so they are reachable and their
		// capability is enforced, without adding four items to the WooCommerce
		// menu. Sub-tabs inside the plugin do the navigation.
		add_submenu_page( '', __( 'ProfitGuard findings', 'profitguard-for-woocommerce' ), '', $capability, self::SLUG_FINDINGS, array( $this, 'render_findings' ) );
		add_submenu_page( '', __( 'ProfitGuard import', 'profitguard-for-woocommerce' ), '', $capability, self::SLUG_IMPORT, array( $this, 'render_import' ) );
		add_submenu_page( '', __( 'ProfitGuard settings', 'profitguard-for-woocommerce' ), '', $capability, self::SLUG_SETTINGS, array( $this, 'render_settings' ) );
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function plugin_action_links( $links ): array {
		$links   = is_array( $links ) ? $links : array();
		$url     = admin_url( 'admin.php?page=' . self::SLUG );
		$prepend = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Dashboard', 'profitguard-for-woocommerce' ) . '</a>';
		array_unshift( $links, $prepend );
		return $links;
	}

	/**
	 * Load the stylesheet, only on ProfitGuard screens.
	 *
	 * Enqueueing on every admin page is one of the commonest ways a plugin
	 * breaks somebody else's screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		if ( false === strpos( (string) $hook, 'profitguard' ) ) {
			return;
		}
		wp_enqueue_style(
			'profitguard-admin',
			PROFITGUARD_URL . 'assets/css/admin.css',
			array(),
			PROFITGUARD_VERSION
		);
	}

	// POST handling.

	/**
	 * Dispatch a ProfitGuard form submission.
	 *
	 * One entry point, one set of checks. Nothing here trusts $_POST beyond
	 * reading the action name, and every branch re-verifies its own nonce.
	 */
	public function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only reads the action name; each branch verifies its own nonce before doing anything.
		$action = isset( $_POST['profitguard_action'] ) ? sanitize_key( wp_unslash( $_POST['profitguard_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage ProfitGuard.', 'profitguard-for-woocommerce' ),
				403
			);
		}

		check_admin_referer( $action );

		switch ( $action ) {
			case self::ACTION_SCAN:
				$this->do_scan();
				break;
			case self::ACTION_CANCEL:
				Scanner::cancel();
				$this->redirect( self::SLUG, 'success', __( 'Scan cancelled.', 'profitguard-for-woocommerce' ) );
				break;
			case self::ACTION_UPLOAD:
				$this->do_upload();
				break;
			case self::ACTION_COMMIT:
				$this->do_commit();
				break;
			case self::ACTION_SETTINGS:
				$this->do_settings();
				break;
			case self::ACTION_DISMISS:
				Settings::update( array( 'onboarding_dismissed' => true ) );
				$this->redirect( self::SLUG, 'success', '' );
				break;
			case self::ACTION_CLEAR:
				$removed = Repository::clear_carrier_rows();
				$this->redirect(
					self::SLUG_IMPORT,
					'success',
					sprintf(
						/* translators: %d: number of rows removed. */
						_n( '%d imported carrier row removed.', '%d imported carrier rows removed.', $removed, 'profitguard-for-woocommerce' ),
						$removed
					)
				);
				break;
			case self::ACTION_EXPORT:
				Exporter::send_findings_csv();
				break;
		}//end switch
	}

	/**
	 * Start a scan.
	 */
	private function do_scan(): void {
		$result = Scanner::start();

		if ( ! $result['started'] ) {
			$this->redirect( self::SLUG, 'info', __( 'A scan is already running.', 'profitguard-for-woocommerce' ) );
			return;
		}

		$message = Scanner::scheduler_available()
			? __( 'Scan started. This page updates as it progresses.', 'profitguard-for-woocommerce' )
			: __( 'Scan complete.', 'profitguard-for-woocommerce' );

		$this->redirect( self::SLUG, 'success', $message );
	}

	/**
	 * Handle a CSV upload and build a preview.
	 */
	private function do_upload(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in handle_post() before this was dispatched.
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		if ( ! in_array( $kind, array( Importer::KIND_COST, Importer::KIND_CARRIER ), true ) ) {
			$this->redirect( self::SLUG_IMPORT, 'error', __( 'Unknown import type.', 'profitguard-for-woocommerce' ) );
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce checked in handle_post(); the array is validated field by field inside read_upload(), and sanitising the raw $_FILES entry here would corrupt tmp_name.
		$file = isset( $_FILES['profitguard_file'] ) ? (array) $_FILES['profitguard_file'] : array();

		$read = Importer::read_upload( $file );
		if ( ! $read['ok'] ) {
			$this->redirect( self::SLUG_IMPORT, 'error', Importer::upload_error_label( $read['error'] ) );
			return;
		}

		$preview = Importer::build_preview( $read['contents'], $kind );
		if ( ! $preview['ok'] ) {
			$this->redirect( self::SLUG_IMPORT, 'error', Importer::upload_error_label( $preview['error'] ) );
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG_IMPORT,
					'kind'    => $kind,
					'preview' => rawurlencode( $preview['token'] ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Commit a previewed import.
	 */
	private function do_commit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in handle_post() before this was dispatched.
		$token   = isset( $_POST['preview'] ) ? sanitize_text_field( wp_unslash( $_POST['preview'] ) ) : '';
		$preview = Importer::get_preview( $token );

		if ( null === $preview ) {
			$this->redirect(
				self::SLUG_IMPORT,
				'error',
				__( 'That import expired before it was confirmed. Please upload the file again.', 'profitguard-for-woocommerce' )
			);
			return;
		}

		// The mapping the merchant confirmed, which may differ from what was
		// suggested. Each index is cast to int, so a crafted value cannot
		// reach an array lookup as anything else.
		$mapping = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in handle_post(); each key and value is sanitised individually in the loop below, which a blanket sanitiser on the array would not do correctly.
		$raw_mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array();
		foreach ( $raw_mapping as $concept => $index ) {
			$concept = sanitize_key( (string) $concept );
			$index   = (int) $index;
			if ( '' !== $concept && $index >= 0 ) {
				$mapping[ $concept ] = $index;
			}
		}

		$rows = is_array( $preview['rows'] ) ? $preview['rows'] : array();

		if ( Importer::KIND_COST === $preview['kind'] ) {
			// Replacing a cost held in WooCommerce's own COGS field needs an
			// explicit tick on the preview screen. Absent it, those rows are
			// refused rather than applied.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in handle_post() before this was dispatched.
			$allow_native = isset( $_POST['confirm_native_overwrite'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['confirm_native_overwrite'] ) );

			$totals  = Importer::commit_costs( $rows, $mapping, $allow_native );
			$message = sprintf(
				/* translators: 1: products updated, 2: rows that matched nothing, 3: rows rejected. */
				__( 'Costs imported: %1$d products updated, %2$d rows matched no product, %3$d rows rejected.', 'profitguard-for-woocommerce' ),
				(int) $totals['updated'],
				(int) $totals['unmatched'],
				(int) $totals['rejected']
			);

			if ( ! empty( $totals['blocked'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: %d: rows left unchanged because they would have replaced a cost in WooCommerce's own field. */
					_n(
						'%d row was left alone because it would have replaced a cost held in the WooCommerce Cost of Goods Sold field.',
						'%d rows were left alone because they would have replaced a cost held in the WooCommerce Cost of Goods Sold field.',
						(int) $totals['blocked'],
						'profitguard-for-woocommerce'
					),
					(int) $totals['blocked']
				);
			}
		} else {
			$totals  = Importer::commit_carrier( $rows, $mapping );
			$message = sprintf(
				/* translators: 1: rows imported, 2: rows matched to orders, 3: duplicates skipped, 4: rows rejected. */
				__( 'Carrier costs imported: %1$d rows added, %2$d matched an order, %3$d duplicates skipped, %4$d rejected.', 'profitguard-for-woocommerce' ),
				(int) $totals['inserted'],
				(int) $totals['matched'],
				(int) $totals['duplicate'],
				(int) $totals['rejected']
			);
		}

		Importer::forget_preview( $token );

		$this->redirect( self::SLUG_IMPORT, 'success', $message );
	}

	/**
	 * Save settings.
	 */
	private function do_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in handle_post(); every field is sanitised and clamped in Settings::sanitise() on the next line.
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

		Settings::update( Settings::sanitise( $raw ) );

		$this->redirect( self::SLUG_SETTINGS, 'success', __( 'Settings saved.', 'profitguard-for-woocommerce' ) );
	}

	/**
	 * Redirect back to a ProfitGuard page with a flash message.
	 *
	 * The message travels in a transient keyed to the user rather than in the
	 * query string: a message in the URL can be set by anyone who can make the
	 * merchant click a link, which is a small but real way to put arbitrary
	 * text on an admin screen.
	 *
	 * @param string $page    Page slug.
	 * @param string $type    success|error|info.
	 * @param string $message Message.
	 */
	private function redirect( string $page, string $type, string $message ): void {
		if ( '' !== $message ) {
			set_transient(
				'profitguard_notice_' . get_current_user_id(),
				array(
					'type'    => $type,
					'message' => $message,
				),
				60
			);
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . rawurlencode( $page ) ) );
		exit;
	}

	// Notices.

	/**
	 * Render the flash notice and, once, the onboarding prompt.
	 */
	public function render_notices(): void {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			return;
		}

		$key   = 'profitguard_notice_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( is_array( $flash ) && ! empty( $flash['message'] ) ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( in_array( $flash['type'], array( 'success', 'error', 'info', 'warning' ), true ) ? $flash['type'] : 'info' ),
				esc_html( (string) $flash['message'] )
			);
		}

		$this->maybe_render_onboarding();
	}

	/**
	 * The one-time onboarding prompt.
	 *
	 * Shown ONCE, only to users who can act on it, only until the first scan or
	 * an explicit dismissal, and never on ProfitGuard's own pages (where the
	 * same button is already on screen). A plugin that keeps re-announcing
	 * itself on the dashboard is the behaviour the WordPress.org guidelines
	 * exist to discourage.
	 */
	private function maybe_render_onboarding(): void {
		if ( Settings::onboarding_dismissed() ) {
			return;
		}
		if ( Settings::get( 'last_scan_at' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'profitguard' ) ) {
			return;
		}

		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'ProfitGuard is ready. Run your first profit scan.', 'profitguard-for-woocommerce' ); ?></strong></p>
			<p>
				<?php
				esc_html_e(
					'It reads your products and orders inside this WordPress installation. Nothing is sent anywhere.',
					'profitguard-for-woocommerce'
				);
				?>
			</p>
			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" style="display:inline">
					<?php wp_nonce_field( self::ACTION_SCAN ); ?>
					<input type="hidden" name="profitguard_action" value="<?php echo esc_attr( self::ACTION_SCAN ); ?>" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Run Profit Scan', 'profitguard-for-woocommerce' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" style="display:inline">
					<?php wp_nonce_field( self::ACTION_DISMISS ); ?>
					<input type="hidden" name="profitguard_action" value="<?php echo esc_attr( self::ACTION_DISMISS ); ?>" />
					<button type="submit" class="button-link"><?php esc_html_e( 'Not now', 'profitguard-for-woocommerce' ); ?></button>
				</form>
			</p>
		</div>
		<?php
	}

	// Page renderers.

	/**
	 * Guard every page render.
	 */
	private function guard(): void {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view ProfitGuard.', 'profitguard-for-woocommerce' ),
				403
			);
		}
	}

	/**
	 * Dashboard.
	 */
	public function render_dashboard(): void {
		$this->guard();
		Pages::dashboard();
	}

	/**
	 * Findings table.
	 */
	public function render_findings(): void {
		$this->guard();
		Pages::findings();
	}

	/**
	 * Imports.
	 */
	public function render_import(): void {
		$this->guard();
		Pages::import();
	}

	/**
	 * Settings.
	 */
	public function render_settings(): void {
		$this->guard();
		Pages::settings();
	}
}
