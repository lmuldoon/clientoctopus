<?php
/**
 * Team Business Logic Handlers
 *
 * Business logic for the Agency-tier team seats feature:
 *   - clientoctopus_team_get_members()    — list all team members for an owner
 *   - clientoctopus_team_invite_member()  — invite a new team member (creates WP user if needed)
 *   - clientoctopus_team_remove_member()  — remove a team member and decrement seat counter
 *
 * @package ClientOctopus\Team
 * @since   0.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return all team members for the given owner.
 *
 * @param int $owner_id
 * @return array<array{id: int, member_user_id: int, display_name: string, email: string, role: string, invited_at: string, accepted_at: string|null}>
 */
function clientoctopus_team_get_members( int $owner_id ): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT tm.id, tm.member_user_id, tm.role, tm.invited_at, tm.accepted_at,
			        u.display_name, u.user_email AS email
			 FROM {$wpdb->prefix}clientoctopus_team_members tm
			 JOIN {$wpdb->users} u ON u.ID = tm.member_user_id
			 WHERE tm.owner_id = %d
			 ORDER BY tm.invited_at ASC",
			$owner_id
		),
		ARRAY_A
	);

	return $rows ?: [];
}

/**
 * Invite a new team member.
 *
 * Creates a WordPress user if one does not already exist for the given email.
 * Existing users receive a plain notification; new users receive a set-password link.
 *
 * @param int    $owner_id
 * @param string $email
 * @param string $name
 * @param string $role  'admin'|'editor'|'viewer'
 * @return array{success: bool, error?: string}
 */
function clientoctopus_team_invite_member( int $owner_id, string $email, string $name, string $role ): array {
	global $wpdb;

	$email = sanitize_email( $email );
	$name  = sanitize_text_field( $name );

	if ( ! is_email( $email ) ) {
		return [ 'success' => false, 'error' => 'invalid_email' ];
	}

	$valid_roles = [ 'admin', 'editor', 'viewer' ];
	if ( ! in_array( $role, $valid_roles, true ) ) {
		$role = 'editor';
	}

	// Find or create the WordPress user before opening the transaction so we
	// don't hold the lock during potentially slow WP user-creation queries.
	$existing_user = get_user_by( 'email', $email );
	$is_new_user   = false;

	if ( $existing_user ) {
		$member_user_id = (int) $existing_user->ID;

		// Prevent the owner from adding themselves.
		if ( $member_user_id === $owner_id ) {
			return [ 'success' => false, 'error' => 'cannot_invite_self' ];
		}
	} else {
		$base_username = sanitize_user( strtolower( str_replace( ' ', '_', $name ) ), true );
		$base_username = $base_username ?: sanitize_user( strstr( $email, '@', true ), true );
		$username      = $base_username;
		$suffix        = 2;
		while ( username_exists( $username ) ) {
			$username = $base_username . '_' . $suffix++;
		}
		$password = wp_generate_password( 24, true, true );

		$member_user_id = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $member_user_id ) ) {
			return [ 'success' => false, 'error' => $member_user_id->get_error_message() ];
		}

		$member_user_id = (int) $member_user_id;
		$is_new_user    = true;
	}

	// Lock the owner's meta row so concurrent requests can't both pass the
	// seat check before either one increments the counter.
	$wpdb->query( 'START TRANSACTION' );

	$seats_used  = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT team_seats_used FROM {$wpdb->prefix}clientoctopus_user_meta WHERE user_id = %d FOR UPDATE",
		$owner_id
	) );
	$seats_limit = ClientOctopus_Entitlements::get_team_limit( $owner_id );

	if ( $seats_used >= $seats_limit ) {
		$wpdb->query( 'ROLLBACK' );
		return [ 'success' => false, 'error' => 'seat_limit_reached', 'upgrade_required' => true ];
	}

	// Check if already a member of this owner's team.
	$existing_row = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}clientoctopus_team_members WHERE owner_id = %d AND member_user_id = %d",
		$owner_id,
		$member_user_id
	) );

	if ( $existing_row ) {
		$wpdb->query( 'ROLLBACK' );
		return [ 'success' => false, 'error' => 'already_a_member' ];
	}

	// Insert team member row.
	$wpdb->insert(
		$wpdb->prefix . 'clientoctopus_team_members',
		[
			'owner_id'       => $owner_id,
			'member_user_id' => $member_user_id,
			'role'           => $role,
			'invited_at'     => gmdate( 'Y-m-d H:i:s' ),
		],
		[ '%d', '%d', '%s', '%s' ]
	);

	// Increment seat counter.
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}clientoctopus_user_meta SET team_seats_used = team_seats_used + 1 WHERE user_id = %d",
		$owner_id
	) );

	$wpdb->query( 'COMMIT' );

	// Assign role and display name after the team_members row is committed so
	// the security hook finds the record and doesn't immediately strip the role.
	if ( $is_new_user ) {
		wp_update_user( [
			'ID'           => $member_user_id,
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => 'clientoctopus_member',
		] );
	}

	// Send invite email.
	clientoctopus_team_send_invite_email( $member_user_id, $owner_id, $is_new_user );

	return [ 'success' => true ];
}

/**
 * Remove a team member and decrement the owner's seat counter.
 *
 * @param int $owner_id
 * @param int $row_id  Primary key of the clientoctopus_team_members row.
 * @return bool
 */
function clientoctopus_team_remove_member( int $owner_id, int $row_id ): bool {
	global $wpdb;

	$deleted = $wpdb->delete(
		$wpdb->prefix . 'clientoctopus_team_members',
		[ 'id' => $row_id, 'owner_id' => $owner_id ],
		[ '%d', '%d' ]
	);

	if ( ! $deleted ) {
		return false;
	}

	// Decrement seat counter, but never below 1 (the owner always occupies one seat).
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}clientoctopus_user_meta
		 SET team_seats_used = GREATEST( team_seats_used - 1, 1 )
		 WHERE user_id = %d",
		$owner_id
	) );

	return true;
}

/**
 * Send the appropriate invite email to a new team member.
 *
 * New WP users: includes a set-password link so they can log in for the first time.
 * Existing WP users: plain notification only — no password disruption.
 *
 * @param int  $member_user_id
 * @param int  $owner_id
 * @param bool $is_new_user
 */
function clientoctopus_team_send_invite_email( int $member_user_id, int $owner_id, bool $is_new_user ): void {
	$member = get_user_by( 'ID', $member_user_id );
	if ( ! $member ) {
		return;
	}

	$business_name = get_option( 'clientoctopus_business_name', get_bloginfo( 'name' ) );
	$login_url     = admin_url();
	$site_name     = get_bloginfo( 'name' );

	if ( $is_new_user ) {
		$reset_key  = get_password_reset_key( $member );
		$set_pw_url = is_wp_error( $reset_key )
			? $login_url
			: network_site_url( "wp-login.php?action=rp&key={$reset_key}&login=" . rawurlencode( $member->user_login ) );

		$subject = "You've been invited to join {$business_name}";
		$body    = clientoctopus_email_html( [
			'subject'       => $subject,
			'business_name' => $business_name,
			'name'          => $member->display_name,
			'body'          => "<p>You've been invited to join <strong>{$business_name}</strong> as a team member on {$site_name}.</p><p>Click the button below to set your password and access your account.</p><p style=\"font-size:13px;color:#9CA3AF;\">This link expires after 24 hours. If you did not expect this invitation, you can safely ignore it.</p>",
			'cta_label'     => 'Set Your Password',
			'cta_url'       => $set_pw_url,
		] );
	} else {
		$subject = "You've been added to {$business_name} on {$site_name}";
		$body    = clientoctopus_email_html( [
			'subject'       => $subject,
			'business_name' => $business_name,
			'name'          => $member->display_name,
			'body'          => "<p>You've been added as a team member for <strong>{$business_name}</strong> on {$site_name}.</p><p>Log in to your existing account to get started.</p>",
			'cta_label'     => 'Go to Dashboard',
			'cta_url'       => $login_url,
		] );
	}

	wp_mail(
		$member->user_email,
		$subject,
		$body,
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);
}
