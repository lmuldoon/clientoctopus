<?php
/**
 * Stripe API Wrapper
 *
 * All Stripe HTTP calls go through this class via WordPress's wp_remote_*
 * functions — no Composer dependency required.
 *
 * Options used:
 *   clientoctopus_stripe_secret_key      — Secret key (sk_test_… / sk_live_…)
 *   clientoctopus_stripe_publishable_key — Publishable key (pk_test_… / pk_live_…)
 *   clientoctopus_stripe_webhook_secret  — Webhook signing secret (whsec_…)
 *
 * @package ClientOctopus\Payments
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Stripe
 */
class ClientOctopus_Stripe {

	private const API_BASE    = 'https://api.stripe.com/v1/';
	private const API_VERSION = '2024-06-20';
	private const TIMEOUT     = 30;

	// ── Config helpers ────────────────────────────────────────────────────────

	public static function get_secret_key(): string {
		return (string) get_option( 'clientoctopus_stripe_secret_key', '' );
	}

	public static function get_publishable_key(): string {
		return (string) get_option( 'clientoctopus_stripe_publishable_key', '' );
	}

	public static function get_webhook_secret(): string {
		return (string) get_option( 'clientoctopus_stripe_webhook_secret', '' );
	}

	/**
	 * Is Stripe fully configured (secret key present)?
	 */
	public static function is_configured(): bool {
		return ! empty( self::get_secret_key() );
	}

	/**
	 * Returns 'live' or 'test' based on the configured secret key.
	 */
	public static function get_mode(): string {
		return str_starts_with( self::get_secret_key(), 'sk_live_' ) ? 'live' : 'test';
	}

	// ── Core HTTP request ─────────────────────────────────────────────────────

	/**
	 * Make a request to the Stripe API.
	 *
	 * @param string $method   HTTP method: 'GET' | 'POST'
	 * @param string $endpoint e.g. 'checkout/sessions'
	 * @param array  $data     Body (POST) or query params (GET).
	 *
	 * @return array|WP_Error Decoded JSON body or WP_Error on failure.
	 */
	private static function request( string $method, string $endpoint, array $data = [] ): array|WP_Error {
		$secret = self::get_secret_key();

		if ( ! $secret ) {
			return new WP_Error(
				'stripe_not_configured',
				__( 'Stripe is not configured. Please add your API keys in Client Octopus → Settings.', 'clientoctopus' ),
				[ 'status' => 500 ]
			);
		}

		$url  = self::API_BASE . ltrim( $endpoint, '/' );
		$args = [
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Authorization'  => 'Bearer ' . $secret,
				'Content-Type'   => 'application/x-www-form-urlencoded',
				'Stripe-Version' => self::API_VERSION,
			],
		];

		if ( 'POST' === $method && ! empty( $data ) ) {
			// http_build_query produces bracket notation (line_items[0][...]=val)
			// which Stripe's API accepts.
			$args['body'] = http_build_query( $data );
		} elseif ( 'GET' === $method && ! empty( $data ) ) {
			$url .= '?' . http_build_query( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'stripe_http_error',
				$response->get_error_message(),
				[ 'status' => 503 ]
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'stripe_invalid_response',
				__( 'Invalid response from Stripe.', 'clientoctopus' ),
				[ 'status' => 502 ]
			);
		}

		if ( $code >= 400 ) {
			$msg  = $body['error']['message'] ?? __( 'Stripe returned an error.', 'clientoctopus' );
			$type = $body['error']['type']    ?? 'stripe_error';
			return new WP_Error( $type, $msg, [ 'status' => $code ] );
		}

		return $body;
	}

	// ── Checkout Sessions ─────────────────────────────────────────────────────

	/**
	 * Create a Stripe Checkout Session.
	 *
	 * @param array $params See Stripe docs for checkout/sessions.
	 *
	 * @return array|WP_Error Session object or error.
	 */
	public static function create_checkout_session( array $params ): array|WP_Error {
		return self::request( 'POST', 'checkout/sessions', $params );
	}

	/**
	 * Retrieve a Stripe Checkout Session by ID.
	 *
	 * @param string $session_id Stripe session ID (cs_xxx).
	 *
	 * @return array|WP_Error
	 */
	public static function retrieve_session( string $session_id ): array|WP_Error {
		return self::request( 'GET', 'checkout/sessions/' . $session_id );
	}

	// ── Webhook signature verification ────────────────────────────────────────

	/**
	 * Verify a Stripe webhook signature.
	 *
	 * Stripe sends `Stripe-Signature: t=...,v1=...` header.
	 * We recompute HMAC-SHA256 and compare using timing-safe comparison.
	 *
	 * Rejects signatures older than 5 minutes (replay protection).
	 *
	 * @param string $payload    Raw request body (do NOT decode it first).
	 * @param string $sig_header Value of the Stripe-Signature header.
	 * @param string $secret     Webhook signing secret (whsec_…).
	 *
	 * @return bool
	 */
	public static function verify_webhook_signature(
		string $payload,
		string $sig_header,
		string $secret
	): bool {
		if ( ! $sig_header || ! $secret || ! $payload ) {
			return false;
		}

		$timestamp  = null;
		$signatures = [];

		foreach ( explode( ',', $sig_header ) as $element ) {
			$parts = explode( '=', $element, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			[ $key, $value ] = $parts;
			if ( 't' === $key )  {
				$timestamp = $value;
			}
			if ( 'v1' === $key ) {
				$signatures[] = $value;
			}
		}

		if ( null === $timestamp || empty( $signatures ) ) {
			return false;
		}

		// Reject stale signatures (older than 5 minutes).
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $payload;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}

		return false;
	}
}
