<?php
/**
 * REST API: Automation Reminders
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Routes:
 *   GET /automations         — list the owner's 3 automation rows
 *   PUT /automations/{trigger} — update one automation row
 *
 * Available on all plans — no premium check.
 *
 * @package ClientOctopus
 * @since   1.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', static function (): void {
	$path = CLIENTOCTOPUS_DIR . 'modules/automations/class-automations.php';
	if ( ! class_exists( 'ClientOctopus_Automations' ) && file_exists( $path ) ) {
		require_once $path;
	}

	$ns      = 'clientoctopus/v1';
	$trigger = '(?P<trigger>not_viewed|not_accepted|expiring_soon)';

	// ── GET /automations ─────────────────────────────────────────────────────
	register_rest_route( $ns, '/automations/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_get_automations',
		'permission_callback' => 'clientoctopus_rest_require_manage', // Requires manage_options — admin only.
	] );

	// ── PUT /automations/{trigger} ───────────────────────────────────────────
	register_rest_route( $ns, "/automations/{$trigger}/", [
		'methods'             => WP_REST_Server::EDITABLE,
		'callback'            => 'clientoctopus_rest_update_automation',
		'permission_callback' => 'clientoctopus_rest_require_manage', // Requires manage_options — admin only.
		'args'                => [
			'trigger'       => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => [ 'not_viewed', 'not_accepted', 'expiring_soon' ],
			],
			'enabled'       => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
			'delay_days'    => [ 'type' => 'integer', 'required' => false, 'default' => 3, 'minimum' => 1, 'maximum' => 30 ],
			'email_subject' => [ 'type' => 'string',  'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'email_body'    => [ 'type' => 'string',  'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
		],
	] );
} );

/**
 * GET /clientoctopus/v1/automations/
 */
function clientoctopus_rest_get_automations( WP_REST_Request $request ): WP_REST_Response {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$rows     = ClientOctopus_Automations::get_all( $owner_id );
	return new WP_REST_Response( [ 'automations' => array_values( $rows ) ], 200 );
}

/**
 * PUT /clientoctopus/v1/automations/{trigger}/
 */
function clientoctopus_rest_update_automation( WP_REST_Request $request ): WP_REST_Response {
	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$trigger  = (string) $request->get_param( 'trigger' );

	ClientOctopus_Automations::save( $owner_id, $trigger, [
		'enabled'       => (bool) $request->get_param( 'enabled' ),
		'delay_days'    => (int) $request->get_param( 'delay_days' ),
		'email_subject' => (string) $request->get_param( 'email_subject' ),
		'email_body'    => (string) $request->get_param( 'email_body' ),
	] );

	$rows = ClientOctopus_Automations::get_all( $owner_id );
	return new WP_REST_Response( [ 'automation' => $rows[ $trigger ] ?? [] ], 200 );
}
