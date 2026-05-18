<?php
/**
 * File Model
 *
 * Handles file uploads, downloads, and deletion for project deliverables.
 * Files are stored in uploads/clientoctopus/{project_id}/ and served via
 * authenticated REST endpoints — never exposed as direct web URLs.
 *
 * @package ClientOctopus\Files
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_File
 */
class ClientOctopus_File {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table queries; table() returns a trusted constant, not user input.

	private const TABLE         = 'clientoctopus_files';
	private const STORAGE_LIMIT_MB = 1024; // 1 GB

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	// ── Upload ────────────────────────────────────────────────────────────────

	/**
	 * Upload a file to a project.
	 *
	 * Verifies the owner has the use_files entitlement, checks storage quota,
	 * processes the upload via wp_handle_upload(), and records the DB row.
	 *
	 * @param int   $project_id
	 * @param int   $owner_id   WordPress user ID of the agency/freelancer.
	 * @param array $wp_file    Entry from $_FILES (keys: name, type, tmp_name, error, size).
	 *
	 * @return int|WP_Error New file ID on success.
	 */
	public static function upload( int $project_id, int $owner_id, array $wp_file ): int|WP_Error {
		global $wpdb;

		if ( ! clientoctopus_can_user( $owner_id, 'use_files' ) ) {
			return new WP_Error(
				'unauthorized',
				__( 'File sharing is available on Agency plan.', 'clientoctopus' ),
				[ 'status' => 403 ]
			);
		}

		// Verify project ownership.
		$project = self::get_project( $project_id, $owner_id );
		if ( is_wp_error( $project ) ) {
			return $project;
		}

		// Check storage quota.
		$used_mb = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT storage_used_mb FROM {$wpdb->prefix}clientoctopus_user_meta WHERE user_id = %d",
				$owner_id
			)
		);
		$file_mb = (int) ceil( $wp_file['size'] / ( 1024 * 1024 ) );

		if ( ( $used_mb + $file_mb ) > self::STORAGE_LIMIT_MB ) {
			return new WP_Error(
				'storage_limit_exceeded',
				__( 'Storage limit of 1 GB reached. Please delete files to free space.', 'clientoctopus' ),
				[ 'status' => 413 ]
			);
		}

		// Override upload path to our project directory.
		$upload_dir_filter = static function ( array $dirs ) use ( $project_id ): array {
			$dirs['subdir'] = '/clientoctopus/' . $project_id;
			$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
			$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
			return $dirs;
		};

		add_filter( 'upload_dir', $upload_dir_filter );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$uploaded = wp_handle_upload( $wp_file, [ 'test_form' => false ] );

		remove_filter( 'upload_dir', $upload_dir_filter );

		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error(
				'upload_failed',
				$uploaded['error'],
				[ 'status' => 500 ]
			);
		}

		$now = current_time( 'mysql' );

		$wpdb->insert(
			self::table(),
			[
				'project_id'   => $project_id,
				'uploaded_by'  => $owner_id,
				'file_name'    => sanitize_file_name( $wp_file['name'] ),
				'file_url'     => $uploaded['url'],
				'file_size_kb' => (int) ceil( $wp_file['size'] / 1024 ),
				'file_mime'    => sanitize_mime_type( $uploaded['type'] ),
				'created_at'   => $now,
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( ! $wpdb->insert_id ) {
			return new WP_Error( 'db_insert_failed', __( 'Failed to save file record.', 'clientoctopus' ), [ 'status' => 500 ] );
		}

		// Update storage usage.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}clientoctopus_user_meta (user_id, storage_used_mb, created_at, updated_at)
				 VALUES (%d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE storage_used_mb = storage_used_mb + %d, updated_at = %s",
				$owner_id,
				$file_mb,
				$now,
				$now,
				$file_mb,
				$now
			)
		);

		return (int) $wpdb->insert_id;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * List files for a project (admin — ownership enforced).
	 *
	 * @param int $project_id
	 * @param int $owner_id
	 *
	 * @return array
	 */
	public static function list( int $project_id, int $owner_id ): array {
		global $wpdb;

		$project = self::get_project( $project_id, $owner_id );
		if ( is_wp_error( $project ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE project_id = %d ORDER BY created_at DESC",
				$project_id
			),
			ARRAY_A
		);

		return array_map( [ __CLASS__, 'prepare_row' ], $rows ?: [] );
	}

	/**
	 * Get a single file (admin).
	 *
	 * @param int $id
	 * @param int $owner_id
	 *
	 * @return array|WP_Error
	 */
	public static function get( int $id, int $owner_id ): array|WP_Error {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.* FROM " . self::table() . " f
				 INNER JOIN {$wpdb->prefix}clientoctopus_projects p ON f.project_id = p.id
				 WHERE f.id = %d AND p.owner_id = %d",
				$id,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'file_not_found', __( 'File not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return self::prepare_row( $row );
	}

	/**
	 * List files for a project — client portal access.
	 *
	 * Verifies that the requesting WP user is the client assigned to the project.
	 *
	 * @param int $project_id
	 * @param int $client_wp_user_id
	 *
	 * @return array|WP_Error
	 */
	public static function get_for_client( int $project_id, int $client_wp_user_id ): array|WP_Error {
		global $wpdb;

		if ( ! self::client_owns_project( $project_id, $client_wp_user_id ) ) {
			return new WP_Error( 'forbidden', __( 'Access denied.', 'clientoctopus' ), [ 'status' => 403 ] );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE project_id = %d ORDER BY created_at DESC",
				$project_id
			),
			ARRAY_A
		);

		return array_map( [ __CLASS__, 'prepare_row' ], $rows ?: [] );
	}

	// ── Delete ────────────────────────────────────────────────────────────────

	/**
	 * Delete a file (admin).
	 *
	 * Removes the file from disk and the DB row, and decrements storage usage.
	 *
	 * @param int $id
	 * @param int $owner_id
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id, int $owner_id ): true|WP_Error {
		global $wpdb;

		// Raw query — we need file_url which prepare_row() strips from the public getter.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.* FROM " . self::table() . " f
				 INNER JOIN {$wpdb->prefix}clientoctopus_projects p ON f.project_id = p.id
				 WHERE f.id = %d AND p.owner_id = %d",
				$id,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'file_not_found', __( 'File not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		self::delete_from_disk( $row['file_url'] );

		$wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );

		self::decrement_storage( $owner_id, (int) $row['file_size_kb'] );

		return true;
	}

	/**
	 * Hard-delete all files for a project — used when a project is deleted.
	 *
	 * Removes each file from disk, purges all DB rows in one query, and
	 * decrements the owner's storage counter by the total freed space.
	 *
	 * @param int $project_id
	 * @param int $owner_id
	 */
	public static function delete_for_project( int $project_id, int $owner_id ): void {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT file_url, file_size_kb FROM " . self::table() . " WHERE project_id = %d",
				$project_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			self::delete_from_disk( $row['file_url'] );
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . self::table() . " WHERE project_id = %d",
				$project_id
			)
		);

		$total_kb = (int) array_sum( array_column( $rows, 'file_size_kb' ) );
		self::decrement_storage( $owner_id, $total_kb );
	}

	// ── Stream ────────────────────────────────────────────────────────────────

	/**
	 * Stream a file to the browser with appropriate headers.
	 *
	 * Call this from a REST callback — it exits after streaming.
	 *
	 * @param int  $id
	 * @param int  $accessor_id    WP user ID requesting the download.
	 * @param bool $is_client      True when called from portal endpoint.
	 * @param int  $project_id     Required when $is_client is true.
	 */
	public static function stream( int $id, int $accessor_id, bool $is_client = false, int $project_id = 0 ): void {
		global $wpdb;

		if ( $is_client ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT f.* FROM " . self::table() . " f WHERE f.id = %d AND f.project_id = %d",
					$id,
					$project_id
				),
				ARRAY_A
			);
			if ( ! $row || ! self::client_owns_project( $project_id, $accessor_id ) ) {
				status_header( 403 );
				exit( 'Access denied.' );
			}
		} else {
			$result = self::get( $id, $accessor_id );
			if ( is_wp_error( $result ) ) {
				status_header( 404 );
				exit( 'File not found.' );
			}
			$row = $result;
		}

		$upload_dir = wp_upload_dir();
		$base       = $upload_dir['basedir'];
		$base_url   = $upload_dir['baseurl'];
		$rel        = str_replace( $base_url, '', $row['file_url'] );
		$abs_path   = $base . $rel;

		if ( ! file_exists( $abs_path ) ) {
			status_header( 404 );
			exit( 'File not found on disk.' );
		}

		// Clear output buffer before streaming.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		$mime = $row['file_mime'] ?: 'application/octet-stream';
		$name = $row['file_name'] ?: basename( $abs_path );

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . addslashes( $name ) . '"' );
		header( 'Content-Length: ' . filesize( $abs_path ) );
		header( 'Cache-Control: no-store' );

		readfile( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- direct output for file download, WP_Filesystem is not suitable here.
		exit;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Prepare a raw DB row for API responses.
	 */
	public static function prepare_row( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['project_id']   = (int) $row['project_id'];
		$row['uploaded_by']  = (int) $row['uploaded_by'];
		$row['file_size_kb'] = (int) $row['file_size_kb'];

		// Format size for display.
		$kb = $row['file_size_kb'];
		$row['file_size_human'] = $kb >= 1024
			? round( $kb / 1024, 1 ) . ' MB'
			: $kb . ' KB';

		// Omit internal file_url from API response.
		unset( $row['file_url'] );

		return $row;
	}

	/**
	 * Verify a project belongs to the given owner.
	 */
	private static function get_project( int $project_id, int $owner_id ): array|WP_Error {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}clientoctopus_projects WHERE id = %d AND owner_id = %d",
				$project_id,
				$owner_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'clientoctopus' ), [ 'status' => 404 ] );
		}

		return $row;
	}

	/**
	 * Check whether a WP user is the client assigned to a project.
	 * Matches by email (consistent with ClientOctopus_Portal_Data) so the check
	 * works even before clientoctopus_clients.wp_user_id has been back-filled.
	 */
	private static function client_owns_project( int $project_id, int $client_wp_user_id ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}clientoctopus_projects p
				 INNER JOIN {$wpdb->prefix}clientoctopus_clients c ON p.client_id = c.id
				 INNER JOIN {$wpdb->users} u ON u.user_email = c.email
				 WHERE p.id = %d AND u.ID = %d",
				$project_id,
				$client_wp_user_id
			)
		);

		return $count > 0;
	}

	private static function delete_from_disk( string $file_url ): void {
		$upload_dir = wp_upload_dir();
		$rel        = str_replace( $upload_dir['baseurl'], '', $file_url );
		$abs_path   = $upload_dir['basedir'] . $rel;
		if ( file_exists( $abs_path ) ) {
			wp_delete_file( $abs_path );
		}
	}

	private static function decrement_storage( int $owner_id, int $file_size_kb ): void {
		global $wpdb;
		$file_mb = (int) ceil( ( $file_size_kb * 1024 ) / ( 1024 * 1024 ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}clientoctopus_user_meta
				 SET storage_used_mb = GREATEST(0, storage_used_mb - %d), updated_at = %s
				 WHERE user_id = %d",
				$file_mb,
				current_time( 'mysql' ),
				$owner_id
			)
		);
	}
}
