<?php
/**
 * Payment Model
 *
 * CRUD for the clientoctopus_payments table.
 * Each row tracks one Stripe Checkout Session from pending → completed.
 *
 * @package ClientOctopus\Payments
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Payment
 */
class ClientOctopus_Payment {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; table() returns a trusted constant, not user input.

	private const TABLE = 'clientoctopus_payments';

	/** Valid payment status values. */
	public const STATUSES = [ 'pending', 'processing', 'completed', 'failed', 'refunded' ];

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	// ── Create ────────────────────────────────────────────────────────────────

	/**
	 * Create a pending payment record.
	 *
	 * Called immediately after a Stripe Checkout Session is created, before
	 * the client has paid. The record is updated to 'completed' via webhook.
	 *
	 * @param int    $proposal_id
	 * @param int    $owner_id
	 * @param array  $data {
	 *     @type float  $amount      Charge amount in the proposal currency.
	 *     @type string $currency    ISO 4217 currency code.
	 *     @type int    $deposit_pct Percentage of total being charged (1-100).
	 *     @type string $session_id  Gateway checkout/order ID (Stripe cs_xxx or PayPal order id).
	 *     @type string $provider    'stripe' | 'paypal'. Defaults to 'stripe'.
	 *     @type int    $client_id   Optional FK to clientoctopus_clients.
	 * }
	 *
	 * @return int|WP_Error New payment ID or error.
	 */
	public static function create( int $proposal_id, int $owner_id, array $data ): int|WP_Error {
		global $wpdb;

		$now      = current_time( 'mysql' );
		$provider = 'paypal' === ( $data['provider'] ?? 'stripe' ) ? 'paypal' : 'stripe';

		$row = [
			'proposal_id'      => $proposal_id,
			'owner_id'         => $owner_id,
			'client_id'        => $data['client_id'] ?? null,
			'amount'           => (float) ( $data['amount'] ?? 0 ),
			'currency'         => strtoupper( (string) ( $data['currency'] ?? 'GBP' ) ),
			'deposit_pct'      => max( 1, min( 100, (int) ( $data['deposit_pct'] ?? 100 ) ) ),
			'provider'         => $provider,
			'status'           => 'pending',
			'created_at'       => $now,
			'updated_at'       => $now,
		];

		if ( 'paypal' === $provider ) {
			$row['paypal_order_id'] = $data['session_id'] ?? null;
		} else {
			$row['stripe_session_id'] = $data['session_id'] ?? null;
		}

		$inserted = $wpdb->insert( self::table(), $row );

		if ( false === $inserted ) {
			return new WP_Error(
				'db_insert_failed',
				__( 'Failed to create payment record.', 'clientoctopus' ),
				[ 'status' => 500 ]
			);
		}

		return (int) $wpdb->insert_id;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Look up a payment by its Stripe Checkout Session ID.
	 *
	 * @param string $session_id Stripe session ID (cs_xxx).
	 *
	 * @return array|WP_Error
	 */
	public static function get_by_session_id( string $session_id ): array|WP_Error {
		return self::get_by_gateway_id( $session_id, 'stripe' );
	}

	/**
	 * Look up a payment by its gateway checkout/order ID, provider-aware.
	 *
	 * @param string $gateway_id Stripe session ID (cs_xxx) or PayPal order ID.
	 * @param string $provider   'stripe' | 'paypal'. Defaults to 'stripe'.
	 *
	 * @return array|WP_Error
	 */
	public static function get_by_gateway_id( string $gateway_id, string $provider = 'stripe' ): array|WP_Error {
		global $wpdb;

		$column = 'paypal' === $provider ? 'paypal_order_id' : 'stripe_session_id';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE {$column} = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is one of two hardcoded constants above, not user input.
				$gateway_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error(
				'payment_not_found',
				__( 'Payment not found.', 'clientoctopus' ),
				[ 'status' => 404 ]
			);
		}

		return self::prepare_row( $row );
	}

	/**
	 * Get all payments for a proposal.
	 *
	 * @param int $proposal_id
	 *
	 * @return array[]
	 */
	public static function get_for_proposal( int $proposal_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE proposal_id = %d ORDER BY created_at DESC",
				$proposal_id
			),
			ARRAY_A
		);

		return array_map( [ __CLASS__, 'prepare_row' ], $rows ?: [] );
	}

	/**
	 * Check whether a completed payment exists for a proposal.
	 *
	 * @param int $proposal_id
	 *
	 * @return bool
	 */
	public static function has_completed_payment( int $proposal_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table() . " WHERE proposal_id = %d AND status = 'completed'",
				$proposal_id
			)
		);

		return $count > 0;
	}

	// ── Update ────────────────────────────────────────────────────────────────

	/**
	 * Mark a payment as completed after a successful Stripe webhook.
	 *
	 * @param string      $session_id        Stripe session ID.
	 * @param string      $payment_intent_id Stripe PaymentIntent ID.
	 * @param string|null $customer_id       Stripe Customer ID (if present).
	 *
	 * @return bool|WP_Error
	 */
	public static function mark_complete(
		string $session_id,
		string $payment_intent_id,
		?string $customer_id = null
	): bool|WP_Error {
		return self::mark_complete_for_provider( $session_id, $payment_intent_id, $customer_id, 'stripe' );
	}

	/**
	 * Mark a payment as completed after a successful gateway confirmation, provider-aware.
	 *
	 * @param string      $gateway_id   Stripe session ID or PayPal order ID.
	 * @param string      $charge_id    Stripe PaymentIntent ID or PayPal capture ID.
	 * @param string|null $customer_id  Stripe Customer ID (if present). Unused for PayPal.
	 * @param string      $provider     'stripe' | 'paypal'. Defaults to 'stripe'.
	 *
	 * @return bool|WP_Error
	 */
	public static function mark_complete_for_provider(
		string $gateway_id,
		string $charge_id,
		?string $customer_id = null,
		string $provider = 'stripe'
	): bool|WP_Error {
		global $wpdb;

		$now    = current_time( 'mysql' );
		$column = 'paypal' === $provider ? 'paypal_order_id' : 'stripe_session_id';

		$fields = [
			'status'       => 'completed',
			'completed_at' => $now,
			'updated_at'   => $now,
		];

		if ( 'paypal' === $provider ) {
			$fields['paypal_capture_id'] = $charge_id;
		} else {
			$fields['stripe_payment_intent_id'] = $charge_id;
			$fields['stripe_customer_id']       = $customer_id;
		}

		$result = $wpdb->update( self::table(), $fields, [ $column => $gateway_id ] );

		if ( false === $result ) {
			return new WP_Error(
				'db_update_failed',
				__( 'Failed to update payment record.', 'clientoctopus' ),
				[ 'status' => 500 ]
			);
		}

		// Fire hook so analytics cache is invalidated.
		$payment = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, owner_id FROM " . self::table() . " WHERE {$column} = %s", $gateway_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is one of two hardcoded constants above, not user input.
		);
		if ( $payment ) {
			do_action( 'clientoctopus_payment_completed', (int) $payment->id, (int) $payment->owner_id );
		}

		return true;
	}

	/**
	 * Mark a payment as failed.
	 *
	 * @param string $session_id
	 * @param string $provider   'stripe' | 'paypal'. Defaults to 'stripe'.
	 *
	 * @return bool|WP_Error
	 */
	public static function mark_failed( string $session_id, string $provider = 'stripe' ): bool|WP_Error {
		global $wpdb;

		$column = 'paypal' === $provider ? 'paypal_order_id' : 'stripe_session_id';

		$result = $wpdb->update(
			self::table(),
			[ 'status' => 'failed', 'updated_at' => current_time( 'mysql' ) ],
			[ $column => $session_id ]
		);

		if ( false === $result ) {
			return new WP_Error( 'db_update_failed', __( 'Failed to update payment.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		return true;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Cast raw DB row to correct types.
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	public static function prepare_row( array $row ): array {
		$row['id']          = (int)   $row['id'];
		$row['proposal_id'] = (int)   $row['proposal_id'];
		$row['owner_id']    = (int)   $row['owner_id'];
		$row['client_id']   = $row['client_id'] ? (int) $row['client_id'] : null;
		$row['amount']      = (float) $row['amount'];
		$row['deposit_pct'] = (int)   $row['deposit_pct'];

		return $row;
	}
}
