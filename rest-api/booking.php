<?php
/**
 * Call Booking REST API (Pro/Agency)
 *
 * GET    /booking/availability/       — public, unauthenticated — computes open
 *                                        slots for a given date from the owner's
 *                                        configured weekly hours minus existing
 *                                        bookings, buffer, and minimum notice.
 * POST   /booking/submit/             — public, unauthenticated — creates a
 *                                        booking. Reachable only via the
 *                                        [clientoctopus_booking_form] shortcode,
 *                                        itself only ever linked from a real
 *                                        inbox click (see modules/booking/shortcode.php
 *                                        header) — the validation pipeline below
 *                                        (honeypot, rate limit, DB-level
 *                                        double-booking guard) is the real gate,
 *                                        same trust model as rest-api/leads.php.
 * GET    /booking/cancel/{token}/     — public — token-scoped cancellation.
 * GET    /bookings/                   — admin, paginated list.
 * PATCH  /bookings/{id}/              — admin, cancel.
 *
 * @package ClientOctopus
 * @since   1.4.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- All table variables use $wpdb->prefix with hardcoded slugs, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Configuration helpers ───────────────────────────────────────────────────

/**
 * @return array<string, array{enabled: bool, start: string, end: string}> Keyed mon..sun.
 */
function clientoctopus_booking_weekly_hours(): array {
	$defaults = [
		'mon' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
		'tue' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
		'wed' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
		'thu' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
		'fri' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
		'sat' => [ 'enabled' => false, 'start' => '09:00', 'end' => '17:00' ],
		'sun' => [ 'enabled' => false, 'start' => '09:00', 'end' => '17:00' ],
	];

	$raw     = (string) get_option( 'clientoctopus_booking_weekly_hours', '' );
	$decoded = $raw ? json_decode( $raw, true ) : null;

	return is_array( $decoded ) ? array_replace_recursive( $defaults, $decoded ) : $defaults;
}

/**
 * @return array{enabled: bool, duration: int, buffer: int, min_notice_hours: int, max_days_ahead: int, meeting_link: string, page_id: int}
 */
function clientoctopus_booking_settings(): array {
	return [
		'enabled'          => (bool) get_option( 'clientoctopus_booking_enabled', false ),
		'duration'         => max( 5, (int) get_option( 'clientoctopus_booking_duration', 30 ) ),
		'buffer'           => max( 0, (int) get_option( 'clientoctopus_booking_buffer', 15 ) ),
		'min_notice_hours' => max( 0, (int) get_option( 'clientoctopus_booking_min_notice_hours', 24 ) ),
		'max_days_ahead'   => max( 1, (int) get_option( 'clientoctopus_booking_max_days_ahead', 30 ) ),
		'meeting_link'     => (string) get_option( 'clientoctopus_booking_meeting_link', '' ),
		'page_id'          => (int) get_option( 'clientoctopus_booking_page_id', 0 ),
	];
}

/**
 * Pro/Agency plan gate, matching rest-api/analytics.php's exact pattern —
 * every handler below (public and admin) calls this. Previously nothing in
 * this file checked the actual plan, only the plain "enabled" toggle and
 * (for admin routes) the manage_clientoctopus capability — neither of which
 * is a Freemius plan check, so a site running the premium code bundle
 * without an active Pro/Agency plan got fully working Booking.
 *
 * @param int  $owner_id
 * @param bool $admin Admin routes get a 403 upgrade prompt; public routes
 *                    get the same generic 404 already used for "disabled".
 */
function clientoctopus_booking_plan_error( int $owner_id, bool $admin = false ): ?WP_Error {
	if ( clientoctopus_can_user( $owner_id, 'use_booking' ) ) {
		return null;
	}
	if ( $admin ) {
		return new WP_Error( 'upgrade_required', __( 'Booking requires a Pro or Agency plan.', 'clientoctopus' ), [ 'status' => 403 ] );
	}
	return new WP_Error( 'not_configured', __( 'Booking is not available.', 'clientoctopus' ), [ 'status' => 404 ] );
}

/**
 * Compute open UTC slot start-times for one calendar date (interpreted in the
 * site's configured timezone — wp_timezone() handles both the timezone_string
 * and legacy gmt_offset cases, so this deliberately doesn't add a second,
 * potentially conflicting timezone setting of its own).
 *
 * @param string $date_ymd 'Y-m-d', interpreted in the site timezone.
 * @return DateTime[] Slot start times, UTC.
 */
function clientoctopus_booking_compute_slots( string $date_ymd, int $owner_id ): array {
	$settings = clientoctopus_booking_settings();
	$tz       = wp_timezone();
	$utc      = new DateTimeZone( 'UTC' );

	$day = DateTime::createFromFormat( 'Y-m-d', $date_ymd, $tz );
	if ( ! $day ) {
		return [];
	}
	$day->setTime( 0, 0, 0 );

	$now      = new DateTime( 'now', $utc );
	$max_date = ( clone $now )->modify( "+{$settings['max_days_ahead']} days" );
	if ( $day > $max_date ) {
		return [];
	}

	$day_key = strtolower( $day->format( 'D' ) ); // 'mon'..'sun'
	$hours   = clientoctopus_booking_weekly_hours();
	if ( empty( $hours[ $day_key ]['enabled'] ) ) {
		return [];
	}

	[ $start_h, $start_m ] = array_map( 'intval', explode( ':', $hours[ $day_key ]['start'] ) );
	[ $end_h, $end_m ]     = array_map( 'intval', explode( ':', $hours[ $day_key ]['end'] ) );

	$slot_start = ( clone $day )->setTime( $start_h, $start_m );
	$day_end    = ( clone $day )->setTime( $end_h, $end_m );
	$step       = ( $settings['duration'] + $settings['buffer'] ) * MINUTE_IN_SECONDS;
	$min_start  = ( clone $now )->modify( "+{$settings['min_notice_hours']} hours" );

	// Existing confirmed bookings for this owner on this date — exact-match
	// exclusion since every slot boundary is a fixed step from day start.
	global $wpdb;
	$day_start_utc = ( clone $slot_start )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	$day_end_utc   = ( clone $day_end )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	$booked        = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT scheduled_at FROM {$wpdb->prefix}clientoctopus_bookings
			 WHERE owner_id = %d AND status = 'confirmed' AND scheduled_at BETWEEN %s AND %s",
			$owner_id,
			$day_start_utc,
			$day_end_utc
		)
	);
	$booked = array_flip( $booked );

	// Blocked ranges (appointments/days off/holidays) overlapping this date —
	// a range-overlap test, not exact-match, since a block rarely lands on a
	// slot boundary. Scoped to this day's own start/end so a multi-week
	// holiday doesn't require scanning unrelated rows on every date computed.
	$blocks = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT starts_at, ends_at FROM {$wpdb->prefix}clientoctopus_booking_blocks
			 WHERE owner_id = %d AND ends_at > %s AND starts_at < %s",
			$owner_id,
			$day_start_utc,
			$day_end_utc
		),
		ARRAY_A
	);

	$slots = [];
	while ( $slot_start < $day_end ) {
		$slot_end = ( clone $slot_start )->modify( "+{$settings['duration']} minutes" );
		if ( $slot_end > $day_end ) {
			break;
		}

		$slot_utc     = ( clone $slot_start )->setTimezone( $utc );
		$slot_end_utc = ( clone $slot_end )->setTimezone( $utc );

		$is_blocked = false;
		foreach ( $blocks as $block ) {
			if ( $slot_utc->format( 'Y-m-d H:i:s' ) < $block['ends_at'] && $slot_end_utc->format( 'Y-m-d H:i:s' ) > $block['starts_at'] ) {
				$is_blocked = true;
				break;
			}
		}

		if ( $slot_utc >= $min_start && ! $is_blocked && ! isset( $booked[ $slot_utc->format( 'Y-m-d H:i:s' ) ] ) ) {
			$slots[] = $slot_utc;
		}

		$slot_start->modify( "+{$step} seconds" );
	}

	return $slots;
}

// ── Route registration ────────────────────────────────────────────────────

add_action( 'rest_api_init', static function (): void {
	$ns = 'clientoctopus/v1';

	register_rest_route( $ns, '/booking/availability/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_booking_availability',
		'permission_callback' => '__return_true',
		'args'                => [
			'date' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	register_rest_route( $ns, '/booking/submit/', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'clientoctopus_rest_booking_submit',
		'permission_callback' => '__return_true',
		'args'                => [
			'name'            => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			'email'           => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
			'phone'           => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
			'message'         => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ],
			'scheduled_at'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ], // UTC ISO8601 from the slot picker
			'website'         => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ], // honeypot
			'rendered_at'     => [ 'type' => 'integer', 'required' => false, 'default' => 0 ], // time-trap
		],
	] );

	register_rest_route( $ns, '/booking/cancel/(?P<token>[a-zA-Z0-9]+)/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_booking_cancel',
		'permission_callback' => '__return_true',
		'args'                => [ 'token' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ] ],
	] );

	register_rest_route( $ns, '/bookings/', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'clientoctopus_rest_list_bookings',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'status'   => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
			'search'   => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
			'page'     => [ 'type' => 'integer', 'required' => false, 'default' => 1, 'minimum' => 1 ],
			'per_page' => [ 'type' => 'integer', 'required' => false, 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
		],
	] );

	register_rest_route( $ns, '/bookings/(?P<id>\d+)/', [
		'methods'             => 'PATCH',
		'callback'            => 'clientoctopus_rest_update_booking',
		'permission_callback' => 'clientoctopus_rest_require_manage',
		'args'                => [
			'id'     => [ 'type' => 'integer', 'required' => true ],
			'status' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	// ── Blocked time (appointments/days off/holidays) — admin-only, no
	// public route; visitors only ever see the resulting reduced availability.
	register_rest_route( $ns, '/booking/blocks/', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'clientoctopus_rest_list_booking_blocks',
			'permission_callback' => 'clientoctopus_rest_require_manage',
		],
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'clientoctopus_rest_create_booking_block',
			'permission_callback' => 'clientoctopus_rest_require_manage',
			'args'                => [
				'label'     => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
				'starts_at' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				'ends_at'   => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		],
	] );

	register_rest_route( $ns, '/booking/blocks/(?P<id>\d+)/', [
		[
			'methods'             => 'PATCH',
			'callback'            => 'clientoctopus_rest_update_booking_block',
			'permission_callback' => 'clientoctopus_rest_require_manage',
			'args'                => [
				'id'        => [ 'type' => 'integer', 'required' => true ],
				'label'     => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
				'starts_at' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				'ends_at'   => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		],
		[
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'clientoctopus_rest_delete_booking_block',
			'permission_callback' => 'clientoctopus_rest_require_manage',
			'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
		],
	] );
} );

// ── Public handlers ─────────────────────────────────────────────────────────

function clientoctopus_rest_booking_availability( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$owner_id = (int) get_option( 'clientoctopus_owner_user_id', 0 );
	$settings = clientoctopus_booking_settings();
	if ( ! $owner_id || ! $settings['enabled'] ) {
		return new WP_Error( 'not_configured', __( 'Booking is not available.', 'clientoctopus' ), [ 'status' => 404 ] );
	}
	$plan_error = clientoctopus_booking_plan_error( $owner_id );
	if ( $plan_error ) {
		return $plan_error;
	}

	// Read-only, but still worth throttling — no legitimate visitor needs to
	// hammer this beyond clicking around a few months of calendar days.
	$ip = clientoctopus_get_client_ip();
	if ( ! clientoctopus_rest_rate_limit( 'booking_availability', abs( crc32( $ip ) ), 60, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'clientoctopus' ), [ 'status' => 429 ] );
	}

	$date = (string) $request->get_param( 'date' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return new WP_Error( 'invalid_date', __( 'Invalid date.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$slots = clientoctopus_booking_compute_slots( $date, $owner_id );

	return new WP_REST_Response( [
		'date'     => $date,
		'duration' => $settings['duration'],
		'slots'    => array_map( static fn( DateTime $d ) => $d->format( 'Y-m-d\TH:i:s\Z' ), $slots ),
	], 200 );
}

function clientoctopus_rest_booking_submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = (int) get_option( 'clientoctopus_owner_user_id', 0 );
	$settings = clientoctopus_booking_settings();
	if ( ! $owner_id || ! $settings['enabled'] ) {
		return new WP_Error( 'not_configured', __( 'Booking is not available.', 'clientoctopus' ), [ 'status' => 404 ] );
	}
	$plan_error = clientoctopus_booking_plan_error( $owner_id );
	if ( $plan_error ) {
		return $plan_error;
	}

	// Honeypot + time-trap — same enumeration-safe pattern as lead capture.
	$honeypot    = (string) $request->get_param( 'website' );
	$rendered_at = (int) $request->get_param( 'rendered_at' );
	if ( '' !== $honeypot || ( $rendered_at && ( time() - $rendered_at ) < 2 ) ) {
		return new WP_REST_Response( [ 'success' => true ], 201 );
	}

	$ip = clientoctopus_get_client_ip();
	if ( ! clientoctopus_rest_rate_limit( 'booking_submit', abs( crc32( $ip ) ), 5, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'clientoctopus' ), [ 'status' => 429 ] );
	}

	$name = trim( (string) $request->get_param( 'name' ) );
	if ( '' === $name ) {
		return new WP_Error( 'missing_name', __( 'Name is required.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$email = trim( (string) $request->get_param( 'email' ) );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$scheduled_at_raw = (string) $request->get_param( 'scheduled_at' );
	try {
		$scheduled_at = new DateTime( $scheduled_at_raw, new DateTimeZone( 'UTC' ) );
	} catch ( Exception $e ) {
		return new WP_Error( 'invalid_slot', __( 'Invalid time slot.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	// Re-derive that the requested slot is actually still open server-side —
	// never trust a client-submitted timestamp alone.
	$date_key   = ( clone $scheduled_at )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	$open_slots = clientoctopus_booking_compute_slots( $date_key, $owner_id );
	$still_open = false;
	foreach ( $open_slots as $slot ) {
		if ( $slot->format( 'Y-m-d H:i:s' ) === $scheduled_at->format( 'Y-m-d H:i:s' ) ) {
			$still_open = true;
			break;
		}
	}
	if ( ! $still_open ) {
		return new WP_Error( 'slot_unavailable', __( 'That time is no longer available. Please pick another.', 'clientoctopus' ), [ 'status' => 409 ] );
	}

	$phone   = mb_substr( trim( (string) $request->get_param( 'phone' ) ), 0, 50 );
	$message = mb_substr( trim( (string) $request->get_param( 'message' ) ), 0, 2000 );
	$now     = current_time( 'mysql' );

	$inserted = $wpdb->insert(
		$wpdb->prefix . 'clientoctopus_bookings',
		[
			'owner_id'         => $owner_id,
			'name'             => mb_substr( $name, 0, 255 ),
			'email'            => $email,
			'phone'            => $phone ?: null,
			'message'          => $message ?: null,
			'scheduled_at'     => $scheduled_at->format( 'Y-m-d H:i:s' ),
			'duration_minutes' => $settings['duration'],
			'status'           => 'confirmed',
			'cancel_token'     => wp_generate_password( 40, false, false ),
			'created_at'       => $now,
			'updated_at'       => $now,
		]
	);

	// The owner_slot UNIQUE KEY catches a genuine double-booking race here —
	// two submissions for the exact same slot within milliseconds — as a
	// clean duplicate-key failure rather than needing application locking.
	if ( ! $inserted ) {
		return new WP_Error( 'slot_unavailable', __( 'That time was just taken. Please pick another.', 'clientoctopus' ), [ 'status' => 409 ] );
	}

	$booking_id = $wpdb->insert_id;

	/**
	 * Fires after a booking is successfully created — confirmation email,
	 * owner notification, and reminder scheduling all hook this.
	 *
	 * @param int $booking_id
	 * @param int $owner_id
	 */
	do_action( 'clientoctopus_booking_created', $booking_id, $owner_id );

	return new WP_REST_Response( [ 'success' => true ], 201 );
}

function clientoctopus_rest_booking_cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$token = (string) $request->get_param( 'token' );

	$booking = $wpdb->get_row(
		$wpdb->prepare( "SELECT id, owner_id, status FROM {$wpdb->prefix}clientoctopus_bookings WHERE cancel_token = %s", $token ),
		ARRAY_A
	);
	if ( ! $booking ) {
		return new WP_Error( 'not_found', __( 'Booking not found.', 'clientoctopus' ), [ 'status' => 404 ] );
	}

	if ( 'cancelled' !== $booking['status'] ) {
		$wpdb->update(
			$wpdb->prefix . 'clientoctopus_bookings',
			[ 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => (int) $booking['id'] ]
		);
		do_action( 'clientoctopus_booking_cancelled', (int) $booking['id'], (int) $booking['owner_id'] );
	}

	return new WP_REST_Response( [ 'success' => true ], 200 );
}

// ── Admin handlers ─────────────────────────────────────────────────────────

function clientoctopus_rest_list_bookings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );

	$plan_error = clientoctopus_booking_plan_error( $owner_id, true );
	if ( $plan_error ) {
		return $plan_error;
	}

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
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = $wpdb->prepare( '(name LIKE %s OR email LIKE %s)', $like, $like );
	}
	$where_sql = implode( ' AND ', $where );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql components are individually prepared above.
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}clientoctopus_bookings WHERE {$where_sql}" );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql components are individually prepared above.
			"SELECT * FROM {$wpdb->prefix}clientoctopus_bookings WHERE {$where_sql} ORDER BY scheduled_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		),
		ARRAY_A
	);

	foreach ( $rows as &$row ) {
		$row['id']       = (int) $row['id'];
		$row['owner_id'] = (int) $row['owner_id'];
		$row['lead_id']  = $row['lead_id'] ? (int) $row['lead_id'] : null;
	}

	$counts = [ 'all' => 0, 'confirmed' => 0, 'cancelled' => 0 ];
	$count_rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$wpdb->prefix}clientoctopus_bookings WHERE owner_id = %d GROUP BY status", $owner_id )
	);
	foreach ( $count_rows as $count_row ) {
		if ( isset( $counts[ $count_row->status ] ) ) {
			$counts[ $count_row->status ] = (int) $count_row->c;
		}
		$counts['all'] += (int) $count_row->c;
	}

	return new WP_REST_Response( [
		'bookings' => $rows,
		'total'    => $total,
		'page'     => $page,
		'per_page' => $per_page,
		'counts'   => $counts,
	], 200 );
}

function clientoctopus_rest_update_booking( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );

	$plan_error = clientoctopus_booking_plan_error( $owner_id, true );
	if ( $plan_error ) {
		return $plan_error;
	}

	$id       = (int) $request->get_param( 'id' );
	$status   = (string) $request->get_param( 'status' );

	if ( ! in_array( $status, [ 'confirmed', 'cancelled' ], true ) ) {
		return new WP_Error( 'invalid_status', __( 'Invalid status.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$updated = $wpdb->update(
		$wpdb->prefix . 'clientoctopus_bookings',
		[ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
		[ 'id' => $id, 'owner_id' => $owner_id ]
	);

	if ( false === $updated ) {
		return new WP_Error( 'db_error', __( 'Could not update booking.', 'clientoctopus' ), [ 'status' => 500 ] );
	}

	if ( 'cancelled' === $status ) {
		do_action( 'clientoctopus_booking_cancelled', $id, $owner_id );
	}

	$booking = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_bookings WHERE id = %d AND owner_id = %d", $id, $owner_id ),
		ARRAY_A
	);

	return new WP_REST_Response( [ 'booking' => $booking ], 200 );
}

// ── Blocked time (appointments/days off/holidays) ──────────────────────────

/**
 * Parses and validates a starts_at/ends_at pair from a request. The admin UI
 * sends plain "Y-m-d\TH:i" wall-clock strings with no timezone marker — these
 * are interpreted as the site's configured timezone (wp_timezone()), the
 * same convention already used for the weekly-hours schedule, rather than
 * the admin's own browser timezone or raw UTC. Converted to UTC here for
 * storage/comparison, matching clientoctopus_bookings.scheduled_at.
 *
 * @return array{0: DateTime, 1: DateTime}|WP_Error
 */
function clientoctopus_parse_block_range( WP_REST_Request $request ): array|WP_Error {
	$tz  = wp_timezone();
	$utc = new DateTimeZone( 'UTC' );

	try {
		$starts_at = new DateTime( (string) $request->get_param( 'starts_at' ), $tz );
		$ends_at   = new DateTime( (string) $request->get_param( 'ends_at' ), $tz );
	} catch ( Exception $e ) {
		return new WP_Error( 'invalid_range', __( 'Invalid start/end time.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	$starts_at->setTimezone( $utc );
	$ends_at->setTimezone( $utc );

	if ( $ends_at <= $starts_at ) {
		return new WP_Error( 'invalid_range', __( 'End time must be after the start time.', 'clientoctopus' ), [ 'status' => 422 ] );
	}

	return [ $starts_at, $ends_at ];
}

function clientoctopus_rest_list_booking_blocks( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );

	$plan_error = clientoctopus_booking_plan_error( $owner_id, true );
	if ( $plan_error ) {
		return $plan_error;
	}

	$blocks = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}clientoctopus_booking_blocks WHERE owner_id = %d AND ends_at >= %s ORDER BY starts_at ASC",
			$owner_id,
			current_time( 'mysql', true )
		),
		ARRAY_A
	);

	$tz = wp_timezone();
	foreach ( $blocks as &$block ) {
		$block['id']       = (int) $block['id'];
		$block['owner_id'] = (int) $block['owner_id'];

		// Site-local wall-clock strings, pre-converted server-side so the
		// admin UI can bind them directly into <input type="datetime-local">
		// for both display and edit-prefill without doing any timezone math
		// of its own (matches the "server owns timezone conversion" approach
		// used throughout the rest of this feature).
		$block['starts_at_local'] = ( new DateTime( $block['starts_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d\TH:i' );
		$block['ends_at_local']   = ( new DateTime( $block['ends_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d\TH:i' );
	}

	return new WP_REST_Response( [ 'blocks' => $blocks ], 200 );
}

function clientoctopus_rest_create_booking_block( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );

	$plan_error = clientoctopus_booking_plan_error( $owner_id, true );
	if ( $plan_error ) {
		return $plan_error;
	}

	$range    = clientoctopus_parse_block_range( $request );
	if ( is_wp_error( $range ) ) {
		return $range;
	}
	[ $starts_at, $ends_at ] = $range;

	$inserted = $wpdb->insert(
		$wpdb->prefix . 'clientoctopus_booking_blocks',
		[
			'owner_id'   => $owner_id,
			'label'      => mb_substr( trim( (string) $request->get_param( 'label' ) ), 0, 255 ) ?: null,
			'starts_at'  => $starts_at->format( 'Y-m-d H:i:s' ),
			'ends_at'    => $ends_at->format( 'Y-m-d H:i:s' ),
			'created_at' => current_time( 'mysql' ),
		]
	);

	if ( ! $inserted ) {
		return new WP_Error( 'db_error', __( 'Could not save this block.', 'clientoctopus' ), [ 'status' => 500 ] );
	}

	return new WP_REST_Response( [ 'success' => true, 'id' => $wpdb->insert_id ], 201 );
}

function clientoctopus_rest_update_booking_block( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );

	$plan_error = clientoctopus_booking_plan_error( $owner_id, true );
	if ( $plan_error ) {
		return $plan_error;
	}

	$id     = (int) $request->get_param( 'id' );
	$source = $wpdb->get_var( $wpdb->prepare( "SELECT source FROM {$wpdb->prefix}clientoctopus_booking_blocks WHERE id = %d AND owner_id = %d", $id, $owner_id ) );
	if ( $source && 'manual' !== $source ) {
		return new WP_Error( 'block_managed_externally', __( 'This block is managed by a connected calendar — edit the event there instead.', 'clientoctopus' ), [ 'status' => 403 ] );
	}

	$range    = clientoctopus_parse_block_range( $request );
	if ( is_wp_error( $range ) ) {
		return $range;
	}
	[ $starts_at, $ends_at ] = $range;

	$updated = $wpdb->update(
		$wpdb->prefix . 'clientoctopus_booking_blocks',
		[
			'label'     => mb_substr( trim( (string) $request->get_param( 'label' ) ), 0, 255 ) ?: null,
			'starts_at' => $starts_at->format( 'Y-m-d H:i:s' ),
			'ends_at'   => $ends_at->format( 'Y-m-d H:i:s' ),
		],
		[ 'id' => $id, 'owner_id' => $owner_id ]
	);

	if ( false === $updated ) {
		return new WP_Error( 'db_error', __( 'Could not update this block.', 'clientoctopus' ), [ 'status' => 500 ] );
	}

	return new WP_REST_Response( [ 'success' => true ], 200 );
}

function clientoctopus_rest_delete_booking_block( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $wpdb;

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );

	$plan_error = clientoctopus_booking_plan_error( $owner_id, true );
	if ( $plan_error ) {
		return $plan_error;
	}

	$id     = (int) $request->get_param( 'id' );
	$source = $wpdb->get_var( $wpdb->prepare( "SELECT source FROM {$wpdb->prefix}clientoctopus_booking_blocks WHERE id = %d AND owner_id = %d", $id, $owner_id ) );
	if ( $source && 'manual' !== $source ) {
		return new WP_Error( 'block_managed_externally', __( 'This block is managed by a connected calendar — disconnect it in Settings instead of deleting individual events.', 'clientoctopus' ), [ 'status' => 403 ] );
	}

	$deleted = $wpdb->delete( $wpdb->prefix . 'clientoctopus_booking_blocks', [ 'id' => $id, 'owner_id' => $owner_id ] );

	if ( ! $deleted ) {
		return new WP_Error( 'not_found', __( 'Block not found.', 'clientoctopus' ), [ 'status' => 404 ] );
	}

	return new WP_REST_Response( [ 'success' => true ], 200 );
}
