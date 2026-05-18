<?php
/**
 * Milestone Model
 *
 * CRUD for the clientoctopus_milestones table.
 * Milestones belong to a project and represent discrete steps of work.
 *
 * @package ClientOctopus\Projects
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Milestone
 */
class ClientOctopus_Milestone {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; table() returns a trusted constant, not user input.

	public const STATUSES = [ 'pending', 'submitted', 'in-progress', 'completed' ];

	private const TABLE = 'clientoctopus_milestones';

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	// ── Create ────────────────────────────────────────────────────────────────

	/**
	 * Create a milestone.
	 *
	 * @param int   $project_id
	 * @param int   $owner_id
	 * @param array $data title, description, due_date, sort_order.
	 *
	 * @return int|WP_Error New milestone ID.
	 */
	public static function create( int $project_id, int $owner_id, array $data ): int|WP_Error {
		global $wpdb;

		if ( empty( $data['title'] ) ) {
			return new WP_Error( 'missing_title', __( 'Milestone title is required.', 'clientoctopus' ), [ 'status' => 400 ] );
		}

		// Auto-assign sort_order if not provided.
		if ( ! isset( $data['sort_order'] ) ) {
			$max = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(MAX(sort_order), -1) FROM " . self::table() . " WHERE project_id = %d AND owner_id = %d",
					$project_id,
					$owner_id
				)
			);
			$data['sort_order'] = $max + 1;
		}

		$now = current_time( 'mysql' );

		$row = [
			'project_id'  => $project_id,
			'owner_id'    => $owner_id,
			'title'       => sanitize_text_field( $data['title'] ),
			'description' => sanitize_textarea_field( $data['description'] ?? '' ) ?: null,
			'status'      => 'pending',
			'due_date'    => ! empty( $data['due_date'] ) ? sanitize_text_field( $data['due_date'] ) : null,
			'sort_order'  => (int) $data['sort_order'],
			'created_at'  => $now,
			'updated_at'  => $now,
		];

		$inserted = $wpdb->insert( self::table(), $row );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Failed to create milestone.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		return (int) $wpdb->insert_id;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * List all milestones for a project, ordered by sort_order.
	 *
	 * @param int $project_id
	 * @param int $owner_id Pass 0 to skip ownership check.
	 *
	 * @return array[]
	 */
	public static function list( int $project_id, int $owner_id = 0 ): array {
		global $wpdb;

		$where = $owner_id
			? $wpdb->prepare( "project_id = %d AND owner_id = %d", $project_id, $owner_id )
			: $wpdb->prepare( "project_id = %d", $project_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT * FROM " . self::table() . " WHERE $where ORDER BY sort_order ASC, created_at ASC",
			ARRAY_A
		);

		return array_map( [ __CLASS__, 'prepare_row' ], $rows ?: [] );
	}

	// ── Update ────────────────────────────────────────────────────────────────

	/**
	 * Update a milestone.
	 *
	 * @param int   $id
	 * @param int   $owner_id
	 * @param array $data title, description, status, due_date.
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, int $owner_id, array $data ): true|WP_Error {
		global $wpdb;

		$allowed = [ 'title', 'description', 'status', 'due_date' ];
		$update  = array_intersect_key( $data, array_flip( $allowed ) );

		if ( empty( $update ) ) {
			return new WP_Error( 'no_data', __( 'No valid fields to update.', 'clientoctopus' ), [ 'status' => 400 ] );
		}

		// Block status changes on completed milestones — they are permanently locked.
		if ( isset( $update['status'] ) ) {
			$current_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM " . self::table() . " WHERE id = %d AND owner_id = %d",
					$id,
					$owner_id
				)
			);

			if ( 'completed' === $current_status ) {
				return new WP_Error(
					'milestone_locked',
					__( 'Completed milestones cannot be changed.', 'clientoctopus' ),
					[ 'status' => 422 ]
				);
			}
		}

		// Stamp completed_at when completing; clear it when un-completing.
		if ( isset( $update['status'] ) ) {
			if ( 'completed' === $update['status'] ) {
				$existing_completed = $wpdb->get_var(
					$wpdb->prepare( "SELECT completed_at FROM " . self::table() . " WHERE id = %d", $id )
				);
				if ( ! $existing_completed ) {
					$update['completed_at'] = current_time( 'mysql' );
				}
			} elseif ( in_array( $update['status'], [ 'pending', 'in-progress' ], true ) ) {
				$update['completed_at'] = null;
			}
		}

		$update['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update(
			self::table(),
			$update,
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		if ( false === $result ) {
			return new WP_Error( 'db_update_failed', __( 'Failed to update milestone.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		if ( 0 === $result ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE id = %d AND owner_id = %d", $id, $owner_id )
			);
			if ( ! $exists ) {
				return new WP_Error( 'milestone_not_found', __( 'Milestone not found.', 'clientoctopus' ), [ 'status' => 404 ] );
			}
		}

		return true;
	}

	// ── Delete ────────────────────────────────────────────────────────────────

	/**
	 * Delete a milestone.
	 *
	 * @param int $id
	 * @param int $owner_id
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id, int $owner_id ): true|WP_Error {
		global $wpdb;

		$result = $wpdb->delete(
			self::table(),
			[ 'id' => $id, 'owner_id' => $owner_id ],
			[ '%d', '%d' ]
		);

		if ( false === $result || 0 === $result ) {
			return new WP_Error( 'milestone_not_found', __( 'Milestone not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return true;
	}

	// ── Reorder ───────────────────────────────────────────────────────────────

	/**
	 * Update sort_order for a set of milestone IDs.
	 *
	 * @param int   $project_id
	 * @param int   $owner_id
	 * @param int[] $ordered_ids Milestone IDs in desired display order.
	 *
	 * @return true|WP_Error
	 */
	public static function reorder( int $project_id, int $owner_id, array $ordered_ids ): true|WP_Error {
		global $wpdb;

		foreach ( $ordered_ids as $sort_order => $milestone_id ) {
			$wpdb->update(
				self::table(),
				[
					'sort_order' => $sort_order,
					'updated_at' => current_time( 'mysql' ),
				],
				[ 'id' => (int) $milestone_id, 'project_id' => $project_id, 'owner_id' => $owner_id ]
			);
		}

		return true;
	}

	// ── Admin submit / Client approve ─────────────────────────────────────────

	/**
	 * Admin submits a pending milestone for client approval — transitions pending → submitted.
	 *
	 * @param int $id       Milestone ID.
	 * @param int $owner_id Must match to prevent cross-owner tampering.
	 *
	 * @return true|WP_Error
	 */
	public static function submit( int $id, int $owner_id ): true|WP_Error {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM " . self::table() . " WHERE id = %d AND owner_id = %d",
				$id,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'milestone_not_found', __( 'Milestone not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		if ( 'pending' !== $row['status'] ) {
			return new WP_Error( 'invalid_status', __( 'Only pending milestones can be submitted for approval.', 'clientoctopus' ), [ 'status' => 422 ] );
		}

		$wpdb->update(
			self::table(),
			[ 'status' => 'submitted', 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		return true;
	}

	/**
	 * Client approves a submitted milestone — transitions submitted → in-progress.
	 *
	 * @param int $id         Milestone ID.
	 * @param int $project_id Must match to prevent cross-project tampering.
	 *
	 * @return true|WP_Error
	 */
	public static function approve( int $id, int $project_id ): true|WP_Error {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM " . self::table() . " WHERE id = %d AND project_id = %d",
				$id,
				$project_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'milestone_not_found', __( 'Milestone not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		if ( 'submitted' !== $row['status'] ) {
			return new WP_Error( 'not_submitted', __( 'Only submitted milestones can be approved.', 'clientoctopus' ), [ 'status' => 422 ] );
		}

		$wpdb->update(
			self::table(),
			[ 'status' => 'in-progress', 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'project_id' => $project_id ]
		);

		return true;
	}

	/**
	 * Check whether all milestones for a project are completed.
	 *
	 * @param int $project_id
	 *
	 * @return bool True when there are no incomplete milestones (also true when no milestones exist).
	 */
	public static function all_completed( int $project_id ): bool {
		global $wpdb;

		$incomplete = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table() . " WHERE project_id = %d AND status != 'completed'",
				$project_id
			)
		);

		return 0 === $incomplete;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Prepare a raw database row for the API response.
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	public static function prepare_row( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['project_id'] = (int) $row['project_id'];
		$row['owner_id']   = (int) $row['owner_id'];
		$row['sort_order'] = (int) $row['sort_order'];

		return $row;
	}
}
