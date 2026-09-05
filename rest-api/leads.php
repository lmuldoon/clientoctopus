<?php
/**
 * Lead Capture REST API
 *
 * POST /leads/submit/          — public, unauthenticated — the [clientoctopus_lead_form]
 *                                 shortcode submits here. The plugin's first
 *                                 public, write-capable endpoint that creates
 *                                 brand-new rows from anonymous visitors, so
 *                                 the validation/anti-abuse pipeline below is
 *                                 the real gate — permission_callback alone
 *                                 (__return_true, same as other public routes)
 *                                 is not what protects this route.
 * GET    /leads/                — admin, paginated list
 * GET    /leads/{id}/           — admin, single lead
 * PATCH  /leads/{id}/           — admin, update status
 * POST   /leads/{id}/convert/   — admin, convert lead to a client record
 * DELETE /leads/{id}/           — admin, delete
 *
 * @package ClientOctopus
 * @since   1.3.0
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- All table variables use $wpdb->prefix with hardcoded slugs, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Field configuration ────────────────────────────────────────────────────

/**
 * The optional fields an owner can enable/require/relabel, stored as their
 * own `clientoctopus_leads` columns rather than folded into the JSON-ish
 * $data array used for the six "extra" fields below. Kept separate from
 * clientoctopus_lead_optional_fields() because they're handled specially
 * (dedicated columns, format validation for email) in the submit handler.
 *
 * @return string[]
 */
function clientoctopus_lead_core_fields(): array {
	return [ 'name', 'email' ];
}

/**
 * The extra fields an owner can enable/require/relabel, stored in the
 * generic $data array in the submit handler.
 *
 * @return string[]
 */
function clientoctopus_lead_optional_fields(): array {
	return [ 'phone', 'company', 'message', 'budget_range', 'preferred_contact', 'source' ];
}

/**
 * Resolve the current, owner-configured settings for every field — name and
 * email included, configured identically to the six extra fields.
 *
 * @return array<string, array{enabled: bool, required: bool, label: string}>
 */
function clientoctopus_lead_field_settings(): array {
	$defaults = [
		'name'              => [ 'Name', true, true ],
		'email'             => [ 'Email', true, false ],
		'phone'             => [ 'Phone Number', true, false ],
		'company'           => [ 'Company', false, false ],
		'message'           => [ 'Message', true, false ],
		'budget_range'      => [ 'Budget Range', false, false ],
		'preferred_contact' => [ 'Preferred Contact Method', false, false ],
		'source'            => [ 'How did you hear about us?', false, false ],
	];

	$fields = [];
	foreach ( $defaults as $key => [ $default_label, $default_enabled, $default_required ] ) {
		// get_option()'s default only applies when the option row doesn't
		// exist at all — an empty string that got saved for real (e.g. a
		// partial form submission that omitted this field) is a value WP
		// considers "set", so it would otherwise stick forever instead of
		// falling back. Treat a blank stored label the same as unset.
		$stored_label = trim( (string) get_option( "clientoctopus_lead_field_{$key}_label", '' ) );

		$fields[ $key ] = [
			'enabled'  => (bool) get_option( "clientoctopus_lead_field_{$key}_enabled", $default_enabled ),
			'required' => (bool) get_option( "clientoctopus_lead_field_{$key}_required", $default_required ),
			'label'    => '' !== $stored_label ? $stored_label : $default_label,
		];
	}
	return $fields;
}

/**
 * Owner-configured budget bands for the Budget Range field, auto-formatted
 * from a currency + numeric thresholds rather than hand-typed — the plugin
 * has no site-wide currency (each proposal/invoice picks its own), and a
 * lead is captured before any document exists, so this is its own small
 * currency setting rather than borrowing one.
 *
 * @return string[] e.g. [ 'Under £1,000', '£1,000 – £5,000', '£5,000 – £10,000', '£10,000+' ]
 */
function clientoctopus_lead_budget_options(): array {
	$currency   = (string) get_option( 'clientoctopus_lead_budget_currency', 'GBP' );
	$thresholds = array_filter( array_map( 'absint', explode( ',', (string) get_option( 'clientoctopus_lead_budget_thresholds', '1000,5000,10000' ) ) ) );
	sort( $thresholds );

	// Duplicated from shared/currency.js's SYMBOLS map rather than shared
	// cross-language — same acceptable duplication already used for the
	// PHP-side symbol map in client/invoice-template.php.
	$symbols = [ 'GBP' => '£', 'USD' => '$', 'EUR' => '€', 'CAD' => 'CA$', 'AUD' => 'A$' ];
	$sym     = $symbols[ $currency ] ?? ( $currency . ' ' );

	$options = [];
	$prev    = null;
	foreach ( $thresholds as $t ) {
		$options[] = null === $prev
			? sprintf( 'Under %s%s', $sym, number_format( $t ) )
			: sprintf( '%s%s – %s%s', $sym, number_format( $prev ), $sym, number_format( $t ) );
		$prev = $t;
	}
	if ( null !== $prev ) {
		$options[] = sprintf( '%s%s+', $sym, number_format( $prev ) );
	}
	return $options;
}

// ── Route registration ────────────────────────────────────────────────────

add_action( 'rest_api_init', static function (): void {
	$ns = 'clientoctopus/v1';

	// Public submission endpoint — no WP auth; protected by the validation
	// pipeline inside the callback (honeypot, rate limits, CAPTCHA, required-
	// field re-derivation), same trust model as rest-api/client-proposals.php.
	register_rest_route( $ns, '/leads/submit/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_submit_lead',
		'permission_callback' => '__return_true',
		'args'                => [
			'name'               => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'email'              => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_email' ],
			'phone'              => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'company'            => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'message'            => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ],
			'budget_range'       => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'preferred_contact'  => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'source'             => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'source_url'         => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'esc_url_raw' ],
			'consent'            => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
			'website'            => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ], // honeypot
			'rendered_at'        => [ 'type' => 'integer', 'required' => false, 'default' => 0 ], // time-trap
			'turnstile_token'    => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	register_rest_route( $ns, '/leads/', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'clientoctopus_rest_list_leads',
			'permission_callback' => 'clientoctopus_rest_require_manage',
			'args'                => [
				'status'   => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
				'search'   => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
				'export'   => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
				'page'     => [ 'type' => 'integer', 'required' => false, 'default' => 1, 'minimum' => 1 ],
				'per_page' => [ 'type' => 'integer', 'required' => false, 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
			],
		],
	] );

	// Bulk archive/delete — a single request over an ID list rather than N
	// individual PATCH/DELETE round trips from the admin UI.
	register_rest_route( $ns, '/leads/bulk/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_bulk_update_leads',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'ids'    => [ 'type' => 'array', 'required' => true, 'items' => [ 'type' => 'integer' ] ],
			'action' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	register_rest_route( $ns, '/leads/(?P<id>\d+)/', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'clientoctopus_rest_get_lead',
			'permission_callback' => 'clientoctopus_rest_require_manage',
			'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
		],
		[
			'methods'             => 'PATCH',
			'callback'            => 'clientoctopus_rest_update_lead_status',
			'permission_callback' => 'clientoctopus_rest_require_manage',
			'args'                => [
				'id'     => [ 'type' => 'integer', 'required' => true ],
				'status' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		],
		[
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'clientoctopus_rest_delete_lead',
			'permission_callback' => 'clientoctopus_rest_require_manage',
			'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
		],
	] );

	register_rest_route( $ns, '/leads/(?P<id>\d+)/convert/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_convert_lead',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
	] );
} );

// ── Public submission handler ─────────────────────────────────────────────

/**
 * Verify a Cloudflare Turnstile token server-side.
 *
 * @param string $token
 * @param string $remote_ip
 *
 * @return bool|WP_Error True if verified, WP_Error on infrastructure failure,
 *                       false if the token is simply invalid.
 */
function clientoctopus_verify_turnstile( string $token, string $remote_ip ): bool|WP_Error {
	$secret = (string) get_option( 'clientoctopus_lead_turnstile_secret_key', '' );
	if ( ! $secret || ! $token ) {
		return false;
	}

	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-to-server API verification call (same pattern as this plugin's Stripe/PayPal API calls), not asset offloading; flagged only because the scanner pattern-matches the domain string. Opt-in only, disclosed in readme.txt's External Services section.
	$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
		'timeout' => 12,
		'body'    => [
			'secret'   => $secret,
			'response' => $token,
			'remoteip' => $remote_ip,
		],
	] );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'turnstile_http_error', $response->get_error_message(), [ 'status' => 503 ] );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $body ) && ! empty( $body['success'] );
}

function clientoctopus_rest_submit_lead( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = (int) get_option( 'clientoctopus_owner_user_id', 0 );
	if ( ! $owner_id ) {
		return new WP_Error( 'not_configured', __( 'This site is not yet configured to accept leads.', 'clientoctopus' ), [ 'status' => 500 ] );
	}

	// 1. Honeypot + time-trap — reject silently with a generic success so a
	// bot doesn't learn it was caught, matching the enumeration-safe pattern
	// used elsewhere (portal magic-link) for the same reason.
	$honeypot    = (string) $request->get_param( 'website' );
	$rendered_at = (int) $request->get_param( 'rendered_at' );
	if ( '' !== $honeypot || ( $rendered_at && ( time() - $rendered_at ) < 2 ) ) {
		return new WP_REST_Response( [ 'success' => true ], 201 );
	}

	// 2 & 3. IP-keyed rate limit.
	$ip = clientoctopus_get_client_ip();
	$ip_limit = (int) get_option( 'clientoctopus_lead_rate_limit_ip', 5 );
	if ( ! clientoctopus_rest_rate_limit( 'lead_capture', abs( crc32( $ip ) ), $ip_limit, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'rate_limited', __( 'Too many submissions. Please try again later.', 'clientoctopus' ), [ 'status' => 429 ] );
	}

	// 4. Global per-owner cap for the current hour — catches distributed
	// submissions that dodge the per-IP limit above.
	$global_limit = (int) get_option( 'clientoctopus_lead_rate_limit_global', 50 );
	if ( ! clientoctopus_rest_rate_limit( 'lead_capture_global', $owner_id, $global_limit, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'rate_limited', __( 'Too many submissions. Please try again later.', 'clientoctopus' ), [ 'status' => 429 ] );
	}

	// 5. CAPTCHA, if configured.
	if ( 'turnstile' === get_option( 'clientoctopus_lead_captcha_provider', 'none' ) ) {
		$verified = clientoctopus_verify_turnstile( (string) $request->get_param( 'turnstile_token' ), $ip );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( ! $verified ) {
			return new WP_Error( 'captcha_failed', __( 'Verification failed. Please try again.', 'clientoctopus' ), [ 'status' => 422 ] );
		}
	}

	// 6. Re-derive required fields from settings — never trust the client.
	// Name and email are configured the same way as the extra fields below,
	// but stored in their own dedicated columns, so they're checked here.
	$field_settings = clientoctopus_lead_field_settings();

	$name = $field_settings['name']['enabled'] ? trim( (string) $request->get_param( 'name' ) ) : '';
	if ( $field_settings['name']['enabled'] && $field_settings['name']['required'] && '' === $name ) {
		return new WP_Error(
			'missing_field',
			/* translators: %s is the field's owner-configured label */
			sprintf( __( '%s is required.', 'clientoctopus' ), $field_settings['name']['label'] ),
			[ 'status' => 422 ]
		);
	}

	$email = $field_settings['email']['enabled'] ? trim( (string) $request->get_param( 'email' ) ) : '';
	if ( $field_settings['email']['enabled'] && $field_settings['email']['required'] && '' === $email ) {
		return new WP_Error(
			'missing_field',
			/* translators: %s is the field's owner-configured label */
			sprintf( __( '%s is required.', 'clientoctopus' ), $field_settings['email']['label'] ),
			[ 'status' => 422 ]
		);
	}

	$data = [];
	foreach ( clientoctopus_lead_optional_fields() as $key ) {
		$value = trim( (string) $request->get_param( $key ) );
		if ( ! $field_settings[ $key ]['enabled'] ) {
			continue; // Disabled fields are never stored, even if submitted.
		}
		if ( $field_settings[ $key ]['required'] && '' === $value ) {
			return new WP_Error(
				'missing_field',
				/* translators: %s is the field's owner-configured label */
				sprintf( __( '%s is required.', 'clientoctopus' ), $field_settings[ $key ]['label'] ),
				[ 'status' => 422 ]
			);
		}
		$data[ $key ] = $value;
	}

	// 7. Email format validation (not just sanitize_email's char-stripping).
	if ( '' !== $email && ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	// Consent checkbox — required whenever the owner has a consent line configured.
	$consent_text = get_option( 'clientoctopus_lead_consent_text', '' );
	if ( $consent_text && ! $request->get_param( 'consent' ) ) {
		return new WP_Error( 'consent_required', __( 'Please confirm you agree before submitting.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	// 8. Length caps matching column sizes.
	$name          = mb_substr( $name, 0, 255 );
	$email         = mb_substr( $email, 0, 255 );
	$source_url    = mb_substr( (string) $request->get_param( 'source_url' ), 0, 500 );
	$data['phone']             = isset( $data['phone'] )             ? mb_substr( $data['phone'], 0, 50 )    : null;
	$data['company']           = isset( $data['company'] )           ? mb_substr( $data['company'], 0, 255 ) : null;
	$data['message']           = isset( $data['message'] )           ? $data['message']                      : null;
	$data['budget_range']      = isset( $data['budget_range'] )      ? mb_substr( $data['budget_range'], 0, 100 )      : null;
	// The field renders as a <select> of owner-configured bands — a value
	// that doesn't match any current band is far more likely a stale cached
	// page (bands changed since the visitor loaded the form) than an attack,
	// so it's silently dropped rather than a hard 422, matching this
	// endpoint's existing bot-tolerant posture elsewhere (e.g. the honeypot).
	if ( $data['budget_range'] && ! in_array( $data['budget_range'], clientoctopus_lead_budget_options(), true ) ) {
		$data['budget_range'] = null;
	}
	$data['preferred_contact'] = isset( $data['preferred_contact'] ) ? mb_substr( $data['preferred_contact'], 0, 50 )  : null;
	$data['source']            = $data['source'] ?? null; // stored transiently; not a DB column (see source_url)

	// 9. Duplicate-email check against existing clients — flag, don't reject.
	$existing_client_id = null;
	if ( '' !== $email ) {
		$existing_client_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}clientoctopus_clients WHERE owner_id = %d AND email = %s LIMIT 1",
				$owner_id,
				$email
			)
		);
	}

	$now = current_time( 'mysql' );
	$inserted = $wpdb->insert(
		$wpdb->prefix . 'clientoctopus_leads',
		[
			'owner_id'           => $owner_id,
			'name'               => $name,
			'email'              => $email ?: null,
			'phone'              => $data['phone'],
			'company'            => $data['company'],
			'message'            => $data['message'],
			'budget_range'       => $data['budget_range'],
			'preferred_contact'  => $data['preferred_contact'],
			'source_url'         => $source_url ?: null,
			'existing_client_id' => $existing_client_id ? (int) $existing_client_id : null,
			'status'             => 'new',
			'created_at'         => $now,
			'updated_at'         => $now,
		]
	);

	if ( ! $inserted ) {
		return new WP_Error( 'db_error', __( 'Could not save your submission. Please try again.', 'clientoctopus' ), [ 'status' => 500 ] );
	}

	$lead_id = $wpdb->insert_id;

	/**
	 * Fires after a lead is successfully captured — owner notification,
	 * optional submitter auto-reply, and webhook dispatch all hook this.
	 *
	 * @param int $lead_id
	 * @param int $owner_id
	 */
	do_action( 'clientoctopus_lead_captured', $lead_id, $owner_id );

	return new WP_REST_Response( [ 'success' => true ], 201 );
}

// ── Admin handlers ─────────────────────────────────────────────────────────

function clientoctopus_rest_list_leads( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$status   = (string) $request->get_param( 'status' );
	$search   = (string) $request->get_param( 'search' );
	$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
	$per_page = (int) $request->get_param( 'per_page' ) ?: 20;
	$offset   = ( $page - 1 ) * $per_page;

	$where = [ $wpdb->prepare( 'owner_id = %d', $owner_id ) ];
	if ( '' !== $status ) {
		$where[] = $wpdb->prepare( 'status = %s', $status );
	}
	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = $wpdb->prepare( '(name LIKE %s OR email LIKE %s OR company LIKE %s OR phone LIKE %s)', $like, $like, $like, $like );
	}
	$where_sql = implode( ' AND ', $where );

	if ( 'csv' === $request->get_param( 'export' ) ) {
		clientoctopus_stream_leads_csv( $where_sql );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql components are individually prepared above.
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}clientoctopus_leads WHERE {$where_sql}" );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}clientoctopus_leads WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		),
		ARRAY_A
	);

	foreach ( $rows as &$row ) {
		$row['id']                  = (int) $row['id'];
		$row['owner_id']            = (int) $row['owner_id'];
		$row['existing_client_id']  = $row['existing_client_id'] ? (int) $row['existing_client_id'] : null;
		$row['converted_client_id'] = $row['converted_client_id'] ? (int) $row['converted_client_id'] : null;
	}

	// Per-status counts for the tab badges — always reflects the owner's full
	// set (unfiltered by the active tab/search), matching the tab-badge
	// convention used by ProposalList/InvoicesApp/ProjectList.
	$counts = [ 'all' => 0, 'new' => 0, 'contacted' => 0, 'converted' => 0, 'archived' => 0 ];
	$count_rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$wpdb->prefix}clientoctopus_leads WHERE owner_id = %d GROUP BY status", $owner_id )
	);
	foreach ( $count_rows as $count_row ) {
		if ( isset( $counts[ $count_row->status ] ) ) {
			$counts[ $count_row->status ] = (int) $count_row->c;
		}
		$counts['all'] += (int) $count_row->c;
	}

	return new WP_REST_Response( [
		'leads'    => $rows,
		'total'    => $total,
		'page'     => $page,
		'per_page' => $per_page,
		'counts'   => $counts,
	], 200 );
}

/**
 * Neutralize CSV/formula injection: a lead is anonymous, public-form input
 * (name, message, etc. are entirely attacker-controlled), and any field
 * starting with a character a spreadsheet app treats as a formula prefix
 * (=, +, -, @, or a leading tab/CR) can execute when the owner opens the
 * exported file in Excel, Sheets, or LibreOffice — the classic "CSV
 * injection" pattern. Prefixing with a single quote forces those apps to
 * treat the cell as plain text; visually the quote is hidden by every major
 * spreadsheet app's own text-cell display, so this doesn't change what the
 * owner sees.
 *
 * @param mixed $value
 * @return string
 */
function clientoctopus_csv_safe_cell( $value ): string {
	$value = (string) $value;
	if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
		return "'" . $value;
	}
	return $value;
}

/**
 * Stream every lead matching $where_sql as a CSV download and exit. No
 * LIMIT — exports the full filtered result set, not just one page. Free on
 * every plan (unlike AnalyticsApp's CSV export, which is Pro/Agency-gated —
 * do not copy that gate here).
 *
 * @param string $where_sql Already-built from individually $wpdb->prepare()'d clauses.
 */
function clientoctopus_stream_leads_csv( string $where_sql ): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql components are individually prepared by the caller.
	$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}clientoctopus_leads WHERE {$where_sql} ORDER BY created_at DESC", ARRAY_A );

	$filename = 'clientoctopus-leads-' . gmdate( 'Y-m-d' ) . '.csv';

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Pragma: no-cache' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, [ 'Name', 'Email', 'Phone', 'Company', 'Message', 'Budget Range', 'Preferred Contact', 'Status', 'Received' ] );

	foreach ( $rows as $row ) {
		fputcsv( $out, [
			clientoctopus_csv_safe_cell( $row['name'] ),
			clientoctopus_csv_safe_cell( $row['email'] ),
			clientoctopus_csv_safe_cell( $row['phone'] ),
			clientoctopus_csv_safe_cell( $row['company'] ),
			clientoctopus_csv_safe_cell( $row['message'] ),
			clientoctopus_csv_safe_cell( $row['budget_range'] ),
			clientoctopus_csv_safe_cell( $row['preferred_contact'] ),
			$row['status'],
			$row['created_at'],
		] );
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing a php://output stream, not a filesystem file.
	exit;
}

/**
 * Bulk archive or delete a set of leads, scoped to the current owner —
 * matches the owner_id safety check already used by every single-row admin
 * handler in this file.
 */
function clientoctopus_rest_bulk_update_leads( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$ids      = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );
	$action   = (string) $request->get_param( 'action' );

	if ( empty( $ids ) || ! in_array( $action, [ 'archive', 'delete' ], true ) ) {
		return new WP_Error( 'invalid_request', __( 'Invalid bulk request.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$table        = $wpdb->prefix . 'clientoctopus_leads';
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

	if ( 'archive' === $action ) {
		$affected = (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is built from the same $ids used in the array_merge() below (one %d per id), so the count always matches; PHPCS can't evaluate the dynamically-built placeholder string or the single-array calling form of prepare(), which WP core officially supports.
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'archived', updated_at = %s WHERE owner_id = %d AND id IN ({$placeholders})",
				array_merge( [ current_time( 'mysql' ), $owner_id ], $ids )
			)
		);
	} else {
		$affected = (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- see identical justification above; $placeholders and $ids stay in sync.
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE owner_id = %d AND id IN ({$placeholders})",
				array_merge( [ $owner_id ], $ids )
			)
		);
	}

	return new WP_REST_Response( [ 'affected' => $affected ], 200 );
}

function clientoctopus_rest_get_lead( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );

	$lead = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_leads WHERE id = %d AND owner_id = %d", $id, $owner_id ),
		ARRAY_A
	);

	if ( ! $lead ) {
		return new WP_Error( 'not_found', __( 'Lead not found.', 'clientoctopus' ), [ 'status' => 404 ] );
	}

	$lead['id']       = (int) $lead['id'];
	$lead['owner_id'] = (int) $lead['owner_id'];

	return new WP_REST_Response( [ 'lead' => $lead ], 200 );
}

function clientoctopus_rest_update_lead_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );
	$status   = (string) $request->get_param( 'status' );

	if ( ! in_array( $status, [ 'new', 'contacted', 'converted', 'archived' ], true ) ) {
		return new WP_Error( 'invalid_status', __( 'Invalid status.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$updated = $wpdb->update(
		$wpdb->prefix . 'clientoctopus_leads',
		[ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
		[ 'id' => $id, 'owner_id' => $owner_id ]
	);

	if ( false === $updated ) {
		return new WP_Error( 'db_error', __( 'Could not update lead.', 'clientoctopus' ), [ 'status' => 500 ] );
	}

	/**
	 * Fires after a lead's status changes (new/contacted/converted/archived).
	 * Used by analytics to invalidate its lead-funnel cache.
	 *
	 * @param int    $lead_id
	 * @param int    $owner_id
	 * @param string $status
	 */
	do_action( 'clientoctopus_lead_status_changed', $id, $owner_id, $status );

	return clientoctopus_rest_get_lead( $request );
}

function clientoctopus_rest_delete_lead( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );

	$deleted = $wpdb->delete( $wpdb->prefix . 'clientoctopus_leads', [ 'id' => $id, 'owner_id' => $owner_id ] );

	if ( ! $deleted ) {
		return new WP_Error( 'not_found', __( 'Lead not found.', 'clientoctopus' ), [ 'status' => 404 ] );
	}

	return new WP_REST_Response( [ 'deleted' => true ], 200 );
}

/**
 * Convert a lead into a normal client record. Idempotent — converting the
 * same lead twice returns the already-created client rather than duplicating.
 */
function clientoctopus_rest_convert_lead( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );
	$id       = (int) $request->get_param( 'id' );

	$lead = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_leads WHERE id = %d AND owner_id = %d", $id, $owner_id ),
		ARRAY_A
	);

	if ( ! $lead ) {
		return new WP_Error( 'not_found', __( 'Lead not found.', 'clientoctopus' ), [ 'status' => 404 ] );
	}

	// Idempotency guard — already converted, return the existing client.
	if ( ! empty( $lead['converted_client_id'] ) ) {
		$client = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d", (int) $lead['converted_client_id'] ),
			ARRAY_A
		);
		return new WP_REST_Response( [ 'client' => $client, 'already_converted' => true ], 200 );
	}

	$now = current_time( 'mysql' );

	// The lead's email already matched an existing client at submission time
	// (see clientoctopus_rest_submit_lead()) — link to that client instead of
	// creating a duplicate record for the same person.
	if ( ! empty( $lead['existing_client_id'] ) ) {
		$client_id = (int) $lead['existing_client_id'];
	} else {
		$wpdb->insert(
			$wpdb->prefix . 'clientoctopus_clients',
			[
				'owner_id'   => $owner_id,
				'name'       => $lead['name'],
				'email'      => $lead['email'] ?: '',
				'company'    => $lead['company'] ?: '',
				'phone'      => $lead['phone'] ?: '',
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$client_id = $wpdb->insert_id;

		if ( ! $client_id ) {
			return new WP_Error( 'db_error', __( 'Could not create client from lead.', 'clientoctopus' ), [ 'status' => 500 ] );
		}
	}

	$wpdb->update(
		$wpdb->prefix . 'clientoctopus_leads',
		[ 'status' => 'converted', 'converted_client_id' => $client_id, 'updated_at' => $now ],
		[ 'id' => $id ]
	);

	/**
	 * Fires after a lead is converted into a client record.
	 *
	 * @param int $client_id
	 * @param int $lead_id
	 * @param int $owner_id
	 */
	do_action( 'clientoctopus_client_created_from_lead', $client_id, $id, $owner_id );

	$client = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d", $client_id ),
		ARRAY_A
	);

	return new WP_REST_Response( [ 'client' => $client, 'already_converted' => false ], 201 );
}
