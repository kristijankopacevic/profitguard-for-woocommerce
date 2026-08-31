<?php
/**
 * Plugin settings.
 *
 * Everything lives in ONE option, stored as an array. A dozen separate options
 * would be a dozen autoloaded rows on every page load of the entire site for no
 * benefit - the settings are always read together or not at all.
 *
 * Every getter validates and clamps on the way OUT as well as sanitising on the
 * way in. An option row can be edited by another plugin, by WP-CLI, or by a
 * database restore from an older version, so "it was valid when we saved it" is
 * not a guarantee the reader can rely on.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Plugin;

use ProfitGuard\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to plugin settings.
 */
final class Settings {

	public const OPTION = 'profitguard_settings';

	/** Capability required to see or change anything in ProfitGuard. */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Defaults.
	 *
	 * A 30% target is the figure the brief uses and a defensible default for
	 * general retail. It is a starting point the merchant is asked to confirm
	 * during onboarding, not a claim about their business.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'target_margin_bp'         => 3000,
			'warning_band_bp'          => 500,
			'critical_band_bp'         => 1500,
			'scan_retention_days'      => 90,
			'delete_data_on_uninstall' => false,
			'onboarding_dismissed'     => false,
			'last_scan_at'             => 0,
		);
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback when absent.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * The target gross margin in basis points, clamped to a usable range.
	 *
	 * Clamped at 9,900 (99%) because no finite price achieves a 100% margin and
	 * a target of 100% or more makes every recommended price null - which looks
	 * exactly like the plugin being broken.
	 *
	 * @return int
	 */
	public static function target_margin_bp(): int {
		$value = (int) self::get( 'target_margin_bp', 3000 );
		return max( 0, min( 9900, $value ) );
	}

	/**
	 * Warning band width in basis points.
	 *
	 * @return int
	 */
	public static function warning_band_bp(): int {
		return max( 0, (int) self::get( 'warning_band_bp', 500 ) );
	}

	/**
	 * Critical band width in basis points.
	 *
	 * @return int
	 */
	public static function critical_band_bp(): int {
		return max( 0, (int) self::get( 'critical_band_bp', 1500 ) );
	}

	/**
	 * How many days of scan history to keep.
	 *
	 * @return int
	 */
	public static function scan_retention_days(): int {
		return max( 1, min( 3650, (int) self::get( 'scan_retention_days', 90 ) ) );
	}

	/**
	 * Whether uninstall should erase ProfitGuard data.
	 *
	 * Defaults to FALSE. A merchant who deactivates a plugin to troubleshoot
	 * and loses their imported cost data would have no way to get it back, so
	 * destruction is strictly opt-in.
	 *
	 * @return bool
	 */
	public static function delete_data_on_uninstall(): bool {
		return (bool) self::get( 'delete_data_on_uninstall', false );
	}

	/**
	 * Whether the onboarding notice has been dismissed or satisfied.
	 *
	 * @return bool
	 */
	public static function onboarding_dismissed(): bool {
		return (bool) self::get( 'onboarding_dismissed', false );
	}

	/**
	 * Persist a partial settings update.
	 *
	 * @param array<string, mixed> $changes Values to merge.
	 */
	public static function update( array $changes ): void {
		update_option( self::OPTION, array_merge( self::all(), $changes ), false );
	}

	/**
	 * Sanitise a submitted settings form.
	 *
	 * The target margin arrives as a PERCENT from the form and is stored as
	 * basis points. The conversion happens here, server-side, because a client
	 * that can send basis points can send 350000.
	 *
	 * @param array<string, mixed> $input Raw $_POST slice, already unslashed.
	 * @return array<string, mixed> Values safe to store.
	 */
	public static function sanitise( array $input ): array {
		$clean = array();

		if ( isset( $input['target_margin_percent'] ) ) {
			$percent = Money::parse_percent_to_bp( sanitize_text_field( (string) $input['target_margin_percent'] ) );
			// A blank or unparseable value keeps the current setting rather
			// than silently resetting a merchant's target to a default.
			if ( null !== $percent ) {
				$clean['target_margin_bp'] = max( 0, min( 9900, $percent ) );
			}
		}

		if ( isset( $input['scan_retention_days'] ) ) {
			$clean['scan_retention_days'] = max( 1, min( 3650, absint( $input['scan_retention_days'] ) ) );
		}

		// An unchecked checkbox is absent from the POST body entirely, so its
		// absence has to mean false rather than "unchanged".
		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		return $clean;
	}
}
