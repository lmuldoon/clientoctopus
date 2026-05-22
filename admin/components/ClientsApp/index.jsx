import { useState, useEffect, useMemo } from '@wordpress/element';

// ── Fetch helper ──────────────────────────────────────────────────────────────

async function coFetch( path, options = {} ) {
	const { apiUrl, nonce } = window.clientoctopusData || {};
	const url = ( apiUrl || '/wp-json/clientoctopus/v1/' ) + path;
	const res = await fetch( url, {
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce || '',
			...( options.headers || {} ),
		},
		...options,
	} );
	if ( ! res.ok ) {
		const err = await res.json().catch( () => ( {} ) );
		const e = new Error( err.message || `HTTP ${ res.status }` );
		e.data = err;
		throw e;
	}
	return res.json();
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id; s.textContent = css;
	document.head.appendChild( s );
}

function formatDate( iso ) {
	if ( ! iso ) return '';
	return new Date( iso ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' } );
}

function getInitials( name ) {
	if ( ! name ) return '?';
	return name.trim().split( /\s+/ ).slice( 0, 2 ).map( w => w[0] ).join( '' ).toUpperCase();
}

// Stable avatar colour from name
const AVATAR_PALETTES = [
	[ '#EDE9FE', '#7C3AED' ], [ '#DCFCE7', '#166534' ], [ '#FEF3C7', '#92400E' ],
	[ '#FCE7F3', '#9D174D' ], [ '#DBEAFE', '#1E40AF' ], [ '#FFE4E6', '#9F1239' ],
];
function avatarColour( name ) {
	let h = 0;
	for ( let i = 0; i < ( name || '' ).length; i++ ) h = ( h * 31 + name.charCodeAt( i ) ) >>> 0;
	return AVATAR_PALETTES[ h % AVATAR_PALETTES.length ];
}

// ── CSS ───────────────────────────────────────────────────────────────────────

const CSS = `

.co-cl {
  font-family: 'Archivo', -apple-system, sans-serif;
  padding: 32px 28px 64px;
  animation: co-cl-enter .2s ease both;
  --cl-indigo: #6366F1;
  --cl-navy:   #1A1A2E;
  --cl-slate4: #94A3B8;
  --cl-slate3: #CBD5E1;
  --cl-slate2: #E2E8F0;
  --cl-slate1: #F8FAFC;
  --cl-green:  #10B981;
  --cl-amber:  #F59E0B;
  --cl-red:    #EF4444;
}
@keyframes co-cl-enter {
  from { opacity:0; transform:translateY(8px); }
  to   { opacity:1; transform:translateY(0); }
}

/* ─── Header ── */
.co-cl-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 28px;
}
.co-cl-title-group { display:flex; align-items:center; gap:14px; }
.co-cl-title {
  font-size: 28px;
  font-weight: 800;
  color: var(--cl-navy);
  letter-spacing: -.5px;
  margin: 0;
  line-height: 1;
}
.co-cl-count {
  font-size: 12px;
  font-weight: 700;
  color: var(--cl-indigo);
  background: #EEF2FF;
  border-radius: 20px;
  padding: 3px 11px;
  letter-spacing: .03em;
}
.co-cl-subtitle {
  font-size: 14px;
  color: var(--cl-slate4);
  margin: 6px 0 0;
}

/* ─── Search ── */
.co-cl-search-wrap {
  position: relative;
  width: 280px;
}
.co-cl-search-icon {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--cl-slate4);
  pointer-events: none;
  display:none;
}
.co-cl-search {
  width: 100%;
  box-sizing: border-box;
  padding: 9px 12px 9px 36px;
  border: 1.5px solid var(--cl-slate2);
  border-radius: 10px;
  font-family: 'Archivo', sans-serif;
  font-size: 13.5px;
  color: var(--cl-navy);
  background: #fff;
  transition: border-color .15s, box-shadow .15s;
  outline: none;
}
.co-cl-search:focus {
  border-color: var(--cl-indigo);
  box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}

/* ─── Card / Table ── */
.co-cl-card {
  background: #fff;
  border-radius: 16px;
  border: 1px solid var(--cl-slate2);
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(26,26,46,.04), 0 6px 24px rgba(26,26,46,.06);
}
.co-cl-table { width:100%; border-collapse:collapse; }
.co-cl-th {
  padding: 12px 20px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--cl-slate4);
  text-align: left;
  border-bottom: 1px solid var(--cl-slate2);
  background: var(--cl-slate1);
}
.co-cl-tr {
  border-bottom: 1px solid var(--cl-slate2);
  transition: background .1s;
}
.co-cl-tr:last-child { border-bottom: none; }
.co-cl-tr:hover { background: #FAFBFF; }
.co-cl-tr.has-pending { border-left: 3px solid var(--cl-amber); }
.co-cl-tr.has-pending:not(:hover) { background: #FFFDF7; }
.co-cl-td { padding: 16px 20px; vertical-align: middle; }

/* ─── Avatar ── */
.co-cl-avatar {
  width: 36px; height: 36px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700;
  flex-shrink: 0;
}

/* ─── Client cell ── */
.co-cl-client-cell { display:flex; align-items:center; gap:12px; }
.co-cl-client-info { min-width: 0; }
.co-cl-client-name {
  font-size: 14px;
  font-weight: 600;
  color: var(--cl-navy);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.co-cl-client-email {
  font-size: 12.5px;
  color: var(--cl-slate4);
  margin-top: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ─── Meta text ── */
.co-cl-meta { font-size: 13.5px; color: #475569; }
.co-cl-meta-dim { font-size: 12px; color: var(--cl-slate4); margin-top:3px; }

/* ─── Status badge ── */
.co-cl-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 20px;
  padding: 4px 10px;
  white-space: nowrap;
}
.co-cl-status.invited {
  background: #DCFCE7;
  color: #166534;
}
.co-cl-status.pending {
  background: #FEF3C7;
  color: #92400E;
}
.co-cl-status.no-email {
  background: var(--cl-slate2);
  color: var(--cl-slate4);
}
.co-cl-pulse {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--cl-amber);
  animation: co-cl-pulse 1.6s ease infinite;
  flex-shrink: 0;
}
@keyframes co-cl-pulse {
  0%,100% { opacity:1; transform:scale(1); }
  50%      { opacity:.5; transform:scale(.75); }
}
.co-cl-dot-green {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--cl-green);
  flex-shrink: 0;
}

/* ─── Invite button ── */
.co-cl-invite-btn {
  font-family: 'Archivo', sans-serif;
  font-size: 12.5px;
  font-weight: 600;
  padding: 7px 16px;
  border-radius: 8px;
  border: 1.5px solid var(--cl-indigo);
  background: transparent;
  color: var(--cl-indigo);
  cursor: pointer;
  white-space: nowrap;
  transition: background .13s, color .13s, opacity .13s;
  letter-spacing: .01em;
}
.co-cl-invite-btn:hover:not(:disabled) {
  background: var(--cl-indigo);
  color: #fff;
}
.co-cl-invite-btn:disabled {
  opacity: .45;
  cursor: not-allowed;
  border-color: var(--cl-slate3);
  color: var(--cl-slate4);
}
.co-cl-invite-btn.sent {
  border-color: var(--cl-green);
  color: var(--cl-green);
  background: #F0FDF4;
  cursor: default;
}
.co-cl-invite-btn.resend {
  border-color: var(--cl-slate3);
  color: var(--cl-slate4);
}
.co-cl-invite-btn.resend:hover:not(:disabled) {
  border-color: var(--cl-indigo);
  background: var(--cl-indigo);
  color: #fff;
}

/* ─── Upgrade tooltip wrapper ── */
.co-cl-tip-wrap {
  position: relative;
  display: inline-block;
}
.co-cl-tip-wrap:hover .co-cl-tip {
  opacity: 1;
  pointer-events: auto;
  transform: translateY(0);
}
.co-cl-tip {
  position: absolute;
  bottom: calc(100% + 8px);
  right: 0;
  background: var(--cl-navy);
  color: #fff;
  font-size: 11.5px;
  line-height: 1.4;
  padding: 7px 11px;
  border-radius: 8px;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transform: translateY(4px);
  transition: opacity .15s, transform .15s;
  z-index: 10;
}
.co-cl-tip::after {
  content: '';
  position: absolute;
  top: 100%; right: 14px;
  border: 5px solid transparent;
  border-top-color: var(--cl-navy);
}

/* ─── Empty / loading states ── */
.co-cl-empty {
  padding: 64px 32px;
  text-align: center;
  color: var(--cl-slate4);
}
.co-cl-empty-icon { margin: 0 auto 16px; display:block; opacity:.4; }
.co-cl-empty-title { font-size:16px; font-weight:700; color:#64748B; margin:0 0 6px; }
.co-cl-empty-sub   { font-size:13.5px; margin:0; }

/* ─── Skeleton ── */
.co-cl-skel {
  display: block;
  border-radius: 6px;
  background: linear-gradient(90deg, #F1F5F9 25%, #E2E8F0 50%, #F1F5F9 75%);
  background-size: 200% 100%;
  animation: co-cl-shimmer 1.4s infinite;
}
@keyframes co-cl-shimmer {
  from { background-position: 200% 0; }
  to   { background-position: -200% 0; }
}
`;

// ── Sub-components ────────────────────────────────────────────────────────────

function SkeletonRow() {
	return (
		<tr className="co-cl-tr">
			<td className="co-cl-td">
				<div className="co-cl-client-cell">
					<span className="co-cl-skel" style={ { width:36, height:36, borderRadius:10, flexShrink:0 } }/>
					<div>
						<span className="co-cl-skel" style={ { display:'block', width:120, height:13, marginBottom:6 } }/>
						<span className="co-cl-skel" style={ { display:'block', width:160, height:11 } }/>
					</div>
				</div>
			</td>
			<td className="co-cl-td"><span className="co-cl-skel" style={ { display:'block', width:90, height:13 } }/></td>
			<td className="co-cl-td"><span className="co-cl-skel" style={ { display:'block', width:130, height:13 } }/></td>
			<td className="co-cl-td"><span className="co-cl-skel" style={ { display:'block', width:80, height:24, borderRadius:20 } }/></td>
			<td className="co-cl-td"><span className="co-cl-skel" style={ { display:'block', width:90, height:32, borderRadius:8 } }/></td>
		</tr>
	);
}

function StatusBadge( { client } ) {
	if ( ! client.email ) {
		return <span className="co-cl-status no-email">No email</span>;
	}
	if ( client.portal_invited_at ) {
		return (
			<span className="co-cl-status invited">
				<span className="co-cl-dot-green"/>
				Invited · { formatDate( client.portal_invited_at ) }
			</span>
		);
	}
	return (
		<span className="co-cl-status pending">
			<span className="co-cl-pulse"/>
			Not Invited
		</span>
	);
}

function InviteButton( { client, onInvite, sending, justSent } ) {
	if ( window.clientoctopusData?.featureAccess?.use_portal === false ) return null;

	const noEmail  = ! client.email;
	const disabled = noEmail || sending;
	const isResend = !! client.portal_invited_at && ! justSent;

	let label = 'Send Invite';
	let btnClass = 'co-cl-invite-btn';

	if ( justSent )      { label = '✓ Sent';  btnClass += ' sent'; }
	else if ( isResend ) { label = 'Re-send'; btnClass += ' resend'; }
	if ( sending )       { label = '…'; }

	return (
		<button
			className={ btnClass }
			disabled={ disabled }
			onClick={ justSent ? undefined : onInvite }
		>
			{ label }
		</button>
	);
}

// ── Main component ────────────────────────────────────────────────────────────

export default function ClientsApp() {
	injectStyles( 'co-clients-styles', CSS );

	const [ clients,   setClients   ] = useState( [] );
	const [ loading,   setLoading   ] = useState( true );
	const [ search,    setSearch    ] = useState( '' );
	const [ sending,   setSending   ] = useState( null );  // client id in-flight
	const [ justSent,  setJustSent  ] = useState( null );  // client id just invited

	useEffect( () => {
		coFetch( 'clients' )
			.then( data => setClients( data.clients || [] ) )
			.catch( () => {} )
			.finally( () => setLoading( false ) );
	}, [] );

	const filtered = useMemo( () => {
		const q = search.toLowerCase().trim();
		if ( ! q ) return clients;
		return clients.filter( c =>
			( c.name    || '' ).toLowerCase().includes( q ) ||
			( c.email   || '' ).toLowerCase().includes( q ) ||
			( c.company || '' ).toLowerCase().includes( q )
		);
	}, [ clients, search ] );

	async function handleInvite( client ) {
		if ( sending ) return;
		setSending( client.id );
		try {
			const data = await coFetch( `clients/${ client.id }/invite`, { method: 'POST' } );
			if ( data.client ) {
				setClients( prev => prev.map( c => c.id === client.id ? data.client : c ) );
			}
			setJustSent( client.id );
			setTimeout( () => setJustSent( null ), 3000 );
		} catch ( err ) {
			const msg = err?.data?.message || err.message;
			if ( msg ) window.alert( msg );
		} finally {
			setSending( null );
		}
	}

	return (
		<div className="co-cl">
			{ /* Header */ }
			<div className="co-cl-header">
				<div>
					<div className="co-cl-title-group">
						<h1 className="co-cl-title">Clients</h1>
						{ ! loading && <span className="co-cl-count">{ clients.length }</span> }
					</div>
					<p className="co-cl-subtitle">Manage client portal access and invitations</p>
				</div>

				<div className="co-cl-search-wrap">
					<svg className="co-cl-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
					</svg>
					<input
						className="co-cl-search"
						type="text"
						placeholder="Search clients…"
						value={ search }
						onChange={ e => setSearch( e.target.value ) }
					/>
				</div>
			</div>

			{ /* Table card */ }
			<div className="co-cl-card">
				<table className="co-cl-table">
					<thead>
						<tr>
							<th className="co-cl-th">Client</th>
							<th className="co-cl-th">Company</th>
							<th className="co-cl-th">Latest Proposal</th>
							<th className="co-cl-th">Portal Status</th>
							<th className="co-cl-th"></th>
						</tr>
					</thead>
					<tbody>
						{ loading ? (
							<>
								<SkeletonRow/>
								<SkeletonRow/>
								<SkeletonRow/>
							</>
						) : filtered.length === 0 ? (
							<tr>
								<td colSpan="5">
									<div className="co-cl-empty">
										<svg className="co-cl-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
											<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
											<circle cx="9" cy="7" r="4"/>
											<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
										</svg>
										{ search ? (
											<>
												<p className="co-cl-empty-title">No clients match "{ search }"</p>
												<p className="co-cl-empty-sub">Try a different name, email or company.</p>
											</>
										) : (
											<>
												<p className="co-cl-empty-title">No clients yet</p>
												<p className="co-cl-empty-sub">Send your first proposal to get started.</p>
											</>
										) }
									</div>
								</td>
							</tr>
						) : (
							filtered.map( client => {
								const [ bg, fg ] = avatarColour( client.name );
								const isPending  = client.email && ! client.portal_invited_at;
								return (
									<tr
										key={ client.id }
										className={ `co-cl-tr${ isPending && client.latest_proposal_date ? ' has-pending' : '' }` }
									>
										{ /* Client name + email */ }
										<td className="co-cl-td">
											<div className="co-cl-client-cell">
												<div className="co-cl-avatar" style={ { background: bg, color: fg } }>
													{ getInitials( client.name ) }
												</div>
												<div className="co-cl-client-info">
													<div className="co-cl-client-name">{ client.name || '—' }</div>
													<div className="co-cl-client-email">{ client.email || 'No email' }</div>
												</div>
											</div>
										</td>

										{ /* Company */ }
										<td className="co-cl-td">
											<div className="co-cl-meta">{ client.company || <span style={ { color:'#CBD5E1' } }>—</span> }</div>
										</td>

										{ /* Latest accepted proposal */ }
										<td className="co-cl-td">
											{ client.latest_proposal_title ? (
												<>
													<div className="co-cl-meta">{ client.latest_proposal_title }</div>
													<div className="co-cl-meta-dim">{ client.latest_proposal_status === 'completed' ? 'Completed' : 'Accepted' } { formatDate( client.latest_proposal_date ) }</div>
												</>
											) : (
												<span style={ { color:'#CBD5E1', fontSize:13 } }>No accepted proposals</span>
											) }
										</td>

										{ /* Status badge */ }
										<td className="co-cl-td">
											<StatusBadge client={ client }/>
										</td>

										{ /* Action */ }
										<td className="co-cl-td" style={ { textAlign:'right' } }>
											<InviteButton
												client={ client }
												onInvite={ () => handleInvite( client ) }
												sending={ sending === client.id }
												justSent={ justSent === client.id }
											/>
										</td>
									</tr>
								);
							} )
						) }
					</tbody>
				</table>
			</div>
		</div>
	);
}
