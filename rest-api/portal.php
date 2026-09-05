<?php
/**
 * REST API endpoints for the client portal.
 *
 * Public endpoints (no auth required):
 *   POST /clientoctopus/v1/portal/send-magic-link
 *   POST /clientoctopus/v1/portal/verify
 *   POST /clientoctopus/v1/portal/login
 *
 * Authenticated endpoints (custom portal session cookie):
 *   GET  /clientoctopus/v1/portal/me
 *   GET  /clientoctopus/v1/portal/proposals
 *   GET  /clientoctopus/v1/portal/payments
 *   POST /clientoctopus/v1/portal/logout
 *   POST /clientoctopus/v1/portal/set-password
 *   GET  /clientoctopus/v1/portal/receipt/{id}
 *
 * Authentication uses a custom httpOnly cookie (co_portal_session) backed by
 * the clientoctopus_portal_sessions table. No WordPress user account is
 * created or required for portal clients.
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- All table variables use $wpdb->prefix with hardcoded slugs, not user input.

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', 'clientoctopus_register_portal_routes' );

function clientoctopus_register_portal_routes(): void {
	$ns = 'clientoctopus/v1';

	// ── Public: request a magic link ─────────────────────────────────────────
	register_rest_route( $ns, '/portal/send-magic-link/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_portal_send_magic_link',
		'permission_callback' => '__return_true', // Public endpoint; email rate-limiting in handler.
		'args'                => [
			'email' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => fn( $v ) => is_email( $v ),
			],
		],
	] );

	// ── Public: verify token & create portal session ──────────────────────────
	register_rest_route( $ns, '/portal/verify/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_portal_verify',
		'permission_callback' => '__return_true', // Public endpoint; one-time token validated in handler.
		'args'                => [
			'token' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		],
	] );

	// ── Authenticated: current client profile ────────────────────────────────
	register_rest_route( $ns, '/portal/me/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_me',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
	] );

	// ── Authenticated: client's proposals ───────────────────────────────────
	register_rest_route( $ns, '/portal/proposals/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_proposals',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
	] );

	// ── Authenticated: client's payments ────────────────────────────────────
	register_rest_route( $ns, '/portal/payments/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_payments',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
	] );

	// ── Authenticated: client's invoices ────────────────────────────────────
	register_rest_route( $ns, '/portal/invoices/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_invoices',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
	] );

	// ── Authenticated: client's invoice payments ─────────────────────────────
	register_rest_route( $ns, '/portal/invoice-payments/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_invoice_payments',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
	] );

	// ── Authenticated: logout ────────────────────────────────────────────────
	register_rest_route( $ns, '/portal/logout/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_portal_logout',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
	] );

	// ── Authenticated: set password ──────────────────────────────────────────
	register_rest_route( $ns, '/portal/set-password/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_portal_set_password',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [
			'password' => [
				'required'          => true,
				'sanitize_callback' => static fn( $v ) => (string) $v,
			],
		],
	] );

	// ── Authenticated: single payment receipt ───────────────────────────────
	register_rest_route( $ns, '/portal/receipt/(?P<id>\d+)/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_portal_get_receipt',
		'permission_callback' => [ 'ClientOctopus_Portal_Auth', 'rest_permission' ],
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true ],
		],
	] );

	// ── Public: password login ───────────────────────────────────────────────
	register_rest_route( $ns, '/portal/login/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_portal_password_login',
		'permission_callback' => '__return_true', // Public endpoint; credentials validated in handler.
		'args'                => [
			'email'    => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => static fn( $v ) => is_email( $v ),
			],
			'password' => [
				'required'          => true,
				'sanitize_callback' => static fn( $v ) => (string) $v,
			],
		],
	] );
}

// =============================================================================
// Handlers
// =============================================================================

/**
 * POST /portal/send-magic-link
 *
 * Accepts: { email }
 * Always returns a generic success response to prevent email enumeration.
 */
function clientoctopus_portal_send_magic_link( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	// Rate-limit per IP: 3 requests per 10 minutes. Return generic 200 to avoid revealing rate-limit state.
	$ip     = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately via sanitize_text_field.
	$ip_key = abs( crc32( $ip ) );
	if ( ! clientoctopus_rest_rate_limit( 'portal_magic_link', $ip_key, 3, 600 ) ) {
		return new WP_REST_Response( [
			'success' => true,
			'message' => 'If an account exists for that email, a login link has been sent.',
		], 200 );
	}

	$email = $request->get_param( 'email' );

	// Look up the client in clientoctopus_clients — no WordPress user needed.
	$client = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, name, owner_id FROM {$wpdb->prefix}clientoctopus_clients WHERE email = %s LIMIT 1",
			$email
		),
		ARRAY_A
	);

	if ( $client ) {
		$raw_token = ClientOctopus_Portal_Auth::generate_magic_token( (int) $client['id'] );
		ClientOctopus_Portal_Auth::send_magic_link_email( $email, $client['name'] ?? '', $raw_token );
	}

	// Generic response: never reveal whether the email is registered.
	return new WP_REST_Response( [
		'success' => true,
		'message' => 'If an account exists for that email, a login link has been sent.',
	], 200 );
}

/**
 * POST /portal/verify
 *
 * Accepts: { token }
 * On success: creates portal session (cookie) + returns redirect URL.
 */
function clientoctopus_portal_verify( WP_REST_Request $request ): WP_REST_Response {
	$token  = $request->get_param( 'token' );
	$result = ClientOctopus_Portal_Auth::verify_magic_token( $token );

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response( [
			'success' => false,
			'message' => $result->get_error_message(),
			'code'    => $result->get_error_code(),
		], 401 );
	}

	// Clients who have set a password go straight to the dashboard.
	// First-time clients (no password yet) go to the set-password screen.
	$has_password = ! empty( $result['portal_password_hash'] );
	$redirect     = $has_password
		? home_url( '/clientoctopus/dashboard' )
		: home_url( '/clientoctopus/set-password' );

	return new WP_REST_Response( [
		'success'      => true,
		'redirect_url' => $redirect,
	], 200 );
}

/**
 * GET /portal/me
 */
function clientoctopus_portal_me(): WP_REST_Response {
	$client_id = ClientOctopus_Portal_Auth::get_current_client_id();
	$client    = ClientOctopus_Portal_Data::get_client( $client_id );

	return new WP_REST_Response( $client, 200 );
}

/**
 * GET /portal/proposals
 */
function clientoctopus_portal_proposals(): WP_REST_Response {
	$client_id = ClientOctopus_Portal_Auth::get_current_client_id();
	$proposals = ClientOctopus_Portal_Data::get_proposals( $client_id );

	return new WP_REST_Response( $proposals, 200 );
}

/**
 * GET /portal/payments
 */
function clientoctopus_portal_payments(): WP_REST_Response {
	$client_id = ClientOctopus_Portal_Auth::get_current_client_id();
	$payments  = ClientOctopus_Portal_Data::get_payments( $client_id );

	return new WP_REST_Response( $payments, 200 );
}

function clientoctopus_portal_invoices(): WP_REST_Response {
	$client_id = ClientOctopus_Portal_Auth::get_current_client_id();
	$invoices  = ClientOctopus_Portal_Data::get_invoices( $client_id );

	return new WP_REST_Response( $invoices, 200 );
}

function clientoctopus_portal_invoice_payments(): WP_REST_Response {
	$client_id = ClientOctopus_Portal_Auth::get_current_client_id();
	$payments  = ClientOctopus_Portal_Data::get_invoice_payments( $client_id );

	return new WP_REST_Response( $payments, 200 );
}

/**
 * POST /portal/logout
 */
function clientoctopus_portal_logout(): WP_REST_Response {
	ClientOctopus_Portal_Auth::destroy_session();

	return new WP_REST_Response( [
		'success'      => true,
		'redirect_url' => home_url( '/clientoctopus/login' ),
	], 200 );
}

/**
 * Validate a portal password against all requirements.
 * Returns an array of failed rule keys (empty = valid).
 */
function clientoctopus_validate_portal_password( string $password ): array {
	$errors = [];
	if ( strlen( $password ) < 8 )                     $errors[] = 'min_length';
	if ( ! preg_match( '/[A-Z]/', $password ) )        $errors[] = 'uppercase';
	if ( ! preg_match( '/[a-z]/', $password ) )        $errors[] = 'lowercase';
	if ( ! preg_match( '/[0-9]/', $password ) )        $errors[] = 'number';
	if ( ! preg_match( '/[^A-Za-z0-9]/', $password ) ) $errors[] = 'special';
	return $errors;
}

/**
 * POST /portal/set-password
 */
function clientoctopus_portal_set_password( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$client_id = ClientOctopus_Portal_Auth::get_current_client_id();
	$password  = $request->get_param( 'password' );

	$errors = clientoctopus_validate_portal_password( $password );

	if ( $errors ) {
		return new WP_REST_Response( [
			'success' => false,
			'errors'  => $errors,
			'message' => __( 'Password does not meet the requirements.', 'clientoctopus' ),
		], 422 );
	}

	$wpdb->update(
		$wpdb->prefix . 'clientoctopus_clients',
		[ 'portal_password_hash' => wp_hash_password( $password ) ],
		[ 'id' => $client_id ],
		[ '%s' ],
		[ '%d' ]
	);

	return new WP_REST_Response( [
		'success'      => true,
		'redirect_url' => home_url( '/clientoctopus/dashboard' ),
	], 200 );
}

/**
 * POST /portal/login
 */
function clientoctopus_portal_password_login( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	// Rate-limit per IP: 5 attempts per 5 minutes.
	$ip     = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately via sanitize_text_field.
	$ip_key = abs( crc32( $ip ) );
	if ( ! clientoctopus_rest_rate_limit( 'portal_login', $ip_key, 5, 300 ) ) {
		return new WP_REST_Response( [
			'success' => false,
			'message' => __( 'Too many login attempts. Please wait a few minutes before trying again.', 'clientoctopus' ),
		], 429 );
	}

	$email    = $request->get_param( 'email' );
	$password = $request->get_param( 'password' );

	$invalid = new WP_REST_Response( [
		'success' => false,
		'message' => __( 'Invalid email or password.', 'clientoctopus' ),
	], 401 );

	$client = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}clientoctopus_clients WHERE email = %s LIMIT 1",
			$email
		),
		ARRAY_A
	);

	if ( ! $client ) {
		return $invalid;
	}

	// Only allow password login after the client has set one.
	if ( empty( $client['portal_password_hash'] ) ) {
		return $invalid;
	}

	if ( ! wp_check_password( $password, $client['portal_password_hash'] ) ) {
		return $invalid;
	}

	ClientOctopus_Portal_Auth::create_session( (int) $client['id'], (int) $client['owner_id'] );

	return new WP_REST_Response( [
		'success'      => true,
		'redirect_url' => home_url( '/clientoctopus/dashboard' ),
	], 200 );
}

/**
 * GET /portal/receipt/{id}
 *
 * Returns full receipt data for a single completed payment.
 * Scoped to the authenticated client — returns 404 if the payment
 * doesn't belong to them.
 */
function clientoctopus_portal_get_receipt( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$payment_id = (int) $request->get_param( 'id' );
	$client_id  = ClientOctopus_Portal_Auth::get_current_client_id();

	$pm = $wpdb->prefix . 'clientoctopus_payments';
	$pt = $wpdb->prefix . 'clientoctopus_proposals';
	$ct = $wpdb->prefix . 'clientoctopus_clients';

	$payment = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT pay.id, pay.proposal_id, pay.amount, pay.currency, pay.deposit_pct,
			        pay.stripe_payment_intent_id, pay.completed_at, pay.created_at,
			        pr.title AS proposal_title, pr.token AS proposal_token, pr.total_amount,
			        c.name AS client_name, c.email AS client_email
			 FROM   {$pm} AS pay
			 JOIN   {$pt} AS pr ON pr.id = pay.proposal_id
			 JOIN   {$ct} AS c  ON c.id  = pr.client_id
			 WHERE  pay.id = %d
			   AND  pr.client_id = %d
			   AND  pay.status = 'completed'",
			$payment_id,
			$client_id
		),
		ARRAY_A
	);

	if ( ! $payment ) {
		return new WP_REST_Response( [ 'success' => false ], 404 );
	}

	$prior_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$pm}
			 WHERE  proposal_id = %d
			   AND  status = 'completed'
			   AND  created_at < %s",
			(int) $payment['proposal_id'],
			$payment['created_at']
		)
	);

	if ( (int) $payment['deposit_pct'] < 100 ) {
		$payment_type = 'Deposit';
	} elseif ( $prior_count > 0 ) {
		$payment_type = 'Remaining balance';
	} else {
		$payment_type = 'Full payment';
	}

	return new WP_REST_Response( [
		'success'        => true,
		'payment'        => $payment,
		'payment_type'   => $payment_type,
		'client_name'    => $payment['client_name'],
		'client_email'   => $payment['client_email'],
		'business_name'  => get_option( 'clientoctopus_business_name' ) ?: get_option( 'blogname' ),
		'business_logo'  => get_option( 'clientoctopus_logo_url', '' ),
		'brand_color'    => get_option( 'clientoctopus_brand_color', '#6366F1' ),
	], 200 );
}
