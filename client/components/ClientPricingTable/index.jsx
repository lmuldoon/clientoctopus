/**
 * ClientPricingTable
 *
 * Renders the proposal's line items as a clean pricing table
 * with subtotal, optional discount, optional VAT, and grand total.
 *
 * Props:
 *   items       {Array}  Line items: { id, description, qty, unit_price }
 *   discountPct {number} Discount percentage (0–100)
 *   vatPct      {number} VAT percentage (0–100)
 *   currency    {string} ISO currency code (GBP | USD | EUR | …)
 *   totalAmount {number|null} Pre-calculated total from DB (authoritative)
 */

const injectStyles = ( id, css ) => {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
};

const CSS = `
.cfp-wrap {
	margin: 40px 0 88px;
}

.cfp-label {
	font-family: 'DM Sans', sans-serif;
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: #9CA3AF;
	margin-bottom: 16px;
}

/* ── Table ──────────────────────────────────────────────────── */
.cfp-table {
	border: 1.5px solid #EAECEF;
	border-radius: 12px;
	overflow: hidden;
}

.cfp-thead,
.cfp-row {
	display: grid;
	grid-template-columns: 1fr 72px 130px 130px;
	gap: 8px;
	padding: 13px 20px;
	align-items: center;
}

.cfp-thead {
	background: #F8F7F5;
	border-bottom: 1.5px solid #EAECEF;
}

.cfp-th {
	font-family: 'DM Sans', sans-serif;
	font-size: 10px;
	font-weight: 800;
	color: #9CA3AF;
	letter-spacing: 0.1em;
	text-transform: uppercase;
}
.cfp-th--r { text-align: right; }

.cfp-row {
	border-bottom: 1px solid #F3F4F6;
	transition: background 0.12s;
}
.cfp-row:last-child    { border-bottom: none; }
.cfp-row:nth-child(even) { background: #FAFAFA; }
.cfp-row:hover         { background: #F8F7F5; }

.cfp-desc {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 500;
	color: #1A1A2E;
}

.cfp-num {
	font-family: 'DM Mono', monospace;
	font-size: 13.5px;
	color: #374151;
	text-align: right;
}
.cfp-num--qty {
	text-align: left;
	color: #6B7280;
	font-size: 13px;
}
.cfp-num--total {
	font-weight: 600;
	color: #1A1A2E;
}

/* ── Totals block ───────────────────────────────────────────── */
.cfp-totals {
	display: flex;
	justify-content: flex-end;
	margin-top: 20px;
}
.cfp-totals-inner {
	width: 300px;
	display: flex;
	flex-direction: column;
	gap: 0;
}

.cfp-total-row {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	padding: 7px 0;
}
.cfp-total-lbl {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #6B7280;
}
.cfp-total-val {
	font-family: 'DM Mono', monospace;
	font-size: 13.5px;
	color: #374151;
}

.cfp-total-row--disc .cfp-total-lbl,
.cfp-total-row--disc .cfp-total-val { color: #10B981; }

/* ── Grand total ────────────────────────────────────────────── */
.cfp-grand {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 10px;
	padding-top: 14px;
	border-top: 2px solid #1A1A2E;
}
.cfp-grand-lbl {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 700;
	color: #1A1A2E;
	letter-spacing: 0.02em;
}
.cfp-grand-val {
	font-family: 'Playfair Display', serif;
	font-size: 30px;
	font-weight: 700;
	color: #6366F1;
	letter-spacing: -0.5px;
	line-height: 1;
}

/* ── Mobile ─────────────────────────────────────────────────── */
@media (max-width: 600px) {
	.cfp-thead { display: none; }
	.cfp-row {
		grid-template-columns: 1fr auto;
		grid-template-rows: auto auto;
		row-gap: 3px;
	}
	.cfp-desc      { grid-column: 1; grid-row: 1; }
	.cfp-num--qty  { grid-column: 1; grid-row: 2; font-size: 11px; text-align: left; }
	.cfp-num--unit { display: none; }
	.cfp-num--total { grid-column: 2; grid-row: 1 / 3; align-self: center; font-size: 14px; }
	.cfp-totals-inner { width: 100%; }
}

/* ── Print ──────────────────────────────────────────────────── */
@media print {
	.cfp-table { border-color: #ccc; }
	.cfp-grand-val { color: #000 !important; -webkit-print-color-adjust: exact; }
	.cfp-thead { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; }
}
`;

const SYMBOLS = { GBP: '£', USD: '$', EUR: '€', CAD: 'CA$', AUD: 'A$' };

function fmt( amount, currency ) {
	const sym = SYMBOLS[ currency ] || ( currency + ' ' );
	return sym + Number( amount ).toLocaleString( 'en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
}

export default function ClientPricingTable( { items = [], discountPct = 0, vatPct = 0, currency = 'GBP', totalAmount } ) {
	injectStyles( 'co-pricing-s', CSS );

	if ( ! items.length ) return null;

	const subtotal     = items.reduce( ( s, i ) => s + ( i.qty || 0 ) * ( i.unit_price || 0 ), 0 );
	const discountAmt  = subtotal * ( discountPct / 100 );
	const afterDisc    = subtotal - discountAmt;
	const vatAmt       = afterDisc * ( vatPct / 100 );
	const grandTotal   = totalAmount != null ? Number( totalAmount ) : Math.round( ( afterDisc + vatAmt ) * 100 ) / 100;

	return (
		<div className="cfp-wrap">
			<div className="cfp-label">Pricing Breakdown</div>

			<div className="cfp-table">
				<div className="cfp-thead">
					<span className="cfp-th">Description</span>
					<span className="cfp-th">Qty</span>
					<span className="cfp-th cfp-th--r">Unit Price</span>
					<span className="cfp-th cfp-th--r">Total</span>
				</div>

				{ items.map( ( item, i ) => (
					<div key={ item.id || i } className="cfp-row">
						<span className="cfp-desc">{ item.description || '—' }</span>
						<span className="cfp-num cfp-num--qty">{ item.qty }</span>
						<span className="cfp-num cfp-num--unit">{ fmt( item.unit_price || 0, currency ) }</span>
						<span className="cfp-num cfp-num--total">{ fmt( ( item.qty || 0 ) * ( item.unit_price || 0 ), currency ) }</span>
					</div>
				) ) }
			</div>

			<div className="cfp-totals">
				<div className="cfp-totals-inner">
					<div className="cfp-total-row">
						<span className="cfp-total-lbl">Subtotal</span>
						<span className="cfp-total-val">{ fmt( subtotal, currency ) }</span>
					</div>

					{ discountPct > 0 && (
						<div className="cfp-total-row cfp-total-row--disc">
							<span className="cfp-total-lbl">Discount ({ discountPct }%)</span>
							<span className="cfp-total-val">−{ fmt( discountAmt, currency ) }</span>
						</div>
					) }

					{ vatPct > 0 && (
						<div className="cfp-total-row">
							<span className="cfp-total-lbl">VAT ({ vatPct }%)</span>
							<span className="cfp-total-val">{ fmt( vatAmt, currency ) }</span>
						</div>
					) }

					<div className="cfp-grand">
						<span className="cfp-grand-lbl">Total Due</span>
						<span className="cfp-grand-val">{ fmt( grandTotal, currency ) }</span>
					</div>
				</div>
			</div>
		</div>
	);
}
