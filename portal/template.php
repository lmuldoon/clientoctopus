<?php
/**
 * Standalone portal page template.
 *
 * Renders a bare HTML page (no theme) that boots the portal React bundle.
 * Injects window.coPortalData with everything the JS needs.
 *
 * Variables available from portal/routing.php:
 *   $page — one of 'login' | 'verify' | 'dashboard' | 'proposals' | 'payments'
 */

declare( strict_types = 1 );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-scope variables use clientoctopus_ prefix; DB queries use trusted table constants.

if ( ! defined( 'ABSPATH' ) ) exit;

// Re-read the page from query var (routing.php already validated it).
$clientoctopus_portal_page = get_query_var( 'clientoctopus_portal_page', 'login' );

// Client data for authenticated pages.
$clientoctopus_client_data = null;
if ( ClientOctopus_Portal_Auth::is_authenticated() ) {
	$clientoctopus_client_data = ClientOctopus_Portal_Data::get_client( get_current_user_id() );
}

// Business identity.
$clientoctopus_business_name        = get_option( 'blogname', '' );
$clientoctopus_business_logo        = get_option( 'clientoctopus_logo_url', '' );
$clientoctopus_brand_color          = get_option( 'clientoctopus_brand_color', '#6366F1' );
$clientoctopus_hide_business_name   = (bool) get_option( 'clientoctopus_hide_business_name', '' );

// For the verify page, pass the raw token from the query string so the
// PortalVerify component can fire the verify API immediately on mount.
$clientoctopus_verify_token = '';
if ( 'verify' === $clientoctopus_portal_page ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing token, no state change occurs here.
	$clientoctopus_verify_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
}

// Determine if the admin owner has the agency plan (projects are agency-only).
$clientoctopus_has_projects = false;
if ( ClientOctopus_Portal_Auth::is_authenticated() ) {
	global $wpdb;
	$clientoctopus_owner_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.owner_id
			 FROM {$wpdb->prefix}clientoctopus_proposals p
			 INNER JOIN {$wpdb->prefix}clientoctopus_clients c ON c.id = p.client_id
			 INNER JOIN {$wpdb->users} u ON u.user_email = c.email
			 WHERE u.ID = %d
			 LIMIT 1",
			get_current_user_id()
		)
	);
	if ( $clientoctopus_owner_id ) {
		$clientoctopus_has_projects = (bool) ClientOctopus_Entitlements::can_user( $clientoctopus_owner_id, 'use_projects' );
	}
}

// Nonce for WP REST API calls.
$clientoctopus_nonce = wp_create_nonce( 'wp_rest' );

// Asset manifest.
$clientoctopus_asset_file = CLIENTOCTOPUS_DIR . 'build/portal.asset.php';
$clientoctopus_asset      = file_exists( $clientoctopus_asset_file ) ? require $clientoctopus_asset_file : [ 'version' => CLIENTOCTOPUS_VERSION, 'dependencies' => [] ];
$clientoctopus_ver        = $clientoctopus_asset['version'];
$clientoctopus_bundle_url = plugins_url( 'build/portal.js', CLIENTOCTOPUS_DIR . 'clientoctopus.php' );

// Enqueue portal bundle with its dependencies so WordPress loads wp-element,
// react, react-jsx-runtime etc. before the bundle runs.
$clientoctopus_deps = array_unique( array_merge( $clientoctopus_asset['dependencies'], [ 'wp-element' ] ) );
wp_enqueue_script( 'co-portal', $clientoctopus_bundle_url, $clientoctopus_deps, $clientoctopus_ver, true );
wp_enqueue_style( 'co-portal-reset', plugins_url( 'portal/portal.css', CLIENTOCTOPUS_DIR . 'clientoctopus.php' ), [], CLIENTOCTOPUS_VERSION );

// Inject runtime data via wp_add_inline_script so no bare <script> tag is needed.
wp_add_inline_script(
	'co-portal',
	'window.coPortalData = ' . wp_json_encode( [
		'page'            => $clientoctopus_portal_page,
		'apiUrl'          => esc_url_raw( rest_url( 'clientoctopus/v1' ) ),
		'nonce'           => $clientoctopus_nonce,
		'isAuthenticated' => ClientOctopus_Portal_Auth::is_authenticated(),
		'clientData'      => $clientoctopus_client_data,
		'businessName'       => $clientoctopus_business_name,
		'businessLogo'       => $clientoctopus_business_logo,
		'brandColor'         => $clientoctopus_brand_color,
		'hideBusinessName'   => $clientoctopus_hide_business_name,
		'verifyToken'     => $clientoctopus_verify_token,
		'pluginUrl'       => CLIENTOCTOPUS_URL,
		'hasProjects'     => $clientoctopus_has_projects,
	] ) . ';',
	'before'
);

// Page title.
$clientoctopus_page_titles = [
	'login'     => 'Login',
	'verify'    => 'Verifying…',
	'dashboard' => 'Dashboard',
	'proposals' => 'Proposals',
	'projects'  => 'Projects',
	'payments'  => 'Payments',
];
$clientoctopus_page_title = $clientoctopus_page_titles[ $clientoctopus_portal_page ] ?? 'Portal';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $clientoctopus_page_title . ' — ' . $clientoctopus_business_name ); ?></title>
<meta name="robots" content="noindex, nofollow">
<?php wp_head(); ?>
</head>
<body>
<div id="co-portal-root"></div>

<?php wp_footer(); ?>
</body>
</html>
