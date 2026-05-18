<?php
/**
 * Clients Admin Page
 *
 * @package ClientOctopus
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_clientoctopus' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'clientoctopus' ) );
}
?>
<div class="co-clients-page">
	<div id="co-clients-root">
		<div id="co-loading-fallback" style="
			display: flex;
			align-items: center;
			justify-content: center;
			min-height: 320px;
			font-family: 'Archivo', -apple-system, sans-serif;
		">
			<div style="text-align: center; color: #64748B;">
				<svg width="40" height="40" viewBox="0 0 24 24" fill="none"
					xmlns="http://www.w3.org/2000/svg"
					style="margin: 0 auto 16px; display: block; animation: co-spin 1s linear infinite;">
					<circle cx="12" cy="12" r="10" stroke="#E2E8F0" stroke-width="2.5"/>
					<path d="M12 2a10 10 0 0 1 10 10" stroke="#6366F1" stroke-width="2.5" stroke-linecap="round"/>
				</svg>
				<p style="margin: 0; font-size: 14px;">
					<?php esc_html_e( 'Loading Clients…', 'clientoctopus' ); ?>
				</p>
			</div>
		</div>
	</div>
</div>
