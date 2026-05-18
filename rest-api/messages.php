<?php
/**
 * REST API: Message Endpoints
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Admin routes (authenticated WordPress users):
 *   GET    /projects/{id}/messages          — list messages (marks client msgs read)
 *   POST   /projects/{id}/messages          — send a message
 *   DELETE /projects/{id}/messages/{mid}    — delete a message
 *   GET    /messages/unread-count           — total unread count across all projects
 *
 * Portal routes (authenticated portal / clientoctopus_client users):
 *   GET    /portal/projects/{id}/messages   — list messages (marks admin msgs read)
 *   POST   /portal/projects/{id}/messages   — send a message as client
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

	// Ensure classes are loaded.
	$msg_path = CLIENTOCTOPUS_DIR . 'modules/messaging/class-message.php';
	if ( ! class_exists( 'ClientOctopus_Message' ) && file_exists( $msg_path ) ) {
		require_once $msg_path;
	}

	if ( ! class_exists( 'ClientOctopus_Portal_Auth' ) ) {
		$p = CLIENTOCTOPUS_DIR . 'modules/portal/class-portal-auth.php';
		if ( file_exists( $p ) ) {
			require_once $p;
		}
	}

	$ns      = 'clientoctopus/v1';
	$proj_id = '(?P<id>\d+)';
	$msg_id  = '(?P<mid>\d+)';

	// ── Admin: list ──────────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/messages", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_list_messages',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── Admin: send ──────────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/messages", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_send_message',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'id'      => [ 'type' => 'integer', 'required' => true ],
			'message' => [ 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
		],
	] );

	// ── Admin: delete ────────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/messages/{$msg_id}", [
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'clientoctopus_rest_delete_message',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'id'  => [ 'type' => 'integer', 'required' => true ],
			'mid' => [ 'type' => 'integer', 'required' => true ],
		],
	] );

	// ── Admin: global unread count ───────────────────────────────────────────
	register_rest_route( $ns, '/messages/unread-count', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_messages_unread_count',
		'permission_callback' => 'clientoctopus_rest_require_auth',
	] );

	// ── Portal: list ─────────────────────────────────────────────────────────
	register_rest_route( $ns, "/portal/projects/{$proj_id}/messages", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_rest_list_messages',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── Portal: send ─────────────────────────────────────────────────────────
	register_rest_route( $ns, "/portal/projects/{$proj_id}/messages", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_portal_rest_send_message',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [
			'id'      => [ 'type' => 'integer', 'required' => true ],
			'message' => [ 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
		],
	] );
} );

// ── Admin handlers ────────────────────────────────────────────────────────────

function clientoctopus_rest_list_messages( WP_REST_Request $request ): WP_REST_Response {
	$owner_id   = get_current_user_id();
	$project_id = (int) $request->get_param( 'id' );
	$result     = ClientOctopus_Message::list_for_admin( $project_id, $owner_id );

	return new WP_REST_Response( $result, 200 );
}

function clientoctopus_rest_send_message( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id   = get_current_user_id();
	$project_id = (int) $request->get_param( 'id' );
	$text       = (string) $request->get_param( 'message' );

	$message_id = ClientOctopus_Message::send( $project_id, $owner_id, 'admin', $text, $owner_id );

	if ( is_wp_error( $message_id ) ) {
		return $message_id;
	}

	// Return full updated list so client state stays in sync.
	$result = ClientOctopus_Message::list_for_admin( $project_id, $owner_id );

	return new WP_REST_Response( $result, 201 );
}

function clientoctopus_rest_delete_message( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id   = get_current_user_id();
	$message_id = (int) $request->get_param( 'mid' );
	$result     = ClientOctopus_Message::delete( $message_id, $owner_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'deleted' => true ], 200 );
}

function clientoctopus_rest_messages_unread_count( WP_REST_Request $request ): WP_REST_Response {
	$owner_id = get_current_user_id();
	$count    = ClientOctopus_Message::unread_count_admin( $owner_id );

	return new WP_REST_Response( [ 'count' => $count ], 200 );
}

// ── Portal handlers ───────────────────────────────────────────────────────────

function clientoctopus_portal_rest_list_messages( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$client_id  = get_current_user_id();
	$project_id = (int) $request->get_param( 'id' );
	$result     = ClientOctopus_Message::list_for_client( $project_id, $client_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( $result, 200 );
}

function clientoctopus_portal_rest_send_message( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$client_id  = get_current_user_id();
	$project_id = (int) $request->get_param( 'id' );
	$text       = (string) $request->get_param( 'message' );

	$message_id = ClientOctopus_Message::send( $project_id, $client_id, 'client', $text );

	if ( is_wp_error( $message_id ) ) {
		return $message_id;
	}

	$result = ClientOctopus_Message::list_for_client( $project_id, $client_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( $result, 201 );
}
