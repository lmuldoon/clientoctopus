<?php
/**
 * ClientOctopus_Recurring_Profile
 *
 * A recurring profile is a template that spawns a fresh, ordinary
 * clientoctopus_invoices row on a schedule. Default `billing_mode` is
 * 'manual' — the client pays each generated invoice via the existing
 * one-off Stripe/PayPal checkout flow, exactly like any other invoice.
 * An owner can opt a profile into `billing_mode = 'auto_charge'` (Stripe
 * only), which charges the client's saved card automatically instead —
 * see generate_next_invoice() and modules/payments/class-stripe.php.
 *
 * @package ClientOctopus
 * @since   1.1.4
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table references use $wpdb->prefix with hardcoded names, never user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClientOctopus_Recurring_Profile {

	public const FREQUENCIES = [ 'weekly', 'monthly', 'quarterly', 'yearly' ];
	public const BILLING_MODES = [ 'manual', 'auto_charge' ];

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Create a new recurring profile.
	 *
	 * @param int   $owner_id
	 * @param array $data {title, client_id, line_items, currency, discount_type,
	 *                      discount_value, vat_pct, vat_number, frequency, start_date,
	 *                      end_date, max_occurrences, po_number, payment_terms, notes}
	 * @return array|WP_Error
	 */
	public static function create( int $owner_id, array $data ): array|WP_Error {
		global $wpdb;

		if ( empty( $data['client_id'] ) ) {
			return new WP_Error( 'client_required', __( 'A client is required for a recurring profile.', 'clientoctopus' ), [ 'status' => 422 ] );
		}

		$billing_mode = self::sanitize_billing_mode( $data['billing_mode'] ?? 'manual', $owner_id );
		if ( is_wp_error( $billing_mode ) ) {
			return $billing_mode;
		}

		$table      = $wpdb->prefix . 'clientoctopus_recurring_profiles';
		$now        = current_time( 'mysql' );
		$start_date = ! empty( $data['start_date'] ) ? sanitize_text_field( $data['start_date'] ) : gmdate( 'Y-m-d' );
		$frequency  = in_array( $data['frequency'] ?? '', self::FREQUENCIES, true ) ? $data['frequency'] : 'monthly';

		$line_items = ! empty( $data['line_items'] ) && is_array( $data['line_items'] )
			? wp_json_encode( $data['line_items'] )
			: null;

		$inserted = $wpdb->insert(
			$table,
			[
				'owner_id'         => $owner_id,
				'client_id'        => (int) $data['client_id'],
				'title'            => sanitize_text_field( $data['title'] ?? '' ),
				'po_number'        => ! empty( $data['po_number'] ) ? sanitize_text_field( $data['po_number'] ) : null,
				'payment_terms'    => ! empty( $data['payment_terms'] ) ? sanitize_text_field( $data['payment_terms'] ) : null,
				'notes'            => ! empty( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
				'line_items'       => $line_items,
				'currency'         => strtoupper( sanitize_text_field( $data['currency'] ?? 'GBP' ) ),
				'discount_type'    => in_array( $data['discount_type'] ?? '', [ 'percentage', 'fixed' ], true )
					? $data['discount_type']
					: 'percentage',
				'discount_value'   => max( 0, (float) ( $data['discount_value'] ?? 0 ) ),
				'vat_pct'          => max( 0, min( 100, (float) ( $data['vat_pct'] ?? 0 ) ) ),
				'vat_number'       => ! empty( $data['vat_number'] ) ? sanitize_text_field( $data['vat_number'] ) : null,
				'frequency'        => $frequency,
				'start_date'       => $start_date,
				'end_date'         => ! empty( $data['end_date'] ) ? sanitize_text_field( $data['end_date'] ) : null,
				'max_occurrences'  => ! empty( $data['max_occurrences'] ) ? max( 1, (int) $data['max_occurrences'] ) : null,
				'occurrences_sent' => 0,
				'next_run_date'    => $start_date,
				'billing_mode'     => $billing_mode,
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return new WP_Error( 'recurring_profile_create_failed', __( 'Failed to create recurring profile.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		$profile_id = (int) $wpdb->insert_id;
		$profile    = self::get( $profile_id, $owner_id );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		// Generate + send the first invoice immediately rather than waiting for
		// tomorrow's cron — matches the instant result creating a normal invoice
		// gives. Non-fatal if this fails: the profile still exists and next_run_date
		// is left untouched on failure, so the cron retries this same cycle.
		//
		// Only do this if the start date has already arrived — otherwise the
		// profile's own next_run_date (= start_date, set above) is already in
		// the future, and the existing process_due() cron will pick it up on
		// that date. Without this guard, a profile created with a future start
		// date would still bill immediately, ignoring the chosen start date.
		$profile['first_invoice'] = null;
		if ( $start_date <= gmdate( 'Y-m-d' ) ) {
			$first_invoice_id = self::generate_next_invoice( $profile_id );
			$profile['first_invoice'] = ( ! is_wp_error( $first_invoice_id ) && class_exists( 'ClientOctopus_Invoice' ) )
				? ClientOctopus_Invoice::get( $first_invoice_id, $owner_id )
				: null;
			if ( is_wp_error( $profile['first_invoice'] ?? null ) ) {
				$profile['first_invoice'] = null;
			}
		}

		// Re-fetch so the returned profile reflects the advanced next_run_date /
		// occurrences_sent that generate_next_invoice() just wrote, not the stale
		// pre-generation values captured above.
		$refreshed = self::get( $profile_id, $owner_id );
		if ( ! is_wp_error( $refreshed ) ) {
			$refreshed['first_invoice'] = $profile['first_invoice'];
			$profile = $refreshed;
		}

		return $profile;
	}

	/**
	 * Update an active or paused recurring profile. Cancelled profiles are read-only.
	 *
	 * @param int   $id
	 * @param int   $owner_id
	 * @param array $data
	 * @return array|WP_Error
	 */
	public static function update( int $id, int $owner_id, array $data ): array|WP_Error {
		global $wpdb;

		$table   = $wpdb->prefix . 'clientoctopus_recurring_profiles';
		$current = self::get( $id, $owner_id );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( 'cancelled' === $current['status'] ) {
			return new WP_Error( 'recurring_profile_cancelled', __( 'A cancelled recurring profile cannot be edited.', 'clientoctopus' ), [ 'status' => 422 ] );
		}

		// Mirrors the frequency fallback pattern just below: only override billing_mode
		// when the request actually names a recognized value, otherwise keep whatever
		// the profile already has (REST arg defaults mean an omitted field still shows
		// up here as 'manual', so a plain absence check isn't enough on its own).
		$billing_mode_requested = in_array( $data['billing_mode'] ?? '', self::BILLING_MODES, true )
			? $data['billing_mode']
			: $current['billing_mode'];
		$billing_mode = self::sanitize_billing_mode( $billing_mode_requested, $owner_id );
		if ( is_wp_error( $billing_mode ) ) {
			return $billing_mode;
		}

		// $current['line_items'] comes back JSON-decoded from self::get() — re-encode
		// it for storage when the edit doesn't include a genuine replacement, instead
		// of following every other nullable field's null-if-absent fallback (which
		// would silently wipe existing line items on any edit that omits them).
		$line_items = array_key_exists( 'line_items', $data ) && ! empty( $data['line_items'] ) && is_array( $data['line_items'] )
			? wp_json_encode( $data['line_items'] )
			: ( ! empty( $current['line_items'] ) ? wp_json_encode( $current['line_items'] ) : null );

		$final_frequency = in_array( $data['frequency'] ?? '', self::FREQUENCIES, true ) ? $data['frequency'] : $current['frequency'];

		// Editing frequency after cycles have already gone out leaves next_run_date
		// stuck on the old cadence otherwise — recompute it from the original
		// start_date/occurrences_sent using the (possibly new) frequency.
		$next_run_date = ( (int) $current['occurrences_sent'] > 0 )
			? self::calculate_next_run_date( $current['start_date'], $final_frequency, (int) $current['occurrences_sent'] )
			: $current['next_run_date'];

		$wpdb->update(
			$table,
			[
				'title'           => sanitize_text_field( $data['title'] ?? $current['title'] ),
				'po_number'       => array_key_exists( 'po_number', $data )
					? ( ! empty( $data['po_number'] ) ? sanitize_text_field( $data['po_number'] ) : null )
					: $current['po_number'],
				'payment_terms'   => array_key_exists( 'payment_terms', $data )
					? ( ! empty( $data['payment_terms'] ) ? sanitize_text_field( $data['payment_terms'] ) : null )
					: $current['payment_terms'],
				'notes'           => array_key_exists( 'notes', $data )
					? ( ! empty( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null )
					: $current['notes'],
				'client_id'       => ! empty( $data['client_id'] ) ? (int) $data['client_id'] : $current['client_id'],
				'line_items'      => $line_items,
				'currency'        => strtoupper( sanitize_text_field( $data['currency'] ?? $current['currency'] ) ),
				'discount_type'   => in_array( $data['discount_type'] ?? '', [ 'percentage', 'fixed' ], true )
					? $data['discount_type']
					: $current['discount_type'],
				'discount_value'  => max( 0, (float) ( $data['discount_value'] ?? $current['discount_value'] ) ),
				'vat_pct'         => max( 0, min( 100, (float) ( $data['vat_pct'] ?? $current['vat_pct'] ) ) ),
				'vat_number'      => array_key_exists( 'vat_number', $data )
					? ( ! empty( $data['vat_number'] ) ? sanitize_text_field( $data['vat_number'] ) : null )
					: $current['vat_number'],
				'frequency'       => $final_frequency,
				'next_run_date'   => $next_run_date,
				'end_date'        => array_key_exists( 'end_date', $data )
					? ( ! empty( $data['end_date'] ) ? sanitize_text_field( $data['end_date'] ) : null )
					: $current['end_date'],
				'max_occurrences' => array_key_exists( 'max_occurrences', $data )
					? ( ! empty( $data['max_occurrences'] ) ? max( 1, (int) $data['max_occurrences'] ) : null )
					: $current['max_occurrences'],
				'billing_mode'    => $billing_mode,
				'updated_at'      => current_time( 'mysql' ),
			],
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		return self::get( $id, $owner_id );
	}

	/**
	 * Validate a requested billing_mode value.
	 *
	 * 'auto_charge' is Stripe-only — PayPal has no reusable saved-payment-method
	 * flow yet (see modules/payments/class-paypal.php docblock), so reject it
	 * outright at this layer rather than relying solely on the admin UI hiding
	 * the toggle when PayPal is the configured gateway.
	 *
	 * @param mixed $value
	 * @return string|WP_Error
	 */
	/**
	 * Whether $owner_id is actually allowed to use auto-charge right now: the
	 * use_payments entitlement (mirrors every other payment-touching code path
	 * in the plugin) plus the configured gateway being genuinely usable
	 * (is_configured()), not just set to a recognized option value — which is
	 * nearly always true since that's the option's own default.
	 *
	 * Public so callers that need to decide whether to even OFFER auto_charge
	 * (e.g. clientoctopus.php's proposal-acceptance flow, which must silently
	 * downgrade to manual rather than let create() reject the whole profile)
	 * can check this before ever constructing a billing_mode value.
	 *
	 * @param int $owner_id
	 */
	public static function can_auto_charge( int $owner_id ): bool {
		if ( true !== clientoctopus_can_user( $owner_id, 'use_payments' ) ) {
			return false;
		}

		$provider = get_option( 'clientoctopus_payment_provider', 'stripe' );

		if ( 'paypal' === $provider ) {
			if ( ! class_exists( 'ClientOctopus_PayPal' ) ) {
				$paypal_class = CLIENTOCTOPUS_DIR . 'modules/payments/class-paypal.php';
				if ( file_exists( $paypal_class ) ) {
					require_once $paypal_class;
				}
			}
			return class_exists( 'ClientOctopus_PayPal' ) && ClientOctopus_PayPal::is_configured();
		}

		if ( 'stripe' === $provider ) {
			if ( ! class_exists( 'ClientOctopus_Stripe' ) ) {
				$stripe_class = CLIENTOCTOPUS_DIR . 'modules/payments/class-stripe.php';
				if ( file_exists( $stripe_class ) ) {
					require_once $stripe_class;
				}
			}
			return class_exists( 'ClientOctopus_Stripe' ) && ClientOctopus_Stripe::is_configured();
		}

		return false;
	}

	private static function sanitize_billing_mode( mixed $value, int $owner_id ): string|WP_Error {
		$mode = in_array( $value, self::BILLING_MODES, true ) ? $value : 'manual';

		if ( 'manual' === $mode ) {
			return $mode;
		}

		if ( ! self::can_auto_charge( $owner_id ) ) {
			return new WP_Error(
				'auto_charge_unavailable',
				__( 'Auto-charge requires a plan with payments enabled and Stripe or PayPal fully configured.', 'clientoctopus' ),
				[ 'status' => 422 ]
			);
		}

		return $mode;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function get( int $id, int $owner_id ): array|WP_Error {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_recurring_profiles';
		$ct    = $wpdb->prefix . 'clientoctopus_clients';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.*, c.name AS _client_name,
				        c.stripe_pm_brand AS _client_card_brand, c.stripe_pm_last4 AS _client_card_last4,
			        c.paypal_payer_email AS _client_paypal_email
				 FROM {$table} p
				 LEFT JOIN {$ct} c ON c.id = p.client_id
				 WHERE p.id = %d AND p.owner_id = %d LIMIT 1",
				$id,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'recurring_profile_not_found', __( 'Recurring profile not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return self::format_row( $row );
	}

	private const LIST_STATUSES = [ 'active', 'paused', 'cancelled', 'past_due' ];

	/**
	 * List recurring profiles for an owner.
	 *
	 * @param array $args { page, per_page, status }
	 *
	 * @return array { profiles: [], total: int, pages: int, page: int, per_page: int, counts: array }
	 */
	public static function list( int $owner_id, array $args = [] ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_recurring_profiles';
		$ct    = $wpdb->prefix . 'clientoctopus_clients';

		$page          = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page      = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset        = ( $page - 1 ) * $per_page;
		$status_filter = isset( $args['status'] ) && in_array( $args['status'], self::LIST_STATUSES, true )
			? $args['status']
			: null;

		$where = [ $wpdb->prepare( 'p.owner_id = %d', $owner_id ) ];
		if ( $status_filter ) {
			$where[] = $wpdb->prepare( 'p.status = %s', $status_filter );
		}
		$where_sql = implode( ' AND ', $where );

		// $where_sql components are individually prepared above; no outer prepare() needed.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} p WHERE {$where_sql}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, c.name AS _client_name,
				        c.stripe_pm_brand AS _client_card_brand, c.stripe_pm_last4 AS _client_card_last4,
			        c.paypal_payer_email AS _client_paypal_email
				 FROM {$table} p
				 LEFT JOIN {$ct} c ON c.id = p.client_id
				 WHERE {$where_sql}
				 ORDER BY p.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		// Per-status counts for the tab badges — always reflects the full,
		// unfiltered-by-status set (matches the pre-pagination client-side
		// behavior, which counted the entire fetched list regardless of tab).
		$counts = array_fill_keys( self::LIST_STATUSES, 0 );
		$counts['all'] = 0;
		$count_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$table} WHERE owner_id = %d GROUP BY status", $owner_id )
		);
		foreach ( $count_rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->c;
			}
			$counts['all'] += (int) $row->c;
		}

		return [
			'profiles' => array_map( [ self::class, 'format_row' ], $rows ?: [] ),
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
			'counts'   => $counts,
		];
	}

	public static function pause( int $id, int $owner_id ): true|WP_Error {
		return self::set_status( $id, $owner_id, [ 'active' ], 'paused' );
	}

	/**
	 * Resume a paused profile, or reactivate one that auto-paused after
	 * exhausting its auto-charge retries ('past_due') — e.g. once the client
	 * has updated their card. Reactivating from past_due also clears the
	 * failure streak so it isn't immediately re-flagged on the next cycle.
	 */
	public static function resume( int $id, int $owner_id ): true|WP_Error {
		$current = self::get( $id, $owner_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$result = self::set_status( $id, $owner_id, [ 'paused', 'past_due' ], 'active' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'past_due' === $current['status'] ) {
			// Retry state lives on the invoice (DB version 29) — give every
			// currently-unpaid invoice under this profile a clean retry slate
			// rather than touching the now-unused columns on the profile itself.
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}clientoctopus_invoices
					 SET retry_count = 0, last_failure_at = NULL
					 WHERE recurring_profile_id = %d AND status IN ('sent','overdue') AND deleted_at IS NULL",
					$id
				)
			);
		}

		return true;
	}

	public static function cancel( int $id, int $owner_id ): true|WP_Error {
		global $wpdb;

		$current = self::get( $id, $owner_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 'cancelled' === $current['status'] ) {
			return true;
		}

		$wpdb->update(
			$wpdb->prefix . 'clientoctopus_recurring_profiles',
			[ 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		return true;
	}

	/**
	 * @param string[] $from Valid current statuses this transition is allowed from.
	 * @param string   $to   Status to transition to.
	 */
	private static function set_status( int $id, int $owner_id, array $from, string $to ): true|WP_Error {
		global $wpdb;

		$current = self::get( $id, $owner_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! in_array( $current['status'], $from, true ) ) {
			return new WP_Error(
				'recurring_profile_invalid_transition',
				/* translators: 1: current status, 2: required status(es) */
				sprintf( __( 'This profile is %1$s; only a %2$s profile can be changed this way.', 'clientoctopus' ), $current['status'], implode( ' or ', $from ) ),
				[ 'status' => 422 ]
			);
		}

		$wpdb->update(
			$wpdb->prefix . 'clientoctopus_recurring_profiles',
			[ 'status' => $to, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		return true;
	}

	// ── Cron entry points ─────────────────────────────────────────────────────

	/**
	 * Generate + send the next invoice for every active profile due today or
	 * earlier. Called from the daily cron (see clientoctopus.php).
	 */
	public static function process_due(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_recurring_profiles';
		$ids   = $wpdb->get_col(
			"SELECT id FROM {$table} WHERE status = 'active' AND next_run_date <= CURDATE()"
		);

		foreach ( $ids as $profile_id ) {
			self::generate_next_invoice( (int) $profile_id );
		}
	}

	/**
	 * Build and send the next invoice for this recurring profile, then advance
	 * the schedule (or auto-cancel the profile if its end condition is reached).
	 *
	 * No owner-scoping — this runs from a system/cron context, not a request
	 * made on behalf of a specific logged-in user.
	 *
	 * @return int|WP_Error New invoice ID, or error.
	 */
	public static function generate_next_invoice( int $profile_id ): int|WP_Error {
		global $wpdb;

		$table   = $wpdb->prefix . 'clientoctopus_recurring_profiles';
		$profile = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'active' LIMIT 1", $profile_id ),
			ARRAY_A
		);

		if ( ! $profile ) {
			return new WP_Error( 'recurring_profile_not_found', __( 'Recurring profile not found or not active.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		if ( ! class_exists( 'ClientOctopus_Invoice' ) ) {
			$invoice_class = CLIENTOCTOPUS_DIR . 'modules/invoices/class-invoice.php';
			if ( file_exists( $invoice_class ) ) {
				require_once $invoice_class;
			}
		}
		if ( ! class_exists( 'ClientOctopus_Invoice' ) ) {
			return new WP_Error( 'invoice_class_missing', __( 'Invoice module unavailable.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		$line_items = ! empty( $profile['line_items'] ) ? json_decode( $profile['line_items'], true ) : [];

		$invoice = ClientOctopus_Invoice::create( (int) $profile['owner_id'], [
			'title'                => $profile['title'],
			'client_id'            => (int) $profile['client_id'],
			'currency'             => $profile['currency'],
			'line_items'           => is_array( $line_items ) ? $line_items : [],
			'discount_type'        => $profile['discount_type'],
			'discount_value'       => $profile['discount_value'],
			'vat_pct'              => $profile['vat_pct'],
			'vat_number'           => $profile['vat_number'],
			'issue_date'           => gmdate( 'Y-m-d' ),
			'due_date'             => gmdate( 'Y-m-d', strtotime( '+14 days' ) ),
			'po_number'            => $profile['po_number'],
			'payment_terms'        => $profile['payment_terms'],
			'notes'                => $profile['notes'],
			'recurring_profile_id' => $profile_id,
		] );

		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		// Fires clientoctopus_invoice_sent, which both emails the client and
		// dispatches the outbound webhook — see modules/invoices/handlers.php.
		// If this fails, the invoice stays in draft and the profile's schedule
		// is NOT advanced below, so the next cron run retries this same cycle
		// (send() is idempotent — draft/sent/overdue can all be (re-)sent).
		$sent = ClientOctopus_Invoice::send( (int) $invoice['id'], (int) $profile['owner_id'] );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		// Auto-charge: only ever does anything from the SECOND invoice onward —
		// the first invoice is always paid manually, which is what captures the
		// client's card (see rest-api/invoices.php's setup_future_usage flag).
		// No card on file yet is the expected state for invoice #1, not an error.
		if ( 'auto_charge' === $profile['billing_mode'] ) {
			self::attempt_auto_charge( (int) $invoice['id'], $profile_id );
		}

		$occurrences = (int) $profile['occurrences_sent'] + 1;
		$next_run    = self::calculate_next_run_date( $profile['start_date'], $profile['frequency'], $occurrences );

		$updates = [
			'next_run_date'    => $next_run,
			'occurrences_sent' => $occurrences,
			'updated_at'       => current_time( 'mysql' ),
		];

		$reached_end_date  = ! empty( $profile['end_date'] ) && $next_run > $profile['end_date'];
		$reached_max_count = ! empty( $profile['max_occurrences'] ) && $occurrences >= (int) $profile['max_occurrences'];
		if ( $reached_end_date || $reached_max_count ) {
			$updates['status'] = 'cancelled';
		}

		$wpdb->update( $table, $updates, [ 'id' => $profile_id ] );

		return (int) $invoice['id'];
	}

	/**
	 * Max consecutive off-session charge failures before a profile is auto-paused.
	 *
	 * @var int
	 */
	private const MAX_CHARGE_RETRIES = 3;

	/**
	 * Days to wait between retry attempts on a failed auto-charge.
	 *
	 * @var int
	 */
	private const RETRY_INTERVAL_DAYS = 3;

	/**
	 * Stripe error codes that mean "we couldn't even attempt the charge"
	 * (missing/misconfigured credentials, network failure, unparsable
	 * response) as opposed to a genuine gateway-returned decline. These must
	 * never count against an invoice's retry streak — a transient outage or
	 * a pulled API key shouldn't auto-pause a profile whose card is fine.
	 *
	 * @var string[]
	 */
	private const INFRASTRUCTURE_ERROR_CODES = [
		'stripe_not_configured', 'stripe_http_error', 'stripe_invalid_response',
		'paypal_not_configured', 'paypal_http_error', 'paypal_auth_failed',
	];

	/**
	 * Attempt to auto-charge the client's saved card (Stripe or PayPal,
	 * whichever this site is configured for) for a generated invoice.
	 * No-ops silently if no card is on file yet (expected for a profile's
	 * first invoice, which is always paid manually).
	 *
	 * Called both from generate_next_invoice() (the first attempt on a fresh
	 * invoice) and from the retry cron (subsequent attempts on the same
	 * still-unpaid invoice) — see retry_failed_charges(). Retry/failure state
	 * lives on the INVOICE, not the profile — see DB version 29 migration note
	 * in database/schema.php for why (a later invoice's success must never
	 * erase an earlier, still-unpaid invoice's failure streak).
	 *
	 * @param int $invoice_id
	 * @param int $profile_id
	 */
	public static function attempt_auto_charge( int $invoice_id, int $profile_id ): void {
		global $wpdb;

		$invoice = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, owner_id, client_id, status, total_amount, currency, retry_count
				 FROM {$wpdb->prefix}clientoctopus_invoices WHERE id = %d AND deleted_at IS NULL",
				$invoice_id
			),
			ARRAY_A
		);
		// Already paid/cancelled by the time we got here (e.g. client paid
		// manually while a retry was pending) — nothing to do.
		if ( ! $invoice || ! in_array( $invoice['status'], [ 'sent', 'overdue' ], true ) ) {
			return;
		}

		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT stripe_customer_id, stripe_payment_method_id, paypal_vault_id
				 FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d",
				(int) $invoice['client_id']
			),
			ARRAY_A
		);
		if ( ! $client ) {
			return;
		}

		$amount_int = (int) round( (float) $invoice['total_amount'] * 100 );
		$currency   = strtolower( (string) $invoice['currency'] );
		$amount_major = (float) $invoice['total_amount'];

		// Stable per logical attempt (this invoice, this attempt number) — a
		// concurrent or retried call for the exact same attempt collapses to
		// one real charge instead of two.
		$idempotency_key = "co_recur_{$invoice_id}_" . ( (int) $invoice['retry_count'] + 1 );
		$metadata        = [ 'type' => 'invoice', 'invoice_id' => $invoice_id ];

		$provider = 'paypal' === get_option( 'clientoctopus_payment_provider', 'stripe' ) ? 'paypal' : 'stripe';

		if ( 'paypal' === $provider ) {
			if ( empty( $client['paypal_vault_id'] ) ) {
				return;
			}
			if ( ! class_exists( 'ClientOctopus_PayPal' ) ) {
				$paypal_class = CLIENTOCTOPUS_DIR . 'modules/payments/class-paypal.php';
				if ( file_exists( $paypal_class ) ) {
					require_once $paypal_class;
				}
			}
			if ( ! class_exists( 'ClientOctopus_PayPal' ) ) {
				return;
			}
			$result = ClientOctopus_PayPal::charge_with_vault(
				$client['paypal_vault_id'],
				$amount_major,
				strtoupper( $currency ),
				$metadata,
				$idempotency_key
			);
		} else {
			if ( empty( $client['stripe_customer_id'] ) || empty( $client['stripe_payment_method_id'] ) ) {
				return;
			}
			if ( ! class_exists( 'ClientOctopus_Stripe' ) ) {
				$stripe_class = CLIENTOCTOPUS_DIR . 'modules/payments/class-stripe.php';
				if ( file_exists( $stripe_class ) ) {
					require_once $stripe_class;
				}
			}
			if ( ! class_exists( 'ClientOctopus_Stripe' ) ) {
				return;
			}
			$result = ClientOctopus_Stripe::charge_off_session(
				$client['stripe_customer_id'],
				$client['stripe_payment_method_id'],
				$amount_int,
				$currency,
				$metadata,
				$idempotency_key
			);
		}

		// PayPal's Merchant-Initiated Transaction framework is designed to
		// exempt routine vault-based recharges from needing buyer action again
		// (SCA is normally satisfied once, at the original vaulting payment),
		// so this isn't the expected path — but the API contract only
		// guarantees a 200 OK with status reflecting the real outcome, not
		// that the outcome is always 'COMPLETED'. Checking status explicitly
		// (instead of just "no WP_Error") stops a non-completed order (e.g.
		// PAYER_ACTION_REQUIRED) from being wrongly marked paid.
		$paypal_completed = 'paypal' === $provider && is_array( $result ) && 'COMPLETED' === ( $result['status'] ?? '' );

		if ( ! is_wp_error( $result ) && ( 'stripe' === $provider || $paypal_completed ) ) {
			// PayPal: capture id lives at purchase_units[0].payments.captures[0].id;
			// order id (top-level) is the natural gateway_id. Stripe: the
			// PaymentIntent id serves as both (no Checkout Session exists here).
			$gateway_id = (string) ( $result['id'] ?? '' );
			$charge_id  = 'paypal' === $provider
				? (string) ( $result['purchase_units'][0]['payments']['captures'][0]['id'] ?? $gateway_id )
				: (string) ( $result['id'] ?? '' );

			ClientOctopus_Invoice::mark_paid_for_provider( $invoice_id, $gateway_id, $charge_id, $provider );
			return;
		}

		// An infrastructure-class error (bad/missing credentials, network blip,
		// malformed response) means the charge was never actually attempted
		// against the card — don't blame the client's card or burn a retry
		// for a problem on our side. Leave retry_count untouched.
		//
		// Consequence worth guarding against: since retry_count never advances
		// past 0 here, this invoice can never become eligible for
		// retry_failed_charges() either (its query requires retry_count BETWEEN
		// 1 AND MAX-1) — a persistently broken integration means exactly ONE
		// silent attempt per invoice, forever, with no owner-facing signal at
		// all, for as long as the misconfiguration lasts. Notify the owner
		// (throttled — a broken key can otherwise trigger this on every new
		// invoice/cron cycle) so a real config problem doesn't go unnoticed
		// indefinitely, without touching the card-decline dunning state.
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), self::INFRASTRUCTURE_ERROR_CODES, true ) ) {
			self::notify_infrastructure_error( $profile_id, (int) $invoice['owner_id'], $provider );
			return;
		}

		// Distinguish "needs one-time verification, card is fine" from a
		// genuine decline — both currently ended up with identical "your card
		// was declined" messaging. Stripe surfaces this as a specific
		// WP_Error code (see class-stripe.php); PayPal would surface it as a
		// non-error PAYER_ACTION_REQUIRED status (caught by $paypal_completed
		// being false above without an error) rather than a WP_Error.
		$is_stripe_auth_required = is_wp_error( $result ) && 'authentication_required' === $result->get_error_code();
		$is_paypal_action_required = 'paypal' === $provider && ! is_wp_error( $result ) && is_array( $result )
			&& 'PAYER_ACTION_REQUIRED' === ( $result['status'] ?? '' );
		$reason = ( $is_stripe_auth_required || $is_paypal_action_required ) ? 'authentication_required' : 'decline';

		self::record_charge_failure( $invoice_id, $profile_id, $provider, $reason );
	}

	/**
	 * Record a failed auto-charge attempt on the invoice itself: fire the
	 * standard payment-failed notification/hook (same one manual declines
	 * use), then bump ITS retry streak — auto-pausing the parent profile once
	 * MAX_CHARGE_RETRIES is hit on this invoice specifically.
	 *
	 * @param int    $invoice_id
	 * @param int    $profile_id
	 * @param string $provider
	 * @param string $reason 'decline' | 'authentication_required' — selects
	 *                       the notification copy; retry/dunning mechanics are
	 *                       identical either way.
	 */
	private static function record_charge_failure( int $invoice_id, int $profile_id, string $provider = 'stripe', string $reason = 'decline' ): void {
		global $wpdb;

		$payments_api = CLIENTOCTOPUS_DIR . 'rest-api/payments.php';
		if ( ! function_exists( 'clientoctopus_handle_payment_failed' ) && file_exists( $payments_api ) ) {
			require_once $payments_api;
		}
		if ( function_exists( 'clientoctopus_handle_payment_failed' ) ) {
			clientoctopus_handle_payment_failed( [
				'provider'   => $provider,
				'gateway_id' => '',
				'metadata'   => [ 'type' => 'invoice', 'invoice_id' => $invoice_id, 'reason' => $reason ],
			] );
		}

		$retry_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT retry_count FROM {$wpdb->prefix}clientoctopus_invoices WHERE id = %d", $invoice_id )
		) + 1;

		$wpdb->update(
			$wpdb->prefix . 'clientoctopus_invoices',
			[ 'retry_count' => $retry_count, 'last_failure_at' => current_time( 'mysql' ) ],
			[ 'id' => $invoice_id ]
		);

		if ( $retry_count >= self::MAX_CHARGE_RETRIES ) {
			// retry_failed_charges() can process several invoices per cron run, each
			// a real HTTP round-trip — an owner pausing/cancelling this profile
			// mid-loop must never be silently overwritten back to past_due. Make the
			// transition atomic and conditional on the row still being 'active' right
			// now, rather than trusting a status read moments earlier.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}clientoctopus_recurring_profiles SET status = 'past_due' WHERE id = %d AND status = 'active'",
					$profile_id
				)
			);
			if ( $wpdb->rows_affected > 0 ) {
				$profile = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT owner_id, title FROM {$wpdb->prefix}clientoctopus_recurring_profiles WHERE id = %d",
						$profile_id
					),
					ARRAY_A
				);
				if ( $profile ) {
					self::notify_past_due( (int) $profile['owner_id'], $profile['title'], $reason );

					$invoice_client = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT i.token, c.name, c.email
							 FROM {$wpdb->prefix}clientoctopus_invoices i
							 LEFT JOIN {$wpdb->prefix}clientoctopus_clients c ON c.id = i.client_id
							 WHERE i.id = %d",
							$invoice_id
						),
						ARRAY_A
					);
					if ( $invoice_client && ! empty( $invoice_client['email'] ) ) {
						self::notify_client_past_due( $invoice_client, $profile['title'], $reason );
					}
				}
			}
		}
	}

	/**
	 * Email the owner once a profile auto-pauses after exhausting its charge
	 * retries — this is the one case that needs explicit "action needed"
	 * framing rather than the generic per-attempt failure notice.
	 *
	 * @param int    $owner_id
	 * @param string $title
	 * @param string $reason 'decline' | 'authentication_required' — the
	 *                       triggering attempt's failure reason, used for copy only.
	 */
	private static function notify_past_due( int $owner_id, string $title, string $reason = 'decline' ): void {
		$owner = get_userdata( $owner_id );
		if ( ! $owner ) {
			return;
		}

		$title = $title ?: __( 'Recurring billing', 'clientoctopus' );

		$explanation = 'authentication_required' === $reason
			? __( "has been paused because the client's bank keeps asking for one-time verification on this payment — their card itself may be fine.", 'clientoctopus' )
			/* translators: %d is the number of failed attempts */
			: sprintf( __( 'has been paused after %d failed attempts to charge the client\'s saved card.', 'clientoctopus' ), self::MAX_CHARGE_RETRIES );

		wp_mail(
			$owner->user_email,
			/* translators: %s is the recurring profile title */
			sprintf( __( '⏸ Auto-charge paused for "%s"', 'clientoctopus' ), sanitize_text_field( $title ) ),
			clientoctopus_email_html( [
				'name'      => $owner->display_name,
				/* translators: 1: profile title, 2: explanation of why it paused */
				'body'      => sprintf(
					"<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">Auto-charge for <em>%s</em> %s</p><p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">Ask your client to complete a payment on the outstanding invoice — this resolves either case — or switch this profile back to manual billing, then resume it.</p>",
					esc_html( $title ),
					$explanation
				),
				'cta_label' => __( 'View Recurring Billing', 'clientoctopus' ),
				'cta_url'   => admin_url( 'admin.php?page=clientoctopus-invoices' ),
			] ),
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}

	/**
	 * Email the client once a profile auto-pauses after exhausting its charge
	 * retries — the client-facing counterpart to notify_past_due(). Previously
	 * the client got no signal at all when this happened; the per-attempt
	 * failure emails don't mention that billing has now actually stopped, just
	 * that one attempt failed. Points at the same outstanding invoice's pay
	 * page — paying it re-vaults a working card and (via the
	 * clientoctopus_invoice_paid listener in modules/invoices/handlers.php)
	 * automatically resumes the profile, with no separate "update card" flow
	 * needed.
	 *
	 * @param array  $client { token, name, email } — the still-unpaid invoice's
	 *                        token and the client's own name/email.
	 * @param string $title  Recurring profile title.
	 * @param string $reason 'decline' | 'authentication_required' — the
	 *                       triggering attempt's failure reason, used for copy only.
	 */
	private static function notify_client_past_due( array $client, string $title, string $reason = 'decline' ): void {
		$title = $title ?: __( 'Recurring billing', 'clientoctopus' );

		$explanation = 'authentication_required' === $reason
			? __( "Your bank keeps asking us to verify this payment before it can go through — this doesn't mean your card is broken, it just needs a one-time confirmation from you.", 'clientoctopus' )
			: __( "We've been unable to charge your card after several attempts, so automatic billing has been paused.", 'clientoctopus' );

		wp_mail(
			$client['email'],
			__( 'Action needed — automatic billing has paused', 'clientoctopus' ),
			clientoctopus_email_html( [
				'name'      => $client['name'] ?? '',
				/* translators: 1: explanation of why billing paused, 2: recurring profile title */
				'body'      => sprintf(
					"<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">%s (<em>%s</em>)</p><p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">Paying your outstanding invoice below will confirm your payment method and resume automatic billing — no other action is needed.</p>",
					$explanation,
					esc_html( $title )
				),
				'cta_label' => __( 'Pay Outstanding Invoice', 'clientoctopus' ),
				'cta_url'   => site_url( '/invoices/' . $client['token'] ),
			] ),
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}

	/**
	 * Seconds to throttle infrastructure-error notifications by, per profile —
	 * a persistently broken API key/connection would otherwise re-trigger this
	 * on every affected invoice and every cron cycle.
	 *
	 * @var int
	 */
	private const INFRA_ERROR_NOTIFY_THROTTLE = DAY_IN_SECONDS;

	/**
	 * Notify the owner that auto-charge couldn't even attempt a charge due to
	 * a configuration/connectivity problem (not a card decline) — see the
	 * call site in attempt_auto_charge() for why this needs to exist at all.
	 * Throttled per profile so a persistent outage sends one email, not one
	 * per invoice per cron run.
	 *
	 * @param int    $profile_id
	 * @param int    $owner_id
	 * @param string $provider 'stripe' | 'paypal'.
	 */
	private static function notify_infrastructure_error( int $profile_id, int $owner_id, string $provider ): void {
		$throttle_key = "clientoctopus_autocharge_infra_err_{$profile_id}";
		if ( get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, self::INFRA_ERROR_NOTIFY_THROTTLE );

		$owner = get_userdata( $owner_id );
		if ( ! $owner ) {
			return;
		}

		$gateway_label = 'paypal' === $provider ? 'PayPal' : 'Stripe';

		wp_mail(
			$owner->user_email,
			__( '⚠️ Auto-charge couldn\'t run — payment setup needs attention', 'clientoctopus' ),
			clientoctopus_email_html( [
				'name'      => $owner->display_name,
				/* translators: %s is the gateway name, e.g. Stripe or PayPal */
				'body'      => sprintf(
					"<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">A scheduled auto-charge couldn't run because your %s connection isn't working — this could be a missing or revoked API key, or a temporary connection problem.</p><p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">This is not a declined card — no charge was attempted. Please check your payment settings.</p>",
					esc_html( $gateway_label )
				),
				'cta_label' => __( 'Check Payment Settings', 'clientoctopus' ),
				'cta_url'   => admin_url( 'admin.php?page=clientoctopus-settings' ),
			] ),
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}

	/**
	 * Retry auto-charge on every unpaid invoice that failed at least once but
	 * hasn't yet exhausted MAX_CHARGE_RETRIES, spaced RETRY_INTERVAL_DAYS
	 * apart. Called from the daily cron (see clientoctopus.php), separately
	 * from process_due() (which only generates new cycles, never retries an
	 * existing failure).
	 *
	 * Queries invoices directly (not "the latest invoice per profile") so two
	 * independently-failing invoices from the same profile are each retried
	 * on their own schedule — see the DB version 29 migration note.
	 */
	public static function retry_failed_charges(): void {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id AS invoice_id, i.recurring_profile_id AS profile_id
				 FROM {$wpdb->prefix}clientoctopus_invoices i
				 INNER JOIN {$wpdb->prefix}clientoctopus_recurring_profiles p ON p.id = i.recurring_profile_id
				 WHERE i.status IN ('sent','overdue') AND i.deleted_at IS NULL
				 AND i.retry_count BETWEEN 1 AND %d
				 AND i.last_failure_at IS NOT NULL AND i.last_failure_at <= DATE_SUB(NOW(), INTERVAL %d DAY)
				 AND p.status = 'active' AND p.billing_mode = 'auto_charge'",
				self::MAX_CHARGE_RETRIES - 1,
				self::RETRY_INTERVAL_DAYS
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			self::attempt_auto_charge( (int) $row['invoice_id'], (int) $row['profile_id'] );
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Anchored to $start_date's day-of-month rather than chaining off the
	 * previously computed date, so short months don't compound drift —
	 * a profile started Jan 31 clamps to Feb 28, then snaps back to Mar 31
	 * once a long-enough month comes around again (matches Stripe/Chargebee
	 * billing-anchor behavior).
	 */
	private static function calculate_next_run_date( string $start_date, string $frequency, int $cycle_number ): string {
		if ( 'weekly' === $frequency ) {
			return gmdate( 'Y-m-d', strtotime( "+{$cycle_number} weeks", strtotime( $start_date ) ) );
		}

		$months_step = match ( $frequency ) {
			'quarterly' => 3,
			'yearly'    => 12,
			default     => 1, // monthly
		};

		$anchor_day = (int) gmdate( 'j', strtotime( $start_date ) );
		$target     = new DateTime( $start_date );
		$target->modify( 'first day of this month' );
		$target->modify( '+' . ( $months_step * $cycle_number ) . ' months' );
		$days_in_target_month = (int) $target->format( 't' );
		$target->setDate( (int) $target->format( 'Y' ), (int) $target->format( 'm' ), min( $anchor_day, $days_in_target_month ) );

		return $target->format( 'Y-m-d' );
	}

	private static function format_row( array $row ): array {
		$row['id']               = (int) $row['id'];
		$row['owner_id']         = (int) $row['owner_id'];
		$row['client_id']        = (int) $row['client_id'];
		$row['discount_value']   = (float) $row['discount_value'];
		$row['vat_pct']          = (float) $row['vat_pct'];
		$row['max_occurrences']  = $row['max_occurrences'] ? (int) $row['max_occurrences'] : null;
		$row['occurrences_sent'] = (int) $row['occurrences_sent'];
		$row['retry_count']      = (int) ( $row['retry_count'] ?? 0 );
		$row['line_items']       = ! empty( $row['line_items'] )
			? json_decode( $row['line_items'], true )
			: [];

		return $row;
	}
}
