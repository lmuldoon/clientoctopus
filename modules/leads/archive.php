<?php
/**
 * Lead Auto-Archive
 *
 * Enforces clientoctopus_lead_archive_days by hooking the existing daily
 * automations cron (clientoctopus_daily_automations) rather than registering
 * a separate wp_schedule_event — this is a single lightweight UPDATE, not
 * worth its own cron slot.
 *
 * @package ClientOctopus
 * @since   1.3.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; table name built from $wpdb->prefix with a hardcoded slug, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'clientoctopus_daily_automations', 'clientoctopus_lead_run_auto_archive' );

/**
 * Archive leads that have had no status change in N days. 0 (default) disables this entirely.
 */
function clientoctopus_lead_run_auto_archive(): void {
	$days = (int) get_option( 'clientoctopus_lead_archive_days', 0 );
	if ( $days <= 0 ) {
		return;
	}

	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}clientoctopus_leads
			 SET status = 'archived', updated_at = %s
			 WHERE status IN ( 'new', 'contacted' )
			   AND updated_at <= DATE_SUB( NOW(), INTERVAL %d DAY )",
			current_time( 'mysql' ),
			$days
		)
	);
}
