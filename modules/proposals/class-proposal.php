<?php
/**
 * Proposal Model
 *
 * Handles all CRUD operations for the clientoctopus_proposals table.
 * Every write operation checks entitlements before acting.
 *
 * @package ClientOctopus\Proposals
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Proposal
 */
class ClientOctopus_Proposal {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; table() returns a trusted constant, not user input.

	// ── Schema ────────────────────────────────────────────────────────────────

	/**
	 * Valid proposal status values.
	 *
	 * @var string[]
	 */
	public const STATUSES = [ 'draft', 'sent', 'viewed', 'accepted', 'declined', 'expired', 'completed' ];

	/**
	 * Table name (without $wpdb->prefix — use self::table() in queries).
	 *
	 * @var string
	 */
	private const TABLE = 'clientoctopus_proposals';

	/**
	 * Return the full prefixed table name.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	// ── Create ────────────────────────────────────────────────────────────────

	/**
	 * Create a new proposal.
	 *
	 * @param int   $owner_id WordPress user ID of the creator.
	 * @param array $data     Proposal fields (see $defaults).
	 *
	 * @return int|WP_Error New proposal ID, or WP_Error on failure.
	 */
	public static function create( int $owner_id, array $data ): int|WP_Error {
		global $wpdb;

$now      = current_time( 'mysql' );
		$defaults = [
			'owner_id'        => $owner_id,
			'client_id'       => null,
			'title'           => '',
			'content'         => null,
			'token'           => self::generate_token(),
			'status'          => 'draft',
			'total_amount'    => null,
			'currency'        => 'GBP',
			'payment_enabled' => 0,
			'expiry_date'     => null,
			'template_id'     => null,
			'created_at'      => $now,
			'updated_at'      => $now,
		];

		$row = array_merge( $defaults, array_intersect_key( $data, $defaults ) );

		// Always regenerate token — never allow caller to set it.
		$row['token'] = self::generate_token();

		// Enable payment if owner has Pro/Agency.
		if ( clientoctopus_can_user( $owner_id, 'use_payments' ) ) {
			$row['payment_enabled'] = 1;
		}

		// Recurring-billing proposals never take a deposit or direct payment —
		// billing happens exclusively through the recurring-invoice profile
		// auto-created on acceptance. Enforced here (not by individual callers)
		// so every path that can create a proposal — the wizard, duplicate(),
		// any future caller — is covered uniformly.
		if ( self::content_is_recurring( $row['content'] ?? null ) ) {
			$row['payment_enabled'] = 0;
		}

		$inserted = $wpdb->insert( self::table(), $row );

		if ( false === $inserted ) {
			return new WP_Error(
				'db_insert_failed',
				__( 'Failed to create proposal.', 'clientoctopus' ),
				[ 'status' => 500 ]
			);
		}

		$id = (int) $wpdb->insert_id;

		return $id;
	}

	/**
	 * Whether a (possibly JSON-encoded) content blob has recurring billing enabled.
	 *
	 * @param array|string|null $content
	 */
	private static function content_is_recurring( array|string|null $content ): bool {
		if ( is_string( $content ) ) {
			$content = json_decode( $content, true );
		}
		return is_array( $content ) && ! empty( $content['recurring']['enabled'] );
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Get a single proposal by ID.
	 *
	 * @param int $id
	 * @param int $owner_id Used to scope ownership (non-admins see own only).
	 *
	 * @return array|WP_Error
	 */
	public static function get( int $id, int $owner_id = 0 ): array|WP_Error {
		global $wpdb;

		$t = self::table();

		$sql = $owner_id
			? $wpdb->prepare(
				"SELECT p.*, c.name AS client_name, c.email AS client_email,
				        c.company AS client_company, c.phone AS client_phone
				 FROM $t p
				 LEFT JOIN {$wpdb->prefix}clientoctopus_clients c ON p.client_id = c.id
				 WHERE p.id = %d AND p.owner_id = %d AND p.deleted_at IS NULL",
				$id,
				$owner_id
			)
			: $wpdb->prepare(
				"SELECT p.*, c.name AS client_name, c.email AS client_email,
				        c.company AS client_company, c.phone AS client_phone
				 FROM $t p
				 LEFT JOIN {$wpdb->prefix}clientoctopus_clients c ON p.client_id = c.id
				 WHERE p.id = %d AND p.deleted_at IS NULL",
				$id
			);

		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! $row ) {
			return new WP_Error(
				'proposal_not_found',
				__( 'Proposal not found.', 'clientoctopus' ),
				[ 'status' => 404 ]
			);
		}

		return self::prepare_row( $row );
	}

	/**
	 * List proposals for a user with optional filters.
	 *
	 * @param int   $owner_id
	 * @param array $args {
	 *     Optional query args.
	 *
	 *     @type string $status   Filter by status.
	 *     @type string $search   Search title/client name.
	 *     @type int    $page     Page number (1-based).
	 *     @type int    $per_page Results per page (max 100).
	 *     @type string $orderby  Column to sort by.
	 *     @type string $order    'ASC' or 'DESC'.
	 * }
	 *
	 * @return array { proposals: [], total: int, pages: int }
	 */
	public static function list( int $owner_id, array $args = [] ): array {
		global $wpdb;

		$status   = $args['status']   ?? '';
		$search   = $args['search']   ?? '';
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$orderby_input = $args['orderby'] ?? 'created_at';
		$orderby       = in_array( $orderby_input, [ 'created_at', 'updated_at', 'title', 'status', 'total_amount' ], true )
			? $orderby_input
			: 'created_at';
		$order    = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$offset   = ( $page - 1 ) * $per_page;
		$t        = self::table();

		// Build WHERE — all column refs must use the 'p.' alias because the
		// main SELECT joins clientoctopus_clients (which also has owner_id).
		$where = [ $wpdb->prepare( "p.owner_id = %d", $owner_id ), "p.deleted_at IS NULL" ];

		if ( $status && in_array( $status, self::STATUSES, true ) ) {
			$where[] = $wpdb->prepare( "p.status = %s", $status );
		}

		if ( $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( "(p.title LIKE %s OR p.client_id IN (SELECT id FROM {$wpdb->prefix}clientoctopus_clients WHERE name LIKE %s))", $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// $orderby and $order are whitelisted above; $where_sql components are
		// individually prepared via $wpdb->prepare(). The ORDER BY clause cannot
		// use placeholders, so it is built from the validated whitelist values.
		// $where_sql components are individually prepared above; no outer prepare() needed.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t p WHERE $where_sql" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, c.name AS client_name, c.email AS client_email, c.company AS client_company
				 FROM $t p
				 LEFT JOIN {$wpdb->prefix}clientoctopus_clients c ON p.client_id = c.id
				 WHERE $where_sql
				 ORDER BY p.$orderby $order
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			$rows = [];
		}

		// Per-status counts for the tab badges — always reflects the full,
		// unfiltered-by-status/search set (matches the pre-pagination client-side
		// behavior, which counted the entire fetched list regardless of the
		// active tab or search box).
		$counts = array_fill_keys( self::STATUSES, 0 );
		$counts['all'] = 0;
		$count_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS c FROM $t WHERE owner_id = %d AND deleted_at IS NULL GROUP BY status", $owner_id )
		);
		foreach ( $count_rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->c;
			}
			$counts['all'] += (int) $row->c;
		}

		return [
			'proposals' => array_map( [ __CLASS__, 'prepare_row' ], $rows ),
			'total'     => $total,
			'pages'     => (int) ceil( $total / $per_page ),
			'page'      => $page,
			'per_page'  => $per_page,
			'counts'    => $counts,
		];
	}

	// ── Update ────────────────────────────────────────────────────────────────

	/**
	 * Update a proposal.
	 *
	 * Sent/viewed/accepted/declined proposals may still have some fields updated
	 * (e.g. expiry_date), but their status cannot be rolled back.
	 *
	 * @param int   $id       Proposal ID.
	 * @param int   $owner_id Ownership check.
	 * @param array $data     Fields to update.
	 *
	 * @return bool|WP_Error
	 */
	public static function update( int $id, int $owner_id, array $data ): bool|WP_Error {
		global $wpdb;

		$allowed = [
			'title', 'content', 'total_amount', 'currency',
			'expiry_date', 'client_id', 'status', 'template_id', 'payment_enabled',
		];

		$update = array_intersect_key( $data, array_flip( $allowed ) );

		if ( empty( $update ) ) {
			return new WP_Error( 'no_data', __( 'No valid fields to update.', 'clientoctopus' ), [ 'status' => 400 ] );
		}

		// Same enforcement as create(): a proposal being saved with recurring
		// billing enabled can never have payment_enabled on, regardless of what
		// the caller passed (or didn't pass).
		if ( array_key_exists( 'content', $update ) && self::content_is_recurring( $update['content'] ) ) {
			$update['payment_enabled'] = 0;
		}

		// Auto-reset declined proposals to draft when content is being edited,
		// unless the caller is explicitly setting a different status.
		if ( ! isset( $update['status'] ) ) {
			$current_status = $wpdb->get_var(
				$wpdb->prepare( 'SELECT status FROM ' . self::table() . ' WHERE id = %d AND owner_id = %d', $id, $owner_id )
			);
			if ( in_array( $current_status, [ 'declined', 'revision_requested' ], true ) ) {
				$update['status'] = 'draft';
			}
		}

		$update['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update(
			self::table(),
			$update,
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		if ( false === $result ) {
			return new WP_Error( 'db_update_failed', __( 'Failed to update proposal.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		if ( 0 === $result ) {
			// Either not found or no change — verify existence.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE id = %d AND owner_id = %d", $id, $owner_id ) );
			if ( ! $exists ) {
				return new WP_Error( 'proposal_not_found', __( 'Proposal not found.', 'clientoctopus' ), [ 'status' => 404 ] );
			}
		}

		return true;
	}

	// ── Send ──────────────────────────────────────────────────────────────────

	/**
	 * Mark a proposal as sent and record the sent timestamp.
	 *
	 * @param int    $id       Proposal ID.
	 * @param int    $owner_id
	 * @param string $client_email Email to send to (for notification in Sprint 3).
	 *
	 * @return bool|WP_Error
	 */
	public static function send( int $id, int $owner_id, string $client_email = '' ): bool|WP_Error {
		global $wpdb;

		$proposal = self::get( $id, $owner_id );

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		if ( 'draft' !== $proposal['status'] ) {
			return new WP_Error(
				'invalid_status',
				__( 'Only draft proposals can be sent.', 'clientoctopus' ),
				[ 'status' => 422 ]
			);
		}

		$now = current_time( 'mysql' );

		$wpdb->update(
			self::table(),
			[
				'status'     => 'sent',
				'sent_at'    => $now,
				'updated_at' => $now,
			],
			[ 'id' => $id, 'owner_id' => $owner_id ]
		);

		// Allow modules (e.g. portal) to react to a proposal being sent.
		do_action( 'clientoctopus_proposal_sent', $id, $owner_id );

		return true;
	}

	// ── Delete ────────────────────────────────────────────────────────────────

	/**
	 * Delete a proposal.
	 *
	 * Only draft or declined proposals can be deleted.
	 *
	 * @param int $id
	 * @param int $owner_id
	 *
	 * @return bool|WP_Error
	 */
	public static function delete( int $id, int $owner_id ): bool|WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
		$proposal = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM " . self::table() . " WHERE id = %d AND owner_id = %d AND deleted_at IS NULL",
				$id,
				$owner_id
			)
		);

		if ( ! $proposal ) {
			return new WP_Error( 'proposal_not_found', __( 'Proposal not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		// Guard: block deletion when a linked project is still active.
		$project = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$wpdb->prefix}clientoctopus_projects
				 WHERE proposal_id = %d AND owner_id = %d AND deleted_at IS NULL
				 LIMIT 1",
				$id,
				$owner_id
			)
		);

		if ( $project ) {
			if ( clientoctopus_can_user( $owner_id, 'use_projects' ) && 'completed' !== $project->status ) {
				return new WP_Error(
					'proposal_has_active_project',
					__( 'This proposal has an active project. Complete or delete the project first.', 'clientoctopus' ),
					[ 'status' => 422 ]
				);
			}
			// Project is completed, or owner is not on Agency plan — cascade soft-delete alongside the proposal.
			$wpdb->update(
				$wpdb->prefix . 'clientoctopus_projects',
				[ 'deleted_at' => current_time( 'mysql' ) ],
				[ 'id' => (int) $project->id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		// Soft-delete: stamp deleted_at so the row is preserved for analytics and project references.
		$wpdb->update(
			self::table(),
			[ 'deleted_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'owner_id' => $owner_id ],
			[ '%s' ],
			[ '%d', '%d' ]
		);

		return true;
	}

	// ── Duplicate ─────────────────────────────────────────────────────────────

	/**
	 * Duplicate an existing proposal.
	 *
	 * Creates a new draft copy with "Copy of " prefix on the title.
	 * Entitlement check applies (free users still limited to 5 total).
	 *
	 * @param int $id
	 * @param int $owner_id
	 *
	 * @return int|WP_Error New proposal ID.
	 */
	public static function duplicate( int $id, int $owner_id ): int|WP_Error {
		$source = self::get( $id, $owner_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$content      = is_array( $source['content'] ) ? $source['content'] : [];
		$total_amount = $source['total_amount'];

		// A package-mode proposal may already have a client's resolved selection
		// (content.line_items/selected_tier_id/selected_addon_ids, written at
		// accept time) baked in. A duplicate is a fresh draft, not a copy of one
		// client's choice, so reset the selection back to unresolved and the
		// total back to the cheapest-tier floor — mirroring the expiry_date reset.
		if ( 'packages' === ( $content['pricing_mode'] ?? 'flat' ) ) {
			unset( $content['selected_tier_id'], $content['selected_addon_ids'] );
			$content['line_items'] = [];
			$total_amount          = ClientOctopus_Proposal_Handlers::calculate_cheapest_tier_total(
				$content['packages'] ?? [],
				(float) ( $content['discount_pct'] ?? 0 ),
				(float) ( $content['vat_pct'] ?? 0 )
			);
		}

		// Strip accept-time recurring-profile audit state — a duplicate is a
		// fresh, unaccepted draft, so it must not falsely imply a recurring
		// invoice profile already exists for it. The recurring schedule
		// configuration itself (frequency/start_date/etc.) is left intact.
		unset( $content['recurring_profile_id'] );

		$new_data = [
			'title'        => __( 'Copy of ', 'clientoctopus' ) . $source['title'],
			'content'      => wp_json_encode( $content ),
			'total_amount' => $total_amount,
			'currency'     => $source['currency'],
			'expiry_date'  => null, // Reset expiry on duplicate.
			'client_id'    => $source['client_id'],
		];

		return self::create( $owner_id, $new_data );
	}

	// ── Preview Token ─────────────────────────────────────────────────────────

	/**
	 * Generate (or regenerate) a preview token for a proposal.
	 *
	 * The preview token is a separate UUID from the client-facing token so
	 * that revoking the preview never affects the live proposal URL.
	 *
	 * @param int $id       Proposal ID.
	 * @param int $owner_id Ownership check.
	 *
	 * @return string|WP_Error The new preview token on success.
	 */
	public static function generate_preview_token( int $id, int $owner_id ): string|WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::table() . " WHERE id = %d AND owner_id = %d AND deleted_at IS NULL",
				$id,
				$owner_id
			)
		);

		if ( ! $exists ) {
			return new WP_Error( 'proposal_not_found', __( 'Proposal not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		$token  = wp_generate_uuid4();
		$result = $wpdb->update(
			self::table(),
			[ 'preview_token' => $token, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'owner_id' => $owner_id ],
			[ '%s', '%s' ],
			[ '%d', '%d' ]
		);

		if ( false === $result ) {
			return new WP_Error( 'db_update_failed', __( 'Failed to generate preview token.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		return $token;
	}

	/**
	 * Revoke the preview token for a proposal (sets it to NULL).
	 *
	 * @param int $id       Proposal ID.
	 * @param int $owner_id Ownership check.
	 *
	 * @return bool|WP_Error
	 */
	public static function revoke_preview_token( int $id, int $owner_id ): bool|WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::table() . " WHERE id = %d AND owner_id = %d AND deleted_at IS NULL",
				$id,
				$owner_id
			)
		);

		if ( ! $exists ) {
			return new WP_Error( 'proposal_not_found', __( 'Proposal not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		$result = $wpdb->update(
			self::table(),
			[ 'preview_token' => null, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'owner_id' => $owner_id ],
			[ null, '%s' ],
			[ '%d', '%d' ]
		);

		if ( false === $result ) {
			return new WP_Error( 'db_update_failed', __( 'Failed to revoke preview token.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		return true;
	}

	/**
	 * Get a proposal by its preview token.
	 *
	 * @param string $preview_token
	 *
	 * @return array|WP_Error
	 */
	public static function get_by_preview_token( string $preview_token ): array|WP_Error {
		global $wpdb;

		$t   = self::table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.*, c.name AS client_name, c.email AS client_email,
				        c.company AS client_company, c.phone AS client_phone,
				        u.user_email AS owner_email
				 FROM $t p
				 LEFT JOIN {$wpdb->prefix}clientoctopus_clients c ON p.client_id = c.id
				 LEFT JOIN {$wpdb->users} u ON p.owner_id = u.ID
				 WHERE p.preview_token = %s AND p.deleted_at IS NULL",
				$preview_token
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'proposal_not_found', __( 'Preview link not found or has been revoked.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return self::prepare_row( $row );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Generate a cryptographically random public token for a proposal.
	 *
	 * Used in the client-facing URL: /proposals/{token}
	 * Format: UUID v4 via wp_generate_uuid4().
	 *
	 * @return string
	 */
	private static function generate_token(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Prepare a raw database row for the API response.
	 *
	 * Casts types and decodes JSON content.
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	public static function prepare_row( array $row ): array {
		$row['id']              = (int) $row['id'];
		$row['owner_id']        = (int) $row['owner_id'];
		$row['client_id']       = $row['client_id'] ? (int) $row['client_id'] : null;
		$row['total_amount']    = $row['total_amount'] !== null ? (float) $row['total_amount'] : null;
		$row['payment_enabled'] = (bool) $row['payment_enabled'];
		$row['decline_reason']  = $row['decline_reason'] ?? null;

		// Normalise expiry_date: the column is DATETIME but the date picker
		// needs exactly YYYY-MM-DD. Strip the time component if present.
		if ( ! empty( $row['expiry_date'] ) ) {
			$row['expiry_date'] = substr( $row['expiry_date'], 0, 10 );
		}

		// Decode JSON content block.
		if ( is_string( $row['content'] ) ) {
			$decoded = json_decode( $row['content'], true );
			$row['content'] = is_array( $decoded ) ? $decoded : [];
		}

		return $row;
	}

}
