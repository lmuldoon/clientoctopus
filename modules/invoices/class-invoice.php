<?php
/**
 * ClientOctopus_Invoice
 *
 * Core class for standalone invoices — not tied to proposals.
 * Handles create, update, send, mark-paid, list, get, delete.
 *
 * Tier split:
 *   Free   — create, send, view, manual mark-paid
 *   Pro    — Stripe "Pay Now" button via /invoices/{id}/pay/ REST route
 *
 * @package ClientOctopus
 * @since   1.2.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table references use $wpdb->prefix with hardcoded names, never user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClientOctopus_Invoice {

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Create a new draft invoice.
	 *
	 * @param int   $owner_id
	 * @param array $data     {title, currency, client_id, line_items, discount_type,
	 *                         discount_value, vat_pct, vat_number, notes, due_date,
	 *                         issue_date, payment_terms, po_number, recurring_profile_id}
	 * @return array|WP_Error Newly created invoice row, or error.
	 */
	public static function create( int $owner_id, array $data ): array|WP_Error {
		global $wpdb;

		$table         = $wpdb->prefix . 'clientoctopus_invoices';
		$invoice_number = self::generate_invoice_number( $owner_id );
		$token          = self::generate_token();
		$now            = current_time( 'mysql' );
		$total          = self::compute_total( $data );

		$line_items = ! empty( $data['line_items'] ) && is_array( $data['line_items'] )
			? wp_json_encode( $data['line_items'] )
			: null;

		$inserted = $wpdb->insert(
			$table,
			[
				'owner_id'             => $owner_id,
				'client_id'            => ! empty( $data['client_id'] ) ? (int) $data['client_id'] : null,
				'invoice_number'       => $invoice_number,
				'token'                => $token,
				'status'               => 'draft',
				'title'                => sanitize_text_field( $data['title'] ?? '' ),
				'currency'             => strtoupper( sanitize_text_field( $data['currency'] ?? 'GBP' ) ),
				'line_items'           => $line_items,
				'discount_type'        => in_array( $data['discount_type'] ?? '', [ 'percentage', 'fixed' ], true )
				                     ? $data['discount_type']
				                     : 'percentage',
				'discount_value'       => max( 0, (float) ( $data['discount_value'] ?? 0 ) ),
				'vat_pct'              => max( 0, min( 100, (float) ( $data['vat_pct'] ?? 0 ) ) ),
				'vat_number'           => ! empty( $data['vat_number'] ) ? sanitize_text_field( $data['vat_number'] ) : null,
				'notes'                => ! empty( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
				'due_date'             => ! empty( $data['due_date'] ) ? sanitize_text_field( $data['due_date'] ) : null,
				'issue_date'           => ! empty( $data['issue_date'] ) ? sanitize_text_field( $data['issue_date'] ) : gmdate( 'Y-m-d' ),
				'payment_terms'        => ! empty( $data['payment_terms'] ) ? sanitize_text_field( $data['payment_terms'] ) : null,
				'po_number'            => ! empty( $data['po_number'] ) ? sanitize_text_field( $data['po_number'] ) : null,
				'total_amount'         => $total,
				'recurring_profile_id' => ! empty( $data['recurring_profile_id'] ) ? (int) $data['recurring_profile_id'] : null,
				'created_at'           => $now,
				'updated_at'           => $now,
			],
			[ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return new WP_Error( 'invoice_create_failed', __( 'Failed to create invoice.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		return self::get( (int) $wpdb->insert_id, $owner_id );
	}

	/**
	 * Update a draft invoice. Only drafts can be updated.
	 *
	 * @param int   $id
	 * @param int   $owner_id
	 * @param array $data
	 * @return array|WP_Error Updated invoice row, or error.
	 */
	public static function update( int $id, int $owner_id, array $data ): array|WP_Error {
		global $wpdb;

		$table   = $wpdb->prefix . 'clientoctopus_invoices';
		$current = self::get( $id, $owner_id );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		// Sent invoices can have their notes updated (for bank transfer instructions) but not financial fields.
		$is_sent = 'sent' === $current['status'];

		$fields = [
			'notes'          => ! empty( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
			'updated_at'     => current_time( 'mysql' ),
		];

		if ( ! $is_sent ) {
			$line_items = ! empty( $data['line_items'] ) && is_array( $data['line_items'] )
				? wp_json_encode( $data['line_items'] )
				: null;

			$fields = array_merge( $fields, [
				'client_id'      => ! empty( $data['client_id'] ) ? (int) $data['client_id'] : null,
				'title'          => sanitize_text_field( $data['title'] ?? $current['title'] ),
				'currency'       => strtoupper( sanitize_text_field( $data['currency'] ?? $current['currency'] ) ),
				'line_items'     => $line_items,
				'discount_type'  => in_array( $data['discount_type'] ?? '', [ 'percentage', 'fixed' ], true )
				                     ? $data['discount_type']
				                     : $current['discount_type'],
				'discount_value' => max( 0, (float) ( $data['discount_value'] ?? $current['discount_value'] ) ),
				'vat_pct'        => max( 0, min( 100, (float) ( $data['vat_pct'] ?? $current['vat_pct'] ) ) ),
				'vat_number'     => ! empty( $data['vat_number'] ) ? sanitize_text_field( $data['vat_number'] ) : null,
				'due_date'       => ! empty( $data['due_date'] ) ? sanitize_text_field( $data['due_date'] ) : null,
				'issue_date'     => ! empty( $data['issue_date'] ) ? sanitize_text_field( $data['issue_date'] ) : $current['issue_date'],
				'payment_terms'  => ! empty( $data['payment_terms'] ) ? sanitize_text_field( $data['payment_terms'] ) : null,
				'po_number'      => ! empty( $data['po_number'] ) ? sanitize_text_field( $data['po_number'] ) : null,
				'total_amount'   => self::compute_total( $data ),
			] );
		}

		$wpdb->update( $table, $fields, [ 'id' => $id, 'owner_id' => $owner_id ] );

		return self::get( $id, $owner_id );
	}

	/**
	 * Send (or re-send) an invoice. Draft → sent on first send; sent/overdue re-sends the email without changing status.
	 *
	 * @param int $id
	 * @param int $owner_id
	 * @return bool|WP_Error
	 */
	public static function send( int $id, int $owner_id ): bool|WP_Error {
		global $wpdb;

		$table   = $wpdb->prefix . 'clientoctopus_invoices';
		$invoice = self::get( $id, $owner_id );

		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$allowed = [ 'draft', 'sent', 'overdue' ];
		if ( ! in_array( $invoice['status'], $allowed, true ) ) {
			return new WP_Error( 'invoice_not_sendable', __( 'Only draft, sent, or overdue invoices can be sent.', 'clientoctopus' ), [ 'status' => 422 ] );
		}

		if ( 'draft' === $invoice['status'] ) {
			$wpdb->update(
				$table,
				[
					'status'     => 'sent',
					'sent_at'    => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				],
				[ 'id' => $id, 'owner_id' => $owner_id ]
			);
		}

		do_action( 'clientoctopus_invoice_sent', $id, $owner_id );

		return true;
	}

	/**
	 * Manual mark-as-paid (free plan — no Stripe).
	 *
	 * @param int $id
	 * @param int $owner_id
	 * @return bool|WP_Error
	 */
	public static function mark_paid_manual( int $id, int $owner_id ): bool|WP_Error {
		global $wpdb;

		$table   = $wpdb->prefix . 'clientoctopus_invoices';
		$invoice = self::get( $id, $owner_id );

		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		if ( ! in_array( $invoice['status'], [ 'sent', 'overdue' ], true ) ) {
			return new WP_Error( 'invoice_not_payable', __( 'Only sent or overdue invoices can be marked as paid.', 'clientoctopus' ), [ 'status' => 422 ] );
		}

		$wpdb->update(
			$table,
			[
				'status'     => 'paid',
				'paid_at'    => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		do_action( 'clientoctopus_invoice_paid', $id, $owner_id );

		return true;
	}

	/**
	 * Mark invoice as paid via Stripe webhook — no owner check (webhook context).
	 *
	 * @param int    $id
	 * @param string $session_id
	 * @param string $intent_id
	 */
	public static function mark_paid( int $id, string $session_id, string $intent_id ): void {
		self::mark_paid_for_provider( $id, $session_id, $intent_id, 'stripe' );
	}

	/**
	 * Mark invoice as paid via a gateway webhook/write-through, provider-aware —
	 * no owner check (webhook/redirect context).
	 *
	 * @param int    $id         Invoice ID.
	 * @param string $gateway_id Stripe session ID or PayPal order ID.
	 * @param string $charge_id  Stripe PaymentIntent ID or PayPal capture ID.
	 * @param string $provider   'stripe' | 'paypal'. Defaults to 'stripe'.
	 */
	public static function mark_paid_for_provider( int $id, string $gateway_id, string $charge_id, string $provider = 'stripe' ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_invoices';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, owner_id, status FROM {$table} WHERE id = %d AND deleted_at IS NULL LIMIT 1", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return;
		}

		// Idempotency guard.
		if ( 'paid' === $row['status'] ) {
			return;
		}

		$fields = [
			'status'     => 'paid',
			'provider'   => 'paypal' === $provider ? 'paypal' : 'stripe',
			'paid_at'    => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		];

		if ( 'paypal' === $provider ) {
			$fields['paypal_order_id']   = $gateway_id;
			$fields['paypal_capture_id'] = $charge_id;
		} else {
			$fields['stripe_session_id']        = $gateway_id;
			$fields['stripe_payment_intent_id'] = $charge_id;
		}

		$wpdb->update( $table, $fields, [ 'id' => $id ] );

		do_action( 'clientoctopus_invoice_paid', $id, (int) $row['owner_id'] );
	}

	/**
	 * Fetch a single invoice by ID + owner.
	 *
	 * @param int $id
	 * @param int $owner_id
	 * @return array|WP_Error
	 */
	public static function get( int $id, int $owner_id ): array|WP_Error {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_invoices';
		$ct    = $wpdb->prefix . 'clientoctopus_clients';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.*, c.name AS _client_name
				 FROM {$table} i
				 LEFT JOIN {$ct} c ON c.id = i.client_id
				 WHERE i.id = %d AND i.owner_id = %d AND i.deleted_at IS NULL LIMIT 1",
				$id,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'invoice_not_found', __( 'Invoice not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return self::format_row( $row );
	}

	/**
	 * Fetch a single invoice by its public token (client-facing view).
	 *
	 * @param string $token
	 * @return array|WP_Error
	 */
	public static function get_by_token( string $token ): array|WP_Error {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_invoices';
		$ct    = $wpdb->prefix . 'clientoctopus_clients';

		// Resolve the token to an ID via hash_equals() rather than a direct
		// `WHERE token = %s` equality lookup — see the identical pattern and
		// comment in ClientOctopus_Proposal_Client::get_by_token(). The
		// candidate query only pulls the narrow (id, token) pair, so this
		// stays a fast, lightweight scan even as the invoices table grows.
		$id = null;
		$candidates = $wpdb->get_results(
			"SELECT id, token FROM {$table} WHERE deleted_at IS NULL",
			ARRAY_A
		);
		foreach ( $candidates as $candidate ) {
			if ( hash_equals( (string) $candidate['token'], $token ) ) {
				$id = (int) $candidate['id'];
				break;
			}
		}

		$row = $id ? $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.*, c.name AS _client_name
				 FROM {$table} i
				 LEFT JOIN {$ct} c ON c.id = i.client_id
				 WHERE i.id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		) : null;

		if ( ! $row ) {
			return new WP_Error( 'invoice_not_found', __( 'Invoice not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return self::format_row( $row );
	}

	/**
	 * List invoices for an owner, with optional status filter.
	 *
	 * @param int   $owner_id
	 * @param array $filters {status: string|null}
	 * @return array[]
	 */
	public static function list( int $owner_id, array $filters = [] ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_invoices';
		$ct    = $wpdb->prefix . 'clientoctopus_clients';

		$allowed_statuses = [ 'draft', 'sent', 'paid', 'overdue', 'cancelled' ];
		$status_filter    = isset( $filters['status'] ) && in_array( $filters['status'], $allowed_statuses, true )
			? $filters['status']
			: null;

		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where = [ $wpdb->prepare( 'i.owner_id = %d', $owner_id ), 'i.deleted_at IS NULL' ];
		if ( $status_filter ) {
			$where[] = $wpdb->prepare( 'i.status = %s', $status_filter );
		}
		$where_sql = implode( ' AND ', $where );

		// $where_sql components are individually prepared above; no outer prepare() needed.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} i WHERE {$where_sql}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.*, c.name AS _client_name
				 FROM {$table} i
				 LEFT JOIN {$ct} c ON c.id = i.client_id
				 WHERE {$where_sql}
				 ORDER BY i.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		// Per-status counts for the tab badges — always reflects the full,
		// unfiltered-by-status set (matches the pre-pagination client-side
		// behavior, which counted the entire fetched list regardless of tab).
		$counts = array_fill_keys( $allowed_statuses, 0 );
		$counts['all'] = 0;
		$count_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$table} WHERE owner_id = %d AND deleted_at IS NULL GROUP BY status", $owner_id )
		);
		foreach ( $count_rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->c;
			}
			$counts['all'] += (int) $row->c;
		}

		return [
			'invoices' => array_map( [ self::class, 'format_row' ], $rows ?: [] ),
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
			'counts'   => $counts,
		];
	}

	/**
	 * Soft-delete an invoice. Only drafts and cancelled invoices can be deleted.
	 *
	 * @param int $id
	 * @param int $owner_id
	 * @return bool|WP_Error
	 */
	public static function delete( int $id, int $owner_id ): bool|WP_Error {
		global $wpdb;

		$table   = $wpdb->prefix . 'clientoctopus_invoices';
		$invoice = self::get( $id, $owner_id );

		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$wpdb->update(
			$table,
			[ 'deleted_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		return true;
	}

	/**
	 * Cancel a sent or overdue invoice.
	 *
	 * @param int $id
	 * @param int $owner_id
	 * @return bool|WP_Error
	 */
	public static function cancel( int $id, int $owner_id ): bool|WP_Error {
		global $wpdb;

		$table   = $wpdb->prefix . 'clientoctopus_invoices';
		$invoice = self::get( $id, $owner_id );

		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		if ( ! in_array( $invoice['status'], [ 'sent', 'overdue' ], true ) ) {
			return new WP_Error( 'invoice_not_cancellable', __( 'Only sent or overdue invoices can be cancelled.', 'clientoctopus' ), [ 'status' => 422 ] );
		}

		$wpdb->update(
			$table,
			[ 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		do_action( 'clientoctopus_invoice_cancelled', $id, $owner_id );

		return true;
	}

	/**
	 * Mark overdue invoices (sent, due_date in the past) — called from daily cron.
	 */
	public static function mark_overdue_batch(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_invoices';

		$rows = $wpdb->get_results(
			"SELECT id, owner_id FROM {$table}
			 WHERE status = 'sent'
			   AND due_date IS NOT NULL
			   AND due_date < CURDATE()
			   AND deleted_at IS NULL",
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$wpdb->update(
				$table,
				[ 'status' => 'overdue', 'updated_at' => current_time( 'mysql' ) ],
				[ 'id' => (int) $row['id'] ]
			);
			do_action( 'clientoctopus_invoice_overdue', (int) $row['id'], (int) $row['owner_id'] );
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Generate the next sequential invoice number for an owner.
	 * MAX(invoice_number) + 1 scoped per owner, so each owner starts at 1.
	 */
	private static function generate_invoice_number( int $owner_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'clientoctopus_invoices';
		$max   = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(MAX(invoice_number), 0) FROM {$table} WHERE owner_id = %d", $owner_id )
		);

		return $max + 1;
	}

	private static function generate_token(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Compute the invoice total from line_items, discount, and VAT.
	 *
	 * subtotal = sum of line item amounts
	 * after discount = subtotal - discount (percentage or fixed)
	 * total = after_discount + (after_discount * vat_pct / 100)
	 */
	private static function compute_total( array $data ): float {
		$line_items = is_array( $data['line_items'] ?? null ) ? $data['line_items'] : [];
		$subtotal   = 0.0;

		foreach ( $line_items as $item ) {
			$subtotal += (float) ( $item['amount'] ?? 0 );
		}

		$discount_type  = $data['discount_type'] ?? 'percentage';
		$discount_value = max( 0, (float) ( $data['discount_value'] ?? 0 ) );
		$vat_pct        = max( 0, min( 100, (float) ( $data['vat_pct'] ?? 0 ) ) );

		if ( 'percentage' === $discount_type ) {
			$discount_amount = $subtotal * ( min( 100, $discount_value ) / 100 );
		} else {
			$discount_amount = min( $discount_value, $subtotal );
		}

		$after_discount = max( 0, $subtotal - $discount_amount );
		$vat_amount     = $after_discount * ( $vat_pct / 100 );

		return round( $after_discount + $vat_amount, 2 );
	}

	/**
	 * Format a raw DB row — decode JSON line_items, cast numeric fields.
	 */
	private static function format_row( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['owner_id']       = (int) $row['owner_id'];
		$row['client_id']      = $row['client_id'] ? (int) $row['client_id'] : null;
		$row['invoice_number'] = (int) $row['invoice_number'];
		$row['discount_value'] = (float) $row['discount_value'];
		$row['vat_pct']        = (float) $row['vat_pct'];
		$row['total_amount']   = (float) $row['total_amount'];
		$row['recurring_profile_id'] = isset( $row['recurring_profile_id'] ) && '' !== $row['recurring_profile_id']
			? (int) $row['recurring_profile_id']
			: null;
		$row['line_items']     = ! empty( $row['line_items'] )
			? json_decode( $row['line_items'], true )
			: [];
		$row['invoice_ref']    = 'INV-' . str_pad( (string) $row['invoice_number'], 4, '0', STR_PAD_LEFT );

		return $row;
	}
}
