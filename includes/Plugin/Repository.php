<?php
/**
 * Reading and writing ProfitGuard's own tables.
 *
 * Every query here uses $wpdb->prepare() or $wpdb's own escaping helpers. The
 * few places that interpolate do so ONLY with a table name built from
 * $wpdb->prefix plus a literal, which cannot come from input, and each one says
 * so at the call site.
 *
 * @package ProfitGuard
 */

declare(strict_types=1);

namespace ProfitGuard\Plugin;

use ProfitGuard\Core\Finding;

defined( 'ABSPATH' ) || exit;

/*
 * A TABLE NAME CANNOT BE A PREPARED PLACEHOLDER.
 *
 * $wpdb->prepare() binds VALUES, not identifiers, so the table name in every
 * query below is interpolated. That is safe here and only here because each one
 * comes from Database::*_table(), which returns $wpdb->prefix concatenated with
 * a hard-coded literal - there is no path by which user input reaches it.
 *
 * Every VALUE in every query is still bound through prepare(), and the two
 * places that build a fragment ($where_sql, $order_sql) do so from a fixed set
 * of placeholders and a whitelist respectively.
 *
 * The exemption is deliberately limited to this one sniff in this one file,
 * rather than disabled globally, so a genuinely unprepared VALUE anywhere else
 * still fails the build.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Data access for findings, carrier rows and run history.
 */
final class Repository {

	public const RUN_SCAN           = 'scan';
	public const RUN_COST_IMPORT    = 'cost_import';
	public const RUN_CARRIER_IMPORT = 'carrier_import';

	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';

	// Runs.

	/**
	 * Start a run and return its id.
	 *
	 * @param string               $kind   A RUN_* constant.
	 * @param array<string, mixed> $totals Initial totals.
	 * @return int
	 */
	public static function start_run( string $kind, array $totals = array() ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no object cache applies.
		$wpdb->insert(
			Database::runs_table(),
			array(
				'kind'       => $kind,
				'status'     => self::STATUS_RUNNING,
				'totals'     => (string) wp_json_encode( $totals ),
				'started_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a run's totals without finishing it.
	 *
	 * @param int                  $run_id Run id.
	 * @param array<string, mixed> $totals Totals.
	 */
	public static function update_run_totals( int $run_id, array $totals ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			Database::runs_table(),
			array( 'totals' => (string) wp_json_encode( $totals ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Finish a run.
	 *
	 * @param int                  $run_id  Run id.
	 * @param string               $status  A STATUS_* constant.
	 * @param array<string, mixed> $totals  Final totals.
	 * @param string               $message Optional message.
	 */
	public static function finish_run( int $run_id, string $status, array $totals = array(), string $message = '' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			Database::runs_table(),
			array(
				'status'      => $status,
				'totals'      => (string) wp_json_encode( $totals ),
				'message'     => $message,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $run_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * The most recent run of a kind.
	 *
	 * @param string $kind A RUN_* constant.
	 * @return array<string, mixed>|null
	 */
	public static function latest_run( string $kind ): ?array {
		global $wpdb;

		$table = Database::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE kind = %s ORDER BY id DESC LIMIT 1", $kind ),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}
		$row['totals'] = self::decode_json( $row['totals'] ?? '' );
		return $row;
	}

	/**
	 * Recent runs of any kind, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent_runs( int $limit = 20 ): array {
		global $wpdb;

		$table = Database::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, $limit ) ),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['totals'] = self::decode_json( $row['totals'] ?? '' );
		}
		return $rows;
	}

	/**
	 * Delete run history older than the retention window.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows removed.
	 */
	public static function purge_runs( int $days ): int {
		global $wpdb;

		$table  = Database::runs_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE started_at < %s AND status <> %s", $cutoff, self::STATUS_RUNNING )
		);
	}

	// Findings.

	/**
	 * Insert findings for a scan.
	 *
	 * @param int       $scan_id  Scan run id.
	 * @param Finding[] $findings Findings.
	 * @return int Rows written.
	 */
	public static function insert_findings( int $scan_id, array $findings ): int {
		global $wpdb;

		$table   = Database::findings_table();
		$written = 0;
		$now     = current_time( 'mysql', true );

		foreach ( $findings as $finding ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$ok = $wpdb->insert(
				$table,
				array(
					'scan_id'        => $scan_id,
					'module'         => $finding->module,
					'type'           => $finding->type,
					'severity'       => $finding->severity,
					'financial_type' => $finding->financial_type,
					// Passed through as null when null. $wpdb writes a real
					// SQL NULL for a PHP null, which is exactly what the
					// nullable column is for.
					'impact_minor'   => $finding->impact_minor,
					'confidence'     => $finding->confidence,
					'subject_kind'   => $finding->subject_kind,
					'subject_id'     => $finding->subject_id,
					'subject_label'  => $finding->subject_label,
					'reference'      => $finding->reference,
					'current_minor'  => $finding->current_minor,
					'expected_minor' => $finding->expected_minor,
					'evidence'       => (string) wp_json_encode( $finding->evidence ),
					'created_at'     => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
			if ( false !== $ok ) {
				++$written;
			}
		}//end foreach

		return $written;
	}

	/**
	 * Remove every finding from scans other than the current one.
	 *
	 * Called when a scan completes, so the dashboard flips from the old results
	 * to the new ones in one step rather than showing a half-updated mixture
	 * while a long scan runs.
	 *
	 * @param int $keep_scan_id Scan id to keep.
	 * @return int Rows removed.
	 */
	public static function prune_findings_except( int $keep_scan_id ): int {
		global $wpdb;

		$table = Database::findings_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scan_id <> %d", $keep_scan_id ) );
	}

	/**
	 * The id of the most recent completed scan.
	 *
	 * @return int 0 when there is none.
	 */
	public static function latest_scan_id(): int {
		$run = self::latest_completed_run( self::RUN_SCAN );
		return null === $run ? 0 : (int) $run['id'];
	}

	/**
	 * The most recent COMPLETED run of a kind.
	 *
	 * @param string $kind A RUN_* constant.
	 * @return array<string, mixed>|null
	 */
	public static function latest_completed_run( string $kind ): ?array {
		global $wpdb;

		$table = Database::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE kind = %s AND status = %s ORDER BY id DESC LIMIT 1",
				$kind,
				self::STATUS_COMPLETED
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}
		$row['totals'] = self::decode_json( $row['totals'] ?? '' );
		return $row;
	}

	/**
	 * Query findings for the table screen.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *     @type int    $scan_id  Scan id.
	 *     @type string $module   Module filter.
	 *     @type string $type     Type filter.
	 *     @type string $severity Severity filter.
	 *     @type string $orderby  impact|severity|type.
	 *     @type int    $per_page Rows per page.
	 *     @type int    $page     1-based page.
	 * }
	 * @return array{rows:array<int, array<string, mixed>>,total:int}
	 */
	public static function query_findings( array $args ): array {
		global $wpdb;

		$table = Database::findings_table();

		$scan_id = (int) ( $args['scan_id'] ?? 0 );
		$where   = array( 'scan_id = %d' );
		$params  = array( $scan_id );

		foreach ( array( 'module', 'type', 'severity' ) as $column ) {
			$value = isset( $args[ $column ] ) ? (string) $args[ $column ] : '';
			if ( '' !== $value ) {
				$where[]  = "{$column} = %s";
				$params[] = $value;
			}
		}

		$where_sql = implode( ' AND ', $where );

		/*
		 * ORDER BY is built from a fixed whitelist, never from input. An
		 * unrecognised value falls back to the default rather than being
		 * interpolated.
		 *
		 * `impact_minor IS NULL` first in the impact ordering puts findings
		 * with no stateable amount BELOW every finding that has one, matching
		 * Core\Finding::compare(). MySQL sorts NULL lowest by default, which
		 * without this would put them at the top of a DESC sort - the exact
		 * opposite of what a merchant needs.
		 */
		$order_map = array(
			'impact'   => 'impact_minor IS NULL ASC, ABS(impact_minor) DESC, id ASC',
			'severity' => "FIELD(severity,'CRITICAL','HIGH','MEDIUM','LOW','INFO') ASC, impact_minor IS NULL ASC, ABS(impact_minor) DESC",
			'type'     => 'type ASC, id ASC',
			'newest'   => 'id DESC',
		);
		$orderby   = (string) ( $args['orderby'] ?? 'impact' );
		$order_sql = $order_map[ $orderby ] ?? $order_map['impact'];

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is built from a fixed set of placeholders; values are bound below.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$params ) );

		$query_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql and $order_sql come from fixed literals/whitelist; values are bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
				...$query_params
			),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['evidence'] = self::decode_json( $row['evidence'] ?? '' );
		}

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Counts by type for one scan.
	 *
	 * @param int $scan_id Scan id.
	 * @return array<string, int>
	 */
	public static function counts_by_type( int $scan_id ): array {
		global $wpdb;

		$table = Database::findings_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT type, COUNT(*) AS n FROM {$table} WHERE scan_id = %d GROUP BY type", $scan_id ),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['type'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Sum the amounts of findings of given types, for one scan.
	 *
	 * DELIBERATELY NOT `SUM( COALESCE( impact_minor, 0 ) )`. SQL SUM() already
	 * ignores NULL and returns NULL over an all-null set, which is precisely
	 * the behaviour required: "we could price nothing" must come back as null,
	 * not as zero.
	 *
	 * @param int      $scan_id Scan id.
	 * @param string[] $types   Finding types.
	 * @return int|null
	 */
	public static function sum_impact( int $scan_id, array $types ): ?int {
		global $wpdb;

		if ( empty( $types ) ) {
			return null;
		}

		$table        = Database::findings_table();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$params       = array_merge( array( $scan_id ), $types );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated from a count, values bound below.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(impact_minor) FROM {$table} WHERE scan_id = %d AND type IN ({$placeholders})",
				...$params
			)
		);

		return ( null === $value ) ? null : (int) $value;
	}

	// Carrier costs.

	/**
	 * Insert a carrier row, ignoring an exact duplicate.
	 *
	 * The UNIQUE index on row_hash is what makes re-importing the same file
	 * safe - the single most damaging import bug possible here would be
	 * doubling a merchant's costs on a second upload.
	 *
	 * @param array<string, mixed> $row Row values.
	 * @return string 'inserted'|'duplicate'|'failed'
	 */
	public static function insert_carrier_row( array $row ): string {
		global $wpdb;

		$table = Database::carrier_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$existing = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
			$wpdb->prepare( "SELECT id FROM {$table} WHERE row_hash = %s", (string) $row['row_hash'] )
		);
		if ( null !== $existing ) {
			return 'duplicate';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$ok = $wpdb->insert(
			$table,
			array(
				'import_id'        => (int) ( $row['import_id'] ?? 0 ),
				'order_id'         => (int) ( $row['order_id'] ?? 0 ),
				'order_reference'  => (string) ( $row['order_reference'] ?? '' ),
				'tracking_number'  => (string) ( $row['tracking_number'] ?? '' ),
				'carrier'          => (string) ( $row['carrier'] ?? '' ),
				'cost_minor'       => $row['cost_minor'] ?? null,
				'surcharge_minor'  => $row['surcharge_minor'] ?? null,
				'adjustment_minor' => $row['adjustment_minor'] ?? null,
				'currency'         => (string) ( $row['currency'] ?? '' ),
				'shipped_on'       => $row['shipped_on'] ?? null,
				'row_hash'         => (string) $row['row_hash'],
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return false === $ok ? 'failed' : 'inserted';
	}

	/**
	 * Total carrier cost per order id.
	 *
	 * Summed because one order can appear on several carrier lines - a
	 * multi-parcel shipment, or a base charge and a later adjustment.
	 *
	 * @param int[] $order_ids Order ids.
	 * @return array<int, int> Order id => total minor units.
	 */
	public static function carrier_costs_for_orders( array $order_ids ): array {
		global $wpdb;

		$order_ids = array_values( array_filter( array_map( 'intval', $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}

		$table        = Database::carrier_table();
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated from a count; values bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id,
				        SUM( COALESCE(cost_minor,0) + COALESCE(surcharge_minor,0) + COALESCE(adjustment_minor,0) ) AS total
				   FROM {$table}
				  WHERE order_id IN ({$placeholders})
				  GROUP BY order_id",
				...$order_ids
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['order_id'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Carrier rows that matched no order.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function unmatched_carrier_rows( int $limit = 50 ): array {
		global $wpdb;

		$table = Database::carrier_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = 0 ORDER BY id ASC LIMIT %d", max( 1, $limit ) ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many carrier rows exist, and how many matched an order.
	 *
	 * @return array{total:int,matched:int}
	 */
	public static function carrier_row_counts(): array {
		global $wpdb;

		$table = Database::carrier_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS total, SUM( CASE WHEN order_id > 0 THEN 1 ELSE 0 END ) AS matched FROM {$table}",
			ARRAY_A
		);

		return array(
			'total'   => (int) ( $row['total'] ?? 0 ),
			'matched' => (int) ( $row['matched'] ?? 0 ),
		);
	}

	/**
	 * All carrier rows, for duplicate detection.
	 *
	 * @return array<int, array{tracking_number:string,carrier_cost_minor:int|null,row_index:int}>
	 */
	public static function all_carrier_rows_for_duplicates(): array {
		global $wpdb;

		$table = Database::carrier_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		$rows = $wpdb->get_results(
			"SELECT id, tracking_number, cost_minor FROM {$table} WHERE tracking_number <> '' ORDER BY id ASC",
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'tracking_number'    => (string) $row['tracking_number'],
				'carrier_cost_minor' => ( null === $row['cost_minor'] ) ? null : (int) $row['cost_minor'],
				'row_index'          => (int) $row['id'],
			);
		}
		return $out;
	}

	/**
	 * Remove every carrier row. Used by the "clear imported data" action.
	 *
	 * @return int Rows removed.
	 */
	public static function clear_carrier_rows(): int {
		global $wpdb;

		$table = Database::carrier_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a literal + $wpdb->prefix.
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}

	// Helpers.

	/**
	 * Decode a JSON column into an array, tolerating malformed data.
	 *
	 * Uses json_decode with associative=true, and NEVER unserialize(): an
	 * unserialize() on database content is an object-injection sink, and a JSON
	 * column costs nothing to use instead.
	 *
	 * @param mixed $value Raw column value.
	 * @return array<string, mixed>
	 */
	private static function decode_json( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
