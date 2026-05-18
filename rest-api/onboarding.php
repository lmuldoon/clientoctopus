<?php
/**
 * REST API: Onboarding endpoints.
 *
 * GET  /clientoctopus/v1/onboarding/status  → current step + saved brand data
 * POST /clientoctopus/v1/onboarding/save    → persist step data to wp_options
 * POST /clientoctopus/v1/onboarding/complete → mark wizard done
 *
 * @package ClientOctopus
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', static function (): void {

	$ns = 'clientoctopus/v1';

	// GET /onboarding/status
	register_rest_route( $ns, '/onboarding/status', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_onboarding_status',
		'permission_callback' => 'clientoctopus_rest_require_auth',
	] );

	// POST /onboarding/save
	register_rest_route( $ns, '/onboarding/save', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_onboarding_save',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'step'                   => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 4 ],
			'stripe_pk'              => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'stripe_sk'              => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'stripe_webhook_secret'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'business_name'          => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'from_name'              => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'from_email'             => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
			'brand_color'            => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color' ],
			'logo_url'               => [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
		],
	] );

	// POST /onboarding/complete
	register_rest_route( $ns, '/onboarding/complete', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_onboarding_complete',
		'permission_callback' => 'clientoctopus_rest_require_auth',
	] );

} );

/**
 * Return current onboarding state.
 */
function clientoctopus_onboarding_status( WP_REST_Request $request ): WP_REST_Response {
	return new WP_REST_Response( [
		'complete'      => (bool) get_option( 'clientoctopus_onboarding_complete' ),
		'step'          => (int) get_option( 'clientoctopus_onboarding_step', 0 ),
		'saved'         => [
			'stripe_pk'     => get_option( 'clientoctopus_stripe_publishable_key', '' ),
			'business_name' => get_option( 'clientoctopus_business_name', '' ),
			'from_name'     => get_option( 'clientoctopus_from_name', '' ),
			'from_email'    => get_option( 'clientoctopus_from_email', '' ),
			'brand_color'   => get_option( 'clientoctopus_brand_color', '#6366f1' ),
			'logo_url'      => get_option( 'clientoctopus_logo_url', '' ),
		],
	], 200 );
}

/**
 * Save one step's worth of settings and advance the stored step pointer.
 */
function clientoctopus_onboarding_save( WP_REST_Request $request ): WP_REST_Response {
	$map = [
		'stripe_pk'             => 'clientoctopus_stripe_publishable_key',
		'stripe_sk'             => 'clientoctopus_stripe_secret_key',
		'stripe_webhook_secret' => 'clientoctopus_stripe_webhook_secret',
		'business_name'         => 'clientoctopus_business_name',
		'from_name'             => 'clientoctopus_from_name',
		'from_email'            => 'clientoctopus_from_email',
		'brand_color'           => 'clientoctopus_brand_color',
		'logo_url'              => 'clientoctopus_logo_url',
	];

	foreach ( $map as $param => $option ) {
		$value = $request->get_param( $param );
		if ( null !== $value && '' !== $value ) {
			update_option( $option, $value );
		}
	}

	$step = $request->get_param( 'step' );
	if ( null !== $step ) {
		$current = (int) get_option( 'clientoctopus_onboarding_step', 0 );
		if ( (int) $step >= $current ) {
			update_option( 'clientoctopus_onboarding_step', (int) $step );
		}
	}

	return new WP_REST_Response( [
		'success' => true,
		'step'    => (int) get_option( 'clientoctopus_onboarding_step', 0 ),
	], 200 );
}

/**
 * Mark onboarding as complete.
 */
function clientoctopus_onboarding_complete( WP_REST_Request $request ): WP_REST_Response {
	update_option( 'clientoctopus_onboarding_complete', gmdate( 'c' ) );

	return new WP_REST_Response( [ 'success' => true ], 200 );
}
