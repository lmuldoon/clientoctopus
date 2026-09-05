<?php
/**
 * Portal data retrieval: proposals and payments scoped to a client.
 *
 * Every public method takes the session-resolved clientoctopus_clients.id and
 * only returns records that belong to that exact client row — no cross-client
 * leakage. Client ID is sourced from the custom portal session (no WordPress
 * user needed). Scoping by ID rather than email matters because email has no
 * uniqueness guarantee across different owner accounts on the same install —
 * two unrelated owners' clients could share an email address, and scoping by
 * email alone would merge their data together.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) exit;

class ClientOctopus_Portal_Data {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all interpolated tables use $wpdb->prefix with trusted constants, not user input.

	// -------------------------------------------------------------------------
	// Client
	// -------------------------------------------------------------------------

	/**
	 * Return basic profile data for a portal client by ID.
	 *
	 * @param  int $client_id  clientoctopus_clients.id (from portal session).
	 * @return array{ id: int, name: string, email: string, company: string }
	 */
	public static function get_client( int $client_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, email, company FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d LIMIT 1",
				$client_id
			),
			ARRAY_A
		);

		return [
			'id'      => $row ? (int) $row['id'] : 0,
			'name'    => $row['name']    ?? '',
			'email'   => $row['email']   ?? '',
			'company' => $row['company'] ?? '',
		];
	}

	// -------------------------------------------------------------------------
	// Proposals
	// -------------------------------------------------------------------------

	/**
	 * Return all proposals belonging to the given client.
	 *
	 * @param  int $client_id
	 * @return array[]
	 */
	public static function get_proposals( int $client_id ): array {
		global $wpdb;

		$pt = $wpdb->prefix . 'clientoctopus_proposals';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.owner_id, p.title, p.status, p.token, p.total_amount, p.currency,
				        p.expiry_date, p.sent_at, p.created_at, p.updated_at, p.content
				 FROM   {$pt} p
				 WHERE  p.client_id = %d
				   AND  p.deleted_at IS NULL
				 ORDER  BY p.created_at DESC",
				$client_id
			),
			ARRAY_A
		);

		// Only expose pricing_mode (needed for the "From $X" floor-price treatment
		// on unaccepted package-mode proposals) — never leak the full content blob
		// (line items, tier/addon definitions) to the portal list view.
		foreach ( $rows as &$row ) {
			$decoded = json_decode( $row['content'] ?? '', true );
			$row['pricing_mode'] = is_array( $decoded ) ? ( $decoded['pricing_mode'] ?? 'flat' ) : 'flat';
			unset( $row['content'] );
		}
		unset( $row );

		return $rows ?: [];
	}

	// -------------------------------------------------------------------------
	// Payments
	// -------------------------------------------------------------------------

	/**
	 * Return all payments for the given client.
	 *
	 * @param  int $client_id
	 * @return array[]
	 */
	public static function get_payments( int $client_id ): array {
		global $wpdb;

		$pt = $wpdb->prefix . 'clientoctopus_proposals';
		$pm = $wpdb->prefix . 'clientoctopus_payments';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.id, pm.proposal_id, pm.amount, pm.currency, pm.status,
				        pm.stripe_session_id, pm.stripe_payment_intent_id, pm.created_at, pm.updated_at,
				        pr.title AS proposal_title, pr.token AS proposal_token
				 FROM   {$pm}  AS pm
				 JOIN   {$pt}  AS pr ON pr.id = pm.proposal_id
				 WHERE  pr.client_id = %d
				 ORDER  BY pm.created_at DESC",
				$client_id
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	// -------------------------------------------------------------------------
	// Projects
	// -------------------------------------------------------------------------

	/**
	 * Return all projects for the given client.
	 * Includes milestone counts for progress display.
	 *
	 * @param  int $client_id
	 * @return array[]
	 */
	public static function get_projects( int $client_id ): array {
		global $wpdb;

		$pt  = $wpdb->prefix . 'clientoctopus_projects';
		$ct  = $wpdb->prefix . 'clientoctopus_clients';
		$mt  = $wpdb->prefix . 'clientoctopus_milestones';
		$pp  = $wpdb->prefix . 'clientoctopus_proposals';
		$pmt = $wpdb->prefix . 'clientoctopus_payments';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pr.*,
				        c.name    AS client_name,
				        p.title   AS proposal_title,
				        p.token   AS proposal_token,
				        p.total_amount AS proposal_total,
				        p.payment_enabled AS proposal_payment_enabled,
				        COUNT(m.id) AS milestone_total,
				        SUM( CASE WHEN m.status = 'completed' THEN 1 ELSE 0 END ) AS milestone_completed,
				        ( SELECT COALESCE(SUM(pm.amount), 0)
				          FROM   {$pmt} pm
				          WHERE  pm.proposal_id = pr.proposal_id AND pm.status = 'completed'
				        ) AS paid_amount
				 FROM   {$pt}  pr
				 JOIN   {$ct}  c  ON c.id  = pr.client_id
				 LEFT JOIN {$pp}  p  ON p.id  = pr.proposal_id
				 LEFT JOIN {$mt} m ON m.project_id = pr.id
				 WHERE  pr.client_id = %d
				   AND  pr.deleted_at IS NULL
				 GROUP  BY pr.id
				 ORDER  BY pr.created_at DESC",
				$client_id
			),
			ARRAY_A
		);

		return array_map( static function ( array $row ): array {
			$row['id']                   = (int) $row['id'];
			$row['milestone_total']      = (int) ( $row['milestone_total']     ?? 0 );
			$row['milestone_completed']  = (int) ( $row['milestone_completed'] ?? 0 );
			$row['progress_pct']         = $row['milestone_total'] > 0
				? (int) round( $row['milestone_completed'] / $row['milestone_total'] * 100 )
				: 0;
			// Proposals with payment disabled (e.g. recurring-billing proposals,
			// which are billed exclusively via their auto-created recurring
			// invoice profile) can never be paid directly, so they must never
			// show a phantom "amount due" that the client has no way to pay.
			$row['remaining_balance']    = empty( $row['proposal_payment_enabled'] )
				? 0.00
				: max( 0.00, (float) ( $row['proposal_total'] ?? 0 ) - (float) ( $row['paid_amount'] ?? 0 ) );
			return $row;
		}, $rows ?: [] );
	}

	/**
	 * Return a single project (with milestones) for the portal client.
	 *
	 * Only returns the project if it belongs to this exact client ID.
	 *
	 * @param  int $client_id
	 * @param  int $project_id
	 * @return array|WP_Error
	 */
	public static function get_project( int $client_id, int $project_id ): array|WP_Error {
		global $wpdb;

		$pt  = $wpdb->prefix . 'clientoctopus_projects';
		$ct  = $wpdb->prefix . 'clientoctopus_clients';
		$pp  = $wpdb->prefix . 'clientoctopus_proposals';
		$mt  = $wpdb->prefix . 'clientoctopus_milestones';
		$pmt = $wpdb->prefix . 'clientoctopus_payments';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pr.*,
				        c.name    AS client_name,
				        p.title   AS proposal_title,
				        p.token   AS proposal_token,
				        p.total_amount AS proposal_total,
				        p.payment_enabled AS proposal_payment_enabled,
				        COUNT(m.id) AS milestone_total,
				        SUM( CASE WHEN m.status = 'completed' THEN 1 ELSE 0 END ) AS milestone_completed,
				        ( SELECT COALESCE(SUM(pm.amount), 0)
				          FROM   {$pmt} pm
				          WHERE  pm.proposal_id = pr.proposal_id AND pm.status = 'completed'
				        ) AS paid_amount
				 FROM   {$pt}  pr
				 JOIN   {$ct}  c ON c.id  = pr.client_id
				 JOIN   {$pp}  p ON p.id  = pr.proposal_id
				 LEFT JOIN {$mt} m ON m.project_id = pr.id
				 WHERE  pr.id = %d AND pr.client_id = %d AND pr.deleted_at IS NULL
				 GROUP  BY pr.id",
				$project_id,
				$client_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Project not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		$row['id']                  = (int) $row['id'];
		$row['milestone_total']     = (int) ( $row['milestone_total']     ?? 0 );
		$row['milestone_completed'] = (int) ( $row['milestone_completed'] ?? 0 );
		$row['progress_pct']        = $row['milestone_total'] > 0
			? (int) round( $row['milestone_completed'] / $row['milestone_total'] * 100 )
			: 0;
		// See get_projects() — payment-disabled proposals (e.g. recurring
		// billing) can never be paid directly, so must never show a due amount.
		$row['remaining_balance']   = empty( $row['proposal_payment_enabled'] )
			? 0.00
			: max( 0.00, (float) ( $row['proposal_total'] ?? 0 ) - (float) ( $row['paid_amount'] ?? 0 ) );

		// Include full milestone list.
		$milestones = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$mt} WHERE project_id = %d ORDER BY sort_order ASC, created_at ASC",
				$project_id
			),
			ARRAY_A
		);

		$row['milestones'] = array_map( static function ( array $m ): array {
			$m['id']         = (int) $m['id'];
			$m['project_id'] = (int) $m['project_id'];
			$m['sort_order'] = (int) $m['sort_order'];
			return $m;
		}, $milestones ?: [] );

		return $row;
	}

	// -------------------------------------------------------------------------
	// Stats (aggregated for dashboard)
	// -------------------------------------------------------------------------

	/**
	 * Return dashboard summary statistics for the client.
	 *
	 * @param  int $client_id
	 * @return array{
	 *   active_proposals: int,
	 *   in_progress:      int,
	 *   total_paid:       float,
	 *   currency:         string
	 * }
	 */
	public static function get_stats( int $client_id ): array {
		$proposals = self::get_proposals( $client_id );
		$payments  = self::get_payments( $client_id );

		$active_statuses  = [ 'sent', 'viewed', 'accepted' ];
		$active_proposals = 0;
		$in_progress      = 0;

		foreach ( $proposals as $p ) {
			$status = $p['status'] ?? '';
			if ( in_array( $status, $active_statuses, true ) ) {
				$active_proposals++;
			}
			if ( 'accepted' === $status ) {
				$in_progress++;
			}
		}

		$total_paid = 0.0;
		$currency   = 'GBP';

		foreach ( $payments as $pm ) {
			if ( 'completed' === ( $pm['status'] ?? '' ) ) {
				$total_paid += (float) $pm['amount'];
				if ( ! empty( $pm['currency'] ) ) {
					$currency = strtoupper( $pm['currency'] );
				}
			}
		}

		return [
			'active_proposals' => $active_proposals,
			'in_progress'      => $in_progress,
			'total_paid'       => $total_paid,
			'currency'         => $currency,
		];
	}

	// -------------------------------------------------------------------------
	// Invoices
	// -------------------------------------------------------------------------

	/**
	 * Return all non-draft invoices for the given client.
	 *
	 * @param  int $client_id  clientoctopus_clients.id (from portal session).
	 * @return array
	 */
	public static function get_invoices( int $client_id ): array {
		global $wpdb;

		$it = $wpdb->prefix . 'clientoctopus_invoices';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.invoice_number, i.token, i.status, i.title,
				        i.currency, i.total_amount, i.due_date, i.issue_date,
				        i.sent_at, i.paid_at, i.created_at
				 FROM   {$it} i
				 WHERE  i.client_id = %d
				   AND  i.deleted_at IS NULL
				   AND  i.status != 'draft'
				 ORDER  BY i.created_at DESC",
				$client_id
			),
			ARRAY_A
		);

		return array_map( static function ( array $row ): array {
			$row['id']             = (int) $row['id'];
			$row['invoice_number'] = (int) $row['invoice_number'];
			$row['total_amount']   = (float) $row['total_amount'];
			$row['invoice_ref']    = 'INV-' . str_pad( (string) $row['invoice_number'], 4, '0', STR_PAD_LEFT );
			return $row;
		}, $rows ?: [] );
	}

	/**
	 * Return paid invoices for use in the Payments tab invoice payments table.
	 *
	 * @param  int $client_id  clientoctopus_clients.id (from portal session).
	 * @return array
	 */
	public static function get_invoice_payments( int $client_id ): array {
		global $wpdb;

		$it = $wpdb->prefix . 'clientoctopus_invoices';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.invoice_number, i.token, i.title, i.currency,
				        i.total_amount, i.paid_at, i.stripe_payment_intent_id
				 FROM   {$it} i
				 WHERE  i.client_id = %d
				   AND  i.deleted_at IS NULL
				   AND  i.status = 'paid'
				 ORDER  BY i.paid_at DESC",
				$client_id
			),
			ARRAY_A
		);

		return array_map( static function ( array $row ): array {
			$row['id']             = (int) $row['id'];
			$row['invoice_number'] = (int) $row['invoice_number'];
			$row['total_amount']   = (float) $row['total_amount'];
			$row['invoice_ref']    = 'INV-' . str_pad( (string) $row['invoice_number'], 4, '0', STR_PAD_LEFT );
			return $row;
		}, $rows ?: [] );
	}
}
