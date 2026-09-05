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
		// Client deliverables are meant to be served only through the
		// authenticated download endpoint below — never as a direct, guessable
		// web URL. unique_filename_callback appends a random token so the
		// stored filename can't be predicted from the visible original name
		// alone, and ensure_upload_protection() adds a deny-all .htaccess so
		// Apache/LiteSpeed hosts (the large majority) refuse direct requests
		// entirely regardless of filename.
		$uploaded = wp_handle_upload( $wp_file, [
			'test_form'                => false,
			'unique_filename_callback' => static function ( string $dir, string $name, string $ext ): string {
				$base = sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) );
				return $base . '-' . wp_generate_password( 12, false, false ) . $ext;
			},
		] );

		remove_filter( 'upload_dir', $upload_dir_filter );

		self::ensure_upload_protection();

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

	/**
	 * Write a deny-all .htaccess (and a blank index.php, for directory-listing
	 * defense-in-depth) into the shared clientoctopus uploads base folder, if
	 * one doesn't already exist. Apache/LiteSpeed rules cascade into every
	 * {project_id} subfolder, so this is written once at the parent level
	 * rather than per project. Nginx doesn't honor .htaccess — this is a
	 * best-effort mitigation for the large majority of WP hosting, not a
	 * substitute for the random-filename layer above.
	 */
	private static function ensure_upload_protection(): void {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'clientoctopus';

		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}

		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$contents = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a static deny-all rule file, not user-controlled content.
			file_put_contents( $htaccess, $contents );
		}

		$index = $base . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- static silence file, matches WP core's own convention for upload directories.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
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

	// ── Delete ────────────────────────────────────────────────────────────────

	/**
	 * Delete a file (admin).
	 *
	 * Removes the file from disk and the DB row, and decrements storage usage.
	 *
	 * @param int $id
	 * @param int $owner_id
	 *
	 * @return bool|WP_Error
	 */
	public static function delete( int $id, int $owner_id ): bool|WP_Error {
		global $wpdb;

		// Raw query — we need file_url which prepare_row() strips from the public getter.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
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
	 * @param int $id
	 * @param int $owner_id  WP user ID of the owner requesting the download.
	 */
	public static function stream( int $id, int $owner_id ): void {
		global $wpdb;

		// Raw query — we need file_url, which prepare_row() strips from the
		// public getter (see the identical pattern/comment in delete() above).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
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
			status_header( 404 );
			exit( 'File not found.' );
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
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
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
	 * Return files for a project — client portal view, identified by client ID.
	 *
	 * @param int $project_id
	 * @param int $client_id
	 * @return array|WP_Error
	 */
	public static function get_for_client( int $project_id, int $client_id ): array|WP_Error {
		global $wpdb;

		if ( ! self::client_owns_project( $project_id, $client_id ) ) {
			return new WP_Error( 'forbidden', __( 'Access denied.', 'clientoctopus' ), [ 'status' => 403 ] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
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
	 * Stream a file download for a portal client identified by client ID.
	 *
	 * @param int $id          File ID.
	 * @param int $client_id
	 * @param int $project_id
	 */
	public static function stream_for_client( int $id, int $client_id, int $project_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- self::table() is a trusted constant ($wpdb->prefix + hardcoded class const), not user input.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.* FROM " . self::table() . " f WHERE f.id = %d AND f.project_id = %d",
				$id,
				$project_id
			),
			ARRAY_A
		);

		if ( ! $row || ! self::client_owns_project( $project_id, $client_id ) ) {
			status_header( 403 );
			exit( 'Access denied.' );
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

		if ( ob_get_level() ) {
			ob_end_clean();
		}

		$mime = $row['file_mime'] ?: 'application/octet-stream';
		$name = $row['file_name'] ?: basename( $abs_path );

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . filesize( $abs_path ) );
		header( 'Cache-Control: no-store' );

		readfile( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- direct output for file download, WP_Filesystem is not suitable here.
		exit;
	}

	/**
	 * Check whether the given client owns (is the client on) a project.
	 *
	 * @param int $project_id
	 * @param int $client_id
	 * @return bool
	 */
	private static function client_owns_project( int $project_id, int $client_id ): bool {
		global $wpdb;

		return (bool) (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}clientoctopus_projects
				 WHERE id = %d AND client_id = %d",
				$project_id,
				$client_id
			)
		);
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
