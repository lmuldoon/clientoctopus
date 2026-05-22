<?php
/**
 * AI REST endpoints
 *
 * POST /wp-json/clientoctopus/v1/ai/process
 *   — Requires admin auth (clientoctopus_rest_require_auth)
 *   — Forwards to CF AI Relay server
 *   — Returns { result, remaining }
 *
 * POST /wp-json/clientoctopus/v1/ai/test-connection
 *   — Requires admin auth
 *   — Sends a minimal test request to the relay to verify connectivity
 *
 * @package ClientOctopus\AI
 * @since   0.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', static function (): void {

	register_rest_route(
		'clientoctopus/v1',
		'/ai/process',
		[
			'methods'             => 'POST',
			'callback'            => 'clientoctopus_rest_ai_process',
			'permission_callback' => 'clientoctopus_rest_require_manage',
			'args'                => [
				'action' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => static fn( $v ) => in_array( $v, [ 'improve', 'shorten', 'persuasive', 'generate' ], true ),
				],
				'text'  => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => '',
				],
				'brief' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => '',
				],
			],
		]
	);

	register_rest_route(
		'clientoctopus/v1',
		'/ai/test-connection',
		[
			'methods'             => 'POST',
			'callback'            => 'clientoctopus_rest_ai_test_connection',
			'permission_callback' => 'clientoctopus_rest_require_manage',
		]
	);
} );

function clientoctopus_rest_ai_process( WP_REST_Request $request ): WP_REST_Response {
	$user_id = get_current_user_id();
	$action  = $request->get_param( 'action' );
	$text    = $request->get_param( 'text' );
	$brief   = $request->get_param( 'brief' );

	$result = ClientOctopus_AI_Service::process( $user_id, $action, $text, $brief );

	if ( is_wp_error( $result ) ) {
		$status = (int) ( $result->get_error_data()['status'] ?? 500 );
		return new WP_REST_Response(
			[ 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ],
			$status
		);
	}

	return new WP_REST_Response( $result, 200 );
}

function clientoctopus_rest_ai_test_connection(): WP_REST_Response {
	$relay_key = get_option( 'clientoctopus_license_key', '' );

	if ( ! $relay_key ) {
		return new WP_REST_Response(
			[ 'success' => false, 'message' => 'Licence key is not configured. Go to Settings.' ],
			400
		);
	}

	$relay_url = untrailingslashit( CLIENTOCTOPUS_AI_RELAY_URL );

	$response = wp_remote_post(
		$relay_url . '/wp-json/co-relay/v1/process',
		[
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'relay_api_key' => $relay_key,
				'action'        => 'improve',
				'text'          => 'test',
			] ),
		]
	);

	if ( is_wp_error( $response ) ) {
		return new WP_REST_Response(
			[ 'success' => false, 'message' => 'Could not reach relay: ' . $response->get_error_message() ],
			502
		);
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	// A 401 means we reached the relay but the key is wrong.
	// A 200 or any relay-structured response means we're connected.
	if ( $code === 401 ) {
		return new WP_REST_Response(
			[ 'success' => false, 'message' => 'Relay reachable but API key is invalid or inactive.' ],
			200
		);
	}

	if ( isset( $data['success'] ) ) {
		return new WP_REST_Response(
			[ 'success' => true, 'message' => 'Connection successful.' ],
			200
		);
	}

	return new WP_REST_Response(
		[ 'success' => false, 'message' => "Relay responded with HTTP {$code}." ],
		200
	);
}
