<?php
/**
 * Lead GDPR Export/Erase Support
 *
 * Registers a WP core Tools > Export/Erase Personal Data exporter and eraser
 * for the clientoctopus_leads table, keyed by email address across all
 * owners (a requester's email is the sole key WP core's privacy tools use).
 *
 * Erasure anonymizes PII fields in place rather than deleting the row —
 * keeps the "never auto-delete" retention invariant (see
 * clientoctopus_lead_archive_days) intact while still satisfying a genuine
 * legal erasure request, which operates on a different axis (PII content,
 * not retention status).
 *
 * @package ClientOctopus
 * @since   1.3.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table queries; table name built from $wpdb->prefix with a hardcoded slug, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_privacy_personal_data_exporters', 'clientoctopus_register_lead_data_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'clientoctopus_register_lead_data_eraser' );

/**
 * @param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters
 * @return array<string, array{exporter_friendly_name: string, callback: callable}>
 */
function clientoctopus_register_lead_data_exporter( array $exporters ): array {
	$exporters['clientoctopus-leads'] = [
		'exporter_friendly_name' => __( 'Client Octopus — Lead Capture Submissions', 'clientoctopus' ),
		'callback'               => 'clientoctopus_lead_data_exporter',
	];
	return $exporters;
}

/**
 * @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers
 * @return array<string, array{eraser_friendly_name: string, callback: callable}>
 */
function clientoctopus_register_lead_data_eraser( array $erasers ): array {
	$erasers['clientoctopus-leads'] = [
		'eraser_friendly_name' => __( 'Client Octopus — Lead Capture Submissions', 'clientoctopus' ),
		'callback'              => 'clientoctopus_lead_data_eraser',
	];
	return $erasers;
}

/**
 * @param string $email_address
 * @param int    $page
 * @return array{data: array, done: bool}
 */
function clientoctopus_lead_data_exporter( string $email_address, int $page = 1 ): array {
	global $wpdb;

	$per_page = 500;
	$offset   = ( max( 1, $page ) - 1 ) * $per_page;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}clientoctopus_leads WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
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
			'name'              => __( 'Name', 'clientoctopus' ),
			'email'             => __( 'Email', 'clientoctopus' ),
			'phone'             => __( 'Phone', 'clientoctopus' ),
			'company'           => __( 'Company', 'clientoctopus' ),
			'message'           => __( 'Message', 'clientoctopus' ),
			'budget_range'      => __( 'Budget Range', 'clientoctopus' ),
			'preferred_contact' => __( 'Preferred Contact', 'clientoctopus' ),
			'source_url'        => __( 'Source URL', 'clientoctopus' ),
			'status'            => __( 'Status', 'clientoctopus' ),
			'created_at'        => __( 'Submitted', 'clientoctopus' ),
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
			'group_id'    => 'clientoctopus-leads',
			'group_label' => __( 'Lead Capture Submissions', 'clientoctopus' ),
			'item_id'     => 'clientoctopus-lead-' . $row['id'],
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
function clientoctopus_lead_data_eraser( string $email_address, int $page = 1 ): array {
	global $wpdb;

	// No OFFSET here: each match is anonymized (email set to null) as it's
	// processed, which removes it from future WHERE matches — using an
	// OFFSET based on $page would skip rows once earlier pages shrink the
	// matching set. Always take the next batch of still-matching rows.
	$per_page = 500;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}clientoctopus_leads WHERE email = %s ORDER BY id ASC LIMIT %d",
			$email_address,
			$per_page
		)
	);

	$items_removed = false;
	foreach ( $ids as $id ) {
		$updated = $wpdb->update(
			$wpdb->prefix . 'clientoctopus_leads',
			[
				'name'              => __( 'Removed for privacy', 'clientoctopus' ),
				'email'             => null,
				'phone'             => null,
				'company'           => null,
				'message'           => null,
				'budget_range'      => null,
				'preferred_contact' => null,
				'source_url'        => null,
				'updated_at'        => current_time( 'mysql' ),
			],
			[ 'id' => (int) $id ]
		);
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
