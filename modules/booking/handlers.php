<?php
/**
 * Call Booking Email + Reminder Handlers (Pro/Agency)
 *
 * Hooked to clientoctopus_booking_created (rest-api/booking.php):
 *   - Visitor confirmation email (date/time in their timezone, meeting link,
 *     .ics attachment, Google Calendar link, cancel link)
 *   - Owner notification email
 * Hooked to clientoctopus_booking_cancelled:
 *   - Owner notification that a booking was cancelled
 * Cron (clientoctopus_15min, reusing the interval already registered for
 * clientoctopus_sync_pending_payments — no new interval needed):
 *   - 1-hour-out reminder email to the visitor
 * Cron (clientoctopus_daily_automations, reusing the existing daily cron —
 * same "housekeeping on the daily cron" pattern as
 * clientoctopus_lead_run_auto_archive() in modules/leads/archive.php):
 *   - Sweep clientoctopus_booking_blocks rows once they're in the past
 *
 * @package ClientOctopus
 * @since   1.4.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table queries; table name built from $wpdb->prefix with a hardcoded slug, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a minimal VCALENDAR/VEVENT block. No external library — this is
 * plain text templating, not a calendar API integration.
 */
function clientoctopus_generate_ics( string $uid, DateTime $start_utc, int $duration_minutes, string $summary, string $description ): string {
	$end_utc = ( clone $start_utc )->modify( "+{$duration_minutes} minutes" );
	$fmt     = 'Ymd\THis\Z';

	// Normalize every newline form to a bare \n *before* escaping — a raw \r
	// (from a Windows-style \r\n, or on its own) would otherwise survive
	// untouched, since only \n is escaped below. RFC 5545 requires CRLF line
	// termination, but some real-world parsers are lenient enough to treat a
	// lone \r as its own line break too — a booking's name/message field is
	// public, unauthenticated form input, so a bare \r reaching this output
	// unescaped could let an attacker smuggle extra fabricated iCalendar
	// property lines into an event synced to the real business owner's
	// calendar (see clientoctopus_calendar_push_booking_event()).
	$escape = static fn( string $s ): string => str_replace(
		[ "\\", ",", ";", "\n" ],
		[ "\\\\", "\\,", "\\;", "\\n" ],
		str_replace( [ "\r\n", "\r" ], "\n", $s )
	);

	return implode( "\r\n", [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Client Octopus//Booking//EN',
		'BEGIN:VEVENT',
		'UID:' . $uid . '@clientoctopus',
		'DTSTAMP:' . gmdate( $fmt ),
		'DTSTART:' . $start_utc->format( $fmt ),
		'DTEND:' . $end_utc->format( $fmt ),
		'SUMMARY:' . $escape( $summary ),
		'DESCRIPTION:' . $escape( $description ),
		'END:VEVENT',
		'END:VCALENDAR',
	] ) . "\r\n";
}

/**
 * A one-click "add to Google Calendar" link — just a URL, no OAuth/API call.
 */
function clientoctopus_google_calendar_url( DateTime $start_utc, int $duration_minutes, string $summary, string $description ): string {
	$end_utc = ( clone $start_utc )->modify( "+{$duration_minutes} minutes" );
	return add_query_arg( [
		'action'  => 'TEMPLATE',
		'text'    => rawurlencode( $summary ),
		'dates'   => $start_utc->format( 'Ymd\THis\Z' ) . '/' . $end_utc->format( 'Ymd\THis\Z' ),
		'details' => rawurlencode( $description ),
	], 'https://calendar.google.com/calendar/render' );
}

// ── Visitor confirmation + owner notification ──────────────────────────────

add_action( 'clientoctopus_booking_created', static function ( int $booking_id, int $owner_id ): void {
	global $wpdb;

	$booking = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_bookings WHERE id = %d", $booking_id ),
		ARRAY_A
	);
	if ( ! $booking ) {
		return;
	}

	$owner = get_userdata( $owner_id );
	if ( ! $owner ) {
		return;
	}

	$business_name = get_option( 'clientoctopus_business_name', get_bloginfo( 'name' ) );
	$from_name     = get_option( 'clientoctopus_from_name', $business_name );
	$from_email    = get_option( 'clientoctopus_from_email', get_option( 'admin_email', '' ) );
	$meeting_link  = (string) get_option( 'clientoctopus_booking_meeting_link', '' );

	$start_utc = new DateTime( $booking['scheduled_at'], new DateTimeZone( 'UTC' ) );
	$duration  = (int) $booking['duration_minutes'];
	/* translators: %s is the business name */
	$summary = sprintf( __( 'Call with %s', 'clientoctopus' ), $business_name );

	// ── Visitor confirmation ──────────────────────────────────────────────
	$local_start = clone $start_utc; // formatted client-side in the widget; email uses site timezone as the one authoritative reference.
	$local_start->setTimezone( wp_timezone() );

	$cancel_url = home_url( '/wp-json/clientoctopus/v1/booking/cancel/' . rawurlencode( $booking['cancel_token'] ) . '/' );
	$page_id    = (int) get_option( 'clientoctopus_booking_page_id', 0 );
	if ( $page_id && get_permalink( $page_id ) ) {
		$cancel_url = add_query_arg( 'cancel', $booking['cancel_token'], get_permalink( $page_id ) );
	}

	$confirmation_subject = (string) get_option( 'clientoctopus_booking_confirmation_subject', '' ) ?: __( 'Booking confirmed', 'clientoctopus' );
	$confirmation_note    = (string) get_option( 'clientoctopus_booking_confirmation_body', '' );

	$body_html = '<p style="margin:0 0 12px;font-size:16px;color:#374151;line-height:1.65;">';
	/* translators: 1: date/time, 2: business name */
	$body_html .= sprintf(
		esc_html__( "You're booked for %1\$s with %2\$s.", 'clientoctopus' ),
		'<strong>' . esc_html( $local_start->format( 'l, j F Y \a\t g:ia' ) ) . '</strong>',
		esc_html( $business_name )
	) . '</p>';
	if ( $confirmation_note ) {
		$body_html .= '<p style="margin:0 0 12px;font-size:16px;color:#374151;line-height:1.65;">' . nl2br( esc_html( $confirmation_note ) ) . '</p>';
	}
	if ( $meeting_link ) {
		$body_html .= '<p style="margin:0 0 12px;font-size:16px;color:#374151;line-height:1.65;">' .
			sprintf(
				/* translators: %s is the meeting link URL */
				esc_html__( 'Join here: %s', 'clientoctopus' ),
				'<a href="' . esc_url( $meeting_link ) . '">' . esc_html( $meeting_link ) . '</a>'
			) . '</p>';
	}
	$body_html .= '<p style="margin:0;font-size:14px;color:#6B7280;line-height:1.5;">' .
		sprintf(
			/* translators: %s is the cancel link URL */
			esc_html__( 'Need to cancel? %s', 'clientoctopus' ),
			'<a href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancel this booking', 'clientoctopus' ) . '</a>'
		) . '</p>';

	$description = $meeting_link ? sprintf( /* translators: %s meeting link */ __( 'Meeting link: %s', 'clientoctopus' ), $meeting_link ) : '';

	$message = clientoctopus_email_html( [
		'subject'   => $confirmation_subject,
		'name'      => $booking['name'],
		'body'      => $body_html,
		'cta_label' => __( 'Add to Google Calendar', 'clientoctopus' ),
		'cta_url'   => clientoctopus_google_calendar_url( $start_utc, $duration, $summary, $description ),
	] );

	$ics_content = clientoctopus_generate_ics( 'booking-' . $booking_id, $start_utc, $duration, $summary, $description );
	$ics_path    = wp_tempnam( 'clientoctopus-booking.ics' );
	file_put_contents( $ics_path, $ics_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a transient .ics attachment to a WP-provided temp path, not user-controlled.

	wp_mail(
		$booking['email'],
		wp_strip_all_tags( $confirmation_subject ),
		$message,
		[
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		],
		[ $ics_path ]
	);

	wp_delete_file( $ics_path );

	// ── Owner notification ──────────────────────────────────────────────
	$owner_body = '<p style="margin:0 0 12px;font-size:16px;color:#374151;line-height:1.65;">' .
		sprintf(
			/* translators: 1: visitor name, 2: date/time */
			esc_html__( '%1$s booked a call for %2$s.', 'clientoctopus' ),
			esc_html( $booking['name'] ),
			esc_html( $local_start->format( 'l, j F Y \a\t g:ia' ) )
		) . '</p>';

	$owner_message = clientoctopus_email_html( [
		'subject'   => __( 'New booking', 'clientoctopus' ),
		'name'      => $owner->display_name,
		'body'      => $owner_body,
		'cta_label' => __( 'View Bookings', 'clientoctopus' ),
		'cta_url'   => admin_url( 'admin.php?page=clientoctopus-bookings' ),
	] );

	wp_mail(
		$owner->user_email,
		__( 'New booking', 'clientoctopus' ),
		$owner_message,
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);
}, 10, 2 );

// ── Cancellation notice to the owner ────────────────────────────────────────

add_action( 'clientoctopus_booking_cancelled', static function ( int $booking_id, int $owner_id ): void {
	global $wpdb;

	$booking = $wpdb->get_row(
		$wpdb->prepare( "SELECT name, scheduled_at FROM {$wpdb->prefix}clientoctopus_bookings WHERE id = %d", $booking_id ),
		ARRAY_A
	);
	$owner = get_userdata( $owner_id );
	if ( ! $booking || ! $owner ) {
		return;
	}

	$local_start = ( new DateTime( $booking['scheduled_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() );

	$body = '<p style="margin:0;font-size:16px;color:#374151;line-height:1.65;">' .
		sprintf(
			/* translators: 1: visitor name, 2: date/time */
			esc_html__( '%1$s cancelled their booking for %2$s.', 'clientoctopus' ),
			esc_html( $booking['name'] ),
			esc_html( $local_start->format( 'l, j F Y \a\t g:ia' ) )
		) . '</p>';

	wp_mail(
		$owner->user_email,
		__( 'Booking cancelled', 'clientoctopus' ),
		clientoctopus_email_html( [
			'subject' => __( 'Booking cancelled', 'clientoctopus' ),
			'name'    => $owner->display_name,
			'body'    => $body,
		] ),
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);
}, 10, 2 );

// ── Reminder cron ────────────────────────────────────────────────────────

add_action( 'clientoctopus_send_booking_reminders', 'clientoctopus_cron_send_booking_reminders' );

function clientoctopus_cron_send_booking_reminders(): void {
	global $wpdb;

	// Window must be at least as wide as the clientoctopus_15min cron interval,
	// otherwise a booking's reminder time can fall entirely between two runs
	// and never get caught by either. reminder_sent_at IS NULL below prevents
	// duplicate sends on the runs that do overlap.
	$window_start = gmdate( 'Y-m-d H:i:s', time() + 50 * MINUTE_IN_SECONDS );
	$window_end   = gmdate( 'Y-m-d H:i:s', time() + 65 * MINUTE_IN_SECONDS );

	$bookings = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}clientoctopus_bookings
			 WHERE status = 'confirmed' AND reminder_sent_at IS NULL
			   AND scheduled_at BETWEEN %s AND %s",
			$window_start,
			$window_end
		),
		ARRAY_A
	);

	foreach ( $bookings as $booking ) {
		$business_name = get_option( 'clientoctopus_business_name', get_bloginfo( 'name' ) );
		$from_name     = get_option( 'clientoctopus_from_name', $business_name );
		$from_email    = get_option( 'clientoctopus_from_email', get_option( 'admin_email', '' ) );
		$meeting_link  = (string) get_option( 'clientoctopus_booking_meeting_link', '' );

		$local_start = ( new DateTime( $booking['scheduled_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() );

		$body = '<p style="margin:0 0 12px;font-size:16px;color:#374151;line-height:1.65;">' .
			sprintf(
				/* translators: %s is the call time */
				esc_html__( 'Just a reminder — your call is coming up at %s.', 'clientoctopus' ),
				'<strong>' . esc_html( $local_start->format( 'g:ia' ) ) . '</strong>'
			) . '</p>';
		if ( $meeting_link ) {
			$body .= '<p style="margin:0;font-size:16px;color:#374151;line-height:1.65;">' .
				sprintf(
					/* translators: %s is the meeting link URL */
					esc_html__( 'Join here: %s', 'clientoctopus' ),
					'<a href="' . esc_url( $meeting_link ) . '">' . esc_html( $meeting_link ) . '</a>'
				) . '</p>';
		}

		wp_mail(
			$booking['email'],
			__( 'Reminder: your call is in about an hour', 'clientoctopus' ),
			clientoctopus_email_html( [
				'subject' => __( 'Reminder: your call is in about an hour', 'clientoctopus' ),
				'name'    => $booking['name'],
				'body'    => $body,
			] ),
			[
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $from_name . ' <' . $from_email . '>',
			]
		);

		$wpdb->update(
			$wpdb->prefix . 'clientoctopus_bookings',
			[ 'reminder_sent_at' => current_time( 'mysql' ) ],
			[ 'id' => (int) $booking['id'] ]
		);
	}
}

// ── Expired block cleanup ────────────────────────────────────────────────

add_action( 'clientoctopus_daily_automations', 'clientoctopus_booking_cleanup_expired_blocks' );

/**
 * A block is only ever consulted for future availability, so a few hours'
 * lag between "the block ended" and "the row gets swept" (daily cron) has no
 * functional effect — this is pure tidiness, not correctness-critical.
 */
function clientoctopus_booking_cleanup_expired_blocks(): void {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}clientoctopus_booking_blocks WHERE ends_at < %s",
			current_time( 'mysql', true )
		)
	);
}
