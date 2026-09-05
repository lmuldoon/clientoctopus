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

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table queries on an admin-only, on-demand settings page; table variables use $wpdb->prefix with trusted constants, not user input.

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
$cf_calendar_synced_count = null;
$cf_calendar_sync_failed_providers = [];
$cf_calendar_synced_now   = false;
$cf_calendar_sync_error   = '';

if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['clientoctopus_settings_nonce'] ) ) {
	if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clientoctopus_settings_nonce'] ) ), 'clientoctopus_save_settings' ) ) {
		// Branding options: available to all plans.
		$fields = [
			'clientoctopus_business_name' => 'sanitize_text_field',
			'clientoctopus_from_name'     => 'sanitize_text_field',
			'clientoctopus_from_email'    => 'sanitize_email',
			'clientoctopus_brand_color'   => 'sanitize_hex_color',
			'clientoctopus_button_color'  => 'sanitize_hex_color',
			'clientoctopus_logo_url'      => 'esc_url_raw',
			'clientoctopus_login_bg_url'  => 'esc_url_raw',
		];

		// Checkbox presence is read into a local variable first — Plugin Check's
		// sanitization sniff flags any raw superglobal access syntactically,
		// even inside a harmless !empty() check.
		$_hide_business_name       = ! empty( $_POST['clientoctopus_hide_business_name'] );
		$_show_powered_by          = ! empty( $_POST['clientoctopus_show_powered_by'] );
		$_delete_data_on_uninstall = ! empty( $_POST['clientoctopus_delete_data_on_uninstall'] );
		update_option( 'clientoctopus_hide_business_name',       $_hide_business_name       ? '1' : '' );
		update_option( 'clientoctopus_show_powered_by',          $_show_powered_by          ? '1' : '' );
		update_option( 'clientoctopus_delete_data_on_uninstall', $_delete_data_on_uninstall ? '1' : '' );

		foreach ( $fields as $option => $sanitizer ) {
			// Each value is sanitized via the callback defined in the $fields whitelist above
			// (sanitize_text_field, sanitize_email, sanitize_hex_color, or esc_url_raw).
			$value = isset( $_POST[ $option ] ) ? call_user_func( $sanitizer, wp_unslash( $_POST[ $option ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via $sanitizer from the whitelist on line 30.
			update_option( $option, $value );
		}

		// Payment gateway options: paid plans only — do not overwrite on free to avoid clearing stored keys.
		$_save_owner_id = clientoctopus_get_owner_id( get_current_user_id() );
		if ( clientoctopus_can_user( $_save_owner_id, 'use_payments' ) ) {
			update_option( 'clientoctopus_stripe_publishable_key', sanitize_text_field( wp_unslash( $_POST['clientoctopus_stripe_publishable_key'] ?? '' ) ) );
			update_option( 'clientoctopus_stripe_secret_key',      sanitize_text_field( wp_unslash( $_POST['clientoctopus_stripe_secret_key'] ?? '' ) ) );
			update_option( 'clientoctopus_stripe_webhook_secret',  sanitize_text_field( wp_unslash( $_POST['clientoctopus_stripe_webhook_secret'] ?? '' ) ) );

			$_save_payment_provider = sanitize_text_field( wp_unslash( $_POST['clientoctopus_payment_provider'] ?? '' ) );
			update_option( 'clientoctopus_payment_provider', 'paypal' === $_save_payment_provider ? 'paypal' : 'stripe' );
			update_option( 'clientoctopus_paypal_client_id',     sanitize_text_field( wp_unslash( $_POST['clientoctopus_paypal_client_id'] ?? '' ) ) );
			update_option( 'clientoctopus_paypal_client_secret', sanitize_text_field( wp_unslash( $_POST['clientoctopus_paypal_client_secret'] ?? '' ) ) );
			update_option( 'clientoctopus_paypal_webhook_id',    sanitize_text_field( wp_unslash( $_POST['clientoctopus_paypal_webhook_id'] ?? '' ) ) );
			$_save_paypal_mode = sanitize_text_field( wp_unslash( $_POST['clientoctopus_paypal_mode'] ?? '' ) );
			update_option( 'clientoctopus_paypal_mode', 'live' === $_save_paypal_mode ? 'live' : 'sandbox' );
		}

		// Testimonial options: paid plans only.
		if ( clientoctopus_can_user( $_save_owner_id, 'use_testimonials' ) ) {
			$_testimonial_enabled = ! empty( $_POST['clientoctopus_testimonial_enabled'] );
			update_option( 'clientoctopus_testimonial_body',      sanitize_textarea_field( wp_unslash( $_POST['clientoctopus_testimonial_body'] ?? '' ) ) );
			update_option( 'clientoctopus_testimonial_url',       esc_url_raw( wp_unslash( $_POST['clientoctopus_testimonial_url'] ?? '' ) ) );
			update_option( 'clientoctopus_testimonial_cta_label', sanitize_text_field( wp_unslash( $_POST['clientoctopus_testimonial_cta_label'] ?? '' ) ) );
			update_option( 'clientoctopus_testimonial_enabled',   $_testimonial_enabled ? '1' : '' );
		} else {
			update_option( 'clientoctopus_testimonial_enabled', '' );
		}

		// Call booking: Pro/Agency only.
		if ( clientoctopus_can_user( $_save_owner_id, 'use_booking' ) ) {
			$_booking_enabled = ! empty( $_POST['clientoctopus_booking_enabled'] );
			update_option( 'clientoctopus_booking_enabled', $_booking_enabled ? '1' : '' );
			$_booking_branded_style = ! empty( $_POST['clientoctopus_booking_branded_style'] );
			update_option( 'clientoctopus_booking_branded_style', $_booking_branded_style ? '1' : '' );
			update_option( 'clientoctopus_booking_duration', max( 5, absint( wp_unslash( $_POST['clientoctopus_booking_duration'] ?? '30' ) ) ) );
			update_option( 'clientoctopus_booking_buffer', absint( wp_unslash( $_POST['clientoctopus_booking_buffer'] ?? '15' ) ) );
			update_option( 'clientoctopus_booking_min_notice_hours', absint( wp_unslash( $_POST['clientoctopus_booking_min_notice_hours'] ?? '24' ) ) );
			update_option( 'clientoctopus_booking_max_days_ahead', max( 1, absint( wp_unslash( $_POST['clientoctopus_booking_max_days_ahead'] ?? '30' ) ) ) );
			update_option( 'clientoctopus_booking_meeting_link', esc_url_raw( wp_unslash( $_POST['clientoctopus_booking_meeting_link'] ?? '' ) ) );
			update_option( 'clientoctopus_booking_page_id', absint( wp_unslash( $_POST['clientoctopus_booking_page_id'] ?? '0' ) ) );
			update_option( 'clientoctopus_booking_confirmation_subject', sanitize_text_field( wp_unslash( $_POST['clientoctopus_booking_confirmation_subject'] ?? '' ) ) );
			update_option( 'clientoctopus_booking_confirmation_body', sanitize_textarea_field( wp_unslash( $_POST['clientoctopus_booking_confirmation_body'] ?? '' ) ) );

			$_booking_days = [];
			foreach ( [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ] as $_bk_day ) {
				$_bk_start = sanitize_text_field( wp_unslash( $_POST[ "clientoctopus_booking_day_{$_bk_day}_start" ] ?? '09:00' ) );
				$_bk_end   = sanitize_text_field( wp_unslash( $_POST[ "clientoctopus_booking_day_{$_bk_day}_end" ] ?? '17:00' ) );
				$_booking_days[ $_bk_day ] = [
					'enabled' => ! empty( $_POST[ "clientoctopus_booking_day_{$_bk_day}_enabled" ] ),
					'start'   => preg_match( '/^\d{2}:\d{2}$/', $_bk_start ) ? $_bk_start : '09:00',
					'end'     => preg_match( '/^\d{2}:\d{2}$/', $_bk_end ) ? $_bk_end : '17:00',
				];
			}
			update_option( 'clientoctopus_booking_weekly_hours', wp_json_encode( $_booking_days ) );
		}

		// Calendar Sync: Pro/Agency only. "Disconnect" is a distinct action
		// (a provider name posted via the Disconnect button) rather than a
		// persisted field; Apple connect/update needs a live CalDAV check
		// before saving so a typo'd app-specific password is caught
		// immediately instead of failing silently on the next cron tick.
		if ( clientoctopus_can_user( $_save_owner_id, 'use_calendar_sync' ) ) {
			global $wpdb;

			$_cal_disconnect = sanitize_key( wp_unslash( $_POST['clientoctopus_calendar_disconnect'] ?? '' ) );
			if ( in_array( $_cal_disconnect, [ 'google', 'microsoft', 'apple' ], true ) ) {
				// For Google/Microsoft, the relay is the source of truth for
				// connection status (see clientoctopus_calendar_sync_relay_providers()) —
				// if the relay-side delete fails, clearing the local row anyway
				// would just get silently re-created as "connected" on the next
				// 15-min cron poll, making Disconnect appear to un-do itself.
				$_cal_relay_ok = true;
				if ( in_array( $_cal_disconnect, [ 'google', 'microsoft' ], true ) ) {
					$_cal_relay_key = get_option( 'clientoctopus_license_key', '' );
					if ( $_cal_relay_key ) {
						$_cal_disconnect_response = wp_remote_post( untrailingslashit( CLIENTOCTOPUS_AI_RELAY_URL ) . "/wp-json/co-relay/v1/calendar/{$_cal_disconnect}/disconnect", [
							'timeout' => 15,
							'headers' => [ 'Content-Type' => 'application/json' ],
							'body'    => wp_json_encode( [ 'relay_api_key' => $_cal_relay_key ] ),
						] );
						$_cal_disconnect_data = is_wp_error( $_cal_disconnect_response ) ? null : json_decode( wp_remote_retrieve_body( $_cal_disconnect_response ), true );
						$_cal_relay_ok = is_array( $_cal_disconnect_data ) && ! empty( $_cal_disconnect_data['success'] );
					}
				}

				if ( $_cal_relay_ok ) {
					$wpdb->delete( $wpdb->prefix . 'clientoctopus_calendar_connections', [ 'owner_id' => $_save_owner_id, 'provider' => $_cal_disconnect ] );
					$wpdb->delete( $wpdb->prefix . 'clientoctopus_booking_blocks', [ 'owner_id' => $_save_owner_id, 'source' => $_cal_disconnect ] );
				} else {
					$errors[] = __( 'Could not disconnect — the calendar relay server did not confirm the request. Please try again.', 'clientoctopus' );
				}
			}

			// Apple discovery can find more than one calendar on an account (a
			// shared or subscribed read-only calendar alongside the real one,
			// for example) — with no single well-defined "primary" the way
			// Google/Microsoft have, silently picking the first match risked
			// monitoring the wrong calendar with no way for the owner to
			// tell. When more than one is found, the connection isn't saved
			// yet: the entered credentials + discovered list are held in a
			// short-lived transient and a picker is shown instead (below);
			// picking a calendar re-submits this same form to finish.
			$_apple_pending_key      = 'clientoctopus_apple_pending_' . get_current_user_id();
			$_apple_chosen_url       = sanitize_text_field( wp_unslash( $_POST['clientoctopus_apple_calendar_url'] ?? '' ) );
			$_apple_pending          = get_transient( $_apple_pending_key );

			if ( $_apple_chosen_url && is_array( $_apple_pending ) && ! empty( $_apple_pending['calendars'] ) ) {
				$_apple_chosen = null;
				foreach ( $_apple_pending['calendars'] as $_apple_cal ) {
					if ( $_apple_cal['url'] === $_apple_chosen_url ) {
						$_apple_chosen = $_apple_cal;
						break;
					}
				}

				if ( $_apple_chosen ) {
					$_now = current_time( 'mysql' );
					$wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}clientoctopus_calendar_connections (owner_id, provider, status, account_label, apple_username, apple_password_enc, provider_meta, created_at, updated_at)
						 VALUES (%d, 'apple', 'connected', %s, %s, %s, %s, %s, %s)
						 ON DUPLICATE KEY UPDATE status = 'connected', account_label = VALUES(account_label), apple_username = VALUES(apple_username), apple_password_enc = VALUES(apple_password_enc), provider_meta = VALUES(provider_meta), updated_at = VALUES(updated_at)",
						$_save_owner_id,
						$_apple_pending['username'],
						$_apple_pending['username'],
						$_apple_pending['password_enc'],
						wp_json_encode( [ 'calendar_url' => $_apple_chosen['url'] ] ),
						$_now,
						$_now
					) );
					delete_transient( $_apple_pending_key );
					if ( function_exists( 'clientoctopus_calendar_sync_apple' ) ) {
						clientoctopus_calendar_sync_apple( $_save_owner_id );
					}
				} else {
					$errors[] = __( 'That calendar selection has expired — please reconnect Apple Calendar.', 'clientoctopus' );
					delete_transient( $_apple_pending_key );
				}
			} else {
				$_apple_username = sanitize_text_field( wp_unslash( $_POST['clientoctopus_apple_calendar_username'] ?? '' ) );
				$_apple_password = trim( sanitize_text_field( wp_unslash( $_POST['clientoctopus_apple_calendar_password'] ?? '' ) ) );
				if ( $_apple_username && $_apple_password && class_exists( 'ClientOctopus_CalDAV_Client' ) ) {
					$_caldav_calendars = ( new ClientOctopus_CalDAV_Client( $_apple_username, $_apple_password ) )->discover_calendars();
					if ( is_wp_error( $_caldav_calendars ) ) {
						/* translators: %s is the CalDAV error message */
						$errors[] = sprintf( __( 'Could not connect to Apple Calendar: %s', 'clientoctopus' ), $_caldav_calendars->get_error_message() );
					} elseif ( count( $_caldav_calendars ) > 1 ) {
						set_transient( $_apple_pending_key, [
							'username'     => $_apple_username,
							'password_enc' => clientoctopus_calendar_encrypt( $_apple_password ),
							'calendars'    => $_caldav_calendars,
						], 15 * MINUTE_IN_SECONDS );
						$cf_apple_pending_calendars = $_caldav_calendars;
					} else {
						$_now = current_time( 'mysql' );
						$wpdb->query( $wpdb->prepare(
							"INSERT INTO {$wpdb->prefix}clientoctopus_calendar_connections (owner_id, provider, status, account_label, apple_username, apple_password_enc, provider_meta, created_at, updated_at)
							 VALUES (%d, 'apple', 'connected', %s, %s, %s, %s, %s, %s)
							 ON DUPLICATE KEY UPDATE status = 'connected', account_label = VALUES(account_label), apple_username = VALUES(apple_username), apple_password_enc = VALUES(apple_password_enc), provider_meta = VALUES(provider_meta), updated_at = VALUES(updated_at)",
							$_save_owner_id,
							$_apple_username,
							$_apple_username,
							clientoctopus_calendar_encrypt( $_apple_password ),
							wp_json_encode( [ 'calendar_url' => $_caldav_calendars[0]['url'] ] ),
							$_now,
							$_now
						) );
						if ( function_exists( 'clientoctopus_calendar_sync_apple' ) ) {
							clientoctopus_calendar_sync_apple( $_save_owner_id );
						}
					}
				}
			}

			if ( ! empty( $_POST['clientoctopus_calendar_sync_existing'] ) && function_exists( 'clientoctopus_calendar_backfill_existing_bookings' ) ) {
				$_cf_backfill_result       = clientoctopus_calendar_backfill_existing_bookings( $_save_owner_id );
				$cf_calendar_synced_count  = $_cf_backfill_result['processed'];
				$cf_calendar_sync_failed_providers = $_cf_backfill_result['failed_providers'];
			}

			// Manual trigger for the READ direction (pull busy time in from the
			// connected calendar(s) right now) — otherwise this only happens on
			// the 15-min clientoctopus_15min cron, with no way to confirm a
			// calendar change took effect without just waiting.
			if ( ! empty( $_POST['clientoctopus_calendar_sync_now'] ) ) {
				if ( function_exists( 'clientoctopus_calendar_sync_relay_providers' ) ) {
					$_cf_relay_sync_result = clientoctopus_calendar_sync_relay_providers( $_save_owner_id );
					if ( is_wp_error( $_cf_relay_sync_result ) ) {
						$cf_calendar_sync_error = $_cf_relay_sync_result->get_error_message();
					}
				}
				if ( function_exists( 'clientoctopus_calendar_sync_apple' ) ) {
					clientoctopus_calendar_sync_apple( $_save_owner_id );
				}
				// Only claim success if nothing reported a real failure — a
				// rate-limited or otherwise failed relay poll previously showed
				// the exact same "up to date" notice as a genuine success,
				// making the two indistinguishable from the UI alone.
				$cf_calendar_synced_now = ! $cf_calendar_sync_error;
			}
		}

		// Automation reminders — available on all plans.
		if ( ! class_exists( 'ClientOctopus_Automations' ) ) {
			$_auto_path = CLIENTOCTOPUS_DIR . 'modules/automations/class-automations.php';
			if ( file_exists( $_auto_path ) ) {
				require_once $_auto_path;
			}
		}
		if ( class_exists( 'ClientOctopus_Automations' ) ) {
			$_auto_triggers = [ 'not_viewed', 'not_accepted', 'expiring_soon', 'lead_not_contacted' ];
			foreach ( $_auto_triggers as $_auto_trigger ) {
				$_auto_enabled = ! empty( $_POST[ 'clientoctopus_auto_' . $_auto_trigger . '_enabled' ] );
				ClientOctopus_Automations::save(
					$_save_owner_id,
					$_auto_trigger,
					[
						'enabled'       => $_auto_enabled,
						'delay_days'    => absint( wp_unslash( $_POST[ 'clientoctopus_auto_' . $_auto_trigger . '_delay' ] ?? '3' ) ),
						'email_subject' => sanitize_text_field( wp_unslash( $_POST[ 'clientoctopus_auto_' . $_auto_trigger . '_subject' ] ?? '' ) ),
						'email_body'    => sanitize_textarea_field( wp_unslash( $_POST[ 'clientoctopus_auto_' . $_auto_trigger . '_body' ] ?? '' ) ),
					]
				);
			}
		}

		// Lead capture — available on all plans.
		foreach ( array_merge( clientoctopus_lead_core_fields(), clientoctopus_lead_optional_fields() ) as $_lf_key ) {
			$_lf_enabled  = ! empty( $_POST[ "clientoctopus_lead_field_{$_lf_key}_enabled" ] );
			$_lf_required = ! empty( $_POST[ "clientoctopus_lead_field_{$_lf_key}_required" ] );
			update_option( "clientoctopus_lead_field_{$_lf_key}_enabled", $_lf_enabled ? '1' : '' );
			update_option( "clientoctopus_lead_field_{$_lf_key}_required", $_lf_required ? '1' : '' );
			// Only write the label when the field was actually present in the
			// submission — the labels row is a real, single, always-rendered
			// text input, so a POST missing it entirely means something went
			// wrong with the submission, not that the user wants it cleared.
			// Blindly defaulting to '' here previously meant any partial POST
			// permanently wiped every label to blank.
			if ( isset( $_POST[ "clientoctopus_lead_field_{$_lf_key}_label" ] ) ) {
				update_option( "clientoctopus_lead_field_{$_lf_key}_label", sanitize_text_field( wp_unslash( $_POST[ "clientoctopus_lead_field_{$_lf_key}_label" ] ) ) );
			}
		}
		$_lead_budget_currency = sanitize_text_field( wp_unslash( $_POST['clientoctopus_lead_budget_currency'] ?? 'GBP' ) );
		update_option( 'clientoctopus_lead_budget_currency', in_array( $_lead_budget_currency, [ 'GBP', 'USD', 'EUR', 'CAD', 'AUD' ], true ) ? $_lead_budget_currency : 'GBP' );
		$_lead_budget_thresholds_raw = sanitize_text_field( wp_unslash( $_POST['clientoctopus_lead_budget_thresholds'] ?? '' ) );
		$_lead_budget_thresholds     = implode( ',', array_filter( array_map( 'absint', explode( ',', $_lead_budget_thresholds_raw ) ) ) );
		update_option( 'clientoctopus_lead_budget_thresholds', $_lead_budget_thresholds ?: '1000,5000,10000' );
		update_option( 'clientoctopus_lead_consent_text', sanitize_text_field( wp_unslash( $_POST['clientoctopus_lead_consent_text'] ?? '' ) ) );
		update_option( 'clientoctopus_lead_submit_button_text', sanitize_text_field( wp_unslash( $_POST['clientoctopus_lead_submit_button_text'] ?? '' ) ) );
		update_option( 'clientoctopus_lead_rate_limit_ip', max( 1, absint( wp_unslash( $_POST['clientoctopus_lead_rate_limit_ip'] ?? '5' ) ) ) );
		update_option( 'clientoctopus_lead_rate_limit_global', max( 1, absint( wp_unslash( $_POST['clientoctopus_lead_rate_limit_global'] ?? '50' ) ) ) );
		update_option( 'clientoctopus_lead_archive_days', absint( wp_unslash( $_POST['clientoctopus_lead_archive_days'] ?? '0' ) ) );
		$_lead_captcha_provider = sanitize_text_field( wp_unslash( $_POST['clientoctopus_lead_captcha_provider'] ?? 'none' ) );
		update_option( 'clientoctopus_lead_captcha_provider', 'turnstile' === $_lead_captcha_provider ? 'turnstile' : 'none' );
		update_option( 'clientoctopus_lead_turnstile_site_key', sanitize_text_field( wp_unslash( $_POST['clientoctopus_lead_turnstile_site_key'] ?? '' ) ) );
		update_option( 'clientoctopus_lead_turnstile_secret_key', sanitize_text_field( wp_unslash( $_POST['clientoctopus_lead_turnstile_secret_key'] ?? '' ) ) );
		$_lead_autoreply_enabled = ! empty( $_POST['clientoctopus_lead_autoreply_enabled'] );
		update_option( 'clientoctopus_lead_autoreply_enabled', $_lead_autoreply_enabled ? '1' : '' );
		update_option( 'clientoctopus_lead_autoreply_subject', sanitize_text_field( wp_unslash( $_POST['clientoctopus_lead_autoreply_subject'] ?? '' ) ) );
		update_option( 'clientoctopus_lead_autoreply_body', sanitize_textarea_field( wp_unslash( $_POST['clientoctopus_lead_autoreply_body'] ?? '' ) ) );

		// Turnstile requires BOTH keys to actually verify submissions server-side
		// (see clientoctopus_verify_turnstile() in rest-api/leads.php) — the site
		// key alone is enough to render the widget, so without this warning an
		// owner can save a lead form that looks fully functional to visitors
		// while silently rejecting every submission.
		if ( 'turnstile' === get_option( 'clientoctopus_lead_captcha_provider', 'none' ) && ! get_option( 'clientoctopus_lead_turnstile_secret_key', '' ) ) {
			$errors[] = __( 'Turnstile is enabled but no Secret Key is set — the widget will show on your lead form, but every submission will be rejected until you add a Secret Key.', 'clientoctopus' );
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

$payment_provider     = 'paypal' === get_option( 'clientoctopus_payment_provider', 'stripe' ) ? 'paypal' : 'stripe';
$paypal_client_id     = get_option( 'clientoctopus_paypal_client_id', '' );
$paypal_client_secret = get_option( 'clientoctopus_paypal_client_secret', '' );
$paypal_mode          = 'live' === get_option( 'clientoctopus_paypal_mode', 'sandbox' ) ? 'live' : 'sandbox';
$paypal_webhook_id    = get_option( 'clientoctopus_paypal_webhook_id', '' );
$paypal_webhook_url   = rest_url( 'clientoctopus/v1/payments/paypal/webhook' );
$paypal_configured    = '' !== $paypal_client_id && '' !== $paypal_client_secret;

// Initial SSR visibility for the gateway-specific field cards — JS (settings.js) takes
// over toggling once the Payment Provider <select> changes.
$co_hide_stripe_fields = 'stripe' !== $payment_provider ? ' style="display:none;"' : '';
$co_hide_paypal_fields = 'paypal' !== $payment_provider ? ' style="display:none;"' : '';

$business_name        = get_option( 'clientoctopus_business_name', '' );
$hide_business_name   = get_option( 'clientoctopus_hide_business_name', '' );
$show_powered_by      = get_option( 'clientoctopus_show_powered_by',    '' );
$delete_data_on_uninstall = get_option( 'clientoctopus_delete_data_on_uninstall', '' );
$from_name            = get_option( 'clientoctopus_from_name', '' );
$from_email    = get_option( 'clientoctopus_from_email', '' );
$brand_color   = get_option( 'clientoctopus_brand_color', '#6366f1' );
$button_color  = get_option( 'clientoctopus_button_color', '' );
$logo_url      = get_option( 'clientoctopus_logo_url', '' );
$login_bg_url  = get_option( 'clientoctopus_login_bg_url', '' );

$testimonial_enabled    = get_option( 'clientoctopus_testimonial_enabled', '' );
$testimonial_body       = get_option( 'clientoctopus_testimonial_body', '' );
$testimonial_review_url = get_option( 'clientoctopus_testimonial_url', '' );
$testimonial_cta_label  = get_option( 'clientoctopus_testimonial_cta_label', '' );

$cf_lead_fields             = clientoctopus_lead_field_settings();
$cf_lead_budget_currency    = get_option( 'clientoctopus_lead_budget_currency', 'GBP' );
$cf_lead_budget_thresholds  = get_option( 'clientoctopus_lead_budget_thresholds', '1000,5000,10000' );
$cf_lead_consent_text       = get_option( 'clientoctopus_lead_consent_text', "By submitting, you agree to be contacted about our services." );
$cf_lead_submit_button_text = get_option( 'clientoctopus_lead_submit_button_text', '' );
$cf_lead_rate_limit_ip      = (int) get_option( 'clientoctopus_lead_rate_limit_ip', 5 );
$cf_lead_rate_limit_global  = (int) get_option( 'clientoctopus_lead_rate_limit_global', 50 );
$cf_lead_archive_days       = (int) get_option( 'clientoctopus_lead_archive_days', 0 );
$cf_lead_captcha_provider   = get_option( 'clientoctopus_lead_captcha_provider', 'none' );
$cf_lead_turnstile_site_key = get_option( 'clientoctopus_lead_turnstile_site_key', '' );
$cf_lead_turnstile_secret   = get_option( 'clientoctopus_lead_turnstile_secret_key', '' );
$cf_lead_autoreply_enabled  = get_option( 'clientoctopus_lead_autoreply_enabled', '' );
$cf_lead_autoreply_subject  = get_option( 'clientoctopus_lead_autoreply_subject', '' );
$cf_lead_autoreply_body     = get_option( 'clientoctopus_lead_autoreply_body', '' );
$cf_lead_field_labels       = [
	'name'              => __( 'Name', 'clientoctopus' ),
	'email'             => __( 'Email', 'clientoctopus' ),
	'phone'             => __( 'Phone Number', 'clientoctopus' ),
	'company'           => __( 'Company', 'clientoctopus' ),
	'message'           => __( 'Message', 'clientoctopus' ),
	'budget_range'      => __( 'Budget Range', 'clientoctopus' ),
	'preferred_contact' => __( 'Preferred Contact Method', 'clientoctopus' ),
	'source'            => __( 'How did you hear about us?', 'clientoctopus' ),
];

$cf_owner_id        = clientoctopus_get_owner_id( get_current_user_id() );
$cf_payments_locked = ! clientoctopus_can_user( $cf_owner_id, 'use_payments' );
$cf_is_free         = ! clientoctopus_can_user( $cf_owner_id, 'use_testimonials' );
$cf_booking_locked  = ! clientoctopus_can_user( $cf_owner_id, 'use_booking' );
$cf_calendar_sync_locked = ! clientoctopus_can_user( $cf_owner_id, 'use_calendar_sync' );

$cf_booking_enabled     = get_option( 'clientoctopus_booking_enabled', '' );
$cf_booking_branded_style = get_option( 'clientoctopus_booking_branded_style', '' );
$cf_booking_duration    = (int) get_option( 'clientoctopus_booking_duration', 30 );
$cf_booking_buffer      = (int) get_option( 'clientoctopus_booking_buffer', 15 );
$cf_booking_min_notice  = (int) get_option( 'clientoctopus_booking_min_notice_hours', 24 );
$cf_booking_max_days    = (int) get_option( 'clientoctopus_booking_max_days_ahead', 30 );
$cf_booking_meeting_url = get_option( 'clientoctopus_booking_meeting_link', '' );
$cf_booking_page_id     = (int) get_option( 'clientoctopus_booking_page_id', 0 );
$cf_booking_conf_sub    = get_option( 'clientoctopus_booking_confirmation_subject', '' );
$cf_booking_conf_body   = get_option( 'clientoctopus_booking_confirmation_body', '' );

// Calendar Sync — Google/Microsoft connection status is a locally-cached
// mirror of the relay's own token state (refreshed each 15-min cron tick and
// once synchronously below if we've just returned from the OAuth redirect),
// since the relay — not this site — owns those tokens. Apple's connection
// status is genuinely local, since its credential lives in this site's own
// clientoctopus_calendar_connections row.
// The actual sync + redirect for a fresh OAuth return happens on admin_init
// (see modules/calendar-sync/handlers.php) — before this page's own HTML (or
// even wp-admin's menu/toolbar chrome) has started rendering. Doing it here
// instead was too late: wp_safe_redirect() can't reliably work once the
// surrounding admin page has already sent output, which left a blank content
// area (menu/toolbar shown, redirect silently failed, exit cut off the rest)
// until a manual reload.
$cf_calendar_just_connected = (bool) get_transient( 'clientoctopus_calendar_connected_notice_' . get_current_user_id() );
if ( $cf_calendar_just_connected ) {
	delete_transient( 'clientoctopus_calendar_connected_notice_' . get_current_user_id() );
}
$cf_calendar_connect_error = isset( $_GET['calendar_connect_error'] ) ? sanitize_text_field( wp_unslash( $_GET['calendar_connect_error'] ) ) : '';

global $wpdb;
$cf_calendar_connections = $wpdb->get_results(
	$wpdb->prepare( "SELECT provider, status, account_label FROM {$wpdb->prefix}clientoctopus_calendar_connections WHERE owner_id = %d", $cf_owner_id ),
	OBJECT_K
);
$cf_calendar_connected = static fn( string $provider ): bool =>
	isset( $cf_calendar_connections[ $provider ] ) && 'connected' === $cf_calendar_connections[ $provider ]->status;

$cf_relay_key            = get_option( 'clientoctopus_license_key', '' );
// Nonce-protected: this URL round-trips through the OAuth provider and comes
// back as a plain GET, so without a nonce any logged-in admin who visited
// (or was tricked into visiting) this exact URL could trigger a real sync —
// see the matching wp_verify_nonce() check in the admin_init handler in
// modules/calendar-sync/handlers.php.
$cf_calendar_return_url  = wp_nonce_url( admin_url( 'admin.php?page=clientoctopus-settings&tab=booking&calendar_connected=1' ), 'clientoctopus_calendar_connected' );
$cf_calendar_connect_url = static fn( string $provider ): string => add_query_arg( [
	'relay_api_key' => rawurlencode( $cf_relay_key ),
	'return_url'    => rawurlencode( $cf_calendar_return_url ),
], untrailingslashit( CLIENTOCTOPUS_AI_RELAY_URL ) . "/wp-json/co-relay/v1/calendar/{$provider}/start" );

$cf_apple_username = $cf_calendar_connections['apple']->account_label ?? '';

// $cf_apple_pending_calendars may already be set above by the save handler
// (a multi-calendar discovery that just happened this request) — otherwise
// check for one left over from an earlier POST (e.g. the owner reloaded the
// page before picking), so the picker still renders instead of silently
// reverting to the plain username/password form.
if ( empty( $cf_apple_pending_calendars ) ) {
	$cf_apple_pending = get_transient( 'clientoctopus_apple_pending_' . get_current_user_id() );
	if ( is_array( $cf_apple_pending ) && ! empty( $cf_apple_pending['calendars'] ) ) {
		$cf_apple_pending_calendars = $cf_apple_pending['calendars'];
	}
}

$cf_booking_days_default = [
	'mon' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
	'tue' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
	'wed' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
	'thu' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
	'fri' => [ 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ],
	'sat' => [ 'enabled' => false, 'start' => '09:00', 'end' => '17:00' ],
	'sun' => [ 'enabled' => false, 'start' => '09:00', 'end' => '17:00' ],
];
$cf_booking_days_raw     = get_option( 'clientoctopus_booking_weekly_hours', '' );
$cf_booking_days_decoded = $cf_booking_days_raw ? json_decode( $cf_booking_days_raw, true ) : null;
$cf_booking_days         = is_array( $cf_booking_days_decoded ) ? array_replace_recursive( $cf_booking_days_default, $cf_booking_days_decoded ) : $cf_booking_days_default;

$cf_booking_day_labels = [
	'mon' => __( 'Monday', 'clientoctopus' ),
	'tue' => __( 'Tuesday', 'clientoctopus' ),
	'wed' => __( 'Wednesday', 'clientoctopus' ),
	'thu' => __( 'Thursday', 'clientoctopus' ),
	'fri' => __( 'Friday', 'clientoctopus' ),
	'sat' => __( 'Saturday', 'clientoctopus' ),
	'sun' => __( 'Sunday', 'clientoctopus' ),
];

// Load automation rows (seeded with defaults if first visit).
$cf_automations = [];
if ( ! class_exists( 'ClientOctopus_Automations' ) ) {
	$_cf_auto_path = CLIENTOCTOPUS_DIR . 'modules/automations/class-automations.php';
	if ( file_exists( $_cf_auto_path ) ) {
		require_once $_cf_auto_path;
	}
}
if ( class_exists( 'ClientOctopus_Automations' ) ) {
	$cf_automations = ClientOctopus_Automations::get_all( $cf_owner_id );
}

// ── Tabs ──────────────────────────────────────────────────────────────────────
$cf_tabs = [
	'branding'    => [ 'label' => __( 'Branding', 'clientoctopus' ) ],
	'leads'       => [ 'label' => __( 'Lead Capture', 'clientoctopus' ) ],
	'automations' => [ 'label' => __( 'Automations', 'clientoctopus' ) ],
	'payments'    => [ 'label' => __( 'Payments', 'clientoctopus' ) ],
	'booking'     => [ 'label' => __( 'Booking', 'clientoctopus' ) ],
	'advanced'    => [ 'label' => __( 'Advanced', 'clientoctopus' ) ],
];

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

	<?php if ( $cf_calendar_just_connected ) : ?>
		<div class="co-notice co-notice--success">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
			<?php esc_html_e( 'Calendar connected.', 'clientoctopus' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( null !== $cf_calendar_synced_count ) : ?>
		<div class="co-notice co-notice--success">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
			<?php
			echo esc_html( sprintf(
				/* translators: %d is the number of bookings pushed to the connected calendar(s) */
				_n( 'Synced %d existing booking to your connected calendar.', 'Synced %d existing bookings to your connected calendar(s).', $cf_calendar_synced_count, 'clientoctopus' ),
				$cf_calendar_synced_count
			) );
			?>
		</div>
	<?php endif; ?>

	<?php if ( $cf_calendar_sync_failed_providers ) : ?>
		<div class="co-notice co-notice--error">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
			<?php
			$_cf_provider_labels = [ 'google' => 'Google', 'microsoft' => 'Microsoft', 'apple' => 'Apple' ];
			printf(
				/* translators: %s: comma-separated list of providers a booking failed to push to. */
				esc_html__( 'Some bookings could not be pushed to: %s. This is often temporary (e.g. a brief rate limit) — try "Sync existing bookings" again in a moment.', 'clientoctopus' ),
				esc_html( implode( ', ', array_map( static fn( string $p ): string => $_cf_provider_labels[ $p ] ?? ucfirst( $p ), $cf_calendar_sync_failed_providers ) ) )
			);
			?>
		</div>
	<?php endif; ?>

	<?php if ( $cf_calendar_synced_now ) : ?>
		<div class="co-notice co-notice--success">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
			<?php esc_html_e( 'Calendar checked — availability is up to date.', 'clientoctopus' ); ?>
		</div>
	<?php elseif ( $cf_calendar_sync_error ) : ?>
		<div class="co-notice co-notice--error">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
			<?php
			printf(
				/* translators: %s: the specific sync error returned by the calendar relay. */
				esc_html__( 'Calendar sync did not complete: %s', 'clientoctopus' ),
				esc_html( $cf_calendar_sync_error )
			);
			?>
		</div>
	<?php endif; ?>

	<?php if ( $cf_calendar_connect_error ) : ?>
		<div class="co-notice co-notice--error">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
			<?php
			/* translators: %s is the error message returned by the calendar relay */
			echo esc_html( sprintf( __( 'Could not connect calendar: %s', 'clientoctopus' ), $cf_calendar_connect_error ) );
			?>
		</div>
	<?php endif; ?>

	<form method="POST" action="">
		<?php wp_nonce_field( 'clientoctopus_save_settings', 'clientoctopus_settings_nonce' ); ?>

		<div class="co-settings-tabs" role="tablist">
			<?php foreach ( $cf_tabs as $cf_tab_id => $cf_tab ) : ?>
				<button
					type="button"
					class="co-settings-tab"
					data-tab="<?php echo esc_attr( $cf_tab_id ); ?>"
					role="tab"
				><?php echo esc_html( $cf_tab['label'] ); ?></button>
			<?php endforeach; ?>
		</div>

		<!-- ═══ Tab: Branding ═══════════════════════════════════════════════ -->
		<div class="co-settings-panel" data-panel="branding">

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
						<?php esc_html_e( 'Show "Powered by Client Octopus" in client-facing emails (opt-in, off by default)', 'clientoctopus' ); ?>
					</label>
					<p class="co-help"><?php esc_html_e( 'Off by default. When you check this box and save, a small "Powered by Client Octopus" badge will be added to the footer of outgoing emails sent to your clients.', 'clientoctopus' ); ?></p>
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
					<label class="co-label" for="co-button-color-hex">
						<?php esc_html_e( 'Portal Button Colour', 'clientoctopus' ); ?>
					</label>
					<input
						type="hidden"
						id="co-button-color-value"
						name="clientoctopus_button_color"
						value="<?php echo esc_attr( $button_color ); ?>"
					>
					<div class="co-color-row">
						<input
							type="color"
							id="co-button-color-picker"
							value="<?php echo esc_attr( $button_color ?: $brand_color ); ?>"
						>
						<input
							type="text"
							id="co-button-color-hex"
							class="co-input"
							value="<?php echo esc_attr( $button_color ); ?>"
							placeholder="<?php echo esc_attr( $brand_color ); ?> (brand colour)"
							maxlength="7"
							spellcheck="false"
							autocomplete="off"
						>
						<button type="button" class="co-btn-text" id="co-button-color-clear" style="<?php echo esc_attr( $button_color ? '' : 'display:none;' ); ?>">
							<?php esc_html_e( 'Use brand colour', 'clientoctopus' ); ?>
						</button>
					</div>
					<p class="co-help"><?php esc_html_e( 'Optional. Leave blank to use your Brand Colour for portal buttons. Set this if your brand colour doesn\'t work well as a solid button fill — button text colour is chosen automatically for contrast.', 'clientoctopus' ); ?></p>
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

				<div class="co-field">
					<label class="co-label" for="co-login-bg-url-input">
						<?php esc_html_e( 'Login Background Image', 'clientoctopus' ); ?>
					</label>
					<input
						type="hidden"
						id="co-login-bg-url-input"
						name="clientoctopus_login_bg_url"
						value="<?php echo esc_attr( $login_bg_url ); ?>"
					>
					<div class="co-media-picker-row">
						<button type="button" class="co-btn-secondary" id="co-login-bg-select-btn">
							<?php echo $login_bg_url ? esc_html__( 'Change Image', 'clientoctopus' ) : esc_html__( 'Select Image', 'clientoctopus' ); ?>
						</button>
						<button type="button" class="co-btn-text" id="co-login-bg-remove-btn" style="<?php echo esc_attr( $login_bg_url ? '' : 'display:none;' ); ?>">
							<?php esc_html_e( 'Remove', 'clientoctopus' ); ?>
						</button>
					</div>
					<div class="co-logo-preview-wrap" id="co-login-bg-preview-wrap" style="<?php echo esc_attr( $login_bg_url ? '' : 'display:none;' ); ?>">
						<span class="co-logo-preview-label"><?php esc_html_e( 'Preview', 'clientoctopus' ); ?></span>
						<img id="co-login-bg-preview" src="<?php echo esc_url( $login_bg_url ); ?>" alt="<?php esc_attr_e( 'Login background preview', 'clientoctopus' ); ?>" class="co-login-bg-preview-img">
					</div>
					<p class="co-help"><?php esc_html_e( 'Optional. When set, the portal login screen uses this image as a full-bleed background with a frosted glass login card, instead of your solid brand colour. At least 1920×1080px, landscape recommended.', 'clientoctopus' ); ?></p>
				</div>
			</div>

		</div><!-- /panel: branding -->

		<!-- ═══ Tab: Payments ═══════════════════════════════════════════════ -->
		<div class="co-settings-panel" data-panel="payments">
			<div class="co-settings-stack">

			<?php if ( $cf_payments_locked ) : ?>

			<!-- ── Single consolidated lock message — replaces all Payments cards ── -->
			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 010 20 15.3 15.3 0 010-20z"/>
					</svg>
					<?php esc_html_e( 'Payments', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Accept payments on proposals and invoices via Stripe or PayPal, with a single "Pay Now" button clients always see.', 'clientoctopus' ); ?>
				</p>
				<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;padding:30px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
						<path d="M7 11V7a5 5 0 0110 0v4"/>
					</svg>
					<p style="margin:0;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Available on Pro &amp; Agency plans', 'clientoctopus' ); ?></p>
					<a href="<?php echo esc_url( function_exists( 'clientoctopus_fs' ) ? clientoctopus_fs()->get_upgrade_url() : 'https://clientoctopus.com/pricing' ); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:7px 18px;background:#6366F1;color:#fff;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;"><?php esc_html_e( 'Upgrade', 'clientoctopus' ); ?></a>
				</div>
			</div>

			<?php else : ?>

			<!-- ── Payment Provider card ───────────────────────────────────────────── -->
			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 010 20 15.3 15.3 0 010-20z"/>
					</svg>
					<?php esc_html_e( 'Payment Provider', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Clients always see a single "Pay Now" button — choose which gateway it uses.', 'clientoctopus' ); ?>
				</p>

				<div class="co-field">
					<label class="co-label" for="co-payment-provider"><?php esc_html_e( 'Active Gateway', 'clientoctopus' ); ?></label>
					<select id="co-payment-provider" name="clientoctopus_payment_provider" class="co-input">
						<option value="stripe" <?php selected( $payment_provider, 'stripe' ); ?>><?php esc_html_e( 'Stripe', 'clientoctopus' ); ?></option>
						<option value="paypal" <?php selected( $payment_provider, 'paypal' ); ?>><?php esc_html_e( 'PayPal', 'clientoctopus' ); ?></option>
					</select>
					<p class="co-help"><?php esc_html_e( 'Only the selected gateway needs to be configured below.', 'clientoctopus' ); ?></p>
				</div>
			</div>

			<!-- ── Stripe API Keys card ──────────────────────────────────────────── -->
			<div class="co-card co-gateway-fields co-gateway-fields--stripe"<?php echo $co_hide_stripe_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a hardcoded string constant above, no user input. ?>>
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>
					</svg>
					<?php esc_html_e( 'Stripe API Keys', 'clientoctopus' ); ?>
					<?php if ( $stripe_mode ) : ?>
						<span class="co-badge co-badge--<?php echo esc_attr( $stripe_mode ); ?>">
							<?php echo esc_html( ucfirst( $stripe_mode ) ); ?> <?php esc_html_e( 'mode', 'clientoctopus' ); ?>
						</span>
					<?php else : ?>
						<span class="co-badge co-badge--none"><?php esc_html_e( 'Not configured', 'clientoctopus' ); ?></span>
					<?php endif; ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Find these in your Stripe Dashboard under Developers → API Keys.', 'clientoctopus' ); ?>
				</p>

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
			</div>

			<!-- ── Webhook card ──────────────────────────────────────────────────── -->
			<div class="co-card co-gateway-fields co-gateway-fields--stripe"<?php echo $co_hide_stripe_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a hardcoded string constant above, no user input. ?>>
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
							data-copy-target="co-webhook-url"
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
			</div>

			<!-- ── PayPal API Credentials card ─────────────────────────────────────── -->
			<div class="co-card co-gateway-fields co-gateway-fields--paypal"<?php echo $co_hide_paypal_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a hardcoded string constant above, no user input. ?>>
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>
					</svg>
					<?php esc_html_e( 'PayPal API Credentials', 'clientoctopus' ); ?>
					<?php if ( $paypal_configured ) : ?>
						<span class="co-badge co-badge--<?php echo 'live' === $paypal_mode ? 'live' : 'test'; ?>">
							<?php echo esc_html( ucfirst( $paypal_mode ) ); ?> <?php esc_html_e( 'mode', 'clientoctopus' ); ?>
						</span>
					<?php else : ?>
						<span class="co-badge co-badge--none"><?php esc_html_e( 'Not configured', 'clientoctopus' ); ?></span>
					<?php endif; ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Find these in the PayPal Developer Dashboard under Apps &amp; Credentials.', 'clientoctopus' ); ?>
				</p>

				<div class="co-field">
					<label class="co-label" for="co-paypal-mode"><?php esc_html_e( 'Mode', 'clientoctopus' ); ?></label>
					<select id="co-paypal-mode" name="clientoctopus_paypal_mode" class="co-input">
						<option value="sandbox" <?php selected( $paypal_mode, 'sandbox' ); ?>><?php esc_html_e( 'Sandbox (testing)', 'clientoctopus' ); ?></option>
						<option value="live" <?php selected( $paypal_mode, 'live' ); ?>><?php esc_html_e( 'Live', 'clientoctopus' ); ?></option>
					</select>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-paypal-client-id"><?php esc_html_e( 'Client ID', 'clientoctopus' ); ?></label>
					<input
						type="text"
						id="co-paypal-client-id"
						name="clientoctopus_paypal_client_id"
						class="co-input"
						value="<?php echo esc_attr( $paypal_client_id ); ?>"
						autocomplete="off"
						spellcheck="false"
					>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-paypal-client-secret"><?php esc_html_e( 'Client Secret', 'clientoctopus' ); ?></label>
					<input
						type="password"
						id="co-paypal-client-secret"
						name="clientoctopus_paypal_client_secret"
						class="co-input"
						value="<?php echo esc_attr( $paypal_client_secret ); ?>"
						autocomplete="new-password"
						spellcheck="false"
					>
					<p class="co-help"><?php esc_html_e( 'Never share your client secret. It is stored encrypted in your database.', 'clientoctopus' ); ?></p>
				</div>
			</div>

			<!-- ── PayPal Webhook card ─────────────────────────────────────────────── -->
			<div class="co-card co-gateway-fields co-gateway-fields--paypal"<?php echo $co_hide_paypal_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a hardcoded string constant above, no user input. ?>>
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.8 10.72a19.79 19.79 0 01-3.07-8.67A2 2 0 012.71 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.91 9.67a16 16 0 006.29 6.29l1.03-1.04a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/>
					</svg>
					<?php esc_html_e( 'PayPal Webhook', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php
					printf(
						/* translators: 1: <code>CHECKOUT.ORDER.APPROVED</code>, 2: <code>PAYMENT.CAPTURE.COMPLETED</code> */
						esc_html__( 'In the PayPal Developer Dashboard, open your app and add a webhook pointed at the URL below. Select %1$s and %2$s events, then paste the resulting Webhook ID here.', 'clientoctopus' ),
						'<code>CHECKOUT.ORDER.APPROVED</code>',
						'<code>PAYMENT.CAPTURE.COMPLETED</code>'
					);
					?>
				</p>

				<div class="co-field">
					<label class="co-label" for="co-paypal-webhook-url"><?php esc_html_e( 'Webhook Endpoint URL', 'clientoctopus' ); ?></label>
					<div class="co-webhook-row">
						<input
							type="text"
							id="co-paypal-webhook-url"
							class="co-input"
							value="<?php echo esc_url( $paypal_webhook_url ); ?>"
							readonly
						>
						<button
							type="button"
							class="co-copy-btn"
							data-copy-target="co-paypal-webhook-url"
						><?php esc_html_e( 'Copy', 'clientoctopus' ); ?></button>
					</div>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-paypal-webhook-id"><?php esc_html_e( 'Webhook ID', 'clientoctopus' ); ?></label>
					<input
						type="text"
						id="co-paypal-webhook-id"
						name="clientoctopus_paypal_webhook_id"
						class="co-input"
						value="<?php echo esc_attr( $paypal_webhook_id ); ?>"
						autocomplete="off"
						spellcheck="false"
					>
					<p class="co-help">
						<?php esc_html_e( 'PayPal identifies webhooks by this ID (not a shared secret) — used to verify events are genuinely from PayPal.', 'clientoctopus' ); ?>
					</p>
				</div>
			</div>

			<?php endif; ?>

			</div><!-- /.co-settings-stack -->
		</div><!-- /panel: payments -->

		<!-- ═══ Tab: Lead Capture ═══════════════════════════════════════════ -->
		<div class="co-settings-panel" data-panel="leads">

			<!-- ── Lead Capture card ─────────────────────────────────────────────── -->
			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
					</svg>
					<?php esc_html_e( 'Lead Capture', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Configure the fields shown on your [clientoctopus_lead_form] shortcode, plus anti-spam settings. Available on all plans.', 'clientoctopus' ); ?>
				</p>

				<p class="co-label" style="margin-bottom:10px;"><?php esc_html_e( 'Choose which fields to show, and which are required:', 'clientoctopus' ); ?></p>
				<?php foreach ( $cf_lead_field_labels as $cf_lf_key => $cf_lf_default_label ) : ?>
					<div class="co-field" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
						<label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;color:#374151;cursor:pointer;min-width:110px;">
							<input
								type="checkbox"
								name="clientoctopus_lead_field_<?php echo esc_attr( $cf_lf_key ); ?>_enabled"
								value="1"
								<?php checked( $cf_lead_fields[ $cf_lf_key ]['enabled'] ); ?>
								style="width:16px;height:16px;cursor:pointer;flex-shrink:0;"
							>
							<?php esc_html_e( 'Show', 'clientoctopus' ); ?>
						</label>
						<label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;color:#374151;cursor:pointer;">
							<input
								type="checkbox"
								name="clientoctopus_lead_field_<?php echo esc_attr( $cf_lf_key ); ?>_required"
								value="1"
								<?php checked( $cf_lead_fields[ $cf_lf_key ]['required'] ); ?>
								style="width:16px;height:16px;cursor:pointer;flex-shrink:0;"
							>
							<?php esc_html_e( 'Required', 'clientoctopus' ); ?>
						</label>
						<input
							type="text"
							name="clientoctopus_lead_field_<?php echo esc_attr( $cf_lf_key ); ?>_label"
							class="co-input"
							style="flex:1;min-width:180px;"
							value="<?php echo esc_attr( $cf_lead_fields[ $cf_lf_key ]['label'] ); ?>"
							placeholder="<?php echo esc_attr( $cf_lf_default_label ); ?>"
						>
					</div>
				<?php endforeach; ?>

				<div class="co-field" style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;">
					<div>
						<label class="co-label" for="co-lead-budget-currency"><?php esc_html_e( 'Budget Range currency', 'clientoctopus' ); ?></label>
						<select id="co-lead-budget-currency" name="clientoctopus_lead_budget_currency" class="co-input">
							<?php foreach ( [ 'GBP' => '£', 'USD' => '$', 'EUR' => '€', 'CAD' => 'CA$', 'AUD' => 'A$' ] as $cf_budget_code => $cf_budget_symbol ) : ?>
								<option value="<?php echo esc_attr( $cf_budget_code ); ?>" <?php selected( $cf_lead_budget_currency, $cf_budget_code ); ?>>
									<?php echo esc_html( $cf_budget_code . ' (' . $cf_budget_symbol . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div style="flex:1;min-width:220px;">
						<label class="co-label" for="co-lead-budget-thresholds"><?php esc_html_e( 'Budget Range thresholds', 'clientoctopus' ); ?></label>
						<input
							type="text"
							id="co-lead-budget-thresholds"
							name="clientoctopus_lead_budget_thresholds"
							class="co-input"
							value="<?php echo esc_attr( $cf_lead_budget_thresholds ); ?>"
							placeholder="1000,5000,10000"
						>
					</div>
				</div>
				<p class="co-hint">
					<?php
					printf(
						/* translators: %s is a comma-separated preview of the generated dropdown options */
						esc_html__( 'Comma-separated numbers, low to high. Currently generates: %s', 'clientoctopus' ),
						esc_html( implode( ', ', clientoctopus_lead_budget_options() ) )
					);
					?>
				</p>

				<div class="co-divider"></div>

				<div class="co-field">
					<label class="co-label" for="co-lead-consent-text">
						<?php esc_html_e( 'Consent line', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(shown with a required checkbox; leave blank to omit)', 'clientoctopus' ); ?></span>
					</label>
					<input
						type="text"
						id="co-lead-consent-text"
						name="clientoctopus_lead_consent_text"
						class="co-input"
						value="<?php echo esc_attr( $cf_lead_consent_text ); ?>"
					>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-lead-submit-button-text">
						<?php esc_html_e( 'Submit button text', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(optional)', 'clientoctopus' ); ?></span>
					</label>
					<input
						type="text"
						id="co-lead-submit-button-text"
						name="clientoctopus_lead_submit_button_text"
						class="co-input"
						value="<?php echo esc_attr( $cf_lead_submit_button_text ); ?>"
						placeholder="<?php esc_attr_e( 'Send', 'clientoctopus' ); ?>"
					>
				</div>

				<div class="co-row-2">
					<div class="co-field">
						<label class="co-label" for="co-lead-rate-ip">
							<?php esc_html_e( 'Max submissions per IP, per hour', 'clientoctopus' ); ?>
						</label>
						<input type="number" min="1" id="co-lead-rate-ip" name="clientoctopus_lead_rate_limit_ip" class="co-input" value="<?php echo esc_attr( (string) $cf_lead_rate_limit_ip ); ?>">
					</div>
					<div class="co-field">
						<label class="co-label" for="co-lead-rate-global">
							<?php esc_html_e( 'Max submissions total, per hour', 'clientoctopus' ); ?>
						</label>
						<input type="number" min="1" id="co-lead-rate-global" name="clientoctopus_lead_rate_limit_global" class="co-input" value="<?php echo esc_attr( (string) $cf_lead_rate_limit_global ); ?>">
					</div>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-lead-archive-days">
						<?php esc_html_e( 'Auto-archive new leads after (days)', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(0 = never)', 'clientoctopus' ); ?></span>
					</label>
					<input type="number" min="0" id="co-lead-archive-days" name="clientoctopus_lead_archive_days" class="co-input" value="<?php echo esc_attr( (string) $cf_lead_archive_days ); ?>">
					<p class="co-hint"><?php esc_html_e( 'Archiving only changes status — leads are kept, never deleted automatically.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-divider"></div>

				<div class="co-field">
					<label class="co-label" for="co-lead-captcha-provider">
						<?php esc_html_e( 'CAPTCHA Provider', 'clientoctopus' ); ?>
					</label>
					<select id="co-lead-captcha-provider" name="clientoctopus_lead_captcha_provider" class="co-input">
						<option value="none" <?php selected( $cf_lead_captcha_provider, 'none' ); ?>><?php esc_html_e( 'None', 'clientoctopus' ); ?></option>
						<option value="turnstile" <?php selected( $cf_lead_captcha_provider, 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'clientoctopus' ); ?></option>
					</select>
				</div>

				<div id="co-lead-turnstile-fields" <?php echo 'turnstile' !== $cf_lead_captcha_provider ? 'style="display:none;"' : ''; ?>>
					<div class="co-row-2">
						<div class="co-field">
							<label class="co-label" for="co-lead-turnstile-site-key"><?php esc_html_e( 'Turnstile Site Key', 'clientoctopus' ); ?></label>
							<input type="text" id="co-lead-turnstile-site-key" name="clientoctopus_lead_turnstile_site_key" class="co-input monospace" value="<?php echo esc_attr( $cf_lead_turnstile_site_key ); ?>">
						</div>
						<div class="co-field">
							<label class="co-label" for="co-lead-turnstile-secret-key"><?php esc_html_e( 'Turnstile Secret Key', 'clientoctopus' ); ?></label>
							<input type="password" id="co-lead-turnstile-secret-key" name="clientoctopus_lead_turnstile_secret_key" class="co-input monospace" value="<?php echo esc_attr( $cf_lead_turnstile_secret ); ?>">
						</div>
					</div>
				</div>

				<div class="co-divider"></div>

				<div class="co-field" style="display:flex;align-items:center;gap:10px;">
					<input
						type="checkbox"
						id="co-lead-autoreply-enabled"
						name="clientoctopus_lead_autoreply_enabled"
						value="1"
						<?php checked( $cf_lead_autoreply_enabled, '1' ); ?>
						style="width:18px;height:18px;cursor:pointer;flex-shrink:0;"
					>
					<label for="co-lead-autoreply-enabled" style="margin:0;font-size:13px;font-weight:500;color:#374151;cursor:pointer;">
						<?php esc_html_e( 'Send an automatic reply to the submitter', 'clientoctopus' ); ?>
					</label>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-lead-autoreply-subject">
						<?php esc_html_e( 'Auto-reply subject', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(optional)', 'clientoctopus' ); ?></span>
					</label>
					<input type="text" id="co-lead-autoreply-subject" name="clientoctopus_lead_autoreply_subject" class="co-input" value="<?php echo esc_attr( $cf_lead_autoreply_subject ); ?>" placeholder="<?php esc_attr_e( 'Thanks for reaching out', 'clientoctopus' ); ?>">
				</div>

				<div class="co-field">
					<label class="co-label" for="co-lead-autoreply-body">
						<?php esc_html_e( 'Auto-reply message', 'clientoctopus' ); ?>
						<span><?php esc_html_e( '(optional)', 'clientoctopus' ); ?></span>
					</label>
					<textarea
						id="co-lead-autoreply-body"
						name="clientoctopus_lead_autoreply_body"
						class="co-input"
						rows="4"
						style="height:auto;padding:12px 14px;font-family:-apple-system,sans-serif;letter-spacing:0;resize:vertical;"
						placeholder="<?php esc_attr_e( "Thanks for getting in touch — we've received your message and will be in contact soon.", 'clientoctopus' ); ?>"
					><?php echo esc_textarea( $cf_lead_autoreply_body ); ?></textarea>
					<p class="co-hint"><?php esc_html_e( 'Plain text. This exact message is sent to every submitter — it never includes their own submitted content, to prevent this form being used to relay email to arbitrary addresses.', 'clientoctopus' ); ?></p>
				</div>
			</div>

		</div><!-- /panel: leads -->

		<!-- ═══ Tab: Booking ═══════════════════════════════════════════════ -->
		<div class="co-settings-panel" data-panel="booking">
			<div class="co-settings-stack">

			<?php if ( $cf_booking_locked ) : ?>

			<!-- ── Single consolidated lock message — replaces all Booking cards ── -->
			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
					</svg>
					<?php esc_html_e( 'Booking', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Let leads and visitors book a call directly, with your calendar synced automatically.', 'clientoctopus' ); ?>
				</p>
				<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;padding:30px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
					</svg>
					<p style="margin:0;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Available on Pro &amp; Agency plans', 'clientoctopus' ); ?></p>
					<a href="<?php echo esc_url( function_exists( 'clientoctopus_fs' ) ? clientoctopus_fs()->get_upgrade_url() : 'https://clientoctopus.com/pricing' ); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:7px 18px;background:#6366F1;color:#fff;border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;"><?php esc_html_e( 'Upgrade', 'clientoctopus' ); ?></a>
				</div>
			</div>

			<?php else : ?>

			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
					</svg>
					<?php esc_html_e( 'Call Booking', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Let leads and visitors book a call directly via the [clientoctopus_booking_form] shortcode.', 'clientoctopus' ); ?>
				</p>

				<div class="co-field">
					<label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;color:#374151;cursor:pointer;">
						<input type="checkbox" name="clientoctopus_booking_enabled" value="1" <?php checked( $cf_booking_enabled ); ?> style="width:16px;height:16px;cursor:pointer;">
						<?php esc_html_e( 'Enable Booking', 'clientoctopus' ); ?>
					</label>
				</div>

				<div class="co-field">
					<label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;color:#374151;cursor:pointer;">
						<input type="checkbox" name="clientoctopus_booking_branded_style" value="1" <?php checked( $cf_booking_branded_style ); ?> style="width:16px;height:16px;cursor:pointer;">
						<?php esc_html_e( 'Use Client Octopus styling', 'clientoctopus' ); ?>
					</label>
					<p class="co-hint"><?php esc_html_e( 'Applies a polished, ready-made look to the public booking calendar. Leave unchecked to style it yourself with your site\'s own CSS.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-divider"></div>

				<p class="co-label" style="margin-bottom:10px;"><?php esc_html_e( 'Weekly availability (site timezone):', 'clientoctopus' ); ?></p>
				<?php foreach ( $cf_booking_day_labels as $cf_bk_day => $cf_bk_day_label ) : ?>
					<div class="co-field" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
						<label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;color:#374151;cursor:pointer;min-width:120px;">
							<input
								type="checkbox"
								name="clientoctopus_booking_day_<?php echo esc_attr( $cf_bk_day ); ?>_enabled"
								value="1"
								<?php checked( $cf_booking_days[ $cf_bk_day ]['enabled'] ); ?>
								style="width:16px;height:16px;cursor:pointer;flex-shrink:0;"
							>
							<?php echo esc_html( $cf_bk_day_label ); ?>
						</label>
						<input
							type="time"
							name="clientoctopus_booking_day_<?php echo esc_attr( $cf_bk_day ); ?>_start"
							class="co-input"
							style="width:130px;"
							value="<?php echo esc_attr( $cf_booking_days[ $cf_bk_day ]['start'] ); ?>"
						>
						<span>&ndash;</span>
						<input
							type="time"
							name="clientoctopus_booking_day_<?php echo esc_attr( $cf_bk_day ); ?>_end"
							class="co-input"
							style="width:130px;"
							value="<?php echo esc_attr( $cf_booking_days[ $cf_bk_day ]['end'] ); ?>"
						>
					</div>
				<?php endforeach; ?>

				<div class="co-divider"></div>

				<div class="co-field" style="display:flex;gap:14px;flex-wrap:wrap;">
					<div>
						<label class="co-label" for="co-booking-duration"><?php esc_html_e( 'Call duration (minutes)', 'clientoctopus' ); ?></label>
						<input type="number" min="5" id="co-booking-duration" name="clientoctopus_booking_duration" class="co-input" value="<?php echo esc_attr( (string) $cf_booking_duration ); ?>">
					</div>
					<div>
						<label class="co-label" for="co-booking-buffer"><?php esc_html_e( 'Buffer between calls (minutes)', 'clientoctopus' ); ?></label>
						<input type="number" min="0" id="co-booking-buffer" name="clientoctopus_booking_buffer" class="co-input" value="<?php echo esc_attr( (string) $cf_booking_buffer ); ?>">
					</div>
					<div>
						<label class="co-label" for="co-booking-min-notice"><?php esc_html_e( 'Minimum notice (hours)', 'clientoctopus' ); ?></label>
						<input type="number" min="0" id="co-booking-min-notice" name="clientoctopus_booking_min_notice_hours" class="co-input" value="<?php echo esc_attr( (string) $cf_booking_min_notice ); ?>">
					</div>
					<div>
						<label class="co-label" for="co-booking-max-days"><?php esc_html_e( 'Booking window (days ahead)', 'clientoctopus' ); ?></label>
						<input type="number" min="1" id="co-booking-max-days" name="clientoctopus_booking_max_days_ahead" class="co-input" value="<?php echo esc_attr( (string) $cf_booking_max_days ); ?>">
					</div>
				</div>

				<div class="co-divider"></div>

				<div class="co-field">
					<label class="co-label" for="co-booking-meeting-link"><?php esc_html_e( 'Meeting link', 'clientoctopus' ); ?></label>
					<input type="url" id="co-booking-meeting-link" name="clientoctopus_booking_meeting_link" class="co-input" value="<?php echo esc_attr( $cf_booking_meeting_url ); ?>" placeholder="https://meet.google.com/xxx-xxxx-xxx">
					<p class="co-hint"><?php esc_html_e( 'Your permanent Zoom/Google Meet room link — included in the booking confirmation and reminder emails.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-field">
					<label class="co-label" for="co-booking-page"><?php esc_html_e( 'Booking Page', 'clientoctopus' ); ?></label>
					<?php
					wp_dropdown_pages( [
						'name'              => 'clientoctopus_booking_page_id',
						'id'                => 'co-booking-page',
						'class'             => 'co-input',
						// wp_dropdown_pages() outputs show_option_none without escaping it
						// internally (unlike option_none_value, which it does esc_attr()),
						// so this is escaped here even though it's a static, non-user-supplied string.
						'show_option_none'  => esc_html__( '— Select a page —', 'clientoctopus' ),
						'option_none_value' => '0',
						'selected'          => (int) $cf_booking_page_id,
					] );
					?>
					<p class="co-hint"><?php esc_html_e( 'Create a page containing the [clientoctopus_booking_form] shortcode, then select it here — this is the link sent in the lead-capture confirmation email.', 'clientoctopus' ); ?></p>
				</div>

				<div class="co-divider"></div>

				<div class="co-field">
					<label class="co-label" for="co-booking-conf-subject"><?php esc_html_e( 'Confirmation email subject', 'clientoctopus' ); ?></label>
					<input type="text" id="co-booking-conf-subject" name="clientoctopus_booking_confirmation_subject" class="co-input" value="<?php echo esc_attr( $cf_booking_conf_sub ); ?>" placeholder="<?php esc_attr_e( 'Booking confirmed', 'clientoctopus' ); ?>">
				</div>
				<div class="co-field">
					<label class="co-label" for="co-booking-conf-body"><?php esc_html_e( 'Confirmation email note (optional)', 'clientoctopus' ); ?></label>
					<textarea id="co-booking-conf-body" name="clientoctopus_booking_confirmation_body" class="co-input" rows="3"><?php echo esc_textarea( $cf_booking_conf_body ); ?></textarea>
				</div>
			</div>

			<div class="co-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14l2 2 4-4"/>
					</svg>
					<?php esc_html_e( 'Calendar Sync', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Connect a real calendar so Booking automatically blocks your existing appointments, and confirmed bookings show up on your calendar too.', 'clientoctopus' ); ?>
				</p>

				<?php if ( ! $cf_relay_key ) : ?>
					<p class="co-hint"><?php esc_html_e( 'Add your licence key above before connecting Google or Microsoft.', 'clientoctopus' ); ?></p>
				<?php endif; ?>

				<div class="co-field" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #e2e8f0;">
					<div>
						<p style="margin:0;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Google Calendar', 'clientoctopus' ); ?></p>
						<p class="co-hint" style="margin:2px 0 0;"><?php echo $cf_calendar_connected( 'google' ) ? esc_html__( 'Connected', 'clientoctopus' ) : esc_html__( 'Not connected', 'clientoctopus' ); ?></p>
					</div>
					<?php if ( $cf_calendar_connected( 'google' ) ) : ?>
						<button type="submit" name="clientoctopus_calendar_disconnect" value="google" class="co-btn-secondary"><?php esc_html_e( 'Disconnect', 'clientoctopus' ); ?></button>
					<?php elseif ( $cf_relay_key ) : ?>
						<a href="<?php echo esc_url( $cf_calendar_connect_url( 'google' ) ); ?>" class="co-btn-secondary"><?php esc_html_e( 'Connect Google Calendar', 'clientoctopus' ); ?></a>
					<?php endif; ?>
				</div>

				<div class="co-field" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #e2e8f0;">
					<div>
						<p style="margin:0;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Microsoft 365 / Outlook', 'clientoctopus' ); ?></p>
						<p class="co-hint" style="margin:2px 0 0;"><?php echo $cf_calendar_connected( 'microsoft' ) ? esc_html__( 'Connected', 'clientoctopus' ) : esc_html__( 'Not connected', 'clientoctopus' ); ?></p>
					</div>
					<?php if ( $cf_calendar_connected( 'microsoft' ) ) : ?>
						<button type="submit" name="clientoctopus_calendar_disconnect" value="microsoft" class="co-btn-secondary"><?php esc_html_e( 'Disconnect', 'clientoctopus' ); ?></button>
					<?php elseif ( $cf_relay_key ) : ?>
						<a href="<?php echo esc_url( $cf_calendar_connect_url( 'microsoft' ) ); ?>" class="co-btn-secondary"><?php esc_html_e( 'Connect Microsoft 365', 'clientoctopus' ); ?></a>
					<?php endif; ?>
				</div>

				<div class="co-field" style="padding-top:12px;">
					<p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Apple Calendar (iCloud)', 'clientoctopus' ); ?></p>
					<p class="co-hint" style="margin:0 0 10px;">
						<?php
						printf(
							/* translators: %s is a link to Apple's app-specific password instructions */
							esc_html__( 'Apple has no one-click connect — generate an %s and paste it in below.', 'clientoctopus' ),
							'<a href="https://support.apple.com/en-us/102654" target="_blank" rel="noopener">' . esc_html__( 'app-specific password', 'clientoctopus' ) . '</a>'
						);
						?>
					</p>
					<?php if ( $cf_calendar_connected( 'apple' ) ) : ?>
						<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
							<p style="margin:0;font-size:13px;color:#374151;"><?php echo esc_html( sprintf( /* translators: %s is the connected Apple ID */ __( 'Connected as %s', 'clientoctopus' ), $cf_apple_username ) ); ?></p>
							<button type="submit" name="clientoctopus_calendar_disconnect" value="apple" class="co-btn-secondary"><?php esc_html_e( 'Disconnect', 'clientoctopus' ); ?></button>
						</div>
					<?php elseif ( ! empty( $cf_apple_pending_calendars ) ) : ?>
						<div style="display:flex;gap:10px;flex-wrap:wrap;">
							<select name="clientoctopus_apple_calendar_url" class="co-input" style="flex:1;min-width:200px;">
								<?php foreach ( $cf_apple_pending_calendars as $cf_apple_cal ) : ?>
									<option value="<?php echo esc_attr( $cf_apple_cal['url'] ); ?>"><?php echo esc_html( $cf_apple_cal['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<p class="co-hint"><?php esc_html_e( 'More than one calendar was found on this Apple account — choose the one to sync, then Save Settings below to finish connecting.', 'clientoctopus' ); ?></p>
					<?php else : ?>
						<div style="display:flex;gap:10px;flex-wrap:wrap;">
							<input type="email" name="clientoctopus_apple_calendar_username" class="co-input" style="flex:1;min-width:200px;" placeholder="<?php esc_attr_e( 'Apple ID email', 'clientoctopus' ); ?>">
							<input type="password" name="clientoctopus_apple_calendar_password" class="co-input" style="flex:1;min-width:200px;" placeholder="<?php esc_attr_e( 'App-specific password', 'clientoctopus' ); ?>" autocomplete="off">
						</div>
						<p class="co-hint"><?php esc_html_e( 'Save Settings below to connect.', 'clientoctopus' ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( $cf_calendar_connected( 'google' ) || $cf_calendar_connected( 'microsoft' ) || $cf_calendar_connected( 'apple' ) ) : ?>
					<div class="co-divider"></div>
					<div class="co-field" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
						<div>
							<p style="margin:0;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Sync calendar now', 'clientoctopus' ); ?></p>
							<p class="co-hint" style="margin:2px 0 0;"><?php esc_html_e( 'Busy time from your calendar is normally checked every 15 minutes automatically. Use this to check right away instead of waiting.', 'clientoctopus' ); ?></p>
						</div>
						<button type="submit" name="clientoctopus_calendar_sync_now" value="1" class="co-btn-secondary"><?php esc_html_e( 'Sync now', 'clientoctopus' ); ?></button>
					</div>
					<div class="co-field" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
						<div>
							<p style="margin:0;font-size:13px;font-weight:600;color:#1A1A2E;"><?php esc_html_e( 'Sync existing bookings', 'clientoctopus' ); ?></p>
							<p class="co-hint" style="margin:2px 0 0;"><?php esc_html_e( 'Only newly-confirmed bookings show up on your calendar automatically. Run this once to push any upcoming bookings made before you connected.', 'clientoctopus' ); ?></p>
						</div>
						<button type="submit" name="clientoctopus_calendar_sync_existing" value="1" class="co-btn-secondary"><?php esc_html_e( 'Sync existing bookings', 'clientoctopus' ); ?></button>
					</div>
				<?php endif; ?>
			</div>

			<?php endif; ?>

			</div><!-- /.co-settings-stack -->
		</div><!-- /panel: booking -->

		<!-- ═══ Tab: Automations ═══════════════════════════════════════════ -->
		<div class="co-settings-panel" data-panel="automations">
			<div class="co-settings-stack">

			<!-- ── Automated Reminders ─────────────────────────────────────────────── -->
			<?php
			$cf_auto_labels = [
				'not_viewed'    => [
					'title'       => __( 'Not Viewed', 'clientoctopus' ),
					'description' => __( 'Send a follow-up when a proposal has been sent but not yet opened.', 'clientoctopus' ),
					'delay_label' => __( 'Days after sending before reminder fires', 'clientoctopus' ),
					'delay_hint'  => __( 'Counted from when the proposal was sent.', 'clientoctopus' ),
					'icon'        => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/><line x1="1" y1="1" x2="23" y2="23"/>',
					'merge_tags'  => [ '{client_name}', '{proposal_title}', '{proposal_url}' ],
				],
				'not_accepted'  => [
					'title'       => __( 'Not Accepted', 'clientoctopus' ),
					'description' => __( 'Send a nudge when a proposal has been viewed but not accepted.', 'clientoctopus' ),
					'delay_label' => __( 'Days after viewing before reminder fires', 'clientoctopus' ),
					'delay_hint'  => __( 'Counted from when the client first opened the proposal.', 'clientoctopus' ),
					'icon'        => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>',
					'merge_tags'  => [ '{client_name}', '{proposal_title}', '{proposal_url}' ],
				],
				'expiring_soon' => [
					'title'       => __( 'Expiring Soon', 'clientoctopus' ),
					'description' => __( 'Warn the client before an open proposal expires. Only fires when an expiry date is set.', 'clientoctopus' ),
					'delay_label' => __( 'Days before expiry to send reminder', 'clientoctopus' ),
					'delay_hint'  => __( 'Counted back from the proposal expiry date.', 'clientoctopus' ),
					'icon'        => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
					'merge_tags'  => [ '{client_name}', '{proposal_title}', '{proposal_url}' ],
				],
				'lead_not_contacted' => [
					'title'       => __( 'Lead Not Contacted', 'clientoctopus' ),
					'description' => __( 'Remind yourself when a captured lead is still marked New after a few days. This emails you, not the lead.', 'clientoctopus' ),
					'delay_label' => __( 'Days after submission before reminder fires', 'clientoctopus' ),
					'delay_hint'  => __( 'Counted from when the lead was submitted.', 'clientoctopus' ),
					'icon'        => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
					'merge_tags'  => [ '{lead_name}', '{lead_email}', '{delay_days}' ],
				],
			];
			?>
			<div class="co-auto-card">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
						<path d="M13.73 21a2 2 0 0 1-3.46 0"/>
					</svg>
					<?php esc_html_e( 'Automated Reminders', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Set-and-forget follow-up emails that fire automatically based on proposal activity. Enable any trigger below, customise the message, and Client Octopus handles the rest.', 'clientoctopus' ); ?>
				</p>

				<div style="display:flex;flex-direction:column;gap:12px;">
				<?php foreach ( $cf_auto_labels as $cf_trigger_slug => $cf_trigger_meta ) :
					$cf_auto_row  = $cf_automations[ $cf_trigger_slug ] ?? [];
					$cf_enabled   = ! empty( $cf_auto_row['enabled'] );
					$cf_delay     = (int) ( $cf_auto_row['delay_days'] ?? 3 );
					$cf_subject   = $cf_auto_row['email_subject'] ?? '';
					$cf_body      = $cf_auto_row['email_body'] ?? '';
					$cf_field_id  = 'co-auto-' . esc_attr( str_replace( '_', '-', $cf_trigger_slug ) );
					$cf_toggle_id = $cf_field_id . '-toggle';
				?>
				<div class="co-auto-trigger<?php echo $cf_enabled ? ' is-enabled' : ''; ?>" id="<?php echo esc_attr( $cf_field_id ); ?>-wrap">

					<!-- Header / toggle row -->
					<div class="co-auto-trigger-header">
						<div class="co-auto-trigger-icon">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php echo $cf_enabled ? '#6366F1' : '#9CA3AF'; ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<?php echo $cf_trigger_meta['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG path data from TRIGGERS constant above, no user input ?>
							</svg>
						</div>
						<div class="co-auto-trigger-text">
							<p class="co-auto-trigger-title"><?php echo esc_html( $cf_trigger_meta['title'] ); ?></p>
							<p class="co-auto-trigger-desc"><?php echo esc_html( $cf_trigger_meta['description'] ); ?></p>
						</div>
						<label class="co-toggle" for="<?php echo esc_attr( $cf_toggle_id ); ?>" title="<?php esc_attr_e( 'Enable this reminder', 'clientoctopus' ); ?>">
							<input
								type="checkbox"
								id="<?php echo esc_attr( $cf_toggle_id ); ?>"
								name="clientoctopus_auto_<?php echo esc_attr( $cf_trigger_slug ); ?>_enabled"
								value="1"
								<?php checked( $cf_enabled ); ?>
								onchange="(function(cb){
									var wrap=document.getElementById('<?php echo esc_js( $cf_field_id ); ?>-wrap');
									var body=document.getElementById('<?php echo esc_js( $cf_field_id ); ?>-body-wrap');
									var icon=wrap.querySelector('.co-auto-trigger-icon svg');
									wrap.classList.toggle('is-enabled',cb.checked);
									body.style.display=cb.checked?'':'none';
									icon.setAttribute('stroke',cb.checked?'#6366F1':'#9CA3AF');
								})(this)"
							>
							<span class="co-toggle-slider"></span>
						</label>
					</div>

					<!-- Fields body -->
					<div class="co-auto-trigger-body" id="<?php echo esc_attr( $cf_field_id ); ?>-body-wrap" style="display:<?php echo $cf_enabled ? '' : 'none'; ?>;">

						<div class="co-field">
							<label class="co-label" for="<?php echo esc_attr( $cf_field_id ); ?>-delay">
								<?php echo esc_html( $cf_trigger_meta['delay_label'] ); ?>
							</label>
							<div class="co-auto-delay-row">
								<input
									type="number"
									id="<?php echo esc_attr( $cf_field_id ); ?>-delay"
									name="clientoctopus_auto_<?php echo esc_attr( $cf_trigger_slug ); ?>_delay"
									class="co-input"
									value="<?php echo esc_attr( (string) $cf_delay ); ?>"
									min="1"
									max="30"
								>
								<span class="co-delay-unit"><?php esc_html_e( 'days', 'clientoctopus' ); ?></span>
							</div>
							<p class="co-hint"><?php echo esc_html( $cf_trigger_meta['delay_hint'] ); ?></p>
						</div>

						<div class="co-field">
							<label class="co-label" for="<?php echo esc_attr( $cf_field_id ); ?>-subject">
								<?php esc_html_e( 'Email subject', 'clientoctopus' ); ?>
							</label>
							<input
								type="text"
								id="<?php echo esc_attr( $cf_field_id ); ?>-subject"
								name="clientoctopus_auto_<?php echo esc_attr( $cf_trigger_slug ); ?>_subject"
								class="co-input"
								value="<?php echo esc_attr( $cf_subject ); ?>"
								spellcheck="false"
							>
						</div>

						<div class="co-field">
							<label class="co-label" for="<?php echo esc_attr( $cf_field_id ); ?>-body">
								<?php esc_html_e( 'Email body', 'clientoctopus' ); ?>
							</label>
							<textarea
								id="<?php echo esc_attr( $cf_field_id ); ?>-body"
								name="clientoctopus_auto_<?php echo esc_attr( $cf_trigger_slug ); ?>_body"
								class="co-input"
								rows="5"
							><?php echo esc_textarea( $cf_body ); ?></textarea>
							<p class="co-hint">
								<?php esc_html_e( 'Merge tags you can use:', 'clientoctopus' ); ?>
								<?php foreach ( $cf_trigger_meta['merge_tags'] as $cf_merge_tag ) : ?>
									<code><?php echo esc_html( $cf_merge_tag ); ?></code>&ensp;
								<?php endforeach; ?>
							</p>
						</div>

					</div><!-- /.co-auto-trigger-body -->
				</div><!-- /.co-auto-trigger -->
				<?php endforeach; ?>
				</div><!-- /triggers list -->

			</div><!-- /.co-auto-card -->

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

			</div><!-- /.co-settings-stack -->
		</div><!-- /panel: automations -->

		<!-- ═══ Tab: Advanced ═══════════════════════════════════════════════ -->
		<div class="co-settings-panel" data-panel="advanced">

			<!-- ── Danger Zone card ─────────────────────────────────────────────── -->
			<div class="co-card co-card--danger">
				<p class="co-card__title">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
					</svg>
					<?php esc_html_e( 'Danger Zone', 'clientoctopus' ); ?>
				</p>
				<p class="co-card__desc">
					<?php esc_html_e( 'Controls what happens to your data if this plugin is ever removed from this site.', 'clientoctopus' ); ?>
				</p>

				<div class="co-field">
					<label class="co-checkbox-label">
						<input
							type="checkbox"
							name="clientoctopus_delete_data_on_uninstall"
							value="1"
							<?php checked( '1', $delete_data_on_uninstall ); ?>
						>
						<?php esc_html_e( 'Delete all Client Octopus data when this plugin is deleted', 'clientoctopus' ); ?>
					</label>
					<p class="co-help">
						<?php esc_html_e( 'Off by default. When off (recommended if you might reinstall or upgrade later), deleting the plugin only removes its code — your proposals, clients, projects, invoices, and settings are kept and will be there if you install it again. Turn this on only if you want a permanent, complete wipe of all Client Octopus data the moment the plugin is deleted.', 'clientoctopus' ); ?>
					</p>
				</div>
			</div>

		</div><!-- /panel: advanced -->

		<button type="submit" class="co-btn-save">
			<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
			<?php esc_html_e( 'Save Settings', 'clientoctopus' ); ?>
		</button>

	</form>
</div>
</div>
