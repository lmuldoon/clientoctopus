<?php
/**
 * Client GDPR Export/Erase Support
 *
 * Registers a WP core Tools > Export/Erase Personal Data exporter and eraser
 * for the clientoctopus_clients table, keyed by email address across all
 * owners (a requester's email is the sole key WP core's privacy tools use) —
 * same shape as modules/leads/privacy.php, adapted for the extra fields
 * clients carry (portal login credentials, saved payment method references).
 *
 * Erasure anonymizes PII in place rather than deleting the row — six other
 * tables (proposals, projects, payments, invoices, recurring_profiles,
 * portal_sessions) reference a client by id, and deleting the row would
 * orphan real financial/audit records. Saved payment instruments (Stripe
 * payment_method_id, PayPal vault_id) have their local DB reference cleared
 * but are not revoked at the processor as part of this pass.
 *
 * @package ClientOctopus
 * @since   1.3.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table queries; table name built from $wpdb->prefix with a hardcoded slug, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_privacy_personal_data_exporters', 'clientoctopus_register_client_data_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'clientoctopus_register_client_data_eraser' );

/**
 * @param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters
 * @return array<string, array{exporter_friendly_name: string, callback: callable}>
 */
function clientoctopus_register_client_data_exporter( array $exporters ): array {
	$exporters['clientoctopus-clients'] = [
		'exporter_friendly_name' => __( 'Client Octopus — Client Records', 'clientoctopus' ),
		'callback'               => 'clientoctopus_client_data_exporter',
	];
	return $exporters;
}

/**
 * @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers
 * @return array<string, array{eraser_friendly_name: string, callback: callable}>
 */
function clientoctopus_register_client_data_eraser( array $erasers ): array {
	$erasers['clientoctopus-clients'] = [
		'eraser_friendly_name' => __( 'Client Octopus — Client Records', 'clientoctopus' ),
		'callback'              => 'clientoctopus_client_data_eraser',
	];
	return $erasers;
}

/**
 * @param string $email_address
 * @param int    $page
 * @return array{data: array, done: bool}
 */
function clientoctopus_client_data_exporter( string $email_address, int $page = 1 ): array {
	global $wpdb;

	$per_page = 500;
	$offset   = ( max( 1, $page ) - 1 ) * $per_page;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}clientoctopus_clients WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
			$email_address,
			$per_page,
			$offset
		),
		ARRAY_A
	);

	$export_items = [];
	foreach ( $rows as $row ) {
		$fields = [];
		$labels = [
			'name'               => __( 'Name', 'clientoctopus' ),
			'email'              => __( 'Email', 'clientoctopus' ),
			'company'            => __( 'Company', 'clientoctopus' ),
			'phone'              => __( 'Phone', 'clientoctopus' ),
			'paypal_payer_email' => __( 'PayPal Email', 'clientoctopus' ),
			'created_at'         => __( 'Client Since', 'clientoctopus' ),
		];
		foreach ( $labels as $key => $label ) {
			if ( '' === (string) ( $row[ $key ] ?? '' ) ) {
				continue;
			}
			$fields[] = [
				'name'  => $label,
				'value' => $row[ $key ],
			];
		}

		$export_items[] = [
			'group_id'    => 'clientoctopus-clients',
			'group_label' => __( 'Client Records', 'clientoctopus' ),
			'item_id'     => 'clientoctopus-client-' . $row['id'],
			'data'        => $fields,
		];
	}

	return [
		'data' => $export_items,
		'done' => count( $rows ) < $per_page,
	];
}

/**
 * @param string $email_address
 * @param int    $page
 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
 */
function clientoctopus_client_data_eraser( string $email_address, int $page = 1 ): array {
	global $wpdb;

	// No OFFSET: each match is anonymized (email set to null) as it's
	// processed, which removes it from future WHERE matches — see
	// clientoctopus_lead_data_eraser() for the identical reasoning.
	$per_page = 500;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}clientoctopus_clients WHERE email = %s ORDER BY id ASC LIMIT %d",
			$email_address,
			$per_page
		)
	);

	$items_removed = false;
	foreach ( $ids as $id ) {
		$id = (int) $id;

		$updated = $wpdb->update(
			$wpdb->prefix . 'clientoctopus_clients',
			[
				'name'                     => __( 'Removed for privacy', 'clientoctopus' ),
				'email'                    => null,
				'company'                  => null,
				'phone'                    => null,
				'paypal_payer_email'       => null,
				'portal_token_hash'        => null,
				'portal_password_hash'     => null,
				'portal_token_expires_at'  => null,
				'stripe_customer_id'       => null,
				'stripe_payment_method_id' => null,
				'stripe_pm_brand'          => null,
				'stripe_pm_last4'          => null,
				'paypal_vault_id'          => null,
				'paypal_vault_customer_id' => null,
				'updated_at'               => current_time( 'mysql' ),
			],
			[ 'id' => $id ]
		);

		// Kill any live portal session immediately, not just future logins —
		// nulling portal_token_hash/portal_password_hash above only stops
		// new logins; an already-issued session token would otherwise keep
		// working until it naturally expires.
		$wpdb->delete( $wpdb->prefix . 'clientoctopus_portal_sessions', [ 'client_id' => $id ] );

		if ( $updated ) {
			$items_removed = true;
		}
	}

	return [
		'items_removed'  => $items_removed,
		'items_retained' => false,
		'messages'       => [],
		'done'           => count( $ids ) < $per_page,
	];
}
