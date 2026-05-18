<?php
/**
 * Portal data retrieval: proposals and payments scoped to a client.
 *
 * Every public method takes a WP user ID and only returns records that
 * belong to that user's `_cf_client_id` meta value — no cross-client leakage.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) exit;

class ClientOctopus_Portal_Data {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all interpolated tables use $wpdb->prefix with trusted constants, not user input.

	// -------------------------------------------------------------------------
	// Client
	// -------------------------------------------------------------------------

	/**
	 * Return basic profile data for the current portal user.
	 *
	 * @param  int $user_id WP user ID.
	 * @return array{ id:string, name:string, email:string, company:string }
	 */
	public static function get_client( int $user_id ): array {
		$user      = get_user_by( 'ID', $user_id );
		$client_id = get_user_meta( $user_id, ClientOctopus_Portal_Auth::META_CLIENT, true );

		return [
			'id'      => $client_id ?: '',
			'name'    => $user ? $user->display_name : '',
			'email'   => $user ? $user->user_email   : '',
			'company' => get_user_meta( $user_id, '_cf_company', true ) ?: '',
		];
	}

	// -------------------------------------------------------------------------
	// Proposals
	// -------------------------------------------------------------------------

	/**
	 * Return all proposals where the client email matches the WP user's email.
	 *
	 * Proposals are stored with a `client_email` column; we join on that.
	 *
	 * @param  int $user_id
	 * @return array[]  Array of proposal rows (associative arrays).
	 */
	public static function get_proposals( int $user_id ): array {
		global $wpdb;

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return [];
		}

		$pt = $wpdb->prefix . 'clientoctopus_proposals';
		$ct = $wpdb->prefix . 'clientoctopus_clients';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.owner_id, p.title, p.status, p.token, p.total_amount, p.currency,
				        p.expiry_date, p.sent_at, p.created_at, p.updated_at
				 FROM   {$pt} p
				 JOIN   {$ct} c ON c.id = p.client_id
				 WHERE  c.email = %s
				 ORDER  BY p.created_at DESC",
				$user->user_email
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	// -------------------------------------------------------------------------
	// Payments
	// -------------------------------------------------------------------------

	/**
	 * Return all completed/failed payments for the given client.
	 *
	 * @param  int $user_id
	 * @return array[]
	 */
	public static function get_payments( int $user_id ): array {
		global $wpdb;

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return [];
		}

		$pt = $wpdb->prefix . 'clientoctopus_proposals';
		$pm = $wpdb->prefix . 'clientoctopus_payments';
		$ct = $wpdb->prefix . 'clientoctopus_clients';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.id, pm.proposal_id, pm.amount, pm.currency, pm.status,
				        pm.stripe_session_id, pm.stripe_payment_intent_id, pm.created_at, pm.updated_at,
				        pr.title AS proposal_title, pr.token AS proposal_token
				 FROM   {$pm}  AS pm
				 JOIN   {$pt}  AS pr ON pr.id = pm.proposal_id
				 JOIN   {$ct}  AS c  ON c.id  = pr.client_id
				 WHERE  c.email = %s
				 ORDER  BY pm.created_at DESC",
				$user->user_email
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	// -------------------------------------------------------------------------
	// Projects
	// -------------------------------------------------------------------------

	/**
	 * Return all projects where the client email matches the WP user's email.
	 * Includes milestone counts for progress display.
	 *
	 * @param  int $user_id
	 * @return array[]
	 */
	public static function get_projects( int $user_id ): array {
		global $wpdb;

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return [];
		}

		$pt = $wpdb->prefix . 'clientoctopus_projects';
		$ct = $wpdb->prefix . 'clientoctopus_clients';
		$mt = $wpdb->prefix . 'clientoctopus_milestones';
		$pp = $wpdb->prefix . 'clientoctopus_proposals';

		$pmt = $wpdb->prefix . 'clientoctopus_payments';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pr.*,
				        c.name    AS client_name,
				        p.title   AS proposal_title,
				        p.token   AS proposal_token,
				        p.total_amount AS proposal_total,
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
				 WHERE  c.email = %s
				 GROUP  BY pr.id
				 ORDER  BY pr.deleted_at IS NOT NULL ASC, pr.created_at DESC",
				$user->user_email
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
			$row['remaining_balance']    = max( 0.00, (float) ( $row['proposal_total'] ?? 0 ) - (float) ( $row['paid_amount'] ?? 0 ) );
			return $row;
		}, $rows ?: [] );
	}

	/**
	 * Return a single project (with milestones) for the portal client.
	 *
	 * Only returns the project if the client email matches.
	 *
	 * @param  int $user_id
	 * @param  int $project_id
	 * @return array|WP_Error
	 */
	public static function get_project( int $user_id, int $project_id ): array|WP_Error {
		global $wpdb;

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'Project not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

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
				 WHERE  pr.id = %d AND c.email = %s
				 GROUP  BY pr.id",
				$project_id,
				$user->user_email
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
		$row['remaining_balance']   = max( 0.00, (float) ( $row['proposal_total'] ?? 0 ) - (float) ( $row['paid_amount'] ?? 0 ) );

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
	 * @param  int $user_id
	 * @return array{
	 *   active_proposals: int,
	 *   in_progress:      int,
	 *   total_paid:       float,
	 *   currency:         string
	 * }
	 */
	public static function get_stats( int $user_id ): array {
		$proposals = self::get_proposals( $user_id );
		$payments  = self::get_payments( $user_id );

		$active_statuses   = [ 'sent', 'viewed', 'accepted' ];
		$active_proposals  = 0;
		$in_progress       = 0;

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
}
