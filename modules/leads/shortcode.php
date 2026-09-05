<?php
/**
 * Lead Capture Shortcode
 *
 * [clientoctopus_lead_form] — the plugin's first add_shortcode() call.
 * Renders a plain-JS (not React) embed, deliberately lightweight since this
 * loads on arbitrary third-party marketing pages, not the plugin's own
 * admin/portal surfaces.
 *
 * @package ClientOctopus
 * @since   1.3.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'clientoctopus_lead_form', 'clientoctopus_render_lead_form' );

add_action( 'wp_enqueue_scripts', static function (): void {
	global $post;
	if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( $post->post_content, 'clientoctopus_lead_form' ) ) {
		return;
	}

	wp_enqueue_style( 'co-lead-form', CLIENTOCTOPUS_URL . 'assets/css/lead-form.css', [], CLIENTOCTOPUS_VERSION );
	wp_enqueue_script( 'co-lead-form', CLIENTOCTOPUS_URL . 'assets/js/lead-form.js', [], CLIENTOCTOPUS_VERSION, true );
	wp_localize_script( 'co-lead-form', 'coLeadFormData', [
		'apiUrl' => esc_url_raw( rest_url( 'clientoctopus/v1/leads/submit/' ) ),
	] );

	if ( 'turnstile' === get_option( 'clientoctopus_lead_captcha_provider', 'none' )
		&& get_option( 'clientoctopus_lead_turnstile_site_key', '' ) ) {
		// phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- Cloudflare Turnstile CAPTCHA widget must load from Cloudflare's own domain to function (cannot be self-hosted); opt-in only when the owner configures a site key, and disclosed in readme.txt's External Services section — same category as the Google reCAPTCHA integrations common across WP.org plugins.
		wp_enqueue_script( 'co-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], CLIENTOCTOPUS_VERSION, true );
	}
} );

function clientoctopus_render_lead_form(): string {
	require_once CLIENTOCTOPUS_DIR . 'rest-api/leads.php';

	$field_settings = clientoctopus_lead_field_settings();
	$consent_text   = (string) get_option( 'clientoctopus_lead_consent_text', '' );
	$captcha        = get_option( 'clientoctopus_lead_captcha_provider', 'none' );
	$site_key       = (string) get_option( 'clientoctopus_lead_turnstile_site_key', '' );

	ob_start();
	?>
	<form class="co-lead-form" method="post" novalidate>
		<?php
		$field_order = array_merge( clientoctopus_lead_core_fields(), clientoctopus_lead_optional_fields() );
		foreach ( $field_order as $key ) :
			$field = $field_settings[ $key ];
			if ( ! $field['enabled'] ) {
				continue;
			}
			$input_id = 'co-lead-' . esc_attr( $key );
			$input_type = 'email' === $key ? 'email' : 'text';
			?>
			<div class="co-lead-form__row">
				<label class="co-lead-form__label" for="<?php echo esc_attr( $input_id ); ?>">
					<?php echo esc_html( $field['label'] ); ?>
					<?php if ( $field['required'] ) : ?><span aria-hidden="true">*</span><?php endif; ?>
				</label>
				<?php if ( 'message' === $key ) : ?>
					<textarea class="co-lead-form__input co-lead-form__textarea" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="4" <?php echo $field['required'] ? 'required' : ''; ?>></textarea>
				<?php elseif ( 'budget_range' === $key ) : ?>
					<select class="co-lead-form__input" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $key ); ?>" <?php echo $field['required'] ? 'required' : ''; ?>>
						<option value=""><?php esc_html_e( 'Select a range...', 'clientoctopus' ); ?></option>
						<?php foreach ( clientoctopus_lead_budget_options() as $budget_option ) : ?>
							<option value="<?php echo esc_attr( $budget_option ); ?>"><?php echo esc_html( $budget_option ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input class="co-lead-form__input" type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $key ); ?>" <?php echo $field['required'] ? 'required' : ''; ?>>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<?php if ( $consent_text ) : ?>
			<div class="co-lead-form__row co-lead-form__row--checkbox">
				<label class="co-lead-form__checkbox-label">
					<input type="checkbox" name="consent" value="1" required>
					<span><?php echo esc_html( $consent_text ); ?></span>
				</label>
			</div>
		<?php endif; ?>

		<?php if ( 'turnstile' === $captcha && $site_key ) : ?>
			<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $site_key ); ?>"></div>
		<?php endif; ?>

		<!-- Honeypot — hidden from real visitors via CSS, left empty by them; bots that
		     fill every field submit this too and are rejected server-side. -->
		<div class="co-lead-form__honeypot" aria-hidden="true">
			<label for="co-lead-website">Website</label>
			<input type="text" id="co-lead-website" name="website" tabindex="-1" autocomplete="off">
		</div>
		<input type="hidden" name="rendered_at" value="<?php echo esc_attr( (string) time() ); ?>">

		<div class="co-lead-form__row">
			<button type="submit" class="co-lead-form__submit"><?php echo esc_html( get_option( 'clientoctopus_lead_submit_button_text', '' ) ?: __( 'Send', 'clientoctopus' ) ); ?></button>
		</div>

		<div class="co-lead-form__message" role="status" aria-live="polite"></div>
	</form>
	<?php
	return (string) ob_get_clean();
}
