<?php
/**
 * Lead Capture Email + Webhook Handlers
 *
 * Hooked to clientoctopus_lead_captured (rest-api/leads.php):
 *   - Owner notification email
 *   - Optional auto-reply to the submitter (fixed, owner-authored template
 *     text only — never reflects submitted field content back to the
 *     recipient, since this endpoint has no auth and an unbounded auto-reply
 *     could otherwise be used to relay one attacker-triggered email at an
 *     arbitrary address)
 *   - lead.captured webhook dispatch
 *
 * Loaded on every request (via clientoctopus.php), matching
 * modules/invoices/handlers.php's loading pattern, so hooks fire in both
 * REST and any future non-REST contexts.
 *
 * @package ClientOctopus
 * @since   1.3.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table queries; table variables use $wpdb->prefix with hardcoded slugs, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Owner notification ────────────────────────────────────────────────────

add_action( 'clientoctopus_lead_captured', static function ( int $lead_id, int $owner_id ): void {
	global $wpdb;

	$lead = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_leads WHERE id = %d", $lead_id ),
		ARRAY_A
	);
	if ( ! $lead ) {
		return;
	}

	$owner = get_userdata( $owner_id );
	if ( ! $owner ) {
		return;
	}

	$business_name = get_option( 'clientoctopus_business_name', get_bloginfo( 'name' ) );
	$from_name     = get_option( 'clientoctopus_from_name', $business_name );
	$from_email    = get_option( 'clientoctopus_from_email', get_option( 'admin_email', '' ) );
	$leads_url     = admin_url( 'admin.php?page=clientoctopus-leads' );

	/* translators: %s is the lead's name */
	$subject = sprintf( __( 'New lead: %s', 'clientoctopus' ), $lead['name'] );

	$body_html  = '<p style="margin:0 0 12px;font-size:16px;color:#374151;line-height:1.65;">';
	$body_html .= sprintf(
		/* translators: %s is the lead's name */
		esc_html__( 'You have a new lead from %s.', 'clientoctopus' ),
		esc_html( $lead['name'] )
	) . '</p>';

	$body_html .= '<table style="width:100%;border-collapse:collapse;margin:16px 0 20px;">';
	$rows = [
		'Name'    => $lead['name'],
		'Email'   => $lead['email'],
		'Phone'   => $lead['phone'],
		'Company' => $lead['company'],
	];
	foreach ( $rows as $label => $value ) {
		if ( empty( $value ) ) {
			continue;
		}
		$body_html .= '<tr><td style="padding:8px 0;color:#6B7280;font-size:14px;">' . esc_html( $label ) . '</td>';
		$body_html .= '<td style="padding:8px 0;color:#111827;font-size:14px;">' . esc_html( $value ) . '</td></tr>';
	}
	$body_html .= '</table>';

	if ( ! empty( $lead['message'] ) ) {
		$body_html .= '<p style="margin:0 0 12px;font-size:15px;color:#374151;line-height:1.65;">' .
			nl2br( esc_html( $lead['message'] ) ) . '</p>';
	}

	if ( ! empty( $lead['existing_client_id'] ) ) {
		$body_html .= '<p style="margin:0 0 12px;font-size:14px;color:#B45309;line-height:1.5;">' .
			esc_html__( 'Note: this email address already matches an existing client.', 'clientoctopus' ) . '</p>';
	}

	$message = clientoctopus_email_html( [
		'subject'   => $subject,
		'name'      => $owner->display_name,
		'body'      => $body_html,
		'cta_label' => __( 'View Lead', 'clientoctopus' ),
		'cta_url'   => $leads_url,
	] );

	wp_mail(
		$owner->user_email,
		wp_strip_all_tags( $subject ),
		$message,
		[
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		]
	);
}, 10, 2 );

// ── Optional auto-reply to the submitter ──────────────────────────────────

add_action( 'clientoctopus_lead_captured', static function ( int $lead_id, int $owner_id ): void {
	if ( ! get_option( 'clientoctopus_lead_autoreply_enabled', '' ) ) {
		return;
	}

	global $wpdb;

	$lead = $wpdb->get_row(
		$wpdb->prepare( "SELECT name, email FROM {$wpdb->prefix}clientoctopus_leads WHERE id = %d", $lead_id ),
		ARRAY_A
	);
	if ( ! $lead || ! is_email( $lead['email'] ?? '' ) ) {
		return;
	}

	$business_name = get_option( 'clientoctopus_business_name', get_bloginfo( 'name' ) );
	$from_name     = get_option( 'clientoctopus_from_name', $business_name );
	$from_email    = get_option( 'clientoctopus_from_email', get_option( 'admin_email', '' ) );

	/* translators: %s is the business name */
	$default_subject = sprintf( __( 'Thanks for reaching out to %s', 'clientoctopus' ), $business_name );
	$subject          = (string) get_option( 'clientoctopus_lead_autoreply_subject', '' ) ?: $default_subject;

	$default_body = __( "Thanks for getting in touch — we've received your message and will be in contact soon.", 'clientoctopus' );
	$body_text    = (string) get_option( 'clientoctopus_lead_autoreply_body', '' ) ?: $default_body;

	// Fixed, owner-authored template only — never interpolates the lead's
	// own submitted content back into the reply body.
	$body_html = '<p style="margin:0 0 12px;font-size:16px;color:#374151;line-height:1.65;">' .
		nl2br( esc_html( $body_text ) ) . '</p>';

	// "Pick a Time to Talk" link — only when Booking (Pro/Agency) is enabled
	// and the owner has selected a page containing [clientoctopus_booking_form].
	// See modules/booking/shortcode.php's header comment for why this is a
	// link the visitor clicks (email-verification) rather than an inline
	// widget shown immediately after this same submission.
	$booking_cta_label = '';
	$booking_cta_url   = '';
	if ( get_option( 'clientoctopus_booking_enabled', '' ) && clientoctopus_can_user( $owner_id, 'use_booking' ) ) {
		$booking_page_id = (int) get_option( 'clientoctopus_booking_page_id', 0 );
		$booking_page_url = $booking_page_id ? get_permalink( $booking_page_id ) : '';
		if ( $booking_page_url ) {
			$booking_cta_label = __( 'Pick a Time to Talk', 'clientoctopus' );
			$booking_cta_url   = $booking_page_url;
		}
	}

	$message = clientoctopus_email_html( array_filter( [
		'subject'   => $subject,
		'name'      => $lead['name'],
		'body'      => $body_html,
		'cta_label' => $booking_cta_label,
		'cta_url'   => $booking_cta_url,
	] ) );

	wp_mail(
		$lead['email'],
		wp_strip_all_tags( $subject ),
		$message,
		[
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		]
	);
}, 10, 2 );

// ── Webhook dispatch ───────────────────────────────────────────────────────

add_action( 'clientoctopus_lead_captured', static function ( int $lead_id, int $owner_id ): void {
	if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) {
		return;
	}

	global $wpdb;

	$lead = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_leads WHERE id = %d", $lead_id ),
		ARRAY_A
	);
	if ( ! $lead ) {
		return;
	}

	clientoctopus_webhook_dispatch( 'lead.captured', $owner_id, [
		'lead_id'      => $lead_id,
		'name'         => $lead['name'],
		'email'        => $lead['email'],
		'phone'        => $lead['phone'],
		'company'      => $lead['company'],
		'message'      => $lead['message'],
		'budget_range' => $lead['budget_range'],
		'source_url'   => $lead['source_url'],
	] );
}, 99, 2 );
