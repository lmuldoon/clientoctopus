<?php
/**
 * REST API: Approval Endpoints
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Admin routes (authenticated WordPress users):
 *   GET    /projects/{id}/approvals          — list approval requests for a project
 *   POST   /projects/{id}/approvals          — create an approval request
 *   DELETE /projects/{id}/approvals/{aid}    — delete an approval request
 *
 * Portal routes (authenticated portal / clientoctopus_client users):
 *   GET    /portal/projects/{id}/approvals         — list approvals (client)
 *   POST   /portal/approvals/{aid}/respond         — client approves or rejects
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

	// Load class.
	$path = CLIENTOCTOPUS_DIR . 'modules/approvals/class-approval.php';
	if ( ! class_exists( 'ClientOctopus_Approval' ) && file_exists( $path ) ) {
		require_once $path;
	}

	if ( ! class_exists( 'ClientOctopus_Portal_Auth' ) ) {
		$p = CLIENTOCTOPUS_DIR . 'modules/portal/class-portal-auth.php';
		if ( file_exists( $p ) ) {
			require_once $p;
		}
	}

	$ns         = 'clientoctopus/v1';
	$proj_id    = '(?P<id>\d+)';
	$approv_id  = '(?P<aid>\d+)';

	// ── Admin: list ──────────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/approvals", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_list_approvals',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── Admin: create ────────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/approvals", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_create_approval',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id'          => [ 'type' => 'integer', 'required' => true ],
			'type'        => [ 'type' => 'string',  'required' => false, 'default' => 'other' ],
			'description' => [ 'type' => 'string',  'required' => false, 'default' => '',     'sanitize_callback' => 'sanitize_textarea_field' ],
		],
	] );

	// ── Admin: delete ────────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/approvals/{$approv_id}", [
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'clientoctopus_rest_delete_approval',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id'  => [ 'type' => 'integer', 'required' => true ],
			'aid' => [ 'type' => 'integer', 'required' => true ],
		],
	] );

	// ── Portal: list ─────────────────────────────────────────────────────────
	register_rest_route( $ns, "/portal/projects/{$proj_id}/approvals", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_rest_list_approvals',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── Portal: respond ──────────────────────────────────────────────────────
	register_rest_route( $ns, "/portal/approvals/{$approv_id}/respond", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_portal_rest_respond_approval',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [
			'aid'     => [ 'type' => 'integer', 'required' => true ],
			'status'  => [ 'type' => 'string',  'required' => true,  'enum' => [ 'approved', 'rejected' ] ],
			'comment' => [ 'type' => 'string',  'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
		],
	] );
} );

// ── Admin handlers ────────────────────────────────────────────────────────────

function clientoctopus_rest_list_approvals( WP_REST_Request $request ): WP_REST_Response {
	$owner_id   = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );
	$approvals  = ClientOctopus_Approval::list( $project_id, $owner_id );

	return new WP_REST_Response( [ 'approvals' => $approvals ], 200 );
}

function clientoctopus_rest_create_approval( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id   = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );

	$lock_error = clientoctopus_project_lock_check( $project_id, $owner_id );
	if ( $lock_error ) {
		return new WP_Error(
			'project_locked',
			__( 'This project is complete — approval requests can no longer be added.', 'clientoctopus' ),
			[ 'status' => 422 ]
		);
	}

	$result = ClientOctopus_Approval::create( $project_id, $owner_id, [
		'type'        => $request->get_param( 'type' ),
		'description' => $request->get_param( 'description' ),
	] );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$approvals = ClientOctopus_Approval::list( $project_id, $owner_id );

	return new WP_REST_Response( [ 'approvals' => $approvals ], 201 );
}

function clientoctopus_rest_delete_approval( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id    = get_current_user_id();
	$approval_id = (int) $request->get_param( 'aid' );
	$result      = ClientOctopus_Approval::delete( $approval_id, $owner_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'deleted' => true ], 200 );
}

// ── Portal handlers ───────────────────────────────────────────────────────────

function clientoctopus_portal_rest_list_approvals( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$client_id  = get_current_user_id();
	$project_id = (int) $request->get_param( 'id' );
	$result     = ClientOctopus_Approval::get_for_client( $project_id, $client_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'approvals' => $result ], 200 );
}

function clientoctopus_portal_rest_respond_approval( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$client_id   = get_current_user_id();
	$approval_id = (int) $request->get_param( 'aid' );
	$status      = (string) $request->get_param( 'status' );
	$comment     = (string) $request->get_param( 'comment' );

	// Block responds on completed projects.
	$proj_status = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.status
			 FROM {$wpdb->prefix}clientoctopus_approvals a
			 JOIN {$wpdb->prefix}clientoctopus_projects p ON p.id = a.project_id
			 WHERE a.id = %d",
			$approval_id
		)
	);
	if ( 'completed' === $proj_status ) {
		return new WP_Error(
			'project_locked',
			__( 'This project is complete — approvals can no longer be responded to.', 'clientoctopus' ),
			[ 'status' => 422 ]
		);
	}

	$result = ClientOctopus_Approval::respond( $approval_id, $client_id, $status, $comment );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'approval' => $result ], 200 );
}
