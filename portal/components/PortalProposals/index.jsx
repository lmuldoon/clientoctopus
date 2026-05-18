/**
 * PortalProposals
 *
 * Full list of client proposals with filter tabs (All / Active / Accepted / Declined).
 * Each proposal renders as a card with status badge, amount, and link to viewer.
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
	draft:    { bg: '#F3F4F6', text: '#6B7280' },
	sent:     { bg: '#DBEAFE', text: '#1D4ED8' },
	viewed:   { bg: '#FEF3C7', text: '#B45309' },
	accepted: { bg: '#D1FAE5', text: '#065F46' },
	declined: { bg: '#FEE2E2', text: '#B91C1C' },
	expired:  { bg: '#F3F4F6', text: '#9CA3AF' },
};

function StatusBadge( { status } ) {
	const s = ( status || '' ).toLowerCase();
	const colors = STATUS_COLORS[ s ] || STATUS_COLORS.draft;
	return (
		<span style={{
			display:       'inline-block',
			padding:       '4px 12px',
			borderRadius:  '20px',
			fontSize:      '12px',
			fontWeight:    '600',
			fontFamily:    "'DM Sans', sans-serif",
			background:    colors.bg,
			color:         colors.text,
			textTransform: 'capitalize',
			letterSpacing: '0.02em',
		}}>
			{ s }
		</span>
	);
}

injectStyles( 'cpp-s', `
/* ── Page header ──────────────────────────────────────── */
.cpp-header { margin-bottom: 32px; }

.cpp-heading {
	font-family: 'Playfair Display', serif;
	font-size: 32px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 8px;
	letter-spacing: -0.02em;
}

/* ── Filter tabs ──────────────────────────────────────── */
.cpp-tabs {
	display: flex;
	gap: 6px;
	margin-bottom: 28px;
	flex-wrap: wrap;
}

.cpp-tab {
	padding: 7px 16px;
	border-radius: 20px;
	border: 1.5px solid #E5E7EB;
	background: #fff;
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 500;
	color: #6B7280;
	cursor: pointer;
	transition: all .12s;
}

.cpp-tab:hover { border-color: #6366F1; color: #6366F1; }

.cpp-tab.cpp-tab-active {
	background: #6366F1;
	border-color: #6366F1;
	color: #fff;
	font-weight: 600;
}

/* ── Card grid ────────────────────────────────────────── */
.cpp-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
	gap: 16px;
}

/* ── Proposal card ────────────────────────────────────── */
.cpp-card {
	background: #fff;
	border-radius: 14px;
	border: 1px solid #EEECEA;
	padding: 22px 24px 18px;
	box-shadow: 0 1px 3px rgba(26,26,46,.04);
	transition: box-shadow .15s, transform .15s;
	border-left-width: 3px;
	border-left-color: transparent;
	border-left-style: solid;
}

.cpp-card:hover {
	box-shadow: 0 4px 16px rgba(26,26,46,.09);
	transform: translateY(-1px);
}

.cpp-card.cpp-accepted { border-left-color: #10B981; }

.cpp-card-top {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 14px;
}

.cpp-card-title {
	font-family: 'Playfair Display', serif;
	font-size: 17px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 4px;
	line-height: 1.3;
	letter-spacing: -0.01em;
}

.cpp-card-company {
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	color: #9CA3AF;
	margin: 0;
}

.cpp-card-amount {
	font-family: 'DM Mono', monospace;
	font-size: 16px;
	color: #6366F1;
	white-space: nowrap;
	flex-shrink: 0;
	font-weight: 400;
}

.cpp-card-bottom {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding-top: 14px;
	border-top: 1px solid #F3F4F6;
}

.cpp-card-date {
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	color: #9CA3AF;
}

.cpp-card-link {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 600;
	color: #6366F1;
	text-decoration: none;
	display: inline-flex;
	align-items: center;
	gap: 4px;
}
.cpp-card-link:hover { text-decoration: underline; }

/* ── Empty state ──────────────────────────────────────── */
.cpp-empty {
	background: #fff;
	border-radius: 14px;
	border: 1px solid #EEECEA;
	padding: 56px 32px;
	text-align: center;
	grid-column: 1 / -1;
}

.cpp-empty-msg {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #9CA3AF;
	margin: 0;
}

/* ── Skeleton ─────────────────────────────────────────── */
.cpp-skel {
	background: linear-gradient(90deg, #F3F4F6 25%, #E9EAEC 50%, #F3F4F6 75%);
	background-size: 200% 100%;
	animation: cpp-pulse 1.4s ease infinite;
	border-radius: 6px;
}
@keyframes cpp-pulse {
	0%   { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

@media (max-width: 600px) {
	.cpp-heading { font-size: 24px; }
	.cpp-grid { grid-template-columns: 1fr; }
}
` );

const FILTERS = [
	{ label: 'All',      value: 'all' },
	{ label: 'Active',   value: 'active' },
	{ label: 'Accepted', value: 'accepted' },
	{ label: 'Declined', value: 'declined' },
];

export default function PortalProposals() {
	const [ loading,   setLoading   ] = useState( true );
	const [ proposals, setProposals ] = useState( [] );
	const [ filter,    setFilter    ] = useState( 'all' );

	useEffect( () => {
		apiFetch( '/portal/proposals' ).then( data => {
			setProposals( Array.isArray( data ) ? data : [] );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	function formatDate( d ) {
		if ( ! d ) return '—';
		return new Intl.DateTimeFormat( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' } )
			.format( new Date( d ) );
	}

	const filtered = proposals.filter( p => {
		if ( filter === 'all' )      return true;
		if ( filter === 'active' )   return [ 'sent', 'viewed' ].includes( p.status );
		if ( filter === 'accepted' ) return p.status === 'accepted';
		if ( filter === 'declined' ) return p.status === 'declined';
		return true;
	} );

	if ( loading ) {
		return (
			<div>
				<div className="cpp-header">
					<div className="cpp-skel" style={{ height: 36, width: 200 }} />
				</div>
				<div className="cpp-grid">
					{ [1,2,3].map( i => (
						<div key={ i } style={{ background: '#fff', borderRadius: 14, padding: 24, border: '1px solid #EEECEA' }}>
							<div className="cpp-skel" style={{ height: 18, width: '70%', marginBottom: 10 }} />
							<div className="cpp-skel" style={{ height: 14, width: '40%' }} />
						</div>
					) ) }
				</div>
			</div>
		);
	}

	return (
		<div>
			<div className="cpp-header">
				<h1 className="cpp-heading">Your Proposals</h1>
			</div>

			<div className="cpp-tabs">
				{ FILTERS.map( f => (
					<button
						key={ f.value }
						className={ `cpp-tab${ filter === f.value ? ' cpp-tab-active' : '' }` }
						onClick={ () => setFilter( f.value ) }
					>
						{ f.label }
						<span style={{ marginLeft: 5, opacity: .7 }}>
							({ filter === f.value ? filtered.length : proposals.filter( p => {
								if ( f.value === 'all' )      return true;
								if ( f.value === 'active' )   return [ 'sent', 'viewed' ].includes( p.status );
								if ( f.value === 'accepted' ) return p.status === 'accepted';
								if ( f.value === 'declined' ) return p.status === 'declined';
								return true;
							} ).length })
						</span>
					</button>
				) ) }
			</div>

			<div className="cpp-grid">
				{ filtered.length === 0 ? (
					<div className="cpp-empty">
						<p className="cpp-empty-msg">No proposals in this category.</p>
					</div>
				) : filtered.map( p => (
					<div
						key={ p.id }
						className={ `cpp-card${ p.status === 'accepted' ? ' cpp-accepted' : '' }` }
					>
						<div className="cpp-card-top">
							<div>
								<p className="cpp-card-title">{ p.title || 'Untitled Proposal' }</p>
								{ p.client_company && (
									<p className="cpp-card-company">{ p.client_company }</p>
								) }
							</div>
							<div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 6 }}>
								<StatusBadge status={ p.status } />
								{ p.total_amount && (
									<span className="cpp-card-amount">
										{ fmt( p.total_amount, p.currency || 'GBP' ) }
									</span>
								) }
							</div>
						</div>
						<div className="cpp-card-bottom">
							<span className="cpp-card-date">{ formatDate( p.created_at ) }</span>
							<a className="cpp-card-link" href={ `/proposals/${ p.token }` }>
								View Proposal
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
