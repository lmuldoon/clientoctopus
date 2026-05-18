/**
 * PortalDashboard
 *
 * Welcome screen: stats row, recent proposals, recent payments.
 * Fetches: GET /portal/me, /portal/proposals, /portal/payments
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
	completed:{ bg: '#D1FAE5', text: '#065F46' },
	pending:  { bg: '#FEF3C7', text: '#B45309' },
	failed:   { bg: '#FEE2E2', text: '#B91C1C' },
};

function StatusBadge( { status } ) {
	const s = ( status || '' ).toLowerCase();
	const colors = STATUS_COLORS[ s ] || STATUS_COLORS.draft;
	return (
		<span style={{
			display:       'inline-block',
			padding:       '3px 10px',
			borderRadius:  '20px',
			fontSize:      '12px',
			fontWeight:    '600',
			fontFamily:    "'DM Sans', sans-serif",
			letterSpacing: '0.02em',
			background:    colors.bg,
			color:         colors.text,
			textTransform: 'capitalize',
		}}>
			{ s }
		</span>
	);
}

injectStyles( 'cpd-s', `
/* ── Page header ──────────────────────────────────────── */
.cpd-header { margin-bottom: 36px; }

.cpd-greeting {
	font-family: 'Playfair Display', serif;
	font-size: 32px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 8px;
	letter-spacing: -0.02em;
	line-height: 1.2;
}

.cpd-subtitle {
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	color: #6B7280;
	margin: 0;
}

/* ── Stats row ────────────────────────────────────────── */
.cpd-stats {
	display: grid;
	grid-template-columns: repeat(5, 1fr);
	gap: 18px;
	margin-bottom: 44px;
}

.cpd-stat-card {
	background: #fff;
	border-radius: 14px;
	border: 1px solid #EEECEA;
	padding: 24px 24px 20px;
	box-shadow: 0 1px 3px rgba(26,26,46,.04);
}

.cpd-stat-num {
	font-family: 'DM Mono', monospace;
	font-size: 30px;
	color: #6366F1;
	font-weight: 400;
	margin: 0 0 6px;
	line-height: 1;
}

.cpd-stat-label {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #9CA3AF;
	margin: 0;
	font-weight: 500;
}

/* ── Section header ───────────────────────────────────── */
.cpd-section-head {
	font-family: 'DM Sans', sans-serif;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.1em;
	text-transform: uppercase;
	color: #9CA3AF;
	margin: 0 0 16px;
}

/* ── Table ────────────────────────────────────────────── */
.cpd-table-wrap {
	background: #fff;
	border-radius: 14px;
	border: 1px solid #EEECEA;
	overflow: hidden;
	margin-bottom: 12px;
	box-shadow: 0 1px 3px rgba(26,26,46,.04);
}

.cpd-table {
	width: 100%;
	border-collapse: collapse;
}

.cpd-table th {
	font-family: 'DM Sans', sans-serif;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: #9CA3AF;
	padding: 14px 20px;
	text-align: left;
	border-bottom: 1px solid #F3F4F6;
}

.cpd-table td {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #374151;
	padding: 14px 20px;
	border-bottom: 1px solid #F9FAFB;
	vertical-align: middle;
}

.cpd-table tr:last-child td { border-bottom: none; }

.cpd-table td.mono {
	font-family: 'DM Mono', monospace;
	font-size: 13px;
}

.cpd-table td.right { text-align: right; }

.cpd-proposal-title {
	font-weight: 600;
	color: #1A1A2E;
}

/* ── View all link ────────────────────────────────────── */
.cpd-view-all {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 600;
	color: #6366F1;
	text-decoration: none;
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-bottom: 40px;
}
.cpd-view-all:hover { text-decoration: underline; }

/* ── Empty state ──────────────────────────────────────── */
.cpd-empty {
	background: #fff;
	border-radius: 14px;
	border: 1px solid #EEECEA;
	padding: 48px 32px;
	text-align: center;
	margin-bottom: 12px;
}

.cpd-empty-icon {
	width: 48px;
	height: 48px;
	background: #F3F4F6;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto 16px;
}

.cpd-empty-msg {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #9CA3AF;
	margin: 0;
	line-height: 1.6;
}

/* ── Skeleton ─────────────────────────────────────────── */
.cpd-skel {
	background: linear-gradient(90deg, #F3F4F6 25%, #E9EAEC 50%, #F3F4F6 75%);
	background-size: 200% 100%;
	animation: cpd-pulse 1.4s ease infinite;
	border-radius: 6px;
}
@keyframes cpd-pulse {
	0%   { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

/* ── Stat card variants ───────────────────────────────── */
.cpd-stat-card--due {
	background: #FFFBEB;
	border-color: #FDE68A;
}
.cpd-stat-card--due .cpd-stat-label { color: #92400E; }
.cpd-stat-card--due .cpd-stat-num   { color: #78350F; }

.cpd-stat-card--complete .cpd-stat-label { color: #065F46; }
.cpd-stat-card--complete .cpd-stat-num   { color: #059669; }

/* ── Responsive ───────────────────────────────────────── */
@media (max-width: 1200px) {
	.cpd-stats { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 950px) {
	.cpd-stats { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
	.cpd-stats { grid-template-columns: 1fr; }
	.cpd-greeting { font-size: 24px; }
	.cpd-table th, .cpd-table td { padding: 12px 14px; }
}
` );

export default function PortalDashboard() {
	const [ loading,   setLoading   ] = useState( true );
	const [ client,    setClient    ] = useState( null );
	const [ proposals, setProposals ] = useState( [] );
	const [ payments,  setPayments  ] = useState( [] );
	const [ projects,  setProjects  ] = useState( [] );

	useEffect( () => {
		Promise.all( [
			apiFetch( '/portal/me' ),
			apiFetch( '/portal/proposals' ),
			apiFetch( '/portal/payments' ),
			apiFetch( '/portal/projects' ).catch( () => [] ),
		] ).then( ( [ me, props, pays, projs ] ) => {
			setClient( me );
			setProposals( Array.isArray( props  ) ? props  : [] );
			setPayments(  Array.isArray( pays   ) ? pays   : [] );
			setProjects(  Array.isArray( projs?.projects ) ? projs.projects : [] );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	// ── Stats ──────────────────────────────────────────────────────────────────
	const activeStatuses = [ 'sent', 'viewed', 'accepted' ];
	const activeCount    = proposals.filter( p => activeStatuses.includes( p.status ) ).length;
	const inProgress     = proposals.filter( p => p.status === 'accepted' ).length;
	const totalPaid      = payments
		.filter( p => p.status === 'completed' )
		.reduce( ( sum, p ) => sum + parseFloat( p.amount || 0 ), 0 );
	const currency       = payments[0]?.currency || projects[0]?.currency || 'GBP';

	const completedCount = projects.filter( p => p.status === 'completed' ).length;
	const amountDue      = projects
		.filter( p => p.status === 'completed' && parseFloat( p.remaining_balance ) > 0 )
		.reduce( ( sum, p ) => sum + parseFloat( p.remaining_balance || 0 ), 0 );

	const firstName = ( client?.name || '' ).split( ' ' )[0] || 'there';

	const recentProposals = proposals.slice( 0, 5 );
	const recentPayments  = payments.slice( 0, 5 );

	function formatDate( dateStr ) {
		if ( ! dateStr ) return '—';
		return new Intl.DateTimeFormat( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' } )
			.format( new Date( dateStr ) );
	}

	// ── Skeleton ───────────────────────────────────────────────────────────────
	if ( loading ) {
		return (
			<div>
				<div className="cpd-header">
					<div className="cpd-skel" style={{ height: 36, width: 260, marginBottom: 10 }} />
					<div className="cpd-skel" style={{ height: 18, width: 320 }} />
				</div>
				<div className="cpd-stats">
					{ [1,2,3,4,5].map( i => (
						<div key={ i } className="cpd-stat-card">
							<div className="cpd-skel" style={{ height: 30, width: 80, marginBottom: 8 }} />
							<div className="cpd-skel" style={{ height: 14, width: 120 }} />
						</div>
					) ) }
				</div>
				<div className="cpd-skel" style={{ height: 160, borderRadius: 14 }} />
			</div>
		);
	}

	return (
		<div>
			{ /* ── Header ── */ }
			<div className="cpd-header">
				<h1 className="cpd-greeting">Welcome back, { firstName }!</h1>
				<p className="cpd-subtitle">Here&rsquo;s what&rsquo;s happening with your projects.</p>
			</div>

			{ /* ── Stats ── */ }
			<div className="cpd-stats">
				<div className="cpd-stat-card">
					<p className="cpd-stat-num">{ activeCount }</p>
					<p className="cpd-stat-label">Active Proposals</p>
				</div>
				<div className="cpd-stat-card">
					<p className="cpd-stat-num">{ inProgress }</p>
					<p className="cpd-stat-label">In Progress</p>
				</div>
				<div className={ `cpd-stat-card${ completedCount > 0 ? ' cpd-stat-card--complete' : '' }` }>
					<p className="cpd-stat-num">{ completedCount }</p>
					<p className="cpd-stat-label">Completed</p>
				</div>
				<div className="cpd-stat-card">
					<p className="cpd-stat-num">{ fmt( totalPaid, currency ) }</p>
					<p className="cpd-stat-label">Total Paid</p>
				</div>
				<div className={ `cpd-stat-card${ amountDue > 0 ? ' cpd-stat-card--due' : '' }` }>
					<p className="cpd-stat-num">
						{ amountDue > 0 ? fmt( amountDue, currency ) : '—' }
					</p>
					<p className="cpd-stat-label">Amount Due</p>
				</div>
			</div>

			{ /* ── Recent Proposals ── */ }
			<p className="cpd-section-head">Recent Proposals</p>

			{ recentProposals.length === 0 ? (
				<div className="cpd-empty">
					<div className="cpd-empty-icon">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none"
							stroke="#9CA3AF" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
							<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
							<polyline points="22,6 12,13 2,6"/>
						</svg>
					</div>
					<p className="cpd-empty-msg">
						No proposals yet. You&rsquo;ll be notified when your first proposal is ready.
					</p>
				</div>
			) : (
				<div className="cpd-table-wrap">
					<table className="cpd-table">
						<thead>
							<tr>
								<th>Proposal</th>
								<th>Status</th>
								<th>Amount</th>
								<th>Date</th>
							</tr>
						</thead>
						<tbody>
							{ recentProposals.map( p => (
								<tr key={ p.id }>
									<td>
										<a href={ `/proposals/${ p.token }` }
											className="cpd-proposal-title"
											style={{ textDecoration: 'none', color: '#1A1A2E' }}>
											{ p.title || 'Untitled Proposal' }
										</a>
									</td>
									<td><StatusBadge status={ p.status } /></td>
									<td className="mono">
										{ p.total_amount ? fmt( p.total_amount, p.currency || 'GBP' ) : '—' }
									</td>
									<td style={{ color: '#9CA3AF', fontSize: 13 }}>
										{ formatDate( p.created_at ) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ proposals.length > 5 && (
				<a className="cpd-view-all" href="/clientoctopus/proposals">
					View all proposals
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
						<line x1="5" y1="12" x2="19" y2="12"/>
						<polyline points="12 5 19 12 12 19"/>
					</svg>
				</a>
			) }

			{ /* ── Recent Payments ── */ }
			{ recentPayments.length > 0 && (
				<>
					<p className="cpd-section-head">Recent Payments</p>
					<div className="cpd-table-wrap">
						<table className="cpd-table">
							<thead>
								<tr>
									<th>Proposal</th>
									<th>Amount</th>
									<th>Date</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
								{ recentPayments.map( pm => (
									<tr key={ pm.id }>
										<td>
											<a href={ `/proposals/${ pm.proposal_token }` }
												style={{ textDecoration: 'none', color: '#1A1A2E', fontWeight: 600 }}>
												{ pm.proposal_title || 'Untitled' }
											</a>
										</td>
										<td className="mono">
											{ fmt( pm.amount, pm.currency || 'GBP' ) }
										</td>
										<td style={{ color: '#9CA3AF', fontSize: 13 }}>
											{ formatDate( pm.created_at ) }
										</td>
										<td><StatusBadge status={ pm.status } /></td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					{ payments.length > 5 && (
						<a className="cpd-view-all" href="/clientoctopus/payments">
							View payment history
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none"
								stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
								<line x1="5" y1="12" x2="19" y2="12"/>
								<polyline points="12 5 19 12 12 19"/>
							</svg>
						</a>
					) }
				</>
			) }
		</div>
	);
}
