<?php
/**
 * Project Model
 *
 * CRUD for the clientoctopus_projects table.
 * Projects are auto-created when a proposal is accepted (Agency tier).
 *
 * @package ClientOctopus\Projects
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Project
 */
class ClientOctopus_Project {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; table() returns a trusted constant, not user input.

	private const TABLE = 'clientoctopus_projects';

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	// ── Create ────────────────────────────────────────────────────────────────

	/**
	 * Create a new project.
	 *
	 * @param int   $owner_id
	 * @param array $data Required: name, proposal_id, client_id. Optional: description, status.
	 *
	 * @return int|WP_Error New project ID.
	 */
	public static function create( int $owner_id, array $data ): int|WP_Error {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = [
			'owner_id'    => $owner_id,
			'client_id'   => (int) ( $data['client_id']   ?? 0 ),
			'proposal_id' => (int) ( $data['proposal_id'] ?? 0 ),
			'name'        => sanitize_text_field( $data['name']        ?? '' ),
			'description' => sanitize_textarea_field( $data['description'] ?? '' ) ?: null,
			'status'      => 'active',
			'created_at'  => $now,
			'updated_at'  => $now,
		];

		if ( ! $row['name'] ) {
			return new WP_Error( 'missing_name', __( 'Project name is required.', 'clientoctopus' ), [ 'status' => 400 ] );
		}

		$inserted = $wpdb->insert( self::table(), $row );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Failed to create project.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		return (int) $wpdb->insert_id;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Get a single project with joined client, proposal, and milestone counts.
	 *
	 * @param int $id
	 * @param int $owner_id Pass 0 to skip ownership check (admin use).
	 *
	 * @return array|WP_Error
	 */
	public static function get( int $id, int $owner_id = 0 ): array|WP_Error {
		global $wpdb;

		$t  = self::table();
		$ct = $wpdb->prefix . 'clientoctopus_clients';
		$pt = $wpdb->prefix . 'clientoctopus_proposals';
		$mt = $wpdb->prefix . 'clientoctopus_milestones';

		$where = $owner_id
			? $wpdb->prepare( "pr.id = %d AND pr.owner_id = %d AND pr.deleted_at IS NULL", $id, $owner_id )
			: $wpdb->prepare( "pr.id = %d AND pr.deleted_at IS NULL", $id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT pr.*,
			        c.name    AS client_name,
			        c.email   AS client_email,
			        c.company AS client_company,
			        p.title   AS proposal_title,
			        p.token   AS proposal_token,
			        COUNT(m.id) AS milestone_total,
			        SUM( CASE WHEN m.status = 'completed' THEN 1 ELSE 0 END ) AS milestone_completed
			 FROM $t pr
			 LEFT JOIN $ct c ON c.id = pr.client_id
			 LEFT JOIN $pt p ON p.id = pr.proposal_id
			 LEFT JOIN $mt m ON m.project_id = pr.id
			 WHERE $where
			 GROUP BY pr.id",
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		$row = self::prepare_row( $row );

		// Include full milestone list.
		$row['milestones'] = ClientOctopus_Milestone::list( $row['id'], $owner_id );

		return $row;
	}

	/**
	 * List projects for an owner with optional filters.
	 *
	 * @param int   $owner_id
	 * @param array $args { status, search, page, per_page, orderby, order }
	 *
	 * @return array { projects: [], total: int, pages: int }
	 */
	public static function list( int $owner_id, array $args = [] ): array {
		global $wpdb;

		$status   = $args['status']   ?? '';
		$search   = $args['search']   ?? '';
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$orderby  = in_array( $args['orderby'] ?? 'created_at', [ 'created_at', 'updated_at', 'name', 'status' ], true )
			? $args['orderby']
			: 'created_at';
		$order    = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$t  = self::table();
		$ct = $wpdb->prefix . 'clientoctopus_clients';
		$pt = $wpdb->prefix . 'clientoctopus_proposals';
		$mt = $wpdb->prefix . 'clientoctopus_milestones';

		$where = [ $wpdb->prepare( "pr.owner_id = %d", $owner_id ), "pr.deleted_at IS NULL" ];

		if ( $status && in_array( $status, [ 'active', 'on-hold', 'completed' ], true ) ) {
			$where[] = $wpdb->prepare( "pr.status = %s", $status );
		}

		if ( $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( "(pr.name LIKE %s OR c.name LIKE %s)", $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT pr.id) FROM $t pr
			 LEFT JOIN $ct c ON c.id = pr.client_id
			 WHERE $where_sql"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT pr.*,
			        c.name    AS client_name,
			        c.email   AS client_email,
			        c.company AS client_company,
			        p.title   AS proposal_title,
			        p.token   AS proposal_token,
			        COUNT(m.id) AS milestone_total,
			        SUM( CASE WHEN m.status = 'completed' THEN 1 ELSE 0 END ) AS milestone_completed
			 FROM $t pr
			 LEFT JOIN $ct c  ON c.id  = pr.client_id
			 LEFT JOIN $pt p  ON p.id  = pr.proposal_id
			 LEFT JOIN $mt m  ON m.project_id = pr.id
			 WHERE $where_sql
			 GROUP BY pr.id
			 ORDER BY pr.$orderby $order
			 LIMIT $per_page OFFSET $offset",
			ARRAY_A
		);

		return [
			'projects' => array_map( [ __CLASS__, 'prepare_row' ], $rows ?: [] ),
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Get a project by proposal ID.
	 *
	 * @param int $proposal_id
	 *
	 * @return array|WP_Error
	 */
	public static function get_by_proposal( int $proposal_id ): array|WP_Error {
		global $wpdb;

		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::table() . " WHERE proposal_id = %d LIMIT 1",
				$proposal_id
			)
		);

		if ( ! $id ) {
			return new WP_Error( 'project_not_found', __( 'No project for this proposal.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return self::get( $id );
	}

	// ── Update ────────────────────────────────────────────────────────────────

	/**
	 * Update a project.
	 *
	 * @param int   $id
	 * @param int   $owner_id
	 * @param array $data Fields: name, description, status.
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, int $owner_id, array $data ): true|WP_Error {
		global $wpdb;

		$allowed = [ 'name', 'description', 'status' ];
		$update  = array_intersect_key( $data, array_flip( $allowed ) );

		if ( empty( $update ) ) {
			return new WP_Error( 'no_data', __( 'No valid fields to update.', 'clientoctopus' ), [ 'status' => 400 ] );
		}

		// Set completed_at when transitioning to completed.
		if ( ( $update['status'] ?? '' ) === 'completed' ) {
			$update['completed_at'] = current_time( 'mysql' );
			do_action( 'clientoctopus_project_completed', $id, $owner_id );
		}

		$update['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update(
			self::table(),
			$update,
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		if ( false === $result ) {
			return new WP_Error( 'db_update_failed', __( 'Failed to update project.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		if ( 0 === $result ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE id = %d AND owner_id = %d", $id, $owner_id ) );
			if ( ! $exists ) {
				return new WP_Error( 'project_not_found', __( 'Project not found.', 'clientoctopus' ), [ 'status' => 404 ] );
			}
		}

		return true;
	}

	// ── Delete ────────────────────────────────────────────────────────────────

	/**
	 * Delete a project and all its milestones.
	 *
	 * @param int $id
	 * @param int $owner_id
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id, int $owner_id ): true|WP_Error {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::table() . " WHERE id = %d AND owner_id = %d AND deleted_at IS NULL",
				$id,
				$owner_id
			)
		);

		if ( ! $exists ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		$now = current_time( 'mysql' );

		$wpdb->update(
			self::table(),
			[ 'deleted_at' => $now ],
			[ 'id' => $id, 'owner_id' => $owner_id ],
			[ '%s' ],
			[ '%d', '%d' ]
		);

		// Hard-delete all uploaded files to free disk space and storage quota.
		if ( ! class_exists( 'ClientOctopus_File' ) ) {
			require_once CLIENTOCTOPUS_DIR . 'modules/files/class-file.php';
		}
		ClientOctopus_File::delete_for_project( $id, $owner_id );

		// Remove child milestones so they don't orphan in the milestones table.
		$wpdb->delete(
			$wpdb->prefix . 'clientoctopus_milestones',
			[ 'project_id' => $id, 'owner_id' => $owner_id ],
			[ '%d', '%d' ]
		);

		return true;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Prepare a raw database row for API response.
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	public static function prepare_row( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['owner_id']    = (int) $row['owner_id'];
		$row['client_id']   = (int) $row['client_id'];
		$row['proposal_id'] = (int) $row['proposal_id'];

		$total     = (int) ( $row['milestone_total']     ?? 0 );
		$completed = (int) ( $row['milestone_completed'] ?? 0 );

		$row['milestone_total']     = $total;
		$row['milestone_completed'] = $completed;
		$row['progress_pct']        = $total > 0 ? (int) round( $completed / $total * 100 ) : 0;

		return $row;
	}
}
