<?php
/**
 * Admin View: Client Octopus Settings
 *
 * General settings page: licence key, Stripe, developer overrides.
 * Handles saving via direct option updates (nonce-verified POST).
 *
 * @package ClientOctopus
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Insufficient permissions.', 'clientoctopus' ) );
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View-scope variables; this file is only included from the admin page callback and never in global scope.

// ── Save handler ──────────────────────────────────────────────────────────────

$saved  = false;
$errors = [];

if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['clientoctopus_settings_nonce'] ) ) {
	if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clientoctopus_settings_nonce'] ) ), 'clientoctopus_save_settings' ) ) {
		// Branding options: available to all plans.
		$fields = [
			'clientoctopus_business_name' => 'sanitize_text_field',
			'clientoctopus_from_name'     => 'sanitize_text_field',
			'clientoctopus_from_email'    => 'sanitize_email',
			'clientoctopus_brand_color'   => 'sanitize_hex_color',
			'clientoctopus_logo_url'      => 'esc_url_raw',
		];

		update_option( 'clientoctopus_hide_business_name', ! empty( $_POST['clientoctopus_hide_business_name'] ) ? '1' : '' );
		update_option( 'clientoctopus_show_powered_by',    ! empty( $_POST['clientoctopus_show_powered_by'] )    ? '1' : '' );

		foreach ( $fields as $option => $sanitizer ) {
			$value = isset( $_POST[ $option ] ) ? call_user_func( $sanitizer, wp_unslash( $_POST[ $option ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via $sanitizer callback registered above.
			update_option( $option, $value );
		}

		// Stripe options: paid plans only — do not overwrite on free to avoid clearing stored keys.
		$_save_owner_id = clientoctopus_get_owner_id( get_current_user_id() );
		if ( clientoctopus_can_user( $_save_owner_id, 'use_payments' ) ) {
			update_option( 'clientoctopus_stripe_publishable_key', sanitize_text_field( wp_unslash( $_POST['clientoctopus_stripe_publishable_key'] ?? '' ) ) );
			update_option( 'clientoctopus_stripe_secret_key',      sanitize_text_field( wp_unslash( $_POST['clientoctopus_stripe_secret_key'] ?? '' ) ) );
			update_option( 'clientoctopus_stripe_webhook_secret',  sanitize_text_field( wp_unslash( $_POST['clientoctopus_stripe_webhook_secret'] ?? '' ) ) );
		}

		// Testimonial options: paid plans only.
		if ( clientoctopus_can_user( $_save_owner_id, 'use_testimonials' ) ) {
			update_option( 'clientoctopus_testimonial_body',      sanitize_textarea_field( wp_unslash( $_POST['clientoctopus_testimonial_body'] ?? '' ) ) );
			update_option( 'clientoctopus_testimonial_url',       esc_url_raw( wp_unslash( $_POST['clientoctopus_testimonial_url'] ?? '' ) ) );
			update_option( 'clientoctopus_testimonial_cta_label', sanitize_text_field( wp_unslash( $_POST['clientoctopus_testimonial_cta_label'] ?? '' ) ) );
			update_option( 'clientoctopus_testimonial_enabled',   ! empty( $_POST['clientoctopus_testimonial_enabled'] ) ? '1' : '' );
		} else {
			update_option( 'clientoctopus_testimonial_enabled', '' );
		}

		$saved = true;
	} else {
		$errors[] = __( 'Security check failed. Please try again.', 'clientoctopus' );
	}
}

// ── Current values ────────────────────────────────────────────────────────────

$pub_key      = get_option( 'clientoctopus_stripe_publishable_key', '' );
$secret_key   = get_option( 'clientoctopus_stripe_secret_key', '' );
$webhook_sec  = get_option( 'clientoctopus_stripe_webhook_secret', '' );

$stripe_mode   = str_starts_with( $secret_key, 'sk_live_' ) ? 'live' : ( $secret_key ? 'test' : '' );
$webhook_url   = rest_url( 'clientoctopus/v1/payments/webhook' );

$business_name        = get_option( 'clientoctopus_business_name', '' );
$hide_business_name   = get_option( 'clientoctopus_hide_business_name', '' );
$show_powered_by      = get_option( 'clientoctopus_show_powered_by',    '' );
$from_name            = get_option( 'clientoctopus_from_name', '' );
$from_email    = get_option( 'clientoctopus_from_email', '' );
$brand_color   = get_option( 'clientoctopus_brand_color', '#6366f1' );
$logo_url      = get_option( 'clientoctopus_logo_url', '' );

$testimonial_enabled    = get_option( 'clientoctopus_testimonial_enabled', '' );
$testimonial_body       = get_option( 'clientoctopus_testimonial_body', '' );
$testimonial_review_url = get_option( 'clientoctopus_testimonial_url', '' );
$testimonial_cta_label  = get_option( 'clientoctopus_testimonial_cta_label', '' );

$cf_owner_id        = clientoctopus_get_owner_id( get_current_user_id() );
$cf_payments_locked = ! clientoctopus_can_user( $cf_owner_id, 'use_payments' );
$cf_is_free         = ! clientoctopus_can_user( $cf_owner_id, 'use_testimonials' );

?>
<div>
<div class="co-settings-wrap">

	<!-- Hero -->
	<div class="co-settings-hero">
		<div>
			<h1 class="co-settings-hero__title"><?php esc_html_e( 'Settings', 'clientoctopus' ); ?></h1>
			<p class="co-settings-hero__sub">
				<?php esc_html_e( 'Configure your Client Octopus licence and payment settings.', 'clientoctopus' ); ?>
			</p>
		</div>
	</div>

	<?php foreach ( $errors as $err ) : ?>
		<div class="co-notice co-notice--error">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
			<?php echo esc_html( $err ); ?>
		</div>
	<?php endforeach; ?>

	<?php if ( $saved ) : ?>
		<div class="co-notice co-notice--success">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
			<?php esc_html_e( 'Settings saved.', 'clientoctopus' ); ?>
		</div>
	<?php endif; ?>

	<form method="POST" action="">
		<?php wp_nonce_field( 'clientoctopus_save_settings', 'clientoctopus_settings_nonce' ); ?>

		<div class="co-settings-grid">

			<!-- ── Branding card ─────────────────────────────────────────────────── -->
			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="13.5" cy="6.5" r=".5" fill="#6366F1"/><circle cx="17.5" cy="10.5" r=".5" fill="#6366F1"/><circle cx="8.5" cy="7.5" r=".5" fill="#6366F1"/><circle cx="6.5" cy="12.5" r=".5" fill="#6366F1"/>
						<path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10c.555 0 1.1-.05 1.629-.145a1 1 0 00.571-1.67 1.002 1.002 0 01.148-1.37c.35-.29.83-.4 1.28-.28l1.95.52a1 1 0 001.23-.97V17c0-2.76-2.24-5-5-5h-1c-.55 0-1-.45-1-1 0-.55.45-1 1-1 .55 0 1-.45 1-1 0-.55-.45-1-1-1z"/>
					</svg>
					<?php esc_html_e( 'Branding', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Customise the name, colour, and logo shown on client-facing proposals and the client portal.', 'clientoctopus' ); ?>
				</p>

				<div class="co-field">
					<label class="co-label" for="co-business-name">
						<?php esc_html_e( 'Business Name', 'clientoctopus' ); ?>
					</label>
					<input
						type="text"
						id="co-business-name"
						name="clientoctopus_business_name"
						class="co-input"
						value="<?php echo esc_attr( $business_name ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. Acme Studio', 'clientoctopus' ); ?>"
						autocomplete="organization"
						spellcheck="false"
					>
					<p class="co-help"><?php esc_html_e( 'Used in email and proposal headers and the client portal header.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-field">
					<label class="co-checkbox-label">
						<input
							type="checkbox"
							name="clientoctopus_hide_business_name"
							value="1"
							<?php checked( '1', $hide_business_name ); ?>
						>
						<?php esc_html_e( 'Hide business name on proposals and emails', 'clientoctopus' ); ?>
					</label>
					<p class="co-help"><?php esc_html_e( 'If your logo already includes your business name, enable this to avoid repeating it beneath the logo on proposals and outgoing emails.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-field">
					<label class="co-checkbox-label">
						<input
							type="checkbox"
							name="clientoctopus_show_powered_by"
							value="1"
							<?php checked( '1', $show_powered_by ); ?>
						>
						<?php esc_html_e( 'Show "Powered by Client Octopus" in emails', 'clientoctopus' ); ?>
					</label>
					<p class="co-help"><?php esc_html_e( 'When enabled, a small "Powered by Client Octopus" badge is added to the footer of all outgoing emails.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-divider"></div>

				<div class="co-field">
					<label class="co-label" for="co-from-name">
						<?php esc_html_e( 'Sender Name', 'clientoctopus' ); ?>
					</label>
					<input
						type="text"
						id="co-from-name"
						name="clientoctopus_from_name"
						class="co-input"
						value="<?php echo esc_attr( $from_name ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. Acme Studio', 'clientoctopus' ); ?>"
						autocomplete="off"
						spellcheck="false"
					>
					<p class="co-help"><?php esc_html_e( 'The display name clients see in their inbox — usually your agency or business name.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-from-email">
						<?php esc_html_e( 'Sender Email', 'clientoctopus' ); ?>
					</label>
					<input
						type="email"
						id="co-from-email"
						name="clientoctopus_from_email"
						class="co-input"
						value="<?php echo esc_attr( $from_email ); ?>"
						placeholder="<?php esc_attr_e( 'hello@youragency.com', 'clientoctopus' ); ?>"
						autocomplete="email"
						spellcheck="false"
					>
					<p class="co-help"><?php esc_html_e( 'The address all Client Octopus emails are sent from. Must be an address you control.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-divider"></div>

				<div class="co-field">
					<label class="co-label" for="co-brand-color-picker">
						<?php esc_html_e( 'Brand Colour', 'clientoctopus' ); ?>
					</label>
					<div class="co-color-row">
						<input
							type="color"
							id="co-brand-color-picker"
							name="clientoctopus_brand_color"
							value="<?php echo esc_attr( $brand_color ); ?>"
						>
						<input
							type="text"
							id="co-brand-color-hex"
							class="co-input"
							value="<?php echo esc_attr( $brand_color ); ?>"
							placeholder="#6366f1"
							maxlength="7"
							spellcheck="false"
							autocomplete="off"
						>
					</div>
					<p class="co-help"><?php esc_html_e( 'Applied to proposal buttons and portal accents.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-logo-url-input">
						<?php esc_html_e( 'Logo URL', 'clientoctopus' ); ?>
					</label>
					<input
						type="url"
						id="co-logo-url-input"
						name="clientoctopus_logo_url"
						class="co-input"
						value="<?php echo esc_attr( $logo_url ); ?>"
						placeholder="https://…/logo.png"
						autocomplete="off"
						spellcheck="false"
					>
					<div class="co-logo-preview-wrap" id="co-logo-preview-wrap" style="<?php echo esc_attr( $logo_url ? '' : 'display:none;' ); ?>">
						<span class="co-logo-preview-label"><?php esc_html_e( 'Preview', 'clientoctopus' ); ?></span>
						<img id="co-logo-preview" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Logo preview', 'clientoctopus' ); ?>">
					</div>
					<p class="co-help"><?php esc_html_e( 'Displayed in proposal headers and portal. Use PNG or JPG — SVG files are not supported by email clients and will not appear in sent proposal emails. Max 180×48px recommended.', 'clientoctopus' ); ?></p>
				</div>
			</div>

			<!-- ── Stripe cards (stacked in one grid column) ────────────────────── -->
			<div style="display:flex;flex-direction:column;gap:20px;">

			<!-- ── Stripe API Keys card ──────────────────────────────────────────── -->
			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>
					</svg>
					<?php esc_html_e( 'Stripe API Keys', 'clientoctopus' ); ?>
					<?php if ( ! $cf_payments_locked && $stripe_mode ) : ?>
						<span class="co-badge co-badge--<?php echo esc_attr( $stripe_mode ); ?>">
							<?php echo esc_html( ucfirst( $stripe_mode ) ); ?> <?php esc_html_e( 'mode', 'clientoctopus' ); ?>
						</span>
					<?php elseif ( ! $cf_payments_locked ) : ?>
						<span class="co-badge co-badge--none"><?php esc_html_e( 'Not configured', 'clientoctopus' ); ?></span>
					<?php endif; ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Find these in your Stripe Dashboard under Developers → API Keys.', 'clientoctopus' ); ?>
				</p>

				<?php if ( $cf_payments_locked ) : ?>
				<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;padding:30px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
						<path d="M7 11V7a5 5 0 0110 0v4"/>
					</svg>
					<p style="margin:0;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Available on Pro &amp; Agency plans', 'clientoctopus' ); ?></p>
					<a href="<?php echo esc_url( function_exists( 'clientoctopus_fs' ) ? clientoctopus_fs()->get_upgrade_url() : 'https://clientoctopus.com/pricing' ); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:7px 18px;background:#6366F1;color:#fff;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;"><?php esc_html_e( 'Upgrade', 'clientoctopus' ); ?></a>
				</div>
				<?php else : ?>

				<div class="co-field">
					<label class="co-label" for="co-pub-key">
						<?php esc_html_e( 'Publishable Key', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(pk_test_… or pk_live_…)', 'clientoctopus' ); ?></span>
					</label>
					<input
						type="text"
						id="co-pub-key"
						name="clientoctopus_stripe_publishable_key"
						class="co-input"
						value="<?php echo esc_attr( $pub_key ); ?>"
						placeholder="pk_test_…"
						autocomplete="off"
						spellcheck="false"
					>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-secret-key">
						<?php esc_html_e( 'Secret Key', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(sk_test_… or sk_live_…)', 'clientoctopus' ); ?></span>
					</label>
					<input
						type="password"
						id="co-secret-key"
						name="clientoctopus_stripe_secret_key"
						class="co-input"
						value="<?php echo esc_attr( $secret_key ); ?>"
						placeholder="sk_test_…"
						autocomplete="new-password"
						spellcheck="false"
					>
					<p class="co-help"><?php esc_html_e( 'Never share your secret key. It is stored encrypted in your database.', 'clientoctopus' ); ?></p>
				</div>

				<?php endif; ?>
			</div>

			<!-- ── Webhook card ──────────────────────────────────────────────────── -->
			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.8 10.72a19.79 19.79 0 01-3.07-8.67A2 2 0 012.71 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.91 9.67a16 16 0 006.29 6.29l1.03-1.04a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/>
					</svg>
					<?php esc_html_e( 'Stripe Webhook', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php
					printf(
						/* translators: 1: <code>checkout.session.completed</code>, 2: <code>checkout.session.async_payment_succeeded</code>, 3: <code>checkout.session.async_payment_failed</code> */
						esc_html__( 'In your Stripe Dashboard go to Developers → Workbench → Webhooks and add a new destination. Select %1$s, %2$s, and %3$s events.', 'clientoctopus' ),
						'<code>checkout.session.completed</code>',
						'<code>checkout.session.async_payment_succeeded</code>',
						'<code>checkout.session.async_payment_failed</code>'
					);
					?>
				</p>

				<?php if ( $cf_payments_locked ) : ?>
				<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;padding:30px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
						<path d="M7 11V7a5 5 0 0110 0v4"/>
					</svg>
					<p style="margin:0;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Available on Pro &amp; Agency plans', 'clientoctopus' ); ?></p>
					<a href="<?php echo esc_url( function_exists( 'clientoctopus_fs' ) ? clientoctopus_fs()->get_upgrade_url() : 'https://clientoctopus.com/pricing' ); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:7px 18px;background:#6366F1;color:#fff;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;"><?php esc_html_e( 'Upgrade', 'clientoctopus' ); ?></a>
				</div>
				<?php else : ?>

				<div class="co-field">
					<label class="co-label" for="co-webhook-url"><?php esc_html_e( 'Webhook Endpoint URL', 'clientoctopus' ); ?></label>
					<div class="co-webhook-row">
						<input
							type="text"
							id="co-webhook-url"
							class="co-input"
							value="<?php echo esc_url( $webhook_url ); ?>"
							readonly
						>
						<button
							type="button"
							class="co-copy-btn"
							onclick="navigator.clipboard.writeText(document.getElementById('co-webhook-url').value).then(function(){this.textContent='Copied!';}.bind(this))"
						><?php esc_html_e( 'Copy', 'clientoctopus' ); ?></button>
					</div>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-webhook-secret">
						<?php esc_html_e( 'Signing Secret', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(whsec_…)', 'clientoctopus' ); ?></span>
					</label>
					<input
						type="password"
						id="co-webhook-secret"
						name="clientoctopus_stripe_webhook_secret"
						class="co-input"
						value="<?php echo esc_attr( $webhook_sec ); ?>"
						placeholder="whsec_…"
						autocomplete="new-password"
						spellcheck="false"
					>
					<p class="co-help">
						<?php esc_html_e( 'Found in your webhook\'s settings page on the Stripe Dashboard. Used to verify events are genuinely from Stripe.', 'clientoctopus' ); ?>
					</p>
				</div>

				<?php endif; ?>
			</div>

			</div><!-- /.stripe-column -->

			<!-- ── Testimonial Emails card ──────────────────────────────────────── -->
			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
					</svg>
					<?php esc_html_e( 'Testimonial Emails', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'When enabled, clients will receive a review request email once their final payment clears. Tick the box below to turn this on. Available on Pro and Agency plans.', 'clientoctopus' ); ?>
				</p>

				<?php if ( $cf_is_free ) : ?>
				<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;padding:30px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
						<path d="M7 11V7a5 5 0 0110 0v4"/>
					</svg>
					<p style="margin:0;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Available on Pro &amp; Agency plans', 'clientoctopus' ); ?></p>
					<a href="<?php echo esc_url( function_exists( 'clientoctopus_fs' ) ? clientoctopus_fs()->get_upgrade_url() : 'https://clientoctopus.com/pricing' ); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:7px 18px;background:#6366F1;color:#fff;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;"><?php esc_html_e( 'Upgrade', 'clientoctopus' ); ?></a>
				</div>
				<?php else : ?>

				<div class="co-field" style="display:flex;align-items:center;gap:10px;">
					<input
						type="checkbox"
						id="co-testimonial-enabled"
						name="clientoctopus_testimonial_enabled"
						value="1"
						<?php checked( $testimonial_enabled, '1' ); ?>
						style="width:18px;height:18px;cursor:pointer;flex-shrink:0;"
					>
					<label for="co-testimonial-enabled" style="margin:0;font-size:13px;font-weight:500;color:#374151;cursor:pointer;">
						<?php esc_html_e( 'Send testimonial request email after final payment', 'clientoctopus' ); ?>
					</label>
				</div>

				<div class="co-divider"></div>

				<div class="co-field">
					<label class="co-label" for="co-testimonial-body">
						<?php esc_html_e( 'Email body copy', 'clientoctopus' ); ?>
					</label>
					<textarea
						id="co-testimonial-body"
						name="clientoctopus_testimonial_body"
						class="co-input"
						rows="4"
						style="height:auto;padding:12px 14px;font-family:-apple-system,sans-serif;letter-spacing:0;resize:vertical;"
						placeholder="<?php esc_attr_e( "It was a pleasure working with you. If you have a moment, we\xe2\x80\x99d love to hear your feedback \xe2\x80\x94 it helps us improve and helps others find us.", 'clientoctopus' ); ?>"
					><?php echo esc_textarea( $testimonial_body ); ?></textarea>
					<p class="co-hint"><?php esc_html_e( 'Plain text. Leave blank to use the default message.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-testimonial-url">
						<?php esc_html_e( 'Review / testimonial URL', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(optional)', 'clientoctopus' ); ?></span>
					</label>
					<input
						type="url"
						id="co-testimonial-url"
						name="clientoctopus_testimonial_url"
						class="co-input"
						value="<?php echo esc_url( $testimonial_review_url ); ?>"
						placeholder="https://g.page/r/your-google-review-link"
						autocomplete="off"
						spellcheck="false"
					>
					<p class="co-hint"><?php esc_html_e( 'Google Reviews, Trustpilot, Clutch, or any custom form. Leave blank to omit the button.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-testimonial-cta-label">
						<?php esc_html_e( 'Button label', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(optional)', 'clientoctopus' ); ?></span>
					</label>
					<input
						type="text"
						id="co-testimonial-cta-label"
						name="clientoctopus_testimonial_cta_label"
						class="co-input"
						value="<?php echo esc_attr( $testimonial_cta_label ); ?>"
						placeholder="<?php esc_attr_e( 'Leave a Review', 'clientoctopus' ); ?>"
						spellcheck="false"
					>
					<p class="co-hint"><?php esc_html_e( 'Text shown on the review button. Defaults to "Leave a Review".', 'clientoctopus' ); ?></p>
				</div>

				<?php endif; ?>
			</div>

		</div><!-- /.co-settings-grid -->

		<button type="submit" class="co-btn-save">
			<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
			<?php esc_html_e( 'Save Settings', 'clientoctopus' ); ?>
		</button>

	</form>
</div>
</div>
