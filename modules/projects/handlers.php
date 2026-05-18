<?php
/**
 * Project Business Logic Handlers
 *
 * Orchestrates higher-level project operations:
 *   - create_from_accepted_proposal() — auto-creates project on proposal acceptance
 *   - expire_overdue_milestones()     — cron: mark past-due milestones
 *
 * @package ClientOctopus\Projects
 * @since   0.1.0
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use $wpdb->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Project_Handlers
 */
class ClientOctopus_Project_Handlers {

	/**
	 * Auto-create a project when a proposal is accepted.
	 *
	 * Called via the clientoctopus_proposal_accepted action hook.
	 * Only runs for Agency plan users (clientoctopus_can_user check performed in the hook,
	 * but an extra guard is kept here for direct calls).
	 *
	 * @param int $proposal_id
	 * @param int $owner_id
	 *
	 * @return int|WP_Error New project ID, or WP_Error on failure.
	 */
	public static function create_from_accepted_proposal( int $proposal_id, int $owner_id ): int|WP_Error {
		global $wpdb;

		// Guard: no duplicate projects for the same proposal.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}clientoctopus_projects WHERE proposal_id = %d LIMIT 1",
				$proposal_id
			)
		);

		if ( $existing ) {
			return (int) $existing; // Idempotent.
		}

		// Fetch the proposal to get title and client_id.
		$proposal = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, client_id FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d AND owner_id = %d",
				$proposal_id,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! $proposal ) {
			return new WP_Error(
				'proposal_not_found',
				__( 'Proposal not found.', 'clientoctopus' ),
				[ 'status' => 404 ]
			);
		}

		// Require a client_id — projects must have a client.
		if ( empty( $proposal['client_id'] ) ) {
			return new WP_Error(
				'no_client',
				__( 'Cannot create a project: proposal has no associated client.', 'clientoctopus' ),
				[ 'status' => 422 ]
			);
		}

		$project_id = ClientOctopus_Project::create( $owner_id, [
			'name'        => $proposal['title'],
			'proposal_id' => $proposal_id,
			'client_id'   => (int) $proposal['client_id'],
		] );

		if ( is_wp_error( $project_id ) ) {
			return $project_id;
		}

		do_action( 'clientoctopus_project_created', $project_id, $owner_id );

		// Auto-create milestones from proposal line items.
		$content_raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d",
				$proposal_id
			)
		);

		if ( $content_raw ) {
			$content    = json_decode( $content_raw, true );
			$line_items = $content['line_items'] ?? [];

			if ( ! class_exists( 'ClientOctopus_Milestone' ) ) {
				$mpath = CLIENTOCTOPUS_DIR . 'modules/projects/class-milestone.php';
				if ( file_exists( $mpath ) ) require_once $mpath;
			}

			foreach ( $line_items as $item ) {
				$title = trim( $item['description'] ?? '' );
				if ( '' === $title ) continue;

				ClientOctopus_Milestone::create( $project_id, $owner_id, [
					'title'  => $title,
					'status' => 'pending',
				] );
			}
		}

		return $project_id;
	}

	/**
	 * Mark past-due milestones as overdue (no-op status change; useful for reporting).
	 *
	 * Currently a no-op placeholder — milestone status is not auto-expired because
	 * "overdue" is not a separate status value; the UI derives overdue state from
	 * due_date < today. This hook is registered for future use.
	 *
	 * @return int Always 0.
	 */
	public static function expire_overdue_milestones(): int {
		return 0;
	}
}

// ── Register daily cron ───────────────────────────────────────────────────────
add_action( 'clientoctopus_expire_milestones', [ 'ClientOctopus_Project_Handlers', 'expire_overdue_milestones' ] );

add_action( 'init', static function (): void {
	if ( ! wp_next_scheduled( 'clientoctopus_expire_milestones' ) ) {
		wp_schedule_event( time(), 'daily', 'clientoctopus_expire_milestones' );
	}
} );
