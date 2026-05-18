<?php
/**
 * Setup wizard mount point.
 *
 * React takes over from here. The WP admin chrome is hidden by the
 * SetupWizard component via body class manipulation on mount.
 *
 * @package ClientOctopus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="co-setup-root" class="co-setup-page"></div>
