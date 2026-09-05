<?php
/**
 * Calendar Sync (Pro/Agency)
 *
 * Reads busy time from the owner's connected Google/Microsoft/Apple calendar
 * and blocks those slots in Booking, and pushes confirmed bookings out as
 * real events on those same calendars — see the plan for the full design.
 *
 * Google/Microsoft: OAuth tokens are owned entirely by the co-ai-relay
 * server (same trust boundary as modules/ai/class-ai-service.php's AI
 * proxying) — this plugin never handles a Google/Microsoft token, only the
 * site's own existing `clientoctopus_license_key` to authenticate itself to
 * the relay, exactly like AI requests already do.
 *
 * Apple/iCloud: no OAuth exists for third-party calendar access, so this
 * talks directly to caldav.icloud.com using an app-specific password the
 * owner generates and pastes in (class-caldav-client.php).
 *
 * Synced busy periods are written into the EXISTING clientoctopus_booking_blocks
 * table (tagged with a `source` column) rather than a separate cache table,
 * so they block slots via the exact same clientoctopus_booking_compute_slots()
 * query that already reads that table, and are visible to the owner in the
 * same "Time Off" admin list instead of being an invisible side effect.
 *
 * @package ClientOctopus\CalendarSync
 * @since   1.5.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table queries; table variables use $wpdb->prefix with hardcoded slugs, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-caldav-client.php';

// ── OAuth-return handling (must happen before any admin HTML is output) ────

/**
 * Handles the ?calendar_connected=1 redirect Google/Microsoft/the relay sends
 * the browser back to after a successful OAuth connect. This has to run on
 * admin_init — before wp-admin has started rendering the page (menu, admin
 * bar, etc.) — not inside admin/views/settings.php's own render code. Doing
 * it there was too late: by the time that file runs, the surrounding admin
 * page has already sent HTML output, so wp_safe_redirect() could not
 * reliably take effect, leaving a blank content area (chrome rendered,
 * redirect silently failed, exit cut off everything after it) until a
 * manual page reload.
 */
add_action( 'admin_init', static function (): void {
	$_admin_page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
	if ( empty( $_GET['calendar_connected'] ) || 'clientoctopus-settings' !== $_admin_page ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Nonce generated in admin/views/settings.php's $cf_calendar_return_url via
	// wp_nonce_url() — without this, the sync below would run for anyone who
	// simply visited this URL, not just after a real completed OAuth flow.
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'clientoctopus_calendar_connected' ) ) {
		return;
	}

	$owner_id = clientoctopus_get_owner_id( get_current_user_id() );

	// The relay's OAuth callback already knows for a fact that this specific
	// provider just connected — mark it directly rather than relying on
	// immediately re-polling /calendar/busy to confirm it, which shares the
	// same per-key rate limit as a manual "Sync now" and can be exhausted by
	// exactly the kind of repeated testing that happens around a fresh
	// connect. Without this, a rate-limited poll here left the settings page
	// still showing "Connect" after a real, successful connection, with no
	// error and no indication anything had gone wrong — indistinguishable
	// from a genuine failure until something later (a manual refresh, or the
	// next cron tick) happened to re-sync successfully.
	$provider = sanitize_key( wp_unslash( $_GET['provider'] ?? '' ) );
	if ( in_array( $provider, [ 'google', 'microsoft' ], true ) ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}clientoctopus_calendar_connections (owner_id, provider, status, last_synced_at, created_at, updated_at)
			 VALUES (%d, %s, 'connected', %s, %s, %s)
			 ON DUPLICATE KEY UPDATE status = 'connected', last_synced_at = VALUES(last_synced_at), updated_at = VALUES(updated_at)",
			$owner_id,
			$provider,
			current_time( 'mysql' ),
			current_time( 'mysql' ),
			current_time( 'mysql' )
		) );
	}

	// Best-effort busy-period population — may legitimately be rate-limited
	// right after a fresh connect (see above); the "connected" status above
	// is already correct regardless of whether this succeeds.
	clientoctopus_calendar_sync_relay_providers( $owner_id );

	// The one-time success notice survives the redirect via a transient —
	// see $cf_calendar_just_connected in admin/views/settings.php.
	set_transient( 'clientoctopus_calendar_connected_notice_' . get_current_user_id(), 1, 30 );
	wp_safe_redirect( remove_query_arg( [ 'calendar_connected', 'provider' ] ) );
	exit;
} );

// ── Encryption (Apple app-specific password at rest) ────────────────────────

/**
 * AES-256-CBC keyed off this site's own auth salt — never leaves this
 * install, unlike the relay-owned Google/Microsoft tokens. Deliberately
 * actually encrypted, unlike the pre-existing (mislabeled) plaintext
 * Stripe/PayPal option storage elsewhere in this plugin.
 */
function clientoctopus_calendar_encrypt( string $plaintext ): string {
	$key        = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv         = random_bytes( 16 );
	$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
	return base64_encode( $iv . $ciphertext );
}

function clientoctopus_calendar_decrypt( string $encoded ): string {
	$raw = base64_decode( $encoded );
	if ( strlen( $raw ) < 17 ) {
		return '';
	}
	$iv         = substr( $raw, 0, 16 );
	$ciphertext = substr( $raw, 16 );
	$key        = hash( 'sha256', wp_salt( 'auth' ), true );
	return (string) openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
}

// ── Blocked-time helpers (shared by both sync directions) ──────────────────

/**
 * Replace all of one owner+source's rows in clientoctopus_booking_blocks with
 * a fresh set — manual rows and other providers' rows are untouched. Periods
 * that overlap one of the owner's own confirmed bookings are skipped: those
 * are events Calendar Sync itself already pushed out to this same calendar
 * (see clientoctopus_calendar_push_booking_event()), and re-importing them as
 * a "Busy" block would just duplicate a booking that's already listed as a
 * booking. Filtering by time-range overlap against bookings we already know
 * about locally works identically for every provider, with no per-provider
 * event-ID matching needed.
 *
 * Guarded by a non-blocking MySQL named lock scoped to this owner+source: if
 * a manual "Sync now" click and the 15-min cron ever land close enough
 * together to overlap, both would otherwise independently delete-then-insert
 * the same batch — the first call's DELETE clears the table before either
 * call's INSERTs run, so the surviving rows end up doubled rather than
 * replaced. The lock makes the second, overlapping call skip its own run
 * entirely instead of racing; the next sync (cron or button) catches up
 * normally, so skipping is harmless.
 *
 * @param array{0: DateTime, 1: DateTime, 2: string}[] $periods Start, end, event title.
 */
function clientoctopus_calendar_replace_blocks( int $owner_id, string $source, array $periods ): void {
	global $wpdb;

	// MySQL's GET_LOCK() namespace is global to the whole server, not scoped
	// per-database — on shared hosting, another site's ClientOctopus install
	// with the same owner_id/source could otherwise collide on this exact
	// name. $wpdb->prefix is normally unique per site, so it's included here
	// on top of the usual clientoctopus_ prefix.
	$lock_name = $wpdb->prefix . 'clientoctopus_cal_blocks_' . $owner_id . '_' . $source;
	if ( ! (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) ) {
		return; // Another sync for this owner+source is already in flight.
	}

	try {
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}clientoctopus_booking_blocks WHERE owner_id = %d AND source = %s",
			$owner_id,
			$source
		) );

		$utc           = new DateTimeZone( 'UTC' );
		$own_bookings  = $wpdb->get_results( $wpdb->prepare(
			"SELECT scheduled_at, duration_minutes FROM {$wpdb->prefix}clientoctopus_bookings WHERE owner_id = %d AND status = 'confirmed'",
			$owner_id
		), ARRAY_A );

		$generic_label = [
			'google'    => __( 'Busy (Google Calendar)', 'clientoctopus' ),
			'microsoft' => __( 'Busy (Microsoft 365)', 'clientoctopus' ),
			'apple'     => __( 'Busy (Apple Calendar)', 'clientoctopus' ),
		][ $source ] ?? __( 'Busy', 'clientoctopus' );

		$now = current_time( 'mysql' );
		foreach ( $periods as [ $starts_at, $ends_at, $title ] ) {
			$is_own_booking = false;
			foreach ( $own_bookings as $booking ) {
				$booking_start = new DateTime( $booking['scheduled_at'], $utc );
				$booking_end   = ( clone $booking_start )->modify( "+{$booking['duration_minutes']} minutes" );
				if ( $starts_at < $booking_end && $ends_at > $booking_start ) {
					$is_own_booking = true;
					break;
				}
			}
			if ( $is_own_booking ) {
				continue;
			}

			// Prefer the real event title (e.g. "Dentist Appointment") — some
			// events genuinely have no title, so fall back to the generic label.
			$label = mb_substr( trim( (string) $title ), 0, 255 ) ?: $generic_label;

			$wpdb->insert( $wpdb->prefix . 'clientoctopus_booking_blocks', [
				'owner_id'   => $owner_id,
				'source'     => $source,
				'label'      => $label,
				'starts_at'  => $starts_at->format( 'Y-m-d H:i:s' ),
				'ends_at'    => $ends_at->format( 'Y-m-d H:i:s' ),
				'created_at' => $now,
			] );
		}
	} finally {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}
}

// ── Google/Microsoft: poll the relay ─────────────────────────────────────────

/**
 * Ask the relay for this site's connected-provider status and current busy
 * periods in one call — reused both by the cron (to sync blocks) and by the
 * Settings page (to refresh "Connected" status right after the OAuth
 * redirect returns, without waiting for the next cron tick).
 *
 * @return array{connected: array<string,bool>, busy: array<int, array{provider: string, starts_at: string, ends_at: string}>, errors: array<string,string>}|WP_Error
 */
function clientoctopus_calendar_poll_relay( int $owner_id ): array|WP_Error {
	$relay_url = untrailingslashit( CLIENTOCTOPUS_AI_RELAY_URL );
	$relay_key = get_option( 'clientoctopus_license_key', '' );

	if ( ! $relay_key ) {
		return new WP_Error( 'relay_not_configured', __( 'Add your licence key in Settings before connecting a calendar.', 'clientoctopus' ) );
	}

	$response = wp_remote_post( $relay_url . '/wp-json/co-relay/v1/calendar/busy', [
		'timeout' => 20,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( [
			'relay_api_key'  => $relay_key,
			'max_days_ahead' => clientoctopus_booking_settings()['max_days_ahead'],
		] ),
	] );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'relay_unreachable', __( 'Could not reach the calendar relay server.', 'clientoctopus' ) );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || empty( $data['success'] ) ) {
		return new WP_Error( 'relay_invalid_response', $data['message'] ?? __( 'Invalid response from the calendar relay.', 'clientoctopus' ) );
	}

	return [
		'connected' => $data['connected'] ?? [],
		'busy'      => $data['busy'] ?? [],
		'errors'    => $data['errors'] ?? [],
	];
}

/**
 * Syncs Google + Microsoft blocks from the relay's response, and mirrors
 * "connected" status into the local clientoctopus_calendar_connections table
 * so the Settings page can render status without a live remote call on
 * every page load.
 *
 * @return true|WP_Error True on a clean sync; WP_Error if the relay call
 *                       itself failed (rate-limited, unreachable, etc.) or
 *                       came back with a per-provider error (e.g. a token
 *                       refresh failure) — the cron still treats this as
 *                       best-effort and just retries next tick, but a manual
 *                       "Sync now" click needs to know so it can tell the
 *                       owner instead of claiming success regardless.
 */
function clientoctopus_calendar_sync_relay_providers( int $owner_id ) {
	$result = clientoctopus_calendar_poll_relay( $owner_id );
	if ( is_wp_error( $result ) ) {
		return $result; // Best-effort for the cron caller — next tick tries again.
	}

	global $wpdb;
	$now = current_time( 'mysql' );

	foreach ( [ 'google', 'microsoft' ] as $provider ) {
		$is_connected = ! empty( $result['connected'][ $provider ] );

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}clientoctopus_calendar_connections (owner_id, provider, status, last_synced_at, created_at, updated_at)
			 VALUES (%d, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE status = VALUES(status), last_synced_at = VALUES(last_synced_at), updated_at = VALUES(updated_at)",
			$owner_id,
			$provider,
			$is_connected ? 'connected' : 'disconnected',
			$now,
			$now,
			$now
		) );

		if ( ! $is_connected ) {
			clientoctopus_calendar_replace_blocks( $owner_id, $provider, [] );
			continue;
		}

		$periods = [];
		$utc     = new DateTimeZone( 'UTC' );
		foreach ( $result['busy'] as $period ) {
			if ( ( $period['provider'] ?? '' ) !== $provider ) {
				continue;
			}
			try {
				// The relay's timestamps carry their own offset (e.g. Google
				// events return the event's local BST/GMT offset) — PHP's
				// DateTime ignores the $utc constructor arg whenever the
				// string already specifies one, so setTimezone() is needed
				// to actually force true UTC before clientoctopus_calendar_
				// replace_blocks() naively formats without an offset below.
				$periods[] = [
					( new DateTime( $period['starts_at'], $utc ) )->setTimezone( $utc ),
					( new DateTime( $period['ends_at'], $utc ) )->setTimezone( $utc ),
					(string) ( $period['title'] ?? '' ),
				];
			} catch ( Exception $e ) {
				continue;
			}
		}
		clientoctopus_calendar_replace_blocks( $owner_id, $provider, $periods );
	}

	if ( ! empty( $result['errors'] ) ) {
		return new WP_Error(
			'relay_provider_error',
			implode( ' ', array_map(
				static fn( string $provider, string $msg ): string => ucfirst( $provider ) . ': ' . $msg,
				array_keys( $result['errors'] ),
				array_values( $result['errors'] )
			) )
		);
	}

	return true;
}

// ── Apple: direct CalDAV ─────────────────────────────────────────────────────

function clientoctopus_calendar_get_apple_connection( int $owner_id ): ?array {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}clientoctopus_calendar_connections WHERE owner_id = %d AND provider = 'apple' AND status = 'connected'",
		$owner_id
	), ARRAY_A ) ?: null;
}

/**
 * @return ClientOctopus_CalDAV_Client|WP_Error WP_Error if the stored password
 *         can't be decrypted — e.g. this site's auth salt changed since it was
 *         saved, which would otherwise surface as a misleading "Apple rejected
 *         these credentials" 401 rather than the real cause.
 */
function clientoctopus_calendar_apple_client( array $connection ): ClientOctopus_CalDAV_Client|WP_Error {
	$encrypted = (string) $connection['apple_password_enc'];
	$password  = clientoctopus_calendar_decrypt( $encrypted );

	if ( $encrypted && ! $password ) {
		return new WP_Error(
			'calendar_decrypt_failed',
			__( "Your site's security keys changed since Apple Calendar was connected, so the saved app-specific password can no longer be read. Please reconnect Apple Calendar in Settings.", 'clientoctopus' )
		);
	}

	return new ClientOctopus_CalDAV_Client( (string) $connection['apple_username'], $password );
}

function clientoctopus_calendar_sync_apple( int $owner_id ): void {
	$connection = clientoctopus_calendar_get_apple_connection( $owner_id );
	if ( ! $connection ) {
		return;
	}

	$client = clientoctopus_calendar_apple_client( $connection );
	if ( is_wp_error( $client ) ) {
		return; // Surfaced to the owner via Settings status, not worth logging on every cron tick.
	}

	$meta          = json_decode( (string) $connection['provider_meta'], true ) ?: [];
	$calendar_url  = $meta['calendar_url'] ?? '';

	if ( ! $calendar_url ) {
		$discovered = $client->discover_calendar_url();
		if ( is_wp_error( $discovered ) ) {
			return; // Best-effort — next cron tick tries again.
		}
		$calendar_url = $discovered;

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'clientoctopus_calendar_connections',
			[ 'provider_meta' => wp_json_encode( [ 'calendar_url' => $calendar_url ] ) ],
			[ 'id' => (int) $connection['id'] ]
		);
	}

	$max_days_ahead = clientoctopus_booking_settings()['max_days_ahead'];
	$range_start    = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
	$range_end      = ( clone $range_start )->modify( "+{$max_days_ahead} days" );

	$periods = $client->get_busy_periods( $calendar_url, $range_start, $range_end );
	if ( is_wp_error( $periods ) ) {
		return; // Best-effort — next cron tick tries again.
	}

	clientoctopus_calendar_replace_blocks( $owner_id, 'apple', $periods );

	global $wpdb;
	$wpdb->update(
		$wpdb->prefix . 'clientoctopus_calendar_connections',
		[ 'last_synced_at' => current_time( 'mysql' ) ],
		[ 'id' => (int) $connection['id'] ]
	);
}

// ── Cron ──────────────────────────────────────────────────────────────────

// clientoctopus_15min is a registered cron *recurrence* (a "run every 15
// minutes" schedule), not itself a hook anything fires — the actual event is
// scheduled against the clientoctopus_calendar_sync_tick hook in
// clientoctopus.php (matching the pattern already used for booking reminders
// and pending-payment sync). Hooking clientoctopus_15min directly here would
// silently never run, since nothing ever schedules an event by that name.
add_action( 'clientoctopus_calendar_sync_tick', 'clientoctopus_cron_calendar_sync' );

function clientoctopus_cron_calendar_sync(): void {
	$owner_id = (int) get_option( 'clientoctopus_owner_user_id', 0 );
	if ( ! $owner_id || ! clientoctopus_can_user( $owner_id, 'use_calendar_sync' ) ) {
		return;
	}

	clientoctopus_calendar_sync_relay_providers( $owner_id );
	clientoctopus_calendar_sync_apple( $owner_id );
}

// ── Write-back: push confirmed bookings out, remove cancelled ones ─────────

/**
 * Pushes one confirmed booking out to every currently-connected calendar as a
 * real event. Used both for brand-new bookings (via the clientoctopus_booking_created
 * hook below) and for the one-off "Sync existing bookings" backfill
 * (clientoctopus_calendar_backfill_existing_bookings()) — same logic either way,
 * a booking doesn't care whether it's new or pre-existing.
 *
 * @return string[] Providers this booking did NOT get pushed to (rate-limited,
 *                   relay/provider API error, etc.) — empty means every
 *                   currently-connected provider got it. Previously this
 *                   returned void and every per-provider failure was a silent
 *                   `continue`, indistinguishable from "nothing to do" —
 *                   which is exactly how a Microsoft push briefly failed with
 *                   no indication anything had gone wrong, until a later
 *                   backfill run (which only re-attempts providers still
 *                   missing an event) happened to pick it up.
 */
function clientoctopus_calendar_push_booking_event( int $booking_id, int $owner_id ): array {
	if ( ! clientoctopus_can_user( $owner_id, 'use_calendar_sync' ) ) {
		return [];
	}

	$failed = [];

	global $wpdb;
	$booking = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_bookings WHERE id = %d", $booking_id ),
		ARRAY_A
	);
	if ( ! $booking ) {
		return [];
	}

	$business_name = get_option( 'clientoctopus_business_name', get_bloginfo( 'name' ) );
	$start_utc     = new DateTime( $booking['scheduled_at'], new DateTimeZone( 'UTC' ) );
	$summary       = sprintf(
		/* translators: 1: business name, 2: booked person's name */
		__( 'Call with %2$s (%1$s)', 'clientoctopus' ),
		$business_name,
		$booking['name']
	);
	// Same meeting-link line already sent to the customer in their own
	// confirmation email (see the clientoctopus_booking_created handler in
	// modules/booking/handlers.php) — without it, the owner's calendar event
	// had no way to show the meeting link at all.
	$meeting_link      = (string) get_option( 'clientoctopus_booking_meeting_link', '' );
	$description_parts = array_filter( [
		trim( (string) $booking['message'] ),
		$meeting_link ? sprintf( /* translators: %s is the meeting link URL */ __( 'Meeting link: %s', 'clientoctopus' ), $meeting_link ) : '',
	] );
	$description = implode( "\n\n", $description_parts );
	$uid         = 'co-booking-' . $booking_id;
	$ics_body    = clientoctopus_generate_ics( $uid, $start_utc, (int) $booking['duration_minutes'], $summary, $description );

	// Google/Microsoft — via the relay.
	$relay_key = get_option( 'clientoctopus_license_key', '' );
	if ( $relay_key ) {
		foreach ( [ 'google', 'microsoft' ] as $provider ) {
			$connected = $wpdb->get_var( $wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}clientoctopus_calendar_connections WHERE owner_id = %d AND provider = %s",
				$owner_id,
				$provider
			) );
			if ( 'connected' !== $connected ) {
				continue;
			}

			// Skip a provider this booking already has a tracked event on —
			// without this, calling this function again (e.g. the backfill
			// re-processing a booking after a second provider gets connected)
			// would create a genuine DUPLICATE event on a provider that
			// already has one, since the unique key on this table only stops
			// the local tracking row from being duplicated, not the real
			// Google/Microsoft API call that creates it.
			$already_synced = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}clientoctopus_booking_calendar_events WHERE booking_id = %d AND provider = %s",
				$booking_id,
				$provider
			) );
			if ( $already_synced ) {
				continue;
			}

			$response = wp_remote_post( untrailingslashit( CLIENTOCTOPUS_AI_RELAY_URL ) . '/wp-json/co-relay/v1/calendar/events', [
				'timeout' => 20,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'relay_api_key' => $relay_key,
					'provider'      => $provider,
					'starts_at'     => $start_utc->format( 'c' ),
					'duration_minutes' => (int) $booking['duration_minutes'],
					'summary'       => $summary,
					'description'   => $description,
					// Deliberately no attendee_email: Google/Microsoft both
					// auto-email attendees on event create AND delete, so
					// inviting the lead here would duplicate (or triplicate,
					// with more providers connected) the confirmation/
					// cancellation email Client Octopus already sends via its
					// own .ics-attached email (modules/booking/handlers.php)
					// — that's the one channel meant to notify the lead.
					// This event exists purely so the owner sees the booking
					// on their own calendar and it blocks their availability.
				] ),
			] );
			if ( is_wp_error( $response ) ) {
				$failed[] = $provider;
				continue;
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $data['success'] ) && ! empty( $data['external_event_id'] ) ) {
				$wpdb->insert( $wpdb->prefix . 'clientoctopus_booking_calendar_events', [
					'booking_id'        => $booking_id,
					'provider'          => $provider,
					'external_event_id' => $data['external_event_id'],
					'created_at'        => current_time( 'mysql' ),
				] );
			} else {
				$failed[] = $provider;
			}
		}
	}

	// Apple — direct CalDAV.
	$apple_already_synced = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}clientoctopus_booking_calendar_events WHERE booking_id = %d AND provider = 'apple'",
		$booking_id
	) );
	$apple_connection = $apple_already_synced ? null : clientoctopus_calendar_get_apple_connection( $owner_id );
	if ( $apple_connection ) {
		$meta = json_decode( (string) $apple_connection['provider_meta'], true ) ?: [];
		$client = ! empty( $meta['calendar_url'] ) ? clientoctopus_calendar_apple_client( $apple_connection ) : null;
		if ( $client && ! is_wp_error( $client ) ) {
			$event_url  = $client->create_event( $meta['calendar_url'], $uid, $ics_body );
			if ( is_wp_error( $event_url ) ) {
				$failed[] = 'apple';
			} else {
				$wpdb->insert( $wpdb->prefix . 'clientoctopus_booking_calendar_events', [
					'booking_id'        => $booking_id,
					'provider'          => 'apple',
					'external_event_id' => $event_url,
					'created_at'        => current_time( 'mysql' ),
				] );
			}
		} else {
			$failed[] = 'apple';
		}
	}

	return $failed;
}

add_action( 'clientoctopus_booking_created', 'clientoctopus_calendar_push_booking_event', 20, 2 );

/**
 * One-off "Sync existing bookings" backfill — pushes any still-upcoming
 * confirmed booking that predates the calendar connection (or predates a
 * newly-connected second provider) out to the calendar(s) connected today.
 * Safe to run more than once: only bookings with NO row at all in
 * clientoctopus_booking_calendar_events are considered, so anything already
 * pushed (by this backfill or by the normal creation hook) is never touched
 * again — running it twice in a row simply finds nothing left to do.
 *
 * @return array{processed: int, failed_providers: string[]} processed = bookings
 *         attempted; failed_providers = the unique set of providers that had
 *         at least one push fail during this run, so the caller can tell the
 *         owner "N synced, but Microsoft needs another try" instead of a bare
 *         count that reads as full success even when something didn't land.
 */
function clientoctopus_calendar_backfill_existing_bookings( int $owner_id ): array {
	global $wpdb;

	// A booking already synced to ONE connected provider (e.g. Google,
	// connected first) still needs processing once a SECOND provider (e.g.
	// Microsoft) gets connected later — "has at least one row" is not the
	// same as "synced to every currently-connected provider". Find bookings
	// missing a row for at least one currently-connected provider;
	// clientoctopus_calendar_push_booking_event() itself skips any
	// (booking, provider) pair that's already synced, so re-processing a
	// partially-synced booking here only fills the gap, never duplicates.
	$connected_providers = $wpdb->get_col( $wpdb->prepare(
		"SELECT provider FROM {$wpdb->prefix}clientoctopus_calendar_connections WHERE owner_id = %d AND status = 'connected'",
		$owner_id
	) );
	if ( ! $connected_providers ) {
		return [ 'processed' => 0, 'failed_providers' => [] ];
	}

	$placeholders = implode( ',', array_fill( 0, count( $connected_providers ), '%s' ) );
	$booking_ids  = $wpdb->get_col(
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is built from count($connected_providers), the same array passed into array_merge() below (one %s per provider), so the count always matches; PHPCS can't evaluate the dynamically-built placeholder string or the single-array calling form of prepare(), which WP core officially supports.
		$wpdb->prepare(
			"SELECT b.id FROM {$wpdb->prefix}clientoctopus_bookings b
			 WHERE b.owner_id = %d AND b.status = 'confirmed' AND b.scheduled_at > UTC_TIMESTAMP()
			   AND (
			       SELECT COUNT(DISTINCT e.provider) FROM {$wpdb->prefix}clientoctopus_booking_calendar_events e
			       WHERE e.booking_id = b.id AND e.provider IN ({$placeholders})
			   ) < %d",
			array_merge( [ $owner_id ], $connected_providers, [ count( $connected_providers ) ] )
		)
	);

	// The relay rate-limits /calendar/events to one call every 2 seconds per
	// connected provider (co_relay_calendar_rate_limited() in rest-api/calendar.php)
	// — a normal single new booking never hits this, but backfilling several
	// at once in a tight loop would, and a rate-limited push is silently
	// skipped by clientoctopus_calendar_push_booking_event() (same best-effort
	// posture as everywhere else in this feature). Pace the loop to stay under
	// that limit rather than silently losing pushes past the first one.
	$failed_providers = [];
	foreach ( $booking_ids as $i => $booking_id ) {
		if ( $i > 0 ) {
			sleep( 3 );
		}
		$failed_providers = array_merge( $failed_providers, clientoctopus_calendar_push_booking_event( (int) $booking_id, $owner_id ) );
	}

	return [
		'processed'        => count( $booking_ids ),
		'failed_providers' => array_values( array_unique( $failed_providers ) ),
	];
}

add_action( 'clientoctopus_booking_cancelled', static function ( int $booking_id, int $owner_id ): void {
	global $wpdb;

	$events = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}clientoctopus_booking_calendar_events WHERE booking_id = %d", $booking_id ),
		ARRAY_A
	);
	if ( ! $events ) {
		return;
	}

	$relay_key = get_option( 'clientoctopus_license_key', '' );

	foreach ( $events as $event ) {
		if ( in_array( $event['provider'], [ 'google', 'microsoft' ], true ) && $relay_key ) {
			wp_remote_request( untrailingslashit( CLIENTOCTOPUS_AI_RELAY_URL ) . '/wp-json/co-relay/v1/calendar/events/' . rawurlencode( $event['external_event_id'] ), [
				'method'  => 'DELETE',
				'timeout' => 20,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'relay_api_key' => $relay_key, 'provider' => $event['provider'] ] ),
			] );
		} elseif ( 'apple' === $event['provider'] ) {
			$connection = clientoctopus_calendar_get_apple_connection( $owner_id );
			$client     = $connection ? clientoctopus_calendar_apple_client( $connection ) : null;
			if ( $client && ! is_wp_error( $client ) ) {
				$client->delete_event( $event['external_event_id'] );
			}
		}

		$wpdb->delete( $wpdb->prefix . 'clientoctopus_booking_calendar_events', [ 'id' => (int) $event['id'] ] );
	}
}, 20, 2 );
