<?php
/**
 * Plugin Name: Client Octopus
 * Plugin URI:  https://clientoctopus.com
 * Description: All-in-one client workflow management for WordPress — proposals, invoices, payments, projects, and client portals.
 * Version:     1.3.1
 * Author:      codievolt
 * Author URI:  https://codievolt.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clientoctopus
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @fs_premium_only /modules/ai/, /modules/payments/, /modules/webhooks/, /modules/portal/, /modules/projects/, /modules/messaging/, /modules/files/, /modules/team/, /modules/analytics/, /modules/booking/, /modules/calendar-sync/, /portal/, /rest-api/ai.php, /rest-api/payments.php, /rest-api/webhooks.php, /rest-api/portal.php, /rest-api/projects.php, /rest-api/messages.php, /rest-api/files.php, /rest-api/team.php, /rest-api/approvals.php, /rest-api/analytics.php, /rest-api/booking.php, /admin/views/analytics.php, /admin/views/bookings.php, /admin/components/AnalyticsApp/, /admin/components/ProjectsApp/, /admin/components/ProjectDetail/, /admin/components/ProjectApprovals/, /admin/components/ProjectFiles/, /admin/components/ProjectMessages/, /admin/components/TeamApp/, /admin/components/WebhooksApp/, /admin/components/BookingsApp/, /admin/views/projects.php, /admin/views/team.php, /admin/views/webhooks.php, /admin/projects.jsx, /admin/analytics.jsx, /admin/team.jsx, /admin/webhooks.jsx, /admin/booking.jsx, /build/projects.js, /build/projects.asset.php, /build/team.js, /build/team.asset.php, /build/webhooks.js, /build/webhooks.asset.php, /build/analytics.js, /build/analytics.asset.php, /build/portal.js, /build/portal.asset.php, /build/booking.js, /build/booking.asset.php, /assets/js/booking-widget.js, /assets/css/booking.css, /assets/css/booking-theme.css
 *
 * @package ClientOctopus
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'clientoctopus_fs' ) ) {
	clientoctopus_fs()->set_basename( true, __FILE__ );
} else {

// ─────────────────────────────────────────────────────────────────────────────
// Freemius SDK Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'clientoctopus_fs' ) ) {
	// Create a helper function for easy SDK access.
	function clientoctopus_fs() {
		global $clientoctopus_fs;

		if ( ! isset( $clientoctopus_fs ) ) {
			// Include Freemius SDK.
			require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

			$clientoctopus_fs = fs_dynamic_init( array(
				'id'                  => '29266',
				'slug'                => 'clientoctopus',
				'type'                => 'plugin',
				'public_key'          => 'pk_7340e277f5277dff75373f4c2f12b',
				'is_premium'          => true,
				// If your plugin is a serviceware, set this option to false.
				'has_premium_version' => true,
				'has_addons'          => false,
				'has_paid_plans'      => true,
				'is_org_compliant'    => true,
				// Automatically removed in the free version. If you're not using the
				// auto-generated free version, delete this line before uploading to wp.org.
				'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
				'menu'                => array(
					'slug'       => 'clientoctopus-settings',
					'first-path' => 'admin.php?page=clientoctopus-setup',
					'support'    => false,
					'account'    => true,
					'parent'     => array(
						'slug' => 'clientoctopus',
					),
				),
			) );
		}

		return $clientoctopus_fs;
	}

	// Init Freemius.
	clientoctopus_fs();
	// Signal that SDK was initiated.
	do_action( 'clientoctopus_fs_loaded' );

	// ── Freemius licence key sync ────────────────────────────────────────────
	clientoctopus_fs()->add_action( 'after_license_activation', static function (): void {
		$license  = clientoctopus_fs()->_get_license();
		$owner_id = (int) get_option( 'clientoctopus_owner_user_id', 0 );
		if ( $license && ! empty( $license->secret_key ) ) {
			update_option( 'clientoctopus_license_key', $license->secret_key );
		}
		$plan = strtolower( (string) clientoctopus_fs()->get_plan_name() );
		if ( $owner_id && in_array( $plan, [ 'pro', 'agency' ], true ) ) {
			ClientOctopus_Entitlements::set_user_plan( $owner_id, $plan );
		}
		if ( $license && ! empty( $license->secret_key ) ) {
			clientoctopus_push_license_to_relay( $license->secret_key, $plan, (int) $license->id );
		}
	} );

	clientoctopus_fs()->add_action( 'after_license_deactivation', static function (): void {
		$owner_id = (int) get_option( 'clientoctopus_owner_user_id', 0 );
		update_option( 'clientoctopus_license_key', '' );
		if ( $owner_id ) {
			ClientOctopus_Entitlements::set_user_plan( $owner_id, 'free' );
		}
	} );

	clientoctopus_fs()->add_action( 'after_license_change', static function ( $_plan_change, $plan ): void {
		$owner_id  = (int) get_option( 'clientoctopus_owner_user_id', 0 );
		$plan_name = strtolower( is_object( $plan ) ? (string) $plan->name : '' );
		if ( $owner_id && in_array( $plan_name, [ 'pro', 'agency' ], true ) ) {
			ClientOctopus_Entitlements::set_user_plan( $owner_id, $plan_name );
		}
	}, 10, 2 );

	clientoctopus_fs()->add_action( 'after_uninstall', static function (): void {
		// Off by default (Settings → Danger Zone) — deleting the plugin should
		// only remove code, not data, since "Delete" is also how a user might
		// (incorrectly, but commonly) start a free→paid upgrade. Data is only
		// wiped here if the site owner explicitly opted in.
		if ( ! get_option( 'clientoctopus_delete_data_on_uninstall' ) ) {
			return;
		}

		global $wpdb;

		// ── Custom tables ─────────────────────────────────────────────────────
		$tables = [
			'clientoctopus_user_meta',
			'clientoctopus_ai_usage_logs',
			'clientoctopus_clients',
			'clientoctopus_proposals',
			'clientoctopus_projects',
			'clientoctopus_milestones',
			'clientoctopus_payments',
			'clientoctopus_messages',
			'clientoctopus_files',
			'clientoctopus_approvals',
			'clientoctopus_events',
			'clientoctopus_team_members',
			'clientoctopus_webhooks',
			'clientoctopus_webhook_logs',
			'clientoctopus_automations',
			'clientoctopus_reminder_log',
			'clientoctopus_invoices',
			'clientoctopus_recurring_profiles',
			'clientoctopus_leads',
			'clientoctopus_lead_reminder_log',
			'clientoctopus_bookings',
			'clientoctopus_booking_blocks',
		];
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall hook: drops plugin-owned tables only; table names are hardcoded, not user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}

		// ── Options & transients ──────────────────────────────────────────────
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'clientoctopus\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_clientoctopus\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_timeout_clientoctopus\_%'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// ── Custom roles ──────────────────────────────────────────────────────
		remove_role( 'clientoctopus_client' );
		remove_role( 'clientoctopus_member' );

		// ── User meta ─────────────────────────────────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}usermeta WHERE meta_key LIKE '\_clientoctopus\_%'" );
	} );

	// Backfill: sync key and plan on first admin load after deployment,
	// for licenses that were active before these hooks existed.
	add_action( 'admin_init', static function (): void {
		$license = clientoctopus_fs()->_get_license();
		if ( ! $license || empty( $license->secret_key ) ) {
			return;
		}
		if ( ! get_option( 'clientoctopus_license_key', '' ) ) {
			update_option( 'clientoctopus_license_key', $license->secret_key );
		}
		$owner_id  = clientoctopus_get_owner_id( get_current_user_id() );
		$plan_name = strtolower( (string) clientoctopus_fs()->get_plan_name() );
		if ( 'free' === ClientOctopus_Entitlements::get_user_plan( $owner_id ) ) {
			if ( in_array( $plan_name, [ 'pro', 'agency' ], true ) ) {
				ClientOctopus_Entitlements::set_user_plan( $owner_id, $plan_name );
			}
		}
		// Push key to relay once per day so the relay DB stays in sync
		// even if the Freemius webhook never fired or the relay was redeployed.
		if ( ! get_transient( 'clientoctopus_relay_sync' ) ) {
			clientoctopus_push_license_to_relay( $license->secret_key, $plan_name, (int) $license->id );
			set_transient( 'clientoctopus_relay_sync', 1, DAY_IN_SECONDS );
		}
	} );
}

/**
 * Register the current Freemius license with the relay server.
 * Fire-and-forget: failures are logged but never block the user.
 */
function clientoctopus_push_license_to_relay( string $license_key, string $plan, int $license_id ): void {
	$relay_url = untrailingslashit( CLIENTOCTOPUS_AI_RELAY_URL );

	wp_remote_post(
		$relay_url . '/wp-json/co-relay/v1/register-license',
		[
			'timeout'  => 8,
			'blocking' => false,
			'headers'  => [ 'Content-Type' => 'application/json' ],
			'body'     => wp_json_encode( [
				'license_key' => $license_key,
				'license_id'  => $license_id,
				'product_id'  => 29266,
				'plan'        => in_array( $plan, [ 'pro', 'agency' ], true ) ? $plan : 'pro',
				'user_email'  => wp_get_current_user()->user_email ?? '',
			] ),
		]
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Constants
// ─────────────────────────────────────────────────────────────────────────────

define( 'CLIENTOCTOPUS_VERSION',        '1.3.0' );
define( 'CLIENTOCTOPUS_DB_VERSION',     '34' );
define( 'CLIENTOCTOPUS_REWRITE_VERSION', '4' );
define( 'CLIENTOCTOPUS_DIR',        plugin_dir_path( __FILE__ ) );
define( 'CLIENTOCTOPUS_URL',        plugin_dir_url( __FILE__ ) );
define( 'CLIENTOCTOPUS_BASENAME',   plugin_basename( __FILE__ ) );

// AI relay server URL — update this if you move hosting. Never exposed to agencies.
define( 'CLIENTOCTOPUS_AI_RELAY_URL', 'https://clientoctopus.clientoctopus.com' );

// ─────────────────────────────────────────────────────────────────────────────
// Autoloader
// ─────────────────────────────────────────────────────────────────────────────

/**
 * PSR-style autoloader for ClientOctopus_* classes.
 *
 * Maps:
 *   ClientOctopus_Entitlements → includes/class-entitlements.php
 *   ClientOctopus_Db           → includes/class-db.php
 *   ClientOctopus_Api          → includes/class-api.php
 *   ClientOctopus_Auth         → includes/class-auth.php
 */
spl_autoload_register( static function ( string $class ): void {
	if ( ! str_starts_with( $class, 'ClientOctopus_' ) ) {
		return;
	}

	// e.g. ClientOctopus_Entitlements → entitlements
	$slug = strtolower( substr( $class, strlen( 'ClientOctopus_' ) ) );
	$slug = str_replace( '_', '-', $slug );

	// Handlers class lives in a module directory without a class- prefix file.
	// ClientOctopus_Proposal_Handlers → modules/proposals/handlers.php
	if ( str_ends_with( $slug, '-handlers' ) ) {
		$module = str_replace( '-handlers', '', $slug );
		$path   = CLIENTOCTOPUS_DIR . "modules/{$module}/handlers.php";
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}

	$candidates = [
		CLIENTOCTOPUS_DIR . "includes/class-{$slug}.php",
		// Module classes: e.g. ClientOctopus_Proposal → modules/proposals/class-proposal.php
		// ClientOctopus_Proposal_Template → modules/proposals/class-proposal-template.php
		CLIENTOCTOPUS_DIR . "modules/" . strstr( $slug, '-', true ) . "/class-{$slug}.php",
	];

	foreach ( $candidates as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}
} );

// ─────────────────────────────────────────────────────────────────────────────
// Global helper
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Check whether a user can access a feature.
 *
 * This is the ONE function every module calls. It routes through the
 * single permission engine, ensuring no scattered plan checks.
 *
 * @param int    $user_id WordPress user ID.
 * @param string $feature Feature slug (e.g. 'use_ai', 'create_proposal').
 * @param array  $options Optional context (e.g. ['proposal_id' => 42]).
 *
 * @return bool|string Boolean for most features; string for portal tier.
 */
function clientoctopus_can_user( int $user_id, string $feature, array $options = [] ): bool|string {
	return ClientOctopus_Entitlements::can_user( $user_id, $feature, $options );
}

/**
 * Compute the next UTC timestamp at which $hour:00 occurs in the site's own
 * configured timezone — used to anchor daily crons to a predictable, sensible
 * time instead of wp_schedule_event( time(), ... )'s default of "whenever
 * this code first happened to run," which produces a different, effectively
 * random run time on every install.
 *
 * Note: WP-Cron's 'daily' schedule recurs every exactly 86400 seconds from
 * this anchor, not "at wall-clock $hour" — so the actual run time will drift
 * by an hour on the two days a year the site's timezone crosses a Daylight
 * Saving boundary. That's an inherent WP-Cron limitation, not fixable here.
 *
 * @param int $hour 0-23, in the site's local time.
 *
 * @return int Unix timestamp (UTC).
 */
function clientoctopus_next_daily_anchor( int $hour ): int {
	$now    = current_datetime();
	$anchor = $now->setTime( $hour, 0, 0 );
	if ( $anchor <= $now ) {
		$anchor = $anchor->modify( '+1 day' );
	}
	return $anchor->getTimestamp();
}

/**
 * Resolve the effective owner for a given WordPress user.
 *
 * If the user is a team member, returns the primary account owner's ID.
 * Otherwise returns the user's own ID. Use this in all module handlers so
 * team members see and operate on their owner's data.
 *
 * @param int $user_id WordPress user ID (typically get_current_user_id()).
 * @return int The owner user ID, or $user_id if not a team member.
 */
function clientoctopus_get_owner_id( int $user_id ): int {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$owner = $wpdb->get_var( $wpdb->prepare(
		"SELECT owner_id FROM {$wpdb->prefix}clientoctopus_team_members WHERE member_user_id = %d LIMIT 1",
		$user_id
	) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $owner ? (int) $owner : $user_id;
}

/**
 * Return the given user's team role: 'owner' if they're not a team member
 * row at all (the account owner themself), otherwise the stored
 * 'admin' | 'editor' | 'viewer' role from clientoctopus_team_members.
 *
 * 'owner' is always treated as at least as privileged as 'admin' by callers
 * — the account owner's own access must never be affected by this check,
 * since they aren't a team member and role enforcement only exists to
 * differentiate INVITED members from each other and from the owner.
 *
 * @param int $user_id WordPress user ID (typically get_current_user_id()).
 * @return string 'owner' | 'admin' | 'editor' | 'viewer'
 */
function clientoctopus_get_member_role( int $user_id ): string {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$role = $wpdb->get_var( $wpdb->prepare(
		"SELECT role FROM {$wpdb->prefix}clientoctopus_team_members WHERE member_user_id = %d LIMIT 1",
		$user_id
	) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $role ?: 'owner';
}

/**
 * Return #ffffff or #1A1A2E — whichever has better WCAG contrast against $hex.
 *
 * Uses relative luminance (WCAG 2.1) with a threshold of 0.35.
 *
 * @param string $hex Hex color with or without leading #. Three- or six-digit.
 * @return string '#ffffff' or '#1A1A2E'
 */
function clientoctopus_accessible_text_color( string $hex ): string {
	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) ) {
		return '#ffffff';
	}
	$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
	$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
	$b = hexdec( substr( $hex, 4, 2 ) ) / 255;
	$linearise = static function ( float $c ): float {
		return $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
	};
	$luminance = 0.2126 * $linearise( $r ) + 0.7152 * $linearise( $g ) + 0.0722 * $linearise( $b );
	return $luminance > 0.35 ? '#1A1A2E' : '#ffffff';
}

/**
 * Return $hex as-is if it's dark enough to read as TEXT on a white background,
 * otherwise return $fallback.
 *
 * Uses relative luminance (WCAG 2.1) with a threshold of 0.55 — looser than
 * clientoctopus_accessible_text_color()'s 0.35 since this is checking a colour
 * against a fixed white background, not picking between black/white.
 *
 * @param string $hex      Hex color with or without leading #. Three- or six-digit.
 * @param string $fallback Hex color to use when $hex is too light to read.
 * @return string
 */
function clientoctopus_readable_on_white( string $hex, string $fallback ): string {
	$clean = ltrim( $hex, '#' );
	if ( 3 === strlen( $clean ) ) {
		$clean = $clean[0] . $clean[0] . $clean[1] . $clean[1] . $clean[2] . $clean[2];
	}
	if ( 6 !== strlen( $clean ) ) {
		return $fallback;
	}
	$r = hexdec( substr( $clean, 0, 2 ) ) / 255;
	$g = hexdec( substr( $clean, 2, 2 ) ) / 255;
	$b = hexdec( substr( $clean, 4, 2 ) ) / 255;
	$linearise = static function ( float $c ): float {
		return $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
	};
	$luminance = 0.2126 * $linearise( $r ) + 0.7152 * $linearise( $g ) + 0.0722 * $linearise( $b );
	return $luminance > 0.55 ? $fallback : $hex;
}

/**
 * Build a branded HTML email body consistent with the magic-link email design.
 *
 * @param array $args {
 *   name          string  Client first/full name — used in "Hi {name},". Defaults to "there".
 *   body          string  Main content HTML (dropped inside a <td>; <p>, <strong>, <br> are fine).
 *   cta_label     string  Button label (optional).
 *   cta_url       string  Button href (optional).
 *   footer        string  Small footer note HTML (optional).
 *   business_name string  Defaults to the site name.
 * }
 * @return string Full HTML email document.
 */
function clientoctopus_email_html( array $args ): string {
	$business_name       = esc_html( $args['business_name'] ?? get_option( 'blogname', 'Client Octopus' ) );
	$hide_business_name  = get_option( 'clientoctopus_hide_business_name', '' );
	$name                = esc_html( $args['name'] ?? '' );
	$greeting            = $name ? "Hi {$name}," : 'Hi there,';
	$body                = $args['body'] ?? '';
	$title_tag           = ! empty( $args['subject'] ) ? '<title>' . esc_html( $args['subject'] ) . '</title>' : '';

	$brand_color        = get_option( 'clientoctopus_brand_color', '#6366F1' );
	$button_text_color  = clientoctopus_accessible_text_color( $brand_color );
	$logo_url           = get_option( 'clientoctopus_logo_url', '' );
	$cf_logo_url   = CLIENTOCTOPUS_URL . 'assets/images/logo-icon.png';

	$logo_html = '';
	// SVG images are not rendered by email clients (Gmail, Outlook, Apple Mail).
	// Skip the logo block if the URL points to an SVG file.
	$logo_is_svg = $logo_url && (
		str_ends_with( strtolower( wp_parse_url( $logo_url, PHP_URL_PATH ) ?? '' ), '.svg' )
	);
	if ( $logo_url && ! $logo_is_svg ) {
		$safe_logo = esc_url( $logo_url );
		$logo_html = "
          <tr>
            <td style=\"padding-bottom:16px;\">
              <img src=\"{$safe_logo}\" alt=\"{$business_name}\"
                   style=\"max-height:48px;max-width:180px;display:block;\" border=\"0\">
            </td>
          </tr>";
	}

	$cta_html = '';
	if ( ! empty( $args['cta_label'] ) && ! empty( $args['cta_url'] ) ) {
		$label    = esc_html( $args['cta_label'] );
		$href     = esc_url( $args['cta_url'] );
		$cta_html = "
          <tr>
            <td style=\"padding-bottom:36px;text-align:center;\">
              <a href=\"{$href}\"
                 style=\"display:inline-block;padding:16px 40px;background:{$brand_color};
                         color:{$button_text_color};font-size:16px;font-weight:600;text-decoration:none;
                         border-radius:12px;letter-spacing:0.01em;\">
                {$label}
              </a>
            </td>
          </tr>";
	}

	$footer_html = '';
	if ( ! empty( $args['footer'] ) ) {
		$footer_html = "
          <tr>
            <td style=\"border-top:1px solid #F3F4F6;padding-top:28px;\">
              <p style=\"margin:0;font-size:13px;color:#9CA3AF;line-height:1.6;\">{$args['footer']}</p>
            </td>
          </tr>";
	}

	ob_start();
	?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above
echo $title_tag;
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
</head>
<body style="margin:0;padding:0;background:#F8F7F5;font-family:'DM Sans',Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F8F7F5;padding:48px 16px;">
    <tr>
      <td align="center">
        <table width="520" cellpadding="0" cellspacing="0"
               style="background:#ffffff;border-radius:20px;padding:48px 44px;
                      box-shadow:0 2px 4px rgba(26,26,46,.04),0 12px 40px rgba(26,26,46,.09);">
          <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url/esc_html above
echo $logo_html;
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
          <?php if ( ! $hide_business_name ) : ?>
          <tr>
            <td style="padding-bottom:32px;border-bottom:1px solid #F3F4F6;">
              <p style="margin:0;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;
                        color:#9CA3AF;font-weight:600;"><?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html applied above
echo $business_name;
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?></p>
            </td>
          </tr>
          <?php endif; ?>
          <tr>
            <td style="padding-top:36px;padding-bottom:12px;">
              <h1 style="margin:0;font-size:28px;font-weight:700;color:#1A1A2E;
                         font-family:Georgia,serif;letter-spacing:-0.02em;">
                <?php echo esc_html( $greeting ); ?>
              </h1>
            </td>
          </tr>
          <tr>
            <td style="padding-bottom:32px;">
              <?php echo wp_kses_post( $body ); ?>
            </td>
          </tr>
          <?php echo wp_kses_post( $cta_html ); ?>
          <?php echo wp_kses_post( $footer_html ); ?>
        </table>
        <?php if ( get_option( 'clientoctopus_show_powered_by' ) ) : ?>
        <table width="520" cellpadding="0" cellspacing="0" style="margin-top:24px;">
          <tr>
            <td align="center" style="padding-bottom:32px;">
              <a href="https://clientoctopus.com"
                 style="text-decoration:none;display:inline-flex;align-items:center;vertical-align:middle;">
                <span style="font-family:'DM Sans',Helvetica,Arial,sans-serif;font-size:13px;
                             color:#9CA3AF;vertical-align:middle;letter-spacing:0.02em;margin-right:7px;">Powered by</span>
                <img src="<?php echo esc_url( $cf_logo_url ); ?>" alt="Client Octopus"
                     style="display:inline-block;vertical-align:middle;border:0;height:18px;width:auto;">
              </a>
            </td>
          </tr>
        </table>
        <?php endif; ?>
      </td>
    </tr>
  </table>
</body>
</html>
	<?php
	return ob_get_clean();
}

/**
 * Simple transient-based rate limiter, keyed by an arbitrary string — the
 * shared implementation behind clientoctopus_rest_rate_limit() (WP user ID)
 * and any caller needing to key by something else instead, e.g. a public
 * proposal token, for endpoints that have no WordPress user at all.
 *
 * Returns true if the request is allowed, false if the caller has exceeded
 * $limit actions within the current $window-second period.
 *
 * @param string $action    Unique action slug (e.g. 'send_proposal').
 * @param string $key_value Whatever identifies "the same caller" for this action.
 * @param int    $limit     Maximum allowed calls per window.
 * @param int    $window    Window length in seconds (default 60).
 * @return bool True = allowed, false = rate limited.
 */
function clientoctopus_rest_rate_limit_by_key( string $action, string $key_value, int $limit = 60, int $window = 60 ): bool {
	$key   = 'clientoctopus_rl_' . md5( $action . '_' . $key_value );
	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return false;
	}
	if ( 0 === $count ) {
		set_transient( $key, 1, $window );
	} else {
		set_transient( $key, $count + 1, $window );
	}
	return true;
}

/**
 * Simple transient-based rate limiter for REST endpoints, keyed by WP user ID.
 *
 * @param string $action  Unique action slug (e.g. 'send_proposal').
 * @param int    $user_id WordPress user ID.
 * @param int    $limit   Maximum allowed calls per window.
 * @param int    $window  Window length in seconds (default 60).
 * @return bool True = allowed, false = rate limited.
 */
function clientoctopus_rest_rate_limit( string $action, int $user_id, int $limit = 60, int $window = 60 ): bool {
	return clientoctopus_rest_rate_limit_by_key( $action, (string) $user_id, $limit, $window );
}

/**
 * Best-effort visitor IP address, preferring a CDN/proxy-supplied header over
 * REMOTE_ADDR when present. Every other IP-keyed rate limit in this plugin
 * (portal login/magic-link) only reads REMOTE_ADDR directly, which is fine
 * behind those specific auth flows but under-collects for a public marketing
 * page embed (the lead-capture shortcode), which is far more likely to sit
 * behind a CDN — falls back to REMOTE_ADDR when no proxy header is present.
 *
 * @return string
 */
/**
 * Resolve the "client IP" used to key public rate limits (booking, lead
 * capture). Defaults to REMOTE_ADDR — the address the webserver itself
 * recorded for the TCP connection, which a client cannot forge — rather than
 * X-Forwarded-For, which is an ordinary request header any visitor can set
 * to an arbitrary value, making header-based rate limiting fully bypassable
 * by sending a fresh fake value on every request.
 *
 * Sites genuinely running behind a trusted reverse proxy or CDN (where
 * REMOTE_ADDR is the proxy's own address for every visitor, not the real
 * client) can opt back into header-based resolution via this filter — that
 * decision belongs to whoever controls the server's proxy configuration, not
 * to a default that trusts client-supplied input unconditionally.
 *
 * @return string
 */
function clientoctopus_get_client_ip(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately via sanitize_text_field.
	$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );

	/**
	 * Filters the resolved client IP for rate-limiting purposes.
	 *
	 * @param string $ip REMOTE_ADDR by default.
	 */
	return (string) apply_filters( 'clientoctopus_client_ip', $ip );
}

function clientoctopus_output_template_favicon(): void {
	$icon = get_site_icon_url( 32 );
	if ( $icon ) {
		printf( '<link rel="icon" href="%s">', esc_url( $icon ) );
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Main plugin class — singleton bootstrap.
 */
final class ClientOctopus {

	private static ?self $instance = null;

	/**
	 * Retrieve or create the singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** @codeCoverageIgnore */
	private function __construct() {
		$this->register_hooks();
	}

	// ── Hooks ────────────────────────────────────────────────────────────────

	/**
	 * Register all WordPress hooks.
	 */
	private function register_hooks(): void {
		add_action( 'admin_menu',              [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_admin_assets' ] );

		// Override the From address/name for all plugin emails when configured.
		add_filter( 'wp_mail_from', static function ( string $email ): string {
			$configured = get_option( 'clientoctopus_from_email', '' );
			return $configured ? $configured : $email;
		} );
		add_filter( 'wp_mail_from_name', static function ( string $name ): string {
			$configured = get_option( 'clientoctopus_from_name', '' );
			return $configured ? $configured : $name;
		} );

		// Ensure clientoctopus_member always has 'read' so users can access wp-admin.
		// Runs on 'init' (before admin_init) so the cap is present before WP checks access.
		add_action( 'init', static function (): void {
			$role = get_role( 'clientoctopus_member' );
			if ( $role && ! $role->has_cap( 'read' ) ) {
				$role->add_cap( 'read' );
			}
		} );

		// Ensure administrator and clientoctopus_member roles always have manage_clientoctopus,
		// even on installs that were active before this capability was introduced.
		// Also strip the clientoctopus_member role from any user who has it but is not
		// in clientoctopus_team_members — catches bypasses via direct DB edits or CLI.
		add_action( 'admin_init', static function (): void {
			foreach ( [ 'administrator', 'clientoctopus_member' ] as $role_slug ) {
				$role = get_role( $role_slug );
				if ( $role && ! $role->has_cap( 'manage_clientoctopus' ) ) {
					$role->add_cap( 'manage_clientoctopus' );
				}
			}

			if ( ! get_transient( 'clientoctopus_role_guard_ran' ) ) {
				global $wpdb;
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names use $wpdb->users and $wpdb->prefix with hardcoded slugs, not user input.
				$unauthorised = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT u.ID
						 FROM {$wpdb->users} u
						 JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
						    AND um.meta_key = %s
						    AND um.meta_value LIKE %s
						 WHERE u.ID NOT IN (
						     SELECT member_user_id FROM {$wpdb->prefix}clientoctopus_team_members
						 )",
						$wpdb->prefix . 'capabilities',
						'%clientoctopus_member%'
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $unauthorised as $uid ) {
					( new WP_User( (int) $uid ) )->remove_role( 'clientoctopus_member' );
				}
				set_transient( 'clientoctopus_role_guard_ran', true, DAY_IN_SECONDS );
			}
		} );

		add_action( 'admin_init', static function (): void {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection, no state change.
			$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

			// Redirect away from setup if onboarding is already complete
			// (e.g. Freemius opt-in redirected back to first-path after wizard was done).
			if ( 'clientoctopus-setup' === $page && get_option( 'clientoctopus_onboarding_complete' ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=clientoctopus' ) );
				exit;
			}

			// Redirect to setup wizard after fresh activation, but only once Freemius
			// opt-in is resolved (registered or skipped). This lets Freemius show its
			// opt-in banner on the first admin page load; the wizard opens on the next.
			if (
				get_option( 'clientoctopus_show_setup_wizard' ) &&
				current_user_can( 'manage_options' ) &&
				( clientoctopus_fs()->is_registered() || clientoctopus_fs()->is_anonymous() )
			) {
				delete_option( 'clientoctopus_show_setup_wizard' );
				if ( 'clientoctopus-setup' !== $page ) {
					wp_safe_redirect( admin_url( 'admin.php?page=clientoctopus-setup' ) );
					exit;
				}
			}
		} );

		// Run DB migrations automatically when the stored version is behind.
		add_action( 'admin_init', static function (): void {
			if ( (string) get_option( 'clientoctopus_db_version', '0' ) !== CLIENTOCTOPUS_DB_VERSION ) {
				$schema = CLIENTOCTOPUS_DIR . 'database/schema.php';
				if ( file_exists( $schema ) ) {
					require_once $schema;
					clientoctopus_create_tables();
					update_option( 'clientoctopus_db_version', CLIENTOCTOPUS_DB_VERSION );
				}
			}
		} );

		// Flush rewrite rules once whenever the plugin version changes (new routes deployed).
		add_action( 'admin_init', static function (): void {
			if ( get_option( 'clientoctopus_rewrite_version' ) !== CLIENTOCTOPUS_REWRITE_VERSION ) {
				flush_rewrite_rules( false );
				update_option( 'clientoctopus_rewrite_version', CLIENTOCTOPUS_REWRITE_VERSION );
			}
		} );

		// Include REST route files NOW (during plugins_loaded) so that each
		// file's own add_action('rest_api_init', ...) callback is registered
		// before rest_api_init fires. If the files were included inside a
		// rest_api_init callback, their inner add_action calls would be too
		// late — rest_api_init would already have fired and the routes would
		// never be registered.
		$this->load_rest_files();

		// This "if" block is auto-removed from the free WP.org build by
		// Freemius's deployment processor.
		if ( clientoctopus_fs()->is__premium_only() ) {
			// Load webhook dispatcher.
			$dispatcher = CLIENTOCTOPUS_DIR . 'modules/webhooks/dispatcher.php';
			if ( file_exists( $dispatcher ) ) {
				require_once $dispatcher;
			}

			// Load portal routing (rewrite rules + template_redirect).
			$portal_routing = CLIENTOCTOPUS_DIR . 'portal/routing.php';
			if ( file_exists( $portal_routing ) ) {
				require_once $portal_routing;
			}

			// Load call booking email/reminder handlers and the
			// [clientoctopus_booking_form] shortcode.
			$booking_handlers = CLIENTOCTOPUS_DIR . 'modules/booking/handlers.php';
			if ( file_exists( $booking_handlers ) ) {
				require_once $booking_handlers;
			}
			$booking_shortcode = CLIENTOCTOPUS_DIR . 'modules/booking/shortcode.php';
			if ( file_exists( $booking_shortcode ) ) {
				require_once $booking_shortcode;
			}

			// Load calendar sync (busy-time import + booking write-back for
			// Google/Microsoft/Apple) — depends on modules/booking/handlers.php
			// already being loaded above for clientoctopus_generate_ics() and
			// clientoctopus_booking_settings().
			$calendar_sync_handlers = CLIENTOCTOPUS_DIR . 'modules/calendar-sync/handlers.php';
			if ( file_exists( $calendar_sync_handlers ) ) {
				require_once $calendar_sync_handlers;
			}
		}

		// Load client-facing proposal routing (rewrite rules + template_redirect).
		// Available on all plans — clients can view and sign proposals on free tier.
		// Also registers invoice rewrite rules (invoices/{token}).
		$routing = CLIENTOCTOPUS_DIR . 'modules/proposals/client-routing.php';
		if ( file_exists( $routing ) ) {
			require_once $routing;
		}

		// Output favicon on standalone client-facing templates (proposal + invoice).
		// Raw <link> tags are not permitted by WP.org; a named action hook is used instead.
		add_action( 'clientoctopus_template_head', 'clientoctopus_output_template_favicon' );

		// Load invoice email + webhook handlers — fires on clientoctopus_invoice_* actions.
		$invoice_handlers = CLIENTOCTOPUS_DIR . 'modules/invoices/handlers.php';
		if ( file_exists( $invoice_handlers ) ) {
			require_once $invoice_handlers;
		}

		// Load lead capture email + webhook handlers — fires on clientoctopus_lead_captured.
		$lead_handlers = CLIENTOCTOPUS_DIR . 'modules/leads/handlers.php';
		if ( file_exists( $lead_handlers ) ) {
			require_once $lead_handlers;
		}

		// Load the [clientoctopus_lead_form] shortcode — available on all plans.
		$lead_shortcode = CLIENTOCTOPUS_DIR . 'modules/leads/shortcode.php';
		if ( file_exists( $lead_shortcode ) ) {
			require_once $lead_shortcode;
		}

		// Load lead auto-archive — hooks the existing daily automations cron.
		$lead_archive = CLIENTOCTOPUS_DIR . 'modules/leads/archive.php';
		if ( file_exists( $lead_archive ) ) {
			require_once $lead_archive;
		}

		// Load lead GDPR export/erase support.
		$lead_privacy = CLIENTOCTOPUS_DIR . 'modules/leads/privacy.php';
		if ( file_exists( $lead_privacy ) ) {
			require_once $lead_privacy;
		}

		// Load client GDPR export/erase support.
		$client_privacy = CLIENTOCTOPUS_DIR . 'modules/clients/privacy.php';
		if ( file_exists( $client_privacy ) ) {
			require_once $client_privacy;
		}

		// No WP admin block needed — portal clients no longer have WordPress accounts.

		// When a WP user is deleted, remove their team member record and decrement
		// the owner's seat counter so the count stays accurate.
		add_action( 'deleted_user', static function ( int $user_id ): void {
			global $wpdb;
			$table = $wpdb->prefix . 'clientoctopus_team_members';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix + hardcoded slug, not user input.
			$owner_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT owner_id FROM {$table} WHERE member_user_id = %d LIMIT 1",
				$user_id
			) );

			if ( $owner_id ) {
				$wpdb->delete( $table, [ 'member_user_id' => $user_id ], [ '%d' ] );
				// Recalculate from actual rows rather than decrementing, so the counter
				// stays accurate even when concurrent deletions race each other.
				$actual = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE owner_id = %d",
					(int) $owner_id
				) );
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}clientoctopus_user_meta
					 SET team_seats_used = %d
					 WHERE user_id = %d",
					max( 1, $actual + 1 ), // +1 for the owner themselves
					(int) $owner_id
				) );
			}
		} );

		// Prevent seat-limit bypass by blocking direct role assignment.
		// The clientoctopus_member role must only be granted through the invite system,
		// which validates limits and creates the clientoctopus_team_members row.
		// If the role is assigned any other way, strip it immediately.
		$enforce_team_role = static function ( int $user_id, string $role ): void {
			if ( 'clientoctopus_member' !== $role ) {
				return;
			}
			global $wpdb;
			$in_team = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}clientoctopus_team_members WHERE member_user_id = %d LIMIT 1",
				$user_id
			) );
			if ( ! $in_team ) {
				( new WP_User( $user_id ) )->remove_role( 'clientoctopus_member' );
			}
		};
		add_action( 'set_user_role', $enforce_team_role, 10, 2 );
		add_action( 'add_user_role', $enforce_team_role, 10, 2 );

		// Redirect team members to Client Octopus after login instead of the homepage.
		add_filter( 'login_redirect', static function ( string $redirect_to, string $_requested, $user ): string {
			if ( $user instanceof WP_User && in_array( 'clientoctopus_member', (array) $user->roles, true ) ) {
				return admin_url( 'admin.php?page=clientoctopus-proposals' );
			}
			return $redirect_to;
		}, 10, 3 );

		// Mark a team member's invite as accepted the first time they log in.
		add_action( 'wp_login', static function ( string $_login, WP_User $user ): void {
			global $wpdb;
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}clientoctopus_team_members
				 SET accepted_at = %s
				 WHERE member_user_id = %d AND accepted_at IS NULL",
				gmdate( 'Y-m-d H:i:s' ),
				$user->ID
			) );
		}, 10, 2 );

		// Suppress admin bar for portal clients.
		// Portal clients use custom sessions — no WP login redirect needed.

		// No action needed on proposal send — portal clients authenticate via magic
		// links and do not require a WordPress user account to be pre-created.

		// Hook: auto-create project when a proposal is accepted (Agency tier only).
		add_action( 'clientoctopus_proposal_accepted', static function ( int $proposal_id, int $owner_id ): void {
			if ( ! clientoctopus_can_user( $owner_id, 'use_projects' ) ) {
				return;
			}
			$base = CLIENTOCTOPUS_DIR . 'modules/projects/';
			foreach ( [
				'class-project.php'   => 'ClientOctopus_Project',
				'class-milestone.php' => 'ClientOctopus_Milestone',
				'handlers.php'        => 'ClientOctopus_Project_Handlers',
			] as $file => $class ) {
				if ( ! class_exists( $class ) && file_exists( $base . $file ) ) {
					require_once $base . $file;
				}
			}
			$result = ClientOctopus_Project_Handlers::create_from_accepted_proposal( $proposal_id, $owner_id );
			if ( is_wp_error( $result ) ) {
				// Silently fail — project auto-creation errors are non-fatal.
			}
		}, 10, 2 );

		// Hook: auto-create a recurring-invoice profile when a recurring-billing
		// proposal is accepted. Re-fetches the proposal (same fresh-DB-refetch
		// pattern as the project-auto-creation listener above) so it sees the
		// already-resolved content.line_items — this means it works correctly
		// for both Flat and Package Selector proposals with recurring enabled.
		// Non-fatal on failure, matching the other listeners on this hook.
		add_action( 'clientoctopus_proposal_accepted', static function ( int $proposal_id, int $owner_id ): void {
			if ( ! class_exists( 'ClientOctopus_Proposal' ) ) {
				$path = CLIENTOCTOPUS_DIR . 'modules/proposals/class-proposal.php';
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
			$proposal = ClientOctopus_Proposal::get( $proposal_id, $owner_id );
			if ( is_wp_error( $proposal ) ) {
				return;
			}
			$content = is_array( $proposal['content'] ?? null ) ? $proposal['content'] : [];
			if ( empty( $content['recurring']['enabled'] ) || empty( $proposal['client_id'] ) ) {
				return;
			}
			$line_items = is_array( $content['line_items'] ?? null ) ? $content['line_items'] : [];
			if ( empty( $line_items ) ) {
				// No resolved line items — creating a permanently-£0 recurring
				// profile would be worse than not creating one at all.
				return;
			}

			if ( ! class_exists( 'ClientOctopus_Recurring_Profile' ) ) {
				$path = CLIENTOCTOPUS_DIR . 'modules/invoices/class-recurring-profile.php';
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
			if ( ! class_exists( 'ClientOctopus_Recurring_Profile' ) ) {
				return;
			}

			// Recurring profiles use a flat {description, amount} line-item shape
			// (no quantity), unlike proposals' {description, qty, unit_price} —
			// collapse qty*unit_price into a single amount per line.
			$profile_line_items = array_map( static function ( array $item ): array {
				return [
					'description' => $item['description'] ?? '',
					'amount'      => ( (float) ( $item['qty'] ?? 0 ) ) * ( (float) ( $item['unit_price'] ?? 0 ) ),
				];
			}, $line_items );

			$recurring = $content['recurring'];

			// auto_charge requested but no longer actually usable (e.g. the plan
			// or gateway configuration changed between proposal creation and
			// client acceptance): silently fall back to manual rather than
			// dropping the whole recurring profile — the client already
			// accepted a proposal promising recurring billing, so it must
			// still get created. Uses the same check create() itself applies
			// via sanitize_billing_mode(), so this can never desync from it.
			$billing_mode = $recurring['billing_mode'] ?? 'manual';
			if ( 'auto_charge' === $billing_mode && ! ClientOctopus_Recurring_Profile::can_auto_charge( $owner_id ) ) {
				$billing_mode = 'manual';
			}

			$profile = ClientOctopus_Recurring_Profile::create( $owner_id, [
				'client_id'       => (int) $proposal['client_id'],
				'title'           => $proposal['title'] ?? '',
				'line_items'      => $profile_line_items,
				'currency'        => $proposal['currency'] ?? 'GBP',
				'discount_type'   => 'percentage',
				'discount_value'  => (float) ( $content['discount_pct'] ?? 0 ),
				'vat_pct'         => (float) ( $content['vat_pct'] ?? 0 ),
				'frequency'       => $recurring['frequency']       ?? 'monthly',
				'start_date'      => $recurring['start_date']      ?? gmdate( 'Y-m-d' ),
				'end_date'        => $recurring['end_date']        ?? null,
				'max_occurrences' => $recurring['max_occurrences'] ?? null,
				'payment_terms'   => $recurring['payment_terms']   ?? '',
				'notes'           => $recurring['notes']           ?? '',
				'billing_mode'    => $billing_mode,
			] );

			if ( is_wp_error( $profile ) ) {
				return;
			}

			// Write the profile id back as an audit record only — never read by
			// anything else, mirrors selected_tier_id for Package Selector.
			global $wpdb;
			$content['recurring_profile_id'] = $profile['id'] ?? null;
			$wpdb->update(
				$wpdb->prefix . 'clientoctopus_proposals',
				[ 'content' => wp_json_encode( $content ) ],
				[ 'id' => $proposal_id ]
			);
		}, 15, 2 );

		// Hook: send portal invitation email when a proposal is accepted.
		// Sends a magic link to the client without creating a WordPress user account.
		add_action( 'clientoctopus_proposal_accepted', static function ( int $proposal_id, int $owner_id ): void {
			global $wpdb;
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT c.id AS client_id, c.email AS client_email, c.name AS client_name
					 FROM {$wpdb->prefix}clientoctopus_proposals p
					 JOIN {$wpdb->prefix}clientoctopus_clients c ON c.id = p.client_id
					 WHERE p.id = %d",
					$proposal_id
				),
				ARRAY_A
			);
			if ( ! $row || empty( $row['client_email'] ) ) {
				return;
			}
			// Only send the invite email when the owner has portal access.
			// The class_exists() check is a defensive fallback for the free
			// WP.org build, where modules/portal/ is physically absent.
			if ( ! clientoctopus_can_user( $owner_id, 'use_portal' ) || ! class_exists( 'ClientOctopus_Portal_Auth' ) ) {
				return;
			}
			$client_id = (int) $row['client_id'];
			$raw_token = ClientOctopus_Portal_Auth::generate_magic_token( $client_id );
			ClientOctopus_Portal_Auth::send_magic_link_email( $row['client_email'], $row['client_name'] ?? '', $raw_token );
			$wpdb->update(
				$wpdb->prefix . 'clientoctopus_clients',
				[ 'portal_invited_at' => current_time( 'mysql' ) ],
				[ 'id' => $client_id ]
			);
		}, 20, 2 );

		// Portal client passwords are now stored in clientoctopus_clients.portal_password_hash
		// and managed entirely by the portal — no WordPress profile_update hook needed.

		// ── Outbound webhook dispatch ─────────────────────────────────────────
		// This "if" block is auto-removed from the free WP.org build by
		// Freemius's deployment processor.
		if ( clientoctopus_fs()->is__premium_only() ) {
		add_action( 'clientoctopus_proposal_sent', static function ( int $proposal_id, int $owner_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			$proposal = ClientOctopus_Proposal::get( $proposal_id, $owner_id );
			if ( is_wp_error( $proposal ) ) return;
			$sent_content = is_array( $proposal['content'] ?? null ) ? $proposal['content'] : [];
			clientoctopus_webhook_dispatch( 'proposal.sent', $owner_id, [
				'proposal_id'  => $proposal_id,
				'title'        => $proposal['title'] ?? '',
				'total'        => $proposal['total_amount'] ?? null,
				'pricing_mode' => $sent_content['pricing_mode'] ?? 'flat',
				'currency'     => $proposal['currency'] ?? 'GBP',
				'status'       => $proposal['status'] ?? '',
			] );
		}, 99, 2 );

		add_action( 'clientoctopus_proposal_accepted', static function ( int $proposal_id, int $owner_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			$proposal = ClientOctopus_Proposal::get( $proposal_id, $owner_id );
			if ( is_wp_error( $proposal ) ) return;
			clientoctopus_webhook_dispatch( 'proposal.accepted', $owner_id, [
				'proposal_id' => $proposal_id,
				'title'       => $proposal['title'] ?? '',
				'total'       => $proposal['total_amount'] ?? null,
				'currency'    => $proposal['currency'] ?? 'GBP',
			] );
		}, 99, 2 );

		add_action( 'clientoctopus_proposal_declined', static function ( int $proposal_id, int $owner_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			$proposal = ClientOctopus_Proposal::get( $proposal_id, $owner_id );
			if ( is_wp_error( $proposal ) ) return;
			clientoctopus_webhook_dispatch( 'proposal.declined', $owner_id, [
				'proposal_id'    => $proposal_id,
				'title'          => $proposal['title'] ?? '',
				'decline_reason' => $proposal['decline_reason'] ?? '',
			] );
		}, 99, 2 );

		add_action( 'clientoctopus_revision_requested', static function ( int $proposal_id, int $owner_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			$proposal = ClientOctopus_Proposal::get( $proposal_id, $owner_id );
			if ( is_wp_error( $proposal ) ) return;
			clientoctopus_webhook_dispatch( 'proposal.revision_requested', $owner_id, [
				'proposal_id'   => $proposal_id,
				'title'         => $proposal['title'] ?? '',
				'revision_note' => $proposal['revision_note'] ?? '',
			] );
		}, 99, 2 );

		add_action( 'clientoctopus_payment_completed', static function ( int $payment_id, int $owner_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			global $wpdb;
			$payment = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT p.*, pr.title AS proposal_title
					 FROM {$wpdb->prefix}clientoctopus_payments p
					 LEFT JOIN {$wpdb->prefix}clientoctopus_proposals pr ON pr.id = p.proposal_id
					 WHERE p.id = %d AND p.owner_id = %d",
					$payment_id,
					$owner_id
				),
				ARRAY_A
			);
			if ( ! $payment ) return;
			clientoctopus_webhook_dispatch( 'payment.completed', $owner_id, [
				'payment_id'     => $payment_id,
				'proposal_id'    => (int) $payment['proposal_id'],
				'proposal_title' => $payment['proposal_title'] ?? '',
				'amount'         => $payment['amount'],
				'currency'       => $payment['currency'],
			] );
		}, 99, 2 );

		// Fired from rest-api/payments.php::clientoctopus_handle_payment_failed() —
		// gateway-agnostic, covers both invoice- and proposal-type declines/expirations.
		add_action( 'clientoctopus_payment_failed', static function ( int $owner_id, array $context ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			clientoctopus_webhook_dispatch( 'payment.failed', $owner_id, [
				'type'        => $context['type'] ?? '',
				'invoice_id'  => $context['invoice_id']  ?? null,
				'proposal_id' => $context['proposal_id'] ?? null,
				'amount'      => $context['amount']   ?? 0,
				'currency'    => $context['currency'] ?? '',
			] );
		}, 99, 2 );

		add_action( 'clientoctopus_project_created', static function ( int $project_id, int $owner_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			$project = ClientOctopus_Project::get( $project_id, $owner_id );
			if ( is_wp_error( $project ) ) return;
			clientoctopus_webhook_dispatch( 'project.created', $owner_id, [
				'project_id'   => $project_id,
				'name'         => $project['name'] ?? '',
				'client_name'  => $project['client_name'] ?? '',
				'proposal_id'  => $project['proposal_id'] ?? null,
			] );
		}, 99, 2 );

		add_action( 'clientoctopus_project_completed', static function ( int $project_id, int $owner_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			$project = ClientOctopus_Project::get( $project_id, $owner_id );
			if ( is_wp_error( $project ) ) return;
			clientoctopus_webhook_dispatch( 'project.completed', $owner_id, [
				'project_id'  => $project_id,
				'name'        => $project['name'] ?? '',
				'client_name' => $project['client_name'] ?? '',
			] );
		}, 99, 2 );

		add_action( 'clientoctopus_milestone_submitted', static function ( int $owner_id, int $milestone_id, int $project_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			clientoctopus_webhook_dispatch( 'milestone.submitted', $owner_id, [
				'milestone_id' => $milestone_id,
				'project_id'   => $project_id,
			] );
		}, 99, 3 );

		add_action( 'clientoctopus_milestone_approved', static function ( int $owner_id, int $milestone_id, int $project_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			clientoctopus_webhook_dispatch( 'milestone.approved', $owner_id, [
				'milestone_id' => $milestone_id,
				'project_id'   => $project_id,
			] );
		}, 99, 3 );

		add_action( 'clientoctopus_milestone_completed', static function ( int $owner_id, int $milestone_id, int $project_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			clientoctopus_webhook_dispatch( 'milestone.completed', $owner_id, [
				'milestone_id' => $milestone_id,
				'project_id'   => $project_id,
			] );
		}, 99, 3 );

		add_action( 'clientoctopus_approval_responded', static function ( int $owner_id, int $approval_id, string $status ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			clientoctopus_webhook_dispatch( 'approval.responded', $owner_id, [
				'approval_id' => $approval_id,
				'status'      => $status,
			] );
		}, 99, 3 );

		add_action( 'clientoctopus_message_sent', static function ( int $owner_id, int $message_id, int $project_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			clientoctopus_webhook_dispatch( 'message.sent', $owner_id, [
				'message_id' => $message_id,
				'project_id' => $project_id,
			] );
		}, 99, 3 );
		}

		// Register custom cron intervals.
		add_filter( 'cron_schedules', static function ( array $schedules ): array {
			if ( ! isset( $schedules['monthly'] ) ) {
				$schedules['monthly'] = [
					'interval' => 30 * DAY_IN_SECONDS,
					'display'  => __( 'Once a month', 'clientoctopus' ),
				];
			}
			if ( ! isset( $schedules['clientoctopus_15min'] ) ) {
				$schedules['clientoctopus_15min'] = [
					'interval' => 15 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 15 minutes', 'clientoctopus' ),
				];
			}
			return $schedules;
		} );

		// Monthly usage reset — fires at start of each month.
		add_action( 'clientoctopus_monthly_reset', [ ClientOctopus_Entitlements::class, 'reset_monthly_usage' ] );

		add_action( 'init', static function (): void {
			if ( ! wp_next_scheduled( 'clientoctopus_monthly_reset' ) ) {
				wp_schedule_event(
					(int) strtotime( 'first day of next month midnight' ),
					'monthly',
					'clientoctopus_monthly_reset'
				);
			}
		} );

		// Sync pending Stripe payments every 15 minutes (webhook fallback).
		add_action( 'clientoctopus_sync_pending_payments', 'clientoctopus_cron_sync_pending_payments' );

		add_action( 'init', static function (): void {
			if ( ! wp_next_scheduled( 'clientoctopus_sync_pending_payments' ) ) {
				wp_schedule_event( time(), 'clientoctopus_15min', 'clientoctopus_sync_pending_payments' );
			}
		} );

		// Daily automation reminders — fires once per day to send follow-up emails.
		add_action( 'clientoctopus_daily_automations', static function (): void {
			$path = CLIENTOCTOPUS_DIR . 'modules/automations/class-automations.php';
			if ( ! class_exists( 'ClientOctopus_Automations' ) && file_exists( $path ) ) {
				require_once $path;
			}
			if ( class_exists( 'ClientOctopus_Automations' ) ) {
				ClientOctopus_Automations::run_daily();
			}
		} );

		add_action( 'init', static function (): void {
			if ( ! wp_next_scheduled( 'clientoctopus_daily_automations' ) ) {
				wp_schedule_event( clientoctopus_next_daily_anchor( 9 ), 'daily', 'clientoctopus_daily_automations' );
			}
		} );

		//@fs_premium_only
		// Recurring invoices — daily cron generates + sends the next invoice for
		// every profile due today or earlier. Pro/Agency only, matching the
		// entitlement gate on the REST routes and admin nav item.
		add_action( 'clientoctopus_process_recurring_profiles', static function (): void {
			$path = CLIENTOCTOPUS_DIR . 'modules/invoices/class-recurring-profile.php';
			if ( ! class_exists( 'ClientOctopus_Recurring_Profile' ) && file_exists( $path ) ) {
				require_once $path;
			}
			if ( class_exists( 'ClientOctopus_Recurring_Profile' ) ) {
				ClientOctopus_Recurring_Profile::process_due();
			}
		} );

		add_action( 'init', static function (): void {
			if ( ! wp_next_scheduled( 'clientoctopus_process_recurring_profiles' ) ) {
				wp_schedule_event( clientoctopus_next_daily_anchor( 9 ), 'daily', 'clientoctopus_process_recurring_profiles' );
			}
		} );

		// Auto-charge retry/dunning — separate daily cron from the one above,
		// which only ever generates new cycles. This one re-attempts a charge
		// on a profile's existing unpaid invoice after a prior failure.
		add_action( 'clientoctopus_retry_failed_recurring_charges', static function (): void {
			$path = CLIENTOCTOPUS_DIR . 'modules/invoices/class-recurring-profile.php';
			if ( ! class_exists( 'ClientOctopus_Recurring_Profile' ) && file_exists( $path ) ) {
				require_once $path;
			}
			if ( class_exists( 'ClientOctopus_Recurring_Profile' ) ) {
				ClientOctopus_Recurring_Profile::retry_failed_charges();
			}
		} );

		add_action( 'init', static function (): void {
			if ( ! wp_next_scheduled( 'clientoctopus_retry_failed_recurring_charges' ) ) {
				wp_schedule_event( time(), 'daily', 'clientoctopus_retry_failed_recurring_charges' );
			}
		} );

		// Call booking reminders — reuses the clientoctopus_15min interval
		// already registered above for the payment-sync cron; the actual
		// handler is registered in modules/booking/handlers.php.
		add_action( 'init', static function (): void {
			if ( ! wp_next_scheduled( 'clientoctopus_send_booking_reminders' ) ) {
				wp_schedule_event( time(), 'clientoctopus_15min', 'clientoctopus_send_booking_reminders' );
			}
		} );

		// Calendar Sync busy-time polling — same clientoctopus_15min interval;
		// the actual handler is registered in modules/calendar-sync/handlers.php.
		// clientoctopus_15min is only a registered *recurrence schedule*, not a
		// hook anything fires on its own — a distinct event hook (this one)
		// must actually be scheduled against it, the same as the two crons above.
		add_action( 'init', static function (): void {
			if ( ! wp_next_scheduled( 'clientoctopus_calendar_sync_tick' ) ) {
				wp_schedule_event( time(), 'clientoctopus_15min', 'clientoctopus_calendar_sync_tick' );
			}
		} );
		//@end:fs_premium_only
	}

	// ── REST API ─────────────────────────────────────────────────────────────

	/**
	 * Include REST route files during plugins_loaded.
	 *
	 * Each file registers its own add_action('rest_api_init', ...) callback.
	 * By including files here (not inside a rest_api_init callback) those
	 * inner callbacks are queued in time to fire when rest_api_init runs.
	 */
	public function load_rest_files(): void {
		// Free-tier routes: always loaded.
		$route_files = [
			CLIENTOCTOPUS_DIR . 'rest-api/entitlements.php',
			CLIENTOCTOPUS_DIR . 'rest-api/proposals.php',
			CLIENTOCTOPUS_DIR . 'rest-api/client-proposals.php',
			CLIENTOCTOPUS_DIR . 'rest-api/clients.php',
			CLIENTOCTOPUS_DIR . 'rest-api/onboarding.php',
			CLIENTOCTOPUS_DIR . 'rest-api/automations.php',
			CLIENTOCTOPUS_DIR . 'rest-api/invoices.php',
			CLIENTOCTOPUS_DIR . 'rest-api/recurring-profiles.php',
			CLIENTOCTOPUS_DIR . 'rest-api/leads.php',
		];

		// Premium-only routes: only loaded for paying users. This "if" block
		// is auto-removed from the free WP.org build by Freemius's deployment
		// processor.
		if ( clientoctopus_fs()->is__premium_only() ) {
			$route_files = array_merge( $route_files, [
				CLIENTOCTOPUS_DIR . 'rest-api/payments.php',
				CLIENTOCTOPUS_DIR . 'rest-api/portal.php',
				CLIENTOCTOPUS_DIR . 'rest-api/projects.php',
				CLIENTOCTOPUS_DIR . 'rest-api/files.php',
				CLIENTOCTOPUS_DIR . 'rest-api/approvals.php',
				CLIENTOCTOPUS_DIR . 'rest-api/messages.php',
				CLIENTOCTOPUS_DIR . 'rest-api/ai.php',
				CLIENTOCTOPUS_DIR . 'rest-api/analytics.php',
				CLIENTOCTOPUS_DIR . 'rest-api/team.php',
				CLIENTOCTOPUS_DIR . 'rest-api/webhooks.php',
				CLIENTOCTOPUS_DIR . 'rest-api/booking.php',
			] );
		}

		foreach ( $route_files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	/**
	 * Register the top-level admin menu and sub-pages.
	 */
	public function register_admin_menu(): void {
		$svg_raw  = file_get_contents( CLIENTOCTOPUS_DIR . 'assets/images/logo-icon.svg' );
		$svg_raw  = preg_replace( '/^<\?xml[^?]*\?>\s*/s', '', $svg_raw );
		$svg_raw  = preg_replace( '/<svg\b/', '<svg fill="#a7aaad" shape-rendering="geometricPrecision" width="20" height="20"', $svg_raw, 1 );
		$svg_icon = 'data:image/svg+xml;base64,' . base64_encode( $svg_raw );

		add_menu_page(
			__( 'Client Octopus', 'clientoctopus' ),
			__( 'Client Octopus', 'clientoctopus' ),
			'manage_clientoctopus',
			'clientoctopus',
			[ $this, 'render_plan_overview' ],
			$svg_icon,
			30
		);

		add_submenu_page(
			'clientoctopus',
			__( 'Plan & Usage', 'clientoctopus' ),
			__( 'Plan & Usage', 'clientoctopus' ),
			'manage_clientoctopus',
			'clientoctopus',
			[ $this, 'render_plan_overview' ]
		);

		// Menu order below follows the customer journey (Leads → Bookings →
		// Proposals → Clients → Projects → Invoices) rather than build order,
		// with less-frequent config/admin items (Analytics, Team, Webhooks,
		// Settings) grouped at the end. Each premium-only item keeps its own
		// individual is__premium_only() gate (rather than one shared block)
		// since they're no longer contiguous in this order.

		// Leads: available on all plans (free, pro, agency).
		add_submenu_page(
			'clientoctopus',
			__( 'Leads', 'clientoctopus' ),
			__( 'Leads', 'clientoctopus' ),
			'manage_clientoctopus',
			'clientoctopus-leads',
			[ $this, 'render_leads' ]
		);

		if ( clientoctopus_fs()->is__premium_only() ) {
			add_submenu_page(
				'clientoctopus',
				__( 'Bookings', 'clientoctopus' ),
				__( 'Bookings', 'clientoctopus' ),
				'manage_clientoctopus',
				'clientoctopus-bookings',
				[ $this, 'render_bookings__premium_only' ]
			);
		}

		add_submenu_page(
			'clientoctopus',
			__( 'Proposals', 'clientoctopus' ),
			__( 'Proposals', 'clientoctopus' ),
			'manage_clientoctopus',
			'clientoctopus-proposals',
			[ $this, 'render_proposals' ]
		);

		add_submenu_page(
			'clientoctopus',
			__( 'Clients', 'clientoctopus' ),
			__( 'Clients', 'clientoctopus' ),
			'manage_clientoctopus',
			'clientoctopus-clients',
			[ $this, 'render_clients' ]
		);

		if ( clientoctopus_fs()->is__premium_only() ) {
			// Build Projects menu title with unread message badge if applicable.
			$projects_menu_title = __( 'Projects', 'clientoctopus' );
			$msg_class_file      = CLIENTOCTOPUS_DIR . 'modules/messaging/class-message.php';
			if ( file_exists( $msg_class_file ) ) {
				if ( ! class_exists( 'ClientOctopus_Message' ) ) {
					require_once $msg_class_file;
				}
				$unread_msgs = ClientOctopus_Message::unread_count_admin( get_current_user_id() );
				if ( $unread_msgs > 0 ) {
					$projects_menu_title .= sprintf(
						' <span class="awaiting-mod count-%1$d"><span class="count">%1$d</span></span>',
						$unread_msgs
					);
				}
			}

			add_submenu_page(
				'clientoctopus',
				__( 'Projects', 'clientoctopus' ),
				$projects_menu_title,
				'manage_clientoctopus',
				'clientoctopus-projects',
				[ $this, 'render_projects__premium_only' ]
			);
		}

		// Invoices: available on all plans (free, pro, agency).
		add_submenu_page(
			'clientoctopus',
			__( 'Invoices', 'clientoctopus' ),
			__( 'Invoices', 'clientoctopus' ),
			'manage_clientoctopus',
			'clientoctopus-invoices',
			[ $this, 'render_invoices' ]
		);

		if ( clientoctopus_fs()->is__premium_only() ) {
			add_submenu_page(
				'clientoctopus',
				__( 'Analytics', 'clientoctopus' ),
				__( 'Analytics', 'clientoctopus' ),
				'manage_clientoctopus',
				'clientoctopus-analytics',
				[ $this, 'render_analytics__premium_only' ]
			);

			add_submenu_page(
				'clientoctopus',
				__( 'Team', 'clientoctopus' ),
				__( 'Team', 'clientoctopus' ),
				'manage_options',
				'clientoctopus-team',
				[ $this, 'render_team__premium_only' ]
			);

			add_submenu_page(
				'clientoctopus',
				__( 'Webhooks', 'clientoctopus' ),
				__( 'Webhooks', 'clientoctopus' ),
				'manage_clientoctopus',
				'clientoctopus-webhooks',
				[ $this, 'render_webhooks__premium_only' ]
			);
		}

		// Settings: owner-only — team members do not manage plugin settings.
		add_submenu_page(
			'clientoctopus',
			__( 'Settings', 'clientoctopus' ),
			__( 'Settings', 'clientoctopus' ),
			'manage_options',
			'clientoctopus-settings',
			[ $this, 'render_settings' ]
		);

		// Setup wizard — hidden from sidebar (null parent), accessible via redirect on activation.
		add_submenu_page(
			null,
			__( 'Setup', 'clientoctopus' ),
			__( 'Setup', 'clientoctopus' ),
			'manage_options',
			'clientoctopus-setup',
			[ $this, 'render_setup' ]
		);
	}

	/**
	 * Render the Plan & Usage admin page.
	 *
	 * Prepares variables and includes the view template.
	 */
	public function render_plan_overview(): void {
		$user_id   = get_current_user_id();
		$user_plan = ClientOctopus_Entitlements::get_user_plan( $user_id );

		$usage_data = [
			'proposals'        => ClientOctopus_Entitlements::get_total_count( $user_id, 'create_proposal' ),
			'proposals_limit'  => ClientOctopus_Entitlements::get_feature_limit( $user_id, 'create_proposal' ),
			'storage_mb'       => ClientOctopus_Entitlements::get_storage_used( $user_id ),
			'storage_limit_mb' => ClientOctopus_Entitlements::get_storage_limit( $user_id ),
			'team_seats'       => ClientOctopus_Entitlements::get_team_seats_used( $user_id ),
			'team_limit'       => ClientOctopus_Entitlements::get_team_limit( $user_id ),
		];

		$feature_access = [
			'create_proposal' => ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'create_proposal' ),
			'team_access'     => ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'team_access' ),
		];

		// This "if" block is auto-removed from the free WP.org build by
		// Freemius's deployment processor, so these keys are simply absent
		// from $feature_access there — admin/views/plan-overview.php's own
		// `?? false` default then correctly renders them as locked.
		if ( clientoctopus_fs()->is__premium_only() ) {
			$feature_access['use_payments']  = ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_payments' );
			$feature_access['use_portal']    = ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_portal' );
			$feature_access['use_projects']  = ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_projects' );
			$feature_access['use_messaging'] = ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_messaging' );
			$feature_access['use_files']     = ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_files' );

			$usage_data['ai_requests']    = ClientOctopus_Entitlements::get_monthly_usage( $user_id, 'use_ai' );
			$usage_data['ai_limit']       = ClientOctopus_Entitlements::get_feature_limit( $user_id, 'use_ai' );
			$feature_access['use_ai']     = ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_ai' );
		}

		require CLIENTOCTOPUS_DIR . 'admin/views/plan-overview.php';
	}

	/**
	 * Render the Proposals admin page.
	 *
	 * Outputs the React app mount point. All UI is handled by React.
	 */
	public function render_proposals(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/proposals.php';
	}

	public function render_clients(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/clients.php';
	}

	public function render_invoices(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/invoices.php';
	}

	/**
	 * Render the Leads admin page.
	 */
	public function render_leads(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/leads.php';
	}

	/**
	 * Render the Projects admin page. Name ends in __premium_only so this
	 * method (and the admin/views/projects.php it requires) is auto-removed
	 * from the free WP.org build by Freemius's deployment processor.
	 */
	public function render_projects__premium_only(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/projects.php';
	}

	/**
	 * Render the Analytics admin page. Name ends in __premium_only so this
	 * method (and the admin/views/analytics.php it requires) is auto-removed
	 * from the free WP.org build by Freemius's deployment processor.
	 */
	public function render_analytics__premium_only(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/analytics.php';
	}

	/**
	 * Render the Settings admin page.
	 */
	public function render_settings(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/settings.php';
	}

	/**
	 * Render the Team management page. Name ends in __premium_only so this
	 * method (and the admin/views/team.php it requires) is auto-removed from
	 * the free WP.org build by Freemius's deployment processor.
	 */
	public function render_team__premium_only(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/team.php';
	}

	/**
	 * Render the Webhooks management page. Name ends in __premium_only so
	 * this method (and the admin/views/webhooks.php it requires) is
	 * auto-removed from the free WP.org build by Freemius's deployment
	 * processor.
	 */
	public function render_webhooks__premium_only(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/webhooks.php';
	}

	/**
	 * Render the Bookings admin page.
	 */
	public function render_bookings__premium_only(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/bookings.php';
	}

	/**
	 * Render the Setup wizard page.
	 */
	public function render_setup(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/setup.php';
	}

	/**
	 * Enqueue admin scripts and styles on Client Octopus pages.
	 *
	 * Loads the compiled React app (build/index.js + build/index.css) only on
	 * the Proposals admin page. Provides window.clientoctopusData for React ↔ PHP comms.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'clientoctopus' ) ) {
			return;
		}

		$build_dir = CLIENTOCTOPUS_DIR . 'build/';
		$build_url = CLIENTOCTOPUS_URL . 'build/';
		$admin_css = CLIENTOCTOPUS_URL . 'admin/css/';
		$admin_js  = CLIENTOCTOPUS_URL . 'admin/js/';

		// Self-hosted fonts — no Google Fonts CDN calls. CLIENTOCTOPUS_URL resolves
		// to plugin_dir_url(), so this is a same-origin asset; Plugin Check's
		// Offloading sniff flags it on the "fonts" path alone, a false positive.
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
		wp_enqueue_style( 'co-admin-fonts', CLIENTOCTOPUS_URL . 'assets/fonts/admin-fonts.css', [], CLIENTOCTOPUS_VERSION );

		// Shared spinner + React mount styles for all Client Octopus admin pages.
		wp_enqueue_style( 'co-admin-views', $admin_css . 'admin-react-views.css', [], CLIENTOCTOPUS_VERSION );
		$user_id   = clientoctopus_get_owner_id( get_current_user_id() );
		$plan      = ClientOctopus_Entitlements::get_user_plan( $user_id );

		$runtime_data = [
			'apiUrl'             => rest_url( 'clientoctopus/v1/' ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'adminUrl'           => admin_url(),
			'userPlan'           => $plan,
			'planLimits'         => [
				'proposals' => ClientOctopus_Entitlements::get_feature_limit( $user_id, 'create_proposal' ),
			],
			'proposalUsage'      => ClientOctopus_Entitlements::get_monthly_usage( $user_id, 'create_proposal' ),
			'proposalNextReset'  => gmdate( 'j F', strtotime( 'first day of next month' ) ),
			'onboardingComplete' => (bool) get_option( 'clientoctopus_onboarding_complete' ),
			'featureAccess'      => [
				'create_proposal' => clientoctopus_can_user( $user_id, 'create_proposal' ),
				'use_payments'    => clientoctopus_can_user( $user_id, 'use_payments' ),
				'use_portal'      => clientoctopus_can_user( $user_id, 'use_portal' ),
				'use_projects'    => clientoctopus_can_user( $user_id, 'use_projects' ),
				'use_messaging'   => clientoctopus_can_user( $user_id, 'use_messaging' ),
				'use_files'       => clientoctopus_can_user( $user_id, 'use_files' ),
				'team_access'     => clientoctopus_can_user( $user_id, 'team_access' ),
				'use_webhooks'    => clientoctopus_can_user( $user_id, 'use_webhooks' ),
				'use_invoices'    => clientoctopus_can_user( $user_id, 'use_invoices' ),
			],
			'teamSeats'            => ClientOctopus_Entitlements::get_team_seats_used( $user_id ),
			'teamLimit'            => ClientOctopus_Entitlements::get_team_limit( $user_id ),
			'senderEmailConfigured' => ! empty( get_option( 'clientoctopus_from_email', '' ) ),
			'paymentProvider'       => 'paypal' === get_option( 'clientoctopus_payment_provider', 'stripe' ) ? 'paypal' : 'stripe',
			'homeUrl'               => home_url(),
		];

		//@fs_premium_only
		$runtime_data['planLimits']['ai']        = ClientOctopus_Entitlements::get_feature_limit( $user_id, 'use_ai' );
		$runtime_data['featureAccess']['use_ai'] = clientoctopus_can_user( $user_id, 'use_ai' );
		//@end:fs_premium_only

		if ( str_contains( $hook, 'clientoctopus-proposals' ) ) {
			$asset_file = $build_dir . 'index.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'index.css' ) ) {
				wp_enqueue_style( 'co-admin', $build_url . 'index.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-admin', $build_url . 'index.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-admin', 'clientoctopusData', $runtime_data );

		//@fs_premium_only
		} elseif ( str_contains( $hook, 'clientoctopus-projects' ) ) {
			$asset_file = $build_dir . 'projects.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'projects.css' ) ) {
				wp_enqueue_style( 'co-projects', $build_url . 'projects.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-projects', $build_url . 'projects.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-projects', 'clientoctopusData', $runtime_data );

		} elseif ( str_contains( $hook, 'clientoctopus-analytics' ) ) {
			$asset_file = $build_dir . 'analytics.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'analytics.css' ) ) {
				wp_enqueue_style( 'co-analytics', $build_url . 'analytics.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-analytics', $build_url . 'analytics.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-analytics', 'clientoctopusData', $runtime_data );

		} elseif ( str_contains( $hook, 'clientoctopus-bookings' ) ) {
			$asset_file = $build_dir . 'booking.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'booking.css' ) ) {
				wp_enqueue_style( 'co-booking-admin', $build_url . 'booking.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-booking-admin', $build_url . 'booking.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-booking-admin', 'clientoctopusData', $runtime_data );
		//@end:fs_premium_only

		} elseif ( str_contains( $hook, 'clientoctopus-clients' ) ) {
			$asset_file = $build_dir . 'clients.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'clients.css' ) ) {
				wp_enqueue_style( 'co-clients', $build_url . 'clients.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-clients', $build_url . 'clients.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-clients', 'clientoctopusData', $runtime_data );

		} elseif ( str_contains( $hook, 'clientoctopus-invoices' ) ) {
			$asset_file = $build_dir . 'invoices.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'invoices.css' ) ) {
				wp_enqueue_style( 'co-invoices', $build_url . 'invoices.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-invoices', $build_url . 'invoices.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-invoices', 'clientoctopusData', $runtime_data );

		} elseif ( str_contains( $hook, 'clientoctopus-leads' ) ) {
			$asset_file = $build_dir . 'leads.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'leads.css' ) ) {
				wp_enqueue_style( 'co-leads', $build_url . 'leads.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-leads', $build_url . 'leads.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-leads', 'clientoctopusData', $runtime_data );

		} elseif ( str_contains( $hook, 'clientoctopus-setup' ) ) {
			$asset_file = $build_dir . 'setup.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'setup.css' ) ) {
				wp_enqueue_style( 'co-setup', $build_url . 'setup.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-setup', $build_url . 'setup.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-setup', 'clientoctopusData', $runtime_data );

		//@fs_premium_only
		} elseif ( str_contains( $hook, 'clientoctopus-team' ) ) {
			$asset_file = $build_dir . 'team.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'team.css' ) ) {
				wp_enqueue_style( 'co-team', $build_url . 'team.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-team', $build_url . 'team.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-team', 'clientoctopusData', $runtime_data );

		} elseif ( str_contains( $hook, 'clientoctopus-webhooks' ) ) {
			$asset_file = $build_dir . 'webhooks.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'webhooks.css' ) ) {
				wp_enqueue_style( 'co-webhooks', $build_url . 'webhooks.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-webhooks', $build_url . 'webhooks.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-webhooks', 'clientoctopusData', $runtime_data );
		//@end:fs_premium_only

		} elseif ( str_contains( $hook, 'clientoctopus-settings' ) ) {
			wp_enqueue_media();
			wp_enqueue_style( 'co-settings', $admin_css . 'settings.css', [], CLIENTOCTOPUS_VERSION );
			wp_enqueue_script( 'co-settings', $admin_js . 'settings.js', [], CLIENTOCTOPUS_VERSION, true );

		} else {
			// Plan & Usage overview (top-level page, slug: clientoctopus).
			wp_enqueue_style( 'co-plan-overview', $admin_css . 'plan-overview.css', [], CLIENTOCTOPUS_VERSION );
			wp_enqueue_script( 'co-plan-overview', $admin_js . 'plan-overview.js', [], CLIENTOCTOPUS_VERSION, true );
		}
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Activation / Deactivation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Plugin activation callback.
 *
 * Creates all database tables, stores version options, and schedules cron.
 */
function clientoctopus_activate(): void {
	require_once CLIENTOCTOPUS_DIR . 'database/schema.php';

	// Skip the ~40-query dbDelta/migration replay when the schema is already
	// current (e.g. deactivate/reactivate with no version change) — mirrors
	// the same version-gate used for the admin_init migration check below.
	if ( (string) get_option( 'clientoctopus_db_version', '0' ) !== CLIENTOCTOPUS_DB_VERSION ) {
		clientoctopus_create_tables();
		update_option( 'clientoctopus_db_version', CLIENTOCTOPUS_DB_VERSION );
	}

	add_option( 'clientoctopus_version',        CLIENTOCTOPUS_VERSION );
	add_option( 'clientoctopus_owner_user_id',  get_current_user_id() );

	if ( ! wp_next_scheduled( 'clientoctopus_monthly_reset' ) ) {
		wp_schedule_event(
			(int) strtotime( 'first day of next month midnight' ),
			'monthly',
			'clientoctopus_monthly_reset'
		);
	}

	// Register the clientoctopus_member role for Agency team members.
	// manage_clientoctopus is a custom capability checked by the plugin's admin menu pages.
	add_role(
		'clientoctopus_member',
		__( 'Client Octopus Team Member', 'clientoctopus' ),
		[ 'read' => true, 'manage_clientoctopus' => true ]
	);

	// Grant the same custom capability to administrators so they still pass
	// the manage_clientoctopus check used by the Client Octopus admin menu pages.
	$admin_role = get_role( 'administrator' );
	if ( $admin_role ) {
		$admin_role->add_cap( 'manage_clientoctopus' );
	}

	// Register proposal rewrite rules before flushing.
	add_rewrite_tag( '%clientoctopus_proposal_token%', '([a-zA-Z0-9\-]+)' );
	add_rewrite_tag( '%clientoctopus_payment_result%', '(success|cancel)' );
	add_rewrite_tag( '%clientoctopus_preview_token%',  '([a-zA-Z0-9\-]+)' );
	add_rewrite_rule(
		'^proposals/preview/([a-zA-Z0-9\-]+)/?$',
		'index.php?clientoctopus_preview_token=$matches[1]',
		'top'
	);
	add_rewrite_rule(
		'^proposals/([a-zA-Z0-9\-]+)/success/?$',
		'index.php?clientoctopus_proposal_token=$matches[1]&clientoctopus_payment_result=success',
		'top'
	);
	add_rewrite_rule(
		'^proposals/([a-zA-Z0-9\-]+)/cancel/?$',
		'index.php?clientoctopus_proposal_token=$matches[1]&clientoctopus_payment_result=cancel',
		'top'
	);
	add_rewrite_rule(
		'^proposals/([a-zA-Z0-9\-]+)/?$',
		'index.php?clientoctopus_proposal_token=$matches[1]',
		'top'
	);

	// Register portal rewrite rules before flushing.
	add_rewrite_tag( '%clientoctopus_portal_page%', '([a-z]+)' );
	foreach ( [ 'login', 'verify', 'dashboard', 'proposals', 'payments', 'projects' ] as $portal_page ) {
		add_rewrite_rule(
			"^clientoctopus/{$portal_page}/?$",
			"index.php?clientoctopus_portal_page={$portal_page}",
			'top'
		);
	}
	add_rewrite_rule( '^clientoctopus/?$', 'index.php?clientoctopus_portal_page=login', 'top' );

	flush_rewrite_rules();

	// Queue redirect to setup wizard on first admin load after activation.
	// Uses a persistent option (not a transient) so it survives slow page loads,
	// and the redirect is gated on Freemius opt-in completion so Freemius can
	// display its opt-in banner on the first admin page load before we redirect.
	if ( ! get_option( 'clientoctopus_onboarding_complete' ) ) {
		update_option( 'clientoctopus_show_setup_wizard', '1', false );
	}
}

/**
 * Plugin deactivation callback.
 *
 * Clears scheduled cron jobs. Does NOT drop database tables.
 */
function clientoctopus_deactivate(): void {
	wp_clear_scheduled_hook( 'clientoctopus_monthly_reset' );
	wp_clear_scheduled_hook( 'clientoctopus_sync_pending_payments' );
	flush_rewrite_rules();
}

/**
 * Cron callback: sync all pending/processing Stripe payments.
 *
 * Runs every 15 minutes as a webhook fallback. Skips payments created within
 * the last 5 minutes to avoid racing a Stripe webhook that may still be in flight.
 */
function clientoctopus_cron_sync_pending_payments(): void {
	if ( ! function_exists( 'clientoctopus_handle_checkout_complete' ) ) {
		$path = CLIENTOCTOPUS_DIR . 'rest-api/payments.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	if ( ! class_exists( 'ClientOctopus_Stripe' ) || ! ClientOctopus_Stripe::is_configured() ) {
		return;
	}

	global $wpdb;

	$cutoff = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cron callback, caching not appropriate.
		$wpdb->prepare(
			"SELECT id, stripe_session_id FROM {$wpdb->prefix}clientoctopus_payments
			 WHERE status IN ('pending','processing')
			   AND stripe_session_id IS NOT NULL
			   AND stripe_session_id != ''
			   AND created_at < %s",
			$cutoff
		),
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		return;
	}

	foreach ( $rows as $row ) {
		$session = ClientOctopus_Stripe::retrieve_session( $row['stripe_session_id'] );
		if ( ! is_wp_error( $session ) && 'paid' === ( $session['payment_status'] ?? '' ) ) {
			clientoctopus_handle_checkout_complete( $session );
		}
	}
}

register_activation_hook( __FILE__,   'clientoctopus_activate' );
register_deactivation_hook( __FILE__, 'clientoctopus_deactivate' );

// ─────────────────────────────────────────────────────────────────────────────
// Initialise
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', static function (): void {
	ClientOctopus::instance();
} );

// ── Privacy policy content ────────────────────────────────────────────────────

add_action( 'admin_init', static function (): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	// Required GDPR/privacy disclosure text — names external services (Freemius,
	// Stripe, Cloudflare, clientoctopus.com) the plugin talks to. Not a resource
	// load; Plugin Check's Offloading sniff false-positives on the domain mentions.
	// phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent
	wp_add_privacy_policy_content(
		'Client Octopus',
		wp_kses_post(
			'<h2>What data this plugin collects</h2>
			<p>Client Octopus stores the following data in your WordPress database:</p>
			<ul>
				<li>Client contact details (name, email address, company name, phone number) entered by the site owner</li>
				<li>Proposal content, status history, and timestamps</li>
				<li>Payment records (amount, currency, date — no card data is stored by this plugin)</li>
				<li>Client portal login tokens (temporary, expire after 24 hours)</li>
				<li>Lead capture form submissions (name, email, and any other fields the site owner has enabled) submitted by visitors via the [clientoctopus_lead_form] shortcode, along with the submitter\'s IP address (used only for spam-prevention rate limiting)</li>
			</ul>
			<h2>External services</h2>
			<p>When the AI writing assistant is used (Pro/Agency plans only), the text prompt and your licence key are sent to the Client Octopus relay server for processing. No site URL or admin email is transmitted to the AI relay.</p>
			<p>A daily licence check transmits your licence key and account email address to clientoctopus.com to verify plan status.</p>
			<p>Licence management is handled by Freemius. When a licence is activated, your site URL, plugin version, and licence key are sent to Freemius. See <a href="https://freemius.com/privacy/">Freemius Privacy Policy</a>.</p>
			<p>Payment processing is handled by Stripe. No card details pass through or are stored by this plugin. See <a href="https://stripe.com/privacy">Stripe Privacy Policy</a>.</p>
			<p>If Cloudflare Turnstile is enabled for the lead capture form, submitted form data is verified with Cloudflare before being saved. See <a href="https://www.cloudflare.com/privacypolicy/">Cloudflare Privacy Policy</a>.</p>
			<p>Lead capture submissions can be exported or erased via Tools &rarr; Export Personal Data / Erase Personal Data, looked up by the email address the visitor submitted.</p>
			<p>Client records can also be exported or erased via Tools &rarr; Export Personal Data / Erase Personal Data, looked up by the client\'s email address.</p>'
		)
	);
	// phpcs:enable PluginCheck.CodeAnalysis.Offloading.OffloadedContent
} );

} // end Freemius free/paid guard
