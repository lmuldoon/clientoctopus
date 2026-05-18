<?php
/**
 * Message Model
 *
 * Handles project-scoped messaging between agency and clients.
 * Messages are threaded by project. Read tracking: read_at marks
 * when the recipient (the non-sender party) opened the conversation.
 *
 * @package ClientOctopus\Messaging
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Message
 */
class ClientOctopus_Message {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; table() returns a trusted constant, not user input.

	private const TABLE = 'clientoctopus_messages';

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	// ── Send ──────────────────────────────────────────────────────────────────

	/**
	 * Send a message.
	 *
	 * @param int    $project_id
	 * @param int    $sender_id       WP user ID of the sender.
	 * @param string $sender_type     'admin' or 'client'.
	 * @param string $message
	 * @param int    $owner_id        For admin sends: the project owner. Pass 0 for client sends.
	 *
	 * @return int|WP_Error New message ID.
	 */
	public static function send(
		int $project_id,
		int $sender_id,
		string $sender_type,
		string $message,
		int $owner_id = 0
	): int|WP_Error {
		global $wpdb;

		// Verify access.
		if ( 'admin' === $sender_type ) {
			$project = self::get_project( $project_id, $owner_id ?: $sender_id );
			if ( is_wp_error( $project ) ) {
				return $project;
			}
		} else {
			if ( ! self::client_owns_project( $project_id, $sender_id ) ) {
				return new WP_Error( 'forbidden', __( 'Access denied.', 'clientoctopus' ), [ 'status' => 403 ] );
			}
		}

		$message = sanitize_textarea_field( $message );
		if ( '' === $message ) {
			return new WP_Error( 'empty_message', __( 'Message cannot be empty.', 'clientoctopus' ), [ 'status' => 400 ] );
		}

		$now = current_time( 'mysql' );

		$wpdb->insert(
			self::table(),
			[
				'project_id'  => $project_id,
				'sender_id'   => $sender_id,
				'sender_type' => $sender_type,
				'message'     => $message,
				'created_at'  => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);

		if ( ! $wpdb->insert_id ) {
			return new WP_Error( 'db_insert_failed', __( 'Failed to send message.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		$id = (int) $wpdb->insert_id;

		self::notify_recipient( $id );

		return $id;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * List messages for a project — admin view.
	 * Marks all unread client messages as read.
	 *
	 * @param int $project_id
	 * @param int $owner_id
	 *
	 * @return array { messages: array, unread_count: int }
	 */
	public static function list_for_admin( int $project_id, int $owner_id ): array {
		global $wpdb;

		$project = self::get_project( $project_id, $owner_id );
		if ( is_wp_error( $project ) ) {
			return [ 'messages' => [], 'unread_count' => 0 ];
		}

		// Count unread before marking read.
		$unread = self::unread_count_for_project_admin( $project_id, $owner_id );

		// Mark client messages read.
		self::mark_read_admin( $project_id, $owner_id );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE project_id = %d ORDER BY created_at ASC",
				$project_id
			),
			ARRAY_A
		);

		return [
			'messages'     => array_map( [ __CLASS__, 'prepare_row' ], $rows ?: [] ),
			'unread_count' => $unread,
		];
	}

	/**
	 * List messages for a project — client portal view.
	 * Marks all unread admin messages as read.
	 *
	 * @param int $project_id
	 * @param int $client_wp_user_id
	 *
	 * @return array|WP_Error { messages: array, unread_count: int }
	 */
	public static function list_for_client( int $project_id, int $client_wp_user_id ): array|WP_Error {
		global $wpdb;

		if ( ! self::client_owns_project( $project_id, $client_wp_user_id ) ) {
			return new WP_Error( 'forbidden', __( 'Access denied.', 'clientoctopus' ), [ 'status' => 403 ] );
		}

		$unread = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table() . "
				 WHERE project_id = %d AND sender_type = 'admin' AND read_at IS NULL",
				$project_id
			)
		);

		self::mark_read_client( $project_id, $client_wp_user_id );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE project_id = %d ORDER BY created_at ASC",
				$project_id
			),
			ARRAY_A
		);

		return [
			'messages'     => array_map( [ __CLASS__, 'prepare_row' ], $rows ?: [] ),
			'unread_count' => $unread,
		];
	}

	// ── Delete ────────────────────────────────────────────────────────────────

	/**
	 * Delete a message (admin only, own project).
	 *
	 * @param int $id
	 * @param int $owner_id
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id, int $owner_id ): true|WP_Error {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE m FROM " . self::table() . " m
				 INNER JOIN {$wpdb->prefix}clientoctopus_projects p ON m.project_id = p.id
				 WHERE m.id = %d AND p.owner_id = %d",
				$id,
				$owner_id
			)
		);

		if ( ! $result ) {
			return new WP_Error( 'message_not_found', __( 'Message not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return true;
	}

	// ── Mark Read ─────────────────────────────────────────────────────────────

	/**
	 * Mark all unread client messages in a project as read (agency opens tab).
	 *
	 * @param int $project_id
	 * @param int $owner_id
	 */
	public static function mark_read_admin( int $project_id, int $owner_id ): void {
		global $wpdb;

		// Only update if project belongs to owner.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " m
				 INNER JOIN {$wpdb->prefix}clientoctopus_projects p ON m.project_id = p.id
				 SET m.read_at = %s
				 WHERE m.project_id = %d AND p.owner_id = %d
				   AND m.sender_type = 'client' AND m.read_at IS NULL",
				current_time( 'mysql' ),
				$project_id,
				$owner_id
			)
		);
	}

	/**
	 * Mark all unread admin messages in a project as read (client opens section).
	 *
	 * @param int $project_id
	 * @param int $client_wp_user_id
	 */
	public static function mark_read_client( int $project_id, int $client_wp_user_id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " m
				 INNER JOIN {$wpdb->prefix}clientoctopus_projects p ON m.project_id = p.id
				 INNER JOIN {$wpdb->prefix}clientoctopus_clients c ON p.client_id = c.id
				 INNER JOIN {$wpdb->users} u ON u.user_email = c.email
				 SET m.read_at = %s
				 WHERE m.project_id = %d AND u.ID = %d
				   AND m.sender_type = 'admin' AND m.read_at IS NULL",
				current_time( 'mysql' ),
				$project_id,
				$client_wp_user_id
			)
		);
	}

	// ── Counts ────────────────────────────────────────────────────────────────

	/**
	 * Total unread messages from clients across all projects owned by admin.
	 * Used for WP admin menu badge.
	 *
	 * @param int $owner_id
	 *
	 * @return int
	 */
	public static function unread_count_admin( int $owner_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table() . " m
				 INNER JOIN {$wpdb->prefix}clientoctopus_projects p ON m.project_id = p.id
				 WHERE p.owner_id = %d AND m.sender_type = 'client' AND m.read_at IS NULL",
				$owner_id
			)
		);
	}

	/**
	 * Unread count for a specific project (admin perspective).
	 * Used for the Messages tab badge in ProjectDetail.
	 *
	 * @param int $project_id
	 * @param int $owner_id
	 *
	 * @return int
	 */
	public static function unread_count_for_project_admin( int $project_id, int $owner_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table() . " m
				 INNER JOIN {$wpdb->prefix}clientoctopus_projects p ON m.project_id = p.id
				 WHERE m.project_id = %d AND p.owner_id = %d
				   AND m.sender_type = 'client' AND m.read_at IS NULL",
				$project_id,
				$owner_id
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Prepare a raw DB row for API responses.
	 * Casts types and resolves sender_name.
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	public static function prepare_row( array $row ): array {
		global $wpdb;

		$row['id']         = (int) $row['id'];
		$row['project_id'] = (int) $row['project_id'];
		$row['sender_id']  = (int) $row['sender_id'];

		// Resolve sender display name.
		if ( 'admin' === $row['sender_type'] ) {
			$user = get_userdata( $row['sender_id'] );
			$row['sender_name'] = $user ? $user->display_name : __( 'Agency', 'clientoctopus' );
		} else {
			$name = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT name FROM {$wpdb->prefix}clientoctopus_clients WHERE wp_user_id = %d",
					$row['sender_id']
				)
			);
			$row['sender_name'] = $name ?: __( 'Client', 'clientoctopus' );
		}

		return $row;
	}

	/**
	 * Verify a project belongs to the given owner.
	 *
	 * @param int $project_id
	 * @param int $owner_id
	 *
	 * @return array|WP_Error
	 */
	private static function get_project( int $project_id, int $owner_id ): array|WP_Error {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}clientoctopus_projects WHERE id = %d AND owner_id = %d",
				$project_id,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return $row;
	}

	/**
	 * Check whether a WP user is the client assigned to a project.
	 * Matches by email (consistent with ClientOctopus_Portal_Data) so the check
	 * works even before clientoctopus_clients.wp_user_id has been back-filled.
	 *
	 * @param int $project_id
	 * @param int $client_wp_user_id
	 *
	 * @return bool
	 */
	private static function client_owns_project( int $project_id, int $client_wp_user_id ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}clientoctopus_projects p
				 INNER JOIN {$wpdb->prefix}clientoctopus_clients c ON p.client_id = c.id
				 INNER JOIN {$wpdb->users} u ON u.user_email = c.email
				 WHERE p.id = %d AND u.ID = %d",
				$project_id,
				$client_wp_user_id
			)
		);

		return $count > 0;
	}

	/**
	 * Send a notification email to the recipient of a new message.
	 *
	 * @param int $message_id
	 */
	private static function notify_recipient( int $message_id ): void {
		global $wpdb;

		$msg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.sender_type, m.message, m.sender_id,
				        p.name AS project_name, p.owner_id,
				        c.email AS client_email, c.name AS client_name,
				        u.user_email AS owner_email,
				        u.display_name AS owner_name
				 FROM " . self::table() . " m
				 INNER JOIN {$wpdb->prefix}clientoctopus_projects p ON m.project_id = p.id
				 INNER JOIN {$wpdb->users} u ON p.owner_id = u.ID
				 LEFT  JOIN {$wpdb->prefix}clientoctopus_clients c ON p.client_id = c.id
				 WHERE m.id = %d",
				$message_id
			),
			ARRAY_A
		);

		if ( ! $msg ) {
			return;
		}

		if ( 'admin' === $msg['sender_type'] ) {
			// Agency sent → notify client.
			if ( ! $msg['client_email'] ) {
				return;
			}
			$project_name = esc_html( $msg['project_name'] );
			$owner_name   = esc_html( $msg['owner_name'] );
			$msg_text     = esc_html( $msg['message'] );

			$subject   = sprintf( 'New message on project: %s', wp_specialchars_decode( sanitize_text_field( $msg['project_name'] ?: __( 'your project', 'clientoctopus' ) ), ENT_QUOTES ) );
			$body_html = "
				<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
					You have a new message from <strong style=\"color:#1A1A2E;\">{$owner_name}</strong> on
					<strong style=\"color:#1A1A2E;\">{$project_name}</strong>.
				</p>
				<div style=\"margin:20px 0;padding:16px 20px;background:#F9FAFB;border-radius:10px;border-left:3px solid #D1D5DB;\">
					<p style=\"margin:0;font-size:15px;color:#374151;line-height:1.7;font-style:italic;\">&ldquo;{$msg_text}&rdquo;</p>
				</div>
				<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
					Log in to your portal to reply.
				</p>";

			$html_message = clientoctopus_email_html( [
				'subject'   => $subject,
				'name'      => $msg['client_name'] ?: '',
				'body'      => $body_html,
				'cta_label' => __( 'Reply in Portal', 'clientoctopus' ),
				'cta_url'   => home_url( '/clientoctopus/' ),
			] );
			wp_mail( $msg['client_email'], $subject, $html_message, [ 'Content-Type: text/html; charset=UTF-8' ] );
		} else {
			// Client sent → notify agency.
			if ( ! $msg['owner_email'] ) {
				return;
			}
			// sanitize_text_field strips newlines and control characters — safe for email subject headers.
			// esc_html is only used for the HTML body below.
			$client_display_name  = sanitize_text_field( $msg['client_name'] ?: __( 'Your client', 'clientoctopus' ) );
			$project_display_name = sanitize_text_field( $msg['project_name'] ?: __( 'your project', 'clientoctopus' ) );

			$client_name  = esc_html( $client_display_name );
			$project_name = esc_html( $msg['project_name'] );
			$msg_text     = esc_html( $msg['message'] );
			$subject      = sprintf( 'New message from %s on project: %s', $client_display_name, $project_display_name );
			$body_html    = clientoctopus_email_html( [
				'subject'   => $subject,
				'body'      => "
					<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\"><strong style=\"color:#1A1A2E;\">{$client_name}</strong> sent a message on project <strong style=\"color:#1A1A2E;\">{$project_name}</strong>.</p>
					<div style=\"margin:0;padding:16px 20px;background:#F9FAFB;border-radius:10px;border-left:3px solid #D1D5DB;\">
						<p style=\"margin:0;font-size:15px;color:#374151;line-height:1.7;font-style:italic;\">&ldquo;{$msg_text}&rdquo;</p>
					</div>",
				'cta_label' => __( 'View Message', 'clientoctopus' ),
				'cta_url'   => admin_url( 'admin.php?page=clientoctopus-projects' ),
			] );
			wp_mail( $msg['owner_email'], $subject, $body_html, [ 'Content-Type: text/html; charset=UTF-8' ] );
		}
	}
}
