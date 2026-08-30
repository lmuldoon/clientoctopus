<?php
/**
 * Client Octopus Database Schema
 *
 * Creates all 12 Client Octopus tables via dbDelta() on plugin activation.
 * Safe to call multiple times — dbDelta only applies diffs.
 *
 * Tables:
 *   1.  clientoctopus_user_meta          — per-user plan, usage, billing
 *   2.  clientoctopus_ai_usage_logs      — AI request audit trail
 *   3.  clientoctopus_clients            — client records
 *   4.  clientoctopus_proposals          — proposal data
 *   5.  clientoctopus_projects           — post-acceptance project tracking
 *   6.  clientoctopus_milestones         — project milestone steps (Agency)
 *   7.  clientoctopus_payments           — Stripe payment records
 *   8.  clientoctopus_messages           — threaded messaging (Agency)
 *   9.  clientoctopus_files              — file uploads per project (Agency)
 *   10. clientoctopus_approvals          — approval workflows (Agency)
 *   11. clientoctopus_team_members       — Agency team seats (member ↔ owner links)
 *   12. clientoctopus_webhooks           — outbound webhook endpoints
 *   13. clientoctopus_webhook_logs       — webhook delivery audit log
 *   14. clientoctopus_portal_sessions    — custom portal auth sessions (no WP users)
 *
 * @package ClientOctopus
 * @since   0.1.0
 */

declare( strict_types=1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dbDelta() calls and custom table queries; table names use trusted constants.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create or upgrade all Client Octopus database tables.
 *
 * Uses the WordPress dbDelta() function so it is safe to run on every
 * plugin activation — it only modifies tables when the definition changes.
 *
 * @return void
 */
function clientoctopus_create_tables(): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// ────────────────────────────────────────────────────────────────────────
	// Table 1: clientoctopus_user_meta
	// Foundation for entitlements — single source of truth per user.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_user_meta (
		id INT NOT NULL AUTO_INCREMENT,
		user_id INT NOT NULL,
		plan ENUM('free','pro','agency') NOT NULL DEFAULT 'free',

		ai_usage_count INT NOT NULL DEFAULT 0,
		ai_usage_month VARCHAR(7) DEFAULT NULL,

		billing_cycle_start DATE DEFAULT NULL,
		billing_cycle_end DATE DEFAULT NULL,
		stripe_customer_id VARCHAR(255) DEFAULT NULL,

		team_seats_used INT NOT NULL DEFAULT 1,
		storage_used_mb INT NOT NULL DEFAULT 0,

		created_at DATETIME DEFAULT NULL,
		updated_at DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		UNIQUE KEY user_id (user_id),
		KEY plan (plan),
		KEY ai_usage_month (ai_usage_month),
		KEY billing_cycle_start (billing_cycle_start)
	) $charset_collate;" );

	// Drop stale proposal counter columns removed in DB version 15.
	$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_user_meta DROP COLUMN IF EXISTS proposals_created_total" );
	$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_user_meta DROP COLUMN IF EXISTS proposal_count_month" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 2: clientoctopus_ai_usage_logs
	// Audit trail for every AI call — enables cost tracking and analytics.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_ai_usage_logs (
		id INT NOT NULL AUTO_INCREMENT,
		user_id INT NOT NULL,
		proposal_id INT DEFAULT NULL,
		action VARCHAR(50) DEFAULT NULL,

		tokens_input INT DEFAULT NULL,
		tokens_output INT DEFAULT NULL,
		cost_usd DECIMAL(10,4) DEFAULT NULL,

		timestamp DATETIME DEFAULT NULL,
		month VARCHAR(7) DEFAULT NULL,

		PRIMARY KEY  (id),
		KEY user_month (user_id, month),
		KEY timestamp (timestamp),
		KEY proposal_id (proposal_id),
		KEY action (action)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 3: clientoctopus_clients
	// Freelancer's/agency's client records.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_clients (
		id INT NOT NULL AUTO_INCREMENT,
		owner_id INT NOT NULL,
		wp_user_id INT DEFAULT NULL,

		name VARCHAR(255) NOT NULL,
		email VARCHAR(255) DEFAULT NULL,
		company VARCHAR(255) DEFAULT NULL,
		phone VARCHAR(20) DEFAULT NULL,

		portal_invited_at DATETIME DEFAULT NULL,

		created_at DATETIME DEFAULT NULL,
		updated_at DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		KEY owner_id (owner_id),
		KEY email (email),
		KEY created_at (created_at)
	) $charset_collate;" );

	// Ensure portal_invited_at exists on existing installations.
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_clients LIKE %s", 'portal_invited_at' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN portal_invited_at DATETIME DEFAULT NULL" );
	}
	// Magic-link token stored on the client row (no WP user needed).
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_clients LIKE %s", 'portal_token_hash' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN portal_token_hash VARCHAR(64) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN portal_token_expires_at DATETIME DEFAULT NULL" );
	}
	// Optional portal password hash (bcrypt via wp_hash_password).
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_clients LIKE %s", 'portal_password_hash' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN portal_password_hash VARCHAR(255) DEFAULT NULL" );
	}
	// Saved Stripe payment method for recurring-profile auto-charge (DB version 28).
	// A per-transaction Stripe customer id already exists on clientoctopus_payments,
	// but that's a one-off snapshot per checkout session — this is a reusable
	// customer + payment method attached to the client itself, captured via
	// setup_future_usage on their first manual payment.
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_clients LIKE %s", 'stripe_customer_id' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN stripe_customer_id VARCHAR(255) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN stripe_payment_method_id VARCHAR(255) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN stripe_pm_brand VARCHAR(20) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN stripe_pm_last4 VARCHAR(4) DEFAULT NULL" );
	}
	// Saved PayPal payment method for recurring-profile auto-charge (DB version 29) —
	// same role as the Stripe columns above, via PayPal's Vault feature (store_in_vault
	// on the Orders API, not the JS SDK — see modules/payments/class-paypal.php).
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_clients LIKE %s", 'paypal_vault_id' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN paypal_vault_id VARCHAR(255) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN paypal_vault_customer_id VARCHAR(255) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_clients ADD COLUMN paypal_payer_email VARCHAR(255) DEFAULT NULL" );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Table 4: clientoctopus_proposals
	// Core proposal data. content is a JSON block structure.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_proposals (
		id INT NOT NULL AUTO_INCREMENT,
		owner_id INT NOT NULL,
		client_id INT DEFAULT NULL,

		title VARCHAR(255) NOT NULL,
		content LONGTEXT DEFAULT NULL,

		token VARCHAR(64) NOT NULL DEFAULT '',
		preview_token VARCHAR(64) DEFAULT NULL,

		status ENUM('draft','sent','viewed','accepted','declined','expired','completed','revision_requested') NOT NULL DEFAULT 'draft',

		total_amount DECIMAL(12,2) DEFAULT NULL,
		currency VARCHAR(3) NOT NULL DEFAULT 'GBP',

		payment_enabled TINYINT(1) NOT NULL DEFAULT 0,

		expiry_date DATETIME DEFAULT NULL,
		created_at DATETIME DEFAULT NULL,
		updated_at DATETIME DEFAULT NULL,
		sent_at DATETIME DEFAULT NULL,
		viewed_at DATETIME DEFAULT NULL,
		accepted_at DATETIME DEFAULT NULL,
		declined_at DATETIME DEFAULT NULL,
		decline_reason TEXT DEFAULT NULL,
		deleted_at DATETIME DEFAULT NULL,
		revision_note TEXT DEFAULT NULL,
		revision_requested_at DATETIME DEFAULT NULL,

		signed_name VARCHAR(255) DEFAULT NULL,
		signed_at   DATETIME     DEFAULT NULL,
		signed_ip   VARCHAR(45)  DEFAULT NULL,

		template_id VARCHAR(50) DEFAULT NULL,

		PRIMARY KEY  (id),
		UNIQUE KEY token (token),
		UNIQUE KEY preview_token (preview_token),
		KEY owner_id (owner_id),
		KEY client_id (client_id),
		KEY status (status),
		KEY created_at (created_at),
		KEY template_id (template_id)
	) $charset_collate;" );

	// Ensure 'completed' + 'revision_requested' statuses exist on existing installations.
	$wpdb->query(
		"ALTER TABLE {$wpdb->prefix}clientoctopus_proposals
		 MODIFY COLUMN status ENUM('draft','sent','viewed','accepted','declined','expired','completed','revision_requested') NOT NULL DEFAULT 'draft'"
	);
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_proposals LIKE %s", 'deleted_at' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_proposals ADD COLUMN deleted_at DATETIME DEFAULT NULL" );
	}
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_proposals LIKE %s", 'revision_note' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_proposals ADD COLUMN revision_note TEXT DEFAULT NULL" );
	}
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_proposals LIKE %s", 'revision_requested_at' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_proposals ADD COLUMN revision_requested_at DATETIME DEFAULT NULL" );
	}
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_proposals LIKE %s", 'preview_token' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_proposals ADD COLUMN preview_token VARCHAR(64) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_proposals ADD UNIQUE KEY preview_token (preview_token)" );
	}

	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_proposals LIKE %s", 'signed_name' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_proposals ADD COLUMN signed_name VARCHAR(255) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_proposals ADD COLUMN signed_at DATETIME DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_proposals ADD COLUMN signed_ip VARCHAR(45) DEFAULT NULL" );
	}

	// Composite index for admin proposal list (owner_id filtered, deleted_at checked, sorted by created_at).
	if ( ! $wpdb->get_var( $wpdb->prepare(
		"SHOW INDEX FROM {$wpdb->prefix}clientoctopus_proposals WHERE Key_name = %s",
		'owner_deleted_created'
	) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_proposals ADD KEY owner_deleted_created (owner_id, deleted_at, created_at)" );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Table 5: clientoctopus_projects
	// Auto-created when a proposal is accepted (Agency tier).
	// Links proposal → delivery work.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_projects (
		id INT NOT NULL AUTO_INCREMENT,
		owner_id INT NOT NULL,
		client_id INT NOT NULL,
		proposal_id INT NOT NULL,

		name VARCHAR(255) NOT NULL,
		description TEXT DEFAULT NULL,

		status ENUM('active','on-hold','completed') NOT NULL DEFAULT 'active',

		created_at DATETIME DEFAULT NULL,
		updated_at DATETIME DEFAULT NULL,
		completed_at DATETIME DEFAULT NULL,
		deleted_at DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		KEY owner_id (owner_id),
		KEY client_id (client_id),
		KEY proposal_id (proposal_id),
		KEY status (status)
	) $charset_collate;" );

	// Ensure deleted_at exists on existing installations (dbDelta adds it for new installs; this covers upgrades).
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_projects LIKE %s", 'deleted_at' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_projects ADD COLUMN deleted_at DATETIME DEFAULT NULL" );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Table 6: clientoctopus_milestones
	// Step-level milestones within a project. Agency tier.
	// sort_order controls display sequence (drag-to-reorder).
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_milestones (
		id           INT NOT NULL AUTO_INCREMENT,
		project_id   INT NOT NULL,
		owner_id     INT NOT NULL,

		title        VARCHAR(255) NOT NULL DEFAULT '',
		description  TEXT DEFAULT NULL,

		status       ENUM('pending','submitted','in-progress','completed') NOT NULL DEFAULT 'pending',

		due_date     DATE DEFAULT NULL,
		completed_at DATETIME DEFAULT NULL,

		sort_order   SMALLINT NOT NULL DEFAULT 0,
		created_at   DATETIME DEFAULT NULL,
		updated_at   DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		KEY project_id (project_id),
		KEY owner_id (owner_id),
		KEY status (status)
	) $charset_collate;" );

	// Ensure the 'submitted' status exists for existing installations (dbDelta won't modify ENUMs).
	$wpdb->query(
		"ALTER TABLE {$wpdb->prefix}clientoctopus_milestones
		 MODIFY COLUMN status ENUM('pending','submitted','in-progress','completed') NOT NULL DEFAULT 'pending'"
	);

	// ────────────────────────────────────────────────────────────────────────
	// Table 7: clientoctopus_payments
	// Stripe payment records. Pro + Agency tiers.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_payments (
		id INT NOT NULL AUTO_INCREMENT,
		proposal_id INT NOT NULL,
		client_id INT DEFAULT NULL,
		owner_id INT NOT NULL,

		amount DECIMAL(12,2) NOT NULL,
		currency VARCHAR(3) NOT NULL DEFAULT 'GBP',
		deposit_pct TINYINT UNSIGNED NOT NULL DEFAULT 100,

		stripe_session_id VARCHAR(255) DEFAULT NULL,
		stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
		stripe_customer_id VARCHAR(255) DEFAULT NULL,

		status ENUM('pending','processing','completed','failed','refunded') NOT NULL DEFAULT 'pending',

		created_at DATETIME DEFAULT NULL,
		updated_at DATETIME DEFAULT NULL,
		completed_at DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		UNIQUE KEY stripe_session_id (stripe_session_id),
		KEY proposal_id (proposal_id),
		KEY owner_id (owner_id),
		KEY status (status),
		KEY proposal_status (proposal_id, status)
	) $charset_collate;" );

	// provider/paypal_order_id/paypal_capture_id are added exclusively via the guarded
	// ALTER block below (DB version 19), not baked into the CREATE TABLE text above —
	// having them in both confused dbDelta's diffing and produced malformed ALTER SQL
	// on every admin page load (matches the pattern used for clients.portal_* etc.).

	// Ensure the composite index exists on existing installations.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- migration DDL uses $wpdb->prefix (trusted) and a hardcoded literal; ALTER TABLE has no user input.
	$idx_exists = $wpdb->get_var( $wpdb->prepare(
		"SHOW INDEX FROM {$wpdb->prefix}clientoctopus_payments WHERE Key_name = %s",
		'proposal_status'
	) );
	if ( ! $idx_exists ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_payments ADD KEY proposal_status (proposal_id, status)" );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// PayPal support (DB version 19) — additive columns; existing Stripe columns/data untouched.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_payments LIKE %s", 'provider' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_payments ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'stripe' AFTER deposit_pct" );
	}
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_payments LIKE %s", 'paypal_order_id' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_payments ADD COLUMN paypal_order_id VARCHAR(255) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_payments ADD COLUMN paypal_capture_id VARCHAR(255) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_payments ADD UNIQUE KEY paypal_order_id (paypal_order_id)" );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// ────────────────────────────────────────────────────────────────────────
	// Table 8: clientoctopus_messages
	// Threaded messaging between agency/freelancer and client. Agency only.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_messages (
		id INT NOT NULL AUTO_INCREMENT,
		project_id INT NOT NULL,
		sender_id INT NOT NULL,
		sender_type ENUM('admin','client') NOT NULL,

		message TEXT NOT NULL,

		read_at DATETIME DEFAULT NULL,
		created_at DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		KEY project_id (project_id),
		KEY sender_id (sender_id),
		KEY created_at (created_at),
		KEY unread_lookup (project_id, sender_type, read_at)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 9: clientoctopus_files
	// File uploads per project. Agency only. 1 GB storage limit per account.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_files (
		id INT NOT NULL AUTO_INCREMENT,
		project_id INT NOT NULL,
		uploaded_by INT NOT NULL,

		file_name VARCHAR(255) NOT NULL,
		file_url VARCHAR(500) NOT NULL,
		file_size_kb INT DEFAULT NULL,
		file_mime VARCHAR(50) DEFAULT NULL,

		created_at DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		KEY project_id (project_id),
		KEY uploaded_by (uploaded_by)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 10: clientoctopus_approvals
	// Approval workflows for designs/deliverables. Agency only.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_approvals (
		id INT NOT NULL AUTO_INCREMENT,
		project_id INT NOT NULL,
		type VARCHAR(50) DEFAULT NULL,

		description TEXT DEFAULT NULL,
		status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',

		requested_by INT DEFAULT NULL,
		approved_by INT DEFAULT NULL,
		client_comment TEXT DEFAULT NULL,

		created_at DATETIME DEFAULT NULL,
		responded_at DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		KEY project_id (project_id),
		KEY status (status)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 11: clientoctopus_team_members
	// Agency-tier team seats. Links team members to the primary account owner.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_team_members (
		id             INT NOT NULL AUTO_INCREMENT,
		owner_id       INT NOT NULL,
		member_user_id INT NOT NULL,
		role           ENUM('admin','editor','viewer') NOT NULL DEFAULT 'editor',
		invited_at     DATETIME DEFAULT NULL,
		accepted_at    DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		UNIQUE KEY owner_member (owner_id, member_user_id),
		KEY owner_id (owner_id),
		KEY member_user_id (member_user_id)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 13: clientoctopus_webhooks
	// Outbound webhook endpoints configured per owner. Pro + Agency tiers.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_webhooks (
		id         INT NOT NULL AUTO_INCREMENT,
		owner_id   INT NOT NULL,
		url        VARCHAR(500) NOT NULL,
		events     JSON NOT NULL,
		secret     VARCHAR(64) NOT NULL DEFAULT '',
		enabled    TINYINT(1) NOT NULL DEFAULT 1,
		created_at DATETIME DEFAULT NULL,
		updated_at DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		KEY owner_id (owner_id),
		KEY enabled  (enabled)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 14: clientoctopus_webhook_logs
	// Delivery audit log — one row per dispatch attempt.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_webhook_logs (
		id            INT NOT NULL AUTO_INCREMENT,
		webhook_id    INT NOT NULL,
		event         VARCHAR(50) NOT NULL,
		response_code SMALLINT DEFAULT NULL,
		success       TINYINT(1) NOT NULL DEFAULT 0,
		delivered_at  DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		KEY webhook_id   (webhook_id),
		KEY delivered_at (delivered_at)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 15: clientoctopus_portal_sessions
	// Custom portal authentication sessions — replaces WordPress auth cookies
	// for client (customer) access so no WordPress user account is required.
	// One row per active session; expired rows are pruned on login.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_portal_sessions (
		id         BIGINT NOT NULL AUTO_INCREMENT,
		client_id  INT NOT NULL,
		owner_id   INT NOT NULL,
		session_token VARCHAR(64) NOT NULL,
		expires_at DATETIME NOT NULL,
		created_at DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		UNIQUE KEY session_token (session_token),
		KEY client_id (client_id),
		KEY expires_at (expires_at)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 16: clientoctopus_automations
	// One row per trigger per owner. Auto-seeded on first settings page load.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_automations (
		id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		owner_id      BIGINT UNSIGNED NOT NULL,
		trigger_event VARCHAR(40) NOT NULL,
		delay_days    TINYINT UNSIGNED NOT NULL DEFAULT 3,
		enabled       TINYINT(1) NOT NULL DEFAULT 0,
		email_subject VARCHAR(255) NOT NULL DEFAULT '',
		email_body    TEXT DEFAULT NULL,
		created_at    DATETIME NOT NULL,
		updated_at    DATETIME NOT NULL,

		PRIMARY KEY  (id),
		UNIQUE KEY owner_trigger (owner_id, trigger_event)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 17: clientoctopus_reminder_log
	// Duplicate-send guard — one row per proposal × trigger once a reminder
	// has been dispatched. Prevents re-sending on subsequent cron runs.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_reminder_log (
		id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		proposal_id   BIGINT UNSIGNED NOT NULL,
		trigger_event VARCHAR(40) NOT NULL,
		sent_at       DATETIME NOT NULL,

		PRIMARY KEY  (id),
		UNIQUE KEY proposal_trigger (proposal_id, trigger_event),
		KEY proposal_id (proposal_id)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 18: clientoctopus_invoices
	// Standalone invoices — not tied to proposals. Auto-numbered per owner.
	// Free: create/send/view/manual-mark-paid. Pro: Stripe Pay Now button.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_invoices (
		id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		owner_id                 BIGINT UNSIGNED NOT NULL,
		client_id                BIGINT UNSIGNED DEFAULT NULL,

		invoice_number           INT UNSIGNED NOT NULL DEFAULT 0,
		token                    VARCHAR(64) NOT NULL DEFAULT '',

		status                   ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',

		title                    VARCHAR(255) NOT NULL DEFAULT '',
		currency                 VARCHAR(3) NOT NULL DEFAULT 'GBP',

		line_items               LONGTEXT DEFAULT NULL,
		discount_type            ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
		discount_value           DECIMAL(10,2) NOT NULL DEFAULT 0,
		vat_pct                  DECIMAL(5,2) NOT NULL DEFAULT 0,
		vat_number               VARCHAR(50) DEFAULT NULL,
		notes                    TEXT DEFAULT NULL,

		due_date                 DATE DEFAULT NULL,
		issue_date               DATE NOT NULL,
		payment_terms            VARCHAR(100) DEFAULT NULL,
		po_number                VARCHAR(100) DEFAULT NULL,

		total_amount             DECIMAL(12,2) NOT NULL DEFAULT 0,

		stripe_session_id        VARCHAR(255) DEFAULT NULL,
		stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,

		sent_at                  DATETIME DEFAULT NULL,
		paid_at                  DATETIME DEFAULT NULL,
		created_at               DATETIME DEFAULT NULL,
		updated_at               DATETIME DEFAULT NULL,
		deleted_at               DATETIME DEFAULT NULL,

		PRIMARY KEY  (id),
		UNIQUE KEY token (token),
		KEY owner_id (owner_id),
		KEY client_id (client_id),
		KEY status (status),
		KEY owner_status (owner_id, status, deleted_at)
	) $charset_collate;" );

	// provider/paypal_order_id/paypal_capture_id are added exclusively via the guarded
	// ALTER block below (DB version 19), not baked into the CREATE TABLE text above —
	// having them in both confused dbDelta's diffing and produced malformed ALTER SQL
	// on every admin page load (matches the pattern used for clients.portal_* etc.).
	// PayPal support (DB version 19) — additive columns; existing Stripe columns/data untouched.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_invoices LIKE %s", 'provider' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_invoices ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'stripe' AFTER total_amount" );
	}
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_invoices LIKE %s", 'paypal_order_id' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_invoices ADD COLUMN paypal_order_id VARCHAR(255) DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_invoices ADD COLUMN paypal_capture_id VARCHAR(255) DEFAULT NULL" );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// ────────────────────────────────────────────────────────────────────────
	// Table 19: clientoctopus_events
	// Append-only audit log — proposal views, accept/decline/revision-request,
	// and payment-completed events. Referenced throughout the codebase
	// (ClientOctopus_Proposal_Client, the payments REST handlers) but its
	// CREATE TABLE statement was missing here, so the table never actually
	// got created — added in DB version 20.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_events (
		id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		proposal_id BIGINT UNSIGNED NOT NULL,
		event_type  VARCHAR(50) NOT NULL,
		user_ip     VARCHAR(45) DEFAULT NULL,
		user_agent  VARCHAR(500) DEFAULT NULL,
		timestamp   DATETIME NOT NULL,
		metadata    TEXT DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY proposal_id (proposal_id),
		KEY event_type (event_type),
		KEY timestamp (timestamp)
	) $charset_collate;" );

	// ────────────────────────────────────────────────────────────────────────
	// Table 20: clientoctopus_recurring_profiles
	// Templates that spawn a fresh clientoctopus_invoices row on a schedule
	// (client pays each one manually via the existing Stripe/PayPal flow —
	// no auto-charge). billing_mode exists now so a future auto-charge mode
	// can be added without a schema rework; only 'manual' is used today.
	// ────────────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}clientoctopus_recurring_profiles (
		id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		owner_id         BIGINT UNSIGNED NOT NULL,
		client_id        BIGINT UNSIGNED NOT NULL,
		title            VARCHAR(255) NOT NULL DEFAULT '',
		line_items       LONGTEXT DEFAULT NULL,
		currency         VARCHAR(3) NOT NULL DEFAULT 'GBP',
		discount_type    ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
		discount_value   DECIMAL(10,2) NOT NULL DEFAULT 0,
		vat_pct          DECIMAL(5,2) NOT NULL DEFAULT 0,
		frequency        ENUM('weekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
		start_date       DATE NOT NULL,
		end_date         DATE DEFAULT NULL,
		max_occurrences  SMALLINT UNSIGNED DEFAULT NULL,
		occurrences_sent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		next_run_date    DATE NOT NULL,
		billing_mode     ENUM('manual','auto_charge') NOT NULL DEFAULT 'manual',
		status           ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
		created_at       DATETIME DEFAULT NULL,
		updated_at       DATETIME DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY owner_id (owner_id),
		KEY client_id (client_id),
		KEY next_run_date (next_run_date),
		KEY status (status)
	) $charset_collate;" );

	// Auto-charge retry/dunning (DB version 28) — dbDelta doesn't reliably MODIFY an
	// existing ENUM's value list, so widen it explicitly (mirrors the same pattern
	// used for clientoctopus_proposals.status above). Idempotent — safe to run every load.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		"ALTER TABLE {$wpdb->prefix}clientoctopus_recurring_profiles
		 MODIFY COLUMN status ENUM('active','paused','cancelled','past_due') NOT NULL DEFAULT 'active'"
	);
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_recurring_profiles LIKE %s", 'retry_count' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_recurring_profiles ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_recurring_profiles ADD COLUMN last_failure_at DATETIME DEFAULT NULL" );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Recurring invoices (DB version 21) — additive column linking a generated
	// invoice back to its series, plus a uniqueness guard on invoice numbering.
	// Not baked into the clientoctopus_invoices CREATE TABLE text above — same
	// dbDelta-confusion reason documented for the provider/paypal_* columns.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_invoices LIKE %s", 'recurring_profile_id' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_invoices ADD COLUMN recurring_profile_id BIGINT UNSIGNED DEFAULT NULL" );
	}

	// Auto-charge retry/dunning tracking (DB version 29) — moved here from
	// clientoctopus_recurring_profiles (DB version 28). Tracking retries per
	// PROFILE let a later invoice's successful charge silently reset the
	// counter for an EARLIER invoice that was still failing/unpaid, making it
	// permanently invisible to the retry cron. Tracking per INVOICE fixes
	// that — each unpaid invoice's failure streak is independent. The old
	// columns on clientoctopus_recurring_profiles are left in place (unused)
	// rather than dropped, to avoid a risky column removal.
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_invoices LIKE %s", 'retry_count' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_invoices ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_invoices ADD COLUMN last_failure_at DATETIME DEFAULT NULL" );
	}

	$has_owner_invoice_number_key = $wpdb->get_var( $wpdb->prepare(
		"SHOW INDEX FROM {$wpdb->prefix}clientoctopus_invoices WHERE Key_name = %s",
		'owner_invoice_number'
	) );
	if ( ! $has_owner_invoice_number_key ) {
		$duplicate_invoice_numbers = $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT owner_id, invoice_number FROM {$wpdb->prefix}clientoctopus_invoices
				GROUP BY owner_id, invoice_number HAVING COUNT(*) > 1
			) dupes"
		);
		// Only add the constraint if the existing data is actually clean — an install
		// with pre-existing duplicates (from the old unguarded MAX()+1 logic racing)
		// would fail the ALTER outright; skip it there rather than fatal the migration,
		// and leave it to be retried once the duplicates are manually resolved.
		if ( ! $duplicate_invoice_numbers ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_invoices ADD UNIQUE KEY owner_invoice_number (owner_id, invoice_number)" );
		}
	}

	// Recurring invoices (DB version 25) — additive po_number column, mirroring
	// the po_number field already on clientoctopus_invoices.
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_recurring_profiles LIKE %s", 'po_number' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_recurring_profiles ADD COLUMN po_number VARCHAR(100) DEFAULT NULL AFTER title" );
	}

	// Recurring invoices (DB version 26) — additive vat_number column, mirroring
	// the vat_number field already on clientoctopus_invoices.
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_recurring_profiles LIKE %s", 'vat_number' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_recurring_profiles ADD COLUMN vat_number VARCHAR(50) DEFAULT NULL AFTER vat_pct" );
	}

	// Recurring invoices (DB version 27) — additive payment_terms and notes
	// columns, mirroring the same fields already on clientoctopus_invoices.
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_recurring_profiles LIKE %s", 'payment_terms' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_recurring_profiles ADD COLUMN payment_terms VARCHAR(100) DEFAULT NULL AFTER po_number" );
	}
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}clientoctopus_recurring_profiles LIKE %s", 'notes' ) ) ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}clientoctopus_recurring_profiles ADD COLUMN notes TEXT DEFAULT NULL" );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	update_option( 'clientoctopus_db_version', defined( 'CLIENTOCTOPUS_DB_VERSION' ) ? CLIENTOCTOPUS_DB_VERSION : '1' );
}
