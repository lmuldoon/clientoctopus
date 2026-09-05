<?php
/**
 * Analytics Service
 *
 * All analytics queries scoped to a single owner_id and date range.
 * Results are cached in transients (5-minute TTL) and invalidated on
 * key write events (proposal saved, payment completed).
 *
 * @package ClientOctopus\Analytics
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClientOctopus_Analytics {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use $wpdb->prefix with trusted constants, not user input.

	private const CACHE_TTL = 300; // 5 minutes

	// ── KPIs ──────────────────────────────────────────────────────────────────

	/**
	 * Return headline KPIs for the given date range plus the prior equivalent
	 * period (for trend arrows).
	 *
	 * @param  int    $owner_id
	 * @param  string $from     MySQL DATETIME string — range start (inclusive)
	 * @param  string $to       MySQL DATETIME string — range end (inclusive)
	 * @return array
	 */
	public static function kpis( int $owner_id, string $from, string $to ): array {
		$cache_key = self::key( 'kpis', $owner_id, $from, $to );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// Current period.
		$current = self::kpi_query( $wpdb, $owner_id, $from, $to );

		// Prior period — same duration, immediately before $from.
		$from_dt  = new DateTime( $from );
		$to_dt    = new DateTime( $to );
		$interval = $from_dt->diff( $to_dt );
		$prior_to = ( clone $from_dt )->modify( '-1 second' );
		$prior_from = ( clone $prior_to )->sub( $interval );
		$prior = self::kpi_query( $wpdb, $owner_id, $prior_from->format( 'Y-m-d H:i:s' ), $prior_to->format( 'Y-m-d H:i:s' ) );

		$result = [
			'revenue'              => (float) ( $current['revenue'] ?? 0 ),
			'proposals_sent'       => (int)   ( $current['proposals_sent'] ?? 0 ),
			'conversion_rate'      => self::conversion_rate( $current ),
			'avg_days_to_close'    => (float) ( $current['avg_days_to_close'] ?? 0 ),
			'revenue_prev'         => (float) ( $prior['revenue'] ?? 0 ),
			'proposals_sent_prev'  => (int)   ( $prior['proposals_sent'] ?? 0 ),
			'conversion_rate_prev' => self::conversion_rate( $prior ),
			'avg_days_prev'        => (float) ( $prior['avg_days_to_close'] ?? 0 ),
		];

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	private static function kpi_query( wpdb $wpdb, int $owner_id, string $from, string $to ): array {
		$p_table   = $wpdb->prefix . 'clientoctopus_proposals';
		$pay_table = $wpdb->prefix . 'clientoctopus_payments';
		$inv_table = $wpdb->prefix . 'clientoctopus_invoices';

		// Revenue combines proposal payments and paid standalone invoices —
		// both are real money received, regardless of source.
		$proposal_revenue = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount),0) FROM {$pay_table}
			 WHERE owner_id = %d AND status = 'completed'
			   AND completed_at BETWEEN %s AND %s",
			$owner_id, $from, $to
		) );

		$invoice_revenue = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(total_amount),0) FROM {$inv_table}
			 WHERE owner_id = %d AND status = 'paid' AND deleted_at IS NULL
			   AND paid_at BETWEEN %s AND %s",
			$owner_id, $from, $to
		) );

		$revenue = $proposal_revenue + $invoice_revenue;

		$proposals_sent = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p_table}
			 WHERE owner_id = %d AND sent_at BETWEEN %s AND %s",
			$owner_id, $from, $to
		) );

		$proposals_accepted = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p_table}
			 WHERE owner_id = %d AND status IN ('accepted','completed') AND sent_at BETWEEN %s AND %s",
			$owner_id, $from, $to
		) );

		$avg_days = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(AVG(TIMESTAMPDIFF(DAY, sent_at, accepted_at)),0)
			 FROM {$p_table}
			 WHERE owner_id = %d AND accepted_at IS NOT NULL AND status IN ('accepted','completed') AND sent_at BETWEEN %s AND %s",
			$owner_id, $from, $to
		) );

		return compact( 'revenue', 'proposals_sent', 'proposals_accepted', 'avg_days_to_close' ) + [
			'avg_days_to_close' => $avg_days,
		];
	}

	private static function conversion_rate( array $row ): float {
		$sent = (int) ( $row['proposals_sent'] ?? 0 );
		if ( 0 === $sent ) {
			return 0.0;
		}
		return round( ( (int) ( $row['proposals_accepted'] ?? 0 ) / $sent ) * 100, 1 );
	}

	// ── Revenue chart ──────────────────────────────────────────────────────────

	/**
	 * Revenue grouped by day, week, or month.
	 *
	 * @param  string $granularity 'day'|'week'|'month'
	 * @return array  [ { date, amount } … ]
	 */
	public static function revenue_chart( int $owner_id, string $from, string $to, string $granularity = 'day' ): array {
		$cache_key = self::key( "chart_{$granularity}", $owner_id, $from, $to );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$pay_table = $wpdb->prefix . 'clientoctopus_payments';
		$inv_table = $wpdb->prefix . 'clientoctopus_invoices';

		$format = match ( $granularity ) {
			'month' => '%Y-%m',
			'week'  => '%x-W%v',
			default => '%Y-%m-%d',
		};

		// Combine proposal payments and paid invoices into the same date buckets —
		// both are revenue, regardless of source.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT date_label, SUM(amount) AS amount FROM (
				(SELECT DATE_FORMAT(completed_at, %s) AS date_label, amount, completed_at AS sort_ts
				 FROM {$pay_table}
				 WHERE owner_id = %d AND status = 'completed'
				   AND completed_at BETWEEN %s AND %s)
				UNION ALL
				(SELECT DATE_FORMAT(paid_at, %s) AS date_label, total_amount AS amount, paid_at AS sort_ts
				 FROM {$inv_table}
				 WHERE owner_id = %d AND status = 'paid' AND deleted_at IS NULL
				   AND paid_at BETWEEN %s AND %s)
			 ) combined
			 GROUP BY date_label
			 ORDER BY MIN(sort_ts) ASC",
			$format, $owner_id, $from, $to,
			$format, $owner_id, $from, $to
		), ARRAY_A );

		$result = array_map( static fn( $r ) => [
			'date'   => $r['date_label'],
			'amount' => (float) $r['amount'],
		], $rows ?: [] );

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	// ── Proposal performance ───────────────────────────────────────────────────

	/**
	 * Acceptance rate overall and broken down by template.
	 *
	 * @return array {
	 *   by_template: [ { template_id, label, sent, accepted, win_rate } … ],
	 *   overall_acceptance_rate: float,
	 *   avg_days_to_acceptance: float,
	 * }
	 */
	public static function proposal_performance( int $owner_id, string $from, string $to ): array {
		$cache_key = self::key( 'performance', $owner_id, $from, $to );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$p_table = $wpdb->prefix . 'clientoctopus_proposals';

		// Overall (closed proposals only — excludes open drafts/sent).
		$overall = $wpdb->get_row( $wpdb->prepare(
			"SELECT
			   COUNT(*) AS closed,
			   SUM(status IN ('accepted','completed')) AS accepted,
			   COALESCE(AVG(CASE WHEN accepted_at IS NOT NULL THEN TIMESTAMPDIFF(DAY, sent_at, accepted_at) END), 0) AS avg_days
			 FROM {$p_table}
			 WHERE owner_id = %d
			   AND status IN ('accepted','declined','completed')
			   AND sent_at BETWEEN %s AND %s",
			$owner_id, $from, $to
		), ARRAY_A );

		$overall_rate = ( $overall && $overall['closed'] > 0 )
			? round( ( $overall['accepted'] / $overall['closed'] ) * 100, 1 )
			: 0.0;

		// By template.
		$by_tpl_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
			   COALESCE(template_id, 'unknown') AS template_id,
			   COUNT(*) AS closed,
			   SUM(status = 'accepted') AS accepted
			 FROM {$p_table}
			 WHERE owner_id = %d
			   AND status IN ('accepted','declined','completed')
			   AND sent_at BETWEEN %s AND %s
			 GROUP BY template_id
			 ORDER BY SUM(status = 'accepted') / COUNT(*) DESC",
			$owner_id, $from, $to
		), ARRAY_A );

		$template_labels = [
			'web-design' => 'Web Design',
			'retainer'   => 'Retainer',
			'blank'      => 'Blank',
			'marketing'  => 'Marketing',
		];

		$by_template = array_map( static fn( $r ) => [
			'template_id' => $r['template_id'],
			'label'       => $template_labels[ $r['template_id'] ] ?? ucfirst( $r['template_id'] ),
			'closed'      => (int) $r['closed'],
			'accepted'    => (int) $r['accepted'],
			'win_rate'    => $r['closed'] > 0 ? round( ( $r['accepted'] / $r['closed'] ) * 100, 1 ) : 0.0,
		], $by_tpl_rows ?: [] );

		$result = [
			'by_template'            => $by_template,
			'overall_acceptance_rate' => $overall_rate,
			'avg_days_to_acceptance'  => (float) ( $overall['avg_days'] ?? 0 ),
		];

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	// ── Lead funnel ──────────────────────────────────────────────────────────────

	/**
	 * Lead capture stage breakdown for the given period, plus the prior
	 * equivalent period's captured count (for the KPI trend arrow) —
	 * mirrors kpis()'s current+prior pattern.
	 *
	 * @param  int    $owner_id
	 * @param  string $from     MySQL DATETIME string — range start (inclusive)
	 * @param  string $to       MySQL DATETIME string — range end (inclusive)
	 * @return array
	 */
	public static function lead_funnel( int $owner_id, string $from, string $to ): array {
		$cache_key = self::key( 'lead_funnel', $owner_id, $from, $to );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$l_table = $wpdb->prefix . 'clientoctopus_leads';

		$current = self::lead_funnel_query( $wpdb, $l_table, $owner_id, $from, $to );

		// Prior period — same duration, immediately before $from.
		$from_dt    = new DateTime( $from );
		$to_dt      = new DateTime( $to );
		$interval   = $from_dt->diff( $to_dt );
		$prior_to   = ( clone $from_dt )->modify( '-1 second' );
		$prior_from = ( clone $prior_to )->sub( $interval );
		$prior      = self::lead_funnel_query( $wpdb, $l_table, $owner_id, $prior_from->format( 'Y-m-d H:i:s' ), $prior_to->format( 'Y-m-d H:i:s' ) );

		$captured = array_sum( $current );

		$result = [
			'captured'        => $captured,
			'new'             => $current['new'] ?? 0,
			'contacted'       => $current['contacted'] ?? 0,
			'converted'       => $current['converted'] ?? 0,
			'archived'        => $current['archived'] ?? 0,
			'conversion_rate' => $captured > 0 ? round( ( ( $current['converted'] ?? 0 ) / $captured ) * 100, 1 ) : 0.0,
			'captured_prev'   => array_sum( $prior ),
		];

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * @return array<string, int> Counts keyed by status; missing statuses omitted.
	 */
	private static function lead_funnel_query( wpdb $wpdb, string $l_table, int $owner_id, string $from, string $to ): array {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS c FROM {$l_table}
			 WHERE owner_id = %d AND created_at BETWEEN %s AND %s
			 GROUP BY status",
			$owner_id, $from, $to
		), ARRAY_A );

		$counts = [];
		foreach ( $rows ?: [] as $row ) {
			$counts[ $row['status'] ] = (int) $row['c'];
		}
		return $counts;
	}

	// ── Activity feed ──────────────────────────────────────────────────────────

	/**
	 * Last N events across proposals, payments, messages, and projects.
	 *
	 * @return array [ { type, label, timestamp, meta } … ]
	 */
	public static function activity_feed( int $owner_id, int $limit = 20 ): array {
		$cache_key = 'clientoctopus_analytics_feed_' . $owner_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$p   = $wpdb->prefix;
		$lim = (int) $limit;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"(SELECT 'payment' AS type, CONCAT('Payment received from ', c.name) AS label,
			         pay.completed_at AS ts, pay.amount AS meta
			  FROM {$p}clientoctopus_payments pay
			  JOIN {$p}clientoctopus_clients c ON c.id = pay.client_id
			  WHERE pay.owner_id = %d AND pay.status = 'completed' AND pay.completed_at IS NOT NULL)
			UNION ALL
			(SELECT 'accepted', CONCAT('Proposal accepted: ', title), accepted_at, total_amount
			 FROM {$p}clientoctopus_proposals
			 WHERE owner_id = %d AND status IN ('accepted','completed') AND accepted_at IS NOT NULL)
			UNION ALL
			(SELECT 'sent', CONCAT('Proposal sent: ', title), sent_at, total_amount
			 FROM {$p}clientoctopus_proposals
			 WHERE owner_id = %d AND sent_at IS NOT NULL)
			UNION ALL
			(SELECT 'message', CONCAT('Message from client on: ', pr.name), m.created_at, NULL
			 FROM {$p}clientoctopus_messages m
			 JOIN {$p}clientoctopus_projects pr ON pr.id = m.project_id
			 WHERE pr.owner_id = %d AND m.sender_type = 'client' AND m.created_at IS NOT NULL)
			UNION ALL
			(SELECT 'project', CONCAT('Project started: ', name), created_at, NULL
			 FROM {$p}clientoctopus_projects
			 WHERE owner_id = %d AND created_at IS NOT NULL AND deleted_at IS NULL)
			UNION ALL
			(SELECT 'invoice_paid', CONCAT('Invoice paid: ', c.name), inv.paid_at, inv.total_amount
			 FROM {$p}clientoctopus_invoices inv
			 JOIN {$p}clientoctopus_clients c ON c.id = inv.client_id
			 WHERE inv.owner_id = %d AND inv.status = 'paid' AND inv.deleted_at IS NULL AND inv.paid_at IS NOT NULL)
			ORDER BY ts DESC
			LIMIT %d",
			$owner_id, $owner_id, $owner_id, $owner_id, $owner_id, $owner_id, $lim
		), ARRAY_A );

		$result = array_map( static fn( $r ) => [
			'type'      => $r['type'],
			'label'     => $r['label'],
			'timestamp' => $r['ts'],
			'meta'      => $r['meta'],
		], $rows ?: [] );

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	// ── Cache invalidation ─────────────────────────────────────────────────────

	/**
	 * Wipe all analytics transients for an owner.
	 * Called on proposal saves, payment completions, invoice payments, and
	 * lead capture/status/conversion events.
	 */
	public static function bust_cache( int $owner_id ): void {
		global $wpdb;
		// NOTE: these LIKE patterns must match key()'s real prefix
		// (clientoctopus_analytics_) — a prior version of this used
		// 'cf_analytics' here, which never matched anything key() actually
		// wrote, so kpis()/revenue_chart()/proposal_performance()/lead_funnel()
		// silently never got invalidated by this method.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s OR option_name LIKE %s",
			'%_transient_clientoctopus_analytics_%' . $owner_id . '%',
			'%_transient_timeout_clientoctopus_analytics_%' . $owner_id . '%'
		) );
		delete_transient( 'clientoctopus_analytics_feed_' . $owner_id );
	}

	// ── CSV export ─────────────────────────────────────────────────────────────

	/**
	 * Stream a CSV file to the browser and exit.
	 */
	public static function stream_csv( int $owner_id, string $from, string $to ): void {
		global $wpdb;
		$p_table   = $wpdb->prefix . 'clientoctopus_proposals';
		$pay_table = $wpdb->prefix . 'clientoctopus_payments';
		$inv_table = $wpdb->prefix . 'clientoctopus_invoices';

		$proposal_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.title, p.status, p.total_amount, p.currency, p.template_id,
			        p.sent_at, p.accepted_at, p.declined_at,
			        COALESCE(pay.amount, 0) AS payment_received
			 FROM {$p_table} p
			 LEFT JOIN {$pay_table} pay ON pay.proposal_id = p.id AND pay.status = 'completed'
			 WHERE p.owner_id = %d AND p.sent_at BETWEEN %s AND %s
			 ORDER BY p.sent_at DESC",
			$owner_id, $from, $to
		), ARRAY_A );

		// Paid standalone invoices — reported as their own type since they carry
		// different fields (no template, no accept/decline lifecycle) rather than
		// conflated into the proposal columns.
		$invoice_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT title, status, total_amount, currency, sent_at, paid_at
			 FROM {$inv_table}
			 WHERE owner_id = %d AND status = 'paid' AND deleted_at IS NULL
			   AND paid_at BETWEEN %s AND %s
			 ORDER BY paid_at DESC",
			$owner_id, $from, $to
		), ARRAY_A );

		$filename = 'clientoctopus-analytics-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'Type', 'Title', 'Status', 'Amount', 'Currency', 'Template', 'Sent', 'Accepted', 'Declined', 'Payment Received' ] );

		foreach ( $proposal_rows as $row ) {
			fputcsv( $out, [
				'Proposal',
				$row['title'],
				$row['status'],
				$row['total_amount'],
				$row['currency'],
				$row['template_id'] ?? '',
				$row['sent_at']     ?? '',
				$row['accepted_at'] ?? '',
				$row['declined_at'] ?? '',
				$row['payment_received'],
			] );
		}

		foreach ( $invoice_rows as $row ) {
			fputcsv( $out, [
				'Invoice',
				$row['title'],
				$row['status'],
				$row['total_amount'],
				$row['currency'],
				'', // no template concept for invoices
				$row['sent_at'] ?? '',
				'', // no accepted_at concept for invoices
				'', // no declined_at concept for invoices
				$row['total_amount'],
			] );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing a php://output stream, not a filesystem file.
		exit;
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	private static function key( string $method, int $owner_id, string $from, string $to ): string {
		return 'clientoctopus_analytics_' . $method . '_' . $owner_id . '_' . md5( $from . $to );
	}
}

// ── Cache busting hooks ────────────────────────────────────────────────────────

add_action( 'clientoctopus_proposal_saved', static function ( int $proposal_id, int $owner_id ): void {
	ClientOctopus_Analytics::bust_cache( $owner_id );
}, 10, 2 );

add_action( 'clientoctopus_payment_completed', static function ( int $payment_id, int $owner_id ): void {
	ClientOctopus_Analytics::bust_cache( $owner_id );
}, 10, 2 );

// Invoices now contribute to revenue (see kpi_query/revenue_chart/activity_feed
// above) — without this, the analytics cache wouldn't invalidate when an
// invoice (recurring or manual) gets paid, and revenue would look stale.
add_action( 'clientoctopus_invoice_paid', static function ( int $invoice_id, int $owner_id ): void {
	ClientOctopus_Analytics::bust_cache( $owner_id );
}, 10, 2 );

// Lead capture, status changes (new/contacted/archived), and conversion to a
// client record all affect lead_funnel() — bust on all three.
add_action( 'clientoctopus_lead_captured', static function ( int $lead_id, int $owner_id ): void {
	ClientOctopus_Analytics::bust_cache( $owner_id );
}, 10, 2 );

add_action( 'clientoctopus_lead_status_changed', static function ( int $lead_id, int $owner_id, string $status ): void {
	ClientOctopus_Analytics::bust_cache( $owner_id );
}, 10, 3 );

add_action( 'clientoctopus_client_created_from_lead', static function ( int $client_id, int $lead_id, int $owner_id ): void {
	ClientOctopus_Analytics::bust_cache( $owner_id );
}, 10, 3 );
