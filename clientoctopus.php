<?php
/**
 * Plugin Name: Client Octopus
 * Plugin URI:  https://clientoctopus.com
 * Description: All-in-one client workflow management for WordPress — proposals, payments, projects, and client portals.
 * Version:     0.1.2
 * Author:      codievolt
 * Author URI:  https://codievolt.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clientoctopus
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
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
		$license = clientoctopus_fs()->_get_license();
		if ( $license && ! empty( $license->secret_key ) ) {
			update_option( 'clientoctopus_license_key', $license->secret_key );
		}
		$plan = strtolower( (string) clientoctopus_fs()->get_plan_name() );
		if ( in_array( $plan, [ 'pro', 'agency' ], true ) ) {
			ClientOctopus_Entitlements::set_user_plan( get_current_user_id(), $plan );
		}
		if ( $license && ! empty( $license->secret_key ) ) {
			clientoctopus_push_license_to_relay( $license->secret_key, $plan );
		}
	} );

	clientoctopus_fs()->add_action( 'after_license_deactivation', static function (): void {
		update_option( 'clientoctopus_license_key', '' );
		ClientOctopus_Entitlements::set_user_plan( get_current_user_id(), 'free' );
	} );

	clientoctopus_fs()->add_action( 'after_license_change', static function ( $_plan_change, $plan ): void {
		$plan_name = strtolower( is_object( $plan ) ? (string) $plan->name : '' );
		if ( in_array( $plan_name, [ 'pro', 'agency' ], true ) ) {
			ClientOctopus_Entitlements::set_user_plan( get_current_user_id(), $plan_name );
		}
	}, 10, 2 );

	clientoctopus_fs()->add_action( 'after_uninstall', static function (): void {
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
		];
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are hardcoded strings, not user input.
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
			clientoctopus_push_license_to_relay( $license->secret_key, $plan_name );
			set_transient( 'clientoctopus_relay_sync', 1, DAY_IN_SECONDS );
		}
	} );
}

/**
 * Register the current Freemius license with the relay server.
 * Fire-and-forget: failures are logged but never block the user.
 */
function clientoctopus_push_license_to_relay( string $license_key, string $plan ): void {
	$relay_url = untrailingslashit( CLIENTOCTOPUS_AI_RELAY_URL );

	wp_remote_post(
		$relay_url . '/wp-json/co-relay/v1/register-license',
		[
			'timeout'  => 8,
			'blocking' => false,
			'headers'  => [ 'Content-Type' => 'application/json' ],
			'body'     => wp_json_encode( [
				'license_key' => $license_key,
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

define( 'CLIENTOCTOPUS_VERSION',        '0.1.2' );
define( 'CLIENTOCTOPUS_DB_VERSION',     '12' );
define( 'CLIENTOCTOPUS_REWRITE_VERSION', '3' );
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
		str_ends_with( strtolower( parse_url( $logo_url, PHP_URL_PATH ) ?? '' ), '.svg' )
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
              <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-supplied HTML
echo $body;
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
            </td>
          </tr>
          <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/esc_url above
echo $cta_html;
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
          <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built from caller-supplied HTML
echo $footer_html;
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
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
 * Simple transient-based rate limiter for REST endpoints.
 *
 * Returns true if the request is allowed, false if the caller has exceeded
 * $limit actions within the current $window-second period.
 *
 * @param string $action  Unique action slug (e.g. 'send_proposal').
 * @param int    $user_id WordPress user ID.
 * @param int    $limit   Maximum allowed calls per window.
 * @param int    $window  Window length in seconds (default 60).
 * @return bool True = allowed, false = rate limited.
 */
function clientoctopus_rest_rate_limit( string $action, int $user_id, int $limit = 60, int $window = 60 ): bool {
	$key   = 'clientoctopus_rl_' . md5( $action . '_' . $user_id );
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

		//@fs_premium_only
		if ( clientoctopus_fs()->is_premium() ) {
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
		}
		//@end:fs_premium_only

		// Load client-facing proposal routing (rewrite rules + template_redirect).
		// Available on all plans — clients can view and sign proposals on free tier.
		$routing = CLIENTOCTOPUS_DIR . 'modules/proposals/client-routing.php';
		if ( file_exists( $routing ) ) {
			require_once $routing;
		}

		// Block WP admin access for clientoctopus_client role.
		add_action( 'admin_init', static function (): void {
			if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
				if ( ClientOctopus_Portal_Auth::is_authenticated() ) {
					wp_safe_redirect( home_url( '/clientoctopus/dashboard' ) );
					exit;
				}
			}
		} );

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
		add_filter( 'show_admin_bar', static function ( bool $show ): bool {
			if ( ClientOctopus_Portal_Auth::is_authenticated() ) {
				return false;
			}
			return $show;
		} );

		// After WP login, send clients to the portal dashboard instead of WP admin.
		add_filter( 'login_redirect', static function ( string $redirect_to, string $_requested_redirect_to, $user ): string {
			if ( $user instanceof WP_User && in_array( 'clientoctopus_client', (array) $user->roles, true ) ) {
				return home_url( '/clientoctopus/dashboard' );
			}
			return $redirect_to;
		}, 10, 3 );

		// Hook: when a proposal is sent, provision/update the client's portal account.
		add_action( 'clientoctopus_proposal_sent', static function ( int $proposal_id, int $_owner_id ): void {
			global $wpdb;
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT c.email AS client_email, c.name AS client_name
					 FROM {$wpdb->prefix}clientoctopus_proposals p
					 JOIN {$wpdb->prefix}clientoctopus_clients c ON c.id = p.client_id
					 WHERE p.id = %d",
					$proposal_id
				),
				ARRAY_A
			);
			if ( $row && ! empty( $row['client_email'] ) ) {
				ClientOctopus_Portal_Auth::get_or_create_wp_user(
					$row['client_email'],
					$row['client_name'] ?? null
				);
			}
		}, 10, 2 );

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

		// Hook: send portal invitation email when a proposal is accepted.
		// On Free plan: create the WP account silently but skip the email —
		// the owner can manually invite from the Clients page once they upgrade.
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
			// Always create the WP account so it is ready if the owner upgrades.
			$user = ClientOctopus_Portal_Auth::get_or_create_wp_user(
				$row['client_email'],
				$row['client_name'] ?? null
			);
			if ( is_wp_error( $user ) ) {
				return;
			}
			// Only send the invite email when the owner has portal access.
			if ( ! clientoctopus_can_user( $owner_id, 'use_portal' ) ) {
				return;
			}
			$raw_token = ClientOctopus_Portal_Auth::generate_magic_token( $user->ID );
			ClientOctopus_Portal_Auth::send_magic_link_email( $user, $raw_token );
			$wpdb->update(
				$wpdb->prefix . 'clientoctopus_clients',
				[ 'portal_invited_at' => current_time( 'mysql' ) ],
				[ 'id' => (int) $row['client_id'] ]
			);
		}, 20, 2 );

		// When a WP admin changes a client user's password via the admin UI,
		// automatically mark the portal password as set so password login works.
		// Compares the old and new password hashes — if they differ, the password
		// was changed and the client should be able to log in with the new one.
		add_action( 'profile_update', static function ( int $user_id, WP_User $old_user ): void {
			$user = get_user_by( 'ID', $user_id );
			if ( ! $user || ! in_array( 'clientoctopus_client', (array) $user->roles, true ) ) {
				return;
			}
			if ( $user->user_pass !== $old_user->user_pass ) {
				ClientOctopus_Portal_Auth::mark_password_set( $user_id );
			}
		}, 10, 2 );

		// ── Outbound webhook dispatch ─────────────────────────────────────────
		//@fs_premium_only
		add_action( 'clientoctopus_proposal_sent', static function ( int $proposal_id, int $owner_id ): void {
			if ( ! function_exists( 'clientoctopus_webhook_dispatch' ) ) return;
			$proposal = ClientOctopus_Proposal::get( $proposal_id, $owner_id );
			if ( is_wp_error( $proposal ) ) return;
			clientoctopus_webhook_dispatch( 'proposal.sent', $owner_id, [
				'proposal_id' => $proposal_id,
				'title'       => $proposal['title'] ?? '',
				'total'       => $proposal['total_amount'] ?? null,
				'currency'    => $proposal['currency'] ?? 'GBP',
				'status'      => $proposal['status'] ?? '',
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
		//@end:fs_premium_only

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
		];

		// Premium-only routes: only loaded for paying users.
		//@fs_premium_only
		if ( clientoctopus_fs()->is_premium() ) {
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
			] );
		}
		//@end:fs_premium_only

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

		//@fs_premium_only
		if ( clientoctopus_fs()->is_premium() ) {
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
				[ $this, 'render_projects' ]
			);

			add_submenu_page(
				'clientoctopus',
				__( 'Analytics', 'clientoctopus' ),
				__( 'Analytics', 'clientoctopus' ),
				'manage_clientoctopus',
				'clientoctopus-analytics',
				[ $this, 'render_analytics' ]
			);

			add_submenu_page(
				'clientoctopus',
				__( 'Team', 'clientoctopus' ),
				__( 'Team', 'clientoctopus' ),
				'manage_options',
				'clientoctopus-team',
				[ $this, 'render_team' ]
			);

			add_submenu_page(
				'clientoctopus',
				__( 'Webhooks', 'clientoctopus' ),
				__( 'Webhooks', 'clientoctopus' ),
				'manage_clientoctopus',
				'clientoctopus-webhooks',
				[ $this, 'render_webhooks' ]
			);
		}
		//@end:fs_premium_only

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
			'storage_limit_mb' => 'agency' === $user_plan ? 1000 : 0,
			'team_seats'       => ClientOctopus_Entitlements::get_team_seats_used( $user_id ),
			'team_limit'       => ClientOctopus_Entitlements::get_team_limit( $user_id ),
		];

		$feature_access = [
			'create_proposal' => ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'create_proposal' ),
			'use_payments'    => ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_payments' ),
			'use_portal'      => ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_portal' ),
			'use_projects'    => ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_projects' ),
			'use_messaging'   => ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_messaging' ),
			'use_files'       => ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_files' ),
			'team_access'     => ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'team_access' ),
		];

		//@fs_premium_only
		$usage_data['ai_requests']    = ClientOctopus_Entitlements::get_monthly_usage( $user_id, 'use_ai' );
		$usage_data['ai_limit']       = ClientOctopus_Entitlements::get_feature_limit( $user_id, 'use_ai' );
		$feature_access['use_ai']     = ClientOctopus_Entitlements::plan_includes_feature( $user_id, 'use_ai' );
		//@end:fs_premium_only

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

	/**
	 * Render the Projects admin page.
	 */
	public function render_projects(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/projects.php';
	}

	/**
	 * Render the Analytics admin page.
	 */
	public function render_analytics(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/analytics.php';
	}

	/**
	 * Render the Settings admin page.
	 */
	public function render_settings(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/settings.php';
	}

	/**
	 * Render the Team management page.
	 */
	public function render_team(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/team.php';
	}

	/**
	 * Render the Webhooks management page.
	 */
	public function render_webhooks(): void {
		require CLIENTOCTOPUS_DIR . 'admin/views/webhooks.php';
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
	 * the Proposals admin page. Provides window.coData for React ↔ PHP comms.
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

		// Self-hosted fonts — no Google Fonts CDN calls.
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
			],
			'teamSeats'            => ClientOctopus_Entitlements::get_team_seats_used( $user_id ),
			'teamLimit'            => ClientOctopus_Entitlements::get_team_limit( $user_id ),
			'senderEmailConfigured' => ! empty( get_option( 'clientoctopus_from_email', '' ) ),
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
			wp_localize_script( 'co-admin', 'coData', $runtime_data );

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
			wp_localize_script( 'co-projects', 'coData', $runtime_data );

		} elseif ( str_contains( $hook, 'clientoctopus-analytics' ) ) {
			$asset_file = $build_dir . 'analytics.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'analytics.css' ) ) {
				wp_enqueue_style( 'co-analytics', $build_url . 'analytics.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-analytics', $build_url . 'analytics.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-analytics', 'coData', $runtime_data );
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
			wp_localize_script( 'co-clients', 'coData', $runtime_data );

		} elseif ( str_contains( $hook, 'clientoctopus-setup' ) ) {
			$asset_file = $build_dir . 'setup.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'setup.css' ) ) {
				wp_enqueue_style( 'co-setup', $build_url . 'setup.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-setup', $build_url . 'setup.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-setup', 'coData', $runtime_data );

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
			wp_localize_script( 'co-team', 'coData', $runtime_data );

		} elseif ( str_contains( $hook, 'clientoctopus-webhooks' ) ) {
			$asset_file = $build_dir . 'webhooks.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: [ 'dependencies' => [ 'wp-element', 'wp-i18n' ], 'version' => CLIENTOCTOPUS_VERSION ];

			if ( file_exists( $build_dir . 'webhooks.css' ) ) {
				wp_enqueue_style( 'co-webhooks', $build_url . 'webhooks.css', [], $asset['version'] );
			}

			wp_enqueue_script( 'co-webhooks', $build_url . 'webhooks.js', $asset['dependencies'], $asset['version'], true );
			wp_localize_script( 'co-webhooks', 'coData', $runtime_data );
		//@end:fs_premium_only

		} elseif ( str_contains( $hook, 'clientoctopus-settings' ) ) {
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
	clientoctopus_create_tables();

	add_option( 'clientoctopus_version',    CLIENTOCTOPUS_VERSION );
	add_option( 'clientoctopus_db_version', CLIENTOCTOPUS_DB_VERSION );

	if ( ! wp_next_scheduled( 'clientoctopus_monthly_reset' ) ) {
		wp_schedule_event(
			(int) strtotime( 'first day of next month midnight' ),
			'monthly',
			'clientoctopus_monthly_reset'
		);
	}

	// Register the clientoctopus_client role for portal users.
	add_role(
		'clientoctopus_client',
		__( 'Client Octopus Client', 'clientoctopus' ),
		[ 'read' => true ]
	);

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
	flush_rewrite_rules();
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
			</ul>
			<h2>External services</h2>
			<p>When the AI writing assistant is used (Pro/Agency plans only), the text prompt and your site URL are sent to the Client Octopus relay server for processing. See the plugin readme for full details and links to the privacy policy of each third-party service.</p>
			<p>Payment processing is handled by Stripe. No card details pass through or are stored by this plugin. See <a href="https://stripe.com/privacy">Stripe\'s Privacy Policy</a>.</p>'
		)
	);
} );

} // end Freemius free/paid guard
