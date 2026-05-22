<?php
/**
 * Client Octopus — Plan & Usage Admin View
 *
 * Rendered inside WordPress #wpcontent.
 * Styles and JS are enqueued via admin/css/plan-overview.css and admin/js/plan-overview.js.
 *
 * Expected variables (provided by ClientOctopus::render_plan_overview()):
 *   @var string $user_plan       'free' | 'pro' | 'agency'
 *   @var array  $usage_data      Keys: ai_requests, ai_limit, proposals,
 *                                proposals_limit, storage_mb, storage_limit_mb,
 *                                team_seats, team_limit
 *   @var array  $feature_access  Keys map features to bool|string
 *
 * @package ClientOctopus
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-scope variables; this file is only included from the admin page callback and never in global scope.

// ─── Safe defaults ───────────────────────────────────────────────────────────
$user_plan    = $user_plan    ?? 'free';
$usage_data   = $usage_data   ?? [];
$feature_access = $feature_access ?? [];

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Return percentage (0–100) and a status class.
 *
 * @param int|null $used
 * @param int|null $limit
 *
 * @return array{pct: int, status: string}
 */
function clientoctopus_progress( ?int $used, ?int $limit ): array {
	if ( null === $limit || 0 === $limit ) {
		return [ 'pct' => 0, 'status' => 'unlimited' ];
	}

	$pct = (int) min( 100, round( ( $used / $limit ) * 100 ) );

	$status = 'ok';
	if ( $pct >= 95 ) $status = 'danger';
	elseif ( $pct >= 80 ) $status = 'warn';

	return [ 'pct' => $pct, 'status' => $status ];
}

$plan_labels = [
	'free'   => 'Free',
	'pro'    => 'Pro',
	'agency' => 'Agency',
];

$plan_label = $plan_labels[ $user_plan ] ?? 'Free';
$is_agency  = 'agency' === $user_plan;
$is_pro_or_above = in_array( $user_plan, [ 'pro', 'agency' ], true );

// Usage progress.
$ai_prog       = clientoctopus_progress( $usage_data['ai_requests'] ?? 0,  $usage_data['ai_limit'] ?? null );
$prop_prog     = clientoctopus_progress( $usage_data['proposals'] ?? 0,    $usage_data['proposals_limit'] ?? null );
$storage_prog  = clientoctopus_progress( $usage_data['storage_mb'] ?? 0,   $usage_data['storage_limit_mb'] ?? null );
$team_prog     = clientoctopus_progress( $usage_data['team_seats'] ?? 1,   $usage_data['team_limit'] ?? 1 );

// Feature grid definition.
$features = [
	[
		'key'     => 'create_proposal',
		'label'   => 'Proposals',
		'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
		'note'    => 'Unlimited',
		'gate'    => 'all',
	],
	[
		'key'     => 'use_payments',
		'label'   => 'Payments',
		'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
		'note'    => $is_pro_or_above ? 'Stripe enabled' : 'Pro required',
		'gate'    => 'pro',
	],
	[
		'key'     => 'use_portal',
		'label'   => 'Client Portal',
		'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		'note'    => 'agency' === $user_plan ? 'Full access' : ( 'pro' === $user_plan ? 'View-only' : 'Pro required' ),
		'gate'    => 'pro',
	],
	[
		'key'     => 'use_projects',
		'label'   => 'Projects',
		'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
		'note'    => $is_agency ? 'Active' : 'Agency required',
		'gate'    => 'agency',
	],
	[
		'key'     => 'use_messaging',
		'label'   => 'Messaging',
		'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
		'note'    => $is_agency ? 'Active' : 'Agency required',
		'gate'    => 'agency',
	],
	[
		'key'     => 'use_files',
		'label'   => 'File Sharing',
		'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>',
		'note'    => $is_agency ? '1 GB storage' : 'Agency required',
		'gate'    => 'agency',
	],
	[
		'key'     => 'use_ai',
		'label'   => 'AI Assist',
		'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
		'note'    => 'agency' === $user_plan ? '500 req/mo' : ( 'pro' === $user_plan ? '100 req/mo' : 'Pro required' ),
		'gate'    => 'pro',
	],
	[
		'key'     => 'team_access',
		'label'   => 'Team Members',
		'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
		'note'    => $is_agency ? 'Up to 5 seats' : '1 user only',
		'gate'    => 'agency',
	],
];

// Upgrade CTA target — use Freemius pricing page.
$upgrade_url = function_exists( 'clientoctopus_fs' ) ? clientoctopus_fs()->get_upgrade_url() : 'https://clientoctopus.com/pricing';
?>

<div id="co-admin-wrap">

    <?php /* ── Top Header ────────────────────────────────────────────────── */ ?>
    <div class="co-header co-animate">
        <div class="co-brand">
            <h1 class="co-brand-name">Plan &amp; Usage</h1>
            <p class="co-brand-tagline">Your plan, usage limits and feature access</p>
        </div>
        <div class="co-header-right">
            <span class="co-plan-badge <?php echo esc_attr( $user_plan ); ?>">
                <?php echo esc_html( $plan_label ); ?> Plan
            </span>
            <?php if ( ! $is_agency ) : ?>
                <a href="<?php echo esc_url( $upgrade_url ); ?>" class="co-btn-upgrade">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="17 11 12 6 7 11"/><line x1="12" y1="6" x2="12" y2="18"/>
                    </svg>
                    Upgrade Plan
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php /* ── Row 1: Plan Card + Feature Grid ──────────────────────────── */ ?>
    <div class="co-grid-2 co-animate co-animate-1">

        <?php /* Plan Overview Card */ ?>
        <div class="co-card co-plan-card">
            <div class="co-card-title" style="color:rgba(255,255,255,.4);">Your Plan</div>
            <div class="co-plan-name"><?php echo esc_html( $plan_label ); ?></div>
            <div class="co-plan-sub">
                <?php if ( 'free' === $user_plan ) : ?>
                    Upgrade to Pro to unlock AI assistance, payments, client portal & testimonials.
                <?php elseif ( 'pro' === $user_plan ) : ?>
                    Upgrade to Agency to unlock projects, messaging, file uploads & up to 5 team seats.
                <?php else : ?>
                    Full access · Team collaboration · Projects & messaging.
                <?php endif; ?>
            </div>

            <div class="co-plan-limits">
                <?php /* Proposals */ ?>
                <div class="co-limit-row">
                    <div class="co-limit-header">
                        <span class="co-limit-label">Proposals</span>
                        <span class="co-limit-count">
                            <?php
                            $used  = $usage_data['proposals'] ?? 0;
                            $limit = $usage_data['proposals_limit'] ?? null;
                            echo null === $limit
                                ? esc_html( $used ) . ' created'
                                : esc_html( $used ) . ' / ' . esc_html( $limit );
                            ?>
                        </span>
                    </div>
                    <div class="co-bar-track">
                        <div class="co-bar-fill <?php echo null === $limit ? 'unlimited' : esc_attr( $prop_prog['status'] ); ?>"
                             data-pct="<?php echo null === $limit ? 100 : esc_attr( $prop_prog['pct'] ); ?>"></div>
                    </div>
                </div>

                <?php /* AI Requests */ ?>
                <div class="co-limit-row">
                    <div class="co-limit-header">
                        <span class="co-limit-label">AI Requests</span>
                        <span class="co-limit-count">
                            <?php
                            $ai_used  = $usage_data['ai_requests'] ?? 0;
                            $ai_limit = $usage_data['ai_limit'] ?? null;
                            if ( ! $is_pro_or_above ) {
                                echo 'Not available';
                            } elseif ( null === $ai_limit ) {
                                echo esc_html( $ai_used ) . ' this month';
                            } else {
                                echo esc_html( $ai_used ) . ' / ' . esc_html( $ai_limit ) . ' this month';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="co-bar-track">
                        <div class="co-bar-fill <?php echo ! $is_pro_or_above ? '' : esc_attr( $ai_prog['status'] ); ?>"
                             data-pct="<?php echo ! $is_pro_or_above ? 0 : esc_attr( $ai_prog['pct'] ); ?>"></div>
                    </div>
                </div>

                <?php /* Team Seats */ ?>
                <div class="co-limit-row">
                    <div class="co-limit-header">
                        <span class="co-limit-label">Team Seats</span>
                        <span class="co-limit-count">
                            <?php echo esc_html( $usage_data['team_seats'] ?? 1 ) . ' / ' . esc_html( $usage_data['team_limit'] ?? 1 ); ?>
                        </span>
                    </div>
                    <div class="co-bar-track">
                        <div class="co-bar-fill <?php echo esc_attr( $team_prog['status'] ); ?>"
                             data-pct="<?php echo esc_attr( $team_prog['pct'] ); ?>"></div>
                    </div>
                </div>
            </div>

            <?php if ( ! $is_agency ) : ?>
                <div class="co-plan-cta">
                    <a href="<?php echo esc_url( $upgrade_url ); ?>" class="co-btn-upgrade-card">
                        <?php echo 'free' === $user_plan ? 'Upgrade to Pro' : 'Upgrade to Agency'; ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;stroke:currentColor;">
                            <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php /* Feature Access Grid */ ?>
        <div class="co-card">
            <div class="co-card-title">Feature Access</div>
            <div class="co-feature-grid">
                <?php foreach ( $features as $feat ) :
                    $access  = $feature_access[ $feat['key'] ] ?? false;
                    $active  = false !== $access;
                    $tooltip = '';
                    if ( ! $active ) {
                        $tooltip = 'agency' === $feat['gate']
                            ? 'Upgrade to Agency'
                            : 'Upgrade to Pro';
                    }
                    ?>
                    <div class="co-feature-item <?php echo $active ? 'co-active' : 'co-locked'; ?>"
                         <?php echo $tooltip ? 'data-tooltip="' . esc_attr( $tooltip ) . '"' : ''; ?>>

                        <div class="co-feature-icon">
                            <?php
							echo wp_kses(
								$feat['icon'],
								[
									'svg'      => [ 'viewBox' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [], 'xmlns' => [] ],
									'path'     => [ 'd' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [] ],
									'circle'   => [ 'cx' => [], 'cy' => [], 'r' => [], 'fill' => [], 'stroke' => [] ],
									'line'     => [ 'x1' => [], 'y1' => [], 'x2' => [], 'y2' => [] ],
									'polyline' => [ 'points' => [] ],
									'rect'     => [ 'x' => [], 'y' => [], 'width' => [], 'height' => [], 'rx' => [] ],
								]
							);
							?>
                        </div>

                        <div class="co-feature-name"><?php echo esc_html( $feat['label'] ); ?></div>
                        <div class="co-feature-note"><?php echo esc_html( $feat['note'] ); ?></div>

                        <?php if ( $active ) : ?>
                            <div class="co-check-badge">
                                <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </div>
                        <?php else : ?>
                            <div class="co-lock-badge">
                                <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php /* ── Row 2: Usage Stats ─────────────────────────────────────────── */ ?>
    <div class="co-section-title co-animate co-animate-2">Usage This Month</div>

    <div class="co-grid-4 co-animate co-animate-3">

        <?php /* AI Requests */ ?>
        <div class="co-stat-card indigo">
            <div class="co-stat-label">AI Requests</div>
            <div class="co-stat-value"><?php echo esc_html( $usage_data['ai_requests'] ?? 0 ); ?></div>
            <div class="co-stat-meta">
                <?php
                $ai_limit_val = $usage_data['ai_limit'] ?? null;
                if ( ! $is_pro_or_above ) {
                    echo 'Upgrade to unlock';
                } elseif ( null === $ai_limit_val ) {
                    echo 'Unlimited';
                } else {
                    echo 'of ' . esc_html( $ai_limit_val ) . ' monthly';
                }
                ?>
            </div>
            <?php if ( $is_pro_or_above && null !== $ai_limit_val && 0 !== $ai_limit_val ) : ?>
                <div class="co-stat-bar-row">
                    <div class="co-bar-track light">
                        <div class="co-bar-fill light-indigo <?php echo esc_attr( $ai_prog['status'] ); ?>"
                             data-pct="<?php echo esc_attr( $ai_prog['pct'] ); ?>"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php /* Proposals */ ?>
        <div class="co-stat-card emerald">
            <div class="co-stat-label">Proposals Created</div>
            <div class="co-stat-value"><?php echo esc_html( $usage_data['proposals'] ?? 0 ); ?></div>
            <div class="co-stat-meta">
                <?php
                $prop_limit = $usage_data['proposals_limit'] ?? null;
                echo null === $prop_limit
                    ? 'Unlimited'
                    : 'of ' . esc_html( $prop_limit ) . ' total';
                ?>
            </div>
            <?php if ( null !== $prop_limit ) : ?>
                <div class="co-stat-bar-row">
                    <div class="co-bar-track light">
                        <div class="co-bar-fill light-indigo <?php echo esc_attr( $prop_prog['status'] ); ?>"
                             data-pct="<?php echo esc_attr( $prop_prog['pct'] ); ?>"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php /* Storage */ ?>
        <div class="co-stat-card amber">
            <div class="co-stat-label">Storage Used</div>
            <div class="co-stat-value">
                <?php
                $mb = $usage_data['storage_mb'] ?? 0;
                echo $mb >= 1000
                    ? esc_html( number_format( $mb / 1000, 1 ) ) . '<span style="font-size:16px;color:var(--slate-400);margin-left:2px">GB</span>'
                    : esc_html( $mb ) . '<span style="font-size:16px;color:var(--slate-400);margin-left:2px">MB</span>';
                ?>
            </div>
            <div class="co-stat-meta">
                <?php echo esc_html( $is_agency ? 'of 1 GB limit' : 'Not available' ); ?>
            </div>
            <?php if ( $is_agency ) : ?>
                <div class="co-stat-bar-row">
                    <div class="co-bar-track light">
                        <div class="co-bar-fill light-indigo <?php echo esc_attr( $storage_prog['status'] ); ?>"
                             data-pct="<?php echo esc_attr( $storage_prog['pct'] ); ?>"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php /* Team */ ?>
        <div class="co-stat-card navy">
            <div class="co-stat-label">Team Seats</div>
            <div class="co-stat-value"><?php echo esc_html( $usage_data['team_seats'] ?? 1 ); ?></div>
            <div class="co-stat-meta">
                of <?php echo esc_html( $usage_data['team_limit'] ?? 1 ); ?> available
            </div>
            <div class="co-stat-bar-row">
                <div class="co-bar-track light">
                    <div class="co-bar-fill light-indigo <?php echo esc_attr( $team_prog['status'] ); ?>"
                         data-pct="<?php echo esc_attr( $team_prog['pct'] ); ?>"></div>
                </div>
            </div>
        </div>
    </div>

    <?php /* ── Quick Actions ─────────────────────────────────────────────── */ ?>
    <div class="co-section-title co-animate co-animate-4">Quick Actions</div>

    <div class="co-animate co-animate-5">
        <div class="co-actions">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=clientoctopus-proposals&action=new' ) ); ?>" class="co-action-btn primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    New Proposal
                </a>

            <a href="<?php echo esc_url( admin_url( 'admin.php?page=clientoctopus-clients' ) ); ?>" class="co-action-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                Manage Clients
            </a>

            <a href="<?php echo esc_url( admin_url( 'admin.php?page=clientoctopus-proposals' ) ); ?>" class="co-action-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>
                    </svg>
                    All Proposals
                </a>

            <?php if ( $is_agency ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=clientoctopus-projects' ) ); ?>" class="co-action-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                    Projects
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=clientoctopus-team' ) ); ?>" class="co-action-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
                    </svg>
                    Team
                </a>
            <?php endif; ?>

            <?php if ( ! $is_agency ) : ?>
                <a href="<?php echo esc_url( $upgrade_url ); ?>" class="co-action-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="17 11 12 6 7 11"/><line x1="12" y1="6" x2="12" y2="18"/>
                    </svg>
                    <?php echo 'free' === $user_plan ? 'Upgrade to Pro' : 'Upgrade to Agency'; ?>
                </a>
            <?php endif; ?>

            <a href="<?php echo esc_url( admin_url( 'admin.php?page=clientoctopus-settings' ) ); ?>" class="co-action-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/>
                </svg>
                Settings
            </a>
        </div>
    </div>

</div><!-- #co-admin-wrap -->

