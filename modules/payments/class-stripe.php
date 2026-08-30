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
	private static function request( string $method, string $endpoint, array $data = [], string $idempotency_key = '' ): array|WP_Error {
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

		// Lets a caller guarantee a given logical charge attempt only ever
		// results in one real Stripe charge, even if request() itself gets
		// invoked twice concurrently for it (see charge_off_session()).
		if ( '' !== $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}

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
			$msg = $body['error']['message'] ?? __( 'Stripe returned an error.', 'clientoctopus' );
			// Prefer Stripe's specific `code` (e.g. 'authentication_required',
			// 'card_declined') over the coarser `type` (e.g. 'card_error') —
			// callers need the specific reason to distinguish "needs SCA
			// verification" from a genuine decline. Not every error has a
			// `code` (e.g. some api_error responses only have `type`), so fall
			// back to that, then a generic default.
			$error_code = $body['error']['code'] ?? $body['error']['type'] ?? 'stripe_error';
			return new WP_Error( $error_code, $msg, [ 'status' => $code ] );
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

	// ── Saved payment methods / off-session charging ──────────────────────────

	/**
	 * Retrieve a PaymentIntent by ID.
	 *
	 * @param string   $id     PaymentIntent ID (pi_xxx).
	 * @param string[] $expand Optional dot-paths to expand (e.g. ['payment_method']).
	 *
	 * @return array|WP_Error
	 */
	public static function retrieve_payment_intent( string $id, array $expand = [] ): array|WP_Error {
		$params = $expand ? [ 'expand' => $expand ] : [];
		return self::request( 'GET', 'payment_intents/' . $id, $params );
	}

	/**
	 * Retrieve a PaymentMethod by ID (used to read card brand/last4 for display).
	 *
	 * @param string $id PaymentMethod ID (pm_xxx).
	 *
	 * @return array|WP_Error
	 */
	public static function retrieve_payment_method( string $id ): array|WP_Error {
		return self::request( 'GET', 'payment_methods/' . $id );
	}

	/**
	 * Charge a previously-saved payment method off-session — used by
	 * recurring profiles with billing_mode = 'auto_charge'. The caller is
	 * responsible for having captured $customer_id/$payment_method_id from a
	 * prior Checkout Session created with setup_future_usage = 'off_session'.
	 *
	 * @param string $customer_id       Stripe Customer ID (cus_xxx).
	 * @param string $payment_method_id Stripe PaymentMethod ID (pm_xxx).
	 * @param int    $amount_int        Amount in the currency's smallest unit (e.g. pence).
	 * @param string $currency          Lowercase ISO 4217 currency code.
	 * @param array  $metadata          Same shape as checkout session metadata.
	 * @param string $idempotency_key   Pass a key stable per logical attempt (e.g.
	 *                                  invoice id + retry count) so a concurrent or
	 *                                  retried call for the same attempt can never
	 *                                  result in two real charges.
	 *
	 * @return array|WP_Error The PaymentIntent object on success. A decline or an
	 *                        SCA authentication_required response both come back
	 *                        as a WP_Error here — callers treat any error the same
	 *                        way (record a failed attempt, don't try to distinguish
	 *                        decline reasons for retry purposes).
	 */
	public static function charge_off_session(
		string $customer_id,
		string $payment_method_id,
		int $amount_int,
		string $currency,
		array $metadata = [],
		string $idempotency_key = ''
	): array|WP_Error {
		return self::request( 'POST', 'payment_intents', [
			'amount'         => $amount_int,
			'currency'       => $currency,
			'customer'       => $customer_id,
			'payment_method' => $payment_method_id,
			'off_session'    => 'true',
			'confirm'        => 'true',
			'metadata'       => $metadata,
		], $idempotency_key );
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
