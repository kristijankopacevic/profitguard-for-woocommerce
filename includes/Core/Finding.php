<?php
/**
 * One finding: something worth a merchant's attention.
 *
 * Ported from the ProfitGuard TypeScript core (lib/findings/types.ts), with two
 * deliberate departures for WooCommerce.
 *
 * DEPARTURE 1 - NO PLAN GATE. The TypeScript product caps how many findings a
 * free merchant may open. That is exactly the "20 findings free, pay to unlock
 * the rest" pattern the WordPress.org guidelines forbid, so the concept does
 * not exist here at all. There is no `locked` count and no visible-findings
 * limit anywhere in this plugin.
 *
 * DEPARTURE 2 - MISSING_COST IS PER-PRODUCT. The Shopify version emits one
 * catalog-level "121 variants have no cost" finding, because 121 identical rows
 * would bury the four negative-margin products. WooCommerce needs per-product
 * rows so the findings table can filter and export them - so the rows exist in
 * the data model, and the DASHBOARD summarises them to a count instead of
 * listing them. Same problem, solved at the presentation layer.
 *
 * THE RULE THAT MATTERS MOST
 *
 * `impact_minor` is `int|null`, and null is not zero. A finding whose monetary
 * effect cannot be established from evidence the merchant actually has - a
 * low-margin product that never sold, an order with no carrier invoice - is
 * still a real finding worth showing. What it is NOT is an amount. Summing it
 * as zero understates the total; inventing a figure would be a lie. So it stays
 * null, it is counted, it is excluded from money totals, and the aggregate
 * reports how many were excluded.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Core;

defined( 'ABSPATH' ) || defined( 'PROFITGUARD_TESTING' ) || exit;

/**
 * An immutable finding. Pure PHP: no WordPress, no WooCommerce.
 */
final class Finding {

	// Modules.

	public const MODULE_MARGIN   = 'MARGIN';
	public const MODULE_SHIPPING = 'SHIPPING';

	// Margin finding types.

	public const TYPE_HEALTHY                = 'HEALTHY';
	public const TYPE_LOW_MARGIN             = 'LOW_MARGIN';
	public const TYPE_CRITICAL_MARGIN        = 'CRITICAL_MARGIN';
	public const TYPE_NEGATIVE_MARGIN        = 'NEGATIVE_MARGIN';
	public const TYPE_MISSING_COST           = 'MISSING_COST';
	public const TYPE_SALE_PRICE_MARGIN_RISK = 'SALE_PRICE_MARGIN_RISK';
	public const TYPE_COST_INCREASE          = 'COST_INCREASE';

	// Shipping finding types.

	public const TYPE_SHIPPING_PROFIT                = 'SHIPPING_PROFIT';
	public const TYPE_SHIPPING_LOSS                  = 'SHIPPING_LOSS';
	public const TYPE_HIGH_SHIPPING_LOSS             = 'HIGH_SHIPPING_LOSS';
	public const TYPE_MISSING_CARRIER_COST           = 'MISSING_CARRIER_COST';
	public const TYPE_POSSIBLE_DUPLICATE_CARRIER_ROW = 'POSSIBLE_DUPLICATE_CARRIER_ROW';
	public const TYPE_UNMATCHED_CARRIER_ROW          = 'UNMATCHED_CARRIER_ROW';

	// Severity.

	public const SEVERITY_INFO     = 'INFO';
	public const SEVERITY_LOW      = 'LOW';
	public const SEVERITY_MEDIUM   = 'MEDIUM';
	public const SEVERITY_HIGH     = 'HIGH';
	public const SEVERITY_CRITICAL = 'CRITICAL';

	/*
	--------------------------------------------------------------- *
	 * Financial classification
	 *
	 * What KIND of claim the money figure is making. A merchant reads these
	 * very differently: a confirmed calculation is arithmetic on data they
	 * already have, an evidenced difference is two documents disagreeing, and
	 * an estimate is a projection. Presenting them identically teaches a
	 * merchant to distrust all three.
	 */

	/** Arithmetic on data the store already holds. Exact. */
	public const FINANCIAL_CONFIRMED_CALCULATION = 'CONFIRMED_CALCULATION';
	/** Two sources disagree by this amount, both supplied by the merchant. */
	public const FINANCIAL_EVIDENCED_DIFFERENCE = 'EVIDENCED_DIFFERENCE';
	/** Projected from real data over a stated period. */
	public const FINANCIAL_ESTIMATED_IMPACT = 'ESTIMATED_IMPACT';
	/** No figure can be stated, and that is the finding. */
	public const FINANCIAL_MISSING_DATA = 'MISSING_DATA';

	// Subject kinds.

	public const SUBJECT_PRODUCT    = 'product';
	public const SUBJECT_VARIATION  = 'variation';
	public const SUBJECT_ORDER      = 'order';
	public const SUBJECT_IMPORT_ROW = 'import_row';

	/**
	 * Module: MODULE_MARGIN or MODULE_SHIPPING.
	 *
	 * @var string
	 */
	public $module;

	/**
	 * Finding type, one of the TYPE_* constants.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Severity, one of the SEVERITY_* constants.
	 *
	 * @var string
	 */
	public $severity;

	/**
	 * Financial classification, one of the FINANCIAL_* constants.
	 *
	 * @var string
	 */
	public $financial_type;

	/**
	 * Monetary effect in minor units. NULL when none can be established.
	 *
	 * @var int|null
	 */
	public $impact_minor;

	/**
	 * Confidence 0-100 that the finding is real. Not how large it is.
	 *
	 * @var int
	 */
	public $confidence;

	/**
	 * Subject kind, one of the SUBJECT_* constants.
	 *
	 * @var string
	 */
	public $subject_kind;

	/**
	 * WooCommerce object id, or 0 when the subject is not a stored object.
	 *
	 * @var int
	 */
	public $subject_id;

	/**
	 * Human label: a product name, "Order #1842".
	 *
	 * @var string
	 */
	public $subject_label;

	/**
	 * The current figure, in minor units, or null.
	 *
	 * @var int|null
	 */
	public $current_minor;

	/**
	 * The expected or target figure, in minor units, or null.
	 *
	 * @var int|null
	 */
	public $expected_minor;

	/**
	 * Structured supporting numbers, so the merchant can check our work.
	 *
	 * @var array<string, scalar|null>
	 */
	public $evidence;

	/**
	 * Sortable secondary key: SKU, order number.
	 *
	 * @var string
	 */
	public $reference;

	/**
	 * Construct a finding.
	 *
	 * @param array<string, mixed> $args Field values.
	 */
	public function __construct( array $args ) {
		$this->module         = (string) ( $args['module'] ?? self::MODULE_MARGIN );
		$this->type           = (string) ( $args['type'] ?? self::TYPE_HEALTHY );
		$this->severity       = (string) ( $args['severity'] ?? self::SEVERITY_INFO );
		$this->financial_type = (string) ( $args['financial_type'] ?? self::FINANCIAL_MISSING_DATA );
		$this->confidence     = (int) ( $args['confidence'] ?? 100 );
		$this->subject_kind   = (string) ( $args['subject_kind'] ?? self::SUBJECT_PRODUCT );
		$this->subject_id     = (int) ( $args['subject_id'] ?? 0 );
		$this->subject_label  = (string) ( $args['subject_label'] ?? '' );
		$this->reference      = (string) ( $args['reference'] ?? '' );
		$this->evidence       = isset( $args['evidence'] ) && is_array( $args['evidence'] ) ? $args['evidence'] : array();

		// Never coalesced to 0. The nullability is the whole point.
		$this->impact_minor   = self::nullable_int( $args['impact_minor'] ?? null );
		$this->current_minor  = self::nullable_int( $args['current_minor'] ?? null );
		$this->expected_minor = self::nullable_int( $args['expected_minor'] ?? null );
	}

	/**
	 * Cast to int, preserving null.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function nullable_int( $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * True when this finding states a monetary amount.
	 *
	 * @return bool
	 */
	public function has_amount(): bool {
		return null !== $this->impact_minor;
	}

	/**
	 * Severity rank. Higher is worse. Used for ordering, never displayed.
	 *
	 * @param string $severity A SEVERITY_* constant.
	 * @return int
	 */
	public static function severity_rank( string $severity ): int {
		switch ( $severity ) {
			case self::SEVERITY_CRITICAL:
				return 4;
			case self::SEVERITY_HIGH:
				return 3;
			case self::SEVERITY_MEDIUM:
				return 2;
			case self::SEVERITY_LOW:
				return 1;
			default:
				return 0;
		}
	}

	/**
	 * Compare two findings for "show me the biggest problems first".
	 *
	 * Order: evidenced financial impact, then severity, then confidence.
	 *
	 * A finding with NO stateable impact sorts BELOW every finding that has
	 * one, whatever its severity. A merchant's attention is a budget, and a
	 * number they can act on outranks one we could not compute.
	 *
	 * Ties break on type then reference so the order is total and a report
	 * generated twice is byte-identical.
	 *
	 * @param Finding $a Left.
	 * @param Finding $b Right.
	 * @return int Negative when $a sorts first.
	 */
	public static function compare( Finding $a, Finding $b ): int {
		$a_has = $a->has_amount();
		$b_has = $b->has_amount();

		if ( $a_has && ! $b_has ) {
			return -1;
		}
		if ( ! $a_has && $b_has ) {
			return 1;
		}
		if ( $a_has && $b_has ) {
			// Magnitude, not sign: a loss and an opportunity of the same size
			// are equally worth the merchant's next minute.
			$diff = abs( (int) $b->impact_minor ) <=> abs( (int) $a->impact_minor );
			if ( 0 !== $diff ) {
				return $diff;
			}
		}

		$severity = self::severity_rank( $b->severity ) <=> self::severity_rank( $a->severity );
		if ( 0 !== $severity ) {
			return $severity;
		}

		$confidence = $b->confidence <=> $a->confidence;
		if ( 0 !== $confidence ) {
			return $confidence;
		}

		$type = strcmp( $a->type, $b->type );
		if ( 0 !== $type ) {
			return $type;
		}

		return strcmp( $a->reference, $b->reference );
	}

	/**
	 * Sorted copy, worst first. Does not mutate the input.
	 *
	 * @param Finding[] $findings Findings.
	 * @return Finding[] Sorted findings.
	 */
	public static function rank( array $findings ): array {
		$sorted = array_values( $findings );
		usort( $sorted, array( self::class, 'compare' ) );
		return $sorted;
	}

	/**
	 * Flat array form, for storage and export.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'module'         => $this->module,
			'type'           => $this->type,
			'severity'       => $this->severity,
			'financial_type' => $this->financial_type,
			'impact_minor'   => $this->impact_minor,
			'confidence'     => $this->confidence,
			'subject_kind'   => $this->subject_kind,
			'subject_id'     => $this->subject_id,
			'subject_label'  => $this->subject_label,
			'current_minor'  => $this->current_minor,
			'expected_minor' => $this->expected_minor,
			'reference'      => $this->reference,
			'evidence'       => $this->evidence,
		);
	}
}
