<?php
/**
 * REST API: Proposals Endpoints
 *
 * Namespace: /wp-json/clientoctopus/v1/
 *
 * Routes:
 *   POST   /proposals/create      — create proposal from wizard payload
 *   GET    /proposals             — list user's proposals (with filters)
 *   GET    /proposals/{id}        — get single proposal
 *   POST   /proposals/{id}/update — update proposal fields
 *   POST   /proposals/{id}/send   — send proposal to client
 *   POST   /proposals/{id}/duplicate — duplicate proposal
 *   DELETE /proposals/{id}        — delete proposal
 *   GET    /proposals/templates   — list available templates for user's plan
 *
 * All routes require authentication.
 *
 * @package ClientOctopus
 * @since   0.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load proposal classes if not already autoloaded.
add_action( 'rest_api_init', static function (): void {
	// Load each module class independently — a single shared condition was
	// short-circuiting after the first two classes loaded, leaving handlers.php
	// never required and ClientOctopus_Proposal_Handlers undefined.
	$base_dir    = CLIENTOCTOPUS_DIR . 'modules/proposals/';
	$module_files = [
		'class-proposal-template.php' => 'ClientOctopus_Proposal_Template',
		'class-proposal.php'          => 'ClientOctopus_Proposal',
		'handlers.php'                => 'ClientOctopus_Proposal_Handlers',
	];
	foreach ( $module_files as $file => $class ) {
		if ( ! class_exists( $class ) ) {
			$path = $base_dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	$ns = 'clientoctopus/v1';

	// ── GET /proposals/templates ──────────────────────────────────────────────
	register_rest_route( $ns, '/proposals/templates', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_list_templates',
		'permission_callback' => 'clientoctopus_rest_require_auth',
	] );

	// ── POST /proposals/create ────────────────────────────────────────────────
	register_rest_route( $ns, '/proposals/create', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_create_proposal',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => clientoctopus_proposal_create_args(),
	] );

	// ── GET /proposals ────────────────────────────────────────────────────────
	register_rest_route( $ns, '/proposals', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_list_proposals',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'status'   => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'enum'              => array_merge( [ '' ], ClientOctopus_Proposal::STATUSES ),
				'default'           => '',
			],
			'search'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
			'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
			'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
			'orderby'  => [ 'type' => 'string', 'default' => 'created_at', 'sanitize_callback' => 'sanitize_key' ],
			'order'    => [ 'type' => 'string', 'default' => 'DESC', 'enum' => [ 'ASC', 'DESC' ] ],
		],
	] );

	// ── GET /proposals/{id} ───────────────────────────────────────────────────
	register_rest_route( $ns, '/proposals/(?P<id>\d+)', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_get_proposal',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── POST /proposals/{id}/update ───────────────────────────────────────────
	register_rest_route( $ns, '/proposals/(?P<id>\d+)/update', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_update_proposal',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => array_merge(
			[ 'id' => [ 'type' => 'integer', 'required' => true ] ],
			clientoctopus_proposal_update_args()
		),
	] );

	// ── POST /proposals/{id}/send ─────────────────────────────────────────────
	register_rest_route( $ns, '/proposals/(?P<id>\d+)/send', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_send_proposal',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [
			'id'            => [ 'type' => 'integer', 'required' => true ],
			'client_email'  => [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_email',
			],
			'email_subject' => [
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
		],
	] );

	// ── POST /proposals/{id}/update-wizard ───────────────────────────────────
	register_rest_route( $ns, '/proposals/(?P<id>\d+)/update-wizard', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_update_wizard_proposal',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => array_merge(
			[ 'id' => [ 'type' => 'integer', 'required' => true ] ],
			clientoctopus_proposal_create_args()
		),
	] );

	// ── POST /proposals/{id}/duplicate ────────────────────────────────────────
	register_rest_route( $ns, '/proposals/(?P<id>\d+)/duplicate', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_duplicate_proposal',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── DELETE /proposals/{id} ────────────────────────────────────────────────
	register_rest_route( $ns, '/proposals/(?P<id>\d+)', [
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'clientoctopus_rest_delete_proposal',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── POST /proposals/{id}/preview-token ───────────────────────────────────
	register_rest_route( $ns, '/proposals/(?P<id>\d+)/preview-token', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_generate_preview_token',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );

	// ── DELETE /proposals/{id}/preview-token ─────────────────────────────────
	register_rest_route( $ns, '/proposals/(?P<id>\d+)/preview-token', [
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'clientoctopus_rest_revoke_preview_token',
		'permission_callback' => 'clientoctopus_rest_require_auth',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Argument definitions
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Args for POST /proposals/create.
 */
function clientoctopus_proposal_create_args(): array {
	return [
		'template_id'     => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_key' ],
		'title'           => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
		'currency'        => [ 'type' => 'string',  'required' => false, 'default' => 'GBP', 'sanitize_callback' => 'sanitize_text_field' ],
		'expiry_date'     => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		'deposit_pct'     => [ 'type' => 'number',  'required' => false, 'default' => 0 ],
		'require_deposit' => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
		'client_name'     => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		'client_email'    => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_email' ],
		'client_company'  => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		'client_phone'    => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		'line_items'      => [ 'type' => 'array',   'required' => false, 'default' => [] ],
		'discount_pct'    => [ 'type' => 'number',  'required' => false, 'default' => 0 ],
		'vat_pct'         => [ 'type' => 'number',  'required' => false, 'default' => 0 ],
	];
}

/**
 * Args for POST /proposals/{id}/update.
 */
function clientoctopus_proposal_update_args(): array {
	return [
		'title'        => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		'content'      => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'wp_kses_post' ],
		'status'       => [ 'type' => 'string', 'required' => false, 'enum' => ClientOctopus_Proposal::STATUSES ],
		'currency'     => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		'expiry_date'  => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		'total_amount' => [ 'type' => 'number', 'required' => false ],
		'client_id'    => [ 'type' => 'integer','required' => false ],
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// Route handlers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GET /clientoctopus/v1/proposals/templates
 *
 * Returns templates available for the current user's plan.
 */
function clientoctopus_rest_list_templates( WP_REST_Request $request ): WP_REST_Response {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$plan     = ClientOctopus_Entitlements::get_user_plan( $user_id );
	$all      = ClientOctopus_Proposal_Template::all();

	// Annotate each template with whether it's accessible.
	$templates = array_map( static function ( array $tpl ) use ( $plan ) {
		$tpl['locked'] = ( 'pro' === $tpl['tier'] && 'free' === $plan );
		unset( $tpl['sections'] ); // Don't expose full content in listing.
		return $tpl;
	}, $all );

	return new WP_REST_Response( [ 'templates' => array_values( $templates ) ], 200 );
}

/**
 * POST /clientoctopus/v1/proposals/create
 *
 * Create a proposal from the wizard payload.
 */
function clientoctopus_rest_create_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$payload = $request->get_params();

	$result = ClientOctopus_Proposal_Handlers::create_from_wizard( $user_id, $payload );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'proposal' => $result ], 201 );
}

/**
 * GET /clientoctopus/v1/proposals
 *
 * List the current user's proposals.
 */
function clientoctopus_rest_list_proposals( WP_REST_Request $request ): WP_REST_Response {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );

	$result = ClientOctopus_Proposal::list( $user_id, [
		'status'   => $request->get_param( 'status' ),
		'search'   => $request->get_param( 'search' ),
		'page'     => (int) $request->get_param( 'page' ),
		'per_page' => (int) $request->get_param( 'per_page' ),
		'orderby'  => $request->get_param( 'orderby' ),
		'order'    => $request->get_param( 'order' ),
	] );

	return new WP_REST_Response( $result, 200 );
}

/**
 * GET /clientoctopus/v1/proposals/{id}
 *
 * Get a single proposal.
 */
function clientoctopus_rest_get_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );

	$result = ClientOctopus_Proposal::get( $id, $user_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'proposal' => $result ], 200 );
}

/**
 * POST /clientoctopus/v1/proposals/{id}/update
 *
 * Update an existing proposal.
 */
function clientoctopus_rest_update_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );

	$data = array_filter(
		$request->get_params(),
		static fn( $k ) => in_array( $k, [ 'title', 'content', 'status', 'currency', 'expiry_date', 'total_amount', 'client_id' ], true ),
		ARRAY_FILTER_USE_KEY
	);

	if ( isset( $data['status'] ) ) {
		global $wpdb;
		$current_status = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}clientoctopus_proposals WHERE id = %d AND owner_id = %d",
				$id,
				$user_id
			)
		);

		// Valid forward transitions only — no rolling back terminal states.
		$allowed_from = [
			'draft'              => [ 'declined', 'expired', 'completed', 'revision_requested' ],
			'sent'               => [ 'declined', 'expired', 'completed' ],
			'viewed'             => [ 'declined', 'expired', 'completed' ],
			'accepted'           => [ 'completed' ],
			'revision_requested' => [ 'draft', 'declined', 'expired' ],
			'declined'           => [],
			'expired'            => [],
			'completed'          => [],
		];

		$allowed = $allowed_from[ $current_status ] ?? [];
		if ( $current_status !== $data['status'] && ! in_array( $data['status'], $allowed, true ) ) {
			return new WP_Error(
				'invalid_status_transition',
				sprintf(
					/* translators: 1: current status, 2: requested status */
					__( 'Cannot change proposal status from "%1$s" to "%2$s".', 'clientoctopus' ),
					$current_status,
					$data['status']
				),
				[ 'status' => 422 ]
			);
		}

		// Proposals with a linked project must be completed through the project, not directly.
		if ( 'completed' === $data['status'] && clientoctopus_can_user( $user_id, 'use_projects' ) ) {
			$has_project = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}clientoctopus_projects WHERE proposal_id = %d LIMIT 1",
					$id
				)
			);
			if ( $has_project ) {
				return new WP_Error(
					'use_project_to_complete',
					__( 'This proposal has a linked project. Mark the project as complete in the Projects section to close this proposal.', 'clientoctopus' ),
					[ 'status' => 422 ]
				);
			}
		}
	}

	$result = ClientOctopus_Proposal::update( $id, $user_id, $data );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$proposal = ClientOctopus_Proposal::get( $id, $user_id );

	if ( is_wp_error( $proposal ) ) {
		// Fallback: return minimal shape so the caller can still handle it.
		return new WP_REST_Response( [ 'updated' => true, 'id' => $id ], 200 );
	}

	// Fire testimonial email when a proposal is marked complete with no linked project
	// (Agency users complete proposals through the project; this covers Pro plan users).
	if ( isset( $data['status'] ) && 'completed' === $data['status'] ) {
		$has_project = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}clientoctopus_projects WHERE proposal_id = %d LIMIT 1",
				$id
			)
		);
		if ( ! $has_project && function_exists( 'clientoctopus_maybe_send_testimonial_email' ) ) {
			clientoctopus_maybe_send_testimonial_email( [ 'proposal_id' => $id ], $user_id );
		}

		// Notify client of outstanding balance when a deposit was taken but the
		// remaining balance hasn't been paid yet.
		clientoctopus_maybe_send_balance_due_email( $id );
	}

	return new WP_REST_Response( [ 'proposal' => $proposal ], 200 );
}

/**
 * POST /clientoctopus/v1/proposals/{id}/send
 *
 * Mark a proposal as sent and email the client.
 */
function clientoctopus_rest_send_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );

	if ( ! clientoctopus_rest_rate_limit( 'send_proposal', $user_id, 20 ) ) {
		return new WP_Error( 'rate_limited', __( 'Too many requests. Please wait a moment.', 'clientoctopus' ), [ 'status' => 429 ] );
	}

	if ( ! get_option( 'clientoctopus_from_email', '' ) ) {
		return new WP_Error(
			'no_sender_email',
			__( 'No sender email address is configured. Please add one in Settings → Sender Email before sending proposals.', 'clientoctopus' ),
			[ 'status' => 422 ]
		);
	}

	$id            = (int) $request->get_param( 'id' );
	$client_email  = (string) ( $request->get_param( 'client_email' ) ?? '' );
	$email_subject = (string) ( $request->get_param( 'email_subject' ) ?? '' );

	$result = ClientOctopus_Proposal_Handlers::send_to_client( $id, $user_id, $client_email, $email_subject );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'sent' => true, 'id' => $id ], 200 );
}

/**
 * POST /clientoctopus/v1/proposals/{id}/update-wizard
 *
 * Update an existing proposal using the full wizard payload.
 */
function clientoctopus_rest_update_wizard_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );
	$payload = $request->get_params();

	$result = ClientOctopus_Proposal_Handlers::update_from_wizard( $id, $user_id, $payload );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'proposal' => $result ], 200 );
}

/**
 * POST /clientoctopus/v1/proposals/{id}/duplicate
 *
 * Duplicate a proposal.
 */
function clientoctopus_rest_duplicate_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );

	$new_id = ClientOctopus_Proposal::duplicate( $id, $user_id );

	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}

	$proposal = ClientOctopus_Proposal::get( $new_id, $user_id );

	if ( is_wp_error( $proposal ) ) {
		return new WP_REST_Response( [ 'duplicated' => true, 'id' => $new_id ], 201 );
	}

	return new WP_REST_Response( [ 'proposal' => $proposal ], 201 );
}

/**
 * DELETE /clientoctopus/v1/proposals/{id}
 *
 * Delete a proposal.
 */
function clientoctopus_rest_delete_proposal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );

	$result = ClientOctopus_Proposal::delete( $id, $user_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
}

/**
 * POST /clientoctopus/v1/proposals/{id}/preview-token
 *
 * Generate (or regenerate) a shareable preview link for a proposal.
 * The preview URL is read-only — no client actions are available on it.
 */
function clientoctopus_rest_generate_preview_token( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );

	$token = ClientOctopus_Proposal::generate_preview_token( $id, $user_id );

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$preview_url = home_url( "/proposals/preview/{$token}" );

	return new WP_REST_Response( [ 'preview_token' => $token, 'preview_url' => $preview_url ], 200 );
}

/**
 * DELETE /clientoctopus/v1/proposals/{id}/preview-token
 *
 * Revoke the preview token — the preview URL immediately becomes invalid.
 */
function clientoctopus_rest_revoke_preview_token( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id      = (int) $request->get_param( 'id' );

	$result = ClientOctopus_Proposal::revoke_preview_token( $id, $user_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( [ 'revoked' => true ], 200 );
}

/**
 * Send the client a "balance due" email when a proposal is marked complete
 * and a deposit has been paid but the remaining balance is still outstanding.
 *
 * @param int $proposal_id
 */
function clientoctopus_maybe_send_balance_due_email( int $proposal_id ): void {
	global $wpdb;

	$pt = $wpdb->prefix . 'clientoctopus_proposals';
	$pm = $wpdb->prefix . 'clientoctopus_payments';
	$ct = $wpdb->prefix . 'clientoctopus_clients';

	// Fetch proposal with client email and token.
	$proposal = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT p.id, p.title, p.token, p.total_amount, p.client_id,
			        c.name AS client_name, c.email AS client_email
			 FROM   {$pt} p
			 LEFT JOIN {$ct} c ON c.id = p.client_id
			 WHERE  p.id = %d",
			$proposal_id
		),
		ARRAY_A
	);

	if ( ! $proposal || empty( $proposal['client_email'] ) ) {
		return;
	}

	$total = (float) $proposal['total_amount'];

	// Sum all completed payments for this proposal.
	$total_paid = (float) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COALESCE( SUM(amount), 0 )
			 FROM   {$pm}
			 WHERE  proposal_id = %d AND status = 'completed'",
			$proposal_id
		)
	);

	// Only send if the deposit was paid but the balance isn't — i.e. some money
	// has been received but the full amount hasn't been cleared yet.
	if ( $total_paid <= 0 || $total_paid >= $total ) {
		return;
	}

	$remaining      = $total - $total_paid;
	$currency       = strtoupper( (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT currency FROM {$pt} WHERE id = %d", $proposal_id )
	) ) ?: 'GBP';
	$remaining_fmt  = $currency . ' ' . number_format( $remaining, 2 );
	$proposal_title = esc_html( $proposal['title'] ?? 'Proposal' );
	$proposal_url   = home_url( '/proposals/' . $proposal['token'] );

	$body  = "<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">";
	$body .= sprintf(
		/* translators: %s is the proposal title */
		esc_html__( 'Great news — the work on your project "%s" is now complete!', 'clientoctopus' ),
		$proposal_title
	);
	$body .= "</p>";
	$body .= "<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">";
	$body .= sprintf(
		/* translators: %s is the formatted remaining amount */
		esc_html__( 'The outstanding balance of %s is now due. Please click the button below to make your payment.', 'clientoctopus' ),
		"<strong style=\"color:#1A1A2E;\">{$remaining_fmt}</strong>"
	);
	$body .= "</p>";

	wp_mail(
		$proposal['client_email'],
		/* translators: %s is the proposal title */
		sprintf( __( 'Your balance is due — %s', 'clientoctopus' ), sanitize_text_field( $proposal['title'] ?? 'Proposal' ) ),
		clientoctopus_email_html( [
			'name'      => $proposal['client_name'] ?? '',
			'body'      => $body,
			'cta_label' => __( 'Pay Remaining Balance', 'clientoctopus' ),
			'cta_url'   => $proposal_url,
		] ),
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);
}
