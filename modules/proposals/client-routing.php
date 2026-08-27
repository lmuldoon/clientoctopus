<?php
/**
 * Client Proposal Routing
 *
 * Registers a WordPress rewrite rule so that URLs like:
 *   https://yoursite.com/proposals/{token}
 * are served by the standalone Client Octopus client template — completely
 * bypassing the active theme.
 *
 * The token is a UUID4 generated when the proposal is created.
 *
 * Hooks registered:
 *   init              — add_rewrite_tag, add_rewrite_rule
 *   template_redirect — intercept matched requests and serve template
 *
 * NOTE: flush_rewrite_rules() is called on plugin activation (clientoctopus.php),
 * so the rule takes effect immediately after install.
 *
 * @package ClientOctopus\Proposals
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Case-insensitive $_GET lookup — PayPal's own redirect params (`token`, `PayerID`)
 * are read directly from the query string, so this guards against any casing variance
 * rather than relying on the exact-case key PayPal happens to use today.
 *
 * @param string $name Case-insensitive query param name.
 *
 * @return string
 */
function clientoctopus_get_query_param_ci( string $name ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment provider redirect parameters, read-only, no state change.
	foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect parameters.
		if ( 0 === strcasecmp( (string) $key, $name ) ) {
			return sanitize_text_field( wp_unslash( is_array( $value ) ? '' : (string) $value ) );
		}
	}
	return '';
}

// ── Register rewrite tags + rules ────────────────────────────────────────────

add_action( 'init', static function (): void {
	// Primary token query var.
	add_rewrite_tag( '%clientoctopus_proposal_token%', '([a-zA-Z0-9\-]+)' );
	// Payment result: 'success' | 'cancel'
	add_rewrite_tag( '%clientoctopus_payment_result%', '(success|cancel)' );
	// Preview token — registered before the generic proposal rule so it matches first.
	add_rewrite_tag( '%clientoctopus_preview_token%', '([a-zA-Z0-9\-]+)' );
	// Invoice token — registered after proposal tags; separate query var namespace.
	add_rewrite_tag( '%clientoctopus_invoice_token%', '([a-zA-Z0-9\-]+)' );

	// /proposals/preview/{token}[/]  — internal preview viewer (read-only).
	add_rewrite_rule(
		'^proposals/preview/([a-zA-Z0-9\-]+)/?$',
		'index.php?clientoctopus_preview_token=$matches[1]',
		'top'
	);

	// /proposals/{token}/[/]  — proposal viewer.
	add_rewrite_rule(
		'^proposals/([a-zA-Z0-9\-]+)/?$',
		'index.php?clientoctopus_proposal_token=$matches[1]',
		'top'
	);

	// /proposals/{token}/success[/]  — payment success page.
	add_rewrite_rule(
		'^proposals/([a-zA-Z0-9\-]+)/success/?$',
		'index.php?clientoctopus_proposal_token=$matches[1]&clientoctopus_payment_result=success',
		'top'
	);

	// /proposals/{token}/cancel[/]  — payment cancelled page.
	add_rewrite_rule(
		'^proposals/([a-zA-Z0-9\-]+)/cancel/?$',
		'index.php?clientoctopus_proposal_token=$matches[1]&clientoctopus_payment_result=cancel',
		'top'
	);

	// /invoices/{token}[/]  — standalone invoice viewer.
	add_rewrite_rule(
		'^invoices/([a-zA-Z0-9\-]+)/?$',
		'index.php?clientoctopus_invoice_token=$matches[1]',
		'top'
	);

	// /invoices/{token}/success[/]  — Stripe payment success.
	add_rewrite_rule(
		'^invoices/([a-zA-Z0-9\-]+)/success/?$',
		'index.php?clientoctopus_invoice_token=$matches[1]&clientoctopus_payment_result=success',
		'top'
	);

	// /invoices/{token}/cancel[/]  — Stripe payment cancelled.
	add_rewrite_rule(
		'^invoices/([a-zA-Z0-9\-]+)/cancel/?$',
		'index.php?clientoctopus_invoice_token=$matches[1]&clientoctopus_payment_result=cancel',
		'top'
	);
}, 10 );

// ── Serve the standalone invoice template ────────────────────────────────────

add_action( 'template_redirect', static function (): void {
	$invoice_token = get_query_var( 'clientoctopus_invoice_token' );

	if ( ! $invoice_token ) {
		return;
	}

	$template = CLIENTOCTOPUS_DIR . 'client/invoice-template.php';

	if ( ! file_exists( $template ) ) {
		wp_die(
			esc_html__( 'Invoice viewer template not found.', 'clientoctopus' ),
			esc_html__( 'Error', 'clientoctopus' ),
			[ 'response' => 500 ]
		);
	}

	$clientoctopus_invoice_token  = sanitize_text_field( $invoice_token );
	$clientoctopus_payment_result = sanitize_key( get_query_var( 'clientoctopus_payment_result', '' ) );
	// Stripe passes ?session_id=cs_xxx on the success redirect. PayPal always appends its own
	// `token` (order id) + `PayerID` params to whatever return_url we give it — detect PayPal
	// purely from THEIR params (case-insensitive), with no dependency on any marker of ours
	// surviving PayPal's redirect. return_url is built with no pre-existing query string, so
	// there's no risk of PayPal producing a malformed `?a=b?c=d` URL either.
	$clientoctopus_paypal_token     = clientoctopus_get_query_param_ci( 'token' );
	$clientoctopus_paypal_payer_id  = clientoctopus_get_query_param_ci( 'PayerID' );
	$clientoctopus_gateway_provider = ( $clientoctopus_paypal_token && $clientoctopus_paypal_payer_id ) ? 'paypal' : 'stripe';
	$clientoctopus_session_id       = 'paypal' === $clientoctopus_gateway_provider
		? $clientoctopus_paypal_token
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stripe redirect parameter, read-only, no state change.
		: sanitize_text_field( wp_unslash( $_GET['session_id'] ?? '' ) );

	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
	include $template;
	exit;
}, 1 );

// ── Serve the standalone client template ──────────────────────────────────────

add_action( 'template_redirect', static function (): void {
	$template = CLIENTOCTOPUS_DIR . 'client/template.php';

	// ── Preview URL: /proposals/preview/{token} ──────────────────────────────
	$preview_token = get_query_var( 'clientoctopus_preview_token' );

	if ( $preview_token ) {
		if ( ! file_exists( $template ) ) {
			wp_die(
				esc_html__( 'Proposal viewer template not found.', 'clientoctopus' ),
				esc_html__( 'Error', 'clientoctopus' ),
				[ 'response' => 500 ]
			);
		}

		$clientoctopus_preview_token = sanitize_text_field( $preview_token );

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		include $template;
		exit;
	}

	// ── Standard proposal URL: /proposals/{token} ────────────────────────────
	$token = get_query_var( 'clientoctopus_proposal_token' );

	if ( ! $token ) {
		return;
	}

	if ( ! file_exists( $template ) ) {
		wp_die(
			esc_html__( 'Proposal viewer template not found.', 'clientoctopus' ),
			esc_html__( 'Error', 'clientoctopus' ),
			[ 'response' => 500 ]
		);
	}

	// Pass sanitised variables to the template.
	$clientoctopus_proposal_token  = sanitize_text_field( $token );
	$clientoctopus_payment_result  = sanitize_key( get_query_var( 'clientoctopus_payment_result', '' ) );
	// Stripe passes ?session_id=cs_xxx on the success redirect. PayPal always appends its own
	// `token` (order id) + `PayerID` params to whatever return_url we give it — detect PayPal
	// purely from THEIR params (case-insensitive), with no dependency on any marker of ours
	// surviving PayPal's redirect. return_url is built with no pre-existing query string, so
	// there's no risk of PayPal producing a malformed `?a=b?c=d` URL either.
	$clientoctopus_paypal_token     = clientoctopus_get_query_param_ci( 'token' );
	$clientoctopus_paypal_payer_id  = clientoctopus_get_query_param_ci( 'PayerID' );
	$clientoctopus_gateway_provider = ( $clientoctopus_paypal_token && $clientoctopus_paypal_payer_id ) ? 'paypal' : 'stripe';
	$clientoctopus_session_id       = 'paypal' === $clientoctopus_gateway_provider
		? $clientoctopus_paypal_token
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stripe redirect parameter, read-only, no state change.
		: sanitize_text_field( wp_unslash( $_GET['session_id'] ?? '' ) );

	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
	include $template;
	exit;
}, 1 );
