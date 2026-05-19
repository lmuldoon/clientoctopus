<?php
/**
 * Portal URL routing.
 *
 * Registers rewrite rules for:
 *   /portal                → login (redirect)
 *   /portal/login          → PortalLogin component
 *   /portal/verify         → PortalVerify component  (token in query string)
 *   /portal/dashboard      → PortalDashboard (auth-gated)
 *   /portal/proposals      → PortalProposals (auth-gated)
 *   /portal/payments       → PortalPayments  (auth-gated)
 *
 * All portal pages bypass the active theme and render portal/template.php
 * as a standalone HTML page.
 */

declare( strict_types = 1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Register query vars ───────────────────────────────────────────────────────

add_filter( 'query_vars', function( array $vars ): array {
	$vars[] = 'clientoctopus_portal_page';
	return $vars;
} );

// ── Rewrite rules ─────────────────────────────────────────────────────────────

add_action( 'init', 'clientoctopus_add_portal_rewrite_rules' );

function clientoctopus_add_portal_rewrite_rules(): void {
	// /portal → redirect to /portal/login (handled in template_redirect below).
	add_rewrite_rule(
		'^clientoctopus/?$',
		'index.php?clientoctopus_portal_page=login',
		'top'
	);

	add_rewrite_rule(
		'^clientoctopus/login/?$',
		'index.php?clientoctopus_portal_page=login',
		'top'
	);

	add_rewrite_rule(
		'^clientoctopus/verify/?$',
		'index.php?clientoctopus_portal_page=verify',
		'top'
	);

	add_rewrite_rule(
		'^clientoctopus/dashboard/?$',
		'index.php?clientoctopus_portal_page=dashboard',
		'top'
	);

	add_rewrite_rule(
		'^clientoctopus/proposals/?$',
		'index.php?clientoctopus_portal_page=proposals',
		'top'
	);

	add_rewrite_rule(
		'^clientoctopus/payments/?$',
		'index.php?clientoctopus_portal_page=payments',
		'top'
	);

	add_rewrite_rule(
		'^clientoctopus/projects/?$',
		'index.php?clientoctopus_portal_page=projects',
		'top'
	);

	add_rewrite_rule(
		'^clientoctopus/set-password/?$',
		'index.php?clientoctopus_portal_page=set-password',
		'top'
	);

	add_rewrite_rule(
		'^clientoctopus/receipt/?$',
		'index.php?clientoctopus_portal_page=receipt',
		'top'
	);

	add_rewrite_rule(
		'^clientoctopus/logout/?$',
		'index.php?clientoctopus_portal_page=logout',
		'top'
	);
}

// ── Template redirect ─────────────────────────────────────────────────────────

add_action( 'template_redirect', 'clientoctopus_portal_template_redirect' );

function clientoctopus_portal_template_redirect(): void {
	$page = get_query_var( 'clientoctopus_portal_page' );

	if ( ! $page ) {
		return;
	}

	$authenticated_pages = [ 'dashboard', 'proposals', 'payments', 'projects', 'receipt' ];
	$public_pages        = [ 'login', 'verify' ];

	// /portal/set-password — auth required; accessible for both first-time
	// setup and password changes from within the portal.
	if ( 'set-password' === $page ) {
		if ( ! ClientOctopus_Portal_Auth::is_authenticated() ) {
			wp_safe_redirect( home_url( '/clientoctopus/login' ) );
			exit;
		}
		require CLIENTOCTOPUS_DIR . 'portal/template.php';
		exit;
	}

	// /portal/logout — clear session and redirect to login.
	if ( 'logout' === $page ) {
		wp_logout();
		wp_safe_redirect( home_url( '/clientoctopus/login' ) );
		exit;
	}

	if ( in_array( $page, $authenticated_pages, true ) ) {
		// Auth gate: unauthenticated clients → login.
		if ( ! ClientOctopus_Portal_Auth::is_authenticated() ) {
			wp_safe_redirect( home_url( '/clientoctopus/login' ) );
			exit;
		}
	} elseif ( in_array( $page, $public_pages, true ) ) {
		// Already logged-in clients hitting /login → dashboard.
		if ( 'login' === $page && ClientOctopus_Portal_Auth::is_authenticated() ) {
			wp_safe_redirect( home_url( '/clientoctopus/dashboard' ) );
			exit;
		}
	} else {
		// Unknown portal page → 404.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		return;
	}

	// Render standalone portal page (bypasses theme).
	require CLIENTOCTOPUS_DIR . 'portal/template.php';
	exit;
}
