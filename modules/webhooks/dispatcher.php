<?php
/**
 * Outbound Webhook Dispatcher
 *
 * Fires signed HTTP POST requests to owner-configured webhook URLs
 * when key Client Octopus events occur.
 *
 * Payload shape:
 *   { "event": "proposal.accepted", "timestamp": "ISO8601", "data": { ... } }
 *
 * Signature header:
 *   X-ClientOctopus-Signature: sha256=<hmac-sha256(payload, secret)>
 *
 * @package ClientOctopus\Webhooks
 * @since   0.1.2
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatch an event to all matching, enabled webhooks for an owner.
 *
 * @param string $event    Dot-notation event name, e.g. 'proposal.accepted'.
 * @param int    $owner_id Plugin owner user ID (not team-member ID).
 * @param array  $data     Serialisable payload data included under the "data" key.
 *
 * @return void
 */
function clientoctopus_webhook_dispatch( string $event, int $owner_id, array $data ): void {
	global $wpdb;

	if ( ! clientoctopus_can_user( $owner_id, 'use_webhooks' ) ) {
		return;
	}

	$webhooks = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, url, secret
			 FROM {$wpdb->prefix}clientoctopus_webhooks
			 WHERE owner_id = %d
			   AND enabled  = 1
			   AND JSON_CONTAINS(events, %s)",
			$owner_id,
			json_encode( $event )
		),
		ARRAY_A
	);

	if ( empty( $webhooks ) ) {
		return;
	}

	$payload = (string) wp_json_encode( [
		'event'     => $event,
		'timestamp' => gmdate( 'c' ),
		'data'      => $data,
	] );

	foreach ( $webhooks as $wh ) {
		$sig = 'sha256=' . hash_hmac( 'sha256', $payload, $wh['secret'] );

		$response = wp_remote_post( $wh['url'], [
			'body'    => $payload,
			'headers' => [
				'Content-Type'           => 'application/json',
				'X-ClientOctopus-Event'     => $event,
				'X-ClientOctopus-Signature' => $sig,
			],
			'timeout' => 5,
		] );

		$code    = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$success = ( $code >= 200 && $code < 300 ) ? 1 : 0;

		$wpdb->insert(
			$wpdb->prefix . 'clientoctopus_webhook_logs',
			[
				'webhook_id'    => (int) $wh['id'],
				'event'         => $event,
				'response_code' => $code,
				'success'       => $success,
				'delivered_at'  => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%d', '%d', '%s' ]
		);
	}
}
