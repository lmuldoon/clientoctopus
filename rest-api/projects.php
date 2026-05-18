<?php
/**
 * REST API: Project & Milestone Endpoints
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Admin routes (authenticated WordPress users):
 *   GET    /projects                             — list projects
 *   GET    /projects/{id}                        — get project + milestones
 *   POST   /projects/{id}/update                 — update project fields
 *   DELETE /projects/{id}                        — delete project
 *   POST   /projects/{id}/milestones             — create milestone
 *   POST   /projects/{id}/milestones/{mid}/update — update milestone
 *   DELETE /projects/{id}/milestones/{mid}       — delete milestone
 *   POST   /projects/{id}/milestones/reorder     — reorder milestones
 *
 * Portal routes (authenticated portal / clientoctopus_client users):
 *   GET    /portal/projects                      — client's projects
 *   GET    /portal/projects/{id}                 — single project + milestones
 *
 * @package ClientOctopus
 * @since   0.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', static function (): void {

	// Explicitly require module files — autoloader cannot resolve these class names
	// because strstr('project', '-', true) and strstr('milestone', '-', true) both
	// return false (no hyphen), so the autoloader path resolution fails.
	$base = CLIENTOCTOPUS_DIR . 'modules/projects/';
	foreach ( [
		'class-project.php'   => 'ClientOctopus_Project',
		'class-milestone.php' => 'ClientOctopus_Milestone',
		'handlers.php'        => 'ClientOctopus_Project_Handlers',
	] as $file => $class ) {
		if ( ! class_exists( $class ) && file_exists( $base . $file ) ) {
			require_once $base . $file;
		}
	}

	// Also ensure Portal_Data is available (for portal routes).
	if ( ! class_exists( 'ClientOctopus_Portal_Data' ) ) {
		$p = CLIENTOCTOPUS_DIR . 'modules/portal/class-portal-data.php';
		if ( file_exists( $p ) ) require_once $p;
	}
	if ( ! class_exists( 'ClientOctopus_Portal_Auth' ) ) {
		$p = CLIENTOCTOPUS_DIR . 'modules/portal/class-portal-auth.php';
		if ( file_exists( $p ) ) require_once $p;
	}

	$ns = 'clientoctopus/v1';

	// ── GET /projects ─────────────────────────────────────────────────────────
	register_rest_route( $ns, '/projects', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_list_projects',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'status'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
			'search'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
			'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
			'orderby'  => [ 'type' => 'string', 'default' => 'created_at', 'sanitize_callback' => 'sanitize_key' ],
			'order'    => [ 'type' => 'string', 'default' => 'DESC', 'enum' => [ 'ASC', 'DESC' ] ],
		],
	] );

	// ── GET /projects/{id} ────────────────────────────────────────────────────
	register_rest_route( $ns, '/projects/(?P<id>\d+)', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_get_project',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── POST /projects/{id}/update ────────────────────────────────────────────
	register_rest_route( $ns, '/projects/(?P<id>\d+)/update', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_update_project',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'id'          => [ 'type' => 'integer', 'required' => true ],
			'name'        => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'description' => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ],
			'status'      => [ 'type' => 'string', 'required' => false, 'enum' => [ 'active', 'on-hold', 'completed' ] ],
		],
	] );

	// ── DELETE /projects/{id} ─────────────────────────────────────────────────
	register_rest_route( $ns, '/projects/(?P<id>\d+)', [
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'clientoctopus_rest_delete_project',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── GET /projects/{id}/payments ───────────────────────────────────────────
	register_rest_route( $ns, '/projects/(?P<id>\d+)/payments', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_get_project_payments',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── POST /projects/{id}/milestones ────────────────────────────────────────
	register_rest_route( $ns, '/projects/(?P<id>\d+)/milestones', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_create_milestone',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'id'          => [ 'type' => 'integer', 'required' => true ],
			'title'       => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
			'description' => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ],
			'due_date'    => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// ── POST /projects/{id}/milestones/{mid}/update ───────────────────────────
	register_rest_route( $ns, '/projects/(?P<id>\d+)/milestones/(?P<mid>\d+)/update', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_update_milestone',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'id'          => [ 'type' => 'integer', 'required' => true ],
			'mid'         => [ 'type' => 'integer', 'required' => true ],
			'title'       => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'description' => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ],
			'status'      => [ 'type' => 'string',  'required' => false, 'enum' => ClientOctopus_Milestone::STATUSES ],
			'due_date'    => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// ── DELETE /projects/{id}/milestones/{mid} ────────────────────────────────
	register_rest_route( $ns, '/projects/(?P<id>\d+)/milestones/(?P<mid>\d+)', [
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'clientoctopus_rest_delete_milestone',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'id'  => [ 'type' => 'integer', 'required' => true ],
			'mid' => [ 'type' => 'integer', 'required' => true ],
		],
	] );

	// ── POST /projects/{id}/milestones/reorder ────────────────────────────────
	register_rest_route( $ns, '/projects/(?P<id>\d+)/milestones/reorder', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_reorder_milestones',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'id'          => [ 'type' => 'integer', 'required' => true ],
			'ordered_ids' => [ 'type' => 'array',   'required' => true ],
		],
	] );

	// ── POST /projects/{id}/milestones/{mid}/submit ───────────────────────────
	register_rest_route( $ns, '/projects/(?P<id>\d+)/milestones/(?P<mid>\d+)/submit', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_submit_milestone',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'id'  => [ 'type' => 'integer', 'required' => true ],
			'mid' => [ 'type' => 'integer', 'required' => true ],
		],
	] );

	// ── Portal: GET /portal/projects ──────────────────────────────────────────
	register_rest_route( $ns, '/portal/projects', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_rest_list_projects',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
	] );

	// ── Portal: GET /portal/projects/{id} ─────────────────────────────────────
	register_rest_route( $ns, '/portal/projects/(?P<id>\d+)', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_rest_get_project',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── Portal: POST /portal/projects/{id}/milestones/{mid}/approve ───────────
	register_rest_route( $ns, '/portal/projects/(?P<id>\d+)/milestones/(?P<mid>\d+)/approve', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_portal_rest_approve_milestone',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [
			'id'  => [ 'type' => 'integer', 'required' => true ],
			'mid' => [ 'type' => 'integer', 'required' => true ],
		],
	] );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Admin handlers
// ─────────────────────────────────────────────────────────────────────────────

function clientoctopus_rest_list_projects( WP_REST_Request $request ): WP_REST_Response {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$result  = ClientOctopus_Project::list( $user_id, [
		'status'   => $request->get_param( 'status' ),
		'search'   => $request->get_param( 'search' ),
		'page'     => (int) $request->get_param( 'page' ),
		'per_page' => (int) $request->get_param( 'per_page' ),
		'orderby'  => $request->get_param( 'orderby' ),
		'order'    => $request->get_param( 'order' ),
	] );
	return new WP_REST_Response( $result, 200 );
}

function clientoctopus_rest_get_project( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );
	$result  = ClientOctopus_Project::get( $id, $user_id );
	if ( is_wp_error( $result ) ) return $result;

	// Keep proposal status in sync whenever this project is viewed.
	if ( 'completed' === ( $result['status'] ?? '' ) && ! empty( $result['proposal_id'] ) ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}clientoctopus_proposals
				 SET status = 'completed'
				 WHERE id = %d AND owner_id = %d AND status NOT IN ('completed', 'expired')",
				(int) $result['proposal_id'],
				$user_id
			)
		);
	}

	return new WP_REST_Response( [ 'project' => $result ], 200 );
}

/**
 * Returns a WP_Error if the given project is locked (completed + fully paid), null otherwise.
 */
function clientoctopus_project_lock_check( int $project_id, int $owner_id ): ?WP_Error {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT pr.status,
			        COALESCE(prop.total_amount, 0) AS total_amount,
			        COALESCE(
			            ( SELECT SUM(pm.amount)
			              FROM {$wpdb->prefix}clientoctopus_payments pm
			              WHERE pm.proposal_id = pr.proposal_id AND pm.status = 'completed' ),
			            0
			        ) AS total_paid
			 FROM {$wpdb->prefix}clientoctopus_projects pr
			 LEFT JOIN {$wpdb->prefix}clientoctopus_proposals prop ON prop.id = pr.proposal_id
			 WHERE pr.id = %d AND pr.owner_id = %d",
			$project_id,
			$owner_id
		),
		ARRAY_A
	);

	if ( ! $row || 'completed' !== $row['status'] ) {
		return null;
	}

	$fully_paid = (float) $row['total_amount'] <= 0 || (float) $row['total_paid'] >= (float) $row['total_amount'];

	return $fully_paid
		? new WP_Error( 'project_locked', __( 'This project is complete and fully paid — it can no longer be edited.', 'clientoctopus' ), [ 'status' => 422 ] )
		: null;
}

function clientoctopus_rest_update_project( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$user_id = clientoctopus_get_owner_id( get_current_user_id() );

	if ( ! clientoctopus_can_user( $user_id, 'use_projects' ) ) {
		return new WP_Error( 'projects_not_available', __( 'Projects are available on the Agency plan.', 'clientoctopus' ), [ 'status' => 403 ] );
	}

	$id = (int) $request->get_param( 'id' );
	$data    = array_filter(
		$request->get_params(),
		static fn( $k ) => in_array( $k, [ 'name', 'description', 'status' ], true ),
		ARRAY_FILTER_USE_KEY
	);

	// Gate: completed + fully-paid projects are locked — no further edits allowed.
	$lock_error = clientoctopus_project_lock_check( $id, $user_id );
	if ( $lock_error ) return $lock_error;

	// Gate: cannot mark completed until all milestones are done.
	if ( isset( $data['status'] ) && 'completed' === $data['status'] ) {
		if ( ! ClientOctopus_Milestone::all_completed( $id ) ) {
			return new WP_Error(
				'milestones_incomplete',
				__( 'All milestones must be marked complete before the project can be closed.', 'clientoctopus' ),
				[ 'status' => 422 ]
			);
		}
	}

	$result = ClientOctopus_Project::update( $id, $user_id, $data );
	if ( is_wp_error( $result ) ) return $result;

	$project = ClientOctopus_Project::get( $id, $user_id );

	// On project completion: stamp the proposal as completed and email the client.
	if ( isset( $data['status'] ) && 'completed' === $data['status'] && ! is_wp_error( $project ) ) {
		if ( ! empty( $project['proposal_id'] ) ) {
			$wpdb->update(
				$wpdb->prefix . 'clientoctopus_proposals',
				[ 'status' => 'completed' ],
				[ 'id' => (int) $project['proposal_id'], 'owner_id' => $user_id ],
				[ '%s' ],
				[ '%d', '%d' ]
			);
		}
		clientoctopus_send_project_completion_email( $project );
		clientoctopus_maybe_send_testimonial_email( $project, $user_id );
	} elseif ( ! is_wp_error( $project ) && 'completed' === ( $project['status'] ?? '' ) && ! empty( $project['proposal_id'] ) ) {
		// Project is already complete — sync the proposal status in case it was missed.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}clientoctopus_proposals
				 SET status = 'completed'
				 WHERE id = %d AND owner_id = %d AND status NOT IN ('completed', 'expired')",
				(int) $project['proposal_id'],
				$user_id
			)
		);
	}

	return new WP_REST_Response( [ 'project' => $project ], 200 );
}

function clientoctopus_rest_delete_project( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );

	if ( ! clientoctopus_can_user( $user_id, 'use_projects' ) ) {
		return new WP_Error( 'projects_not_available', __( 'Projects are available on the Agency plan.', 'clientoctopus' ), [ 'status' => 403 ] );
	}

	$id = (int) $request->get_param( 'id' );
	$result  = ClientOctopus_Project::delete( $id, $user_id );
	if ( is_wp_error( $result ) ) return $result;
	return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
}

function clientoctopus_rest_get_project_payments( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );

	$project = ClientOctopus_Project::get( $id, $user_id );
	if ( is_wp_error( $project ) ) return $project;

	$proposal_id    = (int) ( $project['proposal_id'] ?? 0 );
	$proposal_total = null;

	if ( $proposal_id ) {
		$proposal_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT total_amount FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d",
				$proposal_id
			)
		);
	}

	$rows = $proposal_id ? $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, amount, currency, deposit_pct, status, completed_at, created_at
			 FROM {$wpdb->prefix}clientoctopus_payments
			 WHERE proposal_id = %d
			 ORDER BY created_at ASC",
			$proposal_id
		),
		ARRAY_A
	) : [];

	$payments   = array_map( static function ( array $r ): array {
		$r['id']          = (int) $r['id'];
		$r['amount']      = (float) $r['amount'];
		$r['deposit_pct'] = (int) $r['deposit_pct'];
		return $r;
	}, $rows ?: [] );

	$total_paid = array_sum( array_column(
		array_filter( $payments, static fn( $p ) => 'completed' === $p['status'] ),
		'amount'
	) );

	return new WP_REST_Response( [
		'payments'       => $payments,
		'total_paid'     => $total_paid,
		'proposal_total' => $proposal_total,
		'remaining'      => $proposal_total !== null ? max( 0.0, $proposal_total - $total_paid ) : null,
	], 200 );
}

function clientoctopus_rest_create_milestone( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );

	if ( ! clientoctopus_can_user( $user_id, 'use_projects' ) ) {
		return new WP_Error( 'projects_not_available', __( 'Projects are available on the Agency plan.', 'clientoctopus' ), [ 'status' => 403 ] );
	}

	$lock_error = clientoctopus_project_lock_check( $project_id, $user_id );
	if ( $lock_error ) return $lock_error;

	// Ownership check.
	$project = ClientOctopus_Project::get( $project_id, $user_id );
	if ( is_wp_error( $project ) ) return $project;

	$mid = ClientOctopus_Milestone::create( $project_id, $user_id, [
		'title'       => $request->get_param( 'title' ),
		'description' => $request->get_param( 'description' ) ?? '',
		'due_date'    => $request->get_param( 'due_date' )    ?? '',
	] );

	if ( is_wp_error( $mid ) ) return $mid;

	$project = ClientOctopus_Project::get( $project_id, $user_id );
	return new WP_REST_Response( [ 'project' => $project ], 201 );
}

function clientoctopus_rest_update_milestone( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );
	$mid        = (int) $request->get_param( 'mid' );

	if ( ! clientoctopus_can_user( $user_id, 'use_projects' ) ) {
		return new WP_Error( 'projects_not_available', __( 'Projects are available on the Agency plan.', 'clientoctopus' ), [ 'status' => 403 ] );
	}
	$data       = array_filter(
		$request->get_params(),
		static fn( $k ) => in_array( $k, [ 'title', 'description', 'status', 'due_date' ], true ),
		ARRAY_FILTER_USE_KEY
	);

	// Block admin from marking a milestone complete until the client has approved it.
	if ( isset( $data['status'] ) && 'completed' === $data['status'] ) {
		global $wpdb;
		$current_status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}clientoctopus_milestones WHERE id = %d AND owner_id = %d",
				$mid,
				$user_id
			)
		);
		if ( in_array( $current_status, [ 'pending', 'submitted' ], true ) ) {
			return new WP_Error(
				'awaiting_client_approval',
				__( 'This milestone must be approved by the client before it can be marked complete.', 'clientoctopus' ),
				[ 'status' => 422 ]
			);
		}
	}

	$result = ClientOctopus_Milestone::update( $mid, $user_id, $data );
	if ( is_wp_error( $result ) ) return $result;

	$project = ClientOctopus_Project::get( $project_id, $user_id );

	// Email client when a milestone is marked complete.
	if ( isset( $data['status'] ) && 'completed' === $data['status'] && ! is_wp_error( $project ) ) {
		$milestone_title = '';
		foreach ( $project['milestones'] ?? [] as $m ) {
			if ( (int) $m['id'] === $mid ) {
				$milestone_title = $m['title'];
				break;
			}
		}
		clientoctopus_send_milestone_complete_email( $project, $milestone_title );
	}

	return new WP_REST_Response( [ 'project' => $project ], 200 );
}

function clientoctopus_rest_delete_milestone( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );
	$mid        = (int) $request->get_param( 'mid' );

	if ( ! clientoctopus_can_user( $user_id, 'use_projects' ) ) {
		return new WP_Error( 'projects_not_available', __( 'Projects are available on the Agency plan.', 'clientoctopus' ), [ 'status' => 403 ] );
	}

	$result = ClientOctopus_Milestone::delete( $mid, $user_id );
	if ( is_wp_error( $result ) ) return $result;
	$project = ClientOctopus_Project::get( $project_id, $user_id );
	return new WP_REST_Response( [ 'project' => $project ], 200 );
}

function clientoctopus_rest_reorder_milestones( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );

	if ( ! clientoctopus_can_user( $user_id, 'use_projects' ) ) {
		return new WP_Error( 'projects_not_available', __( 'Projects are available on the Agency plan.', 'clientoctopus' ), [ 'status' => 403 ] );
	}

	$ids = array_map( 'intval', (array) $request->get_param( 'ordered_ids' ) );
	$result     = ClientOctopus_Milestone::reorder( $project_id, $user_id, $ids );
	if ( is_wp_error( $result ) ) return $result;
	$project = ClientOctopus_Project::get( $project_id, $user_id );
	return new WP_REST_Response( [ 'project' => $project ], 200 );
}

function clientoctopus_rest_submit_milestone( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );
	$mid        = (int) $request->get_param( 'mid' );

	$project = ClientOctopus_Project::get( $project_id, $user_id );
	if ( is_wp_error( $project ) ) return $project;

	$result = ClientOctopus_Milestone::submit( $mid, $user_id );
	if ( is_wp_error( $result ) ) return $result;

	$project = ClientOctopus_Project::get( $project_id, $user_id );

	// Find the submitted milestone title for the email.
	$milestone_title = '';
	foreach ( $project['milestones'] ?? [] as $m ) {
		if ( (int) $m['id'] === $mid ) {
			$milestone_title = $m['title'];
			break;
		}
	}
	clientoctopus_send_milestone_submitted_email( $project, $milestone_title );

	return new WP_REST_Response( [ 'project' => $project ], 200 );
}

// ─────────────────────────────────────────────────────────────────────────────
// Portal handlers
// ─────────────────────────────────────────────────────────────────────────────

function clientoctopus_portal_rest_list_projects( WP_REST_Request $request ): WP_REST_Response {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$projects = ClientOctopus_Portal_Data::get_projects( $user_id );
	return new WP_REST_Response( [ 'projects' => $projects ], 200 );
}

function clientoctopus_portal_rest_get_project( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );
	$project = ClientOctopus_Portal_Data::get_project( $user_id, $id );
	if ( is_wp_error( $project ) ) return $project;
	return new WP_REST_Response( [ 'project' => $project ], 200 );
}

function clientoctopus_portal_rest_approve_milestone( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );
	$mid        = (int) $request->get_param( 'mid' );

	// Ownership check — ensure this project belongs to the current portal user.
	$project = ClientOctopus_Portal_Data::get_project( $user_id, $project_id );
	if ( is_wp_error( $project ) ) return $project;

	$result = ClientOctopus_Milestone::approve( $mid, $project_id );
	if ( is_wp_error( $result ) ) return $result;

	// Reload so the response has the updated milestone status.
	$project = ClientOctopus_Portal_Data::get_project( $user_id, $project_id );

	// Notify the project owner.
	$milestone_title = '';
	foreach ( ( ! is_wp_error( $project ) ? $project['milestones'] ?? [] : [] ) as $m ) {
		if ( (int) $m['id'] === $mid ) {
			$milestone_title = $m['title'];
			break;
		}
	}
	if ( ! is_wp_error( $project ) ) {
		clientoctopus_send_milestone_approved_email( $project, $milestone_title );
	}

	return new WP_REST_Response( [ 'project' => $project ], 200 );
}

// ─────────────────────────────────────────────────────────────────────────────
// Email helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch the client email address for a project row.
 *
 * @param array $project Project row (must contain client_id or proposal_id).
 * @return string Client email, or empty string if not resolvable.
 */
function clientoctopus_project_client_email( array $project ): string {
	return clientoctopus_project_client_data( $project )['email'];
}

function clientoctopus_project_client_data( array $project ): array {
	global $wpdb;

	$client_id = (int) ( $project['client_id'] ?? 0 );
	if ( ! $client_id ) return [ 'email' => '', 'name' => '' ];

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT email, name FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d",
			$client_id
		),
		ARRAY_A
	);

	return [
		'email' => (string) ( $row['email'] ?? '' ),
		'name'  => (string) ( $row['name']  ?? '' ),
	];
}

/**
 * Email the client that a milestone has been submitted for their approval.
 *
 * @param array  $project        Full project row (from ClientOctopus_Project::get).
 * @param string $milestone_title
 */
function clientoctopus_send_milestone_submitted_email( array $project, string $milestone_title ): void {
	$client = clientoctopus_project_client_data( $project );
	if ( ! $client['email'] ) return;

	$project_name  = esc_html( $project['name'] ?? '' );
	$milestone_esc = esc_html( $milestone_title );
	$subject = 'Milestone Ready for Approval' . ( $milestone_title ? ': ' . $milestone_title : '' );

	$body_html = "
		<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
			A milestone on your project <strong style=\"color:#1A1A2E;\">{$project_name}</strong>
			has been submitted and is ready for your review and approval.
		</p>
		<div style=\"margin:20px 0;padding:16px 20px;background:#EEF2FF;border-radius:10px;border-left:3px solid #6366F1;\">
			<p style=\"margin:0;font-size:13px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#6366F1;\">Awaiting Approval</p>
			<p style=\"margin:6px 0 0;font-size:16px;font-weight:600;color:#1A1A2E;\">{$milestone_esc}</p>
		</div>
		<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
			Log in to your portal to review and approve this milestone.
		</p>";

	$message = clientoctopus_email_html( [
		'name'      => $client['name'],
		'body'      => $body_html,
		'cta_label' => 'Review Milestone',
		'cta_url'   => home_url( '/clientoctopus/' ),
	] );

	wp_mail( $client['email'], $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
}

/**
 * Email the project owner (admin) that a client approved a milestone.
 *
 * @param array  $project        Portal project row (includes owner_id, name).
 * @param string $milestone_title
 */
function clientoctopus_send_milestone_approved_email( array $project, string $milestone_title ): void {
	$owner = get_userdata( (int) ( $project['owner_id'] ?? 0 ) );
	if ( ! $owner || ! $owner->user_email ) return;

	$project_name  = esc_html( $project['name'] ?? '' );
	$milestone_esc = esc_html( $milestone_title );
	$client_name   = esc_html( $project['client_name'] ?? 'Your client' );
	$subject       = 'Milestone Approved' . ( $milestone_title ? ': ' . $milestone_title : '' );

	$body_html = "
		<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
			<strong style=\"color:#1A1A2E;\">{$client_name}</strong> has approved a milestone
			on project <strong style=\"color:#1A1A2E;\">{$project_name}</strong>.
		</p>
		<div style=\"margin:20px 0;padding:16px 20px;background:#F0FDF4;border-radius:10px;border-left:3px solid #10B981;\">
			<p style=\"margin:0;font-size:13px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#10B981;\">Approved</p>
			<p style=\"margin:6px 0 0;font-size:16px;font-weight:600;color:#1A1A2E;\">{$milestone_esc}</p>
		</div>
		<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
			You can now mark this milestone as complete in your projects dashboard.
		</p>";

	$message = clientoctopus_email_html( [
		'name'      => $owner->display_name,
		'body'      => $body_html,
		'cta_label' => 'View Project',
		'cta_url'   => admin_url( 'admin.php?page=clientoctopus-projects' ),
	] );

	wp_mail( $owner->user_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
}

/**
 * Email the client that a milestone has been marked complete.
 *
 * @param array  $project        Full project row (from ClientOctopus_Project::get).
 * @param string $milestone_title
 */
function clientoctopus_send_milestone_complete_email( array $project, string $milestone_title ): void {
	$client = clientoctopus_project_client_data( $project );
	if ( ! $client['email'] ) return;

	$project_name   = esc_html( $project['name'] ?? '' );
	$milestone_esc  = esc_html( $milestone_title );
	$subject        = sprintf( 'Milestone Complete: %s', $milestone_title );

	$body_html = "
		<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
			Great news — a milestone has been completed on your project
			<strong style=\"color:#1A1A2E;\">{$project_name}</strong>.
		</p>
		<div style=\"margin:20px 0;padding:16px 20px;background:#F0FDF4;border-radius:10px;border-left:3px solid #10B981;\">
			<p style=\"margin:0;font-size:13px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#10B981;\">Completed</p>
			<p style=\"margin:6px 0 0;font-size:16px;font-weight:600;color:#1A1A2E;\">{$milestone_esc}</p>
		</div>
		<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
			Log in to your portal to see the latest progress on your project.
		</p>";

	$message = clientoctopus_email_html( [
		'name'      => $client['name'],
		'body'      => $body_html,
		'cta_label' => 'View Project',
		'cta_url'   => home_url( '/clientoctopus/' ),
	] );

	wp_mail( $client['email'], $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
}

/**
 * Email the client that their project has been completed.
 *
 * @param array $project Full project row (from ClientOctopus_Project::get).
 */
function clientoctopus_send_project_completion_email( array $project ): void {
	$client = clientoctopus_project_client_data( $project );
	if ( ! $client['email'] ) return;

	global $wpdb;

	$proposal_id  = (int) ( $project['proposal_id'] ?? 0 );
	$total_amount = 0.00;
	$paid_amount  = 0.00;

	if ( $proposal_id ) {
		$total_amount = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT total_amount FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d",
				$proposal_id
			)
		);
		$paid_amount = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}clientoctopus_payments WHERE proposal_id = %d AND status = 'completed'",
				$proposal_id
			)
		);
	}

	$remaining    = max( 0.00, $total_amount - $paid_amount );
	$project_name = esc_html( $project['name'] ?? '' );

	$payment_block = '';
	if ( $remaining > 0 ) {
		$amount_fmt    = esc_html( number_format( $remaining, 2 ) );
		$payment_block = "
		<div style=\"margin:20px 0;padding:16px 20px;background:#FFFBEB;border-radius:10px;border-left:3px solid #F59E0B;\">
			<p style=\"margin:0;font-size:13px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#F59E0B;\">Payment Due</p>
			<p style=\"margin:6px 0 0;font-size:20px;font-weight:700;color:#1A1A2E;\">&pound;{$amount_fmt}</p>
			<p style=\"margin:6px 0 0;font-size:13px;color:#9CA3AF;\">Please log in to your portal to arrange payment.</p>
		</div>";
	}

	$subject   = sprintf( '%s is Complete', $project['name'] ?? '' );
	$body_html = "
		<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
			Your project <strong style=\"color:#1A1A2E;\">{$project_name}</strong> has been completed.
			Thank you for working with us.
		</p>
		{$payment_block}
		<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
			Log in to your portal to view the final summary.
		</p>";

	$message = clientoctopus_email_html( [
		'name'      => $client['name'],
		'body'      => $body_html,
		'cta_label' => 'View Project',
		'cta_url'   => home_url( '/clientoctopus/' ),
	] );

	wp_mail( $client['email'], $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
}

function clientoctopus_maybe_send_testimonial_email( array $project, int $owner_id ): void {
	if ( '1' !== get_option( 'clientoctopus_testimonial_enabled' ) ) return;
	if ( ! clientoctopus_can_user( $owner_id, 'use_testimonials' ) ) return;

	$proposal_id = (int) ( $project['proposal_id'] ?? 0 );
	if ( ! $proposal_id ) return;

	global $wpdb;

	$proposal = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT total_amount, client_id, title FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d AND owner_id = %d",
			$proposal_id,
			$owner_id
		),
		ARRAY_A
	);

	if ( ! $proposal || empty( $proposal['total_amount'] ) || empty( $proposal['client_id'] ) ) return;

	$total_paid = (float) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}clientoctopus_payments WHERE proposal_id = %d AND status = 'completed'",
			$proposal_id
		)
	);

	if ( $total_paid < ( (float) $proposal['total_amount'] - 0.01 ) ) return;

	$client_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT name, email FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d",
			(int) $proposal['client_id']
		),
		ARRAY_A
	);

	if ( ! $client_row || empty( $client_row['email'] ) ) return;

	$body_text  = get_option( 'clientoctopus_testimonial_body', '' )
		?: __( "It was a pleasure working with you. If you have a moment, we\xe2\x80\x99d love to hear your feedback \xe2\x80\x94 it helps us improve and helps others find us.", 'clientoctopus' );
	$review_url = get_option( 'clientoctopus_testimonial_url', '' );
	$cta_label  = get_option( 'clientoctopus_testimonial_cta_label', '' ) ?: __( 'Leave a Review', 'clientoctopus' );

	$email_args = [
		'name' => $client_row['name'] ?? '',
		'body' => '<p style="margin:0;font-size:16px;color:#6B7280;line-height:1.65;">'
		          . nl2br( esc_html( $body_text ) ) . '</p>',
	];
	if ( $review_url ) {
		$email_args['cta_label'] = $cta_label;
		$email_args['cta_url']   = $review_url;
	}

	wp_mail(
		$client_row['email'],
		/* translators: %s is the proposal title */
		sprintf( __( 'How did we do? — %s', 'clientoctopus' ), $proposal['title'] ?? '' ),
		clientoctopus_email_html( $email_args ),
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);
}
