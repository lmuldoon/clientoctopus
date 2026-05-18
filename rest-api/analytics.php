<?php
/**
 * Analytics REST endpoint
 *
 * GET /wp-json/clientoctopus/v1/analytics/overview
 *   — Requires admin auth (clientoctopus_rest_require_auth)
 *   — Pro/Agency only (free users receive 403 upgrade_required)
 *   — Accepts: range (week|month|year|custom), from, to, export (csv)
 *   — Returns: { kpis, chart, performance, feed }
 *
 * @package ClientOctopus\Analytics
 * @since   0.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once CLIENTOCTOPUS_DIR . 'modules/analytics/class-analytics.php';

add_action( 'rest_api_init', static function (): void {

	register_rest_route(
		'clientoctopus/v1',
		'/analytics/overview',
		[
			'methods'             => 'GET',
			'callback'            => 'clientoctopus_rest_analytics_overview',
			'permission_callback' => 'clientoctopus_rest_require_auth',
			'args'                => [
				'range'  => [
					'required'          => false,
					'type'              => 'string',
					'default'           => 'month',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => static fn( $v ) => in_array( $v, [ 'week', 'month', 'year', 'custom' ], true ),
				],
				'from'   => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'to'     => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'export' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		]
	);
} );

function clientoctopus_rest_analytics_overview( WP_REST_Request $request ): WP_REST_Response {
	$user_id = get_current_user_id();

	// Plan gate — free users get an upgrade prompt.
	$plan = ClientOctopus_Entitlements::get_user_plan( $user_id );
	if ( 'free' === $plan ) {
		return new WP_REST_Response(
			[
				'code'    => 'upgrade_required',
				'message' => __( 'Analytics requires a Pro or Agency plan.', 'clientoctopus' ),
			],
			403
		);
	}

	// Resolve date range.
	[ $from, $to ] = clientoctopus_analytics_resolve_range(
		$request->get_param( 'range' ),
		$request->get_param( 'from' ),
		$request->get_param( 'to' )
	);

	if ( is_wp_error( $from ) ) {
		return new WP_REST_Response( [ 'message' => $from->get_error_message() ], 400 );
	}

	// CSV export bypasses JSON response.
	if ( 'csv' === $request->get_param( 'export' ) ) {
		ClientOctopus_Analytics::stream_csv( $user_id, $from, $to );
	}

	// Determine chart granularity from range width.
	$granularity = clientoctopus_analytics_granularity( $request->get_param( 'range' ) );

	return new WP_REST_Response(
		[
			'range'       => [ 'from' => $from, 'to' => $to ],
			'kpis'        => ClientOctopus_Analytics::kpis( $user_id, $from, $to ),
			'chart'       => ClientOctopus_Analytics::revenue_chart( $user_id, $from, $to, $granularity ),
			'performance' => ClientOctopus_Analytics::proposal_performance( $user_id, $from, $to ),
			'feed'        => ClientOctopus_Analytics::activity_feed( $user_id, 20 ),
		],
		200
	);
}

/**
 * Resolve a named range or custom dates to [from, to] MySQL DATETIME strings.
 *
 * @return array{0: string, 1: string}|array{0: WP_Error}
 */
function clientoctopus_analytics_resolve_range( string $range, ?string $from, ?string $to ): array {
	$now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

	switch ( $range ) {
		case 'week':
			return [
				( clone $now )->modify( '-6 days' )->format( 'Y-m-d 00:00:00' ),
				$now->format( 'Y-m-d 23:59:59' ),
			];

		case 'year':
			return [
				$now->format( 'Y-01-01 00:00:00' ),
				$now->format( 'Y-m-d 23:59:59' ),
			];

		case 'custom':
			if ( ! $from || ! $to ) {
				return [ new WP_Error( 'missing_dates', 'from and to are required for custom range.' ), '' ];
			}
			$from_dt = DateTime::createFromFormat( 'Y-m-d', $from );
			$to_dt   = DateTime::createFromFormat( 'Y-m-d', $to );
			if ( ! $from_dt || ! $to_dt || $from_dt > $to_dt ) {
				return [ new WP_Error( 'invalid_dates', 'Invalid date range.' ), '' ];
			}
			// Max 1-year span.
			if ( $from_dt->diff( $to_dt )->days > 366 ) {
				return [ new WP_Error( 'range_too_large', 'Custom range cannot exceed one year.' ), '' ];
			}
			return [
				$from_dt->format( 'Y-m-d 00:00:00' ),
				$to_dt->format( 'Y-m-d 23:59:59' ),
			];

		default: // month
			return [
				$now->format( 'Y-m-01 00:00:00' ),
				$now->format( 'Y-m-d 23:59:59' ),
			];
	}
}

function clientoctopus_analytics_granularity( string $range ): string {
	return match ( $range ) {
		'year'  => 'month',
		'week'  => 'day',
		default => 'day',
	};
}
