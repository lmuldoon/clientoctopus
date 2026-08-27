<?php
/**
 * ClientOctopus_Recurring_Profile
 *
 * A recurring profile is a template that spawns a fresh, ordinary
 * clientoctopus_invoices row on a schedule — the client pays each generated
 * invoice manually via the existing one-off Stripe/PayPal checkout flow,
 * exactly like any other invoice. There is no auto-charge in this version;
 * the `billing_mode` column exists only so that can be added later without
 * a schema rework.
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
				'billing_mode'     => 'manual',
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
		$first_invoice_id      = self::generate_next_invoice( $profile_id );
		$profile['first_invoice'] = ( ! is_wp_error( $first_invoice_id ) && class_exists( 'ClientOctopus_Invoice' ) )
			? ClientOctopus_Invoice::get( $first_invoice_id, $owner_id )
			: null;
		if ( is_wp_error( $profile['first_invoice'] ?? null ) ) {
			$profile['first_invoice'] = null;
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
				'updated_at'      => current_time( 'mysql' ),
			],
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		return self::get( $id, $owner_id );
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
				"SELECT p.*, c.name AS _client_name
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

	private const LIST_STATUSES = [ 'active', 'paused', 'cancelled' ];

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
				"SELECT p.*, c.name AS _client_name
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
		return self::set_status( $id, $owner_id, 'active', 'paused' );
	}

	public static function resume( int $id, int $owner_id ): true|WP_Error {
		return self::set_status( $id, $owner_id, 'paused', 'active' );
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

	private static function set_status( int $id, int $owner_id, string $from, string $to ): true|WP_Error {
		global $wpdb;

		$current = self::get( $id, $owner_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( $from !== $current['status'] ) {
			return new WP_Error(
				'recurring_profile_invalid_transition',
				/* translators: 1: current status, 2: required status */
				sprintf( __( 'This profile is %1$s; only a %2$s profile can be changed this way.', 'clientoctopus' ), $current['status'], $from ),
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
		$row['line_items']       = ! empty( $row['line_items'] )
			? json_decode( $row['line_items'], true )
			: [];

		return $row;
	}
}
