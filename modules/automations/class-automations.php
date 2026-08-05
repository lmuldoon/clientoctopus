<?php
/**
 * Automated Email Reminders
 *
 * Manages the three built-in proposal follow-up triggers:
 *   - not_viewed    — proposal sent but not opened after N days
 *   - not_accepted  — proposal viewed but not accepted after N days
 *   - expiring_soon — proposal expiring within N days
 *
 * Available on all plans (free, pro, agency). No plan checks here.
 *
 * @package ClientOctopus
 * @since   1.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table references use $wpdb->prefix with hardcoded names, never user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClientOctopus_Automations {

	/** Allowed trigger slugs and their default settings. */
	private const TRIGGERS = [
		'not_viewed'    => [
			'delay_days'    => 3,
			'email_subject' => 'Following up on your proposal',
			'email_body'    => "Hi {client_name},\n\nI wanted to follow up on the proposal I sent you recently — {proposal_title}.\n\nPlease take a moment to review it when you get a chance. I'm happy to answer any questions.\n\n",
		],
		'not_accepted'  => [
			'delay_days'    => 5,
			'email_subject' => 'Your proposal is still waiting',
			'email_body'    => "Hi {client_name},\n\nJust a quick follow-up on {proposal_title}. The proposal is ready and waiting for your acceptance whenever you're ready.\n\nFeel free to reach out if you have any questions or would like to make any changes.\n\n",
		],
		'expiring_soon' => [
			'delay_days'    => 2,
			'email_subject' => 'Your proposal is expiring soon',
			'email_body'    => "Hi {client_name},\n\nThis is a friendly reminder that your proposal — {proposal_title} — is expiring in {delay_days} days.\n\nPlease review and accept before it expires to secure your spot.\n\n",
		],
	];

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Seed default automation rows for an owner if none exist yet.
	 *
	 * Safe to call on every settings page load — does nothing if rows already exist.
	 */
	public static function seed_defaults( int $owner_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_automations';
		$now   = current_time( 'mysql' );

		foreach ( self::TRIGGERS as $trigger => $defaults ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE owner_id = %d AND trigger_event = %s LIMIT 1",
				$owner_id,
				$trigger
			) );

			if ( ! $exists ) {
				$wpdb->insert(
					$table,
					[
						'owner_id'      => $owner_id,
						'trigger_event' => $trigger,
						'delay_days'    => $defaults['delay_days'],
						'enabled'       => 0,
						'email_subject' => $defaults['email_subject'],
						'email_body'    => $defaults['email_body'],
						'created_at'    => $now,
						'updated_at'    => $now,
					],
					[ '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
				);
			}
		}
	}

	/**
	 * Return all 3 automation rows for an owner, seeding defaults if missing.
	 *
	 * @return array[] Rows indexed by trigger_event slug.
	 */
	public static function get_all( int $owner_id ): array {
		global $wpdb;

		self::seed_defaults( $owner_id );

		$table = $wpdb->prefix . 'clientoctopus_automations';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_id = %d ORDER BY FIELD(trigger_event, 'not_viewed', 'not_accepted', 'expiring_soon')",
				$owner_id
			),
			ARRAY_A
		);

		$indexed = [];
		foreach ( $rows as $row ) {
			$indexed[ $row['trigger_event'] ] = $row;
		}
		return $indexed;
	}

	/**
	 * Save one automation row for a given trigger.
	 *
	 * @param int    $owner_id
	 * @param string $trigger  One of the TRIGGERS keys.
	 * @param array  $data     Keys: enabled (bool), delay_days (int), email_subject (string), email_body (string).
	 */
	public static function save( int $owner_id, string $trigger, array $data ): void {
		if ( ! array_key_exists( $trigger, self::TRIGGERS ) ) {
			return;
		}

		global $wpdb;

		self::seed_defaults( $owner_id );

		$table = $wpdb->prefix . 'clientoctopus_automations';
		$wpdb->update(
			$table,
			[
				'enabled'       => (int) ! empty( $data['enabled'] ),
				'delay_days'    => max( 1, min( 30, (int) ( $data['delay_days'] ?? self::TRIGGERS[ $trigger ]['delay_days'] ) ) ),
				'email_subject' => sanitize_text_field( $data['email_subject'] ?? '' ),
				'email_body'    => sanitize_textarea_field( $data['email_body'] ?? '' ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[
				'owner_id'      => $owner_id,
				'trigger_event' => $trigger,
			],
			[ '%d', '%d', '%s', '%s', '%s' ],
			[ '%d', '%s' ]
		);
	}

	// ── Cron callback ─────────────────────────────────────────────────────────

	/**
	 * Cron callback — hooked to clientoctopus_daily_automations.
	 *
	 * Iterates over every owner with at least one enabled automation,
	 * finds matching proposals, and sends reminder emails.
	 */
	public static function run_daily(): void {
		global $wpdb;

		$auto_table = $wpdb->prefix . 'clientoctopus_automations';
		$log_table  = $wpdb->prefix . 'clientoctopus_reminder_log';
		$prop_table = $wpdb->prefix . 'clientoctopus_proposals';

		// Load all enabled automations grouped by owner.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$auto_table} WHERE enabled = 1",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		// Group by owner_id.
		$by_owner = [];
		foreach ( $rows as $row ) {
			$by_owner[ (int) $row['owner_id'] ][] = $row;
		}

		foreach ( $by_owner as $owner_id => $automations ) {
			self::process_owner( $owner_id, $automations, $prop_table, $log_table );
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private static function process_owner( int $owner_id, array $automations, string $prop_table, string $log_table ): void {
		global $wpdb;

		$from_name  = get_option( 'clientoctopus_from_name', get_option( 'blogname', '' ) );
		$from_email = get_option( 'clientoctopus_from_email', get_option( 'admin_email', '' ) );

		foreach ( $automations as $auto ) {
			$trigger     = $auto['trigger_event'];
			$delay       = (int) $auto['delay_days'];
			$subject_tpl = $auto['email_subject'];
			$body_tpl    = $auto['email_body'];

			$proposals = self::get_matching_proposals( $owner_id, $trigger, $delay, $prop_table, $log_table );

			foreach ( $proposals as $proposal ) {
				self::send_reminder( $proposal, $trigger, $delay, $subject_tpl, $body_tpl, $from_name, $from_email, $log_table );
			}
		}
	}

	private static function get_matching_proposals( int $owner_id, string $trigger, int $delay, string $prop_table, string $log_table ): array {
		global $wpdb;

		switch ( $trigger ) {
			case 'not_viewed':
				$sql = $wpdb->prepare(
					"SELECT p.* FROM {$prop_table} p
					 LEFT JOIN {$log_table} rl ON rl.proposal_id = p.id AND rl.trigger_event = %s
					 WHERE p.owner_id = %d
					   AND p.status = 'sent'
					   AND p.viewed_at IS NULL
					   AND p.deleted_at IS NULL
					   AND p.sent_at IS NOT NULL
					   AND p.sent_at <= DATE_SUB( NOW(), INTERVAL %d DAY )
					   AND rl.id IS NULL",
					$trigger,
					$owner_id,
					$delay
				);
				break;

			case 'not_accepted':
				$sql = $wpdb->prepare(
					"SELECT p.* FROM {$prop_table} p
					 LEFT JOIN {$log_table} rl ON rl.proposal_id = p.id AND rl.trigger_event = %s
					 WHERE p.owner_id = %d
					   AND p.status = 'viewed'
					   AND p.deleted_at IS NULL
					   AND p.viewed_at IS NOT NULL
					   AND p.viewed_at <= DATE_SUB( NOW(), INTERVAL %d DAY )
					   AND rl.id IS NULL",
					$trigger,
					$owner_id,
					$delay
				);
				break;

			case 'expiring_soon':
				$sql = $wpdb->prepare(
					"SELECT p.* FROM {$prop_table} p
					 LEFT JOIN {$log_table} rl ON rl.proposal_id = p.id AND rl.trigger_event = %s
					 WHERE p.owner_id = %d
					   AND p.status IN('sent','viewed')
					   AND p.deleted_at IS NULL
					   AND p.expiry_date IS NOT NULL
					   AND p.expiry_date BETWEEN NOW() AND DATE_ADD( NOW(), INTERVAL %d DAY )
					   AND rl.id IS NULL",
					$trigger,
					$owner_id,
					$delay
				);
				break;

			default:
				return [];
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
	}

	private static function send_reminder(
		array $proposal,
		string $trigger,
		int $delay,
		string $subject_tpl,
		string $body_tpl,
		string $from_name,
		string $from_email,
		string $log_table
	): void {
		global $wpdb;

		$client_email = $proposal['client_email'] ?? '';
		if ( ! is_email( $client_email ) ) {
			return;
		}

		$proposal_url = add_query_arg(
			[ 'proposal' => rawurlencode( $proposal['token'] ) ],
			trailingslashit( home_url() ) . 'proposal/'
		);

		$merge_data = [
			'{client_name}'    => $proposal['client_name'] ?? '',
			'{proposal_title}' => $proposal['title'] ?? '',
			'{proposal_url}'   => $proposal_url,
			'{delay_days}'     => (string) $delay,
		];

		$subject = self::resolve_merge_tags( $subject_tpl, $merge_data );
		$body    = self::resolve_merge_tags( $body_tpl, $merge_data );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		];

		$sent = wp_mail(
			$client_email,
			wp_strip_all_tags( $subject ),
			clientoctopus_email_html( [
				'subject'   => $subject,
				'name'      => $proposal['client_name'] ?? '',
				'body'      => nl2br( esc_html( $body ) ),
				'cta_label' => __( 'View Proposal', 'clientoctopus' ),
				'cta_url'   => $proposal_url,
			] ),
			$headers
		);

		if ( $sent ) {
			$wpdb->insert(
				$log_table,
				[
					'proposal_id'  => (int) $proposal['id'],
					'trigger_event' => $trigger,
					'sent_at'      => current_time( 'mysql' ),
				],
				[ '%d', '%s', '%s' ]
			);
		}
	}

	private static function resolve_merge_tags( string $text, array $merge_data ): string {
		return str_replace( array_keys( $merge_data ), array_values( $merge_data ), $text );
	}
}
