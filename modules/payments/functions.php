<?php
/**
 * Shared payment helper functions (provider-agnostic).
 *
 * @package ClientOctopus\Payments
 * @since   1.2.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'clientoctopus_min_charge_amount' ) ) {
	/**
	 * Minimum chargeable amount for a currency, in that currency's smallest unit
	 * (e.g. cents/pence) — both Stripe and PayPal reject charges below a small
	 * per-currency floor. Single source of truth (previously two separate,
	 * inconsistent lists lived in rest-api/invoices.php and rest-api/payments.php).
	 *
	 * @param string $currency ISO 4217 currency code, any case.
	 * @param string $provider 'stripe' | 'paypal'. Reserved for provider-specific
	 *                         overrides; both gateways currently use the same floor.
	 *
	 * @return int
	 */
	function clientoctopus_min_charge_amount( string $currency, string $provider = 'stripe' ): int {
		$currency        = strtolower( $currency );
		$higher_minimum  = [ 'usd', 'aud', 'cad', 'sgd', 'hkd', 'jpy', 'krw' ];

		return in_array( $currency, $higher_minimum, true ) ? 50 : 30;
	}
}
