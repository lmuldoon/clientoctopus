<?php
/**
 * REST API: File Endpoints
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Admin routes (authenticated WordPress users):
 *   GET    /projects/{id}/files              — list files for a project
 *   POST   /projects/{id}/files              — upload a file (multipart/form-data)
 *   GET    /projects/{id}/files/{fid}/download — stream/download a file
 *   DELETE /projects/{id}/files/{fid}        — delete a file
 *
 * Portal routes (authenticated portal / clientoctopus_client users):
 *   GET    /portal/projects/{id}/files              — list files (client)
 *   GET    /portal/projects/{id}/files/{fid}/download — download a file (client)
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
	$path = CLIENTOCTOPUS_DIR . 'modules/files/class-file.php';
	if ( ! class_exists( 'ClientOctopus_File' ) && file_exists( $path ) ) {
		require_once $path;
	}

	// Also ensure portal auth is available.
	if ( ! class_exists( 'ClientOctopus_Portal_Auth' ) ) {
		$p = CLIENTOCTOPUS_DIR . 'modules/portal/class-portal-auth.php';
		if ( file_exists( $p ) ) {
			require_once $p;
		}
	}

	$ns      = 'clientoctopus/v1';
	$proj_id = '(?P<id>\d+)';
	$file_id = '(?P<fid>\d+)';

	// ── Admin: list ──────────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/files", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_list_files',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── Admin: upload ────────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/files", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_upload_file',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── Admin: download ──────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/files/{$file_id}/download", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_download_file',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id'  => [ 'type' => 'integer', 'required' => true ],
			'fid' => [ 'type' => 'integer', 'required' => true ],
		],
	] );

	// ── Admin: delete ────────────────────────────────────────────────────────
	register_rest_route( $ns, "/projects/{$proj_id}/files/{$file_id}", [
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'clientoctopus_rest_delete_file',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id'  => [ 'type' => 'integer', 'required' => true ],
			'fid' => [ 'type' => 'integer', 'required' => true ],
		],
	] );

	// ── Portal: list ─────────────────────────────────────────────────────────
	register_rest_route( $ns, "/portal/projects/{$proj_id}/files", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_rest_list_files',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── Portal: download ─────────────────────────────────────────────────────
	register_rest_route( $ns, "/portal/projects/{$proj_id}/files/{$file_id}/download", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_rest_download_file',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [
			'id'  => [ 'type' => 'integer', 'required' => true ],
			'fid' => [ 'type' => 'integer', 'required' => true ],
		],
	] );
} );

// ── Admin handlers ────────────────────────────────────────────────────────────

function clientoctopus_rest_list_files( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id   = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );
	$files      = ClientOctopus_File::list( $project_id, $owner_id );

	return new WP_REST_Response( [ 'files' => $files ], 200 );
}

function clientoctopus_rest_upload_file( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id   = clientoctopus_get_owner_id( get_current_user_id() );
	$project_id = (int) $request->get_param( 'id' );

	// REST endpoint — authenticated via permission_callback (no nonce required for REST API).
	if ( empty( $_FILES['file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST endpoint; auth handled by permission_callback above.
		return new WP_Error( 'no_file', __( 'No file provided.', 'clientoctopus' ), [ 'status' => 400 ] );
	}

	// $_FILES is passed directly to wp_handle_upload() which performs all file
	// type validation and sanitization internally — no additional sanitization needed.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REST endpoint; wp_handle_upload() validates and sanitizes $_FILES.
	$result = ClientOctopus_File::upload( $project_id, $owner_id, $_FILES['file'] );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$files = ClientOctopus_File::list( $project_id, $owner_id );

	return new WP_REST_Response( [ 'files' => $files ], 201 );
}

function clientoctopus_rest_download_file( WP_REST_Request $request ): void {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$file_id  = (int) $request->get_param( 'fid' );

	ClientOctopus_File::stream( $file_id, $owner_id );
}

function clientoctopus_rest_delete_file( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$file_id  = (int) $request->get_param( 'fid' );
	$result   = ClientOctopus_File::delete( $file_id, $owner_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'deleted' => true ], 200 );
}

// ── Portal handlers ───────────────────────────────────────────────────────────

function clientoctopus_portal_rest_list_files( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$email      = ClientOctopus_Portal_Auth::get_current_email();
	$project_id = (int) $request->get_param( 'id' );
	$result     = ClientOctopus_File::get_for_client_by_email( $project_id, $email );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'files' => $result ], 200 );
}

function clientoctopus_portal_rest_download_file( WP_REST_Request $request ): void {
	$email      = ClientOctopus_Portal_Auth::get_current_email();
	$project_id = (int) $request->get_param( 'id' );
	$file_id    = (int) $request->get_param( 'fid' );

	ClientOctopus_File::stream_for_client_by_email( $file_id, $email, $project_id );
}
