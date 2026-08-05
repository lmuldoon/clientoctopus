<?php
/**
 * Clients REST API
 *
 * GET  /clientoctopus/v1/clients/ — list all clients for the owner
 *
 * @package ClientOctopus
 * @since   0.1.0
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- All table variables use $wpdb->prefix with hardcoded slugs, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', static function (): void {
	$ns = 'clientoctopus/v1';

	register_rest_route( $ns, '/clients/', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'clientoctopus_rest_list_clients',
			'permission_callback' => 'clientoctopus_rest_require_manage',
		],
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'clientoctopus_rest_create_client',
			'permission_callback' => 'clientoctopus_rest_require_manage',
			'args'                => [
				'name'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				'email'   => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_email' ],
				'company' => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
				'phone'   => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		],
	] );

	//@fs_premium_only
	register_rest_route( $ns, '/clients/(?P<id>\d+)/invite/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_invite_client',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true ],
		],
	] );
	//@end:fs_premium_only
} );

function clientoctopus_rest_list_clients( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$ct      = $wpdb->prefix . 'clientoctopus_clients';
	$pt      = $wpdb->prefix . 'clientoctopus_proposals';

	// Single JOIN against the latest accepted/completed proposal per client,
	// replacing three correlated subqueries that generated N×3 extra queries.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT c.*,
			        lp.title  AS latest_proposal_title,
			        lp.status AS latest_proposal_status,
			        CASE WHEN lp.status = 'completed' THEN lp.updated_at ELSE lp.accepted_at END AS latest_proposal_date
			 FROM   {$ct} c
			 LEFT JOIN {$pt} lp ON lp.id = (
			     SELECT p2.id
			     FROM   {$pt} p2
			     WHERE  p2.client_id = c.id
			       AND  p2.status IN ('accepted','completed')
			       AND  p2.deleted_at IS NULL
			     ORDER  BY p2.accepted_at DESC
			     LIMIT  1
			 )
			 WHERE  c.owner_id = %d
			 ORDER  BY c.created_at DESC",
			$user_id
		),
		ARRAY_A
	);

	$clients = array_map( static function ( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['owner_id']   = (int) $row['owner_id'];
		$row['wp_user_id'] = $row['wp_user_id'] ? (int) $row['wp_user_id'] : null;
		return $row;
	}, $rows ?: [] );

	return new WP_REST_Response( [ 'clients' => $clients ], 200 );
}

function clientoctopus_rest_create_client( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$name     = $request->get_param( 'name' );

	if ( ! $name ) {
		return new WP_Error( 'missing_name', __( 'Client name is required.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$now  = current_time( 'mysql' );
	$data = [
		'owner_id'   => $owner_id,
		'name'       => $name,
		'email'      => $request->get_param( 'email' ) ?: '',
		'company'    => $request->get_param( 'company' ) ?: '',
		'phone'      => $request->get_param( 'phone' ) ?: '',
		'created_at' => $now,
		'updated_at' => $now,
	];

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( $wpdb->prefix . 'clientoctopus_clients', $data );
	$id = $wpdb->insert_id;

	if ( ! $id ) {
		return new WP_Error( 'db_error', __( 'Could not create client.', 'clientoctopus' ), [ 'status' => 500 ] );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$client = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d", $id ),
		ARRAY_A
	);

	$client['id']         = (int) $client['id'];
	$client['owner_id']   = (int) $client['owner_id'];
	$client['wp_user_id'] = $client['wp_user_id'] ? (int) $client['wp_user_id'] : null;

	return new WP_REST_Response( [ 'client' => $client ], 201 );
}

//@fs_premium_only
function clientoctopus_rest_invite_client( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$client_id = (int) $request->get_param( 'id' );

	if ( ! clientoctopus_rest_rate_limit( 'invite_client', $user_id, 20 ) ) {
		return new WP_Error( 'rate_limited', __( 'Too many requests. Please wait a moment.', 'clientoctopus' ), [ 'status' => 429 ] );
	}

	// Gate: owner must have portal access.
	if ( ! clientoctopus_can_user( $user_id, 'use_portal' ) ) {
		return new WP_Error(
			'plan_required',
			__( 'Upgrade to Pro or Agency to send portal invitations.', 'clientoctopus' ),
			[ 'status' => 403 ]
		);
	}

	$client = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d AND owner_id = %d",
			$client_id,
			$user_id
		),
		ARRAY_A
	);

	if ( ! $client ) {
		return new WP_Error( 'not_found', __( 'Client not found.', 'clientoctopus' ), [ 'status' => 404 ] );
	}

	if ( empty( $client['email'] ) ) {
		return new WP_Error( 'no_email', __( 'This client has no email address.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$raw_token = ClientOctopus_Portal_Auth::generate_magic_token( $client_id );
	ClientOctopus_Portal_Auth::send_magic_link_email( $client['email'], $client['name'] ?? '', $raw_token );

	$now = current_time( 'mysql' );
	$wpdb->update(
		$wpdb->prefix . 'clientoctopus_clients',
		[ 'portal_invited_at' => $now ],
		[ 'id' => $client_id ]
	);

	// Return fresh client row.
	$updated = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT c.*,
			        ( SELECT p.title
			          FROM   {$wpdb->prefix}clientoctopus_proposals p
			          WHERE  p.client_id = c.id AND p.status IN ('accepted','completed')
			          ORDER  BY p.accepted_at DESC LIMIT 1
			        ) AS latest_proposal_title,
			        ( SELECT p.status
			          FROM   {$wpdb->prefix}clientoctopus_proposals p
			          WHERE  p.client_id = c.id AND p.status IN ('accepted','completed')
			          ORDER  BY p.accepted_at DESC LIMIT 1
			        ) AS latest_proposal_status,
			        ( SELECT CASE WHEN p.status = 'completed' THEN p.updated_at ELSE p.accepted_at END
			          FROM   {$wpdb->prefix}clientoctopus_proposals p
			          WHERE  p.client_id = c.id AND p.status IN ('accepted','completed')
			          ORDER  BY p.accepted_at DESC LIMIT 1
			        ) AS latest_proposal_date
			 FROM   {$wpdb->prefix}clientoctopus_clients c
			 WHERE  c.id = %d",
			$client_id
		),
		ARRAY_A
	);

	if ( $updated ) {
		$updated['id']         = (int) $updated['id'];
		$updated['owner_id']   = (int) $updated['owner_id'];
		$updated['wp_user_id'] = $updated['wp_user_id'] ? (int) $updated['wp_user_id'] : null;
	}

	return new WP_REST_Response( [ 'client' => $updated ], 200 );
}
//@end:fs_premium_only
