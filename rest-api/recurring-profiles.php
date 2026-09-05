<?php
/**
 * REST API: Recurring Invoice Profiles
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Available on all plans (free, pro, agency) — matches Invoices, which
 * Recurring Invoices is a tab within (see clientoctopus.php load_rest_files()).
 *
 * A recurring profile is a template that spawns an ordinary
 * clientoctopus_invoices row on a schedule via the daily
 * clientoctopus_process_recurring_profiles cron — see
 * modules/invoices/class-recurring-profile.php. Clients pay each generated
 * invoice manually via the existing Stripe/PayPal "Pay Now" flow; there is
 * no separate payment endpoint here.
 *
 * @package ClientOctopus
 * @since   1.1.4
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', static function (): void {
	$recurring_class = CLIENTOCTOPUS_DIR . 'modules/invoices/class-recurring-profile.php';
	if ( ! class_exists( 'ClientOctopus_Recurring_Profile' ) && file_exists( $recurring_class ) ) {
		require_once $recurring_class;
	}

	$ns = 'clientoctopus/v1';
	$id = '(?P<id>\d+)';

	// ── GET /recurring-profiles/ ──────────────────────────────────────────────
	register_rest_route( $ns, '/recurring-profiles/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_list_recurring_profiles',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'status'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
			'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
			'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
		],
	] );

	// ── POST /recurring-profiles/create/ ──────────────────────────────────────
	register_rest_route( $ns, '/recurring-profiles/create/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_create_recurring_profile',
		'permission_callback' => 'clientoctopus_rest_require_edit',
		'args'                => clientoctopus_recurring_profile_field_args(),
	] );

	// ── GET /recurring-profiles/{id}/ ─────────────────────────────────────────
	register_rest_route( $ns, "/recurring-profiles/{$id}/", [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_get_recurring_profile',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
		],
	] );

	// ── POST /recurring-profiles/{id}/update/ ─────────────────────────────────
	register_rest_route( $ns, "/recurring-profiles/{$id}/update/", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_update_recurring_profile',
		'permission_callback' => 'clientoctopus_rest_require_edit',
		'args'                => array_merge(
			[ 'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ] ],
			clientoctopus_recurring_profile_field_args()
		),
	] );

	// ── POST /recurring-profiles/{id}/pause/ ──────────────────────────────────
	register_rest_route( $ns, "/recurring-profiles/{$id}/pause/", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_pause_recurring_profile',
		'permission_callback' => 'clientoctopus_rest_require_edit',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
		],
	] );

	// ── POST /recurring-profiles/{id}/resume/ ─────────────────────────────────
	register_rest_route( $ns, "/recurring-profiles/{$id}/resume/", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_resume_recurring_profile',
		'permission_callback' => 'clientoctopus_rest_require_edit',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
		],
	] );

	// ── POST /recurring-profiles/{id}/cancel/ ─────────────────────────────────
	register_rest_route( $ns, "/recurring-profiles/{$id}/cancel/", [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_cancel_recurring_profile',
		'permission_callback' => 'clientoctopus_rest_require_edit',
		'args'                => [
			'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
		],
	] );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Shared args helper
// ─────────────────────────────────────────────────────────────────────────────

function clientoctopus_recurring_profile_field_args(): array {
	return [
		'client_id'       => [ 'type' => 'integer', 'required' => false ],
		'title'           => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'po_number'       => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'payment_terms'   => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'notes'           => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ],
		'currency'        => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => 'GBP', 'enum' => [ 'GBP', 'USD', 'EUR', 'CAD', 'AUD' ] ],
		'line_items'      => [ 'type' => 'array',   'required' => false, 'default' => [] ],
		'discount_type'   => [ 'type' => 'string',  'required' => false, 'default' => 'percentage', 'enum' => [ 'percentage', 'fixed' ] ],
		'discount_value'  => [ 'type' => 'number',  'required' => false, 'default' => 0, 'minimum' => 0 ],
		'vat_pct'         => [ 'type' => 'number',  'required' => false, 'default' => 0, 'minimum' => 0, 'maximum' => 100 ],
		'vat_number'      => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'frequency'       => [ 'type' => 'string',  'required' => false, 'default' => 'monthly', 'enum' => [ 'weekly', 'monthly', 'quarterly', 'yearly' ] ],
		'start_date'      => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'end_date'        => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		'max_occurrences' => [ 'type' => 'integer', 'required' => false, 'minimum' => 1 ],
		// No 'default' here deliberately — 'manual' is a valid explicit choice, not
		// just the fallback, so a REST-applied default would make "explicitly manual"
		// indistinguishable from "field omitted" on update(). create()/update() in
		// class-recurring-profile.php each apply their own default when this comes
		// through as null (same pattern as frequency/discount_type elsewhere here).
		'billing_mode'    => [ 'type' => 'string',  'required' => false, 'enum' => [ 'manual', 'auto_charge' ] ],
	];
}

function clientoctopus_recurring_profile_params_from_request( WP_REST_Request $request ): array {
	return [
		'client_id'       => $request->get_param( 'client_id' ),
		'title'           => $request->get_param( 'title' ),
		'po_number'       => $request->get_param( 'po_number' ),
		'payment_terms'   => $request->get_param( 'payment_terms' ),
		'notes'           => $request->get_param( 'notes' ),
		'currency'        => $request->get_param( 'currency' ),
		'line_items'      => $request->get_param( 'line_items' ),
		'discount_type'   => $request->get_param( 'discount_type' ),
		'discount_value'  => $request->get_param( 'discount_value' ),
		'vat_pct'         => $request->get_param( 'vat_pct' ),
		'vat_number'      => $request->get_param( 'vat_number' ),
		'frequency'       => $request->get_param( 'frequency' ),
		'start_date'      => $request->get_param( 'start_date' ),
		'end_date'        => $request->get_param( 'end_date' ),
		'max_occurrences' => $request->get_param( 'max_occurrences' ),
		'billing_mode'    => $request->get_param( 'billing_mode' ),
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// Handler functions
// ─────────────────────────────────────────────────────────────────────────────

function clientoctopus_rest_list_recurring_profiles( WP_REST_Request $request ): WP_REST_Response {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$result   = ClientOctopus_Recurring_Profile::list( $owner_id, [
		'status'   => $request->get_param( 'status' ),
		'page'     => (int) $request->get_param( 'page' ),
		'per_page' => (int) $request->get_param( 'per_page' ),
	] );

	return new WP_REST_Response( $result, 200 );
}

function clientoctopus_rest_create_recurring_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$data     = clientoctopus_recurring_profile_params_from_request( $request );
	$result   = ClientOctopus_Recurring_Profile::create( $owner_id, $data );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'profile' => $result ], 201 );
}

function clientoctopus_rest_get_recurring_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$profile  = ClientOctopus_Recurring_Profile::get( $id, $owner_id );

	if ( is_wp_error( $profile ) ) {
		return $profile;
	}

	return new WP_REST_Response( [ 'profile' => $profile ], 200 );
}

function clientoctopus_rest_update_recurring_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$data     = clientoctopus_recurring_profile_params_from_request( $request );
	$result   = ClientOctopus_Recurring_Profile::update( $id, $owner_id, $data );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'profile' => $result ], 200 );
}

function clientoctopus_rest_pause_recurring_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$result   = ClientOctopus_Recurring_Profile::pause( $id, $owner_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'profile' => ClientOctopus_Recurring_Profile::get( $id, $owner_id ) ], 200 );
}

function clientoctopus_rest_resume_recurring_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$result   = ClientOctopus_Recurring_Profile::resume( $id, $owner_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'profile' => ClientOctopus_Recurring_Profile::get( $id, $owner_id ) ], 200 );
}

function clientoctopus_rest_cancel_recurring_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$result   = ClientOctopus_Recurring_Profile::cancel( $id, $owner_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'profile' => ClientOctopus_Recurring_Profile::get( $id, $owner_id ) ], 200 );
}
