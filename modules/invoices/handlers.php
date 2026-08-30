<?php
/**
 * Invoice Email + Webhook Handlers
 *
 * Hooked to invoice lifecycle actions:
 *   clientoctopus_invoice_sent      — send client notification email
 *   clientoctopus_invoice_paid      — fire invoice.paid webhook
 *   clientoctopus_invoice_overdue   — fire invoice.overdue webhook
 *   clientoctopus_invoice_cancelled — fire invoice.cancelled webhook
 *
 * Loaded on every request (via clientoctopus.php) so hooks fire in cron and
 * non-REST contexts, not just during REST API calls.
 *
 * @package ClientOctopus
 * @since   1.2.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Ensure Invoice class is available ─────────────────────────────────────────

if ( ! class_exists( 'ClientOctopus_Invoice' ) ) {
	$clientoctopus_invoice_class = CLIENTOCTOPUS_DIR . 'modules/invoices/class-invoice.php';
	if ( file_exists( $clientoctopus_invoice_class ) ) {
		require_once $clientoctopus_invoice_class;
	}
}

// ── Invoice sent — email the client ─────────────────────────────────────────

add_action( 'clientoctopus_invoice_sent', static function ( int $invoice_id, int $owner_id ): void {
	if ( ! class_exists( 'ClientOctopus_Invoice' ) ) {
		return;
	}

	$invoice = ClientOctopus_Invoice::get( $invoice_id, $owner_id );
	if ( is_wp_error( $invoice ) ) {
		return;
	}

	// Fetch client email from the clients table.
	$client_email = '';
	$client_name  = '';
	if ( $invoice['client_id'] ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT name, email FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d LIMIT 1",
				$invoice['client_id']
			),
			ARRAY_A
		);
		$client_email = $client['email'] ?? '';
		$client_name  = $client['name']  ?? '';
	}

	if ( ! is_email( $client_email ) ) {
		return;
	}

	$business_name = get_option( 'clientoctopus_business_name', get_bloginfo( 'name' ) );
	$from_name     = get_option( 'clientoctopus_from_name', $business_name );
	$from_email    = get_option( 'clientoctopus_from_email', get_option( 'admin_email', '' ) );
	$invoice_url   = trailingslashit( home_url() ) . 'invoices/' . $invoice['token'];

	$currency_symbols = [
		'GBP' => '£', 'USD' => '$', 'EUR' => '€', 'CAD' => '$', 'AUD' => '$',
	];
	$symbol  = $currency_symbols[ $invoice['currency'] ] ?? '';
	$total   = $symbol . number_format( (float) $invoice['total_amount'], 2 );
	$due     = ! empty( $invoice['due_date'] )
		? gmdate( 'j F Y', (int) strtotime( $invoice['due_date'] ) )
		: '';

	/* translators: 1: invoice reference, 2: business name */
	$subject = sprintf( __( 'Invoice %1$s from %2$s', 'clientoctopus' ), esc_html( $invoice['invoice_ref'] ), esc_html( $business_name ) );

	$body_html  = '<p style="margin:0 0 12px;font-size:16px;color:#374151;line-height:1.65;">';
	$body_html .= sprintf(
		/* translators: 1: client name */
		esc_html__( 'Hi %s,', 'clientoctopus' ),
		esc_html( $client_name )
	) . '</p>';
	$body_html .= '<p style="margin:0 0 12px;font-size:16px;color:#374151;line-height:1.65;">' .
		esc_html__( 'Please find your invoice details below.', 'clientoctopus' ) . '</p>';

	$body_html .= '<table style="width:100%;border-collapse:collapse;margin:16px 0 20px;">';
	$body_html .= '<tr><td style="padding:8px 0;color:#6B7280;font-size:14px;">' . esc_html__( 'Invoice', 'clientoctopus' ) . '</td>';
	$body_html .= '<td style="padding:8px 0;font-weight:600;color:#111827;font-size:14px;">' . esc_html( $invoice['invoice_ref'] ) . '</td></tr>';
	if ( ! empty( $invoice['title'] ) ) {
		$body_html .= '<tr><td style="padding:8px 0;color:#6B7280;font-size:14px;">' . esc_html__( 'Description', 'clientoctopus' ) . '</td>';
		$body_html .= '<td style="padding:8px 0;color:#111827;font-size:14px;">' . esc_html( $invoice['title'] ) . '</td></tr>';
	}
	$body_html .= '<tr><td style="padding:8px 0;color:#6B7280;font-size:14px;">' . esc_html__( 'Amount Due', 'clientoctopus' ) . '</td>';
	$body_html .= '<td style="padding:8px 0;font-weight:700;color:#111827;font-size:16px;">' . esc_html( $total ) . '</td></tr>';
	if ( $due ) {
		$body_html .= '<tr><td style="padding:8px 0;color:#6B7280;font-size:14px;">' . esc_html__( 'Due Date', 'clientoctopus' ) . '</td>';
		$body_html .= '<td style="padding:8px 0;color:#111827;font-size:14px;">' . esc_html( $due ) . '</td></tr>';
	}
	$body_html .= '</table>';

	if ( ! empty( $invoice['notes'] ) ) {
		$body_html .= '<p style="margin:0 0 12px;font-size:15px;color:#374151;line-height:1.65;">' .
			nl2br( esc_html( $invoice['notes'] ) ) . '</p>';
	}

	$message = clientoctopus_email_html( [
		'subject'   => $subject,
		'name'      => $client_name,
		'body'      => $body_html,
		'cta_label' => __( 'View Invoice', 'clientoctopus' ),
		'cta_url'   => $invoice_url,
	] );

	wp_mail(
		$client_email,
		wp_strip_all_tags( $subject ),
		$message,
		[
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		]
	);
}, 10, 2 );

// ── Webhook dispatchers ──────────────────────────────────────────────────────

add_action( 'clientoctopus_invoice_sent', static function ( int $invoice_id, int $owner_id ): void {
	if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
	$payload = clientoctopus_invoice_webhook_payload( $invoice_id, $owner_id );
	if ( $payload ) {
		clientoctopus_webhook_dispatch( 'invoice.sent', $owner_id, $payload );
	}
}, 99, 2 );

add_action( 'clientoctopus_invoice_paid', static function ( int $invoice_id, int $owner_id ): void {
	if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
	$payload = clientoctopus_invoice_webhook_payload( $invoice_id, $owner_id );
	if ( $payload ) {
		clientoctopus_webhook_dispatch( 'invoice.paid', $owner_id, $payload );
	}
}, 99, 2 );

// Auto-resume a past_due recurring profile once its outstanding invoice gets
// paid — fires for both manual and auto-charge payment success (both routes
// call mark_paid_for_provider(), which fires this hook universally), so the
// status check below makes it a safe no-op for a routine already-active
// profile's payment. Deliberately automatic rather than requiring the owner
// to click Resume: a client successfully paying with a working card is as
// clear a signal as exists that billing should continue, and requiring a
// manual step here would just reintroduce a milder version of the same
// dead-end (paid invoice, still-paused profile, silently missing next cycle).
add_action( 'clientoctopus_invoice_paid', static function ( int $invoice_id, int $owner_id ): void {
	global $wpdb;

	$profile_id = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT recurring_profile_id FROM {$wpdb->prefix}clientoctopus_invoices WHERE id = %d", $invoice_id )
	);
	if ( ! $profile_id ) return;

	$status = $wpdb->get_var(
		$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}clientoctopus_recurring_profiles WHERE id = %d", $profile_id )
	);
	if ( 'past_due' !== $status ) return;

	$path = CLIENTOCTOPUS_DIR . 'modules/invoices/class-recurring-profile.php';
	if ( ! class_exists( 'ClientOctopus_Recurring_Profile' ) && file_exists( $path ) ) {
		require_once $path;
	}
	if ( class_exists( 'ClientOctopus_Recurring_Profile' ) ) {
		ClientOctopus_Recurring_Profile::resume( $profile_id, $owner_id );
	}
}, 99, 2 );

add_action( 'clientoctopus_invoice_overdue', static function ( int $invoice_id, int $owner_id ): void {
	if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
	$payload = clientoctopus_invoice_webhook_payload( $invoice_id, $owner_id );
	if ( $payload ) {
		clientoctopus_webhook_dispatch( 'invoice.overdue', $owner_id, $payload );
	}
}, 99, 2 );

add_action( 'clientoctopus_invoice_cancelled', static function ( int $invoice_id, int $owner_id ): void {
	if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
	$payload = clientoctopus_invoice_webhook_payload( $invoice_id, $owner_id );
	if ( $payload ) {
		clientoctopus_webhook_dispatch( 'invoice.cancelled', $owner_id, $payload );
	}
}, 99, 2 );

// ── Payload builder ───────────────────────────────────────────────────────────

/**
 * Build the standard webhook payload for an invoice event.
 *
 * @param int $invoice_id
 * @param int $owner_id
 * @return array|null Null if invoice cannot be loaded.
 */
function clientoctopus_invoice_webhook_payload( int $invoice_id, int $owner_id ): ?array {
	if ( ! class_exists( 'ClientOctopus_Invoice' ) ) {
		return null;
	}

	$invoice = ClientOctopus_Invoice::get( $invoice_id, $owner_id );
	if ( is_wp_error( $invoice ) ) {
		return null;
	}

	$client_email = '';
	if ( $invoice['client_id'] ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$client_email = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT email FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d LIMIT 1", $invoice['client_id'] )
		);
	}

	return [
		'invoice_id'           => $invoice['id'],
		'invoice_number'       => $invoice['invoice_ref'],
		'client_email'         => $client_email,
		'total_amount'         => $invoice['total_amount'],
		'currency'             => $invoice['currency'],
		'status'               => $invoice['status'],
		'recurring_profile_id' => $invoice['recurring_profile_id'] ?? null,
	];
}
