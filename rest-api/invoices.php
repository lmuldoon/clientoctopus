<?php
/**
 * REST API: Standalone Invoices
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Free-tier routes (always registered):
 *   GET  /invoices/              — list owner's invoices
 *   POST /invoices/create/       — create draft invoice
 *   GET  /invoices/{id}/         — fetch single invoice
 *   POST /invoices/{id}/update/  — update invoice
 *   POST /invoices/{id}/send/    — send to client
 *   POST /invoices/{id}/mark-paid/ — manual mark as paid (free, no Stripe)
 *   POST /invoices/{id}/cancel/  — cancel sent/overdue invoice
 *   DELETE /invoices/{id}/       — soft-delete
 *
 * Pro-only route (Freemius build only):
 *   POST /invoices/{id}/pay/     — create Stripe Checkout Session
 *
 * All admin routes require manage_options (clientoctopus_rest_require_manage).
 * The client-facing view reads via the token — no admin auth needed there.
 *
 * @package ClientOctopus
 * @since   1.2.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', static function (): void {
	// Load Invoice class — not in autoloader scope (no hyphen in class slug).
	$invoice_class = CLIENTOCTOPUS_DIR . 'modules/invoices/class-invoice.php';
	if ( ! class_exists( 'ClientOctopus_Invoice' ) && file_exists( $invoice_class ) ) {
		require_once $invoice_class;
	}

	$ns = 'clientoctopus/v1';
	$id = '(?P<id>\d+)';

	// ── GET /invoices/ ────────────────────────────────────────────────────────
	register_rest_route( $ns, '/invoices/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_list_invoices',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'status' => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
				'enum'              => [ 'draft', 'sent', 'paid', 'overdue', 'cancelled' ],
			],
		],
	] );

	// ── POST /invoices/create/ ────────────────────────────────────────────────
	register_rest_route( $ns, '/invoices/create/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_create_invoice',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => clientoctopus_invoice_field_args(),
	] );

	// ── GET /invoices/{id}/ ───────────────────────────────────────────────────
	register_rest_route( $ns, "/invoices/{$id}/", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_get_invoice',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
		],
	] );

	// ── POST /invoices/{id}/update/ ───────────────────────────────────────────
	register_rest_route( $ns, "/invoices/{$id}/update/", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_update_invoice',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => array_merge(
			[ 'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ] ],
			clientoctopus_invoice_field_args()
		),
	] );

	// ── POST /invoices/{id}/send/ ─────────────────────────────────────────────
	register_rest_route( $ns, "/invoices/{$id}/send/", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_send_invoice',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
		],
	] );

	// ── POST /invoices/{id}/mark-paid/ ────────────────────────────────────────
	register_rest_route( $ns, "/invoices/{$id}/mark-paid/", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_mark_invoice_paid',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
		],
	] );

	// ── POST /invoices/{id}/cancel/ ───────────────────────────────────────────
	register_rest_route( $ns, "/invoices/{$id}/cancel/", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_cancel_invoice',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
		],
	] );

	// ── DELETE /invoices/{id}/ ────────────────────────────────────────────────
	register_rest_route( $ns, "/invoices/{$id}/", [
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'clientoctopus_rest_delete_invoice',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
		],
	] );

	// ── GET /invoices/public/{token}/ — client-facing, no admin auth ──────────
	// Token is a UUID4 — not guessable. No WP session needed.
	register_rest_route( $ns, '/invoices/public/(?P<token>[a-zA-Z0-9\-]+)/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_get_invoice_public',
		'permission_callback' => '__return_true', // Token-based auth in handler — UUID4 is the credential.
	] );

	// ── POST /invoices/{id}/pay/ — Stripe Checkout Session (Pro only) ─────────
	//@fs_premium_only
	if ( clientoctopus_fs()->is_premium() ) {
		register_rest_route( $ns, "/invoices/{$id}/pay/", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'clientoctopus_rest_invoice_pay',
			'permission_callback' => '__return_true', // Token-passed in body; validated in handler.
			'args'                => [
				'id'    => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
				'token' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}
	//@end:fs_premium_only
} );

// ─────────────────────────────────────────────────────────────────────────────
// Shared args helper
// ─────────────────────────────────────────────────────────────────────────────

function clientoctopus_invoice_field_args(): array {
	return [
		'client_id'      => [ 'type' => 'integer', 'required' => false ],
		'title'          => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'currency'       => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => 'GBP', 'enum' => [ 'GBP', 'USD', 'EUR', 'CAD', 'AUD' ] ],
		'line_items'     => [ 'type' => 'array',   'required' => false, 'default' => [] ],
		'discount_type'  => [ 'type' => 'string',  'required' => false, 'default' => 'percentage', 'enum' => [ 'percentage', 'fixed' ] ],
		'discount_value' => [ 'type' => 'number',  'required' => false, 'default' => 0, 'minimum' => 0 ],
		'vat_pct'        => [ 'type' => 'number',  'required' => false, 'default' => 0, 'minimum' => 0, 'maximum' => 100 ],
		'vat_number'     => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'notes'          => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ],
		'due_date'       => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'issue_date'     => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'payment_terms'  => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'po_number'      => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// Handler functions
// ─────────────────────────────────────────────────────────────────────────────

function clientoctopus_rest_list_invoices( WP_REST_Request $request ): WP_REST_Response {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$status   = $request->get_param( 'status' ) ?: null;
	$invoices = ClientOctopus_Invoice::list( $owner_id, $status ? [ 'status' => $status ] : [] );

	return new WP_REST_Response( [ 'invoices' => $invoices ], 200 );
}

function clientoctopus_rest_create_invoice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$data     = clientoctopus_invoice_params_from_request( $request );
	$result   = ClientOctopus_Invoice::create( $owner_id, $data );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'invoice' => $result ], 201 );
}

function clientoctopus_rest_get_invoice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$invoice  = ClientOctopus_Invoice::get( $id, $owner_id );

	if ( is_wp_error( $invoice ) ) {
		return $invoice;
	}

	return new WP_REST_Response( [ 'invoice' => $invoice ], 200 );
}

function clientoctopus_rest_update_invoice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$data     = clientoctopus_invoice_params_from_request( $request );
	$result   = ClientOctopus_Invoice::update( $id, $owner_id, $data );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'invoice' => $result ], 200 );
}

function clientoctopus_rest_send_invoice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );

	$invoice = ClientOctopus_Invoice::get( $id, $owner_id );
	if ( is_wp_error( $invoice ) ) {
		return $invoice;
	}

	$client_id = (int) ( $invoice['client_id'] ?? 0 );
	if ( ! $client_id ) {
		return new WP_Error(
			'no_client',
			__( 'Cannot send invoice: no client is assigned.', 'clientoctopus' ),
			[ 'status' => 422 ]
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$client_email = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT email FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d AND owner_id = %d LIMIT 1",
			$client_id,
			$owner_id
		)
	);

	if ( ! $client_email || ! is_email( $client_email ) ) {
		return new WP_Error(
			'no_client_email',
			__( 'Cannot send invoice: the assigned client has no email address. Please update the client record and try again.', 'clientoctopus' ),
			[ 'status' => 422 ]
		);
	}

	$result = ClientOctopus_Invoice::send( $id, $owner_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$invoice = ClientOctopus_Invoice::get( $id, $owner_id );
	return new WP_REST_Response( [ 'invoice' => $invoice ], 200 );
}

function clientoctopus_rest_mark_invoice_paid( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$result   = ClientOctopus_Invoice::mark_paid_manual( $id, $owner_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$invoice = ClientOctopus_Invoice::get( $id, $owner_id );
	return new WP_REST_Response( [ 'invoice' => $invoice ], 200 );
}

function clientoctopus_rest_cancel_invoice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$result   = ClientOctopus_Invoice::cancel( $id, $owner_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$invoice = ClientOctopus_Invoice::get( $id, $owner_id );
	return new WP_REST_Response( [ 'invoice' => $invoice ], 200 );
}

function clientoctopus_rest_delete_invoice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$result   = ClientOctopus_Invoice::delete( $id, $owner_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'deleted' => true ], 200 );
}

function clientoctopus_rest_get_invoice_public( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$token   = sanitize_text_field( (string) $request->get_param( 'token' ) );
	$invoice = ClientOctopus_Invoice::get_by_token( $token );

	if ( is_wp_error( $invoice ) ) {
		return $invoice;
	}

	// Check if the owner has Stripe configured and payments enabled.
	$payment_enabled = false;
	//@fs_premium_only
	if ( clientoctopus_fs()->is_premium() ) {
		$stripe_class = CLIENTOCTOPUS_DIR . 'modules/payments/class-stripe.php';
		if ( ! class_exists( 'ClientOctopus_Stripe' ) && file_exists( $stripe_class ) ) {
			require_once $stripe_class;
		}
		$payment_enabled = class_exists( 'ClientOctopus_Stripe' ) && ClientOctopus_Stripe::is_configured();
	}
	//@end:fs_premium_only

	// Fetch client details for display.
	$client_name = '';
	if ( $invoice['client_id'] ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$client_name = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT name FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d LIMIT 1", $invoice['client_id'] )
		);
	}

	return new WP_REST_Response( [
		'invoice'         => $invoice,
		'client_name'     => $client_name,
		'payment_enabled' => $payment_enabled,
		'business_name'   => get_option( 'clientoctopus_business_name', get_bloginfo( 'name' ) ),
		'business_logo'   => esc_url( get_option( 'clientoctopus_logo_url', '' ) ),
	], 200 );
}

//@fs_premium_only
function clientoctopus_rest_invoice_pay( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$id    = (int) $request->get_param( 'id' );
	$token = sanitize_text_field( (string) $request->get_param( 'token' ) );

	// Validate token matches invoice — this is the auth credential.
	$invoice = ClientOctopus_Invoice::get_by_token( $token );
	if ( is_wp_error( $invoice ) || (int) $invoice['id'] !== $id ) {
		return new WP_Error( 'invoice_not_found', __( 'Invoice not found.', 'clientoctopus' ), [ 'status' => 404 ] );
	}

	if ( ! in_array( $invoice['status'], [ 'sent', 'overdue' ], true ) ) {
		return new WP_Error(
			'invoice_not_payable',
			__( 'This invoice cannot be paid at its current status.', 'clientoctopus' ),
			[ 'status' => 422 ]
		);
	}

	$stripe_class = CLIENTOCTOPUS_DIR . 'modules/payments/class-stripe.php';
	if ( ! class_exists( 'ClientOctopus_Stripe' ) && file_exists( $stripe_class ) ) {
		require_once $stripe_class;
	}

	if ( ! class_exists( 'ClientOctopus_Stripe' ) || ! ClientOctopus_Stripe::is_configured() ) {
		return new WP_Error( 'stripe_not_configured', __( 'Payment is not available. Please contact the site administrator.', 'clientoctopus' ), [ 'status' => 503 ] );
	}

	$total    = (float) $invoice['total_amount'];
	$currency = strtolower( $invoice['currency'] );

	if ( $total <= 0 ) {
		return new WP_Error( 'invalid_amount', __( 'Invoice total must be greater than zero to pay online.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$amount_int = (int) round( $total * 100 );
	$min_amount = in_array( $currency, [ 'usd', 'aud', 'cad' ], true ) ? 50 : 30;

	if ( $amount_int < $min_amount ) {
		return new WP_Error( 'amount_too_low', __( 'The invoice amount is below the minimum required for online payment.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$label       = ! empty( $invoice['title'] ) ? $invoice['title'] : $invoice['invoice_ref'];
	$success_url = site_url( '/invoices/' . $token . '/success' ) . '?session_id={CHECKOUT_SESSION_ID}';
	$cancel_url  = site_url( '/invoices/' . $token . '/cancel' );

	$session = ClientOctopus_Stripe::create_checkout_session( [
		'mode'                 => 'payment',
		'payment_method_types' => [ 'card' ],
		'line_items'           => [
			[
				'price_data' => [
					'currency'     => $currency,
					'product_data' => [ 'name' => $label ],
					'unit_amount'  => $amount_int,
				],
				'quantity'   => 1,
			],
		],
		'success_url'          => $success_url,
		'cancel_url'           => $cancel_url,
		'metadata'             => [
			'type'       => 'invoice',
			'invoice_id' => $invoice['id'],
			'token'      => $token,
		],
	] );

	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return new WP_REST_Response( [
		'checkout_url' => $session['url'],
		'session_id'   => $session['id'],
	], 200 );
}
//@end:fs_premium_only

// ── Shared request-params extractor ──────────────────────────────────────────

function clientoctopus_invoice_params_from_request( WP_REST_Request $request ): array {
	$line_items_raw = $request->get_param( 'line_items' );
	$line_items     = [];
	if ( is_array( $line_items_raw ) ) {
		foreach ( $line_items_raw as $item ) {
			if ( isset( $item['description'] ) || isset( $item['amount'] ) ) {
				$line_items[] = [
					'description' => sanitize_text_field( $item['description'] ?? '' ),
					'amount'      => max( 0, (float) ( $item['amount'] ?? 0 ) ),
				];
			}
		}
	}

	return [
		'client_id'      => $request->get_param( 'client_id' ) ? (int) $request->get_param( 'client_id' ) : null,
		'title'          => sanitize_text_field( (string) ( $request->get_param( 'title' ) ?? '' ) ),
		'currency'       => strtoupper( sanitize_text_field( (string) ( $request->get_param( 'currency' ) ?? 'GBP' ) ) ),
		'line_items'     => $line_items,
		'discount_type'  => in_array( $request->get_param( 'discount_type' ), [ 'percentage', 'fixed' ], true )
		                     ? $request->get_param( 'discount_type' )
		                     : 'percentage',
		'discount_value' => max( 0, (float) ( $request->get_param( 'discount_value' ) ?? 0 ) ),
		'vat_pct'        => max( 0, min( 100, (float) ( $request->get_param( 'vat_pct' ) ?? 0 ) ) ),
		'vat_number'     => sanitize_text_field( (string) ( $request->get_param( 'vat_number' ) ?? '' ) ),
		'notes'          => sanitize_textarea_field( (string) ( $request->get_param( 'notes' ) ?? '' ) ),
		'due_date'       => sanitize_text_field( (string) ( $request->get_param( 'due_date' ) ?? '' ) ),
		'issue_date'     => sanitize_text_field( (string) ( $request->get_param( 'issue_date' ) ?? '' ) ),
		'payment_terms'  => sanitize_text_field( (string) ( $request->get_param( 'payment_terms' ) ?? '' ) ),
		'po_number'      => sanitize_text_field( (string) ( $request->get_param( 'po_number' ) ?? '' ) ),
	];
}
