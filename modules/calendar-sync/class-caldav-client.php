<?php
/**
 * Minimal CalDAV client (Apple/iCloud calendar sync)
 *
 * Hand-rolled rather than a Composer dependency — matches the plugin's
 * existing precedent of hand-rolling small protocol pieces itself (see
 * clientoctopus_generate_ics() in modules/booking/handlers.php) instead of
 * adding a library for what's a fairly small, well-bounded need: discover
 * the owner's default calendar, read busy periods from it, and PUT/DELETE
 * a single event on it. Uses HTTP Basic auth with an iCloud app-specific
 * password — Apple has no OAuth API for third-party calendar access, unlike
 * Google/Microsoft (see modules/calendar-sync/handlers.php's header comment).
 *
 * @package ClientOctopus\CalendarSync
 * @since   1.5.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClientOctopus_CalDAV_Client {

	private const BASE_URL = 'https://caldav.icloud.com/';

	private string $username;
	private string $password;

	public function __construct( string $username, string $password ) {
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * Discover the owner's default calendar collection URL via the standard
	 * CalDAV principal → calendar-home-set → calendar-collection chain.
	 * Thin wrapper over discover_calendars() returning just the first match —
	 * used by the automatic sync path, which discovers once at initial
	 * connect and then relies on the cached calendar_url forever after, so it
	 * has no need for the full list.
	 *
	 * @return string|WP_Error Absolute calendar collection URL.
	 */
	public function discover_calendar_url(): string|WP_Error {
		$calendars = $this->discover_calendars();
		if ( is_wp_error( $calendars ) ) {
			return $calendars;
		}

		return $calendars[0]['url'];
	}

	/**
	 * Discover every calendar collection on the account that supports VEVENTs
	 * (i.e. could plausibly be "my availability"), so the owner can pick the
	 * right one instead of silently getting whichever sorts first — a real
	 * ambiguity on iCloud accounts with more than one calendar (shared,
	 * subscribed, or otherwise) that Google/Microsoft don't have, since both
	 * expose one well-defined "primary" calendar.
	 *
	 * @return array{url: string, name: string}[]|WP_Error
	 */
	public function discover_calendars(): array|WP_Error {
		$principal = $this->propfind_text(
			self::BASE_URL,
			'<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>',
			'0',
			'd:current-user-principal/d:href'
		);
		if ( is_wp_error( $principal ) ) {
			return $principal;
		}

		$principal_url = $this->resolve_url( self::BASE_URL, $principal );

		$home_set = $this->propfind_text(
			$principal_url,
			'<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-home-set/></d:prop></d:propfind>',
			'0',
			'c:calendar-home-set/d:href'
		);
		if ( is_wp_error( $home_set ) ) {
			return $home_set;
		}

		$home_url = $this->resolve_url( self::BASE_URL, $home_set );

		$response = $this->request(
			'PROPFIND',
			$home_url,
			'<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
				'<d:prop><d:resourcetype/><d:displayname/><cs:getctag/><c:supported-calendar-component-set/></d:prop>' .
			'</d:propfind>',
			[ 'Depth' => '1' ]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$xml = $this->parse_xml( $response['body'] );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		// Every collection whose resourcetype includes <calendar/> and whose
		// supported-calendar-component-set includes VEVENT.
		$calendars = [];
		foreach ( $xml->xpath( '//d:response' ) as $node ) {
			$node->registerXPathNamespace( 'd', 'DAV:' );
			$node->registerXPathNamespace( 'c', 'urn:ietf:params:xml:ns:caldav' );

			$is_calendar = $node->xpath( './/d:resourcetype/*[local-name()="calendar"]' );
			if ( empty( $is_calendar ) ) {
				continue;
			}

			$supports_events = $node->xpath( './/c:supported-calendar-component-set/c:comp[@name="VEVENT"]' );
			if ( empty( $supports_events ) ) {
				continue;
			}

			$href = $node->xpath( './d:href' );
			if ( empty( $href ) ) {
				continue;
			}

			$name = $node->xpath( './/d:displayname' );

			$calendars[] = [
				'url'  => $this->resolve_url( self::BASE_URL, (string) $href[0] ),
				'name' => ! empty( $name ) ? (string) $name[0] : (string) $href[0],
			];
		}

		if ( empty( $calendars ) ) {
			return new WP_Error( 'caldav_no_calendar', __( 'Could not find a usable calendar on this Apple account.', 'clientoctopus' ) );
		}

		return $calendars;
	}

	/**
	 * @return array{0: DateTime, 1: DateTime, 2: string}[]|WP_Error Busy periods (UTC) with event title.
	 */
	public function get_busy_periods( string $calendar_url, DateTime $range_start_utc, DateTime $range_end_utc ): array|WP_Error {
		$fmt = 'Ymd\THis\Z';
		$body = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
			'<d:prop><d:getetag/><c:calendar-data/></d:prop>' .
			'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">' .
			'<c:time-range start="' . $range_start_utc->format( $fmt ) . '" end="' . $range_end_utc->format( $fmt ) . '"/>' .
			'</c:comp-filter></c:comp-filter></c:filter>' .
			'</c:calendar-query>';

		$response = $this->request( 'REPORT', $calendar_url, $body, [ 'Depth' => '1' ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$xml = $this->parse_xml( $response['body'] );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$periods = [];
		foreach ( $xml->xpath( '//c:calendar-data' ) as $node ) {
			foreach ( self::parse_ics_vevents( (string) $node ) as $period ) {
				$periods[] = $period;
			}
		}

		return $periods;
	}

	/**
	 * PUT a single-event .ics onto the calendar collection.
	 *
	 * @return string|WP_Error The resource URL of the created event.
	 */
	public function create_event( string $calendar_url, string $uid, string $ics_body ): string|WP_Error {
		$event_url = untrailingslashit( $calendar_url ) . '/' . rawurlencode( $uid ) . '.ics';

		$response = $this->request( 'PUT', $event_url, $ics_body, [ 'Content-Type' => 'text/calendar; charset=utf-8' ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return new WP_Error( 'caldav_put_failed', sprintf( 'CalDAV PUT failed with status %d.', $response['status'] ) );
		}

		return $event_url;
	}

	public function delete_event( string $event_url ): bool|WP_Error {
		$response = $this->request( 'DELETE', $event_url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// A 404 here means it's already gone — treat as success, not a failure
		// (the booking may have already been cancelled and cleaned up once).
		if ( $response['status'] >= 300 && 404 !== $response['status'] ) {
			return new WP_Error( 'caldav_delete_failed', sprintf( 'CalDAV DELETE failed with status %d.', $response['status'] ) );
		}

		return true;
	}

	/**
	 * Quick credential check — used when the owner first connects, so a typo'd
	 * app-specific password is caught immediately rather than on the next
	 * silent cron sync.
	 */
	public function test_connection(): bool|WP_Error {
		$result = $this->discover_calendar_url();
		return is_wp_error( $result ) ? $result : true;
	}

	// ── Internals ────────────────────────────────────────────────────────────

	private function propfind_text( string $url, string $body, string $depth, string $xpath ): string|WP_Error {
		$response = $this->request( 'PROPFIND', $url, $body, [ 'Depth' => $depth ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$xml = $this->parse_xml( $response['body'] );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$nodes = $xml->xpath( '//' . $xpath );
		if ( empty( $nodes ) ) {
			return new WP_Error( 'caldav_missing_property', sprintf( 'CalDAV response missing expected property: %s', $xpath ) );
		}

		return (string) $nodes[0];
	}

	/**
	 * @return array{status: int, body: string}|WP_Error
	 */
	private function request( string $method, string $url, string $body = '', array $extra_headers = [], int $redirects_left = 3 ): array|WP_Error {
		// iCloud is known to redirect the shared caldav.icloud.com host to a
		// per-account pod host (e.g. pXX-caldav.icloud.com) on first contact.
		// WordPress's HTTP client follows redirects by default, but whether it
		// re-sends a custom Authorization header across a cross-host redirect
		// isn't guaranteed (curl in particular has dropped Authorization on
		// redirect by default in some configurations) — so redirects are
		// disabled here and followed manually, explicitly re-attaching Basic
		// auth every hop, rather than trusting that behavior silently.
		$response = wp_remote_request( $url, [
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array_merge( [
				'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
				'Content-Type'  => 'application/xml; charset=utf-8',
			], $extra_headers ),
			'body'        => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'caldav_unreachable', $response->get_error_message() );
		}

		$redirect_status = (int) wp_remote_retrieve_response_code( $response );
		$location        = wp_remote_retrieve_header( $response, 'location' );
		if ( in_array( $redirect_status, [ 301, 302, 307, 308 ], true ) && $location && $redirects_left > 0 ) {
			return $this->request( $method, $this->resolve_url( $url, (string) $location ), $body, $extra_headers, $redirects_left - 1 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'caldav_unauthorized', __( 'Apple rejected these credentials — check the Apple ID and app-specific password.', 'clientoctopus' ) );
		}

		return [ 'status' => $status, 'body' => (string) wp_remote_retrieve_body( $response ) ];
	}

	private function parse_xml( string $body ): SimpleXMLElement|WP_Error {
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return new WP_Error( 'caldav_invalid_response', __( 'Apple returned an unreadable calendar response.', 'clientoctopus' ) );
		}

		$xml->registerXPathNamespace( 'd', 'DAV:' );
		$xml->registerXPathNamespace( 'c', 'urn:ietf:params:xml:ns:caldav' );

		return $xml;
	}

	private function resolve_url( string $base, string $href ): string {
		if ( str_starts_with( $href, 'http://' ) || str_starts_with( $href, 'https://' ) ) {
			return $href;
		}
		$parts = wp_parse_url( $base );
		return $parts['scheme'] . '://' . $parts['host'] . $href;
	}

	/**
	 * Minimal iCalendar VEVENT parser — extracts DTSTART/DTEND/SUMMARY, which
	 * is all busy/free checking plus a display title needs. Handles the
	 * common 'Z' (UTC) and TZID=<IANA name> forms; falls back to skipping an
	 * event whose date format isn't recognized rather than guessing.
	 *
	 * @return array{0: DateTime, 1: DateTime, 2: string}[]
	 */
	private static function parse_ics_vevents( string $ics ): array {
		$periods = [];
		$utc     = new DateTimeZone( 'UTC' );

		foreach ( preg_split( '/(?=BEGIN:VEVENT)/', $ics ) as $chunk ) {
			if ( ! str_contains( $chunk, 'BEGIN:VEVENT' ) ) {
				continue;
			}

			$start = self::parse_ics_datetime( $chunk, 'DTSTART' );
			$end   = self::parse_ics_datetime( $chunk, 'DTEND' );
			if ( $start && $end ) {
				$periods[] = [ $start->setTimezone( $utc ), $end->setTimezone( $utc ), self::parse_ics_summary( $chunk ) ];
			}
		}

		return $periods;
	}

	private static function parse_ics_summary( string $chunk ): string {
		if ( ! preg_match( '/^SUMMARY(;[^:\r\n]+)?:(.*)$/m', $chunk, $m ) ) {
			return '';
		}
		// Reverse the same TEXT escaping clientoctopus_generate_ics() applies
		// when writing events (\\, \,, \;, \n).
		return str_replace( [ '\\\\', '\\,', '\\;', '\\n' ], [ '\\', ',', ';', "\n" ], trim( $m[2] ) );
	}

	private static function parse_ics_datetime( string $chunk, string $property ): ?DateTime {
		// Parameter block matched loosely (any ;KEY=VALUE pairs) rather than
		// only ";TZID=..." — all-day events use ";VALUE=DATE" instead, which
		// the old TZID-only pattern didn't match at all, silently dropping
		// every all-day event (DTSTART;VALUE=DATE:20260907).
		if ( ! preg_match( '/^' . $property . '((?:;[^:\r\n]+)*):([0-9TZ]+)/m', $chunk, $m ) ) {
			return null;
		}

		$params = $m[1];
		$value  = $m[2];

		$tzid = '';
		if ( preg_match( '/;TZID=([^:;\r\n]+)/', $params, $tz_m ) ) {
			$tzid = $tz_m[1];
		}

		try {
			// All-day event — a bare YYYYMMDD date, either flagged explicitly
			// via ;VALUE=DATE or simply lacking a "T" time component. Treated
			// as UTC midnight-to-midnight, matching the same approximation
			// CO_Relay_Google_Calendar::get_busy() already uses for Google's
			// equivalent date-only all-day events.
			if ( str_contains( $params, ';VALUE=DATE' ) || ! str_contains( $value, 'T' ) ) {
				return new DateTime( $value, new DateTimeZone( 'UTC' ) );
			}
			if ( str_ends_with( $value, 'Z' ) ) {
				return new DateTime( $value, new DateTimeZone( 'UTC' ) );
			}
			if ( $tzid ) {
				return new DateTime( $value, new DateTimeZone( $tzid ) );
			}
			// Floating-time event with no explicit zone — treat as the site's
			// own timezone, same convention used throughout the rest of
			// Booking for timezone-less inputs.
			return new DateTime( $value, wp_timezone() );
		} catch ( Exception $e ) {
			return null;
		}
	}
}
