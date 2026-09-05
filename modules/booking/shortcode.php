<?php
/**
 * Call Booking Shortcode (Pro/Agency)
 *
 * [clientoctopus_booking_form] — a cold, standalone booking widget. Deliberately
 * reached only via a link (the lead-capture confirmation email's "Pick a Time
 * to Talk" button, or the owner's own nav menu/marketing links) rather than
 * offered inline right after lead-form submission — see modules/leads/handlers.php
 * for why: the click itself is what proves the visitor's email address is
 * real and reachable, which matters more here than on an ordinary lead (a bad
 * email on a lead is a useless row; a bad email on a booking wastes a real
 * calendar slot and produces a no-show).
 *
 * @package ClientOctopus
 * @since   1.4.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'clientoctopus_booking_form', 'clientoctopus_render_booking_form' );

add_action( 'wp_enqueue_scripts', static function (): void {
	global $post;
	if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( $post->post_content, 'clientoctopus_booking_form' ) ) {
		return;
	}

	wp_enqueue_style( 'co-booking', CLIENTOCTOPUS_URL . 'assets/css/booking.css', [], CLIENTOCTOPUS_VERSION );

	// Optional branded skin — layered on top of the structural stylesheet
	// above (same selectors, loaded after, so it wins the cascade without
	// !important) rather than a full standalone theme, so it only needs to
	// carry color/type/shadow overrides, not re-implement layout. Off by
	// default so existing embeds never change look without the owner opting in.
	if ( get_option( 'clientoctopus_booking_branded_style', '' ) ) {
		wp_enqueue_style( 'co-booking-theme', CLIENTOCTOPUS_URL . 'assets/css/booking-theme.css', [ 'co-booking' ], CLIENTOCTOPUS_VERSION );
	}

	wp_enqueue_script( 'co-booking', CLIENTOCTOPUS_URL . 'assets/js/booking-widget.js', [], CLIENTOCTOPUS_VERSION, true );
	wp_localize_script( 'co-booking', 'coBookingData', [
		'apiUrl'               => esc_url_raw( rest_url( 'clientoctopus/v1/' ) ),
		// The calendar's "today"/day-of-week must match the SITE's timezone
		// (what the server uses to interpret availability requests), not the
		// visitor's browser timezone — otherwise a day that looks open/closed
		// in the visitor's calendar can disagree with the server's answer.
		'siteUtcOffsetMinutes' => (int) round( ( new DateTime( 'now', wp_timezone() ) )->getOffset() / 60 ),
	] );
} );

function clientoctopus_render_booking_form(): string {
	require_once CLIENTOCTOPUS_DIR . 'rest-api/booking.php';

	$settings = clientoctopus_booking_settings();
	$owner_id = (int) get_option( 'clientoctopus_owner_user_id', 0 );
	if ( ! $settings['enabled'] || clientoctopus_booking_plan_error( $owner_id ) ) {
		return '<p class="co-booking-disabled">' . esc_html__( 'Booking is not currently available.', 'clientoctopus' ) . '</p>';
	}

	$enabled_days = implode( ',', array_keys( array_filter(
		clientoctopus_booking_weekly_hours(),
		static fn( array $day ): bool => $day['enabled']
	) ) );

	ob_start();
	?>
	<div class="co-booking" data-duration="<?php echo esc_attr( (string) $settings['duration'] ); ?>" data-max-days-ahead="<?php echo esc_attr( (string) $settings['max_days_ahead'] ); ?>" data-enabled-days="<?php echo esc_attr( $enabled_days ); ?>">
		<div class="co-booking__step co-booking__step--dates">
			<div class="co-booking__dates" aria-label="<?php esc_attr_e( 'Choose a date', 'clientoctopus' ); ?>"></div>
		</div>

		<div class="co-booking__step co-booking__step--slots" hidden>
			<button type="button" class="co-booking__back"><?php esc_html_e( '← Back', 'clientoctopus' ); ?></button>
			<div class="co-booking__slots"></div>
		</div>

		<form class="co-booking__step co-booking__step--details" hidden novalidate>
			<button type="button" class="co-booking__back"><?php esc_html_e( '← Back', 'clientoctopus' ); ?></button>
			<p class="co-booking__selected-slot"></p>

			<div class="co-booking__row">
				<label for="co-booking-name"><?php esc_html_e( 'Name', 'clientoctopus' ); ?></label>
				<input type="text" id="co-booking-name" name="name" required>
			</div>
			<div class="co-booking__row">
				<label for="co-booking-email"><?php esc_html_e( 'Email', 'clientoctopus' ); ?></label>
				<input type="email" id="co-booking-email" name="email" required>
			</div>
			<div class="co-booking__row">
				<label for="co-booking-phone"><?php esc_html_e( 'Phone (optional)', 'clientoctopus' ); ?></label>
				<input type="text" id="co-booking-phone" name="phone">
			</div>
			<div class="co-booking__row">
				<label for="co-booking-message"><?php esc_html_e( 'Anything you\'d like us to know? (optional)', 'clientoctopus' ); ?></label>
				<textarea id="co-booking-message" name="message" rows="3"></textarea>
			</div>

			<!-- Honeypot — hidden from real visitors via CSS. -->
			<div class="co-booking__honeypot" aria-hidden="true">
				<label for="co-booking-website">Website</label>
				<input type="text" id="co-booking-website" name="website" tabindex="-1" autocomplete="off">
			</div>
			<input type="hidden" name="rendered_at" value="<?php echo esc_attr( (string) time() ); ?>">

			<button type="submit" class="co-booking__submit"><?php esc_html_e( 'Confirm Booking', 'clientoctopus' ); ?></button>
		</form>

		<div class="co-booking__step co-booking__step--confirmed" hidden>
			<p class="co-booking__confirmed-message"></p>
		</div>

		<div class="co-booking__step co-booking__step--cancelled" hidden>
			<p><?php esc_html_e( 'Your booking has been cancelled.', 'clientoctopus' ); ?></p>
		</div>

		<div class="co-booking__message" role="status" aria-live="polite"></div>
	</div>
	<?php
	return (string) ob_get_clean();
}
