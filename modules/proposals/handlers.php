<?php
/**
 * Proposal Business Logic Handlers
 *
 * Orchestrates higher-level operations that span multiple models:
 *   - create_from_wizard()   — creates proposal + client in one call
 *   - send_to_client()       — marks sent + triggers notification email
 *   - process_line_items()   — validates and stores pricing data
 *   - expire_overdue()       — cron job to auto-expire old proposals
 *
 * These are called by REST route callbacks, not accessed directly.
 *
 * @package ClientOctopus\Proposals
 * @since   0.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Proposal_Handlers
 */
class ClientOctopus_Proposal_Handlers {

	// ── Wizard Create ─────────────────────────────────────────────────────────

	/**
	 * Create a proposal from the 5-step wizard payload.
	 *
	 * Handles:
	 *   1. Template access check.
	 *   2. Client record lookup or creation.
	 *   3. Line-item calculation to determine total_amount.
	 *   4. Proposal insertion via ClientOctopus_Proposal::create().
	 *
	 * @param int   $owner_id WordPress user ID of the creator.
	 * @param array $payload  Validated wizard data (from REST request body).
	 *
	 * @return array|WP_Error The created proposal row, or WP_Error on failure.
	 */
	public static function create_from_wizard( int $owner_id, array $payload ): array|WP_Error {
		// ── Template access ──────────────────────────────────────────────────
		$template_id = sanitize_key( $payload['template_id'] ?? 'blank' );

		if ( ! ClientOctopus_Proposal_Template::user_can_access( $owner_id, $template_id ) ) {
			return new WP_Error(
				'template_locked',
				__( 'This template requires a Pro plan.', 'clientoctopus' ),
				[ 'status' => 403 ]
			);
		}

		// ── Client record ────────────────────────────────────────────────────
		$client_id = self::resolve_client(
			$owner_id,
			sanitize_text_field( $payload['client_name']    ?? '' ),
			sanitize_email(      $payload['client_email']   ?? '' ),
			sanitize_text_field( $payload['client_company'] ?? '' ),
			sanitize_text_field( $payload['client_phone']   ?? '' )
		);

		if ( is_wp_error( $client_id ) ) {
			return $client_id;
		}

		// ── Line items → total ───────────────────────────────────────────────
		$line_items   = is_array( $payload['line_items'] ?? null ) ? $payload['line_items'] : [];
		$discount_pct = (float) ( $payload['discount_pct'] ?? 0 );
		$vat_pct      = (float) ( $payload['vat_pct']      ?? 0 );
		$total_amount = self::calculate_total( $line_items, $discount_pct, $vat_pct );

		// ── Build content block ──────────────────────────────────────────────
		// Merge template default sections with wizard pricing data.
		$default_content = json_decode( ClientOctopus_Proposal_Template::default_content( $template_id ), true ) ?: [];
		$content         = array_merge( $default_content, [
			'line_items'      => self::sanitize_line_items( $line_items ),
			'discount_pct'    => $discount_pct,
			'vat_pct'         => $vat_pct,
			'deposit_pct'     => (int) ( $payload['deposit_pct']    ?? 0 ),
			'require_deposit' => ! empty( $payload['require_deposit'] ),
		] );

		// ── Expiry date ──────────────────────────────────────────────────────
		$expiry_date = ! empty( $payload['expiry_date'] )
			? sanitize_text_field( $payload['expiry_date'] )
			: gmdate( 'Y-m-d', strtotime( '+30 days' ) );

		// ── Create proposal ──────────────────────────────────────────────────
		$proposal_id = ClientOctopus_Proposal::create( $owner_id, [
			'client_id'      => $client_id,
			'title'          => sanitize_text_field( $payload['title'] ?? __( 'Untitled Proposal', 'clientoctopus' ) ),
			'content'        => wp_json_encode( $content ),
			'total_amount'   => $total_amount,
			'currency'       => strtoupper( sanitize_text_field( $payload['currency'] ?? 'GBP' ) ),
			'expiry_date'    => $expiry_date,
			'template_id'    => $template_id,
		] );

		if ( is_wp_error( $proposal_id ) ) {
			return $proposal_id;
		}

		// ── Return full row ──────────────────────────────────────────────────
		return ClientOctopus_Proposal::get( $proposal_id, $owner_id );
	}

	// ── Wizard Update ─────────────────────────────────────────────────────────

	/**
	 * Update an existing proposal from the wizard payload.
	 *
	 * Mirrors create_from_wizard() but calls update() instead of create(),
	 * so usage counters are NOT incremented again.
	 *
	 * @param int   $id       Proposal ID to update.
	 * @param int   $owner_id Ownership check.
	 * @param array $payload  Wizard form data.
	 *
	 * @return array|WP_Error Updated proposal row, or WP_Error.
	 */
	public static function update_from_wizard( int $id, int $owner_id, array $payload ): array|WP_Error {
		global $wpdb;

		// ── Client record ────────────────────────────────────────────────────
		$client_id = self::resolve_client(
			$owner_id,
			sanitize_text_field( $payload['client_name']    ?? '' ),
			sanitize_email(      $payload['client_email']   ?? '' ),
			sanitize_text_field( $payload['client_company'] ?? '' ),
			sanitize_text_field( $payload['client_phone']   ?? '' )
		);

		if ( is_wp_error( $client_id ) ) {
			return $client_id;
		}

		// If the client already exists and details may have changed, update them.
		if ( $client_id && ! empty( $payload['client_name'] ) ) {
			$wpdb->update(
				$wpdb->prefix . 'clientoctopus_clients',
				[
					'name'       => sanitize_text_field( $payload['client_name']    ?? '' ),
					'company'    => sanitize_text_field( $payload['client_company'] ?? '' ),
					'phone'      => sanitize_text_field( $payload['client_phone']   ?? '' ),
					'updated_at' => current_time( 'mysql' ),
				],
				[ 'id' => $client_id, 'owner_id' => $owner_id ]
			);
		}

		// ── Line items → total ───────────────────────────────────────────────
		$line_items   = is_array( $payload['line_items'] ?? null ) ? $payload['line_items'] : [];
		$discount_pct = (float) ( $payload['discount_pct'] ?? 0 );
		$vat_pct      = (float) ( $payload['vat_pct']      ?? 0 );
		$total_amount = self::calculate_total( $line_items, $discount_pct, $vat_pct );

		// ── Build content block ──────────────────────────────────────────────
		$template_id     = sanitize_key( $payload['template_id'] ?? 'blank' );
		$default_content = json_decode( ClientOctopus_Proposal_Template::default_content( $template_id ), true ) ?: [];
		$content         = array_merge( $default_content, [
			'template_id'     => $template_id,
			'line_items'      => self::sanitize_line_items( $line_items ),
			'discount_pct'    => $discount_pct,
			'vat_pct'         => $vat_pct,
			'deposit_pct'     => (int) ( $payload['deposit_pct']    ?? 0 ),
			'require_deposit' => ! empty( $payload['require_deposit'] ),
		] );

		// ── Update proposal ──────────────────────────────────────────────────
		$result = ClientOctopus_Proposal::update( $id, $owner_id, [
			'client_id'    => $client_id,
			'title'        => sanitize_text_field( $payload['title'] ?? __( 'Untitled Proposal', 'clientoctopus' ) ),
			'content'      => wp_json_encode( $content ),
			'total_amount' => $total_amount,
			'currency'     => strtoupper( sanitize_text_field( $payload['currency'] ?? 'GBP' ) ),
			'expiry_date'  => sanitize_text_field( $payload['expiry_date'] ?? '' ) ?: null,
			'template_id'  => $template_id,
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return ClientOctopus_Proposal::get( $id, $owner_id );
	}

	// ── Send to Client ────────────────────────────────────────────────────────

	/**
	 * Send a proposal to a client: mark as sent + email notification.
	 *
	 * @param int    $proposal_id
	 * @param int    $owner_id
	 * @param string $client_email  Override email (falls back to stored client email).
	 * @param string $email_subject Custom subject line (falls back to auto-generated).
	 *
	 * @return true|WP_Error
	 */
	public static function send_to_client( int $proposal_id, int $owner_id, string $client_email = '', string $email_subject = '' ): true|WP_Error {
		global $wpdb;

		$result = ClientOctopus_Proposal::send( $proposal_id, $owner_id, $client_email );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Fetch proposal + client data for notification email.
		$proposal = ClientOctopus_Proposal::get( $proposal_id, $owner_id );

		if ( is_wp_error( $proposal ) ) {
			return true; // Sent successfully; notification is best-effort.
		}

		$recipient = $client_email ?: ( $proposal['client_email'] ?? '' );

		// If the proposal has no client linked but we have an email address,
		// resolve or create the client record and link it now. Without this,
		// project auto-creation silently fails on acceptance because it requires
		// a client_id on the proposal row.
		if ( empty( $proposal['client_id'] ) && $recipient ) {
			$client_id = self::resolve_client( $owner_id, '', $recipient, '', '' );
			if ( $client_id && ! is_wp_error( $client_id ) ) {
				$wpdb->update(
					$wpdb->prefix . 'clientoctopus_proposals',
					[ 'client_id' => $client_id ],
					[ 'id' => $proposal_id ],
					[ '%d' ],
					[ '%d' ]
				);
			}
		}

		if ( $recipient ) {
			self::send_proposal_email( $proposal, $recipient, $email_subject );
		}

		return true;
	}

	// ── Expire Overdue ────────────────────────────────────────────────────────

	/**
	 * Auto-expire proposals whose expiry_date has passed.
	 *
	 * Intended to run as a daily WP-Cron job.
	 *
	 * @return int Number of proposals expired.
	 */
	public static function expire_overdue(): int {
		global $wpdb;

		// Sync any proposals whose linked project is completed but proposal status wasn't updated.
		$wpdb->query(
			"UPDATE {$wpdb->prefix}clientoctopus_proposals p
			 INNER JOIN {$wpdb->prefix}clientoctopus_projects pr ON pr.proposal_id = p.id
			 SET p.status = 'completed'
			 WHERE pr.status = 'completed'
			   AND p.status NOT IN ('completed', 'expired')"
		);

		// Send warning emails to clients whose proposals expire within 3 days.
		self::send_expiry_warnings();

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}clientoctopus_proposals
				 SET status = 'expired', updated_at = %s
				 WHERE status IN ('draft','sent','viewed')
				   AND expiry_date IS NOT NULL
				   AND expiry_date < %s",
				current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);

		return (int) $result;
	}

	/**
	 * Email clients whose proposals expire within 3 days. Sends at most once per proposal.
	 */
	private static function send_expiry_warnings(): void {
		global $wpdb;

		$expiring = $wpdb->get_results(
			"SELECT p.id, p.title, p.token, p.expiry_date, p.client_id
			 FROM {$wpdb->prefix}clientoctopus_proposals p
			 WHERE p.status IN ('sent','viewed')
			   AND p.expiry_date IS NOT NULL
			   AND p.expiry_date > NOW()
			   AND p.expiry_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)
			   AND p.deleted_at IS NULL"
		);

		foreach ( $expiring as $proposal ) {
			$flag_key = 'clientoctopus_expiry_warned_' . $proposal->id;
			if ( get_option( $flag_key ) ) {
				continue;
			}

			$client = get_userdata( (int) $proposal->client_id );
			if ( ! $client ) {
				continue;
			}

			$days    = max( 1, (int) ceil( ( strtotime( $proposal->expiry_date ) - time() ) / DAY_IN_SECONDS ) );
			/* translators: %d is the number of days until the proposal expires */
			$day_str = $days === 1 ? __( '1 day', 'clientoctopus' ) : sprintf( __( '%d days', 'clientoctopus' ), $days );

			wp_mail(
				$client->user_email,
				/* translators: %s is a human-readable time string like "3 days" */
				sprintf( __( 'Your proposal expires in %s', 'clientoctopus' ), $day_str ),
				clientoctopus_email_html( [
					'name'      => $client->display_name,
					'body'      => '<p style="margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;">Your proposal <em>' . esc_html( $proposal->title ) . '</em> will expire in <strong style="color:#1A1A2E;">' . esc_html( $day_str ) . '</strong>. Please review and respond before it expires.</p>',
					'cta_label' => __( 'Review Proposal', 'clientoctopus' ),
					'cta_url'   => home_url( '/proposals/' . $proposal->token ),
				] ),
				[ 'Content-Type: text/html; charset=UTF-8' ]
			);

			update_option( $flag_key, '1', false );
		}
	}

	/**
	 * Clear the expiry warning flag for a proposal (call on delete or manual expire).
	 */
	public static function clear_expiry_warning( int $proposal_id ): void {
		delete_option( 'clientoctopus_expiry_warned_' . $proposal_id );
	}

	// ── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Resolve or create a client record.
	 *
	 * If a client with the same email already exists for this owner,
	 * returns their ID. Otherwise creates a new record.
	 *
	 * Returns null (not an error) if both name and email are empty —
	 * proposals without clients are allowed.
	 *
	 * @param int    $owner_id
	 * @param string $name
	 * @param string $email
	 * @param string $company
	 * @param string $phone
	 *
	 * @return int|null|WP_Error Client ID, null, or WP_Error.
	 */
	private static function resolve_client( int $owner_id, string $name, string $email, string $company, string $phone ): int|null|WP_Error {
		global $wpdb;

		if ( ! $name && ! $email ) {
			return null;
		}

		// Look for existing client by email.
		if ( $email ) {
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}clientoctopus_clients
					 WHERE owner_id = %d AND email = %s
					 LIMIT 1",
					$owner_id,
					$email
				)
			);

			if ( $existing_id ) {
				return (int) $existing_id;
			}
		}

		// Create new client.
		$now = current_time( 'mysql' );

		$wpdb->insert(
			$wpdb->prefix . 'clientoctopus_clients',
			[
				'owner_id'   => $owner_id,
				'name'       => $name,
				'email'      => $email,
				'company'    => $company,
				'phone'      => $phone,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $wpdb->insert_id ) {
			return new WP_Error( 'client_create_failed', __( 'Failed to create client record.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Calculate proposal grand total from line items.
	 *
	 * @param array $line_items  Array of { qty, unit_price }.
	 * @param float $discount_pct
	 * @param float $vat_pct
	 *
	 * @return float
	 */
	private static function calculate_total( array $line_items, float $discount_pct, float $vat_pct ): float {
		$subtotal     = 0.0;

		foreach ( $line_items as $item ) {
			$qty        = max( 0, (float) ( $item['qty']        ?? 0 ) );
			$unit_price = max( 0, (float) ( $item['unit_price'] ?? 0 ) );
			$subtotal  += $qty * $unit_price;
		}

		$discount_amt = $subtotal * ( $discount_pct / 100 );
		$after_disc   = $subtotal - $discount_amt;
		$vat_amt      = $after_disc * ( $vat_pct / 100 );

		return round( $after_disc + $vat_amt, 2 );
	}

	/**
	 * Sanitize and normalise line items array.
	 *
	 * @param array $items
	 *
	 * @return array
	 */
	private static function sanitize_line_items( array $items ): array {
		$clean = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$clean[] = [
				'id'          => sanitize_key( $item['id'] ?? uniqid( 'li_', true ) ),
				'description' => sanitize_text_field( $item['description'] ?? '' ),
				'qty'         => max( 0, (float) ( $item['qty'] ?? 1 ) ),
				'unit_price'  => max( 0, (float) ( $item['unit_price'] ?? 0 ) ),
			];
		}

		return $clean;
	}

	/**
	 * Send the proposal notification email to the client.
	 *
	 * @param array  $proposal
	 * @param string $recipient
	 *
	 * @return void
	 */
	private static function send_proposal_email( array $proposal, string $recipient, string $email_subject = '' ): void {
		$owner        = get_user_by( 'ID', $proposal['owner_id'] );
		$site_name    = get_bloginfo( 'name' ) ?: 'Client Octopus';
		$from_display = ( $owner && $owner->display_name ) ? $owner->display_name : $site_name;
		$proposal_url = esc_url( get_site_url() . '/proposals/' . ( $proposal['token'] ?? $proposal['id'] ) );
		$title        = esc_html( $proposal['title'] ?? '' );
		$from_esc     = esc_html( $from_display );
		$expiry       = esc_html( $proposal['expiry_date'] ?? __( 'the specified date', 'clientoctopus' ) );

		if ( ! empty( trim( $email_subject ) ) ) {
			$subject = sanitize_text_field( $email_subject );
		} elseif ( ! empty( trim( $proposal['title'] ?? '' ) ) ) {
			/* translators: %s is the proposal title */
		$subject = sprintf( __( 'Proposal Received: %s', 'clientoctopus' ), $proposal['title'] );
		} else {
			/* translators: %s is the sender's display name */
			$subject = sprintf( __( 'Proposal Received from %s', 'clientoctopus' ), $from_display );
		}

		$body_html = "
			<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
				<strong style=\"color:#1A1A2E;\">{$from_esc}</strong> has sent you a new proposal.
			</p>
			<div style=\"margin:20px 0;padding:16px 20px;background:#F8F7FF;border-radius:10px;border-left:3px solid #6366F1;\">
				<p style=\"margin:0;font-size:16px;font-weight:600;color:#1A1A2E;\">{$title}</p>
				<p style=\"margin:6px 0 0;font-size:13px;color:#9CA3AF;\">Expires {$expiry}</p>
			</div>
			<p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">
				Click below to review the proposal and accept or decline.
			</p>";

		$message = clientoctopus_email_html( [
			'subject'   => $subject,
			'name'      => $proposal['client_name'] ?? '',
			'body'      => $body_html,
			'cta_label' => __( 'View Proposal', 'clientoctopus' ),
			'cta_url'   => $proposal_url,
		] );

		wp_mail(
			$recipient,
			$subject,
			$message,
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}
}

// ─── Register daily expiry cron ───────────────────────────────────────────────
add_action( 'clientoctopus_expire_proposals', [ 'ClientOctopus_Proposal_Handlers', 'expire_overdue' ] );

add_action( 'init', static function (): void {
	if ( ! wp_next_scheduled( 'clientoctopus_expire_proposals' ) ) {
		wp_schedule_event( time(), 'daily', 'clientoctopus_expire_proposals' );
	}
} );
