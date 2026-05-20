/**
 * PortalReceipt
 *
 * Printable payment receipt. Opened in a new tab from PortalPayments.
 * Reads ?payment=ID from the query string, fetches /portal/receipt/{id}.
 */

const { useState, useEffect } = wp.element;

const fmt = ( amount, currency = 'GBP' ) =>
	new Intl.NumberFormat( 'en-GB', { style: 'currency', currency } ).format( amount );

const formatDate = ( d ) => {
	if ( ! d ) return '—';
	return new Intl.DateTimeFormat( 'en-GB', { day: '2-digit', month: 'long', year: 'numeric' } )
		.format( new Date( d ) );
};

const pad = ( n ) => String( n ).padStart( 6, '0' );

injectStyles( 'co-global-s', `
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'DM Sans', sans-serif; -webkit-font-smoothing: antialiased; background: #F8F7F5; }` );

injectStyles( 'cprc-s', `
.cprc-page {
	min-height: 100vh;
	background: #F8F7F5;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 40px 20px 60px;
}

.cprc-print-btn-wrap {
	width: 100%;
	max-width: 640px;
	display: flex;
	justify-content: flex-end;
	margin-bottom: 16px;
}

.cprc-print-btn {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	padding: 10px 20px;
	background: #6366F1;
	color: #fff;
	border: none;
	border-radius: 8px;
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	transition: background .15s;
}
.cprc-print-btn:hover { background: #4F46E5; }

.cprc-card {
	background: #fff;
	border-radius: 20px;
	width: 100%;
	max-width: 640px;
	box-shadow: 0 2px 4px rgba(26,26,46,.04), 0 12px 40px rgba(26,26,46,.08);
	overflow: hidden;
}

/* ── Header band ─────────────────────────────────────── */
.cprc-header {
	padding: 32px 44px;
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.cprc-logo {
	height: 36px;
	width: auto;
	object-fit: contain;
}

.cprc-biz-name {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 700;
	letter-spacing: 0.1em;
	text-transform: uppercase;
	color: rgba(255,255,255,.7);
}

.cprc-receipt-label {
	font-family: 'Playfair Display', serif;
	font-size: 28px;
	font-weight: 700;
	color: #fff;
	letter-spacing: -0.01em;
}

/* ── Meta row ────────────────────────────────────────── */
.cprc-meta {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 0;
	border-bottom: 1px solid #F3F4F6;
}

.cprc-meta-cell {
	padding: 24px 44px;
}
.cprc-meta-cell + .cprc-meta-cell {
	border-left: 1px solid #F3F4F6;
}

.cprc-meta-label {
	font-family: 'DM Sans', sans-serif;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: #9CA3AF;
	margin: 0 0 6px;
}

.cprc-meta-value {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #1A1A2E;
	font-weight: 500;
	margin: 0;
}

/* ── Line items ──────────────────────────────────────── */
.cprc-items {
	padding: 32px 44px;
	border-bottom: 1px solid #F3F4F6;
}

.cprc-item-row {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	padding: 12px 0;
}
.cprc-item-row + .cprc-item-row {
	border-top: 1px solid #F3F4F6;
}

.cprc-item-name {
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	color: #1A1A2E;
	font-weight: 500;
}

.cprc-item-type {
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	color: #9CA3AF;
	margin-top: 2px;
}

.cprc-item-amount {
	font-family: 'DM Mono', monospace;
	font-size: 15px;
	color: #1A1A2E;
	font-weight: 400;
	white-space: nowrap;
	margin-left: 24px;
}

/* ── Total ───────────────────────────────────────────── */
.cprc-total {
	padding: 28px 44px;
	background: #F8F7F5;
	display: flex;
	justify-content: space-between;
	align-items: center;
	border-bottom: 1px solid #F3F4F6;
}

.cprc-total-label {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: #374151;
}

.cprc-total-amount {
	font-family: 'DM Mono', monospace;
	font-size: 26px;
	color: #059669;
	font-weight: 400;
}

/* ── Payment details ─────────────────────────────────── */
.cprc-details {
	padding: 24px 44px;
	border-bottom: 1px solid #F3F4F6;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.cprc-detail-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.cprc-detail-key {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #9CA3AF;
}

.cprc-detail-val {
	font-family: 'DM Mono', monospace;
	font-size: 12px;
	color: #6B7280;
	max-width: 260px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* ── Footer ──────────────────────────────────────────── */
.cprc-footer {
	padding: 24px 44px;
	text-align: center;
}

.cprc-footer-msg {
	font-family: 'Playfair Display', serif;
	font-size: 16px;
	font-style: italic;
	color: #9CA3AF;
	margin: 0;
}

/* ── Error / loading states ──────────────────────────── */
.cprc-status {
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	color: #6B7280;
	margin-top: 80px;
}

/* ── Print styles ────────────────────────────────────── */
@media print {
	body { background: #fff !important; }
	.cprc-page { padding: 0 !important; background: #fff !important; }
	.cprc-print-btn-wrap { display: none !important; }
	.cprc-card {
		box-shadow: none !important;
		border-radius: 0 !important;
		max-width: 100% !important;
		border: 1px solid #E5E7EB;
	}
}

@media (max-width: 520px) {
	.cprc-header   { padding: 24px 24px; }
	.cprc-meta-cell,
	.cprc-items,
	.cprc-total,
	.cprc-details,
	.cprc-footer   { padding-left: 24px; padding-right: 24px; }
	.cprc-meta     { grid-template-columns: 1fr; }
	.cprc-meta-cell + .cprc-meta-cell { border-left: none; border-top: 1px solid #F3F4F6; }
	.cprc-total-amount { font-size: 20px; }
}
` );

function getContrastColor( hex ) {
	const c = ( hex || '#6366F1' ).replace( '#', '' );
	const r = parseInt( c.substring( 0, 2 ), 16 ) / 255;
	const g = parseInt( c.substring( 2, 4 ), 16 ) / 255;
	const b = parseInt( c.substring( 4, 6 ), 16 ) / 255;
	const lin = x => x <= 0.04045 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 );
	const L = 0.2126 * lin( r ) + 0.7152 * lin( g ) + 0.0722 * lin( b );
	return L > 0.35 ? '#1A1A2E' : '#ffffff';
}

export default function PortalReceipt() {
	const [ state,   setState   ] = useState( 'loading' ); // 'loading' | 'loaded' | 'error'
	const [ data,    setData    ] = useState( null );

	const paymentId = new URLSearchParams( window.location.search ).get( 'payment' );

	useEffect( () => {
		if ( ! paymentId ) {
			setState( 'error' );
			return;
		}

		fetch( window.coPortalData.apiUrl + '/portal/receipt/' + paymentId, {
			headers: {
				'X-WP-Nonce':   window.coPortalData.nonce,
				'Content-Type': 'application/json',
			},
		} )
			.then( r => r.json() )
			.then( json => {
				if ( json.success ) {
					setData( json );
					setState( 'loaded' );
				} else {
					setState( 'error' );
				}
			} )
			.catch( () => setState( 'error' ) );
	}, [ paymentId ] );

	if ( state === 'loading' ) {
		return <div className="cprc-page"><p className="cprc-status">Loading receipt…</p></div>;
	}

	if ( state === 'error' || ! data ) {
		return <div className="cprc-page"><p className="cprc-status">Receipt not found.</p></div>;
	}

	const { payment, payment_type, client_name, client_email, business_name, business_logo, brand_color } = data;
	const headerBg      = brand_color || '#6366F1';
	const headerText    = getContrastColor( headerBg );
	const receiptNum = pad( payment.id );
	const paidDate   = formatDate( payment.completed_at || payment.created_at );

	return (
		<div className="cprc-page">
			<div className="cprc-print-btn-wrap">
				<button className="cprc-print-btn" onClick={ () => window.print() }>
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
						<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>
					</svg>
					Print / Save as PDF
				</button>
			</div>

			<div className="cprc-card">
				{ /* Header */ }
				<div className="cprc-header" style={ { background: headerBg } }>
					<div>
						{ business_logo
							? <img src={ business_logo } alt={ business_name } className="cprc-logo" />
							: <p className="cprc-biz-name" style={ { color: headerText === '#ffffff' ? 'rgba(255,255,255,.7)' : 'rgba(26,26,46,.7)' } }>{ business_name }</p>
						}
					</div>
					<p className="cprc-receipt-label" style={ { color: headerText } }>Receipt</p>
				</div>

				{ /* Meta grid */ }
				<div className="cprc-meta">
					<div className="cprc-meta-cell">
						<p className="cprc-meta-label">Receipt number</p>
						<p className="cprc-meta-value">#{ receiptNum }</p>
					</div>
					<div className="cprc-meta-cell">
						<p className="cprc-meta-label">Date</p>
						<p className="cprc-meta-value">{ paidDate }</p>
					</div>
					<div className="cprc-meta-cell">
						<p className="cprc-meta-label">Billed to</p>
						<p className="cprc-meta-value">{ client_name }</p>
						<p className="cprc-meta-value" style={{ color: '#9CA3AF', fontSize: 13 }}>{ client_email }</p>
					</div>
					<div className="cprc-meta-cell">
						<p className="cprc-meta-label">From</p>
						<p className="cprc-meta-value">{ business_name }</p>
					</div>
				</div>

				{ /* Line item */ }
				<div className="cprc-items">
					<div className="cprc-item-row">
						<div>
							<p className="cprc-item-name">{ payment.proposal_title || 'Proposal' }</p>
							<p className="cprc-item-type">{ payment_type }</p>
						</div>
						<p className="cprc-item-amount">
							{ fmt( payment.amount, payment.currency || 'GBP' ) }
						</p>
					</div>
				</div>

				{ /* Total */ }
				<div className="cprc-total">
					<span className="cprc-total-label">Total paid</span>
					<span className="cprc-total-amount">
						{ fmt( payment.amount, payment.currency || 'GBP' ) }
					</span>
				</div>

				{ /* Payment details */ }
				<div className="cprc-details">
					<div className="cprc-detail-row">
						<span className="cprc-detail-key">Payment method</span>
						<span className="cprc-detail-val">Card (via Stripe)</span>
					</div>
					{ payment.stripe_payment_intent_id && (
						<div className="cprc-detail-row">
							<span className="cprc-detail-key">Transaction reference</span>
							<span className="cprc-detail-val">{ payment.stripe_payment_intent_id }</span>
						</div>
					) }
				</div>

				{ /* Footer */ }
				<div className="cprc-footer">
					<p className="cprc-footer-msg">Thank you for your business.</p>
				</div>
			</div>
		</div>
	);
}
