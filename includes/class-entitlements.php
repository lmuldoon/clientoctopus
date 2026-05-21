<?php
/**
 * Client Octopus Entitlements System
 *
 * Single source of truth for:
 *   - User plan (free / pro / agency)
 *   - Feature access (boolean or tier string)
 *   - Usage limits (monthly, lifetime, storage, seats)
 *   - Rate limiting (AI requests)
 *
 * All feature checks across the entire plugin route through
 * ClientOctopus_Entitlements::can_user(). Never add scattered
 * if ($plan === 'pro') checks in module handlers.
 *
 * @package ClientOctopus
 * @since   0.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; all table variables use ->prefix with trusted constants, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Entitlements
 */
class ClientOctopus_Entitlements {

	// ── Constants ─────────────────────────────────────────────────────────────

	/**
	 * Seconds a user must wait between AI requests (rate limiting).
	 *
	 * @var int
	 */
	private const RATE_LIMIT_SECONDS = 3;

	/**
	 * Valid plan slugs.
	 *
	 * @var string[]
	 */
	private const VALID_PLANS = [ 'free', 'pro', 'agency' ];

	// ── Feature Matrix ────────────────────────────────────────────────────────

	/**
	 * Return the complete feature access matrix.
	 *
	 * Each feature maps to an array keyed by plan slug. Values:
	 *   - false                        → feature blocked
	 *   - true                         → feature fully available
	 *   - string                       → feature available at a named tier
	 *   - ['limit'=>int, 'limit_type'=>string] → subject to usage limits
	 *   - ['limit'=>null, ...]          → available, unlimited
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_feature_matrix(): array {
		return [
			// ── Proposals ────────────────────────────────────────────────────
			'create_proposal' => [
				'free'   => [ 'limit' => null, 'limit_type' => null ],
				'pro'    => [ 'limit' => null, 'limit_type' => null ],
				'agency' => [ 'limit' => null, 'limit_type' => null ],
			],

			// ── AI Assistance ─────────────────────────────────────────────────
			'use_ai' => [
				'free'   => false,
				'pro'    => [ 'limit' => 100, 'limit_type' => 'monthly' ],
				'agency' => [ 'limit' => 500, 'limit_type' => 'monthly' ],
			],

			// ── Payments ──────────────────────────────────────────────────────
			'use_payments' => [
				'free'   => false,
				'pro'    => true,
				'agency' => true,
			],

			// ── Outbound Webhooks ─────────────────────────────────────────────
			'use_webhooks' => [
				'free'   => false,
				'pro'    => true,
				'agency' => true,
			],

			// ── Client Portal ─────────────────────────────────────────────────
			// Returns 'basic' (view-only) or 'full' (messaging + files).
			// Callers must inspect the returned string.
			'use_portal' => [
				'free'   => false,
				'pro'    => 'basic',
				'agency' => 'full',
			],

			// ── Projects ─────────────────────────────────────────────────────
			'use_projects' => [
				'free'   => false,
				'pro'    => false,
				'agency' => true,
			],

			// ── Messaging ────────────────────────────────────────────────────
			'use_messaging' => [
				'free'   => false,
				'pro'    => false,
				'agency' => true,
			],

			// ── File Uploads (1 GB limit for Agency) ──────────────────────────
			'use_files' => [
				'free'   => false,
				'pro'    => false,
				'agency' => [ 'limit' => 1000, 'limit_type' => 'mb' ],
			],

			// ── Testimonial Emails ────────────────────────────────────────────
			'use_testimonials' => [
				'free'   => false,
				'pro'    => true,
				'agency' => true,
			],

			// ── Team Seats ────────────────────────────────────────────────────
			'team_access' => [
				'free'   => [ 'limit' => 1, 'limit_type' => 'users' ],
				'pro'    => [ 'limit' => 1, 'limit_type' => 'users' ],
				'agency' => [ 'limit' => 5, 'limit_type' => 'users' ],
			],
		];
	}

	// ── Core Check ────────────────────────────────────────────────────────────

	/**
	 * Check whether a user can access a feature.
	 *
	 * This is THE method all modules must call. No exceptions.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $feature Feature slug.
	 * @param array  $options Optional context (e.g. ['proposal_id' => 42]).
	 *
	 * @return bool|string True/false for most features. String portal tier
	 *                     ('basic'|'full') for 'use_portal'.
	 */
	public static function can_user( int $user_id, string $feature, array $options = [] ): bool|string {
		$plan   = self::get_user_plan( $user_id );
		$matrix = self::get_feature_matrix();

		// Unknown feature → deny by default.
		if ( ! isset( $matrix[ $feature ] ) ) {
			return false;
		}

		$access = $matrix[ $feature ][ $plan ];

		// Hard deny.
		if ( false === $access ) {
			return false;
		}

		// Hard allow.
		if ( true === $access ) {
			return true;
		}

		// Portal tier string ('basic' | 'full') — return as-is.
		if ( is_string( $access ) ) {
			return $access;
		}

		// Array-based limit rules.
		if ( is_array( $access ) ) {
			return self::check_limit( $user_id, $feature, $access, $options );
		}

		return false;
	}

	// ── Limit Checking ────────────────────────────────────────────────────────

	/**
	 * Evaluate a limit rule against current usage.
	 *
	 * @param int    $user_id
	 * @param string $feature
	 * @param array  $access  The limit definition array.
	 * @param array  $options
	 *
	 * @return bool
	 */
	private static function check_limit(
		int $user_id,
		string $feature,
		array $access,
		array $options
	): bool {
		$limit      = $access['limit']      ?? null;
		$limit_type = $access['limit_type'] ?? null;

		// Null limit → unlimited.
		if ( null === $limit ) {
			return true;
		}

		return match ( $limit_type ) {
			'total'   => self::get_total_count( $user_id, $feature ) < $limit,
			'monthly' => self::get_monthly_usage( $user_id, $feature ) < $limit,
			'mb'      => self::get_storage_used( $user_id ) < $limit,
			'users'   => self::get_team_seats_used( $user_id ) < $limit,
			default   => true,
		};
	}

	// ── Plan ──────────────────────────────────────────────────────────────────

	/**
	 * Get a user's current plan.
	 *
	 * Creates a default 'free' row if none exists yet.
	 *
	 * @param int $user_id
	 *
	 * @return string 'free' | 'pro' | 'agency'
	 */
	public static function get_user_plan( int $user_id ): string {
		global $wpdb;

		// Team members inherit their owner's plan.
		$user_id = clientoctopus_get_owner_id( $user_id );

		$plan = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT plan FROM {$wpdb->prefix}clientoctopus_user_meta WHERE user_id = %d",
				$user_id
			)
		);

		if ( ! in_array( $plan, self::VALID_PLANS, true ) ) {
			self::ensure_user_meta( $user_id );
			return 'free';
		}

		return $plan;
	}

	/**
	 * Set a user's plan.
	 *
	 * Handles downgrade notifications per Technical.docx §17.
	 *
	 * @param int    $user_id
	 * @param string $new_plan 'free' | 'pro' | 'agency'
	 * @param string $old_plan Previous plan (empty string if unknown).
	 *
	 * @return bool True on success.
	 */
	public static function set_user_plan( int $user_id, string $new_plan, string $old_plan = '' ): bool {
		global $wpdb;

		if ( ! in_array( $new_plan, self::VALID_PLANS, true ) ) {
			return false;
		}

		self::ensure_user_meta( $user_id );

		$result = $wpdb->update(
			$wpdb->prefix . 'clientoctopus_user_meta',
			[
				'plan'       => $new_plan,
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'user_id' => $user_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		// Notify on downgrade.
		if ( $old_plan && $old_plan !== $new_plan ) {
			self::handle_plan_downgrade( $user_id, $old_plan, $new_plan );
		}

		return false !== $result;
	}

	// ── Usage Queries ─────────────────────────────────────────────────────────

	/**
	 * Get the monthly usage count for a feature.
	 *
	 * For 'use_ai' this queries ai_usage_logs directly (most accurate).
	 * For proposals it reads the cached column in user_meta.
	 *
	 * @param int    $user_id
	 * @param string $feature
	 *
	 * @return int
	 */
	public static function get_monthly_usage( int $user_id, string $feature ): int {
		global $wpdb;

		if ( 'use_ai' === $feature ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}clientoctopus_ai_usage_logs
					 WHERE user_id = %d AND month = %s",
					$user_id,
					gmdate( 'Y-m' )
				)
			);
		}

		// Count directly from the proposals table — always accurate, no cron dependency.
		if ( 'create_proposal' === $feature ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}clientoctopus_proposals
					 WHERE owner_id = %d AND deleted_at IS NULL
					 AND DATE_FORMAT(created_at, '%%Y-%%m') = %s",
					$user_id,
					gmdate( 'Y-m' )
				)
			);
		}

		// Other monthly counters live in user_meta.
		return (int) ( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT proposal_count_month FROM {$wpdb->prefix}clientoctopus_user_meta
				 WHERE user_id = %d",
				$user_id
			)
		) ?? 0 );
	}

	/**
	 * Get the lifetime total count for a feature.
	 *
	 * Used for the free-tier proposal cap (max 5 total, ever).
	 *
	 * @param int    $user_id
	 * @param string $feature
	 *
	 * @return int
	 */
	public static function get_total_count( int $user_id, string $feature ): int {
		global $wpdb;

		if ( 'create_proposal' === $feature ) {
			return (int) ( $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}clientoctopus_proposals
					 WHERE owner_id = %d AND deleted_at IS NULL",
					$user_id
				)
			) ?? 0 );
		}

		return 0;
	}

	/**
	 * Get storage used by a user in MB.
	 *
	 * @param int $user_id
	 *
	 * @return int
	 */
	public static function get_storage_used( int $user_id ): int {
		global $wpdb;

		return (int) ( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT storage_used_mb FROM {$wpdb->prefix}clientoctopus_user_meta
				 WHERE user_id = %d",
				$user_id
			)
		) ?? 0 );
	}

	/**
	 * Get number of team seats currently occupied.
	 *
	 * @param int $user_id The primary account owner.
	 *
	 * @return int
	 */
	public static function get_team_seats_used( int $user_id ): int {
		global $wpdb;

		return (int) ( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT team_seats_used FROM {$wpdb->prefix}clientoctopus_user_meta
				 WHERE user_id = %d",
				$user_id
			)
		) ?? 1 );
	}

	/**
	 * Get the maximum team-seat allowance for a user's plan.
	 *
	 * @param int $user_id
	 *
	 * @return int
	 */
	public static function get_team_limit( int $user_id ): int {
		return match ( self::get_user_plan( $user_id ) ) {
			'agency' => 5,
			default  => 1,
		};
	}

	/**
	 * Check whether a plan includes a feature at all, ignoring runtime usage limits.
	 *
	 * Use this for display/UI purposes. Use can_user() for actual access gates.
	 *
	 * @param int    $user_id
	 * @param string $feature
	 *
	 * @return bool True if the plan matrix entry is not false.
	 */
	public static function plan_includes_feature( int $user_id, string $feature ): bool {
		$plan   = self::get_user_plan( $user_id );
		$matrix = self::get_feature_matrix();

		if ( ! isset( $matrix[ $feature ][ $plan ] ) ) {
			return false;
		}

		return false !== $matrix[ $feature ][ $plan ];
	}

	/**
	 * Get the numeric limit for a feature on the user's current plan.
	 *
	 * @param int    $user_id
	 * @param string $feature
	 *
	 * @return int|null null means unlimited; 0 means blocked.
	 */
	public static function get_feature_limit( int $user_id, string $feature ): ?int {
		$plan   = self::get_user_plan( $user_id );
		$matrix = self::get_feature_matrix();

		if ( ! isset( $matrix[ $feature ][ $plan ] ) ) {
			return 0;
		}

		$access = $matrix[ $feature ][ $plan ];

		if ( false === $access )            { return 0; }
		if ( true === $access )             { return null; }  // unlimited
		if ( is_string( $access ) )         { return null; }  // portal tier
		if ( is_array( $access ) )          { return $access['limit'] ?? null; }

		return null;
	}

	// ── Usage Logging ─────────────────────────────────────────────────────────

	/**
	 * Log a feature usage event and increment counters.
	 *
	 * For 'use_ai':     inserts into ai_usage_logs and bumps ai_usage_count.
	 * For proposals:    increments proposals_created_total + proposal_count_month.
	 *
	 * @param int    $user_id
	 * @param string $feature
	 * @param array  $meta    Extra data (proposal_id, action, tokens_*, cost_usd).
	 *
	 * @return bool True on success.
	 */
	public static function log_usage( int $user_id, string $feature, array $meta = [] ): bool {
		global $wpdb;

		self::ensure_user_meta( $user_id );

		$now   = current_time( 'mysql' );
		$month = gmdate( 'Y-m' );

		if ( 'use_ai' === $feature ) {
			$wpdb->insert(
				$wpdb->prefix . 'clientoctopus_ai_usage_logs',
				[
					'user_id'       => $user_id,
					'proposal_id'   => $meta['proposal_id']   ?? null,
					'action'        => $meta['action']        ?? null,
					'tokens_input'  => $meta['tokens_input']  ?? null,
					'tokens_output' => $meta['tokens_output'] ?? null,
					'cost_usd'      => $meta['cost_usd']      ?? null,
					'timestamp'     => $now,
					'month'         => $month,
				],
				[ '%d', '%d', '%s', '%d', '%d', '%f', '%s', '%s' ]
			);

			// Reset counter if a new month has started.
			$stored_month = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ai_usage_month FROM {$wpdb->prefix}clientoctopus_user_meta WHERE user_id = %d",
					$user_id
				)
			);

			if ( $stored_month !== $month ) {
				$wpdb->update(
					$wpdb->prefix . 'clientoctopus_user_meta',
					[ 'ai_usage_count' => 1, 'ai_usage_month' => $month, 'updated_at' => $now ],
					[ 'user_id' => $user_id ],
					[ '%d', '%s', '%s' ],
					[ '%d' ]
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}clientoctopus_user_meta
						 SET ai_usage_count = ai_usage_count + 1, updated_at = %s
						 WHERE user_id = %d",
						$now,
						$user_id
					)
				);
			}

			return true;
		}

		if ( 'create_proposal' === $feature ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}clientoctopus_user_meta
					 SET proposals_created_total = proposals_created_total + 1,
					     proposal_count_month    = proposal_count_month + 1,
					     updated_at              = %s
					 WHERE user_id = %d",
					$now,
					$user_id
				)
			);

			return true;
		}

		return false;
	}

	// ── Rate Limiting ─────────────────────────────────────────────────────────

	/**
	 * Check whether a user is within the AI rate limit.
	 *
	 * Uses a WordPress transient keyed per user. Returns false if the user
	 * has made a request within the last RATE_LIMIT_SECONDS seconds.
	 *
	 * @param int $user_id
	 *
	 * @return bool True if allowed; false if rate-limited.
	 */
	public static function check_rate_limit( int $user_id ): bool {
		$key = "clientoctopus_rate_limit_{$user_id}";

		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, self::RATE_LIMIT_SECONDS );

		return true;
	}

	// ── Cron Reset ────────────────────────────────────────────────────────────

	/**
	 * Reset monthly usage counters for all users.
	 *
	 * Called by the 'clientoctopus_monthly_reset' cron action at the start
	 * of each month. Resets AI and proposal monthly counters.
	 *
	 * @return void
	 */
	public static function reset_monthly_usage(): void {
		global $wpdb;

		$month = gmdate( 'Y-m' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}clientoctopus_user_meta
				 SET ai_usage_count      = 0,
				     proposal_count_month = 0,
				     ai_usage_month       = %s,
				     updated_at           = NOW()",
				$month
			)
		);
	}

	// ── Internal Helpers ──────────────────────────────────────────────────────

	/**
	 * Ensure a user_meta row exists, inserting a default 'free' row if not.
	 *
	 * Safe to call multiple times — only inserts if the row is absent.
	 *
	 * @param int $user_id
	 *
	 * @return void
	 */
	public static function ensure_user_meta( int $user_id ): void {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}clientoctopus_user_meta WHERE user_id = %d",
				$user_id
			)
		);

		if ( $exists ) {
			return;
		}

		$now = current_time( 'mysql' );

		$wpdb->insert(
			$wpdb->prefix . 'clientoctopus_user_meta',
			[
				'user_id'                 => $user_id,
				'plan'                    => 'free',
				'ai_usage_count'          => 0,
				'ai_usage_month'          => gmdate( 'Y-m' ),
				'proposals_created_total' => 0,
				'proposal_count_month'    => 0,
				'team_seats_used'         => 1,
				'storage_used_mb'         => 0,
				'created_at'              => $now,
				'updated_at'              => $now,
			],
			[ '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Handle plan downgrade side-effects.
	 *
	 * Per Technical.docx §17:
	 *   - New feature restrictions apply immediately.
	 *   - Usage counters reset on the next month boundary (not immediately).
	 *   - User is notified via email.
	 *
	 * @param int    $user_id
	 * @param string $old_plan
	 * @param string $new_plan
	 *
	 * @return void
	 */
	private static function handle_plan_downgrade( int $user_id, string $old_plan, string $new_plan ): void {
		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return;
		}

		$new_plan_label = esc_html( ucfirst( $new_plan ) );
		wp_mail(
			$user->user_email,
			__( 'Your Client Octopus plan has changed', 'clientoctopus' ),
			clientoctopus_email_html( [
				'name' => $user->display_name,
				'body' => "<p style=\"margin:0 0 16px;font-size:16px;color:#6B7280;line-height:1.65;\">Your Client Octopus plan has been changed to <strong style=\"color:#1A1A2E;\">{$new_plan_label}</strong>. Some features may now be restricted.</p><p style=\"margin:0;font-size:16px;color:#6B7280;line-height:1.65;\">Your usage counters will reset at the start of next month. If you have any questions, please get in touch.</p>",
				'cta_label' => __( 'View Account', 'clientoctopus' ),
				'cta_url'   => admin_url( 'admin.php?page=clientoctopus-account' ),
			] ),
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}
}
