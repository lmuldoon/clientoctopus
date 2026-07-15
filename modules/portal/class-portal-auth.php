<?php
/**
 * Portal authentication: custom session management.
 *
 * Flow (magic link):
 *   1. Client submits email → generate_magic_token() stores SHA-256 hash on
 *      the clientoctopus_clients row (no WordPress user required).
 *   2. Raw token in email URL → verify_magic_token() hashes it, finds the
 *      matching client row, checks expiry, clears the token (one-time use),
 *      creates a session row in clientoctopus_portal_sessions, and sets a
 *      custom httpOnly cookie (co_portal_session).
 *   3. Subsequent requests: rest_permission() reads the cookie, hashes it,
 *      validates against the sessions table, and populates static context
 *      properties so endpoint handlers can access the current client.
 *
 * No WordPress user account is created for portal clients.
 * Team members (agency collaborators) continue to use standard WP auth.
 */

declare( strict_types = 1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table names use $wpdb->prefix with trusted constants.

if ( ! defined( 'ABSPATH' ) ) exit;

class ClientOctopus_Portal_Auth {

	/** Cookie name for the portal session token. */
	const COOKIE_NAME = 'co_portal_session';

	/** Magic-link token lifetime in seconds (24 hours). */
	const TOKEN_TTL = 86400;

	/** Session lifetime in seconds (30 days). */
	const SESSION_TTL = 2592000;

	// ── Current-request context (populated by rest_permission()) ─────────────

	/** @var string|null Email of the authenticated portal client. */
	private static ?string $current_email = null;

	/** @var int|null clientoctopus_clients.id of the authenticated client. */
	private static ?int $current_client_id = null;

	/** @var int|null Owner WP user ID for the authenticated client. */
	private static ?int $current_owner_id = null;

	// ─────────────────────────────────────────────────────────────────────────
	// Magic token
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Generate a magic login token for the given client.
	 *
	 * Stores the SHA-256 hash + expiry directly on the clientoctopus_clients
	 * row and returns the raw token (embedded in the email URL).
	 *
	 * @param  int $client_id  clientoctopus_clients.id
	 * @return string Raw token.
	 */
	public static function generate_magic_token( int $client_id ): string {
		global $wpdb;

		$raw_token = wp_generate_password( 32, false, false );
		$hash      = hash( 'sha256', $raw_token );
		$expiry    = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL );

		$wpdb->update(
			$wpdb->prefix . 'clientoctopus_clients',
			[
				'portal_token_hash'       => $hash,
				'portal_token_expires_at' => $expiry,
			],
			[ 'id' => $client_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return $raw_token;
	}

	/**
	 * Verify a magic token from the URL.
	 *
	 * On success: clears the token (one-time use), creates a session, sets the
	 * portal session cookie, and returns the client row.
	 *
	 * On failure: returns a WP_Error.
	 *
	 * @param  string $raw_token  Token from the query string.
	 * @return array|WP_Error     Client row (ARRAY_A) or WP_Error.
	 */
	public static function verify_magic_token( string $raw_token ) {
		global $wpdb;

		if ( strlen( $raw_token ) < 10 ) {
			return new WP_Error( 'invalid_token', 'Invalid token.' );
		}

		$hash = hash( 'sha256', $raw_token );

		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}clientoctopus_clients
				 WHERE portal_token_hash = %s
				 LIMIT 1",
				$hash
			),
			ARRAY_A
		);

		if ( ! $client ) {
			return new WP_Error( 'invalid_token', 'This login link is invalid.' );
		}

		// Check expiry.
		if ( empty( $client['portal_token_expires_at'] ) || strtotime( $client['portal_token_expires_at'] ) < time() ) {
			// Clear the expired token.
			$wpdb->update(
				$wpdb->prefix . 'clientoctopus_clients',
				[ 'portal_token_hash' => null, 'portal_token_expires_at' => null ],
				[ 'id' => (int) $client['id'] ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			return new WP_Error( 'expired_token', 'This login link has expired. Please request a new one.' );
		}

		// One-time use: clear token before creating session.
		$wpdb->update(
			$wpdb->prefix . 'clientoctopus_clients',
			[ 'portal_token_hash' => null, 'portal_token_expires_at' => null ],
			[ 'id' => (int) $client['id'] ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		self::create_session( (int) $client['id'], (int) $client['owner_id'] );

		return $client;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Session management
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Create a new portal session for the given client and set the cookie.
	 *
	 * Prunes expired sessions for this client before inserting.
	 *
	 * @param  int $client_id  clientoctopus_clients.id
	 * @param  int $owner_id   WP user ID of the account owner.
	 * @return void
	 */
	public static function create_session( int $client_id, int $owner_id ): void {
		global $wpdb;

		// Prune expired sessions to keep the table tidy.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}clientoctopus_portal_sessions
				 WHERE client_id = %d AND expires_at < NOW()",
				$client_id
			)
		);

		$raw_token = wp_generate_password( 32, false, false );
		$hash      = hash( 'sha256', $raw_token );
		$expires   = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );

		$wpdb->insert(
			$wpdb->prefix . 'clientoctopus_portal_sessions',
			[
				'client_id'     => $client_id,
				'owner_id'      => $owner_id,
				'session_token' => $hash,
				'expires_at'    => $expires,
				'created_at'    => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);

		self::set_session_cookie( $raw_token );
	}

	/**
	 * Set the portal session cookie.
	 *
	 * @param  string $raw_token
	 */
	private static function set_session_cookie( string $raw_token ): void {
		$secure   = is_ssl();
		$samesite = 'Strict';
		$expires  = time() + self::SESSION_TTL;

		setcookie( self::COOKIE_NAME, $raw_token, [
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => $samesite,
		] );

		// Make the cookie available in the current request without a redirect.
		$_COOKIE[ self::COOKIE_NAME ] = $raw_token;
	}

	/**
	 * Destroy the current portal session (logout).
	 */
	public static function destroy_session(): void {
		global $wpdb;

		$raw_token = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';
		if ( $raw_token ) {
			$hash = hash( 'sha256', $raw_token );
			$wpdb->delete(
				$wpdb->prefix . 'clientoctopus_portal_sessions',
				[ 'session_token' => $hash ],
				[ '%s' ]
			);
		}

		// Clear cookie.
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::COOKIE_NAME, '', [
				'expires'  => time() - 3600,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			] );
		} else {
			setcookie( self::COOKIE_NAME, '', time() - 3600, '/' );
		}

		unset( $_COOKIE[ self::COOKIE_NAME ] );
		self::$current_email     = null;
		self::$current_client_id = null;
		self::$current_owner_id  = null;
	}

	/**
	 * Resolve the session cookie into a session row.
	 *
	 * Joins with clientoctopus_clients so the row includes client data.
	 *
	 * @return object|null Session row with client columns, or null.
	 */
	private static function resolve_session(): ?object {
		global $wpdb;

		$raw_token = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';
		if ( ! $raw_token ) {
			return null;
		}

		$hash = hash( 'sha256', $raw_token );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.id AS session_id, s.client_id, s.owner_id, s.expires_at,
				        c.email, c.name, c.company
				 FROM {$wpdb->prefix}clientoctopus_portal_sessions s
				 INNER JOIN {$wpdb->prefix}clientoctopus_clients c ON c.id = s.client_id
				 WHERE s.session_token = %s
				   AND s.expires_at > NOW()
				 LIMIT 1",
				$hash
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Auth checks
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Whether the current request is an authenticated portal client.
	 *
	 * @return bool
	 */
	public static function is_authenticated(): bool {
		// Use cached context if already set (e.g. by rest_permission()).
		if ( null !== self::$current_email ) {
			return true;
		}

		$session = self::resolve_session();
		if ( ! $session ) {
			return false;
		}

		self::$current_email     = $session->email;
		self::$current_client_id = (int) $session->client_id;
		self::$current_owner_id  = (int) $session->owner_id;

		return true;
	}

	/**
	 * Email of the currently authenticated portal client.
	 *
	 * @return string|null
	 */
	public static function get_current_email(): ?string {
		self::is_authenticated();
		return self::$current_email;
	}

	/**
	 * clientoctopus_clients.id of the authenticated client.
	 *
	 * @return int|null
	 */
	public static function get_current_client_id(): ?int {
		self::is_authenticated();
		return self::$current_client_id;
	}

	/**
	 * Owner WP user ID for the authenticated client.
	 *
	 * @return int|null
	 */
	public static function get_current_owner_id(): ?int {
		self::is_authenticated();
		return self::$current_owner_id;
	}

	/**
	 * Return basic profile data for the current portal client.
	 *
	 * @return array{ id: int, name: string, email: string, company: string }|null
	 */
	public static function get_current_client_data(): ?array {
		if ( ! self::is_authenticated() ) {
			return null;
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, email, company FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d",
				self::$current_client_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return [
			'id'      => (int) $row['id'],
			'name'    => $row['name'] ?? '',
			'email'   => $row['email'] ?? '',
			'company' => $row['company'] ?? '',
		];
	}

	/**
	 * Whether the current portal client has set a custom password.
	 *
	 * @return bool
	 */
	public static function has_set_password(): bool {
		if ( ! self::is_authenticated() ) {
			return false;
		}

		global $wpdb;
		$hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT portal_password_hash FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d",
				self::$current_client_id
			)
		);

		return ! empty( $hash );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REST permission callback
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * REST permission callback for portal-authenticated endpoints.
	 *
	 * Validates the co_portal_session cookie against the sessions table and
	 * populates the static context properties so endpoint handlers can call
	 * get_current_email() / get_current_client_id() / get_current_owner_id().
	 *
	 * @return bool|WP_Error
	 */
	public static function rest_permission() {
		$session = self::resolve_session();

		if ( ! $session ) {
			return new WP_Error(
				'clientoctopus_portal_unauthorized',
				'Authentication required.',
				[ 'status' => 401 ]
			);
		}

		self::$current_email     = $session->email;
		self::$current_client_id = (int) $session->client_id;
		self::$current_owner_id  = (int) $session->owner_id;

		return true;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Email
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Send a magic login link email to the given client.
	 *
	 * @param  string $email
	 * @param  string $name      Display name hint (may be empty).
	 * @param  string $raw_token Token to embed in the URL.
	 * @return bool
	 */
	public static function send_magic_link_email( string $email, string $name, string $raw_token ): bool {
		$business_name = get_option( 'blogname', 'Client Portal' );
		$portal_url    = home_url( '/clientoctopus/verify?token=' . rawurlencode( $raw_token ) );
		$login_url     = home_url( '/clientoctopus/login' );
		$subject       = sprintf( 'Your login link for %s', $business_name );
		$expiry_hours  = (int) ( self::TOKEN_TTL / 3600 );
		$display_name  = $name ?: $email;

		$message = clientoctopus_email_html( [
			'name'          => $display_name,
			'business_name' => $business_name,
			'body'          => '<p style="margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;">Click the button below to securely log in to your client portal. This link expires in <strong style="color:#1A1A2E;">' . $expiry_hours . ' hours</strong> and can only be used once.</p>',
			'cta_label'     => 'Access Your Portal',
			'cta_url'       => $portal_url,
			'footer'        => 'Bookmark your portal for easy access: <a href="' . esc_url( $login_url ) . '" style="color:#6366F1;">' . esc_html( $login_url ) . '</a>',
		] );

		return wp_mail( $email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}
}
