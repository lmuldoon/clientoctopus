<?php
/**
 * REST API: Client-Facing Proposal Endpoints
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Routes:
 *   GET  /client/proposals/{token}         — fetch proposal by public token
 *   POST /client/proposals/{token}/view    — log a view event
 *   POST /client/proposals/{token}/accept  — client accepts proposal
 *   POST /client/proposals/{token}/decline — client declines proposal
 *
 * These routes require NO WordPress authentication.
 * The public token acts as the credential — treat it like a signed URL.
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
	// Load class-proposal-client.php if not already loaded.
	$path = CLIENTOCTOPUS_DIR . 'modules/proposals/class-proposal-client.php';
	if ( ! class_exists( 'ClientOctopus_Proposal_Client' ) && file_exists( $path ) ) {
		require_once $path;
	}

	$ns     = 'clientoctopus/v1';
	$token  = '(?P<token>[a-zA-Z0-9\-]+)';

	// ── GET /client/proposals/preview/{token} — must be registered before the
	// generic /{token} route so WordPress matches the more specific path first.
	register_rest_route( $ns, "/client/proposals/preview/{$token}", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_client_get_preview_proposal',
		'permission_callback' => '__return_true',
		'args'                => [
			'token' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// ── GET /client/proposals/{token} ────────────────────────────────────────
	register_rest_route( $ns, "/client/proposals/{$token}", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_client_get_proposal',
		'permission_callback' => '__return_true', // Token is the credential.
		'args'                => [
			'token' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// ── POST /client/proposals/{token}/view ──────────────────────────────────
	register_rest_route( $ns, "/client/proposals/{$token}/view", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_client_track_view',
		'permission_callback' => '__return_true',
		'args'                => [
			'token' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// ── POST /client/proposals/{token}/accept ────────────────────────────────
	register_rest_route( $ns, "/client/proposals/{$token}/accept", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_client_accept_proposal',
		'permission_callback' => '__return_true',
		'args'                => [
			'token' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// ── POST /client/proposals/{token}/decline ───────────────────────────────
	register_rest_route( $ns, "/client/proposals/{$token}/decline", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_client_decline_proposal',
		'permission_callback' => '__return_true',
		'args'                => [
			'token'  => [ 'type' => 'string', 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
			'reason' => [ 'type' => 'string', 'required' => false, 'default' => '',     'sanitize_callback' => 'sanitize_textarea_field' ],
		],
	] );

	// ── POST /client/proposals/{token}/request-change ────────────────────────
	register_rest_route( $ns, "/client/proposals/{$token}/request-change", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_client_request_change',
		'permission_callback' => '__return_true',
		'args'                => [
			'token' => [ 'type' => 'string', 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
			'note'  => [ 'type' => 'string', 'required' => false, 'default' => '',     'sanitize_callback' => 'sanitize_textarea_field' ],
		],
	] );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Route handlers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GET /clientoctopus/v1/client/proposals/preview/{token}
 *
 * Returns proposal data by preview token — same shape as the client endpoint
 * but adds is_preview:true and never tracks views or allows actions.
 */
function clientoctopus_rest_client_get_preview_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$preview_token = (string) $request->get_param( 'token' );

	if ( ! class_exists( 'ClientOctopus_Proposal' ) ) {
		$path = CLIENTOCTOPUS_DIR . 'modules/proposals/class-proposal.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	$row = ClientOctopus_Proposal::get_by_preview_token( $preview_token );

	if ( is_wp_error( $row ) ) {
		return $row;
	}

	// Whitelist the same client-safe fields used by the standard client endpoint.
	$client_fields = [
		'id', 'title', 'content', 'status', 'total_amount', 'currency',
		'payment_enabled', 'expiry_date', 'sent_at', 'viewed_at',
		'accepted_at', 'declined_at', 'created_at', 'client_name',
		'client_email', 'owner_email', 'decline_reason',
	];

	$result = array_intersect_key( $row, array_flip( $client_fields ) );

	// Disable payment on previews — no actions should be possible.
	$result['payment_enabled']   = false;
	$result['has_paid']          = false;
	$result['remaining_balance'] = 0.0;
	$result['is_preview']        = true;

	return new WP_REST_Response( [ 'proposal' => $result ], 200 );
}

/**
 * GET /clientoctopus/v1/client/proposals/{token}
 *
 * Returns the client-safe proposal data (no owner_id, no token field).
 */
function clientoctopus_rest_client_get_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$token  = (string) $request->get_param( 'token' );
	$result = ClientOctopus_Proposal_Client::get_by_token( $token );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Augment with payment status so the client view can hide the payment button
	// when a completed payment already exists for this proposal.
	$base       = CLIENTOCTOPUS_DIR . 'modules/payments/class-payment.php';
	$has_paid   = false;
	if ( ! class_exists( 'ClientOctopus_Payment' ) && file_exists( $base ) ) {
		require_once $base;
	}
	$remaining_balance = 0.0;
	if ( class_exists( 'ClientOctopus_Payment' ) ) {
		$has_paid  = ClientOctopus_Payment::has_completed_payment( (int) $result['id'] );
		$payments  = ClientOctopus_Payment::get_for_proposal( (int) $result['id'] );
		$total_paid = array_reduce( $payments, static function ( float $carry, array $pm ): float {
			return $carry + ( 'completed' === $pm['status'] ? (float) $pm['amount'] : 0.0 );
		}, 0.0 );
		$remaining_balance = max( 0.0, (float) ( $result['total_amount'] ?? 0 ) - $total_paid );
	}
	$result['has_paid']          = $has_paid;
	$result['remaining_balance'] = $remaining_balance;

	// Prevent the payment button appearing when there is nothing to charge.
	if ( (float) ( $result['total_amount'] ?? 0 ) <= 0 ) {
		$result['payment_enabled'] = false;
	}

	return new WP_REST_Response( [ 'proposal' => $result ], 200 );
}

/**
 * POST /clientoctopus/v1/client/proposals/{token}/view
 *
 * Logs a view event and transitions sent → viewed on first open.
 * Returns 200 always (best-effort tracking — clients should not see errors here).
 */
function clientoctopus_rest_client_track_view( WP_REST_Request $request ): WP_REST_Response {
	$token      = (string) $request->get_param( 'token' );
	$ip         = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR']     ?? '' ) );
	$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

	ClientOctopus_Proposal_Client::track_view( $token, $ip, $user_agent );

	return new WP_REST_Response( [ 'tracked' => true ], 200 );
}

/**
 * POST /clientoctopus/v1/client/proposals/{token}/accept
 *
 * Client accepts the proposal.
 */
function clientoctopus_rest_client_accept_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$token  = (string) $request->get_param( 'token' );
	$result = ClientOctopus_Proposal_Client::accept( $token );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'proposal' => $result ], 200 );
}

/**
 * POST /clientoctopus/v1/client/proposals/{token}/decline
 *
 * Client declines the proposal.
 */
function clientoctopus_rest_client_decline_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$token  = (string) $request->get_param( 'token' );
	$reason = (string) $request->get_param( 'reason' );
	$result = ClientOctopus_Proposal_Client::decline( $token, $reason );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'proposal' => $result ], 200 );
}

/**
 * POST /clientoctopus/v1/client/proposals/{token}/request-change
 *
 * Client requests changes — moves proposal to revision_requested status.
 */
function clientoctopus_rest_client_request_change( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$token  = (string) $request->get_param( 'token' );
	$note   = (string) $request->get_param( 'note' );
	$result = ClientOctopus_Proposal_Client::request_change( $token, $note );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'proposal' => $result ], 200 );
}
