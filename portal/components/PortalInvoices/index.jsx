/**
 * PortalInvoices
 *
 * Client-facing invoice list with filter tabs (All / Sent / Paid / Overdue).
 * Each invoice renders as a card linking to the public invoice page.
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

const STATUS_COLORS = {
	sent:      { bg: '#DBEAFE', text: '#1D4ED8' },
	paid:      { bg: '#D1FAE5', text: '#065F46' },
	overdue:   { bg: '#FEE2E2', text: '#B91C1C' },
	cancelled: { bg: '#F3F4F6', text: '#9CA3AF' },
};

function StatusBadge( { status } ) {
	const s      = ( status || '' ).toLowerCase();
	const colors = STATUS_COLORS[ s ] || { bg: '#F3F4F6', text: '#6B7280' };
	return (
		<span style={{
			display:       'inline-block',
			padding:       '4px 12px',
			borderRadius:  '20px',
			fontSize:      '12px',
			fontWeight:    '600',
			fontFamily:    "'Archivo', -apple-system, BlinkMacSystemFont, sans-serif",
			background:    colors.bg,
			color:         colors.text,
			textTransform: 'capitalize',
			letterSpacing: '0.02em',
		}}>
			{ s }
		</span>
	);
}

injectStyles( 'cppi-s', `
/* ── Page header ──────────────────────────────────────── */
.cppi-header { margin-bottom: 32px; }

.cppi-heading {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 32px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 8px;
	letter-spacing: -0.02em;
}

/* ── Filter tabs ──────────────────────────────────────── */
.cppi-tabs {
	display: flex;
	gap: 6px;
	margin-bottom: 28px;
	flex-wrap: wrap;
}

.cppi-tab {
	padding: 7px 16px;
	border-radius: 20px;
	border: 1.5px solid #E5E7EB;
	background: #fff;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 13px;
	font-weight: 500;
	color: #6B7280;
	cursor: pointer;
	transition: all .12s;
}

.cppi-tab:hover { border-color: #6366F1; color: #6366F1; }

.cppi-tab.cppi-tab-active {
	background: #6366F1;
	border-color: #6366F1;
	color: #fff;
	font-weight: 600;
}

/* ── Card grid ────────────────────────────────────────── */
.cppi-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
	gap: 16px;
}

/* ── Invoice card ─────────────────────────────────────── */
.cppi-card {
	background: #fff;
	border-radius: 14px;
	border: 1px solid #EEECEA;
	padding: 22px 24px 18px;
	box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 1px 3px rgba(15,23,42,0.06);
	transition: box-shadow .15s, transform .15s;
}

.cppi-card:hover {
	box-shadow: 0 4px 12px rgba(15,23,42,0.08);
	transform: translateY(-1px);
}

.cppi-card-top {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 14px;
}

.cppi-card-ref {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 12px;
	color: #9CA3AF;
	margin: 0 0 3px;
	letter-spacing: 0.04em;
}

.cppi-card-title {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 17px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0;
	line-height: 1.3;
	letter-spacing: -0.01em;
}

.cppi-card-amount {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 16px;
	color: #6366F1;
	white-space: nowrap;
	flex-shrink: 0;
	font-weight: 400;
}

.cppi-card-bottom {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding-top: 14px;
	border-top: 1px solid #F3F4F6;
}

.cppi-card-date {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 12px;
	color: #9CA3AF;
}

.cppi-card-link {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 13px;
	font-weight: 600;
	color: #6366F1;
	text-decoration: none;
	display: inline-flex;
	align-items: center;
	gap: 4px;
}
.cppi-card-link:hover { text-decoration: underline; }

/* ── Empty state ──────────────────────────────────────── */
.cppi-empty {
	background: #fff;
	border-radius: 14px;
	border: 1px solid #EEECEA;
	padding: 56px 32px;
	text-align: center;
	grid-column: 1 / -1;
}

.cppi-empty-msg {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 14px;
	color: #9CA3AF;
	margin: 0;
}

/* ── Skeleton ─────────────────────────────────────────── */
.cppi-skel {
	background: linear-gradient(90deg, #F3F4F6 25%, #E9EAEC 50%, #F3F4F6 75%);
	background-size: 200% 100%;
	animation: cppi-pulse 1.4s ease infinite;
	border-radius: 6px;
}
@keyframes cppi-pulse {
	0%   { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

@media (max-width: 600px) {
	.cppi-heading { font-size: 24px; }
	.cppi-grid { grid-template-columns: 1fr; }
}
` );

const FILTERS = [
	{ label: 'All',     value: 'all' },
	{ label: 'Sent',    value: 'sent' },
	{ label: 'Paid',    value: 'paid' },
	{ label: 'Overdue', value: 'overdue' },
];

export default function PortalInvoices() {
	const [ loading,  setLoading  ] = useState( true );
	const [ invoices, setInvoices ] = useState( [] );
	const [ filter,   setFilter   ] = useState( 'all' );

	useEffect( () => {
		apiFetch( '/portal/invoices/' ).then( data => {
			setInvoices( Array.isArray( data ) ? data : [] );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	function formatDate( d ) {
		if ( ! d ) return '—';
		return new Intl.DateTimeFormat( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' } )
			.format( new Date( d ) );
	}

	function countFor( value ) {
		if ( value === 'all' ) return invoices.length;
		return invoices.filter( i => i.status === value ).length;
	}

	const filtered = filter === 'all' ? invoices : invoices.filter( i => i.status === filter );

	if ( loading ) {
		return (
			<div>
				<div className="cppi-header">
					<div className="cppi-skel" style={{ height: 36, width: 200 }} />
				</div>
				<div className="cppi-grid">
					{ [ 1, 2, 3 ].map( i => (
						<div key={ i } style={{ background: '#fff', borderRadius: 14, padding: 24, border: '1px solid #EEECEA' }}>
							<div className="cppi-skel" style={{ height: 18, width: '70%', marginBottom: 10 }} />
							<div className="cppi-skel" style={{ height: 14, width: '40%' }} />
						</div>
					) ) }
				</div>
			</div>
		);
	}

	return (
		<div>
			<div className="cppi-header">
				<h1 className="cppi-heading">Your Invoices</h1>
			</div>

			<div className="cppi-tabs">
				{ FILTERS.map( f => (
					<button
						key={ f.value }
						className={ `cppi-tab${ filter === f.value ? ' cppi-tab-active' : '' }` }
						onClick={ () => setFilter( f.value ) }
					>
						{ f.label }
						<span style={{ marginLeft: 5, opacity: .7 }}>({ countFor( f.value ) })</span>
					</button>
				) ) }
			</div>

			<div className="cppi-grid">
				{ filtered.length === 0 ? (
					<div className="cppi-empty">
						<p className="cppi-empty-msg">
							{ filter === 'all' ? 'No invoices yet.' : `No ${ filter } invoices.` }
						</p>
					</div>
				) : filtered.map( inv => (
					<div
						key={ inv.id }
						className={ `cppi-card${ inv.status === 'paid' ? ' cppi-paid' : '' }${ inv.status === 'overdue' ? ' cppi-overdue' : '' }` }
					>
						<div className="cppi-card-top">
							<div>
								<p className="cppi-card-ref">{ inv.invoice_ref }</p>
								<p className="cppi-card-title">{ inv.title || inv.invoice_ref }</p>
							</div>
							<div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 6 }}>
								<StatusBadge status={ inv.status } />
								<span className="cppi-card-amount">
									{ fmt( inv.total_amount, inv.currency || 'GBP' ) }
								</span>
							</div>
						</div>
						<div className="cppi-card-bottom">
							<span className="cppi-card-date">
								{ inv.due_date ? `Due ${ formatDate( inv.due_date ) }` : formatDate( inv.created_at ) }
							</span>
							<a className="cppi-card-link" href={ `/invoices/${ inv.token }` } target="_blank" rel="noreferrer">
								View Invoice
								<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
									stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
									<line x1="5" y1="12" x2="19" y2="12"/>
									<polyline points="12 5 19 12 12 19"/>
								</svg>
							</a>
						</div>
					</div>
				) ) }
			</div>
		</div>
	);
}
