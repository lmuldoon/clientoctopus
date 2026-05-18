<?php
/**
 * AI Service
 *
 * Sends AI processing requests to the CF AI Relay server.
 * Checks local entitlements before forwarding to the relay.
 *
 * @package ClientOctopus\AI
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClientOctopus_AI_Service {

	/**
	 * Process an AI action for the given user.
	 *
	 * @param  int    $user_id  WP user ID of the requesting admin.
	 * @param  string $action   improve|shorten|persuasive|generate
	 * @param  string $text     The text to process.
	 * @param  string $brief    Optional brief (generate action).
	 * @return array|WP_Error   { result: string, remaining: int }
	 */
	public static function process( int $user_id, string $action, string $text, string $brief = '' ): array|WP_Error {
		// ── Plan gate ─────────────────────────────────────────────────────────

		if ( ! clientoctopus_can_user( $user_id, 'use_ai' ) ) {
			return new WP_Error(
				'plan_required',
				__( 'AI writing tools require a Pro or Agency plan. Please upgrade to access this feature.', 'clientoctopus' ),
				[ 'status' => 403 ]
			);
		}

		// ── Relay config ──────────────────────────────────────────────────────

		$relay_url = untrailingslashit( CLIENTOCTOPUS_AI_RELAY_URL );
		$relay_key = get_option( 'clientoctopus_license_key', '' );

		if ( ! $relay_key ) {
			return new WP_Error(
				'relay_not_configured',
				__( 'AI is not configured. Please add your licence key in Settings.', 'clientoctopus' ),
				[ 'status' => 503 ]
			);
		}

		// ── Call relay ────────────────────────────────────────────────────────

		$body = wp_json_encode( [
			'relay_api_key' => $relay_key,
			'action'        => $action,
			'text'          => $text,
			'brief'         => $brief,
		] );

		$response = wp_remote_post(
			$relay_url . '/wp-json/co-relay/v1/process',
			[
				'timeout' => 35,
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'relay_unreachable',
				__( 'Could not reach the AI relay server. Please try again.', 'clientoctopus' ),
				[ 'status' => 502 ]
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $data['success'] ) ) {
			return new WP_Error( 'relay_invalid_response', __( 'Invalid response from relay server.', 'clientoctopus' ), [ 'status' => 502 ] );
		}

		if ( ! $data['success'] ) {
			$relay_code = $data['code'] ?? 'relay_error';
			$relay_msg  = $data['message'] ?? __( 'AI service error. Please try again.', 'clientoctopus' );
			$http       = ( 'quota_exceeded' === $relay_code || 'rate_limited' === $relay_code ) ? 429 : 502;

			return new WP_Error( $relay_code, $relay_msg, [ 'status' => $http ] );
		}

		// ── Log usage locally ─────────────────────────────────────────────────

		if ( class_exists( 'ClientOctopus_Entitlements' ) ) {
			ClientOctopus_Entitlements::log_usage( $user_id, 'use_ai', [
				'action'      => $action,
				'tokens_used' => $data['tokens_used'] ?? 0,
			] );
		}

		return [
			'result'    => $data['result'],
			'remaining' => $data['remaining_requests'] ?? 0,
		];
	}
}
