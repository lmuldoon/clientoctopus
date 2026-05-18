<?php
/**
 * Client Octopus Client Proposal Template
 *
 * Standalone HTML page served when a client visits /proposals/{token}.
 * Completely bypasses the active WordPress theme — this is a self-contained
 * document viewer page.
 *
 * Variables injected by client-routing.php:
 *   $clientoctopus_proposal_token  string  Sanitised UUID token from the URL.
 *
 * @package ClientOctopus
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- All file-scope variables in this template use the clientoctopus_ prefix.

// Determine whether this is a preview or a standard proposal URL.
$clientoctopus_preview_token  = $clientoctopus_preview_token  ?? '';
$clientoctopus_proposal_token = $clientoctopus_proposal_token ?? '';
$clientoctopus_is_preview        = '' !== $clientoctopus_preview_token;
$clientoctopus_active_token      = $clientoctopus_is_preview ? $clientoctopus_preview_token : $clientoctopus_proposal_token;

if ( empty( $clientoctopus_active_token ) ) {
	wp_die(
		esc_html__( 'Invalid proposal link.', 'clientoctopus' ),
		esc_html__( 'Not Found', 'clientoctopus' ),
		[ 'response' => 404 ]
	);
}

// Payment result only applies on live proposal URLs.
$clientoctopus_payment_result = $clientoctopus_is_preview ? '' : ( $clientoctopus_payment_result ?? '' );
$clientoctopus_session_id     = $clientoctopus_is_preview ? '' : ( $clientoctopus_session_id     ?? '' );

// ── Client email (for success page personalisation) ───────────────────────────
$clientoctopus_client_email = '';
if ( ! $clientoctopus_is_preview && ! empty( $clientoctopus_payment_result ) && class_exists( 'ClientOctopus_Proposal_Client' ) ) {
	$_proposal_row = ClientOctopus_Proposal_Client::get_by_token( $clientoctopus_proposal_token );
	if ( ! is_wp_error( $_proposal_row ) ) {
		$clientoctopus_client_email = $_proposal_row['client_email'] ?? '';
	}
}

// ── Business branding ─────────────────────────────────────────────────────────
$clientoctopus_business_name        = get_bloginfo( 'name' );
$clientoctopus_business_logo        = esc_url( get_option( 'clientoctopus_logo_url', '' ) );
$clientoctopus_brand_color          = sanitize_hex_color( get_option( 'clientoctopus_brand_color', '#6366F1' ) ) ?: '#6366F1';
$clientoctopus_hide_business_name   = (bool) get_option( 'clientoctopus_hide_business_name', '' );

// ── Asset URLs ────────────────────────────────────────────────────────────────
$clientoctopus_build_dir     = CLIENTOCTOPUS_DIR . 'build/';
$clientoctopus_build_url     = CLIENTOCTOPUS_URL . 'build/';
$clientoctopus_asset_file    = $clientoctopus_build_dir . 'client.asset.php';
$clientoctopus_asset         = file_exists( $clientoctopus_asset_file ) ? require $clientoctopus_asset_file : [ 'version' => CLIENTOCTOPUS_VERSION ];
$clientoctopus_script_ver    = $clientoctopus_asset['version'] ?? CLIENTOCTOPUS_VERSION;

$clientoctopus_script_url    = $clientoctopus_build_url . 'client.js';
$clientoctopus_style_url     = $clientoctopus_build_url . 'client.css';
$clientoctopus_has_css       = file_exists( $clientoctopus_build_dir . 'client.css' );

// The client bundle must exist.
if ( ! file_exists( $clientoctopus_build_dir . 'client.js' ) ) {
	wp_die(
		esc_html__( 'Proposal viewer assets not built. Please contact the site administrator.', 'clientoctopus' ),
		esc_html__( 'Configuration Error', 'clientoctopus' ),
		[ 'response' => 500 ]
	);
}

// ── Favicon ───────────────────────────────────────────────────────────────────
$clientoctopus_favicon_url = get_site_icon_url( 32 );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">

	<title><?php echo esc_html( $clientoctopus_business_name ); ?> &mdash; <?php esc_html_e( 'Proposal', 'clientoctopus' ); ?></title>

	<?php if ( $clientoctopus_favicon_url ) : ?>
		<link rel="icon" href="<?php echo esc_url( $clientoctopus_favicon_url ); ?>">
	<?php endif; ?>

	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone template, wp_enqueue_style() cannot be used outside WP head. ?>
	<link rel="stylesheet" href="<?php echo esc_url( plugins_url( 'client/client.css', CLIENTOCTOPUS_DIR . 'clientoctopus.php' ) ); ?>?v=<?php echo esc_attr( CLIENTOCTOPUS_VERSION ); ?>">

	<?php if ( $clientoctopus_has_css ) : ?>
		<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone template, wp_enqueue_style() cannot be used outside WP head. ?>
		<link rel="stylesheet" href="<?php echo esc_url( $clientoctopus_style_url ); ?>?v=<?php echo esc_attr( $clientoctopus_script_ver ); ?>">
	<?php endif; ?>
</head>
<body>

	<div id="co-client-root">
		<!-- Pre-hydration loading indicator -->
		<div class="co-preload">
			<div class="co-preload__spinner"></div>
			<span><?php esc_html_e( 'Loading your proposal&hellip;', 'clientoctopus' ); ?></span>
		</div>
	</div>

	<?php
	$clientoctopus_script_deps = $clientoctopus_asset['dependencies'] ?? [];
	if ( ! in_array( 'wp-element', $clientoctopus_script_deps, true ) ) {
		$clientoctopus_script_deps[] = 'wp-element';
	}

	wp_enqueue_script( 'co-client', $clientoctopus_script_url, $clientoctopus_script_deps, $clientoctopus_script_ver, false );

	wp_add_inline_script(
		'co-client',
		'window.coClientData = ' . wp_json_encode( [
			'apiUrl'          => rest_url( 'clientoctopus/v1/' ),
			'token'           => $clientoctopus_active_token,
			'businessName'    => $clientoctopus_business_name,
			'businessLogo'    => $clientoctopus_business_logo,
			'hideBusinessName' => $clientoctopus_hide_business_name,
			'brandColor'      => $clientoctopus_brand_color,
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'pageType'        => $clientoctopus_is_preview ? 'preview' : ( $clientoctopus_payment_result ?: 'proposal' ),
			'sessionId'       => $clientoctopus_session_id,
			'clientEmail'     => $clientoctopus_client_email,
			'isPortalClient'  => class_exists( 'ClientOctopus_Portal_Auth' ) && ClientOctopus_Portal_Auth::is_authenticated(),
			'pluginLogoUrl'   => esc_url( CLIENTOCTOPUS_URL . 'assets/images/logo-inline.svg' ),
		] ) . ';',
		'before'
	);

	wp_print_scripts();
	?>

</body>
</html>
