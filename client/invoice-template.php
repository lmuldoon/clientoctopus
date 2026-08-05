<?php
/**
 * Client Invoice Template
 *
 * Standalone HTML page served when a client visits /invoices/{token}.
 * Completely bypasses the active WordPress theme.
 *
 * CSS and scripts are enqueued via wp_enqueue_style() / wp_enqueue_script()
 * before the HTML is output, then printed via wp_print_styles() /
 * wp_print_scripts(). This satisfies WordPress.org's requirement to use the
 * built-in enqueue API even for standalone pages.
 *
 * Variables injected by client-routing.php:
 *   $clientoctopus_invoice_token  string  Sanitised UUID token from the URL.
 *   $clientoctopus_payment_result string  'success' | 'cancel' | ''
 *   $clientoctopus_session_id     string  Stripe session_id query param (read-only).
 *
 * @package ClientOctopus
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- All file-scope variables use the clientoctopus_ prefix.

$clientoctopus_invoice_token  = $clientoctopus_invoice_token  ?? '';
$clientoctopus_payment_result = $clientoctopus_payment_result ?? '';
$clientoctopus_session_id     = $clientoctopus_session_id     ?? '';

if ( empty( $clientoctopus_invoice_token ) ) {
	wp_die(
		esc_html__( 'Invalid invoice link.', 'clientoctopus' ),
		esc_html__( 'Not Found', 'clientoctopus' ),
		[ 'response' => 404 ]
	);
}

// Load Invoice class if needed.
if ( ! class_exists( 'ClientOctopus_Invoice' ) ) {
	$clientoctopus_invoice_cls = CLIENTOCTOPUS_DIR . 'modules/invoices/class-invoice.php';
	if ( file_exists( $clientoctopus_invoice_cls ) ) {
		require_once $clientoctopus_invoice_cls;
	}
}

// ── Write-through: mark invoice paid if returning from successful Stripe checkout ─
if ( 'success' === $clientoctopus_payment_result && $clientoctopus_session_id && class_exists( 'ClientOctopus_Invoice' ) ) {
	$clientoctopus_stripe_cls = CLIENTOCTOPUS_DIR . 'modules/payments/class-stripe.php';
	if ( ! class_exists( 'ClientOctopus_Stripe' ) && file_exists( $clientoctopus_stripe_cls ) ) {
		require_once $clientoctopus_stripe_cls;
	}
	if ( class_exists( 'ClientOctopus_Stripe' ) && ClientOctopus_Stripe::is_configured() ) {
		$clientoctopus_stripe_session = ClientOctopus_Stripe::retrieve_session( $clientoctopus_session_id );
		if ( ! is_wp_error( $clientoctopus_stripe_session ) && 'paid' === ( $clientoctopus_stripe_session['payment_status'] ?? '' ) ) {
			if ( function_exists( 'clientoctopus_handle_checkout_complete' ) ) {
				clientoctopus_handle_checkout_complete( $clientoctopus_stripe_session );
			}
		}
	}
}

$clientoctopus_invoice = class_exists( 'ClientOctopus_Invoice' )
	? ClientOctopus_Invoice::get_by_token( $clientoctopus_invoice_token )
	: null;

if ( ! $clientoctopus_invoice || is_wp_error( $clientoctopus_invoice ) ) {
	wp_die(
		esc_html__( 'Invoice not found.', 'clientoctopus' ),
		esc_html__( 'Not Found', 'clientoctopus' ),
		[ 'response' => 404 ]
	);
}

$clientoctopus_inv = $clientoctopus_invoice;

// ── Branding ──────────────────────────────────────────────────────────────────
$clientoctopus_business_name  = get_option( 'clientoctopus_business_name', get_bloginfo( 'name' ) );
$clientoctopus_business_logo  = esc_url( get_option( 'clientoctopus_logo_url', '' ) );
$clientoctopus_brand_color    = sanitize_hex_color( get_option( 'clientoctopus_brand_color', '#6366F1' ) ) ?: '#6366F1';

// ── Client details ────────────────────────────────────────────────────────────
$clientoctopus_client_name    = '';
$clientoctopus_client_email   = '';
$clientoctopus_client_company = '';

if ( $clientoctopus_inv['client_id'] ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$_co_client = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT name, email, company FROM {$wpdb->prefix}clientoctopus_clients WHERE id = %d LIMIT 1",
			$clientoctopus_inv['client_id']
		),
		ARRAY_A
	);
	$clientoctopus_client_name    = $_co_client['name']    ?? '';
	$clientoctopus_client_email   = $_co_client['email']   ?? '';
	$clientoctopus_client_company = $_co_client['company'] ?? '';
}

// ── Payment enabled (Pro only) ────────────────────────────────────────────────
$clientoctopus_payment_enabled = false;
//@fs_premium_only
if ( function_exists( 'clientoctopus_fs' ) && clientoctopus_fs()->is_premium() ) {
	$_stripe_class = CLIENTOCTOPUS_DIR . 'modules/payments/class-stripe.php';
	if ( ! class_exists( 'ClientOctopus_Stripe' ) && file_exists( $_stripe_class ) ) {
		require_once $_stripe_class;
	}
	$clientoctopus_payment_enabled = class_exists( 'ClientOctopus_Stripe' )
		&& ClientOctopus_Stripe::is_configured()
		&& in_array( $clientoctopus_inv['status'], [ 'sent', 'overdue' ], true );
}
//@end:fs_premium_only

// ── Currency symbol ───────────────────────────────────────────────────────────
$clientoctopus_currency_symbols = [
	'GBP' => '£', 'USD' => '$', 'EUR' => '€', 'CAD' => '$', 'AUD' => '$',
];
$clientoctopus_sym = $clientoctopus_currency_symbols[ $clientoctopus_inv['currency'] ] ?? '';


// ── Enqueue styles (must happen before HTML output) ───────────────────────────
wp_enqueue_style( 'co-inv-client', CLIENTOCTOPUS_URL . 'client/invoice.css', [], CLIENTOCTOPUS_VERSION );
wp_enqueue_style( 'co-client-fonts', CLIENTOCTOPUS_URL . 'assets/fonts/client-fonts.css', [], CLIENTOCTOPUS_VERSION );
// Brand colour custom property — uses a PHP value so cannot live in the static CSS file.
wp_add_inline_style( 'co-inv-client', ':root { --brand: ' . esc_attr( $clientoctopus_brand_color ) . '; }' );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">

	<title><?php
		echo esc_html( $clientoctopus_inv['invoice_ref'] );
		echo ' — ';
		echo esc_html( $clientoctopus_business_name );
	?></title>

	<?php do_action( 'clientoctopus_template_head' ); ?>

	<?php wp_print_styles(); ?>
</head>
<body>

<div class="inv-wrap">

<?php
// ── Success / cancel banners ───────────────────────────────────────────────────
if ( 'success' === $clientoctopus_payment_result ) {
	echo '<div class="inv-banner inv-banner-success">' . esc_html__( '✓ Payment received — thank you!', 'clientoctopus' ) . '</div>';
} elseif ( 'cancel' === $clientoctopus_payment_result ) {
	echo '<div class="inv-banner inv-banner-cancel">' . esc_html__( 'Payment was not completed. You can try again below.', 'clientoctopus' ) . '</div>';
}
?>

	<div class="inv-header-bar">
		<?php if ( $clientoctopus_business_logo ) : ?>
			<div class="inv-logo"><img src="<?php echo esc_url( $clientoctopus_business_logo ); ?>" alt="<?php echo esc_attr( $clientoctopus_business_name ); ?>"></div>
		<?php else : ?>
			<div class="inv-logo-text"><?php echo esc_html( $clientoctopus_business_name ); ?></div>
		<?php endif; ?>
	</div>

	<div class="inv-card">
		<div class="inv-title-row">
			<div>
				<div class="inv-ref"><?php echo esc_html( $clientoctopus_inv['invoice_ref'] ); ?></div>
				<?php if ( $clientoctopus_inv['title'] ) : ?>
					<div class="inv-title"><?php echo esc_html( $clientoctopus_inv['title'] ); ?></div>
				<?php endif; ?>
			</div>
			<?php
			$status_classes = [
				'draft' => 'inv-badge-draft', 'sent' => 'inv-badge-sent',
				'paid'  => 'inv-badge-paid',  'overdue' => 'inv-badge-overdue',
				'cancelled' => 'inv-badge-cancelled',
			];
			$status_labels  = [
				'draft' => __( 'Draft', 'clientoctopus' ), 'sent' => __( 'Invoice', 'clientoctopus' ),
				'paid'  => __( 'Paid', 'clientoctopus' ),  'overdue' => __( 'Overdue', 'clientoctopus' ),
				'cancelled' => __( 'Cancelled', 'clientoctopus' ),
			];
			$inv_status      = $clientoctopus_inv['status'];
			$status_class    = $status_classes[ $inv_status ] ?? 'inv-badge-draft';
			$status_label    = $status_labels[ $inv_status ]  ?? $inv_status;
			echo '<span class="inv-badge ' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span>';
			?>
		</div>

		<dl class="inv-meta">
			<div class="inv-meta-block">
				<dt><?php esc_html_e( 'Billed To', 'clientoctopus' ); ?></dt>
				<dd>
					<?php echo esc_html( $clientoctopus_client_name ); ?>
					<?php if ( $clientoctopus_client_company ) : ?>
						<br><?php echo esc_html( $clientoctopus_client_company ); ?>
					<?php endif; ?>
					<?php if ( $clientoctopus_client_email ) : ?>
						<br><span style="color:#94A3B8;font-size:13px;"><?php echo esc_html( $clientoctopus_client_email ); ?></span>
					<?php endif; ?>
				</dd>
			</div>
			<div class="inv-meta-block">
				<dt><?php esc_html_e( 'From', 'clientoctopus' ); ?></dt>
				<dd><?php echo esc_html( $clientoctopus_business_name ); ?></dd>
			</div>
			<div class="inv-meta-block">
				<dt><?php esc_html_e( 'Issue Date', 'clientoctopus' ); ?></dt>
				<dd><?php
					$_issue = $clientoctopus_inv['issue_date'] ?? '';
					echo $_issue ? esc_html( gmdate( 'j F Y', (int) strtotime( $_issue ) ) ) : '—';
				?></dd>
			</div>
			<div class="inv-meta-block">
				<dt><?php esc_html_e( 'Due Date', 'clientoctopus' ); ?></dt>
				<dd class="<?php echo 'overdue' === $inv_status ? 'overdue' : ''; ?>"><?php
					$_due = $clientoctopus_inv['due_date'] ?? '';
					echo $_due ? esc_html( gmdate( 'j F Y', (int) strtotime( $_due ) ) ) : '—';
				?></dd>
			</div>
			<?php if ( $clientoctopus_inv['payment_terms'] ) : ?>
			<div class="inv-meta-block">
				<dt><?php esc_html_e( 'Payment Terms', 'clientoctopus' ); ?></dt>
				<dd><?php echo esc_html( $clientoctopus_inv['payment_terms'] ); ?></dd>
			</div>
			<?php endif; ?>
			<?php if ( $clientoctopus_inv['po_number'] ) : ?>
			<div class="inv-meta-block">
				<dt><?php esc_html_e( 'PO Number', 'clientoctopus' ); ?></dt>
				<dd><?php echo esc_html( $clientoctopus_inv['po_number'] ); ?></dd>
			</div>
			<?php endif; ?>
			<?php if ( $clientoctopus_inv['vat_number'] ) : ?>
			<div class="inv-meta-block">
				<dt><?php esc_html_e( 'VAT Number', 'clientoctopus' ); ?></dt>
				<dd><?php echo esc_html( $clientoctopus_inv['vat_number'] ); ?></dd>
			</div>
			<?php endif; ?>
		</dl>

		<?php
		// ── Line items ────────────────────────────────────────────────────────────
		$line_items   = is_array( $clientoctopus_inv['line_items'] ) ? $clientoctopus_inv['line_items'] : [];
		$subtotal     = 0.0;
		foreach ( $line_items as $item ) {
			$subtotal += (float) ( $item['amount'] ?? 0 );
		}
		$discount_type  = $clientoctopus_inv['discount_type']  ?? 'percentage';
		$discount_value = (float) ( $clientoctopus_inv['discount_value'] ?? 0 );
		$vat_pct        = (float) ( $clientoctopus_inv['vat_pct'] ?? 0 );
		$disc_amt       = 'percentage' === $discount_type
			? $subtotal * ( min( 100, $discount_value ) / 100 )
			: min( $discount_value, $subtotal );
		$after_disc     = max( 0, $subtotal - $disc_amt );
		$vat_amt        = $after_disc * ( $vat_pct / 100 );
		$total          = $after_disc + $vat_amt;
		$sym            = $clientoctopus_sym;
		?>

		<?php if ( ! empty( $line_items ) ) : ?>
		<table class="inv-items-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Description', 'clientoctopus' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Amount', 'clientoctopus' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $line_items as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item['description'] ?? '' ); ?></td>
					<td><?php echo esc_html( $sym . number_format( (float) ( $item['amount'] ?? 0 ), 2 ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<div class="inv-totals">
			<div class="inv-totals-row">
				<span class="inv-totals-label"><?php esc_html_e( 'Subtotal', 'clientoctopus' ); ?></span>
				<span class="inv-totals-value"><?php echo esc_html( $sym . number_format( $subtotal, 2 ) ); ?></span>
			</div>
			<?php if ( $discount_value > 0 ) : ?>
			<div class="inv-totals-row">
				<span class="inv-totals-label"><?php esc_html_e( 'Discount', 'clientoctopus' ); ?></span>
				<span class="inv-totals-value" style="color:#10B981;">&minus;<?php echo esc_html( $sym . number_format( $disc_amt, 2 ) ); ?></span>
			</div>
			<?php endif; ?>
			<?php if ( $vat_pct > 0 ) : ?>
			<div class="inv-totals-row">
				<span class="inv-totals-label">
					<?php
					printf(
						/* translators: %s: VAT percentage */
						esc_html__( 'VAT (%s%%)', 'clientoctopus' ),
						esc_html( number_format( $vat_pct, 0 ) )
					);
					?>
				</span>
				<span class="inv-totals-value"><?php echo esc_html( $sym . number_format( $vat_amt, 2 ) ); ?></span>
			</div>
			<?php endif; ?>
			<div class="inv-totals-row inv-total-final">
				<span class="inv-totals-label"><?php esc_html_e( 'Total Due', 'clientoctopus' ); ?></span>
				<span class="inv-totals-value"><?php echo esc_html( $sym . number_format( $total, 2 ) . ' ' . esc_html( $clientoctopus_inv['currency'] ) ); ?></span>
			</div>
		</div>

		<?php if ( $clientoctopus_inv['notes'] ) : ?>
		<div class="inv-notes">
			<p class="inv-notes-label"><?php esc_html_e( 'Notes', 'clientoctopus' ); ?></p>
			<p class="inv-notes-body"><?php echo esc_html( $clientoctopus_inv['notes'] ); ?></p>
		</div>
		<?php endif; ?>
	</div>

	<?php
	// ── Pay Now / Paid state ──────────────────────────────────────────────────
	if ( 'paid' === $clientoctopus_inv['status'] ) {
		echo '<div class="inv-cta"><p class="inv-paid-msg">✓ ' . esc_html__( 'This invoice has been paid. Thank you!', 'clientoctopus' ) . '</p></div>';
	} elseif ( 'cancelled' === $clientoctopus_inv['status'] ) {
		echo '<div class="inv-cta" style="color:#94A3B8;">' . esc_html__( 'This invoice has been cancelled.', 'clientoctopus' ) . '</div>';
	} elseif ( $clientoctopus_payment_enabled ) {
		?>
		<div class="inv-cta" id="co-inv-pay-wrap">
			<button class="inv-pay-btn" id="co-inv-pay-btn" type="button">
				<?php esc_html_e( 'Pay Now', 'clientoctopus' ); ?> <?php echo esc_html( $sym . number_format( $total, 2 ) ); ?>
			</button>
		</div>
		<?php
	}
	?>

</div><!-- .inv-wrap -->

<?php
// ── Scripts ───────────────────────────────────────────────────────────────────
if ( $clientoctopus_payment_enabled ) {
	$inv_id    = (int) $clientoctopus_inv['id'];
	$inv_token = esc_js( $clientoctopus_inv['token'] );
	$api_url   = esc_js( rtrim( rest_url( 'clientoctopus/v1/' ), '/' ) );
	$nonce     = esc_js( wp_create_nonce( 'wp_rest' ) );

	wp_register_script( 'co-inv-pay', false, [], CLIENTOCTOPUS_VERSION, true );
	wp_enqueue_script( 'co-inv-pay' );
	wp_add_inline_script(
		'co-inv-pay',
		sprintf(
			'(function(){
				var btn = document.getElementById("co-inv-pay-btn");
				if(!btn)return;
				btn.addEventListener("click",function(){
					btn.textContent = "Redirecting to payment...";
					btn.disabled = true;
					fetch("%s/invoices/%d/pay/",{
						method:"POST",
						headers:{"Content-Type":"application/json","X-WP-Nonce":"%s"},
						body:JSON.stringify({id:%d,token:"%s"})
					})
					.then(function(r){return r.json();})
					.then(function(d){
						if(d.checkout_url){window.location.href=d.checkout_url;}
						else{alert(d.message||"Payment error");btn.disabled=false;btn.textContent="Pay Now";}
					})
					.catch(function(){alert("Payment unavailable. Please try again.");btn.disabled=false;btn.textContent="Pay Now";});
				});
			})();',
			$api_url,
			$inv_id,
			$nonce,
			$inv_id,
			$inv_token
		)
	);
}

wp_print_scripts();
?>
</body>
</html>
