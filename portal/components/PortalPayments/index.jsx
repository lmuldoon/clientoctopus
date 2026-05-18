/**
 * PortalPayments
 *
 * Payment history table with running total.
 * Alternating row backgrounds, DM Mono amounts, status badges.
 */

const { useState, useEffect } = wp.element;

const apiFetch = ( path ) =>
	fetch( window.coPortalData.apiUrl + path, {
		headers: {
			'X-WP-Nonce':   window.coPortalData.nonce,
			'Content-Type': 'application/json',
		},
	} ).then( r => r.json() );

const fmt = ( amount, currency = 'GBP' ) =>
	new Intl.NumberFormat( 'en-GB', { style: 'currency', currency } ).format( amount );

const PAYMENT_COLORS = {
	completed: { bg: '#D1FAE5', text: '#065F46' },
	pending:   { bg: '#FEF3C7', text: '#B45309' },
	failed:    { bg: '#FEE2E2', text: '#B91C1C' },
};

function StatusBadge( { status } ) {
	const s = ( status || '' ).toLowerCase();
	const c = PAYMENT_COLORS[ s ] || PAYMENT_COLORS.pending;
	return (
		<span style={{
			display:       'inline-block',
			padding:       '3px 10px',
			borderRadius:  '20px',
			fontSize:      '12px',
			fontWeight:    '600',
			fontFamily:    "'DM Sans', sans-serif",
			background:    c.bg,
			color:         c.text,
			textTransform: 'capitalize',
		}}>
			{ s }
		</span>
	);
}

injectStyles( 'cppm-s', `
/* ── Page header ──────────────────────────────────────── */
.cppm-header { margin-bottom: 32px; }

.cppm-heading {
	font-family: 'Playfair Display', serif;
	font-size: 32px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 8px;
	letter-spacing: -0.02em;
}

/* ── Summary bar ──────────────────────────────────────── */
.cppm-summary {
	display: inline-flex;
	align-items: baseline;
	gap: 10px;
	background: #fff;
	border: 1px solid #EEECEA;
	border-radius: 10px;
	padding: 12px 20px;
	margin-bottom: 28px;
	box-shadow: 0 1px 3px rgba(26,26,46,.04);
}

.cppm-summary-label {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #9CA3AF;
	font-weight: 500;
}

.cppm-summary-amount {
	font-family: 'DM Mono', monospace;
	font-size: 22px;
	color: #6366F1;
	font-weight: 400;
}

/* ── Table ────────────────────────────────────────────── */
.cppm-table-wrap {
	background: #fff;
	border-radius: 14px;
	border: 1px solid #EEECEA;
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(26,26,46,.04);
}

.cppm-table {
	width: 100%;
	border-collapse: collapse;
}

.cppm-table th {
	font-family: 'DM Sans', sans-serif;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.07em;
	text-transform: uppercase;
	color: #9CA3AF;
	padding: 14px 20px;
	text-align: left;
	border-bottom: 1px solid #F3F4F6;
}

.cppm-table th.right,
.cppm-table td.right { text-align: right; }

.cppm-table td {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #374151;
	padding: 14px 20px;
	vertical-align: middle;
	cursor: pointer;
}

.cppm-table td.mono {
	font-family: 'DM Mono', monospace;
	font-size: 13px;
}

.cppm-table tbody tr:nth-child(even) td { background: #FAFAF8; }
.cppm-table tbody tr:last-child td      { border-bottom: none; }

.cppm-table tbody tr:hover td {
	background: #F5F4FF;
}

.cppm-prop-link {
	font-weight: 600;
	color: #1A1A2E;
	text-decoration: none;
}
.cppm-prop-link:hover { color: #6366F1; text-decoration: underline; }

.cppm-receipt-link {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	font-weight: 600;
	color: #6366F1;
	text-decoration: none;
	padding: 4px 10px;
	border: 1px solid #E0E0F8;
	border-radius: 6px;
	background: #F5F4FF;
	white-space: nowrap;
	transition: background .15s, border-color .15s;
}
.cppm-receipt-link:hover {
	background: #EEEDFF;
	border-color: #C7C5F5;
	text-decoration: none;
}

/* ── Running total row ────────────────────────────────── */
.cppm-total-row td {
	background: #F8F7F5 !important;
	border-top: 2px solid #EEECEA;
	font-weight: 700;
	padding: 14px 20px;
}

.cppm-total-label {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 700;
	color: #374151;
	letter-spacing: 0.03em;
	text-transform: uppercase;
}

.cppm-total-amount {
	font-family: 'DM Mono', monospace;
	font-size: 15px;
	color: #6366F1;
}

/* ── Empty state ──────────────────────────────────────── */
.cppm-empty {
	padding: 64px 32px;
	text-align: center;
}

.cppm-empty-msg {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #9CA3AF;
	margin: 0 0 8px;
}

.cppm-empty-sub {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #C0C0C8;
	margin: 0;
}

/* ── Skeleton ─────────────────────────────────────────── */
.cppm-skel {
	background: linear-gradient(90deg, #F3F4F6 25%, #E9EAEC 50%, #F3F4F6 75%);
	background-size: 200% 100%;
	animation: cppm-pulse 1.4s ease infinite;
	border-radius: 6px;
}
@keyframes cppm-pulse {
	0%   { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

@media (max-width: 640px) {
	.cppm-heading { font-size: 24px; }
	.cppm-table th, .cppm-table td { padding: 11px 14px; }
	/* Hide 'Date' column on small screens */
	.cppm-table .col-date { display: none; }
}
` );

export default function PortalPayments() {
	const [ loading,  setLoading  ] = useState( true );
	const [ payments, setPayments ] = useState( [] );

	useEffect( () => {
		apiFetch( '/portal/payments' ).then( data => {
			setPayments( Array.isArray( data ) ? data : [] );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	function formatDate( d ) {
		if ( ! d ) return '—';
		return new Intl.DateTimeFormat( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' } )
			.format( new Date( d ) );
	}

	const currency    = payments[0]?.currency || 'GBP';
	const runningTotal = payments
		.filter( p => p.status === 'completed' )
		.reduce( ( sum, p ) => sum + parseFloat( p.amount || 0 ), 0 );

	if ( loading ) {
		return (
			<div>
				<div className="cppm-header">
					<div className="cppm-skel" style={{ height: 36, width: 220 }} />
				</div>
				<div style={{ background: '#fff', borderRadius: 14, border: '1px solid #EEECEA', padding: 24 }}>
					{ [1,2,3,4].map( i => (
						<div key={ i } className="cppm-skel" style={{ height: 14, width: '100%', marginBottom: 14 }} />
					) ) }
				</div>
			</div>
		);
	}

	return (
		<div>
			<div className="cppm-header">
				<h1 className="cppm-heading">Payment History</h1>
			</div>

			{ payments.length > 0 && (
				<div className="cppm-summary">
					<span className="cppm-summary-label">Total paid</span>
					<span className="cppm-summary-amount">{ fmt( runningTotal, currency ) }</span>
				</div>
			) }

			<div className="cppm-table-wrap">
				<table className="cppm-table">
					<thead>
						<tr>
							<th>Proposal</th>
							<th className="right">Amount</th>
							<th className="col-date">Date</th>
							<th>Status</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ payments.length === 0 ? (
							<tr>
								<td colSpan="5">
									<div className="cppm-empty">
										<p className="cppm-empty-msg">No payments yet.</p>
										<p className="cppm-empty-sub">
											Payments will appear here once a proposal has been accepted and paid.
										</p>
									</div>
								</td>
							</tr>
						) : payments.map( pm => (
							<tr
								key={ pm.id }
								onClick={ () => { window.location.href = `/proposals/${ pm.proposal_token }`; } }
							>
								<td>
									<a
										className="cppm-prop-link"
										href={ `/proposals/${ pm.proposal_token }` }
										onClick={ e => e.stopPropagation() }
									>
										{ pm.proposal_title || 'Untitled Proposal' }
									</a>
								</td>
								<td className="mono right">
									{ fmt( pm.amount, pm.currency || 'GBP' ) }
								</td>
								<td className="col-date" style={{ color: '#9CA3AF', fontSize: 13 }}>
									{ formatDate( pm.created_at ) }
								</td>
								<td><StatusBadge status={ pm.status } /></td>
								<td onClick={ e => e.stopPropagation() } style={{ width: 1, whiteSpace: 'nowrap' }}>
									{ pm.status === 'completed' && (
										<a
											className="cppm-receipt-link"
											href={ `/clientoctopus/receipt?payment=${ pm.id }` }
											target="_blank"
											rel="noreferrer"
										>
											<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
												<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>
											</svg>
											Receipt
										</a>
									) }
								</td>
							</tr>
						) ) }
					</tbody>

					{ payments.length > 0 && (
						<tfoot>
							<tr className="cppm-total-row">
								<td className="cppm-total-label">Running Total</td>
								<td className="mono right cppm-total-amount">
									{ fmt( runningTotal, currency ) }
								</td>
								<td className="col-date" />
								<td />
								<td />
							</tr>
						</tfoot>
					) }
				</table>
			</div>
		</div>
	);
}
