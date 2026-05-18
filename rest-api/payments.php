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
		'class-stripe.php'  => 'ClientOctopus_Stripe',
		'class-payment.php' => 'ClientOctopus_Payment',
	] as $file => $class ) {
		if ( ! class_exists( $class ) && file_exists( $base . $file ) ) {
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
	register_rest_route( $ns, '/payments/create-session', [
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
	register_rest_route( $ns, '/payments/status', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_payment_status',
		'permission_callback' => '__return_true',
		'args'                => [
			'session_id' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
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
	register_rest_route( $ns, '/payments/webhook', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_payment_webhook',
		'permission_callback' => '__return_true', // Stripe signature check inside.
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
	$payable = [ 'accepted', 'draft', 'sent', 'viewed', 'completed' ];
	if ( ! in_array( $proposal['status'], $payable, true ) ) {
		return new WP_Error(
			'invalid_proposal_status',
			__( 'This proposal cannot be paid at its current status.', 'clientoctopus' ),
			[ 'status' => 422 ]
		);
	}

	// ── Guard: Stripe configured? ────────────────────────────────────────────
	if ( ! ClientOctopus_Stripe::is_configured() ) {
		return new WP_Error(
			'stripe_not_configured',
			__( 'Payment is not available. Please contact the site administrator.', 'clientoctopus' ),
			[ 'status' => 503 ]
		);
	}

	// ── Calculate charge amount ──────────────────────────────────────────────
	$total           = (float) ( $proposal['total_amount'] ?? 0 );
	$content         = is_array( $proposal['content'] ) ? $proposal['content'] : [];
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

	// Guard against Stripe's per-currency minimums (30p for GBP, 50¢ for USD, etc.).
	$min_amount = in_array( $currency, [ 'usd', 'aud', 'cad', 'sgd', 'hkd', 'jpy', 'krw' ], true ) ? 50 : 30;
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

	// ── Build Stripe URLs ────────────────────────────────────────────────────
	$success_url = site_url( '/proposals/' . $token . '/success' ) . '?session_id={CHECKOUT_SESSION_ID}';
	$cancel_url  = site_url( '/proposals/' . $token . '/cancel' );

	// ── Create checkout session ──────────────────────────────────────────────
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
		'metadata'             => [
			'proposal_id' => $proposal['id'],
			'token'       => $token,
			'deposit_pct' => $deposit_pct,
		],
	] );

	if ( is_wp_error( $session ) ) {
		return $session;
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
		'session_id'  => $session['id'],
		'client_id'   => $proposal['client_id'] ?? null,
	] );

	return new WP_REST_Response( [
		'checkout_url' => $session['url'],
		'session_id'   => $session['id'],
	], 200 );
}

/**
 * GET /clientoctopus/v1/payments/status?session_id=cs_xxx&token=xxx
 *
 * Returns the payment status. Called by the PaymentSuccess component to
 * confirm payment after Stripe's success redirect.
 */
function clientoctopus_rest_payment_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$session_id = (string) $request->get_param( 'session_id' );

	// Helper: build the standard response array from a payment record.
	$make_response = static fn( array $p ): array => [
		'status'       => $p['status'],
		'amount'       => $p['amount'],
		'currency'     => $p['currency'],
		'deposit_pct'  => $p['deposit_pct'],
		'completed_at' => $p['completed_at'] ?? null,
	];

	// Try local DB first. If the payment is already in a terminal state, return immediately.
	$payment = ClientOctopus_Payment::get_by_session_id( $session_id );
	if ( ! is_wp_error( $payment ) && in_array( $payment['status'], [ 'completed', 'failed', 'refunded' ], true ) ) {
		return new WP_REST_Response( $make_response( $payment ), 200 );
	}

	// Payment is pending (or not yet in DB) — check Stripe directly.
	if ( ! ClientOctopus_Stripe::is_configured() ) {
		return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
	}

	$stripe_session = ClientOctopus_Stripe::retrieve_session( $session_id );
	if ( is_wp_error( $stripe_session ) ) {
		return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
	}

	if ( 'paid' === ( $stripe_session['payment_status'] ?? '' ) ) {
		// Write-through: process fully as if the webhook had fired.
		// clientoctopus_handle_checkout_complete is idempotent — mark_complete updates by
		// session_id, and clientoctopus_proposal_accepted checks status before creating a project.
		clientoctopus_handle_checkout_complete( $stripe_session );

		$payment = ClientOctopus_Payment::get_by_session_id( $session_id );
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
		case 'payment_intent.payment_failed':
			$session_id = $event['data']['object']['id'] ?? '';
			if ( $session_id ) {
				ClientOctopus_Payment::mark_failed( $session_id );
			}
			break;
	}

	// Always return 200 — Stripe will retry on any non-2xx response.
	return new WP_REST_Response( [ 'received' => true ], 200 );
}

/**
 * Handle checkout.session.completed event.
 *
 * 1. Mark payment completed in our DB.
 * 2. Ensure proposal status is 'accepted' (in case the client paid without clicking Accept).
 * 3. Send owner notification email.
 *
 * @param array $session Stripe session object from event data.
 */
function clientoctopus_handle_checkout_complete( array $session ): void {
	$session_id        = $session['id']              ?? '';
	$payment_intent_id = $session['payment_intent']  ?? '';
	$customer_id       = $session['customer']        ?? null;
	$metadata          = $session['metadata']        ?? [];
	$proposal_id       = (int) ( $metadata['proposal_id'] ?? 0 );

	if ( ! $session_id || ! $proposal_id ) {
		return;
	}

	// Idempotency guard — if this session was already processed (webhook fired twice
	// or status endpoint triggered write-through concurrently) skip all side-effects.
	$existing = ClientOctopus_Payment::get_by_session_id( $session_id );
	if ( ! is_wp_error( $existing ) && 'completed' === $existing['status'] ) {
		return;
	}

	// Mark payment complete.
	ClientOctopus_Payment::mark_complete( $session_id, (string) $payment_intent_id, $customer_id ?: null );
	$payment = ClientOctopus_Payment::get_by_session_id( $session_id );

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
	// the testimonial check at completion time would have found no completed
	// payment and silently skipped. Retry it now.
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
		}
	}

	// Log event.
	$wpdb->insert(
		$wpdb->prefix . 'clientoctopus_events',
		[
			'proposal_id' => $proposal_id,
			'event_type'  => 'payment_completed',
			'user_ip'     => '',
			'user_agent'  => 'stripe-webhook',
			'timestamp'   => current_time( 'mysql' ),
			'metadata'    => wp_json_encode( [
				'session_id'  => $session_id,
				'amount'      => $session['amount_total'] ?? 0,
				'currency'    => $session['currency']     ?? '',
			] ),
		],
		[ '%d', '%s', '%s', '%s', '%s', '%s' ]
	);

	// Email owner.
	clientoctopus_notify_owner_payment_complete( (int) $proposal['owner_id'], $proposal, $session );
}

/**
 * Send the owner an email when their proposal is paid.
 *
 * @param int   $owner_id WordPress user ID.
 * @param array $proposal Raw proposal row.
 * @param array $session  Stripe session object.
 */
function clientoctopus_notify_owner_payment_complete( int $owner_id, array $proposal, array $session ): void {
	global $wpdb;

	$owner = get_userdata( $owner_id );
	if ( ! $owner ) {
		return;
	}

	$amount_raw    = ( $session['amount_total'] ?? 0 ) / 100;
	$currency      = strtoupper( $session['currency'] ?? 'GBP' );
	$amount_fmt    = $currency . ' ' . number_format( $amount_raw, 2 );
	$proposal_title = esc_html( $proposal['title'] ?? 'Proposal' );

	// Owner notification.
	/* translators: %s is the proposal title */
	$subject = sprintf( __( '💰 Payment received for "%s"', 'clientoctopus' ), $proposal['title'] ?? 'Proposal' );
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
				sprintf( __( 'Payment confirmed — %s', 'clientoctopus' ), $proposal['title'] ?? 'Proposal' ),
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
