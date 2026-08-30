<?php
/**
 * REST API: Payment Endpoints
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Routes:
 *   POST /payments/create-session  — create Stripe Checkout Session (token auth)
 *   GET  /payments/status          — check payment status by session_id + token
 *   POST /payments/webhook         — Stripe webhook (signature verification only)
 *
 * The first two routes use the proposal token for identity — no WP session
 * required. This is safe because the token is a UUID4 that is not guessable.
 *
 * @package ClientOctopus
 * @since   0.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Cron callback for deferred testimonial email — scheduled 60s after payment
// to avoid SMTP burst-delivery failures when multiple emails fire in one request.
add_action( 'clientoctopus_send_testimonial_email', 'clientoctopus_payments_send_scheduled_testimonial', 10, 2 );

function clientoctopus_payments_send_scheduled_testimonial( array $context, int $owner_id ): void {
	if ( function_exists( 'clientoctopus_maybe_send_testimonial_email' ) ) {
		clientoctopus_maybe_send_testimonial_email( $context, $owner_id );
	}
}

add_action( 'rest_api_init', static function (): void {
	// Load payment module classes if not already autoloaded.
	$base = CLIENTOCTOPUS_DIR . 'modules/payments/';
	foreach ( [
		'functions.php'     => null,
		'class-stripe.php'  => 'ClientOctopus_Stripe',
		'class-paypal.php'  => 'ClientOctopus_PayPal',
		'class-payment.php' => 'ClientOctopus_Payment',
	] as $file => $class ) {
		if ( ( null === $class || ! class_exists( $class ) ) && file_exists( $base . $file ) ) {
			require_once $base . $file;
		}
	}

	// Load ClientOctopus_Proposal_Client for token lookups.
	if ( ! class_exists( 'ClientOctopus_Proposal_Client' ) ) {
		$path = CLIENTOCTOPUS_DIR . 'modules/proposals/class-proposal-client.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	$ns = 'clientoctopus/v1';

	// ── POST /payments/create-session ─────────────────────────────────────────
	register_rest_route( $ns, '/payments/create-session/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_payment_create_session',
		'permission_callback' => '__return_true', // Token-based auth in handler.
		'args'                => [
			'token' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		],
	] );

	// ── GET /payments/status ──────────────────────────────────────────────────
	register_rest_route( $ns, '/payments/status/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_payment_status',
		'permission_callback' => '__return_true', // Public endpoint; gateway id validated in handler.
		'args'                => [
			'session_id' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'provider' => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'stripe',
			],
			'token' => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			],
		],
	] );

	// ── POST /payments/webhook ────────────────────────────────────────────────
	register_rest_route( $ns, '/payments/webhook/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_payment_webhook',
		'permission_callback' => '__return_true', // Stripe signature check inside.
	] );

	// ── POST /payments/paypal/webhook ─────────────────────────────────────────
	register_rest_route( $ns, '/payments/paypal/webhook/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_payment_paypal_webhook',
		'permission_callback' => '__return_true', // PayPal signature check inside.
	] );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Handlers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * POST /clientoctopus/v1/payments/create-session
 *
 * Creates a Stripe Checkout Session for a proposal and returns the URL to
 * redirect the client to Stripe's hosted payment page.
 */
function clientoctopus_rest_payment_create_session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$token = (string) $request->get_param( 'token' );

	// ── Validate token + get proposal ────────────────────────────────────────
	$proposal = ClientOctopus_Proposal_Client::get_by_token( $token );

	if ( is_wp_error( $proposal ) ) {
		return $proposal;
	}

	// ── Check payment is enabled ─────────────────────────────────────────────
	if ( ! $proposal['payment_enabled'] ) {
		return new WP_Error(
			'payment_not_enabled',
			__( 'Payment is not enabled for this proposal.', 'clientoctopus' ),
			[ 'status' => 403 ]
		);
	}

	// ── Check status allows payment ──────────────────────────────────────────
	// Package Selector proposals must be accepted (i.e. a tier/add-on selection
	// has already been resolved into total_amount) before a checkout session can
	// be created — otherwise a client could pay before, or instead of, choosing
	// a tier, and clientoctopus_handle_payment_complete() would then retroactively
	// mark the proposal accepted with no selection ever having been made.
	$proposal_content = is_array( $proposal['content'] ) ? $proposal['content'] : [];
	$is_package_mode  = ( $proposal_content['pricing_mode'] ?? 'flat' ) === 'packages';
	$payable          = $is_package_mode
		? [ 'accepted', 'completed' ]
		: [ 'accepted', 'draft', 'sent', 'viewed', 'completed' ];
	if ( ! in_array( $proposal['status'], $payable, true ) ) {
		return new WP_Error(
			'invalid_proposal_status',
			__( 'This proposal cannot be paid at its current status.', 'clientoctopus' ),
			[ 'status' => 422 ]
		);
	}

	// ── Guard: active gateway configured? ────────────────────────────────────
	$provider = 'paypal' === get_option( 'clientoctopus_payment_provider', 'stripe' ) ? 'paypal' : 'stripe';
	$gateway_configured = 'paypal' === $provider ? ClientOctopus_PayPal::is_configured() : ClientOctopus_Stripe::is_configured();
	if ( ! $gateway_configured ) {
		return new WP_Error(
			'payment_not_configured',
			__( 'Payment is not available. Please contact the site administrator.', 'clientoctopus' ),
			[ 'status' => 503 ]
		);
	}

	// ── Calculate charge amount ──────────────────────────────────────────────
	$total           = (float) ( $proposal['total_amount'] ?? 0 );
	$content         = $proposal_content;
	$require_deposit = ! empty( $content['require_deposit'] );
	$deposit_pct_raw = (int) ( $content['deposit_pct'] ?? 0 );
	$deposit_pct     = ( $require_deposit && $deposit_pct_raw > 0 )
		? min( 100, $deposit_pct_raw )
		: 100;
	$charge          = round( $total * ( $deposit_pct / 100 ), 2 );

	// If payments have already been made, charge only the remaining balance.
	$existing_payments = ClientOctopus_Payment::get_for_proposal( (int) $proposal['id'] );
	$total_paid        = array_reduce( $existing_payments, static function ( float $carry, array $pm ): float {
		return $carry + ( 'completed' === $pm['status'] ? (float) $pm['amount'] : 0.0 );
	}, 0.0 );

	$is_balance_payment = $total_paid > 0.0;
	if ( $is_balance_payment ) {
		$remaining = round( $total - $total_paid, 2 );
		if ( $remaining <= 0 ) {
			return new WP_Error(
				'already_paid',
				__( 'This proposal has already been paid in full.', 'clientoctopus' ),
				[ 'status' => 422 ]
			);
		}
		$charge      = $remaining;
		$deposit_pct = 100; // Treat the balance payment as a full-amount charge.
	}

	if ( $charge <= 0 ) {
		return new WP_Error(
			'invalid_amount',
			__( 'Proposal total amount is not set. Please contact us.', 'clientoctopus' ),
			[ 'status' => 422 ]
		);
	}

	$currency   = strtolower( $proposal['currency'] ?? 'gbp' );
	$amount_int = (int) round( $charge * 100 ); // Convert to smallest unit (pence/cents).

	// Guard against the gateway's per-currency minimums (30p for GBP, 50¢ for USD, etc.).
	$min_amount = clientoctopus_min_charge_amount( $currency, $provider );
	if ( $amount_int < $min_amount ) {
		return new WP_Error(
			'amount_too_low',
			sprintf(
				/* translators: 1: formatted amount, 2: currency */
				__( 'The payment amount (%1$s %2$s) is below the minimum allowed. Please increase the proposal value.', 'clientoctopus' ),
				number_format( $charge, 2 ),
				strtoupper( $currency )
			),
			[ 'status' => 422 ]
		);
	}

	$deposit_note = $is_balance_payment
		? __( ' (remaining balance)', 'clientoctopus' )
		: ( $deposit_pct < 100 ? sprintf( ' (%d%% deposit)', $deposit_pct ) : '' );

	$cancel_url = site_url( '/proposals/' . $token . '/cancel' );
	$metadata   = [
		'proposal_id' => $proposal['id'],
		'token'       => $token,
		'deposit_pct' => $deposit_pct,
	];

	if ( 'paypal' === $provider ) {
		// PayPal always appends its own `token` (order id) + `PayerID` query params to
		// whatever return_url we supply — deliberately no pre-existing query string here,
		// so there's no risk of PayPal producing a malformed `?a=b?c=d` URL; client-routing.php
		// detects PayPal purely from the presence of its own `token`/`PayerID` params.
		$success_url = site_url( '/proposals/' . $token . '/success' );

		$order = ClientOctopus_PayPal::create_order( [
			'intent'          => 'CAPTURE',
			'purchase_units'  => [
				[
					'amount'    => [
						'currency_code' => strtoupper( $currency ),
						'value'         => number_format( $charge, 2, '.', '' ),
					],
					'custom_id' => wp_json_encode( $metadata ),
				],
			],
			'payment_source'  => [
				'paypal' => [
					'experience_context' => [
						'return_url' => $success_url,
						'cancel_url' => $cancel_url,
					],
				],
			],
		] );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$approval_url = '';
		foreach ( (array) ( $order['links'] ?? [] ) as $link ) {
			if ( 'payer-action' === ( $link['rel'] ?? '' ) ) {
				$approval_url = (string) $link['href'];
				break;
			}
		}

		if ( ! $approval_url ) {
			return new WP_Error( 'paypal_order_error', __( 'PayPal did not return an approval link.', 'clientoctopus' ), [ 'status' => 502 ] );
		}

		$gateway_id = (string) ( $order['id'] ?? '' );
		$checkout_url = $approval_url;
	} else {
		$success_url = site_url( '/proposals/' . $token . '/success' ) . '?session_id={CHECKOUT_SESSION_ID}';

		$session = ClientOctopus_Stripe::create_checkout_session( [
			'mode'                 => 'payment',
			'payment_method_types' => [ 'card' ],
			'line_items'           => [
				[
					'price_data' => [
						'currency'     => $currency,
						'product_data' => [
							'name' => ( $proposal['title'] ?? __( 'Proposal', 'clientoctopus' ) ) . $deposit_note,
						],
						'unit_amount'  => $amount_int,
					],
					'quantity'   => 1,
				],
			],
			'success_url'          => $success_url,
			'cancel_url'           => $cancel_url,
			'metadata'             => $metadata,
			'payment_intent_data'  => [ 'metadata' => $metadata ],
		] );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$gateway_id   = (string) $session['id'];
		$checkout_url = (string) $session['url'];
	}

	// ── Persist pending payment record ───────────────────────────────────────
	// Look up the owner_id from the raw proposal table (not available in client response).
	global $wpdb;
	$owner_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT owner_id FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d",
			$proposal['id']
		)
	);

	ClientOctopus_Payment::create( $proposal['id'], $owner_id, [
		'amount'      => $charge,
		'currency'    => strtoupper( $currency ),
		'deposit_pct' => $deposit_pct,
		'provider'    => $provider,
		'session_id'  => $gateway_id,
		'client_id'   => $proposal['client_id'] ?? null,
	] );

	return new WP_REST_Response( [
		'checkout_url' => $checkout_url,
		'session_id'   => $gateway_id,
	], 200 );
}

/**
 * GET /clientoctopus/v1/payments/status?session_id=xxx&provider=stripe|paypal&token=xxx
 *
 * Returns the payment status. Called by the PaymentSuccess component to
 * confirm payment after the gateway's success redirect.
 */
function clientoctopus_rest_payment_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$gateway_id = (string) $request->get_param( 'session_id' );
	$provider   = 'paypal' === (string) $request->get_param( 'provider' ) ? 'paypal' : 'stripe';

	// Helper: build the standard response array from a payment record.
	$make_response = static fn( array $p ): array => [
		'status'       => $p['status'],
		'amount'       => $p['amount'],
		'currency'     => $p['currency'],
		'deposit_pct'  => $p['deposit_pct'],
		'completed_at' => $p['completed_at'] ?? null,
	];

	// Try local DB first. If the payment is already in a terminal state, return immediately.
	$payment = ClientOctopus_Payment::get_by_gateway_id( $gateway_id, $provider );
	if ( ! is_wp_error( $payment ) && in_array( $payment['status'], [ 'completed', 'failed', 'refunded' ], true ) ) {
		return new WP_REST_Response( $make_response( $payment ), 200 );
	}

	if ( 'paypal' === $provider ) {
		if ( ! ClientOctopus_PayPal::is_configured() ) {
			return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
		}

		// Read-only check first — capture_order() is a mutating call that errors if the
		// order was already captured elsewhere (e.g. by the webhook, or a concurrent poll).
		$order = ClientOctopus_PayPal::get_order( $gateway_id );
		if ( is_wp_error( $order ) ) {
			return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
		}

		if ( 'APPROVED' === ( $order['status'] ?? '' ) ) {
			$captured = ClientOctopus_PayPal::capture_order( $gateway_id );
			if ( ! is_wp_error( $captured ) ) {
				$order = $captured;
			}
		}

		if ( 'COMPLETED' === ( $order['status'] ?? '' ) ) {
			clientoctopus_handle_paypal_order_complete( $order );

			$payment = ClientOctopus_Payment::get_by_gateway_id( $gateway_id, $provider );
			if ( ! is_wp_error( $payment ) ) {
				return new WP_REST_Response( $make_response( $payment ), 200 );
			}

			return new WP_REST_Response( [ 'status' => 'completed' ], 200 );
		}

		return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
	}

	// Payment is pending (or not yet in DB) — check Stripe directly.
	if ( ! ClientOctopus_Stripe::is_configured() ) {
		return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
	}

	$stripe_session = ClientOctopus_Stripe::retrieve_session( $gateway_id );
	if ( is_wp_error( $stripe_session ) ) {
		return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
	}

	if ( 'paid' === ( $stripe_session['payment_status'] ?? '' ) ) {
		// Write-through: process fully as if the webhook had fired.
		// clientoctopus_handle_checkout_complete is idempotent — mark_complete updates by
		// session_id, and clientoctopus_proposal_accepted checks status before creating a project.
		clientoctopus_handle_checkout_complete( $stripe_session );

		$payment = ClientOctopus_Payment::get_by_gateway_id( $gateway_id, $provider );
		if ( ! is_wp_error( $payment ) ) {
			return new WP_REST_Response( $make_response( $payment ), 200 );
		}

		return new WP_REST_Response( [ 'status' => 'completed' ], 200 );
	}

	return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
}

/**
 * POST /clientoctopus/v1/payments/webhook
 *
 * Stripe webhook endpoint. Processes checkout.session.completed events.
 *
 * IMPORTANT: WordPress coerces the raw request body when it parses parameters,
 * so we must read the raw body directly via php://input before WordPress
 * processes it — the REST API fires this after parsing, but we grab raw input.
 */
function clientoctopus_rest_payment_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$payload    = $request->get_body();
	$sig_header = $request->get_header( 'stripe-signature' );
	$secret     = ClientOctopus_Stripe::get_webhook_secret();

	// ── Signature verification ────────────────────────────────────────────────
	if ( ! $secret ) {
		return new WP_Error(
			'webhook_not_configured',
			'Webhook secret is not configured.',
			[ 'status' => 403 ]
		);
	}
	if ( ! ClientOctopus_Stripe::verify_webhook_signature( $payload, $sig_header, $secret ) ) {
		return new WP_Error(
			'webhook_signature_invalid',
			__( 'Webhook signature verification failed.', 'clientoctopus' ),
			[ 'status' => 400 ]
		);
	}

	$event = json_decode( $payload, true );

	if ( ! is_array( $event ) || empty( $event['type'] ) ) {
		return new WP_Error( 'invalid_payload', 'Invalid event payload.', [ 'status' => 400 ] );
	}

	// ── Route by event type ───────────────────────────────────────────────────
	switch ( $event['type'] ) {
		case 'checkout.session.completed':
		case 'checkout.session.async_payment_succeeded':
			clientoctopus_handle_checkout_complete( $event['data']['object'] ?? [] );
			break;

		case 'checkout.session.async_payment_failed':
		case 'checkout.session.expired':
			$session = $event['data']['object'] ?? [];
			clientoctopus_handle_payment_failed( [
				'provider'   => 'stripe',
				'gateway_id' => (string) ( $session['id'] ?? '' ),
				'metadata'   => is_array( $session['metadata'] ?? null ) ? $session['metadata'] : [],
			] );
			break;

		case 'payment_intent.payment_failed':
			// A bare PaymentIntent has no Checkout Session id — mark_failed()'s
			// stripe_session_id lookup can't match it. payment_intent_data.metadata
			// (set at session creation) is copied onto the PaymentIntent itself,
			// so route on that instead of trying to resolve back to a session id.
			$intent = $event['data']['object'] ?? [];
			clientoctopus_handle_payment_failed( [
				'provider'   => 'stripe',
				'gateway_id' => '',
				'metadata'   => is_array( $intent['metadata'] ?? null ) ? $intent['metadata'] : [],
			] );
			break;
	}

	// Always return 200 — Stripe will retry on any non-2xx response.
	return new WP_REST_Response( [ 'received' => true ], 200 );
}

/**
 * POST /clientoctopus/v1/payments/paypal/webhook
 *
 * PayPal webhook endpoint. Verifies via PayPal's own verify-webhook-signature
 * REST API (see ClientOctopus_PayPal::verify_webhook_signature()), then
 * processes CHECKOUT.ORDER.APPROVED / PAYMENT.CAPTURE.COMPLETED events.
 */
function clientoctopus_rest_payment_paypal_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$payload    = $request->get_body();
	$webhook_id = ClientOctopus_PayPal::get_webhook_id();

	if ( ! $webhook_id ) {
		return new WP_Error(
			'webhook_not_configured',
			'PayPal webhook ID is not configured.',
			[ 'status' => 403 ]
		);
	}

	if ( ! ClientOctopus_PayPal::verify_webhook_signature( $request->get_headers(), $payload, $webhook_id ) ) {
		return new WP_Error(
			'webhook_signature_invalid',
			__( 'Webhook signature verification failed.', 'clientoctopus' ),
			[ 'status' => 400 ]
		);
	}

	$event = json_decode( $payload, true );

	if ( ! is_array( $event ) || empty( $event['event_type'] ) ) {
		return new WP_Error( 'invalid_payload', 'Invalid event payload.', [ 'status' => 400 ] );
	}

	// ── Route by event type ───────────────────────────────────────────────────
	switch ( $event['event_type'] ) {
		case 'CHECKOUT.ORDER.APPROVED':
			$order_id = $event['resource']['id'] ?? '';
			if ( $order_id ) {
				$order = ClientOctopus_PayPal::capture_order( $order_id );
				if ( ! is_wp_error( $order ) && 'COMPLETED' === ( $order['status'] ?? '' ) ) {
					clientoctopus_handle_paypal_order_complete( $order );
				}
			}
			break;

		case 'PAYMENT.CAPTURE.COMPLETED':
			// Order id is the capture's `supplementary_data.related_ids.order_id` — if present,
			// fetch the full order so clientoctopus_handle_paypal_order_complete() gets the same
			// shape it expects from capture_order(). Skip if this arrives from a duplicate/retry
			// after CHECKOUT.ORDER.APPROVED already handled it (idempotency guard is downstream).
			$order_id = $event['resource']['supplementary_data']['related_ids']['order_id'] ?? '';
			if ( $order_id ) {
				$order = ClientOctopus_PayPal::get_order( $order_id );
				if ( ! is_wp_error( $order ) && 'COMPLETED' === ( $order['status'] ?? '' ) ) {
					clientoctopus_handle_paypal_order_complete( $order );
				}
			}
			break;

		case 'PAYMENT.CAPTURE.DENIED':
		case 'CHECKOUT.ORDER.VOIDED':
			// CHECKOUT.ORDER.VOIDED's resource IS the order (resource.id = order id).
			// PAYMENT.CAPTURE.DENIED's resource is the capture — resource.id there is the
			// capture id, not the order id; the real order id is nested under
			// supplementary_data.related_ids.order_id. Resolve order-id-first so this
			// never silently passes a capture id to an order-id lookup.
			$order_id = $event['resource']['supplementary_data']['related_ids']['order_id']
				?? $event['resource']['id']
				?? '';
			if ( $order_id ) {
				$order    = ClientOctopus_PayPal::get_order( $order_id );
				$metadata = [];
				if ( ! is_wp_error( $order ) ) {
					$custom_id = $order['purchase_units'][0]['custom_id'] ?? '';
					$decoded   = json_decode( (string) $custom_id, true );
					$metadata  = is_array( $decoded ) ? $decoded : [];
				}
				clientoctopus_handle_payment_failed( [
					'provider'   => 'paypal',
					'gateway_id' => $order_id,
					'metadata'   => $metadata,
				] );
			}
			break;
	}

	// Always return 200 — PayPal will retry on any non-2xx response.
	return new WP_REST_Response( [ 'received' => true ], 200 );
}

/**
 * Handle checkout.session.completed event (Stripe adapter).
 *
 * Extracts Stripe-session-shaped fields into the gateway-agnostic
 * $normalized shape and hands off to clientoctopus_handle_payment_complete().
 * Existing call sites (webhook, invoice write-through) keep calling this
 * exact function, unchanged — zero behavioral risk to Stripe.
 *
 * @param array $session Stripe session object from event data.
 */
function clientoctopus_handle_checkout_complete( array $session ): void {
	$metadata = is_array( $session['metadata'] ?? null ) ? $session['metadata'] : [];

	clientoctopus_handle_payment_complete( [
		'gateway_id'     => (string) ( $session['id'] ?? '' ),
		'provider'       => 'stripe',
		'transaction_id' => (string) ( $session['payment_intent'] ?? '' ),
		'customer_id'    => $session['customer'] ?? null,
		'metadata'       => $metadata,
		'amount'         => (float) ( $session['amount_total'] ?? 0 ) / 100,
		'currency'       => strtoupper( (string) ( $session['currency'] ?? '' ) ),
	] );
}

/**
 * Handle a completed/captured PayPal order (PayPal adapter).
 *
 * Extracts PayPal-order-shaped fields (as returned by
 * ClientOctopus_PayPal::capture_order()) into the gateway-agnostic
 * $normalized shape and hands off to clientoctopus_handle_payment_complete().
 *
 * @param array $order PayPal order object (post-capture).
 */
function clientoctopus_handle_paypal_order_complete( array $order ): void {
	$purchase_unit = $order['purchase_units'][0] ?? [];

	// PayPal's "Show order details" (GET) response doesn't always echo custom_id on
	// purchase_units the way the capture response does — if we were handed an order
	// object without it (e.g. a concurrent request already captured it and we only
	// have a fresh GET), re-fetch explicitly before giving up on the metadata that
	// carries our invoice/proposal reference.
	if ( empty( $purchase_unit['custom_id'] ) && ! empty( $order['id'] ) && class_exists( 'ClientOctopus_PayPal' ) ) {
		$refetched = ClientOctopus_PayPal::get_order( (string) $order['id'] );
		if ( ! is_wp_error( $refetched ) && ! empty( $refetched['purchase_units'][0]['custom_id'] ) ) {
			$order         = $refetched;
			$purchase_unit = $order['purchase_units'][0] ?? [];
		}
	}

	$capture        = $purchase_unit['payments']['captures'][0] ?? [];
	$capture_amount = $capture['amount'] ?? ( $purchase_unit['amount'] ?? [] );

	$metadata = json_decode( (string) ( $purchase_unit['custom_id'] ?? '' ), true );
	if ( ! is_array( $metadata ) ) {
		$metadata = [];
	}

	// Present only when this order's payment_source.paypal.attributes.vault
	// requested store_in_vault (auto-charge setup) — see rest-api/invoices.php.
	$vault = $order['payment_source']['paypal']['attributes']['vault'] ?? null;
	$paypal_vault = is_array( $vault ) && ! empty( $vault['id'] ) ? [
		'id'          => (string) $vault['id'],
		'customer_id' => (string) ( $vault['customer']['id'] ?? '' ),
		'payer_email' => (string) ( $order['payer']['email_address'] ?? '' ),
	] : null;

	clientoctopus_handle_payment_complete( [
		'gateway_id'     => (string) ( $order['id'] ?? '' ),
		'provider'       => 'paypal',
		'transaction_id' => (string) ( $capture['id'] ?? '' ),
		'customer_id'    => $order['payer']['payer_id'] ?? null,
		'metadata'       => $metadata,
		'amount'         => (float) ( $capture_amount['value'] ?? 0 ),
		'currency'       => strtoupper( (string) ( $capture_amount['currency_code'] ?? '' ) ),
		'paypal_vault'   => $paypal_vault,
	] );
}

/**
 * Gateway-agnostic payment completion core.
 *
 * 1. Mark payment completed in our DB.
 * 2. Ensure proposal status is 'accepted' (in case the client paid without clicking Accept).
 * 3. Send owner notification email.
 *
 * @param array $normalized {
 *     @type string     $gateway_id     Stripe session ID or PayPal order ID.
 *     @type string     $provider       'stripe' | 'paypal'.
 *     @type string     $transaction_id Stripe PaymentIntent ID or PayPal capture ID.
 *     @type mixed      $customer_id    Stripe Customer ID or PayPal payer ID, if present.
 *     @type array      $metadata       Same shape as Stripe's session metadata
 *                                      (type, invoice_id|proposal_id, token, deposit_pct).
 *     @type float      $amount         Charge amount, in the currency's major unit.
 *     @type string     $currency       ISO 4217 currency code, uppercase.
 *     @type array|null $paypal_vault   { id, customer_id, payer_email } when this PayPal
 *                                      order requested vaulting — see clientoctopus_handle_paypal_order_complete().
 * }
 */
function clientoctopus_handle_payment_complete( array $normalized ): void {
	$gateway_id      = (string) ( $normalized['gateway_id'] ?? '' );
	$provider        = 'paypal' === ( $normalized['provider'] ?? 'stripe' ) ? 'paypal' : 'stripe';
	$transaction_id  = (string) ( $normalized['transaction_id'] ?? '' );
	$customer_id     = $normalized['customer_id'] ?? null;
	$metadata        = is_array( $normalized['metadata'] ?? null ) ? $normalized['metadata'] : [];
	$amount          = (float) ( $normalized['amount'] ?? 0 );
	$currency        = strtoupper( (string) ( $normalized['currency'] ?? '' ) );
	$proposal_id     = (int) ( $metadata['proposal_id'] ?? 0 );
	$paypal_vault    = is_array( $normalized['paypal_vault'] ?? null ) ? $normalized['paypal_vault'] : null;

	if ( ! $gateway_id ) {
		return;
	}

	// ── Invoice payment branch ────────────────────────────────────────────────
	$invoice_id = (int) ( $metadata['invoice_id'] ?? 0 );
	if ( $invoice_id && 'invoice' === ( $metadata['type'] ?? '' ) ) {
		$invoice_class = CLIENTOCTOPUS_DIR . 'modules/invoices/class-invoice.php';
		if ( ! class_exists( 'ClientOctopus_Invoice' ) && file_exists( $invoice_class ) ) {
			require_once $invoice_class;
		}
		if ( class_exists( 'ClientOctopus_Invoice' ) ) {
			ClientOctopus_Invoice::mark_paid_for_provider( $invoice_id, $gateway_id, $transaction_id, $provider );
		}
		if ( 'stripe' === $provider && $customer_id && $transaction_id ) {
			clientoctopus_capture_stripe_saved_card( (int) ( $metadata['client_id'] ?? 0 ), (string) $customer_id, $transaction_id );
		}
		if ( 'paypal' === $provider && ! empty( $paypal_vault['id'] ) ) {
			clientoctopus_capture_paypal_saved_card( (int) ( $metadata['client_id'] ?? 0 ), $paypal_vault );
		}
		return;
	}

	// ── Proposal payment branch (existing logic) ──────────────────────────────
	if ( ! $proposal_id ) {
		return;
	}

	// Idempotency guard — if this session was already processed (webhook fired twice
	// or status endpoint triggered write-through concurrently) skip all side-effects.
	$existing = ClientOctopus_Payment::get_by_gateway_id( $gateway_id, $provider );
	if ( ! is_wp_error( $existing ) && 'completed' === $existing['status'] ) {
		return;
	}

	// Mark payment complete.
	ClientOctopus_Payment::mark_complete_for_provider( $gateway_id, $transaction_id, $customer_id ?: null, $provider );
	$payment = ClientOctopus_Payment::get_by_gateway_id( $gateway_id, $provider );

	// Ensure proposal is accepted.
	global $wpdb;
	$proposal = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, owner_id, status, title, client_id, total_amount FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d",
			$proposal_id
		),
		ARRAY_A
	);

	if ( ! $proposal ) {
		return;
	}

	// Transition to 'accepted' if still in an open state, and notify modules once.
	// Firing clientoctopus_proposal_accepted on every payment would re-send the portal magic-link
	// email on every milestone/installment — it must only fire on first acceptance.
	if ( in_array( $proposal['status'], [ 'draft', 'sent', 'viewed' ], true ) ) {
		$wpdb->update(
			$wpdb->prefix . 'clientoctopus_proposals',
			[
				'status'      => 'accepted',
				'accepted_at' => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $proposal_id ]
		);
		do_action( 'clientoctopus_proposal_accepted', $proposal_id, (int) $proposal['owner_id'] );
	}
	do_action( 'clientoctopus_payment_completed', ! is_wp_error( $payment ) ? (int) $payment['id'] : 0, (int) $proposal['owner_id'] );

	// If the proposal was already marked complete before this payment arrived,
	// the testimonial check at completion time found no completed payment and
	// silently skipped. Schedule the retry now that the final payment is in.
	// Deferred 60 s to avoid goSMTP connection failures when multiple emails
	// fire in quick succession. spawn_cron() ensures the event fires via a
	// background HTTP request without requiring a future page load.
	//
	// Agency path: proposal completed via project → check for completed project.
	// Pro path: proposal completed directly (no project) → check proposal status.
	$completed_project = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}clientoctopus_projects WHERE proposal_id = %d AND status = 'completed' LIMIT 1",
			$proposal_id
		),
		ARRAY_A
	);
	if ( $completed_project ) {
		wp_schedule_single_event(
			time() + 60,
			'clientoctopus_send_testimonial_email',
			[ $completed_project, (int) $proposal['owner_id'] ]
		);
		spawn_cron();
	} else {
		$proposal_status = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d",
				$proposal_id
			)
		);
		if ( 'completed' === $proposal_status ) {
			wp_schedule_single_event(
				time() + 60,
				'clientoctopus_send_testimonial_email',
				[ [ 'proposal_id' => $proposal_id ], (int) $proposal['owner_id'] ]
			);
			spawn_cron();
		}
	}

	// Log event.
	$wpdb->insert(
		$wpdb->prefix . 'clientoctopus_events',
		[
			'proposal_id' => $proposal_id,
			'event_type'  => 'payment_completed',
			'user_ip'     => '',
			'user_agent'  => $provider . '-webhook',
			'timestamp'   => current_time( 'mysql' ),
			'metadata'    => wp_json_encode( [
				'session_id' => $gateway_id,
				'amount'     => (int) round( $amount * 100 ),
				'currency'   => strtolower( $currency ),
			] ),
		],
		[ '%d', '%s', '%s', '%s', '%s', '%s' ]
	);

	// Email owner.
	clientoctopus_notify_owner_payment_complete( (int) $proposal['owner_id'], $proposal, $amount, $currency );
}

/**
 * Send the owner an email when their proposal is paid.
 *
 * @param int    $owner_id WordPress user ID.
 * @param array  $proposal Raw proposal row.
 * @param float  $amount   Charge amount, in the currency's major unit.
 * @param string $currency ISO 4217 currency code, uppercase.
 */
function clientoctopus_notify_owner_payment_complete( int $owner_id, array $proposal, float $amount, string $currency ): void {
	global $wpdb;

	$owner = get_userdata( $owner_id );
	if ( ! $owner ) {
		return;
	}

	$currency       = $currency ?: 'GBP';
	$amount_fmt     = $currency . ' ' . number_format( $amount, 2 );
	$proposal_title = esc_html( $proposal['title'] ?? 'Proposal' );

	// Owner notification.
	/* translators: %s is the proposal title */
	$subject = sprintf( __( '💰 Payment received for "%s"', 'clientoctopus' ), sanitize_text_field( $proposal['title'] ?? 'Proposal' ) );
	wp_mail(
		$owner->user_email,
		$subject,
		clientoctopus_email_html( [
			'name'      => $owner->display_name,
			'body'      => "<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">A payment of <strong style=\"color:#1A1A2E;\">{$amount_fmt}</strong> has been received for your proposal <em>{$proposal_title}</em>.</p>",
			'cta_label' => __( 'View Proposal', 'clientoctopus' ),
			'cta_url'   => admin_url( 'admin.php?page=clientoctopus-proposals' ),
		] ),
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);

	// Client receipt email — look up the client record directly from clientoctopus_clients
	// (client_id is NOT a WordPress user ID; it references our own clients table).
	if ( ! empty( $proposal['client_id'] ) ) {
		$client_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT name, email FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d",
				(int) $proposal['client_id']
			),
			ARRAY_A
		);
		if ( $client_row && ! empty( $client_row['email'] ) ) {
			wp_mail(
				$client_row['email'],
				/* translators: %s is the proposal title */
				sprintf( __( 'Payment confirmed — %s', 'clientoctopus' ), sanitize_text_field( $proposal['title'] ?? 'Proposal' ) ),
				clientoctopus_email_html( [
					'name'      => $client_row['name'] ?? '',
					'body'      => "<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">We have received your payment of <strong style=\"color:#1A1A2E;\">{$amount_fmt}</strong> for <em>{$proposal_title}</em>. Thank you!</p><p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">You can view your payment history from your client portal.</p>",
					'cta_label' => __( 'Go to Portal', 'clientoctopus' ),
					'cta_url'   => home_url( '/clientoctopus/payments' ),
				] ),
				[ 'Content-Type: text/html; charset=UTF-8' ]
			);
		}
	}

}

/**
 * Persist the card used for an invoice payment against the client record, for
 * reuse by a recurring profile's auto-charge cycles. Only ever called when the
 * checkout session had a Stripe customer attached (i.e. customer_creation was
 * requested — which rest-api/invoices.php only does for a billing_mode =
 * 'auto_charge' recurring profile's invoices), so no extra mode check needed here.
 *
 * $client_id comes from the session's own metadata (captured at Checkout
 * Session creation time), not a fresh lookup against the invoices table —
 * an admin reassigning the invoice to a different client between session
 * creation and webhook delivery must never cause the saved card to attach
 * to the wrong (new) client instead of whoever actually paid.
 *
 * @param int    $client_id
 * @param string $customer_id   Stripe Customer ID (cus_xxx).
 * @param string $payment_intent_id PaymentIntent ID (pi_xxx) from this session.
 */
function clientoctopus_capture_stripe_saved_card( int $client_id, string $customer_id, string $payment_intent_id ): void {
	global $wpdb;

	if ( ! $client_id ) {
		return;
	}

	$intent = ClientOctopus_Stripe::retrieve_payment_intent( $payment_intent_id, [ 'payment_method' ] );
	if ( is_wp_error( $intent ) ) {
		return;
	}

	$payment_method = $intent['payment_method'] ?? null;
	if ( ! is_array( $payment_method ) ) {
		// Not expanded for some reason — fall back to a plain ID with no brand/last4.
		$pm_id    = is_string( $payment_method ) ? $payment_method : '';
		$pm_brand = null;
		$pm_last4 = null;
	} else {
		$pm_id    = (string) ( $payment_method['id'] ?? '' );
		$pm_brand = $payment_method['card']['brand'] ?? null;
		$pm_last4 = $payment_method['card']['last4'] ?? null;
	}

	if ( ! $pm_id ) {
		return;
	}

	$wpdb->update(
		$wpdb->prefix . 'clientoctopus_clients',
		[
			'stripe_customer_id'       => $customer_id,
			'stripe_payment_method_id' => $pm_id,
			'stripe_pm_brand'          => $pm_brand,
			'stripe_pm_last4'          => $pm_last4,
		],
		[ 'id' => $client_id ]
	);
}

/**
 * Persist a vaulted PayPal payment method against the client record, for
 * reuse by a recurring profile's auto-charge cycles — the PayPal counterpart
 * to clientoctopus_capture_stripe_saved_card(). $client_id comes from the
 * order's own metadata (captured at create_order() time), for the same
 * client-reassignment-race reason documented there.
 *
 * @param int   $client_id
 * @param array $vault { id, customer_id, payer_email }
 */
function clientoctopus_capture_paypal_saved_card( int $client_id, array $vault ): void {
	global $wpdb;

	if ( ! $client_id || empty( $vault['id'] ) ) {
		return;
	}

	$wpdb->update(
		$wpdb->prefix . 'clientoctopus_clients',
		[
			'paypal_vault_id'          => $vault['id'],
			'paypal_vault_customer_id' => $vault['customer_id'] ?? '',
			'paypal_payer_email'       => $vault['payer_email'] ?? '',
		],
		[ 'id' => $client_id ]
	);
}

/**
 * Gateway-agnostic payment failure core.
 *
 * Mirrors clientoctopus_handle_payment_complete()'s type-based branching, but
 * for declined/expired/voided payment attempts. Before this existed, a failed
 * invoice-type session did nothing at all (invoices never had a row in
 * clientoctopus_payments for mark_failed() to update), and a failed
 * proposal-type session updated the payments table silently with no
 * notification to anyone.
 *
 * @param array $normalized {
 *     @type string $provider   'stripe' | 'paypal'.
 *     @type string $gateway_id Stripe session ID or PayPal order ID. May be
 *                               empty for Stripe payment_intent.payment_failed,
 *                               which is routed purely on metadata instead.
 *     @type array  $metadata   Same shape as the success path (type, invoice_id|proposal_id).
 * }
 */
function clientoctopus_handle_payment_failed( array $normalized ): void {
	$provider   = 'paypal' === ( $normalized['provider'] ?? 'stripe' ) ? 'paypal' : 'stripe';
	$gateway_id = (string) ( $normalized['gateway_id'] ?? '' );
	$metadata   = is_array( $normalized['metadata'] ?? null ) ? $normalized['metadata'] : [];
	$invoice_id  = (int) ( $metadata['invoice_id'] ?? 0 );
	$proposal_id = (int) ( $metadata['proposal_id'] ?? 0 );

	global $wpdb;

	// ── Invoice payment branch ────────────────────────────────────────────────
	if ( $invoice_id && 'invoice' === ( $metadata['type'] ?? '' ) ) {
		$invoice = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, owner_id, client_id, title, currency, total_amount, token
				 FROM {$wpdb->prefix}clientoctopus_invoices WHERE id = %d AND deleted_at IS NULL",
				$invoice_id
			),
			ARRAY_A
		);
		if ( ! $invoice ) {
			return;
		}
		do_action( 'clientoctopus_payment_failed', (int) $invoice['owner_id'], [
			'type'       => 'invoice',
			'invoice_id' => $invoice_id,
			'amount'     => (float) $invoice['total_amount'],
			'currency'   => $invoice['currency'],
		] );
		clientoctopus_notify_owner_payment_failed( (int) $invoice['owner_id'], [
			'type'          => 'invoice',
			'title'         => $invoice['title'],
			'amount'        => (float) $invoice['total_amount'],
			'currency'      => $invoice['currency'],
			'client_id'     => $invoice['client_id'],
			'cta_url'       => admin_url( 'admin.php?page=clientoctopus-invoices' ),
			// The client's own pay page — distinct from the owner's admin CTA
			// above. Without this the client email's "retry using the same
			// payment link" copy had no link behind it at all.
			'client_cta_url' => site_url( '/invoices/' . $invoice['token'] ),
			// 'decline' | 'authentication_required' — see class-recurring-profile.php's
			// attempt_auto_charge(). Only ever set for auto-charge failures; manual
			// payment failures (no reason in metadata) default to 'decline' copy.
			'reason'        => $metadata['reason'] ?? 'decline',
		] );
		return;
	}

	// ── Proposal payment branch ────────────────────────────────────────────────
	if ( ! $proposal_id ) {
		return;
	}

	// checkout.session.* failures still key off the payments-table row, which
	// only ever exists for proposal-type sessions — keep using mark_failed()
	// for those so its DB bookkeeping (status='failed') is preserved.
	if ( $gateway_id ) {
		ClientOctopus_Payment::mark_failed( $gateway_id, $provider );
	}

	$proposal = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, owner_id, client_id, title, total_amount, token FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d",
			$proposal_id
		),
		ARRAY_A
	);
	if ( ! $proposal ) {
		return;
	}

	$currency = strtoupper( (string) ( $metadata['currency'] ?? 'GBP' ) );

	do_action( 'clientoctopus_payment_failed', (int) $proposal['owner_id'], [
		'type'        => 'proposal',
		'proposal_id' => $proposal_id,
		'amount'      => (float) $proposal['total_amount'],
		'currency'    => $currency,
	] );
	clientoctopus_notify_owner_payment_failed( (int) $proposal['owner_id'], [
		'type'           => 'proposal',
		'title'          => $proposal['title'],
		'amount'         => (float) $proposal['total_amount'],
		'currency'       => $currency,
		'client_id'      => $proposal['client_id'],
		'cta_url'        => admin_url( 'admin.php?page=clientoctopus-proposals' ),
		'client_cta_url' => site_url( '/proposals/' . $proposal['token'] ),
	] );
}

/**
 * Send the owner (and client, if resolvable) an email when a payment attempt
 * on their invoice or proposal is declined/expired/voided.
 *
 * @param int   $owner_id WordPress user ID.
 * @param array $context  { type, title, amount, currency, client_id, cta_url, reason }
 */
function clientoctopus_notify_owner_payment_failed( int $owner_id, array $context ): void {
	global $wpdb;

	$owner = get_userdata( $owner_id );
	if ( ! $owner ) {
		return;
	}

	$currency        = $context['currency'] ?: 'GBP';
	$amount_fmt      = $currency . ' ' . number_format( (float) $context['amount'], 2 );
	$title           = esc_html( $context['title'] ?? ( 'invoice' === $context['type'] ? 'Invoice' : 'Proposal' ) );
	$noun            = 'invoice' === $context['type'] ? __( 'invoice', 'clientoctopus' ) : __( 'proposal', 'clientoctopus' );
	$needs_auth      = 'authentication_required' === ( $context['reason'] ?? 'decline' );

	/* translators: %s is the invoice or proposal title */
	$subject = $needs_auth
		? sprintf( __( 'Payment needs verification for "%s"', 'clientoctopus' ), sanitize_text_field( $context['title'] ?? '' ) )
		: sprintf( __( '⚠️ Payment failed for "%s"', 'clientoctopus' ), sanitize_text_field( $context['title'] ?? '' ) );
	$owner_explanation = $needs_auth
		? __( "needs verification — the client's bank is asking for a one-time confirmation before it can go through. This doesn't necessarily mean the card is broken.", 'clientoctopus' )
		: __( 'was declined, expired, or cancelled.', 'clientoctopus' );
	wp_mail(
		$owner->user_email,
		$subject,
		clientoctopus_email_html( [
			'name'      => $owner->display_name,
			/* translators: 1: amount, 2: invoice/proposal noun, 3: title, 4: explanation */
			'body'      => sprintf(
				"<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">A payment attempt of <strong style=\"color:#1A1A2E;\">%s</strong> on your %s <em>%s</em> %s</p><p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">No action is needed on your end — the client can retry from the same payment link.</p>",
				$amount_fmt,
				$noun,
				$title,
				$owner_explanation
			),
			'cta_label' => 'invoice' === $context['type'] ? __( 'View Invoice', 'clientoctopus' ) : __( 'View Proposal', 'clientoctopus' ),
			'cta_url'   => $context['cta_url'],
		] ),
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);

	if ( empty( $context['client_id'] ) ) {
		return;
	}

	$client_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT name, email FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d",
			(int) $context['client_id']
		),
		ARRAY_A
	);
	if ( ! $client_row || empty( $client_row['email'] ) ) {
		return;
	}

	$client_subject = $needs_auth
		/* translators: %s is the invoice or proposal title */
		? sprintf( __( 'Please verify your payment — %s', 'clientoctopus' ), sanitize_text_field( $context['title'] ?? '' ) )
		/* translators: %s is the invoice or proposal title */
		: sprintf( __( 'Payment didn\'t go through — %s', 'clientoctopus' ), sanitize_text_field( $context['title'] ?? '' ) );
	$client_explanation = $needs_auth
		? __( "Your bank is asking us to verify this payment before it can go through. This isn't a declined card — it just needs a quick one-time confirmation from you.", 'clientoctopus' )
		: __( "didn't go through. This can happen if a card was declined, expired, or the checkout session timed out.", 'clientoctopus' );

	wp_mail(
		$client_row['email'],
		$client_subject,
		clientoctopus_email_html( [
			'name'      => $client_row['name'] ?? '',
			'body'      => $needs_auth
				/* translators: 1: amount, 2: invoice/proposal noun, 3: title */
				? sprintf(
					"<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">%s</p><p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">Please confirm your payment of <strong style=\"color:#1A1A2E;\">%s</strong> for the %s <em>%s</em> using the link below.</p>",
					$client_explanation,
					$amount_fmt,
					$noun,
					$title
				)
				/* translators: 1: amount, 2: invoice/proposal noun, 3: title, 4: explanation */
				: sprintf(
					"<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">Your payment of <strong style=\"color:#1A1A2E;\">%s</strong> for the %s <em>%s</em> %s</p><p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">Please try again using the link below.</p>",
					$amount_fmt,
					$noun,
					$title,
					$client_explanation
				),
			'cta_label' => $needs_auth ? __( 'Confirm Payment', 'clientoctopus' ) : __( 'Retry Payment', 'clientoctopus' ),
			'cta_url'   => $context['client_cta_url'] ?? '',
		] ),
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);
}
