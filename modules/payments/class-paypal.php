<?php
/**
 * PayPal API Wrapper
 *
 * All PayPal HTTP calls go through this class via WordPress's wp_remote_*
 * functions — no Composer dependency required (mirrors class-stripe.php).
 *
 * Uses the PayPal Orders API v2 with a full-page-redirect checkout flow
 * (create order → redirect to the buyer's `payer-action` approval URL →
 * PayPal redirects back → we call capture_order() ourselves), NOT PayPal's
 * client-side JS SDK/embedded buttons — this mirrors how Stripe Checkout
 * Sessions already work in this plugin, so no PayPal JS SDK is needed.
 *
 * Options used:
 *   clientoctopus_paypal_client_id     — PayPal REST app Client ID
 *   clientoctopus_paypal_client_secret — PayPal REST app Client Secret
 *   clientoctopus_paypal_webhook_id    — Registered webhook's ID (not a shared secret)
 *   clientoctopus_paypal_mode          — 'sandbox' | 'live'
 *
 * @package ClientOctopus\Payments
 * @since   1.2.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_PayPal
 */
class ClientOctopus_PayPal {

	private const API_BASE_SANDBOX = 'https://api-m.sandbox.paypal.com';
	private const API_BASE_LIVE    = 'https://api-m.paypal.com';
	private const TIMEOUT          = 30;

	// ── Config helpers ────────────────────────────────────────────────────────

	public static function get_client_id(): string {
		return (string) get_option( 'clientoctopus_paypal_client_id', '' );
	}

	public static function get_client_secret(): string {
		return (string) get_option( 'clientoctopus_paypal_client_secret', '' );
	}

	public static function get_webhook_id(): string {
		return (string) get_option( 'clientoctopus_paypal_webhook_id', '' );
	}

	/**
	 * Is PayPal fully configured (client ID + secret present)?
	 */
	public static function is_configured(): bool {
		return '' !== self::get_client_id() && '' !== self::get_client_secret();
	}

	/**
	 * Returns 'live' or 'sandbox'. Defaults to 'sandbox' — safer than assuming live.
	 */
	public static function get_mode(): string {
		$mode = (string) get_option( 'clientoctopus_paypal_mode', 'sandbox' );
		return 'live' === $mode ? 'live' : 'sandbox';
	}

	private static function get_api_base(): string {
		return 'live' === self::get_mode() ? self::API_BASE_LIVE : self::API_BASE_SANDBOX;
	}

	// ── OAuth2 access token ───────────────────────────────────────────────────

	/**
	 * Get a cached (or freshly obtained) OAuth2 access token.
	 *
	 * POST {base}/v1/oauth2/token, HTTP Basic auth (client_id:client_secret),
	 * body grant_type=client_credentials. Cached as a transient for
	 * (expires_in - 60) seconds to avoid a round-trip on every API call.
	 *
	 * @return string|WP_Error
	 */
	private static function get_access_token(): string|WP_Error {
		$mode          = self::get_mode();
		$transient_key = 'clientoctopus_paypal_token_' . $mode;

		$cached = get_transient( $transient_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$client_id     = self::get_client_id();
		$client_secret = self::get_client_secret();

		if ( ! $client_id || ! $client_secret ) {
			return new WP_Error(
				'paypal_not_configured',
				__( 'PayPal is not configured. Please add your API credentials in Client Octopus → Settings.', 'clientoctopus' ),
				[ 'status' => 500 ]
			);
		}

		$response = wp_remote_post( self::get_api_base() . '/v1/oauth2/token', [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required by PayPal's OAuth2 Basic-auth spec, not obfuscation.
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => [ 'grant_type' => 'client_credentials' ],
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'paypal_http_error', $response->get_error_message(), [ 'status' => 503 ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$msg = is_array( $body ) ? ( $body['error_description'] ?? '' ) : '';
			return new WP_Error(
				'paypal_auth_failed',
				$msg ?: __( 'Failed to authenticate with PayPal. Please check your API credentials.', 'clientoctopus' ),
				[ 'status' => 502 ]
			);
		}

		$token      = (string) $body['access_token'];
		$expires_in = (int) ( $body['expires_in'] ?? 300 );
		set_transient( $transient_key, $token, max( 60, $expires_in - 60 ) );

		return $token;
	}

	// ── Core HTTP request ─────────────────────────────────────────────────────

	/**
	 * Make an authenticated request to the PayPal API.
	 *
	 * @param string     $method   HTTP method: 'GET' | 'POST'
	 * @param string     $endpoint e.g. 'v2/checkout/orders'
	 * @param array|null $body     JSON body (POST only).
	 *
	 * @return array|WP_Error Decoded JSON body or WP_Error on failure.
	 */
	private static function request( string $method, string $endpoint, ?array $body = null ): array|WP_Error {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = [
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
		];

		if ( 'POST' === $method ) {
			// PHP can't distinguish an empty array from an empty object — wp_json_encode( [] )
			// produces the JSON array "[]", but endpoints like capture_order (called with no
			// body) require an empty JSON *object* "{}" and reject "[]" as a malformed request.
			$args['body'] = wp_json_encode( null !== $body ? $body : new stdClass() );
		}

		$response = wp_remote_request( self::get_api_base() . '/' . ltrim( $endpoint, '/' ), $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'paypal_http_error', $response->get_error_message(), [ 'status' => 503 ] );
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$decoded_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded_body ) ) {
			$decoded_body = [];
		}

		if ( $code >= 400 ) {
			$msg  = $decoded_body['message'] ?? __( 'PayPal returned an error.', 'clientoctopus' );
			$name = $decoded_body['name']    ?? 'paypal_error';
			return new WP_Error( $name, $msg, [ 'status' => $code, 'details' => $decoded_body['details'] ?? [] ] );
		}

		return $decoded_body;
	}

	// ── Orders ────────────────────────────────────────────────────────────────

	/**
	 * Create a PayPal order.
	 *
	 * @param array $params Full Orders API v2 request body — e.g.
	 *                      { intent: 'CAPTURE', purchase_units: [...], payment_source: {...} }.
	 *
	 * @return array|WP_Error Order object (including its `links` array — find the
	 *                        `payer-action` rel for the buyer approval URL) or error.
	 */
	public static function create_order( array $params ): array|WP_Error {
		return self::request( 'POST', 'v2/checkout/orders', $params );
	}

	/**
	 * Capture a previously-approved order — this is the step that actually
	 * finalizes the charge (PayPal, unlike Stripe Checkout, does not do this
	 * automatically on the hosted page).
	 *
	 * @param string $order_id
	 *
	 * @return array|WP_Error Capture result — on success, status is 'COMPLETED'
	 *                        and the capture id is at
	 *                        purchase_units[0].payments.captures[0].id.
	 */
	public static function capture_order( string $order_id ): array|WP_Error {
		return self::request( 'POST', 'v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture' );
	}

	/**
	 * Retrieve an order's current status (mirrors Stripe's retrieve_session).
	 *
	 * @param string $order_id
	 *
	 * @return array|WP_Error
	 */
	public static function get_order( string $order_id ): array|WP_Error {
		return self::request( 'GET', 'v2/checkout/orders/' . rawurlencode( $order_id ) );
	}

	// ── Webhook signature verification ────────────────────────────────────────

	/**
	 * Verify a PayPal webhook via PayPal's own verify-webhook-signature API
	 * (POST /v1/notifications/verify-webhook-signature) — PayPal does the
	 * cryptographic/cert-chain verification for us; we just forward the
	 * required transmission headers, our webhook ID, and the raw event body.
	 *
	 * @param array  $headers    Incoming request headers (case-insensitive lookup
	 *                           expected: paypal-transmission-id, paypal-transmission-time,
	 *                           paypal-cert-url, paypal-auth-algo, paypal-transmission-sig).
	 * @param string $raw_body   Raw request body, decoded to the webhook_event object.
	 * @param string $webhook_id Registered webhook ID (clientoctopus_paypal_webhook_id).
	 *
	 * @return bool
	 */
	public static function verify_webhook_signature( array $headers, string $raw_body, string $webhook_id ): bool {
		if ( ! $webhook_id || ! $raw_body ) {
			return false;
		}

		// WP_REST_Request::get_headers() normalizes header names to lowercase with
		// underscores (e.g. "PayPal-Transmission-Id" -> "paypal_transmission_id"),
		// so compare with dashes normalized to underscores on both sides.
		$get = static function ( array $headers, string $name ): string {
			$name = str_replace( '-', '_', strtolower( $name ) );
			foreach ( $headers as $key => $value ) {
				if ( str_replace( '-', '_', strtolower( (string) $key ) ) === $name ) {
					return is_array( $value ) ? (string) ( $value[0] ?? '' ) : (string) $value;
				}
			}
			return '';
		};

		$webhook_event = json_decode( $raw_body, true );
		if ( ! is_array( $webhook_event ) ) {
			return false;
		}

		$verify_body = [
			'transmission_id'   => $get( $headers, 'paypal-transmission-id' ),
			'transmission_time' => $get( $headers, 'paypal-transmission-time' ),
			'cert_url'          => $get( $headers, 'paypal-cert-url' ),
			'auth_algo'         => $get( $headers, 'paypal-auth-algo' ),
			'transmission_sig'  => $get( $headers, 'paypal-transmission-sig' ),
			'webhook_id'        => $webhook_id,
			'webhook_event'     => $webhook_event,
		];

		if ( ! $verify_body['transmission_id'] || ! $verify_body['transmission_sig'] ) {
			return false;
		}

		$result = self::request( 'POST', 'v1/notifications/verify-webhook-signature', $verify_body );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return 'SUCCESS' === ( $result['verification_status'] ?? '' );
	}
}
